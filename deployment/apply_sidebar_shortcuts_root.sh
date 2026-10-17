#!/usr/bin/env bash
set -euo pipefail

# Publish exactly two reviewed browser assets. This never changes the checkout
# revision, environment, PHP source, caches, routes, database, or installed apps.
revision=
project=/home/fasakha/public_html
inspect=0
while (($#)); do
    case "$1" in
        --revision) revision="${2:?A revision is required}"; shift 2 ;;
        --root) project="${2:?An application path is required}"; shift 2 ;;
        --inspect) inspect=1; shift ;;
        --help)
            printf '%s\n' 'Usage: bash apply_sidebar_shortcuts_root.sh --revision FULL_SHA [--inspect] [--root APPLICATION_PATH]' \
                'Run as root or fasakha. --inspect reads an already fetched revision without making changes.'
            exit 0 ;;
        *) printf '%s\n' 'STOPPED: UNKNOWN_ARGUMENT' >&2; exit 1 ;;
    esac
done
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || { printf '%s\n' 'STOPPED: FULL_REVISION_REQUIRED' >&2; exit 1; }
command -v python3 >/dev/null
command -v git >/dev/null

python3 - "$project" "$revision" "$inspect" <<'SIDEBAR_PY'
import contextlib
import ctypes
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import signal
import stat
import subprocess
import sys
import tempfile
from dataclasses import dataclass

OWNER = 'fasakha'
BRANCH = 'refs/heads/codex/sidebar-whatsapp-desktop-20261010'
ASSETS = (
    ('public/dashboard/js/dashboard-navigation.js',
     'f96f6393c3d5c1b8d89d347e69bfe7aeae0fa74101e85923a301de9de24bb550',
     'cd830481b13e4d666d69f8582eeeef26eecf7de3e18e7773dfdf8ce399815ec1'),
    ('public/dashboard/branding/dashboard-brand.css',
     '84bbf64d110db8d97eb030c3d1e944b6663da7ab080d16d56363722c81d3ab5a',
     'ea1625740cd940edb3e70b6ffc4f56b0ea5786a85c1875f94cb6a2a71f934126'),
)

class Stopped(Exception):
    pass

@dataclass(frozen=True)
class Snapshot:
    body: bytes
    uid: int
    gid: int
    mode: int
    device: int
    inode: int
    modified: int

def digest(body):
    return hashlib.sha256(body).hexdigest()

def ancestors(path):
    if not path.is_absolute() or '..' in path.parts:
        raise Stopped('UNSAFE_PATH')
    for parent in reversed(path.parents):
        meta = parent.lstat()
        if not stat.S_ISDIR(meta.st_mode):
            raise Stopped('SYMLINK_OR_NON_DIRECTORY_ANCESTOR')

def snapshot(path):
    ancestors(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(descriptor)
        if not stat.S_ISREG(before.st_mode) or before.st_size > 1024 * 1024:
            raise Stopped('ASSET_TYPE_OR_SIZE_INVALID')
        with os.fdopen(descriptor, 'rb', closefd=False) as stream:
            body = stream.read(1024 * 1024 + 1)
        after = os.fstat(descriptor)
        if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (
                after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise Stopped('ASSET_CHANGED_DURING_READ')
        return Snapshot(body, before.st_uid, before.st_gid,
                        stat.S_IMODE(before.st_mode), before.st_dev,
                        before.st_ino, before.st_mtime_ns)
    finally:
        os.close(descriptor)

def write_private(path, body, meta, uid, gid):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                         meta.mode if meta else 0o600)
    try:
        os.fchown(descriptor, meta.uid if meta else uid, meta.gid if meta else gid)
        os.fchmod(descriptor, meta.mode if meta else 0o600)
        with os.fdopen(descriptor, 'wb', closefd=False) as stream:
            stream.write(body)
            stream.flush()
            os.fsync(descriptor)
    finally:
        os.close(descriptor)

def git(arguments, owner_uid, inspect=False):
    prefix = []
    if os.geteuid() == 0 and not inspect:
        prefix = ['runuser', '-u', OWNER, '--']
    result = subprocess.run(prefix + ['git', '-c', 'safe.directory=' + str(root),
        '-C', str(root)] + arguments, stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL, timeout=60)
    if result.returncode:
        raise Stopped('GIT_READ_OR_FETCH_FAILED')
    return result.stdout

@contextlib.contextmanager
def critical():
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)

