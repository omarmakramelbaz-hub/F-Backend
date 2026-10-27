#!/usr/bin/env python3
"""Normalize only twelve observed, exact-hash predecessor files, then resume.

The source transaction and application bytes remain unchanged. Immutable private
intent binds every inode/old mode before the first chmod; completion permits the
unchanged original operator to resume after its own source/schema checkpoints.
"""
import base64
from contextlib import ExitStack
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
import types
import zlib

CORE='052e69008da7e330815b376824cd8afc18cf04c1'
OPERATOR='29b245a5634e9fda747138cb0c0dbb103f1bc76a'
PINS_SHA256='ed1fd7e640ac473aecc6c8a9171754f36bad048234ad28eddedb7c33130a7237'
SOURCE_SHA256='0e6cade4b9fd20a6a73ce7a79a587e67c69b9cecb1b6c852a62c531fcd2c5375'
SOURCE_COMPRESSED='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'
ROWS=[{'path': 'app/Http/Controllers/Api/V1/Vendor/OrderController.php', 'sha256': '96b32d5b5535efdc129714c0d690d3afb8404aac92c76bd26177e9036bb04ec1', 'size': 34058, 'old_mode': 493, 'target_mode': 420}, {'path': 'app/Http/Controllers/Dashboard/AdvertisingController.php', 'sha256': '369504f700e13972035dbff282ee1f96e57db217f75d2494766c5831cadde3b9', 'size': 3805, 'old_mode': 509, 'target_mode': 420}, {'path': 'app/Http/Controllers/Dashboard/OrderController.php', 'sha256': 'a9f22936b5d7eacf38b01e38487857bc68abb1c8c3e778f9a9e129d124c73f29', 'size': 31320, 'old_mode': 493, 'target_mode': 420}, {'path': 'app/Http/Controllers/Dashboard/ReportController.php', 'sha256': '0e7694cafa4d8d4923f2ecd1333756b8b74171e5fe9f69e5d3d4ebd110ed0da2', 'size': 11059, 'old_mode': 493, 'target_mode': 420}, {'path': 'app/Http/Controllers/Dashboard/UserController.php', 'sha256': '6bb82e6ff548625eb6d13cf77ac69682051b2012c3987399337743bbdfd5b2a5', 'size': 6532, 'old_mode': 509, 'target_mode': 420}, {'path': 'app/Http/Controllers/Dashboard/WalletController.php', 'sha256': 'b02eb062440251e84bcd8ae237e5e5b154b96a75999e5dc5bdeb1bc7453a0eb9', 'size': 2099, 'old_mode': 493, 'target_mode': 420}, {'path': 'app/Repositories/ResturantRepository.php', 'sha256': '80bdac964b5c6c3f7fdb8414257eb0f5a38182da3128bdc2bd87646f8dc8bf71', 'size': 6795, 'old_mode': 493, 'target_mode': 420}, {'path': 'app/Repositories/UserRepository.php', 'sha256': '828fffed385907e38e44565f1135e0b40b607c89d3e54874782376270929a1a5', 'size': 11268, 'old_mode': 493, 'target_mode': 420}, {'path': 'app/Scopes/ResturantScope.php', 'sha256': '6e163b2903a82ee22874ab59ff4e2809a6c9cbe2aaed5bdf3208e8c755436720', 'size': 1073, 'old_mode': 509, 'target_mode': 420}, {'path': 'app/Services/Dashboard/TakeawayAccess.php', 'sha256': 'ab7715f2c50ddf02958b0425c5dfbb890a2b102fac5271c07b6ec194efc26e85', 'size': 9580, 'old_mode': 384, 'target_mode': 416}, {'path': 'resources/views/admin/wallets/form.blade.php', 'sha256': 'dac12380286d582503fe5c04f7d923418a26c4bcaf4f286e773e4fc951b00c4d', 'size': 1231, 'old_mode': 509, 'target_mode': 420}, {'path': 'resources/views/admin/wallets/withdraw_form.blade.php', 'sha256': '423bafa6aaaa5399647be89e559b4ee3588732862d533d99dd4652421f7e5b1e', 'size': 1281, 'old_mode': 509, 'target_mode': 420}]
ROOT=Path('/home/fasakha/public_html')
APP_UID,APP_GID=1001,1003
PRIVATE=Path('/var/lib/go-erp-manual-updates/private')
WORK=Path('/var/lib/go-erp-live-updates')
AUDITS=('workspace-source-bbeaa55b1e2bd548734237d844aedfd0bb640b6f312a988e06d5cf501780fd71',
    'workspace-source-1cde384859a557e75eb69c8eb294a8d8cd0c76e4ec2703b2287abe60448977d5')

