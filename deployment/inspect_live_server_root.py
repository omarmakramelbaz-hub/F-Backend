#!/usr/bin/env python3
"""Refactor, step 0 (second look): the facts the plan needs from srv1836524. It only reads.

  1. The live site code against one commit of the repository (the branch rebuilt to match it): each file that still
     differs, with a short diff of text files (a diff that looks like it holds a secret is never printed).
  2. The site's own git checkout: its commit, its last commits and how many tracked files differ from it.
  3. PHP: the installed PHP packages, OPcache for the site's PHP-FPM, its pool and php.ini limits.
  4. Laravel's effective settings, from the cached configuration: only harmless ones (debug, environment, drivers,
     the database's name); never a password, a key or a token. And the size of its logs, sessions and caches.
  5. What anyone can fetch over the web from fasakhaninja.com and test.fasakhaninja.com: the status of paths that must
     never be public (.env, .git, logs, debug tools). Only status codes and sizes, never what came back.
  6. The scheduler's and the queue worker's command lines (long values masked), GO ERP's systemd units.
  7. Folder sizes, the database's largest tables and settings, the machine's CPUs, memory and load.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/inspect_live_server_root.py --commit <the commit to compare with>

The repository is read into a temporary folder (trees only, plus the few files that differ) that is removed at the
end; nothing else is written.
"""
import argparse
import collections
import difflib
import glob
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import urllib.error
import urllib.request

HOST = 'srv1836524'
WEB_USER = 'fasakha'
SITE = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
PHP_ROOT = Path('/opt/cpanel/ea-php82/root')
REPOSITORY = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
ORIGINS = {
    'https://fasakhaninja.com': ('/', '/admin/login', '/.env', '/.env.save', '/.git/config', '/error_log', '/composer.lock',
                                 '/storage/logs/laravel.log', '/vendor/composer/installed.json', '/_debugbar/open',
                                 '/_ignition/health-check', '/telescope', '/horizon', '/phpinfo.php', '/storage/'),
    'https://test.fasakhaninja.com': ('/', '/admin/login', '/.env', '/.git/config', '/error_log', '/composer.lock',
                                      '/storage/logs/laravel.log', '/vendor/composer/installed.json', '/_debugbar/open',
                                      '/_ignition/health-check', '/dev/', '/dev/.env', '/dev/storage/logs/laravel.log',
                                      '/public/.env'),
}
# Settings safe to print: what runs, never a credential.
CONFIG_KEYS = ('app.env', 'app.debug', 'app.url', 'app.timezone', 'app.locale', 'cache.default', 'session.driver',
               'session.lifetime', 'session.secure', 'session.same_site', 'queue.default', 'logging.default',
               'broadcasting.default', 'filesystems.default', 'mail.default', 'database.default',
               'database.connections.mysql.database', 'debugbar.enabled', 'debugbar.storage.enabled')
PHP_INI_KEYS = ('memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'display_errors',
                'realpath_cache_size', 'realpath_cache_ttl', 'max_input_vars')
POOL_KEYS = re.compile(r'^\s*(pm(\.[a-z_]+)?|request_terminate_timeout|php_(admin_)?(value|flag)\[(memory_limit|max_execution_time|'
                       r'upload_max_filesize|post_max_size|display_errors|opcache\.[a-z_]+)\])\s*=')
FOLDERS = ('faskhaNinja', 'test.fasakhaninja.com', '__app', '__config', '__database', '__resources', '__routes', 'deployment',
           'tests', 'docs', 'desktop-pos', 'desktop', '.git', 'vendor', 'node_modules', 'storage', 'storage/logs',
           'storage/framework/sessions', 'storage/framework/cache', 'storage/framework/views', 'storage/app', 'storage/debugbar',
           'public/uploads', 'public/dashboard')
DB_VARIABLES = ('version', 'innodb_buffer_pool_size', 'innodb_log_file_size', 'max_connections', 'slow_query_log',
                'long_query_time', 'query_cache_type', 'query_cache_size', 'tmp_table_size', 'max_heap_table_size',
                'table_open_cache', 'thread_cache_size', 'innodb_flush_log_at_trx_commit')
