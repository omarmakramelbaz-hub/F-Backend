#!/usr/bin/env python3
"""Upgrade the installed v1 watcher to v2 checkout policy without reading credentials."""
import contextlib
import ctypes
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import socket
import stat
import subprocess
import sys
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
RELEASES = Path('/opt/go-erp-live-updates/releases')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
CONFIG = Path('/etc/go-erp/live-updates.json')
BASE = '75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
POLICY = 'historical-baseline-v2'
OLD_RUNTIME_NAME = 'policy-62f3d9e18d2a718978959b3fd141430872a98f900e4053a69f883ae0f4ad7da6'
OLD_PINS = {'go_erp_live_source.py':'a93fae23b31e1193492ad8bab3093e2f4fbb2eb8',
            'go_erp_live_updates.py':'60f73cd5b8696d591734bb89ee193d88eccda335',
            'publish_go_erp_update.py':'f9a7353b0705383844a576ab6d0f5b1662912bfb',
            'go_erp_current_candidate_ci.yml':'f9d38b7f27f9505eb2b633214930e698f759676a',
            'verify_go_erp_current_candidate_ci.py':'6e9b8f4aa24067b25ea8e7ce899136a1840171f5'}
PINS = {'go_erp_live_source.py': 'ce185cdfa8742508de20550ebfe59301f036c273', 'go_erp_live_updates.py': '13648df9725ec3f81207d262cbd3ba506ac7dd0a', 'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb', 'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423', 'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de'}
ROSTER = tuple(sorted(PINS))
MAX_FILE = 2*1024*1024
MAX_STATE = 16*1024*1024

class Stop(Exception):
    pass

def need(value, reason):
    if not value:
        raise Stop(reason)

def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def sha(data):
    return hashlib.sha256(data).hexdigest()

def key(item):
    return (item.st_dev,item.st_ino,item.st_mode,item.st_uid,item.st_gid,item.st_nlink,
            item.st_size,item.st_mtime_ns)

def exact_key(item):
    return (*key(item),item.st_ctime_ns)

def directory_key(item):
    return (item.st_dev,item.st_ino,item.st_mode,item.st_uid,item.st_gid)

class Directory:
    def __init__(self,path,mode=None):
        self.rows=[];self.path=path
        need(path.is_absolute() and '..' not in path.parts,'DIRECTORY_UNSAFE')
        fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        self.rows.append((fd,os.fstat(fd),None,None))
        try:
            for index,name in enumerate(path.parts[1:]):
                parent=fd;fd=os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=parent)
                item=os.fstat(fd);self.rows.append((fd,item,parent,name))
                sticky=index<len(path.parts)-2 and bool(item.st_mode&stat.S_ISVTX)
                need(stat.S_ISDIR(item.st_mode) and item.st_uid==0
                     and (not item.st_mode&0o022 or sticky),'DIRECTORY_UNSAFE')
            self.fd=fd
            if mode is not None:
                need(stat.S_IMODE(os.fstat(fd).st_mode)==mode,'DIRECTORY_MODE_INVALID')
            self.verify()
        except BaseException:
            self.close();raise
    def verify(self):
        for fd,item,parent,name in self.rows:
            need(directory_key(os.fstat(fd))==directory_key(item),'DIRECTORY_CHANGED')
            if parent is not None:
                need(directory_key(os.stat(name,dir_fd=parent,follow_symlinks=False))
                     ==directory_key(item),'DIRECTORY_CHANGED')
    def close(self):
        for fd,*_ in reversed(self.rows):os.close(fd)
        self.rows=[]

def read_fd(fd,maximum,mode):
    before=os.fstat(fd)
    need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
         stat.S_IMODE(before.st_mode))==(0,0,1,mode)
         and 0<before.st_size<=maximum,'FILE_UNSAFE')
    os.lseek(fd,0,os.SEEK_SET);result=bytearray()
    while len(result)<=maximum:
        part=os.read(fd,min(65536,maximum+1-len(result)))
        if not part:break
        result.extend(part)
    need(len(result)==before.st_size and exact_key(before)==exact_key(os.fstat(fd)),
         'FILE_CHANGED')
    return bytes(result)

