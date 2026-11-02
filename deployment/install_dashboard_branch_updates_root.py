#!/usr/bin/env python3
"""Install on srv1836524 the dashboard changes the owner asked for on 2026-11-02:
  - «مندوب الفرع» prints the app order as it sends it out (the order card has no print button any more; the order's
    details page still prints it again);
  - the GO opening's «تقفيل الوردية» card shows the branches' drawer balance to the owner only;
  - a delivery order sent to a branch by the owner, an admin or the call center (it prints on the branch's printer by
    itself) chimes once on the branch's device with its own tone, never repeated (any other order keeps ringing with
    the usual sound until handled).

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/install_dashboard_branch_updates_root.py              checks only, changes nothing
  python3 -I -B deployment/install_dashboard_branch_updates_root.py --apply      installs them
  python3 -I -B deployment/install_dashboard_branch_updates_root.py --rollback   puts the previous files back

--apply refuses a live file it does not know (one changed on the server since), keeps every file it replaces in
/var/lib/go-erp-dashboard-updates (root only), writes the new ones keeping each file's owner and mode, clears the
compiled views, and checks https://fasakhaninja.com serves the new scripts and the tone; otherwise it puts every
previous file back by itself.
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
BACKUPS = Path('/var/lib/go-erp-dashboard-updates')
ORIGIN = 'https://fasakhaninja.com'
PACKAGE = Path(__file__).resolve().parent.parent
# Each file with the live version it replaces (sha256), or None for a new file; and what marks this release's version.
FILES = (
    ('public/dashboard/js/dashboard-order-alerts.js', 'd63f8ca8ecc53abeb5b5ccccf136d68306ed2ed4985ad62d0d369ba3b8e69c61', b'go-delivery-order.wav'),
    ('public/dashboard/js/order-board.js', '6330fb5f23ea363e2787b5d413744a9729d45dfe210e46b2528207e302ddac9e', b'printAfter'),
    ('resources/views/admin/orders/board_card.blade.php', '8b2bcf407f4520dffc460d245c0edd99961e231cffaaac902123d22606286a07',
     b'data-print-after="prepare"'),
    ('app/Services/Dashboard/HomeHologramMetrics.php', '834335243f4b385292e33efa71f56e08197732d6a23696ab6199830cd22ac5dd', b'ownerDrawerBalance'),
    ('public/sounds/go-delivery-order.wav', None, b'WAVE'),
)
# What a browser gets once installed: the scripts and the tone (views and PHP are checked through the site's own run).
SERVED = (('/dashboard/js/order-board.js', b'printAfter'), ('/dashboard/js/dashboard-order-alerts.js', b'go-delivery-order.wav'),
          ('/sounds/go-delivery-order.wav', b'WAVE'))


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_DASHBOARD_UPDATES_' + reason)


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def new_files(package):
    found = {}
    for name, base, marker in FILES:
        path = package / name
        need(path.is_file(), 'PACKAGE_INCOMPLETE: ' + name)
        data = path.read_bytes()
        need(marker in data, 'PACKAGE_NOT_THIS_RELEASE: ' + name)
        found[name] = data
    return found


def live(site, name):
    """The live file's bytes and information, or (None, None) when it does not exist."""
    path = site / name
    try:
        info = path.lstat()
    except FileNotFoundError:
        return None, None
    need(stat.S_ISREG(info.st_mode), 'LIVE_FILE_NOT_A_FILE: ' + name)
    return path.read_bytes(), info


def states(site, package):
    wanted = new_files(package)
    result = {}
    for name, base, marker in FILES:
        data, info = live(site, name)
        if data is not None and data == wanted[name]:
            result[name] = 'installed'
        elif data is None:
            result[name] = 'new' if base is None else 'missing-on-server'
        elif base is not None and sha256(data) == base:
            result[name] = 'base'
        else:
            result[name] = 'changed-on-server'
    return result, wanted


def write_atomic(path, data, uid, gid, mode):
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-update-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def owner_for_new(path):
    """A new file takes the owner and mode of its neighbours (else of its folder, readable by the site)."""
    folder = path.parent
    for sibling in sorted(folder.iterdir()):
        if sibling.is_file() and not sibling.is_symlink():
            info = sibling.lstat()
            return info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)
    info = folder.lstat()
    return info.st_uid, info.st_gid, 0o644


