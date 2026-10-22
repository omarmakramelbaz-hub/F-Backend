#!/usr/bin/env python3
"""Finalize only proven private hardlink aliases of the accepted new GO ERP source."""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import socket
import stat
import subprocess
import sys
import uuid

ROOT=Path('/home/fasakha/public_html')
BACKUPS=Path('/home/fasakha/desktop-dashboard-release-backups')
AUDITS=Path('/var/lib/go-erp-publication-link-finalization')
UID,GID=1001,1003
B3='75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
OWNER='deployment/repair_go_erp_publication_ownership_root.py'
OWNER_BLOB='6ceff5c798411e42ca4d7673764eb72ded3ed2e6'
MANIFEST_BLOB='da47b5e5dc50676d6046a6511b3905a0cd053ed1'
SELF='deployment/finalize_go_erp_publication_links_root.py'
REV=re.compile(r'[a-f0-9]{40}\Z')
SERVER=re.compile(r'server-[A-Za-z0-9_-]{6,32}\Z')
ALIAS=re.compile(r'published-[A-Za-z0-9_-]{6,32}\Z')
CAPTURE=re.compile(r'\.go-erp-link-capture-[a-f0-9]{32}\Z')

class Stop(Exception):
    def __init__(self,code,audit=None):super().__init__(code);self.audit=audit

def need(ok,code):
    if not ok:raise Stop(code)

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def key(s):return (s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns)
def dkey(s):return (s.st_dev,s.st_ino,s.st_mode,s.st_uid,s.st_gid)

class Dirs:
    def __init__(self,path,owner=None,mode=None):
        need(path.is_absolute() and '..'not in path.parts,'DIRECTORY_UNSAFE');self.rows=[]
        fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        self.rows.append((fd,os.fstat(fd),None,None))
        try:
            for part in path.parts[1:]:
                parent=fd;fd=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=parent);s=os.fstat(fd)
                self.rows.append((fd,s,parent,part))
                actor=(s.st_uid,s.st_gid)==(UID,GID) and not s.st_mode&0o002
                system=s.st_uid==0 and (not s.st_mode&0o022 or s.st_mode&stat.S_ISVTX)
                need(stat.S_ISDIR(s.st_mode) and (actor or system),'DIRECTORY_UNSAFE')
            self.fd=fd;s=os.fstat(fd)
            if owner is not None:need((s.st_uid,s.st_gid)==owner,'DIRECTORY_OWNER_INVALID')
            if mode is not None:need(stat.S_IMODE(s.st_mode)==mode,'DIRECTORY_MODE_INVALID')
            self.verify()
        except BaseException:self.close();raise
    def verify(self):
        for fd,s,parent,name in self.rows:
            need(dkey(os.fstat(fd))==dkey(s),'DIRECTORY_CHANGED')
            if parent is not None:need(dkey(os.stat(name,dir_fd=parent,follow_symlinks=False))==dkey(s),'DIRECTORY_CHANGED')
    def close(self):
        for fd,*_ in reversed(self.rows):os.close(fd)
        self.rows=[]

def read_at(directory,name,limit=2*1024*1024,owner=None,mode=None):
    need('/'not in name and name not in('.','..'),'FILE_SCOPE_INVALID');directory.verify()
    fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
    try:
        s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and 0<s.st_size<=limit,'PRIVATE_FILE_UNSAFE')
        if owner is not None:need((s.st_uid,s.st_gid)==owner,'PRIVATE_FILE_OWNER_INVALID')
        if mode is not None:need(stat.S_IMODE(s.st_mode)==mode,'PRIVATE_FILE_MODE_INVALID')
        data=bytearray()
        while len(data)<=limit:
            part=os.read(fd,min(65536,limit+1-len(data)))
            if not part:break
            data.extend(part)
        need(len(data)==s.st_size and key(s)==key(os.fstat(fd))==key(os.stat(name,dir_fd=directory.fd,follow_symlinks=False))
             and os.fstat(fd).st_nlink==1,'PRIVATE_FILE_CHANGED');directory.verify();return bytes(data)
    finally:os.close(fd)

