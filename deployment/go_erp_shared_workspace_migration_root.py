#!/usr/bin/env python3
"""Production additive migration actuator; process effects are gated and injectable.

Only the two reviewed PHP migrations can create their four new tables. A root
private receipt is persisted after each individually executed CREATE. A table
created before its completion receipt is durable remains foreign/review-required.
No down/drop/backfill/import or financial/user-row update path exists here.
"""
from pathlib import Path
import copy
import re

PINS = None
ROOT = '/home/fasakha/public_html'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
UID, GID = 1001, 1003
LIMIT = 128 * 1024
SHA = re.compile(r'[a-f0-9]{64}\Z')
GATE_ANCHOR = b'const GO_ERP_WORKSPACE_MIGRATION_GATE = null;'


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_MIGRATION_' + code)


def materialize_program(io, template, gate):
    need(isinstance(template, bytes) and template.count(GATE_ANCHOR) == 1, 'PROGRAM_TEMPLATE_CHANGED')
    digest = io.sha(io.canonical(gate))
    return template.replace(GATE_ANCHOR,
        ("const GO_ERP_WORKSPACE_MIGRATION_GATE = '" + digest + "';").encode())


class MigrationActuator:
    def __init__(self, io, states, runner, audit_parent, intent, program_template,
            verify_locks, verify_manual_only, *, pins=None):
        self.io, self.states, self.runner = io, states, runner
        self.intent, self.template = copy.deepcopy(intent), program_template
        self.verify_locks, self.verify_manual_only = verify_locks, verify_manual_only
        self.pins = PINS if pins is None else copy.deepcopy(pins)
        self.intent_sha = states.checked_migration_intent(intent)
        self.audit = Path(audit_parent) / ('workspace-sql-' + self.intent_sha)
        self.gate = {'policy': states.POLICY, 'release': intent['release'],
            'source_manifest_sha256': intent['source_manifest_sha256'],
            'migration_hashes': intent['migration_hashes'],
            'schema_hashes': {table: row['schema_sha256'] for table, row in intent['expected_schemas'].items()}}

    def verify(self):
        pins = self.pins
        need(isinstance(pins, dict) and set(pins) == {'template_sha256', 'program_sha256',
                'intent_sha256', 'gate_sha256', 'resume_helper_sha256'}
            and all(isinstance(value, str) and SHA.fullmatch(value) and value != '0' * 64 for value in pins.values()),
            'FINAL_PINS_UNAVAILABLE')
        need(pins['template_sha256'] == self.io.sha(self.template) and pins['intent_sha256'] == self.intent_sha
            and pins['gate_sha256'] == self.io.sha(self.io.canonical(self.gate)), 'COMPILED_BINDING_CHANGED')
        self.program = materialize_program(self.io, self.template, self.gate)
        need(self.io.sha(self.program) == pins['program_sha256'], 'PROGRAM_PIN_CHANGED')
        need(callable(self.verify_locks) and self.verify_locks() is not False
            and callable(self.verify_manual_only) and self.verify_manual_only() is not False, 'LOCK_OR_MANUAL_MODE_CHANGED')

    def _prepare(self):
        self.verify()
        self.io.directory(self.audit.parent, 0, 0, private=True)
        self.io.private_directory(self.audit, self.verify)
        self.io.private_record(self.audit / 'intent.json', self.intent, self.verify)

    def _record(self, name):
        value = self.io.read(self.audit / name, 0, 0, optional=True, private=True)
        return None if value is None else self.io.parse(value[0])

    def _call(self, mode, path=None, table=None):
        self.verify()
        need(mode in ('observe', 'create-table', 'record-ledger') and
            (path is None if mode == 'observe' else path in self.states.MIGRATIONS), 'PROCESS_SCOPE_INVALID')
        if mode == 'create-table':
            need(table in self.states.MIGRATIONS[path], 'TABLE_SCOPE_INVALID')
        else:
            need(table is None, 'TABLE_SCOPE_INVALID')
        specification = {'database_id_sha256': self.intent['database_id_sha256'], 'gate': self.gate,
            'migration_path': path, 'mode': mode, 'table': table}
        response = self.runner.php(self.program, specification, cwd=ROOT, uid=UID, gid=GID,
            timeout=180, maximum=LIMIT)
        need(isinstance(response, dict) and set(response) == {'returncode', 'stdout', 'stderr'}
            and type(response['returncode']) is int and isinstance(response['stdout'], bytes)
            and isinstance(response['stderr'], bytes) and len(response['stdout']) + len(response['stderr']) <= LIMIT,
            'PRIVATE_PROCESS_OUTPUT_INVALID')
        capture = self.io.canonical({'mode': mode, 'migration': path, 'table': table,
            'stdout_sha256': self.io.sha(response['stdout']), 'stderr_sha256': self.io.sha(response['stderr']),
            'returncode': response['returncode']})
        self.io.private_record(self.audit / ('process-' + self.io.sha(capture) + '.json'), capture, self.verify)
        # Raw output, including bootstrap errors, stays private and is never
        # interpolated into exceptions or public rollout output.
        for label in ('stdout', 'stderr'):
            raw = response[label]
            self.io.private_record(self.audit / ('private-' + label + '-' + self.io.sha(raw) + '.bin'), raw, self.verify)
        need(response['returncode'] == 0, 'PRIVATE_PHP_EFFECT_FAILED')
        value = self.io.parse(response['stdout'])
        need(set(value) == {'database_id_sha256', 'tables', 'ledger', 'workspace_enabled', 'local',
                'financial_rows_changed', 'dropped_tables'}
            and value['database_id_sha256'] == self.intent['database_id_sha256']
            and value['workspace_enabled'] is False and value['local'] is False
            and value['financial_rows_changed'] is False and value['dropped_tables'] == []
            and isinstance(value['tables'], dict)
            and set(value['tables']) == set(self.states.TABLES + self.states.PRIVATE_TABLES)
            and isinstance(value['ledger'], dict) and set(value['ledger']) == set(self.states.MIGRATIONS),
            'DATABASE_OR_EFFECT_SCOPE_CHANGED')
        return value

    def _snapshot(self, observed):
        journal = {'intent_sha256': self.intent_sha, 'table_intents': {}, 'table_receipts': {}, 'ledger_receipts': {}}
        ledger = {}
        for table in self.states.TABLES:
            prior = self._record('table-intent-' + table + '.json')
            receipt = self._record('table-created-' + table + '.json')
            if prior is not None:
                journal['table_intents'][table] = prior
            if receipt is not None:
                journal['table_receipts'][table] = receipt
        for path in self.states.MIGRATIONS:
            stored = self._record('ledger-' + self.io.sha(path.encode()) + '.json')
            row = observed['ledger'][path]
            if stored is not None:
                need(set(stored) == {'row', 'receipt'}, 'LEDGER_RECEIPT_INVALID')
                journal['ledger_receipts'][path] = stored['receipt']
            if row is None:
                ledger[path] = None
            elif stored is not None and self.io.canonical(row) == self.io.canonical(stored['row']):
                ledger[path] = stored['receipt']
            else:
                ledger[path] = {'foreign_ledger_row': row}
        return {'tables': copy.deepcopy(observed['tables']), 'journal': journal, 'ledger': ledger,
            'persisted_intent_sha256': self.intent_sha}

    def _checkpoint(self, snapshot):
        return self.states.migration_checkpoint(self.intent, snapshot['tables'], snapshot['journal'], snapshot['ledger'],
            persisted_intent_sha256=snapshot['persisted_intent_sha256'])

    def observe(self):
        self._prepare()
        observed = self._call('observe')
        return observed, self._snapshot(observed)

    def _scope(self, before, after, *, created_table=None, recorded_migration=None):
        for table in self.states.TABLES + self.states.PRIVATE_TABLES:
            if table == created_table:
                need(before['tables'][table] is None and after['tables'][table] is not None, 'CREATE_NOT_FROM_ABSENCE')
            else:
                need(self.io.canonical(before['tables'][table]) == self.io.canonical(after['tables'][table]),
                    'UNREVIEWED_TABLE_CHANGED')
        for path in self.states.MIGRATIONS:
            if path == recorded_migration:
                need(before['ledger'][path] is None and isinstance(after['ledger'][path], dict), 'LEDGER_NOT_FROM_ABSENCE')
            else:
                need(self.io.canonical(before['ledger'][path]) == self.io.canonical(after['ledger'][path]),
                    'UNREVIEWED_LEDGER_CHANGED')

    def apply(self, parameters):
        self._prepare()
        path = parameters.get('migration_path')
        need(path in self.states.MIGRATIONS
            and parameters.get('migration_sha256') == self.intent['migration_hashes'][path]
            and parameters.get('intent_sha256') == self.intent_sha
            and parameters.get('approved_resume_helper_sha256') == self.pins['resume_helper_sha256']
            and parameters.get('cwd') == ROOT and parameters.get('php') == PHP
            and type(parameters.get('run_as_uid')) is int and parameters['run_as_uid'] == UID
            and type(parameters.get('run_as_gid')) is int and parameters['run_as_gid'] == GID
            and parameters.get('private_output_limit') == LIMIT and parameters.get('flag_must_stay_false') is True
            and all(parameters.get(name) is False for name in ('unrestricted_migrate_allowed', 'down_allowed',
                'drop_allowed', 'financial_row_updates_allowed')), 'ACTION_SCOPE_INVALID')
        before, snapshot = self.observe()
        checked = self._checkpoint(snapshot)
        need(checked['state'] in ('absent', 'owned-partial')
            and self.states.digest(checked) == parameters.get('expected_checkpoint_sha256'), 'CHECKPOINT_CHANGED_OR_FOREIGN')
        expected_path = next(name for name in self.states.MIGRATIONS if name not in checked['completed_migrations'])
        remaining = [table for table in self.states.MIGRATIONS[path] if table in checked['missing_tables']]
        owned = [table for table in self.states.MIGRATIONS[path] if table in checked['owned_tables']]
        need(path == expected_path and parameters.get('remaining_tables') == remaining
            and parameters.get('owned_tables') == owned
            and parameters.get('mode') == ('owned-resume' if owned or not remaining else 'fresh-reviewed-path')
            and self.io.canonical(parameters.get('table_intents')) == self.io.canonical({table:
                self.states.table_intent(self.intent, table) for table in remaining}), 'REMAINING_SCOPE_CHANGED')
        for table in remaining:
            self.io.private_record(self.audit / ('table-intent-' + table + '.json'),
                self.states.table_intent(self.intent, table), self.verify)
            after = self._call('create-table', path, table)
            self._scope(before, after, created_table=table)
            receipt = self.states.table_receipt(self.intent, table, after['tables'][table])
            self.io.private_record(self.audit / ('table-created-' + table + '.json'), receipt, self.verify)
            before = after
        snapshot = self._snapshot(before)
        checked = self._checkpoint(snapshot)
        need(checked['state'] != 'foreign-conflict' and all(table in checked['owned_tables']
            for table in self.states.MIGRATIONS[path]), 'MIGRATION_TABLES_NOT_OWNED')
        if before['ledger'][path] is None:
            ledger_receipt = self.states.ledger_receipt(self.intent, path)
            self.io.private_record(self.audit / ('ledger-intent-' + self.io.sha(path.encode()) + '.json'),
                {'before_absent': True, 'receipt': ledger_receipt}, self.verify)
            after = self._call('record-ledger', path)
            self._scope(before, after, recorded_migration=path)
            self.io.private_record(self.audit / ('ledger-' + self.io.sha(path.encode()) + '.json'),
                {'row': after['ledger'][path], 'receipt': ledger_receipt}, self.verify)
            before = after
        snapshot = self._snapshot(before)
        need(self._checkpoint(snapshot)['state'] != 'foreign-conflict', 'AFTER_MIGRATION_FOREIGN')
        return self.result(path, snapshot)

    def result(self, path, snapshot):
        return {'snapshot': snapshot, 'migration_path': path,
            'migration_sha256': self.intent['migration_hashes'][path], 'run_as_uid': UID, 'run_as_gid': GID,
            'php': PHP, 'cwd': ROOT, 'dropped_tables': [], 'financial_rows_changed': False,
            'raw_output_disclosed': False, 'workspace_enabled': False, 'automatic_updates_enabled': False,
            'database_changed': True, 'database_new_tables': len(self.states.MIGRATIONS[path])}

    def recover(self, parameters):
        observed, snapshot = self.observe()
        checked = self._checkpoint(snapshot)
        need(checked['state'] != 'foreign-conflict', 'UNRECEIPTED_DDL_REQUIRES_REVIEW')
        path = parameters.get('migration_path')
        need(path in self.states.MIGRATIONS and parameters.get('intent_sha256') == self.intent_sha,
            'RECOVERY_BINDING_INVALID')
        return {'status': 'complete' if path in checked['completed_migrations'] else 'owned-partial',
            'result': self.result(path, snapshot)}
