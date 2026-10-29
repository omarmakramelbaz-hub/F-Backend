#!/usr/bin/env python3
"""Install the server side of the new GO ERP desktop app (device setup and 30-second sync) on the live site.

Run as root on srv1836524 from a clean checkout of the reviewed commit:
  plan only:  python3 -I -B deployment/install_go_erp_desktop_sync_root.py --release COMMIT
  install:    python3 -I -B deployment/install_go_erp_desktop_sync_root.py --release COMMIT --apply
  undo:       python3 -I -B deployment/install_go_erp_desktop_sync_root.py --rollback BACKUP_DIR

The install adds six new files (one controller, three services, one routes file, one migration),
adds one line to RouteServiceProvider, creates the three new desktop_sync_* tables with that one
migration, then checks that the new address answers. Nothing else changes: no existing table, no
.env, no PHP-FPM, no old desktop update service. A private backup is kept; --rollback restores the
files (the three new tables stay; they are empty and unused without the files).
A server that already has an earlier version of these six files from this branch is upgraded in place.
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
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
HOST = 'srv1836524'
WEB_USER = 'fasakha'
# The live site matches this commit (preview 62 source plus the installed speed fixes).
BASE = '53ca2ae3d5bbdec86c1f008ef29636dbc665584e'
NEW_FILES = (
    'app/Http/Controllers/Api/DesktopSyncController.php',
    'app/Services/DesktopSync/DesktopSyncDevices.php',
    'app/Services/DesktopSync/DesktopSyncOperations.php',
    'app/Services/DesktopSync/DesktopSyncState.php',
    'database/migrations/2026_10_30_000001_create_desktop_sync_tables.php',
    'routes/desktop_sync.php',
)
NEW_DIRECTORY = 'app/Services/DesktopSync'
PROVIDER = 'app/Providers/RouteServiceProvider.php'
TARGETS = NEW_FILES + (PROVIDER,)
MIGRATION = 'database/migrations/2026_10_30_000001_create_desktop_sync_tables.php'
ANCHOR = "Route::middleware('api')->prefix('api')->group(base_path('routes/go_services.php'));"
LINE = "Route::middleware('api')->prefix('api')->group(base_path('routes/desktop_sync.php'));"
SCOPE = frozenset(('app', 'bootstrap', 'config', 'database', 'desktop', 'public', 'resources', 'routes'))
BACKUPS = Path('/var/lib/go-erp-desktop-sync')
WATCHER_LOCK = Path('/var/lib/go-erp-live-updates/watcher.lock')
PROBE_URL = 'https://fasakhaninja.com/api/desktop-sync/v1/sync'
ROUTE_CACHE = 'bootstrap/cache/routes-v7.php'
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
        raise Stop('GO_ERP_DESKTOP_SYNC_' + reason)


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


def safe_directory(root, name, missing_ok=False):
    """Every folder on the way must be a real folder, never a link."""
    current = root
    for part in name.split('/'):
        current = current / part
        try:
            info = current.lstat()
        except FileNotFoundError:
            need(missing_ok, 'LIVE_FOLDER_MISSING')
            return None
        need(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode), 'LIVE_ANCESTRY')
    return info


def live_read(root, name, web_uid):
    """Return (bytes, stat) of one live file, or (None, None) when it is absent."""
    parent = name.rsplit('/', 1)[0]
    if safe_directory(root, parent, missing_ok=True) is None:
        return None, None
    path = root / name
    try:
        before = path.lstat()
    except FileNotFoundError:
        return None, None
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


def sync_directory(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def live_write(root, name, data, uid, gid, mode):
    path = root / name
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-desktop-sync-', dir=str(path.parent))
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


def add_route_line(text):
    """Insert the desktop sync routes right after the GO services routes, with the same indentation."""
    lines = text.split(b'\n')
    anchors = [index for index, line in enumerate(lines) if line.strip() == ANCHOR.encode()]
    if len(anchors) != 1 or b'routes/desktop_sync.php' in text:
        return None
    line = lines[anchors[0]]
    indent = line[:len(line) - len(line.lstrip())]
    return b'\n'.join(lines[:anchors[0] + 1] + [indent + LINE.encode()] + lines[anchors[0] + 1:])


def release_files(package, release):
    """The running package must be the clean reviewed commit that adds exactly these files."""
    need(git(package, 'rev-parse', 'HEAD').decode().strip() == release, 'PACKAGE_REVISION')
    need(not git(package, 'status', '--porcelain', '--untracked-files=all'), 'PACKAGE_DIRTY')
    git(package, 'merge-base', '--is-ancestor', BASE, release)
    changes = {}
    for line in git(package, 'diff', '--name-status', '--no-renames', BASE, release, '--').decode().splitlines():
        status, name = line.split('\t', 1)
        if in_scope(name):
            changes[name] = status
    expected = dict((name, 'A') for name in NEW_FILES)
    expected[PROVIDER] = 'M'
    need(changes == expected, 'UNREVIEWED_SOURCE_DELTA')
    files = {}
    for name in NEW_FILES:
        wanted = git(package, 'show', release + ':' + name)
        need(0 < len(wanted) <= MAX_SOURCE and (package / name).read_bytes() == wanted, 'RELEASE_FILE')
        files[name] = wanted
    base_provider = git(package, 'show', BASE + ':' + PROVIDER)
    wanted_provider = git(package, 'show', release + ':' + PROVIDER)
    need((package / PROVIDER).read_bytes() == wanted_provider and add_route_line(base_provider) == wanted_provider,
         'RELEASE_PROVIDER_CHANGE')
    files[PROVIDER] = wanted_provider
    return files, base_provider


def earlier_versions(package, release):
    """Every version of the new files that earlier commits of this branch shipped, by checksum."""
    versions = {}
    for name in NEW_FILES:
        found = set()
        for commit in git(package, 'log', '--format=%H', '--diff-filter=AM', BASE + '..' + release, '--', name).decode().split():
            found.add(sha256(git(package, 'show', commit + ':' + name)))
        versions[name] = found
    return versions


def lock_watcher(path):
    """Hold the old update worker's lock (if it still exists) so nothing reads the tree while it changes."""
    try:
        fd = os.open(str(path), os.O_RDWR | os.O_NOFOLLOW)
    except FileNotFoundError:
        return None
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(fd)
        raise Stop('GO_ERP_DESKTOP_SYNC_UPDATE_WORKER_BUSY_RETRY_IN_A_MINUTE') from None
    return fd


