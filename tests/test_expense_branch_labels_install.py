#!/usr/bin/env python3
"""Exercise real current/base PHP and JS bytes with actual Git hunks and rollback.

PHP and Node parse the real candidate sources. No application is booted and no
fixture has a database, .env, attachments, or desktop export prerequisite.
"""
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
SPEC = importlib.util.spec_from_file_location('expense_branch_labels_installer',
                                            ROOT / 'deployment/apply_expense_branch_labels.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)
ACTUAL_BASE = '24b22fed1c1a0bf06eea88a4c26f8fac00ce1535'
BRANCH_EXPENSES = 'app/Services/Dashboard/BranchExpenses.php'
DESKTOP_PATHS = ('app/Services/Dashboard/DesktopDashboardSchema.php',
                 'app/Services/Dashboard/DesktopDashboardBootstrap.php')


def repository_blob(revision, relative):
    return subprocess.run(['git', '-C', str(ROOT), 'show', revision + ':' + relative],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout


def legacy_attachments(body):
    """Independent expected bytes from the diagnostic of the activated server."""
    for before, after in (
        (b'        // A journal/reconciliation transaction can fail after this nested service returns.\n'
         b'        // Immutable private bytes must survive that rollback so the same UUID can retry.\n'
         b'        $enclosed=DB::transactionLevel()>0;\n', b''),
        (b"$path=$enclosed?app(DesktopDashboardExpenseAttachments::class)->store($file,$v,(int)$actor->id):$file->store('branch-expenses','local');",
         b"$path=$file->store('branch-expenses','local');"),
        (b'if($path&&!$keepUpload&&!$enclosed)Storage::disk',
         b'if($path&&!$keepUpload)Storage::disk'),
    ):
        if body.count(before) != 1:
            raise AssertionError('Reviewed attachment source no longer has its unique diagnostic anchor')
        body = body.replace(before, after, 1)
    return body


class RealSourceFixture:
    def __init__(self, folder, *, legacy=False, desktop=False):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Expense Source Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.base_bodies = {path: repository_blob(ACTUAL_BASE, path) for path in installer.SOURCE_PATHS}
        self.expected = {path: (ROOT / path).read_bytes() for path in installer.SOURCE_PATHS}
        if desktop:
            for relative in DESKTOP_PATHS:
                self.write(relative, repository_blob(ACTUAL_BASE, relative))
        for path, body in self.base_bodies.items():
            self.write(path, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual activated compatible source')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for path, body in self.expected.items():
            self.write(path, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual candidate labels and edit source')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        if legacy:
            self.write(BRANCH_EXPENSES, legacy_attachments(self.base_bodies[BRANCH_EXPENSES]))
            self.expected[BRANCH_EXPENSES] = legacy_attachments(self.expected[BRANCH_EXPENSES])
        for relative in installer.SOURCE_PATHS:
            (self.root / relative).chmod(0o640)
        self.before = self.snapshot()
        self.untouched = {path: (self.root / path).read_bytes() for path in DESKTOP_PATHS if (self.root / path).exists()}
        self.real_lint = installer.lint_sources
        self.fail_post_lint = False
        self.php = os.environ.get('EXPENSE_BRANCH_LABELS_PHP') or shutil.which('php')
        if not self.php:
            raise unittest.SkipTest('PHP executable required to parse real source fixtures')

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.root), *arguments],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout.decode()

    def write(self, relative, body):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(body)

    def snapshot(self):
        return {relative: (self.root / relative).read_bytes() for relative in installer.SOURCE_PATHS}

    def lint(self, php, directory):
        self.real_lint(php, directory)
        if directory == self.root and self.fail_post_lint:
            raise RuntimeError('FIXTURE_POSTCHECK_FAILED')

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, self.php, check_only=check)

    def receipt(self):
        return json.loads(next((self.root.parent / 'expense-branch-labels-backups').glob('*/receipt.json')).read_text())

    def assert_desktop_untouched(self, case):
        for relative in DESKTOP_PATHS:
            if relative in self.untouched:
                case.assertEqual((self.root / relative).read_bytes(), self.untouched[relative])
            else:
                case.assertFalse((self.root / relative).exists())


class ExpenseBranchLabelsInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = RealSourceFixture(self.temporary.name, legacy=True)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_check_only_parses_actual_sources_without_live_application_writes(self):
        before = {path: (self.fixture.root / path).stat() for path in installer.SOURCE_PATHS}
        self.assertIsNone(self.fixture.apply(check=True))
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        for path, info in before.items():
            self.assertEqual((self.fixture.root / path).stat().st_ino, info.st_ino)
            self.assertEqual((self.fixture.root / path).stat().st_mtime_ns, info.st_mtime_ns)
        private = self.fixture.root.parent / 'expense-branch-labels-backups'
        self.assertEqual([path.name for path in private.iterdir()], ['install.lock'])
        self.fixture.assert_desktop_untouched(self)

    def test_actual_legacy_source_installs_preserving_attachment_implementation_and_modes(self):
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        source = self.fixture.snapshot()[BRANCH_EXPENSES]
        self.assertNotIn(b'$enclosed', source)
        self.assertNotIn(b'DesktopDashboardExpenseAttachments', source)
        for relative in installer.SOURCE_PATHS:
            self.assertEqual((backup / relative).read_bytes(), self.fixture.before[relative])
            self.assertEqual(stat.S_IMODE((self.fixture.root / relative).stat().st_mode), 0o640)
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['database_changes'])
        self.assertEqual({row['path'] for row in receipt['files']}, set(installer.SOURCE_PATHS))
        self.fixture.assert_desktop_untouched(self)

    def test_install_and_rerun_support_modern_and_legacy_attachment_variants(self):
        for legacy in (False, True):
            for desktop in (False, True):
                with self.subTest(legacy=legacy, desktop=desktop), tempfile.TemporaryDirectory() as folder:
                    fixture = RealSourceFixture(folder, legacy=legacy, desktop=desktop)
                    fixture.apply()
                    self.assertEqual(fixture.snapshot(), fixture.expected)
                    self.assertIsNone(fixture.apply())
                    self.assertEqual(fixture.snapshot(), fixture.expected)
                    self.assertEqual(len(list((fixture.root.parent / 'expense-branch-labels-backups').glob('*/receipt.json'))), 1)
                    fixture.assert_desktop_untouched(self)

    def test_unrelated_live_customization_is_preserved_and_backed_up(self):
        relative = BRANCH_EXPENSES
        extra = b'\n// Independent live correction outside release hunks.\n'
        self.fixture.write(relative, self.fixture.before[relative] + extra)
        (self.fixture.root / relative).chmod(0o640)
        self.fixture.before = self.fixture.snapshot()
        self.fixture.expected[relative] += extra
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        self.assertEqual((backup / relative).read_bytes(), self.fixture.before[relative])

    def test_half_present_desktop_exports_are_outside_the_installer_scope(self):
        # This update has no desktop export dependency: even the old installer's
        # pair check must not block an otherwise valid expense-only release.
        relative = DESKTOP_PATHS[0]
        body = b'<?php // Independent live desktop export implementation.\n'
        self.fixture.write(relative, body)
        self.fixture.untouched[relative] = body
        self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        self.fixture.assert_desktop_untouched(self)

    def test_conflicting_runtime_hunk_fails_before_any_live_write(self):
        # Replace a guaranteed removed source line from the real PHP patch.
        before = self.fixture.before[BRANCH_EXPENSES]
        after = self.fixture.expected[BRANCH_EXPENSES]
        removed = next(line for line in before.splitlines(keepends=True)
                       if line not in after.splitlines(keepends=True) and line.strip())
        self.fixture.write(BRANCH_EXPENSES, before.replace(removed, b'// Unreviewed conflicting behavior\n', 1))
        self.fixture.before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + BRANCH_EXPENSES):
            self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertFalse(list((self.fixture.root.parent / 'expense-branch-labels-backups').glob('*/receipt.json')))
        self.fixture.assert_desktop_untouched(self)

    def test_postcheck_failure_restores_original_legacy_sources_and_modes(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'FIXTURE_POSTCHECK_FAILED'):
            self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertEqual(self.fixture.receipt()['status'], 'rolled_back')
        self.assertEqual(self.fixture.receipt()['review'], [])
        for relative in installer.SOURCE_PATHS:
            self.assertEqual(stat.S_IMODE((self.fixture.root / relative).stat().st_mode), 0o640)
        self.fixture.assert_desktop_untouched(self)

    def test_partial_patch_failure_rolls_back_only_our_applied_hunks(self):
        real_apply = installer.patch_apply
        live_calls = []
        def fail_second_live(directory, patch, reverse=False):
            if directory == self.fixture.root and not reverse:
                live_calls.append(patch)
                if len(live_calls) == 2:
                    return False
            return real_apply(directory, patch, reverse)
        with mock.patch.object(installer, 'patch_apply', fail_second_live):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_PATCH_FAILED'):
                self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertEqual(self.fixture.receipt()['status'], 'rolled_back')
        self.assertEqual(self.fixture.receipt()['review'], [])

    def test_rollback_refuses_to_overwrite_a_concurrent_edit(self):
        relative = BRANCH_EXPENSES
        before, info = installer.read_source(self.fixture.root / relative)
        patch = self.fixture.root.parent / 'rollback.diff'
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch.write_bytes(installer.legacy_expense_patch(self.fixture.root, self.fixture.release))
        after = self.fixture.expected[relative]
        concurrent = after + b'\n// New concurrent live correction\n'
        self.fixture.write(relative, concurrent)
        review = installer.rollback(self.fixture.root, [(relative, patch, after)], {relative: (before, info)})
        self.assertEqual(review, [relative])
        self.assertEqual((self.fixture.root / relative).read_bytes(), concurrent)

    def test_symlink_source_is_rejected_before_application_writes(self):
        relative = installer.SOURCE_PATHS[-1]
        target = self.fixture.root / relative
        outside = target.with_name('outside.php')
        target.rename(outside)
        target.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply(check=True)
        self.assertFalse(list((self.fixture.root.parent / 'expense-branch-labels-backups').glob('*/receipt.json')))

    def test_manifest_and_root_wrapper_are_narrow_and_independent(self):
        self.assertEqual(installer.BASE_REVISION, ACTUAL_BASE)
        self.assertEqual(set(installer.SOURCE_PATHS), {
            BRANCH_EXPENSES, 'app/Services/Dashboard/ExpenseCategories.php',
            'public/dashboard/js/branch-expenses.js',
            'public/dashboard/css/branch-expenses.css',
            'resources/lang/ar/expenses.php', 'resources/lang/en/expenses.php'})
        wrapper = (ROOT / 'deployment/apply_expense_branch_labels_root.sh').read_text()
        self.assertIn('expense_source=deployment/apply_expense_branch_labels.py', wrapper)
        self.assertIn('expense_base=' + ACTUAL_BASE, wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        self.assertNotIn('artisan', wrapper)
        source = (ROOT / 'deployment/apply_expense_branch_labels.py').read_text()
        self.assertNotIn('artisan', source)
        self.assertNotIn('deployment_paths', source)
        self.assertNotIn('apply_expense_stock_purchases.py', source)


if __name__ == '__main__':
    unittest.main()
