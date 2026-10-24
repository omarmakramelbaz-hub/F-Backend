#!/usr/bin/env python3
"""Install the server download-button flow after proving the genuine preview42."""
import argparse
from contextlib import ExitStack
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
import types
import uuid

ROOT=Path('/home/fasakha/public_html')
WORK=Path('/var/lib/go-erp-live-updates')
CONTROL=Path('/var/lib/go-erp-manual-updates')
JS='public/dashboard/js/go-erp-releases.js'
BEFORE='fda039de332a8cb58cca490a79b22225f5431c1eeacd910050b65791e123706a'
AFTER='4281eac90700dad9fedb27d14209745f186481f607be27923605b9f2de6c7b02'
ENGINE_SHA='0a6da17799e88b45061442f81c730e32cdb1822ae68b2bd2f659b14d9f0610d9'
ARCHIVE_SHA='2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'
VERSION='0.4.4-preview.42'
COMMIT='26c6f8b6d80ab8da05b88a00789483a51a4c9a2a'
INSTALLER='f918e811ca501612d75b196de56cfe18fad9fac547ad79dc791b38928aac5b9f'
BYTES=415949308
SEQUENCE=40054814945
SOURCE={'format':1,'files':1355,'framework':'8.83.29','sha256':'89ab1a441e26e1d0893b1615fa64d3697d98f13d4afa0605b743b882374b65d1'}
SERVICE='go-erp-manual-updates.service'
WATCH='go-erp-manual-updates.path'
NATIVE_CORE='6f5133af48ae03d626f0ac6ee02ff3a724c72407'
NATIVE_SEED='710a11953f03aaf8139c2745f4ed11e0a3d78efc'


class Stop(Exception):pass

def need(value,code):
    if not value:raise Stop(code)

def sha(raw):return hashlib.sha256(raw).hexdigest()

def load(raw,name,pin):
    need(sha(raw)==pin,'MODULE_PIN_INVALID')
    value=types.ModuleType(name);value.__file__='<pinned-'+name+'>';sys.modules[name]=value
    exec(compile(raw,value.__file__,'exec'),value.__dict__);value.Stop=Stop
    return value


def quiet(e,control=None):
    control=control or CONTROL
    for unit in ('go-erp-live-updates.service',SERVICE):
        need(e.run(['/usr/bin/systemctl','show',unit,'-p','ActiveState','--value']) in ('inactive','failed'),'EXISTING_JOB_BUSY')
    need(e.run(['/usr/bin/systemctl','show','go-erp-live-updates.timer','-p','ActiveState','--value'])=='inactive'
        and e.run(['/usr/bin/systemctl','show','go-erp-live-updates.timer','-p','UnitFileState','--value'])
            in ('disabled','masked'),'AUTOMATIC_TIMER_NOT_DISABLED')
    need(e.read_file(control/'private/active.json',uid=0,modes=(0o600,),optional=True) is None,'UNFINISHED_MANUAL_JOB_BUSY')


def genuine42(publisher,state):
    need(isinstance(state,dict) and isinstance(state.get('published_history'),list)
        and len(state['published_history'])<=10000,'PUBLICATION_HISTORY_INVALID')
    records=list(state['published_history'])
    if isinstance(state.get('published'),dict):records.append(state['published'].get('release'))
    matches=[]
    for raw in records:
        if not isinstance(raw,dict) or raw.get('version')!=VERSION:continue
        release=publisher.validate_release(raw)
        if (release['source_revision']==COMMIT and release['source']==SOURCE and release['sequence']==SEQUENCE
                and release['installer']['sha256']==INSTALLER and release['installer']['bytes']==BYTES):
            matches.append(release)
    need(matches and all(value==matches[0] for value in matches),'GENUINE42_RELEASE_NOT_PROVEN')
    return matches[0]


