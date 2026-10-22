#!/usr/bin/env python3
"""Apply only the two reviewed bootstrap memory changes, retaining rollback inodes."""
import contextlib
import ctypes
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
BACKUPS = Path('/home/fasakha/desktop-dashboard-release-backups')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
DOCTOR_PIN = '1842302a87b7e93253ad5be616c3ab3e56bdd4a1'
OPS_PIN = '795cf1ea467143e8a8ba0d4b6213a0c5ab36e741'
TARGETS = ('app/Services/Dashboard/DesktopDashboardBootstrap.php',
           'app/Services/Dashboard/DesktopDashboardData.php')
BEFORE = {
    TARGETS[0]: ('8b42426918adc24264d5088d28ca46e6dab3fb92553d4f18c8b23bd8da0dc3fd',
                 'a8f892ba2f52da40b7754c6c462784f8b5efb8a25dcf000b7e0f28c38ee64234'),
    TARGETS[1]: ('5f4c261278fc7824d2e0a81b0f652705bdb891db932cf0b17d747c788ccfa0f1',
                 '10773738d8b184a1442165f9badf7362736a4a98516cad05224d9967bf5d44c4'),
}
AFTER = {TARGETS[0]: '303ced5d0af761ba4d584908a66fc3c47ae4e7499ce42138380de181b4e8263e',
         TARGETS[1]: '42b1d65edd9776b783dde008049aa07baa4571a4e2ea58010ff3eb2c4b67ed46'}
MAX_FILE = 1024 * 1024

class Stop(Exception):
    pass

def need(value, reason):
    if not value:
        raise Stop(reason)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def key(value):
    return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
            value.st_nlink,value.st_size,value.st_mtime_ns)

def read_fd(fd):
    before = os.fstat(fd)
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
         and 0 < before.st_size <= MAX_FILE,'SOURCE_METADATA_INVALID')
    os.lseek(fd,0,os.SEEK_SET)
    result = bytearray()
    while len(result) <= MAX_FILE:
        part = os.read(fd,min(65536,MAX_FILE+1-len(result)))
        if not part:
            break
        result.extend(part)
    need(len(result) == before.st_size and key(before) == key(os.fstat(fd)),'SOURCE_CHANGED')
    return bytes(result)

def private_bytes(path, expected=None):
    path = Path(path)
    need(path.is_absolute() and path.resolve() == path,'PRIVATE_STAGE_UNSAFE')
    for parent in (path.parent,*path.parent.parents):
        item = parent.lstat()
        sticky = parent != path.parent and bool(item.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0
             and (not item.st_mode & 0o022 or sticky),'PRIVATE_STAGE_UNSAFE')
    need(stat.S_IMODE(path.parent.stat().st_mode) == 0o700,'PRIVATE_STAGE_UNSAFE')
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need((before.st_uid,before.st_gid,stat.S_IMODE(before.st_mode)) == (0,0,0o600),
             'PRIVATE_STAGE_UNSAFE')
        data = read_fd(fd)
        need(key(before) == key(path.lstat()),'PRIVATE_STAGE_CHANGED')
        if expected is not None:
            need(sha(data) == expected,'PRIVATE_STAGE_PIN_INVALID')
        return data
    finally:
        os.close(fd)

def private_module(path, pin):
    data = private_bytes(path)
    need(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() == pin,
         'PRIVATE_HELPER_PIN_INVALID')
    namespace = {'__name__':'reviewed_bootstrap_memory_'+pin}
    exec(compile(data,str(path),'exec'),namespace)
    return namespace

@contextlib.contextmanager
def installer_lock(backups, ops):
    directory = ops['Dirs'](backups,(1001,1003),0o700)
    fd = None
    try:
        fd = os.open('server-install.lock',os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,
                     dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
             stat.S_IMODE(before.st_mode)) == (1001,1003,1,0o600),'INSTALL_LOCK_UNSAFE')
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('INSTALL_BUSY') from None
        def verify():
            directory.verify()
            need(key(before) == key(os.fstat(fd)) == key(os.stat('server-install.lock',
                 dir_fd=directory.fd,follow_symlinks=False)),'INSTALL_LOCK_CHANGED')
        verify()
        yield verify
        verify()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()

