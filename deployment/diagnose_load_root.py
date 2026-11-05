#!/usr/bin/env python3
"""What is loading srv1836524 right now. It only reads.

  1. The web requests of the last minutes, from the site's access log: requests a minute, the addresses most asked
     for (numbers in them and anything after "?" removed), and for the busiest ones the callers (their address cut to
     its first two parts) and their programs.
  2. The database: the queries running at this moment, sampled a few times a second (every value replaced by ?).
  3. The GO ERP desktop devices: when each last synced and how many operations it sent in the last hour.
  4. The processes using the processors most.
With --general-log SECONDS (at most 30): every statement the database runs for those seconds, grouped by shape
(values replaced by ?) with how many connections were opened. The database's general log is turned on for those
seconds only, into a file only root can read; it is turned off, set back as it was and its file removed right after,
whatever happens.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/diagnose_load_root.py [--general-log 10]
"""
import argparse
import collections
import datetime
import glob
import os
from pathlib import Path
import re
import socket
import subprocess
import time

HOST = 'srv1836524'
DATABASE = 'fasakha_db'
DOMAIN = 'fasakhaninja.com'
LOGS = ('/usr/local/apache/domlogs/{d}*', '/usr/local/apache/domlogs/*/{d}*', '/etc/apache2/logs/domlogs/{d}*',
        '/etc/apache2/logs/domlogs/*/{d}*', '/var/log/apache2/domlogs/{d}*', '/home/fasakha/access-logs/{d}*')
WINDOW_MINUTES = 10
TAIL_BYTES = 40 * 1024 * 1024
LINE = re.compile(r'^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+(?: "[^"]*" "([^"]*)")?')


