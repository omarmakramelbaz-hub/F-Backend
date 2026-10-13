#!/usr/bin/env python3
"""Publish reviewed desktop additions without changing the production Git checkout."""
import argparse
import fcntl
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
COMPATIBILITY_PATH = 'app/Http/Requests/Dashboard/QuestionAnswer/StoreQuestionAnswrRequest.php'
COMPATIBILITY_CANONICAL_PATH = 'app/Http/Requests/Dashboard/QuestionAnswer/StoreQuestionAnswerRequest.php'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
MIGRATIONS = (
    'database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php',
    'database/migrations/2026_10_08_160000_create_desktop_dashboard_local_state.php',
    'database/migrations/2026_10_08_210000_create_desktop_dashboard_archived_commands.php',
    'database/migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php',
    'database/migrations/2026_10_08_220000_create_desktop_dashboard_media_manifest.php',
    'database/migrations/2026_10_08_230000_create_desktop_dashboard_source_manifest.php',
)
SOURCE_ROOTS = ('app','bootstrap','config','database','desktop','resources','routes',
                'public/dashboard/js','public/dashboard/css','public/dashboard/vendor/desktop-external')
SECRET = re.compile(r'(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$', re.I)
SUFFIX = re.compile(r'\.(?:php|js|css|json|html|scss|vue)$',re.I)
STAGE = 'BOOTSTRAP'

class SafeError(Exception):
    """Only fixed public codes are emitted by this installer."""

def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def load_base():
    path=Path(__file__).absolute().with_name('install_whatsapp_inbox.py')
    data=path.read_bytes();item=path.lstat()
    if (not stat.S_ISREG(item.st_mode) or item.st_uid not in (0,os.geteuid())
        or item.st_mode & 0o022 or item.st_nlink!=1 or blob(data)!=BASE_BLOB):
        raise SafeError('DESKTOP_PUBLICATION_BASE_INVALID')
    module=importlib.util.module_from_spec(importlib.util.spec_from_loader('desktop_publication_base',loader=None))
    module.__file__=str(path);sys.modules[module.__name__]=module
    exec(compile(data,str(path),'exec'),module.__dict__)
    return module

base=load_base()

def command(root,args,capture=False,timeout=120,private=None):
    env=os.environ.copy()
    if private:
        env.update(APP_SERVICES_CACHE=str(private/'private-services.php'),
                   APP_PACKAGES_CACHE=str(private/'private-packages.php'))
    process=subprocess.Popen(args,cwd=root,env=env,stdout=subprocess.PIPE,
                             stderr=subprocess.DEVNULL,start_new_session=True)
    try:
        output,_=process.communicate(timeout=timeout)
    except BaseException as error:
        try:os.killpg(process.pid,signal.SIGTERM)
        except ProcessLookupError:pass
        try:process.communicate(timeout=10)
        except subprocess.TimeoutExpired:
            try:os.killpg(process.pid,signal.SIGKILL)
            except ProcessLookupError:pass
            process.communicate(timeout=10)
        if not isinstance(error,subprocess.TimeoutExpired):raise
        return 1,b''
    return process.returncode,output if capture and len(output)<=8*1024*1024 else b''

def strict_path(relative):
    return (isinstance(relative,str) and re.fullmatch(r'(?:app|bootstrap|config|database|desktop|resources|routes|public)/[A-Za-z0-9_.@/-]+|artisan',relative)
            and '..' not in Path(relative).parts and not relative.startswith('bootstrap/cache/') and not SECRET.search(relative))

def fingerprint_value(value):
    return (isinstance(value,dict) and set(value)=={'format','files','sha256','framework'}
            and type(value['format']) is int and value['format']==1 and type(value['files']) is int
            and 1<=value['files']<=20000 and re.fullmatch('[a-f0-9]{64}',value['sha256'] or '')
            and value['framework']=='8.83.29')

