#!/usr/bin/env python3
"""Archive exactly two rejected public mail diagnostics, retaining private evidence."""
import contextlib
import fcntl
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
CUSTODY = Path('/home/.go-erp-mail-diagnostics-archives')
EXPECTED_COMMIT = '0f12420ffef0d54e97a10c53cefcbbae733d5766'
REJECTION_COMMIT = EXPECTED_COMMIT
TARGETS = ('public/phpmailer_test.php','public/smtp_test.php')
RETRY_PIN = '8da3f1f89a2ab6ecbf5979942c97c7705510f2c1'
OPS_PIN = '795cf1ea467143e8a8ba0d4b6213a0c5ab36e741'
MAX_FILE = 1024*1024
SHA = re.compile(r'[a-f0-9]{64}\Z')

class Stop(Exception):
    pass

def need(ok,code):
    if not ok:
        raise Stop(code)

def key(value):
    return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
            value.st_nlink,value.st_size,value.st_mtime_ns)

def read_fd(fd):
    before = os.fstat(fd)
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
         and 0 <= before.st_size <= MAX_FILE,'FILE_UNSAFE')
    os.lseek(fd,0,os.SEEK_SET)
    data = bytearray()
    while len(data) <= MAX_FILE:
        part = os.read(fd,min(65536,MAX_FILE+1-len(data)))
        if not part:
            break
        data.extend(part)
    need(len(data) == before.st_size and key(before) == key(os.fstat(fd)),'FILE_CHANGED')
    return bytes(data)

@contextlib.contextmanager
def locked(parent,name,owner,ops):
    directory = ops['Dirs'](parent,owner,0o700)
    fd = None
    try:
        fd = os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
             stat.S_IMODE(before.st_mode)) == (*owner,1,0o600),'LOCK_UNSAFE')
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('OPERATION_BUSY') from None
        def verify():
            directory.verify()
            need(key(os.fstat(fd)) == key(before)
                 == key(os.stat(name,dir_fd=directory.fd,follow_symlinks=False)),'LOCK_CHANGED')
        verify()
        yield verify
        verify()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()

