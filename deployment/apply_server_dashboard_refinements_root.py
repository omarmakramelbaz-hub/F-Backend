#!/usr/bin/env python3
"""Publish reviewed server dashboard refinements without changing desktop update controls."""
import argparse
from contextlib import ExitStack
import fcntl
import hashlib
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile
import time
import types

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BASE_BLOB = '190abfe6ff0f9d11024a495e7a1aa3d79e879dc4'
SERVICE = 'app/Services/Dashboard/UnpaidOrderCancellation.php'
CONTROLLER = 'app/Http/Controllers/Dashboard/OrderBoardController.php'
# A missing predecessor means the file must be new or already exactly current.
PINS = {'app/Services/Dashboard/UnpaidOrderCancellation.php': (None, 'f4ac44a8601b1063871414b97cfaeda9e645980c9906c801ca23bad8e941c6de'), 'app/Services/Payments/LegacyPaymentFailure.php': ('0d3042d7c3b1c6f919d9aa7e87cc229835414d44dd18ea9092656b1ee1bc9d9d', 'ef49cf1649a25fa241911530f7d58d252b3fc1b41daad58c6172fae772578b64'), 'app/Services/Dashboard/OrderBoardService.php': ('0f55a52febe52a190fe94de85a5b9de5331f0c712c5939fd7f6f864292eb562f', '024a82e30e46832a27726f5408f03afbe2e0714d380944cd18cf6c8cf3a1cbf2'), 'public/dashboard/css/home-hud.css': ('1015e9e0a9a6dcc428ae07609ba7b113a86263ec1c7fcb0b435670de8725aebb', '091c45cb02d71805307a17551b9c43394bdfe88e6ebd5923c5ebc4a9d9137cc8'), 'public/dashboard/branding/dashboard-brand.css': ('6c0d2476c871d21a2df6dc7c07e4afa0e5727c4324795b26e6dac68100d1077a', 'f39b2f8dbb68994aae0965476e7b766a5837f3b6f432ed39b0db37fe5319cd68'), 'public/dashboard/js/dashboard-order-alerts.js': ('e054d15da3589e40e6dfb48a14b8ccbbbad329625801ca10361dca620c93ba62', 'ad44f9c5a3a8e0a98e40b076f906e39d2a4372f34b254119ff2050afe8195247'), 'resources/views/admin/home_hud_solar.blade.php': ('70810555e85770cef721fbdb9c4caddcaaa1388bb7c9f6adfc16b0dd7275e2b9', 'fd7741bbee22f5f43fee5317cc3747238927be283e030674986602922bbc130d'), 'resources/views/admin/layouts/menu.blade.php': ('1bd7ded59ce0ed1decccf7ba0a011b7d1089b9bfaa744a712380b913e8ba7aaf', 'e4f7a5d17953d93a998db0ec4b240e4b56ec75bc863fc3d3c8a5394c8e07663c'), 'resources/views/admin/orders/board_card.blade.php': ('4b9645a0104b27da44bfe27d7a3f449d056d8124dcf576cf4b9fd2da453c0f5b', '09a952325ade4fbe86083fe688fb85d5ffe2fb975803deaf34b2830da2574b94'), 'public/dashboard/js/home-hud.js': ('bd4bae707b04ee12ae39d2d03eca9cc8200760e5f3c6b10c38feb39da9e9889f', '9cd5f9b92c68767da63dc87efe3a89336619d162570cc72d41f381b9dd98498c'), 'app/Http/Controllers/Payment/RestaurantPaymentController.php': ('0810327324eed83eee58b57de028efebf9b95ce771a99359495c9ea9b89b2846', '1bf3aa3b531bb0bb7afc0c33ccbfbe97383c7ab63fd971c2d947a7a89b41cee4'), 'app/Http/Controllers/Dashboard/OrderBoardController.php': ('a786450dd87bb1c8c97a34828e9dffa4ea7916402b94a7a9ee56aae86814709b', '68ec5318c61842e4597ed4fbaf1bea6e34515eccd9c4cfb006cc9f53e8b3b697')}


