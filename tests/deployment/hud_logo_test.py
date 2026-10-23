"""Exercise the two-file server logo publication, retry, and guarded rollback."""
import hashlib
import importlib.util
import io
import json
import os
from contextlib import redirect_stdout
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('hud_logo_publisher', ROOT / 'deployment/apply_hud_logo_root.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class HudLogoPublisherTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name) / 'app'
        self.root.mkdir()
        self.backup = Path(temporary.name) / 'backup'
        self.calls = []
        self.new = {name: (ROOT / name).read_bytes() for name in m.PINS}
        self.old = {name: ('prior ' + name).encode() for name in m.PINS}
        for name in m.PINS:
            self.assertEqual(hashlib.sha256(self.new[name]).hexdigest(), m.PINS[name][1])
        pins = {name: (hashlib.sha256(self.old[name]).hexdigest(), pin[1]) for name, pin in m.PINS.items()}
        patched = patch.object(m, 'PINS', pins)
        patched.start()
        self.addCleanup(patched.stop)
        self.engine = m.engine((ROOT / 'deployment/apply_paymob_order_confirmation_root.py').read_bytes())
        for name in m.PINS:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(self.old[name])
            target.chmod(0o640)
            os.utime(target, ns=(1000000000, 1000000000))

    def runner(self, args, **kwargs):
        self.calls.append(args)
        if args[:3] == [m.PHP, '-n', '-l']:
            self.assertEqual(Path(args[3]).read_bytes(), self.new[m.CONTROLLER])
        else:
            self.assertEqual(args, ['/usr/sbin/runuser', '-u', 'fasakha', '--', m.PHP,
                '-d', 'display_errors=0', '-d', 'log_errors=0', 'artisan', 'view:clear'])
            self.assertEqual(kwargs['cwd'], str(self.root))
            for name in self.new:
                self.assertEqual((self.root / name).read_bytes(), self.new[name])
        return types.SimpleNamespace(returncode=0)

    def publish(self):
        with redirect_stdout(io.StringIO()) as output:
            changed = m.publish_package(self.root, self.engine, self.new, self.backup,
                os.getuid(), os.getgid(), self.runner)
        return changed, output.getvalue()

    def test_css_then_view_publish_with_backup_fresh_cache_key_and_idempotent_retry(self):
        written = []
        def replace(target, *args):
            written.append(str(target.relative_to(self.root)))
            return m.cache_replace(target, *args)
        with patch.object(self.engine, 'replace_file', side_effect=replace):
            changed, output = self.publish()
        self.assertTrue(changed)
        self.assertIn('HUD_LOGO_SOURCE_READY', output)
        self.assertEqual(written, [m.SERVICE, m.CONTROLLER])
        self.assertEqual(sum(args[:3] == [m.PHP, '-n', '-l'] for args in self.calls), 1)
        self.assertEqual(self.calls[-1][-1], 'view:clear')
        metadata = json.loads((self.backup / 'metadata.json').read_text())
        mtimes = {}
        for name in self.old:
            self.assertEqual((self.backup / name).read_bytes(), self.old[name])
            target = self.root / name
            self.assertEqual(target.stat().st_mode & 0o777, 0o640)
            self.assertGreater(target.stat().st_mtime_ns, 1000000000)
            self.assertTrue(metadata[name]['existed'])
            mtimes[name] = target.stat().st_mtime_ns
        changed, output = self.publish()
        self.assertFalse(changed)
        self.assertIn('HUD_LOGO_SOURCE_ALREADY_APPLIED', output)
        self.assertEqual(self.calls[-1][-1], 'view:clear')
        for name, old_time in mtimes.items():
            self.assertEqual((self.root / name).stat().st_mtime_ns, old_time)

    def test_failed_view_write_rolls_back_css_and_keeps_original_view(self):
        def replace(target, *args):
            if target == self.root / m.CONTROLLER:
                raise OSError('injected failure')
            return m.cache_replace(target, *args)
        with patch.object(self.engine, 'replace_file', side_effect=replace):
            with self.assertRaises(OSError):
                self.publish()
        for name in m.PINS:
            self.assertEqual((self.root / name).read_bytes(), self.old[name])
            self.assertEqual((self.root / name).stat().st_mode & 0o777, 0o640)
            self.assertEqual((self.backup / name).read_bytes(), self.old[name])
        self.assertFalse(any(args[-1] == 'view:clear' for args in self.calls))

    def test_unknown_live_edits_refuse_before_lint_or_any_source_write(self):
        (self.root / m.CONTROLLER).write_bytes(b'unknown live edit')
        with self.assertRaisesRegex(RuntimeError, '^BASELINE_MISMATCH$'):
            self.publish()
        self.assertEqual((self.root / m.SERVICE).read_bytes(), self.old[m.SERVICE])
        self.assertEqual((self.root / m.CONTROLLER).read_bytes(), b'unknown live edit')
        self.assertFalse(self.calls)
        self.assertFalse(self.backup.exists())


if __name__ == '__main__':
    unittest.main()
