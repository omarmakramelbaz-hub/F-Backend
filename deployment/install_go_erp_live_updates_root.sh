#!/usr/bin/env bash
# One-time setup from the authorized server console. Never uses the deploy-only key.
set -euo pipefail
exec /usr/bin/python3 -I -B - "$0" "$@" <<'PY'
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys
import tempfile
import termios
import urllib.error
import urllib.request

ROOT = Path('/home/fasakha/public_html')
STATE = Path('/var/lib/go-erp-live-updates')
CONFIG_DIR = Path('/etc/go-erp')
CONFIG = CONFIG_DIR / 'live-updates.json'
INSTALL = Path('/opt/go-erp-live-updates/releases')
SYSTEMD_DIR = Path('/etc/systemd/system')
BRANCH = 'codex/go-erp-live-source-20261021'
SETUP_BRANCH = 'refs/heads/codex/go-erp-activation-view-fix-20261021-r3'
REPOSITORY = 'omarmakramelbaz-hub/F-Backend'
REPOSITORY_ID = 1311412084
SELF = 'deployment/install_go_erp_live_updates_root.sh'
FILES = (SELF, 'deployment/go_erp_live_source.py', 'deployment/go_erp_live_updates.py',
         'deployment/publish_go_erp_update.py', 'deployment/desktop_dashboard_server_preflight.py',
         'deployment/diagnose_desktop_dashboard_current_source.py')
REVISION = re.compile(r'[a-f0-9]{40}\Z')
TOKEN = re.compile(r'[A-Za-z0-9_-]{20,512}\Z')
FEED_PINS = {
    'app/Services/Dashboard/DesktopDashboardUpdates.php': '58e9a9db31d5ffa59bf2793578bde70088b6b798',
    'app/Http/Controllers/Dashboard/DesktopDashboardUpdateController.php': 'f5d3c88d7ae34121164d23f21ffdda7a4cf8f5fc',
    'routes/api.php': '9ada39b13b25e68034b09f8bf9e759e5543d8498',
}
GIT_ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha',
           'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_NO_LAZY_FETCH': '1', 'GIT_OPTIONAL_LOCKS': '0'}

class SetupError(Exception):
    pass

def need(value, reason):
    if not value:
        raise SetupError('GO_ERP_LIVE_SETUP_' + reason)

def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()

def directory(path, mode=0o700, create=False):
    need(path.is_absolute() and '..' not in path.parts, 'PATH_INVALID')
    for item in reversed((path, *path.parents)):
        try:
            info = item.lstat()
        except FileNotFoundError:
            need(create, 'DIRECTORY_MISSING')
            requested = mode if item == path else 0o755
            item.mkdir(mode=requested)
            created = os.open(item, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                need(os.fstat(created).st_uid == 0, 'DIRECTORY_UNSAFE')
                os.fchmod(created, requested)
            finally:
                os.close(created)
            info = item.lstat()
        sticky = item in (Path('/tmp'), Path('/var/tmp')) and info.st_mode & stat.S_ISVTX
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
             and (not info.st_mode & 0o022 or sticky),
             'DIRECTORY_UNSAFE')
    need(stat.S_IMODE(path.stat().st_mode) == mode, 'DIRECTORY_MODE_INVALID')

def private_bytes(path, limit):
    directory(path.parent, stat.S_IMODE(path.parent.lstat().st_mode))
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
         and stat.S_IMODE(before.st_mode) == 0o600 and 0 < before.st_size <= limit,
         'PRIVATE_INPUT_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        identity = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink,
                              s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        need(identity(os.fstat(fd)) == identity(before), 'PRIVATE_INPUT_CHANGED')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            data = stream.read(limit + 1)
        need(len(data) == before.st_size and identity(os.fstat(fd)) == identity(path.lstat())
             == identity(before), 'PRIVATE_INPUT_CHANGED')
        return data
    finally:
        os.close(fd)