def write_at(directory,name,data):
    directory.verify();fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=directory.fd)
    try:
        need(os.fstat(fd).st_uid==0 and os.fstat(fd).st_nlink==1,'AUDIT_FILE_UNSAFE')
        offset=0
        while offset<len(data):
            n=os.write(fd,data[offset:]);need(n>0,'AUDIT_WRITE_FAILED');offset+=n
        os.fsync(fd)
    finally:os.close(fd)
    os.fsync(directory.fd)

def json_at(directory,name,value):write_at(directory,name,(json.dumps(value,sort_keys=True)+'\n').encode())

def validate_backup(backup,manifest):
    directory=Dirs(backup,(UID,GID),0o700)
    try:
        receipt=json.loads(read_at(directory,'receipt.json',owner=(UID,GID),mode=0o600))
        need(isinstance(receipt,dict) and set(receipt)=={'version','git_head','before_source','after_source','manifest_blob','database','code_archive','files'}
             and type(receipt['version'])is int and receipt['version']==1 and receipt['git_head']==manifest['expected_head']
             and receipt['before_source']==manifest['before_source'] and receipt['after_source']==manifest['after_source']
             and receipt['manifest_blob']==MANIFEST_BLOB,'SUCCESS_BACKUP_RECEIPT_INVALID')
        files=receipt['files'];need(isinstance(files,dict),'SUCCESS_BACKUP_ROSTER_INVALID')
        routes=set(files)&{'bootstrap/cache/routes.php','bootstrap/cache/routes-v7.php'}
        need(len(routes)==1 and set(files)=={r['path']for r in manifest['sources']}|{'.env','bootstrap/cache/config.php'}|routes,'SUCCESS_BACKUP_ROSTER_INVALID')
        for row in manifest['sources']:
            previous=files[row['path']]
            need((previous is None)==(row['before_blob']is None)
                 and(previous is None or isinstance(previous,dict) and previous.get('blob')==row['before_blob']),'SUCCESS_BACKUP_SOURCE_INVALID')
        activated=json.loads(read_at(directory,'activated.json',owner=(UID,GID),mode=0o600))
        need(activated=={'source':manifest['after_source'],'authentication_checked':4,'enabled':True,'local':False,'web_authentication_checked':4}
             and type(activated['authentication_checked'])is int and type(activated['web_authentication_checked'])is int
             and activated['enabled']is True and activated['local']is False,'SUCCESS_BACKUP_ACTIVATION_INVALID')
        directory.verify();return receipt
    finally:directory.close()

def live_bytes(fd,before,links):
    s=os.fstat(fd);need(key(s)==key(before) and s.st_nlink==links,'LIVE_SOURCE_CHANGED')
    os.lseek(fd,0,os.SEEK_SET);data=bytearray()
    while len(data)<=8*1024*1024:
        part=os.read(fd,65536)
        if not part:break
        data.extend(part)
    need(0<len(data)<=8*1024*1024 and len(data)==before.st_size and key(os.fstat(fd))==key(before)
         and os.fstat(fd).st_nlink==links,'LIVE_SOURCE_CHANGED');return bytes(data)

def scan_aliases(directory):
    directory.verify();names=os.listdir(directory.fd);need(len(names)<=2000,'PUBLICATION_COUNT_LIMIT');result={}
    for name in names:
        s=os.stat(name,dir_fd=directory.fd,follow_symlinks=False)
        need(stat.S_ISREG(s.st_mode) and (s.st_uid,s.st_gid)==(UID,GID),'PUBLICATION_ENTRY_UNSAFE')
        # Metadata only: displaced originals may contain private configuration.
        if ALIAS.fullmatch(name):result.setdefault((s.st_dev,s.st_ino),[]).append((name,s))
    directory.verify();return result