def read_at(directory,name,maximum,mode):
    directory.verify();need('/' not in name and name not in ('','.','..'),'FILE_SCOPE_INVALID')
    fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
    try:
        before=os.fstat(fd);data=read_fd(fd,maximum,mode)
        need(exact_key(before)==exact_key(os.stat(name,dir_fd=directory.fd,follow_symlinks=False)),
             'FILE_CHANGED')
        directory.verify();return data
    finally:os.close(fd)

def write_at(directory,name,data,mode=0o600):
    directory.verify()
    fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=directory.fd)
    try:
        need(os.fstat(fd).st_uid==0 and os.fstat(fd).st_nlink==1,'OUTPUT_UNSAFE')
        offset=0
        while offset<len(data):
            count=os.write(fd,data[offset:]);need(count>0,'WRITE_FAILED');offset+=count
        os.fchmod(fd,mode);os.fsync(fd)
    finally:os.close(fd)
    os.fsync(directory.fd)

def json_at(directory,name,value):
    write_at(directory,name,(json.dumps(value,sort_keys=True)+'\n').encode())

@contextlib.contextmanager
def critical():
    previous=signal.pthread_sigmask(signal.SIG_BLOCK,{signal.SIGINT,signal.SIGTERM,signal.SIGHUP})
    try:yield
    finally:signal.pthread_sigmask(signal.SIG_SETMASK,previous)

def exchange(left_fd,left,right_fd,right):
    libc=ctypes.CDLL(None,use_errno=True);function=libc.renameat2
    function.argtypes=[ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
    function.restype=ctypes.c_int
    if function(left_fd,os.fsencode(left),right_fd,os.fsencode(right),2):
        raise OSError(ctypes.get_errno(),'atomic exchange failed')

@contextlib.contextmanager
def watcher_lock(work):
    directory=Directory(work,0o700);fd=None
    try:
        fd=os.open('watcher.lock',os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
        before=os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
             stat.S_IMODE(before.st_mode))==(0,0,1,0o600),'LOCK_UNSAFE')
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise Stop('WATCHER_BUSY') from None
        def verify():
            directory.verify()
            need(exact_key(before)==exact_key(os.fstat(fd))==exact_key(os.stat('watcher.lock',
                 dir_fd=directory.fd,follow_symlinks=False)),'LOCK_CHANGED')
        verify();yield verify;verify()
    finally:
        if fd is not None:os.close(fd)
        directory.close()

def service_unit(directory):
    return ('[Unit]\nDescription=GO ERP accepted live source releases\n'
        'Wants=network-online.target\nAfter=network-online.target\n\n[Service]\n'
        'Type=oneshot\nUser=root\nGroup=root\nUMask=0077\n'
        f'ExecStart=/usr/bin/python3 -I -B {directory}/go_erp_live_updates.py --config {CONFIG} --once\n'
        'TimeoutStartSec=45min\nNoNewPrivileges=yes\nPrivateTmp=yes\nPrivateDevices=yes\n'
        'ProtectSystem=strict\nProtectHome=read-only\nProtectKernelTunables=yes\n'
        'ProtectKernelModules=yes\nProtectControlGroups=yes\nRestrictSUIDSGID=yes\n'
        f'ReadWritePaths={WORK} {ROOT}/storage/app/desktop-updates\n'
        'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6\n').encode()

def runtime_name(payloads):
    value={name:{'blob':blob(data),'sha256':sha(data)} for name,data in sorted(payloads.items())}
    return 'policy-'+sha(json.dumps(value,sort_keys=True,separators=(',',':')).encode())

def validate_runtime(directory,pins,exact=True):
    directory.verify()
    if exact:
        need(set(os.listdir(directory.fd))==set(pins),'RUNTIME_ROSTER_INVALID')
    for name,pin in pins.items():
        need(blob(read_at(directory,name,MAX_FILE,0o644))==pin,'RUNTIME_PIN_INVALID')

