#!/usr/bin/env python3
"""Pinned source-only transaction used by the guarded navigation coordinator.

This module has no standalone apply CLI. The trusted coordinator supplies its
reviewed byte manifest and owns the install, manual-worker and watcher locks.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time
import uuid

PINS_PENDING = True
SOURCE_PINS = {}
ALLOWED_PATHS = frozenset({
    'resources/views/admin/index.blade.php',
    'public/dashboard/js/dashboard-spa.js',
    'app/Services/Dashboard/BranchShiftClosing.php',
})
MAX_FILE = 1024 * 1024
MAX_RECORD = 128 * 1024
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_NAVIGATION_SOURCE_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def parse(raw):
    def pairs(items):
        value = {}
        for key, item in items:
            need(key not in value, 'RECORD_DUPLICATE_KEY')
            value[key] = item
        return value
    def invalid(_):
        raise Stop('GO_ERP_NAVIGATION_SOURCE_RECORD_NONFINITE')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    need(isinstance(value, dict), 'RECORD_INVALID')
    return value


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
        value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def parent_fd(path):
    path = Path(path)
    need(path.is_absolute() and all(part not in ('.', '..') for part in path.parts), 'PATH_INVALID')
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


def _read_file(path, owners, limit=MAX_FILE, optional=False, private=False,
        cache_identity=None):
    parent = parent_fd(path)
    try:
        try:
            before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
        except FileNotFoundError:
            if optional:
                return None
            raise Stop('GO_ERP_NAVIGATION_SOURCE_FILE_MISSING') from None
        owned_cache = (not private and cache_identity is not None
            and (before.st_uid, before.st_gid) == cache_identity
            and stat.S_IMODE(before.st_mode) == 0o664)
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
            and before.st_uid in owners and (not before.st_mode & 0o022 or owned_cache)
            and not before.st_mode & (stat.S_ISUID | stat.S_ISGID | stat.S_ISVTX)
            and 0 <= before.st_size <= limit
            and (not private or stat.S_IMODE(before.st_mode) == 0o600), 'FILE_UNSAFE')
        fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            need(identity(before) == identity(os.fstat(fd)), 'FILE_CHANGED')
            parts, total = [], 0
            while total <= limit:
                part = os.read(fd, min(65536, limit + 1 - total))
                if not part:
                    break
                parts.append(part)
                total += len(part)
            need(total == before.st_size and identity(before) == identity(os.fstat(fd))
                == identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)), 'FILE_CHANGED')
            return b''.join(parts), before
        finally:
            os.close(fd)
    finally:
        os.close(parent)


def read_file(path, owners, limit=MAX_FILE, optional=False, private=False):
    # Source files and root-only audit records always retain the strict policy.
    return _read_file(path, owners, limit, optional, private)


def directory(path, owners, mode=None):
    need(path.is_absolute() and path.resolve() == path, 'DIRECTORY_PATH_INVALID')
    for parent in (*reversed(path.parents), path):
        info = parent.lstat()
        sticky_ancestor = parent != path and bool(info.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(info.st_mode) and info.st_uid in owners
            and (not info.st_mode & 0o022 or sticky_ancestor), 'DIRECTORY_UNSAFE')
    info = path.lstat()
    need(mode is None or stat.S_IMODE(info.st_mode) == mode, 'DIRECTORY_MODE_INVALID')
    return info


def replace_expected(path, raw, expected, verify, advance=False, on_renamed=None):
    """Bound the source parent and recheck its last observed inode before rename."""
    verify()
    parent = parent_fd(path)
    temporary = '.navigation-source-' + uuid.uuid4().hex
    try:
        parent_info = os.fstat(parent)
        need(identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)) == identity(expected), 'TARGET_CHANGED')
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw)
            stream.flush()
            os.fchown(stream.fileno(), expected.st_uid, expected.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(expected.st_mode))
            if advance:
                stamp = max(time.time_ns(), (expected.st_mtime_ns // 1000000000 + 1) * 1000000000)
                os.utime(stream.fileno(), ns=(stamp, stamp))
            os.fsync(stream.fileno())
            written = os.fstat(stream.fileno())
        verify()
        named = parent_fd(path)
        try:
            need((os.fstat(named).st_dev, os.fstat(named).st_ino) == (parent_info.st_dev, parent_info.st_ino), 'PARENT_CHANGED')
        finally:
            os.close(named)
        need(identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)) == identity(expected), 'TARGET_CHANGED')
        os.replace(temporary, path.name, src_dir_fd=parent, dst_dir_fd=parent)
        # Rename has changed the visible source even if the following durable
        # directory checkpoint fails. Register its exact owned inode first.
        if on_renamed is not None:
            on_renamed((written.st_dev, written.st_ino))
        os.fsync(parent)
    finally:
        try:
            os.unlink(temporary, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


def write_record(path, raw, verify, on_renamed=None):
    need(isinstance(raw, bytes) and len(raw) <= max(MAX_FILE, MAX_RECORD), 'AUDIT_BYTES_INVALID')
    verify()
    directory(path.parent, {0}, 0o700)
    existing = read_file(path, {0}, max(MAX_FILE, MAX_RECORD), optional=True, private=True)
    if existing is not None:
        need(existing[0] == raw, 'AUDIT_CHANGED')
        return
    parent = parent_fd(path)
    temporary = '.navigation-audit-' + uuid.uuid4().hex
    try:
        parent_info = os.fstat(parent)
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw)
            stream.flush()
            os.fchown(stream.fileno(), 0, 0)
            os.fchmod(stream.fileno(), 0o600)
            os.fsync(stream.fileno())
            written = os.fstat(stream.fileno())
        verify()
        named = parent_fd(path)
        try:
            need((os.fstat(named).st_dev, os.fstat(named).st_ino) == (parent_info.st_dev, parent_info.st_ino), 'AUDIT_PARENT_CHANGED')
        finally:
            os.close(named)
        need(not os.path.lexists(path), 'AUDIT_TARGET_APPEARED')
        os.replace(temporary, path.name, src_dir_fd=parent, dst_dir_fd=parent)
        if on_renamed is not None:
            on_renamed((written.st_dev, written.st_ino))
        os.fsync(parent)
    finally:
        try:
            os.unlink(temporary, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


def remove_owned_record(path, raw, inode, verify):
    """Remove only this invocation's uncommitted receipt, never a peer record."""
    value = read_file(path, {0}, MAX_RECORD, optional=True, private=True)
    if value is None:
        return
    current, info = value
    need(inode is not None and current == raw
        and (info.st_dev, info.st_ino) == inode, 'COMMIT_RECEIPT_CHANGED')
    verify()
    parent = parent_fd(path)
    try:
        parent_info = os.fstat(parent)
        named = parent_fd(path)
        try:
            need((os.fstat(named).st_dev, os.fstat(named).st_ino)
                == (parent_info.st_dev, parent_info.st_ino), 'AUDIT_PARENT_CHANGED')
        finally:
            os.close(named)
        need(identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False))
            == identity(info), 'COMMIT_RECEIPT_CHANGED')
        os.unlink(path.name, dir_fd=parent)
        os.fsync(parent)
    finally:
        os.close(parent)


