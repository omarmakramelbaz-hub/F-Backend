#!/usr/bin/env python3
"""Repair exactly one failed native candidate without changing its captured live source."""
import argparse
import copy
from contextlib import ExitStack, contextmanager
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys
import tempfile
import time
import types
import uuid

ROOT=Path('/home/fasakha/public_html')
WORK=Path('/var/lib/go-erp-live-updates')
CONTROL=Path('/var/lib/go-erp-manual-updates')
NATIVE_RELEASES=Path('/opt/go-erp-live-updates/releases')
MANUAL_RELEASES=Path('/opt/go-erp-manual-updates/releases')
SYSTEMD=Path('/etc/systemd/system')
CONFIG=Path('/etc/go-erp/live-updates.json')
BOOTSTRAP='18e13894c1afcc9f3daa61df2446b02cf806cb7a'
PRIOR='2d9e23a5157782882382c797a44ad15c317ae845'
BOOTSTRAP_INSTALL_SHA='72f082a6fd3a00cfa1aa4cf3d7e1cefbbf76dd1036507002d046c0534a21053e'
ENGINE_SHA='0a6da17799e88b45061442f81c730e32cdb1822ae68b2bd2f659b14d9f0610d9'
OLD_TICK_SHA='bf012d51f740a176f769b738b4af155e9055eefae238465e553fd4a369547208'
BASE_BLOB='ede9af30ea42ba53a5376d5e1cf634d9b3032f2a'
UTILITY_BLOB='60df7ec876f9634e0a7dcb4c5a420d0d5ecd9ae9'
OLD_SEED='9fbfa4a7bf5c5bfafb471265edc7429ec4b4f636'
OLD_CORE='c4229ff357c2feda3352c62b0171e2a0b1cb0d78'
OLD_RUNTIME='policy-228fbc5bf869c5ca4ffadf47a34968639035dd8b34e965d841778f918608ac3c'
OLD_POLICY='historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v1'
OLD_SUFFIX=b'-native-close-reopen-program-contract-warm-start-accounts-v1'
CORE='a237b4c048887c6af1b0fb53fe02c414c1d17cd6'
SEED='1556e002f18d00b9e8f9ba81590843e0d8697318'
POLICY='historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v2'
SUFFIX=b'-native-close-reopen-program-contract-warm-start-accounts-v2'
PREVIOUS='12d74b4f35737a2b1e271657b159137538c3edaf'
MANUAL_PINS={'go_erp_manual_tick_root.py': '361df24ea239a54833c575a27feaa6b403b745ea76f2349d6bc707e35d919179',
 'go_erp_manual_updates_root.py': '36b408d398644f34c4ca931897f1bacaf9002705636568213bcbb9654efa4b55',
 'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
OLD_MANUAL_PINS={'go_erp_manual_tick_root.py': 'bf012d51f740a176f769b738b4af155e9055eefae238465e553fd4a369547208',
 'go_erp_manual_updates_root.py': 'd4d2cca42674472001f70a68350379977956538a05016a3d67e077371669a784',
 'go_erp_published_download_root.py': '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
PACKAGE_PROGRAM_PATHS={'go_erp_manual_updates_root.py':'deployment/go_erp_warm_start_repair_manual_root.py',
    'go_erp_manual_tick_root.py':'deployment/go_erp_warm_start_repair_tick_root.py',
    'go_erp_published_download_root.py':'deployment/go_erp_published_download_root.py'}
SERVICE='go-erp-manual-updates.service'
WATCH='go-erp-manual-updates.path'
CODE=re.compile(r'GO_ERP_[A-Z0-9_]{1,140}\Z')
REV=re.compile(r'[a-f0-9]{40}\Z')
SAFE=9007199254740991
ARCHIVE_RELEASE='5628a33525e2af63b221f044eb0e650cb6a2b790'
INPUT_CANDIDATE='afb333bbddd9dbc908e3c3674d99c766ed5c4053'
INPUT_VERSION='0.4.4-preview.52'
CAPTURE_SEED='138bc2773bf8de099b029a18550e49f0692e9806'
FAILED_CANDIDATE='5563faf5aa1e2e62157c4b3b9638592a02854c59'
FAILED_VERSION='0.4.4-preview.53'
INPUT_COUNTER=53
INPUT_INVENTORY='525a481eb2d13dc47879b48f4a6110cbd702f2c0d5fdad5e49d663dcafe75d21'
PINS_PENDING=False
SHORTCUT=b"            if published and published.get('inventorySha256') == current:"
BOUND_SHORTCUT=b"""            if (published and published.get('inventorySha256') == current
                    and isinstance(desired, dict) and desired.get('commit') == published.get('commit')
                    and desired.get('candidatePolicy') == policy):"""


class Stop(Exception):pass

def need(value,code):
    if not value:raise Stop(code)

def sha(raw):return hashlib.sha256(raw).hexdigest()

def blob(raw):return hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()

def module(raw,name,expected,git_blob=False):
    need((blob(raw) if git_blob else sha(raw))==expected,'MODULE_PIN_INVALID')
    value=types.ModuleType(name);value.__file__='<pinned-'+name+'>';sys.modules[name]=value
    exec(compile(raw,value.__file__,'exec'),value.__dict__)
    return value


def derive_native_payloads(payloads,pins,new_seed):
    need(REV.fullmatch(new_seed) and new_seed!=OLD_SEED and set(payloads)==set(pins),'NATIVE_RELEASE_INVALID')
    result={}
    for name,raw in payloads.items():
        expected=pins[name]
        need((blob(raw),len(raw),sha(raw))==tuple(expected),'OLD_NATIVE_PAYLOAD_CHANGED')
        if name in ('go_erp_live_source.py','go_erp_live_updates.py'):
            for before,after in ((OLD_SEED.encode(),new_seed.encode()),(OLD_SUFFIX,SUFFIX)):
                need(raw.count(before)==1,'NATIVE_PATCH_CONTEXT_INVALID')
                raw=raw.replace(before,after)
        if name.endswith('.py'):compile(raw,name,'exec')
        result[name]=raw
    return result


def rebind_observed(old_source,new_source,observed):
    if observed is None:return None
    need(isinstance(observed,dict) and isinstance(observed.get('inventory'),dict)
        and observed==old_source._metadata(OLD_SEED,observed['inventory']),'OBSERVED_METADATA_UNREVIEWED')
    result=new_source._metadata(SEED,copy.deepcopy(observed['inventory']))
    need(result['inventory']==observed['inventory'] and result['removedFiles']==observed['removedFiles']
        and result['packagedFiles']==observed['packagedFiles']
        and result['inventorySha256']==observed['inventorySha256'],'OBSERVED_REMOVAL_AUTHORITY_CHANGED')
    return result


def migrate_state(value,old_source,new_source):
    need(isinstance(value,dict) and value.get('base_revision')==OLD_SEED and value.get('format')==1
        and type(value['format']) is int and type(value.get('counter')) is int and INPUT_COUNTER<=value['counter']<=SAFE
        and isinstance(value.get('published_history'),list),'STATE_IDENTITY_INVALID')
    result=copy.deepcopy(value);result['base_revision']=SEED
    result['observed']=rebind_observed(old_source,new_source,value.get('observed'))
    need({k:v for k,v in result.items() if k not in ('base_revision','observed')}
        =={k:v for k,v in value.items() if k not in ('base_revision','observed')},'STATE_HISTORY_CHANGED')
    return result


def allocation_unconsumed(job,state):
    desired=state.get('desired') or {}
    return (type(job.get('baseline_counter')) is int and state.get('counter')==job['baseline_counter']
        and state.get('last_remote_sha')==job.get('baseline_remote')
        and desired.get('commit')==job.get('baseline_candidate'))


def checked_failed_state(state,publication):
    """Recover only the known unpublished .53 candidate and its genuine .52 publication."""
    desired=state.get('desired') if isinstance(state,dict) else None
    published=state.get('published') if isinstance(state,dict) else None
    need(isinstance(state,dict) and type(state.get('format')) is int and state['format']==1
        and state.get('base_revision')==OLD_SEED and type(state.get('counter')) is int
        and state['counter']==INPUT_COUNTER and state.get('last_remote_sha')==FAILED_CANDIDATE
        and isinstance(desired,dict) and desired.get('commit')==FAILED_CANDIDATE
        and desired.get('version')==FAILED_VERSION and desired.get('candidatePolicy')==OLD_POLICY
        and desired.get('inventorySha256')==INPUT_INVENTORY and desired.get('pushed') is True
        and desired.get('status') in ('retry-build','blocked')
        and type(desired.get('failures')) is int and 1<=desired['failures']<=100
        and type(desired.get('retry_at')) in (int,float) and math.isfinite(desired['retry_at'])
        and 0<=desired['retry_at']<=SAFE and isinstance(published,dict)
        and published.get('commit')==INPUT_CANDIDATE and isinstance(published.get('release'),dict)
        and published['release'].get('source_revision')==INPUT_CANDIDATE
        and published['release'].get('version')==state.get('version_floor')==INPUT_VERSION
        and published['release'] in state.get('published_history',[]),
        'EXACT_FAILED_WARM_START_CANDIDATE_REQUIRED')
    need(publication is None or publication==published['release'],'PUBLIC_FEED_NOT_CURRENT')
    return desired


class Release:
    def __init__(self,repository,revision):self.repository,self.revision=Path(repository),revision
    def git(self,*args):
        result=subprocess.run(['/usr/bin/git','--git-dir='+str(self.repository),'-c','credential.helper=',
            '-c','core.hooksPath=/dev/null',*args],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,timeout=180,env={'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1',
                'GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1',
                'GIT_TERMINAL_PROMPT':'0','GIT_OPTIONAL_LOCKS':'0'})
        need(result.returncode==0 and len(result.stdout)<=2*1024**2,'RELEASE_READ_FAILED');return result.stdout
    def file(self,path,revision=None):return self.git('show',(revision or self.revision)+':'+path)



def quiet(engine):
    for unit in ('go-erp-live-updates.service',SERVICE):
        need(engine.run(['/usr/bin/systemctl','show',unit,'-p','ActiveState','--value']) in ('inactive','failed'),
            'EXISTING_JOB_BUSY')
    need(engine.run(['/usr/bin/systemctl','show','go-erp-live-updates.timer','-p','ActiveState','--value'])=='inactive'
        and engine.run(['/usr/bin/systemctl','show','go-erp-live-updates.timer','-p','UnitFileState','--value'])
            in ('disabled','masked'),'AUTOMATIC_TIMER_NOT_DISABLED')
    need(engine.read_file(CONTROL/'private/active.json',uid=0,modes=(0o600,),optional=True) is None,
        'UNFINISHED_MANUAL_JOB_BUSY')


class LockedUtility:
    def __init__(self,original,verify):self.original,self.verify=original,verify
    def __getattr__(self,name):return getattr(self.original,name)
    @contextmanager
    def watcher_lock(self,work):
        need(work==WORK,'LOCK_SCOPE_INVALID');self.verify();yield self.verify;self.verify()


def checked_native_core(release):
    need(release.git('diff-tree','--no-commit-id','--name-only','--no-renames','--no-ext-diff',
        INPUT_CANDIDATE,CORE,'--','app','bootstrap','config','database','desktop','public',
        'resources','routes','artisan','composer.json','composer.lock')==b'',
        'NATIVE_CORE_APPLICATION_OR_DEPENDENCY_CHANGED')


class Context:
    def __init__(self,release,engine):
        self.release,self.e=release,engine;checked_native_core(release);self.owner=pwd.getpwnam('fasakha')
        self.base=module(release.file('deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py',PRIOR),
            'close_native_transaction',BASE_BLOB,True)
        self.utility=module(release.file('deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py',PRIOR),
            'close_native_utility',UTILITY_BLOB,True)
        self.old_tick=module(release.file('deployment/go_erp_warm_start_tick_root.py',PREVIOUS),
            'warm_start_previous_native_tick',OLD_TICK_SHA)
        old_cohort=self.old_tick.NEW_COHORT
        need(old_cohort['seed']==OLD_SEED and old_cohort['policy']==OLD_POLICY
            and old_cohort['runtime'].name==OLD_RUNTIME,'PREVIOUS_NATIVE_COHORT_CHANGED')
        self.old_tick.PINS=old_cohort['pins'];self.old_tick.RUNTIME=old_cohort['runtime']
        self.old_worker=self.old_tick.load_runtime(old_cohort)
        self.old_payloads={name:engine.read_file(self.old_tick.RUNTIME/name,uid=0,modes=(0o644,))[0]
            for name in self.old_tick.PINS}
        self.payloads=derive_native_payloads(self.old_payloads,self.old_tick.PINS,SEED)
        self.pins={name:blob(raw) for name,raw in self.payloads.items()}
        self.runtime=self.utility.install_runtime(NATIVE_RELEASES,self.payloads,self.pins)
        folder=self.utility.Directory(self.runtime,0o755)
        try:
            self.source=self.utility.module_at(folder,'go_erp_live_source.py',self.pins['go_erp_live_source.py'])
            self.worker=self.utility.module_at(folder,'go_erp_live_updates.py',self.pins['go_erp_live_updates.py'])
            self.publisher=self.utility.module_at(folder,'publish_go_erp_update.py',self.pins['publish_go_erp_update.py'])
        finally:folder.close()
        old=self.utility.Directory(self.old_tick.RUNTIME,0o755)
        try:self.old_source=self.utility.module_at(old,'go_erp_live_source.py',self.old_tick.PINS['go_erp_live_source.py'][0])
        finally:old.close()
        self.job_path=CONTROL/'private'/('warm-start-repair-'+CORE+'.json')
        self.prior_job_path=CONTROL/'private'/('warm-start-'+OLD_CORE+'.json')
        self.job=None;self.manual_runtime=None
        b=self.base;b.OLD_SEED=OLD_SEED;b.OLD_RUNTIME=OLD_RUNTIME;b.OLD_PINS={k:v[0] for k,v in self.old_tick.PINS.items()}
        b.OLD_ROSTER=b.ROSTER=tuple(sorted(self.pins));b.NEW_SEED=SEED;b.CORE=CORE
        b.PREFIX='warm-start-repair-seed-upgrade-';b.EXPECTED_SOURCE={'framework':'8.83.29'}

    def state(self):
        value=self.base.parse(self.e.read_file(WORK/'state/current.json',16*1024**2,uid=0,modes=(0o600,))[0])
        need(value.get('base_revision') in (OLD_SEED,SEED),'STATE_BASE_UNREVIEWED')
        return self.base.checked_state(value,value['base_revision'])
    def publication(self):
        raw=self.e.read_file(ROOT/'storage/app/desktop-updates/current.json',512*1024,uid=0,modes=(0o600,0o644),optional=True)
        return self.publisher.validate_release(self.base.parse(raw[0])) if raw else None
    def save(self):self.e.atomic_write(self.job_path,self.e.canonical(self.job),0,0,0o600)
    def public_status(self,state,code=None,claim=False):
        """Expose only this explicit rollout's public progress, never its proof."""
        states=('queued','running','waiting-ci','publishing','ready','failed')
        nonce=self.job.get('public_nonce');version=self.job.get('version')
        need(state in states and isinstance(nonce,str) and re.fullmatch('[a-f0-9]{32}',nonce)
            and (version is None or isinstance(version,str) and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]{0,14}',version))
            and (code is None or isinstance(code,str) and re.fullmatch('GO_ERP_[A-Z0-9_]{1,100}',code)),
            'PUBLIC_STATUS_INVALID')
        shared=CONTROL/'shared';self.e.directory(shared,0,self.owner.pw_gid,0o750,False)
        path=shared/'status.json';before=self.e.read_file(path,8192,uid=0,modes=(0o640,))
        previous=self.base.parse(before[0])
        need(before[1].st_gid==self.owner.pw_gid and set(previous)=={'format','kind','state','nonce','updated_at','version','code'}
            and type(previous['format']) is int and previous['format']==1
            and previous['kind']=='go-erp-manual-release-status'
            and previous['state'] in ('idle',*states)
            and (previous['nonce'] is None or isinstance(previous['nonce'],str) and re.fullmatch('[a-f0-9]{32}',previous['nonce']))
            and type(previous['updated_at']) is int and 1<=previous['updated_at']<=SAFE
            and (previous['version'] is None or isinstance(previous['version'],str)
                and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]{0,14}',previous['version']))
            and (previous['code'] is None or isinstance(previous['code'],str)
                and re.fullmatch('GO_ERP_[A-Z0-9_]{1,100}',previous['code'])),
            'PUBLIC_STATUS_BASELINE_INVALID')
        if not claim and previous['nonce']!=nonce:return False
        self.e.atomic_write(path,self.e.canonical({'format':1,'kind':'go-erp-manual-release-status',
            'state':state,'nonce':nonce,'updated_at':int(time.time()),'version':version,'code':code}),
            0,self.owner.pw_gid,0o640)
        return True
    def snapshot(self,state,seed_path,source):
        dependencies=self.worker.dependency_snapshot(ROOT,self.owner.pw_uid)
        need(state.get('dependency_guard') in (None,dependencies),'DEPENDENCIES_CHANGED')
        value=source.scan_snapshot(ROOT,seed_path,self.owner.pw_uid,state.get('observed'))
        need(value['metadata']['seedRevision']==state['base_revision'],'SNAPSHOT_SEED_INVALID')
        return value
    def live(self,state):
        need(state.get('base_revision')==SEED,'LIVE_STATE_BASE_INVALID')
        config=self.base.parse(self.e.read_file(CONFIG,16384,uid=0,modes=(0o600,))[0])
        self.base.checked_config(config,SEED)
        native_unit=SYSTEMD/'go-erp-live-updates.service'
        wanted=self.base.unit_bytes(self.utility,self.runtime,ROOT,WORK,CONFIG)
        need(self.e.read_file(native_unit,65536,uid=0,modes=(0o644,))[0]==wanted,'NATIVE_UNIT_CHANGED')
        self.utility.effective_unit(native_unit,CONFIG,(self.runtime,))
        folder=self.utility.Directory(self.runtime,0o755)
        try:self.utility.validate_runtime(folder,self.pins)
        finally:folder.close()
        value=self.snapshot(state,WORK/'checkout',self.source)
        need(value['metadata']['inventorySha256']==self.job['inventory']
            and self.base.normalized_source(value['_files'])==self.job['source'],'LIVE_SOURCE_CHANGED')
        folder=self.utility.Directory(self.runtime,0o755)
        try:self.utility.validate_runtime(folder,self.pins)
        finally:folder.close()
        return value
    def prior_hold(self,optional=False):
        value=self.e.read_file(WORK/'program-rollout-hold.json',1024*1024,uid=0,modes=(0o600,),optional=optional)
        return (value[0],self.base.parse(value[0])) if value is not None else (None,None)

    def verify_predecessor(self):
        raw=self.e.read_file(self.prior_job_path,16*1024**2,uid=0,modes=(0o600,))[0]
        prior=self.base.parse(raw)
        need(type(prior.get('format')) is int and prior['format']==1
            and prior.get('kind')=='go-erp-warm-start-rollout' and prior.get('core')==OLD_CORE
            and prior.get('seed')==OLD_SEED and prior.get('runtime')==OLD_RUNTIME
            and prior.get('phase')=='running' and prior.get('prepare_started') is True
            and prior.get('candidate')==FAILED_CANDIDATE and prior.get('version')==FAILED_VERSION
            and prior.get('inventory')==INPUT_INVENTORY and prior.get('remote_lease')==INPUT_CANDIDATE
            and prior.get('baseline_candidate')==prior.get('baseline_remote')==INPUT_CANDIDATE
            and prior.get('baseline_counter')==52
            and isinstance(prior.get('source'),dict)
            and isinstance(prior.get('audit'),str) and Path(prior['audit']).parent==CONTROL/'private/audits'
            and re.fullmatch('warm-start-[a-f0-9]{32}',Path(prior['audit']).name)
            and isinstance(prior.get('captured_metadata_sha256'),str)
            and re.fullmatch('[a-f0-9]{64}',prior['captured_metadata_sha256'])
            and isinstance(prior.get('previous_journal_sha256'),str)
            and re.fullmatch('[a-f0-9]{64}',prior['previous_journal_sha256']),
            'PREVIOUS_FAILED_ROLLOUT_UNREVIEWED')
        self.e.directory(Path(prior['audit']),0,0,0o700,False)
        capture=self.e.read_file(Path(prior['audit'])/'captured-source.json',16*1024**2,uid=0,modes=(0o600,))[0]
        need(sha(capture)==prior['captured_metadata_sha256'],'PREVIOUS_CAPTURE_BYTES_CHANGED')
        value=self.base.parse(capture)
        need(isinstance(value.get('inventory'),dict) and value==self.old_source._metadata(CAPTURE_SEED,value['inventory'])
            and value.get('inventorySha256')==INPUT_INVENTORY,'PREVIOUS_CAPTURE_BINDING_CHANGED')
        predecessor=self.e.read_file(Path(prior['audit'])/'previous-rollout.json',16*1024**2,uid=0,modes=(0o600,))[0]
        before=self.base.parse(predecessor)
        need(sha(predecessor)==prior['previous_journal_sha256'] and before.get('kind')=='go-erp-startup-session-rollout'
            and before.get('core')=='63a94e51bb6a120458623d74b46323f94438ae41'
            and before.get('seed')==CAPTURE_SEED and before.get('phase')=='ready','PREVIOUS_STARTUP_JOURNAL_CHANGED')
        return raw

    def quiet_hold(self):
        """Keep a genuine historical .52 receipt; never transfer its authorization."""
        need(self.e.read_file(WORK/('program-rollout-completed-'+OLD_CORE+'.json'),2*1024**2,
            uid=0,modes=(0o600,),optional=True) is None,'PREVIOUS_ACCEPTANCE_REQUIRES_REVIEW')
        pending=self.e.read_file(WORK/'pending-program-acceptance.json',2*1024**2,uid=0,modes=(0o600,),optional=True)
        if pending is None:return None
        state=self.state();published=state.get('published') or {};release=published.get('release') or {}
        expected=self.worker.program_gate_expected('63a94e51bb6a120458623d74b46323f94438ae41',
            release.get('source'),published.get('inventorySha256'))
        receipt=self.base.parse(pending[0])
        need(set(receipt)=={'format','kind','core','source','inventory_sha256','candidate_revision','version','installer','ci','employee_evidence'}
            and type(receipt.get('format')) is int and receipt['format']==1 and receipt.get('kind')=='go-erp-program-acceptance'
            and all(receipt.get(k)==v for k,v in expected.items())
            and receipt.get('candidate_revision')==published.get('commit')==INPUT_CANDIDATE
            and receipt.get('version')==release.get('version')==INPUT_VERSION and receipt.get('installer')==release.get('installer')
            and isinstance(receipt.get('ci'),dict) and set(receipt['ci'])=={'directory','run_id','run_attempt'}
            and receipt['ci'].get('run_id')==published.get('run_id') and receipt['ci'].get('run_attempt')==published.get('run_attempt'),
            'PREVIOUS_ACCEPTANCE_REQUIRES_REVIEW')
        completed=self.worker.program_gate_json(WORK/('program-rollout-completed-'+expected['core']+'.json'))
        auth=completed.get('authorization');need(isinstance(auth,dict)
            and set(auth)=={'candidate','version','installer','release','receipt_path','receipt_sha256','employee_evidence'}
            and auth.get('candidate')==INPUT_CANDIDATE and auth.get('version')==INPUT_VERSION
            and auth.get('release')==release and auth.get('installer')==release.get('installer')
            and isinstance(auth.get('receipt_sha256'),str) and re.fullmatch('[a-f0-9]{64}',auth['receipt_sha256'])
            and isinstance(auth.get('receipt_path'),str)
            and Path(auth['receipt_path']).parent==WORK/'program-rollout-authorizations'
            and Path(auth['receipt_path']).name=='receipt-'+auth['receipt_sha256']+'.json'
            and receipt.get('employee_evidence')==auth.get('employee_evidence'),
            'PREVIOUS_ACCEPTANCE_REQUIRES_REVIEW')
        retained=self.e.read_file(Path(auth['receipt_path']),2*1024**2,uid=0,modes=(0o600,))[0]
        need(retained==pending[0] and sha(retained)==auth['receipt_sha256'],'PREVIOUS_ACCEPTANCE_BYTES_CHANGED')
        historical=copy.deepcopy(state)
        historical['desired']={'commit':INPUT_CANDIDATE,'inventorySha256':published['inventorySha256'],
            'version':INPUT_VERSION,'status':'published','pushed':True}
        need(self.worker.program_gate_completed(WORK,expected,historical,lambda:release),
            'PREVIOUS_ACCEPTANCE_EVIDENCE_CHANGED')
        need(self.e.read_file(WORK/'pending-program-acceptance.json',2*1024**2,uid=0,modes=(0o600,))[0]==pending[0],
            'PREVIOUS_ACCEPTANCE_BYTES_CHANGED')
        return pending[0]

    def job_record(self):
        previous_raw=self.verify_predecessor();previous=self.base.parse(previous_raw)
        existing=self.e.read_file(self.job_path,16*1024**2,uid=0,modes=(0o600,),optional=True)
        if existing:
            value=self.base.parse(existing[0]);need(type(value.get('format')) is int and value['format']==1
                and value.get('kind')=='go-erp-warm-start-repair-rollout'
                and value.get('core')==CORE and value.get('seed')==SEED and value.get('runtime')==self.runtime.name
                and value.get('phase') in ('preparing','prepared','running','ready')
                and type(value.get('prepare_started')) is bool and value.get('source')==previous['source']
                and value.get('inventory')==INPUT_INVENTORY and value.get('captured_metadata_sha256')==previous['captured_metadata_sha256']
                and value.get('published_inventory_at_entry')==previous.get('published_inventory_at_entry')
                and value.get('previous_journal_sha256')==sha(previous_raw)
                and value.get('previous_audit')==previous['audit'] and value.get('remote_lease')==FAILED_CANDIDATE
                and isinstance(value.get('audit'),str) and Path(value['audit']).parent==CONTROL/'private/audits'
                and re.fullmatch('warm-start-repair-[a-f0-9]{32}',Path(value['audit']).name),
                'REPAIR_JOURNAL_UNREVIEWED')
            self.job=value;self.e.directory(Path(value['audit']),0,0,0o700,False)
        else:
            state=self.state();checked_failed_state(state,self.publication());pending=self.quiet_hold()
            self.verify_published_archive(state)
            baseline=self.base.parse(self.e.read_file(Path(previous['audit'])/'previous-state.json',16*1024**2,uid=0,modes=(0o600,))[0])
            for key in ('published','published_history','version_floor','sequence_floor'):
                need(state.get(key)==baseline.get(key),'PUBLISHED_BASELINE_CHANGED')
            need(previous.get('published_inventory_at_entry')==state['published']['inventorySha256'],
                'PUBLISHED_INVENTORY_CHANGED')
            before=self.snapshot(state,WORK/'checkout',self.old_source)
            need(before['metadata']['inventorySha256']==INPUT_INVENTORY
                and self.base.normalized_source(before['_files'])==previous['source'],'FAILED_SOURCE_CHANGED')
            old_hold_raw,old_hold=self.prior_hold()
            expected={'format':1,'kind':'go-erp-program-rollout-hold',**self.old_worker.program_gate_expected(OLD_CORE,previous['source'],INPUT_INVENTORY),'authorization':None}
            need(old_hold==expected,'PREVIOUS_HOLD_UNREVIEWED')
            audit=CONTROL/'private/audits'/('warm-start-repair-'+uuid.uuid4().hex);audit.mkdir(mode=0o700)
            capture=self.e.read_file(Path(previous['audit'])/'captured-source.json',16*1024**2,uid=0,modes=(0o600,))[0]
            for name,data in (('previous-rollout.json',previous_raw),('previous-state.json',self.e.canonical(state)),
                    ('captured-source.json',capture),('previous-hold.json',old_hold_raw)):
                self.e.atomic_write(audit/name,data,0,0,0o600)
            if pending is not None:self.e.atomic_write(audit/'previous-pending-acceptance.json',pending,0,0,0o600)
            self.job={'format':1,'kind':'go-erp-warm-start-repair-rollout','core':CORE,'seed':SEED,
                'runtime':self.runtime.name,'source':previous['source'],'inventory':INPUT_INVENTORY,
                'published_inventory_at_entry':previous['published_inventory_at_entry'],
                'captured_metadata_sha256':previous['captured_metadata_sha256'],
                'previous_journal_sha256':sha(previous_raw),'previous_audit':previous['audit'],'audit':str(audit),
                'previous_hold_sha256':sha(old_hold_raw),'previous_pending_sha256':sha(pending) if pending is not None else None,'remote_lease':FAILED_CANDIDATE,'phase':'preparing','prepare_started':False,
                'baseline_candidate':None,'baseline_counter':None,'baseline_remote':None,
                'candidate':None,'version':None,'transient_failures':0,'public_nonce':uuid.uuid4().hex}
            self.save()
        need(isinstance(self.job.get('public_nonce'),str) and re.fullmatch('[a-f0-9]{32}',self.job['public_nonce']),
            'ROLLOUT_PUBLIC_NONCE_INVALID')
        copied=self.e.read_file(Path(self.job['audit'])/'previous-rollout.json',16*1024**2,uid=0,modes=(0o600,))[0]
        need(copied==previous_raw,'PREVIOUS_JOURNAL_BYTES_CHANGED')
        held=self.e.read_file(Path(self.job['audit'])/'previous-hold.json',2*1024**2,uid=0,modes=(0o600,))[0]
        need(sha(held)==self.job.get('previous_hold_sha256'),'PREVIOUS_HOLD_BYTES_CHANGED')
        pending_copy=self.e.read_file(Path(self.job['audit'])/'previous-pending-acceptance.json',2*1024**2,uid=0,modes=(0o600,),optional=True)
        need((pending_copy is None and self.job.get('previous_pending_sha256') is None) or (pending_copy is not None
            and sha(pending_copy[0])==self.job.get('previous_pending_sha256')),'PREVIOUS_ACCEPTANCE_AUDIT_CHANGED')
        self.captured_metadata()
        self.base.EXPECTED_SOURCE,self.base.EXPECTED_INVENTORY=self.job['source'],self.job['inventory']
        self.expected=self.worker.program_gate_expected(CORE,self.job['source'],self.job['inventory'])

    def captured_metadata(self):
        raw=self.e.read_file(Path(self.job['audit'])/'captured-source.json',16*1024**2,uid=0,modes=(0o600,))[0]
        need(sha(raw)==self.job['captured_metadata_sha256'],'CAPTURED_METADATA_BYTES_CHANGED')
        value=self.base.parse(raw)
        need(isinstance(value,dict) and isinstance(value.get('inventory'),dict)
            and value==self.old_source._metadata(CAPTURE_SEED,value['inventory'])
            and value.get('inventorySha256')==self.job['inventory']==INPUT_INVENTORY,'CAPTURED_METADATA_BINDING_CHANGED')
        return value

    def verify_published_archive(self,state):
        previous=self.base.parse(self.verify_predecessor())
        baseline=self.base.parse(self.e.read_file(Path(previous['audit'])/'previous-state.json',16*1024**2,uid=0,modes=(0o600,))[0])
        for key in ('published','published_history','version_floor','sequence_floor'):
            need(state.get(key)==baseline.get(key),'PUBLISHED_BASELINE_CHANGED')
        raw=self.release.file('deployment/go_erp_published_download_root.py',ARCHIVE_RELEASE)
        archive=module(raw,'warm_start_repair_readonly_archive',MANUAL_PINS['go_erp_published_download_root.py']);archive.Stop=Stop
        shared=CONTROL/'shared';archive.directory(shared,self.owner.pw_gid)
        folder=shared/'installers';archive.directory(folder,self.owner.pw_gid)
        path=shared/'downloads.json';before=archive.read(path,1024**2,(0o640,),gid=self.owner.pw_gid)
        value=archive.manifest(self.publisher,archive.parse(before[0]))
        release=archive.native_release(self.publisher,state)
        need(release is not None and release.get('version')==INPUT_VERSION and release.get('source_revision')==INPUT_CANDIDATE
            and value['current']==release,'PUBLISHED_ARCHIVE_NOT_CURRENT')
        feed=self.publication();need(feed is None or feed==release,'PUBLIC_FEED_NOT_CURRENT')
        for item in (value['current'],value['previous']):
            if item is not None:archive.verified_archive_binary(self.publisher,folder,item,self.owner.pw_gid)
        need(archive.read(path,1024**2,(0o640,),gid=self.owner.pw_gid)==before
            and self.state()==state and self.publication()==feed,'PUBLISHED_ARCHIVE_CHANGED')
        return release

    def manual(self):
        e=self.e;payloads={name:self.release.file(PACKAGE_PROGRAM_PATHS[name],
            ARCHIVE_RELEASE if name=='go_erp_published_download_root.py' else None) for name in MANUAL_PINS}
        e.PROGRAM_PINS=MANUAL_PINS;runtime=e.install_runtime(payloads,MANUAL_RELEASES)
        receipt_path=CONTROL/'private/installation.json';before=e.read_file(receipt_path,8192,uid=0,modes=(0o600,))
        old=self.base.parse(before[0]);cohorts={e.sha(e.canonical(dict(sorted(pins.items())))):pins
            for pins in (OLD_MANUAL_PINS,MANUAL_PINS)}
        need(old.get('kind')=='go-erp-manual-installation' and old.get('format')==1
            and old.get('runtime') in cohorts,'MANUAL_RECEIPT_UNREVIEWED')
        old_runtime=MANUAL_RELEASES/old['runtime'];e.directory(old_runtime,0,0,0o555,False)
        pins=cohorts[old['runtime']];need({p.name for p in old_runtime.iterdir()}==set(pins),'MANUAL_RUNTIME_ROSTER_INVALID')
        for name,digest in pins.items():need(e.sha(e.read_file(old_runtime/name,uid=0,modes=(0o444,))[0])==digest,'MANUAL_RUNTIME_CHANGED')
        old_units=e.units(old_runtime);new_units=e.units(runtime)
        need(e.read_file(SYSTEMD/WATCH,65536,uid=0,modes=(0o644,))[0]==old_units[WATCH]==new_units[WATCH],
            'MANUAL_PATH_CHANGED')
        service=e.read_file(SYSTEMD/SERVICE,65536,uid=0,modes=(0o644,))
        need(service[0] in (old_units[SERVICE],new_units[SERVICE])
            and old.get('units')=={k:e.sha(v) for k,v in old_units.items()},'MANUAL_SERVICE_CHANGED')
        desired=e.canonical({'format':1,'kind':'go-erp-manual-installation','release':self.release.revision,
            'runtime':runtime.name,'units':{k:e.sha(v) for k,v in new_units.items()}})
        audit=CONTROL/'private/audits'/('warm-start-manual-'+uuid.uuid4().hex);audit.mkdir(mode=0o700)
        e.atomic_write(audit/'before-service',service[0],0,0,0o600);e.atomic_write(audit/'before-receipt',before[0],0,0,0o600)
        try:
            e.atomic_write(SYSTEMD/SERVICE,new_units[SERVICE],0,0,0o644);e.run(['/usr/bin/systemctl','daemon-reload'])
            e.atomic_write(receipt_path,desired,0,0,0o600)
        except BaseException:
            current=e.read_file(SYSTEMD/SERVICE,65536,uid=0,modes=(0o644,))[0]
            need(current in (service[0],new_units[SERVICE]),'MANUAL_ROLLBACK_REQUIRES_REVIEW')
            e.atomic_write(SYSTEMD/SERVICE,service[0],0,0,0o644);e.run(['/usr/bin/systemctl','daemon-reload'])
            e.atomic_write(receipt_path,before[0],0,0,0o600);raise
        self.manual_runtime=runtime

    def prepare(self,locked_utility):
        b,u=self.base,self.utility
        expected_hold={'format':1,'kind':'go-erp-program-rollout-hold',**self.expected}
        held=self.e.read_file(WORK/'program-rollout-hold.json',1024*1024,uid=0,modes=(0o600,),optional=True)
        replacing_old_hold=False
        if held is not None:
            value=b.parse(held[0])
            old_expected={'format':1,'kind':'go-erp-program-rollout-hold',
                **self.old_worker.program_gate_expected(OLD_CORE,self.job['source'],self.job['inventory']),'authorization':None}
            if value==old_expected:
                self.quiet_hold()
                archived=self.e.read_file(Path(self.job['audit'])/'previous-hold.json',2*1024**2,uid=0,modes=(0o600,))[0]
                need(held[0]==archived and sha(archived)==self.job['previous_hold_sha256'],'PREVIOUS_HOLD_BYTES_CHANGED')
                replacing_old_hold=True
            else:
                need(set(value)==set(expected_hold)|{'authorization'}
                    and all(value.get(k)==v for k,v in expected_hold.items()),'EXISTING_ROLLOUT_HOLD_UNREVIEWED')
        elif self.job['phase']=='preparing':
            need(False,'PREVIOUS_HOLD_MISSING')
        def migration(value,old_seed,new_seed,publication=None):
            need(old_seed==OLD_SEED and new_seed==SEED,'MIGRATION_STATE_CHANGED')
            checked_failed_state(value,publication)
            self.verify_published_archive(value)
            need(value.get('last_remote_sha')==self.job['remote_lease'],'MIGRATION_REMOTE_CHANGED')
            snapshot=self.snapshot(value,WORK/'checkout',self.old_source)
            need(snapshot['metadata']['inventorySha256']==self.job['inventory']
                and b.normalized_source(snapshot['_files'])==self.job['source'],'OLD_SOURCE_CHANGED')
            return migrate_state(value,self.old_source,self.source)
        def probe(target,live,checkout,current,pins,seed,utility):
            need(target==self.runtime and seed==SEED,'PROBE_BINDING_INVALID')
            folder=u.Directory(target,0o755)
            try:u.validate_runtime(folder,self.pins)
            finally:folder.close()
            snapshot=self.snapshot(current,checkout,self.source)
            need(snapshot['metadata']['inventorySha256']==self.job['inventory']
                and b.normalized_source(snapshot['_files'])==self.job['source'],'STAGED_SOURCE_CHANGED')
            folder=u.Directory(target,0o755)
            try:u.validate_runtime(folder,self.pins)
            finally:folder.close()
            return snapshot['metadata']
        def remote(checkout):
            need(b.git(checkout,'ls-remote','--exit-code',b.REMOTE,'refs/heads/codex/go-erp-live-source-20261021',maximum=4096)
                ==(self.job['remote_lease']+'\trefs/heads/codex/go-erp-live-source-20261021\n').encode(),'REMOTE_LEASE_CHANGED')
        b.migrated_state=migration
        # Both old/new and interrupted mixed-slot states enter the original
        # recovery/validation path before any source checkout is trusted.
        result=b.upgrade(self.payloads,pins=self.pins,utility=locked_utility,new_seed=SEED,core=CORE,
            old_pins={k:v[0] for k,v in self.old_tick.PINS.items()},probe=probe,remote=remote)
        need(result.get('ok') is True,'NATIVE_TRANSACTION_'+(result.get('reason') if
            isinstance(result.get('reason'),str) and re.fullmatch('[A-Z0-9_]{1,70}',result['reason']) else 'FAILED'))
        state=self.state();need(state['base_revision']==SEED,'NATIVE_PREPARATION_INCOMPLETE');self.live(state)
        if replacing_old_hold:
            reviewed=copy.deepcopy(state);reviewed['base_revision']=OLD_SEED
            checked_failed_state(reviewed,self.publication());self.verify_published_archive(state);self.quiet_hold();remote(WORK/'checkout')
            current=self.e.read_file(WORK/'program-rollout-hold.json',1024*1024,uid=0,modes=(0o600,))[0]
            need(current==held[0],'PREVIOUS_HOLD_BYTES_CHANGED')
            self.e.atomic_write(WORK/'program-rollout-hold.json',self.e.canonical({**expected_hold,'authorization':None}),0,0,0o600)
        if not self.ready(state) and held is None:
            self.e.atomic_write(WORK/'program-rollout-hold.json',self.e.canonical({**expected_hold,'authorization':None}),0,0,0o600)
        self.job['phase']='prepared' if self.job['phase']=='preparing' else self.job['phase']
        if result.get('audit_directory'):self.job['native_audit']=result['audit_directory']
        self.save()

    def candidate(self,state):
        desired=state.get('desired')
        need(isinstance(desired,dict) and desired.get('candidatePolicy')==POLICY
            and desired.get('inventorySha256')==self.job['inventory'] and desired.get('pushed') is True
            and desired.get('commit')==state.get('last_remote_sha') and REV.fullmatch(desired.get('commit','')),
            'CANDIDATE_UNREVIEWED')
        number=re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)',desired.get('version',''))
        need(number is not None and int(number[1])==state.get('counter') and int(number[1])<=SAFE,
            'CANDIDATE_COUNTER_MISMATCH')
        historical=WORK/'checkout';b=self.base
        b.checkout(historical,SEED,self.utility)
        staged=b.git(historical,'ls-files','--stage','-z');b.git(historical,'update-index','--refresh')
        need(b.git(historical,'ls-files','--stage','-z')==staged,'HISTORICAL_INDEX_CHANGED')
        b.git(historical,'fetch','--quiet','--depth=2','--no-tags','--no-recurse-submodules',b.REMOTE,desired['commit'])
        temporary=Path(tempfile.mkdtemp(prefix='warm-start-candidate-proof-',dir=WORK));target=temporary/'candidate';added=False
        try:
            b.git(historical,'worktree','add','--quiet','--detach',str(target),desired['commit']);added=True
            folder=self.utility.Directory(self.runtime,0o755)
            try:verifier=self.utility.module_at(folder,'verify_go_erp_current_candidate_ci.py',self.pins['verify_go_erp_current_candidate_ci.py'])
            finally:folder.close()
            proof=verifier.validate_checkout(target,historical,expected_base=SEED)
            need(proof.get('revision')==desired['commit'] and proof.get('version')==desired['version']
                and proof.get('source')==self.job['source'] and proof.get('inventory_sha256')==self.job['inventory'],
                'CANDIDATE_PROOF_INVALID')
        finally:
            if added:b.git(historical,'worktree','remove','--force',str(target))
            temporary.rmdir()
        need(b.git(historical,'ls-remote','--exit-code',b.REMOTE,'refs/heads/codex/go-erp-live-source-20261021',maximum=4096)
            ==(desired['commit']+'\trefs/heads/codex/go-erp-live-source-20261021\n').encode(),'CANDIDATE_REMOTE_CHANGED')
        return desired

    def authorized(self):
        state=self.state();self.live(state);desired=self.candidate(state)
        pending=WORK/'pending-program-acceptance.json'
        value=self.worker.program_gate_json(pending)
        need(value.get('candidate_revision')==desired['commit'] and value.get('version')==desired['version']
            and all(value.get(k)==v for k,v in self.expected.items()),'ACCEPTANCE_BINDING_INVALID')
        self.worker.program_gate_authorize(WORK,pending,self.expected,state,
            lambda folder,run,wanted:self.worker.verify_proof(folder,run,wanted,self.publisher),self.publication)

    def ready(self,state):
        return self.worker.program_gate_completed(WORK,self.expected,state,self.publication)

    def archive(self):
        path=self.manual_runtime/'go_erp_published_download_root.py'
        need(sha(self.e.read_file(path,uid=0,modes=(0o444,))[0])==MANUAL_PINS[path.name],'ARCHIVE_HELPER_CHANGED')
        archive=module(path.read_bytes(),'warm_start_published_archive',MANUAL_PINS[path.name]);archive.Stop=Stop
        result=archive.archive_current(ROOT,WORK/'state/current.json',CONTROL/'shared',self.owner.pw_gid)
        need(result.get('available') is True and result.get('current_version')==self.job['version'],
            'NEW_PUBLISHED_DOWNLOAD_UNAVAILABLE')
        return result

    def tick(self,allow,budget=2700):
        args=['/usr/bin/python3','-I','-B',str(self.manual_runtime/'go_erp_manual_tick_root.py'),
            '--inventory',self.job['inventory']]
        if self.job['candidate'] is not None:args+=['--candidate',self.job['candidate']]
        elif allow:args+=['--allow-prepare']
        started=time.monotonic();deadline=started+min(2700,budget)
        with subprocess.Popen(args,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE) as child:
            while True:
                remaining=deadline-time.monotonic()
                if remaining<=0:
                    child.kill();child.communicate();raise Stop('WORKER_TIMED_OUT_RETRY_SAME_COMMAND')
                try:
                    stdout,stderr=child.communicate(timeout=min(30,remaining));break
                except subprocess.TimeoutExpired:
                    print('GO_ERP_WARM_START_REPAIR_WAITING_WORKER seconds='+str(int(time.monotonic()-started)),flush=True)
            result=types.SimpleNamespace(stdout=stdout,stderr=stderr,returncode=child.returncode)
        need(len(result.stdout)+len(result.stderr)<=8192,'TICK_OUTPUT_INVALID')
        lines=(result.stdout+result.stderr).decode('utf8','strict').splitlines()
        need(result.returncode in (0,1) and 1<=len(lines)<=16 and all(CODE.fullmatch(v) for v in lines),'TICK_OUTPUT_INVALID')
        return result.returncode,lines[-1]

    def complete(self):
        deadline=time.monotonic()+7200
        while time.monotonic()<deadline:
            quiet(self.e)
            with self.utility.watcher_lock(WORK):
                state=self.state();self.live(state)
                if self.ready(state):
                    desired=self.candidate(state);self.job.update(candidate=desired['commit'],version=desired['version'])
                    archive=self.archive();self.job['phase']='ready';self.save();return archive
                desired=state.get('desired') or {};allow=False
                self.public_status('waiting-ci' if self.job['candidate'] is not None else 'running')
                retry=desired.get('retry_at',0)
                need(type(retry) in (int,float) and math.isfinite(retry) and 0<=retry<=SAFE,'BACKOFF_INVALID')
                delay=max(0,min(60,math.ceil(retry-time.time())))
                if self.job['candidate'] is None and desired.get('candidatePolicy')==OLD_POLICY:
                    reviewed=copy.deepcopy(state);reviewed['base_revision']=OLD_SEED
                    checked_failed_state(reviewed,self.publication());self.verify_published_archive(state)
                    # The original worker itself supersedes the exact old policy before backoff/status.
                    # Do not reset any persisted old failure, retry time, counter or candidate.
                    delay=0
                if self.job['candidate'] is not None:
                    need(desired.get('commit')==self.job['candidate'] and desired.get('version')==self.job['version']
                        and desired.get('candidatePolicy')==POLICY and desired.get('inventorySha256')==self.job['inventory'],
                        'LATCHED_CANDIDATE_CHANGED')
                    need(desired.get('status') not in ('retry-build','blocked'),'CI_REVIEW_REQUIRED')
                elif not delay:
                    if self.job['prepare_started']:
                        if desired.get('candidatePolicy')==POLICY and desired.get('commit')!=self.job['baseline_candidate']:
                            self.job.update(candidate=desired['commit'],version=desired['version'])
                        else:
                            # A crash before next_counter consumed no version.
                            # Exact old counter/remote/candidate bounds the retry.
                            need(allocation_unconsumed(self.job,state) and self.job['transient_failures']<3,
                                'ALLOCATION_REVIEW_REQUIRED')
                            self.job['transient_failures']+=1;allow=True
                    else:
                        self.job.update(prepare_started=True,baseline_candidate=desired.get('commit'),
                            baseline_counter=state['counter'],baseline_remote=state.get('last_remote_sha'));allow=True
                    self.job['phase']='running';self.save()
            if delay:
                print('GO_ERP_WARM_START_REPAIR_RESPECTING_BACKOFF seconds='+str(delay),flush=True);time.sleep(delay);continue
            rc,code=self.tick(allow,max(0,deadline-time.monotonic()))
            print(code,flush=True)
            if rc==0 and code=='GO_ERP_LIVE_UPDATE_ALREADY_RUNNING':
                if allow:self.job['prepare_started']=False;self.save()
                time.sleep(5);continue
            with self.utility.watcher_lock(WORK):
                state=self.state();desired=state.get('desired') or {}
                if self.job['candidate'] is None:
                    if desired.get('candidatePolicy')==POLICY and desired.get('commit')!=self.job['baseline_candidate']:
                        self.job.update(candidate=desired['commit'],version=desired['version']);self.save()
                    elif (rc==1 and code=='GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
                            and allocation_unconsumed(self.job,state) and self.job['transient_failures']<3):
                        self.job['prepare_started']=False;self.job['transient_failures']+=1;self.save();continue
                    else:raise Stop('ALLOCATION_REVIEW_REQUIRED')
                need(desired.get('commit')==self.job['candidate'] and desired.get('version')==self.job['version'],
                    'LATCHED_CANDIDATE_CHANGED')
                self.public_status('waiting-ci')
                if rc:
                    if code=='GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT' and self.job['transient_failures']<3 \
                            and desired.get('status') in ('waiting-ci','prepared'):
                        self.job['transient_failures']+=1;self.save()
                    else:raise Stop(code)
                elif code=='GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED':
                    self.public_status('publishing');self.authorized()
                elif code in ('GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW','GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED'):
                    raise Stop(code)
            time.sleep(30)
        raise Stop('COMPLETION_TIMED_OUT_RETRY_SAME_COMMAND')


def public_status_after_unlock(ctx,state,code=None):
    """A queued web job may acquire this lock as soon as the path is active."""
    try:
        with ExitStack() as status_lock:
            ctx.e.acquire(status_lock,CONTROL/'private/worker.lock')
            return ctx.public_status(state,code)
    except Stop as error:
        if str(error)=='INSTALLATION_BUSY':return False
        raise


def execute(release,engine,complete):
    need(not PINS_PENDING and REV.fullmatch(CORE) and REV.fullmatch(SEED) and CORE!=SEED
        and all(re.fullmatch('[a-f0-9]{64}',v) for v in MANUAL_PINS.values()),'NATIVE_PINS_PENDING')
    quiet(engine)
    with ExitStack() as stack:
        engine.acquire(stack,CONTROL/'private/install.lock')
        engine.run(['/usr/bin/systemctl','stop',WATCH]);quiet(engine)
        need(engine.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])=='inactive','MANUAL_PATH_STILL_ACTIVE')
        with ExitStack() as workers:
            engine.acquire(workers,CONTROL/'private/worker.lock')
            ctx=Context(release,engine)
            try:
                with ctx.utility.watcher_lock(WORK) as verify:
                    ctx.job_record();ctx.manual();ctx.public_status('running',claim=True)
                    ctx.prepare(LockedUtility(ctx.utility,verify))
                if not complete:
                    return {'ok':True,'published':False,'prepared':True,'manual_path_paused':True,'version':ctx.job['version']}
                archive=ctx.complete()
            except BaseException:
                if ctx.job is not None and 'public_nonce' in ctx.job:
                    try:ctx.public_status('failed','GO_ERP_WARM_START_REPAIR_UPDATE_FAILED')
                    except BaseException:pass
                raise
        # Release the broker lock before path activation so a pending explicit
        # owner request can start without a failed flock/rate-limit cycle.
        try:
            engine.run(['/usr/bin/systemctl','start',WATCH])
            need(engine.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])=='active',
                'PUBLISHED_PATH_ACTIVATION_REQUIRED')
            public_status_after_unlock(ctx,'ready')
        except BaseException:
            try:public_status_after_unlock(ctx,'failed','GO_ERP_WARM_START_REPAIR_UPDATE_FAILED')
            except BaseException:pass
            raise
        return {'ok':True,'published':True,'prepared':True,'manual_path_paused':False,
            'version':ctx.job['version'],'candidate_revision':ctx.job['candidate'],'archive':archive}


