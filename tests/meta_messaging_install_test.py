#!/usr/bin/env python3
"""Isolated deployment fixtures; never touch the live server or invoke PHP."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('meta_installer', pathlib.Path(__file__).resolve().parents[1] / 'deployment/install_meta_messaging.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
RELEASE = 'a' * 40


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-install-test-')
        self.root = pathlib.Path(self.temporary.name) / 'project'
        self.root.mkdir()
        self.original = {
            'vendor/autoload.php': b'<?php // runtime\n',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': b'<?php // original shared workflow\n',
            'routes/api.php': b'<?php // installed custom API routes\n',
            'routes/web.php': b'<?php // installed custom dashboard routes\n',
            'resources/views/admin/whatsapp/sidebar.blade.php': b'installed WhatsApp link\n',
            'resources/views/admin/layouts/menu.blade.php': b"@include('admin.whatsapp.sidebar')\n",
            '.env': b'PRIVATE_SECRET=never-read-or-change\n',
            'bootstrap/cache/config.php': b'private cached configuration\n',
            'bootstrap/cache/routes-v7.php': b'original cached routes\n',
        }
        for rel, content in self.original.items():
            self.write(rel, content, 0o640)
        self.payloads = {
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': '<?php // extended shared workflow\n',
            'routes/meta_messaging.php': '<?php // new callback\n',
            'database/migrations/2026_10_11_160000_create_meta_webhook_events_table.php': '<?php // additive migration\n',
        }
        shared = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
        content = self.original[shared]
        baseline = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
        self.manifest = {'baseline': {shared: baseline}, 'new_files': [rel for rel in self.payloads if rel != shared]}
        self.calls = []
        self.fail = None
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 20})
        self.output = ''

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, rel, content, mode=0o644):
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def git(self, root, *args):
        rel = args[-1].split(':', 1)[1]
        if rel == 'deployment/meta_messaging_route_check.php':
            return '<?php // pinned route verifier\n'
        return json.dumps(self.manifest) if rel == 'deployment/meta_messaging_manifest.json' else self.payloads[rel]

    def runner(self, args, root, capture=False):
        self.calls.append(args)
        if 'route:list' in args:
            raise subprocess.CalledProcessError(1, args, output='Unrelated legacy controller does not exist')
        if self.fail and self.fail(args):
            raise subprocess.CalledProcessError(1, args)
        if 'config:clear' in args:
            (self.root / 'bootstrap/cache/config.php').unlink(missing_ok=True)
        if 'config:cache' in args:
            self.write('bootstrap/cache/config.php', b'new cached configuration\n')
        if 'route:clear' in args:
            (self.root / 'bootstrap/cache/routes-v7.php').unlink(missing_ok=True)
        if 'route:cache' in args:
            self.write('bootstrap/cache/routes-v7.php', b'new cached routes\n')
        return subprocess.CompletedProcess(args, 0, self.probe_output if args[-1].endswith('/verify-meta-routes.php') else '', '')

    def install(self):
        argv = ['install', '--root', str(self.root), '--release', RELEASE, '--php', 'php']
        output = io.StringIO()
        with mock.patch.object(sys, 'argv', argv), mock.patch.object(installer.os, 'geteuid', return_value=1001), \
                mock.patch.object(installer, 'git', side_effect=self.git), mock.patch.object(installer, 'run', side_effect=self.runner), \
                contextlib.redirect_stdout(output):
            try:
                installer.main()
            finally:
                self.output = output.getvalue()

    def test_additive_install_preserves_routes_credentials_and_permissions(self):
        self.install()
        for rel in ['routes/api.php', 'routes/web.php', 'resources/views/admin/whatsapp/sidebar.blade.php']:
            self.assertTrue((self.root / rel).read_bytes().startswith(self.original[rel]))
            self.assertEqual((self.root / rel).stat().st_mode & 0o777, 0o640)
        for rel in ['.env', 'resources/views/admin/layouts/menu.blade.php']:
            self.assertEqual((self.root / rel).read_bytes(), self.original[rel])
        migrations = [call for call in self.calls if 'migrate' in call]
        self.assertEqual(len(migrations), 1)
        self.assertIn('--path=database/migrations/2026_10_11_160000_create_meta_webhook_events_table.php', migrations[0])
        self.assertFalse(any('migrate:rollback' in call for call in self.calls))
        self.assertFalse(any('route:list' in call for call in self.calls))
        self.assertIn('META_ROUTES_VERIFIED=20', self.output)
        backup = next((self.root.parent / 'meta-release-backups').iterdir())
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup / 'routes/api.php').stat().st_mode & 0o777, 0o600)
        self.assertEqual((backup / 'verify-meta-routes.php').stat().st_mode & 0o777, 0o600)

    def test_repeat_keeps_bytes_and_inodes(self):
        self.install()
        rels = list(self.payloads) + ['routes/api.php', 'routes/web.php']
        before = {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino) for rel in rels}
        self.install()
        self.assertEqual(before, {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino) for rel in rels})

    def test_failed_route_cache_restores_code_and_private_caches(self):
        self.fail = lambda args: 'route:cache' in args
        with self.assertRaises(subprocess.CalledProcessError):
            self.install()
        for rel, content in self.original.items():
            self.assertEqual((self.root / rel).read_bytes(), content)
            self.assertEqual((self.root / rel).stat().st_mode & 0o777, 0o640)
        for rel in self.manifest['new_files']:
            self.assertFalse((self.root / rel).exists())

    def test_changed_shared_file_is_rejected_before_writes(self):
        shared = next(iter(self.manifest['baseline']))
        self.write(shared, b'external edits')
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_INSTALLED_FILE'):
            self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_existing_meta_file_and_missing_sidebar_are_rejected(self):
        self.write(self.manifest['new_files'][0], b'external Meta implementation')
        with self.assertRaisesRegex(RuntimeError, 'EXISTING_META_FILE_DIFFERS'):
            self.install()
        (self.root / self.manifest['new_files'][0]).unlink()
        self.write('resources/views/admin/layouts/menu.blade.php', b'custom menu without WhatsApp')
        with self.assertRaisesRegex(RuntimeError, 'INSTALLED_WHATSAPP_SIDEBAR_REQUIRED'):
            self.install()
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_symlink_backup_is_rejected_before_writes(self):
        (self.root.parent / 'meta-release-backups').symlink_to(self.root)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'):
            self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])

    def test_real_route_failure_restores_code_and_keeps_diagnostics_private(self):
        details = json.dumps({'ok': False, 'phase': 'routes', 'reason': 'ADMIN_MIDDLEWARE', 'route': 'meta-inbox.conversations',
                              'error_type': 'MetaMessagingRouteCheckFailure', 'access_token': 'fixture-sensitive-token'})
        old_runner = self.runner
        def failed_probe(args, root, capture=False):
            if args[-1].endswith('/verify-meta-routes.php'):
                raise subprocess.CalledProcessError(1, args, output=details, stderr='fixture-sensitive-stderr')
            return old_runner(args, root, capture)
        with mock.patch.object(self, 'runner', side_effect=failed_probe):
            with self.assertRaises(subprocess.CalledProcessError):
                self.install()
        self.assertIn('ADMIN_MIDDLEWARE', self.output)
        self.assertIn('meta-inbox.conversations', self.output)
        self.assertNotIn('fixture-sensitive', self.output)
        for rel, content in self.original.items():
            self.assertEqual((self.root / rel).read_bytes(), content)
        backup = next((self.root.parent / 'meta-release-backups').iterdir())
        diagnostic = backup / 'route-check-failure.log'
        self.assertEqual(diagnostic.stat().st_mode & 0o777, 0o600)
        self.assertIn('fixture-sensitive-stderr', diagnostic.read_text())
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_incomplete_route_verification_does_not_install(self):
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 2})
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
            self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_retry_after_verification_rollback_succeeds_without_restoring_database(self):
        self.probe_output = json.dumps({'ok': False})
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
            self.install()
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 20})
        self.install()
        self.assertIn('META_MESSAGING_INSTALLED', self.output)
        self.assertFalse(any('migrate:rollback' in call or 'migrate:fresh' in call for call in self.calls))

    def test_diagnostic_write_failure_does_not_prevent_rollback(self):
        self.probe_output = json.dumps({'ok': False})
        original_open = installer.os.open
        def failing_log_open(path, *args, **kwargs):
            if str(path).endswith('/route-check-failure.log'):
                raise OSError('fixture log storage unavailable')
            return original_open(path, *args, **kwargs)
        with mock.patch.object(installer.os, 'open', side_effect=failing_log_open):
            with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
                self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())
        self.assertIn('PRIVATE_DIAGNOSTIC_UNAVAILABLE', self.output)


if __name__ == '__main__':
    unittest.main()
