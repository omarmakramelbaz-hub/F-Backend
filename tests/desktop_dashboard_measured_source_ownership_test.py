#!/usr/bin/env python3
"""Exercise measured-source ownership with a real unprivileged POSIX child.

All PHP, database and web boundaries use the existing isolated installer
fixture. Only fresh temporary files receive ownership changes.
"""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import traceback
import unittest


HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location(
    'desktop_measured_source_fixture', HERE / 'desktop_dashboard_server_install_test.py')
fixture_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture_module)
Fixture = fixture_module.Fixture
installer = fixture_module.installer

APP_UID, APP_GID = 1001, 1003
OTHER_UID, OTHER_GID = 2001, 2003
CACHE = 'database/migrations/2026_10_20_045322_create_cache_table.php'
CACHE_BYTES = b'<?php /* Existing cache migration: source evidence only; never execute. */\n'
KERNEL = 'app/Console/Kernel.php'
EXPECTED_MIGRATIONS = (
    'database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php',
    'database/migrations/2026_10_08_160000_create_desktop_dashboard_local_state.php',
    'database/migrations/2026_10_08_210000_create_desktop_dashboard_archived_commands.php',
    'database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php',
    'database/migrations/2026_10_08_220000_create_desktop_dashboard_media_manifest.php',
    'database/migrations/2026_10_08_230000_create_desktop_dashboard_source_manifest.php',
    'database/migrations/2026_10_20_000001_create_desktop_dashboard_print_ownership.php',
    'database/migrations/2026_10_20_000002_create_desktop_dashboard_created_scopes.php',
)
def ownership_environment():
    if os.name != 'posix' or not hasattr(os, 'fork') or os.geteuid() != 0:
        return False, 'root and POSIX fork are required'
    # A root-only user namespace cannot represent the real app/foreign owners.
    # Report this honestly instead of replacing ownership with mocked metadata.
    for kind, required in [('uid', (APP_UID, OTHER_UID)), ('gid', (APP_GID, OTHER_GID))]:
        mapping = Path('/proc/self/' + kind + '_map')
        if mapping.exists():
            ranges = [tuple(map(int, line.split())) for line in mapping.read_text().splitlines()]
            if not all(any(start <= value < start + count for start, _, count in ranges)
                       for value in required):
                return False, kind + ' namespace does not map the required nonroot owners'
    return True, ''


REAL_OWNERS, OWNERS_REASON = ownership_environment()
REQUIRE_REAL_OWNERS = '--require-real-owners' in sys.argv
if REQUIRE_REAL_OWNERS:
    sys.argv.remove('--require-real-owners')


def identity(path):
    item = path.lstat()
    return (path.read_bytes(), item.st_uid, item.st_gid,
            stat.S_IMODE(item.st_mode), item.st_dev, item.st_ino, item.st_nlink)


