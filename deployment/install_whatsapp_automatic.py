#!/usr/bin/env python3
"""Upgrade a fixed WhatsApp runtime manifest without changing live configuration."""
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
BASE_HELPER_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
CACHE_PATH = 'bootstrap/cache/config.php'
CONFIG_PATHS = ('config/whatsapp_orders.php', 'config/whatsapp_replies.php')
WORKFLOW_PATH = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
# These are the actually installed feature release, plus the verified historical guard.
SOURCE_BLOBS = {
    'app/Support/WhatsAppInboxProtocol.php': '3ef9d915b639f3f13d1396f3dd46d0a2cca4e888',
    'app/Support/WhatsAppOrderExtraction.php': '26f493bd3094afcc92ca4ec60cf5452d728bd62d',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php': '0bc90e89075b51ea031f323a1b1f46901f31e6c4',
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php': '55a7a88f9d432671e2e9be5dcbf25d3a2ed60fde',
    WORKFLOW_PATH: 'ee937d66677dcaa0a7e405fb83f353596c80c9c0',
    'app/Console/Commands/ProcessWhatsAppOrders.php': '80aef4ea498ec96db21bde114abd45bf7a1e508e',
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': 'b52b89f8bfc12e4fd40bda127d093dd52914a3c9',
    'app/Http/Controllers/Dashboard/WhatsAppOrderController.php': 'd1cfbc538a2e11fc3c37eb4e2b502b78509e2f08',
    'routes/whatsapp_orders.php': '984c7f2272cc0c0a3e0314388a9925604e50d999',
    'public/js/dashboard-whatsapp-inbox.js': '60348b6f3abcb0c71ddb248756eaef0483581364',
    'public/js/dashboard-whatsapp-orders.js': 'c7ac9e1119b7d9153e6e5cf8bf8ac30106b148f3',
    'public/css/dashboard-whatsapp-orders.css': '3bbf57dc737b2e5b38e29af6f7662b22d16ec555',
    'public/css/dashboard-whatsapp-inbox.css': '597a74f9de593b45455634019596894d34d1289b',
    'resources/views/admin/whatsapp/orders.blade.php': '23d112518d9042019e01ff3f000aa6fb2a8420bc',
}
NEW_PATHS = ('config/whatsapp_cart.php',)
# Dependencies precede the workflow. The command is last; rollback restores entry points first.
MANIFEST = tuple(path for path in SOURCE_BLOBS if path != WORKFLOW_PATH
                 and path != 'app/Console/Commands/ProcessWhatsAppOrders.php') + NEW_PATHS + (
                     WORKFLOW_PATH, 'app/Console/Commands/ProcessWhatsAppOrders.php')
PROTECTED_PATHS = ('.env', CACHE_PATH, *CONFIG_PATHS, 'routes/admin.php',
                   'resources/views/admin/index.blade.php',
                   'resources/views/admin/layouts/menu.blade.php',
                   'app/Console/Kernel.php',
                   'app/Services/Dashboard/TakeawayAccess.php',
                   'app/Services/Dashboard/BranchCustomers.php',
                   'app/Services/Dashboard/TakeawayCatalog.php',
                   'app/Services/Dashboard/TakeawayService.php',
                   'app/Services/Dashboard/PhoneDelivery.php',
                   'app/Services/Dashboard/PosServiceTicket.php',
                   'app/Services/Dashboard/PosServicePhone.php',
                   'app/Services/Dashboard/PosBranchPrinting.php',
                   'app/Services/Dashboard/PhoneMapProvider.php',
                   'resources/views/admin/maps/assets.blade.php',
                   'public/dashboard/js/dashboard-location-picker.js',
                   'public/dashboard/vendor/leaflet/leaflet.js',
                   'public/dashboard/vendor/leaflet/leaflet.css')


def load_base_helper():
    path = pathlib.Path(__file__).absolute().parent / 'install_whatsapp_inbox.py'
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_BASE_HELPER_PATH')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or stat.S_IMODE(info.st_mode) & 0o022):
            raise RuntimeError('UNSAFE_BASE_HELPER_OWNER_OR_TYPE')
        content = os.read(descriptor, 1024 * 1024 + 1)
    finally:
        os.close(descriptor)
    if hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() != BASE_HELPER_BLOB:
        raise RuntimeError('BASE_HELPER_HASH_NEEDS_REVIEW')
    name = 'whatsapp_automatic_verified_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


