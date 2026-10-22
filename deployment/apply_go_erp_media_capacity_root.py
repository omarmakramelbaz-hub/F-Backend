#!/usr/bin/env python3
"""One-file capacity deployment and compatible native seed upgrade; no business writes."""
import argparse
import copy
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import tempfile
import uuid

ROOT=Path('/home/fasakha/public_html')
WORK=Path('/var/lib/go-erp-live-updates')
CONFIG=Path('/etc/go-erp/live-updates.json')
SOURCE='app/Services/Dashboard/DesktopDashboardMedia.php'
BEFORE_BLOB='8dcb92e56ce55b88d86a2d007c56517d2cadb9f0'
OLD_SEED='75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
PACKAGE=Path(__file__).absolute().parent.parent

def need(value, reason):
    if not value: raise RuntimeError('GO_ERP_CAPACITY_'+reason)

def load(name, path):
    spec=importlib.util.spec_from_file_location(name,path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value

def blob(data): return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
def identity(info):
    return (info.st_dev,info.st_ino,info.st_mode,info.st_uid,info.st_gid,info.st_nlink,
            info.st_size,info.st_mtime_ns,info.st_ctime_ns)

def source_bytes(path, uid):
    for parent in path.parents:
        need(not parent.is_symlink() and parent.is_dir(),'SOURCE_ANCESTRY')
    before=path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and before.st_uid in (0,uid)
         and not before.st_mode&0o022 and 0<before.st_size<1024**2,'SOURCE_IDENTITY')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    try:
        opened=os.fstat(fd);data=os.read(fd,1024**2)
        need(identity(opened)==identity(os.fstat(fd))==identity(path.lstat())==identity(before)
             and len(data)==before.st_size,'SOURCE_CHANGED')
    finally: os.close(fd)
    return data,before

