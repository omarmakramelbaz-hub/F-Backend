#!/usr/bin/env python3
"""After a day of the slow query log (turned on by tune_server_root.py), what loads srv1836524 and what fails on it:
  - slow queries: grouped by their shape (every value replaced by ?), ranked by the total time they took;
  - the site's logs: errors per day, and the errors that repeat (their class, code and the site's own file and line
    where they happen; never their message, which can hold customers' data);
  - now: the load, PHP-FPM's OPcache, the sign-in page's time and the database's counters.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/analyze_live_server_root.py             the report (changes nothing)
  python3 -I -B deployment/analyze_live_server_root.py --finish    the report, then the slow query log goes back to its
                                                                   previous settings and its file (it holds the queries'
                                                                   values) is removed
"""
import argparse
import collections
import datetime
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import time
import urllib.error
import urllib.request

HOST = 'srv1836524'
SITE = Path('/home/fasakha/public_html')
SLOW_LOG = Path('/var/lib/mysql/go-erp-slow.log')
TUNING = Path('/var/lib/go-erp-server-tuning')
PHP_ROOT = '/opt/cpanel/ea-php82/root'
ORIGIN = 'https://fasakhaninja.com'
DAYS = 7
TOP = 25
STATUS = ('Uptime', 'Questions', 'Slow_queries', 'Created_tmp_disk_tables', 'Created_tmp_tables', 'Select_full_join',
          'Threads_connected', 'Max_used_connections')
ENTRY = re.compile(r'^\[(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}:\d{2}\] \w+\.(\w+): ')
EXCEPTION = re.compile(r'\[object\] \(([A-Za-z0-9_\\]+)\(code: ([A-Za-z0-9]{0,12})\)')
THROWN_AT = re.compile(r' at (/[^\s()]+\.php):(\d+)\)')
FRAME = re.compile(r'^#\d+ (/[^\s()]+\.php)\((\d+)\)')
SITE_CODE = ('app/', 'routes/', 'config/', 'database/', 'resources/')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_LIVE_ANALYSIS_' + reason)


