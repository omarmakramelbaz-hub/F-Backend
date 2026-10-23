#!/usr/bin/env python3
"""Read-only import checks and a private, application-independent FPM INI query.

The FPM result describes the matched pool with a private script. It deliberately
does not claim to measure the application's front controller or HTTP bootstrap.
No PHP_VALUE/PHP_ADMIN_VALUE, public PHP endpoint, reload or export is used.
"""
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import socket
import stat
import struct
import subprocess
import sys
import tempfile
import time

APP = Path('/home/fasakha/public_html')
POOL_DIRECTORY = Path('/opt/cpanel/ea-php82/root/etc/php-fpm.d')
SOCKET_DIRECTORY = Path('/opt/cpanel/ea-php82/root/usr/var/run/php-fpm')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
MAX_FCGI_BYTES = 16384


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def read_regular(path, maximum=65536, root_owned=False):
    descriptor = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as source:
        before = os.fstat(source.fileno())
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
             and 0 < before.st_size <= maximum, 'PROBE_FILE_UNSAFE')
        if root_owned:
            need(before.st_uid == 0 and not before.st_mode & 0o022, 'PROBE_FILE_OWNER_UNSAFE')
        data = source.read(maximum + 1)
        after = os.fstat(source.fileno())
    key = lambda value: (value.st_dev, value.st_ino, value.st_size,
                         value.st_mtime_ns, value.st_ctime_ns)
    need(len(data) == before.st_size and key(before) == key(after) == key(path.lstat()),
         'PROBE_FILE_CHANGED')
    return data


def parse_pool(text):
    sections = []
    current = None
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith((';', '#')):
            continue
        if re.fullmatch(r'\[[A-Za-z0-9_.-]+\]', line):
            current = {}
            sections.append(current)
        elif current is not None and '=' in line:
            key, value = (part.strip() for part in line.split('=', 1))
            if key in ('user', 'listen'):
                need(key not in current, 'FPM_POOL_DUPLICATE_SETTING')
                current[key] = value.strip('"\'')
    return [section for section in sections if section.get('user') == 'fasakha']


def find_pool():
    need(POOL_DIRECTORY.resolve() == POOL_DIRECTORY, 'FPM_POOL_DIRECTORY_UNSAFE')
    matches = []
    for path in sorted(POOL_DIRECTORY.glob('*.conf')):
        matches.extend(parse_pool(read_regular(path, root_owned=True).decode('utf-8')))
    need(len(matches) == 1, 'FPM_POOL_MISSING_OR_AMBIGUOUS')
    path = Path(matches[0].get('listen', ''))
    need(path.parent == SOCKET_DIRECTORY and re.fullmatch(r'[A-Za-z0-9_.-]+\.sock', path.name),
         'FPM_POOL_SOCKET_UNREVIEWED')
    need(path.parent.resolve() == path.parent, 'FPM_SOCKET_DIRECTORY_UNSAFE')
    need(stat.S_ISSOCK(path.lstat().st_mode), 'FPM_SOCKET_UNAVAILABLE')
    return path


def record(kind, content=b''):
    need(len(content) <= 65535, 'FCGI_REQUEST_TOO_LARGE')
    return struct.pack('!BBHHBB', 1, kind, 1, len(content), 0, 0) + content


def name_value(name, value):
    name, value = name.encode('ascii'), value.encode('utf-8')
    need(len(name) < 128 and len(value) < 16384, 'FCGI_PARAMETER_TOO_LARGE')
    length = lambda size: bytes([size]) if size < 128 else struct.pack('!I', size | 0x80000000)
    return length(len(name)) + length(len(value)) + name + value