def checked_plan(release, pins, payloads):
    need(isinstance(release, str) and REV.fullmatch(release), 'RELEASE_INVALID')
    need(isinstance(pins, dict) and set(pins) == ALLOWED_PATHS
        and isinstance(payloads, dict) and set(payloads) == set(pins), 'PLAN_SCOPE_INVALID')
    for name, pin in pins.items():
        need(isinstance(pin, dict) and set(pin) == {'before', 'after'}
            and all(isinstance(value, str) and SHA.fullmatch(value) for value in pin.values())
            and pin['before'] != pin['after'] and isinstance(payloads[name], bytes)
            and 0 < len(payloads[name]) <= MAX_FILE and sha(payloads[name]) == pin['after'], 'PLAN_PIN_INVALID')
    return {'format': 1, 'kind': 'go-erp-navigation-source-plan', 'release': release,
        'paths': {name: dict(pins[name]) for name in sorted(pins)}}


def compiled_path(root, name):
    # The existing pinned Laravel8.83.29 compiler uses sha1('v2'.$path).
    # Only this exact reviewed Blade source can contribute a compiled path.
    need(name in ALLOWED_PATHS and name.endswith('.blade.php'), 'COMPILED_SOURCE_SCOPE_INVALID')
    key = hashlib.sha1(('v2' + str(root / name)).encode()).hexdigest()
    return root / 'storage/framework/views' / (key + '.php')


