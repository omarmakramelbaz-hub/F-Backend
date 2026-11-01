#!/usr/bin/env python3
"""Turn on the dashboard's automatic absence on srv1836524.

An employee with no attendance status recorded by the attendance deadline (22:00 Cairo time, or the end of a shift
that starts at 22:00 or later) is recorded «غياب بدون إذن» with the branch's absence deduction, by the server itself
(App\\Services\\Dashboard\\EmployeeAutomaticAbsence, the artisan command employees:auto-absence). That code is on the
server already; it only runs once it is turned on, which this script does.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/activate_employee_auto_absence_root.py                 checks only, changes nothing
  python3 -I -B deployment/activate_employee_auto_absence_root.py --apply         turns it on from tomorrow
  python3 -I -B deployment/activate_employee_auto_absence_root.py --apply --starts-on 2026-11-02
  python3 -I -B deployment/activate_employee_auto_absence_root.py --rollback      turns it off again

Turning it on sets up the folder the command reads (/home/fasakha/employee-auto-absence-backups): activation.json
with the first operating day it applies to (06:00 to 06:00 Cairo time; days before it are never recorded) and
install.lock (the lock the command takes while it runs). The command's own dry run must then report it ready with no
errors; only then a systemd timer starts running it as the site user every 5 minutes. Nothing else changes: no site
file, database table or setting. Turning it off stops the timer and removes activation.json; absences already
recorded stay, and the owner can cancel any of them on the employees page. Running it again changes nothing.
"""
import argparse
import datetime
import fcntl
import grp
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

HOST = 'srv1836524'
WEB_USER = 'fasakha'
SITE = Path('/home/fasakha/public_html')
FOLDER = Path('/home/fasakha/employee-auto-absence-backups')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
UNITS = Path('/etc/systemd/system')
NAME = 'go-erp-employee-auto-absence'
EVERY_MINUTES = 5
SERVICE_CODE = 'app/Services/Dashboard/EmployeeAutomaticAbsence.php'
COMMAND_CODE = 'app/Console/Commands/RecordEmployeeAutomaticAbsence.php'
DAY = re.compile(r'[0-9]{4}-[0-9]{2}-[0-9]{2}\Z')
COUNTS = re.compile(r'Employee automatic absence: (\{[^\n]*\})')
NOT_READY = 'not activated or its attendance schema is not ready'


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_AUTO_ABSENCE_' + reason)


def operating_day(now=None):
    """The operating day in Cairo (06:00 to 06:00), like App\\Services\\Dashboard\\OperatingDay."""
    previous = os.environ.get('TZ')
    os.environ['TZ'] = 'Africa/Cairo'
    time.tzset()
    try:
        local = time.localtime(time.time() if now is None else now)
    finally:
        if previous is None:
            del os.environ['TZ']
        else:
            os.environ['TZ'] = previous
        time.tzset()
    day = datetime.date(local.tm_year, local.tm_mon, local.tm_mday)
    return day - datetime.timedelta(days=1) if local.tm_hour < 6 else day


def valid_day(value):
    if not isinstance(value, str) or not DAY.match(value):
        return False
    try:
        return datetime.date(int(value[:4]), int(value[5:7]), int(value[8:])).isoformat() == value
    except ValueError:
        return False


def check_code(site):
    """The command and the service that reads this folder are on the site."""
    need((site / 'artisan').is_file(), 'SITE_NOT_FOUND')
    for name in (SERVICE_CODE, COMMAND_CODE):
        need((site / name).is_file(), 'CODE_NOT_ON_THE_SITE')
    service = (site / SERVICE_CODE).read_text(encoding='utf-8', errors='replace')
    command = (site / COMMAND_CODE).read_text(encoding='utf-8', errors='replace')
    need("'/employee-auto-absence-backups/activation.json'" in service and "'/install.lock'" in service, 'CODE_READS_ANOTHER_FOLDER')
    need("employees:auto-absence" in command and '--dry-run' in command, 'CODE_COMMAND_DIFFERS')


def folder_state(folder, root_uid, gid):
    """The folder belongs to root and the site's group (0750): the site user reads it, never changes it."""
    try:
        info = folder.lstat()
    except FileNotFoundError:
        return None
    need(stat.S_ISDIR(info.st_mode), 'FOLDER_NOT_A_FOLDER')
    need(info.st_uid == root_uid and info.st_gid == gid and stat.S_IMODE(info.st_mode) == 0o750, 'FOLDER_OWNERSHIP')
    return info


def lock_state(lock, uid):
    """The command opens the lock as the site user and refuses one that others could read: the site user's, 0600."""
    try:
        info = lock.lstat()
    except FileNotFoundError:
        return None
    need(stat.S_ISREG(info.st_mode) and info.st_uid == uid and stat.S_IMODE(info.st_mode) == 0o600, 'LOCK_FILE')
    return info