def plan(root,backup,manifest):
    rows=[];parents={};pub=None
    try:
        pub=Dirs(backup/'publications',(UID,GID),0o700);aliases=scan_aliases(pub)
        new=[r for r in manifest['sources']if r['before_blob']is None]
        need(0<len(new)<=2000,'NEW_SOURCE_ROSTER_INVALID')
        for row in new:
            name=row['path'];need(isinstance(name,str) and re.fullmatch(r'(?:app|bootstrap|config|database|desktop|resources|routes|public)/[A-Za-z0-9_.@/-]+|artisan',name)
                                 and '..'not in Path(name).parts and REV.fullmatch(row['after_blob']),'NEW_SOURCE_PATH_INVALID')
            directory=parents.setdefault(str((root/name).parent),None)
            if directory is None:directory=Dirs((root/name).parent);parents[str((root/name).parent)]=directory
            fd=os.open(Path(name).name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
            entry={'path':name,'fd':fd,'directory':directory,'name':Path(name).name};rows.append(entry)
            s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and (s.st_uid,s.st_gid)==(UID,GID)
                               and stat.S_IMODE(s.st_mode)==0o644 and s.st_nlink in(1,2),'LIVE_SOURCE_METADATA_INVALID')
            need(key(s)==key(os.stat(entry['name'],dir_fd=directory.fd,follow_symlinks=False)),'LIVE_SOURCE_CHANGED')
            data=live_bytes(fd,s,s.st_nlink);need(blob(data)==row['after_blob'],'LIVE_SOURCE_PIN_INVALID')
            found=aliases.get((s.st_dev,s.st_ino),[])
            need(len(found)==(1 if s.st_nlink==2 else 0),'PRIVATE_ALIAS_NOT_UNIQUE')
            if found:need(found[0][1].st_nlink==2 and key(found[0][1])==key(s),'PRIVATE_ALIAS_CHANGED')
            entry.update(before=s,blob=row['after_blob'],alias=found[0][0]if found else None)
        for directory in parents.values():directory.verify()
        return rows,parents,pub
    except BaseException:
        for row in rows:os.close(row['fd'])
        for directory in parents.values():directory.close()
        if pub:pub.close()
        raise

def verify_row(row,pub,links):
    row['directory'].verify();pub.verify()
    need(key(os.stat(row['name'],dir_fd=row['directory'].fd,follow_symlinks=False))==key(row['before']),'LIVE_SOURCE_CHANGED')
    need(blob(live_bytes(row['fd'],row['before'],links))==row['blob'],'LIVE_SOURCE_PIN_INVALID')
    if links==2:
        aliases=scan_aliases(pub).get((row['before'].st_dev,row['before'].st_ino),[])
        need(len(aliases)==1 and aliases[0][0]==row['alias'] and aliases[0][1].st_nlink==2
             and key(aliases[0][1])==key(row['before']),'PRIVATE_ALIAS_CHANGED')

@contextlib.contextmanager
def critical():
    previous=signal.pthread_sigmask(signal.SIG_BLOCK,{signal.SIGINT,signal.SIGTERM})
    try:yield
    finally:signal.pthread_sigmask(signal.SIG_SETMASK,previous)

def audit_parent(path):
    try:os.mkdir(path,0o700)
    except FileExistsError:pass
    return Dirs(path,(0,0),0o700)

