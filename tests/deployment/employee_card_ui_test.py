import importlib.util
from pathlib import Path
import os
import tempfile
import types
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('employee_card_publisher', REPO/'deployment/apply_employee_card_ui_root.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class EmployeeCardPublicationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)/'application'
        self.root.mkdir()
        self.publisher = module.engine((REPO/'deployment/apply_paymob_order_confirmation_root.py').read_bytes())
        self.names = tuple(module.PINS)
        self.before = {name: ('before '+name).encode() for name in self.names}
        self.after = {name: ('after '+name).encode() for name in self.names}
        self.manifest = {name: {'before': [self.publisher.digest(self.before[name])], 'after': self.publisher.digest(self.after[name])} for name in self.names}
        for name in self.names:
            file = self.root/name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(self.before[name])
            file.chmod(0o640)

    def test_two_assets_publish_css_first_with_backup_metadata_and_fresh_cache_keys(self):
        future = 2000000000000000000
        for name in self.names:
            os.utime(self.root/name, ns=(future, future))
        changes = self.publisher.plan(self.root, self.manifest, self.after, owner_uid=os.getuid())
        order = []
        def record(*args):
            order.append(args[0].relative_to(self.root).as_posix())
            return module.asset_replace(*args)
        with patch.object(self.publisher, 'replace_file', side_effect=record):
            self.publisher.publish(self.root, changes, Path(self.tmp.name)/'backup')
        self.assertEqual(list(self.names), order)
        self.assertTrue(order[0].endswith('.css'))
        for name in self.names:
            live = self.root/name
            self.assertEqual(live.read_bytes(), self.after[name])
            self.assertEqual((Path(self.tmp.name)/'backup'/name).read_bytes(), self.before[name])
            self.assertEqual(live.stat().st_mode & 0o777, 0o640)
            self.assertEqual(live.stat().st_uid, os.getuid())
            self.assertGreater(live.stat().st_mtime_ns//1000000000, future//1000000000)
        self.assertEqual(self.publisher.plan(self.root, self.manifest, self.after), [])

    def test_unreviewed_source_payload_and_symlink_refuse_before_publication(self):
        for kind in ('source', 'payload', 'symlink'):
            name = self.names[1]
            file = self.root/name
            file.unlink()
            file.write_bytes(b'other edit' if kind == 'source' else self.before[name])
            payloads = dict(self.after)
            if kind == 'payload': payloads[name] = b'tampered payload'
            if kind == 'symlink':
                file.unlink();file.symlink_to(self.root/self.names[0])
            with self.subTest(kind=kind), self.assertRaises(RuntimeError):
                self.publisher.plan(self.root, self.manifest, payloads)
            self.assertEqual((self.root/self.names[0]).read_bytes(), self.before[self.names[0]])

    def test_second_asset_failure_restores_first_asset(self):
        changes = self.publisher.plan(self.root, self.manifest, self.after)
        calls = []
        def fail_second(*args):
            calls.append(args[0])
            if len(calls) == 2: raise OSError('injected source publication failure')
            return module.asset_replace(*args)
        with patch.object(self.publisher, 'replace_file', side_effect=fail_second), self.assertRaises(OSError):
            self.publisher.publish(self.root, changes, Path(self.tmp.name)/'backup')
        for name in self.names:
            self.assertEqual((self.root/name).read_bytes(), self.before[name])

    def test_pin_and_path_scope_are_narrow(self):
        with self.assertRaises(RuntimeError): module.engine(b'changed publisher')
        self.assertEqual(set(module.PINS), {'public/dashboard/css/branch-operations.css', 'public/dashboard/js/branch-operations.js'})
        with self.assertRaises(RuntimeError):
            self.publisher.plan(self.root, {**self.manifest, 'other': {}}, self.after)

    def test_schema_probe_is_read_only_and_failures_are_sanitized(self):
        captured = []
        def run(args, **kwargs):
            captured.append(args)
            return types.SimpleNamespace(returncode=0, stdout=b'READY')
        with patch.object(module.subprocess, 'run', side_effect=run): module.identity_ready()
        self.assertEqual(captured[0][-2], '-r')
        self.assertIn('EmployeeIdentity::ready()', captured[0][-1])
        self.assertNotIn('migrate', captured[0][-1])
        for code, response, expected in (
            (2, b'NOT_READY', 'IDENTITY_SCHEMA_REQUIRED'),
            (3, b'UNAVAILABLE', 'IDENTITY_BACKEND_PREFLIGHT_UNAVAILABLE'),
            (2, b'secret unexpected provider output', 'IDENTITY_BACKEND_PREFLIGHT_UNAVAILABLE')):
            with patch.object(module.subprocess, 'run', return_value=types.SimpleNamespace(returncode=code, stdout=response)):
                with self.assertRaisesRegex(RuntimeError, '^'+expected+'$'): module.identity_ready()


if __name__ == '__main__':
    unittest.main()
