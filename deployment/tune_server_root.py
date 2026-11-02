#!/usr/bin/env python3
"""The four server fixes the owner approved on 2026-11-02 for srv1836524. None changes the site's code:
  1. slowlog   MariaDB logs queries slower than 0.3 s to /var/lib/mysql/go-erp-slow.log, to find what loads the
               database (until turned off by the analysis, or until MariaDB restarts). Nothing else changes.
  2. schedule  The Laravel scheduler runs once a minute, as the site user only: root's duplicate crontab line goes
               (about 20 jobs ran twice, among them balance notices and daily, monthly and yearly reports). Files root
               created where the site writes (storage/logs, storage/framework, bootstrap/cache) go back to the site user.
  3. debug     APP_DEBUG=false: a crash shows a plain error page instead of code details, and Ignition's web tools stop
               answering. Every other cached setting must stay exactly the same, or it is put back.
  4. opcache   PHP 8.2 keeps the site's compiled code in memory (the ea-php82-php-opcache package, sized for the site);
               PHP-FPM reloads gracefully. Files changed on disk are picked up within 2 seconds, so installs work as before.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/tune_server_root.py              what is on now (changes nothing)
  python3 -I -B deployment/tune_server_root.py --apply      makes the four changes, each checked; one that fails is undone
  python3 -I -B deployment/tune_server_root.py --rollback   undoes the last --apply

Whatever a change replaces is kept in /var/lib/go-erp-server-tuning (root only). No setting's value, password or key
is ever printed.
"""
import argparse
import datetime
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import statistics
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

HOST = 'srv1836524'
WEB_USER = 'fasakha'
SITE = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
PHP_ROOT = Path('/opt/cpanel/ea-php82/root')
FPM_UNIT = 'ea-php82-php-fpm'
OPCACHE_PACKAGE = 'ea-php82-php-opcache'
OPCACHE_INI = PHP_ROOT / 'etc/php.d/zz-go-erp-opcache.ini'
OPCACHE_SETTINGS = b"""; GO ERP on srv1836524: the site's compiled PHP stays in memory. Files changed on disk are picked
; up within 2 seconds (validate_timestamps), so installs that replace files keep working as before.
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=32531
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.save_comments=1
"""
BACKUPS = Path('/var/lib/go-erp-server-tuning')
ORIGIN = 'https://fasakhaninja.com'
SLOW_LOG = '/var/lib/mysql/go-erp-slow.log'
LONG_QUERY_TIME = '0.3'
SLOW_VARIABLES = ('slow_query_log', 'long_query_time', 'slow_query_log_file')
# Where the site writes at run time: anything root left there goes back to the site user.
SITE_WRITES = ('storage/logs', 'storage/framework', 'bootstrap/cache')
STEPS = ('slowlog', 'schedule', 'debug', 'opcache')
READ_CONFIG = r'''
$config = require $argv[1];
$debug = $config['app']['debug'] ?? null;
unset($config['app']['debug']);
echo json_encode(['debug' => $debug, 'rest' => md5(serialize($config))]);
'''


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_SERVER_TUNING_' + reason)


# What touches the machine; the tests replace these. -----------------------------------------------------------------

def command(arguments, user=None, cwd=None, timeout=900, data=None):
    """(exit code, output) of a command, run as the site user when given. stdin is never inherited: this runs inside
    a shell heredoc it must not read."""
    arguments = [str(item) for item in arguments]
    if user is not None:
        info = pwd.getpwnam(user)
        if info.pw_uid != os.geteuid():
            arguments = ['runuser', '-u', user, '--', 'env', 'HOME=' + info.pw_dir] + arguments
    options = {'input': data} if data is not None else {'stdin': subprocess.DEVNULL}
    try:
        result = subprocess.run(arguments, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=None if cwd is None else str(cwd),
                                timeout=timeout, check=False, **options)
    except (OSError, subprocess.TimeoutExpired):
        return 127, ''
    return result.returncode, result.stdout.decode('utf-8', 'replace')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *arguments, **options):
        return None


def page(path, timeout=20):
    """(status, seconds to the first byte) of a page as a browser gets it; nothing of it is kept."""
    request = urllib.request.Request(ORIGIN + path + ('&' if '?' in path else '?') + 'go_erp_tuning=' + str(time.time()),
                                     headers={'User-Agent': 'go-erp-server-tuning'})
    started = time.monotonic()
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=timeout) as response:
            response.read(1)
            return response.status, time.monotonic() - started
    except urllib.error.HTTPError as error:
        return error.code, time.monotonic() - started
    except (urllib.error.URLError, OSError):
        return None, None