def leftover_captures(parent):
    directory=Dirs(parent,(UID,GID),0o700)
    try:
        names=os.listdir(directory.fd);need(len(names)<=2000,'BACKUP_COUNT_LIMIT')
        for name in names:
            if not CAPTURE.fullmatch(name):continue
            capture=Dirs(parent/name,(0,0),0o700)
            try:
                saved=json.loads(read_at(capture,'plan.json',owner=(0,0),mode=0o600))
                need(isinstance(saved,dict) and set(saved)=={'format','b3','manifest_blob','backup','capture_directory','rows','audit'}
                     and type(saved['format'])is int and saved['format']==1 and saved['b3']==B3
                     and saved['manifest_blob']==MANIFEST_BLOB and saved['capture_directory']==str(parent/name)
                     and isinstance(saved['backup'],str) and Path(saved['backup']).parent==parent
                     and SERVER.fullmatch(Path(saved['backup']).name)
                     and isinstance(saved['audit'],str) and Path(saved['audit']).is_absolute()
                     and isinstance(saved['rows'],list) and 0<len(saved['rows'])<=2000,'CAPTURE_BINDING_INVALID')
                need(set(os.listdir(capture.fd))=={'plan.json'},'PENDING_CAPTURE_REQUIRES_PRIVATE_REVIEW')
            finally:capture.close()
        directory.verify()
    finally:directory.close()

def finalize(root,backup,manifest,requested=False,audit_parent=AUDITS,source_guard=lambda:None,
             before_unlink=lambda row:None,after_unlink=lambda row:None):
    source_guard();validate_backup(backup,manifest);leftover_captures(backup.parent)
    rows,parents,pub=plan(root,backup,manifest);audit=None;capture=None;copies=None;parent=None;capture_parent=None
    try:
        pending=[r for r in rows if r['alias']is not None]
        for row in rows:verify_row(row,pub,2 if row['alias']else 1)
        source_guard()
        if not requested or not pending:return {'pending':len(pending),'changed':0,'already_clean':len(rows)-len(pending),'audit':None}
        parent=globals()['audit_parent'](audit_parent)
        audit_path=audit_parent/('finalize-'+uuid.uuid4().hex);os.mkdir(audit_path,0o700)
        audit=Dirs(audit_path,(0,0),0o700);os.mkdir('copies',0o700,dir_fd=audit.fd);copies=Dirs(audit_path/'copies',(0,0),0o700)
        capture_path=backup.parent/('.go-erp-link-capture-'+uuid.uuid4().hex)
        os.mkdir(capture_path,0o700);capture=Dirs(capture_path,(0,0),0o700)
        need(os.fstat(capture.fd).st_dev==os.fstat(pub.fd).st_dev,'CAPTURE_FILESYSTEM_INVALID')
        mapping=[]
        for index,row in enumerate(pending):
            verify_row(row,pub,2);copy='copies/{:04d}.bin'.format(index)
            data=live_bytes(row['fd'],row['before'],2);write_at(copies,Path(copy).name,data)
            mapping.append({'path':row['path'],'alias':row['alias'],'blob':row['blob'],'copy':copy,
                'device':row['before'].st_dev,'source_inode':row['before'].st_ino,'mode':'0644',
                'mtime_ns':row['before'].st_mtime_ns,'bytes':len(data),'capture':'captured-{:04d}.bin'.format(index)})
        record={'format':1,'b3':B3,'manifest_blob':MANIFEST_BLOB,'backup':str(backup),'capture_directory':str(capture_path),'rows':mapping}
        json_at(audit,'planned.json',record);json_at(capture,'plan.json',{**record,'audit':str(audit_path)})
        os.fsync(parent.fd)
        capture_parent=Dirs(backup.parent,(UID,GID),0o700);os.fsync(capture_parent.fd)
        # Every independent complete copy and plan is durable before the first alias moves.
        for row in rows:verify_row(row,pub,2 if row['alias']else 1)
        source_guard();changed=0
        try:
            for index,(row,mapped)in enumerate(zip(pending,mapping)):
                before_unlink(row)
                with critical():
                    verify_row(row,pub,2);capture.verify();capture_parent.verify()
                    os.rename(row['alias'],mapped['capture'],src_dir_fd=pub.fd,dst_dir_fd=capture.fd)
                    os.fsync(pub.fd);os.fsync(capture.fd)
                    # The captured name now belongs to a root-only directory. A raced
                    # foreign alias is retained there, never unlinked or overwritten.
                    fd=os.open(mapped['capture'],os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=capture.fd)
                    try:
                        held=os.fstat(fd)
                        need(key(held)==key(row['before']) and held.st_nlink==2,'CAPTURED_ALIAS_UNEXPECTED')
                        need(blob(live_bytes(fd,row['before'],2))==row['blob'],'CAPTURED_ALIAS_UNEXPECTED')
                        need(key(os.stat(mapped['capture'],dir_fd=capture.fd,follow_symlinks=False))==key(held),'CAPTURED_ALIAS_CHANGED')
                        need(blob(live_bytes(row['fd'],row['before'],2))==row['blob'],'LIVE_SOURCE_CHANGED')
                        os.unlink(mapped['capture'],dir_fd=capture.fd);os.fsync(capture.fd)
                    finally:os.close(fd)
                    verify_row(row,pub,1);after_unlink(row)
                    json_at(audit,'completed-{:04d}.json'.format(index),{'format':1,'path':row['path'],'inode':row['before'].st_ino,'links':1})
                    changed+=1
            for row in rows:verify_row(row,pub,1)
            source_guard();json_at(audit,'completed.json',{'format':1,'changed':changed,'source_after_verified':True,'source_files_written':False})
            return {'pending':0,'changed':changed,'already_clean':len(rows)-len(pending),'audit':str(audit_path)}
        except BaseException:
            try:json_at(audit,'stopped.json',{'format':1,'completed':changed,'private_copies_retained':True,'automatic_relink_attempted':False})
            except BaseException:pass
            raise Stop('FINALIZATION_STOPPED_REVIEW_PRIVATE_AUDIT',str(audit_path))from None
    finally:
        for row in rows:os.close(row['fd'])
        for directory in parents.values():directory.close()
        for directory in(pub,audit,capture,copies,parent,capture_parent):
            if directory:directory.close()

