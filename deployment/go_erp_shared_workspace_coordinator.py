#!/usr/bin/env python3
"""Concrete rollout orchestration through code-pinned, injected actuators only.

This module performs no filesystem, subprocess, SQL, network or service operation.
The final root loader must verify the engine/actuator bytes, original locks and
private Journal implementation. No request may supply plans, pins or actuators.
Compiled final pins are unavailable, so production effects are blocked by default.
"""
import copy
import hashlib
import json
import re

POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
COMPILED_PINS = None
REQUIRED_CANDIDATE_WORKFLOWS = frozenset(('desktop-dashboard-tests.yml',
    'go-erp-update-tests.yml', 'whatsapp-integrated-regressions.yml'))
ROOT = '/home/fasakha/public_html'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
WORK = '/var/lib/go-erp-live-updates'
CONTROL = '/var/lib/go-erp-manual-updates'
FPM = 'ea-php82-php-fpm.service'
ORIGIN = 'https://fasakhaninja.com'
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
VERSION = re.compile(r'0\.4\.4-preview\.([1-9][0-9]{0,14})\Z')
MAX_PRIVATE_RECEIPT = 128 * 1024
PROGRAMS = frozenset(('desktop/workspace-archive.php', 'desktop/workspace-control.php',
    'desktop/workspace-delta.php', 'desktop/workspace-import.php', 'desktop/workspace-verify.php'))
ACTIONS = ('observe_predecessor', 'source_apply', 'migration_observe', 'migration_apply',
    'verify_candidate', 'https_readiness', 'verify_handler', 'activation_flag',
    'activation_cache', 'activation_route_cache', 'activation_fpm', 'activation_https')
READ_ONLY = frozenset(('observe_predecessor', 'migration_observe', 'verify_candidate',
    'https_readiness', 'verify_handler', 'activation_https'))
PHASES = ('observe', 'predecessor-resume-required', 'source', 'migration-observe',
    'migration', 'ci', 'https-readiness', 'activation', 'active', 'review-required')
PREDECESSOR_FIELDS = ('counter', 'candidate', 'version', 'inventory_sha256',
    'acceptance_receipt_sha256', 'installer_sha256', 'snapshot_sha256', 'remote_lease')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_COORDINATOR_' + code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def same(left, right):
    return canonical(left) == canonical(right)


def hash_pin(value):
    return isinstance(value, str) and bool(SHA.fullmatch(value)) and value != '0' * 64


def revision(value):
    return isinstance(value, str) and bool(REV.fullmatch(value)) and value != '0' * 40


def exact(value, fields, code):
    need(isinstance(value, dict) and set(value) == set(fields), code)


def identity_fields():
    return {'host': 'srv1836524', 'uid': 1001, 'gid': 1003, 'root': ROOT,
        'php': PHP, 'work': WORK, 'control': CONTROL, 'fpm_unit': FPM, 'origin': ORIGIN}


def checked_predecessor(value):
    exact(value, PREDECESSOR_FIELDS, 'PREDECESSOR_PIN_INVALID')
    need(type(value['counter']) is int and value['counter'] in (59, 60)
        and value['version'] == '0.4.4-preview.' + str(value['counter'])
        and revision(value['candidate']) and value['remote_lease'] == value['candidate']
        and all(hash_pin(value[name]) for name in ('inventory_sha256',
            'acceptance_receipt_sha256', 'installer_sha256', 'snapshot_sha256')),
        'PREDECESSOR_PIN_INVALID')


