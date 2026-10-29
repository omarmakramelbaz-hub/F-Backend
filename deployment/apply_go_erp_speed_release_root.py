#!/usr/bin/env python3
"""Install the reviewed dashboard speed fixes into the live site for the next GO ERP release.

Run as root on srv1836524 from a clean checkout of the reviewed commit.
Without --apply it only reads and prints a plan. With --apply it keeps private
backups, then writes exactly six reviewed live files: four dashboard files, the
desktop router and one new migration (its index was added to the database
separately). Nothing else changes: no database, environment, PHP-FPM, watcher or
update state. --rollback DIR restores the files saved in that backup.
The owner then starts the release from the dashboard page "GO ERP download and
update" with the "update the app" button.
"""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile
import uuid

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
HOST = 'srv1836524'
WEB_USER = 'fasakha'
BASE = '6a9afe74203995a36416373528de5a29097fee83'
TARGETS = (
    'app/Services/Dashboard/WhatsAppInboxReadState.php',
    'database/migrations/2026_10_29_120000_add_whatsapp_inbox_unread_index.php',
    'desktop/router.php',
    'public/dashboard/js/dashboard-order-alerts.js',
    'public/js/dashboard-meta-inbox.js',
    'public/js/dashboard-whatsapp-inbox.js',
)
NEW_FILES = frozenset(('database/migrations/2026_10_29_120000_add_whatsapp_inbox_unread_index.php',))
SCOPE = frozenset(('app', 'bootstrap', 'config', 'database', 'desktop', 'public', 'resources', 'routes'))
MANUAL = Path('/var/lib/go-erp-manual-updates')
WATCHER_LOCK = Path('/var/lib/go-erp-live-updates/watcher.lock')
FEED = ROOT / 'storage/app/desktop-updates/current.json'
BACKUPS = Path('/var/lib/go-erp-speed-release')
ACTIVE = frozenset(('queued', 'running', 'waiting-ci', 'publishing'))
PACKAGE = Path(__file__).absolute().parent.parent
REV = re.compile(r'[a-f0-9]{40}\Z')
MAX_SOURCE = 4 * 1024 ** 2
GIT_ENV = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8', 'HOME': '/root',
           'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull, 'GIT_CONFIG_SYSTEM': os.devnull,
           'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_SPEED_RELEASE_' + reason)


def git(root, *arguments):
    result = subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                             '-c', 'credential.helper=', '-C', str(root)] + list(arguments),
                            env=GIT_ENV, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=300, check=False)
    need(result.returncode == 0 and len(result.stdout) <= 64 * 1024 ** 2, 'GIT_FAILED')
    return result.stdout


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid, info.st_nlink,
            info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def in_scope(name):
    return name == 'artisan' or name.split('/')[0] in SCOPE


def live_read(root, name, web_uid):
    """Return (bytes, stat) of one live file, or (None, None) when it is absent."""
    path = root / name
    current = root
    for part in name.split('/')[:-1]:
        current = current / part
        info = current.lstat()
        need(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode), 'LIVE_ANCESTRY')
    try:
        before = path.lstat()
    except FileNotFoundError:
        return None, None
    # The live-source scanner accepts only these files; anything else needs a person to look.
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_uid in (0, web_uid)
         and not before.st_mode & 0o002 and before.st_size <= MAX_SOURCE, 'LIVE_FILE_IDENTITY')
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW)
    try:
        data = os.read(fd, MAX_SOURCE + 1)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()),
             'LIVE_FILE_CHANGED')
    finally:
        os.close(fd)
    return data, before


def live_write(root, name, data, uid, gid, mode):
    path = root / name
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-speed-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
        sync_directory(path.parent)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def live_remove(root, name):
    path = root / name
    os.unlink(str(path))
    sync_directory(path.parent)


