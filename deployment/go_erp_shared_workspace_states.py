#!/usr/bin/env python3
"""Pure migration/config/FPM checkpoint decisions for a pinned coordinator.

No I/O, SQL, subprocesses, environment reads, deployment, allocation or publishing.
The coordinator must prove locks, private-record bytes, provenance and real effects;
these functions do not turn arbitrary JSON assertions into trusted evidence.
Persist every returned intent before performing its corresponding external action.
"""
import copy
import hashlib
import json
import re

POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
COMPILED_ACTIVATION_GATE = None  # Exact final candidate/contract pins are unavailable.
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
MIGRATIONS = {
    'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php': (
        'desktop_dashboard_workspaces', 'desktop_dashboard_workspace_members',
        'desktop_dashboard_workspace_manifests'),
    'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php': (
        'desktop_dashboard_ws_command_auth',),
}
TABLES = tuple(table for names in MIGRATIONS.values() for table in names)
PRIVATE_TABLES = ('desktop_dashboard_workspace_local_authority',
    'desktop_dashboard_workspace_local_members',
    'desktop_dashboard_ws_authority_history')
FLAG = 'DESKTOP_DASHBOARD_WORKSPACE_ENABLED'
FPM_UNIT = 'ea-php82-php-fpm.service'
ORIGIN = 'https://fasakhaninja.com'


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_STATE_' + code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def same_json(value, expected):
    # Python dict equality treats True as 1; protocol records must not.
    try:
        return canonical(value) == canonical(expected)
    except (TypeError, ValueError, OverflowError):
        return False


def hash_pin(value):
    return isinstance(value, str) and bool(SHA.fullmatch(value)) and value != '0' * 64


def revision(value):
    return isinstance(value, str) and bool(REV.fullmatch(value)) and value != '0' * 40


def schema_digest(schema):
    """Hash a full normalized schema, not a table name or selected columns.

    The pinned SQL reader must preserve ordered columns/index columns and normalize
    driver-specific spellings before this function. Default values stay exact.
    """
    need(isinstance(schema, dict) and set(schema) == {'engine', 'collation', 'columns', 'indexes'}
        and schema['engine'] == 'InnoDB' and isinstance(schema['columns'], list)
        and schema['columns'] and isinstance(schema['indexes'], list)
        and isinstance(schema['collation'], str) and 0 < len(schema['collation']) <= 100, 'SCHEMA_INVALID')
    names = []
    for column in schema['columns']:
        need(isinstance(column, dict) and set(column) == {'name', 'type', 'nullable', 'default', 'extra',
                'collation', 'generation_expression'}
            and isinstance(column['name'], str) and re.fullmatch(r'[a-z][a-z0-9_]{0,63}', column['name'])
            and isinstance(column['type'], str) and 0 < len(column['type']) <= 200
            and column['type'] == column['type'].lower() and type(column['nullable']) is bool
            and (column['default'] is None or type(column['default']) in (str, int, bool))
            and isinstance(column['extra'], str)
            and (column['collation'] is None or isinstance(column['collation'], str))
            and isinstance(column['generation_expression'], str), 'COLUMN_INVALID')
        names.append(column['name'])
    need(len(names) == len(set(names)), 'COLUMN_DUPLICATE')
    indexes = []
    for index in schema['indexes']:
        need(isinstance(index, dict) and set(index) == {'name', 'unique', 'columns', 'type', 'prefixes', 'orders'}
            and isinstance(index['name'], str) and re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,100}', index['name'])
            and type(index['unique']) is bool and isinstance(index['columns'], list)
            and index['columns'] and all(name in names for name in index['columns'])
            and len(index['columns']) == len(set(index['columns']))
            and isinstance(index['prefixes'], list) and len(index['prefixes']) == len(index['columns'])
            and all(prefix is None or type(prefix) is int and prefix > 0 for prefix in index['prefixes'])
            and isinstance(index['orders'], list) and len(index['orders']) == len(index['columns'])
            and all(order in ('ASC', 'DESC') for order in index['orders'])
            and index['type'] in ('BTREE', 'HASH', 'FULLTEXT', 'SPATIAL'), 'INDEX_INVALID')
        indexes.append(index['name'])
    need(len(indexes) == len(set(indexes)) and 'PRIMARY' in indexes, 'INDEX_ROSTER_INVALID')
    return digest(schema)


