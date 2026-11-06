"""Check that the targeted courier receipt release preserves unrelated live code."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT/'deployment/courier-delivery-breakdown-20261106'
SPEC = importlib.util.spec_from_file_location('courier_release', RELEASE/'apply_root.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class CourierReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='courier-release-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()/'project'
        self.root.mkdir()
        self.manifest = json.loads((RELEASE/'manifest.json').read_text())
        self.original = {}
        for item in self.manifest['files']:
            path = self.root/item['path']
            path.parent.mkdir(parents=True, exist_ok=True)
            if item['mode'] == 'patch':
                content = ('<?php\n// unrelated server change\n'+item['before']+'\n// another server change\n').encode()
            elif item['before_sha256'] is not None:
                content = b'original view'
                item['before_sha256'] = release.digest(content)
            else:
                content = None
            if content is not None:
                path.write_bytes(content)
            self.original[item['path']] = content
        self.backup = Path(self.temp.name)/'backup'
        self.owner = (os.getuid(), os.getgid())

    def state(self):
        return {name:(self.root/name).read_bytes() if (self.root/name).exists() else None for name in release.PATHS}

    def test_apply_preserves_other_server_changes_and_repeat_is_idempotent(self):
        changes = release.plan(self.root, self.manifest)
        self.assertEqual(release.apply_plan(self.root, changes, self.backup, self.owner, lambda:None), 5)
        board = (self.root/'app/Services/Dashboard/PhoneDeliveryBoard.php').read_text()
        self.assertIn('// unrelated server change', board)
        self.assertIn('// another server change', board)
        self.assertIn('PhoneDeliveryBatchReceipt::present', board)
        repeated = release.plan(self.root, self.manifest)
        self.assertEqual(release.apply_plan(self.root, repeated, self.backup/'unused', self.owner, lambda:None), 0)

    def test_failed_verification_restores_changed_and_new_files(self):
        def fail():
            raise RuntimeError('intentional verification failure')
        with self.assertRaisesRegex(RuntimeError, 'intentional verification failure'):
            release.apply_plan(self.root, release.plan(self.root, self.manifest), self.backup, self.owner, fail)
        self.assertEqual(self.state(), self.original)
        self.assertEqual(stat.S_IMODE(self.backup.stat().st_mode), 0o700)

    def test_conflicting_receipt_method_rejects_entire_plan(self):
        path = self.root/'app/Services/Dashboard/PhoneDeliveryBoard.php'
        path.write_text('<?php // a different receipt implementation')
        before = self.state()
        with self.assertRaisesRegex(RuntimeError, 'COURIER_RECEIPT_SOURCE_CHANGED'):
            release.plan(self.root, self.manifest)
        self.assertEqual(before, self.state())

    def test_concurrent_server_change_is_preserved(self):
        changes = release.plan(self.root, self.manifest)
        path = self.root/'app/Services/Dashboard/PhoneDeliveryBoard.php'
        path.write_text('<?php // concurrent server change')
        before = self.state()
        with self.assertRaisesRegex(RuntimeError, 'COURIER_RECEIPT_CONCURRENT_SOURCE_CHANGE'):
            release.apply_plan(self.root, changes, self.backup, self.owner, lambda:None)
        self.assertEqual(before, self.state())

    def test_changed_view_or_existing_new_class_is_not_overwritten(self):
        for name in ['resources/views/admin/phone_orders/batch_receipt.blade.php',
                     'app/Services/Dashboard/PhoneDeliveryBatchReceipt.php']:
            path = self.root/name
            original = path.read_bytes() if path.exists() else None
            path.write_bytes(b'unrelated newer implementation')
            with self.assertRaisesRegex(RuntimeError, 'COURIER_RECEIPT_SOURCE_CHANGED'):
                release.plan(self.root, self.manifest)
            if original is None:
                path.unlink()
            else:
                path.write_bytes(original)

    def test_payload_and_scope_are_checked(self):
        for key, value, error in [('content','tampered','CANDIDATE_CHANGED'),
                                  ('path','routes/admin.php','MANIFEST_CHANGED')]:
            manifest = copy.deepcopy(self.manifest)
            manifest['files'][0][key] = value
            with self.assertRaisesRegex(RuntimeError, error):
                release.plan(self.root, manifest)

    def test_candidate_is_readable_by_site_account_under_private_umask(self):
        stage = Path(self.temp.name)/'stage'
        stage.mkdir()
        previous = os.umask(0o077)
        try:
            candidate = release.stage_candidate(stage, release.plan(self.root, self.manifest))
        finally:
            os.umask(previous)
        for path in [candidate, *candidate.rglob('*')]:
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o755 if path.is_dir() else 0o444)

    def test_symlink_is_rejected(self):
        path = self.root/'app/Services/Dashboard/PhoneDeliveryBatchReceipt.php'
        outside = Path(self.temp.name)/'outside'
        outside.write_bytes(b'preserve')
        path.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_PATH'):
            release.plan(self.root, self.manifest)
        self.assertEqual(outside.read_bytes(), b'preserve')


if __name__ == '__main__':
    unittest.main()
