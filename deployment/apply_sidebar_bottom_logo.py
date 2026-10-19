#!/usr/bin/env python3
"""Publish only the shared sidebar footer style and navigation fitting function."""
import argparse
import contextlib
import ctypes
from dataclasses import dataclass
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import stat
import subprocess
import tempfile


BASE_REVISION = 'e4adbb74ffb383ab8ce91f661537bc0c31193100'
PRODUCTION_ROOT = Path('/home/fasakha/public_html')
NAVIGATION = 'public/dashboard/js/dashboard-navigation.js'
BRANDING = 'public/dashboard/branding/dashboard-brand.css'
FUNCTION_START = b'    function fitNavigation() {\n'
FUNCTION_END = b'\n    fitNavigation();'
STYLE_START = b'/* dashboard-sidebar-footer:start */'
STYLE_END = b'/* dashboard-sidebar-footer:end */'
MAX_BYTES = 4 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute() and '..' not in path.parts
            and str(path) == os.path.normpath(str(path)), 'CANONICAL_ABSOLUTE_PATH_REQUIRED')
    for parent in reversed((path, *path.parents)):
        try:
            info = parent.lstat()
        except FileNotFoundError:
            continue
        require(not stat.S_ISLNK(info.st_mode), 'SYMLINK_REJECTED')
        if parent != path:
            require(stat.S_ISDIR(info.st_mode), 'DIRECTORY_ANCESTOR_REQUIRED')


@dataclass(frozen=True)
class Snapshot:
    body: bytes
    uid: int
    gid: int
    mode: int
    device: int
    inode: int
    modified: int


