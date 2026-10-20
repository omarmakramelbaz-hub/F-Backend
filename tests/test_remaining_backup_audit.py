"""Exercise false-negative guards and real checksum/tar reads without server access."""
import contextlib
import errno
import hashlib
import io
import json
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deployment'))
import audit_remaining_backup_candidates_root as audit


class RemainingBackupAuditTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.backup = self.root / 'complete'
        self.backup.mkdir()
        (self.backup / 'backup.ok').write_text('ALL_APPS_BACKUP_VERIFIED\nLAYOUT=SPLIT_HOME\n')
        databases = {name: {'bytes': 1024, 'sha256': '0' * 64, 'completion_footer': True}
                     for name in ('fasakha_db', 'fasakha_dev_db')}
        (self.backup / 'manifest.json').write_text(json.dumps(
            {'layout': 'split-home', 'account': 'fasakha', 'database_verification': databases}))
        (self.backup / 'home-current.tar.gz').write_bytes(b'private original data')
        (self.backup / 'account').mkdir()
        (self.backup / 'account' / 'cpmove-fasakha.tar.gz').write_bytes(b'account and databases')
        self.seal()
        self.backup_patch = patch.object(audit, 'BACKUP', str(self.backup))
        self.backup_patch.start()
        self.old = self.root / 'temp_extract'
        self.old.mkdir()
        self.live = self.root / 'live'
        self.live.mkdir()

    def tearDown(self):
        self.backup_patch.stop()
        self.temporary.cleanup()

    def seal(self):
        names = ('manifest.json', 'home-current.tar.gz', 'account/cpmove-fasakha.tar.gz')
        lines = [hashlib.sha256((self.backup / name).read_bytes()).hexdigest() + '  ' + name + '\n'
                 for name in names]
        (self.backup / 'SHA256SUMS').write_text(''.join(lines))

    def test_real_checksum_readback_detects_changed_complete_backup(self):
        self.assertEqual(audit.verify_backup()['layout'], 'split-home')
        (self.backup / 'home-current.tar.gz').write_bytes(b'changed')
        with self.assertRaises(audit.AuditError):
            audit.verify_backup()

    def test_backup_marker_and_layout_required(self):
        (self.backup / 'backup.ok').write_text('LAYOUT=SPLIT_HOME\n')
        with self.assertRaises(audit.AuditError):
            audit.verify_backup()

    def test_missing_database_or_account_package_coverage_rejected(self):
        manifest = json.loads((self.backup / 'manifest.json').read_text())
        del manifest['database_verification']['fasakha_dev_db']
        (self.backup / 'manifest.json').write_text(json.dumps(manifest))
        self.seal()
        with self.assertRaises(audit.AuditError):
            audit.verify_backup()

    def test_symlinked_payload_and_escaping_checksum_path_rejected(self):
        target = self.root / 'elsewhere'
        target.write_bytes(b'private original data')
        (self.backup / 'home-current.tar.gz').unlink()
        (self.backup / 'home-current.tar.gz').symlink_to(target)
        with self.assertRaises(audit.AuditError):
            audit.verify_backup()
        (self.backup / 'SHA256SUMS').write_text('0' * 64 + '  ../elsewhere\n')
        with self.assertRaises(audit.AuditError):
            audit.verify_backup()

    def test_exact_candidates_rejects_symlinks_and_retains_other_paths(self):
        with patch.object(audit, 'CANDIDATES', (str(self.old),)):
            self.assertEqual(audit.candidates(), [str(self.old)])
            self.old.rmdir()
            self.old.symlink_to(self.live, target_is_directory=True)
            with self.assertRaises(audit.AuditError):
                audit.candidates()
        self.assertTrue(self.live.is_dir())

    def test_relative_live_symlink_is_dependency(self):
        (self.live / 'uploads').symlink_to('../temp_extract')
        with patch.object(audit, 'RETAINED', (str(self.live),)):
            self.assertEqual(audit.scan_symlinks([str(self.old)]),
                             ['retained-symlink:' + str(self.live / 'uploads')])

    def test_metadata_inspection_does_not_extract_archive_or_print_contents(self):
        archive = self.root / 'cpmove-fasakha.tar.gz'
        content = b'SECRET_TOKEN=never-show-this'
        with tarfile.open(str(archive), 'w:gz') as stream:
            item = tarfile.TarInfo('cpmove-fasakha/homedir/.env')
            item.size = len(content)
            stream.addfile(item, io.BytesIO(content))
        record = audit.inspect_candidate(str(archive))
        self.assertEqual(record['archive_root'], 'cpmove-fasakha')
        self.assertNotIn('SECRET_TOKEN', json.dumps(record))
        self.assertFalse((self.root / 'cpmove-fasakha').exists())

    def test_mount_source_root_and_escaped_path_are_dependencies(self):
        proc = self.root / 'proc'
        (proc / 'self').mkdir(parents=True)
        (proc / 'self' / 'mountinfo').write_text('12 1 8:1 ' + str(self.old) +
                                               ' /jail rw - ext4 /dev/sda4 rw\n')
        with patch.object(audit, 'PROC', str(proc)):
            self.assertEqual(audit.scan_mounts([str(self.old)]), ['mount:' + str(self.old)])

    def test_streaming_config_scan_handles_chunk_boundaries(self):
        config = self.root / 'application.conf'
        config.write_bytes(b'x' * (1024 * 1024 - 4) + os.fsencode(str(self.old)) + b'/uploads')
        self.assertTrue(audit.file_references(str(config), [str(self.old)]))

    def test_unreadable_config_and_retained_walk_fail_closed(self):
        with patch('builtins.open', side_effect=PermissionError(errno.EACCES, 'hidden secret')):
            with self.assertRaises(audit.AuditError):
                audit.file_references(str(self.root / 'settings'), [str(self.old)])
        with patch.object(audit, 'scan_mounts', return_value=[]), \
             patch.object(audit, 'scan_symlinks', side_effect=PermissionError(errno.EACCES, 'secret')):
            with self.assertRaises(audit.AuditError):
                audit.dependency_check([str(self.old)])

    def test_dangling_configuration_link_is_not_silently_skipped(self):
        config = self.root / 'config'
        config.mkdir()
        (config / 'missing.conf').symlink_to(self.root / 'missing')
        with patch.object(audit, 'CONFIGS', (str(config),)):
            with self.assertRaises(audit.AuditError):
                audit.scan_configs([str(self.old)])

    def test_effective_systemd_dependency_prints_no_environment_secret(self):
        outputs = [b'worker.service loaded active running Worker\n',
                   b'Id=worker.service\nEnvironment=PASSWORD=secret CONFIG=' + os.fsencode(str(self.old))]
        with patch.object(audit, 'CONFIGS', ()), patch.object(audit, 'run', side_effect=outputs):
            capture = io.StringIO()
            with contextlib.redirect_stdout(capture):
                self.assertEqual(audit.scan_configs([str(self.old)]), ['systemd:worker.service'])
            self.assertNotIn('secret', capture.getvalue())

    def make_proc(self):
        proc = self.root / 'proc'
        process = proc / '123'
        (process / 'fd').mkdir(parents=True)
        (process / 'cwd').symlink_to(self.live)
        (process / 'exe').symlink_to('/usr/bin/python3')
        (process / 'maps').write_bytes(b'')
        (process / 'cmdline').write_bytes(b'python3\0worker.py\0')
        return proc, process

    def test_process_fd_maps_and_cpanel_backup_dependencies(self):
        proc, process = self.make_proc()
        (process / 'fd' / '3').symlink_to(self.old / 'upload')
        (process / 'maps').write_bytes(os.fsencode(str(self.old / 'library.so')))
        (process / 'cmdline').write_bytes(b'/usr/bin/perl\0/usr/local/cpanel/scripts/pkgacct\0fasakha\0')
        with patch.object(audit, 'PROC', str(proc)):
            found = audit.scan_processes([str(self.old)])
        self.assertIn('process-fd:' + str(process), found)
        self.assertIn('process-maps:' + str(process), found)
        self.assertIn('cpanel-backup-process:' + str(process), found)

    def test_vanishing_fd_does_not_skip_live_process_maps(self):
        proc, process = self.make_proc()
        (process / 'maps').write_bytes(os.fsencode(str(self.old / 'library.so')))
        original = audit.proc_value
        def value(path, link=False):
            if path.endswith('/fd/9'):
                return None
            return original(path, link=link)
        (process / 'fd' / '9').symlink_to('/gone')
        with patch.object(audit, 'PROC', str(proc)), patch.object(audit, 'proc_value', side_effect=value):
            self.assertIn('process-maps:' + str(process), audit.scan_processes([str(self.old)]))

    def test_process_permission_failure_is_not_ignored_as_exit(self):
        with patch('os.readlink', side_effect=PermissionError(errno.EACCES, 'hidden secret')):
            with self.assertRaises(audit.AuditError):
                audit.proc_value('/proc/123/fd/1', link=True)

    def test_relative_process_argument_is_dependency(self):
        proc, process = self.make_proc()
        (process / 'cmdline').write_bytes(b'python3\0../temp_extract/worker.py\0')
        with patch.object(audit, 'PROC', str(proc)):
            self.assertIn('process-argv-path:' + str(process), audit.scan_processes([str(self.old)]))


if __name__ == '__main__':
    unittest.main()
