#!/usr/bin/env python3
"""Remove one named obsolete full backup after verifying its sealed replacement."""
import argparse
from contextlib import contextmanager
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import socket
import stat
import sys
import threading
import time
import uuid

BASE = Path('/root/fasakhansta-full-backups')
LATEST_NAME = '20261025T215627Z-fresh-full-runpgcme'
OLD_NAME = '20261020T000547Z-current-bwj11opo'
MARKER = b'FRESH_FULL_BACKUP_VERIFIED\nLAYOUT=SPLIT_HOME_SERVER_RUNTIME\n'
REQUIRED = {'manifest.json', 'RESTORE-NOTES.txt', 'database-inventory.json',
            'home-inventory.json', 'server-runtime-inventory.json',
            'account/cpmove-fasakha.tar.gz', 'home-current.tar.gz',
            'server-config.tar.gz', 'server-runtime.tar.gz'}
HOME_FILES = {'public_html/artisan', 'public_html/.env', 'public_html/vendor/autoload.php'}
SHA = re.compile(r'[a-f0-9]{64}\Z')
DATED = re.compile(r'([0-9]{8}T[0-9]{6}Z)(?:-|\Z)')
QUARANTINE_PREFIX = '.pruning-' + OLD_NAME + '-'


class Stop(Exception):
    pass


def need(condition, code):
    if not condition:
        raise Stop(code)


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def json_bytes(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False) + '\n').encode()


def parse_json(raw):
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, 'DUPLICATE_JSON_KEY')
            result[key] = value
        return result
    def invalid(_):
        raise Stop('NONFINITE_JSON')
    result = json.loads(raw.decode('utf-8'), object_pairs_hook=pairs, parse_constant=invalid)
    need(isinstance(result, dict), 'JSON_OBJECT_REQUIRED')
    return result


def safe_ancestors(path):
    need(path.is_absolute() and '..' not in path.parts, 'ABSOLUTE_CANONICAL_PATH_REQUIRED')
    chain = []
    for parent in (*reversed(path.parents), path):
        item = parent.lstat()
        sticky_parent = parent != path and bool(item.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0 and item.st_gid == 0
             and (not item.st_mode & 0o022 or sticky_parent), 'DIRECTORY_OWNER_MODE_OR_LINK')
        chain.append((parent, identity(item)[:5]))
    need(stat.S_IMODE(path.lstat().st_mode) == 0o700, 'BACKUP_BASE_NOT_PRIVATE')
    return chain


def safe_item(info, device):
    need(info.st_dev == device, 'CROSS_FILESYSTEM_ENTRY')
    need(info.st_uid == 0 and info.st_gid == 0 and not info.st_mode & 0o022,
         'ENTRY_OWNER_OR_MODE')
    need(stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode), 'LINK_OR_SPECIAL_ENTRY')
    if stat.S_ISREG(info.st_mode):
        need(info.st_nlink == 1, 'HARDLINK_ENTRY')


def reject_mounts(path):
    # st_dev alone does not detect bind mounts on the same filesystem.
    with open('/proc/self/mountinfo', 'rb') as stream:
        raw = stream.read(16 * 1024**2 + 1)
    need(len(raw) <= 16 * 1024**2, 'MOUNT_TABLE_TOO_LARGE')
    for line in raw.splitlines():
        fields = line.split()
        need(len(fields) >= 10 and b'-' in fields, 'MOUNT_TABLE_INVALID')
        target = re.sub(br'\\([0-7]{3})', lambda m: bytes([int(m.group(1), 8)]), fields[4])
        mount = Path(os.fsdecode(target))
        need(mount != path and path not in mount.parents, 'BACKUP_CONTAINS_MOUNT')