def compiled_directory(root, owner_uid, cache_owner_gid):
    """Allow the application's exact writable Blade cache chain, not source paths.

    The coordinator supplies the application's primary GID from its trusted
    account record. Only these three application-owned directories may use
    0775; every ancestor keeps the original directory policy.
    """
    path = root / 'storage/framework/views'
    need(path.is_absolute() and path.resolve() == path, 'DIRECTORY_PATH_INVALID')
    cache_paths = {root / 'storage', root / 'storage/framework', path}
    for parent in (*reversed(path.parents), path):
        info = parent.lstat()
        sticky_ancestor = parent != path and bool(info.st_mode & stat.S_ISVTX)
        owned_cache = (parent in cache_paths and cache_owner_gid is not None
            and (info.st_uid, info.st_gid) == (owner_uid, cache_owner_gid)
            and stat.S_IMODE(info.st_mode) == 0o775)
        need(stat.S_ISDIR(info.st_mode) and info.st_uid in {0, owner_uid}
            and (not info.st_mode & 0o022 or sticky_ancestor or owned_cache)
            and (parent not in cache_paths
                or not info.st_mode & (stat.S_ISUID | stat.S_ISGID | stat.S_ISVTX)), 'DIRECTORY_UNSAFE')


def read_compiled(root, name, owner_uid, cache_owner_gid):
    # This is the only caller that permits 0664, for one derived Blade file.
    # Its marker, regular-file, hardlink, size and identity checks still apply.
    path = compiled_path(root, name)
    cache_identity = None if cache_owner_gid is None else (owner_uid, cache_owner_gid)
    return _read_file(path, {0, owner_uid}, optional=True, cache_identity=cache_identity)


def invalidate_blade(root, names, audit, owner_uid, verify, check_only=False,
        cache_owner_gid=None):
    invalidated = []
    for name in sorted(names):
        if not name.endswith('.blade.php'):
            continue
        path = compiled_path(root, name)
        compiled_directory(root, owner_uid, cache_owner_gid)
        value = read_compiled(root, name, owner_uid, cache_owner_gid)
        if value is None:
            continue
        raw, info = value
        marker = ('/**PATH ' + str(root / name) + ' ENDPATH**/').encode()
        need(raw.rstrip().endswith(marker) or raw.rstrip().endswith(marker + b' ?>'), 'COMPILED_MARKER_INVALID')
        write_record(audit / ('compiled-before-' + sha(name.encode()) + '-' + sha(raw) + '.bin'), raw, verify)
        if check_only:
            continue
        verify()
        parent = parent_fd(path)
        try:
            need(identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)) == identity(info), 'COMPILED_CHANGED')
            os.unlink(path.name, dir_fd=parent)
            os.fsync(parent)
        finally:
            os.close(parent)
        invalidated.append(name)
    return invalidated