def fastcgi(sock, parameters, timeout=10):
    deadline = time.monotonic() + timeout
    def exact(size):
        result = bytearray()
        while len(result) < size:
            remaining = deadline - time.monotonic()
            need(remaining > 0, 'FCGI_DEADLINE')
            sock.settimeout(remaining)
            chunk = sock.recv(size - len(result))
            need(chunk, 'FCGI_TRUNCATED_RESPONSE')
            result.extend(chunk)
        return bytes(result)
    payload = b''.join(name_value(key, value) for key, value in parameters.items())
    sock.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, payload) + record(4) + record(5))
    stdout = bytearray()
    total = 0
    for _ in range(256):
        version, kind, request, size, padding, reserved = struct.unpack('!BBHHBB', exact(8))
        need(version == 1 and request == 1 and reserved == 0, 'FCGI_RESPONSE_HEADER_INVALID')
        total += size
        need(total <= MAX_FCGI_BYTES, 'FCGI_RESPONSE_LIMIT')
        content = exact(size)
        if padding:
            exact(padding)
        if kind == 6:
            stdout.extend(content)
        elif kind == 7:
            need(not content, 'FCGI_PRIVATE_SCRIPT_FAILED')
        elif kind == 3:
            need(size == 8 and struct.unpack('!IB3x', content) == (0, 0), 'FCGI_REQUEST_FAILED')
            return bytes(stdout)
        else:
            raise Stop('FCGI_RESPONSE_KIND_INVALID')
    raise Stop('FCGI_RESPONSE_RECORD_LIMIT')


def fpm_settings(path, socket_path, expected_uid):
    nonce = os.urandom(32).hex()
    parameters = {
        'SCRIPT_FILENAME': str(path), 'SCRIPT_NAME': '/go-erp-private-ini.php',
        'DOCUMENT_ROOT': str(path.parent), 'REQUEST_METHOD': 'GET', 'QUERY_STRING': '',
        'REQUEST_URI': '/go-erp-private-ini.php', 'SERVER_PROTOCOL': 'HTTP/1.1',
        'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_NAME': 'localhost',
        'SERVER_ADDR': '127.0.0.1', 'REMOTE_ADDR': '127.0.0.1', 'SERVER_PORT': '80',
        'CONTENT_LENGTH': '0', 'REDIRECT_STATUS': '200', 'GO_ERP_PROBE_NONCE': nonce,
    }
    need(not any(name in parameters for name in ('PHP_VALUE', 'PHP_ADMIN_VALUE')), 'FCGI_INI_OVERRIDE_FORBIDDEN')
    with socket.socket(socket.AF_UNIX) as client:
        client.settimeout(10)
        client.connect(str(socket_path))
        wire = fastcgi(client, parameters)
    parts = wire.split(b'\r\n\r\n', 1)
    need(len(parts) == 2 and b'content-type: application/json' in parts[0].lower(), 'FPM_RESPONSE_INVALID')
    value = json.loads(parts[1])
    need(set(value) == {'kind', 'nonce', 'sapi', 'php_version', 'effective_uid',
                        'precision', 'serialize_precision', 'user_ini_filename'}
         and value['kind'] == 'go-erp-private-fpm-ini' and value['nonce'] == nonce
         and value['sapi'] == 'fpm-fcgi' and value['effective_uid'] == expected_uid,
         'FPM_IDENTITY_UNVERIFIED')
    need(re.fullmatch(r'8\.2\.[0-9]+', value['php_version']) is not None
         and all(isinstance(value[key], str) and re.fullmatch(r'-?[0-9]{1,3}', value[key])
                 for key in ('precision', 'serialize_precision')), 'FPM_SETTINGS_INVALID')
    need(-1 <= int(value['serialize_precision']) <= 30, 'FPM_PRECISION_UNREVIEWED')
    filename = value.pop('user_ini_filename')
    need(filename == '' or re.fullmatch(r'[A-Za-z0-9_.-]{1,120}', filename), 'FPM_USER_INI_NAME_UNREVIEWED')
    overrides = []
    if filename:
        for label, directory in [('application-root', APP), ('public', APP / 'public')]:
            candidate = directory / filename
            if candidate.exists() or candidate.is_symlink():
                content = read_regular(candidate).decode('utf-8')
                settings = re.findall(r'^\s*(?:serialize_precision|precision)\s*=\s*(-?[0-9]+)\s*(?:;.*)?$', content, re.M)
                overrides.append({'directory': label, 'precision_assignment_present': bool(settings)})
    for key in ('kind', 'nonce', 'effective_uid'):
        value.pop(key)
    return {**value, 'scope': 'matched-fpm-pool-private-script',
            'application_entrypoint_verified': False, 'application_user_ini_files': overrides}


