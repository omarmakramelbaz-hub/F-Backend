#!/usr/bin/env python3
"""Install branch stock minimum hunks, their additive schema and no other migrations."""
import argparse
import datetime
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import shutil
import stat
import subprocess
import tempfile

BASE_REVISION = 'd99f094b6c23fd198757e5e4bb663b44f688d0f8'
MIGRATION = 'database/migrations/2026_10_18_230000_create_branch_stock_minimum_alerts.php'
NEW_PATHS = (
    'app/Services/Dashboard/BranchStockMinimums.php',
    'app/Http/Controllers/Dashboard/BranchStockMinimumController.php',
    'app/Console/Commands/DispatchBranchStockMinimumAlerts.php',
    'app/Mail/BranchStockMinimumAlert.php',
    'resources/views/emails/branch_stock_minimum.blade.php',
    MIGRATION,
)
MODIFIED_PATHS = (
    'app/Services/Dashboard/BranchInventory.php',
    'app/Services/Dashboard/BranchStock.php',
    'routes/branch_stock.php',
    'app/Console/Kernel.php',
    'public/dashboard/js/branch-stock.js',
    'public/dashboard/css/branch-stock.css',
    'resources/views/admin/branch_stock/index.blade.php',
)
SOURCE_PATHS = NEW_PATHS + MODIFIED_PATHS
MAX_BYTES = 4 * 1024 * 1024
BASIC_COMPAT_PATHS = ('public/dashboard/js/branch-stock.js', 'public/dashboard/css/branch-stock.css')
# These independently deployed integrations are from the reviewed BasicRecipes
# release 5617979f658ff79e416b9600f099b72cd48e8836, not inferred live content.
BASIC_STOCK_BUSY = (
    b'        var stockBusy = busy || loading || !!frozen;\n'
    b'        root.dataset.stockBusy = String(stockBusy);\n'
    b"        root.dispatchEvent(new CustomEvent('inventory:stock-busy', { detail: stockBusy }));\n"
)
BASIC_RECIPE_CSS = b'''
/* The basic recipe review stays within the recipes tab at narrow widths. */
#branch-stock .bs-recipe-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
#branch-stock .bs-basic-button{background:#f58220;color:#fff;border-color:#f58220;font-weight:700;white-space:normal}
#branch-stock .bs-basic-button:hover{background:#df7012}
#branch-stock .bs-basic-dialog{color:#17304a;background:#fff;border:1px solid #dde5ef;border-radius:14px;padding:22px;max-width:620px;width:calc(100% - 32px);max-height:85vh;overflow:auto;box-sizing:border-box;box-shadow:0 16px 60px #0b1a3440;direction:rtl;text-align:right}
#branch-stock .bs-basic-dialog::backdrop{background:#07142299}
#branch-stock .bs-basic-dialog h2{font-size:21px;margin:0 0 12px}
#branch-stock .bs-basic-dialog p{font-size:14px;line-height:1.8}
#branch-stock [data-basic-skipped],#branch-stock [data-basic-result-skipped]{max-height:230px;overflow:auto;font-size:13px;line-height:1.8;padding-inline-start:24px}
#branch-stock .bs-basic-actions{display:flex;gap:10px;justify-content:flex-start;flex-wrap:wrap;margin-top:18px}
#branch-stock [data-basic-result-summary]{margin:0}
'''


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(command, cwd=None):
    return subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=180)


def git(root, *arguments):
    return run(['git', '-C', str(root), *arguments])


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for candidate in (path, *path.parents):
        require(not candidate.is_symlink(), 'SYMLINK_REJECTED: ' + str(candidate))


def read_source(path, absent_ok=False):
    safe(path)
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        if absent_ok:
            return None, None
        raise
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_UNLINKED_SOURCE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'FASAKHA_OWNED_SOURCE_REQUIRED: ' + str(path))
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body,
            'SOURCE_INVALID: ' + str(path))
    return body, info


def digest(body):
    return None if body is None else hashlib.sha256(body).hexdigest()


def restore_metadata(path, expected_body, original_info):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid() and stream.read(MAX_BYTES + 1) == expected_body,
                'UNOWNED_SOURCE_METADATA_CHANGE: ' + str(path))
        group = original_info.st_gid if original_info else os.getegid()
        if info.st_gid != group:
            os.fchown(stream.fileno(), -1, group)
        os.fchmod(stream.fileno(), stat.S_IMODE(original_info.st_mode) if original_info else 0o644)


