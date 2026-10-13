#!/usr/bin/env python3
"""Install the read-only WhatsApp inbox without replacing the server checkout."""
import fcntl
import ctypes
import contextlib
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from typing import Optional

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
MIGRATION = 'database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php'
MANIFEST = (
    'app/Support/WhatsAppInboxProtocol.php',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php',
    'app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php',
    'app/Console/Commands/ConsumeWhatsAppInbox.php',
    MIGRATION,
    'app/Services/Dashboard/WhatsAppInboxAccess.php',
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php',
    'routes/whatsapp_inbox.php',
    'resources/views/admin/whatsapp/index.blade.php',
    'resources/views/admin/whatsapp/sidebar.blade.php',
    'public/js/dashboard-whatsapp-inbox.js',
    'public/css/dashboard-whatsapp-inbox.css',
    'tests/whatsapp_inbox_protocol_test.php',
    'tests/whatsapp_inbox_consumer_test.php',
    'deployment/install_whatsapp_inbox.py',
    'tests/whatsapp_inbox_install_test.py',
    'docs/whatsapp-inbox.md',
)
CORE_HASHES = {
    'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484',
    'app/Services/Dashboard/BranchCustomers.php': '6889e9517410708fd4738d290d5551b32b467c5d',
    'app/Services/Dashboard/TakeawayCatalog.php': '49943493fffb5405c87a3001f36c339caa2edf31',
    'app/Services/Dashboard/TakeawayService.php': '92c2fb071461787b2d8c7080518466588a7a2cee',
    'app/Services/Dashboard/PhoneDelivery.php': 'cd4f5daddbf1ed462d04f7fa24b4966cb94608c9',
    'app/Services/Dashboard/PosServiceTicket.php': '87b24fb862d5d6fbc4be7a62f437bb50aba03be7',
    'app/Services/Dashboard/PosServicePhone.php': '23e9226064ade62b28b0d4fde2ded631ca7b8d73',
    'app/Services/Dashboard/PosBranchPrinting.php': '021a53ff471c594fcef75509327aace74bcbf86c',
    'resources/views/admin/index.blade.php': 'fabc2b2fc836348565efd372a5b5e62ce7b533b4',
}
ROUTES_PATH = 'routes/admin.php'
MENU_PATH = 'resources/views/admin/layouts/menu.blade.php'
ROUTES_HASH = '45a7fba56f463cd8a4b04bb242f550f05909ef5c'
MENU_HASH = '924d866a2b661f3caae597031191e95b2ec801a1'
ROUTES_LINE = b"require __DIR__.'/whatsapp_inbox.php';"
MENU_LINE = b"@include('admin.whatsapp.sidebar')"
MENU_ANCHOR = '<!-- الاعدادات -->'.encode('utf-8')
METRICS = ('events_seen', 'events_processed', 'events_skipped', 'messages_inserted',
           'messages_replayed', 'quarantined_events', 'ignored_records',
           'status_records', 'errors')


class SafeError(Exception):
    """Only fixed non-sensitive status codes may be passed here."""


def blob_hash(content):
    import hashlib
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


@dataclass(frozen=True)
class Snapshot:
    content: bytes
    uid: int
    gid: int
    mode: int
    device: int
    inode: int


@dataclass
class Publication:
    path: pathlib.Path
    before: Optional[Snapshot]
    after: Snapshot
    temporary: pathlib.Path
    recovery: Optional[pathlib.Path] = None


@contextlib.contextmanager
def blocked_signals():
    """A pending termination is delivered only after the journal is consistent."""
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)


def atomic_rename(source, destination, flags):
    # Linux renameat2 is required. Never fall back to overwrite-by-rename.
    try:
        library = ctypes.CDLL(None, use_errno=True)
        operation = library.renameat2
        operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
        operation.restype = ctypes.c_int
    except (AttributeError, OSError):
        raise SafeError('ATOMIC_RENAME_UNAVAILABLE') from None
    if operation(-100, os.fsencode(source), -100, os.fsencode(destination), flags) != 0:
        raise SafeError('ATOMIC_RENAME_FAILED')


def atomic_exchange(source, destination):
    atomic_rename(source, destination, 2)  # RENAME_EXCHANGE


