import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

MODULE = Path(__file__).resolve().parents[1] / 'deployment/stage_go_erp_missing_media.py'
spec = importlib.util.spec_from_file_location('go_erp_media_backup_stage', MODULE)
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class BackupStageTest(unittest.TestCase):
    def test_exact_paths_only_and_missing_are_explicit(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            backup, output = root/'backup', root/'output'
            backup.mkdir(); output.mkdir()
            relative = helper.PATHS[0]
            source = backup/relative
            source.parent.mkdir(parents=True)
            source.write_bytes(b'original-image-bytes')
            other = backup/'different-branch'/Path(relative).name
            other.parent.mkdir(); other.write_bytes(b'not-the-requested-image')
            result = helper.stage(str(output), (str(backup),))
            self.assertEqual(result['staged_count'], 1)
            self.assertEqual((output/relative).read_bytes(), source.read_bytes())
            self.assertEqual(result['staged'][0]['sha256'], hashlib.sha256(source.read_bytes()).hexdigest())
            self.assertEqual(len(result['absent']), 7)
            self.assertFalse((output/'different-branch').exists())

    def test_conflicting_backup_bytes_are_not_chosen(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); output = root/'output'; output.mkdir()
            roots = []
            for index in range(2):
                backup = root/str(index)
                source = backup/helper.PATHS[0]
                source.parent.mkdir(parents=True); source.write_bytes(bytes([index + 1]))
                roots.append(str(backup))
            result = helper.stage(str(output), roots)
            self.assertEqual(result['staged_count'], 0)
            self.assertEqual(result['ambiguous'], [helper.PATHS[0]])
            self.assertEqual(list(output.iterdir()), [])

    def test_symlink_files_and_parents_cannot_read_other_paths(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); backup = root/'backup'; output = root/'output'
            backup.mkdir(); output.mkdir()
            source = backup/helper.PATHS[0]
            source.parent.mkdir(parents=True)
            other = root/'private'; other.write_bytes(b'not-public')
            source.symlink_to(other)
            result = helper.stage(str(output), (str(backup),))
            self.assertEqual(result['staged_count'], 0)
            source.unlink()
            linked = root/'linked-backup'; linked.symlink_to(backup, target_is_directory=True)
            result = helper.stage(str(output), (str(linked),))
            self.assertEqual(result['staged_count'], 0)
            self.assertEqual(list(output.iterdir()), [])


if __name__ == '__main__':
    unittest.main()
