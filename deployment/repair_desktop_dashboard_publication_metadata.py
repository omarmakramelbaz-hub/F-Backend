#!/usr/bin/env python3
"""Repair metadata for the exact 38 reviewed publication files, preserving bytes."""
import fcntl
import json
import os
from pathlib import Path
import pwd
import re
import signal
import stat
import subprocess
import sys

import repair_desktop_dashboard_gostores_owner as common

SCOPE_FILE = 'desktop_dashboard_publication_metadata_scope.json'
SCOPE_BLOB = 'cf11abae30d20db225fbb08be34bf86d9538b0ae'
MANIFEST_BLOB = '35827afdfa613ebd944847062c2cd5c9b3c96c56'
ROOT = Path('/home/fasakha/public_html')


def release_argument(arguments):
    if len(arguments) != 3 or arguments[1] != '--release' or not re.fullmatch('[a-f0-9]{40}', arguments[2]):
        raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_REQUIRES_PINNED_RELEASE')
    return arguments[2]


def metadata(value):
    return value.st_uid, value.st_gid, stat.S_IMODE(value.st_mode)


def expected(row, name):
    uid, gid, mode = row[name]
    return uid, gid, int(mode, 8)


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def snapshot(fd, pin):
    before = os.fstat(fd)
    if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
            or before.st_size > common.LIMIT or before.st_mode & 0o7000
            or not before.st_mode & 0o400):
        raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_FILE_UNSAFE')
    os.lseek(fd, 0, os.SEEK_SET)
    data = bytearray()
    while len(data) <= common.LIMIT:
        part = os.read(fd, min(65536, common.LIMIT + 1 - len(data)))
        if not part:
            break
        data.extend(part)
    after = os.fstat(fd)
    if common.identity(before) != common.identity(after) or len(data) > common.LIMIT:
        raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_CONCURRENT_CHANGE')
    if common.blob(data) != pin:
        raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SOURCE_CHANGED')
    return after


def assert_named(root, uid, entry, state):
    row, _, fd, parent_states = entry
    path = root / row['path']
    fresh, states = common.open_directories(path.parent, root, uid)
    try:
        named = os.stat(path.name, dir_fd=fresh[-1], follow_symlinks=False)
        # Unrelated directory entries may change ancestor timestamps/link counts.
        # Bind the path to its original directory inodes, owners and permissions.
        if ([value[:5] for value in states] != [value[:5] for value in parent_states]
                or common.identity(named) != common.identity(state)
                or common.identity(snapshot(fd, row['blob'])) != common.identity(state)):
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_CONCURRENT_CHANGE')
    finally:
        for descriptor in reversed(fresh):
            os.close(descriptor)


