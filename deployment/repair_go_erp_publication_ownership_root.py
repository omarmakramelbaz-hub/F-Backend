#!/usr/bin/env python3
"""Recover ownership of the eight measured, unchanged publication files only."""
import contextlib
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import socket
import stat
import subprocess
import sys

ROOT = Path('/home/fasakha/public_html')
APP = '3b7d53e6021ce825dd6fc56218cb6ce25f72b3b4'
AUTOMATION = '9bcfaf65627f9bfe01797ecb3ed8fe7beff69143'
SELF = 'deployment/repair_go_erp_publication_ownership_root.py'
UID, GID = 1001, 1003
AUDITS = Path('/var/lib/go-erp-publication-owner-recovery')
BACKUPS = Path('/home/fasakha/desktop-dashboard-release-backups')
BEFORE_SHA = 'a6adf27f343f61a5264fd6b65c0aa8978f2825a0ed3e3379d522c89aac4ddfa6'
AFTER_SHA = 'ef2f7d28823d3c447b71e052df49e9eba1b4a0f3edb7ab44e9c4d42417e150b3'
PINS = {
    'deployment/activate_go_erp_complete_root.sh': (AUTOMATION, '44d219ee7a7b08bf1b94c96c58d30804f78f4074'),
    'deployment/diagnose_go_erp_activation_permissions_root.sh': (AUTOMATION, '5754f78b1c145c30e6f39770d30a888528b4c51d'),
    'deployment/desktop_dashboard_server_manifest.json': (APP, 'da47b5e5dc50676d6046a6511b3905a0cd053ed1'),
    'deployment/desktop_dashboard_server_preflight.py': (APP, '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4'),
    'deployment/diagnose_desktop_dashboard_current_source.py': (APP, 'e7a7a8271481013a1c16167d6f7a6ff429687ef5'),
}
COHORT = {
    'app/Repositories/UserRepository.php': ('5fdd643f6ab2235c9830831b885ccaf0af8918b9', 0o755),
    'app/Http/Controllers/Api/V1/User/CartController.php': ('1a872609a4e5414ee0b1f62d0971c6ffc9ffc05f', 0o755),
    'app/Http/Controllers/Dashboard/SettingsController.php': ('6644e6b540a39fc7094a0e22c5ee853929784807', 0o755),
    'app/Exceptions/Handler.php': ('abb3f87f51a45220afe8a40f68600ad1a1dfa437', 0o644),
    'app/Observers/OrderCompetitionObserver.php': ('a28cae80b834aceab7cbdf8ebd1fe67234bda3d9', 0o644),
    'resources/views/admin/pending_vendors/index.blade.php': ('6a9cb3cb32f211e124ae6ff1ab496fa6268fba8c', 0o755),
    'resources/views/admin/settings.blade.php': ('d866b771efdbdf2e81f3d28d28e6ba9a35c5c834', 0o755),
    'resources/views/admin/users/index.blade.php': ('e36567c3e065274a740f2727c706cbf727a0dd2c', 0o755),
}
ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha', 'GIT_OPTIONAL_LOCKS': '0',
       'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_NO_LAZY_FETCH': '1',
       'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
CLASSES = frozenset(('Stop', 'AuditError', 'DiagnosisError', 'OSError', 'PermissionError',
    'FileNotFoundError', 'NotADirectoryError', 'TimeoutExpired', 'UnicodeDecodeError',
    'JSONDecodeError', 'ValueError', 'KeyError', 'TypeError', 'RuntimeError', 'SyntaxError'))


class Stop(Exception):
    pass


class State:
    phase = 'INVOCATION'