class Binary:
    def __init__(self,e,path,optional=True):
        self.e,self.path,self.fd,self.parent=e,Path(path),None,None
        try:
            self.parent=e.parent_fd(self.path)
            try:info=os.stat(self.path.name,dir_fd=self.parent,follow_symlinks=False)
            except FileNotFoundError:
                if optional:return
                raise Stop('INSTALLER_SOURCE_MISSING') from None
            need(stat.S_ISREG(info.st_mode) and info.st_uid==0 and info.st_nlink==1
                and stat.S_IMODE(info.st_mode) in (0o600,0o640,0o644)
                and 0<info.st_size<=1024**3,'INSTALLER_SOURCE_UNSAFE')
            self.info=info;self.fd=os.open(self.path.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=self.parent)
            self.verify();digest=hashlib.sha256();total=0
            while True:
                block=os.read(self.fd,1024*1024)
                if not block:break
                digest.update(block);total+=len(block)
            self.verify();need(total==info.st_size,'INSTALLER_SOURCE_CHANGED')
            self.digest=digest.hexdigest();self.valid=total==BYTES and self.digest==INSTALLER
            os.lseek(self.fd,0,os.SEEK_SET)
        except BaseException:
            self.close();raise
    def verify(self):
        need(self.e.identity(self.info)==self.e.identity(os.fstat(self.fd))
            ==self.e.identity(os.stat(self.path.name,dir_fd=self.parent,follow_symlinks=False)),
            'INSTALLER_SOURCE_CHANGED')
        named=self.e.parent_fd(self.path)
        try:need(self.e.identity(os.fstat(named))[:5]==self.e.identity(os.fstat(self.parent))[:5],'INSTALLER_PARENT_CHANGED')
        finally:os.close(named)
    def close(self):
        if self.fd is not None:os.close(self.fd);self.fd=None
        if self.parent is not None:os.close(self.parent);self.parent=None


def sources(e,root,work,shared):
    yield 'archive',shared/'installers'/(INSTALLER+'.exe')
    yield 'canonical',root/'storage/app/desktop-updates'/(INSTALLER+'.exe')
    parent=work/'program-acceptance'
    try:parent.lstat()
    except FileNotFoundError:return
    e.directory(parent,0,0,0o700,False)
    entries=list(parent.iterdir());need(len(entries)<=1000,'ACCEPTED_SOURCE_ROSTER_LIMIT')
    for folder in sorted(entries):
        if not re.fullmatch(COMMIT+r'-[A-Za-z0-9_-]{1,80}',folder.name):continue
        e.directory(folder,0,0,0o700,False)
        try:(folder/'ci').lstat()
        except FileNotFoundError:continue
        e.directory(folder/'ci',0,0,0o700,False)
        yield 'accepted-ci',folder/'ci/GO-ERP-Preview-Setup.exe'


def copy_verified(e,source,target,gid,expected):
    source.verify();need(source.valid,'SOURCE42_NOT_VERIFIED')
    parent=e.parent_fd(target);temporary='.verified42-'+uuid.uuid4().hex
    try:
        if expected is None:
            try:os.stat(target.name,dir_fd=parent,follow_symlinks=False)
            except FileNotFoundError:pass
            else:raise Stop('ARCHIVE_BINARY_CHANGED')
        else:
            need(e.identity(os.stat(target.name,dir_fd=parent,follow_symlinks=False))==e.identity(expected),'ARCHIVE_BINARY_CHANGED')
        fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=parent)
        digest=hashlib.sha256();total=0;os.lseek(source.fd,0,os.SEEK_SET)
        with os.fdopen(fd,'wb') as output:
            while True:
                block=os.read(source.fd,1024*1024)
                if not block:break
                output.write(block);digest.update(block);total+=len(block)
            need(total==BYTES and digest.hexdigest()==INSTALLER,'SOURCE42_CHANGED')
            source.verify();output.flush();os.fchown(output.fileno(),0,gid);os.fchmod(output.fileno(),0o640);os.fsync(output.fileno())
        if expected is not None:
            need(e.identity(os.stat(target.name,dir_fd=parent,follow_symlinks=False))==e.identity(expected),'ARCHIVE_BINARY_CHANGED')
        else:
            try:os.stat(target.name,dir_fd=parent,follow_symlinks=False)
            except FileNotFoundError:pass
            else:raise Stop('ARCHIVE_BINARY_CHANGED')
        os.replace(temporary,target.name,src_dir_fd=parent,dst_dir_fd=parent);os.fsync(parent)
    finally:
        try:os.unlink(temporary,dir_fd=parent)
        except FileNotFoundError:pass
        os.close(parent)


