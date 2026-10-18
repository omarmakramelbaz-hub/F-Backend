#!/usr/bin/env python3
"""Shorten only the Arabic home branch option; preserve every other live byte."""
import argparse
import datetime
import fcntl
import os
from pathlib import Path
import stat
import subprocess
import tempfile

OLD = "'all_branches'=>'كل الفروع المسموح بها'".encode('utf-8')
NEW = "'all_branches'=>'كل الفروع'".encode('utf-8')


def require(value, message):
    if not value:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute() and all(not p.is_symlink() for p in (path, *path.parents)),
            'SYMLINK_OR_RELATIVE_PATH_REJECTED')


def apply(root, php):
    os.umask(0o077)
    source = root / 'resources/lang/ar/home_overview.php'
    private = root.parent / 'home-all-branches-label-backups'
    safe(source)
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700,
            'PRIVATE_BACKUP_DIRECTORY_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    with os.fdopen(os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600), 'r+') as lock:
        lock_info = os.fstat(lock.fileno())
        require(stat.S_ISREG(lock_info.st_mode) and lock_info.st_nlink == 1
                and lock_info.st_uid == os.geteuid()
                and stat.S_IMODE(lock_info.st_mode) == 0o600, 'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        original = source.lstat()
        require(stat.S_ISREG(original.st_mode) and original.st_nlink == 1
                and original.st_uid == os.geteuid(), 'OWNED_REGULAR_SOURCE_REQUIRED')
        before = source.read_bytes()
        require(len(before) < 256 * 1024 and b'\x00' not in before, 'SOURCE_INVALID')
        if OLD not in before:
            require(before.count(NEW) == 1, 'HOME_BRANCH_LABEL_NEEDS_REVIEW')
            print('HOME_ALL_BRANCHES_LABEL_OK already_current=1')
            return
        require(before.count(OLD) == 1 and NEW not in before, 'HOME_BRANCH_LABEL_NEEDS_REVIEW')
        after = before.replace(OLD, NEW, 1)
        with tempfile.TemporaryDirectory(prefix='label-preflight-', dir=private) as directory:
            candidate = Path(directory) / 'home_overview.php'
            candidate.write_bytes(after)
            check = subprocess.run([php, '-l', str(candidate)], capture_output=True, timeout=30)
            require(check.returncode == 0, 'HOME_BRANCH_LABEL_PHP_INVALID')
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir=private))
        (backup / 'home_overview.php').write_bytes(before)
        descriptor, temporary = tempfile.mkstemp(prefix='.home-label-', dir=source.parent)
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(after)
                stream.flush()
                os.fsync(stream.fileno())
                os.fchown(stream.fileno(), -1, original.st_gid)
                os.fchmod(stream.fileno(), stat.S_IMODE(original.st_mode))
            current = source.lstat()
            keys = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
            require(all(getattr(current, key) == getattr(original, key) for key in keys)
                    and source.read_bytes() == before,
                    'SOURCE_CHANGED_DURING_PREFLIGHT')
            os.replace(temporary, source)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
        print('HOME_ALL_BRANCHES_LABEL_OK; BACKUP=' + str(backup))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    args = parser.parse_args()
    try:
        apply(Path(args.root), args.php)
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        raise SystemExit('STOPPED: ' + str(error))