def atomic_move_noreplace(source, destination):
    atomic_rename(source, destination, 1)  # RENAME_NOREPLACE


def probe_atomic_exchange(directory):
    first = directory / 'atomic-probe-first'
    second = directory / 'atomic-probe-second'
    first.write_bytes(b'first')
    second.write_bytes(b'second')
    try:
        atomic_exchange(first, second)
        if first.read_bytes() != b'second' or second.read_bytes() != b'first':
            raise SafeError('ATOMIC_EXCHANGE_CHECK_FAILED')
    finally:
        if first.exists():
            first.unlink()
        if second.exists():
            second.unlink()


def safe_ancestors(path, uid, owned_from=None):
    """Root-owned system ancestors are allowed; project ancestors must be ours."""
    path = pathlib.Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise SafeError('UNSAFE_PATH')
    for parent in reversed(path.parents):
        try:
            metadata = parent.lstat()
        except FileNotFoundError:
            continue
        if not stat.S_ISDIR(metadata.st_mode):
            raise SafeError('ANCESTOR_NOT_DIRECTORY_OR_SYMLINK')
        required_owner = (owned_from is not None and
                          (parent == owned_from or owned_from in parent.parents))
        if metadata.st_uid not in ((uid,) if required_owner else (0, uid)):
            raise SafeError('ANCESTOR_OWNER_NEEDS_REVIEW')


def snapshot(path, uid, root, absent_ok=False):
    safe_ancestors(path, uid, root)
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        if absent_ok:
            return None
        raise SafeError('REQUIRED_FILE_MISSING') from None
    except OSError:
        raise SafeError('FILE_NOT_READABLE_OR_SYMLINK') from None
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid:
            raise SafeError('FILE_OWNER_OR_TYPE_NEEDS_REVIEW')
        if metadata.st_size > 8 * 1024 * 1024:
            raise SafeError('FILE_TOO_LARGE')
        with os.fdopen(descriptor, 'rb', closefd=False) as stream:
            content = stream.read(8 * 1024 * 1024 + 1)
        if len(content) > 8 * 1024 * 1024:
            raise SafeError('FILE_TOO_LARGE')
        after = os.fstat(descriptor)
        if (after.st_size, after.st_mtime_ns, after.st_ctime_ns) != (
                metadata.st_size, metadata.st_mtime_ns, metadata.st_ctime_ns):
            raise SafeError('FILE_CHANGED_DURING_READ')
        return Snapshot(content, metadata.st_uid, metadata.st_gid,
                        stat.S_IMODE(metadata.st_mode), metadata.st_dev, metadata.st_ino)
    finally:
        os.close(descriptor)


def assert_snapshot(path, expected, uid, root):
    if snapshot(path, uid, root, absent_ok=True) != expected:
        raise SafeError('FILE_CHANGED_CONCURRENTLY')


def ensure_directory(path, uid, root, created):
    safe_ancestors(path, uid, root)
    if not path.exists() and not path.is_symlink():
        ensure_directory(path.parent, uid, root, created)
        try:
            path.mkdir(mode=0o755)
            created.append((path, path.lstat().st_ino))
        except FileExistsError:
            pass
    metadata = path.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != uid:
        raise SafeError('PROJECT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW')


