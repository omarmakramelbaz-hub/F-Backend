#!/usr/bin/env python3
"""Install the customer follow-up template repair without replacing other live changes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import stat
import subprocess
import tempfile

BASE_REVISION = 'a1c50bd9871aa88a1bf37a840358a6185b1fe580'
SOURCE_PATHS = (
    'app/Services/Dashboard/CustomerMessagingService.php',
    'app/Http/Controllers/Dashboard/BranchOperationsController.php',
    'app/Support/CustomerCrmRouteCheck.php',
    'routes/branch_operations.php',
    'public/dashboard/js/customer-crm.js',
)
MAX_BYTES = 4 * 1024 * 1024
MARKER = 'CUSTOMER_FOLLOWUP_TEMPLATE'


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


def read_source(path):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_UNLINKED_SOURCE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'APPLICATION_OWNED_SOURCE_REQUIRED: ' + str(path))
        body = stream.read(MAX_BYTES + 1)
    require(0 < len(body) <= MAX_BYTES and b'\x00' not in body, 'SOURCE_INVALID: ' + str(path))
    return body, info


def same_source(path, original):
    body, info = read_source(path)
    before, old_info = original
    return (body == before and info.st_uid == old_info.st_uid and info.st_gid == old_info.st_gid
            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode)
            and info.st_ino == old_info.st_ino and info.st_dev == old_info.st_dev
            and info.st_mtime_ns == old_info.st_mtime_ns)


def digest(body):
    return hashlib.sha256(body).hexdigest()


def atomic(path, body, info):
    safe(path)
    descriptor, name = tempfile.mkstemp(prefix='.followup-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(body)
            stream.flush()
            os.fchown(stream.fileno(), -1, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            os.fsync(stream.fileno())
        os.replace(name, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(name):
            os.unlink(name)


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


def validate_release(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    require(SOURCE_PATHS and len(set(SOURCE_PATHS)) == len(SOURCE_PATHS), 'RELEASE_MANIFEST_INVALID')
    for commit in (BASE_REVISION, revision):
        result = git(root, 'rev-parse', '--verify', commit + '^{commit}')
        require(result.returncode == 0 and result.stdout.decode().strip() == commit,
                'PINNED_COMMIT_UNAVAILABLE: ' + commit)
        tree = git(root, 'ls-tree', '-z', commit, '--', *SOURCE_PATHS)
        entries = tree.stdout.rstrip(b'\x00').split(b'\x00')
        require(tree.returncode == 0 and len(entries) == len(SOURCE_PATHS), 'RELEASE_SOURCE_TREE_MISMATCH')
        for entry in entries:
            header, relative = entry.split(b'\t', 1)
            mode, kind, _ = header.split(b' ', 2)
            require(mode in (b'100644', b'100755') and kind == b'blob'
                    and relative.decode() in SOURCE_PATHS, 'RELEASE_SOURCE_NOT_REGULAR')
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines())
            == {'M\t' + relative for relative in SOURCE_PATHS}, 'RELEASE_SOURCE_MANIFEST_MISMATCH')


def release_body(root, revision, relative):
    result = git(root, 'show', revision + ':' + relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= MAX_BYTES
            and b'\x00' not in result.stdout, 'RELEASE_SOURCE_INVALID: ' + relative)
    return result.stdout


def merge_candidate(stage, relative, before, base, release):
    if before == release or before == base:
        return release
    directory = Path(tempfile.mkdtemp(prefix='merge-', dir=stage))
    current, ancestor, incoming = (directory / name for name in ('current', 'base', 'release'))
    for path, body in ((current, before), (ancestor, base), (incoming, release)):
        path.write_bytes(body)
    result = run(['git', 'merge-file', '-p', '--diff3', str(current), str(ancestor), str(incoming)])
    require(result.returncode == 0, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
    require(0 < len(result.stdout) <= MAX_BYTES and b'\x00' not in result.stdout,
            'MERGED_SOURCE_INVALID: ' + relative)
    return result.stdout


def lint_sources(php, directory):
    for relative in SOURCE_PATHS:
        if relative.endswith('.php'):
            require(run([php, '-l', str(directory / relative)]).returncode == 0,
                    'PHP_SYNTAX_INVALID: ' + relative)
        elif relative.endswith('.js'):
            node = shutil.which('node')
            if node:
                require(run([node, '--check', str(directory / relative)]).returncode == 0,
                        'JAVASCRIPT_SYNTAX_INVALID: ' + relative)
            else:
                print(MARKER + '_JS_CHECK_SKIPPED node_unavailable=1', flush=True)


ROUTE_PROBE_PHP = r"""
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
 require 'vendor/autoload.php';
 if ($argv[1] === 'staged') {
  $container = new \Illuminate\Container\Container;
  \Illuminate\Container\Container::setInstance($container);
  $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($container), $container);
  $container->instance('router', $router);
  \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
  \Illuminate\Support\Facades\Facade::clearResolvedInstances();
  $router->middlewareGroup('web', ['Illuminate\\Session\\Middleware\\StartSession', 'App\\Http\\Middleware\\VerifyCsrfToken']);
  $router->aliasMiddleware('IsAdmin', 'App\\Http\\Middleware\\IsAdmin');
  $router->aliasMiddleware('lang', 'App\\Http\\Middleware\\lang');
  require $argv[2].'/app/Http/Controllers/Dashboard/BranchOperationsController.php';
  $router->middleware('web')->group($argv[2].'/routes/branch_operations.php');
 } else {
  $app = require 'bootstrap/app.php';
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $app->make(\Illuminate\Contracts\Http\Kernel::class);
  $router = $app->make('router');
 }
 $routes = $router->getRoutes(); $routes->refreshNameLookups();
 $name = 'customers.feedback-template-prepare';
 $matches = array_values(array_filter($routes->getRoutes(), static fn ($r) => $r->getName() === $name));
 if (count($matches) !== 1) throw new \RuntimeException();
 $route = $matches[0];
 $controller = \App\Http\Controllers\Dashboard\BranchOperationsController::class;
 if ($route->uri() !== 'admin/customers/feedback-template-prepare' || $route->methods() !== ['POST']
     || $route->getActionName() !== $controller.'@prepareFeedbackTemplate'
     || !method_exists($controller, 'prepareFeedbackTemplate')
     || $route->excludedMiddleware() !== []) throw new \RuntimeException();
 $middleware = $router->gatherRouteMiddleware($route);
 foreach (['Illuminate\\Session\\Middleware\\StartSession', 'App\\Http\\Middleware\\VerifyCsrfToken',
           'App\\Http\\Middleware\\IsAdmin', 'App\\Http\\Middleware\\lang'] as $required) {
  if (!in_array($required, $middleware, true)) throw new \RuntimeException();
 }
 $request = \Illuminate\Http\Request::create('/admin/customers/feedback-template-prepare', 'POST');
 if ($routes->match($request) !== $route) throw new \RuntimeException();
 while (ob_get_level() > 0) ob_end_clean();
 echo '{"ok":true,"verified_routes":1,"requests_dispatched":0}';
} catch (\Throwable $error) {
 while (ob_get_level() > 0) ob_end_clean(); exit(1);
}
"""


def route_check(root, php, stage=None, log=None):
    result = run([php, '-r', ROUTE_PROBE_PHP, 'staged' if stage else 'live', str(stage or root)], cwd=root)
    if log:
        log.write(result.stdout + result.stderr)
        log.flush()
    try:
        report = json.loads(result.stdout) if result.returncode == 0 else None
    except (ValueError, TypeError):
        report = None
    require(report == {'ok': True, 'verified_routes': 1, 'requests_dispatched': 0},
            'FEEDBACK_TEMPLATE_ROUTE_CHECK_FAILED')
    print(MARKER + '_ROUTE_OK requests_dispatched=0', flush=True)


def clear_caches(root, php, log, strict=True):
    failures = []
    for command in ('route:clear', 'config:clear', 'view:clear'):
        result = run([php, str(root / 'artisan'), command], cwd=root)
        log.write((command + '\n').encode() + result.stdout + result.stderr)
        log.flush()
        if result.returncode:
            failures.append(command)
    require(not strict or not failures, 'APPLICATION_CACHE_CLEAR_FAILED: ' + ','.join(failures))


def crm_check(root, php, log):
    result = run([php, str(root / 'artisan'), 'crm:check'], cwd=root)
    log.write(b'crm:check\n' + result.stdout + result.stderr)
    log.flush()
    reports = []
    for line in result.stdout.decode('utf-8', errors='replace').splitlines():
        try:
            reports.append(json.loads(line))
        except ValueError:
            pass
    require(result.returncode == 0 and any(isinstance(report, dict)
            and report.get('ok') is True and type(report.get('verified_routes')) is int
            and report['verified_routes'] >= 14 and report.get('requests_dispatched') == 0
            for report in reports), 'CUSTOMER_CRM_ROUTE_CHECK_FAILED')


def rollback(root, published, originals):
    review = []
    for relative, after in reversed(published):
        try:
            target = root / relative
            before, original_info = originals[relative]
            observed, info = read_source(target)
            require(info.st_gid == original_info.st_gid and stat.S_IMODE(info.st_mode)
                    == stat.S_IMODE(original_info.st_mode), 'UNOWNED_SOURCE_METADATA_CHANGE')
            if observed == before:
                continue
            require(observed == after, 'UNOWNED_CHANGE_DURING_ROLLBACK')
            atomic(target, before, original_info)
            require(read_source(target)[0] == before, 'ROLLBACK_VERIFICATION_FAILED')
        except (RuntimeError, OSError, subprocess.SubprocessError):
            review.append(relative)
    return review


def install(root, revision, php, check_only=False):
    os.umask(0o077)
    validate_release(root, revision)
    private = root.parent / 'customer-followup-template-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(private.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700, 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    descriptor = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid()
                and stat.S_IMODE(info.st_mode) == 0o600, 'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals, pending = {}, []
            for relative in SOURCE_PATHS:
                before, source_info = read_source(root / relative)
                originals[relative] = (before, source_info)
                after = merge_candidate(stage, relative, before,
                                        release_body(root, BASE_REVISION, relative),
                                        release_body(root, revision, relative))
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                staged.write_bytes(after)
                if after != before:
                    pending.append((relative, after))
            lint_sources(php, stage)
            route_check(root, php, stage=stage)
            for relative in SOURCE_PATHS:
                require(same_source(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            print(MARKER + '_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None
            if not pending:
                route_check(root, php)
                print(MARKER + '_OK already_current=1 files=0', flush=True)
                return None
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=stamp + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION, 'status': 'prepared',
                       'database_writes': False, 'messages_sent': False, 'settings_enabled': False,
                       'files': []}
            for relative, after in pending:
                before, source_info = originals[relative]
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(before)
                saved.chmod(0o600)
                require(digest(saved.read_bytes()) == digest(before), 'BACKUP_HASH_MISMATCH')
                receipt['files'].append({'path': relative, 'before_sha256': digest(before),
                                         'after_sha256': digest(after),
                                         'mode': stat.S_IMODE(source_info.st_mode),
                                         'uid': source_info.st_uid, 'gid': source_info.st_gid})
            receipt_path = backup / 'receipt.json'
            save_receipt(receipt_path, receipt)
            for relative in SOURCE_PATHS:
                require(same_source(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
            published = []
            with (backup / 'install.log').open('wb') as log:
                try:
                    for relative, after in pending:
                        require(same_source(root / relative, originals[relative]),
                                'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
                        published.append((relative, after))
                        atomic(root / relative, after, originals[relative][1])
                    lint_sources(php, root)
                    clear_caches(root, php, log)
                    route_check(root, php, log=log)
                    crm_check(root, php, log)
                    expected = dict(pending)
                    for relative, (before, source_info) in originals.items():
                        observed, info = read_source(root / relative)
                        require(observed == expected.get(relative, before)
                                and info.st_gid == source_info.st_gid and stat.S_IMODE(info.st_mode)
                                == stat.S_IMODE(source_info.st_mode),
                                'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                except BaseException:
                    review = rollback(root, published, originals)
                    receipt['status'] = 'rollback_review_required' if review else 'code_restored'
                    receipt['review'] = review
                    save_receipt(receipt_path, receipt)
                    try:
                        clear_caches(root, php, log, strict=False)
                    except (RuntimeError, OSError, subprocess.SubprocessError):
                        log.write(b'Rollback cache clearing requires review.\n')
                    print(MARKER + ('_ROLLBACK_REVIEW_REQUIRED' if review else '_CODE_RESTORED')
                          + '; BACKUP=' + str(backup), flush=True)
                    raise
            receipt['status'] = 'installed'
            save_receipt(receipt_path, receipt)
            print(MARKER + '_OK files=' + str(len(pending)) + '; BACKUP=' + str(backup), flush=True)
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
