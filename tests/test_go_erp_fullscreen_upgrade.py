"""Bounded native-seed adaptation preserves proof bytes and held-state history."""
import copy
import hashlib
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    'fullscreen_watcher_upgrade', ROOT / 'deployment/upgrade_go_erp_fullscreen_watcher_root.py')
upgrade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)

OLD_SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
OLD_CANDIDATE = 'dceb59ad489bbba6b6534a4da784f6d26c82b2ff'
OLD_INVENTORY = '0068ecaeb994a7c7bd3916eeaa137a1c33e0f2ddf3f1e5b3807a35db61d56005'
NEW_SEED = upgrade.NEW_SEED
OLD_POLICY = b'-native-program-center-seed-v1'
NEW_POLICY = b'-native-fullscreen-f12-seed-v1'
ROSTER = (
    'desktop_dashboard_server_preflight.py',
    'diagnose_desktop_dashboard_current_source.py',
    'go_erp_current_candidate_ci.yml',
    'go_erp_live_source.py',
    'go_erp_live_updates.py',
    'publish_go_erp_update.py',
    'verify_go_erp_current_candidate_ci.py',
)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


class FullscreenPayloadTests(unittest.TestCase):
    def payloads(self):
        # Deliberately embed old labels in proof/publisher fixtures too: the
        # adapter must leave every byte outside its two admitted files intact.
        result = {name: b'# immutable proof ' + name.encode() + b'\n'
                  + OLD_SEED.encode() + b'\n' + OLD_POLICY + b'\n'
                  for name in ROSTER}
        for name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            result[name] = (b"PREVIEW31_SEED = '" + OLD_SEED.encode()
                            + b"'\nPREVIEW31_POLICY = CANDIDATE_POLICY + '"
                            + OLD_POLICY + b"'\n# publication gate remains installed\n")
        return result

    def adapt(self, payloads, new_seed=NEW_SEED):
        pins = {name: blob(data) for name, data in payloads.items()}
        with patch.object(upgrade, 'OLD_PINS', pins):
            return upgrade.adapt_payloads(payloads, new_seed)

    def test_reviewed_runtime_roster_is_exactly_seven_files(self):
        self.assertEqual(upgrade.OLD_SEED, OLD_SEED)
        self.assertEqual(set(upgrade.OLD_PINS), set(ROSTER))
        self.assertTrue(all(isinstance(pin, str) and len(pin) == 40
                            for pin in upgrade.OLD_PINS.values()))

    def test_only_two_seed_and_policy_bindings_change_and_inputs_are_immutable(self):
        original = self.payloads()
        before = copy.deepcopy(original)
        actual = self.adapt(original)
        self.assertEqual(original, before)
        self.assertIsNot(actual, original)
        self.assertEqual(set(actual), set(original))
        for name, old in original.items():
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                self.assertEqual(actual[name], old.replace(OLD_SEED.encode(), NEW_SEED.encode())
                                 .replace(OLD_POLICY, NEW_POLICY))
            else:
                self.assertEqual(actual[name], old, name)

    def test_unreviewed_payload_bytes_are_refused_before_adaptation(self):
        original = self.payloads()
        pins = {name: blob(data) for name, data in original.items()}
        for name in ROSTER:
            with self.subTest(name=name):
                changed = dict(original)
                changed[name] += b'# later unreviewed edit\n'
                before = copy.deepcopy(changed)
                with patch.object(upgrade, 'OLD_PINS', pins), self.assertRaises(Exception):
                    upgrade.adapt_payloads(changed, NEW_SEED)
                self.assertEqual(changed, before)

    def test_missing_extra_or_nonbyte_runtime_members_are_refused(self):
        original = self.payloads()
        pins = {name: blob(data) for name, data in original.items()}
        variants = [dict(original), dict(original), dict(original)]
        variants[0].pop('publish_go_erp_update.py')
        variants[1]['extra.py'] = b'unsolicited executable code'
        variants[2]['go_erp_live_source.py'] = 'not bytes'
        for changed in variants:
            with self.subTest(keys=list(changed)):
                before = copy.deepcopy(changed)
                with patch.object(upgrade, 'OLD_PINS', pins), self.assertRaises(Exception):
                    upgrade.adapt_payloads(changed, NEW_SEED)
                self.assertEqual(changed, before)

    def test_missing_or_repeated_context_is_refused_even_when_payload_is_pinned(self):
        for filename in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            for marker in (OLD_SEED.encode(), OLD_POLICY):
                for replacement in (b'', marker + b'\n' + marker):
                    with self.subTest(filename=filename, marker=marker, replacement=replacement):
                        values = self.payloads()
                        values[filename] = values[filename].replace(marker, replacement)
                        before = copy.deepcopy(values)
                        with self.assertRaises(Exception):
                            self.adapt(values)
                        self.assertEqual(values, before)

    def test_invalid_or_unchanged_seed_cannot_rebind_the_worker(self):
        for value in ('', 'foreign', '0' * 40, 'a' * 39, 'a' * 41, 'A' * 40, OLD_SEED, None, True):
            with self.subTest(value=value), self.assertRaises(Exception):
                self.adapt(self.payloads(), value)


