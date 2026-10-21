#!/usr/bin/env python3
"""Archive the two observed backup families after a complete projected scan."""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import sys
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
BACKUPS = Path('/home/fasakha/desktop-dashboard-release-backups')
AUDITS = Path('/var/lib/go-erp-meta-backup-archives')
UID, GID = 1001, 1003
PARENT = '713a58ef48928f0b4143c80d12619f202c88561c'
SUFFIX = '.bak-meta-20261020-044957'
SUFFIXES = (SUFFIX, '.bak')
SELF = 'deployment/archive_go_erp_meta_backups_root.py'
PINS = {'deployment/finalize_go_erp_publication_links_root.py': '795cf1ea467143e8a8ba0d4b6213a0c5ab36e741',
        'deployment/diagnose_go_erp_live_watcher_root.py': '1842302a87b7e93253ad5be616c3ab3e56bdd4a1',
        'deployment/repair_go_erp_publication_ownership_root.py': '6ceff5c798411e42ca4d7673764eb72ded3ed2e6'}
REV = re.compile(r'[a-f0-9]{40}\Z')
MAXIMUM = 8*1024*1024
MAX_TOTAL = 256*1024*1024
PHASES = frozenset(('AFTER_SOURCE', 'DEPENDENCIES', 'PRIOR_CAPTURES', 'PLAN',
    'PLAN_VERIFY', 'PROJECTED_SOURCE', 'PRE_ARCHIVE', 'ARCHIVE_COPY',
    'PRE_CAPTURE', 'CAPTURE', 'POST_ARCHIVE', 'STATE', 'ENTRY'))
# Literal reasons from the immutable 795cf publication primitives. A foreign
# exception merely called Stop must never gain permission to print its text.
OPS_REASONS = frozenset('''AUDIT_FILE_UNSAFE AUDIT_WRITE_FAILED BACKUP_COUNT_LIMIT
BACKUP_DIRECTORY_UNSAFE CAPTURED_ALIAS_CHANGED CAPTURED_ALIAS_UNEXPECTED
CAPTURE_BINDING_INVALID CAPTURE_FILESYSTEM_INVALID DIRECTORY_CHANGED
DIRECTORY_MODE_INVALID DIRECTORY_OWNER_INVALID DIRECTORY_UNSAFE
ENTRYPOINT_NOT_REVIEWED EXACT_AFTER_SOURCE_REQUIRED EXACT_NEW_SOURCE_ROSTER_REQUIRED
FILE_SCOPE_INVALID INSTALL_LOCK_CHANGED INSTALL_LOCK_UNSAFE LIVE_SOURCE_CHANGED
LIVE_SOURCE_METADATA_INVALID LIVE_SOURCE_PIN_INVALID NEW_SOURCE_PATH_INVALID
NEW_SOURCE_ROSTER_INVALID OWNER_HELPER_NOT_IN_RELEASE OWNER_HELPER_PIN_INVALID
PENDING_CAPTURE_REQUIRES_PRIVATE_REVIEW PINNED_INVOCATION_REQUIRED
PRIVATE_ALIAS_CHANGED PRIVATE_ALIAS_NOT_UNIQUE PRIVATE_FILE_CHANGED
PRIVATE_FILE_MODE_INVALID PRIVATE_FILE_OWNER_INVALID PRIVATE_FILE_UNSAFE
PROJECT_ACCOUNT_UNSAFE PUBLICATION_COUNT_LIMIT PUBLICATION_ENTRY_UNSAFE
REVIEWED_GIT_UNAVAILABLE ROOT_AND_HOST_REQUIRED SUCCESSFUL_BACKUP_NOT_UNIQUE
SUCCESS_BACKUP_ACTIVATION_INVALID SUCCESS_BACKUP_RECEIPT_INVALID
SUCCESS_BACKUP_ROSTER_INVALID SUCCESS_BACKUP_SOURCE_INVALID
UNREVIEWED_SOURCE_METADATA'''.split())


class Stop(Exception):
    pass


def need(condition, reason):
    if not condition:
        raise Stop('GO_ERP_META_BACKUP_' + reason)


def checkpoint(phase, action, *args, **kwargs):
    """Annotate an existing exception without changing the operation or guard."""
    try:
        return action(*args, **kwargs)
    except Exception as error:
        if not hasattr(error, 'go_erp_archive_phase'):
            error.go_erp_archive_phase = phase
        raise


