#!/usr/bin/env python3
"""Guarded native fullscreen successor. Publication remains held for Windows proof."""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import sys
import tempfile
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
OLD_SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
CORE = '53266b0596533b2752480d148608886404f307db'
NEW_SEED = '6c261f7928a2d82e2906d7e59c1d6144ecf40296'
OLD_CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
OLD_CANDIDATE = 'dceb59ad489bbba6b6534a4da784f6d26c82b2ff'
ADVANCED_CANDIDATE = '5f2b0fecafec1c3803499284b0abcca4ecfed7e4'
ADVANCED_INVENTORY = 'de5ac407604249df2198aa71c2d7b4ca0a88ac16bba3cf3f028c61e3077111d8'
ADVANCED_SOURCE = {'format':1,'files':1346,'framework':'8.83.29','sha256':'41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63'}
OBSERVED_OLD_HOLD_INVENTORY = '1480586d09f7b87ac2528f74b14a5694c631d31c6ddc5c32eb8dc750c5550a9c'
OBSERVED_OLD_HOLD_SHA256 = '8cfd6212d2e3e9c7ed72f4e555eb4fd71639c8dc0c8567c4c450a3fae768262f'
OLD_INVENTORY = '0068ecaeb994a7c7bd3916eeaa137a1c33e0f2ddf3f1e5b3807a35db61d56005'
BEFORE_INVENTORY = 'fddb71ab6baa4a13292816355e463054dbcca600135ce132cfaa36e0eab2faef'
AFTER_INVENTORY = '2a356d414bd5c60d51be3a17ab526c16038b3eec1f50e775d6169becc40107eb'
OLD_SOURCE = {'format':1,'files':1342,'framework':'8.83.29','sha256':'13bf1542e2118724272545f70f8eb2bd113fc1fe475bbc9a378135528f6824ea'}
BEFORE_SOURCE = {'format':1,'files':1346,'framework':'8.83.29','sha256':'5825ec04b927fcafc42e04bd9ee4705a2e1862bb62be700a116502eacad60a1e'}
AFTER_SOURCE = {'format':1,'files':1346,'framework':'8.83.29','sha256':'78766b080df9e152ef1111c6ac8d83a93eebb41a05344b67c8338ad9c1826c0b'}
HR_INVENTORY = '11826400c8b3d7ee203ba0ab40433ab5a5cfc769e3f4bc315fe3d4d01980020e'
HR_SOURCE = {'format':1,'files':1346,'framework':'8.83.29','sha256':'9ab3e06ee816ecc9525e1abb08602644c561d1659d944254ecb6d299cfb9e271'}
HR_BEFORE_INVENTORY = 'b1152d57f3ffe2072af9ed0b002ad185d2f630f3708eb26cba620f568ae058e2'
HR_BEFORE_SOURCE = {'format':1,'files':1346,'framework':'8.83.29','sha256':'fc965914186bbb30fbcf4d96dc2a3e465af439b91c6dd1121f4ec8ebdbfed267'}
HR_AFTER_INVENTORY = 'c3d17d27d6159d0060bd5366c9deb33309aab6d569d4894770f2ca0f197c0480'
HR_AFTER_SOURCE = {'format':1,'files':1346,'framework':'8.83.29','sha256':'85d8106a12395de58561b71003e4140070ca3026963cdfd70923561ead54289d'}
HUD_BEFORE_INVENTORY = '5d422e95d391beaeffde93d5a7a142346a5584bbf22219174eb74a6d19365037'
HUD_BEFORE_SOURCE = {'format':1,'files':1351,'framework':'8.83.29','sha256':'77fba8441ca0d455b17567a491a2cbd33352fc8b2d9552657b86ddeee3882234'}
HUD_AFTER_INVENTORY = 'b825fee261d11e310bb21bce8829fb23ca16526d080e0e1ed4699fbd845f9879'
HUD_AFTER_SOURCE = {'format':1,'files':1351,'framework':'8.83.29','sha256':'b459f29cab22ba168b502bd3392e4c72941dfdeaa827e5b8554becec2e1061d2'}
HUD_ADVANCED_INVENTORY = '7088d7bd171802f7129c990255d1e059dd214dbc02c73adc8efa89f01735a77e'
HUD_ADVANCED_SOURCE = {'format':1,'files':1351,'framework':'8.83.29','sha256':'cf9da626f6c32004bcf4ed7bf86589b7a71a41a621453738b4a10ad7d5327671'}
CANDIDATE_SOURCES = {OLD_INVENTORY:OLD_SOURCE,BEFORE_INVENTORY:BEFORE_SOURCE,
                     AFTER_INVENTORY:AFTER_SOURCE,ADVANCED_INVENTORY:ADVANCED_SOURCE,HR_INVENTORY:HR_SOURCE,
                     HR_BEFORE_INVENTORY:HR_BEFORE_SOURCE,HR_AFTER_INVENTORY:HR_AFTER_SOURCE,
                     HUD_BEFORE_INVENTORY:HUD_BEFORE_SOURCE,HUD_AFTER_INVENTORY:HUD_AFTER_SOURCE,
                     HUD_ADVANCED_INVENTORY:HUD_ADVANCED_SOURCE}
