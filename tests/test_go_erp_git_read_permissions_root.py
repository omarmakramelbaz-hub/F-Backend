import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

HELPER = Path(__file__).with_name('repair_go_erp_git_read_permissions_root.py')
if not HELPER.is_file():
    HELPER = Path(__file__).resolve().parents[1] / 'deployment' / 'repair_go_erp_git_read_permissions_root.py'
SPEC = importlib.util.spec_from_file_location('repair', HELPER)
repair = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(repair)


class VirtualOwnership:
    """Only for UID0-only test sandboxes; real Git bytes are still verified.

    On normal root-capable Linux hosts the tests use real chown and runuser.
    """
    def __init__(self):
        self.owners = {}
        self.original_stat = os.stat
        self.original_lstat = os.lstat
        self.original_fstat = os.fstat
        self.patches = []

    def convert(self, value):
        owner = self.owners.get((value.st_dev, value.st_ino))
        if owner is None:
            return value
        class View:
            st_uid, st_gid = owner
            def __getattr__(self, name):
                return getattr(value, name)
            def __getitem__(self, index):
                return owner[index - 4] if index in (4, 5) else value[index]
        return View()

    def chown(self, path, uid, gid, **kwargs):
        value = self.original_stat(path, **kwargs)
        self.owners[(value.st_dev, value.st_ino)] = (uid, gid)

    def fchown(self, descriptor, uid, gid):
        value = self.original_fstat(descriptor)
        self.owners[(value.st_dev, value.st_ino)] = (uid, gid)

    def start(self):
        replacements = {'stat': lambda *a, **k: self.convert(self.original_stat(*a, **k)),
                        'lstat': lambda *a, **k: self.convert(self.original_lstat(*a, **k)),
                        'fstat': lambda *a, **k: self.convert(self.original_fstat(*a, **k)),
                        'chown': self.chown, 'fchown': self.fchown}
        for name, function in replacements.items():
            patch = mock.patch.object(os, name, function)
            patch.start()
            self.patches.append(patch)

    def stop(self):
        for patch in reversed(self.patches):
            patch.stop()


