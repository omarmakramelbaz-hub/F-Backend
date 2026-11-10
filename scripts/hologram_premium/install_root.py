#!/usr/bin/env python3
"""Install only reviewed GO hologram artwork, renderer, and command controls."""
from datetime import datetime, timezone
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys
import tempfile

FEATURE = 'hologram-premium-commands-20261110'
BASE_REVISION = '47118f609f16cc704fed041a7c8c2c559c9a5e43'
PROJECT = Path('/home/fasakha/public_html')
TENANTS = Path('/home/fasakha/go-owner-dashboards/tenants')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
ALLOWED = {
    'public/dashboard/js/go-hologram.js',
    'public/dashboard/js/home-hud.js',
    'public/dashboard/js/hud-commands.js',
    'public/dashboard/css/home-hud.css',
    'public/dashboard/css/go-hologram.css',
    'public/dashboard/css/hud-commands.css',
    'public/dashboard/branding/go-hologram-premium-20261110.png',
    'resources/views/admin/home_hud.blade.php',
    'resources/views/admin/partials/go_hologram_sidebar.blade.php',
    'resources/views/admin/home.blade.php',
    'resources/views/admin/home_branch.blade.php',
}
PATCHED = {
    'public/dashboard/js/home-hud.js',
    'resources/views/admin/home_hud.blade.php',
    'resources/views/admin/partials/go_hologram_sidebar.blade.php',
    'resources/views/admin/home.blade.php',
    'resources/views/admin/home_branch.blade.php',
}
SAFE_ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C', 'PYTHONNOUSERSITE': '1'}


class ReviewError(RuntimeError):
    pass


def digest(value):
    return hashlib.sha256(value).hexdigest()


def safe_directory(path):
    for parent in [path, *path.parents]:
        if parent.is_symlink() or parent.exists() and not parent.is_dir():
            raise ReviewError('GO_PREMIUM_DIRECTORY_CHANGED')


def regular(path, maximum=16777216):
    if path.is_symlink() or not path.is_file():
        raise ReviewError('GO_PREMIUM_REGULAR_FILE_REQUIRED')
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > maximum:
        raise ReviewError('GO_PREMIUM_UNSAFE_FILE')
    return path.read_bytes()


def checked_path(project, relative):
    if not isinstance(relative, str) or relative not in ALLOWED or Path(relative).is_absolute() or '..' in Path(relative).parts:
        raise ReviewError('GO_PREMIUM_UNREVIEWED_PATH')
    path = project / relative
    safe_directory(path.parent)
    if path.is_symlink() or path.exists() and not path.is_file():
        raise ReviewError('GO_PREMIUM_FILE_CHANGED: ' + relative)
    return path


def payload(entry):
    try:
        if entry.get('encoding') == 'base64':
            value = base64.b64decode(entry['content'], validate=True)
        elif entry.get('encoding') == 'utf-8':
            value = entry['content'].encode('utf-8')
        else:
            raise ValueError('encoding')
    except (ValueError, KeyError, TypeError) as error:
        raise ReviewError('GO_PREMIUM_PAYLOAD_INVALID') from error
    if digest(value) != entry.get('after_sha256'):
        raise ReviewError('GO_PREMIUM_PAYLOAD_HASH_MISMATCH')
    return value


