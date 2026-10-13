#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
"""Quiesce only the known pipelines around the pinned evidence publication."""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import select
import signal
import stat
import subprocess
import sys
import tempfile
import time

ROOT=pathlib.Path('/home/fasakha/public_html')
SYSTEMCTL='/usr/bin/systemctl'
PUBLISHER_BLOB='ad881bc4529ce112890f1e73f1c323ab6664ea60'
BASE_BLOB='f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
HELPERS={'apply_whatsapp_order_semantic_repair.py':PUBLISHER_BLOB,'install_whatsapp_inbox.py':BASE_BLOB}
UNITS=tuple('fasakhansta-whatsapp-'+v for v in ('capture.timer','capture.service','orders.timer','orders.service'))


class SafeError(Exception):pass


def command(args,timeout=60):
    try:
        result=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=timeout)
        if len(result.stdout)>8388608:return 1,b''
        return result.returncode,result.stdout
    except (OSError,subprocess.TimeoutExpired):return 1,b''


def blob(value):return hashlib.sha1(b'blob '+str(len(value)).encode()+b'\0'+value).hexdigest()


def owned_directory(path,uid,private=False):
    for parent in reversed(path.parents):
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0,uid):raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_PATH_UNSAFE')
    info=path.lstat()
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid!=uid
            or (private and stat.S_IMODE(info.st_mode)!=0o700)):
        raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_DIRECTORY_UNSAFE')


def stage_helpers(directory,release,uid,gid,runner=command):
    for name,digest in HELPERS.items():
        relative='deployment/'+name
        code,tree=runner(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'ls-tree','-z',release,relative])
        if code!=0 or tree!=b'100644 blob '+digest.encode()+b'\t'+relative.encode()+b'\0':
            raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_HELPER_TREE_MISMATCH')
        code,content=runner(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'show',release+':'+relative])
        if code!=0 or not content or len(content)>1048576 or blob(content)!=digest:
            raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_HELPER_HASH_MISMATCH')
        path=directory/name;fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as stream:
            stream.write(content);stream.flush();os.fchown(stream.fileno(),uid,gid);os.fsync(stream.fileno())


