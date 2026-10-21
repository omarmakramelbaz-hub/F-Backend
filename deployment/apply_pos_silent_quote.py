#!/usr/bin/env python3
"""Suppress the quote progress banner in the existing live POS script."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile

BASE_REVISION = '3585500fbb1fc1d853d60acf07906375521cd194'
SOURCE_PATH = 'public/dashboard/js/takeaway-pos.js'
BEFORE = b"        var payload = draft(); notify(label('calculating'));\n"
AFTER = b"        var payload = draft();\n"
MAX_BYTES = 4 * 1024 * 1024
MARKER = 'POS_SILENT_QUOTE'


def require(condition, message):
    if not condition:
        raise RuntimeError(MARKER + '_' + message)


def git(root, *arguments):
    environment = dict(os.environ, GIT_NO_REPLACE_OBJECTS='1')
    result = subprocess.run(['git', '-C', str(root), *arguments], env=environment,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90)
    require(result.returncode == 0, 'GIT_READ_FAILED')
    return result.stdout


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    require(all(not item.is_symlink() for item in (path, *path.parents)), 'SYMLINK_REJECTED')


def read_owned(path):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(),
                'OWNED_REGULAR_FILE_REQUIRED')
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body, 'SOURCE_INVALID')
    return body, info


def release_block(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    require(git(root, 'rev-parse', '--verify', revision + '^{commit}').decode().strip() == revision,
            'PINNED_REVISION_REQUIRED')
    before = git(root, 'show', BASE_REVISION + ':' + SOURCE_PATH)
    after = git(root, 'show', revision + ':' + SOURCE_PATH)
    require(len(before) <= MAX_BYTES and len(after) <= MAX_BYTES and b'\x00' not in before + after,
            'RELEASE_SOURCE_INVALID')
    require(before.count(BEFORE) == 1 and after.count(AFTER) == 1,
            'RELEASE_ANCHOR_MISMATCH')
    require(after.replace(AFTER, BEFORE, 1) == before,
            'RELEASE_SCOPE_MISMATCH')
    return AFTER


def transform(body, block):
    if body.count(block) == 1 and body.count(BEFORE) == 0:
        return body
    require(body.count(BEFORE) == 1 and body.count(block) == 0, 'LIVE_ANCHOR_MISMATCH')
    return body.replace(BEFORE, block, 1)


def same_version(path, original):
    body, info = read_owned(path)
    old_body, old = original
    return (body == old_body and info.st_ino == old.st_ino and info.st_dev == old.st_dev
            and info.st_mtime_ns == old.st_mtime_ns and info.st_ctime_ns == old.st_ctime_ns
            and info.st_uid == old.st_uid and info.st_gid == old.st_gid and info.st_mode == old.st_mode)


def replace(path, body, info):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.pos-silent-quote-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), -1, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
        os.replace(temporary, path)
        descriptor = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def lint(candidate, directory):
    path = directory / 'takeaway-pos.js'
    path.write_bytes(candidate)
    node = shutil.which('node')
    if not node:
        print(MARKER + '_LOCAL_JS_PARSE_UNAVAILABLE node_missing=1', flush=True)
        return
    result = subprocess.run([node, '--check', str(path)], stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=60)
    require(result.returncode == 0, 'CANDIDATE_PARSE_FAILED')


def install(root, revision, check_only=False):
    safe(root)
    require(root.is_dir() and root.stat().st_uid == os.geteuid(), 'APPLICATION_OWNER_REQUIRED')
    block = release_block(root, revision)
    parent = root.parent / 'pos-silent-quote-backups'
    safe(parent)
    parent.mkdir(mode=0o700, exist_ok=True)
    info = parent.stat()
    require(info.st_uid == os.geteuid() and not stat.S_IMODE(info.st_mode) & 0o077,
            'PRIVATE_BACKUP_REQUIRED')
    descriptor = os.open(parent / '.install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(), 'LOCK_INVALID')
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        target = root / SOURCE_PATH
        original = read_owned(target)
        candidate = transform(original[0], block)
        require(os.access(target.parent, os.W_OK | os.X_OK), 'DESTINATION_NOT_WRITABLE')
        with tempfile.TemporaryDirectory(prefix='.preflight-', dir=parent) as temporary:
            lint(candidate, Path(temporary))
        if check_only:
            print(MARKER + '_PREFLIGHT_OK files=1', flush=True)
            return None
        if candidate == original[0]:
            print(MARKER + '_ALREADY_INSTALLED files=1', flush=True)
            return None
        require(same_version(target, original), 'SOURCE_CHANGED_DURING_PREFLIGHT')
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=stamp, dir=parent))
        saved = backup / 'takeaway-pos.js'
        saved.write_bytes(original[0])
        saved.chmod(0o600)
        receipt_file = backup / 'receipt.json'
        digest = lambda body: hashlib.sha256(body).hexdigest()
        receipt = {'status': 'prepared', 'revision': revision, 'file': SOURCE_PATH,
                   'before_sha256': digest(original[0]), 'after_sha256': digest(candidate),
                   'uid': original[1].st_uid, 'gid': original[1].st_gid,
                   'mode': stat.S_IMODE(original[1].st_mode),
                   'database_bootstrapped': False, 'print_command_run': False}
        def record():
            receipt_file.write_text(json.dumps(receipt, indent=2) + '\n')
            receipt_file.chmod(0o600)
        record()
        started = False
        try:
            require(same_version(target, original), 'SOURCE_CHANGED_DURING_INSTALL')
            started = True
            replace(target, candidate, original[1])
            require(read_owned(target)[0] == candidate, 'PUBLISH_VERIFICATION_FAILED')
            receipt['status'] = 'installed'
            record()
        except BaseException:
            if started:
                try:
                    if read_owned(target)[0] == candidate:
                        replace(target, original[0], original[1])
                        receipt['status'] = 'rolled_back'
                    elif same_version(target, original):
                        receipt['status'] = 'not_published'
                    else:
                        receipt['status'] = 'rollback_needs_review'
                except BaseException:
                    receipt['status'] = 'rollback_needs_review'
            else:
                receipt['status'] = 'not_published'
            record()
            print(MARKER + '_INSTALL_FAILED receipt=' + str(receipt_file), flush=True)
            raise
        print(MARKER + '_INSTALLED files=1 receipt=' + str(receipt_file), flush=True)
        return backup


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--check', action='store_true')
    arguments = parser.parse_args()
    try:
        install(arguments.root, arguments.revision, arguments.check)
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        print(str(error) if isinstance(error, RuntimeError) else MARKER + '_INSTALLATION_BLOCKED', flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
