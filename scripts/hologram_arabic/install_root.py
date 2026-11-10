#!/usr/bin/env python3
"""Apply only the reviewed hologram fragments; never reset the production checkout."""
from datetime import datetime, timezone
import base64
import importlib.util
import time
import shutil
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

FEATURE = 'hologram-arabic-response-20261110'
BASE_REVISION = '7258fd82706494020aa9c4262d41a1ce354ed442'
# This exact list is finalized alongside the generated, pinned manifest.
ALLOWED = {
    'app/Providers/AppServiceProvider.php',
    'app/Services/Dashboard/HomeHologramArabicVoice.php',
    'app/Http/Controllers/Dashboard/HomeHologramArabicVoiceController.php',
    'config/home_hologram_arabic_voice.php',
    'routes/hologram_arabic_voice.php',
    'public/dashboard/js/hud-commands.js',
    'public/dashboard/css/hud-commands.css',
    'resources/views/admin/home_hud.blade.php',
}


class ReviewError(RuntimeError):
    pass


def digest(content):
    return hashlib.sha256(content).hexdigest()


def checked_path(project, relative):
    if relative not in ALLOWED or Path(relative).is_absolute() or '..' in Path(relative).parts:
        raise ReviewError('GO_ARABIC_UNREVIEWED_PATH')
    path = project / relative
    for candidate in [project, *reversed(path.relative_to(project).parents), path]:
        candidate = candidate if candidate.is_absolute() else project / candidate
        if candidate.is_symlink():
            raise ReviewError('GO_ARABIC_SYMLINK_REFUSED: ' + relative)
    # The loop above checks intermediate parents as well as the leaf.
    for parent in path.parents:
        if parent == project.parent:
            break
        if parent.is_symlink() or parent.exists() and not parent.is_dir():
            raise ReviewError('GO_ARABIC_PARENT_CHANGED: ' + relative)
    if path.exists() and not path.is_file():
        raise ReviewError('GO_ARABIC_NOT_A_FILE: ' + relative)
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
        raise ReviewError('GO_ARABIC_PAYLOAD_INVALID') from error
    if digest(value) != entry.get('after_sha256'):
        raise ReviewError('GO_ARABIC_PAYLOAD_HASH_MISMATCH')
    return value


