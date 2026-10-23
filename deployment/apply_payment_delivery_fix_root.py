#!/usr/bin/env python3
"""Apply four source files from a pinned release; no database or Git checkout writes."""
import argparse
from contextlib import ExitStack
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import tempfile

ROOT = Path('/home/fasakha/public_html')
PATHS = (
    'app/Services/Dashboard/OrderBoardService.php',
    'app/Services/Dashboard/PosServiceTicket.php',
    'public/dashboard/js/phone-orders.js',
    'public/dashboard/css/phone-delivery-refresh.css',
)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def regular(root, name):
    target = root / name
    if root.resolve() != root or not root.is_dir():
        raise RuntimeError('ROOT_INVALID')
    for component in [target, *list(target.parents)[:len(Path(name).parts) - 1]]:
        if component.is_symlink():
            raise RuntimeError('SYMLINK_REJECTED')
    info = target.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise RuntimeError('FILE_TYPE_INVALID')
    return target, info


def plan(root, manifest, payloads):
    if set(manifest) != set(PATHS) or set(payloads) != set(PATHS):
        raise RuntimeError('PACKAGE_PATHS_INVALID')
    changes = []
    for name in PATHS:
        target, info = regular(root, name)
        desired = payloads[name]
        if digest(desired) != manifest[name]['after']:
            raise RuntimeError('PACKAGE_HASH_INVALID')
        current = target.read_bytes()
        if digest(current) == manifest[name]['after']:
            continue
        if digest(current) != manifest[name]['before']:
            raise RuntimeError('BASELINE_MISMATCH')
        changes.append((name, current, desired, info))
    return changes


def replace_file(target, content, info):
    descriptor, temporary = tempfile.mkstemp(prefix='.payment-delivery-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            output.write(content)
            output.flush()
            os.fchown(output.fileno(), info.st_uid, info.st_gid)
            os.fchmod(output.fileno(), stat.S_IMODE(info.st_mode))
            os.fsync(output.fileno())
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def publish(root, changes, backup):
    backup.mkdir(mode=0o700)
    for name, original, _, info in changes:
        target = backup / name
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        target.write_bytes(original)
        target.chmod(0o600)
    (backup / 'metadata.json').write_text(json.dumps({name: {
        'before': digest(original), 'after': digest(desired), 'uid': info.st_uid,
        'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
    } for name, original, desired, info in changes}, indent=2))
    written = []
    try:
        for name, original, desired, info in changes:
            target, current_info = regular(root, name)
            if target.read_bytes() != original or (current_info.st_uid, current_info.st_gid, stat.S_IMODE(current_info.st_mode)) != (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)):
                raise RuntimeError('SOURCE_CHANGED')
            replace_file(target, desired, info)
            written.append((name, original, desired, info))
        for name, _, desired, _ in changes:
            if (root / name).read_bytes() != desired:
                raise RuntimeError('PUBLICATION_VERIFY_FAILED')
    except BaseException:
        for name, original, desired, info in reversed(written):
            target, _ = regular(root, name)
            if target.read_bytes() != desired:
                raise RuntimeError('ROLLBACK_REVIEW_REQUIRED')
            replace_file(target, original, info)
        raise


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--git-dir', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('SERVER_INVALID')
    if not re.fullmatch('[a-f0-9]{40}', args.release):
        raise RuntimeError('RELEASE_INVALID')
    owner = pwd.getpwnam('fasakha').pw_uid
    if ROOT.is_symlink() or ROOT.resolve() != ROOT or ROOT.stat().st_uid != owner:
        raise RuntimeError('ROOT_INVALID')
    lock = os.open('/run/lock/fasakhansta-payment-delivery-fix.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with ExitStack() as handles:
        held = handles.enter_context(os.fdopen(lock, 'r+'))
        if os.fstat(held.fileno()).st_uid != 0:
            raise RuntimeError('LOCK_OWNER_INVALID')
        fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
        # Share the source watcher's lock so it cannot capture half of this update.
        work = Path('/var/lib/go-erp-live-updates')
        if work.exists():
            if work.is_symlink() or work.resolve() != work or work.stat().st_uid != 0:
                raise RuntimeError('WATCHER_DIRECTORY_INVALID')
            watcher = handles.enter_context(os.fdopen(os.open(work / 'watcher.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600), 'r+'))
            info = os.fstat(watcher.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
                raise RuntimeError('WATCHER_LOCK_INVALID')
            try:
                fcntl.flock(watcher, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeError('WATCHER_BUSY_RETRY_AFTER_COMPLETION') from None
        def git(*arguments):
            return subprocess.check_output(['/usr/bin/git', '--git-dir=' + args.git_dir, *arguments],
                env={'PATH': '/usr/bin:/bin', 'GIT_NO_REPLACE_OBJECTS': '1'}, stderr=subprocess.DEVNULL)
        if git('rev-parse', args.release + '^{commit}').decode().strip() != args.release:
            raise RuntimeError('RELEASE_INVALID')
        manifest = json.loads(git('show', args.release + ':deployment/payment_delivery_fix.json'))['files']
        payloads = {name: git('show', args.release + ':' + name) for name in PATHS}
        changes = plan(ROOT, manifest, payloads)
        if any(info.st_uid != owner for _, _, _, info in changes):
            raise RuntimeError('SOURCE_OWNER_INVALID')
        with tempfile.TemporaryDirectory(prefix='payment-delivery-lint-') as temporary:
            for name in PATHS:
                staged = Path(temporary) / Path(name).name
                staged.write_bytes(payloads[name])
                command = ['/opt/cpanel/ea-php82/root/usr/bin/php', '-n', '-l', str(staged)] if name.endswith('.php') else ([shutil.which('node'), '--check', str(staged)] if name.endswith('.js') and shutil.which('node') else None)
                if command:
                    subprocess.run(command, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if not changes:
            print('PAYMENT_DELIVERY_FIX_ALREADY_APPLIED')
            return
        if not args.apply:
            print('PAYMENT_DELIVERY_FIX_CHECK_READY')
            return
        backups = Path('/home/fasakha/payment-delivery-fix-backups')
        backups.mkdir(mode=0o700, exist_ok=True)
        if backups.is_symlink() or backups.stat().st_uid != 0:
            raise RuntimeError('BACKUP_ROOT_INVALID')
        backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
        # Re-read every baseline after linting and before making any source change.
        plan(ROOT, manifest, payloads)
        publish(ROOT, changes, backup)
        print('PAYMENT_DELIVERY_FIX_READY')
        print('BACKUP=' + str(backup))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('PAYMENT_DELIVERY_FIX_FAILED_' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__))
        raise SystemExit(1)
