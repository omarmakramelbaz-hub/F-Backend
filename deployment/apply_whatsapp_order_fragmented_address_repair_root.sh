#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
"""Quiesce only the known pipelines around the pinned fragmented-address publication."""
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
PUBLISHER_BLOB='78b24af119e583e9f78ca045f8b4e916d437c3f4'
BASE_BLOB='f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
HELPERS={'apply_whatsapp_order_fragmented_address_repair.py':PUBLISHER_BLOB,'install_whatsapp_inbox.py':BASE_BLOB}
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
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0,uid):raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_PATH_UNSAFE')
    info=path.lstat()
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid!=uid
            or (private and stat.S_IMODE(info.st_mode)!=0o700)):
        raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_DIRECTORY_UNSAFE')


def stage_helpers(directory,release,uid,gid,runner=command):
    for name,digest in HELPERS.items():
        relative='deployment/'+name
        code,tree=runner(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'ls-tree','-z',release,relative])
        if code!=0 or tree!=b'100644 blob '+digest.encode()+b'\t'+relative.encode()+b'\0':
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_HELPER_TREE_MISMATCH')
        code,content=runner(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'show',release+':'+relative])
        if code!=0 or not content or len(content)>1048576 or blob(content)!=digest:
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_HELPER_HASH_MISMATCH')
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
                raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_LOCK_UNSAFE')
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise SafeError('ORDER_FRAGMENTED_ADDRESS_ANOTHER_INSTALL_RUNNING') from None
        return fds
    except BaseException:
        for fd in fds:os.close(fd)
        raise


def call_publisher(directory,release,uid,gid,held_locks,check_only,expected=None,expected_sources=None,expected_protected=None):
    read_fd,write_fd=os.pipe();pid=os.fork()
    if pid==0:
        os.close(read_fd)
        try:
            def interrupted(signum,frame):raise InterruptedError()
            signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
            os.setgroups([]);os.setgid(gid);os.setuid(uid);os.umask(0o077)
            path=directory/'apply_whatsapp_order_fragmented_address_repair.py'
            fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
            try:
                info=os.fstat(fd);source=os.read(fd,1048577)
                if (not stat.S_ISREG(info.st_mode) or info.st_uid!=uid or info.st_nlink!=1
                        or stat.S_IMODE(info.st_mode)!=0o600 or blob(source)!=PUBLISHER_BLOB):
                    raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_STAGED_HELPER_CHANGED')
            finally:os.close(fd)
            name='verified_whatsapp_order_fragmented_address_repair'
            module=importlib.util.module_from_spec(importlib.util.spec_from_loader(name,loader=None))
            module.__file__=str(path);sys.modules[name]=module
            exec(compile(source,str(path),'exec'),module.__dict__)
            if check_only=='state':
                result=module.verify_state(ROOT,uid,expected,expected_sources,expected_protected,held_locks)
            else:
                result=module.apply(ROOT,uid,release,check_only,held_locks=held_locks,expected_configuration=expected)
            value={'ok':True,'result':result}
        except BaseException as error:
            status=str(error) if (isinstance(error,SafeError) or
                    'module' in locals() and isinstance(error,module.SafeError)) else 'ORDER_FRAGMENTED_ADDRESS_CHILD_STOPPED'
            # Only fixed status codes and our own canonical private backup path may leave the child.
            if not re.fullmatch(r'[A-Z_]+(?:; BACKUP=/home/fasakha/whatsapp-release-backups/order-fragmented-address-[a-z0-9_]+)?',status):
                status='ORDER_FRAGMENTED_ADDRESS_CHILD_STOPPED'
            value={'ok':False,'status':status}
        try:os.write(write_fd,json.dumps(value,separators=(',',':')).encode())
        finally:os.close(write_fd);os._exit(0)
    os.close(write_fd);chunks=[];total=0;deadline=time.monotonic()+240;waited=False
    try:
        while True:
            if time.monotonic()>deadline:
                raise SafeError('ORDER_FRAGMENTED_ADDRESS_CHILD_TIMEOUT')
            readable,_,_=select.select([read_fd],[],[],min(1,max(0,deadline-time.monotonic())))
            if not readable:continue
            data=os.read(read_fd,4096)
            if not data:break
            total+=len(data)
            if total>16384:
                raise SafeError('ORDER_FRAGMENTED_ADDRESS_CHILD_OUTPUT_INVALID')
            chunks.append(data)
        _,exit_status=os.waitpid(pid,0);waited=True
        value=json.loads(b''.join(chunks))
        if exit_status!=0 or not isinstance(value,dict) or type(value.get('ok')) is not bool:
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_CHILD_OUTPUT_INVALID')
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
    except (ValueError,UnicodeError):raise SafeError('ORDER_FRAGMENTED_ADDRESS_UNIT_STATE_NEEDS_REVIEW') from None


