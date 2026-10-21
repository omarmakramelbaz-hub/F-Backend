#!/usr/bin/env bash
# Default read-only. --repair removes only 0022 from exact approved source files.
set -euo pipefail
exec /usr/bin/python3 -I -B - "$@" <<'PY'
import hashlib
import contextlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import signal
import stat
import subprocess
import sys
import uuid

ROOT = Path('/home/fasakha/public_html')
BACKUPS = Path('/home/fasakha/desktop-dashboard-release-backups')
AUDITS = Path('/root/go-erp-publication-permission-audits')
RELEASE = '6472433078a882b5fa9d1d548b6baa325a89e06c'
PINS = {
    'deployment/desktop_dashboard_server_manifest.json': 'af5c48005b53263e5d9c9d1b717986b54bf496c3',
    'deployment/desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'deployment/diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
}
SAFE = re.compile(r'(?:app|bootstrap|config|database|desktop|resources|routes|public)/[A-Za-z0-9_.@/-]+|artisan')
SECRET = re.compile(r'(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$', re.I)
SUFFIX = re.compile(r'\.(?:php|js|css|json|html|scss|vue)$', re.I)
SHA = re.compile(r'[a-f0-9]{40}\Z')
BACKUP_NAME = re.compile(r'server-[A-Za-z0-9_-]{6,32}\Z')
MAX_OUTPUT = 512 * 1024


class AuditError(Exception):
    pass


def need(value):
    if not value:
        raise AuditError()


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def reviewed_payload(root):
    payload = {}
    for name, pin in PINS.items():
        result = subprocess.run(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i',
            'PATH=/usr/bin:/bin', 'HOME=/home/fasakha', 'GIT_OPTIONAL_LOCKS=0',
            'GIT_NO_LAZY_FETCH=1', 'GIT_NO_REPLACE_OBJECTS=1', '/usr/bin/git',
            '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', str(root),
            'show', RELEASE + ':' + name], stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30, check=False)
        need(result.returncode == 0 and 0 < len(result.stdout) <= 2 * 1024 ** 2
             and blob(result.stdout) == pin)
        payload[name] = result.stdout
    return payload


def validate_manifest(manifest):
    need(isinstance(manifest, dict) and len(manifest['sources']) == 266
         and len(manifest['protected']) == 1087
         and manifest['before_source'] == {'format': 1, 'files': 1164,
             'sha256': '130b4e77cdff3a3b54168ebeb124a27d9bbfc92970d5d3d740c7231bd18ff8fb',
             'framework': '8.83.29'})
    seen = set()
    for row in manifest['sources']:
        name = row['path']
        need(isinstance(name, str) and SAFE.fullmatch(name) and '..' not in Path(name).parts
             and not name.startswith('bootstrap/cache/') and name.lower() not in seen
             and not SECRET.search(name) and (name == 'artisan' or SUFFIX.search(name))
             and (row['before_blob'] is None or SHA.fullmatch(row['before_blob'])))
        seen.add(name.lower())
    for row in manifest['protected']:
        name = row['path']
        need(isinstance(name, str) and SAFE.fullmatch(name) and '..' not in Path(name).parts
             and not name.startswith('bootstrap/cache/') and name.lower() not in seen
             and not SECRET.search(name) and (name == 'artisan' or SUFFIX.search(name))
             and SHA.fullmatch(row['blob']))
        seen.add(name.lower())
    return manifest


def metadata(value):
    return {'uid': value.st_uid, 'gid': value.st_gid,
            'mode': format(stat.S_IMODE(value.st_mode), '04o'), 'links': value.st_nlink}


def external_gui(manifest, source):
    return [row['path'] for row in manifest.get('protected', [])
        if row['path'] != 'artisan' and not any(row['path'].startswith(prefix + '/') for prefix in source['ROOTS'])]


def take_snapshot(root, manifest, scanner, source):
    return scanner['snapshot'](root, source, external_gui(manifest, source), [], lambda: 'read-only-metadata')