def child(argv, timeout=120):
    try:
        result = subprocess.run(argv, env=GIT_ENV, stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired):
        raise SetupError('GO_ERP_LIVE_SETUP_CHILD_UNAVAILABLE') from None
    need(result.returncode == 0 and len(result.stdout) <= 4 * 1024 ** 2
         and len(result.stderr) <= 128 * 1024, 'CHILD_FAILED')
    return result.stdout

def git(*args):
    return child(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i',
                  *[key + '=' + value for key, value in GIT_ENV.items()], '/usr/bin/git',
                  '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', str(ROOT), *args])

def open_directory(path):
    need(path.is_absolute() and '..' not in path.parts, 'PATH_INVALID')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for name in path.parts[1:]:
            following = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = following
        return fd
    except BaseException:
        os.close(fd)
        raise

def require_installed_feed():
    uid = pwd.getpwnam('fasakha').pw_uid
    for name, pin in FEED_PINS.items():
        path = ROOT / name
        parent = open_directory(path.parent)
        try:
            fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            try:
                before = os.fstat(fd)
                need(stat.S_ISREG(before.st_mode) and before.st_uid == uid and before.st_nlink == 1
                     and not before.st_mode & 0o022 and 0 < before.st_size <= 8 * 1024 ** 2,
                     'SERVER_ACTIVATION_REQUIRED')
                with os.fdopen(fd, 'rb', closefd=False) as stream:
                    data = stream.read(8 * 1024 ** 2 + 1)
                after = os.fstat(fd)
                named = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
                identify = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
                                      s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
                need(len(data) == before.st_size and blob(data) == pin
                     and identify(before) == identify(after) == identify(named),
                     'SERVER_ACTIVATION_REQUIRED')
            finally:
                os.close(fd)
        finally:
            os.close(parent)

def payload(revision, entry):
    git('fetch', '--quiet', '--no-tags', '--no-prune', '--no-recurse-submodules', '--refmap=',
        'origin', SETUP_BRANCH)
    need(git('rev-parse', 'FETCH_HEAD').decode().strip() == revision, 'RELEASE_SUPERSEDED')
    result = {}
    for name in FILES:
        record = git('ls-tree', '-z', revision, name)
        matched = re.fullmatch(rb'100(?:644|755) blob ([a-f0-9]{40})\t'
                               + re.escape(name.encode()) + rb'\0', record)
        need(matched is not None, 'REVIEWED_FILE_INVALID')
        data = git('show', revision + ':' + name)
        need(0 < len(data) <= 2 * 1024 ** 2 and blob(data) == matched[1].decode(),
             'REVIEWED_BLOB_INVALID')
        result[Path(name).name] = data
    need(private_bytes(entry, 2 * 1024 ** 2) == result[Path(SELF).name], 'ENTRYPOINT_NOT_PINNED')
    return result

