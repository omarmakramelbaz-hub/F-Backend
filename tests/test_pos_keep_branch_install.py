#!/usr/bin/env python3
"""Installer fixtures use private Git repos, no application or database bootstrap."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('pos_keep_branch', ROOT / 'deployment/apply_pos_keep_branch.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name) / 'app'
        self.target = self.root / installer.SOURCE_PATH
        self.target.parent.mkdir(parents=True)
        self.before = (b'function switchInvoice(index, restoring) {\n' + installer.BEFORE + b'}\n')
        self.after = self.before.replace(installer.BEFORE, installer.AFTER)
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.target.write_bytes(self.before)
        self.git('add', '.')
        self.git('commit', '-qm', 'before')
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.target.write_bytes(self.after)
        self.git('add', '.')
        self.git('commit', '-qm', 'reviewed invoice guard')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        self.target.chmod(0o640)
        self.base_patch = mock.patch.object(installer, 'BASE_REVISION', self.base)
        self.base_patch.start()
        self.output = io.StringIO()
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.base_patch.stop()
        self.temporary.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], stderr=subprocess.DEVNULL).decode()

    def install(self, check=False):
        with contextlib.redirect_stdout(self.output):
            return installer.install(self.root, self.revision, check)

    def test_check_only_preserves_source_and_creates_no_receipt(self):
        with mock.patch.object(installer, 'lint') as lint:
            self.assertIsNone(self.install(check=True))
        self.assertEqual(self.target.read_bytes(), self.before)
        self.assertFalse(list(self.root.parent.glob('pos-keep-branch-backups/*/receipt.json')))
        lint.assert_called_once()

    def test_install_preserves_unrelated_live_changes_and_metadata_with_private_backup(self):
        live = b'// independent header fix\n' + self.before + b'// independent footer fix\n'
        self.target.write_bytes(live)
        backup = self.install()
        self.assertEqual(self.target.read_bytes(), live.replace(installer.BEFORE, installer.AFTER))
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o640)
        self.assertEqual((backup / 'takeaway-pos.js').read_bytes(), live)
        self.assertEqual((backup / 'takeaway-pos.js').stat().st_mode & 0o777, 0o600)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertFalse(receipt['database_bootstrapped'])
        self.assertFalse(receipt['print_command_run'])

    def test_repeat_is_idempotent(self):
        self.install()
        self.assertIsNone(self.install())
        self.assertEqual(self.target.read_bytes(), self.after)
        self.assertEqual(len(list(self.root.parent.glob('pos-keep-branch-backups/*/receipt.json'))), 1)

    def test_missing_and_ambiguous_anchor_refuse_source_changes(self):
        for live in (b'// unexpected switch implementation\n', self.before + self.before):
            self.target.write_bytes(live)
            with self.assertRaisesRegex(RuntimeError, 'LIVE_ANCHOR_MISMATCH'):
                self.install()
            self.assertEqual(self.target.read_bytes(), live)

    def test_release_with_unreviewed_file_edits_is_rejected(self):
        self.git('switch', '--detach', self.revision)
        self.target.write_bytes(self.after + b'// additional release edit\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'unreviewed edit')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        self.target.write_bytes(self.before)
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_SCOPE_MISMATCH'):
            self.install()
        self.assertEqual(self.target.read_bytes(), self.before)

    def test_lint_failure_preserves_live_source(self):
        with mock.patch.object(installer, 'lint', side_effect=RuntimeError('CANDIDATE_PARSE_FAILED')):
            with self.assertRaisesRegex(RuntimeError, 'PARSE_FAILED'):
                self.install()
        self.assertEqual(self.target.read_bytes(), self.before)

    def test_concurrent_change_during_preflight_is_preserved(self):
        changed = self.before + b'// concurrent live update\n'
        def lint(*args):
            self.target.write_bytes(changed)
        with mock.patch.object(installer, 'lint', side_effect=lint):
            with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED_DURING_PREFLIGHT'):
                self.install()
        self.assertEqual(self.target.read_bytes(), changed)

    def test_failed_publish_after_replace_rolls_back_original(self):
        replace = installer.replace
        called = False
        def failed_publish(path, body, info):
            nonlocal called
            replace(path, body, info)
            if not called:
                called = True
                raise OSError('fixture fsync error after replacement')
        with mock.patch.object(installer, 'replace', side_effect=failed_publish):
            with self.assertRaises(OSError):
                self.install()
        self.assertEqual(self.target.read_bytes(), self.before)
        receipts = list(self.root.parent.glob('pos-keep-branch-backups/*/receipt.json'))
        self.assertEqual(json.loads(receipts[0].read_text())['status'], 'rolled_back')

    def test_symlink_and_hardlink_are_rejected(self):
        saved = self.target.with_suffix('.saved')
        self.target.rename(saved)
        self.target.symlink_to(saved)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        self.target.unlink()
        saved.rename(self.target)
        os.link(self.target, saved)
        with self.assertRaisesRegex(RuntimeError, 'OWNED_REGULAR_FILE_REQUIRED'):
            self.install()
        self.assertEqual(self.target.read_bytes(), self.before)


if __name__ == '__main__':
    unittest.main()
