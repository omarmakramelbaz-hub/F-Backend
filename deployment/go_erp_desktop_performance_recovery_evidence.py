#!/usr/bin/env python3
"""Read-only evidence for the single rejected, unallocated seed correction.

Private Python is never executed. The original authenticated source engine is
rehydrated only after its committed WAL is proved to exist, so its committed
apply branch can verify the source inodes without constructing a fresh intent.
The caller holds the original locks and keeps these evidence bytes private.
"""
import ast
import hashlib
import json
import os
from pathlib import Path
import re
from types import FunctionType

CORE='43b1498938d4550a4c63c267dbcde12b0bab7e81'
BAD_SEED='11c739039e5c6320b3542d414682d755c7902790'
CURRENT_SEED='dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
CURRENT_CORE='052e69008da7e330815b376824cd8afc18cf04c1'
SOURCE_PLAN='0db4906a3792068774a18b79c550697cfa5fca6b35160dec70bd7a3ce214a9d2'
MANUAL_ID='64c479503f586268c4d191f2b85a2963d6e0473933cfea4cf09411f90bb189ea'
BAD_MANUAL_PINS={
 'go_erp_manual_tick_root.py':'4ea08fc1ffa3113c14a964931869a7016b69619e45ba05f272929c36a736b911',
 'go_erp_manual_updates_root.py':'c8304a4f3ab4a4a8160e4bf687fa129343f53031fb38d695c398195ac12ed624',
 'go_erp_published_download_root.py':'2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'}
WORK=Path('/var/lib/go-erp-live-updates')
PRIVATE=Path('/var/lib/go-erp-manual-updates/private')
AUDIT=PRIVATE/'audits'/('desktop-performance-'+CORE)
MANUAL_RELEASES=Path('/opt/go-erp-manual-updates/releases')
SYSTEMD=Path('/etc/systemd/system')
CONFIG=Path('/etc/go-erp/live-updates.json')
REMOTE='https://github.com/omarmakramelbaz-hub/F-Backend.git'


class Stop(Exception):pass


def need(value,code):
    if not value:raise Stop('GO_ERP_DESKTOP_PERFORMANCE_EVIDENCE_'+code)


def sha(raw):return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()+b'\n'


def _read(r,path,maximum=16*1024**2,*,optional=False,modes=(0o600,)):
    result=r.e.read_file(path,maximum,uid=0,modes=modes,optional=optional)
    return None if result is None else result[0]


def _names(r,path,mode=0o700,maximum=4096):
    r.e.directory(path,0,0,mode,False)
    fd=r.io.parent_fd(path/'readonly-roster')
    try:
        before=r.io.identity(os.fstat(fd))
        names=os.listdir(fd)
        need(len(names)<=maximum,'DIRECTORY_ROSTER_LIMIT')
        need(before==r.io.identity(os.fstat(fd))==r.io.identity(path.lstat()),'DIRECTORY_CHANGED')
        return set(names)
    finally:os.close(fd)


def verifier_core(raw):
    """Accept one plain top-level string assignment; execute no staged code."""
    need(isinstance(raw,bytes) and 0<len(raw)<=2*1024**2,'VERIFIER_BYTES_INVALID')
    tree=ast.parse(raw.decode('utf-8'))
    values=[]
    for node in tree.body:
        if isinstance(node,ast.Assign) and any(isinstance(target,ast.Name) and target.id=='CORE' for target in node.targets):
            need(len(node.targets)==1 and isinstance(node.value,ast.Constant)
                 and isinstance(node.value.value,str),'VERIFIER_CORE_ASSIGNMENT_CHANGED')
            values.append(node.value.value)
        elif isinstance(node,ast.AnnAssign) and isinstance(node.target,ast.Name) and node.target.id=='CORE':
            raise Stop('GO_ERP_DESKTOP_PERFORMANCE_EVIDENCE_VERIFIER_CORE_ASSIGNMENT_CHANGED')
    need(len(values)==1 and re.fullmatch(r'[a-f0-9]{40}',values[0]),'VERIFIER_CORE_ASSIGNMENT_CHANGED')
    return values[0]


def _checkout(r,path,expected,*,verifier=False):
    r.io.directory(path,0,0)
    r.io.directory(path/'.git',0,0)
    package=type(r.package)(path/'.git',r.package.revision)
    head=package.git('rev-parse','HEAD').decode('ascii').strip()
    need(head==expected,'CHECKOUT_HEAD_CHANGED')
    need(package.git('remote','get-url','origin').decode('ascii').strip()==REMOTE,'CHECKOUT_REMOTE_CHANGED')
    need(package.git('-c','core.fsmonitor=false','-c','core.autocrlf=false',
         '-c','submodule.recurse=false','--work-tree='+str(path),
         'status','--porcelain','--untracked-files=all')==b'','CHECKOUT_DIRTY')
    if not verifier:return head,None
    name='deployment/verify_go_erp_preview31_seed.py'
    raw=package.git('show',head+':'+name)
    need(_read(r,path/name,2*1024**2,modes=(0o600,0o644))==raw,'STAGED_VERIFIER_CHANGED')
    return head,verifier_core(raw)


