#!/usr/bin/env python3
"""Exercise the exact release hunks, additive migration and guarded rollback paths."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
ACTUAL_BASE = '00e4f1f7df6a9eede836600f834cef6c33d55e26'
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
SPEC = importlib.util.spec_from_file_location(
    'order_live_activity_installer', ROOT / 'deployment/apply_order_live_activity.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)
MODIFIED = installer.MODIFIED_PATHS[0]
NEW = installer.NEW_PATHS[0]


def repository_blob(revision, relative):
    return subprocess.run(['git', '-C', str(ROOT), 'show', revision + ':' + relative],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout


class RealSourceFixture:
    def __init__(self, folder):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Live Activity Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.before = {path: repository_blob(ACTUAL_BASE, path) for path in installer.MODIFIED_PATHS}
        self.expected = {path: (ROOT / path).read_bytes() for path in installer.SOURCE_PATHS}
        for path, body in self.before.items():
            self.write(path, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual activated backend PHP')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for path, body in self.expected.items():
            self.write(path, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual order tracking and Live Activity PHP')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        # Source directories are deployment prerequisites and are never created by this installer.
        for path in installer.SOURCE_PATHS:
            (self.root / path).parent.mkdir(parents=True, exist_ok=True)
        for path in self.before:
            (self.root / path).chmod(0o640)
        self.schema = {'present': False, 'recorded': False}
        self.commands = []
        self.fail_migration = False
        self.fail_post_lint = False
        self.fail_route_clear = False
        self.linted = []
        self.sources_when_migrating = {}
        self.real_run = installer.run

    @property
    def private(self):
        return self.root.parent / 'order-live-activity-backups'

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.root), *arguments],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout.decode()

    def write(self, relative, body):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(body)
        target.chmod(0o644)

    def lint(self, _php, directory):
        self.linted.append(directory)
        if PHP:
            installer.lint_sources_original(PHP, directory)
        if directory == self.root and self.fail_post_lint:
            raise RuntimeError('FIXTURE_POSTCHECK_FAILED')

    def run(self, command, cwd=None):
        if len(command) > 2 and command[1] == '-r' and command[2] == installer.ROUTES_PHP:
            return subprocess.CompletedProcess(command, 0, b'ORDER_LIVE_ACTIVITY_ROUTES_OK', b'')
        if len(command) > 1 and command[1] == 'artisan':
            self.commands.append(command)
            if command[2] == 'migrate':
                self.sources_when_migrating = {path: (self.root / path).read_bytes() for path in installer.MODIFIED_PATHS}
                if self.fail_migration:
                    return subprocess.CompletedProcess(command, 1, b'', b'')
                self.schema = {'present': True, 'recorded': True}
            if command[2] == 'route:clear' and self.fail_route_clear:
                return subprocess.CompletedProcess(command, 1, b'', b'')
            return subprocess.CompletedProcess(command, 0, b'', b'')
        return self.real_run(command, cwd=cwd)

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'lint_sources', self.lint),
              mock.patch.object(installer, 'schema_state', lambda *_: self.schema.copy()),
              mock.patch.object(installer, 'run', self.run),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, PHP or 'fixture-php', check_only=check)

    def receipts(self):
        return list(self.private.glob('*/receipt.json'))

    def receipt(self):
        return json.loads(self.receipts()[0].read_text())


installer.lint_sources_original = installer.lint_sources


class OrderLiveActivityInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = RealSourceFixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def assert_original_sources(self):
        for path, before in self.fixture.before.items():
            self.assertEqual((self.fixture.root / path).read_bytes(), before, path)
            self.assertEqual(stat.S_IMODE((self.fixture.root / path).stat().st_mode), 0o640)
        for path in installer.NEW_PATHS:
            self.assertFalse((self.fixture.root / path).exists(), path)

    def test_check_stages_all_candidates_without_source_or_database_changes(self):
        before = {path: (self.fixture.root / path).stat() for path in installer.MODIFIED_PATHS}
        self.assertIsNone(self.fixture.apply(check=True))
        self.assert_original_sources()
        for path, info in before.items():
            after = (self.fixture.root / path).stat()
            self.assertEqual((after.st_ino, after.st_mtime_ns), (info.st_ino, info.st_mtime_ns))
        self.assertEqual(self.fixture.commands, [])
        self.assertFalse(self.fixture.receipts())
        self.assertEqual(len(self.fixture.linted), 1)

    def test_install_backs_up_sources_preserves_modes_and_runs_only_scoped_migration(self):
        backup = self.fixture.apply()
        for path, expected in self.fixture.expected.items():
            target = self.fixture.root / path
            self.assertEqual(target.read_bytes(), expected, path)
            self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o640 if path in self.fixture.before else 0o644)
            if path in self.fixture.before:
                self.assertEqual((backup / path).read_bytes(), self.fixture.before[path])
                self.assertEqual(stat.S_IMODE((backup / path).stat().st_mode), 0o600)
        self.assertEqual([cmd[2:] for cmd in self.fixture.commands], [
            ['migrate', '--force', '--path=' + installer.MIGRATION], ['route:clear']])
        self.assertEqual(self.fixture.sources_when_migrating, self.fixture.before, 'Schema precedes all order hooks and routes')
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertTrue(receipt['migration_attempted'])
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertTrue(receipt['route_cache_cleared'])
        self.assertEqual({row['path'] for row in receipt['files']}, set(installer.SOURCE_PATHS))

    def test_idempotent_rerun_preserves_files_and_skips_migration(self):
        self.fixture.apply()
        before = {path: (self.fixture.root / path).stat() for path in installer.SOURCE_PATHS}
        self.fixture.commands.clear()
        self.assertIsNone(self.fixture.apply())
        for path, info in before.items():
            self.assertEqual((self.fixture.root / path).read_bytes(), self.fixture.expected[path])
            self.assertEqual((self.fixture.root / path).stat().st_ino, info.st_ino)
        self.assertEqual([cmd[2:] for cmd in self.fixture.commands], [['route:clear']])
        self.assertEqual(len(self.fixture.receipts()), 1)

    def test_unrelated_live_edit_is_preserved_by_hunks(self):
        extra = b'\n// Independent live source correction.\n'
        target = self.fixture.root / MODIFIED
        target.write_bytes(self.fixture.before[MODIFIED] + extra)
        backup = self.fixture.apply()
        self.assertEqual(target.read_bytes(), self.fixture.expected[MODIFIED] + extra)
        self.assertEqual((backup / MODIFIED).read_bytes(), self.fixture.before[MODIFIED] + extra)

    def test_conflicting_live_hunk_refuses_without_writes(self):
        before = self.fixture.before[MODIFIED]
        after = self.fixture.expected[MODIFIED]
        removed = next(line for line in before.splitlines(keepends=True)
                       if line not in after.splitlines(keepends=True) and line.strip())
        conflict = before.replace(removed, b'// Unreviewed conflicting source\n', 1)
        (self.fixture.root / MODIFIED).write_bytes(conflict)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + MODIFIED):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / MODIFIED).read_bytes(), conflict)
        self.assertFalse(self.fixture.receipts())
        self.assertEqual(self.fixture.commands, [])
        for path in installer.NEW_PATHS:
            self.assertFalse((self.fixture.root / path).exists())

    def test_new_file_collision_is_never_overwritten(self):
        collision = b'<?php // An independently installed class\n'
        self.fixture.write(NEW, collision)
        with self.assertRaisesRegex(RuntimeError, 'NEW_SOURCE_COLLISION_NEEDS_REVIEW: ' + NEW):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / NEW).read_bytes(), collision)
        self.assertFalse(self.fixture.receipts())
        self.assertEqual(self.fixture.commands, [])

    def test_partial_hunks_refuse_instead_of_duplicating_or_replacing_source(self):
        selected = None
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            for path in installer.MODIFIED_PATHS:
                patch = installer.release_patch(self.fixture.root, self.fixture.release, path)
                header, separator, rest = patch.partition(b'@@ ')
                first_hunk, second, _ = rest.partition(b'\n@@ ')
                if separator and second:
                    selected = (path, header + b'@@ ' + first_hunk + b'\n')
                    break
        self.assertIsNotNone(selected, 'The release must have a multi-hunk modified source')
        path, body = selected
        patch_file = self.fixture.root.parent / 'partial.diff'
        patch_file.write_bytes(body)
        self.assertTrue(installer.patch_apply(self.fixture.root, patch_file))
        observed = (self.fixture.root / path).read_bytes()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + path):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / path).read_bytes(), observed)
        self.assertFalse(self.fixture.receipts())

    def test_migration_failure_restores_only_new_code_and_never_rolls_back_database(self):
        self.fixture.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_ORDER_LIVE_ACTIVITY_MIGRATION_FAILED'):
            self.fixture.apply()
        self.assert_original_sources()
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertEqual(len(self.fixture.commands), 1)
        self.assertEqual(self.fixture.commands[0][2:], ['migrate', '--force', '--path=' + installer.MIGRATION])

    def test_postcheck_failure_reverses_our_exact_hunks_and_removes_new_files(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'FIXTURE_POSTCHECK_FAILED'):
            self.fixture.apply()
        self.assert_original_sources()
        self.assertEqual(self.fixture.receipt()['status'], 'code_restored')
        self.assertEqual(self.fixture.receipt()['review'], [])
        self.assertTrue(self.fixture.schema['recorded'], 'Additive table is never rolled back')

    def test_route_cache_clear_failure_restores_code(self):
        self.fixture.fail_route_clear = True
        with self.assertRaisesRegex(RuntimeError, 'ORDER_LIVE_ACTIVITY_ROUTE_CLEAR_FAILED'):
            self.fixture.apply()
        self.assert_original_sources()
        self.assertEqual(self.fixture.receipt()['status'], 'code_restored')

    def test_live_patch_failure_restores_prior_files_without_whole_source_copy(self):
        real_apply = installer.patch_apply
        def fail_live(directory, patch, reverse=False):
            if directory == self.fixture.root and not reverse and MODIFIED.encode() in patch.read_bytes():
                return False
            return real_apply(directory, patch, reverse)
        with mock.patch.object(installer, 'patch_apply', fail_live):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_PATCH_FAILED: ' + MODIFIED):
                self.fixture.apply()
        self.assert_original_sources()
        self.assertEqual(self.fixture.receipt()['status'], 'code_restored')

    def test_concurrent_source_edit_after_backup_prevents_all_source_writes(self):
        real_save = installer.save_receipt
        concurrent = self.fixture.before[MODIFIED] + b'\n// Concurrent update\n'
        def save_then_edit(path, receipt):
            real_save(path, receipt)
            if receipt['status'] == 'prepared':
                (self.fixture.root / MODIFIED).write_bytes(concurrent)
        with mock.patch.object(installer, 'save_receipt', save_then_edit):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + MODIFIED):
                self.fixture.apply()
        self.assertEqual((self.fixture.root / MODIFIED).read_bytes(), concurrent)
        self.assertEqual(self.fixture.commands, [])
        for path in installer.NEW_PATHS:
            self.assertFalse((self.fixture.root / path).exists())

    def test_concurrent_new_file_after_backup_is_not_removed(self):
        real_save = installer.save_receipt
        concurrent = b'<?php // Newly installed independent file\n'
        def save_then_create(path, receipt):
            real_save(path, receipt)
            if receipt['status'] == 'prepared':
                self.fixture.write(NEW, concurrent)
        with mock.patch.object(installer, 'save_receipt', save_then_create):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + NEW):
                self.fixture.apply()
        self.assertEqual((self.fixture.root / NEW).read_bytes(), concurrent)
        self.assertEqual(self.fixture.commands, [])

    def test_rollback_retains_a_concurrent_installed_source_edit_for_review(self):
        target = self.fixture.root / MODIFIED
        before, info = installer.read_source(target)
        patch = self.fixture.root.parent / 'rollback.diff'
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch.write_bytes(installer.release_patch(self.fixture.root, self.fixture.release, MODIFIED))
        concurrent = self.fixture.expected[MODIFIED] + b'\n// Concurrent installed source update\n'
        target.write_bytes(concurrent)
        review = installer.rollback(self.fixture.root, [(MODIFIED, patch, self.fixture.expected[MODIFIED])],
                                    {MODIFIED: (before, info)})
        self.assertEqual(review, [MODIFIED])
        self.assertEqual(target.read_bytes(), concurrent)

    def test_symlink_and_hardlink_sources_are_rejected(self):
        target = self.fixture.root / MODIFIED
        outside = target.with_name('outside.php')
        target.rename(outside)
        target.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply(check=True)
        target.unlink()
        os.link(outside, target)
        with self.assertRaisesRegex(RuntimeError, 'REGULAR_UNLINKED_SOURCE_REQUIRED'):
            self.fixture.apply(check=True)
        self.assertFalse(self.fixture.receipts())

    def test_release_mode_change_is_rejected(self):
        self.fixture.git('reset', '--hard', self.fixture.release)
        target = self.fixture.root / MODIFIED
        target.chmod(0o755)
        self.fixture.git('add', '.')
        self.fixture.git('commit', '-qm', 'Invalid release mode mutation')
        self.fixture.release = self.fixture.git('rev-parse', 'HEAD').strip()
        self.fixture.git('reset', '--hard', self.fixture.base)
        for path in installer.SOURCE_PATHS:
            (self.fixture.root / path).parent.mkdir(parents=True, exist_ok=True)
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_PATCH_INVALID'):
            self.fixture.apply()

    def test_existing_additive_schema_can_finish_code_install_without_rerunning_migration(self):
        self.fixture.schema = {'present': True, 'recorded': True}
        self.fixture.apply()
        self.assertEqual([cmd[2:] for cmd in self.fixture.commands], [['route:clear']])
        self.assertFalse(self.fixture.receipt()['migration_attempted'])

    def test_executable_existing_source_mode_is_preserved(self):
        target = self.fixture.root / MODIFIED
        target.chmod(0o755)
        self.fixture.apply()
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o755)

    def test_manifest_wrapper_and_patch_contract_exclude_broad_deployment_commands(self):
        self.assertEqual(installer.BASE_REVISION, ACTUAL_BASE)
        self.assertIn(installer.MIGRATION, installer.NEW_PATHS)
        source = (ROOT / 'deployment/apply_order_live_activity.py').read_text()
        wrapper = (ROOT / 'deployment/apply_order_live_activity_root.sh').read_text()
        self.assertIn('activity_base=' + ACTUAL_BASE, wrapper)
        self.assertIn('activity_source=deployment/apply_order_live_activity.py', wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        self.assertIn("'--path=' + MIGRATION", source)
        for text in (source, wrapper):
            for forbidden in ('reset --hard', 'checkout ', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'db:seed'):
                self.assertNotIn(forbidden, text)


class OrderLiveActivityProbeTests(unittest.TestCase):
    def test_schema_probe_requires_consistent_boolean_state(self):
        for payload in ({'present': False, 'recorded': False}, {'present': True, 'recorded': True}):
            with self.subTest(payload=payload), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], 0, json.dumps(payload).encode(), b'')):
                self.assertEqual(installer.schema_state(ROOT, 'php'), payload)
        for payload in ({'present': 1, 'recorded': True}, {'present': True}, {'present': False, 'recorded': True},
                        {'present': True, 'recorded': False}, []):
            with self.subTest(payload=payload), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], 0, json.dumps(payload).encode(), b'')):
                with self.assertRaises(RuntimeError):
                    installer.schema_state(ROOT, 'php')

    def test_route_probe_requires_exact_success_marker(self):
        for code, body in ((1, b'ORDER_LIVE_ACTIVITY_ROUTES_OK'), (0, b''), (0, b'sensitive boot output')):
            with self.subTest(code=code, body=body), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], code, body, b'')):
                with self.assertRaisesRegex(RuntimeError, 'ORDER_LIVE_ACTIVITY_ROUTE_CHECK_FAILED'):
                    installer.verify_routes(ROOT, 'php')

    @unittest.skipUnless(PHP, 'PHP executable unavailable')
    def test_read_only_probe_programs_are_valid_php(self):
        with tempfile.TemporaryDirectory() as folder:
            for index, source in enumerate((installer.SCHEMA_PHP, installer.ROUTES_PHP)):
                candidate = Path(folder) / (str(index) + '.php')
                candidate.write_text('<?php\n' + source)
                result = installer.run([PHP, '-l', str(candidate)])
                self.assertEqual(result.returncode, 0, result.stderr.decode())


if __name__ == '__main__':
    unittest.main()
