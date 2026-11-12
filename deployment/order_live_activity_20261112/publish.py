#!/usr/bin/env python3
"""Incremental order activity release; preserves unrelated production edits."""
import argparse
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
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
PACKAGE = Path(__file__).resolve().parent
MIGRATION = 'database/migrations/2026_11_12_150000_create_order_live_activities_table.php'
PATCHES = {
    'app/Providers/AppServiceProvider.php': (
        '        Order::observe(OrderCompetitionObserver::class);',
        '        Order::observe(\\App\\Observers\\OrderLiveActivityObserver::class);', 'after'),
    'app/Console/Kernel.php': (
        '    protected function schedule(Schedule $schedule)\n    {',
        "        $schedule->command('orders:sync-live-activities')->everyMinute()->withoutOverlapping();", 'after'),
    'routes/api.php': (
        "            Route::post('/check-otp-order', [UserController::class, 'checkOtpFirstOrder']);",
        "            Route::post('live-activities', [\\App\\Http\\Controllers\\Api\\V1\\User\\OrderLiveActivityController::class, 'store'])->middleware('throttle:60,1');\n            Route::post('live-activities/unregister', [\\App\\Http\\Controllers\\Api\\V1\\User\\OrderLiveActivityController::class, 'unregister'])->middleware('throttle:60,1');", 'before'),
    'app/Notifications/NotifyUserOrderStatusUpdatedNotification.php': (
        "                    'order_status' => $this->msg->status,",
        "                    'updated_at' => $this->msg->updated_at?->timestamp ?? 0,", 'after'),
}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def patched(name, original):
    anchor, insert, position = PATCHES[name]
    text = original.decode('utf-8')
    if insert in text:
        if text.count(insert) != 1:
            raise RuntimeError('DUPLICATED_RELEASE_MARKER: ' + name)
        return original
    if text.count(anchor) != 1:
        raise RuntimeError('RELEASE_ANCHOR_MISMATCH: ' + name)
    replacement = (insert + '\n' + anchor) if position == 'before' else (anchor + '\n' + insert)
    return text.replace(anchor, replacement, 1).encode('utf-8')


def checked_path(root, name, missing=False):
    if Path(name).is_absolute() or '..' in Path(name).parts:
        raise RuntimeError('INVALID_PACKAGE_PATH')
    path = root / name
    for parent in path.relative_to(root).parents:
        current = root / parent
        if current.is_symlink() or (current.exists() and not current.is_dir()):
            raise RuntimeError('UNSAFE_PARENT: ' + name)
    if path.is_symlink():
        raise RuntimeError('UNSAFE_FILE: ' + name)
    if path.exists():
        info = path.stat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise RuntimeError('UNSAFE_FILE: ' + name)
    elif not missing:
        raise RuntimeError('MISSING_FILE: ' + name)
    return path


def plan(root, package):
    manifest = json.loads((package / 'manifest.json').read_text())
    changes = {}
    for name, spec in manifest.items():
        source = checked_path(package / 'payload', name).read_bytes()
        if digest(source) != spec['after']:
            raise RuntimeError('PACKAGE_HASH_MISMATCH: ' + name)
        target = checked_path(root, name, missing=spec['before'] is None)
        before = target.read_bytes() if target.exists() else None
        if before == source:
            continue
        if (digest(before) if before is not None else None) != spec['before']:
            raise RuntimeError('BASELINE_MISMATCH: ' + name)
        changes[name] = (before, source)
    for name in PATCHES:
        before = checked_path(root, name).read_bytes()
        after = patched(name, before)
        if before != after:
            changes[name] = (before, after)
    return changes