def protected_match(snapshot, manifest):
    for row in manifest.get('protected', []):
        value = snapshot['files'].get(row['path'])
        if value is None:
            pair = snapshot['gui'].get(row['path'])
            value = pair[0] if pair is not None else None
        if value is None or value['blob'] != row['blob']:
            return False
    return True


def named_metadata(root_fd, relative, scanner):
    """Use descriptor-relative stat only; never open .env or cache contents."""
    fd = os.dup(root_fd)
    try:
        path = Path(relative)
        need(not path.is_absolute() and '..' not in path.parts)
        for name in path.parts[:-1]:
            following = scanner['open_directory'](fd, name)
            os.close(fd)
            fd = following
        value = os.stat(path.name, dir_fd=fd, follow_symlinks=False)
        return value
    except FileNotFoundError:
        return None
    finally:
        os.close(fd)


def permissions(root, uid, manifest, snapshot, scanner):
    fd = scanner['open_root'](root)
    unsafe, ancestors, observed = [], {}, {}
    try:
        root_metadata = os.fstat(fd)
        observed['.'] = scanner['identity'](root_metadata)[:5]
        if root_metadata.st_uid != uid:
            ancestors['.'] = {'path': '.', **metadata(root_metadata), 'issues': ['owner_mismatch']}
        for row in manifest['sources']:
            name = row['path']
            # Every publication path is in the already-checked source snapshot.
            actual = snapshot['files'].get(name)
            value = named_metadata(fd, name, scanner)
            observed[name] = None if value is None else scanner['identity'](value)
            expected = row['before_blob']
            matches = (actual is None and expected is None
                       or actual is not None and actual['blob'] == expected)
            issues = []
            if value is None:
                if expected is not None:
                    issues.append('required_file_missing')
            else:
                if not stat.S_ISREG(value.st_mode):
                    issues.append('not_regular_file')
                if value.st_uid != uid:
                    issues.append('owner_mismatch')
                if value.st_mode & 0o022:
                    issues.append('group_or_other_writable')
                if value.st_nlink != 1:
                    issues.append('hardlinked_file')
            if not matches:
                issues.append('reviewed_before_blob_mismatch')
            if issues:
                unsafe.append({'path': name, **({} if value is None else metadata(value)),
                    'before_blob_matches': bool(matches), 'issues': issues})
            for parent in Path(name).parents:
                relative = parent.as_posix()
                if relative == '.' or relative in ancestors:
                    continue
                item = named_metadata(fd, relative, scanner)
                observed[relative] = None if item is None else scanner['identity'](item)
                if item is None:
                    ancestors[relative] = None
                else:
                    problems = []
                    if not stat.S_ISDIR(item.st_mode):
                        problems.append('not_directory')
                    if item.st_uid != uid:
                        problems.append('owner_mismatch')
                    ancestors[relative] = ({'path': relative, **metadata(item), 'issues': problems}
                                           if problems else None)
        # Report only metadata for the next installer checkpoints. No content
        # under these private/cache paths is opened or hashed by this audit.
        private_issues = []
        for name in ('.env', 'bootstrap/cache/config.php', 'bootstrap/cache/routes.php',
                     'bootstrap/cache/routes-v7.php', 'storage/framework/views'):
            value = named_metadata(fd, name, scanner)
            observed[name] = None if value is None else scanner['identity'](value)
            if value is None:
                continue
            problems = []
            if not (stat.S_ISDIR(value.st_mode) if name == 'storage/framework/views'
                    else stat.S_ISREG(value.st_mode)):
                problems.append('unsafe_type')
            if value.st_uid != uid:
                problems.append('owner_mismatch')
            if name == '.env' and value.st_mode & 0o022:
                problems.append('group_or_other_writable')
            if problems:
                private_issues.append({'path': name, **metadata(value), 'issues': problems,
                    'contents_read': False})
        return {'publication_paths_checked': len(manifest['sources']),
            'unsafe_publication_paths': unsafe,
            'publication_ancestor_issues': [value for _, value in sorted(ancestors.items()) if value],
            'next_checkpoint_metadata_issues': private_issues}, observed
    finally:
        os.close(fd)


