#!/usr/bin/env python3
"""Manifest-bound source transaction, durable resume and inode-owned rollback.

No standalone apply CLI, SQL, feature activation, allocation or publication.
The pinned coordinator must supply its reviewed manifest/payloads while holding
the original install, manual-worker and watcher locks. Final release pins remain
unavailable; this reusable engine never treats caller data as release approval.
"""
import ctypes
import errno
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import time

POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
FINAL_RELEASE_PINS = None
MAX_FILE = 2 * 1024**2
MAX_RECORD = 2 * 1024**2
MAX_TOTAL = 64 * 1024**2
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
MIGRATIONS = frozenset((
    'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php',
    'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php'))


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_SOURCE_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def parse(raw):
    def pairs(items):
        value = {}
        for key, item in items:
            need(key not in value, 'DUPLICATE_RECORD_KEY')
            value[key] = item
        return value
    def nonfinite(_):
        raise Stop('GO_ERP_SHARED_WORKSPACE_SOURCE_NONFINITE_RECORD')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=nonfinite)
    need(isinstance(value, dict), 'RECORD_OBJECT_REQUIRED')
    return value


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
        info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def inode(info):
    return [info.st_dev, info.st_ino]


def relative(name):
    need(isinstance(name, str) and 0 < len(name) <= 300
        and all(32 < ord(char) < 127 for char in name) and '\\' not in name and ':' not in name
        and not name.startswith('/') and str(PurePosixPath(name)) == name
        and all(part not in ('.', '..') and not part.startswith('.') for part in name.split('/')),
        'RELATIVE_PATH_INVALID')
    return name


def source_name(name):
    relative(name)
    need(name.startswith(('app/', 'config/', 'desktop/', 'public/', 'resources/', 'routes/'))
        or name in ('artisan', 'bootstrap/app.php') or name in MIGRATIONS,
        'SOURCE_SCOPE_INVALID')
    return name


def parent_fd(path):
    need(path.is_absolute() and '..' not in path.parts, 'ABSOLUTE_PATH_REQUIRED')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in path.parent.parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise


def directory(path, uid, gid, *, private=False, cache_root=None):
    """Only the exact storage/framework/views chain may use app-owned 0775."""
    need(path.is_absolute() and '..' not in path.parts and path.resolve() == path,
        'DIRECTORY_PATH_INVALID')
    pairs = {(0, 0), (uid, gid)}
    caches = set() if cache_root is None else {cache_root / 'storage',
        cache_root / 'storage/framework', cache_root / 'storage/framework/views'}
    for current in (*reversed(path.parents), path):
        info = current.lstat()
        mode = stat.S_IMODE(info.st_mode)
        sticky_parent = current != path and mode == 0o1777 and (info.st_uid, info.st_gid) == (0, 0)
        writable_cache = current in caches and (info.st_uid, info.st_gid, mode) == (uid, gid, 0o775)
        need(stat.S_ISDIR(info.st_mode) and (info.st_uid, info.st_gid) in pairs
            and (sticky_parent or not mode & 0o022 or writable_cache)
            and (sticky_parent or not mode & 0o7000), 'DIRECTORY_UNSAFE')
    info = path.lstat()
    if private:
        need((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) == (0, 0, 0o700),
            'PRIVATE_DIRECTORY_UNSAFE')
    return info


def read(path, uid, gid, *, optional=False, private=False, limit=MAX_FILE, cache_root=None):
    directory(path.parent, uid, gid, private=private, cache_root=cache_root)
    parent = parent_fd(path)
    try:
        try:
            before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            need(optional, 'FILE_MISSING')
            return None
        modes = (0o600,) if private else (0o640, 0o644)
        cache_file = cache_root is not None and path.parent == cache_root / 'storage/framework/views'
        if cache_file:
            modes += (0o664,)
        pairs = {(0, 0)} if private else {(0, 0), (uid, gid)}
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
            and (before.st_uid, before.st_gid) in pairs and stat.S_IMODE(before.st_mode) in modes
            and (stat.S_IMODE(before.st_mode) != 0o664 or (before.st_uid, before.st_gid) == (uid, gid))
            and 0 <= before.st_size <= limit, 'FILE_UNSAFE')
        fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            need(identity(before) == identity(os.fstat(fd)), 'FILE_CHANGED')
            parts, total = [], 0
            while total <= limit:
                chunk = os.read(fd, min(65536, limit + 1 - total))
                if not chunk:
                    break
                parts.append(chunk)
                total += len(chunk)
            need(total == before.st_size and identity(before) == identity(os.fstat(fd))
                == identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)), 'FILE_CHANGED')
            return b''.join(parts), before
        finally:
            os.close(fd)
    finally:
        os.close(parent)