def repair(root, uid, gid, rows, journal_parent, before_change=None,
           after_row=None, after_change=None, release=None):
    if os.geteuid() != 0:
        raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_REQUIRES_ROOT')
    entries = []
    plans = []
    changed = []
    attempted = []
    initial = {}
    journal = None
    try:
        names = set()
        for row in rows:
            relative = Path(row['path'])
            if relative.is_absolute() or '..' in relative.parts or row['path'] in names:
                raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SCOPE_INVALID')
            names.add(row['path'])
            old, target = expected(row, 'before'), expected(row, 'after')
            if (target[:2] != (uid, gid) or target[2] & 0o7022
                    or old[2] & 0o7000 or not old[2] & 0o400
                    or old[2] & ~0o022 != target[2]
                    or old[:2] not in ((0, 0), (uid, gid))):
                raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SCOPE_INVALID')
            path = root / relative
            descriptors, states = common.open_directories(path.parent, root, uid)
            fd = None
            try:
                fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                             dir_fd=descriptors[-1])
                entry = (row, descriptors, fd, states)
                entries.append(entry)
                current = snapshot(fd, row['blob'])
                initial[row['path']] = current
                if metadata(current) not in (old, target):
                    raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_UNREVIEWED_METADATA')
                assert_named(root, uid, entry, current)
                if metadata(current) != target:
                    plans.append((entry, current))
            except BaseException:
                if fd is None:
                    for descriptor in reversed(descriptors):
                        os.close(descriptor)
                raise
        # All 38 files have passed type, inode, content and metadata checks.
        if not plans:
            for entry in entries:
                assert_named(root, uid, entry, initial[entry[0]['path']])
            return None, []
        journal = common.private_journal(root, uid, journal_parent)
        records = [{'path': entry[0]['path'], 'blob': entry[0]['blob'],
                    'before_uid': original.st_uid, 'before_gid': original.st_gid,
                    'before_mode': format(stat.S_IMODE(original.st_mode), '04o'),
                    'device': original.st_dev, 'inode': original.st_ino,
                    'after_uid': expected(entry[0], 'after')[0],
                    'after_gid': expected(entry[0], 'after')[1],
                    'after_mode': entry[0]['after'][2]}
                   for entry, original in plans]
        common.write_record(journal / 'before.json', {
            'release': release, 'objects': records, 'application_content_changed': False})
        sync_directory(journal)
        if before_change:
            before_change()
        # Recheck every original before the first metadata syscall.
        for entry in entries:
            assert_named(root, uid, entry, initial[entry[0]['path']])
        for index, (entry, original) in enumerate(plans):
            row, _, fd, _ = entry
            target = expected(row, 'after')
            assert_named(root, uid, entry, original)
            with common.blocked_signals():
                attempted.append(fd)
                # A single syscall per row preserves the other metadata fields.
                if (original.st_uid, original.st_gid) != target[:2]:
                    os.fchown(fd, target[0], target[1])
                else:
                    os.fchmod(fd, target[2])
                after = os.fstat(fd)
                # A peer's intervening metadata must never enter our recovery
                # list. An unverified attempted operation remains uncertain.
                if (after.st_dev, after.st_ino, after.st_nlink, after.st_size,
                    after.st_mtime_ns, metadata(after)) != (
                    original.st_dev, original.st_ino, original.st_nlink, original.st_size,
                    original.st_mtime_ns, target):
                    raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_CONCURRENT_CHANGE')
                changed.append((entry, original, after))
            assert_named(root, uid, entry, after)
            os.fsync(fd)
            if after_row:
                after_row(index)
        if after_change:
            after_change()
        captured = {entry[0]['path']: after for entry, _, after in changed}
        for entry in entries:
            row, _, fd, _ = entry
            current = snapshot(fd, row['blob'])
            if metadata(current) != expected(row, 'after'):
                raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_CONCURRENT_CHANGE')
            assert_named(root, uid, entry, captured.get(row['path'], initial[row['path']]))
        common.write_record(journal / 'after.json', {'objects': records, 'verified': True})
        sync_directory(journal)
        return journal, records
    except BaseException as error:
        recovered = len(attempted) == len(changed)
        for entry, original, after in reversed(changed):
            row, _, fd, _ = entry
            try:
                if common.identity(snapshot(fd, row['blob'])) != common.identity(after):
                    recovered = False
                    continue
                assert_named(root, uid, entry, after)
                with common.blocked_signals():
                    if (original.st_uid, original.st_gid) != (after.st_uid, after.st_gid):
                        os.fchown(fd, original.st_uid, original.st_gid)
                    else:
                        os.fchmod(fd, stat.S_IMODE(original.st_mode))
                    os.fsync(fd)
                restored = snapshot(fd, row['blob'])
                if (restored.st_dev, restored.st_ino, restored.st_nlink,
                    restored.st_size, restored.st_mtime_ns, metadata(restored)) != (
                    original.st_dev, original.st_ino, original.st_nlink,
                    original.st_size, original.st_mtime_ns, metadata(original)):
                    recovered = False
                assert_named(root, uid, entry, restored)
            except BaseException:
                recovered = False
        if journal is not None:
            common.write_record(journal / 'stopped.json', {
                'metadata_restored_or_not_started': recovered,
                'application_content_changed': False})
            sync_directory(journal)
        code = str(error) if isinstance(error, common.ReviewRequired) else 'DESKTOP_METADATA_REPAIR_STOPPED'
        raise common.ReviewRequired(code + ('; JOURNAL=' + str(journal) if journal else '')) from None
    finally:
        for _, descriptors, fd, _ in reversed(entries):
            os.close(fd)
            for descriptor in reversed(descriptors):
                os.close(descriptor)


