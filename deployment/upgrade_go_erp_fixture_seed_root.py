#!/usr/bin/env python3
"""Bounded successor seed for the failed Preview37 Windows temp-path fixture.

Only the private watcher unit, clean seed checkout, state, rollout hold and
configuration are exchanged. Application code, modes, SQL and prior audits are
observations, never mutation targets. Release pins are filled after the separate
Windows alias proof and immutable CORE/SEED review.
"""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
RELEASES = Path('/opt/go-erp-live-updates/releases')
CONFIG = Path('/etc/go-erp/live-updates.json')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
MODE_MODULE = 'upgrade_go_erp_mode_inventory_root.py'
MODE_PIN = '1e2ae5f131032bd81bac11052442434bd231061b'
OLD_CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
OLD_SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
OLD_CANDIDATE = '5f2b0fecafec1c3803499284b0abcca4ecfed7e4'
CORE = '4e0c33197b6d9cd635e3396e1edbf567bfb3a020'
SEED = 'e64e69667198b7dceddd4dfe516d963f43bbae27'
SOURCE = {'format':1,'files':1346,'framework':'8.83.29',
          'sha256':'41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63'}
INVENTORY = 'de5ac407604249df2198aa71c2d7b4ca0a88ac16bba3cf3f028c61e3077111d8'
VERSION = '0.4.4-preview.38'
PREFIX = 'fixture-seed-upgrade-'
SLOTS = ('unit','checkout','state','hold','config')
MAX_STATE = 16*1024*1024
_MODE = None


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_FIXTURE_SEED_'+reason)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return (json.dumps(value,sort_keys=True)+'\n').encode()


def review(reason):
    error=Stop('GO_ERP_FIXTURE_SEED_'+reason)
    error.rollback_complete=False
    raise error


def load_mode(package):
    global _MODE
    path=package/MODE_MODULE
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd)
        key=lambda value:(value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
                          value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,
             stat.S_IMODE(before.st_mode))==(0,0,1,0o644) and 0<before.st_size<=2*1024*1024,'MODE_MODULE_UNSAFE')
        data=os.read(fd,2*1024*1024+1)
        blob=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        need(blob==MODE_PIN and key(before)==key(os.fstat(fd))==key(path.lstat()),'MODE_MODULE_PIN_INVALID')
    finally:os.close(fd)
    module=types.ModuleType('reviewed_fixture_mode_prior');module.__file__=str(path)
    sys.modules[module.__name__]=module
    exec(compile(data,str(path),'exec'),module.__dict__)
    _MODE=module
    return module


def next_state(base, state):
    base.checked_state(state,OLD_SEED)
    desired=state.get('desired')
    need(state.get('counter')==37 and isinstance(desired,dict)
         and desired.get('commit')==OLD_CANDIDATE and desired.get('version')=='0.4.4-preview.37'
         and desired.get('inventorySha256')==INVENTORY and desired.get('pushed') is True
         and desired.get('status') in ('waiting-ci','retry-build','blocked')
         and state.get('last_remote_sha')==OLD_CANDIDATE and state.get('published') is None,'OLD_STATE_INVALID')
    result=copy.deepcopy(state)
    result.update(base_revision=SEED,observed=None,desired=None)
    # Counter, last remote lease, floors and all history are preserved exactly.
    need(set(result)==set(state) and all(result[name]==state[name]
         for name in state if name not in ('base_revision','observed','desired')),'STATE_TRANSFORM_INVALID')
    return result


