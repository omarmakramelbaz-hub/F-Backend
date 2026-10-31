#!/usr/bin/env python3
"""Apply a named bounded account fix, preserving unrelated source and account data."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import socket
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

BASE_ALLOWED = {
    'app/Services/Dashboard/DashboardLoginIdentity.php',
    'app/Services/Dashboard/OwnerMobile.php',
    'app/Http/Controllers/Dashboard/OwnerMobileController.php',
    'app/Http/Controllers/Dashboard/HomeController.php',
    'resources/views/admin/users/form.blade.php',
    'resources/views/admin/users/edit.blade.php',
    'routes/admin.php',
}
ACCOUNT_ALLOWED = {
    'app/Services/Dashboard/DashboardAccountRole.php',
    'app/Services/Dashboard/OwnerMobile.php',
    'app/Http/Controllers/Dashboard/OwnerMobileController.php',
    'app/Repositories/UserRepository.php',
    'resources/views/admin/users/form.blade.php',
    'resources/views/admin/users/edit.blade.php',
    'resources/views/admin/users/index.blade.php',
    'resources/views/admin/users/show.blade.php',
}
FEATURE_PATHS = {'owner-mobile-20261101': BASE_ALLOWED, 'owner-account-fix-20261101': ACCOUNT_ALLOWED}
ALLOWED = BASE_ALLOWED | ACCOUNT_ALLOWED

class ReviewError(RuntimeError):
    pass

def digest(data):
    return hashlib.sha256(data).hexdigest()

def target_path(project, relative):
    if relative not in ALLOWED:
        raise ReviewError('GO_OWNER_MOBILE_UNREVIEWED_PATH')
    path = project / relative
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != project.parent):
        raise ReviewError('GO_OWNER_MOBILE_SYMLINK')
    if not path.parent.is_dir() or path.resolve() != path:
        raise ReviewError('GO_OWNER_MOBILE_PATH_CHANGED')
    return path

def plan(project, manifest):
    if manifest.get('format') != 1 or manifest.get('feature') not in FEATURE_PATHS:
        raise ReviewError('GO_OWNER_MOBILE_MANIFEST_CHANGED')
    files = manifest.get('files', [])
    allowed = FEATURE_PATHS[manifest['feature']]
    if len(files) != len(allowed) or {item['path'] for item in files} != allowed:
        raise ReviewError('GO_OWNER_MOBILE_MANIFEST_PATHS_CHANGED')
    result = []
    for item in files:
        path = target_path(project, item['path'])
        before = path.read_bytes() if path.exists() else None
        if item['kind'] == 'new':
            after = item['content'].encode()
            if before is not None and before != after:
                raise ReviewError('GO_OWNER_MOBILE_SOURCE_CHANGED: '+item['path'])
        elif item['kind'] == 'patch' and before is not None:
            after = before
            newline = '\r\n' if b'\r\n' in before else '\n'
            for edit in item['edits']:
                old = edit['before'].replace('\n', newline).encode()
                new = edit['after'].replace('\n', newline).encode()
                if after.count(new) == 1:
                    continue
                if after.count(old) == 1:
                    after = after.replace(old, new, 1)
                else:
                    raise ReviewError('GO_OWNER_MOBILE_SOURCE_CHANGED: '+item['path'])
        else:
            raise ReviewError('GO_OWNER_MOBILE_SOURCE_MISSING: '+item['path'])
        meta = path.stat() if before is not None else None
        result.append({'path':item['path'], 'before':before, 'after':after, 'meta':meta})
    return result

def atomic_write(path, content, mode, uid, gid):
    descriptor, temporary = tempfile.mkstemp(prefix='.go-owner-mobile-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
            info = os.fstat(stream.fileno())
            if (info.st_uid, info.st_gid) != (uid, gid):
                os.fchown(stream.fileno(), uid, gid)
            os.fchmod(stream.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def apply_plan(project, changes, backup, owner, verify):
    backup.mkdir(mode=0o700)
    applied = []
    receipts = []
    for change in changes:
        saved = backup / change['path']
        if change['before'] is not None:
            saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            saved.write_bytes(change['before'])
            saved.chmod(0o600)
        receipts.append({'path':change['path'], 'before':digest(change['before']) if change['before'] is not None else None,
            'after':digest(change['after'])})
    (backup / 'receipt.json').write_text(json.dumps(receipts, indent=2)+'\n')
    try:
        for change in changes:
            path = target_path(project, change['path'])
            current = path.read_bytes() if path.exists() else None
            if current != change['before']:
                raise ReviewError('GO_OWNER_MOBILE_CONCURRENT_SOURCE_CHANGE')
            if current == change['after']:
                continue
            info = change['meta']
            atomic_write(path, change['after'], stat.S_IMODE(info.st_mode) if info else 0o644,
                info.st_uid if info else owner[0], info.st_gid if info else owner[1])
            applied.append(change)
        verify()
    except BaseException:
        conflict = False
        for change in reversed(applied):
            path = target_path(project, change['path'])
            if not path.exists() or path.read_bytes() != change['after']:
                conflict = True
                continue
            if change['before'] is None:
                path.unlink()
            else:
                info = change['meta']
                atomic_write(path, change['before'], stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
        if conflict:
            raise ReviewError('GO_OWNER_MOBILE_ROLLBACK_CONFLICT')
        raise

PROBE = r'''<?php
ini_set('display_errors','0'); ini_set('log_errors','0');
try {
    chdir($argv[1]); require $argv[1].'/vendor/autoload.php';
    $app=require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $route=app('router')->getRoutes()->getByName('owner.mobile.update');
    if (!$route || $route->uri()!=='admin/owner/mobile' || $route->methods()!==['POST']
        || $route->getActionName()!=='App\\Http\\Controllers\\Dashboard\\OwnerMobileController@__invoke'
        || !in_array('IsAdmin',$route->gatherMiddleware(),true) || !in_array('web',$route->gatherMiddleware(),true)) {
        throw new RuntimeException('GO_OWNER_MOBILE_ROUTE_CHECK_FAILED');
    }
    foreach (['01012345678','+201012345678','٠١٠١٢٣٤٥٦٧٨'] as $phone) {
        if (\App\Services\Dashboard\DashboardLoginIdentity::mobile($phone)!=='1012345678') {
            throw new RuntimeException('GO_OWNER_MOBILE_NORMALIZATION_CHECK_FAILED');
        }
    }
    app(\App\Services\Dashboard\OwnerMobile::class);
    foreach (['form','edit'] as $view) {
        app('blade.compiler')->compileString(file_get_contents($argv[1].'/resources/views/admin/users/'.$view.'.blade.php'));
    }
    if (($argv[2]??'')==='owner-account-fix-20261101') {
        $owner=\Illuminate\Support\Facades\DB::table('users')->where('id',1)->first(['id','account_type','mobile']);
        if (!$owner || !\App\Services\Dashboard\DashboardAccountRole::isOwner($owner)) {
            throw new RuntimeException('GO_OWNER_ACCOUNT_ROLE_CHECK_FAILED');
        }
        foreach (['index','show'] as $view) {
            app('blade.compiler')->compileString(file_get_contents($argv[1].'/resources/views/admin/users/'.$view.'.blade.php'));
        }
        echo 'GO_OWNER_ACCOUNT_MOBILE_PRESENT='.(\App\Services\Dashboard\DashboardLoginIdentity::mobile((string)$owner->mobile)!==null?'1':'0')."\n";
    }
    echo "GO_OWNER_MOBILE_RUNTIME_READY\n";
} catch (Throwable $error) {
    $code=preg_match('/^GO_OWNER_MOBILE_[A-Z_]+$/D',$error->getMessage())?$error->getMessage():'GO_OWNER_MOBILE_RUNTIME_CHECK_FAILED';
    fwrite(STDERR,$code."\n"); exit(1);
}
'''

def run(command, cwd):
    completed = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=60)
    if completed.returncode:
        raise ReviewError('GO_OWNER_MOBILE_RUNTIME_COMMAND_FAILED')
    return completed.stdout

def main():
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise ReviewError('GO_OWNER_MOBILE_SERVER_REQUIRED')
    if len(sys.argv) != 3 or sys.argv[2] != '--apply':
        raise ReviewError('GO_OWNER_MOBILE_OPERATOR_ARGUMENTS_REQUIRED')
    project = Path('/home/fasakha/public_html')
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    if project.resolve() != project or not (project/'vendor/autoload.php').is_file():
        raise ReviewError('GO_OWNER_MOBILE_APPLICATION_REQUIRED')
    account = pwd.getpwnam('fasakha')
    manifest = json.loads(Path(sys.argv[1]).read_text())
    backup_root = Path('/home/fasakha/go-owner-mobile-fix-backups')
    if backup_root.is_symlink() or backup_root.resolve() != backup_root:
        raise ReviewError('GO_OWNER_MOBILE_BACKUP_PATH_CHANGED')
    backup_root.mkdir(exist_ok=True, mode=0o700)
    with open('/var/lock/go-owner-mobile-fix.lock','a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        changes = plan(project, manifest)
        with tempfile.TemporaryDirectory(prefix='go-owner-mobile-check-', dir='/var/tmp') as temporary:
            stage = Path(temporary)
            stage.chmod(0o755)
            for index, change in enumerate(changes):
                candidate = stage/(str(index)+'.php')
                candidate.write_bytes(change['after'])
                run([php,'-l',str(candidate)], project)
            probe = stage/'probe.php'; probe.write_text(PROBE); probe.chmod(0o644)
            proof = {}
            def verify():
                run(['runuser','-u','fasakha','--',php,'artisan','route:clear'], project)
                run(['runuser','-u','fasakha','--',php,'artisan','view:clear'], project)
                output = run(['runuser','-u','fasakha','--',php,'-d','display_errors=0','-d','log_errors=0',str(probe),str(project),manifest['feature']], project)
                if 'GO_OWNER_MOBILE_RUNTIME_READY' not in output.splitlines():
                    raise ReviewError('GO_OWNER_MOBILE_RUNTIME_PROOF_MISSING')
                if manifest['feature'] == 'owner-account-fix-20261101':
                    if not any(line in output.splitlines() for line in ['GO_OWNER_ACCOUNT_MOBILE_PRESENT=0','GO_OWNER_ACCOUNT_MOBILE_PRESENT=1']):
                        raise ReviewError('GO_OWNER_MOBILE_RUNTIME_PROOF_MISSING')
                    proof['owner_mobile_present'] = 'GO_OWNER_ACCOUNT_MOBILE_PRESENT=1' in output.splitlines()
            backup = backup_root/(datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+os.urandom(4).hex())
            try:
                apply_plan(project, changes, backup, (account.pw_uid,account.pw_gid), verify)
            except BaseException:
                # Old routes/views must be reloaded after a successful file rollback.
                subprocess.run(['runuser','-u','fasakha','--',php,'artisan','route:clear'],cwd=project,capture_output=True,timeout=60)
                subprocess.run(['runuser','-u','fasakha','--',php,'artisan','view:clear'],cwd=project,capture_output=True,timeout=60)
                raise
    status = 'GO_OWNER_ACCOUNT_FIX_READY' if manifest['feature'] == 'owner-account-fix-20261101' else 'GO_OWNER_MOBILE_FIX_READY'
    receipt = {'status':status,'phone_form_ready':True,'phone_login_ready':True,
        'password_change_required':False,'updated_files':sum(item['before']!=item['after'] for item in changes)}
    if manifest['feature'] == 'owner-account-fix-20261101':
        receipt.update(proof)
        receipt['account_role'] = 'owner'
        receipt['phone_layout_ready'] = True
    print(json.dumps(receipt))

if __name__ == '__main__':
    try:
        main()
    except ReviewError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except BaseException:
        print('GO_OWNER_MOBILE_DEPLOYMENT_REQUIRES_REVIEW', file=sys.stderr)
        sys.exit(1)