def fpm_opcache_loaded():
    """Whether the running PHP-FPM 8.2 master has OPcache in memory (its memory map holds opcache.so)."""
    found = False
    for pid in os.listdir('/proc'):
        if not pid.isdigit():
            continue
        try:
            title = Path('/proc', pid, 'cmdline').read_bytes().replace(b'\0', b' ')
            if not (title.startswith(b'php-fpm: master process') and str(PHP_ROOT).encode() in title):
                continue
            found = True
            if b'opcache.so' in Path('/proc', pid, 'maps').read_bytes():
                return True
        except OSError:
            continue
    return False if found else None


sleep = time.sleep


# Shared helpers -----------------------------------------------------------------------------------------------------

def answers(path, wanted, attempts=10):
    for attempt in range(attempts):
        status, seconds = page(path)
        if status == wanted:
            return True
        if attempt + 1 < attempts:
            sleep(2)
    return False


def page_time(samples=5):
    """Median milliseconds for the sign-in page, after one request to warm it."""
    page('/admin/login')
    times = [seconds for status, seconds in (page('/admin/login') for _ in range(samples)) if status == 200 and seconds is not None]
    return int(statistics.median(times) * 1000) if times else None


def write_keeping(path, data, info):
    """Replaces a file atomically, keeping its owner and mode."""
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-tuning-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fchown(output.fileno(), info.st_uid, info.st_gid)
            os.fchmod(output.fileno(), stat.S_IMODE(info.st_mode))
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
    except BaseException:
        if os.path.exists(temporary):
            os.unlink(temporary)
        raise


def mysql_variables(names):
    code, output = command(['mysql', '--batch', '--skip-column-names', '-e',
                            'SHOW GLOBAL VARIABLES WHERE Variable_name IN ({})'.format(','.join("'{}'".format(name) for name in names))])
    need(code == 0, 'DATABASE_NOT_READABLE')
    return dict(line.split('\t', 1) for line in output.splitlines() if '\t' in line)


CRONTABS = Path('/var/spool/cron')


def crontab(user):
    code, output = command(['crontab', '-l', '-u', user])
    if code != 0 and not (CRONTABS / user).exists():
        return ''
    need(code == 0, 'CRONTAB_NOT_READABLE')
    return output


def active(table):
    return [line.rstrip() for line in table.splitlines() if line.strip() and not line.lstrip().startswith('#')]


def scheduler_line(line):
    stripped = line.strip()
    return bool(stripped) and not stripped.startswith('#') and 'schedule:run' in stripped and str(SITE) in stripped


def root_owned(site, folders=SITE_WRITES):
    found = []
    for name in folders:
        base = site / name
        if not base.is_dir():
            continue
        for folder, _, files in os.walk(str(base)):
            for entry in [folder] + [os.path.join(folder, item) for item in files]:
                try:
                    info = os.lstat(entry)
                except OSError:
                    continue
                if info.st_uid == 0:
                    found.append(entry)
    return found


def env_with_debug(data, value):
    """The .env bytes with APP_DEBUG set to value; every other byte unchanged. Returns (new bytes, old value)."""
    lines = data.split(b'\n')
    matches = [index for index, line in enumerate(lines) if re.match(br'^\s*APP_DEBUG\s*=', line)]
    need(len(matches) == 1, 'APP_DEBUG_LINE_NOT_SINGLE')
    line = lines[matches[0]]
    ending = b'\r' if line.endswith(b'\r') else b''
    old = line.rstrip(b'\r').split(b'=', 1)[1].strip().strip(b'"\'').lower()
    lines[matches[0]] = b'APP_DEBUG=' + value + ending
    return b'\n'.join(lines), old


def cached_config(site):
    """app.debug as cached, and a fingerprint of every other cached setting (never their values)."""
    cached = site / 'bootstrap/cache/config.php'
    need(cached.is_file(), 'CONFIG_NOT_CACHED')
    code, output = command([PHP, '-r', READ_CONFIG, '--', str(cached)], user=WEB_USER)
    need(code == 0, 'CONFIG_NOT_READABLE')
    try:
        found = json.loads(output)
    except ValueError:
        raise Stop('GO_ERP_SERVER_TUNING_CONFIG_NOT_READABLE')
    return found


