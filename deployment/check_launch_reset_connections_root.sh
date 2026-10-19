#!/usr/bin/env bash
# Read-only application process and database connection diagnosis. No downtime.
set -euo pipefail
umask 077
launch_revision=${1:?Full pinned revision required}
[[ $# = 1 && "$launch_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) = 0 && $(hostname -s) = srv1836524 ]]
launch_root=/home/fasakha/public_html
launch_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$launch_php" && -d "$launch_root" && ! -L "$launch_root" && $(realpath -- "$launch_root") = "$launch_root" ]]
[[ $(stat -c '%u' -- "$launch_root") = "$(id -u fasakha)" ]]
launch_tmp=$(mktemp -d /tmp/launch-connection-check.XXXXXXXX)
trap 'rm -rf -- "$launch_tmp"' EXIT
chmod 0755 "$launch_tmp"
launch_source=deployment/launch_reset/check_connections.php
launch_blob=$(runuser -u fasakha -- git -C "$launch_root" rev-parse "$launch_revision:$launch_source")
[[ "$launch_blob" =~ ^[0-9a-f]{40}$ ]]
runuser -u fasakha -- git -C "$launch_root" show "$launch_revision:$launch_source" > "$launch_tmp/check.php"
[[ $(git hash-object --no-filters "$launch_tmp/check.php") = "$launch_blob" ]]
chmod 0644 "$launch_tmp/check.php"
printf '%s\n' 'LAUNCH_CONNECTION_DIAGNOSTIC_READ_ONLY'
python3 - <<'PY'
import json
import os
from pathlib import Path
import pwd
import re
import subprocess

root = '/home/fasakha/public_html'
uid = pwd.getpwnam('fasakha').pw_uid
try:
    index = (Path(root) / 'public/index.php').read_bytes()
    maintenance_marker = b"require __DIR__.'/../storage/framework/maintenance.php'"
    autoload_marker = b"require __DIR__.'/../vendor/autoload.php'"
    prerender_supported = maintenance_marker in index and autoload_marker in index and index.index(maintenance_marker) < index.index(autoload_marker)
except OSError:
    prerender_supported = None
print('APPLICATION_ENTRY_STATE=' + json.dumps({'maintenance_marker_exists': (Path(root) / 'storage/framework/down').is_file(),
    'maintenance_prerender_exists': (Path(root) / 'storage/framework/maintenance.php').is_file(),
    'maintenance_prerender_before_bootstrap': prerender_supported,
    'configuration_cache_exists': (Path(root) / 'bootstrap/cache/config.php').is_file()}, separators=(',', ':')))
tasks = {'whatsapp:process-orders', 'meta:process-messages', 'meta:process-ai',
         'crm:customers-sync', 'crm:whatsapp-process', 'queue:work', 'queue:listen',
         'horizon', 'horizon:work', 'schedule:run', 'schedule:work', 'schedule:finish',
         'order-board:advance', 'dashboard-push:dispatch', 'employees:auto-absence',
         'employee:auto-absence', 'go-services:dispatch'}
processes = []
for entry in Path('/proc').iterdir():
    if not entry.name.isdecimal():
        continue
    try:
        if entry.stat().st_uid != uid:
            continue
        executable = Path(os.readlink(entry / 'exe')).name
        if executable.startswith('php-fpm'):
            role = 'php_fpm'
        elif re.fullmatch(r'(?:php(?:[0-9.]+)?|lsphp|php-cgi)', executable):
            role = 'php'
        else:
            continue
        cwd = os.readlink(entry / 'cwd')
        cwd_class = 'application' if cwd == root else ('application_subdirectory' if cwd.startswith(root + '/') else 'other')
        with (entry / 'cmdline').open('rb') as stream:
            raw = stream.read(65537)
        if len(raw) > 65536:
            arguments = []
        else:
            arguments = [part.decode('utf-8', errors='replace') for part in raw.rstrip(b'\0').split(b'\0')]
        task = None
        for index, argument in enumerate(arguments[:-1]):
            if argument == root + '/artisan' or (argument in {'artisan', './artisan'} and cwd == root):
                role = 'artisan'
                task = arguments[index + 1] if arguments[index + 1] in tasks else 'other'
                break
        item = {'pid': int(entry.name), 'role': role, 'cwd_class': cwd_class}
        if task is not None:
            item['task'] = task
        processes.append(item)
    except (OSError, PermissionError):
        continue
print('APPLICATION_PHP_PROCESSES=' + json.dumps(sorted(processes, key=lambda item: item['pid']), separators=(',', ':')))
units = []
allowed_states = {
    'LoadState': {'loaded', 'not-found', 'masked', 'error', 'bad-setting', 'merged', 'stub'},
    'ActiveState': {'active', 'reloading', 'inactive', 'failed', 'activating', 'deactivating', 'maintenance', 'refreshing'},
    'SubState': {'running', 'dead', 'exited', 'waiting', 'elapsed', 'start-pre', 'start', 'start-post', 'reload',
                 'reload-signal', 'reload-notify', 'stop', 'stop-watchdog', 'stop-sigterm', 'stop-sigkill', 'stop-post',
                 'final-watchdog', 'final-sigterm', 'final-sigkill', 'failed', 'auto-restart', 'auto-restart-queued',
                 'cleaning', 'condition', 'listening'},
}
for family in ('whatsapp-orders', 'meta-messaging', 'meta-ai', 'customer-crm'):
    for kind in ('timer', 'service'):
        unit = 'fasakhansta-' + family + '.' + kind
        try:
            result = subprocess.run(['systemctl', 'show', unit, '--property=LoadState,ActiveState,SubState'], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=3, check=False)
            values = {}
            for line in result.stdout.decode('ascii', errors='replace').splitlines():
                key, separator, value = line.partition('=')
                if key in {'LoadState', 'ActiveState', 'SubState'} and separator:
                    values[key] = value if value in allowed_states[key] else 'unrecognized'
            units.append({'unit': unit, 'states': values, 'query_ok': result.returncode == 0})
        except (OSError, subprocess.TimeoutExpired):
            units.append({'unit': unit, 'states': {}, 'query_ok': False})
print('APPLICATION_KNOWN_WORKER_UNITS=' + json.dumps(units, separators=(',', ':')))
PY
runuser -u fasakha -- "$launch_php" -d display_errors=0 -d log_errors=0 "$launch_tmp/check.php" "$launch_root"
printf '%s\n' 'LAUNCH_CONNECTION_DIAGNOSTIC_COMPLETE'
