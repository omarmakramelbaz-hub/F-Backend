#!/usr/bin/env python3
"""Change only four confirmed root-owned configuration files to fasakha."""
import argparse
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import secrets
import signal
import stat
import sys


APP_ROOT = pathlib.Path('/home/fasakha/public_html')
FILES = ('go_services.php', 'jwt.php', 'mail.php', 'partner_auth.php')
LOCKS = ('orders-install.lock', 'inbox-install.lock')
MAX_BYTES = 8 * 1024 * 1024


class SafeError(Exception):
    def __init__(self, code, backup=None):
        super().__init__(code)
        self.backup = backup


def _fstat(fd):
    return os.fstat(fd)


def _lstat(path):
    return os.lstat(path)


def _stat_at(name, fd):
    return os.stat(name, dir_fd=fd, follow_symlinks=False)


def _identity(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid,
            stat.S_IMODE(info.st_mode), stat.S_IFMT(info.st_mode))


def _state(info):
    return _identity(info) + (info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _read_snapshot(fd, uid, gid):
    before = _fstat(fd)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or stat.S_IMODE(before.st_mode) != 0o644 or before.st_size > MAX_BYTES
            or (before.st_uid, before.st_gid) not in ((0, 0), (uid, gid))):
        raise SafeError('CONFIG_OWNER_FILE_PROFILE_NEEDS_REVIEW')
    os.lseek(fd, 0, os.SEEK_SET)
    digest, size = hashlib.sha256(), 0
    while True:
        chunk = os.read(fd, min(65536, MAX_BYTES + 1 - size))
        if not chunk:
            break
        size += len(chunk)
        if size > MAX_BYTES:
            raise SafeError('CONFIG_OWNER_FILE_TOO_LARGE')
        digest.update(chunk)
    after = _fstat(fd)
    if _state(before) != _state(after) or size != before.st_size:
        raise SafeError('CONFIG_OWNER_FILE_CHANGED_DURING_READ')
    return _state(after), digest.hexdigest()


