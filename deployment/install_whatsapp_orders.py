#!/usr/bin/env python3
"""Add guarded WhatsApp drafts, replies and read state without activating sends."""
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

BASE_RELEASE = '347ac438b97221ec4b889f9d6dc31521f770c369'
BASE_HELPER_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
APP_ROOT = pathlib.Path('/home/fasakha/public_html')
MIGRATION = 'database/migrations/2026_10_10_000002_create_whatsapp_order_drafts.php'
MIGRATIONS = (MIGRATION,
              'database/migrations/2026_10_10_000003_create_whatsapp_reply_requests.php',
              'database/migrations/2026_10_10_000004_create_whatsapp_inbox_reads.php')
CACHE_PATH = 'bootstrap/cache/config.php'
ROUTES_PATH = 'routes/admin.php'
ORDERS_REQUIRE = b"require __DIR__.'/whatsapp_orders.php';"
ROUTE_REQUIRES = (ORDERS_REQUIRE, b"require __DIR__.'/whatsapp_replies.php';",
                  b"require __DIR__.'/whatsapp_reads.php';")
CONFIG_NAMES = ('whatsapp_orders', 'whatsapp_replies')
UPGRADE_PATHS = (
    'resources/views/admin/whatsapp/index.blade.php',
    'public/js/dashboard-whatsapp-inbox.js',
)
MANIFEST = (
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php',
    'app/Support/WhatsAppOrderExtraction.php',
    'config/whatsapp_orders.php',
    'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
    'app/Console/Commands/ProcessWhatsAppOrders.php',
    *MIGRATIONS,
    'app/Http/Controllers/Dashboard/WhatsAppOrderController.php',
    'routes/whatsapp_orders.php',
    'public/js/dashboard-whatsapp-orders.js',
    'public/css/dashboard-whatsapp-orders.css',
    'resources/views/admin/whatsapp/orders.blade.php',
    'app/Services/Dashboard/WhatsAppReplyService.php',
    'app/Services/Dashboard/WhatsAppVoiceMedia.php',
    'app/Http/Controllers/Dashboard/WhatsAppReplyController.php',
    'config/whatsapp_replies.php',
    'routes/whatsapp_replies.php',
    'app/Services/Dashboard/WhatsAppInboxReadState.php',
    'app/Http/Controllers/Dashboard/WhatsAppInboxReadController.php',
    'routes/whatsapp_reads.php',
    'resources/views/admin/whatsapp/replies.blade.php',
    'public/js/dashboard-whatsapp-replies.js',
    'public/css/dashboard-whatsapp-replies.css',
    *UPGRADE_PATHS,
    'tests/whatsapp_order_extraction_test.php',
    'tests/whatsapp_order_workflow_test.php',
    'tests/whatsapp_orders_ui_test.js',
    'tests/whatsapp_inbox_reads_test.php',
    'tests/whatsapp_voice_media_test.php',
    'tests/whatsapp_reply_service_test.php',
    'tests/whatsapp_replies_ui_test.js',
    'deployment/configure_whatsapp_orders.py',
    'tests/whatsapp_orders_config_test.py',
    'deployment/install_whatsapp_orders.py',
    'tests/whatsapp_orders_install_test.py',
    'docs/whatsapp-orders.md',
)
BASE_GUARDS = (
    'app/Support/WhatsAppInboxProtocol.php',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php',
    'app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php',
    'app/Console/Commands/ConsumeWhatsAppInbox.php',
    'app/Services/Dashboard/WhatsAppInboxAccess.php',
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php',
    'routes/whatsapp_inbox.php',
    'resources/views/admin/whatsapp/sidebar.blade.php',
    'public/css/dashboard-whatsapp-inbox.css',
    'database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php',
    'deployment/install_whatsapp_inbox.py',
)


