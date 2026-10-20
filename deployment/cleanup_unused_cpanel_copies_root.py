#!/usr/bin/env python3
"""Remove only five identified obsolete cPanel copies after backup/dependency checks."""
import argparse
import datetime
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import sys
import tempfile

import audit_remaining_backup_candidates_root as audit


def need(condition, message):
    if not condition:
        raise audit.AuditError(message)


def snapshot(path, cutoff_ns, original_path=None):
    """Record every inode without following symlinks or accepting mounted trees."""
    path = Path(path)
    recorded_path = str(original_path) if original_path is not None else str(path)
    need(recorded_path in audit.CANDIDATES, 'Path is outside the five-copy whitelist.')
    top = path.lstat()
    need(not stat.S_ISLNK(top.st_mode) and path.resolve() == path,
         'Candidate path or parent became a symlink: ' + str(path))
    need(stat.S_ISREG(top.st_mode) or stat.S_ISDIR(top.st_mode),
         'Candidate has an unexpected type: ' + str(path))
    digest = hashlib.sha256()
    count = [0]

    def record(item):
        info = item.lstat()
        need(info.st_dev == top.st_dev, 'Mounted data inside candidate: ' + str(item))
        need(stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode) or
             stat.S_ISLNK(info.st_mode), 'Special file inside candidate: ' + str(item))
        need(info.st_mtime_ns <= cutoff_ns,
             'Candidate contains data newer than the complete backup: ' + str(item))
        row = [str(item.relative_to(path)), info.st_mode, info.st_dev,
               info.st_ino, info.st_size, info.st_mtime_ns,
               info.st_ctime_ns if item != path else None,
               info.st_uid, info.st_gid, info.st_nlink]
        if stat.S_ISLNK(info.st_mode):
            row.append(os.readlink(str(item)))
        digest.update((json.dumps(row, ensure_ascii=True) + '\n').encode('utf-8'))
        count[0] += 1

    record(path)
    if stat.S_ISDIR(top.st_mode):
        def walk_error(error):
            raise audit.AuditError('Cannot inspect candidate tree: ' + str(error.filename))
        for parent, dirs, files in os.walk(str(path), topdown=True,
                                           followlinks=False, onerror=walk_error):
            dirs.sort()
            for name in sorted(dirs + files):
                record(Path(parent) / name)
    return {'path': recorded_path, 'device': top.st_dev, 'inode': top.st_ino,
            'type': 'directory' if stat.S_ISDIR(top.st_mode) else 'file',
            'root_ctime_ns': top.st_ctime_ns,
            'metadata_sha256': digest.hexdigest(), 'entries': count[0]}


def require_cpanel_copy(path, record, databases=('fasakha_db', 'fasakha_dev_db')):
    """Names alone do not establish that unfamiliar data is an old account copy."""
    path = Path(path)
    if record['type'] == 'file':
        need(record.get('archive_root') == 'cpmove-fasakha',
             'Archive is not a recognized fasakha cPanel copy; retained: ' + str(path))
        return
    root = path / 'cpmove-fasakha' if path.name == 'temp_extract' else path
    need(root.is_dir() and not root.is_symlink() and root.resolve() == root,
         'Unrecognized extracted account root; retained: ' + str(path))
    if path.name == 'temp_extract':
        need(set(record.get('top_level_names', [])) == {'cpmove-fasakha'},
             'Extraction directory has unfamiliar extra data; retained: ' + str(path))
    if str(path) == '/home/cpmove-fasakha' and set(record.get('top_level_names', [])) == {'mysql'}:
        require_sql_only_copy(root / 'mysql', databases)
        return
    markers = [name for name in ('homedir', 'mysql', 'cp', 'userdata', 'version', 'meta')
               if os.path.lexists(str(root / name)) and not (root / name).is_symlink()]
    need(len(markers) >= 2 and any(name in markers for name in ('mysql', 'cp')),
         'Extracted directory lacks cPanel backup structure; retained: ' + str(path))


