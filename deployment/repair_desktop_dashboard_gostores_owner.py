#!/usr/bin/env python3
"""Repair the two reviewed GoStores owners, then inventory publication metadata.

This standalone root helper does not bootstrap PHP, read .env contents, query
SQL, publish application code, or recursively change owners. The application
release remains pinned separately and must be invoked after this helper exits.
"""
import contextlib
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import signal
import stat
import subprocess
import sys
import tempfile

RELEASE = '24b552cead9e06294dbfce0e45e8f7b9eeba70e1'
MANIFEST = 'deployment/desktop_dashboard_server_manifest.json'
MANIFEST_BLOB = '08e935e2173efd79c8ee3d555d6c2c7cb0f09b5c'
DIRECTORY = Path('app/Services/GoStores')
CATALOG = DIRECTORY / 'Catalog.php'
CATALOG_BLOB = '9dd08877f66cb7288e4fe4f97ee670727b1ecc25'
LIMIT = 8 * 1024 * 1024


class ReviewRequired(Exception):
    pass


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid,
            value.st_gid, value.st_nlink, value.st_size,
            value.st_mtime_ns, value.st_ctime_ns)


@contextlib.contextmanager
def blocked_signals():
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)


def open_directories(path, root, uid, reviewed=None):
    """Open every ancestor without following symlinks; retain all descriptors."""
    if not path.is_absolute() or '..' in path.parts:
        raise ReviewRequired('DESKTOP_OWNER_REPAIR_UNSAFE_PATH')
    descriptors = []
    states = []
    current = Path('/')
    try:
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        descriptors.append(fd)
        for name in path.parts[1:]:
            current /= name
            fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                         dir_fd=fd)
            descriptors.append(fd)
            value = os.fstat(fd)
            project_path = current == root or root in current.parents
            allowed = (uid,) if project_path and current != reviewed else (0, uid)
            if value.st_uid not in allowed:
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_ANCESTOR_CHANGED')
            states.append(identity(value))
        return descriptors, states
    except BaseException:
        for fd in reversed(descriptors):
            os.close(fd)
        raise


def catalog_state(fd):
    before = os.fstat(fd)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_size > LIMIT or before.st_mode & 0o7022
            or not before.st_mode & 0o400):
        raise ReviewRequired('DESKTOP_OWNER_REPAIR_CATALOG_METADATA_UNREVIEWED')
    os.lseek(fd, 0, os.SEEK_SET)
    data = bytearray()
    while len(data) <= LIMIT:
        part = os.read(fd, min(65536, LIMIT + 1 - len(data)))
        if not part:
            break
        data.extend(part)
    after = os.fstat(fd)
    if len(data) > LIMIT or identity(before) != identity(after):
        raise ReviewRequired('DESKTOP_OWNER_REPAIR_CATALOG_CHANGED')
    if blob(data) != CATALOG_BLOB:
        raise ReviewRequired('DESKTOP_OWNER_REPAIR_CATALOG_BLOB_CHANGED')
    return after


def write_record(path, value):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as output:
        json.dump(value, output, sort_keys=True)
        output.write('\n')
        output.flush()
        os.fsync(output.fileno())