def rename_absent(parent, old, new):
    """Linux atomic no-replace rename; never overwrite an appeared added target."""
    libc = ctypes.CDLL(None, use_errno=True)
    function = getattr(libc, 'renameat2', None)
    need(function is not None, 'ATOMIC_NOREPLACE_UNAVAILABLE')
    function.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
    function.restype = ctypes.c_int
    if function(parent, os.fsencode(old), parent, os.fsencode(new), 1) != 0:
        number = ctypes.get_errno()
        need(number != errno.EEXIST, 'ABSENT_TARGET_APPEARED')
        raise Stop('GO_ERP_SHARED_WORKSPACE_SOURCE_ATOMIC_NOREPLACE_FAILED')


def same_parent(path, original):
    current = parent_fd(path)
    try:
        need(inode(os.fstat(current)) == inode(original), 'PARENT_CHANGED')
    finally:
        os.close(current)


def private_record(path, value, verify):
    raw = value if isinstance(value, bytes) else canonical(value)
    need(len(raw) <= MAX_RECORD, 'RECORD_LIMIT')
    verify()
    previous = read(path, 0, 0, optional=True, private=True, limit=MAX_RECORD)
    if previous is not None:
        need(previous[0] == raw, 'IMMUTABLE_RECORD_CHANGED')
        return
    parent = parent_fd(path)
    stage = '.record-' + os.urandom(16).hex()
    try:
        original = os.fstat(parent)
        fd = os.open(stage, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw)
            stream.flush()
            os.fchown(stream.fileno(), 0, 0)
            os.fchmod(stream.fileno(), 0o600)
            os.fsync(stream.fileno())
        verify()
        same_parent(path, original)
        rename_absent(parent, stage, path.name)
        os.fsync(parent)
    finally:
        try:
            os.unlink(stage, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


def load_record(path):
    result = read(path, 0, 0, optional=True, private=True, limit=MAX_RECORD)
    return None if result is None else parse(result[0])


def private_directory(path, verify):
    verify()
    directory(path.parent, 0, 0)
    try:
        path.mkdir(mode=0o700)
        os.chown(path, 0, 0)
        os.chmod(path, 0o700)
        parent = parent_fd(path)
        try:
            os.fsync(parent)
        finally:
            os.close(parent)
    except FileExistsError:
        pass
    directory(path, 0, 0, private=True)


def replacement_priority(name):
    """Dependencies before referrers; the final coordinator may pin a finer order."""
    for prefix, rank in (('config/', 0), ('app/Models/', 10), ('app/Services/', 20),
            ('app/Http/Controllers/', 30), ('app/Http/Middleware/', 40),
            ('app/Providers/', 50), ('app/Http/Kernel.php', 60), ('desktop/', 70),
            ('bootstrap/', 80), ('routes/', 90), ('resources/', 100), ('public/', 110)):
        if name.startswith(prefix):
            return rank, name
    return 15, name


def checked_plan(root, release, uid, gid, pins, payloads, new_directories, replacement_order=None):
    need(isinstance(release, str) and REV.fullmatch(release) and release != '0' * 40,
        'RELEASE_REQUIRED')
    need(type(uid) is int and uid >= 0 and type(gid) is int and gid >= 0, 'OWNER_INVALID')
    need(isinstance(pins, dict) and 0 < len(pins) <= 2000 and isinstance(payloads, dict)
        and set(payloads) == set(pins), 'MANIFEST_SCOPE_INVALID')
    total = 0
    for name, row in pins.items():
        source_name(name)
        need(isinstance(row, dict) and set(row) == {'before', 'after'}
            and (row['before'] is None or isinstance(row['before'], str) and SHA.fullmatch(row['before']))
            and isinstance(row['after'], str) and SHA.fullmatch(row['after']) and row['before'] != row['after']
            and isinstance(payloads[name], bytes) and 0 < len(payloads[name]) <= MAX_FILE
            and sha(payloads[name]) == row['after'], 'MANIFEST_PIN_INVALID')
        total += len(payloads[name])
        need(total <= MAX_TOTAL, 'MANIFEST_TOTAL_LIMIT')
        if name in MIGRATIONS:
            need(row['before'] is None, 'MIGRATION_MUST_BE_NEW')
    need(isinstance(new_directories, (list, tuple)) and len(set(new_directories)) == len(new_directories),
        'DIRECTORY_ROSTER_INVALID')
    for name in new_directories:
        relative(name)
        need(name not in pins and any(path.startswith(name + '/') for path in pins)
            and name.split('/')[0] in ('app', 'config', 'desktop', 'public', 'resources', 'routes', 'database'),
            'DIRECTORY_SCOPE_INVALID')
    replacements = {name for name, row in pins.items() if row['before'] is not None}
    if replacement_order is None:
        replacement_order = sorted(replacements, key=replacement_priority)
    need(isinstance(replacement_order, (list, tuple)) and len(replacement_order) == len(replacements)
        and set(replacement_order) == replacements, 'REPLACEMENT_ORDER_INVALID')
    positions = {name: index for index, name in enumerate(replacement_order)}
    controllers = [name for name in replacements if name.startswith('app/Http/Controllers/')]
    views = [name for name in replacements if name.startswith('resources/views/')]
    need(all(positions[controller] < positions[view] for controller in controllers for view in views),
        'CONTROLLERS_MUST_PRECEDE_VIEWS')
    return {'format': 1, 'kind': 'go-erp-shared-workspace-source-plan', 'policy': POLICY,
        'release': release, 'root': str(root), 'owner_uid': uid, 'owner_gid': gid,
        'paths': {name: dict(pins[name]) for name in sorted(pins)},
        'new_directories': sorted(new_directories, key=lambda name: (name.count('/'), name)),
        'addition_order': sorted(name for name, row in pins.items() if row['before'] is None),
        'replacement_order': list(replacement_order)}


def _missing_chain(root, relative_path, planned, uid, gid):
    current = root
    missing = False
    for part in PurePosixPath(relative_path).parts:
        current /= part
        name = current.relative_to(root).as_posix()
        if missing or not os.path.lexists(current):
            need(name in planned, 'UNPLANNED_MISSING_DIRECTORY')
            missing = True
        else:
            directory(current, uid, gid)
    return missing


def _facts(info):
    return {'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode)}


def _matches(info, facts):
    return _facts(info) == {key: facts[key] for key in ('uid', 'gid', 'mode')}


def invalidate_blade(root, names, audit, uid, gid, verify, *, check_only=False):
    """Invalidate only exact reviewed Blade paths and compiler marker matches."""
    for name in sorted(names):
        source_name(name)
        if not name.endswith('.blade.php'):
            continue
        key = hashlib.sha1(('v2' + str(root / name)).encode()).hexdigest()
        path = root / 'storage/framework/views' / (key + '.php')
        value = read(path, uid, gid, optional=True, cache_root=root)
        if value is None:
            continue
        raw, info = value
        marker = ('/**PATH ' + str(root / name) + ' ENDPATH**/').encode()
        need(raw.rstrip().endswith(marker) or raw.rstrip().endswith(marker + b' ?>'), 'COMPILED_MARKER_INVALID')
        private_record(audit / ('compiled-before-' + sha(name.encode()) + '-' + sha(raw) + '.bin'), raw, verify)
        if check_only:
            continue
        verify()
        parent = parent_fd(path)
        try:
            original = os.fstat(parent)
            same_parent(path, original)
            need(identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)) == identity(info),
                'COMPILED_CHANGED')
            os.unlink(path.name, dir_fd=parent)
            os.fsync(parent)
        finally:
            os.close(parent)


