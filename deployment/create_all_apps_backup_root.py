#!/usr/bin/env python3
"""Private, additive backup of fasakha's cPanel account and seven app repositories.

Run only on srv1836524 as root. This does not deploy, restore, stop services, or
delete previous backups. Integrity checks do not constitute a restore rehearsal.
"""
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
import subprocess
import sys
import tarfile
import tempfile

OWNER = 'omarmakramelbaz-hub'
REPOS = ('F-Backend', 'F-customer', 'F-stores', 'F-admin', 'F-dev',
         'Go-customer', 'Go-Partner')
ACCOUNT = 'fasakha'
HOME = Path('/home/fasakha')
BASE = Path('/root/fasakhansta-full-backups')
PKGACCT = '/usr/local/cpanel/scripts/pkgacct'
UAPI = '/usr/local/cpanel/bin/uapi'


class BackupError(RuntimeError):
    pass


def need(condition, message):
    if not condition:
        raise BackupError(message)


def run(args, log=None, env=None, cwd=None):
    if log is not None:
        with open(str(log), 'ab') as output:
            result = subprocess.run(args, cwd=cwd, env=env,
                                    stdout=output, stderr=subprocess.STDOUT)
        need(result.returncode == 0, 'Command failed; private log: ' + str(log))
        return ''
    result = subprocess.run(args, cwd=cwd, env=env, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, universal_newlines=True)
    need(result.returncode == 0, 'Command failed: ' + Path(args[0]).name +
         ' (sensitive output withheld)')
    return result.stdout