class FullscreenHeldStateTests(unittest.TestCase):
    def state(self):
        return {
            'format': 1,
            'base_revision': OLD_SEED,
            'counter': 36,
            'version_floor': '0.4.4-preview.36',
            'sequence_floor': 39872151507,
            'last_remote_sha': OLD_CANDIDATE,
            'observed': None,
            'desired': {
                'commit': OLD_CANDIDATE, 'version': '0.4.4-preview.36',
                'inventorySha256': OLD_INVENTORY,
                'candidatePath': '/var/lib/go-erp-live-updates/staging/candidate-36-0068ecaeb994a7c7',
                'candidatePolicy': 'historical-baseline-v2-native-program-center-seed-v1',
                'pushed': True, 'failures': 0, 'retry_at': 0, 'status': 'waiting-ci',
            },
            'published': None,
            'published_history': [],
            'dependency_guard': {'fixed-local-dependencies': {'sha256': 'e' * 64}},
        }

    def migrate(self, value, old_seed=OLD_SEED, new_seed=NEW_SEED, publication=None, reviewed=None):
        return upgrade.migrated_state(value, old_seed, new_seed, publication, reviewed=reviewed)

    def test_unpublished_candidate_is_retired_without_reusing_version_or_losing_history(self):
        value = self.state()
        before = copy.deepcopy(value)
        result = self.migrate(value)
        self.assertEqual(value, before)
        self.assertEqual(result['base_revision'], NEW_SEED)
        self.assertEqual(result['counter'], 36)
        self.assertEqual(result['version_floor'], '0.4.4-preview.36')
        self.assertEqual(result['sequence_floor'], before['sequence_floor'])
        self.assertEqual(result['last_remote_sha'], OLD_CANDIDATE)
        self.assertEqual(result['published_history'], before['published_history'])
        self.assertEqual(result['dependency_guard'], before['dependency_guard'])
        for name in ('observed', 'desired', 'published'):
            self.assertIsNone(result[name])
        result['dependency_guard']['fixed-local-dependencies']['sha256'] = '0' * 64
        self.assertEqual(value, before, 'A migrated object must not alias retained private state')

    def test_only_recognized_unpublished_retry_states_are_retired(self):
        for status in ('waiting-ci', 'retry-build', 'blocked'):
            with self.subTest(status=status):
                value = self.state()
                value['desired']['status'] = status
                self.assertEqual(self.migrate(value)['base_revision'], NEW_SEED)
        for status in ('published', 'prepared', 'building', 'unauthorized'):
            with self.subTest(status=status), self.assertRaises(Exception):
                value = self.state()
                value['desired']['status'] = status
                self.migrate(value)

    def test_prior_accepted_release_history_and_sequence_floor_are_preserved(self):
        value = self.state()
        value['published_history'] = [{
            'version': '0.4.4-preview.35', 'sequence': 39872150000,
            'source_revision': 'b' * 40,
            'source': {'format': 1, 'files': 1342, 'framework': '8.83.29', 'sha256': 'e' * 64},
            'installer': {'sha256': 'c' * 64, 'bytes': 100000,
                          'path': '/api/desktop-dashboard/update/installer/' + 'c' * 64},
            'gui': {},
        }]
        before = copy.deepcopy(value)
        result = self.migrate(value)
        self.assertEqual(result['published_history'], before['published_history'])
        self.assertEqual(result['sequence_floor'], before['sequence_floor'])
        result['published_history'][0]['version'] = 'modified-result'
        self.assertEqual(value, before)

    def test_old_observation_is_accepted_only_for_its_exact_seed_and_inventory(self):
        value = self.state()
        value['observed'] = {'seedRevision': OLD_SEED, 'inventorySha256': OLD_INVENTORY}
        self.assertIsNone(self.migrate(value)['observed'])
        for field, replacement in (('seedRevision', 'a' * 40), ('inventorySha256', 'f' * 64)):
            changed = copy.deepcopy(value)
            changed['observed'][field] = replacement
            with self.subTest(field=field), self.assertRaises(Exception):
                self.migrate(changed)

    def test_foreign_candidate_publication_or_remote_history_cannot_be_erased(self):
        changes = [
            ('base_revision', 'a' * 40), ('last_remote_sha', 'a' * 40),
            ('published', {'commit': OLD_CANDIDATE}), ('desired', None),
        ]
        for field, replacement in changes:
            with self.subTest(field=field), self.assertRaises(Exception):
                value = self.state()
                value[field] = replacement
                self.migrate(value)
        for field, replacement in (
            ('commit', 'a' * 40), ('version', '0.4.4-preview.35'),
            ('inventorySha256', 'f' * 64), ('pushed', False), ('pushed', 1),
        ):
            with self.subTest(desired_field=field, replacement=replacement), self.assertRaises(Exception):
                value = self.state()
                value['desired'][field] = replacement
                self.migrate(value)
        with self.assertRaises(Exception):
            self.migrate(self.state(), publication={'source_revision': OLD_CANDIDATE})

    def test_unknown_seed_or_invalid_counter_floors_are_refused_without_mutation(self):
        for old_seed, new_seed in (('a' * 40, NEW_SEED), (OLD_SEED, OLD_SEED), (OLD_SEED, 'invalid')):
            value = self.state()
            before = copy.deepcopy(value)
            with self.subTest(old_seed=old_seed, new_seed=new_seed), self.assertRaises(Exception):
                self.migrate(value, old_seed, new_seed)
            self.assertEqual(value, before)
        for field, replacement in (
            ('counter', 35), ('counter', 37), ('counter', True), ('counter', -1),
            ('format', True),
            ('version_floor', '0.4.4-preview.37'), ('version_floor', 'unrecognized'),
            ('sequence_floor', -1), ('sequence_floor', True),
        ):
            value = self.state()
            value[field] = replacement
            before = copy.deepcopy(value)
            with self.subTest(field=field, replacement=replacement), self.assertRaises(Exception):
                self.migrate(value)
            self.assertEqual(value, before)

    def test_old_publication_preserves_higher_sequence_but_cannot_publish_preview36(self):
        value = self.state()
        before = copy.deepcopy(value)
        publication = {'version': '0.4.4-preview.35', 'sequence': value['sequence_floor'] + 1}
        result = self.migrate(value, publication=publication)
        self.assertEqual(result['counter'], 36)
        self.assertEqual(result['sequence_floor'], publication['sequence'])
        self.assertEqual(value, before)
        for change in ({'version': '0.4.4-preview.36'}, {'sequence': True}, {'sequence': 0}, {'sequence': -1}):
            changed = {**publication, **change}
            with self.subTest(change=change), self.assertRaises(Exception):
                self.migrate(value, publication=changed)

    def test_exact_advanced_preview37_preserves_its_newer_counter_and_sequence(self):
        value = self.state()
        value['counter'] = 37
        value['version_floor'] = '0.4.4-preview.35'
        value['last_remote_sha'] = upgrade.ADVANCED_CANDIDATE
        value['desired'].update(commit=upgrade.ADVANCED_CANDIDATE,
                                version='0.4.4-preview.37',
                                inventorySha256=upgrade.ADVANCED_INVENTORY)
        value['observed'] = {'seedRevision': OLD_SEED,
                             'inventorySha256': upgrade.ADVANCED_INVENTORY}
        before = copy.deepcopy(value)
        result = self.migrate(value)
        self.assertEqual(value, before)
        self.assertEqual(result['base_revision'], NEW_SEED)
        self.assertEqual(result['counter'], 37)
        self.assertEqual(result['version_floor'], '0.4.4-preview.37')
        self.assertEqual(result['sequence_floor'], before['sequence_floor'])
        self.assertEqual(result['last_remote_sha'], upgrade.ADVANCED_CANDIDATE)
        for name in ('desired', 'observed', 'published'):
            self.assertIsNone(result[name])

    def test_unknown_or_mixed_preview37_metadata_cannot_retire_an_advanced_candidate(self):
        baseline = self.state()
        baseline['counter'] = 37
        baseline['last_remote_sha'] = upgrade.ADVANCED_CANDIDATE
        baseline['desired'].update(commit=upgrade.ADVANCED_CANDIDATE,
                                   version='0.4.4-preview.37',
                                   inventorySha256=upgrade.ADVANCED_INVENTORY)
        for field, replacement in (('commit', 'a' * 40), ('version', '0.4.4-preview.36'),
                                   ('inventorySha256', OLD_INVENTORY)):
            value = copy.deepcopy(baseline)
            value['desired'][field] = replacement
            before = copy.deepcopy(value)
            with self.subTest(field=field), self.assertRaises(Exception):
                self.migrate(value)
            self.assertEqual(value, before)
        value = copy.deepcopy(baseline)
        value['counter'] = 38
        with self.assertRaises(Exception):
            self.migrate(value)

    def reviewed_preview38(self):
        value = self.state()
        value['counter'] = 38
        value['version_floor'] = '0.4.4-preview.37'
        value['last_remote_sha'] = '8' * 40
        value['desired'].update(commit='8' * 40, version='0.4.4-preview.38',
                                inventorySha256=upgrade.ADVANCED_INVENTORY)
        value['observed'] = {'seedRevision': OLD_SEED,
                             'inventorySha256': upgrade.ADVANCED_INVENTORY}
        reviewed = {
            'revision': '8' * 40, 'base_revision': OLD_SEED,
            'version': '0.4.4-preview.38', 'inventory_sha256': upgrade.ADVANCED_INVENTORY,
            'source': copy.deepcopy(upgrade.ADVANCED_SOURCE),
            'application_changed': False, 'database_read': False,
        }
        return value, reviewed

    def test_fully_bound_original_candidate_proof_preserves_preview38_high_water(self):
        value, reviewed = self.reviewed_preview38()
        before = copy.deepcopy(value)
        proof_before = copy.deepcopy(reviewed)
        result = self.migrate(value, reviewed=reviewed)
        self.assertEqual(value, before)
        self.assertEqual(reviewed, proof_before)
        self.assertEqual(result['base_revision'], NEW_SEED)
        self.assertEqual(result['counter'], 38)
        self.assertEqual(result['version_floor'], '0.4.4-preview.38')
        self.assertEqual(result['sequence_floor'], before['sequence_floor'])
        self.assertEqual(result['published_history'], before['published_history'])
        self.assertEqual(result['last_remote_sha'], before['last_remote_sha'])
        for name in ('desired', 'observed', 'published'):
            self.assertIsNone(result[name])

    def test_unrecognized_later_revision_requires_the_original_candidate_proof(self):
        value, _ = self.reviewed_preview38()
        with self.assertRaises(Exception):
            self.migrate(value)

    def test_mixed_or_mutating_original_proof_cannot_retire_a_later_candidate(self):
        value, reviewed = self.reviewed_preview38()
        for field, replacement in (
            ('revision', '9' * 40), ('base_revision', '9' * 40),
            ('version', '0.4.4-preview.39'), ('inventory_sha256', '9' * 64),
            ('source', None), ('source', {**upgrade.ADVANCED_SOURCE, 'sha256': '9' * 64}),
            ('application_changed', True), ('application_changed', 0),
            ('database_read', True), ('database_read', 0),
        ):
            proof = copy.deepcopy(reviewed)
            proof[field] = replacement
            before = copy.deepcopy(value)
            with self.subTest(field=field, replacement=replacement), self.assertRaises(Exception):
                self.migrate(value, reviewed=proof)
            self.assertEqual(value, before)

    def test_unknown_application_cohort_is_refused_when_both_source_maps_are_missing(self):
        value, reviewed = self.reviewed_preview38()
        value['desired']['inventorySha256'] = '9' * 64
        value['observed']['inventorySha256'] = '9' * 64
        reviewed['inventory_sha256'] = '9' * 64
        reviewed['source'] = None
        with self.assertRaises(Exception):
            self.migrate(value, reviewed=reviewed)


if __name__ == '__main__':
    unittest.main()
