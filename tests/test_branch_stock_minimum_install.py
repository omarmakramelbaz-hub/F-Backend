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
SPEC = importlib.util.spec_from_file_location('branch_stock_minimum_installer', ROOT / 'deployment/apply_branch_stock_minimum.py')
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
            if command[2] == 'route:clear':
                (self.root / 'bootstrap/cache/routes-v7.php').unlink()
                return subprocess.CompletedProcess(command, 0, stdout=b'', stderr=b'')
            assert command[2] == 'migrate'
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
            raise RuntimeError('STOCK_MINIMUM_SCHEMA_NEEDS_REVIEW')
        return dict(self.state)

    def runtime(self, root, php, target=False):
        if target and getattr(self, 'fail_runtime', False):
            raise RuntimeError('STOCK_MINIMUM_RUNTIME_POSTCHECK_FAILED')
        return {'route_cache_path': 'bootstrap/cache/routes-v7.php', 'owner_email_ready': True, 'mail_transport_ready': True}

    def lint(self, php, stage, root, cache):
        for relative in installer.SOURCE_PATHS:
            assert (stage / relative).is_file()
        if stage == root and self.fail_post_lint:
            raise RuntimeError('PHP_PARSE_INVALID')

    def apply(self, check=False):
        with (mock.patch.object(installer, 'BASE_REVISION', self.base),
              mock.patch.object(installer, 'run', self.run),
              mock.patch.object(installer, 'schema_state', self.schema),
              mock.patch.object(installer, 'runtime_state', self.runtime),
              mock.patch.object(installer, 'lint_sources', self.lint),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root, self.release, 'fixture-php', check_only=check)

    def originals(self, case):
        for relative, before in self.before.items():
            case.assertEqual((self.root / relative).read_bytes(), before)
        for relative in installer.NEW_PATHS:
            case.assertFalse((self.root / relative).exists())

    def receipt(self):
        return json.loads(next((self.root.parent / 'branch-stock-minimum-backups').glob('*/receipt.json')).read_text())


