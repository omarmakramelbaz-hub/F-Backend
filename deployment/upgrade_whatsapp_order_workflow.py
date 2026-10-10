#!/usr/bin/env python3
"""Publish one pinned workflow guard while preserving review mode and configuration."""
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
WORKFLOW_PATH = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
CACHE_PATH = 'bootstrap/cache/config.php'
CONFIG_PATHS = ('config/whatsapp_orders.php', 'config/whatsapp_replies.php')
TARGET_RELEASE = 'f7ea0c4ab6518f93b94f8c01da437c1c2196ee10'
TARGET_BLOB = 'ee937d66677dcaa0a7e405fb83f353596c80c9c0'
SOURCE_BLOB = '2520c503297445e937504142172de6656575c7b9'
BASE_HELPER_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'


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
    if hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() != BASE_HELPER_BLOB:
        raise RuntimeError('BASE_HELPER_HASH_NEEDS_REVIEW')
    name = 'whatsapp_workflow_upgrade_verified_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


try:
    base = load_base_helper()
except BaseException:
    if __name__ == '__main__':
        print('WORKFLOW_BASE_HELPER_VERIFICATION_FAILED', file=sys.stderr)
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
    if (($argv[1] ?? '') === 'target' && (!method_exists($class, 'activationBoundary')
        || !method_exists($class, 'automaticFloor'))) exit(1);
    $orders = config('whatsapp_orders'); $replies = config('whatsapp_replies');
    if (!is_array($orders) || !is_array($replies)) exit(1);
    echo json_encode(['review' => ($orders['mode'] ?? null) === 'review',
        'fingerprint' => hash('sha256', serialize([$orders, $replies]))], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) { exit(1); }