def repair(root, uid, gid, groups, journal_parent, before_change=None,
           after_change=None):
    if os.geteuid() != 0:
        raise ReviewRequired('DESKTOP_OWNER_REPAIR_REQUIRES_ROOT')
    path = root / DIRECTORY
    descriptors, states = open_directories(path, root, uid, reviewed=path)
    file_fd = None
    journal = None
    changed = []
    attempted = []
    try:
        directory = os.fstat(descriptors[-1])
        if directory.st_uid not in (0, uid) or stat.S_IMODE(directory.st_mode) != 0o755:
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_DIRECTORY_METADATA_UNREVIEWED')
        file_fd = os.open('Catalog.php', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                          dir_fd=descriptors[-1])
        catalog = catalog_state(file_fd)
        if catalog.st_uid not in (0, uid):
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_CATALOG_OWNER_UNREVIEWED')
        if catalog.st_uid == uid and catalog.st_gid not in groups:
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_EXISTING_APP_FILE_GROUP_UNREVIEWED')
        plans = []
        if directory.st_uid == 0:
            plans.append((str(DIRECTORY), descriptors[-1], directory, uid, directory.st_gid))
        if catalog.st_uid == 0:
            plans.append((str(CATALOG), file_fd, catalog, uid, gid))
        if not plans:
            return None, []
        # Both reviewed objects are validated before any ownership change.
        journal_descriptors, journal_states = open_directories(journal_parent, root, uid)
        try:
            item = os.fstat(journal_descriptors[-1])
            # Privacy belongs to the new 0700 child, not its parent. Root-owned
            # 0750/0755 parents are safe when other users cannot modify entries.
            if item.st_uid != 0 or stat.S_IMODE(item.st_mode) & 0o022:
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_JOURNAL_PARENT_UNSAFE')
            candidate = Path(tempfile.mkdtemp(prefix='desktop-dashboard-owner-repair-',
                                            dir=journal_parent))
            child_fd = os.open(candidate.name,
                               os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                               dir_fd=journal_descriptors[-1])
            try:
                child = os.fstat(child_fd)
                named = candidate.lstat()
                if (child.st_uid != 0 or stat.S_IMODE(child.st_mode) != 0o700
                        or not stat.S_ISDIR(named.st_mode)
                        or (child.st_dev, child.st_ino) != (named.st_dev, named.st_ino)):
                    raise ReviewRequired('DESKTOP_OWNER_REPAIR_JOURNAL_DIRECTORY_UNSAFE')
                fresh, _ = open_directories(journal_parent, root, uid)
                try:
                    # Child creation changes parent timestamps and link count;
                    # its identity, ownership and permissions must remain fixed.
                    stable = lambda value: (value.st_dev, value.st_ino, value.st_mode,
                                            value.st_uid, value.st_gid)
                    if [stable(os.fstat(fd)) for fd in fresh[1:]] != [
                            value[:5] for value in journal_states]:
                        raise ReviewRequired('DESKTOP_OWNER_REPAIR_JOURNAL_PARENT_CHANGED')
                finally:
                    for fd in reversed(fresh):
                        os.close(fd)
                # No metadata records may be written before privacy is verified.
                journal = candidate
            finally:
                os.close(child_fd)
        finally:
            for fd in reversed(journal_descriptors):
                os.close(fd)
        records = [{'path': name, 'before_uid': original.st_uid,
                    'before_gid': original.st_gid,
                    'mode': format(stat.S_IMODE(original.st_mode), '04o'),
                    'device': original.st_dev, 'inode': original.st_ino,
                    'after_uid': new_uid, 'after_gid': new_gid}
                   for name, _, original, new_uid, new_gid in plans]
        write_record(journal / 'before.json', {
            'release': RELEASE, 'catalog_blob': CATALOG_BLOB, 'objects': records,
            'utc': datetime.datetime.now(datetime.timezone.utc).isoformat()})
        if before_change:
            before_change()
        fresh, current_states = open_directories(path, root, uid, reviewed=path)
        try:
            if current_states != states or identity(catalog_state(file_fd)) != identity(catalog):
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_CONCURRENT_CHANGE')
            named = os.stat('Catalog.php', dir_fd=fresh[-1], follow_symlinks=False)
            if identity(named) != identity(catalog):
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_CONCURRENT_CHANGE')
        finally:
            for fd in reversed(fresh):
                os.close(fd)
        for name, fd, original, new_uid, new_gid in plans:
            if identity(os.fstat(fd)) != identity(original):
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_CONCURRENT_CHANGE')
            # A pending termination is delivered after the recovery state exists.
            # If a syscall/state capture itself fails, the journal marks recovery
            # uncertain rather than claiming an unrecorded change was restored.
            with blocked_signals():
                attempted.append(fd)
                os.fchown(fd, new_uid, new_gid)
                after = os.fstat(fd)
                changed.append((fd, original, after))
            if (after.st_dev, after.st_ino, after.st_mode, after.st_uid, after.st_gid,
                after.st_nlink, after.st_size, after.st_mtime_ns) != (
                original.st_dev, original.st_ino, original.st_mode, new_uid, new_gid,
                original.st_nlink, original.st_size, original.st_mtime_ns):
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_CONCURRENT_CHANGE')
        if after_change:
            after_change()
        fresh, current_states = open_directories(path, root, uid)
        try:
            expected_directory = next((after for fd, _, after in changed
                                       if fd == descriptors[-1]), directory)
            expected_catalog = next((after for fd, _, after in changed
                                     if fd == file_fd), catalog)
            if (current_states[:-1] != states[:-1]
                    or identity(os.fstat(fresh[-1])) != identity(expected_directory)
                    or identity(catalog_state(file_fd)) != identity(expected_catalog)
                    or identity(os.stat('Catalog.php', dir_fd=fresh[-1],
                                        follow_symlinks=False)) != identity(expected_catalog)):
                raise ReviewRequired('DESKTOP_OWNER_REPAIR_CONCURRENT_CHANGE')
        finally:
            for fd in reversed(fresh):
                os.close(fd)
        write_record(journal / 'after.json', {'objects': records, 'verified': True})
        return journal, records
    except BaseException as error:
        recovered = len(changed) == len(attempted)
        for fd, original, expected in reversed(changed):
            try:
                if identity(os.fstat(fd)) != identity(expected):
                    recovered = False
                    continue
                with blocked_signals():
                    os.fchown(fd, original.st_uid, original.st_gid)
                current = os.fstat(fd)
                if (current.st_uid, current.st_gid) != (original.st_uid, original.st_gid):
                    recovered = False
            except OSError:
                recovered = False
        if journal is not None:
            write_record(journal / 'stopped.json', {
                'owner_changes_restored_or_not_started': recovered})
        code = str(error) if isinstance(error, ReviewRequired) else 'DESKTOP_OWNER_REPAIR_STOPPED'
        raise ReviewRequired(code + ('; JOURNAL=' + str(journal) if journal else '')) from None
    finally:
        if file_fd is not None:
            os.close(file_fd)
        for fd in reversed(descriptors):
            os.close(fd)