def scan_tree(fd, device):
    result = {}
    def walk(current, prefix):
        info = os.fstat(current)
        safe_item(info, device)
        result[prefix or '.'] = identity(info)
        for name in sorted(os.listdir(current)):
            item = os.stat(name, dir_fd=current, follow_symlinks=False)
            safe_item(item, device)
            relative = prefix + '/' + name if prefix else name
            need(len(result) < 250000, 'BACKUP_ENTRY_LIMIT')
            if stat.S_ISDIR(item.st_mode):
                child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=current)
                try:
                    need(identity(os.fstat(child)) == identity(item), 'DIRECTORY_CHANGED')
                    walk(child, relative)
                    need(identity(os.stat(name, dir_fd=current, follow_symlinks=False)) == identity(item),
                         'DIRECTORY_CHANGED')
                finally:
                    os.close(child)
            else:
                result[relative] = identity(item)
        need(identity(os.fstat(current)) == identity(info), 'DIRECTORY_CHANGED')
    walk(fd, '')
    return result


@contextmanager
def parent_fd(root_fd, relative):
    parts = PurePosixPath(relative).parts
    fd = os.dup(root_fd)
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        yield fd, parts[-1]
    finally:
        os.close(fd)


def read_file(root_fd, relative, expected, maximum):
    need(expected[6] <= maximum, 'METADATA_TOO_LARGE')
    with parent_fd(root_fd, relative) as pair:
        parent, name = pair
        need(identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected, 'FILE_CHANGED')
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            need(identity(os.fstat(fd)) == expected, 'FILE_CHANGED')
            chunks, total = [], 0
            while True:
                chunk = os.read(fd, 1024**2)
                if not chunk:
                    break
                total += len(chunk)
                need(total <= maximum and total <= expected[6], 'FILE_CHANGED')
                chunks.append(chunk)
            need(total == expected[6] and identity(os.fstat(fd)) == expected
                 and identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected, 'FILE_CHANGED')
            return b''.join(chunks)
        finally:
            os.close(fd)


def hash_file(root_fd, relative, expected):
    with parent_fd(root_fd, relative) as pair:
        parent, name = pair
        need(identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected, 'FILE_CHANGED')
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            need(identity(os.fstat(fd)) == expected, 'FILE_CHANGED')
            digest, size = hashlib.sha256(), 0
            while True:
                block = os.read(fd, 1024**2)
                if not block:
                    break
                size += len(block)
                need(size <= expected[6], 'FILE_CHANGED')
                digest.update(block)
            need(size == expected[6] and identity(os.fstat(fd)) == expected
                 and identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected, 'FILE_CHANGED')
            return digest.hexdigest()
        finally:
            os.close(fd)


def parse_sums(raw):
    need(raw.endswith(b'\n') and b'\x00' not in raw, 'CHECKSUM_FORMAT_INVALID')
    result = {}
    for row in raw[:-1].split(b'\n'):
        match = re.fullmatch(br'([a-f0-9]{64})  ([^\r\n\\]+)', row)
        need(match is not None, 'CHECKSUM_FORMAT_INVALID')
        name = match.group(2).decode('utf-8')
        path = PurePosixPath(name)
        need(name and not path.is_absolute() and '..' not in path.parts
             and path.as_posix() == name and name not in ('.', 'backup.ok', 'SHA256SUMS'),
             'CHECKSUM_PATH_INVALID')
        need(name not in result, 'DUPLICATE_CHECKSUM_PATH')
        result[name] = match.group(1).decode('ascii')
    return result


class Progress:
    def __init__(self, seconds):
        self.seconds, self.event = seconds, threading.Event()
        self.started, self.stage = time.monotonic(), 'verify_latest_backup'
        self.thread = threading.Thread(target=self.beat, daemon=True)

    def beat(self):
        while not self.event.wait(self.seconds):
            print('FULL_BACKUP_PRUNE_PROGRESS stage=%s elapsed_seconds=%d' %
                  (self.stage, time.monotonic() - self.started), flush=True)

    def __enter__(self):
        need(self.seconds > 0, 'HEARTBEAT_INTERVAL_INVALID')
        self.thread.start()
        print('FULL_BACKUP_PRUNE_STAGE=verify_latest_backup', flush=True)
        return self

    def __exit__(self, *_):
        self.event.set()
        self.thread.join(timeout=1)


