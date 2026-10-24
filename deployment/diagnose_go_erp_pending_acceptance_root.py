#!/usr/bin/env python3
"""Read bounded acceptance identities without changing the release pipeline."""
from contextlib import ExitStack
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat

WORK=Path('/var/lib/go-erp-live-updates')
CONTROL=Path('/var/lib/go-erp-manual-updates')
FAILED='59b48c293c1e00ec7a5c9129e6ae5c0576b81f11'
HEX=lambda value,n:value if isinstance(value,str) and re.fullmatch('[a-f0-9]{%d}'%n,value) else None
VERSION=lambda value:value if isinstance(value,str) and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]{0,14}',value) else None
NUMBER=lambda value:value if type(value) is int and 0<=value<=9007199254740991 else None

class Stop(Exception):pass
def need(value,code):
    if not value:raise Stop(code)
def identity(value):
    return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,value.st_nlink,
        value.st_size,value.st_mtime_ns,value.st_ctime_ns)
def directory(path):
    need(path.is_absolute(),'PATH_INVALID')
    for item in (*reversed(path.parents),path):
        value=item.lstat()
        need(stat.S_ISDIR(value.st_mode) and value.st_uid==0 and value.st_gid==0
            and (not value.st_mode&0o022 or item!=path and value.st_mode&stat.S_ISVTX)
            and (item!=path or stat.S_IMODE(value.st_mode)==0o700),'DIRECTORY_UNSAFE')
def unique(pairs):
    value={}
    for key,item in pairs:need(key not in value,'DUPLICATE_JSON_FIELD');value[key]=item
    return value
def obj(value):return value if isinstance(value,dict) else {}
def source(value):
    value=obj(value)
    return {'format':NUMBER(value.get('format')),'files':NUMBER(value.get('files')),
        'framework':value.get('framework') if isinstance(value.get('framework'),str)
            and re.fullmatch(r'[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}',value['framework']) else None,
        'sha256':HEX(value.get('sha256'),64)}