def cache_replace(target, content, uid, gid, mode):
    """Preserve attributes and advance filemtime-based asset cache keys."""
    old = target.lstat().st_mtime_ns if target.exists() else 0
    descriptor, temporary = tempfile.mkstemp(prefix='.home-hud-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            output.write(content)
            output.flush()
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            fresh = max(time.time_ns(), (old // 1000000000 + 1) * 1000000000)
            os.utime(output.fileno(), ns=(fresh, fresh))
            os.fsync(output.fileno())
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def engine(content):
    actual = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
    if actual != BASE_BLOB:
        raise RuntimeError('PUBLISH_ENGINE_PIN_INVALID')
    if not PINS or tuple(PINS)[-1] != CONTROLLER:
        raise RuntimeError('RELEASE_PINS_PENDING')
    module = types.ModuleType('_home_hud_publish_engine')
    exec(compile(content, '<pinned-publish-engine>', 'exec'), module.__dict__)
    module.PATHS = tuple(PINS)
    module.replace_file = cache_replace
    return module


def manifest():
    return {path: {'before': [] if before is None else [before], 'after': after}
        for path, (before, after) in PINS.items()}


def lint_payloads(payloads, runner=None):
    runner = subprocess.run if runner is None else runner
    try:
        with tempfile.TemporaryDirectory(prefix='home-hud-lint-') as temporary:
            for index, name in enumerate(path for path in PINS if path.endswith('.php')):
                staged = Path(temporary) / (str(index) + '.php')
                staged.write_bytes(payloads[name])
                result = runner([PHP, '-n', '-l', str(staged)], stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL, timeout=30, check=False)
                if result.returncode != 0:
                    raise RuntimeError('LINT_FAILED')
    except Exception:
        raise RuntimeError('PHP_SYNTAX_PREFLIGHT_FAILED') from None


def post_publication(root, runner=None):
    """Compiled views are cleared after source commits; failure leaves source installed."""
    runner = subprocess.run if runner is None else runner
    try:
        result = runner(['/usr/sbin/runuser', '-u', 'fasakha', '--', PHP,
            '-d', 'display_errors=0', '-d', 'log_errors=0', 'artisan', 'view:clear'],
            cwd=str(root), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=90, check=False)
        if result.returncode != 0:
            raise RuntimeError('VIEW_CLEAR_FAILED')
    except Exception:
        raise RuntimeError('POST_PUBLICATION_VIEW_CLEAR_FAILED') from None


def publish_package(root, publisher, payloads, backup, uid, gid, runner=None):
    # The first plan checks the complete package before any source is written.
    # A second plan and the engine's per-file guards reject intervening changes.
    publisher.plan(root, manifest(), payloads, owner_uid=uid)
    lint_payloads(payloads, runner)
    changes = publisher.plan(root, manifest(), payloads, owner_uid=uid)
    if changes:
        publisher.publish(root, changes, backup, uid, gid)
        print('SERVER_DASHBOARD_SOURCE_READY', flush=True)
        print('BACKUP=' + str(backup), flush=True)
    else:
        print('SERVER_DASHBOARD_SOURCE_ALREADY_APPLIED', flush=True)
    # Keep this outside the engine's transaction, including on idempotent retry.
    post_publication(root, runner)
    return bool(changes)


def acquire(handles, path, label):
    descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    held = handles.enter_context(os.fdopen(descriptor, 'r+'))
    info = os.fstat(held.fileno())
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
        raise RuntimeError(label + '_LOCK_INVALID')
    try:
        fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise RuntimeError(label + '_BUSY_RETRY_AFTER_COMPLETION') from None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('SERVER_INVALID')
    if not re.fullmatch('[a-f0-9]{40}', args.release):
        raise RuntimeError('RELEASE_INVALID')
    owner = pwd.getpwnam('fasakha')
    if ROOT.is_symlink() or ROOT.resolve() != ROOT or not ROOT.is_dir() or ROOT.stat().st_uid != owner.pw_uid:
        raise RuntimeError('ROOT_INVALID')
    repository = Path(args.git_dir).absolute()
    if repository.resolve() != repository or not repository.is_dir() or repository.stat().st_uid != 0 or repository.stat().st_mode & 0o022:
        raise RuntimeError('PACKAGE_INVALID')

    def git(*arguments):
        value = subprocess.check_output(['/usr/bin/git', '--git-dir=' + str(repository),
            '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', '-c', 'credential.helper=', *arguments],
            env={'PATH': '/usr/bin:/bin', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_SYSTEM': '/dev/null',
                'GIT_CONFIG_NOSYSTEM': '1', 'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_NO_LAZY_FETCH': '1',
                'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}, stderr=subprocess.DEVNULL, timeout=20)
        if len(value) > 2 * 1024 * 1024:
            raise RuntimeError('PACKAGE_SIZE_INVALID')
        return value

    if git('rev-parse', args.release + '^{commit}').decode().strip() != args.release:
        raise RuntimeError('RELEASE_INVALID')
    publisher = engine(git('show', args.release + ':deployment/apply_paymob_order_confirmation_root.py'))
    payloads = {path: git('show', args.release + ':' + path) for path in PINS}
    with ExitStack() as handles:
        acquire(handles, '/run/lock/fasakhansta-home-hud.lock', 'HUD_PUBLICATION')
        acquire(handles, '/run/lock/fasakhansta-paymob-confirmation.lock', 'PAYMENT_PUBLICATION')
        work = Path('/var/lib/go-erp-live-updates')
        if work.exists() or work.is_symlink():
            if work.is_symlink() or work.resolve() != work or not work.is_dir() or work.stat().st_uid != 0 or work.stat().st_mode & 0o022:
                raise RuntimeError('WATCHER_DIRECTORY_INVALID')
            acquire(handles, work / 'watcher.lock', 'WATCHER')
        publisher.plan(ROOT, manifest(), payloads, owner_uid=owner.pw_uid)
        if not args.apply:
            lint_payloads(payloads)
            print('SERVER_DASHBOARD_CHECK_READY')
            return
        backups = Path('/home/fasakha/dashboard-refinement-backups')
        backups.mkdir(mode=0o700, exist_ok=True)
        if backups.is_symlink() or backups.resolve() != backups or backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
            raise RuntimeError('BACKUP_ROOT_INVALID')
        backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
        publish_package(ROOT, publisher, payloads, backup, owner.pw_uid, owner.pw_gid)
        print('SERVER_DASHBOARD_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, RuntimeError) and re.fullmatch('[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print('SERVER_DASHBOARD_FAILED_' + code)
        raise SystemExit(1)