def repair42(e,archive,owner,audit,root=ROOT,work=WORK,control=CONTROL):
    shared=control/'shared';e.directory(shared,0,owner.pw_gid,0o750,False)
    e.directory(shared/'installers',0,owner.pw_gid,0o750,False)
    publisher=archive.load_publisher()
    path=shared/'downloads.json';before=e.read_file(path,1024*1024,uid=0,modes=(0o640,))
    need(before[1].st_gid==owner.pw_gid,'DOWNLOAD_MANIFEST_GROUP_INVALID')
    value=archive.parse(before[0]);need(isinstance(value,dict) and set(value)=={'format','kind','current','previous'}
        and type(value.get('format')) is int and value['format']==1 and value['kind']=='go-erp-published-downloads',
        'DOWNLOAD_MANIFEST_INVALID')
    slots=[slot for slot in ('current','previous') if isinstance(value[slot],dict)
        and value[slot].get('version')==VERSION]
    wanted=dict(value)
    if slots:
        state_raw=e.read_file(work/'state/current.json',16*1024**2,uid=0,modes=(0o600,))
        release=genuine42(publisher,archive.parse(state_raw[0]))
        for slot in slots:wanted[slot]=release
    # Validate the resulting genuine pair before changing bytes or pointers.
    archive.manifest(publisher,wanted)
    if wanted['previous'] is not None:
        need(wanted['current']['source_revision']!=wanted['previous']['source_revision']
            and wanted['current']['installer']['sha256']!=wanted['previous']['installer']['sha256'],
            'DOWNLOAD_PAIR_IDENTITY_CONFLICT')
    for record in (wanted['current'],wanted['previous']):
        if record is None or record.get('version')==VERSION:continue
        archive.verified_archive_binary(publisher,shared/'installers',record,owner.pw_gid)
    if not slots:
        return {'version':VERSION,'verified':False,'source_revision':None,'installer_sha256':None,
            'installer_bytes':None,'in_download_slots':False,'binary_repaired':False,'manifest_repaired':False,
            'source_kind':'not-referenced','current_download_version':wanted['current']['version']}
    e.atomic_write(audit/'before-downloads.json',before[0],0,0,0o600)
    target=shared/'installers'/(INSTALLER+'.exe');target_info=None;target_valid=False
    inspected=Binary(e,target)
    try:
        if inspected.fd is not None:
            target_info=inspected.info;target_valid=inspected.valid
            e.atomic_write(audit/'installer-preimage.json',e.canonical({'sha256':inspected.digest,'bytes':inspected.info.st_size,
                'mode':stat.S_IMODE(inspected.info.st_mode),'gid':inspected.info.st_gid}),0,0,0o600)
        source_kind=None;selected=None
        if target_valid:source_kind='archive'
        else:
            for kind,candidate in sources(e,root,work,shared):
                if candidate==target:continue
                try:opened=Binary(e,candidate)
                except FileNotFoundError:continue
                if opened.fd is not None and opened.valid:selected=opened;source_kind=kind;break
                opened.close()
            need(selected is not None,'SOURCE42MISSING')
            try:copy_verified(e,selected,target,owner.pw_gid,target_info)
            finally:selected.close()
        # A correct digest with incorrect archived mode/group still needs
        # repair without trusting any newly introduced installer bytes.
        if target_valid and (stat.S_IMODE(target_info.st_mode)!=0o640 or target_info.st_gid!=owner.pw_gid):
            copy_verified(e,inspected,target,owner.pw_gid,target_info);target_valid=False
        current=e.read_file(path,1024*1024,uid=0,modes=(0o640,))
        need(current[0]==before[0] and e.identity(current[1])==e.identity(before[1]),'DOWNLOAD_MANIFEST_CHANGED')
        need(e.read_file(work/'state/current.json',16*1024**2,uid=0,modes=(0o600,))[0]==state_raw[0],
            'NATIVE_STATE_CHANGED')
        changed=wanted!=value
        if changed:e.atomic_write(path,archive.canonical(wanted),0,owner.pw_gid,0o640)
        check=Binary(e,target,False)
        try:need(check.valid and stat.S_IMODE(check.info.st_mode)==0o640 and check.info.st_gid==owner.pw_gid,'ARCHIVED42_VERIFY_FAILED')
        finally:check.close()
        return {'version':VERSION,'verified':True,'source_revision':COMMIT,'installer_sha256':INSTALLER,'installer_bytes':BYTES,
            'in_download_slots':bool(slots),'binary_repaired':not target_valid,'manifest_repaired':changed,
            'source_kind':source_kind,'current_download_version':wanted['current']['version']}
    finally:inspected.close()


