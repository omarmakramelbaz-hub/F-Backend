#!/usr/bin/env python3
"""Apply only the reviewed store-family SKU resolver; never bootstrap the application."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import time


RELATIVE = Path('app/Services/Dashboard/FasakhanstaStoreFulfillment.php')
BEFORE = b'''                $candidates = (int) ($source->product_id ?? 0) > 0 ? ($shared[(int) $source->product_id] ?? []) : [];
                if (!$candidates) $candidates = $names[self::normalizedName((string) ($source->product_name ?? ''))] ?? [];
'''
AFTER = b'''                $candidates = (int) ($source->product_id ?? 0) > 0 ? ($shared[(int) $source->product_id] ?? []) : [];
                if (count($candidates) > 1) {
                    // The shared product identifies a family: regular/plus/top meals,
                    // jar weights and herring varieties can share it. Resolve only an
                    // exact displayed SKU within that family; never choose its first row.
                    $name = self::normalizedName((string) ($source->product_name ?? ''));
                    $named = array_values(array_filter($candidates, static fn ($candidate) => $name !== ''
                        && self::normalizedName((string) ($candidate->product_name ?? '')) === $name));
                    if ($named) $candidates = $named;
                }
                if (!$candidates) $candidates = $names[self::normalizedName((string) ($source->product_name ?? ''))] ?? [];
'''


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def regular(path):
    value = path.lstat()
    require(stat.S_ISREG(value.st_mode) and value.st_nlink == 1, 'UNSAFE_TARGET_FILE')
    require(value.st_uid == os.geteuid(), 'TARGET_OWNER_MISMATCH')
    return value


def apply(root, php, install):
    root = Path(root).absolute()
    require(root.resolve(strict=True) == root and root.is_dir(), 'UNSAFE_DOCUMENT_ROOT')
    target = root / RELATIVE
    for directory in RELATIVE.parents:
        require(not (root / directory).is_symlink(), 'SYMLINK_DIRECTORY_REFUSED')
    private = root.parent / 'go-erp-order-family-fix'
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.lstat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700, 'UNSAFE_BACKUP_DIRECTORY')
    lock = os.open(private / 'apply.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        require(stat.S_ISREG(os.fstat(lock).st_mode) and os.fstat(lock).st_nlink == 1, 'UNSAFE_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        original_info = regular(target)
        original = target.read_bytes()
        require(len(original) <= 1_000_000, 'TARGET_TOO_LARGE')
        require(b'class FasakhanstaStoreFulfillment' in original
                and b'public static function inventoryLines(' in original, 'TARGET_CLASS_MISMATCH')
        if original.count(AFTER) == 1 and original.count(BEFORE) == 0:
            return {'marker': 'GO_ERP_ORDER_FAMILY_RESOLUTION_READY', 'changed': False, 'sha256': sha(original)}
        require(original.count(BEFORE) == 1 and original.count(AFTER) == 0, 'REVIEWED_SOURCE_HUNK_NOT_UNIQUE')
        updated = original.replace(BEFORE, AFTER, 1)
        descriptor, temporary_name = tempfile.mkstemp(prefix='.go-order-family-', suffix='.php', dir=target.parent)
        temporary = Path(temporary_name)
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(updated)
                stream.flush()
                os.fchmod(stream.fileno(), stat.S_IMODE(original_info.st_mode))
                current = os.fstat(stream.fileno())
                if (current.st_uid, current.st_gid) != (original_info.st_uid, original_info.st_gid):
                    os.fchown(stream.fileno(), original_info.st_uid, original_info.st_gid)
                os.fsync(stream.fileno())
            lint = subprocess.run([str(php), '-l', str(temporary)], capture_output=True, timeout=30)
            require(lint.returncode == 0, 'PATCHED_PHP_SYNTAX_FAILED')
            current_info = regular(target)
            require((current_info.st_dev, current_info.st_ino, current_info.st_mtime_ns,
                     current_info.st_mode, current_info.st_gid)
                    == (original_info.st_dev, original_info.st_ino, original_info.st_mtime_ns,
                        original_info.st_mode, original_info.st_gid)
                    and target.read_bytes() == original, 'TARGET_CHANGED_DURING_REVIEW')
            result = {'marker': 'GO_ERP_ORDER_FAMILY_RESOLUTION_CHECKED', 'changed': False,
                      'before_sha256': sha(original), 'after_sha256': sha(updated)}
            if not install:
                return result
            backup = private / (str(time.time_ns()) + '-' + sha(original)[:16] + '.php')
            backup_fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(backup_fd, 'wb') as stream:
                stream.write(original)
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, target)
            require(target.read_bytes() == updated, 'PUBLISHED_SOURCE_VERIFICATION_FAILED')
            result.update(marker='GO_ERP_ORDER_FAMILY_RESOLUTION_READY', changed=True, backup=str(backup))
            return result
        finally:
            temporary.unlink(missing_ok=True)
    finally:
        os.close(lock)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    arguments = parser.parse_args()
    try:
        print(json.dumps(apply(arguments.root, arguments.php, arguments.apply), sort_keys=True))
    except Exception as error:
        print('GO_ERP_ORDER_FAMILY_RESOLUTION_FAILED: ' + str(error))
        raise SystemExit(1)