def php_lint(php, package):
    for name in TARGETS:
        result = subprocess.run([php, '-n', '-l', str(package / name)], stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=60, check=False)
        need(result.returncode == 0, 'PHP_SYNTAX')


def artisan(root, php, web_user, *arguments):
    """Runs artisan as the site user; its output is never printed (it can name the database)."""
    command = [php, 'artisan'] + list(arguments)
    info = pwd.getpwnam(web_user)
    if info.pw_uid != os.geteuid():
        command = ['runuser', '-u', web_user, '--', 'env', 'HOME=' + info.pw_dir] + command
    result = subprocess.run(command, cwd=str(root), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=600, check=False)
    return result.returncode


def probe(url=PROBE_URL, attempts=10, delay=2.0):
    """401 means the new address is live and its tables exist (the probe sends no device)."""
    status = None
    for attempt in range(attempts):
        request = urllib.request.Request(url, data=b'{}', method='POST',
                                         headers={'Content-Type': 'application/json', 'Accept': 'application/json',
                                                  'User-Agent': 'go-erp-desktop-sync-installer'})
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                status = response.status
        except urllib.error.HTTPError as error:
            status = error.code
        except (urllib.error.URLError, OSError):
            status = 0
        if status == 401:
            return status
        if attempt + 1 < attempts:
            time.sleep(delay)
    return status


def inspect(root, package, release, web_uid, php):
    files, base_provider = release_files(package, release)
    live = dict((name, live_read(root, name, web_uid)) for name in TARGETS)
    present = [name for name in NEW_FILES if live[name][0] is not None]
    same = [name for name in NEW_FILES if live[name][0] == files[name]]
    provider = live[PROVIDER][0]
    need(provider is not None, 'ROUTE_PROVIDER_MISSING')
    if len(same) == len(NEW_FILES) and provider.count(LINE.encode()) == 1:
        return {'result': 'GO_ERP_DESKTOP_SYNC_FILES_ALREADY_INSTALLED', 'release': release}, {'installed': True}
    upgrade = bool(present)
    if upgrade:
        # Only files this branch shipped before may be replaced; anything else needs a person to look.
        earlier = earlier_versions(package, release)
        need(all(sha256(live[name][0]) in earlier[name] for name in present), 'NEW_FILES_DIFFER_FROM_EVERY_RELEASE_REVIEW_REQUIRED')
        need(provider.count(LINE.encode()) == 1, 'ROUTE_PROVIDER_DIFFERS_REVIEW_REQUIRED')
        provider_mode, new_provider = 'kept', provider
    elif provider == base_provider:
        provider_mode, new_provider = 'replaced', files[PROVIDER]
    else:
        new_provider = add_route_line(provider)
        need(new_provider is not None, 'ROUTE_PROVIDER_DIFFERS_REVIEW_REQUIRED')
        provider_mode = 'line-added'
    php_lint(php, package)
    plan = {'result': 'GO_ERP_DESKTOP_SYNC_PLAN', 'release': release, 'mode': 'upgrade' if upgrade else 'install',
            'new_files': len(NEW_FILES),
            'route_provider': provider_mode, 'new_folder': safe_directory(root, NEW_DIRECTORY, missing_ok=True) is None,
            'route_cache': os.path.lexists(str(root / ROUTE_CACHE)), 'new_tables': 3,
            'existing_tables_changed': False, 'environment_changed': False}
    wanted = dict(files)
    wanted[PROVIDER] = new_provider
    return plan, {'installed': False, 'wanted': wanted, 'live': live}