def _committed_source(r,verify):
    io=r.io
    plan=r.source.checked_plan(io,r.public_payloads)
    digest=sha(canonical(plan))
    need(digest==SOURCE_PLAN,'SOURCE_PLAN_CHANGED')
    audit=r.source.AUDITS/('workspace-source-'+digest)
    names=_names(r,audit)
    need('intent.json' in names,'COMMITTED_SOURCE_INTENT_MISSING')
    intent=_read(r,audit/'intent.json',2*1024**2)
    attempts=sorted(name for name in names if name.startswith('attempt-'))
    need(attempts and len(attempts)<=1000
         and attempts==['attempt-'+str(index).zfill(6) for index in range(len(attempts))],
         'SOURCE_ATTEMPT_SEQUENCE_CHANGED')
    attempt=audit/attempts[-1]
    roster=_names(r,attempt)
    need({'attempt.json','committed.json'}<=roster
         and not {'rolled-back.json','rollback-started.json'}&roster,'SOURCE_NOT_COMMITTED')
    _read(r,attempt/'attempt.json',2*1024**2)
    committed_raw=_read(r,attempt/'committed.json',2*1024**2)
    committed=io.parse(committed_raw)
    # Do not call Transaction.__init__: it owns creating new WAL records.
    transaction=object.__new__(io.Transaction)
    transaction.uid,transaction.gid=r.source.OWNER_UID,r.source.OWNER_GID
    transaction.root,transaction.audit_parent=r.source.ROOT,r.source.AUDITS
    transaction.payloads,transaction.pins=r.public_payloads,r.source.PINS
    transaction.checkpoint=lambda *_args:None
    transaction.verify_locks=verify
    transaction.plan,transaction.digest=plan,digest
    transaction.audit,transaction.attempt=audit,attempt
    def forbidden(*_args,**_kwargs):
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_EVIDENCE_SOURCE_WRITE_FORBIDDEN')
    # Even a root-side WAL disappearance between reads must not turn evidence
    # collection into a new write attempt. Keep the original instructions and
    # readers while removing their private-record fallback writer.
    original_intent=io.Transaction._intent
    readonly_intent=FunctionType(original_intent.__code__,
        dict(original_intent.__globals__,private_record=forbidden),
        original_intent.__name__,original_intent.__defaults__,original_intent.__closure__)
    transaction._file=transaction._stage=transaction._publish=forbidden
    need(io.load_record(audit/'intent.json')==io.parse(intent),'SOURCE_INTENT_CHANGED')
    transaction.intent=readonly_intent(transaction)
    need(_read(r,attempt/'committed.json',2*1024**2)==committed_raw,'SOURCE_COMMIT_CHANGED')
    result=transaction.apply()
    need(result.get('changed') is False and result.get('plan_sha256')==digest,'SOURCE_COMMITTED_BRANCH_CHANGED')
    return committed,digest


def _manual(r,bad_manual):
    need(isinstance(bad_manual,dict) and set(bad_manual)==set(BAD_MANUAL_PINS)
         and all(isinstance(raw,bytes) and sha(raw)==BAD_MANUAL_PINS[name] for name,raw in bad_manual.items()),
         'BAD_MANUAL_PAYLOAD_CHANGED')
    need(sha(canonical(dict(sorted(BAD_MANUAL_PINS.items()))))==MANUAL_ID,'BAD_MANUAL_RUNTIME_CHANGED')
    root=MANUAL_RELEASES/MANUAL_ID
    need(_names(r,root,0o555)==set(bad_manual),'MANUAL_ROSTER_CHANGED')
    for name,raw in bad_manual.items():
        need(_read(r,root/name,2*1024**2,modes=(0o444,))==raw,'MANUAL_BYTES_CHANGED')
    receipt=r.io.parse(_read(r,PRIVATE/'installation.json',8192))
    need(receipt.get('release')==r.package.revision,'MANUAL_RELEASE_CHANGED')
    units=r.e.units(root)
    need(set(units)=={'go-erp-manual-updates.path','go-erp-manual-updates.service'},'MANUAL_UNITS_CHANGED')
    hashes={name:sha(raw) for name,raw in units.items()}
    for name,raw in units.items():
        need(_read(r,SYSTEMD/name,65536,modes=(0o644,))==raw,'MANUAL_UNIT_BYTES_CHANGED')
    need(receipt.get('units')==hashes and receipt.get('runtime')==MANUAL_ID,'MANUAL_RECEIPT_CHANGED')
    return receipt,hashes