def load_base_helper():
    path = pathlib.Path(__file__).absolute().parent / 'install_whatsapp_inbox.py'
    for parent in reversed(path.parents):
        metadata = parent.lstat()
        if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_BASE_HELPER_PATH')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_BASE_HELPER_OWNER_OR_TYPE')
        if stat.S_IMODE(metadata.st_mode) & 0o022:
            raise RuntimeError('BASE_HELPER_IS_WRITABLE_BY_OTHERS')
        content = os.read(descriptor, 1024 * 1024 + 1)
    finally:
        os.close(descriptor)
    observed = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
    if observed != BASE_HELPER_BLOB:
        raise RuntimeError('BASE_HELPER_HASH_NEEDS_REVIEW')
    # Execute the verified bytes, avoiding a second pathname read after verification.
    name = 'whatsapp_orders_verified_base_installer'
    spec = importlib.util.spec_from_loader(name, loader=None)
    module = importlib.util.module_from_spec(spec)
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


try:
    base = load_base_helper()
except BaseException:
    if __name__ == '__main__':
        print('ORDERS_BASE_HELPER_VERIFICATION_FAILED', file=sys.stderr)
        sys.exit(1)
    raise
SafeError = base.SafeError


def read_git_file(root, release, relative, runner):
    code, tree = runner(root, ['git', 'ls-tree', '-z', release, relative], capture=True)
    pattern = rb'(100644|100755) blob [0-9a-f]{40}\t' + re.escape(relative.encode()) + rb'\x00'
    if code != 0 or re.fullmatch(pattern, tree) is None:
        raise SafeError('ORDERS_PINNED_FILE_NOT_REGULAR')
    code, content = runner(root, ['git', 'show', release + ':' + relative], capture=True)
    if code != 0 or not content or len(content) > 8 * 1024 * 1024:
        raise SafeError('ORDERS_PINNED_FILE_MISSING_OR_INVALID')
    return content


def route_proposal(content, original_hash=base.ROUTES_HASH):
    if not any(name in content for name in (b'whatsapp_orders.php', b'whatsapp_replies.php', b'whatsapp_reads.php')):
        if base.route_proposal(content, original_hash) != content:
            raise SafeError('ORDERS_REQUIRE_EXISTING_INBOX_ROUTE')
        newline = b'\r\n' if b'\r\n' in content else b'\n'
        return content + b''.join(newline + require + newline for require in ROUTE_REQUIRES)
    for newline in (b'\n', b'\r\n'):
        suffix = b''.join(newline + require + newline for require in ROUTE_REQUIRES)
        if content.endswith(suffix):
            original = content[:-len(suffix)]
            if base.route_proposal(original, original_hash) == original and route_proposal(original, original_hash) == content:
                return content
    raise SafeError('ORDERS_ROUTE_HASH_OR_ANCHOR_NEEDS_REVIEW')


