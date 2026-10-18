#!/usr/bin/env python3
"""Exercise the scoped installer against disposable release and live trees."""
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


SPEC = importlib.util.spec_from_file_location(
    'dashboard_all_branches_installer',
    Path(__file__).resolve().parents[1] / 'deployment/apply_dashboard_all_branches.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class InstallerTest(unittest.TestCase):
    FIRST = 'app/first.txt'
    SECOND = 'app/second.txt'
    NEW = 'app/helper.txt'

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        self.root = self.parent / 'public_html'
        self.root.mkdir()
        self.git('init', '--quiet')
        self.git('config', 'user.name', 'Installer test')
        self.git('config', 'user.email', 'installer-test@example.invalid')
        self.base = b''.join(('line ' + str(index) + '\n').encode() for index in range(1, 41))
        self.release = self.base.replace(b'line 3\n', b'branch default all\n').replace(
            b'line 30\n', b'ignore stale default\n')
        for relative in (self.FIRST, self.SECOND):
            self.write(relative, self.base)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture baseline')
        self.base_revision = self.git('rev-parse', 'HEAD').strip().decode()
        for relative in (self.FIRST, self.SECOND):
            self.write(relative, self.release)
        self.write(self.NEW, b'branch helper\n')
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture release')
        self.revision = self.git('rev-parse', 'HEAD').strip().decode()
        self.git('reset', '--hard', '--quiet', self.base_revision)
        self.patches = mock.patch.multiple(
            INSTALLER, BASE_REVISION=self.base_revision, NEW_PATHS=(self.NEW,),
            MODIFIED_PATHS=(self.FIRST, self.SECOND),
            SOURCE_PATHS=(self.NEW, self.FIRST, self.SECOND))
        self.patches.start()
        self.addCleanup(self.patches.stop)

    def git(self, *arguments):
        result = subprocess.run(['git', '-C', str(self.root), *arguments],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def write(self, relative, body):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)
        return path

    def install(self, check_only=False):
        with contextlib.redirect_stdout(io.StringIO()):
            return INSTALLER.install(self.root, self.revision, '/unused/php', check_only)

    def assert_originals(self):
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.base)
        self.assertEqual((self.root / self.SECOND).read_bytes(), self.base)
        self.assertFalse((self.root / self.NEW).exists())

    def test_clean_apply_backup_metadata_and_idempotence(self):
        (self.root / self.FIRST).chmod(0o750)
        backup = self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.release)
        self.assertEqual((self.root / self.FIRST).stat().st_mode & 0o777, 0o750)
        self.assertEqual((self.root / self.NEW).read_bytes(), b'branch helper\n')
        self.assertEqual((backup / self.FIRST).read_bytes(), self.base)
        self.assertEqual(json.loads((backup / 'receipt.json').read_text())['status'], 'installed')
        self.assertIsNone(self.install())

    def test_preserves_live_drift_and_accepts_partial_applied_hunks(self):
        live = self.base.replace(b'line 3\n', b'branch default all\n').replace(
            b'line 15\n', b'independent live delivery logic\n')
        self.write(self.FIRST, live)
        self.write(self.NEW, b'branch helper\n')
        self.install()
        expected = self.release.replace(b'line 15\n', b'independent live delivery logic\n')
        self.assertEqual((self.root / self.FIRST).read_bytes(), expected)
        self.assertIsNone(self.install())

    def test_conflict_preflights_every_source_before_writing(self):
        conflicted = self.base.replace(b'line 30\n', b'conflicting live default\n')
        self.write(self.SECOND, conflicted)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.base)
        self.assertEqual((self.root / self.SECOND).read_bytes(), conflicted)
        self.assertFalse((self.root / self.NEW).exists())

    def test_existing_new_file_collision_is_not_overwritten(self):
        self.write(self.NEW, b'independent implementation\n')
        with self.assertRaisesRegex(RuntimeError, 'NEW_SOURCE_COLLISION'):
            self.install()
        self.assertEqual((self.root / self.NEW).read_bytes(), b'independent implementation\n')
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.base)

    def test_check_only_has_no_source_changes(self):
        self.install(check_only=True)
        self.assert_originals()

    def test_postcheck_failure_restores_old_code_and_removes_new_file(self):
        calls = []
        def fail_live_lint(php, directory):
            calls.append(directory)
            if directory == self.root:
                raise RuntimeError('SIMULATED_POSTCHECK_FAILURE')
        with mock.patch.object(INSTALLER, 'lint_sources', side_effect=fail_live_lint):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_POSTCHECK_FAILURE'):
                self.install()
        self.assertEqual(len(calls), 2)
        self.assert_originals()
        receipts = list((self.parent / 'dashboard-all-branches-backups').glob('*/receipt.json'))
        self.assertEqual(len(receipts), 1)
        self.assertEqual(json.loads(receipts[0].read_text())['status'], 'code_restored')

    def test_concurrent_live_edit_stops_before_install(self):
        concurrent = self.base + b'concurrent write\n'
        def race(root, php, pending):
            self.write(self.FIRST, concurrent)
            return ()
        with mock.patch.object(INSTALLER, 'compiled_views', side_effect=race):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_BEFORE_INSTALL'):
                self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), concurrent)
        self.assertEqual((self.root / self.SECOND).read_bytes(), self.base)
        self.assertFalse((self.root / self.NEW).exists())

    def test_symlink_is_rejected_before_writes(self):
        target = self.parent / 'outside.txt'
        target.write_bytes(b'external bytes\n')
        (self.root / self.NEW).symlink_to(target)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        self.assertEqual(target.read_bytes(), b'external bytes\n')
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.base)

    def test_exact_patch_preserves_no_final_newline(self):
        with tempfile.TemporaryDirectory(dir=self.parent) as temporary:
            directory = Path(temporary)
            (directory / 'sample.txt').write_bytes(b'before')
            patch = directory / 'change.diff'
            patch.write_bytes(INSTALLER.exact_patch('sample.txt', b'before', b'after'))
            self.assertTrue(INSTALLER.patch_check(directory, patch))
            self.assertTrue(INSTALLER.patch_apply(directory, patch))
            self.assertEqual((directory / 'sample.txt').read_bytes(), b'after')

    def expense_fixture(self):
        anchor = INSTALLER.EXPENSE_LISTING_ANCHOR
        common = b'        $v=$this->filters($values);[$base,$branches]=$this->scope($v,$actor);'
        end = b'        return $query;\n    }\n    public function show() {}\n}\n'
        base = (b'<?php\nclass BranchExpenses\n{\n' + anchor + common + b'\n'
                + b'        $categoryScopes=branchLocalCategories();\n'
                + b'        $query=$this->filtered(clone $base,$v,$categoryScopes);\n' + end)
        release = base.replace(anchor, anchor + b''.join(INSTALLER.EXPENSE_DEFAULT_LINES), 1)
        live = (b'<?php\nclass BranchExpenses\n{\n' + anchor + common
                + b'$query=$this->filtered(clone $base,$v);\n'
                + b'        $categories=legacyCategories();\n' + end)
        return base, release, live

    def test_expense_adjacent_live_filter_drift_inserts_only_default_lines(self):
        base, release, live = self.expense_fixture()
        with tempfile.TemporaryDirectory(dir=self.parent) as temporary:
            stage = Path(temporary)
            # This is the real adjacency shape: baseline category work split the
            # first listing line, while the older live copy still shares it.
            paths = [stage / name for name in ('live', 'base', 'release')]
            for path, body in zip(paths, (live, base, release)):
                path.write_bytes(body)
            result = INSTALLER.run(['git', 'merge-file', '-p', '--diff3', *map(str, paths)])
            self.assertNotEqual(result.returncode, 0)
            merged = INSTALLER.merge_candidate(stage, INSTALLER.BRANCH_EXPENSES, live, base, release)
            expected = live.replace(INSTALLER.EXPENSE_LISTING_ANCHOR,
                                    INSTALLER.EXPENSE_LISTING_ANCHOR
                                    + b''.join(INSTALLER.EXPENSE_DEFAULT_LINES), 1)
            self.assertEqual(merged, expected)
            self.assertIn(b'$categories=legacyCategories();', merged)
            self.assertNotIn(b'branchLocalCategories()', merged)
            self.assertEqual(INSTALLER.merge_candidate(
                stage, INSTALLER.BRANCH_EXPENSES, merged, base, release), merged)

    def test_expense_fallback_rejects_partial_duplicate_or_unrecognized_defaults(self):
        base, release, live = self.expense_fixture()
        anchor = INSTALLER.EXPENSE_LISTING_ANCHOR
        addition = b''.join(INSTALLER.EXPENSE_DEFAULT_LINES)
        variants = [
            live.replace(anchor, anchor + INSTALLER.EXPENSE_DEFAULT_LINES[0], 1),
            live.replace(anchor, anchor + INSTALLER.EXPENSE_DEFAULT_LINES[1], 1),
            live.replace(anchor, anchor + addition + addition, 1),
            live.replace(anchor, anchor + b'        $actor=customActor();\n', 1),
            live + anchor,
        ]
        for variant in variants:
            with self.subTest(variant=variant):
                with self.assertRaisesRegex(RuntimeError, 'EXPENSE_DEFAULT_'):
                    INSTALLER.expense_defaults_candidate(variant, base, release)
        with self.assertRaisesRegex(RuntimeError, 'EXPENSE_DEFAULT_RELEASE_NEEDS_REVIEW'):
            INSTALLER.expense_defaults_candidate(live, base, release + b'// unrelated release change\n')


if __name__ == '__main__':
    unittest.main()
