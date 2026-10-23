#!/usr/bin/env python3
"""Publish the exact reviewed GO home HUD without changing routes or records."""
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
SERVICE = 'app/Services/Dashboard/HomeHudLinks.php'
CONTROLLER = 'app/Http/Controllers/Dashboard/HomeController.php'
# A missing predecessor means the file must be new or already exactly current.
PINS = {
    SERVICE: (None, 'ed63b333f503bd16b835220c5707a50425ab0ca6d0c7e4a63e3fb014b7a282a5'),
    'public/dashboard/css/home-hud.css':
        (None, '4c1f6edf44354fb334d45647d3f0ef36c6547a35708a65c170286da32a4052c7'),
    'public/dashboard/js/home-hud.js':
        (None, '79e6187c54139eb1c81f22cfdb9dd3d23301d0bb996ffd61ca1f02236afcf01e'),
    'resources/views/admin/home_hud_solar.blade.php':
        (None, '1483c826788c6f4663dfa209b0b0de43f4bbbb8864588648e451e2d5230d2d46'),
    'resources/views/admin/home_hud.blade.php':
        (None, '3277dee508199794c858b098d39bf9fc9ee04b072f97f3c409859e0b47084f9e'),
    'public/dashboard/js/branch-home.js':
        ('ed46480864ea9627b303f02871b21266c3609b1b0b4503de531bf46cf11c3038',
         '6f9ab4b17dff21262c3319e58b6116252fc8f15a99a8efec06b0ff9a2d528314'),
    'public/dashboard/js/home-overview.js':
        ('0fc9f94ec4b1a499dacf4cc22ed2d2a195712481a0a09b3d03995bd88183c7ba',
         '78a97876c4d859e855696b02bfd059b4cf4c6cd9d0900bbe9d8b8cdca65bf37e'),
    'resources/views/admin/home.blade.php':
        ('5712444132767cf4c1861dd1d13690dc9d688a1ecfdbf198657f89b397402825',
         '63f254a3974fab2f2c2fb9f9bbb9b7c7fce566f2a1031022bb7312ede85cc896'),
    'resources/views/admin/home_branch.blade.php':
        ('ae55cfb40ad27d76e3136898cd876ae45a14070813bf86c08aa824a6a6c761d1',
         '21c46d060e6c661692cf19c40f1f0880d642753031d351e66159e3b4ec4182eb'),
    CONTROLLER:
        ('a3fbf9e9a2553b7bf7365227e2502196c2cd3b5272255914583cfdf2d3edeacb',
         '275aa66eee50849bc8b79d658125c88606d57d006649a84be7368c3aa224b016'),
}


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
            for index, name in enumerate((SERVICE, CONTROLLER)):
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
        print('HOME_HUD_SOURCE_READY', flush=True)
        print('BACKUP=' + str(backup), flush=True)
    else:
        print('HOME_HUD_SOURCE_ALREADY_APPLIED', flush=True)
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
            print('HOME_HUD_CHECK_READY')
            return
        backups = Path('/home/fasakha/home-hud-backups')
        backups.mkdir(mode=0o700, exist_ok=True)
        if backups.is_symlink() or backups.resolve() != backups or backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
            raise RuntimeError('BACKUP_ROOT_INVALID')
        backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
        publish_package(ROOT, publisher, payloads, backup, owner.pw_uid, owner.pw_gid)
        print('HOME_HUD_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, RuntimeError) and re.fullmatch('[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print('HOME_HUD_FAILED_' + code)
        raise SystemExit(1)