def read_manifest(path,pin):
    data=path.read_bytes();item=path.lstat()
    if (not stat.S_ISREG(item.st_mode) or item.st_uid not in (0,os.geteuid()) or item.st_mode&0o022
        or item.st_nlink!=1 or len(data)>8*1024*1024 or blob(data)!=pin):
        raise SafeError('DESKTOP_MANIFEST_PIN_INVALID')
    value=json.loads(data)
    if (not isinstance(value,dict) or set(value)!={'version','expected_head','before_source','after_source','sources','protected','migrations'}
        or type(value['version']) is not int or value['version']!=1
        or not re.fullmatch('[a-f0-9]{40}',value['expected_head'])
        or not fingerprint_value(value['before_source']) or not fingerprint_value(value['after_source'])
        or value['migrations']!=list(MIGRATIONS) or not isinstance(value['sources'],list)
        or not 1<=len(value['sources'])<=2000 or not isinstance(value['protected'],list)):
        raise SafeError('DESKTOP_MANIFEST_SCOPE_INVALID')
    seen=set();phases=[]
    for row in value['sources']:
        if (set(row)!={'path','before_blob','after_blob','phase'} or not strict_path(row['path'])
            or row['path'] in seen or row['before_blob']==row['after_blob']
            or row['before_blob'] is not None and not re.fullmatch('[a-f0-9]{40}',row['before_blob'])
            or not re.fullmatch('[a-f0-9]{40}',row['after_blob']) or type(row['phase']) is not int
            or row['phase'] not in (0,1,2)):
            raise SafeError('DESKTOP_MANIFEST_SOURCE_INVALID')
        if row['path'].startswith('database/migrations/') and row['path'] not in MIGRATIONS:
            raise SafeError('DESKTOP_MIGRATION_SCOPE_INVALID')
        if (row['path'] in MIGRATIONS)!=(row['phase']==0):raise SafeError('DESKTOP_PUBLICATION_ORDER_INVALID')
        if row['phase']==1 and row['before_blob'] is not None:
            # Add every new dependency before modifying an existing controller
            # or service which a currently active route can already invoke.
            raise SafeError('DESKTOP_EXISTING_SOURCE_ORDER_INVALID')
        if (row['path'].startswith(('routes/','resources/views/','app/Providers/'))
            or row['path'] in ('app/Http/Kernel.php','app/Exceptions/Handler.php')) and row['phase']!=2:
            raise SafeError('DESKTOP_ENTRYPOINT_ORDER_INVALID')
        seen.add(row['path']);phases.append(row['phase'])
    if phases!=sorted(phases) or not set(MIGRATIONS).issubset(seen):raise SafeError('DESKTOP_PUBLICATION_ORDER_INVALID')
    compatibility=next((row for row in value['sources']if row['path']==COMPATIBILITY_PATH),None)
    canonical=next((row for row in value['sources']if row['path']==COMPATIBILITY_CANONICAL_PATH),None)
    if canonical is not None or compatibility is not None:
        if(compatibility is None or canonical is None or compatibility['phase']!=2 or compatibility['before_blob']is None
            or canonical['phase']!=1 or canonical['before_blob']is not None):
            raise SafeError('DESKTOP_REQUEST_COMPATIBILITY_PAIR_INVALID')
    for row in value['protected']:
        if set(row)!={'path','blob'} or not strict_path(row['path']) or row['path'] in seen or not re.fullmatch('[a-f0-9]{40}',row['blob']):
            raise SafeError('DESKTOP_MANIFEST_PROTECTED_INVALID')
        seen.add(row['path'])
    return value

def manifest_target_profile(value):
    return {**{row['path']:row['after_blob']for row in value['sources']},
            **{row['path']:row['blob']for row in value['protected']}}

def validate_manifest_pair(primary,alternative):
    # A baseline chooses which current bytes may be changed, never a different
    # desired release. Already-current paths are explicit protected rows in the
    # other manifest, so both final source and external GUI pins must agree.
    if(any(primary[key]!=alternative[key]for key in('expected_head','after_source','migrations'))
        or manifest_target_profile(primary)!=manifest_target_profile(alternative)):
        raise SafeError('DESKTOP_ALTERNATIVE_TARGET_DISAGREES')

def baseline_matches(root,uid,value,head,fingerprint):
    if head!=value['expected_head']or fingerprint!=value['before_source']:return False
    for row in value['sources']:
        path=root/row['path']
        if row['before_blob']is None:
            if path.exists()or path.is_symlink():return False
        else:
            try:content=read_source(path,root,uid)
            except FileNotFoundError:return False
            if blob(content)!=row['before_blob']:return False
    for row in value['protected']:
        try:content=read_source(root/row['path'],root,uid)
        except FileNotFoundError:return False
        if blob(content)!=row['blob']:return False
    return True

def select_manifest(root,uid,candidates,runner):
    # Selection is read only, before creating backup directories or locks.
    # Mixed or unknown deployments cannot be assembled from per-file choices.
    code,head=runner(root,['/usr/bin/git','rev-parse','HEAD'],capture=True)
    if code:raise SafeError('DESKTOP_BASELINE_HEAD_UNAVAILABLE')
    head=head.strip().decode();fingerprint=source_fingerprint(root,uid)
    matches=[candidate for candidate in candidates if baseline_matches(root,uid,candidate[2],head,fingerprint)]
    if len(matches)!=1:
        raise SafeError('DESKTOP_NO_APPROVED_BASELINE_MATCHES'if not matches else'DESKTOP_APPROVED_BASELINE_AMBIGUOUS')
    if source_fingerprint(root,uid)!=fingerprint:raise SafeError('DESKTOP_BASELINE_CHANGED_DURING_SELECTION')
    return matches[0]