def main():
    lock_fd = None
    descriptors = []
    try:
        if os.geteuid() != 0:
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_REQUIRES_ROOT')
        release = release_argument(sys.argv)
        os.umask(0o077)
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(
            common.ReviewRequired('DESKTOP_METADATA_REPAIR_INTERRUPTED')))
        account = pwd.getpwnam('fasakha')
        uid, gid = account.pw_uid, account.pw_gid
        path = Path(__file__).absolute().with_name(SCOPE_FILE)
        value = path.lstat()
        data = path.read_bytes()
        if (not stat.S_ISREG(value.st_mode) or value.st_uid != 0
                or value.st_nlink != 1 or value.st_mode & 0o022
                or common.blob(data) != SCOPE_BLOB):
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SCOPE_PIN_INVALID')
        scope = json.loads(data)
        if (scope['version'] != 2 or 'release' in scope
                or (uid, gid) != (scope['uid'], scope['gid']) or len(scope['rows']) != 38
                or scope['manifest_blob'] != MANIFEST_BLOB):
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_ACCOUNT_OR_SCOPE_CHANGED')
        parent = ROOT.parent / 'desktop-dashboard-release-backups'
        descriptors, _ = common.open_directories(parent, ROOT, uid)
        value = os.fstat(descriptors[-1])
        if value.st_uid != uid or stat.S_IMODE(value.st_mode) != 0o700:
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_LOCK_PARENT_UNSAFE')
        lock_fd = os.open('server-install.lock', os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK,
                          dir_fd=descriptors[-1])
        value = os.fstat(lock_fd)
        if (not stat.S_ISREG(value.st_mode) or value.st_uid != uid
                or value.st_nlink != 1 or stat.S_IMODE(value.st_mode) != 0o600):
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_LOCK_UNSAFE')
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise common.ReviewRequired('DESKTOP_INSTALL_ALREADY_RUNNING') from None
        command = ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/git', '-C', str(ROOT)]
        env = {**os.environ, 'GIT_OPTIONAL_LOCKS': '0', 'GIT_NO_LAZY_FETCH': '1'}
        result = subprocess.run(command + ['show', release + ':' + common.MANIFEST],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                check=True, timeout=20, env=env)
        if len(result.stdout) > 1024 * 1024 or common.blob(result.stdout) != MANIFEST_BLOB:
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_MANIFEST_PIN_INVALID')
        manifest = json.loads(result.stdout)
        sources = {row['path']: row['before_blob'] for row in manifest['sources']}
        if any(sources.get(row['path']) != row['blob'] for row in scope['rows']):
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SCOPE_NOT_IN_MANIFEST')
        head = subprocess.run(command + ['rev-parse', 'HEAD'], stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL, check=True, timeout=20, env=env)
        if head.stdout.strip().decode() != manifest['expected_head']:
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SERVER_HEAD_CHANGED')
        # Pinned installer modules inspect source only; no PHP or SQL is invoked.
        import apply_desktop_dashboard_server as installer
        if installer.source_fingerprint(ROOT, uid) != manifest['before_source']:
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SERVER_SOURCE_CHANGED')
        journal, records = repair(ROOT, uid, gid, scope['rows'], Path('/root'), release=release)
        print(json.dumps({'kind': 'desktop-reviewed-publication-metadata-repair',
                          'release': release,
                          'changed': records, 'journal': str(journal) if journal else None,
                          'application_content_changed': False, 'database_read': False},
                         separators=(',', ':')))
        blockers = common.inventory(ROOT, uid, set(os.getgrouplist(account.pw_name, gid)), manifest)
        print(json.dumps({'kind': 'desktop-required-owner-inventory', 'blockers': blockers,
                          'repairs_outside_reviewed_scope': 0}, separators=(',', ':')))
        if blockers:
            raise common.ReviewRequired('DESKTOP_OTHER_PUBLICATION_OWNERS_REQUIRE_REVIEW')
        if installer.source_fingerprint(ROOT, uid) != manifest['before_source']:
            raise common.ReviewRequired('DESKTOP_METADATA_REPAIR_SERVER_SOURCE_CHANGED')
        print('DESKTOP_METADATA_REPAIR_READY_FOR_PINNED_INSTALL')
        return 0
    except BaseException as error:
        print(str(error) if isinstance(error, common.ReviewRequired) else
              'DESKTOP_METADATA_REPAIR_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        return 1
    finally:
        if lock_fd is not None:
            os.close(lock_fd)
        for descriptor in reversed(descriptors):
            os.close(descriptor)


if __name__ == '__main__':
    sys.exit(main())