def safe_source_path(value):
    if not isinstance(value, str) or not re.fullmatch(
            r'(?:app|bootstrap|config|database|desktop|resources|routes|public)(?:/[A-Za-z0-9_.@-]+)*', value):
        return False
    parts = value.split('/')
    if any(part.startswith('.') or re.search(r'credentials|service[-_]account|firebase|error_log|\.(?:key|pem)(?:[._-]|$)', part, re.I)
           for part in parts):
        return False
    return not value.startswith(('bootstrap/cache', 'public/uploads', 'public/storage', 'public/node_modules'))


def annotate_plan_error(error, stem, ops):
    error.go_erp_archive_phase = 'PLAN'
    relative = str(Path(stem).parent)
    if safe_source_path(relative):
        error.go_erp_archive_path = relative
    if not isinstance(error, ops['Stop']) or str(error) not in {
            'DIRECTORY_UNSAFE', 'DIRECTORY_OWNER_INVALID', 'DIRECTORY_MODE_INVALID', 'DIRECTORY_CHANGED'}:
        return
    trace = error.__traceback__
    while trace is not None:
        frame = trace.tb_frame
        if frame.f_code is ops['Dirs'].__init__.__code__:
            value = frame.f_locals.get('s')
            if value is not None and stat.S_ISDIR(value.st_mode):
                error.go_erp_archive_metadata = {'uid': value.st_uid, 'gid': value.st_gid,
                    'mode': format(stat.S_IMODE(value.st_mode), '04o')}
                component = frame.f_locals.get('part')
                if isinstance(component, str) and re.fullmatch(r'[A-Za-z0-9_@-]{1,100}', component):
                    error.go_erp_archive_component = component
        trace = trace.tb_next


def diagnostic_failure(error, diagnostic, source, worker, ops, context=None):
    phase = getattr(error, 'go_erp_archive_phase', 'ENTRY')
    if phase not in PHASES:
        phase = 'ENTRY'
    result = diagnostic['failure'](error, phase, source, worker)
    if isinstance(error, ops['Stop']) and str(error) in OPS_REASONS:
        result['reason'] = 'GO_ERP_META_BACKUP_GUARD_' + str(error)
    elif (context is not None and isinstance(error, context['complete']['Stop'])
          and str(error) == 'GO_ERP_COMPLETE_SERVER_STATE_CHANGED_DURING_INSPECTION'):
        result['reason'] = 'GO_ERP_META_BACKUP_GUARD_SERVER_STATE_CHANGED_DURING_INSPECTION'
    path = getattr(error, 'go_erp_archive_path', None)
    if safe_source_path(path):
        result['safe_path'] = path
        metadata = getattr(error, 'go_erp_archive_metadata', None)
        if (isinstance(metadata, dict) and set(metadata) == {'uid', 'gid', 'mode'}
                and all(type(metadata[key]) is int and 0 <= metadata[key] <= 4294967295 for key in ('uid', 'gid'))
                and isinstance(metadata['mode'], str) and re.fullmatch(r'[0-7]{4}', metadata['mode'])):
            result['directory_metadata'] = metadata
        component = getattr(error, 'go_erp_archive_component', None)
        if isinstance(component, str) and re.fullmatch(r'[A-Za-z0-9_@-]{1,100}', component):
            result['directory_component'] = component
    count = getattr(error, 'go_erp_planned_backups', None)
    if type(count) is int and 0 <= count <= 20000:
        result['planned_backups'] = count
    return result



