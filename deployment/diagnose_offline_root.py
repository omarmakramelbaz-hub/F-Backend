#!/usr/bin/env python3
"""Why the GO ERP app misses data offline on srv1836524. It only reads.

  1. The devices set up with the app: their branches, who set them up, when they last synced (never a token).
  2. Per branch, the unpaid dine-in and delivery orders the sync sends to the devices (it sends at most 300 per branch,
     oldest first): how many there are, the oldest and the newest, and how many the limit leaves out.
  3. The errors of the site's logs from the last week (class, code, the site's own file and line; never the message).

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/diagnose_offline_root.py
"""
import importlib.util
import os
from pathlib import Path
import socket
import subprocess

HOST = 'srv1836524'
DATABASE = 'fasakha_db'
SNAPSHOT_LIMIT = 300


def mysql(query):
    result = subprocess.run(['mysql', '--batch', '--skip-column-names', '-e', query, DATABASE], stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=300, check=False)
    if result.returncode != 0:
        return None
    return [line.split('\t') for line in result.stdout.decode('utf-8', 'replace').splitlines()]


def analysis():
    spec = importlib.util.spec_from_file_location('analysis', str(Path(__file__).resolve().parent / 'analyze_live_server_root.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def report():
    lines = ['GO_ERP_OFFLINE_DIAGNOSIS', '', '== devices set up with the app ==']
    devices = mysql('SELECT name, branches, enrolled_by, IFNULL(last_seen_at, "never"), IF(revoked_at IS NULL, "active", "stopped"), created_at '
                    'FROM desktop_sync_devices ORDER BY created_at')
    if devices is None:
        lines.append('unreadable')
    for row in devices or []:
        lines.append('  {} | branches {} | set up by user {} | last sync {} | {} | since {}'.format(*row))
    lines.append('')
    lines.append('== unpaid orders the sync sends (per branch: channel count oldest newest last-2-days; at most {} are sent, oldest first) =='.format(SNAPSHOT_LIMIT))
    rows = mysql("SELECT branch, channel, COUNT(*), MIN(created_at), MAX(created_at), SUM(created_at >= UTC_TIMESTAMP() - INTERVAL 2 DAY) "
                 "FROM pos_service_tickets WHERE payment_status = 'unpaid' AND status != 'cancelled' GROUP BY branch, channel ORDER BY branch, channel")
    if rows is None:
        lines.append('unreadable')
    totals = {}
    for branch, channel, count, oldest, newest, recent in rows or []:
        totals[branch] = totals.get(branch, 0) + int(count)
        lines.append('  {} {} | {} unpaid | oldest {} | newest {} | {} in the last 2 days'.format(branch, channel, count, oldest, newest, recent))
    for branch, total in sorted(totals.items()):
        if total > SNAPSHOT_LIMIT:
            lines.append('  !! {}: {} unpaid orders, so the newest {} never reach the devices'.format(branch, total, total - SNAPSHOT_LIMIT))
    statuses = mysql("SELECT branch, channel, status, COUNT(*) FROM pos_service_tickets WHERE payment_status = 'unpaid' AND status != 'cancelled' "
                     "AND created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY GROUP BY branch, channel, status ORDER BY branch, channel, status")
    if statuses:
        lines.append('  older than 2 days and still unpaid, by status:')
        for branch, channel, status, count in statuses:
            lines.append('    {} {} {}: {}'.format(branch, channel, status, count))
    lines.append('')
    found = analysis().site_errors(Path('/home/fasakha/public_html/storage/logs'))
    lines.append('== errors in the site\'s logs since {} (class, code, where; never the message) =='.format(found['since']))
    lines.append('total {} in {} kinds; per day: {}'.format(found['total'], found['kinds'], ', '.join('{} {}'.format(day[5:], count) for day, count in found['per_day']) or 'none'))
    for row in found['top']:
        code = '({})'.format(row['code']) if row['code'] else ''
        lines.append('{:>6}x  {}{} at {}  (last {})'.format(row['count'], row['class'], code, row['where'], row['last']))
    return '\n'.join(lines)


def main():
    if not (os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST):
        print('GO_ERP_OFFLINE_DIAGNOSIS_ROOT_AND_HOST_REQUIRED', flush=True)
        raise SystemExit(1)
    print(report(), flush=True)


if __name__ == '__main__':
    main()
