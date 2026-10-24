#!/usr/bin/env python3
"""Upgrade the reviewed close/reopen native seed, preserving manual releases."""
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
OLD_TICK_SHA='753baf72d9171adc0d084350fa319ef71c0fa6843c684b42fed5dbef76c588f8'
BASE_BLOB='ede9af30ea42ba53a5376d5e1cf634d9b3032f2a'
UTILITY_BLOB='60df7ec876f9634e0a7dcb4c5a420d0d5ecd9ae9'
OLD_SEED='1ce9bb30034e300e3ed693e28a4dcbd608fb66c4'
OLD_CORE='42846d6715edef9c9de926fd010aae52cf410e92'
OLD_RUNTIME='policy-acf5db0bbd0d415d753c0081a43de7b02b38509546a25382d69eb8aedbb7cc0a'
OLD_POLICY='historical-baseline-v2-native-windows-fixture-paths-v1'
CORE='6f5133af48ae03d626f0ac6ee02ff3a724c72407'
SEED='710a11953f03aaf8139c2745f4ed11e0a3d78efc'
POLICY='historical-baseline-v2-native-close-reopen-v1'
MANUAL_PINS={'go_erp_manual_updates_root.py':'cbd04c0153557bb5b9846620fded668dda3379a2aafab19bd5c1ecc613075a75',
    'go_erp_manual_tick_root.py':'b9774012613cca3223dc21ea29497f67bd532b3af2906c788da499f439cc3d9a',
    'go_erp_published_download_root.py':'2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
OLD_MANUAL_PINS={'go_erp_manual_updates_root.py':'214978522787f36aee22112e3837e63f113e0f5600eb8cee31b2a35e8223b753',
    'go_erp_manual_tick_root.py':OLD_TICK_SHA,
    'go_erp_published_download_root.py':MANUAL_PINS['go_erp_published_download_root.py']}
PACKAGE_PROGRAM_PATHS={'go_erp_manual_updates_root.py':'deployment/go_erp_close_reopen_manual_root.py',
    'go_erp_manual_tick_root.py':'deployment/go_erp_close_reopen_tick_root.py',
    'go_erp_published_download_root.py':'deployment/go_erp_published_download_root.py'}
SERVICE='go-erp-manual-updates.service'
WATCH='go-erp-manual-updates.path'
CODE=re.compile(r'GO_ERP_[A-Z0-9_]{1,140}\Z')
REV=re.compile(r'[a-f0-9]{40}\Z')
SAFE=9007199254740991
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
            for before,after in ((OLD_SEED.encode(),new_seed.encode()),
                    (b'-native-windows-fixture-paths-v1',b'-native-close-reopen-v1')):
                need(raw.count(before)==1,'NATIVE_PATCH_CONTEXT_INVALID')
                raw=raw.replace(before,after)
        if name=='go_erp_live_updates.py':
            need(raw.count(SHORTCUT)==1,'NATIVE_SHORTCUT_CONTEXT_INVALID')
            raw=raw.replace(SHORTCUT,BOUND_SHORTCUT)
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
        and type(value['format']) is int and type(value.get('counter')) is int and 42<=value['counter']<=SAFE
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


def bootstrap(release,engine):
    raw=engine.read_file(CONFIG,16384,uid=0,modes=(0o600,))[0]
    config=json.loads(raw);base=config.get('base_revision')
    need(base in (OLD_SEED,SEED),'BASE_REVISION_UNREVIEWED')
    prior=engine.read_file(CONTROL/'private'/('close-reopen-'+CORE+'.json'),16*1024**2,uid=0,modes=(0o600,),optional=True)
    if prior is not None:
        job=json.loads(prior[0]);need(job.get('kind')=='go-erp-close-reopen-rollout'
            and job.get('core')==CORE and job.get('seed')==SEED,'BOOTSTRAP_JOURNAL_UNREVIEWED')
        return
    if base==SEED:return
    installer=release.file('deployment/apply_go_erp_published_downloads_root.py',BOOTSTRAP)
    need(sha(installer)==BOOTSTRAP_INSTALL_SHA,'BOOTSTRAP_PIN_INVALID')
    directory=Path(tempfile.mkdtemp(prefix='go-close-bootstrap-',dir='/var/tmp'))
    try:
        target=directory/'apply.py';engine.atomic_write(target,installer,0,0,0o600)
        result=subprocess.run(['/usr/bin/python3','-I','-B',str(target),'--git-dir',str(release.repository),
            '--release',BOOTSTRAP,'--apply'],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,timeout=600)
        need(len(result.stdout)<=65536,'BOOTSTRAP_OUTPUT_INVALID')
        if result.returncode:
            lines=result.stdout.decode('utf8','replace').splitlines()
            if len(lines)==1:
                try:code=json.loads(lines[0]).get('code','')
                except ValueError:code=''
                if CODE.fullmatch(code):raise Stop(code)
            raise Stop('BOOTSTRAP_FAILED')
        lines=result.stdout.decode('utf8','strict').splitlines()
        need(len(lines)==2 and lines[1]=='GO_ERP_PUBLISHED_DOWNLOADS_READY_MANUAL_UPDATES_ONLY',
            'BOOTSTRAP_OUTPUT_INVALID')
        value=json.loads(lines[0]);need(value.get('ok') is True and value.get('path_active') is True
            and value.get('database_changed') is False and value.get('automatic_updates_enabled') is False,
            'BOOTSTRAP_OUTPUT_INVALID')
    finally:
        for path in directory.iterdir():path.unlink()
        directory.rmdir()


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


class Context:
    def __init__(self,release,engine):
        self.release,self.e=release,engine;self.owner=pwd.getpwnam('fasakha')
        self.base=module(release.file('deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py',PRIOR),
            'close_native_transaction',BASE_BLOB,True)
        self.utility=module(release.file('deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py',PRIOR),
            'close_native_utility',UTILITY_BLOB,True)
        self.old_tick=module(release.file('deployment/go_erp_manual_tick_root.py',BOOTSTRAP),
            'close_old_native_tick',OLD_TICK_SHA)
        self.old_worker=self.old_tick.load_runtime()
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
        self.job_path=CONTROL/'private'/('close-reopen-'+CORE+'.json')
        self.job=None;self.manual_runtime=None
        b=self.base;b.OLD_SEED=OLD_SEED;b.OLD_RUNTIME=OLD_RUNTIME;b.OLD_PINS={k:v[0] for k,v in self.old_tick.PINS.items()}
        b.OLD_ROSTER=b.ROSTER=tuple(sorted(self.pins));b.NEW_SEED=SEED;b.CORE=CORE
        b.PREFIX='close-reopen-seed-upgrade-';b.EXPECTED_SOURCE={'framework':'8.83.29'}

    def state(self):
        value=self.base.parse(self.e.read_file(WORK/'state/current.json',16*1024**2,uid=0,modes=(0o600,))[0])
        need(value.get('base_revision') in (OLD_SEED,SEED),'STATE_BASE_UNREVIEWED')
        return self.base.checked_state(value,value['base_revision'])
    def publication(self):
        raw=self.e.read_file(ROOT/'storage/app/desktop-updates/current.json',512*1024,uid=0,modes=(0o600,0o644),optional=True)
        return self.publisher.validate_release(self.base.parse(raw[0])) if raw else None
    def save(self):self.e.atomic_write(self.job_path,self.e.canonical(self.job),0,0,0o600)
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
    def job_record(self):
        existing=self.e.read_file(self.job_path,16*1024**2,uid=0,modes=(0o600,),optional=True)
        if existing:
            value=self.base.parse(existing[0]);need(value.get('format')==1 and value.get('kind')=='go-erp-close-reopen-rollout'
                and value.get('core')==CORE and value.get('seed')==SEED and value.get('runtime')==self.runtime.name
                and isinstance(value.get('source'),dict) and isinstance(value.get('inventory'),str)
                and re.fullmatch('[a-f0-9]{64}',value['inventory'])
                and value.get('phase') in ('preparing','prepared','running','ready')
                and type(value.get('prepare_started')) is bool,'ROLLOUT_JOURNAL_UNREVIEWED')
            self.job=value
        else:
            state=self.state();need(state.get('base_revision')==OLD_SEED,'ROLLOUT_JOURNAL_MISSING')
            old=self.snapshot(state,WORK/'checkout',self.old_source)
            source=self.base.normalized_source(old['_files'])
            self.job={'format':1,'kind':'go-erp-close-reopen-rollout','core':CORE,'seed':SEED,'runtime':self.runtime.name,
                'source':source,'inventory':old['metadata']['inventorySha256'],'phase':'preparing',
                'remote_lease':state.get('last_remote_sha'),'prepare_started':False,'baseline_candidate':None,
                'baseline_counter':None,'baseline_remote':None,
                'candidate':None,'version':None,'transient_failures':0}
            need(REV.fullmatch(self.job['remote_lease'] or ''),'REMOTE_LEASE_UNREVIEWED');self.save()
        self.base.EXPECTED_SOURCE,self.base.EXPECTED_INVENTORY=self.job['source'],self.job['inventory']
        self.expected=self.worker.program_gate_expected(CORE,self.job['source'],self.job['inventory'])

    def manual(self):
        e=self.e;payloads={name:self.release.file(PACKAGE_PROGRAM_PATHS[name]) for name in MANUAL_PINS}
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
        audit=CONTROL/'private/audits'/('close-manual-'+uuid.uuid4().hex);audit.mkdir(mode=0o700)
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
        if held is not None:
            value=b.parse(held[0])
            need(set(value)==set(expected_hold)|{'authorization'}
                and all(value.get(k)==v for k,v in expected_hold.items()),'EXISTING_ROLLOUT_HOLD_UNREVIEWED')
        def migration(value,old_seed,new_seed,publication=None):
            need(old_seed==OLD_SEED and new_seed==SEED,'MIGRATION_STATE_CHANGED')
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
        temporary=Path(tempfile.mkdtemp(prefix='close-candidate-proof-',dir=WORK));target=temporary/'candidate';added=False
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
        archive=module(path.read_bytes(),'close_published_archive',MANUAL_PINS[path.name]);archive.Stop=Stop
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
                    print('GO_ERP_CLOSE_REOPEN_WAITING_WORKER seconds='+str(int(time.monotonic()-started)),flush=True)
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
                retry=desired.get('retry_at',0)
                need(type(retry) in (int,float) and math.isfinite(retry) and 0<=retry<=SAFE,'BACKOFF_INVALID')
                delay=max(0,min(60,math.ceil(retry-time.time())))
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
                print('GO_ERP_CLOSE_REOPEN_RESPECTING_BACKOFF seconds='+str(delay),flush=True);time.sleep(delay);continue
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
                if rc:
                    if code=='GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT' and self.job['transient_failures']<3 \
                            and desired.get('status') in ('waiting-ci','prepared'):
                        self.job['transient_failures']+=1;self.save()
                    else:raise Stop(code)
                elif code=='GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED':self.authorized()
                elif code in ('GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW','GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED'):
                    raise Stop(code)
            time.sleep(30)
        raise Stop('COMPLETION_TIMED_OUT_RETRY_SAME_COMMAND')


def execute(release,engine,complete):
    need(REV.fullmatch(CORE) and REV.fullmatch(SEED) and CORE!=SEED
        and all(re.fullmatch('[a-f0-9]{64}',v) for v in MANUAL_PINS.values()),'NATIVE_PINS_PENDING')
    quiet(engine);bootstrap(release,engine)
    with ExitStack() as stack:
        engine.acquire(stack,CONTROL/'private/install.lock')
        engine.run(['/usr/bin/systemctl','stop',WATCH]);quiet(engine)
        need(engine.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])=='inactive','MANUAL_PATH_STILL_ACTIVE')
        with ExitStack() as workers:
            engine.acquire(workers,CONTROL/'private/worker.lock')
            ctx=Context(release,engine)
            with ctx.utility.watcher_lock(WORK) as verify:
                ctx.job_record();ctx.manual()
                ctx.prepare(LockedUtility(ctx.utility,verify))
            if not complete:
                return {'ok':True,'published':False,'prepared':True,'manual_path_paused':True,'version':ctx.job['version']}
            archive=ctx.complete()
        # Release the broker lock before path activation so a pending explicit
        # owner request can start without a failed flock/rate-limit cycle.
        engine.run(['/usr/bin/systemctl','start',WATCH])
        need(engine.run(['/usr/bin/systemctl','show',WATCH,'-p','ActiveState','--value'])=='active',
            'PUBLISHED_PATH_ACTIVATION_REQUIRED')
        return {'ok':True,'published':True,'prepared':True,'manual_path_paused':False,
            'version':ctx.job['version'],'candidate_revision':ctx.job['candidate'],'archive':archive}