def apply(root, backups, release, web_uid, plan, context):
    wanted, live = context['wanted'], context['live']
    services = safe_directory(root, 'app/Services')
    owners = {}
    for name in TARGETS:
        info = live[name][1]
        if info is not None:
            owners[name] = (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
        else:
            parent = safe_directory(root, name.rsplit('/', 1)[0], missing_ok=True) or services
            owners[name] = (parent.st_uid, parent.st_gid, 0o644)
    if not backups.exists():
        backups.mkdir(mode=0o700)
    backups_info = backups.lstat()
    need(stat.S_ISDIR(backups_info.st_mode) and backups_info.st_uid == os.geteuid() and not backups_info.st_mode & 0o077,
         'BACKUP_DIRECTORY_UNSAFE')
    backup = backups / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-' + uuid.uuid4().hex[:8])
    backup.mkdir(mode=0o700)
    created_folder = plan['new_folder']
    manifest = {'format': 2, 'release': release, 'base': BASE, 'created_folder': created_folder, 'files': {}}
    for index, name in enumerate(TARGETS):
        before = live[name][0]
        if before is not None:
            private_write(backup / ('file-%d.before' % index), before)
        manifest['files'][name] = {'index': index, 'before_sha256': None if before is None else sha256(before),
                                   'after_sha256': sha256(wanted[name]), 'uid': owners[name][0],
                                   'gid': owners[name][1], 'mode': owners[name][2]}
    private_write(backup / 'manifest.json', json.dumps(manifest, sort_keys=True).encode())
    written = []
    folder_made = False
    try:
        for name in TARGETS:
            need(live_read(root, name, web_uid)[0] == live[name][0], 'LIVE_FILE_CHANGED')
        if created_folder:
            folder = root / NEW_DIRECTORY
            os.mkdir(str(folder), 0o755)
            folder_made = True
            os.chown(str(folder), services.st_uid, services.st_gid)
            os.chmod(str(folder), 0o755)
            sync_directory(folder.parent)
            for name in NEW_FILES:
                if name.startswith(NEW_DIRECTORY + '/'):
                    owners[name] = (services.st_uid, services.st_gid, 0o644)
        for name in TARGETS:
            written.append(name)
            live_write(root, name, wanted[name], *owners[name])
        for name in TARGETS:
            data, info = live_read(root, name, web_uid)
            need(data == wanted[name] and (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) == owners[name], 'LIVE_VERIFY')
    except BaseException as error:
        restored = restore(root, web_uid, live, owners, written, folder_made)
        private_write(backup / 'failed.json', json.dumps({'restored': restored}).encode())
        code = str(error) if isinstance(error, Stop) else 'GO_ERP_DESKTOP_SYNC_APPLY_FAILED'
        raise Stop(code + (' RESTORED' if restored else ' REVIEW_REQUIRED ' + str(backup))) from None
    private_write(backup / 'installed.json', json.dumps({'release': release}).encode())
    return backup, owners, folder_made


def restore(root, web_uid, live, owners, written, folder_made):
    try:
        for name in written:
            if live[name][0] is None:
                if live_read(root, name, web_uid)[0] is not None:
                    live_remove(root, name)
            else:
                live_write(root, name, live[name][0], *owners[name])
        if folder_made and not os.listdir(str(root / NEW_DIRECTORY)):
            os.rmdir(str(root / NEW_DIRECTORY))
        return all(live_read(root, name, web_uid)[0] == live[name][0] for name in TARGETS)
    except BaseException:
        return False


def rollback(root, backup, web_uid):
    """Puts back exactly what the backup holds: earlier files are restored, files the install added are removed."""
    info = backup.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid() and not info.st_mode & 0o077, 'BACKUP_UNSAFE')
    manifest = read_json(backup / 'manifest.json')
    need(isinstance(manifest, dict) and manifest.get('format') in (1, 2) and manifest.get('base') == BASE
         and sorted(manifest.get('files', {})) == sorted(TARGETS), 'BACKUP_MANIFEST')
    plan = {}
    for name in TARGETS:
        row = manifest['files'][name]
        before = None
        if row['before_sha256'] is not None:
            # The first installer version saved only the route provider; it never replaced other files.
            saved = backup / ('provider.before' if manifest['format'] == 1 else 'file-%d.before' % row['index'])
            need(manifest['format'] == 2 or name == PROVIDER, 'BACKUP_MANIFEST')
            before = saved.read_bytes()
            need(sha256(before) == row['before_sha256'], 'BACKUP_FILE_CHANGED')
        current = live_read(root, name, web_uid)[0]
        need((None if current is None else sha256(current)) in (row['after_sha256'], row['before_sha256']),
             'LIVE_FILE_CHANGED_SINCE_INSTALL')
        plan[name] = (before, row)
    for name in TARGETS:
        before, row = plan[name]
        if before is None:
            if live_read(root, name, web_uid)[0] is not None:
                live_remove(root, name)
        elif live_read(root, name, web_uid)[0] != before:
            live_write(root, name, before, row['uid'], row['gid'], row['mode'])
    folder = root / NEW_DIRECTORY
    if manifest.get('created_folder') and folder.is_dir() and not folder.is_symlink() and not os.listdir(str(folder)):
        os.rmdir(str(folder))
    need(all(live_read(root, name, web_uid)[0] == plan[name][0] for name in TARGETS), 'ROLLBACK_VERIFY')
    return {'result': 'GO_ERP_DESKTOP_SYNC_ROLLED_BACK', 'backup': str(backup), 'new_tables_kept': True}


