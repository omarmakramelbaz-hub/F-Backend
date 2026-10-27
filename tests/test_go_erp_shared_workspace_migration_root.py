"""Real root-private receipts with injected PHP process; no SQL/server execution."""
import copy
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value); return value
io = module('migration_source_io', 'deployment/go_erp_shared_workspace_source_root.py')
states = module('migration_states', 'deployment/go_erp_shared_workspace_states.py')
subject = module('migration_actuator', 'deployment/go_erp_shared_workspace_migration_root.py')
fixture = module('migration_fixtures', 'tests/test_go_erp_shared_workspace_states.py')


class Crash(BaseException):
    pass


class FakePHP:
    def __init__(self, intent):
        self.intent = intent; self.calls = []; self.creates = 0
        self.crash_before_create = None; self.crash_after_create = None
        self.value = {'database_id_sha256': intent['database_id_sha256'],
            'tables': {name: None for name in states.TABLES + states.PRIVATE_TABLES},
            'ledger': {name: None for name in states.MIGRATIONS}, 'workspace_enabled': False,
            'local': False, 'financial_rows_changed': False, 'dropped_tables': []}

    def php(self, program, specification, **options):
        if options != {'cwd': subject.ROOT, 'uid': 1001, 'gid': 1003, 'timeout': 180, 'maximum': subject.LIMIT}:
            raise AssertionError('Unreviewed PHP invocation')
        if subject.GATE_ANCHOR in program:
            raise AssertionError('Uncompiled helper executed')
        self.calls.append(copy.deepcopy(specification))
        mode, path, table = (specification[name] for name in ('mode', 'migration_path', 'table'))
        if mode == 'create-table':
            self.creates += 1
            if self.crash_before_create == self.creates:
                raise Crash()
            self.value['tables'][table] = copy.deepcopy(self.intent['expected_schemas'][table]['schema'])
            if self.crash_after_create == self.creates:
                raise Crash()
        elif mode == 'record-ledger':
            self.value['ledger'][path] = {'id': len([value for value in self.value['ledger'].values() if value])+1,
                'migration': Path(path).stem, 'batch': 8}
        elif mode != 'observe':
            raise AssertionError('Unreviewed process mode')
        return {'returncode': 0, 'stdout': io.canonical(self.value), 'stderr': b''}


