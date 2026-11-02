#!/usr/bin/env python3
"""Give the dashboard's sign-in page on srv1836524 the GO opening look, as in the GO ERP app.

The page keeps its form exactly (the app fills it in to sign the dashboard in); only its look changes: the opening's
moving background, the live hologram (the dashboard's own /dashboard/js/go-hologram.js, already on the server with the
opening) and the GO mark. Without them on the server the page falls back to its GO logo.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/install_login_hud_root.py              checks only, changes nothing
  python3 -I -B deployment/install_login_hud_root.py --apply      installs it
  python3 -I -B deployment/install_login_hud_root.py --rollback   puts the previous page back

--apply refuses a live page it does not know (one changed on the server since), keeps the live page in
/var/lib/go-erp-login-hud (root only), replaces it keeping its owner and mode, clears the compiled views, and checks
https://fasakhaninja.com/admin/login answers with the new page; otherwise it puts the previous page back by itself.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import pwd
import socket
import stat
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

HOST = 'srv1836524'
WEB_USER = 'fasakha'
SITE = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BACKUPS = Path('/var/lib/go-erp-login-hud')
PAGE = 'resources/views/admin/login.blade.php'
# The new page waits here until installed: the repository's PAGE stays the one the live server runs.
NEW_PAGE = 'deployment/login_hud/login.blade.php'
NEEDS = ('public/dashboard/js/go-hologram.js', 'resources/views/admin/partials/go_hud_mark.blade.php')
PROBE_URL = 'https://fasakhaninja.com/admin/login'
MARKER = b'data-login-hologram'
# The sign-in page this replaces (the repository's page before the GO opening look).
BASE_SHA256 = '31c8004daa5a1ec67835913efad56fc525e8b9c2c062de805be46efd42d51808'
PACKAGE = Path(__file__).resolve().parent.parent


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_LOGIN_HUD_' + reason)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def live_page(site):
    path = site / PAGE
    info = path.lstat()
    need(stat.S_ISREG(info.st_mode), 'LIVE_PAGE_NOT_A_FILE')
    return path, path.read_bytes(), info


def new_page(package):
    data = (package / NEW_PAGE).read_bytes()
    need(MARKER in data and b'data-dashboard-login' in data and b'name="email"' in data and b'name="password"' in data, 'NEW_PAGE_INCOMPLETE')
    return data


def write_atomic(path, data, info):
    fd, temporary = tempfile.mkstemp(prefix='.login-hud-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fchown(output.fileno(), info.st_uid, info.st_gid)
            os.fchmod(output.fileno(), stat.S_IMODE(info.st_mode))
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def artisan(site, php, user, *arguments):
    """Runs artisan as the site user; its output is never printed (it can name the database)."""
    info = pwd.getpwnam(user)
    command = [php, 'artisan'] + list(arguments)
    if info.pw_uid != os.geteuid():
        command = ['runuser', '-u', user, '--', 'env', 'HOME=' + info.pw_dir] + command
    result = subprocess.run(command, cwd=str(site), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=300, check=False)
    return result.returncode


def probe(url=PROBE_URL, attempts=6, delay=2.0):
    """The sign-in page as a browser gets it: 200 and the new page."""
    for attempt in range(attempts):
        request = urllib.request.Request(url, headers={'Accept': 'text/html', 'User-Agent': 'go-erp-login-hud-installer'})
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                if response.status == 200 and MARKER in response.read(2 * 1024 * 1024):
                    return True
        except (urllib.error.URLError, OSError):
            pass
        if attempt + 1 < attempts:
            time.sleep(delay)
    return False


def plan(site, package):
    path, data, info = live_page(site)
    wanted = new_page(package)
    state = 'installed' if data == wanted else 'base' if sha256(data) == BASE_SHA256 else 'changed-on-server'
    return {'result': 'GO_ERP_LOGIN_HUD_PLAN', 'live_page': state, 'live_sha256': sha256(data), 'new_sha256': sha256(wanted),
            'hologram_on_server': all((site / name).is_file() for name in NEEDS)}


def apply(site, package, backups, php, user, check=probe):
    path, data, info = live_page(site)
    wanted = new_page(package)
    if data == wanted:
        return {'result': 'GO_ERP_LOGIN_HUD_ALREADY_INSTALLED'}
    need(sha256(data) == BASE_SHA256, 'LIVE_PAGE_CHANGED_ON_SERVER')
    if not backups.exists():
        backups.mkdir(mode=0o700, parents=True)
    folder = backups.lstat()
    need(stat.S_ISDIR(folder.st_mode) and folder.st_uid == os.geteuid() and stat.S_IMODE(folder.st_mode) == 0o700, 'BACKUP_FOLDER')
    backup = backups / ('login.blade.php.' + datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S'))
    fd = os.open(str(backup), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as output:
        output.write(data)
        output.flush()
        os.fsync(output.fileno())
    (backups / (backup.name + '.json')).write_text(json.dumps({'mode': stat.S_IMODE(info.st_mode), 'uid': info.st_uid, 'gid': info.st_gid}))
    write_atomic(path, wanted, info)
    artisan(site, php, user, 'view:clear')
    if not check():
        write_atomic(path, data, info)
        artisan(site, php, user, 'view:clear')
        need(False, 'NEW_PAGE_NOT_SERVED_PREVIOUS_PAGE_RESTORED')
    return {'result': 'GO_ERP_LOGIN_HUD_INSTALLED', 'backup': str(backup), 'hologram_on_server': all((site / name).is_file() for name in NEEDS)}


def rollback(site, package, backups, php, user):
    path, data, info = live_page(site)
    need(data == new_page(package), 'LIVE_PAGE_IS_NOT_THIS_RELEASE')
    saved = sorted(item for item in backups.glob('login.blade.php.*') if not item.name.endswith('.json')) if backups.exists() else []
    need(saved, 'NO_BACKUP')
    previous = saved[-1].read_bytes()
    write_atomic(path, previous, info)
    artisan(site, php, user, 'view:clear')
    return {'result': 'GO_ERP_LOGIN_HUD_ROLLED_BACK', 'restored_from': str(saved[-1]), 'live_sha256': sha256(previous)}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need(os.access(PHP, os.X_OK) and (SITE / 'artisan').is_file(), 'SITE_NOT_FOUND')
    os.umask(0o077)
    if options.rollback:
        result = rollback(SITE, PACKAGE, BACKUPS, PHP, WEB_USER)
    elif options.apply:
        result = apply(SITE, PACKAGE, BACKUPS, PHP, WEB_USER)
    else:
        result = plan(SITE, PACKAGE)
    print(json.dumps(result, sort_keys=True), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_LOGIN_HUD_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