def acquire_locks(parent,uid,gid):
    fds=[]
    try:
        for name in ('orders-install.lock','inbox-install.lock'):
            path=parent/name
            try:
                fd=os.open(path,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                os.fchown(fd,uid,gid)
            except FileExistsError:
                fd=os.open(path,os.O_RDWR|os.O_NOFOLLOW|os.O_NONBLOCK)
            fds.append(fd);info=os.fstat(fd);link=path.lstat()
            if (not stat.S_ISREG(info.st_mode) or info.st_uid!=uid or info.st_nlink!=1
                    or stat.S_IMODE(info.st_mode)!=0o600
                    or (info.st_dev,info.st_ino)!=(link.st_dev,link.st_ino)):
                raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_LOCK_UNSAFE')
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise SafeError('ORDER_SEMANTIC_REPAIR_ANOTHER_INSTALL_RUNNING') from None
        return fds
    except BaseException:
        for fd in fds:os.close(fd)
        raise


def call_publisher(directory,release,uid,gid,held_locks,check_only,expected=None,recovery_scope=None,backup=None,recover_only=False):
    read_fd,write_fd=os.pipe();pid=os.fork()
    if pid==0:
        os.close(read_fd)
        try:
            def interrupted(signum,frame):raise InterruptedError()
            signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
            os.setgroups([]);os.setgid(gid);os.setuid(uid);os.umask(0o077)
            path=directory/'apply_whatsapp_order_semantic_repair.py'
            fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
            try:
                info=os.fstat(fd);source=os.read(fd,1048577)
                if (not stat.S_ISREG(info.st_mode) or info.st_uid!=uid or info.st_nlink!=1
                        or stat.S_IMODE(info.st_mode)!=0o600 or blob(source)!=PUBLISHER_BLOB):
                    raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_STAGED_HELPER_CHANGED')
            finally:os.close(fd)
            name='verified_whatsapp_order_semantic_repair'
            module=importlib.util.module_from_spec(importlib.util.spec_from_loader(name,loader=None))
            module.__file__=str(path);sys.modules[name]=module
            exec(compile(source,str(path),'exec'),module.__dict__)
            if recover_only:
                result=module.recover(ROOT,uid,recovery_scope,expected,backup)
            else:
                result=module.apply(ROOT,uid,release,check_only,held_locks=held_locks,expected_configuration=expected)
                if check_only:
                    recovery=module.recovery_preflight(ROOT,uid)
                    result['recovery_pending']=recovery['pending'];result['recovery_scope']=recovery['scope_hash']
            value={'ok':True,'result':result}
        except BaseException as error:
            status=str(error) if (isinstance(error,SafeError) or
                    'module' in locals() and isinstance(error,module.SafeError)) else 'ORDER_SEMANTIC_REPAIR_CHILD_STOPPED'
            # Only fixed status codes and our own canonical private backup path may leave the child.
            if not re.fullmatch(r'[A-Z_]+(?:; BACKUP=/home/fasakha/whatsapp-release-backups/order-semantic-[a-z0-9_]+)?',status):
                status='ORDER_SEMANTIC_REPAIR_CHILD_STOPPED'
            value={'ok':False,'status':status}
        try:os.write(write_fd,json.dumps(value,separators=(',',':')).encode())
        finally:os.close(write_fd);os._exit(0)
    os.close(write_fd);chunks=[];total=0;deadline=time.monotonic()+240;waited=False
    try:
        while True:
            if time.monotonic()>deadline:
                raise SafeError('ORDER_SEMANTIC_REPAIR_CHILD_TIMEOUT')
            readable,_,_=select.select([read_fd],[],[],min(1,max(0,deadline-time.monotonic())))
            if not readable:continue
            data=os.read(read_fd,4096)
            if not data:break
            total+=len(data)
            if total>16384:
                raise SafeError('ORDER_SEMANTIC_REPAIR_CHILD_OUTPUT_INVALID')
            chunks.append(data)
        _,exit_status=os.waitpid(pid,0);waited=True
        value=json.loads(b''.join(chunks))
        if exit_status!=0 or not isinstance(value,dict) or type(value.get('ok')) is not bool:
            raise SafeError('ORDER_SEMANTIC_REPAIR_CHILD_OUTPUT_INVALID')
        return value
    finally:
        os.close(read_fd)
        if not waited:
            try:os.kill(pid,signal.SIGTERM)
            except ProcessLookupError:pass
            until=time.monotonic()+10
            while time.monotonic()<until:
                try:
                    done,_=os.waitpid(pid,os.WNOHANG)
                    if done:waited=True;break
                except ChildProcessError:waited=True;break
                time.sleep(0.05)
            if not waited:
                try:os.kill(pid,signal.SIGKILL)
                except ProcessLookupError:pass
                try:os.waitpid(pid,0)
                except ChildProcessError:pass


def unit_state(name,runner=command):
    args=[SYSTEMCTL,'show','--no-pager','--property=LoadState','--property=ActiveState','--property=FragmentPath']
    if name.endswith('.service'):args.append('--property=MainPID')
    code,output=runner(args+[name])
    try:
        lines=output.decode('ascii').strip().splitlines();value=dict(line.split('=',1) for line in lines)
        expected={'LoadState','ActiveState','FragmentPath'}|({'MainPID'} if name.endswith('.service') else set())
        if (code!=0 or len(lines)!=len(value) or set(value)!=expected or value['LoadState']!='loaded'
                or value['ActiveState'] not in ('active','activating','inactive','failed')
                or value['FragmentPath']!='/etc/systemd/system/'+name
                or ('MainPID' in value and not re.fullmatch('[0-9]+',value['MainPID']))):raise ValueError()
        if value['ActiveState'] in ('inactive','failed') and value.get('MainPID','0')!='0':raise ValueError()
        return value
    except (ValueError,UnicodeError):raise SafeError('ORDER_SEMANTIC_REPAIR_UNIT_STATE_NEEDS_REVIEW') from None


def unit_profile(name):
    path=pathlib.Path('/etc/systemd/system')/name
    for parent in reversed(path.parents):
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_mode&0o022:
            raise SafeError('ORDER_SEMANTIC_REPAIR_UNIT_PATH_UNSAFE')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info=os.fstat(fd);content=os.read(fd,1048577);after=os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_nlink!=1
                or info.st_mode&0o022 or info.st_size>1048576
                or (info.st_ino,info.st_size,info.st_mtime_ns,info.st_ctime_ns)!=(after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns)):
            raise SafeError('ORDER_SEMANTIC_REPAIR_UNIT_PROFILE_UNSAFE')
        return (info.st_dev,info.st_ino,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),
                info.st_mtime_ns,info.st_ctime_ns,hashlib.sha256(content).hexdigest())
    finally:os.close(fd)


