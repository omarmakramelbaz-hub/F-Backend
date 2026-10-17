#!/usr/bin/env python3
"""Exercise delivery workflow publication and rollback in disposable Git roots."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


PROJECT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    'phone_delivery_workflow_installer', PROJECT / 'deployment/apply_phone_delivery_workflow.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)
HELPER = 'app/Services/Dashboard/BranchDeliveryTariff.php'


class WorkflowInstallerTest(unittest.TestCase):
    @contextlib.contextmanager
    def fixture(self, php_hook=None):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            root.mkdir()
            def git(*arguments):
                result = subprocess.run(['git', '-C', str(root), *arguments], check=True,
                                        stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                return result.stdout.decode().strip()
            git('init', '--quiet')
            git('config', 'user.email', 'fixture@example.invalid')
            git('config', 'user.name', 'Installer Fixture')
            before = {}
            for relative, status in INSTALLER.SOURCE_MANIFEST.items():
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                if status == 'A':
                    before[relative] = None
                    continue
                body = b''.join(b'// stable fixture line %02d\n' % number for number in range(30))
                body = body.replace(b'stable fixture line 15', b'previous delivery behavior')
                target.write_bytes(body)
                before[relative] = body
            # Future unrelated invoice changes must neither gate nor change this update.
            invoice = root / 'app/Services/Dashboard/PosReceiptPrinting.php'
            invoice.write_bytes(b'<?php // Independent future invoice policy.\n')
            (root / 'artisan').write_text('<?php // fixture\n')
            git('add', '.')
            git('commit', '--quiet', '-m', 'Already deployed baseline')
            base = git('rev-parse', 'HEAD')
            after = {}
            for relative, body in before.items():
                if body is None:
                    updated = b'<?php\n// New branch tariff helper.\n'
                elif relative.endswith('.css'):
                    updated = body.replace(b'// previous delivery behavior\n',
                                           b'// previous delivery behavior\n// workflow insertion\n')
                else:
                    updated = body.replace(b'previous delivery behavior', b'new delivery workflow behavior')
                (root / relative).write_bytes(updated)
                after[relative] = updated
            git('add', '.')
            git('commit', '--quiet', '-m', 'Delivery workflow release')
            revision = git('rev-parse', 'HEAD')
            git('checkout', '--quiet', base)
            php_calls = []
            original_run = INSTALLER.run
            def runner(command, cwd=None):
                if command[0] != 'fixture-php':
                    return original_run(command, cwd=cwd)
                php_calls.append((command, cwd))
                if php_hook:
                    result = php_hook(command, cwd, root)
                    if result is not None:
                        return result
                return subprocess.CompletedProcess(command, 0, b'', b'')
            with patch.object(INSTALLER, 'BASE_REVISION', base), \
                 patch.object(INSTALLER, 'run', runner), \
                 contextlib.redirect_stdout(io.StringIO()):
                yield root, revision, before, after, php_calls

    def receipts(self, root):
        return list((root.parent / 'phone-delivery-workflow-backups').glob('*/receipt.json'))

    def assert_source(self, root, bodies):
        for relative, expected in bodies.items():
            self.assertEqual(INSTALLER.content(root / relative), expected, relative)

    def test_preserves_local_changes_and_unrelated_invoice_policy_with_idempotence(self):
        with self.fixture() as (root, revision, before, after, php_calls):
            relative = 'public/dashboard/js/phone-orders.js'
            customize = lambda body: body.replace(b'stable fixture line 02', b'Independent local extension')
            before[relative] = customize(before[relative])
            after[relative] = customize(after[relative])
            (root / relative).write_bytes(before[relative])
            backup = INSTALLER.install(root, revision, 'fixture-php')
            self.assert_source(root, after)
            self.assertEqual((backup / relative).read_bytes(), before[relative])
            self.assertEqual((root / 'app/Services/Dashboard/PosReceiptPrinting.php').read_bytes(),
                             b'<?php // Independent future invoice policy.\n')
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(receipt['status'], 'installed')
            self.assertEqual(len(receipt['files']), 8)
            entry = next(item for item in receipt['files'] if item['path'] == HELPER)
            self.assertFalse(entry['existed'])
            self.assertIsNone(entry['before'])
            self.assertEqual(entry['after'], hashlib.sha256(after[HELPER]).hexdigest())
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php'))
            self.assert_source(root, after)
            self.assertEqual(len(self.receipts(root)), 1)
            self.assertEqual(len([command for command, _ in php_calls if 'view:clear' in command]), 1)

    def test_late_conflict_prevents_every_write_including_new_helper(self):
        with self.fixture() as (root, revision, before, _, _):
            relative = next(reversed(INSTALLER.SOURCE_MANIFEST))
            before[relative] = before[relative].replace(b'previous delivery behavior', b'Custom conflicting behavior')
            (root / relative).write_bytes(before[relative])
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assert_source(root, before)
            self.assertEqual(self.receipts(root), [])

    def test_custom_existing_helper_is_never_overwritten(self):
        with self.fixture() as (root, revision, before, _, _):
            before[HELPER] = b'<?php // Existing custom branch helper.\n'
            (root / HELPER).write_bytes(before[HELPER])
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assert_source(root, before)
            self.assertEqual(self.receipts(root), [])

    def test_check_only_creates_no_helper_or_receipt_and_does_not_clear_views(self):
        with self.fixture() as (root, revision, before, _, php_calls):
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php', check_only=True))
            self.assert_source(root, before)
            self.assertEqual(self.receipts(root), [])
            self.assertFalse(any('view:clear' in command for command, _ in php_calls))

    def test_existing_exact_helper_completes_partial_group_without_replacing_it(self):
        with self.fixture() as (root, revision, _, after, _):
            (root / HELPER).write_bytes(after[HELPER])
            backup = INSTALLER.install(root, revision, 'fixture-php')
            self.assert_source(root, after)
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(len(receipt['files']), 7)
            self.assertNotIn(HELPER, [item['path'] for item in receipt['files']])
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php'))

    def test_staged_helper_lint_failure_leaves_all_live_source_unchanged(self):
        def php_hook(command, _cwd, root):
            if '-l' in command and command[-1].endswith(HELPER) and root not in Path(command[-1]).parents:
                return subprocess.CompletedProcess(command, 1, b'', b'fixture syntax failure')
        with self.fixture(php_hook) as (root, revision, before, _, _):
            with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_INVALID'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assert_source(root, before)
            self.assertEqual(self.receipts(root), [])

    def test_cache_failure_restores_bytes_and_modes_and_removes_added_helper(self):
        def php_hook(command, _cwd, _root):
            if 'view:clear' in command:
                return subprocess.CompletedProcess(command, 1, b'', b'fixture cache failure')
        with self.fixture(php_hook) as (root, revision, before, _, _):
            relative = 'public/dashboard/js/phone-orders.js'
            (root / relative).chmod(0o640)
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assert_source(root, before)
            self.assertEqual((root / relative).stat().st_mode & 0o777, 0o640)
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rolled_back')
            self.assertTrue(receipt['view_cache_clear_failed_after_rollback'])

    def test_concurrent_helper_change_is_retained_with_coherent_source_group(self):
        def php_hook(command, _cwd, root):
            if 'view:clear' in command:
                target = root / HELPER
                target.write_bytes(target.read_bytes() + b'// Independent concurrent edit.\n')
                return subprocess.CompletedProcess(command, 1, b'', b'fixture failure')
        with self.fixture(php_hook) as (root, revision, _, after, _):
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / HELPER).read_bytes(), after[HELPER] + b'// Independent concurrent edit.\n')
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rollback_review_required')
            self.assertEqual(receipt['review'], [HELPER])
            self.assertEqual(receipt['retained_source_group'], list(INSTALLER.SOURCE_MANIFEST))

    def test_symlink_helper_is_rejected_without_touching_target_or_existing_sources(self):
        with self.fixture() as (root, revision, before, _, _):
            outside = root.parent / 'outside.php'
            outside.write_bytes(b'<?php // Protected external source.\n')
            (root / HELPER).symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual(outside.read_bytes(), b'<?php // Protected external source.\n')
            self.assert_source(root, {path: body for path, body in before.items() if path != HELPER})
            self.assertEqual(self.receipts(root), [])

    def test_preflight_race_preserves_independent_edit_without_publishing(self):
        changed = []
        relative = 'public/dashboard/js/phone-orders.js'
        def php_hook(command, _cwd, root):
            if '-l' in command and not changed:
                target = root / relative
                target.write_bytes(target.read_bytes() + b'// Independent concurrent edit.\n')
                changed.append(True)
        with self.fixture(php_hook) as (root, revision, before, _, _):
            with self.assertRaisesRegex(RuntimeError, 'LIVE_FILE_CHANGED_DURING_PREFLIGHT'):
                INSTALLER.install(root, revision, 'fixture-php')
            before[relative] += b'// Independent concurrent edit.\n'
            self.assert_source(root, before)
            self.assertEqual(self.receipts(root), [])

    @unittest.skipUnless(os.environ.get('PHONE_INSTALLER_TEST_PHP'),
                         'Set PHONE_INSTALLER_TEST_PHP for actual-source integration and real PHP lint')
    def test_actual_pinned_sources_preflight_conflict_install_and_replay(self):
        revision = subprocess.check_output(['git', '-C', str(PROJECT), 'rev-parse', 'HEAD']).decode().strip()
        php = os.environ['PHONE_INSTALLER_TEST_PHP']
        original_run = INSTALLER.run
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
            objects = subprocess.check_output(['git', '-C', str(PROJECT), 'rev-parse', '--git-path', 'objects']).decode().strip()
            objects = Path(objects) if Path(objects).is_absolute() else PROJECT / objects
            (root / '.git/objects/info/alternates').write_text(str(objects.resolve()) + '\n')
            shallow = subprocess.check_output(['git', '-C', str(PROJECT), 'rev-parse', '--git-path', 'shallow']).decode().strip()
            shallow = Path(shallow) if Path(shallow).is_absolute() else PROJECT / shallow
            if shallow.exists():
                (root / '.git/shallow').write_bytes(shallow.read_bytes())
            subprocess.run(['git', '-C', str(root), 'checkout', '--quiet', INSTALLER.BASE_REVISION], check=True)
            local = b'// Independent deployment customization retained.\n'
            relative = 'public/dashboard/js/phone-orders.js'
            (root / relative).write_bytes(local + (root / relative).read_bytes())
            invoice = root / 'app/Services/Dashboard/PosReceiptPrinting.php'
            invoice.write_bytes(invoice.read_bytes() + b'\n// Unrelated future invoice change.\n')
            unchanged_invoice = invoice.read_bytes()
            prior = {path: INSTALLER.content(root / path) for path in INSTALLER.SOURCE_MANIFEST}
            def runner(command, cwd=None):
                # Actual PHP syntax checks run; the isolated fixture never boots Laravel.
                if command[:3] == [php, 'artisan', 'view:clear']:
                    return subprocess.CompletedProcess(command, 0, b'', b'')
                return original_run(command, cwd=cwd)
            with patch.object(INSTALLER, 'run', runner), contextlib.redirect_stdout(io.StringIO()):
                conflict = root / 'resources/lang/en/phone_orders.php'
                conflict.write_bytes(conflict.read_bytes().replace(b'return [', b'return [ // Conflicting translation header'))
                conflicting = {path: INSTALLER.content(root / path) for path in INSTALLER.SOURCE_MANIFEST}
                with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                    INSTALLER.install(root, revision, php)
                self.assert_source(root, conflicting)
                self.assertEqual(self.receipts(root), [])
                conflict.write_bytes(prior['resources/lang/en/phone_orders.php'])
                INSTALLER.install(root, revision, php, check_only=True)
                self.assert_source(root, prior)
                self.assertEqual(self.receipts(root), [])
                backup = INSTALLER.install(root, revision, php)
                self.assertEqual(len(json.loads((backup / 'receipt.json').read_text())['files']), 8)
                for path in INSTALLER.SOURCE_MANIFEST:
                    expected = subprocess.check_output(['git', '-C', str(root), 'show', revision + ':' + path])
                    self.assertEqual((root / path).read_bytes(), local + expected if path == relative else expected)
                self.assertIsNone(INSTALLER.install(root, revision, php))
                self.assertEqual(len(self.receipts(root)), 1)
                self.assertEqual(invoice.read_bytes(), unchanged_invoice)


if __name__ == '__main__':
    unittest.main()
