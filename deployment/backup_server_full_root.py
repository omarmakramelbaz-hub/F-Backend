#!/usr/bin/env python3
"""A full backup of srv1836524, taken before the refactor, each part checked readable:
  - every database of the site's cPanel account (one dump per database, with its routines, triggers and events);
  - the cPanel account itself (/usr/local/cpanel/scripts/pkgacct, without the home folder: settings, domains, mail);
  - the account's home folder: the site, its uploads and storage (without the framework's caches and sessions);
  - the GO ERP server state: /etc/go-erp, its systemd units, /var/lib/go-erp-*, the cron tables.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/backup_server_full_root.py            what it would back up and the space it needs
  python3 -I -B deployment/backup_server_full_root.py --apply    makes the backup in /root/fasakhansta-full-backups

--apply needs twice the estimated size free, works at low priority (the site keeps answering), and writes a
manifest.json with each part's size and SHA-256. A part that cannot be made or read back stops it, and the
unfinished backup is removed. Database names, paths and sizes are printed; no password or setting ever is.
"""
import argparse
import datetime
import glob
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import stat
import subprocess

HOST = 'srv1836524'
ACCOUNT = 'fasakha'
HOME = Path('/home/fasakha')
BACKUPS = Path('/root/fasakhansta-full-backups')
PKGACCT = '/usr/local/cpanel/scripts/pkgacct'
# Rebuilt by the site by itself: never worth keeping.
HOME_EXCLUDES = ('public_html/storage/framework/cache', 'public_html/storage/framework/sessions', 'public_html/storage/framework/views',
                 '.cpanel/caches', '.trash', 'tmp')
SYSTEM = ('/etc/go-erp', '/etc/systemd/system/go-erp-*', '/var/lib/go-erp-*', '/var/spool/cron', '/etc/cron.d')
LOW = ['nice', '-n', '15', 'ionice', '-c', '3']


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_FULL_BACKUP_' + reason)


def run(command, **options):
    return subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False, **options)


def databases():
    """The account's databases (cPanel names them <account>_...), read with root's own MySQL access."""
    result = run(['mysql', '--batch', '--skip-column-names', '-e', 'SHOW DATABASES'])
    need(result.returncode == 0, 'MYSQL_NOT_READABLE')
    names = [line.strip() for line in result.stdout.decode('utf-8', 'replace').splitlines()]
    return sorted(name for name in names if name == ACCOUNT or name.startswith(ACCOUNT + '_'))


def size_of(paths):
    total = 0
    for path in paths:
        result = run(['du', '-sb', path])
        if result.returncode in (0, 1) and result.stdout:
            total += int(result.stdout.split()[0])
    return total


def system_paths():
    found = []
    for pattern in SYSTEM:
        found.extend(sorted(path for path in glob.glob(pattern) if os.path.exists(path)))
    return found


def free_bytes(folder):
    probe = Path(folder)
    while not probe.exists() and probe != probe.parent:
        probe = probe.parent
    return shutil.disk_usage(str(probe)).free


def plan():
    names = databases()
    database_bytes = size_of(['/var/lib/mysql/' + name for name in names])
    home_bytes = size_of([str(HOME)])
    system_bytes = size_of(system_paths())
    estimate = database_bytes + home_bytes + system_bytes
    free = free_bytes(BACKUPS)
    return {'result': 'GO_ERP_FULL_BACKUP_PLAN', 'databases': names, 'database_mb': database_bytes // 1048576, 'home_mb': home_bytes // 1048576,
            'system_mb': system_bytes // 1048576, 'estimate_mb': estimate // 1048576, 'free_mb': free // 1048576,
            'enough_space': free >= 2 * estimate, 'cpanel_account_backup': os.access(PKGACCT, os.X_OK), 'folder': str(BACKUPS)}