def artisan(site, *arguments):
    return command([PHP, 'artisan'] + list(arguments), user=WEB_USER, cwd=site, timeout=300)[0]


def reload_fpm():
    if command(['systemctl', 'reload', FPM_UNIT])[0] != 0:
        command(['systemctl', 'restart', FPM_UNIT])


# 1. slowlog ---------------------------------------------------------------------------------------------------------

def slowlog_state():
    values = mysql_variables(SLOW_VARIABLES)
    return 'slow_query_log {}, long_query_time {}, {}'.format(values.get('slow_query_log'), values.get('long_query_time'),
                                                             'logging to the GO ERP file' if values.get('slow_query_log_file') == SLOW_LOG else 'its own file')


def slowlog_apply(site, backup):
    before = mysql_variables(SLOW_VARIABLES)
    need(set(before) == set(SLOW_VARIABLES), 'DATABASE_NOT_READABLE')
    sql = "SET GLOBAL slow_query_log_file='{}'; SET GLOBAL long_query_time={}; SET GLOBAL slow_query_log=ON;".format(SLOW_LOG, LONG_QUERY_TIME)
    saved = {'previous': before}
    if command(['mysql', '-e', sql])[0] != 0 or mysql_variables(SLOW_VARIABLES).get('slow_query_log') != 'ON':
        slowlog_undo(site, backup, saved)
        raise Stop('GO_ERP_SERVER_TUNING_SLOWLOG_NOT_ON')
    return saved, 'on: queries over {} s go to {}'.format(LONG_QUERY_TIME, SLOW_LOG)


def slowlog_undo(site, backup, saved):
    previous = saved['previous']
    need(re.match(r'^[A-Za-z0-9_./-]+$', previous.get('slow_query_log_file', '')), 'SLOWLOG_PREVIOUS_FILE')
    sql = "SET GLOBAL slow_query_log={}; SET GLOBAL long_query_time={}; SET GLOBAL slow_query_log_file='{}';".format(
        'ON' if previous.get('slow_query_log') == 'ON' else 'OFF', float(previous.get('long_query_time', '10')), previous['slow_query_log_file'])
    need(command(['mysql', '-e', sql])[0] == 0, 'SLOWLOG_NOT_RESTORED')


# 2. schedule --------------------------------------------------------------------------------------------------------

def schedule_state(site):
    root_lines = [line for line in crontab('root').splitlines() if scheduler_line(line)]
    site_lines = [line for line in crontab(WEB_USER).splitlines() if scheduler_line(line)]
    return 'root runs the scheduler: {} line(s); the site user: {} line(s); files root owns where the site writes: {}'.format(
        len(root_lines), len(site_lines), len(root_owned(site)))


def schedule_apply(site, backup):
    need(any(scheduler_line(line) for line in crontab(WEB_USER).splitlines()), 'SITE_SCHEDULER_MISSING')
    table = crontab('root')
    lines = table.splitlines(True)
    matches = [index for index, line in enumerate(lines) if scheduler_line(line)]
    need(len(matches) <= 1, 'ROOT_SCHEDULER_LINES_UNEXPECTED')
    saved = {'crontab': None, 'owners': []}
    try:
        if matches:
            (backup / 'root.crontab').write_text(table)
            saved['crontab'] = 'root.crontab'
            kept = ''.join(line for index, line in enumerate(lines) if index != matches[0])
            if kept and not kept.endswith('\n'):
                kept += '\n'
            fd, temporary = tempfile.mkstemp(prefix='go-erp-crontab-', dir=str(backup))
            with os.fdopen(fd, 'w') as output:
                output.write(kept)
            need(command(['crontab', '-u', 'root', temporary])[0] == 0, 'ROOT_CRONTAB_NOT_WRITTEN')
            os.unlink(temporary)
            need(active(crontab('root')) == active(kept), 'ROOT_CRONTAB_NOT_AS_WRITTEN')
        info = pwd.getpwnam(WEB_USER)
        for path in root_owned(site):
            owner = os.lstat(path)
            saved['owners'].append([path, owner.st_uid, owner.st_gid])
            os.lchown(path, info.pw_uid, info.pw_gid)
        (backup / 'owners.json').write_text(json.dumps(saved['owners']))
    except Exception:
        schedule_undo(site, backup, saved)
        raise
    return saved, '{}; {} file(s) given back to {}'.format('root\'s duplicate scheduler line removed' if matches else 'root had no scheduler line',
                                                          len(saved['owners']), WEB_USER)


