#!/usr/bin/env python3
"""Private Git fixtures; no application, database, or printer is loaded."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('pos_menu_shift_update', ROOT / 'deployment/apply_pos_menu_shift_update.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name) / 'app'
        self.root.mkdir()
        self.before = {}
        self.after = {}
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        for number, name in enumerate(installer.SOURCE_PATHS):
            prefix = '<?php\n' if name.endswith('.php') else ''
            before = (prefix + '// independent heading\n\nfunction fixture' + str(number) + '() {\n'
                      + '    return "original";\n}\n\n// stable tail one\n// stable tail two\n// stable tail three\n').encode()
            self.before[name] = before
            self.after[name] = before.replace(b'return "original";', b'return "reviewed";')
            self.write(name, before)
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.base = self.git('rev-parse', 'HEAD').strip()
        for name, body in self.after.items():
            self.write(name, body)
        self.git('add', '.')
        self.git('commit', '-qm', 'reviewed release')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        self.git('reset', '--hard', self.base)
        for name in installer.SOURCE_PATHS:
            (self.root / name).chmod(0o640)
        self.base_patch = mock.patch.object(installer, 'BASE_REVISION', self.base)
        self.base_patch.start()
        self.lint_patch = mock.patch.object(installer, 'lint')
        self.lint_mock = self.lint_patch.start()
        self.old_umask = os.umask(0o077)

    def tearDown(self):
        os.umask(self.old_umask)
        self.lint_patch.stop()
        self.base_patch.stop()
        self.temporary.cleanup()

    def git(self, *arguments):
        return subprocess.check_output(['git', '-C', str(self.root), *arguments], stderr=subprocess.DEVNULL).decode()

    def write(self, name, body):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)

    def install(self, check=False):
        with contextlib.redirect_stdout(io.StringIO()):
            return installer.install(self.root, self.revision, 'fixture-php', check)

    def assert_originals(self):
        for name, body in self.before.items():
            self.assertEqual((self.root / name).read_bytes(), body)

    def receipts(self):
        return list(self.root.parent.glob('pos-menu-shift-update-backups/*/receipt.json'))

    def test_preflight_parses_all_candidates_without_source_or_receipt_changes(self):
        self.assertIsNone(self.install(check=True))
        self.assert_originals()
        self.assertFalse(self.receipts())
        self.assertEqual(set(self.lint_mock.call_args.args[0]), set(installer.SOURCE_PATHS))

    def test_preserves_unrelated_live_edits_and_modes_with_private_backups(self):
        for name in installer.SOURCE_PATHS:
            self.write(name, self.before[name] + b'// independent live addition\n')
        backup = self.install()
        for name in installer.SOURCE_PATHS:
            self.assertEqual((self.root / name).read_bytes(), self.after[name] + b'// independent live addition\n')
            self.assertEqual((self.root / name).stat().st_mode & 0o777, 0o640)
            saved = backup / 'original' / name
            self.assertEqual(saved.read_bytes(), self.before[name] + b'// independent live addition\n')
            self.assertEqual(saved.stat().st_mode & 0o777, 0o600)
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(receipt['status'], 'installed')
        self.assertEqual(set(receipt['files']), set(installer.SOURCE_PATHS))
        self.assertFalse(receipt['database_bootstrapped'])
        self.assertFalse(receipt['print_command_run'])
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)

    def test_repeat_is_idempotent(self):
        self.install()
        self.assertIsNone(self.install())
        self.assertEqual(len(self.receipts()), 1)

    def test_conflict_refuses_all_writes(self):
        name = installer.SOURCE_PATHS[-1]
        conflicting = self.before[name].replace(b'return "original";', b'return "live independent behavior";')
        self.write(name, conflicting)
        with self.assertRaisesRegex(RuntimeError, 'LIVE_MERGE_CONFLICT'):
            self.install()
        for other in installer.SOURCE_PATHS[:-1]:
            self.assertEqual((self.root / other).read_bytes(), self.before[other])
        self.assertEqual((self.root / name).read_bytes(), conflicting)
        self.assertFalse(self.receipts())

    def test_candidate_lint_failure_refuses_all_writes(self):
        self.lint_mock.side_effect = RuntimeError('fixture parser error')
        with self.assertRaisesRegex(RuntimeError, 'parser error'):
            self.install()
        self.assert_originals()

    def test_concurrent_change_during_preflight_is_preserved(self):
        name = installer.SOURCE_PATHS[0]
        self.lint_mock.side_effect = lambda *arguments: self.write(name, b'concurrent source change\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_CHANGED_DURING_PREFLIGHT'):
            self.install()
        self.assertEqual((self.root / name).read_bytes(), b'concurrent source change\n')
        for other in installer.SOURCE_PATHS[1:]:
            self.assertEqual((self.root / other).read_bytes(), self.before[other])

    def test_partial_publish_failure_rolls_back_in_reverse_order(self):
        original_replace = installer.replace
        attempted = []
        def fail_third(path, body, info):
            attempted.append(str(path.relative_to(self.root)))
            if len(attempted) == 3:
                raise OSError('fixture publish error')
            return original_replace(path, body, info)
        with mock.patch.object(installer, 'replace', side_effect=fail_third):
            with self.assertRaises(OSError):
                self.install()
        self.assert_originals()
        self.assertEqual(attempted[-2:], list(reversed(installer.SOURCE_PATHS[:2])))
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_post_replace_failure_is_rolled_back(self):
        original_replace = installer.replace
        called = False
        def fail_after_replace(path, body, info):
            nonlocal called
            original_replace(path, body, info)
            if not called:
                called = True
                raise OSError('fixture fsync error')
        with mock.patch.object(installer, 'replace', side_effect=fail_after_replace):
            with self.assertRaises(OSError):
                self.install()
        self.assert_originals()
        self.assertEqual(json.loads(self.receipts()[0].read_text())['status'], 'rolled_back')

    def test_rollback_preserves_concurrent_edits_and_records_review(self):
        original_replace = installer.replace
        calls = 0
        name = installer.SOURCE_PATHS[0]
        def fail_second(path, body, info):
            nonlocal calls
            calls += 1
            if calls == 2:
                self.write(name, b'concurrent published source\n')
                raise OSError('fixture publish error')
            original_replace(path, body, info)
        with mock.patch.object(installer, 'replace', side_effect=fail_second):
            with self.assertRaises(OSError):
                self.install()
        self.assertEqual((self.root / name).read_bytes(), b'concurrent published source\n')
        receipt = json.loads(self.receipts()[0].read_text())
        self.assertEqual(receipt['status'], 'rollback_needs_review')
        self.assertEqual(receipt['review_files'], [name])

    def test_isolated_git_dir_ignores_bad_live_remote_ref(self):
        isolated = self.root.parent / 'isolated.git'
        shutil.copytree(self.root / '.git', isolated)
        bad = self.root / '.git/refs/remotes/origin/backup/2026-10-20-all-apps'
        bad.parent.mkdir(parents=True)
        bad.write_text('1' * 40 + '\n')
        with mock.patch.dict(os.environ, {'GIT_DIR': str(isolated)}):
            self.install(check=True)
            self.install()
        self.assertEqual(bad.read_text(), '1' * 40 + '\n')
        for name in installer.SOURCE_PATHS:
            self.assertEqual((self.root / name).read_bytes(), self.after[name])

    def test_wrong_release_parent_is_rejected(self):
        self.git('switch', '--detach', self.revision)
        self.write('unrelated.txt', b'extra release\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'unexpected additional commit')
        self.revision = self.git('rev-parse', 'HEAD').strip()
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_PARENT_MISMATCH'):
            self.install()

    def test_symlink_and_hardlink_sources_are_rejected(self):
        name = installer.SOURCE_PATHS[0]
        target = self.root / name
        saved = target.with_suffix('.saved')
        target.rename(saved)
        target.symlink_to(saved)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_REJECTED'):
            self.install()
        target.unlink()
        saved.rename(target)
        os.link(target, saved)
        with self.assertRaisesRegex(RuntimeError, 'OWNED_REGULAR_FILE_REQUIRED'):
            self.install()


if __name__ == '__main__':
    unittest.main()