@unittest.skipUnless(os.geteuid() == 0, 'Root is required for the isolated ownership fixture.')
class RepairTests(unittest.TestCase):
    def setUp(self):
        self.actor = pwd.getpwnam('nobody')
        self.base = Path(tempfile.mkdtemp(prefix='go-erp-git-fixture-', dir='/run'))
        self.addCleanup(shutil.rmtree, str(self.base), ignore_errors=True)
        self.base.chmod(0o711)
        self.root = self.base / 'repository'
        self.root.mkdir()
        def git(*args):
            return subprocess.check_output(['/usr/bin/git', '-c', 'safe.directory=' + str(self.root), '-C', str(self.root)] + list(args), stderr=subprocess.DEVNULL)
        git('init', '--quiet')
        git('config', 'user.name', 'Owned fixture')
        git('config', 'user.email', 'fixture@example.invalid')
        (self.root / 'application.txt').write_text('application bytes stay intact\n')
        git('add', 'application.txt')
        git('commit', '--quiet', '-m', 'fixture')
        self.oid = git('rev-parse', 'HEAD').decode().strip()
        git('-c', 'pack.writeReverseIndex=true', 'repack', '-ad')
        pack = next((self.root / '.git' / 'objects' / 'pack').glob('*.pack'))
        self.pack_hash = pack.stem[5:]
        self.pack_paths = {ext: pack.with_suffix('.' + ext) for ext in ('pack', 'idx', 'rev')}
        self.sizes = {ext: path.stat().st_size for ext, path in self.pack_paths.items()}
        self.ref = self.root / '.git' / repair.BACKUP_REF
        self.ref.parent.mkdir(parents=True)
        self.ref.write_text(self.oid + '\n')
        self.journal = self.root / 'offline-journal.json'
        self.journal.write_text('{"paid_unsynced":true}\n')
        self.virtual = len(Path('/proc/self/uid_map').read_text().split()) == 3 and Path('/proc/self/uid_map').read_text().split()[-1] == '1'
        if self.virtual:
            ownership = VirtualOwnership()
            ownership.start()
            self.addCleanup(ownership.stop)
        for folder, dirs, files in os.walk(str(self.root)):
            os.chown(folder, self.actor.pw_uid, self.actor.pw_gid)
            for name in files:
                os.chown(str(Path(folder) / name), self.actor.pw_uid, self.actor.pw_gid)
        for path in self.pack_paths.values():
            os.chown(str(path), 0, 0)
            path.chmod(0o400)
        os.chown(str(self.ref), 0, 0)
        self.ref.chmod(0o600)
        self.held = []
        if self.virtual:
            original_git = repair.git
            def virtual_actor_git(root, args, data=None, actor=None):
                if actor is not None:
                    if any(path.stat().st_uid != self.actor.pw_uid for path in self.pack_paths.values()):
                        raise repair.Stop('GO_ERP_GIT_READ_GIT_INSPECTION_FAILED')
                    if repair.BACKUP_REF in args and self.ref.stat().st_uid != self.actor.pw_uid:
                        raise repair.Stop('GO_ERP_GIT_READ_GIT_INSPECTION_FAILED')
                return original_git(root, args, data, actor=None)
            patch = mock.patch.object(repair, 'git', virtual_actor_git)
            patch.start()
            self.addCleanup(patch.stop)

    def tearDown(self):
        for item in self.held:
            item.close()
        shutil.rmtree(str(self.base))

    def collect(self):
        held, packs, refs = repair.collect(self.root, self.actor.pw_uid, self.actor.pw_gid, self.pack_hash, self.sizes)
        self.held.extend(held)
        return held, packs, refs

    def test_actual_pack_permissions_ref_and_idempotence(self):
        before_guard = repair.guard(self.root)
        journal = self.journal.read_bytes()
        with self.assertRaises(repair.Stop):
            repair.commit_objects(self.root, [self.oid], 'nobody')
        held, packs, refs = self.collect()
        self.assertEqual(len(refs), 1)
        repair.commit_objects(self.root, [self.oid])
        repair.git(self.root, ['verify-pack', str(packs['idx'].path)])
        self.assertEqual(repair.change_owners(held, self.actor.pw_uid, self.actor.pw_gid), 4)
        repair.commit_objects(self.root, [self.oid], 'nobody')
        self.assertEqual(repair.git(self.root, ['rev-parse', '--verify', repair.BACKUP_REF], actor='nobody').decode().strip(), self.oid)
        self.assertEqual(repair.guard(self.root), before_guard)
        self.assertEqual(self.journal.read_bytes(), journal)
        for path in self.pack_paths.values():
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o400)
        self.assertEqual(stat.S_IMODE(self.ref.stat().st_mode), 0o600)
        held2, _packs, refs2 = self.collect()
        self.assertEqual(refs2, [])
        self.assertEqual(repair.change_owners(held2, self.actor.pw_uid, self.actor.pw_gid), 0)

    def test_private_audit_before_change(self):
        held, _packs, _refs = self.collect()
        folder = repair.write_audit(self.base, {'files': [item.record(self.root / '.git') for item in held]})
        self.assertEqual(stat.S_IMODE(folder.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((folder / 'before.json').stat().st_mode), 0o600)
        report = json.loads((folder / 'before.json').read_text())
        self.assertEqual(len(report['files']), 4)
        self.assertTrue(all(row['uid'] == 0 for row in report['files']))
        self.assertTrue(all(item.path.stat().st_uid == 0 for item in held))

    def test_wrong_pack_checksum_rejected_before_change(self):
        path = self.pack_paths['pack']
        path.chmod(0o600)
        data = bytearray(path.read_bytes())
        data[13] ^= 1
        path.write_bytes(data)
        path.chmod(0o400)
        with self.assertRaisesRegex(repair.Stop, 'PACK_CHECKSUM_INVALID'):
            self.collect()
        self.assertEqual(self.ref.stat().st_uid, 0)

    def test_wrong_index_checksum_rejected(self):
        path = self.pack_paths['idx']
        path.chmod(0o600)
        data = bytearray(path.read_bytes())
        data[-1] ^= 1
        path.write_bytes(data)
        path.chmod(0o400)
        with self.assertRaisesRegex(repair.Stop, 'INDEX_CHECKSUM_INVALID'):
            self.collect()

    def test_wrong_reverse_checksum_rejected(self):
        path = self.pack_paths['rev']
        path.chmod(0o600)
        data = bytearray(path.read_bytes())
        data[-1] ^= 1
        path.write_bytes(data)
        path.chmod(0o400)
        with self.assertRaisesRegex(repair.Stop, 'REVERSE_INDEX_CHECKSUM_INVALID'):
            self.collect()

    def test_wrong_pack_mode_rejected(self):
        self.pack_paths['pack'].chmod(0o440)
        with self.assertRaisesRegex(repair.Stop, 'PACK_OWNER_OR_MODE_CHANGED'):
            self.collect()

    def test_pack_hard_link_rejected(self):
        os.link(str(self.pack_paths['idx']), str(self.base / 'linked-index'))
        with self.assertRaisesRegex(repair.Stop, 'UNSAFE_FILE'):
            self.collect()

    def test_pack_symlink_rejected(self):
        path = self.pack_paths['idx']
        target = self.base / 'moved-index'
        path.rename(target)
        path.symlink_to(target)
        with self.assertRaisesRegex(repair.Stop, 'UNSAFE_FILE'):
            self.collect()

    def test_refs_ancestor_symlink_rejected(self):
        directory = self.root / '.git' / 'refs'
        target = self.root / '.git' / 'moved-refs'
        directory.rename(target)
        directory.symlink_to(target)
        with self.assertRaisesRegex(repair.Stop, 'DIRECTORY_OWNER_OR_MODE_INVALID'):
            self.collect()

    def test_ref_symlink_rejected(self):
        self.ref.unlink()
        self.ref.symlink_to(self.base / 'nonexistent')
        with self.assertRaisesRegex(repair.Stop, 'LINKED_REMOTE_REF'):
            self.collect()

    def test_ref_hardlink_rejected(self):
        os.link(str(self.ref), str(self.base / 'linked-ref'))
        with self.assertRaisesRegex(repair.Stop, 'UNSAFE_FILE'):
            self.collect()

    def test_other_owner_rejected(self):
        os.chown(str(self.ref), 60002, 60002)
        with self.assertRaisesRegex(repair.Stop, 'REMOTE_REF_OWNER_OR_MODE_INVALID'):
            self.collect()

    def test_ref_invalid_bytes_rejected(self):
        self.ref.write_text('0' * 39 + 'Z\n')
        with self.assertRaisesRegex(repair.Stop, 'REMOTE_REF_BYTES_INVALID'):
            self.collect()

    def test_replaced_file_rejected_before_fchown(self):
        held, _packs, _refs = self.collect()
        data = self.ref.read_bytes()
        self.ref.unlink()
        self.ref.write_bytes(data)
        self.ref.chmod(0o600)
        with self.assertRaisesRegex(repair.Stop, 'DIRECTORY_CHANGED|FILE_CHANGED'):
            repair.change_owners(held, self.actor.pw_uid, self.actor.pw_gid)
        self.assertTrue(all(path.stat().st_uid == 0 for path in self.pack_paths.values()))

    def test_changed_ancestor_rejected_before_fchown(self):
        held, _packs, _refs = self.collect()
        origin = self.root / '.git' / 'refs' / 'remotes' / 'origin'
        origin.chmod(0o700)
        with self.assertRaisesRegex(repair.Stop, 'DIRECTORY_CHANGED'):
            repair.change_owners(held, self.actor.pw_uid, self.actor.pw_gid)
        self.assertTrue(all(path.stat().st_uid == 0 for path in self.pack_paths.values()))

    def test_empty_guard_metadata_is_valid(self):
        path = self.root / '.git' / 'FETCH_HEAD'
        path.write_bytes(b'')
        os.chown(str(path), self.actor.pw_uid, self.actor.pw_gid)
        self.assertEqual(repair.guard(self.root)['FETCH_HEAD']['bytes'], 0)

    def test_other_root_metadata_is_reported_without_changes(self):
        log = self.root / '.git' / 'logs' / 'root-owned-marker'
        log.write_text('not inspected as a ref\n')
        log.chmod(0o600)
        before = log.stat()
        held, _packs, _refs = self.collect()
        result = repair.other_root_metadata(self.root, {item.path for item in held})
        self.assertIn('logs/root-owned-marker', [item['path'] for item in result])
        repair.change_owners(held, self.actor.pw_uid, self.actor.pw_gid)
        self.assertEqual(repair.identity(log.stat()), repair.identity(before))

    def test_root_700_nested_directories_repaired_top_down(self):
        parent = self.ref.parent
        os.chown(str(parent), 0, 0)
        parent.chmod(0o700)
        child = parent / 'nested'
        child.mkdir(mode=0o700)
        child_ref = child / 'other'
        child_ref.write_text(self.oid + '\n')
        child_ref.chmod(0o600)
        held, _packs, _refs = self.collect()
        self.assertEqual(sum(item.directory for item in held), 2)
        self.assertEqual(repair.change_owners(held, self.actor.pw_uid, self.actor.pw_gid), 7)
        self.assertEqual(parent.stat().st_uid, self.actor.pw_uid)
        self.assertEqual(child.stat().st_uid, self.actor.pw_uid)
        self.assertEqual(stat.S_IMODE(parent.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(child.stat().st_mode), 0o700)
        held2, _packs, refs2 = self.collect()
        self.assertEqual(refs2, [])
        self.assertEqual(repair.change_owners(held2, self.actor.pw_uid, self.actor.pw_gid), 0)

    def test_root_755_nested_directory_rejected(self):
        os.chown(str(self.ref.parent), 0, 0)
        self.ref.parent.chmod(0o755)
        with self.assertRaisesRegex(repair.Stop, 'DIRECTORY_OWNER_OR_MODE_INVALID'):
            self.collect()

    def test_root_owned_upper_refs_directory_rejected(self):
        os.chown(str(self.root / '.git' / 'refs'), 0, 0)
        with self.assertRaisesRegex(repair.Stop, 'DIRECTORY_OWNER_OR_MODE_INVALID'):
            self.collect()


if __name__ == '__main__':
    unittest.main()
