#!/usr/bin/env python3
"""Create a checked current backup before pruning three owner-authorized old trees.

The home is streamed as root, separately from cPanel's --skiphomedir package.
No source deployment, database restoration, or service restart is performed.
"""
import datetime
import errno
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

import create_all_apps_backup_root as core

HOME = core.HOME
BASE = core.BASE
OLD_NAMES = ('erp-release-backups', 'pre-restore-20261003-001744', 'restore_old')
RUNTIME_DIRS = ('public_html/storage/logs', 'public_html/storage/debugbar',
                'public_html/storage/framework/cache',
                'public_html/storage/framework/views',
                'public_html/storage/framework/sessions')
RESERVE = 3 * 1024 ** 3
DATABASE_MAP = Path('/var/cpanel/databases/fasakha.json')
need = core.need
run = core.run


def database_inventory_from_map():
    # Read cPanel's account ownership registry directly. The uapi launcher is
    # unavailable on this server; neither permissions nor the registry change.
    need(DATABASE_MAP.is_file() and not DATABASE_MAP.is_symlink(),
         'Missing regular cPanel account database ownership map.')
    try:
        document = json.loads(DATABASE_MAP.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        raise core.BackupError('Cannot read/parse the cPanel database ownership map.')
    mysql = document.get('MYSQL')
    need(isinstance(mysql, dict) and mysql.get('owner') == core.ACCOUNT,
         'cPanel database map has an unexpected account owner.')
    databases = mysql.get('dbs')
    need(isinstance(databases, dict) and databases,
         'cPanel ownership map has no account MySQL databases.')
    names = sorted(databases)
    need(all(isinstance(name, str) and name and
             not any(char.isspace() or char in '/\\\0' for char in name)
             for name in names), 'Unexpected database name in cPanel ownership map.')
    postgres = document.get('PGSQL') or {}
    need(isinstance(postgres, dict) and not postgres.get('dbs'),
         'PostgreSQL databases require an additional archive verification step.')
    environment = HOME / 'public_html' / '.env'
    need(environment.is_file(), 'Missing primary application environment file.')
    text = environment.read_text(encoding='utf-8')
    match = re.search(r'^\s*(?:export\s+)?DB_DATABASE\s*=\s*([^\r\n]*)', text, re.M)
    need(match is not None, 'Cannot establish the primary application database name.')
    value = match.group(1).strip()
    if value.startswith(('\x22', "'")):
        quoted = re.fullmatch(r'([\x22\x27])(.*?)\1\s*(?:#.*)?', value)
        need(quoted is not None, 'Unsupported quoted primary database name.')
        primary = quoted.group(2)
    else:
        primary = value.split(' #', 1)[0].strip()
    need(primary in names, 'The primary application database is absent from the ownership map.')
    # The owner supplied an inventory showing these two local databases.
    for observed in ('fasakha_db', 'fasakha_dev_db'):
        if (Path('/var/lib/mysql') / observed).is_dir():
            need(observed in names, 'An observed account database is absent from the ownership map.')
    return {'source': 'cpanel_account_ownership_map', 'account': core.ACCOUNT,
            'map_path': str(DATABASE_MAP), 'map_sha256': core.sha256(DATABASE_MAP),
            'databases': names, 'primary_application_database': primary}


def inside(path, parent):
    return path == parent or str(path).startswith(str(parent) + '/')


def candidate_paths():
    result = []
    for name in OLD_NAMES:
        path = HOME / name
        if not os.path.lexists(str(path)):
            continue
        need(path.is_dir() and not path.is_symlink() and path.resolve() == path,
             'Unexpected old backup path: ' + str(path))
        need(path.stat().st_dev == HOME.stat().st_dev,
             'Old backup uses another filesystem: ' + str(path))
        result.append(path)
    return result


def walk_error(error):
    raise core.BackupError('Cannot inventory directory: ' + str(error.filename))


def home_inventory(candidates):
    excluded = {str(p.relative_to(HOME)) for p in candidates}
    result = {'.': {'type': 'directory'}}
    for parent, dirs, files in os.walk(str(HOME), topdown=True, followlinks=False,
                                      onerror=walk_error):
        relative_parent = os.path.relpath(parent, str(HOME))
        if relative_parent in RUNTIME_DIRS:
            dirs[:] = []
            continue
        for name in list(dirs) + files:
            path = Path(parent) / name
            relative = str(path.relative_to(HOME))
            if relative in excluded:
                if name in dirs:
                    dirs.remove(name)
                continue
            info = path.lstat()
            if stat.S_ISLNK(info.st_mode):
                resolved = path.resolve()
                need(not any(inside(resolved, old) for old in candidates),
                     'A retained home symlink depends on an old tree: ' + str(path))
                result[relative] = {'type': 'symlink', 'target': os.readlink(str(path))}
            elif stat.S_ISDIR(info.st_mode):
                result[relative] = {'type': 'directory'}
            elif stat.S_ISREG(info.st_mode):
                result[relative] = {'type': 'file', 'size': info.st_size}
            else:
                raise core.BackupError('Unexpected special file in home: ' + str(path))
    return result


def assert_no_old_path(text, candidates, source):
    for path in candidates:
        if re.search(re.escape(str(path)) + r'(?=$|[/\s;\x22\x27\x29\x7d])', text):
            raise core.BackupError('Old tree is referenced by ' + source + ': ' + str(path))


def dependency_check(candidates):
    # Symlinks are inspected by the root home inventory, excluding only old roots.
    inventory = home_inventory(candidates)
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        fields = line.split()
        for raw in fields[3:5]:
            path = Path(re.sub(r'\\([0-7]{3})',
                               lambda m: chr(int(m.group(1), 8)), raw))
            need(not any(inside(path, old) for old in candidates),
                 'A mount depends on an old tree: ' + str(path))
    config_files = set()
    userdata = Path('/var/cpanel/userdata/fasakha')
    if userdata.is_dir():
        config_files.update(p for p in userdata.iterdir() if p.is_file())
    live = HOME / 'public_html'
    config_files.update(live.glob('.env*'))
    for root in (live / 'app', live / 'config', live / 'routes',
                 live / 'bootstrap' / 'cache', HOME / '.config'):
        if root.is_dir():
            config_files.update(p for p in root.rglob('*') if p.is_file())
    unit_rows = run(['systemctl', 'list-units', '--type=service', '--all',
                     '--no-legend', '--plain', '--no-pager'])
    units = []
    for line in unit_rows.splitlines():
        units.extend(word for word in line.split()[:2] if word.endswith('.service'))
    if units:
        settings = run(['systemctl', 'show', '--property=WorkingDirectory',
                        '--property=ExecStart', '--property=EnvironmentFiles'] + units)
        assert_no_old_path(settings, candidates, 'effective systemd settings')
        for match in re.findall(r'EnvironmentFiles=([^\n]*)', settings):
            for name in re.findall(r'/[^\s();{}]+', match):
                path = Path(name)
                if path.is_file():
                    config_files.add(path)
    for path in config_files:
        if path.is_file() and path.stat().st_size < 8 * 1024 ** 2:
            assert_no_old_path(path.read_text(encoding='utf-8', errors='replace'),
                               candidates, 'configuration ' + str(path))
    for process in Path('/proc').iterdir():
        if not process.name.isdigit():
            continue
        targets = [process / 'cwd', process / 'exe']
        try:
            targets.extend((process / 'fd').iterdir())
        except OSError:
            pass  # Kernel processes and processes that exited during inventory.
        for path in targets:
            try:
                target = os.readlink(str(path)).replace(' (deleted)', '')
            except OSError:
                continue
            if target.startswith('/'):
                need(not any(inside(Path(target), old) for old in candidates),
                     'A running process uses an old tree: ' + str(path))
    return inventory


def fingerprint(path):
    need(path.is_dir() and not path.is_symlink() and path.resolve() == path,
         'Old backup identity changed: ' + str(path))
    info = path.stat()
    digest = hashlib.sha256()
    for parent, dirs, files in os.walk(str(path), followlinks=False, onerror=walk_error):
        dirs.sort()
        for name in ['.'] + sorted(dirs + files):
            item = Path(parent) if name == '.' else Path(parent) / name
            metadata = item.lstat()
            record = [str(item.relative_to(path)), metadata.st_mode, metadata.st_dev,
                      metadata.st_ino, metadata.st_size, metadata.st_mtime_ns]
            if item.is_symlink():
                record.append(os.readlink(str(item)))
            digest.update((json.dumps(record, ensure_ascii=True) + '\n').encode())
    return {'path': str(path), 'device': info.st_dev, 'inode': info.st_ino,
            'metadata_sha256': digest.hexdigest()}


def current_size(candidates):
    exclusions = [str(p) for p in candidates]
    exclusions += [str(HOME / p) + '/*' for p in RUNTIME_DIRS]
    args = ['du', '-sk', '--apparent-size']
    args += ['--exclude=' + p for p in exclusions]
    args.append(str(HOME))
    return int(run(args).split()[0]) * 1024


def check_space(destination, candidates, include_home=True):
    home_bytes = current_size(candidates) if include_home else 0
    # All local MySQL files bound the smaller fasakha account dump/staging.
    db_bytes = 0
    if Path('/var/lib/mysql').is_dir():
        db_bytes = int(run(['du', '-sk', '/var/lib/mysql']).split()[0]) * 1024
    required = int(home_bytes * 1.15) + 4 * db_bytes + RESERVE + 1024 ** 3
    free = shutil.disk_usage(str(destination)).free
    need(free > required, 'Insufficient space for current data: need %.1f GiB; '
         'available %.1f GiB.' % (required / 1024 ** 3, free / 1024 ** 3))
    return {'included_home_apparent_bytes': home_bytes,
            'local_mysql_bytes_upper_bound': db_bytes, 'required_bytes': required,
            'available_bytes_before': free, 'reserve_bytes': RESERVE}


def stop_process_group(process):
    if process.poll() is not None:
        return
    os.killpg(process.pid, signal.SIGTERM)
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()


def package_account(destination):
    log = destination / 'logs' / 'cpanel-package.log'
    args = [core.PKGACCT, '--skiphomedir', '--dbbackup=all', core.ACCOUNT,
            str(destination / 'account')]
    with log.open('ab') as output:
        process = subprocess.Popen(args, stdout=output, stderr=subprocess.STDOUT,
                                   start_new_session=True, cwd='/')
        try:
            while process.poll() is None:
                need(shutil.disk_usage(str(destination)).free > RESERVE,
                     'Operating space reserve reached; only backup process stopped.')
                time.sleep(0.25)
            need(process.returncode == 0, 'cPanel backup failed; private log: ' + str(log))
        finally:
            stop_process_group(process)
    archive = destination / 'account' / ('cpmove-' + core.ACCOUNT + '.tar.gz')
    need(archive.is_file(), 'cPanel account package was not produced.')
    return archive


def verify_sql_package(path, databases):
    results = {}
    with tarfile.open(str(path), 'r|gz') as archive:
        for member in archive:
            if not member.isfile():
                continue
            for database in databases:
                suffix = 'mysql/' + database + '.sql'
                if member.name != suffix and not member.name.endswith('/' + suffix):
                    continue
                need(database not in results and member.size > 100,
                     'Missing, duplicate or empty SQL dump: ' + database)
                digest = hashlib.sha256()
                size = 0
                tail = b''
                stream = archive.extractfile(member)
                for block in iter(lambda: stream.read(1024 * 1024), b''):
                    digest.update(block)
                    size += len(block)
                    tail = (tail + block)[-65536:]
                need(size == member.size and b'-- Dump completed' in tail,
                     'SQL dump has no successful completion footer: ' + database)
                results[database] = {'bytes': size, 'sha256': digest.hexdigest(),
                                     'completion_footer': True}
    need(set(results) == set(databases), 'Account package is missing a database.')
    core.check_gzip(path)
    return results


def stream_home(destination, candidates):
    archive = destination / 'home-current.tar.gz'
    exclusions = ['./' + str(p.relative_to(HOME)) for p in candidates]
    exclusions += ['./' + p + '/*' for p in RUNTIME_DIRS]
    args = ['tar', '--acls', '--xattrs', '--numeric-owner', '-cf', '-']
    args += ['--exclude=' + p for p in exclusions]
    args += ['-C', str(HOME), '.']
    env = os.environ.copy()
    env['LC_ALL'] = 'C'
    log = destination / 'logs' / 'home-stream.log'
    with log.open('wb') as errors:
        process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=errors,
                                   start_new_session=True, cwd='/', env=env)
        try:
            with gzip.open(str(archive), 'wb', compresslevel=6) as output:
                for block in iter(lambda: process.stdout.read(1024 * 1024), b''):
                    need(shutil.disk_usage(str(destination)).free > RESERVE,
                         'Operating space reserve reached during home backup.')
                    output.write(block)
            process.stdout.close()
            process.wait()
        finally:
            stop_process_group(process)
            if process.stdout:
                process.stdout.close()
    warnings = []
    if process.returncode == 1:
        # Directory mtimes can change online. A changing/removing regular file
        # is never accepted as a complete backup eligible for old-tree pruning.
        for line in log.read_text(errors='replace').splitlines():
            match = re.fullmatch(r'tar: (.+): file changed as we read it', line)
            need(match is not None, 'Home archive warning needs review: ' + str(log))
            relative = match.group(1)
            path = HOME / relative
            need(path.is_dir() and not path.is_symlink() and inside(path.resolve(), HOME),
                 'A live file changed while being archived; old backups retained.')
            warnings.append(relative)
        need(warnings, 'Home archive was not successful; old backups retained.')
    else:
        need(process.returncode == 0, 'Home archive failed; private log: ' + str(log))
    return archive, warnings