def unit_profile(name):
    path=pathlib.Path('/etc/systemd/system')/name
    for parent in reversed(path.parents):
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_mode&0o022:
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_UNIT_PATH_UNSAFE')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info=os.fstat(fd);content=os.read(fd,1048577);after=os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_nlink!=1
                or info.st_mode&0o022 or info.st_size>1048576
                or (info.st_ino,info.st_size,info.st_mtime_ns,info.st_ctime_ns)!=(after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns)):
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_UNIT_PROFILE_UNSAFE')
        return (info.st_dev,info.st_ino,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),
                info.st_mtime_ns,info.st_ctime_ns,hashlib.sha256(content).hexdigest())
    finally:os.close(fd)


def require_quiet(runner=command):
    states={name:unit_state(name,runner) for name in UNITS}
    if any(v['ActiveState'] not in ('inactive','failed') or v.get('MainPID','0')!='0' for v in states.values()):
        raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_WORKERS_NOT_QUIESCENT')


def wait_quiet(runner=command, clock=time.monotonic, pause=time.sleep, timeout=60):
    deadline=clock()+timeout
    while True:
        states={name:unit_state(name,runner) for name in UNITS}
        if all(v['ActiveState'] in ('inactive','failed') and v.get('MainPID','0')=='0' for v in states.values()):
            return
        if any(states[name]['ActiveState'] not in ('inactive','failed') for name in (UNITS[0],UNITS[2])):
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_TIMERS_NOT_PAUSED')
        if clock()>=deadline:raise SafeError('ORDER_FRAGMENTED_ADDRESS_WORKER_IDLE_TIMEOUT')
        pause(min(0.25,max(0,deadline-clock())))


class PipelineReceipt:
    PHASES=frozenset(('RECORDED','TIMERS_PAUSED','QUIESCENT','PUBLICATION_STARTED',
        'PUBLISHED','RESTORED','FILES_RESTORED','PAUSE_CANCELLED',
        'ORDERS_PAUSED_CAPTURE_ACTIVE','RECOVERY_NEEDS_REVIEW'))
    def __init__(self,path):self.path=path;self.count=0
    def mark(self,phase):
        if phase not in self.PHASES:raise SafeError('ORDER_FRAGMENTED_ADDRESS_RECEIPT_PHASE_INVALID')
        write_receipt(self.path/('phase-'+str(self.count).zfill(2)+'.json'),{'phase':phase})
        self.count+=1


def write_receipt(path,value):
    content=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
    temporary=path.with_name('.'+path.name+'.temporary')
    fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(content);stream.flush();os.fsync(stream.fileno())
        # Link into an absent destination atomically, then retain exactly one link.
        os.link(temporary,path,follow_symlinks=False);temporary.unlink()
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        if temporary.exists():temporary.unlink()


def record_pipeline(before,profiles,release,configuration_fingerprint,uid,sources,protected_fingerprint):
    parent=ROOT.parent/'whatsapp-release-backups';owned_directory(parent,uid,True)
    path=pathlib.Path(tempfile.mkdtemp(prefix='order-fragmented-address-pipeline-',dir=parent))
    path.chmod(0o700)
    write_receipt(path/'receipt.json',{'release':release,'original_units':before,
        'unit_profiles':profiles,'configuration_fingerprint':configuration_fingerprint,
        'original_sources':sources,'protected_fingerprint':protected_fingerprint})
    receipt=PipelineReceipt(path);receipt.mark('RECORDED');return receipt


def restore_timers(before,profiles,runner=command,profile=unit_profile,guard=None):
    if guard is None:raise SafeError('ORDER_FRAGMENTED_ADDRESS_RESTART_GUARD_REQUIRED')
    if any(profile(name)!=profiles[name] for name in UNITS):raise SafeError('ORDER_FRAGMENTED_ADDRESS_UNIT_CHANGED')
    guard()
    for name in UNITS:
        if name.endswith('.timer') and before[name]['ActiveState']=='active':
            guard()
            code,_=runner([SYSTEMCTL,'start',name])
            if code!=0 or unit_state(name,runner)['ActiveState']!='active':
                raise SafeError('ORDER_FRAGMENTED_ADDRESS_PIPELINE_RESTORE_FAILED')
            guard()
    for name in UNITS:
        if name.endswith('.timer') and before[name]['ActiveState']!='active':
            if unit_state(name,runner)['ActiveState'] not in ('inactive','failed'):
                raise SafeError('ORDER_FRAGMENTED_ADDRESS_PIPELINE_RESTORE_FAILED')