def configured_token(token_file):
    if token_file is not None:
        value = private_bytes(token_file, 1024).decode('ascii').strip()
    elif CONFIG.exists():
        old = json.loads(private_bytes(CONFIG, 4096))
        need(isinstance(old, dict) and old.get('repository') == REPOSITORY,
             'EXISTING_CONFIGURATION_INVALID')
        value = old.get('token')
    else:
        fd = None
        try:
            fd = os.open('/dev/tty', os.O_RDWR | os.O_NOCTTY)
            need(os.isatty(fd), 'PRIVATE_GITHUB_TOKEN_REQUIRED')
            settings = termios.tcgetattr(fd)
            hidden = settings.copy()
            hidden[3] &= ~termios.ECHO
            try:
                termios.tcsetattr(fd, termios.TCSAFLUSH, hidden)
                os.write(fd, b'GitHub token (hidden; never send it in chat): ')
                value = os.read(fd, 1024).decode('ascii').strip()
            finally:
                termios.tcsetattr(fd, termios.TCSAFLUSH, settings)
                os.write(fd, b'\n')
        except (EOFError, OSError):
            raise SetupError('GO_ERP_LIVE_SETUP_PRIVATE_GITHUB_TOKEN_REQUIRED') from None
        finally:
            if fd is not None:
                os.close(fd)
    need(isinstance(value, str) and TOKEN.fullmatch(value), 'TOKEN_INVALID')
    return value

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def verify_repository(token):
    opener = urllib.request.build_opener(NoRedirect())
    for suffix in ('', '/actions/runs?per_page=1'):
        request = urllib.request.Request('https://api.github.com/repos/' + REPOSITORY + suffix,
            headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'GO-ERP-live-updates'})
        try:
            with opener.open(request, timeout=20) as response:
                need(response.status == 200, 'GITHUB_AUTHORIZATION_FAILED')
                data = response.read(2 * 1024 ** 2 + 1)
                need(len(data) <= 2 * 1024 ** 2, 'GITHUB_RESPONSE_TOO_LARGE')
                result = json.loads(data)
        except (urllib.error.URLError, ValueError, OSError):
            raise SetupError('GO_ERP_LIVE_SETUP_GITHUB_AUTHORIZATION_FAILED') from None
        if not suffix:
            need(isinstance(result, dict) and result.get('id') == REPOSITORY_ID
                 and result.get('full_name') == REPOSITORY, 'REPOSITORY_IDENTITY_MISMATCH')
        else:
            need(isinstance(result, dict) and isinstance(result.get('workflow_runs'), list),
                 'ACTIONS_READ_UNAVAILABLE')

def atomic_file(path, data, mode):
    directory(path.parent, stat.S_IMODE(path.parent.stat().st_mode))
    if path.exists() or path.is_symlink():
        old = path.lstat()
        need(stat.S_ISREG(old.st_mode) and old.st_uid == 0 and old.st_nlink == 1
             and not old.st_mode & 0o022, 'EXISTING_FILE_UNSAFE')
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-setup-', dir=path.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        parent = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(parent)
        finally:
            os.close(parent)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def unit_files(revision):
    need(REVISION.fullmatch(revision), 'REVISION_INVALID')
    entry = INSTALL / revision / 'go_erp_live_updates.py'
    service = ('[Unit]\nDescription=GO ERP accepted live source releases\n'
        'Wants=network-online.target\nAfter=network-online.target\n\n[Service]\n'
        'Type=oneshot\nUser=root\nGroup=root\nUMask=0077\n'
        f'ExecStart=/usr/bin/python3 -I -B {entry} --config {CONFIG} --once\n'
        'TimeoutStartSec=45min\nNoNewPrivileges=yes\nPrivateTmp=yes\nPrivateDevices=yes\n'
        'ProtectSystem=strict\nProtectHome=read-only\nProtectKernelTunables=yes\n'
        'ProtectKernelModules=yes\nProtectControlGroups=yes\nRestrictSUIDSGID=yes\n'
        f'ReadWritePaths={STATE} {ROOT}/storage/app/desktop-updates\n'
        'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6\n')
    timer = ('[Unit]\nDescription=Check GO ERP live source every minute\n\n[Timer]\n'
        'OnBootSec=30s\nOnUnitInactiveSec=60s\nAccuracySec=1s\nRandomizedDelaySec=0\n'
        'Unit=go-erp-live-updates.service\n\n[Install]\nWantedBy=timers.target\n')
    return {'go-erp-live-updates.service': service.encode(),
            'go-erp-live-updates.timer': timer.encode()}

