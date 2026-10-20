#!/usr/bin/env python3
"""Publish only the reviewed stock email branch scoping service."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile

BASE_REVISION = '2ce66fdb11030c54593f1d6941d7354936077c4a'
# Exact installed consolidated-report service, unchanged from a032130a at this parent.
BEFORE_SHA256 = {
    'app/Services/Dashboard/BranchStockMinimums.php': '7e9cef9cef02be01fbb3f4c405dae213e8f60fde1c161b2c170f7c2f052264b7',
}
SOURCE_PATHS = tuple(BEFORE_SHA256)
MAX_BYTES = 4 * 1024 * 1024


def require(condition, code):
    if not condition:
        raise RuntimeError(code)


def run(command):
    return subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180)


def git(root, *arguments):
    result = run(['git', '-C', str(root), *arguments])
    require(result.returncode == 0, 'STOCK_BRANCHES_ONLY_GIT_READ_FAILED')
    return result.stdout


def safe(path):
    require(path.is_absolute(), 'STOCK_BRANCHES_ONLY_ABSOLUTE_PATH_REQUIRED')
    for parent in (path, *path.parents):
        require(not parent.is_symlink(), 'STOCK_BRANCHES_ONLY_SYMLINK_REJECTED')


def read_owned(path):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(),
                'STOCK_BRANCHES_ONLY_OWNED_REGULAR_FILE_REQUIRED')
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body, 'STOCK_BRANCHES_ONLY_SOURCE_INVALID')
    return body, info


def digest(body):
    return hashlib.sha256(body).hexdigest()


def same_version(path, original):
    body, info = read_owned(path)
    old_body, old = original
    return (body == old_body and info.st_ino == old.st_ino and info.st_dev == old.st_dev
            and info.st_mtime_ns == old.st_mtime_ns and info.st_ctime_ns == old.st_ctime_ns
            and info.st_uid == old.st_uid and info.st_gid == old.st_gid and info.st_mode == old.st_mode)


def replace(path, body, original_info):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.stock-branches-only-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), -1, original_info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(original_info.st_mode))
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def lint_sources(root, staged, php, cache):
    # PHP syntax only: no autoload, Laravel bootstrap, database connection, or SMTP.
    for relative in SOURCE_PATHS:
        result = run([php, '-l', str(staged / relative)])
        require(result.returncode == 0, 'STOCK_BRANCHES_ONLY_CANDIDATE_PARSE_FAILED: ' + relative)


def install(root, revision, php, check_only=False):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'STOCK_BRANCHES_ONLY_FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.stat().st_uid == os.geteuid(), 'STOCK_BRANCHES_ONLY_APPLICATION_OWNER_REQUIRED')
    git(root, 'cat-file', '-e', revision + '^{commit}')
    require(run(['git', '-C', str(root), 'merge-base', '--is-ancestor', BASE_REVISION, revision]).returncode == 0,
            'STOCK_BRANCHES_ONLY_UNEXPECTED_RELEASE_BASE')
    backup_parent = root.parent / 'stock-branches-only-backups'
    safe(backup_parent)
    backup_parent.mkdir(mode=0o700, exist_ok=True)
    require(backup_parent.stat().st_uid == os.geteuid() and not stat.S_IMODE(backup_parent.stat().st_mode) & 0o077,
            'STOCK_BRANCHES_ONLY_PRIVATE_BACKUP_REQUIRED')
    lock_path = backup_parent / '.install.lock'
    descriptor = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(),
                'STOCK_BRANCHES_ONLY_LOCK_INVALID')
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        before, after = {}, {}
        for relative in SOURCE_PATHS:
            target = root / relative
            before[relative] = read_owned(target)
            require(os.access(target.parent, os.W_OK | os.X_OK), 'STOCK_BRANCHES_ONLY_DESTINATION_NOT_WRITABLE')
            after[relative] = git(root, 'show', revision + ':' + relative)
            require(len(after[relative]) <= MAX_BYTES and b'\x00' not in after[relative], 'STOCK_BRANCHES_ONLY_CANDIDATE_INVALID')
            require(digest(before[relative][0]) == BEFORE_SHA256[relative] or before[relative][0] == after[relative],
                    'STOCK_BRANCHES_ONLY_LIVE_SOURCE_CHANGED: ' + relative)
        # An exact already-installed payload is idempotent; unknown content is rejected above.
        with tempfile.TemporaryDirectory(prefix='.preflight-', dir=backup_parent) as temporary:
            staged = Path(temporary)
            for relative, body in after.items():
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            lint_sources(root, staged, php, staged / 'parse-cache')
        if check_only:
            print('STOCK_BRANCHES_ONLY_PREFLIGHT_OK files=1 no_mail_sent=1', flush=True)
            return None
        pending = [relative for relative in SOURCE_PATHS if before[relative][0] != after[relative]]
        if not pending:
            print('STOCK_BRANCHES_ONLY_ALREADY_INSTALLED files=1 no_mail_sent=1', flush=True)
            return None
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=stamp, dir=backup_parent))
        receipt = {'status': 'prepared', 'revision': revision, 'mail_command_run': False, 'database_bootstrapped': False, 'files': {}}
        for relative in SOURCE_PATHS:
            old_body, old = before[relative]
            saved = backup / 'original' / relative
            saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            saved.write_bytes(old_body)
            saved.chmod(0o600)
            receipt['files'][relative] = {'before_sha256': digest(old_body), 'after_sha256': digest(after[relative]),
                                         'mode': stat.S_IMODE(old.st_mode), 'uid': old.st_uid, 'gid': old.st_gid}
        receipt_file = backup / 'receipt.json'
        def record():
            receipt_file.write_text(json.dumps(receipt, indent=2) + '\n')
            receipt_file.chmod(0o600)
        record()
        published = []
        try:
            for relative in SOURCE_PATHS:
                require(same_version(root / relative, before[relative]), 'STOCK_BRANCHES_ONLY_SOURCE_CHANGED_DURING_PREFLIGHT')
            for relative in pending:
                require(same_version(root / relative, before[relative]), 'STOCK_BRANCHES_ONLY_SOURCE_CHANGED_DURING_INSTALL')
                # Track the attempt before replace: an error after os.replace can
                # otherwise hide a completed rename from the rollback handler.
                published.append(relative)
                replace(root / relative, after[relative], before[relative][1])
            for relative in SOURCE_PATHS:
                require(read_owned(root / relative)[0] == after[relative], 'STOCK_BRANCHES_ONLY_PUBLISH_VERIFICATION_FAILED')
            receipt['status'] = 'installed'
            record()
        except BaseException:
            conflicts = []
            for relative in reversed(published):
                try:
                    current = read_owned(root / relative)[0]
                    if current == before[relative][0]:
                        continue
                    if current != after[relative]:
                        conflicts.append(relative)
                        continue
                    replace(root / relative, before[relative][0], before[relative][1])
                except BaseException:
                    conflicts.append(relative)
            receipt['status'] = 'rollback_needs_review' if conflicts else 'rolled_back'
            receipt['rollback_conflicts'] = conflicts
            record()
            print('STOCK_BRANCHES_ONLY_INSTALL_FAILED receipt=' + str(receipt_file), flush=True)
            raise
        print('STOCK_BRANCHES_ONLY_INSTALLED files=1 no_mail_sent=1 receipt=' + str(receipt_file), flush=True)
        return backup


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', required=True)
    parser.add_argument('--check', action='store_true')
    arguments = parser.parse_args()
    try:
        install(arguments.root, arguments.revision, arguments.php, arguments.check)
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        # Never print command output, environment values, application data, or database data.
        code = str(error) if isinstance(error, RuntimeError) else 'STOCK_BRANCHES_ONLY_INSTALLATION_BLOCKED'
        print(code, flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