def unchanged(path, original):
    body, info = read_source(path, absent_ok=original[0] is None)
    old_body, old_info = original
    if old_body is None:
        return body is None
    return (body == old_body and info.st_uid == old_info.st_uid
            and info.st_gid == old_info.st_gid
            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode)
            and info.st_ino == old_info.st_ino and info.st_dev == old_info.st_dev
            and info.st_mtime_ns == old_info.st_mtime_ns)


def preflight_destination(root, relative):
    """Git hunk checks do not check whether Git can create/replace a live file."""
    target = root / relative
    safe(target)
    parent = target.parent
    while not parent.exists():
        require(parent != root, 'APPLICATION_ROOT_INVALID')
        parent = parent.parent
    info = parent.stat()
    require(stat.S_ISDIR(info.st_mode), 'DESTINATION_PARENT_NOT_DIRECTORY: ' + relative)
    require(os.access(parent, os.W_OK | os.X_OK, effective_ids=True),
            'DESTINATION_PARENT_NOT_WRITABLE: ' + str(parent.relative_to(root))
            + '; file=' + relative + '; uid=' + str(info.st_uid) + '; gid=' + str(info.st_gid)
            + '; mode=' + format(stat.S_IMODE(info.st_mode), '04o')
            + '; effective_uid=' + str(os.geteuid()))


def patch_failure_category(stderr):
    """Expose an actionable filesystem category without printing Git's raw output."""
    observed = stderr.lower()
    categories = (
        ('permission_denied', (b'permission denied', b'operation not permitted')),
        ('read_only_filesystem', (b'read-only file system',)),
        ('storage_full', (b'no space left on device', b'disk quota exceeded')),
        ('destination_conflict', (b'already exists', b'not a directory', b'no such file or directory')),
        ('hunk_conflict', (b'does not apply', b'patch failed')),
    )
    return next((name for name, phrases in categories if any(phrase in observed for phrase in phrases)),
                'git_error')


def patch_check(directory, patch, reverse=False):
    command = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        command.append('--reverse')
    return run([*command, str(patch)], cwd=directory).returncode == 0


def patch_apply(directory, patch, reverse=False):
    command = ['git', 'apply', '--whitespace=nowarn']
    if reverse:
        command.append('--reverse')
    result = run([*command, str(patch)], cwd=directory)
    if result.returncode != 0:
        print('STOCK_MINIMUM_PATCH_DIAGNOSTIC category=' + patch_failure_category(result.stderr)
              + ' exit_code=' + str(result.returncode), flush=True)
    return result.returncode == 0


def lint_sources(php, directory, application_root, cache_directory):
    """Compile disposable candidates without loading candidate application code."""
    autoload = application_root / 'vendor/autoload.php'
    require(autoload.is_file(), 'PHP_VENDOR_AUTOLOAD_REQUIRED')
    cache_directory.mkdir(mode=0o700, exist_ok=True)
    blade_program = r"""
require $argv[1];
$compiler = new Illuminate\View\Compilers\BladeCompiler(
    new Illuminate\Filesystem\Filesystem, $argv[3]
);
$source = file_get_contents($argv[2]);
if ($source === false) { throw new RuntimeException('BLADE_SOURCE_UNREADABLE'); }
token_get_all($compiler->compileString($source), TOKEN_PARSE);
"""
    node = shutil.which('node')
    for relative in SOURCE_PATHS:
        if relative.endswith('.blade.php'):
            result = run([php, '-r', blade_program, str(autoload),
                          str(directory / relative), str(cache_directory)])
            require(result.returncode == 0, 'BLADE_COMPILE_INVALID: ' + relative)
        elif relative.endswith('.php'):
            result = run([php, '-l', str(directory / relative)])
            require(result.returncode == 0, 'PHP_PARSE_INVALID: ' + relative)
        elif relative.endswith('.js') and node:
            result = run([node, '--check', str(directory / relative)])
            require(result.returncode == 0, 'JS_PARSE_INVALID: ' + relative)


