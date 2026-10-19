#!/usr/bin/env bash
# Read source identities and schema metadata into a private temporary directory.
# Never publish application files, change application ownership, migrate, or export business rows.
set -euo pipefail
umask 077

release=${1:?Pass the reviewed inspection release SHA}
[[ $# == 1 && $(id -u) -eq 0 && $(hostname -s) == srv1836524 && $release =~ ^[a-f0-9]{40}$ ]] || {
  echo 'DESKTOP_UPDATES_INSPECTION_INVALID_INVOCATION' >&2; exit 1;
}
project=/home/fasakha/public_html
php_binary=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -d "$project/.git" && ! -L "$project" && ! -L "$project/.git" && $(realpath "$project") == "$project" ]] || {
  echo 'DESKTOP_UPDATES_INSPECTION_PROJECT_UNSAFE' >&2; exit 1;
}
[[ -x "$php_binary" && -x /usr/bin/python3 && -x /usr/bin/timeout ]] || {
  echo 'DESKTOP_UPDATES_INSPECTION_RUNTIME_UNAVAILABLE' >&2; exit 1;
}
id fasakha >/dev/null
directory=$(mktemp -d /tmp/desktop-updates-inspection.XXXXXXXX)
trap 'rm -rf -- "$directory"' EXIT

declare -A pins=(
  [deployment/diagnose_desktop_dashboard_current_source.py]=e7a7a8271481013a1c16167d6f7a6ff429687ef5
  [deployment/desktop_dashboard_server_preflight.py]=4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4
  [tests/fixtures/desktop-actual-server-baseline.json]=cdb15f27e1365d188e908b983a8a78a27322505c
  [deployment/desktop_dashboard_server_manifest.json]=35827afdfa613ebd944847062c2cd5c9b3c96c56
  [deployment/desktop_dashboard_publication_metadata_scope.json]=cf11abae30d20db225fbb08be34bf86d9538b0ae
  [deployment/desktop_dashboard_server_metadata.php]=346b933e177506a66fb79baf4fa4e336ec805ac7
  [deployment/desktop_dashboard_inspect.php]=5cd78c2dcd70e1df5e7fa7f610eb3723f52ca1a6
)
for path in "${!pins[@]}"; do
  [[ ${pins[$path]} =~ ^[a-f0-9]{40}$ ]] || {
    echo 'DESKTOP_UPDATES_INSPECTION_PINS_UNSET' >&2; exit 1;
  }
  target="$directory/${path##*/}"
  runuser -u fasakha -- git --no-optional-locks -C "$project" show "$release:$path" > "$target"
  actual=$(/usr/bin/python3 -B -c 'import hashlib,pathlib,sys; data=pathlib.Path(sys.argv[1]).read_bytes(); print(hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest())' "$target")
  [[ $actual == "${pins[$path]}" ]] || {
    echo 'DESKTOP_UPDATES_INSPECTION_PIN_MISMATCH' >&2; exit 1;
  }
  chmod 0644 "$target"
done

# Keep the verified readers root-owned; only Laravel's temporary cache directory
# is writable by the application account. Production cache paths are untouched.
mkdir -m 0700 "$directory/schema"
mv -- "$directory/desktop_dashboard_server_metadata.php" "$directory/desktop_dashboard_inspect.php" "$directory/schema/"
chown fasakha:fasakha "$directory/schema"
chmod 0755 "$directory"

if ! timeout --signal=TERM --kill-after=5s 120s \
  runuser -u fasakha -- /usr/bin/python3 -B \
  "$directory/diagnose_desktop_dashboard_current_source.py" --release "$release" \
  > "$directory/source-report.txt" 2> "$directory/source-error.txt"; then
  echo 'DESKTOP_UPDATES_INSPECTION_SOURCE_FAILED_NO_APPLICATION_CHANGES' >&2
  exit 1
fi

if ! timeout --signal=TERM --kill-after=5s 120s \
  runuser -u fasakha -- "$php_binary" \
  "$directory/schema/desktop_dashboard_server_metadata.php" "$project" \
  > "$directory/schema-report.json" 2> "$directory/schema-error.txt"; then
  echo 'DESKTOP_UPDATES_INSPECTION_SCHEMA_FAILED_NO_APPLICATION_CHANGES' >&2
  exit 1
fi

# Schema bootstrap and concurrent dashboard deployments must not move the measured
# source between the source report and the schema read.
if ! timeout --signal=TERM --kill-after=5s 120s \
  runuser -u fasakha -- /usr/bin/python3 -B \
  "$directory/diagnose_desktop_dashboard_current_source.py" --release "$release" \
  > "$directory/source-report-after.txt" 2> "$directory/source-after-error.txt" \
  || ! cmp -s -- "$directory/source-report.txt" "$directory/source-report-after.txt"; then
  echo 'DESKTOP_UPDATES_INSPECTION_SOURCE_CHANGED_NO_APPLICATION_CHANGES' >&2
  exit 1
fi

