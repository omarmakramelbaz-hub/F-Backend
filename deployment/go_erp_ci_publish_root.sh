#!/usr/bin/env bash
# Reviewed console entry for one accepted CI artifact. This does not use the
# existing deploy-only SSH identity or change the live checkout's HEAD.
set -euo pipefail
exec /usr/bin/python3 -I -B - "$0" "$@" <<'PY'
import argparse,fcntl,hashlib,json,os,pathlib,pwd,re,shutil,socket,stat,subprocess,sys,tempfile

ROOT=pathlib.Path('/home/fasakha/public_html')
BRANCH='refs/heads/codex/go-erp-minute-updates-20261020'
PHP='/opt/cpanel/ea-php82/root/usr/bin/php'
SELF='deployment/go_erp_ci_publish_root.sh'
LAUNCHER='deployment/apply_desktop_dashboard_server_root.sh'
PUBLISHER='deployment/publish_go_erp_update.py'
MANIFEST='deployment/desktop_dashboard_server_manifest.json'
PHP_CHECK='deployment/desktop_dashboard_release.php'
FILES=(SELF,LAUNCHER,PUBLISHER,MANIFEST,PHP_CHECK,
       'deployment/desktop_dashboard_server_preflight.py',
       'deployment/diagnose_desktop_dashboard_current_source.py','desktop-pos/package.json')
REV=re.compile('[a-f0-9]{40}\\Z')
ENV={'PATH':'/usr/bin:/bin','HOME':'/home/fasakha','GIT_OPTIONAL_LOCKS':'0',
     'GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1'}

class Stop(Exception):pass
def need(ok,reason):
    if not ok:raise Stop('GO_ERP_CI_'+reason)
def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def identity(value):return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
def private_directory(path):
    need(path.is_absolute() and path.resolve()==path,'STAGE_PATH_UNSAFE')
    need(re.fullmatch(r'/var/tmp/go-erp-ci-[A-Za-z0-9]{8,32}',str(path)) is not None,'STAGE_PATH_UNSAFE')
    item=path.lstat();need(stat.S_ISDIR(item.st_mode) and item.st_uid==0 and stat.S_IMODE(item.st_mode)==0o700,'STAGE_OWNERSHIP_UNSAFE')
    for parent in path.parents:
        item=parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid==0 and
             (not item.st_mode&0o022 or str(parent)=='/var/tmp' and item.st_mode&stat.S_ISVTX),'STAGE_ANCESTOR_UNSAFE')
def read_regular(path,limit):
    item=path.lstat();need(stat.S_ISREG(item.st_mode) and item.st_uid==0 and item.st_nlink==1 and not item.st_mode&0o022 and 0<item.st_size<=limit,'INPUT_UNSAFE')
    descriptor=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        need(identity(os.fstat(descriptor))==identity(item),'INPUT_CHANGED')
        data=bytearray()
        while len(data)<=limit:
            part=os.read(descriptor,min(65536,limit+1-len(data)))
            if not part:break
            data.extend(part)
        need(len(data)==item.st_size and identity(item)==identity(os.fstat(descriptor))==identity(path.lstat()),'INPUT_CHANGED')
        return bytes(data)
    finally:os.close(descriptor)
def run(arguments,timeout=120):
    try:value=subprocess.run(arguments,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout,env=ENV)
    except (OSError,subprocess.TimeoutExpired):raise Stop('GO_ERP_CI_CHILD_UNAVAILABLE_OR_TIMED_OUT') from None
    need(len(value.stdout)<=8*1024**2 and len(value.stderr)<=65536,'CHILD_OUTPUT_LIMIT')
    return value
def git(*arguments):
    result=run(['/usr/sbin/runuser','-u','fasakha','--','/usr/bin/env','-i',*[key+'='+value for key,value in ENV.items()],
                '/usr/bin/git','--no-optional-locks','-c','core.fsmonitor=false','-C',str(ROOT),*arguments])
    need(result.returncode==0,'REVIEWED_GIT_READ_FAILED')
    return result.stdout
def latest(release):
    git('fetch','--quiet','--no-tags','--no-prune','--no-recurse-submodules','--refmap=','origin',BRANCH)
    actual=git('rev-parse','FETCH_HEAD').decode('ascii').strip()
    need(REV.fullmatch(actual) is not None,'REMOTE_REF_INVALID')
    return actual==release