def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--git-dir',required=True)
    parser.add_argument('--release',required=True);mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--apply',action='store_true');mode.add_argument('--complete',action='store_true');args=parser.parse_args()
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524' and REV.fullmatch(args.release),'ROOT_HOST_RELEASE_REQUIRED')
    os.umask(0o077);release=Release(args.git_dir,args.release)
    engine=module(release.file('deployment/apply_go_erp_manual_updates_root.py',BOOTSTRAP),'close_file_engine',ENGINE_SHA)
    engine.Stop=Stop
    repository=release.repository.absolute();engine.parents(repository)
    need(repository.resolve()==repository and repository.stat().st_uid==0 and not repository.stat().st_mode&0o022,'REPOSITORY_UNSAFE')
    need(release.git('rev-parse',args.release+'^{commit}').decode().strip()==args.release,'RELEASE_INVALID')
    result=execute(release,engine,args.complete)
    print(json.dumps({'format':1,'kind':'go-erp-close-reopen-rollout',**result,'database_changed':False,
        'credentials_disclosed':False,'automatic_updates_enabled':False}),flush=True)
    print('GO_ERP_CLOSE_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY' if result['published'] else 'GO_ERP_CLOSE_REOPEN_PREPARED_PUBLICATION_HELD')


if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error) if isinstance(error,Stop) and re.fullmatch(r'(?:GO_ERP_)?[A-Z0-9_]{1,150}',str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'format':1,'kind':'go-erp-close-reopen-rollout','ok':False,'code':'GO_ERP_CLOSE_REOPEN_'+code,
            'published':False,'database_changed':False,'credentials_disclosed':False,'automatic_updates_enabled':False}),flush=True)
        raise SystemExit(1)