def cli_probe(path, settings):
    command = ['/usr/sbin/runuser', '-u', 'fasakha', '--', PHP,
               '-d', 'memory_limit=512M', '-d', 'display_errors=0', '-d', 'log_errors=0', str(path)]
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL, start_new_session=True)
    try:
        output, _ = process.communicate(json.dumps({'fpm_serialize_precision': settings['serialize_precision']}).encode(), timeout=150)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        raise Stop('CLI_PROBE_DEADLINE') from None
    need(process.returncode == 0 and 0 < len(output) <= 256 * 1024, 'CLI_PROBE_FAILED')
    return validate_cli_report(json.loads(output), int(settings['serialize_precision']))


def validate_cli_report(value, fpm_precision):
    """Construct a report from allowed fields; never forward arbitrary child JSON."""
    keys = {'result', 'server_cli', 'matched_devices', 'base_branch_count', 'actor_rows_in_users_query',
            'actor_row_check_passes', 'tables_checked', 'ddl_guard_rejected_tables',
            'schema_hash_precision_invariant', 'numeric_tables', 'source_precision_variants',
            'target_precision_variants', 'projection_scope', 'catalog_grant_hashes_checked',
            'catalog_reason', 'actual_snapshot_checked', 'desktop_actual_settings_checked',
            'guarded_read_queries', 'credentials_disclosed', 'schema_or_business_writes'}
    need(isinstance(value, dict) and set(value) == keys, 'CLI_REPORT_INVALID')
    def number(item, limit=500000):
        need(type(item) is int and 0 <= item <= limit, 'CLI_REPORT_COUNT_INVALID')
        return item
    def flag(item):
        need(type(item) is bool, 'CLI_REPORT_FLAG_INVALID')
        return item
    def identifier(item):
        need(isinstance(item, str) and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', item), 'CLI_REPORT_NAME_INVALID')
        return item
    need(value['result'] == 'GO_ERP_IMPORT422_READ_ONLY_CHECK'
         and value['matched_devices'] == 1
         and all(value[key] is False for key in ('schema_or_business_writes', 'actual_snapshot_checked',
             'desktop_actual_settings_checked', 'credentials_disclosed', 'catalog_grant_hashes_checked')),
         'CLI_REPORT_INVALID')
    cli = value['server_cli']
    need(isinstance(cli, dict) and set(cli) == {'sapi', 'php_version', 'precision', 'serialize_precision'}
         and cli['sapi'] == 'cli' and isinstance(cli['php_version'], str)
         and re.fullmatch(r'8\.2\.[0-9]+', cli['php_version'])
         and all(isinstance(cli[key], str) and re.fullmatch(r'-?[0-9]{1,3}', cli[key])
                 for key in ('precision', 'serialize_precision')), 'CLI_SETTINGS_INVALID')
    modes = list(dict.fromkeys([fpm_precision, -1, 17]))
    need(value['source_precision_variants'] == modes and value['target_precision_variants'] == [-1, 17],
         'CLI_REPORT_VARIANTS_INVALID')
    projection_scope = 'base account queries; selected numeric/JSON columns; before redaction and FK closure'
    catalog_reason = 'grant export requires locks; actual wire snapshot is not available'
    need(value['projection_scope'] == projection_scope and value['catalog_reason'] == catalog_reason,
         'CLI_REPORT_SCOPE_INVALID')
    rejected = value['ddl_guard_rejected_tables']
    need(isinstance(rejected, list) and len(rejected) <= 200, 'CLI_REPORT_DDL_INVALID')
    rejected = [identifier(item) for item in rejected]
    raw_tables = value['numeric_tables']
    need(isinstance(raw_tables, dict) and len(raw_tables) <= 200, 'CLI_REPORT_TABLES_INVALID')
    tables = {}
    for table, part in raw_tables.items():
        identifier(table)
        need(isinstance(part, dict) and set(part) == {'rows_checked', 'columns', 'numeric_projection_mismatch_rows'},
             'CLI_REPORT_TABLE_INVALID')
        rows = number(part['rows_checked'])
        columns = part['columns']
        need(isinstance(columns, dict) and len(columns) <= 512, 'CLI_REPORT_COLUMNS_INVALID')
        clean_columns = {}
        for name, column in columns.items():
            identifier(name)
            need(isinstance(column, dict) and set(column) == {'sql_type', 'php_types', 'is_numeric_projection', 'json_float_values'}
                 and column['sql_type'] in ('float', 'double', 'real', 'decimal', 'numeric', 'json', 'text', 'longtext',
                    'mediumtext', 'tinytext', 'varchar', 'char')
                 and isinstance(column['php_types'], dict)
                 and set(column['php_types']) <= {'null', 'bool', 'int', 'float', 'string'}, 'CLI_REPORT_COLUMN_INVALID')
            clean_columns[name] = {'sql_type': column['sql_type'],
                'php_types': {kind: number(count, rows) for kind, count in column['php_types'].items()},
                'is_numeric_projection': flag(column['is_numeric_projection']),
                'json_float_values': number(column['json_float_values'], 100000000)}
        mismatches = part['numeric_projection_mismatch_rows']
        need(isinstance(mismatches, dict) and set(mismatches) == {str(a)+'=>'+str(b) for a in modes for b in (-1, 17)},
             'CLI_REPORT_MATRIX_INVALID')
        tables[table] = {'rows_checked': rows, 'columns': clean_columns,
            'numeric_projection_mismatch_rows': {key: number(count, rows) for key, count in mismatches.items()}}
    return {'result': 'GO_ERP_IMPORT422_READ_ONLY_CHECK', 'server_cli': dict(cli),
        'matched_devices': 1, 'base_branch_count': number(value['base_branch_count'], 20000),
        'actor_rows_in_users_query': number(value['actor_rows_in_users_query']),
        'actor_row_check_passes': flag(value['actor_row_check_passes']),
        'tables_checked': number(value['tables_checked'], 200), 'ddl_guard_rejected_tables': rejected,
        'schema_hash_precision_invariant': flag(value['schema_hash_precision_invariant']),
        'numeric_tables': tables, 'source_precision_variants': modes, 'target_precision_variants': [-1, 17],
        'projection_scope': projection_scope, 'catalog_grant_hashes_checked': False, 'catalog_reason': catalog_reason,
        'actual_snapshot_checked': False, 'desktop_actual_settings_checked': False,
        'guarded_read_queries': number(value['guarded_read_queries'], 20000),
        'credentials_disclosed': False, 'schema_or_business_writes': False}


def main():
    need(len(sys.argv) == 1 and os.geteuid() == 0
         and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    need(APP.resolve() == APP and APP.is_dir(), 'APPLICATION_DIRECTORY_UNSAFE')
    account = pwd.getpwnam('fasakha')
    package = Path(__file__).resolve().parent
    source = {name: read_regular(package / name, 128 * 1024, root_owned=True)
              for name in ('go_erp_import422_web_ini.php', 'diagnose_go_erp_import422.php')}
    private = Path(tempfile.mkdtemp(prefix='go-erp-private-import422-', dir='/home/fasakha'))
    os.chown(private, 0, account.pw_gid)
    os.chmod(private, 0o710)
    try:
        paths = {}
        for name, data in source.items():
            path = private / name
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
            with os.fdopen(descriptor, 'wb') as output:
                output.write(data)
            os.chown(path, 0, account.pw_gid)
            paths[name] = path
        settings = fpm_settings(paths['go_erp_import422_web_ini.php'], find_pool(), account.pw_uid)
        report = cli_probe(paths['diagnose_go_erp_import422.php'], settings)
        for name, data in source.items():
            need(read_regular(paths[name], 128 * 1024, root_owned=True) == data, 'PRIVATE_PROBE_CHANGED')
        print(json.dumps({'result': 'GO_ERP_IMPORT422_COMBINED_CHECK', 'server_fpm': settings,
                          'server_cli_checks': report, 'application_entrypoint_verified': False,
                          'desktop_actual_settings_checked': False, 'public_endpoint_created': False,
                          'runtime_configuration_changed': False, 'schema_or_business_writes': False}, sort_keys=True))
    finally:
        shutil.rmtree(private)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'result': 'GO_ERP_IMPORT422_PROBE_STOPPED',
                          'reason': str(error) if isinstance(error, Stop) else type(error).__name__,
                          'schema_or_business_writes': False, 'public_endpoint_created': False}))
        sys.exit(1)