def read_source(path,root,uid):
    base.safe_ancestors(path,uid)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        item=os.fstat(fd)
        if not stat.S_ISREG(item.st_mode) or item.st_uid not in (0,uid) or item.st_size>8*1024*1024:
            raise SafeError('DESKTOP_SOURCE_METADATA_INVALID')
        with os.fdopen(fd,'rb',closefd=False)as stream:data=stream.read(8*1024*1024+1)
        after=os.fstat(fd)
        keys=lambda s:(s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns)
        if len(data)>8*1024*1024 or keys(item)!=keys(after) or keys(after)!=keys(path.lstat()):
            raise SafeError('DESKTOP_SOURCE_CHANGED')
        return data
    finally:os.close(fd)

def source_fingerprint(root,uid):
    files={};cases=set();size=0
    def add(path):
        nonlocal size
        name=path.relative_to(root).as_posix()
        if name.startswith('bootstrap/cache/') or SECRET.search(name) or name!='artisan' and not SUFFIX.search(name):return
        if not strict_path(name) or name.lower()in cases:raise SafeError('DESKTOP_SOURCE_PATH_INVALID')
        data=read_source(path,root,uid);size+=len(data)
        if size>64*1024*1024 or len(files)>=20000:raise SafeError('DESKTOP_SOURCE_LIMIT')
        files[name]=hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest();cases.add(name.lower())
    def walk_error(_):raise SafeError('DESKTOP_SOURCE_UNREADABLE')
    for relative in SOURCE_ROOTS:
        folder=root/relative;base.safe_ancestors(folder,uid)
        if folder.is_symlink():raise SafeError('DESKTOP_SOURCE_LINK')
        if not folder.exists():continue
        if not folder.is_dir():raise SafeError('DESKTOP_SOURCE_ROOT_INVALID')
        for parent,directories,names in os.walk(folder,followlinks=False,onerror=walk_error):
            for name in directories+names:
                path=Path(parent)/name
                if not SECRET.search(path.relative_to(root).as_posix()) and path.is_symlink():raise SafeError('DESKTOP_SOURCE_LINK')
            if Path(parent)==root/'bootstrap':directories[:]=[n for n in directories if n!='cache']
            for name in names:add(Path(parent)/name)
    if(root/'artisan').exists()or(root/'artisan').is_symlink():add(root/'artisan')
    if not files:raise SafeError('DESKTOP_SOURCE_EMPTY')
    digest=hashlib.sha256(b'desktop-dashboard-source:1\n')
    for name,sha in sorted(files.items()):digest.update(name.encode()+b'\0'+sha.encode()+b'\n')
    return {'format':1,'files':len(files),'sha256':digest.hexdigest(),'framework':'8.83.29'}

def write_private(path,data):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())

def patch_env(data):
    # Retain every other line byte-for-byte, including saved Meta/WhatsApp settings.
    lines=data.splitlines(keepends=True)
    wanted=re.compile(rb'^[ \t]*(?:export[ \t]+)?DESKTOP_DASHBOARD_(?:ENABLED|LOCAL)[ \t]*=')
    content=b''.join(line for line in lines if not wanted.match(line))
    return content+(b''if content.endswith(b'\n')else b'\n')+b'DESKTOP_DASHBOARD_ENABLED=true\nDESKTOP_DASHBOARD_LOCAL=false\n'

def publication_snapshot(root,relative,uid,absent_ok=False):
    path=root/relative
    # Report only the approved path and non-sensitive ownership/mode facts.
    # Existing root-owned models are readable for the full source guard, but
    # this app-user publisher never silently chowns a destination or ancestor.
    for target in (*reversed(path.parents),path):
        if target!=root and root not in target.parents:continue
        try:item=target.lstat()
        except FileNotFoundError:continue
        if item.st_uid!=uid:
            name=target.relative_to(root).as_posix()
            if name=='.':name=relative
            raise SafeError('DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: '+name+'; UID='+str(item.st_uid)+'; MODE='+format(stat.S_IMODE(item.st_mode),'04o'))
    return base.snapshot(path,uid,root,absent_ok=absent_ok)

def php_check(root,private,helper,mode,runner,extra=()):
    code,output=runner(root,[PHP,str(helper),mode,str(root),str(private),*map(str,extra)],capture=True,timeout=1800,private=private)
    if code:raise SafeError('DESKTOP_PRIVATE_CHECK_FAILED')
    try:return json.loads(output)
    except(ValueError,TypeError):raise SafeError('DESKTOP_PRIVATE_CHECK_RESULT_INVALID')from None

