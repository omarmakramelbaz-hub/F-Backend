#!/usr/bin/env python3
"""Add employees and payroll to the existing permitted header shortcuts."""
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


PRODUCTION_ROOT = Path('/home/fasakha/public_html')
NAVIGATION = 'public/dashboard/js/dashboard-navigation.js'
BRANDING = 'public/dashboard/branding/dashboard-brand.css'
SHORTCUT_MARKER = b'dashboard-collapsed-shortcuts'
FOOTER_MARKER = b'dashboard-sidebar-footer'
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


def block_span(body, marker=SHORTCUT_MARKER, required=False):
    start_marker, end_marker = b'/* ' + marker + b':start */', b'/* ' + marker + b':end */'
    starts, ends = body.count(start_marker), body.count(end_marker)
    if starts == ends == 0:
        require(not required and marker + b':' not in body, 'MARKED_BLOCK_REQUIRED')
        return None
    require(starts == ends == 1 and body.count(marker + b':') == 2,
            'MARKED_BLOCK_MARKERS_NEED_REVIEW')
    start = body.index(start_marker)
    end_start = body.index(end_marker)
    end = end_start + len(end_marker)
    require(end_start > start + len(start_marker)
            and (start == 0 or body[start - 1:start] == b'\n')
            and body[start + len(start_marker):start + len(start_marker) + 1] == b'\n'
            and body[end_start - 1:end_start] == b'\n'
            and (end == len(body) or body[end:end + 1] == b'\n')
            and body[start + len(start_marker):end_start].strip(),
            'MARKED_BLOCK_BOUNDARIES_NEED_REVIEW')
    return start, end


def prerequisites(navigation, branding):
    installed = block_span(navigation)
    if installed:
        navigation = navigation[:installed[0]] + navigation[installed[1]:]
    signatures = (
        b"const sidebar = document.getElementById('dashboard-sidebar');",
        b"const navigation = document.querySelector('[data-dashboard-navigation]');",
        b'    function fitNavigation() {\n',
        b'        const areaBottom = () =>',
        b"document.addEventListener('dashboard:page-loaded',",
    )
    require(all(navigation.count(signature) == 1 for signature in signatures),
            'INSTALLED_SHARED_NAVIGATION_REQUIRED')
    block_span(branding, FOOTER_MARKER, required=True)
    require(b'.dashboard-theme #dashboard-sidebar' in branding
            and b'dashboard-navigation-ready' in branding
            and b'.dashboard-brand' in branding,
            'INSTALLED_SHARED_SIDEBAR_STYLES_REQUIRED')


def entries_span(navigation):
    start, end = block_span(navigation, required=True)
    block = navigation[start:end]
    opening = b'    const entries = [\n'
    closing = b'\n    ];'
    require(block.count(opening) == 1 and block.count(closing) == 1,
            'SHORTCUT_ENTRIES_BOUNDARIES_NEED_REVIEW')
    first = start + block.index(opening)
    last = start + block.index(closing) + len(closing)
    require(last > first, 'SHORTCUT_ENTRIES_ORDER_INVALID')
    return first, last


def plan(navigation, branding, release_navigation, release_branding):
    prerequisites(navigation, branding)
    prerequisites(release_navigation, release_branding)
    new_start, new_end = entries_span(release_navigation)
    new_entries = release_navigation[new_start:new_end]
    stock = "        ['branch-stock', 'إضافة بضاعة', 'Add stock', 'fa-boxes', 'بضاعة', 'Stock']".encode()
    employee = "        ['employees', 'الموظفون والرواتب', 'Employees & payroll', 'fa-user-clock', 'الموظفون', 'Staff']".encode()
    tail = stock + b',\n' + employee
    require(new_entries.count(tail) == 1 and new_entries.count(b"['employees',") == 1,
            'VERIFIED_EMPLOYEE_SHORTCUT_REQUIRED')
    old_entries = new_entries.replace(tail, stock, 1)
    start, end = entries_span(navigation)
    require(navigation[start:end] in (old_entries, new_entries),
            'LIVE_SHORTCUT_ENTRIES_NEED_REVIEW')
    css_start, css_end = block_span(release_branding, b'dashboard-header-employee-shortcut', required=True)
    compact_style = release_branding[css_start:css_end]
    installed_style = block_span(branding, b'dashboard-header-employee-shortcut')
    if installed_style:
        require(branding[installed_style[0]:installed_style[1]] == compact_style,
                'LIVE_COMPACT_SHORTCUT_STYLE_NEEDS_REVIEW')
    else:
        branding += (b'' if branding.endswith(b'\n') else b'\n') + b'\n' + compact_style + b'\n'
    return navigation[:start] + new_entries + navigation[end:], branding


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
    desired = plan(*(item.body for item in originals), source(root, args.revision, NAVIGATION),
                   source(root, args.revision, BRANDING))
    changed = [index for index in range(2) if originals[index].body != desired[index]]
    for index, path in enumerate(paths):
        print(('UPDATE ' if index in changed else 'CURRENT ') + str(path.relative_to(root)), flush=True)
    if args.inspect or not changed:
        print(('HEADER_EMPLOYEE_SHORTCUT_INSPECTION_OK ' if args.inspect else 'HEADER_EMPLOYEE_SHORTCUT_ALREADY_CURRENT ') + args.revision)
        return

    private = root.parent / 'header-employee-shortcut-backups'
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
        receipt = {'revision': args.revision, 'assets': []}
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
            print('HEADER_EMPLOYEE_SHORTCUT_UPDATE_OK ' + args.revision + '\nBACKUP ' + str(backup))
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
