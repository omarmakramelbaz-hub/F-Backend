"""Offline checks for inventory, account coverage and completion receipts."""
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    'backup', str(Path(__file__).resolve().parents[1] /
                  'deployment/create_all_apps_backup_root.py'))
backup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(backup)


class BackupTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def archive(self, omit=None, sql=None):
        payload = {
            'homedir/public_html/artisan': b'<?php test',
            'homedir/public_html/.env': b'PRIVATE_TEST_CONFIGURATION',
            'homedir/public_html/vendor/autoload.php': b'<?php autoload',
            'mysql/fasakha_db.sql': sql if sql is not None else
                b'-- MariaDB dump\nCREATE TABLE test (id INT);\n' + b'-- footer\n' * 25,
            'mysql/fasakha_other.sql':
                b'-- MariaDB dump\nCREATE DATABASE fasakha_other;\n' + b'-- footer\n' * 25,
        }
        path = self.root / 'cpmove-fasakha.tar.gz'
        with tarfile.open(str(path), 'w:gz') as archive:
            for name, content in payload.items():
                if name == omit:
                    continue
                item = tarfile.TarInfo('cpmove-fasakha/' + name)
                item.size = len(content)
                archive.addfile(item, io.BytesIO(content))
        return path

    def test_all_databases_and_required_live_files(self):
        report = backup.check_account_archive(
            self.archive(), ['fasakha_db', 'fasakha_other'])
        self.assertEqual(set(report['database_dump_bytes']),
                         {'fasakha_db', 'fasakha_other'})
        self.assertEqual(len(report['required_live_files']), 3)
        self.assertFalse((self.root / '.env').exists())
        self.assertNotIn('PRIVATE_TEST', json.dumps(report))

    def test_missing_environment_prevents_success(self):
        with self.assertRaises(backup.BackupError):
            backup.check_account_archive(
                self.archive(omit='homedir/public_html/.env'), ['fasakha_db'])

    def test_missing_secondary_database_prevents_success(self):
        with self.assertRaises(backup.BackupError):
            backup.check_account_archive(
                self.archive(omit='mysql/fasakha_other.sql'),
                ['fasakha_db', 'fasakha_other'])

    def test_empty_or_invalid_dump_prevents_success(self):
        for data in (b'', b'not SQL\n' * 30):
            with self.assertRaises(backup.BackupError):
                backup.check_account_archive(self.archive(sql=data), ['fasakha_db'])

    def test_corrupt_gzip_trailer_prevents_success(self):
        path = self.archive()
        raw = bytearray(path.read_bytes())
        raw[-8] ^= 0x01
        path.write_bytes(raw)
        with self.assertRaises((OSError, EOFError, tarfile.TarError)):
            backup.check_account_archive(path, ['fasakha_db'])

    def test_uapi_envelopes_and_failed_inventory(self):
        result = {'status': 1, 'data': [{'database': 'fasakha_db'}]}
        self.assertEqual(backup.database_names({'result': result}), ['fasakha_db'])
        self.assertEqual(backup.database_names({'cpanelresult': {'result': result}}),
                         ['fasakha_db'])
        for payload in ({'result': {'status': 0}},
                        {'result': {'status': 1, 'data': []}}):
            with self.assertRaises(backup.BackupError):
                backup.database_names(payload)

    def test_receipt_only_after_checksum_verification(self):
        (self.root / 'source.txt').write_text('backup payload')
        backup.seal(self.root, {'restore_rehearsal': False})
        self.assertEqual((self.root / 'backup.ok').read_text(),
                         'ALL_APPS_BACKUP_VERIFIED\n')
        self.assertIn('source.txt', (self.root / 'SHA256SUMS').read_text())

    def test_checksum_failure_leaves_no_completion_marker(self):
        (self.root / 'source.txt').write_text('backup payload')
        with patch.object(backup, 'sha256', return_value='0' * 64):
            with self.assertRaises(backup.BackupError):
                backup.seal(self.root, {})
        self.assertFalse((self.root / 'backup.ok').exists())

    def test_no_private_home_paths_requires_no_extra_archive(self):
        with patch.object(backup, 'run', return_value='[]'):
            self.assertEqual(backup.supplement_private_home(self.root), 0)
        self.assertEqual(json.loads((self.root / 'private-home-paths.json').read_text()), [])

    def test_private_home_scanner_rejects_unrelated_paths(self):
        with patch.object(backup, 'run', return_value='["/root/unrelated"]'):
            with self.assertRaises(backup.BackupError):
                backup.supplement_private_home(self.root)


if __name__ == '__main__':
    unittest.main()