# Validate both readers before emitting anything. Do not repeat PHP diagnostics,
# configuration, source contents, or exception messages on a failed inspection.
/usr/bin/python3 -B - "$directory" <<'PY'
import json
from pathlib import Path
import sys

def exact_keys(value, names):
    if not isinstance(value, dict) or set(value) != set(names):
        raise ValueError()

def strings(value):
    if not isinstance(value, list) or any(not isinstance(item, str) for item in value):
        raise ValueError()

try:
    folder = Path(sys.argv[1])
    files = [folder/'source-report.txt', folder/'schema-report.json']
    if any(path.stat().st_size > 16*1024*1024 for path in files):
        raise ValueError()
    lines = files[0].read_text().splitlines()
    if (len(lines) != 3 or lines[0] != 'DESKTOP_CURRENT_SOURCE_DIAGNOSIS_BEGIN'
            or lines[2] != 'DESKTOP_CURRENT_SOURCE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false'):
        raise ValueError()
    source = json.loads(lines[1])
    source_fields = {'kind', 'release', 'actual_head', 'expected_head', 'actual_source',
                     'expected_source', 'source_matches_before', 'source_matches_release',
                     'source_difference_count', 'source_differences', 'source_differences_truncated',
                     'gui', 'related_meta_gui', 'additional_dashboard_gui',
                     'publication_metadata_states', 'unreviewed_metadata', 'deployment_performed',
                     'metadata_changed', 'php_bootstrapped', 'database_read', 'source_content_printed'}
    if not isinstance(source, dict) or set(source) - source_fields:
        raise ValueError()
    if (source['kind'] != 'desktop-current-baseline-read-only-diagnosis'
            or source['source_differences_truncated'] is not False
            or len(source['source_differences']) != source['source_difference_count']
            or any(source[key] is not False for key in (
                'deployment_performed', 'metadata_changed', 'php_bootstrapped',
                'database_read', 'source_content_printed'))):
        raise ValueError()
    schema = json.loads(files[1].read_bytes())
    allowed = {'format', 'kind', 'generated_at', 'php_version', 'laravel_version',
               'database_engine', 'extensions', 'tables', 'routes',
               'desktop_migrations', 'desktop_dashboard_flags'}
    if (set(schema) != allowed or schema['format'] != 1 or schema['kind'] != 'schema-only'
            or not isinstance(schema['tables'], dict) or not isinstance(schema['routes'], list)):
        raise ValueError()
    exact_keys(schema['desktop_dashboard_flags'], {'enabled', 'local'})
    if any(type(value) is not bool for value in schema['desktop_dashboard_flags'].values()):
        raise ValueError()
    migrations = schema['desktop_migrations']
    exact_keys(migrations, {'table_exists', 'records'})
    if type(migrations['table_exists']) is not bool or not isinstance(migrations['records'], list):
        raise ValueError()
    for row in migrations['records']:
        exact_keys(row, {'migration', 'batch'})
        if not isinstance(row['migration'], str) or type(row['batch']) is not int:
            raise ValueError()
    strings(schema['extensions'])
    for table in schema['tables'].values():
        if (not isinstance(table, dict) or set(table) - {'columns', 'indexes', 'references'}
                or not isinstance(table.get('columns'), list)):
            raise ValueError()
        for column in table['columns']:
            exact_keys(column, {'name', 'type', 'nullable', 'extra'})
            if (type(column['nullable']) is not bool
                    or any(not isinstance(column[key], str) for key in ('name', 'type', 'extra'))):
                raise ValueError()
        if not isinstance(table.get('indexes', {}), dict):
            raise ValueError()
        for index in table.get('indexes', {}).values():
            exact_keys(index, {'unique', 'columns'})
            if type(index['unique']) is not bool:
                raise ValueError()
            strings(index['columns'])
        if not isinstance(table.get('references', []), list):
            raise ValueError()
        for reference in table.get('references', []):
            exact_keys(reference, {'column', 'table', 'target_column'})
            if any(not isinstance(value, str) for value in reference.values()):
                raise ValueError()
    for route in schema['routes']:
        exact_keys(route, {'name', 'methods', 'uri', 'action', 'middleware'})
        if (route['name'] is not None and not isinstance(route['name'], str)
                or not isinstance(route['uri'], str) or not isinstance(route['action'], str)):
            raise ValueError()
        strings(route['methods'])
        strings(route['middleware'])
    print('DESKTOP_DASHBOARD_UPDATES_INSPECTION_BEGIN')
    print(json.dumps({'source': source, 'schema': schema, 'deployment_performed': False,
                      'business_rows_exported': False, 'application_files_changed': False},
                     ensure_ascii=True, separators=(',', ':')))
    print('DESKTOP_DASHBOARD_UPDATES_INSPECTION_END; DEPLOYMENT_PERFORMED=false')
except BaseException:
    print('DESKTOP_UPDATES_INSPECTION_OUTPUT_REJECTED_NO_PRIVATE_OUTPUT', file=sys.stderr)
    sys.exit(1)
PY
