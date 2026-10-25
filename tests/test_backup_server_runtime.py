"""Verify full runtime coverage, link handling and archive integrity."""
import copy
import gzip
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import tarfile
import tempfile
import types
import unittest
from unittest import mock

MODULE = Path(__file__).resolve().parents[1] / 'deployment/full_backup_20261025/backup_server_runtime.py'
spec = importlib.util.spec_from_file_location('server_runtime_backup_test', MODULE)
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


def check_gzip(path):
    with gzip.open(str(path), 'rb') as stream:
        while stream.read(65536):
            pass


CORE = types.SimpleNamespace(check_gzip=check_gzip,
                             sha256=lambda path: hashlib.sha256(path.read_bytes()).hexdigest())


@unittest.skipUnless(os.geteuid() == 0, 'private output ownership checks require root')
class RuntimeBackupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='runtime-backup-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / 'source'
        self.destination = self.root / 'backup'
        self.source.mkdir(mode=0o700)
        self.destination.mkdir(mode=0o700)
        (self.destination / 'logs').mkdir(mode=0o700)
        self.file = self.source / 'private.json'
        self.file.write_bytes(b'{"key":"synthetic-secret"}\n')
        self.file.chmod(0o640)
        (self.source / 'empty').touch(mode=0o600)
        (self.source / 'empty-directory').mkdir(mode=0o750)

    def inventory(self):
        return runtime.inventory([self.source])

    def archive(self):
        return runtime.stream_archive(self.destination, [self.source])

    def test_complete_backup_preserves_empty_files_permissions_and_internal_links(self):
        outside = self.root / 'not-in-runtime'
        outside.write_bytes(b'outside-content-must-not-be-dereferenced')
        (self.source / 'absolute-link').symlink_to(outside)
        (self.source / 'dangling-link').symlink_to('/nonexistent/synthetic-runtime-target')
        os.link(self.file, self.source / 'hard-link')
        with mock.patch.object(runtime, 'discover_roots', return_value=[self.source]):
            report = runtime.backup(self.destination, CORE)
        self.assertEqual(report['inventory_entries'], 7)
        self.assertEqual(report['regular_files'], 3)
        archive = self.destination / report['archive']
        check_gzip(archive)
        with tarfile.open(str(archive), 'r:gz') as data:
            members = {member.name.rstrip('/'): member for member in data}
        name = str(self.source).lstrip('/')
        self.assertTrue(members[name + '/absolute-link'].issym())
        self.assertTrue(members[name + '/dangling-link'].issym())
        self.assertEqual(members[name + '/absolute-link'].linkname, str(outside))
        self.assertNotIn(str(outside).lstrip('/'), members)
        self.assertTrue(members[name + '/hard-link'].isfile())
        self.assertFalse(members[name + '/hard-link'].islnk())
        self.assertEqual(members[name + '/private.json'].mode, 0o640)
        self.assertEqual(members[name + '/empty'].size, 0)
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.destination / 'server-runtime-inventory.json').stat().st_mode & 0o777, 0o600)

    def test_selected_symlink_root_is_rejected(self):
        link = self.root / 'linked-root'
        link.symlink_to(self.source, target_is_directory=True)
        with mock.patch.object(runtime, 'FIXED_ROOTS', (link,)), \
                mock.patch.object(runtime, 'GLOB_ROOTS', ()):
            with self.assertRaisesRegex(runtime.RuntimeBackupError, 'linked or special'):
                runtime.discover_roots()

    def test_selected_symlink_ancestor_is_rejected(self):
        link = self.root / 'linked-parent'
        link.symlink_to(self.source, target_is_directory=True)
        selected = link / 'empty-directory'
        with mock.patch.object(runtime, 'FIXED_ROOTS', (selected,)), \
                mock.patch.object(runtime, 'GLOB_ROOTS', ()):
            with self.assertRaisesRegex(runtime.RuntimeBackupError, 'ancestor'):
                runtime.discover_roots()

    def test_discovery_removes_redundant_nested_roots(self):
        with mock.patch.object(runtime, 'FIXED_ROOTS', (self.source, self.source / 'empty-directory')), \
                mock.patch.object(runtime, 'GLOB_ROOTS', ()):
            self.assertEqual(runtime.discover_roots(), [self.source])

    def test_special_file_is_rejected_without_opening(self):
        os.mkfifo(self.source / 'pipe')
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'special file'):
            self.inventory()

    def test_live_file_change_invalidates_initial_inventory(self):
        entries = self.inventory()
        self.file.write_bytes(b'modified-after-inventory')
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'source changed'):
            runtime.unchanged(entries)

    def test_changed_file_while_hashing_is_rejected(self):
        before = self.file.lstat()
        original_read = os.read
        changed = False
        def read(fd, size):
            nonlocal changed
            data = original_read(fd, size)
            if not changed:
                changed = True
                with self.file.open('ab') as stream:
                    stream.write(b'changed')
            return data
        with mock.patch.object(runtime.os, 'read', side_effect=read):
            with self.assertRaisesRegex(runtime.RuntimeBackupError, 'grew|changed'):
                runtime.stable_file_hash(self.file, before)

    def test_wrong_archive_hash_is_rejected(self):
        entries = self.inventory()
        archive = self.archive()
        entries[str(self.file).lstrip('/')]['sha256'] = '0' * 64
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'content differs'):
            runtime.verify_archive(archive, entries)

    def test_wrong_archive_mode_is_rejected(self):
        entries = self.inventory()
        archive = self.archive()
        entries[str(self.file).lstrip('/')]['mode'] = 0o644
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'permissions differ'):
            runtime.verify_archive(archive, entries)

    def test_extra_archive_entry_is_rejected(self):
        entries = self.inventory()
        archive = self.archive()
        del entries[str(self.file).lstrip('/')]
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'extra or duplicate'):
            runtime.verify_archive(archive, entries)

    def test_missing_archive_entry_is_rejected(self):
        entries = self.inventory()
        archive = self.archive()
        entries[str(self.source / 'missing').lstrip('/')] = copy.deepcopy(entries[str(self.file).lstrip('/')])
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'complete initial inventory'):
            runtime.verify_archive(archive, entries)

    def test_duplicate_archive_entry_is_rejected(self):
        entries = self.inventory()
        name = str(self.file).lstrip('/')
        archive = self.destination / 'duplicate.tar.gz'
        with tarfile.open(str(archive), 'w:gz') as data:
            for _ in range(2):
                member = tarfile.TarInfo(name)
                member.mode = self.file.stat().st_mode & 0o7777
                member.uid, member.gid = self.file.stat().st_uid, self.file.stat().st_gid
                member.size = self.file.stat().st_size
                data.addfile(member, io.BytesIO(self.file.read_bytes()))
        with self.assertRaisesRegex(runtime.RuntimeBackupError, 'extra or duplicate'):
            runtime.verify_archive(archive, entries)

    def test_space_reserve_aborts_without_touching_source(self):
        original = self.file.read_bytes()
        with mock.patch.object(runtime.shutil, 'disk_usage', return_value=types.SimpleNamespace(free=0)):
            with self.assertRaisesRegex(runtime.RuntimeBackupError, 'space reserve'):
                self.archive()
        self.assertEqual(self.file.read_bytes(), original)

    def test_existing_archive_is_not_overwritten(self):
        archive = self.destination / 'server-runtime.tar.gz'
        archive.write_bytes(b'prior-incomplete-artifact')
        with self.assertRaises(FileExistsError):
            self.archive()
        self.assertEqual(archive.read_bytes(), b'prior-incomplete-artifact')


if __name__ == '__main__':
    unittest.main()
