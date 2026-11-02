"""The full backup before the refactor: every part made, read back and listed; nothing left behind when a part fails."""
import gzip
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('full_backup', ROOT / 'deployment/backup_server_full_root.py')
backup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(backup)


class FullBackupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-full-backup-')
        base = Path(self.temporary.name)
        self.home = base / 'home' / 'fasakha'
        for name in ('public_html/storage/app/public', 'public_html/storage/framework/cache', 'public_html/storage/framework/sessions', 'mail'):
            (self.home / name).mkdir(parents=True)
        (self.home / 'public_html/index.php').write_text('<?php // the site')
        (self.home / 'public_html/storage/app/public/photo.jpg').write_bytes(b'\xff\xd8 a photo')
        (self.home / 'public_html/storage/framework/cache/x').write_text('cache')
        (self.home / 'public_html/storage/framework/sessions/s').write_text('session')
        self.state = base / 'var/lib/go-erp-desktop-sync'
        self.state.mkdir(parents=True)
        (self.state / 'state.json').write_text('{}')
        self.backups = base / 'root/fasakhansta-full-backups'
        self.dumped = []
        self.original = (backup.HOME, backup.BACKUPS, backup.SYSTEM, backup.PKGACCT, backup.databases, backup.dump_database, backup.LOW, backup.free_bytes)
        backup.HOME, backup.BACKUPS, backup.SYSTEM = self.home, self.backups, (str(self.state), str(base / 'etc/missing'))
        backup.PKGACCT = str(base / 'no-pkgacct')
        backup.LOW = []
        backup.databases = lambda: ['fasakha_main', 'fasakha_owner2']

        def dump(name, target):
            self.dumped.append(name)
            with gzip.open(str(target), 'wb') as output:
                output.write(('CREATE DATABASE `%s`;\n-- Dump completed on 2026-11-02\n' % name).encode())
        backup.dump_database = dump

    def tearDown(self):
        (backup.HOME, backup.BACKUPS, backup.SYSTEM, backup.PKGACCT, backup.databases, backup.dump_database, backup.LOW, backup.free_bytes) = self.original
        self.temporary.cleanup()

    def test_the_plan_only_reads(self):
        result = backup.plan()
        self.assertEqual(result['result'], 'GO_ERP_FULL_BACKUP_PLAN')
        self.assertEqual(result['databases'], ['fasakha_main', 'fasakha_owner2'])
        self.assertTrue(result['enough_space'])
        self.assertFalse(result['cpanel_account_backup'])
        self.assertFalse(self.backups.exists())

    def test_every_part_is_made_read_back_and_listed(self):
        result = backup.apply()
        self.assertEqual(result['result'], 'GO_ERP_FULL_BACKUP_DONE')
        folder = Path(result['folder'])
        self.assertTrue(folder.name.endswith('-before-refactor'))
        self.assertEqual(stat.S_IMODE(folder.stat().st_mode), 0o700)
        self.assertEqual(self.dumped, ['fasakha_main', 'fasakha_owner2'])
        manifest = json.loads((folder / 'manifest.json').read_text())
        self.assertEqual([part['file'] for part in manifest['parts']],
                         ['databases/fasakha_main.sql.gz', 'databases/fasakha_owner2.sql.gz', 'home-current.tar.gz', 'server-runtime.tar.gz'])
        for part in manifest['parts']:
            path = folder / part['file']
            self.assertEqual((path.stat().st_size, stat.S_IMODE(path.stat().st_mode)), (part['bytes'], 0o600))
        with tarfile.open(str(folder / 'home-current.tar.gz')) as home:
            names = home.getnames()
        self.assertIn('fasakha/public_html/index.php', names)
        self.assertIn('fasakha/public_html/storage/app/public/photo.jpg', names, 'The uploads are kept')
        self.assertFalse([name for name in names if '/framework/cache/' in name or '/framework/sessions/' in name], 'Not the caches')
        with tarfile.open(str(folder / 'server-runtime.tar.gz')) as runtime:
            self.assertTrue(any(name.endswith('go-erp-desktop-sync/state.json') for name in runtime.getnames()))

    def test_without_enough_space_nothing_is_written(self):
        backup.free_bytes = lambda folder: 10
        with self.assertRaises(backup.Stop) as stopped:
            backup.apply()
        self.assertEqual(str(stopped.exception), 'GO_ERP_FULL_BACKUP_NOT_ENOUGH_SPACE')
        self.assertFalse(self.backups.exists() and any(self.backups.iterdir()))

    def test_a_part_that_fails_leaves_nothing_behind(self):
        def broken(name, target):
            raise backup.Stop('GO_ERP_FULL_BACKUP_DATABASE_DUMP_INCOMPLETE: ' + name)
        backup.dump_database = broken
        with self.assertRaises(backup.Stop):
            backup.apply()
        self.assertEqual(list(self.backups.iterdir()), [], 'The unfinished backup is removed')

    def test_a_dump_must_end_as_a_complete_one(self):
        backup.dump_database = self.original[5]
        folder = Path(self.temporary.name)
        fake = folder / 'bin'
        fake.mkdir()
        (fake / 'mysqldump').write_text('#!/bin/sh\necho "CREATE TABLE t (id int);"\n')
        os.chmod(str(fake / 'mysqldump'), 0o755)
        path = os.environ['PATH']
        os.environ['PATH'] = str(fake) + os.pathsep + path
        try:
            with self.assertRaises(backup.Stop) as stopped:
                backup.dump_database('fasakha_main', folder / 'cut.sql.gz')
            self.assertEqual(str(stopped.exception), 'GO_ERP_FULL_BACKUP_DATABASE_DUMP_INCOMPLETE: fasakha_main')
            (fake / 'mysqldump').write_text('#!/bin/sh\necho "CREATE TABLE t (id int);"\necho "-- Dump completed on 2026-11-02 12:00:00"\n')
            backup.dump_database('fasakha_main', folder / 'whole.sql.gz')
            with gzip.open(str(folder / 'whole.sql.gz')) as whole:
                self.assertIn(b'Dump completed', whole.read())
        finally:
            os.environ['PATH'] = path


if __name__ == '__main__':
    unittest.main()