def install_runtime(releases,payloads,pins):
    name=runtime_name(payloads);target=releases/name
    parent=Directory(releases,0o755)
    temporary=None
    try:
        try:directory=Directory(target,0o755)
        except FileNotFoundError:
            temporary='.policy-install-'+uuid.uuid4().hex
            os.mkdir(temporary,0o700,dir_fd=parent.fd);os.fsync(parent.fd)
            staging=Directory(releases/temporary,0o700)
            try:
                for filename,data in sorted(payloads.items()):write_at(staging,filename,data,0o644)
                validate_runtime(staging,pins)
                parent.verify();os.fchmod(staging.fd,0o755);os.fsync(staging.fd)
                os.rename(temporary,name,src_dir_fd=parent.fd,dst_dir_fd=parent.fd)
                os.fsync(parent.fd);temporary=None
            finally:staging.close()
            directory=Directory(target,0o755)
        try:validate_runtime(directory,pins)
        finally:directory.close()
        return target
    finally:
        # A partial root-private staging directory is retained for review; no
        # recursive removal or replacement of another runtime is authorized.
        parent.close()

def module_at(directory,name,pin):
    data=read_at(directory,name,MAX_FILE,0o644);need(blob(data)==pin,'RUNTIME_PIN_INVALID')
    module=types.ModuleType('reviewed_runtime_upgrade_'+name[:-3]);module.__file__=str(directory.path/name)
    sys.modules[module.__name__]=module
    exec(compile(data,module.__file__,'exec'),module.__dict__)
    return module

def probe_runtime(target,live,work,state_bytes,pins):
    directory=Directory(target,0o755)
    try:
        validate_runtime(directory,pins)
        source=module_at(directory,'go_erp_live_source.py',pins['go_erp_live_source.py'])
        worker=module_at(directory,'go_erp_live_updates.py',pins['go_erp_live_updates.py'])
        need(getattr(source,'CANDIDATE_POLICY',None)==getattr(worker,'CANDIDATE_POLICY',None)==POLICY,
             'POLICY_PROTOCOL_INVALID')
        state=worker.parse_json(state_bytes)
        need(isinstance(state,dict) and state.get('format')==1 and state.get('base_revision')==BASE
             and type(state.get('counter')) is int and 0<=state['counter']<=9007199254740991,
             'STATE_INVALID')
        dependencies=worker.dependency_snapshot(live,1001)
        need(state.get('dependency_guard') in (None,dependencies),'DEPENDENCY_GUARD_MISMATCH')
        snapshot=source.scan_snapshot(live,work/'checkout',1001,state.get('observed'))
        need(isinstance(snapshot,dict) and isinstance(snapshot.get('metadata'),dict)
             and isinstance(snapshot['metadata'].get('inventory'),dict),'SOURCE_PROBE_INVALID')
        validate_runtime(directory,pins)
        return {'counter':state['counter'],'desired':state.get('desired') is not None,
                'published':state.get('published') is not None,'source_files':len(snapshot['metadata']['inventory']),
                'dependency_guard_matches':True,'candidate_policy':POLICY}
    finally:directory.close()

def system_command(arguments):
    result=subprocess.run(arguments,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,timeout=30,env={'PATH':'/usr/bin:/bin'})
    need(result.returncode==0,'SYSTEMD_COMMAND_FAILED')


def validate_effective_unit(properties,unit,config,runtimes,allow_pending_reload=False):
    """Reject overrides; accept the old loaded unit only for interrupted reload recovery."""
    need(properties.get('FragmentPath')==str(unit)
         and properties.get('DropInPaths')=='','EFFECTIVE_UNIT_UNREVIEWED')
    need(properties.get('ActiveState') in ('inactive','failed'),'WATCHER_BUSY')
    allowed_reload=('no','yes') if allow_pending_reload else ('no',)
    need(properties.get('NeedDaemonReload') in allowed_reload,'EFFECTIVE_UNIT_RELOAD_REQUIRED')
    expected={'/usr/bin/python3 -I -B '+str(directory/'go_erp_live_updates.py')
              +' --config '+str(config)+' --once' for directory in runtimes}
    value=properties.get('ExecStart','')
    entries=re.findall(r'argv\[\]=([^;]+);',value)
    need(len(entries)==1 and entries[0].strip() in expected
         and value.count('path=')==1 and 'path=/usr/bin/python3 ;' in value,
         'EFFECTIVE_RUNTIME_UNREVIEWED')