def archive_files(root,work,backups,ops,archive,bindings,requested=True,before_move=None,
                  custody_root=None,guard=None,context=None):
    report = {'kind':'go-erp-mail-diagnostics-archive','ok':False,'captured':0,
              'already_archived':0,'pending':2,'source_files_removed':0,
              'application_contents_changed':False,'database_changed':False,'credentials_disclosed':False}
    rows = []
    public = parent = capture = None
    phase = 'LOCK'
    try:
        need(isinstance(bindings,dict) and set(bindings) == set(TARGETS)
             and all(isinstance(value,str) and SHA.fullmatch(value) for value in bindings.values()),'BINDING_INVALID')
        custody_root = CUSTODY if custody_root is None else Path(custody_root)
        capture_path = custody_root/('candidate-'+EXPECTED_COMMIT)
        with archive['locked'](work,'watcher.lock',(0,0),ops) as watcher:
            with archive['locked'](backups,'server-install.lock',(1001,1003),ops) as installer:
                def locks():
                    watcher(); installer()
                phase = 'PLAN'
                public = ops['Dirs'](root/'public')
                exists = custody_root.exists()
                if exists:
                    parent = ops['Dirs'](custody_root,(0,0),0o700)
                plan = None
                if parent is not None:
                    need(os.fstat(parent.fd).st_dev == os.fstat(public.fd).st_dev,'FILESYSTEM_MISMATCH')
                    try:
                        capture = ops['Dirs'](capture_path,(0,0),0o700)
                    except FileNotFoundError:
                        pass
                    if capture is not None:
                        plan = json.loads(ops['read_at'](capture,'plan.json',limit=16*1024*1024,owner=(0,0),mode=0o600))
                        need(isinstance(plan,dict) and plan.get('format') == 1
                             and plan.get('kind') == report['kind'] and plan.get('commit') == EXPECTED_COMMIT
                             and plan.get('bindings') == bindings and plan.get('context') == context
                             and isinstance(plan.get('rows'),list) and len(plan['rows']) == 2,'PLAN_BINDING_INVALID')
                for index,name in enumerate(TARGETS):
                    short = name.split('/')[1]
                    original = 'original-{:04d}.bin'.format(index)
                    copied = 'copy-{:04d}.bin'.format(index)
                    try:
                        live = os.stat(short,dir_fd=public.fd,follow_symlinks=False)
                    except FileNotFoundError:
                        live = None
                    held = None
                    if capture is not None:
                        try:
                            held = os.stat(original,dir_fd=capture.fd,follow_symlinks=False)
                        except FileNotFoundError:
                            pass
                    need((live is None) != (held is None),'SOURCE_CUSTODY_AMBIGUOUS')
                    moved = live is None
                    before = held if moved else live
                    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
                         and before.st_uid in (0,1001) and not before.st_mode & (0o002|0o7000)
                         and 0 < before.st_size <= MAX_FILE,'SOURCE_METADATA_INVALID')
                    fd = os.open(original if moved else short,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,
                                 dir_fd=capture.fd if moved else public.fd)
                    row = {'path':name,'name':short,'fd':fd,'before':before,'original':original,
                           'copy':copied,'moved':moved,'sha256':bindings[name]}
                    rows.append(row)
                    need(key(os.fstat(fd)) == key(before),'SOURCE_CHANGED')
                    data = read_fd(fd)
                    need(hashlib.sha256(data).hexdigest() == bindings[name],'SOURCE_BYTES_MISMATCH')
                    row['data'] = data
                    if plan is not None:
                        item = plan['rows'][index]
                        need(item == {'path':name,'sha256':bindings[name],'identity':list(key(before)),
                                      'copy':copied,'original':original},'ORIGINAL_BINDING_INVALID')
                        copied_data = ops['read_at'](capture,copied,limit=MAX_FILE,owner=(0,0),mode=0o600)
                        copied_stat = os.stat(copied,dir_fd=capture.fd,follow_symlinks=False)
                        need(copied_data == data and copied_stat.st_ino != before.st_ino,'COPY_BINDING_INVALID')
                    else:
                        need(not moved,'UNREVIEWED_MISSING_SOURCE')
                moved = {row['path'] for row in rows if row['moved']}
                report['already_archived'] = len(moved)
                report['pending'] = 2-len(moved)
                if guard is not None:
                    guard(moved)
                if not requested:
                    report.update(ok=True,phase=phase)
                    return report
                phase = 'PRIVATE_COPY'
                if plan is None:
                    if parent is None:
                        ancestor = ops['Dirs'](custody_root.parent,(0,0))
                        try:
                            os.mkdir(custody_root.name,0o700,dir_fd=ancestor.fd)
                            os.fsync(ancestor.fd)
                        finally:
                            ancestor.close()
                        parent = ops['Dirs'](custody_root,(0,0),0o700)
                    device = os.fstat(parent.fd).st_dev
                    need(device == os.fstat(public.fd).st_dev
                         and all(row['before'].st_dev == device for row in rows),'FILESYSTEM_MISMATCH')
                    os.mkdir(capture_path.name,0o700,dir_fd=parent.fd)
                    os.fsync(parent.fd)
                    capture = ops['Dirs'](capture_path,(0,0),0o700)
                    for row in rows:
                        ops['write_at'](capture,row['copy'],row['data'])
                    plan = {'format':1,'kind':report['kind'],'commit':EXPECTED_COMMIT,
                            'bindings':bindings,'context':context,'rows':[
                        {'path':row['path'],'sha256':row['sha256'],'identity':list(key(row['before'])),
                         'copy':row['copy'],'original':row['original']} for row in rows]}
                    ops['json_at'](capture,'plan.json',plan)
                    os.fsync(parent.fd)
                report['capture_directory'] = str(capture_path)
                def verify():
                    locks(); public.verify(); parent.verify(); capture.verify()
                    for row in rows:
                        where = capture if row['moved'] else public
                        name = row['original'] if row['moved'] else row['name']
                        need(key(os.fstat(row['fd'])) == key(row['before'])
                             == key(os.stat(name,dir_fd=where.fd,follow_symlinks=False)),'SOURCE_CHANGED')
                        need(hashlib.sha256(read_fd(row['fd'])).hexdigest() == row['sha256'],'SOURCE_BYTES_MISMATCH')
                        data = ops['read_at'](capture,row['copy'],limit=MAX_FILE,owner=(0,0),mode=0o600)
                        copied = os.stat(row['copy'],dir_fd=capture.fd,follow_symlinks=False)
                        need(data == row['data'] and copied.st_ino != row['before'].st_ino,'COPY_BINDING_INVALID')
                        if row['moved']:
                            try:
                                os.stat(row['name'],dir_fd=public.fd,follow_symlinks=False)
                            except FileNotFoundError:
                                pass
                            else:
                                raise Stop('SOURCE_REAPPEARED')
                phase = 'PRE_MOVE'
                verify()
                for index,row in enumerate(rows):
                    if row['moved']:
                        continue
                    if before_move is not None:
                        before_move(row)
                    verify()
                    if guard is not None:
                        guard(moved)
                    phase = 'MOVE'
                    with ops['critical']():
                        verify()
                        os.rename(row['name'],row['original'],src_dir_fd=public.fd,dst_dir_fd=capture.fd)
                        row['moved'] = True
                        moved.add(row['path'])
                        report['captured'] += 1
                        report['source_files_removed'] += 1
                        report['application_contents_changed'] = True
                        report['pending'] -= 1
                        os.fsync(public.fd); os.fsync(capture.fd)
                        verify()
                        receipt = 'moved-{:04d}.json'.format(index)
                        ops['json_at'](capture,receipt,{'path':row['path'],'sha256':row['sha256'],
                                                       'original_inode':row['before'].st_ino})
                phase = 'POST_SCAN'
                verify()
                if guard is not None:
                    guard(moved)
                completed = {'archived':2,'source_verified':True}
                try:
                    prior = ops['read_at'](capture,'completed.json',owner=(0,0),mode=0o600)
                except FileNotFoundError:
                    ops['json_at'](capture,'completed.json',completed)
                else:
                    need(json.loads(prior) == completed,'COMPLETED_BINDING_INVALID')
                report.update(ok=True,phase=phase,pending=0)
        return report
    except Exception as error:
        report.update(ok=False,phase=phase,reason='PRIVATE_FAILURE')
        if type(error) is Stop and re.fullmatch(r'[A-Z_]{1,80}',str(error)):
            report['reason'] = 'GO_ERP_MAIL_ARCHIVE_'+str(error)
        if capture is not None and requested:
            try:
                ops['json_at'](capture,'stopped-'+uuid.uuid4().hex+'.json',
                    {'phase':phase,'captured':report['captured'],'already_archived':report['already_archived'],
                     'pending':report['pending'],'reason':report['reason']})
            except Exception:
                pass
        return report
    finally:
        for row in rows:
            os.close(row['fd'])
        for directory in (capture,parent,public):
            if directory is not None:
                directory.close()

