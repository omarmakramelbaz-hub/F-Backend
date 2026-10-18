#!/usr/bin/env python3
"""Exercise the submit hotfix against actual deployed and current JavaScript bytes."""
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
ACTUAL_BASE = '754bf038af50dcade0d43f2f6b537a5b155de4d0'
JS = 'public/dashboard/js/branch-expenses.js'
SPEC = importlib.util.spec_from_file_location(
    'expense_category_submit_installer', ROOT / 'deployment/apply_expense_category_submit.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


def repository_blob(revision, relative):
    return subprocess.run(['git', '-C', str(ROOT), 'show', revision + ':' + relative],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout


class RealSourceFixture:
    def __init__(self, folder):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Expense Submit Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.before = repository_blob(ACTUAL_BASE, JS)
        self.expected = (ROOT / JS).read_bytes()
        if self.before == self.expected:
            raise AssertionError('The actual hotfix must modify its one source path')
        self.write(self.before)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual activated expense JavaScript')
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.write(self.expected)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual category submit hotfix JavaScript')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        self.target.chmod(0o640)
        self.real_lint = installer.lint_sources
        self.fail_post_lint = False

    @property
    def target(self):
        return self.root / JS

    @property
    def private(self):
        return self.root.parent / 'expense-category-submit-backups'

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.root), *arguments],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout.decode()

    def write(self, body):
        self.target.parent.mkdir(parents=True, exist_ok=True)
        self.target.write_bytes(body)

    def lint(self, directory):
        self.real_lint(directory)
        if directory == self.root and self.fail_post_lint:
            raise RuntimeError('FIXTURE_POSTCHECK_FAILED')

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, check_only=check)

    def receipts(self):
        return list(self.private.glob('*/receipt.json'))

    def receipt(self):
        return json.loads(self.receipts()[0].read_text())


class ExpenseCategorySubmitInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = RealSourceFixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_check_only_parses_real_javascript_without_source_writes(self):
        before = self.fixture.target.stat()
        self.assertIsNone(self.fixture.apply(check=True))
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.before)
        after = self.fixture.target.stat()
        self.assertEqual(after.st_ino, before.st_ino)
        self.assertEqual(after.st_mtime_ns, before.st_mtime_ns)
        self.assertEqual(stat.S_IMODE(after.st_mode), 0o640)
        self.assertEqual([path.name for path in self.fixture.private.iterdir()], ['install.lock'])

    def test_install_backs_up_actual_source_and_retains_mode_owner_and_group(self):
        before = self.fixture.target.stat()
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.expected)
        self.assertEqual((backup / JS).read_bytes(), self.fixture.before)
        after = self.fixture.target.stat()
        self.assertEqual(stat.S_IMODE(after.st_mode), 0o640)
        self.assertEqual((after.st_uid, after.st_gid), (before.st_uid, before.st_gid))
        self.assertEqual(stat.S_IMODE((backup / JS).stat().st_mode), 0o600)
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['database_changes'])
        self.assertEqual([row['path'] for row in receipt['files']], [JS])

    def test_rerun_does_not_apply_insertion_hunks_twice(self):
        self.fixture.apply()
        before = self.fixture.target.stat()
        self.assertIsNone(self.fixture.apply())
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.expected)
        self.assertEqual(self.fixture.target.stat().st_ino, before.st_ino)
        self.assertEqual(len(self.fixture.receipts()), 1)

    def test_unrelated_live_changes_are_preserved(self):
        extra = b'\n// Independent expense display correction outside the hotfix hunks.\n'
        self.fixture.write(self.fixture.before + extra)
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.expected + extra)
        self.assertEqual((backup / JS).read_bytes(), self.fixture.before + extra)

    def test_conflicting_live_hunk_refuses_without_writes(self):
        before_lines = self.fixture.before.splitlines(keepends=True)
        after_lines = self.fixture.expected.splitlines(keepends=True)
        removed = next(line for line in before_lines if line not in after_lines and line.strip())
        conflict = self.fixture.before.replace(removed, b'// Unreviewed conflicting source\n', 1)
        self.fixture.write(conflict)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + JS):
            self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), conflict)
        self.assertFalse(self.fixture.receipts())

    def test_partial_hunks_refuse_instead_of_replacing_or_duplicating_source(self):
        # Apply only the first actual hunk, preserving the diff metadata.
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch = installer.release_patch(self.fixture.root, self.fixture.release, JS)
        first, separator, rest = patch.partition(b'@@ ')
        self.assertTrue(separator)
        first_hunk, separator, _ = rest.partition(b'\n@@ ')
        self.assertTrue(separator, 'The actual submit hotfix should have multiple hunks')
        partial = self.fixture.root.parent / 'partial.diff'
        partial.write_bytes(first + b'@@ ' + first_hunk + b'\n')
        self.assertTrue(installer.patch_apply(self.fixture.root, partial))
        observed = self.fixture.target.read_bytes()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + JS):
            self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), observed)
        self.assertFalse(self.fixture.receipts())

    def test_postcheck_failure_reverses_our_hunks_and_retains_original_metadata(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'FIXTURE_POSTCHECK_FAILED'):
            self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.before)
        self.assertEqual(stat.S_IMODE(self.fixture.target.stat().st_mode), 0o640)
        self.assertEqual(self.fixture.receipt()['status'], 'rolled_back')
        self.assertEqual(self.fixture.receipt()['review'], [])

    def test_live_patch_failure_retains_original_and_records_rollback(self):
        real_apply = installer.patch_apply
        def fail_live(directory, patch, reverse=False):
            if directory == self.fixture.root and not reverse:
                return False
            return real_apply(directory, patch, reverse)
        with mock.patch.object(installer, 'patch_apply', fail_live):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_PATCH_FAILED: ' + JS):
                self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.before)
        self.assertEqual(self.fixture.receipt()['status'], 'rolled_back')
        self.assertEqual(self.fixture.receipt()['review'], [])

    def test_concurrent_change_after_preflight_is_preserved_and_install_refused(self):
        real_save = installer.save_receipt
        concurrent = self.fixture.before + b'\n// Concurrent source update\n'
        def save_then_edit(path, receipt):
            real_save(path, receipt)
            if receipt['status'] == 'prepared':
                self.fixture.write(concurrent)
        with mock.patch.object(installer, 'save_receipt', save_then_edit):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + JS):
                self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), concurrent)
        self.assertEqual(self.fixture.receipt()['status'], 'prepared')

    def test_rollback_preserves_a_concurrent_edit_for_review(self):
        before, info = installer.read_source(self.fixture.target)
        patch = self.fixture.root.parent / 'rollback.diff'
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch.write_bytes(installer.release_patch(self.fixture.root, self.fixture.release, JS))
        concurrent = self.fixture.expected + b'\n// Concurrent source update after installation\n'
        self.fixture.write(concurrent)
        review = installer.rollback(self.fixture.root, [(JS, patch, self.fixture.expected)],
                                    {JS: (before, info)})
        self.assertEqual(review, [JS])
        self.assertEqual(self.fixture.target.read_bytes(), concurrent)

    def test_symlinks_and_hardlinks_are_rejected(self):
        target = self.fixture.target
        outside = target.with_name('outside.js')
        target.rename(outside)
        target.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply(check=True)
        target.unlink()
        os.link(outside, target)
        with self.assertRaisesRegex(RuntimeError, 'REGULAR_UNLINKED_SOURCE_REQUIRED'):
            self.fixture.apply(check=True)
        self.assertFalse(self.fixture.receipts())

    def test_release_executable_mode_change_is_rejected(self):
        self.fixture.git('reset', '--hard', self.fixture.release)
        self.fixture.target.chmod(0o755)
        self.fixture.git('add', '.')
        self.fixture.git('commit', '-qm', 'Invalid executable JavaScript source')
        self.fixture.release = self.fixture.git('rev-parse', 'HEAD').strip()
        self.fixture.git('reset', '--hard', self.fixture.base)
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_SOURCE_NOT_REGULAR'):
            self.fixture.apply()
        self.assertEqual(self.fixture.target.read_bytes(), self.fixture.before)

    def test_node_is_optional_and_never_executes_the_javascript(self):
        with mock.patch.object(installer.shutil, 'which', return_value=None), \
             mock.patch.object(installer, 'run') as run:
            installer.lint_sources(self.fixture.root)
            run.assert_not_called()
        node = shutil.which('node')
        if node:
            with mock.patch.object(installer, 'run', wraps=installer.run) as run:
                installer.lint_sources(self.fixture.root)
                run.assert_called_once_with([node, '--check', str(self.fixture.target)])

    def test_manifest_and_wrapper_are_js_only_and_independent_of_other_releases(self):
        self.assertEqual(installer.BASE_REVISION, ACTUAL_BASE)
        self.assertEqual(installer.SOURCE_PATHS, (JS,))
        source = (ROOT / 'deployment/apply_expense_category_submit.py').read_text()
        wrapper = (ROOT / 'deployment/apply_expense_category_submit_root.sh').read_text()
        self.assertIn('expense_base=' + ACTUAL_BASE, wrapper)
        self.assertIn('expense_source=deployment/apply_expense_category_submit.py', wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        for text in (source, wrapper):
            for excluded in ('artisan', 'DesktopDashboard', 'php82', 'legacy_expense', '--php'):
                self.assertNotIn(excluded, text)


if __name__ == '__main__':
    unittest.main()