@unittest.skipUnless(os.geteuid() == 0, 'Private deployment records require a disposable root runtime')
class MigrationActuatorTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='workspace-sql-receipts-'); self.addCleanup(self.temp.cleanup)
        self.audit = Path(self.temp.name)
        self.intent, *_ = fixture.migrations()
        self.php = FakePHP(self.intent)
        self.template = (ROOT / 'deployment/go_erp_shared_workspace_migration.php').read_bytes()
        self.instance = subject.MigrationActuator(io, states, self.php, self.audit, self.intent, self.template,
            lambda: None, lambda: None)
        gate_sha = io.sha(io.canonical(self.instance.gate))
        self.pins = {'template_sha256': io.sha(self.template),
            'program_sha256': io.sha(subject.materialize_program(io, self.template, self.instance.gate)),
            'intent_sha256': states.digest(self.intent), 'gate_sha256': gate_sha, 'resume_helper_sha256': 'f' * 64}
        self.instance.pins = self.pins

    def parameters(self):
        _, snapshot = self.instance.observe(); checked = self.instance._checkpoint(snapshot)
        path = next(name for name in states.MIGRATIONS if name not in checked['completed_migrations'])
        remaining = [table for table in states.MIGRATIONS[path] if table in checked['missing_tables']]
        owned = [table for table in states.MIGRATIONS[path] if table in checked['owned_tables']]
        return {'migration_path': path, 'migration_sha256': self.intent['migration_hashes'][path],
            'intent_sha256': states.digest(self.intent), 'approved_resume_helper_sha256': 'f' * 64,
            'expected_checkpoint_sha256': states.digest(checked), 'remaining_tables': remaining, 'owned_tables': owned,
            'table_intents': {table: states.table_intent(self.intent, table) for table in remaining},
            'mode': 'owned-resume' if owned or not remaining else 'fresh-reviewed-path',
            'cwd': subject.ROOT, 'php': subject.PHP, 'run_as_uid': 1001, 'run_as_gid': 1003,
            'private_output_limit': subject.LIMIT, 'flag_must_stay_false': True,
            'unrestricted_migrate_allowed': False, 'down_allowed': False, 'drop_allowed': False,
            'financial_row_updates_allowed': False}

    def test_only_four_additive_creates_and_two_ledger_inserts_with_private_owned_receipts(self):
        self.instance.apply(self.parameters()); self.instance.apply(self.parameters())
        _, snapshot = self.instance.observe()
        self.assertEqual(self.instance._checkpoint(snapshot)['state'], 'owned-complete')
        self.assertEqual([call['table'] for call in self.php.calls if call['mode'] == 'create-table'], list(states.TABLES))
        self.assertEqual([call['migration_path'] for call in self.php.calls if call['mode'] == 'record-ledger'], list(states.MIGRATIONS))
        for table in states.TABLES:
            self.assertTrue((self.instance.audit / ('table-intent-' + table + '.json')).exists())
            self.assertTrue((self.instance.audit / ('table-created-' + table + '.json')).exists())
        self.assertTrue(all(path.stat().st_mode & 0o777 == 0o600 for path in self.instance.audit.iterdir()))

    def test_owned_partial_resumes_only_remaining_tables_without_repeating_first_create(self):
        parameters = self.parameters(); self.php.crash_before_create = 2
        with self.assertRaises(Crash): self.instance.apply(parameters)
        recovered = self.instance.recover(parameters)
        self.assertEqual(recovered['status'], 'owned-partial')
        next_parameters = self.parameters(); self.assertEqual(next_parameters['mode'], 'owned-resume')
        self.assertEqual(next_parameters['remaining_tables'], list(states.TABLES[1:3]))
        self.php.crash_before_create = None; self.instance.apply(next_parameters)
        first = [call for call in self.php.calls if call['mode'] == 'create-table' and call['table'] == states.TABLES[0]]
        self.assertEqual(len(first), 1)

    def test_create_without_durable_completion_receipt_is_never_adopted_or_dropped(self):
        parameters = self.parameters(); self.php.crash_after_create = 1
        with self.assertRaises(Crash): self.instance.apply(parameters)
        with self.assertRaisesRegex(subject.Stop, 'UNRECEIPTED_DDL_REQUIRES_REVIEW'):
            self.instance.recover(parameters)
        self.assertIsNotNone(self.php.value['tables'][states.TABLES[0]])
        self.assertEqual(self.php.creates, 1)

    def test_matching_foreign_table_is_preserved_and_cannot_reach_create(self):
        self.php.value['tables'][states.TABLES[0]] = copy.deepcopy(self.intent['expected_schemas'][states.TABLES[0]]['schema'])
        with self.assertRaisesRegex(subject.Stop, 'CHECKPOINT_CHANGED_OR_FOREIGN'):
            self.instance.apply(self.parameters())
        self.assertEqual(self.php.creates, 0)
        self.assertIsNotNone(self.php.value['tables'][states.TABLES[0]])

    def test_wrong_uid_unknown_path_drop_or_generic_migrate_are_rejected_before_process(self):
        parameters = self.parameters()
        for name, value in (('run_as_uid', 0), ('migration_path', 'database/migrations/other.php'),
                ('drop_allowed', True), ('unrestricted_migrate_allowed', True)):
            before = len(self.php.calls)
            with self.subTest(name=name), self.assertRaises(subject.Stop):
                self.instance.apply({**parameters, name: value})
            self.assertEqual(len(self.php.calls), before)

    def test_null_pins_block_before_audit_or_php(self):
        self.instance.pins = None
        with self.assertRaisesRegex(subject.Stop, 'FINAL_PINS_UNAVAILABLE'):
            self.instance.observe()
        self.assertFalse(self.instance.audit.exists()); self.assertEqual(self.php.calls, [])


if __name__ == '__main__': unittest.main()
