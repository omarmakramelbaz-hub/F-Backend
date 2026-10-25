#!/usr/bin/env python3
"""Exact two-file source exchange before a source-bound desktop candidate."""
import base64
from contextlib import ExitStack
import hashlib
import json
import os
from pathlib import Path
import signal
import stat

PATHS = ('config/settings.php', 'desktop/bootstrap.php')
MAX_FILE = 1024 * 1024


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def stable(info):
    # renameat2 can change ctime. Reads use O_NOATIME; mtime remains bound.
    return [info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns]


def directory_key(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid)


class Directory:
    def __init__(self, path, web_uid, private=False):
        self.path = Path(path); self.rows = []
        need(self.path.is_absolute() and '..' not in self.path.parts, 'SOURCE_DIRECTORY_UNSAFE')
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.rows.append((fd, os.fstat(fd), None, None))
        try:
            for index, name in enumerate(self.path.parts[1:]):
                parent = fd
                fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                info = os.fstat(fd); self.rows.append((fd, info, parent, name))
                sticky = index < len(self.path.parts) - 2 and info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
                need(stat.S_ISDIR(info.st_mode) and info.st_uid in ((0,) if private else (0, web_uid))
                     and (not info.st_mode & (0o022 if private else 0o002) or sticky), 'SOURCE_DIRECTORY_UNSAFE')
            self.fd = fd
            if private:
                info = os.fstat(fd)
                need(info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700, 'SOURCE_AUDIT_UNSAFE')
            self.verify()
        except BaseException:
            self.close(); raise

    def verify(self):
        for fd, before, parent, name in self.rows:
            need(directory_key(os.fstat(fd)) == directory_key(before), 'SOURCE_DIRECTORY_CHANGED')
            if parent is not None:
                need(directory_key(os.stat(name, dir_fd=parent, follow_symlinks=False))
                     == directory_key(before), 'SOURCE_DIRECTORY_CHANGED')

    def close(self):
        for fd, *_ in reversed(self.rows):
            os.close(fd)
        self.rows = []

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.close()


def read(directory, name, web_uid, private=False):
    directory.verify()
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_NOATIME, dir_fd=directory.fd)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
             and before.st_uid in (0, web_uid) and not before.st_mode & 0o002
             and not stat.S_IMODE(before.st_mode) & 0o7000
             and 0 < before.st_size <= MAX_FILE, 'SOURCE_FILE_UNSAFE')
        if private:
            need((before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode)) == (0, 0, 0o600), 'SOURCE_JOURNAL_UNSAFE')
        chunks = []; size = 0
        while size <= MAX_FILE:
            block = os.read(fd, min(65536, MAX_FILE + 1 - size))
            if not block:
                break
            chunks.append(block); size += len(block)
        attrs = {name: base64.b64encode(os.getxattr(fd, name)).decode()
                 for name in sorted(os.listxattr(fd))}
        after = os.fstat(fd)
        need(stable(before) == stable(after) and before.st_ctime_ns == after.st_ctime_ns
             and stable(before) == stable(os.stat(name, dir_fd=directory.fd, follow_symlinks=False))
             and before.st_ctime_ns == os.stat(name, dir_fd=directory.fd, follow_symlinks=False).st_ctime_ns
             and size == before.st_size, 'SOURCE_FILE_CHANGED')
        directory.verify(); raw = b''.join(chunks)
        return raw, {'identity': stable(before), 'sha256': sha(raw), 'xattrs': attrs}, before
    finally:
        os.close(fd)


def write(directory, name, raw, metadata=None, attrs=None):
    directory.verify()
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory.fd)
    try:
        offset = 0
        while offset < len(raw):
            count = os.write(fd, raw[offset:]); need(count > 0, 'SOURCE_WRITE_FAILED'); offset += count
        if metadata is not None:
            os.fchown(fd, metadata.st_uid, metadata.st_gid)
            os.fchmod(fd, stat.S_IMODE(metadata.st_mode))
            for key, value in (attrs or {}).items():
                os.setxattr(fd, key, base64.b64decode(value, validate=True))
            os.utime(fd, ns=(metadata.st_atime_ns, metadata.st_mtime_ns))
        os.fsync(fd)
    finally:
        os.close(fd)
    os.fsync(directory.fd); directory.verify()


