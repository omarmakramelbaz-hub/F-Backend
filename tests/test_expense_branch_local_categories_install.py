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
SPEC = importlib.util.spec_from_file_location('expense_branch_local_categories_installer',
                                            ROOT / 'deployment/apply_expense_branch_local_categories.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)
ACTUAL_BASE = 'c63d40490aedf9400e0e971b0abcb0874a3b4cb8'
BRANCH_EXPENSES = 'app/Services/Dashboard/BranchExpenses.php'
DESKTOP_PATHS = installer.OPTIONAL_PATHS


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
        self.base_bodies = {path: repository_blob(ACTUAL_BASE, path) for path in installer.MODIFIED_PATHS}
        self.expected = {path: (ROOT / path).read_bytes() for path in installer.SOURCE_PATHS}
        for path, body in self.base_bodies.items():
            self.write(path, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual activated compatible source')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for path, body in self.expected.items():
            self.write(path, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'Actual candidate basic categories and optional description source')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        enabled = DESKTOP_PATHS if desktop is True else (desktop if desktop else ())
        for relative in DESKTOP_PATHS:
            if relative not in enabled:
                (self.root / relative).unlink()
                self.expected[relative] = None
        if legacy:
            self.write(BRANCH_EXPENSES, legacy_attachments(self.base_bodies[BRANCH_EXPENSES]))
            self.expected[BRANCH_EXPENSES] = legacy_attachments(self.expected[BRANCH_EXPENSES])
        for relative in installer.MODIFIED_PATHS:
            if (self.root / relative).exists():
                (self.root / relative).chmod(0o640)
        self.before = self.snapshot()
        self.untouched = {path: (self.root / path).read_bytes() for path in DESKTOP_PATHS if (self.root / path).exists()}
        self.real_lint = installer.lint_sources
        self.fail_post_lint = False
        self.state = {'origin': False, 'present': False, 'recorded': False}
        self.migration_calls = []
        self.fail_migration = False
        self.real_run = installer.run
        self.php = os.environ.get('EXPENSE_BRANCH_LOCAL_CATEGORIES_PHP') or shutil.which('php')
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
        return {relative: ((self.root / relative).read_bytes() if (self.root / relative).exists() else None)
                for relative in installer.SOURCE_PATHS}

    def lint(self, php, directory):
        self.real_lint(php, directory)
        if directory == self.root and self.fail_post_lint:
            raise RuntimeError('FIXTURE_POSTCHECK_FAILED')

    def run(self, command, cwd=None):
        if len(command) > 2 and command[1:3] == ['artisan', 'migrate']:
            self.migration_calls.append(command)
            if command[3:] != ['--force', '--path=' + installer.MIGRATION] or cwd != self.root:
                raise AssertionError('Only the scoped additive migration may run')
            if (self.root / installer.MIGRATION).read_bytes() != self.expected[installer.MIGRATION]:
                raise AssertionError('Migration must be installed before execution')
            for relative in installer.MODIFIED_PATHS:
                observed = (self.root / relative).read_bytes() if (self.root / relative).exists() else None
                if observed != self.before[relative]:
                    raise AssertionError('Runtime caller changed before schema was ready')
            self.state['origin'] = True
            if self.fail_migration:
                return subprocess.CompletedProcess(command, 1, b'', b'Fixture partial DDL failure')
            self.state.update(present=True, recorded=True)
            return subprocess.CompletedProcess(command, 0, b'Fixture scoped migration ready', b'')
        return self.real_run(command, cwd)

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'lint_sources', self.lint),
              mock.patch.object(installer, 'schema_state', lambda root, php: dict(self.state)),
              mock.patch.object(installer, 'run', self.run),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, self.php, check_only=check)

    def receipt(self):
        return json.loads(next((self.root.parent / 'expense-branch-local-categories-backups').glob('*/receipt.json')).read_text())

    def assert_desktop_scope(self, case):
        for relative in DESKTOP_PATHS:
            if relative in self.untouched:
                case.assertEqual((self.root / relative).read_bytes(), self.expected[relative])
            else:
                case.assertFalse((self.root / relative).exists())


class ExpenseBranchLocalCategoriesInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.fixture = RealSourceFixture(self.temporary.name, legacy=True)
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.temporary.cleanup()

    def test_check_only_parses_actual_sources_and_leaves_database_and_application_untouched(self):
        before = {path: (self.fixture.root / path).stat() for path in installer.MANDATORY_MODIFIED_PATHS}
        self.assertIsNone(self.fixture.apply(check=True))
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertFalse(self.fixture.migration_calls)
        for path, info in before.items():
            self.assertEqual((self.fixture.root / path).stat().st_ino, info.st_ino)
            self.assertEqual((self.fixture.root / path).stat().st_mtime_ns, info.st_mtime_ns)
        private = self.fixture.root.parent / 'expense-branch-local-categories-backups'
        self.assertEqual([path.name for path in private.iterdir()], ['install.lock'])
        for relative in DESKTOP_PATHS:
            self.assertFalse((self.fixture.root / relative).exists())

    def test_schema_precedes_callers_and_only_exact_scoped_migration_runs(self):
        backup = self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        self.assertEqual(len(self.fixture.migration_calls), 1)
        source = self.fixture.snapshot()[BRANCH_EXPENSES]
        self.assertNotIn(b'$enclosed', source)
        self.assertNotIn(b'DesktopDashboardExpenseAttachments', source)
        for relative in installer.MANDATORY_MODIFIED_PATHS:
            self.assertEqual((backup / relative).read_bytes(), self.fixture.before[relative])
            self.assertEqual(stat.S_IMODE((self.fixture.root / relative).stat().st_mode), 0o640)
            self.assertEqual(stat.S_IMODE((backup / relative).stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE((self.fixture.root / installer.MIGRATION).stat().st_mode), 0o644)
        self.assertFalse((backup / installer.MIGRATION).exists())
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'installed')
        self.assertTrue(receipt['migration_attempted'])
        self.assertFalse(receipt['database_rollback_attempted'])
        self.assertEqual(receipt['schema_after'], {'origin': True, 'present': True, 'recorded': True})
        self.assertEqual({row['path'] for row in receipt['files']},
                         set(installer.NEW_PATHS + installer.MANDATORY_MODIFIED_PATHS))
        self.assertEqual(set(receipt['skipped_paths']), set(DESKTOP_PATHS))
        self.fixture.assert_desktop_scope(self)

    def test_install_and_rerun_preserve_both_attachment_variants_and_optional_desktop(self):
        for legacy in (False, True):
            for desktop in (False, True, (DESKTOP_PATHS[2],)):
                with self.subTest(legacy=legacy, desktop=desktop), tempfile.TemporaryDirectory() as folder:
                    fixture = RealSourceFixture(folder, legacy=legacy, desktop=desktop)
                    fixture.apply()
                    self.assertEqual(fixture.snapshot(), fixture.expected)
                    self.assertIsNone(fixture.apply())
                    self.assertEqual(fixture.snapshot(), fixture.expected)
                    self.assertEqual(len(fixture.migration_calls), 1)
                    self.assertEqual(len(list((fixture.root.parent / 'expense-branch-local-categories-backups').glob('*/receipt.json'))), 1)
                    fixture.assert_desktop_scope(self)

    def test_valid_partial_schema_can_resume_without_replacing_existing_files(self):
        self.fixture.state['origin'] = True
        self.fixture.write(installer.MIGRATION, self.fixture.expected[installer.MIGRATION])
        self.fixture.before = self.fixture.snapshot()
        self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        self.assertEqual(len(self.fixture.migration_calls), 1)

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

    def test_only_present_optional_desktop_data_is_patched_and_absent_services_stay_absent(self):
        relative = DESKTOP_PATHS[2]
        extra = b'\n// Independent live desktop data correction.\n'
        body = repository_blob(ACTUAL_BASE, relative) + extra
        self.fixture.write(relative, body)
        (self.fixture.root / relative).chmod(0o640)
        self.fixture.expected[relative] = (ROOT / relative).read_bytes() + extra
        self.fixture.before = self.fixture.snapshot()
        self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        for missing in DESKTOP_PATHS:
            if missing != relative:
                self.assertFalse((self.fixture.root / missing).exists())

    def test_conflicting_runtime_hunk_fails_before_any_live_write(self):
        before = self.fixture.before[BRANCH_EXPENSES]
        after = self.fixture.expected[BRANCH_EXPENSES]
        removed = next(line for line in before.splitlines(keepends=True)
                       if line not in after.splitlines(keepends=True) and line.strip())
        self.fixture.write(BRANCH_EXPENSES, before.replace(removed, b'// Unreviewed conflicting behavior\n', 1))
        self.fixture.before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + BRANCH_EXPENSES):
            self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertFalse(self.fixture.migration_calls)
        self.assertFalse(list((self.fixture.root.parent / 'expense-branch-local-categories-backups').glob('*/receipt.json')))

    def test_conflicting_new_migration_file_is_rejected(self):
        self.fixture.write(installer.MIGRATION, b'<?php // A different migration.\n')
        self.fixture.before = self.fixture.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'NEW_SOURCE_COLLISION_NEEDS_REVIEW'):
            self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertFalse(self.fixture.migration_calls)

    def test_migration_failure_restores_code_and_retry_uses_partial_additive_schema(self):
        self.fixture.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_EXPENSE_BRANCH_LOCAL_CATEGORIES_MIGRATION_FAILED'):
            self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertEqual(self.fixture.state, {'origin': True, 'present': False, 'recorded': False})
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertTrue(receipt['database_changes_may_remain'])
        self.assertFalse(receipt['database_rollback_attempted'])
        self.fixture.fail_migration = False
        self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.expected)
        self.assertEqual(len(self.fixture.migration_calls), 2)

    def test_postcheck_failure_restores_original_sources_preserving_added_schema(self):
        self.fixture.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'FIXTURE_POSTCHECK_FAILED'):
            self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertEqual(self.fixture.state, {'origin': True, 'present': True, 'recorded': True})
        receipt = self.fixture.receipt()
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertEqual(receipt['review'], [])
        self.assertTrue(receipt['database_changes_may_remain'])
        for relative in installer.MANDATORY_MODIFIED_PATHS:
            self.assertEqual(stat.S_IMODE((self.fixture.root / relative).stat().st_mode), 0o640)

    def test_partial_patch_failure_reverses_only_our_hunks(self):
        real_apply = installer.patch_apply
        live_calls = []
        def fail_third_live(directory, patch, reverse=False):
            if directory == self.fixture.root and not reverse:
                live_calls.append(patch)
                if len(live_calls) == 3:
                    return False
            return real_apply(directory, patch, reverse)
        with mock.patch.object(installer, 'patch_apply', fail_third_live):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_PATCH_FAILED'):
                self.fixture.apply()
        self.assertEqual(self.fixture.snapshot(), self.fixture.before)
        self.assertTrue(all(self.fixture.state.values()))
        self.assertEqual(self.fixture.receipt()['status'], 'code_restored')

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
        relative = installer.MANDATORY_MODIFIED_PATHS[-1]
        target = self.fixture.root / relative
        outside = target.with_name('outside.php')
        target.rename(outside)
        target.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.fixture.apply(check=True)
        self.assertFalse(self.fixture.migration_calls)

    def test_rollback_refuses_to_overwrite_a_concurrent_mode_change(self):
        relative = BRANCH_EXPENSES
        before, info = installer.read_source(self.fixture.root / relative)
        patch = self.fixture.root.parent / 'rollback.diff'
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            patch.write_bytes(installer.legacy_expense_patch(self.fixture.root, self.fixture.release))
        after = self.fixture.expected[relative]
        self.fixture.write(relative, after)
        (self.fixture.root / relative).chmod(0o600)
        review = installer.rollback(self.fixture.root, [(relative, patch, after)], {relative: (before, info)})
        self.assertEqual(review, [relative])
        self.assertEqual((self.fixture.root / relative).read_bytes(), after)
        self.assertEqual(stat.S_IMODE((self.fixture.root / relative).stat().st_mode), 0o600)

    def test_schema_parser_rejects_invalid_and_incomplete_recorded_states(self):
        for result in (
            subprocess.CompletedProcess([], 1, b'', b''),
            subprocess.CompletedProcess([], 0, b'not-json', b''),
            subprocess.CompletedProcess([], 0, b'{"origin":1,"present":true,"recorded":true}', b''),
            subprocess.CompletedProcess([], 0, b'{"origin":true,"present":false,"recorded":true}', b''),
        ):
            with self.subTest(output=result.stdout), mock.patch.object(installer, 'run', return_value=result):
                with self.assertRaisesRegex(RuntimeError, 'SCHEMA_NEEDS_REVIEW|RECORDED_MIGRATION_INCOMPLETE'):
                    installer.schema_state(self.fixture.root, self.fixture.php)
        php_source = self.fixture.root.parent / 'schema-probe.php'
        php_source.write_text('<?php\n' + installer.SCHEMA_PHP)
        result = self.fixture.real_run([self.fixture.php, '-l', str(php_source)])
        self.assertEqual(result.returncode, 0, result.stderr.decode())

    def test_manifest_schema_probe_and_root_wrapper_are_narrow(self):
        self.assertEqual(installer.BASE_REVISION, ACTUAL_BASE)
        self.assertEqual(installer.NEW_PATHS, (installer.MIGRATION,))
        self.assertEqual(set(installer.MANDATORY_MODIFIED_PATHS), {
            BRANCH_EXPENSES, 'app/Services/Dashboard/ExpenseCategories.php',
            'public/dashboard/js/branch-expenses.js', 'resources/views/admin/expenses/index.blade.php',
            'resources/lang/ar/expenses.php', 'resources/lang/en/expenses.php'})
        wrapper = (ROOT / 'deployment/apply_expense_branch_local_categories_root.sh').read_text()
        self.assertIn('expense_source=deployment/apply_expense_branch_local_categories.py', wrapper)
        self.assertIn('expense_base=' + ACTUAL_BASE, wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        self.assertNotIn('artisan', wrapper)
        for required in ('information_schema.STATISTICS', 'information_schema.COLUMNS', 'INNODB',
                         'expense_category_branch_key_unique', 'origin_branch', 'unsigned'):
            self.assertIn(required, installer.SCHEMA_PHP)
        self.assertEqual(set(installer.OPTIONAL_PATHS), set(DESKTOP_PATHS))
        self.assertNotIn('DesktopDashboardSchema', installer.SCHEMA_PHP)
        self.assertNotIn('drop', installer.SCHEMA_PHP.lower())

    def test_release_requires_its_exact_available_base_and_full_commit(self):
        before = self.fixture.snapshot()
        with mock.patch.object(installer, 'BASE_REVISION', '0' * 40):
            with self.assertRaisesRegex(RuntimeError, 'PINNED_COMMIT_UNAVAILABLE'):
                installer.install(self.fixture.root, self.fixture.release, self.fixture.php, check_only=True)
        with mock.patch.object(installer, 'BASE_REVISION', self.fixture.base):
            with self.assertRaisesRegex(RuntimeError, 'FULL_REVISION_REQUIRED'):
                installer.install(self.fixture.root, self.fixture.release[:12], self.fixture.php, check_only=True)
        self.assertEqual(self.fixture.snapshot(), before)

    def test_optional_desktop_file_appearing_during_preflight_is_preserved_and_blocks_install(self):
        relative = DESKTOP_PATHS[2]
        concurrent = repository_blob(ACTUAL_BASE, relative) + b'\n// Concurrent independent update.\n'
        real_lint = self.fixture.lint
        def create_during_lint(php, directory):
            real_lint(php, directory)
            if directory != self.fixture.root:
                self.fixture.write(relative, concurrent)
        self.fixture.lint = create_during_lint
        with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative):
            self.fixture.apply()
        self.assertEqual((self.fixture.root / relative).read_bytes(), concurrent)
        for path, before in self.fixture.before.items():
            if path != relative:
                self.assertEqual(self.fixture.snapshot()[path], before)
        self.assertFalse(self.fixture.migration_calls)