def prior_backups(parent, uid, manifest, scanner):
    try:
        fd = scanner['open_root'](parent)
    except FileNotFoundError:
        return {'directory_exists': False, 'records': []}
    try:
        info = os.fstat(fd)
        if info.st_uid not in (0, uid) or info.st_mode & 0o022:
            return {'directory_exists': True, 'directory_metadata': metadata(info),
                'safe_to_inspect': False, 'records': []}
        choices = []
        for name in os.listdir(fd):
            if not BACKUP_NAME.fullmatch(name):
                continue
            item = os.stat(name, dir_fd=fd, follow_symlinks=False)
            choices.append((item.st_mtime_ns, name, item))
            need(len(choices) <= 2000)
        records = []
        for _, name, item in sorted(choices, reverse=True)[:5]:
            if (not stat.S_ISDIR(item.st_mode) or item.st_uid not in (0, uid)
                    or item.st_mode & 0o077):
                records.append({'path': name, **metadata(item), 'safe_to_inspect': False,
                    'attributed_to_reported_failure': False})
                continue
            child = scanner['open_directory'](fd, name)
            try:
                row = {'path': name, **metadata(item), 'attributed_to_reported_failure': False}
                for filename in ('receipt.json', 'activated.json'):
                    key = filename.split('.')[0]
                    try:
                        info = os.stat(filename, dir_fd=child, follow_symlinks=False)
                    except FileNotFoundError:
                        row[key + '_exists'] = False
                        continue
                    row[key + '_exists'] = True
                    if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, uid)
                            or info.st_nlink != 1 or info.st_mode & 0o077):
                        row[key + '_metadata_safe'] = False
                        continue
                    try:
                        content, checked = scanner['read_file'](child, filename, 8 * 1024 ** 2)
                    except FileNotFoundError:
                        raise AuditError() from None
                    need(checked.st_uid in (0, uid) and checked.st_nlink == 1
                         and not checked.st_mode & 0o077)
                    row[key + '_exists'] = True
                    try:
                        value = json.loads(content)
                    except (ValueError, UnicodeError):
                        row[key + '_json_valid'] = False
                        continue
                    row[key + '_json_valid'] = isinstance(value, dict)
                    if not isinstance(value, dict):
                        continue
                    if key == 'receipt':
                        row['receipt_manifest_matches'] = value.get('manifest_blob') == PINS['deployment/desktop_dashboard_server_manifest.json']
                        row['receipt_before_source_matches'] = value.get('before_source') == manifest['before_source']
                    else:
                        row['activated_source_matches'] = value.get('source') == manifest['after_source']
                for filename, label in (('code.tar', 'code_archive'), ('database.sql', 'database_dump'),
                                        ('publications', 'publications_directory'), ('checks', 'checks_directory')):
                    try:
                        value = os.stat(filename, dir_fd=child, follow_symlinks=False)
                        row[label + '_exists'] = (stat.S_ISDIR(value.st_mode) if 'directory' in label
                                                  else stat.S_ISREG(value.st_mode))
                    except FileNotFoundError:
                        row[label + '_exists'] = False
                records.append(row)
            finally:
                os.close(child)
        return {'directory_exists': True, 'records': records}
    finally:
        os.close(fd)


