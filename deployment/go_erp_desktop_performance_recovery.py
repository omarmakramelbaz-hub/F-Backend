#!/usr/bin/env python3
"""Pure source-adoption plan for one rejected, unallocated performance seed.

This module performs no filesystem, service, Git or application operations.
Its caller must read the supplied private records with the original no-follow
readers while holding all original locks, reprove the committed source WAL and
installed manual/runtime bytes, and prove the replacement policy seed before
creating a separately journaled adoption. Both old journals remain unchanged;
the original native Context alone creates the new native job. This plan is never a publication or
counter-allocation permit. No private record belongs in public diagnostic output.
"""
import copy
import hashlib
import json
import re

OLD_CORE='43b1498938d4550a4c63c267dbcde12b0bab7e81'
CORE='b5503e21e3af32eecaf0d2f9d68eee7d21be7439'
BAD_SEED='11c739039e5c6320b3542d414682d755c7902790'
NEW_SEED='d15942c947ac31c21a90b53856084a5de35d3cc3'
OLD_BINDING_SHA256='1ed9f2aeae05e6ad8196926de079192835422f76f0d998d2a4b3b2a3b8235242'
SOURCE_PLAN_SHA256='0db4906a3792068774a18b79c550697cfa5fca6b35160dec70bd7a3ce214a9d2'
BAD_MANUAL_RUNTIME='64c479503f586268c4d191f2b85a2963d6e0473933cfea4cf09411f90bb189ea'
CURRENT_CORE='052e69008da7e330815b376824cd8afc18cf04c1'
CURRENT_SEED='dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
CURRENT_CANDIDATE='d1976ce02c553939032241e7e724d26a9bbe7190'
CURRENT_INVENTORY='bb8960a67f6ef51039c33627e25cb509ba9bd455ef736c0787000340dbeaf99b'
INVENTORY='531ba5ab285bafc999930a78b9212640cf3c13fe83365da260ace931b49964b7'
WORK='/var/lib/go-erp-live-updates'
PRIVATE='/var/lib/go-erp-manual-updates/private'
CONFIG='/etc/go-erp/live-updates.json'
UNIT='/etc/systemd/system/go-erp-live-updates.service'
SHA=re.compile(r'[a-f0-9]{64}\Z')
REV=re.compile(r'[a-f0-9]{40}\Z')
JOB_KEYS=frozenset(('format','kind','core','seed','runtime','source','inventory',
    'published_inventory_at_entry','captured_metadata_sha256','previous_journal_sha256','audit',
    'remote_lease','phase','prepare_started','baseline_candidate','baseline_counter','baseline_remote',
    'candidate','version','transient_failures','public_nonce'))


class Stop(Exception):
    pass


def need(value,code):
    if not value:raise Stop('GO_ERP_DESKTOP_PERFORMANCE_RECOVERY_'+code)


def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()+b'\n'


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def parse(raw):
    def unique(pairs):
        result={}
        for key,value in pairs:
            need(key not in result,'DUPLICATE_PRIVATE_FIELD');result[key]=value
        return result
    need(isinstance(raw,bytes),'PRIVATE_BYTES_REQUIRED')
    value=json.loads(raw,object_pairs_hook=unique)
    need(isinstance(value,dict),'PRIVATE_OBJECT_REQUIRED')
    return value


def changed(before,after):
    need(set(before)==set(after),'FIELD_ROSTER_CHANGED')
    return {name for name in before if canonical(before[name])!=canonical(after[name])}


def runtime_name(pins):
    need(isinstance(pins,dict) and len(pins)==7,'NATIVE_ROSTER_CHANGED')
    value={}
    for name,row in pins.items():
        need(isinstance(row,(list,tuple)) and len(row)==3 and isinstance(row[0],str)
             and REV.fullmatch(row[0]) and type(row[1]) is int and row[1]>0
             and isinstance(row[2],str) and SHA.fullmatch(row[2]),'NATIVE_PIN_CHANGED')
        value[name]={'blob':row[0],'sha256':row[2]}
    return 'policy-'+sha(json.dumps(value,sort_keys=True,separators=(',',':')).encode())