def sha256_of(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as stream:
        for block in iter(lambda: stream.read(4 * 1048576), b''):
            digest.update(block)
    return digest.hexdigest()


def gzip_ok(path):
    return run(LOW + ['gzip', '-t', str(path)]).returncode == 0


def dump_database(name, target):
    """mysqldump | gzip, both checked; the dump must end as mysqldump ends a complete one."""
    with open(target, 'wb') as output:
        dump = subprocess.Popen(LOW + ['mysqldump', '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob',
                                       '--default-character-set=utf8mb4', '--databases', name], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        pack = subprocess.Popen(['gzip', '-6'], stdin=dump.stdout, stdout=output, stderr=subprocess.DEVNULL)
        dump.stdout.close()
        pack.wait()
        dump.wait()
    need(dump.returncode == 0 and pack.returncode == 0, 'DATABASE_DUMP_FAILED: ' + name)
    need(gzip_ok(target), 'DATABASE_DUMP_UNREADABLE: ' + name)
    with gzip.open(str(target), 'rb') as stream:
        tail = b''
        for block in iter(lambda: stream.read(1048576), b''):
            tail = (tail + block)[-4096:]
    need(b'-- Dump completed' in tail, 'DATABASE_DUMP_INCOMPLETE: ' + name)


def archive(target, base, members, excludes=()):
    """tar.gz of members under base; files that change while being read (logs) do not stop it, anything else does."""
    command = LOW + ['tar', '--create', '--gzip', '--file', str(target), '--directory', base, '--warning=no-file-changed',
                     '--warning=no-file-removed'] + ['--exclude=' + item for item in excludes] + list(members)
    result = run(command)
    need(result.returncode in (0, 1), 'ARCHIVE_FAILED: ' + target.name)
    need(gzip_ok(target), 'ARCHIVE_UNREADABLE: ' + target.name)


def apply():
    found = plan()
    need(found['enough_space'], 'NOT_ENOUGH_SPACE')
    need(found['databases'], 'NO_DATABASE_FOUND')
    if not BACKUPS.exists():
        BACKUPS.mkdir(mode=0o700, parents=True)
    folder = BACKUPS / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-before-refactor')
    folder.mkdir(mode=0o700)
    try:
        parts = []
        (folder / 'databases').mkdir(mode=0o700)
        for name in found['databases']:
            target = folder / 'databases' / (name + '.sql.gz')
            dump_database(name, target)
            parts.append(target)
        if found['cpanel_account_backup']:
            (folder / 'account').mkdir(mode=0o700)
            result = run(LOW + [PKGACCT, '--skiphomedir', ACCOUNT, str(folder / 'account')], timeout=7200)
            made = sorted((folder / 'account').glob('cpmove-' + ACCOUNT + '*.tar.gz'))
            need(result.returncode == 0 and made, 'CPANEL_ACCOUNT_BACKUP_FAILED')
            need(gzip_ok(made[-1]), 'CPANEL_ACCOUNT_BACKUP_UNREADABLE')
            parts.append(made[-1])
        home = folder / 'home-current.tar.gz'
        archive(home, str(HOME.parent), [HOME.name], [HOME.name + '/' + item for item in HOME_EXCLUDES])
        parts.append(home)
        system = system_paths()
        if system:
            runtime = folder / 'server-runtime.tar.gz'
            archive(runtime, '/', [path.lstrip('/') for path in system])
            parts.append(runtime)
        manifest = {'format': 1, 'host': HOST, 'made_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'databases': found['databases'],
                    'home_excludes': list(HOME_EXCLUDES), 'system': system,
                    'parts': [{'file': str(part.relative_to(folder)), 'bytes': part.stat().st_size, 'sha256': sha256_of(part)} for part in parts]}
        (folder / 'manifest.json').write_text(json.dumps(manifest, indent=1, ensure_ascii=False))
        for item in folder.rglob('*'):
            os.chmod(str(item), 0o700 if item.is_dir() else 0o600)
    except BaseException:
        shutil.rmtree(str(folder), ignore_errors=True)
        raise
    total = sum(part['bytes'] for part in manifest['parts'])
    return {'result': 'GO_ERP_FULL_BACKUP_DONE', 'folder': str(folder), 'total_mb': total // 1048576,
            'parts': {part['file']: part['bytes'] // 1048576 for part in manifest['parts']}, 'databases': len(found['databases']),
            'free_mb_after': free_bytes(folder) // 1048576}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--apply', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need(HOME.is_dir(), 'ACCOUNT_HOME_NOT_FOUND')
    os.umask(0o077)
    result = apply() if options.apply else plan()
    print(json.dumps(result, sort_keys=True, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_FULL_BACKUP_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
