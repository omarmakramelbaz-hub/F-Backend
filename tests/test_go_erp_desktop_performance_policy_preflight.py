"""Actual policy verifier and pinned loader against local bare Git fixtures."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


BASE = Path(__file__).resolve().parents[1]
FIXTURES = BASE / 'tests/fixtures/go_erp_desktop_performance'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


roll = load('performance_policy_preflight_rollout', BASE / 'deployment/upgrade_go_erp_desktop_performance_root.py')
root_raw = (FIXTURES / 'root_operator.py').read_bytes()
if roll.sha(root_raw) != '90d9dff5ff0fcadb4854f5f0dc2103dcfe8b9b25ef9922164fba1b6e690a03e9':
    raise AssertionError('Frozen original module-loader fixture changed')
root_api = load('performance_policy_original_loader', FIXTURES / 'root_operator.py')
VERIFIER = (FIXTURES / 'policy_seed_verifier.py').read_bytes()
if roll.sha(VERIFIER) != '2de7ec3884760a2b566f45469aa7aa1d2b31d6a605be16fe496af9b998ce15bc':
    raise AssertionError('Proper published policy verifier fixture changed')


def git(directory, *arguments):
    environment = {'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1',
        'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null',
        'GIT_TERMINAL_PROMPT': '0', 'GIT_NO_REPLACE_OBJECTS': '1',
        'GIT_AUTHOR_NAME': 'Fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.invalid',
        'GIT_COMMITTER_NAME': 'Fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid'}
    return subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', *arguments],
        cwd=directory, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout


class PolicyPreflight(unittest.TestCase):
    def fixture(self, temporary, *, wrong_core=False, extra=None, baseline='0.4.4-preview.60'):
        work = Path(temporary) / 'work'
        work.mkdir()
        git(work, 'init', '--quiet', '--initial-branch=main')
        files = {'desktop/program.php': b'<?php // public fixture\n',
            'desktop-pos/package.json': roll.canonical({'version': baseline}),
            'desktop-pos/package-lock.json': roll.canonical({'version': baseline, 'packages': {'': {'version': baseline}}}),
            'desktop-pos/src/main.cjs': b'PUBLIC_FIXTURE = true;\n',
            '.github/workflows/native.yml': b'name: native fixture\n',
            '.github/workflows/foundation.yml': b'name: foundation fixture\n',
            '.github/scripts/proof.cjs': b'PUBLIC_PROOF = true;\n',
            'deployment/verify_go_erp_preview31_seed.py': VERIFIER.replace(roll.CORE.encode(), b'0' * 40),
            'tests/test_go_erp_preview31_seed.py': b'ORIGINAL_TEST = True\n',
            '.github/workflows/go-erp-update-tests.yml': b'name: original policy\n'}
        program_hashes = {'desktop/program.php': roll.sha(files['desktop/program.php'])}
        files['desktop/workspace-contract.json'] = roll.canonical({'programs': program_hashes})
        for name, raw in files.items():
            path = work / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(raw)
        git(work, 'add', '--all')
        git(work, 'commit', '--quiet', '-m', 'Reviewed core fixture')
        core = git(work, 'rev-parse', 'HEAD').strip().decode()
        verifier = VERIFIER.replace(roll.CORE.encode(), ('f' * 40 if wrong_core else core).encode())
        (work / 'deployment/verify_go_erp_preview31_seed.py').write_bytes(verifier)
        (work / 'tests/test_go_erp_preview31_seed.py').write_bytes(('CORE = ' + repr(core) + '\n').encode())
        (work / '.github/workflows/go-erp-update-tests.yml').write_bytes(('name: policy ' + core + '\n').encode())
        if extra is not None:
            path = work / extra
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'UNREVIEWED_POLICY_ADDITION\n')
        git(work, 'add', '--all')
        git(work, 'commit', '--quiet', '-m', 'Policy fixture')
        seed = git(work, 'rev-parse', 'HEAD').strip().decode()
        repository = Path(temporary) / 'release.git'
        git(work, 'clone', '--quiet', '--bare', '--no-hardlinks', str(work), str(repository))
        package = root_api.Package(repository, seed)
        reads = []
        original_file = package.file
        def tracked_file(name, revision=None):
            reads.append((name, revision))
            return original_file(name, revision)
        package.file = tracked_file
        rollout = roll.Rollout.__new__(roll.Rollout)
        rollout.package, rollout.root_api = package, root_api
        rollout.io = SimpleNamespace(parse=json.loads)
        rollout.current = SimpleNamespace(VERSION='0.4.4-preview.60')
        rollout.native = SimpleNamespace(BACKEND=('app', 'bootstrap', 'config', 'database', 'desktop',
            'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock'))
        rollout.o = SimpleNamespace(modules={'acceptance': SimpleNamespace(NATIVE_WORKFLOW='native.yml',
            FOUNDATION_WORKFLOW='foundation.yml', VERIFIER='.github/scripts/proof.cjs')})
        rollout.acceptance_pins = {'contract_sha256': roll.sha(files['desktop/workspace-contract.json']),
            'program_hashes': program_hashes,
            'source_hashes': {'desktop-pos/src/main.cjs': roll.sha(files['desktop-pos/src/main.cjs'])},
            'workflow_sha256': roll.sha(files['.github/workflows/native.yml']),
            'foundation_workflow_sha256': roll.sha(files['.github/workflows/foundation.yml']),
            'verifier_sha256': roll.sha(files['.github/scripts/proof.cjs'])}
        return rollout, core, seed, verifier, reads

    def test_single_child_with_exact_three_policy_files_reaches_all_acceptance_bindings(self):
        with tempfile.TemporaryDirectory(prefix='performance-policy-good-') as temporary:
            rollout, core, seed, verifier, reads = self.fixture(temporary)
            with patch.multiple(roll, CORE=core, SEED=seed, WEB_RELEASE=core,
                                POLICY_VERIFIER_SHA256=roll.sha(verifier)):
                rollout._reviewed_code()
            self.assertEqual(reads[0], ('deployment/verify_go_erp_preview31_seed.py', seed))
            self.assertIn(('desktop/workspace-contract.json', core), reads)
            self.assertIn(('desktop-pos/src/main.cjs', core), reads)

    def test_wrong_embedded_core_stops_before_acceptance_files_are_read(self):
        with tempfile.TemporaryDirectory(prefix='performance-policy-wrong-core-') as temporary:
            rollout, core, seed, verifier, reads = self.fixture(temporary, wrong_core=True)
            with patch.multiple(roll, CORE=core, SEED=seed, WEB_RELEASE=core,
                                POLICY_VERIFIER_SHA256=roll.sha(verifier)), \
                    self.assertRaisesRegex(roll.Stop, 'POLICY_SEED_CORE_CHANGED'):
                rollout._reviewed_code()
            self.assertEqual(reads, [('deployment/verify_go_erp_preview31_seed.py', seed)])

    def test_app_or_native_change_in_policy_seed_is_rejected_by_actual_verifier(self):
        for extra in ('app/extra.php', 'desktop-pos/src/extra.cjs'):
            with self.subTest(extra=extra), tempfile.TemporaryDirectory(prefix='performance-policy-extra-') as temporary:
                rollout, core, seed, verifier, reads = self.fixture(temporary, extra=extra)
                with patch.multiple(roll, CORE=core, SEED=seed, WEB_RELEASE=core,
                                    POLICY_VERIFIER_SHA256=roll.sha(verifier)), \
                        self.assertRaisesRegex(Exception, 'GO_ERP_PREVIEW31_SEED_UNREVIEWED_POLICY_DELTA'):
                    rollout._reviewed_code()
                self.assertEqual(reads, [('deployment/verify_go_erp_preview31_seed.py', seed)])

    def test_changed_verifier_hash_is_rejected_by_actual_original_loader(self):
        with tempfile.TemporaryDirectory(prefix='performance-policy-bad-hash-') as temporary:
            rollout, core, seed, verifier, reads = self.fixture(temporary)
            with patch.multiple(roll, CORE=core, SEED=seed, WEB_RELEASE=core,
                                POLICY_VERIFIER_SHA256='0' * 64), \
                    self.assertRaisesRegex(root_api.Stop, 'MODULE_PIN_CHANGED'):
                rollout._reviewed_code()
            self.assertEqual(reads, [('deployment/verify_go_erp_preview31_seed.py', seed)])

    def test_unpublished_review_version_cannot_be_used_as_the_seed_baseline(self):
        with tempfile.TemporaryDirectory(prefix='performance-policy-version-') as temporary:
            rollout, core, seed, verifier, reads = self.fixture(temporary, baseline='0.4.4-preview.61')
            with patch.multiple(roll, CORE=core, SEED=seed, WEB_RELEASE=core,
                                POLICY_VERIFIER_SHA256=roll.sha(verifier)), \
                    self.assertRaisesRegex(roll.Stop, 'POLICY_SEED_VERSION_BASELINE_CHANGED'):
                rollout._reviewed_code()
            self.assertNotIn(('desktop/workspace-contract.json', core), reads)


if __name__ == '__main__':
    unittest.main()
