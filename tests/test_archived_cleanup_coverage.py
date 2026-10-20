import io
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deployment'))
import verify_archived_cleanup_copies as coverage


class ArchivedCleanupCoverageTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.base = Path(self.temporary.name)
        self.home = self.base / 'home'
        self.old = self.home / 'component-backups'
        self.old.mkdir(parents=True)
        self.data = self.old / 'data.bin'
        self.data.write_bytes(b'original private bytes')
        self.archive = self.base / 'home-current.tar.gz'

    def tearDown(self):
        self.temporary.cleanup()

    def create_archive(self):
        with tarfile.open(str(self.archive), 'w:gz') as archive:
            archive.add(str(self.home), arcname='.')

    def verify(self):
        return coverage.verify(self.home, self.archive, [self.old])

    def add_file(self, archive, name, content):
        member = tarfile.TarInfo(name)
        member.size = len(content)
        archive.addfile(member, io.BytesIO(content))

    def add_root(self, archive):
        member = tarfile.TarInfo('./component-backups')
        member.type = tarfile.DIRTYPE
        archive.addfile(member)

    def test_actual_streamed_archive_matches_files_and_symlinks(self):
        (self.old / 'pointer').symlink_to('data.bin')
        (self.old / 'empty-directory').mkdir()
        self.create_archive()
        self.assertEqual(self.verify()[str(self.old)],
                         {'verified': True, 'files': 1, 'sha256_verified': 1})
        self.assertTrue(self.data.exists())

    def test_direct_account_archive_and_directory_share_one_stream(self):
        old_archive = self.home / 'backup-2025.tar.gz'
        old_archive.write_bytes(b'old account archive bytes')
        self.create_archive()
        original = coverage.gzip.open
        with patch.object(coverage.gzip, 'open', wraps=original) as opened:
            reports = coverage.verify(self.home, self.archive, [self.old, old_archive])
        self.assertEqual(opened.call_count, 1)
        self.assertEqual(reports[str(old_archive)], {'verified': True, 'files': 1, 'sha256_verified': 1})

    def test_same_length_different_contents_are_rejected(self):
        self.create_archive()
        self.data.write_bytes(b'x' * len(self.data.read_bytes()))
        with self.assertRaises(coverage.CoverageError):
            self.verify()

    def test_different_size_is_rejected(self):
        self.create_archive()
        self.data.write_bytes(b'short')
        with self.assertRaises(coverage.CoverageError):
            self.verify()

    def test_missing_regular_file_and_missing_directory_are_rejected(self):
        with tarfile.open(str(self.archive), 'w:gz') as archive:
            self.add_root(archive)
        with self.assertRaises(coverage.CoverageError):
            self.verify()
        with tarfile.open(str(self.archive), 'w:gz') as archive:
            self.add_file(archive, './component-backups/data.bin', self.data.read_bytes())
        with self.assertRaises(coverage.CoverageError):
            self.verify()

    def test_symlink_target_mismatch_is_rejected(self):
        (self.old / 'pointer').symlink_to('data.bin')
        self.create_archive()
        (self.old / 'pointer').unlink()
        (self.old / 'pointer').symlink_to('../different')
        with self.assertRaises(coverage.CoverageError):
            self.verify()

    def test_unrelated_archive_path_traversal_is_rejected(self):
        with tarfile.open(str(self.archive), 'w:gz') as archive:
            self.add_file(archive, '../escape', b'never extract')
        with self.assertRaises(coverage.CoverageError):
            self.verify()
        self.assertFalse((self.base / 'escape').exists())

    def test_duplicate_relevant_member_is_rejected(self):
        with tarfile.open(str(self.archive), 'w:gz') as archive:
            self.add_root(archive)
            for unused in range(2):
                self.add_file(archive, './component-backups/data.bin', self.data.read_bytes())
        with self.assertRaises(coverage.CoverageError):
            self.verify()

    def test_backward_tar_hardlink_is_verified_by_target_content(self):
        os.link(str(self.data), str(self.old / 'hardlink'))
        self.create_archive()
        self.assertEqual(self.verify()[str(self.old)]['sha256_verified'], 2)

    def test_hardlink_to_preceding_member_outside_candidates_is_verified(self):
        target = self.home / 'A-first'
        os.link(str(self.data), str(target))
        self.create_archive()
        self.assertEqual(self.verify()[str(self.old)]['files'], 1)

    def test_forward_tar_hardlink_is_rejected(self):
        os.link(str(self.data), str(self.old / 'hardlink'))
        with tarfile.open(str(self.archive), 'w:gz') as archive:
            self.add_root(archive)
            link = tarfile.TarInfo('./component-backups/hardlink')
            link.type = tarfile.LNKTYPE
            link.linkname = './component-backups/data.bin'
            archive.addfile(link)
            self.add_file(archive, './component-backups/data.bin', self.data.read_bytes())
        with self.assertRaises(coverage.CoverageError):
            self.verify()

    def test_candidate_outside_home_and_missing_candidate_are_rejected(self):
        self.create_archive()
        with self.assertRaises(coverage.CoverageError):
            coverage.verify(self.home, self.archive, [self.base])
        with self.assertRaisesRegex(coverage.CoverageError, 'Missing cleanup candidate'):
            coverage.verify(self.home, self.archive, [self.home / 'missing'])

    def test_live_change_during_hashing_is_rejected(self):
        self.create_archive()
        original = coverage.digest_stream
        def changed(stream):
            result = original(stream)
            self.data.write_bytes(b'changed during hashing')
            return result
        with patch.object(coverage, 'digest_stream', side_effect=changed):
            with self.assertRaises(coverage.CoverageError):
                self.verify()

    def test_live_change_after_hashing_is_rejected(self):
        self.create_archive()
        original = coverage.archive_name
        changed = []
        def archive_name(value):
            if not changed:
                changed.append(True)
                self.data.write_bytes(b'changed while archive streams')
            return original(value)
        with patch.object(coverage, 'archive_name', side_effect=archive_name):
            with self.assertRaisesRegex(coverage.CoverageError, 'changed during archive'):
                self.verify()

    def test_no_follow_file_hash_rejects_symlink_swap(self):
        expected = coverage.signature(self.data.lstat())
        target = self.home / 'other'
        target.write_bytes(self.data.read_bytes())
        self.data.unlink()
        self.data.symlink_to(target)
        with self.assertRaises(coverage.CoverageError):
            coverage.hash_live(str(self.data), expected)

    def test_truncated_gzip_trailer_is_rejected(self):
        self.create_archive()
        self.archive.write_bytes(self.archive.read_bytes()[:-4])
        with self.assertRaises(coverage.CoverageError):
            self.verify()


if __name__ == '__main__':
    unittest.main()