try:
    base = load_base_helper()
except BaseException:
    if __name__ == '__main__':
        print('AUTOMATIC_BASE_HELPER_VERIFICATION_FAILED', file=sys.stderr)
        sys.exit(1)
    raise
SafeError = base.SafeError

READINESS_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if ($app->getCachedConfigPath() !== getcwd() . '/bootstrap/cache/config.php') exit(1);
    $class = \App\Services\Dashboard\WhatsAppOrderWorkflow::class;
    if ((new \ReflectionClass($class))->getFileName() !== getcwd() . '/app/Services/Dashboard/WhatsAppOrderWorkflow.php'
        || !app($class)->available() || !array_key_exists('whatsapp:process-orders', $kernel->all())) exit(1);
    if (!method_exists($class, 'activationBoundary') || !method_exists($class, 'automaticFloor')) exit(1);
    $orders = config('whatsapp_orders'); $replies = config('whatsapp_replies');
    if (!is_array($orders) || !is_array($replies)) exit(1);
    $routesCache = $app->getCachedRoutesPath();
    if (!preg_match('/\A' . preg_quote(getcwd() . '/bootstrap/cache/routes', '/') . '(?:-v[0-9]+)?\.php\z/', $routesCache)) exit(1);
    if (($argv[1] ?? '') === 'target') {
        if ((new \ReflectionClass($class))->getConstant('AUTOMATIC_ACTIVATION_TIME_GUARD') !== 'whatsapp-auto-activation-time-v1') exit(1);
        if ((new \ReflectionClass(\App\Services\Dashboard\WhatsAppInboxConsumer::class))->getConstant('QUARANTINE_BATCH_RECHECK') !== true) exit(1);
        foreach (['whatsapp-orders.customers','whatsapp-orders.delivery-settings','whatsapp-orders.address-suggestions','whatsapp-orders.delivery-quote'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            if (!$route || !in_array('IsAdmin', $route->gatherMiddleware(), true)) exit(1);
        }
    }
    echo json_encode(['review' => ($orders['mode'] ?? null) === 'review',
        'fingerprint' => hash('sha256', serialize([$orders, $replies])),
        'route_cache_path' => substr($routesCache, strlen(getcwd()) + 1)], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) { exit(1); }
'''


def fingerprint(root, uid, relative, absent_ok=False):
    base.safe_ancestors(root / relative, uid, root)
    try:
        descriptor = os.open(root / relative, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        if absent_ok:
            return None
        raise SafeError('AUTOMATIC_REQUIRED_FILE_MISSING') from None
    except OSError:
        raise SafeError('AUTOMATIC_FILE_UNSAFE') from None
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid
                or (relative in ('.env', CACHE_PATH) and info.st_nlink != 1)):
            raise SafeError('AUTOMATIC_FILE_OWNER_OR_TYPE_NEEDS_REVIEW; FILE=' + relative)
        return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev, info.st_ino,
                info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
    finally:
        os.close(descriptor)


def readiness(root, runner, phase):
    code, output = runner(root, ['php', '-r', READINESS_PHP, phase], capture=True)
    try:
        result = json.loads(output) if code == 0 else None
    except (TypeError, ValueError):
        result = None
    if (not isinstance(result, dict) or set(result) != {'review', 'fingerprint', 'route_cache_path'}
            or type(result['review']) is not bool or not isinstance(result['fingerprint'], str)
            or re.fullmatch(r'[0-9a-f]{64}', result['fingerprint']) is None
            or not isinstance(result['route_cache_path'], str)
            or re.fullmatch(r'bootstrap/cache/routes(?:-v[0-9]+)?\.php', result['route_cache_path']) is None):
        raise SafeError('AUTOMATIC_READ_ONLY_SMOKE_FAILED')
    return result


def write_private(path, content):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())


def stage_release(root, release, backup, runner, manifest):
    code, kind = runner(root, ['git', 'cat-file', '-t', release], capture=True)
    if code != 0 or kind.strip() != b'commit':
        raise SafeError('AUTOMATIC_RELEASE_NOT_A_COMMIT')
    files, pins = {}, {}
    for relative in manifest:
        code, tree = runner(root, ['git', 'ls-tree', '-z', release, relative], capture=True)
        match = re.fullmatch(rb'100644 blob ([0-9a-f]{40})\t' + re.escape(relative.encode()) + rb'\x00', tree)
        if code != 0 or match is None:
            raise SafeError('AUTOMATIC_TARGET_NOT_PINNED_REGULAR_FILE; FILE=' + relative)
        code, content = runner(root, ['git', 'show', release + ':' + relative], capture=True)
        wanted = match[1].decode()
        if code != 0 or not content or len(content) > 8 * 1024 * 1024 or base.blob_hash(content) != wanted:
            raise SafeError('AUTOMATIC_TARGET_BLOB_NEEDS_REVIEW; FILE=' + relative)
        files[relative], pins[relative] = content, wanted
        staged = backup / 'target' / relative
        staged.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        write_private(staged, content)
        if relative.endswith('.php'):
            code, _ = runner(root, ['php', '-l', str(staged)])
            if code != 0 or base.snapshot(staged, os.geteuid(), root).content != content:
                raise SafeError('AUTOMATIC_TARGET_LINT_OR_STAGING_FAILED; FILE=' + relative)
    return files, pins


def install(root, uid, release, runner=base.command, manifest=MANIFEST, source_blobs=SOURCE_BLOBS):
    if re.fullmatch(r'[0-9a-f]{40}', release) is None:
        raise SafeError('AUTOMATIC_INVALID_RELEASE_PIN')
    base.validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'
    base.private_parent(parent, uid)
    descriptor = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    backup = None
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) != 0o600):
            raise SafeError('AUTOMATIC_LOCK_OWNER_OR_TYPE_NEEDS_REVIEW')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_ORDERS_INSTALL_OR_CONFIGURATION_IS_RUNNING') from None
        protected = {relative: (base.snapshot(root / relative, uid, root, relative == CACHE_PATH),
                                fingerprint(root, uid, relative, relative == CACHE_PATH))
                     for relative in PROTECTED_PATHS}

        def unchanged():
            try:
                for relative, (snapshot, metadata) in protected.items():
                    base.assert_snapshot(root / relative, snapshot, uid, root)
                    if fingerprint(root, uid, relative, relative == CACHE_PATH) != metadata:
                        return False
                return True
            except (SafeError, OSError):
                return False

        initial = readiness(root, runner, 'source')
        if not initial['review']:
            raise SafeError('AUTOMATIC_UPGRADE_REQUIRES_REVIEW_MODE')
        route_cache_path = initial['route_cache_path']
        route_cache_before = base.snapshot(root / route_cache_path, uid, root, absent_ok=True)
        route_cache_metadata = fingerprint(root, uid, route_cache_path, absent_ok=True)
        before = {relative: base.snapshot(root / relative, uid, root, relative not in source_blobs)
                  for relative in manifest}
        backup = pathlib.Path(tempfile.mkdtemp(prefix='automatic-upgrade-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('AUTOMATIC_BACKUP_REQUIRES_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        files, pins = stage_release(root, release, backup, runner, manifest)
        for relative, prior in before.items():
            if prior is not None and base.blob_hash(prior.content) not in (source_blobs.get(relative), pins[relative]):
                raise SafeError('AUTOMATIC_LIVE_SOURCE_NEEDS_REVIEW; FILE=' + relative)
        write_private(backup / 'receipt.json', (json.dumps({'release': release, 'target_blobs': pins,
            'source_blobs': {p: None if s is None else base.blob_hash(s.content) for p, s in before.items()}},
            sort_keys=True) + '\n').encode())
        if not unchanged():
            raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
        for relative, snapshot in before.items():
            base.assert_snapshot(root / relative, snapshot, uid, root)
        written, created = {}, []
        route_cache_retained = backup / 'routes-cache.before.php'
        route_cache_moved = False
        effective_changed = False
        try:
            for relative in manifest:
                if before[relative] is not None and before[relative].content == files[relative]:
                    continue
                if not unchanged():
                    raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
                base.replace_file(root / relative, files[relative], before[relative], uid, root, created,
                                  journal=written, relative=relative, private_directory=publications)
            if not written and route_cache_before is not None:
                try:
                    current = readiness(root, runner, 'target')
                except SafeError:
                    current = None  # The existing compiled cache may still omit new routes.
                if current is not None:
                    if current != initial or not unchanged():
                        effective_changed = current != initial
                        raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
                    for relative, snapshot in before.items():
                        base.assert_snapshot(root / relative, snapshot, uid, root)
                    base.assert_snapshot(root / route_cache_path, route_cache_before, uid, root)
                    if fingerprint(root, uid, route_cache_path) != route_cache_metadata:
                        raise SafeError('AUTOMATIC_ROUTES_CACHE_CHANGED_CONCURRENTLY')
                    return 'WHATSAPP_AUTOMATIC_RUNTIME_ALREADY_CURRENT_REVIEW_ONLY', backup
            if route_cache_before is not None:
                with base.blocked_signals():
                    base.assert_snapshot(root / route_cache_path, route_cache_before, uid, root)
                    if fingerprint(root, uid, route_cache_path) != route_cache_metadata:
                        raise SafeError('AUTOMATIC_ROUTES_CACHE_CHANGED_CONCURRENTLY')
                    # Register before the syscall; exceptions after its success remain recoverable.
                    route_cache_moved = True
                    base.atomic_move_noreplace(root / route_cache_path, route_cache_retained)
                    if base.snapshot(route_cache_retained, uid, root) != route_cache_before:
                        raise SafeError('AUTOMATIC_ROUTES_CACHE_CHANGED_DURING_MOVE')
            else:
                base.assert_snapshot(root / route_cache_path, None, uid, root)
            final = readiness(root, runner, 'target')
            if final != initial or not unchanged():
                effective_changed = final != initial
                raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
            for relative in manifest:
                wanted = written[relative].after if relative in written else before[relative]
                base.assert_snapshot(root / relative, wanted, uid, root)
            base.assert_snapshot(root / route_cache_path, None, uid, root)
        except BaseException:
            restored = True
            with base.blocked_signals():
                # Config drift may have enabled auto. Preserve new guards and their dependencies.
                if effective_changed or not unchanged():
                    status = 'CONFIGURATION_CHANGED_REVIEW_REQUIRED_RUNTIME_RETAINED'
                else:
                    for publication in reversed(list(written.values())):
                        try:
                            restored = base.recover_publication(publication, root, uid)
                        except BaseException:
                            restored = False
                        if not restored:
                            break  # A changed entry point can still need every earlier dependency.
                    if restored and route_cache_moved:
                        try:
                            retained = base.snapshot(route_cache_retained, uid, root, absent_ok=True)
                            current_cache = base.snapshot(root / route_cache_path, uid, root, absent_ok=True)
                            if retained is not None and current_cache is None:
                                # Restore the exact captured inode, including a competing pre-move edit.
                                base.atomic_move_noreplace(route_cache_retained, root / route_cache_path)
                                restored = base.snapshot(root / route_cache_path, uid, root) == retained
                            elif retained is not None:
                                restored = False
                            else:
                                restored = current_cache == route_cache_before
                        except BaseException:
                            restored = False
                    status = ('AUTOMATIC_UPGRADE_FILES_RESTORED' if restored else
                              'AUTOMATIC_ROLLBACK_STOPPED_CONCURRENT_FILE_RETAINED')
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
        return ('WHATSAPP_AUTOMATIC_RUNTIME_READY_REVIEW_ONLY' if written else
                'WHATSAPP_AUTOMATIC_RUNTIME_ALREADY_CURRENT_REVIEW_ONLY'), backup
    finally:
        os.close(descriptor)


def main():
    try:
        if len(sys.argv) != 5 or sys.argv[1] != '--root' or sys.argv[3] != '--release':
            raise SafeError('USAGE_AUTOMATIC_INSTALL_CONFIRMED_ROOT_AND_RELEASE_ONLY')
        root = pathlib.Path(sys.argv[2])
        if root != APP_ROOT or os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_AUTOMATIC_INSTALL_AS_FASAKHA_FOR_CONFIRMED_ROOT')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('AUTOMATIC_UPGRADE_INTERRUPTED')))
        result, backup = install(root, os.geteuid(), sys.argv[4])
        print(result)
        print('BACKUP=' + str(backup))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('AUTOMATIC_UPGRADE_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
