"""Exercise frozen workspace acceptance against an actual private Git package.

The checks stop at a deliberately unavailable GitHub boundary, after all exact
candidate workflow, verifier, native-source and program-contract pins passed.
No lifecycle receipt or successful publication is invented by these fixtures.
"""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

BASE = Path(__file__).resolve().parents[1]
FIXTURES = BASE / 'tests/fixtures/go_erp_desktop_performance'
FALLBACK = BASE.parent / 'perf_operator/tests/fixtures/go_erp_desktop_performance'
CANONICAL_62 = 'f9c104bc0bfab1e8f3d0598b1800148f182c08bf'


def load(name, path, digest=None):
    raw = path.read_bytes()
    if digest is not None and hashlib.sha256(raw).hexdigest() != digest:
        raise AssertionError('Frozen source changed: ' + path.name)
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


acceptance = load('transport_original_workspace_acceptance',
    FIXTURES / 'workspace_acceptance.py',
    'a7178e78925ebe4fe6d3730be490adaa21d5462b7d7af5eba1fd176e6d9f8369')
root_file = FIXTURES / 'root_operator.py'
if not root_file.is_file():
    root_file = FALLBACK / 'root_operator.py'
root_api = load('transport_original_root_package', root_file,
    '90d9dff5ff0fcadb4854f5f0dc2103dcfe8b9b25ef9922164fba1b6e690a03e9')


class GitHubBoundaryReached(Exception):
    pass