def inventory(root, uid, groups, manifest):
    required = {row['path'] for row in manifest['sources']}
    # Laravel 8.83.29 Compiler::getCompiledPath uses sha1('v2'.$path).
    # https://github.com/laravel/framework/blob/v8.83.29/src/Illuminate/View/Compilers/Compiler.php
    # Check only the views this pinned installer will publish, leaving unrelated
    # compiled views outside the repair and blocker scope.
    for name in tuple(required):
        if name.endswith('.blade.php'):
            key = hashlib.sha1(('v2' + str(root / name)).encode()).hexdigest()
            required.add('storage/framework/views/' + key + '.php')
    required.update(['.env', 'bootstrap/cache/config.php', 'bootstrap/cache/routes.php',
                     'bootstrap/cache/routes-v7.php', 'storage/framework/views'])
    directories = {'.', 'storage/framework/views'}
    for name in tuple(required):
        directories.update(str(parent) for parent in Path(name).parents)
    required.update(directories)
    blocked = []
    unsafe = []
    for name in sorted(required, key=lambda value: (len(Path(value).parts), value)):
        relative = Path(name)
        if relative.is_absolute() or '..' in relative.parts:
            raise ReviewRequired('DESKTOP_OWNER_INVENTORY_UNSAFE_PATH')
        if any(parent == relative or parent in relative.parents for parent in unsafe):
            continue
        try:
            value = (root / relative).lstat()
        except FileNotFoundError:
            if name == '.env':
                blocked.append({'path': name, 'issues': ['required_file_missing']})
            continue
        kind = 'directory' if stat.S_ISDIR(value.st_mode) else 'file' if stat.S_ISREG(value.st_mode) else 'unsafe'
        issues = []
        if kind != ('directory' if name in directories else 'file'):
            issues.append('unsafe_path_type')
            unsafe.append(relative)
        if value.st_uid != uid:
            issues.append('owner_differs')
        mode = stat.S_IMODE(value.st_mode)
        if kind == 'file':
            if value.st_gid not in groups:
                issues.append('file_group_cannot_be_preserved')
            if mode & 0o022:
                issues.append('file_writable_by_other_user')
            if value.st_nlink != 1:
                issues.append('file_multiple_links_need_review')
            if not mode & 0o400:
                issues.append('file_owner_cannot_read')
        elif kind == 'directory' and mode & 0o300 != 0o300:
            issues.append('directory_owner_cannot_write_or_traverse')
        if issues:
            blocked.append({'path': name, 'uid': value.st_uid, 'gid': value.st_gid,
                            'mode': format(mode, '04o'), 'type': kind, 'issues': issues})
    return blocked