LIVE_SOURCES = {HUD_BEFORE_INVENTORY:HUD_BEFORE_SOURCE,HUD_AFTER_INVENTORY:HUD_AFTER_SOURCE,
                HUD_ADVANCED_INVENTORY:HUD_ADVANCED_SOURCE}
NAV_TARGETS = {HUD_BEFORE_INVENTORY:HUD_AFTER_INVENTORY}
PREPARED_SOURCES = {key:value for key,value in LIVE_SOURCES.items() if key not in NAV_TARGETS}
OLD_RUNTIME = 'policy-bc51ded70f74442617201c92c641a9663ee5eab6488ca8a8f4ecb2083f337ab0'
OLD_PINS = {'desktop_dashboard_server_preflight.py':'4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'diagnose_desktop_dashboard_current_source.py':'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
    'go_erp_current_candidate_ci.yml':'af4d9bb97572c84e26cb6750d380adde2f7e9423',
    'go_erp_live_source.py':'fe269b99334a35516f38a42a18403978dcba54ad',
    'go_erp_live_updates.py':'de36d356328b34c022ce74a319c342b0bbe4004b',
    'publish_go_erp_update.py':'f9a7353b0705383844a576ab6d0f5b1662912bfb',
    'verify_go_erp_current_candidate_ci.py':'fb2c6ee364fae14062148846b654df8c96bba0de'}
PRIOR_PIN = '602ba86d2fd59221e046b58fa9c197fa4544973e'
NAV = 'public/dashboard/js/dashboard-navigation.js'
BEFORE_NAV = '611d6b79a243a4fdce16be1fca8a2343ffdd70c1'
AFTER_NAV = 'ba9dc90619bfe58479a70d8a1f618393c7261b71'
PREFIX = 'fullscreen-rollout-'
REV = re.compile(r'[a-f0-9]{40}\Z')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop(reason)


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


def canonical(value):
    return (json.dumps(value,sort_keys=True)+'\n').encode()