def unclassified_inventory(source, root, seed, planned_names=()):
    """Bounded metadata-only supplement; it never authorizes an archive path."""
    report = {'unclassified_additions': [], 'unclassified_count': 0,
              'redacted_paths': 0, 'inventory_complete': False}
    root_fd = None
    try:
        roster = source._git(seed, ['ls-tree', '-r', '-z', '--name-only', 'HEAD']).split(b'\0')
        need(len(roster) <= 100001, 'INVENTORY_LIMIT')
        seed_names = {name.decode('utf-8') for name in roster if name}
        planned = set(planned_names)
        root_fd, ancestry = source._open_root(root)
        entries = 0
        truncated = False
        def visit(fd, prefix=''):
            nonlocal entries, truncated
            before = source._identity(os.fstat(fd))
            for component in sorted(os.listdir(fd)):
                entries += 1
                need(entries <= 100000, 'INVENTORY_LIMIT')
                name = prefix+component
                if not prefix and component not in source.ROOTS and component != 'artisan':
                    continue
                # Exclude private/data/cache names before any metadata lookup.
                if source._excluded(name):
                    continue
                value = os.stat(component, dir_fd=fd, follow_symlinks=False)
                if stat.S_ISDIR(value.st_mode):
                    child = os.open(component, source._DIR_FLAGS, dir_fd=fd)
                    try:
                        need(source._identity(os.fstat(child)) == source._identity(value), 'INVENTORY_UNSTABLE')
                        visit(child, name+'/')
                        need(source._identity(os.fstat(child)) == source._identity(value), 'INVENTORY_UNSTABLE')
                    finally:
                        os.close(child)
                    continue
                if not stat.S_ISREG(value.st_mode):
                    truncated = True
                    continue
                if name in planned or name in seed_names or name == 'artisan':
                    continue
                if (Path(name).suffix.lower() in source.NEW_CODE_SUFFIXES
                        and re.fullmatch(r'[A-Za-z0-9_./@-]+', name)):
                    continue
                if name.startswith('public/') and Path(name).suffix.lower() in source.PUBLIC_MEDIA_SUFFIXES:
                    continue
                report['unclassified_count'] += 1
                if not safe_source_path(name):
                    report['redacted_paths'] += 1
                elif len(report['unclassified_additions']) < 100:
                    report['unclassified_additions'].append(name)
                else:
                    truncated = True
            need(source._identity(os.fstat(fd)) == before, 'INVENTORY_UNSTABLE')
        visit(root_fd)
        check_fd, after = source._open_root(root)
        os.close(check_fd)
        need(after == ancestry, 'INVENTORY_UNSTABLE')
        report['inventory_complete'] = not truncated
    except Exception:
        # The original failure remains authoritative; never expose new exceptions.
        report['inventory_complete'] = False
    finally:
        if root_fd is not None:
            os.close(root_fd)
    return report


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


def exact(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def unchanged(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns)


def content(fd, before):
    need(unchanged(os.fstat(fd)) == unchanged(before), 'FILE_CHANGED')
    os.lseek(fd, 0, os.SEEK_SET)
    chunks, count = [], 0
    while count <= MAXIMUM:
        data = os.read(fd, min(65536, MAXIMUM+1-count))
        if not data:
            break
        chunks.append(data); count += len(data)
    need(count == before.st_size and count <= MAXIMUM
         and unchanged(os.fstat(fd)) == unchanged(before), 'FILE_CHANGED')
    return b''.join(chunks)


def exact_backup_present(root, relative):
    """Inspect the exact backup name without imposing mutation guards on siblings."""
    need(root.is_absolute() and '..' not in root.parts
         and not Path(relative).is_absolute() and '..' not in Path(relative).parts, 'BACKUP_LOOKUP_SCOPE_INVALID')
    flags = os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW
    fd = os.open('/', flags)
    try:
        for part in root.parts[1:]+Path(relative).parts[:-1]:
            following = os.open(part, flags, dir_fd=fd)
            os.close(fd); fd = following
        os.stat(Path(relative).name, dir_fd=fd, follow_symlinks=False)
        return True
    except FileNotFoundError:
        return False
    finally:
        os.close(fd)


def plan(root, manifest, ops):
    stems = {row['path'] for row in manifest['sources']+manifest['protected'] if row['path'].endswith('.php')}
    need(0 < len(stems) <= 20000, 'STEM_ROSTER_INVALID')
    rows, parents, retained = [], {}, set()
    try:
        for stem, suffix in ((stem, suffix) for stem in sorted(stems) for suffix in SUFFIXES):
            need(re.fullmatch(r'(?:app|bootstrap|config|database|desktop|resources|routes|public)/[A-Za-z0-9_./@-]+\.php', stem)
                 and '..' not in Path(stem).parts, 'STEM_SCOPE_INVALID')
            name = stem+suffix
            if not exact_backup_present(root, name):
                continue
            parent_path = (root/name).parent
            if parent_path not in parents:
                parents[parent_path] = ops['Dirs'](parent_path)
            parent = parents[parent_path]; parent.verify()
            try:
                before = os.stat(Path(name).name, dir_fd=parent.fd, follow_symlinks=False)
            except FileNotFoundError:
                if parent_path not in retained:
                    parent.close(); del parents[parent_path]
                continue
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
                 and (before.st_uid, before.st_gid) in ((0,0),(UID,GID))
                 and not before.st_mode & (0o002|0o7000) and 0 <= before.st_size <= MAXIMUM, 'BACKUP_METADATA_INVALID')
            need(len(rows) < 2000, 'BACKUP_ROSTER_LIMIT')
            fd = os.open(Path(name).name, os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK, dir_fd=parent.fd)
            row = {'path': name, 'name': Path(name).name, 'parent': parent, 'fd': fd, 'before': before}
            rows.append(row)
            retained.add(parent_path)
            need(exact(os.fstat(fd)) == exact(before), 'BACKUP_REPLACED')
            data = content(fd, before)
            need(exact(os.fstat(fd)) == exact(before)
                 == exact(os.stat(row['name'], dir_fd=parent.fd, follow_symlinks=False)), 'BACKUP_REPLACED')
            row['blob'] = blob(data)
        for parent in parents.values():
            parent.verify()
        return rows, parents
    except BaseException as error:
        if isinstance(error, Exception) and 'stem' in locals():
            annotate_plan_error(error, stem, ops)
        for row in rows:
            os.close(row['fd'])
        for parent in parents.values():
            parent.close()
        raise


