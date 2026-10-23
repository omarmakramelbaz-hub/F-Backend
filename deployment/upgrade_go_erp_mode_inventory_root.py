#!/usr/bin/env python3
"""Reconcile two historical executable bits; never change application or state."""
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
CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
CANDIDATE = '5f2b0fecafec1c3803499284b0abcca4ecfed7e4'
VERSION = '0.4.4-preview.37'
SOURCE = {'format': 1, 'files': 1346, 'framework': '8.83.29', 'sha256': '41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63'}
PREPARED_INVENTORY = '1480586d09f7b87ac2528f74b14a5694c631d31c6ddc5c32eb8dc750c5550a9c'
TARGET_INVENTORY = 'de5ac407604249df2198aa71c2d7b4ca0a88ac16bba3cf3f028c61e3077111d8'
PRIOR_NAME = 'upgrade_go_erp_confirmation_nav_successor_root.py'
PRIOR_PIN = 'aafc3627a24ea17c7e280bc1d0da3c30903299b3'
PREPARED_AUDIT = 'confirmation-navigation-successor-e2f9d518b41946da8bd0c530d0f55b3a'
PREFIX = 'mode-inventory-reconciliation-'
MODE_CORRECTIONS = {
    'app/Http/Controllers/CheckoutController.php': ('100644', '100755'),
    'app/Http/Controllers/Payment/PaymobController.php': ('100644', '100755'),
}
APPROVED_PINS = {'app/Services/Dashboard/OrderBoardService.php': ('d2c8c69de733b131aa27f36e02d6b4b0b990b6b2', '6cb2330647bd701291be94fe5549f2d1bc000015'), 'app/Services/Dashboard/PosServiceTicket.php': ('3549b9fa0df2dd97a630b20bb9753df4d4178bf4', 'b1600ecb7c1f5ec6a3d8f90b6c391ccca8f5babe'), 'public/dashboard/js/phone-orders.js': ('5923ef6feccda16bd1d759e9d9d307a96f8c6480', '319cbc52a36f0f73ad69f3285e4e7d52b5e7fa69'), 'public/dashboard/css/phone-delivery-refresh.css': ('bd9f95fb6d8a28f382708ca6e6a9b36f12d62392', '181359e8b0e4088f544eede725306fcbf3f7e9e7'), 'app/Http/Controllers/CheckoutController.php': ('00efa1050e81c210bd921f665afb1cdf5d89889d', 'ec76900a10c11c702659eff3199d4b751dce5343'), 'app/Http/Controllers/Payment/PaymobController.php': ('13e48a6675303c651ad72ee5d79c88b6e56d520c', '9bd38c2a69debfea91d31d2a7f3179ab0fd631b4'), 'app/Http/Controllers/Payment/RestaurantPaymentController.php': (None, 'efc57fdc0ea3f3173080a2282402e1c51305ef3e'), 'app/Services/Payments/RestaurantOrderPayment.php': (None, '1699f40405d04abe92ae5350b57d26f0a90a8b36'), 'app/Services/Payments/RestaurantPaymentEffects.php': (None, '23264d1573363b722b87dab064e53da1e14df6de'), 'app/Console/Commands/ReconcileRestaurantPayments.php': (None, 'ff7d17d64d8676b89dee567fa434fdecfaf59c8e'), 'app/Console/Kernel.php': ('b6b28cbe6cb1098a1861bd3b4c905c61a0f58ef8', 'a3d44fd2fa9827ee2bae5fe93cffa6a470eac7af'), 'routes/api.php': ('16d776a16436821c5e5815dcbdd807f35cb0353f', 'bc722739c14e22607028de7bb15f59a55a8ab464'), 'resources/views/admin/orders/board_card.blade.php': ('359ddbc50c651da22f7aee10ed9d7a27df26a6a5', '6b723579bd15adc82524bd6056d79feac37aac87'), 'resources/views/admin/orders/board_detail.blade.php': ('ef6c357fcaff06fbc5d125e14be352138214a822', 'e94cb48239828025415861b1c8ef45621049ddbb'), 'resources/lang/ar/order_board.php': ('93e67aab5ae5beea8261b6007c8453496eb08a18', '966b799f864d96f36b17684b6cc03a716dbea7af'), 'resources/lang/en/order_board.php': ('deac8a800e24146897a902ac6a4efe78b5dbdfb5', '9a0a2eb2fdf97a4bf248547b274331b03bfd2d01'), 'public/dashboard/css/order-board.css': ('97b409f020f6ef547956205057f7795955416379', '88093e1439246947cde3a540b1db0d14229a7d67')}
_LOADED_PRIOR = None


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_MODE_RECONCILIATION_' + reason)