def finish(root, php, web_user, result, check, migrate=True):
    """Creates the three tables, makes the new routes visible, then checks the live address."""
    if migrate:
        need(artisan(root, php, web_user, 'migrate', '--path=' + MIGRATION, '--force') == 0, 'MIGRATION_FAILED')
    if os.path.lexists(str(root / ROUTE_CACHE)):
        need(artisan(root, php, web_user, 'route:clear') == 0, 'ROUTE_CACHE_CLEAR_FAILED')
        result['route_cache_cleared'] = True
    status = check()
    result['live_check_status'] = status
    if status == 401:
        result['live_check'] = 'READY'
    elif status == 503:
        result['live_check'] = 'TABLES_MISSING'
    elif status == 404:
        result['live_check'] = 'ROUTE_NOT_VISIBLE_YET_RELOAD_PHP'
    else:
        result['live_check'] = 'UNEXPECTED_STATUS'
    return result


def run(root, package, release, web_uid, php, mode, backup=None, backups=BACKUPS, watcher_lock=WATCHER_LOCK,
        web_user=WEB_USER, check=probe):
    lock = lock_watcher(watcher_lock)
    try:
        if mode == 'rollback':
            return rollback(root, backup, web_uid)
        plan, context = inspect(root, package, release, web_uid, php)
        if mode != 'apply':
            return plan
        if context['installed']:
            # Files are in place: only make sure the tables exist and the address answers.
            return finish(root, php, web_user, dict(plan, result='GO_ERP_DESKTOP_SYNC_ALREADY_INSTALLED'), check)
        saved, owners, folder_made = apply(root, backups, release, web_uid, plan, context)
        result = dict(plan, result='GO_ERP_DESKTOP_SYNC_INSTALLED', backup=str(saved))
        try:
            return finish(root, php, web_user, result, check)
        except Stop as error:
            restored = restore(root, web_uid, context['live'], owners, list(TARGETS), folder_made)
            private_write(saved / 'finish-failed.json', json.dumps({'error': str(error), 'restored': restored}).encode())
            raise Stop(str(error) + (' FILES_RESTORED' if restored else ' REVIEW_REQUIRED ' + str(saved))) from None
    finally:
        if lock is not None:
            os.close(lock)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
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
              else 'GO_ERP_DESKTOP_SYNC_STOPPED_WITHOUT_PRIVATE_OUTPUT', flush=True)
        raise SystemExit(1)