SCHEMA_PHP = r"""
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $schema=\Illuminate\Support\Facades\Schema::class;$db=\Illuminate\Support\Facades\DB::class;
 $required=[
  'migrations'=>['migration'],
  'stock_ingredients'=>['id','name','unit'],
  'branch_inventory'=>['id','branch','ingredient_id','quantity_units','revision'],
  'branch_stock'=>['id','branch','product_id','unit','quantity_units','revision'],
  'resturants'=>['id','name'],
  'resturant_products'=>['id','resturant_id','product_name'],
  'users'=>['id','email','account_type','owner_resturant_id','status'],
  'branch_operation_commands'=>['id','branch','actor_id','request_key','request_hash','kind','result','created_at','updated_at'],
  'branch_payrolls'=>['id'],
 ];
 foreach($required as$table=>$columns){
  if(!$schema::hasTable($table)||!$schema::hasColumns($table,$columns))throw new \RuntimeException();
 }
 $recorded=(int)$db::table('migrations')->where('migration','2026_10_18_230000_create_branch_stock_minimum_alerts')->count();
 if(!in_array($recorded,[0,1],true))throw new \RuntimeException();
 $tables=[
  'branch_stock_minimums'=>['id','branch','stock_type','stock_item_id','unit','minimum_units','revision','updated_by','active_low','episode','created_at','updated_at'],
  'branch_stock_minimum_alerts'=>['id','minimum_id','episode','branch_name','item_name','unit','balance_units','minimum_units','attempts','next_attempt_at','lease_token','lease_until','last_error_code','sent_at','cancelled_at','created_at','updated_at'],
 ];
 $keys=[
  'branch_stock_minimums'=>[['branch','stock_type','stock_item_id']],
  'branch_stock_minimum_alerts'=>[['minimum_id','episode']],
 ];
 $present=true;
 foreach($tables as$table=>$columns){
  if(!$schema::hasTable($table)){$present=false;continue;}
  if(!$schema::hasColumns($table,$columns))throw new \RuntimeException();
  $indexes=$db::select('SELECT INDEX_NAME AS index_name,COLUMN_NAME AS column_name,SEQ_IN_INDEX AS position,NON_UNIQUE AS non_unique '
   .'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$db::connection()->getTablePrefix().$table]);
  $all=[];$unique=[];
  foreach($indexes as$index){$all[$index->index_name][(int)$index->position]=$index->column_name;
   if((int)$index->non_unique===0)$unique[$index->index_name][(int)$index->position]=$index->column_name;}
  foreach($keys[$table] as$expected){$found=false;foreach($unique as$parts){ksort($parts);if(array_values($parts)===$expected)$found=true;}if(!$found)throw new \RuntimeException();}
  if($table==='branch_stock_minimum_alerts'){$found=false;foreach($all as$parts){ksort($parts);if(array_values($parts)===['sent_at','cancelled_at','next_attempt_at'])$found=true;}if(!$found)throw new \RuntimeException();}
 }
 if($db::connection()->getDriverName()!=='mysql')throw new \RuntimeException();
 foreach(array_diff(array_keys($required+$tables),['migrations','users','stock_ingredients','resturant_products','branch_payrolls'])as$table){
  if(!$schema::hasTable($table))continue;
  $meta=$db::selectOne('SELECT ENGINE AS table_engine FROM information_schema.TABLES '
   .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$db::connection()->getTablePrefix().$table]);
  if(!$meta||strtoupper((string)$meta->table_engine)!=='INNODB')throw new \RuntimeException();
 }
 while(ob_get_level()>0)ob_end_clean();
 echo json_encode(['present'=>$present,'recorded'=>$recorded===1],JSON_THROW_ON_ERROR);
}catch(\Throwable $ignore){while(ob_get_level()>0)ob_end_clean();exit(1);}
"""


def schema_state(root, php):
    result = run([php, '-r', SCHEMA_PHP], cwd=root)
    try:
        state = json.loads(result.stdout) if result.returncode == 0 else None
    except (ValueError, TypeError):
        state = None
    require(isinstance(state, dict) and set(state) == {'present', 'recorded'}
            and all(type(value) is bool for value in state.values()),
            'STOCK_MINIMUM_SCHEMA_NEEDS_REVIEW')
    require(not state['recorded'] or state['present'],
            'STOCK_MINIMUM_RECORDED_MIGRATION_INCOMPLETE')
    return state