def verify_coverage(verified):
    manifest = verified.manifest
    need(type(manifest.get('format')) is int and manifest['format'] == 1
         and manifest.get('kind') == 'fasakhansta-fresh-full-backup'
         and manifest.get('layout') == 'split-home-plus-server-runtime'
         and manifest.get('account') == 'fasakha', 'LATEST_MANIFEST_INVALID')
    database = verified.metadata('database-inventory.json')
    names = database.get('databases')
    need(database.get('account') == 'fasakha' and isinstance(names, list) and len(names) == 2
         and len(set(names)) == 2 and all(isinstance(name, str) and
             re.fullmatch(r'fasakha_[A-Za-z0-9_]+', name) for name in names), 'DATABASE_INVENTORY_INVALID')
    sql = manifest.get('database_verification')
    need(isinstance(sql, dict) and set(sql) == set(names), 'TWO_DATABASES_NOT_VERIFIED')
    for entry in sql.values():
        need(isinstance(entry, dict) and type(entry.get('bytes')) is int and entry['bytes'] > 100
             and isinstance(entry.get('sha256'), str) and SHA.fullmatch(entry['sha256'])
             and entry.get('completion_footer') is True, 'DATABASE_COMPLETION_INVALID')
    verified.home_inventory = verified.metadata('home-inventory.json')
    home = manifest.get('home_verification')
    need(isinstance(home, dict) and type(home.get('inventory_entries_verified')) is int
         and home['inventory_entries_verified'] == len(verified.home_inventory) > 0
         and isinstance(home.get('required_live_files'), list)
         and set(home['required_live_files']) == HOME_FILES, 'HOME_COVERAGE_INVALID')
    for name in HOME_FILES:
        item = verified.home_inventory.get(name)
        need(isinstance(item, dict) and item.get('type') == 'file'
             and type(item.get('size')) is int and item['size'] > 0, 'HOME_REQUIRED_FILE_NOT_COVERED')
    verified.runtime_inventory = verified.metadata('server-runtime-inventory.json')
    runtime = manifest.get('server_runtime')
    need(isinstance(runtime, dict) and runtime.get('archive') == 'server-runtime.tar.gz'
         and runtime.get('inventory') == 'server-runtime-inventory.json', 'RUNTIME_COVERAGE_MISSING')
    roots = runtime.get('roots')
    need(isinstance(roots, list) and all(isinstance(root, str) for root in roots)
         and '/etc/go-erp' in roots and any(root.startswith('/opt/go-erp-') for root in roots)
         and any(root.startswith('/var/lib/go-erp-') for root in roots), 'RUNTIME_ROOTS_INVALID')
    files = [item for item in verified.runtime_inventory.values()
             if isinstance(item, dict) and item.get('type') == 'file']
    need(files and all(type(item.get('size')) is int and item['size'] >= 0 for item in files),
         'RUNTIME_INVENTORY_INVALID')
    need(type(runtime.get('inventory_entries')) is int
         and runtime['inventory_entries'] == runtime.get('inventory_entries_verified')
         == len(verified.runtime_inventory) > 0
         and runtime.get('regular_files') == len(files)
         and runtime.get('regular_file_bytes_verified') == sum(item['size'] for item in files) > 0
         and runtime.get('archive_bytes') == verified.snapshot['server-runtime.tar.gz'][6]
         and runtime.get('sha256') == verified.sums['server-runtime.tar.gz'], 'RUNTIME_COVERAGE_INVALID')


