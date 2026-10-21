#!/usr/bin/env python3
"""Restore the measured root-fetched Git cohort to its repository owner.

No checkout, ref update, application write, chmod or recursive chown is used.
The console launcher must verify this published file's immutable Git blob.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import struct
import subprocess
import sys
import tempfile

ROOT = Path('/home/fasakha/public_html')
PACK = '2d7a3a621a9c26e729a9c7fafaf2b2ff9ac86f6b'
SIZES = {'pack': 30773812, 'idx': 293168, 'rev': 41780}
BACKUP_REF = 'refs/remotes/origin/backup/2026-10-20-all-apps'
BACKUP_SHA = '77e37edd86fd50aec4859f160310c6065cd074d9'
RELEASE = '8ad022856b9feacdc96a3449ff6d075c29bb2ae4'
AUDITS = Path('/var/lib/go-erp-git-read-recovery')
ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha',
       'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
       'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_NO_LAZY_FETCH': '1',
       'GIT_OPTIONAL_LOCKS': '0'}
OID = re.compile(rb'[0-9a-f]{40}\n\Z')
NAME = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]*\Z')


class Stop(Exception):
    pass


def need(ok, message):
    if not ok:
        raise Stop('GO_ERP_GIT_READ_' + message)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid,
            value.st_gid, value.st_nlink, value.st_size, value.st_mtime_ns,
            value.st_ctime_ns)


def parents(path, owner, allow_sticky=False):
    for parent in path.parents:
        item = parent.lstat()
        sticky = allow_sticky and parent in (Path('/tmp'), Path('/var/tmp')) and item.st_mode & stat.S_ISVTX
        need(stat.S_ISDIR(item.st_mode) and not parent.is_symlink(), 'UNSAFE_ANCESTOR')
        need(item.st_uid in (0, owner), 'UNEXPECTED_ANCESTOR_OWNER')
        need(not item.st_mode & 0o002 or sticky, 'WRITABLE_ANCESTOR')


class DirectoryGuards:
    def __init__(self):
        self.rows = []

    def add(self, path, owner, allow_root=False, repairable=False):
        before = path.lstat()
        root_cohort = repairable and before.st_uid == 0 and before.st_gid == 0 and stat.S_IMODE(before.st_mode) == 0o700
        need(stat.S_ISDIR(before.st_mode) and not path.is_symlink()
             and (before.st_uid in ((0, owner) if allow_root else (owner,)) or root_cohort)
             and not before.st_mode & 0o002, 'DIRECTORY_OWNER_OR_MODE_INVALID')
        fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            need(identity(before) == identity(os.fstat(fd)) == identity(path.lstat()), 'DIRECTORY_CHANGED')
        except BaseException:
            os.close(fd)
            raise
        self.rows.append((path, fd, before))
        return fd, before

    def updated(self, path, value):
        self.rows = [(name, fd, value if name == path else before) for name, fd, before in self.rows]

    def verify(self):
        for path, fd, before in self.rows:
            need(identity(before) == identity(os.fstat(fd)) == identity(path.lstat()), 'DIRECTORY_CHANGED')

    def close(self):
        for _path, fd, _before in self.rows:
            os.close(fd)
        self.rows = []


class Held:
    directory = False
    def __init__(self, path, limit, allow_empty=False):
        self.path = path
        self.fd = None
        self.directory_guard = None
        before = path.lstat()
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
             and (0 <= before.st_size if allow_empty else 0 < before.st_size)
             and before.st_size <= limit, 'UNSAFE_FILE')
        self.fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            need(identity(before) == identity(os.fstat(self.fd)), 'FILE_REPLACED')
            self.before = before
            self.data = self.read()
            self.digest = hashlib.sha256(self.data).hexdigest()
            self.verify()
        except BaseException:
            os.close(self.fd)
            self.fd = None
            raise

    def read(self):
        os.lseek(self.fd, 0, os.SEEK_SET)
        chunks = []
        count = 0
        while True:
            data = os.read(self.fd, 1024 * 1024)
            if not data:
                break
            count += len(data)
            need(count <= self.before.st_size, 'FILE_GREW')
            chunks.append(data)
        need(count == self.before.st_size, 'FILE_SIZE_CHANGED')
        return b''.join(chunks)

    def verify(self):
        if self.directory_guard is not None:
            self.directory_guard.verify()
        need(identity(self.before) == identity(os.fstat(self.fd)) == identity(self.path.lstat()), 'FILE_CHANGED')

    def close(self):
        if self.fd is not None:
            os.close(self.fd)
            self.fd = None
        if self.directory_guard is not None:
            self.directory_guard.close()

    def record(self, root):
        item = self.before
        return {'path': str(self.path.relative_to(root)), 'uid': item.st_uid,
                'gid': item.st_gid, 'mode': stat.S_IMODE(item.st_mode),
                'bytes': item.st_size, 'mtime_ns': item.st_mtime_ns,
                'sha256': self.digest}


class HeldDirectory(Held):
    directory = True

    def __init__(self, path, fd, before, guards):
        self.path = path
        self.fd = os.dup(fd)
        self.before = before
        self.directory_guard = guards
        self.data = b''
        self.digest = hashlib.sha256(b'').hexdigest()

    def read(self):
        return b''

    def record(self, root):
        row = super().record(root)
        row.update({'directory': True, 'sha256': None})
        return row


def git(root, args, data=None, actor=None):
    command = ['/usr/bin/git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
               '-c', 'core.hooksPath=/dev/null', '-c', 'safe.directory=' + str(root),
               '-C', str(root)] + list(args)
    if actor is not None:
        command = ['/usr/sbin/runuser', '-u', actor, '--', '/usr/bin/env', '-i'] + [k + '=' + v for k, v in ENV.items()] + command
    try:
        value = subprocess.run(command, input=data, env=ENV, stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, timeout=120, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Stop('GO_ERP_GIT_READ_CHILD_UNAVAILABLE') from None
    need(value.returncode == 0 and len(value.stdout) < 256 * 1024 and len(value.stderr) < 65536, 'GIT_INSPECTION_FAILED')
    return value.stdout


def validate_pack(files, pack_hash, sizes):
    packed, indexed, reverse = [files[key].data for key in ('pack', 'idx', 'rev')]
    for key in sizes:
        need(files[key].before.st_size == sizes[key], 'PACK_SIZE_CHANGED')
    digest = bytes.fromhex(pack_hash)
    need(packed[:4] == b'PACK' and len(packed) > 32, 'PACK_HEADER_INVALID')
    version, count = struct.unpack('>II', packed[4:12])
    need(version in (2, 3) and count > 0 and packed[-20:] == digest
         and hashlib.sha1(packed[:-20]).digest() == digest, 'PACK_CHECKSUM_INVALID')
    need(indexed[:8] == b'\xfftOc\0\0\0\2' and len(indexed) >= 1072,
         'INDEX_HEADER_INVALID')
    fanout = struct.unpack('>256I', indexed[8:1032])
    need(all(a <= b for a, b in zip(fanout, fanout[1:])) and fanout[-1] == count,
         'INDEX_OBJECT_COUNT_INVALID')
    need(indexed[-40:-20] == digest and hashlib.sha1(indexed[:-20]).digest() == indexed[-20:], 'INDEX_CHECKSUM_INVALID')
    need(len(indexed) >= 1072 + count * 28, 'INDEX_SIZE_INVALID')
    need(reverse[:12] == b'RIDX\0\0\0\1\0\0\0\1' and len(reverse) == 52 + count * 4,
         'REVERSE_INDEX_HEADER_INVALID')
    need(reverse[-40:-20] == digest and hashlib.sha1(reverse[:-20]).digest() == reverse[-20:], 'REVERSE_INDEX_CHECKSUM_INVALID')


def guard(root):
    result = {}
    names = ['HEAD', 'index', 'config', 'packed-refs', 'FETCH_HEAD']
    directory = root / '.git' / 'refs' / 'heads'
    if directory.exists():
        for folder, dirs, files in os.walk(str(directory), followlinks=False):
            need(not any((Path(folder) / name).is_symlink() for name in dirs), 'LINKED_LOCAL_REF_DIRECTORY')
            names.extend(str((Path(folder) / name).relative_to(root / '.git')) for name in files)
    for name in names:
        path = root / '.git' / name
        if not path.exists():
            need(not path.is_symlink(), 'LINKED_GUARD_FILE')
            result[name] = None
            continue
        item = Held(path, 32 * 1024 * 1024, allow_empty=True)
        try:
            result[name] = item.record(root / '.git')
        finally:
            item.close()
    return result


def collect(root, uid, gid, pack_hash=PACK, sizes=SIZES):
    held = []
    directory_guard = DirectoryGuards()
    try:
        need(root.resolve() == root and (root / '.git').resolve() == root / '.git', 'LINKED_PROJECT')
        for parent in reversed(root.parents):
            directory_guard.add(parent, uid, allow_root=True)
        for path in (root, root / '.git', root / '.git' / 'objects', root / '.git' / 'objects' / 'pack',
                     root / '.git' / 'refs', root / '.git' / 'refs' / 'remotes', root / '.git' / 'refs' / 'remotes' / 'origin'):
            directory_guard.add(path, uid)
        parents(root, uid)
        packs = {}
        for extension in ('pack', 'idx', 'rev'):
            item = Held(root / '.git' / 'objects' / 'pack' / ('pack-' + pack_hash + '.' + extension), sizes[extension])
            item.directory_guard = directory_guard
            held.append(item)
            need(stat.S_IMODE(item.before.st_mode) == 0o400 and
                 (item.before.st_uid, item.before.st_gid) in ((0, 0), (uid, gid)), 'PACK_OWNER_OR_MODE_CHANGED')
            packs[extension] = item
        validate_pack(packs, pack_hash, sizes)
        directory = root / '.git' / 'refs' / 'remotes' / 'origin'
        need(directory.is_dir() and not directory.is_symlink(), 'REMOTE_REF_DIRECTORY_INVALID')
        refs = []
        count = 0
        for folder, dirs, files in os.walk(str(directory), followlinks=False):
            folder = Path(folder)
            if folder != directory:
                fd, value = directory_guard.add(folder, uid, repairable=True)
                if value.st_uid == 0:
                    held.append(HeldDirectory(folder, fd, value, directory_guard))
            for name in dirs + files:
                need(NAME.fullmatch(name) and name not in ('.', '..') and not name.endswith('.lock'), 'REMOTE_REF_NAME_INVALID')
                need(not (folder / name).is_symlink(), 'LINKED_REMOTE_REF')
            for name in files:
                count += 1
                need(count <= 4096, 'REMOTE_REF_LIMIT')
                path = folder / name
                value = path.lstat()
                if value.st_uid == uid:
                    continue
                item = Held(path, 41)
                item.directory_guard = directory_guard
                held.append(item)
                need(item.before.st_uid == 0 and item.before.st_gid == 0 and stat.S_IMODE(item.before.st_mode) == 0o600,
                     'REMOTE_REF_OWNER_OR_MODE_INVALID')
                need(OID.fullmatch(item.data) is not None, 'REMOTE_REF_BYTES_INVALID')
                refs.append(item)
        need(count > 0, 'REMOTE_REFS_MISSING')
        directory_guard.verify()
        return held, packs, refs
    except BaseException:
        for item in held:
            item.close()
        directory_guard.close()
        raise


def commit_objects(root, oids, actor=None):
    values = sorted(set(oids))
    need(all(re.fullmatch(r'[a-f0-9]{40}', item) for item in values), 'OBJECT_ID_INVALID')
    data = ''.join(item + '\n' for item in values).encode('ascii')
    expected = ''.join(item + ' commit\n' for item in values).encode('ascii')
    need(git(root, ['cat-file', '--batch-check=%(objectname) %(objecttype)'], data, actor) == expected,
         'REVIEWED_OBJECT_UNAVAILABLE')


def write_audit(directory, report):
    need(directory.is_dir() and not directory.is_symlink(), 'AUDIT_DIRECTORY_INVALID')
    folder = Path(tempfile.mkdtemp(prefix='repair-', dir=str(directory)))
    folder.chmod(0o700)
    path = folder / 'before.json'
    descriptor = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write((json.dumps(report, sort_keys=True, indent=2) + '\n').encode('ascii'))
        stream.flush()
        os.fsync(stream.fileno())
    descriptor = os.open(str(folder), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    return folder


def other_root_metadata(root, excluded):
    result = []
    count = 0
    for folder, dirs, files in os.walk(str(root / '.git'), followlinks=False):
        for name in dirs + files:
            path = Path(folder) / name
            count += 1
            need(count <= 200000, 'METADATA_INSPECTION_LIMIT')
            value = path.lstat()
            if value.st_uid == 0 and path not in excluded:
                need(len(result) < 4096, 'ROOT_METADATA_LIMIT')
                result.append({'path': str(path.relative_to(root / '.git')),
                               'mode': stat.S_IMODE(value.st_mode),
                               'directory': stat.S_ISDIR(value.st_mode),
                               'symlink': stat.S_ISLNK(value.st_mode)})
    return result


def change_owners(held, uid, gid):
    pending = sorted((item for item in held if item.before.st_uid == 0),
                     key=lambda item: (not item.directory, len(item.path.parts), str(item.path)))
    for item in held:
        item.verify()
        need(hashlib.sha256(item.read()).hexdigest() == item.digest, 'CONTENT_CHANGED_BEFORE_REPAIR')
    for item in pending:
        item.verify()
        os.fchown(item.fd, uid, gid)
        after = os.fstat(item.fd)
        before = item.before
        need((after.st_dev, after.st_ino, after.st_mode, after.st_nlink, after.st_size, after.st_mtime_ns) ==
             (before.st_dev, before.st_ino, before.st_mode, before.st_nlink, before.st_size, before.st_mtime_ns)
             and (after.st_uid, after.st_gid) == (uid, gid), 'OWNERSHIP_REPAIR_NOT_EXACT')
        need(identity(after) == identity(item.path.lstat()) and hashlib.sha256(item.read()).hexdigest() == item.digest,
             'CONTENT_CHANGED_DURING_REPAIR')
        item.before = after
        if item.directory:
            item.directory_guard.updated(item.path, after)
    return len(pending)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repair', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    actor = pwd.getpwnam('fasakha')
    entry = Path(__file__).absolute()
    parents(entry, 0, True)
    entry_stat = entry.lstat()
    need(stat.S_ISREG(entry_stat.st_mode) and entry_stat.st_uid == 0 and entry_stat.st_nlink == 1
         and stat.S_IMODE(entry_stat.st_mode) == 0o600, 'ENTRYPOINT_UNSAFE')
    held, packs, refs = collect(ROOT, actor.pw_uid, actor.pw_gid)
    try:
        head = git(ROOT, ['rev-parse', '--verify', 'HEAD']).decode('ascii').strip()
        need(git(ROOT, ['rev-parse', '--verify', BACKUP_REF]).decode('ascii').strip() == BACKUP_SHA, 'MEASURED_REFERENCE_CHANGED')
        commits = [head, BACKUP_SHA, RELEASE] + [item.data[:-1].decode('ascii') for item in refs]
        commit_objects(ROOT, commits)
        git(ROOT, ['verify-pack', str(packs['idx'].path)])
        before_guard = guard(ROOT)
        report = {'format': 1, 'root': str(ROOT), 'head': head, 'pack': PACK,
                  'files': [item.record(ROOT / '.git') for item in held if item.before.st_uid == 0],
                  'guard': before_guard, 'application_contents_changed': False,
                  'modes_changed': False, 'repair_requested': args.repair}
        report['other_root_owned_metadata_not_modified'] = other_root_metadata(ROOT, {item.path for item in held})
        if not args.repair:
            print('GO_ERP_GIT_READ_PREFLIGHT_OK files=' + str(len(report['files'])))
            return 0
        if not AUDITS.exists():
            AUDITS.mkdir(mode=0o700)
        audit_stat = AUDITS.lstat()
        need(stat.S_ISDIR(audit_stat.st_mode) and audit_stat.st_uid == 0
             and stat.S_IMODE(audit_stat.st_mode) == 0o700, 'AUDIT_DIRECTORY_UNSAFE')
        parents(AUDITS, 0)
        folder = write_audit(AUDITS, report)
        changed = change_owners(held, actor.pw_uid, actor.pw_gid)
        need(guard(ROOT) == before_guard and git(ROOT, ['rev-parse', '--verify', 'HEAD']).decode('ascii').strip() == head,
             'GUARDED_METADATA_CHANGED')
        commit_objects(ROOT, commits, 'fasakha')
        need(git(ROOT, ['rev-parse', '--verify', BACKUP_REF], actor='fasakha').decode('ascii').strip() == BACKUP_SHA,
             'ACTOR_REFERENCE_READ_FAILED')
        print('GO_ERP_GIT_READ_PERMISSIONS_REPAIRED files=' + str(changed))
        print('AUDIT_DIRECTORY=' + str(folder))
        print('APPLICATION_CONTENTS_CHANGED=false')
        return 0
    finally:
        for item in held:
            item.close()


if __name__ == '__main__':
    try:
        result = main()
    except Stop as error:
        print(str(error), file=sys.stderr)
        result = 1
    except Exception:
        print('GO_ERP_GIT_READ_STOPPED_REVIEW_PRIVATE_AUDIT', file=sys.stderr)
        result = 1
    sys.exit(result)
