import hashlib
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch


BASE = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


helper = load('performance_source', BASE / 'deployment/go_erp_desktop_performance_source.py')
legacy_path = BASE / 'tests/fixtures/go_erp_desktop_performance/source_root.py'
if hashlib.sha256(legacy_path.read_bytes()).hexdigest() != '0e6cade4b9fd20a6a73ce7a79a587e67c69b9cecb1b6c852a62c531fcd2c5375':
    raise AssertionError('The original source WAL fixture differs from the pinned root helper')
source = load('performance_original_source', legacy_path)


class AssetPlan(unittest.TestCase):
    def test_production_plan_uses_only_reviewed_assets(self):
        # A normal checkout contains the assets beside deployment/. The sparse
        # review workspace stores them in its adjacent current-source checkout.
        roots = (BASE, BASE.parent / 'perf_current')
        root = next(root for root in roots if all((root / name).is_file() for name in helper.PINS))
        payloads = {name: (root / name).read_bytes() for name in helper.PINS}
        plan = helper.checked_plan(source, payloads)
        self.assertEqual(plan['release'], '11c739039e5c6320b3542d414682d755c7902790')
        self.assertEqual(plan['root'], '/home/fasakha/public_html')
        self.assertEqual((plan['owner_uid'], plan['owner_gid']), (1001, 1003))
        self.assertEqual(plan['new_directories'], [])
        self.assertEqual(plan['addition_order'], [])
        self.assertEqual(set(plan['paths']), {
            'public/dashboard/js/dashboard-spa.js',
            'public/dashboard/js/desktop-client.js',
            'public/dashboard/js/order-board.js'})
        self.assertEqual(plan['replacement_order'], sorted(plan['paths']))
        for name in payloads:
            with self.subTest(name=name):
                changed = dict(payloads)
                changed[name] += b'\n'
                with self.assertRaises(source.Stop):
                    helper.checked_plan(source, changed)
        with self.assertRaises(source.Stop):
            helper.checked_plan(source, {**payloads, '.env': b'forbidden'})


class RealSourceTransaction(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='performance-source-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'site'
        self.root.mkdir(mode=0o755)
        self.audit = Path(self.temporary.name) / 'audits'
        self.audit.mkdir(mode=0o700)
        self.before = {name: ('before:' + name + '\n').encode() for name in helper.PINS}
        self.after = {name: ('after:' + name + '\n').encode() for name in helper.PINS}
        self.pins = {name: {'before': source.sha(self.before[name]), 'after': source.sha(self.after[name])}
                     for name in helper.PINS}
        self.facts = {}
        for index, (name, raw) in enumerate(self.before.items()):
            path = self.root / name
            path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            path.write_bytes(raw)
            path.chmod(0o640 if index % 2 else 0o644)
            info = path.stat()
            self.facts[name] = (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        self.environment = self.root / '.env'
        self.environment.write_bytes(b'WORKSPACE_ENABLED=true\nPRIVATE_SENTINEL=unchanged\n')
        self.environment.chmod(0o600)
        self.env_before = (self.environment.read_bytes(), source.identity(self.environment.stat()))
        self.verified = 0
        self.locks_valid = True
        for name, value in {'ROOT': self.root, 'AUDITS': self.audit, 'OWNER_UID': os.getuid(),
                            'OWNER_GID': os.getgid(), 'PINS': self.pins}.items():
            active = patch.object(helper, name, value)
            active.start()
            self.addCleanup(active.stop)

    def verify(self):
        self.verified += 1
        return self.locks_valid

    def transaction(self, checkpoint=None):
        return helper.transaction(source, self.after, verify_locks=self.verify, checkpoint=checkpoint)

    def assert_site(self, expected):
        for name, raw in expected.items():
            path = self.root / name
            self.assertEqual(path.read_bytes(), raw)
            info = path.stat()
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), self.facts[name])
        self.assertEqual((self.environment.read_bytes(), source.identity(self.environment.stat())), self.env_before)

    def test_apply_is_resumable_and_preserves_site_metadata_and_env(self):
        transaction = self.transaction()
        self.assert_site(self.before)
        result = transaction.apply()
        self.assertEqual(result['paths'], sorted(self.pins))
        self.assertTrue(result['changed'])
        self.assertFalse(result['schema_changed'])
        self.assertFalse(result['financial_data_changed'])
        self.assertFalse(result['feature_activated'])
        self.assertFalse(result['allocated'])
        self.assertFalse(result['published'])
        self.assert_site(self.after)
        resumed = self.transaction().apply()
        self.assertFalse(resumed['changed'])
        self.assert_site(self.after)
        self.assertGreater(self.verified, 10)
        with self.assertRaisesRegex(source.Stop, 'COMMITTED_ROLLBACK_REQUIRES_REVIEW'):
            transaction.rollback()

    def test_owned_rollback_restores_only_exact_before_images(self):
        transaction = self.transaction()
        def interrupted_before_commit():
            raise RuntimeError('interrupted before source commit')
        with self.assertRaises(RuntimeError):
            transaction.apply(interrupted_before_commit)
        result = transaction.rollback()
        self.assertTrue(result['restored'])
        self.assert_site(self.before)

    def test_interrupted_publish_resumes_original_wal(self):
        class Interrupt(Exception):
            pass
        seen = []
        transaction = self.transaction()
        # The exact engine labels are deliberately discovered by its callbacks;
        # interrupt on any first source-changing checkpoint below intent.
        def interrupt(phase, name):
            seen.append((phase, name))
            if name and (self.root / name).is_file() and (self.root / name).read_bytes() == self.after[name]:
                raise Interrupt()
        transaction.checkpoint = interrupt
        with self.assertRaises(Interrupt):
            transaction.apply()
        self.transaction().apply()
        self.assert_site(self.after)

    def test_foreign_edits_are_rejected_before_intent(self):
        name = next(iter(self.pins))
        (self.root / name).write_bytes(b'foreign edit')
        with self.assertRaises(source.Stop):
            self.transaction()
        for other in set(self.pins) - {name}:
            self.assertEqual((self.root / other).read_bytes(), self.before[other])

    def test_changed_owned_inode_prevents_rollback(self):
        transaction = self.transaction()
        def interrupted_before_commit():
            raise RuntimeError('interrupted before source commit')
        with self.assertRaises(RuntimeError):
            transaction.apply(interrupted_before_commit)
        name = next(iter(self.pins))
        path = self.root / name
        replacement = path.with_name('foreign-replacement.js')
        replacement.write_bytes(path.read_bytes())
        replacement.chmod(stat.S_IMODE(path.stat().st_mode))
        replacement.replace(path)
        foreign_inode = source.inode(path.stat())
        with self.assertRaises(source.Stop):
            transaction.rollback()
        self.assertEqual(path.read_bytes(), self.after[name])
        self.assertEqual(source.inode(path.stat()), foreign_inode)
        self.assertEqual((self.environment.read_bytes(), source.identity(self.environment.stat())), self.env_before)

    def test_lock_loss_stops_before_source_write(self):
        transaction = self.transaction()
        self.locks_valid = False
        with self.assertRaises(source.Stop):
            transaction.apply()
        self.assert_site(self.before)

    def test_symlink_or_hardlink_before_source_is_rejected(self):
        name = next(iter(self.pins))
        path = self.root / name
        original = path.with_name('private-original.js')
        path.rename(original)
        path.symlink_to(original)
        with self.assertRaises((source.Stop, OSError)):
            self.transaction()
        path.unlink()
        os.link(original, path)
        with self.assertRaises(source.Stop):
            self.transaction()


if __name__ == '__main__':
    unittest.main()