def projected(source,observed,removed):
    inventory = {name:dict(row) for name,row in observed['inventory'].items()}
    for name in removed:
        need(name in TARGETS,'REMOVAL_SCOPE_INVALID')
        inventory[name] = {'authority':'removed','present':False,'sha256':None,'bytes':0,
                           'mode':inventory[name]['mode']}
    return source._metadata(observed['seedRevision'],inventory)

def private_module(path,pin):
    path = Path(path)
    need(path.is_absolute() and path.resolve() == path,'PRIVATE_HELPER_UNSAFE')
    for parent in (path.parent,*path.parent.parents):
        value = parent.lstat()
        sticky = parent != path.parent and bool(value.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(value.st_mode) and value.st_uid == 0
             and (not value.st_mode & 0o022 or sticky),'PRIVATE_HELPER_UNSAFE')
    need(stat.S_IMODE(path.parent.stat().st_mode) == 0o700,'PRIVATE_HELPER_UNSAFE')
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
             stat.S_IMODE(before.st_mode)) == (0,0,1,0o600) and 0 < before.st_size <= 512*1024,'PRIVATE_HELPER_UNSAFE')
        data = os.read(fd,512*1024+1)
        need(len(data) == before.st_size and key(before) == key(os.fstat(fd)) == key(path.stat())
             and hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() == pin,'PRIVATE_HELPER_CHANGED')
        namespace = {'__name__':'reviewed_mail_archive_'+pin}
        exec(compile(data,str(path),'exec'),namespace)
        return namespace
    finally:
        os.close(fd)