def write_js(e,owner,payload,audit,root=ROOT):
    path=root/JS;before=e.read_file(path,2*1024**2,uid=owner.pw_uid,modes=(0o644,))
    need(sha(before[0]) in (BEFORE,AFTER) and sha(payload)==AFTER,'JS_BASELINE_OR_PAYLOAD_INVALID')
    if before[0]==payload:return False
    info=before[1];e.atomic_write(audit/'before-js.js',before[0],0,0,0o600)
    e.atomic_write(audit/'before-js-metadata.json',e.canonical({'uid':info.st_uid,'gid':info.st_gid,
        'mode':stat.S_IMODE(info.st_mode),'atime_ns':info.st_atime_ns,'mtime_ns':info.st_mtime_ns}),0,0,0o600)
    try:
        current=e.read_file(path,2*1024**2,uid=owner.pw_uid,modes=(0o644,))
        need(current[0]==before[0] and e.identity(current[1])==e.identity(info),'JS_CHANGED')
        e.atomic_write(path,payload,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),True)
        need(e.read_file(path,2*1024**2,uid=owner.pw_uid,modes=(0o644,))[0]==payload,'JS_VERIFY_FAILED')
    except BaseException:
        try:
            current=e.read_file(path,2*1024**2,uid=owner.pw_uid,modes=(0o644,))
            need(current[0] in (before[0],payload) and current[1].st_gid==info.st_gid,'JS_ROLLBACK_REQUIRES_REVIEW')
            if current[0]!=before[0]:e.atomic_write(path,before[0],info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode))
            fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
            try:os.utime(fd,ns=(info.st_atime_ns,info.st_mtime_ns));os.fsync(fd)
            finally:os.close(fd)
        except BaseException:
            raise Stop('JS_ROLLBACK_REQUIRES_REVIEW') from None
        raise
    return True


def require_no_older_rollout(e,current,control=CONTROL):
    if sha(current)==AFTER:return
    path=control/'private'/('close-reopen-'+NATIVE_CORE+'.json')
    record=e.read_file(path,16*1024**2,uid=0,modes=(0o600,),optional=True)
    if record is None:return
    value=json.loads(record[0])
    need(isinstance(value,dict) and value.get('format')==1 and type(value['format']) is int
        and value.get('kind')=='go-erp-close-reopen-rollout' and value.get('core')==NATIVE_CORE
        and value.get('seed')==NATIVE_SEED and value.get('phase') in ('preparing','prepared','running','ready'),
        'NATIVE_ROLLOUT_JOURNAL_UNREVIEWED')
    need(value['phase']=='ready','FINISH_EXISTING_NATIVE_ROLLOUT_BEFORE_UI_CHANGE')


def perform(e,archive,owner,payload,release,root=ROOT,work=WORK,control=CONTROL):
    before=e.read_file(root/JS,2*1024**2,uid=owner.pw_uid,modes=(0o644,))
    need(sha(before[0]) in (BEFORE,AFTER) and sha(payload)==AFTER,'JS_BASELINE_OR_PAYLOAD_INVALID')
    audit=control/'private/audits'/('button-flow-'+release[:12]+'-'+uuid.uuid4().hex);audit.mkdir(mode=0o700)
    with ExitStack() as locks:
        e.acquire(locks,control/'shared/.downloads.lock')
        proof=repair42(e,archive,owner,audit,root,work,control)
        e.atomic_write(audit/'verified42.json',e.canonical(proof),0,0,0o600)
        require_no_older_rollout(e,before[0],control)
        changed=write_js(e,owner,payload,audit,root)
    e.atomic_write(audit/'completed.json',e.canonical({'ok':True,'source_changed':changed,'verified42':proof}),0,0,0o600)
    return {'source_changed':changed,'verified42':proof}


