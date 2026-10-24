"""Exercise the single-asset server HUD publisher and guarded rollback."""
import hashlib
import importlib.util
import io
import json
import os
from contextlib import redirect_stdout
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('hud_blue_publisher', ROOT / 'deployment/apply_hud_blue_root.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class HudBluePublisherTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name) / 'app'
        self.root.mkdir()
        self.backup = Path(temporary.name) / 'backup'
        self.assertEqual(tuple(m.PINS), ('public/dashboard/js/home-hud.js',))
        self.name = m.CONTROLLER
        self.target = self.root / self.name
        self.new = {self.name: (ROOT / self.name).read_bytes()}
        self.assertEqual(hashlib.sha256(self.new[self.name]).hexdigest(), m.PINS[self.name][1])
        self.old = b'reviewed previous solar JavaScript\n'
        patched = patch.object(m, 'PINS', {self.name:
            (hashlib.sha256(self.old).hexdigest(), m.PINS[self.name][1])})
        patched.start()
        self.addCleanup(patched.stop)
        self.engine = m.engine((ROOT / 'deployment/apply_paymob_order_confirmation_root.py').read_bytes())
        self.target.parent.mkdir(parents=True)
        self.target.write_bytes(self.old)
        self.target.chmod(0o640)
        os.utime(self.target, ns=(1000000000, 1000000000))

    def publish(self):
        with redirect_stdout(io.StringIO()) as output:
            changed = m.publish_package(self.root, self.engine, self.new, self.backup,
                os.getuid(), os.getgid())
        return changed, output.getvalue()

    def test_backup_fresh_cache_key_and_idempotent_retry_without_application_commands(self):
        # An asset update must not run PHP, artisan, database or provider code.
        with patch.object(m.subprocess, 'run', side_effect=AssertionError('Unexpected application command')):
            changed, output = self.publish()
            self.assertTrue(changed)
            self.assertIn('HUD_BLUE_SOURCE_READY', output)
            self.assertEqual(self.target.read_bytes(), self.new[self.name])
            self.assertEqual((self.backup / self.name).read_bytes(), self.old)
            metadata = json.loads((self.backup / 'metadata.json').read_text())
            self.assertEqual(set(metadata), {self.name})
            self.assertTrue(metadata[self.name]['existed'])
            self.assertEqual(self.target.stat().st_mode & 0o777, 0o640)
            self.assertGreater(self.target.stat().st_mtime_ns, 1000000000)
            published_time = self.target.stat().st_mtime_ns
            changed, output = self.publish()
            self.assertFalse(changed)
            self.assertIn('HUD_BLUE_SOURCE_ALREADY_APPLIED', output)
            self.assertEqual(self.target.stat().st_mtime_ns, published_time)

    def test_verification_failure_after_atomic_write_rolls_back_the_single_asset(self):
        real_regular = self.engine.regular
        writes = []
        failed = False
        def replace(target, content, *args):
            m.cache_replace(target, content, *args)
            writes.append(content)
        def regular(*args, **kwargs):
            nonlocal failed
            if writes and not failed:
                failed = True
                raise OSError('injected post-write verification failure')
            return real_regular(*args, **kwargs)
        with patch.object(self.engine, 'replace_file', side_effect=replace), \
                patch.object(self.engine, 'regular', side_effect=regular):
            with self.assertRaisesRegex(OSError, '^injected post-write verification failure$'):
                self.publish()
        self.assertEqual(writes, [self.new[self.name], self.old])
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o640)
        self.assertEqual((self.backup / self.name).read_bytes(), self.old)

    def test_unknown_live_edit_refuses_before_backup_or_any_source_write(self):
        self.target.write_bytes(b'unknown live edit')
        with patch.object(self.engine, 'replace_file', side_effect=AssertionError('No source write allowed')):
            with self.assertRaisesRegex(RuntimeError, '^BASELINE_MISMATCH$'):
                self.publish()
        self.assertEqual(self.target.read_bytes(), b'unknown live edit')
        self.assertFalse(self.backup.exists())


if __name__ == '__main__':
    unittest.main()