def diagnose(work=WORK,control=CONTROL):
    reads=[]
    def read(path,limit=2*1024**2,optional=True):
        try:directory(path.parent)
        except FileNotFoundError:
            if optional:return None,None
            raise Stop('FILE_MISSING') from None
        try:fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
        except FileNotFoundError:
            if optional:return None,None
            raise Stop('FILE_MISSING') from None
        try:
            before=os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
                stat.S_IMODE(before.st_mode))==(0,0,1,0o600) and 0<before.st_size<=limit,'FILE_UNSAFE')
            raw=bytearray()
            while len(raw)<=limit:
                part=os.read(fd,min(65536,limit+1-len(raw)))
                if not part:break
                raw.extend(part)
            need(len(raw)==before.st_size and identity(before)==identity(os.fstat(fd))==identity(path.lstat()),'FILE_CHANGED')
            value=json.loads(raw,object_pairs_hook=unique,parse_constant=lambda value:(_ for _ in ()).throw(Stop('JSON_NONFINITE')))
            need(isinstance(value,dict),'JSON_OBJECT_REQUIRED');reads.append((path,identity(before)))
            return value,bytes(raw)
        finally:os.close(fd)
    with ExitStack() as locks:
        for path in (control/'private/worker.lock',work/'watcher.lock'):
            directory(path.parent);fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK);locks.callback(os.close,fd)
            value=os.fstat(fd)
            need(stat.S_ISREG(value.st_mode) and (value.st_uid,value.st_gid,value.st_nlink,
                stat.S_IMODE(value.st_mode))==(0,0,1,0o600),'LOCK_UNSAFE')
            reads.append((path,identity(value)))
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise Stop('RELEASE_PROCESS_BUSY') from None
        pending,raw=read(work/'pending-program-acceptance.json',optional=False)
        state,_=read(work/'state/current.json',16*1024**2,False);hold,_=read(work/'program-rollout-hold.json')
        digest=hashlib.sha256(raw).hexdigest();ci=obj(pending.get('ci'));evidence=obj(pending.get('employee_evidence'))
        installer=obj(pending.get('installer'));core=HEX(pending.get('core'),40);candidate=HEX(pending.get('candidate_revision'),40)
        fingerprint=source(pending.get('source'));inventory=HEX(pending.get('inventory_sha256'),64)
        schema=(set(pending)=={'format','kind','core','source','inventory_sha256','candidate_revision','version','installer','ci','employee_evidence'}
            and type(pending.get('format')) is int and pending['format']==1 and pending.get('kind')=='go-erp-program-acceptance'
            and core is not None and candidate is not None and VERSION(pending.get('version')) is not None
            and inventory is not None and fingerprint==pending.get('source') and fingerprint['format']==1
            and fingerprint['files'] is not None and fingerprint['files']>0 and all(fingerprint.get(k) is not None for k in ('framework','sha256'))
            and set(ci)=={'directory','run_id','run_attempt'} and NUMBER(ci.get('run_id')) not in (None,0)
            and NUMBER(ci.get('run_attempt')) not in (None,0) and isinstance(ci.get('directory'),str)
            and set(evidence)=={'path','sha256'} and isinstance(evidence.get('path'),str)
            and HEX(evidence.get('sha256'),64) is not None and set(installer)=={'path','sha256','bytes'}
            and HEX(installer.get('sha256'),64) is not None and NUMBER(installer.get('bytes')) not in (None,0)
            and installer.get('path')=='/api/desktop-dashboard/update/installer/'+str(installer.get('sha256')))
        folder=None
        if candidate and isinstance(ci.get('directory'),str):
            path=Path(ci['directory']);parent=path.parent
            if path.name=='ci' and parent.parent==work/'program-acceptance' and re.fullmatch(candidate+r'-[A-Za-z0-9_-]{6,64}',parent.name):folder=parent
        retained,retained_raw=read(folder/'acceptance.json') if folder is not None else (None,None)
        employee_scope=folder is not None and evidence.get('path')==str(folder/'employee.json')
        employee,employee_raw=read(folder/'employee.json') if employee_scope else (None,None)
        completed,completed_raw=read(work/('program-rollout-completed-'+core+'.json')) if core else (None,None)
        authorization=obj(obj(completed).get('authorization'))
        receipt_path=work/'program-rollout-authorizations'/('receipt-'+digest+'.json')
        receipt_scope=authorization.get('receipt_path')==str(receipt_path) and authorization.get('receipt_sha256')==digest
        receipt,receipt_raw=read(receipt_path) if receipt_scope else (None,None)
        def binds(release):
            release=obj(release)
            return (schema and release.get('source_revision')==candidate and release.get('version')==pending['version']
                and release.get('source')==pending['source'] and release.get('installer')==installer
                and release.get('sequence')==ci.get('run_id'))
        history=state.get('published_history');need(isinstance(history,list) and len(history)<=10000,'HISTORY_UNSAFE')
        desired=obj(state.get('desired'));published=obj(state.get('published'));h=obj(hold);completion=obj(completed)
        completion_binding=(schema and all(completion.get(k)==pending.get(k) for k in ('core','source','inventory_sha256'))
            and type(completion.get('format')) is int and completion['format']==1
            and completion.get('kind')=='go-erp-program-rollout-completed' and binds(completion.get('release'))
            and authorization.get('candidate')==candidate and authorization.get('version')==pending['version']
            and authorization.get('release')==completion.get('release') and authorization.get('employee_evidence')==evidence)
        result={'format':1,'kind':'go-erp-pending-acceptance-diagnostic','ok':True,
            'pending':{'sha256':digest,'schema_valid':bool(schema),'core':core,'candidate_revision':candidate,
                'version':VERSION(pending.get('version')),'source':fingerprint,'inventory_sha256':inventory,
                'ci_run_id':NUMBER(ci.get('run_id')),'ci_run_attempt':NUMBER(ci.get('run_attempt')),
                'installer_sha256':HEX(installer.get('sha256'),64),'installer_bytes':NUMBER(installer.get('bytes'))},
            'state':{'counter':NUMBER(state.get('counter')),'desired_candidate':HEX(desired.get('commit'),40),
                'desired_version':VERSION(desired.get('version')),'desired_status':desired.get('status')
                    if desired.get('status') in ('prepared','waiting-ci','published','retry-build','blocked') else None,
                'desired_failures':NUMBER(desired.get('failures')),'base_revision':HEX(state.get('base_revision'),40),
                'published_version':VERSION(obj(published.get('release')).get('version'))},
            'hold':{'core':HEX(h.get('core'),40),'source':source(h.get('source')),
                'inventory_sha256':HEX(h.get('inventory_sha256'),64)},
            'bindings':{'pending_is_failed46':candidate==FAILED,'pending_matches_desired':candidate is not None and candidate==desired.get('commit'),
                'pending_matches_published':bool(published.get('commit')==candidate and binds(published.get('release'))),'matching_history_count':sum(bool(binds(row)) for row in history),
                'ci_reference_scope_valid':folder is not None,'retained_acceptance_equal':retained_raw==raw,
                'employee_reference_scope_valid':employee_scope,'employee_digest_matches':employee_raw is not None and hashlib.sha256(employee_raw).hexdigest()==evidence.get('sha256'),
                'hold_present':hold is not None,'hold_authorization_is_null':hold is not None and 'authorization' in h and h['authorization'] is None,
                'hold_matches_pending':schema and all(h.get(k)==pending.get(k) for k in ('core','source','inventory_sha256')),
                'completion_present':completed is not None,'completion_binding_matches':bool(completion_binding),
                'completion_canonical_publication_verified':completion.get('canonical_publication_verified') is True,
                'immutable_receipt_scope_valid':receipt_scope,'immutable_receipt_equal':receipt_raw==raw},
            'files_changed':False,'database_accessed':False,'credentials_read':False,'network_accessed':False}
        for path,before in reads:need(identity(path.lstat())==before,'INPUT_CHANGED')
        return result

def main():
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_REQUIRED')
    output=json.dumps(diagnose(),sort_keys=True);need(len(output)<=8192,'OUTPUT_LIMIT');print(output)
if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error) if isinstance(error,Stop) and re.fullmatch('[A-Z0-9_]{1,100}',str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format':1,'kind':'go-erp-pending-acceptance-diagnostic','ok':False,'code':'GO_ERP_PENDING_DIAGNOSTIC_'+code,
            'files_changed':False,'database_accessed':False,'credentials_read':False,'network_accessed':False},sort_keys=True))
        raise SystemExit(1)
