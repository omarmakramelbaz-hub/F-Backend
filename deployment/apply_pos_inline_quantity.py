#!/usr/bin/env python3
"""Apply inline POS quantities, application print guards, and internal branding surgically."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile


BASE_REVISION = 'fe1d8499df06d72d3d482c18dc30792c043d7840'
APP_BASE_REVISION = '74a0070907d9ffbaa0f3b5aa7b6082bc1c345878'
BRAND_BASE_REVISION = '4d20f13dc3dd2b64d63660d09ad88553c7f3affe'
FOUNDATION_BASE_REVISION = '4a81f073c134d762fdb31afa8e5dda99f978192e'
FOUNDATION_REVISION = 'adc9e46a86df569bd6dab15e9f4302fc1c55aa91'
# Dependencies and the new script precede all consumers and the filemtime view.
SOURCE_MANIFEST = {
    'app/Services/Dashboard/OrderBoardPrinting.php': ('new', 'A'),
    'public/dashboard/js/order-board-detail.js': ('new', 'A'),
    'app/Services/Dashboard/OrderBoardItems.php': ('app', 'M'),
    'app/Services/Dashboard/OrderBoardService.php': ('composite', 'M'),
    'app/Http/Controllers/Dashboard/OrderBoardController.php': ('app', 'M'),
    'app/Http/Controllers/Dashboard/OrderController.php': ('live', 'M'),
    'public/dashboard/js/takeaway-pos.js': ('live', 'M'),
    'public/dashboard/js/phone-orders.js': ('live', 'M'),
    'public/dashboard/css/takeaway-pos.css': ('live', 'M'),
    'public/dashboard/branding/dashboard-brand.css': ('brand', 'M'),
    'resources/views/admin/partials/go_erp_logo.blade.php': ('brand', 'M'),
    'resources/views/admin/orders/board_detail.blade.php': ('live', 'M'),
}
BRAND_MENU = 'resources/views/admin/layouts/menu.blade.php'
FOUNDATION_MANIFEST = {
    'app/Models/Order.php': 'M',
    'app/Http/Controllers/Dashboard/OrderBoardController.php': 'M',
    'app/Services/Dashboard/OrderBoardService.php': 'M',
    'app/Services/Dashboard/TakeawayCatalog.php': 'M',
    'app/Http/Controllers/Api/V1/User/CartController.php': 'M',
    'app/Http/Resources/Api/User/CartResource.php': 'M',
    'routes/order_board.php': 'M',
    'resources/views/admin/orders/board.blade.php': 'M',
    'resources/views/admin/orders/board_card.blade.php': 'M',
    'resources/lang/ar/order_board.php': 'M',
    'resources/lang/en/order_board.php': 'M',
    'app/Services/Dashboard/OrderBoardItems.php': 'A',
    'resources/views/admin/orders/board_items.blade.php': 'A',
    'public/dashboard/js/order-board-items.js': 'A',
    'public/dashboard/css/order-board-items.css': 'A',
}
UPGRADE_PATHS = (
    'app/Services/Dashboard/OrderBoardItems.php',
    'public/dashboard/js/order-board-items.js',
    'public/dashboard/css/order-board-items.css',
    'resources/views/admin/orders/board_items.blade.php',
    'resources/views/admin/orders/board.blade.php',
    'resources/lang/ar/order_board.php',
    'resources/lang/en/order_board.php',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(command, cwd=None):
    return subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=120)


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for part in (path, *path.parents):
        require(not part.is_symlink(), 'SYMLINK_REJECTED: ' + str(part))


def content(path):
    safe(path)
    if not path.exists():
        return None
    require(path.is_file(), 'TARGET_NOT_REGULAR: ' + str(path))
    body = path.read_bytes()
    require(len(body) <= 4194304 and b'\x00' not in body, 'SOURCE_INVALID: ' + str(path))
    return body


def digest(body):
    return hashlib.sha256(body).hexdigest() if body is not None else None


def atomic(path, body, mode):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.pos-inline-quantity-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def git(root, *arguments):
    return run(['git', '-C', str(root), *arguments])


def patch_check(directory, patch, reverse=False):
    arguments = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        arguments.append('--reverse')
    return run([*arguments, str(patch)], cwd=directory).returncode == 0


def lint_php(php, directory):
    for relative in SOURCE_MANIFEST:
        if not relative.endswith('.php') or relative.endswith('.blade.php'):
            continue
        result = run([php, '-l', str(directory / relative)])
        require(result.returncode == 0, 'PHP_SYNTAX_INVALID: ' + relative)


def source(root, revision, relative, optional=False):
    tree = git(root, 'ls-tree', revision, '--', relative)
    require(tree.returncode == 0, 'BASE_SOURCE_UNAVAILABLE: ' + relative)
    if not tree.stdout:
        require(optional, 'REQUIRED_RELEASE_SOURCE_MISSING: ' + relative)
        return None
    metadata = tree.stdout.split(b'\t', 1)[0].split()
    require(len(metadata) == 3 and metadata[0] in (b'100644', b'100755')
            and metadata[1] == b'blob', 'RELEASE_SOURCE_NOT_REGULAR: ' + relative)
    result = git(root, 'show', revision + ':' + relative)
    require(result.returncode == 0 and len(result.stdout) <= 4194304
            and b'\x00' not in result.stdout, 'RELEASE_SOURCE_INVALID: ' + relative)
    return result.stdout


def commit_patch(root, before, after, relative):
    result = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--unified=3', before, after, '--', relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= 8388608
            and b'GIT binary patch' not in result.stdout and b'old mode ' not in result.stdout,
            'PREREQUISITE_PATCH_INVALID: ' + relative)
    return result.stdout


def make_patch(directory, relative, before, after):
    pairs = directory / 'patch-inputs'
    old = pairs / 'before' / relative
    new = pairs / 'after' / relative
    new.parent.mkdir(parents=True, exist_ok=True)
    new.write_bytes(after)
    if before is not None:
        old.parent.mkdir(parents=True, exist_ok=True)
        old.write_bytes(before)
    result = run(['git', 'diff', '--no-index', '--no-ext-diff', '--no-textconv', '--unified=3',
                  '--src-prefix=a/', '--dst-prefix=b/', '--',
                  'before/' + relative if before is not None else '/dev/null', 'after/' + relative], cwd=pairs)
    require(result.returncode == 1 and 0 < len(result.stdout) <= 8388608
            and b'GIT binary patch' not in result.stdout, 'RELEASE_CHANGE_MISSING: ' + relative)
    # Rewrite only the metadata headers; hunk content is never transformed.
    lines = []
    in_hunk = False
    for line in result.stdout.splitlines(keepends=True):
        if line.startswith(b'@@ '):
            in_hunk = True
        if not in_hunk and line.startswith(b'diff --git '):
            line = ('diff --git a/' + relative + ' b/' + relative + '\n').encode()
        elif not in_hunk and line.startswith(b'--- '):
            line = ('--- ' + ('a/' + relative if before is not None else '/dev/null') + '\n').encode()
        elif not in_hunk and line.startswith(b'+++ '):
            line = ('+++ b/' + relative + '\n').encode()
        lines.append(line)
    return b''.join(lines)


def baseline(root, relative, origin, stage):
    if origin == 'new':
        require(source(root, BASE_REVISION, relative, optional=True) is None,
                'NEW_SOURCE_ALREADY_TRACKED: ' + relative)
        return None
    revision = {'live': BASE_REVISION, 'app': APP_BASE_REVISION,
                'brand': BRAND_BASE_REVISION, 'composite': BASE_REVISION}.get(origin)
    require(revision is not None, 'BASELINE_ORIGIN_INVALID: ' + relative)
    body = source(root, revision, relative)
    if origin != 'composite':
        return body
    # fe1 OperatingDay behavior remains; only the previously installed editor
    # foundation is overlaid in temporary storage to reconstruct the live base.
    target = stage / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(body)
    patch = stage / 'composite.diff'
    patch.write_bytes(commit_patch(root, FOUNDATION_BASE_REVISION, FOUNDATION_REVISION, relative))
    require(patch_check(stage, patch), 'COMPOSITE_BASELINE_CONFLICT: ' + relative)
    require(run(['git', 'apply', '--whitespace=nowarn', str(patch)], cwd=stage).returncode == 0,
            'COMPOSITE_BASELINE_FAILED: ' + relative)
    return target.read_bytes()


def build_plan(root, revision):
    for commit in (APP_BASE_REVISION, BRAND_BASE_REVISION, FOUNDATION_BASE_REVISION, FOUNDATION_REVISION):
        resolved = git(root, 'rev-parse', '--verify', commit + '^{commit}')
        require(resolved.returncode == 0 and resolved.stdout.decode().strip() == commit,
                'PINNED_BASE_COMMIT_UNAVAILABLE')
    plan = {}
    with tempfile.TemporaryDirectory(prefix='inline-quantity-plan-') as temporary:
        stage = Path(temporary)
        for relative, (origin, status) in SOURCE_MANIFEST.items():
            before = baseline(root, relative, origin, stage / 'baseline')
            require(status in ('A', 'M') and (before is None) == (status == 'A'),
                    'SOURCE_STATUS_INVALID: ' + relative)
            after = source(root, revision, relative)
            require(after != before, 'RELEASE_CHANGE_MISSING: ' + relative)
            plan[relative] = {'status': status, 'patch': make_patch(stage, relative, before, after)}
    return plan


def prove_prerequisites(root, plan):
    with tempfile.TemporaryDirectory(prefix='inline-quantity-proof-') as temporary:
        stage = Path(temporary)
        paths = tuple(dict.fromkeys((*FOUNDATION_MANIFEST, *UPGRADE_PATHS, *SOURCE_MANIFEST, BRAND_MENU)))
        for relative in paths:
            body = content(root / relative)
            if body is None and relative in plan and plan[relative]['status'] == 'A':
                continue
            require(body is not None, 'INSTALLED_APP_EDITOR_REQUIRED: ' + relative)
            target = stage / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
        require(b'admin.partials.go_erp_logo' in (stage / BRAND_MENU).read_bytes(),
                'GO_ERP_MENU_INCLUDE_REQUIRED')
        for index, (relative, entry) in enumerate(plan.items()):
            patch = stage / ('current-' + str(index) + '.diff')
            patch.write_bytes(entry['patch'])
            if patch_check(stage, patch, reverse=True):
                require(run(['git', 'apply', '--reverse', '--whitespace=nowarn', str(patch)], cwd=stage).returncode == 0,
                        'FEATURE_NORMALIZATION_FAILED: ' + relative)
            else:
                require(patch_check(stage, patch), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
        # Undo the POS followup before checking the original editor: the shared
        # added files must be compared with their original foundation versions.
        for index, relative in enumerate(UPGRADE_PATHS):
            patch = stage / ('upgrade-' + str(index) + '.diff')
            patch.write_bytes(commit_patch(root, FOUNDATION_REVISION, APP_BASE_REVISION, relative))
            require(patch_check(stage, patch, reverse=True), 'APP_POS_PREREQUISITE_CONFLICT: ' + relative)
            require(run(['git', 'apply', '--reverse', '--whitespace=nowarn', str(patch)], cwd=stage).returncode == 0,
                    'APP_POS_PREREQUISITE_NORMALIZATION_FAILED: ' + relative)
        for index, relative in enumerate(FOUNDATION_MANIFEST):
            patch = stage / ('foundation-' + str(index) + '.diff')
            patch.write_bytes(commit_patch(root, FOUNDATION_BASE_REVISION, FOUNDATION_REVISION, relative))
            require(patch_check(stage, patch, reverse=True), 'APP_FOUNDATION_PREREQUISITE_CONFLICT: ' + relative)
    print('POS_INLINE_QUANTITY_PREREQUISITES_OK', flush=True)



PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level=ob_get_level();ob_start();$exit=1;$result=['ok'=>false,'reason'=>'RUNTIME_PROBE_FAILED'];
$known=['RUNTIME_PROBE_FAILED','ROUTE_CONTRACT_INVALID','ROUTE_MIDDLEWARE_INVALID',
 'PUBLIC_METHOD_REQUIRED','CART_QUANTITY_MISSING','CART_QUANTITY_NOT_FRACTIONAL',
 'PRINT_STORAGE_COLUMNS_MISSING','TRANSACTIONAL_TABLE_REQUIRED','DATABASE_DRIVER_UNSUPPORTED',
 'POS_CATALOG_STYLE_MISSING','BLADE_SYNTAX_INVALID','PRINT_POLICY_CLASS_REQUIRED'];
try {
 $root=$argv[1];$phase=$argv[2];
 require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $app->make(\Illuminate\Contracts\Http\Kernel::class);
 $router=$app['router'];$routes=$router->getRoutes();
 $controller='App\\Http\\Controllers\\Dashboard\\OrderBoardController';
 $contracts=[
  'order-board.items'=>['GET','admin/order-board/{source}/{id}/items',$controller.'@items'],
  'order-board.items.save'=>['POST','admin/order-board/{source}/{id}/items',$controller.'@saveItems'],
  'order-board.print'=>['GET','admin/order-board/{source}/{id}/print',$controller.'@print'],
  'print-pdf'=>['GET','admin/print-pdf','App\\Http\\Controllers\\Dashboard\\OrderController@print_fatora'],
  'download-pdf'=>['GET','admin/download-pdf','App\\Http\\Controllers\\Dashboard\\OrderController@download_fatora'],
 ];
 foreach($contracts as $name=>[$method,$uri,$action]) {
  $route=$routes->getByName($name);
  if(!$route || $route->uri()!==$uri || !in_array($method,$route->methods(),true)
    || ltrim($route->getActionName(),'\\')!==$action) throw new \RuntimeException('ROUTE_CONTRACT_INVALID');
  $middleware=$route->gatherMiddleware();$resolved=$router->gatherRouteMiddleware($route);
  if(!in_array('web',$middleware,true) || !in_array('IsAdmin',$middleware,true)
    || !in_array(\App\Http\Middleware\IsAdmin::class,$resolved,true)
    || !in_array(\App\Http\Middleware\VerifyCsrfToken::class,$resolved,true)
    || !in_array(\Illuminate\Session\Middleware\StartSession::class,$resolved,true))
   throw new \RuntimeException('ROUTE_MIDDLEWARE_INVALID');
 }
 $methods=[
  'App\\Services\\Dashboard\\OrderBoardService'=>['scopedRow'],
  $controller=>['items','saveItems'],
  'App\\Http\\Controllers\\Dashboard\\OrderController'=>['print_fatora','download_fatora'],
  'App\\Services\\Dashboard\\OrderBoardItems'=>['read','save'],
  'App\\Services\\Dashboard\\TakeawayAccess'=>['canReprintReceipts'],
 ];
 if($phase==='installed') {
  if(!class_exists(\App\Services\Dashboard\OrderBoardPrinting::class))
   throw new \RuntimeException('PRINT_POLICY_CLASS_REQUIRED');
  $methods[\App\Services\Dashboard\OrderBoardPrinting::class]=['issue'];
 }
 foreach($methods as $class=>$names) foreach($names as $name) {
  if(!method_exists($class,$name) || !(new \ReflectionMethod($class,$name))->isPublic())
   throw new \RuntimeException('PUBLIC_METHOD_REQUIRED');
 }
 $schema=\Illuminate\Support\Facades\Schema::getFacadeRoot();
 if(!$schema->hasTable('carts') || !$schema->hasColumn('carts','qty'))
  throw new \RuntimeException('CART_QUANTITY_MISSING');
 if(!$schema->hasTable('branch_operation_commands') || !$schema->hasColumns('branch_operation_commands',
   ['branch','actor_id','request_key','request_hash','kind','result','created_at','updated_at']))
  throw new \RuntimeException('PRINT_STORAGE_COLUMNS_MISSING');
 $db=\Illuminate\Support\Facades\DB::connection();$driver=$db->getDriverName();
 if(!in_array($driver,['mysql','sqlite'],true)) throw new \RuntimeException('DATABASE_DRIVER_UNSUPPORTED');
 if($driver==='mysql') {
  $column=$db->selectOne('SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS '
   .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$db->getTablePrefix().'carts','qty']);
  $type=strtolower((string)($column->column_type??''));
  $fractional=(bool)preg_match('/^(float|double)(?:\(\d+\))?(?: unsigned)?$/D',$type);
  if(preg_match('/^(float|double)\(\d+,(\d+)\)/',$type,$match)) $fractional=(int)$match[2]>=3;
  if(preg_match('/^(decimal|numeric)\(\d+,(\d+)\)/',$type,$match)) $fractional=(int)$match[2]>=3;
  if(!$fractional) throw new \RuntimeException('CART_QUANTITY_NOT_FRACTIONAL');
  $tables=['orders','go_store_orders','carts','branch_operation_commands'];
  foreach(['go_service_jobs','partner_service_requests'] as $table) if($schema->hasTable($table)) $tables[]=$table;
  foreach($tables as $table) {
   $meta=$db->selectOne('SELECT ENGINE AS table_engine FROM information_schema.TABLES '
    .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$db->getTablePrefix().$table]);
   if(strtolower((string)($meta->table_engine??''))!=='innodb')
    throw new \RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
  }
 }
 $style=$root.'/public/dashboard/css/takeaway-pos.css';
 if(!is_file($style) || !is_readable($style)) throw new \RuntimeException('POS_CATALOG_STYLE_MISSING');
 for($index=3;$index<count($argv);$index++) {
  try {
   $source=file_get_contents($argv[$index]);
   if($source===false) throw new \RuntimeException();
   token_get_all($app['blade.compiler']->compileString($source),TOKEN_PARSE);
  } catch(\Throwable $error) {throw new \RuntimeException('BLADE_SYNTAX_INVALID');}
 }
 $result=['ok'=>true];$exit=0;
} catch(\Throwable $error) {
 $reason=in_array($error->getMessage(),$known,true)?$error->getMessage():'RUNTIME_PROBE_FAILED';
 $result=['ok'=>false,'reason'=>$reason];
}
while(ob_get_level()>$level)ob_end_clean();echo json_encode($result);exit($exit);
'''


def runtime_probe(root, php, stage, phase, views=()):
    import json
    from pathlib import Path
    reasons = {'RUNTIME_PROBE_FAILED', 'ROUTE_CONTRACT_INVALID', 'ROUTE_MIDDLEWARE_INVALID',
               'PUBLIC_METHOD_REQUIRED', 'CART_QUANTITY_MISSING', 'CART_QUANTITY_NOT_FRACTIONAL',
               'PRINT_STORAGE_COLUMNS_MISSING', 'TRANSACTIONAL_TABLE_REQUIRED',
               'DATABASE_DRIVER_UNSUPPORTED', 'POS_CATALOG_STYLE_MISSING',
               'BLADE_SYNTAX_INVALID', 'PRINT_POLICY_CLASS_REQUIRED'}
    require(phase in ('preflight', 'installed'), 'RUNTIME_PROBE_PHASE_INVALID')
    probe = Path(stage) / 'inline-quantity-runtime.php'
    try:
        probe.write_text(PROBE)
        probe.chmod(0o644)
        result = run([str(php), str(probe), str(root), phase, *map(str, views)], cwd=root)
        report = json.loads(result.stdout)
    except Exception:
        require(False, 'RUNTIME_PROBE_FAILED')
    require(isinstance(report, dict), 'RUNTIME_PROBE_FAILED')
    reason = report.get('reason')
    require(result.returncode == 0 and report.get('ok') is True,
            reason if isinstance(reason, str) and reason in reasons else 'RUNTIME_PROBE_FAILED')
    print('INLINE_QUANTITY_RUNTIME_PASS', flush=True)
    return {'ok': True}


def install(root, revision, php, check_only=False):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    require(root.is_absolute() and root.resolve() == root and root.is_dir(),
            'APPLICATION_ROOT_INVALID')
    safe(root)
    resolved = git(root, 'rev-parse', '--verify', revision + '^{commit}')
    require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision,
            'RELEASE_COMMIT_UNAVAILABLE')
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    plan = build_plan(root, revision)
    prove_prerequisites(root, plan)

    private = root.parent / 'pos-inline-quantity-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    require(private.is_dir() and stat.S_IMODE(private.stat().st_mode) == 0o700
            and private.stat().st_uid == os.geteuid(), 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals = {}
            pending = []
            for index, (relative, entry) in enumerate(plan.items()):
                status = entry['status']
                target = root / relative
                before = content(target)
                require(target.parent.is_dir(), 'REQUIRED_PARENT_MISSING: ' + relative)
                require(status == 'A' or before is not None,
                        'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                mode = stat.S_IMODE(target.stat().st_mode) if before is not None else 0o644
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                originals[relative] = (before, mode)
                patch_path = stage / ('patch-' + str(index) + '.diff')
                patch_path.write_bytes(entry['patch'])
                if patch_check(stage, patch_path, reverse=True):
                    continue
                require(patch_check(stage, patch_path), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                applied = run(['git', 'apply', '--whitespace=nowarn', str(patch_path)], cwd=stage)
                require(applied.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, entry['patch'], staged.read_bytes()))

            lint_php(php, stage)
            views = [stage / path for path in SOURCE_MANIFEST if path.endswith('.blade.php')]
            runtime_probe(root, php, stage, 'preflight', views)
            if not pending:
                print('POS_INLINE_QUANTITY_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch for _, patch, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print('POS_INLINE_QUANTITY_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Every target must still contain exactly the bytes checked above.
            for relative, _, _ in pending:
                require(content(root / relative) == originals[relative][0],
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=prefix + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION,
                       'status': 'prepared', 'files': [],
                       'app_baseline': APP_BASE_REVISION, 'brand_baseline': BRAND_BASE_REVISION}
            for relative, _, after in pending:
                before, mode = originals[relative]
                if before is not None:
                    saved = backup / relative
                    saved.parent.mkdir(parents=True, exist_ok=True)
                    saved.write_bytes(before)
                    saved.chmod(mode)
                receipt['files'].append({'path': relative, 'mode': mode,
                                         'existed': before is not None,
                                         'before': digest(before), 'after': digest(after)})
            receipt_path = backup / 'receipt.json'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            try:
                # This does not use --index, checkout, reset, or whole-file replacement.
                applied = run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                require(applied.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    require(content(root / relative) == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(php, root)
                prove_prerequisites(root, plan)
                runtime_probe(root, php, stage, 'installed')
            except BaseException:
                review = []
                # An unowned edit to an existing consumer may still need the new
                # helper or access methods. Keep the source group together instead
                # of removing dependencies underneath that preserved consumer.
                for relative, _, after in pending:
                    before, _ = originals[relative]
                    if before is None:
                        continue
                    try:
                        if content(root / relative) not in (before, after):
                            review.append(relative)
                    except (RuntimeError, OSError):
                        review.append(relative)
                if review:
                    receipt['retained_dependency_group'] = [relative for relative, _, _ in pending]
                else:
                    for relative, _, after in reversed(pending):
                        target = root / relative
                        before, mode = originals[relative]
                        try:
                            observed = content(target)
                            if observed == after:
                                if before is None:
                                    target.unlink()
                                else:
                                    atomic(target, before, mode)
                            elif observed != before:
                                review.append(relative)
                        except (RuntimeError, OSError):
                            # An independently changed new helper can remain unused
                            # after the original consumers have been restored.
                            review.append(relative)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
                print(('POS_INLINE_QUANTITY_ROLLBACK_REVIEW_REQUIRED' if review else
                       'POS_INLINE_QUANTITY_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print('POS_INLINE_QUANTITY_OK files=' + str(len(pending))
                  + '; BACKUP=' + str(backup), flush=True)
            return backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    require(os.geteuid() == pwd.getpwnam('fasakha').pw_uid, 'RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.revision, args.php, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