def inspect_predecessor(snapshot, expected):
    """Read-only branch model; a reserved .60 can never be replaced by this engine."""
    checked_predecessor(expected)
    fields = tuple(identity_fields()) + ('counter', 'published_candidate', 'published_version',
        'desired_candidate', 'desired_version', 'desired_status', 'desired_pushed',
        'inventory_sha256', 'acceptance_receipt_sha256', 'installer_sha256',
        'snapshot_sha256', 'remote_lease', 'workspace_enabled', 'automatic_updates_enabled')
    exact(snapshot, fields, 'PREDECESSOR_SNAPSHOT_INVALID')
    need(all(same(snapshot[key], value) for key, value in identity_fields().items())
        and snapshot['workspace_enabled'] is False and snapshot['automatic_updates_enabled'] is False
        and snapshot['desired_pushed'] is True and type(snapshot['counter']) is int
        and snapshot['counter'] in (59, 60) and revision(snapshot['desired_candidate'])
        and revision(snapshot['published_candidate'])
        and snapshot['desired_version'] == '0.4.4-preview.' + str(snapshot['counter'])
        and snapshot['remote_lease'] == snapshot['desired_candidate']
        and all(hash_pin(snapshot[name]) for name in ('inventory_sha256',
            'acceptance_receipt_sha256', 'installer_sha256', 'snapshot_sha256')),
        'PREDECESSOR_SNAPSHOT_UNREVIEWED')
    if snapshot['desired_status'] != 'published':
        need(snapshot['counter'] == 60 and snapshot['desired_status'] in
            ('prepared', 'waiting-ci', 'retry-build', 'blocked')
            and snapshot['published_version'] == '0.4.4-preview.59',
            'UNKNOWN_RESERVATION_REQUIRES_REVIEW')
        return {'branch': 'resume-existing-60', 'counter': 60,
            'candidate': snapshot['desired_candidate'], 'version': snapshot['desired_version'],
            'allocation_allowed': False, 'source_apply_allowed': False,
            'artifact_replacement_allowed': False}
    observed = {'counter': snapshot['counter'], 'candidate': snapshot['published_candidate'],
        'version': snapshot['published_version'], 'inventory_sha256': snapshot['inventory_sha256'],
        'acceptance_receipt_sha256': snapshot['acceptance_receipt_sha256'],
        'installer_sha256': snapshot['installer_sha256'], 'snapshot_sha256': snapshot['snapshot_sha256'],
        'remote_lease': snapshot['remote_lease']}
    need(snapshot['desired_candidate'] == snapshot['published_candidate']
        and snapshot['desired_version'] == snapshot['published_version'] and same(observed, expected),
        'ACCEPTED_PREDECESSOR_CHANGED')
    return {'branch': 'accepted-pinned-predecessor', 'counter': snapshot['counter'],
        'candidate': snapshot['published_candidate'], 'version': snapshot['published_version'],
        'allocation_allowed': False, 'source_apply_allowed': True,
        'artifact_replacement_allowed': False}


class ApprovedActuators:
    """The root loader supplies callables only after verifying their code bytes.

    Each entry has exactly code_sha256/invoke/recover. Recovery reads durable
    effect records and actual state; it must not execute the pending operation.
    This object is never constructed from web input or a release artifact's JSON.
    """
    def __init__(self, entries):
        exact(entries, ACTIONS, 'ACTUATOR_ROSTER_INVALID')
        for entry in entries.values():
            exact(entry, ('code_sha256', 'invoke', 'recover'), 'ACTUATOR_ENTRY_INVALID')
            need(hash_pin(entry['code_sha256']) and callable(entry['invoke'])
                and callable(entry['recover']), 'ACTUATOR_ENTRY_INVALID')
        self.entries = dict(entries)
        self.code_pins = {name: value['code_sha256'] for name, value in entries.items()}

    def invoke(self, action):
        return self._call(action, False)

    def recover(self, action):
        return self._call(action, True)

    def _call(self, action, recovery):
        name = action['name']
        need(name in ACTIONS, 'ACTUATOR_ACTION_INVALID')
        function = self.entries[name]['recover' if recovery else 'invoke']
        result = function(copy.deepcopy(action))
        need(isinstance(result, dict) and len(canonical(result)) <= MAX_PRIVATE_RECEIPT,
            'PRIVATE_RECEIPT_LIMIT_OR_SHAPE')
        return copy.deepcopy(result)


