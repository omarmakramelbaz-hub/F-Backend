#!/usr/bin/env python3
"""Reproduce the reported live server from actual reviewed source and Git hunks.

Only schema inspection, migration execution and lint dispatch are mocked. The
source bytes, user-supplied attachment diff, Git patching and rollback are real.
"""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
ACTUAL_BASE = '41fe5cb2252e708ff544c05e8d48eb0df3ad6b87'
ACTUAL_RELEASE = 'a1a2764cab407a8bc2a871403dfcdee304bba220'
BRANCH_EXPENSES = 'app/Services/Dashboard/BranchExpenses.php'
DESKTOP_PATHS = ('app/Services/Dashboard/DesktopDashboardSchema.php',
                 'app/Services/Dashboard/DesktopDashboardBootstrap.php')
DIAGNOSTIC_PATCH = ROOT / 'tests/fixtures/expense_stock_live_legacy_attachments.patch'
SPEC = importlib.util.spec_from_file_location('expense_stock_compat_installer',
                                            ROOT / 'deployment/apply_expense_stock_purchases.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


def repository_blob(revision, relative):
    return subprocess.run(['git', '-C', str(ROOT), 'show', revision + ':' + relative],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout


# Keep the failed installer available so this suite demonstrates the original
# failure against exactly the same fixture that the compatibility release fixes.
original_installer = types.ModuleType('original_expense_stock_installer')
exec(compile(repository_blob(ACTUAL_RELEASE, 'deployment/apply_expense_stock_purchases.py'),
             '<original-reviewed-installer>', 'exec'), original_installer.__dict__)
BASE_BODIES = {relative: repository_blob(ACTUAL_BASE, relative)
               for relative in original_installer.MODIFIED_PATHS}
RELEASE_BODIES = {relative: repository_blob(ACTUAL_RELEASE, relative)
                  for relative in original_installer.SOURCE_PATHS}


def expected_legacy_attachments(body):
    """Independent description of the three changes in the supplied diagnostic."""
    changes = (
        (b'        // A journal/reconciliation transaction can fail after this nested service returns.\n'
         b'        // Immutable private bytes must survive that rollback so the same UUID can retry.\n'
         b'        $enclosed=DB::transactionLevel()>0;\n', b''),
        (b"$path=$enclosed?app(DesktopDashboardExpenseAttachments::class)->store($file,$v,(int)$actor->id):$file->store('branch-expenses','local');",
         b"$path=$file->store('branch-expenses','local');"),
        (b'if($path&&!$keepUpload&&!$enclosed)Storage::disk',
         b'if($path&&!$keepUpload)Storage::disk'),
    )
    for before, after in changes:
        if body.count(before) != 1:
            raise AssertionError('Reviewed fixture attachment change is no longer unique')
        body = body.replace(before, after)
    return body


class RealSourceFixture:
    def __init__(self, folder, *, legacy=True, desktop=False):
        self.root = Path(folder) / 'app'
        self.root.mkdir()
        self.calls = []
        self.state = {'present': False, 'recorded': False, 'seeded': False}
        self.fail_migration = False
        self.fail_post_lint = False
        self.git('init', '-q')
        self.git('config', 'user.name', 'Actual Source Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for relative, body in BASE_BODIES.items():
            self.write(relative, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'actual reviewed base source')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for relative, body in RELEASE_BODIES.items():
            self.write(relative, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'actual reviewed release source')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        if legacy:
            # Use the reported diff rather than the production compatibility
            # helper when constructing the live server's original source.
            self.git('apply', str(DIAGNOSTIC_PATCH))
            assert (self.root / BRANCH_EXPENSES).read_bytes() == expected_legacy_attachments(BASE_BODIES[BRANCH_EXPENSES])
        if not desktop:
            for relative in DESKTOP_PATHS:
                (self.root / relative).unlink()
        for relative in original_installer.MODIFIED_PATHS:
            if (self.root / relative).exists():
                (self.root / relative).chmod(0o640)
        self.expected = dict(RELEASE_BODIES)
        if legacy:
            self.expected[BRANCH_EXPENSES] = expected_legacy_attachments(self.expected[BRANCH_EXPENSES])
        if not desktop:
            for relative in DESKTOP_PATHS:
                del self.expected[relative]
        self.before = self.snapshot()
        self.real_run = installer.run

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.root), *arguments],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                              check=True).stdout.decode()

    def write(self, relative, body):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(body)

    def snapshot(self):
        return {relative: (self.root / relative).read_bytes()
                for relative in original_installer.SOURCE_PATHS
                if (self.root / relative).is_file()}

    def run(self, command, cwd=None):
        if command[:2] == ['fixture-php', 'artisan']:
            self.calls.append(tuple(command))
            # Migration must run with all old callers still intact; dormant
            # helper and the one new migration may already have been published.
            for relative in original_installer.MODIFIED_PATHS:
                target = self.root / relative
                if relative in self.before:
                    assert target.read_bytes() == self.before[relative]
                else:
                    assert not target.exists()
            for relative in original_installer.NEW_PATHS:
                assert (self.root / relative).read_bytes() == RELEASE_BODIES[relative]
            if not self.fail_migration:
                self.state = {'present': True, 'recorded': True, 'seeded': True}
            return subprocess.CompletedProcess(command, int(self.fail_migration),
                                               stdout=b'PRIVATE_DATABASE_DETAIL', stderr=b'')
        return self.real_run(command, cwd)

    def lint(self, php, stage, root, cache):
        for relative in original_installer.SOURCE_PATHS:
            target = stage / relative
            if relative in self.expected:
                assert target.is_file(), relative
            else:
                assert not target.exists(), relative
        if stage == root and self.fail_post_lint:
            raise RuntimeError('PHP_PARSE_INVALID')

    def apply(self, check=False, module=installer):
        with (mock.patch.object(module, 'BASE_REVISION', self.base),
              mock.patch.object(module, 'run', self.run),
              mock.patch.object(module, 'schema_state', lambda root, php: dict(self.state)),
              mock.patch.object(module, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return module.install(self.root, self.release, 'fixture-php', check_only=check)

    def receipt(self):
        receipts = list((self.root.parent / 'expense-stock-purchases-backups').glob('*/receipt.json'))
        assert len(receipts) == 1
        return json.loads(receipts[0].read_text())


class ExpenseStockLiveCompatibilityTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = RealSourceFixture(self.temporary.name)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def assertOriginals(self):
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertTrue(all(not (self.fixture.root / relative).exists() for relative in DESKTOP_PATHS))

    def test_reported_actual_blob_reproduces_original_installer_failure(self):
        blob = self.fixture.git('hash-object', BRANCH_EXPENSES).strip()
        self.assertTrue(blob.startswith('d29f769e4'), blob)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + BRANCH_EXPENSES):
            self.fixture.apply(check=True, module=original_installer)
        self.assertOriginals()
        self.assertEqual(self.fixture.calls, [])

    def test_legacy_check_does_not_publish_source_or_run_migration(self):
        self.assertIsNone(self.fixture.apply(check=True))
        self.assertOriginals()
        self.assertEqual(self.fixture.calls, [])
        self.assertEqual([p.name for p in (self.fixture.root.parent / 'expense-stock-purchases-backups').iterdir()],
                         ['install.lock'])

    def test_actual_legacy_source_receives_all_hooks_without_desktop_files(self):
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        source = (self.fixture.root / BRANCH_EXPENSES).read_bytes()
        for hook in (b"'quantity_kg'=>'nullable'", b'normalizeQuantity($v,$stockCategory)',
                     b'$this->stockPurchases()->receive($id,$v,$actor)',
                     b'$this->stockPurchases()->reverse($row,$actor)',
                     b'$this->stockPurchases()->prime(', b'$this->stockPurchases()->present($row)'):
            self.assertIn(hook, source)
        self.assertNotIn(b'$enclosed', source)
        self.assertNotIn(b'DesktopDashboardExpenseAttachments', source)
        self.assertEqual((backup / BRANCH_EXPENSES).read_bytes(), self.fixture.before[BRANCH_EXPENSES])
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertEqual(set(receipt['skipped_paths']), set(DESKTOP_PATHS))
        self.assertFalse(set(DESKTOP_PATHS) & {row['path'] for row in receipt['files']})
        self.assertEqual(len(self.fixture.calls), 1)
        for relative in self.fixture.expected:
            self.assertEqual(stat.S_IMODE((self.fixture.root / relative).stat().st_mode),
                             0o644 if relative in installer.NEW_PATHS else 0o640)

    def test_legacy_rerun_is_idempotent_and_keeps_desktop_absent(self):
        self.fixture.apply()
        installed = self.fixture.snapshot()
        self.assertIsNone(self.fixture.apply())
        self.assertEqual(self.fixture.snapshot(), installed)
        self.assertEqual(len(self.fixture.calls), 1)
        self.assertTrue(all(not (self.fixture.root / relative).exists() for relative in DESKTOP_PATHS))

    def test_unrelated_legacy_live_edit_survives_compatibility_patch(self):
        suffix = b'\n// An independent reviewed live correction outside the changed hunks.\n'
        self.fixture.write(BRANCH_EXPENSES, self.fixture.before[BRANCH_EXPENSES] + suffix)
        (self.fixture.root / BRANCH_EXPENSES).chmod(0o640)
        self.fixture.before = self.fixture.snapshot()
        self.fixture.expected[BRANCH_EXPENSES] += suffix
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        self.assertEqual((backup / BRANCH_EXPENSES).read_bytes(), self.fixture.before[BRANCH_EXPENSES])

    def test_additional_legacy_hunk_conflict_stops_before_source_or_database_writes(self):
        before = self.fixture.before[BRANCH_EXPENSES]
        self.fixture.write(BRANCH_EXPENSES, before.replace(
            b'$path=null;$keepUpload=false;$attachment=[];',
            b'$path=null;$keepUpload=false;$attachment=[];$unreviewedBehavior=true;'))
        self.fixture.before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + BRANCH_EXPENSES):
            self.fixture.apply()
        self.assertOriginals()
        self.assertEqual(self.fixture.calls, [])
        self.assertFalse(list((self.fixture.root.parent / 'expense-stock-purchases-backups').glob('*/receipt.json')))

    def test_unrelated_source_conflict_still_stops_before_source_or_database_writes(self):
        relative = 'app/Services/Dashboard/ExpenseCategories.php'
        before = self.fixture.before[relative]
        old = b"'category_items'=>$manage?array_values($active):[]];"
        self.assertIn(old, before)
        self.fixture.write(relative, before.replace(old, b"'category_items'=>$manage?array_reverse($active):[]];"))
        self.fixture.before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative):
            self.fixture.apply()
        self.assertOriginals()
        self.assertEqual(self.fixture.calls, [])

    def test_post_migration_rollback_restores_legacy_without_creating_desktop(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            self.fixture.apply()
        self.assertOriginals()
        self.assertTrue(all(self.fixture.state.values()))
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['migration_attempted'])
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertEqual(receipt['review'], [])

    def test_failed_migration_restores_legacy_without_creating_desktop(self):
        self.fixture.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_EXPENSE_STOCK_MIGRATION_FAILED'):
            self.fixture.apply()
        self.assertOriginals()
        self.assertEqual(self.fixture.receipt()['status'], 'code_restored')
        self.assertEqual(len(self.fixture.calls), 1)

    def test_half_present_desktop_pair_is_rejected_before_source_or_database_writes(self):
        for relative in DESKTOP_PATHS:
            with self.subTest(present=relative), tempfile.TemporaryDirectory() as temporary:
                fixture = RealSourceFixture(temporary)
                fixture.write(relative, BASE_BODIES[relative])
                observed = fixture.snapshot()
                with self.assertRaisesRegex(RuntimeError, 'DESKTOP.*(?:PAIR|INCOMPLETE|PARTIAL|REVIEW)'):
                    fixture.apply()
                self.assertEqual(fixture.snapshot(), observed)
                self.assertEqual(fixture.calls, [])

    def test_complete_desktop_install_supports_modern_and_legacy_attachments(self):
        for legacy in (False, True):
            with self.subTest(legacy=legacy), tempfile.TemporaryDirectory() as temporary:
                fixture = RealSourceFixture(temporary, legacy=legacy, desktop=True)
                fixture.apply()
                self.assertEqual(fixture.snapshot(), fixture.expected)
                for relative in DESKTOP_PATHS:
                    self.assertIn(b'expense_inventory_receipts', (fixture.root / relative).read_bytes())
                self.assertEqual(fixture.receipt().get('skipped_paths', []), [])
                self.assertEqual(len(fixture.calls), 1)
                self.assertIsNone(fixture.apply())
                self.assertEqual(len(fixture.calls), 1)


if __name__ == '__main__':
    unittest.main()