RUNTIME_PHP = r"""
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
 $cache=$app->getCachedRoutesPath();
 if(!preg_match('/\A'.preg_quote(getcwd().'/bootstrap/cache/routes','/').'(?:-v[0-9]+)?\.php\z/',$cache))throw new \RuntimeException();
 $owner=\Illuminate\Support\Facades\DB::table('users')->where('id',1)->where('account_type','admin')->first();
 $ownerReady=$owner&&empty($owner->owner_resturant_id)&&$owner->status==='accepted'
  &&filter_var(trim((string)($owner->email??'')),FILTER_VALIDATE_EMAIL)!==false;
 $mailer=config('mail.default','smtp');
 $transportReady=is_string($mailer)&&in_array(config('mail.mailers.'.$mailer.'.transport',$mailer),['smtp','sendmail','ses','mailgun','postmark'],true);
 if(($argv[1]??'')==='target'){
  $route=app('router')->getRoutes()->getByName('branch-stock.minimum-save');
  if(!$route||!in_array('POST',$route->methods(),true)||!in_array('IsAdmin',$route->gatherMiddleware(),true)
   ||$route->getActionName()!==\App\Http\Controllers\Dashboard\BranchStockMinimumController::class.'@store')throw new \RuntimeException();
  if(!array_key_exists('branch-stock:notify-minimum',$kernel->all()))throw new \RuntimeException();
  $scheduled=array_values(array_filter(app(\Illuminate\Console\Scheduling\Schedule::class)->events(),
   static fn($event)=>str_contains($event->command??'','branch-stock:notify-minimum')));
  if(count($scheduled)!==1||$scheduled[0]->expression!=='* * * * *'||!$scheduled[0]->withoutOverlapping)throw new \RuntimeException();
 }
 while(ob_get_level()>0)ob_end_clean();
 echo json_encode(['route_cache_path'=>substr($cache,strlen(getcwd())+1),
  'owner_email_ready'=>(bool)$ownerReady,'mail_transport_ready'=>$transportReady],JSON_THROW_ON_ERROR);
}catch(\Throwable $ignore){while(ob_get_level()>0)ob_end_clean();exit(1);}
"""


def runtime_state(root, php, target=False):
    """Inspect routes, command registration and schedule without running any job."""
    result = run([php, '-r', RUNTIME_PHP, 'target' if target else 'source'], cwd=root)
    try:
        state = json.loads(result.stdout) if result.returncode == 0 else None
    except (ValueError, TypeError):
        state = None
    require(isinstance(state, dict) and set(state) == {'route_cache_path', 'owner_email_ready', 'mail_transport_ready'}
            and isinstance(state['route_cache_path'], str)
            and type(state['owner_email_ready']) is bool and type(state['mail_transport_ready']) is bool
            and re.fullmatch(r'bootstrap/cache/routes(?:-v[0-9]+)?\.php', state['route_cache_path']),
            'STOCK_MINIMUM_RUNTIME_POSTCHECK_FAILED' if target else 'STOCK_MINIMUM_RUNTIME_PREFLIGHT_FAILED')
    return state


def mail_status(runtime):
    report = (' owner_email_ready=' + str(int(runtime['owner_email_ready']))
              + ' mail_transport_ready=' + str(int(runtime['mail_transport_ready'])))
    if not (runtime['owner_email_ready'] and runtime['mail_transport_ready']):
        print('STOCK_MINIMUM_MAIL_CONFIGURATION_REQUIRED' + report, flush=True)
    return report


def restore_route_cache(root, relative, original):
    """Restore our removed route cache only while no other process has rebuilt it."""
    target = root / relative
    before, info = original
    observed, _ = read_source(target, absent_ok=True)
    if observed is not None:
        require(observed == before, 'UNOWNED_ROUTE_CACHE_CHANGE_DURING_ROLLBACK')
        return
    if before is None:
        return
    safe(target)
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(before)
        stream.flush()
        os.fsync(stream.fileno())
    restore_metadata(target, before, info)
    os.utime(target, ns=(info.st_atime_ns, info.st_mtime_ns), follow_symlinks=False)