def plan(*,old_binding,new_binding,performance_journal,performance_checkpoint_raw,
         native_job,native_state_raw,config_raw,native_unit_sha256,
         previous_state_raw,captured_metadata_raw,failed_report,intent_raw,
         pending_program_hold_raw,staged_checkout_head,staged_verifier_core,
         source_commit,source_plan_sha256,bad_manual_pins,manual_receipt,
         expected_bad_manual_unit_hashes):
    """Return one new performance journal, refusing consumed or mixed cases."""
    need(isinstance(old_binding,dict) and sha(canonical(old_binding))==OLD_BINDING_SHA256
         and old_binding.get('core')==OLD_CORE
         and old_binding.get('seed')==BAD_SEED and old_binding.get('web_release')==BAD_SEED,
         'OLD_BINDING_CHANGED')
    need(isinstance(new_binding,dict) and changed(old_binding,new_binding)=={'core','seed','native_payloads'}
         and new_binding['core']==CORE
         and new_binding['seed']==NEW_SEED,'REPLACEMENT_BINDING_CHANGED')
    old_pins,new_pins=old_binding['native_payloads'],new_binding['native_payloads']
    need(changed(old_pins,new_pins)=={'go_erp_live_source.py','go_erp_live_updates.py'},
         'REPLACEMENT_NATIVE_SCOPE_CHANGED')
    old_runtime,new_runtime=runtime_name(old_pins),runtime_name(new_pins)
    need(isinstance(performance_journal,dict) and type(performance_journal.get('format')) is int
         and performance_journal['format']==1
         and performance_journal.get('kind')=='go-erp-desktop-performance-journal'
         and performance_journal.get('phase')=='prepared'
         and performance_journal.get('binding_sha256')==sha(canonical(old_binding))
         and performance_journal.get('inventory_sha256')==INVENTORY
         and performance_checkpoint_raw==canonical(performance_journal),'PERFORMANCE_CHECKPOINT_CHANGED')
    old_observation=performance_journal['current']
    need(old_observation.get('candidate')==CURRENT_CANDIDATE
         and old_observation.get('version')=='0.4.4-preview.60'
         and old_observation['profile']['core']==CURRENT_CORE
         and old_observation['profile']['seed']==CURRENT_SEED,'CURRENT_OBSERVATION_CHANGED')
    hashes=old_observation['private_hashes']
    need(sha(native_state_raw)==hashes[WORK+'/state/current.json']
         # The worker writes UTF-8 JSON (ensure_ascii=False). The frozen
         # Context writes this parsed audit with engine.canonical, whose
         # ensure_ascii=True escapes the same Unicode source path names.
         and previous_state_raw==canonical(parse(native_state_raw))
         and sha(config_raw)==hashes[CONFIG]
         and native_unit_sha256==hashes[UNIT],'ORIGINAL_NATIVE_SLOTS_CHANGED')
    state,config=parse(native_state_raw),parse(config_raw)
    desired,published=state.get('desired') or {},state.get('published') or {}
    need(type(state.get('counter')) is int and state['counter']==60
         and state.get('base_revision')==config.get('base_revision')==CURRENT_SEED
         and state.get('last_remote_sha')==desired.get('commit')==published.get('commit')==CURRENT_CANDIDATE
         and desired.get('status')=='published' and desired.get('pushed') is True
         and desired.get('version')=='0.4.4-preview.60'
         and desired.get('inventorySha256')==published.get('inventorySha256')==CURRENT_INVENTORY,
         'COUNTER_OR_PUBLICATION_CHANGED')
    need(isinstance(native_job,dict) and set(native_job)==JOB_KEYS
         and type(native_job.get('format')) is int and native_job['format']==1
         and native_job['kind']=='go-erp-warm-start-rollout'
         and native_job['core']==OLD_CORE and native_job['seed']==BAD_SEED and native_job['runtime']==old_runtime
         and native_job['phase']=='preparing' and native_job['prepare_started'] is False
         and all(native_job[name] is None for name in ('candidate','version','baseline_candidate',
                                                       'baseline_counter','baseline_remote'))
         and type(native_job['transient_failures']) is int and native_job['transient_failures']==0,
         'ALLOCATION_OR_NATIVE_JOB_CHANGED')
    need(native_job['inventory']==INVENTORY and native_job['published_inventory_at_entry']==CURRENT_INVENTORY
         and native_job['remote_lease']==CURRENT_CANDIDATE
         and re.fullmatch(re.escape(PRIVATE)+r'/audits/warm-start-[a-f0-9]{32}',native_job['audit'])
         and re.fullmatch(r'[a-f0-9]{32}',native_job['public_nonce']), 'NATIVE_JOB_SOURCE_OR_AUDIT_CHANGED')
    fingerprint=native_job['source']
    need(isinstance(fingerprint,dict) and set(fingerprint)=={'format','files','framework','sha256'}
         and type(fingerprint['format']) is int and fingerprint['format']==1
         and type(fingerprint['files']) is int and 1<=fingerprint['files']<=20000
         and fingerprint['framework']=='8.83.29' and isinstance(fingerprint['sha256'],str)
         and SHA.fullmatch(fingerprint['sha256']),'NATIVE_SOURCE_FINGERPRINT_CHANGED')
    predecessor=performance_journal['predecessor']
    prior_path=PRIVATE+'/shared-workspace-'+CURRENT_CORE+'.json'
    need(native_job['previous_journal_sha256']==predecessor['private_hashes'][prior_path]
         and predecessor['predecessor']['candidate']==CURRENT_CANDIDATE
         and predecessor['predecessor']['counter']==60,'PREVIOUS_ACCEPTANCE_CHANGED')
    metadata=parse(captured_metadata_raw)
    need(sha(captured_metadata_raw)==native_job['captured_metadata_sha256']
         ==performance_journal['captured_metadata_sha256']
         and metadata.get('seedRevision')==CURRENT_SEED and metadata.get('inventorySha256')==INVENTORY,
         'CAPTURED_METADATA_CHANGED')
    need(isinstance(failed_report,dict) and type(failed_report.get('format')) is int and failed_report['format']==1
         and failed_report.get('kind')=='go-erp-preview31-watcher-upgrade'
         and failed_report.get('phase')=='SEED_STAGE' and failed_report.get('reason')=='SEED_CORE_MISMATCH'
         and failed_report.get('ok') is False and failed_report.get('rollback_complete') is True
         and not failed_report.get('recovery_required',False)
         and all(failed_report.get(name) is False for name in ('application_contents_changed','database_changed',
            'configuration_changed','state_changed','checkout_changed','unit_changed','published','credentials_disclosed'))
         and re.fullmatch(re.escape(WORK)+r'/warm-start-seed-upgrade-[a-f0-9]{32}',failed_report.get('audit_directory','')),
         'FAILED_AUDIT_REQUIRES_REVIEW')
    need(intent_raw is None and pending_program_hold_raw is None
         and staged_checkout_head==BAD_SEED and staged_verifier_core==CURRENT_CORE,
         'FAILED_STAGE_OR_PENDING_EFFECT_CHANGED')
    expected_commit={'format':1,'kind':'go-erp-shared-workspace-source-committed',
        'plan_sha256':source_plan_sha256,'attempt':source_commit.get('attempt'),
        'schema_changed':False,'financial_data_changed':False,'feature_activated':False,
        'allocated':False,'published':False}
    need(canonical(source_commit)==canonical(expected_commit) and type(source_commit.get('format')) is int
         and isinstance(source_commit.get('attempt'),str)
         and re.fullmatch(r'attempt-[0-9]{6}',source_commit['attempt'])
         and source_plan_sha256==SOURCE_PLAN_SHA256,
         'COMMITTED_SOURCE_WAL_CHANGED')
    need(isinstance(bad_manual_pins,dict) and set(bad_manual_pins)=={'go_erp_manual_tick_root.py',
         'go_erp_manual_updates_root.py','go_erp_published_download_root.py'}
         and all(isinstance(value,str) and SHA.fullmatch(value) for value in bad_manual_pins.values()),
         'FAILED_MANUAL_PIN_ROSTER_CHANGED')
    manual_id=sha(canonical(dict(sorted(bad_manual_pins.items()))))
    need(manual_id==BAD_MANUAL_RUNTIME and isinstance(manual_receipt,dict)
         and set(manual_receipt)=={'format','kind','release','runtime','units'}
         and type(manual_receipt.get('format')) is int and manual_receipt['format']==1
         and manual_receipt.get('kind')=='go-erp-manual-installation'
         and manual_receipt.get('release')==old_binding['original_release']
         and manual_receipt.get('runtime')==manual_id
         and manual_receipt.get('units')==expected_bad_manual_unit_hashes
         and set(expected_bad_manual_unit_hashes)=={'go-erp-manual-updates.path','go-erp-manual-updates.service'}
         and all(isinstance(value,str) and SHA.fullmatch(value) for value in expected_bad_manual_unit_hashes.values()),
         'FAILED_MANUAL_RECEIPT_CHANGED')
    next_performance=copy.deepcopy(performance_journal)
    next_performance['binding_sha256']=sha(canonical(new_binding))
    need(changed(performance_journal,next_performance)=={'binding_sha256'},'CORRECTION_SCOPE_CHANGED')
    return {'format':1,'kind':'go-erp-desktop-performance-owned-source-adoption',
        'core':CORE,'from_core':OLD_CORE,'from_seed':BAD_SEED,'to_seed':new_binding['seed'],
        'native_job_before_sha256':sha(canonical(native_job)),
        'performance_before_sha256':sha(canonical(performance_journal)),'performance_after':next_performance,
        'old_binding_sha256':sha(canonical(old_binding)),'new_binding_sha256':sha(canonical(new_binding)),
        'manual_receipt_before_sha256':sha(canonical(manual_receipt)),
        'failed_report_sha256':sha(canonical(failed_report)),
        'captured_metadata_sha256':sha(captured_metadata_raw),
        'manual_restore_required':False,'manual_transition_owned':True,
        'old_records_unchanged':True,'new_native_job_owned_by_original':True,
        'counter_allocation_allowed':False,'database_changed':False}


if __name__=='__main__':
    raise SystemExit('GO_ERP_DESKTOP_PERFORMANCE_RECOVERY_PURE_PLAN_ONLY')