def make_plan(states, binding, predecessor, source_plan_sha256, migration_intent, program_hashes,
        *, replacement_order_sha256):
    """Construct the private immutable job plan, never an allocation authorization."""
    states.activation_intent(binding)
    checked_predecessor(predecessor)
    need(hash_pin(source_plan_sha256) and hash_pin(replacement_order_sha256)
        and isinstance(program_hashes, dict) and set(program_hashes) == PROGRAMS
        and all(hash_pin(value) for value in program_hashes.values()), 'PROGRAM_CONTRACT_INVALID')
    states.checked_migration_intent(migration_intent)
    need(migration_intent['release'] == binding['web_release']
        and migration_intent['source_manifest_sha256'] == binding['source_manifest_sha256'],
        'MIGRATION_RELEASE_BINDING_CHANGED')
    return {'format': 1, 'kind': 'go-erp-shared-workspace-coordinator-plan', 'policy': POLICY,
        'binding': copy.deepcopy(binding), 'predecessor': copy.deepcopy(predecessor),
        'source_plan_sha256': source_plan_sha256, 'replacement_order_sha256': replacement_order_sha256,
        'migration_intent': copy.deepcopy(migration_intent),
        'program_hashes': dict(program_hashes), 'allocation_allowed': False,
        'artifact_replacement_allowed': False, 'automatic_updates_enabled': False}


