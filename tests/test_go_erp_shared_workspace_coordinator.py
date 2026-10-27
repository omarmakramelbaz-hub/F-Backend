"""Pinned-actuator fixture orchestration only; no server, filesystem or SQL effects."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = module('workspace_coordinator', 'deployment/go_erp_shared_workspace_coordinator.py')
states = module('workspace_states_for_coordinator', 'deployment/go_erp_shared_workspace_states.py')


class Crash(BaseException):
    pass


def schema():
    return {'engine': 'InnoDB', 'collation': 'utf8mb4_unicode_ci', 'columns': [
        {'name': 'id', 'type': 'char(36)', 'nullable': False, 'default': None, 'extra': '',
            'collation': 'utf8mb4_unicode_ci', 'generation_expression': ''}],
        'indexes': [{'name': 'PRIMARY', 'unique': True, 'columns': ['id'], 'type': 'BTREE',
            'prefixes': [None], 'orders': ['ASC']}]}


def migration_fixture():
    intent = states.migration_intent('b' * 40, '1' * 64, '2' * 64,
        {name: str(index + 3) * 64 for index, name in enumerate(states.MIGRATIONS)},
        {name: schema() for name in states.TABLES})
    snapshot = {'tables': {name: None for name in states.TABLES},
        'journal': {'intent_sha256': states.digest(intent), 'table_intents': {},
            'table_receipts': {}, 'ledger_receipts': {}},
        'ledger': {name: None for name in states.MIGRATIONS}, 'persisted_intent_sha256': states.digest(intent)}
    return intent, snapshot


def own_tables(intent, snapshot, migration, tables, *, ledger=True):
    for table in tables:
        snapshot['journal']['table_intents'][table] = states.table_intent(intent, table)
        snapshot['tables'][table] = schema()
        snapshot['journal']['table_receipts'][table] = states.table_receipt(intent, table, schema())
    if ledger and all(snapshot['tables'][table] is not None for table in states.MIGRATIONS[migration]):
        row = states.ledger_receipt(intent, migration)
        snapshot['ledger'][migration] = row
        snapshot['journal']['ledger_receipts'][migration] = copy.deepcopy(row)


def checkpoint(intent, snapshot):
    return states.migration_checkpoint(intent, snapshot['tables'], snapshot['journal'], snapshot['ledger'],
        persisted_intent_sha256=snapshot['persisted_intent_sha256'])


def fixtures():
    intent, snapshot = migration_fixture()
    complete = copy.deepcopy(snapshot)
    for name, tables in states.MIGRATIONS.items():
        own_tables(intent, complete, name, tables)
    binding = {'candidate_revision': 'a' * 40, 'web_release': 'b' * 40,
        'native_core': 'c' * 40, 'seed': 'd' * 40, 'policy': subject.POLICY,
        'source_manifest_sha256': '1' * 64, 'migration_receipt_sha256': states.digest(checkpoint(intent, complete)),
        'contract_sha256': '5' * 64, 'env_before_sha256': '6' * 64,
        'env_enabled_sha256': '7' * 64, 'env_unchanged_bytes_sha256': '8' * 64,
        'php_handler_configuration_sha256': '9' * 64, 'route_cache_required': True}
    predecessor = {'counter': 59, 'candidate': 'e' * 40, 'version': '0.4.4-preview.59',
        'inventory_sha256': 'a' * 64, 'acceptance_receipt_sha256': 'b' * 64,
        'installer_sha256': 'c' * 64, 'snapshot_sha256': 'd' * 64, 'remote_lease': 'e' * 40}
    programs = {name: str(index + 1) * 64 for index, name in enumerate(sorted(subject.PROGRAMS))}
    plan = subject.make_plan(states, binding, predecessor, 'e' * 64, intent, programs,
        replacement_order_sha256='a' * 64)
    pins = {'gate': {name: binding[name] for name in states.GATE_FIELDS},
        'actuators': {name: 'f' * 64 for name in subject.ACTIONS},
        'program_hashes': programs, 'workflow_runs': [{'workflow': 'full-candidate.yml',
            'path': '.github/workflows/full-candidate.yml', 'workflow_sha256': 'a' * 64,
            'repository_id': 1311412084, 'repository': 'omarmakramelbaz-hub/F-Backend', 'event': 'push',
            'branch': 'codex/go-erp-live-source-20261021', 'head_sha': binding['candidate_revision'],
            'run_id': 123, 'attempt': 1, 'latest_run_and_attempt': True, 'status': 'completed', 'conclusion': 'success',
            'jobs': {'full-native': {'id': 456, 'run_id': 123, 'attempt': 1,
                'head_sha': binding['candidate_revision'], 'status': 'completed', 'conclusion': 'success'}}}],
        'source_plan_sha256': plan['source_plan_sha256'], 'migration_intent_sha256': states.digest(intent),
        'replacement_order_sha256': plan['replacement_order_sha256'],
        'migration_resume_helper_sha256': 'b' * 64, 'predecessor': predecessor, 'version': '0.4.4-preview.60'}
    template = pins['workflow_runs'][0]
    pins['workflow_runs'] = []
    for index, workflow in enumerate(sorted(subject.REQUIRED_CANDIDATE_WORKFLOWS)):
        run = copy.deepcopy(template)
        run.update(workflow=workflow, path='.github/workflows/' + workflow, run_id=123 + index)
        for job in run['jobs'].values():
            job.update(id=456 + index, run_id=123 + index)
        pins['workflow_runs'].append(run)
    return plan, pins, snapshot


class MemoryJournal:
    """The real adapter must be pinned/fsynced; this fixture simulates its CAS API."""
    def __init__(self):
        self.state = None
        self.fail_before_event = None
        self.crash_after_event = None

    def load(self):
        return copy.deepcopy(self.state)

    def commit(self, old, state):
        expected_old = None if self.state is None else subject.digest(self.state)
        if old != expected_old:
            raise AssertionError('CAS changed')
        event = state['history'][-1]['event']
        if event == self.fail_before_event:
            raise Crash()
        self.state = copy.deepcopy(state)
        if event == self.crash_after_event:
            raise Crash()
        return subject.digest(state)


class FakeWorld:
    def __init__(self, plan, pins, snapshot, journal):
        self.plan, self.pins, self.snapshot, self.journal = plan, pins, copy.deepcopy(snapshot), journal
        self.calls, self.recoveries, self.effects = [], [], {}
        self.workspace_enabled = False
        self.partial_next_migration = False
        self.crash_after_action = None
        self.unknown_recovery = False
        self.modify_result = lambda _name, result: result
        self.reserved60 = False

    def approved(self):
        return subject.ApprovedActuators({name: {'code_sha256': self.pins['actuators'][name],
            'invoke': self.invoke, 'recover': self.recover} for name in subject.ACTIONS})

    def recover(self, action):
        self.recoveries.append(copy.deepcopy(action))
        if self.unknown_recovery:
            return {'status': 'unknown', 'receipt': None}
        found = self.effects.get(subject.digest(action))
        if found is None:
            return {'status': 'unknown', 'receipt': None}
        return copy.deepcopy(found)

    def invoke(self, action):
        self.calls.append(copy.deepcopy(action))
        pending = self.journal.state['pending']
        if pending is None or pending['action_sha256'] != subject.digest(action):
            raise AssertionError('Actuator ran before durable pending intent')
        name, parameters, binding = action['name'], action['parameters'], self.plan['binding']
        if name not in ('activation_flag', 'activation_cache', 'activation_route_cache', 'activation_fpm', 'activation_https'):
            if self.workspace_enabled:
                raise AssertionError('Feature enabled before complete preactivation phases')
        false = {'workspace_enabled': False, 'automatic_updates_enabled': False}
        status = 'complete'
        if name == 'observe_predecessor':
            predecessor = self.plan['predecessor']
            result = {**subject.identity_fields(), 'counter': predecessor['counter'],
                'published_candidate': predecessor['candidate'], 'published_version': predecessor['version'],
                'desired_candidate': predecessor['candidate'], 'desired_version': predecessor['version'],
                'desired_status': 'published', 'desired_pushed': True,
                **{key: predecessor[key] for key in ('inventory_sha256', 'acceptance_receipt_sha256',
                    'installer_sha256', 'snapshot_sha256', 'remote_lease')}, **false}
            if self.reserved60:
                result.update(counter=60, desired_candidate='f' * 40, desired_version='0.4.4-preview.60',
                    desired_status='blocked', remote_lease='f' * 40)
        elif name == 'source_apply':
            result = {'source_plan_sha256': self.plan['source_plan_sha256'],
                'source_manifest_sha256': binding['source_manifest_sha256'], 'release': binding['web_release'],
                'all_new_files_before_replacements': True, 'replacement_order_sha256': self.plan['replacement_order_sha256'],
                'modified_dependency_order_verified': True,
                'source_committed': True, 'handler_configuration_sha256': binding['php_handler_configuration_sha256'], **false}
        elif name == 'migration_observe':
            result = {'snapshot': copy.deepcopy(self.snapshot), **false}
        elif name == 'migration_apply':
            tables = parameters['remaining_tables']
            partial = self.partial_next_migration
            if partial:
                tables = tables[:1]
                status = 'owned-partial'
                self.partial_next_migration = False
            own_tables(self.plan['migration_intent'], self.snapshot, parameters['migration_path'], tables, ledger=not partial)
            result = {'snapshot': copy.deepcopy(self.snapshot),
                **{key: parameters[key] for key in ('migration_path', 'migration_sha256', 'run_as_uid', 'run_as_gid', 'php', 'cwd')},
                'dropped_tables': [], 'financial_rows_changed': False, 'raw_output_disclosed': False, **false}
        elif name == 'verify_candidate':
            result = {**self.pins['gate'], **{key: True for key in states.GATE_TRUE_FIELDS},
                'version': self.pins['version'], 'workflow_runs': self.pins['workflow_runs'],
                'program_hashes': self.plan['program_hashes'], **false}
        elif name == 'https_readiness':
            result = {'origin': subject.ORIGIN, 'source_manifest_sha256': binding['source_manifest_sha256'],
                'handler_configuration_sha256': binding['php_handler_configuration_sha256'],
                'healthy': True, 'served_configuration_verified': True, 'current_env_sha256': binding['env_before_sha256'],
                'env_unchanged_bytes_sha256': binding['env_unchanged_bytes_sha256'], 'owned_env_inode_verified': True, **false}
        else:
            event = parameters['event']
            result = {'intent_sha256': parameters['activation_intent_sha256'],
                'action_intent_sha256': parameters['action_intent_sha256'],
                'private_receipt_sha256': 'c' * 64}
            if event == 'handler_verified':
                result['configuration_sha256'] = binding['php_handler_configuration_sha256']
            elif event == 'flag_written':
                self.workspace_enabled = True
                result.update(flag=states.FLAG, before_sha256=binding['env_before_sha256'],
                    enabled_sha256=binding['env_enabled_sha256'], unchanged_bytes_sha256=binding['env_unchanged_bytes_sha256'],
                    current_sha256=binding['env_enabled_sha256'], only_workspace_flag_changed=True, owned_inode_verified=True)
            elif event == 'cache_built':
                result.update(cache_sha256='d' * 64, workspace_enabled=True, local=False,
                    env_sha256=binding['env_enabled_sha256'], source_manifest_sha256=binding['source_manifest_sha256'],
                    owned_cache_verified=True)
            elif event == 'route_cache_built':
                result.update(route_cache_sha256='e' * 64, workspace_routes_verified=True,
                    source_manifest_sha256=binding['source_manifest_sha256'])
            elif event == 'fpm_reloaded':
                result.update(unit=subject.FPM, active=True, handler_before_sha256=binding['php_handler_configuration_sha256'],
                    handler_after_sha256=binding['php_handler_configuration_sha256'], reload_completed=True,
                    cache_sha256=parameters['facts']['cache_sha256'], route_cache_sha256=parameters['facts']['route_cache_sha256'])
            elif event == 'https_verified':
                result.update(origin=subject.ORIGIN, workspace_enabled=True, local=False,
                    source_manifest_sha256=binding['source_manifest_sha256'], cache_sha256=parameters['facts']['cache_sha256'],
                    php_handler_configuration_sha256=binding['php_handler_configuration_sha256'], served_configuration_verified=True)
            else:
                raise AssertionError(event)
        result = self.modify_result(name, copy.deepcopy(result))
        receipt = {'action_sha256': subject.digest(action), 'private_receipt_sha256': 'c' * 64, 'result': result}
        self.effects[subject.digest(action)] = {'status': status, 'receipt': copy.deepcopy(receipt)}
        if name == self.crash_after_action:
            raise Crash()
        return receipt


class CoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.plan, self.pins, self.snapshot = fixtures()
        self.journal = MemoryJournal()
        self.world = FakeWorld(self.plan, self.pins, self.snapshot, self.journal)

    def coordinator(self, pins=True):
        return subject.Coordinator(states, self.plan, self.journal, self.world.approved(),
            compiled_pins=self.pins if pins else None)

    def until(self, phase, maximum=50):
        coordinator = self.coordinator()
        for _ in range(maximum):
            result = coordinator.step()
            if result['phase'] == phase:
                return coordinator
        self.fail('Phase did not complete: ' + phase)

    def test_unknown_final_pins_block_all_journal_and_actuator_writes(self):
        self.assertIsNone(subject.COMPILED_PINS)
        with self.assertRaisesRegex(subject.Stop, 'FINAL_COMPILED_PINS_UNAVAILABLE'):
            self.coordinator(False).step()
        self.assertIsNone(self.journal.state)
        self.assertEqual(self.world.calls, [])

    def test_exact_actuator_hash_roster_and_plan_pins_are_required(self):
        self.pins['actuators']['source_apply'] = '1' * 64
        # The loader's approved object retains different bytes from the gate.
        approved = self.world.approved()
        self.pins['actuators']['source_apply'] = 'f' * 64
        with self.assertRaisesRegex(subject.Stop, 'FINAL_COMPILED_PINS_CHANGED'):
            subject.Coordinator(states, self.plan, self.journal, approved, compiled_pins=self.pins).step()
        self.assertIsNone(self.journal.state)

    def test_original_candidate_workflow_cannot_be_omitted_from_final_pins(self):
        self.pins['workflow_runs'] = self.pins['workflow_runs'][:-1]
        with self.assertRaisesRegex(subject.Stop, 'ORIGINAL_CANDIDATE_CI_GATES_REQUIRED'):
            self.coordinator().step()
        self.assertIsNone(self.journal.state)
        self.assertEqual(self.world.calls, [])

    def test_checkpoint_body_cannot_jump_past_source_and_migration_phases(self):
        self.until('source')
        self.journal.state['phase'] = 'ci'
        before = len(self.world.calls)
        with self.assertRaisesRegex(subject.Stop, 'JOURNAL_CHECKPOINT_BODY_CHANGED'):
            self.coordinator().step()
        self.assertEqual(len(self.world.calls), before)

    def test_full_fixture_progression_keeps_flag_false_until_ci_and_https_readiness(self):
        coordinator = self.until('active')
        names = [call['name'] for call in self.world.calls]
        self.assertLess(names.index('observe_predecessor'), names.index('source_apply'))
        self.assertLess(names.index('source_apply'), names.index('migration_apply'))
        self.assertLess(names.index('verify_candidate'), names.index('https_readiness'))
        self.assertLess(names.index('https_readiness'), names.index('activation_flag'))
        self.assertEqual(names[-1], 'activation_https')
        self.assertTrue(coordinator.summary()['workspace_enabled'])
        self.assertFalse(coordinator.summary()['automatic_updates_enabled'])
        self.assertFalse(coordinator.summary()['allocation_allowed'])
        self.assertFalse(coordinator.summary()['artifact_replacement_allowed'])
        self.assertFalse(coordinator.summary()['published'])
        self.assertTrue(all(call['identity'] == subject.identity_fields() for call in self.world.calls))

    def test_reserved_old_60_is_observed_and_held_without_source_or_allocation(self):
        self.world.reserved60 = True
        coordinator = self.until('predecessor-resume-required')
        self.assertEqual([call['name'] for call in self.world.calls], ['observe_predecessor'])
        before = self.journal.load()
        self.assertEqual(coordinator.step()['phase'], 'predecessor-resume-required')
        self.assertEqual(self.journal.load(), before)
        self.assertFalse(coordinator.summary()['allocation_allowed'])

    def test_source_pending_is_durable_before_any_source_effect(self):
        self.until('source')
        self.journal.fail_before_event = 'pending:source_apply'
        with self.assertRaises(Crash):
            self.coordinator().step()
        self.assertNotIn('source_apply', [call['name'] for call in self.world.calls])

    def test_source_crash_recovers_owned_receipt_without_repeating_effect(self):
        self.until('source')
        self.world.crash_after_action = 'source_apply'
        with self.assertRaises(Crash):
            self.coordinator().step()
        self.world.crash_after_action = None
        self.assertEqual(self.coordinator().step()['phase'], 'migration-observe')
        self.assertEqual(sum(call['name'] == 'source_apply' for call in self.world.calls), 1)
        self.assertEqual([call['name'] for call in self.world.recoveries], ['source_apply'])

    def test_unknown_pending_effect_never_blindly_repeats_source_or_migration(self):
        self.until('source')
        self.world.crash_after_action = 'source_apply'
        with self.assertRaises(Crash):
            self.coordinator().step()
        self.world.unknown_recovery = True
        with self.assertRaisesRegex(subject.Stop, 'PENDING_EFFECT_REQUIRES_REVIEW'):
            self.coordinator().step()
        self.assertEqual(sum(call['name'] == 'source_apply' for call in self.world.calls), 1)

    def test_partial_ddl_crash_recovers_owned_tables_then_requests_only_remaining_tables(self):
        self.until('migration')
        self.world.partial_next_migration = True
        self.world.crash_after_action = 'migration_apply'
        with self.assertRaises(Crash):
            self.coordinator().step()
        self.world.crash_after_action = None
        self.coordinator().step()  # Read owned partial receipt, do not rerun action.
        self.assertEqual(sum(call['name'] == 'migration_apply' for call in self.world.calls), 1)
        self.coordinator().step()
        calls = [call for call in self.world.calls if call['name'] == 'migration_apply']
        self.assertEqual(calls[0]['parameters']['remaining_tables'], list(states.TABLES[:3]))
        self.assertEqual(calls[1]['parameters']['remaining_tables'], list(states.TABLES[1:3]))
        self.assertEqual(calls[1]['parameters']['mode'], 'owned-resume')
        self.assertEqual(calls[1]['parameters']['approved_resume_helper_sha256'], self.pins['migration_resume_helper_sha256'])

    def test_migration_effects_are_only_two_reviewed_paths_under_account_with_no_drops(self):
        self.until('ci')
        calls = [call for call in self.world.calls if call['name'] == 'migration_apply']
        self.assertEqual([call['parameters']['migration_path'] for call in calls], list(states.MIGRATIONS))
        for call in calls:
            parameters = call['parameters']
            self.assertEqual((parameters['run_as_uid'], parameters['run_as_gid']), (1001, 1003))
            self.assertEqual(parameters['php'], subject.PHP)
            self.assertEqual(parameters['cwd'], subject.ROOT)
            self.assertFalse(parameters['drop_allowed'])
            self.assertFalse(parameters['down_allowed'])
            self.assertFalse(parameters['financial_row_updates_allowed'])
            self.assertFalse(parameters['unrestricted_migrate_allowed'])
            self.assertTrue(parameters['flag_must_stay_false'])

    def test_noop_migration_receipt_never_clears_pending_for_blind_repeat(self):
        self.until('migration')
        before = copy.deepcopy(self.world.snapshot)
        def modify(name, result):
            if name == 'migration_apply':
                result['snapshot'] = before
            return result
        self.world.modify_result = modify
        with self.assertRaisesRegex(subject.Stop, 'MIGRATION_NO_OWNED_PROGRESS_REQUIRES_REVIEW'):
            self.coordinator().step()
        self.assertEqual(self.journal.state['pending']['action']['name'], 'migration_apply')
        with self.assertRaisesRegex(subject.Stop, 'MIGRATION_NO_OWNED_PROGRESS_REQUIRES_REVIEW'):
            self.coordinator().step()
        self.assertEqual(sum(call['name'] == 'migration_apply' for call in self.world.calls), 1)

    def test_foreign_ddl_drop_financial_change_or_wrong_actor_receipt_is_rejected(self):
        for key, value in (('dropped_tables', ['financial_table']),
                ('financial_rows_changed', True), ('run_as_uid', 0), ('run_as_gid', True)):
            with self.subTest(key=key):
                self.setUp()
                self.until('migration')
                def modify(name, result):
                    if name == 'migration_apply':
                        result[key] = value
                    return result
                self.world.modify_result = modify
                with self.assertRaisesRegex(subject.Stop, 'MIGRATION_EFFECT_SCOPE_CHANGED'):
                    self.coordinator().step()
                self.assertFalse(self.journal.state['workspace_enabled'])

    def test_matching_foreign_table_is_conflict_and_never_dropped_or_recreated(self):
        self.world.snapshot['tables'][states.TABLES[0]] = schema()
        self.until('migration')
        before = copy.deepcopy(self.world.snapshot)
        with self.assertRaisesRegex(subject.Stop, 'FOREIGN_MIGRATION_REQUIRES_REVIEW'):
            self.coordinator().step()
        self.assertEqual(self.world.snapshot, before)
        self.assertNotIn('migration_apply', [call['name'] for call in self.world.calls])

    def test_ci_incomplete_or_wrong_candidate_never_enables_feature(self):
        for field, value in (('full_native_lifecycle_accepted', False), ('candidate_revision', 'f' * 40)):
            with self.subTest(field=field):
                self.setUp()
                self.until('ci')
                def modify(name, result):
                    if name == 'verify_candidate':
                        result[field] = value
                    return result
                self.world.modify_result = modify
                with self.assertRaisesRegex(subject.Stop, 'EXACT_FULL_CANDIDATE_CI_REQUIRED'):
                    self.coordinator().step()
                self.assertFalse(self.world.workspace_enabled)
                self.assertNotIn('activation_flag', [call['name'] for call in self.world.calls])

    def test_workflow_repository_head_path_latest_attempt_and_jobs_are_code_pinned(self):
        mutations = ((('repository_id',), 1311412085), (('head_sha',), 'f' * 40),
            (('path',), '.github/workflows/unreviewed.yml'), (('latest_run_and_attempt',), False),
            (('jobs', 'full-native', 'conclusion'), 'skipped'), (('jobs', 'full-native', 'attempt'), 2))
        for keys, value in mutations:
            with self.subTest(keys=keys):
                self.setUp()
                row = self.pins['workflow_runs'][0]
                for key in keys[:-1]:
                    row = row[key]
                row[keys[-1]] = value
                with self.assertRaisesRegex(subject.Stop, 'WORKFLOW_'):
                    self.coordinator().step()
                self.assertIsNone(self.journal.state)
                self.assertEqual(self.world.calls, [])

    def test_source_receipt_must_prove_all_additions_first_and_exact_replacement_order(self):
        for key, value in (('all_new_files_before_replacements', False), ('replacement_order_sha256', 'f' * 64)):
            with self.subTest(key=key):
                self.setUp()
                self.until('source')
                def modify(name, result):
                    if name == 'source_apply':
                        result[key] = value
                    return result
                self.world.modify_result = modify
                with self.assertRaisesRegex(subject.Stop, 'SOURCE_RECEIPT_CHANGED'):
                    self.coordinator().step()
                self.assertFalse(self.journal.state['workspace_enabled'])

    def test_actual_flag_false_https_readiness_is_mandatory_before_activation(self):
        self.until('https-readiness')
        def modify(name, result):
            if name == 'https_readiness':
                result['healthy'] = False
            return result
        self.world.modify_result = modify
        with self.assertRaisesRegex(subject.Stop, 'FALSE_FLAG_HTTPS_READINESS_REQUIRED'):
            self.coordinator().step()
        self.assertFalse(self.world.workspace_enabled)
        self.assertNotIn('activation_flag', [call['name'] for call in self.world.calls])

    def test_pending_flag_crash_uses_receipt_recovery_and_never_repeats_flag_write(self):
        self.until('activation')
        self.world.crash_after_action = 'activation_flag'
        coordinator = self.coordinator()
        with self.assertRaises(Crash):
            for _ in range(5):
                coordinator.step()
        self.world.crash_after_action = None
        self.coordinator().step()
        self.assertEqual(sum(call['name'] == 'activation_flag' for call in self.world.calls), 1)
        self.assertTrue(self.journal.state['workspace_enabled'])

    def test_unexpected_true_flag_before_ci_or_noninteger_actor_proofs_fail(self):
        self.until('source')
        def modify(name, result):
            if name == 'source_apply':
                result['workspace_enabled'] = True
            return result
        self.world.modify_result = modify
        with self.assertRaisesRegex(subject.Stop, 'FEATURE_MUST_STAY_DISABLED'):
            self.coordinator().step()
        self.assertFalse(self.journal.state['workspace_enabled'])

    def test_program_contract_and_pinned_version_must_match_exact_predecessor(self):
        self.plan['program_hashes']['desktop/workspace-archive.php'] = 'f' * 64
        with self.assertRaisesRegex(subject.Stop, 'FINAL_COMPILED_PINS_CHANGED'):
            self.coordinator().step()
        self.assertIsNone(self.journal.state)

    def test_private_receipts_are_bounded_and_unknown_actuators_are_not_accepted(self):
        entries = {name: {'code_sha256': 'f' * 64, 'invoke': lambda _action: {}, 'recover': lambda _action: {}}
            for name in subject.ACTIONS}
        entries['drop_tables'] = entries['source_apply']
        with self.assertRaisesRegex(subject.Stop, 'ACTUATOR_ROSTER_INVALID'):
            subject.ApprovedActuators(entries)
        self.until('source')
        self.world.modify_result = lambda _name, result: {**result, 'unbounded': 'x' * (subject.MAX_PRIVATE_RECEIPT + 1)}
        with self.assertRaisesRegex(subject.Stop, 'PRIVATE_RECEIPT_LIMIT_OR_SHAPE'):
            self.coordinator().step()


if __name__ == '__main__':
    unittest.main()