def _journal(fd, value):
    data = (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
    offset = 0
    while offset < len(data):
        count = os.write(fd, data[offset:])
        if count < 1:
            raise SafeError('CONFIG_OWNER_JOURNAL_WRITE_FAILED')
        offset += count
    os.fsync(fd)


def repair(root, uid, gid, chown=os.fchown, hook=None):
    """Tests inject an inert chown implementation; production runs only as root."""
    root = pathlib.Path(root)
    if not root.is_absolute() or '..' in root.parts or uid <= 0 or gid <= 0:
        raise SafeError('CONFIG_OWNER_ROOT_OR_ACCOUNT_INVALID')
    directories, descriptors, file_fds, expected, originals = [], [], {}, {}, {}
    held_locks, attempted = [], []
    backup, journal = None, None

    def directory(path, exact_owner=None, exact_mode=None):
        # Walk from / using dir_fd and O_NOFOLLOW; parent symlinks cannot be
        # substituted between a metadata check and the next directory open.
        parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        descriptors.append(parent)
        current = pathlib.Path('/')
        for part in path.parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            descriptors.append(child)
            current = current/part
            info = _fstat(child)
            owners = (uid,) if current == root or root in current.parents else (0, uid)
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid not in owners
                    or _identity(_lstat(current)) != _identity(info)):
                raise SafeError('CONFIG_OWNER_DIRECTORY_PROFILE_NEEDS_REVIEW')
            directories.append((current, child, _identity(info)))
            parent = child
        info = _fstat(parent)
        if ((exact_owner is not None and info.st_uid != exact_owner)
                or (exact_mode is not None and stat.S_IMODE(info.st_mode) != exact_mode)):
            raise SafeError('CONFIG_OWNER_DIRECTORY_PROFILE_NEEDS_REVIEW')
        return parent

    def assert_directories():
        for path, fd, before in directories:
            if _identity(_fstat(fd)) != before or _identity(_lstat(path)) != before:
                raise SafeError('CONFIG_OWNER_DIRECTORY_CHANGED_CONCURRENTLY')
        for name, fd, before in held_locks:
            if _state(_fstat(fd)) != before or _state(_stat_at(name, parent_fd)) != before:
                raise SafeError('CONFIG_OWNER_LOCK_CHANGED_CONCURRENTLY')

    def assert_file(name, snapshot):
        if _state(_stat_at(name, config_fd)) != snapshot[0]:
            raise SafeError('CONFIG_OWNER_FILE_PATH_CHANGED_CONCURRENTLY')
        if _read_snapshot(file_fds[name], uid, gid) != snapshot:
            raise SafeError('CONFIG_OWNER_FILE_CHANGED_CONCURRENTLY')

    def assert_all():
        assert_directories()
        for name, snapshot in expected.items():
            assert_file(name, snapshot)

    try:
        config_fd = directory(root/'config', exact_owner=uid)
        if _fstat(config_fd).st_mode & 0o022:
            raise SafeError('CONFIG_OWNER_CONFIGURATION_DIRECTORY_WRITABLE')
        parent = root.parent/'whatsapp-release-backups'
        parent_fd = directory(parent, exact_owner=uid, exact_mode=0o700)
        for name in LOCKS:
            fd = os.open(name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent_fd)
            descriptors.append(fd)
            info = _fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1
                    or stat.S_IMODE(info.st_mode) != 0o600
                    or _state(_stat_at(name, parent_fd)) != _state(info)):
                raise SafeError('CONFIG_OWNER_LOCK_PROFILE_NEEDS_REVIEW')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise SafeError('CONFIG_OWNER_ANOTHER_INSTALL_RUNNING') from None
            held_locks.append((name, fd, _state(info)))
        # Validate all four exact paths before creating a journal or changing any owner.
        for name in FILES:
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=config_fd)
            descriptors.append(fd)
            file_fds[name] = fd
            originals[name] = expected[name] = _read_snapshot(fd, uid, gid)
            assert_file(name, expected[name])
        assert_all()
        pending = [name for name in FILES if expected[name][0][2:4] != (uid, gid)]
        if not pending:
            return {'status': 'WHATSAPP_ORDER_CONFIG_OWNERS_ALREADY_READY', 'changed': 0, 'backup': None}
        backup_name = 'config-owners-' + secrets.token_hex(8)
        os.mkdir(backup_name, 0o700, dir_fd=parent_fd)
        backup = parent/backup_name
        backup_fd = os.open(backup_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent_fd)
        descriptors.append(backup_fd)
        info = _fstat(backup_fd)
        if (info.st_uid != 0 or not stat.S_ISDIR(info.st_mode)
                or stat.S_IMODE(info.st_mode) != 0o700
                or _identity(_lstat(backup)) != _identity(info)):
            raise SafeError('CONFIG_OWNER_BACKUP_PROFILE_NEEDS_REVIEW')
        directories.append((backup, backup_fd, _identity(info)))
        journal = os.open('journal.jsonl', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                          0o600, dir_fd=backup_fd)
        descriptors.append(journal)
        info = _fstat(journal)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
                or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600):
            raise SafeError('CONFIG_OWNER_JOURNAL_PROFILE_NEEDS_REVIEW')
        _journal(journal, {'schema': 'whatsapp-config-owners-v1', 'target_uid': uid, 'target_gid': gid,
                          'files': [{'path': 'config/'+name, 'original_metadata': originals[name][0],
                                     'sha256': originals[name][1]} for name in FILES]})
        os.fsync(backup_fd)
        os.fsync(parent_fd)
        for name in pending:
            if hook is not None:
                hook('before', name)
            assert_all()
            _journal(journal, {'operation': 'intent', 'path': 'config/'+name})
            attempted.append(name)
            chown(file_fds[name], uid, gid)
            os.fsync(file_fds[name])
            after = _read_snapshot(file_fds[name], uid, gid)
            before = originals[name]
            # Only owner/group and ctime may change; contents, inode, mode, link
            # count, size and mtime must remain identical.
            if (after[0][:2] != before[0][:2] or after[0][2:4] != (uid, gid)
                    or after[0][4:9] != before[0][4:9] or after[1] != before[1]):
                raise SafeError('CONFIG_OWNER_POST_CHANGE_VERIFICATION_FAILED')
            expected[name] = after
            assert_all()
            _journal(journal, {'operation': 'complete', 'path': 'config/'+name})
        assert_all()
        _journal(journal, {'operation': 'ready', 'changed': len(pending)})
        return {'status': 'WHATSAPP_ORDER_CONFIG_OWNERS_READY', 'changed': len(pending), 'backup': str(backup)}
    except BaseException as error:
        if not attempted:
            if isinstance(error, SafeError):
                raise SafeError(str(error), str(backup) if backup is not None else None) from None
            raise SafeError('CONFIG_OWNER_REPAIR_STOPPED_BEFORE_CHANGES', str(backup) if backup is not None else None) from None
        restored = True
        mask = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
        try:
            for name in reversed(attempted):
                try:
                    assert_directories()
                    now = _read_snapshot(file_fds[name], uid, gid)
                    before = originals[name]
                    if (_state(_stat_at(name, config_fd)) != now[0] or now[0][:2] != before[0][:2]
                            or now[0][4:9] != before[0][4:9] or now[1] != before[1]):
                        raise SafeError('CONFIG_OWNER_ROLLBACK_CONCURRENT_CHANGE')
                    if now[0][2:4] != before[0][2:4]:
                        chown(file_fds[name], before[0][2], before[0][3])
                        os.fsync(file_fds[name])
                    after = _read_snapshot(file_fds[name], uid, gid)
                    if (after[0][:9] != before[0][:9] or after[1] != before[1]
                            or _state(_stat_at(name, config_fd)) != after[0]):
                        raise SafeError('CONFIG_OWNER_ROLLBACK_VERIFICATION_FAILED')
                except BaseException:
                    restored = False
            try:
                _journal(journal, {'operation': 'rollback', 'restored': restored})
            except BaseException:
                pass
        finally:
            signal.pthread_sigmask(signal.SIG_SETMASK, mask)
        raise SafeError('CONFIG_OWNER_REPAIR_FAILED_FILES_RESTORED' if restored else
                        'CONFIG_OWNER_REPAIR_PARTIAL_REVIEW_REQUIRED', str(backup)) from None
    finally:
        for fd in reversed(descriptors):
            os.close(fd)