def verify_home(path, inventory, candidates):
    expected = set(inventory)
    found = set()
    required = {'public_html/artisan', 'public_html/.env',
                'public_html/vendor/autoload.php'}
    valid_required = set()
    excluded = {str(p.relative_to(HOME)) for p in candidates}
    with tarfile.open(str(path), 'r|gz') as archive:
        for member in archive:
            name = member.name
            while name.startswith('./'):
                name = name[2:]
            name = name.rstrip('/') or '.'
            need(not name.startswith('/') and '..' not in Path(name).parts,
                 'Unexpected archive path.')
            need(not any(name == p or name.startswith(p + '/') for p in excluded),
                 'Old backup tree was unexpectedly included in current home archive.')
            for runtime in RUNTIME_DIRS:
                need(not name.startswith(runtime + '/'),
                     'Runtime cache/log contents unexpectedly included.')
            if name in required and member.isfile() and member.size > 0:
                valid_required.add(name)
            if name in inventory:
                kind = inventory[name]['type']
                valid = ((kind == 'file' and (member.isfile() or member.islnk())) or
                         (kind == 'directory' and member.isdir()) or
                         (kind == 'symlink' and member.issym() and
                          member.linkname == inventory[name]['target']))
                need(valid, 'Home archive changed entry type/target: ' + name)
                found.add(name)
    need(valid_required == required, 'Home archive lacks required live backend files.')
    need(found == expected, 'Home archive does not cover the entire initial current inventory.')
    core.check_gzip(path)
    return {'inventory_entries_verified': len(found),
            'required_live_files': sorted(valid_required)}


