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

FEATURE = 'hologram-accounts-20261101-v6'
BASE_REVISION = '39e47892c7bee9b440721992a37b13f796225214'
# This exact list is finalized alongside the generated, pinned manifest.
ALLOWED = {
    'app/Http/Controllers/Dashboard/HomeController.php',
    'app/Providers/AppServiceProvider.php',
    'app/Services/Dashboard/HomeHologramMetrics.php',
    'app/Services/Dashboard/HomeHudLinks.php',
    'app/Services/Dashboard/HomeHologramVoice.php',
    'app/Http/Controllers/Dashboard/HomeHologramVoiceController.php',
    'app/Observers/HomeHologramVoiceObserver.php',
    'app/Console/Commands/PrewarmHomeHologramVoice.php',
    'config/home_hologram_voice.php',
    'routes/hologram_voice.php',
    'public/dashboard/js/home-hud.js',
    'public/dashboard/js/home-overview.js',
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
            approved_upgrade = (relative == 'routes/hologram_voice.php' and before is not None
                and digest(before) == entry.get('upgrade_from_sha256'))
            if entry.get('before_sha256') is not None or before is not None and before != after and not approved_upgrade:
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


PROJECT=Path('/home/fasakha/public_html')
TENANTS=Path('/home/fasakha/go-owner-dashboards')
LIB=Path('/usr/local/lib/go-hologram-voice')
SETTINGS=Path('/etc/go-hologram-voice')
CONFIG=SETTINGS/'config.json'
CAPACITY=SETTINGS/'capacity.json'
STATE=Path('/var/lib/go-hologram-voice')
RUNTIME=LIB/'runtime'
PHP='/opt/cpanel/ea-php82/root/usr/bin/php'
SERVICE=Path('/etc/systemd/system/go-hologram-account-voice.service')
TIMER=Path('/etc/systemd/system/go-hologram-account-voice.timer')
WORKER_PATHS={'worker.py','bootstrap_runtime.py','assets.json','host_probe.py',
    'assets/reference.wav','assets/common-tail.wav','assets/office-admin.mp3',
    'assets/office-admin.envelope.json','assets/omar.mp3','assets/omar.envelope.json'}
SAFE_ENV={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C','LC_ALL':'C','PYTHONNOUSERSITE':'1'}


def command(argv,timeout=90,allow_failure=False):
    result=subprocess.run(argv,cwd=PROJECT,capture_output=True,text=True,timeout=timeout,env=SAFE_ENV)
    if result.returncode and not allow_failure:
        raise ReviewError('GO_ACCOUNT_RUNTIME_COMMAND_FAILED')
    return result


def regular(path,maximum=1048576):
    if path.is_symlink() or not path.is_file():raise ReviewError('GO_ACCOUNT_UNSAFE_FILE')
    info=path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink!=1 or info.st_size>maximum:
        raise ReviewError('GO_ACCOUNT_UNSAFE_FILE')
    return path.read_bytes()


def clean_parents(path):
    for parent in [path,*path.parents]:
        if parent.is_symlink():raise ReviewError('GO_ACCOUNT_SYMLINK_REFUSED')
        if parent.exists() and not parent.is_dir():raise ReviewError('GO_ACCOUNT_DIRECTORY_CHANGED')


def directory(path,uid,gid,mode):
    clean_parents(path)
    if not path.exists():
        if not path.parent.is_dir():raise ReviewError('GO_ACCOUNT_PARENT_MISSING')
        path.mkdir(mode=mode);os.chown(path,uid,gid);os.chmod(path,mode)
    info=path.stat()
    if info.st_uid!=uid or info.st_gid!=gid or stat.S_IMODE(info.st_mode)!=mode:
        raise ReviewError('GO_ACCOUNT_DIRECTORY_PERMISSIONS')


def contexts(project=PROJECT,tenants=TENANTS):
    result=[{'scope':'central','base':project,'runtime':None,
             'spool_root':project/'storage/app/go-hologram-voice'}]
    root=tenants/'tenants'
    if not root.exists():return result
    clean_parents(root)
    for child in sorted(root.iterdir()):
        if not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}',child.name):
            raise ReviewError('GO_ACCOUNT_TENANT_PATH_CHANGED')
        clean_parents(child)
        file=child/'runtime.json'
        if not file.exists():continue
        info=file.stat();facts=json.loads(regular(file,65536))
        if facts.get('state')!='active':continue
        slug=facts.get('slug','')
        if info.st_uid!=0 or info.st_mode&0o022 or facts.get('format')!=1 or facts.get('id')!=child.name \
            or facts.get('base')!=str(child) or facts.get('prefix')!='' or not re.fullmatch(r'[0-9a-f]{16}',slug):
            raise ReviewError('GO_ACCOUNT_TENANT_RUNTIME_CHANGED')
        public=project/'public/dashboards'/slug
        if facts.get('public')!=str(public) or public.resolve()!=public or not public.is_dir():
            raise ReviewError('GO_ACCOUNT_TENANT_PUBLIC_CHANGED')
        if facts.get('origin')!='https://'+facts.get('host','') or not re.fullmatch('go-'+slug+r'\.[a-z0-9.-]+',facts.get('host','')):
            raise ReviewError('GO_ACCOUNT_TENANT_ORIGIN_CHANGED')
        if regular(tenants/'control/deny'/child.name,64)!=b'':continue
        config=child/'bootstrap/cache/config.php'
        if digest(regular(config,8388608))!=facts.get('config_sha256'):
            raise ReviewError('GO_ACCOUNT_TENANT_CONFIG_CHANGED')
        result.append({'scope':'tenant:'+child.name,'base':child,'runtime':file,
                       'spool_root':child/'storage/app/go-hologram-voice'})
    return result


def probe_module():
    source=Path(__file__).with_name('host_probe.py')
    spec=importlib.util.spec_from_file_location('account_voice_host_probe',source)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    return module


def worker_plan(manifest):
    entries=manifest.get('worker_files',[])
    if len(entries)!=len(WORKER_PATHS) or {entry.get('path') for entry in entries}!=WORKER_PATHS:
        raise ReviewError('GO_ACCOUNT_WORKER_PATHS_CHANGED')
    prepared=[]
    for entry in entries:
        content=payload(entry);relative=entry['path'];target=LIB/relative
        clean_parents(target.parent)
        if target.is_symlink():raise ReviewError('GO_ACCOUNT_WORKER_SYMLINK_REFUSED')
        before=regular(target,8388608) if target.exists() else None
        if before is not None and (target.stat().st_uid!=0 or target.stat().st_mode&0o022):
            raise ReviewError('GO_ACCOUNT_UNTRUSTED_WORKER_SOURCE')
        if before is not None and before!=content:
            # This first speech package must not overwrite a different service.
            raise ReviewError('GO_ACCOUNT_WORKER_SOURCE_CHANGED: '+relative)
        prepared.append({'path':relative,'before':before,'after':content})
    return prepared


def install_worker(entries,account):
    directory(LIB,0,account.pw_gid,0o750)
    directory(LIB/'assets',0,account.pw_gid,0o750)
    for entry in entries:
        target=LIB/entry['path']
        if target.is_symlink():raise ReviewError('GO_ACCOUNT_WORKER_SYMLINK_REFUSED')
        current=regular(target,8388608) if target.exists() else None
        if current!=entry['before']:raise ReviewError('GO_ACCOUNT_CONCURRENT_WORKER_CHANGE')
        if current!=entry['after']:atomic_write(target,entry['after'],0o640,0,account.pw_gid)
    # The scheduler runs this exact already-verified installer, never a moving ref.
    source=Path(__file__).read_bytes()
    if (LIB/'install_root.py').is_symlink():raise ReviewError('GO_ACCOUNT_WORKER_SYMLINK_REFUSED')
    atomic_write(LIB/'install_root.py',source,0o640,0,account.pw_gid)
    directory(SETTINGS,0,account.pw_gid,0o750)
    directory(STATE,account.pw_uid,account.pw_gid,0o700)
    directory(RUNTIME,account.pw_uid,account.pw_gid,0o700)


def configure(scopes,account,report,enable_generation):
    assessment=probe_module().assessment(report)
    for scope in scopes:
        root=scope['spool_root'];directory(root,account.pw_uid,account.pw_gid,0o700)
        for child in ['queue','processing','ready','dead']:
            directory(root/child,account.pw_uid,account.pw_gid,0o700)
    manifest_sha=digest(regular(LIB/'assets.json',262144))
    receipt={'safe':bool(assessment['safe'] and enable_generation),'runtime_root':str(RUNTIME),
             'model_manifest_sha256':manifest_sha,'checked_at':int(time.time())}
    config={'version':1,'runtime_root':str(RUNTIME),'assets_root':str(LIB),
        'state_root':str(STATE),'capacity_receipt':str(CAPACITY),'generation_enabled':bool(enable_generation),
        'ffmpeg_path':'/usr/bin/ffmpeg','threads':2,
        'min_available_memory_bytes':assessment['min_available_memory_bytes'],
        'runtimes':[{'scope':scope['scope'],'spool_root':str(scope['spool_root'])} for scope in scopes]}
    for target,value in [(CAPACITY,receipt),(CONFIG,config)]:
        atomic_write(target,(json.dumps(value,separators=(',',':'))+'\n').encode(),0o640,0,account.pw_gid)
    return assessment


def user_python(script,args,timeout=90,allow_failure=False):
    # -s preserves trusted sibling imports; the fixed env excludes PYTHONPATH.
    return command(['runuser','-u','fasakha','--','/usr/bin/python3','-s','-B',str(script),*args],timeout,allow_failure)


PHP_HELPER=r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');
try {
    $root='/home/fasakha/public_html';require $root.'/vendor/autoload.php';
    if($argv[1]==='-'){$base=$root;chdir($base);$app=require $root.'/bootstrap/app.php';}
    else{$runtime=\App\Services\OwnerDashboards\Bootstrap::read($argv[1]);$base=$runtime['base'];$app=\App\Services\OwnerDashboards\Bootstrap::application($runtime);chdir($base);}
    $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    if(realpath(dirname($app->getCachedRoutesPath()))!==$base.'/bootstrap/cache'
        ||is_link(config('view.compiled'))||realpath(config('view.compiled'))!==$base.'/storage/framework/views')throw new RuntimeException('CACHE_SCOPE');
    $quiet=new \Symfony\Component\Console\Output\NullOutput;
    if($argv[2]==='clear'){
        if($kernel->call('route:clear',[],$quiet)!==0||$kernel->call('view:clear',[],$quiet)!==0)throw new RuntimeException('CLEAR');
        echo json_encode(['ok'=>true]).PHP_EOL;exit;
    }
    foreach(['status','audio'] as $action){
        $name='dashboard.hologram-voice.'.$action;
        $matches=[];foreach(app('router')->getRoutes() as $candidate)if($candidate->getName()===$name)$matches[]=$candidate;
        if(count($matches)!==1)throw new RuntimeException('ROUTE_COUNT');
        $route=$matches[0];
        if($route->uri()!=='admin/dashboard/hologram-voice/{ticket}/'.$action
            ||array_diff(['web','lang','IsAdmin'],$route->gatherMiddleware()))throw new RuntimeException('ROUTE');
    }
    $output=new \Symfony\Component\Console\Output\BufferedOutput;
    $code=$kernel->call('hologram-voice:prewarm',[],$output);
    $lines=preg_split('/\R/',trim($output->fetch()));$data=json_decode(end($lines),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new RuntimeException('PREWARM');
    $counts=['ready'=>0,'queued'=>0,'unavailable'=>0];
    foreach($counts as $key=>$_){if(!isset($data[$key])||!is_int($data[$key])||$data[$key]<0)throw new RuntimeException('COUNTS');$counts[$key]=$data[$key];}
    echo json_encode(['ok'=>true,'exit'=>$code,'counts'=>$counts,'inventory_written'=>($data['inventory_written']??false)===true]).PHP_EOL;
} catch(Throwable $error){fwrite(STDERR,"GO_ACCOUNT_PHP_RUNTIME_CHECK_FAILED\n");exit(1);}
'''


def php_context(helper,scope,mode):
    result=command(['runuser','-u','fasakha','--',PHP,'-d','display_errors=0','-d','log_errors=0',str(helper),
        str(scope['runtime']) if scope['runtime'] else '-',mode])
    try:return json.loads(result.stdout)
    except ValueError:raise ReviewError('GO_ACCOUNT_PHP_PROOF_MISSING')


def route_snapshots(scopes,backup):
    records=[]
    for index,scope in enumerate(scopes):
        for name in ['routes.php','routes-v6.php','routes-v7.php']:
            path=scope['base']/'bootstrap/cache'/name
            if path.exists():
                before=regular(path,16777216);info=path.stat()
                saved=backup/'route-caches'/str(index)/name;saved.parent.mkdir(mode=0o700,parents=True,exist_ok=True);saved.write_bytes(before);saved.chmod(0o600)
                records.append((path,before,info))
    return records


def restore_routes(records):
    conflict=False
    for path,before,info in records:
        if path.is_symlink():conflict=True;continue
        if path.exists() and path.read_bytes()!=before:conflict=True;continue
        if not path.exists():atomic_write(path,before,stat.S_IMODE(info.st_mode),info.st_uid,info.st_gid)
    if conflict:raise ReviewError('GO_ACCOUNT_ROUTE_CACHE_ROLLBACK_CONFLICT')


def install_timer(account):
    unit='''[Unit]
Description=Bounded private GO account greeting worker
After=local-fs.target
[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/usr/bin/python3 -I -B /usr/local/lib/go-hologram-voice/install_root.py --worker-tick
TimeoutStartSec=600
Nice=10
CPUWeight=10
CPUQuota=100%
MemoryMax=6G
OOMScoreAdjust=500
IOSchedulingClass=idle
PrivateTmp=true
PrivateNetwork=true
NoNewPrivileges=true
'''
    timer='''[Unit]
Description=Process queued private GO account greetings
[Timer]
OnBootSec=30s
OnUnitInactiveSec=15s
AccuracySec=2s
Unit=go-hologram-account-voice.service
[Install]
WantedBy=timers.target
'''
    for target,content in [(SERVICE,unit),(TIMER,timer)]:
        if target.is_symlink():raise ReviewError('GO_ACCOUNT_SERVICE_PATH_CHANGED')
        if target.exists() and target.read_bytes()!=content.encode():
            raise ReviewError('GO_ACCOUNT_EXISTING_SERVICE_UNREVIEWED')
        atomic_write(target,content.encode(),0o644,0,0)
    command(['systemctl','daemon-reload']);command(['systemctl','enable','--now',TIMER.name])
    return command(['systemctl','is-active',TIMER.name],allow_failure=True).stdout.strip()=='active'


def worker_tick():
    account=pwd.getpwnam('fasakha');scopes=contexts();module=probe_module();report=module.collect()
    config=json.loads(regular(CONFIG,65536));allowed=config.get('generation_enabled') is True
    configure(scopes,account,report,allowed)
    result=user_python(LIB/'worker.py',['--config',str(CONFIG),'--max-jobs','1'],timeout=540,allow_failure=True)
    try:output=json.loads(result.stdout)
    except ValueError:output={'ok':False,'reason':'worker_failed'}
    print(json.dumps({'status':'GO_ACCOUNT_VOICE_TICK','worker_ok':result.returncode==0 and output.get('ok') is True,
        'published':output.get('published',0),'paused':output.get('paused',0),'dead':output.get('dead',0)}))


def voice_status():
    scopes=contexts()
    with tempfile.TemporaryDirectory(prefix='go-account-status-',dir='/var/tmp') as temporary:
        stage=Path(temporary);stage.chmod(0o755)
        helper=stage/'runtime.php';helper.write_text(PHP_HELPER);helper.chmod(0o644)
        results=[php_context(helper,scope,'prewarm') for scope in scopes]
    counts={key:sum(result['counts'][key] for result in results) for key in ['ready','queued','unavailable']}
    ready=all(result.get('exit')==0 and result.get('inventory_written') is True for result in results) \
        and counts['ready']>0 and counts['queued']==0 and counts['unavailable']==0
    config=json.loads(regular(CONFIG,65536));receipt=json.loads(regular(CAPACITY,16384))
    generation=config.get('generation_enabled') is True and receipt.get('safe') is True \
        and type(receipt.get('checked_at')) is int and -300<=time.time()-receipt['checked_at']<=86400
    timer=command(['systemctl','is-active',TIMER.name],allow_failure=True).stdout.strip()
    print(json.dumps({'status':'GO_HOLOGRAM_VOICE_READY' if ready and generation and timer=='active' else 'GO_HOLOGRAM_VOICE_PENDING',
        'voice_counts':counts,'worker_timer':timer or 'unknown','trusted_runtimes':len(scopes),
        'all_existing_names_ready':ready,'generation_enabled':generation,
        'private_inventories':'storage/app/go-hologram-voice/prewarm-inventory.json per runtime',
        'download_performed':False,'source_changed':False}))


def main():
    os.umask(0o077)
    if os.geteuid()!=0 or socket.gethostname().split('.')[0]!='srv1836524':raise ReviewError('GO_ACCOUNT_ROOT_HOST_REQUIRED')
    if sys.argv[1:]==['--worker-tick']:
        worker_tick();return
    if sys.argv[1:]==['--voice-status']:
        voice_status();return
    if len(sys.argv)!=3 or sys.argv[2] not in {'--check','--apply'}:raise ReviewError('GO_ACCOUNT_ARGUMENTS_REQUIRED')
    if PROJECT.resolve()!=PROJECT or not (PROJECT/'vendor/autoload.php').is_file():raise ReviewError('GO_ACCOUNT_APP_REQUIRED')
    account=pwd.getpwnam('fasakha');manifest=json.loads(regular(Path(sys.argv[1]),16777216))
    changes=plan(PROJECT,manifest);worker=worker_plan(manifest);scopes=contexts()
    report=probe_module().collect();capacity=probe_module().assessment(report)
    if sys.argv[2]=='--check':
        print(json.dumps({'status':'GO_ACCOUNT_PREFLIGHT','application_files':len(changes),'trusted_runtimes':len(scopes),
            'generation':capacity,'host':report}));return
    backup_root=Path('/root/go-hologram-account-backups');directory(backup_root,0,0,0o700)
    backup=backup_root/(datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+os.urandom(4).hex())
    with open('/var/lock/go-hologram-account-install.lock','a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='go-account-check-',dir='/var/tmp') as temporary:
            stage=Path(temporary);stage.chmod(0o755)
            helper=stage/'runtime.php';helper.write_text(PHP_HELPER);helper.chmod(0o644)
            for index,change in enumerate(changes):
                if change['path'].endswith('.php'):
                    candidate=stage/(str(index)+'.php');candidate.write_bytes(change['after']);candidate.chmod(0o644)
                    command([PHP,'-l',str(candidate)])
            # Stage/verify the small worker before enabling any source reference.
            install_worker(worker,account)
            assets_check=user_python(LIB/'bootstrap_runtime.py',['--assets-root',str(LIB),'--check'])
            if json.loads(assets_check.stdout).get('ok') is not True:raise ReviewError('GO_ACCOUNT_ASSETS_NOT_READY')
            configure(scopes,account,report,False)
            first_counts=[];route_records=[]
            def verify():
                nonlocal route_records,first_counts
                route_records=route_snapshots(scopes,backup)
                for scope in scopes:php_context(helper,scope,'clear')
                first_counts=[php_context(helper,scope,'prewarm') for scope in scopes]
                for change in changes:
                    if checked_path(PROJECT,change['path']).read_bytes()!=change['after']:raise ReviewError('GO_ACCOUNT_INSTALLED_HASH_MISMATCH')
            try:apply_plan(PROJECT,changes,backup,(account.pw_uid,account.pw_gid),verify)
            except BaseException:
                restore_routes(route_records)
                raise
            # Capacity/runtime failure is explicit partial readiness, not app rollback
            # and never permission to substitute a generic/system voice.
            generation=False;runtime_reason=None
            if capacity['safe']:
                installed=user_python(LIB/'bootstrap_runtime.py',['--runtime-root',str(RUNTIME),'--install'],timeout=1200,allow_failure=True)
                generation=installed.returncode==0
                if not generation:runtime_reason='MODEL_RUNTIME_UNAVAILABLE'
            else:runtime_reason='GENERATION_CAPACITY_OR_ABI_REFUSED'
            configure(scopes,account,report,generation)
            worker_result=user_python(LIB/'worker.py',['--config',str(CONFIG),'--max-jobs','10'],timeout=540,allow_failure=True)
            try:worker_proof=json.loads(worker_result.stdout)
            except ValueError:worker_proof={'ok':False}
            worker_ready=worker_result.returncode==0 and worker_proof.get('ok') is True
            final_counts=[php_context(helper,scope,'prewarm') for scope in scopes]
            counts={key:sum(result['counts'][key] for result in final_counts) for key in ['ready','queued','unavailable']}
            all_voices=worker_ready and all(result.get('exit')==0 and result.get('inventory_written') is True for result in final_counts) \
                and counts['queued']==0 and counts['unavailable']==0 and counts['ready']>0
            timer_ready=False
            try:timer_ready=install_timer(account)
            except (ReviewError,OSError,subprocess.TimeoutExpired):runtime_reason=runtime_reason or 'WORKER_TIMER_NOT_READY'
            ready=all_voices and timer_ready and generation and capacity['safe']
    print(json.dumps({'status':'GO_HOLOGRAM_ACCOUNTS_READY' if ready else 'GO_HOLOGRAM_ACCOUNTS_PARTIAL',
        'feature':FEATURE,'source_installed':True,'owner_balance_ready':True,'named_voice_ready':ready,
        'all_existing_names_ready':all_voices,
        'voice_counts':counts,'generation_enabled':generation,'generation_capacity':capacity,'reason':runtime_reason,
        'worker_ready':worker_ready,'worker_timer_ready':timer_ready,'trusted_runtimes':len(scopes),
        'private_inventories':'storage/app/go-hologram-voice/prewarm-inventory.json per runtime',
        'backup':str(backup),'database_schema_changed':False,'desktop_rebuilt':False}))


if __name__=='__main__':
    try:main()
    except ReviewError as error:print(str(error),file=sys.stderr);sys.exit(1)
    except BaseException:print('GO_HOLOGRAM_ACCOUNT_INSTALL_REQUIRES_REVIEW',file=sys.stderr);sys.exit(1)