def plan(project, manifest):
    if manifest.get('format') != 1 or manifest.get('feature') != FEATURE or manifest.get('base_revision') != BASE_REVISION:
        raise ReviewError('GO_ARABIC_MANIFEST_CHANGED')
    files = manifest.get('files')
    if not isinstance(files, list) or not files or len(files) > len(ALLOWED):
        raise ReviewError('GO_ARABIC_MANIFEST_PATHS_CHANGED')
    if any(not isinstance(item, dict) for item in files) or len({item.get('path') for item in files}) != len(files):
        raise ReviewError('GO_ARABIC_DUPLICATE_PATH')
    changes = []
    for entry in files:
        relative = entry.get('path')
        path = checked_path(project, relative)
        before = regular(path) if path.is_file() else None
        kind = entry.get('kind')
        if kind == 'new':
            after = payload(entry)
            if entry.get('before_sha256') is not None or before is not None and before != after:
                raise ReviewError('GO_ARABIC_SOURCE_CHANGED: ' + relative)
        elif kind == 'replace':
            after = payload(entry)
            expected = entry.get('before_sha256')
            if not isinstance(expected, str) or not re.fullmatch(r'[0-9a-f]{64}', expected):
                raise ReviewError('GO_ARABIC_BASE_HASH_MISSING')
            accepted = entry.get('accepted_before_sha256', [])
            if not isinstance(accepted, list) or len(accepted) > 4 or any(not isinstance(value, str) or not re.fullmatch(r'[0-9a-f]{64}', value) for value in accepted):
                raise ReviewError('GO_ARABIC_BASE_HASH_MISSING')
            if before != after and (before is None or digest(before) not in [expected, *accepted]):
                raise ReviewError('GO_ARABIC_SOURCE_CHANGED: ' + relative)
        elif kind == 'patch' and before is not None:
            if not re.fullmatch(r'[0-9a-f]{64}', entry.get('before_sha256', '')):
                raise ReviewError('GO_ARABIC_BASE_HASH_MISSING')
            if not re.fullmatch(r'[0-9a-f]{64}', entry.get('after_sha256', '')):
                raise ReviewError('GO_ARABIC_AFTER_HASH_MISSING')
            edits = entry.get('edits')
            if not isinstance(edits, list) or not edits:
                raise ReviewError('GO_ARABIC_PATCH_EMPTY')
            after = before
            newline = '\r\n' if b'\r\n' in before else '\n'
            for edit in edits:
                old, new = edit.get('before'), edit.get('after')
                if not isinstance(old, str) or not isinstance(new, str) or not old or not new or old == new:
                    raise ReviewError('GO_ARABIC_PATCH_INVALID')
                old = old.replace('\n', newline).encode('utf-8')
                new = new.replace('\n', newline).encode('utf-8')
                if after.count(new) == 1 and after.replace(new, b'', 1).count(old) == 0:
                    continue
                if after.count(old) != 1 or after.count(new) != 0:
                    raise ReviewError('GO_ARABIC_SOURCE_CHANGED: ' + relative)
                after = after.replace(old, new, 1)
            unique = entry.get('unique_after', [])
            if not isinstance(unique, list) or len(unique) > 4 or any(
                    not isinstance(marker, str) or not 1 <= len(marker) <= 256
                    or '\n' in marker or '\r' in marker for marker in unique):
                raise ReviewError('GO_ARABIC_PATCH_INVALID')
            # A loader already inserted elsewhere is an overlapping live edit,
            # even when the original voice-loader line remains unmodified.
            if any(after.count(marker.encode('utf-8')) != 1 for marker in unique):
                raise ReviewError('GO_ARABIC_SOURCE_CHANGED: ' + relative)
            # An exact baseline must produce the exact reviewed result. Compatible
            # changes outside the unique fragments are deliberately preserved.
            if digest(before) == entry['before_sha256'] and digest(after) != entry['after_sha256']:
                raise ReviewError('GO_ARABIC_PATCH_HASH_MISMATCH: ' + relative)
        else:
            raise ReviewError('GO_ARABIC_SOURCE_MISSING: ' + relative)
        changes.append({'path': relative, 'before': before, 'after': after,
                        'stat': path.stat() if before is not None else None,
                        'compatible_live_changes': before is not None and digest(before) not in
                            {entry.get('before_sha256'), entry.get('after_sha256')}})
    return changes