def plan(project, manifest):
    if manifest.get('format') != 1 or manifest.get('feature') != FEATURE or manifest.get('base_revision') != BASE_REVISION:
        raise ReviewError('GO_PREMIUM_MANIFEST_CHANGED')
    entries = manifest.get('files')
    if not isinstance(entries, list) or not entries or len(entries) > len(ALLOWED) or any(not isinstance(e, dict) for e in entries):
        raise ReviewError('GO_PREMIUM_MANIFEST_FILES_INVALID')
    if len({e.get('path') for e in entries}) != len(entries):
        raise ReviewError('GO_PREMIUM_DUPLICATE_PATH')
    changes = []
    for entry in entries:
        relative = entry.get('path')
        path = checked_path(project, relative)
        before = regular(path) if path.exists() else None
        kind = entry.get('kind')
        if kind in {'new', 'replace'}:
            if relative in PATCHED:
                raise ReviewError('GO_PREMIUM_BOUNDED_PATCH_REQUIRED')
            after = payload(entry)
            expected = entry.get('before_sha256', [])
            if not isinstance(expected, list) or any(not isinstance(h, str) or not re.fullmatch(r'[0-9a-f]{64}', h) for h in expected):
                raise ReviewError('GO_PREMIUM_BASE_HASH_INVALID')
            if kind == 'new' and expected or kind == 'replace' and not expected:
                raise ReviewError('GO_PREMIUM_BASE_HASH_INVALID')
            if before != after and (kind == 'new' and before is not None or kind == 'replace' and (before is None or digest(before) not in expected)):
                raise ReviewError('GO_PREMIUM_SOURCE_CHANGED: ' + relative)
        elif kind == 'patch' and relative in PATCHED and before is not None:
            if not re.fullmatch(r'[0-9a-f]{64}', entry.get('before_sha256', '')) or not re.fullmatch(r'[0-9a-f]{64}', entry.get('after_sha256', '')):
                raise ReviewError('GO_PREMIUM_PATCH_HASH_INVALID')
            edits = entry.get('edits')
            if not isinstance(edits, list) or not edits:
                raise ReviewError('GO_PREMIUM_PATCH_EMPTY')
            after = before
            newline = '\r\n' if b'\r\n' in before else '\n'
            for edit in edits:
                if not isinstance(edit, dict):
                    raise ReviewError('GO_PREMIUM_PATCH_INVALID')
                old, new = edit.get('before'), edit.get('after')
                if not isinstance(old, str) or not isinstance(new, str) or not old or not new or old == new:
                    raise ReviewError('GO_PREMIUM_PATCH_INVALID')
                old, new = old.replace('\n', newline).encode(), new.replace('\n', newline).encode()
                if after.count(new) == 1 and after.replace(new, b'', 1).count(old) == 0:
                    continue
                if after.count(old) != 1 or after.count(new) != 0:
                    raise ReviewError('GO_PREMIUM_SOURCE_CHANGED: ' + relative)
                after = after.replace(old, new, 1)
            if digest(before) == entry['before_sha256'] and digest(after) != entry['after_sha256']:
                raise ReviewError('GO_PREMIUM_PATCH_RESULT_CHANGED')
        else:
            raise ReviewError('GO_PREMIUM_SOURCE_MISSING: ' + str(relative))
        changes.append({'path': relative, 'before': before, 'after': after, 'stat': path.stat() if before is not None else None})
    return changes