def bootstrap_git(args,maximum=2*1024*1024):
    env={'PATH':'/usr/bin:/bin','HOME':'/home/fasakha','GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1','GIT_OPTIONAL_LOCKS':'0','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null'}
    result=subprocess.run(['/usr/sbin/runuser','-u','fasakha','--','/usr/bin/env','-i',*[k+'='+v for k,v in env.items()],'/usr/bin/git','--no-optional-locks','-c','core.fsmonitor=false','-c','core.hooksPath=/dev/null','-C',str(ROOT),*args],env=env,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30)
    need(result.returncode==0 and len(result.stdout)<=maximum and len(result.stderr)<=65536,'REVIEWED_GIT_UNAVAILABLE');return result.stdout

def select_backup(manifest,root):
    parent=Dirs(BACKUPS,(UID,GID),0o700)
    try:
        names=os.listdir(parent.fd);need(len(names)<=2000,'BACKUP_COUNT_LIMIT');candidates=[]
        for name in names:
            if SERVER.fullmatch(name):
                s=os.stat(name,dir_fd=parent.fd,follow_symlinks=False)
                need(stat.S_ISDIR(s.st_mode) and(s.st_uid,s.st_gid)==(UID,GID) and stat.S_IMODE(s.st_mode)==0o700,'BACKUP_DIRECTORY_UNSAFE')
                candidates.append((s.st_mtime_ns,name))
        matches=[]
        for _,name in sorted(candidates,reverse=True)[:20]:
            path=BACKUPS/name
            try:
                validate_backup(path,manifest);rows,parents,pub=plan(root,path,manifest)
            except(FileNotFoundError,Stop,ValueError,KeyError):continue
            try:matches.append(path)
            finally:
                for row in rows:os.close(row['fd'])
                for directory in parents.values():directory.close()
                pub.close()
        need(len(matches)==1,'SUCCESSFUL_BACKUP_NOT_UNIQUE');parent.verify();return matches[0]
    finally:parent.close()

