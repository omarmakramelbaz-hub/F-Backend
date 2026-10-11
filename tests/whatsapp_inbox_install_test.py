#!/usr/bin/env python3
"""Isolated installer fixtures: no PHP, database, network or server access."""
import importlib.util
import json
import os
import pathlib
import stat
import sys
import signal
import tempfile
import unittest
from unittest import mock

MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / 'deployment/install_whatsapp_inbox.py'
SPEC = importlib.util.spec_from_file_location('whatsapp_inbox_installer', MODULE_PATH)
installer = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = installer
SPEC.loader.exec_module(installer)
RELEASE = 'a' * 40


class Fixture:
    def __init__(self, base, newline=b'\n', routes_ending=True):
        self.uid = os.geteuid()
        self.root = pathlib.Path(base) / 'project'
        self.root.mkdir(mode=0o700)
        (self.root / '.git').mkdir()
        self.core = {}
        for index, relative in enumerate(installer.CORE_HASHES):
            content = b'<?php /* core ' + str(index).encode() + b' */\n'
            self.write(relative, content)
            self.core[relative] = installer.blob_hash(content)
        self.routes = b'<?php' + newline + b'// existing unrelated routes'
        if routes_ending:
            self.routes += newline
        self.menu = b'<nav>' + newline + b'  ' + installer.MENU_ANCHOR + newline + b'</nav>' + newline
        self.write(installer.ROUTES_PATH, self.routes, 0o640)
        self.write(installer.MENU_PATH, self.menu, 0o600)
        self.write('app/Console/Kernel.php', b"<?php $this->load(__DIR__.'/Commands');\n")
        self.write('.env', b'APP_SECRET=fixture-secret-must-never-be-read\n', 0o600)
        self.payloads = {relative: (b'<?php // new ' + relative.encode() + b'\n')
                         for relative in installer.MANIFEST}
        self.calls = []
        self.fail = None
        self.hook = None
        self.metrics = {key: 0 for key in installer.METRICS}
        self.consume_code = 0

    def write(self, relative, content, mode=0o644):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def runner(self, root, args, capture=False, timeout=60):
        self.calls.append((pathlib.Path(root), tuple(args), capture))
        if self.hook is not None:
            self.hook(root, args)
        if self.fail is not None and self.fail(args):
            return 1, b'deliberately sensitive stderr is never forwarded'
        if args[:3] == ['git', 'cat-file', '-t']:
            return 0, b'commit\n'
        if args[:3] == ['git', 'ls-tree', '-z']:
            relative = args[-1]
            return 0, b'100644 blob ' + b'b' * 40 + b'\t' + relative.encode() + b'\0'
        if args[:2] == ['git', 'show']:
            relative = args[-1].split(':', 1)[1]
            return 0, self.payloads.get(relative, b'')
        if 'whatsapp:consume-inbox' in args:
            return self.consume_code, json.dumps(self.metrics).encode()
        return 0, b''

    def install(self, consume=0):
        return installer.install(self.root, self.uid, RELEASE, consume, self.runner,
                                 self.core, installer.blob_hash(self.routes), installer.blob_hash(self.menu))

    def unchanged_legacy(self):
        return ((self.root / installer.ROUTES_PATH).read_bytes() == self.routes and
                (self.root / installer.MENU_PATH).read_bytes() == self.menu and
                stat.S_IMODE((self.root / installer.ROUTES_PATH).stat().st_mode) == 0o640 and
                stat.S_IMODE((self.root / installer.MENU_PATH).stat().st_mode) == 0o600)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='whatsapp-inbox-installer-test-')
        self.fixture = Fixture(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def test_additive_install_preserves_legacy_and_private_backup(self):
        fixture = self.fixture
        result, backup, metrics = fixture.install()
        self.assertEqual(result, 'WHATSAPP_INBOX_READY')
        self.assertIsNone(metrics)
        self.assertEqual((fixture.root / installer.ROUTES_PATH).read_bytes(),
                         fixture.routes + b'\n' + installer.ROUTES_LINE + b'\n')
        self.assertEqual((fixture.root / installer.MENU_PATH).read_bytes(),
                         fixture.menu.replace(installer.MENU_ANCHOR, installer.MENU_LINE + b'\n' + installer.MENU_ANCHOR))
        for relative, content in fixture.payloads.items():
            self.assertEqual((fixture.root / relative).read_bytes(), content)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((backup / 'receipt.json').stat().st_mode), 0o600)
        self.assertEqual((fixture.root / '.env').read_bytes(), b'APP_SECRET=fixture-secret-must-never-be-read\n')
        self.assertFalse(any('config:clear' in args or 'migrate:rollback' in args or 'checkout' in args
                             for _, args, _ in fixture.calls))
        migrations = [args for _, args, _ in fixture.calls if 'migrate' in args]
        self.assertEqual(migrations, [('php', 'artisan', 'migrate', '--path=' + installer.MIGRATION, '--force')])

    def test_exact_repeat_does_not_rewrite_files(self):
        fixture = self.fixture
        fixture.install()
        inodes = {relative: (fixture.root / relative).stat().st_ino
                  for relative in (*installer.MANIFEST, installer.ROUTES_PATH, installer.MENU_PATH)}
        fixture.install()
        self.assertEqual(inodes, {relative: (fixture.root / relative).stat().st_ino for relative in inodes})

    def test_crlf_and_no_final_newline_anchor_idempotence(self):
        for ending in (True, False):
            with tempfile.TemporaryDirectory() as base:
                fixture = Fixture(base, newline=b'\r\n', routes_ending=ending)
                fixture.install()
                first = (fixture.root / installer.ROUTES_PATH).read_bytes()
                fixture.install()
                self.assertEqual((fixture.root / installer.ROUTES_PATH).read_bytes(), first)
                self.assertTrue(first.startswith(fixture.routes))

    def test_missing_core_fails_before_mutations(self):
        fixture = self.fixture
        (fixture.root / next(iter(fixture.core))).unlink()
        with self.assertRaisesRegex(installer.SafeError, 'REQUIRED_FILE_MISSING'):
            fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        self.assertFalse(any((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_unreviewed_core_and_legacy_edits_fail_closed(self):
        fixture = self.fixture
        fixture.write(next(iter(fixture.core)), b'<?php changed-core\n')
        with self.assertRaisesRegex(installer.SafeError, 'CORE_FILE_HASH_NEEDS_REVIEW'):
            fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        for relative in (installer.ROUTES_PATH, installer.MENU_PATH):
            source = fixture.routes if relative == installer.ROUTES_PATH else fixture.menu
            func = installer.route_proposal if relative == installer.ROUTES_PATH else installer.menu_proposal
            with self.assertRaises(installer.SafeError):
                func(source + b'/* external */', installer.blob_hash(source))

    def test_duplicate_menu_anchor_and_closing_php_tag_are_rejected(self):
        duplicate = installer.MENU_ANCHOR + b'\n' + installer.MENU_ANCHOR
        with self.assertRaisesRegex(installer.SafeError, 'MENU_ANCHOR_AMBIGUOUS'):
            installer.menu_proposal(duplicate, installer.blob_hash(duplicate))
        source = b'<?php\n?>'
        with self.assertRaisesRegex(installer.SafeError, 'ROUTES_CLOSING_TAG_NEEDS_REVIEW'):
            installer.route_proposal(source, installer.blob_hash(source))

    def test_existing_new_file_only_accepted_if_identical(self):
        fixture = self.fixture
        relative = installer.MANIFEST[0]
        fixture.write(relative, fixture.payloads[relative], 0o620)
        fixture.install()
        self.assertEqual(stat.S_IMODE((fixture.root / relative).stat().st_mode), 0o620)
        # A different pinned release must not overwrite a deployed addition.
        fixture.payloads[relative] += b'new version\n'
        with self.assertRaisesRegex(installer.SafeError, 'EXISTING_INBOX_FILE_DIFFERS'):
            fixture.install()

    def test_project_and_backup_symlinks_are_rejected(self):
        fixture = self.fixture
        outside = pathlib.Path(self.temporary.name) / 'outside'
        outside.mkdir()
        (fixture.root / 'public').symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(installer.SafeError, 'ANCESTOR_NOT_DIRECTORY_OR_SYMLINK'):
            fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        (fixture.root / 'public').unlink()
        parent = fixture.root.parent / 'whatsapp-release-backups'
        for child in parent.iterdir():
            child.unlink()
        parent.rmdir()
        parent.symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(installer.SafeError, 'PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW'):
            fixture.install()

    def test_release_symlink_mode_and_lint_failure_stop_before_write(self):
        fixture = self.fixture
        original = fixture.runner
        def symlink(root, args, **kwargs):
            code, output = original(root, args, **kwargs)
            if args[:3] == ['git', 'ls-tree', '-z']:
                output = output.replace(b'100644', b'120000')
            return code, output
        fixture.runner = symlink
        with self.assertRaisesRegex(installer.SafeError, 'PINNED_RELEASE_FILE_NOT_REGULAR'):
            fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        fixture.runner = original
        fixture.fail = lambda args: args[:2] == ['php', '-l']
        with self.assertRaisesRegex(installer.SafeError, 'PINNED_PHP_LINT_FAILED'):
            fixture.install()
        self.assertTrue(fixture.unchanged_legacy())

    def test_migration_and_smoke_failure_restore_only_our_files(self):
        for failure in ('migrate', 'smoke'):
            with tempfile.TemporaryDirectory() as base:
                fixture = Fixture(base)
                fixture.fail = lambda args: ('migrate' in args if failure == 'migrate' else args[:2] == ['php', '-r'])
                with self.assertRaisesRegex(installer.SafeError, 'INBOX_FILES_RESTORED_TABLES_RETAINED'):
                    fixture.install()
                self.assertTrue(fixture.unchanged_legacy())
                self.assertFalse(any((fixture.root / relative).exists() for relative in installer.MANIFEST))
                self.assertFalse(any('migrate:rollback' in args for _, args, _ in fixture.calls))

    def test_partial_file_write_failure_restores_before_exposing_route(self):
        fixture = self.fixture
        original = installer.replace_file
        failed = False
        def replacement(path, content, expected, uid, root, created, restore=None, **kwargs):
            nonlocal failed
            if not failed and str(path).endswith(installer.MANIFEST[1]):
                failed = True
                raise installer.SafeError('FIXTURE_WRITE_FAILURE')
            return original(path, content, expected, uid, root, created, restore, **kwargs)
        with mock.patch.object(installer, 'replace_file', replacement):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_FILES_RESTORED_TABLES_RETAINED'):
                fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        self.assertFalse(any((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_concurrent_absent_file_creation_during_preflight_is_preserved(self):
        fixture = self.fixture
        external = installer.MANIFEST[0]
        done = False
        def hook(root, args):
            nonlocal done
            if not done and args[:2] == ['php', '-l'] and 'composed-menu.blade.php' in args[-1]:
                fixture.write(external, b'concurrent-new-file\n')
                done = True
        fixture.hook = hook
        with self.assertRaisesRegex(installer.SafeError, 'INBOX_FILES_RESTORED_TABLES_RETAINED'):
            fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        self.assertEqual((fixture.root / external).read_bytes(), b'concurrent-new-file\n')

    def test_concurrent_core_edit_is_not_restored_or_replaced(self):
        fixture = self.fixture
        relative = next(iter(fixture.core))
        def hook(root, args):
            if args[:2] == ['php', '-r']:
                fixture.write(relative, b'concurrent-reviewed-core\n')
        fixture.hook = hook
        with self.assertRaisesRegex(installer.SafeError, 'INBOX_FILES_RESTORED_TABLES_RETAINED'):
            fixture.install()
        self.assertEqual((fixture.root / relative).read_bytes(), b'concurrent-reviewed-core\n')
        self.assertTrue(fixture.unchanged_legacy())

    def test_concurrent_permission_change_prevents_rollback_overwrite(self):
        fixture = self.fixture
        def hook(root, args):
            if args[:2] == ['php', '-r']:
                (fixture.root / installer.ROUTES_PATH).chmod(0o604)
        fixture.hook = hook
        fixture.fail = lambda args: args[:2] == ['php', '-r']
        with self.assertRaisesRegex(installer.SafeError, 'INBOX_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
            fixture.install()
        self.assertIn(installer.ROUTES_LINE, (fixture.root / installer.ROUTES_PATH).read_bytes())
        self.assertEqual(stat.S_IMODE((fixture.root / installer.ROUTES_PATH).stat().st_mode), 0o604)
        self.assertEqual((fixture.root / installer.MENU_PATH).read_bytes(), fixture.menu)

    def test_concurrent_owner_change_prevents_rollback_overwrite(self):
        fixture = self.fixture
        changed = False
        actual_snapshot = installer.snapshot
        def hook(root, args):
            nonlocal changed
            if args[:2] == ['php', '-r']:
                changed = True
        def ownership_change(path, uid, root, absent_ok=False):
            item = actual_snapshot(path, uid, root, absent_ok)
            if changed and str(path).endswith(installer.ROUTES_PATH) and item is not None:
                # Container filesystems may reject chown even as root. Model only
                # the metadata returned after a competing owner change.
                return installer.Snapshot(item.content, uid + 1, item.gid, item.mode, item.device, item.inode)
            return item
        fixture.hook = hook
        fixture.fail = lambda args: args[:2] == ['php', '-r']
        with mock.patch.object(installer, 'snapshot', ownership_change):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
                fixture.install()
        self.assertIn(installer.ROUTES_LINE, (fixture.root / installer.ROUTES_PATH).read_bytes())

    def test_external_edit_immediately_after_publish_is_never_journaled_as_ours(self):
        fixture = self.fixture
        actual_replace = installer.atomic_exchange
        done = False
        def competing_replace(source, destination):
            nonlocal done
            actual_replace(source, destination)
            if not done and str(destination).endswith(installer.ROUTES_PATH):
                pathlib.Path(destination).write_bytes(b'external route replacement\n')
                done = True
        with mock.patch.object(installer, 'atomic_exchange', competing_replace):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
                fixture.install()
        self.assertEqual((fixture.root / installer.ROUTES_PATH).read_bytes(), b'external route replacement\n')
        self.assertEqual((fixture.root / installer.MENU_PATH).read_bytes(), fixture.menu)
        self.assertTrue(all((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_preexchange_external_edit_is_restored_with_dependencies_retained(self):
        fixture = self.fixture
        actual_exchange = installer.atomic_exchange
        external = fixture.routes + b"\nrequire __DIR__.'/whatsapp_inbox.php'; // external edit\n"
        done = False
        def competing_exchange(source, destination):
            nonlocal done
            if not done and str(destination).endswith(installer.ROUTES_PATH):
                pathlib.Path(destination).write_bytes(external)
                done = True
            return actual_exchange(source, destination)
        with mock.patch.object(installer, 'atomic_exchange', competing_exchange):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
                fixture.install()
        self.assertEqual((fixture.root / installer.ROUTES_PATH).read_bytes(), external)
        self.assertTrue(all((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_interruption_after_exchange_restores_original_before_removing_dependencies(self):
        fixture = self.fixture
        actual_exchange = installer.atomic_exchange
        done = False
        def interrupted_exchange(source, destination):
            nonlocal done
            result = actual_exchange(source, destination)
            if not done and str(destination).endswith(installer.ROUTES_PATH):
                done = True
                raise KeyboardInterrupt()
            return result
        with mock.patch.object(installer, 'atomic_exchange', interrupted_exchange):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_FILES_RESTORED_TABLES_RETAINED'):
                fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        self.assertFalse(any((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_interruption_after_new_file_link_is_recoverable(self):
        fixture = self.fixture
        actual_link = installer.os.link
        done = False
        def interrupted_link(source, destination, **kwargs):
            nonlocal done
            result = actual_link(source, destination, **kwargs)
            if not done and str(destination).endswith(installer.MANIFEST[0]):
                done = True
                raise KeyboardInterrupt()
            return result
        with mock.patch.object(installer.os, 'link', interrupted_link):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_FILES_RESTORED_TABLES_RETAINED'):
                fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        self.assertFalse(any((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_prerecovery_race_retains_external_anchor_and_all_dependencies(self):
        fixture = self.fixture
        actual_move = installer.atomic_move_noreplace
        done = False
        external = fixture.routes + b'\n' + installer.ROUTES_LINE + b' // external rollback edit\n'
        def competing_move(source, destination):
            nonlocal done
            if not done and str(source).endswith(installer.ROUTES_PATH):
                pathlib.Path(source).write_bytes(external)
                done = True
            return actual_move(source, destination)
        fixture.fail = lambda args: args[:2] == ['php', '-r']
        with mock.patch.object(installer, 'atomic_move_noreplace', competing_move):
            with self.assertRaisesRegex(installer.SafeError, 'INBOX_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
                fixture.install()
        self.assertEqual((fixture.root / installer.ROUTES_PATH).read_bytes(), external)
        self.assertTrue(all((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_linux_exchange_support_is_required_before_project_mutation(self):
        fixture = self.fixture
        with mock.patch.object(installer, 'atomic_exchange', side_effect=installer.SafeError('ATOMIC_RENAME_UNAVAILABLE')):
            with self.assertRaisesRegex(installer.SafeError, 'ATOMIC_RENAME_UNAVAILABLE'):
                fixture.install()
        self.assertTrue(fixture.unchanged_legacy())
        self.assertFalse(any((fixture.root / relative).exists() for relative in installer.MANIFEST))

    def test_publication_syscall_runs_with_termination_signals_blocked(self):
        fixture = self.fixture
        actual_exchange = installer.atomic_exchange
        checked = False
        def checked_exchange(source, destination):
            nonlocal checked
            if str(destination).endswith(installer.ROUTES_PATH):
                current_mask = signal.pthread_sigmask(signal.SIG_BLOCK, set())
                self.assertTrue({signal.SIGINT, signal.SIGTERM}.issubset(current_mask))
                checked = True
            return actual_exchange(source, destination)
        with mock.patch.object(installer, 'atomic_exchange', checked_exchange):
            fixture.install()
        self.assertTrue(checked)

    def test_atomic_create_does_not_overwrite_an_intervening_file(self):
        fixture = self.fixture
        path = fixture.root / 'new.txt'
        real_link = os.link
        def competing_link(source, destination, **kwargs):
            pathlib.Path(destination).write_bytes(b'external')
            return real_link(source, destination, **kwargs)
        with mock.patch.object(installer.os, 'link', competing_link):
            with self.assertRaisesRegex(installer.SafeError, 'FILE_CHANGED_CONCURRENTLY'):
                installer.replace_file(path, b'ours', None, fixture.uid, fixture.root, [])
        self.assertEqual(path.read_bytes(), b'external')

    def test_quarantine_keeps_working_install_and_reports_fixed_metrics(self):
        fixture = self.fixture
        fixture.metrics['quarantined_events'] = 2
        fixture.consume_code = 2
        result, _, metrics = fixture.install(consume=100)
        self.assertEqual(result, 'WHATSAPP_INBOX_READY_PROJECTION_REVIEW_REQUIRED')
        self.assertEqual(metrics['quarantined_events'], 2)
        self.assertIn(installer.ROUTES_LINE, (fixture.root / installer.ROUTES_PATH).read_bytes())
        self.assertTrue(all((fixture.root / relative).exists() for relative in installer.MANIFEST))
        self.assertEqual([args for _, args, _ in fixture.calls if 'whatsapp:consume-inbox' in args],
                         [('php', 'artisan', 'whatsapp:consume-inbox', '--limit=100')])

    def test_invalid_projection_output_is_not_forwarded(self):
        fixture = self.fixture
        fixture.metrics = {'private': 'fixture-secret-must-never-be-printed'}
        result, _, metrics = fixture.install(consume=10)
        self.assertEqual(result, 'WHATSAPP_INBOX_READY_PROJECTION_REPORT_UNKNOWN')
        self.assertIsNone(metrics)

    def test_invalid_release_and_large_limit_stop_before_staging(self):
        fixture = self.fixture
        for release, limit in (('main', 0), (RELEASE, 101)):
            with self.assertRaisesRegex(installer.SafeError, 'INVALID_RELEASE_OR_CONSUMPTION_LIMIT'):
                installer.install(fixture.root, fixture.uid, release, limit, fixture.runner)
        self.assertFalse(fixture.calls)
        self.assertTrue(fixture.unchanged_legacy())


if __name__ == '__main__':
    unittest.main()
