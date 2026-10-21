#!/usr/bin/env python3
"""Remove only other-write from six byte-proven B3 public JavaScript files."""
import contextlib
import errno
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
BACKUPS = Path('/home/fasakha/desktop-dashboard-release-backups')
B3 = '75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
PREFIX = 'public/site/js/'
FILE_PINS = {
    PREFIX+'bootstrap.bundle.min.js':'fe19d88aeb7277028a2265b9ed06ab8f1bd29fe1',
    PREFIX+'jquery-3.6.0.min.js':'c4c6022f2982e8dae64cebd6b9a2b59f2547faad',
    PREFIX+'jquery.nice-select.min.js':'88eb2d6c809c1a7a94dead67f023a2ee2ecf262e',
    PREFIX+'script.js':'8c51b569071d19f31ad76f42c5110cdea0926282',
    PREFIX+'swiper-bundle.min.js':'6dac048c2180fa9f1fcbf6996c40dcf24a9c4417',
    PREFIX+'wow.min.js':'a123b5e0fdccf0db5e153b2a0e17d0261747499c',
}
MODULE_PINS = ('1842302a87b7e93253ad5be616c3ab3e56bdd4a1',
               'c09b74e27e968e66caf36a65822f10d5cb0ea21a',
               '795cf1ea467143e8a8ba0d4b6213a0c5ab36e741')
MAX_FILE = 8*1024*1024
MAX_TOTAL = 256*1024*1024
PHASES = frozenset(('ENTRY','SEED','LOCK','PLAN','INVENTORY','VERIFY','AUDIT',
                    'APPLY','POST_VERIFY','LOCK_EXIT'))
REASONS = frozenset(('ROOT_AND_HOST_REQUIRED','INVOCATION_INVALID','PRIVATE_HELPER_UNSAFE',
    'PRIVATE_HELPER_CHANGED','PRIVATE_HELPER_PIN_INVALID','SEED_REVISION_INVALID','SEED_ROSTER_INVALID',
    'SEED_BLOB_INVALID','DIRECTORY_CHANGED','FILE_CHANGED','FILE_BYTES_CHANGED','READ_LIMIT',
    'TOTAL_LIMIT','SCOPE_CHANGED','MODE_POSTCONDITION_FAILED','POLICY_BLOCKED'))

class Stop(Exception):
    pass

def need(ok, code):
    if not ok:
        raise Stop(code)

def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def identity(value):
    return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
            value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)

def directory_identity(value):
    return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid)

def read_blob(fd, saved):
    os.lseek(fd,0,os.SEEK_SET)
    data = bytearray()
    while len(data) <= MAX_FILE:
        part = os.read(fd,min(65536,MAX_FILE+1-len(data)))
        if not part:
            break
        data.extend(part)
    need(len(data) == saved.st_size and len(data) <= MAX_FILE,'READ_LIMIT')
    need(identity(os.fstat(fd)) == identity(saved),'FILE_CHANGED')
    return blob(data)

def private_module(path, pin):
    path = Path(path)
    need(path.is_absolute() and '..' not in path.parts,'PRIVATE_HELPER_UNSAFE')
    held = []
    fd = None
    try:
        parent = os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        held.append((parent,os.fstat(parent),None,None))
        for index,name in enumerate(path.parts[1:-1]):
            previous = parent
            parent = os.open(name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=previous)
            value = os.fstat(parent)
            held.append((parent,value,previous,name))
            sticky = index < len(path.parts)-3 and bool(value.st_mode & stat.S_ISVTX)
            need(value.st_uid == 0 and (not value.st_mode & 0o022 or sticky),'PRIVATE_HELPER_UNSAFE')
        need(stat.S_IMODE(os.fstat(parent).st_mode) == 0o700,'PRIVATE_HELPER_UNSAFE')
        fd = os.open(path.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=parent)
        saved = os.fstat(fd)
        need(stat.S_ISREG(saved.st_mode) and (saved.st_uid,saved.st_gid,saved.st_nlink,
             stat.S_IMODE(saved.st_mode)) == (0,0,1,0o600) and 0 < saved.st_size <= 512*1024,
             'PRIVATE_HELPER_UNSAFE')
        data = os.read(fd,512*1024+1)
        need(len(data) == saved.st_size and identity(os.fstat(fd)) == identity(saved)
             == identity(os.stat(path.name,dir_fd=parent,follow_symlinks=False)),'PRIVATE_HELPER_CHANGED')
        for directory,value,previous,name in held:
            need(directory_identity(os.fstat(directory)) == directory_identity(value),'PRIVATE_HELPER_CHANGED')
            if previous is not None:
                need(directory_identity(os.stat(name,dir_fd=previous,follow_symlinks=False))
                     == directory_identity(value),'PRIVATE_HELPER_CHANGED')
        need(blob(data) == pin,'PRIVATE_HELPER_PIN_INVALID')
        namespace = {'__name__':'reviewed_static_mode_'+pin}
        exec(compile(data,str(path),'exec'),namespace)
        return namespace
    finally:
        if fd is not None:
            os.close(fd)
        for directory,*_ in reversed(held):
            os.close(directory)