def env_fingerprint(root, uid):
    # Guard concurrent environment edits without reading or copying dotenv values.
    base.safe_ancestors(root / '.env', uid, root)
    descriptor = os.open(root / '.env', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid:
            raise SafeError('ENV_OWNER_OR_TYPE_NEEDS_REVIEW')
        return (metadata.st_uid, metadata.st_gid, stat.S_IMODE(metadata.st_mode),
                metadata.st_dev, metadata.st_ino, metadata.st_size,
                metadata.st_mtime_ns, metadata.st_ctime_ns)
    finally:
        os.close(descriptor)


PRECHECK_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->getCachedConfigPath() !== getcwd() . '/bootstrap/cache/config.php') exit(1);
    foreach (['whatsapp_webhook_events', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages', 'whatsapp_inbox_ingestion_failures'] as $table) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) exit(1);
    }
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

CONFIG_CANDIDATE_PHP = r'''
ini_set('display_errors', '0');
try {
    // A private, absent path makes the fresh bootstrap ignore the live cache.
    putenv('APP_CONFIG_CACHE=' . $argv[2]);
    $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $argv[2];
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->getCachedConfigPath() !== $argv[2]) exit(1);
    $original = require $argv[1];
    if (!is_array($original)) exit(1);
    $changed = false;
    foreach (['whatsapp_orders', 'whatsapp_replies'] as $name) {
        $feature = config($name);
        if (!is_array($feature)) exit(1);
        $changed = $changed || !array_key_exists($name, $original) || $original[$name] !== $feature;
        $original[$name] = $feature;
    }
    if (!$changed) {
        $bytes = file_get_contents($argv[1]);
    } else {
        // All other effective settings retain their current cached values.
        $bytes = '<?php return ' . var_export($original, true) . ';' . PHP_EOL;
    }
    if (!is_string($bytes) || file_put_contents($argv[3], $bytes) !== strlen($bytes)) exit(1);
    chmod($argv[3], 0600);
    $candidate = require $argv[3];
    if (!is_array($candidate) || $candidate !== $original) exit(1);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

SMOKE_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    foreach (['whatsapp_webhook_events', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages', 'whatsapp_inbox_ingestion_failures', 'whatsapp_order_scans', 'whatsapp_order_drafts', 'whatsapp_reply_requests', 'whatsapp_inbox_reads'] as $table) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) exit(1);
    }
    if (!array_key_exists('whatsapp:process-orders', $kernel->all())) exit(1);
    if (app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess(null)) exit(1);
    $routes = app('router')->getRoutes();
    $orders = [];
    foreach ($routes as $route) {
        if (!preg_match('/\Awhatsapp-(?:orders|replies|reads)\./', (string) $route->getName())) continue;
        if (!in_array('IsAdmin', $route->gatherMiddleware(), true)) exit(1);
        $orders[] = $route;
    }
    if (count($orders) < 1) exit(1);
    foreach (['whatsapp-orders.meta', 'whatsapp-orders.catalog', 'whatsapp-orders.state', 'whatsapp-orders.analyze', 'whatsapp-orders.quote', 'whatsapp-orders.dispatch', 'whatsapp-replies.state', 'whatsapp-replies.send', 'whatsapp-replies.voice', 'whatsapp-inbox.unread', 'whatsapp-inbox.read'] as $name) {
        $requiredRoute = $routes->getByName($name);
        if (!$requiredRoute || !in_array('IsAdmin', $requiredRoute->gatherMiddleware(), true)) exit(1);
    }
    $guestRoute = $routes->getByName('whatsapp-orders.meta');
    $request = \Illuminate\Http\Request::create('/' . $guestRoute->uri(), 'GET');
    $request->headers->set('Accept', 'application/json');
    $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
    if (!in_array($response->getStatusCode(), [302, 401, 403], true)) exit(1);
    $config = config('whatsapp_orders');
    $replyConfig = config('whatsapp_replies');
    if (!is_array($config) || !is_array($replyConfig)) exit(1);
    $erp = true;
    foreach (['takeaway_tills', 'takeaway_orders', 'takeaway_order_items', 'takeaway_till_entries', 'pos_service_tickets', 'pos_service_commands', 'pos_service_settings', 'pos_service_tables', 'pos_service_kitchen_tickets', 'branch_operation_commands', 'branch_payrolls', 'branch_customers', 'branch_delivery_companies', 'pos_branch_print_jobs'] as $table) {
        $erp = \Illuminate\Support\Facades\Schema::hasTable($table) && $erp;
    }
    foreach ([
        'pos_service_tickets' => ['bill_issued_at', 'bill_issued_by', 'bill_issued_revision', 'customer_id', 'delivery_company_id', 'delivery_company_snapshot', 'delivery_snapshot'],
        'takeaway_orders' => ['channel', 'tenders_snapshot'],
        'branch_customers' => ['branch', 'phone_key', 'revision', 'actor_id', 'name', 'phone', 'address', 'area', 'delivery_notes', 'latitude', 'longitude'],
        'branch_delivery_companies' => ['id', 'branch', 'name', 'phone', 'contact_name', 'address', 'active'],
        'pos_branch_print_jobs' => ['branch', 'ticket_id', 'kitchen_id', 'status'],
    ] as $table => $columns) {
        $erp = \Illuminate\Support\Facades\Schema::hasColumns($table, $columns) && $erp;
    }
    $actorReady = false;
    $actorId = (string) ($config['automation_actor_id'] ?? '');
    if (preg_match('/\A[1-9][0-9]{0,18}\z/', $actorId)) {
        try {
            $actor = \App\Models\User::withoutGlobalScopes()->find($actorId);
            $actorReady = $actor && app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess($actor);
        } catch (\Throwable $error) { $actorReady = false; }
    }
    $branchesReady = false;
    $businessHours = false;
    if (class_exists(\App\Models\Resturant::class)) {
        $restaurant = new \App\Models\Resturant();
        $businessHours = method_exists($restaurant, 'isWithinBusinessHours')
            && method_exists($restaurant, 'getEffectiveStatusAttribute')
            && \Illuminate\Support\Facades\Schema::hasColumns($restaurant->getTable(), ['status', 'open_at', 'close_at'])
            && config('app.timezone') === 'Africa/Cairo';
        $ids = $config['allowed_branch_ids'] ?? null;
        if (is_array($ids) && count($ids) > 0) {
            try {
                $valid = count(array_filter($ids, static fn ($id) => preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $id))) === count($ids);
                $branchesReady = $valid && \App\Models\Resturant::withoutGlobalScopes()->whereIn('id', $ids)->count() === count($ids);
            } catch (\Throwable $error) { $branchesReady = false; }
        }
    }
    $state = [
        'enabled' => in_array($config['enabled'] ?? false, [true, 1, '1'], true),
        'key_configured' => is_string($config['api_key'] ?? null)
            && strlen($config['api_key']) >= 16 && strlen($config['api_key']) <= 512
            && preg_match('/\s|[\x00-\x1f\x7f]/', $config['api_key']) === 0,
        'model_configured' => is_string($config['model'] ?? null)
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/', $config['model']) === 1,
        'actor_configured' => (bool) $actorReady,
        'branches_configured' => (bool) $branchesReady,
        'activation_configured' => preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', (string) ($config['activation_message_id'] ?? '')) === 1
            && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', (string) ($config['activation_event_id'] ?? '')) === 1,
        'mode_supported' => in_array($config['mode'] ?? null, ['review', 'auto'], true),
        'auto_mode' => ($config['mode'] ?? null) === 'auto',
        'erp_schema_ready' => (bool) $erp,
        'auto_business_hours_ready' => (bool) $businessHours,
        'replies_enabled' => in_array($replyConfig['enabled'] ?? false, [true, 1, '1'], true),
        'reply_token_configured' => is_string($replyConfig['access_token'] ?? null)
            && strlen($replyConfig['access_token']) >= 16 && strlen($replyConfig['access_token']) <= 4096
            && preg_match('/\s|[\x00-\x1f\x7f]/', $replyConfig['access_token']) === 0,
        'voice_tools_available' => (new \App\Services\Dashboard\WhatsAppVoiceMedia())->available(),
    ];
    echo json_encode($state, JSON_THROW_ON_ERROR);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''
READINESS_KEYS = ('enabled', 'key_configured', 'model_configured', 'actor_configured',
                  'branches_configured', 'activation_configured', 'mode_supported',
                  'auto_mode', 'erp_schema_ready', 'auto_business_hours_ready',
                  'replies_enabled', 'reply_token_configured', 'voice_tools_available')


def save_private(path, content):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())


def stage_release(root, release, destination, runner):
    code, kind = runner(root, ['git', 'cat-file', '-t', release], capture=True)
    if code != 0 or kind.strip() != b'commit':
        raise SafeError('ORDERS_RELEASE_NOT_A_COMMIT')
    contents = {}
    for relative in MANIFEST:
        contents[relative] = read_git_file(root, release, relative, runner)
        path = destination / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(contents[relative])
        if relative.endswith('.php'):
            code, _ = runner(destination, ['php', '-l', relative])
            if code != 0:
                raise SafeError('ORDERS_PINNED_PHP_LINT_FAILED')
    code, _ = runner(destination, ['php', 'tests/whatsapp_order_extraction_test.php'])
    if code != 0:
        raise SafeError('ORDERS_EXTRACTION_FIXTURES_FAILED')
    if any((destination / relative).read_bytes() != content for relative, content in contents.items()):
        raise SafeError('ORDERS_STAGE_CHANGED_DURING_VALIDATION')
    return contents


def rollback(root, uid, written, created, before, preexisting_dependencies=False):
    # Every existing UI/config/include is a dependency anchor for this upgrade.
    anchors = [(relative, item) for relative, item in reversed(list(written.items()))
               if item.before is not None]
    additions = [(relative, item) for relative, item in reversed(list(written.items()))
                 if item.before is None]
    # A partial previous install can already contain a target include while
    # missing some dependencies that this run repaired. Do not undo that repair.
    stopped = bool(preexisting_dependencies and additions)
    with base.blocked_signals():
        # An unpublished anchor may acquire an external include/cache subtree
        # while additions are being staged. Keep its dependencies in that case.
        for relative in (*UPGRADE_PATHS, ROUTES_PATH, CACHE_PATH):
            if relative not in written:
                try:
                    stopped = base.snapshot(root / relative, uid, root, absent_ok=True) != before[relative] or stopped
                except BaseException:
                    stopped = True
        for _, publication in anchors:
            try:
                stopped = not base.recover_publication(publication, root, uid) or stopped
            except BaseException:
                stopped = True
        for relative in (*UPGRADE_PATHS, ROUTES_PATH, CACHE_PATH):
            try:
                stopped = base.snapshot(root / relative, uid, root, absent_ok=True) != before[relative] or stopped
            except BaseException:
                stopped = True
        if not stopped:
            for _, publication in additions:
                try:
                    stopped = not base.recover_publication(publication, root, uid) or stopped
                except BaseException:
                    stopped = True
        for directory, inode in reversed(created):
            try:
                metadata = directory.lstat()
                if stat.S_ISDIR(metadata.st_mode) and metadata.st_uid == uid and metadata.st_ino == inode:
                    directory.rmdir()
            except OSError:
                pass
    return not stopped


def install(root, uid, release, runner=base.command, core_hashes=base.CORE_HASHES,
            routes_hash=base.ROUTES_HASH, menu_hash=base.MENU_HASH):
    if not re.fullmatch(r'[0-9a-f]{40}', release):
        raise SafeError('INVALID_ORDERS_RELEASE')
    base.validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'
    base.private_parent(parent, uid)
    descriptor = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_uid != uid or stat.S_IMODE(metadata.st_mode) != 0o600:
            raise SafeError('ORDERS_LOCK_OWNER_OR_TYPE_NEEDS_REVIEW')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_ORDERS_INSTALL_IS_RUNNING') from None
        guarded = {}
        for relative, wanted in core_hashes.items():
            item = base.snapshot(root / relative, uid, root)
            if base.blob_hash(item.content) != wanted:
                raise SafeError('ORDERS_CORE_HASH_NEEDS_REVIEW')
            guarded[relative] = item
        for relative in BASE_GUARDS:
            item = base.snapshot(root / relative, uid, root)
            expected = read_git_file(root, BASE_RELEASE, relative, runner)
            if item.content != expected:
                raise SafeError('ORDERS_LIVE_INBOX_BASE_NEEDS_REVIEW')
            guarded[relative] = item
        kernel = base.snapshot(root / 'app/Console/Kernel.php', uid, root)
        if kernel.content.count(b"$this->load(__DIR__.'/Commands');") != 1:
            raise SafeError('ORDERS_COMMAND_AUTOLOAD_NEEDS_REVIEW')
        guarded['app/Console/Kernel.php'] = kernel
        menu = base.snapshot(root / base.MENU_PATH, uid, root)
        if base.menu_proposal(menu.content, menu_hash) != menu.content:
            raise SafeError('ORDERS_REQUIRE_EXISTING_INBOX_MENU')
        guarded[base.MENU_PATH] = menu
        env_before = env_fingerprint(root, uid)
        before = {relative: base.snapshot(root / relative, uid, root, absent_ok=True) for relative in MANIFEST}
        before[ROUTES_PATH] = base.snapshot(root / ROUTES_PATH, uid, root)
        before[CACHE_PATH] = base.snapshot(root / CACHE_PATH, uid, root, absent_ok=True)
        proposals = {ROUTES_PATH: route_proposal(before[ROUTES_PATH].content, routes_hash)}
        backup = pathlib.Path(tempfile.mkdtemp(prefix='orders-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('ORDERS_BACKUP_REQUIRE_SAME_FILESYSTEM')
        publications = backup / 'publications'
        publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        staged = backup / 'release'
        staged.mkdir(mode=0o700)
        proposals.update(stage_release(root, release, staged, runner))
        old_ui = {}
        for relative in MANIFEST:
            current = before[relative]
            if relative in UPGRADE_PATHS:
                old = read_git_file(root, BASE_RELEASE, relative, runner)
                old_ui[relative] = old
                if current is None or current.content not in (old, proposals[relative]):
                    raise SafeError('ORDERS_EXISTING_WHATSAPP_UI_NEEDS_REVIEW')
            elif current is not None and current.content != proposals[relative]:
                raise SafeError('ORDERS_EXISTING_ADDITION_NEEDS_REVIEW')
        preexisting_dependencies = before[ROUTES_PATH].content == proposals[ROUTES_PATH] or any(
            before[relative].content == proposals[relative] and old_ui[relative] != proposals[relative]
            for relative in UPGRADE_PATHS)
        composed_route = backup / 'composed-admin.php'
        save_private(composed_route, proposals[ROUTES_PATH])
        code, _ = runner(root, ['php', '-l', str(composed_route)])
        if code != 0:
            raise SafeError('ORDERS_COMPOSED_ROUTE_LINT_FAILED')
        code, _ = runner(root, ['php', '-r', PRECHECK_PHP])
        if code != 0:
            raise SafeError('ORDERS_EXISTING_SCHEMA_OR_CACHE_PATH_NOT_READY')
        receipt = {'release': release, 'base_release': BASE_RELEASE, 'files': {}}
        originals = backup / 'before'
        originals.mkdir(mode=0o700)
        for index, (relative, item) in enumerate(before.items()):
            receipt['files'][relative] = None if item is None else {
                'uid': item.uid, 'gid': item.gid, 'mode': item.mode, 'blob': base.blob_hash(item.content), 'copy': str(index)}
            if item is not None:
                save_private(originals / str(index), item.content)
        save_private(backup / 'receipt.json', (json.dumps(receipt, indent=2) + '\n').encode())
        written, created = {}, []
        try:
            for relative, item in {**guarded, **before}.items():
                base.assert_snapshot(root / relative, item, uid, root)
            if env_fingerprint(root, uid) != env_before:
                raise SafeError('ORDERS_ENV_CHANGED_CONCURRENTLY')
            for relative in MANIFEST:
                if relative in UPGRADE_PATHS:
                    continue
                current = before[relative]
                if current is None or current.content != proposals[relative]:
                    base.replace_file(root / relative, proposals[relative], current, uid, root, created,
                                      journal=written, relative=relative, private_directory=publications)
            cache_before = before[CACHE_PATH]
            if cache_before is not None:
                cache_copy = originals / receipt['files'][CACHE_PATH]['copy']
                candidate = backup / 'candidate-config.php'
                absent_cache = backup / 'fresh-uncached.php'
                code, _ = runner(root, ['php', '-r', CONFIG_CANDIDATE_PHP, str(cache_copy), str(absent_cache), str(candidate)])
                if code != 0:
                    raise SafeError('ORDERS_CONFIG_CANDIDATE_FAILED')
                proposed_cache = base.snapshot(candidate, uid, root).content
                code, _ = runner(root, ['php', '-l', str(candidate)])
                if code != 0:
                    raise SafeError('ORDERS_CONFIG_CANDIDATE_LINT_FAILED')
                if env_fingerprint(root, uid) != env_before:
                    raise SafeError('ORDERS_ENV_CHANGED_CONCURRENTLY')
                if proposed_cache != cache_before.content:
                    base.replace_file(root / CACHE_PATH, proposed_cache, cache_before, uid, root, created,
                                      journal=written, relative=CACHE_PATH, private_directory=publications)
            for migration in MIGRATIONS:
                code, _ = runner(root, ['php', 'artisan', 'migrate', '--path=' + migration, '--force'])
                if code != 0:
                    raise SafeError('ORDERS_MIGRATION_FAILED_TABLES_RETAINED')
            for relative in (*UPGRADE_PATHS, ROUTES_PATH):
                current = before[relative]
                if current is None or current.content != proposals[relative]:
                    base.replace_file(root / relative, proposals[relative], current, uid, root, created,
                                      journal=written, relative=relative, private_directory=publications)
            code, _ = runner(root, ['php', 'artisan', 'route:clear'])
            if code != 0:
                raise SafeError('ORDERS_ROUTE_CLEAR_FAILED')
            code, output = runner(root, ['php', '-r', SMOKE_PHP], capture=True)
            if code != 0:
                raise SafeError('ORDERS_SMOKE_FAILED')
            state = json.loads(output)
            if not isinstance(state, dict) or set(state) != set(READINESS_KEYS) or any(type(state[key]) is not bool for key in READINESS_KEYS):
                raise SafeError('ORDERS_SMOKE_REPORT_INVALID')
            for relative, item in guarded.items():
                base.assert_snapshot(root / relative, item, uid, root)
            for relative, item in before.items():
                publication = written.get(relative)
                base.assert_snapshot(root / relative, publication.after if publication is not None else item, uid, root)
            if env_fingerprint(root, uid) != env_before:
                raise SafeError('ORDERS_ENV_CHANGED_CONCURRENTLY')
        except BaseException:
            restored = rollback(root, uid, written, created, before, preexisting_dependencies)
            cleared, _ = runner(root, ['php', 'artisan', 'route:clear'])
            status = 'ORDERS_FILES_RESTORED_TABLES_RETAINED' if restored else 'ORDERS_ROLLBACK_STOPPED_CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'
            if cleared != 0:
                status += '_ROUTE_RECHECK_REQUIRED'
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
        configured = all(state[key] for key in ('enabled', 'key_configured', 'model_configured',
                                               'actor_configured', 'branches_configured',
                                               'activation_configured', 'mode_supported'))
        if not configured:
            result = 'WHATSAPP_ORDERS_READY_CONFIG_REQUIRED'
        elif not state['erp_schema_ready']:
            result = 'WHATSAPP_ORDERS_READY_ERP_SCHEMA_REQUIRED'
        elif state['auto_mode'] and not state['auto_business_hours_ready']:
            result = 'WHATSAPP_ORDERS_READY_AUTOMATION_GATES_REQUIRED'
        else:
            result = 'WHATSAPP_ORDERS_READY_CONFIGURED'
        return result, backup, state
    finally:
        os.close(descriptor)


def main():
    try:
        arguments = sys.argv[1:]
        if len(arguments) != 4 or arguments[0] != '--root' or arguments[2] != '--release':
            raise SafeError('USAGE_ORDERS_ROOT_AND_PINNED_RELEASE')
        root = pathlib.Path(arguments[1])
        if root != APP_ROOT or os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_ORDERS_INSTALL_AS_FASAKHA_FOR_CONFIRMED_ROOT')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('ORDERS_INSTALL_INTERRUPTED')))
        result, backup, state = install(root, os.geteuid(), arguments[3])
        print(result)
        print('BACKUP=' + str(backup))
        print('CONFIGURATION_FLAGS=' + json.dumps(state, sort_keys=True))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('ORDERS_INSTALL_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
