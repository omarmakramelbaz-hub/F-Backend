"""Regression checks for deleting only proven archived account rollback trees."""
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'deployment/clean_archived_point_backups_root.py'


def load_worker():
    spec = importlib.util.spec_from_file_location('point_backup_cleanup_test', MODULE)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@unittest.skipUnless(os.geteuid() == 0 and shutil.which('tar'), 'root ownership and GNU tar required')
class PointBackupCleanupTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.worker = load_worker()

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='point-backup-clean-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.home = self.root / 'home'
        self.backup_base = self.root / 'full-backups'
        self.backup = self.backup_base / 'fresh-full-verified'
        self.home.mkdir(mode=0o700)
        self.backup.mkdir(mode=0o700, parents=True)
        self.backup_base.chmod(0o700)
        self.point = self.home / 'point-backups'
        self.point.mkdir(mode=0o700)
        self.file = self.point / 'old-code.bin'
        self.file.write_bytes(b'synthetic-old-code-v1')
        self.file.chmod(0o600)
        os.utime(self.file, ns=(1700000000123456789, 1700000000123456789))
        (self.point / 'empty').touch(mode=0o600)
        (self.point / 'empty-dir').mkdir(mode=0o750)
        self.unrelated = self.home / 'current-application.dat'
        self.unrelated.write_bytes(b'current-production-data')
        self.split = types.SimpleNamespace(HOME=self.home, dependency_check=mock.Mock(return_value={}))
        self.archive()
        self.allowlist_patch = mock.patch.object(self.worker, 'ALLOWLIST', ('point-backups',))
        self.allowlist_patch.start()
        self.addCleanup(self.allowlist_patch.stop)

    def archive(self):
        subprocess.run(['tar', '--acls', '--xattrs', '--numeric-owner', '-czf',
                        str(self.backup / 'home-current.tar.gz'), '-C', str(self.home), '.'],
                       check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    def run_clean(self, apply=False):
        return self.worker.clean(self.backup, self.split, apply=apply)

    def assert_no_deletion(self):
        self.assertTrue(self.file.is_file())
        self.assertEqual(self.unrelated.read_bytes(), b'current-production-data')
        self.assertTrue((self.backup / 'home-current.tar.gz').is_file())

    def test_check_is_read_only(self):
        self.run_clean()
        self.assert_no_deletion()

    def test_apply_removes_only_known_archived_point_backup(self):
        result = self.run_clean(apply=True)
        self.assertIsInstance(result, dict)
        self.assertFalse(self.point.exists())
        self.assertEqual(self.unrelated.read_bytes(), b'current-production-data')
        self.assertTrue((self.backup / 'home-current.tar.gz').is_file())
        self.assertGreaterEqual(self.split.dependency_check.call_count, 1)

    def test_same_size_modified_content_with_restored_mtime_is_rejected(self):
        before = self.file.stat()
        self.file.write_bytes(b'x' * before.st_size)
        os.utime(self.file, ns=(before.st_atime_ns, before.st_mtime_ns))
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_added_unarchived_file_blocks_removal(self):
        (self.point / 'new-backup.bin').write_bytes(b'not-in-the-full-backup')
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()
        self.assertTrue((self.point / 'new-backup.bin').is_file())

    def test_removed_archived_file_blocks_removal(self):
        (self.point / 'empty').unlink()
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_changed_permission_blocks_removal(self):
        self.file.chmod(0o644)
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_changed_modification_time_blocks_removal(self):
        before = self.file.stat()
        os.utime(self.file, ns=(before.st_atime_ns, before.st_mtime_ns + 10 * 10**9))
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_nanosecond_only_modification_time_change_blocks_removal(self):
        before = self.file.stat()
        os.utime(self.file, ns=(before.st_atime_ns, before.st_mtime_ns + 1))
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_new_extended_metadata_is_preserved_for_review(self):
        try:
            os.setxattr(self.file, 'user.backup-clean-test', b'new-metadata-after-backup')
        except (AttributeError, OSError) as error:
            self.skipTest('extended attributes unavailable: ' + type(error).__name__)
        with self.assertRaisesRegex(self.worker.Stop, 'EXTENDED_METADATA_REQUIRES_REVIEW'):
            self.run_clean(apply=True)
        self.assert_no_deletion()
        self.assertEqual(os.getxattr(self.file, 'user.backup-clean-test'), b'new-metadata-after-backup')

    def test_changed_ownership_blocks_removal(self):
        # Container UID maps may not permit chown; encode a distinct archive owner.
        subprocess.run(['tar', '--acls', '--xattrs', '--numeric-owner', '--owner=65534',
                        '--group=65534', '-czf', str(self.backup / 'home-current.tar.gz'),
                        '-C', str(self.home), '.'], check=True,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_candidate_root_symlink_is_rejected(self):
        target = self.home / 'important-directory'
        self.point.rename(target)
        self.point.symlink_to(target, target_is_directory=True)
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assertTrue(self.point.is_symlink())
        self.assertTrue((target / 'old-code.bin').is_file())
        self.assertTrue(self.unrelated.is_file())

    def test_unlisted_directory_survives(self):
        unlisted = self.home / 'other-backup'
        unlisted.mkdir(mode=0o700)
        (unlisted / 'valuable').write_bytes(b'not-authorized-for-cleanup')
        self.run_clean(apply=True)
        self.assertFalse(self.point.exists())
        self.assertEqual((unlisted / 'valuable').read_bytes(), b'not-authorized-for-cleanup')

    def test_missing_allowlisted_directory_is_an_idempotent_noop(self):
        shutil.rmtree(self.point)
        result = self.run_clean(apply=True)
        self.assertIsInstance(result, dict)
        self.assertTrue(self.unrelated.is_file())
        self.assertTrue((self.backup / 'home-current.tar.gz').is_file())

    def test_live_dependency_failure_preserves_everything(self):
        self.split.dependency_check.side_effect = RuntimeError('live-reference-detected')
        with self.assertRaisesRegex(RuntimeError, 'live-reference-detected'):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_open_process_file_descriptor_blocks_cleanup(self):
        with self.file.open('rb'):
            with self.assertRaisesRegex(self.worker.Stop, 'PROCESS_REFERENCE'):
                self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_updater_configuration_reference_blocks_cleanup(self):
        configs = self.root / 'active-updater-config'
        configs.mkdir(mode=0o700)
        (configs / 'config.json').write_text('{"path":"' + str(self.file) + '"}\n')
        with mock.patch.object(self.worker, 'EXTRA_REFERENCE_DIRS', (configs,)), \
                mock.patch.object(self.worker, 'EXTRA_REFERENCE_FILES', ()):
            with self.assertRaisesRegex(self.worker.Stop, 'LIVE_REFERENCE'):
                self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_git_alternate_into_backup_blocks_cleanup(self):
        gitdir = self.home / '.git'
        info = gitdir / 'objects/info'
        info.mkdir(parents=True)
        (info / 'alternates').write_text(str(self.point) + '\n')
        self.split.dependency_check.return_value = {'.git': {'type': 'directory'}}
        with self.assertRaisesRegex(self.worker.Stop, 'GIT_REFERENCE'):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_git_worktree_reference_into_backup_blocks_cleanup(self):
        gitdir = self.home / '.git'
        worktree = gitdir / 'worktrees/active'
        worktree.mkdir(parents=True)
        (worktree / 'gitdir').write_text(str(self.point / '.git') + '\n')
        self.split.dependency_check.return_value = {'.git': {'type': 'directory'}}
        with self.assertRaisesRegex(self.worker.Stop, 'GIT_REFERENCE|LIVE_REFERENCE'):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_git_directory_file_reference_into_backup_blocks_cleanup(self):
        (self.home / '.git').write_text('gitdir: ' + str(self.point) + '\n')
        self.split.dependency_check.return_value = {'.git': {'type': 'file'}}
        with self.assertRaisesRegex(self.worker.Stop, 'GIT_REFERENCE'):
            self.run_clean(apply=True)
        self.assert_no_deletion()

    def test_archived_internal_symlink_is_unlinked_without_following_target(self):
        target = self.home / 'outside-point-target'
        target.write_bytes(b'preserve-live-target')
        (self.point / 'link').symlink_to(target)
        self.archive()
        self.run_clean(apply=True)
        self.assertFalse(self.point.exists())
        self.assertEqual(target.read_bytes(), b'preserve-live-target')

    def test_changed_internal_symlink_target_is_rejected(self):
        target = self.home / 'outside-point-target'
        target.write_bytes(b'preserve-live-target')
        link = self.point / 'link'
        link.symlink_to(target)
        self.archive()
        link.unlink()
        link.symlink_to('/different-target')
        with self.assertRaises(self.worker.Stop):
            self.run_clean(apply=True)
        self.assert_no_deletion()
        self.assertEqual(target.read_bytes(), b'preserve-live-target')

    def test_change_after_quarantine_preserves_tree_instead_of_deleting(self):
        original = self.worker.assert_snapshot
        changed = False
        def snapshot_check(path, initial):
            nonlocal changed
            path = Path(path)
            if not changed and path.parent.name.startswith('point-backup-prune-'):
                changed = True
                self.assertEqual(path.parent.stat().st_mode & 0o777, 0o700)
                (path / 'old-code.bin').write_bytes(b'new-bytes-arrived-after-quarantine')
            return original(path, initial)
        with mock.patch.object(self.worker, 'assert_snapshot', side_effect=snapshot_check):
            with self.assertRaisesRegex(self.worker.Stop, 'TREE_CHANGED'):
                self.run_clean(apply=True)
        self.assertTrue(changed)
        self.assertEqual(self.file.read_bytes(), b'new-bytes-arrived-after-quarantine')
        self.assertTrue(self.unrelated.is_file())
        self.assertTrue((self.backup / 'home-current.tar.gz').is_file())

    def test_directory_replacement_before_rename_is_not_deleted(self):
        original = os.rename
        retained = self.home / 'original-candidate-retained'
        changed = False
        def rename(source, destination, *args, **kwargs):
            nonlocal changed
            if not changed and Path(source) == self.point:
                changed = True
                original(source, retained)
                shutil.copytree(retained, self.point, copy_function=shutil.copy2)
            return original(source, destination, *args, **kwargs)
        with mock.patch.object(self.worker.os, 'rename', side_effect=rename):
            with self.assertRaisesRegex(self.worker.Stop, 'TREE_IDENTITY_CHANGED'):
                self.run_clean(apply=True)
        self.assertTrue(changed)
        self.assertTrue((retained / 'old-code.bin').is_file())
        self.assertTrue(self.file.is_file())
        self.assertTrue(self.unrelated.is_file())


if __name__ == '__main__':
    unittest.main()
