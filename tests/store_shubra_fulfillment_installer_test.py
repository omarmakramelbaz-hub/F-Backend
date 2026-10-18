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
    'store_shubra_fulfillment_installer',
    Path(__file__).resolve().parents[1] / 'deployment/apply_store_shubra_fulfillment.py')
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
        self.release = self.base.replace(b'line 3\n', b'route store fulfillment\n').replace(
            b'line 30\n', b'attribute store cash\n')
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
        self.database = mock.patch.object(INSTALLER, 'database_preflight')
        self.database.start()
        self.addCleanup(self.database.stop)
        self.linter = mock.patch.object(INSTALLER, 'lint_sources')
        self.linter.start()
        self.addCleanup(self.linter.stop)
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
        live = self.base.replace(b'line 3\n', b'route store fulfillment\n').replace(
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
        receipts = list((self.parent / 'store-shubra-fulfillment-backups').glob('*/receipt.json'))
        self.assertEqual(len(receipts), 1)
        self.assertEqual(json.loads(receipts[0].read_text())['status'], 'code_restored')

    def test_concurrent_live_edit_stops_before_install(self):
        concurrent = self.base + b'concurrent write\n'
        def race(root, php, stage):
            self.write(self.FIRST, concurrent)
            return ()
        with mock.patch.object(INSTALLER, 'database_preflight', side_effect=race):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_PREFLIGHT'):
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

    def test_database_preflight_failure_never_changes_live_sources(self):
        with mock.patch.object(INSTALLER, 'database_preflight',
                               side_effect=RuntimeError('SHUBRA_DATABASE_PREFLIGHT_NEEDS_REVIEW')):
            with self.assertRaisesRegex(RuntimeError, 'SHUBRA_DATABASE_PREFLIGHT_NEEDS_REVIEW'):
                self.install()
        self.assert_originals()
        self.assertEqual(list((self.parent / 'store-shubra-fulfillment-backups').glob('*/receipt.json')), [])

    def test_staged_lint_failure_never_changes_live_sources(self):
        with mock.patch.object(INSTALLER, 'lint_sources',
                               side_effect=RuntimeError('PHP_SYNTAX_INVALID')):
            with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
                self.install()
        self.assert_originals()

    def test_partial_apply_failure_rolls_back_earlier_sources(self):
        original_apply = INSTALLER.patch_apply
        def fail_second_live(directory, patch, reverse=False):
            if directory == self.root and patch.name == 'patch-2.diff' and not reverse:
                return False
            return original_apply(directory, patch, reverse)
        with mock.patch.object(INSTALLER, 'patch_apply', side_effect=fail_second_live):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_PATCH_FAILED'):
                self.install()
        self.assert_originals()
        receipt = next((self.parent / 'store-shubra-fulfillment-backups').glob('*/receipt.json'))
        self.assertEqual(json.loads(receipt.read_text())['status'], 'code_restored')

    def test_rollback_never_overwrites_unowned_concurrent_edit(self):
        concurrent = self.release + b'concurrent operator change\n'
        def fail_live_lint(php, directory):
            if directory == self.root:
                self.write(self.FIRST, concurrent)
                raise RuntimeError('SIMULATED_POSTCHECK_FAILURE')
        with mock.patch.object(INSTALLER, 'lint_sources', side_effect=fail_live_lint):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_POSTCHECK_FAILURE'):
                self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), concurrent)
        self.assertEqual((self.root / self.SECOND).read_bytes(), self.base)
        self.assertFalse((self.root / self.NEW).exists())
        receipt = next((self.parent / 'store-shubra-fulfillment-backups').glob('*/receipt.json'))
        self.assertEqual(json.loads(receipt.read_text())['status'], 'rollback_review_required')
        self.assertEqual(json.loads(receipt.read_text())['review'], [self.FIRST])

    def test_source_hard_link_rejected(self):
        original = self.root / self.FIRST
        os.link(original, self.parent / 'shared-source.txt')
        with self.assertRaisesRegex(RuntimeError, 'REGULAR_UNLINKED_SOURCE_REQUIRED'):
            self.install()
        self.assert_originals()

    def test_wrong_release_manifest_rejected_before_sources_change(self):
        with mock.patch.object(INSTALLER, 'MODIFIED_PATHS', (self.FIRST,)):
            with self.assertRaisesRegex(RuntimeError, 'RELEASE_SOURCE_MANIFEST_MISMATCH'):
                self.install()
        self.assert_originals()

    def test_database_report_validation_excludes_error_output(self):
        real_probe = self.database.temp_original
        for output in (b'{"target":301,"eligible_stores":4}',):
            with mock.patch.object(INSTALLER, 'run', return_value=subprocess.CompletedProcess([], 0, output)):
                with contextlib.redirect_stdout(io.StringIO()) as captured:
                    real_probe(self.root, '/unused/php', self.parent)
                self.assertIn('branch=301 eligible_stores=4', captured.getvalue())
        for output in (b'private server error', b'{"target":301,"eligible_stores":true}',
                       b'{"target":302,"eligible_stores":4}', b'{"target":301,"eligible_stores":-1}',
                       b'{"target":301,"eligible_stores":4,"private":"secret"}'):
            with mock.patch.object(INSTALLER, 'run', return_value=subprocess.CompletedProcess([], 0, output)):
                with self.assertRaisesRegex(RuntimeError, '^SHUBRA_DATABASE_PREFLIGHT_NEEDS_REVIEW$'):
                    real_probe(self.root, '/unused/php', self.parent)



