#!/usr/bin/env python3
"""Correct only the exact GO login slogan; preserve all other live login content."""
import argparse, datetime, fcntl, os, pwd, shutil, stat, subprocess, tempfile
from pathlib import Path

LOGIN = 'resources/views/admin/login.blade.php'
OLD = b'<p class="dashboard-login-brand-title go-erp-tagline" lang="en" dir="ltr">THE INTELEGENT SYSTEM FOR SALES AND INVENTORY AND HR</p>'
NEW = b'<p class="dashboard-login-brand-title go-erp-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>'

def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for part in reversed((path, *path.parents)):
        require(not part.is_symlink(), 'SYMLINK_REJECTED')


def read(path):
    safe(path)
    with path.open('rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_size <= 16777216, 'INVALID_FILE')
        return stream.read(), info


def unchanged(path, before):
    safe(path)
    body, info = read(path)
    return body == before[0] and all(getattr(info, k) == getattr(before[1], k)
        for k in ('st_ino', 'st_dev', 'st_uid', 'st_gid', 'st_mode', 'st_mtime_ns', 'st_ctime_ns'))


def plan(login):
    require(login.count(OLD) + login.count(NEW) == 1 and
            login.count(b'class="dashboard-login-brand-title') == 1, 'SLOGAN_PARAGRAPH_NEEDS_REVIEW')
    return login.replace(OLD, NEW, 1)


def stage(directory, body, info):
    fd, name = tempfile.mkstemp(prefix='.go-slogan-', dir=directory)
    try:
        with os.fdopen(fd, 'wb') as stream:
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
    root = args.root
    safe(root)
    paths = [root / LOGIN]
    before = [read(path) for path in paths]
    desired = [plan(before[0][0])]
    changed = [i for i in range(1) if before[i][0] != desired[i]]
    if args.check or not changed:
        print('GO_ERP_SLOGAN_CHECK_OK' if args.check else 'GO_ERP_SLOGAN_ALREADY_CURRENT')
        for i, path in enumerate(paths):
            print(('UPDATE ' if i in changed else 'CURRENT ') + str(path.relative_to(root)))
        return
    owner = pwd.getpwnam('fasakha')
    require(os.geteuid() in (0, owner.pw_uid), 'RUN_AS_ROOT_OR_FASAKHA')
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    require(Path(php).is_file() and (os.geteuid() != 0 or shutil.which('runuser')), 'PHP_OR_RUNUSER_MISSING')
    safe(root / 'artisan')
    command = (['runuser', '-u', 'fasakha', '--'] if os.geteuid() == 0 else []) + [php, 'artisan', 'view:clear']
    private = root.parent / 'go-erp-logo-backups'
    safe(private)
    require(private != root and root not in private.parents, 'BACKUP_MUST_BE_PRIVATE')
    private.mkdir(mode=0o700, exist_ok=True)
    require(private.is_dir() and stat.S_IMODE(private.stat().st_mode) == 0o700, 'BACKUP_DIRECTORY_MODE_INVALID')
    lock_path = private / 'apply.lock'
    safe(lock_path)
    with os.fdopen(os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600), 'r+b') as lock:
        require(stat.S_ISREG(os.fstat(lock.fileno()).st_mode), 'INVALID_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir=private))
        staged, published = {}, []
        try:
            for i, path in enumerate(paths):
                require(unchanged(path, before[i]), 'TARGET_CHANGED_BEFORE_BACKUP')
                if before[i]:
                    saved = stage(backup, before[i][0], before[i][1])
                    saved.rename(backup / (path.name + '.before'))
                if i in changed:
                    staged[i] = stage(path.parent, desired[i], before[i][1])
            require(all(unchanged(path, before[i]) for i, path in enumerate(paths)), 'TARGET_CHANGED_BEFORE_PUBLICATION')
            for i in changed:
                require(unchanged(paths[i], before[i]), 'TARGET_CHANGED_DURING_PUBLICATION')
                os.replace(staged[i], paths[i])
                published.append(i)
            subprocess.run(command, cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(all(read(path)[0] == desired[i] for i, path in enumerate(paths)), 'FINAL_CHECK_FAILED')
            print('GO_ERP_SLOGAN_OK\nBACKUP ' + str(backup))
        except BaseException:
            for i in reversed(published):
                require(read(paths[i])[0] == desired[i], 'ROLLBACK_BLOCKED_BY_CONCURRENT_EDIT')
                restored = stage(paths[i].parent, before[i][0], before[i][1])
                os.replace(restored, paths[i])
            print('ROLLBACK_RESTORED\nBACKUP ' + str(backup))
            raise
        finally:
            for temporary in staged.values():
                temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('STOPPED: ' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__))