def remove_snapshot(fd, snapshot, prefix=''):
    expected_names = {name[len(prefix) + 1:] if prefix else name for name in snapshot
                      if name != '.' and (name.rsplit('/', 1)[0] if '/' in name else '') == prefix}
    need(set(os.listdir(fd)) == expected_names, 'OLD_DIRECTORY_CHANGED')
    for name in sorted(expected_names):
        relative = prefix + '/' + name if prefix else name
        expected = snapshot[relative]
        need(identity(os.stat(name, dir_fd=fd, follow_symlinks=False)) == expected, 'OLD_ENTRY_CHANGED')
        if stat.S_ISDIR(expected[2]):
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            try:
                need(identity(os.fstat(child)) == expected, 'OLD_DIRECTORY_CHANGED')
                remove_snapshot(child, snapshot, relative)
                need(identity(os.stat(name, dir_fd=fd, follow_symlinks=False))[:5] == expected[:5],
                     'OLD_DIRECTORY_CHANGED')
                os.rmdir(name, dir_fd=fd)
            finally:
                os.close(child)
        else:
            os.unlink(name, dir_fd=fd)
    need(not os.listdir(fd), 'OLD_DIRECTORY_CHANGED')
    os.fsync(fd)


class VerifiedBackup:
    def __init__(self, base, base_fd, chain, lock_fd, lock_before, latest_name, progress):
        self.base, self.base_fd, self.chain = base, base_fd, chain
        self.lock_fd, self.lock_before = lock_fd, lock_before
        self.latest_path, self.latest_name = base / latest_name, latest_name
        self.device, self.progress = os.fstat(base_fd).st_dev, progress
        self.root_fd = None

    def newer_guard(self):
        timestamp = DATED.match(self.latest_name).group(1)
        for name in os.listdir(self.base_fd):
            match = DATED.match(name)
            if name != self.latest_name and match and match.group(1) >= timestamp:
                item = os.stat(name, dir_fd=self.base_fd, follow_symlinks=False)
                need(not stat.S_ISDIR(item.st_mode) and not stat.S_ISLNK(item.st_mode),
                     'NEWER_OR_AMBIGUOUS_BACKUP_PRESENT')

    def base_check(self):
        for path, expected in self.chain:
            need(identity(path.lstat())[:5] == expected, 'BACKUP_BASE_CHANGED')
        need(identity(os.fstat(self.base_fd))[:5] == self.chain[-1][1], 'BACKUP_BASE_CHANGED')
        need(identity(os.fstat(self.lock_fd)) == self.lock_before
             and identity(os.stat('.fresh-full-backup.lock', dir_fd=self.base_fd,
                                  follow_symlinks=False)) == self.lock_before, 'BACKUP_LOCK_CHANGED')
        self.newer_guard()

    def check(self):
        self.base_check()
        reject_mounts(self.latest_path)
        need(identity(os.stat(self.latest_name, dir_fd=self.base_fd, follow_symlinks=False))
             == self.snapshot['.'] and scan_tree(self.root_fd, self.device) == self.snapshot,
             'LATEST_BACKUP_CHANGED')

    def metadata(self, name):
        return parse_json(read_file(self.root_fd, name, self.snapshot[name], 256 * 1024**2))

    def verify(self):
        self.base_check()
        reject_mounts(self.latest_path)
        before = os.stat(self.latest_name, dir_fd=self.base_fd, follow_symlinks=False)
        safe_item(before, self.device)
        need(stat.S_ISDIR(before.st_mode) and stat.S_IMODE(before.st_mode) == 0o700,
             'LATEST_BACKUP_NOT_PRIVATE_DIRECTORY')
        self.root_fd = os.open(self.latest_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                               dir_fd=self.base_fd)
        need(identity(os.fstat(self.root_fd)) == identity(before), 'LATEST_BACKUP_CHANGED')
        self.snapshot = scan_tree(self.root_fd, self.device)
        files = {name for name, item in self.snapshot.items() if stat.S_ISREG(item[2])}
        need(REQUIRED | {'backup.ok', 'SHA256SUMS'} <= files, 'REQUIRED_ARTIFACT_MISSING')
        need(read_file(self.root_fd, 'backup.ok', self.snapshot['backup.ok'], 1024) == MARKER,
             'LATEST_COMPLETION_MARKER_INVALID')
        self.sums = parse_sums(read_file(self.root_fd, 'SHA256SUMS', self.snapshot['SHA256SUMS'],
                                         64 * 1024**2))
        need(set(self.sums) == files - {'backup.ok', 'SHA256SUMS'}, 'CHECKSUM_COVERAGE_INVALID')
        for name, expected in sorted(self.sums.items()):
            need(hash_file(self.root_fd, name, self.snapshot[name]) == expected, 'LATEST_CHECKSUM_MISMATCH')
        self.manifest = self.metadata('manifest.json')
        verify_coverage(self)
        self.check()

    def prune_old(self, old_name=OLD_NAME, apply=True):
        need(old_name == OLD_NAME and self.latest_name == LATEST_NAME, 'EXACT_BACKUP_SCOPE_REQUIRED')
        self.check()
        need(not any(name.startswith(QUARANTINE_PREFIX) for name in os.listdir(self.base_fd)),
             'PREVIOUS_PRUNE_QUARANTINE_REQUIRES_REVIEW')
        old_path = self.base / old_name
        free_before = shutil.disk_usage(str(self.base)).free
        result = {'status': 'checked', 'latest_backup': str(self.latest_path),
                  'obsolete_backup': str(old_path), 'removed_backup': None,
                  'reclaimed_bytes': 0, 'free_bytes_before': free_before,
                  'free_bytes_after': free_before, 'database_count': 2,
                  'server_runtime_included': True, 'latest_backup_unchanged': True}
        try:
            before = os.stat(old_name, dir_fd=self.base_fd, follow_symlinks=False)
        except FileNotFoundError:
            result['status'] = 'already_pruned'
            return result
        safe_item(before, self.device)
        need(stat.S_ISDIR(before.st_mode) and stat.S_IMODE(before.st_mode) == 0o700,
             'OLD_BACKUP_NOT_PRIVATE_DIRECTORY')
        reject_mounts(old_path)
        old_fd = os.open(old_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=self.base_fd)
        try:
            need(identity(os.fstat(old_fd)) == identity(before), 'OLD_BACKUP_CHANGED')
            snapshot = scan_tree(old_fd, self.device)
            # Account all nested entries without reading any obsolete archive data.
            allocated = self.allocated_bytes(old_fd)
            result.update(planned_entries=len(snapshot), planned_allocated_bytes=allocated)
            self.check()
            need(scan_tree(old_fd, self.device) == snapshot
                 and identity(os.stat(old_name, dir_fd=self.base_fd, follow_symlinks=False)) == snapshot['.'],
                 'OLD_BACKUP_CHANGED')
            if not apply:
                return result
            audit_name = 'prune-audit-' + uuid.uuid4().hex
            os.mkdir(audit_name, 0o700, dir_fd=self.base_fd)
            audit_fd = os.open(audit_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=self.base_fd)
            quarantine = QUARANTINE_PREFIX + uuid.uuid4().hex
            audit = dict(result, status='verified_before_deletion', quarantine=str(self.base / quarantine),
                         recorded_utc=datetime.datetime.now(datetime.timezone.utc).isoformat())
            try:
                self.write_audit(audit_fd, audit)
                self.check()
                need(scan_tree(old_fd, self.device) == snapshot, 'OLD_BACKUP_CHANGED')
                os.rename(old_name, quarantine, src_dir_fd=self.base_fd, dst_dir_fd=self.base_fd)
                os.fsync(self.base_fd)
                renamed = os.stat(quarantine, dir_fd=self.base_fd, follow_symlinks=False)
                need(identity(renamed)[:5] == snapshot['.'][:5]
                     and identity(os.fstat(old_fd)) == identity(renamed), 'QUARANTINE_IDENTITY_CHANGED')
                snapshot['.'] = identity(renamed)
                reject_mounts(self.base / quarantine)
                need(scan_tree(old_fd, self.device) == snapshot, 'QUARANTINE_CONTENT_CHANGED')
                self.progress.stage = 'remove_named_obsolete_full_backup'
                print('FULL_BACKUP_PRUNE_STAGE=remove_named_obsolete_full_backup', flush=True)
                remove_snapshot(old_fd, snapshot)
                need(identity(os.stat(quarantine, dir_fd=self.base_fd, follow_symlinks=False))[:5]
                     == snapshot['.'][:5], 'QUARANTINE_IDENTITY_CHANGED')
                os.rmdir(quarantine, dir_fd=self.base_fd)
                os.fsync(self.base_fd)
                self.check()
                result.update(status='pruned', removed_backup=str(old_path), reclaimed_bytes=allocated,
                              free_bytes_after=shutil.disk_usage(str(self.base)).free,
                              audit_directory=str(self.base / audit_name))
                self.write_audit(audit_fd, result)
                return result
            except BaseException:
                audit['status'] = 'interrupted_or_failed_review_required'
                self.write_audit(audit_fd, audit)
                raise
            finally:
                os.close(audit_fd)
        finally:
            os.close(old_fd)

    @staticmethod
    def allocated_bytes(fd):
        total = os.fstat(fd).st_blocks * 512
        for name in os.listdir(fd):
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if stat.S_ISDIR(info.st_mode):
                child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                try:
                    total += VerifiedBackup.allocated_bytes(child)
                finally:
                    os.close(child)
            else:
                total += info.st_blocks * 512
        return total

    @staticmethod
    def write_audit(fd, value):
        temporary = '.audit-' + uuid.uuid4().hex
        output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                         0o600, dir_fd=fd)
        with os.fdopen(output, 'wb') as stream:
            stream.write(json_bytes(value))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, 'audit.json', src_dir_fd=fd, dst_dir_fd=fd)
        os.fsync(fd)


