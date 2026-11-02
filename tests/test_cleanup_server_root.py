"""The approved leftovers go only once the full backup holds each of them whole; nothing else is ever removed."""
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('server_cleanup', ROOT / 'deployment/cleanup_server_root.py')
cleanup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(cleanup)


def add_tree(archive, disk, name):
    """Adds a folder (or file) to a tar under the given member name, like the backup's tar did."""
    archive.add(str(disk), arcname=name, recursive=True)


class CleanupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-cleanup-')
        base = Path(self.temporary.name)
        self.site = base / 'home/fasakha/public_html'
        self.live_updates = base / 'var/lib/go-erp-live-updates'
        self.backup = base / 'backup'
        for folder in (self.site / 'test.fasakhaninja.com/dev/storage', self.site / 'faskhaNinja/lib', self.live_updates / 'staging',
                       self.site / 'app', self.backup):
            folder.mkdir(parents=True)
        (self.site / 'test.fasakhaninja.com/dev/index.php').write_text('<?php // the test site')
        (self.site / 'test.fasakhaninja.com/dev/storage/laravel.log').write_text('a log')
        (self.site / 'faskhaNinja/lib/main.dart').write_text('void main() {}')
        (self.live_updates / 'staging/state.json').write_text('{}')
        (self.site / 'app/Keep.php').write_text('<?php // the site itself')
        (self.site / '.env').write_text('APP_KEY=kept')
        for name in cleanup.SITE_FILES:
            (self.site / name).write_text('old copy of ' + name)
        os.symlink('/etc/hostname', str(self.site / 'faskhaNinja/link'))
        self.original = (cleanup.SITE, cleanup.BACKUP, cleanup.LIVE_UPDATES, cleanup.LOW)
        cleanup.SITE, cleanup.BACKUP, cleanup.LIVE_UPDATES, cleanup.LOW = self.site, self.backup, self.live_updates, []
        self.make_backup()

    def tearDown(self):
        cleanup.SITE, cleanup.BACKUP, cleanup.LIVE_UPDATES, cleanup.LOW = self.original
        self.temporary.cleanup()

    def make_backup(self, skip=()):
        parts = []
        with tarfile.open(str(self.backup / 'home-current.tar.gz'), 'w:gz') as home:
            for name in ('test.fasakhaninja.com', 'faskhaNinja', 'app', '.env') + cleanup.SITE_FILES:
                if name not in skip:
                    add_tree(home, self.site / name, 'fasakha/public_html/' + name)
        with tarfile.open(str(self.backup / 'server-runtime.tar.gz'), 'w:gz') as runtime:
            add_tree(runtime, self.live_updates, 'var/lib/go-erp-live-updates')
        for name in ('home-current.tar.gz', 'server-runtime.tar.gz'):
            parts.append({'file': name, 'bytes': (self.backup / name).stat().st_size, 'sha256': 'x'})
        (self.backup / 'manifest.json').write_text(json.dumps({'parts': parts, 'system': ['/var/lib/go-erp-live-updates']}))

    def test_the_plan_only_reads(self):
        lines = cleanup.plan()
        self.assertEqual(lines[0], 'GO_ERP_SERVER_CLEANUP_PLAN')
        self.assertTrue(any('faskhaNinja: 4 entries' in line for line in lines))
        self.assertTrue((self.site / 'faskhaNinja').is_dir())
        self.assertTrue((self.site / '-ef | grep cpauthd').is_file())

    def test_everything_the_backup_holds_is_removed_and_nothing_else(self):
        lines, ok = cleanup.apply()
        self.assertTrue(ok, lines)
        self.assertEqual(lines[-1], 'GO_ERP_SERVER_CLEANUP_DONE')
        for name in ('test.fasakhaninja.com', 'faskhaNinja') + cleanup.SITE_FILES:
            self.assertFalse(os.path.lexists(str(self.site / name)), name)
        self.assertFalse(self.live_updates.exists())
        self.assertEqual((self.site / '.env').read_text(), 'APP_KEY=kept', 'The live .env is never touched')
        self.assertTrue((self.site / 'app/Keep.php').is_file())
        self.assertTrue(os.path.exists('/etc/hostname'), 'A link is removed, never what it points to')
        self.assertIn("removed: {} ".format(self.site / ".send_order_email', ["), '\n'.join(lines))

    def test_what_changed_since_the_backup_is_kept(self):
        (self.site / 'faskhaNinja/lib/new.dart').write_text('added after the backup')
        lines, ok = cleanup.apply()
        self.assertFalse(ok)
        self.assertTrue((self.site / 'faskhaNinja/lib/new.dart').is_file(), 'Not in the backup: kept')
        self.assertIn('kept (the backup holds 4 of its 5 entries): ' + str(self.site / 'faskhaNinja'), lines)
        self.assertFalse((self.site / 'test.fasakhaninja.com').exists(), 'The rest still goes')
        self.assertEqual(lines[-1], 'GO_ERP_SERVER_CLEANUP_PARTIAL (1 kept)')

    def test_a_file_missing_from_the_backup_is_kept(self):
        self.make_backup(skip=('.env.save',))
        lines, ok = cleanup.apply()
        self.assertFalse(ok)
        self.assertTrue((self.site / '.env.save').is_file())
        self.assertFalse((self.site / '.env.bak-cache-20261020-045526').exists())

    def test_a_changed_backup_stops_before_removing_anything(self):
        with open(str(self.backup / 'home-current.tar.gz'), 'ab') as archive:
            archive.write(b'tampered')
        with self.assertRaises(cleanup.Stop) as stopped:
            cleanup.apply()
        self.assertEqual(str(stopped.exception), 'GO_ERP_SERVER_CLEANUP_BACKUP_PART_CHANGED: home-current.tar.gz')
        self.assertTrue((self.site / 'faskhaNinja').is_dir())
        self.assertTrue(self.live_updates.is_dir())

    def test_only_the_listed_paths_can_ever_be_removed(self):
        names = [str(path) for path, archive, name, folder in cleanup.targets()]
        self.assertEqual(len(names), 12)
        self.assertNotIn(str(self.site), names)
        self.assertNotIn(str(self.site / '.env'), names)
        self.assertTrue(all(name.startswith((str(self.site) + '/', str(self.live_updates))) for name in names))


if __name__ == '__main__':
    unittest.main()
