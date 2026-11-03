#!/usr/bin/env python3
"""Clear the branches' trial operations on srv1836524 so real work starts from zero.

Cleared: the cashier, dine-in and delivery sales (with their prints, kitchen tickets and order numbers), expenses,
the cash drawers' movements and shift closings, and the stock movements. Set back to zero: every drawer's balance,
every stock balance, and every table free. Kept: the customer app's orders and their numbering, menus, tables,
customers, employees, salaries, settings and the desktop devices. With --employees, attendance days and employee
deductions, bonuses and advances are cleared too (each month's pay then counts every day worked, with no late or absence
deduction). With --keep-stock, the stock's balances and movements stay as they are. With --through YYYY-MM-DD, nothing is
cleared if anything was recorded after that operating day, so real work is never cleared.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/reset_branch_operations_root.py [options]            what would change, nothing changes
  python3 -I -B deployment/reset_branch_operations_root.py [options] --apply    backup, then clear and zero
  python3 -I -B deployment/reset_branch_operations_root.py --restore FILE       put that backup back

--apply first keeps a full copy of every row it changes in /var/lib/go-erp-branch-reset (root only), then clears and
zeroes everything in one database transaction, and only if nothing was recorded between the copy and the clearing.
--restore puts a copy back only while the cleared tables are still empty. The database work is done by
deployment/reset_branch_operations.php, run as the site user with the site's own database settings; nothing here
reads or prints them.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import tempfile

HOST = 'srv1836524'
WEB_USER = 'fasakha'
SITE = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BACKUPS = Path('/var/lib/go-erp-branch-reset')
SCRIPT = Path(__file__).resolve().parent / 'reset_branch_operations.php'


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_BRANCH_RESET_' + reason)


def runner(user, php, site, script):
    """Runs the PHP part as the site user from a private copy, its input on stdin; returns its JSON answer."""
    def run(mode, payload=None, flags=()):
        info = pwd.getpwnam(user)
        work = Path(tempfile.mkdtemp(prefix='go-erp-reset-'))
        try:
            os.chown(str(work), info.pw_uid, info.pw_gid)
            os.chmod(str(work), 0o700)
            copy = work / 'reset_branch_operations.php'
            copy.write_bytes(script.read_bytes())
            os.chown(str(copy), info.pw_uid, info.pw_gid)
            os.chmod(str(copy), 0o600)
            command = [php, str(copy), mode, str(site)] + list(flags)
            if info.pw_uid != os.geteuid():
                command = ['runuser', '-u', user, '--', 'env', 'HOME=' + info.pw_dir] + command
            result = subprocess.run(command, cwd=str(site), input=payload if payload is not None else b'', stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE, timeout=1800, check=False)
        finally:
            shutil.rmtree(str(work), ignore_errors=True)
        try:
            answer = json.loads(result.stdout.decode('utf-8'))
        except ValueError:
            answer = None
        need(isinstance(answer, dict), 'PHP_FAILED')
        need('error' not in answer, 'PHP: ' + str(answer.get('error')))
        need(result.returncode == 0, 'PHP_FAILED')
        return answer
    return run


def backups_folder(folder):
    if not folder.exists():
        folder.mkdir(mode=0o700, parents=True)
    info = folder.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700, 'BACKUP_FOLDER')
    return folder


def write_backup(folder, data):
    stamp = datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S')
    path = folder / ('backup-' + stamp + '.json')
    fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as output:
        output.write(data)
        output.flush()
        os.fsync(output.fileno())
    return path


def flags(employees=False, keep_stock=False, through=None):
    """The PHP part's options; a day must be a calendar day."""
    chosen = []
    if employees:
        chosen.append('--employees')
    if keep_stock:
        chosen.append('--keep-stock')
    if through is not None:
        need(re.fullmatch(r'\d{4}-\d{2}-\d{2}', through) is not None, 'THROUGH_NOT_A_DAY')
        datetime.date.fromisoformat(through)
        chosen.append('--through=' + through)
    return chosen


def plan(run, options=()):
    answer = run('plan', flags=options)
    return dict(answer, result='GO_ERP_BRANCH_RESET_PLAN')


def apply(run, folder, options=()):
    backup = run('export', flags=options)
    need(backup.get('format') == 1 and isinstance(backup.get('fingerprint'), dict), 'BACKUP_UNREADABLE')
    data = json.dumps(backup, ensure_ascii=False, sort_keys=True).encode('utf-8')
    path = write_backup(backups_folder(folder), data)
    # The copy is on disk (and synced) before anything is cleared.
    need(json.loads(path.read_bytes().decode('utf-8'))['fingerprint'] == backup['fingerprint'], 'BACKUP_UNREADABLE')
    done = run('apply', json.dumps(backup['fingerprint']).encode('utf-8'), flags=options)
    return {'result': 'GO_ERP_BRANCH_RESET_DONE', 'backup': str(path), 'backup_sha256': hashlib.sha256(data).hexdigest(),
            'backup_rows': sum(len(rows) for rows in backup['tables'].values()), 'deleted': done['deleted'], 'zeroed': done['zeroed'],
            'order_numbers_restarted': done['order_numbers_restarted']}


def restore(run, folder, file):
    path = Path(file).resolve()
    need(path.parent == folder.resolve() and path.is_file() and not path.is_symlink(), 'NOT_A_BACKUP_OF_THIS_SCRIPT')
    data = path.read_bytes()
    backup = json.loads(data.decode('utf-8'))
    need(backup.get('format') == 1, 'NOT_A_BACKUP_OF_THIS_SCRIPT')
    done = run('restore', data, flags=['--employees'] if backup.get('employees') else [])
    return {'result': 'GO_ERP_BRANCH_RESET_RESTORED', 'backup': str(path), 'restored': done['restored']}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--restore')
    parser.add_argument('--employees', action='store_true')
    parser.add_argument('--keep-stock', action='store_true')
    parser.add_argument('--through')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need(os.access(PHP, os.X_OK) and (SITE / 'artisan').is_file() and SCRIPT.is_file(), 'SITE_NOT_FOUND')
    os.umask(0o077)
    run = runner(WEB_USER, PHP, SITE, SCRIPT)
    chosen = flags(options.employees, options.keep_stock, options.through)
    if options.restore:
        result = restore(run, BACKUPS, options.restore)
    elif options.apply:
        result = apply(run, BACKUPS, chosen)
    else:
        result = plan(run, chosen)
    print(json.dumps(result, sort_keys=True, ensure_ascii=False), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_BRANCH_RESET_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