def audit(root, backups, uid, manifest, scanner, source):
    before = take_snapshot(root, manifest, scanner, source)
    report, first_metadata = permissions(root, uid, manifest, before, scanner)
    backup_report = prior_backups(backups, uid, manifest, scanner)
    after = take_snapshot(root, manifest, scanner, source)
    repeated, second_metadata = permissions(root, uid, manifest, after, scanner)
    need(before == after and report == repeated and first_metadata == second_metadata)
    fingerprint = {**source['digest'](after['files']), 'framework': '8.83.29'}
    expected_gui = {name for name in external_gui(manifest, source) if name.startswith(('public/js/', 'public/css/'))}
    return {'kind': 'go-erp-activation-permissions-read-only', 'release': RELEASE,
        'source_matches_reviewed_before': fingerprint == manifest['before_source'],
        'protected_raw_pins_match': protected_match(after, manifest),
        'external_gui_paths_checked': len(external_gui(manifest, source)),
        'complete_external_gui_roster_matches': set(after['additional_dashboard_gui']) == expected_gui,
        **report, 'prior_backups': backup_report,
        'reported_failure': {'code': 'DESKTOP_SOURCE_WRITABLE_BY_OTHER_USER',
            'stage': 'SOURCES_BEFORE_BACKUP', 'application_changes_started': False,
            'server_backup_directory_created_by_that_attempt': False,
            'backup_parent_or_lock_may_have_been_created': True},
        'application_files_changed': False, 'metadata_changed': False,
        'php_bootstrapped': False, 'database_read': False,
        'private_or_source_contents_printed': False}


def open_publication(root_fd, relative, scanner):
    fd = os.dup(root_fd)
    try:
        path = Path(relative)
        for name in path.parts[:-1]:
            following = scanner['open_directory'](fd, name)
            os.close(fd)
            fd = following
        return os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
    finally:
        os.close(fd)


def descriptor_blob(fd, before, scanner):
    need(stat.S_ISREG(before.st_mode) and before.st_size <= 8 * 1024 ** 2)
    os.lseek(fd, 0, os.SEEK_SET)
    digest = hashlib.sha1(b'blob ' + str(before.st_size).encode() + b'\0')
    total = 0
    while True:
        data = os.read(fd, min(65536, 8 * 1024 ** 2 + 1 - total))
        if not data:
            break
        total += len(data)
        need(total <= 8 * 1024 ** 2)
        digest.update(data)
    need(total == before.st_size and scanner['identity'](before) == scanner['identity'](os.fstat(fd)))
    return digest.hexdigest()


def private_audit(parent, scanner):
    need(os.geteuid() == 0)
    # Only a new root-private directory is created. Existing ancestors and
    # permissions are inspected and never changed by this helper.
    parent_fd = scanner['open_root'](parent.parent)
    try:
        parent_info = os.fstat(parent_fd)
        need(parent_info.st_uid == 0 and not parent_info.st_mode & 0o022)
        try:
            os.mkdir(parent.name, 0o700, dir_fd=parent_fd)
        except FileExistsError:
            pass
        storage_fd = scanner['open_directory'](parent_fd, parent.name)
    finally:
        os.close(parent_fd)
    try:
        item = os.fstat(storage_fd)
        need(item.st_uid == 0 and stat.S_IMODE(item.st_mode) == 0o700)
        name = 'repair-' + uuid.uuid4().hex
        os.mkdir(name, 0o700, dir_fd=storage_fd)
        directory_fd = scanner['open_directory'](storage_fd, name)
        item = os.fstat(directory_fd)
        need(item.st_uid == 0 and stat.S_IMODE(item.st_mode) == 0o700)
        os.fsync(storage_fd)
        return parent / name, directory_fd
    finally:
        os.close(storage_fd)


def audit_record(fd, name, value):
    output = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
    with os.fdopen(output, 'w') as stream:
        json.dump(value, stream, sort_keys=True)
        stream.write('\n')
        stream.flush()
        os.fsync(stream.fileno())
    os.fsync(fd)


@contextlib.contextmanager
def critical_metadata_change():
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)


