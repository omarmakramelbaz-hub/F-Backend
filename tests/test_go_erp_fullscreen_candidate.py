"""Adversarial candidate admission uses the original verifier only after scope checks."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import types
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    'fullscreen_candidate_upgrade', ROOT / 'deployment/upgrade_go_erp_fullscreen_watcher_root.py')
upgrade = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upgrade)

REVISION = '8' * 40
BRANCH = 'refs/heads/codex/go-erp-live-source-20261021'


def wire(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()


def candidate_metadata(seed, inventory, number):
    packaged = {name: row['sha256'] for name, row in inventory.items() if row['present']}
    return {
        'format': 1, 'kind': 'go-erp-live-source-candidate',
        'repository': 'omarmakramelbaz-hub/F-Backend',
        'branch': 'codex/go-erp-live-source-20261021',
        'baseRevision': seed, 'version': '0.4.4-preview.' + str(number),
        'versionSequence': number,
        'inventorySha256': hashlib.sha256(wire(inventory)).hexdigest(),
        'inventory': inventory, 'packagedFiles': packaged,
        'authoritativeLiveFiles': {name: row['sha256'] for name, row in inventory.items()
                                   if row['authority'] == 'live'},
        'retainedTrustedFiles': {name: row['sha256'] for name, row in inventory.items()
                                 if row['authority'] == 'trusted-base'},
        'removedFiles': [name for name, row in inventory.items() if not row['present']],
    }


class CandidateFixture:
    REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'

    def __init__(self, test, work):
        self.test = test
        self.work = work
        self.events = []
        self.inventory = {'app/fixture.php': {
            'authority': 'live', 'present': True, 'sha256': 'f' * 64,
            'bytes': 12, 'mode': '100644',
        }}
        self.candidate = candidate_metadata(upgrade.OLD_SEED, self.inventory, 38)
        self.inventory_hash = self.candidate['inventorySha256']
        self.source = {'format': 1, 'files': 1, 'framework': '8.83.29', 'sha256': 'e' * 64}
        self.proof = {
            'revision': REVISION, 'base_revision': upgrade.OLD_SEED,
            'version': '0.4.4-preview.38', 'inventory_sha256': self.inventory_hash,
            'source': copy.deepcopy(self.source), 'application_changed': False, 'database_read': False,
        }
        self.state = {
            'format': 1, 'base_revision': upgrade.OLD_SEED, 'counter': 38,
            'version_floor': '0.4.4-preview.37', 'sequence_floor': 100,
            'last_remote_sha': REVISION, 'published': None, 'published_history': [],
            'observed': {'seedRevision': upgrade.OLD_SEED, 'inventorySha256': self.inventory_hash},
            'desired': {'commit': REVISION, 'version': '0.4.4-preview.38',
                        'inventorySha256': self.inventory_hash, 'pushed': True, 'status': 'waiting-ci'},
        }
        self.parents = [upgrade.OLD_SEED]
        self.changed = {'app/fixture.php', 'desktop-pos/package.json', 'desktop-pos/package-lock.json',
                        'deployment/go_erp_live_source_candidate.json'}
        self.seed_package = {'name': 'fixture', 'version': '0.4.4-preview.35',
                             'dependencies': {'locked': '1.0.0'}}
        self.seed_lock = {'version': '0.4.4-preview.35', 'packages': {
            '': {'name': 'fixture', 'version': '0.4.4-preview.35'},
            'node_modules/locked': {'version': '1.0.0', 'integrity': 'fixed'},
        }}
        self.package = {**copy.deepcopy(self.seed_package), 'version': '0.4.4-preview.38'}
        self.lock = copy.deepcopy(self.seed_lock)
        self.lock['version'] = self.lock['packages']['']['version'] = '0.4.4-preview.38'
        self.remote_answers = [REVISION, REVISION]
        self.remote_reads = 0
        self.verify_calls = []
        self.verify_error = None
        self.targets = []
        self.context = object.__new__(upgrade.Context)
        self.context.base = self
        self.context.utility = object()
        self.context.prior = types.SimpleNamespace(candidate_metadata=candidate_metadata)
        self.context.candidate_verifier = types.SimpleNamespace(validate_checkout=self.validate_checkout)
        self.context.candidate_proof = None
        self.context.remote_revision = upgrade.OLD_CANDIDATE

    @staticmethod
    def parse(raw):
        return json.loads(raw)

    def git(self, root, *arguments, **kwargs):
        self.test.assertEqual(Path(root), self.work / 'checkout')
        self.events.append(('git', arguments))
        command = arguments[0]
        if command == 'ls-remote':
            self.test.assertEqual(arguments[1:], ('--exit-code', self.REMOTE, BRANCH))
            if self.remote_reads:
                self.test.assertIsNone(self.context.candidate_proof,
                                       'Proof must not be cached before the second remote read')
                self.test.assertTrue(all(not target.exists() for target in self.targets))
            answer = self.remote_answers[min(self.remote_reads, len(self.remote_answers) - 1)]
            self.remote_reads += 1
            return (answer + '\t' + BRANCH + '\n').encode()
        if command == 'fetch':
            self.test.assertEqual(arguments[-2:], (self.REMOTE, REVISION))
            self.test.assertIn('--depth=2', arguments)
            return b''
        if command == 'cat-file':
            return (b'tree ' + b'1' * 40 + b'\n'
                    + b''.join(b'parent ' + parent.encode() + b'\n' for parent in self.parents)
                    + b'\nfixture commit\n')
        if command == 'show':
            revision, path = arguments[1].split(':', 1)
            if path == 'deployment/go_erp_live_source_candidate.json':
                self.test.assertEqual(revision, REVISION)
                return wire(self.candidate)
            original = revision == upgrade.OLD_SEED
            if path == 'desktop-pos/package.json':
                return wire(self.seed_package if original else self.package)
            if path == 'desktop-pos/package-lock.json':
                return wire(self.seed_lock if original else self.lock)
            raise AssertionError('Unexpected source read: ' + path)
        if command == 'diff':
            self.test.assertEqual(arguments[-3:], (upgrade.OLD_SEED, REVISION, '--'))
            return b'\0'.join(name.encode() for name in sorted(self.changed)) + b'\0'
        if arguments[:2] == ('worktree', 'add'):
            target = Path(arguments[-2])
            self.test.assertEqual(arguments[-1], REVISION)
            target.mkdir()
            (target / 'fixture').write_text('private candidate')
            self.targets.append(target)
            return b''
        if arguments[:2] == ('worktree', 'remove'):
            target = Path(arguments[-1])
            self.test.assertIn(target, self.targets)
            shutil.rmtree(target)
            return b''
        raise AssertionError('Unexpected Git command: ' + repr(arguments))

    def validate_checkout(self, target, historical, *, expected_base):
        self.events.append(('verify', Path(target)))
        self.verify_calls.append((Path(target), Path(historical), expected_base))
        self.test.assertTrue(Path(target).is_dir())
        self.test.assertEqual(Path(historical), self.work / 'checkout')
        self.test.assertEqual(expected_base, upgrade.OLD_SEED)
        self.test.assertIsNone(self.context.candidate_proof)
        if self.verify_error is not None:
            raise self.verify_error
        return copy.deepcopy(self.proof)

    def checkout(self, root, revision, utility):
        self.events.append(('checkout', revision))
        self.test.assertEqual(Path(root), self.work / 'checkout')
        self.test.assertEqual(revision, upgrade.OLD_SEED)
        self.test.assertIs(utility, self.context.utility)
        self.test.assertIsNone(self.context.candidate_proof)
        self.test.assertTrue(all(not target.exists() for target in self.targets))


class FullscreenCandidateAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='fullscreen-candidate-test-')
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)
        (self.work / 'checkout').mkdir()
        self.fixture = CandidateFixture(self, self.work)
        self.work_patch = patch.object(upgrade, 'WORK', self.work)
        self.work_patch.start()
        self.addCleanup(self.work_patch.stop)
        self.source_patch = patch.dict(upgrade.CANDIDATE_SOURCES,
                                       {self.fixture.inventory_hash: self.fixture.source})
        self.source_patch.start()
        self.addCleanup(self.source_patch.stop)

    def bind(self):
        return self.fixture.context.bind_candidate(self.fixture.state)

    def assert_no_verification_or_cached_proof(self):
        self.assertEqual(self.fixture.verify_calls, [])
        self.assertIsNone(self.fixture.context.candidate_proof)
        self.assertEqual(list(self.work.glob('fullscreen-candidate-check-*')), [])

    def test_success_cleans_private_worktree_and_rechecks_seed_and_remote_before_caching(self):
        original = copy.deepcopy(self.fixture.state)
        result = self.bind()
        self.assertEqual(result, self.fixture.proof)
        self.assertEqual(self.fixture.state, original)
        self.assertEqual(self.fixture.context.candidate_proof, result)
        self.assertEqual(self.fixture.remote_reads, 2)
        self.assertEqual(len(self.fixture.verify_calls), 1)
        self.assertTrue(all(not target.exists() for target in self.fixture.targets))
        self.assertEqual(list(self.work.glob('fullscreen-candidate-check-*')), [])
        events = self.fixture.events
        verify = next(i for i, event in enumerate(events) if event[0] == 'verify')
        remove = next(i for i, event in enumerate(events)
                      if event[0] == 'git' and event[1][:2] == ('worktree', 'remove'))
        checkout = next(i for i, event in enumerate(events) if event[0] == 'checkout')
        self.assertLess(verify, remove)
        self.assertLess(remove, checkout)
        self.assertEqual(events[-1][1][0], 'ls-remote')

    def test_unknown_or_mismatched_remote_candidate_is_rejected_before_content_execution(self):
        self.fixture.remote_answers[0] = '9' * 40
        with self.assertRaises(upgrade.Stop):
            self.bind()
        self.assert_no_verification_or_cached_proof()
        self.assertFalse(any(event[0] == 'git' and event[1][0] == 'fetch'
                             for event in self.fixture.events))

    def test_missing_foreign_or_merge_parent_is_refused_before_original_verifier(self):
        for parents in ([], ['9' * 40], [upgrade.OLD_SEED, '9' * 40]):
            with self.subTest(parents=parents):
                self.fixture.parents = parents
                with self.assertRaises(upgrade.Stop):
                    self.bind()
                self.assert_no_verification_or_cached_proof()

    def test_native_test_workflow_and_dependency_changes_cannot_enter_the_candidate(self):
        original = set(self.fixture.changed)
        for name in ('desktop-pos/src/main.cjs', 'desktop-pos/test/native.test.cjs',
                     'tests/test_policy.py', '.github/workflows/go-erp-update-tests.yml',
                     'composer.lock', 'desktop-pos/runtime/composer.lock'):
            with self.subTest(name=name):
                self.fixture.changed = original | {name}
                with self.assertRaises(upgrade.Stop):
                    self.bind()
                self.assert_no_verification_or_cached_proof()

    def test_candidate_metadata_drift_is_refused_before_original_verifier(self):
        original = copy.deepcopy(self.fixture.candidate)
        changes = [('version', '0.4.4-preview.39'), ('versionSequence', True),
                   ('inventorySha256', '9' * 64), ('baseRevision', '9' * 40),
                   ('unexpected', 'unsigned metadata')]
        for field, value in changes:
            with self.subTest(field=field):
                self.fixture.candidate = {**copy.deepcopy(original), field: value}
                with self.assertRaises(upgrade.Stop):
                    self.bind()
                self.assert_no_verification_or_cached_proof()

    def test_package_and_lock_may_change_version_only(self):
        for filename in ('package', 'lock'):
            with self.subTest(filename=filename):
                original = copy.deepcopy(getattr(self.fixture, filename))
                changed = copy.deepcopy(original)
                if filename == 'package':
                    changed['dependencies']['locked'] = '2.0.0'
                else:
                    changed['packages']['node_modules/locked']['integrity'] = 'foreign'
                setattr(self.fixture, filename, changed)
                with self.assertRaises(upgrade.Stop):
                    self.bind()
                self.assert_no_verification_or_cached_proof()
                setattr(self.fixture, filename, original)

    def test_original_verifier_failure_still_removes_private_candidate(self):
        self.fixture.verify_error = RuntimeError('bounded private verification failure')
        with self.assertRaises(RuntimeError):
            self.bind()
        self.assertEqual(len(self.fixture.verify_calls), 1)
        self.assertIsNone(self.fixture.context.candidate_proof)
        self.assertEqual(list(self.work.glob('fullscreen-candidate-check-*')), [])

    def test_forged_original_proof_cannot_be_cached_and_worktree_is_already_removed(self):
        original = copy.deepcopy(self.fixture.proof)
        for field, value in (('revision', '9' * 40), ('base_revision', '9' * 40),
                             ('version', '0.4.4-preview.39'), ('inventory_sha256', '9' * 64),
                             ('source', None), ('application_changed', True), ('database_read', True)):
            with self.subTest(field=field):
                self.fixture.proof = {**copy.deepcopy(original), field: value}
                with self.assertRaises(upgrade.Stop):
                    self.bind()
                self.assertIsNone(self.fixture.context.candidate_proof)
                self.assertTrue(all(not target.exists() for target in self.fixture.targets))
                self.assertEqual(list(self.work.glob('fullscreen-candidate-check-*')), [])

    def test_remote_race_after_original_verification_cannot_cache_a_stale_proof(self):
        self.fixture.remote_answers[1] = '9' * 40
        with self.assertRaises(upgrade.Stop):
            self.bind()
        self.assertEqual(len(self.fixture.verify_calls), 1)
        self.assertIsNone(self.fixture.context.candidate_proof)
        self.assertEqual(list(self.work.glob('fullscreen-candidate-check-*')), [])


if __name__ == '__main__':
    unittest.main()
