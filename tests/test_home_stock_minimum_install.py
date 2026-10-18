#!/usr/bin/env python3
"""Exercise real Git hunks in disposable homes, without any application/database calls."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('home_stock_minimum_installer', ROOT / 'deployment/apply_home_stock_minimum.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class Fixture:
    def __init__(self, folder):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.before, self.after = {}, {}
        self.fail_stage_lint = False
        self.fail_live_lint = False
        self.concurrent = None
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for number, relative in enumerate(installer.SOURCE_PATHS):
            before = ('file-' + str(number) + '\n' + ''.join('context-' + str(i) + '\n' for i in range(10))
                      + 'old-behavior\n' + ''.join('tail-' + str(i) + '\n' for i in range(10))).encode()
            self.before[relative] = before
            self.after[relative] = before.replace(b'old-behavior', b'new-reviewed-behavior')
            self.write(relative, before)
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for relative, after in self.after.items():
            self.write(relative, after)
        self.git('add', '.')
        self.git('commit', '-qm', 'reviewed release')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        for relative in installer.SOURCE_PATHS:
            (self.root / relative).chmod(0o640)

    def git(self, *args):
        result = subprocess.run(['git', '-C', str(self.root), *args], check=True,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        return result.stdout.decode()

    def write(self, relative, body):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)

    def lint(self, php, directory):
        for relative in installer.SOURCE_PATHS:
            assert (directory / relative).is_file()
        if directory != self.root and self.fail_stage_lint:
            raise RuntimeError('PHP_PARSE_INVALID')
        if directory == self.root and self.fail_live_lint:
            if self.concurrent:
                self.write(self.concurrent, self.after[self.concurrent] + b'\nindependent concurrent edit\n')
            raise RuntimeError('PHP_PARSE_INVALID')

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, 'fixture-php', check_only=check)

    def assert_originals(self, case):
        for relative, before in self.before.items():
            case.assertEqual((self.root / relative).read_bytes(), before)
            case.assertEqual((self.root / relative).stat().st_mode & 0o777, 0o640)

    def receipt(self):
        private = self.root.parent / 'home-stock-minimum-backups'
        return json.loads(next(private.glob('*/receipt.json')).read_text())


class ActualSourceFixture(Fixture):
    """Apply this release's real source hunks onto the pinned deployment baseline."""
    def __init__(self, folder):
        super().__init__(folder)
        self.before = {}
        for relative in installer.SOURCE_PATHS:
            self.before[relative] = subprocess.run(
                ['git', '-C', str(ROOT), 'show', installer.BASE_REVISION + ':' + relative],
                check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
            self.write(relative, self.before[relative])
        self.git('add', '.')
        self.git('commit', '-qm', 'real source baseline')
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.after = {relative: (ROOT / relative).read_bytes() for relative in installer.SOURCE_PATHS}
        for relative, after in self.after.items():
            self.write(relative, after)
        self.git('add', '.')
        self.git('commit', '-qm', 'real homepage source hunks')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        for relative in installer.SOURCE_PATHS:
            # Keep independent repairs far outside feature hunks, as on the live site.
            correction = b'\n// Independent live correction retained.\n' if relative.endswith('.php') else b'\n/* Independent live correction retained. */\n'
            self.before[relative] += correction
            self.after[relative] += correction
            self.write(relative, self.before[relative])
            (self.root / relative).chmod(0o640)


class HomeMinimumInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = Fixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_preflight_does_not_change_any_live_source_or_back_up(self):
        snapshots = {rel: installer.read_source(self.fixture.root / rel) for rel in installer.SOURCE_PATHS}
        self.assertIsNone(self.fixture.apply(check=True))
        for relative, before in snapshots.items():
            self.assertTrue(installer.unchanged(self.fixture.root / relative, before))
        private = self.fixture.root.parent / 'home-stock-minimum-backups'
        self.assertEqual([path.name for path in private.iterdir()], ['install.lock'])

    def test_install_preserves_unrelated_live_edits_and_file_modes(self):
        for relative in installer.SOURCE_PATHS:
            drift = b'\nindependent live correction\n'
            self.fixture.before[relative] += drift
            self.fixture.after[relative] += drift
            self.fixture.write(relative, self.fixture.before[relative])
        backup = self.fixture.apply()
        self.assertTrue(backup.is_dir())
        self.assertFalse(backup.is_relative_to(self.fixture.root))
        for relative, after in self.fixture.after.items():
            self.assertEqual((self.fixture.root / relative).read_bytes(), after)
            self.assertEqual((self.fixture.root / relative).stat().st_mode & 0o777, 0o640)
            self.assertEqual((backup / relative).read_bytes(), self.fixture.before[relative])
        self.assertEqual(self.fixture.receipt()['status'], 'installed')

    def test_rerun_is_idempotent_without_rewriting_sources(self):
        self.fixture.apply()
        snapshots = {rel: installer.read_source(self.fixture.root / rel) for rel in installer.SOURCE_PATHS}
        self.assertIsNone(self.fixture.apply())
        for relative, before in snapshots.items():
            self.assertTrue(installer.unchanged(self.fixture.root / relative, before))
        self.assertEqual(len(list((self.fixture.root.parent / 'home-stock-minimum-backups').glob('*/receipt.json'))), 1)

    def test_partially_installed_release_changes_only_remaining_files(self):
        relative = installer.SOURCE_PATHS[0]
        self.fixture.write(relative, self.fixture.after[relative])
        snapshot = installer.read_source(self.fixture.root / relative)
        self.fixture.apply()
        self.assertTrue(installer.unchanged(self.fixture.root / relative, snapshot))
        self.assertEqual(len(self.fixture.receipt()['files']), len(installer.SOURCE_PATHS) - 1)

    def test_overlap_fails_before_any_live_source_write(self):
        relative = installer.SOURCE_PATHS[-1]
        self.fixture.before[relative] = self.fixture.before[relative].replace(b'old-behavior', b'unknown-live-behavior')
        self.fixture.write(relative, self.fixture.before[relative])
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.fixture.apply()
        self.fixture.assert_originals(self)

    def test_unwritable_destination_is_found_in_preflight(self):
        blocked = self.fixture.root / 'public/dashboard/js'
        real_access = os.access
        def access(path, mode, **kwargs):
            return False if Path(path) == blocked else real_access(path, mode, **kwargs)
        with mock.patch.object(installer.os, 'access', access):
            with self.assertRaisesRegex(RuntimeError, 'DESTINATION_PARENT_NOT_WRITABLE'):
                self.fixture.apply(check=True)
        self.fixture.assert_originals(self)

    def test_symlink_source_is_rejected(self):
        source = self.fixture.root / installer.SOURCE_PATHS[0]
        elsewhere = self.fixture.root.parent / 'elsewhere'
        source.rename(elsewhere)
        source.symlink_to(elsewhere)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply()
        self.assertEqual(elsewhere.read_bytes(), self.fixture.before[installer.SOURCE_PATHS[0]])

    def test_hardlinked_source_is_rejected(self):
        source = self.fixture.root / installer.SOURCE_PATHS[0]
        os.link(source, self.fixture.root.parent / 'linked-source')
        with self.assertRaisesRegex(RuntimeError, 'REGULAR_UNLINKED_SOURCE_REQUIRED'):
            self.fixture.apply()
        self.fixture.assert_originals(self)

    def test_invalid_staged_source_stops_without_a_backup(self):
        self.fixture.fail_stage_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.fixture.assert_originals(self)
        self.assertEqual(list((self.fixture.root.parent / 'home-stock-minimum-backups').glob('*/receipt.json')), [])

    def test_post_install_failure_restores_exact_live_bytes_and_mode(self):
        self.fixture.fail_live_lint = True
        for relative in installer.SOURCE_PATHS:
            self.fixture.before[relative] += b'\nindependent live correction\n'
            self.fixture.after[relative] += b'\nindependent live correction\n'
            self.fixture.write(relative, self.fixture.before[relative])
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.fixture.assert_originals(self)
        self.assertEqual(self.fixture.receipt()['status'], 'rolled_back')

    def test_rollback_preserves_independent_concurrent_edit_for_review(self):
        self.fixture.fail_live_lint = True
        concurrent = installer.SOURCE_PATHS[0]
        self.fixture.concurrent = concurrent
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / concurrent).read_bytes(),
                         self.fixture.after[concurrent] + b'\nindependent concurrent edit\n')
        for relative in installer.SOURCE_PATHS[1:]:
            self.assertEqual((self.fixture.root / relative).read_bytes(), self.fixture.before[relative])
        self.assertEqual(self.fixture.receipt()['status'], 'rollback_review_required')
        self.assertEqual(self.fixture.receipt()['review'], [concurrent])

    def test_change_during_backup_is_detected_before_publish(self):
        real_save = installer.save_receipt
        changed = installer.SOURCE_PATHS[0]
        def save(path, receipt):
            real_save(path, receipt)
            self.fixture.write(changed, self.fixture.before[changed] + b'\nconcurrent source correction\n')
        with mock.patch.object(installer, 'save_receipt', save):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_INSTALL'):
                self.fixture.apply()
        for relative in installer.SOURCE_PATHS[1:]:
            self.assertEqual((self.fixture.root / relative).read_bytes(), self.fixture.before[relative])
        self.assertTrue((self.fixture.root / changed).read_bytes().endswith(b'concurrent source correction\n'))

    def test_mode_change_in_release_is_rejected(self):
        self.fixture.git('checkout', '-q', self.fixture.release)
        (self.fixture.root / installer.SOURCE_PATHS[0]).chmod(0o755)
        self.fixture.git('add', '.')
        self.fixture.git('commit', '-qm', 'unreviewed mode change')
        self.fixture.release = self.fixture.git('rev-parse', 'HEAD').strip()
        self.fixture.git('reset', '--hard', self.fixture.base)
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_PATCH_INVALID'):
            self.fixture.apply()

    def test_lint_never_bootstraps_application(self):
        observed = []
        def run(command, cwd=None):
            observed.append(command)
            return subprocess.CompletedProcess(command, 0, stdout=b'', stderr=b'')
        with (mock.patch.object(installer, 'run', run),
              mock.patch.object(installer.shutil, 'which', lambda name: 'fixture-node')):
            installer.lint_sources('fixture-php', self.fixture.root)
        self.assertEqual(len(observed), 5)
        self.assertTrue(all(command[:2] in (['fixture-php', '-l'], ['fixture-node', '--check']) for command in observed))
        self.assertFalse(any('artisan' in command for command in observed))

    def test_failed_git_write_diagnostic_redacts_raw_output(self):
        response = subprocess.CompletedProcess(['git'], 128, stdout=b'', stderr=b'Permission denied PRIVATE_DETAIL')
        output = io.StringIO()
        with (mock.patch.object(installer, 'run', lambda *args, **kwargs: response),
              contextlib.redirect_stdout(output)):
            self.assertFalse(installer.patch_apply(self.fixture.root, self.fixture.root / 'release.diff'))
        self.assertIn('category=permission_denied exit_code=128', output.getvalue())
        self.assertNotIn('PRIVATE_DETAIL', output.getvalue())


class ActualHomeSourceInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = ActualSourceFixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_real_feature_hunks_preserve_independent_corrections_and_rerun(self):
        self.fixture.apply(check=True)
        self.fixture.assert_originals(self)
        self.fixture.apply()
        for relative, after in self.fixture.after.items():
            self.assertEqual((self.fixture.root / relative).read_bytes(), after)
        snapshots = {relative: installer.read_source(self.fixture.root / relative) for relative in installer.SOURCE_PATHS}
        self.fixture.apply()
        for relative, snapshot in snapshots.items():
            self.assertTrue(installer.unchanged(self.fixture.root / relative, snapshot))

    def test_real_feature_hunks_rollback_preserves_independent_corrections(self):
        self.fixture.fail_live_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.fixture.assert_originals(self)
        self.assertEqual(self.fixture.receipt()['status'], 'rolled_back')


if __name__ == '__main__':
    unittest.main()
