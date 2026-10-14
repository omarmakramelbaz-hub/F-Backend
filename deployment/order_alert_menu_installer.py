#!/usr/bin/env python3
"""Install the reviewed order sound and customer restaurant fix without replacing live overlays."""
import argparse
import difflib
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BASE = 'adc9e46a86df569bd6dab15e9f4302fc1c55aa91'
EXISTING_FILES = (
    'resources/views/admin/layouts/footer.blade.php',
    'public/dashboard/js/order-board.js',
    'public/dashboard/js/branch-print-receiver.js',
    'app/Http/Resources/Api/Vendor/ResturantProductResource.php',
    'app/Services/Dashboard/PosBranchPrinting.php',
    'app/Services/Dashboard/OrderBoardService.php',
    'app/Http/Controllers/Dashboard/OrderBoardController.php',
)
NEW_FILES = ('public/dashboard/js/dashboard-order-alerts.js',)
# Exact known desktop overlays, packaged as verified installer inputs rather than
# requiring the older release Git object to exist on the production server.
COMPAT_BASELINES = {
    'public/dashboard/js/branch-print-receiver.js': (
        'deployment/compat/branch-print-receiver.e88.js',
        'ed5d4c5ae5bc2dbe7c751cf96e2a606bbb8b78a1'),
    'app/Services/Dashboard/PosBranchPrinting.php': (
        'deployment/compat/PosBranchPrinting.e88.php',
        '021a53ff471c594fcef75509327aace74bcbf86c'),
}
# Dependencies and consumers precede the footer which activates the shared engine.
PUBLISH_ORDER = (*NEW_FILES, *EXISTING_FILES[1:], EXISTING_FILES[0])


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def matches(lines, block, at_start=False, at_end=False):
    if not block:
        return []
    size = len(block)
    return [i for i in range(len(lines) - size + 1)
            if (not at_start or i == 0) and (not at_end or i + size == len(lines))
            and lines[i:i + size] == block]


def surgical_patch(current, baseline, reviewed, label):
    """Patch uniquely anchored hunks; reject overlap with a concurrent live change."""
    if baseline == reviewed:
        raise RuntimeError('RELEASE_CHANGE_MISSING: ' + label)
    if current == reviewed:
        return current
    old, new, live = [content.splitlines(keepends=True) for content in (baseline, reviewed, current)]
    for group in difflib.SequenceMatcher(a=old, b=new, autojunk=False).get_grouped_opcodes(8):
        a0, a1 = group[0][1], group[-1][2]
        b0, b1 = group[0][3], group[-1][4]
        before, after = old[a0:a1], new[b0:b1]
        start, end = a0 == 0, a1 == len(old)
        pre, post = matches(live, before, start, end), matches(live, after, start, end)
        if len(post) == 1 and (not pre or (len(pre) == 1 and len(after) > len(before)
                and post[0] <= pre[0] and pre[0] + len(before) <= post[0] + len(after))):
            continue
        if len(pre) == 1 and (not post or (len(post) == 1 and len(before) > len(after)
                and pre[0] <= post[0] and post[0] + len(after) <= pre[0] + len(before))):
            live[pre[0]:pre[0] + len(before)] = after
            continue
        raise RuntimeError('SOURCE_PATCH_CONFLICT: ' + label)
    return b''.join(live)


def safe(path):
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise RuntimeError('SYMLINK_PATH: ' + str(path))


def snapshot(path, uid, repair_gid=None):
    safe(path)
    if not path.exists():
        return None
    meta = path.stat()
    trusted_group = repair_gid is not None and meta.st_uid == uid and meta.st_gid == repair_gid
    if (not stat.S_ISREG(meta.st_mode) or meta.st_uid not in (0, uid)
            or meta.st_mode & 0o7002 or (meta.st_mode & 0o020 and not trusted_group)):
        raise RuntimeError('UNSAFE_FILE: ' + str(path))
    return {'bytes': path.read_bytes(), 'uid': meta.st_uid, 'gid': meta.st_gid,
            'mode': stat.S_IMODE(meta.st_mode), 'device': meta.st_dev, 'inode': meta.st_ino,
            'mtime': meta.st_mtime_ns, 'links': meta.st_nlink}