def sha256(path):
    digest = hashlib.sha256()
    with open(str(path), 'rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest()


def database_names(payload):
    # UAPI's CLI JSON envelope; also accept the older cpanelresult envelope.
    outer = payload.get('cpanelresult', payload)
    result = outer.get('result', outer)
    need(result.get('status') == 1, 'cPanel database inventory failed.')
    rows = result.get('data', outer.get('data'))
    need(isinstance(rows, list), 'Unexpected cPanel database inventory format.')
    names = [row.get('database') for row in rows]
    need(names and all(isinstance(n, str) and n for n in names),
         'Expected nonempty account database inventory.')
    need(len(set(names)) == len(names), 'Duplicate account database inventory.')
    return sorted(names)


def check_gzip(path):
    with gzip.open(str(path), 'rb') as stream:
        for _ in iter(lambda: stream.read(1024 * 1024), b''):
            pass


def check_account_archive(path, databases):
    required = {'homedir/public_html/artisan', 'homedir/public_html/.env',
                'homedir/public_html/vendor/autoload.php'}
    found = set()
    sql = {}
    # Stream without extracting .env, credentials, uploaded files or SQL.
    with tarfile.open(str(path), 'r|gz') as archive:
        for member in archive:
            if not member.isfile():
                continue
            name = member.name
            for suffix in required:
                if (name == suffix or name.endswith('/' + suffix)) and member.size:
                    found.add(suffix)
            for database in databases:
                suffix = 'mysql/' + database + '.sql'
                if name == suffix or name.endswith('/' + suffix):
                    need(database not in sql, 'Duplicate database dump in archive.')
                    need(member.size > 100, 'Empty database dump: ' + database)
                    sample = archive.extractfile(member).read(2 * 1024 * 1024)
                    need(b'CREATE TABLE' in sample or b'CREATE DATABASE' in sample
                         or b'-- MySQL dump' in sample or b'-- MariaDB dump' in sample,
                         'Unrecognized SQL dump: ' + database)
                    sql[database] = member.size
    need(found == required, 'Account archive is missing required live backend files.')
    need(set(sql) == set(databases), 'Account archive is missing database dumps.')
    # Read to EOF separately to validate the gzip trailer and CRC as well.
    check_gzip(path)
    return {'required_live_files': sorted(found), 'database_dump_bytes': sql}


def check_space(destination):
    size_kb = int(run(['du', '-sk', str(HOME)]).split()[0])
    if Path('/var/lib/mysql').is_dir():
        size_kb += int(run(['du', '-sk', '/var/lib/mysql']).split()[0])
    required = 2 * size_kb * 1024 + 2 * 1024 ** 3
    free = shutil.disk_usage(str(destination)).free
    need(free > required, 'Insufficient backup space: need at least %.1f GiB; '
         'available %.1f GiB.' % (required / 1024 ** 3, free / 1024 ** 3))


def copy_sources(directory, logs):
    directory.mkdir(mode=0o700)
    env = os.environ.copy()
    env['GIT_TERMINAL_PROMPT'] = '0'
    mirrors = []
    gitlinks = set()
    for repo in REPOS:
        print('SOURCE_BACKUP=' + repo, flush=True)
        mirror = directory / (repo + '.git')
        log = logs / (repo + '.log')
        run(['git', '-c', 'credential.helper=', 'clone', '--mirror',
             'https://github.com/' + OWNER + '/' + repo + '.git', str(mirror)],
            log=log, env=env)
        run(['git', '-C', str(mirror), 'fsck', '--full'], log=log)
        refs = run(['git', '-C', str(mirror), 'for-each-ref',
                    '--format=%(objectname) %(refname)'])
        need(refs.strip(), 'Repository has no refs: ' + repo)
        (directory / (repo + '.refs.txt')).write_text(refs, encoding='utf-8')
        attrs = run(['git', '-C', str(mirror), 'log', '--all', '--format=', '-p',
                     '--', '.gitattributes', '**/.gitattributes'])
        if re.search(r'filter\s*=\s*lfs\b', attrs):
            need(shutil.which('git-lfs'), 'Git LFS is required for ' + repo)
            run(['git', '-C', str(mirror), 'lfs', 'fetch', '--all'], log=log, env=env)
        for row in refs.splitlines():
            ref = row.split(' ', 1)[1]
            if not ref.startswith(('refs/heads/', 'refs/tags/')):
                continue
            listing = run(['git', '-C', str(mirror), 'ls-tree', '-r', ref])
            for entry in listing.splitlines():
                if entry.startswith('160000 commit '):
                    gitlinks.add(entry.split()[2])
        mirrors.append(mirror)
    # A source snapshot must not claim coverage of an unavailable submodule.
    for oid in gitlinks:
        covered = False
        for mirror in mirrors:
            result = subprocess.run(['git', '-C', str(mirror), 'cat-file', '-e',
                                     oid + '^{commit}'], stdout=subprocess.DEVNULL,
                                    stderr=subprocess.DEVNULL)
            if result.returncode == 0:
                covered = True
                break
        need(covered, 'Submodule commit is outside the seven source backups: ' + oid)
    return {'repositories': list(REPOS), 'submodule_commits_checked': len(gitlinks)}


def copy_system_config(destination):
    paths = ('etc/systemd/system', 'etc/cron.d', 'etc/crontab', 'var/spool/cron',
             'etc/supervisord.conf', 'etc/supervisord.d', 'etc/supervisor',
             'etc/environment', 'etc/sysconfig', 'etc/default', 'etc/fasakhansta',
             'etc/apache2/conf.d/userdata', 'etc/nginx',
             'var/cpanel/userdata/fasakha', 'etc/cpbackup-exclude.conf')
    present = [p for p in paths if os.path.lexists('/' + p)]
    need('etc/systemd/system' in present, 'Expected systemd configuration directory.')
    run(['tar', '-czf', str(destination / 'server-config.tar.gz'), '-C', '/'] + present,
        log=destination / 'logs' / 'server-config.log')
    check_gzip(destination / 'server-config.tar.gz')
    run(['tar', '-tzf', str(destination / 'server-config.tar.gz')],
        log=destination / 'logs' / 'server-config-contents.txt')
    run(['systemctl', 'list-unit-files', '--no-pager'],
        log=destination / 'logs' / 'systemd-unit-inventory.txt')
    return present


def supplement_private_home(destination):
    # Native cPanel packaging can omit root-owned paths unreadable to the
    # account. Preserve those separately, including private desktop evidence.
    scanner = '''import json, os, sys
missing = []
def unreadable(error):
    missing.append(error.filename)
for parent, dirs, files in os.walk(sys.argv[1], onerror=unreadable):
    for name in files:
        path = os.path.join(parent, name)
        if not os.path.islink(path) and not os.access(path, os.R_OK):
            missing.append(path)
print(json.dumps(sorted(set(missing))))
'''
    raw = run(['runuser', '-u', ACCOUNT, '--', 'python3', '-c', scanner, str(HOME)])
    paths = json.loads(raw)
    for path in paths:
        need(isinstance(path, str) and path.startswith(str(HOME) + '/'),
             'Unexpected private home path.')
    inventory = destination / 'private-home-paths.json'
    inventory.write_text(json.dumps(paths, indent=2) + '\n', encoding='utf-8')
    if paths:
        listing = destination / 'private-home-files.list'
        listing.write_bytes(b''.join(os.fsencode(p.lstrip('/')) + b'\0' for p in paths))
        archive = destination / 'private-home-supplement.tar.gz'
        run(['tar', '-czf', str(archive), '-C', '/', '--null', '-T', str(listing)],
            log=destination / 'logs' / 'private-home-supplement.log')
        check_gzip(archive)
    return len(paths)


def seal(directory, manifest):
    (directory / 'manifest.json').write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    (directory / 'RESTORE-NOTES.txt').write_text(
        'Coverage: native cPanel account package for fasakha (home, databases, '
        'domains, SSL and account cron), seven Git source mirrors, and the '
        'listed server configuration paths. Native cPanel exclusions apply.\n'
        'Account-unreadable home paths are captured as root in the private '
        'home supplement, if present.\n'
        'The home package includes desktop installers/source only when they '
        'exist under that account. PC-only files, store-hosted builds, external '
        'secret stores and other hosting accounts are not included.\n'
        'The mirrors contain advertised refs and reachable history; LFS objects '
        'are fetched when LFS attributes exist in history.\n'
        'This is an online backup collected over an interval, not an atomic VPS '
        'snapshot. Native cPanel database dump behavior applies.\n'
        'Verification: SQL inventory, required live files, gzip CRC, Git fsck, '
        'source submodule coverage and SHA256. No restore rehearsal performed.\n'
        'Recovery: verify SHA256SUMS, rehearse cPanel account restoration to an '
        'isolated server, and review custom service configuration before reuse. '
        'The source mirrors can be cloned locally. Protect the original live '
        'system with a fresh backup before any requested rollback.\n', encoding='utf-8')
    files = sorted(p for p in directory.rglob('*') if p.is_file())
    sums = []
    for path in files:
        need(not path.is_symlink(), 'Unexpected symlink inside backup output.')
        relative = str(path.relative_to(directory))
        need('\n' not in relative and '\\' not in relative,
             'Unexpected backup filename.')
        sums.append(sha256(path) + '  ' + relative + '\n')
    (directory / 'SHA256SUMS').write_text(''.join(sums), encoding='utf-8')
    run(['sha256sum', '--check', '--quiet', 'SHA256SUMS'], cwd=str(directory))
    (directory / 'backup.ok').write_text('ALL_APPS_BACKUP_VERIFIED\n', encoding='utf-8')


def main():
    os.umask(0o077)
    need(os.geteuid() == 0, 'Run as root.')
    need(socket.gethostname().split('.')[0] == 'srv1836524', 'Unexpected server.')
    need(HOME.is_dir() and not HOME.is_symlink(), 'Unexpected account home.')
    need(Path('/var/cpanel/users/fasakha').is_file(), 'Missing cPanel account.')
    for executable in (PKGACCT, UAPI, 'git', 'tar', 'du', 'sha256sum', 'runuser',
                       'systemctl', 'python3'):
        need(shutil.which(executable), 'Missing executable: ' + executable)
    need(not BASE.is_symlink(), 'Backup parent must not be a symlink.')
    BASE.mkdir(mode=0o700, exist_ok=True)
    BASE.chmod(0o700)
    with open('/run/lock/fasakhansta-all-apps-backup.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise BackupError('Another complete backup is already running.')
        check_space(BASE)
        timestamp = datetime.datetime.utcnow().strftime('%Y%m%dT%H%M%SZ-')
        destination = Path(tempfile.mkdtemp(prefix=timestamp, dir=str(BASE)))
        print('BACKUP=' + str(destination), flush=True)
        try:
            (destination / 'logs').mkdir(mode=0o700)
            (destination / 'account').mkdir(mode=0o700)
            shutil.copyfile(__file__, str(destination / 'operator-script.py'))
            started = datetime.datetime.utcnow().isoformat() + 'Z'
            raw = run([UAPI, '--user=' + ACCOUNT, '--output=json',
                       'Mysql', 'list_databases'])
            (destination / 'database-inventory.json').write_text(raw, encoding='utf-8')
            databases = database_names(json.loads(raw))
            revision_cmd = ['runuser', '-u', ACCOUNT, '--', 'git', '-C',
                            str(HOME / 'public_html'), 'rev-parse', 'HEAD']
            before = run(revision_cmd).strip()
            sources = copy_sources(destination / 'sources', destination / 'logs')
            check_space(destination)
            print('CPANEL_ACCOUNT_BACKUP_STARTED', flush=True)
            run([PKGACCT, ACCOUNT, str(destination / 'account')],
                log=destination / 'logs' / 'cpanel-package.log')
            package = destination / 'account' / ('cpmove-' + ACCOUNT + '.tar.gz')
            need(package.is_file() and package.stat().st_size > 100,
                 'cPanel did not produce the expected account archive.')
            print('VERIFYING_ACCOUNT_AND_DATABASES', flush=True)
            account_check = check_account_archive(package, databases)
            private_paths = supplement_private_home(destination)
            configs = copy_system_config(destination)
            need(run(revision_cmd).strip() == before,
                 'The live backend commit changed during backup; retry.')
            seal(destination, {'account': ACCOUNT, 'started_utc': started,
                 'finished_utc': datetime.datetime.utcnow().isoformat() + 'Z',
                 'live_backend_commit': before, 'sources': sources,
                 'account_verification': account_check, 'server_config_paths': configs,
                 'private_home_paths_supplemented': private_paths,
                 'restore_rehearsal': False})
            print('ALL_APPS_BACKUP_VERIFIED\nBACKUP=' + str(destination), flush=True)
        except Exception:
            print('INCOMPLETE_BACKUP=' + str(destination), file=sys.stderr, flush=True)
            raise


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error) if isinstance(error, BackupError) else type(error).__name__
        print('BACKUP_FAILED: ' + message, file=sys.stderr)
        sys.exit(1)
