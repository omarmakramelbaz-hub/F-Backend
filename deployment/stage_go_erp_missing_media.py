#!/usr/bin/env python3
"""Stage only the eight observed missing public images from identical backup paths."""
import hashlib
import json
import os
from pathlib import Path
import stat
import sys

PATHS = (
    'pending_vendor/68/6J3A7531-Edit1_30840_lg.jpg',
    'pending_vendor/69/6J3A7531-Edit1_30840_lg.jpg',
    'pending_vendor/70/6J3A7496-Edit-2-copy_19f8b_lg.jpg',
    'pending_vendor/95/6J3A7531-Edit1_30840_lg.jpg',
    'pending_vendor/96/6J3A7531-Edit1_30840_lg.jpg',
    'pending_vendor/97/6J3A7496-Edit-2-copy_19f8b_lg.jpg',
    'users/450/Artboard-2.png',
    'users/543/فسيخ-نبروه.jpg',
)
ROOTS = (
    '/home/cpmove-fasakha/homedir/public_html/storage/app/public',
    '/home/cpmove-fasakha/homedir/storage/app/public',
)
MAX_FILE = 16 * 1024 * 1024


def read_exact(root, relative):
    """Walk via descriptors: neither backup directories nor files may be links."""
    descriptor = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        parts = [*Path(root).parts[1:], *relative.split('/')]
        for segment in parts[:-1]:
            next_descriptor = os.open(segment, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                                      dir_fd=descriptor)
            os.close(descriptor)
            descriptor = next_descriptor
        file_descriptor = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                                  dir_fd=descriptor)
        try:
            before = os.fstat(file_descriptor)
            if not stat.S_ISREG(before.st_mode) or not 0 < before.st_size <= MAX_FILE:
                return None
            blocks, size = [], 0
            while True:
                block = os.read(file_descriptor, min(65536, MAX_FILE + 1 - size))
                if not block:
                    break
                blocks.append(block)
                size += len(block)
                if size > MAX_FILE:
                    return None
            after = os.fstat(file_descriptor)
            keys = ('st_dev', 'st_ino', 'st_mode', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
            if any(getattr(before, key) != getattr(after, key) for key in keys) or size != before.st_size:
                return None
            return b''.join(blocks)
        finally:
            os.close(file_descriptor)
    finally:
        os.close(descriptor)


def stage(destination, roots=ROOTS):
    destination = Path(destination)
    if not destination.is_absolute() or destination.is_symlink() or destination.resolve() != destination:
        raise ValueError('GO_ERP_MEDIA_BACKUP_STAGE_PATH')
    if not destination.is_dir() or list(destination.iterdir()):
        raise ValueError('GO_ERP_MEDIA_BACKUP_STAGE_NOT_EMPTY')
    staged, absent, ambiguous = [], [], []
    for relative in PATHS:
        candidates = {}
        for root in roots:
            try:
                data = read_exact(root, relative)
            except OSError:
                continue
            if data is not None:
                digest = hashlib.sha256(data).hexdigest()
                candidates[digest] = data
        if not candidates:
            absent.append(relative)
        elif len(candidates) > 1:
            ambiguous.append(relative)
        else:
            digest, data = next(iter(candidates.items()))
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            with target.open('xb') as stream:
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
            target.chmod(0o600)
            staged.append({'path': relative, 'bytes': len(data), 'sha256': digest})
    return {'result': 'GO_ERP_SAME_PATH_BACKUP_SCAN', 'staged_count': len(staged),
            'staged': staged, 'absent': absent, 'ambiguous': ambiguous}


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('GO_ERP_MEDIA_BACKUP_STAGE_ARGUMENTS')
    try:
        print(json.dumps(stage(sys.argv[1]), ensure_ascii=False, indent=2))
    except (OSError, ValueError):
        raise SystemExit('GO_ERP_MEDIA_BACKUP_STAGE_FAILED')