def load_pinned(path, pin):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,stat.S_IMODE(before.st_mode))==(0,0,1,0o644)
             and 0<before.st_size<2*1024**2,'MODULE_UNSAFE')
        data=os.read(fd,2*1024**2+1)
        key=lambda x:(x.st_dev,x.st_ino,x.st_mode,x.st_uid,x.st_gid,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
        need(len(data)==before.st_size and blob(data)==pin and key(before)==key(os.fstat(fd))==key(path.lstat()),'MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module=types.ModuleType('fullscreen_'+path.stem);module.__file__=str(path)
    sys.modules[module.__name__]=module
    exec(compile(data,str(path),'exec'),module.__dict__)
    return module


def adapt_payloads(old, new_seed=NEW_SEED):
    need(isinstance(old,dict) and set(old)==set(OLD_PINS) and isinstance(new_seed,str)
         and REV.fullmatch(new_seed) and new_seed not in (OLD_SEED,'0'*40),'PAYLOAD_ROSTER_INVALID')
    result={}
    for name,data in old.items():
        need(isinstance(data,bytes) and blob(data)==OLD_PINS[name],'OLD_PAYLOAD_PIN_INVALID')
        if name in ('go_erp_live_source.py','go_erp_live_updates.py'):
            for before,after in ((OLD_SEED.encode(),new_seed.encode()),
                (b'-native-program-center-seed-v1',b'-native-fullscreen-f12-seed-v1')):
                need(data.count(before)==1,'PAYLOAD_CONTEXT_INVALID')
                data=data.replace(before,after)
        result[name]=data
    return result


def migrated_state(value, old_seed=OLD_SEED, new_seed=NEW_SEED, publication=None, reviewed=None):
    need(old_seed==OLD_SEED and new_seed==NEW_SEED and isinstance(value,dict)
         and value.get('format')==1 and type(value.get('format')) is int and value.get('base_revision')==OLD_SEED,
         'STATE_IDENTITY_INVALID')
    desired=value.get('desired')
    need(isinstance(desired,dict),'HELD_CANDIDATE_UNREVIEWED')
    cohorts={OLD_CANDIDATE:(36,OLD_INVENTORY),ADVANCED_CANDIDATE:(37,ADVANCED_INVENTORY)}
    cohort=cohorts.get(desired.get('commit'))
    if reviewed is not None:
        need(isinstance(reviewed,dict) and reviewed.get('revision')==desired.get('commit')
             and reviewed.get('base_revision')==OLD_SEED and reviewed.get('version')==desired.get('version')
             and reviewed.get('inventory_sha256')==desired.get('inventorySha256')
             and reviewed.get('source')==CANDIDATE_SOURCES.get(reviewed.get('inventory_sha256'))
             and reviewed.get('source') is not None and reviewed.get('application_changed') is False
             and reviewed.get('database_read') is False,'CANDIDATE_PROOF_UNREVIEWED')
        match=re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)',reviewed.get('version',''))
        need(match is not None and 36<=int(match[1])<=9007199254740991,'CANDIDATE_PROOF_UNREVIEWED')
        cohort=(int(match[1]),reviewed['inventory_sha256'])
    need(cohort is not None,'HELD_CANDIDATE_UNREVIEWED')
    number,inventory=cohort
    floor=value.get('version_floor')
    floor_match=re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)',floor) if isinstance(floor,str) else None
    need(type(value.get('counter')) is int and value['counter']==number
         and (floor is None or floor_match is not None and 35<=int(floor_match[1])<=number)
         and type(value.get('sequence_floor',0)) is int and 0<=value.get('sequence_floor',0)<=9007199254740991,
         'HIGH_WATER_UNREVIEWED')
    need(desired.get('version')=='0.4.4-preview.'+str(number) and desired.get('inventorySha256')==inventory
         and desired.get('pushed') is True and desired.get('status') in ('waiting-ci','retry-build','blocked')
         and value.get('last_remote_sha')==desired['commit'] and value.get('published') is None,
         'HELD_CANDIDATE_UNREVIEWED')
    observed=value.get('observed')
    need(observed is None or isinstance(observed,dict) and observed.get('seedRevision')==OLD_SEED
         and observed.get('inventorySha256')==inventory,'OBSERVED_UNREVIEWED')
    need(isinstance(value.get('published_history',[]),list),'HISTORY_INVALID')
    if publication is not None:
        need(isinstance(publication,dict) and re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)',publication.get('version',''))
             and int(publication['version'].rsplit('.',1)[1])<=35
             and type(publication.get('sequence')) is int and 0<publication['sequence']<=9007199254740991,
             'PUBLICATION_UNREVIEWED')
    result=copy.deepcopy(value)
    result.update(base_revision=NEW_SEED,observed=None,desired=None,published=None,
        counter=number,version_floor='0.4.4-preview.'+str(number),
        sequence_floor=max(value.get('sequence_floor',0),publication['sequence'] if publication else 0))
    return result


def hold_value(core, source, inventory):
    return {'format':1,'kind':'go-erp-program-rollout-hold','core':core,'source':source,
            'inventory_sha256':inventory,'authorization':None}