'''


def env_fingerprint(root, uid, relative='.env', absent_ok=False):
    base.safe_ancestors(root / relative, uid, root)
    try:
        descriptor = os.open(root / relative, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        if absent_ok:
            return None
        raise SafeError('WORKFLOW_CONFIGURATION_FILE_MISSING') from None
    except OSError:
        raise SafeError('WORKFLOW_CONFIGURATION_FILE_UNSAFE') from None
    try:
        metadata = os.fstat(descriptor)
        # Initial additive publication retains a private hardlink to these source config files.
        links_safe = metadata.st_nlink >= 1 if relative in CONFIG_PATHS else metadata.st_nlink == 1
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid or not links_safe:
            details = ''
            if relative in ('.env', CACHE_PATH, *CONFIG_PATHS):
                details = (f'; FILE={relative} OWNER_UID={metadata.st_uid} EXPECTED_UID={uid}'
                           f' LINKS={metadata.st_nlink} MODE={stat.S_IMODE(metadata.st_mode):04o}')
            raise SafeError('WORKFLOW_CONFIGURATION_OWNER_OR_TYPE_NEEDS_REVIEW' + details)
        return (metadata.st_uid, metadata.st_gid, stat.S_IMODE(metadata.st_mode), metadata.st_dev,
                metadata.st_ino, metadata.st_nlink, metadata.st_size, metadata.st_mtime_ns, metadata.st_ctime_ns)
    finally:
        os.close(descriptor)


def readiness(root, runner, phase):
    code, output = runner(root, ['php', '-r', READINESS_PHP, phase], capture=True)
    try:
        value = json.loads(output) if code == 0 else None
    except (ValueError, TypeError):
        value = None
    if not isinstance(value, dict) or set(value) != {'review', 'fingerprint'} or type(value['review']) is not bool:
        raise SafeError('WORKFLOW_READ_ONLY_SMOKE_FAILED')
    if not isinstance(value['fingerprint'], str) or not re.fullmatch(r'[0-9a-f]{64}', value['fingerprint']):
        raise SafeError('WORKFLOW_READ_ONLY_SMOKE_FAILED')
    return value


def save_private(path, content):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())


def upgrade(root, uid, runner=base.command, target_release=TARGET_RELEASE,
            target_blob=TARGET_BLOB, source_blob=SOURCE_BLOB):
    if not all(re.fullmatch(r'[0-9a-f]{40}', pin) for pin in (target_release, target_blob, source_blob)):
        raise SafeError('INVALID_WORKFLOW_UPGRADE_PIN')
    base.validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'
    base.private_parent(parent, uid)
    descriptor = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        metadata = os.fstat(descriptor)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid or metadata.st_nlink != 1
                or stat.S_IMODE(metadata.st_mode) != 0o600):
            raise SafeError('WORKFLOW_LOCK_OWNER_OR_TYPE_NEEDS_REVIEW')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_ORDERS_INSTALL_OR_CONFIGURATION_IS_RUNNING') from None
        before = base.snapshot(root / WORKFLOW_PATH, uid, root)
        observed = base.blob_hash(before.content)
        if observed not in (source_blob, target_blob):
            raise SafeError('WORKFLOW_LIVE_SOURCE_NEEDS_REVIEW')
        env_before = env_fingerprint(root, uid)
        cache_before = base.snapshot(root / CACHE_PATH, uid, root, absent_ok=True)
        cache_metadata = env_fingerprint(root, uid, CACHE_PATH, absent_ok=True)
        configuration_files = {path: (base.snapshot(root / path, uid, root), env_fingerprint(root, uid, path)) for path in CONFIG_PATHS}

        def configuration_unchanged():
            try:
                for path, (snapshot, metadata) in configuration_files.items():
                    base.assert_snapshot(root / path, snapshot, uid, root)
                    if env_fingerprint(root, uid, path) != metadata: return False
                base.assert_snapshot(root / CACHE_PATH, cache_before, uid, root)
                return env_fingerprint(root, uid) == env_before and env_fingerprint(root, uid, CACHE_PATH, True) == cache_metadata
            except (SafeError, OSError):
                return False

        initial = readiness(root, runner, 'target' if observed == target_blob else 'source')
        if not initial['review']:
            raise SafeError('WORKFLOW_UPGRADE_REQUIRES_REVIEW_MODE')
        if observed == target_blob:
            final = readiness(root, runner, 'target')
            base.assert_snapshot(root / WORKFLOW_PATH, before, uid, root)
            if not configuration_unchanged() or final != initial:
                raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
            return 'WHATSAPP_ORDER_WORKFLOW_ALREADY_CURRENT_REVIEW_ONLY', None

        code, kind = runner(root, ['git', 'cat-file', '-t', target_release], capture=True)
        if code != 0 or kind.strip() != b'commit':
            raise SafeError('WORKFLOW_TARGET_NOT_A_COMMIT')
        code, tree = runner(root, ['git', 'ls-tree', '-z', target_release, WORKFLOW_PATH], capture=True)
        expected = rb'(100644|100755) blob ' + target_blob.encode() + rb'\t' + re.escape(WORKFLOW_PATH.encode()) + rb'\x00'
        if code != 0 or re.fullmatch(expected, tree) is None:
            raise SafeError('WORKFLOW_TARGET_NOT_PINNED_REGULAR_FILE')
        code, content = runner(root, ['git', 'show', target_release + ':' + WORKFLOW_PATH], capture=True)
        if code != 0 or not content or len(content) > 8 * 1024 * 1024 or base.blob_hash(content) != target_blob:
            raise SafeError('WORKFLOW_TARGET_BLOB_NEEDS_REVIEW')
        backup = pathlib.Path(tempfile.mkdtemp(prefix='workflow-upgrade-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('WORKFLOW_BACKUP_REQUIRES_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        save_private(backup / 'workflow.before.php', before.content)
        staged = backup / 'workflow.target.php'; save_private(staged, content)
        save_private(backup / 'receipt.json', (json.dumps({'release': target_release, 'source_blob': observed,
            'target_blob': target_blob, 'path': WORKFLOW_PATH}, sort_keys=True) + '\n').encode())
        code, _ = runner(root, ['php', '-l', str(staged)])
        if code != 0 or base.snapshot(staged, uid, root).content != content:
            raise SafeError('WORKFLOW_TARGET_LINT_OR_STAGING_FAILED')
        if not configuration_unchanged():
            raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
        base.assert_snapshot(root / WORKFLOW_PATH, before, uid, root)
        written, created = {}, []
        configuration_changed = False
        try:
            base.replace_file(root / WORKFLOW_PATH, content, before, uid, root, created,
                              journal=written, relative=WORKFLOW_PATH, private_directory=publications)
            if not configuration_unchanged():
                configuration_changed = True
                raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
            final = readiness(root, runner, 'target')
            if final != initial:
                configuration_changed = True
                raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
            base.assert_snapshot(root / WORKFLOW_PATH, written[WORKFLOW_PATH].after, uid, root)
            if not configuration_unchanged():
                configuration_changed = True
                raise SafeError('CONFIGURATION_CHANGED_REVIEW_REQUIRED')
        except BaseException:
            with base.blocked_signals():
                if configuration_changed or not configuration_unchanged():
                    # A concurrent auto/config change must not remove the newer safety guard.
                    status = 'CONFIGURATION_CHANGED_REVIEW_REQUIRED'
                else:
                    publication = written.get(WORKFLOW_PATH)
                    try:
                        restored = publication is None or base.recover_publication(publication, root, uid)
                    except BaseException:
                        restored = False
                    status = 'WORKFLOW_UPGRADE_FILES_RESTORED' if restored else 'WORKFLOW_UPGRADE_ROLLBACK_STOPPED_CONCURRENT_FILE_RETAINED'
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
        return 'WHATSAPP_ORDER_WORKFLOW_UPGRADED_REVIEW_ONLY', backup
    finally:
        os.close(descriptor)


def main():
    try:
        if len(sys.argv) != 3 or sys.argv[1] != '--root':
            raise SafeError('USAGE_WORKFLOW_UPGRADE_CONFIRMED_ROOT_ONLY')
        root = pathlib.Path(sys.argv[2])
        if root != APP_ROOT or os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_WORKFLOW_UPGRADE_AS_FASAKHA_FOR_CONFIRMED_ROOT')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('WORKFLOW_UPGRADE_INTERRUPTED')))
        result, backup = upgrade(root, os.geteuid())
        print(result)
        if backup is not None:
            print('BACKUP=' + str(backup))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('WORKFLOW_UPGRADE_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