def source_file(release,name):
    need(name in FILES,'SCRIPT_SCOPE_INVALID')
    row=git('ls-tree','-z',release,name)
    match=re.fullmatch(rb'100(?:644|755) blob ([a-f0-9]{40})\t'+re.escape(name.encode())+rb'\0',row)
    need(match is not None,'REVIEWED_FILE_NOT_REGULAR')
    data=git('show',release+':'+name)
    need(0<len(data)<=2*1024**2 and blob(data)==match[1].decode(),'REVIEWED_BLOB_MISMATCH')
    return data
def stage_release(release,folder,own_file):
    payload={name:source_file(release,name) for name in FILES}
    need(read_regular(own_file,2*1024**2)==payload[SELF],'ENTRYPOINT_DIFFERS_FROM_REVIEWED_COMMIT')
    for name,data in payload.items():
        target=folder/pathlib.PurePosixPath(name).name
        descriptor=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(descriptor,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
    return payload
def publication_module(folder):
    file=folder/pathlib.PurePosixPath(PUBLISHER).name
    namespace={'__name__':'reviewed_go_erp_publication','__file__':str(file)}
    exec(compile(read_regular(file,2*1024**2),str(file),'exec'),namespace)
    return namespace
def validate_artifact(module,receipt_file,installer_file,revision,run_id,version,manifest):
    receipt=module['read_json'](receipt_file,root_owned=True)
    release=module['validate_receipt'](receipt)
    need(release['source_revision']==revision and release['sequence']==run_id and release['version']==version,'ARTIFACT_RUN_SOURCE_OR_VERSION_MISMATCH')
    need(manifest.get('after_source')==release['source'],'SERVER_MANIFEST_AND_TESTED_SOURCE_DIFFER')
    module['verify_installer'](installer_file,release['installer'],root_owned=True)
    return release
def current_matches(module,release):
    before=module['source_snapshot'](ROOT);after=module['source_snapshot'](ROOT)
    need(before==after,'SERVER_SOURCE_CHANGED_DURING_MEASUREMENT')
    return after['fingerprint']==release['source'] and module['snapshot_gui'](after)==release['gui']
def activate(folder,revision,release):
    value=run(['/usr/bin/bash',str(folder/pathlib.PurePosixPath(LAUNCHER).name),'--release',revision],timeout=3900)
    if value.returncode:
        # Retain only the installer's fixed, nonprivate failure identifiers.
        for line in value.stderr.decode('ascii','ignore').splitlines():
            if re.fullmatch(r'DESKTOP_[A-Z_]+(?:; STAGE=[A-Z_]+)?',line):print(line,file=sys.stderr)
        raise Stop('GO_ERP_CI_REVIEWED_ACTIVATION_FAILED')
    rows=value.stdout.decode('ascii').splitlines()
    need(len(rows)==3 and rows[0]=='DESKTOP_DASHBOARD_SERVER_READY' and
         re.fullmatch(r'BACKUP=/home/fasakha/desktop-dashboard-release-backups/server-[A-Za-z0-9_-]{6,32}',rows[1]) and
         rows[2]=='SOURCE='+release['source']['sha256'],'ACTIVATION_RECEIPT_INVALID')
def postflight(folder,release,uid,gid):
    private=folder/'postflight';private.mkdir(mode=0o700);os.chown(private,uid,gid)
    # Only the temporary check directory and reviewed readers become accessible
    # to fasakha; no retained source ownership or modes are changed.
    folder.chmod(0o755)
    for name in (PHP_CHECK,MANIFEST):(folder/pathlib.PurePosixPath(name).name).chmod(0o644)
    prefix=['/usr/sbin/runuser','-u','fasakha','--','/usr/bin/env','-i',*[key+'='+value for key,value in ENV.items()],PHP]
    checked=run(prefix+[str(folder/pathlib.PurePosixPath(PHP_CHECK).name),'verify',str(ROOT),str(private),str(folder/pathlib.PurePosixPath(MANIFEST).name)],timeout=180)
    need(checked.returncode==0,'SERVER_POSTFLIGHT_FAILED')
    need(json.loads(checked.stdout)=={'source':release['source'],'authentication_checked':4,'enabled':True,'local':False},'SERVER_POSTFLIGHT_RECEIPT_INVALID')
    code=r'''ini_set('display_errors','0');ini_set('log_errors','0');ob_start();try{
      foreach(['APP_SERVICES_CACHE'=>'update-services.php','APP_PACKAGES_CACHE'=>'update-packages.php']as$key=>$name){putenv($key.'='.$argv[2].'/'.$name);$_ENV[$key]=$argv[2].'/'.$name;$_SERVER[$key]=$argv[2].'/'.$name;}
      require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$http=$app->make(Illuminate\Contracts\Http\Kernel::class);
      foreach(['/api/desktop-dashboard/update','/api/desktop-dashboard/update/installer/'.str_repeat('0',64)]as$path){$request=Illuminate\Http\Request::create($path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);$response=$http->handle($request);$http->terminate($request,$response);if($response->getStatusCode()!==401)throw new RuntimeException();}
      while(ob_get_level()>0)ob_end_clean();echo "GO_ERP_UPDATE_AUTH_CHECKED=2\n";
    }catch(Throwable $e){while(ob_get_level()>0)ob_end_clean();exit(1);}'''
    auth=run(prefix+['-r',code,str(ROOT),str(private)],timeout=120)
    need(auth.returncode==0 and auth.stdout==b'GO_ERP_UPDATE_AUTH_CHECKED=2\n','UPDATE_ENDPOINT_AUTH_CHECK_FAILED')
def publish(folder,installer_file,receipt_file,release):
    value=run(['/usr/bin/python3','-I','-B',str(folder/pathlib.PurePosixPath(PUBLISHER).name),'--installer',str(installer_file),'--receipt',str(receipt_file)],timeout=900)
    need(value.returncode==0,'SOURCE_GUARDED_PUBLICATION_FAILED')
    rows=value.stdout.decode('ascii').splitlines()
    need(rows in [[marker,'VERSION='+release['version'],'SOURCE='+release['source']['sha256']]
                  for marker in ('GO_ERP_UPDATE_PUBLISHED','GO_ERP_UPDATE_ALREADY_PUBLISHED')],'PUBLICATION_RECEIPT_INVALID')
    for row in rows:print(row)
def main():
    parser=argparse.ArgumentParser(description='Publish one independently reviewed successful Windows CI artifact from the authorized root console.')
    parser.add_argument('--release',required=True);parser.add_argument('--run-id',required=True);parser.add_argument('--artifact-directory',required=True)
    arguments=parser.parse_args(sys.argv[2:])
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    need(REV.fullmatch(arguments.release) is not None and re.fullmatch('[1-9][0-9]{0,15}',arguments.run_id) is not None and int(arguments.run_id)<=9007199254740991,'PINNED_RELEASE_AND_RUN_REQUIRED')
    incoming=pathlib.Path(arguments.artifact_directory);private_directory(incoming)
    actor=pwd.getpwnam('fasakha')
    need(ROOT.resolve()==ROOT,'PROJECT_UNSAFE')
    for path in (ROOT,ROOT/'.git'):
        value=path.lstat();need(stat.S_ISDIR(value.st_mode) and value.st_uid==actor.pw_uid,'PROJECT_UNSAFE')
    lock=os.open('/run/lock/go-erp-ci-publish.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    try:
        value=os.fstat(lock);need(stat.S_ISREG(value.st_mode) and value.st_uid==0 and value.st_nlink==1 and not value.st_mode&0o077,'LOCK_UNSAFE')
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise Stop('GO_ERP_CI_ANOTHER_PUBLICATION_IS_RUNNING') from None
        if not latest(arguments.release):print('GO_ERP_CI_SUPERSEDED_BUILD_SKIPPED_NO_APPLICATION_CHANGES');return 0
        folder=pathlib.Path(tempfile.mkdtemp(prefix='go-erp-ci-reviewed-',dir='/tmp'))
        try:
            payload=stage_release(arguments.release,folder,pathlib.Path(sys.argv[1]).absolute())
            module=publication_module(folder)
            manifest=json.loads(payload[MANIFEST]);info=json.loads(payload['desktop-pos/package.json'])
            receipt=incoming/'go-erp-update-release.json';installer=incoming/'GO-ERP-Preview-Setup.exe'
            release=validate_artifact(module,receipt,installer,arguments.release,int(arguments.run_id),info.get('version'),manifest)
            if not current_matches(module,release):activate(folder,arguments.release,release)
            postflight(folder,release,actor.pw_uid,actor.pw_gid)
            if not latest(arguments.release):print('GO_ERP_CI_SUPERSEDED_BUILD_NOT_PUBLISHED');return 0
            publish(folder,installer,receipt,release)
            return 0
        finally:shutil.rmtree(folder)
    finally:os.close(lock)
if __name__=='__main__':
    try:sys.exit(main())
    except Stop as error:print(str(error),file=sys.stderr);sys.exit(1)
    except BaseException:print('GO_ERP_CI_STOPPED_WITHOUT_PRIVATE_OUTPUT',file=sys.stderr);sys.exit(1)
PY