def command(arguments, timeout=300):
    """(exit code, output); stdin is never inherited (this runs inside a shell heredoc)."""
    try:
        result = subprocess.run([str(item) for item in arguments], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return 127, ''
    return result.returncode, result.stdout.decode('utf-8', 'replace')


# Slow queries -------------------------------------------------------------------------------------------------------

STRING = re.compile(r"'(?:[^'\\]|\\.|'')*'|\"(?:[^\"\\]|\\.|\"\")*\"", re.S)
NUMBER = re.compile(r'(?<![\w.`$])-?\b\d+(?:\.\d+)?(?:[eE][-+]?\d+)?\b')
HEX = re.compile(r'\b0x[0-9a-fA-F]+\b')
LIST = re.compile(r'\(\s*\?(?:\s*,\s*\?)*\s*\)')
ROWS = re.compile(r'\(\?\+\)(?:\s*,\s*\(\?\+\))+')


def shape(sql):
    """The query with every value replaced by ?; None when a value could not be removed for sure."""
    text = STRING.sub('?', sql.strip().rstrip(';'))
    text = HEX.sub('?', text)
    text = NUMBER.sub('?', text)
    if "'" in text or '"' in text or '\\' in text:
        return None
    text = re.sub(r'\s+', ' ', text)
    text = LIST.sub('(?+)', text)
    text = ROWS.sub('(?+), ...', text)
    return text


def slow_queries(path):
    """Slow log entries grouped by shape: count, total and longest seconds, rows examined."""
    found = {'entries': 0, 'withheld': 0, 'first': None, 'last': None, 'shapes': {}}
    if not path.is_file():
        found['missing'] = True
        return found
    entry = None

    def close(entry):
        if not entry or not entry['sql']:
            return
        found['entries'] += 1
        key = shape(' '.join(entry['sql']))
        if key is None:
            found['withheld'] += 1
            key = '(a query whose values could not be removed; not shown)'
        row = found['shapes'].setdefault(key, {'count': 0, 'seconds': 0.0, 'longest': 0.0, 'examined': 0})
        row['count'] += 1
        row['seconds'] += entry['seconds']
        row['longest'] = max(row['longest'], entry['seconds'])
        row['examined'] += entry['examined']

    with open(str(path), 'r', encoding='utf-8', errors='replace') as stream:
        for line in stream:
            if line.startswith('# User@Host:'):
                close(entry)
                entry = {'seconds': 0.0, 'examined': 0, 'sql': []}
            elif entry is None:
                continue
            elif line.startswith('# Query_time:'):
                numbers = dict(re.findall(r'(\w+): ([\d.]+)', line))
                entry['seconds'] = float(numbers.get('Query_time', 0))
                entry['examined'] = int(float(numbers.get('Rows_examined', 0)))
            elif line.startswith('SET timestamp='):
                stamp = int(re.sub(r'\D', '', line) or 0)
                found['first'] = stamp if found['first'] is None else min(found['first'], stamp)
                found['last'] = stamp if found['last'] is None else max(found['last'], stamp)
            elif line.startswith('#') or re.match(r'^use \w+;$', line.strip()):
                continue
            else:
                entry['sql'].append(line.strip())
    close(entry)
    return found


# The site's logs ----------------------------------------------------------------------------------------------------

def site_frame(path):
    marker = str(SITE) + '/'
    if path.startswith(marker):
        relative = path[len(marker):]
        if relative.startswith(SITE_CODE):
            return relative
    return None


def site_errors(folder, days=DAYS, today=None):
    """Errors in the site's logs: per day, and grouped by (class, code, the site's own file:line)."""
    today = today or datetime.date.today()
    since = (today - datetime.timedelta(days=days - 1)).isoformat()
    per_day = collections.Counter()
    groups = {}
    entry = None

    def close(entry):
        if not entry:
            return
        key = (entry['class'], entry['code'], entry['where'] or 'not in the site\'s own code')
        row = groups.setdefault(key, {'count': 0, 'last': entry['day'], 'logs': set()})
        row['count'] += 1
        row['last'] = max(row['last'], entry['day'])
        row['logs'].add(entry['log'])

    for path in sorted(folder.glob('*.log')):
        with open(str(path), 'r', encoding='utf-8', errors='replace') as stream:
            for line in stream:
                start = ENTRY.match(line)
                if start:
                    close(entry)
                    entry = None
                    day, level = start.group(1), start.group(2).upper()
                    if day < since or level not in ('ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'):
                        continue
                    per_day[day] += 1
                    found = EXCEPTION.search(line)
                    thrown = THROWN_AT.search(line)
                    entry = {'day': day, 'log': path.name, 'class': found.group(1).replace('\\\\', '\\') if found else '(logged message)',
                             'code': found.group(2) if found else '',
                             'where': None}
                    if thrown:
                        frame = site_frame(thrown.group(1))
                        if frame:
                            entry['where'] = '{}:{}'.format(frame, thrown.group(2))
                elif entry is not None and entry['where'] is None:
                    frame = FRAME.match(line.strip())
                    if frame and site_frame(frame.group(1)):
                        entry['where'] = '{}:{}'.format(site_frame(frame.group(1)), frame.group(2))
    close(entry)
    rows = sorted(groups.items(), key=lambda item: (-item[1]['count'], item[0]))
    return {'since': since, 'per_day': sorted(per_day.items()), 'total': sum(per_day.values()),
            'top': [{'class': key[0], 'code': key[1], 'where': key[2], 'count': value['count'], 'last': value['last'],
                     'logs': ','.join(sorted(value['logs']))} for key, value in rows[:TOP]],
            'kinds': len(groups)}


# Now --------------------------------------------------------------------------------------------------------------

def sign_in_ms(samples=3):
    times = []
    for attempt in range(samples + 1):
        request = urllib.request.Request(ORIGIN + '/admin/login?go_erp_analysis=' + str(time.time()),
                                         headers={'User-Agent': 'go-erp-live-analysis'})
        started = time.monotonic()
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                response.read(1)
                if response.status == 200 and attempt:
                    times.append(time.monotonic() - started)
        except (urllib.error.URLError, OSError):
            pass
    return int(sorted(times)[len(times) // 2] * 1000) if times else None


def opcache_loaded():
    for pid in os.listdir('/proc'):
        if not pid.isdigit():
            continue
        try:
            title = Path('/proc', pid, 'cmdline').read_bytes().replace(b'\0', b' ')
            if title.startswith(b'php-fpm: master process') and PHP_ROOT.encode() in title:
                return b'opcache.so' in Path('/proc', pid, 'maps').read_bytes()
        except OSError:
            continue
    return None


def now():
    found = collections.OrderedDict()
    try:
        found['load'] = ' '.join(Path('/proc/loadavg').read_text().split()[:3])
    except OSError:
        found['load'] = 'unknown'
    found['cpus'] = os.cpu_count()
    found['opcache in PHP-FPM'] = {True: 'loaded', False: 'not loaded', None: 'PHP-FPM 8.2 not found'}[opcache_loaded()]
    found['sign-in page'] = '{} ms'.format(sign_in_ms())
    code, output = command(['mysql', '--batch', '--skip-column-names', '-e',
                            'SHOW GLOBAL STATUS WHERE Variable_name IN ({})'.format(','.join("'{}'".format(name) for name in STATUS))])
    values = dict(line.split('\t', 1) for line in output.splitlines() if '\t' in line) if code == 0 else {}
    uptime = float(values.get('Uptime', 0) or 0)
    for name in STATUS:
        if name in values:
            rate = ' ({:.1f}/s)'.format(float(values[name]) / uptime) if uptime and name in ('Questions', 'Created_tmp_disk_tables') else ''
            found['db ' + name] = values[name] + rate
    return found


# Finish -------------------------------------------------------------------------------------------------------------

def previous_slowlog(tuning):
    """The slow log's settings from before tune_server_root.py --apply, from its newest backup that has them."""
    for folder in sorted((item for item in tuning.iterdir() if (item / 'manifest.json').is_file()), reverse=True) if tuning.exists() else []:
        done = json.loads((folder / 'manifest.json').read_text()).get('done', {})
        if 'slowlog' in done:
            return done['slowlog']['previous']
    return None


def finish(tuning, log):
    previous = previous_slowlog(tuning) or {'slow_query_log': 'OFF', 'long_query_time': '10', 'slow_query_log_file': ''}
    sql = 'SET GLOBAL slow_query_log={};'.format('ON' if previous.get('slow_query_log') == 'ON' else 'OFF')
    if re.match(r'^[0-9.]+$', previous.get('long_query_time', '')):
        sql += ' SET GLOBAL long_query_time={};'.format(float(previous['long_query_time']))
    if re.match(r'^[A-Za-z0-9_./-]+$', previous.get('slow_query_log_file', '')):
        sql += " SET GLOBAL slow_query_log_file='{}';".format(previous['slow_query_log_file'])
    need(command(['mysql', '-e', sql])[0] == 0, 'SLOWLOG_NOT_RESTORED')
    if log.is_file():
        log.unlink()
    return 'slow query log back to {} (as before); its file removed'.format('ON' if previous.get('slow_query_log') == 'ON' else 'OFF')


# The report -------------------------------------------------------------------------------------------------------

def stamp(value):
    return datetime.datetime.fromtimestamp(value, datetime.timezone.utc).strftime('%Y-%m-%d %H:%M UTC') if value else '?'


def report(slow, errors, current, finished=None):
    lines = ['GO_ERP_LIVE_ANALYSIS']
    lines.append('')
    lines.append('== slow queries (over 0.3 s; values replaced by ?) ==')
    if slow.get('missing'):
        lines.append('no slow query log file (MariaDB restarted, or it was never on)')
    else:
        lines.append('{} slow queries in {} shapes, {} to {}{}'.format(
            slow['entries'], len(slow['shapes']), stamp(slow['first']), stamp(slow['last']),
            '; {} not shown (values could not be removed)'.format(slow['withheld']) if slow['withheld'] else ''))
        ranked = sorted(slow['shapes'].items(), key=lambda item: -item[1]['seconds'])
        for sql, row in ranked[:TOP]:
            lines.append('{:>5}x  total {:.1f}s  avg {:.0f}ms  max {:.0f}ms  rows examined avg {:.0f}'.format(
                row['count'], row['seconds'], row['seconds'] * 1000 / row['count'], row['longest'] * 1000, row['examined'] / row['count']))
            lines.append('       ' + (sql if len(sql) <= 600 else sql[:600] + ' …'))
    lines.append('')
    lines.append('== errors in the site\'s logs since {} (class, code, where; never the message) =='.format(errors['since']))
    lines.append('total {} in {} kinds; per day: {}'.format(errors['total'], errors['kinds'],
                                                         ', '.join('{} {}'.format(day[5:], count) for day, count in errors['per_day']) or 'none'))
    for row in errors['top']:
        code = '({})'.format(row['code']) if row['code'] else ''
        lines.append('{:>6}x  {}{} at {}  (last {}, {})'.format(row['count'], row['class'], code, row['where'], row['last'], row['logs']))
    lines.append('')
    lines.append('== now ==')
    for key, value in current.items():
        lines.append('{}: {}'.format(key, value))
    if finished:
        lines.append('')
        lines.append(finished)
    return '\n'.join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--finish', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    try:
        os.nice(10)
    except OSError:
        pass
    slow = slow_queries(SLOW_LOG)
    errors = site_errors(SITE / 'storage/logs')
    current = now()
    finished = finish(TUNING, SLOW_LOG) if options.finish else None
    print(report(slow, errors, current, finished), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Stop as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except Exception as error:  # noqa: BLE001
        print('GO_ERP_LIVE_ANALYSIS_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