class CandidatePackage(unittest.TestCase):
    def git(self, root, *args):
        env = {'PATH': '/usr/bin:/bin', 'GIT_CONFIG_NOSYSTEM': '1',
               'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null',
               'GIT_AUTHOR_NAME': 'Isolated acceptance fixture',
               'GIT_AUTHOR_EMAIL': 'fixture@localhost',
               'GIT_COMMITTER_NAME': 'Isolated acceptance fixture',
               'GIT_COMMITTER_EMAIL': 'fixture@localhost'}
        return subprocess.check_output(['git', '-c', 'core.hooksPath=/dev/null',
            '-c', 'commit.gpgSign=false', '-C', str(root), *args],
            env=env, stderr=subprocess.DEVNULL)

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-acceptance-package-')
        self.addCleanup(temporary.cleanup)
        folder = Path(temporary.name)
        self.origin = folder / 'origin'; self.origin.mkdir()
        self.git(self.origin, 'init', '--quiet', '--template=')
        (self.origin / 'README.md').write_text('Isolated seed\n')
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '--quiet', '-m', 'isolated seed')
        self.seed = self.git(self.origin, 'rev-parse', 'HEAD').decode().strip()
        self.bare = folder / 'release.git'
        self.bare.mkdir()
        self.git(self.bare, 'init', '--quiet', '--bare', '--template=')
        # This is the initial immutable loader roster: no future candidate yet.
        self.git(self.bare, 'fetch', '--quiet', '--depth=1', str(self.origin), self.seed)
        self.release = root_api.Package(self.bare, self.seed)
        self.sha = lambda raw: hashlib.sha256(raw).hexdigest()
        self.files = {
            '.github/workflows/' + acceptance.NATIVE_WORKFLOW: b'native workflow\n',
            '.github/workflows/' + acceptance.FOUNDATION_WORKFLOW: b'foundation workflow\n',
            acceptance.VERIFIER: b'verifier fixture\n',
            'desktop-pos/src/main.cjs': b'original reviewed native fixture\n',
        }
        self.programs = {name: self.sha(('reviewed ' + name).encode())
                         for name in acceptance.PROGRAMS}
        contract = json.dumps({'programs': self.programs}, sort_keys=True).encode() + b'\n'
        self.files['desktop/workspace-contract.json'] = contract
        for name, raw in self.files.items():
            target = self.origin / name; target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(raw)
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '--quiet', '-m', 'isolated generated preview62')
        self.candidate = self.git(self.origin, 'rev-parse', 'HEAD').decode().strip()
        self.pins = {
            'contract_sha256': self.sha(contract), 'program_hashes': self.programs,
            'workflow_sha256': self.sha(self.files['.github/workflows/' + acceptance.NATIVE_WORKFLOW]),
            'foundation_workflow_sha256': self.sha(self.files['.github/workflows/' + acceptance.FOUNDATION_WORKFLOW]),
            'verifier_sha256': self.sha(self.files[acceptance.VERIFIER]),
            'source_hashes': {'desktop-pos/src/main.cjs': self.sha(self.files['desktop-pos/src/main.cjs'])},
            'electron_version': '39.0.0',
        }
        self.read_config = Mock(return_value={'token': 'isolated-test-value'})
        self.github = Mock(side_effect=GitHubBoundaryReached('source checks reached GitHub'))
        self.state = {'last_remote_sha': self.candidate,
                      'desired': {'commit': self.candidate, 'version': '0.4.4-preview.62', 'pushed': True}}
        self.context = SimpleNamespace(release=self.release, state=lambda: self.state,
            live=Mock(), candidate=Mock(),
            worker=SimpleNamespace(read_config=self.read_config, GitHub=self.github))
        self.io = SimpleNamespace(sha=self.sha, parse=json.loads, canonical=root_api.canonical)
        self.check = acceptance.WorkspaceAcceptance(self.io, self.context, folder / 'audit',
            lambda: True, pins=self.pins)

    def collect(self, candidate=None):
        return self.check._collect(candidate or self.candidate, '0.4.4-preview.62',
                                  self.pins['contract_sha256'])

    def fetch(self, revision):
        # Match the loader's exact-commit fetch into the private copied package.
        self.release.git('fetch', '--quiet', '--depth=1', '--no-tags',
                         '--no-recurse-submodules', str(self.origin), revision)
        self.assertEqual(self.release.git('rev-parse', revision + '^{commit}').strip(), revision.encode())

    def test_missing_future_candidate_reproduces_first_frozen_source_read_failure(self):
        with self.assertRaisesRegex(root_api.Stop, 'ROOT_PINNED_GIT_READ_FAILED'):
            self.collect()
        self.context.live.assert_called_once_with(self.state)
        self.context.candidate.assert_called_once_with(self.state)
        self.read_config.assert_not_called()
        self.github.assert_not_called()

    def test_exact_candidate_fetch_passes_all_original_source_pins_before_github(self):
        self.fetch(self.candidate)
        with self.assertRaises(GitHubBoundaryReached):
            self.collect()
        self.read_config.assert_called_once_with()
        self.github.assert_called_once_with('isolated-test-value')
        self.assertEqual(self.release.git('show', self.candidate + ':' + acceptance.VERIFIER),
                         self.files[acceptance.VERIFIER])

    def test_changed_workflow_native_or_contract_still_fails_before_github(self):
        cases = [('.github/workflows/' + acceptance.NATIVE_WORKFLOW, 'WORKFLOW_SOURCE_PIN_CHANGED'),
                 ('desktop-pos/src/main.cjs', 'NATIVE_PROOF_SOURCE_HASH_CHANGED'),
                 ('desktop/workspace-contract.json', 'PACKAGED_PROGRAM_CONTRACT_CHANGED')]
        for name, reason in cases:
            with self.subTest(name=name):
                self.git(self.origin, 'checkout', '--quiet', '--detach', self.candidate)
                (self.origin / name).write_bytes(b'changed candidate bytes\n')
                self.git(self.origin, 'add', '.')
                self.git(self.origin, 'commit', '--quiet', '-m', 'isolated unapproved change')
                changed = self.git(self.origin, 'rev-parse', 'HEAD').decode().strip()
                self.fetch(changed)
                self.state['last_remote_sha'] = self.state['desired']['commit'] = changed
                with self.assertRaisesRegex(acceptance.Stop, reason):
                    self.collect(changed)
                self.read_config.assert_not_called()
                self.github.assert_not_called()

    def test_resume_loader_roster_includes_the_exact_existing_canonical62(self):
        loader = load('transport_successor_roster',
            BASE / 'deployment/run_go_erp_desktop_audit_successor_root.py')
        self.assertIn(CANONICAL_62, loader.DEPENDENCY_REVISIONS)


if __name__ == '__main__':
    unittest.main()