def exchange(first, second):
    operation = libc.renameat2
    if operation(-100, os.fsencode(first), -100, os.fsencode(second), 2) != 0:
        raise Stopped('ATOMIC_EXCHANGE_FAILED')

def interrupted(signum, frame):
    raise Stopped('INTERRUPTED')

root = pathlib.Path(sys.argv[1])
revision = sys.argv[2]
inspect = sys.argv[3] == '1'
backup = None
journal = []
lock = None
try:
    try:
        account = pwd.getpwnam(OWNER)
    except KeyError:
        account = None
    if os.geteuid() != 0 and (account is None or os.geteuid() != account.pw_uid):
        raise Stopped('RUN_AS_ROOT_OR_FASAKHA')
    if not inspect and account is None:
        raise Stopped('APPLICATION_OWNER_MISSING')
    ancestors(root / 'artisan')
    if not root.is_dir() or root.is_symlink() or not (root / 'artisan').is_file():
        raise Stopped('APPLICATION_ROOT_INVALID')
    owner_uid = account.pw_uid if account else os.geteuid()
    owner_gid = account.pw_gid if account else os.getegid()
    if not inspect:
        if os.geteuid() == 0 and subprocess.run(['sh', '-c', 'command -v runuser'],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
            raise Stopped('RUNUSER_REQUIRED')
        git(['fetch', '--no-tags', '--no-prune', '--no-recurse-submodules',
             '--refmap=', 'origin', BRANCH], owner_uid)
        if git(['rev-parse', 'FETCH_HEAD'], owner_uid).decode().strip() != revision:
            raise Stopped('RELEASE_BRANCH_CHANGED')
    if git(['cat-file', '-t', revision], owner_uid, inspect).strip() != b'commit':
        raise Stopped('REVISION_NOT_A_COMMIT')
    staged = []
    for relative, old_hash, new_hash in ASSETS:
        tree = git(['ls-tree', '-z', revision, relative], owner_uid, inspect)
        if not tree.startswith(b'100644 blob ') or not tree.endswith(b'\t' + relative.encode() + b'\0'):
            raise Stopped('RELEASE_ASSET_NOT_REGULAR')
        body = git(['show', revision + ':' + relative], owner_uid, inspect)
        if digest(body) != new_hash:
            raise Stopped('RELEASE_ASSET_HASH_MISMATCH')
        before = snapshot(root / relative)
        current_hash = digest(before.body)
        if current_hash not in (old_hash, new_hash):
            raise Stopped('CURRENT_ASSET_NEEDS_REVIEW')
        if not inspect and before.uid != owner_uid:
            raise Stopped('CURRENT_ASSET_OWNER_NEEDS_REVIEW')
        staged.append((relative, body, before, new_hash))
        print('ASSET ' + relative + ' ' + ('CURRENT' if current_hash == new_hash else 'UPDATE'), flush=True)
    if inspect:
        print('SIDEBAR_INSPECTION_OK ' + revision)
        sys.exit(0)
    if all(digest(before.body) == new_hash for _, _, before, new_hash in staged):
        print('SIDEBAR_UPDATE_ALREADY_CURRENT ' + revision)
        sys.exit(0)
    try:
        libc = ctypes.CDLL(None, use_errno=True)
        libc.renameat2.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
        libc.renameat2.restype = ctypes.c_int
    except (AttributeError, OSError):
        raise Stopped('ATOMIC_EXCHANGE_UNAVAILABLE')
    private = root.parent / 'sidebar-release-backups'
    ancestors(private)
    try:
        private.mkdir(mode=0o700)
        os.chown(private, owner_uid, owner_gid)
    except FileExistsError:
        pass
    meta = private.lstat()
    if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != owner_uid or stat.S_IMODE(meta.st_mode) != 0o700:
        raise Stopped('PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW')
    lock = os.open(private / 'sidebar.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    lock_meta = os.fstat(lock)
    if not stat.S_ISREG(lock_meta.st_mode) or lock_meta.st_uid not in (os.geteuid(), owner_uid):
        raise Stopped('LOCK_OWNER_OR_TYPE_INVALID')
    os.fchown(lock, owner_uid, owner_gid)
    os.fchmod(lock, 0o600)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise Stopped('ANOTHER_SIDEBAR_UPDATE_IS_RUNNING')
    backup = pathlib.Path(tempfile.mkdtemp(prefix='sidebar-' + revision[:8] + '-', dir=private))
    os.chown(backup, owner_uid, owner_gid)
    if backup.stat().st_dev != root.stat().st_dev:
        raise Stopped('BACKUP_AND_ASSETS_MUST_SHARE_FILESYSTEM')
    # Verify exchange support before changing an application file.
    first, second = backup / 'exchange-probe-a', backup / 'exchange-probe-b'
    write_private(first, b'a', None, owner_uid, owner_gid)
    write_private(second, b'b', None, owner_uid, owner_gid)
    exchange(first, second)
    if first.read_bytes() != b'b' or second.read_bytes() != b'a':
        raise Stopped('ATOMIC_EXCHANGE_PROBE_FAILED')
    first.unlink()
    second.unlink()
    receipt = {'revision': revision, 'assets': []}
    for index, (relative, body, before, new_hash) in enumerate(staged):
        if snapshot(root / relative) != before:
            raise Stopped('ASSET_CHANGED_BEFORE_BACKUP')
        write_private(backup / ('before-' + str(index)), before.body, before, owner_uid, owner_gid)
        receipt['assets'].append({'path': relative, 'before_sha256': digest(before.body),
            'after_sha256': new_hash, 'uid': before.uid, 'gid': before.gid, 'mode': before.mode})
    write_private(backup / 'receipt.json', json.dumps(receipt, indent=2).encode() + b'\n',
                  None, owner_uid, owner_gid)
    signal.signal(signal.SIGINT, interrupted)
    signal.signal(signal.SIGTERM, interrupted)
    for index, (relative, body, before, new_hash) in enumerate(staged):
        if digest(before.body) == new_hash:
            continue
        target = root / relative
        temporary = backup / ('exchanged-' + str(index))
        write_private(temporary, body, before, owner_uid, owner_gid)
        after = snapshot(temporary)
        record = {'target': target, 'temporary': temporary, 'after': after, 'published': False}
        journal.append(record)
        with critical():
            if snapshot(target) != before:
                raise Stopped('ASSET_CHANGED_BEFORE_PUBLICATION')
            exchange(temporary, target)
            record['published'] = True
            # Preserve the actual displaced inode. A last-moment competing edit
            # is restored safely if it changed the reviewed original.
            if snapshot(temporary) != before:
                raise Stopped('ASSET_CHANGED_DURING_PUBLICATION')
            if snapshot(target) != after:
                raise Stopped('ASSET_CHANGED_AFTER_PUBLICATION')
    for relative, body, before, new_hash in staged:
        if digest(snapshot(root / relative).body) != new_hash:
            raise Stopped('FINAL_ASSET_CHECK_FAILED')
    print('SIDEBAR_UPDATE_OK ' + revision)
    print('BACKUP ' + str(backup))
except (Stopped, OSError, subprocess.SubprocessError, ValueError) as error:
    restored = True
    for record in reversed(journal):
        if not record['published']:
            continue
        try:
            with critical():
                if snapshot(record['target']) != record['after']:
                    raise Stopped('CONCURRENT_EDIT_RETAINED')
                exchange(record['temporary'], record['target'])
        except (Stopped, OSError):
            restored = False
    print('STOPPED: ' + (str(error) if isinstance(error, Stopped) else 'LOCAL_OPERATION_FAILED'), file=sys.stderr)
    if journal:
        print('ROLLBACK ' + ('RESTORED' if restored else 'REQUIRES_REVIEW_CONCURRENT_EDIT_RETAINED'), file=sys.stderr)
    if backup:
        print('BACKUP ' + str(backup), file=sys.stderr)
    sys.exit(1)
finally:
    if lock is not None:
        os.close(lock)
SIDEBAR_PY
