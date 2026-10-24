#!/usr/bin/env python3
"""Publish the blue solar HUD asset while native publication remains paused."""
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
BASE_BLOB = '190abfe6ff0f9d11024a495e7a1aa3d79e879dc4'
SERVICE = 'public/dashboard/js/home-hud.js'
CONTROLLER = SERVICE
# A missing predecessor means the file must be new or already exactly current.
PINS = {'public/dashboard/js/home-hud.js': ('56d8d5096d93751aa856bcbbe25f9bd07dd43b53da358b44c599e85a9fdabd11', '2aa43644eb7f25b9b78b46847d5b697c31be6c88d5b3b9895a4a5417d6bdc556')}


def cache_replace(target, content, uid, gid, mode):
    """Preserve attributes and advance filemtime-based asset cache keys."""
    old = target.lstat().st_mtime_ns if target.exists() else 0
    descriptor, temporary = tempfile.mkstemp(prefix='.hud-blue-', dir=target.parent)
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
    module = types.ModuleType('_hud_blue_publish_engine')
    exec(compile(content, '<pinned-publish-engine>', 'exec'), module.__dict__)
    module.PATHS = tuple(PINS)
    module.replace_file = cache_replace
    return module


def manifest():
    return {path: {'before': [] if before is None else [before], 'after': after}
        for path, (before, after) in PINS.items()}


def require_quiescent(runner=None):
    """The timer stays paused and no worker may publish the previous source."""
    runner = subprocess.run if runner is None else runner
    for name, accepted in [('go-erp-live-updates.timer', ('inactive',)),
            ('go-erp-live-updates.service', ('inactive', 'failed'))]:
        result = runner(['/usr/bin/systemctl', 'show', name, '-p', 'ActiveState', '--value'],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            timeout=10, check=False)
        if result.returncode != 0 or result.stdout.decode('ascii', errors='replace').strip() not in accepted:
            raise RuntimeError('NATIVE_WORKER_NOT_QUIESCENT')


def publish_package(root, publisher, payloads, backup, uid, gid):
    # The first plan checks the complete package before any source is written.
    # A second plan and the engine's per-file guards reject intervening changes.
    publisher.plan(root, manifest(), payloads, owner_uid=uid)
    changes = publisher.plan(root, manifest(), payloads, owner_uid=uid)
    if changes:
        publisher.publish(root, changes, backup, uid, gid)
        print('HUD_BLUE_SOURCE_READY', flush=True)
        print('BACKUP=' + str(backup), flush=True)
    else:
        print('HUD_BLUE_SOURCE_ALREADY_APPLIED', flush=True)
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
        if args.apply:
            require_quiescent()
        publisher.plan(ROOT, manifest(), payloads, owner_uid=owner.pw_uid)
        if not args.apply:
            print('HUD_BLUE_CHECK_READY')
            return
        backups = Path('/home/fasakha/hud-blue-backups')
        backups.mkdir(mode=0o700, exist_ok=True)
        if backups.is_symlink() or backups.resolve() != backups or backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
            raise RuntimeError('BACKUP_ROOT_INVALID')
        backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
        publish_package(ROOT, publisher, payloads, backup, owner.pw_uid, owner.pw_gid)
        print('HUD_BLUE_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, RuntimeError) and re.fullmatch('[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print('HUD_BLUE_FAILED_' + code)
        raise SystemExit(1)