def apply(root, audit_parent, release, owner_uid, payloads, *, pins=None, verify_locks=None,
        after_apply=None, after_rollback=None, cache_owner_gid=None):
    """Apply one trusted coordinator's reviewed manifest; never allocate or publish.

    `pins` is the coordinator's immutable code constant, never a web/request
    argument. Explicit injection also permits disposable real-filesystem tests.
    The caller must hold and revalidate all three original rollout locks.
    `after_apply` may enforce a separately reviewed live PHP cache policy; this
    module does not pretend a CLI opcache_reset invalidates PHP-FPM's cache.
    `after_rollback` may reload restored code, but is never called over unknown
    source bytes or before this invocation has replaced any source.
    """
    need(callable(verify_locks), 'HELD_LOCK_VERIFIER_REQUIRED')
    need(after_apply is None or callable(after_apply), 'CACHE_VERIFIER_INVALID')
    need(after_rollback is None or callable(after_rollback), 'ROLLBACK_CACHE_VERIFIER_INVALID')
    def verify():
        need(verify_locks() is not False, 'HELD_LOCK_NOT_VERIFIED')
    verify()
    root, audit_parent = Path(root), Path(audit_parent)
    need(type(owner_uid) is int and owner_uid >= 0, 'OWNER_INVALID')
    need(cache_owner_gid is None or type(cache_owner_gid) is int and cache_owner_gid >= 0,
        'CACHE_OWNER_GID_INVALID')
    if pins is None:
        need(not PINS_PENDING, 'PINS_PENDING')
        pins = SOURCE_PINS
    plan = checked_plan(release, pins, payloads)
    directory(root, {0, owner_uid})
    directory(audit_parent, {0}, 0o700)
    plan['root'] = str(root)
    plan['owner_uid'] = owner_uid
    digest = sha(canonical(plan))
    audit = audit_parent / ('navigation-source-' + digest)
    if not os.path.lexists(audit):
        verify()
        audit.mkdir(mode=0o700)
        os.chown(audit, 0, 0)
        os.chmod(audit, 0o700)
        parent = os.open(audit_parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(parent)
        finally:
            os.close(parent)
    directory(audit, {0}, 0o700)
    intent_path = audit / 'intent.json'
    stored = read_file(intent_path, {0}, MAX_RECORD, optional=True, private=True)
    intent = parse(stored[0]) if stored else None
    if intent is not None:
        need(set(intent) == {'plan', 'files'} and intent['plan'] == plan
            and isinstance(intent['files'], dict) and set(intent['files']) == set(pins), 'INTENT_CHANGED')
    originals, current = {}, {}
    for name in sorted(pins):
        path = root / name
        directory(path.parent, {0, owner_uid})
        raw, info = read_file(path, {0, owner_uid})
        need(sha(raw) in (pins[name]['before'], pins[name]['after']), 'SOURCE_BYTES_UNREVIEWED')
        backup = audit / ('before-' + sha(name.encode()) + '.bin')
        if intent is None:
            need(sha(raw) == pins[name]['before'], 'ALREADY_CHANGED_WITHOUT_INTENT')
            write_record(backup, raw, verify)
            originals[name] = {'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode), 'backup': backup.name}
        else:
            facts = intent['files'][name]
            need(isinstance(facts, dict) and set(facts) == {'uid', 'gid', 'mode', 'backup'}
                and facts['uid'] == info.st_uid and facts['gid'] == info.st_gid
                and facts['mode'] == stat.S_IMODE(info.st_mode) and facts['backup'] == backup.name, 'SOURCE_METADATA_CHANGED')
            before_raw = read_file(backup, {0}, private=True)[0]
            need(sha(before_raw) == pins[name]['before'], 'BACKUP_PIN_CHANGED')
            originals[name] = facts
        current[name] = (raw, info)
    if intent is None:
        intent = {'plan': plan, 'files': originals}
        write_record(intent_path, canonical(intent), verify)
    committed_path = audit / 'committed.json'
    committed = read_file(committed_path, {0}, MAX_RECORD, optional=True, private=True)
    expected_commit = {'format': 1, 'kind': 'go-erp-navigation-source-committed',
        'plan_sha256': digest, 'paths': sorted(pins), 'business_data_changed': False}
    if committed is not None:
        need(parse(committed[0]) == expected_commit and all(sha(raw) == pins[name]['after'] for name, (raw, _) in current.items()), 'COMMITTED_SOURCE_CHANGED')
        verify()
        return {'changed': False, 'audit': str(audit), 'paths': sorted(pins), 'business_data_changed': False}
    invalidate_blade(root, pins, audit, owner_uid, verify, check_only=True,
        cache_owner_gid=cache_owner_gid)
    changed = {}
    owned_commit = []
    try:
        for name in sorted(pins):
            raw, info = current[name]
            if sha(raw) == pins[name]['before']:
                replace_expected(root / name, payloads[name], info, verify, advance=True,
                    on_renamed=lambda inode, name=name: changed.__setitem__(name, inode))
        for name in sorted(pins):
            raw, info = read_file(root / name, {0, owner_uid})
            facts = originals[name]
            need(raw == payloads[name] and (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
                == (facts['uid'], facts['gid'], facts['mode']), 'SOURCE_COMMIT_VERIFY_FAILED')
        invalidate_blade(root, pins, audit, owner_uid, verify, cache_owner_gid=cache_owner_gid)
        if after_apply is not None:
            verify()
            need(callable(after_apply), 'CACHE_VERIFIER_INVALID')
            after_apply()
        verify()
        write_record(committed_path, canonical(expected_commit), verify,
            on_renamed=owned_commit.append)
        return {'changed': bool(changed), 'audit': str(audit), 'paths': sorted(pins), 'business_data_changed': False}
    except BaseException as error:
        # committed.json may already be visible when its directory fsync fails.
        # Remove that exact owned marker before rolling source bytes back;
        # unknown/replaced markers remain untouched and require review.
        commit_removed = True
        try:
            remove_owned_record(committed_path, canonical(expected_commit),
                owned_commit[0] if owned_commit else None, verify)
        except BaseException:
            commit_removed = False
        restored = True
        # A crash before this process started may have left some reviewed after
        # files. Roll back only replacements made by this invocation, never a
        # prior completed/unknown source deployment.
        for name in reversed(changed):
            try:
                raw, info = read_file(root / name, {0, owner_uid})
                facts = originals[name]
                if raw != payloads[name] or (info.st_dev, info.st_ino) != changed[name] or (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) != (facts['uid'], facts['gid'], facts['mode']):
                    restored = False
                    continue
                before_raw = read_file(audit / facts['backup'], {0}, private=True)[0]
                need(sha(before_raw) == pins[name]['before'], 'ROLLBACK_BACKUP_CHANGED')
                replace_expected(root / name, before_raw, info, verify, advance=True)
            except BaseException:
                restored = False
        try:
            invalidate_blade(root, changed, audit, owner_uid, verify, cache_owner_gid=cache_owner_gid)
        except BaseException:
            restored = False
        concurrent = False
        for name in pins:
            try:
                raw, info = read_file(root / name, {0, owner_uid})
                facts = originals[name]
                if sha(raw) not in (pins[name]['before'], pins[name]['after']) or (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) != (facts['uid'], facts['gid'], facts['mode']):
                    concurrent = True
            except BaseException:
                concurrent = True
        runtime_restored = False if after_rollback is not None and changed else None
        if after_rollback is not None and changed and restored and not concurrent and commit_removed:
            try:
                verify()
                after_rollback()
                verify()
                runtime_restored = True
            except BaseException:
                runtime_restored = False
        failure = {'format': 1, 'kind': 'go-erp-navigation-source-failed',
            'plan_sha256': digest, 'restored_own_sources': restored,
            'uncommitted_receipt_removed': commit_removed,
            'runtime_policy_restored': runtime_restored,
            'concurrent_source_retained': concurrent, 'business_data_changed': False}
        # Failure records are content addressed, so later retries retain every
        # earlier result rather than overwriting the audit's previous evidence.
        try:
            write_record(audit / ('failed-' + sha(canonical(failure)) + '.json'), canonical(failure), verify)
        except BaseException:
            pass
        code = str(error) if isinstance(error, Stop) else 'GO_ERP_NAVIGATION_SOURCE_PRIVATE_FAILURE'
        suffix = ('_COMMIT_RECEIPT_REVIEW_REQUIRED' if not commit_removed
            else '_CONCURRENT_SOURCE_REVIEW_REQUIRED' if not restored or concurrent
            else '_RESTORED_RUNTIME_REVIEW_REQUIRED' if runtime_restored is False
            else '_OWN_SOURCES_RESTORED')
        raise Stop(code + suffix) from None


if __name__ == '__main__':
    print('GO_ERP_NAVIGATION_SOURCE_USE_PINNED_COORDINATOR')
    raise SystemExit(1)