class Transaction:
    def __init__(self, root, audit_parent, release, uid, gid, payloads, pins,
            new_directories, verify_locks, checkpoint=None, *, new_attempt=True, replacement_order=None):
        need(callable(verify_locks) and (checkpoint is None or callable(checkpoint)), 'LOCK_VERIFIER_REQUIRED')
        self.uid, self.gid = uid, gid
        self.root, self.audit_parent = Path(root), Path(audit_parent)
        self.payloads, self.pins = payloads, pins
        self.checkpoint = checkpoint or (lambda _phase, _name: None)
        self.verify_locks = verify_locks
        self.plan = checked_plan(self.root, release, uid, gid, pins, payloads, new_directories, replacement_order)
        self.digest = sha(canonical(self.plan))
        self.audit = self.audit_parent / ('workspace-source-' + self.digest)
        self.verify()
        directory(self.root, uid, gid)
        directory(self.audit_parent, 0, 0, private=True)
        private_directory(self.audit, self.verify)
        self.intent = self._intent()
        self.attempt = self._attempt(new_attempt)

    def verify(self):
        need(self.verify_locks() is not False, 'HELD_LOCK_NOT_VERIFIED')

    def _intent(self):
        path = self.audit / 'intent.json'
        intent = load_record(path)
        if intent is None:
            for name in self.plan['new_directories']:
                need(not os.path.lexists(self.root / name), 'NEW_DIRECTORY_NOT_ABSENT')
                _missing_chain(self.root, name, self.plan['new_directories'], self.uid, self.gid)
            files = {}
            for name, row in self.pins.items():
                missing = _missing_chain(self.root, str(PurePosixPath(name).parent),
                    self.plan['new_directories'], self.uid, self.gid)
                value = None if missing else read(self.root / name, self.uid, self.gid, optional=True)
                if row['before'] is None:
                    need(value is None, 'ADDED_SOURCE_NOT_ABSENT')
                    files[name] = {'uid': self.uid, 'gid': self.gid, 'mode': 0o644, 'backup': None}
                else:
                    need(value is not None and sha(value[0]) == row['before'], 'BEFORE_SOURCE_CHANGED')
                    backup = 'before-' + sha(name.encode()) + '.bin'
                    private_record(self.audit / backup, value[0], self.verify)
                    files[name] = {**_facts(value[1]), 'backup': backup}
            intent = {'plan': self.plan, 'files': files}
            private_record(path, intent, self.verify)
            self.checkpoint('intent', '')
        need(set(intent) == {'plan', 'files'} and canonical(intent['plan']) == canonical(self.plan)
            and isinstance(intent['files'], dict) and set(intent['files']) == set(self.pins), 'INTENT_CHANGED')
        for name, facts in intent['files'].items():
            need(isinstance(facts, dict) and set(facts) == {'uid', 'gid', 'mode', 'backup'}
                and all(type(facts[key]) is int for key in ('uid', 'gid', 'mode'))
                and (facts['uid'], facts['gid']) in {(0, 0), (self.uid, self.gid)}
                and facts['mode'] in (0o640, 0o644), 'INTENT_METADATA_INVALID')
            if self.pins[name]['before'] is None:
                need(facts == {'uid': self.uid, 'gid': self.gid, 'mode': 0o644, 'backup': None},
                    'ADDED_SOURCE_METADATA_CHANGED')
            else:
                need(facts['backup'] == 'before-' + sha(name.encode()) + '.bin', 'BACKUP_PATH_CHANGED')
                backup = read(self.audit / facts['backup'], 0, 0, private=True)[0]
                need(sha(backup) == self.pins[name]['before'], 'BACKUP_CHANGED')
        return intent

    def _attempt(self, new_attempt):
        entries = []
        for entry in self.audit.iterdir():
            if entry.name.startswith('attempt-'):
                need(re.fullmatch(r'attempt-[0-9]{6}', entry.name), 'ATTEMPT_PATH_INVALID')
                directory(entry, 0, 0, private=True)
                entries.append(entry)
        entries.sort()
        need([int(path.name[8:]) for path in entries] == list(range(len(entries))), 'ATTEMPT_SEQUENCE_INVALID')
        attempt = entries[-1] if entries else self.audit / 'attempt-000000'
        rolled_back = load_record(attempt / 'rolled-back.json') if entries else None
        if rolled_back is not None:
            need(canonical(rolled_back) == canonical(self._receipt('rolled-back', attempt.name)),
                'ROLLED_BACK_RECORD_CHANGED')
        if new_attempt and rolled_back is not None:
            self._verify_before()
            need(len(entries) < 1000, 'ATTEMPT_LIMIT')
            attempt = self.audit / ('attempt-' + str(len(entries)).zfill(6))
        private_directory(attempt, self.verify)
        private_record(attempt / 'attempt.json', {'format': 1, 'plan_sha256': self.digest,
            'attempt': int(attempt.name[8:])}, self.verify)
        return attempt

    def _verify_before(self):
        for name, row in self.pins.items():
            missing = _missing_chain(self.root, str(PurePosixPath(name).parent),
                self.plan['new_directories'], self.uid, self.gid)
            value = None if missing else read(self.root / name, self.uid, self.gid, optional=True)
            need(value is None if row['before'] is None else value is not None
                and sha(value[0]) == row['before'] and _matches(value[1], self.intent['files'][name]),
                'ROLLED_BACK_SOURCE_CHANGED')
        need(all(not os.path.lexists(self.root / name) for name in self.plan['new_directories']),
            'ROLLED_BACK_DIRECTORY_CHANGED')

    def _wal_path(self, kind, name):
        return self.attempt / (kind + '-' + sha(name.encode()) + '.json')

    def _stage_name(self, kind, name):
        return '.go-erp-workspace-' + self.digest[:16] + '-' + self.attempt.name[8:] + '-' + kind + '-' + sha(name.encode())[:24]

    def _wal(self, kind, name):
        value = load_record(self._wal_path(kind, name))
        if value is not None:
            need(set(value) == {'format', 'plan_sha256', 'kind', 'name', 'stage', 'stage_inode', 'before_identity'}
                and type(value['format']) is int and value['format'] == 1 and value['plan_sha256'] == self.digest
                and value['kind'] == kind and value['name'] == name
                and value['stage'] == self._stage_name(kind, name)
                and isinstance(value['stage_inode'], list) and len(value['stage_inode']) == 2
                and all(type(item) is int and item >= 0 for item in value['stage_inode'])
                and (value['before_identity'] is None or isinstance(value['before_identity'], list)
                    and len(value['before_identity']) == 9 and all(type(item) is int for item in value['before_identity'])),
                'WRITE_AHEAD_RECORD_CHANGED')
        return value

    def _stage(self, kind, name, raw, facts, before):
        path = self.root / name
        directory(path.parent, self.uid, self.gid)
        parent = parent_fd(path)
        stage = self._stage_name(kind, name)
        try:
            original = os.fstat(parent)
            self.verify()
            need(not os.path.lexists(path.parent / stage), 'UNOWNED_STAGE_REVIEW_REQUIRED')
            if kind == 'directory':
                os.mkdir(stage, 0o700, dir_fd=parent)
                fd = os.open(stage, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                try:
                    os.fchown(fd, self.uid, self.gid)
                    os.fchmod(fd, 0o755)
                    os.fsync(fd)
                    staged = os.fstat(fd)
                finally:
                    os.close(fd)
            else:
                fd = os.open(stage, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(raw)
                    stream.flush()
                    os.fchown(stream.fileno(), facts['uid'], facts['gid'])
                    os.fchmod(stream.fileno(), facts['mode'])
                    if before is not None:
                        stamp = max(time.time_ns(), (before.st_mtime_ns // 1000000000 + 1) * 1000000000)
                        os.utime(stream.fileno(), ns=(stamp, stamp))
                    os.fsync(stream.fileno())
                    staged = os.fstat(stream.fileno())
            os.fsync(parent)
            self.checkpoint('staged', name)
            self.verify()
            same_parent(path, original)
            value = {'format': 1, 'plan_sha256': self.digest, 'kind': kind, 'name': name,
                'stage': stage, 'stage_inode': inode(staged),
                'before_identity': None if before is None else list(identity(before))}
            private_record(self._wal_path(kind, name), value, self.verify)
            self.checkpoint('write-ahead', name)
            return value
        finally:
            # Never unlink an unjournaled inode on exception. A crash between
            # create and ownership-WAL is conservatively retained for review.
            os.close(parent)

    def _publish(self, kind, name, value, raw=None, facts=None):
        path = self.root / name
        directory(path.parent, self.uid, self.gid)
        parent = parent_fd(path)
        try:
            original = os.fstat(parent)
            try:
                target = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                target = None
            if target is not None and inode(target) == value['stage_inode']:
                self._verify_target(kind, path, value, raw, facts)
                need(not os.path.lexists(path.parent / value['stage']), 'STAGE_REAPPEARED')
                return
            if value['before_identity'] is None:
                need(target is None, 'ABSENT_TARGET_APPEARED')
            else:
                need(target is not None and list(identity(target)) == value['before_identity'], 'TARGET_CHANGED')
            stage = path.parent / value['stage']
            self._verify_target(kind, stage, value, raw, facts)
            if kind == 'directory':
                need(not any(stage.iterdir()), 'STAGED_DIRECTORY_NOT_EMPTY')
            self.verify()
            same_parent(path, original)
            current = os.stat(path.name, dir_fd=parent, follow_symlinks=False) if target is not None else None
            need(current is None or identity(current) == identity(target), 'TARGET_CHANGED')
            if target is None:
                rename_absent(parent, value['stage'], path.name)
            else:
                os.replace(value['stage'], path.name, src_dir_fd=parent, dst_dir_fd=parent)
            os.fsync(parent)
            self.checkpoint('published', name)
            self.verify()
            same_parent(path, original)
            self._verify_target(kind, path, value, raw, facts)
        finally:
            os.close(parent)

    def _verify_target(self, kind, path, value, raw, facts):
        if kind == 'directory':
            info = directory(path, self.uid, self.gid)
            need(inode(info) == value['stage_inode'] and (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
                == (self.uid, self.gid, 0o755), 'OWNED_DIRECTORY_CHANGED')
        else:
            result = read(path, self.uid, self.gid)
            need(inode(result[1]) == value['stage_inode'] and result[0] == raw
                and _matches(result[1], facts), 'OWNED_SOURCE_CHANGED')

    def _file(self, kind, name, raw, facts, expected_hash):
        value = self._wal(kind, name)
        if value is None:
            current = read(self.root / name, self.uid, self.gid, optional=True)
            need(current is None if expected_hash is None else current is not None
                and sha(current[0]) == expected_hash and _matches(current[1], facts), 'BEFORE_SOURCE_CHANGED')
            value = self._stage(kind, name, raw, facts, None if current is None else current[1])
        self._publish(kind, name, value, raw, facts)

    def apply(self, after_apply=None):
        need(after_apply is None or callable(after_apply), 'AFTER_APPLY_INVALID')
        need(load_record(self.attempt / 'rollback-started.json') is None, 'ROLLBACK_MUST_FINISH_FIRST')
        committed = load_record(self.attempt / 'committed.json')
        if committed is not None:
            need(canonical(committed) == canonical(self._receipt('committed')), 'COMMITTED_RECORD_CHANGED')
            self._verify_after()
            return self.result(False)
        invalidate_blade(self.root, self.pins, self.audit, self.uid, self.gid, self.verify, check_only=True)
        for name in self.plan['new_directories']:
            value = self._wal('directory', name)
            if value is None:
                need(not os.path.lexists(self.root / name), 'NEW_DIRECTORY_NOT_ABSENT')
                value = self._stage('directory', name, None, None, None)
            self._publish('directory', name, value)
        # New classes/runtime programs must all exist before Kernel, middleware,
        # controllers or control entry points can refer to any of them.
        for name in self.plan['addition_order'] + self.plan['replacement_order']:
            self._file('file', name, self.payloads[name], self.intent['files'][name], self.pins[name]['before'])
        self._verify_after()
        invalidate_blade(self.root, self.pins, self.audit, self.uid, self.gid, self.verify)
        if after_apply is not None:
            self.verify()
            after_apply()
            self.verify()
            self._verify_after()
        private_record(self.attempt / 'committed.json', self._receipt('committed'), self.verify)
        self.checkpoint('committed', '')
        return self.result(True)

    def _verify_after(self):
        for name in self.plan['new_directories']:
            value = self._wal('directory', name)
            need(value is not None, 'DIRECTORY_OWNERSHIP_RECORD_MISSING')
            self._verify_target('directory', self.root / name, value, None, None)
            children = {path[len(name) + 1:].split('/')[0]
                for path in (*self.pins, *self.plan['new_directories']) if path.startswith(name + '/')}
            need({entry.name for entry in (self.root / name).iterdir()} == children,
                'UNREVIEWED_CONTENT_IN_NEW_DIRECTORY')
        for name in self.pins:
            value = self._wal('file', name)
            need(value is not None, 'SOURCE_OWNERSHIP_RECORD_MISSING')
            self._verify_target('file', self.root / name, value, self.payloads[name], self.intent['files'][name])

    def _receipt(self, phase, attempt_name=None):
        return {'format': 1, 'kind': 'go-erp-shared-workspace-source-' + phase,
            'plan_sha256': self.digest, 'attempt': self.attempt.name if attempt_name is None else attempt_name,
            'schema_changed': False, 'financial_data_changed': False,
            'feature_activated': False, 'allocated': False, 'published': False}

    def result(self, changed):
        return {**self._receipt('committed'), 'changed': changed, 'audit': str(self.audit),
            'paths': sorted(self.pins), 'new_directories': self.plan['new_directories']}

    def _cleanup_stage(self, kind, name, value, raw=None, facts=None):
        path = self.root / name
        stage = path.parent / self._stage_name(kind, name)
        if not os.path.lexists(stage):
            return
        need(value is not None, 'UNOWNED_STAGE_REVIEW_REQUIRED')
        self._verify_target(kind, stage, value, raw, facts)
        self.verify()
        parent = parent_fd(stage)
        try:
            info = os.stat(stage.name, dir_fd=parent, follow_symlinks=False)
            need(inode(info) == value['stage_inode'], 'STAGE_CHANGED')
            if kind == 'directory':
                try:
                    os.rmdir(stage.name, dir_fd=parent)
                except OSError as error:
                    need(error.errno not in (errno.ENOTEMPTY, errno.EEXIST), 'OWNED_STAGE_DIRECTORY_NOT_EMPTY')
                    raise
            else:
                os.unlink(stage.name, dir_fd=parent)
            os.fsync(parent)
        finally:
            os.close(parent)

    def _rollback_file(self, name):
        value = self._wal('file', name)
        facts, row = self.intent['files'][name], self.pins[name]
        path = self.root / name
        missing = _missing_chain(self.root, str(PurePosixPath(name).parent),
            self.plan['new_directories'], self.uid, self.gid)
        if missing:
            need(row['before'] is None and value is None, 'SOURCE_PARENT_DISAPPEARED')
            return
        current = read(path, self.uid, self.gid, optional=True)
        undo = self._wal('restore', name)
        if undo is not None:
            backup = read(self.audit / facts['backup'], 0, 0, private=True)[0]
            self._publish('restore', name, undo, backup, facts)
        elif value is not None and current is not None and inode(current[1]) == value['stage_inode']:
            self._verify_target('file', path, value, self.payloads[name], facts)
            if row['before'] is None:
                private_record(self._wal_path('remove', name), {'format': 1, 'plan_sha256': self.digest,
                    'name': name, 'owned_inode': inode(current[1])}, self.verify)
                self.verify()
                parent = parent_fd(path)
                try:
                    need(identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False))
                        == identity(current[1]), 'REMOVAL_TARGET_CHANGED')
                    os.unlink(path.name, dir_fd=parent)
                    os.fsync(parent)
                finally:
                    os.close(parent)
                self.checkpoint('removed', name)
            else:
                backup = read(self.audit / facts['backup'], 0, 0, private=True)[0]
                self._file('restore', name, backup, facts, row['after'])
        elif value is not None and value['before_identity'] is not None:
            need(current is not None and list(identity(current[1])) == value['before_identity']
                and sha(current[0]) == row['before'] and _matches(current[1], facts), 'ROLLBACK_TARGET_NOT_OWNED')
        elif row['before'] is None:
            need(current is None, 'ROLLBACK_ADDED_TARGET_NOT_OWNED')
        else:
            need(current is not None and sha(current[0]) == row['before'] and _matches(current[1], facts),
                'UNTOUCHED_SOURCE_CHANGED')
        self._cleanup_stage('file', name, value, self.payloads[name], facts)

    def _referrer_snapshot(self):
        result = {}
        for name in self.plan['replacement_order']:
            value = read(self.root / name, self.uid, self.gid)
            need(sha(value[0]) == self.pins[name]['before'] and _matches(value[1], self.intent['files'][name]),
                'REFERRER_NOT_RESTORED')
            result[name] = {'sha256': self.pins[name]['before'], 'identity': list(identity(value[1]))}
        return result

    def _addition_removal_barrier(self, callback, expected_handler_sha256):
        visible = False
        additions = {}
        for name in self.plan['addition_order']:
            missing = _missing_chain(self.root, str(PurePosixPath(name).parent),
                self.plan['new_directories'], self.uid, self.gid)
            value = self._wal('file', name)
            current = None if missing else read(self.root / name, self.uid, self.gid, optional=True)
            if current is not None:
                need(value is not None and inode(current[1]) == value['stage_inode'],
                    'ROLLBACK_ADDED_TARGET_NOT_OWNED')
                self._verify_target('file', self.root / name, value, self.payloads[name], self.intent['files'][name])
                visible = True
            if value is not None:
                additions[name] = value['stage_inode']
        snapshot = self._referrer_snapshot()
        if not visible:
            return snapshot
        need(callable(callback) and isinstance(expected_handler_sha256, str)
            and SHA.fullmatch(expected_handler_sha256) and expected_handler_sha256 != '0' * 64,
            'FPM_DRAIN_BOUNDARY_REQUIRED')
        pending = {'format': 1, 'kind': 'go-erp-shared-workspace-addition-removal-intent',
            'plan_sha256': self.digest, 'attempt': self.attempt.name,
            'restored_referrers_sha256': sha(canonical(snapshot)), 'addition_inodes': additions,
            'php_handler_configuration_sha256': expected_handler_sha256,
            'fpm_unit': 'ea-php82-php-fpm.service', 'drain_required': True}
        private_record(self.attempt / 'addition-removal-pending.json', pending, self.verify)
        proof = load_record(self.attempt / 'addition-removal-approved.json')
        if proof is None:
            self.verify()
            proof = callback(parse(canonical(pending)))
            self.verify()
        need(isinstance(proof, dict) and set(proof) == {'format', 'kind', 'pending_sha256',
                'php_handler_configuration_sha256', 'fpm_unit', 'old_generation_captured_before_reload',
                'drained_before_addition_removal',
                'reload_completed', 'no_old_generation_requests', 'private_receipt_sha256',
                'restored_referrers_sha256', 'compiled_referrers_restored',
                'route_cache_policy_verified', 'workspace_enabled'}
            and type(proof['format']) is int and proof['format'] == 1
            and proof['kind'] == 'go-erp-shared-workspace-addition-removal-approved'
            and proof['pending_sha256'] == sha(canonical(pending))
            and proof['php_handler_configuration_sha256'] == expected_handler_sha256
            and proof['restored_referrers_sha256'] == pending['restored_referrers_sha256']
            and proof['fpm_unit'] == pending['fpm_unit']
            and all(proof[key] is True for key in ('old_generation_captured_before_reload',
                'drained_before_addition_removal', 'reload_completed',
                'no_old_generation_requests', 'compiled_referrers_restored', 'route_cache_policy_verified'))
            and proof['workspace_enabled'] is False
            and isinstance(proof['private_receipt_sha256'], str) and SHA.fullmatch(proof['private_receipt_sha256'])
            and proof['private_receipt_sha256'] != '0' * 64, 'FPM_DRAIN_BOUNDARY_PROOF_INVALID')
        need(canonical(snapshot) == canonical(self._referrer_snapshot()), 'REFERRER_CHANGED_DURING_DRAIN')
        private_record(self.attempt / 'addition-removal-approved.json', proof, self.verify)
        self.checkpoint('addition-removal-ready', '')
        return snapshot

    def rollback(self, after_rollback=None, *, addition_removal_barrier=None, expected_handler_sha256=None):
        """Restore/remove only inodes proved by this attempt's write-ahead records.

        A committed transaction requires a separately reviewed coordinator action;
        this API cannot undo it. Unknown stages or peer-created content survive.
        A failed rollback remains resumable and never authorizes a new attempt.
        """
        need(after_rollback is None or callable(after_rollback), 'AFTER_ROLLBACK_INVALID')
        need(load_record(self.attempt / 'committed.json') is None, 'COMMITTED_ROLLBACK_REQUIRES_REVIEW')
        completed = load_record(self.attempt / 'rolled-back.json')
        if completed is not None:
            need(canonical(completed) == canonical(self._receipt('rolled-back')), 'ROLLED_BACK_RECORD_CHANGED')
            self._verify_before()
            return {**completed, 'audit': str(self.audit), 'restored': True}
        private_record(self.attempt / 'rollback-started.json', self._receipt('rollback-started'), self.verify)
        # Restore referrers first, in reverse dependency order. New class files
        # remain available to old in-flight FPM requests until the drain proof.
        for name in reversed(self.plan['replacement_order']):
            self._rollback_file(name)
        invalidate_blade(self.root, self.plan['replacement_order'], self.audit,
            self.uid, self.gid, self.verify)
        snapshot = self._addition_removal_barrier(addition_removal_barrier, expected_handler_sha256)
        for name in reversed(self.plan['addition_order']):
            need(canonical(snapshot) == canonical(self._referrer_snapshot()), 'REFERRER_CHANGED_AFTER_DRAIN')
            self._rollback_file(name)
        for name in reversed(self.plan['new_directories']):
            value = self._wal('directory', name)
            path = self.root / name
            if os.path.lexists(path):
                need(value is not None, 'ROLLBACK_DIRECTORY_NOT_OWNED')
                self._verify_target('directory', path, value, None, None)
                self.verify()
                parent = parent_fd(path)
                try:
                    need(inode(os.stat(path.name, dir_fd=parent, follow_symlinks=False)) == value['stage_inode'],
                        'ROLLBACK_DIRECTORY_CHANGED')
                    try:
                        os.rmdir(path.name, dir_fd=parent)
                    except OSError as error:
                        need(error.errno not in (errno.ENOTEMPTY, errno.EEXIST), 'OWNED_DIRECTORY_NOT_EMPTY')
                        raise
                    os.fsync(parent)
                finally:
                    os.close(parent)
                self.checkpoint('directory-removed', name)
            self._cleanup_stage('directory', name, value)
        self._verify_before()
        invalidate_blade(self.root, self.pins, self.audit, self.uid, self.gid, self.verify)
        if after_rollback is not None:
            self.verify()
            after_rollback()
            self.verify()
            self._verify_before()
        private_record(self.attempt / 'rolled-back.json', self._receipt('rolled-back'), self.verify)
        return {**self._receipt('rolled-back'), 'audit': str(self.audit), 'restored': True}


def apply(root, audit_parent, release, owner_uid, payloads, *, pins, owner_gid,
        new_directories=(), verify_locks=None, after_apply=None, checkpoint=None, replacement_order=None):
    transaction = Transaction(root, audit_parent, release, owner_uid, owner_gid,
        payloads, pins, new_directories, verify_locks, checkpoint, replacement_order=replacement_order)
    return transaction.apply(after_apply)


def rollback(root, audit_parent, release, owner_uid, payloads, *, pins, owner_gid,
        new_directories=(), verify_locks=None, after_rollback=None, checkpoint=None,
        replacement_order=None, addition_removal_barrier=None, expected_handler_sha256=None):
    transaction = Transaction(root, audit_parent, release, owner_uid, owner_gid,
        payloads, pins, new_directories, verify_locks, checkpoint, new_attempt=False, replacement_order=replacement_order)
    return transaction.rollback(after_rollback, addition_removal_barrier=addition_removal_barrier,
        expected_handler_sha256=expected_handler_sha256)


if __name__ == '__main__':
    print('GO_ERP_SHARED_WORKSPACE_SOURCE_USE_PINNED_COORDINATOR')
    raise SystemExit(1)
