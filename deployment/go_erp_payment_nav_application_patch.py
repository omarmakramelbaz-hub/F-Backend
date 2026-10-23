#!/usr/bin/env python3
"""Pinned source exchanges for the desktop program section watcher migration.

This module never controls services or reads a database, environment, credentials,
watcher configuration or business storage. Its caller owns the watcher lock and
quiescence window through prepare/apply, watcher migration, rollback or commit.
"""
import copy
import ctypes
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import tempfile

# Filled from the final reviewed integrated core before publication.
BEFORE_SOURCE = None
AFTER_SOURCE = None
PINS = {}
NAV = 'public/dashboard/js/dashboard-navigation.js'
PAYMENT_BASE_SOURCE = {'format': 1, 'files': 1342, 'framework': '8.83.29', 'sha256': '13bf1542e2118724272545f70f8eb2bd113fc1fe475bbc9a378135528f6824ea'}
PAYMENT_SOURCE = {'format': 1, 'files': 1342, 'framework': '8.83.29', 'sha256': '2878fb6e2bd038df4c569a13d0cfe48a06532fc0d9ab2df9bcded41dcdb7fc00'}
PAYMENT_PINS = {'app/Services/Dashboard/OrderBoardService.php': ('d2c8c69de733b131aa27f36e02d6b4b0b990b6b2', '51d4553662658d979d1c7fbc3e4721d8b12c04f8'), 'app/Services/Dashboard/PosServiceTicket.php': ('3549b9fa0df2dd97a630b20bb9753df4d4178bf4', 'b1600ecb7c1f5ec6a3d8f90b6c391ccca8f5babe'), 'public/dashboard/js/phone-orders.js': ('5923ef6feccda16bd1d759e9d9d307a96f8c6480', '319cbc52a36f0f73ad69f3285e4e7d52b5e7fa69'), 'public/dashboard/css/phone-delivery-refresh.css': ('bd9f95fb6d8a28f382708ca6e6a9b36f12d62392', '181359e8b0e4088f544eede725306fcbf3f7e9e7')}


def configure(before_source, after_source, pins):
    global BEFORE_SOURCE, AFTER_SOURCE, PINS
    need(set(pins) == {NAV} and before_source == PAYMENT_SOURCE, 'PAYMENT_NAVIGATION_SCOPE_INVALID')
    need(isinstance(before_source, dict) and isinstance(after_source, dict)
         and before_source != after_source and isinstance(pins, dict) and bool(pins), 'RELEASE_PINS_PENDING')
    need(all(safe_target(name) and isinstance(pair, (tuple, list)) and len(pair) == 2
         and (pair[0] is None or isinstance(pair[0], str) and len(pair[0]) == 40 and all(c in '0123456789abcdef' for c in pair[0]))
         and isinstance(pair[1], str) and len(pair[1]) == 40 and all(c in '0123456789abcdef' for c in pair[1]) and pair[0] != pair[1]
         for name, pair in pins.items()), 'RELEASE_PINS_INVALID')
    BEFORE_SOURCE, AFTER_SOURCE, PINS = copy.deepcopy(before_source), copy.deepcopy(after_source), copy.deepcopy(pins)


MANIFEST = 'deployment/desktop_dashboard_server_manifest.json'
MAX_FILE = 2 * 1024 * 1024


def safe_target(name):
    return (isinstance(name, str) and bool(re.fullmatch(r'[A-Za-z0-9_.@/-]+', name))
            and all(part not in ('', '.', '..') for part in name.split('/'))
            and name != MANIFEST)


def selected_target(scanner, name):
    need(safe_target(name) and not scanner.SECRET.search(name)
         and (scanner.selected(name) or name.startswith('public/')), 'TARGET_PATH_INVALID')
    return scanner.selected(name)


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_PROGRAM_APPLICATION_' + reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def identity(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def directory_identity(item):
    return item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid


def rename(left_fd, left, right_fd, right, flags):
    operation = ctypes.CDLL(None, use_errno=True).renameat2
    operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    operation.restype = ctypes.c_int
    if operation(left_fd, os.fsencode(left), right_fd, os.fsencode(right), flags):
        raise OSError(ctypes.get_errno(), 'atomic source exchange failed')


def unique_json(data):
    def unique(pairs):
        value = {}
        for name, item in pairs:
            need(name not in value, 'DUPLICATE_MANIFEST_KEY')
            value[name] = item
        return value
    return json.loads(data, object_pairs_hook=unique)


def payment_metadata(value, after=False):
    """Recognize exactly four reviewed metadata records; never rewrite their code."""
    need(isinstance(value, dict) and value.get('version') == 1
         and isinstance(value.get('sources'), list) and isinstance(value.get('protected'), list),
         'PAYMENT_MANIFEST_UNREVIEWED')
    names = [row.get('path') for row in value['sources'] + value['protected'] if isinstance(row, dict)]
    need(len(names) == len(set(names)) == len(value['sources']) + len(value['protected']), 'MANIFEST_PATHS_INVALID')
    for name, (old, new) in PAYMENT_PINS.items():
        rows = [row for row in value['sources'] if row['path'] == name]
        protected = [row for row in value['protected'] if row['path'] == name]
        wanted = new if after else old
        need(len(rows) + len(protected) == 1 and
             (rows[0].get('after_blob') if rows else protected[0].get('blob')) == wanted,
             'PAYMENT_MANIFEST_PIN_UNREVIEWED')
        if after:
            continue
        if rows:
            rows[0]['after_blob'] = new
        else:
            value['protected'].remove(protected[0])
            value['sources'].append({'path': name, 'before_blob': old, 'after_blob': new, 'phase': 2})
    return value


def refreshed_manifest(before, targets_before, targets_after):
    """Keep the original deployment cohort/history and alter the reviewed target pins only."""
    value = unique_json(before)
    need(isinstance(value, dict) and value.get('after_source') == PAYMENT_BASE_SOURCE and BEFORE_SOURCE == PAYMENT_SOURCE,
         'PAYMENT_MANIFEST_BASE_UNREVIEWED')
    value = payment_metadata(value)
    value['after_source'] = BEFORE_SOURCE
    need(isinstance(value, dict) and value.get('version') == 1
         and value.get('after_source') == BEFORE_SOURCE
         and isinstance(value.get('sources'), list) and isinstance(value.get('protected'), list),
         'MANIFEST_UNREVIEWED')
    result = copy.deepcopy(value)
    names = [row.get('path') for row in result['sources'] + result['protected'] if isinstance(row, dict)]
    need(len(names) == len(set(names)) == len(result['sources']) + len(result['protected']), 'MANIFEST_PATHS_INVALID')
    for name, (old, new) in PINS.items():
        need(targets_after[name]['blob'] == new and (targets_before.get(name, {}).get('blob')) == old,
             'MANIFEST_SOURCE_PIN_INVALID')
        rows = [row for row in result['sources'] if row['path'] == name]
        protected = [row for row in result['protected'] if row['path'] == name]
        if old is None:
            need(not rows and not protected, 'MANIFEST_ADDITION_ALREADY_PRESENT')
            index = next((i for i, row in enumerate(result['sources']) if row['phase'] > 1), len(result['sources']))
            result['sources'].insert(index, {'path': name, 'before_blob': None, 'after_blob': new, 'phase': 1})
        elif rows:
            need(len(rows) == 1 and rows[0].get('after_blob') == old, 'MANIFEST_OLD_TARGET_DIFFERS')
            rows[0]['after_blob'] = new
        else:
            need(len(protected) == 1 and protected[0].get('blob') == old, 'MANIFEST_OLD_PROTECTED_DIFFERS')
            result['protected'].remove(protected[0])
            result['sources'].append({'path': name, 'before_blob': old, 'after_blob': new, 'phase': 2})
    result['after_source'] = AFTER_SOURCE
    return (json.dumps(result, ensure_ascii=True, indent=2) + '\n').encode()


