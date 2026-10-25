"""Regression checks for archived, completed historical GO ERP checkouts."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'deployment' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@unittest.skipUnless(os.geteuid() == 0 and shutil.which('tar'), 'root and GNU tar required')
class ClosedCheckoutTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.worker = load('clean_closed_upgrade_checkouts_root')
        cls.points = load('clean_archived_point_backups_root')

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='closed-checkout-test-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.work = self.root / 'go-erp-live-updates'
        self.work.mkdir(mode=0o700)
        (self.work / 'state').mkdir(mode=0o700)
        (self.work / 'checkout').mkdir(mode=0o700)
        (self.work / 'checkout/current').write_bytes(b'keep-current-code')
        self.state = {'format': 1, 'base_revision': 'c' * 40,
                      'desired': {'status': 'published', 'commit': 'd' * 40},
                      'published': {'commit': 'd' * 40}}
        self.write(self.work / 'state/current.json', self.state)
        self.old = self.audit('fullscreen', '1', 3, 'a' * 40, 'b' * 40)
        self.newest = self.audit('preview31', '2', 7, 'e' * 40, 'f' * 40)
        self.backup = self.root / 'backups/fresh-full'
        self.backup.mkdir(mode=0o700, parents=True)
        self.home = self.root / 'home'
        self.home.mkdir(mode=0o700)
        self.split = types.SimpleNamespace(HOME=self.home, home_inventory=mock.Mock(return_value={}),
                                           run=mock.Mock(return_value=''))
        self.verified = types.SimpleNamespace(latest_path=self.backup, check=mock.Mock())
        self.guard = mock.Mock()
        self.reference_patch = mock.patch.object(self.worker, 'REFERENCE_ROOTS', ())
        self.reference_patch.start()
        self.addCleanup(self.reference_patch.stop)
        self.extra_patch = mock.patch.object(self.points, 'EXTRA_REFERENCE_DIRS', ())
        self.extra_patch.start()
        self.addCleanup(self.extra_patch.stop)
        self.extra_files_patch = mock.patch.object(self.points, 'EXTRA_REFERENCE_FILES', ())
        self.extra_files_patch.start()
        self.addCleanup(self.extra_files_patch.stop)
        self.archive()

    @staticmethod
    def write(path, value):
        path.write_text(json.dumps(value) + '\n')
        path.chmod(0o600)

    def audit(self, prefix, digit, counter, old_seed, new_seed):
        audit = self.work / (prefix + '-seed-upgrade-' + digit * 32)
        audit.mkdir(mode=0o700)
        checkout = audit / 'exchange-checkout'
        checkout.mkdir(mode=0o700)
        (checkout / 'source').write_bytes(b'historical-source-' + digit.encode())
        (checkout / 'source').chmod(0o600)
        (checkout / 'empty').mkdir(mode=0o700)
        held = list(self.worker.identity(checkout.stat())[:5])
        slots = {name: {'before': {'identity': held}, 'after': {'identity': held}}
                 for name in ('unit', 'checkout', 'state', 'config')}
        self.write(audit / 'intent.json', {'format': 1, 'old_seed': old_seed, 'new_seed': new_seed,
                   'core': '0' * 40, 'runtime': '/opt/go-erp-live-updates/releases/policy-' + '0' * 64,
                   'slots': slots})
        self.write(audit / 'completed.json', {'format': 1, 'ok': True, 'new_seed': new_seed,
                   'counter': counter, 'version_floor': '0.4.4-preview.' + str(counter),
                   'sequence_floor': counter})
        return checkout

    def archive(self):
        subprocess.run(['tar', '--acls', '--xattrs', '--numeric-owner', '--format=pax',
                        '--hard-dereference', '-czf', str(self.backup / 'server-runtime.tar.gz'),
                        '-C', '/', str(self.work).lstrip('/')], check=True,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        inventory = {}
        for parent, dirs, files in os.walk(str(self.work), followlinks=False):
            for path in [Path(parent)] + [Path(parent) / name for name in dirs + files]:
                info = path.lstat()
                row = {'mode': info.st_mode & 0o7777, 'uid': info.st_uid, 'gid': info.st_gid,
                       'identity': list(self.worker.identity(info))}
                if path.is_symlink():
                    row.update(type='symlink', target=os.readlink(str(path)))
                elif path.is_dir():
                    row.update(type='directory')
                else:
                    row.update(type='file', size=info.st_size,
                               sha256=hashlib.sha256(path.read_bytes()).hexdigest())
                inventory[str(path).lstrip('/')] = row
        self.verified.runtime_inventory = inventory

    def clean(self, apply=False):
        return self.worker.clean(self.verified, self.points, self.split, apply=apply,
                                 work=self.work, guard=self.guard)

    def assert_kept(self):
        self.assertTrue(self.old.is_dir())
        self.assertTrue(self.newest.is_dir())
        self.assertTrue((self.work / 'checkout/current').is_file())
        self.assertTrue((self.backup / 'server-runtime.tar.gz').is_file())

    def test_check_is_read_only(self):
        result = self.clean()
        self.assertEqual(result['closed_checkout_status'], 'checked')
        self.assert_kept()

    def test_apply_removes_only_old_body_and_keeps_receipts_current_and_newest(self):
        result = self.clean(True)
        self.assertEqual(result['removed_paths'], [str(self.old)])
        self.assertFalse(self.old.exists())
        self.assertTrue((self.old.parent / 'intent.json').is_file())
        self.assertTrue((self.old.parent / 'completed.json').is_file())
        self.assertTrue(self.newest.is_dir())
        self.assertTrue((self.work / 'checkout/current').is_file())

    def test_unknown_incomplete_directory_is_preserved(self):
        unknown = self.work / ('program-seed-upgrade-' + '3' * 32) / 'exchange-checkout'
        unknown.mkdir(mode=0o700, parents=True)
        (unknown / 'valuable').write_bytes(b'unknown-state')
        self.archive()
        self.clean(True)
        self.assertTrue((unknown / 'valuable').is_file())

    def test_current_seed_transaction_is_preserved(self):
        current = self.audit('fullscreen', '3', 2, 'c' * 40, '9' * 40)
        self.archive()
        self.clean(True)
        self.assertTrue(current.is_dir())

    def test_performance_and_startup_session_rollback_are_preserved(self):
        performance = self.audit('performance', '3', 1, '7' * 40, '8' * 40)
        startup = self.audit('startup-session', '4', 1, '7' * 40, '8' * 40)
        self.archive()
        self.clean(True)
        self.assertTrue(performance.is_dir())
        self.assertTrue(startup.is_dir())

    def test_bad_completed_binding_preserves_that_checkout(self):
        complete = json.loads((self.old.parent / 'completed.json').read_text())
        complete['new_seed'] = '9' * 40
        self.write(self.old.parent / 'completed.json', complete)
        result = self.clean(True)
        self.assertTrue(self.old.is_dir())
        self.assertFalse(result['removed_paths'])

    def test_conflicting_or_rollback_marker_is_preserved(self):
        self.write(self.old.parent / 'rolled-back.json', {'format': 1, 'recovered': True})
        self.clean(True)
        self.assertTrue(self.old.is_dir())

    def test_content_change_same_size_restored_mtime_is_rejected(self):
        path = self.old / 'source'
        before = path.stat()
        path.write_bytes(b'x' * before.st_size)
        os.utime(path, ns=(before.st_atime_ns, before.st_mtime_ns))
        with self.assertRaisesRegex(self.worker.Stop, 'INVENTORY_CONTENT'):
            self.clean(True)
        self.assert_kept()

    def test_added_unarchived_file_is_rejected(self):
        (self.old / 'added').write_bytes(b'new')
        with self.assertRaisesRegex(self.worker.Stop, 'INVENTORY_COVERAGE'):
            self.clean(True)
        self.assert_kept()

    def test_current_git_alternate_reference_blocks_cleanup(self):
        info = self.work / 'checkout/.git/objects/info'
        info.mkdir(parents=True)
        (info / 'alternates').write_text(str(self.old / '.git/objects') + '\n')
        with self.assertRaisesRegex(self.worker.Stop, 'CURRENT_GIT_REFERENCE|LIVE_PATH_REFERENCE'):
            self.clean(True)
        self.assert_kept()

    def test_current_git_directory_pointer_into_candidate_blocks_cleanup(self):
        (self.work / 'checkout/.git').write_text('gitdir: ' + str(self.old) + '\n')
        with self.assertRaisesRegex(self.worker.Stop, 'CURRENT_GIT_REFERENCE'):
            self.clean(True)
        self.assert_kept()

    def test_current_relative_git_worktree_reference_blocks_cleanup(self):
        gitdir = self.work / 'checkout/.git'
        gitdir.mkdir()
        relative = os.path.relpath(self.old, gitdir)
        (gitdir / 'config').write_text('[core]\n worktree = ' + relative + '\n')
        with self.assertRaisesRegex(self.worker.Stop, 'CURRENT_GIT_REFERENCE'):
            self.clean(True)
        self.assert_kept()

    def test_linked_state_ancestor_is_rejected(self):
        state = self.work / 'state'
        state.rename(self.root / 'other-state')
        state.symlink_to(self.root / 'other-state', target_is_directory=True)
        with self.assertRaisesRegex(self.worker.Stop, 'LINKED_OR_SPECIAL_DIRECTORY'):
            self.clean(True)
        self.assert_kept()

    def test_open_file_descriptor_blocks_cleanup(self):
        with (self.old / 'source').open('rb'):
            with self.assertRaisesRegex(self.points.Stop, 'PROCESS_REFERENCE'):
                self.clean(True)
        self.assert_kept()

    def test_pending_candidate_protects_current_source_but_allows_unrelated_history(self):
        self.state['desired'].update(status='waiting-ci', commit='e' * 40)
        self.write(self.work / 'state/current.json', self.state)
        result = self.clean(True)
        self.assertEqual(result['removed_paths'], [str(self.old)])
        self.assertTrue((self.work / 'checkout/current').is_file())

    def test_apply_requires_master_guard(self):
        with self.assertRaisesRegex(self.worker.Stop, 'UPDATER_GUARD'):
            self.worker.clean(self.verified, self.points, self.split, apply=True, work=self.work)
        self.assert_kept()

    def test_guard_failure_before_move_preserves_all_sources(self):
        self.guard.side_effect = RuntimeError('updater-lock-lost')
        with self.assertRaisesRegex(RuntimeError, 'updater-lock-lost'):
            self.clean(True)
        self.assert_kept()

    def test_post_quarantine_change_restores_body(self):
        original = self.points.assert_snapshot
        changed = False
        def check(path, tree):
            nonlocal changed
            if path.parent.name.startswith('closed-checkout-prune-') and not changed:
                (path / 'unexpected').write_bytes(b'race')
                changed = True
            return original(path, tree)
        with mock.patch.object(self.points, 'assert_snapshot', side_effect=check):
            with self.assertRaises(self.points.Stop):
                self.clean(True)
        self.assert_kept()
        self.assertTrue((self.old / 'unexpected').is_file())

    def test_mismatch_between_archive_and_inventory_is_rejected(self):
        key = str(self.old / 'source').lstrip('/')
        self.verified.runtime_inventory[key]['sha256'] = '0' * 64
        with self.assertRaisesRegex(self.worker.Stop, 'INVENTORY_CONTENT'):
            self.clean(True)
        self.assert_kept()


if __name__ == '__main__':
    unittest.main()
