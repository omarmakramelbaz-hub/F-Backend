#!/usr/bin/env python3
"""Install only the reviewed Meta connection overlay; no database or Graph writes."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import tempfile
from urllib.parse import urlsplit


BASELINE_BLOBS = {
    'app/Http/Controllers/Dashboard/MetaInboxController.php': 'e3183d9bd1bbb6ded01cb51a9d8bd29bdb9fca91',
    'app/Services/Dashboard/MetaInboxAccess.php': '260b0a1cc93be44bf9e96f508b55420e5f639f64',
    'routes/meta_inbox.php': '646634ef68604636bf05e07fc619ae722d6a5f02',
    'resources/views/admin/meta/index.blade.php': '79f6d85da843ec69a98c36728fc095c6eb22d645',
}
NEW_FILES = (
    'app/Http/Controllers/Dashboard/MetaConnectionController.php',
    'app/Http/Middleware/MetaOAuthCallbackPrivacy.php',
    'app/Services/Dashboard/MetaOAuthCredentials.php',
    'app/Services/Dashboard/MetaOAuthService.php',
    'app/Services/Dashboard/MetaOAuthFailure.php',
    'resources/views/admin/meta/connection.blade.php',
    'public/css/dashboard-meta-connection.css',
)
# Publish dependencies before routes, views, or the existing inbox controller can use them.
PUBLISH_ORDER = NEW_FILES + (
    'app/Services/Dashboard/MetaInboxAccess.php',
    'routes/meta_inbox.php',
    'resources/views/admin/meta/index.blade.php',
    'app/Http/Controllers/Dashboard/MetaInboxController.php',
)
FOUNDATION_BLOBS = {
    'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484',
    'app/Services/Dashboard/WhatsAppInboxAccess.php': '186789d42113e81818de8d82a2327e3ee9d8d0b5',
    'app/Http/Middleware/IsAdmin.php': '0ad83237ba2fd8f4e52fd68c1cf0de39500d99aa',
    'app/Http/Middleware/VerifyCsrfToken.php': '021d950989c70d277d7aa228caf1de6b284ec6a1',
    'app/Http/Kernel.php': '5c99798b24e893d6084e42660bd8c76c06441f9e',
    'app/Providers/AppServiceProvider.php': '599060dff3c43abe9a7e43f8d8bc6b61fabe158d',
    'app/Providers/RouteServiceProvider.php': '601bc01df754b568c9e6e7a6bb95b866011cca42',
    'routes/web.php': '2ae5ddb9639a70382bc5012f6fb9b83030216f35',
    'config/meta_messaging.php': '3530fbdb5886e6ea88c29a2f2b2be1633485e3fd',
    'public/css/dashboard-meta-inbox.css': 'ab4beecd1580769d917e3ddfe3b4139123d9dbbe',
}
ROUTE_CACHE_FILES = ('bootstrap/cache/routes-v7.php', 'bootstrap/cache/routes.php')
PRIVATE_DIR = 'storage/app/private/meta-oauth'
SETTINGS_FILE = PRIVATE_DIR + '/settings.json'
CREDENTIALS_FILE = PRIVATE_DIR + '/credentials.json'
CALLBACK_PATH = '/admin/meta/connection/callback'
APP_ID = '2177982783065133'
CONFIG_ID = '3007667439572336'
API_VERSION = 'v25.0'

RUNTIME_PREFLIGHT = br'''<?php
ini_set('display_errors', '0'); ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level = ob_get_level(); ob_start(); $result = ['ok' => false]; $exitCode = 1;
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (getenv('APP_ROUTES_CACHE') !== false || array_key_exists('APP_ROUTES_CACHE', $_ENV)
        || array_key_exists('APP_ROUTES_CACHE', $_SERVER) || env('APP_ROUTES_CACHE') !== null
        || $app->routesAreCached()) throw new \RuntimeException();
    $url = parse_url((string) config('app.url'));
    if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
        || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        || !in_array($url['path'] ?? '', ['', '/'], true)
        || !preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $url['host'])
        || strpos($url['host'], '..') !== false || strpos($url['host'], '.') === false
        || (isset($url['port']) && $url['port'] !== 443)) throw new \RuntimeException();
    $origin = 'https://'.strtolower($url['host']);
    if (!is_string(config('meta_messaging.app_secret')) || config('meta_messaging.app_secret') === '')
        throw new \RuntimeException();
    $plain = 'meta-oauth-crypt-readiness';
    if (\Illuminate\Support\Facades\Crypt::decryptString(
        \Illuminate\Support\Facades\Crypt::encryptString($plain)) !== $plain) throw new \RuntimeException();
    $result = ['ok' => true, 'origin' => $origin, 'app_secret_present' => true,
        'route_cache_override' => false, 'routes_cached' => false]; $exitCode = 0;
} catch (\Throwable $error) {}
while (ob_get_level() > $level) ob_end_clean();
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit($exitCode);
'''

RUNTIME_VERIFY = br'''<?php
ini_set('display_errors', '0'); ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level = ob_get_level(); ob_start(); $result = ['ok' => false]; $exitCode = 1;
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (getenv('APP_ROUTES_CACHE') !== false || array_key_exists('APP_ROUTES_CACHE', $_ENV)
        || array_key_exists('APP_ROUTES_CACHE', $_SERVER) || env('APP_ROUTES_CACHE') !== null
        || $app->routesAreCached()) throw new \RuntimeException();
    $settings = $app->make(\App\Services\Dashboard\MetaOAuthCredentials::class)->settings();
    $expected = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($settings) || !is_array($expected)) throw new \RuntimeException();
    ksort($settings); ksort($expected);
    if ($settings !== $expected) throw new \RuntimeException();
    $routes = $app['router']->getRoutes();
    foreach ([
        'index' => ['admin/meta/connection', 'GET', 'index'],
        'start' => ['admin/meta/connection/start', 'POST', 'start'],
        'callback' => ['admin/meta/connection/callback', 'GET', 'callback'],
        'confirm' => ['admin/meta/connection/confirm', 'POST', 'confirm'],
    ] as $name => [$uri, $method, $action]) {
        $route = $routes->getByName('meta-connection.'.$name);
        if (!$route || $route->uri() !== $uri || !in_array($method, $route->methods(), true)
            || $route->getActionName() !== \App\Http\Controllers\Dashboard\MetaConnectionController::class.'@'.$action)
            throw new \RuntimeException();
        $actualMethods = $route->methods(); $expectedMethods = $method === 'GET' ? ['GET', 'HEAD'] : ['POST'];
        sort($actualMethods); sort($expectedMethods);
        if ($actualMethods !== $expectedMethods || !(new \ReflectionMethod(
            \App\Http\Controllers\Dashboard\MetaConnectionController::class, $action))->isPublic()) throw new \RuntimeException();
        $middleware = $route->gatherMiddleware();
        if (!in_array('web', $middleware, true) || !in_array('lang', $middleware, true)
            || !in_array('IsAdmin', $middleware, true)) throw new \RuntimeException();
        if (array_intersect($route->excludedMiddleware(), ['web', 'lang', 'IsAdmin',
            \App\Http\Middleware\MetaOAuthCallbackPrivacy::class, \App\Http\Middleware\IsAdmin::class,
            \App\Http\Middleware\lang::class, \App\Http\Middleware\VerifyCsrfToken::class,
            'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken', 'Illuminate\\Session\\Middleware\\StartSession']))
            throw new \RuntimeException();
        if ($name === 'callback') {
            $privacy = array_search(\App\Http\Middleware\MetaOAuthCallbackPrivacy::class, $middleware, true);
            $admin = array_search('IsAdmin', $middleware, true); $lang = array_search('lang', $middleware, true);
            if ($privacy === false || $privacy > $admin || $privacy > $lang) throw new \RuntimeException();
        }
        if ($routes->match(\Illuminate\Http\Request::create('/'.$uri, $method))->getName() !== 'meta-connection.'.$name)
            throw new \RuntimeException();
    }
    foreach (['resources/views/admin/meta/connection.blade.php', 'resources/views/admin/meta/index.blade.php'] as $view) {
        $compiled = $app['blade.compiler']->compileString(file_get_contents($argv[1].'/'.$view));
        token_get_all($compiled, TOKEN_PARSE);
    }
    $result = ['ok' => true, 'verified_routes' => 4, 'views_compiled' => 2]; $exitCode = 0;
} catch (\Throwable $error) {}
while (ob_get_level() > $level) ob_end_clean();
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit($exitCode);
'''


def application_uid():
    uid = os.geteuid()
    if uid == 0 or pwd.getpwuid(uid).pw_name != 'fasakha':
        raise RuntimeError('RUN_AS_FASAKHA')
    return uid


def run(args, root):
    return subprocess.run(args, cwd=root, capture_output=True, check=False, timeout=60)


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def safe_path(path):
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
        raise RuntimeError('SYMLINK_TARGET')


def snapshot(path, uid, mode=None, owner=None):
    safe_path(path)
    if not path.exists(): return None
    before = path.lstat()
    if not stat.S_ISREG(before.st_mode): raise RuntimeError('NON_FILE_TARGET')
    if before.st_nlink != 1: raise RuntimeError('HARDLINK_TARGET')
    if before.st_uid != (uid if owner is None else owner): raise RuntimeError('FOREIGN_FILE_OWNER')
    permissions = stat.S_IMODE(before.st_mode)
    if permissions & 0o7000 or permissions & 0o002 or (mode is not None and permissions != mode):
        raise RuntimeError('UNSAFE_FILE_MODE')
    with path.open('rb') as stream:
        opened = os.fstat(stream.fileno())
        if (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino):
            raise RuntimeError('CHANGED_DURING_READ')
        content = stream.read(2 * 1024 * 1024 + 1)
        after = os.fstat(stream.fileno())
    identity = lambda value: (value.st_dev, value.st_ino, value.st_uid, value.st_gid, value.st_nlink,
                              value.st_mode, value.st_size, value.st_mtime_ns, value.st_ctime_ns)
    if len(content) > 2 * 1024 * 1024 or identity(before) != identity(after) or path.lstat() != after:
        raise RuntimeError('CHANGED_DURING_READ')
    return {'content': content, 'identity': identity(after), 'mode': permissions}


def directory(path, uid, private=False, required=True):
    safe_path(path)
    if not path.exists():
        if required: raise RuntimeError('REQUIRED_DIRECTORY_MISSING')
        return None
    metadata = path.lstat()
    permissions = stat.S_IMODE(metadata.st_mode)
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in (uid, 0) or permissions & 0o002:
        raise RuntimeError('UNSAFE_DIRECTORY')
    if private and (metadata.st_uid != uid or permissions != 0o700):
        raise RuntimeError('UNSAFE_PRIVATE_DIRECTORY')
    return (metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid, metadata.st_mode)


def atomic(path, content, mode):
    safe_path(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.meta-oauth-install-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content); stream.flush(); os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)


def backup_file(path, content):
    path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
    # A newly-created backup tree contains only app-owned private artifacts.
    for parent in path.parents:
        if parent.name == 'meta-release-backups': break
        os.chmod(parent, 0o700)
    atomic(path, content, 0o600)


def git_file(root, release, rel):
    result = run(['git', 'show', release + ':' + rel], root)
    if result.returncode != 0: raise RuntimeError('RELEASE_FILE_UNAVAILABLE: ' + rel)
    if not result.stdout or len(result.stdout) > 2 * 1024 * 1024:
        raise RuntimeError('INVALID_RELEASE_FILE: ' + rel)
    return result.stdout


def json_probe(args, root, expected=None):
    result = run(args, root)
    try: decoded = json.loads(result.stdout)
    except (ValueError, TypeError): raise RuntimeError('SANITIZED_RUNTIME_PROBE_FAILED')
    if result.returncode != 0 or not isinstance(decoded, dict) or decoded.get('ok') is not True:
        raise RuntimeError('SANITIZED_RUNTIME_PROBE_FAILED')
    if expected is not None and (decoded != expected or type(decoded.get('verified_routes')) is not int
                                  or type(decoded.get('views_compiled')) is not int):
        raise RuntimeError('META_OAUTH_ROUTE_VERIFICATION_FAILED')
    return decoded


def settings_from_probe(probe):
    if set(probe) != {'ok', 'origin', 'app_secret_present', 'route_cache_override', 'routes_cached'} \
        or probe.get('app_secret_present') is not True or probe.get('route_cache_override') is not False \
        or probe.get('routes_cached') is not False or not isinstance(probe.get('origin'), str):
        raise RuntimeError('INVALID_RUNTIME_READINESS')
    try: parsed = urlsplit(probe['origin'])
    except ValueError: raise RuntimeError('INVALID_RUNTIME_ORIGIN')
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password \
        or parsed.path or parsed.query or parsed.fragment \
        or not re.fullmatch(r'https://(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', probe['origin']) \
        or '..' in parsed.hostname or '.' not in parsed.hostname or parsed.port is not None:
        raise RuntimeError('INVALID_RUNTIME_ORIGIN')
    return {'app_id': APP_ID, 'config_id': CONFIG_ID,
            'redirect_uri': probe['origin'] + CALLBACK_PATH, 'api_version': API_VERSION}


def install(root, release, php):
    uid = application_uid()
    root = pathlib.Path(os.path.abspath(root)); safe_path(root); directory(root, uid)
    if not re.fullmatch('[a-f0-9]{40}', release): raise RuntimeError('INVALID_RELEASE')
    if 'APP_ROUTES_CACHE' in os.environ: raise RuntimeError('ROUTE_CACHE_OVERRIDE_BLOCKED')
    for rel in ('vendor/autoload.php', 'bootstrap/app.php', 'artisan'):
        safe_path(root / rel)
        if not (root / rel).is_file(): raise RuntimeError('APPLICATION_RUNTIME_MISSING')
    originals = {}; constraints = {}; parent_states = {}
    for rel, expected in FOUNDATION_BLOBS.items():
        owner = 0 if rel == 'app/Http/Kernel.php' else uid
        state = snapshot(root / rel, uid, owner=owner)
        if state is None or blob(state['content']) != expected: raise RuntimeError('META_AUTH_FOUNDATION_DIFFERS: ' + rel)
        originals[rel] = state; constraints[rel] = (None, owner)
    releases = {}
    for rel in PUBLISH_ORDER:
        parent_states[str((root / rel).parent)] = directory((root / rel).parent, uid)
        state = snapshot(root / rel, uid, mode=0o644)
        payload = git_file(root, release, rel)
        releases[rel] = payload
        if state is None:
            if rel in BASELINE_BLOBS: raise RuntimeError('REQUIRED_BASELINE_MISSING: ' + rel)
        elif state['content'] != payload and (rel not in BASELINE_BLOBS or blob(state['content']) != BASELINE_BLOBS[rel]):
            raise RuntimeError('UNEXPECTED_META_OAUTH_SOURCE: ' + rel)
        originals[rel] = state; constraints[rel] = (0o644, uid)
    for rel in ROUTE_CACHE_FILES:
        if snapshot(root / rel, uid) is not None: raise RuntimeError('CACHED_ROUTES_BLOCKED')
        originals[rel] = None; constraints[rel] = (None, uid)
    for rel in (SETTINGS_FILE, CREDENTIALS_FILE):
        originals[rel] = snapshot(root / rel, uid, mode=0o600); constraints[rel] = (0o600, uid)
    private = root / PRIVATE_DIR
    private_before = directory(private, uid, private=True, required=False)
    directory(root / 'storage/app', uid)
    private_parent_before = directory(private.parent, uid, required=False)
    backup_parent = root.parent / 'meta-release-backups'
    if backup_parent.exists(): directory(backup_parent, uid, private=True)
    else: safe_path(backup_parent); backup_parent.mkdir(mode=0o700)
    prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-oauth-' + release[:8] + '-'
    backup = pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=backup_parent)); os.chmod(backup, 0o700)
    for rel, payload in releases.items():
        staged = backup / 'staged' / rel; backup_file(staged, payload)
        if rel.endswith('.php') and not rel.endswith('.blade.php'):
            if run([php, '-l', str(staged)], root).returncode != 0:
                raise RuntimeError('STAGED_PHP_LINT_FAILED: ' + rel)
    for name, source in (('oauth-runtime-preflight.php', RUNTIME_PREFLIGHT), ('oauth-runtime-verify.php', RUNTIME_VERIFY)):
        path = backup / name; backup_file(path, source)
        if run([php, '-l', str(path)], root).returncode != 0: raise RuntimeError('RUNTIME_PROBE_LINT_FAILED')
    probe = json_probe([php, str(backup / 'oauth-runtime-preflight.php'), str(root)], root)
    settings = settings_from_probe(probe)
    settings_bytes = (json.dumps(settings, indent=2, ensure_ascii=True) + '\n').encode()
    old_settings = originals[SETTINGS_FILE]
    if old_settings is not None and old_settings['content'] != settings_bytes:
        raise RuntimeError('EXISTING_OAUTH_SETTINGS_DIFFER')
    # Save every affected original before the first application change.
    for rel in tuple(BASELINE_BLOBS) + NEW_FILES + (SETTINGS_FILE,):
        old = originals[rel]
        if old is not None: backup_file(backup / 'original' / rel, old['content'])
    backup_file(backup / 'manifest.json', (json.dumps({
        'release': release, 'targets': list(releases) + [SETTINGS_FILE],
        'prior_blobs': {rel: blob(originals[rel]['content']) if originals[rel] else None
                        for rel in tuple(releases) + (SETTINGS_FILE,)},
    }, indent=2) + '\n').encode())
    written = {}; created_dirs = []
    def recheck_all():
        for rel, old in originals.items():
            mode, owner = constraints[rel]
            if snapshot(root / rel, uid, mode=mode, owner=owner) != old:
                raise RuntimeError('CONCURRENT_EDIT: ' + rel)
        for path, old in parent_states.items():
            if directory(pathlib.Path(path), uid) != old: raise RuntimeError('CONCURRENT_DIRECTORY_EDIT')
        if directory(private, uid, private=True, required=False) != private_before \
            or directory(private.parent, uid, required=False) != private_parent_before:
            raise RuntimeError('CONCURRENT_PRIVATE_DIRECTORY_EDIT')
    try:
        recheck_all()
        for rel, payload in releases.items():
            old = originals[rel]
            if old is not None and old['content'] == payload: continue
            if snapshot(root / rel, uid, mode=0o644) != old: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
            atomic(root / rel, payload, 0o644)
            written[rel] = (old, None, payload, 0o644)
            published = snapshot(root / rel, uid, mode=0o644)
            if published is None or published['content'] != payload: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
            written[rel] = (old, published, payload, 0o644)
        # Connection activation is last, after all source files are published.
        if private_parent_before is None:
            safe_path(private.parent)
            private.parent.mkdir(mode=0o700); created_dirs.append(private.parent)
        if private_before is None:
            safe_path(private); private.mkdir(mode=0o700); created_dirs.append(private)
        if snapshot(root / SETTINGS_FILE, uid, mode=0o600) != old_settings:
            raise RuntimeError('CONCURRENT_EDIT: ' + SETTINGS_FILE)
        if old_settings is None:
            atomic(root / SETTINGS_FILE, settings_bytes, 0o600)
            written[SETTINGS_FILE] = (old_settings, None, settings_bytes, 0o600)
            published = snapshot(root / SETTINGS_FILE, uid, mode=0o600)
            if published is None or published['content'] != settings_bytes:
                raise RuntimeError('CONCURRENT_EDIT: ' + SETTINGS_FILE)
            written[SETTINGS_FILE] = (old_settings, published, settings_bytes, 0o600)
        expected_verify = {'ok': True, 'verified_routes': 4, 'views_compiled': 2}
        json_probe([php, str(backup / 'oauth-runtime-verify.php'), str(root), json.dumps(settings, separators=(',', ':'))],
                   root, expected_verify)
        for rel, old in originals.items():
            mode, owner = constraints[rel]
            expected = written[rel][1] if rel in written else old
            if snapshot(root / rel, uid, mode=mode, owner=owner) != expected:
                raise RuntimeError('CONCURRENT_EDIT: ' + rel)
        for path, old in parent_states.items():
            if directory(pathlib.Path(path), uid) != old: raise RuntimeError('CONCURRENT_DIRECTORY_EDIT')
        directory(private, uid, private=True)
    except Exception:
        restored = True
        for rel, (old, published, payload, mode) in reversed(list(written.items())):
            path = root / rel
            try:
                current = snapshot(path, uid, mode=mode)
                if current is None or current['content'] != payload or (published is not None and current != published):
                    restored = False; continue
                if old is None: path.unlink()
                else: atomic(path, old['content'], old['mode'])
            except (OSError, RuntimeError): restored = False
        for path in reversed(created_dirs):
            try: path.rmdir()
            except OSError: restored = False
        print(json.dumps({'status': 'FAILED', 'rollback': 'RESTORED' if restored else 'INCOMPLETE',
                          'backup': str(backup), 'graph_requests': 0}))
        raise
    report = {'status': 'READY', 'updated_files': len(written), 'verified_routes': 4, 'views_compiled': 2,
              'graph_requests': 0, 'database_writes': 0, 'backup': str(backup),
              'redirect_uri': settings['redirect_uri'], 'connect_url': probe['origin'] + '/admin/meta/connection'}
    print(json.dumps(report, indent=2))
    return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True); parser.add_argument('--release', required=True)
    parser.add_argument('--php', required=True)
    args = parser.parse_args()
    install(args.root, args.release, args.php)


if __name__ == '__main__':
    try: main()
    except (RuntimeError, OSError, subprocess.TimeoutExpired) as error:
        # Do not print captured Laravel, PHP, credential, or exception diagnostics.
        print('META_OAUTH_INSTALL_FAILED=' + (str(error) if isinstance(error, RuntimeError) else 'RUNTIME_OR_FILESYSTEM_FAILURE'))
        raise SystemExit(1)