def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--git-dir',required=True)
    parser.add_argument('--release',required=True);mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply',action='store_true');mode.add_argument('--complete',action='store_true');args=parser.parse_args()
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524' and REV.fullmatch(args.release),'ROOT_HOST_RELEASE_REQUIRED')
    os.umask(0o077);release=Release(args.git_dir,args.release)
    engine=module(release.file('deployment/apply_go_erp_manual_updates_root.py',BOOTSTRAP),'warm_start_file_engine',ENGINE_SHA)
    engine.Stop=Stop
    repository=release.repository.absolute();engine.parents(repository)
    need(repository.resolve()==repository and repository.stat().st_uid==0 and not repository.stat().st_mode&0o022,'REPOSITORY_UNSAFE')
    need(release.git('rev-parse',args.release+'^{commit}').decode().strip()==args.release,'RELEASE_INVALID')
    result=execute(release,engine,args.complete)
    print(json.dumps({'format':1,'kind':'go-erp-warm-start-repair-rollout',**result,'database_changed':False,
        'credentials_disclosed':False,'automatic_updates_enabled':False}),flush=True)
    print('GO_ERP_WARM_START_REPAIR_PUBLISHED_MANUAL_UPDATES_ONLY' if result['published'] else 'GO_ERP_WARM_START_REPAIR_PREPARED_PUBLICATION_HELD')


if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error) if isinstance(error,Stop) and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,150}',str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format':1,'kind':'go-erp-warm-start-repair-rollout','ok':False,'code':'GO_ERP_WARM_START_REPAIR_'+code,
            'published':False,'database_changed':False,'credentials_disclosed':False,'automatic_updates_enabled':False}),flush=True)
        raise SystemExit(1)

