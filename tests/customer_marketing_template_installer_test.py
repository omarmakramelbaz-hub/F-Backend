#!/usr/bin/env python3
"""Exercise surgical deployment, read-only checks and rollback on disposable trees."""
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

REPO = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    'customer_marketing_template_installer', REPO / 'deployment/apply_customer_marketing_template.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class InstallerTest(unittest.TestCase):
    FIRST = 'app/first.txt'
    SECOND = 'routes/second.txt'

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
        self.release = self.base.replace(b'line 3\n', b'marketing setup route\n').replace(
            b'line 30\n', b'prepare marketing template\n')
        for relative in (self.FIRST, self.SECOND):
            self.write(relative, self.base)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture baseline')
        self.base_revision = self.git('rev-parse', 'HEAD').strip().decode()
        for relative in (self.FIRST, self.SECOND):
            self.write(relative, self.release)
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'Fixture release')
        self.revision = self.git('rev-parse', 'HEAD').strip().decode()
        # Restore tracked fixture files without altering the checked-out branch/index.
        for relative in (self.FIRST, self.SECOND):
            self.write(relative, self.base)
        self.source_patch = mock.patch.multiple(INSTALLER, BASE_REVISION=self.base_revision,
                                                SOURCE_PATHS=(self.FIRST, self.SECOND))
        self.source_patch.start()
        self.addCleanup(self.source_patch.stop)
        for name in ('lint_sources', 'route_check', 'clear_caches', 'crm_check'):
            patch = mock.patch.object(INSTALLER, name)
            setattr(self, name, patch.start())
            self.addCleanup(patch.stop)

    def git(self, *arguments):
        result = subprocess.run(['git', '-C', str(self.root), *arguments],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def write(self, relative, body):
        target = self.root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(body)
        return target

    def install(self, check_only=False):
        with contextlib.redirect_stdout(io.StringIO()):
            return INSTALLER.install(self.root, self.revision, '/unused/php', check_only)

    def receipts(self):
        return list((self.parent / 'customer-marketing-template-backups').glob('*/receipt.json'))

    def assert_originals(self):
        for relative in (self.FIRST, self.SECOND):
            self.assertEqual((self.root / relative).read_bytes(), self.base)

    def test_clean_install_private_backup_preserved_modes_and_idempotence(self):
        (self.root / self.FIRST).chmod(0o750)
        backup = self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.release)
        self.assertEqual((self.root / self.FIRST).stat().st_mode & 0o777, 0o750)
        self.assertEqual((backup / self.FIRST).read_bytes(), self.base)
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup / self.FIRST).stat().st_mode & 0o777, 0o600)
        self.assertEqual((backup / 'install.log').stat().st_mode & 0o777, 0o600)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['database_writes'])
        self.assertFalse(receipt['messages_sent'])
        self.assertFalse(receipt['settings_enabled'])
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)

    def test_unrelated_live_edits_and_partial_hunks_are_preserved(self):
        live = self.base.replace(b'line 3\n', b'marketing setup route\n').replace(
            b'line 15\n', b'independent live feature\n')
        self.write(self.FIRST, live)
        self.install()
        expected = self.release.replace(b'line 15\n', b'independent live feature\n')
        self.assertEqual((self.root / self.FIRST).read_bytes(), expected)
        self.assertIsNone(self.install())

    def test_conflicting_last_file_stops_before_any_code_write(self):
        conflict = self.base.replace(b'line 30\n', b'conflicting live change\n')
        self.write(self.SECOND, conflict)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
            self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), self.base)
        self.assertEqual((self.root / self.SECOND).read_bytes(), conflict)
        self.assertEqual(self.receipts(), [])

    def test_check_mode_changes_no_code_or_caches(self):
        self.install(check_only=True)
        self.assert_originals()
        self.clear_caches.assert_not_called()
        self.crm_check.assert_not_called()
        self.assertEqual(self.receipts(), [])

    def test_stage_syntax_or_route_failure_changes_no_code(self):
        for check, reason in ((self.lint_sources, 'PHP_SYNTAX_INVALID'),
                              (self.route_check, 'MARKETING_TEMPLATE_ROUTE_CHECK_FAILED')):
            check.side_effect = RuntimeError(reason)
            with self.assertRaisesRegex(RuntimeError, reason):
                self.install()
            self.assert_originals()
            self.assertEqual(self.receipts(), [])
            check.side_effect = None

    def test_concurrent_preflight_edit_is_preserved_and_stops_install(self):
        concurrent = self.base + b'operator edit during preflight\n'
        self.route_check.side_effect = lambda *a, **k: self.write(self.FIRST, concurrent)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_PREFLIGHT'):
            self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), concurrent)
        self.assertEqual((self.root / self.SECOND).read_bytes(), self.base)

    def test_live_route_or_crm_check_failure_restores_all_code(self):
        self.crm_check.side_effect = RuntimeError('CUSTOMER_CRM_ROUTE_CHECK_FAILED')
        with self.assertRaisesRegex(RuntimeError, 'CUSTOMER_CRM_ROUTE_CHECK_FAILED'):
            self.install()
        self.assert_originals()
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'code_restored')
        self.assertEqual(receipt['review'], [])
        self.assertTrue((self.receipts()[0].parent / 'install.log').exists())
        self.assertEqual(self.clear_caches.call_count, 2)

    def test_atomic_failure_on_second_file_restores_first_file(self):
        real_atomic = INSTALLER.atomic
        def fail_second(path, body, info):
            if path == self.root / self.SECOND and body == self.release:
                raise OSError('SIMULATED_ATOMIC_FAILURE')
            real_atomic(path, body, info)
        with mock.patch.object(INSTALLER, 'atomic', side_effect=fail_second):
            with self.assertRaisesRegex(OSError, 'SIMULATED_ATOMIC_FAILURE'):
                self.install()
        self.assert_originals()
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'code_restored')

    def test_rollback_preserves_concurrent_operator_change(self):
        concurrent = self.release + b'operator change after installation\n'
        def fail_postcheck(*args, **kwargs):
            self.write(self.FIRST, concurrent)
            raise RuntimeError('SIMULATED_POSTCHECK_FAILURE')
        self.crm_check.side_effect = fail_postcheck
        with self.assertRaisesRegex(RuntimeError, 'SIMULATED_POSTCHECK_FAILURE'):
            self.install()
        self.assertEqual((self.root / self.FIRST).read_bytes(), concurrent)
        self.assertEqual((self.root / self.SECOND).read_bytes(), self.base)
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_review_required')
        self.assertEqual(receipt['review'], [self.FIRST])

    def test_symlink_and_hardlink_sources_are_refused(self):
        target = self.parent / 'outside.txt'
        target.write_bytes(b'outside bytes\n')
        source = self.root / self.FIRST
        source.unlink()
        source.symlink_to(target)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        self.assertEqual(target.read_bytes(), b'outside bytes\n')
        source.unlink()
        source.write_bytes(self.base)
        os.link(source, self.parent / 'hardlink.txt')
        with self.assertRaisesRegex(RuntimeError, 'REGULAR_UNLINKED_SOURCE_REQUIRED'):
            self.install()
        self.assert_originals()

    def test_wrong_release_manifest_is_refused(self):
        with mock.patch.object(INSTALLER, 'SOURCE_PATHS', (self.FIRST, self.SECOND, 'missing.txt')):
            with self.assertRaisesRegex(RuntimeError, 'RELEASE_SOURCE_TREE_MISMATCH'):
                self.install()
        self.assert_originals()

    def test_only_cache_clear_and_read_only_crm_commands_are_run(self):
        # Use original functions captured before patching by import in a separate module.
        spec = importlib.util.spec_from_file_location('original_marketing_installer', SPEC.origin)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        commands = []
        def command(command, cwd=None):
            commands.append(command)
            output = b'{"ok":true,"verified_routes":15,"requests_dispatched":0}\n'
            return subprocess.CompletedProcess(command, 0, output, b'')
        with mock.patch.object(module, 'run', side_effect=command):
            log = io.BytesIO()
            module.clear_caches(self.root, '/php', log)
            module.crm_check(self.root, '/php', log)
        self.assertEqual([entry[2:] for entry in commands],
                         [['route:clear'], ['config:clear'], ['view:clear'], ['crm:check']])