def atomic(path, data, mode, uid, gid):
    safe(path)
    if not path.parent.is_dir():
        raise RuntimeError('PARENT_DIRECTORY_MISSING: ' + str(path.parent))
    fd, temporary = tempfile.mkstemp(prefix='.order-alert-menu-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def owned_command(root, arguments):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', *arguments], cwd=root, capture_output=True)
    if result.returncode:
        raise RuntimeError('APPLICATION_COMMAND_FAILED: ' + pathlib.Path(arguments[0]).name)
    return result.stdout


def source(root, revision, relative):
    data = owned_command(root, ['git', '-C', str(root), 'show', revision + ':' + relative])
    expected = owned_command(root, ['git', '-C', str(root), 'rev-parse', revision + ':' + relative]).decode().strip()
    if not re.fullmatch('[a-f0-9]{40}', expected) or blob(data) != expected:
        raise RuntimeError('SOURCE_BLOB_MISMATCH: ' + relative)
    return data


def patch_reviewed(root, release, relative, current, reviewed):
    baselines = [source(root, BASE, relative)]
    if relative in COMPAT_BASELINES:
        packaged, expected = COMPAT_BASELINES[relative]
        compatibility = source(root, release, packaged)
        if blob(compatibility) != expected:
            raise RuntimeError('COMPAT_BASELINE_BLOB_MISMATCH: ' + relative)
        baselines.append(compatibility)
    results = set()
    for baseline in baselines:
        try:
            results.add(surgical_patch(current, baseline, reviewed, relative))
        except RuntimeError as error:
            if not str(error).startswith('SOURCE_PATCH_CONFLICT:'):
                raise
    if not results:
        raise RuntimeError('SOURCE_PATCH_CONFLICT: ' + relative + ' current_blob=' + blob(current))
    if len(results) != 1:
        raise RuntimeError('SOURCE_PATCH_AMBIGUOUS: ' + relative)
    return results.pop()


PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level=ob_get_level();ob_start();$exit=1;$result=['ok'=>false];
try {
 require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $compiler=$app['blade.compiler'];$caches=[];
 $views=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
 foreach($views as $relative=>$staged) {
  $code=$compiler->compileString(file_get_contents($staged));token_get_all($code,TOKEN_PARSE);
  $compiled=$compiler->getCompiledPath($argv[1].'/'.$relative);
  $prefix=$argv[1].'/storage/framework/views/';
  if(strncmp($compiled,$prefix,strlen($prefix))!==0 || !preg_match('/^[a-f0-9]+\.php$/',substr($compiled,strlen($prefix)))) throw new \RuntimeException('COMPILED_VIEW_PATH_UNSUPPORTED');
  $caches[]=substr($compiled,strlen($argv[1])+1);
 }
 $result=['ok'=>true,'view_caches'=>$caches];$exit=0;
} catch(\Throwable $error) {
 $result=['ok'=>false,'reason'=>$error->getMessage()==='COMPILED_VIEW_PATH_UNSUPPORTED'?'COMPILED_VIEW_PATH_UNSUPPORTED':'ORDER_ALERT_MENU_RUNTIME_FAILED'];
}
while(ob_get_level()>$level)ob_end_clean();echo json_encode($result,JSON_UNESCAPED_SLASHES);exit($exit);
'''


def runtime(root, php, probe, views_manifest):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', php, str(probe), str(root), str(views_manifest)],
                            cwd=root, capture_output=True)
    try:
        data = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise RuntimeError('ORDER_ALERT_MENU_RUNTIME_FAILED')
    if result.returncode or data.get('ok') is not True:
        raise RuntimeError(data.get('reason', 'ORDER_ALERT_MENU_RUNTIME_FAILED'))
    return data


def validated_caches(data):
    caches = data.get('view_caches')
    if not isinstance(caches, list) or any(not isinstance(p, str) or not re.fullmatch(
            r'storage/framework/views/[a-f0-9]+\.php', p) for p in caches) or len(caches) != len(set(caches)):
        raise RuntimeError('COMPILED_VIEW_PATH_UNSUPPORTED')
    return caches


def install(root, release, php=PHP, check_only=False, repair_permissions=False):
    if os.geteuid() != 0 or root != APP_ROOT or php != PHP or not re.fullmatch('[a-f0-9]{40}', release):
        raise RuntimeError('FIXED_ROOT_RELEASE_REQUIRED')
    safe(root)
    user = pwd.getpwnam('fasakha')
    uid, gid = user.pw_uid, user.pw_gid
    if root.stat().st_uid != uid:
        raise RuntimeError('APPLICATION_OWNER_REQUIRED')
    if check_only:
        # A preflight does not even create the deployment lock or a backup directory.
        return _install_locked(root, release, php, True, uid, gid, repair_permissions)
    lock_path = root.parent / '.order-alert-menu-update.lock'
    safe(lock_path)
    lock = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        meta = os.fstat(lock)
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_nlink != 1 or stat.S_IMODE(meta.st_mode) != 0o600:
            raise RuntimeError('UPDATE_LOCK_UNSAFE')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return _install_locked(root, release, php, False, uid, gid, repair_permissions)
    finally:
        os.close(lock)


def _install_locked(root, release, php, check_only, uid, gid, repair_permissions=False):
    with tempfile.TemporaryDirectory(prefix='order-alert-menu-', dir='/tmp') as temporary:
        stage = pathlib.Path(temporary)
        os.chmod(stage, 0o755)
        probe = stage / 'runtime.php'
        probe.write_text(PROBE)
        os.chmod(probe, 0o644)
        originals, targets, modes, views = {}, {}, {}, {}
        def source_snapshot(relative):
            return snapshot(root / relative, uid, gid if repair_permissions else None)
        for relative in (*EXISTING_FILES, *NEW_FILES):
            old = source_snapshot(relative)
            reviewed = source(root, release, relative)
            if relative in EXISTING_FILES:
                if old is None:
                    raise RuntimeError('BASELINE_FILE_MISSING: ' + relative)
                payload = patch_reviewed(root, release, relative, old['bytes'], reviewed)
            else:
                if old and old['bytes'] != reviewed:
                    raise RuntimeError('NEW_FILE_COLLISION: ' + relative)
                payload = reviewed
            originals[relative], targets[relative] = old, payload
            modes[relative] = old['mode'] & ~0o020 if old else 0o644
            checked = stage / relative
            checked.parent.mkdir(parents=True, exist_ok=True)
            checked.write_bytes(payload)
            os.chmod(checked, 0o644)
            for directory in checked.parents:
                os.chmod(directory, 0o755)
                if directory == stage:
                    break
            if relative.endswith('.blade.php'):
                views[relative] = str(checked)
            elif relative.endswith('.php'):
                owned_command(root, [php, '-l', str(checked)])
        manifest_path = stage / 'views.json'
        manifest_path.write_text(json.dumps(views))
        os.chmod(manifest_path, 0o644)
        readiness = runtime(root, php, probe, manifest_path)
        compiled_paths = validated_caches(readiness)
        if len(compiled_paths) != len(views):
            raise RuntimeError('COMPILED_VIEW_PATH_UNSUPPORTED')
        cache_paths = [cache for relative, cache in zip(views, compiled_paths)
                       if originals[relative] is None or originals[relative]['bytes'] != targets[relative]]
        cache_originals = {p: snapshot(root / p, uid) for p in cache_paths}
        repairs = [p for p, old in originals.items() if old and old['mode'] != modes[p]]
        if check_only:
            print(json.dumps({'status': 'ORDER_ALERT_MENU_PREFLIGHT_OK', 'files': len(targets),
                              'permission_repairs': repairs}))
            return
        parent = root.parent / 'order-alert-menu-backups'
        safe(parent)
        if parent.exists() and (not parent.is_dir() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o077):
            raise RuntimeError('BACKUP_PARENT_UNSAFE')
        parent.mkdir(mode=0o700, exist_ok=True)
        backup = pathlib.Path(tempfile.mkdtemp(prefix=release[:12] + '-', dir=parent))
        os.chmod(backup, 0o700)
        manifest = {'release': release, 'baseline': BASE, 'files': {}}
        for relative, old in {**originals, **cache_originals}.items():
            manifest['files'][relative] = None if old is None else {k: v for k, v in old.items() if k != 'bytes'}
            if old:
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                saved.write_bytes(old['bytes'])
                os.chmod(saved, 0o600)
        (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
        os.chmod(backup / 'manifest.json', 0o600)
        written, invalidated = {}, []
        try:
            for relative, old in originals.items():
                if source_snapshot(relative) != old:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            for relative in PUBLISH_ORDER:
                payload, old = targets[relative], originals[relative]
                if source_snapshot(relative) != old:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
                if old is None or old['bytes'] != payload or old['mode'] != modes[relative]:
                    atomic(root / relative, payload, modes[relative], old['uid'] if old else uid, old['gid'] if old else gid)
                    written[relative] = snapshot(root / relative, uid)
            for relative, old in cache_originals.items():
                if snapshot(root / relative, uid) != old:
                    raise RuntimeError('CONCURRENT_VIEW_CACHE_EDIT: ' + relative)
                if old is not None:
                    (root / relative).unlink()
                    invalidated.append(relative)
            # Compile the published Blade sources in memory; no broad cache or DB command.
            manifest_path.write_text(json.dumps({relative: str(root / relative) for relative in views}))
            if validated_caches(runtime(root, php, probe, manifest_path)) != compiled_paths:
                raise RuntimeError('CONCURRENT_VIEW_CONFIGURATION_EDIT')
            for relative, published in written.items():
                if snapshot(root / relative, uid) != published:
                    raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            print(json.dumps({'status': 'ORDER_ALERT_MENU_READY', 'updated_files': len(written),
                              'backup': str(backup), 'release': release, 'permission_repairs': repairs}))
        except BaseException:
            complete = True
            for relative, published in reversed(list(written.items())):
                try:
                    if snapshot(root / relative, uid) != published:
                        complete = False
                        continue
                    old = originals[relative]
                    if old:
                        atomic(root / relative, old['bytes'], old['mode'], old['uid'], old['gid'])
                    else:
                        (root / relative).unlink()
                except (OSError, RuntimeError):
                    complete = False
            for relative in invalidated:
                try:
                    old = cache_originals[relative]
                    # A concurrently recompiled view must remain in place.
                    if snapshot(root / relative, uid) is None:
                        atomic(root / relative, old['bytes'], old['mode'], old['uid'], old['gid'])
                    else:
                        complete = False
                except (OSError, RuntimeError):
                    complete = False
            print('ORDER_ALERT_MENU_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'))
            raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--php', default=PHP)
    parser.add_argument('--check', action='store_true', help='Read-only patch/lint/Blade preflight; no application publication or cache changes')
    parser.add_argument('--repair-permissions', action='store_true',
                        help='Remove group-write only from reviewed application-owned source files in the application primary group')
    args = parser.parse_args()
    try:
        install(pathlib.Path(args.root).absolute(), args.release, args.php, args.check, args.repair_permissions)
    except Exception as error:
        print(str(error))
        raise SystemExit(1)
