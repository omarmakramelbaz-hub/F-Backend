#!/usr/bin/env python3
"""Read-only metadata and dependency audit; never removes or rewrites files."""
import sys
sys.dont_write_bytecode = True
import errno
import itertools
import json
import os
import re
import socket
import stat
import subprocess
import tarfile

BACKUP = '/root/fasakhansta-full-backups/20261020T000547Z-current-bwj11opo'
CANDIDATES = ('/home/temp_extract', '/home/cpmove-fasakha',
              '/home/cpmove-fasakha.tar.gz', '/home/cpmove-fasakha.tar.gz.1',
              '/root/cpmove-fasakha.tar.gz')
RETAINED = ('/home/fasakha', '/root/fasakhansta-backend')
CONFIGS = ('/var/cpanel/userdata', '/etc/apache2/conf', '/etc/apache2/conf.d',
           '/usr/local/apache/conf', '/etc/nginx', '/etc/supervisor',
           '/etc/supervisord.conf', '/etc/supervisord.d', '/etc/systemd/system',
           '/usr/lib/systemd/system', '/etc/cron.d', '/etc/cron.daily',
           '/etc/cron.hourly', '/etc/cron.weekly', '/etc/cron.monthly',
           '/var/spool/cron', '/etc/crontab', '/etc/fstab', '/etc/rc.d/rc.local',
           '/home/fasakha/public_html/.env', '/home/fasakha/public_html/config',
           '/home/fasakha/public_html/bootstrap/cache',
           '/root/fasakhansta-backend/.env', '/root/fasakhansta-backend/config',
           '/root/fasakhansta-backend/bootstrap/cache')
PROC = '/proc'


class AuditError(Exception):
    pass


def need(condition, message):
    if not condition:
        raise AuditError(message)