def atomic(path, data, uid, gid, mode):
    missing = []
    parent = path.parent
    while not parent.exists():
        missing.append(parent)
        parent = parent.parent
    for directory in reversed(missing):
        directory.mkdir(mode=0o755)
        os.chown(directory, uid, gid)
    fd, temporary = tempfile.mkstemp(prefix='.order-activity-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chown(temporary, uid, gid)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def run(command, cwd):
    completed = subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
    if completed.returncode:
        raise RuntimeError('RELEASE_CHECK_FAILED: ' + command[-1])
    return completed.stdout.decode('utf-8', errors='replace')


def rollback(root, backup):
    records = json.loads((backup / 'receipt.json').read_text())
    for name, info in records.items():
        path = checked_path(root, name)
        if digest(path.read_bytes()) != info['after']:
            raise RuntimeError('ROLLBACK_CONCURRENT_EDIT: ' + name)
    for name, info in records.items():
        path = root / name
        if info['before'] is None:
            path.unlink()
        else:
            original = (backup / name).read_bytes()
            if digest(original) != info['before']:
                raise RuntimeError('BACKUP_HASH_MISMATCH')
            atomic(path, original, info['uid'], info['gid'], info['mode'])


def apply(root, package, backup, uid, gid, php=PHP, runner=run):
    changes = plan(root, package)
    if not changes:
        return False
    # Lint the complete proposed files before changing the application checkout.
    with tempfile.TemporaryDirectory(prefix='order-activity-lint-') as stage:
        for index, (name, (_, after)) in enumerate(changes.items()):
            target = Path(stage) / (str(index) + '.php')
            target.write_bytes(after)
            runner([php, '-l', str(target)], root)
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    records = {}
    for name, (before, after) in changes.items():
        path = root / name
        info = path.stat() if path.exists() else None
        records[name] = {'before': digest(before) if before is not None else None, 'after': digest(after),
                         'uid': info.st_uid if info else uid, 'gid': info.st_gid if info else gid,
                         'mode': stat.S_IMODE(info.st_mode) if info else 0o644}
        if before is not None:
            target = backup / name
            target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            target.write_bytes(before)
            target.chmod(0o600)
    (backup / 'receipt.json').write_text(json.dumps(records, indent=2))
    written = []
    try:
        for name, (before, after) in changes.items():
            path = checked_path(root, name, missing=before is None)
            current = path.read_bytes() if path.exists() else None
            if current != before:
                raise RuntimeError('CONCURRENT_EDIT: ' + name)
            info = records[name]
            atomic(path, after, info['uid'], info['gid'], info['mode'])
            written.append(name)
        runner([php, 'artisan', 'migrate', '--path=' + MIGRATION, '--force'], root)
        runner([php, 'artisan', 'optimize:clear'], root)
        routes = runner([php, 'artisan', 'route:list', '--path=live-activities'], root)
        if 'live-activities' not in routes:
            raise RuntimeError('ROUTE_VERIFICATION_FAILED')
        runner([php, 'artisan', 'orders:sync-live-activities'], root)
    except Exception:
        # Restore only the files written by this run. Preserve the additive table.
        subset = {name: records[name] for name in written}
        (backup / 'receipt.json').write_text(json.dumps(subset, indent=2))
        rollback(root, backup)
        runner([php, 'artisan', 'optimize:clear'], root)
        raise
    return True


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['check', 'apply', 'rollback'], default='apply', nargs='?')
    parser.add_argument('backup', nargs='?')
    args = parser.parse_args()
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('EXPECTED_PRODUCTION_HOST_AND_ROOT')
    if ROOT.resolve() != ROOT or not (ROOT / 'artisan').is_file():
        raise RuntimeError('EXPECTED_APPLICATION_ROOT')
    account = pwd.getpwnam('fasakha')
    if not (ROOT / 'storage/app/firebase_credentials.json').is_file():
        raise RuntimeError('EXISTING_FCM_CREDENTIALS_REQUIRED')
    with open('/var/lock/fasakhansta-order-activity.lock', 'a') as handle:
        fcntl.flock(handle, fcntl.LOCK_EX)
        if args.action == 'rollback':
            if not args.backup:
                raise RuntimeError('BACKUP_REQUIRED')
            backup = Path(args.backup).resolve()
            parent = Path('/home/fasakha/order-activity-backups')
            if backup.parent != parent:
                raise RuntimeError('UNEXPECTED_BACKUP_PATH')
            rollback(ROOT, backup)
            run([PHP, 'artisan', 'optimize:clear'], ROOT)
            print('ORDER_LIVE_ACTIVITY_FILES_ROLLED_BACK')
        elif args.action == 'check':
            changes = plan(ROOT, PACKAGE)
            print('ORDER_LIVE_ACTIVITY_PREFLIGHT_OK files=' + str(len(changes)))
        else:
            from datetime import datetime, timezone
            backup = Path('/home/fasakha/order-activity-backups') / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
            changed = apply(ROOT, PACKAGE, backup, account.pw_uid, account.pw_gid)
            print('ORDER_LIVE_ACTIVITY_APPLIED' if changed else 'ORDER_LIVE_ACTIVITY_ALREADY_APPLIED')
            if changed:
                print('Backup: ' + str(backup))


if __name__ == '__main__':
    main()
