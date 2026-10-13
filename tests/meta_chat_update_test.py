#!/usr/bin/env python3
"""Isolated Meta chat upgrade fixtures; never run PHP, migrations or networking."""
import contextlib
import importlib.util
import io
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('meta_chat_updater', pathlib.Path(__file__).resolve().parents[1] / 'deployment/update_meta_chat.py')
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)
RELEASE = 'a' * 40
PROCESS = 'app/Console/Commands/ProcessMetaMessages.php'
SERVICE = 'app/Services/Dashboard/MetaMediaService.php'
MIGRATION = 'database/migrations/2026_10_13_190000_create_meta_message_media_tables.php'


class ChatUpdaterTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-chat-update-test-')
        self.root = pathlib.Path(self.temporary.name) / 'project'
        self.root.mkdir()
        self.original = {
            'vendor/autoload.php': b'<?php // installed runtime\n',
            'bootstrap/app.php': b'<?php // installed bootstrap\n',
            'artisan': b'<?php // installed artisan\n',
            '.env': b'PRIVATE_SECRET=never-read-or-change\n',
            'bootstrap/cache/config.php': b'cached private configuration\n',
            updater.ROUTE_CACHE: b'cached installed routes\n',
            'routes/api.php': b'<?php // customized API routes\n',
            'routes/web.php': b'<?php // customized dashboard routes\n',
            'resources/views/admin/layouts/menu.blade.php': b'customized native sidebar\n',
            'resources/views/admin/meta/sidebar.blade.php': b'installed Meta sidebar\n',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': b'<?php // newer WhatsApp shared workflow\n',
            'public/js/dashboard-whatsapp-inbox.js': b'// newer WhatsApp chat\n',
            'database/migrations/2026_10_11_160001_create_meta_inbox_tables.php': b'<?php // original Meta schema\n',
            PROCESS: b'<?php // installed Meta processor\n',
            'app/Http/Controllers/Dashboard/MetaInboxController.php': b'<?php // installed Meta controller\n',
            'routes/meta_inbox.php': b'<?php // installed Meta routes\n',
            'resources/views/admin/meta/index.blade.php': b'installed Meta chat view\n',
        }
        self.original.update({rel: ('<?php // original foundation ' + rel + '\n').encode() for rel in updater.FOUNDATION_BLOBS})
        self.foundations = mock.patch.object(updater, 'FOUNDATION_BLOBS',
            {rel: updater.blob(self.original[rel]) for rel in updater.FOUNDATION_BLOBS})
        self.foundations.start()
        for rel, content in self.original.items(): self.write(rel, content, 0o640)
        changed = (PROCESS, 'app/Http/Controllers/Dashboard/MetaInboxController.php',
                   'routes/meta_inbox.php', 'resources/views/admin/meta/index.blade.php')
        new = (SERVICE, 'app/Console/Commands/RefreshMetaProfiles.php',
               'public/js/dashboard-meta-inbox.js', MIGRATION)
        self.payloads = {rel: ('<?php // pinned rich chat ' + rel + '\n').encode() for rel in changed + new}
        self.manifest = {
            'live_upgrade': {rel: [updater.blob(self.original[rel])] if rel in changed else [] for rel in changed + new},
            'upgrade_migrations': [MIGRATION], 'verified_routes': 25,
        }
        self.calls = []
        self.fail_lint = False
        self.fail_runtime = False
        self.fail_migration = False
        self.fail_route_cache = False
        self.preflight_action = None
        self.verify_action = None
        self.verify_result = {'ok': True, 'verified_routes': 25}
        self.tables_applied = []
        self.output = ''
        self.lint_snapshots = []

    def tearDown(self):
        self.foundations.stop()
        self.temporary.cleanup()

    def write(self, rel, content, mode=0o644):
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def snapshot(self):
        return {str(path.relative_to(self.root)): (path.read_bytes(), path.stat().st_mode & 0o777)
                for path in self.root.rglob('*') if path.is_file()}

    def git_file(self, root, release, rel):
        self.assertEqual(root, self.root)
        self.assertEqual(release, RELEASE)
        if rel == 'deployment/meta_messaging_manifest.json': return json.dumps(self.manifest).encode()
        if rel == 'deployment/meta_messaging_route_check.php': return b'<?php // pinned exact Meta route verifier\n'
        self.assertIn(rel, self.manifest['live_upgrade'])
        return self.payloads[rel]

    def runner(self, args, root):
        self.calls.append(args)
        self.assertEqual(args[0], 'php')
        if args[1] == '-l':
            staged = pathlib.Path(args[2])
            self.assertTrue(staged.is_relative_to(self.root.parent / 'meta-release-backups'))
            self.assertEqual(staged.stat().st_mode & 0o777, 0o600)
            if '/staged/' in str(staged):
                rel = str(staged).split('/staged/', 1)[1]
                self.assertEqual(staged.read_bytes(), self.payloads[rel])
            self.lint_snapshots.append(self.snapshot())
            return subprocess.CompletedProcess(args, int(self.fail_lint), b'', b'private PHP diagnostic')
        if args[1].endswith('/verify-meta-runtime.php'):
            if self.preflight_action: self.preflight_action()
            return subprocess.CompletedProcess(args, int(self.fail_runtime), json.dumps({'ok': not self.fail_runtime}).encode(), b'private runtime diagnostic')
        if args[1] == 'artisan':
            command = args[2]
            if command == 'migrate':
                self.assertEqual(args[3:], ['--force', '--path=' + MIGRATION])
                if not self.fail_migration: self.tables_applied.append(MIGRATION)
                return subprocess.CompletedProcess(args, int(self.fail_migration), b'', b'private migration diagnostic')
            if command == 'route:clear': (self.root / updater.ROUTE_CACHE).unlink(missing_ok=True)
            elif command == 'route:cache':
                self.write(updater.ROUTE_CACHE, b'new routes cache\n')
                return subprocess.CompletedProcess(args, int(self.fail_route_cache), b'', b'private route diagnostic')
            else: self.fail('Unexpected artisan command: ' + command)
            return subprocess.CompletedProcess(args, 0, b'', b'')
        self.assertTrue(args[1].endswith('/verify-meta-routes.php'))
        if self.verify_action: self.verify_action()
        return subprocess.CompletedProcess(args, 0, json.dumps(self.verify_result).encode(), b'private verifier diagnostic')

    def update(self):
        output = io.StringIO()
        with mock.patch.object(sys, 'argv', ['update', '--root', str(self.root), '--release', RELEASE, '--php', 'php']), \
             mock.patch.object(updater.os, 'geteuid', return_value=1001), \
             mock.patch.object(updater, 'git_file', side_effect=self.git_file), \
             mock.patch.object(updater, 'run', side_effect=self.runner), contextlib.redirect_stdout(output):
            try: updater.main()
            finally: self.output = output.getvalue()

    def assert_original(self):
        self.assertEqual(self.snapshot(), {rel: (content, 0o640) for rel, content in self.original.items()})

    def test_narrow_upgrade_lints_before_writes_runs_only_listed_additive_migration_and_verifies25_routes(self):
        before = self.snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        self.update()
        expected = dict(before)
        expected.update({rel: (payload, 0o640 if rel in before else 0o644) for rel, payload in self.payloads.items()})
        expected[updater.ROUTE_CACHE] = (b'new routes cache\n', 0o640)
        self.assertEqual(self.snapshot(), expected)
        for rel in before:
            if rel not in self.payloads and rel != updater.ROUTE_CACHE:
                self.assertEqual((self.root / rel).stat().st_ino, inodes[rel], rel)
        self.assertTrue(self.lint_snapshots)
        self.assertTrue(all(snapshot == before for snapshot in self.lint_snapshots))
        self.assertEqual([args[2] for args in self.calls if args[1] == 'artisan'], ['migrate', 'route:clear', 'route:cache'])
        self.assertEqual(self.tables_applied, [MIGRATION])
        self.assertIn('META_ROUTES_VERIFIED=25', self.output)
        self.assertIn('META_CHAT_UPDATED=8', self.output)
        backup, = (self.root.parent / 'meta-release-backups').iterdir()
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        for rel in (PROCESS, updater.ROUTE_CACHE):
            saved = backup / 'original' / rel
            self.assertEqual(saved.read_bytes(), before[rel][0])
            self.assertEqual(saved.stat().st_mode & 0o777, 0o600)

    def test_uncached_routes_remain_uncached_and_repeated_code_is_not_rewritten(self):
        (self.root / updater.ROUTE_CACHE).unlink()
        self.update()
        self.assertFalse((self.root / updater.ROUTE_CACHE).exists())
        self.assertFalse(any('route:cache' in args for args in self.calls))
        before = self.snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertEqual({rel: (self.root / rel).stat().st_ino for rel in before}, inodes)
        self.assertIn('META_CHAT_UPDATED=0', self.output)

    def test_unknown_previous_bytes_are_rejected_before_backups_or_lint(self):
        self.write('routes/meta_inbox.php', b'<?php // unknown local route edit\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_META_UPGRADE_FILE'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_native_shared_config_and_foundation_targets_are_forbidden(self):
        for rel in ('routes/api.php', 'routes/web.php', 'resources/views/admin/layouts/menu.blade.php',
                    'resources/views/admin/meta/sidebar.blade.php', 'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
                    'public/js/dashboard-whatsapp-inbox.js', 'config/meta_messaging.php',
                    'app/Services/Dashboard/MetaInboxConsumer.php', '../outside.php'):
            with self.subTest(rel=rel):
                self.manifest['live_upgrade'][rel] = []
                with self.assertRaisesRegex(RuntimeError, 'INVALID_META_UPGRADE_TARGET'): self.update()
                del self.manifest['live_upgrade'][rel]
                self.assert_original()
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                self.assertEqual(self.calls, [])

    def test_invalid_counts_allowlists_and_unlisted_migrations_fail_before_mutation(self):
        for invalid in (True, 0, '25'):
            with self.subTest(count=invalid):
                self.manifest['verified_routes'] = invalid
                with self.assertRaisesRegex(RuntimeError, 'INVALID_META_UPGRADE_MANIFEST'): self.update()
        self.manifest['verified_routes'] = 25
        self.manifest['live_upgrade'][SERVICE] = ['not-a-blob']
        with self.assertRaisesRegex(RuntimeError, 'INVALID_META_UPGRADE_TARGET'): self.update()
        self.manifest['live_upgrade'][SERVICE] = []
        self.manifest['upgrade_migrations'] = []
        with self.assertRaisesRegex(RuntimeError, 'UNLISTED_META_UPGRADE_MIGRATION'): self.update()
        self.manifest['upgrade_migrations'] = [MIGRATION]
        self.manifest['live_upgrade'][MIGRATION] = ['a' * 40]
        with self.assertRaisesRegex(RuntimeError, 'INVALID_META_UPGRADE_MIGRATION'): self.update()
        self.assert_original()
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_missing_old_file_or_changed_immutable_foundation_is_rejected_before_mutation(self):
        (self.root / PROCESS).unlink()
        with self.assertRaisesRegex(RuntimeError, 'REQUIRED_META_UPGRADE_FILE'): self.update()
        self.write(PROCESS, self.original[PROCESS], 0o640)
        foundation = next(iter(updater.FOUNDATION_BLOBS))
        self.write(foundation, b'<?php // incompatible foundation\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'META_FOUNDATION_DIFFERS'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_lint_and_runtime_failures_do_not_change_application_files(self):
        self.fail_lint = True
        with self.assertRaisesRegex(RuntimeError, 'STAGED_PHP_LINT_FAILED'): self.update()
        self.assert_original()
        self.fail_lint = False
        self.fail_runtime = True
        with self.assertRaisesRegex(RuntimeError, 'META_UPGRADE_RUNTIME_UNAVAILABLE'): self.update()
        self.assert_original()
        self.assertEqual(self.tables_applied, [])
        self.assertFalse(any(args[1] == 'artisan' for args in self.calls))

    def test_route_verification_failure_restores_code_and_cache_but_retains_additive_tables(self):
        self.verify_result = {'ok': True, 'verified_routes': 24}
        with self.assertRaisesRegex(RuntimeError, 'META_UPGRADE_ROUTE_VERIFICATION_FAILED'): self.update()
        self.assert_original()
        self.assertEqual(self.tables_applied, [MIGRATION])
        self.assertIn('META_UPGRADE_CODE_RESTORED', self.output)
        self.assertIn('ADDITIVE_META_TABLES_RETAINED', self.output)
        self.assertNotIn('private', self.output)
        self.assertFalse(any('rollback' in arg or arg in ('migrate:fresh', 'config:clear', 'config:cache', 'view:clear') for args in self.calls for arg in args))

    def test_partial_route_cache_or_migration_failure_rolls_back_code_and_old_cache(self):
        self.fail_route_cache = True
        with self.assertRaisesRegex(RuntimeError, 'META_UPGRADE_ROUTE_CACHE_FAILED'): self.update()
        self.assert_original()
        self.fail_route_cache = False
        self.fail_migration = True
        self.calls.clear()
        with self.assertRaisesRegex(RuntimeError, 'META_UPGRADE_MIGRATION_FAILED'): self.update()
        self.assert_original()
        self.assertFalse(any('route:clear' in args for args in self.calls))

    def test_concurrent_operator_edit_before_publication_is_preserved(self):
        changed = b'<?php // concurrent operator process change\n'
        self.preflight_action = lambda: self.write(PROCESS, changed, 0o640)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        expected = {rel: (content, 0o640) for rel, content in self.original.items()}
        expected[PROCESS] = (changed, 0o640)
        self.assertEqual(self.snapshot(), expected)
        self.assertEqual(self.tables_applied, [])

    def test_rollback_preserves_later_operator_code_and_route_cache_edits(self):
        changed = b'operator changes must be retained\n'
        def changed_after_publication():
            self.write(SERVICE, changed, 0o640)
            self.write(updater.ROUTE_CACHE, changed, 0o640)
        self.verify_action = changed_after_publication
        self.verify_result = {'ok': False}
        with self.assertRaisesRegex(RuntimeError, 'META_UPGRADE_ROUTE_VERIFICATION_FAILED'): self.update()
        self.assertEqual((self.root / PROCESS).read_bytes(), self.original[PROCESS])
        self.assertEqual((self.root / SERVICE).read_bytes(), changed)
        self.assertEqual((self.root / updater.ROUTE_CACHE).read_bytes(), changed)
        self.assertFalse((self.root / MIGRATION).exists())
        self.assertEqual((self.root / 'bootstrap/cache/config.php').read_bytes(), self.original['bootstrap/cache/config.php'])
        self.assertIn('META_UPGRADE_ROLLBACK_INCOMPLETE', self.output)

    def test_successful_route_check_cannot_hide_a_concurrent_feature_edit(self):
        changed = b'operator edit during route verification\n'
        self.verify_action = lambda: self.write(SERVICE, changed, 0o640)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        self.assertEqual((self.root / SERVICE).read_bytes(), changed)
        self.assertEqual((self.root / PROCESS).read_bytes(), self.original[PROCESS])
        self.assertEqual((self.root / updater.ROUTE_CACHE).read_bytes(), self.original[updater.ROUTE_CACHE])
        self.assertIn('META_UPGRADE_ROLLBACK_INCOMPLETE', self.output)

    def test_verifier_requires_boolean_success_and_integer_route_count(self):
        for result in ({'ok': 1, 'verified_routes': 25}, {'ok': True, 'verified_routes': 25.0}):
            with self.subTest(result=result):
                self.verify_result = result
                with self.assertRaisesRegex(RuntimeError, 'META_UPGRADE_ROUTE_VERIFICATION_FAILED'): self.update()
                self.assert_original()

    def test_symlink_target_cache_and_backup_are_rejected_before_publication(self):
        for rel in (SERVICE, updater.ROUTE_CACHE):
            with self.subTest(rel=rel):
                path = self.root / rel
                path.unlink(missing_ok=True)
                path.symlink_to(self.root / '.env')
                with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
                path.unlink()
                if rel in self.original: self.write(rel, self.original[rel], 0o640)
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                self.assertEqual(self.calls, [])
        (self.root.parent / 'meta-release-backups').symlink_to(self.root)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
        self.assert_original()


if __name__ == '__main__': unittest.main()