def migration_intent(release, source_manifest_sha256, database_id_sha256, migration_hashes, expected_schemas):
    """Construct an immutable intent after a separately proven all-absent preflight."""
    need(revision(release) and hash_pin(source_manifest_sha256) and hash_pin(database_id_sha256),
        'MIGRATION_IDENTITY_INVALID')
    need(isinstance(migration_hashes, dict) and set(migration_hashes) == set(MIGRATIONS)
        and all(hash_pin(value) for value in migration_hashes.values())
        and isinstance(expected_schemas, dict) and set(expected_schemas) == set(TABLES),
        'MIGRATION_SCOPE_INVALID')
    schemas = {table: {'schema': copy.deepcopy(expected_schemas[table]),
        'schema_sha256': schema_digest(expected_schemas[table])} for table in TABLES}
    return {'format': 1, 'kind': 'go-erp-shared-workspace-migration-intent',
        'release': release, 'policy': POLICY, 'source_manifest_sha256': source_manifest_sha256,
        'database_id_sha256': database_id_sha256, 'migration_hashes': dict(migration_hashes),
        'expected_schemas': schemas, 'baseline_absent': list(TABLES),
        'financial_data_updates_allowed': False, 'drop_tables_allowed': False}


def checked_migration_intent(intent):
    need(isinstance(intent, dict), 'MIGRATION_INTENT_INVALID')
    schemas = intent.get('expected_schemas')
    need(isinstance(schemas, dict) and set(schemas) == set(TABLES), 'MIGRATION_INTENT_INVALID')
    try:
        expected = migration_intent(intent['release'], intent['source_manifest_sha256'],
            intent['database_id_sha256'], intent['migration_hashes'],
            {table: schemas[table]['schema'] for table in TABLES})
    except (KeyError, TypeError):
        raise Stop('GO_ERP_SHARED_WORKSPACE_STATE_MIGRATION_INTENT_INVALID') from None
    need(same_json(intent, expected), 'MIGRATION_INTENT_CHANGED')
    return digest(intent)


def table_intent(intent, table):
    """Write-ahead boundary to persist before the one reviewed CREATE action."""
    intent_sha = checked_migration_intent(intent)
    need(table in TABLES, 'TABLE_SCOPE_INVALID')
    return {'format': 1, 'kind': 'go-erp-shared-workspace-table-intent',
        'intent_sha256': intent_sha, 'database_id_sha256': intent['database_id_sha256'],
        'table': table, 'before_absent': True,
        'schema_sha256': intent['expected_schemas'][table]['schema_sha256']}


def table_receipt(intent, table, observed_schema):
    """Expected receipt shape; only a trusted locked reader can attest its facts."""
    prior = table_intent(intent, table)
    need(schema_digest(observed_schema) == prior['schema_sha256'], 'TABLE_SCHEMA_MISMATCH')
    return {'format': 1, 'kind': 'go-erp-shared-workspace-table-created',
        'table_intent_sha256': digest(prior), 'intent_sha256': prior['intent_sha256'],
        'database_id_sha256': prior['database_id_sha256'], 'table': table,
        'schema_sha256': prior['schema_sha256'], 'created_from_absence': True}


def ledger_receipt(intent, migration):
    checked_migration_intent(intent)
    need(migration in MIGRATIONS, 'MIGRATION_SCOPE_INVALID')
    return {'format': 1, 'kind': 'go-erp-shared-workspace-migration-recorded',
        'intent_sha256': digest(intent), 'database_id_sha256': intent['database_id_sha256'],
        'migration': migration, 'source_sha256': intent['migration_hashes'][migration]}