def snapshot(path):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(descriptor)
        require(stat.S_ISREG(before.st_mode) and before.st_size <= MAX_BYTES,
                'REGULAR_BOUNDED_FILE_REQUIRED')
        with os.fdopen(descriptor, 'rb', closefd=False) as stream:
            body = stream.read(MAX_BYTES + 1)
        after = os.fstat(descriptor)
        require((before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                == (after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                and len(body) == before.st_size and b'\x00' not in body,
                'FILE_CHANGED_DURING_READ')
        return Snapshot(body, before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode),
                        before.st_dev, before.st_ino, before.st_mtime_ns)
    finally:
        os.close(descriptor)


def digest(body):
    return hashlib.sha256(body).hexdigest()


def source(root, revision, relative):
    require(re.fullmatch(r'[a-f0-9]{40}', revision) is not None, 'FULL_REVISION_REQUIRED')
    result = subprocess.run(['git', '-C', str(root), 'show', revision + ':' + relative],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
    require(result.returncode == 0 and 0 < len(result.stdout) <= MAX_BYTES
            and b'\x00' not in result.stdout, 'VERIFIED_RELEASE_SOURCE_REQUIRED: ' + relative)
    return result.stdout


def function_span(body):
    require(body.count(FUNCTION_START) == 1 and body.count(FUNCTION_END) == 1
            and body.count(b'function fitNavigation(') == 1,
            'NAVIGATION_FUNCTION_BOUNDARIES_NEED_REVIEW')
    start = body.index(FUNCTION_START)
    end = body.index(FUNCTION_END)
    require(end > start and body[start:end].endswith(b'\n    }'),
            'NAVIGATION_FUNCTION_BODY_NEEDS_REVIEW')
    return start, end


def style_span(body, required=False):
    starts, ends = body.count(STYLE_START), body.count(STYLE_END)
    if starts == ends == 0:
        require(not required and b'dashboard-sidebar-footer:' not in body,
                'SIDEBAR_STYLE_MARKERS_REQUIRED')
        return None
    require(starts == ends == 1 and body.count(b'dashboard-sidebar-footer:') == 2,
            'SIDEBAR_STYLE_MARKERS_NEED_REVIEW')
    start, end = body.index(STYLE_START), body.index(STYLE_END) + len(STYLE_END)
    require(end > start + len(STYLE_START)
            and (start == 0 or body[start - 1:start] == b'\n')
            and body[start + len(STYLE_START):start + len(STYLE_START) + 1] == b'\n'
            and body[end - len(STYLE_END) - 1:end - len(STYLE_END)] == b'\n'
            and (end == len(body) or body[end:end + 1] == b'\n'),
            'SIDEBAR_STYLE_BOUNDARIES_NEED_REVIEW')
    return start, end


def plan(navigation, branding, old_source, new_source, release_branding):
    old_start, old_end = function_span(old_source)
    new_start, new_end = function_span(new_source)
    old_function, new_function = old_source[old_start:old_end], new_source[new_start:new_end]
    require(old_function != new_function, 'NAVIGATION_RELEASE_CHANGE_REQUIRED')
    start, end = function_span(navigation)
    require(navigation[start:end] in (old_function, new_function),
            'LIVE_NAVIGATION_FUNCTION_NEEDS_REVIEW')
    navigation = navigation[:start] + new_function + navigation[end:]
    start, end = style_span(release_branding, required=True)
    block = release_branding[start:end]
    installed = style_span(branding)
    if installed:
        require(branding[installed[0]:installed[1]] == block,
                'LIVE_SIDEBAR_STYLE_NEEDS_REVIEW')
    else:
        branding += (b'' if branding.endswith(b'\n') else b'\n') + b'\n' + block + b'\n'
    return navigation, branding


def write_new(path, body, uid, gid, mode):
    safe(path)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        os.fchown(descriptor, uid, gid)
        os.fchmod(descriptor, mode)
        with os.fdopen(descriptor, 'wb', closefd=False) as stream:
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
    finally:
        os.close(descriptor)


@contextlib.contextmanager
def critical():
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)


def exchange_function():
    library = ctypes.CDLL(None, use_errno=True)
    operation = library.renameat2
    operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    operation.restype = ctypes.c_int

    def exchange(first, second):
        if operation(-100, os.fsencode(first), -100, os.fsencode(second), 2) != 0:
            raise OSError(ctypes.get_errno(), 'ATOMIC_EXCHANGE_FAILED')
    return exchange


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=PRODUCTION_ROOT)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--inspect', action='store_true', help='Read only; permits a local --root for verification.')
    args = parser.parse_args()
    root = args.root
    safe(root)
    require(root.is_dir() and root.resolve() == root, 'CANONICAL_APPLICATION_ROOT_REQUIRED')
    require(re.fullmatch(r'[a-f0-9]{40}', args.revision) is not None, 'FULL_REVISION_REQUIRED')
    owner = None
    if root == PRODUCTION_ROOT:
        owner = pwd.getpwnam('fasakha')
        require(root.stat().st_uid == owner.pw_uid, 'APPLICATION_ROOT_OWNER_NEEDS_REVIEW')
    if not args.inspect:
        require(root == PRODUCTION_ROOT, 'PRODUCTION_ROOT_REQUIRED_FOR_WRITE')
        require(os.geteuid() == owner.pw_uid,
                'RUN_AS_APPLICATION_OWNER')
    paths = [root / NAVIGATION, root / BRANDING]
    originals = [snapshot(path) for path in paths]
    if owner:
        require(all(item.uid == owner.pw_uid for item in originals), 'ASSET_OWNER_NEEDS_REVIEW')
    desired = plan(*(item.body for item in originals), source(root, BASE_REVISION, NAVIGATION),
                   source(root, args.revision, NAVIGATION), source(root, args.revision, BRANDING))
    changed = [index for index in range(2) if originals[index].body != desired[index]]
    for index, path in enumerate(paths):
        print(('UPDATE ' if index in changed else 'CURRENT ') + str(path.relative_to(root)), flush=True)
    if args.inspect or not changed:
        print(('SIDEBAR_FOOTER_INSPECTION_OK ' if args.inspect else 'SIDEBAR_FOOTER_ALREADY_CURRENT ') + args.revision)
        return

    private = root.parent / 'sidebar-footer-backups'
    safe(private)
    try:
        private.mkdir(mode=0o700)
    except FileExistsError:
        pass
    metadata = private.lstat()
    require(stat.S_ISDIR(metadata.st_mode) and metadata.st_uid == owner.pw_uid
            and stat.S_IMODE(metadata.st_mode) == 0o700, 'PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW')
    descriptor = os.open(private / 'apply.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'a+b') as lock:
        metadata = os.fstat(lock.fileno())
        require(stat.S_ISREG(metadata.st_mode) and metadata.st_uid == owner.pw_uid
                and stat.S_IMODE(metadata.st_mode) == 0o600, 'PRIVATE_LOCK_NEEDS_REVIEW')
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir=private))
        require(backup.stat().st_dev == root.stat().st_dev, 'SAME_FILESYSTEM_REQUIRED')
        exchange = exchange_function()
        first, second = backup / 'probe-a', backup / 'probe-b'
        write_new(first, b'a', owner.pw_uid, owner.pw_gid, 0o600)
        write_new(second, b'b', owner.pw_uid, owner.pw_gid, 0o600)
        exchange(first, second)
        require(first.read_bytes() == b'b' and second.read_bytes() == b'a', 'ATOMIC_EXCHANGE_PROBE_FAILED')
        first.unlink()
        second.unlink()
        receipt = {'revision': args.revision, 'base': BASE_REVISION, 'assets': []}
        staged, journal = {}, []
        for index, path in enumerate(paths):
            require(snapshot(path) == originals[index], 'ASSET_CHANGED_BEFORE_BACKUP')
            item = originals[index]
            write_new(backup / ('before-' + str(index)), item.body, owner.pw_uid, owner.pw_gid, 0o600)
            receipt['assets'].append({'path': str(path.relative_to(root)), 'before_sha256': digest(item.body),
                                      'after_sha256': digest(desired[index]), 'uid': item.uid, 'gid': item.gid, 'mode': item.mode})
            if index in changed:
                staged[index] = backup / ('exchange-' + str(index))
                write_new(staged[index], desired[index], item.uid, item.gid, item.mode)
        write_new(backup / 'receipt.json', json.dumps(receipt, indent=2).encode() + b'\n', owner.pw_uid, owner.pw_gid, 0o600)

        def interrupted(signum, frame):
            raise RuntimeError('INSTALLATION_INTERRUPTED')
        signal.signal(signal.SIGINT, interrupted)
        signal.signal(signal.SIGTERM, interrupted)
        try:
            for index in changed:
                path, temporary = paths[index], staged[index]
                after = snapshot(temporary)
                record = {'path': path, 'temporary': temporary, 'after': after, 'published': False}
                journal.append(record)
                with critical():
                    require(snapshot(path) == originals[index], 'ASSET_CHANGED_BEFORE_PUBLICATION')
                    exchange(temporary, path)
                    record['published'] = True
                    require(snapshot(temporary) == originals[index], 'ASSET_CHANGED_DURING_PUBLICATION')
                    require(snapshot(path) == after, 'ASSET_CHANGED_AFTER_PUBLICATION')
            require(all(snapshot(path).body == desired[index] for index, path in enumerate(paths)), 'FINAL_ASSET_CHECK_FAILED')
            print('SIDEBAR_FOOTER_UPDATE_OK ' + args.revision + '\nBACKUP ' + str(backup))
        except BaseException:
            restored = True
            for record in reversed(journal):
                if not record['published']:
                    continue
                try:
                    with critical():
                        require(snapshot(record['path']) == record['after'], 'CONCURRENT_EDIT_RETAINED')
                        exchange(record['temporary'], record['path'])
                except (OSError, RuntimeError):
                    restored = False
            print('ROLLBACK ' + ('RESTORED' if restored else 'REQUIRES_REVIEW_CONCURRENT_EDIT_RETAINED') + '\nBACKUP ' + str(backup))
            raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('STOPPED: ' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__))
