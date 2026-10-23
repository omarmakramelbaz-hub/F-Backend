#!/usr/bin/env python3
"""Publish only the reviewed employee-card JavaScript and CSS on the server."""
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
PINS = {
    'public/dashboard/css/branch-operations.css': (
        'e8029639dee6691899a0d3c11622eba19feeaffb9b08925710b51b6837bb7526',
        '9383f72479617f47edb5707e3a8d3809044b2f7461965090aff46d3228457634'),
    'public/dashboard/js/branch-operations.js': (
        'f54401f74871473cf0a42b8d70d46221f38cded9c5bf6abcfe9f9ea66fcdb691',
        '3f7d59875897575339979a48e896a99db01c42c2fc3bf04c50e3fae29ccb75b1'),
}


def asset_replace(target, content, uid, gid, mode):
    """Preserve ownership/mode and advance the existing filemtime cache key."""
    old = target.lstat().st_mtime_ns if target.exists() else 0
    descriptor, temporary = tempfile.mkstemp(prefix='.employee-card-', dir=target.parent)
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
    module = types.ModuleType('_employee_card_publish_engine')
    exec(compile(content, '<pinned-publish-engine>', 'exec'), module.__dict__)
    module.PATHS = tuple(PINS)
    module.replace_file = asset_replace
    return module


def manifest():
    return {path: {'before': [before], 'after': after} for path, (before, after) in PINS.items()}


def identity_ready():
    # Read schema only; never run migrations or alter employee records.
    code = """ob_start();try{require '/home/fasakha/public_html/vendor/autoload.php';
    $a=require '/home/fasakha/public_html/bootstrap/app.php';
    $a->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    $ok=\\App\\Services\\Dashboard\\EmployeeIdentity::ready();
    ob_end_clean();echo $ok?'READY':'NOT_READY';exit($ok?0:2);
    }catch(\\Throwable $e){ob_end_clean();echo 'UNAVAILABLE';exit(3);} """
    result = subprocess.run(['/usr/sbin/runuser', '-u', 'fasakha', '--',
        '/opt/cpanel/ea-php82/root/usr/bin/php', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', code],
        cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30, check=False)
    if result.returncode == 2 and result.stdout == b'NOT_READY':
        raise RuntimeError('IDENTITY_SCHEMA_REQUIRED')
    if result.returncode != 0 or result.stdout != b'READY':
        raise RuntimeError('IDENTITY_BACKEND_PREFLIGHT_UNAVAILABLE')


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
        def acquire(path, label):
            fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
            held = handles.enter_context(os.fdopen(fd, 'r+'))
            info = os.fstat(held.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
                raise RuntimeError(label + '_LOCK_INVALID')
            try:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise RuntimeError(label + '_BUSY_RETRY_AFTER_COMPLETION') from None
        acquire('/run/lock/fasakhansta-employee-card-ui.lock', 'PUBLICATION')
        work = Path('/var/lib/go-erp-live-updates')
        if work.exists() or work.is_symlink():
            if work.is_symlink() or work.resolve() != work or not work.is_dir() or work.stat().st_uid != 0 or work.stat().st_mode & 0o022:
                raise RuntimeError('WATCHER_DIRECTORY_INVALID')
            acquire(work / 'watcher.lock', 'WATCHER')
        changes = publisher.plan(ROOT, manifest(), payloads, owner_uid=owner.pw_uid)
        identity_ready()
        if not args.apply:
            print('EMPLOYEE_CARD_UI_CHECK_READY')
            return
        if changes:
            backups = Path('/home/fasakha/employee-card-ui-backups')
            backups.mkdir(mode=0o700, exist_ok=True)
            if backups.is_symlink() or backups.resolve() != backups or backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
                raise RuntimeError('BACKUP_ROOT_INVALID')
            backup = backups / ('release-' + args.release[:12] + '-' + os.urandom(6).hex())
            publisher.publish(ROOT, changes, backup, owner.pw_uid, owner.pw_gid)
            print('BACKUP=' + str(backup))
        else:
            print('EMPLOYEE_CARD_UI_ALREADY_APPLIED')
        print('EMPLOYEE_CARD_UI_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if isinstance(error, RuntimeError) and re.fullmatch('[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        print('EMPLOYEE_CARD_UI_FAILED_' + code)
        raise SystemExit(1)
