#!/usr/bin/env python3
import importlib.util
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch


REPO = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('family_installer', REPO / 'deployment/apply_order_family_resolution.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)
PHP = os.environ.get('GO_ORDER_FIX_TEST_PHP') or shutil.which('php')


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.assertTrue(PHP, 'PHP CLI is required for real syntax validation')
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name) / 'site'
        self.target = self.root / INSTALLER.RELATIVE
        self.target.parent.mkdir(parents=True)
        current = (REPO / INSTALLER.RELATIVE).read_bytes()
        self.assertEqual(current.count(INSTALLER.AFTER), 1)
        self.before = current.replace(INSTALLER.AFTER, INSTALLER.BEFORE)
        self.target.write_bytes(self.before)
        self.target.chmod(0o640)
        self.backups = self.root.parent / 'go-erp-order-family-fix'

    def test_check_lints_real_source_without_publishing_or_backing_it_up(self):
        result = INSTALLER.apply(self.root, PHP, False)
        self.assertEqual(result['marker'], 'GO_ERP_ORDER_FAMILY_RESOLUTION_CHECKED')
        self.assertEqual(self.target.read_bytes(), self.before)
        self.assertEqual(list(self.backups.glob('*.php')), [])

    def test_apply_preserves_adjacent_edits_permissions_backup_and_safe_rerun(self):
        before = self.before + b'\n// Independently deployed local customization.\n'
        self.target.write_bytes(before)
        result = INSTALLER.apply(self.root, PHP, True)
        expected = before.replace(INSTALLER.BEFORE, INSTALLER.AFTER)
        self.assertTrue(result['changed'])
        self.assertEqual(self.target.read_bytes(), expected)
        self.assertEqual(stat.S_IMODE(self.target.stat().st_mode), 0o640)
        backup = Path(result['backup'])
        self.assertEqual(backup.read_bytes(), before)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o600)
        inode = self.target.stat().st_ino
        repeat = INSTALLER.apply(self.root, PHP, True)
        self.assertFalse(repeat['changed'])
        self.assertEqual(self.target.stat().st_ino, inode)
        self.assertEqual(list(self.backups.glob('*.php')), [backup])

    def test_duplicate_or_unknown_source_is_never_changed(self):
        for source in [self.before + INSTALLER.BEFORE, self.before.replace(INSTALLER.BEFORE, b'// Different resolver.\n')]:
            with self.subTest(source=source[-40:]):
                self.target.write_bytes(source)
                with self.assertRaisesRegex(RuntimeError, 'HUNK_NOT_UNIQUE'):
                    INSTALLER.apply(self.root, PHP, True)
                self.assertEqual(self.target.read_bytes(), source)
                self.assertEqual(list(self.backups.glob('*.php')), [])

    def test_real_php_syntax_failure_leaves_the_original_file_intact(self):
        broken = self.before + b'\nfunction invalid_syntax( {\n'
        self.target.write_bytes(broken)
        with self.assertRaisesRegex(RuntimeError, 'PHP_SYNTAX_FAILED'):
            INSTALLER.apply(self.root, PHP, True)
        self.assertEqual(self.target.read_bytes(), broken)
        self.assertEqual(list(self.backups.glob('*.php')), [])

    def test_source_changed_while_linting_is_not_overwritten(self):
        changed = self.before + b'\n// Changed by another deployment during review.\n'
        def lint(*args, **kwargs):
            self.target.write_bytes(changed)
            return subprocess.CompletedProcess(args, 0)
        with patch.object(INSTALLER.subprocess, 'run', side_effect=lint):
            with self.assertRaisesRegex(RuntimeError, 'CHANGED_DURING_REVIEW'):
                INSTALLER.apply(self.root, PHP, True)
        self.assertEqual(self.target.read_bytes(), changed)
        self.assertEqual(list(self.backups.glob('*.php')), [])

    def test_symlink_target_is_not_followed(self):
        other = self.root / 'other.php'
        other.write_bytes(self.before)
        self.target.unlink()
        self.target.symlink_to(other)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_TARGET'):
            INSTALLER.apply(self.root, PHP, True)
        self.assertEqual(other.read_bytes(), self.before)


if __name__ == '__main__':
    unittest.main(verbosity=2)