def need(ok, reason):
    if not ok:
        raise Stop(reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()


def identity(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def invariant(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_nlink, item.st_size, item.st_mtime_ns)


def git(arguments, maximum=2 * 1024 * 1024, runner=subprocess.run):
    command = ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i']
    command += [name + '=' + value for name, value in ENV.items()]
    command += ['/usr/bin/git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
                '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT)] + list(arguments)
    try:
        result = runner(command, env=ENV, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Stop('REVIEWED_GIT_UNAVAILABLE') from None
    need(result.returncode == 0, 'REVIEWED_GIT_READ_FAILED')
    need(len(result.stdout) <= maximum and len(result.stderr) <= 65536, 'REVIEWED_GIT_OUTPUT_LIMIT')
    return result.stdout


def source_file(revision, name, expected=None, reader=git):
    row = reader(['ls-tree', '-z', revision, '--', name], 1024)
    match = re.fullmatch(rb'100(?:644|755) blob ([a-f0-9]{40})\t' + re.escape(name.encode('ascii')) + rb'\0', row)
    need(match is not None and (expected is None or match[1].decode('ascii') == expected), 'REVIEWED_FILE_PIN_INVALID')
    pin = match[1].decode('ascii')
    size = reader(['cat-file', '-s', pin], 32)
    need(re.fullmatch(rb'[0-9]{1,8}\n', size) and 0 < int(size) <= 2 * 1024 * 1024, 'REVIEWED_FILE_SIZE_INVALID')
    data = reader(['cat-file', 'blob', pin], 2 * 1024 * 1024)
    need(len(data) == int(size) and blob(data) == pin, 'REVIEWED_FILE_BYTES_INVALID')
    return data


def namespace(data, name, shell=False):
    if shell:
        marker = b"<<'PY'\n"
        need(data.count(marker) == 1 and data.endswith(b'\nPY\n'), 'REVIEWED_PYTHON_PAYLOAD_INVALID')
        data = data.split(marker, 1)[1][:-4]
    result = {'__name__': name}
    exec(compile(data, name, 'exec'), result)
    return result


def reviewed_context(reader=git):
    data = {name: source_file(revision, name, pin, reader) for name, (revision, pin) in PINS.items()}
    complete = namespace(data['deployment/activate_go_erp_complete_root.sh'], 'reviewed_owner_complete', True)
    permission = namespace(data['deployment/diagnose_go_erp_activation_permissions_root.sh'], 'reviewed_owner_permissions', True)
    scanner = namespace(data['deployment/diagnose_desktop_dashboard_current_source.py'], 'reviewed_owner_scanner')
    source = namespace(data['deployment/desktop_dashboard_server_preflight.py'], 'reviewed_owner_source')
    manifest = permission['validate_manifest'](json.loads(data['deployment/desktop_dashboard_server_manifest.json']))
    need(manifest['before_source']['sha256'] == BEFORE_SHA and manifest['after_source']['sha256'] == AFTER_SHA,
         'REVIEWED_SOURCE_PROFILE_INVALID')
    by_path = {row['path']: row for row in manifest['sources']}
    need(len(COHORT) == 8 and all(by_path[name]['before_blob'] == pin and by_path[name]['after_blob'] is not None
         for name, (pin, _mode) in COHORT.items()), 'REVIEWED_OWNER_COHORT_INVALID')
    return {'complete': complete, 'permission': permission, 'scanner': scanner,
            'source': source, 'manifest': manifest, 'by_path': by_path}


def check_audit(report, state, cohort=COHORT):
    need(report['publication_paths_checked'] == 266 and report['protected_raw_pins_match'] is True
         and report['complete_external_gui_roster_matches'] is True and report['external_gui_paths_checked'] == 27
         and not report['publication_ancestor_issues'] and not report['next_checkpoint_metadata_issues']
         and report['private_or_source_contents_printed'] is False, 'UNREVIEWED_PUBLICATION_METADATA')
    if state == 'BEFORE':
        need(report['source_matches_reviewed_before'] is True, 'BEFORE_SOURCE_PROOF_MISSING')
    for row in report['unsafe_publication_paths']:
        issues = list(row['issues'])
        if state == 'AFTER':
            issues = [value for value in issues if value != 'reviewed_before_blob_mismatch']
            need(not issues, 'AFTER_PUBLICATION_METADATA_UNSAFE')
        elif issues == ['owner_mismatch']:
            need(row['path'] in cohort and (row['uid'], row['gid'], row['links']) == (0, 0, 1)
                 and row['mode'] == format(cohort[row['path']][1], '04o') and row['before_blob_matches'] is True,
                 'UNLISTED_OWNER_MISMATCH')
        else:
            need(issues == ['group_or_other_writable'] and (row['uid'], row['gid'], row['links']) == (UID, GID, 1)
                 and row['before_blob_matches'] is True, 'UNREVIEWED_PUBLICATION_METADATA')


def inspect(root, context, head_reader, cohort=COHORT):
    c, p, scanner, source, manifest = [context[key] for key in ('complete', 'permission', 'scanner', 'source', 'manifest')]
    state = c['classifier'](root, manifest, scanner, source, head_reader)
    need(state in ('BEFORE', 'AFTER'), 'UNREVIEWED_SERVER_SOURCE')
    report = p['audit'](root, BACKUPS, UID, manifest, scanner, source)
    check_audit(report, state, cohort)
    snapshot = p['take_snapshot'](root, manifest, scanner, source)
    metadata_report, observed = p['permissions'](root, UID, manifest, snapshot, scanner)
    need(all(metadata_report[key] == report[key] for key in metadata_report), 'METADATA_CHANGED_DURING_INSPECTION')
    need(p['take_snapshot'](root, manifest, scanner, source) == snapshot, 'SOURCE_CHANGED_DURING_INSPECTION')
    return {'state': state, 'snapshot': snapshot, 'observed': observed, 'head': head_reader()}


class Parents:
    def __init__(self, root, scanner):
        self.root, self.scanner = root, scanner
        self.rows = {}
        fd = scanner['open_root'](root)
        self.rows['.'] = (fd, os.fstat(fd), None, None)
        self.add('.')

    def add(self, relative):
        if relative not in self.rows:
            path = Path(relative)
            parent = path.parent.as_posix()
            self.add(parent)
            fd = self.scanner['open_directory'](self.rows[parent][0], path.name)
            self.rows[relative] = (fd, os.fstat(fd), parent, path.name)
        item = self.rows[relative][1]
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == UID and not item.st_mode & 0o002,
             'PUBLICATION_PARENT_UNSAFE')

    def verify(self):
        fresh = self.scanner['open_root'](self.root)
        try:
            need(identity(os.fstat(fresh)) == identity(self.rows['.'][1]), 'PUBLICATION_ROOT_CHANGED')
        finally:
            os.close(fresh)
        for _relative, (fd, before, parent, name) in self.rows.items():
            need(identity(os.fstat(fd)) == identity(before), 'PUBLICATION_PARENT_CHANGED')
            if parent is not None:
                named = os.stat(name, dir_fd=self.rows[parent][0], follow_symlinks=False)
                need(identity(named) == identity(before), 'PUBLICATION_PARENT_CHANGED')

    def open(self, relative):
        path = Path(relative)
        parent = path.parent.as_posix()
        self.add(parent)
        return os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.rows[parent][0])

    def named(self, relative):
        path = Path(relative)
        return os.stat(path.name, dir_fd=self.rows[path.parent.as_posix()][0], follow_symlinks=False)

    def close(self):
        for fd, _item, _parent, _name in self.rows.values():
            os.close(fd)
        self.rows = {}


def hashes(fd, item):
    need(stat.S_ISREG(item.st_mode) and item.st_nlink == 1 and 0 < item.st_size <= 8 * 1024 * 1024,
         'PUBLICATION_FILE_UNSAFE')
    os.lseek(fd, 0, os.SEEK_SET)
    raw = hashlib.sha1(b'blob ' + str(item.st_size).encode('ascii') + b'\0')
    sha = hashlib.sha256()
    total = 0
    while True:
        data = os.read(fd, 65536)
        if not data:
            break
        total += len(data)
        need(total <= item.st_size, 'PUBLICATION_SIZE_CHANGED')
        raw.update(data)
        sha.update(data)
    need(total == item.st_size and identity(item) == identity(os.fstat(fd)), 'PUBLICATION_CHANGED_DURING_READ')
    return raw.hexdigest(), sha.hexdigest()


def plan(root, context, original, parents, cohort=COHORT):
    result = []
    try:
        for name, (before_pin, before_mode) in sorted(cohort.items()):
            fd = parents.open(name)
            row = {'path': name, 'fd': fd}
            result.append(row)
            item = os.fstat(fd)
            owner = (item.st_uid, item.st_gid)
            if original['state'] == 'BEFORE':
                need(owner in ((0, 0), (UID, GID)) and stat.S_IMODE(item.st_mode) == before_mode,
                     'MEASURED_OWNER_STATE_CHANGED')
                expected = before_pin
            else:
                need(owner == (UID, GID) and not item.st_mode & 0o7022, 'AFTER_OWNER_STATE_UNSAFE')
                expected = context['by_path'][name]['after_blob']
            need(identity(item) == identity(parents.named(name)) == original['snapshot']['metadata'][name]
                 == original['observed'][name], 'PUBLICATION_IDENTITY_CHANGED')
            pin, sha = hashes(fd, item)
            need(pin == expected, 'PUBLICATION_RAW_PIN_CHANGED')
            row.update(before=item, current=item, blob=pin, sha256=sha)
        parents.verify()
        return result
    except BaseException:
        for row in result:
            os.close(row['fd'])
        raise


def verify_plan(row, parents):
    parents.verify()
    need(identity(row['current']) == identity(os.fstat(row['fd'])) == identity(parents.named(row['path'])),
         'PUBLICATION_IDENTITY_CHANGED')
    need(hashes(row['fd'], row['current']) == (row['blob'], row['sha256']), 'PUBLICATION_CONTENT_CHANGED')


def public_plan(rows):
    return [{'path': row['path'], 'before_uid': row['before'].st_uid, 'before_gid': row['before'].st_gid,
             'mode': format(stat.S_IMODE(row['before'].st_mode), '04o'), 'blob': row['blob'],
             'sha256': row['sha256'], 'device': row['before'].st_dev, 'inode': row['before'].st_ino,
             'mtime_ns': row['before'].st_mtime_ns, 'state': 'root' if row['before'].st_uid == 0 else 'restored'} for row in rows]


@contextlib.contextmanager
def critical():
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)


def rollback(rows, parents):
    restored, skipped = [], []
    for row in reversed(rows):
        try:
            parents.verify()
            actual = os.fstat(row['fd'])
            need(invariant(actual) == invariant(row['before']) and identity(actual) == identity(parents.named(row['path']))
                 and (actual.st_uid, actual.st_gid) in ((0, 0), (UID, GID))
                 and hashes(row['fd'], actual) == (row['blob'], row['sha256']), 'ROLLBACK_TARGET_CHANGED')
            os.fchown(row['fd'], row['before'].st_uid, row['before'].st_gid)
            after = os.fstat(row['fd'])
            need(invariant(after) == invariant(row['before']) and identity(after) == identity(parents.named(row['path']))
                 and (after.st_uid, after.st_gid) == (row['before'].st_uid, row['before'].st_gid)
                 and hashes(row['fd'], after) == (row['blob'], row['sha256']), 'ROLLBACK_METADATA_FAILED')
            row['current'] = after
            restored.append(row['path'])
        except BaseException:
            skipped.append(row['path'])
    return {'restored': restored, 'skipped_changed_or_unavailable': skipped, 'application_contents_changed': False}


def recover(root, context, head_reader, requested, state, cohort=COHORT, inspector=inspect, audit_parent=AUDITS):
    state.phase = 'FULL_SOURCE_AND_METADATA'
    original = inspector(root, context, head_reader, cohort)
    parents = Parents(root, context['scanner'])
    rows, attempted = [], []
    audit_fd = None
    try:
        state.phase = 'HELD_PUBLICATION_PLAN'
        rows = plan(root, context, original, parents, cohort)
        pending = [row for row in rows if row['before'].st_uid == 0]
        need(original['state'] != 'AFTER' or not pending, 'AFTER_RECOVERY_NOT_ALLOWED')
        need(inspector(root, context, head_reader, cohort) == original, 'COHORT_CHANGED_BEFORE_RECOVERY')
        for row in rows:
            verify_plan(row, parents)
        if not requested:
            return {'changed': 0, 'pending': len(pending), 'audit': None, 'state': original['state']}
        state.phase = 'PRIVATE_AUDIT'
        p = context['permission']
        audit_path, audit_fd = p['private_audit'](audit_parent, context['scanner'])
        p['audit_record'](audit_fd, 'planned.json', {'format': 1, 'app_release': APP, 'automation_release': AUTOMATION,
            'source_state': original['state'], 'before_source': context['manifest']['before_source'],
            'after_source': context['manifest']['after_source'], 'plans': public_plan(rows), 'application_contents_changed': False})
        need(inspector(root, context, head_reader, cohort) == original, 'COHORT_CHANGED_AFTER_AUDIT')
        state.phase = 'OWNERSHIP_RECOVERY'
        with critical():
            try:
                for row in pending:
                    verify_plan(row, parents)
                    attempted.append(row)
                    os.fchown(row['fd'], UID, GID)
                    after = os.fstat(row['fd'])
                    need(invariant(after) == invariant(row['before']) and (after.st_uid, after.st_gid) == (UID, GID)
                         and identity(after) == identity(parents.named(row['path']))
                         and hashes(row['fd'], after) == (row['blob'], row['sha256']), 'OWNERSHIP_RECOVERY_NOT_EXACT')
                    row['current'] = after
                    p['audit_record'](audit_fd, 'changed-{:02d}.json'.format(len(attempted)),
                        {'path': row['path'], 'uid': UID, 'gid': GID, 'application_contents_changed': False})
                state.phase = 'POST_RECOVERY_PROOF'
                for row in rows:
                    verify_plan(row, parents)
                fresh = inspector(root, context, head_reader, cohort)
                for row in rows:
                    name = row['path']
                    need(fresh['snapshot']['metadata'][name] == fresh['observed'][name] == identity(row['current']),
                         'POST_RECOVERY_METADATA_CHANGED')
                    fresh['snapshot']['metadata'][name] = original['snapshot']['metadata'][name]
                    fresh['observed'][name] = original['observed'][name]
                need(fresh == original, 'UNRELATED_COHORT_CHANGED')
                p['audit_record'](audit_fd, 'completed.json', {'files_changed': len(pending),
                    'application_contents_changed': False, 'modes_changed': False})
            except BaseException:
                state.phase = 'SAFE_METADATA_ROLLBACK'
                result = rollback(attempted, parents)
                p['audit_record'](audit_fd, 'stopped.json', result)
                raise Stop('RECOVERY_STOPPED_REVIEW_PRIVATE_AUDIT') from None
        return {'changed': len(pending), 'pending': 0, 'audit': str(audit_path), 'state': original['state']}
    finally:
        for row in rows:
            os.close(row['fd'])
        if audit_fd is not None:
            os.close(audit_fd)
        parents.close()


def safe_failure(error, state):
    name = type(error).__name__ if type(error).__name__ in CLASSES else 'Exception'
    reason = str(error) if isinstance(error, Stop) and re.fullmatch(r'[A-Z][A-Z0-9_]{0,95}', str(error)) else 'PRIVATE_INSPECTION_FAILURE'
    phase = state.phase if re.fullmatch(r'[A-Z_]{1,40}', state.phase) else 'UNKNOWN'
    return 'GO_ERP_PUBLICATION_OWNER_STOPPED PHASE=' + phase + ' REASON=' + reason + ' CLASS=' + name


def execute(arguments, state):
    need(len(arguments) in (2, 3) and arguments[0] == '--release'
         and re.fullmatch(r'[a-f0-9]{40}', arguments[1]) and arguments[2:] in ([], ['--repair']), 'PINNED_INVOCATION_REQUIRED')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    actor = pwd.getpwnam('fasakha')
    need((actor.pw_uid, actor.pw_gid) == (UID, GID) and ROOT.resolve() == ROOT, 'PROJECT_ACCOUNT_UNSAFE')
    for path in (ROOT, ROOT / '.git'):
        item = path.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == UID, 'PROJECT_UNSAFE')
    state.phase = 'PINNED_REVIEWED_PAYLOADS'
    context = reviewed_context()
    c = context['complete']
    git(['merge-base', '--is-ancestor', AUTOMATION, arguments[1]], 1024)
    need(c['private_entry'](Path(__file__).absolute()) == source_file(arguments[1], SELF), 'ENTRYPOINT_NOT_REVIEWED')
    def head_reader():
        value = git(['rev-parse', '--verify', 'HEAD'], 64)
        need(re.fullmatch(rb'[a-f0-9]{40}\n', value), 'HEAD_INVALID')
        return value[:-1].decode('ascii')
    return recover(ROOT, context, head_reader, arguments[2:] == ['--repair'], state)


def main(arguments=None, out=sys.stdout, err=sys.stderr):
    state = State()
    try:
        result = execute(sys.argv[1:] if arguments is None else arguments, state)
    except Exception as error:
        print(safe_failure(error, state), file=err)
        return 1
    if result['audit'] is None:
        print('GO_ERP_PUBLICATION_OWNERSHIP_PREFLIGHT_OK pending=' + str(result['pending']), file=out)
    else:
        print('GO_ERP_PUBLICATION_OWNERSHIP_RECOVERED files=' + str(result['changed']), file=out)
        print('AUDIT_DIRECTORY=' + result['audit'], file=out)
    print('APPLICATION_CONTENTS_CHANGED=false', file=out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
