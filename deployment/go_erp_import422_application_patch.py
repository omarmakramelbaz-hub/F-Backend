#!/usr/bin/env python3
"""Four pinned source exchanges for the import diagnostic watcher migration.

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
import stat
import tempfile

BEFORE_SOURCE = {'format': 1, 'files': 1326, 'framework': '8.83.29',
    'sha256': '08e84b198c6bbbd4d917226f96b73b6762f1d3a9d2a34d56446defab2f7ab44b'}
AFTER_SOURCE = {'format': 1, 'files': 1327, 'framework': '8.83.29',
    'sha256': '39ea59e8027d71cb5282c7b7e0fffa23c30361f56f359688777cee1458742b48'}
PINS = {
    'app/Services/Dashboard/DesktopDashboardBootstrap.php': ('fdf4ef7b2ce82f373b4da11c7d79533a55d3156f', 'c113cd658fd233a5c02231ff5885c9e6fa41826c'),
    'app/Services/Dashboard/DesktopDashboardImport.php': ('c3893f1d71737881fb61553e5c2bf86d52d3f55c', '0299aa22be6d60f1e16a2026cfbae6e25e96fc71'),
    'desktop/import.php': ('b84b09282831d3fbb44ebf9d28b811ef544f184b', '8a2e148a91275ca748fd02ed9c577bd50526faeb'),
    'desktop/import-diagnostic.php': (None, 'd47d1a1e69cb21cbabe500e8695ff3203f617200'),
}
MANIFEST = 'deployment/desktop_dashboard_server_manifest.json'
MAX_FILE = 2 * 1024 * 1024


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_IMPORT422_APPLICATION_' + reason)


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


def refreshed_manifest(before, source_before, source_after):
    """Keep the original deployment cohort/history and alter four target pins only."""
    value = unique_json(before)
    need(isinstance(value, dict) and value.get('version') == 1
         and value.get('after_source') == BEFORE_SOURCE
         and isinstance(value.get('sources'), list) and isinstance(value.get('protected'), list),
         'MANIFEST_UNREVIEWED')
    result = copy.deepcopy(value)
    names = [row.get('path') for row in result['sources'] + result['protected'] if isinstance(row, dict)]
    need(len(names) == len(set(names)) == len(result['sources']) + len(result['protected']), 'MANIFEST_PATHS_INVALID')
    for name, (old, new) in PINS.items():
        need(source_after[name]['blob'] == new and (source_before.get(name, {}).get('blob')) == old,
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
    result = types.ModuleType('import422_source_scanner')
    result.__file__ = str(path)
    exec(compile(data, str(path), 'exec'), result.__dict__)
    return result


class ApplicationPatch:
    def __init__(self, root, package, audit_directory, web_uid, web_gid, lock_fd):
        self.root, self.package, self.audit = map(lambda p: Path(p).absolute(), (root, package, audit_directory))
        self.uid, self.gid, self.lock_fd = web_uid, web_gid, lock_fd
        self.parents, self.slots = {}, []
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

    def _ancestry(self, path, root_only=False):
        for parent in (path, *path.parents):
            item = parent.lstat()
            need(stat.S_ISDIR(item.st_mode) and not stat.S_ISLNK(item.st_mode)
                 and item.st_uid in ((0,) if root_only else (0, self.uid))
                 and (not item.st_mode & 0o022 or parent != path and bool(item.st_mode & stat.S_ISVTX)), 'DIRECTORY_UNSAFE')

    def _parent(self, name):
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
        payloads = {}
        for name, (old, wanted) in PINS.items():
            data, info = self._read(name)
            need((blob(data) if data is not None else None) == old, 'BEFORE_FILE_UNREVIEWED')
            package_path = self.package / 'application' / name
            self._ancestry(package_path.parent, root_only=True)
            item = package_path.lstat()
            need(stat.S_ISREG(item.st_mode) and item.st_uid == 0 and item.st_nlink == 1
                 and not item.st_mode & 0o022 and item.st_size <= MAX_FILE, 'PACKAGE_FILE_UNSAFE')
            wanted_data = package_path.read_bytes()
            need(blob(wanted_data) == wanted, 'PACKAGE_FILE_PIN_INVALID')
            payloads[name] = wanted_data
            after_map[name] = {'blob': wanted, 'sha256': hashlib.sha256(wanted_data.replace(b'\r\n', b'\n')).hexdigest()}
        need(self.scanner.digest(after_map) == AFTER_SOURCE, 'AFTER_SOURCE_UNREVIEWED')
        manifest, _ = self._read(MANIFEST)
        need(manifest is not None, 'MANIFEST_MISSING')
        payloads[MANIFEST] = refreshed_manifest(manifest, self.before_map, after_map)
        self.after_map = after_map
        self.stage = Path(tempfile.mkdtemp(prefix='.go-erp-import422-patch-', dir=self.root.parent))
        os.chmod(self.stage, 0o700)
        self.stage_fd = os.open(self.stage, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        for number, (name, wanted_data) in enumerate(payloads.items()):
            original, info = self._read(name)
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
            'files': [{'path': item['name'], 'before_blob': blob(item['before']) if item['before'] else None,
                       'after_blob': blob(item['wanted'])} for item in self.slots], 'stage': str(self.stage)})
        self.prepared = True
        return {'application_files': 4, 'manifest_refreshed': True, 'before_source': BEFORE_SOURCE,
                'after_source': AFTER_SOURCE, 'database_changed': False}

    def apply(self):
        need(self.prepared and not self.applied and not self.committed, 'NOT_PREPARED')
        self._check_lock()
        need(self.scanner.scan(self.root) == self.before_map, 'SOURCE_CHANGED_BEFORE_APPLY')
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

    def verify(self):
        self._check_lock()
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
            if hasattr(self, 'before_map'):
                need(self.scanner.scan(self.root) == self.before_map, 'ROLLBACK_SOURCE_DIFFERS')
            return True
        if not self.prepared and not any(item['moved'] for item in self.slots):
            self._cleanup()
            self.rolled_back = True
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
        self._audit('application-rolled-back.json', {'restored': True, 'database_changed': False})
        self.rolled_back = True
        self._cleanup()
        return True

    def commit(self):
        self.verify()
        self._audit('application-committed.json', {'source': AFTER_SOURCE, 'database_changed': False})
        self.committed = True
        self._cleanup()

    def _cleanup(self):
        # Remove only our five named private slots, never a recursive tree.
        if self.stage_fd is not None:
            for item in self.slots:
                try:
                    os.unlink(item['slot'], dir_fd=self.stage_fd)
                except FileNotFoundError:
                    pass
            os.close(self.stage_fd)
            self.stage_fd = None
            os.rmdir(self.stage)
        for fd, _ in self.parents.values():
            os.close(fd)
        self.parents = {}


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
    for name, (_, wanted) in PINS.items():
        need(files.get(name, {}).get('blob') == wanted, 'AFTER_FILE_PIN_INVALID')
    path = root / MANIFEST
    need(not path.is_symlink() and path.is_file() and path.stat().st_nlink == 1
         and path.stat().st_uid in (0, web_uid) and not path.stat().st_mode & 0o022
         and 0 < path.stat().st_size <= MAX_FILE, 'MANIFEST_FILE_UNSAFE')
    manifest = unique_json(path.read_bytes())
    need(manifest.get('after_source') == AFTER_SOURCE and isinstance(manifest.get('sources'), list), 'MANIFEST_UNREVIEWED')
    for name, (_, wanted) in PINS.items():
        need([row.get('after_blob') for row in manifest['sources'] if row.get('path') == name] == [wanted], 'MANIFEST_TARGET_DIFFERS')
    need(identity(os.fstat(lock_fd)) == identity(lock), 'LOCK_CHANGED')
    return {'already_current': True, 'application_files': 4, 'manifest_refreshed': True,
            'after_source': AFTER_SOURCE, 'database_changed': False}