def migration_checkpoint(intent, observed_tables, journal, observed_ledger, *, persisted_intent_sha256):
    """Classify exact snapshots as absent/owned-complete/owned-partial/foreign-conflict.

    Presence plus an equal schema is never ownership. A table present after an
    interrupted CREATE without its durable completion receipt requires review.
    Unrelated tables are ignored; private native authority tables cause a conflict.
    Returned data never permits DROP, truncation, imports or business-row updates.
    """
    intent_sha = checked_migration_intent(intent)
    need(persisted_intent_sha256 == intent_sha, 'PERSISTED_MIGRATION_INTENT_CHANGED')
    need(isinstance(observed_tables, dict) and all(table in observed_tables for table in TABLES)
        and isinstance(observed_ledger, dict) and set(observed_ledger) == set(MIGRATIONS)
        and isinstance(journal, dict) and set(journal) == {'intent_sha256', 'table_intents', 'table_receipts', 'ledger_receipts'}
        and journal['intent_sha256'] == intent_sha
        and all(isinstance(journal[name], dict) for name in ('table_intents', 'table_receipts', 'ledger_receipts')),
        'MIGRATION_SNAPSHOT_INVALID')
    reasons, owned, missing = [], [], []
    if any(table in observed_tables and observed_tables[table] is not None for table in PRIVATE_TABLES):
        reasons.append('NATIVE_PRIVATE_TABLE_PRESENT')
    if not set(journal['table_intents']) <= set(TABLES) or not set(journal['table_receipts']) <= set(TABLES) \
            or not set(journal['ledger_receipts']) <= set(MIGRATIONS):
        reasons.append('JOURNAL_SCOPE_CHANGED')
    for table in TABLES:
        schema = observed_tables[table]
        prior = journal['table_intents'].get(table)
        receipt = journal['table_receipts'].get(table)
        expected_prior = table_intent(intent, table)
        if prior is not None and not same_json(prior, expected_prior):
            reasons.append('TABLE_INTENT_CHANGED:' + table)
        if schema is None:
            missing.append(table)
            if receipt is not None:
                reasons.append('OWNED_TABLE_DISAPPEARED:' + table)
            continue
        try:
            matching = schema_digest(schema) == intent['expected_schemas'][table]['schema_sha256']
        except Stop:
            matching = False
        if not matching:
            reasons.append('TABLE_SCHEMA_CHANGED:' + table)
        elif not same_json(prior, expected_prior) or not same_json(receipt, table_receipt(intent, table, schema)):
            reasons.append('PRESENT_WITHOUT_OWNED_RECEIPT:' + table)
        else:
            owned.append(table)
    completed_migrations = []
    for migration, tables in MIGRATIONS.items():
        row = observed_ledger[migration]
        receipt = journal['ledger_receipts'].get(migration)
        if row is None:
            if receipt is not None:
                reasons.append('OWNED_LEDGER_DISAPPEARED:' + migration)
        elif not same_json(row, ledger_receipt(intent, migration)) or not same_json(receipt, row) \
                or not all(table in owned for table in tables):
            reasons.append('FOREIGN_OR_INCOMPLETE_LEDGER:' + migration)
        else:
            completed_migrations.append(migration)
    # The pinned migration order is a prefix; do not silently repair reordered DDL.
    if owned != list(TABLES[:len(owned)]):
        reasons.append('TABLE_CREATION_ORDER_CHANGED')
    if completed_migrations != list(MIGRATIONS)[:len(completed_migrations)]:
        reasons.append('MIGRATION_LEDGER_ORDER_CHANGED')
    if reasons:
        state = 'foreign-conflict'
    elif not owned and not completed_migrations:
        state = 'absent'
    elif len(owned) == len(TABLES) and len(completed_migrations) == len(MIGRATIONS):
        state = 'owned-complete'
    else:
        state = 'owned-partial'
    return {'format': 1, 'kind': 'go-erp-shared-workspace-migration-checkpoint',
        'intent_sha256': intent_sha, 'state': state, 'owned_tables': owned,
        'missing_tables': missing, 'completed_migrations': completed_migrations,
        'reasons': reasons, 'resume_requires_owned_evidence': True,
        'drop_tables_allowed': False, 'financial_data_updates_allowed': False,
        'activation_allowed': False}


