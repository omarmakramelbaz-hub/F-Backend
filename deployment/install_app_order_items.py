#!/usr/bin/env python3
"""Install only the reviewed application-order item editor, preserving live customizations."""
import argparse
import difflib
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
BASE = '4a81f073c134d762fdb31afa8e5dda99f978192e'
# Existing files are patched against this base; they are never wholesale replaced.
EXISTING_FILES = (
    'app/Models/Order.php',
    'app/Http/Controllers/Dashboard/OrderBoardController.php',
    'app/Services/Dashboard/OrderBoardService.php',
    'app/Services/Dashboard/TakeawayCatalog.php',
    'app/Http/Controllers/Api/V1/User/CartController.php',
    'app/Http/Resources/Api/User/CartResource.php',
    'routes/order_board.php',
    'resources/views/admin/orders/board.blade.php',
    'resources/views/admin/orders/board_card.blade.php',
    'resources/lang/ar/order_board.php',
    'resources/lang/en/order_board.php',
)
MIGRATION = 'database/migrations/2026_10_14_190000_preserve_app_order_item_weights.php'
NEW_FILES = (
    MIGRATION,
    'app/Services/Dashboard/OrderBoardItems.php',
    'resources/views/admin/orders/board_items.blade.php',
    'public/dashboard/js/order-board-items.js',
    'public/dashboard/css/order-board-items.css',
)
# Dependencies become available before any existing request path refers to them.
PUBLISH_ORDER = (
    *NEW_FILES,
    'app/Models/Order.php',
    'app/Http/Controllers/Api/V1/User/CartController.php',
    'app/Http/Resources/Api/User/CartResource.php',
    'app/Services/Dashboard/TakeawayCatalog.php',
    'app/Http/Controllers/Dashboard/OrderBoardController.php',
    'routes/order_board.php',
    'app/Services/Dashboard/OrderBoardService.php',
    'resources/lang/ar/order_board.php',
    'resources/lang/en/order_board.php',
    'resources/views/admin/orders/board_card.blade.php',
    'resources/views/admin/orders/board.blade.php',
)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def matches(lines, block, at_start=False, at_end=False):
    if not block:
        return []
    size = len(block)
    return [i for i in range(len(lines) - size + 1)
            if (not at_start or i == 0) and (not at_end or i + size == len(lines))
            and lines[i:i + size] == block]


def surgical_patch(current, baseline, reviewed, label):
    """Apply unique eight-line-context edits; reject conflicts and ambiguous anchors."""
    if baseline == reviewed:
        raise RuntimeError('RELEASE_CHANGE_MISSING: ' + label)
    if current == reviewed:
        return current
    old = baseline.splitlines(keepends=True)
    new = reviewed.splitlines(keepends=True)
    live = current.splitlines(keepends=True)
    groups = difflib.SequenceMatcher(a=old, b=new, autojunk=False).get_grouped_opcodes(8)
    for group in groups:
        a0, a1 = group[0][1], group[-1][2]
        b0, b1 = group[0][3], group[-1][4]
        before, after = old[a0:a1], new[b0:b1]
        start, end = a0 == 0, a1 == len(old)
        pre = matches(live, before, start, end)
        post = matches(live, after, start, end)
        # A boundary insertion can contain its old context; prefer the larger exact block.
        if len(post) == 1 and (not pre or (len(pre) == 1 and len(after) > len(before)
                and post[0] <= pre[0] and pre[0] + len(before) <= post[0] + len(after))):
            continue
        if len(pre) == 1 and (not post or (len(post) == 1 and len(before) > len(after)
                and pre[0] <= post[0] and post[0] + len(after) <= pre[0] + len(before))):
            live[pre[0]:pre[0] + len(before)] = after
            continue
        raise RuntimeError('SOURCE_PATCH_CONFLICT: ' + label)
    return b''.join(live)


def safe(path):
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise RuntimeError('SYMLINK_PATH: ' + str(path))


def snapshot(path, uid):
    safe(path)
    if not path.exists():
        return None
    meta = path.stat()
    if not stat.S_ISREG(meta.st_mode) or meta.st_uid not in (0, uid) or meta.st_mode & 0o7022:
        raise RuntimeError('UNSAFE_FILE: ' + str(path))
    return {'bytes': path.read_bytes(), 'uid': meta.st_uid, 'gid': meta.st_gid,
            'mode': stat.S_IMODE(meta.st_mode), 'device': meta.st_dev, 'inode': meta.st_ino,
            'mtime': meta.st_mtime_ns, 'links': meta.st_nlink}


def atomic(path, data, mode, uid, gid):
    safe(path)
    if not path.parent.is_dir():
        raise RuntimeError('PARENT_DIRECTORY_MISSING: ' + str(path.parent))
    fd, temporary = tempfile.mkstemp(prefix='.app-order-items-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def owned_command(root, arguments):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', *arguments],
                            cwd=root, capture_output=True)
    if result.returncode:
        action = ' '.join(arguments[1:3]) if len(arguments) > 1 and arguments[1] == 'artisan' else pathlib.Path(arguments[0]).name
        raise RuntimeError('APPLICATION_COMMAND_FAILED: ' + action)
    return result.stdout