def validate_release(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    for commit in (BASE_REVISION, revision):
        result = git(root, 'rev-parse', '--verify', commit + '^{commit}')
        require(result.returncode == 0 and result.stdout.decode().strip() == commit,
                'PINNED_COMMIT_UNAVAILABLE: ' + commit)
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    expected = {'M\t' + path for path in MODIFIED_PATHS} | {'A\t' + path for path in NEW_PATHS}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            'RELEASE_SOURCE_MANIFEST_MISMATCH')
    for commit, paths in ((BASE_REVISION, MODIFIED_PATHS), (revision, SOURCE_PATHS)):
        result = git(root, 'ls-tree', '-z', commit, '--', *paths)
        require(result.returncode == 0, 'RELEASE_TREE_UNAVAILABLE')
        entries = result.stdout.rstrip(b'\x00').split(b'\x00')
        require(len(entries) == len(paths), 'RELEASE_SOURCE_TREE_MISMATCH')
        for entry in entries:
            header, relative = entry.split(b'\t', 1)
            mode, kind, _ = header.split(b' ', 2)
            require(mode in (b'100644', b'100755') and kind == b'blob'
                    and relative.decode() in paths, 'RELEASE_SOURCE_NOT_REGULAR')


def release_patch(root, revision, relative):
    result = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--unified=3', BASE_REVISION, revision, '--', relative)
    body = result.stdout
    prohibited = (b'GIT binary patch', b'Binary files ', b'old mode ', b'new mode ', b'deleted file mode ')
    require(result.returncode == 0 and 0 < len(body) <= MAX_BYTES
            and body.startswith(('diff --git a/' + relative + ' b/' + relative + '\n').encode())
            and all(marker not in body for marker in prohibited)
            and ((b'new file mode ' in body) == (relative in NEW_PATHS)),
            'RELEASE_PATCH_INVALID: ' + relative)
    return body


def release_body(root, revision, relative):
    result = git(root, 'show', revision + ':' + relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= MAX_BYTES
            and b'\x00' not in result.stdout, 'RELEASE_SOURCE_INVALID: ' + relative)
    return result.stdout


