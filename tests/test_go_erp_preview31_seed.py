"""Real temporary Git trees verify seed lineage and native-byte immutability."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('preview31_seed_policy',
    ROOT / 'deployment/verify_go_erp_preview31_seed.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class Preview31SeedTests(unittest.TestCase):
    def test_release_pins_the_reviewed_private_diagnostic_core(self):
        self.assertEqual(policy.CORE, 'da718620ebf2e23d8615b84b06127d6209f2f351')
        self.assertEqual(policy.POLICY, 'native-close-reopen-program-contract-parallel-media-v1')

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-preview31-policy-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.git('init', '--quiet', '--template=')
        for name, data in {
            'app/source.php': '<?php // fixed application\n',
            'desktop-pos/src/local-runtime.cjs': '// reviewed memory-safe native core\n',
            'desktop-pos/package.json': '{"version":"0.4.4-preview.30"}\n',
            'desktop-pos/package-lock.json': '{"version":"0.4.4-preview.30"}\n',
            'deployment/verify_go_erp_current_candidate_ci.py': '# immutable original verifier\n',
            '.github/workflows/go-erp-update-tests.yml': 'original reviewed checks\n',
        }.items():
            self.write(name, data)
        self.core = self.commit('reviewed fixed native core')
        self.policy_files()
        self.seed = self.commit('only three bounded seed policy files')

    def git(self, *arguments):
        return policy.git(self.root, *arguments)

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(data)

    def commit(self, message):
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
                 '-c', 'commit.gpgSign=false', 'commit', '--quiet', '-m', message)
        return self.git('rev-parse', 'HEAD').decode().strip()

    def policy_files(self):
        for name in policy.POLICY_FILES:
            self.write(name, '# reviewed policy ' + name + '\n')

    def candidate(self, extra=None):
        self.git('reset', '--hard', self.seed)
        value = {'baseRevision': self.seed, 'inventory': {'app/source.php': {'present': True}}}
        self.write(policy.CANDIDATE, json.dumps(value))
        self.write('app/source.php', '<?php // newly captured source\n')
        for name in policy.VERSION_FILES:
            self.write(name, '{"version":"0.4.4-preview.31"}\n')
        if extra:
            self.write(extra, '# forbidden candidate edit\n')
        return self.commit('captured candidate'), value

    def test_exact_three_file_single_parent_policy_seed_is_accepted(self):
        result = policy.validate_seed(self.root, self.seed, core=self.core)
        self.assertEqual(result, {'core': self.core, 'seed': self.seed,
                                 'policy_files': sorted(policy.POLICY_FILES)})
        self.assertEqual(policy.parents(self.root, self.seed), [self.core])

    def test_app_native_dependency_package_or_extra_policy_changes_are_refused(self):
        for name in ['app/source.php', 'desktop-pos/src/local-runtime.cjs',
                     'desktop-pos/src/dashboard-download.cjs', 'composer.lock',
                     'desktop-pos/runtime/composer.lock', 'desktop-pos/package.json',
                     'desktop-pos/package-lock.json', 'deployment/verify_go_erp_current_candidate_ci.py']:
            with self.subTest(name=name):
                self.git('reset', '--hard', self.core)
                self.policy_files()
                self.write(name, 'unreviewed change\n')
                with self.assertRaisesRegex(policy.SeedError, 'UNREVIEWED_POLICY_DELTA'):
                    policy.validate_seed(self.root, self.commit('unreviewed change'), core=self.core)

    def test_each_policy_file_is_required_and_links_or_executable_modes_are_refused(self):
        for omitted in policy.POLICY_FILES:
            with self.subTest(omitted=omitted):
                self.git('reset', '--hard', self.core)
                self.git('clean', '-fd')
                for name in policy.POLICY_FILES - {omitted}:
                    self.write(name, '# approved policy\n')
                with self.assertRaisesRegex(policy.SeedError, 'POLICY_INCOMPLETE'):
                    policy.validate_seed(self.root, self.commit('incomplete policy'), core=self.core)
        for link in (False, True):
            with self.subTest(link=link):
                self.git('reset', '--hard', self.core)
                self.git('clean', '-fd')
                self.policy_files()
                target = self.root / 'tests/test_go_erp_preview31_seed.py'
                if link:
                    target.unlink(); target.symlink_to('../app/source.php')
                else:
                    target.chmod(0o755)
                with self.assertRaisesRegex(policy.SeedError, 'UNREVIEWED_POLICY_DELTA'):
                    policy.validate_seed(self.root, self.commit('unsafe policy type'), core=self.core)

    def test_intermediate_or_merge_parent_cannot_replace_direct_core_parent(self):
        self.write('tests/test_go_erp_preview31_seed.py', '# later policy edit\n')
        later = self.commit('intermediate policy commit')
        with self.assertRaisesRegex(policy.SeedError, 'POLICY_PARENT_INVALID'):
            policy.validate_seed(self.root, later, core=self.core)
        tree = self.git('rev-parse', self.seed + '^{tree}').decode().strip()
        result = subprocess.run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
            '-c', 'commit.gpgSign=false', '-C', str(self.root), 'commit-tree', tree,
            '-p', self.core, '-p', self.seed], input=b'merge policy\n',
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=policy.ENV, check=True)
        with self.assertRaisesRegex(policy.SeedError, 'POLICY_PARENT_INVALID'):
            policy.validate_seed(self.root, result.stdout.decode().strip(), core=self.core)

    def test_candidate_may_change_only_captured_source_version_and_metadata(self):
        revision, candidate = self.candidate()
        allowed = policy.validate_candidate_delta(self.root, revision, self.seed, candidate)
        self.assertEqual(allowed, {'app/source.php', policy.CANDIDATE, *policy.VERSION_FILES})
        for name in ['desktop-pos/src/local-runtime.cjs', 'desktop-pos/src/dashboard-download.cjs',
                     *policy.POLICY_FILES, 'deployment/verify_go_erp_current_candidate_ci.py',
                     'composer.lock', 'desktop-pos/runtime/composer.lock']:
            with self.subTest(name=name):
                revision, candidate = self.candidate(name)
                with self.assertRaisesRegex(policy.SeedError, 'FORBIDDEN_CANDIDATE_DELTA'):
                    policy.validate_candidate_delta(self.root, revision, self.seed, candidate)

    def test_candidate_cannot_claim_native_paths_as_captured_application(self):
        revision, candidate = self.candidate('desktop-pos/src/local-runtime.cjs')
        candidate['inventory']['desktop-pos/src/local-runtime.cjs'] = {'present': True}
        with self.assertRaisesRegex(policy.SeedError, 'CANDIDATE_INVENTORY_INVALID'):
            policy.validate_candidate_delta(self.root, revision, self.seed, candidate)
        with self.assertRaisesRegex(policy.SeedError, 'CANDIDATE_PARENT_INVALID'):
            policy.validate_candidate_delta(self.root, revision, self.core, candidate)

    def test_legacy_bases_delegate_without_entering_new_seed_policy(self):
        for base in policy.LEGACY_BASES:
            with self.subTest(base=base):
                self.write(policy.CANDIDATE, json.dumps({'baseRevision': base}))
                with patch.object(policy, 'legacy_verify', return_value={'legacy': base}) as legacy, \
                     patch.object(policy, 'validate_seed', side_effect=AssertionError('Legacy proof changed')):
                    self.assertEqual(policy.verify(self.root, self.root / 'historical'), {'legacy': base})
                    legacy.assert_called_once_with(self.root, self.root / 'historical')

    def test_new_candidate_runs_original_proof_against_exact_seed_and_preserves_checkout(self):
        revision, _ = self.candidate()
        original = types.SimpleNamespace(validate_checkout=lambda root, seed, expected_base:
            {'revision': revision, 'base_revision': expected_base, 'application_changed': False, 'database_read': False})
        baseline = self.git('worktree', 'list', '--porcelain')
        with patch.object(policy, 'CORE', self.core), \
             patch.object(policy, 'validate_seed', wraps=lambda root, seed: policy_validate(root, seed, self.core)), \
             patch.object(policy, 'original_verifier', return_value=original):
            result = policy.verify(self.root, self.root / 'historical')
        self.assertEqual(result['policy'], policy.POLICY)
        self.assertEqual(result['reviewed_native_import_core'], self.core)
        self.assertEqual(result['base_revision'], self.seed)
        self.assertEqual(self.git('rev-parse', 'HEAD').decode().strip(), revision)
        self.assertEqual(self.git('worktree', 'list', '--porcelain'), baseline)

    def test_forbidden_candidate_edit_is_rejected_before_loading_original_verifier(self):
        self.candidate('desktop-pos/src/local-runtime.cjs')
        with patch.object(policy, 'validate_seed', wraps=lambda root, seed: policy_validate(root, seed, self.core)), \
             patch.object(policy, 'original_verifier', side_effect=AssertionError('Forbidden code executed')):
            with self.assertRaisesRegex(policy.SeedError, 'FORBIDDEN_CANDIDATE_DELTA'):
                policy.verify(self.root, self.root / 'historical')

    def test_duplicate_metadata_and_invalid_revisions_are_refused(self):
        with self.assertRaisesRegex(policy.SeedError, 'DUPLICATE_JSON_FIELD'):
            policy.json_object(b'{"baseRevision":"one","baseRevision":"two"}')
        for revision in ('', 'foreign', True):
            with self.assertRaises(policy.SeedError):
                policy.validate_seed(self.root, revision, core=self.core)


policy_validate = policy.validate_seed


if __name__ == '__main__':
    unittest.main()
