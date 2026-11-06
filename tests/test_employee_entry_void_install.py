import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
RELATIVE = 'public/dashboard/js/branch-operations.js'
RELEASE = ROOT / 'deployment/employee-entry-void-dialog-20261106'
spec = importlib.util.spec_from_file_location('employee_entry_void_install', RELEASE / 'apply.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
BASELINE = subprocess.check_output(['git', '-C', str(ROOT), 'show',
                                   '0675c1e47b272932d24c764fb00599b867b17566:' + RELATIVE])
TARGET = (ROOT / RELATIVE).read_bytes()


class EmployeeEntryVoidInstallerTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'app'
        self.source = self.root / RELATIVE
        self.source.parent.mkdir(parents=True)
        self.source.write_bytes(BASELINE)
        self.source.chmod(0o640)

    def install(self, **options):
        with contextlib.redirect_stdout(io.StringIO()):
            installer.install(self.root, revision='a' * 40, **options)

    def test_context_patch_matches_reviewed_asset_and_preserves_other_changes(self):
        self.assertEqual(installer.candidate(RELATIVE, BASELINE), TARGET)
        decorated = b'// Existing live customization.\n' + BASELINE + b'\n// Keep this too.\n'
        result = installer.candidate(RELATIVE, decorated)
        self.assertEqual(result, b'// Existing live customization.\n' + TARGET + b'\n// Keep this too.\n')
        self.assertEqual(installer.candidate(RELATIVE, result), result)

    def test_apply_backs_up_and_bumps_asset_version_without_changing_mode(self):
        before = self.source.stat()
        self.install()
        self.assertEqual(self.source.read_bytes(), TARGET)
        self.assertEqual(self.source.stat().st_mode & 0o777, 0o640)
        self.assertGreater(int(self.source.stat().st_mtime), int(before.st_mtime))
        backups = list((self.root.parent / 'employee-entry-void-dialog-backups').iterdir())
        self.assertEqual(len(backups), 1)
        self.assertEqual((backups[0] / RELATIVE).read_bytes(), BASELINE)
        manifest = json.loads((backups[0] / 'manifest.json').read_text())
        self.assertEqual(manifest['files'][RELATIVE]['sha256'], hashlib.sha256(BASELINE).hexdigest())
        self.install()
        self.assertEqual(len(list(backups[0].parent.iterdir())), 1)

    def test_dry_run_does_not_modify_source_or_make_backup(self):
        self.install(check=True)
        self.assertEqual(self.source.read_bytes(), BASELINE)
        self.assertFalse((self.root.parent / 'employee-entry-void-dialog-backups').exists())

    def test_unknown_review_implementation_stops_before_writing(self):
        changed = BASELINE.replace("prompt('سبب إلغاء الحركة:')".encode(), b"customReason()")
        self.source.write_bytes(changed)
        with self.assertRaisesRegex(RuntimeError, 'PATCH_CONFLICT'):
            self.install()
        self.assertEqual(self.source.read_bytes(), changed)
        self.assertFalse((self.root.parent / 'employee-entry-void-dialog-backups').exists())

    def test_failure_after_replace_restores_original_file(self):
        original_write = installer.atomic_write
        calls = []

        def fail_after_replace(path, data, info):
            original_write(path, data, info)
            calls.append(data)
            if len(calls) == 1:
                raise RuntimeError('Injected disk failure')

        with patch.object(installer, 'atomic_write', fail_after_replace):
            with self.assertRaisesRegex(RuntimeError, 'Injected disk failure'):
                self.install()
        self.assertEqual(self.source.read_bytes(), BASELINE)
        self.assertEqual(len(calls), 2)

    def test_launcher_verifies_exact_installer_bytes(self):
        digest = hashlib.sha256((RELEASE / 'apply.py').read_bytes()).hexdigest()
        self.assertIn(digest, (RELEASE / 'RUN_ROOT.sh').read_text())


if __name__ == '__main__':
    unittest.main()