class Stop(Exception):pass
def need(value,code):
    if not value:raise Stop('GO_ERP_SOURCE_MODES_'+code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def source_io():
    decoder=zlib.decompressobj();raw=decoder.decompress(base64.b64decode(SOURCE_COMPRESSED,validate=True),2*1024**2+1)
    need(len(raw)<=2*1024**2 and decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail
        and sha(raw)==SOURCE_SHA256,'REVIEWED_IO_CHANGED')
    module=types.ModuleType('reviewed_mode_repair_source');module.__file__='<reviewed-mode-repair-source>'
    sys.modules[module.__name__]=module;exec(compile(raw,module.__file__,'exec'),module.__dict__);return module

def record(io,path):
    value=io.read(path,0,0,optional=True,private=True)
    if value is None:return None
    result=io.parse(value[0]);need(io.canonical(result)==value[0],'PRIVATE_RECORD_CHANGED');return result

def names(private):
    prefix='shared-workspace-source-modes-'+CORE
    return private/(prefix+'.json'),private/(prefix+'-complete.json')

def checked_intent(value,rows):
    need(isinstance(value,dict) and set(value)=={'format','kind','operator','core','rows'}
        and value['format']==1 and value['kind']=='go-erp-reviewed-source-mode-intent'
        and value['operator']==OPERATOR and value['core']==CORE and isinstance(value['rows'],list)
        and len(value['rows'])==len(rows),'INTENT_CHANGED')
    for actual,pinned in zip(value['rows'],rows):
        need(isinstance(actual,dict) and set(actual)==set(pinned)|{'dev','ino','mtime_ns'}
            and all(actual.get(k)==v for k,v in pinned.items())
            and all(type(actual[k]) is int and actual[k]>=0 for k in ('dev','ino','mtime_ns')),'INTENT_CHANGED')
    return value

def completed(io,private,rows):
    intent_path,done_path=names(private);done=record(io,done_path)
    if done is None:return False
    intent=checked_intent(record(io,intent_path),rows)
    need(done=={'format':1,'kind':'go-erp-reviewed-source-modes-complete','intent_sha256':sha(io.canonical(intent)),
        'files':len(rows),'content_changed':False},'COMPLETION_CHANGED');return True

def check_preparation(io,private):
    flow=record(io,private/('shared-workspace-root-'+CORE+'.json'))
    need(flow is not None and flow.get('release_pins_sha256')==PINS_SHA256
        and flow.get('phase')=='observed' and flow.get('prepared') is None
        and flow.get('activation') is None,'SOURCE_TRANSACTION_ALREADY_STARTED')
    for name in AUDITS:
        folder=private/'audits'/name
        if not os.path.lexists(folder):continue
        io.directory(folder,0,0,private=True)
        for index,entry in enumerate(folder.iterdir()):
            need(index<256 and re.fullmatch(r'before-[a-f0-9]{64}\.bin',entry.name) is not None,
                'SOURCE_TRANSACTION_ALREADY_STARTED')
            io.read(entry,0,0,private=True)

def read_source(io,path,fd,row,*,allow_target):
    before=os.fstat(fd);parent=io.parent_fd(path)
    try:need(io.identity(before)==io.identity(os.stat(path.name,dir_fd=parent,follow_symlinks=False)),'FILE_REPLACED')
    finally:os.close(parent)
    need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,before.st_size)
        ==(APP_UID,APP_GID,1,row['size']) and stat.S_IMODE(before.st_mode) in
        ({row['old_mode'],row['target_mode']} if allow_target else {row['old_mode']}),'SOURCE_METADATA_CHANGED')
    os.lseek(fd,0,os.SEEK_SET);parts=[];remaining=row['size']+1
    while remaining:
        chunk=os.read(fd,min(65536,remaining))
        if not chunk:break
        parts.append(chunk);remaining-=len(chunk)
    raw=b''.join(parts)
    need(len(raw)==row['size'] and sha(raw)==row['sha256']
        and io.identity(before)==io.identity(os.fstat(fd)),'SOURCE_BYTES_CHANGED')
    return before

