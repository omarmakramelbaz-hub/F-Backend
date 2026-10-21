#!/usr/bin/env python3
"""Read-only, source-bound diagnosis of the one measured failed activation."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile

ROOT = Path('/home/fasakha/public_html')
BACKUP = Path('/home/fasakha/desktop-dashboard-release-backups/server-4cb99kno')
UID, GID = 1001, 1003
B2 = '9bcfaf65627f9bfe01797ecb3ed8fe7beff69143'
OWNER = 'deployment/repair_go_erp_publication_ownership_root.py'
OWNER_BLOB = '6ceff5c798411e42ca4d7673764eb72ded3ed2e6'
MANIFEST_BLOB = 'da47b5e5dc50676d6046a6511b3905a0cd053ed1'
SELF = 'deployment/diagnose_go_erp_postbackup_root.py'
PROBE = 'deployment/go_erp_postbackup_probe.php'
PROBE_BLOB = '5adf1f9598ce782fa46cb469983fd56ab7c7ffc4'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
MIGRATION_TABLES = [
    ['desktop_dashboard_commands', 'desktop_dashboard_entities', 'desktop_dashboard_devices'],
    ['desktop_dashboard_local_state', 'desktop_dashboard_refreshes'],
    ['desktop_dashboard_archived_commands'], ['desktop_dashboard_remote_attempts'],
    ['desktop_dashboard_media_manifest'], ['desktop_dashboard_source_manifest'],
    ['desktop_dashboard_print_ownership'], ['desktop_dashboard_created_scopes'],
]
PRIVATE = ('.env', 'bootstrap/cache/config.php')
SHA = re.compile(r'[a-f0-9]{40}\Z')

class Stop(Exception):
    pass

def need(ok, code):
    if not ok:
        raise Stop(code)

def identity(s):
    return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink,
            s.st_size, s.st_mtime_ns, s.st_ctime_ns)

def git(args, maximum=2 * 1024 * 1024):
    env = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha', 'GIT_OPTIONAL_LOCKS': '0',
           'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_NO_LAZY_FETCH': '1',
           'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
    result = subprocess.run(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i',
        *[k + '=' + v for k, v in env.items()], '/usr/bin/git', '--no-optional-locks',
        '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT), *args],
        env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    need(result.returncode == 0 and len(result.stdout) <= maximum and len(result.stderr) <= 65536,
         'REVIEWED_GIT_UNAVAILABLE')
    return result.stdout

def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()

class Tree:
    """Keep every directory descriptor pinned; never follow a link."""
    def __init__(self, path, private=False):
        need(path.is_absolute() and '..' not in path.parts, 'DIRECTORY_UNSAFE')
        self.rows = []
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.rows.append((fd, None, None, os.fstat(fd)))
        try:
            for part in path.parts[1:]:
                parent = fd
                fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                info = os.fstat(fd)
                self.rows.append((fd, parent, part, info))
                sticky = bool(info.st_mode & stat.S_ISVTX) and info.st_uid == 0
                allowed = ((info.st_uid, info.st_gid) == (UID, GID) and not info.st_mode & 0o002
                           or info.st_uid == 0 and (not info.st_mode & 0o022 or sticky))
                need(stat.S_ISDIR(info.st_mode) and allowed, 'DIRECTORY_UNSAFE')
            if private:
                need((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) == (UID, GID, 0o700),
                     'PRIVATE_DIRECTORY_UNSAFE')
            self.fd = fd
            self.verify()
        except BaseException:
            self.close()
            raise
    def verify(self):
        for fd, parent, name, before in self.rows:
            need(identity(os.fstat(fd)) == identity(before), 'DIRECTORY_CHANGED')
            if parent is not None:
                need(identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == identity(before),
                     'DIRECTORY_CHANGED')
    def close(self):
        for fd, *_ in reversed(self.rows):
            os.close(fd)
        self.rows = []
    def read(self, name, limit, private=False):
        need('/' not in name and name not in ('.', '..'), 'FILE_SCOPE_INVALID')
        self.verify()
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.fd)
        try:
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_uid == UID
                 and before.st_gid == GID and not before.st_mode & 0o7002
                 and 0 < before.st_size <= limit, 'FILE_METADATA_UNSAFE')
            if private:
                need(stat.S_IMODE(before.st_mode) == 0o600, 'PRIVATE_FILE_MODE_INVALID')
            data = bytearray()
            while len(data) <= limit:
                part = os.read(fd, min(65536, limit + 1 - len(data)))
                if not part:
                    break
                data.extend(part)
            need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
                 == identity(os.stat(name, dir_fd=self.fd, follow_symlinks=False)), 'FILE_CHANGED')
            self.verify()
            return bytes(data), before
        finally:
            os.close(fd)

def receipt_value(data, manifest):
    value = json.loads(data)
    need(isinstance(value, dict) and set(value) == {'version', 'git_head', 'before_source', 'after_source',
         'manifest_blob', 'database', 'code_archive', 'files'} and type(value['version']) is int and value['version'] == 1
         and value['git_head'] == manifest['expected_head'] and value['before_source'] == manifest['before_source']
         and value['after_source'] == manifest['after_source'] and value['manifest_blob'] == MANIFEST_BLOB,
         'BACKUP_RECEIPT_PROFILE_INVALID')
    files = value['files']
    need(isinstance(files, dict), 'BACKUP_RECEIPT_ROSTER_INVALID')
    routes = set(files) & {'bootstrap/cache/routes.php', 'bootstrap/cache/routes-v7.php'}
    need(len(routes) == 1 and set(files) == {r['path'] for r in manifest['sources']} | set(PRIVATE) | routes,
         'BACKUP_RECEIPT_ROSTER_INVALID')
    for name, row in files.items():
        need(row is None or isinstance(row, dict) and set(row) == {'blob', 'uid', 'gid', 'mode', 'device', 'inode'}
             and isinstance(row['blob'], str) and SHA.fullmatch(row['blob'])
             and all(type(row[k]) is int and row[k] >= 0 for k in ('uid', 'gid', 'mode', 'device', 'inode'))
             and row['mode'] <= 0o7777, 'BACKUP_RECEIPT_FILE_INVALID')
    for row in manifest['sources']:
        saved = files[row['path']]
        need((saved is None) == (row['before_blob'] is None)
             and (saved is None or saved['blob'] == row['before_blob']), 'BACKUP_SOURCE_RECEIPT_INVALID')
    need(files['.env'] is not None, 'BACKUP_ENVIRONMENT_RECEIPT_INVALID')
    return value, tuple(PRIVATE) + tuple(sorted(routes))

def restored(root, name, expected):
    tree = Tree((root / name).parent)
    try:
        try:
            data, item = tree.read(Path(name).name, 8 * 1024 * 1024)
        except FileNotFoundError:
            tree.verify()
            return expected is None
        return expected is not None and blob(data) == expected['blob'] and all(
            actual == expected[key] for key, actual in [('uid', item.st_uid), ('gid', item.st_gid),
            ('mode', stat.S_IMODE(item.st_mode)), ('device', item.st_dev), ('inode', item.st_ino)])
    finally:
        tree.close()

def artifacts(path, manifest, publications=False):
    tree = Tree(path, private=True)
    try:
        names = os.listdir(tree.fd)
        need(len(names) <= 2000, 'ARTIFACT_COUNT_LIMIT')
        result = {'published': 0, 'recovered': 0} if publications else {
            'proposed_config': False, 'proposed_routes': False, 'compiled_blades': 0, 'other_files': 0}
        blades = {hashlib.sha256(r['path'].encode()).hexdigest() for r in manifest['sources']
                  if r['path'].startswith('resources/views/') and r['path'].endswith('.blade.php')}
        for name in names:
            item = os.stat(name, dir_fd=tree.fd, follow_symlinks=False)
            need(stat.S_ISREG(item.st_mode) and (item.st_uid, item.st_gid) == (UID, GID)
                 and 1 <= item.st_nlink <= 2 and not item.st_mode & 0o7002, 'ARTIFACT_METADATA_UNSAFE')
            if publications:
                match = re.fullmatch(r'(published|recovered)-[A-Za-z0-9_-]{6,32}', name)
                need(match is not None, 'PUBLICATION_ARTIFACT_NAME_INVALID')
                result[match[1]] += 1
            elif name in ('proposed-config.php', 'proposed-routes.php'):
                result['proposed_config' if name == 'proposed-config.php' else 'proposed_routes'] = True
            elif (match := re.fullmatch(r'compiled-([a-f0-9]{64})\.php', name)):
                need(match[1] in blades, 'UNREVIEWED_BLADE_ARTIFACT')
                result['compiled_blades'] += 1
            else:
                result['other_files'] += 1
        tree.verify()
        return result
    finally:
        tree.close()

def inspect_backup(root, backup, manifest):
    tree = Tree(backup, private=True)
    try:
        data, _ = tree.read('receipt.json', 1024 * 1024, private=True)
        receipt, names = receipt_value(data, manifest)
        report = {'private_restoration': {name: restored(root, name, receipt['files'][name]) for name in names},
                  'checks': artifacts(backup / 'checks', manifest),
                  'publications': artifacts(backup / 'publications', manifest, publications=True)}
        tree.verify()
        return report
    finally:
        tree.close()

def validate_probe(value, manifest):
    need(isinstance(value, dict) and value.get('kind') == 'go-erp-postbackup-read-only-probe'
         and type(value.get('available')) is bool, 'PROBE_RECEIPT_INVALID')
    if not value['available']:
        need(set(value) == {'kind', 'available', 'phase', 'error_class'}
             and value['phase'] in {'ARGUMENTS', 'MANIFEST', 'BOOTSTRAP', 'READ_ONLY_TRANSACTION',
                 'MIGRATION_METADATA', 'COMPILED_VIEW_METADATA', 'READ_ONLY_ROLLBACK'}
             and value['error_class'] in {'RuntimeException', 'Error', 'TypeError', 'ValueError',
                 'JsonException', 'PDOException', 'Illuminate\\Database\\QueryException',
                 'Illuminate\\Contracts\\Container\\BindingResolutionException', 'Throwable'},
             'PROBE_RECEIPT_INVALID')
        return value
    need(set(value) == {'kind', 'available', 'framework', 'driver', 'migrations', 'compiled_views'}
         and value['framework'] == '8.83.29' and value['driver'] == 'mysql'
         and isinstance(value['migrations'], list) and len(value['migrations']) == 8,
         'PROBE_RECEIPT_INVALID')
    for row, migration, tables in zip(value['migrations'], manifest['migrations'], MIGRATION_TABLES):
        name = Path(migration).stem
        need(isinstance(row, dict) and set(row) == {'migration', 'records', 'tables'}
             and row['migration'] == name and type(row['records']) is int and 0 <= row['records'] < 2 ** 31
             and isinstance(row['tables'], list) and len(row['tables']) == len(tables), 'PROBE_RECEIPT_INVALID')
        for member, table in zip(row['tables'], tables):
            need(isinstance(member, dict) and set(member) == {'name', 'exists'}
                 and member['name'] == table and type(member['exists']) is bool, 'PROBE_RECEIPT_INVALID')
    views = {row['path'] for row in manifest['sources']
             if row['path'].startswith('resources/views/') and row['path'].endswith('.blade.php')}
    need(isinstance(value['compiled_views'], list) and len(value['compiled_views']) == len(views),
         'PROBE_RECEIPT_INVALID')
    observed = set()
    for row in value['compiled_views']:
        need(isinstance(row, dict) and set(row) == {'source', 'target', 'metadata'}
             and row['source'] in views - observed and isinstance(row['target'], str)
             and re.fullmatch(r'storage/framework/views/[a-f0-9]{40}\.php', row['target']), 'PROBE_RECEIPT_INVALID')
        observed.add(row['source'])
        item = row['metadata']
        need(item is None or isinstance(item, dict) and set(item) == {'uid', 'gid', 'mode', 'links'}
             and all(type(item[k]) is int and 0 <= item[k] < 2 ** 31 for k in ('uid', 'gid', 'links'))
             and isinstance(item['mode'], str) and re.fullmatch('[0-7]{4}', item['mode']), 'PROBE_RECEIPT_INVALID')
    return value

def probe_process(args, *, env, stdin, stdout, stderr, timeout, check=False):
    process = subprocess.Popen(args, env=env, stdin=stdin, stdout=stdout, stderr=stderr, start_new_session=True)
    try:
        output, error = process.communicate(timeout=timeout)
    except BaseException as failure:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            process.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.communicate(timeout=5)
        if isinstance(failure, subprocess.TimeoutExpired):
            raise Stop('PROBE_PROCESS_UNAVAILABLE') from None
        raise
    return subprocess.CompletedProcess(args, process.returncode, output, error)

def run_probe(owner, context, revision, runner=probe_process):
    payload = owner['source_file'](revision, PROBE, PROBE_BLOB)
    manifest = owner['source_file'](owner['APP'], 'deployment/desktop_dashboard_server_manifest.json', MANIFEST_BLOB)
    folder = Path(tempfile.mkdtemp(prefix='go-erp-postbackup-probe-', dir='/tmp'))
    try:
        folder.chmod(0o755)
        for name, data in [('probe.php', payload), ('manifest.json', manifest)]:
            with (folder / name).open('xb') as stream:
                stream.write(data)
                os.fchmod(stream.fileno(), 0o644)
        private = folder / 'private'
        private.mkdir(mode=0o700)
        os.chown(private, UID, GID)
        env = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha'}
        result = runner(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i',
            *[key + '=' + val for key, val in env.items()], PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(folder / 'probe.php'), str(ROOT), str(private), str(folder / 'manifest.json')],
            env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90, check=False)
        need(result.returncode in (0, 1) and len(result.stdout) <= 256 * 1024
             and len(result.stderr) <= 65536, 'PROBE_PROCESS_UNAVAILABLE')
        value = validate_probe(json.loads(result.stdout), context['manifest'])
        need(result.returncode == (0 if value['available'] else 1), 'PROBE_EXIT_STATUS_INVALID')
        return value
    except Exception:
        return {'kind': 'go-erp-postbackup-read-only-probe', 'available': False,
                'phase': 'PROCESS', 'error_class': 'ProbeUnavailable'}
    finally:
        shutil.rmtree(folder)

def execute(args):
    need(len(args) == 2 and args[0] == '--release' and SHA.fullmatch(args[1]), 'PINNED_INVOCATION_REQUIRED')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    actor = pwd.getpwnam('fasakha')
    need((actor.pw_uid, actor.pw_gid) == (UID, GID) and ROOT.resolve() == ROOT, 'PROJECT_ACCOUNT_UNSAFE')
    for path in (ROOT, ROOT / '.git'):
        item = path.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == UID, 'PROJECT_UNSAFE')
    data = git(['cat-file', 'blob', OWNER_BLOB])
    need(blob(data) == OWNER_BLOB, 'OWNER_HELPER_PIN_INVALID')
    owner = {'__name__': 'reviewed_postbackup_owner'}
    exec(compile(data, OWNER, 'exec'), owner)
    need(owner['source_file'](args[1], OWNER, OWNER_BLOB) == data, 'OWNER_HELPER_NOT_IN_RELEASE')
    owner['git'](['merge-base', '--is-ancestor', B2, args[1]], 1024)
    context = owner['reviewed_context']()
    need(context['complete']['private_entry'](Path(__file__).absolute()) == owner['source_file'](args[1], SELF),
         'ENTRYPOINT_NOT_REVIEWED')
    head = lambda: owner['git'](['rev-parse', '--verify', 'HEAD'], 64).decode('ascii').strip()
    state = owner['State']()
    before = owner['recover'](ROOT, context, head, False, state)
    need(before['state'] == 'BEFORE' and before['pending'] == 0 and before['changed'] == 0,
         'EXACT_RESTORED_BEFORE_REQUIRED')
    report = inspect_backup(ROOT, BACKUP, context['manifest'])
    report['runtime_probe'] = run_probe(owner, context, args[1])
    need(owner['recover'](ROOT, context, head, False, state) == before, 'SOURCE_CHANGED_DURING_DIAGNOSIS')
    return {'format': 1, 'kind': 'go-erp-postbackup-readonly', 'source_state': 'BEFORE', 'owner_repairs_pending': 0,
            'application_contents_changed': False, 'publication_bytes_read': False, **report}

def main(args=None, out=sys.stdout, err=sys.stderr):
    try:
        result = execute(sys.argv[1:] if args is None else args)
        print(json.dumps(result, sort_keys=True, separators=(',', ':')), file=out)
        return 0
    except Exception as error:
        code = str(error) if isinstance(error, Stop) and re.fullmatch(r'[A-Z_]{1,80}', str(error)) else 'PRIVATE_DIAGNOSIS_FAILURE'
        print('GO_ERP_POSTBACKUP_STOPPED REASON=' + code, file=err)
        return 1

if __name__ == '__main__':
    sys.exit(main())