def effective_unit(unit,config,runtimes,allow_pending_reload=False):
    result=subprocess.run(['/usr/bin/systemctl','show','go-erp-live-updates.service',
        '--no-pager','-p','FragmentPath','-p','DropInPaths','-p','NeedDaemonReload',
        '-p','ExecStart','-p','ActiveState'],stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=15,
        env={'PATH':'/usr/bin:/bin','LANG':'C'})
    need(result.returncode==0 and len(result.stdout)<=16384,'EFFECTIVE_UNIT_QUERY_FAILED')
    rows=result.stdout.decode('utf-8','strict').splitlines()
    need(all('=' in row for row in rows),'EFFECTIVE_UNIT_QUERY_INVALID')
    properties=dict(row.split('=',1) for row in rows)
    need(len(properties)==len(rows),'EFFECTIVE_UNIT_QUERY_INVALID')
    validate_effective_unit(properties,unit,config,runtimes,allow_pending_reload)


def upgrade_runtime(payloads,pins,old_pins,live=ROOT,work=WORK,releases=RELEASES,unit=UNIT,
                    config=CONFIG,probe=probe_runtime,command=system_command,
                    verify_unit=effective_unit,before_exchange=None):
    report={'format':1,'kind':'go-erp-checkout-policy-upgrade','ok':False,'unit_changed':False,
        'application_contents_changed':False,'database_changed':False,'state_changed':False,
        'configuration_read':False,'configuration_changed':False,'credentials_disclosed':False,
        'rollback_complete':True}
    phase='PREFLIGHT';capture=unit_parent=config_parent=state_parent=work_dir=None
    config_fd=unit_fd=new_fd=None;before_unit=new_item=None;attempted=False
    lock_manager=None;lock_entered=False
    try:
        need(set(payloads)==set(pins)==set(ROSTER) and set(old_pins)==set(OLD_PINS),
             'PAYLOAD_SCOPE_INVALID')
        for name,data in payloads.items():
            need(isinstance(data,bytes) and 0<len(data)<=MAX_FILE
                 and re.fullmatch('[a-f0-9]{40}',pins[name]) and blob(data)==pins[name],
                 'PAYLOAD_PIN_INVALID')
        old=Directory(releases/OLD_RUNTIME_NAME,0o755)
        try:validate_runtime(old,old_pins)
        finally:old.close()
        lock_manager=watcher_lock(work);lock=lock_manager.__enter__();lock_entered=True
        with contextlib.nullcontext(lock):
            unit_parent=Directory(unit.parent,0o755)
            config_parent=Directory(config.parent,0o700)
            state_parent=Directory(work/'state',0o700)
            config_fd=os.open(config.name,os.O_PATH|os.O_NOFOLLOW,dir_fd=config_parent.fd)
            config_before=os.fstat(config_fd)
            need(stat.S_ISREG(config_before.st_mode) and (config_before.st_uid,config_before.st_gid,
                 config_before.st_nlink,stat.S_IMODE(config_before.st_mode))==(0,0,1,0o600)
                 and 0<config_before.st_size<=16384,'CONFIGURATION_METADATA_INVALID')
            state_bytes=read_at(state_parent,'current.json',MAX_STATE,0o600)
            state_before=os.stat('current.json',dir_fd=state_parent.fd,follow_symlinks=False)
            target=releases/runtime_name(payloads)
            old_unit=service_unit(releases/OLD_RUNTIME_NAME);new_unit=service_unit(target)
            unit_fd=os.open(unit.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=unit_parent.fd)
            before_unit=os.fstat(unit_fd);unit_bytes=read_fd(unit_fd,64*1024,0o644)
            need(unit_bytes in (old_unit,new_unit),'SERVICE_UNIT_UNREVIEWED')
            previous=releases/OLD_RUNTIME_NAME
            verify_unit(unit,config,(previous,target) if unit_bytes==new_unit else (previous,),
                        allow_pending_reload=unit_bytes==new_unit)
            def guard():
                lock();unit_parent.verify();config_parent.verify();state_parent.verify()
                need(exact_key(config_before)==exact_key(os.fstat(config_fd))
                     ==exact_key(os.stat(config.name,dir_fd=config_parent.fd,follow_symlinks=False)),
                     'CONFIGURATION_METADATA_CHANGED')
                need(exact_key(state_before)==exact_key(os.stat('current.json',dir_fd=state_parent.fd,
                     follow_symlinks=False)) and read_at(state_parent,'current.json',MAX_STATE,0o600)
                     ==state_bytes,'STATE_CHANGED')
            guard()
            phase='RUNTIME_INSTALL';target=install_runtime(releases,payloads,pins)
            report['runtime_directory']=str(target)
            phase='RUNTIME_PROBE';proof=probe(target,live,work,state_bytes,pins);guard()
            if unit_bytes==new_unit:
                # Recover a previous interruption after the disk switch but
                # before systemd acknowledged its reload.
                command(['/usr/bin/systemctl','daemon-reload'])
                verify_unit(unit,config,(target,))
                guard();proof=probe(target,live,work,state_bytes,pins);guard()
                need(key(before_unit)==key(os.fstat(unit_fd))==key(os.stat(unit.name,
                     dir_fd=unit_parent.fd,follow_symlinks=False)),'SERVICE_UNIT_CHANGED')
                report.update(ok=True,already_current=True,phase='DONE',**proof)
                return report
            phase='AUDIT';work_dir=Directory(work,0o700)
            capture_name='checkout-policy-upgrade-'+uuid.uuid4().hex
            os.mkdir(capture_name,0o700,dir_fd=work_dir.fd);os.fsync(work_dir.fd)
            capture=Directory(work/capture_name,0o700);report['audit_directory']=str(work/capture_name)
            need(os.fstat(capture.fd).st_dev==os.fstat(unit_parent.fd).st_dev,'FILESYSTEM_MISMATCH')
            write_at(capture,'before-unit.bin',unit_bytes)
            write_at(capture,'exchange-unit.service',new_unit,0o644)
            new_fd=os.open('exchange-unit.service',os.O_RDONLY|os.O_NOFOLLOW,dir_fd=capture.fd)
            new_item=os.fstat(new_fd)
            json_at(capture,'before.json',{'format':1,'runtime_directory':str(target),'pins':pins,
                'old_pins':old_pins,'unit_before_sha256':sha(unit_bytes),'unit_after_sha256':sha(new_unit),
                'unit_identity':list(key(before_unit)),'state_sha256':sha(state_bytes),
                'configuration_identity':list(exact_key(config_before))})
            command(['/usr/bin/systemd-analyze','verify',str(work/capture_name/'exchange-unit.service')])
            phase='UNIT_SWITCH';guard()
            need(key(before_unit)==key(os.fstat(unit_fd))==key(os.stat(unit.name,
                 dir_fd=unit_parent.fd,follow_symlinks=False)) and read_fd(unit_fd,64*1024,0o644)==unit_bytes,
                 'SERVICE_UNIT_CHANGED')
            if before_exchange is not None:before_exchange()
            with critical():
                attempted=True
                exchange(capture.fd,'exchange-unit.service',unit_parent.fd,unit.name)
                os.fsync(capture.fd);os.fsync(unit_parent.fd)
                need(key(before_unit)==key(os.stat('exchange-unit.service',dir_fd=capture.fd,
                     follow_symlinks=False)),'DISPLACED_UNIT_CHANGED')
            need(key(new_item)==key(os.fstat(new_fd))==key(os.stat(unit.name,
                 dir_fd=unit_parent.fd,follow_symlinks=False)) and read_fd(new_fd,64*1024,0o644)==new_unit,
                 'NEW_UNIT_CHANGED')
            report['unit_changed']=True
            phase='DAEMON_RELOAD';command(['/usr/bin/systemctl','daemon-reload'])
            verify_unit(unit,config,(target,))
            phase='POSTFLIGHT';guard();proof=probe(target,live,work,state_bytes,pins);guard()
            need(key(new_item)==key(os.fstat(new_fd))==key(os.stat(unit.name,
                 dir_fd=unit_parent.fd,follow_symlinks=False)),'NEW_UNIT_CHANGED')
            json_at(capture,'completed.json',{'format':1,'ok':True,**proof})
            report.update(ok=True,phase='DONE',already_current=False,**proof)
        return report
    except BaseException as error:
        report.update(phase=phase,reason=str(error) if isinstance(error,Stop)
            and re.fullmatch('[A-Z_]{1,100}',str(error)) else
            'INTERRUPTED' if isinstance(error,(KeyboardInterrupt,SystemExit)) else 'PRIVATE_FAILURE')
        if attempted:
            try:
                unit_parent.verify();capture.verify()
                current=os.stat(unit.name,dir_fd=unit_parent.fd,follow_symlinks=False)
                if key(current)==key(new_item) and read_fd(new_fd,64*1024,0o644)==new_unit:
                    with critical():
                        exchange(capture.fd,'exchange-unit.service',unit_parent.fd,unit.name)
                        os.fsync(capture.fd);os.fsync(unit_parent.fd)
                        need(key(new_item)==key(os.stat('exchange-unit.service',dir_fd=capture.fd,
                             follow_symlinks=False)),'ROLLBACK_DISPLACED_CHANGED')
                restored=os.stat(unit.name,dir_fd=unit_parent.fd,follow_symlinks=False)
                need(key(restored)==key(before_unit) and read_fd(unit_fd,64*1024,0o644)==unit_bytes,
                     'ROLLBACK_UNIT_CHANGED')
                report['unit_changed']=False
                command(['/usr/bin/systemctl','daemon-reload'])
                verify_unit(unit,config,(previous,))
            except BaseException:
                report.update(rollback_complete=False,unit_changed=True)
                try:
                    report['unit_changed']=key(os.stat(unit.name,dir_fd=unit_parent.fd,
                        follow_symlinks=False))!=key(before_unit)
                except Exception:pass
        if capture is not None:
            try:json_at(capture,'failed.json',report)
            except Exception:report['audit_write_failed']=True
        return report
    finally:
        for fd in (config_fd,unit_fd,new_fd):
            if fd is not None:os.close(fd)
        for directory in (capture,unit_parent,config_parent,state_parent,work_dir):
            if directory is not None:directory.close()
        if lock_entered:
            try:lock_manager.__exit__(None,None,None)
            except BaseException:report.update(ok=False,phase='LOCK_RELEASE',reason='PRIVATE_FAILURE')