def pinned_scanner(package):
    import types
    path = package / 'refresh_desktop_dashboard_server_manifest.py'
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
             and not before.st_mode & 0o022 and 0 < before.st_size <= MAX_FILE, 'SCANNER_FILE_UNSAFE')
        data = os.read(fd, MAX_FILE + 1)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
             == identity(path.lstat()) and blob(data) == '8a6834d46dcc29dc4af05fab7d7d47aac6bd5242', 'SCANNER_PIN_INVALID')
    finally:
        os.close(fd)
    result = types.ModuleType('wire_hash_source_scanner')
    result.__file__ = str(path)
    exec(compile(data, str(path), 'exec'), result.__dict__)
    return result


def optional_manifest(root, web_uid):
    """Read existing deployment metadata only; preserve missing file/directory."""
    root = Path(root).absolute()
    for parent in (root, *root.parents):
        item = parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and not stat.S_ISLNK(item.st_mode)
             and item.st_uid in (0, web_uid)
             and (not item.st_mode & 0o022 or parent != root and bool(item.st_mode & stat.S_ISVTX)),
             'MANIFEST_ANCESTRY_UNSAFE')
    directory = root / 'deployment'
    try:
        named = directory.lstat()
    except FileNotFoundError:
        return None, None
    need(stat.S_ISDIR(named.st_mode) and not stat.S_ISLNK(named.st_mode)
         and named.st_uid in (0, web_uid) and not named.st_mode & 0o022, 'MANIFEST_DIRECTORY_UNSAFE')
    directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        need(directory_identity(named) == directory_identity(os.fstat(directory_fd)), 'MANIFEST_DIRECTORY_CHANGED')
        try:
            fd = os.open(Path(MANIFEST).name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
        except FileNotFoundError:
            need(directory_identity(named) == directory_identity(directory.lstat()), 'MANIFEST_DIRECTORY_CHANGED')
            return None, None
        try:
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_uid in (0, web_uid)
                 and not before.st_mode & 0o022 and 0 < before.st_size <= MAX_FILE, 'MANIFEST_FILE_UNSAFE')
            data = bytearray()
            while len(data) <= MAX_FILE:
                part = os.read(fd, min(65536, MAX_FILE + 1 - len(data)))
                if not part:
                    break
                data.extend(part)
            need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
                 == identity(os.stat(Path(MANIFEST).name, dir_fd=directory_fd, follow_symlinks=False))
                 and directory_identity(named) == directory_identity(directory.lstat()), 'MANIFEST_FILE_CHANGED')
            return bytes(data), before
        finally:
            os.close(fd)
    finally:
        os.close(directory_fd)


class PinnedSourceReader:
    """Read only exact declared paths through stable, unlinked directory/file descriptors."""
    def __init__(self, root, web_uid):
        self.root, self.uid, self.parents = Path(root).absolute(), web_uid, {}

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()

    def close(self):
        for fd, _ in self.parents.values():
            os.close(fd)
        self.parents = {}

    def _ancestry(self, path, root_only=False):
        for parent in (path, *path.parents):
            item = parent.lstat()
            need(stat.S_ISDIR(item.st_mode) and not stat.S_ISLNK(item.st_mode)
                 and item.st_uid in ((0,) if root_only else (0, self.uid))
                 and (not item.st_mode & 0o022 or parent != path and bool(item.st_mode & stat.S_ISVTX)), 'DIRECTORY_UNSAFE')

    def _parent(self, name):
        need(safe_target(name) or name == MANIFEST, 'TARGET_PATH_INVALID')
        path = (self.root / name).parent
        if path not in self.parents:
            self._ancestry(path)
            fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            self.parents[path] = fd, directory_identity(os.fstat(fd))
        fd, wanted = self.parents[path]
        need(directory_identity(os.fstat(fd)) == wanted == directory_identity(path.lstat()), 'DIRECTORY_CHANGED')
        return fd

    def _read(self, name):
        parent = self._parent(name)
        leaf = Path(name).name
        try:
            fd = os.open(leaf, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        except FileNotFoundError:
            return None, None
        try:
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_uid in (0, self.uid)
                 and not before.st_mode & 0o022 and 0 < before.st_size <= MAX_FILE, 'SOURCE_FILE_UNSAFE')
            data = bytearray()
            while len(data) <= MAX_FILE:
                part = os.read(fd, min(65536, MAX_FILE + 1 - len(data)))
                if not part:
                    break
                data.extend(part)
            need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
                 == identity(os.stat(leaf, dir_fd=parent, follow_symlinks=False)), 'SOURCE_FILE_CHANGED')
            return bytes(data), before
        finally:
            os.close(fd)

