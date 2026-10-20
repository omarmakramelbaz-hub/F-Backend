import contextlib
import datetime
import io
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deployment'))
import audit_remaining_backup_candidates_root as audit
import cleanup_remaining_historical_copies_root as historical


class HistoricalCleanupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.home = self.base / 'account'
        self.home.mkdir()
        self.live = self.home / 'public_html'
        self.live.mkdir()
        (self.live / '.env').write_bytes(b'private live configuration, do not remove')
        (self.live / 'storage').mkdir()
        (self.live / 'storage' / 'customer-photo').write_bytes(b'preserve customer image')
        self.component = self.home / 'known-component-backups'
        self.component.mkdir()
        (self.component / 'before.php').write_bytes(b'old approved code copy')
        self.old_archive = self.home / 'known-old-archive.tar.gz'
        self.old_archive.write_bytes(b'archived old copy')
        self.backup = self.base / 'full-backups' / 'verified-current'
        self.backup.mkdir(parents=True)
        with tarfile.open(str(self.backup / 'home-current.tar.gz'), 'w:gz') as archive:
            archive.add(str(self.home), arcname='.')
        self.failed = self.backup.parent / 'failed-attempt'
        self.failed.mkdir()
        (self.failed / 'private.log').write_bytes(b'old failed attempt')
        self.manifest = {'layout': 'split-home', 'account': 'fasakha',
                         'started_utc': '2099-01-01T00:00:00.000000Z',
                         'database_verification': {'fasakha_db': {}, 'fasakha_dev_db': {}}}
        self.whitelist = (str(self.component), str(self.old_archive), str(self.failed))
        self.patches = contextlib.ExitStack()
        self.patches.enter_context(mock.patch.object(historical, 'HOME', str(self.home)))
        self.patches.enter_context(mock.patch.object(historical, 'WHITELIST', self.whitelist))
        self.patches.enter_context(mock.patch.object(historical, 'FAILED', str(self.failed)))
        self.patches.enter_context(mock.patch.object(historical, 'LOCK_PATH', str(self.base / 'cleanup.lock')))
        self.patches.enter_context(mock.patch.object(audit, 'CANDIDATES', audit.CANDIDATES))
        self.patches.enter_context(mock.patch.object(audit, 'CONFIGS', ()))
        self.patches.enter_context(mock.patch.object(audit, 'BACKUP', str(self.backup)))
        self.patches.enter_context(mock.patch.object(audit, 'verify_backup', return_value=self.manifest))
        self.patches.enter_context(mock.patch.object(audit, 'dependency_check', return_value=[]))
        self.patches.enter_context(mock.patch.object(historical.os, 'geteuid', return_value=0))
        self.patches.enter_context(mock.patch.object(historical.socket, 'gethostname', return_value='srv1836524'))
        self.patches.enter_context(mock.patch.object(historical.os, 'chdir'))

    def tearDown(self):
        self.patches.close()
        self.temp.cleanup()

    def run_cleanup(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            historical.main(['--apply'])
        return output.getvalue()

    def test_verified_archived_components_and_failed_attempt_removed_live_data_preserved(self):
        output = self.run_cleanup()
        self.assertTrue(all(not os.path.lexists(path) for path in self.whitelist))
        self.assertTrue((self.live / '.env').is_file())
        self.assertTrue((self.live / 'storage' / 'customer-photo').is_file())
        self.assertTrue((self.backup / 'home-current.tar.gz').is_file())
        self.assertIn('ARCHIVED_OLD_COPIES_BYTE_VERIFIED', output)
        self.assertIn('CLEANUP_COMPLETE', output)
        report = next(self.backup.glob('extra-cleanup-staging-*.json')).read_text()
        self.assertIn('archived_byte_coverage', report)
        self.assertNotIn('private live configuration', output + report)

    def test_same_length_changed_old_copy_blocks_every_deletion(self):
        self.old_archive.write_bytes(b'changed old copy!')
        with self.assertRaises(historical.CoverageError):
            self.run_cleanup()
        self.assertTrue(all(os.path.lexists(path) for path in self.whitelist))

    def test_component_newer_than_backup_retained_other_old_copies_cleaned(self):
        future = datetime.datetime(2100, 1, 1, tzinfo=datetime.timezone.utc).timestamp()
        os.utime(str(self.component / 'before.php'), (future, future))
        output = self.run_cleanup()
        self.assertTrue(self.component.is_dir())
        self.assertFalse(self.old_archive.exists())
        self.assertFalse(self.failed.exists())
        self.assertIn('CLEANUP_PARTIAL', output)

    def test_failed_attempt_with_success_marker_is_retained(self):
        (self.failed / 'backup.ok').write_text('new success state')
        output = self.run_cleanup()
        self.assertTrue((self.failed / 'backup.ok').is_file())
        self.assertFalse(self.component.exists())
        self.assertIn('CLEANUP_PARTIAL', output)

    def test_lazy_runtime_source_and_environment_variants_are_scanned(self):
        (self.live / '.env.production').write_text('private variant')
        historical.configure_scope()
        for name in ('app', 'routes', 'resources', '.env.production'):
            self.assertIn(str(self.live / name), audit.CONFIGS)


if __name__ == '__main__':
    unittest.main()