def stop_all(runner=command):
    code,_=runner([SYSTEMCTL,'stop',*UNITS])
    if code!=0:raise SafeError('ORDER_FRAGMENTED_ADDRESS_PIPELINE_RECOVERY_NEEDS_REVIEW')
    require_quiet(runner)


def fail_closed(profiles,runner=command,profile=unit_profile,guard=None):
    stop_all(runner)
    if guard is None:raise SafeError('ORDER_FRAGMENTED_ADDRESS_RESTART_GUARD_REQUIRED')
    if any(profile(name)!=profiles[name] for name in UNITS):
        raise SafeError('ORDER_FRAGMENTED_ADDRESS_CHANGED_UNIT_REQUIRES_REVIEW')
    guard()
    code,_=runner([SYSTEMCTL,'start',UNITS[0]])
    if code!=0 or unit_state(UNITS[0],runner)['ActiveState']!='active':
        raise SafeError('ORDER_FRAGMENTED_ADDRESS_PIPELINE_RECOVERY_NEEDS_REVIEW')
    try:guard()
    except BaseException:
        stop_all(runner);raise
    for name in UNITS[2:]:
        value=unit_state(name,runner)
        if value['ActiveState'] not in ('inactive','failed') or value.get('MainPID','0')!='0':
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_PIPELINE_RECOVERY_NEEDS_REVIEW')


def orchestrate(directory,release,uid,gid,held_locks,runner=command,child=call_publisher,
                profile=unit_profile,record=record_pipeline,wait=wait_quiet):
    # No worker actions occur until code/lint/config preflight has succeeded.
    preflight=child(directory,release,uid,gid,held_locks,True)
    if not preflight['ok']:raise SafeError(preflight['status'])
    value=preflight['result']
    if (value.get('status')!='WHATSAPP_ORDER_FRAGMENTED_ADDRESS_PREFLIGHT_READY'
            or type(value.get('changed')) is not bool
            or not re.fullmatch('[a-f0-9]{64}',value.get('configuration_fingerprint',''))
            or not re.fullmatch('[a-f0-9]{64}',value.get('protected_fingerprint',''))
            or not isinstance(value.get('sources'),dict) or len(value['sources'])!=1):
        raise SafeError('ORDER_FRAGMENTED_ADDRESS_PREFLIGHT_OUTPUT_INVALID')
    if not value['changed']:
        return {'status':'WHATSAPP_ORDER_FRAGMENTED_ADDRESS_ALREADY_CURRENT','backup':None}
    before={name:unit_state(name,runner) for name in UNITS}
    profiles={name:profile(name) for name in UNITS}
    for index in (0,2):
        if before[UNITS[index]]['ActiveState'] not in ('active','inactive','failed'):
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_TIMER_TRANSITION_NEEDS_REVIEW')
        if before[UNITS[index+1]]['ActiveState'] in ('active','activating') and before[UNITS[index]]['ActiveState']!='active':
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_MANUAL_WORKER_NEEDS_REVIEW')
    receipt=record(before,profiles,release,value['configuration_fingerprint'],uid,value['sources'],value['protected_fingerprint'])
    accepted_sources=value['sources']
    def guard():
        result=child(directory,release,uid,gid,held_locks,'state',
            value['configuration_fingerprint'],accepted_sources,value['protected_fingerprint'])
        if not result['ok']:raise SafeError('ORDER_FRAGMENTED_ADDRESS_RESTART_STATE_NEEDS_REVIEW')
        checked=result['result']
        if (checked.get('status')!='WHATSAPP_ORDER_FRAGMENTED_ADDRESS_STATE_READY'
                or checked.get('configuration_fingerprint')!=value['configuration_fingerprint']
                or checked.get('sources')!=accepted_sources
                or checked.get('protected_fingerprint')!=value['protected_fingerprint']):
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_RESTART_STATE_NEEDS_REVIEW')
    paused=False;publication_attempted=False
    try:
        paused=True
        # Leave an existing service invocation alone; it must become idle before
        # publication. Restarting a timer preserves the existing activation/cutoff.
        code,_=runner([SYSTEMCTL,'stop',UNITS[0],UNITS[2]])
        if code!=0:raise SafeError('ORDER_FRAGMENTED_ADDRESS_PIPELINES_STOP_FAILED')
        receipt.mark('TIMERS_PAUSED');wait(runner);receipt.mark('QUIESCENT')
        if any(profile(name)!=profiles[name] for name in UNITS):raise SafeError('ORDER_FRAGMENTED_ADDRESS_UNIT_CHANGED')
        guard()
        publication_attempted=True;receipt.mark('PUBLICATION_STARTED')
        result=child(directory,release,uid,gid,held_locks,False,value['configuration_fingerprint'])
        if not result['ok']:
            if result['status'].startswith('ORDER_FRAGMENTED_ADDRESS_FILES_RESTORED; BACKUP='):
                receipt.mark('FILES_RESTORED');restore_timers(before,profiles,runner,profile,guard)
                paused=False;receipt.mark('RESTORED')
            raise SafeError(result['status'])
        result=result['result']
        if (result.get('status') not in ('WHATSAPP_ORDER_FRAGMENTED_ADDRESS_REPAIR_READY','WHATSAPP_ORDER_FRAGMENTED_ADDRESS_ALREADY_CURRENT')
                or result.get('configuration_fingerprint')!=value['configuration_fingerprint']
                or result.get('protected_fingerprint')!=value['protected_fingerprint']
                or not isinstance(result.get('sources'),dict) or len(result['sources'])!=1):
            raise SafeError('ORDER_FRAGMENTED_ADDRESS_PUBLICATION_RESULT_INVALID')
        accepted_sources=result['sources']
        receipt.mark('PUBLISHED');restore_timers(before,profiles,runner,profile,guard)
        paused=False;receipt.mark('RESTORED');result['pipeline_backup']=str(receipt.path)
        return result
    except BaseException as error:
        if paused:
            if not publication_attempted and all(profile(name)==profiles[name] for name in UNITS):
                try:
                    restore_timers(before,profiles,runner,profile,guard);paused=False;receipt.mark('PAUSE_CANCELLED')
                except BaseException:paused=True
                if not paused:raise
            try:
                fail_closed(profiles,runner,profile,guard);receipt.mark('ORDERS_PAUSED_CAPTURE_ACTIVE')
            except BaseException:
                try:stop_all(runner)
                except BaseException:pass
                try:receipt.mark('RECOVERY_NEEDS_REVIEW')
                except BaseException:pass
                raise SafeError('ORDER_FRAGMENTED_ADDRESS_RECOVERY_NEEDS_REVIEW; PIPELINE_BACKUP='+str(receipt.path)) from None
            status=str(error) if isinstance(error,SafeError) else 'ORDER_FRAGMENTED_ADDRESS_STOPPED'
            raise SafeError(status+'; ORDERS_PAUSED_CAPTURE_ACTIVE; PIPELINE_BACKUP='+str(receipt.path)) from None
        raise