def require_quiet(runner=command):
    states={name:unit_state(name,runner) for name in UNITS}
    if any(v['ActiveState'] not in ('inactive','failed') or v.get('MainPID','0')!='0' for v in states.values()):
        raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_WORKERS_NOT_QUIESCENT')


def restore_timers(before,profiles,runner=command,profile=unit_profile):
    if any(profile(name)!=profiles[name] for name in UNITS):raise SafeError('ORDER_SEMANTIC_REPAIR_UNIT_CHANGED')
    for name in UNITS:
        if name.endswith('.timer') and before[name]['ActiveState']=='active':
            code,_=runner([SYSTEMCTL,'start',name])
            if code!=0 or unit_state(name,runner)['ActiveState']!='active':
                raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINE_RESTORE_FAILED')
    for name in UNITS:
        if name.endswith('.timer') and before[name]['ActiveState']!='active':
            if unit_state(name,runner)['ActiveState'] not in ('inactive','failed'):
                raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINE_RESTORE_FAILED')


def fail_closed(profiles,runner=command,profile=unit_profile):
    code,_=runner([SYSTEMCTL,'stop',*UNITS])
    if code!=0:raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINE_RECOVERY_NEEDS_REVIEW')
    require_quiet(runner)
    if any(profile(name)!=profiles[name] for name in UNITS):
        raise SafeError('ORDER_SEMANTIC_REPAIR_CHANGED_UNIT_REQUIRES_REVIEW')
    code,_=runner([SYSTEMCTL,'start',UNITS[0]])
    if code!=0 or unit_state(UNITS[0],runner)['ActiveState']!='active':
        raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINE_RECOVERY_NEEDS_REVIEW')
    for name in UNITS[2:]:
        value=unit_state(name,runner)
        if value['ActiveState'] not in ('inactive','failed') or value.get('MainPID','0')!='0':
            raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINE_RECOVERY_NEEDS_REVIEW')