def world_write_inventory(source, root, archive, omitted=()):
    """Bounded source metadata only; exclusions precede every entry stat."""
    report = {'remaining_world_writable':[], 'remaining_world_writable_count':0,
              'redacted_count':0, 'inventory_complete':False}
    root_fd = None
    try:
        omitted = set(omitted)
        need(omitted <= set(FILE_PINS), 'POLICY_BLOCKED')
        root_fd, ancestry = source._open_root(root)
        entries = 0
        truncated = False
        def visit(fd, prefix=''):
            nonlocal entries, truncated
            before = source._identity(os.fstat(fd))
            observed = []
            for component in sorted(os.listdir(fd)):
                entries += 1
                need(entries <= 100000, 'POLICY_BLOCKED')
                name = prefix+component
                if not source._in_scope(name) or source._excluded(name):
                    continue
                value = os.stat(component, dir_fd=fd, follow_symlinks=False)
                observed.append((component, source._identity(value)))
                directory = stat.S_ISDIR(value.st_mode)
                regular = stat.S_ISREG(value.st_mode)
                if not directory and not regular:
                    truncated = True
                    continue
                if value.st_mode & 0o002 and name not in omitted:
                    report['remaining_world_writable_count'] += 1
                    if not archive['safe_source_path'](name):
                        report['redacted_count'] += 1
                    elif len(report['remaining_world_writable']) < 100:
                        report['remaining_world_writable'].append({'path':name,
                            'uid':value.st_uid, 'gid':value.st_gid,
                            'mode':format(stat.S_IMODE(value.st_mode), '04o'),
                            'kind':'directory' if directory else 'file'})
                    else:
                        truncated = True
                if directory:
                    child = os.open(component, source._DIR_FLAGS, dir_fd=fd)
                    try:
                        need(source._identity(os.fstat(child)) == source._identity(value), 'DIRECTORY_CHANGED')
                        visit(child, name+'/')
                        need(source._identity(os.fstat(child)) == source._identity(value), 'DIRECTORY_CHANGED')
                    finally:
                        os.close(child)
            for component, expected in observed:
                need(source._identity(os.stat(component, dir_fd=fd, follow_symlinks=False))
                     == expected, 'DIRECTORY_CHANGED')
            need(source._identity(os.fstat(fd)) == before, 'DIRECTORY_CHANGED')
        visit(root_fd)
        check_fd, after = source._open_root(root)
        os.close(check_fd)
        need(after == ancestry, 'DIRECTORY_CHANGED')
        report['inventory_complete'] = not truncated
    except Exception:
        # Never disclose private paths or exception text from a diagnostic walk.
        report['inventory_complete'] = False
    finally:
        if root_fd is not None:
            os.close(root_fd)
    return report

def safe_error(error, archive=None, ops=None):
    if type(error) is Stop and len(error.args) == 1 and error.args[0] in REASONS:
        return 'GO_ERP_STATIC_MODES_'+error.args[0]
    if archive and type(error) is archive['Stop'] and str(error) in {
            'GO_ERP_META_BACKUP_OPERATION_BUSY','GO_ERP_META_BACKUP_LOCK_UNSAFE','GO_ERP_META_BACKUP_LOCK_CHANGED'}:
        return str(error)
    if ops and type(error) is ops['Stop'] and str(error) in {
            'DIRECTORY_UNSAFE','DIRECTORY_OWNER_INVALID','DIRECTORY_MODE_INVALID','DIRECTORY_CHANGED',
            'AUDIT_FILE_UNSAFE','AUDIT_WRITE_FAILED','FILE_SCOPE_INVALID'}:
        return 'GO_ERP_STATIC_MODES_'+str(error)
    return 'PRIVATE_FAILURE'

