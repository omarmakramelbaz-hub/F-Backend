#!/usr/bin/env python3
"""Publish a pinned Paymob order-confirmation package without a live Git checkout."""
import argparse
from contextlib import ExitStack
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
PATHS = (
    'app/Http/Controllers/CheckoutController.php',
    'app/Http/Controllers/Payment/PaymobController.php',
    'app/Http/Controllers/Payment/RestaurantPaymentController.php',
    'app/Services/Payments/RestaurantOrderPayment.php',
    'app/Services/Payments/RestaurantPaymentEffects.php',
    'app/Console/Commands/ReconcileRestaurantPayments.php',
    'app/Console/Kernel.php',
    'app/Services/Dashboard/OrderBoardService.php',
    'routes/api.php',
    'resources/views/admin/orders/board_card.blade.php',
    'resources/views/admin/orders/board_detail.blade.php',
    'resources/lang/ar/order_board.php',
    'resources/lang/en/order_board.php',
    'public/dashboard/css/order-board.css',
)


def digest(content):
    return hashlib.sha256(content).hexdigest()


def regular(root, name, missing=False):
    """Require real existing parent directories even when the final source is new."""
    if name not in PATHS or root.resolve() != root or not root.is_dir():
        raise RuntimeError('ROOT_OR_PATH_INVALID')
    target = root / name
    for parent in list(target.parents)[:len(Path(name).parts) - 1]:
        if parent.is_symlink() or not parent.is_dir():
            raise RuntimeError('SOURCE_PARENT_INVALID')
    if target.is_symlink():
        raise RuntimeError('SYMLINK_REJECTED')
    try:
        info = target.lstat()
    except FileNotFoundError:
        if missing:
            return target, None
        raise RuntimeError('SOURCE_MISSING') from None
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise RuntimeError('FILE_TYPE_INVALID')
    return target, info


def attributes(info):
    return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)


def plan(root, manifest, payloads, owner_uid=None):
    if not isinstance(manifest, dict) or set(manifest) != set(PATHS) or set(payloads) != set(PATHS):
        raise RuntimeError('PACKAGE_PATHS_INVALID')
    changes = []
    for name in PATHS:
        entry = manifest[name]
        if (not isinstance(entry, dict) or set(entry) != {'before', 'after'} or not isinstance(entry['before'], list)
                or len(entry['before']) > 2):
            raise RuntimeError('MANIFEST_INVALID')
        if any(not isinstance(value, str) or not re.fullmatch('[a-f0-9]{64}', value) for value in [entry['after'], *entry['before']]):
            raise RuntimeError('MANIFEST_INVALID')
        if len(set(entry['before'])) != len(entry['before']):
            raise RuntimeError('MANIFEST_INVALID')
        desired = payloads[name]
        if not isinstance(desired, bytes) or digest(desired) != entry['after']:
            raise RuntimeError('PACKAGE_HASH_INVALID')
        target, info = regular(root, name, missing=True)
        if info is None:
            if entry['before']:
                raise RuntimeError('BASELINE_MISMATCH')
            changes.append((name, None, desired, None))
            continue
        if owner_uid is not None and info.st_uid != owner_uid:
            raise RuntimeError('SOURCE_OWNER_INVALID')
        original = target.read_bytes()
        if digest(original) == entry['after']:
            continue
        if digest(original) not in entry['before']:
            raise RuntimeError('BASELINE_MISMATCH')
        changes.append((name, original, desired, info))
    return changes