def read_activation(path):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return None
    need(stat.S_ISREG(info.st_mode) and info.st_size <= 256, 'ACTIVATION_FILE')
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
    except ValueError:
        data = None
    need(isinstance(data, dict) and list(data) == ['starts_on'] and valid_day(data['starts_on']), 'ACTIVATION_FILE')
    return data['starts_on']


def sync_directory(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_atomic(path, data, uid, gid, mode):
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-', dir=str(path.parent))
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


class Held:
    """Holds the command's lock: a run in progress finishes first, and none starts until the change is done."""

    def __init__(self, lock, wait=300):
        self.lock = lock
        self.wait = wait
        self.fd = None

    def __enter__(self):
        self.fd = os.open(str(self.lock), os.O_RDONLY | os.O_NOFOLLOW)
        deadline = time.monotonic() + self.wait
        while True:
            try:
                fcntl.flock(self.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                return self
            except BlockingIOError:
                if time.monotonic() > deadline:
                    os.close(self.fd)
                    self.fd = None
                    need(False, 'STILL_RUNNING')
                time.sleep(1)

    def __exit__(self, *error):
        fcntl.flock(self.fd, fcntl.LOCK_UN)
        os.close(self.fd)


def units(user, group, site, php):
    service = ('[Unit]\n'
               'Description=GO ERP: unexcused absence for employees with no attendance by the deadline\n'
               'After=network-online.target\n\n'
               '[Service]\n'
               'Type=oneshot\n'
               'User={user}\n'
               'Group={group}\n'
               'WorkingDirectory={site}\n'
               'ExecStart={php} artisan employees:auto-absence\n'
               'TimeoutStartSec=600\n'
               'Nice=10\n'
               'NoNewPrivileges=true\n').format(user=user, group=group, site=site, php=php)
    timer = ('[Unit]\n'
             'Description=GO ERP: check the employees\' attendance deadline every {every} minutes\n\n'
             '[Timer]\n'
             'OnCalendar=*:0/{every}\n'
             'Persistent=true\n'
             'AccuracySec=30s\n'
             'Unit={name}.service\n\n'
             '[Install]\n'
             'WantedBy=timers.target\n').format(every=EVERY_MINUTES, name=NAME)
    return {NAME + '.service': service.encode(), NAME + '.timer': timer.encode()}


def dry_run(artisan):
    """The command's own dry run: whether it is ready, and what it would record right now."""
    code, output = artisan(['employees:auto-absence', '--dry-run'])
    if NOT_READY in output:
        return {'ready': False}
    found = COUNTS.search(output)
    need(found, 'DRY_RUN_UNREADABLE')
    try:
        counts = json.loads(found.group(1))
    except ValueError:
        counts = None
    need(isinstance(counts, dict) and isinstance(counts.get('ready'), bool), 'DRY_RUN_UNREADABLE')
    return {key: counts.get(key) for key in ('ready', 'considered', 'recorded', 'skipped', 'errors')}


def timer_state(systemctl):
    code, output = systemctl(['is-active', NAME + '.timer'])
    return output.strip() or 'unknown'


def plan(folder, unit_folder, site, user, today, systemctl, root_uid=0):
    info = pwd.getpwnam(user)
    check_code(site)
    folder_state(folder, root_uid, info.pw_gid)
    lock_state(folder / 'install.lock', info.pw_uid)
    current = read_activation(folder / 'activation.json')
    timer = timer_state(systemctl)
    return {'result': 'GO_ERP_AUTO_ABSENCE_PLAN', 'state': 'on' if current and timer == 'active' else 'off',
            'starts_on': current or (today + datetime.timedelta(days=1)).isoformat(), 'deadline': '22:00',
            'every_minutes': EVERY_MINUTES, 'timer': timer,
            'unit_files': sorted(name for name in units(user, '-', site, PHP) if (unit_folder / name).exists())}


def apply(folder, unit_folder, site, php, user, today, artisan, systemctl, starts_on=None, root_uid=0, root_gid=0):
    info = pwd.getpwnam(user)
    uid, gid = info.pw_uid, info.pw_gid
    group = grp.getgrgid(gid).gr_name
    check_code(site)
    need(starts_on is None or valid_day(starts_on), 'STARTS_ON')
    created = []
    if folder_state(folder, root_uid, gid) is None:
        os.mkdir(str(folder), 0o750)
        created.append(folder)
        os.chown(str(folder), root_uid, gid)
        os.chmod(str(folder), 0o750)
        sync_directory(folder.parent)
        folder_state(folder, root_uid, gid)
    lock = folder / 'install.lock'
    if lock_state(lock, uid) is None:
        fd = os.open(str(lock), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            created.append(lock)
            os.fchown(fd, uid, gid)
            os.fchmod(fd, 0o600)
            os.fsync(fd)
        finally:
            os.close(fd)
        sync_directory(folder)
        lock_state(lock, uid)
    activation = folder / 'activation.json'
    with Held(lock):
        current = read_activation(activation)
        wanted = starts_on or current or (today + datetime.timedelta(days=1)).isoformat()
        if wanted != current:
            write_atomic(activation, json.dumps({'starts_on': wanted}).encode(), root_uid, gid, 0o640)
        try:
            counts = dry_run(artisan)
            problem = None if counts.get('ready') and not counts.get('errors') else 'NOT_READY' if not counts.get('ready') else 'DRY_RUN_ERRORS'
        except Stop as stop:
            problem = str(stop)[len('GO_ERP_AUTO_ABSENCE_'):]
        except Exception:
            problem = 'DRY_RUN_FAILED'
        if problem:
            # Back to how it was: nothing of this run stays.
            if current is None:
                activation.unlink()
            elif wanted != current:
                write_atomic(activation, json.dumps({'starts_on': current}).encode(), root_uid, gid, 0o640)
    if problem:
        for path in reversed(created):
            path.rmdir() if path == folder else path.unlink()
        need(False, problem)
    changed = []
    for name, content in units(user, group, site, php).items():
        path = unit_folder / name
        if not path.exists() or path.read_bytes() != content:
            write_atomic(path, content, root_uid, root_gid, 0o644)
            changed.append(name)
    if changed:
        need(systemctl(['daemon-reload'])[0] == 0, 'SYSTEMD')
    need(systemctl(['enable', '--now', NAME + '.timer'])[0] == 0, 'SYSTEMD')
    need(timer_state(systemctl) == 'active', 'TIMER_NOT_ACTIVE')
    return {'result': 'GO_ERP_AUTO_ABSENCE_ON', 'starts_on': wanted, 'deadline': '22:00', 'every_minutes': EVERY_MINUTES,
            'already_on': current == wanted and not changed and not created, 'dry_run': counts}


def rollback(folder, unit_folder, systemctl, root_uid=0):
    code, output = systemctl(['disable', '--now', NAME + '.timer'])
    removed = []
    for name in (NAME + '.timer', NAME + '.service'):
        path = unit_folder / name
        if path.exists():
            path.unlink()
            removed.append(name)
    if removed:
        need(systemctl(['daemon-reload'])[0] == 0, 'SYSTEMD')
    activation = folder / 'activation.json'
    lock = folder / 'install.lock'
    was = None
    if folder.exists() and lock.exists():
        with Held(lock):
            was = read_activation(activation)
            if was is not None:
                activation.unlink()
    elif activation.exists():
        was = read_activation(activation)
        activation.unlink()
    need(timer_state(systemctl) != 'active', 'TIMER_STILL_ACTIVE')
    return {'result': 'GO_ERP_AUTO_ABSENCE_OFF', 'was_on_since': was, 'unit_files_removed': removed,
            'recorded_absences': 'kept'}


def run_as(user, site, php):
    def artisan(arguments):
        info = pwd.getpwnam(user)
        command = ['runuser', '-u', user, '--', 'env', 'HOME=' + info.pw_dir, php, 'artisan'] + list(arguments)
        result = subprocess.run(command, cwd=str(site), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, timeout=600, check=False)
        return result.returncode, result.stdout.decode('utf-8', 'replace')
    return artisan


def systemctl(arguments):
    result = subprocess.run(['systemctl'] + list(arguments), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, timeout=120, check=False)
    return result.returncode, result.stdout.decode('utf-8', 'replace')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    parser.add_argument('--starts-on')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need(os.access(PHP, os.X_OK), 'PHP_NOT_FOUND')
    need(Path('/usr/share/zoneinfo/Africa/Cairo').exists(), 'CAIRO_TIME_ZONE_MISSING')
    need(options.apply or options.starts_on is None, 'STARTS_ON_NEEDS_APPLY')
    os.umask(0o077)
    if options.rollback:
        result = rollback(FOLDER, UNITS, systemctl)
    elif options.apply:
        result = apply(FOLDER, UNITS, SITE, PHP, WEB_USER, operating_day(), run_as(WEB_USER, SITE, PHP), systemctl,
                       starts_on=options.starts_on)
    else:
        result = plan(FOLDER, UNITS, SITE, WEB_USER, operating_day(), systemctl)
    print(json.dumps(result, sort_keys=True, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_AUTO_ABSENCE_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
