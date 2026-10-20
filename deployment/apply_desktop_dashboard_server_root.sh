#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/python3 -B - "$0" "$@" <<'PY'
import hashlib,json,os,pathlib,pwd,re,shutil,signal,stat,subprocess,sys,tempfile

ROOT=pathlib.Path('/home/fasakha/public_html')
MANIFEST='deployment/desktop_dashboard_server_manifest.json'
ALTERNATIVE_MANIFEST='deployment/desktop_dashboard_server_manifest_expense_rejection.json'
APP='deployment/apply_desktop_dashboard_server.py'
# Filled from the final reviewed payload by pin_desktop_dashboard_installer.py.
HELPER_PINS = {
    'deployment/desktop_dashboard_server_manifest.json': 'f4a949256986a44e48708529c41f8d2ab8d8c396',
    'deployment/apply_desktop_dashboard_server.py': '811af31df25af5bf430703ef756320b97acb184d',
    'deployment/desktop_dashboard_release.php': '69f4efc30a440d7e80778d0618f0f0fa7f79d46d',
    'deployment/install_whatsapp_inbox.py': 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98',
}
class SafeError(Exception):pass

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def failure_status(failure):
    if failure=='DESKTOP_NO_APPROVED_BASELINE_MATCHES':return 'DESKTOP_ROOT_PRECHECK_FAILED_NO_APPLICATION_CHANGES'
    return 'DESKTOP_ROOT_INSTALL_FAILED_REVIEW_PRIVATE_BACKUPS'

