#!/usr/bin/env python3
"""Set readable wallet-card text while preserving the live Blade template."""
import argparse
import datetime
import fcntl
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import tempfile

COLOR = b'            color: #243446;\n'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def snapshot(path):
    require(path.is_absolute(), 'ABSOLUTE_ROOT_REQUIRED')
    require(not any(p.is_symlink() for p in (path, *path.parents)), 'SYMLINK_REJECTED')
    with path.open('rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_size <= 1048576, 'INVALID_TARGET')
        return stream.read(), info


def transform(body):
    styles = list(re.finditer(rb'<style\b[^>]*>', body))
    require(len(styles) == 1 and body.count(b'</style>') == 1, 'STYLE_BLOCK_NEEDS_REVIEW')
    for selector in (b'.trans', b'.wallet'):
        opening = b'        ' + selector + b'{\n'
        require(body.count(opening) == 1, 'SELECTOR_NEEDS_REVIEW')
        start = body.index(opening) + len(opening)
        end = body.find(b'        }\n', start)
        require(styles[0].end() <= start < end < body.index(b'</style>'), 'RULE_BOUNDARIES_NEED_REVIEW')
        rule = body[start:end]
        colors = list(re.finditer(rb'(?im)(?<![-\w])color\s*:', rule))
        require(b'{' not in rule and b'}' not in rule, 'NESTED_RULE_REJECTED')
        if colors:
            require(rule.startswith(COLOR) and len(colors) == 1, 'EXISTING_COLOR_NEEDS_REVIEW')
        else:
            body = body[:start] + COLOR + body[start:]
    return body


def stage(directory, body, info):
    descriptor, name = tempfile.mkstemp(prefix='.wallet-contrast-', dir=directory)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
    except BaseException:
        Path(name).unlink(missing_ok=True)
        raise
    return Path(name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('/home/fasakha/public_html'))
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    target = args.root / 'resources/views/admin/users/wallet.blade.php'
    before, info = snapshot(target)
    after = transform(before)
    if args.check or after == before:
        print('WALLET_CONTRAST_CHECK_OK ' + ('UPDATE' if after != before else 'CURRENT'))
        return
    owner = pwd.getpwnam('fasakha')
    require(os.geteuid() in (0, owner.pw_uid), 'RUN_AS_ROOT_OR_FASAKHA')
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    require(Path(php).is_file() and (os.geteuid() != 0 or shutil.which('runuser')), 'PHP_OR_RUNUSER_MISSING')
    private = args.root.parent / 'wallet-contrast-backups'
    require(not private.is_symlink(), 'BACKUP_SYMLINK_REJECTED')
    private.mkdir(mode=0o700, exist_ok=True)
    require(stat.S_IMODE(private.stat().st_mode) == 0o700, 'BACKUP_DIRECTORY_MODE_INVALID')
    lock_path = private / 'apply.lock'
    require(not lock_path.is_symlink(), 'LOCK_SYMLINK_REJECTED')
    with lock_path.open('a+b') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        backup = stage(private, before, info)
        backup = backup.rename(private / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + backup.name[1:] + '.blade.php'))
        temporary, published = None, False
        try:
            temporary = stage(target.parent, after, info)
            current, latest = snapshot(target)
            require(current == before and all(getattr(latest, key) == getattr(info, key)
                    for key in ('st_ino', 'st_dev', 'st_uid', 'st_gid', 'st_mode', 'st_mtime_ns', 'st_ctime_ns')),
                    'TARGET_CHANGED_BEFORE_PUBLICATION')
            os.replace(temporary, target)
            published = True
            command = (['runuser', '-u', 'fasakha', '--'] if os.geteuid() == 0 else []) + [php, 'artisan', 'view:clear']
            subprocess.run(command, cwd=args.root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(snapshot(target)[0] == after, 'FINAL_CHECK_FAILED')
            print('WALLET_CONTRAST_OK\nBACKUP ' + str(backup))
        except BaseException:
            if published:
                require(snapshot(target)[0] == after, 'ROLLBACK_BLOCKED_BY_CONCURRENT_EDIT')
                restore = stage(target.parent, before, info)
                os.replace(restore, target)
                print('ROLLBACK_RESTORED')
            print('BACKUP ' + str(backup))
            raise
        finally:
            if temporary:
                temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('STOPPED: ' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__))