RELEASE_FIELDS = ('candidate_revision', 'web_release', 'native_core', 'seed')
BINDING_HASH_FIELDS = ('source_manifest_sha256', 'migration_receipt_sha256', 'contract_sha256',
    'env_before_sha256', 'env_enabled_sha256', 'env_unchanged_bytes_sha256',
    'php_handler_configuration_sha256')
GATE_FIELDS = RELEASE_FIELDS + ('policy', 'source_manifest_sha256', 'contract_sha256')
GATE_TRUE_FIELDS = ('latest_required_ci_accepted', 'full_native_lifecycle_accepted',
    'installed_asar_accepted', 'original_program_gate_accepted', 'employee_acceptance_accepted')
PHASES = ('prepared', 'gates-accepted', 'handler-verified', 'flag-write-pending',
    'flag-written', 'cache-build-pending', 'cache-built', 'route-cache-build-pending',
    'cache-ready', 'fpm-reload-pending', 'fpm-ready', 'active',
    'rollback-flag-pending', 'rollback-flag-restored', 'rollback-cache-pending',
    'rollback-cache-ready', 'rollback-fpm-pending', 'rolled-back', 'review-required')


def activation_intent(binding):
    """Make the first durable intent; final release gates remain unavailable by default."""
    need(isinstance(binding, dict)
        and set(binding) == set(RELEASE_FIELDS + BINDING_HASH_FIELDS + ('policy', 'route_cache_required'))
        and all(revision(binding[name]) for name in RELEASE_FIELDS)
        and all(hash_pin(binding[name]) for name in BINDING_HASH_FIELDS)
        and binding['policy'] == POLICY and type(binding['route_cache_required']) is bool
        and binding['env_before_sha256'] != binding['env_enabled_sha256'], 'ACTIVATION_BINDING_INVALID')
    result = {'format': 1, 'kind': 'go-erp-shared-workspace-activation-intent',
        'binding': copy.deepcopy(binding), 'flag': FLAG, 'fpm_unit': FPM_UNIT, 'origin': ORIGIN,
        'phase': 'prepared', 'feature_flag_enabled': False, 'gate_sha256': None,
        'facts': {'cache_sha256': None, 'route_cache_sha256': None},
        'history': [], 'review_reason': None, 'automatic_updates_enabled': False}
    result['intent_sha256'] = digest({key: value for key, value in result.items()
        if key not in ('phase', 'feature_flag_enabled', 'gate_sha256', 'facts', 'history', 'review_reason')})
    return result


def _checked_activation(state):
    need(isinstance(state, dict) and isinstance(state.get('binding'), dict), 'ACTIVATION_STATE_INVALID')
    initial = activation_intent(state['binding'])
    need(set(state) == set(initial) and state.get('phase') in PHASES
        and state.get('format') == initial['format'] and type(state.get('format')) is int
        and state.get('kind') == initial['kind']
        and state.get('intent_sha256') == initial['intent_sha256']
        and state.get('flag') == FLAG and state.get('fpm_unit') == FPM_UNIT
        and state.get('origin') == ORIGIN and state.get('automatic_updates_enabled') is False
        and isinstance(state.get('history'), list) and isinstance(state.get('facts'), dict)
        and set(state['facts']) == {'cache_sha256', 'route_cache_sha256'}
        and all(value is None or hash_pin(value) for value in state['facts'].values())
        and (state.get('feature_flag_enabled') is None or type(state['feature_flag_enabled']) is bool),
        'ACTIVATION_STATE_CHANGED')
    previous, phase = state['intent_sha256'], 'prepared'
    for index, record in enumerate(state['history']):
        need(isinstance(record, dict) and set(record) == {'sequence', 'event', 'proof_sha256', 'previous_sha256',
                'phase_before', 'phase_after', 'feature_flag_enabled_after', 'gate_sha256_after', 'facts_sha256',
                'review_reason_after', 'record_sha256'}
            and type(record['sequence']) is int and record['sequence'] == index + 1
            and isinstance(record['event'], str) and hash_pin(record['proof_sha256'])
            and record['previous_sha256'] == previous
            and record['phase_before'] == phase and record['phase_after'] in PHASES
            and record['record_sha256'] == digest({key: value for key, value in record.items() if key != 'record_sha256'}),
            'ACTIVATION_HISTORY_CHANGED')
        previous = record['record_sha256']
        phase = record['phase_after']
    if state['history']:
        last = state['history'][-1]
        need(state['phase'] == last['phase_after']
            and state['feature_flag_enabled'] is last['feature_flag_enabled_after']
            and state['gate_sha256'] == last['gate_sha256_after']
            and digest(state['facts']) == last['facts_sha256']
            and state['review_reason'] == last['review_reason_after'], 'ACTIVATION_CHECKPOINT_CHANGED')
    else:
        need(state == initial, 'ACTIVATION_INITIAL_CHECKPOINT_CHANGED')
    return initial


