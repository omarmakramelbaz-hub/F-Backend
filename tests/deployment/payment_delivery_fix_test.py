import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('payment_delivery_fix', REPO / 'deployment/apply_payment_delivery_fix_root.py')
fix = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fix)


class PaymentDeliveryPublicationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'app'
        self.root.mkdir()
        package = json.loads((REPO / 'deployment/payment_delivery_fix.json').read_text())
        self.manifest = package['files']
        self.payloads = {name: (REPO / name).read_bytes() for name in fix.PATHS}
        for name in fix.PATHS:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(subprocess.check_output(['git', 'show', package['base_revision'] + ':' + name], cwd=REPO))
            target.chmod(0o640)
        self.unrelated = self.root / 'unrelated.txt'
        self.unrelated.write_text('Keep this live edit')

    def test_exact_publication_backs_up_preserves_metadata_and_repeats_without_changes(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        self.assertEqual(4, len(changes))
        backup = Path(self.temporary.name) / 'backup'
        fix.publish(self.root, changes, backup)
        for name, original, desired, info in changes:
            self.assertEqual(original, (backup / name).read_bytes())
            self.assertEqual(desired, (self.root / name).read_bytes())
            self.assertEqual(0o640, (self.root / name).stat().st_mode & 0o777)
            self.assertEqual(info.st_uid, (self.root / name).stat().st_uid)
        self.assertEqual([], fix.plan(self.root, self.manifest, self.payloads))
        self.assertEqual('Keep this live edit', self.unrelated.read_text())

    def test_partial_previous_publication_only_changes_remaining_files(self):
        name = fix.PATHS[0]
        (self.root / name).write_bytes(self.payloads[name])
        changes = fix.plan(self.root, self.manifest, self.payloads)
        self.assertEqual(3, len(changes))
        self.assertNotIn(name, [change[0] for change in changes])

    def test_unexpected_live_edit_is_rejected_before_publication(self):
        target = self.root / fix.PATHS[-1]
        target.write_text('Unreviewed live edit')
        before = {name: (self.root / name).read_bytes() for name in fix.PATHS}
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            fix.plan(self.root, self.manifest, self.payloads)
        self.assertEqual(before, {name: (self.root / name).read_bytes() for name in fix.PATHS})

    def test_tampered_package_is_rejected(self):
        self.payloads[fix.PATHS[0]] += b'\n'
        with self.assertRaisesRegex(RuntimeError, 'PACKAGE_HASH_INVALID'):
            fix.plan(self.root, self.manifest, self.payloads)

    def test_file_symlink_is_rejected(self):
        target = self.root / fix.PATHS[0]
        target.unlink()
        target.symlink_to(self.unrelated)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            fix.plan(self.root, self.manifest, self.payloads)

    def test_parent_symlink_is_rejected(self):
        parent = self.root / 'app'
        moved = self.root / 'moved-app'
        parent.rename(moved)
        parent.symlink_to(moved, target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            fix.plan(self.root, self.manifest, self.payloads)

    def test_write_failure_rolls_back_published_files_and_retains_backups(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        original_replace = fix.replace_file
        count = 0
        def fail_second(target, content, info):
            nonlocal count
            count += 1
            if count == 2:
                raise RuntimeError('SIMULATED_WRITE_FAILURE')
            original_replace(target, content, info)
        backup = Path(self.temporary.name) / 'backup'
        with patch.object(fix, 'replace_file', fail_second):
            with self.assertRaisesRegex(RuntimeError, 'SIMULATED_WRITE_FAILURE'):
                fix.publish(self.root, changes, backup)
        for name, original, _, _ in changes:
            self.assertEqual(original, (self.root / name).read_bytes())
            self.assertEqual(original, (backup / name).read_bytes())

    def test_changed_source_between_preflight_and_write_is_preserved(self):
        changes = fix.plan(self.root, self.manifest, self.payloads)
        target = self.root / fix.PATHS[1]
        target.write_text('New concurrent edit')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED'):
            fix.publish(self.root, changes, Path(self.temporary.name) / 'backup')
        self.assertEqual('New concurrent edit', target.read_text())
        self.assertEqual(changes[0][1], (self.root / fix.PATHS[0]).read_bytes())


if __name__ == '__main__':
    unittest.main()