class SchemaProbeTests(unittest.TestCase):
    """Run the real PHP probe against small read-only facade doubles."""
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.php = os.environ.get('EXPENSE_BRANCH_LOCAL_CATEGORIES_PHP') or shutil.which('php')
        if not self.php:
            raise unittest.SkipTest('PHP executable required')
        (self.root / 'vendor').mkdir()
        (self.root / 'bootstrap').mkdir()
        (self.root / 'vendor/autoload.php').write_text(r'''<?php
namespace Illuminate\Support\Facades {
class Schema {
 public static function hasTable($table) {
  return $table!=='branch_expense_category_overrides'||DB::$state['present'];
 }
 public static function hasColumn($table,$column){return DB::$state['origin'];}
 public static function hasColumns($table,$columns){return empty(DB::$state['missing_columns']);}
}
class DB {
 public static $state;
 public static function connection(){return new class {
  public function getDriverName(){return DB::$state['driver']??'mysql';}
  public function getTablePrefix(){return 'fixture_';}
 };}
 public static function table($table){return new class {
  public function where(...$args){return $this;}
  public function count(){return DB::$state['recorded'];}
 };}
 public static function selectOne($query,$args){
  if(str_contains($query,'information_schema.TABLES'))return(object)['table_engine'=>DB::$state['engine']??'InnoDB'];
  if(!str_contains($query,'information_schema.COLUMNS'))throw new \RuntimeException();
  $columns=[
   'origin_branch'=>['varchar','varchar(30)',30,'YES'],
   'branch'=>['varchar','varchar(30)',30,'NO'],
   'category_key'=>['varchar','varchar(60)',60,'NO'],
   'name'=>['varchar','varchar(80)',80,'YES'],
   'active'=>['tinyint','tinyint(1)',null,'NO'],
   'revision'=>['int','int unsigned',null,'NO'],
  ];
  $name=$args[1];$value=DB::$state['columns'][$name]??$columns[$name];
  return(object)array_combine(['data_type','column_type','max_length','is_nullable'],$value);
 }
 public static function select($query,$args){
  if(!str_contains($query,'information_schema.STATISTICS'))throw new \RuntimeException();
  $rows=[];foreach(DB::$state['unique']??['branch','category_key']as$position=>$name){
   $rows[]=(object)['index_name'=>DB::$state['index_name']??'expense_category_branch_key_unique',
    'column_name'=>$name,'position'=>$position+1,'non_unique'=>DB::$state['non_unique']??0];
  }return$rows;
 }
}
DB::$state=json_decode(file_get_contents('schema-fixture.json'),true,512,JSON_THROW_ON_ERROR);
}
''')
        (self.root / 'bootstrap/app.php').write_text(r'''<?php
return new class {
 public function make($name){return new class {public function bootstrap(){}};}
};
''')

    def tearDown(self):
        self.temporary.cleanup()

    def probe(self, state):
        (self.root / 'schema-fixture.json').write_text(json.dumps(state))
        return installer.schema_state(self.root, self.php)

    def test_valid_empty_partial_and_complete_additive_schema(self):
        for origin, present, recorded in ((False, False, 0), (True, False, 0),
                                           (False, True, 0), (True, True, 0), (True, True, 1)):
            with self.subTest(origin=origin, present=present, recorded=recorded):
                self.assertEqual(self.probe(dict(origin=origin, present=present, recorded=recorded)),
                                 dict(origin=origin, present=present, recorded=bool(recorded)))

    def test_wrong_engine_driver_missing_columns_and_duplicate_migration_are_rejected(self):
        for override in ({'engine': 'MyISAM'}, {'driver': 'sqlite'},
                         {'missing_columns': True}, {'recorded': 2},
                         {'origin': False, 'recorded': 1}, {'present': False, 'recorded': 1}):
            state = dict(origin=True, present=True, recorded=0)
            state.update(override)
            with self.subTest(override=override), self.assertRaisesRegex(RuntimeError, 'SCHEMA_NEEDS_REVIEW'):
                self.probe(state)

    def test_wrong_column_type_length_nullability_and_revision_signedness_are_rejected(self):
        for column, definition in (
            ('origin_branch', ['varchar', 'varchar(60)', 60, 'YES']),
            ('origin_branch', ['varchar', 'varchar(30)', 30, 'NO']),
            ('branch', ['varchar', 'varchar(30)', 30, 'YES']),
            ('category_key', ['varchar', 'varchar(40)', 40, 'NO']),
            ('name', ['varchar', 'varchar(80)', 80, 'NO']),
            ('active', ['int', 'int', None, 'NO']),
            ('revision', ['int', 'int', None, 'NO']),
        ):
            with self.subTest(column=column, definition=definition), self.assertRaisesRegex(RuntimeError, 'SCHEMA_NEEDS_REVIEW'):
                self.probe(dict(origin=True, present=True, recorded=0, columns={column: definition}))

    def test_named_unique_branch_category_index_is_required(self):
        for override in ({'unique': ['category_key', 'branch']}, {'unique': ['branch']},
                         {'unique': ['branch', 'category_key', 'name']},
                         {'index_name': 'some_other_index'}, {'non_unique': 1}):
            state = dict(origin=True, present=True, recorded=0)
            state.update(override)
            with self.subTest(override=override), self.assertRaisesRegex(RuntimeError, 'SCHEMA_NEEDS_REVIEW'):
                self.probe(state)


if __name__ == '__main__':
    unittest.main()