class FixedArgumentParser(argparse.ArgumentParser):
    def error(self, message):
        raise SafeError('CONFIG_OWNER_ARGUMENTS_INVALID')


def main(argv=None):
    handlers = {}
    try:
        parser = FixedArgumentParser(add_help=False)
        parser.add_argument('--root', required=True)
        args = parser.parse_args(argv)
        root = pathlib.Path(args.root)
        if root != APP_ROOT or '..' in root.parts or os.geteuid() != 0:
            raise SafeError('CONFIG_OWNER_EXECUTION_CONTEXT_INVALID')
        def interrupted(signum, frame):
            raise KeyboardInterrupt()
        for signum in (signal.SIGINT, signal.SIGTERM):
            handlers[signum] = signal.signal(signum, interrupted)
        user = pwd.getpwnam('fasakha')
        result = repair(root, user.pw_uid, user.pw_gid)
        print(json.dumps(result, sort_keys=True))
        return 0
    except BaseException as error:
        result = {'status': 'FAILED', 'reason': str(error) if isinstance(error, SafeError)
                  else 'CONFIG_OWNER_REPAIR_STOPPED_WITHOUT_SECRET_OUTPUT'}
        if isinstance(error, SafeError) and error.backup is not None:
            result['backup'] = error.backup
        print(json.dumps(result, sort_keys=True))
        return 1
    finally:
        for signum, handler in handlers.items():
            signal.signal(signum, handler)


if __name__ == '__main__':
    sys.exit(main())