def execute(args):
    need(len(args) == 4 and args[3] == '--archive','INVOCATION_INVALID')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524','ROOT_AND_HOST_REQUIRED')
    retry = private_module(args[0],RETRY_PIN)
    doc = retry['private_doctor'](args[1])
    ops = private_module(args[2],OPS_PIN)
    source,worker = doc['load_installed'](doc['INSTALL'])
    def read_state():
        directory = doc['Directory'](WORK/'state',0o700)
        try:
            state = worker.parse_json(directory.read('current.json',0o600,worker.MAX_JSON))
            doc['safe_state'](state)
            return state
        finally:
            directory.close()
    context = bindings = None
    try:
        capture = ops['Dirs'](CUSTODY/('candidate-'+EXPECTED_COMMIT),(0,0),0o700)
    except FileNotFoundError:
        pass
    else:
        try:
            plan = json.loads(ops['read_at'](capture,'plan.json',limit=worker.MAX_JSON,owner=(0,0),mode=0o600))
            context,bindings = plan['context'],plan['bindings']
        finally:
            capture.close()
    state = read_state()
    if context is None:
        desired = state.get('desired')
        need(isinstance(desired,dict) and isinstance(desired.get('commit'),str)
             and re.fullmatch(r'[a-f0-9]{40}',desired['commit'])
             and desired.get('status') == 'prepared' and desired.get('pushed') is False,'PREPARED_CANDIDATE_REQUIRED')
        bound_commit = desired['commit']
        candidate = Path(desired.get('candidatePath',''))
        need(candidate.parent == WORK/'staging'
             and re.fullmatch(r'candidate-[1-9][0-9]*-[a-f0-9]{16}',candidate.name)
             and candidate.name.endswith('-'+desired['inventorySha256'][:16]),'CANDIDATE_PATH_INVALID')
        need(source._trusted_git(candidate) == bound_commit
             and source._git(candidate,['rev-parse','HEAD^']).decode().strip() == doc['BASE'],'CANDIDATE_BINDING_INVALID')
        need(not source._git(candidate,['ls-tree','-z',doc['BASE'],'--',*TARGETS]),'TARGETS_NOT_NEW')
        observed = state['observed']
        need(observed == source._metadata(doc['BASE'],observed['inventory'])
             and observed['inventorySha256'] == desired['inventorySha256'],'OBSERVED_BINDING_INVALID')
        bindings = {}
        root_fd,_ = source._open_root(candidate,trusted=True)
        try:
            data,_,_ = source._read_at(root_fd,source.CANDIDATE_PATH,trusted=True)
            need(worker.parse_json(data) == source._candidate_metadata(observed,desired['version'],state['counter']),
                 'CANDIDATE_METADATA_INVALID')
            for name in TARGETS:
                row = observed['inventory'][name]
                need(row['authority'] == 'live' and row['present'] is True,'TARGET_AUTHORITY_INVALID')
                data,_,_ = source._read_at(root_fd,name,trusted=True)
                raw_blob = source._git(candidate,['cat-file','blob',bound_commit+':'+name])
                need(len(data) <= MAX_FILE and data == raw_blob
                     and hashlib.sha256(raw_blob).hexdigest() == row['sha256'],'CANDIDATE_BYTES_INVALID')
                bindings[name] = row['sha256']
        finally:
            os.close(root_fd)
        context = {'commit':EXPECTED_COMMIT,'rejection_commit':REJECTION_COMMIT,
                   'bound_candidate_commit':bound_commit,'base':doc['BASE'],'observed':observed}
    need(isinstance(context,dict) and context.get('commit') == EXPECTED_COMMIT
         and context.get('rejection_commit') == REJECTION_COMMIT
         and isinstance(context.get('bound_candidate_commit'),str)
         and re.fullmatch(r'[a-f0-9]{40}',context['bound_candidate_commit'])
         and context.get('base') == doc['BASE'],'CONTEXT_BINDING_INVALID')
    observed = context['observed']
    def guard(moved):
        current = read_state()
        allowed = [projected(source,observed,set()),projected(source,observed,{TARGETS[0]}),
                   projected(source,observed,{TARGETS[1]}),projected(source,observed,set(TARGETS))]
        need(current.get('observed') in allowed,'CURRENT_STATE_CHANGED')
        actual = source.scan_snapshot(ROOT,WORK/'checkout',1001,observed)['metadata']
        need(actual == projected(source,observed,moved),'UNRELATED_SOURCE_CHANGED')
    return archive_files(ROOT,WORK,BACKUPS,ops,{'locked':lambda parent,name,owner,ops:locked(parent,name,owner,ops)},
                         bindings,custody_root=CUSTODY,guard=guard,context=context)

def main(args=None):
    try:
        report = execute(sys.argv[1:] if args is None else args)
    except Exception as error:
        report = {'ok':False,'kind':'go-erp-mail-diagnostics-archive','reason':'PRIVATE_FAILURE',
                  'application_contents_changed':False,'database_changed':False,'credentials_disclosed':False}
        if type(error) is Stop and re.fullmatch(r'[A-Z_]{1,80}',str(error)):
            report['reason'] = 'GO_ERP_MAIL_ARCHIVE_'+str(error)
    print(json.dumps(report,sort_keys=True))
    return 0 if report['ok'] else 1

if __name__ == '__main__':
    sys.exit(main())
