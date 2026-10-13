#!/usr/bin/env python3
"""Isolated narrow profile-updater fixtures; no live PHP, database or network."""
import contextlib
import importlib.util
import io
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('meta_profile_updater', pathlib.Path(__file__).resolve().parents[1] / 'deployment/update_meta_profiles.py')
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)
RELEASE = 'a' * 40


class ProfileUpdaterTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-profile-update-test-')
        self.root = pathlib.Path(self.temporary.name) / 'project'
        self.root.mkdir()
        self.old_process = b'<?php // known previous installed processor\n'
        self.real_pin = updater.PREVIOUS_PROCESS
        self.real_foundations = dict(updater.FOUNDATION_BLOBS)
        self.pin = mock.patch.object(updater, 'PREVIOUS_PROCESS', updater.blob(self.old_process))
        self.pin.start()
        self.original = {
            'vendor/autoload.php': b'<?php // installed runtime\n',
            'bootstrap/app.php': b'<?php // installed bootstrap\n',
            'artisan': b'<?php // installed artisan\n',
            '.env': b'PRIVATE_SECRET=never-read-or-change\n',
            'bootstrap/cache/config.php': b'cached private configuration\n',
            'bootstrap/cache/routes-v7.php': b'cached installed routes\n',
            'routes/api.php': b'<?php // installed API routes\n',
            'routes/web.php': b'<?php // installed web routes\n',
            'resources/views/admin/layouts/menu.blade.php': b'installed customized sidebar\n',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': b'<?php // newer unrelated shared workflow\n',
            updater.PROCESS: self.old_process,
        }
        self.original.update({rel: ('<?php // installed foundation ' + rel + '\n').encode() for rel in updater.FOUNDATIONS})
        self.foundations = mock.patch.object(updater, 'FOUNDATION_BLOBS',
            {rel: updater.blob(self.original[rel]) for rel in updater.FOUNDATIONS})
        self.foundations.start()
        for rel, content in self.original.items(): self.write(rel, content, 0o640)
        self.payloads = {rel: ('<?php // pinned new profile code ' + rel + '\n').encode() for rel in updater.TARGETS}
        self.payloads.update({rel: self.original[rel] for rel in updater.FOUNDATIONS})
        self.calls = []
        self.fail_lint = None
        self.fail_runtime = None
        self.preflight_action = None
        self.verify_action = None
        self.output = ''
        self.lint_snapshots = []

    def tearDown(self):
        self.foundations.stop()
        self.pin.stop()
        self.temporary.cleanup()

    def write(self, rel, content, mode=0o644):
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def snapshot(self):
        return {str(path.relative_to(self.root)): (path.read_bytes(), path.stat().st_mode & 0o777)
                for path in self.root.rglob('*') if path.is_file()}

    def git_file(self, root, release, rel):
        self.assertEqual(root, self.root)
        self.assertEqual(release, RELEASE)
        self.assertIn(rel, updater.FOUNDATIONS + updater.TARGETS)
        return self.payloads[rel]

    def runner(self, args, root):
        self.calls.append(args)
        self.assertEqual(args[0], 'php')
        if args[1] == '-l':
            staged = pathlib.Path(args[2])
            self.assertTrue(staged.is_relative_to(self.root.parent / 'meta-release-backups'))
            self.assertEqual(staged.stat().st_mode & 0o777, 0o600)
            rel = str(staged).split('/staged/', 1)[1]
            self.assertEqual(staged.read_bytes(), self.payloads[rel])
            self.lint_snapshots.append(self.snapshot())
            return subprocess.CompletedProcess(args, int(rel == self.fail_lint), b'', b'private lint diagnostic')
        self.assertTrue(args[1].endswith('/verify-profile-runtime.php'))
        self.assertEqual(args[2], str(self.root))
        phase = args[3]
        action = self.preflight_action if phase == 'preflight' else self.verify_action
        if action: action()
        return subprocess.CompletedProcess(args, int(phase == self.fail_runtime),
            json.dumps({'ok': phase != self.fail_runtime}).encode(), b'private runtime diagnostic')

    def update(self):
        output = io.StringIO()
        with mock.patch.object(sys, 'argv', ['update', '--root', str(self.root), '--release', RELEASE, '--php', 'php']), \
             mock.patch.object(updater.os, 'geteuid', return_value=1001), \
             mock.patch.object(updater, 'git_file', side_effect=self.git_file), \
             mock.patch.object(updater, 'run', side_effect=self.runner), contextlib.redirect_stdout(output):
            try: updater.main()
            finally: self.output = output.getvalue()

    def test_known_previous_pin_is_the_reviewed_installed_blob(self):
        self.assertEqual(self.real_pin, 'c28b29c0f90be955b3d6d2ff5d82b88179550189')
        self.assertEqual(self.real_foundations['app/Services/Dashboard/MetaInboxAccess.php'],
                         '260b0a1cc93be44bf9e96f508b55420e5f639f64')
        self.assertEqual(set(self.real_foundations), set(updater.FOUNDATIONS))

    def test_later_release_foundation_edits_cannot_redefine_the_installed_baseline(self):
        before = self.snapshot()
        for rel in updater.FOUNDATIONS: self.payloads[rel] = b'<?php // future rich-chat foundation\n'
        self.update()
        for rel in updater.FOUNDATIONS: self.assertEqual(self.snapshot()[rel], before[rel])

    def test_updates_only_three_files_and_preserves_unrelated_bytes_permissions_and_inodes(self):
        before = self.snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        self.update()
        expected = dict(before)
        expected.update({rel: (self.payloads[rel], 0o640 if rel == updater.PROCESS else 0o644) for rel in updater.TARGETS})
        self.assertEqual(self.snapshot(), expected)
        for rel in before:
            if rel != updater.PROCESS: self.assertEqual((self.root / rel).stat().st_ino, inodes[rel], rel)
        self.assertEqual(self.lint_snapshots, [before] * 3, 'All lint checks precede publication')
        self.assertEqual([args[3] for args in self.calls if args[1] != '-l'], ['preflight', 'verify'])
        backup, = (self.root.parent / 'meta-release-backups').iterdir()
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        saved = backup / 'original' / updater.PROCESS
        self.assertEqual(saved.read_bytes(), self.old_process)
        self.assertEqual(saved.stat().st_mode & 0o777, 0o600)
        self.assertEqual((backup / 'verify-profile-runtime.php').stat().st_mode & 0o777, 0o600)
        self.assertIn('META_PROFILE_UPDATED=3', self.output)
        self.assertNotIn('private', self.output)

    def test_current_files_are_verified_without_rewriting_them(self):
        for rel in updater.TARGETS: self.write(rel, self.payloads[rel], 0o640)
        before = self.snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertEqual({rel: (self.root / rel).stat().st_ino for rel in before}, inodes)
        self.assertIn('META_PROFILE_UPDATED=0', self.output)

    def test_unknown_processor_is_rejected_before_backup_lint_or_publication(self):
        self.write(updater.PROCESS, b'<?php // locally changed processor\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_META_PROFILE_FILE'):
            self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_existing_unknown_new_service_is_rejected_without_any_mutation(self):
        service = updater.TARGETS[1]
        self.write(service, b'<?php // independent service\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_META_PROFILE_FILE'):
            self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_missing_processor_or_changed_foundation_is_rejected_before_publication(self):
        (self.root / updater.PROCESS).unlink()
        with self.assertRaisesRegex(RuntimeError, 'REQUIRED_META_PROCESSOR'): self.update()
        self.write(updater.PROCESS, self.old_process, 0o640)
        foundation = updater.FOUNDATIONS[0]
        self.write(foundation, b'<?php // incompatible foundation\n', 0o640)
        before = self.snapshot()
        with self.assertRaisesRegex(RuntimeError, 'META_FOUNDATION_DIFFERS'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_symlink_target_foundation_runtime_and_backup_are_rejected(self):
        for rel in (updater.TARGETS[1], updater.FOUNDATIONS[0], 'vendor/autoload.php'):
            with self.subTest(rel=rel):
                path = self.root / rel
                path.unlink(missing_ok=True)
                path.symlink_to(self.root / '.env')
                with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
                path.unlink()
                if rel in self.original: self.write(rel, self.original[rel], 0o640)
                self.assertEqual((self.root / '.env').read_bytes(), self.original['.env'])
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                self.assertEqual(self.calls, [])
        (self.root.parent / 'meta-release-backups').symlink_to(self.root)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
        self.assertEqual(self.snapshot(), {rel: (content, 0o640) for rel, content in self.original.items()})

    def test_lint_failure_happens_before_app_files_change(self):
        before = self.snapshot()
        self.fail_lint = updater.TARGETS[2]
        with self.assertRaisesRegex(RuntimeError, 'STAGED_PHP_LINT_FAILED'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertEqual(self.lint_snapshots, [before] * 3)
        self.assertFalse(any(args[1] != '-l' for args in self.calls))

    def test_unavailable_crypt_or_tables_stops_before_publication(self):
        before = self.snapshot()
        self.fail_runtime = 'preflight'
        with self.assertRaisesRegex(RuntimeError, 'META_PROFILE_RUNTIME_UNAVAILABLE'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertFalse(any(args[-1] == 'verify' for args in self.calls))

    def test_verification_failure_restores_previous_processor_and_removes_new_files(self):
        before = self.snapshot()
        self.fail_runtime = 'verify'
        with self.assertRaisesRegex(RuntimeError, 'META_PROFILE_RUNTIME_VERIFY_FAILED'): self.update()
        self.assertEqual(self.snapshot(), before)
        self.assertIn('META_PROFILE_CODE_RESTORED', self.output)
        self.assertNotIn('private runtime diagnostic', self.output)

    def test_concurrent_target_edit_is_preserved_before_any_publication(self):
        changed = b'<?php // concurrent operator processor edit\n'
        self.preflight_action = lambda: self.write(updater.PROCESS, changed, 0o640)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        expected = {rel: (content, 0o640) for rel, content in self.original.items()}
        expected[updater.PROCESS] = (changed, 0o640)
        self.assertEqual(self.snapshot(), expected)

    def test_rollback_preserves_later_operator_edit_and_reports_incomplete_restoration(self):
        changed = b'<?php // later operator service edit\n'
        self.verify_action = lambda: self.write(updater.TARGETS[1], changed, 0o640)
        self.fail_runtime = 'verify'
        with self.assertRaisesRegex(RuntimeError, 'META_PROFILE_RUNTIME_VERIFY_FAILED'): self.update()
        self.assertEqual((self.root / updater.PROCESS).read_bytes(), self.old_process)
        self.assertEqual((self.root / updater.TARGETS[1]).read_bytes(), changed)
        self.assertFalse((self.root / updater.TARGETS[2]).exists())
        self.assertIn('META_PROFILE_ROLLBACK_INCOMPLETE', self.output)


if __name__ == '__main__': unittest.main()