def repair(root, backups, uid, gid, manifest, scanner, source, audit_parent):
    """Remove only 0022 from exact approved source files. Never undo tightening."""
    need(os.geteuid() == 0)
    original = take_snapshot(root, manifest, scanner, source)
    result = audit(root, backups, uid, manifest, scanner, source)
    result['kind'] = 'go-erp-activation-permissions-repair'
    result['repair'] = {'requested': True, 'blocked': True, 'modes_tightened': 0}
    allowed = (result['source_matches_reviewed_before'] and result['protected_raw_pins_match']
        and result['complete_external_gui_roster_matches'] and not result['publication_ancestor_issues']
        and not result['next_checkpoint_metadata_issues'])
    candidates = result['unsafe_publication_paths']
    if not allowed or any(row['issues'] != ['group_or_other_writable']
        or row.get('before_blob_matches') is not True or row.get('uid') != uid
        or row.get('gid') != gid or row.get('links') != 1 for row in candidates):
        result['repair']['reason'] = 'unreviewed_cohort_or_metadata'
        return result
    if not candidates:
        result['repair'].update(blocked=False, reason='already_safe')
        return result
    by_path = {row['path']: row for row in manifest['sources']}
    plans, completed, attempted = [], [], []
    root_fd = scanner['open_root'](root)
    audit_fd = None
    audit_path = None
    try:
        for candidate in candidates:
            name = candidate['path']
            expected = by_path[name]['before_blob']
            need(expected is not None)
            fd = open_publication(root_fd, name, scanner)
            plans.append({'path': name, 'fd': fd})
            value = os.fstat(fd)
            need(stat.S_ISREG(value.st_mode) and value.st_nlink == 1
                 and value.st_uid == uid and value.st_gid == gid
                 and not value.st_mode & 0o7000 and value.st_mode & 0o022)
            need(scanner['identity'](value) == original['metadata'][name]
                 and scanner['identity'](value) == scanner['identity'](named_metadata(root_fd, name, scanner))
                 and descriptor_blob(fd, value, scanner) == expected)
            plans[-1].update(before=value, blob=expected, after_mode=stat.S_IMODE(value.st_mode) & ~0o022)
        # Complete the entire plan and compare the complete cohort before the
        # first fchmod. A second unsafe file cannot cause partial preparation.
        need(take_snapshot(root, manifest, scanner, source) == original)
        for plan in plans:
            need(scanner['identity'](os.fstat(plan['fd'])) == scanner['identity'](plan['before'])
                 == scanner['identity'](named_metadata(root_fd, plan['path'], scanner)))
        audit_path, audit_fd = private_audit(audit_parent, scanner)
        public_plan = [{'path': plan['path'], 'before_blob': plan['blob'],
            'before': {**metadata(plan['before']), 'device': plan['before'].st_dev,
                       'inode': plan['before'].st_ino}, 'after_mode': format(plan['after_mode'], '04o')}
            for plan in plans]
        audit_record(audit_fd, 'planned.json', {'release': RELEASE,
            'manifest_blob': PINS['deployment/desktop_dashboard_server_manifest.json'],
            'before_source': manifest['before_source'], 'plans': public_plan})
        need(take_snapshot(root, manifest, scanner, source) == original)
        for index, plan in enumerate(plans):
            need(scanner['identity'](os.fstat(plan['fd'])) == scanner['identity'](plan['before'])
                 == scanner['identity'](named_metadata(root_fd, plan['path'], scanner)))
            with critical_metadata_change():
                attempted.append(plan['path'])
                os.fchmod(plan['fd'], plan['after_mode'])
                os.fsync(plan['fd'])
                after = os.fstat(plan['fd'])
                plan['after'] = after
                completed.append(plan['path'])
                need(stat.S_IMODE(after.st_mode) == plan['after_mode'] and after.st_uid == uid
                     and after.st_gid == gid and after.st_nlink == 1
                     and (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)
                         == (plan['before'].st_dev, plan['before'].st_ino, plan['before'].st_size, plan['before'].st_mtime_ns)
                     and scanner['identity'](after) == scanner['identity'](named_metadata(root_fd, plan['path'], scanner))
                     and descriptor_blob(plan['fd'], after, scanner) == plan['blob'])
                audit_record(audit_fd, 'changed-{:03d}.json'.format(index),
                    {'path': plan['path'], 'before_blob_matches': True,
                     'after': metadata(after), 'source_contents_changed': False})
        fresh = take_snapshot(root, manifest, scanner, source)
        for plan in plans:
            need(fresh['metadata'][plan['path']] == scanner['identity'](plan['after']))
            fresh['metadata'][plan['path']] = original['metadata'][plan['path']]
        need(fresh == original)
        verified = audit(root, backups, uid, manifest, scanner, source)
        need(verified['source_matches_reviewed_before'] and verified['protected_raw_pins_match']
             and verified['complete_external_gui_roster_matches']
             and not verified['unsafe_publication_paths'] and not verified['publication_ancestor_issues']
             and not verified['next_checkpoint_metadata_issues'])
        for plan in plans:
            need(scanner['identity'](os.fstat(plan['fd'])) == scanner['identity'](plan['after'])
                 == scanner['identity'](named_metadata(root_fd, plan['path'], scanner)))
        audit_record(audit_fd, 'completed.json', {'modes_tightened': len(completed),
            'source_contents_changed': False, 'unsafe_permissions_restored': False})
        result = verified
        result['kind'] = 'go-erp-activation-permissions-repair'
        result['repair'] = {'requested': True, 'blocked': False, 'modes_tightened': len(completed),
            'audit_directory': str(audit_path), 'unsafe_permissions_restored': False}
        result['application_files_changed'] = True
        result['application_file_contents_changed'] = False
        result['metadata_changed'] = True
        return result
    except BaseException:
        if audit_fd is not None:
            audit_record(audit_fd, 'stopped.json', {'attempted_paths': attempted,
                'completed_paths': completed, 'unsafe_permissions_restored': False})
        result['repair'].update(reason='concurrent_change_or_os_error', modes_tightened=len(completed),
            change_attempted=bool(attempted), unsafe_permissions_restored=False)
        if audit_path is not None:
            result['repair']['audit_directory'] = str(audit_path)
        result['application_files_changed'] = bool(attempted)
        result['application_file_contents_changed'] = False
        result['metadata_changed'] = bool(attempted)
        return result
    finally:
        for plan in plans:
            os.close(plan['fd'])
        if audit_fd is not None:
            os.close(audit_fd)
        os.close(root_fd)