class ActualSourceFixture(Fixture):
    """Use this release's real hunks and the known independent production repairs."""
    def __init__(self, folder):
        super().__init__(folder)
        self.before = {}
        for relative in installer.MODIFIED_PATHS:
            body = subprocess.run(['git', '-C', str(ROOT), 'show', installer.BASE_REVISION + ':' + relative],
                                  check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
            self.before[relative] = body
            self.write(relative, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'actual source base')
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.after = {relative: (ROOT / relative).read_bytes() for relative in installer.SOURCE_PATHS}
        for relative, body in self.after.items():
            self.write(relative, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'actual feature hunks')
        self.release = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        transforms = {
            'resources/views/admin/branch_stock/index.blade.php': lambda body: body.replace(b'@endif@foreach(', b'@endif\n@foreach('),
            'app/Console/Kernel.php': lambda body: body.replace(
                b'protected function schedule(Schedule $schedule)\n    {\n',
                b"protected function schedule(Schedule $schedule)\n    {\n        $schedule->command('employees:mark-auto-absence')->everyMinute()->withoutOverlapping(5);\n", 1),
            'public/dashboard/js/branch-stock.js': lambda body: body + b'\n// Independent live display correction retained.\n',
            'app/Services/Dashboard/BranchInventory.php': lambda body: body + b'\n// Independent live stock correction retained.\n',
        }
        for relative, before in self.before.items():
            transform = transforms.get(relative, lambda body: body)
            self.before[relative] = transform(before)
            self.after[relative] = transform(self.after[relative])
            self.write(relative, self.before[relative])
            (self.root / relative).chmod(0o640)


class BasicRecipeSourceFixture(ActualSourceFixture):
    """The independently published recipe patch is the live source evidence."""
    def __init__(self, folder):
        super().__init__(folder)
        self.git('apply', str(ROOT / 'tests/fixtures/branch_stock_basic_recipes_20261018.patch'))
        for relative in installer.MODIFIED_PATHS:
            self.before[relative] = (self.root / relative).read_bytes()
        self.main_controller = 'app/Http/Controllers/Dashboard/BranchStockController.php'
        self.write(self.main_controller, (ROOT / 'tests/fixtures/branch_stock_basic_recipes_controller.php').read_bytes())
        (self.root / self.main_controller).chmod(0o640)
        self.controller_before = installer.read_source(self.root / self.main_controller)
        # The expected source keeps the original recipe block and adds minimum busy gating.
        relative = 'public/dashboard/js/branch-stock.js'
        stock_busy = (b'        var stockBusy = busy || minimumBusy || !!minimumEditor || loading || !!frozen;\n'
                      b'        root.dataset.stockBusy = String(stockBusy);\n'
                      b"        root.dispatchEvent(new CustomEvent('inventory:stock-busy', { detail: stockBusy }));\n")
        self.after[relative] = self.after[relative].replace(b'    function lock() {\n', b'    function lock() {\n' + stock_busy, 1)
        relative = 'public/dashboard/css/branch-stock.css'
        base = subprocess.run(['git', '-C', str(ROOT), 'show', installer.BASE_REVISION + ':' + relative],
                              check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
        feature_suffix = self.after[relative][len(base):]
        self.after[relative] = self.before[relative] + feature_suffix
        # Recipe view and routes receive their unchanged independent patch alongside minimums.
        for relative in ('routes/branch_stock.php', 'resources/views/admin/branch_stock/index.blade.php'):
            original = self.root / relative
            original.write_bytes(self.after[relative])
        retained_patch = ROOT / 'tests/fixtures/branch_stock_basic_recipes_20261018.patch'
        self.git('apply', '--include=routes/branch_stock.php', '--include=resources/views/admin/branch_stock/index.blade.php', str(retained_patch))
        for relative in ('routes/branch_stock.php', 'resources/views/admin/branch_stock/index.blade.php'):
            self.after[relative] = (self.root / relative).read_bytes()
            self.write(relative, self.before[relative])


class StockMinimumInstallerTests(unittest.TestCase):
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
        private = self.fixture.root.parent / 'branch-stock-minimum-backups'
        self.assertEqual([path.name for path in private.iterdir()], ['install.lock'])

    def test_check_rejects_unwritable_mail_directory_before_any_live_write(self):
        mail = self.fixture.root / 'app/Mail'
        mail.mkdir(parents=True, exist_ok=True)
        real_access = os.access
        def access(path, mode, **options):
            self.assertTrue(options.get('effective_ids'))
            return False if Path(path) == mail else real_access(path, mode, **options)
        with mock.patch.object(installer.os, 'access', side_effect=access):
            with self.assertRaisesRegex(RuntimeError, 'DESTINATION_PARENT_NOT_WRITABLE: app/Mail'):
                self.fixture.apply(check=True)
        self.fixture.originals(self)
        self.assertEqual(self.fixture.calls, [])

    def test_destination_check_handles_missing_parent_and_rejects_non_directory(self):
        # New nested directories can be created only under a writable existing ancestor.
        installer.preflight_destination(self.fixture.root, 'new/nested/class.php')
        self.fixture.write('blocked', b'existing ordinary file\n')
        with self.assertRaisesRegex(RuntimeError, 'DESTINATION_PARENT_NOT_DIRECTORY'):
            installer.preflight_destination(self.fixture.root, 'blocked/class.php')

    def test_patch_failure_diagnostic_never_emits_raw_git_output(self):
        private = b'error: unable to write app/Mail/file.php: Permission denied PRIVATE_TOKEN_AND_CUSTOMER_DATA'
        output = io.StringIO()
        with mock.patch.object(installer, 'run', return_value=subprocess.CompletedProcess(
                [], 1, stdout=b'PRIVATE_STDOUT', stderr=private)), contextlib.redirect_stdout(output):
            self.assertFalse(installer.patch_apply(self.fixture.root, self.fixture.root / 'dummy.diff'))
        self.assertIn('category=permission_denied', output.getvalue())
        self.assertNotIn('PRIVATE', output.getvalue())
        for expected, stderr in (
                ('read_only_filesystem', b'Read-only file system'),
                ('storage_full', b'No space left on device'),
                ('destination_conflict', b'File already exists'),
                ('hunk_conflict', b'patch failed: untrusted path'),
                ('git_error', b'unknown private output')):
            self.assertEqual(installer.patch_failure_category(stderr), expected)

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
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_STOCK_MINIMUM_MIGRATION_FAILED'):
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
        wrapper = (ROOT / 'deployment/apply_branch_stock_minimum_root.sh').read_text()
        self.assertIn(installer.BASE_REVISION, wrapper)
        self.assertIn('runuser -u fasakha -- python3', wrapper)
        self.assertIn('--revision "$stock_revision"', wrapper)

    def test_schema_reply_rejects_missing_or_non_boolean_fields(self):
        for reply in (b'PRIVATE_SQL_EXCEPTION', b'{}', b'{"present":1,"recorded":false,"seeded":true}'):
            with self.subTest(reply=reply), mock.patch.object(installer, 'run', return_value=
                    subprocess.CompletedProcess([], 0, stdout=reply, stderr=b'PRIVATE_DETAIL')):
                with self.assertRaisesRegex(RuntimeError, '^STOCK_MINIMUM_SCHEMA_NEEDS_REVIEW$'):
                    installer.schema_state(self.fixture.root, 'fixture-php')

    def test_recorded_migration_must_have_complete_feature_schema(self):
        with mock.patch.object(installer, 'run', return_value=subprocess.CompletedProcess(
                [], 0, stdout=b'{"present":false,"recorded":true}', stderr=b'')):
            with self.assertRaisesRegex(RuntimeError, 'RECORDED_MIGRATION_INCOMPLETE'):
                installer.schema_state(self.fixture.root, 'fixture-php')

    def test_cached_routes_are_backed_up_then_cleared_without_running_mail_job(self):
        relative = 'bootstrap/cache/routes-v7.php'
        body = b'old reviewed route cache\n'
        self.fixture.write(relative, body)
        (self.fixture.root / relative).chmod(0o640)
        backup = self.fixture.apply()
        self.assertEqual((backup / relative).read_bytes(), body)
        self.assertFalse((self.fixture.root / relative).exists())
        self.assertEqual([call[2] for call in self.fixture.calls], ['migrate', 'route:clear'])
        receipt = self.fixture.receipt()
        self.assertTrue(receipt['route_cache']['cleared'])
        self.assertTrue(receipt['mail_readiness']['owner_email_ready'])

    def test_failed_runtime_check_restores_exact_old_route_cache_and_code(self):
        relative = 'bootstrap/cache/routes-v7.php'
        body = b'old route cache with independent live corrections\n'
        self.fixture.write(relative, body)
        target = self.fixture.root / relative
        target.chmod(0o640)
        before_info = target.stat()
        self.fixture.fail_runtime = True
        with self.assertRaisesRegex(RuntimeError, 'RUNTIME_POSTCHECK_FAILED'):
            self.fixture.apply()
        self.fixture.originals(self)
        self.assertEqual(target.read_bytes(), body)
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o640)
        self.assertEqual(target.stat().st_mtime_ns, before_info.st_mtime_ns)

    def test_rollback_does_not_replace_an_independently_rebuilt_route_cache(self):
        relative = 'bootstrap/cache/routes-v7.php'
        self.fixture.write(relative, b'old cache\n')
        original = installer.read_source(self.fixture.root / relative)
        self.fixture.write(relative, b'concurrent rebuild\n')
        with self.assertRaisesRegex(RuntimeError, 'UNOWNED_ROUTE_CACHE_CHANGE'):
            installer.restore_route_cache(self.fixture.root, relative, original)
        self.assertEqual((self.fixture.root / relative).read_bytes(), b'concurrent rebuild\n')

    def test_dedicated_timer_has_a_delay_and_no_install_dispatch(self):
        wrapper = (ROOT / 'deployment/apply_branch_stock_minimum_root.sh').read_text()
        self.assertIn('OnActiveSec=60s', wrapper)
        self.assertIn('OnUnitInactiveSec=60s', wrapper)
        self.assertIn('systemctl', wrapper)
        self.assertIn('fasakhansta-stock-minimums.timer', wrapper)
        self.assertIn('no_mail_sent_by_installer=1', wrapper)
        self.assertNotIn('migrate:fresh', wrapper)
        self.assertNotIn("'branch-stock:notify-minimum'", wrapper)

    def test_actual_feature_hunks_preserve_independent_live_repairs(self):
        folder = Path(self.temporary.name) / 'actual'
        folder.mkdir()
        actual = ActualSourceFixture(folder)
        backup = actual.apply()
        self.assertTrue(backup.is_dir())
        for relative, expected in actual.after.items():
            self.assertEqual((actual.root / relative).read_bytes(), expected, relative)
        self.assertEqual([call[2] for call in actual.calls], ['migrate'])
        self.assertIsNone(actual.apply())
        self.assertEqual(len(actual.calls), 1)

    def basic_fixture(self):
        folder = Path(self.temporary.name) / 'basic-recipes'
        folder.mkdir()
        return BasicRecipeSourceFixture(folder)

    def test_known_live_basic_recipe_js_and_css_reproduce_original_hunk_conflicts(self):
        actual = self.basic_fixture()
        with mock.patch.object(installer, 'BASE_REVISION', actual.base):
            for relative in installer.BASIC_COMPAT_PATHS:
                patch = actual.root.parent / 'original-feature.diff'
                patch.write_bytes(installer.release_patch(actual.root, actual.release, relative))
                self.assertFalse(installer.patch_check(actual.root, patch), relative)

    def test_live_basic_recipe_feature_is_preserved_without_touching_its_controller(self):
        actual = self.basic_fixture()
        self.assertNotIn(actual.main_controller, installer.SOURCE_PATHS)
        actual.apply()
        self.assertTrue(installer.unchanged(actual.root / actual.main_controller, actual.controller_before))
        for relative, expected in actual.after.items():
            self.assertEqual((actual.root / relative).read_bytes(), expected, relative)
        self.assertIsNone(actual.apply())
        self.assertEqual([call[2] for call in actual.calls], ['migrate'])
        self.assertTrue(installer.unchanged(actual.root / actual.main_controller, actual.controller_before))

    def test_live_basic_recipe_variant_rolls_back_exactly_on_migration_failure(self):
        actual = self.basic_fixture()
        actual.fail_migration = True
        with self.assertRaisesRegex(RuntimeError, 'SCOPED_STOCK_MINIMUM_MIGRATION_FAILED'):
            actual.apply()
        actual.originals(self)
        self.assertTrue(installer.unchanged(actual.root / actual.main_controller, actual.controller_before))

    def test_published_basic_compatibility_hunks_reverse_exactly_on_postcheck_failure(self):
        actual = self.basic_fixture()
        actual.fail_post_lint = True
        with self.assertRaisesRegex(RuntimeError, 'PHP_PARSE_INVALID'):
            actual.apply()
        actual.originals(self)
        self.assertTrue(installer.unchanged(actual.root / actual.main_controller, actual.controller_before))
        self.assertTrue(all(actual.state.values()))
        self.assertEqual(actual.receipt()['status'], 'code_restored')

    def test_unknown_live_edit_inside_minimum_affected_js_still_stops_without_writes(self):
        actual = self.basic_fixture()
        relative = 'public/dashboard/js/branch-stock.js'
        changed = actual.before[relative].replace(b'function locked() { return recipeBusy || busy || loading || !!frozen; }',
                         b'function locked() { return independently_changed_lock(); }', 1)
        self.assertNotEqual(changed, actual.before[relative])
        actual.before[relative] = changed
        actual.write(relative, changed)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative):
            actual.apply()
        actual.originals(self)
        self.assertEqual(actual.calls, [])

    def test_independently_changed_recipe_busy_gate_requires_review(self):
        actual = self.basic_fixture()
        relative = 'public/dashboard/js/branch-stock.js'
        changed = actual.before[relative].replace(b'var stockBusy = busy || loading || !!frozen;',
                                                 b'var stockBusy = customRecipeBusy();', 1)
        self.assertNotEqual(changed, actual.before[relative])
        actual.before[relative] = changed
        actual.write(relative, changed)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative):
            actual.apply()
        actual.originals(self)
        self.assertEqual(actual.calls, [])

    def test_independently_changed_recipe_css_requires_review(self):
        actual = self.basic_fixture()
        relative = 'public/dashboard/css/branch-stock.css'
        changed = actual.before[relative].replace(b'.bs-basic-dialog h2{font-size:21px;',
                                                 b'.bs-basic-dialog h2{font-size:23px;', 1)
        self.assertNotEqual(changed, actual.before[relative])
        actual.before[relative] = changed
        actual.write(relative, changed)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative):
            actual.apply()
        actual.originals(self)
        self.assertEqual(actual.calls, [])


if __name__ == '__main__':
    unittest.main()