def replace_file(path, content, expected, uid, root, created, restore=None,
                 journal=None, relative=None, private_directory=None):
    ensure_directory(path.parent, uid, root, created)
    private_directory = private_directory or path.parent
    descriptor, temporary_name = tempfile.mkstemp(prefix='published-', dir=private_directory)
    temporary = pathlib.Path(temporary_name)
    journal = {} if journal is None else journal
    relative = str(path) if relative is None else relative
    registered = False
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            stream.flush()
            metadata = restore if restore is not None else expected
            if metadata is not None:
                os.fchown(stream.fileno(), metadata.uid, metadata.gid)
                os.fchmod(stream.fileno(), metadata.mode)
            else:
                os.fchmod(stream.fileno(), 0o644)
            os.fsync(stream.fileno())
            prepared = os.fstat(stream.fileno())
            written = Snapshot(content, prepared.st_uid, prepared.st_gid,
                               stat.S_IMODE(prepared.st_mode), prepared.st_dev, prepared.st_ino)
        with blocked_signals():
            assert_snapshot(path, expected, uid, root)
            # The complete publication record exists BEFORE either atomic action.
            # An exception immediately after the syscall is therefore recoverable.
            publication = Publication(path, expected, written, temporary)
            journal[relative] = publication
            registered = True
            if expected is None:
                try:
                    os.link(temporary, path, follow_symlinks=False)
                except FileExistsError:
                    raise SafeError('FILE_CHANGED_CONCURRENTLY') from None
            else:
                # Inspection happens after atomically exchanging both inodes.
                # A competing pre-exchange edit is retained at the private path.
                atomic_exchange(temporary, path)
                if snapshot(temporary, uid, root) != expected:
                    raise SafeError('FILE_CHANGED_DURING_ATOMIC_EXCHANGE')
            assert_snapshot(path, written, uid, root)
        return publication
    finally:
        # Registered files remain in the private backup. In particular, never
        # unlink a displaced original after an interrupted exchange.
        if not registered and temporary.exists():
            temporary.unlink()


def route_proposal(content, original_hash=ROUTES_HASH):
    newline = b'\r\n' if b'\r\n' in content else b'\n'
    suffix = (b'' if content.endswith((b'\n', b'\r')) else newline)
    suffix += newline + ROUTES_LINE + newline
    if blob_hash(content) == original_hash:
        if ROUTES_LINE in content or b'whatsapp_inbox.php' in content:
            raise SafeError('ROUTES_ANCHOR_AMBIGUOUS')
        if b'?>' in content:
            raise SafeError('ROUTES_CLOSING_TAG_NEEDS_REVIEW')
        return content + suffix
    # Accept only the exact output of our additive transformation.
    for ending in (b'\n', b'\r\n'):
        candidate_suffix = ending + ROUTES_LINE + ending
        if content.endswith(candidate_suffix):
            original = content[:-len(candidate_suffix)]
            for candidate in (original, original[:-len(ending)] if original.endswith(ending) else original):
                if blob_hash(candidate) == original_hash and route_proposal(candidate, original_hash) == content:
                    return content
    raise SafeError('ROUTES_HASH_NEEDS_REVIEW')


def menu_proposal(content, original_hash=MENU_HASH):
    newline = b'\r\n' if b'\r\n' in content else b'\n'
    if blob_hash(content) == original_hash:
        if content.count(MENU_ANCHOR) != 1 or MENU_LINE in content or b'admin.whatsapp.sidebar' in content:
            raise SafeError('MENU_ANCHOR_AMBIGUOUS')
        return content.replace(MENU_ANCHOR, MENU_LINE + newline + MENU_ANCHOR, 1)
    if content.count(MENU_LINE) == 1:
        for ending in (b'\n', b'\r\n'):
            original = content.replace(MENU_LINE + ending + MENU_ANCHOR, MENU_ANCHOR, 1)
            if blob_hash(original) == original_hash and menu_proposal(original, original_hash) == content:
                return content
    raise SafeError('MENU_HASH_NEEDS_REVIEW')


def command(root, arguments, capture=False, timeout=60):
    try:
        result = subprocess.run(arguments, cwd=root, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=timeout, check=False)
        if capture and len(result.stdout) > 8 * 1024 * 1024:
            return 1, b''
        return result.returncode, result.stdout if capture else b''
    except (OSError, subprocess.TimeoutExpired):
        return 1, b''


