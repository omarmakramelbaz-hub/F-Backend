#!/usr/bin/env python3
"""Publish reviewed menu navigation and shift closure sources, preserving live edits."""
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

BASE_REVISION = '2ed0dfbf3947e7ccadf52b2766dbc826e3382e6f'
# Dependencies first, callers next, browser opt-in last.
SOURCE_PATHS = (
    'app/Services/Dashboard/TakeawayService.php',
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Services/Dashboard/BranchShiftClosing.php',
    'app/Http/Controllers/Dashboard/TakeawayController.php',
    'app/Http/Controllers/Dashboard/PosServiceController.php',
    'public/dashboard/js/takeaway-pos.js',
    'public/dashboard/js/dining-pos.js',
    'public/dashboard/js/branch-shifts.js',
    'resources/views/admin/branch_shifts/index.blade.php',
)
MAX_BYTES = 4 * 1024 * 1024
MARKER = 'POS_MENU_SHIFT_UPDATE'


def require(condition, message):
    if not condition:
        raise RuntimeError(MARKER + '_' + message)


def run(arguments):
    return subprocess.run(arguments, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90)


def git(root, *arguments):
    # An external, verified GIT_DIR is supported for production repositories with bad refs.
    result = subprocess.run(['git', '-C', str(root), *arguments],
                            env=dict(os.environ, GIT_NO_REPLACE_OBJECTS='1'),
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
    require(0 < len(body) <= MAX_BYTES and b'\x00' not in body, 'SOURCE_INVALID')
    return body, info


def digest(body):
    return hashlib.sha256(body).hexdigest()


def release_sources(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    require(git(root, 'rev-parse', '--verify', revision + '^{commit}').decode().strip() == revision,
            'PINNED_REVISION_REQUIRED')
    parents = git(root, 'rev-list', '--parents', '-n', '1', revision).decode().split()
    require(parents == [revision, BASE_REVISION], 'RELEASE_PARENT_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    require(set(changed.decode().splitlines()) == {'M\t' + path for path in SOURCE_PATHS},
            'RELEASE_MANIFEST_MISMATCH')
    sources = {}
    for commit in (BASE_REVISION, revision):
        entries = git(root, 'ls-tree', '-z', commit, '--', *SOURCE_PATHS).rstrip(b'\x00').split(b'\x00')
        require(len(entries) == len(SOURCE_PATHS), 'RELEASE_TREE_MISMATCH')
        for entry in entries:
            header, path = entry.split(b'\t', 1)
            mode, kind, _ = header.split(b' ', 2)
            require(mode == b'100644' and kind == b'blob' and path.decode() in SOURCE_PATHS,
                    'RELEASE_SOURCE_NOT_REGULAR')
        for path in SOURCE_PATHS:
            body = git(root, 'show', commit + ':' + path)
            require(0 < len(body) <= MAX_BYTES and b'\x00' not in body, 'RELEASE_SOURCE_INVALID')
            sources[(commit, path)] = body
    return {path: (sources[(BASE_REVISION, path)], sources[(revision, path)]) for path in SOURCE_PATHS}


def merge_candidate(directory, path, current, base, release):
    if current == release or current == base:
        return release
    with tempfile.TemporaryDirectory(prefix='merge-', dir=directory) as temporary:
        paths = [Path(temporary) / name for name in ('current', 'base', 'release')]
        for target, body in zip(paths, (current, base, release)):
            target.write_bytes(body)
        result = run(['git', 'merge-file', '-p', '--diff3', *map(str, paths)])
    require(result.returncode == 0, 'LIVE_MERGE_CONFLICT: ' + path)
    require(0 < len(result.stdout) <= MAX_BYTES and b'\x00' not in result.stdout,
            'MERGED_SOURCE_INVALID: ' + path)
    return result.stdout


def lint(candidates, directory, php):
    binary = shutil.which(php)
    require(binary is not None, 'PHP_BINARY_UNAVAILABLE')
    node = shutil.which('node')
    for relative, body in candidates.items():
        path = directory / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)
        if relative.endswith('.blade.php'):
            # Blade compilation/render checks belong to the isolated release tests.
            # php -l would only inspect literal PHP and cannot validate Blade directives.
            continue
        if relative.endswith('.php'):
            result = run([binary, '-l', str(path)])
        elif node:
            result = run([node, '--check', str(path)])
        else:
            continue
        require(result.returncode == 0, 'CANDIDATE_PARSE_FAILED: ' + relative)
    if not node:
        print(MARKER + '_LOCAL_JS_PARSE_UNAVAILABLE node_missing=1', flush=True)


def same_version(path, original):
    body, info = read_owned(path)
    old_body, old = original
    return (body == old_body and info.st_ino == old.st_ino and info.st_dev == old.st_dev
            and info.st_mtime_ns == old.st_mtime_ns and info.st_ctime_ns == old.st_ctime_ns
            and info.st_uid == old.st_uid and info.st_gid == old.st_gid and info.st_mode == old.st_mode)


def replace(path, body, info):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.pos-menu-shift-update-', dir=path.parent)
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


def private_write(path, body):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(body)
        stream.flush()
        os.fsync(stream.fileno())


def install(root, revision, php, check_only=False):
    safe(root)
    require(root.is_dir() and root.stat().st_uid == os.geteuid(), 'APPLICATION_OWNER_REQUIRED')
    release = release_sources(root, revision)
    parent = root.parent / 'pos-menu-shift-update-backups'
    safe(parent)
    parent.mkdir(mode=0o700, exist_ok=True)
    info = parent.stat()
    require(info.st_uid == os.geteuid() and not stat.S_IMODE(info.st_mode) & 0o077, 'PRIVATE_BACKUP_REQUIRED')
    descriptor = os.open(parent / '.install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid(), 'LOCK_INVALID')
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        originals = {path: read_owned(root / path) for path in SOURCE_PATHS}
        with tempfile.TemporaryDirectory(prefix='.preflight-', dir=parent) as temporary:
            stage = Path(temporary)
            candidates = {path: merge_candidate(stage, path, originals[path][0], *release[path]) for path in SOURCE_PATHS}
            lint(candidates, stage, php)
        for path in SOURCE_PATHS:
            require(same_version(root / path, originals[path]), 'SOURCE_CHANGED_DURING_PREFLIGHT: ' + path)
            require(os.access((root / path).parent, os.W_OK | os.X_OK), 'DESTINATION_NOT_WRITABLE: ' + path)
        if check_only:
            print(MARKER + '_PREFLIGHT_OK files=' + str(len(SOURCE_PATHS)), flush=True)
            return None
        pending = [path for path in SOURCE_PATHS if candidates[path] != originals[path][0]]
        if not pending:
            print(MARKER + '_ALREADY_INSTALLED files=' + str(len(SOURCE_PATHS)), flush=True)
            return None
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=stamp, dir=parent))
        for path in SOURCE_PATHS:
            private_write(backup / 'original' / path, originals[path][0])
        receipt_file = backup / 'receipt.json'
        receipt = {'status': 'prepared', 'revision': revision, 'base_revision': BASE_REVISION,
                   'database_bootstrapped': False, 'print_command_run': False,
                   'files': {path: {'before_sha256': digest(originals[path][0]), 'after_sha256': digest(candidates[path]),
                                    'uid': originals[path][1].st_uid, 'gid': originals[path][1].st_gid,
                                    'mode': stat.S_IMODE(originals[path][1].st_mode)} for path in SOURCE_PATHS}}
        def record():
            temporary = backup / 'receipt.pending'
            private_write(temporary, (json.dumps(receipt, indent=2) + '\n').encode())
            os.replace(temporary, receipt_file)
        record()
        attempted = []
        try:
            for path in pending:
                require(same_version(root / path, originals[path]), 'SOURCE_CHANGED_DURING_INSTALL: ' + path)
                attempted.append(path)
                replace(root / path, candidates[path], originals[path][1])
                require(read_owned(root / path)[0] == candidates[path], 'PUBLISH_VERIFICATION_FAILED: ' + path)
            for path in SOURCE_PATHS:
                require(read_owned(root / path)[0] == candidates[path], 'PUBLISH_VERIFICATION_FAILED: ' + path)
            receipt['status'] = 'installed'
            record()
        except BaseException:
            review = []
            for path in reversed(attempted):
                try:
                    body = read_owned(root / path)[0]
                    if body == candidates[path]:
                        replace(root / path, originals[path][0], originals[path][1])
                    elif body != originals[path][0]:
                        review.append(path)
                except BaseException:
                    review.append(path)
            receipt['status'] = 'rollback_needs_review' if review else 'rolled_back'
            receipt['review_files'] = review
            record()
            print(MARKER + '_INSTALL_FAILED receipt=' + str(receipt_file), flush=True)
            raise
        print(MARKER + '_INSTALLED files=' + str(len(SOURCE_PATHS)) + ' receipt=' + str(receipt_file), flush=True)
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
        print(str(error) if isinstance(error, RuntimeError) else MARKER + '_INSTALLATION_BLOCKED', flush=True)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