@unittest.skipUnless(REAL_OWNERS, 'Real POSIX ownership unavailable: ' + OWNERS_REASON)
class MeasuredSourceOwnershipTests(unittest.TestCase):
    def setUp(self):
        self.f = Fixture()
        self.addCleanup(self.f.close)
        self.f.uid = APP_UID
        self.cache = self.f.root / CACHE
        self.f.write(self.cache, CACHE_BYTES)
        self.f.manifest['protected'].append({'path': CACHE, 'blob': installer.blob(CACHE_BYTES)})
        self.refresh_manifest()
        # The application and checkout belong to the app user. The staged
        # payload models the existing root launcher: readable root-owned files.
        os.chown(self.f.folder, APP_UID, APP_GID)
        self.f.folder.chmod(0o700)
        for path in [self.f.root, *self.f.root.rglob('*')]:
            os.chown(path, APP_UID, APP_GID)
        for path in [self.f.payload, *self.f.payload.rglob('*')]:
            os.chown(path, 0, 0)
            path.chmod(0o755 if path.is_dir() else 0o644)
        os.chown(self.cache, 0, 0)
        self.cache.chmod(0o644)
        self.cache_before = identity(self.cache)

    def refresh_manifest(self):
        self.f.manifest['before_source'] = installer.source_fingerprint(self.f.root, APP_UID)
        target = self.f.folder / 'ownership-target'
        shutil.copytree(self.f.root, target)
        try:
            for row in self.f.manifest['sources']:
                self.f.write(target / row['path'], (self.f.payload / row['path']).read_bytes())
            self.f.manifest['after_source'] = installer.source_fingerprint(target, APP_UID)
        finally:
            shutil.rmtree(target)
        self.f.save_manifest()

    def as_app(self, operation):
        read_fd, write_fd = os.pipe()
        pid = os.fork()
        if pid == 0:
            os.close(read_fd)
            try:
                os.setgroups([])
                os.setgid(APP_GID)
                os.setuid(APP_UID)
                self.assertEqual((os.getuid(), os.geteuid(), os.getgid(), os.getgroups()),
                                 (APP_UID, APP_UID, APP_GID, []))
                operation()
                report = {'ok': True, 'uid': os.geteuid(), 'gid': os.getegid()}
                status = 0
            except BaseException:
                report = {'ok': False, 'traceback': traceback.format_exc()}
                status = 1
            try:
                with os.fdopen(write_fd, 'w') as output:
                    json.dump(report, output)
            finally:
                os._exit(status)
        os.close(write_fd)
        with os.fdopen(read_fd) as incoming:
            report = incoming.read()
        _, status = os.waitpid(pid, 0)
        self.assertTrue(os.WIFEXITED(status), 'Unprivileged child terminated abnormally')
        self.assertEqual(os.WEXITSTATUS(status), 0, report)
        self.assertTrue(json.loads(report)['ok'], report)

    def no_publication_or_business_calls(self):
        self.assertEqual(self.f.migrations, [])
        self.assertFalse(any(call[0] == '/usr/bin/tar' or call[0] == installer.PHP
                             for call in self.f.calls))
        backups = self.f.folder / 'desktop-dashboard-release-backups'
        self.assertFalse(backups.exists() and any(path.is_dir() for path in backups.iterdir()))

    def test_root_owned_cache_is_readable_protected_and_never_executed_or_replaced(self):
        def operation():
            self.assertEqual(installer.read_source(self.cache, self.f.root, APP_UID), CACHE_BYTES)
            self.assertEqual(installer.source_fingerprint(self.f.root, APP_UID),
                             self.f.manifest['before_source'])
            self.assertNotIn(CACHE, [row['path'] for row in self.f.manifest['sources']])
            backup, result = self.f.install()
            self.assertEqual(installer.MIGRATIONS, EXPECTED_MIGRATIONS)
            self.assertEqual(self.f.migrations, list(EXPECTED_MIGRATIONS))
            self.assertNotIn(CACHE, self.f.migrations)
            self.assertEqual(result['source'], self.f.manifest['after_source'])
            self.assertEqual(result['web_authentication_checked'], 4)
            self.assertTrue((backup / 'code.tar').is_file())
            self.assertTrue((backup / 'database.sql').is_file())
            self.assertTrue((backup / 'activated.json').is_file())
            self.assertEqual(identity(self.cache), self.cache_before)
        self.as_app(operation)
        self.assertEqual(identity(self.cache), self.cache_before)

    def test_root_owned_changed_kernel_remains_unpublishable(self):
        original = b'<?php /* Existing root-owned console kernel. */\n'
        self.f.write(self.f.root / KERNEL, original)
        self.f.write(self.f.payload / KERNEL, b'<?php /* Reviewed replacement console kernel. */\n')
        self.f.manifest['sources'].append({
            'path': KERNEL, 'before_blob': installer.blob(original),
            'after_blob': installer.blob((self.f.payload / KERNEL).read_bytes()), 'phase': 2})
        self.refresh_manifest()
        # Only this publication target is root-owned; its ancestors remain app-owned.
        for path in (self.f.root / 'app/Console',):
            os.chown(path, APP_UID, APP_GID)
        os.chown(self.f.root / KERNEL, 0, 0)
        before = identity(self.f.root / KERNEL)
        def operation():
            with self.assertRaisesRegex(installer.SafeError,
                                        r'DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: app/Console/Kernel\.php; UID=0; MODE=0644'):
                self.f.install()
            self.no_publication_or_business_calls()
            self.assertEqual(identity(self.cache), self.cache_before)
        self.as_app(operation)
        self.assertEqual(identity(self.f.root / KERNEL), before)
        self.assertEqual(identity(self.cache), self.cache_before)

    def test_unrelated_owner_is_rejected_even_when_cache_bytes_are_readable(self):
        os.chown(self.cache, OTHER_UID, OTHER_GID)
        before = identity(self.cache)
        def operation():
            self.assertEqual(self.cache.read_bytes(), CACHE_BYTES)
            with self.assertRaisesRegex(installer.SafeError, 'DESKTOP_SOURCE_METADATA_INVALID'):
                self.f.install()
            self.no_publication_or_business_calls()
        self.as_app(operation)
        self.assertEqual(identity(self.cache), before)

    def test_cache_symlink_is_rejected_before_any_publication(self):
        self.cache.unlink()
        self.cache.symlink_to(self.f.root / 'config/meta.php')
        def operation():
            with self.assertRaisesRegex(installer.SafeError, 'DESKTOP_SOURCE_LINK'):
                self.f.install()
            self.no_publication_or_business_calls()
        self.as_app(operation)
        self.assertTrue(self.cache.is_symlink())

    def test_unknown_raw_cache_bytes_reject_even_if_normalized_fingerprint_matches(self):
        changed = CACHE_BYTES.replace(b'\n', b'\r\n')
        self.cache.write_bytes(changed)
        before = identity(self.cache)
        def operation():
            self.assertEqual(installer.source_fingerprint(self.f.root, APP_UID),
                             self.f.manifest['before_source'])
            with self.assertRaisesRegex(installer.SafeError, 'DESKTOP_NO_APPROVED_BASELINE_MATCHES'):
                self.f.install()
            self.no_publication_or_business_calls()
        self.as_app(operation)
        self.assertEqual(identity(self.cache), before)


if __name__ == '__main__':
    if REQUIRE_REAL_OWNERS and not REAL_OWNERS:
        print('REAL_POSIX_OWNERSHIP_REQUIRED: ' + OWNERS_REASON, file=sys.stderr)
        sys.exit(1)
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(MeasuredSourceOwnershipTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if REAL_OWNERS and result.wasSuccessful() and not result.skipped:
        print('REAL_POSIX_OWNERSHIP_EXECUTED uid=%d gid=%d tests=%d skipped=%d' %
              (APP_UID, APP_GID, result.testsRun, len(result.skipped)))
    elif REAL_OWNERS:
        print('REAL_POSIX_OWNERSHIP_FAILED tests=%d skipped=%d' %
              (result.testsRun, len(result.skipped)))
    else:
        print('REAL_POSIX_OWNERSHIP_SKIPPED tests=%d: %s' % (result.testsRun, OWNERS_REASON))
    sys.exit(0 if result.wasSuccessful() and not (REQUIRE_REAL_OWNERS and result.skipped) else 1)
