#!/usr/bin/env python3
"""Publish only the reviewed comments overlay and narrow live navigation changes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
MANIFEST_PATH = 'deployment/meta_comments_manifest.json'
MIGRATION = 'database/migrations/2026_10_14_210000_create_meta_comment_reply_requests.php'
SIDEBAR = 'resources/views/admin/meta/sidebar.blade.php'
WEB_ROUTES = 'routes/web.php'
NAVIGATION = 'public/dashboard/js/dashboard-navigation.js'
SOURCE_FILES = (
    'app/Services/Dashboard/MetaCommentsFailure.php',
    'app/Services/Dashboard/MetaCommentsService.php',
    'app/Http/Controllers/Dashboard/MetaCommentsController.php',
    MIGRATION,
    'deployment/meta_comments_diagnostic.php',
    'public/js/dashboard-meta-comments.js',
    'public/css/dashboard-meta-comments.css',
    'resources/views/admin/meta/comments.blade.php',
    'routes/meta_comments.php',
    SIDEBAR,
)
SIDEBAR_BASELINE = 'cb321326b61eeebb2f8c1276e7b1be217c273d51'
FOUNDATION_PINS = {
    'app/Services/Dashboard/WhatsAppInboxAccess.php': '186789d42113e81818de8d82a2327e3ee9d8d0b5',
    'app/Services/Dashboard/MetaInboxAccess.php': '7faaba6645308c1997bae8182fb4b26dbc5e3b9a',
    'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484',
}
ROUTE_CACHE_FILES = ('bootstrap/cache/routes-v7.php', 'bootstrap/cache/routes.php')
NAV_MARKER = '// BEGIN Meta comments sidebar ordering v1'
NAV_END = '// END Meta comments sidebar ordering v1'
NAV_BLOCK = r'''// BEGIN Meta comments sidebar ordering v1
(() => {
    const placeComments = () => {
        const links = Array.from(document.querySelectorAll('a[href]'));
        const at = path => links.filter(link => {
            try { return new URL(link.href, window.location.href).pathname.replace(/\/$/, '') === path; }
            catch (_) { return false; }
        });
        const comments = at('/admin/meta/comments');
        const inbox = at('/admin/meta');
        if (comments.length !== 1 || inbox.length !== 1) return;
        const item = comments[0].closest('li');
        const after = inbox[0].closest('li');
        if (item && after && item !== after && item.parentElement === after.parentElement)
            after.insertAdjacentElement('afterend', item);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', placeComments, { once: true });
    else placeComments();
})();
// END Meta comments sidebar ordering v1
'''


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def safe(path):
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
        raise RuntimeError('SYMLINK_TARGET')


def identity(metadata):
    return (metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid,
            metadata.st_mode, metadata.st_nlink, metadata.st_size,
            metadata.st_mtime_ns, metadata.st_ctime_ns)


def snapshot(path, uid, allow_hardlinks=False):
    safe(path)
    if not path.exists():
        return None
    before = path.lstat()
    if not stat.S_ISREG(before.st_mode) or before.st_uid not in (0, uid):
        raise RuntimeError('UNSAFE_FILE_OWNER_OR_TYPE')
    if not allow_hardlinks and before.st_nlink != 1:
        raise RuntimeError('HARDLINK_TARGET')
    mode = stat.S_IMODE(before.st_mode)
    if mode & 0o7022:
        raise RuntimeError('UNSAFE_FILE_MODE')
    with path.open('rb') as stream:
        if identity(os.fstat(stream.fileno())) != identity(before):
            raise RuntimeError('CONCURRENT_READ')
        contents = stream.read(2 * 1024 * 1024 + 1)
        after = os.fstat(stream.fileno())
    if len(contents) > 2 * 1024 * 1024 or identity(before) != identity(after) \
            or identity(path.lstat()) != identity(after):
        raise RuntimeError('CONCURRENT_READ')
    return {'bytes': contents, 'identity': identity(after), 'mode': mode,
            'uid': after.st_uid, 'gid': after.st_gid}


def directory(path, uid, required=True):
    safe(path)
    if not path.exists():
        if required:
            raise RuntimeError('REQUIRED_DIRECTORY_MISSING')
        return None
    metadata = path.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in (0, uid) \
            or stat.S_IMODE(metadata.st_mode) & 0o7022:
        raise RuntimeError('UNSAFE_DIRECTORY')
    return (metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid, metadata.st_mode)


def atomic(path, contents, mode, uid, gid):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.meta-comments-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(contents)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def tree_directory(path, top, mode):
    """Set traversable staging parents even when the root command uses umask 077."""
    path.mkdir(mode=mode, parents=True, exist_ok=True)
    for candidate in (path, *path.parents):
        if candidate == top:
            candidate.chmod(mode)
            break
        if top not in candidate.parents:
            raise RuntimeError('PRIVATE_TREE_ESCAPE')
        candidate.chmod(mode)


def run(args, root, timeout=60):
    return subprocess.run(args, cwd=root, capture_output=True, check=False, timeout=timeout)


def git_file(root, release, relative):
    result = run(['runuser', '-u', 'fasakha', '--', 'git', '-C', str(root),
                  'show', release + ':' + relative], root)
    if result.returncode or not result.stdout or len(result.stdout) > 2 * 1024 * 1024:
        raise RuntimeError('RELEASE_FILE_UNAVAILABLE: ' + relative)
    return result.stdout


def manifest_payload(root, release, provided):
    expected = git_file(root, release, MANIFEST_PATH)
    if provided != expected:
        raise RuntimeError('RELEASE_MANIFEST_DIFFERS')
    try:
        parsed = json.loads(provided)
    except (ValueError, TypeError):
        raise RuntimeError('INVALID_RELEASE_MANIFEST')
    if not isinstance(parsed, dict) or set(parsed) != {'version', 'files'} \
            or type(parsed.get('version')) is not int or parsed['version'] != 1 \
            or not isinstance(parsed['files'], dict) or set(parsed['files']) != set(SOURCE_FILES) \
            or any(not isinstance(pin, str) or not re.fullmatch('[a-f0-9]{40}', pin)
                   for pin in parsed['files'].values()):
        raise RuntimeError('INVALID_RELEASE_MANIFEST')
    return parsed['files']


def web_contents(source):
    """Keep every existing route registration and append the comments include once."""
    if not source.startswith('<?php') or 'meta_inbox' not in source or '?>' in source:
        raise RuntimeError('WEB_ROUTE_ANCHOR_REQUIRED')
    if 'meta_comments' in source:
        direct = re.compile(r"(?m)^require __DIR__\s*\.\s*(['\"])/meta_comments\.php\1;[ \t]*\r?$")
        loop = re.compile(r"foreach\s*\(\s*\[([^\]]*)\]\s+as\s+\$metaRoutes\s*\)\s*\{\s*"
                          r"require\s+__DIR__\s*\.\s*(['\"])/\2\s*\.\s*\$metaRoutes\s*\.\s*(['\"])\.php\3\s*;\s*\}")
        direct_count = len(direct.findall(source))
        loop_count = sum(len(re.findall(r"(['\"])meta_comments\1", match[1]))
                         for match in loop.finditer(source))
        if source.count('meta_comments') != 1 or direct_count + loop_count != 1:
            raise RuntimeError('COMMENTS_ROUTE_REGISTRATION_AMBIGUOUS')
        return source
    newline = '\r\n' if '\r\n' in source else '\n'
    return source + ('' if source.endswith('\n') else newline) + newline \
        + "require __DIR__.'/meta_comments.php';" + newline


def navigation_contents(source):
    """Keep existing inbox ordering and preserve unknown native route customizations."""
    priority = re.compile(r"(const priority = node => paths\(node\)\.some\(path => /\\/admin\\/\()"
                          r"([a-z0-9|\\/-]+)(\)\\/\?\$/\.test\(path\)\);)")
    matches = list(priority.finditer(source))
    if len(matches) != 1 or source.count('const priority') != 1:
        raise RuntimeError('NATIVE_PRIORITY_ANCHOR_REQUIRED')
    match = matches[0]
    routes = match[2].split('|')
    if routes.count('meta') != 1 or routes.count('dashboard') != 1 \
            or routes.count(r'meta\/comments') > 1:
        raise RuntimeError('NATIVE_PRIORITY_ROUTES_AMBIGUOUS')
    if r'meta\/comments' not in routes:
        routes.insert(routes.index('meta') + 1, r'meta\/comments')
        source = source[:match.start(2)] + '|'.join(routes) + source[match.end(2):]
    newline = '\r\n' if '\r\n' in source else '\n'
    block = NAV_BLOCK.replace('\n', newline)
    if NAV_MARKER in source or NAV_END in source:
        if source.count(NAV_MARKER) != 1 or source.count(NAV_END) != 1 or block not in source:
            raise RuntimeError('NATIVE_COMMENTS_ORDERING_DIFFERS')
        return source
    return source + ('' if source.endswith('\n') else newline) + newline + block


RUNTIME_PROBE = br'''<?php
ini_set('display_errors', '0'); ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level = ob_get_level(); ob_start(); $result = ['ok' => false]; $exit = 1;
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (getenv('APP_ROUTES_CACHE') !== false || env('APP_ROUTES_CACHE') !== null || $app->routesAreCached())
        throw new \RuntimeException('ROUTES_CACHED');
    if (config('desktop_dashboard.local', false)) throw new \RuntimeException('LIVE_SERVER_REQUIRED');
    $access = $app->make(\App\Services\Dashboard\MetaInboxAccess::class);
    $owner = \App\Models\User::find(1);
    if (!$owner || !$access->canAccess($owner) || !$access->available())
        throw new \RuntimeException('CENTRAL_INBOX_NOT_READY');
    $accounts = \App\Services\Dashboard\MetaInboxAccess::accounts();
    $tokenCount = count(array_filter($accounts, static fn ($a) => is_string($a['access_token'] ?? null)
        && strlen($a['access_token']) >= 16 && strlen($a['access_token']) <= 4096));
    if (count($accounts) === 0 || $tokenCount === 0) throw new \RuntimeException('META_ACCOUNTS_NOT_READY');
    if (\Illuminate\Support\Facades\Crypt::decryptString(
        \Illuminate\Support\Facades\Crypt::encryptString('meta-comments-readiness')) !== 'meta-comments-readiness')
        throw new \RuntimeException('ENCRYPTION_NOT_READY');
    $columns = ['id','actor_id','account_key','target_hash','request_hash','payload_hash','pending_key',
        'state','reason','response_details','send_started_at','completed_at','created_at','updated_at'];
    $tableReady = \Illuminate\Support\Facades\Schema::hasTable('meta_comment_reply_requests');
    if ($tableReady && !\Illuminate\Support\Facades\Schema::hasColumns('meta_comment_reply_requests', $columns))
        throw new \RuntimeException('COMMENTS_TABLE_SCHEMA_DIFFERS');
    $views = $argv[2] === 'preflight'
        ? [$argv[3].'/resources/views/admin/meta/comments.blade.php', $argv[3].'/resources/views/admin/meta/sidebar.blade.php']
        : [$argv[1].'/resources/views/admin/meta/comments.blade.php', $argv[1].'/resources/views/admin/meta/sidebar.blade.php'];
    foreach ($views as $view) {
        $compiled = $app['blade.compiler']->compileString(file_get_contents($view));
        token_get_all($compiled, TOKEN_PARSE);
    }
    $verified = 0;
    if ($argv[2] === 'verify') {
        if (!$tableReady) throw new \RuntimeException('COMMENTS_TABLE_MISSING');
        $routes = $app['router']->getRoutes();
        foreach (['index'=>['admin/meta/comments','GET'], 'accounts'=>['admin/meta/comments/accounts','GET'],
            'posts'=>['admin/meta/comments/posts','GET'], 'comments'=>['admin/meta/comments/comments','GET'],
            'replies'=>['admin/meta/comments/replies','GET'], 'reply'=>['admin/meta/comments/reply','POST']] as $action=>[$uri,$method]) {
            $name = 'meta-comments.'.$action; $route = $routes->getByName($name);
            $methods = $route ? $route->methods() : []; sort($methods);
            $expected = $method === 'GET' ? ['GET', 'HEAD'] : ['POST']; sort($expected);
            if (!$route || $route->uri() !== $uri || $methods !== $expected
                || $route->getActionName() !== \App\Http\Controllers\Dashboard\MetaCommentsController::class.'@'.$action
                || !(new \ReflectionMethod(\App\Http\Controllers\Dashboard\MetaCommentsController::class, $action))->isPublic())
                throw new \RuntimeException('COMMENTS_ROUTE_INVALID');
            $middleware = $route->gatherMiddleware();
            if (!in_array('web', $middleware, true) || !in_array('lang', $middleware, true)
                || !in_array('IsAdmin', $middleware, true) || count($route->excludedMiddleware()) !== 0
                || $routes->match(\Illuminate\Http\Request::create('/'.$uri, $method))->getName() !== $name)
                throw new \RuntimeException('COMMENTS_ROUTE_MIDDLEWARE_INVALID');
            $verified++;
        }
        $service = $app->make(\App\Services\Dashboard\MetaCommentsService::class);
        if (!is_array($service->accounts($owner))) throw new \RuntimeException('COMMENTS_SERVICE_INVALID');
    }
    $result = ['ok'=>true, 'configured_accounts'=>count($accounts), 'verified_routes'=>$verified, 'views_compiled'=>2];
    $exit = 0;
} catch (\Throwable $error) {
    $allowed = ['ROUTES_CACHED','LIVE_SERVER_REQUIRED','CENTRAL_INBOX_NOT_READY','META_ACCOUNTS_NOT_READY',
        'ENCRYPTION_NOT_READY','COMMENTS_TABLE_SCHEMA_DIFFERS','COMMENTS_TABLE_MISSING','COMMENTS_ROUTE_INVALID',
        'COMMENTS_ROUTE_MIDDLEWARE_INVALID','COMMENTS_SERVICE_INVALID'];
    $result = ['ok'=>false, 'reason'=>in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'RUNTIME_PROBE_FAILED'];
}
while (ob_get_level() > $level) ob_end_clean();
echo json_encode($result, JSON_UNESCAPED_SLASHES); exit($exit);
'''


def php_probe(script, phase, root, php, staged):
    result = run(['runuser', '-u', 'fasakha', '--', php, str(script), str(root), phase, str(staged)], root)
    try:
        decoded = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise RuntimeError('SANITIZED_RUNTIME_PROBE_FAILED')
    if result.returncode or not isinstance(decoded, dict) or decoded.get('ok') is not True:
        reason = decoded.get('reason') if isinstance(decoded, dict) else None
        allowed = {'ROUTES_CACHED', 'LIVE_SERVER_REQUIRED', 'CENTRAL_INBOX_NOT_READY', 'META_ACCOUNTS_NOT_READY',
                   'ENCRYPTION_NOT_READY', 'COMMENTS_TABLE_SCHEMA_DIFFERS', 'COMMENTS_TABLE_MISSING',
                   'COMMENTS_ROUTE_INVALID', 'COMMENTS_ROUTE_MIDDLEWARE_INVALID', 'COMMENTS_SERVICE_INVALID'}
        raise RuntimeError(reason if reason in allowed else 'SANITIZED_RUNTIME_PROBE_FAILED')
    expected_routes = 6 if phase == 'verify' else 0
    if set(decoded) != {'ok', 'configured_accounts', 'verified_routes', 'views_compiled'} \
            or type(decoded['configured_accounts']) is not int or decoded['configured_accounts'] < 1 \
            or type(decoded['verified_routes']) is not int or decoded['verified_routes'] != expected_routes \
            or type(decoded['views_compiled']) is not int or decoded['views_compiled'] != 2:
        raise RuntimeError('SANITIZED_RUNTIME_PROBE_FAILED')
    return decoded


def rollback(root, originals, written, uid):
    complete = True
    for relative, published in reversed(list(written.items())):
        try:
            if snapshot(root / relative, uid) != published:
                complete = False
                continue
            before = originals[relative]
            if before is None:
                (root / relative).unlink()
            else:
                atomic(root / relative, before['bytes'], before['mode'], before['uid'], before['gid'])
        except (OSError, RuntimeError):
            complete = False
    return complete


def install(root, release, php, manifest):
    root = pathlib.Path(os.path.abspath(root))
    if os.geteuid() != 0 or root != APP_ROOT or not re.fullmatch('[a-f0-9]{40}', release) or php != PHP:
        raise RuntimeError('FIXED_ROOT_RELEASE_REQUIRED')
    if 'APP_ROUTES_CACHE' in os.environ:
        raise RuntimeError('ROUTE_CACHE_OVERRIDE_BLOCKED')
    user = pwd.getpwnam('fasakha'); uid = user.pw_uid; gid = user.pw_gid
    directory(root, uid)
    if root.stat().st_uid != uid:
        raise RuntimeError('APPLICATION_OWNER_REQUIRED')
    lockpath = root.parent / '.meta-comments-update.lock'; safe(lockpath)
    descriptor = os.open(lockpath, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        lockinfo = os.fstat(descriptor)
        if not stat.S_ISREG(lockinfo.st_mode) or lockinfo.st_uid != 0 or lockinfo.st_nlink != 1 \
                or stat.S_IMODE(lockinfo.st_mode) != 0o600:
            raise RuntimeError('UPDATE_LOCK_UNSAFE')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('COMMENTS_INSTALL_ALREADY_RUNNING')
        return install_locked(root, release, php, manifest, uid, gid)
    finally:
        os.close(descriptor)


def install_locked(root, release, php, manifest, uid, gid):
    for relative in ('vendor/autoload.php', 'bootstrap/app.php', 'artisan'):
        safe(root / relative)
        if not (root / relative).is_file():
            raise RuntimeError('APPLICATION_RUNTIME_MISSING')
    pins = manifest_payload(root, release, manifest)
    foundations = {}
    for relative, pin in FOUNDATION_PINS.items():
        old = snapshot(root / relative, uid, allow_hardlinks=True)
        if old is None or blob(old['bytes']) != pin:
            raise RuntimeError('META_ACCESS_FOUNDATION_DIFFERS: ' + relative)
        foundations[relative] = old
    for relative in ROUTE_CACHE_FILES:
        if snapshot(root / relative, uid) is not None:
            raise RuntimeError('CACHED_ROUTES_BLOCKED')
        foundations[relative] = None
    menu = 'resources/views/admin/layouts/menu.blade.php'
    whatsapp = 'resources/views/admin/whatsapp/sidebar.blade.php'
    menu_state = snapshot(root / menu, uid, allow_hardlinks=True)
    whatsapp_state = snapshot(root / whatsapp, uid, allow_hardlinks=True)
    if menu_state is None or whatsapp_state is None:
        raise RuntimeError('INSTALLED_MENU_REQUIRED')
    if sum(value['bytes'].count(b'admin.meta.sidebar') for value in (menu_state, whatsapp_state)) != 1:
        raise RuntimeError('INSTALLED_META_MENU_LINK_REQUIRED')
    foundations[menu] = menu_state; foundations[whatsapp] = whatsapp_state
    targets = {}; originals = {}; parents = {}
    for relative in SOURCE_FILES:
        path = root / relative
        parents[str(path.parent)] = directory(path.parent, uid)
        payload = git_file(root, release, relative)
        if blob(payload) != pins[relative]:
            raise RuntimeError('RELEASE_SOURCE_PIN_DIFFERS: ' + relative)
        old = snapshot(path, uid)
        if relative == SIDEBAR and old is None:
            raise RuntimeError('INSTALLED_META_SIDEBAR_REQUIRED')
        if old is not None and old['bytes'] != payload \
                and not (relative == SIDEBAR and blob(old['bytes']) == SIDEBAR_BASELINE):
            raise RuntimeError('UNREVIEWED_COMMENTS_TARGET: ' + relative)
        targets[relative] = payload; originals[relative] = old
    for relative, transform in ((WEB_ROUTES, web_contents), (NAVIGATION, navigation_contents)):
        old = snapshot(root / relative, uid)
        if old is None:
            if relative == NAVIGATION:
                continue
            raise RuntimeError('LIVE_WEB_ROUTES_REQUIRED')
        parents[str((root / relative).parent)] = directory((root / relative).parent, uid)
        try:
            targets[relative] = transform(old['bytes'].decode('utf-8')).encode('utf-8')
        except UnicodeError:
            raise RuntimeError('SOURCE_UTF8_REQUIRED')
        originals[relative] = old
    # Hold native route activation and sidebar publication until dependencies and table exist.
    activation = ('routes/meta_comments.php', SIDEBAR, WEB_ROUTES, NAVIGATION)
    order = [relative for relative in SOURCE_FILES if relative not in activation] \
        + [relative for relative in activation if relative in targets]
    parent = root.parent / 'meta-release-backups'; safe(parent)
    if parent.exists():
        directory(parent, uid)
    else:
        parent.mkdir(mode=0o700)
    prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') \
        + '-comments-' + release[:8] + '-'
    backup = pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=parent)); os.chmod(backup, 0o700)
    # Source backups never contain credentials or unrelated server data.
    for relative, old in originals.items():
        if old is not None:
            saved = backup / 'original' / relative
            tree_directory(saved.parent, backup, 0o700)
            saved.write_bytes(old['bytes']); saved.chmod(0o600)
    saved_manifest = {'release': release, 'targets': order, 'table_left_on_rollback': 'meta_comment_reply_requests',
                      'prior_blobs': {relative: blob(old['bytes']) if old else None for relative, old in originals.items()}}
    (backup / 'manifest.json').write_text(json.dumps(saved_manifest, indent=2) + '\n')
    (backup / 'manifest.json').chmod(0o600)
    written = {}
    with tempfile.TemporaryDirectory(prefix='meta-comments-stage-', dir='/tmp') as temporary:
        staged = pathlib.Path(temporary); staged.chmod(0o755)
        for relative, payload in targets.items():
            path = staged / relative
            tree_directory(path.parent, staged, 0o755)
            path.write_bytes(payload); path.chmod(0o644)
            if relative.endswith('.php') and not relative.endswith('.blade.php'):
                if run(['runuser', '-u', 'fasakha', '--', php, '-l', str(path)], root).returncode:
                    raise RuntimeError('STAGED_PHP_LINT_FAILED: ' + relative)
        probe = staged / 'comments-runtime.php'; probe.write_bytes(RUNTIME_PROBE); probe.chmod(0o644)
        if run(['runuser', '-u', 'fasakha', '--', php, '-l', str(probe)], root).returncode:
            raise RuntimeError('RUNTIME_PROBE_LINT_FAILED')
        php_probe(probe, 'preflight', root, php, staged)

        def recheck():
            for relative, old in foundations.items():
                if snapshot(root / relative, uid, allow_hardlinks=True) != old:
                    raise RuntimeError('CONCURRENT_FOUNDATION_EDIT: ' + relative)
            for relative, old in originals.items():
                expected = written.get(relative, old)
                if snapshot(root / relative, uid) != expected:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            for path, prior in parents.items():
                if directory(pathlib.Path(path), uid) != prior:
                    raise RuntimeError('CONCURRENT_DIRECTORY_EDIT')

        def publish(relative):
            old = originals[relative]; payload = targets[relative]
            if old is not None and old['bytes'] == payload:
                return
            if snapshot(root / relative, uid) != old:
                raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            atomic(root / relative, payload, old['mode'] if old else 0o644,
                   old['uid'] if old else uid, old['gid'] if old else gid)
            # Record the write before verification so a subsequent failure can restore it.
            written[relative] = None
            published = snapshot(root / relative, uid)
            if published is None or published['bytes'] != payload:
                raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            written[relative] = published

        try:
            recheck()
            for relative in order:
                if relative in activation:
                    continue
                publish(relative)
            recheck()
            migration = run(['runuser', '-u', 'fasakha', '--', php, 'artisan', 'migrate',
                             '--path=' + MIGRATION, '--force', '--no-interaction'], root)
            if migration.returncode:
                raise RuntimeError('COMMENTS_MIGRATION_FAILED')
            recheck()
            for relative in activation:
                if relative in targets:
                    publish(relative)
            result = php_probe(probe, 'verify', root, php, staged)
            recheck()
        except BaseException:
            complete = rollback(root, originals, written, uid)
            print(json.dumps({'status': 'FAILED', 'rollback': 'RESTORED' if complete else 'NEEDS_REVIEW',
                              'backup': str(backup), 'table_left_on_rollback': 'meta_comment_reply_requests',
                              'graph_requests': 0}))
            raise
    report = {'status': 'META_COMMENTS_READY', 'updated_files': len(written), 'backup': str(backup),
              'graph_requests': 0, 'comments_url_path': '/admin/meta/comments', **result}
    print(json.dumps(report, indent=2)); return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True); parser.add_argument('--release', required=True)
    parser.add_argument('--php', default=PHP); parser.add_argument('--manifest', required=True)
    arguments = parser.parse_args()
    manifest = pathlib.Path(arguments.manifest).read_bytes()
    install(arguments.root, arguments.release, arguments.php, manifest)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.TimeoutExpired) as error:
        message = str(error) if isinstance(error, RuntimeError) else 'RUNTIME_OR_FILESYSTEM_FAILURE'
        print('META_COMMENTS_INSTALL_FAILED=' + message)
        raise SystemExit(1)