class Coordinator:
    """One resumable effect per step, using a trusted durable CAS journal adapter.

    journal.load() returns the private checkpoint or None. journal.commit(old_sha,
    next_state) must fsync the checkpoint and parent and return its canonical hash;
    the root loader must verify that implementation's pin. This engine writes no
    files itself. Invalid/unknown effect recovery is retained and requires review.
    """
    def __init__(self, states, plan, journal, actuators, *, compiled_pins=None):
        need(isinstance(actuators, ApprovedActuators) and callable(getattr(journal, 'load', None))
            and callable(getattr(journal, 'commit', None)), 'APPROVED_ADAPTERS_REQUIRED')
        self.states, self.plan, self.journal, self.actuators = states, copy.deepcopy(plan), journal, actuators
        exact(plan, ('format', 'kind', 'policy', 'binding', 'predecessor', 'source_plan_sha256', 'replacement_order_sha256',
            'migration_intent', 'program_hashes', 'allocation_allowed', 'artifact_replacement_allowed',
            'automatic_updates_enabled'), 'PLAN_INVALID')
        need(same(plan, make_plan(states, plan['binding'], plan['predecessor'], plan['source_plan_sha256'],
            plan['migration_intent'], plan['program_hashes'],
            replacement_order_sha256=plan['replacement_order_sha256'])), 'PLAN_CHANGED')
        self.pins = COMPILED_PINS if compiled_pins is None else copy.deepcopy(compiled_pins)
        self.state = journal.load()

    def _approved(self):
        pins = self.pins
        exact(pins, ('gate', 'actuators', 'program_hashes', 'workflow_runs', 'source_plan_sha256', 'replacement_order_sha256',
            'migration_intent_sha256', 'migration_resume_helper_sha256', 'predecessor', 'version'), 'FINAL_COMPILED_PINS_UNAVAILABLE')
        gate = {name: self.plan['binding'][name] for name in self.states.GATE_FIELDS}
        need(same(pins['gate'], gate) and same(pins['actuators'], self.actuators.code_pins)
            and same(pins['program_hashes'], self.plan['program_hashes'])
            and pins['source_plan_sha256'] == self.plan['source_plan_sha256']
            and pins['replacement_order_sha256'] == self.plan['replacement_order_sha256']
            and pins['migration_intent_sha256'] == self.states.digest(self.plan['migration_intent'])
            and hash_pin(pins['migration_resume_helper_sha256'])
            and same(pins['predecessor'], self.plan['predecessor'])
            and isinstance(pins['workflow_runs'], list) and pins['workflow_runs']
            and isinstance(pins['version'], str) and VERSION.fullmatch(pins['version'])
            and int(VERSION.fullmatch(pins['version'])[1]) == self.plan['predecessor']['counter'] + 1,
            'FINAL_COMPILED_PINS_CHANGED')
        seen_workflows = set()
        for run in pins['workflow_runs']:
            exact(run, ('workflow', 'path', 'workflow_sha256', 'repository_id', 'repository', 'event', 'branch',
                'head_sha', 'run_id', 'attempt', 'latest_run_and_attempt', 'status', 'conclusion', 'jobs'),
                'WORKFLOW_PIN_INVALID')
            need(isinstance(run['workflow'], str) and re.fullmatch(r'[A-Za-z0-9_.-]+\.yml', run['workflow'])
                and run['workflow'] not in seen_workflows and run['path'] == '.github/workflows/' + run['workflow']
                and type(run['repository_id']) is int and run['repository_id'] == 1311412084
                and run['repository'] == 'omarmakramelbaz-hub/F-Backend' and run['event'] == 'push'
                and isinstance(run['branch'], str) and re.fullmatch(r'[A-Za-z0-9_./-]{1,150}', run['branch'])
                and run['head_sha'] == self.plan['binding']['candidate_revision']
                and hash_pin(run['workflow_sha256']) and type(run['run_id']) is int and run['run_id'] > 0
                and type(run['attempt']) is int and run['attempt'] > 0 and isinstance(run['jobs'], dict)
                and run['latest_run_and_attempt'] is True and run['status'] == 'completed'
                and run['conclusion'] == 'success' and run['jobs'], 'WORKFLOW_PIN_INVALID')
            for name, job in run['jobs'].items():
                exact(job, ('id', 'run_id', 'attempt', 'head_sha', 'status', 'conclusion'), 'WORKFLOW_JOB_PIN_INVALID')
                need(isinstance(name, str) and re.fullmatch(r'[A-Za-z0-9 _()./-]{1,120}', name)
                    and type(job['id']) is int and job['id'] > 0 and type(job['run_id']) is int
                    and job['run_id'] == run['run_id'] and type(job['attempt']) is int
                    and job['attempt'] == run['attempt'] and job['head_sha'] == run['head_sha']
                    and job['status'] == 'completed' and job['conclusion'] == 'success', 'WORKFLOW_JOB_PIN_INVALID')
            seen_workflows.add(run['workflow'])
        need(REQUIRED_CANDIDATE_WORKFLOWS <= seen_workflows, 'ORIGINAL_CANDIDATE_CI_GATES_REQUIRED')

    def _initial(self):
        return {'format': 1, 'kind': 'go-erp-shared-workspace-coordinator-checkpoint',
            'plan_sha256': digest(self.plan), 'pins_sha256': digest(self.pins), 'phase': 'observe',
            'revision': 0, 'pending': None, 'receipts': {}, 'migration_snapshot': None,
            'activation': None, 'workspace_enabled': False, 'automatic_updates_enabled': False,
            'allocation_allowed': False, 'artifact_replacement_allowed': False, 'history': []}

    def _checked_state(self):
        initial = self._initial()
        state = self.state
        exact(state, initial, 'JOURNAL_SHAPE_CHANGED')
        need(type(state['format']) is int and state['format'] == 1 and state['kind'] == initial['kind']
            and state['plan_sha256'] == initial['plan_sha256'] and state['pins_sha256'] == initial['pins_sha256']
            and state['phase'] in PHASES and type(state['revision']) is int and state['revision'] >= 0
            and isinstance(state['receipts'], dict) and isinstance(state['history'], list)
            and len(state['history']) == state['revision']
            and state['automatic_updates_enabled'] is False and state['allocation_allowed'] is False
            and state['artifact_replacement_allowed'] is False
            and (state['workspace_enabled'] is None or type(state['workspace_enabled']) is bool),
            'JOURNAL_IDENTITY_CHANGED')
        if state['pending'] is not None:
            exact(state['pending'], ('action', 'action_sha256'), 'PENDING_ACTION_CHANGED')
            need(state['pending']['action_sha256'] == digest(state['pending']['action']), 'PENDING_ACTION_CHANGED')
        previous = None
        for index, item in enumerate(state['history']):
            exact(item, ('revision', 'event', 'previous_record_sha256', 'checkpoint_sha256', 'record_sha256'),
                'JOURNAL_HISTORY_CHANGED')
            need(type(item['revision']) is int and item['revision'] == index + 1
                and item['previous_record_sha256'] == previous
                and hash_pin(item['checkpoint_sha256'])
                and item['record_sha256'] == digest({key: value for key, value in item.items() if key != 'record_sha256'}),
                'JOURNAL_HISTORY_CHANGED')
            previous = item['record_sha256']
        need(state['history'] and state['history'][-1]['checkpoint_sha256']
            == digest({key: value for key, value in state.items() if key != 'history'}),
            'JOURNAL_CHECKPOINT_BODY_CHANGED')

    def _persist(self, result, event):
        old = None if self.state is None else digest(self.state)
        result = copy.deepcopy(result)
        result['revision'] += 1
        item = {'revision': result['revision'], 'event': event,
            'previous_record_sha256': result['history'][-1]['record_sha256'] if result['history'] else None,
            'checkpoint_sha256': digest({key: value for key, value in result.items() if key != 'history'})}
        item['record_sha256'] = digest(item)
        result['history'].append(item)
        expected = digest(result)
        need(self.journal.commit(old, copy.deepcopy(result)) == expected, 'DURABLE_JOURNAL_ACK_REQUIRED')
        need(same(self.journal.load(), result), 'DURABLE_JOURNAL_READBACK_CHANGED')
        self.state = result

    def _action(self, name, parameters):
        need(name in ACTIONS, 'ACTION_SCOPE_INVALID')
        return {'format': 1, 'kind': 'go-erp-shared-workspace-approved-action', 'name': name,
            'plan_sha256': digest(self.plan), 'pins_sha256': digest(self.pins),
            'code_sha256': self.actuators.code_pins[name], 'identity': identity_fields(),
            'read_only': name in READ_ONLY, 'parameters': copy.deepcopy(parameters),
            'financial_data_updates_allowed': False, 'drop_tables_allowed': False,
            'allocation_allowed': False, 'artifact_replacement_allowed': False,
            'automatic_updates_enabled': False}

    def _effect(self, name, parameters):
        action = self._action(name, parameters)
        recovering = self.state['pending'] is not None
        if recovering:
            need(same(self.state['pending']['action'], action), 'PENDING_ACTION_BINDING_CHANGED')
            recovered = self.actuators.recover(action)
            exact(recovered, ('status', 'receipt'), 'RECOVERY_RESULT_INVALID')
            need(recovered['status'] in ('complete', 'owned-partial'), 'PENDING_EFFECT_REQUIRES_REVIEW')
            need(recovered['status'] != 'owned-partial' or name == 'migration_apply',
                'PARTIAL_EFFECT_REQUIRES_REVIEW')
            receipt = recovered['receipt']
        else:
            result = copy.deepcopy(self.state)
            result['pending'] = {'action': action, 'action_sha256': digest(action)}
            self._persist(result, 'pending:' + name)
            receipt = self.actuators.invoke(action)
        exact(receipt, ('action_sha256', 'private_receipt_sha256', 'result'), 'EFFECT_RECEIPT_INVALID')
        need(receipt['action_sha256'] == digest(action) and hash_pin(receipt['private_receipt_sha256'])
            and isinstance(receipt['result'], dict), 'EFFECT_RECEIPT_BINDING_CHANGED')
        return receipt

    def _finish(self, receipt, phase, *, migration_snapshot=None, activation=None, workspace_enabled=None):
        result = copy.deepcopy(self.state)
        name = result['pending']['action']['name']
        result['receipts'][name] = copy.deepcopy(receipt)
        result.update(pending=None, phase=phase)
        if migration_snapshot is not None:
            result['migration_snapshot'] = copy.deepcopy(migration_snapshot)
        if activation is not None:
            result['activation'] = copy.deepcopy(activation)
        if workspace_enabled is not None:
            result['workspace_enabled'] = workspace_enabled
        self._persist(result, 'completed:' + name)

    def _migration(self, snapshot):
        exact(snapshot, ('tables', 'journal', 'ledger', 'persisted_intent_sha256'), 'MIGRATION_SNAPSHOT_INVALID')
        return self.states.migration_checkpoint(self.plan['migration_intent'], snapshot['tables'],
            snapshot['journal'], snapshot['ledger'], persisted_intent_sha256=snapshot['persisted_intent_sha256'])

    def _false_result(self, value, fields):
        exact(value, tuple(fields) + ('workspace_enabled', 'automatic_updates_enabled'), 'FALSE_FLAG_PROOF_INVALID')
        need(value['workspace_enabled'] is False and value['automatic_updates_enabled'] is False,
            'FEATURE_MUST_STAY_DISABLED')

    def step(self):
        self._approved()  # No journal or actuator writes when final pins are absent.
        if self.state is None:
            self._persist(self._initial(), 'initialized')
        self._checked_state()
        phase = self.state['phase']
        binding = self.plan['binding']
        if phase in ('active', 'predecessor-resume-required', 'review-required'):
            return self.summary()
        if phase == 'observe':
            receipt = self._effect('observe_predecessor', {'expected': self.plan['predecessor']})
            decision = inspect_predecessor(receipt['result'], self.plan['predecessor'])
            self._finish(receipt, 'source' if decision['source_apply_allowed'] else 'predecessor-resume-required')
        elif phase == 'source':
            receipt = self._effect('source_apply', {'source_plan_sha256': self.plan['source_plan_sha256'],
                'source_manifest_sha256': binding['source_manifest_sha256'], 'release': binding['web_release'],
                'all_new_files_before_replacements': True,
                'replacement_order_sha256': self.plan['replacement_order_sha256'], 'modified_dependency_order_required': True,
                'rollback_fpm_drain_before_addition_delete_required': True, 'flag_must_stay_false': True})
            value = receipt['result']
            self._false_result(value, ('source_plan_sha256', 'source_manifest_sha256', 'release',
                'all_new_files_before_replacements', 'replacement_order_sha256', 'modified_dependency_order_verified',
                'source_committed', 'handler_configuration_sha256'))
            need(value['source_plan_sha256'] == self.plan['source_plan_sha256']
                and value['source_manifest_sha256'] == binding['source_manifest_sha256']
                and value['release'] == binding['web_release'] and value['source_committed'] is True
                and value['all_new_files_before_replacements'] is True
                and value['replacement_order_sha256'] == self.plan['replacement_order_sha256']
                and value['modified_dependency_order_verified'] is True
                and value['handler_configuration_sha256'] == binding['php_handler_configuration_sha256'],
                'SOURCE_RECEIPT_CHANGED')
            self._finish(receipt, 'migration-observe')
        elif phase == 'migration-observe':
            receipt = self._effect('migration_observe', {'intent_sha256': self.states.digest(self.plan['migration_intent'])})
            self._false_result(receipt['result'], ('snapshot',))
            self._migration(receipt['result']['snapshot'])
            self._finish(receipt, 'migration', migration_snapshot=receipt['result']['snapshot'])
        elif phase == 'migration':
            snapshot = self.state['migration_snapshot']
            checkpoint = self._migration(snapshot)
            need(checkpoint['state'] != 'foreign-conflict', 'FOREIGN_MIGRATION_REQUIRES_REVIEW')
            if checkpoint['state'] == 'owned-complete':
                need(binding['migration_receipt_sha256'] == self.states.digest(checkpoint), 'MIGRATION_COMPLETION_PIN_CHANGED')
                result = copy.deepcopy(self.state)
                result['phase'] = 'ci'
                self._persist(result, 'migrations-complete')
                return self.summary()
            migration = next(name for name in self.states.MIGRATIONS if name not in checkpoint['completed_migrations'])
            tables = self.states.MIGRATIONS[migration]
            remaining = [table for table in tables if table in checkpoint['missing_tables']]
            parameters = {'migration_path': migration,
                'migration_sha256': self.plan['migration_intent']['migration_hashes'][migration],
                'intent_sha256': self.states.digest(self.plan['migration_intent']),
                'expected_checkpoint_sha256': self.states.digest(checkpoint),
                'remaining_tables': remaining, 'owned_tables': [table for table in tables if table in checkpoint['owned_tables']],
                'table_intents': {table: self.states.table_intent(self.plan['migration_intent'], table) for table in remaining},
                'mode': 'owned-resume' if any(table in checkpoint['owned_tables'] for table in tables)
                    or not remaining else 'fresh-reviewed-path',
                'approved_resume_helper_sha256': self.pins['migration_resume_helper_sha256'],
                'cwd': ROOT, 'php': PHP, 'run_as_uid': 1001, 'run_as_gid': 1003,
                'private_output_limit': MAX_PRIVATE_RECEIPT, 'flag_must_stay_false': True,
                'unrestricted_migrate_allowed': False, 'down_allowed': False, 'drop_allowed': False,
                'financial_row_updates_allowed': False}
            receipt = self._effect('migration_apply', parameters)
            value = receipt['result']
            self._false_result(value, ('snapshot', 'migration_path', 'migration_sha256', 'run_as_uid', 'run_as_gid',
                'php', 'cwd', 'dropped_tables', 'financial_rows_changed', 'raw_output_disclosed'))
            need(value['migration_path'] == migration and value['migration_sha256'] == parameters['migration_sha256']
                and type(value['run_as_uid']) is int and value['run_as_uid'] == 1001
                and type(value['run_as_gid']) is int and value['run_as_gid'] == 1003
                and value['php'] == PHP and value['cwd'] == ROOT and value['dropped_tables'] == []
                and value['financial_rows_changed'] is False and value['raw_output_disclosed'] is False,
                'MIGRATION_EFFECT_SCOPE_CHANGED')
            after = self._migration(value['snapshot'])
            need(after['state'] != 'foreign-conflict'
                and set(checkpoint['owned_tables']) <= set(after['owned_tables'])
                and set(checkpoint['completed_migrations']) <= set(after['completed_migrations'])
                and set(after['owned_tables']) - set(checkpoint['owned_tables']) <= set(remaining)
                and set(after['completed_migrations']) - set(checkpoint['completed_migrations']) <= {migration},
                'MIGRATION_EFFECT_NOT_OWNED')
            need(set(after['owned_tables']) != set(checkpoint['owned_tables'])
                or set(after['completed_migrations']) != set(checkpoint['completed_migrations']),
                'MIGRATION_NO_OWNED_PROGRESS_REQUIRES_REVIEW')
            self._finish(receipt, 'migration', migration_snapshot=value['snapshot'])
        elif phase == 'ci':
            receipt = self._effect('verify_candidate', {'gate': self.pins['gate'], 'version': self.pins['version'],
                'workflow_runs': self.pins['workflow_runs'], 'program_hashes': self.plan['program_hashes']})
            value = receipt['result']
            self._false_result(value, tuple(self.states.GATE_FIELDS) + tuple(self.states.GATE_TRUE_FIELDS)
                + ('version', 'workflow_runs', 'program_hashes'))
            need(all(value[name] == binding[name] for name in self.states.GATE_FIELDS)
                and all(value[name] is True for name in self.states.GATE_TRUE_FIELDS)
                and value['version'] == self.pins['version'] and same(value['workflow_runs'], self.pins['workflow_runs'])
                and same(value['program_hashes'], self.plan['program_hashes']), 'EXACT_FULL_CANDIDATE_CI_REQUIRED')
            self._finish(receipt, 'https-readiness')
        elif phase == 'https-readiness':
            receipt = self._effect('https_readiness', {'origin': ORIGIN, 'workspace_enabled': False,
                'source_manifest_sha256': binding['source_manifest_sha256'],
                'handler_configuration_sha256': binding['php_handler_configuration_sha256']})
            value = receipt['result']
            self._false_result(value, ('origin', 'source_manifest_sha256', 'handler_configuration_sha256',
                'healthy', 'served_configuration_verified', 'current_env_sha256', 'env_unchanged_bytes_sha256',
                'owned_env_inode_verified'))
            need(value['origin'] == ORIGIN and value['source_manifest_sha256'] == binding['source_manifest_sha256']
                and value['handler_configuration_sha256'] == binding['php_handler_configuration_sha256']
                and value['healthy'] is True and value['served_configuration_verified'] is True
                and value['current_env_sha256'] == binding['env_before_sha256']
                and value['env_unchanged_bytes_sha256'] == binding['env_unchanged_bytes_sha256']
                and value['owned_env_inode_verified'] is True, 'FALSE_FLAG_HTTPS_READINESS_REQUIRED')
            activation = self.states.activation_intent(binding)
            accepted = self.state['receipts']['verify_candidate']
            evidence = {name: accepted['result'][name] for name in self.states.GATE_FIELDS + self.states.GATE_TRUE_FIELDS}
            evidence['private_receipt_sha256'] = accepted['private_receipt_sha256']
            activation = self.states.advance_activation(activation, 'accept_gates', evidence, compiled_gate=self.pins['gate'])
            self._finish(receipt, 'activation', activation=activation)
        elif phase == 'activation':
            self._activation_step()
        return self.summary()

    def _activation_step(self):
        activation = self.state['activation']
        phase = activation['phase']
        # Every begin transition is durable before the corresponding actuator.
        begins = {'handler-verified': ('begin_flag_write', 'flag_written', 'activation_flag'),
            'flag-written': ('begin_cache_build', 'cache_built', 'activation_cache'),
            'cache-built': ('begin_route_cache_build', 'route_cache_built', 'activation_route_cache'),
            'cache-ready': ('begin_fpm_reload', 'fpm_reloaded', 'activation_fpm')}
        if phase in begins:
            event, _completed, _name = begins[phase]
            readiness = self.state['receipts']['https_readiness']
            evidence = {'intent_sha256': activation['intent_sha256'],
                'action_intent_sha256': self.states.action_intent_sha256(activation, event),
                'private_receipt_sha256': readiness['private_receipt_sha256'],
                'current_sha256': readiness['result']['current_env_sha256'],
                'owned_inode_verified': readiness['result']['owned_env_inode_verified'],
                'unchanged_bytes_sha256': readiness['result']['env_unchanged_bytes_sha256'],
                'target': 'config-cache-only', 'unit': FPM}
            changed = self.states.advance_activation(activation, event, evidence, compiled_gate=self.pins['gate'])
            result = copy.deepcopy(self.state)
            result['activation'] = changed
            self._persist(result, event)
            return
        completions = {'gates-accepted': ('handler_verified', 'verify_handler'),
            'flag-write-pending': ('flag_written', 'activation_flag'),
            'cache-build-pending': ('cache_built', 'activation_cache'),
            'route-cache-build-pending': ('route_cache_built', 'activation_route_cache'),
            'fpm-reload-pending': ('fpm_reloaded', 'activation_fpm'),
            'fpm-ready': ('https_verified', 'activation_https')}
        need(phase in completions, 'ACTIVATION_PHASE_REQUIRES_REVIEW')
        event, name = completions[phase]
        receipt = self._effect(name, {'event': event, 'activation_intent_sha256': activation['intent_sha256'],
            'action_intent_sha256': self.states.action_intent_sha256(activation, event),
            'binding': activation['binding'], 'facts': activation['facts'],
            'false_flag_https_receipt_sha256': self.state['receipts']['https_readiness']['private_receipt_sha256']})
        evidence = receipt['result']
        need(evidence.get('private_receipt_sha256') == receipt['private_receipt_sha256'], 'ACTIVATION_PRIVATE_RECEIPT_CHANGED')
        changed = self.states.advance_activation(activation, event, evidence, compiled_gate=self.pins['gate'])
        self._finish(receipt, 'active' if changed['phase'] == 'active' else 'activation',
            activation=changed, workspace_enabled=changed['feature_flag_enabled'])

    def summary(self):
        return {'format': 1, 'kind': 'go-erp-shared-workspace-coordinator-status',
            'phase': self.state['phase'], 'pending_action': None if self.state['pending'] is None
                else self.state['pending']['action']['name'], 'workspace_enabled': self.state['workspace_enabled'],
            'automatic_updates_enabled': False, 'allocation_allowed': False,
            'artifact_replacement_allowed': False, 'published': False}


if __name__ == '__main__':
    print('GO_ERP_SHARED_WORKSPACE_COORDINATOR_FINAL_PINS_UNAVAILABLE_USE_PINNED_ROOT_LOADER')
    raise SystemExit(1)