def require_sql_only_copy(mysql, databases=('fasakha_db', 'fasakha_dev_db')):
    """Recognize the observed partial extraction without accepting arbitrary data."""
    need(mysql.is_dir() and not mysql.is_symlink() and mysql.resolve() == mysql,
         'SQL-only copy has an indirect mysql directory; retained.')
    entries = sorted(mysql.iterdir())
    need(entries, 'SQL-only copy is empty; retain for review.')
    dumps, create_files = set(), set()
    for entry in entries:
        info = entry.lstat()
        need(stat.S_ISREG(info.st_mode) and not entry.is_symlink(),
             'Unrecognized file in SQL-only extraction; retained: ' + str(entry))
        if entry.name == 'openfileslimit':
            need(info.st_size <= 64 and re.fullmatch(br'[0-9]+\s*', entry.read_bytes()),
                 'Unexpected old open-file limit metadata; retained: ' + str(entry))
            continue
        if entry.name.endswith('.create'):
            database = entry.name[:-7]
            need(database in databases and info.st_size <= 65536,
                 'Unexpected old database creation metadata; retained: ' + str(entry))
            content = entry.read_bytes()
            need(b'\0' not in content and os.fsencode(database) in content and
                 re.search(br'\bCREATE\s+DATABASE\b', content, re.I),
                 'Unrecognized old database creation metadata; retained: ' + str(entry))
            create_files.add(database)
            continue
        need(re.fullmatch(r'fasakha_[A-Za-z0-9_$-]+\.sql(?:\.gz)?', entry.name),
             'Unrecognized file in SQL-only extraction; retained: ' + str(entry))
        database = entry.name[:-3] if entry.name.endswith('.gz') else entry.name
        database = database[:-4]
        need(database in databases,
             'SQL-only copy includes a database absent from the complete backup; retained: ' + str(entry))
        opener = gzip.open if entry.name.endswith('.gz') else open
        try:
            with opener(str(entry), 'rb') as stream:
                header = stream.read(8192)
        except (OSError, EOFError):
            raise audit.AuditError('Cannot identify SQL dump; retained: ' + str(entry))
        need(b'-- MySQL dump' in header or b'-- MariaDB dump' in header,
             'SQL-only extraction contains an unrecognized dump; retained: ' + str(entry))
        dumps.add(database)
    need(dumps and create_files.issubset(dumps),
         'Old database creation metadata has no matching recognized SQL dump; retained.')


def cutoff_from_manifest(manifest):
    need(manifest.get('layout') == 'split-home' and manifest.get('account') == 'fasakha',
         'Unexpected complete backup manifest.')
    value = manifest.get('started_utc', '')
    try:
        parsed = datetime.datetime.strptime(value.rstrip('Z'), '%Y-%m-%dT%H:%M:%S.%f')
    except (ValueError, TypeError):
        try:
            parsed = datetime.datetime.strptime(value.rstrip('Z'), '%Y-%m-%dT%H:%M:%S')
        except (ValueError, TypeError, AttributeError):
            raise audit.AuditError('Cannot establish the complete backup start time.')
    parsed = parsed.replace(tzinfo=datetime.timezone.utc)
    return int(parsed.timestamp() * 1000000000)


def no_dependencies(paths):
    dependencies = audit.dependency_check(paths)
    for dependency in dependencies:
        print('DEPENDENCY_FOUND=' + str(dependency), flush=True)
    need(not dependencies, 'A candidate is referenced; remaining old copies retained.')


def write_report(path, report):
    content = (json.dumps(report, indent=2, sort_keys=True) + '\n').encode('utf-8')
    path.write_bytes(content)
    checksum = hashlib.sha256(content).hexdigest() + '  ' + path.name + '\n'
    path.with_name(path.name + '.sha256').write_text(checksum)