@unittest.skipUnless(os.environ.get('STORE_SHUBRA_TEST_PHP')
                     and os.environ.get('STORE_SHUBRA_TEST_AUTOLOAD'),
                     'Set STORE_SHUBRA_TEST_PHP and STORE_SHUBRA_TEST_AUTOLOAD for the real SQLite probe.')
class DatabaseProbeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / 'vendor').mkdir()
        (self.root / 'bootstrap').mkdir()
        self.repo = Path(__file__).resolve().parents[1]
        self.helper = INSTALLER.NEW_PATHS[0]
        helper_path = self.root / self.helper
        helper_path.parent.mkdir(parents=True)
        helper_path.write_bytes((self.repo / self.helper).read_bytes())
        self.bootstrap = r'''<?php
require AUTOLOAD;
require DELIVERY;
$c = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($c);
$db = new Illuminate\Database\Capsule\Manager($c);
$db->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db->setAsGlobal(); $db->bootEloquent();
$c->instance('db', $db->getDatabaseManager());
Illuminate\Support\Facades\Facade::setFacadeApplication($c);
Illuminate\Support\Facades\Schema::create('resturants', function ($t) {
 $t->integer('id'); $t->string('name'); $t->integer('parent_id')->nullable();
});
Illuminate\Support\Facades\DB::table('resturants')->insert([
 ['id'=>301,'name'=>'شبرا','parent_id'=>null],
 ['id'=>309,'name'=>'المقطم ستور','parent_id'=>null],
 ['id'=>310,'name'=>'زايد ستور','parent_id'=>94],
 ['id'=>900,'name'=>'new','parent_id'=>301]
]);
'''.replace('AUTOLOAD', json.dumps(os.environ['STORE_SHUBRA_TEST_AUTOLOAD'])).replace(
            'DELIVERY', json.dumps(str(self.repo / 'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php')))
        (self.root / 'vendor/autoload.php').write_text(self.bootstrap)
        (self.root / 'bootstrap/app.php').write_text(
            '<?php return new class {public function make($x){return new class {public function bootstrap(){}};}};')

    def probe(self):
        with contextlib.redirect_stdout(io.StringIO()) as captured:
            INSTALLER.database_preflight(self.root, os.environ['STORE_SHUBRA_TEST_PHP'], self.root)
        return captured.getvalue()

    def test_reads_actual_policy_and_only_returns_aggregate(self):
        self.assertEqual(self.probe(),
                         'STORE_SHUBRA_FULFILLMENT_DATABASE_OK branch=301 eligible_stores=2\n')

    def test_wrong_target_is_generic_failure(self):
        (self.root / 'vendor/autoload.php').write_text(self.bootstrap.replace("'شبرا'", "'different target'"))
        with self.assertRaisesRegex(RuntimeError, '^SHUBRA_DATABASE_PREFLIGHT_NEEDS_REVIEW$'):
            self.probe()

    def test_accidental_helper_write_is_denied_by_read_only_transaction(self):
        (self.root / self.helper).write_text(r'''<?php
namespace App\Services\Dashboard;
class FasakhanstaStoreFulfillment {
 public static function sourceRestaurantIdsForBranch($branch) {
  \Illuminate\Support\Facades\DB::table('resturants')->insert(['id'=>999,'name'=>'write','parent_id'=>301]);
  return [301,999];
 }
}
''')
        with self.assertRaisesRegex(RuntimeError, '^SHUBRA_DATABASE_PREFLIGHT_NEEDS_REVIEW$'):
            self.probe()


if __name__ == '__main__':
    unittest.main()
