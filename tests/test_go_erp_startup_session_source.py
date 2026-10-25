"""Exercise the two-file transaction on real inodes and atomic exchanges."""
import ctypes
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('startup_session_source', ROOT/'deployment/go_erp_startup_session_source_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)


def exchange(left_fd, left, right_fd, right):
    function = ctypes.CDLL(None, use_errno=True).renameat2
    function.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    function.restype = ctypes.c_int
    if function(left_fd, os.fsencode(left), right_fd, os.fsencode(right), 2):
        raise OSError(ctypes.get_errno(), 'atomic exchange failed')


class SourceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='startup-session-source-'); self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name); self.root = self.folder/'live'; self.audit = self.folder/'audit'
        self.root.mkdir(); self.audit.mkdir(mode=0o700)
        self.entries = {}; self.web_uid = 65534
        for index, path in enumerate(subject.PATHS):
            file = self.root/path; file.parent.mkdir()
            before = ('<?php // original '+path+'\n').encode(); after = before+b'// reviewed fix\n'
            file.write_bytes(before); file.chmod(0o755 if index == 0 else 0o644)
            try: os.chown(file, self.web_uid, self.web_uid)
            except OSError as error:
                if error.errno not in (1, 22): raise
                self.web_uid = 0; os.chown(file, 0, 0)
            os.utime(file, ns=(1_700_000_000_000_000_000, 1_700_000_001_000_000_000))
            os.setxattr(file, 'user.go-source-test', b'preserved')
            self.entries[path] = {'before': before, 'after': after, 'mode': '100755' if index == 0 else '100644'}
        self.before = {path: (self.root/path).stat() for path in subject.PATHS}
        self.binding = {'input': '7fc29a9e0702cf3a5f4bcea5e2ce7c6d46713c6c', 'core': 'a'*40,
                        'seed': 'b'*40, 'before_inventory': 'c'*64, 'target_inventory': 'd'*64}
        self.transaction = subject.Transaction(self.root, self.audit, self.web_uid, self.entries, self.binding, exchange)

    def assert_old(self):
        for path, row in self.entries.items():
            info = (self.root/path).stat()
            self.assertEqual(subject.stable(info), subject.stable(self.before[path]))
            self.assertEqual((self.root/path).read_bytes(), row['before'])

    def assert_new(self):
        for path, row in self.entries.items():
            info = (self.root/path).stat(); before = self.before[path]
            self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_mtime_ns),
                             (before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode), before.st_mtime_ns))
            self.assertEqual((self.root/path).read_bytes(), row['after'])
            self.assertEqual(os.getxattr(self.root/path, 'user.go-source-test'), b'preserved')

    def test_distinct_web_ownership_is_exercised_on_real_root_ci(self):
        if self.web_uid == 0:
            if os.environ.get('GO_ERP_TEST_REQUIRE_REAL_CHOWN') == '1':
                self.fail('distinct web ownership must execute on the root CI runner')
            self.skipTest('local user namespace maps only uid0; CI requires actual web uid')
        self.transaction.apply(lambda: None)
        self.assertTrue(all((self.root/path).stat().st_uid == self.web_uid for path in subject.PATHS))

    def test_exact_two_files_preserve_owner_group_executable_mode_times_and_xattrs(self):
        syntax = []
        result = self.transaction.apply(lambda: None, syntax=lambda path: syntax.append(path.read_bytes()))
        self.assert_new(); self.assertEqual(len(syntax), 2); self.assertTrue(result['applied'])
        self.assertEqual(stat.S_IMODE(self.audit.stat().st_mode), 0o700)
        self.assertEqual((self.audit/'intent.json').stat().st_uid, 0)
        self.assertEqual(stat.S_IMODE((self.audit/'intent.json').stat().st_mode), 0o600)
        for index, path in enumerate(subject.PATHS):
            self.assertEqual((self.audit/('exchange-'+str(index))).stat().st_ino, self.before[path].st_ino)

    def test_completed_source_retry_is_idempotent_and_does_not_exchange_again(self):
        first = self.transaction.apply(lambda: None); original = self.transaction.exchange
        self.transaction.exchange = lambda *args: self.fail('already applied source must not exchange')
        self.assertEqual(self.transaction.apply(lambda: None), first)
        self.assertEqual(self.transaction.verify(lambda: None), first)
        self.transaction.exchange = original

    def test_failure_after_either_exchange_rolls_back_both_recognized_inodes(self):
        for target in subject.PATHS:
            def event(phase, path):
                if phase == 'after-exchange' and path == target: raise RuntimeError('injected interruption')
            with self.assertRaisesRegex(RuntimeError, 'injected interruption'):
                self.transaction.apply(lambda: None, event=event)
            self.assert_old()

    def test_failed_full_target_scan_rolls_back_and_retry_can_finish(self):
        def reject(): raise RuntimeError('full inventory changed')
        with self.assertRaisesRegex(RuntimeError, 'full inventory changed'): self.transaction.apply(reject)
        self.assert_old(); self.transaction.apply(lambda: None); self.assert_new()

    def test_failed_stage_syntax_never_mutates_live_source(self):
        def reject(path): raise RuntimeError('syntax rejected')
        with self.assertRaisesRegex(RuntimeError, 'syntax rejected'): self.transaction.apply(lambda: None, syntax=reject)
        self.assert_old(); self.assertFalse((self.audit/'intent.json').exists())
        self.transaction.apply(lambda: None); self.assert_new()

    def test_resume_accepts_each_known_partial_exchange_permutation(self):
        self.transaction.apply(lambda: None)
        # Simulate SIGKILL before a journal completion: leave any known permutation.
        for changed in ((), (0,), (1,), (0, 1)):
            for index in changed:
                path = subject.PATHS[index]
                with subject.Directory(self.audit, self.web_uid, private=True) as audit, subject.Directory((self.root/path).parent, self.web_uid) as parent:
                    exchange(audit.fd, 'exchange-'+str(index), parent.fd, Path(path).name)
            self.transaction.apply(lambda: None); self.assert_new()

    def test_unknown_active_or_displaced_content_never_gets_overwritten(self):
        self.transaction.apply(lambda: None)
        path = self.audit/'exchange-0'; path.write_bytes(b'concurrent writer through old fd')
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_RECOVERY_REQUIRES_REVIEW'):
            self.transaction.apply(lambda: None)
        self.assert_new(); self.assertEqual(path.read_bytes(), b'concurrent writer through old fd')

    def test_unknown_live_file_is_refused_before_any_other_source_change(self):
        path = self.root/subject.PATHS[1]; path.write_bytes(b'unknown source')
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_BEFORE_CHANGED'): self.transaction.apply(lambda: None)
        self.assertEqual((self.root/subject.PATHS[0]).stat().st_ino, self.before[subject.PATHS[0]].st_ino)
        self.assertEqual(path.read_bytes(), b'unknown source')

    def test_bound_candidate_core_seed_or_target_inventory_cannot_change_on_resume(self):
        self.transaction.apply(lambda: None)
        self.transaction.binding = {**self.binding, 'core': 'f'*40}
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_INTENT_CHANGED'): self.transaction.apply(lambda: None)
        self.assert_new()

    def test_source_symlink_hardlink_or_parent_symlink_is_refused(self):
        path = self.root/subject.PATHS[0]; original = path.read_bytes(); path.unlink()
        outside = self.folder/'outside'; outside.write_bytes(original); path.symlink_to(outside)
        with self.assertRaises(OSError): self.transaction.apply(lambda: None)
        path.unlink(); os.link(outside, path)
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_FILE_UNSAFE'): self.transaction.apply(lambda: None)
        path.unlink(); path.write_bytes(original); path.chmod(0o755); os.chown(path, self.web_uid, self.web_uid)
        moved = self.root/'config-real'; path.parent.rename(moved); (self.root/'config').symlink_to(moved, target_is_directory=True)
        with self.assertRaises(OSError): self.transaction.apply(lambda: None)

    def test_metadata_or_acl_changes_to_a_held_inode_require_review(self):
        self.transaction.apply(lambda: None)
        os.setxattr(self.audit/'exchange-1', 'user.go-source-test', b'changed')
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_RECOVERY_REQUIRES_REVIEW'): self.transaction.verify(lambda: None)
        self.assert_new()

    def test_verify_after_native_preparation_never_rolls_back_on_scan_failure(self):
        self.transaction.apply(lambda: None)
        def reject(): raise RuntimeError('later server drift')
        with self.assertRaisesRegex(RuntimeError, 'later server drift'): self.transaction.verify(reject)
        self.assert_new()

    def test_extra_paths_or_unchanged_payloads_are_refused(self):
        extra = {**self.entries, 'config/app.php': self.entries[subject.PATHS[0]]}
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_SCOPE_INVALID'):
            subject.Transaction(self.root, self.audit, self.web_uid, extra, self.binding, exchange)
        same = {path: {**row, 'after': row['before']} for path, row in self.entries.items()}
        with self.assertRaisesRegex(subject.Stop, 'SOURCE_SCOPE_INVALID'):
            subject.Transaction(self.root, self.audit, self.web_uid, same, self.binding, exchange)


if __name__ == '__main__': unittest.main()