def verify(row):
    row['parent'].verify()
    need(exact(os.fstat(row['fd'])) == exact(row['before'])
         == exact(os.stat(row['name'], dir_fd=row['parent'].fd, follow_symlinks=False)), 'BACKUP_REPLACED')
    need(blob(content(row['fd'], row['before'])) == row['blob'], 'BACKUP_BYTES_CHANGED')


def projected(source, root, seed, previous, names):
    original = source._excluded
    try:
        source._excluded = lambda name: name in names or original(name)
        snapshot = source.scan_snapshot(root, seed, UID, previous)
        need(not names.intersection(snapshot['metadata']['inventory']), 'BACKUP_RETAINED_IN_SOURCE')
        return snapshot['metadata']
    finally:
        source._excluded = original


def prior_captures(root, backups, manifest, audit_root, ops):
    """Resume only captures whose originals and independent copies remain proven."""
    parent = ops['Dirs'](backups,(UID,GID),0o700)
    stems = {row['path'] for row in manifest['sources']+manifest['protected'] if row['path'].endswith('.php')}
    proven, deferred = set(), []
    def custody(row):
        return tuple(row[key] for key in ('path','blob','device','inode','uid','gid','mode','bytes','mtime_ns'))
    try:
        names = os.listdir(parent.fd)
        need(len(names) <= 2000,'ARCHIVE_COUNT_LIMIT')
        for name in names:
            if not re.fullmatch(r'\.go-erp-meta-backup-archive-[a-f0-9]{32}',name):
                continue
            capture_path = backups/name
            capture = ops['Dirs'](capture_path,(0,0),0o700)
            audit = copies = None
            try:
                record = json.loads(ops['read_at'](capture,'plan.json',owner=(0,0),mode=0o600))
                need(isinstance(record,dict) and type(record.get('format')) is int
                     and record.get('kind') == 'go-erp-meta-backup-archive'
                     and record.get('parent_release') == PARENT
                     and record.get('manifest_blob') == 'da47b5e5dc50676d6046a6511b3905a0cd053ed1'
                     and record.get('capture') == str(capture_path),'PRIOR_CAPTURE_BINDING_INVALID')
                legacy = (record['format'] == 1 and record.get('suffix') == SUFFIX
                          and 'suffixes' not in record)
                current = (record['format'] == 2 and record.get('suffixes') == list(SUFFIXES)
                           and 'suffix' not in record)
                need(legacy or current, 'PRIOR_CAPTURE_FAMILY_BINDING_INVALID')
                # Each private plan authorizes only its original family roster.
                # A format-1 plan can never gain the newly observed .bak scope.
                record_suffixes = (SUFFIX,) if legacy else SUFFIXES
                allowed = {stem+suffix for stem in stems for suffix in record_suffixes}
                audit_path = Path(record.get('audit',''))
                need(audit_path.parent == audit_root and re.fullmatch(r'archive-[a-f0-9]{32}',audit_path.name),'PRIOR_AUDIT_SCOPE_INVALID')
                audit = ops['Dirs'](audit_path,(0,0),0o700)
                need(json.loads(ops['read_at'](audit,'planned.json',owner=(0,0),mode=0o600)) == record,'PRIOR_AUDIT_PLAN_INVALID')
                copies = ops['Dirs'](audit_path/'copies',(0,0),0o700)
                rows = record.get('rows')
                need(isinstance(rows,list) and 0 < len(rows) <= 2000,'PRIOR_CAPTURE_ROSTER_INVALID')
                expected_names = {'plan.json'}
                for index,row in enumerate(rows):
                    need(isinstance(row,dict) and row.get('path') in allowed and REV.fullmatch(row.get('blob',''))
                         and row.get('capture') == 'original-{:04d}.bin'.format(index)
                         and row.get('copy') == 'copies/{:04d}.bin'.format(index),'PRIOR_CAPTURE_ROW_INVALID')
                    def validate_file(directory,filename,copy=False):
                        fd = os.open(filename,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
                        try:
                            before = os.fstat(fd)
                            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size == row['bytes']
                                 and 0 <= before.st_size <= MAXIMUM,'PRIOR_CAPTURE_FILE_INVALID')
                            if copy:
                                need((before.st_uid,before.st_gid,stat.S_IMODE(before.st_mode)) == (0,0,0o600),'PRIOR_COPY_METADATA_INVALID')
                            else:
                                need((before.st_dev,before.st_ino,before.st_uid,before.st_gid,stat.S_IMODE(before.st_mode),before.st_mtime_ns)
                                     == (row['device'],row['inode'],row['uid'],row['gid'],int(row['mode'],8),row['mtime_ns']),'PRIOR_CAPTURE_IDENTITY_INVALID')
                            need(blob(content(fd,before)) == row['blob']
                                 and exact(os.fstat(fd)) == exact(before)
                                 == exact(os.stat(filename,dir_fd=directory.fd,follow_symlinks=False)),'PRIOR_CAPTURE_BYTES_INVALID')
                            directory.verify()
                        finally:
                            os.close(fd)
                    validate_file(copies,Path(row['copy']).name,True)
                    source_parent = ops['Dirs']((root/row['path']).parent)
                    try:
                        captured = row['capture'] in os.listdir(capture.fd)
                        if captured:
                            expected_names.add(row['capture']); validate_file(capture,row['capture'])
                            proven.add(custody(row))
                            try:
                                os.stat(Path(row['path']).name,dir_fd=source_parent.fd,follow_symlinks=False)
                            except FileNotFoundError:
                                pass
                            else:
                                raise Stop('GO_ERP_META_BACKUP_PRIOR_SOURCE_REAPPEARED')
                        else:
                            try:
                                validate_file(source_parent,Path(row['path']).name)
                            except FileNotFoundError:
                                # A successful later retry may hold this exact
                                # original in another independently proven plan.
                                deferred.append(custody(row))
                    finally:
                        source_parent.close()
                need(set(os.listdir(capture.fd)) == expected_names,'PRIOR_CAPTURE_UNKNOWN_ENTRY')
                capture.verify(); copies.verify(); audit.verify()
            finally:
                for directory in (copies,audit,capture):
                    if directory:
                        directory.close()
        need(all(item in proven for item in deferred),'PRIOR_ORIGINAL_CUSTODY_UNPROVEN')
        parent.verify()
    finally:
        parent.close()