DB_STATUS = ('Uptime', 'Questions', 'Slow_queries', 'Threads_connected', 'Max_used_connections', 'Created_tmp_disk_tables',
             'Select_full_join', 'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests')
# A diff line with any of these is never printed.
SECRET = re.compile(r'-----BEGIN|APP_KEY|DB_PASSWORD|PASSWORD\s*=|SECRET\s*=|sk_live_|ghp_[A-Za-z0-9]{20}|github_pat_|'
                    r'xox[abprs]-|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_\-]{35}|"private_key"|client_secret', re.IGNORECASE)
# Token-like values (24+ letters, digits, - _ +, with base64 padding); paths keep their slashes.
LONG_VALUE = re.compile(r'[A-Za-z0-9_\-+]{24,}={0,2}')
DIFF_LINES = 120
CRONTABS = ('/var/spool/cron/' + WEB_USER, '/var/spool/cron/root')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_LIVE_INSPECT_' + reason)


def comparison():
    """The live-code comparison (same folder): its git reading and file hashing are reused as they are."""
    spec = importlib.util.spec_from_file_location('compare_live_code_root', str(Path(__file__).resolve().parent / 'compare_live_code_root.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


compare = comparison()
run = compare.run
text = compare.text


def lines_of(result):
    return text(result.stdout).splitlines() if result is not None and result.returncode == 0 else []


def masked(line):
    return LONG_VALUE.sub('<…>', line)


# 1. The live site code against one commit --------------------------------------------------------------------------

def blob(repository, identifier):
    """A file of the repository; the few that differ are downloaded on demand (the copy holds trees only)."""
    environment = dict(os.environ, GIT_TERMINAL_PROMPT='0', LC_ALL='C')
    environment.pop('GIT_NO_LAZY_FETCH', None)
    result = run(['git', '--git-dir', str(repository), 'cat-file', 'blob', identifier], timeout=300, env=environment)
    return result.stdout if result is not None and result.returncode == 0 else None


def diff_of(old, new, name):
    """A short unified diff of two text files, or why there is none."""
    if old is None or new is None:
        return 'not readable', []
    if b'\0' in old or b'\0' in new:
        return 'binary, {} -> {} bytes'.format(len(old), len(new)), []
    try:
        before, after = old.decode('utf-8').splitlines(), new.decode('utf-8').splitlines()
    except UnicodeDecodeError:
        return 'not UTF-8, {} -> {} bytes'.format(len(old), len(new)), []
    rows = list(difflib.unified_diff(before, after, 'repository/' + name, 'live/' + name, n=2, lineterm=''))
    changed = [row for row in rows[2:] if row[:1] in '+-']
    if any(SECRET.search(row) for row in changed):
        return 'diff withheld: it looks like it holds a secret ({} changed lines)'.format(len(changed)), []
    if len(rows) > DIFF_LINES:
        return 'diff too long to print ({} lines, {} changed)'.format(len(rows), len(changed)), []
    return '{} changed lines'.format(len(changed)), rows


def against_commit(site, url, commit, folder):
    repository, mode = compare.fetch(url, folder)
    found = run(['git', '--git-dir', str(repository), 'rev-parse', '--verify', '-q', commit + '^{commit}'], env=compare.git_environment())
    need(found is not None and found.returncode == 0, 'COMMIT_NOT_IN_REPOSITORY')
    commit = text(found.stdout).strip()
    entries = compare.tree_entries(repository, commit + '^{tree}')
    live = compare.live_files(site, entries)
    same, differ, missing, extra = 0, [], [], []
    for path, identifier in sorted(entries.items()):
        if compare.skipped(path) or not compare.runtime(path):
            continue
        here = live.get(path)
        if here is None:
            missing.append(text(path))
        elif here['blob'] == identifier:
            same += 1
        elif here['lf'] == identifier:
            differ.append({'path': text(path), 'state': 'the same but with Windows line endings', 'diff': []})
        else:
            data = None
            try:
                with open(os.path.join(os.fsencode(str(site)), path), 'rb') as stream:
                    data = stream.read()
            except OSError:
                pass
            state, rows = diff_of(blob(repository, identifier), data, text(path))
            differ.append({'path': text(path), 'state': state, 'diff': rows,
                           'sha256': hashlib.sha256(data).hexdigest() if data is not None else None})
    for path in sorted(live):
        if path not in entries and compare.runtime(path) and compare.code_like(path):
            extra.append(text(path))
    return {'commit': commit, 'mode': mode, 'same': same, 'differ': differ, 'missing': missing, 'extra': extra}


# 2. The site's own git checkout -------------------------------------------------------------------------------------

def site_git(site):
    folder = site / '.git'
    if not folder.exists():
        return {'checkout': False}
    base = ['git', '-c', 'safe.directory=*', '--git-dir', str(folder), '--work-tree', str(site)]
    environment = compare.git_environment()
    found = {'checkout': True}
    found['head'] = ' '.join(lines_of(run(base + ['log', '-1', '--format=%H %ci'], env=environment)))
    found['branch'] = ' '.join(lines_of(run(base + ['symbolic-ref', '-q', '--short', 'HEAD'], env=environment))) or 'no branch'
    found['commits'] = ' '.join(lines_of(run(base + ['rev-list', '--count', 'HEAD'], env=environment)))
    found['last'] = lines_of(run(base + ['log', '-8', '--format=%h %ci %s'], env=environment))
    found['remotes'] = lines_of(run(base + ['remote'], env=environment))
    origin_main = lines_of(run(base + ['log', '-1', '--format=%h %ci', 'refs/remotes/origin/main', '--'], env=environment))
    found['origin_main'] = origin_main[0] if origin_main else 'none'
    status = run(base + ['status', '--porcelain=v1', '--untracked-files=no'], timeout=600, env=environment)
    found['tracked_files_changed'] = len(lines_of(status)) if status is not None and status.returncode == 0 else 'unknown'
    return found


# 3. PHP -------------------------------------------------------------------------------------------------------------

def ini_values(path, keys):
    found = collections.OrderedDict()
    try:
        for line in Path(path).read_text(errors='replace').splitlines():
            line = line.strip()
            if not line or line.startswith((';', '#', '[')) or '=' not in line:
                continue
            key, value = (part.strip() for part in line.split('=', 1))
            if key in keys and key not in found:
                found[key] = value
    except OSError:
        pass
    return found


def php_runtime(root):
    found = collections.OrderedDict()
    packages = lines_of(run(['rpm', '-qa', '--qf', '%{NAME}\\n']))
    found['packages'] = sorted(name for name in packages if name.startswith(('ea-php82', 'ea-php83')))
    fpm = root / 'usr/sbin/php-fpm'
    modules = lines_of(run([str(fpm), '-m'])) if os.access(str(fpm), os.X_OK) else []
    found['fpm_opcache'] = 'loaded' if 'Zend OPcache' in modules else ('not loaded' if modules else 'unknown')
    settings = []
    for path in [root / 'etc/php.ini'] + sorted(Path(item) for item in glob.glob(str(root / 'etc/php.d/*.ini'))):
        try:
            for line in path.read_text(errors='replace').splitlines():
                stripped = line.strip()
                if re.match(r'^(opcache\.[a-z_]+|zend_extension)\s*=', stripped):
                    settings.append('{}: {}'.format(path.name, stripped))
        except OSError:
            continue
    found['opcache_settings'] = settings
    found['php_ini'] = ['{} = {}'.format(key, value) for key, value in ini_values(root / 'etc/php.ini', PHP_INI_KEYS).items()]
    pools = []
    for path in sorted(glob.glob(str(root / 'etc/php-fpm.d/*.conf'))):
        if 'fasakha' not in os.path.basename(path):
            continue
        try:
            rows = [line.strip() for line in Path(path).read_text(errors='replace').splitlines() if POOL_KEYS.match(line)]
        except OSError:
            rows = []
        pools.append('{}: {}'.format(os.path.basename(path), '; '.join(rows) or 'defaults'))
    found['fpm_pools'] = pools
    processes = lines_of(run(['ps', '-eo', 'args']))
    found['fpm_workers'] = sum(1 for line in processes if line.startswith('php-fpm: pool') and 'fasakha' in line)
    return found


# 4. Laravel's effective settings ------------------------------------------------------------------------------------

READ_CONFIG = r'''
$config = require $argv[1];
$found = [];
foreach (json_decode($argv[2], true) as $key) {
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (is_array($value) && array_key_exists($part, $value)) { $value = $value[$part]; } else { $value = '(not set)'; break; }
    }
    $found[$key] = is_scalar($value) || $value === null ? $value : gettype($value);
}
echo json_encode($found);
'''


def as_user(command, user):
    if user is None:
        return command
    return ['runuser', '-u', user, '--'] + command


def laravel(site, php, user):
    found = collections.OrderedDict()
    cached = site / 'bootstrap/cache/config.php'
    if not cached.is_file():
        found['config'] = 'not cached (settings come from .env on each request)'
        return found
    result = run(as_user([php, '-r', READ_CONFIG, '--', str(cached), json.dumps(list(CONFIG_KEYS))], user))
    try:
        values = json.loads(text(result.stdout)) if result is not None and result.returncode == 0 else {}
    except ValueError:
        values = {}
    for key in CONFIG_KEYS:
        found[key] = json.dumps(values[key]) if key in values else 'unreadable'
    environment = site / '.env'
    if environment.exists():
        found['config cache older than .env'] = 'yes' if cached.stat().st_mtime < environment.stat().st_mtime else 'no'
    logs = sorted((item for item in (site / 'storage/logs').glob('*') if item.is_file()), key=lambda item: -item.stat().st_mtime)
    found['newest logs'] = ', '.join('{} {} MB'.format(item.name, item.stat().st_size // 1048576) for item in logs[:4]) or 'none'
    found['log files'] = len(logs)
    sessions = site / 'storage/framework/sessions'
    found['session files'] = sum(1 for item in sessions.iterdir()) if sessions.is_dir() else 0
    return found


# 5. What the web serves ---------------------------------------------------------------------------------------------

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *arguments, **options):
        return None


def fetch_status(address, timeout=12):
    """Status, type, size and redirect of one address; the body is never read."""
    opener = urllib.request.build_opener(NoRedirect)
    request = urllib.request.Request(address, headers={'User-Agent': 'go-erp-live-inspect'})
    try:
        with opener.open(request, timeout=timeout) as response:
            headers, status = response.headers, response.status
    except urllib.error.HTTPError as error:
        headers, status = error.headers, error.code
    except (urllib.error.URLError, OSError) as error:
        return 'no answer ({})'.format(type(error).__name__)
    parts = [str(status), (headers.get('Content-Type') or '-').split(';')[0], (headers.get('Content-Length') or '-') + ' bytes']
    if headers.get('Location'):
        parts.append('-> ' + masked(headers.get('Location')))
    return ' '.join(parts)


def exposure(origins):
    found = collections.OrderedDict()
    for origin, paths in origins.items():
        found[origin] = [(path, fetch_status(origin + path)) for path in paths]
    return found


# 6. Scheduler, workers, units ---------------------------------------------------------------------------------------

def schedule():
    found = collections.OrderedDict()
    rows = []
    for path in CRONTABS:
        try:
            for line in Path(path).read_text(errors='replace').splitlines():
                if line.strip() and not line.lstrip().startswith('#') and re.search(r'artisan|fasakha|go-erp', line):
                    rows.append('{}: {}'.format(os.path.basename(path), masked(line.strip())))
        except OSError:
            continue
    found['cron'] = rows
    processes = lines_of(run(['ps', '-eo', 'user,etime,args']))
    found['artisan processes'] = [masked(line.strip()) for line in processes if 'artisan' in line and 'ps -eo' not in line]
    units = lines_of(run(['systemctl', 'list-units', '--all', '--no-legend', '--plain', 'go-erp*']))
    found['go-erp units'] = [' '.join(line.split()[:4]) for line in units]
    return found


# 7. Sizes, database, machine ----------------------------------------------------------------------------------------

def sizes(site):
    found = collections.OrderedDict()
    for name in FOLDERS:
        path = site / name
        if not path.exists():
            continue
        result = run(['du', '-sb', str(path)], timeout=600)
        if result is not None and result.stdout:
            found[name] = '{} MB'.format(int(text(result.stdout).split()[0]) // 1048576)
    return found


def mysql(query):
    return [row.split('\t') for row in lines_of(run(['mysql', '--batch', '--skip-column-names', '-e', query], timeout=300))]


def database(name):
    found = collections.OrderedDict()
    if not re.match(r'^[A-Za-z0-9_]+$', name or ''):
        found['database'] = 'unknown'
        return found
    found['database'] = name
    tables = mysql("SELECT table_name, IFNULL(table_rows,0), ROUND(data_length/1048576,1), ROUND(index_length/1048576,1), engine "
                   "FROM information_schema.tables WHERE table_schema='{}' AND table_type='BASE TABLE' "
                   "ORDER BY data_length+index_length DESC".format(name))
    found['tables'] = len(tables)
    found['total'] = '{:.0f} MB'.format(sum(float(row[2]) + float(row[3]) for row in tables if len(row) >= 4))
    found['largest'] = ['{} rows={} data={}MB index={}MB {}'.format(*row) for row in tables[:20] if len(row) >= 5]
    found['not InnoDB'] = [row[0] + ' ' + row[4] for row in tables if len(row) >= 5 and row[4] != 'InnoDB']
    keyless = mysql("SELECT t.table_name FROM information_schema.tables t LEFT JOIN information_schema.table_constraints c "
                    "ON c.table_schema=t.table_schema AND c.table_name=t.table_name AND c.constraint_type='PRIMARY KEY' "
                    "WHERE t.table_schema='{}' AND t.table_type='BASE TABLE' AND c.constraint_name IS NULL".format(name))
    found['without a primary key'] = [row[0] for row in keyless]
    found['settings'] = ['{} = {}'.format(*row) for row in mysql(
        "SHOW GLOBAL VARIABLES WHERE Variable_name IN ({})".format(','.join("'{}'".format(key) for key in DB_VARIABLES))) if len(row) == 2]
    found['status'] = ['{} = {}'.format(*row) for row in mysql(
        "SHOW GLOBAL STATUS WHERE Variable_name IN ({})".format(','.join("'{}'".format(key) for key in DB_STATUS))) if len(row) == 2]
    return found


def machine():
    found = collections.OrderedDict()
    found['cpus'] = os.cpu_count()
    try:
        memory = dict(line.split(':', 1) for line in Path('/proc/meminfo').read_text().splitlines() if ':' in line)
        for key in ('MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree'):
            found[key] = '{} MB'.format(int(memory[key].split()[0]) // 1024)
    except (OSError, KeyError, ValueError):
        pass
    try:
        found['load'] = ' '.join(Path('/proc/loadavg').read_text().split()[:3])
        found['up days'] = int(float(Path('/proc/uptime').read_text().split()[0]) // 86400)
    except (OSError, ValueError):
        pass
    return found


# The report -------------------------------------------------------------------------------------------------------

def section(lines, title, values):
    lines.append('')
    lines.append('== ' + title + ' ==')
    for key, value in values.items():
        if isinstance(value, list):
            lines.append('{}: {}'.format(key, '' if value else 'none'))
            lines.extend('  ' + str(item) for item in value)
        else:
            lines.append('{}: {}'.format(key, value))


def report(parts):
    lines = ['GO_ERP_LIVE_INSPECT']
    code = parts.get('code')
    if isinstance(code, dict):
        lines.append('')
        lines.append('== live site code against commit {} =='.format(code['commit'][:12]))
        lines.append('same: {}  differ: {}  missing on live: {}  live code the commit does not have: {}'.format(
            code['same'], len(code['differ']), len(code['missing']), len(code['extra'])))
        for row in code['differ']:
            lines.append('{}  | {}{}'.format(row['path'], row['state'], '  | live sha256 ' + row['sha256'] if row.get('sha256') else ''))
            lines.extend('    ' + item for item in row['diff'])
        missing = collections.Counter(path.rsplit('/', 1)[0] for path in code['missing'])
        for folder, count in missing.most_common(10):
            lines.append('  missing on live: {}/ ({} files)'.format(folder, count))
        for path in code['extra'][:40]:
            lines.append('  only on live: ' + path)
    elif code:
        lines.append('live site code: ' + str(code))
    titles = (('git', 'the site\'s git checkout'), ('php', 'PHP'), ('laravel', 'Laravel settings (cached config; harmless keys only)'),
              ('web', 'what the web serves (status, type, size; never the content)'), ('schedule', 'scheduler, workers, units'),
              ('sizes', 'folder sizes'), ('database', 'database'), ('machine', 'machine'))
    for key, title in titles:
        value = parts.get(key)
        if isinstance(value, dict) and key == 'web':
            lines.append('')
            lines.append('== ' + title + ' ==')
            for origin, rows in value.items():
                for path, status in rows:
                    lines.append('{}{}  {}'.format(origin, path, status))
        elif isinstance(value, dict):
            section(lines, title, value)
        elif value:
            lines.append('')
            lines.append('== {} == {}'.format(title, value))
    return '\n'.join(lines)


def database_name(settings):
    """The site's database, as its cached configuration names it."""
    raw = settings.get('database.connections.mysql.database') if isinstance(settings, dict) else None
    try:
        value = json.loads(raw) if raw else None
    except ValueError:
        value = None
    return value if isinstance(value, str) and re.match(r'^[A-Za-z0-9_]+$', value) else None


def gather(site, url, commit, folder, php, php_root, user, origins):
    """Each part on its own: one that fails says so and the others still report."""
    parts = collections.OrderedDict()
    steps = (('code', lambda: against_commit(site, url, commit, folder) if commit else 'no commit given'),
             ('git', lambda: site_git(site)), ('php', lambda: php_runtime(php_root)), ('laravel', lambda: laravel(site, php, user)),
             ('web', lambda: exposure(origins)), ('schedule', schedule), ('sizes', lambda: sizes(site)))
    for key, step in steps:
        try:
            parts[key] = step()
        except Stop as error:
            parts[key] = str(error)
        except Exception as error:  # noqa: BLE001 - one part failing must not hide the others
            parts[key] = 'unavailable ({})'.format(type(error).__name__)
    try:
        parts['database'] = database(database_name(parts.get('laravel')) or 'fasakha_db')
    except Exception as error:  # noqa: BLE001
        parts['database'] = 'unavailable ({})'.format(type(error).__name__)
    parts['machine'] = machine()
    return parts


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--commit', required=True)
    options = parser.parse_args()
    need(re.match(r'^[0-9a-f]{7,40}$', options.commit), 'COMMIT_MUST_BE_A_HASH')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need((SITE / 'artisan').is_file(), 'SITE_NOT_FOUND')
    os.umask(0o077)
    try:
        os.nice(10)
    except OSError:
        pass
    folder = Path(tempfile.mkdtemp(prefix='go-erp-live-inspect-', dir='/var/tmp'))
    try:
        parts = gather(SITE, REPOSITORY, options.commit, folder, PHP, PHP_ROOT, WEB_USER, ORIGINS)
    finally:
        shutil.rmtree(str(folder), ignore_errors=True)
    print(report(parts), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_LIVE_INSPECT_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
