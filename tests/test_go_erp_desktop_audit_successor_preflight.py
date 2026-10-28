"""Whole successor preflight against a real ref-less depth-one bare package."""
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from types import ModuleType, SimpleNamespace
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
FIXTURES = BASE / 'tests/fixtures/go_erp_desktop_performance'
if not (FIXTURES / 'seed_policy.py').exists():
    FIXTURES = BASE.parent / 'perf_operator/tests/fixtures/go_erp_desktop_performance'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value


roll = load('actual_shallow_successor_preflight', BASE / 'deployment/upgrade_go_erp_desktop_audit_successor_root.py')
source = load('shallow_preflight_original_git', FIXTURES / 'source_candidate.py')
policy = load('shallow_preflight_original_policy', FIXTURES / 'seed_policy.py')


class WholePreflight(unittest.TestCase):
    def fixture(self):
        temporary = tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        root = Path(temporary.name); checkout = root / 'checkout'; checkout.mkdir(mode=0o700)
        source._git(checkout, ['init', '--quiet', '--template='])

        def write(name, raw):
            path = checkout / name; path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(raw)

        def commit(message):
            source._git(checkout, ['add', '.'])
            source._git(checkout, ['commit', '--quiet', '-m', message])
            return source._git(checkout, ['rev-parse', 'HEAD']).decode().strip()

        write('desktop-pos/package.json', b'{"version":"0.4.4-preview.60"}\n')
        write('desktop-pos/package-lock.json', b'{"version":"0.4.4-preview.60","packages":{"":{"version":"0.4.4-preview.60"}}}\n')
        write('desktop-pos/runtime/composer.lock', b'isolated old dependency lock\n')
        parent = commit('isolated reviewed parent')
        runtime = b'isolated reviewed dependency patch\n'
        write('desktop-pos/runtime/composer.lock', runtime)
        core = commit('exact runtime dependency patch')
        verifier_raw = (FIXTURES / 'seed_policy.py').read_bytes().replace(
            ("CORE = '" + policy.CORE + "'").encode(), ("CORE = '" + core + "'").encode())
        for name in policy.POLICY_FILES:
            write(name, verifier_raw if name == 'deployment/verify_go_erp_preview31_seed.py'
                  else b'# isolated permitted policy fixture\n')
        seed = commit('proper policy seed')
        bare = root / 'package.git'
        subprocess.run(['git', 'init', '--bare', '--quiet', str(bare)], check=True)
        # Explicit separate fetches reproduce the production standalone loader.
        for revision in (seed, core, parent):
            subprocess.run(['git', '--git-dir=' + str(bare), 'fetch', '--quiet', '--depth=1',
                            '--no-tags', checkout.as_uri(), revision], check=True)

        class Package:
            repository = bare
            def git(self, *args):
                return subprocess.check_output(['git', '--git-dir=' + str(bare), *args], stderr=subprocess.DEVNULL)
            def file(self, name, revision):
                return self.git('show', revision + ':' + name)

        def verified_load(raw, name, expected):
            self.assertEqual(hashlib.sha256(raw).hexdigest(), expected)
            value = ModuleType(name); exec(compile(raw, '<actual-policy-verifier>', 'exec'), value.__dict__)
            return value

        value = object.__new__(roll.Successor)
        value.package = Package(); value.io = SimpleNamespace(parse=json.loads)
        value.old = SimpleNamespace(native=SimpleNamespace(BACKEND=('app', 'public', 'composer.json', 'composer.lock')),
                                    root_api=SimpleNamespace(load=verified_load))
        value.modules = {'coordinator': SimpleNamespace(WEB_RELEASE=parent)}
        constants = {'CORE': core, 'CORE_PARENT': parent, 'SEED': seed,
                     'RUNTIME_LOCK_SHA': hashlib.sha256(runtime).hexdigest(),
                     'POLICY_VERIFIER_SHA': hashlib.sha256(verifier_raw).hexdigest()}
        return value, constants

    def test_shallow_parent_is_verified_from_commit_headers_and_whole_preflight_passes(self):
        value, constants = self.fixture()
        core, parent = constants['CORE'], constants['CORE_PARENT']
        self.assertEqual(value.package.git('rev-list', '--parents', '-n', '1', core).decode().split(), [core])
        self.assertIn(('parent ' + parent + '\n').encode(), value.package.git('cat-file', 'commit', core))
        with patch.multiple(roll, **constants):
            value._review_seed()

    def test_actual_other_parent_is_rejected_before_policy_or_runtime_read(self):
        value, constants = self.fixture()
        constants['CORE_PARENT'] = 'f' * 40
        with patch.multiple(roll, **constants), self.assertRaisesRegex(roll.Stop, 'CORE_PARENT_CHANGED'):
            value._review_seed()


if __name__ == '__main__':
    unittest.main()