def basic_recipe_compatibility_patch(root, revision, relative, live_body):
    """Apply only minimum feature hunks while retaining the known recipe integration."""
    require(relative in BASIC_COMPAT_PATHS, 'BASIC_RECIPE_COMPATIBILITY_PATH_INVALID')
    before = release_body(root, BASE_REVISION, relative)
    after = release_body(root, revision, relative)
    if relative.endswith('.js'):
        anchor = b'    function lock() {\n'
        require(before.count(anchor) == after.count(anchor) == 1
                and b'inventory:stock-busy' not in before + after
                and b'minimumBusy' in after, 'BASIC_RECIPE_BUSY_ANCHOR_NEEDS_REVIEW')
        before = before.replace(anchor, anchor + BASIC_STOCK_BUSY, 1)
        integrated_busy = BASIC_STOCK_BUSY.replace(b'busy || loading || !!frozen',
                           b'busy || minimumBusy || !!minimumEditor || loading || !!frozen', 1)
        require(live_body.count(BASIC_STOCK_BUSY) + live_body.count(integrated_busy) == 1
                and live_body.count(b'inventory:stock-busy') == 1,
                'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
        after = after.replace(anchor, anchor + integrated_busy, 1)
    else:
        require(after.startswith(before) and BASIC_RECIPE_CSS not in before + after,
                'BASIC_RECIPE_CSS_ANCHOR_NEEDS_REVIEW')
        require(live_body.count(BASIC_RECIPE_CSS) == 1, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
        after = before + BASIC_RECIPE_CSS + after[len(before):]
        before += BASIC_RECIPE_CSS
    body = (('diff --git a/' + relative + ' b/' + relative + '\n').encode()
            + b''.join(difflib.diff_bytes(difflib.unified_diff,
                       before.splitlines(keepends=True), after.splitlines(keepends=True),
                       fromfile=('a/' + relative).encode(),
                       tofile=('b/' + relative).encode(), n=3)))
    require(before != after and len(body) <= MAX_BYTES, 'BASIC_RECIPE_COMPATIBILITY_PATCH_INVALID')
    return body


def save_receipt(path, receipt):
    safe(path)
    descriptor, name = tempfile.mkstemp(prefix='.receipt-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump(receipt, stream, ensure_ascii=False, indent=2)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def rollback(root, applied, originals):
    """Reverse only files whose complete installed bytes are still ours."""
    review = []
    for relative, patch_path, after in reversed(applied):
        try:
            target = root / relative
            before, old_info = originals[relative]
            observed, info = read_source(target, absent_ok=before is None)
            if observed == before:
                if before is not None:
                    require(info.st_gid == old_info.st_gid
                            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode),
                            'UNOWNED_SOURCE_METADATA_CHANGE')
                continue
            require(observed == after, 'UNOWNED_CHANGE_DURING_ROLLBACK')
            require(patch_check(root, patch_path, reverse=True), 'ROLLBACK_CHECK_FAILED')
            require(patch_apply(root, patch_path, reverse=True), 'ROLLBACK_PATCH_FAILED')
            if before is not None:
                restore_metadata(target, before, old_info)
            require(read_source(target, absent_ok=before is None)[0] == before,
                    'ROLLBACK_VERIFICATION_FAILED')
        except (RuntimeError, OSError, subprocess.SubprocessError):
            review.append(relative)
    return review


def install(root, revision, php, check_only=False):
    os.umask(0o077)
    validate_release(root, revision)
    private = root.parent / 'branch-stock-minimum-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(private.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700, 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    lock_fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(lock_fd, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o600,
                'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            paths = SOURCE_PATHS
            originals, pending = {}, []
            for index, relative in enumerate(paths):
                before, source_info = read_source(root / relative, absent_ok=relative in NEW_PATHS)
                originals[relative] = (before, source_info)
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                    staged.chmod(stat.S_IMODE(source_info.st_mode))
                patch = stage / ('patch-' + str(index) + '.diff')
                patch.write_bytes(release_patch(root, revision, relative))
                if relative in NEW_PATHS and before is not None:
                    require(before == release_body(root, revision, relative),
                            'NEW_SOURCE_COLLISION_NEEDS_REVIEW: ' + relative)
                    continue
                # Reverse first prevents duplicate insertion-only hunks on reruns.
                if relative not in NEW_PATHS and patch_check(stage, patch, reverse=True):
                    continue
                if relative in BASIC_COMPAT_PATHS and not patch_check(stage, patch):
                    patch = stage / ('patch-' + str(index) + '-basic-recipes.diff')
                    patch.write_bytes(basic_recipe_compatibility_patch(root, revision, relative, before))
                    if patch_check(stage, patch, reverse=True):
                        continue
                require(patch_check(stage, patch), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                require(patch_apply(stage, patch), 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch, staged.read_bytes()))
            for relative, _, _ in pending:
                preflight_destination(root, relative)
            lint_sources(php, stage, root, stage / '.blade-cache')
            initial_schema = schema_state(root, php)
            runtime = runtime_state(root, php)
            cache_relative = runtime['route_cache_path']
            cache_original = read_source(root / cache_relative, absent_ok=True)
            for relative in paths:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            if not pending and initial_schema['recorded']:
                try:
                    current_runtime = runtime_state(root, php, target=True)
                except RuntimeError:
                    # A prior manual deployment can leave an obsolete route cache.
                    # Clear it through the same backed-up path, then verify again.
                    require(cache_original[0] is not None, 'STOCK_MINIMUM_RUNTIME_POSTCHECK_FAILED')
                else:
                    print('BRANCH_STOCK_MINIMUM_OK already_current=1 files=0' + mail_status(current_runtime), flush=True)
                    return None
            if cache_original[0] is not None:
                preflight_destination(root, cache_relative)
            print('BRANCH_STOCK_MINIMUM_PREFLIGHT_OK files=' + str(len(pending))
                  + ' migration_required=' + str(int(not initial_schema['recorded']))
                  + mail_status(runtime), flush=True)
            if check_only:
                return None
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=stamp + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION, 'status': 'prepared',
                       'migration': MIGRATION, 'schema_before': initial_schema,
                       'migration_attempted': False, 'database_rollback_attempted': False,
                       'route_cache': {'path': cache_relative, 'cleared': False,
                                       'before_sha256': digest(cache_original[0])},
                       'files': []}
            for relative, patch, after in pending:
                before, source_info = originals[relative]
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    saved.write_bytes(before)
                    saved.chmod(0o600)
                    require(digest(saved.read_bytes()) == digest(before), 'BACKUP_HASH_MISMATCH')
                receipt['files'].append({'path': relative, 'before_sha256': digest(before),
                                         'after_sha256': digest(after),
                                         'mode': stat.S_IMODE(source_info.st_mode) if source_info else None,
                                         'uid': source_info.st_uid if source_info else None,
                                         'gid': source_info.st_gid if source_info else None})
            if cache_original[0] is not None:
                saved_cache = backup / cache_relative
                saved_cache.parent.mkdir(parents=True, exist_ok=True)
                saved_cache.write_bytes(cache_original[0])
                saved_cache.chmod(0o600)
                require(digest(saved_cache.read_bytes()) == digest(cache_original[0]),
                        'ROUTE_CACHE_BACKUP_HASH_MISMATCH')
            (backup / 'release.diff').write_bytes(b''.join(p.read_bytes() for _, p, _ in pending))
            receipt_path = backup / 'receipt.json'
            save_receipt(receipt_path, receipt)
            for relative in paths:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
            require(schema_state(root, php) == initial_schema, 'SCHEMA_CHANGED_DURING_PREFLIGHT')
            require(unchanged(root / cache_relative, cache_original), 'ROUTE_CACHE_CHANGED_DURING_PREFLIGHT')
            for relative, _, _ in pending:
                preflight_destination(root, relative)
            applied = []
            try:
                # Publish the guarded helper and additive migration before their callers.
                for paths in (NEW_PATHS, MODIFIED_PATHS):
                    if paths == MODIFIED_PATHS and not initial_schema['recorded']:
                        receipt['migration_attempted'] = True
                        save_receipt(receipt_path, receipt)
                        result = run([php, 'artisan', 'migrate', '--force', '--path=' + MIGRATION], cwd=root)
                        require(result.returncode == 0, 'SCOPED_STOCK_MINIMUM_MIGRATION_FAILED')
                        ready = schema_state(root, php)
                        require(all(ready.values()), 'STOCK_MINIMUM_MIGRATION_INCOMPLETE')
                    for item in pending:
                        relative, patch, after = item
                        if relative not in paths:
                            continue
                        require(unchanged(root / relative, originals[relative]),
                                'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
                        applied.append(item)
                        require(patch_apply(root, patch), 'LIVE_PATCH_FAILED: ' + relative)
                        restore_metadata(root / relative, after, originals[relative][1])
                        require(read_source(root / relative)[0] == after,
                                'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_sources(php, root, root, stage / '.blade-cache')
                if cache_original[0] is not None:
                    require(unchanged(root / cache_relative, cache_original), 'ROUTE_CACHE_CHANGED_DURING_INSTALL')
                    # A new endpoint must load from source; retain the exact old cache for rollback.
                    receipt['route_cache']['cleared'] = True
                    save_receipt(receipt_path, receipt)
                    result = run([php, 'artisan', 'route:clear'], cwd=root)
                    require(result.returncode == 0 and not (root / cache_relative).exists(),
                            'SCOPED_ROUTE_CACHE_CLEAR_FAILED')
                else:
                    require(unchanged(root / cache_relative, cache_original), 'ROUTE_CACHE_CHANGED_DURING_INSTALL')
                final_schema = schema_state(root, php)
                require(all(final_schema.values()), 'STOCK_MINIMUM_POSTCHECK_FAILED')
                final_runtime = runtime_state(root, php, target=True)
                require(final_runtime['route_cache_path'] == runtime['route_cache_path'],
                        'STOCK_MINIMUM_RUNTIME_PATH_CHANGED')
                for relative, _, after in pending:
                    require(read_source(root / relative)[0] == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
            except BaseException:
                review = rollback(root, applied, originals)
                if receipt['route_cache']['cleared']:
                    try:
                        restore_route_cache(root, cache_relative, cache_original)
                    except (RuntimeError, OSError):
                        review.append(cache_relative)
                receipt['status'] = 'rollback_review_required' if review else 'code_restored'
                receipt['review'] = review
                receipt['database_changes_may_remain'] = receipt['migration_attempted']
                save_receipt(receipt_path, receipt)
                print(('STOCK_MINIMUM_ROLLBACK_REVIEW_REQUIRED' if review else
                       'STOCK_MINIMUM_CODE_RESTORED') + ' database_changes_may_remain='
                      + str(int(receipt['migration_attempted'])) + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt['schema_after'] = final_schema
            receipt['mail_readiness'] = {key: final_runtime[key] for key in ('owner_email_ready', 'mail_transport_ready')}
            save_receipt(receipt_path, receipt)
            print('BRANCH_STOCK_MINIMUM_OK files=' + str(len(pending))
                  + mail_status(final_runtime) + '; BACKUP=' + str(backup), flush=True)
            return backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    require(os.geteuid() == pwd.getpwnam('fasakha').pw_uid, 'RUN_AS_FASAKHA_REQUIRED')
    def interrupted(signum, frame):
        raise RuntimeError('INSTALL_INTERRUPTED')
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    install(Path(args.root), args.revision, args.php, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, KeyError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