def exchange(left_fd,left,right_fd,right):
    libc = ctypes.CDLL(None,use_errno=True)
    function = libc.renameat2
    function.argtypes = [ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
    function.restype = ctypes.c_int
    if function(left_fd,os.fsencode(left),right_fd,os.fsencode(right),2):
        raise OSError(ctypes.get_errno(),'atomic exchange failed')

def php_lint(data):
    result = subprocess.run(['/usr/sbin/runuser','-u','fasakha','--',PHP,'-n','-l'],
        input=data,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=30,
        env={'PATH':'/usr/bin:/bin','HOME':'/home/fasakha'})
    need(result.returncode == 0,'PHP_LINT_FAILED')

def projection(source, baseline, rows, changed):
    inventory = {name:dict(value) for name,value in baseline['inventory'].items()}
    for row in rows:
        if row['path'] in changed:
            inventory[row['path']].update(sha256=sha(row['after']),bytes=len(row['after']))
    return source._metadata(baseline['seedRevision'],inventory)

def patch_files(root, work, replacements, before, source, previous, ops, guard,
                lint=php_lint, before_exchange=None):
    """Caller holds both existing locks; all writes are private or exact target swaps."""
    report = {'format':1,'kind':'go-erp-bootstrap-memory-patch','ok':False,'changed':0,
              'already_current':0,'rollback_complete':True,'application_contents_changed':False,
              'database_changed':False,'config_changed':False,'credentials_disclosed':False}
    rows = []
    parent = capture = None
    changed = set()
    phase = 'PREFLIGHT'
    def actual_changes():
        installed, uncertain = set(), set()
        for row in rows:
            if not row['attempted']:
                continue
            try:
                item = os.stat(row['name'],dir_fd=row['directory'].fd,follow_symlinks=False)
                if key(item) == key(row['before']):
                    pass
                elif row['new_before'] is not None and key(item) == key(row['new_before']):
                    installed.add(row['path'])
                else:
                    uncertain.add(row['path'])
            except Exception:
                uncertain.add(row['path'])
        return installed, uncertain
    try:
        need(set(replacements) == set(TARGETS) == set(before),'TARGET_SCOPE_INVALID')
        for name in TARGETS:
            directory = ops['Dirs'](root/Path(name).parent)
            fd = None
            try:
                fd = os.open(Path(name).name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
                item = os.fstat(fd)
                need(item.st_uid in (0,1001) and not item.st_mode & (0o002|0o7000),'SOURCE_METADATA_INVALID')
                data = read_fd(fd)
                need(key(item) == key(os.stat(Path(name).name,dir_fd=directory.fd,follow_symlinks=False)),
                     'SOURCE_CHANGED')
                proposed = replacements[name]
                need(isinstance(proposed,bytes) and 0 < len(proposed) <= MAX_FILE
                     and b'\r' not in proposed,'AFTER_BYTES_INVALID')
                variants = (proposed,proposed.replace(b'\n',b'\r\n'))
                digest = sha(data)
                if digest in before[name]:
                    after = variants[before[name].index(digest)]
                elif data in variants:
                    after = data
                else:
                    raise Stop('SOURCE_PIN_INVALID')
                lint(after)
                rows.append({'path':name,'directory':directory,'name':Path(name).name,'fd':fd,
                             'before':item,'data':data,'after':after,'staged':None,'newfd':None,
                             'new_before':None,'attempted':False})
                fd = None
            except BaseException:
                if fd is not None:
                    os.close(fd)
                directory.close()
                raise
        baseline = source.scan_snapshot(root,work/'checkout',1001,previous)['metadata']
        for row in rows:
            item = baseline['inventory'].get(row['path'])
            need(isinstance(item,dict) and item['authority'] == 'live' and item['present'] is True
                 and item['sha256'] == sha(row['data']) and item['bytes'] == len(row['data']),
                 'SOURCE_SNAPSHOT_BINDING_INVALID')
        def verify(expected):
            guard()
            for row in rows:
                row['directory'].verify()
                fd = row['newfd'] if row['path'] in changed else row['fd']
                expected_item = row['new_before'] if row['path'] in changed else row['before']
                need(key(os.fstat(fd)) == key(expected_item)
                     == key(os.stat(row['name'],dir_fd=row['directory'].fd,
                     follow_symlinks=False)),'SOURCE_CHANGED')
                need(read_fd(fd) == (row['after'] if row['path'] in changed else row['data']),
                     'SOURCE_BYTES_CHANGED')
            need(source.scan_snapshot(root,work/'checkout',1001,previous)['metadata'] == expected,
                 'UNRELATED_SOURCE_CHANGED')
        verify(baseline)
        report['already_current'] = sum(row['data'] == row['after'] for row in rows)
        if report['already_current'] == len(rows):
            report.update(ok=True,phase='DONE')
            return report
        phase = 'BACKUP'
        parent = ops['Dirs'](work,(0,0),0o700)
        capture_name = 'bootstrap-memory-'+uuid.uuid4().hex
        os.mkdir(capture_name,0o700,dir_fd=parent.fd);os.fsync(parent.fd)
        capture = ops['Dirs'](work/capture_name,(0,0),0o700)
        report['audit_directory'] = str(work/capture_name)
        need(os.fstat(capture.fd).st_dev == os.fstat(rows[0]['directory'].fd).st_dev,
             'FILESYSTEM_MISMATCH')
        for index,row in enumerate(rows):
            ops['write_at'](capture,'before-{:04d}.bin'.format(index),row['data'])
            if row['data'] == row['after']:
                continue
            staged = 'exchange-{:04d}.bin'.format(index)
            ops['write_at'](capture,staged,row['after'])
            fd = os.open(staged,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=capture.fd)
            row['newfd'],row['staged'] = fd,staged
            os.fchown(fd,row['before'].st_uid,row['before'].st_gid)
            os.fchmod(fd,stat.S_IMODE(row['before'].st_mode));os.fsync(fd)
            row['new_before'] = os.fstat(fd)
            need(read_fd(fd) == row['after'],'STAGED_BYTES_CHANGED')
        ops['json_at'](capture,'before.json',{'format':1,'kind':report['kind'],'files':[
            {'path':row['path'],'before_sha256':sha(row['data']),'after_sha256':sha(row['after']),
             'identity':list(key(row['before']))} for row in rows]})
        phase = 'PUBLICATION'
        verify(baseline)
        for row in rows:
            if row['data'] == row['after']:
                continue
            if before_exchange is not None:
                before_exchange(row)
            verify(projection(source,baseline,rows,changed))
            with ops['critical']():
                row['attempted'] = True
                exchange(capture.fd,row['staged'],row['directory'].fd,row['name'])
                changed.add(row['path'])
                report.update(changed=len(changed),application_contents_changed=True)
                os.fsync(row['directory'].fd);os.fsync(capture.fd)
                need(key(os.stat(row['staged'],dir_fd=capture.fd,follow_symlinks=False))
                     == key(row['before']),'DISPLACED_SOURCE_CHANGED')
                need(read_fd(row['fd']) == row['data'],'DISPLACED_BYTES_CHANGED')
            verify(projection(source,baseline,rows,changed))
        phase = 'POSTFLIGHT'
        verify(projection(source,baseline,rows,changed))
        ops['json_at'](capture,'completed.json',{'format':1,'ok':True,'changed':len(changed)})
        report.update(ok=True,phase='DONE')
        return report
    except Exception as error:
        report['phase'] = phase
        report['reason'] = str(error) if isinstance(error,Stop) and re.fullmatch('[A-Z_]{1,100}',str(error)) else 'PRIVATE_FAILURE'
        changed,uncertain = actual_changes()
        if changed or uncertain:
            for row in reversed(rows):
                if row['path'] not in changed:
                    continue
                try:
                    guard();row['directory'].verify();capture.verify()
                    need(key(os.fstat(row['newfd'])) == key(row['new_before'])
                         == key(os.stat(row['name'],
                         dir_fd=row['directory'].fd,follow_symlinks=False))
                         and read_fd(row['newfd']) == row['after'],'ROLLBACK_SOURCE_CHANGED')
                    with ops['critical']():
                        exchange(capture.fd,row['staged'],row['directory'].fd,row['name'])
                        os.fsync(row['directory'].fd);os.fsync(capture.fd)
                        need(key(os.stat(row['staged'],dir_fd=capture.fd,follow_symlinks=False))
                             == key(row['new_before']),'ROLLBACK_DISPLACED_CHANGED')
                    changed.remove(row['path'])
                except Exception:
                    report['rollback_complete'] = False
            changed,uncertain = actual_changes()
            report['application_contents_changed'] = bool(changed or uncertain)
            report['changed'] = len(changed)
            report['uncertain'] = len(uncertain)
            if uncertain:
                report['rollback_complete'] = False
            try:
                need(source.scan_snapshot(root,work/'checkout',1001,previous)['metadata']
                     == projection(source,baseline,rows,changed),'ROLLBACK_SOURCE_PROOF_FAILED')
            except Exception:
                report['rollback_complete'] = False
        if capture is not None:
            try:
                ops['json_at'](capture,'failed.json',report)
            except Exception:
                report['audit_write_failed'] = True
        return report
    finally:
        for row in rows:
            for fd in (row['fd'],row['newfd']):
                if fd is not None:
                    os.close(fd)
            row['directory'].close()
        if capture is not None:
            capture.close()
        if parent is not None:
            parent.close()

def execute(args):
    need(len(args) == 5 and args[4] == '--apply','INVOCATION_INVALID')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'ROOT_AND_HOST_REQUIRED')
    doc = private_module(args[0],DOCTOR_PIN)
    ops = private_module(args[1],OPS_PIN)
    source,worker = doc['load_installed'](doc['INSTALL'])
    replacements = {name:private_bytes(path,AFTER[name]) for name,path in zip(TARGETS,args[2:4])}
    report = None
    try:
        with doc['existing_lock'](WORK), installer_lock(BACKUPS,ops) as install_guard:
            directory = doc['Directory'](WORK/'state',0o700)
            try:
                state_bytes = directory.read('current.json',0o600,worker.MAX_JSON)
                state = worker.parse_json(state_bytes);doc['safe_state'](state)
                dependencies = worker.dependency_snapshot(ROOT,1001)
                need(state.get('dependency_guard') in (None,dependencies),'DEPENDENCY_GUARD_MISMATCH')
                def guard():
                    install_guard();directory.verify()
                    need(directory.read('current.json',0o600,worker.MAX_JSON) == state_bytes,'WATCHER_STATE_CHANGED')
                    need(worker.dependency_snapshot(ROOT,1001) == dependencies,'DEPENDENCIES_CHANGED')
                report = patch_files(ROOT,WORK,replacements,BEFORE,source,state.get('observed'),ops,guard)
            finally:
                directory.close()
        return report
    except Exception:
        if report is None:
            raise
        report.update(ok=False,phase='LOCK_RELEASE',reason='PRIVATE_FAILURE')
        return report

def main(args=None):
    try:
        report = execute(sys.argv[1:] if args is None else args)
    except Exception as error:
        report = {'format':1,'kind':'go-erp-bootstrap-memory-patch','ok':False,
            'application_contents_changed':False,'database_changed':False,'config_changed':False,
            'credentials_disclosed':False,'reason':str(error) if isinstance(error,Stop)
            and re.fullmatch('[A-Z_]{1,100}',str(error)) else 'PRIVATE_FAILURE'}
    print(json.dumps(report,sort_keys=True))
    return 0 if report['ok'] else 1

if __name__ == '__main__':
    sys.exit(main())