def run_repair(root,work,backups,source,ops,archive,requested=True,before_chmod=None):
    result = {'kind':'go-erp-static-source-modes','ok':False,'changed':0,'pending':0,
              'attempted':0,'verified_changed':0,'metadata_change_possible':False,'uncertain_paths':[],
              'application_contents_changed':False,'database_changed':False,'issues':[]}
    rows = []
    directory = private = audit = None
    phase = 'LOCK'
    try:
        with archive['locked'](work,'watcher.lock',(0,0),ops) as watcher:
            with archive['locked'](backups,'server-install.lock',(1001,1003),ops) as installer:
                def locks():
                    watcher(); installer()
                phase = 'PLAN'
                directory = ops['Dirs'](root/'public/site/js')
                entries = set(os.listdir(directory.fd))
                need(len(entries) <= 10000,'SCOPE_CHANGED')
                snapshots = {name:os.stat(name,dir_fd=directory.fd,follow_symlinks=False)
                             for name in sorted(entries)}
                total = 0
                for name in sorted(FILE_PINS):
                    short = name[len(PREFIX):]
                    saved = snapshots.get(short)
                    issue = None
                    if saved is None:
                        issue = 'MISSING_APPROVED_FILE'
                    elif not (stat.S_ISREG(saved.st_mode) and saved.st_nlink == 1
                              and (saved.st_uid,saved.st_gid) in ((0,0),(1001,1003))
                              and 0 <= saved.st_size <= MAX_FILE):
                        issue = 'APPROVED_FILE_METADATA_INVALID'
                    if issue:
                        item = {'path':name,'reason':issue}
                        if saved is not None:
                            item.update(uid=saved.st_uid,gid=saved.st_gid,mode=stat.S_IMODE(saved.st_mode))
                        result['issues'].append(item)
                        continue
                    fd = os.open(short,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
                    row = {'path':name,'name':short,'fd':fd,'saved':saved,'expected':saved,
                           'before_mode':stat.S_IMODE(saved.st_mode),
                           'after_mode':stat.S_IMODE(saved.st_mode)&~0o002}
                    rows.append(row)
                    need(identity(os.fstat(fd)) == identity(saved),'FILE_CHANGED')
                    row['blob'] = read_blob(fd,saved)
                    total += saved.st_size
                    need(total <= MAX_TOTAL,'TOTAL_LIMIT')
                    if saved.st_mode & 0o002 and row['blob'] != FILE_PINS[name]:
                        result['issues'].append({'path':name,'reason':'WORLD_WRITABLE_BYTES_DIFFER',
                            'expected_blob':FILE_PINS[name],'actual_blob':row['blob'],
                            'uid':saved.st_uid,'gid':saved.st_gid,'mode':stat.S_IMODE(saved.st_mode)})
                for short,saved in snapshots.items():
                    name = PREFIX+short
                    if name not in FILE_PINS and saved.st_mode & 0o002:
                        item = {'reason':'UNAPPROVED_WORLD_WRITABLE_ENTRY','uid':saved.st_uid,
                                'gid':saved.st_gid,'mode':stat.S_IMODE(saved.st_mode)}
                        if archive['safe_source_path'](name):
                            item['path'] = name
                        else:
                            item['path_redacted'] = True
                        result['issues'].append(item)
                result['issue_count'] = len(result['issues'])
                result['issues'] = result['issues'][:100]
                candidates = [row for row in rows if row['before_mode'] != row['after_mode']]
                result['pending'] = len(candidates)
                result['planned'] = len(candidates)
                phase = 'INVENTORY'
                omitted = [row['path'] for row in candidates] if requested and not result['issue_count'] else []
                result.update(world_write_inventory(source,root,archive,omitted))
                result['inventory_omitted_planned_paths'] = omitted
                result['inventory_omitted_count'] = len(omitted)
                if result['issue_count']:
                    result.update(phase='PLAN',reason='GO_ERP_STATIC_MODES_POLICY_BLOCKED')
                    return result
                def verify():
                    locks(); directory.verify()
                    need(set(os.listdir(directory.fd)) == entries,'SCOPE_CHANGED')
                    for short,saved in snapshots.items():
                        expected = next((row['expected'] for row in rows if row['name'] == short),saved)
                        need(identity(os.stat(short,dir_fd=directory.fd,follow_symlinks=False))
                             == identity(expected),'SCOPE_CHANGED')
                    for row in rows:
                        expected = row['expected']
                        need(identity(os.fstat(row['fd'])) == identity(expected),'FILE_CHANGED')
                        need(read_blob(row['fd'],expected) == row['blob'],'FILE_BYTES_CHANGED')
                phase = 'VERIFY'
                verify()
                if not requested or not candidates:
                    result.update(ok=True,phase=phase)
                    return result
                phase = 'AUDIT'
                private = ops['Dirs'](work,(0,0),0o700)
                audit_name = 'static-source-modes-'+uuid.uuid4().hex
                os.mkdir(audit_name,0o700,dir_fd=private.fd)
                os.fsync(private.fd)
                audit = ops['Dirs'](work/audit_name,(0,0),0o700)
                result['audit'] = str(work/audit_name)
                record = {'kind':result['kind'],'seed_revision':B3,'files':[
                    {'path':row['path'],'blob':row['blob'],'before':row['before_mode'],
                     'after':row['after_mode'],'device':row['saved'].st_dev,'inode':row['saved'].st_ino,
                     'uid':row['saved'].st_uid,'gid':row['saved'].st_gid,'mtime_ns':row['saved'].st_mtime_ns}
                    for row in candidates]}
                ops['json_at'](audit,'plan.json',record)
                verify()
                phase = 'APPLY'
                for row in candidates:
                    if before_chmod is not None:
                        before_chmod(row)
                    verify()
                    result['attempted'] += 1
                    result['metadata_change_possible'] = True
                    result['uncertain_paths'] = [row['path']]
                    os.fchmod(row['fd'],row['after_mode'])
                    result['changed'] += 1
                    os.fsync(row['fd'])
                    after = os.fstat(row['fd'])
                    expected = list(identity(row['saved']))
                    expected[2] = stat.S_IFMT(row['saved'].st_mode)|row['after_mode']
                    need(identity(after)[:-1] == tuple(expected)[:-1],'MODE_POSTCONDITION_FAILED')
                    row['expected'] = after
                    need(read_blob(row['fd'],after) == row['blob'],'FILE_BYTES_CHANGED')
                    need(identity(os.stat(row['name'],dir_fd=directory.fd,follow_symlinks=False))
                         == identity(after),'FILE_CHANGED')
                    result['verified_changed'] += 1
                    result['pending'] -= 1
                    result['metadata_change_possible'] = False
                    result['uncertain_paths'] = []
                    ops['json_at'](audit,'applied-{:04d}.json'.format(result['changed']),
                                   {'path':row['path'],'mode':row['after_mode'],'applied':result['changed']})
                phase = 'POST_VERIFY'
                verify()
                ops['json_at'](audit,'completed.json',{'applied':result['changed'],'verified':True})
                phase = 'LOCK_EXIT'
        result.update(ok=True,pending=0,phase=phase)
        return result
    except Exception as error:
        result.update(ok=False,phase=phase,reason=safe_error(error,archive,ops))
        if result['attempted']:
            try:
                result.update(world_write_inventory(source,root,archive))
                result['inventory_omitted_planned_paths'] = []
                result['inventory_omitted_count'] = 0
            except Exception:
                result['inventory_complete'] = False
        if isinstance(error,OSError) and error.errno in {
                errno.EACCES,errno.EPERM,errno.ENOENT,errno.ELOOP,errno.ENOSPC,errno.EROFS,errno.EIO}:
            result['errno'] = error.errno
        if audit is not None:
            try:
                ops['json_at'](audit,'stopped.json',{'phase':phase,'applied':result['changed'],
                    'attempted':result['attempted'],'verified_changed':result['verified_changed'],
                    'metadata_change_possible':result['metadata_change_possible'],
                    'uncertain_paths':result['uncertain_paths'],'reason':result['reason']})
            except Exception:
                pass
        return result
    finally:
        for row in rows:
            os.close(row['fd'])
        for held in (audit,private,directory):
            if held is not None:
                held.close()

def execute(args):
    need(len(args) == 3,'INVOCATION_INVALID')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'ROOT_AND_HOST_REQUIRED')
    doc,archive,ops = [private_module(path,pin) for path,pin in zip(args,MODULE_PINS)]
    source,_ = doc['load_installed'](doc['INSTALL'])
    try:
        revision,seed = source._seed(WORK/'checkout')
        need(revision == B3,'SEED_REVISION_INVALID')
        need({name for name in seed if name.startswith(PREFIX)} == set(FILE_PINS),'SEED_ROSTER_INVALID')
        need(all(blob(seed[name][0]) == pin for name,pin in FILE_PINS.items()),'SEED_BLOB_INVALID')
    except Exception as error:
        error.go_erp_static_phase = 'SEED'
        if (type(error) is source.SourceError and len(error.args) == 1
                and isinstance(error.args[0],str)
                and re.fullmatch(r'GO_ERP_LIVE_SOURCE_[A-Z_]{1,100}',error.args[0])):
            error.go_erp_static_reason = error.args[0]
        raise
    return run_repair(ROOT,WORK,BACKUPS,source,ops,archive)

def main(args=None):
    try:
        result = execute(sys.argv[1:] if args is None else args)
    except Exception as error:
        phase = getattr(error,'go_erp_static_phase','ENTRY')
        result = {'kind':'go-erp-static-source-modes','ok':False,
                  'phase':phase if phase in PHASES else 'ENTRY',
                  'reason':getattr(error,'go_erp_static_reason',safe_error(error)),
                  'application_contents_changed':False,'database_changed':False}
    print(json.dumps(result,sort_keys=True))
    return 0 if result['ok'] else 1

if __name__ == '__main__':
    sys.exit(main())
