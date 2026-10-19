"""Real streaming archive checks and destructive-operation gates, in temporary dirs."""
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1] / 'deployment'
sys.path.insert(0, str(SCRIPTS))
import create_current_backup_and_prune_root as backup


class CurrentBackupTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.home = self.root / 'home'
        self.home.mkdir()
        self.base = self.root / 'private-backups'
        self.base.mkdir()
        self.destination = self.base / 'new-current'
        self.destination.mkdir()
        (self.destination / 'logs').mkdir()
        self.home_patch = patch.object(backup, 'HOME', self.home)
        self.base_patch = patch.object(backup, 'BASE', self.base)
        self.home_patch.start()
        self.base_patch.start()
        for name in ('public_html/artisan', 'public_html/.env',
                     'public_html/vendor/autoload.php', 'public_html/storage/uploads/photo.jpg',
                     'desktop-source-evidence-example/source.zip'):
            path = self.home / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'CURRENT DATA ' + name.encode())
        for name in backup.OLD_NAMES:
            path = self.home / name
            path.mkdir()
            (path / 'historical-copy.bin').write_bytes(b'OLD' * 1000)

    def tearDown(self):
        self.home_patch.stop()
        self.base_patch.stop()
        self.tmp.cleanup()

    def sql_archive(self, complete=True, secondary=True):
        path = self.destination / 'cpanel-test.tar.gz'
        names = ['fasakha_db', 'fasakha_dev_db'] if secondary else ['fasakha_db']
        with tarfile.open(str(path), 'w:gz') as archive:
            for name in names:
                content = b'-- MariaDB dump\nCREATE TABLE t(id INT);\n' + b'INSERT INTO t VALUES(1);\n' * 10
                if complete:
                    content += b'-- Dump completed on 2026-10-20 00:00:00\n'
                item = tarfile.TarInfo('cpmove-fasakha/mysql/' + name + '.sql')
                item.size = len(content)
                archive.addfile(item, io.BytesIO(content))
        return path

    def seal(self, planned):
        (self.destination / 'home-current.tar.gz').write_bytes(b'test payload')
        backup.seal_current(self.destination, {'layout': 'split-home',
                                              'excluded_old_trees': planned})

    def test_root_stream_preserves_current_private_upload_and_desktop_files(self):
        candidates = backup.candidate_paths()
        runtime = self.home / backup.RUNTIME_DIRS[0]
        runtime.mkdir(parents=True)
        (runtime / 'growing.log').write_bytes(b'REGENERABLE')
        inventory = backup.home_inventory(candidates)
        archive, warnings = backup.stream_home(self.destination, candidates)
        report = backup.verify_home(archive, inventory, candidates)
        self.assertFalse(warnings)
        self.assertEqual(report['inventory_entries_verified'], len(inventory))
        with tarfile.open(str(archive), 'r:gz') as stream:
            names = stream.getnames()
            self.assertIn('./public_html/.env', names)
            self.assertIn('./public_html/storage/uploads/photo.jpg', names)
            self.assertIn('./desktop-source-evidence-example/source.zip', names)
            self.assertNotIn('./public_html/storage/logs/growing.log', names)
            self.assertFalse(any('historical-copy.bin' in name for name in names))

    def test_live_symlink_into_old_tree_blocks_backup_and_prune(self):
        (self.home / 'public_html' / 'old-uploads').symlink_to(
            self.home / backup.OLD_NAMES[0], target_is_directory=True)
        with self.assertRaises(backup.core.BackupError):
            backup.home_inventory(backup.candidate_paths())
        self.assertTrue((self.home / backup.OLD_NAMES[0]).is_dir())

    def test_candidate_symlink_is_never_eligible_for_deletion(self):
        path = self.home / backup.OLD_NAMES[0]
        import shutil
        shutil.rmtree(str(path))
        path.symlink_to(self.home / 'public_html', target_is_directory=True)
        with self.assertRaises(backup.core.BackupError):
            backup.candidate_paths()
        self.assertTrue((self.home / 'public_html' / 'artisan').is_file())

    def test_size_excludes_exact_old_trees_but_keeps_current_private_data(self):
        (self.home / backup.OLD_NAMES[0] / 'large.bin').write_bytes(b'x' * 1024 * 1024)
        size = backup.current_size(backup.candidate_paths())
        self.assertLess(size, 1024 * 1024)
        self.assertGreater(size, (self.home / 'public_html' / '.env').stat().st_size)

    def test_database_package_checks_every_database_completion_footer(self):
        result = backup.verify_sql_package(self.sql_archive(), ['fasakha_db', 'fasakha_dev_db'])
        self.assertEqual(set(result), {'fasakha_db', 'fasakha_dev_db'})
        self.assertTrue(all(item['completion_footer'] for item in result.values()))

    def test_header_only_or_interrupted_sql_dump_blocks_success(self):
        with self.assertRaises(backup.core.BackupError):
            backup.verify_sql_package(self.sql_archive(complete=False), ['fasakha_db'])

    def test_missing_database_blocks_success(self):
        with self.assertRaises(backup.core.BackupError):
            backup.verify_sql_package(self.sql_archive(secondary=False),
                                      ['fasakha_db', 'fasakha_dev_db'])

    def test_no_new_verified_backup_means_no_old_deletion(self):
        planned = [backup.fingerprint(p) for p in backup.candidate_paths()]
        with self.assertRaises(backup.core.BackupError):
            backup.prune_old(self.destination, planned)
        self.assertTrue(all((self.home / name).is_dir() for name in backup.OLD_NAMES))

    def test_corrupted_new_backup_blocks_old_deletion(self):
        planned = [backup.fingerprint(p) for p in backup.candidate_paths()]
        self.seal(planned)
        (self.destination / 'home-current.tar.gz').write_bytes(b'CORRUPTED')
        with self.assertRaises(backup.core.BackupError):
            backup.prune_old(self.destination, planned)
        self.assertTrue(all((self.home / name).is_dir() for name in backup.OLD_NAMES))

    def test_old_tree_modified_during_backup_blocks_deletion(self):
        planned = [backup.fingerprint(p) for p in backup.candidate_paths()]
        self.seal(planned)
        (self.home / backup.OLD_NAMES[0] / 'new-file').write_text('arrived later')
        with patch.object(backup, 'dependency_check', return_value={}):
            with self.assertRaises(backup.core.BackupError):
                backup.prune_old(self.destination, planned)
        self.assertTrue(all((self.home / name).is_dir() for name in backup.OLD_NAMES))

    def test_exact_three_old_directories_removed_only_after_verified_new_backup(self):
        unrelated = self.home / 'another-current-folder'
        unrelated.mkdir()
        (unrelated / 'keep.txt').write_text('keep')
        planned = [backup.fingerprint(p) for p in backup.candidate_paths()]
        self.seal(planned)
        with patch.object(backup, 'dependency_check', return_value={}):
            backup.prune_old(self.destination, planned)
        self.assertTrue(all(not (self.home / name).exists() for name in backup.OLD_NAMES))
        self.assertTrue((self.home / 'public_html' / 'artisan').is_file())
        self.assertTrue((unrelated / 'keep.txt').is_file())
        self.assertTrue((self.destination / 'backup.ok').is_file())
        report = json.loads((self.destination / 'prune-result.json').read_text())
        self.assertEqual(len(report['removed_paths']), 3)


if __name__ == '__main__':
    unittest.main()