def main():
    need(sys.argv[1:] in ([], ['--repair']) and os.geteuid() == 0
         and socket.gethostname().split('.')[0] == 'srv1836524')
    account = pwd.getpwnam('fasakha')
    uid = account.pw_uid
    payload = reviewed_payload(ROOT)
    manifest = validate_manifest(json.loads(payload['deployment/desktop_dashboard_server_manifest.json']))
    scanner = {'__name__': 'reviewed_permission_scanner'}
    source = {'__name__': 'reviewed_permission_source'}
    exec(compile(payload['deployment/diagnose_desktop_dashboard_current_source.py'], 'reviewed-scanner', 'exec'), scanner)
    exec(compile(payload['deployment/desktop_dashboard_server_preflight.py'], 'reviewed-source', 'exec'), source)
    result = (repair(ROOT, BACKUPS, uid, account.pw_gid, manifest, scanner, source, AUDITS)
        if sys.argv[1:] == ['--repair'] else audit(ROOT, BACKUPS, uid, manifest, scanner, source))
    encoded = json.dumps(result, ensure_ascii=True, separators=(',', ':'))
    need(len(encoded.encode('ascii')) <= MAX_OUTPUT)
    print('GO_ERP_ACTIVATION_PERMISSIONS_BEGIN')
    print(encoded)
    print('GO_ERP_ACTIVATION_PERMISSIONS_END; APPLICATION_CONTENTS_CHANGED=false')
    return 1 if result.get('repair', {}).get('blocked') else 0


if __name__ == '__main__':
    try:
        result = main()
    except BaseException:
        print('GO_ERP_ACTIVATION_PERMISSIONS_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        result = 1
    sys.exit(result)
PY