def main():
    descriptors = []
    lock_fd = None
    try:
        if os.geteuid() != 0 or len(sys.argv) != 1:
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_REQUIRES_ROOT_NO_ARGUMENTS')
        os.umask(0o077)
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(
            ReviewRequired('DESKTOP_OWNER_REPAIR_INTERRUPTED')))
        root = Path('/home/fasakha/public_html')
        account = pwd.getpwnam('fasakha')
        uid, gid = account.pw_uid, account.pw_gid
        groups = set(os.getgrouplist(account.pw_name, gid))
        parent = root.parent / 'desktop-dashboard-release-backups'
        descriptors, _ = open_directories(parent, root, uid)
        item = os.fstat(descriptors[-1])
        if item.st_uid != uid or stat.S_IMODE(item.st_mode) != 0o700:
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_INSTALL_LOCK_PARENT_UNSAFE')
        lock_fd = os.open('server-install.lock', os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK,
                          dir_fd=descriptors[-1])
        item = os.fstat(lock_fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_nlink != 1
                or item.st_uid != uid or stat.S_IMODE(item.st_mode) != 0o600):
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_INSTALL_LOCK_UNSAFE')
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ReviewRequired('DESKTOP_INSTALL_ALREADY_RUNNING') from None
        result = subprocess.run([
            '/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/git', '-C', str(root),
            'show', RELEASE + ':' + MANIFEST], stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, check=True, timeout=20,
            env={**os.environ, 'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1'})
        if len(result.stdout) > 1024 * 1024 or blob(result.stdout) != MANIFEST_BLOB:
            raise ReviewRequired('DESKTOP_OWNER_REPAIR_MANIFEST_PIN_INVALID')
        manifest = json.loads(result.stdout)
        journal, records = repair(root, uid, gid, groups, Path('/root'))
        print(json.dumps({'kind': 'desktop-scoped-owner-repair', 'release': RELEASE,
                          'changed': records, 'journal': str(journal) if journal else None,
                          'application_content_changed': False, 'database_read': False},
                         separators=(',', ':')))
        blocked = inventory(root, uid, groups, manifest)
        print(json.dumps({'kind': 'desktop-required-owner-inventory', 'blockers': blocked,
                          'repairs_outside_gostores': 0, 'environment_content_read': False},
                         separators=(',', ':')))
        if blocked:
            raise ReviewRequired('DESKTOP_OTHER_PUBLICATION_OWNERS_REQUIRE_REVIEW')
        print('DESKTOP_OWNER_REPAIR_READY_FOR_PINNED_INSTALL')
        return 0
    except BaseException as error:
        print(str(error) if isinstance(error, ReviewRequired) else
              'DESKTOP_OWNER_REPAIR_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        return 1
    finally:
        # Release the shared lock before the caller starts the immutable installer.
        if lock_fd is not None:
            os.close(lock_fd)
        for fd in reversed(descriptors):
            os.close(fd)


if __name__ == '__main__':
    sys.exit(main())