def setup(revision, files, token):
    config = {'format': 1, 'repository': REPOSITORY, 'repository_id': REPOSITORY_ID,
              'branch': BRANCH, 'token': token, 'base_revision': revision}
    if CONFIG.exists() or CONFIG.is_symlink():
        old = json.loads(private_bytes(CONFIG, 4096))
        need(isinstance(old, dict) and {key: value for key, value in old.items() if key != 'token'}
             == {key: value for key, value in config.items() if key != 'token'},
             'CONFIGURATION_REQUIRES_REVIEWED_UPGRADE')
    units = unit_files(revision)
    directory(SYSTEMD_DIR, 0o755)
    for name, data in units.items():
        file = SYSTEMD_DIR / name
        if file.exists() or file.is_symlink():
            info = file.lstat()
            need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                 and stat.S_IMODE(info.st_mode) == 0o644 and file.read_bytes() == data,
                 'UNIT_REQUIRES_REVIEWED_UPGRADE')
    directory(STATE, create=True)
    directory(CONFIG_DIR, create=True)
    directory(INSTALL, 0o755, create=True)
    target = INSTALL / revision
    if target.exists() or target.is_symlink():
        directory(target, 0o755)
        need(set(os.listdir(target)) == set(files), 'INSTALLED_RELEASE_DIFFERS')
        for name, data in files.items():
            item = target / name
            info = item.lstat()
            need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                 and stat.S_IMODE(info.st_mode) == 0o644 and item.read_bytes() == data,
                 'INSTALLED_RELEASE_DIFFERS')
    else:
        temporary = Path(tempfile.mkdtemp(prefix='.install-', dir=INSTALL))
        try:
            for name, data in files.items():
                atomic_file(temporary / name, data, 0o644)
            temporary.chmod(0o755)
            os.rename(temporary, target)
        finally:
            if temporary.exists():
                import shutil
                shutil.rmtree(temporary)
    # The service has one narrowly writable subtree inside the live application.
    updates = ROOT / 'storage/app/desktop-updates'
    parent = open_directory(updates.parent)
    try:
        created = False
        try:
            os.mkdir(updates.name, mode=0o755, dir_fd=parent)
            created = True
        except FileExistsError:
            pass
        if created:
            folder = os.open(updates.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            try:
                need(os.fstat(folder).st_uid == 0, 'PUBLICATION_DIRECTORY_UNSAFE')
                os.fchmod(folder, 0o755)
            finally:
                os.close(folder)
        info = os.stat(updates.name, dir_fd=parent, follow_symlinks=False)
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
             and stat.S_IMODE(info.st_mode) == 0o755, 'PUBLICATION_DIRECTORY_UNSAFE')
    finally:
        os.close(parent)
    atomic_file(CONFIG, (json.dumps(config, sort_keys=True) + '\n').encode(), 0o600)
    for name, data in units.items():
        atomic_file(SYSTEMD_DIR / name, data, 0o644)
    child(['/usr/bin/systemd-analyze', 'verify',
           *[str(SYSTEMD_DIR / name) for name in units]], timeout=30)
    child(['/usr/bin/systemctl', 'daemon-reload'], timeout=30)
    child(['/usr/bin/systemctl', 'enable', '--now', 'go-erp-live-updates.timer'], timeout=30)
    child(['/usr/bin/systemctl', 'is-active', '--quiet', 'go-erp-live-updates.timer'], timeout=30)
    print('GO_ERP_LIVE_UPDATES_TIMER_INSTALLED')
    print('INTERVAL_SECONDS=60')
    print('STATE=AWAITING_FIRST_ACCEPTED_BUILD')

def main():
    parser = argparse.ArgumentParser(description='Install the minute live-source watcher from the root console.')
    parser.add_argument('--release', required=True)
    parser.add_argument('--github-token-file', type=Path)
    options = parser.parse_args(sys.argv[2:])
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'ROOT_AND_HOST_REQUIRED')
    need(REVISION.fullmatch(options.release), 'REVISION_INVALID')
    files = payload(options.release, Path(sys.argv[1]).absolute())
    require_installed_feed()
    token = configured_token(options.github_token_file)
    verify_repository(token)
    setup(options.release, files, token)
    return 0

if __name__ == '__main__':
    try:
        result = main()
    except SetupError as error:
        print(str(error), file=sys.stderr)
        result = 1
    except BaseException:
        print('GO_ERP_LIVE_SETUP_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        result = 1
    sys.exit(result)
PY