class Context:
    def __init__(self, package):
        need(isinstance(CORE,str) and isinstance(SEED,str) and re.fullmatch(r'[a-f0-9]{40}',CORE)
             and re.fullmatch(r'[a-f0-9]{40}',SEED) and SEED!=OLD_SEED,'RELEASE_PINS_PENDING')
        self.package=package
        self.mode=load_mode(package)
        need((self.mode.CORE,self.mode.SEED,self.mode.CANDIDATE,self.mode.SOURCE,self.mode.TARGET_INVENTORY)==
             (OLD_CORE,OLD_SEED,OLD_CANDIDATE,SOURCE,INVENTORY),'MODE_IDENTITY_INVALID')
        self.old=self.mode.Context(package)
        for name in ('base','prior','utility','application','gate','publisher','owner'):
            setattr(self,name,getattr(self.old,name))
        self.expected=self.gate.program_gate_expected(CORE,SOURCE,INVENTORY)
        old_runtime=self.utility.Directory(self.old.runtime,0o755)
        try:
            self.utility.validate_runtime(old_runtime,self.old.pins)
            self.payloads={name:self.utility.read_at(old_runtime,name,self.utility.MAX_FILE,0o644)
                           for name in self.old.pins}
        finally:old_runtime.close()
        # Preserve all installed policy and embedded CI gates. Only the exact
        # trusted seed constant changes, once in each of these two modules.
        for name in ('go_erp_live_source.py','go_erp_live_updates.py'):
            before=b"PREVIEW31_SEED = '"+OLD_SEED.encode()+b"'"
            after=b"PREVIEW31_SEED = '"+SEED.encode()+b"'"
            need(self.payloads[name].count(before)==1,'RUNTIME_PATCH_CONTEXT_INVALID')
            self.payloads[name]=self.payloads[name].replace(before,after)
        self.pins={name:self.prior.blob(value) for name,value in self.payloads.items()}
        self.runtime=RELEASES/self.utility.runtime_name(self.payloads)

    def remote(self):
        return self.old.original.remote()

    def publication(self):
        return self.old.publication()

    def protected(self):
        protected=[]
        original=self.old.original
        with self.application.PinnedSourceReader(ROOT,self.owner.pw_uid) as reader:
            for name,wanted in original.prior.TARGET_PINS.items():
                data,_=reader._read(name)
                need(data is not None and self.prior.blob(data)==self.old.old.PREPARED_OVERRIDES.get(name,wanted),
                     'PREPARED_SOURCE_CHANGED')
            for name,(_,wanted) in self.mode.APPROVED_PINS.items():
                data,info=reader._read(name)
                need(data is not None and self.prior.blob(data)==wanted,'PAYMENT_SOURCE_CHANGED')
                protected.append({'path':name,'blob':wanted,'identity':list(self.utility.exact_key(info))})
        return protected

    def fresh(self, runtime, pins, checkout, seed, state):
        directory=self.utility.Directory(runtime,0o755)
        try:
            self.utility.validate_runtime(directory,pins)
            source=self.utility.module_at(directory,'go_erp_live_source.py',pins['go_erp_live_source.py'])
            worker=self.utility.module_at(directory,'go_erp_live_updates.py',pins['go_erp_live_updates.py'])
            need(source.PREVIEW31_SEED==worker.PREVIEW31_SEED==seed,'RUNTIME_SEED_INVALID')
            policy=worker.Worker.candidate_policy(types.SimpleNamespace(config={'base_revision':seed},source=source))
            need(policy==source.PREVIEW31_POLICY==worker.PREVIEW31_POLICY,'RUNTIME_POLICY_INVALID')
            need(state.get('dependency_guard') in (None,worker.dependency_snapshot(ROOT,self.owner.pw_uid)),
                 'DEPENDENCIES_CHANGED')
            try:snapshot=source.scan_snapshot(ROOT,checkout,self.owner.pw_uid,None)
            except BaseException as error:
                if type(error) is getattr(source,'SourceError',None):need(False,'LIVE_SNAPSHOT_REJECTED')
                raise
            rows=snapshot['metadata']['inventory']
            need(snapshot['metadata']==source._metadata(seed,rows)
                 and snapshot['metadata']['inventorySha256']==INVENTORY,'LIVE_INVENTORY_CHANGED')
            scanner=self.application.pinned_scanner(self.package)
            need(scanner.digest(scanner.scan(ROOT))==SOURCE,'LIVE_SOURCE_CHANGED')
            self.utility.validate_runtime(directory,pins)
            return snapshot['metadata']
        finally:directory.close()

    def capture_old(self, lock_fd):
        self.prior.assert_quiescent()
        controls,state=self.old.controls()
        next_state(self.base,state)
        need(self.remote()==OLD_CANDIDATE and self.publication() is None,'OLD_PUBLICATION_OR_LEASE_CHANGED')
        self.old.candidate(OLD_CANDIDATE,INVENTORY,37)
        # A recognized rollback returns the same original inodes but rename
        # legitimately advances ctime. Preserve the historical audit authority
        # by reusing only its sealed pre-exchange observations, after proving
        # every other identity field and every retained slot still matches.
        sealed=controls
        for path in WORK.iterdir():
            if re.fullmatch(PREFIX+r'[a-f0-9]{32}',path.name) and (path/'rolled-back.json').exists() \
                    and (path/'intent.json').exists():
                intent=validate_intent(self,path)
                observation=intent['observation']
                if observation['state']==state and controls_equal(controls,observation['controls'],True):
                    self.check_rolled_back_slots(path,intent)
                    sealed={name:canonical(value) for name,value in observation['controls'].items()}
                    break
        evidence=self.old.prepared_evidence(sealed,lock_fd)
        self.old.original.prior.require_no_pending(self.base,self.utility,WORK)
        current=self.gate.program_gate_json(WORK/'program-rollout-hold.json')
        if current==self.mode.hold(self.old.prepared_expected):
            mode_proof=None
            # Never make an unfinished mode transaction invisible.
            for path in WORK.iterdir():
                if re.fullmatch(self.mode.PREFIX+r'[a-f0-9]{32}',path.name) and (path/'intent.json').exists() \
                        and not (path/'rolled-back.json').exists():
                    need(False,'MODE_TRANSACTION_REQUIRES_REVIEW')
        else:
            need(current==self.mode.hold(self.old.expected),'OLD_HOLD_DIFFERS_OR_AUTHORIZED')
            path=self.mode.completed_audit(self.old,sealed,evidence)
            mode_proof={name:{'sha256':sha(self.gate.program_gate_bytes(path/name)),
                             'identity':list(self.utility.exact_key((path/name).lstat()))}
                        for name in ('intent.json','completed.json','before-hold.json','after-hold.json')}
            mode_proof={'path':path.name,'files':mode_proof}
        snapshot=self.fresh(self.old.runtime,self.old.pins,WORK/'checkout',OLD_SEED,state)
        return {'state':state,'controls':{name:json.loads(value) for name,value in sealed.items()},
                'evidence':evidence,'mode_proof':mode_proof,'payment':self.protected(),
                'hold':current,'snapshot':snapshot}

    def check_before(self, observation, lock_fd):
        need(self.capture_old(lock_fd)==observation,'OLD_OBSERVATIONS_CHANGED')

    def check_rollback(self, observation, lock_fd, intent, path):
        controls,state=self.old.controls()
        need(state==observation['state'] and controls_equal(controls,observation['controls'],True),'ROLLBACK_CONTROLS_CHANGED')
        self.check_rolled_back_slots(path,intent)
        self.check_protected(observation)
        self.old.prepared_evidence({name:canonical(value) for name,value in observation['controls'].items()},lock_fd)
        need(self.gate.program_gate_json(WORK/'program-rollout-hold.json')==observation['hold']
             and self.remote()==OLD_CANDIDATE and self.publication() is None,'ROLLBACK_HOLD_OR_LEASE_CHANGED')
        need(self.fresh(self.old.runtime,self.old.pins,WORK/'checkout',OLD_SEED,state)==observation['snapshot'],
             'ROLLBACK_SOURCE_CHANGED')

    def check_rolled_back_slots(self,path,intent):
        u,b=self.utility,self.base
        audit=u.Directory(path,0o700)
        handles=[audit]
        try:
            for slot,parent,name,mode in (('unit',UNIT.parent,UNIT.name,0o755),('checkout',WORK,'checkout',0o700),
                    ('state',WORK/'state','current.json',0o700),('hold',WORK,'program-rollout-hold.json',0o700),
                    ('config',CONFIG.parent,CONFIG.name,0o700)):
                directory=u.Directory(parent,mode);handles.append(directory)
                need(b.recognize(u,directory,name,intent['slots'][slot]['before'],slot=='checkout')
                     and b.recognize(u,audit,b.exchange_name(slot),intent['slots'][slot]['after'],slot=='checkout'),
                     'ROLLBACK_SLOT_CHANGED')
        finally:
            for directory in reversed(handles):directory.close()

    def prepare_target(self, audit, state):
        target=self.utility.install_runtime(RELEASES,self.payloads,self.pins)
        need(target==self.runtime,'RUNTIME_PATH_CHANGED')
        self.base.stage_seed(audit/'exchange-checkout',SEED,CORE,self.utility)
        proof=self.fresh(target,self.pins,audit/'exchange-checkout',SEED,state)
        return proof

    def check_protected(self, observation):
        need(self.protected()==observation['payment'],'PROTECTED_SOURCE_CHANGED')
        path=WORK/self.mode.PREPARED_AUDIT
        for name,value in observation['evidence'].items():
            need(sha(self.gate.program_gate_bytes(path/name))==value['sha256']
                 and list(self.utility.exact_key((path/name).lstat()))==value['identity'],'ORIGINAL_EVIDENCE_CHANGED')
        mode=observation['mode_proof']
        if mode:
            for name,value in mode['files'].items():
                item=WORK/mode['path']/name
                need(sha(self.gate.program_gate_bytes(item))==value['sha256']
                     and list(self.utility.exact_key(item.lstat()))==value['identity'],'MODE_EVIDENCE_CHANGED')

    def check_after(self, intent, lock_fd, state_exact=True):
        self.prior.assert_quiescent()
        self.check_protected(intent['observation'])
        self.base.checkout(WORK/'checkout',SEED,self.utility)
        config=self.gate.program_gate_json(CONFIG)
        self.base.checked_config(config,SEED)
        need(sha(self.gate.program_gate_bytes(CONFIG))==intent['slots']['config']['after']['sha256'],'CONFIG_CHANGED')
        state=self.gate.program_gate_json(WORK/'state/current.json')
        self.base.checked_state(state,SEED)
        if state_exact:need(state==next_state(self.base,intent['observation']['state']),'STATE_CHANGED')
        elif (state.get('desired') or {}).get('status')!='published':
            old=intent['observation']['state']
            mutable={'base_revision','observed','desired','counter','last_remote_sha'}
            need(set(old)==set(state) and all(state[name]==old[name] for name in old if name not in mutable),
                 'DURABLE_HISTORY_CHANGED')
        self.utility.effective_unit(UNIT,CONFIG,(self.runtime,))
        directory=self.utility.Directory(UNIT.parent,0o755)
        try:need(self.utility.read_at(directory,UNIT.name,65536,0o644)==
                 self.base.unit_bytes(self.utility,self.runtime,ROOT,WORK,CONFIG),'UNIT_CHANGED')
        finally:directory.close()
        need(self.fresh(self.runtime,self.pins,WORK/'checkout',SEED,state)==intent['new_snapshot'],'SOURCE_CHANGED')
        return state

    def candidate(self, revision):
        b,p=self.base,self.prior
        need(isinstance(revision,str) and re.fullmatch(r'[a-f0-9]{40}',revision),'CANDIDATE_INVALID')
        repository=self.package.parent/'release.git'
        header=b.git(repository,'cat-file','commit',revision).split(b'\n\n',1)[0]
        need([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')]==[SEED],
             'CANDIDATE_PARENT_INVALID')
        names=set(b.git(repository,'diff','--name-only','--no-renames','-z',SEED,revision,'--').decode().split('\0'))-{''}
        nav='public/dashboard/js/dashboard-navigation.js'
        need(names.issubset(set(self.mode.APPROVED_PINS)|{nav}|set(p.VERSIONS)),'CANDIDATE_CODE_INVALID')
        metadata=b.parse(b.git(repository,'show',revision+':'+p.VERSIONS[2]));rows=metadata.get('inventory')
        need(isinstance(rows,dict) and metadata==p.candidate_metadata(SEED,rows,38)
             and metadata.get('inventorySha256')==INVENTORY,'CANDIDATE_METADATA_INVALID')
        for name,wanted in {nav:'5817024fb309d86ccaae2c79ad21391aa8507ecb',
                           **{key:pair[1] for key,pair in self.mode.APPROVED_PINS.items()}}.items():
            need(b.git(repository,'ls-tree','-z',revision,'--',name).decode()==
                 rows[name]['mode']+' blob '+wanted+'\t'+name+'\0','CANDIDATE_APPROVED_FILE_INVALID')
        for path in p.VERSIONS:
            before=b.git(repository,'ls-tree','-z',SEED,'--',path).split(b' ',1)[0]
            after=b.git(repository,'ls-tree','-z',revision,'--',path).split(b' ',1)[0]
            need(before==after and before in (b'100644',b'100755'),'CANDIDATE_PACKAGE_INVALID')
        for path in p.VERSIONS[:2]:
            before=b.parse(b.git(repository,'show',SEED+':'+path));after=b.parse(b.git(repository,'show',revision+':'+path))
            before['version']=VERSION
            if path.endswith('package-lock.json'):before['packages']['']['version']=VERSION
            need(before==after,'CANDIDATE_PACKAGE_INVALID')
        return metadata


def completion(intent):
    return {'format':1,'kind':'go-erp-fixture-seed-upgraded','core':CORE,'seed':SEED,
            'inventory_sha256':INVENTORY,'intent_sha256':sha(canonical(intent)),
            'counter':37,'previous_candidate':OLD_CANDIDATE}


def rollback(ctx, audit, intent, parents, names):
    u,b=ctx.utility,ctx.base
    switched=[]
    for slot in SLOTS:
        directory=slot=='checkout';value=intent['slots'][slot]
        old=b.recognize(u,parents[slot],names[slot],value['before'],directory)
        new=b.recognize(u,parents[slot],names[slot],value['after'],directory)
        heldold=b.recognize(u,audit,b.exchange_name(slot),value['before'],directory)
        heldnew=b.recognize(u,audit,b.exchange_name(slot),value['after'],directory)
        need((old and heldnew) or (new and heldold),'ROLLBACK_REQUIRES_REVIEW')
        if directory:
            b.checkout(parents[slot].path/names[slot],SEED if new else OLD_SEED,u)
            b.checkout(audit.path/b.exchange_name(slot),OLD_SEED if new else SEED,u)
        if new:switched.append(slot)
    with u.critical():
        for slot in reversed(switched):b.exchange_slot(u,audit,slot,parents,names)
    u.system_command(['/usr/bin/systemctl','daemon-reload'])
    u.effective_unit(UNIT,CONFIG,(ctx.old.runtime,))


def transaction(ctx,lock_fd,verify,observation,event=None):
    """Five exact private inode exchanges; config is the final visible switch."""
    u,b=ctx.utility,ctx.base
    parents={};handles=[];audit=None;intent=None;changed=False
    names={'unit':UNIT.name,'checkout':'checkout','state':'current.json',
           'hold':'program-rollout-hold.json','config':CONFIG.name}
    try:
        for slot,path,mode in (('unit',UNIT.parent,0o755),('checkout',WORK,0o700),
                ('state',WORK/'state',0o700),('hold',WORK,0o700),('config',CONFIG.parent,0o700)):
            parents[slot]=u.Directory(path,mode);handles.append(parents[slot])
        name=PREFIX+uuid.uuid4().hex
        os.mkdir(name,0o700,dir_fd=parents['checkout'].fd);os.fsync(parents['checkout'].fd)
        audit=u.Directory(WORK/name,0o700);handles.append(audit)
        need(len({os.fstat(value.fd).st_dev for value in [*parents.values(),audit]})==1,'FILESYSTEM_MISMATCH')
        before={};data={}
        for slot in SLOTS:
            if slot=='checkout':before[slot]=b.slot_record(u,parents[slot],names[slot],True)
            else:
                data[slot],before[slot]=b.read_record(u,parents[slot],names[slot],MAX_STATE,0o644 if slot=='unit' else 0o600)
                u.write_at(audit,'before-'+slot+'.bin',data[slot])
        cfg=b.parse(data['config']);b.checked_config(cfg,OLD_SEED)
        need(b.parse(data['state'])==observation['state'] and b.parse(data['hold'])==observation['hold'],'OLD_SLOT_CHANGED')
        state=next_state(b,observation['state']);cfg['base_revision']=SEED
        for slot,value in (('unit',b.unit_bytes(u,ctx.runtime,ROOT,WORK,CONFIG)),('state',canonical(state)),
                           ('hold',canonical(ctx.mode.hold(ctx.expected))),('config',canonical(cfg))):
            u.write_at(audit,b.exchange_name(slot),value,0o644 if slot=='unit' else 0o600)
        os.mkdir(b.exchange_name('checkout'),0o700,dir_fd=audit.fd);os.fsync(audit.fd)
        proof=ctx.prepare_target(audit.path,state)
        u.system_command(['/usr/bin/systemd-analyze','verify',str(audit.path/b.exchange_name('unit'))])
        intent={'format':1,'kind':'go-erp-fixture-seed-upgrade','old_core':OLD_CORE,'old_seed':OLD_SEED,
                'core':CORE,'seed':SEED,'runtime':str(ctx.runtime),'pins':ctx.pins,
                'observation':observation,'new_snapshot':proof,'slots':{slot:{'before':before[slot],
                    'after':b.slot_record(u,audit,b.exchange_name(slot),slot=='checkout')} for slot in SLOTS}}
        u.json_at(audit,'intent.json',intent)
        def guard():
            verify();audit.verify();ctx.check_before(observation,lock_fd)
            for slot in SLOTS:
                need(b.recognize(u,parents[slot],names[slot],intent['slots'][slot]['before'],slot=='checkout')
                     and b.recognize(u,audit,b.exchange_name(slot),intent['slots'][slot]['after'],slot=='checkout'),'STAGED_SLOT_CHANGED')
        guard()
        if event:event('before-exchange',None)
        guard()
        with u.critical():
            for slot in SLOTS:
                changed=True;b.exchange_slot(u,audit,slot,parents,names)
                if slot=='unit':
                    u.system_command(['/usr/bin/systemctl','daemon-reload'])
                    u.effective_unit(UNIT,CONFIG,(ctx.runtime,))
                if event:event('after-exchange',slot)
        verify();ctx.check_after(intent,lock_fd)
        need(ctx.remote()==OLD_CANDIDATE and ctx.publication() is None,'PUBLICATION_OR_LEASE_CHANGED')
        for slot in SLOTS:
            need(b.recognize(u,parents[slot],names[slot],intent['slots'][slot]['after'],slot=='checkout')
                 and b.recognize(u,audit,b.exchange_name(slot),intent['slots'][slot]['before'],slot=='checkout'),'AFTER_SLOT_CHANGED')
        u.json_at(audit,'completed.json',completion(intent))
        return {'ok':True,'published':False,'worker_request':'candidate','counter':37,
                'next_version':VERSION,'audit_directory':str(audit.path),'already_current':False,
                'configuration_changed':True,'state_changed':True,'checkout_changed':True,'unit_changed':True,
                'hold_changed':True,'rollback_complete':True}
    except BaseException as error:
        if changed and intent is not None:
            try:
                rollback(ctx,audit,intent,parents,names)
                ctx.check_rollback(observation,lock_fd,intent,audit.path)
                u.json_at(audit,'rolled-back.json',{'format':1,'recovered':False})
            except BaseException:review('ROLLBACK_REQUIRES_REVIEW')
        elif intent is not None:
            u.json_at(audit,'rolled-back.json',{'format':1,'exchanges_started':False})
        raise
    finally:
        for directory in reversed(handles):directory.close()


def audit_paths(ctx):
    result=[]
    for path in WORK.iterdir():
        if re.fullmatch(PREFIX+r'[a-f0-9]{32}',path.name) and (path/'intent.json').exists():
            ctx.gate.program_gate_directory(path)
            if not (path/'rolled-back.json').exists():result.append(path)
    if len(result)>1:review('MULTIPLE_UPGRADE_AUDITS')
    return result


def controls_equal(actual,sealed,renamed=False):
    if set(actual)!=set(sealed):return False
    for name,raw in actual.items():
        value=json.loads(raw)
        if name=='payment':
            if value!=sealed[name]:return False
        else:
            expected=sealed[name]
            if not isinstance(value,dict) or set(value)!={'sha256','identity'} or not isinstance(expected,dict):return False
            if value['sha256']!=expected.get('sha256'):return False
            if (value['identity'][:8] if renamed else value['identity']) != \
                    (expected['identity'][:8] if renamed else expected['identity']):return False
    return True


def validate_intent(ctx,path):
    value=ctx.gate.program_gate_json(path/'intent.json')
    need(isinstance(value,dict) and set(value)=={'format','kind','old_core','old_seed','core','seed',
         'runtime','pins','observation','new_snapshot','slots'} and value['format']==1
         and value['kind']=='go-erp-fixture-seed-upgrade' and value['old_core']==OLD_CORE
         and value['old_seed']==OLD_SEED and value['core']==CORE and value['seed']==SEED
         and value['runtime']==str(ctx.runtime) and value['pins']==ctx.pins
         and isinstance(value['slots'],dict) and set(value['slots'])==set(SLOTS),'AUDIT_IDENTITY_INVALID')
    observation=value['observation']
    need(isinstance(observation,dict) and set(observation)=={'state','controls','evidence','mode_proof','payment','hold','snapshot'},
         'AUDIT_OBSERVATIONS_INVALID')
    next_state(ctx.base,observation['state'])
    need(observation['hold'] in (ctx.mode.hold(ctx.old.prepared_expected),ctx.mode.hold(ctx.old.expected)),
         'AUDIT_HOLD_INVALID')
    need((observation['mode_proof'] is None)==(observation['hold']==ctx.mode.hold(ctx.old.prepared_expected)),
         'AUDIT_MODE_PROOF_INVALID')
    for snapshot,seed in ((observation['snapshot'],OLD_SEED),(value['new_snapshot'],SEED)):
        need(isinstance(snapshot,dict) and snapshot.get('seedRevision')==seed
             and snapshot.get('inventorySha256')==INVENTORY and isinstance(snapshot.get('inventory'),dict),
             'AUDIT_SNAPSHOT_INVALID')
    oldrows=observation['snapshot']['inventory'];newrows=value['new_snapshot']['inventory']
    need(oldrows==newrows and sha(json.dumps(newrows,ensure_ascii=False,sort_keys=True,separators=(',',':'),
         allow_nan=False).encode())==INVENTORY,'AUDIT_SNAPSHOT_INVALID')
    for slot,record in value['slots'].items():
        need(isinstance(record,dict) and set(record)=={'before','after'} and all(isinstance(record[key],dict)
             and set(record[key])==({'identity'} if slot=='checkout' else {'identity','sha256'})
             for key in ('before','after')),'AUDIT_SLOT_INVALID')
        for wanted in record.values():
            identity=wanted['identity']
            need(isinstance(identity,list) and len(identity)==(5 if slot=='checkout' else 8)
                 and all(type(item) is int and item>=0 for item in identity),'AUDIT_SLOT_INVALID')
            if slot!='checkout':need(isinstance(wanted['sha256'],str) and re.fullmatch(r'[a-f0-9]{64}',wanted['sha256']),
                                      'AUDIT_SLOT_INVALID')
    ctx.check_protected(observation)
    if (path/'completed.json').exists():
        need(ctx.gate.program_gate_json(path/'completed.json')==completion(value),'AUDIT_COMPLETION_INVALID')
    return value


def recover(ctx,lock_fd,verify):
    """Rollback only a fully recognized interrupted private exchange set."""
    paths=audit_paths(ctx)
    if not paths or (paths[0]/'completed.json').exists():return False
    path=paths[0];u,b=ctx.utility,ctx.base
    audit=u.Directory(path,0o700);handles=[audit];parents={}
    try:
        intent=validate_intent(ctx,path)
        for slot,parent,mode in (('unit',UNIT.parent,0o755),('checkout',WORK,0o700),
                ('state',WORK/'state',0o700),('hold',WORK,0o700),('config',CONFIG.parent,0o700)):
            parents[slot]=u.Directory(parent,mode);handles.append(parents[slot])
        names={'unit':UNIT.name,'checkout':'checkout','state':'current.json','hold':'program-rollout-hold.json','config':CONFIG.name}
        verify();ctx.prior.assert_quiescent();ctx.check_protected(intent['observation'])
        need(ctx.remote()==OLD_CANDIDATE and ctx.publication() is None,'RECOVERY_PUBLICATION_CHANGED')
        rollback(ctx,audit,intent,parents,names)
        verify();ctx.check_rollback(intent['observation'],lock_fd,intent,path)
        u.json_at(audit,'rolled-back.json',{'format':1,'recovered':True})
        return True
    except BaseException:review('RECOVERY_REQUIRES_REVIEW')
    finally:
        for directory in reversed(handles):directory.close()


def completed_upgrade(ctx,lock_fd,verify):
    paths=audit_paths(ctx)
    need(len(paths)==1 and (paths[0]/'completed.json').exists(),'UPGRADE_NOT_COMPLETED')
    path=paths[0];intent=validate_intent(ctx,path)
    u,b=ctx.utility,ctx.base
    audit=u.Directory(path,0o700)
    try:
        # Retain every original private inode. Mutable worker state and its
        # genuine authorization are allowed to advance after this transaction.
        for slot in SLOTS:
            need(b.recognize(u,audit,b.exchange_name(slot),intent['slots'][slot]['before'],slot=='checkout'),
                 'RETAINED_SLOT_CHANGED')
        verify();state=ctx.check_after(intent,lock_fd,False)
        return path,intent,state
    finally:audit.close()


def state_guard(ctx,state,allow_allocation=False):
    b=ctx.base;b.checked_state(state,SEED)
    desired=state.get('desired')
    if allow_allocation and state.get('counter')==37 and desired is None:
        need(state.get('last_remote_sha')==OLD_CANDIDATE and ctx.remote()==OLD_CANDIDATE
             and state.get('published') is None and ctx.publication() is None,'ALLOCATION_LEASE_CHANGED')
        return None
    need(state.get('counter')==38 and isinstance(desired,dict) and desired.get('version')==VERSION
         and desired.get('inventorySha256')==INVENTORY and desired.get('pushed') is True
         and isinstance(desired.get('commit'),str) and re.fullmatch(r'[a-f0-9]{40}',desired['commit'])
         and desired.get('commit')==state.get('last_remote_sha')==ctx.remote(),'SUCCESSOR_STATE_INVALID')
    need(desired.get('status') in ('waiting-ci','published') and desired.get('failures',0)==0
         and desired.get('retry_at',0)==0,'CI_REVIEW_REQUIRED')
    need(state.get('published') is None or isinstance(state['published'],dict)
         and state['published'].get('commit')==desired['commit'],'OTHER_PUBLICATION_PRESENT')
    return desired


def genuine_completed(ctx,state):
    desired=state_guard(ctx,state)
    ctx.candidate(desired['commit'])
    if not ctx.gate.program_gate_completed(WORK,ctx.expected,state,ctx.publication):return False
    try:(WORK/'program-rollout-hold.json').lstat()
    except FileNotFoundError:pass
    else:need(False,'HOLD_NOT_RETIRED')
    return True


def authorize(ctx,state,intent,lock_fd,verify,receipt):
    directory=ctx.utility.Directory(ctx.runtime,0o755)
    try:worker=ctx.utility.module_at(directory,'go_erp_live_updates.py',ctx.pins['go_erp_live_updates.py'])
    finally:directory.close()
    try:
        ctx.gate.program_gate_authorize(WORK,receipt,ctx.expected,state,
            lambda folder,run,wanted:worker.verify_proof(folder,run,wanted,ctx.publisher),ctx.publication)
        verify();ctx.check_after(intent,lock_fd,False)
        need(ctx.gate.program_gate_json(WORK/'state/current.json')==state,'AUTHORIZATION_STATE_CHANGED')
    except BaseException:
        # Authorization can already be durable. Preserve it for the next exact
        # genuine-gate retry; never report or attempt an invented rollback.
        review('AUTHORIZATION_REQUIRES_REVIEW')
    return {'ok':True,'published':False,'worker_request':'publish','candidate_revision':state['desired']['commit']}


def execute(package,mode,receipt=None):
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    package=Path(package).absolute();need(package.resolve()==package,'PACKAGE_UNSAFE')
    ctx=Context(package)
    with ctx.prior.held_lock(ctx.utility,WORK) as (lock_fd,verify):
        ctx.prior.assert_quiescent()
        if mode=='lease':
            pending=audit_paths(ctx)
            if pending and not (pending[0]/'completed.json').exists():
                # Read-only routing keeps interrupted five-slot recovery
                # reachable even while config and checkout name different
                # recognized sides. Prepare performs full classification.
                try:validate_intent(ctx,pending[0]);verify()
                except BaseException:review('RECOVERY_REQUIRES_REVIEW')
                return {'ok':True,'published':False,'worker_request':'prepare'}
        if mode=='prepare':recover(ctx,lock_fd,verify)
        config=ctx.gate.program_gate_json(CONFIG)
        if config.get('base_revision')==OLD_SEED:
            need(mode in ('lease','prepare'),'UPGRADE_NOT_COMPLETED')
            observation=ctx.capture_old(lock_fd);verify()
            if mode=='lease':return {'ok':True,'published':False,'worker_request':'prepare'}
            return transaction(ctx,lock_fd,verify,observation)
        need(config.get('base_revision')==SEED,'CONFIGURATION_SEED_INVALID')
        path,intent,state=completed_upgrade(ctx,lock_fd,verify)
        desired=state_guard(ctx,state,True)
        if desired is None:
            need(mode in ('lease','prepare'),'ALLOCATION_PENDING')
            return {'ok':True,'published':False,'already_current':True,'worker_request':'candidate','counter':37}
        if mode=='lease':
            return {'ok':True,'published':False,'worker_request':'proof_objects','candidate_revision':desired['commit']}
        ctx.candidate(desired['commit'])
        if genuine_completed(ctx,state):return {'ok':True,'published':True,'worker_request':'none','candidate_revision':desired['commit']}
        need(mode!='verify','PUBLICATION_NOT_COMPLETE')
        current=ctx.gate.program_gate_json(WORK/'program-rollout-hold.json')
        need(all(current.get(name)==value for name,value in ctx.expected.items()),'SUCCESSOR_HOLD_CHANGED')
        if current.get('authorization') is not None:
            auth=current['authorization']
            need(isinstance(auth,dict) and auth.get('candidate')==desired['commit']
                 and ctx.gate.program_gate_allows(WORK,desired,auth.get('release')),'SUCCESSOR_AUTHORIZATION_INVALID')
        else:need(current==ctx.mode.hold(ctx.expected),'SUCCESSOR_HOLD_CHANGED')
        if mode in ('prepare','status'):
            try:pending=ctx.gate.program_gate_json(WORK/'pending-program-acceptance.json')
            except FileNotFoundError:pending=None
            matching=isinstance(pending,dict) and pending.get('candidate_revision')==desired['commit']
            if matching:
                need(pending.get('version')==VERSION and all(pending.get(name)==value for name,value in ctx.expected.items()),
                     'PENDING_ACCEPTANCE_MISMATCH')
            return {'ok':True,'published':False,'already_current':True,
                    'worker_request':'authorize' if matching else 'collect','candidate_revision':desired['commit']}
        need(mode=='authorize' and receipt is not None,'MODE_INVALID')
        return authorize(ctx,state,intent,lock_fd,verify,receipt)


def main(arguments=None):
    parser=argparse.ArgumentParser();parser.add_argument('--package',required=True)
    parser.add_argument('--mode',choices=('lease','prepare','status','authorize','verify'),required=True)
    parser.add_argument('--receipt');args=parser.parse_args(arguments)
    if (args.mode=='authorize')!=bool(args.receipt):parser.error('authorize requires its receipt')
    try:result=execute(args.package,args.mode,args.receipt)
    except BaseException as error:
        # Own literal reasons only. Foreign pinned helper locations/reasons use
        # the same previously reviewed, byte-bound strict privacy reporter.
        reason=error.args[0] if type(error) is Stop and len(error.args)==1 and type(error.args[0]) is str else None
        allowed=reason in PUBLIC_REASONS
        result={'ok':False,'reason':reason if allowed else 'GO_ERP_FIXTURE_SEED_PRIVATE_FAILURE',
                'rollback_complete':getattr(error,'rollback_complete',True) is True}
        for name in ('configuration_changed','state_changed','checkout_changed','unit_changed','hold_changed'):
            result[name]=False if result['rollback_complete'] else 'unknown'
        if allowed:
            trace=error.__traceback__
            while trace is not None and trace.tb_next is not None:trace=trace.tb_next
            if trace is not None and trace.tb_frame.f_globals is globals():
                result['diagnostic']={'type':'Stop','file':'upgrade_go_erp_fixture_seed_root.py','line':trace.tb_lineno,'site':'raise'}
        if not allowed:
            try:
                module=_MODE or load_mode(Path(args.package))
                old=module._LOADED_PRIOR or module.load_prior(Path(args.package))
                result['diagnostic'],result['reason']=old.safe_error_details(error,args.package)
            except BaseException:pass
    result.update(format=1,kind='go-erp-fixture-seed-upgrade',application_contents_changed=False,
                  modes_changed=False,database_changed=False,credentials_disclosed=False)
    print(json.dumps(result,sort_keys=True))
    return 0 if result.get('ok') is True else 78 if result.get('rollback_complete') is False else 1


PUBLIC_REASONS = frozenset(['GO_ERP_FIXTURE_SEED_AFTER_SLOT_CHANGED', 'GO_ERP_FIXTURE_SEED_ALLOCATION_LEASE_CHANGED', 'GO_ERP_FIXTURE_SEED_ALLOCATION_PENDING', 'GO_ERP_FIXTURE_SEED_AUDIT_COMPLETION_INVALID', 'GO_ERP_FIXTURE_SEED_AUDIT_HOLD_INVALID', 'GO_ERP_FIXTURE_SEED_AUDIT_IDENTITY_INVALID', 'GO_ERP_FIXTURE_SEED_AUDIT_MODE_PROOF_INVALID', 'GO_ERP_FIXTURE_SEED_AUDIT_OBSERVATIONS_INVALID', 'GO_ERP_FIXTURE_SEED_AUDIT_SLOT_INVALID', 'GO_ERP_FIXTURE_SEED_AUDIT_SNAPSHOT_INVALID', 'GO_ERP_FIXTURE_SEED_AUTHORIZATION_STATE_CHANGED', 'GO_ERP_FIXTURE_SEED_AUTHORIZATION_REQUIRES_REVIEW', 'GO_ERP_FIXTURE_SEED_CANDIDATE_APPROVED_FILE_INVALID', 'GO_ERP_FIXTURE_SEED_CANDIDATE_CODE_INVALID', 'GO_ERP_FIXTURE_SEED_CANDIDATE_INVALID', 'GO_ERP_FIXTURE_SEED_CANDIDATE_METADATA_INVALID', 'GO_ERP_FIXTURE_SEED_CANDIDATE_PACKAGE_INVALID', 'GO_ERP_FIXTURE_SEED_CANDIDATE_PARENT_INVALID', 'GO_ERP_FIXTURE_SEED_CI_REVIEW_REQUIRED', 'GO_ERP_FIXTURE_SEED_CONFIGURATION_SEED_INVALID', 'GO_ERP_FIXTURE_SEED_CONFIG_CHANGED', 'GO_ERP_FIXTURE_SEED_DEPENDENCIES_CHANGED', 'GO_ERP_FIXTURE_SEED_DURABLE_HISTORY_CHANGED', 'GO_ERP_FIXTURE_SEED_FILESYSTEM_MISMATCH', 'GO_ERP_FIXTURE_SEED_HOLD_NOT_RETIRED', 'GO_ERP_FIXTURE_SEED_LIVE_INVENTORY_CHANGED', 'GO_ERP_FIXTURE_SEED_LIVE_SOURCE_CHANGED', 'GO_ERP_FIXTURE_SEED_LIVE_SNAPSHOT_REJECTED', 'GO_ERP_FIXTURE_SEED_MODE_EVIDENCE_CHANGED', 'GO_ERP_FIXTURE_SEED_MODE_IDENTITY_INVALID', 'GO_ERP_FIXTURE_SEED_MODE_INVALID', 'GO_ERP_FIXTURE_SEED_MODE_MODULE_PIN_INVALID', 'GO_ERP_FIXTURE_SEED_MODE_MODULE_UNSAFE', 'GO_ERP_FIXTURE_SEED_MODE_TRANSACTION_REQUIRES_REVIEW', 'GO_ERP_FIXTURE_SEED_MULTIPLE_UPGRADE_AUDITS', 'GO_ERP_FIXTURE_SEED_OLD_HOLD_DIFFERS_OR_AUTHORIZED', 'GO_ERP_FIXTURE_SEED_OLD_OBSERVATIONS_CHANGED', 'GO_ERP_FIXTURE_SEED_OLD_PUBLICATION_OR_LEASE_CHANGED', 'GO_ERP_FIXTURE_SEED_OLD_SLOT_CHANGED', 'GO_ERP_FIXTURE_SEED_OLD_STATE_INVALID', 'GO_ERP_FIXTURE_SEED_ORIGINAL_EVIDENCE_CHANGED', 'GO_ERP_FIXTURE_SEED_OTHER_PUBLICATION_PRESENT', 'GO_ERP_FIXTURE_SEED_PACKAGE_UNSAFE', 'GO_ERP_FIXTURE_SEED_PAYMENT_SOURCE_CHANGED', 'GO_ERP_FIXTURE_SEED_PENDING_ACCEPTANCE_MISMATCH', 'GO_ERP_FIXTURE_SEED_PREPARED_SOURCE_CHANGED', 'GO_ERP_FIXTURE_SEED_PROTECTED_SOURCE_CHANGED', 'GO_ERP_FIXTURE_SEED_PUBLICATION_NOT_COMPLETE', 'GO_ERP_FIXTURE_SEED_PUBLICATION_OR_LEASE_CHANGED', 'GO_ERP_FIXTURE_SEED_RECOVERY_PUBLICATION_CHANGED', 'GO_ERP_FIXTURE_SEED_RECOVERY_REQUIRES_REVIEW', 'GO_ERP_FIXTURE_SEED_RELEASE_PINS_PENDING', 'GO_ERP_FIXTURE_SEED_RETAINED_SLOT_CHANGED', 'GO_ERP_FIXTURE_SEED_ROLLBACK_CONTROLS_CHANGED', 'GO_ERP_FIXTURE_SEED_ROLLBACK_HOLD_OR_LEASE_CHANGED', 'GO_ERP_FIXTURE_SEED_ROLLBACK_REQUIRES_REVIEW', 'GO_ERP_FIXTURE_SEED_ROLLBACK_SLOT_CHANGED', 'GO_ERP_FIXTURE_SEED_ROLLBACK_SOURCE_CHANGED', 'GO_ERP_FIXTURE_SEED_ROOT_AND_HOST_REQUIRED', 'GO_ERP_FIXTURE_SEED_RUNTIME_PATCH_CONTEXT_INVALID', 'GO_ERP_FIXTURE_SEED_RUNTIME_PATH_CHANGED', 'GO_ERP_FIXTURE_SEED_RUNTIME_POLICY_INVALID', 'GO_ERP_FIXTURE_SEED_RUNTIME_SEED_INVALID', 'GO_ERP_FIXTURE_SEED_SOURCE_CHANGED', 'GO_ERP_FIXTURE_SEED_STAGED_SLOT_CHANGED', 'GO_ERP_FIXTURE_SEED_STATE_CHANGED', 'GO_ERP_FIXTURE_SEED_STATE_TRANSFORM_INVALID', 'GO_ERP_FIXTURE_SEED_SUCCESSOR_AUTHORIZATION_INVALID', 'GO_ERP_FIXTURE_SEED_SUCCESSOR_HOLD_CHANGED', 'GO_ERP_FIXTURE_SEED_SUCCESSOR_STATE_INVALID', 'GO_ERP_FIXTURE_SEED_UNIT_CHANGED', 'GO_ERP_FIXTURE_SEED_UPGRADE_NOT_COMPLETED'])


if __name__=='__main__':raise SystemExit(main())