def web_auth_attempts():
    # Fixed first-party host, TLS verification, no credentials or redirects.
    # A fresh web PHP process must see the published routes and enabled guard,
    # rather than merely passing in the deployer's CLI process/opcode cache.
    deadline=time.monotonic()+50
    for attempt in range(2):
        try:
            for method,suffix in (('GET','bootstrap'),('POST','commands'),('POST','remote-attempts'),('GET','media')):
                remaining=deadline-time.monotonic()
                if remaining<=0:raise SafeError('DESKTOP_WEB_AUTH_DEADLINE_EXCEEDED')
                connection=http.client.HTTPSConnection('fasakhaninja.com',timeout=min(10,remaining))
                try:
                    body=b'{}'if method=='POST'else None
                    headers={'Accept':'application/json','Connection':'close'}
                    if body is not None:headers['Content-Type']='application/json'
                    connection.request(method,'/api/desktop-dashboard/'+suffix,body=body,headers=headers)
                    response=connection.getresponse()
                    if response.status!=401:raise SafeError('DESKTOP_WEB_AUTH_STATUS_INVALID')
                    # Do not read, log, or repeat response bodies.
                finally:connection.close()
            return 4
        except(OSError,http.client.HTTPException,SafeError):
            if attempt==1 or deadline-time.monotonic()<=1:raise SafeError('DESKTOP_WEB_AUTH_VERIFICATION_FAILED')from None
            time.sleep(1)
    raise SafeError('DESKTOP_WEB_AUTH_VERIFICATION_FAILED')

def verify_web_auth():
    # The socket timeout alone does not bound DNS resolution or repeated TLS
    # stages. A process alarm caps the entire fixed-host check below 60 seconds.
    previous_handler=signal.getsignal(signal.SIGALRM)
    signal.signal(signal.SIGALRM,lambda *_:(_ for _ in()).throw(SafeError('DESKTOP_WEB_AUTH_DEADLINE_EXCEEDED')))
    previous_timer=signal.setitimer(signal.ITIMER_REAL,50)
    try:return web_auth_attempts()
    finally:
        signal.setitimer(signal.ITIMER_REAL,*previous_timer)
        signal.signal(signal.SIGALRM,previous_handler)

def rollback(root,uid,journal,created):
    # Entrypoints/cache/environment were published last and are restored first.
    # If a peer changed one, retain the remaining dependencies instead of
    # removing code which the peer's current entrypoint may still require.
    compatibility=journal.get(COMPATIBILITY_PATH)
    canonical=journal.get(COMPATIBILITY_CANONICAL_PATH)
    def bridge_unchanged():
        if compatibility is None:return True
        try:return base.snapshot(compatibility.path,uid,root,absent_ok=True)in(compatibility.before,compatibility.after)
        except BaseException:return False
    def retain_canonical():
        # A bridge edit can race the canonical withdrawal. The new dependency's
        # original inode is still held privately; re-link it without replacing
        # any peer-created path before stopping the rest of the rollback.
        if canonical is None:return
        try:
            retained=base.snapshot(canonical.temporary,uid,root)
            if(base.snapshot(canonical.path,uid,root,absent_ok=True)is None
                and(retained.device,retained.inode)==(canonical.after.device,canonical.after.inode)):
                base.safe_ancestors(canonical.path,uid,root)
                try:os.link(canonical.temporary,canonical.path,follow_symlinks=False)
                except FileExistsError:pass
        except BaseException:pass
    with base.blocked_signals():
        if not bridge_unchanged():retain_canonical();return False
        for publication in reversed(list(journal.values())):
            try:
                if not bridge_unchanged():retain_canonical();return False
                restored=base.recover_publication(publication,root,uid)
                if publication is canonical and not bridge_unchanged():retain_canonical();return False
                if not restored:return False
            except BaseException:
                if not bridge_unchanged():retain_canonical()
                return False
        for directory,inode in reversed(created):
            try:
                item=directory.lstat()
                if item.st_uid==uid and item.st_ino==inode and stat.S_ISDIR(item.st_mode):directory.rmdir()
            except OSError:pass
    return True

def archive_paths(root):
    paths=['app','bootstrap','config','database','resources','routes','public','vendor','artisan','.env']
    paths += [name for name in ('desktop','composer.json','composer.lock','storage/framework/views')if(root/name).exists()]
    return paths

