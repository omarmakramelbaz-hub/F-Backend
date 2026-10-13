#!/usr/bin/env python3
"""Exercise the narrow owner repair without real ownership changes or root."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import tempfile
import types
import unittest
from unittest import mock


SOURCE = pathlib.Path(__file__).resolve().parents[1]/'deployment/repair_whatsapp_order_config_owners_root.py'
SPEC = importlib.util.spec_from_file_location('owner_repair', SOURCE)
repair = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(repair)


class OwnerRepairTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = pathlib.Path(self.temp.name)
        self.root = self.base/'public_html'
        (self.root/'config').mkdir(parents=True)
        self.backups = self.base/'whatsapp-release-backups'
        self.backups.mkdir(mode=0o700)
        self.uid, self.gid = os.getuid() or 1001, os.getgid() or 1001
        for name in repair.FILES:
            (self.root/'config'/name).write_text('private-settings-'+name)
            (self.root/'config'/name).chmod(0o644)
        (self.root/'config/untouched.php').write_text('unrelated-secret')
        for name in repair.LOCKS:
            (self.backups/name).write_bytes(b'')
            (self.backups/name).chmod(0o600)
        self.owners = {}
        for path in [self.base, self.root, self.root/'config', self.backups] + list(self.backups.iterdir()):
            self.owners[self.key(path.lstat())] = (self.uid, self.gid)
        for name in repair.FILES:
            self.owners[self.key((self.root/'config'/name).lstat())] = (0, 0)
        self.calls = []
        self.before = {p.name: (p.read_bytes(), p.stat().st_mode, p.stat().st_mtime_ns)
                       for p in (self.root/'config').iterdir()}

    @staticmethod
    def key(info):
        return info.st_dev, info.st_ino

    def fake_info(self, info):
        uid, gid = self.owners.get(self.key(info), (0, 0))
        values = {name: getattr(info, name) for name in (
            'st_dev', 'st_ino', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')}
        return types.SimpleNamespace(st_uid=uid, st_gid=gid, **values)

    def chown(self, fd, uid, gid):
        self.calls.append((self.key(os.fstat(fd)), uid, gid))
        self.owners[self.key(os.fstat(fd))] = (uid, gid)

    def invoke(self, chown=None, hook=None):
        real_lstat, real_stat, real_fstat = os.lstat, os.stat, os.fstat
        with mock.patch.object(repair, '_fstat', side_effect=lambda fd: self.fake_info(real_fstat(fd))), \
             mock.patch.object(repair, '_lstat', side_effect=lambda p: self.fake_info(real_lstat(p))), \
             mock.patch.object(repair, '_stat_at', side_effect=lambda n, fd: self.fake_info(real_stat(n, dir_fd=fd, follow_symlinks=False))):
            return repair.repair(self.root, self.uid, self.gid, chown=chown or self.chown, hook=hook)

    def assert_unchanged_bytes_and_modes(self):
        after = {p.name: (p.read_bytes(), p.stat().st_mode, p.stat().st_mtime_ns)
                 for p in (self.root/'config').iterdir()}
        self.assertEqual(after, self.before)

    def test_changes_exactly_four_and_preserves_contents_modes_and_mtime(self):
        result = self.invoke()
        self.assertEqual(result['changed'], 4)
        self.assertEqual(result['status'], 'WHATSAPP_ORDER_CONFIG_OWNERS_READY')
        expected_keys = {self.key((self.root/'config'/n).lstat()) for n in repair.FILES}
        self.assertEqual({call[0] for call in self.calls}, expected_keys)
        self.assertTrue(all(call[1:] == (self.uid, self.gid) for call in self.calls))
        self.assert_unchanged_bytes_and_modes()
        journal = pathlib.Path(result['backup'])/'journal.jsonl'
        rows = [json.loads(line) for line in journal.read_text().splitlines()]
        self.assertEqual(journal.stat().st_mode & 0o7777, 0o600)
        self.assertEqual(journal.parent.stat().st_mode & 0o7777, 0o700)
        self.assertEqual(len(rows[0]['files']), 4)
        self.assertNotIn('private-settings', journal.read_text())
        for row in rows[0]['files']:
            self.assertEqual(row['sha256'], hashlib.sha256((self.root/row['path']).read_bytes()).hexdigest())
        self.assertNotIn('private-settings', json.dumps(result))

    def test_idempotent_second_run_creates_no_backup_or_changes(self):
        self.invoke()
        backups = set(self.backups.iterdir())
        self.calls.clear()
        result = self.invoke()
        self.assertEqual(result['status'], 'WHATSAPP_ORDER_CONFIG_OWNERS_ALREADY_READY')
        self.assertEqual(result['changed'], 0)
        self.assertIsNone(result['backup'])
        self.assertEqual(self.calls, [])
        self.assertEqual(set(self.backups.iterdir()), backups)

    def test_mixed_already_correct_owners_changes_only_remaining_files(self):
        self.owners[self.key((self.root/'config'/repair.FILES[0]).lstat())] = (self.uid, self.gid)
        self.assertEqual(self.invoke()['changed'], 3)
        self.assertEqual(len(self.calls), 3)

    def test_bad_profile_validated_before_any_change_or_backup(self):
        for name in repair.FILES:
            target = self.root/'config'/name
            target.chmod(0o664)
            with self.assertRaisesRegex(repair.SafeError, 'FILE_PROFILE_NEEDS_REVIEW'):
                self.invoke()
            target.chmod(0o644)
        self.assertEqual(self.calls, [])
        self.assertFalse(list(self.backups.glob('config-owners-*')))

    def test_foreign_owner_is_not_reassigned(self):
        self.owners[self.key((self.root/'config/jwt.php').lstat())] = (9999, 9999)
        with self.assertRaisesRegex(repair.SafeError, 'FILE_PROFILE_NEEDS_REVIEW'):
            self.invoke()
        self.assertEqual(self.calls, [])

    def test_hardlinked_file_is_rejected_before_change(self):
        os.link(self.root/'config/mail.php', self.base/'mail-hardlink')
        with self.assertRaisesRegex(repair.SafeError, 'FILE_PROFILE_NEEDS_REVIEW'):
            self.invoke()
        self.assertEqual(self.calls, [])

    def test_file_symlink_never_changes_its_target(self):
        outside = self.base/'outside.php'
        outside.write_text('outside-secret')
        target = self.root/'config/mail.php'
        target.unlink()
        target.symlink_to(outside)
        with self.assertRaisesRegex(repair.SafeError, 'STOPPED_BEFORE_CHANGES'):
            self.invoke()
        self.assertEqual(self.calls, [])
        self.assertEqual(outside.read_text(), 'outside-secret')

    def test_config_symlink_blocks_all_changes(self):
        config = self.root/'config'
        config.rename(self.root/'actual-config')
        config.symlink_to(self.root/'actual-config', target_is_directory=True)
        with self.assertRaisesRegex(repair.SafeError, 'STOPPED_BEFORE_CHANGES'):
            self.invoke()
        self.assertEqual(self.calls, [])

    def test_second_chown_failure_rolls_back_only_own_change(self):
        count = 0
        def faulty(fd, uid, gid):
            nonlocal count
            if uid == self.uid:
                count += 1
                if count == 2:
                    raise RuntimeError('private-error')
            self.chown(fd, uid, gid)
        with self.assertRaisesRegex(repair.SafeError, 'FAILED_FILES_RESTORED'):
            self.invoke(chown=faulty)
        self.assertTrue(all(self.owners[self.key((self.root/'config'/n).lstat())] == (0, 0) for n in repair.FILES))
        self.assert_unchanged_bytes_and_modes()

    def test_exception_after_chown_effect_also_rolls_back(self):
        fired = False
        def faulty(fd, uid, gid):
            nonlocal fired
            self.chown(fd, uid, gid)
            if uid == self.uid and not fired:
                fired = True
                raise RuntimeError('private-error')
        with self.assertRaisesRegex(repair.SafeError, 'FAILED_FILES_RESTORED'):
            self.invoke(chown=faulty)
        self.assertTrue(all(self.owners[self.key((self.root/'config'/n).lstat())] == (0, 0) for n in repair.FILES))

    def test_concurrent_content_edit_is_retained_and_not_rolled_back(self):
        def hook(stage, name):
            if name == repair.FILES[1]:
                (self.root/'config'/repair.FILES[0]).write_text('new-concurrent-settings')
        with self.assertRaisesRegex(repair.SafeError, 'PARTIAL_REVIEW_REQUIRED'):
            self.invoke(hook=hook)
        first = self.root/'config'/repair.FILES[0]
        self.assertEqual(first.read_text(), 'new-concurrent-settings')
        self.assertEqual(self.owners[self.key(first.lstat())], (self.uid, self.gid))

    def test_path_replacement_does_not_chown_replacement(self):
        replaced = None
        def hook(stage, name):
            nonlocal replaced
            if name == repair.FILES[1]:
                first = self.root/'config'/repair.FILES[0]
                first.rename(self.base/'displaced.php')
                first.write_text('replacement-settings')
                replaced = self.key(first.lstat())
        with self.assertRaisesRegex(repair.SafeError, 'PARTIAL_REVIEW_REQUIRED'):
            self.invoke(hook=hook)
        self.assertNotIn(replaced, {call[0] for call in self.calls})
        self.assertEqual((self.root/'config'/repair.FILES[0]).read_text(), 'replacement-settings')

    def test_main_suppresses_errors_and_rejects_nonroot_execution(self):
        for arguments in ([], ['--root', '/private/SECRET'], ['--root', str(repair.APP_ROOT)]):
            output = io.StringIO()
            with mock.patch.object(os, 'geteuid', return_value=1001), contextlib.redirect_stdout(output):
                status = repair.main(arguments)
            self.assertEqual(status, 1)
            self.assertNotIn('SECRET', output.getvalue())
            self.assertEqual(json.loads(output.getvalue())['status'], 'FAILED')


if __name__ == '__main__':
    unittest.main()
