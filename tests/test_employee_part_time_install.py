#!/usr/bin/env python3
"""Real Git hunks in private fixtures; schema/migration calls never access a database."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('employee_part_time_installer', ROOT / 'deployment/apply_employee_part_time.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class Fixture:
    def __init__(self, folder):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.calls = []
        self.state = {'present': False, 'recorded': False}
        self.fail_migration = False
        self.fail_post_lint = False
        self.schema_bad = False
        self.before = {}
        self.after = {}
        self.real_run = installer.run
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for index, relative in enumerate(installer.MODIFIED_PATHS):
            before = ('prefix-' + str(index) + '\n' + ''.join('context-' + str(i) + '\n' for i in range(10))
                      + 'old-behavior\n' + ''.join('tail-' + str(i) + '\n' for i in range(10))).encode()
            self.before[relative] = before
            self.after[relative] = before.replace(b'old-behavior', b'new-reviewed-behavior')
            self.write(relative, before)
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for relative, after in self.after.items():
            self.write(relative, after)
        for relative in installer.NEW_PATHS:
            self.after[relative] = b'new reviewed service or migration\n'
            self.write(relative, self.after[relative])
        self.git('add', '.')
        self.git('commit', '-qm', 'reviewed release')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        for relative in installer.MODIFIED_PATHS:
            (self.root / relative).chmod(0o640)

    def git(self, *args):
        result = subprocess.run(['git', '-C', str(self.root), *args], check=True,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        return result.stdout.decode()

    def write(self, relative, body):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)

    def run(self, command, cwd=None):
        if command[:2] == ['fixture-php', 'artisan']:
            self.calls.append(tuple(command))
            # Callers must remain at their live versions until the schema exists.
            for relative, before in self.before.items():
                assert (self.root / relative).read_bytes().startswith(before)
            for relative in installer.NEW_PATHS:
                assert (self.root / relative).read_bytes() == self.after[relative]
            if not self.fail_migration:
                self.state = {'present': True, 'recorded': True}
            return subprocess.CompletedProcess(command, 1 if self.fail_migration else 0,
                                               stdout=b'PRIVATE_DATABASE_DETAIL', stderr=b'')
        return self.real_run(command, cwd)

    def schema(self, root, php):
        if self.schema_bad:
            raise RuntimeError('EMPLOYEE_PART_TIME_SCHEMA_NEEDS_REVIEW')
        return dict(self.state)

    def lint(self, php, stage, root, cache):
        for relative in installer.SOURCE_PATHS:
            assert (stage / relative).is_file()
        if stage == root and self.fail_post_lint:
            raise RuntimeError('PHP_PARSE_INVALID')

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'run', self.run),
              mock.patch.object(installer, 'schema_state', self.schema),
              mock.patch.object(installer, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, 'fixture-php', check_only=check)

    def originals(self, case):
        for relative, before in self.before.items():
            case.assertEqual((self.root / relative).read_bytes(), before)
        for relative in installer.NEW_PATHS:
            case.assertFalse((self.root / relative).exists())

    def receipt(self):
        return json.loads(next((self.root.parent / 'employee-part-time-backups').glob('*/receipt.json')).read_text())


class EmployeePartTimeInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = Fixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_check_only_does_not_publish_files_or_migrate(self):
        self.assertIsNone(self.fixture.apply(check=True))
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])
        private = self.fixture.root.parent / 'employee-part-time-backups'
        self.assertEqual([path.name for path in private.iterdir()], ['install.lock'])

    def test_install_preserves_unrelated_live_edits_and_modes(self):
        relative = installer.MODIFIED_PATHS[0]
        suffix = b'\nunrelated live production correction\n'
        self.fixture.write(relative, self.fixture.before[relative] + suffix)
        (self.fixture.root / relative).chmod(0o640)
        backup = self.fixture.apply()
        self.assertTrue(backup.is_dir())
        for path, after in self.fixture.after.items():
            self.assertEqual((self.fixture.root / path).read_bytes(), after + (suffix if path == relative else b''))
            self.assertEqual(stat.S_IMODE((self.fixture.root / path).stat().st_mode),
                             0o644 if path in installer.NEW_PATHS else 0o640)
        self.assertEqual(self.fixture.calls, [('fixture-php', 'artisan', 'migrate', '--force', '--path=' + installer.MIGRATION)])
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertEqual((backup / relative).read_bytes(), self.fixture.before[relative] + suffix)

    def test_complete_rerun_is_idempotent_and_does_not_remigrate(self):
        self.fixture.apply()
        contents = {relative: (self.fixture.root / relative).read_bytes() for relative in installer.SOURCE_PATHS}
        self.assertIsNone(self.fixture.apply())
        self.assertEqual(len(self.fixture.calls), 1)
        self.assertEqual(contents, {relative: (self.fixture.root / relative).read_bytes() for relative in installer.SOURCE_PATHS})

    def test_hunk_conflict_stops_before_migration_or_any_live_write(self):
        relative = installer.MODIFIED_PATHS[-1]
        conflicting = self.fixture.before[relative].replace(b'old-behavior', b'unreviewed custom behavior')
        self.fixture.write(relative, conflicting)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / relative).read_bytes(), conflicting)
        self.assertFalse((self.fixture.root / installer.NEW_PATHS[0]).exists())
        self.assertEqual(self.fixture.calls, [])

    def test_new_file_collision_is_never_replaced(self):
        relative = installer.NEW_PATHS[0]
        self.fixture.write(relative, b'local custom service\n')
        with self.assertRaisesRegex(RuntimeError, 'NEW_SOURCE_COLLISION_NEEDS_REVIEW'):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / relative).read_bytes(), b'local custom service\n')
        self.assertEqual(self.fixture.calls, [])

    def test_schema_preflight_failure_leaves_all_sources_untouched(self):
        self.fixture.schema_bad = True
        with self.assertRaisesRegex(RuntimeError, 'SCHEMA_NEEDS_REVIEW'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])

    def test_failed_migration_removes_new_files_and_reports_database_review(self):
        self.fixture.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_EMPLOYEE_PART_TIME_MIGRATION_FAILED'):
            self.fixture.apply()
        self.fixture.originals(self)
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['migration_attempted'])
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertFalse(receipt['database_rollback_attempted'])

    def test_post_migration_failure_restores_all_code_without_dropping_database(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertTrue(all(self.fixture.state.values()))
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertEqual(len(self.fixture.calls), 1)

    def test_rollback_refuses_to_overwrite_a_concurrent_live_edit(self):
        relative = installer.MODIFIED_PATHS[0]
        before, info = installer.read_source(self.fixture.root / relative)
        after = self.fixture.after[relative]
        self.fixture.write(relative, after)
        patch = self.fixture.root.parent / 'rollback.diff'
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch.write_bytes(installer.release_patch(self.fixture.root, self.fixture.release, relative))
        self.fixture.write(relative, after + b'concurrent edit\n')
        review = installer.rollback(self.fixture.root, [(relative, patch, after)], {relative: (before, info)})
        self.assertEqual(review, [relative])
        self.assertEqual((self.fixture.root / relative).read_bytes(), after + b'concurrent edit\n')

    def test_symlink_sources_are_rejected(self):
        relative = installer.MODIFIED_PATHS[0]
        target = self.fixture.root / relative
        source = target.with_name('outside.php')
        target.rename(source)
        target.symlink_to(source)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply(check=True)
        self.assertEqual(self.fixture.calls, [])

    def test_manifest_only_allows_the_declared_single_new_migration(self):
        self.assertEqual([relative for relative in installer.SOURCE_PATHS if relative.startswith('database/')],
                         [installer.MIGRATION])
        self.assertFalse(any(relative == '.env' or relative.startswith('config/') for relative in installer.SOURCE_PATHS))
        wrapper = (ROOT / 'deployment/apply_employee_part_time_root.sh').read_text()
        self.assertIn(installer.BASE_REVISION, wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        self.assertIn('--revision "$part_time_revision"', wrapper)

    def test_schema_reply_rejects_missing_or_non_boolean_fields(self):
        for reply in (b'PRIVATE_SQL_EXCEPTION', b'{}', b'{"present":1,"recorded":false}'):
            with self.subTest(reply=reply), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], 0, stdout=reply, stderr=b'PRIVATE_DETAIL')):
                with self.assertRaisesRegex(RuntimeError, '^EMPLOYEE_PART_TIME_SCHEMA_NEEDS_REVIEW$'):
                    installer.schema_state(self.fixture.root, 'fixture-php')

    def test_recorded_migration_must_have_all_schedule_columns(self):
        with mock.patch.object(installer, 'run', return_value=
                subprocess.CompletedProcess([], 0, stdout=b'{"present":false,"recorded":true}', stderr=b'')):
            with self.assertRaisesRegex(RuntimeError, 'RECORDED_MIGRATION_INCOMPLETE'):
                installer.schema_state(self.fixture.root, 'fixture-php')


if __name__ == '__main__':
    unittest.main()
