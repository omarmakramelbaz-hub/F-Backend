#!/usr/bin/env python3
"""Apply a pinned Meta-only upgrade without replacing native dashboard work."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import re
import stat
import subprocess
import tempfile

# Reviewed immutable foundations of installed release da5b2d04583c531f2f56a43f7d2212bc6fdd0010.
FOUNDATION_BLOBS = {
    'app/Services/Dashboard/MetaInboxAccess.php': '260b0a1cc93be44bf9e96f508b55420e5f639f64',
    'app/Services/Dashboard/MetaInboxConsumer.php': 'f7dda06f617de711a3f6a363edda0185e3973794',
    'app/Services/Dashboard/MetaOrderWorkflow.php': 'e13362de65706f9ee5e5dab8acc24220bca5cdb0',
    'app/Support/MetaMessagingProtocol.php': 'ba20320835a33826b4b6bcc84f19c62569180931',
    'config/meta_messaging.php': '3530fbdb5886e6ea88c29a2f2b2be1633485e3fd',
}
ROUTE_CACHE = 'bootstrap/cache/routes-v7.php'
FEATURE_PATH = re.compile(
    r'(?:app/Console/Commands/(?:ProcessMetaMessages|RefreshMetaProfiles)\.php'
    r'|app/Http/Controllers/(?:Dashboard|Api/V1)/Meta[A-Za-z]+\.php'
    r'|app/Services/Dashboard/Meta[A-Za-z]+\.php'
    r'|resources/views/admin/meta/(?!sidebar\.)[a-z0-9_-]+\.blade\.php'
    r'|public/(?:css/dashboard-meta-[a-z0-9_-]+\.css|js/dashboard-meta-[a-z0-9_-]+\.js)'
    r'|routes/meta_[a-z0-9_]+\.php'
    r'|database/migrations/[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_(?:create|add)_meta_[a-z0-9_]+\.php)\Z')
RUNTIME_PROBE = br'''<?php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!$app->make(\App\Services\Dashboard\MetaInboxAccess::class)->available()
        || !\Illuminate\Support\Facades\Schema::hasColumns('meta_inbox_conversations',
            ['id', 'waba_id', 'phone_number_id', 'peer_hash', 'customer', 'updated_at'])
        || !\Illuminate\Support\Facades\Schema::hasTable('meta_webhook_events')) throw new \RuntimeException();
    $plain = 'meta-chat-runtime-check';
    if (\Illuminate\Support\Facades\Crypt::decryptString(
        \Illuminate\Support\Facades\Crypt::encryptString($plain)) !== $plain) throw new \RuntimeException();
    echo '{"ok":true}';
} catch (\Throwable $error) { echo '{"ok":false}'; exit(1); }
'''


def run(args, root):
    return subprocess.run(args, cwd=root, capture_output=True, check=False)


def git_file(root, release, rel):
    result = run(['git', 'show', release + ':' + rel], root)
    if result.returncode != 0: raise RuntimeError('RELEASE_FILE_UNAVAILABLE: ' + rel)
    return result.stdout


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def safe_path(path):
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
        raise RuntimeError('SYMLINK_TARGET')


def contents(path):
    safe_path(path)
    if path.exists() and not path.is_file(): raise RuntimeError('NON_FILE_TARGET')
    return path.read_bytes() if path.exists() else None


def atomic(path, content, mode):
    safe_path(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.meta-chat-install-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream: stream.write(content)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)


def private_file(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(content)
    os.chmod(path, 0o600)


def checked_run(args, root, reason):
    if run(args, root).returncode != 0: raise RuntimeError(reason)


def manifest_settings(manifest):
    upgrade = manifest.get('live_upgrade')
    migrations = manifest.get('upgrade_migrations')
    count = manifest.get('verified_routes')
    if not isinstance(upgrade, dict) or not upgrade or not isinstance(migrations, list) \
        or len(set(m for m in migrations if isinstance(m, str))) != len(migrations) \
        or type(count) is not int or count < 1 or count > 100:
        raise RuntimeError('INVALID_META_UPGRADE_MANIFEST')
    for rel, previous in upgrade.items():
        if not isinstance(rel, str) or not FEATURE_PATH.fullmatch(rel) or rel in FOUNDATION_BLOBS \
            or not isinstance(previous, list) \
            or any(not isinstance(value, str) or not re.fullmatch('[a-f0-9]{40}', value) for value in previous):
            raise RuntimeError('INVALID_META_UPGRADE_TARGET')
    for rel in migrations:
        if not isinstance(rel, str) or rel not in upgrade or not rel.startswith('database/migrations/') or upgrade[rel]:
            raise RuntimeError('INVALID_META_UPGRADE_MIGRATION')
    if set(rel for rel in upgrade if rel.startswith('database/migrations/')) != set(migrations):
        raise RuntimeError('UNLISTED_META_UPGRADE_MIGRATION')
    return upgrade, migrations, count


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--php', required=True)
    args = parser.parse_args()
    root = pathlib.Path(os.path.abspath(args.root))
    safe_path(root)
    if not re.fullmatch('[a-f0-9]{40}', args.release): raise RuntimeError('INVALID_RELEASE')
    if os.geteuid() == 0: raise RuntimeError('RUN_AS_APPLICATION_USER')
    for rel in ('vendor/autoload.php', 'bootstrap/app.php', 'artisan'):
        if contents(root / rel) is None: raise RuntimeError('APPLICATION_RUNTIME_MISSING')
    try: manifest = json.loads(git_file(root, args.release, 'deployment/meta_messaging_manifest.json'))
    except (ValueError, TypeError): raise RuntimeError('INVALID_META_UPGRADE_MANIFEST')
    if not isinstance(manifest, dict): raise RuntimeError('INVALID_META_UPGRADE_MANIFEST')
    upgrade, migrations, expected_routes = manifest_settings(manifest)
    route_probe = git_file(root, args.release, 'deployment/meta_messaging_route_check.php')
    foundations = {}
    for rel, expected in FOUNDATION_BLOBS.items():
        foundations[rel] = contents(root / rel)
        if foundations[rel] is None or blob(foundations[rel]) != expected:
            raise RuntimeError('META_FOUNDATION_DIFFERS: ' + rel)
    installed = {}
    release_files = {}
    targets = {}
    for rel, accepted in upgrade.items():
        path = root / rel
        old = contents(path)
        target = git_file(root, args.release, rel)
        installed[rel] = old
        release_files[rel] = target
        if old == target: continue
        if old is None and accepted: raise RuntimeError('REQUIRED_META_UPGRADE_FILE: ' + rel)
        if old is not None and blob(old) not in accepted:
            raise RuntimeError('UNEXPECTED_META_UPGRADE_FILE: ' + rel)
        # Every feature directory already exists in the initial installation.
        if not path.parent.is_dir(): raise RuntimeError('REQUIRED_META_UPGRADE_DIRECTORY: ' + rel)
        targets[rel] = (old, target, stat.S_IMODE(path.stat().st_mode) if old is not None else 0o644)
    route_path = root / ROUTE_CACHE
    cached_routes = contents(route_path)
    cached_mode = stat.S_IMODE(route_path.stat().st_mode) if cached_routes is not None else 0o644
    backup_parent = root.parent / 'meta-release-backups'
    safe_path(backup_parent)
    if backup_parent.exists() and not backup_parent.is_dir(): raise RuntimeError('INVALID_BACKUP_DIRECTORY')
    backup_parent.mkdir(mode=0o700, exist_ok=True)
    os.chmod(backup_parent, 0o700)
    prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-chat-' + args.release[:8] + '-'
    backup = pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=backup_parent))
    for rel, target in release_files.items():
        staged = backup / 'staged' / rel
        private_file(staged, target)
        if rel.endswith('.php') and not rel.endswith('.blade.php'):
            checked_run([args.php, '-l', str(staged)], root, 'STAGED_PHP_LINT_FAILED: ' + rel)
    verifier = backup / 'verify-meta-routes.php'
    private_file(verifier, route_probe)
    checked_run([args.php, '-l', str(verifier)], root, 'STAGED_META_VERIFIER_LINT_FAILED')
    runtime_probe = backup / 'verify-meta-runtime.php'
    private_file(runtime_probe, RUNTIME_PROBE)
    checked_run([args.php, '-l', str(runtime_probe)], root, 'STAGED_META_RUNTIME_LINT_FAILED')
    readiness = run([args.php, str(runtime_probe), str(root)], root)
    try:
        decoded = json.loads(readiness.stdout)
        ready = readiness.returncode == 0 and decoded == {'ok': True} and decoded.get('ok') is True
    except (ValueError, TypeError): ready = False
    if not ready: raise RuntimeError('META_UPGRADE_RUNTIME_UNAVAILABLE')
    if cached_routes is not None: private_file(backup / 'original' / ROUTE_CACHE, cached_routes)
    before = {}
    routes_touched = False
    route_expected = cached_routes
    try:
        for rel, expected in {**foundations, **installed, ROUTE_CACHE: cached_routes}.items():
            if contents(root / rel) != expected: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
        for rel, (old, target, mode) in targets.items():
            path = root / rel
            if contents(path) != old: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
            if old is not None: private_file(backup / 'original' / rel, old)
            atomic(path, target, mode)
            before[rel] = (old, target, mode)
        for rel in migrations:
            checked_run([args.php, 'artisan', 'migrate', '--force', '--path=' + rel], root, 'META_UPGRADE_MIGRATION_FAILED')
        # Only the routes cache changes; configuration and compiled views remain untouched.
        if contents(route_path) != route_expected: raise RuntimeError('CONCURRENT_EDIT: ' + ROUTE_CACHE)
        routes_touched = True
        try: checked_run([args.php, 'artisan', 'route:clear'], root, 'META_UPGRADE_ROUTE_CLEAR_FAILED')
        finally: route_expected = contents(route_path)
        if cached_routes is not None:
            try: checked_run([args.php, 'artisan', 'route:cache'], root, 'META_UPGRADE_ROUTE_CACHE_FAILED')
            finally:
                route_expected = contents(route_path)
                if route_expected is not None: os.chmod(route_path, cached_mode)
        verified = run([args.php, str(verifier)], root)
        try:
            decoded = json.loads(verified.stdout)
            valid = verified.returncode == 0 and decoded == {'ok': True, 'verified_routes': expected_routes} \
                and decoded.get('ok') is True and type(decoded.get('verified_routes')) is int
        except (ValueError, TypeError): valid = False
        if not valid: raise RuntimeError('META_UPGRADE_ROUTE_VERIFICATION_FAILED')
        for rel, expected in {**foundations, **release_files, ROUTE_CACHE: route_expected}.items():
            if contents(root / rel) != expected: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
    except Exception:
        restored = True
        for rel, (old, target, mode) in reversed(list(before.items())):
            path = root / rel
            try:
                if contents(path) != target:
                    restored = False
                    continue
                if old is None: path.unlink()
                else: atomic(path, old, mode)
            except (OSError, RuntimeError): restored = False
        if routes_touched:
            try:
                if contents(route_path) != route_expected: restored = False
                elif cached_routes is None:
                    if route_expected is not None: route_path.unlink()
                else: atomic(route_path, cached_routes, cached_mode)
            except (OSError, RuntimeError): restored = False
        print('META_UPGRADE_CODE_RESTORED' if restored else 'META_UPGRADE_ROLLBACK_INCOMPLETE')
        print('ADDITIVE_META_TABLES_RETAINED')
        print('BACKUP=' + str(backup))
        raise
    print('META_ROUTES_VERIFIED=' + str(expected_routes))
    print('META_CHAT_UPDATED=' + str(len(targets)))
    print('BACKUP=' + str(backup))


if __name__ == '__main__':
    try: main()
    except (RuntimeError, OSError) as error:
        print('META_UPGRADE_FAILED=' + (str(error) if isinstance(error, RuntimeError) else 'FILESYSTEM_ERROR'))
        raise SystemExit(1)