@contextmanager
def locked_backup(base=BASE, latest_name=LATEST_NAME, heartbeat_seconds=30):
    """Verify once; keep this context open around additional separately scoped cleanup.

    The yielded object exposes latest_path, manifest, sums, home_inventory,
    runtime_inventory, check(), and prune_old(). Never modify its sealed tree.
    """
    need(os.geteuid() == 0 and latest_name == LATEST_NAME, 'ROOT_AND_EXACT_LATEST_REQUIRED')
    base = Path(base)
    chain = safe_ancestors(base)
    base_fd = os.open(str(base), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    lock_fd, verified = None, None
    try:
        need(identity(os.fstat(base_fd))[:5] == chain[-1][1], 'BACKUP_BASE_CHANGED')
        lock_fd = os.open('.fresh-full-backup.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK,
                          0o600, dir_fd=base_fd)
        lock = os.fstat(lock_fd)
        safe_item(lock, os.fstat(base_fd).st_dev)
        need(stat.S_ISREG(lock.st_mode) and stat.S_IMODE(lock.st_mode) == 0o600, 'BACKUP_LOCK_UNSAFE')
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with Progress(heartbeat_seconds) as progress:
            verified = VerifiedBackup(base, base_fd, chain, lock_fd, identity(lock), latest_name, progress)
            verified.verify()
            yield verified
    finally:
        if verified is not None and verified.root_fd is not None:
            os.close(verified.root_fd)
        if lock_fd is not None:
            os.close(lock_fd)
        os.close(base_fd)


def prune(base=BASE, latest_name=LATEST_NAME, old_name=OLD_NAME, apply=False, heartbeat_seconds=30):
    with locked_backup(base, latest_name, heartbeat_seconds) as verified:
        return verified.prune_old(old_name, apply)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    flags = parser.add_mutually_exclusive_group(required=True)
    flags.add_argument('--check', action='store_true')
    flags.add_argument('--apply', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    result = prune(apply=options.apply)
    print(json_bytes(result).decode().strip(), flush=True)
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except BlockingIOError:
        print('FULL_BACKUP_PRUNE_BUSY_NOTHING_REMOVED', file=sys.stderr)
        raise SystemExit(75)
    except KeyboardInterrupt:
        print('FULL_BACKUP_PRUNE_INTERRUPTED_REVIEW_PRIVATE_AUDIT', file=sys.stderr)
        raise SystemExit(130)
    except Exception as error:
        code = str(error) if isinstance(error, Stop) else type(error).__name__
        print('FULL_BACKUP_PRUNE_BLOCKED_' + code, file=sys.stderr)
        raise SystemExit(1)