def source(root, revision, relative):
    return owned_command(root, ['git', '-C', str(root), 'show', revision + ':' + relative])


PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level=ob_get_level();ob_start();$exit=1;$result=['ok'=>false];
try {
 require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $cached=$app->getCachedRoutesPath();
 if (!in_array($cached,[$argv[1].'/bootstrap/cache/routes.php',$argv[1].'/bootstrap/cache/routes-v7.php'],true)) throw new \RuntimeException('ROUTE_CACHE_PATH_UNSUPPORTED');
 if (!\Illuminate\Support\Facades\Schema::hasTable('carts') || !\Illuminate\Support\Facades\Schema::hasColumn('carts','qty')) throw new \RuntimeException('CART_QUANTITY_MISSING');
 if ($argv[2]==='verify') {
  $connection=\Illuminate\Support\Facades\DB::connection();
  if ($connection->getDriverName()==='mysql') {
   $columns=$connection->select("SHOW COLUMNS FROM `".str_replace('`','``',$connection->getTablePrefix().'carts')."` LIKE 'qty'");
   $type=strtolower((string)($columns[0]->Type??''));
   $fractional=preg_match('/^(float|double)(?:\(| |$)/',$type);
   if (preg_match('/^(decimal|numeric)\(\d+,(\d+)\)/',$type,$match)) $fractional=(int)$match[2]>=3;
   if (!$fractional) throw new \RuntimeException('CART_QUANTITY_NOT_FRACTIONAL');
  }
  $routes=$app['router']->getRoutes();
  foreach (['order-board.items'=>['GET','admin/order-board/{source}/{id}/items'],'order-board.items.save'=>['POST','admin/order-board/{source}/{id}/items']] as $name=>[$method,$uri]) {
   $route=$routes->getByName($name);
   if (!$route || $route->uri()!==$uri || !in_array($method,$route->methods(),true) || !in_array('IsAdmin',$route->gatherMiddleware(),true)) throw new \RuntimeException('ORDER_ITEMS_ROUTE_INVALID');
  }
  $app->make(\App\Services\Dashboard\OrderBoardItems::class);
 }
 for ($i=3;$i<count($argv);$i++) {
  $code=$app['blade.compiler']->compileString(file_get_contents($argv[$i]));token_get_all($code,TOKEN_PARSE);
 }
 $result=['ok'=>true,'routes_cached'=>$app->routesAreCached(),'route_cache'=>substr($cached,strlen($argv[1])+1)];$exit=0;
} catch (\Throwable $error) {
 $known=['ROUTE_CACHE_PATH_UNSUPPORTED','CART_QUANTITY_MISSING','CART_QUANTITY_NOT_FRACTIONAL','ORDER_ITEMS_ROUTE_INVALID'];
 $result=['ok'=>false,'reason'=>in_array($error->getMessage(),$known,true)?$error->getMessage():'ORDER_ITEMS_RUNTIME_FAILED'];
}
while(ob_get_level()>$level)ob_end_clean();echo json_encode($result,JSON_UNESCAPED_SLASHES);exit($exit);
'''


def runtime(root, php, probe, phase, views=()):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', php, str(probe), str(root), phase, *map(str, views)],
                            cwd=root, capture_output=True)
    try:
        data = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise RuntimeError('ORDER_ITEMS_RUNTIME_FAILED')
    if result.returncode or data.get('ok') is not True:
        raise RuntimeError(data.get('reason', 'ORDER_ITEMS_RUNTIME_FAILED'))
    return data


def install(root, release, php, check_only=False):
    if os.geteuid() != 0 or root != APP_ROOT or not re.fullmatch('[a-f0-9]{40}', release):
        raise RuntimeError('FIXED_ROOT_RELEASE_REQUIRED')
    safe(root)
    user = pwd.getpwnam('fasakha')
    uid, gid = user.pw_uid, user.pw_gid
    if root.stat().st_uid != uid:
        raise RuntimeError('APPLICATION_OWNER_REQUIRED')
    lock_path = root.parent / '.app-order-items-update.lock'
    safe(lock_path)
    lock = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        lock_info = os.fstat(lock)
        if not stat.S_ISREG(lock_info.st_mode) or lock_info.st_uid != 0 or lock_info.st_nlink != 1 or stat.S_IMODE(lock_info.st_mode) != 0o600:
            raise RuntimeError('UPDATE_LOCK_UNSAFE')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        _install_locked(root, release, php, check_only, uid, gid)
    finally:
        os.close(lock)


def _install_locked(root, release, php, check_only, uid, gid):
    with tempfile.TemporaryDirectory(prefix='app-order-items-', dir='/tmp') as temporary:
        stage = pathlib.Path(temporary)
        os.chmod(stage, 0o755)
        probe = stage / 'runtime.php'
        probe.write_text(PROBE)
        os.chmod(probe, 0o644)
        originals, targets, staged_views = {}, {}, []
        for relative in (*EXISTING_FILES, *NEW_FILES):
            path = root / relative
            old = snapshot(path, uid)
            reviewed = source(root, release, relative)
            if relative in EXISTING_FILES:
                if old is None:
                    raise RuntimeError('BASELINE_FILE_MISSING: ' + relative)
                payload = surgical_patch(old['bytes'], source(root, BASE, relative), reviewed, relative)
            else:
                if old and old['bytes'] != reviewed:
                    raise RuntimeError('NEW_FILE_COLLISION: ' + relative)
                payload = reviewed
            originals[relative], targets[relative] = old, payload
            checked = stage / relative
            checked.parent.mkdir(parents=True, exist_ok=True)
            checked.write_bytes(payload)
            os.chmod(checked, 0o644)
            for directory in checked.parents:
                os.chmod(directory, 0o755)
                if directory == stage:
                    break
            if relative.endswith('.blade.php'):
                staged_views.append(checked)
            elif relative.endswith('.php'):
                owned_command(root, [php, '-l', str(checked)])
        # Staged directory traversal is needed for the application owner to lint/compile.
        for path in stage.rglob('*'):
            if path.is_dir():
                os.chmod(path, 0o755)
        readiness = runtime(root, php, probe, 'preflight', staged_views)
        cache_relative = readiness['route_cache']
        cache_original = snapshot(root / cache_relative, uid)
        if check_only:
            print(json.dumps({'status': 'APP_ORDER_ITEMS_PREFLIGHT_OK', 'files': len(targets),
                              'routes_cached': readiness['routes_cached']}))
            return
        parent = root.parent / 'app-order-items-backups'
        safe(parent)
        if parent.exists() and (not parent.is_dir() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o077):
            raise RuntimeError('BACKUP_PARENT_UNSAFE')
        parent.mkdir(mode=0o700, exist_ok=True)
        backup = pathlib.Path(tempfile.mkdtemp(prefix=release[:12] + '-', dir=parent))
        os.chmod(backup, 0o700)
        manifest = {'release': release, 'baseline': BASE, 'files': {}}
        for relative, old in {**originals, cache_relative: cache_original}.items():
            manifest['files'][relative] = None if old is None else {k: v for k, v in old.items() if k != 'bytes'}
            if old:
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                saved.write_bytes(old['bytes'])
                os.chmod(saved, 0o600)
        (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
        os.chmod(backup / 'manifest.json', 0o600)
        written, runtime_changed = {}, False
        try:
            for relative, old in originals.items():
                if snapshot(root / relative, uid) != old:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            # Publish and run the additive widening before the editor is visible.
            for relative in PUBLISH_ORDER:
                payload, old = targets[relative], originals[relative]
                if snapshot(root / relative, uid) != old:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
                if old is None or old['bytes'] != payload:
                    atomic(root / relative, payload, old['mode'] if old else 0o644,
                           old['uid'] if old else uid, old['gid'] if old else gid)
                    written[relative] = snapshot(root / relative, uid)
                if relative == MIGRATION:
                    owned_command(root, [php, 'artisan', 'migrate', '--force', '--path=' + MIGRATION])
                if relative == 'routes/order_board.php':
                    if snapshot(root / cache_relative, uid) != cache_original:
                        raise RuntimeError('CONCURRENT_ROUTE_CACHE_EDIT')
                    # Route helpers in the service/views require the new route names.
                    runtime_changed = True
                    owned_command(root, [php, 'artisan', 'route:clear'])
            owned_command(root, [php, 'artisan', 'view:clear'])
            result = runtime(root, php, probe, 'verify')
            if readiness['routes_cached']:
                owned_command(root, [php, 'artisan', 'route:cache'])
                result = runtime(root, php, probe, 'verify')
            for relative, published in written.items():
                if snapshot(root / relative, uid) != published:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            print(json.dumps({'status': 'APP_ORDER_ITEMS_READY', 'updated_files': len(written),
                              'backup': str(backup), 'release': release, **result}))
        except BaseException:
            complete = True
            for relative, published in reversed(list(written.items())):
                if snapshot(root / relative, uid) != published:
                    complete = False
                    continue
                old = originals[relative]
                if old:
                    atomic(root / relative, old['bytes'], old['mode'], old['uid'], old['gid'])
                else:
                    (root / relative).unlink()
            if runtime_changed:
                if cache_original:
                    atomic(root / cache_relative, cache_original['bytes'], cache_original['mode'], cache_original['uid'], cache_original['gid'])
                elif (root / cache_relative).exists():
                    (root / cache_relative).unlink()
                try:
                    owned_command(root, [php, 'artisan', 'view:clear'])
                except RuntimeError:
                    complete = False
            print('APP_ORDER_ITEMS_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'))
            print('ADDITIVE_CART_QUANTITY_WIDENING_IS_PRESERVED')
            raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--php', default=PHP)
    parser.add_argument('--check', action='store_true', help='Lint and validate patch anchors without publishing files or migrating')
    args = parser.parse_args()
    try:
        install(pathlib.Path(args.root).absolute(), args.release, args.php, args.check)
    except Exception as error:
        print(str(error))
        raise SystemExit(1)
