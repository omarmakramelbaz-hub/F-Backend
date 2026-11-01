#!/usr/bin/env python3
"""Root-owned control-plane worker. HTTP input is data, never executable code or a path."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import socket
import subprocess
import sys
import time
import uuid
from urllib.parse import quote

BASE = Path('/var/lib/go-owner-dashboards')
TENANTS = Path('/home/fasakha/go-owner-dashboards')
MAIN = Path('/home/fasakha/public_html')
PUBLIC = MAIN / 'public' / 'dashboards'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
LIB = Path('/usr/local/lib/go-owner-dashboards')
SAFE_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'LANG': 'C.UTF-8'}
ASSETS = ['dashboard', 'css', 'js', 'vendor', 'site', 'storefront', 'sounds']
APP_LINKS = ['app', 'config', 'resources', 'routes', 'database', 'vendor', 'composer.json', 'composer.lock']
STATES = {'active', 'suspended', 'deleted'}


class Failure(RuntimeError):
    pass


class PendingHost(Failure):
    pass


class CpanelFailure(Failure):
    def __init__(self, diagnostics):
        super().__init__('GO_OWNER_CPANEL_SUBDOMAIN_FAILED')
        self.cpanel_diagnostics = diagnostics


def api_success(value):
    # CLI/API releases differ in whether their Boolean status is a JSON number or string.
    return value is True or type(value) is int and value == 1 or type(value) is str and value == '1'


def cpanel_document(output):
    """Accept one JSON response, including a CLI warning before it; never infer success from text."""
    if not isinstance(output, str):
        raise ValueError('Missing cPanel output')
    text = output.lstrip('\ufeff \t\r\n')
    try:
        value = json.loads(text)
        if isinstance(value, dict):
            return value
    except (ValueError, TypeError):
        pass
    # Some installations print a startup warning before the JSON document.
    # Inspect only complete documents beginning on a line and require a recognized API envelope.
    decoder = json.JSONDecoder()
    for match in re.finditer(r'(?m)^\s*\{', text):
        try:
            value, length = decoder.raw_decode(text[match.end()-1:])
        except ValueError:
            continue
        if isinstance(value, dict) and ('cpanelresult' in value or 'event' in value and 'data' in value):
            if text[match.end()-1+length:].strip():
                raise ValueError('Multiple or trailing CLI responses')
            return value
    raise ValueError('Unrecognized cPanel JSON')


def cpanel_result(document, function):
    """Normalize documented API2 envelopes, validating every available success indicator."""
    result = document
    envelopes = [result]
    if 'metadata' in result and 'data' in result and 'cpanelresult' not in result:
        if not isinstance(result['metadata'], dict) or not api_success(result['metadata'].get('result')):
            raise ValueError('WHM rejected the call')
        envelopes.append(result['metadata'])
        result = result['data']
        envelopes.append(result)
    if isinstance(result, dict) and 'cpanelresult' in result:
        result = result['cpanelresult']
        envelopes.append(result)
    if not isinstance(result, dict):
        raise ValueError('Missing API result')
    if isinstance(result.get('result'), dict) and 'event' not in result and 'data' not in result:
        result = result['result']
        envelopes.append(result)
    for envelope in envelopes:
        if not isinstance(envelope, dict):
            raise ValueError('Missing API envelope')
        if envelope.get('module', 'SubDomain') != 'SubDomain' or envelope.get('func', function) != function:
            raise ValueError('Unexpected cPanel operation')
        if envelope.get('error') or envelope.get('errors'):
            raise ValueError('API reported errors')
        for key in ['status', 'result']:
            if key in envelope and not isinstance(envelope[key], dict) and not api_success(envelope[key]):
                raise ValueError('API reported failure')
        if 'event' in envelope and (not isinstance(envelope['event'], dict)
                                   or not api_success(envelope['event'].get('result'))
                                   or envelope['event'].get('error') or envelope['event'].get('errors')):
            raise ValueError('API event reported failure')
    if isinstance(result.get('event'), dict):
        success = api_success(result['event'].get('result'))
    elif 'status' in result:
        success = api_success(result['status'])
    else:
        success = api_success(result.get('result'))
    if not success:
        raise ValueError('API rejected the call')
    if 'data' not in result:
        raise ValueError('Missing result data')
    data = result['data']
    if data is None and function == 'listsubdomains':
        data = []
    if not isinstance(data, list) or any(not isinstance(row, dict) for row in data):
        raise ValueError('Missing result data')
    if any(any(key in row and not api_success(row[key]) for key in ['result', 'status'])
           or row.get('error') or row.get('errors') for row in data):
        raise ValueError('API data reported failure')
    if function != 'listsubdomains' and (not data or any(not api_success(row.get('result')) for row in data)):
        raise ValueError('Mutation not acknowledged')
    return data


def cpanel_diagnostics(done, document, function):
    """Only status and bounded error fields leave the CLI; account data and credentials never do."""
    result = document if isinstance(document, dict) else {}
    containers = [result]
    if isinstance(result.get('metadata'), dict):
        containers.append(result['metadata'])
    if isinstance(result.get('data'), dict) and isinstance(result.get('metadata'), dict):
        result = result['data']
        containers.append(result)
    if isinstance(result.get('cpanelresult'), dict):
        result = result['cpanelresult']
        containers.append(result)
    if isinstance(result.get('result'), dict) and 'event' not in result and 'data' not in result:
        result = result['result']
        containers.append(result)
    reasons = []
    for container in containers + [result.get('event')]:
        if not isinstance(container, dict):
            continue
        for key in ['error', 'errors', 'reason']:
            value = container.get(key)
            if isinstance(value, str): reasons.append(value)
            elif isinstance(value, list): reasons.extend(v for v in value if isinstance(v, str))
    if isinstance(result.get('data'), list):
        for row in result['data']:
            if isinstance(row, dict) and (row.get('error') or row.get('errors') or 'result' in row and not api_success(row['result'])):
                for key in ['reason', 'error', 'errors']:
                    value = row.get(key)
                    if isinstance(value, str): reasons.append(value)
                    elif isinstance(value, list): reasons.extend(v for v in value if isinstance(v, str))
    redacted = []
    for reason in reasons[:3]:
        reason = re.sub(r'(?i)(password|passwd|token|secret|api[_-]?key|authorization|cookie)\s*[:=]\s*(?:"[^"]*"|\x27[^\x27]*\x27|\S+)', r'\1=[redacted]', reason)
        reason = re.sub(r'(?i)\b(?:basic|bearer)\s+[A-Za-z0-9+/=._-]+', '[redacted]', reason)
        reason = re.sub(r'(?i)cpsess[a-z0-9]+', '[session redacted]', reason)
        reason = re.sub(r'([a-zA-Z][a-zA-Z0-9+.-]*://)[^\s/@]+:[^\s/@]+@', r'\1[redacted]@', reason)
        reason = re.sub(r'\b[A-Za-z0-9_+/=.-]{40,}\b', '[long value redacted]', reason)
        reason = re.sub(r'[\x00-\x1f\x7f]', ' ', reason)[:600]
        if reason and reason not in redacted: redacted.append(reason)
    combined = '\n'.join(v for v in [done.stdout, done.stderr] if isinstance(v, str)).lower()
    category = next((name for name, words in [
        ('license', ['license expired', 'license is not valid', 'licence', 'license file', 'license is invalid']),
        ('permission', ['permission denied', 'access denied', 'not authorized', 'not permitted']),
        ('account', ['user does not exist', 'unknown user', 'invalid user', 'account is suspended']),
        ('module', ['unable to find module', 'could not find', 'unknown function', 'not implemented']),
        ('cli_arguments', ['unknown option', 'unrecognized option', 'invalid option']),
    ] if any(word in combined for word in words)), 'api_rejected' if document else 'invalid_json')
    event = result.get('event') if isinstance(result.get('event'), dict) else {}
    indicator = event.get('result', result.get('status', result.get('result')))
    if not redacted:
        redacted = [{'license':'cPanel reported a license problem.', 'permission':'cPanel denied the request.',
                     'account':'cPanel could not load the requested account.', 'module':'cPanel could not load its API module or function.',
                     'cli_arguments':'cPanel CLI rejected its command arguments.',
                     'invalid_json':'cPanel CLI did not return a recognized JSON response.'}.get(category,'cPanel did not acknowledge the API request.')]
    def safe_label(value):
        return value if isinstance(value,str) and re.fullmatch(r'[a-zA-Z0-9_:.-]{1,80}',value) else None
    return {'operation': 'SubDomain::' + function, 'read_only': function == 'listsubdomains',
            'exit_code': done.returncode, 'json_received': isinstance(document, dict),
            'api_module':safe_label(result.get('module')),'api_function':safe_label(result.get('func')),
            'response_keys': sorted(k for k in result if k in {'apiversion','func','module','event','status','result','data','error','errors'}),
            'api_status': indicator if type(indicator) in {int, str, bool} and str(indicator) in {'0','1','True','False'} else None,
            'category': category, 'reasons': redacted}


def identifier(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-zA-Z0-9_]+', value):
        raise Failure('GO_OWNER_SQL_IDENTIFIER')
    return '`' + value + '`'


def literal(value):
    if value is None:
        return 'NULL'
    if isinstance(value, bool):
        return '1' if value else '0'
    if isinstance(value, int):
        return str(value)
    encoded = str(value).encode().hex()
    return "CONVERT(0x" + encoded + " USING utf8mb4)" if encoded else "''"


def atomic(path, content, mode=0o640, uid=0, gid=None):
    if path.is_symlink():
        raise Failure('GO_OWNER_UNEXPECTED_SYMLINK')
    temp = path.parent / ('.owner-' + secrets.token_hex(12))
    descriptor = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content.encode() if isinstance(content, str) else content)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), uid, gid if gid is not None else 0)
            os.fchmod(stream.fileno(), mode)
        os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()


def checked_directory(path, mode=0o750, uid=0, gid=0):
    if path.is_symlink():
        raise Failure('GO_OWNER_UNEXPECTED_SYMLINK')
    path.mkdir(mode=mode, parents=True, exist_ok=True)
    if path.resolve() != path:
        raise Failure('GO_OWNER_UNEXPECTED_DIRECTORY')
    os.chown(path, uid, gid)
    os.chmod(path, mode)


def checked_record(row):
    try:
        if str(uuid.UUID(row['id'])) != row['id']:
            raise ValueError()
        if not re.fullmatch(r'[0-9a-f]{16}', row['slug']):
            raise ValueError()
        if row['desired_state'] not in STATES or int(row['created_by']) != 1 or int(row['revision']) < 1:
            raise ValueError()
        if not isinstance(row['name'], str) or not 2 <= len(row['name']) <= 130:
            raise ValueError()
        if not isinstance(row['email'], str) or len(row['email']) > 191 or '\n' in row['email'] or '@' not in row['email']:
            raise ValueError()
        if row['mobile'] is not None and not re.fullmatch(r'1[0125][0-9]{8}', row['mobile']):
            raise ValueError()
        if not re.fullmatch(r'\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}', row['password_hash']):
            raise ValueError()
    except (ValueError, TypeError, KeyError):
        raise Failure('GO_OWNER_CONTROL_RECORD_INVALID') from None
    return row


class Worker:
    def __init__(self, config):
        self.config = config
        self.database = config['database']
        identifier(self.database)
        if not re.fullmatch(r'https://[a-zA-Z0-9.-]+', config['url']):
            raise Failure('GO_OWNER_HTTPS_URL_REQUIRED')
        self.domain = re.sub(r'^www\.', '', config['url'][8:].lower())
        self.mysql = next((p for p in ['/usr/bin/mariadb', '/usr/bin/mysql'] if os.access(p, os.X_OK)), None)
        self.dump = next((p for p in ['/usr/bin/mariadb-dump', '/usr/bin/mysqldump'] if os.access(p, os.X_OK)), None)
        if not self.mysql or not self.dump:
            raise Failure('GO_OWNER_MYSQL_CLIENT_REQUIRED')
        self.account = pwd.getpwnam('fasakha')

    def sql(self, statement, database=None):
        command = [self.mysql, '--batch', '--raw', '--skip-column-names', '--default-character-set=utf8mb4']
        if database is not None:
            identifier(database)
            command += ['--database=' + database]
        done = subprocess.run(command, input=statement, capture_output=True, text=True, timeout=90)
        if done.returncode:
            # Never print SQL/CLI stderr: it may contain passwords, hashes or name/email bindings.
            raise Failure('GO_OWNER_DATABASE_OPERATION_FAILED')
        return done.stdout

    def rows(self, statement, database=None):
        return [json.loads(line) for line in self.sql(statement, database).splitlines() if line.strip()]

    def registry(self, where=''):
        fields = ['id', 'slug', 'name', 'email', 'mobile', 'password_hash', 'created_by',
                  'desired_state', 'state', 'revision', 'applied_revision']
        expr = ','.join(literal(f) + ',' + identifier(f) for f in fields)
        return self.rows('SELECT JSON_OBJECT(' + expr + ') FROM owner_dashboards ' + where, self.database)

    def acknowledge(self, row, state, error=None):
        # A concurrent primary-owner edit can only keep its deny marker and queue a fresh revision.
        result = self.sql('UPDATE owner_dashboards SET state=' + literal(state) + ',error_code=' + literal(error)
                         + ',applied_revision=' + str(int(row['revision'])) + ',updated_at=CURRENT_TIMESTAMP WHERE id='
                         + literal(row['id']) + ' AND revision=' + str(int(row['revision'])) + '; SELECT ROW_COUNT();', self.database)
        return result.strip().endswith('1')

    def record(self, row):
        path = BASE / 'records' / (row['id'] + '.json')
        if path.is_symlink():
            raise Failure('GO_OWNER_UNEXPECTED_SYMLINK')
        if path.exists():
            record = json.loads(path.read_text())
            if record['id'] != row['id'] or record['slug'] != row['slug']:
                raise Failure('GO_OWNER_LEDGER_MISMATCH')
            return path, record
        compact = row['id'].replace('-', '')
        record = {'format': 1, 'id': row['id'], 'slug': row['slug'], 'database': 'fasakha_ow_' + compact[:20],
                  'db_user': 'fasakha_ow_' + compact[:16], 'db_password': secrets.token_hex(32),
                  'app_key': 'base64:' + __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
                  'jwt_secret': secrets.token_hex(32), 'ever_active': False, 'schema_ready': False}
        # Write ownership evidence before the first CREATE. Unknown pre-existing databases/users are never adopted.
        database_exists = self.sql('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=' + literal(record['database'])).strip()
        user_exists = self.sql('SELECT COUNT(*) FROM mysql.user WHERE User=' + literal(record['db_user'])).strip()
        if database_exists != '0' or user_exists != '0':
            raise Failure('GO_OWNER_UNOWNED_DATABASE_OR_USER')
        atomic(path, json.dumps(record))
        return path, record

    def clone_schema(self, record):
        if record['schema_ready'] or record['ever_active']:
            return
        # Schema only. No business data, settings payload, account, token, role assignment or integration secret is dumped.
        unsafe = self.sql("SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=" + literal(self.database)
                          + ")+(SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=" + literal(self.database)
                          + ")+(SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=" + literal(self.database)
                          + ' AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>' + literal(self.database) + ')').strip()
        if unsafe != '0':
            raise Failure('GO_OWNER_SCHEMA_NEEDS_REVIEW')
        done = subprocess.run([self.dump, '--no-data', '--skip-triggers', '--skip-comments', '--no-tablespaces',
                               '--skip-add-drop-table', '--ignore-table=' + self.database + '.owner_dashboards', self.database],
                              capture_output=True, text=True, timeout=90)
        if done.returncode or not done.stdout.strip() or re.search(r'(?im)^\s*(?:USE\s|CREATE\s+DATABASE\s)', done.stdout):
            raise Failure('GO_OWNER_SCHEMA_EXPORT_FAILED')
        target = identifier(record['database'])
        user = identifier(record['db_user']) + "@'localhost'"
        # Retry rebuild is confined to an unpublished, root-ledger-owned target. An activated dashboard is never rebuilt.
        self.sql('DROP DATABASE IF EXISTS ' + target + ';CREATE DATABASE ' + target + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
                 + 'CREATE USER IF NOT EXISTS ' + user + " IDENTIFIED BY '" + record['db_password'] + "';"
                 + 'GRANT ALL PRIVILEGES ON ' + target + '.* TO ' + user + ';')
        schema = re.sub(r'\sAUTO_INCREMENT=[0-9]+', '', done.stdout)
        schema = schema.replace('REFERENCES ' + identifier(self.database) + '.', 'REFERENCES ' + target + '.')
        self.sql(schema, record['database'])

    def insert(self, database, table, values):
        self.sql('INSERT INTO ' + identifier(table) + '(' + ','.join(identifier(c) for c in values) + ') VALUES('
                 + ','.join(literal(v) for v in values.values()) + ');', database)

    def seed(self, row, record):
        if record['schema_ready'] or record['ever_active']:
            return
        target = record['database']
        # Framework migration bookkeeping and the permission vocabulary are the only copied rows.
        for table, fields in [('migrations', ['migration', 'batch']), ('permissions', ['id', 'name', 'guard_name'])]:
            expr = ','.join(literal(f) + ',' + identifier(f) for f in fields)
            for item in self.rows('SELECT JSON_OBJECT(' + expr + ') FROM ' + identifier(table), self.database):
                self.insert(target, table, item)
        role_names = ['Super Admin', 'admin', 'vendor', 'resturant_owner', 'delegate', 'user']
        for role_id, name in enumerate(role_names, 1):
            self.insert(target, 'roles', {'id': role_id, 'name': name, 'guard_name': 'admin'})
        # Owner and the explicitly selectable manager template have local business grants only.
        self.sql("INSERT INTO role_has_permissions(role_id,permission_id) SELECT 1,id FROM permissions WHERE guard_name='admin';"
                 + "INSERT INTO role_has_permissions(role_id,permission_id) SELECT 2,id FROM permissions WHERE guard_name='admin';", target)
        for role_id, role_name in enumerate(role_names[2:], 3):
            templates = self.rows("SELECT JSON_OBJECT('name',p.name) FROM permissions p JOIN role_has_permissions rp ON rp.permission_id=p.id "
                                  + "JOIN roles r ON r.id=rp.role_id WHERE r.guard_name='admin' AND p.guard_name='admin' AND r.name=" + literal(role_name), self.database)
            names = {p['name'] for p in templates}
            if role_name in {'vendor', 'resturant_owner'}:
                names.update(['resturant-list', 'resturant-edit', 'order-list', 'order-edit', 'order-create', 'product-list'])
            for name in names:
                self.sql('INSERT IGNORE INTO role_has_permissions(role_id,permission_id) SELECT ' + str(role_id)
                         + ',id FROM permissions WHERE name=' + literal(name) + " AND guard_name='admin'", target)
        self.insert(target, 'users', self.user_values(row, target))
        self.insert(target, 'model_has_roles', {'role_id': 1, 'model_id': 1, 'model_type': 'App\\Models\\User'})
        # Seed every typed GeneralSettings property with neutral values, never primary-business content.
        defaults = json.loads((LIB / 'settings_defaults.json').read_text())
        defaults.update(site_name=row['name'], email=row['email'], phone=row['mobile'] or '',
                        logo='owner-dashboard-logo.png', favicon='owner-dashboard-logo.png', base_logo='owner-dashboard-logo.png')
        for name, value in defaults.items():
            self.insert(target, 'settings', {'group': 'general', 'name': name, 'locked': 0,
                                            'payload': json.dumps(value, ensure_ascii=False)})
        record['schema_ready'] = True

    def user_values(self, row, target):
        columns = self.rows('SELECT JSON_OBJECT(\'name\',COLUMN_NAME,\'nullable\',IS_NULLABLE,\'default\',COLUMN_DEFAULT,\'extra\',EXTRA) '
                            + 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=' + literal(target) + " AND TABLE_NAME='users'", target)
        values = {'id': 1, 'name': row['name'], 'email': row['email'], 'mobile': row['mobile'], 'country_code': '20',
                  'password': row['password_hash'], 'account_type': 'admin', 'status': 'accepted', 'added_by': 1,
                  'roles_name': '["Super Admin"]', 'app_scope': 'fasakhansta', 'balance': 0,
                  'created_at': time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime()),
                  'updated_at': time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime())}
        names = {c['name'] for c in columns}
        values = {k: v for k, v in values.items() if k in names}
        if any(c['name'] not in values and c['nullable'] == 'NO' and c['default'] is None
               and 'auto_increment' not in c['extra'] and 'GENERATED' not in c['extra'] for c in columns):
            raise Failure('GO_OWNER_USER_REQUIRED_COLUMN_UNREVIEWED')
        return values

    def paths(self, row, record):
        base = TENANTS / 'tenants' / row['id']
        public = PUBLIC / row['slug']
        checked_directory(base, gid=self.account.pw_gid)
        checked_directory(public, 0o755, self.account.pw_uid, self.account.pw_gid)
        for name in APP_LINKS:
            source = MAIN / name
            link = base / name
            if not source.exists():
                if name == 'composer.lock':
                    continue
                raise Failure('GO_OWNER_APPLICATION_SOURCE_MISSING')
            if link.is_symlink():
                if link.resolve() != source.resolve():
                    raise Failure('GO_OWNER_APPLICATION_LINK_MISMATCH')
            elif link.exists():
                raise Failure('GO_OWNER_APPLICATION_LINK_MISMATCH')
            else:
                link.symlink_to(source, target_is_directory=source.is_dir())
        for folder in ['bootstrap', 'bootstrap/cache']:
            checked_directory(base / folder, 0o750, self.account.pw_uid, self.account.pw_gid)
        for folder in ['storage', 'storage/app', 'storage/app/public', 'storage/framework', 'storage/framework/cache',
                       'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
                       'storage/app/laravel-settings', 'storage/app/private']:
            checked_directory(base / folder, 0o700, self.account.pw_uid, self.account.pw_gid)
        attendance = base / 'storage/app/private/employee-auto-absence'
        checked_directory(attendance, 0o700, self.account.pw_uid, self.account.pw_gid)
        if not (attendance / 'activation.json').exists():
            atomic(attendance / 'activation.json', json.dumps({'starts_on': time.strftime('%Y-%m-%d')}), 0o600, self.account.pw_uid, self.account.pw_gid)
            atomic(attendance / 'install.lock', '', 0o600, self.account.pw_uid, self.account.pw_gid)
        for name in ASSETS:
            source, link = MAIN / 'public' / name, public / name
            if not source.is_dir():
                continue
            if link.is_symlink():
                if link.resolve() != source.resolve():
                    raise Failure('GO_OWNER_ASSET_LINK_MISMATCH')
            elif link.exists():
                raise Failure('GO_OWNER_ASSET_LINK_MISMATCH')
            else:
                link.symlink_to(source, target_is_directory=True)
                info = source.stat(); os.lchown(link, info.st_uid, info.st_gid)
        checked_directory(public / 'images', 0o700, self.account.pw_uid, self.account.pw_gid)
        logo = MAIN / 'public/dashboard/branding/fasakhansta-logo-transparent.png'
        # This bundled code asset contains no account or business media.
        atomic(base / 'storage/app/public/owner-dashboard-logo.png', logo.read_bytes(), 0o600, self.account.pw_uid, self.account.pw_gid)
        host = 'go-' + row['slug'] + '.' + self.domain
        runtime = dict(format=1, id=row['id'], slug=row['slug'], prefix='', host=host, origin='https://' + host, base=str(base),
                       public=str(public), state='pending', database=record['database'], db_user=record['db_user'])
        runtime['test_fixture'] = record.get('test_fixture', False)
        old = base / 'runtime.json'
        if old.is_file() and not old.is_symlink():
            runtime.update(config_sha256=json.loads(old.read_text()).get('config_sha256', ''))
        atomic(old, json.dumps(runtime), gid=self.account.pw_gid)
        # Runtime credentials are unique. No primary .env, cache, filesystem, signing key or provider credential is copied.
        environment = {'APP_NAME': 'GO ERP', 'APP_ENV': 'production', 'APP_DEBUG': 'false', 'APP_KEY': record['app_key'],
                       'APP_URL': runtime['origin'], 'DB_CONNECTION': 'mysql', 'DB_HOST': 'localhost',
                       'DB_PORT': '3306', 'DB_SOCKET': self.config['socket'], 'DB_DATABASE': record['database'],
                       'DB_USERNAME': record['db_user'], 'DB_PASSWORD': record['db_password'], 'CACHE_DRIVER': 'database',
                       'SESSION_DRIVER': 'file', 'QUEUE_CONNECTION': 'database', 'FILESYSTEM_DRIVER': 'local',
                       'JWT_SECRET': record['jwt_secret'], 'MAIL_MAILER': 'array', 'BROADCAST_DRIVER': 'null',
                       'QUEUE_CONVERSIONS_BY_DEFAULT': 'false', 'SETTINGS_CACHE_ENABLED': 'false',
                       'DESKTOP_DASHBOARD_ENABLED': 'false', 'DESKTOP_DASHBOARD_LOCAL': 'false',
                       'GO_SERVICES_ENABLED': 'false', 'META_CONVERSIONS_ENABLED': 'false',
                       'PAYMOB_API_KEY': '', 'PAYMOB_CARD_INTEGRATION_ID': '', 'PAYMOB_CARD_IFRAME_ID': '', 'PAYMOB_MOBILE_WALLET_INTEGRATION_ID': ''}
        if any('\n' in value or '"' in value or '\r' in value for value in environment.values()):
            raise Failure('GO_OWNER_ENVIRONMENT_VALUE_INVALID')
        atomic(base / '.env', ''.join(k + '="' + v + '"\n' for k, v in environment.items()), gid=self.account.pw_gid)
        # Initializer must load this tenant's clean .env, not an earlier config cache.
        cache = base / 'bootstrap/cache/config.php'
        if cache.exists():
            if cache.is_symlink():
                raise Failure('GO_OWNER_UNEXPECTED_SYMLINK')
            cache.unlink()
        htaccess = '''Options -Indexes -MultiViews
RewriteEngine On
RewriteRule ^(?:storage|images|uploads)(?:/|$) index.php [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
<FilesMatch "(?i)\\.(php[0-9]?|phtml|phar|env|sql|log)$">
Require all denied
</FilesMatch>
<Files "index.php">
Require all granted
</Files>
'''
        atomic(public / '.htaccess', htaccess, 0o644, self.account.pw_uid, self.account.pw_gid)
        entry = "<?php\n$goOwnerRuntime='" + str(base / 'runtime.json') + "';\nrequire '/home/fasakha/public_html/deployment/owner_dashboards/tenant_http.php';\n"
        atomic(public / 'index.php', entry, 0o644)
        return runtime

    def cpanel(self, function, parameters):
        if function not in {'addsubdomain', 'delsubdomain', 'listsubdomains'}:
            raise Failure('GO_OWNER_CPANEL_ACTION_FORBIDDEN')
        binary = '/usr/local/cpanel/bin/cpapi2'
        if not os.access(binary, os.X_OK):
            raise Failure('GO_OWNER_CPANEL_SUBDOMAIN_TOOL_REQUIRED')
        arguments = [binary, '--user=fasakha', '--output=json', 'SubDomain', function]
        arguments += [key + '=' + quote(value, safe='') for key, value in parameters.items()]
        done = subprocess.run(arguments, capture_output=True, text=True, timeout=90)
        document = None
        try:
            document = cpanel_document(done.stdout)
            if done.returncode:
                raise ValueError()
            return cpanel_result(document, function)
        except (ValueError, TypeError, KeyError):
            raise CpanelFailure(cpanel_diagnostics(done, document, function)) from None

    def prepare_host(self, row, record, runtime, record_path):
        if not record.get('domain_created'):
            existing = self.cpanel('listsubdomains', {'regex': '^' + re.escape(runtime['host']) + '$'})
            if existing:
                docroot = (existing[0].get('documentroot') or existing[0].get('dir')) if len(existing) == 1 else None
                if not record.get('domain_creation_started') or not isinstance(docroot, str) or str(Path(docroot)) != runtime['public']:
                    raise Failure('GO_OWNER_UNOWNED_SUBDOMAIN')
                record['domain_created'] = True
                atomic(record_path, json.dumps(record))
                return
            record['domain_creation_started'] = True
            atomic(record_path, json.dumps(record))
            self.cpanel('addsubdomain', {'domain': 'go-' + row['slug'], 'rootdomain': self.domain,
                                        'dir': '/public_html/public/dashboards/' + row['slug'], 'disallowdot': '1'})
            record['domain_created'] = True
            atomic(record_path, json.dumps(record))

    def map_database(self, record, record_path):
        if record.get('cpanel_mapped'):
            return
        binary = '/usr/local/cpanel/bin/dbmaptool'
        if not os.access(binary, os.X_OK):
            raise Failure('GO_OWNER_CPANEL_DATABASE_MAPPING_REQUIRED')
        done = subprocess.run([binary, 'fasakha', '--type', 'mysql', '--dbs', record['database'], '--dbusers', record['db_user']],
                              capture_output=True, text=True, timeout=60)
        if done.returncode:
            raise Failure('GO_OWNER_CPANEL_DATABASE_MAPPING_FAILED')
        record['cpanel_mapped'] = True
        atomic(record_path, json.dumps(record))

    def host_ready(self, row, record, runtime, record_path):
        # Independent origins prevent cross-owner cookies, browser storage and script access.
        # A random static proof verifies DNS, certificate and document-root routing without bypassing an inactive login.
        proof = record.setdefault('host_proof', secrets.token_hex(32))
        proof_file = Path(runtime['public']) / ('owner-ready-' + proof + '.txt')
        atomic(proof_file, proof, 0o644)
        done = subprocess.run(['curl', '--fail', '--silent', '--show-error', '--max-time', '15', '--proto', '=https',
                               runtime['origin'] + '/' + proof_file.name], capture_output=True, text=True, timeout=20)
        if done.returncode or done.stdout != proof:
            now = int(time.time())
            if now - int(record.get('autossl_checked_at', 0)) > 3600:
                record['autossl_checked_at'] = now
                atomic(record_path, json.dumps(record))
                binary = '/usr/local/cpanel/bin/autossl_check'
                if os.access(binary, os.X_OK):
                    # AutoSSL may finish asynchronously. The next tick rechecks real HTTPS with certificate verification.
                    subprocess.Popen([binary, '--user=fasakha'], stdin=subprocess.DEVNULL,
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
            raise PendingHost('GO_OWNER_DNS_OR_SSL_PENDING')
        proof_file.unlink()

    def console(self, runtime, action):
        if action not in {'initialize', 'health', 'queue', 'schedule', 'isolation', 'crud'}:
            raise Failure('GO_OWNER_CONSOLE_ACTION')
        done = subprocess.run(['runuser', '-u', 'fasakha', '--', PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
                               str(LIB / 'tenant_console.php'), runtime['base'] + '/runtime.json', action],
                              capture_output=True, text=True, timeout=70, env=SAFE_ENV)
        if done.returncode:
            code = done.stderr.strip()
            raise Failure(code if re.fullmatch(r'GO_OWNER_[A-Z_]+', code) else 'GO_OWNER_TENANT_RUNTIME_FAILED')
        return done.stdout

    def apply(self, row):
        checked_record(row)
        deny = TENANTS / 'control/deny' / row['id']
        descriptor = os.open(deny, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o660)
        with os.fdopen(descriptor, 'r+') as stream:
            os.fchown(stream.fileno(), 0, self.account.pw_gid); os.fchmod(stream.fileno(), 0o660)
            fcntl.flock(stream, fcntl.LOCK_EX)
            marker = stream.read().strip()
            if not marker or (marker.isdigit() and int(marker) <= int(row['revision'])):
                stream.seek(0); stream.truncate(); stream.write(str(row['revision'])); stream.flush(); os.fsync(stream.fileno())
        record_path, record = self.record(row)
        if row['desired_state'] != 'active':
            if record['ever_active']:
                self.sql('UPDATE users SET remember_token=NULL', record['database'])
                record['jwt_secret'] = secrets.token_hex(32)
                atomic(record_path, json.dumps(record))
                sessions = TENANTS / 'tenants' / row['id'] / 'storage/framework/sessions'
                if sessions.is_dir() and not sessions.is_symlink():
                    for session in sessions.iterdir():
                        if session.is_file() and not session.is_symlink(): session.unlink()
            runtime_file = TENANTS / 'tenants' / row['id'] / 'runtime.json'
            if runtime_file.is_file() and not runtime_file.is_symlink():
                runtime = json.loads(runtime_file.read_text()); runtime['state'] = row['desired_state']
                atomic(runtime_file, json.dumps(runtime), gid=self.account.pw_gid)
            self.acknowledge(row, row['desired_state'])
            return
        self.clone_schema(record)
        self.seed(row, record)
        atomic(record_path, json.dumps(record))
        # The target owner is always ID 1 in this database, unrelated to primary DB IDs.
        changed_password = self.sql('SELECT password FROM users WHERE id=1', record['database']).strip() != row['password_hash']
        updates = 'name=' + literal(row['name']) + ',email=' + literal(row['email']) + ',mobile=' + literal(row['mobile'])
        updates += ',password=' + literal(row['password_hash']) + ',status=\'accepted\',account_type=\'admin\''
        if changed_password:
            updates += ',remember_token=NULL'
            record['jwt_secret'] = secrets.token_hex(32)
            atomic(record_path, json.dumps(record))
        self.sql('UPDATE users SET ' + updates + ' WHERE id=1', record['database'])
        runtime = self.paths(row, record)
        self.console(runtime, 'initialize')
        cache = Path(runtime['base']) / 'bootstrap/cache/config.php'
        runtime['config_sha256'] = hashlib.sha256(cache.read_bytes()).hexdigest()
        os.chown(cache, 0, self.account.pw_gid); os.chmod(cache, 0o640)
        os.chown(cache.parent, 0, self.account.pw_gid); os.chmod(cache.parent, 0o750)
        atomic(Path(runtime['base']) / 'runtime.json', json.dumps(runtime), gid=self.account.pw_gid)
        # An empty-dashboard health probe runs only before first activation; later edits preserve all business data.
        if not record['ever_active']:
            self.console(runtime, 'health')
        self.prepare_host(row, record, runtime, record_path)
        self.map_database(record, record_path)
        self.host_ready(row, record, runtime, record_path)
        if changed_password:
            for session in (Path(runtime['base']) / 'storage/framework/sessions').iterdir():
                if session.is_file() and not session.is_symlink():
                    session.unlink()
        runtime['state'] = 'active'
        atomic(Path(runtime['base']) / 'runtime.json', json.dumps(runtime), gid=self.account.pw_gid)
        record['ever_active'] = True
        atomic(record_path, json.dumps(record))
        # Never hold a file lock while waiting for an UPDATE's row lock. A primary edit locks row then file.
        accepted = self.acknowledge(row, 'active')
        self.publish_marker(row, accepted)

    def publish_marker(self, row, accepted=True):
        deny = TENANTS / 'control/deny' / row['id']
        descriptor = os.open(deny, os.O_RDWR | os.O_NOFOLLOW)
        with os.fdopen(descriptor, 'r+') as stream:
            fcntl.flock(stream, fcntl.LOCK_EX)
            marker = stream.read().strip()
            current = self.registry('WHERE id=' + literal(row['id']))
            if accepted and marker == str(row['revision']) and current and int(current[0]['revision']) == int(row['revision']) and current[0]['desired_state'] == 'active' and current[0]['state'] == 'active':
                stream.seek(0); stream.truncate(); stream.flush(); os.fsync(stream.fileno())

    def tick(self):
        for raw in self.registry('WHERE revision<>applied_revision ORDER BY updated_at,created_at LIMIT 4'):
            try:
                self.apply(raw)
            except PendingHost as error:
                self.sql("UPDATE owner_dashboards SET state='pending',error_code=" + literal(str(error))
                         + ',updated_at=CURRENT_TIMESTAMP WHERE id=' + literal(raw['id']) + ' AND revision=' + str(int(raw['revision'])), self.database)
            except Exception as error:
                code = str(error)
                self.acknowledge(raw, 'error', code if re.fullmatch(r'GO_OWNER_[A-Z_]+', code) else 'GO_OWNER_PROVISIONING_FAILED')
        # Recover a crash after a successful DB acknowledgement and before marker publication.
        for row in self.registry("WHERE state='active' AND desired_state='active' AND revision=applied_revision"):
            checked_record(row)
            marker = TENANTS / 'control/deny' / row['id']
            runtime_file = TENANTS / 'tenants' / row['id'] / 'runtime.json'
            if marker.is_file() and not marker.is_symlink() and marker.read_text().strip() == str(row['revision']) and runtime_file.is_file():
                runtime = json.loads(runtime_file.read_text())
                cache = Path(runtime['base']) / 'bootstrap/cache/config.php'
                if runtime['state'] == 'active' and cache.is_file() and hashlib.sha256(cache.read_bytes()).hexdigest() == runtime['config_sha256']:
                    self.publish_marker(row)
        stamp = BASE / 'last-background-minute'
        minute = str(int(time.time() // 60))
        if not stamp.exists() or stamp.read_text() != minute:
            atomic(stamp, minute)
            for row in self.registry("WHERE state='active' AND desired_state='active' AND revision=applied_revision"):
                try:
                    runtime = json.loads((TENANTS / 'tenants' / row['id'] / 'runtime.json').read_text())
                    self.console(runtime, 'queue'); self.console(runtime, 'schedule')
                except Exception:
                    pass  # Jobs retain their own retry/failure receipt; never log credentials from providers.


def main():
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524' or sys.argv[1:] != ['--tick']:
        raise Failure('GO_OWNER_WORKER_ROOT_HOST_ARGUMENTS')
    if BASE.is_symlink() or BASE.resolve() != BASE:
        raise Failure('GO_OWNER_WORKER_DIRECTORY')
    with open(BASE / 'worker.lock', 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        config = json.loads((BASE / 'config.json').read_text())
        Worker(config).tick()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error)
        print(code if re.fullmatch(r'GO_OWNER_[A-Z_]+', code) else 'GO_OWNER_WORKER_FAILED', file=sys.stderr)
        sys.exit(1)