def main():
    parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--release',required=True)
    args=parser.parse_args()
    descriptors=[]
    try:
        def interrupted(signum,frame):raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
        if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{40}',args.release):raise SafeError('ORDER_FRAGMENTED_ADDRESS_ROOT_CONTEXT_INVALID')
        user=pwd.getpwnam('fasakha');uid,gid=user.pw_uid,user.pw_gid
        owned_directory(ROOT,uid);owned_directory(ROOT/'.git',uid)
        parent=ROOT.parent/'whatsapp-release-backups';owned_directory(parent,uid,True)
        descriptors=acquire_locks(parent,uid,gid)
        with tempfile.TemporaryDirectory(prefix='.order-fragmented-address-helper-',dir=ROOT.parent) as folder:
            directory=pathlib.Path(folder);os.chown(directory,uid,gid);directory.chmod(0o700)
            stage_helpers(directory,args.release,uid,gid)
            result=orchestrate(directory,args.release,uid,gid,descriptors)
        print(result['status'])
        if result.get('backup'):print('BACKUP='+result['backup'])
        if result.get('pipeline_backup'):print('PIPELINE_BACKUP='+result['pipeline_backup'])
        print('CONFIGURATION_UNCHANGED; EXISTING_PIPELINES_RESTORED; NO_REACTIVATION')
    except BaseException as error:
        value=str(error) if isinstance(error,SafeError) else 'ORDER_FRAGMENTED_ADDRESS_ROOT_STOPPED_WITHOUT_SECRET_OUTPUT'
        print(value,file=sys.stderr);raise SystemExit(1)
    finally:
        for fd in descriptors:os.close(fd)


if __name__=='__main__':main()
PY