def apply(e,archive,owner,payload,release):
    quiet(e)
    with ExitStack() as install:
        e.acquire(install,CONTROL/'private/install.lock')
        was=e.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])
        need(was in ('active','inactive','failed'),'MANUAL_PATH_STATE_INVALID')
        recover=True
        try:
            e.run(['/usr/bin/systemctl','stop',WATCH])
            need(e.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])=='inactive','MANUAL_PATH_STILL_ACTIVE')
            quiet(e)
            with ExitStack() as work:
                e.acquire(work,CONTROL/'private/worker.lock');e.acquire(work,WORK/'watcher.lock')
                quiet(e);result=perform(e,archive,owner,payload,release)
        except BaseException as error:
            recover=str(error)!='JS_ROLLBACK_REQUIRES_REVIEW'
            raise
        finally:
            # Native/worker locks are closed before an existing owner request
            # is delivered. Enabled state is never modified.
            if recover and was=='active':
                e.run(['/usr/bin/systemctl','start',WATCH])
                need(e.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])=='active',
                    'MANUAL_PATH_RESTORE_FAILED')
    return result


def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--git-dir',required=True)
    parser.add_argument('--release',required=True);parser.add_argument('--apply',action='store_true');args=parser.parse_args()
    need(args.apply and os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524'
        and re.fullmatch('[a-f0-9]{40}',args.release),'ROOT_HOST_RELEASE_REQUIRED')
    os.umask(0o077);repository=Path(args.git_dir).absolute()
    need(repository.resolve()==repository and repository.is_dir() and repository.stat().st_uid==0
        and not repository.stat().st_mode&0o022,'REPOSITORY_UNSAFE')
    def git(*values):
        raw=subprocess.check_output(['/usr/bin/git','--git-dir='+str(repository),'-c','credential.helper=',
            '-c','core.hooksPath=/dev/null',*values],stderr=subprocess.DEVNULL,timeout=60,
            env={'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null',
                'GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_TERMINAL_PROMPT':'0'})
        need(len(raw)<=2*1024**2,'PACKAGE_LIMIT');return raw
    need(git('rev-parse',args.release+'^{commit}').decode().strip()==args.release,'RELEASE_INVALID')
    e=load(git('show',args.release+':deployment/apply_go_erp_manual_updates_root.py'),'button_flow_engine',ENGINE_SHA)
    archive=load(git('show',args.release+':deployment/go_erp_published_download_root.py'),'button_flow_archive',ARCHIVE_SHA)
    e.parents(repository);owner=pwd.getpwnam('fasakha')
    for path,uid,gid,mode in ((CONTROL,0,0,0o755),(CONTROL/'private',0,0,0o700),
            (CONTROL/'private/audits',0,0,0o700),(WORK,0,0,0o700)):
        e.directory(path,uid,gid,mode,False)
    result=apply(e,archive,owner,git('show',args.release+':'+JS),args.release)
    print(json.dumps({'format':1,'kind':'go-erp-button-flow-installed','ok':True,**result,
        'database_changed':False,'native_state_changed':False,'timer_changed':False,'automatic_updates_enabled':False}))
    print('GO_ERP_BUTTON_FLOW_READY_DOWNLOADS_CHECKED')


if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error) if isinstance(error,Stop) and re.fullmatch('[A-Z0-9_]{1,120}',str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format':1,'kind':'go-erp-button-flow-installed','ok':False,'code':'GO_ERP_BUTTON_FLOW_'+code,
            'database_changed':False,'native_state_changed':False,'timer_changed':False,'automatic_updates_enabled':False}))
        raise SystemExit(1)