@unittest.skipUnless(os.environ.get('CRM_TEST_PHP') and os.environ.get('CRM_TEST_AUTOLOAD'),
                     'Set CRM_TEST_PHP and CRM_TEST_AUTOLOAD for isolated real Laravel route probes.')
class RealRouteProbeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / 'vendor').mkdir()
        (self.root / 'vendor/autoload.php').write_text(
            '<?php require ' + json.dumps(os.environ['CRM_TEST_AUTOLOAD']) + ';\n'
            'spl_autoload_register(function($class){ if(str_starts_with($class,"App\\\\")) {'
            '$file=' + json.dumps(str(REPO / 'app') + '/') + '.str_replace("\\\\","/",substr($class,4)).".php";'
            'if(is_file($file)) require_once $file; }});\n')
        for relative in ('routes/branch_operations.php',
                         'app/Http/Controllers/Dashboard/BranchOperationsController.php'):
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((REPO / relative).read_bytes())

    def probe(self):
        with contextlib.redirect_stdout(io.StringIO()) as captured:
            INSTALLER.route_check(self.root, os.environ['CRM_TEST_PHP'], self.root)
        return captured.getvalue()

    def test_real_routes_pass_without_dispatch_or_database(self):
        self.assertEqual(self.probe(), 'CUSTOMER_MARKETING_TEMPLATE_ROUTE_OK requests_dispatched=0\n')

    def test_wrong_method_missing_admin_or_shadow_route_is_rejected(self):
        target = self.root / 'routes/branch_operations.php'
        baseline = target.read_bytes()
        variants = [
            baseline.replace(b"Route::post('marketing-template-prepare'", b"Route::get('marketing-template-prepare'"),
            baseline.replace(b"['lang','IsAdmin']", b"['lang']"),
            baseline.replace(b"Route::middleware(['lang','IsAdmin'])", b"Route::post('admin/customers/{path}',fn()=>null)->where('path','.*');\nRoute::middleware(['lang','IsAdmin'])"),
        ]
        for variant in variants:
            target.write_bytes(variant)
            with self.assertRaisesRegex(RuntimeError, '^MARKETING_TEMPLATE_ROUTE_CHECK_FAILED$'):
                self.probe()


if __name__ == '__main__':
    unittest.main()