def atomic_write(path, content, mode, uid, gid):
    descriptor, temporary = tempfile.mkstemp(prefix='.go-premium-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
            if (os.fstat(stream.fileno()).st_uid, os.fstat(stream.fileno()).st_gid) != (uid, gid):
                os.fchown(stream.fileno(), uid, gid)
            os.fchmod(stream.fileno(), mode)
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def apply_plan(project, changes, backup, owner, verify):
    safe_directory(backup.parent)
    backup.mkdir(mode=0o700)
    receipts = []
    for item in changes:
        if item['before'] is not None and item['before'] != item['after']:
            saved = backup / item['path']
            saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            saved.write_bytes(item['before'])
            saved.chmod(0o600)
        info = item['stat']
        receipts.append({'path': item['path'], 'before_sha256': digest(item['before']) if item['before'] is not None else None,
                         'after_sha256': digest(item['after']), 'mode': stat.S_IMODE(info.st_mode) if info else 0o644,
                         'uid': info.st_uid if info else owner[0], 'gid': info.st_gid if info else owner[1]})
    (backup / 'receipt.json').write_text(json.dumps({'feature': FEATURE, 'files': receipts}, indent=2) + '\n')
    (backup / 'receipt.json').chmod(0o600)
    applied, created = [], []
    try:
        for item in changes:
            path = checked_path(project, item['path'])
            current = regular(path) if path.exists() else None
            if current != item['before']:
                raise ReviewError('GO_PREMIUM_CONCURRENT_SOURCE_CHANGE')
            if current == item['after']:
                continue
            missing, parent = [], path.parent
            while not parent.exists():
                missing.append(parent)
                parent = parent.parent
            for parent in reversed(missing):
                parent.mkdir(mode=0o755)
                parent.chmod(0o755)
                if (parent.stat().st_uid, parent.stat().st_gid) != owner:
                    os.chown(parent, *owner)
                created.append(parent)
            info = item['stat']
            # Record before rename: a later directory fsync can fail after publication.
            applied.append(item)
            atomic_write(path, item['after'], stat.S_IMODE(info.st_mode) if info else 0o644,
                         info.st_uid if info else owner[0], info.st_gid if info else owner[1])
        verify()
    except BaseException:
        conflict = False
        for item in reversed(applied):
            try:
                path = checked_path(project, item['path'])
                current = regular(path) if path.exists() else None
                if current == item['before']:
                    continue
                if current != item['after']:
                    conflict = True
                    continue
                if item['before'] is None:
                    path.unlink()
                else:
                    info = item['stat']
                    atomic_write(path, item['before'], stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
            except BaseException:
                conflict = True
        for folder in reversed(created):
            try:
                folder.rmdir()
            except OSError:
                conflict = True
        if conflict:
            raise ReviewError('GO_PREMIUM_ROLLBACK_CONFLICT')
        raise


def tenant_runtimes():
    if not TENANTS.exists():
        return []
    safe_directory(TENANTS)
    result = []
    for child in sorted(TENANTS.iterdir()):
        if not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', child.name):
            raise ReviewError('GO_PREMIUM_TENANT_PATH_CHANGED')
        safe_directory(child)
        file = child / 'runtime.json'
        if not file.exists():
            continue
        content = regular(file, 65536)
        info = file.stat()
        facts = json.loads(content)
        if facts.get('state') != 'active':
            continue
        slug = facts.get('slug', '')
        if info.st_uid != 0 or info.st_mode & 0o022 or facts.get('format') != 1 or facts.get('id') != child.name or facts.get('base') != str(child) or facts.get('prefix') != '' or not re.fullmatch(r'[0-9a-f]{16}', slug):
            raise ReviewError('GO_PREMIUM_TENANT_RUNTIME_CHANGED')
        public = PROJECT / 'public/dashboards' / slug
        if facts.get('public') != str(public) or public.resolve() != public or not public.is_dir():
            raise ReviewError('GO_PREMIUM_TENANT_PUBLIC_CHANGED')
        config = child / 'bootstrap/cache/config.php'
        if digest(regular(config, 8388608)) != facts.get('config_sha256'):
            raise ReviewError('GO_PREMIUM_TENANT_CONFIG_CHANGED')
        if regular(TENANTS.parent / 'control/deny' / child.name, 64) != b'':
            continue
        result.append(file)
    return result


PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');
try {
    $project='/home/fasakha/public_html';require $project.'/vendor/autoload.php';
    if($argv[1]==='-'){$base=$project;chdir($base);$app=require $project.'/bootstrap/app.php';}
    else{$runtime=\App\Services\OwnerDashboards\Bootstrap::read($argv[1]);$base=$runtime['base'];$app=\App\Services\OwnerDashboards\Bootstrap::application($runtime);chdir($base);}
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    $compiled=config('view.compiled');
    if(!is_string($compiled)||is_link($compiled)||realpath($compiled)!==$base.'/storage/framework/views')throw new RuntimeException('CACHE_SCOPE');
    $manifest=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
    foreach($manifest['files'] as $entry){
        if(!str_starts_with($entry['path'],'resources/views/')||!str_ends_with($entry['path'],'.blade.php'))continue;
        $source=file_get_contents($project.'/'.$entry['path']);$php=$app['blade.compiler']->compileString($source);
        $pipes=[];$process=proc_open([PHP_BINARY,'-l'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('COMPILE');
        fwrite($pipes[0],$php);fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('LINT');
    }
    if($kernel->call('view:clear',[],new \Symfony\Component\Console\Output\NullOutput)!==0)throw new RuntimeException('CLEAR');
    echo "GO_PREMIUM_RUNTIME_VERIFIED\n";
} catch(Throwable $error){fwrite(STDERR,"GO_PREMIUM_RUNTIME_CHECK_FAILED\n");exit(1);}
'''


def run(argv, timeout=90):
    result = subprocess.run(argv, cwd=PROJECT, capture_output=True, text=True, timeout=timeout, env=SAFE_ENV)
    if result.returncode:
        raise ReviewError('GO_PREMIUM_RUNTIME_COMMAND_FAILED')
    return result.stdout


def main():
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise ReviewError('GO_PREMIUM_ROOT_HOST_REQUIRED')
    if len(sys.argv) != 3 or sys.argv[2] not in {'--check', '--apply'}:
        raise ReviewError('GO_PREMIUM_ARGUMENTS_REQUIRED')
    safe_directory(PROJECT)
    if PROJECT.resolve() != PROJECT or not (PROJECT / 'vendor/autoload.php').is_file():
        raise ReviewError('GO_PREMIUM_APPLICATION_REQUIRED')
    account = pwd.getpwnam('fasakha')
    manifest = json.loads(regular(Path(sys.argv[1])))
    lock_path = Path('/var/lock/go-hologram-premium-install.lock')
    if lock_path.is_symlink():
        raise ReviewError('GO_PREMIUM_LOCK_PATH_CHANGED')
    with os.fdopen(os.open(lock_path, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600), 'a') as lock:
        lock_info = os.fstat(lock.fileno())
        if lock_info.st_uid != 0 or lock_info.st_nlink != 1 or not stat.S_ISREG(lock_info.st_mode) or lock_info.st_mode & 0o022:
            raise ReviewError('GO_PREMIUM_UNTRUSTED_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        changes = plan(PROJECT, manifest)
        runtimes = tenant_runtimes()
        changed = sum(item['before'] != item['after'] for item in changes)
        if sys.argv[2] == '--check':
            print(json.dumps({'status': 'GO_HOLOGRAM_PREMIUM_CHECK_READY', 'feature': FEATURE, 'files': len(changes),
                              'changed_files': changed, 'tenant_contexts': len(runtimes), 'database_changes': False}))
            return
        if not changed:
            print(json.dumps({'status': 'GO_HOLOGRAM_PREMIUM_ALREADY_APPLIED', 'feature': FEATURE, 'changed_files': 0}))
            return
        backups = Path('/root/go-hologram-premium-backups')
        safe_directory(backups)
        backups.mkdir(mode=0o700, exist_ok=True)
        if backups.stat().st_uid != 0 or stat.S_IMODE(backups.stat().st_mode) != 0o700:
            raise ReviewError('GO_PREMIUM_BACKUP_PERMISSIONS')
        backup = backups / (datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + os.urandom(4).hex())
        with tempfile.TemporaryDirectory(prefix='go-premium-', dir='/var/tmp') as temporary:
            stage = Path(temporary)
            stage.chmod(0o755)
            probe = stage / 'probe.php'
            probe.write_text(PROBE)
            probe.chmod(0o644)
            candidate = stage / 'manifest.json'
            candidate.write_text(json.dumps(manifest))
            candidate.chmod(0o644)
            def verify():
                for runtime in [None, *runtimes]:
                    output = run(['runuser', '-u', 'fasakha', '--', PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
                                  str(probe), str(runtime) if runtime else '-', str(candidate)])
                    if output.splitlines() != ['GO_PREMIUM_RUNTIME_VERIFIED']:
                        raise ReviewError('GO_PREMIUM_RUNTIME_PROOF_MISSING')
                for item in changes:
                    if regular(checked_path(PROJECT, item['path'])) != item['after']:
                        raise ReviewError('GO_PREMIUM_INSTALLED_HASH_CHANGED')
            try:
                apply_plan(PROJECT, changes, backup, (account.pw_uid, account.pw_gid), verify)
            except BaseException:
                # Invalidate only isolated compiled views after source rollback.
                for runtime in [None, *runtimes]:
                    try:
                        run(['runuser', '-u', 'fasakha', '--', PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
                             str(probe), str(runtime) if runtime else '-', str(candidate)])
                    except BaseException:
                        pass
                raise
    print(json.dumps({'status': 'GO_HOLOGRAM_PREMIUM_APPLIED_AND_VERIFIED', 'feature': FEATURE, 'changed_files': changed,
                      'tenant_contexts': len(runtimes), 'backup': str(backup), 'database_changes': False,
                      'route_changes': False, 'voice_worker_changed': False, 'desktop_rebuilt': False}))


if __name__ == '__main__':
    try:
        main()
    except ReviewError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except BaseException:
        print('GO_PREMIUM_INSTALL_REQUIRES_REVIEW', file=sys.stderr)
        sys.exit(1)