def run_archive(root, backups, manifest, source, seed, previous=None, requested=False,
                audit_root=AUDITS, guard=lambda: None, dependencies=lambda: None,
                ops=None, before_capture=lambda row: None, lock_guard=lambda: None):
    need(ops is not None, 'REVIEWED_PRIMITIVES_REQUIRED')
    report = {'format': 1, 'kind': 'go-erp-meta-backup-archive', 'application_contents_changed': False,
              'database_changed': False, 'credentials_read': False, 'captured': 0, 'copied': 0}
    checkpoint('AFTER_SOURCE', guard); checkpoint('AFTER_SOURCE', lock_guard)
    initial_dependencies = checkpoint('DEPENDENCIES', dependencies)
    checkpoint('PRIOR_CAPTURES', prior_captures, root, backups, manifest, audit_root, ops)
    rows, parents = checkpoint('PLAN', plan, root, manifest, ops)
    audit = copies = capture = audit_parent = backup_parent = None
    phase = 'PLAN_VERIFY'
    try:
        names = {row['path'] for row in rows}
        report['pending'] = len(rows)
        total = sum(row['before'].st_size for row in rows)
        need(total <= MAX_TOTAL,'TOTAL_BYTES_LIMIT')
        need(previous is None or isinstance(previous,dict), 'PREVIOUS_METADATA_INVALID')
        need(previous is None or not names.intersection(previous.get('authoritativeLiveFiles',{})), 'BACKUP_PREVIOUSLY_AUTHORITATIVE')
        for row in rows:
            verify(row)
        phase = 'PROJECTED_SOURCE'
        try:
            expected = projected(source, root, seed, previous, names)
        except Exception as error:
            error.go_erp_planned_backups = len(rows)
            error.go_erp_planned_names = tuple(sorted(names))
            raise
        report['projected_source_files'] = len(expected['inventory'])
        phase = 'PRE_ARCHIVE'
        guard(); lock_guard()
        need(dependencies() == initial_dependencies, 'DEPENDENCIES_CHANGED')
        if not requested or not rows:
            report.update(ok=True, archived=False)
            return report
        phase = 'ARCHIVE_COPY'
        need(shutil.disk_usage(audit_root.parent).free >= total+64*1024*1024,'AUDIT_STORAGE_BLOCKED')
        backup_parent = ops['Dirs'](backups, (UID,GID), 0o700)
        need(all(row['before'].st_dev == os.fstat(backup_parent.fd).st_dev for row in rows), 'CAPTURE_FILESYSTEM_INVALID')
        try:
            os.mkdir(audit_root, 0o700)
        except FileExistsError:
            pass
        audit_parent = ops['Dirs'](audit_root, (0,0), 0o700)
        audit_path = audit_root/('archive-'+uuid.uuid4().hex)
        os.mkdir(audit_path, 0o700)
        audit = ops['Dirs'](audit_path, (0,0), 0o700)
        os.mkdir('copies', 0o700, dir_fd=audit.fd)
        copies = ops['Dirs'](audit_path/'copies', (0,0), 0o700)
        capture_path = backups/('.go-erp-meta-backup-archive-'+uuid.uuid4().hex)
        os.mkdir(capture_path, 0o700)
        capture = ops['Dirs'](capture_path, (0,0), 0o700)
        mapping = []
        for index,row in enumerate(rows):
            verify(row)
            copy_name = '{:04d}.bin'.format(index)
            ops['write_at'](copies, copy_name, content(row['fd'], row['before']))
            mapping.append({'path': row['path'], 'blob': row['blob'], 'device': row['before'].st_dev,
                'inode': row['before'].st_ino, 'uid': row['before'].st_uid, 'gid': row['before'].st_gid,
                'mode': format(stat.S_IMODE(row['before'].st_mode),'04o'), 'bytes': row['before'].st_size,
                'mtime_ns': row['before'].st_mtime_ns, 'copy': 'copies/'+copy_name,
                'capture': 'original-{:04d}.bin'.format(index)})
        record = {'format': 2, 'kind': report['kind'], 'suffixes': list(SUFFIXES), 'parent_release': PARENT,
            'manifest_blob': 'da47b5e5dc50676d6046a6511b3905a0cd053ed1', 'capture': str(capture_path),
            'audit': str(audit_path), 'rows': mapping}
        ops['json_at'](audit, 'planned.json', record)
        ops['json_at'](capture, 'plan.json', record)
        os.fsync(audit_parent.fd); os.fsync(backup_parent.fd)
        report.update(audit=str(audit_path), capture_directory=str(capture_path), copied=len(rows))
        phase = 'PRE_CAPTURE'
        for row in rows:
            verify(row)
        guard(); lock_guard()
        need(dependencies() == initial_dependencies, 'DEPENDENCIES_CHANGED')
        # All projected guards and complete independent copies precede the first move.
        try:
            phase = 'CAPTURE'
            for index,(row,item) in enumerate(zip(rows,mapping)):
                before_capture(row)
                with ops['critical']():
                    verify(row); capture.verify(); backup_parent.verify(); lock_guard()
                    os.rename(row['name'], item['capture'], src_dir_fd=row['parent'].fd, dst_dir_fd=capture.fd)
                    os.fsync(row['parent'].fd); os.fsync(capture.fd)
                    fd = os.open(item['capture'], os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK, dir_fd=capture.fd)
                    try:
                        held = os.fstat(fd)
                        need(unchanged(held) == unchanged(row['before'])
                             and unchanged(os.stat(item['capture'], dir_fd=capture.fd, follow_symlinks=False)) == unchanged(held), 'CAPTURED_OBJECT_UNEXPECTED')
                        need(blob(content(fd, row['before'])) == row['blob'], 'CAPTURED_BYTES_UNEXPECTED')
                    finally:
                        os.close(fd)
                    # Retain the original inode in root-only custody. Nothing is unlinked.
                    ops['json_at'](audit, 'captured-{:04d}.json'.format(index), {'format':1, **item})
                    report['captured'] += 1
            phase = 'POST_ARCHIVE'
            guard(); lock_guard()
            need(dependencies() == initial_dependencies, 'DEPENDENCIES_CHANGED')
            actual = source.scan_snapshot(root, seed, UID, previous)['metadata']
            need(actual == expected, 'POST_ARCHIVE_SOURCE_CHANGED')
            capture.verify(); backup_parent.verify(); lock_guard()
            ops['json_at'](audit, 'completed.json', {'format':1, 'captured':len(rows), 'source_verified':True})
            report.update(ok=True, archived=True, pending=0)
            return report
        except BaseException:
            try:
                ops['json_at'](audit, 'stopped.json', {'format':1, 'captured':report['captured'], 'all_copies_retained':True})
            except BaseException:
                pass
            error = Stop('GO_ERP_META_BACKUP_ARCHIVE_STOPPED_PRIVATE_AUDIT='+str(audit_path))
            error.go_erp_archive_phase = phase
            raise error from None
    except Exception as error:
        if not hasattr(error, 'go_erp_archive_phase'):
            error.go_erp_archive_phase = phase
        raise
    finally:
        for row in rows:
            os.close(row['fd'])
        for parent in parents.values():
            parent.close()
        for directory in (copies,audit,capture,audit_parent,backup_parent):
            if directory:
                directory.close()


