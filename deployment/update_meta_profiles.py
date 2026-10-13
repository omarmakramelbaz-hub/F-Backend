#!/usr/bin/env python3
"""Publish only the optional Meta profile service and its two worker commands."""
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

PROCESS = 'app/Console/Commands/ProcessMetaMessages.php'
TARGETS = (PROCESS, 'app/Services/Dashboard/MetaProfileService.php',
           'app/Console/Commands/RefreshMetaProfiles.php')
PREVIOUS_PROCESS = 'c28b29c0f90be955b3d6d2ff5d82b88179550189'
FOUNDATIONS = ('app/Services/Dashboard/MetaInboxAccess.php',
               'app/Services/Dashboard/MetaInboxConsumer.php',
               'app/Services/Dashboard/MetaOrderWorkflow.php',
               'app/Support/MetaMessagingProtocol.php', 'config/meta_messaging.php')
# Original installed Meta release da5b2d04583c531f2f56a43f7d2212bc6fdd0010.
# Later release changes must not silently redefine this updater's foundation.
FOUNDATION_BLOBS = {
    'app/Services/Dashboard/MetaInboxAccess.php': '260b0a1cc93be44bf9e96f508b55420e5f639f64',
    'app/Services/Dashboard/MetaInboxConsumer.php': 'f7dda06f617de711a3f6a363edda0185e3973794',
    'app/Services/Dashboard/MetaOrderWorkflow.php': 'e13362de65706f9ee5e5dab8acc24220bca5cdb0',
    'app/Support/MetaMessagingProtocol.php': 'ba20320835a33826b4b6bcc84f19c62569180931',
    'config/meta_messaging.php': '3530fbdb5886e6ea88c29a2f2b2be1633485e3fd',
}
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
        || !\Illuminate\Support\Facades\Schema::hasTable('meta_webhook_events')
        || !class_exists(\App\Services\Dashboard\MetaInboxConsumer::class)
        || !class_exists(\App\Services\Dashboard\MetaOrderWorkflow::class)) throw new \RuntimeException();
    $plain = 'meta-profile-runtime-check';
    if (\Illuminate\Support\Facades\Crypt::decryptString(
        \Illuminate\Support\Facades\Crypt::encryptString($plain)) !== $plain) throw new \RuntimeException();
    if (($argv[2] ?? '') === 'verify') {
        if (!class_exists(\App\Services\Dashboard\MetaProfileService::class)
            || !class_exists(\App\Console\Commands\RefreshMetaProfiles::class)) throw new \RuntimeException();
        $parameters = (new \ReflectionMethod(\App\Console\Commands\ProcessMetaMessages::class, 'handle'))->getParameters();
        if (count($parameters) !== 3 || (string) $parameters[2]->getType() !== \App\Services\Dashboard\MetaProfileService::class
            || (new \ReflectionClass(\App\Console\Commands\RefreshMetaProfiles::class))->getDefaultProperties()['signature']
                !== 'meta:refresh-profiles {--limit=10}') throw new \RuntimeException();
    }
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
    descriptor, temporary = tempfile.mkstemp(prefix='.meta-profile-install-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream: stream.write(content)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)


def check_runtime(php, probe, root, phase):
    result = run([php, str(probe), str(root), phase], root)
    try: valid = result.returncode == 0 and json.loads(result.stdout) == {'ok': True}
    except (ValueError, TypeError): valid = False
    if not valid: raise RuntimeError('META_PROFILE_RUNTIME_' + ('VERIFY_FAILED' if phase == 'verify' else 'UNAVAILABLE'))


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
    foundations = {}
    for rel in FOUNDATIONS:
        foundations[rel] = contents(root / rel)
        if foundations[rel] is None or blob(foundations[rel]) != FOUNDATION_BLOBS[rel]:
            raise RuntimeError('META_FOUNDATION_DIFFERS: ' + rel)
    targets = {}
    installed = {}
    release_files = {}
    for rel in TARGETS:
        path = root / rel
        old = contents(path)
        target = git_file(root, args.release, rel)
        installed[rel] = old
        release_files[rel] = target
        if rel == PROCESS and old is None: raise RuntimeError('REQUIRED_META_PROCESSOR')
        if old != target:
            if old is not None and (rel != PROCESS or blob(old) != PREVIOUS_PROCESS):
                raise RuntimeError('UNEXPECTED_META_PROFILE_FILE: ' + rel)
            targets[rel] = (old, target, stat.S_IMODE(path.stat().st_mode) if old is not None else 0o644)
    backup_parent = root.parent / 'meta-release-backups'
    safe_path(backup_parent)
    if backup_parent.exists() and not backup_parent.is_dir(): raise RuntimeError('INVALID_BACKUP_DIRECTORY')
    backup_parent.mkdir(mode=0o700, exist_ok=True)
    os.chmod(backup_parent, 0o700)
    prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-profiles-' + args.release[:8] + '-'
    backup = pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=backup_parent))
    # Stage and lint pinned release bytes before changing a single application file.
    for rel in TARGETS:
        staged = backup / 'staged' / rel
        staged.parent.mkdir(parents=True, exist_ok=True)
        staged.write_bytes(release_files[rel])
        os.chmod(staged, 0o600)
        if run([args.php, '-l', str(staged)], root).returncode != 0:
            raise RuntimeError('STAGED_PHP_LINT_FAILED: ' + rel)
    probe = backup / 'verify-profile-runtime.php'
    probe.write_bytes(RUNTIME_PROBE)
    os.chmod(probe, 0o600)
    check_runtime(args.php, probe, root, 'preflight')
    before = {}
    try:
        for rel, expected in {**foundations, **installed}.items():
            if contents(root / rel) != expected: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
        for rel, (old, target, mode) in targets.items():
            path = root / rel
            if contents(path) != old: raise RuntimeError('CONCURRENT_EDIT: ' + rel)
            if old is not None:
                stored = backup / 'original' / rel
                stored.parent.mkdir(parents=True, exist_ok=True)
                stored.write_bytes(old)
                os.chmod(stored, 0o600)
            atomic(path, target, mode)
            before[rel] = (old, target, mode)
        check_runtime(args.php, probe, root, 'verify')
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
            except OSError: restored = False
            except RuntimeError: restored = False
        print('META_PROFILE_CODE_RESTORED' if restored else 'META_PROFILE_ROLLBACK_INCOMPLETE')
        print('BACKUP=' + str(backup))
        raise
    print('META_PROFILE_RUNTIME_VERIFIED')
    print('META_PROFILE_UPDATED=' + str(len(targets)))
    print('BACKUP=' + str(backup))


if __name__ == '__main__':
    try: main()
    except (RuntimeError, OSError) as error:
        print('META_PROFILE_UPDATE_FAILED=' + (str(error) if isinstance(error, RuntimeError) else 'FILESYSTEM_ERROR'))
        raise SystemExit(1)