def _failed(r,state_raw,config_raw,unit_raw,verify):
    work_names=_names(r,WORK,maximum=20000)
    names=sorted(name for name in work_names if re.fullmatch(r'warm-start-seed-upgrade-[a-f0-9]{32}',name))
    need(len(names)<=256,'FAILED_AUDIT_COUNT_LIMIT')
    matches=[]
    for name in names:
        path=WORK/name
        contents=_names(r,path)
        before=_read(r,path/'before-config.bin',16384,optional=True)
        staged=_read(r,path/'exchange-config',16384,optional=True)
        if before is None or staged is None:continue
        before_value,staged_value=r.io.parse(before),r.io.parse(staged)
        if before_value.get('base_revision')!=CURRENT_SEED or staged_value.get('base_revision')!=BAD_SEED:continue
        need(before==config_raw and {k:v for k,v in before_value.items() if k!='base_revision'}
             =={k:v for k,v in staged_value.items() if k!='base_revision'},'FAILED_CONFIG_CHANGED')
        need(not {'intent.json','completed.json','rolled-back.json'}&contents,'FAILED_AUDIT_HAS_EFFECT_INTENT')
        need(_read(r,path/'before-state.bin')==state_raw
             and _read(r,path/'before-unit.bin',65536)==unit_raw,'FAILED_BEFORE_SLOTS_CHANGED')
        failures=sorted(entry for entry in contents if re.fullmatch(r'failed-[a-f0-9]{32}\.json',entry))
        need(0<len(failures)<=16,'FAILED_REPORT_COUNT_INVALID')
        reports=[r.io.parse(_read(r,path/entry,65536)) for entry in failures]
        need(all(canonical(report)==canonical(reports[0]) for report in reports),'FAILED_REPORTS_DIFFER')
        report=reports[0]
        need(report.get('audit_directory')==str(path) and report.get('phase')=='SEED_STAGE'
             and report.get('reason')=='SEED_CORE_MISMATCH','FAILED_REASON_CHANGED')
        head,core=_checkout(r,path/'exchange-checkout',BAD_SEED,verifier=True)
        need(core==CURRENT_CORE,'FAILED_VERIFIER_CORE_CHANGED')
        verify()
        # Repeated manual attempts create separate UUID audits before rejecting
        # the same seed. All matches above must be unconsumed and consistent;
        # use the newest verified failed record as the retained rejection proof.
        latest=max((path/entry).lstat().st_mtime_ns for entry in failures)
        matches.append((latest,str(path),report,head,core))
    need(0<len(matches)<=16,'FAILED_AUDIT_MATCH_COUNT_CHANGED')
    matches.sort(key=lambda row:(row[0],row[1]))
    return matches[-1][2:]


def collect(rollout,observation,bad_manual,verify,*,saved=None):
    """Return only private kwargs for the separately pinned pure recovery plan."""
    r=rollout
    need(callable(verify) and verify() is not False,'LOCK_REQUIRED')
    performance=(saved['performance_journal'] if saved is not None else r.flow)
    native_job=(saved['native_job'] if saved is not None else r.io.parse(_read(r,PRIVATE/('shared-workspace-'+CORE+'.json'))))
    need(performance.get('current')==r.current.record(observation),'OBSERVATION_CHANGED')
    checkpoint=_read(r,AUDIT/('checkpoint-'+sha(canonical(performance))+'.json'))
    need(checkpoint==canonical(performance),'PERFORMANCE_CHECKPOINT_CHANGED')
    state_raw=_read(r,WORK/'state/current.json')
    config_raw=_read(r,CONFIG,16384)
    unit_raw=_read(r,SYSTEMD/'go-erp-live-updates.service',65536,modes=(0o644,))
    native_audit=Path(native_job['audit'])
    need(re.fullmatch(re.escape(str(PRIVATE))+r'/audits/warm-start-[a-f0-9]{32}',str(native_audit)),
         'NATIVE_AUDIT_PATH_CHANGED')
    previous=_read(r,native_audit/'previous-state.json')
    captured=_read(r,native_audit/'captured-source.json')
    committed,source_digest=_committed_source(r,verify)
    receipt,unit_hashes=_manual(r,bad_manual)
    report,head,core=_failed(r,state_raw,config_raw,unit_raw,verify)
    _checkout(r,WORK/'checkout',CURRENT_SEED)
    hold=_read(r,WORK/'program-rollout-hold.json',optional=True)
    need(hold is None,'PENDING_PROGRAM_HOLD_PRESENT')
    need(verify() is not False,'LOCK_CHANGED')
    return dict(old_binding=r.bad_binding,new_binding=r.bind,performance_journal=performance,
        performance_checkpoint_raw=checkpoint,native_job=native_job,native_state_raw=state_raw,
        config_raw=config_raw,native_unit_sha256=sha(unit_raw),previous_state_raw=previous,
        captured_metadata_raw=captured,failed_report=report,intent_raw=None,pending_program_hold_raw=hold,
        staged_checkout_head=head,staged_verifier_core=core,source_commit=committed,
        source_plan_sha256=source_digest,bad_manual_pins=dict(BAD_MANUAL_PINS),manual_receipt=receipt,
        expected_bad_manual_unit_hashes=unit_hashes)


if __name__=='__main__':
    raise SystemExit('GO_ERP_DESKTOP_PERFORMANCE_EVIDENCE_USE_PINNED_COORDINATOR')
