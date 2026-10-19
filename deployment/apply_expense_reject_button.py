#!/usr/bin/env python3
"""Add the pending-expense reject button without replacing neighboring changes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time


PATCHES = {
    'public/dashboard/js/branch-expenses.js': (
        """            if (boot.permissions.can_approve && item.status === 'pending' && item.payment_method === 'cash') {
                var approve = actionButton('approve', function () { review(item, 'approve'); }); approve.className = 'ex-approve ex-status-approve'; approve.dataset.expenseApprove = item.id; statusActions.appendChild(approve);
            }
            status.appendChild(statusActions); row.appendChild(status);""",
        """            if (boot.permissions.can_approve && item.status === 'pending') {
                if (item.payment_method === 'cash') {
                    var approve = actionButton('approve', function () { review(item, 'approve'); }); approve.className = 'ex-approve ex-status-approve'; approve.dataset.expenseApprove = item.id; statusActions.appendChild(approve);
                }
                var reject = actionButton('reject', function () { review(item, 'reject'); }); reject.className = 'ex-reject ex-status-reject'; reject.dataset.expenseReject = item.id; statusActions.appendChild(reject);
            }
            status.appendChild(statusActions); row.appendChild(status);""",
    ),
    'public/dashboard/css/branch-expenses.css': (
        ".ex-status-approve:hover{background:#12653e;border-color:#12653e}\n.ex-review-error{",
        ".ex-status-approve:hover{background:#12653e;border-color:#12653e}\n"
        ".ex-status-actions .ex-status-reject{display:inline-flex;align-items:center;justify-content:center;border:1px solid #bc3535;border-radius:6px;background:#bc3535;color:#fff;padding:6px 9px;font-size:10px;min-height:30px;white-space:nowrap}\n"
        ".ex-status-actions .ex-status-reject:hover{background:#952929;border-color:#952929}\n"
        ".ex-review-error{",
    ),
    'resources/lang/ar/expenses.php': (
        "'approve'=>'اعتماد وصرف','reject'=>'رفض','void'=>",
        "'approve'=>'اعتماد وصرف','reject'=>'رفض المصروف','void'=>",
    ),
    'resources/lang/en/expenses.php': (
        "'approve'=>'Approve and pay','reject'=>'Reject','void'=>",
        "'approve'=>'Approve and pay','reject'=>'Reject expense','void'=>",
    ),
}
MAX_BYTES = 4 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe_path(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for entry in (path, *path.parents):
        require(not entry.is_symlink(), 'SYMLINK_REJECTED: ' + str(entry))


def read_file(path):
    safe_path(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_FILE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'OWNER_MISMATCH: ' + str(path))
        data = stream.read(MAX_BYTES + 1)
    require(len(data) <= MAX_BYTES and b'\x00' not in data, 'INVALID_SOURCE: ' + str(path))
    return data, info


def candidate(relative, data):
    old, new = (text.encode('utf-8') for text in PATCHES[relative])
    if data.count(new) == 1 and data.count(old) == 0:
        return data
    require(data.count(old) == 1 and data.count(new) == 0,
            'PATCH_CONFLICT: ' + relative)
    return data.replace(old, new, 1)


def signature(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid,
            stat.S_IMODE(info.st_mode), info.st_mtime_ns)


def unchanged(path, original):
    data, info = read_file(path)
    return data == original[0] and signature(info) == signature(original[1])


def atomic_write(path, data, info):
    safe_path(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.expense-reject-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            # Blade versions these assets with filemtime(), rounded to seconds.
            modified = max(time.time_ns(), (info.st_mtime_ns // 1_000_000_000 + 1) * 1_000_000_000)
            os.utime(stream.fileno(), ns=(modified, modified))
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def lint_candidates(directory, php):
    node = shutil.which('node')
    for relative in PATCHES:
        command = None
        if relative.endswith('.php') and php:
            command = [php, '-l', str(directory / relative)]
        elif relative.endswith('.js') and node:
            command = [node, '--check', str(directory / relative)]
        if command:
            result = subprocess.run(command, capture_output=True, timeout=30)
            require(result.returncode == 0, 'SYNTAX_INVALID: ' + relative)
    print('SYNTAX_CHECKS: php=' + ('passed' if php else 'unavailable')
          + ' node=' + ('passed' if node else 'unavailable'))


def private_directory(path):
    safe_path(path)
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.stat()
    require(path.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700,
            'PRIVATE_DIRECTORY_REQUIRED: ' + str(path))


def backup(root, originals, revision):
    parent = root.parent / 'expense-reject-button-backups'
    private_directory(parent)
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
    directory = Path(tempfile.mkdtemp(prefix=stamp, dir=parent))
    manifest = {'root': str(root), 'revision': revision, 'files': {}}
    for relative, (data, info) in originals.items():
        target = directory / relative
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        target.write_bytes(data)
        target.chmod(0o600)
        manifest['files'][relative] = {
            'sha256': hashlib.sha256(data).hexdigest(), 'uid': info.st_uid,
            'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
        }
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (directory / 'manifest.json').chmod(0o600)
    return directory


def install(root, php=None, check=False, revision='local'):
    safe_path(root)
    require(root.is_dir(), 'APPLICATION_ROOT_REQUIRED')
    require(root.stat().st_uid == os.geteuid(), 'APPLICATION_OWNER_MISMATCH')
    lock_path = root.parent / '.expense-reject-button.lock'
    safe_path(lock_path)
    descriptor = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'a+b') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid(), 'INVALID_INSTALLER_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        originals = {relative: read_file(root / relative) for relative in PATCHES}
        candidates = {relative: candidate(relative, original[0])
                      for relative, original in originals.items()}
        with tempfile.TemporaryDirectory(prefix='expense-reject-check-') as temporary:
            directory = Path(temporary)
            for relative, data in candidates.items():
                target = directory / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
            lint_candidates(directory, php)
        changed = [relative for relative in PATCHES if originals[relative][0] != candidates[relative]]
        if not changed:
            print('EXPENSE_REJECT_BUTTON_ALREADY_APPLIED')
            return
        for relative, original in originals.items():
            require(unchanged(root / relative, original), 'SOURCE_CHANGED: ' + relative)
        if check:
            print('EXPENSE_REJECT_BUTTON_CHECK_OK: ' + ', '.join(changed))
            return
        directory = backup(root, {relative: originals[relative] for relative in changed}, revision)
        print('BACKUP: ' + str(directory), flush=True)
        written = []
        old_handler = signal.getsignal(signal.SIGTERM)

        def terminate(signum, frame):
            raise RuntimeError('INSTALL_INTERRUPTED')

        signal.signal(signal.SIGTERM, terminate)
        try:
            for relative in changed:
                require(unchanged(root / relative, originals[relative]), 'SOURCE_CHANGED: ' + relative)
                # Record before replacement so failures after os.replace also roll back.
                written.append(relative)
                atomic_write(root / relative, candidates[relative], originals[relative][1])
            for relative in PATCHES:
                require(read_file(root / relative)[0] == candidates[relative],
                        'POST_INSTALL_MISMATCH: ' + relative)
        except BaseException:
            rollback_errors = []
            for relative in reversed(written):
                try:
                    current = read_file(root / relative)[0]
                    require(current in (candidates[relative], originals[relative][0]),
                            'CONCURRENT_CHANGE_PREVENTS_ROLLBACK: ' + relative)
                    if current != originals[relative][0]:
                        atomic_write(root / relative, originals[relative][0], originals[relative][1])
                except Exception as error:
                    rollback_errors.append(str(error))
            if rollback_errors:
                raise RuntimeError('ROLLBACK_INCOMPLETE; backup=' + str(directory)
                                   + '; ' + '; '.join(rollback_errors))
            print('EXPENSE_REJECT_BUTTON_ROLLED_BACK', flush=True)
            raise
        finally:
            signal.signal(signal.SIGTERM, old_handler)
        print('EXPENSE_REJECT_BUTTON_APPLIED: ' + ', '.join(changed))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--revision', default='local')
    parser.add_argument('--php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    require(args.revision == 'local' or re.fullmatch(r'[0-9a-f]{40}', args.revision),
            'FULL_REVISION_REQUIRED')
    if args.php:
        require(Path(args.php).is_file() and os.access(args.php, os.X_OK), 'PHP_EXECUTABLE_REQUIRED')
    install(args.root, args.php, args.check, args.revision)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('EXPENSE_REJECT_BUTTON_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