def repair(io,root,private,rows,verify,preparation):
    if completed(io,private,rows):return {'already_complete':True,'changed':0,'files':len(rows)}
    verify();preparation()
    intent_path,done_path=names(private);intent=record(io,intent_path)
    if intent is not None:checked_intent(intent,rows)
    with ExitStack() as stack:
        opened=[];facts=[]
        for index,row in enumerate(rows):
            path=root/row['path'];io.directory(path.parent,APP_UID,APP_GID)
            parent=io.parent_fd(path)
            try:fd=os.open(path.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=parent)
            finally:os.close(parent)
            stack.callback(os.close,fd);before=read_source(io,path,fd,row,allow_target=intent is not None)
            fact={**row,'dev':before.st_dev,'ino':before.st_ino,'mtime_ns':before.st_mtime_ns}
            if intent is not None:need(fact==intent['rows'][index],'BOUND_INODE_CHANGED')
            facts.append(fact);opened.append((path,fd,row,before))
        if intent is None:
            intent={'format':1,'kind':'go-erp-reviewed-source-mode-intent','operator':OPERATOR,'core':CORE,'rows':facts}
            io.private_record(intent_path,intent,verify)
        verify();preparation();changed=0
        for path,fd,row,before in opened:
            verify();need(io.identity(os.fstat(fd))==io.identity(before),'SOURCE_PEER_CHANGED')
            parent=io.parent_fd(path)
            try:need(io.identity(os.stat(path.name,dir_fd=parent,follow_symlinks=False))==io.identity(before),'FILE_REPLACED')
            finally:os.close(parent)
            if stat.S_IMODE(before.st_mode)!=row['target_mode']:
                os.fchmod(fd,row['target_mode']);os.fsync(fd);changed+=1
            after=read_source(io,path,fd,row,allow_target=True)
            need(stat.S_IMODE(after.st_mode)==row['target_mode'] and
                (after.st_dev,after.st_ino,after.st_mtime_ns)==(before.st_dev,before.st_ino,before.st_mtime_ns),'NORMALIZATION_FAILED')
        verify()
        for path,fd,row,_before in opened:
            need(stat.S_IMODE(read_source(io,path,fd,row,allow_target=True).st_mode)==row['target_mode'],'FINAL_MODE_CHANGED')
        io.private_record(done_path,{'format':1,'kind':'go-erp-reviewed-source-modes-complete',
            'intent_sha256':sha(io.canonical(intent)),'files':len(rows),'content_changed':False},verify)
    return {'already_complete':False,'changed':changed,'files':len(rows)}

def unit(action,name):
    run=subprocess.run(['/usr/bin/systemctl',action,name],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,timeout=30);need(len(run.stdout)<=256,'UNIT_OUTPUT_INVALID')
    return run.stdout.decode().strip()

def quiet():
    for name in ('go-erp-live-updates.service','go-erp-manual-updates.service','go-erp-live-updates.timer','go-erp-manual-updates.path'):
        need(unit('is-active',name) in ('inactive','failed','unknown'),'UPDATE_WORKER_ACTIVE')
    need(unit('is-enabled','go-erp-live-updates.timer') in ('disabled','masked'),'AUTOMATIC_TIMER_CHANGED')
    need(not os.path.lexists(PRIVATE/'active.json'),'MANUAL_JOB_ACTIVE')

def main():
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_REQUIRED')
    os.umask(0o077);io=source_io();held=[]
    with ExitStack() as stack:
        def lock(path):
            _raw,info=io.read(path,0,0,private=True)
            parent=io.parent_fd(path)
            try:fd=os.open(path.name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=parent)
            finally:os.close(parent)
            stack.callback(os.close,fd);need(io.identity(os.fstat(fd))==io.identity(info),'LOCK_CHANGED')
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise Stop('GO_ERP_SOURCE_MODES_UPDATE_BUSY')
            held.append((path,fd,info))
        lock(PRIVATE/'install.lock')
        stopped=subprocess.run(['/usr/bin/systemctl','stop','go-erp-manual-updates.path'],stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=30)
        need(stopped.returncode==0,'MANUAL_PATH_STOP_FAILED');quiet()
        lock(PRIVATE/'worker.lock');lock(WORK/'watcher.lock')
        def verify():
            quiet()
            for path,fd,info in held:
                need(io.identity(os.fstat(fd))==io.identity(info)
                    and io.identity(path.lstat())==io.identity(info),'LOCK_CHANGED')
        result=repair(io,ROOT,PRIVATE,ROWS,verify,lambda:check_preparation(io,PRIVATE))
    print('GO_ERP_SHARED_WORKSPACE_SOURCE_MODES_REPAIRED '+json.dumps(result,separators=(',',':')),flush=True)

if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error);print(code if re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,160}',code)
            else 'GO_ERP_SOURCE_MODES_PRIVATE_FAILURE',flush=True);raise SystemExit(1)
