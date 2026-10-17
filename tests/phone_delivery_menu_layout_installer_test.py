#!/usr/bin/env python3
"""Verify layout/consent publication, local-change protection and rollback."""
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
    'phone_delivery_menu_layout_installer', PROJECT / 'deployment/apply_phone_delivery_menu_layout.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)
CSS = 'public/dashboard/css/phone-orders.css'
JS = 'public/dashboard/js/phone-orders.js'
JS_BEFORE = b''.join(b'// stable workflow line %02d\n' % number for number in range(30)).replace(
    b'stable workflow line 15', b'duplicate follow-up step')
JS_AFTER = JS_BEFORE.replace(b'duplicate follow-up step', b'single follow-up step')


class MenuLayoutInstallerTest(unittest.TestCase):
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
            target = root / CSS
            target.parent.mkdir(parents=True)
            before = b''.join(b'/* stable fixture rule %02d */\n' % number for number in range(30))
            target.write_bytes(before)
            (root / JS).parent.mkdir(parents=True, exist_ok=True)
            (root / JS).write_bytes(JS_BEFORE)
            (root / 'artisan').write_text('<?php // fixture\n')
            unrelated = root / 'phone-orders.js'
            unrelated.write_bytes(b'// Existing phone workflow stays byte-identical.\n')
            git('add', '.')
            git('commit', '--quiet', '-m', 'Confirmed deployed baseline')
            base = git('rev-parse', 'HEAD')
            after = before + b'/* New menu layout override */\n.ph-workspace{grid-template-columns:1fr 2fr}\n'
            target.write_bytes(after)
            (root / JS).write_bytes(JS_AFTER)
            git('add', '.')
            git('commit', '--quiet', '-m', 'CSS menu layout release')
            revision = git('rev-parse', 'HEAD')
            git('checkout', '--quiet', base)
            original_run = INSTALLER.run
            php_calls = []
            def runner(command, cwd=None):
                if command[0] != 'fixture-php':
                    return original_run(command, cwd=cwd)
                php_calls.append(command)
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
        return list((root.parent / 'phone-delivery-menu-layout-backups').glob('*/receipt.json'))

    def test_preserves_unrelated_css_and_other_files_with_idempotent_append(self):
        with self.fixture() as (root, revision, before, after, php_calls):
            customize = lambda body: body.replace(b'stable fixture rule 02', b'Independent existing customization')
            (root / CSS).write_bytes(customize(before))
            backup = INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / CSS).read_bytes(), customize(after))
            self.assertEqual((root / JS).read_bytes(), JS_AFTER)
            self.assertEqual((backup / CSS).read_bytes(), customize(before))
            self.assertEqual((root / 'phone-orders.js').read_bytes(), b'// Existing phone workflow stays byte-identical.\n')
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(receipt['status'], 'installed')
            self.assertEqual(len(receipt['files']), 2)
            self.assertEqual(receipt['files'][0]['after'], hashlib.sha256(customize(after)).hexdigest())
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php'))
            self.assertEqual((root / CSS).read_bytes().count(b'New menu layout override'), 1)
            self.assertEqual(len(self.receipts(root)), 1)
            self.assertEqual(len([command for command in php_calls if 'view:clear' in command]), 1)

    def test_overlapping_css_conflict_stops_before_live_changes(self):
        with self.fixture() as (root, revision, before, _, _):
            live = before.replace(b'stable fixture rule 29', b'Conflicting release context')
            (root / CSS).write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / CSS).read_bytes(), live)
            self.assertEqual((root / JS).read_bytes(), JS_BEFORE)
            self.assertEqual(self.receipts(root), [])

    def test_later_javascript_conflict_prevents_css_publication(self):
        with self.fixture() as (root, revision, before, _, _):
            live = JS_BEFORE.replace(b'duplicate follow-up step', b'Custom conflicting workflow')
            (root / JS).write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / CSS).read_bytes(), before)
            self.assertEqual((root / JS).read_bytes(), live)
            self.assertEqual(self.receipts(root), [])

    def test_installed_css_completes_javascript_preserving_unrelated_customization(self):
        with self.fixture() as (root, revision, _, after, _):
            (root / CSS).write_bytes(after)
            customize = lambda body: body.replace(b'stable workflow line 02', b'Independent local JavaScript extension')
            (root / JS).write_bytes(customize(JS_BEFORE))
            backup = INSTALLER.install(root, revision, 'fixture-php')
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual([item['path'] for item in receipt['files']], [JS])
            self.assertEqual((root / CSS).read_bytes(), after)
            self.assertEqual((root / JS).read_bytes(), customize(JS_AFTER))
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php'))
            self.assertEqual(len(self.receipts(root)), 1)

    def test_check_only_keeps_css_exact_and_does_not_clear_views(self):
        with self.fixture() as (root, revision, before, _, php_calls):
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php', check_only=True))
            self.assertEqual((root / CSS).read_bytes(), before)
            self.assertEqual((root / JS).read_bytes(), JS_BEFORE)
            self.assertEqual(self.receipts(root), [])
            self.assertEqual(php_calls, [])

    def test_cache_failure_restores_exact_css_and_original_mode(self):
        def php_hook(command, _cwd, _root):
            if 'view:clear' in command:
                return subprocess.CompletedProcess(command, 1, b'', b'fixture cache failure')
        with self.fixture(php_hook) as (root, revision, before, _, _):
            (root / CSS).chmod(0o640)
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / CSS).read_bytes(), before)
            self.assertEqual((root / JS).read_bytes(), JS_BEFORE)
            self.assertEqual((root / CSS).stat().st_mode & 0o777, 0o640)
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rolled_back')
            self.assertTrue(receipt['view_cache_clear_failed_after_rollback'])

    def test_concurrent_live_css_edit_is_preserved_and_flagged_for_review(self):
        def php_hook(command, _cwd, root):
            if 'view:clear' in command:
                target = root / CSS
                target.write_bytes(target.read_bytes() + b'/* Concurrent owner edit */\n')
                return subprocess.CompletedProcess(command, 1, b'', b'fixture failure')
        with self.fixture(php_hook) as (root, revision, _, after, _):
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / CSS).read_bytes(), after + b'/* Concurrent owner edit */\n')
            self.assertEqual((root / JS).read_bytes(), JS_AFTER)
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rollback_review_required')
            self.assertEqual(receipt['review'], [CSS])

    def test_symlink_css_is_rejected_without_touching_external_target(self):
        with self.fixture() as (root, revision, before, _, _):
            outside = root.parent / 'outside.css'
            outside.write_bytes(before)
            (root / CSS).unlink()
            (root / CSS).symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual(outside.read_bytes(), before)
            self.assertEqual((root / JS).read_bytes(), JS_BEFORE)
            self.assertEqual(self.receipts(root), [])

    @unittest.skipUnless(os.environ.get('PHONE_INSTALLER_TEST_PHP'),
                         'Set PHONE_INSTALLER_TEST_PHP after committing the actual CSS release')
    def test_actual_pinned_sources_preflight_install_and_replay(self):
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
            local = b'/* Independent deployment CSS customization retained. */\n'
            target = root / CSS
            target.write_bytes(local + target.read_bytes())
            local_js = b'// Independent deployment JavaScript customization retained.\n'
            (root / JS).write_bytes(local_js + (root / JS).read_bytes())
            prior = {path: (root / path).read_bytes() for path in INSTALLER.SOURCE_MANIFEST}
            def runner(command, cwd=None):
                if command[:3] == [php, 'artisan', 'view:clear']:
                    return subprocess.CompletedProcess(command, 0, b'', b'')
                return original_run(command, cwd=cwd)
            with patch.object(INSTALLER, 'run', runner), contextlib.redirect_stdout(io.StringIO()):
                INSTALLER.install(root, revision, php, check_only=True)
                for path, body in prior.items():
                    self.assertEqual((root / path).read_bytes(), body)
                self.assertEqual(self.receipts(root), [])
                backup = INSTALLER.install(root, revision, php)
                self.assertEqual(len(json.loads((backup / 'receipt.json').read_text())['files']), 2)
                for path in INSTALLER.SOURCE_MANIFEST:
                    expected = subprocess.check_output(['git', '-C', str(root), 'show', revision + ':' + path])
                    self.assertEqual((root / path).read_bytes(), (local if path == CSS else local_js) + expected)
                self.assertIsNone(INSTALLER.install(root, revision, php))
                self.assertEqual(len(self.receipts(root)), 1)


if __name__ == '__main__':
    unittest.main()