def replace_source(path, data, before):
    fd,name=tempfile.mkstemp(prefix='.go-erp-capacity-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as output:
            output.write(data);output.flush();os.fchown(output.fileno(),before.st_uid,before.st_gid)
            os.fchmod(output.fileno(),stat.S_IMODE(before.st_mode));os.fsync(output.fileno())
        os.replace(name,path)
        parent=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try: os.fsync(parent)
        finally: os.close(parent)
    finally:
        if os.path.exists(name):os.unlink(name)

def migrated_state(state, old_seed, new_seed):
    need(isinstance(state,dict) and state.get('format')==1 and state.get('base_revision')==old_seed
         and type(state.get('counter')) is int and state['counter']>=0,'STATE_IDENTITY')
    value=copy.deepcopy(state)
    floor=value.get('version_floor')
    need(floor is None or isinstance(floor,str) and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]*',floor),'VERSION_FLOOR')
    value.update(base_revision=new_seed,observed=None,desired=None,published=None)
    value['counter']=max(value['counter'],22)
    value['version_floor']='0.4.4-preview.'+str(max(22,int(floor.rsplit('.',1)[1]) if floor else 0))
    return value

def upgrade(root, work, config_path, stage, release, uid, worker, seed_policy, refresh, apply):
    target=root/SOURCE
    original,info=source_bytes(target,uid)
    wanted=(PACKAGE/SOURCE).read_bytes()
    need(b'public const MAX_TOTAL=2*1024*1024*1024;' in wanted,'PACKAGE_SOURCE')
    config=worker.read_config(config_path)
    state_path=work/'state/current.json'
    state=worker.parse_json(worker.regular_bytes(state_path,worker.MAX_JSON,private=True))
    checkout=work/'checkout'
    worker.owned_directory(checkout)
    actual=seed_policy.git(checkout,'rev-parse','HEAD').decode().strip()
    need(actual==config['base_revision'] and not seed_policy.git(checkout,'status','--porcelain'),'OLD_CHECKOUT_CHANGED')
    if original==wanted and actual==release and state.get('base_revision')==release:
        return {'result':'GO_ERP_MEDIA_CAPACITY_ALREADY_READY','offline_ready':False}
    need(config['base_revision']==OLD_SEED and blob(original)==BEFORE_BLOB,'UNREVIEWED_BEFORE_STATE')
    next_state=migrated_state(state,OLD_SEED,release)
    next_config={**config,'base_revision':release}
    summary={'result':'GO_ERP_MEDIA_CAPACITY_PLAN','limit_bytes':2*1024**3,'version':'0.4.4-preview.22',
             'application_files':1,'watcher_seed_upgraded':True,'offline_ready':False}
    if not apply:return summary

    staged=stage/'seed';staged.mkdir(mode=0o700)
    seed_policy.git(staged,'init','--quiet','--template=')
    seed_policy.git(staged,'remote','add','origin',seed_policy.REMOTE)
    seed_policy.git(staged,'fetch','--quiet','--depth=16','--no-tags','--no-recurse-submodules','origin',release)
    seed_policy.git(staged,'checkout','--quiet','--detach','--no-recurse-submodules',release)
    seed_policy.validate_seed(staged,release)
    need(not seed_policy.git(staged,'status','--porcelain'),'NEW_CHECKOUT_DIRTY')
    need((staged/SOURCE).read_bytes()==wanted and
         (staged/'deployment/apply_go_erp_media_capacity_root.py').read_bytes()==Path(__file__).read_bytes(),
         'RUNNING_PACKAGE_DIFFERS_FROM_RELEASE')
    check=subprocess.run(['/opt/cpanel/ea-php82/root/usr/bin/php','-n','-l',str(staged/SOURCE)],
                         stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30,check=False)
    need(check.returncode==0,'PHP_SYNTAX')
    expected=refresh.scan(staged)
    wanted_fingerprint=refresh.digest(expected)
    expected[SOURCE]={'blob':blob(original),'sha256':hashlib.sha256(original.replace(b'\r\n',b'\n')).hexdigest()}
    need(refresh.digest(refresh.scan(root))==refresh.digest(expected),'LIVE_SOURCE_DIFFERS_FROM_TESTED_COHORT')
    need(source_bytes(target,uid)[0]==original,'SOURCE_CHANGED')

    backup_parent=work/'capacity-upgrades';worker.owned_directory(backup_parent,create=True)
    audit=backup_parent/(datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S')+'-'+uuid.uuid4().hex)
    audit.mkdir(mode=0o700)
    config_before=worker.regular_bytes(config_path,16384,private=True)
    state_before=worker.regular_bytes(state_path,worker.MAX_JSON,private=True)
    for name,data in [('source.before.php',original),('config.before.json',config_before),('state.before.json',state_before)]:
        fd=os.open(audit/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as output:output.write(data);output.flush();os.fsync(output.fileno())
    worker.atomic_json(audit/'intent.json',{'release':release,'old_seed':OLD_SEED,'source_blob':blob(original),
        'source_uid':info.st_uid,'source_gid':info.st_gid,'source_mode':stat.S_IMODE(info.st_mode)})
    moved=False
    try:
        need(worker.regular_bytes(config_path,16384,private=True)==config_before and
             worker.regular_bytes(state_path,worker.MAX_JSON,private=True)==state_before,'STATE_CHANGED')
        os.rename(checkout,audit/'checkout.before');moved=True
        os.rename(staged,checkout)
        replace_source(target,wanted,info)
        worker.atomic_json(config_path,next_config)
        worker.atomic_json(state_path,next_state)
        need(refresh.digest(refresh.scan(root))==wanted_fingerprint,'SOURCE_VERIFY')
        need(source_bytes(target,uid)[0]==wanted and worker.read_config(config_path)==next_config
             and worker.parse_json(worker.regular_bytes(state_path,worker.MAX_JSON,private=True))==next_state,'VERIFY')
    except BaseException:
        restored=True
        try:
            if source_bytes(target,uid)[0]==wanted:replace_source(target,original,info)
            elif source_bytes(target,uid)[0]!=original:restored=False
            if worker.read_config(config_path) in (config,next_config):worker.atomic_json(config_path,config)
            else:restored=False
            current=worker.parse_json(worker.regular_bytes(state_path,worker.MAX_JSON,private=True))
            if current in (state,next_state):worker.atomic_json(state_path,state)
            else:restored=False
            if moved:
                if checkout.exists():os.rename(checkout,audit/'checkout.not-activated')
                os.rename(audit/'checkout.before',checkout)
        except BaseException:restored=False
        worker.atomic_json(audit/'failed.json',{'restored':restored,'business_data_changed':False})
        raise RuntimeError('GO_ERP_CAPACITY_FAILED_BACKUP: '+str(audit)) from None
    summary.update(result='GO_ERP_MEDIA_CAPACITY_INSTALLED',backup=str(audit),source=wanted_fingerprint,
                   watcher_seed=release,business_data_changed=False)
    worker.atomic_json(audit/'committed.json',summary)
    return summary

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release',required=True);parser.add_argument('--apply',action='store_true')
    options=parser.parse_args()
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    need(bool(re.fullmatch('[a-f0-9]{40}',options.release)),'RELEASE')
    os.umask(0o077)
    worker=load('capacity_worker',PACKAGE/'deployment/go_erp_live_updates.py')
    seed=load('capacity_seed',PACKAGE/'deployment/verify_go_erp_media_capacity_seed.py')
    refresh=load('capacity_source',PACKAGE/'deployment/refresh_desktop_dashboard_server_manifest.py')
    worker.owned_directory(WORK);worker.owned_directory(WORK/'staging')
    lock=os.open(WORK/'watcher.lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    try:
        item=os.fstat(lock)
        need(stat.S_ISREG(item.st_mode) and item.st_uid==0 and item.st_nlink==1 and not item.st_mode&0o077,'LOCK')
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise RuntimeError('GO_ERP_CAPACITY_UPDATE_WORKER_BUSY_RETRY_AFTER_COMPLETION') from None
        print('GO_ERP_MEDIA_CAPACITY_CHECKING_REVIEWED_SEED',flush=True)
        temporary=Path(tempfile.mkdtemp(prefix='capacity-upgrade-',dir=WORK/'staging'))
        try:
            result=upgrade(ROOT,WORK,CONFIG,temporary,options.release,pwd.getpwnam('fasakha').pw_uid,
                           worker,seed,refresh,options.apply)
            print(json.dumps(result,sort_keys=True),flush=True)
        finally:shutil.rmtree(temporary)
    finally:os.close(lock)

if __name__=='__main__':
    try:main()
    except Exception as error:
        print(str(error) if isinstance(error,RuntimeError) and str(error).startswith('GO_ERP_')
              else 'GO_ERP_CAPACITY_STOPPED_WITHOUT_PRIVATE_OUTPUT',flush=True)
        raise SystemExit(1)