def schedule_undo(site, backup, saved):
    for path, uid, gid in saved.get('owners', []):
        if os.path.lexists(path):
            os.lchown(path, uid, gid)
    if saved.get('crontab'):
        need(command(['crontab', '-u', 'root', str(backup / saved['crontab'])])[0] == 0, 'ROOT_CRONTAB_NOT_RESTORED')


# 3. debug -----------------------------------------------------------------------------------------------------------

def debug_state(site):
    return 'APP_DEBUG cached: {}'.format(json.dumps(cached_config(site)['debug']))


def debug_apply(site, backup):
    environment = site / '.env'
    data, info = environment.read_bytes(), environment.lstat()
    before = cached_config(site)
    changed, old = env_with_debug(data, b'false')
    if before['debug'] is False and old == b'false':
        return {'env': None}, 'already off'
    (backup / 'env').write_bytes(data)
    os.chmod(str(backup / 'env'), 0o600)
    saved = {'env': 'env'}
    write_keeping(environment, changed, info)
    try:
        need(artisan(site, 'config:cache') == 0, 'CONFIG_NOT_CACHED_AGAIN')
        after = cached_config(site)
        need(after['rest'] == before['rest'], 'OTHER_SETTINGS_WOULD_CHANGE')
        need(after['debug'] is False, 'DEBUG_STILL_ON')
        need(answers('/admin/login', 200), 'SITE_NOT_ANSWERING')
        need(answers('/_ignition/health-check', 404), 'IGNITION_STILL_ANSWERING')
    except Exception:
        debug_undo(site, backup, saved)
        raise
    return saved, 'off: other settings unchanged; /admin/login 200; Ignition 404'


def debug_undo(site, backup, saved):
    if not saved.get('env'):
        return
    environment = site / '.env'
    write_keeping(environment, (backup / saved['env']).read_bytes(), environment.lstat())
    need(artisan(site, 'config:cache') == 0, 'CONFIG_NOT_RESTORED')


# 4. opcache ---------------------------------------------------------------------------------------------------------

def package_installed():
    return command(['rpm', '-q', OPCACHE_PACKAGE])[0] == 0


def opcache_state(site):
    loaded = fpm_opcache_loaded()
    return 'package {}; loaded in the running PHP-FPM: {}'.format('installed' if package_installed() else 'not installed',
                                                                  {True: 'yes', False: 'no', None: 'PHP-FPM 8.2 not found'}[loaded])


def opcache_apply(site, backup):
    need(fpm_opcache_loaded() is not None, 'FPM_NOT_FOUND')
    before = page_time()
    saved = {'package': False, 'ini': OPCACHE_INI.read_bytes().decode('utf-8', 'replace') if OPCACHE_INI.exists() else None}
    try:
        if not package_installed():
            need(command(['dnf', '-y', '-q', 'install', OPCACHE_PACKAGE], timeout=1800)[0] == 0 and package_installed(), 'PACKAGE_NOT_INSTALLED')
            saved['package'] = True
        OPCACHE_INI.write_bytes(OPCACHE_SETTINGS)
        os.chmod(str(OPCACHE_INI), 0o644)
        need(command([PHP_ROOT / 'usr/sbin/php-fpm', '-t'])[0] == 0, 'FPM_CONFIGURATION_INVALID')
        reload_fpm()
        for attempt in range(15):
            if fpm_opcache_loaded():
                break
            sleep(2)
        need(fpm_opcache_loaded(), 'NOT_LOADED_AFTER_RELOAD')
        need(answers('/admin/login', 200), 'SITE_NOT_ANSWERING')
    except Exception:
        opcache_undo(site, backup, saved)
        raise
    after = page_time()
    return saved, 'on: {} installed, PHP-FPM reloaded with it; sign-in page {} ms -> {} ms'.format(
        OPCACHE_PACKAGE if saved['package'] else 'package already', before, after)


def opcache_undo(site, backup, saved):
    if saved.get('ini') is None:
        try:
            OPCACHE_INI.unlink()
        except FileNotFoundError:
            pass
    else:
        OPCACHE_INI.write_bytes(saved['ini'].encode('utf-8'))
    if saved.get('package'):
        command(['dnf', '-y', '-q', 'remove', OPCACHE_PACKAGE], timeout=1800)
    reload_fpm()
    need(answers('/admin/login', 200), 'SITE_NOT_ANSWERING_AFTER_UNDO')


