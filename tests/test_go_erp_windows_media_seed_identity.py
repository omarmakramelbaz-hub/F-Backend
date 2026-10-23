"""Independent immutable proof for the Windows fixture-only core and policy seed.

The confirmed 8.3 alias diagnosis permits canonicalizing three freshly created
test directories. It does not authorize a production path-guard change, an
assertion waiver, a workflow change in the core, or a different seed policy.
"""
from pathlib import Path
import subprocess
import tempfile
import types
import unittest


ROOT = Path(__file__).resolve().parents[1]
OLD_CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
OLD_SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
CORE = '4e0c33197b6d9cd635e3396e1edbf567bfb3a020'
SEED = 'e64e69667198b7dceddd4dfe516d963f43bbae27'
FIXTURES = {
    'desktop-pos/test/generation-native.cjs': (
        b"  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-native-generation-'));",
        b'  // Resolve Windows 8.3 aliases on this own fresh fixture root before the\n'
        b'  // unmodified media guard checks its descendants for redirected paths.\n'
        b"  const profile = await fs.realpath(await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-native-generation-')));"),
    'desktop-pos/test/media.test.cjs': (
        b"  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-'));",
        b'  // A fresh fixture may be created through the Windows TEMP 8.3 alias.\n'
        b"  const directory = await fs.realpath(await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-')));"),
    'desktop-pos/test/media-reuse.test.cjs': (
        b"  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-reuse-'));",
        b'  // Canonicalize only this own fresh fixture root; production checks stay strict.\n'
        b"  const directory = await fs.realpath(await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-media-reuse-')));"),
}
POLICY_FILES = {
    '.github/workflows/go-erp-update-tests.yml',
    'deployment/verify_go_erp_preview31_seed.py',
    'tests/test_go_erp_preview31_seed.py',
}


def git(*arguments):
    return subprocess.check_output(['git', '-c', 'credential.helper=',
        '-c', 'core.hooksPath=/dev/null', '-c', 'core.autocrlf=false',
        '-C', str(ROOT), *arguments], stderr=subprocess.PIPE)


def contents(revision, path):
    return git('show', revision + ':' + path)


def parents(revision):
    header = git('cat-file', 'commit', revision).split(b'\n\n', 1)[0]
    return [line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')]


def paths(before, after):
    return set(git('diff', '--name-only', '--no-renames', '-z', before, after, '--').decode().split('\0')) - {''}


class WindowsMediaSeedIdentityTests(unittest.TestCase):
    def test_core_changes_exactly_three_fresh_fixture_roots(self):
        self.assertEqual(parents(CORE), [OLD_SEED])
        self.assertEqual(paths(OLD_SEED, CORE), set(FIXTURES))
        for path, (old, new) in FIXTURES.items():
            with self.subTest(path=path):
                before = contents(OLD_SEED, path)
                self.assertEqual(before.count(old), 1)
                self.assertEqual(contents(CORE, path), before.replace(old, new))
                original = git('ls-tree', OLD_SEED, '--', path).split()[0]
                self.assertEqual(git('ls-tree', CORE, '--', path).split()[0], original)

    def test_every_production_tree_and_windows_acceptance_assertion_is_unchanged(self):
        # Git tree identities cover all descendant blob bytes AND file modes,
        # including every native module and the original media path guard.
        for path in ('desktop-pos/src', 'app', 'bootstrap', 'config', 'database',
                     'desktop', 'public', 'resources', 'routes'):
            with self.subTest(path=path):
                self.assertEqual(git('rev-parse', CORE + ':' + path),
                                 git('rev-parse', OLD_SEED + ':' + path))
        for path in ('.github/workflows/desktop-dashboard-tests.yml',
                     'tests/desktop_dashboard_runtime/legacy.php',
                     'tests/desktop_dashboard_runtime/browser.cjs',
                     'desktop-pos/package.json', 'desktop-pos/package-lock.json'):
            self.assertEqual(contents(CORE, path), contents(OLD_SEED, path), path)
        for path in ('composer.json', 'composer.lock'):
            # The historical root has no tracked composer.lock. Preserve that
            # absence as well as exact bytes/mode for any tracked root record.
            self.assertEqual(git('ls-tree', CORE, '--', path),
                             git('ls-tree', OLD_SEED, '--', path), path)

    def test_seed_changes_only_the_three_existing_core_pin_literals(self):
        self.assertEqual(parents(SEED), [CORE])
        self.assertEqual(paths(CORE, SEED), POLICY_FILES)
        for path in POLICY_FILES:
            before = contents(CORE, path)
            self.assertGreater(before.count(OLD_CORE.encode()), 0, path)
            self.assertEqual(contents(SEED, path), before.replace(OLD_CORE.encode(), CORE.encode()), path)
            self.assertEqual(git('ls-tree', SEED, '--', path).split()[0], b'100644')
        for path in (*FIXTURES, 'desktop-pos/src', 'app', 'desktop', 'public', 'resources', 'routes'):
            self.assertEqual(git('rev-parse', SEED + ':' + path), git('rev-parse', CORE + ':' + path), path)
        workflow = contents(SEED, '.github/workflows/go-erp-update-tests.yml')
        self.assertIn(b'origin ' + CORE.encode(), workflow)
        self.assertIn(b'test_go_erp_preview31_seed.py', workflow)

    def test_actual_new_seed_validator_enforces_the_new_immutable_lineage(self):
        name = 'deployment/verify_go_erp_preview31_seed.py'
        validator = types.ModuleType('actual_windows_media_seed_policy')
        validator.__file__ = str(ROOT / name)
        exec(compile(contents(SEED, name), str(ROOT / name), 'exec'), validator.__dict__)
        self.assertEqual(validator.CORE, CORE)
        self.assertEqual(validator.POLICY, 'native-program-center-seed-v1')
        self.assertEqual(set(validator.POLICY_FILES), POLICY_FILES)
        # Match the server's private staged checkout ownership. CI invokes this
        # proof as root over a runner-owned source checkout; the real validator
        # deliberately drops SUDO_UID and must retain Git's ownership refusal.
        with tempfile.TemporaryDirectory(prefix='windows-media-seed-policy-') as temporary:
            repository = Path(temporary) / 'seed.git'
            subprocess.check_call(['git', '-c', 'core.hooksPath=/dev/null',
                                   'init', '--quiet', '--bare', str(repository)])
            common = Path(git('rev-parse', '--git-common-dir').decode().strip())
            if not common.is_absolute():
                common = (ROOT / common).resolve()
            (repository / 'objects/info/alternates').write_text(str(common / 'objects') + '\n')
            self.assertEqual(validator.validate_seed(repository, SEED),
                             {'core': CORE, 'seed': SEED, 'policy_files': sorted(POLICY_FILES)})
            with self.assertRaisesRegex(validator.SeedError, 'POLICY_PARENT_INVALID'):
                validator.validate_seed(repository, OLD_SEED)
            with self.assertRaisesRegex(validator.SeedError, 'POLICY_SEED_REQUIRED'):
                validator.validate_seed(repository, CORE)


if __name__ == '__main__':
    unittest.main()