def old_hold_allowed(value, raw):
    if value in [hold_value(OLD_CORE,source,inventory) for inventory,source in CANDIDATE_SOURCES.items()]:
        return True
    # This observed, unauthorised hold is only a superseded control record.
    # Its inventory grants no authority to a candidate or the live source scan.
    return (value==hold_value(OLD_CORE,ADVANCED_SOURCE,OBSERVED_OLD_HOLD_INVENTORY)
        and hashlib.sha256(raw).hexdigest()==OBSERVED_OLD_HOLD_SHA256)


class Context:
    def __init__(self,package):
        self.package=package
        self.prior=p=load_pinned(package/'upgrade_go_erp_program_watcher_root.py',PRIOR_PIN)
        self.base=b=p.load_pinned(package/p.BASE_MODULE,p.BASE_BLOB)
        self.utility=u=b.load_utility(package/'upgrade_go_erp_capacity_watcher_root.py')
        self.application=p.load_pinned(package/p.APPLICATION_MODULE,p.APPLICATION_BLOB)
        self.owner=pwd.getpwnam('fasakha')
        old=u.Directory(b.RELEASES/OLD_RUNTIME,0o755)
        try:
            u.validate_runtime(old,OLD_PINS)
            self.old_payloads={name:u.read_at(old,name,u.MAX_FILE,0o644) for name in OLD_PINS}
            self.source=u.module_at(old,'go_erp_live_source.py',OLD_PINS['go_erp_live_source.py'])
            self.worker=u.module_at(old,'go_erp_live_updates.py',OLD_PINS['go_erp_live_updates.py'])
            self.candidate_verifier=u.module_at(old,'verify_go_erp_current_candidate_ci.py',OLD_PINS['verify_go_erp_current_candidate_ci.py'])
        finally:
            old.close()
        self.payloads=adapt_payloads(self.old_payloads)
        self.pins={name:blob(data) for name,data in self.payloads.items()}
        b.OLD_SEED,b.OLD_RUNTIME,b.OLD_PINS,b.OLD_ROSTER=OLD_SEED,OLD_RUNTIME,OLD_PINS,tuple(sorted(OLD_PINS))
        b.ROSTER=tuple(sorted(OLD_PINS));b.PREFIX='fullscreen-seed-upgrade-'
        b.EXPECTED_SOURCE,b.EXPECTED_INVENTORY=AFTER_SOURCE,AFTER_INVENTORY
        self.candidate_proof=None
        b.migrated_state=lambda value,old_seed,new_seed,publication=None: migrated_state(value,old_seed,new_seed,publication,self.candidate_proof)
        self.expected_old=hold_value(OLD_CORE,OLD_SOURCE,OLD_INVENTORY)
        self.expected_new=hold_value(CORE,AFTER_SOURCE,AFTER_INVENTORY)
        self.remote_revision=OLD_CANDIDATE

    def slots(self):
        b,u=self.base,self.utility
        result={}
        for key,parent,name,limit,mode in [('config',b.CONFIG.parent,b.CONFIG.name,16384,0o600),
            ('state',WORK/'state','current.json',b.MAX_STATE,0o600),('unit',b.UNIT.parent,b.UNIT.name,65536,0o644)]:
            directory=u.Directory(parent,0o755 if key=='unit' else 0o700)
            try:
                result[key]=u.read_at(directory,name,limit,mode)
            finally:
                directory.close()
        config=b.parse(result['config']);state=b.parse(result['state'])
        seed=config.get('base_revision')
        need(seed in (OLD_SEED,NEW_SEED),'CONFIGURATION_SEED_UNREVIEWED')
        b.checked_config(config,seed);b.checked_state(state,seed);b.checkout(WORK/'checkout',seed,u)
        runtime=b.RELEASES/(OLD_RUNTIME if seed==OLD_SEED else u.runtime_name(self.payloads))
        need(result['unit']==b.unit_bytes(u,runtime,ROOT,WORK,b.CONFIG),'UNIT_UNREVIEWED')
        u.effective_unit(b.UNIT,b.CONFIG,(runtime,))
        return result,config,state

    def snapshot(self, inventory, source, seed=OLD_SEED):
        value=self.source.scan_snapshot(ROOT,WORK/'checkout',self.owner.pw_uid,None)
        need(value['metadata'].get('seedRevision')==seed and value['metadata'].get('inventorySha256')==inventory
             and self.base.normalized_source(value['_files'])==source,'APPLICATION_COHORT_UNREVIEWED')
        return value

    def remote(self,checkout):
        branch='refs/heads/codex/go-erp-live-source-20261021'
        need(self.base.git(checkout,'ls-remote','--exit-code',self.base.REMOTE,branch,maximum=4096)
             ==(self.remote_revision+'\t'+branch+'\n').encode(),'REMOTE_BRANCH_UNREVIEWED')

    def bind_candidate(self,state):
        """Accept a newer counter only after the original content verifier proves the entire candidate."""
        b,u=self.base,self.utility
        desired=state.get('desired')
        need(isinstance(desired,dict) and isinstance(desired.get('commit'),str)
             and REV.fullmatch(desired['commit']) and desired.get('pushed') is True
             and desired.get('status') in ('waiting-ci','retry-build','blocked')
             and desired['commit']==state.get('last_remote_sha'),'HELD_CANDIDATE_UNREVIEWED')
        self.remote_revision=desired['commit'];self.remote(WORK/'checkout')
        b.git(WORK/'checkout','fetch','--quiet','--depth=2','--no-tags','--no-recurse-submodules',b.REMOTE,self.remote_revision)
        header=b.git(WORK/'checkout','cat-file','commit',self.remote_revision).split(b'\n\n',1)[0]
        need([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')]==[OLD_SEED],
             'CANDIDATE_PARENT_UNREVIEWED')
        candidate=b.parse(b.git(WORK/'checkout','show',self.remote_revision+':deployment/go_erp_live_source_candidate.json'))
        rows=candidate.get('inventory');number=candidate.get('versionSequence')
        need(isinstance(rows,dict) and type(number) is int and 36<=number<=9007199254740991
             and candidate==self.prior.candidate_metadata(OLD_SEED,rows,number)
             and candidate.get('inventorySha256')==desired.get('inventorySha256') in CANDIDATE_SOURCES,
             'CANDIDATE_METADATA_UNREVIEWED')
        changed=set(b.git(WORK/'checkout','diff','--name-only','--no-renames','-z',OLD_SEED,self.remote_revision,'--').decode().split('\0'))-{''}
        versions={'desktop-pos/package.json','desktop-pos/package-lock.json'}
        need(changed.issubset(set(rows)|versions|{'deployment/go_erp_live_source_candidate.json'}),'CANDIDATE_NATIVE_OR_TEST_DELTA')
        for name in versions:
            expected=b.parse(b.git(WORK/'checkout','show',OLD_SEED+':'+name))
            actual=b.parse(b.git(WORK/'checkout','show',self.remote_revision+':'+name))
            expected['version']=candidate['version']
            if name.endswith('package-lock.json'):expected['packages']['']['version']=candidate['version']
            need(actual==expected,'CANDIDATE_DEPENDENCY_DELTA')
        temporary=Path(tempfile.mkdtemp(prefix='fullscreen-candidate-check-',dir=WORK))
        target=temporary/'candidate';added=False
        try:
            b.git(WORK/'checkout','worktree','add','--quiet','--detach',str(target),self.remote_revision);added=True
            proof=self.candidate_verifier.validate_checkout(target,WORK/'checkout',expected_base=OLD_SEED)
        finally:
            if added:b.git(WORK/'checkout','worktree','remove','--force',str(target))
            os.rmdir(temporary)
        migrated_state(state,OLD_SEED,NEW_SEED,None,proof)
        b.checkout(WORK/'checkout',OLD_SEED,u);self.remote(WORK/'checkout')
        self.candidate_proof=proof
        return proof

    def confirm_old(self, before):
        current,config,state=self.slots()
        need(current==before and config['base_revision']==OLD_SEED,'WATCHER_ROLLBACK_REQUIRES_REVIEW')


def navigation_patch(ctx,audit,lock_fd):
    """Use reviewed inode-bound exchanges for one JS file; legacy manifests are outside this patch."""
    a=ctx.application
    before_source,after_source=ctx.navigation_sources
    a.configure(before_source,after_source,{NAV:(BEFORE_NAV,AFTER_NAV)})
    class NavigationPatch(a.ApplicationPatch):
        def prepare(self):
            need(not self.prepared,'NAV_ALREADY_PREPARED');self._check_lock()
            self.scanner=a.pinned_scanner(self.package)
            self.before_map=self.scanner.scan(self.root)
            need(self.scanner.digest(self.before_map)==before_source,'NAV_BEFORE_COHORT_UNREVIEWED')
            data,info=self._read(NAV)
            need(data is not None and blob(data)==BEFORE_NAV and info is not None,'NAV_BEFORE_PIN_INVALID')
            with a.PinnedSourceReader(self.package/'application',0) as reader:
                wanted,_=reader._read(NAV)
            need(wanted is not None and blob(wanted)==AFTER_NAV,'NAV_AFTER_PIN_INVALID')
            self.after_map=copy.deepcopy(self.before_map)
            self.after_map[NAV]={'blob':AFTER_NAV,'sha256':hashlib.sha256(wanted.replace(b'\r\n',b'\n')).hexdigest()}
            need(self.scanner.digest(self.after_map)==after_source,'NAV_AFTER_COHORT_UNREVIEWED')
            self.stage=Path(tempfile.mkdtemp(prefix='.go-erp-fullscreen-nav-',dir=self.root.parent));os.chmod(self.stage,0o700)
            self.stage_fd=os.open(self.stage,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
            self._write(self.audit,'0.before',data)
            self._write(self.stage,'0',wanted,stat.S_IMODE(info.st_mode),info.st_uid,info.st_gid)
            staged=(self.stage/'0').lstat()
            need(staged.st_dev==os.fstat(self._parent(NAV)).st_dev,'NAV_FILESYSTEM_DIFFERS')
            self.slots=[{'name':NAV,'slot':'0','before':data,'before_info':info,'wanted':wanted,
                         'published':a.identity(staged),'moved':False}]
            # No legacy deployment manifest is read, rewritten, or represented as current.
            self.manifest_present=True
            self._audit('application-intent.json',{'before_source':before_source,'after_source':after_source,
                'path':NAV,'before_blob':BEFORE_NAV,'after_blob':AFTER_NAV,'before_identity':a.identity(info),
                'stage':str(self.stage),'legacy_manifest_managed':False})
            self.prepared=True
        def _verify_manifest_absence(self):
            pass
        def commit(self):
            self.verify()
            self._audit('application-committed.json',{'source':after_source,'database_changed':False,'legacy_manifest_managed':False})
            self.committed=True;self._cleanup()
    path=audit/'application';os.mkdir(path,0o700)
    return NavigationPatch(ROOT,ctx.package,path,ctx.owner.pw_uid,ctx.owner.pw_gid,lock_fd)


def apply(ctx):
    p,b,u=ctx.prior,ctx.base,ctx.utility
    with p.held_lock(u,WORK) as (lock_fd,verify):
        p.assert_quiescent();p.require_no_pending(b,u,WORK)
        for path in WORK.iterdir():
            if re.fullmatch(PREFIX+r'[a-f0-9]{32}',path.name):
                u.Directory(path,0o700).close()
                need(not (path/'intent.json').exists() or (path/'completed.json').exists() or (path/'rolled-back.json').exists(),
                     'PREVIOUS_FULLSCREEN_TRANSACTION_REQUIRES_REVIEW')
        before,config,state=ctx.slots()
        gate=ctx.worker
        current_hold_bytes=gate.program_gate_bytes(WORK/'program-rollout-hold.json')
        current_hold=gate.program_gate_parse(current_hold_bytes)
        if config['base_revision']==NEW_SEED:
            allowed=[hold_value(CORE,source,inventory) for inventory,source in PREPARED_SOURCES.items()]
            need(current_hold in allowed,'NEW_HOLD_UNREVIEWED')
            ctx.expected_new=current_hold
            target_source=current_hold['source'];target_inventory=current_hold['inventory_sha256']
            b.EXPECTED_SOURCE,b.EXPECTED_INVENTORY=target_source,target_inventory
            ctx.snapshot(target_inventory,target_source,NEW_SEED)
            result=b.upgrade(ctx.payloads,pins=ctx.pins,utility=p.LockedUtility(u,WORK,verify),
                new_seed=NEW_SEED,core=CORE,old_pins=OLD_PINS,remote=ctx.remote)
            need(result.get('ok') is True,'CURRENT_NATIVE_SEED_REQUIRES_REVIEW')
            result.update(kind='go-erp-fullscreen-prepared',worker_request='candidate',publication_held=True,published=False,
                timer_paused=True,application_contents_changed=False,database_changed=False,native_seed=NEW_SEED,native_core=CORE)
            return result
        proof=ctx.bind_candidate(state)
        migrated_state(state,OLD_SEED,NEW_SEED,None,proof)
        need(old_hold_allowed(current_hold,current_hold_bytes),'OLD_HOLD_DIFFERS_OR_AUTHORIZED')
        ctx.expected_old=current_hold
        need(not (WORK/('program-rollout-completed-'+OLD_CORE+'.json')).exists(),'OLD_ROLLOUT_ALREADY_COMPLETED')
        ctx.remote(WORK/'checkout')
        captured=ctx.source.scan_snapshot(ROOT,WORK/'checkout',ctx.owner.pw_uid,None)
        actual=(captured['metadata']['inventorySha256'],b.normalized_source(captured['_files']))
        need(actual[0] in LIVE_SOURCES and actual[1]==LIVE_SOURCES[actual[0]],'APPLICATION_COHORT_UNREVIEWED')
        target_inventory=NAV_TARGETS.get(actual[0],actual[0])
        target_source=PREPARED_SOURCES[target_inventory]
        ctx.navigation_sources=(actual[1],target_source)
        b.EXPECTED_SOURCE,b.EXPECTED_INVENTORY=target_source,target_inventory
        ctx.expected_new=hold_value(CORE,target_source,target_inventory)
        audit_path=WORK/(PREFIX+uuid.uuid4().hex);os.mkdir(audit_path,0o700)
        audit=u.Directory(audit_path,0o700);work=u.Directory(WORK,0o700)
        patch=None;hold_switched=False;watcher_result=None;old_hold_bytes=gate.program_gate_bytes(WORK/'program-rollout-hold.json')
        old_hold_key=u.exact_key((WORK/'program-rollout-hold.json').lstat())
        need(gate.program_gate_parse(old_hold_bytes)==ctx.expected_old
             and old_hold_allowed(ctx.expected_old,old_hold_bytes)
             and gate.program_gate_bytes(WORK/'program-rollout-hold.json')==old_hold_bytes
             and u.exact_key((WORK/'program-rollout-hold.json').lstat())==old_hold_key,'OLD_HOLD_CHANGED')
        live_hold_key=held_hold_key=None
        try:
            u.write_at(audit,'before-hold.json',old_hold_bytes)
            u.write_at(audit,'exchange-hold.json',canonical(ctx.expected_new))
            u.json_at(audit,'intent.json',{'format':1,'old_seed':OLD_SEED,'new_seed':NEW_SEED,'native_core':CORE,
                'old_hold':ctx.expected_old,'new_hold':ctx.expected_new,'controls_sha256':{key:hashlib.sha256(value).hexdigest() for key,value in before.items()},
                'old_hold_sha256':hashlib.sha256(old_hold_bytes).hexdigest(),
                'old_candidate':state['desired']['commit'],'navigation_already_applied':actual[0] not in NAV_TARGETS})
            if actual[0] in NAV_TARGETS:
                patch=navigation_patch(ctx,audit_path,lock_fd);patch.prepare();verify();patch.apply();patch.verify()
            ctx.snapshot(target_inventory,target_source)
            verify();ctx.confirm_old(before)
            need(gate.program_gate_bytes(WORK/'program-rollout-hold.json')==old_hold_bytes
                 and gate.program_gate_parse(old_hold_bytes)==ctx.expected_old
                 and old_hold_allowed(ctx.expected_old,old_hold_bytes)
                 and u.exact_key((WORK/'program-rollout-hold.json').lstat())==old_hold_key,'OLD_HOLD_CHANGED')
            u.exchange(audit.fd,'exchange-hold.json',work.fd,'program-rollout-hold.json');hold_switched=True
            os.fsync(audit.fd);os.fsync(work.fd)
            live_hold_key=u.exact_key((WORK/'program-rollout-hold.json').lstat())
            held_hold_key=u.exact_key((audit_path/'exchange-hold.json').lstat())
            need(gate.program_gate_json(WORK/'program-rollout-hold.json')==ctx.expected_new
                 and gate.program_gate_bytes(audit_path/'exchange-hold.json')==old_hold_bytes,'HOLD_EXCHANGE_INVALID')
            watcher_result=b.upgrade(ctx.payloads,pins=ctx.pins,utility=p.LockedUtility(u,WORK,verify),
                new_seed=NEW_SEED,core=CORE,old_pins=OLD_PINS,remote=ctx.remote)
            if watcher_result.get('ok') is not True:
                need(watcher_result.get('rollback_complete') is True,'WATCHER_RECOVERY_REQUIRED')
                raise Stop('WATCHER_UPGRADE_ROLLED_BACK')
            verify();ctx.snapshot(target_inventory,target_source,NEW_SEED)
            need(gate.program_gate_json(WORK/'program-rollout-hold.json')==ctx.expected_new
                 and u.exact_key((WORK/'program-rollout-hold.json').lstat())==live_hold_key,'NEW_HOLD_CHANGED')
            if patch is not None:patch.verify();patch.commit()
            result={**watcher_result,'kind':'go-erp-fullscreen-prepared','worker_request':'candidate','publication_held':True,
                'published':False,'timer_paused':True,'application_contents_changed':patch is not None,'database_changed':False,
                'native_seed':NEW_SEED,'native_core':CORE,'next_version':'0.4.4-preview.'+str(watcher_result['counter']+1),'backup':str(audit_path)}
            u.json_at(audit,'completed.json',result)
            return result
        except BaseException:
            # Restore application/hold only after independently recognizing all four old watcher slots.
            restored=False
            if watcher_result is None or watcher_result.get('ok') is not True and watcher_result.get('rollback_complete') is True:
                try:
                    ctx.confirm_old(before)
                    if patch is not None:patch.rollback()
                    if hold_switched:
                        need(gate.program_gate_json(WORK/'program-rollout-hold.json')==ctx.expected_new
                             and u.exact_key((WORK/'program-rollout-hold.json').lstat())==live_hold_key
                             and gate.program_gate_bytes(audit_path/'exchange-hold.json')==old_hold_bytes
                             and u.exact_key((audit_path/'exchange-hold.json').lstat())==held_hold_key,'HOLD_ROLLBACK_REQUIRES_REVIEW')
                        u.exchange(audit.fd,'exchange-hold.json',work.fd,'program-rollout-hold.json')
                        os.fsync(audit.fd);os.fsync(work.fd)
                    need(gate.program_gate_json(WORK/'program-rollout-hold.json')==ctx.expected_old,'OLD_HOLD_RESTORE_FAILED')
                    u.json_at(audit,'rolled-back.json',{'restored':True,'database_changed':False});restored=True
                except BaseException:
                    pass
            u.json_at(audit,'failed.json',{'restored':restored,'published':False,'timer_paused':True,'database_changed':False})
            raise Stop('ROLLED_BACK_TIMER_PAUSED' if restored else 'RECOVERY_REQUIRED_TIMER_PAUSED') from None
        finally:
            if patch is not None:patch.close()
            audit.close();work.close()


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package',required=True);parser.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    need(args.apply and os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_AND_APPLY_REQUIRED')
    package=Path(args.package).absolute()
    need(package.resolve()==package,'PACKAGE_UNSAFE');os.umask(0o077)
    result=apply(Context(package))
    print(json.dumps(result,sort_keys=True))


if __name__=='__main__':
    try:
        main()
    except BaseException as error:
        code=str(error) if isinstance(error,Stop) and re.fullmatch('[A-Z0-9_]{1,100}',str(error)) else 'PRIVATE_FAILURE'
        print(json.dumps({'ok':False,'code':'GO_ERP_FULLSCREEN_'+code,'published':False,'timer_paused':True,'database_changed':False},sort_keys=True))
        raise SystemExit(1)