# Plan, apply, rollback ----------------------------------------------------------------------------------------------

APPLY = {'slowlog': slowlog_apply, 'schedule': schedule_apply, 'debug': debug_apply, 'opcache': opcache_apply}
UNDO = {'slowlog': slowlog_undo, 'schedule': schedule_undo, 'debug': debug_undo, 'opcache': opcache_undo}


def safely(step):
    try:
        return step()
    except Stop as error:
        return str(error)
    except Exception as error:  # noqa: BLE001 - one part failing must not hide the others
        return 'unreadable ({})'.format(type(error).__name__)


def plan(site):
    lines = ['GO_ERP_SERVER_TUNING_PLAN']
    lines.append('slowlog: ' + safely(slowlog_state))
    lines.append('schedule: ' + safely(lambda: schedule_state(site)))
    lines.append('debug: ' + safely(lambda: debug_state(site)))
    lines.append('opcache: ' + safely(lambda: opcache_state(site)))
    lines.append('sign-in page: {} ms'.format(safely(page_time)))
    return lines


def backups_folder(backups):
    if not backups.exists():
        backups.mkdir(mode=0o700, parents=True)
    info = backups.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid(), 'BACKUPS_FOLDER')
    os.chmod(str(backups), 0o700)
    folder = backups / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S-%f')
    folder.mkdir(mode=0o700)
    return folder


def apply(site, backups, steps=STEPS):
    folder = backups_folder(backups)
    manifest = {'format': 1, 'site': str(site), 'done': {}}
    lines, failed = ['GO_ERP_SERVER_TUNING_APPLY'], []
    for name in steps:
        try:
            saved, words = APPLY[name](site, folder)
            manifest['done'][name] = saved
            lines.append('{}: done; {}'.format(name, words))
        except Stop as error:
            failed.append(name)
            lines.append('{}: not done ({}); left as it was'.format(name, error))
        except Exception as error:  # noqa: BLE001
            failed.append(name)
            lines.append('{}: not done (unexpected {}); check it before trying again'.format(name, type(error).__name__))
        (folder / 'manifest.json').write_text(json.dumps(manifest, indent=1))
    lines.append('backup: ' + str(folder))
    lines.append(('GO_ERP_SERVER_TUNING_DONE' if not failed else 'GO_ERP_SERVER_TUNING_PARTIAL') +
                 ' ({} of {})'.format(len(steps) - len(failed), len(steps)))
    return lines, not failed


def rollback(site, backups):
    sets = sorted(item for item in backups.iterdir() if (item / 'manifest.json').is_file()) if backups.exists() else []
    need(sets, 'NOTHING_TO_ROLL_BACK')
    folder = sets[-1]
    manifest = json.loads((folder / 'manifest.json').read_text())
    need(not manifest.get('rolled_back'), 'ALREADY_ROLLED_BACK')
    lines, failed = ['GO_ERP_SERVER_TUNING_ROLLBACK ' + str(folder)], []
    for name in reversed(STEPS):
        if name not in manifest['done']:
            continue
        try:
            UNDO[name](site, folder, manifest['done'][name])
            lines.append('{}: undone'.format(name))
        except Exception as error:  # noqa: BLE001
            failed.append(name)
            lines.append('{}: NOT undone ({})'.format(name, error if isinstance(error, Stop) else type(error).__name__))
    manifest['rolled_back'] = not failed
    (folder / 'manifest.json').write_text(json.dumps(manifest, indent=1))
    lines.append('GO_ERP_SERVER_TUNING_ROLLED_BACK' if not failed else 'GO_ERP_SERVER_TUNING_ROLLBACK_INCOMPLETE')
    return lines, not failed


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need((SITE / 'artisan').is_file() and os.access(PHP, os.X_OK), 'SITE_NOT_FOUND')
    os.umask(0o077)
    if options.apply:
        lines, ok = apply(SITE, BACKUPS)
    elif options.rollback:
        lines, ok = rollback(SITE, BACKUPS)
    else:
        lines, ok = plan(SITE), True
    print('\n'.join(lines), flush=True)
    if not ok:
        raise SystemExit(1)


if __name__ == '__main__':
    try:
        main()
    except Stop as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except Exception as error:  # noqa: BLE001
        print('GO_ERP_SERVER_TUNING_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