def atomic_write(path, content, mode, uid, gid):
    descriptor, temporary = tempfile.mkstemp(prefix='.go-hologram-', dir=path.parent)
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
    backup.mkdir(mode=0o700)
    receipts = []
    for change in changes:
        if change['before'] is not None:
            destination = backup / change['path']
            destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            destination.write_bytes(change['before'])
            destination.chmod(0o600)
        info = change['stat']
        receipts.append({'path': change['path'],
                         'before_sha256': digest(change['before']) if change['before'] is not None else None,
                         'after_sha256': digest(change['after']),
                         'mode': stat.S_IMODE(info.st_mode) if info else 0o644,
                         'uid': info.st_uid if info else owner[0],
                         'gid': info.st_gid if info else owner[1],
                         'compatible_live_changes': change['compatible_live_changes']})
    (backup / 'receipt.json').write_text(json.dumps({'feature': FEATURE, 'files': receipts}, indent=2) + '\n')
    applied, created = [], []
    try:
        for change in changes:
            path = checked_path(project, change['path'])
            current = path.read_bytes() if path.exists() else None
            if current != change['before']:
                raise ReviewError('GO_ARABIC_CONCURRENT_SOURCE_CHANGE: ' + change['path'])
            if current == change['after']:
                continue
            missing = []
            parent = path.parent
            while not parent.exists():
                missing.append(parent)
                parent = parent.parent
            for parent in reversed(missing):
                parent.mkdir(mode=0o755)
                if (parent.stat().st_uid, parent.stat().st_gid) != owner:
                    os.chown(parent, *owner)
                created.append(parent)
            info = change['stat']
            # Include the current path before writing: an fsync error can occur
            # after its atomic rename has already installed the new bytes.
            applied.append(change)
            atomic_write(path, change['after'], stat.S_IMODE(info.st_mode) if info else 0o644,
                         info.st_uid if info else owner[0], info.st_gid if info else owner[1])
        verify()
    except BaseException:
        conflict = False
        for change in reversed(applied):
            try:
                path = checked_path(project, change['path'])
                current = path.read_bytes() if path.is_file() else None
                if current == change['before']:
                    continue
                if current != change['after']:
                    conflict = True
                    continue
                if change['before'] is None:
                    path.unlink()
                else:
                    info = change['stat']
                    atomic_write(path, change['before'], stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
            except BaseException:
                # A concurrent edit/symlink on one path must not prevent safe
                # restoration of the earlier, independently verified files.
                conflict = True
        for folder in reversed(created):
            try:
                folder.rmdir()
            except OSError:
                conflict = True
        if conflict:
            raise ReviewError('GO_ARABIC_ROLLBACK_CONFLICT')
        raise


PROJECT = Path('/home/fasakha/public_html')
TENANTS = Path('/home/fasakha/go-owner-dashboards')
LIB = Path('/usr/local/lib/go-hologram-arabic')
STATE = Path('/var/lib/go-hologram-arabic')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
ENGINE_URL = 'https://files.pythonhosted.org/packages/32/8b/c480d8d4e71fcbcf2ff9fe574ead95ad96b63886acbdff4dc70479ff2bcd/piper_phonemize-1.1.0-cp39-cp39-manylinux_2_28_x86_64.whl'
ENGINE_SIZE = 24954320
ENGINE_SHA256 = '59158d9dce0ee6c6f3f53cd48b13ef0069bf191f4203846f7c1ffab42160b699'
ENGINE_LIBRARY = 'piper_phonemize.libs/libespeak-ng-e09f6b87.so.1.1.51'
PRIVATE_FILES = {'synthesize.py', 'GPL-3.0.txt', 'NOTICE.txt'}
SAFE_ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8', 'PYTHONNOUSERSITE': '1'}


def regular(path, maximum=16777216):
    if path.is_symlink() or not path.is_file():
        raise ReviewError('GO_ARABIC_REGULAR_FILE_REQUIRED')
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > maximum:
        raise ReviewError('GO_ARABIC_UNSAFE_FILE')
    return path.read_bytes()


def clean_parents(path):
    for parent in [path, *path.parents]:
        if parent.is_symlink() or parent.exists() and not parent.is_dir():
            raise ReviewError('GO_ARABIC_DIRECTORY_CHANGED')


def directory(path, uid, gid, mode):
    clean_parents(path)
    if not path.exists():
        if not path.parent.is_dir():
            raise ReviewError('GO_ARABIC_PARENT_MISSING')
        path.mkdir(mode=mode)
        os.chown(path, uid, gid)
        os.chmod(path, mode)
    info = path.stat()
    if (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) != (uid, gid, mode):
        raise ReviewError('GO_ARABIC_DIRECTORY_PERMISSIONS')


def command(argv, timeout=45, data=None):
    try:
        result = subprocess.run(argv, cwd=PROJECT, input=data, capture_output=True,
                                timeout=timeout, env=SAFE_ENV)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise ReviewError('GO_ARABIC_RUNTIME_COMMAND_FAILED') from error
    if result.returncode:
        raise ReviewError('GO_ARABIC_RUNTIME_COMMAND_FAILED')
    return result


def contexts(project=PROJECT, tenants=TENANTS):
    result = [{'scope': 'central', 'base': project, 'runtime': None}]
    root = tenants / 'tenants'
    if not root.exists():
        return result
    clean_parents(root)
    for child in sorted(root.iterdir()):
        if not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', child.name):
            raise ReviewError('GO_ARABIC_TENANT_PATH_CHANGED')
        clean_parents(child)
        file = child / 'runtime.json'
        if not file.exists():
            continue
        facts = json.loads(regular(file, 65536))
        if facts.get('state') != 'active':
            continue
        info, slug = file.stat(), facts.get('slug', '')
        if info.st_uid != 0 or info.st_mode & 0o022 or facts.get('format') != 1 or facts.get('id') != child.name \
                or facts.get('base') != str(child) or facts.get('prefix') != '' or not re.fullmatch(r'[0-9a-f]{16}', slug):
            raise ReviewError('GO_ARABIC_TENANT_RUNTIME_CHANGED')
        public = project / 'public/dashboards' / slug
        if facts.get('public') != str(public) or public.resolve() != public or not public.is_dir():
            raise ReviewError('GO_ARABIC_TENANT_PUBLIC_CHANGED')
        if facts.get('origin') != 'https://' + facts.get('host', '') or not re.fullmatch('go-' + slug + r'\.[a-z0-9.-]+', facts.get('host', '')):
            raise ReviewError('GO_ARABIC_TENANT_ORIGIN_CHANGED')
        if regular(tenants / 'control/deny' / child.name, 64) != b'':
            continue
        if digest(regular(child / 'bootstrap/cache/config.php', 8388608)) != facts.get('config_sha256'):
            raise ReviewError('GO_ARABIC_TENANT_CONFIG_CHANGED')
        result.append({'scope': 'tenant:' + child.name, 'base': child, 'runtime': file})
    return result


def engine_files(archive):
    """Read only eSpeak library/data from the SHA-pinned official wheel, without pip."""
    import zipfile
    if len(regular(archive, ENGINE_SIZE + 1)) != ENGINE_SIZE or digest(regular(archive, ENGINE_SIZE + 1)) != ENGINE_SHA256:
        raise ReviewError('GO_ARABIC_ENGINE_ARCHIVE_CHANGED')
    files = {}
    with zipfile.ZipFile(archive) as source:
        members = source.infolist()
        if len(members) > 2000:
            raise ReviewError('GO_ARABIC_ENGINE_ARCHIVE_INVALID')
        for member in members:
            if member.filename == ENGINE_LIBRARY:
                relative = 'libespeak-ng.so.1'
            elif member.filename.startswith('piper_phonemize/espeak-ng-data/'):
                relative = member.filename[len('piper_phonemize/'):]
                if member.is_dir():
                    continue
            else:
                continue
            mode = member.external_attr >> 16
            if mode and stat.S_IFMT(mode) not in {0, stat.S_IFREG} or member.file_size > 16777216 or Path(relative).is_absolute() or '..' in Path(relative).parts or relative in files:
                raise ReviewError('GO_ARABIC_ENGINE_ARCHIVE_INVALID')
            files[relative] = source.read(member)
    if 'libespeak-ng.so.1' not in files or 'espeak-ng-data/ar_dict' not in files or not 10 <= len(files) <= 1000 \
            or sum(map(len, files.values())) > 33554432:
        raise ReviewError('GO_ARABIC_ENGINE_ARCHIVE_INVALID')
    return files


def private_payload(manifest):
    entries = manifest.get('private_files', [])
    if not isinstance(entries, list) or len(entries) != len(PRIVATE_FILES) or {e.get('path') for e in entries} != PRIVATE_FILES:
        raise ReviewError('GO_ARABIC_PRIVATE_PATHS_CHANGED')
    return {e['path']: payload(e) for e in entries}


def runtime_plan(files):
    """A first installation or exact replay; never replace an unknown helper/library."""
    clean_parents(LIB)
    planned = []
    for relative, data in sorted(files.items()):
        path = LIB / relative
        clean_parents(path.parent)
        if path.is_symlink():
            raise ReviewError('GO_ARABIC_PRIVATE_SOURCE_CHANGED')
        before = regular(path, 33554432) if path.exists() else None
        if before is not None:
            info = path.stat()
            if before != data or info.st_uid != 0 or info.st_mode & 0o022:
                raise ReviewError('GO_ARABIC_PRIVATE_SOURCE_CHANGED')
        planned.append({'path': relative, 'before': before, 'after': data})
    return planned


def publish_runtime(planned, account, created):
    directory(LIB, 0, account.pw_gid, 0o750)
    for entry in planned:
        target = LIB / entry['path']
        missing, parent = [], target.parent
        while not parent.exists():
            missing.append(parent)
            parent = parent.parent
        for parent in reversed(missing):
            directory(parent, 0, account.pw_gid, 0o750)
        current = regular(target, 33554432) if target.exists() else None
        if current != entry['before']:
            raise ReviewError('GO_ARABIC_CONCURRENT_PRIVATE_CHANGE')
        if current != entry['after']:
            created.append(entry)
            atomic_write(target, entry['after'], 0o640, 0, account.pw_gid)
    return created


def rollback_runtime(created):
    conflict = False
    for entry in reversed(created):
        target = LIB / entry['path']
        try:
            if target.exists() and regular(target, 33554432) == entry['after']:
                target.unlink()
            elif target.exists() or target.is_symlink():
                conflict = True
        except (OSError, ReviewError):
            conflict = True
    if conflict:
        raise ReviewError('GO_ARABIC_PRIVATE_ROLLBACK_CONFLICT')


def valid_wav(data):
    import io
    import wave
    try:
        with wave.open(io.BytesIO(data)) as audio:
            if not 44 < len(data) <= 2097152 or audio.getnchannels() != 1 or audio.getsampwidth() != 2 \
                    or not 8000 <= audio.getframerate() <= 48000 or not 0.2 <= audio.getnframes() / audio.getframerate() <= 45:
                raise ValueError('shape')
            raw = audio.readframes(audio.getnframes())
            if len(raw) != audio.getnframes() * 2 or not any(raw):
                raise ValueError('silence')
    except (ValueError, EOFError, wave.Error) as error:
        raise ReviewError('GO_ARABIC_SMOKE_AUDIO_INVALID') from error


PHP_HELPER = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');
try {
    $root='/home/fasakha/public_html';require $root.'/vendor/autoload.php';
    if($argv[1]==='-'){$base=$root;chdir($base);$app=require $root.'/bootstrap/app.php';}
    else{$runtime=\App\Services\OwnerDashboards\Bootstrap::read($argv[1]);$base=$runtime['base'];$app=\App\Services\OwnerDashboards\Bootstrap::application($runtime);chdir($base);}
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    if(realpath(dirname($app->getCachedRoutesPath()))!==$base.'/bootstrap/cache')throw new RuntimeException('CACHE_SCOPE');
    if(!function_exists('proc_open')||!function_exists('proc_get_status')||!function_exists('proc_terminate'))throw new RuntimeException('PROC_OPEN_REQUIRED');
    if($argv[2]==='preflight'){echo json_encode(['ok'=>true]).PHP_EOL;exit;}
    if($argv[2]==='clear'){$quiet=new \Symfony\Component\Console\Output\NullOutput;if($kernel->call('route:clear',[],$quiet)!==0)throw new RuntimeException('CLEAR');echo json_encode(['ok'=>true]).PHP_EOL;exit;}
    $matches=[];foreach(app('router')->getRoutes() as $candidate)if($candidate->getName()==='dashboard.hologram-arabic.speak')$matches[]=$candidate;
    if(count($matches)!==1)throw new RuntimeException('ROUTE_COUNT');$route=$matches[0];
    if($route->uri()!=='admin/dashboard/hologram-arabic/speak'||$route->methods()!==['POST']||array_diff(['web','lang','IsAdmin','throttle:12,1'],$route->gatherMiddleware()))throw new RuntimeException('ROUTE');
    $voice=app(\App\Services\Dashboard\HomeHologramArabicVoice::class);
    $result=$voice->synthesize((object)['id'=>1,'account_type'=>'admin'],'مرحباً، الصوت العربي جاهز.');
    if(($result['state']??null)!=='ready'||!isset($result['path'])||!is_file($result['path'])||is_link($result['path']))throw new RuntimeException('SERVICE');
    $path=$result['path'];$data=file_get_contents($path);unlink($path);
    if(strlen($data)<1044||substr($data,0,4)!=='RIFF'||substr($data,8,4)!=='WAVE')throw new RuntimeException('AUDIO');
    echo json_encode(['ok'=>true,'route'=>true,'private_audio'=>true,'audio_bytes'=>strlen($data)]).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"GO_ARABIC_PHP_RUNTIME_CHECK_FAILED\n");exit(1);}
