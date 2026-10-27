"""Pure schema/activation checkpoint tests; no server, SQL, file or service effects."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('shared_workspace_states',
    ROOT / 'deployment/go_erp_shared_workspace_states.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)


def schema():
    return {'engine': 'InnoDB', 'collation': 'utf8mb4_unicode_ci', 'columns': [
        {'name': 'id', 'type': 'char(36)', 'nullable': False, 'default': None, 'extra': '',
            'collation': 'utf8mb4_unicode_ci', 'generation_expression': ''},
        {'name': 'enabled', 'type': 'tinyint(1)', 'nullable': False, 'default': '1', 'extra': '',
            'collation': None, 'generation_expression': ''}],
        'indexes': [{'name': 'PRIMARY', 'unique': True, 'columns': ['id'], 'type': 'BTREE',
            'prefixes': [None], 'orders': ['ASC']}]}


def migrations():
    intent = subject.migration_intent('a' * 40, 'b' * 64, 'c' * 64,
        {name: str(index + 1) * 64 for index, name in enumerate(subject.MIGRATIONS)},
        {table: schema() for table in subject.TABLES})
    observed = {table: None for table in subject.TABLES}
    journal = {'intent_sha256': subject.digest(intent), 'table_intents': {},
        'table_receipts': {}, 'ledger_receipts': {}}
    ledger = {migration: None for migration in subject.MIGRATIONS}
    return intent, observed, journal, ledger


def classify(intent, observed, journal, ledger):
    return subject.migration_checkpoint(intent, observed, journal, ledger,
        persisted_intent_sha256=subject.digest(intent))


def own_table(intent, observed, journal, table):
    journal['table_intents'][table] = subject.table_intent(intent, table)
    observed[table] = schema()
    journal['table_receipts'][table] = subject.table_receipt(intent, table, observed[table])


def own_all(intent, observed, journal, ledger):
    for table in subject.TABLES:
        own_table(intent, observed, journal, table)
    for migration in subject.MIGRATIONS:
        row = subject.ledger_receipt(intent, migration)
        ledger[migration] = row
        journal['ledger_receipts'][migration] = copy.deepcopy(row)


class MigrationTests(unittest.TestCase):
    def test_all_absent_and_input_purity(self):
        values = migrations()
        before = copy.deepcopy(values)
        result = classify(*values)
        self.assertEqual(result['state'], 'absent')
        self.assertFalse(result['drop_tables_allowed'])
        self.assertFalse(result['financial_data_updates_allowed'])
        self.assertFalse(result['activation_allowed'])
        self.assertEqual(values, before)

    def test_crash_with_only_durable_table_intent_does_not_claim_table_created(self):
        intent, observed, journal, ledger = migrations()
        table = subject.TABLES[0]
        journal['table_intents'][table] = subject.table_intent(intent, table)
        self.assertEqual(classify(intent, observed, journal, ledger)['state'], 'absent')
        observed[table] = schema()
        result = classify(intent, observed, journal, ledger)
        self.assertEqual(result['state'], 'foreign-conflict')
        self.assertIn('PRESENT_WITHOUT_OWNED_RECEIPT:' + table, result['reasons'])
        self.assertFalse(result['drop_tables_allowed'])

    def test_receipted_partial_ddl_resumes_at_owned_checkpoint(self):
        intent, observed, journal, ledger = migrations()
        own_table(intent, observed, journal, subject.TABLES[0])
        first = classify(intent, observed, journal, ledger)
        self.assertEqual(first['state'], 'owned-partial')
        self.assertEqual(first['owned_tables'], [subject.TABLES[0]])
        own_table(intent, observed, journal, subject.TABLES[1])
        self.assertEqual(classify(intent, observed, journal, ledger)['owned_tables'], list(subject.TABLES[:2]))
        own_table(intent, observed, journal, subject.TABLES[2])
        migration = next(iter(subject.MIGRATIONS))
        row = subject.ledger_receipt(intent, migration)
        ledger[migration] = row
        journal['ledger_receipts'][migration] = copy.deepcopy(row)
        self.assertEqual(classify(intent, observed, journal, ledger)['completed_migrations'], [migration])

    def test_exact_owned_all_tables_and_both_ledger_receipts_complete(self):
        values = migrations()
        own_all(*values)
        result = classify(*values)
        self.assertEqual(result['state'], 'owned-complete')
        self.assertEqual(result['owned_tables'], list(subject.TABLES))
        self.assertFalse(result['activation_allowed'])

    def test_all_tables_without_complete_ledger_is_partial(self):
        intent, observed, journal, ledger = migrations()
        for table in subject.TABLES:
            own_table(intent, observed, journal, table)
        self.assertEqual(classify(intent, observed, journal, ledger)['state'], 'owned-partial')

    def test_matching_preexisting_table_without_prior_receipt_is_foreign(self):
        intent, observed, journal, ledger = migrations()
        observed[subject.TABLES[0]] = schema()
        self.assertEqual(classify(intent, observed, journal, ledger)['state'], 'foreign-conflict')

    def test_wrong_database_source_intent_or_table_receipt_never_claims_ownership(self):
        for key in ('database_id_sha256', 'schema_sha256', 'intent_sha256', 'table_intent_sha256'):
            intent, observed, journal, ledger = migrations()
            table = subject.TABLES[0]
            own_table(intent, observed, journal, table)
            journal['table_receipts'][table][key] = 'd' * 64
            with self.subTest(key=key):
                self.assertEqual(classify(intent, observed, journal, ledger)['state'], 'foreign-conflict')

    def test_schema_shape_or_order_drift_is_conflict(self):
        for mutate in (lambda s: s.update(engine='MyISAM'),
                lambda s: s['columns'][1].update(nullable=True),
                lambda s: s['columns'][1].update(default='0'),
                lambda s: s['columns'][0].update(collation='utf8mb4_bin'),
                lambda s: s['columns'][1].update(generation_expression='1 + 1'),
                lambda s: s['columns'].reverse(),
                lambda s: s['indexes'][0].update(prefixes=[10]),
                lambda s: s['indexes'][0].update(orders=['DESC']),
                lambda s: s['indexes'][0].update(unique=False)):
            intent, observed, journal, ledger = migrations()
            own_table(intent, observed, journal, subject.TABLES[0])
            mutate(observed[subject.TABLES[0]])
            with self.subTest(mutate=mutate):
                self.assertEqual(classify(intent, observed, journal, ledger)['state'], 'foreign-conflict')

    def test_disappeared_owned_table_or_ledger_is_conflict_not_recreate(self):
        values = migrations()
        intent, observed, journal, ledger = values
        own_all(*values)
        observed[subject.TABLES[0]] = None
        self.assertEqual(classify(*values)['state'], 'foreign-conflict')
        observed[subject.TABLES[0]] = schema()
        ledger[next(iter(subject.MIGRATIONS))] = None
        self.assertEqual(classify(*values)['state'], 'foreign-conflict')

    def test_native_private_table_or_foreign_ledger_is_conflict(self):
        values = migrations()
        intent, observed, journal, ledger = values
        observed[subject.PRIVATE_TABLES[0]] = schema()
        result = classify(*values)
        self.assertIn('NATIVE_PRIVATE_TABLE_PRESENT', result['reasons'])
        del observed[subject.PRIVATE_TABLES[0]]
        ledger[next(iter(subject.MIGRATIONS))] = {'migration': 'foreign', 'batch': 1}
        self.assertEqual(classify(*values)['state'], 'foreign-conflict')

    def test_unrelated_tables_are_preserved_and_never_returned_for_drop(self):
        values = migrations()
        values[1]['wallets'] = {'private': 'unrelated financial table'}
        before = copy.deepcopy(values)
        result = classify(*values)
        self.assertEqual(values, before)
        self.assertEqual(result['state'], 'absent')
        self.assertNotIn('wallets', result['missing_tables'])
        self.assertNotIn('wallets', result['owned_tables'])
        self.assertFalse(result['drop_tables_allowed'])

    def test_unknown_or_reordered_owned_table_is_conflict(self):
        intent, observed, journal, ledger = migrations()
        own_table(intent, observed, journal, subject.TABLES[-1])
        self.assertIn('TABLE_CREATION_ORDER_CHANGED', classify(intent, observed, journal, ledger)['reasons'])
        journal['table_intents']['wallets'] = {'before_absent': True}
        self.assertIn('JOURNAL_SCOPE_CHANGED', classify(intent, observed, journal, ledger)['reasons'])

    def test_changed_persisted_intent_or_schema_pin_is_rejected(self):
        values = migrations()
        with self.assertRaisesRegex(subject.Stop, 'PERSISTED_MIGRATION_INTENT_CHANGED'):
            subject.migration_checkpoint(*values, persisted_intent_sha256='d' * 64)
        values[0]['expected_schemas'][subject.TABLES[0]]['schema_sha256'] = 'e' * 64
        with self.assertRaisesRegex(subject.Stop, 'MIGRATION_INTENT_CHANGED'):
            classify(*values)

    def test_boolean_integer_substitution_cannot_forge_owned_receipts(self):
        values = migrations()
        intent, observed, journal, ledger = values
        own_all(*values)
        journal['table_receipts'][subject.TABLES[0]]['created_from_absence'] = 1
        self.assertEqual(classify(*values)['state'], 'foreign-conflict')
        intent['format'] = True
        with self.assertRaisesRegex(subject.Stop, 'MIGRATION_INTENT_CHANGED'):
            classify(*values)


def binding(route_cache=False):
    result = {name: str(index + 1) * 40 for index, name in enumerate(subject.RELEASE_FIELDS)}
    result.update({name: chr(ord('a') + index) * 64 for index, name in enumerate(subject.BINDING_HASH_FIELDS)})
    # All test hash characters must be hexadecimal.
    result['php_handler_configuration_sha256'] = '8' * 64
    result.update(policy=subject.POLICY, route_cache_required=route_cache)
    return result


def gate(state):
    return {name: state['binding'][name] for name in subject.GATE_FIELDS}


def gate_evidence(state):
    return {**gate(state), **{name: True for name in subject.GATE_TRUE_FIELDS},
        'private_receipt_sha256': '9' * 64}


def proof(state, event, **values):
    return {'intent_sha256': state['intent_sha256'],
        'action_intent_sha256': subject.action_intent_sha256(state, event),
        'private_receipt_sha256': '9' * 64, **values}


def step(state, event, **values):
    return subject.advance_activation(state, event, proof(state, event, **values), compiled_gate=gate(state))


def env_values(state, enabled=True):
    b = state['binding']
    return {'flag': subject.FLAG, 'before_sha256': b['env_before_sha256'],
        'enabled_sha256': b['env_enabled_sha256'], 'unchanged_bytes_sha256': b['env_unchanged_bytes_sha256'],
        'current_sha256': b['env_enabled_sha256' if enabled else 'env_before_sha256'],
        'only_workspace_flag_changed': True, 'owned_inode_verified': True}


def cache_values(state, enabled=True):
    b = state['binding']
    return {'cache_sha256': '6' * 64, 'workspace_enabled': enabled, 'local': False,
        'env_sha256': b['env_enabled_sha256' if enabled else 'env_before_sha256'],
        'source_manifest_sha256': b['source_manifest_sha256'], 'owned_cache_verified': True}


def fpm_values(state):
    return {'unit': subject.FPM_UNIT, 'active': True, 'reload_completed': True,
        'handler_before_sha256': state['binding']['php_handler_configuration_sha256'],
        'handler_after_sha256': state['binding']['php_handler_configuration_sha256'],
        'cache_sha256': state['facts']['cache_sha256'],
        'route_cache_sha256': state['facts']['route_cache_sha256']}


def accepted(state):
    return subject.advance_activation(state, 'accept_gates', gate_evidence(state), compiled_gate=gate(state))


def flag_written(state):
    state = accepted(state)
    state = step(state, 'handler_verified', configuration_sha256=state['binding']['php_handler_configuration_sha256'])
    state = step(state, 'begin_flag_write', **env_values(state, False))
    return step(state, 'flag_written', **env_values(state, True))


def cache_ready(state):
    state = flag_written(state)
    state = step(state, 'begin_cache_build', target='config-cache-only')
    state = step(state, 'cache_built', **cache_values(state))
    if state['binding']['route_cache_required']:
        state = step(state, 'begin_route_cache_build')
        state = step(state, 'route_cache_built', route_cache_sha256='7' * 64,
            workspace_routes_verified=True, source_manifest_sha256=state['binding']['source_manifest_sha256'])
    return state


class ActivationTests(unittest.TestCase):
    def test_unknown_final_gate_default_cannot_enable_flag(self):
        state = subject.activation_intent(binding())
        self.assertIsNone(subject.COMPILED_ACTIVATION_GATE)
        self.assertFalse(state['feature_flag_enabled'])
        with self.assertRaisesRegex(subject.Stop, 'FINAL_COMPILED_GATE_UNAVAILABLE'):
            subject.advance_activation(state, 'accept_gates', gate_evidence(state))
        with self.assertRaises(subject.Stop):
            step(state, 'begin_flag_write', **env_values(state, False))

    def test_every_original_and_full_lifecycle_gate_must_be_true(self):
        for name in subject.GATE_TRUE_FIELDS:
            state = subject.activation_intent(binding())
            evidence = gate_evidence(state)
            evidence[name] = False
            with self.subTest(name=name), self.assertRaisesRegex(subject.Stop, 'FULL_ACCEPTANCE_REQUIRED'):
                subject.advance_activation(state, 'accept_gates', evidence, compiled_gate=gate(state))

    def test_wrong_candidate_or_stale_contract_cannot_activate(self):
        state = subject.activation_intent(binding())
        for name in subject.GATE_FIELDS:
            wrong = gate(state)
            wrong[name] = 'f' * (40 if name in subject.RELEASE_FIELDS else 64)
            with self.subTest(name=name), self.assertRaises(subject.Stop):
                subject.advance_activation(state, 'accept_gates', gate_evidence(state), compiled_gate=wrong)

    def test_durable_flag_pending_is_false_and_replays_owned_completion_after_crash(self):
        state = accepted(subject.activation_intent(binding()))
        state = step(state, 'handler_verified', configuration_sha256=state['binding']['php_handler_configuration_sha256'])
        pending = step(state, 'begin_flag_write', **env_values(state, False))
        self.assertEqual(pending['phase'], 'flag-write-pending')
        self.assertFalse(pending['feature_flag_enabled'])
        before = copy.deepcopy(pending)
        evidence = proof(pending, 'flag_written', **env_values(pending))
        first = subject.advance_activation(pending, 'flag_written', evidence, compiled_gate=gate(pending))
        replay = subject.advance_activation(pending, 'flag_written', evidence, compiled_gate=gate(pending))
        self.assertEqual(first, replay)
        self.assertEqual(pending, before)
        self.assertTrue(first['feature_flag_enabled'])
        with self.assertRaises(subject.Stop):
            subject.advance_activation(first, 'flag_written', evidence, compiled_gate=gate(first))

    def test_flag_proof_must_preserve_unrelated_env_bytes_and_owned_inode(self):
        state = accepted(subject.activation_intent(binding()))
        state = step(state, 'handler_verified', configuration_sha256=state['binding']['php_handler_configuration_sha256'])
        state = step(state, 'begin_flag_write', **env_values(state, False))
        for key, value in (('only_workspace_flag_changed', False), ('owned_inode_verified', False),
                ('unchanged_bytes_sha256', '0' * 64), ('current_sha256', 'f' * 64)):
            values = env_values(state)
            values[key] = value
            with self.subTest(key=key), self.assertRaises(subject.Stop):
                step(state, 'flag_written', **values)

    def test_cache_and_fpm_are_separate_durable_pending_actions(self):
        state = flag_written(subject.activation_intent(binding()))
        state = step(state, 'begin_cache_build', target='config-cache-only')
        self.assertEqual(state['phase'], 'cache-build-pending')
        state = step(state, 'cache_built', **cache_values(state))
        state = step(state, 'begin_fpm_reload', unit=subject.FPM_UNIT)
        self.assertEqual(state['phase'], 'fpm-reload-pending')
        self.assertNotEqual(state['phase'], 'active')
        state = step(state, 'fpm_reloaded', **fpm_values(state))
        self.assertEqual(state['phase'], 'fpm-ready')

    def test_valid_config_cache_route_cache_fpm_https_progression(self):
        for route_cache in (False, True):
            state = cache_ready(subject.activation_intent(binding(route_cache)))
            self.assertEqual(state['phase'], 'cache-ready')
            self.assertEqual(state['facts']['route_cache_sha256'], '7' * 64 if route_cache else None)
            state = step(state, 'begin_fpm_reload', unit=subject.FPM_UNIT)
            state = step(state, 'fpm_reloaded', **fpm_values(state))
            state = step(state, 'https_verified', origin=subject.ORIGIN, workspace_enabled=True,
                local=False, source_manifest_sha256=state['binding']['source_manifest_sha256'],
                cache_sha256=state['facts']['cache_sha256'], served_configuration_verified=True,
                php_handler_configuration_sha256=state['binding']['php_handler_configuration_sha256'])
            self.assertEqual(state['phase'], 'active')
            self.assertTrue(state['feature_flag_enabled'])
            self.assertFalse(state['automatic_updates_enabled'])

    def test_fpm_cannot_precede_required_route_cache_and_stale_cache_is_denied(self):
        state = flag_written(subject.activation_intent(binding(True)))
        state = step(state, 'begin_cache_build', target='config-cache-only')
        state = step(state, 'cache_built', **cache_values(state))
        with self.assertRaises(subject.Stop):
            step(state, 'begin_fpm_reload', unit=subject.FPM_UNIT)
        state = step(state, 'begin_route_cache_build')
        state = step(state, 'route_cache_built', route_cache_sha256='7' * 64,
            workspace_routes_verified=True, source_manifest_sha256=state['binding']['source_manifest_sha256'])
        state = step(state, 'begin_fpm_reload', unit=subject.FPM_UNIT)
        values = fpm_values(state)
        values['cache_sha256'] = 'f' * 64
        with self.assertRaisesRegex(subject.Stop, 'FPM_PROOF_INVALID'):
            step(state, 'fpm_reloaded', **values)

    def test_local_config_handler_drift_and_wrong_origin_fail(self):
        state = flag_written(subject.activation_intent(binding()))
        state = step(state, 'begin_cache_build', target='config-cache-only')
        values = cache_values(state)
        values['local'] = True
        with self.assertRaisesRegex(subject.Stop, 'CACHE_PROOF_INVALID'):
            step(state, 'cache_built', **values)
        state = step(state, 'cache_built', **cache_values(state))
        state = step(state, 'begin_fpm_reload', unit=subject.FPM_UNIT)
        values = fpm_values(state)
        values['handler_after_sha256'] = 'f' * 64
        with self.assertRaisesRegex(subject.Stop, 'FPM_PROOF_INVALID'):
            step(state, 'fpm_reloaded', **values)
        state = step(state, 'fpm_reloaded', **fpm_values(state))
        with self.assertRaisesRegex(subject.Stop, 'HTTPS_PROOF_INVALID'):
            step(state, 'https_verified', origin='https://other.example.invalid', workspace_enabled=True,
                local=False, source_manifest_sha256=state['binding']['source_manifest_sha256'],
                cache_sha256=state['facts']['cache_sha256'], served_configuration_verified=True,
                php_handler_configuration_sha256=state['binding']['php_handler_configuration_sha256'])

    def test_stale_action_proof_and_changed_durable_checkpoint_are_rejected(self):
        state = accepted(subject.activation_intent(binding()))
        evidence = proof(state, 'handler_verified', configuration_sha256=state['binding']['php_handler_configuration_sha256'])
        newer = subject.advance_activation(state, 'handler_verified', evidence, compiled_gate=gate(state))
        with self.assertRaisesRegex(subject.Stop, 'ACTION_PROOF_BINDING_INVALID'):
            subject.advance_activation(newer, 'begin_flag_write', evidence, compiled_gate=gate(newer))
        changed = copy.deepcopy(newer)
        changed['phase'] = 'flag-written'
        with self.assertRaisesRegex(subject.Stop, 'ACTIVATION_CHECKPOINT_CHANGED'):
            subject.action_intent_sha256(changed, 'begin_cache_build')
        changed = copy.deepcopy(newer)
        changed['history'][0]['proof_sha256'] = 'f' * 64
        with self.assertRaisesRegex(subject.Stop, 'ACTIVATION_HISTORY_CHANGED'):
            subject.action_intent_sha256(changed, 'begin_flag_write')

    def test_owned_rollback_can_disable_after_final_gate_becomes_unavailable(self):
        state = cache_ready(subject.activation_intent(binding(True)))
        before_binding = copy.deepcopy(state['binding'])
        state = subject.advance_activation(state, 'begin_rollback', proof(state, 'begin_rollback', **env_values(state)))
        self.assertEqual(state['phase'], 'rollback-flag-pending')
        state = subject.advance_activation(state, 'rollback_flag_restored',
            proof(state, 'rollback_flag_restored', **env_values(state, False)))
        state = subject.advance_activation(state, 'rollback_begin_cache',
            proof(state, 'rollback_begin_cache', target='config-cache-only'))
        values = {**cache_values(state, False), 'route_cache_policy_verified': True, 'route_cache_sha256': '5' * 64}
        state = subject.advance_activation(state, 'rollback_cache_built', proof(state, 'rollback_cache_built', **values))
        state = subject.advance_activation(state, 'rollback_begin_fpm', proof(state, 'rollback_begin_fpm'))
        state = subject.advance_activation(state, 'rollback_fpm_reloaded', proof(state, 'rollback_fpm_reloaded', **fpm_values(state)))
        self.assertEqual(state['phase'], 'rolled-back')
        self.assertFalse(state['feature_flag_enabled'])
        self.assertEqual(state['binding'], before_binding)
        self.assertFalse(state['automatic_updates_enabled'])

    def test_unknown_env_ownership_requires_review_without_restoring_foreign_bytes(self):
        state = flag_written(subject.activation_intent(binding()))
        values = env_values(state)
        values['current_sha256'] = 'f' * 64
        before = copy.deepcopy((state, values))
        result = subject.advance_activation(state, 'begin_rollback', proof(state, 'begin_rollback', **values))
        self.assertEqual((state, values), before)
        self.assertEqual(result['phase'], 'review-required')
        self.assertIsNone(result['feature_flag_enabled'])
        with self.assertRaises(subject.Stop):
            subject.advance_activation(result, 'rollback_flag_restored',
                proof(result, 'rollback_flag_restored', **env_values(result, False)))


if __name__ == '__main__':
    unittest.main()