def seal_current(destination, manifest):
    (destination / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (destination / 'RESTORE-NOTES.txt').write_text(
        'SPLIT-HOME complete current backup. The cPanel account package was made '
        'with --skiphomedir --dbbackup=all; it requires home-current.tar.gz too.\n'
        'Home was streamed as root, including local edits, .env, dependencies, '
        'uploads, desktop files and private account files. Only the three '
        'explicit historical trees and the listed regenerable Laravel runtime '
        'contents were excluded. Runtime directory metadata is retained.\n'
        'Recovery: verify SHA256SUMS; rehearse restoring the cPanel account '
        'package to an isolated cPanel server, then restore home-current.tar.gz '
        'into that account home with reviewed numeric ownership/ACL mapping. '
        'Review custom server-config.tar.gz before enabling services.\n'
        'Seven Git source mirrors are included. External LFS/submodules are '
        'subject to the source mirror validation described in the manifest. '
        'PC-only files and store-hosted releases are not included.\n'
        'This is an online backup over an interval, not an atomic VPS snapshot. '
        'SQL completion footers, gzip CRC, home coverage, Git fsck and SHA256 '
        'were checked. No database import or full restore rehearsal performed.\n'
        'prune-result.json and PRUNE-SHA256SUMS, when present, describe the '
        'subsequent owner-authorized removal of the three old directories.\n')
    lines = []
    for path in sorted(destination.rglob('*')):
        if not path.is_file():
            continue
        need(not path.is_symlink(), 'Unexpected symlink in new backup.')
        name = str(path.relative_to(destination))
        need('\n' not in name and '\\' not in name, 'Unexpected backup filename.')
        lines.append(core.sha256(path) + '  ' + name + '\n')
    (destination / 'SHA256SUMS').write_text(''.join(lines))
    run(['sha256sum', '--check', '--quiet', 'SHA256SUMS'], cwd=str(destination))
    (destination / 'backup.ok').write_text('ALL_APPS_BACKUP_VERIFIED\nLAYOUT=SPLIT_HOME\n')


def prune_old(destination, planned):
    need((destination / 'backup.ok').is_file() and
         'LAYOUT=SPLIT_HOME' in (destination / 'backup.ok').read_text(),
         'No checked new complete backup; old backups retained.')
    manifest = json.loads((destination / 'manifest.json').read_text())
    need(manifest.get('layout') == 'split-home' and manifest.get('excluded_old_trees') == planned,
         'New backup does not match the exact pruning plan.')
    run(['sha256sum', '--check', '--quiet', 'SHA256SUMS'], cwd=str(destination))
    paths = candidate_paths()
    need({str(p) for p in paths} == {p['path'] for p in planned},
         'Old directory set changed; pruning stopped.')
    dependency_check(paths)
    for path in paths:
        expected = next(p for p in planned if p['path'] == str(path))
        need(fingerprint(path) == expected, 'An old tree changed during backup: ' + str(path))
    need(getattr(shutil.rmtree, 'avoids_symlink_attacks', False),
         'Safe directory removal is unavailable; old trees retained.')
    before = shutil.disk_usage(str(BASE)).free
    quarantine = Path(tempfile.mkdtemp(prefix='prune-staging-', dir=str(BASE)))
    need(quarantine.stat().st_dev == HOME.stat().st_dev,
         'Prune staging must use the same filesystem as the old backups.')
    removed = []
    try:
        for path in paths:
            expected = next(p for p in planned if p['path'] == str(path))
            current = path.lstat()
            need(stat.S_ISDIR(current.st_mode) and current.st_dev == expected['device'] and
                 current.st_ino == expected['inode'], 'Old path identity changed.')
            target = quarantine / path.name
            os.rename(str(path), str(target))
            need(target.stat().st_dev == expected['device'] and
                 target.stat().st_ino == expected['inode'], 'Prune rename identity mismatch.')
            shutil.rmtree(str(target))
            removed.append(str(path))
        quarantine.rmdir()
    except Exception:
        print('PRUNE_STAGING_RETAINED=' + str(quarantine), file=sys.stderr, flush=True)
        raise
    report = {'removed_paths': removed, 'finished_utc': utc_now(),
              'free_before_bytes': before,
              'free_after_bytes': shutil.disk_usage(str(BASE)).free}
    (destination / 'prune-result.json').write_text(json.dumps(report, indent=2) + '\n')
    (destination / 'PRUNE-SHA256SUMS').write_text(
        core.sha256(destination / 'prune-result.json') + '  prune-result.json\n')
    print('OLD_BACKUPS_REMOVED=' + str(len(removed)), flush=True)
    print('FREE_SPACE_GIB=%.1f' % (report['free_after_bytes'] / 1024 ** 3), flush=True)


def utc_now():
    return datetime.datetime.utcnow().isoformat() + 'Z'


def main():
    os.umask(0o077)
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'Run as root only on srv1836524.')
    os.chdir('/')
    need(HOME.is_dir() and HOME.resolve() == HOME, 'Unexpected account home.')
    need(Path('/var/cpanel/users/fasakha').is_file(), 'Missing cPanel account.')
    for tool in (core.PKGACCT, 'git', 'tar', 'du', 'sha256sum', 'systemctl', 'runuser'):
        need(shutil.which(tool), 'Missing required executable: ' + tool)
    need(not BASE.is_symlink(), 'Unexpected backup parent symlink.')
    BASE.mkdir(mode=0o700, exist_ok=True)
    BASE.chmod(0o700)
    with open('/run/lock/fasakhansta-all-apps-backup.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise core.BackupError('Another complete backup is running.')
        database_report = database_inventory_from_map()
        databases = database_report['databases']
        print('DATABASES_FROM_CPANEL_OWNERSHIP_MAP=' + str(len(databases)), flush=True)
        candidates = candidate_paths()
        need(candidates, 'No expected old directories found; review inventory.')
        print('CHECKING_OLD_TREE_DEPENDENCIES', flush=True)
        inventory = dependency_check(candidates)
        planned = [fingerprint(p) for p in candidates]
        space = check_space(BASE, candidates)
        print('CURRENT_HOME_GIB=%.2f' % (space['included_home_apparent_bytes'] / 1024 ** 3), flush=True)
        destination = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime(
            '%Y%m%dT%H%M%SZ-current-'), dir=str(BASE)))
        print('BACKUP=' + str(destination), flush=True)
        try:
            (destination / 'logs').mkdir(mode=0o700)
            (destination / 'account').mkdir(mode=0o700)
            for script in (__file__, core.__file__):
                shutil.copyfile(script, str(destination / Path(script).name))
            (destination / 'home-inventory.json').write_text(json.dumps(inventory, indent=2))
            (destination / 'database-inventory.json').write_text(json.dumps(database_report, indent=2))
            started = utc_now()
            print('BACKING_UP_ACCOUNT_AND_ALL_DATABASES', flush=True)
            package = package_account(destination)
            sql = verify_sql_package(package, databases)
            print('VERIFYING_SOURCE_MIRRORS', flush=True)
            sources = core.copy_sources(destination / 'sources', destination / 'logs')
            check_space(destination, candidates)
            print('STREAMING_CURRENT_HOME', flush=True)
            home, warnings = stream_home(destination, candidates)
            print('VERIFYING_COMPLETE_HOME_COVERAGE', flush=True)
            home_check = verify_home(home, inventory, candidates)
            configs = core.copy_system_config(destination)
            need(database_inventory_from_map()['databases'] == databases,
                 'Account database ownership changed during backup; old backups retained.')
            manifest = {'layout': 'split-home', 'account': core.ACCOUNT,
                        'started_utc': started, 'finished_utc': utc_now(),
                        'excluded_old_trees': planned,
                        'excluded_runtime_contents': [p + '/*' for p in RUNTIME_DIRS],
                        'space_plan': space, 'sources': sources,
                        'database_verification': sql, 'home_verification': home_check,
                        'online_directory_mtime_warnings': warnings,
                        'server_config_paths': configs, 'restore_rehearsal': False}
            seal_current(destination, manifest)
            print('ALL_APPS_BACKUP_VERIFIED\nBACKUP=' + str(destination), flush=True)
            print('PRUNING_THREE_AUTHORIZED_OLD_DIRECTORIES', flush=True)
            prune_old(destination, planned)
            print('CURRENT_BACKUP_AND_CLEANUP_COMPLETE\nBACKUP=' + str(destination), flush=True)
        except Exception:
            label = 'VERIFIED_BACKUP_RETAINED=' if (destination / 'backup.ok').is_file() else 'INCOMPLETE_BACKUP='
            print(label + str(destination), file=sys.stderr, flush=True)
            raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error) if isinstance(error, core.BackupError) else type(error).__name__
        print('BACKUP_OR_CLEANUP_STOPPED: ' + message, file=sys.stderr)
        sys.exit(1)