def action_intent_sha256(state, event):
    """Bind an external operation's private receipt to a durable pending state."""
    _checked_activation(state)
    return digest({'intent_sha256': state['intent_sha256'], 'phase': state['phase'],
        'event': event, 'previous_sha256': state['history'][-1]['record_sha256']
        if state['history'] else state['intent_sha256']})


def _gate(state, compiled_gate):
    gate = COMPILED_ACTIVATION_GATE if compiled_gate is None else compiled_gate
    need(isinstance(gate, dict) and set(gate) == set(GATE_FIELDS)
        and all(gate[name] == state['binding'][name] for name in GATE_FIELDS),
        'FINAL_COMPILED_GATE_UNAVAILABLE_OR_CHANGED')
    return digest(gate)


def _proof(state, evidence, event):
    need(isinstance(evidence, dict) and evidence.get('intent_sha256') == state['intent_sha256']
        and evidence.get('action_intent_sha256') == action_intent_sha256(state, event)
        and hash_pin(evidence.get('private_receipt_sha256')), 'ACTION_PROOF_BINDING_INVALID')


def _owned_env(state, evidence, enabled):
    binding = state['binding']
    need(evidence.get('flag') == FLAG
        and evidence.get('before_sha256') == binding['env_before_sha256']
        and evidence.get('enabled_sha256') == binding['env_enabled_sha256']
        and evidence.get('unchanged_bytes_sha256') == binding['env_unchanged_bytes_sha256']
        and evidence.get('current_sha256') == binding['env_enabled_sha256' if enabled else 'env_before_sha256']
        and evidence.get('only_workspace_flag_changed') is True
        and evidence.get('owned_inode_verified') is True, 'ENV_OWNERSHIP_PROOF_INVALID')


def _cache_proof(state, evidence, enabled):
    need(hash_pin(evidence.get('cache_sha256')) and evidence.get('workspace_enabled') is enabled
        and evidence.get('local') is False and evidence.get('env_sha256') == state['binding'][
            'env_enabled_sha256' if enabled else 'env_before_sha256']
        and evidence.get('source_manifest_sha256') == state['binding']['source_manifest_sha256']
        and evidence.get('owned_cache_verified') is True, 'CACHE_PROOF_INVALID')


def _fpm_proof(state, evidence):
    need(evidence.get('unit') == FPM_UNIT and evidence.get('active') is True
        and evidence.get('handler_before_sha256') == evidence.get('handler_after_sha256')
            == state['binding']['php_handler_configuration_sha256']
        and evidence.get('reload_completed') is True
        and evidence.get('cache_sha256') == state['facts']['cache_sha256']
        and hash_pin(evidence.get('cache_sha256'))
        and evidence.get('route_cache_sha256') == state['facts']['route_cache_sha256'], 'FPM_PROOF_INVALID')


