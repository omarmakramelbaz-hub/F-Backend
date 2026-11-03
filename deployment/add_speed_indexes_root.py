#!/usr/bin/env python3
"""Indexes for the slowest queries of srv1836524 (the analysis of its slow query log): the dashboard's unread
notifications, the customers page and its counts, the WhatsApp and Meta order scans, and the apps' account syncs.
Each of these read or sorted a whole table on every call; with its index it reads only the rows it returns.
An index changes no data and no answer: only how fast the database finds the rows.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/add_speed_indexes_root.py              what would change, nothing changes
  python3 -I -B deployment/add_speed_indexes_root.py --apply      adds the missing indexes, one at a time
  python3 -I -B deployment/add_speed_indexes_root.py --rollback   removes the indexes this script added

Each index is added online (the table stays readable and writable while it is built) and gives up after a few seconds
instead of waiting behind a long query. An index already there (by its name, or another index starting with the same
columns) is left as it is. The indexes this script added are listed in /var/lib/go-erp-server-indexes/added.json,
and only those are ever removed.
"""
import argparse
import datetime
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import time

HOST = 'srv1836524'
DATABASE = 'fasakha_db'
STATE = Path('/var/lib/go-erp-server-indexes')
NAME = re.compile(r'^[a-z][a-z0-9_]{0,63}$')
# (table, index, columns): the query each one serves is in the docstring above.
INDEXES = (
    ('notifications', 'go_notifications_unread', ('notifiable_type', 'notifiable_id', 'read_at', 'created_at')),
    ('crm_customers', 'go_crm_customers_name', ('name',)),
    ('crm_customers', 'go_crm_customers_service', ('service_consent', 'opted_out_at')),
    ('crm_customers', 'go_crm_customers_marketing', ('marketing_consent', 'opted_out_at')),
    ('whatsapp_inbox_messages', 'go_wa_messages_thread_time', ('conversation_id', 'created_at')),
    ('meta_inbox_messages', 'go_meta_messages_thread_time', ('conversation_id', 'created_at')),
    ('users', 'go_users_updated', ('updated_at', 'id')),
    ('user_address', 'go_user_address_updated', ('updated_at', 'id')),
)


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_SPEED_INDEXES_' + reason)


def mysql(statement, timeout=900):
    """Runs one statement as root (socket authentication), returns its rows; None when it failed."""
    try:
        result = subprocess.run(['mysql', '--batch', '--skip-column-names', '-e', statement, DATABASE], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if result.returncode != 0:
        return None
    return [line.split('\t') for line in result.stdout.decode('utf-8', 'replace').splitlines()]


def quoted(name):
    need(NAME.match(name) is not None, 'BAD_NAME')
    return '`' + name + '`'


def literal(value):
    need(NAME.match(value) is not None, 'BAD_NAME')
    return "'" + value + "'"


def state(table, run=mysql):
    """The table's rows (an estimate), columns and indexes (name -> columns in order); None when there is no table."""
    found = run('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' + literal(table))
    need(found is not None, 'DATABASE_UNREADABLE')
    if not found:
        return None
    columns = run('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' + literal(table))
    indexes = run('SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
                  + literal(table) + ' ORDER BY INDEX_NAME, SEQ_IN_INDEX')
    need(columns is not None and indexes is not None, 'DATABASE_UNREADABLE')
    named = {}
    for index, column in indexes:
        named.setdefault(index, []).append(column)
    return {'rows': int(found[0][0] or 0), 'columns': {row[0] for row in columns}, 'indexes': named}


def plan(run=mysql):
    """What each index needs: add, already there (and why), or skipped (no such table or column)."""
    steps = []
    for table, index, columns in INDEXES:
        current = state(table, run)
        if current is None:
            steps.append({'table': table, 'index': index, 'action': 'skip', 'why': 'no such table'})
            continue
        missing = [column for column in columns if column not in current['columns']]
        if missing:
            steps.append({'table': table, 'index': index, 'action': 'skip', 'why': 'no column ' + ', '.join(missing)})
            continue
        if index in current['indexes']:
            steps.append({'table': table, 'index': index, 'action': 'present', 'why': 'already added'})
            continue
        same = [name for name, cols in current['indexes'].items() if tuple(cols[:len(columns)]) == tuple(columns)]
        if same:
            steps.append({'table': table, 'index': index, 'action': 'present', 'why': 'covered by ' + same[0]})
            continue
        steps.append({'table': table, 'index': index, 'action': 'add', 'columns': list(columns), 'rows': current['rows']})
    return steps


def statement(table, index, columns):
    # Online: reads and writes go on while it is built. A lock it cannot get within 10 seconds stops it, not the site.
    return ('SET SESSION lock_wait_timeout = 10; ALTER TABLE ' + quoted(table) + ' ADD INDEX ' + quoted(index) + ' ('
            + ', '.join(quoted(column) for column in columns) + '), ALGORITHM=INPLACE, LOCK=NONE')


def record(folder):
    path = folder / 'added.json'
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError):
        return []


def keep(folder, added):
    folder.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(folder), 0o700)
    temporary = folder / 'added.json.tmp'
    temporary.write_text(json.dumps(added, ensure_ascii=False, indent=1))
    os.chmod(str(temporary), 0o600)
    os.replace(str(temporary), str(folder / 'added.json'))


def apply(run=mysql, folder=STATE, clock=time.monotonic):
    added = record(folder)
    results = []
    for step in plan(run):
        if step['action'] != 'add':
            results.append(step)
            continue
        started = clock()
        done = run(statement(step['table'], step['index'], step['columns']))
        seconds = round(clock() - started, 1)
        if done is None:
            results.append(dict(step, action='failed', seconds=seconds))
            continue
        added.append({'table': step['table'], 'index': step['index'], 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds')})
        keep(folder, added)
        results.append(dict(step, action='added', seconds=seconds))
    failed = [step['index'] for step in results if step['action'] == 'failed']
    return {'result': 'GO_ERP_SPEED_INDEXES_ADDED' if not failed else 'GO_ERP_SPEED_INDEXES_PARTLY_ADDED', 'steps': results, 'failed': failed}


def rollback(run=mysql, folder=STATE):
    added = record(folder)
    removed = []
    kept = []
    for item in reversed(added):
        current = state(item['table'], run)
        if current is None or item['index'] not in current['indexes']:
            removed.append(item['index'])
            continue
        done = run('SET SESSION lock_wait_timeout = 10; ALTER TABLE ' + quoted(item['table']) + ' DROP INDEX ' + quoted(item['index'])
                   + ', ALGORITHM=INPLACE, LOCK=NONE')
        if done is None:
            kept.append(item)
        else:
            removed.append(item['index'])
    keep(folder, [item for item in added if item in kept])
    return {'result': 'GO_ERP_SPEED_INDEXES_REMOVED' if not kept else 'GO_ERP_SPEED_INDEXES_PARTLY_REMOVED', 'removed': removed,
            'still_there': [item['index'] for item in kept]}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    if options.apply:
        result = apply()
    elif options.rollback:
        result = rollback()
    else:
        result = {'result': 'GO_ERP_SPEED_INDEXES_PLAN', 'steps': plan()}
    print(json.dumps(result, ensure_ascii=False, sort_keys=True), flush=True)
    if result['result'].endswith('PARTLY_ADDED') or result['result'].endswith('PARTLY_REMOVED'):
        raise SystemExit(1)


if __name__ == '__main__':
    try:
        main()
    except Stop as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except Exception as error:  # noqa: BLE001
        print('GO_ERP_SPEED_INDEXES_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
