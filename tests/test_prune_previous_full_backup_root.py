"""Exercise bounded full-backup pruning without a real server or backup data."""
import errno
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import tarfile
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    'previous_full_backup_pruner_test',
    str(ROOT / 'deployment/prune_previous_full_backup_root.py'))
pruner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pruner)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def archive(path, contents):
    with tarfile.open(str(path), 'w:gz') as output:
        for name, payload in sorted(contents.items()):
            item = tarfile.TarInfo(name)
            item.mode, item.uid, item.gid = 0o600, 0, 0
            item.size = len(payload)
            output.addfile(item, io.BytesIO(payload))
    path.chmod(0o600)


@unittest.skipUnless(os.geteuid() == 0, 'ownership checks require root')
class PruneTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='bounded-full-prune-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.root.chmod(0o700)
        self.base = self.root / 'full-backups'
        self.base.mkdir(mode=0o700)
        self.latest = self.base / pruner.LATEST_NAME
        self.latest.mkdir(mode=0o700)
        self.old = self.base / pruner.OLD_NAME
        self.old.mkdir(mode=0o700)
        self.write(self.old / 'home-current.tar.gz', b'old full backup archive\n')
        nested = self.old / 'account'
        nested.mkdir(mode=0o700)
        self.write(nested / 'archive.bin', b'old account bytes\n')
        self.other = self.base / '20261024T000000Z-partial-keep'
        self.other.mkdir(mode=0o700)
        self.write(self.other / 'partial.bin', b'keep unrelated partial bytes\n')
        self.runtime = self.root / 'go-erp-runtime'
        self.runtime.mkdir(mode=0o700)
        self.write(self.runtime / 'state.json', b'{"runtime":"must survive"}\n')
        self.old_before = self.inventory(self.old)
        self.other_before = self.inventory(self.other)
        self.runtime_before = self.inventory(self.runtime)
        self.make_latest()
        self.latest_before = self.inventory(self.latest)

    def write(self, path, data):
        path.write_bytes(data)
        path.chmod(0o600)

    def write_json(self, path, value):
        self.write(path, (json.dumps(value, sort_keys=True) + '\n').encode())

    def inventory(self, root):
        return {str(path.relative_to(root)): path.read_bytes()
                for path in root.rglob('*') if path.is_file() and not path.is_symlink()}

    def seal(self):
        lines = []
        for path in sorted(self.latest.rglob('*')):
            if path.is_file() and path.name not in ('backup.ok', 'SHA256SUMS'):
                lines.append(digest(path.read_bytes()) + '  ' +
                             str(path.relative_to(self.latest)) + '\n')
        self.write(self.latest / 'SHA256SUMS', ''.join(lines).encode())

    def make_latest(self):
        account = self.latest / 'account'
        account.mkdir(mode=0o700)
        home_files = {'public_html/artisan': b'<?php artisan\n',
                      'public_html/.env': b'DB_DATABASE=fasakha_db\n',
                      'public_html/vendor/autoload.php': b'<?php autoload\n'}
        home_inventory = {name: {'type': 'file', 'size': len(value)}
                          for name, value in home_files.items()}
        self.write_json(self.latest / 'home-inventory.json', home_inventory)
        archive(self.latest / 'home-current.tar.gz', home_files)
        databases = ['fasakha_db', 'fasakha_dev_db']
        sql = {name: (('-- SQL fixture ' + name + '\n') * 10 +
                      '-- Dump completed on 2026-10-25\n').encode()
               for name in databases}
        archive(account / 'cpmove-fasakha.tar.gz',
                {'mysql/' + name + '.sql': value for name, value in sql.items()})
        database_verification = {
            name: {'bytes': len(value), 'sha256': digest(value), 'completion_footer': True}
            for name, value in sql.items()}
        self.write_json(self.latest / 'database-inventory.json', {
            'source': 'cpanel_account_ownership_map', 'account': 'fasakha',
            'map_path': '/var/cpanel/databases/fasakha.json',
            'map_sha256': 'a' * 64, 'databases': databases,
            'primary_application_database': 'fasakha_db'})
        archive(self.latest / 'server-config.tar.gz',
                {'etc/systemd/system/fixture.service': b'[Service]\n'})
        runtime_data = b'{"state":"fixture"}\n'
        runtime_inventory = {'etc/go-erp/state.json': {
            'type': 'file', 'mode': 0o600, 'uid': 0, 'gid': 0,
            'size': len(runtime_data), 'sha256': digest(runtime_data)}}
        self.write_json(self.latest / 'server-runtime-inventory.json', runtime_inventory)
        runtime_archive = self.latest / 'server-runtime.tar.gz'
        archive(runtime_archive, {'etc/go-erp/state.json': runtime_data})
        runtime = {
            'archive': runtime_archive.name, 'inventory': 'server-runtime-inventory.json',
            'roots': ['/etc/go-erp', '/opt/go-erp-live-updates', '/var/lib/go-erp-live-updates'],
            'inventory_entries': len(runtime_inventory),
            'inventory_entries_verified': len(runtime_inventory),
            'regular_files': 1, 'regular_file_bytes_verified': len(runtime_data),
            'archive_bytes': runtime_archive.stat().st_size,
            'sha256': digest(runtime_archive.read_bytes())}
        manifest = {
            'format': 1, 'kind': 'fasakhansta-fresh-full-backup',
            'layout': 'split-home-plus-server-runtime', 'account': 'fasakha',
            'database_verification': database_verification,
            'home_verification': {'inventory_entries_verified': len(home_inventory),
                                  'required_live_files': sorted(home_files)},
            'server_runtime': runtime, 'server_config_paths': ['etc/systemd/system'],
            'point_backups_included': True, 'earlier_backups_deleted': False,
            'online_capture_window': True, 'restore_rehearsal': False,
            'windows_pc_local_data_included': False}
        self.write_json(self.latest / 'manifest.json', manifest)
        self.write(self.latest / 'RESTORE-NOTES.txt', b'restore fixture instructions\n')
        self.seal()
        self.write(self.latest / 'backup.ok',
                   b'FRESH_FULL_BACKUP_VERIFIED\nLAYOUT=SPLIT_HOME_SERVER_RUNTIME\n')

    def run_prune(self, apply=False):
        return pruner.prune(base=self.base, latest_name=pruner.LATEST_NAME,
                            old_name=pruner.OLD_NAME, apply=apply,
                            heartbeat_seconds=0.02)

    def assert_old_intact(self):
        self.assertTrue(self.old.is_dir())
        self.assertEqual(self.inventory(self.old), self.old_before)

    def assert_unrelated_intact(self):
        self.assertEqual(self.inventory(self.other), self.other_before)
        self.assertEqual(self.inventory(self.runtime), self.runtime_before)

    def assert_blocked(self):
        with self.assertRaises(pruner.Stop):
            self.run_prune(apply=True)
        self.assert_old_intact()
        self.assert_unrelated_intact()

    def test_check_verifies_but_preserves_every_backup(self):
        self.run_prune()
        self.assert_old_intact()
        self.assert_unrelated_intact()
        self.assertEqual(self.inventory(self.latest), self.latest_before)

    def test_apply_deletes_only_the_exact_old_full_backup(self):
        summary = self.run_prune(apply=True)
        self.assertFalse(self.old.exists())
        self.assert_unrelated_intact()
        self.assertEqual(self.inventory(self.latest), self.latest_before)
        self.assertIsInstance(summary, dict)

    def test_old_missing_is_an_idempotent_success(self):
        shutil.rmtree(str(self.old))
        self.run_prune(apply=True)
        self.run_prune(apply=True)
        self.assert_unrelated_intact()
        self.assertEqual(self.inventory(self.latest), self.latest_before)

    def test_missing_old_does_not_bypass_latest_backup_validation(self):
        shutil.rmtree(str(self.old))
        self.write(self.latest / 'backup.ok', b'INCOMPLETE\n')
        with self.assertRaises(pruner.Stop):
            self.run_prune(apply=True)
        self.assert_unrelated_intact()

    def test_marker_must_match_exact_complete_layout(self):
        self.write(self.latest / 'backup.ok', b'FRESH_FULL_BACKUP_VERIFIED\n')
        self.assert_blocked()

    def test_missing_required_archive_is_rejected_even_after_resealing(self):
        (self.latest / 'server-runtime.tar.gz').unlink()
        self.seal()
        self.assert_blocked()

    def test_corrupt_account_archive_checksum_preserves_old(self):
        with (self.latest / 'account/cpmove-fasakha.tar.gz').open('ab') as output:
            output.write(b'tampered')
        self.assert_blocked()

    def test_manifest_requires_two_completed_database_dumps(self):
        path = self.latest / 'manifest.json'
        value = json.loads(path.read_text())
        del value['database_verification']['fasakha_dev_db']
        self.write_json(path, value)
        self.seal()
        self.assert_blocked()

    def test_manifest_requires_completed_home_coverage(self):
        path = self.latest / 'manifest.json'
        value = json.loads(path.read_text())
        value['home_verification']['required_live_files'].remove('public_html/.env')
        self.write_json(path, value)
        self.seal()
        self.assert_blocked()

    def test_database_inventory_must_match_verified_manifest(self):
        path = self.latest / 'database-inventory.json'
        value = json.loads(path.read_text())
        value['databases'] = ['fasakha_db', 'unrelated_database']
        self.write_json(path, value)
        self.seal()
        self.assert_blocked()

    def test_checksum_paths_cannot_escape_latest_directory(self):
        for unsafe in ('../outside', '/etc/passwd', 'account/../../outside', './manifest.json'):
            with self.subTest(path=unsafe):
                self.seal()
                with (self.latest / 'SHA256SUMS').open('ab') as output:
                    output.write(('0' * 64 + '  ' + unsafe + '\n').encode())
                self.assert_blocked()

    def test_duplicate_checksum_entries_are_rejected(self):
        path = self.latest / 'SHA256SUMS'
        with path.open('ab') as output:
            output.write(path.read_bytes().splitlines(True)[0])
        self.assert_blocked()

    def test_unlisted_regular_file_prevents_pruning(self):
        self.write(self.latest / 'unexpected-unsealed.bin', b'extra')
        self.assert_blocked()

    def test_latest_symlink_artifact_cannot_borrow_an_external_file(self):
        artifact = self.latest / 'home-current.tar.gz'
        original = artifact.read_bytes()
        external = self.root / 'external.tar.gz'
        self.write(external, original)
        artifact.unlink()
        artifact.symlink_to(external)
        self.assert_blocked()
        self.assertEqual(external.read_bytes(), original)

    def test_old_symlink_is_rejected_without_following_its_target(self):
        link = self.old / 'linked-runtime'
        link.symlink_to(self.runtime, target_is_directory=True)
        self.assert_blocked()
        self.assertTrue(link.is_symlink())

    def test_hardlinked_old_file_is_rejected(self):
        outside = self.root / 'outside-old-copy.bin'
        os.link(str(self.old / 'home-current.tar.gz'), str(outside))
        self.assert_blocked()
        self.assertEqual(outside.read_bytes(), self.old_before['home-current.tar.gz'])

    def test_group_writable_old_backup_is_rejected(self):
        (self.old / 'home-current.tar.gz').chmod(0o660)
        self.assert_blocked()

    def test_nonroot_owned_old_file_is_rejected(self):
        try:
            os.chown(str(self.old / 'home-current.tar.gz'), 65534, 65534)
        except OSError as error:
            if error.errno in (errno.EINVAL, errno.EPERM):
                self.skipTest('host user namespace cannot assign the fixture UID')
            raise
        self.assert_blocked()

    def test_group_accessible_backup_base_is_rejected(self):
        self.base.chmod(0o750)
        self.assert_blocked()

    def test_newer_dated_backup_aborts_cleanup(self):
        (self.base / '20261026T000000Z-fresh-full-newer').mkdir(mode=0o700)
        self.assert_blocked()

    def test_same_filesystem_bind_mount_inside_old_backup_is_rejected(self):
        mount = ('55 36 0:1 / %s rw,nosuid - tmpfs tmpfs rw\n' %
                 (self.old / 'account')).encode()
        # A same-device bind mount would evade an st_dev-only check. Exercise
        # the real mountinfo parser while leaving all file operations real.
        with mock.patch('builtins.open', side_effect=lambda *args, **kwargs: io.BytesIO(mount)):
            self.assert_blocked()

    def test_identical_bytes_replaced_after_verification_prevent_deletion(self):
        with pruner.locked_backup(base=self.base, latest_name=pruner.LATEST_NAME,
                                  heartbeat_seconds=0.02) as verified:
            path = self.latest / 'home-current.tar.gz'
            original = path.read_bytes()
            replacement = self.latest / 'same-content-new-inode.tmp'
            self.write(replacement, original)
            os.replace(str(replacement), str(path))
            self.assertEqual(path.read_bytes(), original)
            with self.assertRaises(pruner.Stop):
                verified.prune_old(apply=True)
        self.assert_old_intact()
        self.assert_unrelated_intact()

    def test_backup_lock_contention_preserves_old(self):
        import fcntl
        path = self.base / '.fresh-full-backup.lock'
        with path.open('wb') as output:
            path.chmod(0o600)
            fcntl.flock(output.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises((BlockingIOError, pruner.Stop)):
                self.run_prune(apply=True)
        self.assert_old_intact()
        self.assert_unrelated_intact()


if __name__ == '__main__':
    unittest.main()