def stage_release(root, release, destination, runner):
    code, kind = runner(root, ['git', 'cat-file', '-t', release], capture=True)
    if code != 0 or kind.strip() != b'commit':
        raise SafeError('PINNED_RELEASE_NOT_A_COMMIT')
    for relative in MANIFEST:
        code, tree = runner(root, ['git', 'ls-tree', '-z', release, relative], capture=True)
        pattern = rb'(100644|100755) blob [0-9a-f]{40}\t' + re.escape(relative.encode()) + rb'\x00'
        if code != 0 or re.fullmatch(pattern, tree) is None:
            raise SafeError('PINNED_RELEASE_FILE_NOT_REGULAR')
        code, content = runner(root, ['git', 'show', release + ':' + relative], capture=True)
        if code != 0 or not content or len(content) > 8 * 1024 * 1024:
            raise SafeError('PINNED_RELEASE_FILE_MISSING_OR_INVALID')
        path = destination / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
    for relative in MANIFEST:
        if relative.endswith('.php'):
            code, _ = runner(destination, ['php', '-l', relative])
            if code != 0:
                raise SafeError('PINNED_PHP_LINT_FAILED')
    for relative in ('tests/whatsapp_inbox_protocol_test.php', 'tests/whatsapp_inbox_consumer_test.php'):
        code, _ = runner(destination, ['php', relative])
        if code != 0:
            raise SafeError('PINNED_PROTOCOL_OR_CONSUMER_TEST_FAILED')