def artisan(site, php, user, *arguments):
    """Runs artisan as the site user; its output is never printed (it can name the database)."""
    info = pwd.getpwnam(user)
    command = [php, 'artisan'] + list(arguments)
    if info.pw_uid != os.geteuid():
        command = ['runuser', '-u', user, '--', 'env', 'HOME=' + info.pw_dir] + command
    result = subprocess.run(command, cwd=str(site), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=300, check=False)
    return result.returncode


def probe(origin=ORIGIN, attempts=6, delay=2.0):
    """The new scripts and the tone as a browser gets them."""
    stamp = str(int(time.time()))
    for address, marker in SERVED:
        ok = False
        for attempt in range(attempts):
            request = urllib.request.Request(origin + address + '?v=' + stamp, headers={'User-Agent': 'go-erp-dashboard-updates-installer'})
            try:
                with urllib.request.urlopen(request, timeout=15) as response:
                    ok = response.status == 200 and marker in response.read(4 * 1024 * 1024)
            except (urllib.error.URLError, OSError):
                ok = False
            if ok:
                break
            if attempt + 1 < attempts:
                time.sleep(delay)
        if not ok:
            return False
    return True


def plan(site, package):
    found, wanted = states(site, package)
    return {'result': 'GO_ERP_DASHBOARD_UPDATES_PLAN', 'files': found,
            'ready': all(state in ('base', 'new', 'installed') for state in found.values()),
            'already_installed': all(state == 'installed' for state in found.values())}


def backups_folder(folder):
    if not folder.exists():
        folder.mkdir(mode=0o700, parents=True)
    info = folder.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700, 'BACKUP_FOLDER')
    return folder


def restore(site, saved):
    """Puts back the files of one backup set (and removes the ones that did not exist before)."""
    for name, entry in saved['files'].items():
        path = site / name
        if entry['existed']:
            data = (Path(saved['folder']) / entry['copy']).read_bytes()
            write_atomic(path, data, entry['uid'], entry['gid'], entry['mode'])
        elif path.exists():
            path.unlink()


def apply(site, package, backups, php, user, check=probe):
    found, wanted = states(site, package)
    if all(state == 'installed' for state in found.values()):
        return {'result': 'GO_ERP_DASHBOARD_UPDATES_ALREADY_INSTALLED'}
    changed = sorted(name for name, state in found.items() if state not in ('base', 'new', 'installed'))
    need(not changed, 'LIVE_FILE_CHANGED_ON_SERVER: ' + ', '.join(changed))
    folder = backups_folder(backups) / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S-%f')
    folder.mkdir(mode=0o700)
    saved = {'folder': str(folder), 'files': {}}
    for index, (name, base, marker) in enumerate(FILES):
        data, info = live(site, name)
        if data is None:
            saved['files'][name] = {'existed': False}
            continue
        copy = 'file-%d' % index
        fd = os.open(str(folder / copy), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        saved['files'][name] = {'existed': True, 'copy': copy, 'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
                                'sha256': sha256(data)}
    (folder / 'manifest.json').write_text(json.dumps(saved, sort_keys=True))
    # Everything is kept before anything changes.
    try:
        for name, base, marker in FILES:
            path = site / name
            entry = saved['files'][name]
            uid, gid, mode = (entry['uid'], entry['gid'], entry['mode']) if entry['existed'] else owner_for_new(path)
            write_atomic(path, wanted[name], uid, gid, mode)
        artisan(site, php, user, 'view:clear')
        served = check()
    except Exception:
        restore(site, saved)
        artisan(site, php, user, 'view:clear')
        raise
    if not served:
        restore(site, saved)
        artisan(site, php, user, 'view:clear')
        need(False, 'NOT_SERVED_PREVIOUS_FILES_RESTORED')
    return {'result': 'GO_ERP_DASHBOARD_UPDATES_INSTALLED', 'backup': str(folder),
            'files': {name: found[name] for name, base, marker in FILES}}


def rollback(site, package, backups, php, user):
    found, wanted = states(site, package)
    need(all(state == 'installed' for state in found.values()), 'LIVE_FILES_ARE_NOT_THIS_RELEASE')
    sets = sorted(item for item in backups.iterdir() if (item / 'manifest.json').is_file()) if backups.exists() else []
    need(sets, 'NO_BACKUP')
    saved = json.loads((sets[-1] / 'manifest.json').read_text())
    need(Path(saved['folder']) == sets[-1], 'NO_BACKUP')
    restore(site, saved)
    artisan(site, php, user, 'view:clear')
    return {'result': 'GO_ERP_DASHBOARD_UPDATES_ROLLED_BACK', 'restored_from': str(sets[-1])}


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
              else 'GO_ERP_DASHBOARD_UPDATES_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
