"""Temporary-directory proofs for the one-file importer deployment helper."""
import fcntl
import errno
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile
import types
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
TARGET = Path('app/Services/Dashboard/DesktopDashboardImport.php')
SCANNER = Path('deployment/refresh_desktop_dashboard_server_manifest.py')
HELPER = ROOT / 'deployment/apply_go_erp_import_memory_server_root.py'


def module_from(path, name):
    module = types.ModuleType(name)
    module.__file__ = str(path)
    exec(compile(path.read_bytes(), str(path), 'exec'), module.__dict__)
    return module


@unittest.skipUnless(os.geteuid() == 0 and hasattr(os, 'O_NOFOLLOW'),
                     'Root/Linux ownership and atomic-exchange proof')
class ImportMemoryServerDeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-import-server-test-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.live = self.base / 'live'
        self.review = self.base / 'review'
        self.work = self.base / 'work'
        for folder in (self.live, self.review, self.work):
            folder.mkdir(mode=0o700)
        self.work.chmod(0o700)
        self.lock = self.work / 'watcher.lock'
        self.lock.write_bytes(b'')
        self.lock.chmod(0o600)
        self.old = b'<?php\n// exact old importer\nreturn 1;\n'
        self.new = b'<?php\n// reviewed projected validation\nreturn 2;\n'
        for folder, payload in ((self.live, self.old), (self.review, self.new)):
            (folder / TARGET).parent.mkdir(parents=True)
            (folder / TARGET).write_bytes(payload)
            (folder / 'artisan').write_bytes(b'<?php\n// common public source\n')
        self.target = self.live / TARGET
        self.target.chmod(0o640)
        self.owner = (1001, 1001)
        try:
            os.chown(self.target, *self.owner)
        except OSError as error:
            if error.errno not in (errno.EINVAL, errno.EPERM):
                raise
            # User namespaces may expose uid0 without mapping the hosting uid.
            # The file-owner preservation proof still runs for the mapped uid.
            self.owner = (os.geteuid(), os.getegid())
            os.chown(self.target, *self.owner)
        os.chown(self.live, *self.owner)
        (self.review / SCANNER).parent.mkdir(parents=True)
        shutil.copyfile(ROOT / SCANNER, self.review / SCANNER)
        (self.review / SCANNER).chmod(0o644)
        self.scanner = module_from(ROOT / SCANNER, 'fixture_source_scanner')
        self.helper = module_from(HELPER, 'fixture_import_server_helper')
        self.constants = patch.multiple(
            self.helper,
            OLD_SHA=hashlib.sha256(self.old).hexdigest(),
            NEW_SHA=hashlib.sha256(self.new).hexdigest(),
            BEFORE_SOURCE=self.scanner.digest(self.scanner.scan(self.live)),
            AFTER_SOURCE=self.scanner.digest(self.scanner.scan(self.review)),
        )
        self.constants.start()
        self.addCleanup(self.constants.stop)
        self.lint_calls = []

    def lint(self, data):
        self.assertIsInstance(data, bytes)
        self.assertEqual(data, self.new)
        self.lint_calls.append(data)

    def deploy(self, **kwargs):
        return self.helper.deploy(self.review, self.live, self.work,
                                  lint=self.lint, web_uid=self.owner[0], **kwargs)

    def assert_pending(self, report):
        self.assertIs(report['OfflineReady'], False)
        self.assertEqual(report['WatcherAcceptance'], 'pending')
        self.assertNotIn('PRIVATE_CALLBACK_EXCEPTION', json.dumps(report))

    def test_default_dry_run_preserves_file_and_private_state(self):
        before = self.target.stat()
        report = self.deploy()
        self.assertTrue(report['ok'], report)
        self.assertTrue(report['dry_run'])
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assertEqual(self.target.stat().st_ino, before.st_ino)
        self.assertEqual(set(p.name for p in self.work.iterdir()), {'watcher.lock'})
        self.assertTrue(self.lint_calls)
        self.assert_pending(report)

    def test_apply_preserves_owner_mode_and_is_idempotent(self):
        report = self.deploy(apply=True)
        self.assertTrue(report['ok'], report)
        self.assertTrue(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.new)
        current = self.target.stat()
        self.assertEqual((current.st_uid, current.st_gid, stat.S_IMODE(current.st_mode)),
                         (*self.owner, 0o640))
        inode = current.st_ino
        audit_paths = set(self.work.iterdir()) - {self.lock}
        self.assertEqual(len(audit_paths), 1)
        for directory in audit_paths:
            self.assertTrue(directory.is_dir())
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o700)
            for item in directory.iterdir():
                self.assertTrue(item.is_file())
                self.assertFalse(item.is_symlink())
                self.assertEqual(stat.S_IMODE(item.stat().st_mode), 0o600)
            self.assertEqual((directory / 'before-importer.php').read_bytes(), self.old)
            self.assertEqual((directory / 'wanted-importer.php').read_bytes(), self.new)
            self.assertTrue((directory / 'intent.json').is_file())
            self.assertTrue((directory / 'committed.json').is_file())
        second = self.deploy(apply=True)
        self.assertTrue(second['ok'], second)
        self.assertTrue(second['already_current'])
        self.assertFalse(second['applied'])
        self.assertEqual(self.target.stat().st_ino, inode)
        self.assertEqual(set(self.work.iterdir()) - {self.lock}, audit_paths)
        self.assert_pending(report)
        self.assert_pending(second)

    def test_unrecognized_full_cohort_is_refused(self):
        (self.live / 'artisan').write_bytes(b'<?php\n// unexpected source\n')
        report = self.deploy(apply=True)
        self.assertFalse(report['ok'])
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assert_pending(report)

    def test_php_lint_failure_cannot_modify_source(self):
        def fail_lint(_):
            raise RuntimeError('PRIVATE_CALLBACK_EXCEPTION')
        report = self.helper.deploy(self.review, self.live, self.work,
                                    apply=True, lint=fail_lint, web_uid=self.owner[0])
        self.assertFalse(report['ok'])
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assert_pending(report)

    def test_unreviewed_payload_is_refused(self):
        (self.review / TARGET).write_bytes(b'<?php\n// unreviewed replacement\n')
        report = self.deploy(apply=True)
        self.assertFalse(report['ok'])
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_unreviewed_scanner_is_never_executed(self):
        scanner = self.review / SCANNER
        scanner.write_bytes(scanner.read_bytes() + b"\nraise RuntimeError('PRIVATE_CALLBACK_EXCEPTION')\n")
        report = self.deploy(apply=True)
        self.assertFalse(report['ok'])
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assert_pending(report)

    def test_target_changed_before_swap_is_preserved(self):
        foreign = b'<?php\n// concurrent target\n'
        report = self.deploy(apply=True, before_swap=lambda: self.target.write_bytes(foreign))
        self.assertFalse(report['ok'])
        self.assertEqual(self.target.read_bytes(), foreign)
        self.assert_pending(report)

    def test_other_source_changed_before_swap_refuses_target_write(self):
        report = self.deploy(apply=True, before_swap=lambda:
                             (self.live / 'artisan').write_bytes(b'<?php\n// concurrent cohort\n'))
        self.assertFalse(report['ok'])
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_owned_failure_rolls_back_exact_wanted_file(self):
        def fail():
            raise RuntimeError('PRIVATE_CALLBACK_EXCEPTION')
        report = self.deploy(apply=True, after_swap=fail)
        self.assertFalse(report['ok'])
        self.assertTrue(report['rollback_complete'], report)
        self.assertEqual(self.target.read_bytes(), self.old)
        info = self.target.stat()
        self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)),
                         (*self.owner, 0o640))
        self.assert_pending(report)

    def test_rollback_does_not_overwrite_concurrent_current_file(self):
        foreign = b'<?php\n// accepted concurrent writer\n'
        def change_then_fail():
            self.target.write_bytes(foreign)
            raise RuntimeError('PRIVATE_CALLBACK_EXCEPTION')
        report = self.deploy(apply=True, after_swap=change_then_fail)
        self.assertFalse(report['ok'])
        self.assertFalse(report['rollback_complete'])
        self.assertEqual(self.target.read_bytes(), foreign)
        self.assert_pending(report)

    def test_rollback_keeps_watcher_lock_until_restoration_finishes(self):
        original_exchange = self.helper.exchange
        exchanges = []

        def checking_exchange(*arguments):
            exchanges.append(arguments)
            if len(exchanges) == 2:
                # A second descriptor cannot acquire the same flock while the
                # rollback restores the held previous generation's source.
                with self.lock.open('rb') as contender:
                    with self.assertRaises(BlockingIOError):
                        fcntl.flock(contender.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            return original_exchange(*arguments)

        def fail():
            raise RuntimeError('PRIVATE_CALLBACK_EXCEPTION')

        with patch.object(self.helper, 'exchange', checking_exchange):
            report = self.deploy(apply=True, after_swap=fail)
        self.assertEqual(len(exchanges), 2)
        self.assertFalse(report['ok'])
        self.assertTrue(report['rollback_complete'], report)
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_atomic_boundary_racer_is_restored_after_displaced_mismatch(self):
        original_exchange = self.helper.exchange
        foreign = b'<?php\n// race after final preflight guard\n'
        exchanges = []

        def racing_exchange(*arguments):
            exchanges.append(arguments)
            if len(exchanges) == 1:
                replacement = self.target.with_name('.race-replacement')
                replacement.write_bytes(foreign)
                replacement.chmod(0o640)
                os.chown(replacement, *self.owner)
                os.replace(replacement, self.target)
            return original_exchange(*arguments)

        with patch.object(self.helper, 'exchange', racing_exchange):
            report = self.deploy(apply=True)
        self.assertEqual(len(exchanges), 2)
        self.assertFalse(report['ok'])
        self.assertEqual(report['reason'], 'CAS_DISPLACED_CHANGED')
        self.assertFalse(report['applied'])
        self.assertTrue(report['rollback_complete'], report)
        self.assertEqual(self.target.read_bytes(), foreign)
        self.assert_pending(report)

    def test_rollback_boundary_racer_preserves_all_versions_for_manual_review(self):
        original_exchange = self.helper.exchange
        foreign = b'<?php\n// third party at rollback exchange boundary\n'
        exchanges = []

        def rollback_race(*arguments):
            exchanges.append(arguments)
            if len(exchanges) == 2:
                replacement = self.target.with_name('.rollback-race-replacement')
                replacement.write_bytes(foreign)
                replacement.chmod(0o640)
                os.chown(replacement, *self.owner)
                os.replace(replacement, self.target)
            return original_exchange(*arguments)

        def fail():
            raise RuntimeError('PRIVATE_CALLBACK_EXCEPTION')

        with patch.object(self.helper, 'exchange', rollback_race):
            report = self.deploy(apply=True, after_swap=fail)
        self.assertFalse(report['ok'])
        self.assertFalse(report['rollback_complete'], report)
        self.assertTrue(report['manual_review'], report)
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assertEqual(len(exchanges), 2, 'No further compensation may race another writer.')
        audit = Path(report['audit_directory'])
        self.assertTrue((audit / 'intent.json').is_file())
        self.assertFalse((audit / 'committed.json').exists())
        self.assertFalse((audit / 'rolled-back.json').exists())
        intent = json.loads((audit / 'intent.json').read_bytes())
        self.assertEqual((self.target.parent / intent['stage_name']).read_bytes(), foreign)
        self.assertEqual((audit / 'before-importer.php').read_bytes(), self.old)
        self.assertEqual((audit / 'wanted-importer.php').read_bytes(), self.new)
        self.assert_pending(report)

    def test_busy_watcher_lock_returns_retry_without_writes(self):
        with self.lock.open('rb') as held:
            fcntl.flock(held.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            report = self.deploy(apply=True)
        self.assertFalse(report['ok'])
        self.assertEqual(report['reason'], 'WATCHER_BUSY')
        self.assertFalse(report['applied'])
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assertEqual(set(p.name for p in self.work.iterdir()), {'watcher.lock'})

    def test_linked_target_is_refused(self):
        saved = self.base / 'saved.php'
        self.target.rename(saved)
        self.target.symlink_to(saved)
        report = self.deploy(apply=True)
        self.assertFalse(report['ok'])
        self.assertEqual(saved.read_bytes(), self.old)
        self.assertTrue(self.target.is_symlink())

    def test_hardlinked_target_is_refused(self):
        os.link(self.target, self.base / 'other-link.php')
        report = self.deploy(apply=True)
        self.assertFalse(report['ok'])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_live_root_owner_must_match_resolved_hosting_account(self):
        report = self.helper.deploy(self.review, self.live, self.work, apply=True,
                                    lint=self.lint, web_uid=self.owner[0] + 1)
        self.assertFalse(report['ok'])
        self.assertFalse(report['applied'])
        self.assertEqual(report['reason'], 'LIVE_ROOT_OWNER_INVALID')
        self.assertEqual(self.target.read_bytes(), self.old)
        self.assertEqual(set(p.name for p in self.work.iterdir()), {'watcher.lock'})


if __name__ == '__main__':
    unittest.main()
