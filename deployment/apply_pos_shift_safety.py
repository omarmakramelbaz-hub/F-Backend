#!/usr/bin/env python3
"""Publish only the reviewed POS ticket and shift safety source files."""
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

BASE_REVISION = '47b03e8ac95ad565645424d1deb3931b1b60bd66'
# Measured live source, not inferred from BASE_REVISION's historical payload.
BEFORE_SHA256 = {
    'app/Services/Dashboard/PosServiceTicket.php': '875537c790a999b29057fcda1a00a2112843607f3bcd47727b658e26b87b2591',
    'public/dashboard/js/dining-pos.js': 'a6cd60d392f32fde807b6dca42cfd0add95423f521997dd5ca67c5022c6fd46e',
    'app/Services/Dashboard/BranchShiftClosing.php': '21d13d560857700d2904a82af73af798b072f5d56c350c708d85f7723b714bfb',
    'public/dashboard/js/branch-shifts.js': '4c3e7d91162b7a37437cdb14b868484b9710dd22cb16ba55e5da3f0a39e1e0bb',
    'resources/views/admin/branch_shifts/index.blade.php': '658fb3e802aa0b2e5e0f5800ec1f043e931239db02edf7c47da3fa9c227fe4fb',
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
    require(result.returncode == 0, 'POS_SHIFT_SAFETY_GIT_READ_FAILED')
    return result.stdout


def safe(path):
    require(path.is_absolute(), 'POS_SHIFT_SAFETY_ABSOLUTE_PATH_REQUIRED')
    for parent in (path, *path.parents):
        require(not parent.is_symlink(), 'POS_SHIFT_SAFETY_SYMLINK_REJECTED')


def read_owned(path):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(),
                'POS_SHIFT_SAFETY_OWNED_REGULAR_FILE_REQUIRED')
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body, 'POS_SHIFT_SAFETY_SOURCE_INVALID')
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
    descriptor, temporary = tempfile.mkstemp(prefix='.pos-shift-safety-', dir=path.parent)
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
    # Parse source only: no Laravel bootstrap, database connection, print jobs, or HTTP.
    autoload = root / 'vendor/autoload.php'
    safe(autoload)
    require(autoload.is_file(), 'POS_SHIFT_SAFETY_VENDOR_AUTOLOAD_REQUIRED')
    cache.mkdir(mode=0o700)
    blade = r"""
require $argv[1];
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $argv[3]);
$source = file_get_contents($argv[2]);
if ($source === false) { throw new RuntimeException('BLADE_SOURCE_UNREADABLE'); }
token_get_all($compiler->compileString($source), TOKEN_PARSE);
"""
    node = shutil.which('node')
    for relative in SOURCE_PATHS:
        target = staged / relative
        if relative.endswith('.blade.php'):
            result = run([php, '-r', blade, str(autoload), str(target), str(cache)])
        elif relative.endswith('.php'):
            result = run([php, '-l', str(target)])
        elif node:
            result = run([node, '--check', str(target)])
        else:
            print('POS_SHIFT_SAFETY_JS_PARSE_CHECK_UNAVAILABLE node_missing=1', flush=True)
            continue
        require(result.returncode == 0, 'POS_SHIFT_SAFETY_CANDIDATE_PARSE_FAILED: ' + relative)


def install(root, revision, php, check_only=False):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'POS_SHIFT_SAFETY_FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.stat().st_uid == os.geteuid(), 'POS_SHIFT_SAFETY_APPLICATION_OWNER_REQUIRED')
    git(root, 'cat-file', '-e', revision + '^{commit}')
    require(run(['git', '-C', str(root), 'merge-base', '--is-ancestor', BASE_REVISION, revision]).returncode == 0,
            'POS_SHIFT_SAFETY_UNEXPECTED_RELEASE_BASE')
    backup_parent = root.parent / 'pos-shift-safety-backups'
    safe(backup_parent)
    backup_parent.mkdir(mode=0o700, exist_ok=True)
    require(backup_parent.stat().st_uid == os.geteuid() and not stat.S_IMODE(backup_parent.stat().st_mode) & 0o077,
            'POS_SHIFT_SAFETY_PRIVATE_BACKUP_REQUIRED')
    lock_path = backup_parent / '.install.lock'
    descriptor = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(),
                'POS_SHIFT_SAFETY_LOCK_INVALID')
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        before, after = {}, {}
        for relative in SOURCE_PATHS:
            target = root / relative
            before[relative] = read_owned(target)
            require(os.access(target.parent, os.W_OK | os.X_OK), 'POS_SHIFT_SAFETY_DESTINATION_NOT_WRITABLE')
            after[relative] = git(root, 'show', revision + ':' + relative)
            require(len(after[relative]) <= MAX_BYTES and b'\x00' not in after[relative], 'POS_SHIFT_SAFETY_CANDIDATE_INVALID')
            require(digest(before[relative][0]) == BEFORE_SHA256[relative] or before[relative][0] == after[relative],
                    'POS_SHIFT_SAFETY_LIVE_SOURCE_CHANGED: ' + relative)
        # Mixed before/after state is recoverable, but any unknown content is rejected above.
        with tempfile.TemporaryDirectory(prefix='.preflight-', dir=backup_parent) as temporary:
            staged = Path(temporary)
            for relative, body in after.items():
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            lint_sources(root, staged, php, staged / 'parse-cache')
        if check_only:
            print('POS_SHIFT_SAFETY_PREFLIGHT_OK files=5 no_print_jobs_created=1', flush=True)
            return None
        pending = [relative for relative in SOURCE_PATHS if before[relative][0] != after[relative]]
        if not pending:
            print('POS_SHIFT_SAFETY_ALREADY_INSTALLED files=5 no_print_jobs_created=1', flush=True)
            return None
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=stamp, dir=backup_parent))
        receipt = {'status': 'prepared', 'revision': revision, 'print_command_run': False, 'database_bootstrapped': False, 'files': {}}
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
                require(same_version(root / relative, before[relative]), 'POS_SHIFT_SAFETY_SOURCE_CHANGED_DURING_PREFLIGHT')
            for relative in pending:
                require(same_version(root / relative, before[relative]), 'POS_SHIFT_SAFETY_SOURCE_CHANGED_DURING_INSTALL')
                replace(root / relative, after[relative], before[relative][1])
                published.append(relative)
            for relative in SOURCE_PATHS:
                require(read_owned(root / relative)[0] == after[relative], 'POS_SHIFT_SAFETY_PUBLISH_VERIFICATION_FAILED')
            receipt['status'] = 'installed'
            record()
        except BaseException:
            conflicts = []
            for relative in reversed(published):
                try:
                    if read_owned(root / relative)[0] != after[relative]:
                        conflicts.append(relative)
                        continue
                    replace(root / relative, before[relative][0], before[relative][1])
                except BaseException:
                    conflicts.append(relative)
            receipt['status'] = 'rollback_needs_review' if conflicts else 'rolled_back'
            receipt['rollback_conflicts'] = conflicts
            record()
            print('POS_SHIFT_SAFETY_INSTALL_FAILED receipt=' + str(receipt_file), flush=True)
            raise
        print('POS_SHIFT_SAFETY_INSTALLED files=5 no_print_jobs_created=1 receipt=' + str(receipt_file), flush=True)
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
        code = str(error) if isinstance(error, RuntimeError) else 'POS_SHIFT_SAFETY_INSTALLATION_BLOCKED'
        print(code, flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