@contextlib.contextmanager
def locked(parent, name, owner, ops):
    directory = ops['Dirs'](parent, owner, 0o700)
    fd = None
    try:
        fd = os.open(name, os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK, dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid) == owner
             and before.st_nlink == 1 and stat.S_IMODE(before.st_mode) == 0o600, 'LOCK_UNSAFE')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('GO_ERP_META_BACKUP_OPERATION_BUSY') from None
        def verify_lock():
            directory.verify()
            need(exact(os.fstat(fd)) == exact(before)
                 == exact(os.stat(name, dir_fd=directory.fd, follow_symlinks=False)), 'LOCK_CHANGED')
        verify_lock()
        yield verify_lock
        verify_lock()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()


def git(arguments, maximum=2*1024*1024):
    env = {'PATH':'/usr/bin:/bin', 'HOME':'/home/fasakha', 'GIT_NO_REPLACE_OBJECTS':'1',
        'GIT_NO_LAZY_FETCH':'1', 'GIT_OPTIONAL_LOCKS':'0', 'GIT_CONFIG_NOSYSTEM':'1', 'GIT_CONFIG_GLOBAL':'/dev/null'}
    result = subprocess.run(['/usr/sbin/runuser','-u','fasakha','--','/usr/bin/env','-i',
        *[key+'='+value for key,value in env.items()], '/usr/bin/git','--no-optional-locks',
        '-c','core.fsmonitor=false','-c','core.hooksPath=/dev/null','-C',str(ROOT),*arguments],
        env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    need(result.returncode == 0 and len(result.stdout) <= maximum and len(result.stderr) <= 65536, 'REVIEWED_GIT_UNAVAILABLE')
    return result.stdout


def execute(args):
    need(len(args) in (2,3) and args[0] == '--release' and REV.fullmatch(args[1])
         and args[2:] in ([],['--archive']), 'PINNED_INVOCATION_REQUIRED')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    actor = pwd.getpwnam('fasakha')
    need((actor.pw_uid,actor.pw_gid) == (UID,GID) and ROOT.resolve() == ROOT, 'PROJECT_ACCOUNT_UNSAFE')
    git(['merge-base','--is-ancestor',PARENT,args[1]],1024)
    modules = []
    for name,pin in PINS.items():
        data = git(['cat-file','blob',pin]); need(blob(data) == pin, 'REVIEWED_HELPER_PIN_INVALID')
        namespace = {'__name__':'reviewed_meta_archive_'+str(len(modules))}
        exec(compile(data,name,'exec'),namespace); modules.append(namespace)
    ops,diagnostic,owner = modules
    for name,pin in PINS.items():
        owner['source_file'](args[1],name,pin)
    entry = Path(__file__).absolute(); parent = ops['Dirs'](entry.parent,(0,0),0o700)
    try:
        need(ops['read_at'](parent,entry.name,owner=(0,0),mode=0o600) == owner['source_file'](args[1],SELF), 'REVIEWED_ENTRY_PIN_INVALID')
    finally:
        parent.close()
    context = owner['reviewed_context']()
    manifest = context['manifest']
    source,worker = diagnostic['load_installed'](diagnostic['INSTALL'])
    head = lambda: owner['git'](['rev-parse','--verify','HEAD'],64).decode('ascii').strip()
    def guard():
        need(context['complete']['classifier'](ROOT,manifest,context['scanner'],context['source'],head) == 'AFTER', 'EXACT_AFTER_SOURCE_REQUIRED')
    with locked(WORK,'watcher.lock',(0,0),ops) as watcher_guard:
        with locked(BACKUPS,'server-install.lock',(UID,GID),ops) as install_guard:
            def locks():
                watcher_guard(); install_guard()
            state_parent = ops['Dirs'](WORK/'state',(0,0),0o700)
            try:
                raw = ops['read_at'](state_parent,'current.json',limit=worker.MAX_JSON,owner=(0,0),mode=0o600)
                state = worker.parse_json(raw); diagnostic['safe_state'](state)
            finally:
                state_parent.close()
            def dependencies():
                value = worker.dependency_snapshot(ROOT,UID)
                need(state.get('dependency_guard') in (None,value), 'DEPENDENCY_GUARD_CHANGED')
                return value
            try:
                return run_archive(ROOT,BACKUPS,manifest,source,WORK/'checkout',state.get('observed'),
                    args[2:] == ['--archive'],AUDITS,guard,dependencies,ops,lock_guard=locks)
            except Exception as error:
                if isinstance(error,Stop):
                    raise
                result = diagnostic_failure(error,diagnostic,source,worker,ops,context)
                if (isinstance(error, source.SourceError)
                        and str(error) == 'GO_ERP_LIVE_SOURCE_UNCLASSIFIED_ADDITION'
                        and result['phase'] == 'PROJECTED_SOURCE'):
                    result.update(unclassified_inventory(source, ROOT, WORK/'checkout',
                        getattr(error, 'go_erp_planned_names', ())))
                return {'format':1,'kind':'go-erp-meta-backup-archive','application_contents_changed':False,
                    'database_changed':False,'credentials_read':False,**result}


def main(args=None, out=sys.stdout):
    try:
        result = execute(sys.argv[1:] if args is None else args)
    except Exception as error:
        reason = str(error) if isinstance(error,Stop) and re.fullmatch(r'GO_ERP_META_BACKUP_[A-Z_]{1,100}',str(error)) else 'GO_ERP_META_BACKUP_PRIVATE_REVIEW_REQUIRED'
        result = {'format':1,'kind':'go-erp-meta-backup-archive','ok':False,'reason':reason,
            'application_contents_changed':False,'database_changed':False,'credentials_read':False}
        phase = getattr(error,'go_erp_archive_phase',None)
        if phase in PHASES:
            result['phase'] = phase
        if isinstance(error,Stop) and re.fullmatch(r'GO_ERP_META_BACKUP_ARCHIVE_STOPPED_PRIVATE_AUDIT=/var/lib/go-erp-meta-backup-archives/archive-[a-f0-9]{32}',str(error)):
            result['audit'] = str(error).split('=',1)[1]
    print(json.dumps(result,sort_keys=True,separators=(',',':')),file=out)
    return 0 if result.get('ok') else 1


if __name__ == '__main__':
    sys.exit(main())