class Transaction:
    def __init__(self, root, audit, web_uid, entries, binding, exchange):
        need(tuple(entries) == PATHS and all(set(value) == {'before', 'after', 'mode'}
             and isinstance(value['before'], bytes) and isinstance(value['after'], bytes)
             and value['before'] != value['after'] and 0 < len(value['after']) <= MAX_FILE
             and value['mode'] == ('100755' if path == PATHS[0] else '100644')
             for path, value in entries.items()), 'SOURCE_SCOPE_INVALID')
        self.root, self.audit, self.web_uid = Path(root), Path(audit), web_uid
        self.entries, self.binding, self.exchange = entries, binding, exchange

    def _expected(self):
        return {'format': 1, 'kind': 'go-erp-startup-session-source', 'binding': self.binding,
                'paths': {path: {'before_sha256': sha(row['before']), 'after_sha256': sha(row['after']),
                                'mode': row['mode']} for path, row in self.entries.items()}}

    def _checked_intent(self, value):
        expected = self._expected()
        need(isinstance(value, dict) and set(value) == set(expected) | {'records'}
             and all(value.get(key) == item for key, item in expected.items())
             and isinstance(value['records'], dict) and set(value['records']) == set(PATHS), 'SOURCE_INTENT_CHANGED')
        for path, record in value['records'].items():
            need(isinstance(record, dict) and set(record) == {'before', 'after'}, 'SOURCE_INTENT_CHANGED')
            for label in ('before', 'after'):
                row = record[label]
                need(isinstance(row, dict) and set(row) == {'identity', 'sha256', 'xattrs'}
                     and row['sha256'] == sha(self.entries[path][label])
                     and isinstance(row['identity'], list) and len(row['identity']) == 8
                     and all(type(item) is int for item in row['identity'])
                     and row['identity'][5] == 1 and row['identity'][6] == len(self.entries[path][label])
                     and isinstance(row['xattrs'], dict), 'SOURCE_INTENT_CHANGED')
            need(record['before']['identity'][2:6] == record['after']['identity'][2:6]
                 and record['before']['identity'][7] == record['after']['identity'][7]
                 and record['before']['xattrs'] == record['after']['xattrs'], 'SOURCE_INTENT_CHANGED')
        return value

    def _open(self, stack):
        audit = stack.enter_context(Directory(self.audit, self.web_uid, private=True))
        parents = {path: stack.enter_context(Directory((self.root / path).parent, self.web_uid)) for path in PATHS}
        need(len({os.fstat(item.fd).st_dev for item in (audit, *parents.values())}) == 1,
             'SOURCE_FILESYSTEM_MISMATCH')
        return audit, parents

    def _intent(self, audit, parents, syntax):
        expected = self._expected()
        try:
            raw, _, _ = read(audit, 'intent.json', self.web_uid, private=True)
        except FileNotFoundError:
            records = {}
            for index, path in enumerate(PATHS):
                raw, before, metadata = read(parents[path], Path(path).name, self.web_uid)
                need(raw == self.entries[path]['before']
                     and ('100755' if metadata.st_mode & 0o111 else '100644') == self.entries[path]['mode'],
                     'SOURCE_BEFORE_CHANGED')
                staged = 'exchange-' + str(index)
                try:
                    new_raw, after, _ = read(audit, staged, self.web_uid)
                except FileNotFoundError:
                    write(audit, staged, self.entries[path]['after'], metadata, before['xattrs'])
                    new_raw, after, _ = read(audit, staged, self.web_uid)
                need(new_raw == self.entries[path]['after'] and after['identity'][2:6] == before['identity'][2:6]
                     and after['identity'][7] == before['identity'][7] and after['xattrs'] == before['xattrs'],
                     'SOURCE_STAGED_CHANGED')
                syntax(audit.path / staged)
                records[path] = {'before': before, 'after': after}
            intent = {**expected, 'records': records}
            # Both source/stage identities are checked again before durable intent.
            for index, path in enumerate(PATHS):
                need(read(parents[path], Path(path).name, self.web_uid)[1] == records[path]['before']
                     and read(audit, 'exchange-' + str(index), self.web_uid)[1] == records[path]['after'],
                     'SOURCE_PLAN_CHANGED')
            write(audit, 'intent.json', canonical(intent))
            return intent
        return self._checked_intent(json.loads(raw))

    def _states(self, audit, parents, intent):
        result = {}
        for index, path in enumerate(PATHS):
            active = read(parents[path], Path(path).name, self.web_uid)[1]
            held = read(audit, 'exchange-' + str(index), self.web_uid)[1]
            record = intent['records'][path]
            need(set(record) == {'before', 'after'}, 'SOURCE_INTENT_CHANGED')
            if active == record['before'] and held == record['after']:
                result[path] = 'before'
            elif active == record['after'] and held == record['before']:
                result[path] = 'after'
            else:
                raise Stop('SOURCE_RECOVERY_REQUIRES_REVIEW')
        return result

    def _exchange(self, audit, parent, index, path):
        audit.verify(); parent.verify()
        self.exchange(audit.fd, 'exchange-' + str(index), parent.fd, Path(path).name)
        os.fsync(audit.fd); os.fsync(parent.fd)
        audit.verify(); parent.verify()

    def apply(self, validate, syntax=lambda path: None, event=lambda phase, path: None):
        with ExitStack() as stack:
            audit, parents = self._open(stack)
            intent = self._intent(audit, parents, syntax)
            states = self._states(audit, parents, intent)
            previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM, signal.SIGHUP})
            try:
                try:
                    for index, path in enumerate(PATHS):
                        if states[path] == 'before':
                            event('before-exchange', path)
                            need(self._states(audit, parents, intent) == states, 'SOURCE_CHANGED_BEFORE_EXCHANGE')
                            self._exchange(audit, parents[path], index, path)
                            states[path] = 'after'
                            need(self._states(audit, parents, intent) == states, 'SOURCE_CHANGED_AFTER_EXCHANGE')
                            event('after-exchange', path)
                    validate()
                    need(all(value == 'after' for value in self._states(audit, parents, intent).values()),
                         'SOURCE_POSTFLIGHT_CHANGED')
                    return {'paths': list(PATHS), 'intent_sha256': sha(canonical(intent)), 'applied': True}
                except BaseException:
                    # Classify both sides of both files before any inverse exchange.
                    current = self._states(audit, parents, intent)
                    for index, path in reversed(list(enumerate(PATHS))):
                        if current[path] == 'after':
                            self._exchange(audit, parents[path], index, path)
                            current[path] = 'before'
                            need(self._states(audit, parents, intent) == current, 'SOURCE_ROLLBACK_CHANGED')
                    raise
            finally:
                signal.pthread_sigmask(signal.SIG_SETMASK, previous)

    def verify(self, validate):
        """After native preparation starts, verification never reverses source."""
        with ExitStack() as stack:
            audit, parents = self._open(stack)
            raw, _, _ = read(audit, 'intent.json', self.web_uid, private=True)
            intent = self._checked_intent(json.loads(raw))
            need(all(value == 'after' for value in self._states(audit, parents, intent).values()),
                 'SOURCE_NOT_APPLIED')
            validate()
            need(all(value == 'after' for value in self._states(audit, parents, intent).values()),
                 'SOURCE_POSTFLIGHT_CHANGED')
            return {'paths': list(PATHS), 'intent_sha256': sha(canonical(intent)), 'applied': True}