def run(argv, cwd=None):
    result = subprocess.run(argv, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    need(result.returncode == 0, 'Command failed: ' + os.path.basename(argv[0]))
    return result.stdout


def inside(path, parent):
    return path == parent or path.startswith(parent.rstrip('/') + '/')


def read(path):
    try:
        with open(path, 'rb') as stream:
            return stream.read()
    except OSError:
        raise AuditError('Cannot read: ' + path)


def identity(path):
    try:
        info = os.lstat(path)
    except OSError:
        raise AuditError('Cannot inspect: ' + path)
    need(os.path.realpath(path) == path and not stat.S_ISLNK(info.st_mode),
         'Symlink or indirect path: ' + path)
    kind = 'directory' if stat.S_ISDIR(info.st_mode) else 'file' if stat.S_ISREG(info.st_mode) else 'other'
    return dict(path=path, type=kind, device=info.st_dev, inode=info.st_ino,
                size=info.st_size, mtime_ns=info.st_mtime_ns, ctime_ns=info.st_ctime_ns,
                uid=info.st_uid, gid=info.st_gid, mode=stat.S_IMODE(info.st_mode))


def candidates():
    paths = []
    for path in CANDIDATES:
        try:
            os.lstat(path)
        except FileNotFoundError:
            continue
        except OSError:
            raise AuditError('Cannot inspect candidate: ' + path)
        record = identity(path)
        expected = 'file' if '.tar.gz' in os.path.basename(path) else 'directory'
        need(record['type'] == expected, 'Unexpected candidate type: ' + path)
        need(record['device'] == os.stat(os.path.dirname(path)).st_dev,
             'Candidate is on another filesystem: ' + path)
        paths.append(path)
    return paths


def inspect_candidate(path):
    record = identity(path)
    if record['type'] == 'directory':
        try:
            record['top_level_names'] = sorted(os.listdir(path))
        except OSError:
            raise AuditError('Cannot list candidate: ' + path)
    else:
        try:
            with tarfile.open(path, 'r|gz') as archive:
                member = next(iter(archive), None)
                need(member is not None, 'Empty candidate archive: ' + path)
                name = member.name
                while name.startswith('./'):
                    name = name[2:]
                need(name and not name.startswith('/') and '..' not in name.split('/'),
                     'Unsafe candidate archive name: ' + path)
                record['archive_root'] = name.split('/')[0]
        except (OSError, tarfile.TarError, EOFError):
            raise AuditError('Cannot identify candidate archive: ' + path)
    return record


def verify_backup():
    need(identity(BACKUP)['type'] == 'directory', 'Missing complete backup.')
    for name in ('backup.ok', 'manifest.json', 'SHA256SUMS'):
        need(identity(BACKUP + '/' + name)['type'] == 'file', 'Invalid backup metadata.')
    marker = read(BACKUP + '/backup.ok').splitlines()
    need(b'ALL_APPS_BACKUP_VERIFIED' in marker and b'LAYOUT=SPLIT_HOME' in marker,
         'Complete split-home backup marker is absent.')
    try:
        manifest = json.loads(read(BACKUP + '/manifest.json').decode('utf-8'))
    except (ValueError, UnicodeError):
        raise AuditError('Cannot parse backup manifest.')
    need(isinstance(manifest, dict) and manifest.get('layout') == 'split-home' and
         manifest.get('account') == 'fasakha',
         'Unexpected backup layout.')
    databases = manifest.get('database_verification', {})
    need(isinstance(databases, dict) and all(
        isinstance(databases.get(name), dict) and
        databases[name].get('completion_footer') is True and
        databases[name].get('bytes', 0) > 0 and
        re.match(r'^[0-9a-f]{64}$', databases[name].get('sha256', ''))
        for name in ('fasakha_db', 'fasakha_dev_db')),
        'Missing verified account database coverage.')
    checks = read(BACKUP + '/SHA256SUMS').splitlines()
    need(checks, 'Empty backup checksum inventory.')
    names = []
    for line in checks:
        match = re.match(br'^[0-9a-f]{64}  (.+)$', line)
        need(match is not None, 'Unexpected checksum inventory format.')
        name = os.fsdecode(match.group(1))
        need(not os.path.isabs(name) and '..' not in name.split('/') and '\\' not in name,
             'Unsafe checksum inventory path.')
        need(identity(BACKUP + '/' + name)['type'] == 'file', 'Invalid checked backup file.')
        names.append(name)
    need({'manifest.json', 'home-current.tar.gz', 'account/cpmove-fasakha.tar.gz'} <= set(names),
         'Missing required complete-backup checksums.')
    run(['sha256sum', '--check', '--quiet', 'SHA256SUMS'], cwd=BACKUP)
    return manifest


def walk_error(error):
    raise AuditError('Cannot scan directory: ' + str(error.filename))


def walk_files(root, follow=False):
    seen = set()
    for parent, dirs, files in os.walk(root, followlinks=follow, onerror=walk_error):
        key = os.stat(parent)
        key = (key.st_dev, key.st_ino)
        if key in seen:
            dirs[:] = []
            continue
        seen.add(key)
        for name in list(dirs) + files:
            yield os.path.join(parent, name)


def references(data, paths):
    return any(os.fsencode(path) in data for path in paths)


def file_references(path, paths):
    needles = [os.fsencode(target) for target in paths]
    overlap = max([len(needle) for needle in needles] or [1])
    previous = b''
    try:
        with open(path, 'rb') as stream:
            while True:
                block = stream.read(1024 * 1024)
                if not block:
                    return False
                if references(previous + block, paths):
                    return True
                previous = block[-overlap:]
    except OSError:
        raise AuditError('Cannot read configuration: ' + path)


def scan_mounts(paths):
    found = []
    for line in read(PROC + '/self/mountinfo').decode('utf-8', 'surrogateescape').splitlines():
        fields = line.split()
        need(len(fields) >= 10 and '-' in fields, 'Cannot parse mount inventory.')
        for raw in fields[3:5]:
            path = re.sub(r'\\([0-7]{3})', lambda m: chr(int(m.group(1), 8)), raw)
            if any(inside(path, target) for target in paths):
                found.append('mount:' + path)
    return found


def scan_symlinks(paths):
    found = []
    for root in RETAINED:
        need(os.path.isdir(root) and os.path.realpath(root) == root, 'Missing retained tree: ' + root)
        for path in walk_files(root):
            if stat.S_ISLNK(os.lstat(path).st_mode):
                if any(inside(os.path.realpath(path), target) for target in paths):
                    found.append('retained-symlink:' + path)
    return found


def scan_configs(paths):
    found = []
    for root in CONFIGS:
        try:
            os.lstat(root)
        except FileNotFoundError:
            continue
        except OSError:
            raise AuditError('Cannot inspect configuration: ' + root)
        if any(inside(os.path.realpath(root), target) for target in paths):
            found.append('config-symlink:' + root)
        try:
            root_mode = os.stat(root).st_mode
        except OSError:
            raise AuditError('Cannot access configuration: ' + root)
        items = walk_files(root, follow=True) if stat.S_ISDIR(root_mode) else (root,)
        for path in items:
            try:
                mode = os.stat(path).st_mode
            except OSError:
                raise AuditError('Cannot access configuration: ' + path)
            if any(inside(os.path.realpath(path), target) for target in paths):
                found.append('config-symlink:' + path)
            elif stat.S_ISREG(mode) and file_references(path, paths):
                found.append('config:' + path)
    units = run(['systemctl', 'list-units', '--type=service', '--all', '--no-legend', '--no-pager', '--plain'])
    names = [token.decode('utf-8') for line in units.splitlines()
             for token in line.split()[:2] if token.endswith(b'.service')]
    need(names, 'Cannot establish effective systemd service settings.')
    settings = run(['systemctl', 'show', '--no-pager', '--property=Id,FragmentPath,DropInPaths,WorkingDirectory,RootDirectory,ExecStart,ExecStartPre,ExecStartPost,Environment,EnvironmentFiles'] + names)
    for block in settings.split(b'\n\n'):
        if references(block, paths):
            unit = next((line[3:].decode('utf-8') for line in block.splitlines() if line.startswith(b'Id=')), 'unknown-unit')
            found.append('systemd:' + unit)
    return found


def proc_gone(error):
    return isinstance(error, OSError) and error.errno in (errno.ENOENT, errno.ESRCH)


def proc_value(path, link=False):
    try:
        if link:
            return os.readlink(path).replace(' (deleted)', '')
        with open(path, 'rb') as stream:
            return stream.read()
    except OSError as error:
        if proc_gone(error):
            return None
        raise AuditError('Cannot inspect process entry: ' + path)


def scan_processes(paths):
    found = []
    try:
        pids = [name for name in os.listdir(PROC) if name.isdigit()]
    except OSError:
        raise AuditError('Cannot enumerate processes.')
    for pid in pids:
        base = PROC + '/' + pid
        try:
            for field in ('cwd', 'exe'):
                target = proc_value(base + '/' + field, link=True)
                if target and any(inside(target, old) for old in paths):
                    found.append('process-' + field + ':' + base)
            for fd in os.listdir(base + '/fd'):
                target = proc_value(base + '/fd/' + fd, link=True)
                if target and any(inside(target, old) for old in paths):
                    found.append('process-fd:' + base)
            for field in ('maps', 'cmdline'):
                data = proc_value(base + '/' + field)
                if data and references(data, paths):
                    found.append('process-' + field + ':' + base)
                if field == 'cmdline' and data:
                    names = [os.path.basename(os.fsdecode(arg)) for arg in data.split(b'\0') if arg]
                    if any(name in ('pkgacct', 'restorepkg', 'cpbackup') for name in names) or b'/usr/local/cpanel/bin/backup' in data:
                        found.append('cpanel-backup-process:' + base)
                    cwd = proc_value(base + '/cwd', link=True)
                    if cwd:
                        for arg in data.split(b'\0'):
                            value = os.fsdecode(arg).split('=', 1)[-1]
                            if '/' in value and any(inside(os.path.realpath(os.path.join(cwd, value)), old) for old in paths):
                                found.append('process-argv-path:' + base)
        except OSError as error:
            if not proc_gone(error):
                raise AuditError('Cannot scan process: ' + base)
    return found


def dependency_check(paths):
    try:
        return sorted(set(itertools.chain(scan_mounts(paths), scan_symlinks(paths),
                                          scan_configs(paths), scan_processes(paths))))
    except OSError:
        raise AuditError('Cannot complete dependency inventory.')


def audit():
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'Run as root only on srv1836524.')
    print('AUDIT_ONLY=NO_FILES_REMOVED', flush=True)
    print('VERIFYING_PINNED_COMPLETE_BACKUP', flush=True)
    verify_backup()
    print('PINNED_COMPLETE_BACKUP_VERIFIED', flush=True)
    paths = candidates()
    records = [inspect_candidate(path) for path in paths]
    for record in records:
        print('CANDIDATE=' + json.dumps(record, sort_keys=True), flush=True)
    found = dependency_check(paths)
    for dependency in found:
        print('DEPENDENCY_FOUND=' + dependency, flush=True)
    print('AUDIT_STATUS=' + ('DEPENDENCIES_FOUND' if found else 'NO_DEPENDENCIES_OBSERVED'), flush=True)
    return records


if __name__ == '__main__':
    try:
        audit()
    except (AuditError, OSError, ValueError) as error:
        print('AUDIT_STOPPED=' + (str(error) if isinstance(error, AuditError) else type(error).__name__), file=sys.stderr)
        sys.exit(1)