def review(reason):
    error = Stop('GO_ERP_MODE_RECONCILIATION_' + reason)
    error.rollback_complete = False
    raise error


def sha(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return (json.dumps(value, sort_keys=True) + '\n').encode()


def load_prior(package):
    global _LOADED_PRIOR
    path = package / PRIOR_NAME
    for parent in (path.parent, *path.parent.parents):
        item = parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0 and not item.st_mode & 0o022, 'PACKAGE_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        key = lambda item: (item.st_dev,item.st_ino,item.st_mode,item.st_uid,item.st_gid,item.st_nlink,item.st_size,item.st_mtime_ns,item.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,stat.S_IMODE(before.st_mode)) == (0,0,1,0o644)
             and 0 < before.st_size <= 2*1024*1024, 'PRIOR_UNSAFE')
        data = bytearray()
        while len(data) <= 2*1024*1024:
            part = os.read(fd, min(65536, 2*1024*1024 + 1-len(data)))
            if not part: break
            data.extend(part)
        need(len(data) == before.st_size and key(before) == key(os.fstat(fd)) == key(path.lstat())
             and hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() == PRIOR_PIN, 'PRIOR_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('pinned_confirmation_inventory_prior'); module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(bytes(data), str(path), 'exec'), module.__dict__)
    _LOADED_PRIOR = module
    return module


class Context:
    def __init__(self, package):
        self.package = package
        self.old = load_prior(package)
        need((self.old.CORE,self.old.SEED,self.old.NEW_SOURCE,self.old.NEW_INVENTORY) ==
             (CORE,SEED,SOURCE,PREPARED_INVENTORY), 'PRIOR_IDENTITY_INVALID')
        self.original = original = self.old.Context(package)
        for name in ('base','prior','utility','application','gate','publisher','worker','owner','runtime','pins'):
            setattr(self,name,getattr(original,name))
        need(self.old.PAYMENT_PINS == APPROVED_PINS, 'APPROVED_ROSTER_INVALID')
        self.prepared_expected = original.expected
        self.expected = self.gate.program_gate_expected(CORE,SOURCE,TARGET_INVENTORY)
        # The worker deletes its private candidate clone after pushing. The
        # wrapper fetches these fixed public objects into its own bare cache;
        # the installed checkout and all refs remain unchanged.
        self.candidate_root = package.parent/'release.git'
        cache = self.utility.Directory(self.candidate_root)
        try:
            need(self.base.git(self.candidate_root,'rev-parse','--is-bare-repository')==b'true\n','CANDIDATE_CACHE_INVALID')
            cache.verify()
        finally:cache.close()
        directory = self.utility.Directory(self.runtime,0o755)
        try:
            self.source = self.utility.module_at(directory,'go_erp_live_source.py',self.pins['go_erp_live_source.py'])
        finally:
            directory.close()

    def controls(self):
        return self.original.controls()

    def publication(self):
        return self.original.publication()

    def candidate(self, revision, inventory, minimum):
        b,p = self.base,self.prior
        repository = getattr(self,'candidate_root',WORK/'checkout')
        need(isinstance(revision,str) and re.fullmatch(r'[a-f0-9]{40}',revision) and inventory == TARGET_INVENTORY and minimum == 37,
             'CANDIDATE_INVALID')
        header = b.git(repository,'cat-file','commit',revision).split(b'\n\n',1)[0]
        need([row[7:].decode() for row in header.splitlines() if row.startswith(b'parent ')] == [SEED], 'CANDIDATE_PARENT_INVALID')
        names = set(b.git(repository,'diff','--name-only','--no-renames','-z',SEED,revision,'--').decode().split('\0'))-{''}
        need(names.issubset(set(APPROVED_PINS)|{self.old.NAV if hasattr(self,'old') else 'public/dashboard/js/dashboard-navigation.js'}|set(p.VERSIONS)),
             'CANDIDATE_CODE_INVALID')
        metadata = b.parse(b.git(repository,'show',revision+':'+p.VERSIONS[2]))
        rows = metadata.get('inventory')
        need(isinstance(rows,dict) and metadata == p.candidate_metadata(SEED,rows,37)
             and metadata.get('inventorySha256') == TARGET_INVENTORY, 'CANDIDATE_METADATA_INVALID')
        prepared = copy.deepcopy(rows)
        for name,(old,new) in MODE_CORRECTIONS.items():
            need(isinstance(prepared.get(name),dict) and prepared[name].get('mode') == new, 'MODE_CORRECTION_INVALID')
            prepared[name]['mode'] = old
        need(sha(json.dumps(prepared,ensure_ascii=False,sort_keys=True,separators=(',',':'),allow_nan=False).encode()) == PREPARED_INVENTORY,
             'MODE_CORRECTION_INVALID')
        nav = 'public/dashboard/js/dashboard-navigation.js'
        for name,wanted in {nav:'5817024fb309d86ccaae2c79ad21391aa8507ecb',**{key:pair[1] for key,pair in APPROVED_PINS.items()}}.items():
            record = b.git(repository,'ls-tree','-z',revision,'--',name).decode()
            need(record == rows[name]['mode']+' blob '+wanted+'\t'+name+'\0', 'CANDIDATE_APPROVED_FILE_INVALID')
        for path in p.VERSIONS:
            before = b.git(repository,'ls-tree','-z',SEED,'--',path).split(b' ',1)[0]
            after = b.git(repository,'ls-tree','-z',revision,'--',path).split(b' ',1)[0]
            need(before == after and before in (b'100644',b'100755'), 'CANDIDATE_PACKAGE_INVALID')
        for path in p.VERSIONS[:2]:
            before=b.parse(b.git(repository,'show',SEED+':'+path));after=b.parse(b.git(repository,'show',revision+':'+path))
            before['version']=VERSION
            if path.endswith('package-lock.json'):before['packages']['']['version']=VERSION
            need(after == before,'CANDIDATE_PACKAGE_INVALID')
        return 37

    def prepared_evidence(self, controls, lock_fd):
        path = WORK/PREPARED_AUDIT
        self.gate.program_gate_directory(path)
        self.old.validate_intent(self.original,path,controls)
        self.old.inner_commit(self.original,path)
        value=self.gate.program_gate_json(path/'completed.json')
        need(value in (self.old.completion_value(),self.old.completion_value(True)), 'PREPARED_COMPLETION_INVALID')
        self.application.verify_current(ROOT,self.package,self.owner.pw_uid,self.owner.pw_gid,lock_fd)
        result={}
        for name in ('intent.json','completed.json','application/application-intent.json','application/application-committed.json'):
            data=self.gate.program_gate_bytes(path/name)
            result[name]={'sha256':sha(data),'identity':list(self.utility.exact_key((path/name).lstat()))}
        return result

    def observe(self, lock_fd):
        self.prior.assert_quiescent()
        controls,state=self.controls()
        self.original.prior.require_no_pending(self.base,self.utility,WORK)
        desired=state.get('desired')
        need(state.get('counter') == 37 and isinstance(desired,dict) and desired.get('commit') == CANDIDATE
             and desired.get('version') == VERSION and desired.get('inventorySha256') == TARGET_INVENTORY
             and desired.get('pushed') is True and desired.get('status') in ('waiting-ci','published')
             and state.get('last_remote_sha') == CANDIDATE and self.original.remote() == CANDIDATE, 'CANDIDATE_STATE_INVALID')
        self.candidate(CANDIDATE,TARGET_INVENTORY,37)
        evidence=self.prepared_evidence(controls,lock_fd)
        # Pure double read: no previous-state write/removal authority and no
        # candidate construction, Git fetch, framework bootstrap or PHP call.
        try:snapshot=self.source.scan_snapshot(ROOT,WORK/'checkout',self.owner.pw_uid,None)
        except Exception as error:
            if type(error) is getattr(self.source,'SourceError',None):need(False,'LIVE_SNAPSHOT_REJECTED')
            raise
        rows=snapshot['metadata']['inventory']
        need(snapshot['metadata'] == self.source._metadata(SEED,rows)
             and snapshot['metadata']['inventorySha256'] == TARGET_INVENTORY, 'LIVE_INVENTORY_CHANGED')
        scanner=self.application.pinned_scanner(self.package)
        need(scanner.digest(scanner.scan(ROOT)) == SOURCE, 'LIVE_SOURCE_CHANGED')
        return controls,state,evidence


def hold(expected):
    return {'format':1,'kind':'go-erp-program-rollout-hold',**expected,'authorization':None}


def intent_value(ctx,controls,evidence,old_identity):
    return {'format':1,'kind':'go-erp-mode-inventory-reconciliation','candidate':CANDIDATE,'version':VERSION,
            'prepared_audit':PREPARED_AUDIT,'prepared_evidence':evidence,
            'old_hold':hold(ctx.prepared_expected),'new_hold':hold(ctx.expected),
            'mode_corrections':{name:list(pair) for name,pair in MODE_CORRECTIONS.items()},
            'old_hold_identity':list(old_identity),
            'control_hashes':{name:sha(data) for name,data in controls.items()}}


def completion(intent):
    return {'format':1,'kind':'go-erp-mode-inventory-reconciled','candidate':CANDIDATE,
            'inventory_sha256':TARGET_INVENTORY,'intent_sha256':sha(canonical(intent))}


def validate_audit(ctx,path,controls,evidence,pending=False):
    value=ctx.gate.program_gate_json(path/'intent.json')
    old_identity=value.get('old_hold_identity') if isinstance(value,dict) else None
    need(isinstance(old_identity,list) and len(old_identity)==9 and all(type(item) is int and item>=0 for item in old_identity), 'AUDIT_IDENTITY_INVALID')
    expected=intent_value(ctx,controls,evidence,old_identity)
    need(isinstance(value,dict) and set(value)==set(expected)
         and all(value.get(name)==data for name,data in expected.items() if name!='control_hashes'), 'AUDIT_IDENTITY_INVALID')
    hashes=value.get('control_hashes')
    need(isinstance(hashes,dict) and set(hashes)==set(controls)
         and all(isinstance(data,str) and re.fullmatch(r'[a-f0-9]{64}',data) for data in hashes.values())
         and all(hashes[name]==sha(controls[name]) for name in controls if pending or name!='state'), 'AUDIT_CONTROLS_CHANGED')
    need(ctx.gate.program_gate_bytes(path/'before-hold.json')==canonical(hold(ctx.prepared_expected)), 'AUDIT_OLD_HOLD_CHANGED')
    if (path/'completed.json').exists():
        need(ctx.gate.program_gate_json(path/'completed.json')==completion(value),'AUDIT_COMPLETION_INVALID')
    return value


def completed_audit(ctx,controls,evidence):
    proofs=[]
    for path in WORK.iterdir():
        if re.fullmatch(PREFIX+r'[a-f0-9]{32}',path.name) and (path/'intent.json').exists() and not (path/'rolled-back.json').exists():
            ctx.gate.program_gate_directory(path)
            need((path/'completed.json').exists(),'AUDIT_COMPLETION_INVALID')
            value=validate_audit(ctx,path,controls,evidence)
            need(ctx.gate.program_gate_bytes(path/'after-hold.json')==canonical(hold(ctx.prepared_expected))
                 and tuple(ctx.utility.exact_key((path/'after-hold.json').lstat())[:8])==tuple(value['old_hold_identity'][:8]),'PENDING_SLOT_CHANGED')
            proofs.append(path)
    need(len(proofs)==1,'AUDIT_COMPLETION_INVALID')
    return proofs[0]


def current_hold(ctx,state):
    value=ctx.gate.program_gate_json(WORK/'program-rollout-hold.json')
    need(isinstance(value,dict) and all(value.get(name)==item for name,item in ctx.expected.items()),'RECONCILED_HOLD_CHANGED')
    if value.get('authorization') is not None:
        auth=value['authorization']
        need(isinstance(auth,dict) and auth.get('candidate')==CANDIDATE and isinstance(auth.get('release'),dict)
             and ctx.gate.program_gate_allows(WORK,state['desired'],auth['release']),'AUTHORIZATION_INVALID')
    else:
        need(value==hold(ctx.expected),'RECONCILED_HOLD_CHANGED')
    return value


def reconcile(ctx,lock_fd,verify,controls,state,evidence):
    g,u=ctx.gate,ctx.utility
    audits=[]
    for path in WORK.iterdir():
        if re.fullmatch(PREFIX+r'[a-f0-9]{32}',path.name):
            g.program_gate_directory(path)
            if (path/'intent.json').exists() and not (path/'rolled-back.json').exists():audits.append(path)
    if len(audits)>1:review('MULTIPLE_RECONCILIATIONS')
    current=g.program_gate_json(WORK/'program-rollout-hold.json')
    if audits:
        path=audits[0]
        pending=not (path/'completed.json').exists()
        try:
            value=validate_audit(ctx,path,controls,evidence,pending)
            if pending:
                udir=u.Directory(path,0o700)
                wdir=u.Directory(WORK,0o700)
                try:
                    verify();ctx.prior.assert_quiescent()
                    if current==hold(ctx.prepared_expected):
                        need(u.exact_key((WORK/'program-rollout-hold.json').lstat())==tuple(value['old_hold_identity'])
                             and g.program_gate_bytes(path/'after-hold.json')==canonical(hold(ctx.expected)),'PENDING_SLOT_CHANGED')
                        u.exchange(udir.fd,'after-hold.json',wdir.fd,'program-rollout-hold.json')
                        os.fsync(udir.fd);os.fsync(wdir.fd)
                    else:
                        need(current==hold(ctx.expected),'PENDING_HOLD_CHANGED')
                    need(g.program_gate_bytes(path/'after-hold.json')==canonical(hold(ctx.prepared_expected))
                         and g.program_gate_json(WORK/'program-rollout-hold.json')==hold(ctx.expected)
                         and tuple(u.exact_key((path/'after-hold.json').lstat())[:8])==tuple(value['old_hold_identity'][:8]),'PENDING_SLOT_CHANGED')
                    verify();again,now,proof=ctx.observe(lock_fd)
                    need(again==controls and now==state and proof==evidence and ctx.publication() is None,'OBSERVATIONS_CHANGED')
                    u.json_at(udir,'completed.json',completion(value))
                finally:udir.close();wdir.close()
            else:
                current_hold(ctx,state)
                completed_audit(ctx,controls,evidence)
            return {'ok':True,'already_current':True,'audit_directory':str(path)}
        except BaseException:review('PENDING_RECONCILIATION_REQUIRES_REVIEW')
    need(current==hold(ctx.prepared_expected),'OLD_HOLD_DIFFERS_OR_AUTHORIZED')
    need(state['desired'].get('status')=='waiting-ci' and state.get('published') is None and ctx.publication() is None,'ALREADY_PUBLISHED')
    audit=WORK/(PREFIX+uuid.uuid4().hex);os.mkdir(audit,0o700)
    directory=u.Directory(audit,0o700);work=u.Directory(WORK,0o700)
    before=g.program_gate_bytes(WORK/'program-rollout-hold.json')
    need(before==canonical(current),'OLD_HOLD_ENCODING_INVALID')
    observed=u.exact_key((WORK/'program-rollout-hold.json').lstat());switched=False
    value=intent_value(ctx,controls,evidence,observed)
    def unchanged():
        verify();ctx.prior.assert_quiescent()
        actual,now,proof=ctx.observe(lock_fd)
        need(actual==controls and now==state and proof==evidence and ctx.publication() is None,'OBSERVATIONS_CHANGED')
    try:
        u.write_at(directory,'before-hold.json',before)
        u.write_at(directory,'after-hold.json',canonical(hold(ctx.expected)))
        u.json_at(directory,'intent.json',value)
        unchanged()
        need(g.program_gate_bytes(WORK/'program-rollout-hold.json')==before
             and u.exact_key((WORK/'program-rollout-hold.json').lstat())==observed,'OLD_HOLD_CHANGED')
        u.exchange(directory.fd,'after-hold.json',work.fd,'program-rollout-hold.json');switched=True
        os.fsync(directory.fd);os.fsync(work.fd)
        need(g.program_gate_json(WORK/'program-rollout-hold.json')==hold(ctx.expected),'NEW_HOLD_CHANGED')
        unchanged()
        u.json_at(directory,'completed.json',completion(value))
        return {'ok':True,'already_current':False,'audit_directory':str(audit)}
    except BaseException:
        if (audit/'completed.json').exists():review('COMPLETION_REQUIRES_REVIEW')
        try:
            if switched:
                need(g.program_gate_json(WORK/'program-rollout-hold.json')==hold(ctx.expected)
                     and u.read_at(directory,'after-hold.json',2*1024*1024,0o600)==before,'ROLLBACK_HOLD_CHANGED')
                unchanged()
                u.exchange(directory.fd,'after-hold.json',work.fd,'program-rollout-hold.json')
                os.fsync(directory.fd);os.fsync(work.fd)
            need(g.program_gate_json(WORK/'program-rollout-hold.json')==current,'ROLLBACK_OLD_HOLD_CHANGED')
            u.json_at(directory,'rolled-back.json',{'format':1,'restored':True})
        except BaseException:review('ROLLBACK_REQUIRES_REVIEW')
        raise
    finally:
        directory.close();work.close()


def execute(package,mode,receipt=None):
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    package=Path(package).absolute();need(package.resolve()==package,'PACKAGE_UNSAFE')
    ctx=Context(package)
    with ctx.prior.held_lock(ctx.utility,WORK) as (lock_fd,verify):
        controls,state,evidence=ctx.observe(lock_fd)
        published=ctx.gate.program_gate_completed(WORK,ctx.expected,state,ctx.publication)
        if published:
            completed_audit(ctx,controls,evidence)
            try:(WORK/'program-rollout-hold.json').lstat()
            except FileNotFoundError:pass
            else:need(False,'HOLD_NOT_RETIRED')
            return {'ok':True,'published':True,'worker_request':'none'}
        need(mode!='verify','PUBLICATION_NOT_COMPLETE')
        if mode=='reconcile':return {**reconcile(ctx,lock_fd,verify,controls,state,evidence),'published':False,'worker_request':'collect'}
        # A new target hold is never accepted without its complete immutable
        # reconciliation journal and original source preparation proof.
        completed_audit(ctx,controls,evidence)
        current_hold(ctx,state)
        if mode=='status':
            pending=ctx.gate.program_gate_json(WORK/'pending-program-acceptance.json')
            need(pending.get('candidate_revision')==CANDIDATE and pending.get('version')==VERSION
                 and all(pending.get(name)==data for name,data in ctx.expected.items()),'PENDING_ACCEPTANCE_MISMATCH')
            return {'ok':True,'published':False,'worker_request':'authorize'}
        need(mode=='authorize' and receipt is not None,'MODE_INVALID')
        ctx.gate.program_gate_authorize(WORK,receipt,ctx.expected,state,
            lambda folder,run,desired:ctx.worker.verify_proof(folder,run,desired,ctx.publisher),ctx.publication)
        verify();need(ctx.controls()[0]==controls,'AUTHORIZATION_OBSERVATIONS_CHANGED')
        return {'ok':True,'published':False,'worker_request':'publish'}


PUBLIC_REASONS = frozenset(['GO_ERP_MODE_RECONCILIATION_ALREADY_PUBLISHED', 'GO_ERP_MODE_RECONCILIATION_APPROVED_ROSTER_INVALID', 'GO_ERP_MODE_RECONCILIATION_AUDIT_COMPLETION_INVALID', 'GO_ERP_MODE_RECONCILIATION_AUDIT_CONTROLS_CHANGED', 'GO_ERP_MODE_RECONCILIATION_AUDIT_IDENTITY_INVALID', 'GO_ERP_MODE_RECONCILIATION_AUDIT_OLD_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_AUTHORIZATION_INVALID', 'GO_ERP_MODE_RECONCILIATION_AUTHORIZATION_OBSERVATIONS_CHANGED', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_APPROVED_FILE_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_CACHE_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_CODE_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_METADATA_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_PACKAGE_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_PARENT_INVALID', 'GO_ERP_MODE_RECONCILIATION_CANDIDATE_STATE_INVALID', 'GO_ERP_MODE_RECONCILIATION_COMPLETION_REQUIRES_REVIEW', 'GO_ERP_MODE_RECONCILIATION_HOLD_NOT_RETIRED', 'GO_ERP_MODE_RECONCILIATION_LIVE_INVENTORY_CHANGED', 'GO_ERP_MODE_RECONCILIATION_LIVE_SNAPSHOT_REJECTED', 'GO_ERP_MODE_RECONCILIATION_LIVE_SOURCE_CHANGED', 'GO_ERP_MODE_RECONCILIATION_MODE_CORRECTION_INVALID', 'GO_ERP_MODE_RECONCILIATION_MODE_INVALID', 'GO_ERP_MODE_RECONCILIATION_MULTIPLE_RECONCILIATIONS', 'GO_ERP_MODE_RECONCILIATION_NEW_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_OBSERVATIONS_CHANGED', 'GO_ERP_MODE_RECONCILIATION_OLD_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_OLD_HOLD_DIFFERS_OR_AUTHORIZED', 'GO_ERP_MODE_RECONCILIATION_OLD_HOLD_ENCODING_INVALID', 'GO_ERP_MODE_RECONCILIATION_PACKAGE_UNSAFE', 'GO_ERP_MODE_RECONCILIATION_PENDING_ACCEPTANCE_MISMATCH', 'GO_ERP_MODE_RECONCILIATION_PENDING_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_PENDING_RECONCILIATION_REQUIRES_REVIEW', 'GO_ERP_MODE_RECONCILIATION_PENDING_SLOT_CHANGED', 'GO_ERP_MODE_RECONCILIATION_PREPARED_COMPLETION_INVALID', 'GO_ERP_MODE_RECONCILIATION_PRIOR_IDENTITY_INVALID', 'GO_ERP_MODE_RECONCILIATION_PRIOR_PIN_INVALID', 'GO_ERP_MODE_RECONCILIATION_PRIOR_UNSAFE', 'GO_ERP_MODE_RECONCILIATION_PUBLICATION_NOT_COMPLETE', 'GO_ERP_MODE_RECONCILIATION_RECONCILED_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_ROLLBACK_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_ROLLBACK_OLD_HOLD_CHANGED', 'GO_ERP_MODE_RECONCILIATION_ROLLBACK_REQUIRES_REVIEW', 'GO_ERP_MODE_RECONCILIATION_ROOT_AND_HOST_REQUIRED'])


def main(arguments=None):
    parser=argparse.ArgumentParser();parser.add_argument('--package',required=True)
    parser.add_argument('--mode',choices=('reconcile','status','authorize','verify'),required=True);parser.add_argument('--receipt')
    args=parser.parse_args(arguments)
    if (args.mode=='authorize') != bool(args.receipt):parser.error('authorize requires its receipt')
    try:
        result=execute(args.package,args.mode,args.receipt)
    except BaseException as error:
        reason=error.args[0] if type(error) is Stop and len(error.args)==1 and type(error.args[0]) is str else None
        result={'ok':False,'reason':reason if reason in PUBLIC_REASONS else 'GO_ERP_MODE_RECONCILIATION_PRIVATE_FAILURE',
                'rollback_complete':getattr(error,'rollback_complete',True) is True}
        if reason in PUBLIC_REASONS:
            trace=error.__traceback__
            while trace is not None and trace.tb_next is not None:trace=trace.tb_next
            if trace is not None and trace.tb_frame.f_globals is globals():
                result['diagnostic']={'type':'Stop','file':'upgrade_go_erp_mode_inventory_root.py','line':trace.tb_lineno,'site':'raise'}
        try:
            old=_LOADED_PRIOR or load_prior(Path(args.package))
            details,foreign=old.safe_error_details(error,args.package)
            if reason not in PUBLIC_REASONS:
                result['reason']=foreign;result['diagnostic']=details
        except BaseException:pass
    result.update(format=1,kind='go-erp-mode-inventory-reconciliation',application_contents_changed=False,
                  modes_changed=False,database_changed=False,state_changed=False,configuration_changed=False,
                  credentials_disclosed=False)
    print(json.dumps(result,sort_keys=True))
    return 0 if result.get('ok') is True else 78 if result.get('rollback_complete') is False else 1


if __name__=='__main__':raise SystemExit(main())
