"""Exercise the self-contained deployment with no server release Git objects."""
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


packaging = module('phone_customer_bundle_packaging', ROOT / 'deployment/package_phone_customer_auto_branch.py')


class CustomerBranchBundleTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.archive_temporary = tempfile.TemporaryDirectory(prefix='phone-bundle-archive-')
        cls.addClassCleanup(cls.archive_temporary.cleanup)
        cls.archive_path = Path(cls.archive_temporary.name) / (packaging.TOP + '.zip')
        cls.revision = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
        cls.archive_sha256 = packaging.build_archive(ROOT, cls.revision, cls.archive_path)

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='phone-bundle-test-')
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name)
        with zipfile.ZipFile(self.archive_path) as archive:
            archive.extractall(self.parent)
        self.bundle = self.parent / packaging.TOP
        self.installer = module('phone_customer_bundle_under_test', self.bundle / 'install.py')
        self.root = self.parent / 'app'
        self.root.mkdir()
        self.before = {path: self.source(packaging.BASE_REVISION, path) for path in packaging.SOURCES}
        self.after = {path: self.source(self.revision, path) for path in packaging.SOURCES}
        self.publish(self.before)
        self.php = self.parent / 'php'
        self.php.write_text('#!/usr/bin/env python3\nraise SystemExit(0)\n')
        self.php.chmod(0o755)
        self.probe_patcher = mock.patch.object(self.installer, 'runtime_probe', return_value=None)
        self.probe = self.probe_patcher.start()
        self.addCleanup(self.probe_patcher.stop)

    @staticmethod
    def source(revision, path):
        return subprocess.check_output(['git', '-C', str(ROOT), 'show', revision + ':' + path])

    def publish(self, files):
        for path, body in files.items():
            target = self.root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            target.chmod(0o644)

    def snapshots(self):
        return {path: (self.root / path).read_bytes() for path in packaging.SOURCES}

    def install(self, **kwargs):
        with contextlib.redirect_stdout(io.StringIO()):
            return self.installer.install(self.root, str(self.php), **kwargs)

    def receipts(self):
        return list((self.parent / 'phone-customer-auto-branch-backups').glob('*/receipt.json'))

    def test_check_install_and_repeat_need_no_server_commit_or_repository(self):
        self.assertFalse((self.root / '.git').exists())
        self.assertIsNone(self.install(check_only=True))
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(self.receipts(), [])
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['delivery_mode'], 'verified_local_bundle')
        self.assertEqual(receipt['revision'], self.revision)
        self.assertEqual(receipt['payload_sha256'], self.installer.BUNDLE_PAYLOAD_SHA256)
        self.assertEqual(receipt['status'], 'installed')
        for path in packaging.SOURCES:
            self.assertEqual((backup / path).read_bytes(), self.before[path])
            self.assertEqual(stat.S_IMODE((self.root / path).stat().st_mode), 0o644)
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)

    def test_earlier_refresh_and_unrelated_live_edits_are_preserved(self):
        earlier = 'a7d7b5572390bff8ca4b2503172cc63470370dae'
        live = {path: self.source(earlier, path) + b'\n// Existing independent live customization\n'
                for path in packaging.SOURCES}
        self.publish(live)
        backup = self.install()
        updated = self.snapshots()
        for path in packaging.SOURCES:
            self.assertEqual((backup / path).read_bytes(), live[path])
            self.assertTrue(updated[path].endswith(b'// Existing independent live customization\n'))
        result = subprocess.run(['git', 'apply', '--reverse', str(backup / 'release.diff')], cwd=self.root,
                                capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(self.snapshots(), live)

    def test_partial_install_reuses_existing_backend_without_duplicate_backups(self):
        self.publish({packaging.SOURCES[0]: self.after[packaging.SOURCES[0]]})
        backup = self.install()
        self.assertEqual(self.snapshots(), self.after)
        self.assertEqual([entry['path'] for entry in json.loads((backup / 'receipt.json').read_text())['files']],
                         [packaging.SOURCES[1]])
        self.assertIsNone(self.install())

    def test_payload_tampering_stops_without_source_changes(self):
        for relative in ['utility.py', 'patches/0.diff', 'patches/1.diff']:
            path = self.bundle / relative
            original = path.read_bytes()
            path.write_bytes(original + b'\n# Tampered\n')
            with self.subTest(relative=relative), self.assertRaisesRegex(RuntimeError, 'BUNDLE_PAYLOAD_HASH_MISMATCH'):
                self.install()
            self.assertEqual(self.snapshots(), self.before)
            self.assertEqual(self.receipts(), [])
            path.write_bytes(original)

    def test_bundle_symlink_payload_is_rejected_before_loading(self):
        utility = self.bundle / 'utility.py'
        copy = self.parent / 'untrusted-utility.py'
        copy.write_bytes(utility.read_bytes())
        utility.unlink()
        utility.symlink_to(copy)
        with self.assertRaisesRegex(RuntimeError, 'BUNDLE_SYMLINK_REJECTED'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)

    def test_failed_installed_runtime_restores_exact_live_sources(self):
        self.probe.side_effect = [None, RuntimeError('PHONE_CUSTOMER_ROUTE_INVALID')]
        with self.assertRaisesRegex(RuntimeError, 'PHONE_CUSTOMER_ROUTE_INVALID'):
            self.install()
        self.assertEqual(self.snapshots(), self.before)
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_archive_paths_hash_manifest_launcher_and_metadata(self):
        self.assertEqual(hashlib.sha256(self.archive_path.read_bytes()).hexdigest(), self.archive_sha256)
        with zipfile.ZipFile(self.archive_path) as archive:
            for info in archive.infolist():
                path = PurePosixPath(info.filename)
                self.assertFalse(path.is_absolute())
                self.assertNotIn('..', path.parts)
                self.assertEqual(path.parts[0], packaging.TOP)
                self.assertTrue(stat.S_ISREG(info.external_attr >> 16))
        script = (self.bundle / 'INSTALL.sh').read_text()
        self.assertEqual(subprocess.run(['bash', '-n', str(self.bundle / 'INSTALL.sh')]).returncode, 0)
        blocks = re.findall(r"sha256sum --strict --check <<'BUNDLE_SHA256'\n(.*?)\nBUNDLE_SHA256", script, re.S)
        self.assertEqual(len(blocks), 2)
        self.assertEqual(blocks[0], blocks[1])
        checks = blocks[0].encode() + b'\n'
        result = subprocess.run(['sha256sum', '--strict', '--check'], input=checks, cwd=self.bundle, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        installer = self.bundle / 'install.py'
        installer.write_bytes(installer.read_bytes() + b'\n# tamper\n')
        result = subprocess.run(['sha256sum', '--strict', '--check'], input=checks, cwd=self.bundle, capture_output=True)
        self.assertNotEqual(result.returncode, 0, 'launcher must authenticate its exact installer bytes')
        metadata = json.loads((self.bundle / 'manifest.json').read_text())
        self.assertEqual(metadata['delivery_mode'], 'verified_local_bundle')
        self.assertEqual(metadata['revision'], self.revision)
        self.assertEqual(metadata['source_files'], list(packaging.SOURCES))
        self.assertNotIn('git fetch', script)
        self.assertNotIn('git reset', script)
        self.assertNotIn('git checkout', script)
        self.assertIn('chmod 0755 "$bundle_stage"', script)
        self.assertIn('install -m 0644', script)
        self.assertIn('runuser -u fasakha -- python3', script)


if __name__ == '__main__':
    unittest.main()