def guard_backup_space(root,backup,database_bytes):
    if type(database_bytes)is not int or database_bytes<0:raise SafeError('DESKTOP_DATABASE_SIZE_INVALID')
    size=0;seen=set();device=root.stat().st_dev
    for name in archive_paths(root):
        path=root/name
        if not path.exists():continue
        paths=[path]
        if path.is_dir()and not path.is_symlink():
            paths=[]
            for parent,directories,names in os.walk(path,followlinks=False):
                directories[:]=[n for n in directories if (Path(parent)/n).lstat().st_dev==device and not(Path(parent)/n).is_symlink()]
                paths.extend(Path(parent)/n for n in names)
        for path in paths:
            item=path.lstat();key=(item.st_dev,item.st_ino)
            if item.st_dev==device and key not in seen:
                seen.add(key);size+=(item.st_size if stat.S_ISREG(item.st_mode)else 0)+2048
    # Dump text/hex escaping and metadata can exceed the engine's byte count.
    # Retain at least 1 GiB beyond the conservative archive+SQL estimate.
    required=size+4*database_bytes+1024*1024*1024
    if shutil.disk_usage(backup).free<required:raise SafeError('DESKTOP_BACKUP_SPACE_INSUFFICIENT')

def archive_code(root,backup,runner):
    paths=archive_paths(root)
    # Runtime ledgers/uploads are retained in place; source, vendor, credentials,
    # actual config/route cache and compiled views are all privately archived.
    code,_=runner(root,['/usr/bin/tar','--acls','--xattrs','--numeric-owner','--one-file-system','-cpf',str(backup/'code.tar'),*paths],timeout=1800)
    if code or not(backup/'code.tar').is_file()or(backup/'code.tar').stat().st_size==0:raise SafeError('DESKTOP_CODE_BACKUP_FAILED')
    (backup/'code.tar').chmod(0o600)
    code,_=runner(root,['/usr/bin/tar','-tf',str(backup/'code.tar')],timeout=1800)
    if code:raise SafeError('DESKTOP_CODE_BACKUP_INVALID')

def archived_source_fingerprint(path):
    # Verify the source inside the archive, rather than trusting a tar exit
    # status while requests or another release could have edited live files.
    files={};cases=set();size=0
    try:
        with tarfile.open(path,'r:')as archive:
            for member in archive.getmembers():
                name=member.name
                if name!='artisan' and not any(name.startswith(root+'/')for root in SOURCE_ROOTS):continue
                if name.startswith('bootstrap/cache/')or SECRET.search(name)or member.isdir():continue
                if member.issym():raise SafeError('DESKTOP_ARCHIVED_SOURCE_LINK')
                if name!='artisan'and not SUFFIX.search(name):continue
                if(not strict_path(name)or name.lower()in cases or member.size>8*1024*1024
                   or not(member.isfile()or member.islnk())):raise SafeError('DESKTOP_ARCHIVED_SOURCE_INVALID')
                stream=archive.extractfile(member)
                if stream is None:raise SafeError('DESKTOP_ARCHIVED_SOURCE_UNREADABLE')
                with stream:data=stream.read(8*1024*1024+1)
                size+=len(data)
                if len(data)>8*1024*1024 or size>64*1024*1024 or len(files)>=20000:raise SafeError('DESKTOP_ARCHIVED_SOURCE_LIMIT')
                files[name]=hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest();cases.add(name.lower())
    except(tarfile.TarError,OSError,KeyError):raise SafeError('DESKTOP_CODE_BACKUP_INVALID')from None
    digest=hashlib.sha256(b'desktop-dashboard-source:1\n')
    for name,sha in sorted(files.items()):digest.update(name.encode()+b'\0'+sha.encode()+b'\n')
    return {'format':1,'files':len(files),'sha256':digest.hexdigest(),'framework':'8.83.29'}

def file_digest(path):
    digest=hashlib.sha256()
    with path.open('rb')as stream:
        for data in iter(lambda:stream.read(1024*1024),b''):digest.update(data)
    return digest.hexdigest()

def verify_archived_files(path,expected):
    try:
        with tarfile.open(path,'r:')as archive:
            for name,content in expected.items():
                if content is None:
                    try:archive.getmember(name)
                    except KeyError:continue
                    raise SafeError('DESKTOP_ARCHIVED_ABSENT_FILE_CHANGED')
                stream=archive.extractfile(name)
                if stream is None:raise SafeError('DESKTOP_ARCHIVED_FILE_UNREADABLE')
                with stream:data=stream.read(8*1024*1024+1)
                if data!=content:raise SafeError('DESKTOP_ARCHIVED_FILE_CHANGED')
    except(tarfile.TarError,OSError,KeyError):raise SafeError('DESKTOP_ARCHIVED_FILE_MISSING_OR_INVALID')from None