def mysql(query):
    try:
        result = subprocess.run(['mysql', '--batch', '--skip-column-names', '-e', query, DATABASE], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if result.returncode != 0:
        return None
    return [line.split('\t') for line in result.stdout.decode('utf-8', 'replace').splitlines()]


def path_shape(path):
    """An address without its values: no query string, numbers and long tokens as #."""
    path = path.split('?', 1)[0]
    path = re.sub(r'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', '/#', path, flags=re.I)
    path = re.sub(r'/[A-Za-z0-9_\-]{24,}', '/#', path)
    return re.sub(r'\d+', '#', path)[:120]


def masked(address):
    if ':' in address:
        return ':'.join(address.split(':')[:2]) + ':…'
    parts = address.split('.')
    return '.'.join(parts[:2]) + '.x.x' if len(parts) == 4 else 'x'


def program(agent):
    agent = agent or '-'
    for name in ('GO ERP Desktop', 'Electron', 'okhttp', 'Dart', 'Dalvik', 'CFNetwork', 'curl', 'python', 'Chrome', 'Safari', 'Firefox', 'bot'):
        if name.lower() in agent.lower():
            return name
    return agent[:30]


def moment(text):
    try:
        return datetime.datetime.strptime(text, '%d/%b/%Y:%H:%M:%S %z')
    except ValueError:
        return None


def read_requests(files, minutes=WINDOW_MINUTES):
    """The requests of the last `minutes` minutes (relative to the newest line), from the end of each log."""
    rows = []
    for name in files:
        try:
            size = os.path.getsize(name)
            with open(name, 'rb') as handle:
                handle.seek(max(0, size - TAIL_BYTES))
                data = handle.read().decode('utf-8', 'replace').splitlines()[1:]
        except OSError:
            continue
        for line in data:
            found = LINE.match(line)
            if not found:
                continue
            when = moment(found.group(2))
            if when:
                rows.append((when, found.group(1), found.group(3), path_shape(found.group(4)), found.group(5), program(found.group(6))))
    if not rows:
        return [], None
    newest = max(row[0] for row in rows)
    since = newest - datetime.timedelta(minutes=minutes)
    return [row for row in rows if row[0] > since], newest


def report_requests(rows, newest, minutes=WINDOW_MINUTES):
    lines = ['== web requests, the {} minutes up to {} =='.format(minutes, newest.strftime('%Y-%m-%d %H:%M:%S %z') if newest else '-')]
    if not rows:
        lines.append('no access log read')
        return lines
    lines.append('{} requests, {:.0f} a minute'.format(len(rows), len(rows) / minutes))
    paths = collections.Counter((row[2] + ' ' + row[3]) for row in rows)
    for shape, count in paths.most_common(25):
        statuses = collections.Counter(row[4] for row in rows if (row[2] + ' ' + row[3]) == shape)
        lines.append('{:>7}  {}  ({})'.format(count, shape, ', '.join('{} {}'.format(status, n) for status, n in statuses.most_common(3))))
    for shape, _count in paths.most_common(5):
        callers = collections.Counter((masked(row[1]), row[5]) for row in rows if (row[2] + ' ' + row[3]) == shape)
        lines.append('  callers of ' + shape + ': ' + '; '.join('{} {} ×{}'.format(address, agent, n) for (address, agent), n in callers.most_common(5)))
    return lines


def query_shape(sql):
    sql = re.sub(r"'(?:[^'\\]|\\.)*'", '?', sql)
    sql = re.sub(r'"(?:[^"\\]|\\.)*"', '?', sql)
    sql = re.sub(r'\b\d+(\.\d+)?\b', '?', sql)
    sql = re.sub(r'\?(\s*,\s*\?)+', '?+', sql)
    return re.sub(r'\s+', ' ', sql).strip()[:220]


def report_queries(samples=40, pause=0.25, run=mysql, sleep=time.sleep):
    seen = collections.Counter()
    taken = 0
    for _ in range(samples):
        rows = run("SELECT INFO FROM information_schema.PROCESSLIST WHERE COMMAND = 'Query' AND INFO IS NOT NULL AND ID != CONNECTION_ID()")
        if rows is None:
            return ['== database queries running now ==', 'unreadable']
        taken += 1
        for row in rows:
            seen[query_shape(row[0])] += 1
        sleep(pause)
    lines = ['== database queries running now ({} looks, {:.2f} s apart) =='.format(taken, pause)]
    if not seen:
        lines.append('none running when looked at')
    for shape, count in seen.most_common(15):
        lines.append('{:>5}  {}'.format(count, shape))
    return lines


GENERAL_LOG = Path('/var/lib/mysql/go-erp-general.log')
GENERAL_LINE = re.compile(r'^(?:\d{6}\s+\d{1,2}:\d{2}:\d{2}|\d{4}-\d{2}-\d{2}T\S+)?\s*(\d+)\s+(Connect|Query|Quit|Init DB|Prepare|Execute|Close stmt)\b\t?(.*)$')


def general_shapes(text, per_connection=None):
    """Statements of a general log by shape, and the connections opened. per_connection (a dict) receives each
    connection's statements by shape: one connection with thousands of them is a long-running job, not a page."""
    shapes = collections.Counter()
    connects = 0
    statements = 0
    for line in text.splitlines():
        found = GENERAL_LINE.match(line.strip('\r'))
        if not found:
            continue
        thread, kind, body = found.group(1), found.group(2), found.group(3)
        if kind == 'Connect':
            connects += 1
        elif kind in ('Query', 'Execute'):
            statements += 1
            shape = query_shape(body)
            shapes[shape] += 1
            if per_connection is not None:
                per_connection.setdefault(thread, collections.Counter())[shape] += 1
    return shapes, connects, statements


def report_general_log(seconds, run=mysql, sleep=time.sleep, log=GENERAL_LOG):
    seconds = max(1, min(30, int(seconds)))
    previous = run("SELECT @@GLOBAL.general_log, @@GLOBAL.general_log_file, @@GLOBAL.log_output")
    if not previous:
        return ['== every statement for {} seconds =='.format(seconds), 'unreadable']
    was_on, was_file, was_output = previous[0]
    if was_on not in ('0', 'OFF'):
        return ['== every statement for {} seconds =='.format(seconds), 'the general log is already on: left as it is']
    text = ''
    try:
        if log.exists():
            log.unlink()
        if run("SET GLOBAL log_output = 'FILE'; SET GLOBAL general_log_file = '{}'; SET GLOBAL general_log = 'ON'".format(log)) is None:
            return ['== every statement for {} seconds =='.format(seconds), 'could not turn it on']
        sleep(seconds)
    finally:
        restored = run("SET GLOBAL general_log = 'OFF'; SET GLOBAL general_log_file = '{}'; SET GLOBAL log_output = '{}'".format(
            was_file.replace("'", ''), was_output.replace("'", '')))
        try:
            text = log.read_text(errors='replace') if log.exists() else ''
        finally:
            if log.exists():
                log.unlink()
    per_connection = {}
    shapes, connects, statements = general_shapes(text, per_connection)
    lines = ['== every statement for {} seconds (general log; off again{}, its file removed) =='.format(seconds, '' if restored is not None else ' NOT CONFIRMED')]
    lines.append('{} statements ({:.0f} a second), {} connections opened ({:.1f} a second)'.format(statements, statements / seconds, connects, connects / seconds))
    schema = sum(count for shape, count in shapes.items() if 'information_schema' in shape.lower())
    lines.append('of them asking the database whether a table or column exists (information_schema): {} ({:.0f}%)'.format(
        schema, 100 * schema / statements if statements else 0))
    for shape, count in shapes.most_common(30):
        lines.append('{:>6}  {}'.format(count, shape))
    lines += busiest_connections(per_connection)
    return lines


def busiest_connections(per_connection, top=5):
    """The connections that ran the most statements, each with what it ran most."""
    ranked = sorted(per_connection.items(), key=lambda item: -sum(item[1].values()))[:top]
    if not ranked:
        return []
    lines = ['-- the busiest connections (a page opens its own; a long job keeps one) --']
    for thread, counter in ranked:
        lines.append('  connection {}: {} statements'.format(thread, sum(counter.values())))
        for shape, count in counter.most_common(3):
            lines.append('      {:>6}  {}'.format(count, shape[:150]))
    return lines


ARTISAN_WORD = re.compile(r'^(?:[a-z][a-z0-9_-]*(?::[a-z0-9_-]+)*|--[a-z][a-z0-9-]*)$')


def artisan_command(arguments):
    """The artisan command a PHP process runs, by its name and option names only (never a value)."""
    if 'artisan' not in [Path(argument).name for argument in arguments]:
        return None
    start = [Path(argument).name for argument in arguments].index('artisan') + 1
    rest = arguments[start:start + 6]
    # The command's own name, then option names only: a positional value is never printed.
    words = [rest[0]] if rest and ARTISAN_WORD.match(rest[0]) and not rest[0].startswith('--') else []
    for argument in rest[1:] if words else rest:
        word = argument.split('=', 1)[0]
        if word.startswith('--') and ARTISAN_WORD.match(word):
            words.append(word)
    return ' '.join(words) or '(artisan)'


def report_devices(run=mysql):
    lines = ['== GO ERP desktop devices ==']
    devices = run('SELECT id, name, IFNULL(last_seen_at, "never") FROM desktop_sync_devices WHERE revoked_at IS NULL ORDER BY id')
    if devices is None:
        return lines + ['unreadable']
    recent = run('SELECT device_id, COUNT(*) FROM desktop_sync_operations WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR GROUP BY device_id') or []
    sent = {row[0]: row[1] for row in recent}
    for device_id, name, seen in devices:
        lines.append('  #{} {} | last sync {} | operations in the last hour: {}'.format(device_id, name, seen, sent.get(device_id, '0')))
    return lines


def report_processes():
    try:
        result = subprocess.run(['ps', '-eo', 'pcpu,etimes,comm', '--sort=-pcpu'], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return ['== processes ==', 'unreadable']
    rows = result.stdout.decode('utf-8', 'replace').splitlines()[1:]
    by_name = collections.defaultdict(lambda: [0.0, 0])
    for row in rows:
        parts = row.split(None, 2)
        if len(parts) == 3:
            by_name[parts[2]][0] += float(parts[0])
            by_name[parts[2]][1] += 1
    lines = ['== processes using the processors (summed per program; each process: its share since it started) ==',
             'load: ' + ' '.join(Path('/proc/loadavg').read_text().split()[:3])]
    for name, (cpu, count) in sorted(by_name.items(), key=lambda item: -item[1][0])[:8]:
        lines.append('{:>6.1f}%  {} ×{}'.format(cpu, name, count))
    lines += php_commands()
    return lines


def php_commands(proc=Path('/proc')):
    """The command-line PHP processes running now: their artisan command, age and processor share."""
    found = []
    try:
        result = subprocess.run(['ps', '-eo', 'pid,pcpu,etimes,comm', '--sort=-pcpu'], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return []
    for row in result.stdout.decode('utf-8', 'replace').splitlines()[1:]:
        parts = row.split(None, 3)
        if len(parts) != 4 or parts[3] not in ('php', 'php-cli', 'php82'):
            continue
        try:
            arguments = (proc / parts[0] / 'cmdline').read_bytes().decode('utf-8', 'replace').split('\0')
        except OSError:
            continue
        command = artisan_command([argument for argument in arguments if argument])
        found.append('  php {}: {}% since it started {} s ago'.format(command or '(not artisan)', parts[1], parts[2]))
    return (['-- command-line PHP now --'] + found[:6]) if found else []


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--general-log', type=int, default=0)
    options = parser.parse_args()
    if not (os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST):
        print('GO_ERP_LOAD_DIAGNOSIS_ROOT_AND_HOST_REQUIRED', flush=True)
        raise SystemExit(1)
    try:
        os.nice(10)
    except OSError:
        pass
    files = {}
    for pattern in LOGS:
        for name in sorted(glob.glob(pattern.format(d=DOMAIN))):
            if not os.path.isfile(name) or name.endswith(('.gz', '.bz2', '-bytes_log')) or time.time() - os.path.getmtime(name) >= 3600:
                continue
            # The same log is reachable through several paths (links): read each file once.
            identity = os.stat(name)
            files.setdefault((identity.st_dev, identity.st_ino), name)
    files = sorted(files.values())
    rows, newest = read_requests(files)
    lines = ['GO_ERP_LOAD_DIAGNOSIS', 'access logs read: ' + (', '.join(files) or 'none found'), '']
    lines += report_requests(rows, newest) + [''] + report_queries() + [''] + report_devices() + [''] + report_processes()
    if options.general_log:
        lines += [''] + report_general_log(options.general_log)
    print('\n'.join(lines), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:  # noqa: BLE001
        print('GO_ERP_LOAD_DIAGNOSIS_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
