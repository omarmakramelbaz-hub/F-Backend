#!/usr/bin/env python3
"""Publish compatible dashboard fitting and a clear logo over a translucent solar disc."""
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
SERVICE = 'resources/views/admin/layouts/header.blade.php'
CONTROLLER = 'resources/views/admin/layouts/footer.blade.php'
# A missing predecessor means the file must be new or already exactly current.
PINS = {'public/dashboard/css/dashboard-fit.css': (None, '0596d4d0d6afb95f3c87947b20bc74229120ad05c3348486b13edfc05de65eb9'), 'public/dashboard/js/dashboard-fit.js': (None, '9aa3d4a1e168adfe3a6876b8a9430c012aab70cdc8068bd332ab39e748bde278'), 'public/dashboard/css/home-hud.css': ('091c45cb02d71805307a17551b9c43394bdfe88e6ebd5923c5ebc4a9d9137cc8', '0da8383f0b3cf481dd4d2166fa9449a62ebd4d1c662faeac812859497bbb5a7f'), 'resources/views/admin/home_hud_solar.blade.php': ('fd7741bbee22f5f43fee5317cc3747238927be283e030674986602922bbc130d', '136d5bf688a85a8775ae2a174edb83ff6a86f09444a4899535322e6b9ca71e8f'), 'public/dashboard/js/home-hud.js': ('9cd5f9b92c68767da63dc87efe3a89336619d162570cc72d41f381b9dd98498c', '56d8d5096d93751aa856bcbbe25f9bd07dd43b53da358b44c599e85a9fdabd11'), 'resources/views/admin/layouts/header.blade.php': ('8afa62cd73ce1800f94f85e8109335aa13dddb1d565b28cf4ecf178f46401b1b', 'bac73f60b80d035c8d557022cac3f52874714b12f4268bdec996a8af86a9de76'), 'resources/views/admin/layouts/footer.blade.php': ('ed091f6cb975e54edd638190e82f047531dcd3ef1a77ad4f3b619d19fa9871a5', '3723a75a747366254e995d3fa3d9c63b85712407d475f07d7b0b1a756fd4af6f')}


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
        print('DASHBOARD_FIT_SOURCE_READY', flush=True)
        print('BACKUP=' + str(backup), flush=True)
    else:
        print('DASHBOARD_FIT_SOURCE_ALREADY_APPLIED', flush=True)
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
        acquire(handles, '/run/lock/fasakhansta-home-hud.lock', 'PUBLICATION')
        work = Path('/var/lib/go-erp-live-updates')
        if work.exists() or work.is_symlink():
            if work.is_symlink() or work.resolve() != work or not work.is_dir() or work.stat().st_uid != 0 or work.stat().st_mode & 0o022:
                raise RuntimeError('WATCHER_DIRECTORY_INVALID')
            acquire(handles, work / 'watcher.lock', 'WATCHER')
        publisher.plan(ROOT, manifest(), payloads, owner_uid=owner.pw_uid)
        if not args.apply:
            lint_payloads(payloads)
            print('DASHBOARD_FIT_CHECK_READY')
            return
        backups = Path('/home/fasakha/dashboard-fit-backups')
        backups.mkdir(mode=0o700, exist_ok=True)
        if backups.is_symlink() or backups.resolve() != backups or backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
            raise RuntimeError('BACKUP_ROOT_INVALID')
        backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
        publish_package(ROOT, publisher, payloads, backup, owner.pw_uid, owner.pw_gid)
        print('DASHBOARD_FIT_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, RuntimeError) and re.fullmatch('[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print('DASHBOARD_FIT_FAILED_' + code)
        raise SystemExit(1)