def install(root,uid,manifest_path,manifest_pin,runner=command,web_checker=verify_web_auth,
            alternative_manifest_path=None,alternative_manifest_pin=None):
    global STAGE
    STAGE='MANIFEST';value=read_manifest(manifest_path,manifest_pin)
    if (alternative_manifest_path is None)!=(alternative_manifest_pin is None):raise SafeError('DESKTOP_ALTERNATIVE_MANIFEST_PAIR_REQUIRED')
    base.validate_root(root,uid)
    if alternative_manifest_path is not None:
        if(not manifest_path.is_absolute()or not alternative_manifest_path.is_absolute()
            or manifest_path.parent!=alternative_manifest_path.parent or manifest_path==alternative_manifest_path):
            raise SafeError('DESKTOP_ALTERNATIVE_PAYLOAD_SCOPE_INVALID')
        alternative=read_manifest(alternative_manifest_path,alternative_manifest_pin)
        validate_manifest_pair(value,alternative)
        STAGE='SELECTION'
        manifest_path,manifest_pin,value=select_manifest(root,uid,[(manifest_path,manifest_pin,value),
            (alternative_manifest_path,alternative_manifest_pin,alternative)],runner)
    payload=manifest_path.parent.parent;helper=payload/'deployment/desktop_dashboard_release.php'
    parent=root.parent/'desktop-dashboard-release-backups';base.private_parent(parent,uid)
    fd=os.open(parent/'server-install.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    try:
        item=os.fstat(fd)
        if not stat.S_ISREG(item.st_mode)or item.st_uid!=uid or item.st_nlink!=1 or stat.S_IMODE(item.st_mode)!=0o600:raise SafeError('DESKTOP_LOCK_METADATA_INVALID')
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise SafeError('DESKTOP_INSTALL_ALREADY_RUNNING')from None
        code,head=runner(root,['/usr/bin/git','rev-parse','HEAD'],capture=True)
        if code or head.strip().decode()!=value['expected_head']:raise SafeError('DESKTOP_SERVER_HEAD_CHANGED')
        if source_fingerprint(root,uid)!=value['before_source']:raise SafeError('DESKTOP_SERVER_SOURCE_CHANGED')
        STAGE='SOURCES';before={};proposals={};protected={}
        for row in value['sources']:
            relative=row['path'];original=publication_snapshot(root,relative,uid,absent_ok=True)
            if(original is None)!=(row['before_blob']is None)or original is not None and blob(original.content)!=row['before_blob']:
                raise SafeError('DESKTOP_REVIEWED_BEFORE_SOURCE_MISMATCH')
            if original is not None and original.mode&0o022:raise SafeError('DESKTOP_SOURCE_WRITABLE_BY_OTHER_USER')
            content=read_source(payload/relative,payload,uid)
            if blob(content)!=row['after_blob']:raise SafeError('DESKTOP_REVIEWED_AFTER_SOURCE_MISMATCH')
            before[relative]=original;proposals[relative]=content
        for row in value['protected']:
            content=read_source(root/row['path'],root,uid)
            if blob(content)!=row['blob']:raise SafeError('DESKTOP_PROTECTED_ASSET_CHANGED')
            protected[row['path']]=content
        before['.env']=publication_snapshot(root,'.env',uid)
        if before['.env'].mode&0o022:raise SafeError('DESKTOP_ENVIRONMENT_MODE_INVALID')
        backup=Path(tempfile.mkdtemp(prefix='server-',dir=parent));backup.chmod(0o700)
        if backup.stat().st_dev!=root.stat().st_dev:raise SafeError('DESKTOP_BACKUP_FILESYSTEM_INVALID')
        publications=backup/'publications';publications.mkdir(mode=0o700);base.probe_atomic_exchange(publications)
        private=backup/'checks';private.mkdir(mode=0o700)
        STAGE='PREFLIGHT';report=php_check(root,private,helper,'preflight',runner)
        if(set(report)!={'route_cache','config_cache','database_estimated_bytes'}or report['config_cache']!='bootstrap/cache/config.php'
            or report['route_cache']not in('bootstrap/cache/routes.php','bootstrap/cache/routes-v7.php')):
            raise SafeError('DESKTOP_CACHE_SCOPE_INVALID')
        for relative in ('bootstrap/cache/config.php',report['route_cache']):before[relative]=publication_snapshot(root,relative,uid,absent_ok=True)
        for relative in proposals:
            if relative.endswith('.php'):
                code,_=runner(root,[PHP,'-l',str(payload/relative)])
                if code:raise SafeError('DESKTOP_PHP_LINT_FAILED')
        STAGE='BACKUP';guard_backup_space(root,backup,report['database_estimated_bytes']);archive_code(root,backup,runner)
        if archived_source_fingerprint(backup/'code.tar')!=value['before_source']:raise SafeError('DESKTOP_ARCHIVED_SOURCE_MISMATCH')
        verify_archived_files(backup/'code.tar',{**protected,**{name:None if item is None else item.content for name,item in before.items()}})
        database=php_check(root,backup,helper,'backup',runner)
        if(set(database)!={'database_backup_sha256','database_backup_bytes','consistency'}or not re.fullmatch('[a-f0-9]{64}',database['database_backup_sha256'])
            or type(database['database_backup_bytes'])is not int or database['database_backup_bytes']<1
            or database['consistency']not in('innodb-consistent-snapshot','global-read-lock')):
            raise SafeError('DESKTOP_DATABASE_BACKUP_INVALID')
        receipt={'version':1,'git_head':value['expected_head'],'before_source':value['before_source'],'after_source':value['after_source'],'manifest_blob':manifest_pin,'database':database,
                 'code_archive':{'sha256':file_digest(backup/'code.tar'),'bytes':(backup/'code.tar').stat().st_size},
                 'files':{name:None if item is None else{'blob':blob(item.content),'uid':item.uid,'gid':item.gid,'mode':item.mode,'device':item.device,'inode':item.inode}for name,item in before.items()}}
        write_private(backup/'receipt.json',(json.dumps(receipt,sort_keys=True,indent=2)+'\n').encode())
        # Backup succeeds before the first source, schema, .env or cache change.
        if source_fingerprint(root,uid)!=value['before_source']:raise SafeError('DESKTOP_SOURCE_CHANGED_DURING_BACKUP')
        for relative,item in before.items():base.assert_snapshot(root/relative,item,uid,root)
        for relative,content in protected.items():
            if read_source(root/relative,root,uid)!=content:raise SafeError('DESKTOP_PROTECTED_ASSET_CHANGED_DURING_BACKUP')
        written={};created=[]
        def publish(relative,content,original):
            return base.replace_file(root/relative,content,original,uid,root,created,journal=written,relative=relative,private_directory=publications)
        try:
            # The original misspelled filename declares the canonical class.
            # A fallback shim must replace that declaration before adding the
            # canonical file; otherwise a fresh Spatie/Artisan discovery sees
            # two declarations during the pre-entrypoint migration bootstrap.
            # This is the sole reviewed early modification of existing source.
            compatibility=next((row for row in value['sources']if row['path']==COMPATIBILITY_PATH),None)
            if compatibility is not None:
                STAGE='COMPATIBILITY'
                publish(COMPATIBILITY_PATH,proposals[COMPATIBILITY_PATH],before[COMPATIBILITY_PATH])
            STAGE='PUBLICATION'
            for row in value['sources']:
                if row['phase']==2:continue
                relative=row['path'];publish(relative,proposals[relative],before[relative])
            STAGE='MIGRATION'
            for migration in MIGRATIONS:
                code,_=runner(root,[PHP,'artisan','migrate','--path='+migration,'--force'],private=private,timeout=1800)
                if code:raise SafeError('DESKTOP_ADDITIVE_MIGRATION_FAILED')
            STAGE='ENTRYPOINTS'
            for row in value['sources']:
                if row['phase']!=2:continue
                if row['path']==COMPATIBILITY_PATH:continue
                relative=row['path'];publish(relative,proposals[relative],before[relative])
            if source_fingerprint(root,uid)!=value['after_source']:raise SafeError('DESKTOP_PUBLISHED_SOURCE_MISMATCH')
            STAGE='ENVIRONMENT';publish('.env',patch_env(before['.env'].content),before['.env'])
            if before['bootstrap/cache/config.php']is not None:
                php_check(root,private,helper,'config',runner)
                proposal=base.snapshot(private/'proposed-config.php',uid,private)
                publish('bootstrap/cache/config.php',proposal.content,before['bootstrap/cache/config.php'])
            STAGE='CACHE'
            if before[report['route_cache']]is not None:
                # Build under a private APP_ROUTES_CACHE and publish by guarded
                # inode exchange. Never clear or rebuild an unrelated cache.
                code,_=runner(root,[PHP,'-r',"putenv('APP_ROUTES_CACHE='.$argv[1]);$_ENV['APP_ROUTES_CACHE']=$argv[1];$_SERVER['APP_ROUTES_CACHE']=$argv[1];require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$kernel=$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class);exit($kernel->call('route:cache'));",str(private/'proposed-routes.php')],private=private)
                if code:raise SafeError('DESKTOP_PRIVATE_ROUTE_CACHE_FAILED')
                proposal=base.snapshot(private/'proposed-routes.php',uid,private)
                publish(report['route_cache'],proposal.content,before[report['route_cache']])
            for relative in proposals:
                if not relative.endswith('.blade.php'):continue
                blade=php_check(root,private,helper,'blade',runner,(relative,))
                if(set(blade)!={'target','compiled'}or not re.fullmatch(r'storage/framework/views/[a-f0-9]{40}\.php',blade['target'])
                   or not re.fullmatch(r'compiled-[a-f0-9]{64}\.php',blade['compiled'])):raise SafeError('DESKTOP_COMPILED_VIEW_SCOPE_INVALID')
                old=publication_snapshot(root,blade['target'],uid,absent_ok=True)
                proposal=base.snapshot(private/blade['compiled'],uid,private)
                code,_=runner(root,[PHP,'-l',str(private/blade['compiled'])])
                if code:raise SafeError('DESKTOP_COMPILED_VIEW_LINT_FAILED')
                publish(blade['target'],proposal.content,old)
            STAGE='POSTFLIGHT';result=php_check(root,private,helper,'verify',runner,(manifest_path,))
            if result!={'source':value['after_source'],'authentication_checked':4,'enabled':True,'local':False}:raise SafeError('DESKTOP_POSTFLIGHT_RESULT_INVALID')
            STAGE='WEB';count=web_checker()
            if type(count)is not int or count!=4:raise SafeError('DESKTOP_WEB_AUTH_RECEIPT_INVALID')
            result['web_authentication_checked']=count
            if source_fingerprint(root,uid)!=value['after_source']:raise SafeError('DESKTOP_SOURCE_CHANGED_AFTER_VERIFICATION')
            for relative,publication in written.items():base.assert_snapshot(root/relative,publication.after,uid,root)
            for relative,content in protected.items():
                if read_source(root/relative,root,uid)!=content:raise SafeError('DESKTOP_PROTECTED_ASSET_CHANGED_AFTER_PUBLICATION')
            code,head=runner(root,['/usr/bin/git','rev-parse','HEAD'],capture=True)
            if code or head.strip().decode()!=value['expected_head']:raise SafeError('DESKTOP_HEAD_CHANGED_AFTER_PUBLICATION')
        except BaseException:
            restored=rollback(root,uid,written,created)
            status='FILES_RESTORED'if restored else'CONCURRENT_FILES_RETAINED_REVIEW_REQUIRED'
            raise SafeError('DESKTOP_ACTIVATION_FAILED; '+status+'; ADDITIVE_TABLES_AND_BUSINESS_OPERATIONS_RETAINED; BACKUP='+str(backup))from None
        write_private(backup/'activated.json',(json.dumps(result,sort_keys=True)+'\n').encode())
        return backup,result
    finally:os.close(fd)

def main():
    try:
        args=argparse.ArgumentParser(description=__doc__)
        args.add_argument('--root',required=True);args.add_argument('--manifest',required=True);args.add_argument('--manifest-blob',required=True)
        args.add_argument('--alternative-manifest');args.add_argument('--alternative-manifest-blob')
        options=args.parse_args()
        if Path(options.root)!=ROOT or os.geteuid()!=pwd.getpwnam('fasakha').pw_uid:raise SafeError('DESKTOP_RUN_AS_FASAKHA_FOR_FIXED_ROOT')
        if not re.fullmatch('[a-f0-9]{40}',options.manifest_blob):raise SafeError('DESKTOP_MANIFEST_PIN_INVALID')
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in()).throw(SafeError('DESKTOP_INSTALL_INTERRUPTED')))
        if (options.alternative_manifest is None)!=(options.alternative_manifest_blob is None):raise SafeError('DESKTOP_ALTERNATIVE_MANIFEST_PAIR_REQUIRED')
        if options.alternative_manifest_blob is not None and not re.fullmatch('[a-f0-9]{40}',options.alternative_manifest_blob):raise SafeError('DESKTOP_MANIFEST_PIN_INVALID')
        backup,result=install(ROOT,os.geteuid(),Path(options.manifest),options.manifest_blob,
            alternative_manifest_path=Path(options.alternative_manifest)if options.alternative_manifest is not None else None,
            alternative_manifest_pin=options.alternative_manifest_blob)
        print('DESKTOP_DASHBOARD_SERVER_READY');print('BACKUP='+str(backup));print('SOURCE='+result['source']['sha256'])
        return 0
    except SafeError as error:print(str(error),file=sys.stderr)
    except BaseException:print('DESKTOP_INSTALL_STOPPED; STAGE='+STAGE,file=sys.stderr)
    return 1

if __name__=='__main__':sys.exit(main())
