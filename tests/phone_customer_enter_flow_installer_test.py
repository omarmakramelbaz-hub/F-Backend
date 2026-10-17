#!/usr/bin/env python3
"""Verify customer Enter-flow publication, local-change protection and rollback."""
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
    'phone_customer_enter_flow_installer', PROJECT / 'deployment/apply_phone_customer_enter_flow.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)
JS = 'public/dashboard/js/phone-orders.js'
CSS = 'public/dashboard/css/phone-orders.css'
CSS_BEFORE = b''.join(b'/* stable header style %02d */\n' % number for number in range(30))
CSS_AFTER = CSS_BEFORE + b'/* Clear branch header */\n.ph-header .ph-branch{color:#172338;font-weight:700}\n'


class CustomerEnterFlowInstallerTest(unittest.TestCase):
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
            target = root / JS
            target.parent.mkdir(parents=True)
            before = b''.join(b'// stable workflow line %02d\n' % number for number in range(30))
            target.write_bytes(before)
            (root / CSS).parent.mkdir(parents=True, exist_ok=True)
            (root / CSS).write_bytes(CSS_BEFORE)
            (root / 'artisan').write_text('<?php // fixture\n')
            unrelated = root / 'phone-orders.css'
            unrelated.write_bytes(b'/* Existing menu layout remains unchanged. */\n')
            git('add', '.')
            git('commit', '--quiet', '-m', 'Confirmed deployed baseline')
            base = git('rev-parse', 'HEAD')
            after = before.replace(b'stable workflow line 15', b'customer name Enter focuses confirmation')
            after += b'// customer location Enter validates and advances once\n'
            target.write_bytes(after)
            (root / CSS).write_bytes(CSS_AFTER)
            git('add', '.')
            git('commit', '--quiet', '-m', 'Customer Enter-flow release')
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
        return list((root.parent / 'phone-customer-enter-flow-backups').glob('*/receipt.json'))

    def test_preserves_local_js_and_unrelated_layout_with_idempotence(self):
        with self.fixture() as (root, revision, before, after, php_calls):
            customize = lambda body: body.replace(b'stable workflow line 02', b'Independent existing customization')
            (root / JS).write_bytes(customize(before))
            backup = INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / JS).read_bytes(), customize(after))
            self.assertEqual((root / CSS).read_bytes(), CSS_AFTER)
            self.assertEqual((backup / JS).read_bytes(), customize(before))
            self.assertEqual((root / 'phone-orders.css').read_bytes(), b'/* Existing menu layout remains unchanged. */\n')
            receipt = json.loads((backup / 'receipt.json').read_text())
            self.assertEqual(receipt['status'], 'installed')
            self.assertEqual(len(receipt['files']), 2)
            self.assertEqual(receipt['files'][0]['after'], hashlib.sha256(customize(after)).hexdigest())
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php'))
            self.assertEqual((root / JS).read_bytes(), customize(after))
            self.assertEqual(len(self.receipts(root)), 1)
            self.assertEqual(len([command for command in php_calls if 'view:clear' in command]), 1)

    def test_conflicting_workflow_stops_before_live_changes(self):
        with self.fixture() as (root, revision, before, _, _):
            live = before.replace(b'stable workflow line 15', b'Conflicting independent workflow')
            (root / JS).write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / JS).read_bytes(), live)
            self.assertEqual((root / CSS).read_bytes(), CSS_BEFORE)
            self.assertEqual(self.receipts(root), [])

    def test_later_css_conflict_prevents_javascript_publication(self):
        with self.fixture() as (root, revision, before, _, _):
            live = CSS_BEFORE.replace(b'stable header style 29', b'Conflicting independent header')
            (root / CSS).write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / JS).read_bytes(), before)
            self.assertEqual((root / CSS).read_bytes(), live)
            self.assertEqual(self.receipts(root), [])

    def test_check_only_keeps_js_exact_and_does_not_clear_views(self):
        with self.fixture() as (root, revision, before, _, php_calls):
            self.assertIsNone(INSTALLER.install(root, revision, 'fixture-php', check_only=True))
            self.assertEqual((root / JS).read_bytes(), before)
            self.assertEqual((root / CSS).read_bytes(), CSS_BEFORE)
            self.assertEqual(self.receipts(root), [])
            self.assertEqual(php_calls, [])

    def test_cache_failure_restores_exact_js_and_original_mode(self):
        def php_hook(command, _cwd, _root):
            if 'view:clear' in command:
                return subprocess.CompletedProcess(command, 1, b'', b'fixture cache failure')
        with self.fixture(php_hook) as (root, revision, before, _, _):
            (root / JS).chmod(0o640)
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / JS).read_bytes(), before)
            self.assertEqual((root / CSS).read_bytes(), CSS_BEFORE)
            self.assertEqual((root / JS).stat().st_mode & 0o777, 0o640)
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rolled_back')
            self.assertTrue(receipt['view_cache_clear_failed_after_rollback'])

    def test_concurrent_live_change_is_preserved_and_flagged_for_review(self):
        def php_hook(command, _cwd, root):
            if 'view:clear' in command:
                target = root / JS
                target.write_bytes(target.read_bytes() + b'// Concurrent owner edit\n')
                return subprocess.CompletedProcess(command, 1, b'', b'fixture failure')
        with self.fixture(php_hook) as (root, revision, _, after, _):
            with self.assertRaisesRegex(RuntimeError, 'VIEW_CACHE_CLEAR_FAILED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual((root / JS).read_bytes(), after + b'// Concurrent owner edit\n')
            self.assertEqual((root / CSS).read_bytes(), CSS_AFTER)
            receipt = json.loads(self.receipts(root)[0].read_text())
            self.assertEqual(receipt['status'], 'rollback_review_required')
            self.assertEqual(receipt['review'], [JS])

    def test_symlink_js_is_rejected_without_touching_external_target(self):
        with self.fixture() as (root, revision, before, _, _):
            outside = root.parent / 'outside.js'
            outside.write_bytes(before)
            (root / JS).unlink()
            (root / JS).symlink_to(outside)
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
                INSTALLER.install(root, revision, 'fixture-php')
            self.assertEqual(outside.read_bytes(), before)
            self.assertEqual((root / CSS).read_bytes(), CSS_BEFORE)
            self.assertEqual(self.receipts(root), [])

    @unittest.skipUnless(os.environ.get('PHONE_INSTALLER_TEST_PHP'),
                         'Set PHONE_INSTALLER_TEST_PHP after committing the actual JavaScript release')
    def test_actual_pinned_source_preflight_conflict_install_and_replay(self):
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
            local = b'// Independent deployment JavaScript customization retained.\n'
            target = root / JS
            target.write_bytes(local + target.read_bytes())
            prior = target.read_bytes()
            css = root / 'public/dashboard/css/phone-orders.css'
            local_css = b'/* Independent deployment CSS customization retained. */\n'
            css.write_bytes(local_css + css.read_bytes())
            prior_css = css.read_bytes()
            def runner(command, cwd=None):
                if command[:3] == [php, 'artisan', 'view:clear']:
                    return subprocess.CompletedProcess(command, 0, b'', b'')
                return original_run(command, cwd=cwd)
            with patch.object(INSTALLER, 'run', runner), contextlib.redirect_stdout(io.StringIO()):
                # Change a removed line from an actual release hunk to exercise
                # real overlapping edits instead of a fabricated source fixture.
                delta = INSTALLER.release_patch(root, revision, JS, 'M')
                removed = next(line[1:] for line in delta.splitlines(keepends=True)
                               if line.startswith(b'-') and not line.startswith(b'---'))
                live = prior.replace(removed, b'// Conflicting custom customer flow\n', 1)
                self.assertNotEqual(live, prior)
                target.write_bytes(live)
                with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                    INSTALLER.install(root, revision, php)
                self.assertEqual(target.read_bytes(), live)
                self.assertEqual(css.read_bytes(), prior_css)
                self.assertEqual(self.receipts(root), [])
                target.write_bytes(prior)
                INSTALLER.install(root, revision, php, check_only=True)
                self.assertEqual(target.read_bytes(), prior)
                self.assertEqual(css.read_bytes(), prior_css)
                self.assertEqual(self.receipts(root), [])
                backup = INSTALLER.install(root, revision, php)
                self.assertEqual(len(json.loads((backup / 'receipt.json').read_text())['files']), 2)
                expected = subprocess.check_output(['git', '-C', str(root), 'show', revision + ':' + JS])
                self.assertEqual(target.read_bytes(), local + expected)
                expected_css = subprocess.check_output(['git', '-C', str(root), 'show', revision + ':' + CSS])
                self.assertEqual(css.read_bytes(), local_css + expected_css)
                self.assertIsNone(INSTALLER.install(root, revision, php))
                self.assertEqual(len(self.receipts(root)), 1)


if __name__ == '__main__':
    unittest.main()