def advance_activation(state, event, evidence=None, *, compiled_gate=None):
    """Return a new durable checkpoint, preserving supplied state and evidence.

    `compiled_gate` is a coordinator's code-pinned constant, never a request value.
    None keeps final pins unavailable. Proofs must originate from trusted locked
    effects/readers, not web input. begin_* returns must be persisted before I/O.
    Replaying a completion after a crash uses the same pending checkpoint/proof.
    Unknown environment ownership enters review without authorizing rollback.
    """
    _checked_activation(state)
    need(isinstance(event, str) and event, 'ACTIVATION_EVENT_INVALID')
    evidence = {} if evidence is None else evidence
    need(isinstance(evidence, dict), 'ACTIVATION_EVIDENCE_INVALID')
    result = copy.deepcopy(state)
    phase, binding = state['phase'], state['binding']
    if event == 'accept_gates':
        need(phase == 'prepared', 'ACTIVATION_PHASE_INVALID')
        gate_sha = _gate(state, compiled_gate)
        need(set(evidence) == set(GATE_FIELDS + GATE_TRUE_FIELDS + ('private_receipt_sha256',))
            and all(evidence.get(name) == binding[name] for name in GATE_FIELDS)
            and all(evidence.get(name) is True for name in GATE_TRUE_FIELDS)
            and hash_pin(evidence.get('private_receipt_sha256')), 'FULL_ACCEPTANCE_REQUIRED')
        result.update(phase='gates-accepted', gate_sha256=gate_sha)
    elif event == 'begin_rollback':
        need(phase not in ('prepared', 'rolled-back', 'review-required') and not phase.startswith('rollback-'),
            'ACTIVATION_PHASE_INVALID')
        _proof(state, evidence, event)
        if evidence.get('current_sha256') not in (binding['env_before_sha256'], binding['env_enabled_sha256']) \
                or evidence.get('owned_inode_verified') is not True \
                or evidence.get('unchanged_bytes_sha256') != binding['env_unchanged_bytes_sha256']:
            result.update(phase='review-required', feature_flag_enabled=None,
                review_reason='UNKNOWN_ENVIRONMENT_OWNERSHIP')
        else:
            result.update(phase='rollback-flag-pending',
                feature_flag_enabled=evidence['current_sha256'] == binding['env_enabled_sha256'])
    elif event.startswith('rollback_'):
        _proof(state, evidence, event)
        if event == 'rollback_flag_restored':
            need(phase == 'rollback-flag-pending', 'ACTIVATION_PHASE_INVALID')
            _owned_env(state, evidence, False)
            result.update(phase='rollback-flag-restored', feature_flag_enabled=False)
        elif event == 'rollback_begin_cache':
            need(phase == 'rollback-flag-restored' and evidence.get('target') == 'config-cache-only'
                and not evidence.get('mutate_unrelated_cache'),
                'ACTIVATION_PHASE_INVALID')
            result['phase'] = 'rollback-cache-pending'
        elif event == 'rollback_cache_built':
            need(phase == 'rollback-cache-pending', 'ACTIVATION_PHASE_INVALID')
            _cache_proof(state, evidence, False)
            need(evidence.get('route_cache_policy_verified') is True, 'ROUTE_CACHE_POLICY_REQUIRED')
            need(evidence.get('route_cache_sha256') is None or hash_pin(evidence['route_cache_sha256']),
                'ROUTE_CACHE_PROOF_INVALID')
            need(not binding['route_cache_required'] or hash_pin(evidence.get('route_cache_sha256')),
                'ROUTE_CACHE_POLICY_REQUIRED')
            result['facts'].update(cache_sha256=evidence['cache_sha256'],
                route_cache_sha256=evidence.get('route_cache_sha256'))
            result['phase'] = 'rollback-cache-ready'
        elif event == 'rollback_begin_fpm':
            need(phase == 'rollback-cache-ready', 'ACTIVATION_PHASE_INVALID')
            result['phase'] = 'rollback-fpm-pending'
        elif event == 'rollback_fpm_reloaded':
            need(phase == 'rollback-fpm-pending', 'ACTIVATION_PHASE_INVALID')
            _fpm_proof(state, evidence)
            result.update(phase='rolled-back', feature_flag_enabled=False)
        else:
            raise Stop('GO_ERP_SHARED_WORKSPACE_STATE_ACTIVATION_EVENT_INVALID')
    else:
        need(state['gate_sha256'] == _gate(state, compiled_gate), 'FINAL_COMPILED_GATE_CHANGED')
        _proof(state, evidence, event)
        if event == 'handler_verified':
            need(phase == 'gates-accepted'
                and evidence.get('configuration_sha256') == binding['php_handler_configuration_sha256'],
                'HANDLER_PROOF_INVALID')
            result['phase'] = 'handler-verified'
        elif event == 'begin_flag_write':
            need(phase == 'handler-verified' and evidence.get('current_sha256') == binding['env_before_sha256']
                and evidence.get('owned_inode_verified') is True
                and evidence.get('unchanged_bytes_sha256') == binding['env_unchanged_bytes_sha256'],
                'ACTIVATION_PHASE_INVALID')
            result['phase'] = 'flag-write-pending'
        elif event == 'flag_written':
            need(phase == 'flag-write-pending', 'ACTIVATION_PHASE_INVALID')
            _owned_env(state, evidence, True)
            result.update(phase='flag-written', feature_flag_enabled=True)
        elif event == 'begin_cache_build':
            need(phase == 'flag-written' and evidence.get('target') == 'config-cache-only',
                'ACTIVATION_PHASE_INVALID')
            result['phase'] = 'cache-build-pending'
        elif event == 'cache_built':
            need(phase == 'cache-build-pending', 'ACTIVATION_PHASE_INVALID')
            _cache_proof(state, evidence, True)
            result['facts']['cache_sha256'] = evidence['cache_sha256']
            result['phase'] = 'cache-built' if binding['route_cache_required'] else 'cache-ready'
        elif event == 'begin_route_cache_build':
            need(phase == 'cache-built' and binding['route_cache_required'] is True,
                'ACTIVATION_PHASE_INVALID')
            result['phase'] = 'route-cache-build-pending'
        elif event == 'route_cache_built':
            need(phase == 'route-cache-build-pending' and hash_pin(evidence.get('route_cache_sha256'))
                and evidence.get('workspace_routes_verified') is True
                and evidence.get('source_manifest_sha256') == binding['source_manifest_sha256'],
                'ROUTE_CACHE_PROOF_INVALID')
            result['phase'] = 'cache-ready'
            result['facts']['route_cache_sha256'] = evidence['route_cache_sha256']
        elif event == 'begin_fpm_reload':
            need(phase == 'cache-ready' and evidence.get('unit') == FPM_UNIT, 'ACTIVATION_PHASE_INVALID')
            result['phase'] = 'fpm-reload-pending'
        elif event == 'fpm_reloaded':
            need(phase == 'fpm-reload-pending', 'ACTIVATION_PHASE_INVALID')
            _fpm_proof(state, evidence)
            result['phase'] = 'fpm-ready'
        elif event == 'https_verified':
            need(phase == 'fpm-ready' and evidence.get('origin') == ORIGIN
                and evidence.get('workspace_enabled') is True and evidence.get('local') is False
                and evidence.get('source_manifest_sha256') == binding['source_manifest_sha256']
                and evidence.get('cache_sha256') == state['facts']['cache_sha256']
                and evidence.get('php_handler_configuration_sha256') == binding['php_handler_configuration_sha256']
                and evidence.get('served_configuration_verified') is True,
                'HTTPS_PROOF_INVALID')
            result['phase'] = 'active'
        else:
            raise Stop('GO_ERP_SHARED_WORKSPACE_STATE_ACTIVATION_EVENT_INVALID')
    record = {'sequence': len(state['history']) + 1, 'event': event,
        'proof_sha256': digest(evidence), 'previous_sha256': state['history'][-1]['record_sha256']
        if state['history'] else state['intent_sha256'],
        'phase_before': phase, 'phase_after': result['phase'],
        'feature_flag_enabled_after': result['feature_flag_enabled'],
        'gate_sha256_after': result['gate_sha256'], 'facts_sha256': digest(result['facts']),
        'review_reason_after': result['review_reason']}
    record['record_sha256'] = digest(record)
    result['history'].append(record)
    return result
