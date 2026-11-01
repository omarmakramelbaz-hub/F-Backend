#!/usr/bin/env python3
"""Apply only the reviewed hologram fragments; never reset the production checkout."""
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

FEATURE = 'hologram-live-3d-20261101-v2'
BASE_REVISION = '39ea43ff587d5e8f1285c811f7e11b02a137ecc2'
# This exact list is finalized alongside the generated, pinned manifest.
ALLOWED = {
    'app/Http/Controllers/Dashboard/HomeController.php',
    'app/Services/Dashboard/HomeHologramVoice.php',
    'public/dashboard/js/home-hud.js',
    'public/dashboard/js/go-hologram.js',
    'public/dashboard/js/go-hologram-shell.js',
    'public/dashboard/css/home-hud.css',
    'public/dashboard/css/go-hologram.css',
    'public/dashboard/audio/go-welcome.mp3',
    'public/dashboard/audio/go-welcome.envelope.json',
    'public/dashboard/audio/go-welcome-omar.mp3',
    'public/dashboard/audio/go-welcome-omar.envelope.json',
    'resources/views/admin/home_hud.blade.php',
    'resources/views/admin/partials/go_hologram_sidebar.blade.php',
}


class ReviewError(RuntimeError):
    pass


def digest(content):
    return hashlib.sha256(content).hexdigest()


def checked_path(project, relative):
    if relative not in ALLOWED or Path(relative).is_absolute() or '..' in Path(relative).parts:
        raise ReviewError('GO_HOLOGRAM_UNREVIEWED_PATH')
    path = project / relative
    for candidate in [project, *reversed(path.relative_to(project).parents), path]:
        candidate = candidate if candidate.is_absolute() else project / candidate
        if candidate.is_symlink():
            raise ReviewError('GO_HOLOGRAM_SYMLINK_REFUSED: ' + relative)
    # The loop above checks intermediate parents as well as the leaf.
    for parent in path.parents:
        if parent == project.parent:
            break
        if parent.is_symlink() or parent.exists() and not parent.is_dir():
            raise ReviewError('GO_HOLOGRAM_PARENT_CHANGED: ' + relative)
    if path.exists() and not path.is_file():
        raise ReviewError('GO_HOLOGRAM_NOT_A_FILE: ' + relative)
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
        raise ReviewError('GO_HOLOGRAM_PAYLOAD_INVALID') from error
    if digest(value) != entry.get('after_sha256'):
        raise ReviewError('GO_HOLOGRAM_PAYLOAD_HASH_MISMATCH')
    return value


def plan(project, manifest):
    if manifest.get('format') != 1 or manifest.get('feature') != FEATURE or manifest.get('base_revision') != BASE_REVISION:
        raise ReviewError('GO_HOLOGRAM_MANIFEST_CHANGED')
    files = manifest.get('files')
    if not isinstance(files, list) or not files or len(files) > len(ALLOWED):
        raise ReviewError('GO_HOLOGRAM_MANIFEST_PATHS_CHANGED')
    if any(not isinstance(item, dict) for item in files) or len({item.get('path') for item in files}) != len(files):
        raise ReviewError('GO_HOLOGRAM_DUPLICATE_PATH')
    changes = []
    for entry in files:
        relative = entry.get('path')
        path = checked_path(project, relative)
        before = path.read_bytes() if path.is_file() else None
        kind = entry.get('kind')
        if kind == 'new':
            after = payload(entry)
            if entry.get('before_sha256') is not None or before is not None and before != after:
                raise ReviewError('GO_HOLOGRAM_SOURCE_CHANGED: ' + relative)
        elif kind == 'patch' and before is not None:
            if not re.fullmatch(r'[0-9a-f]{64}', entry.get('before_sha256', '')):
                raise ReviewError('GO_HOLOGRAM_BASE_HASH_MISSING')
            if not re.fullmatch(r'[0-9a-f]{64}', entry.get('after_sha256', '')):
                raise ReviewError('GO_HOLOGRAM_AFTER_HASH_MISSING')
            edits = entry.get('edits')
            if not isinstance(edits, list) or not edits:
                raise ReviewError('GO_HOLOGRAM_PATCH_EMPTY')
            after = before
            newline = '\r\n' if b'\r\n' in before else '\n'
            for edit in edits:
                old, new = edit.get('before'), edit.get('after')
                if not isinstance(old, str) or not isinstance(new, str) or not old or not new or old == new:
                    raise ReviewError('GO_HOLOGRAM_PATCH_INVALID')
                old = old.replace('\n', newline).encode('utf-8')
                new = new.replace('\n', newline).encode('utf-8')
                if after.count(new) == 1 and after.replace(new, b'', 1).count(old) == 0:
                    continue
                if after.count(old) != 1 or after.count(new) != 0:
                    raise ReviewError('GO_HOLOGRAM_SOURCE_CHANGED: ' + relative)
                after = after.replace(old, new, 1)
            # An exact baseline must produce the exact reviewed result. Compatible
            # changes outside the unique fragments are deliberately preserved.
            if digest(before) == entry['before_sha256'] and digest(after) != entry['after_sha256']:
                raise ReviewError('GO_HOLOGRAM_PATCH_HASH_MISMATCH: ' + relative)
        else:
            raise ReviewError('GO_HOLOGRAM_SOURCE_MISSING: ' + relative)
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
                raise ReviewError('GO_HOLOGRAM_CONCURRENT_SOURCE_CHANGE: ' + change['path'])
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
            raise ReviewError('GO_HOLOGRAM_ROLLBACK_CONFLICT')
        raise


PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');
try {
    $project='/home/fasakha/public_html';
    require $project.'/vendor/autoload.php';
    if(($argv[1]??'-')==='-') {
        chdir($project);$app=require $project.'/bootstrap/app.php';$base=$project;
    } else {
        $runtime=\App\Services\OwnerDashboards\Bootstrap::read($argv[1],true);
        $app=\App\Services\OwnerDashboards\Bootstrap::application($runtime);$base=$runtime['base'];chdir($base);
    }
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    if(isset($runtime)) {
        $connection=$app['db']->connection();
        if($connection->getDatabaseName()!==$runtime['database']||$connection->getConfig('username')!==$runtime['db_user']
            ||!\App\Services\OwnerDashboards\Runtime::tenant())throw new RuntimeException('GO_HOLOGRAM_TENANT_BINDING_FAILED');
    }
    if(realpath(dirname($app->getCachedRoutesPath()))!==$base.'/bootstrap/cache')throw new RuntimeException('GO_HOLOGRAM_CACHE_SCOPE_FAILED');
    $compiled=$app['config']->get('view.compiled');
    if(!is_string($compiled)||is_link($compiled)||realpath($compiled)!==$base.'/storage/framework/views')throw new RuntimeException('GO_HOLOGRAM_CACHE_SCOPE_FAILED');
    if($kernel->call('route:clear',[],new \Symfony\Component\Console\Output\NullOutput())!==0
        ||$kernel->call('view:clear',[],new \Symfony\Component\Console\Output\NullOutput())!==0)throw new RuntimeException('GO_HOLOGRAM_CACHE_CLEAR_FAILED');
    $manifest=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
    foreach($manifest['files'] as $entry) {
        if(!str_starts_with($entry['path'],'resources/views/')||!str_ends_with($entry['path'],'.blade.php'))continue;
        $compiled=$app['blade.compiler']->compileString(file_get_contents($project.'/'.$entry['path']));
        $pipes=[];$process=proc_open([PHP_BINARY,'-l'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('GO_HOLOGRAM_VIEW_LINT_FAILED');
        fwrite($pipes[0],$compiled);fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('GO_HOLOGRAM_VIEW_LINT_FAILED');
    }
    if(!app('router')->getRoutes()->getByName('admin_dash'))throw new RuntimeException('GO_HOLOGRAM_HOME_ROUTE_MISSING');
    $voice=app(\App\Services\Dashboard\HomeHologramVoice::class);
    foreach(['Omar','General Account'] as $name) {
        $audio=$voice->forActor((object)['name'=>$name]);
        if(!is_array($audio)||!isset($audio['url'],$audio['envelope']['values'])||count($audio['envelope']['values'])<1)throw new RuntimeException('GO_HOLOGRAM_LOCAL_VOICE_MISSING');
    }
    echo "GO_HOLOGRAM_RUNTIME_VERIFIED\n";
}catch(Throwable $error){
    $message=preg_match('/^GO_HOLOGRAM_[A-Z_]+$/D',$error->getMessage())?$error->getMessage():'GO_HOLOGRAM_RUNTIME_CHECK_FAILED';
    fwrite(STDERR,$message."\n");exit(1);
}
'''


def run(command, cwd, timeout=60):
    result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        error = result.stderr.strip()
        raise ReviewError(error if re.fullmatch(r'GO_HOLOGRAM_[A-Z_]+', error) else 'GO_HOLOGRAM_RUNTIME_COMMAND_FAILED')
    return result.stdout


def tenant_runtimes():
    base = Path('/home/fasakha/go-owner-dashboards/tenants')
    if not base.exists():
        return []
    if base.is_symlink() or base.resolve() != base:
        raise ReviewError('GO_HOLOGRAM_TENANT_ROOT_CHANGED')
    result = []
    for directory in sorted(base.iterdir()):
        if not re.fullmatch(r'[0-9a-f-]{36}', directory.name):
            raise ReviewError('GO_HOLOGRAM_TENANT_PATH_CHANGED')
        runtime = directory / 'runtime.json'
        if directory.is_symlink() or runtime.is_symlink():
            raise ReviewError('GO_HOLOGRAM_TENANT_PATH_CHANGED')
        if not runtime.is_file():
            continue
        facts = json.loads(runtime.read_text())
        if facts.get('state') == 'deleted':
            continue
        if facts.get('id') != directory.name or facts.get('base') != str(directory):
            raise ReviewError('GO_HOLOGRAM_TENANT_RUNTIME_CHANGED')
        # A tenant still being provisioned may not have a config cache yet. Its
        # first health bootstrap will compile the updated shared templates.
        if (directory / 'bootstrap/cache/config.php').is_file():
            result.append(runtime)
    return result


def main():
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise ReviewError('GO_HOLOGRAM_SERVER_REQUIRED')
    if len(sys.argv) != 3 or sys.argv[2] not in {'--check', '--apply'}:
        raise ReviewError('GO_HOLOGRAM_ARGUMENTS_REQUIRED')
    project=Path('/home/fasakha/public_html');php='/opt/cpanel/ea-php82/root/usr/bin/php'
    if project.is_symlink() or project.resolve() != project or not (project/'vendor/autoload.php').is_file():
        raise ReviewError('GO_HOLOGRAM_APPLICATION_REQUIRED')
    account=pwd.getpwnam('fasakha')
    manifest_path=Path(sys.argv[1])
    if manifest_path.is_symlink() or not manifest_path.is_file():
        raise ReviewError('GO_HOLOGRAM_MANIFEST_MISSING')
    manifest=json.loads(manifest_path.read_text())
    with open('/var/lock/go-hologram-opening.lock','a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        changes=plan(project,manifest)
        runtimes=tenant_runtimes()
        if sys.argv[2]=='--check':
            print(json.dumps({'status':'GO_HOLOGRAM_CHECK_READY','feature':FEATURE,'base_revision':BASE_REVISION,'files':len(changes),
                              'changed_files':sum(item['before']!=item['after'] for item in changes),
                              'tenant_contexts':len(runtimes)}))
            return
        backup_root=Path('/root/go-hologram-opening-backups')
        if backup_root.is_symlink() or backup_root.resolve()!=backup_root:
            raise ReviewError('GO_HOLOGRAM_BACKUP_PATH_CHANGED')
        backup_root.mkdir(mode=0o700,exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='go-hologram-',dir='/var/tmp') as temporary:
            stage=Path(temporary);stage.chmod(0o755)
            for index,change in enumerate(changes):
                if not change['path'].endswith('.php'):
                    continue
                candidate=stage/(str(index)+'.php');candidate.write_bytes(change['after']);candidate.chmod(0o644)
                run([php,'-l',str(candidate)],project)
            probe=stage/'probe.php';probe.write_text(PROBE);probe.chmod(0o644)
            candidate_manifest=stage/'manifest.json';candidate_manifest.write_text(json.dumps(manifest));candidate_manifest.chmod(0o644)
            def verify():
                for runtime in [None,*runtimes]:
                    output=run(['runuser','-u','fasakha','--',php,'-d','display_errors=0','-d','log_errors=0',
                                str(probe),str(runtime) if runtime else '-',str(candidate_manifest)],project)
                    if output.splitlines()!=['GO_HOLOGRAM_RUNTIME_VERIFIED']:
                        raise ReviewError('GO_HOLOGRAM_RUNTIME_PROOF_MISSING')
                for change in changes:
                    if checked_path(project,change['path']).read_bytes()!=change['after']:
                        raise ReviewError('GO_HOLOGRAM_INSTALLED_HASH_MISMATCH')
            backup=backup_root/(datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+os.urandom(4).hex())
            try:
                apply_plan(project,changes,backup,(account.pw_uid,account.pw_gid),verify)
            except BaseException:
                # Re-clear isolated view/route caches only, now against the restored source.
                for runtime in [None,*runtimes]:
                    try:
                        run(['runuser','-u','fasakha','--',php,str(probe),str(runtime) if runtime else '-',str(candidate_manifest)],project)
                    except BaseException:
                        pass
                raise
    print(json.dumps({'status':'GO_HOLOGRAM_OPENING_READY','feature':FEATURE,'base_revision':BASE_REVISION,'changed_files':sum(item['before']!=item['after'] for item in changes),
                      'verified_tenant_contexts':len(runtimes),'backup':str(backup),
                      'database_changes':False,'desktop_rebuilt':False}))


if __name__=='__main__':
    try:
        main()
    except ReviewError as error:
        print(str(error),file=sys.stderr);sys.exit(1)
    except BaseException:
        print('GO_HOLOGRAM_DEPLOYMENT_REQUIRES_REVIEW',file=sys.stderr);sys.exit(1)
