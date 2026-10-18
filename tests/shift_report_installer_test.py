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
    'shift_report_installer',
    Path(__file__).resolve().parents[1] / 'deployment/apply_shift_report.py')
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
            SOURCE_PATHS=(self.NEW, self.FIRST, self.SECOND), LEGACY_PATHS=())
        self.patches.start()
        self.schema_patch = mock.patch.object(INSTALLER, 'schema_check')
        self.schema_patch.start()
        self.addCleanup(self.schema_patch.stop)
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
        receipts = list((self.parent / 'shift-report-backups').glob('*/receipt.json'))
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

    def test_schema_apply_failure_keeps_every_source_and_old_balance_code(self):
        def schema(root, php, stage, check_only):
            if not check_only:
                raise RuntimeError('SIMULATED_SCHEMA_APPLY_FAILED')
        with mock.patch.object(INSTALLER, 'schema_check', side_effect=schema):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_SCHEMA_APPLY_FAILED'):
                self.install()
        self.assert_originals()

    def test_exact_reviewed_legacy_source_accepts_release_but_unknown_conflict_stops(self):
        with tempfile.TemporaryDirectory(dir=self.parent) as temporary:
            stage=Path(temporary)
            legacy=self.base.replace(b'line 3\n',b'older single-branch screen\n')
            result=INSTALLER.merge_candidate(stage,self.FIRST,legacy,self.base,self.release,legacy)
            self.assertEqual(result,self.release)
            with self.assertRaisesRegex(RuntimeError,'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.merge_candidate(stage,self.FIRST,legacy+b'unknown drift\n',self.base,self.release,legacy)

class ObservedCompatibilityTest(unittest.TestCase):
    ROOT=Path(__file__).resolve().parents[1]
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.stage=Path(self.temporary.name)
        self.sources={}
        for relative in (INSTALLER.SHIFT_SERVICE_PATH,INSTALLER.SHIFT_RECEIVER_PATH):
            base=subprocess.check_output(['git','-C',str(self.ROOT),'show',INSTALLER.BASE_REVISION+':'+relative])
            self.sources[relative]=(base,(self.ROOT/relative).read_bytes())
            path=self.stage/relative;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(base)
        subprocess.run(['git','apply',str(self.ROOT/'tests/fixtures/shift_report_live_20261018.patch')],cwd=self.stage,check=True)
    def merge(self,path,body=None):
        base,release=self.sources[path]
        return INSTALLER.merge_candidate(self.stage,path,body if body is not None else (self.stage/path).read_bytes(),base,release)
    def test_preserves_observed_shubra_claims_and_missing_desktop_module(self):
        path=INSTALLER.SHIFT_SERVICE_PATH;live=(self.stage/path).read_bytes()
        self.assertEqual(INSTALLER.digest(live),INSTALLER.OBSERVED_SHIFT_HASHES[0])
        merged=self.merge(path)
        self.assertEqual(INSTALLER.digest(merged),INSTALLER.OBSERVED_SHIFT_HASHES[1])
        self.assertIn(b'FasakhanstaStoreFulfillment::sourceRestaurantIdsForBranch',merged)
        self.assertIn(b"if(!in_array($source,['app','app_cash'],true))$s->where('branch',$branch)",merged)
        self.assertNotIn(b'function desktopReview',merged);self.assertNotIn(b'function reconcileDesktop',merged)
        self.assertIn(b'function canClose',merged);self.assertIn(b"$snapshot['drawer_preserved']=true",merged)
        self.assertNotIn(b"->where('id',$till->id)->update",merged)
        self.assertEqual(self.merge(path,merged),merged)
    def test_preserves_sound_and_actor_when_adding_shift_print_routes(self):
        path=INSTALLER.SHIFT_RECEIVER_PATH;merged=self.merge(path)
        self.assertEqual(INSTALLER.digest(merged),INSTALLER.OBSERVED_RECEIVER_HASHES[1])
        self.assertIn(b"'actor_id'=>(int)auth('admin')->id()",merged)
        self.assertIn(b"'enable_sound'=>__('phone_orders.enable_sound')",merged)
        self.assertEqual(merged.count(b"'shifts'=>"),1)
        self.assertEqual(self.merge(path,merged),merged)
    def test_unknown_live_drift_still_requires_review(self):
        for path in self.sources:
            with self.subTest(path=path):
                live=(self.stage/path).read_bytes()+b'// unreviewed additional drift\n'
                with self.assertRaisesRegex(RuntimeError,'LIVE_CHANGE_NEEDS_REVIEW'):
                    self.merge(path,live)
    def test_modified_release_is_not_accepted_as_reviewed(self):
        path=INSTALLER.SHIFT_SERVICE_PATH;base,release=self.sources[path]
        with self.assertRaisesRegex(RuntimeError,'SHIFT_OBSERVED_RELEASE_NEEDS_REVIEW'):
            INSTALLER.merge_candidate(self.stage,path,(self.stage/path).read_bytes(),base,release+b'// unexpected release drift\n')

if __name__ == '__main__':
    unittest.main()