def orchestrate(directory,release,uid,gid,held_locks,runner=command,child=call_publisher,profile=unit_profile,journal=None):
    # App/source/lint/config preflight happens while the existing pipelines continue running.
    preflight=child(directory,release,uid,gid,held_locks,True)
    if not preflight['ok']:raise SafeError(preflight['status'])
    value=preflight['result']
    if (value.get('status')!='WHATSAPP_ORDER_SEMANTIC_PREFLIGHT_READY'
            or type(value.get('changed')) is not bool
            or not re.fullmatch('[a-f0-9]{64}',value.get('configuration_fingerprint',''))
            or type(value.get('recovery_pending')) is not int or not 0<=value['recovery_pending']<=13
            or not re.fullmatch('[a-f0-9]{64}',value.get('recovery_scope',''))):
        raise SafeError('ORDER_SEMANTIC_REPAIR_PREFLIGHT_OUTPUT_INVALID')
    if not value['changed'] and value['recovery_pending']==0:
        return {'status':'WHATSAPP_ORDER_SEMANTIC_ALREADY_CURRENT','backup':None}
    before={name:unit_state(name,runner) for name in UNITS}
    profiles={name:profile(name) for name in UNITS}
    if journal:journal({'phase':'PRIOR_PIPELINES','before':before,'profiles':profiles,
                        'configuration_fingerprint':value['configuration_fingerprint'],'release':release})
    for index in (0,2):
        if before[UNITS[index]]['ActiveState'] not in ('active','inactive','failed'):
            raise SafeError('ORDER_SEMANTIC_REPAIR_TIMER_TRANSITION_NEEDS_REVIEW')
        if before[UNITS[index+1]]['ActiveState'] in ('active','activating') and before[UNITS[index]]['ActiveState']!='active':
            raise SafeError('ORDER_SEMANTIC_REPAIR_MANUAL_WORKER_NEEDS_REVIEW')
    paused=False
    try:
        paused=True
        code,_=runner([SYSTEMCTL,'stop',*UNITS])
        if code!=0:raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINES_STOP_FAILED')
        require_quiet(runner)
        if any(profile(name)!=profiles[name] for name in UNITS):raise SafeError('ORDER_SEMANTIC_REPAIR_UNIT_CHANGED')
        # A last worker may finish while systemd stops it. Refresh only the
        # database before-images after quiescence, preserving configuration CAS.
        refreshed=child(directory,release,uid,gid,held_locks,True,value['configuration_fingerprint'])
        if not refreshed['ok']:raise SafeError(refreshed['status'])
        refreshed=refreshed['result']
        if (refreshed.get('status')!='WHATSAPP_ORDER_SEMANTIC_PREFLIGHT_READY'
                or refreshed.get('configuration_fingerprint')!=value['configuration_fingerprint']
                or type(refreshed.get('recovery_pending')) is not int or not 0<=refreshed['recovery_pending']<=13
                or not re.fullmatch('[a-f0-9]{64}',refreshed.get('recovery_scope',''))):
            raise SafeError('ORDER_SEMANTIC_REPAIR_QUIESCENT_PREFLIGHT_INVALID')
        value['recovery_pending']=refreshed['recovery_pending'];value['recovery_scope']=refreshed['recovery_scope']
        if journal:journal({'phase':'QUIESCENT','recovery_pending':value['recovery_pending'],'recovery_scope':value['recovery_scope']})
        result=child(directory,release,uid,gid,held_locks,False,value['configuration_fingerprint'])
        if not result['ok']:
            if result['status'].startswith('ORDER_SEMANTIC_REPAIR_FILES_RESTORED; BACKUP='):
                restore_timers(before,profiles,runner,profile)
                paused=False
            raise SafeError(result['status'])
        result=result['result']
        if (result.get('status') not in ('WHATSAPP_ORDER_SEMANTIC_REPAIR_READY','WHATSAPP_ORDER_SEMANTIC_ALREADY_CURRENT')
                or result.get('configuration_fingerprint')!=value['configuration_fingerprint']):
            raise SafeError('ORDER_SEMANTIC_REPAIR_PUBLICATION_RESULT_INVALID')
        if journal:journal({'phase':'CODE_PUBLISHED','backup':result.get('backup')})
        if value['recovery_pending']:
            recovered=child(directory,release,uid,gid,held_locks,False,value['configuration_fingerprint'],
                            value['recovery_scope'],result.get('backup'),True)
            if not recovered['ok']:raise SafeError(recovered['status'])
            recovered=recovered['result']
            if (recovered.get('status')!='WHATSAPP_ORDER_SEMANTIC_RECOVERY_READY'
                    or recovered.get('configuration_fingerprint')!=value['configuration_fingerprint']):
                raise SafeError('ORDER_SEMANTIC_REPAIR_RECOVERY_RESULT_INVALID')
            result['recovery_metrics']=recovered['metrics'];result['recovery_backup']=recovered['backup']
            if journal:journal({'phase':'RECOVERY_COMMITTED','backup':recovered['backup'],'metrics':recovered['metrics']})
        restore_timers(before,profiles,runner,profile);paused=False
        if journal:journal({'phase':'PRIOR_PIPELINES_RESTORED'})
        return result
    except BaseException as error:
        if paused:
            try:fail_closed(profiles,runner,profile)
            except BaseException:raise SafeError('ORDER_SEMANTIC_REPAIR_RECOVERY_NEEDS_REVIEW') from None
            if journal:
                try:journal({'phase':'ORDERS_PAUSED_CAPTURE_ACTIVE'})
                except BaseException:pass
            status=str(error) if isinstance(error,SafeError) else 'ORDER_SEMANTIC_REPAIR_STOPPED'
            raise SafeError(status+'; ORDERS_PAUSED_CAPTURE_ACTIVE') from None
        raise