def replace_file(target, content, uid, gid, mode):
    descriptor, temporary = tempfile.mkstemp(prefix='.paymob-confirmation-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            output.write(content)
            output.flush()
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            os.fsync(output.fileno())
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def unchanged_source(root, change):
    name, original, _, old_info = change
    target, info = regular(root, name, missing=True)
    if old_info is None:
        if info is not None:
            raise RuntimeError('SOURCE_CHANGED')
    elif info is None or target.read_bytes() != original or attributes(info) != attributes(old_info):
        raise RuntimeError('SOURCE_CHANGED')
    return target


def publish(root, changes, backup, new_uid=None, new_gid=None):
    """Back up all originals before publication; preserve concurrent edits on rollback."""
    new_uid = os.geteuid() if new_uid is None else new_uid
    new_gid = os.getegid() if new_gid is None else new_gid
    for change in changes:
        unchanged_source(root, change)
    backup.mkdir(mode=0o700)
    metadata = {}
    for name, original, desired, info in changes:
        uid, gid, mode = attributes(info) if info is not None else (new_uid, new_gid, 0o644)
        metadata[name] = {'existed': info is not None, 'before': digest(original) if original is not None else None,
            'after': digest(desired), 'uid': uid, 'gid': gid, 'mode': mode}
        if original is not None:
            saved = backup / name
            saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            saved.write_bytes(original)
            saved.chmod(0o600)
    audit = backup / 'metadata.json'
    audit.write_text(json.dumps(metadata, indent=2) + '\n')
    audit.chmod(0o600)
    written = []
    try:
        # Every new class must exist before any live controller, Kernel or route calls it.
        ordered = [change for change in changes if change[3] is None] + [change for change in changes if change[3] is not None]
        for change in ordered:
            target = unchanged_source(root, change)
            name, _, desired, _ = change
            record = metadata[name]
            replace_file(target, desired, record['uid'], record['gid'], record['mode'])
            written.append(change)
        for name, _, desired, _ in changes:
            target, info = regular(root, name)
            record = metadata[name]
            if target.read_bytes() != desired or attributes(info) != (record['uid'], record['gid'], record['mode']):
                raise RuntimeError('PUBLICATION_VERIFY_FAILED')
    except BaseException:
        review = False
        for name, original, desired, info in reversed(written):
            try:
                target, current = regular(root, name, missing=True)
                if current is None and original is None:
                    continue
                record = metadata[name]
                if current is None or target.read_bytes() != desired or attributes(current) != (record['uid'], record['gid'], record['mode']):
                    review = True
                    continue
                if original is None:
                    target.unlink()
                else:
                    uid, gid, mode = attributes(info)
                    replace_file(target, original, uid, gid, mode)
            except BaseException:
                review = True
        if review:
            raise RuntimeError('ROLLBACK_REVIEW_REQUIRED') from None
        raise


def reconciliation_receipt(output):
    prefix = 'RESTAURANT_PAYMENT_RECONCILIATION '
    if not isinstance(output, (str, bytes)) or len(output) > 65536:
        raise RuntimeError('RECONCILIATION_RECEIPT_INVALID')
    if isinstance(output, bytes):
        matches = [line[len(prefix):] for line in output.splitlines() if line.startswith(prefix.encode())]
    else:
        matches = [line[len(prefix):] for line in output.splitlines() if line.startswith(prefix)]
    if not matches:
        raise RuntimeError('RECONCILIATION_RECEIPT_MISSING')
    if len(matches) != 1:
        raise RuntimeError('RECONCILIATION_RECEIPT_INVALID')
    try:
        data = json.loads(matches[0])
    except (ValueError, UnicodeError):
        raise RuntimeError('RECONCILIATION_RECEIPT_INVALID') from None
    counts = ('candidates', 'checked', 'activated', 'unchanged', 'closed', 'deferred', 'effects_deferred')
    if not isinstance(data, dict) or data.get('mode') != 'apply' or any(type(data.get(key)) is not int or not 0 <= data[key] <= 5 for key in counts):
        raise RuntimeError('RECONCILIATION_RECEIPT_INVALID')
    completed = data['activated'] + data['unchanged'] + data['closed']
    if completed + data['deferred'] != data['candidates'] or not completed <= data['checked'] <= data['candidates'] or data['effects_deferred'] > data['activated']:
        raise RuntimeError('RECONCILIATION_RECEIPT_INVALID')
    # Extra fields and unrelated app/provider output are never forwarded.
    return {'mode': 'apply', **{key: data[key] for key in counts}}


def post_publication(root, reconcile=False, runner=subprocess.run):
    """Source is committed before these operations; a failure must never undo DB recovery."""
    steps = [('CACHE_ROUTE', ['route:clear']), ('CACHE_VIEW', ['view:clear'])]
    if reconcile:
        steps.append(('RECONCILIATION', ['order-payments:reconcile', '--apply', '--limit=5']))
    for label, arguments in steps:
        command = ['/usr/sbin/runuser', '-u', 'fasakha', '--', PHP,
            '-d', 'display_errors=0', '-d', 'log_errors=0', 'artisan', *arguments]
        try:
            result = runner(command, cwd=str(root), stdout=subprocess.PIPE if label == 'RECONCILIATION' else subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, timeout=90, check=False)
            if result.returncode != 0:
                raise RuntimeError('STEP_FAILED')
        except Exception:
            raise RuntimeError('POST_PUBLICATION_' + label + '_FAILED') from None
        if label == 'RECONCILIATION':
            try:
                receipt = reconciliation_receipt(result.stdout)
            except RuntimeError as error:
                raise RuntimeError('POST_PUBLICATION_' + str(error)) from None
            print('RESTAURANT_PAYMENT_RECONCILIATION ' + json.dumps(receipt, sort_keys=True), flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--reconcile', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    if args.reconcile and not args.apply:
        raise RuntimeError('RECONCILE_REQUIRES_APPLY')
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('SERVER_INVALID')
    if not re.fullmatch('[a-f0-9]{40}', args.release):
        raise RuntimeError('RELEASE_INVALID')
    account = pwd.getpwnam('fasakha')
    if ROOT.is_symlink() or ROOT.resolve() != ROOT or not ROOT.is_dir() or ROOT.stat().st_uid != account.pw_uid:
        raise RuntimeError('ROOT_INVALID')
    with ExitStack() as handles:
        def acquire(path, label):
            descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
            held = handles.enter_context(os.fdopen(descriptor, 'r+'))
            info = os.fstat(held.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
                raise RuntimeError(label + '_LOCK_INVALID')
            try:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeError(label + '_BUSY_RETRY_AFTER_COMPLETION') from None
        acquire('/run/lock/fasakhansta-paymob-confirmation.lock', 'PUBLICATION')
        work = Path('/var/lib/go-erp-live-updates')
        if work.exists() or work.is_symlink():
            if work.is_symlink() or work.resolve() != work or not work.is_dir() or work.stat().st_uid != 0 or work.stat().st_mode & 0o022:
                raise RuntimeError('WATCHER_DIRECTORY_INVALID')
            acquire(work / 'watcher.lock', 'WATCHER')
        def git(*arguments):
            return subprocess.check_output(['/usr/bin/git', '--git-dir=' + args.git_dir, *arguments],
                env={'PATH': '/usr/bin:/bin', 'GIT_NO_REPLACE_OBJECTS': '1'}, stderr=subprocess.DEVNULL)
        if git('rev-parse', args.release + '^{commit}').decode().strip() != args.release:
            raise RuntimeError('RELEASE_INVALID')
        manifest = json.loads(git('show', args.release + ':deployment/paymob_order_confirmation.json'))['files']
        payloads = {name: git('show', args.release + ':' + name) for name in PATHS}
        changes = plan(ROOT, manifest, payloads, owner_uid=account.pw_uid)
        with tempfile.TemporaryDirectory(prefix='paymob-confirmation-lint-') as temporary:
            for index, name in enumerate(PATHS):
                if not name.endswith('.php'):
                    continue
                staged = Path(temporary) / (str(index) + '.php')
                staged.write_bytes(payloads[name])
                subprocess.run([PHP, '-n', '-l', str(staged)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if not args.apply:
            print('PAYMOB_ORDER_CONFIRMATION_CHECK_READY')
            return
        if changes:
            backups = Path('/home/fasakha/paymob-confirmation-backups')
            backups.mkdir(mode=0o700, exist_ok=True)
            if backups.is_symlink() or backups.resolve() != backups or backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
                raise RuntimeError('BACKUP_ROOT_INVALID')
            backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
            plan(ROOT, manifest, payloads, owner_uid=account.pw_uid)
            publish(ROOT, changes, backup, account.pw_uid, account.pw_gid)
            print('PAYMOB_ORDER_CONFIRMATION_SOURCE_READY', flush=True)
            print('BACKUP=' + str(backup), flush=True)
        else:
            print('PAYMOB_ORDER_CONFIRMATION_SOURCE_ALREADY_APPLIED', flush=True)
        # Repeat these even for identical source, allowing recovery after a partial post-step.
        post_publication(ROOT, args.reconcile)
        print('PAYMOB_ORDER_CONFIRMATION_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('PAYMOB_ORDER_CONFIRMATION_FAILED_' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__))
        raise SystemExit(1)