SMOKE_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    foreach (['whatsapp_webhook_events', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages', 'whatsapp_inbox_ingestion_failures'] as $table) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) exit(1);
    }
    if (!array_key_exists('whatsapp:consume-inbox', $kernel->all())) exit(1);
    if (app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess(null)) exit(1);
    foreach (['whatsapp-inbox.index', 'whatsapp-inbox.conversations', 'whatsapp-inbox.messages'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        if (!$route || !in_array('IsAdmin', $route->gatherMiddleware(), true)) exit(1);
    }
    $route = app('router')->getRoutes()->getByName('whatsapp-inbox.conversations');
    $request = \Illuminate\Http\Request::create('/' . $route->uri(), 'GET');
    $request->headers->set('Accept', 'application/json');
    $http = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $response = $http->handle($request);
    if (!in_array($response->getStatusCode(), [302, 401, 403], true)) exit(1);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''


def private_parent(path, uid):
    safe_ancestors(path, uid)
    try:
        path.mkdir(mode=0o700)
    except FileExistsError:
        pass
    metadata = path.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != uid or stat.S_IMODE(metadata.st_mode) != 0o700:
        raise SafeError('PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW')


def validate_root(root, uid):
    safe_ancestors(root, uid)
    metadata = root.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != uid:
        raise SafeError('PROJECT_ROOT_OWNER_OR_TYPE_NEEDS_REVIEW')
    git_metadata = (root / '.git').lstat()
    if not stat.S_ISDIR(git_metadata.st_mode) or git_metadata.st_uid != uid:
        raise SafeError('GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW')


def recover_publication(publication, root, uid):
    """Restore with no-overwrite moves; retain every displaced version."""
    path = publication.path
    current = snapshot(path, uid, root, absent_ok=True)
    if current == publication.before:
        # Includes failed new-file creation; no publication happened.
        return True
    if current != publication.after:
        # A post-publication edit already occupies the destination. Keep it.
        return False
    source_snapshot = (snapshot(publication.temporary, uid, root)
                       if publication.before is not None else None)
    descriptor, recovery_name = tempfile.mkstemp(prefix='recovered-', dir=publication.temporary.parent)
    os.close(descriptor)
    recovery = pathlib.Path(recovery_name)
    recovery.unlink()
    publication.recovery = recovery
    try:
        atomic_move_noreplace(path, recovery)
    except BaseException:
        # A fixture or unexpected exception can occur after the atomic syscall.
        # Its retained inode, rather than the exception, determines recovery.
        if not recovery.exists():
            return False
    try:
        displaced = snapshot(recovery, uid, root)
    except BaseException:
        # If the captured owner/content changed, return that captured inode with
        # a no-overwrite move. Never leave an existing legacy file missing.
        try:
            atomic_move_noreplace(recovery, path)
        except BaseException:
            pass
        return False
    unchanged = displaced == publication.after
    if unchanged and publication.before is None:
        # The new addition has been removed from the project. Its inode remains
        # private, so an open external writer cannot become an unnamed lost file.
        return snapshot(path, uid, root, absent_ok=True) is None
    # If a competing edit arrived immediately before the move, restore that edit,
    # rather than our stale original. A no-overwrite move preserves a newer path.
    source = publication.temporary if unchanged else recovery
    try:
        safe_ancestors(path, uid, root)
        atomic_move_noreplace(source, path)
    except BaseException:
        # If restoration was interrupted after its syscall, its target inode is
        # already safe; otherwise preserve whichever path was created by a peer.
        if not path.exists():
            try:
                atomic_move_noreplace(recovery, path)
            except BaseException:
                pass
        return False
    return (unchanged and source_snapshot == publication.before
            and snapshot(path, uid, root) == source_snapshot)


def rollback(root, uid, before, written, created):
    stopped = False
    with blocked_signals():
        # Legacy includes must be safe before deleting any of their dependencies.
        legacy = [(relative, item) for relative, item in reversed(list(written.items()))
                  if relative in (ROUTES_PATH, MENU_PATH)]
        additions = [(relative, item) for relative, item in reversed(list(written.items()))
                     if relative not in (ROUTES_PATH, MENU_PATH)]
        for _, publication in legacy:
            try:
                if not recover_publication(publication, root, uid):
                    stopped = True
            except BaseException:
                stopped = True
        if not stopped:
            for _, publication in additions:
                try:
                    if not recover_publication(publication, root, uid):
                        stopped = True
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


def install(root, uid, release, consume=0, runner=command,
            core_hashes=CORE_HASHES, routes_hash=ROUTES_HASH, menu_hash=MENU_HASH):
    if not re.fullmatch(r'[0-9a-f]{40}', release) or not 0 <= consume <= 100:
        raise SafeError('INVALID_RELEASE_OR_CONSUMPTION_LIMIT')
    validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'
    private_parent(parent, uid)
    descriptor = os.open(parent / 'inbox-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    backup = None
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid or stat.S_IMODE(metadata.st_mode) != 0o600:
            raise SafeError('INSTALL_LOCK_OWNER_OR_TYPE_NEEDS_REVIEW')
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_INBOX_INSTALL_IS_RUNNING') from None
        guarded = {}
        for relative, wanted in core_hashes.items():
            item = snapshot(root / relative, uid, root)
            if blob_hash(item.content) != wanted:
                raise SafeError('CORE_FILE_HASH_NEEDS_REVIEW')
            guarded[relative] = item
        kernel = snapshot(root / 'app/Console/Kernel.php', uid, root)
        if kernel.content.count(b"$this->load(__DIR__.'/Commands');") != 1:
            raise SafeError('COMMAND_AUTOLOAD_NEEDS_REVIEW')
        guarded['app/Console/Kernel.php'] = kernel
        before = {
            ROUTES_PATH: snapshot(root / ROUTES_PATH, uid, root),
            MENU_PATH: snapshot(root / MENU_PATH, uid, root),
        }
        proposals = {
            ROUTES_PATH: route_proposal(before[ROUTES_PATH].content, routes_hash),
            MENU_PATH: menu_proposal(before[MENU_PATH].content, menu_hash),
        }
        for relative in MANIFEST:
            before[relative] = snapshot(root / relative, uid, root, absent_ok=True)
        backup = pathlib.Path(tempfile.mkdtemp(prefix='inbox-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('BACKUP_AND_PROJECT_REQUIRE_SAME_FILESYSTEM')
        publication_directory = backup / 'publications'
        publication_directory.mkdir(mode=0o700)
        probe_atomic_exchange(publication_directory)
        staged = backup / 'release'
        staged.mkdir(mode=0o700)
        stage_release(root, release, staged, runner)
        for relative in MANIFEST:
            proposals[relative] = (staged / relative).read_bytes()
            if before[relative] is not None and before[relative].content != proposals[relative]:
                raise SafeError('EXISTING_INBOX_FILE_DIFFERS_FROM_PINNED_RELEASE')
        # Lint the exact composed legacy files; never lint a wholesale replacement.
        for relative in (ROUTES_PATH, MENU_PATH):
            candidate = backup / ('composed-' + pathlib.Path(relative).name)
            candidate.write_bytes(proposals[relative])
            code, _ = runner(root, ['php', '-l', str(candidate)])
            if code != 0:
                raise SafeError('COMPOSED_PHP_LINT_FAILED')
        before_directory = backup / 'before'
        before_directory.mkdir(mode=0o700)
        receipt = {'release': release, 'files': {}}
        for index, (relative, item) in enumerate(before.items()):
            receipt['files'][relative] = None if item is None else {
                'uid': item.uid, 'gid': item.gid, 'mode': item.mode, 'blob': blob_hash(item.content)}
            if item is not None:
                target = before_directory / str(index)
                fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(item.content)
                    stream.flush()
                    os.fsync(stream.fileno())
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
        (backup / 'receipt.json').chmod(0o600)
        written, created = {}, []
        try:
            for relative, item in {**guarded, **before}.items():
                assert_snapshot(root / relative, item, uid, root)
            # Install dependencies and schema before exposing the route/sidebar.
            for relative in MANIFEST:
                original = before[relative]
                if original is None or original.content != proposals[relative]:
                    replace_file(root / relative, proposals[relative], original, uid, root, created,
                                 journal=written, relative=relative, private_directory=publication_directory)
            code, _ = runner(root, ['php', 'artisan', 'migrate', '--path=' + MIGRATION, '--force'])
            if code != 0:
                raise SafeError('INBOX_MIGRATION_FAILED_TABLES_RETAINED')
            for relative in (ROUTES_PATH, MENU_PATH):
                original = before[relative]
                if original.content != proposals[relative]:
                    replace_file(root / relative, proposals[relative], original, uid, root, created,
                                 journal=written, relative=relative, private_directory=publication_directory)
            code, _ = runner(root, ['php', 'artisan', 'route:clear'])
            if code != 0:
                raise SafeError('INBOX_ROUTE_CLEAR_FAILED')
            code, _ = runner(root, ['php', '-r', SMOKE_PHP])
            if code != 0:
                raise SafeError('INBOX_SMOKE_FAILED')
            for relative, original in guarded.items():
                assert_snapshot(root / relative, original, uid, root)
            for relative, original in before.items():
                publication = written.get(relative)
                assert_snapshot(root / relative, publication.after if publication is not None else original, uid, root)
        except BaseException:
            restored = rollback(root, uid, before, written, created)
            cleared, _ = runner(root, ['php', 'artisan', 'route:clear'])
            status = 'INBOX_FILES_RESTORED_TABLES_RETAINED' if restored else 'INBOX_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'
            if cleared != 0:
                status += '_ROUTE_RECHECK_REQUIRED'
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
        # Processing is after successful installation. Projection errors never
        # roll back a functioning inbox or touch existing webhook capture.
        metrics = None
        result = 'WHATSAPP_INBOX_READY'
        if consume:
            code, output = runner(root, ['php', 'artisan', 'whatsapp:consume-inbox', '--limit=' + str(consume)], capture=True)
            try:
                parsed = json.loads(output)
                if not isinstance(parsed, dict) or any(type(parsed.get(key)) is not int or parsed[key] < 0 for key in METRICS):
                    raise ValueError()
                metrics = {key: parsed[key] for key in METRICS}
                if code != 0 or metrics['quarantined_events'] or metrics['errors']:
                    result = 'WHATSAPP_INBOX_READY_PROJECTION_REVIEW_REQUIRED'
            except (ValueError, TypeError, json.JSONDecodeError):
                result = 'WHATSAPP_INBOX_READY_PROJECTION_REPORT_UNKNOWN'
        return result, backup, metrics
    finally:
        os.close(descriptor)


def main():
    try:
        args = sys.argv[1:]
        if len(args) not in (4, 6) or args[:1] != ['--root'] or args[2:3] != ['--release']:
            raise SafeError('USAGE_ROOT_RELEASE_OPTIONAL_CONSUME')
        root = pathlib.Path(args[1])
        if root != APP_ROOT or os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_AS_FASAKHA_FOR_CONFIRMED_PROJECT_ROOT')
        consume = 0
        if len(args) == 6:
            if args[4] != '--consume' or not re.fullmatch(r'[0-9]{1,3}', args[5]):
                raise SafeError('INVALID_CONSUMPTION_LIMIT')
            consume = int(args[5])
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('INSTALL_INTERRUPTED')))
        result, backup, metrics = install(root, os.geteuid(), args[3], consume)
        print(result)
        print('BACKUP=' + str(backup))
        if metrics is not None:
            print('PROJECTION_METRICS=' + json.dumps(metrics, sort_keys=True))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('INBOX_INSTALL_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