def apply_plan(plan, cutoff_ns, extra_report=None):
    """Recheck all identities before renaming any approved path to private staging."""
    need(plan and len({row['path'] for row in plan}) == len(plan), 'Invalid cleanup plan.')
    paths = [row['path'] for row in plan]
    need(set(paths).issubset(set(audit.CANDIDATES)), 'Cleanup plan escaped whitelist.')
    backup = Path(audit.BACKUP)
    need(backup.is_dir() and backup.resolve() == backup and not backup.is_symlink(),
         'Unexpected complete backup location.')
    need(getattr(shutil.rmtree, 'avoids_symlink_attacks', False),
         'Safe directory removal is unavailable; all copies retained.')
    need(cutoff_from_manifest(audit.verify_backup()) == cutoff_ns,
         'The verified backup identity changed.')
    print('RECHECKING_CURRENT_REFERENCES', flush=True)
    no_dependencies(paths)
    for row in plan:
        need(snapshot(row['path'], cutoff_ns) == row,
             'Candidate changed during checks: ' + row['path'])
        need(row['device'] == backup.stat().st_dev,
             'Candidate uses a different filesystem; retained: ' + row['path'])
    staging = Path(tempfile.mkdtemp(prefix='extra-cleanup-staging-', dir=str(backup.parent)))
    need(staging.stat().st_dev == backup.stat().st_dev, 'Unsafe cleanup staging filesystem.')
    before = shutil.disk_usage(str(backup)).free
    report_path = backup / (staging.name + '.json')
    report = {'started_utc': datetime.datetime.utcnow().isoformat() + 'Z',
              'preserved_complete_backup': str(backup), 'planned': plan,
              'removed_paths': [], 'staged_paths': [], 'free_before_bytes': before,
              'status': 'started'}
    if extra_report is not None:
        report['additional_verification'] = extra_report
    write_report(report_path, report)
    try:
        for number, row in enumerate(plan):
            path = Path(row['path'])
            need(snapshot(path, cutoff_ns) == row,
                 'Candidate contents changed immediately before staging: ' + str(path))
            current = path.lstat()
            need(path.resolve() == path and not stat.S_ISLNK(current.st_mode) and
                 current.st_dev == row['device'] and current.st_ino == row['inode'],
                 'Candidate identity changed immediately before cleanup: ' + str(path))
            # Unique prefixes distinguish identically named archives in /home and /root.
            target = staging / ('%02d-' % number + path.name)
            os.rename(str(path), str(target))
            info = target.lstat()
            need(info.st_dev == row['device'] and info.st_ino == row['inode'],
                 'Staged copy identity mismatch; staged data retained.')
            report['staged_paths'].append({'original': str(path), 'staged': str(target)})
            write_report(report_path, report)
            # Rename changes only the top inode ctime. Everything beneath it must
            # still match the complete preflight inventory, including symlinks.
            expected_staged = dict(row, root_ctime_ns=info.st_ctime_ns)
            need(snapshot(target, cutoff_ns, original_path=path) == expected_staged,
                 'Staged contents changed; staged copy retained for review.')
            no_dependencies(paths + [str(target)])
            need(snapshot(target, cutoff_ns, original_path=path) == expected_staged,
                 'Staged contents changed during final checks; retained.')
            if row['type'] == 'directory':
                shutil.rmtree(str(target))
            else:
                target.unlink()
            report['removed_paths'].append(str(path))
            print('REMOVED=' + str(path), flush=True)
        staging.rmdir()
        report['status'] = 'complete'
    except Exception:
        report['status'] = 'stopped'
        # Return an undeleted staged copy to its original name if still available.
        for item in reversed(report['staged_paths']):
            staged = Path(item['staged'])
            original = Path(item['original'])
            if os.path.lexists(str(staged)) and not os.path.lexists(str(original)):
                os.rename(str(staged), str(original))
        print('CLEANUP_STAGING_RETAINED=' + str(staging), flush=True)
        raise
    finally:
        report['finished_utc'] = datetime.datetime.utcnow().isoformat() + 'Z'
        report['free_after_bytes'] = shutil.disk_usage(str(backup)).free
        write_report(report_path, report)
        print('CLEANUP_REPORT=' + str(report_path), flush=True)
    print('OLD_COPIES_REMOVED=' + str(len(report['removed_paths'])), flush=True)
    print('FREED_GIB=%.2f' % ((report['free_after_bytes'] - before) / 1024 ** 3), flush=True)
    print('FREE_SPACE_GIB=%.2f' % (report['free_after_bytes'] / 1024 ** 3), flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--check', action='store_true')
    args = parser.parse_args(argv)
    os.umask(0o077)
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'Run as root only on srv1836524.')
    os.chdir('/')
    with open('/run/lock/fasakhansta-all-apps-backup.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise audit.AuditError('Another backup or cleanup is running.')
        print('VERIFYING_RETAINED_COMPLETE_BACKUP', flush=True)
        manifest = audit.verify_backup()
        cutoff_ns = cutoff_from_manifest(manifest)
        available = audit.candidates()
        if not available:
            print('NO_MATCHING_OLD_COPIES_REMAIN\nCLEANUP_COMPLETE', flush=True)
            return
        paths, retained = [], []
        for path in available:
            # Report type, age and archive structure only. No file contents are printed.
            try:
                record = audit.inspect_candidate(path)
                print('CANDIDATE=' + json.dumps(record, sort_keys=True), flush=True)
                require_cpanel_copy(path, record, manifest['database_verification'])
            except (audit.AuditError, OSError) as error:
                retained.append(path)
                print('RETAINED_UNRECOGNIZED_COPY=' + path, flush=True)
                reason = str(error) if isinstance(error, audit.AuditError) else type(error).__name__
                print('RETAINED_REASON=' + reason, flush=True)
                continue
            paths.append(path)
        if not paths:
            print('NO_RECOGNIZED_OLD_COPIES\nCLEANUP_PARTIAL', flush=True)
            return
        print('CHECKING_OLD_COPY_DEPENDENCIES', flush=True)
        no_dependencies(paths)
        plan = [snapshot(path, cutoff_ns) for path in paths]
        print('PRESERVED_BACKUP=' + str(audit.BACKUP), flush=True)
        if args.apply:
            apply_plan(plan, cutoff_ns)
            print('CLEANUP_PARTIAL' if retained else 'CLEANUP_COMPLETE', flush=True)
        else:
            print('CHECK_COMPLETE\nAUDIT_ONLY=NO_FILES_REMOVED', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        label = str(error) if isinstance(error, audit.AuditError) else type(error).__name__
        print('CLEANUP_STOPPED: ' + label, file=sys.stderr, flush=True)
        sys.exit(1)