def command(args,timeout=120):
    process=subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True)
    try:output,error=process.communicate(timeout=timeout)
    except BaseException as error:
        try:os.killpg(process.pid,signal.SIGTERM)
        except ProcessLookupError:pass
        try:process.communicate(timeout=10)
        except subprocess.TimeoutExpired:
            try:os.killpg(process.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            process.communicate(timeout=10)
        if not isinstance(error,subprocess.TimeoutExpired):raise
        raise SafeError('DESKTOP_ROOT_CHILD_TIMED_OUT')from None
    if len(output)>8*1024*1024 or len(error)>65536:raise SafeError('DESKTOP_ROOT_OUTPUT_LIMIT')
    return process.returncode,output,error

def git_file(release,relative,pin):
    prefix=['/usr/sbin/runuser','-u','fasakha','--','/usr/bin/git','-C',str(ROOT)]
    code,tree,_=command(prefix+['ls-tree','-z',release,relative])
    if code or re.fullmatch(rb'100(?:644|755) blob '+pin.encode()+rb'\t'+re.escape(relative.encode())+rb'\x00',tree)is None:
        raise SafeError('DESKTOP_ROOT_RELEASE_FILE_NOT_REGULAR')
    code,data,_=command(prefix+['show',release+':'+relative])
    if code or not data or blob(data)!=pin:raise SafeError('DESKTOP_ROOT_RELEASE_PIN_INVALID')
    return data

def source_roster(data):
    try:
        value=json.loads(data);result={}
        if type(value['version'])is not int or value['version']!=1 or not 1<=len(value['sources'])<=2000:raise ValueError()
        for row in value['sources']:
            name=row['path'];pin=row['after_blob']
            if (name in result or not isinstance(name,str) or '..'in pathlib.PurePosixPath(name).parts
                or not re.fullmatch(r'(?:app|bootstrap|config|database|desktop|resources|routes|public)/[A-Za-z0-9_.@/-]+|artisan',name)
                or name.startswith('bootstrap/cache/') or not re.fullmatch('[a-f0-9]{40}',pin)):raise ValueError()
            result[name]=pin
        return result
    except(ValueError,KeyError,TypeError):raise SafeError('DESKTOP_ROOT_MANIFEST_SCOPE_INVALID')from None

def stage(directory,release):
    payload={name:git_file(release,name,pin)for name,pin in HELPER_PINS.items()}
    roster=source_roster(payload[MANIFEST])
    alternative=None
    if ALTERNATIVE_MANIFEST in payload:
        alternative=directory/ALTERNATIVE_MANIFEST
        for name,pin in source_roster(payload[ALTERNATIVE_MANIFEST]).items():
            if name in roster and roster[name]!=pin:raise SafeError('DESKTOP_ROOT_ALTERNATIVE_SOURCE_CONFLICT')
            roster[name]=pin
    for name,pin in roster.items():payload[name]=git_file(release,name,pin)
    for name,data in payload.items():
        path=directory/name;path.parent.mkdir(parents=True,exist_ok=True)
        for parent in (path.parent,*path.parent.parents):
            if parent==directory or directory in parent.parents:
                parent.chmod(0o755);item=parent.lstat()
                if not stat.S_ISDIR(item.st_mode)or item.st_uid!=0 or stat.S_IMODE(item.st_mode)!=0o755:raise SafeError('DESKTOP_ROOT_STAGE_DIRECTORY_UNSAFE')
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb')as stream:stream.write(data);stream.flush();os.fchmod(stream.fileno(),0o644);os.fsync(stream.fileno())
    return directory/MANIFEST,alternative

def main():
    directory=None
    try:
        args=sys.argv[1:]
        if len(args)!=3 or args[1]!='--release' or not re.fullmatch('[a-f0-9]{40}',args[2])or os.geteuid()!=0:
            raise SafeError('DESKTOP_ROOT_USE_PINNED_RELEASE_AS_ROOT')
        if any(not re.fullmatch('[a-f0-9]{40}',pin)for pin in HELPER_PINS.values()):raise SafeError('DESKTOP_ROOT_PAYLOAD_PINS_UNSET')
        helper=pathlib.Path(args[0]).absolute();item=helper.lstat()
        if not stat.S_ISREG(item.st_mode)or item.st_uid!=0 or item.st_nlink!=1 or item.st_mode&0o077:raise SafeError('DESKTOP_ROOT_LAUNCHER_UNSAFE')
        for parent in helper.parents:
            info=parent.lstat()
            if not stat.S_ISDIR(info.st_mode)or info.st_uid!=0 or(info.st_mode&0o022 and parent!=pathlib.Path('/tmp')):raise SafeError('DESKTOP_ROOT_LAUNCHER_ANCESTOR_UNSAFE')
        uid=pwd.getpwnam('fasakha').pw_uid
        for path in(ROOT,ROOT/'.git'):
            item=path.lstat()
            if not stat.S_ISDIR(item.st_mode)or item.st_uid!=uid:raise SafeError('DESKTOP_ROOT_PROJECT_UNSAFE')
        os.umask(0o077)
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in()).throw(SafeError('DESKTOP_ROOT_INTERRUPTED')))
        directory=pathlib.Path(tempfile.mkdtemp(prefix='desktop-dashboard-payload-',dir='/tmp'));directory.chmod(0o755)
        manifest,alternative=stage(directory,args[2])
        approved_paths=set(source_roster(manifest.read_bytes()))|{'.env','bootstrap/cache/config.php','bootstrap/cache/routes.php','bootstrap/cache/routes-v7.php','storage/framework/views'}
        if alternative is not None:approved_paths.update(source_roster(alternative.read_bytes()))
        for name in tuple(approved_paths):approved_paths.update(str(parent)for parent in pathlib.PurePosixPath(name).parents if str(parent)!='.')
        arguments=['/usr/sbin/runuser','-u','fasakha','--','/usr/bin/python3','-B',str(directory/APP),
                   '--root',str(ROOT),'--manifest',str(manifest),'--manifest-blob',HELPER_PINS[MANIFEST]]
        if alternative is not None:arguments.extend(['--alternative-manifest',str(alternative),'--alternative-manifest-blob',HELPER_PINS[ALTERNATIVE_MANIFEST]])
        code,output,error=command(arguments,timeout=3600)
        # App diagnostics are captured privately. Only its fixed receipt reaches
        # root output; no framework messages, environment or SQL are repeated.
        try:rows=output.decode('ascii').splitlines()
        except UnicodeError:rows=[]
        if(code or len(rows)!=3 or rows[0]!='DESKTOP_DASHBOARD_SERVER_READY'
            or re.fullmatch(r'BACKUP=/home/fasakha/desktop-dashboard-release-backups/server-[A-Za-z0-9_-]{6,32}',rows[1])is None
            or re.fullmatch('SOURCE=[a-f0-9]{64}',rows[2])is None):
            # Emit only the app's fixed recovery receipt. Raw PHP/database/git
            # diagnostics are never repeated, even when a child failed.
            try:failure=error.decode('ascii').strip()
            except UnicodeError:failure=''
            if re.fullmatch(r'DESKTOP_ACTIVATION_FAILED; (FILES_RESTORED|CONCURRENT_FILES_RETAINED_REVIEW_REQUIRED); ADDITIVE_TABLES_AND_BUSINESS_OPERATIONS_RETAINED; BACKUP=/home/fasakha/desktop-dashboard-release-backups/server-[A-Za-z0-9_-]{6,32}',failure):
                print(failure,file=sys.stderr)
            elif (match:=re.fullmatch(r'DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: ([A-Za-z0-9_.@/-]+); UID=([0-9]{1,10}); MODE=([0-7]{4})',failure))and match[1]in approved_paths:
                print(failure,file=sys.stderr)
            elif re.fullmatch(r'DESKTOP_[A-Z_]+(?:; STAGE=[A-Z_]+)?',failure):
                print(failure,file=sys.stderr)
            raise SafeError(failure_status(failure))
        for row in rows:print(row)
        return 0
    except SafeError as error:print(str(error),file=sys.stderr)
    except BaseException:print('DESKTOP_ROOT_STOPPED_WITHOUT_PRIVATE_OUTPUT',file=sys.stderr)
    finally:
        if directory is not None:shutil.rmtree(directory)
    return 1

sys.exit(main())
PY