def main():
    parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--release',required=True)
    args=parser.parse_args()
    descriptors=[];journal_fd=None;pipeline_backup=None
    try:
        def interrupted(signum,frame):raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
        os.umask(0o077)
        if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{40}',args.release):raise SafeError('ORDER_SEMANTIC_REPAIR_ROOT_CONTEXT_INVALID')
        user=pwd.getpwnam('fasakha');uid,gid=user.pw_uid,user.pw_gid
        owned_directory(ROOT,uid);owned_directory(ROOT/'.git',uid)
        parent=ROOT.parent/'whatsapp-release-backups';owned_directory(parent,uid,True)
        descriptors=acquire_locks(parent,uid,gid)
        pipeline_backup=pathlib.Path(tempfile.mkdtemp(prefix='semantic-pipeline-state-',dir=parent));pipeline_backup.chmod(0o700)
        journal_fd=os.open(pipeline_backup/'journal.jsonl',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        def journal(value):
            data=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode();offset=0
            while offset<len(data):
                amount=os.write(journal_fd,data[offset:])
                if amount<1:raise SafeError('ORDER_SEMANTIC_REPAIR_PIPELINE_JOURNAL_FAILED')
                offset+=amount
            os.fsync(journal_fd)
        with tempfile.TemporaryDirectory(prefix='.order-semantic-helper-',dir=ROOT.parent) as folder:
            directory=pathlib.Path(folder);os.chown(directory,uid,gid);directory.chmod(0o700)
            stage_helpers(directory,args.release,uid,gid)
            result=orchestrate(directory,args.release,uid,gid,descriptors,journal=journal)
        print(result['status'])
        if result.get('backup'):print('BACKUP='+result['backup'])
        if result.get('recovery_backup'):print('RECOVERY_BACKUP='+result['recovery_backup'])
        if result.get('recovery_metrics'):print('RECOVERY='+json.dumps(result['recovery_metrics'],sort_keys=True))
        print('PIPELINE_BACKUP='+str(pipeline_backup))
        print('CONFIGURATION_UNCHANGED; EXISTING_PIPELINES_RESTORED; NO_REACTIVATION')
    except BaseException as error:
        value=str(error) if isinstance(error,SafeError) else 'ORDER_SEMANTIC_REPAIR_ROOT_STOPPED_WITHOUT_SECRET_OUTPUT'
        print(value,file=sys.stderr)
        if pipeline_backup is not None:print('PIPELINE_BACKUP='+str(pipeline_backup),file=sys.stderr)
        raise SystemExit(1)
    finally:
        for fd in descriptors:os.close(fd)
        if journal_fd is not None:os.close(journal_fd)


if __name__=='__main__':main()
PY