def target_blobs(root, scanner, web_uid):
    with PinnedSourceReader(root, web_uid) as reader:
        result = {}
        for name in PINS:
            selected_target(scanner, name)
            data, _ = reader._read(name)
            result[name] = {'blob': blob(data) if data is not None else None}
        return result


class ApplicationPatch(PinnedSourceReader):
    def __init__(self, root, package, audit_directory, web_uid, web_gid, lock_fd):
        self.root, self.package, self.audit = map(lambda p: Path(p).absolute(), (root, package, audit_directory))
        self.uid, self.gid, self.lock_fd = web_uid, web_gid, lock_fd
        super().__init__(root, web_uid)
        self.slots = []
        self.prepared = self.applied = self.committed = self.rolled_back = False
        self.stage = None
        self.stage_fd = None
        need(os.geteuid() == 0, 'ROOT_REQUIRED')
        lock = os.fstat(lock_fd)
        need(stat.S_ISREG(lock.st_mode) and lock.st_uid == 0 and lock.st_nlink == 1
             and stat.S_IMODE(lock.st_mode) == 0o600, 'LOCK_UNSAFE')
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.lock_identity = identity(lock)
        self._ancestry(self.root)
        self._ancestry(self.package, root_only=True)
        audit = self.audit.lstat()
        need(stat.S_ISDIR(audit.st_mode) and audit.st_uid == 0 and stat.S_IMODE(audit.st_mode) == 0o700
             and not self.audit.is_symlink() and not list(self.audit.iterdir()), 'AUDIT_NOT_EMPTY_PRIVATE_DIRECTORY')

    def _stage_read(self, slot):
        need(directory_identity(os.fstat(self.stage_fd)) == directory_identity(self.stage.lstat()), 'STAGE_CHANGED')
        fd = os.open(slot, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.stage_fd)
        try:
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= MAX_FILE, 'STAGE_FILE_UNSAFE')
            data = bytearray()
            while len(data) <= MAX_FILE:
                part = os.read(fd, min(65536, MAX_FILE + 1 - len(data)))
                if not part:
                    break
                data.extend(part)
            need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
                 == identity(os.stat(slot, dir_fd=self.stage_fd, follow_symlinks=False)), 'STAGE_FILE_CHANGED')
            return bytes(data), before
        finally:
            os.close(fd)

    def _write(self, directory, name, data, mode=0o600, uid=0, gid=0):
        fd = os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'wb', closefd=False) as output:
                output.write(data)
                output.flush()
                os.fchown(fd, uid, gid)
                os.fchmod(fd, mode)
                os.fsync(fd)
        finally:
            os.close(fd)

    def _audit(self, name, value):
        self._write(self.audit, name, (json.dumps(value, sort_keys=True) + '\n').encode())

    def _check_lock(self):
        need(identity(os.fstat(self.lock_fd)) == self.lock_identity, 'LOCK_CHANGED')

    def prepare(self):
        need(not self.prepared, 'ALREADY_PREPARED')
        self._check_lock()
        # The adjacent scanner is code loaded by the pinned outer package. No PHP,
        # Laravel bootstrap, runtime config or original application code is run.
        self.scanner = pinned_scanner(self.package)
        self.before_map = self.scanner.scan(self.root)
        need(self.scanner.digest(self.before_map) == BEFORE_SOURCE, 'BEFORE_SOURCE_UNREVIEWED')
        after_map = copy.deepcopy(self.before_map)
        payloads, before_targets, after_targets = {}, {}, {}
        for name, (old, wanted) in PINS.items():
            selected = selected_target(self.scanner, name)
            data, info = self._read(name)
            need((blob(data) if data is not None else None) == old, 'BEFORE_FILE_UNREVIEWED')
            with PinnedSourceReader(self.package / 'application', 0) as reader:
                wanted_data, _ = reader._read(name)
            need(wanted_data is not None, 'PACKAGE_FILE_UNSAFE')
            need(blob(wanted_data) == wanted, 'PACKAGE_FILE_PIN_INVALID')
            payloads[name] = wanted_data
            before_targets[name], after_targets[name] = {'blob': old}, {'blob': wanted}
            if selected:
                after_map[name] = {'blob': wanted, 'sha256': hashlib.sha256(wanted_data.replace(b'\r\n', b'\n')).hexdigest()}
        need(self.scanner.digest(after_map) == AFTER_SOURCE, 'AFTER_SOURCE_UNREVIEWED')
        manifest, manifest_info = optional_manifest(self.root, self.uid)
        self.manifest_present = manifest is not None
        need(self.manifest_present, 'PAYMENT_MANIFEST_MISSING')
        if self.manifest_present:
            payloads[MANIFEST] = refreshed_manifest(manifest, before_targets, after_targets)
        self.after_map = after_map
        self.stage = Path(tempfile.mkdtemp(prefix='.go-erp-program-patch-', dir=self.root.parent))
        os.chmod(self.stage, 0o700)
        self.stage_fd = os.open(self.stage, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        for number, (name, wanted_data) in enumerate(payloads.items()):
            original, info = self._read(name)
            if name == MANIFEST:
                need(original == manifest and info is not None and identity(info) == identity(manifest_info),
                     'MANIFEST_CHANGED_BEFORE_STAGING')
            slot = str(number)
            if original is not None:
                self._write(self.audit, slot + '.before', original)
            mode, uid, gid = (stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid) if info else (0o644, self.uid, self.gid)
            self._write(self.stage, slot, wanted_data, mode, uid, gid)
            published = (self.stage / slot).lstat()
            need(published.st_dev == os.fstat(self._parent(name)).st_dev, 'SOURCE_EXCHANGE_FILESYSTEM_DIFFERS')
            self.slots.append({'name': name, 'slot': slot, 'before': original, 'before_info': info,
                               'wanted': wanted_data, 'published': identity(published), 'moved': False})
        self._audit('application-intent.json', {'before_source': BEFORE_SOURCE, 'after_source': AFTER_SOURCE,
            'manifest_refreshed': self.manifest_present, 'manifest_preserved_absent': not self.manifest_present,
            'files': [{'path': item['name'], 'before_blob': blob(item['before']) if item['before'] else None,
                       'after_blob': blob(item['wanted'])} for item in self.slots], 'stage': str(self.stage)})
        self.prepared = True
        return {'application_files': len(PINS), 'manifest_refreshed': self.manifest_present,
                'manifest_preserved_absent': not self.manifest_present, 'before_source': BEFORE_SOURCE,
                'after_source': AFTER_SOURCE, 'database_changed': False}

    def apply(self):
        need(self.prepared and not self.applied and not self.committed, 'NOT_PREPARED')
        self._check_lock()
        need(self.scanner.scan(self.root) == self.before_map, 'SOURCE_CHANGED_BEFORE_APPLY')
        self._verify_manifest_absence()
        # The fingerprint deliberately excludes raw GUI assets. Check every
        # pinned inode before the first exchange, then again at its exchange.
        for item in self.slots:
            current, info = self._read(item['name'])
            need(current == item['before'] and (info is None or identity(info) == identity(item['before_info'])), 'TARGET_CHANGED')
        try:
            for item in self.slots:
                name, slot = item['name'], item['slot']
                current, info = self._read(name)
                need(current == item['before'] and (info is None or identity(info) == identity(item['before_info'])), 'TARGET_CHANGED')
                staged, staged_info = self._stage_read(slot)
                need(staged == item['wanted'] and identity(staged_info) == item['published'], 'STAGED_TARGET_CHANGED')
                parent = self._parent(name)
                rename(self.stage_fd, slot, parent, Path(name).name, 1 if info is None else 2)
                item['moved'] = True
                # Exchanges preserve file content/ownership but update ctime.
                item['published'] = identity(os.stat(Path(name).name, dir_fd=parent, follow_symlinks=False))
                if info is not None:
                    moved = os.stat(slot, dir_fd=self.stage_fd, follow_symlinks=False)
                    need((moved.st_dev, moved.st_ino) == (info.st_dev, info.st_ino), 'EXCHANGED_ORIGINAL_CHANGED')
                    item['held_original'] = identity(moved)
                os.fsync(parent)
                os.fsync(self.stage_fd)
            self.applied = True
            self.verify()
        except BaseException:
            self.rollback()
            raise

    def _verify_manifest_absence(self):
        if not self.manifest_present:
            need(optional_manifest(self.root, self.uid)[0] is None, 'MANIFEST_APPEARED')

    def verify(self):
        self._check_lock()
        self._verify_manifest_absence()
        need(self.applied and self.scanner.scan(self.root) == self.after_map, 'AFTER_SOURCE_CHANGED')
        for item in self.slots:
            data, info = self._read(item['name'])
            need(data == item['wanted'] and identity(info) == item['published'], 'PUBLISHED_FILE_CHANGED')
            if item['before'] is not None:
                held, held_info = self._stage_read(item['slot'])
                need(held == item['before'] and identity(held_info) == item['held_original'], 'HELD_ORIGINAL_CHANGED')
        return True

    def rollback(self):
        need(not self.committed, 'ALREADY_COMMITTED')
        self._check_lock()
        if self.rolled_back:
            try:
                if hasattr(self, 'before_map'):
                    need(self.scanner.scan(self.root) == self.before_map, 'ROLLBACK_SOURCE_DIFFERS')
                for item in self.slots:
                    need(self._read(item['name'])[0] == item['before'], 'ROLLBACK_FILE_DIFFERS')
                if hasattr(self, 'manifest_present'):
                    self._verify_manifest_absence()
                return True
            finally:
                self.close()
        if not self.prepared and not any(item['moved'] for item in self.slots):
            self._cleanup()
            self.rolled_back = True
            if hasattr(self, 'manifest_present'):
                self._verify_manifest_absence()
            return True
        # Classify every target and retained original before the first rollback
        # mutation. An unexpected writer never has its replacement overwritten.
        for item in self.slots:
            if item['moved']:
                data, info = self._read(item['name'])
                need(data == item['wanted'] and identity(info) == item['published'], 'ROLLBACK_TARGET_CHANGED')
                if item['before'] is not None:
                    data, info = self._stage_read(item['slot'])
                    need(data == item['before'] and identity(info) == item['held_original'], 'ROLLBACK_ORIGINAL_CHANGED')
        for item in reversed(self.slots):
            if not item['moved']:
                continue
            data, info = self._read(item['name'])
            need(data == item['wanted'] and identity(info) == item['published'], 'ROLLBACK_TARGET_CHANGED')
            parent = self._parent(item['name'])
            rename(parent, Path(item['name']).name, self.stage_fd, item['slot'], 1 if item['before'] is None else 2)
            item['moved'] = False
            os.fsync(parent)
            os.fsync(self.stage_fd)
        self.applied = False
        need(self.scanner.scan(self.root) == self.before_map, 'ROLLBACK_SOURCE_DIFFERS')
        for item in self.slots:
            need(self._read(item['name'])[0] == item['before'], 'ROLLBACK_FILE_DIFFERS')
        self._verify_manifest_absence()
        self._audit('application-rolled-back.json', {'restored': True, 'database_changed': False,
            'manifest_preserved_absent': not self.manifest_present})
        self.rolled_back = True
        self._cleanup()
        return True

    def commit(self):
        self.verify()
        self._audit('application-committed.json', {'source': AFTER_SOURCE, 'database_changed': False,
            'manifest_refreshed': self.manifest_present, 'manifest_preserved_absent': not self.manifest_present})
        self.committed = True
        self._cleanup()

    def _cleanup(self):
        # Remove only our named private slots, never a recursive tree.
        if self.stage_fd is not None:
            for item in self.slots:
                try:
                    os.unlink(item['slot'], dir_fd=self.stage_fd)
                except FileNotFoundError:
                    pass
            os.close(self.stage_fd)
            self.stage_fd = None
            os.rmdir(self.stage)
        self.close()


def verify_current(root, package, web_uid, web_gid, lock_fd):
    """Idempotent path for an already migrated watcher; never create an audit."""
    root, package = Path(root).absolute(), Path(package).absolute()
    scanner = pinned_scanner(package)
    lock = os.fstat(lock_fd)
    need(stat.S_ISREG(lock.st_mode) and lock.st_uid == 0 and lock.st_nlink == 1
         and stat.S_IMODE(lock.st_mode) == 0o600, 'LOCK_UNSAFE')
    fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    files = scanner.scan(root)
    need(scanner.digest(files) == AFTER_SOURCE, 'AFTER_SOURCE_UNREVIEWED')
    targets = target_blobs(root, scanner, web_uid)
    for name, (_, wanted) in PINS.items():
        need(targets[name]['blob'] == wanted, 'AFTER_FILE_PIN_INVALID')
    data, _ = optional_manifest(root, web_uid)
    present = data is not None
    need(present, 'PAYMENT_MANIFEST_MISSING')
    if present:
        manifest = unique_json(data)
        payment_metadata(manifest, after=True)
        need(manifest.get('after_source') == AFTER_SOURCE and isinstance(manifest.get('sources'), list), 'MANIFEST_UNREVIEWED')
        for name, (_, wanted) in PINS.items():
            need([row.get('after_blob') for row in manifest['sources'] if row.get('path') == name] == [wanted], 'MANIFEST_TARGET_DIFFERS')
    need(identity(os.fstat(lock_fd)) == identity(lock), 'LOCK_CHANGED')
    return {'already_current': True, 'application_files': len(PINS), 'manifest_refreshed': present,
            'manifest_preserved_absent': not present, 'after_source': AFTER_SOURCE, 'database_changed': False}


def verify_before(root, package, web_uid=None):
    """Read-only source proof for --check; the transaction independently repeats it."""
    scanner = pinned_scanner(Path(package))
    files = scanner.scan(Path(root))
    need(scanner.digest(files) == BEFORE_SOURCE, 'BEFORE_SOURCE_UNREVIEWED')
    targets = target_blobs(root, scanner, Path(root).lstat().st_uid if web_uid is None else web_uid)
    for name, (old, _) in PINS.items():
        need(targets[name]['blob'] == old, 'BEFORE_FILE_UNREVIEWED')
    data, _ = optional_manifest(root, Path(root).lstat().st_uid if web_uid is None else web_uid)
    need(data is not None, 'PAYMENT_MANIFEST_MISSING')
    value = unique_json(data)
    need(isinstance(value, dict) and value.get('after_source') == PAYMENT_BASE_SOURCE,
         'PAYMENT_MANIFEST_BASE_UNREVIEWED')
    payment_metadata(value)
    return {'before_source': BEFORE_SOURCE, 'database_changed': False}