def execute(args):
    need(len(args)in(2,3) and args[0]=='--release' and REV.fullmatch(args[1]) and args[2:]in([],['--finalize']),'PINNED_INVOCATION_REQUIRED')
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    actor=pwd.getpwnam('fasakha');need((actor.pw_uid,actor.pw_gid)==(UID,GID) and ROOT.resolve()==ROOT,'PROJECT_ACCOUNT_UNSAFE')
    project=Dirs(ROOT,(UID,GID));repository=Dirs(ROOT/'.git',(UID,GID))
    project.close();repository.close()
    data=bootstrap_git(['cat-file','blob',OWNER_BLOB]);need(blob(data)==OWNER_BLOB,'OWNER_HELPER_PIN_INVALID')
    owner={'__name__':'reviewed_link_finalization_owner'};exec(compile(data,OWNER,'exec'),owner)
    need(owner['source_file'](args[1],OWNER,OWNER_BLOB)==data,'OWNER_HELPER_NOT_IN_RELEASE')
    owner['git'](['merge-base','--is-ancestor',B3,args[1]],1024);context=owner['reviewed_context']()
    need(context['complete']['private_entry'](Path(__file__).absolute())==owner['source_file'](args[1],SELF),'ENTRYPOINT_NOT_REVIEWED')
    manifest=context['manifest'];new={r['path']for r in manifest['sources']if r['before_blob']is None};need(len(new)==162,'EXACT_NEW_SOURCE_ROSTER_REQUIRED')
    head=lambda:owner['git'](['rev-parse','--verify','HEAD'],64).decode('ascii').strip()
    def guard():
        c=context['complete'];need(c['classifier'](ROOT,manifest,context['scanner'],context['source'],head)=='AFTER','EXACT_AFTER_SOURCE_REQUIRED')
        snapshot=context['permission']['take_snapshot'](ROOT,manifest,context['scanner'],context['source'])
        metadata={**snapshot['metadata'],**{name:value[1]for name,value in snapshot['gui'].items()if value is not None}}
        for name,item in metadata.items():
            need(stat.S_ISREG(item[2]) and(item[3],item[4])in((0,0),(UID,GID)) and not item[2]&0o002
                 and item[5]in((1,2)if name in new else(1,)),'UNREVIEWED_SOURCE_METADATA')
    lock_parent=Dirs(BACKUPS,(UID,GID),0o700)
    try:
        fd=os.open('server-install.lock',os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=lock_parent.fd)
        try:
            s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and(s.st_uid,s.st_gid,s.st_nlink,stat.S_IMODE(s.st_mode))==(UID,GID,1,0o600),'INSTALL_LOCK_UNSAFE')
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise Stop('INSTALLATION_ALREADY_RUNNING')from None
            guard();backup=select_backup(manifest,ROOT)
            result=finalize(ROOT,backup,manifest,args[2:]==['--finalize'],AUDITS,guard)
            need(key(os.fstat(fd))==key(s) and key(os.stat('server-install.lock',dir_fd=lock_parent.fd,follow_symlinks=False))==key(s),'INSTALL_LOCK_CHANGED')
            return {'format':1,'kind':'go-erp-publication-links','source_state':'AFTER','backup':str(backup),'application_contents_changed':False,'database_changed':False,**result}
        finally:os.close(fd)
    finally:lock_parent.close()

def main(args=None,out=sys.stdout,err=sys.stderr):
    try:
        result=execute(sys.argv[1:]if args is None else args);print(json.dumps(result,sort_keys=True,separators=(',',':')),file=out);return 0
    except Exception as error:
        code=str(error)if isinstance(error,Stop) and re.fullmatch('[A-Z_]{1,80}',str(error))else'PRIVATE_FINALIZATION_FAILURE'
        print('GO_ERP_PUBLICATION_LINKS_STOPPED REASON='+code,file=err)
        if isinstance(error,Stop) and error.audit:print('AUDIT_DIRECTORY='+error.audit,file=err)
        return 1

if __name__=='__main__':sys.exit(main())