def execute(args):
    need(len(args)==2 and args[1]=='--upgrade','INVOCATION_INVALID')
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    directory=Directory(Path(args[0]),0o700)
    try:
        need(set(os.listdir(directory.fd))==set(ROSTER),'PAYLOAD_SCOPE_INVALID')
        payloads={name:read_at(directory,name,MAX_FILE,0o600) for name in ROSTER}
    finally:directory.close()
    return upgrade_runtime(payloads,PINS,OLD_PINS)

def main(args=None):
    def interrupted(_signal,_frame):raise Stop('INTERRUPTED')
    previous={number:signal.signal(number,interrupted)
              for number in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP)}
    try:report=execute(sys.argv[1:] if args is None else args)
    except BaseException as error:
        report={'format':1,'kind':'go-erp-checkout-policy-upgrade','ok':False,'phase':'ENTRY',
            'reason':str(error) if isinstance(error,Stop) and re.fullmatch('[A-Z_]{1,100}',str(error)) else 'PRIVATE_FAILURE',
            'application_contents_changed':False,'database_changed':False,'configuration_read':False,
            'configuration_changed':False,'state_changed':False,'credentials_disclosed':False}
    finally:
        for number,handler in previous.items():signal.signal(number,handler)
    print(json.dumps(report,sort_keys=True));return 0 if report.get('ok') is True else 1

if __name__=='__main__':sys.exit(main())