def sync_directory(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def private_write(path, data):
    fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as output:
        output.write(data)
        output.flush()
        os.fsync(output.fileno())


def read_json(path):
    try:
        return json.loads(Path(path).read_bytes().decode('utf-8'))
    except FileNotFoundError:
        return None


def release_files(package, release):
    """The running package must be the clean reviewed commit, changing exactly the six files."""
    need(git(package, 'rev-parse', 'HEAD').decode().strip() == release, 'PACKAGE_REVISION')
    need(not git(package, 'status', '--porcelain', '--untracked-files=all'), 'PACKAGE_DIRTY')
    git(package, 'merge-base', '--is-ancestor', BASE, release)
    changes = {}
    for line in git(package, 'diff', '--name-status', '--no-renames', BASE, release, '--').decode().splitlines():
        status, name = line.split('\t', 1)
        if in_scope(name):
            changes[name] = status
    need(changes == dict((name, 'A' if name in NEW_FILES else 'M') for name in TARGETS), 'UNREVIEWED_SOURCE_DELTA')
    files = {}
    for name in TARGETS:
        wanted = git(package, 'show', release + ':' + name)
        original = None if name in NEW_FILES else git(package, 'show', BASE + ':' + name)
        need(0 < len(wanted) <= MAX_SOURCE and (package / name).read_bytes() == wanted, 'RELEASE_FILE')
        files[name] = (original, wanted)
    return files


def release_idle(manual):
    status = read_json(manual / 'shared/status.json')
    state = status.get('state') if isinstance(status, dict) else None
    need(state not in ACTIVE, 'APP_UPDATE_IN_PROGRESS_RETRY_AFTER_IT_FINISHES')
    # The web page keeps last-request.json for good; only pending.json means a build is about to start.
    need(not os.path.lexists(str(manual / 'requests/pending.json')), 'APP_UPDATE_REQUEST_WAITING_RETRY_AFTER_IT_FINISHES')
    return state


def lock_watcher(path):
    """Hold the watcher's lock so no build reads the live tree while it changes."""
    try:
        fd = os.open(str(path), os.O_RDWR | os.O_NOFOLLOW)
    except FileNotFoundError:
        return None
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(fd)
        raise Stop('GO_ERP_SPEED_RELEASE_UPDATE_WORKER_BUSY_RETRY_IN_A_MINUTE') from None
    return fd


def php_lint(php, package):
    for name in TARGETS:
        if name.endswith('.php'):
            result = subprocess.run([php, '-n', '-l', str(package / name)], stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, timeout=60, check=False)
            need(result.returncode == 0, 'PHP_SYNTAX')


def inspect(root, package, release, web_uid, manual, php):
    files = release_files(package, release)
    state = release_idle(manual)
    live = dict((name, live_read(root, name, web_uid)) for name in TARGETS)
    installed = [name for name in TARGETS if live[name][0] == files[name][1]]
    published = [name for name in TARGETS if live[name][0] == files[name][0]]
    if len(installed) == len(TARGETS):
        return {'result': 'GO_ERP_SPEED_RELEASE_ALREADY_INSTALLED', 'release': release}, None
    need(len(published) == len(TARGETS), 'LIVE_FILES_DIFFER_FROM_PREVIEW_62' if not installed
         else 'PARTIAL_INSTALL_USE_ROLLBACK_OR_REVIEW')
    php_lint(php, package)
    feed = read_json(root / 'storage/app/desktop-updates/current.json')
    plan = {'result': 'GO_ERP_SPEED_RELEASE_PLAN', 'release': release, 'live_files': len(TARGETS),
            'published_version': feed.get('version') if isinstance(feed, dict) else None,
            'app_update_state': state, 'database_changed': False, 'environment_changed': False}
    return plan, {'files': files, 'live': live}


def apply(root, backups, release, web_uid, plan, context):
    files, live = context['files'], context['live']
    owners = {}
    for name in TARGETS:
        info = live[name][1]
        if info is None:
            parent = (root / name).parent.lstat()
            owners[name] = (parent.st_uid, parent.st_gid, 0o644)
        else:
            owners[name] = (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
    if not backups.exists():
        backups.mkdir(mode=0o700)
    info = backups.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077, 'BACKUP_DIRECTORY_UNSAFE')
    backup = backups / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-' + uuid.uuid4().hex[:8])
    backup.mkdir(mode=0o700)
    manifest = {'format': 1, 'release': release, 'base': BASE, 'files': {}}
    for index, name in enumerate(TARGETS):
        original = live[name][0]
        if original is not None:
            private_write(backup / ('file-%d.before' % index), original)
        manifest['files'][name] = {'index': index, 'before_sha256': None if original is None else sha256(original),
                                   'after_sha256': sha256(files[name][1]), 'uid': owners[name][0],
                                   'gid': owners[name][1], 'mode': owners[name][2]}
    private_write(backup / 'manifest.json', json.dumps(manifest, sort_keys=True).encode())
    written = []
    try:
        for name in TARGETS:
            need(live_read(root, name, web_uid)[0] == live[name][0], 'LIVE_FILE_CHANGED')
        for name in TARGETS:
            written.append(name)
            live_write(root, name, files[name][1], *owners[name])
        for name in TARGETS:
            data, info = live_read(root, name, web_uid)
            need(data == files[name][1] and (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) == owners[name],
                 'LIVE_VERIFY')
    except BaseException as error:
        restored = True
        try:
            for name in written:
                if live[name][0] is None:
                    if live_read(root, name, web_uid)[0] is not None:
                        live_remove(root, name)
                else:
                    live_write(root, name, live[name][0], *owners[name])
            restored = all(live_read(root, name, web_uid)[0] == live[name][0] for name in TARGETS)
        except BaseException:
            restored = False
        private_write(backup / 'failed.json', json.dumps({'restored': restored}).encode())
        code = str(error) if isinstance(error, Stop) else 'GO_ERP_SPEED_RELEASE_APPLY_FAILED'
        raise Stop(code + (' RESTORED' if restored else ' REVIEW_REQUIRED ' + str(backup))) from None
    private_write(backup / 'installed.json', json.dumps({'release': release}).encode())
    result = dict(plan)
    result.update(result='GO_ERP_SPEED_RELEASE_INSTALLED', backup=str(backup),
                  next_step='OWNER_PRESSES_UPDATE_THE_APP')
    return result


def rollback(root, backup, web_uid):
    info = backup.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077, 'BACKUP_UNSAFE')
    manifest = read_json(backup / 'manifest.json')
    need(isinstance(manifest, dict) and manifest.get('format') == 1 and manifest.get('base') == BASE
         and sorted(manifest.get('files', {})) == sorted(TARGETS), 'BACKUP_MANIFEST')
    plan = {}
    for name in TARGETS:
        row = manifest['files'][name]
        original = None
        if row['before_sha256'] is not None:
            original = (backup / ('file-%d.before' % row['index'])).read_bytes()
            need(sha256(original) == row['before_sha256'], 'BACKUP_FILE_CHANGED')
        current = live_read(root, name, web_uid)[0]
        current_sha = None if current is None else sha256(current)
        need(current_sha in (row['after_sha256'], row['before_sha256']), 'LIVE_FILE_CHANGED_SINCE_INSTALL')
        plan[name] = (original, row)
    for name in TARGETS:
        original, row = plan[name]
        if original is None:
            if live_read(root, name, web_uid)[0] is not None:
                live_remove(root, name)
        else:
            live_write(root, name, original, row['uid'], row['gid'], row['mode'])
    need(all(live_read(root, name, web_uid)[0] == plan[name][0] for name in TARGETS), 'ROLLBACK_VERIFY')
    return {'result': 'GO_ERP_SPEED_RELEASE_ROLLED_BACK', 'backup': str(backup)}


def run(root, package, release, web_uid, php, mode, backup=None, manual=MANUAL, backups=BACKUPS, watcher_lock=WATCHER_LOCK):
    lock = lock_watcher(watcher_lock)
    try:
        if mode == 'rollback':
            release_idle(manual)
            return rollback(root, backup, web_uid)
        plan, context = inspect(root, package, release, web_uid, manual, php)
        if context is None or mode != 'apply':
            return plan
        return apply(root, backups, release, web_uid, plan, context)
    finally:
        if lock is not None:
            os.close(lock)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--rollback')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    web_uid = pwd.getpwnam(WEB_USER).pw_uid
    if options.rollback:
        need(not options.apply and Path(options.rollback).parent == BACKUPS, 'ROLLBACK_PATH')
        result = run(ROOT, PACKAGE, None, web_uid, PHP, 'rollback', backup=Path(options.rollback))
    else:
        need(options.release is not None and REV.match(options.release) is not None, 'RELEASE')
        result = run(ROOT, PACKAGE, options.release, web_uid, PHP, 'apply' if options.apply else 'plan')
    print(json.dumps(result, sort_keys=True), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_SPEED_RELEASE_STOPPED_WITHOUT_PRIVATE_OUTPUT', flush=True)
        raise SystemExit(1)