'''


def php_context(helper, context, mode):
    result = command(['runuser', '-u', 'fasakha', '--', PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
                      str(helper), str(context['runtime']) if context['runtime'] else '-', mode], timeout=45)
    try:
        value = json.loads(result.stdout)
        if value.get('ok') is not True:
            raise ValueError('proof')
        return value
    except (ValueError, TypeError) as error:
        raise ReviewError('GO_ARABIC_PHP_PROOF_MISSING') from error


def route_snapshots(scopes, backup):
    records = []
    for index, scope in enumerate(scopes):
        for name in ['routes.php', 'routes-v6.php', 'routes-v7.php']:
            path = scope['base'] / 'bootstrap/cache' / name
            if path.exists():
                data, info = regular(path, 16777216), path.stat()
                saved = backup / 'route-caches' / str(index) / name
                saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                saved.write_bytes(data)
                saved.chmod(0o600)
                records.append((path, data, info))
    return records


def restore_routes(records):
    conflict = False
    for path, before, info in records:
        if path.is_symlink() or path.exists() and regular(path) != before:
            conflict = True
        elif not path.exists():
            atomic_write(path, before, stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
    if conflict:
        raise ReviewError('GO_ARABIC_ROUTE_ROLLBACK_CONFLICT')


def main():
    import platform
    if len(sys.argv) != 3 or sys.argv[2] not in {'--check', '--apply'}:
        raise ReviewError('GO_ARABIC_USAGE')
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524' or platform.machine() != 'x86_64':
        raise ReviewError('GO_ARABIC_HOST_REQUIRED')
    os.umask(0o077)
    manifest = json.loads(regular(Path(sys.argv[1]), 2097152))
    source_plan = plan(PROJECT, manifest)
    scopes, account = contexts(), pwd.getpwnam('fasakha')
    if not PROJECT.is_dir() or not (PROJECT / 'artisan').is_file() or not Path(PHP).is_file():
        raise ReviewError('GO_ARABIC_APPLICATION_REQUIRED')
    descriptor = os.open('/var/lock/go-hologram-arabic-install.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
    try:
        with tempfile.TemporaryDirectory(prefix='go-arabic-', dir='/var/tmp') as temporary:
            stage = Path(temporary)
            os.chown(stage, 0, account.pw_gid)
            stage.chmod(0o750)
            archive = stage / 'engine.whl'
            command(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https',
                     '--proto-redir', '=https', '--max-time', '90', ENGINE_URL, '-o', str(archive)], timeout=100)
            engine = engine_files(archive)
            files = private_payload(manifest)
            files.update({'engine/' + name: data for name, data in engine.items()})
            private = runtime_plan(files)
            for name, data in files.items():
                target = stage / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
                target.chmod(0o640)
                os.chown(target, 0, account.pw_gid)
            for path in sorted(stage.rglob('*')):
                if path.is_dir():
                    path.chmod(0o750)
                    os.chown(path, 0, account.pw_gid)
            helper = stage / 'runtime-probe.php'
            helper.write_text(PHP_HELPER)
            helper.chmod(0o640)
            os.chown(helper, 0, account.pw_gid)
            audio = command(['runuser', '-u', 'fasakha', '--', '/usr/bin/python3', '-I', '-B', str(stage / 'synthesize.py')],
                            timeout=10, data='مرحباً، الصوت العربي جاهز.'.encode('utf-8')).stdout
            valid_wav(audio)
            for scope in scopes:
                php_context(helper, scope, 'preflight')
            if sys.argv[2] == '--check':
                print(json.dumps({'status': 'GO_HOLOGRAM_ARABIC_CHECKED', 'scopes': len(scopes), 'engine_sha256': ENGINE_SHA256}))
                return
            backup_root = Path('/root/go-hologram-arabic-backups')
            directory(backup_root, 0, 0, 0o700)
            backup = Path(tempfile.mkdtemp(prefix=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir=backup_root))
            backup.chmod(0o700)
            routes = route_snapshots(scopes, backup)
            created = []
            proofs = []
            try:
                publish_runtime(private, account, created)
                directory(STATE, account.pw_uid, account.pw_gid, 0o700)
                lock = STATE / 'speech.lock'
                if not lock.exists():
                    atomic_write(lock, b'', 0o600, account.pw_uid, account.pw_gid)
                elif regular(lock, 0) != b'' or lock.stat().st_uid != account.pw_uid or stat.S_IMODE(lock.stat().st_mode) != 0o600:
                    raise ReviewError('GO_ARABIC_LOCK_CHANGED')
                for scope in scopes:
                    directory(scope['base'] / 'storage/app/go-hologram-arabic', account.pw_uid, account.pw_gid, 0o700)
                def verify():
                    for change in source_plan:
                        if change['path'].endswith('.php'):
                            command(['runuser', '-u', 'fasakha', '--', PHP, '-l', str(PROJECT / change['path'])])
                    for scope in scopes:
                        php_context(helper, scope, 'clear')
                    for scope in scopes:
                        proofs.append(php_context(helper, scope, 'verify'))
                    for change in source_plan:
                        if regular(PROJECT / change['path']) != change['after']:
                            raise ReviewError('GO_ARABIC_POST_APPLY_SOURCE_CHANGED')
                apply_plan(PROJECT, source_plan, backup / 'source', (account.pw_uid, account.pw_gid), verify)
            except BaseException:
                restore_routes(routes)
                rollback_runtime(created)
                raise
            status = 'GO_HOLOGRAM_ARABIC_ALREADY_APPLIED' if not created and all(c['before'] == c['after'] for c in source_plan) else 'GO_HOLOGRAM_ARABIC_APPLIED_AND_VERIFIED'
            print(json.dumps({'status': status, 'changed_files': sum(c['before'] != c['after'] for c in source_plan),
                              'backup': str(backup), 'runtime_checks': proofs, 'engine_sha256': ENGINE_SHA256,
                              'database_changed': False, 'greeting_worker_changed': False, 'system_packages_changed': False}))
    finally:
        os.close(descriptor)


if __name__ == '__main__':
    try:
        main()
    except (ReviewError, OSError, ValueError, KeyError) as error:
        print(json.dumps({'status': 'GO_HOLOGRAM_ARABIC_REFUSED', 'reason': str(error) if isinstance(error, ReviewError) else 'preflight-or-install-failed'}))
        raise SystemExit(1)

