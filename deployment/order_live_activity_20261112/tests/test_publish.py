import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

PACKAGE = Path(__file__).resolve().parents[1]
ROOT = PACKAGE.parents[1]
spec = importlib.util.spec_from_file_location('activity_publish', PACKAGE / 'publish.py')
publish = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publish)

class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'application'
        self.root.mkdir()
        self.backup = Path(self.temp.name) / 'backup'
        manifest = json.loads((PACKAGE / 'manifest.json').read_text())
        for name in [*publish.PATCHES, *manifest]:
            source = subprocess.run(['git', 'show', 'HEAD:' + name], cwd=ROOT, capture_output=True)
            # Tests run against the released commit too: reconstruct the event's reviewed preimage.
            if name == 'app/Events/OrderStatusUpdated.php':
                source = subprocess.run(['git', 'show', '9683bb9058a3fe7cc04a63c36e333cd5b7161343:' + name], cwd=ROOT, capture_output=True)
            if name in publish.PATCHES:
                source = subprocess.run(['git', 'show', '9683bb9058a3fe7cc04a63c36e333cd5b7161343:' + name], cwd=ROOT, capture_output=True)
            if source.returncode == 0 and (name in publish.PATCHES or manifest.get(name, {}).get('before') is not None):
                target = self.root / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(source.stdout)
        self.calls = []

    def runner(self, command, cwd):
        self.calls.append(command)
        return 'user/live-activities user/live-activities/unregister'

    def apply(self, runner=None):
        return publish.apply(self.root, PACKAGE, self.backup, os.getuid(), os.getgid(), php='php', runner=runner or self.runner)

    def test_incremental_apply_is_idempotent_and_rollback_restores_every_file(self):
        originals = {str(p.relative_to(self.root)): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        self.assertTrue(self.apply())
        self.assertFalse(self.apply())
        migrate = [call for call in self.calls if 'migrate' in call]
        self.assertEqual(len(migrate), 1)
        self.assertIn('--path=' + publish.MIGRATION, migrate[0])
        publish.rollback(self.root, self.backup)
        restored = {str(p.relative_to(self.root)): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        self.assertEqual(restored, originals)

    def test_unrelated_production_edits_are_preserved(self):
        name = 'routes/api.php'
        target = self.root / name
        target.write_bytes(target.read_bytes() + b'\n// existing production customization\n')
        self.apply()
        self.assertIn(b'existing production customization', target.read_bytes())

    def test_unknown_baseline_refuses_without_mutation(self):
        target = self.root / 'app/Events/OrderStatusUpdated.php'
        target.write_bytes(target.read_bytes() + b'\n// different event\n')
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            self.apply()
        self.assertFalse(self.backup.exists())

    def test_failed_activation_restores_files(self):
        original = (self.root / 'routes/api.php').read_bytes()
        def fail(command, cwd):
            if 'migrate' in command:
                raise RuntimeError('simulated migration failure')
            return self.runner(command, cwd)
        with self.assertRaisesRegex(RuntimeError, 'simulated migration'):
            self.apply(fail)
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), original)
        self.assertFalse((self.root / 'app/Models/OrderLiveActivity.php').exists())

    def test_symlinked_destination_is_rejected(self):
        target = self.root / 'app/Models'
        target.mkdir(exist_ok=True)
        (target / 'OrderLiveActivity.php').symlink_to(self.root / 'routes/api.php')
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.apply()

if __name__ == '__main__':
    unittest.main()
