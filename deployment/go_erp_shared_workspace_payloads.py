#!/usr/bin/env python3
"""Exact derivation of the original collector/manual helpers for workspace v1.

Only cohort constants and the reviewed installed-ASAR proof predicate change.
All original ZIP/installer/CI/program/publication functions remain byte-identical.
No generated helper is installed or run by this module.
"""
import ast
import copy
import hashlib
from pathlib import Path
import re

PINS = None
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
SUFFIX = '-native-close-reopen-program-contract-shared-workspace-v1'
OLD_SEED = '0885a3b550f92df2b74bcd2c16d27954d8b44b7b'
OLD_SUFFIX = '-native-close-reopen-program-contract-direct-login-accounts-v4'
FAST_SUFFIX = '-native-close-reopen-program-contract-direct-login-accounts-v5'
OLD_WORKER_SHA = '2555209445b41641a1358d5a75d0dd9f501b8992510bfd7661573664b4a84f5f'
FAST_NORMALIZED_SHA = '8396ba45450979a60f4d5097ae4c82a5426859c7ab48f13de0305851340c6138'
OLD_SOURCE_SHA = '22cad7fc794e1153c79dc016554323335b02409645585fb53ce4e83c0b932453'
TICK_SHA = '2b4c0c501accfdf1d08dc0ec90206da1cf0a2a22b799e7eac723f3d7c816cdb6'
BROKER_SHA = 'ca2e2488956aa40a17ceb883a119117e72ba2f5f25935e9ee974d9f1cd989953'
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')

class Stop(Exception): pass
def need(value,code):
    if not value: raise Stop('GO_ERP_SHARED_WORKSPACE_PAYLOADS_'+code)
def sha(raw):return hashlib.sha256(raw).hexdigest()


def _normalize(raw):
    seed=rb"(?m)^PREVIEW31_SEED = '[a-f0-9]{40}'$"
    policy=rb"(?m)^PREVIEW31_POLICY = CANDIDATE_POLICY \+ '([^'\r\n]+)'$"
    need(isinstance(raw,bytes) and len(re.findall(seed,raw))==1 and len(re.findall(policy,raw))==1
        and re.findall(policy,raw)[0].decode() in (OLD_SUFFIX,FAST_SUFFIX,SUFFIX),'COHORT_CONTEXT_CHANGED')
    return re.sub(policy,("PREVIEW31_POLICY = CANDIDATE_POLICY + '"+OLD_SUFFIX+"'").encode(),
        re.sub(seed,("PREVIEW31_SEED = '"+OLD_SEED+"'").encode(),raw))


def _bind(raw,seed):
    need(isinstance(seed,str) and REV.fullmatch(seed) and seed!='0'*40,'SEED_REQUIRED')
    need(raw.count(OLD_SEED.encode())==1 and raw.count(OLD_SUFFIX.encode())==1,'BINDING_CONTEXT_CHANGED')
    return raw.replace(OLD_SEED.encode(),seed.encode()).replace(OLD_SUFFIX.encode(),SUFFIX.encode())


def native(payloads,performance_helper,*,seed,startup_hashes,packaged_native_hashes):
    """Converge accepted .59 and .60 payloads to the same reviewed new cohort."""
    need(isinstance(payloads,dict) and 'go_erp_live_updates.py' in payloads and 'go_erp_live_source.py' in payloads,
        'NATIVE_PAYLOADS_REQUIRED')
    need(isinstance(startup_hashes,dict) and set(startup_hashes)=={'helper','fixture','nativeModule'}
        and all(isinstance(value,str) and SHA.fullmatch(value) for value in startup_hashes.values()),'STARTUP_HASHES_REQUIRED')
    need(isinstance(packaged_native_hashes,dict) and {'main.cjs','workspace-policy.cjs','workspace-native.cjs'}<=set(packaged_native_hashes)
        and all(isinstance(name,str) and re.fullmatch(r'[A-Za-z0-9_.-]+\.cjs',name)
            and isinstance(value,str) and SHA.fullmatch(value) for name,value in packaged_native_hashes.items()),
        'EXACT_ASAR_NATIVE_MAP_REQUIRED')
    result=copy.deepcopy(payloads);worker=_normalize(result['go_erp_live_updates.py'])
    if sha(worker)==OLD_WORKER_SHA:
        worker=performance_helper.transform(worker)
    need(sha(worker)==FAST_NORMALIZED_SHA,'ORIGINAL_WORKER_CHANGED')
    tree=ast.parse(worker.decode());functions=[node for node in tree.body if isinstance(node,ast.FunctionDef)
        and node.name=='verify_installed_startup_source']
    need(len(functions)==1,'STARTUP_VERIFIER_CONTEXT_CHANGED')
    original=ast.get_source_segment(worker.decode(),functions[0]);updated=original
    anchor="'sourceHashes', 'installedAsarCode'"
    hashes="and value.get('sourceHashes') == "+repr(performance_helper.SOURCE_HASHES)
    need(updated.count(anchor)==updated.count(hashes)==1,'STARTUP_PROOF_CONTEXT_CHANGED')
    updated=updated.replace(anchor,"'sourceHashes', 'packagedNativeHashes', 'installedAsarCode'")
    updated=updated.replace(hashes,"and value.get('sourceHashes') == "+repr(startup_hashes)
        +"\n         and value.get('packagedNativeHashes') == "+repr(dict(sorted(packaged_native_hashes.items()))))
    worker=worker.replace(original.encode(),updated.encode())
    result['go_erp_live_updates.py']=_bind(worker,seed)
    source=_normalize(result['go_erp_live_source.py'])
    need(sha(source)==OLD_SOURCE_SHA,'ORIGINAL_SOURCE_COLLECTOR_CHANGED')
    result['go_erp_live_source.py']=_bind(source,seed)
    for name,raw in result.items():
        if name.endswith('.py'):compile(raw,name,'exec')
    return result


def _cohort(value):
    need(isinstance(value,dict) and set(value)=={'pins','seed','policy','runtime','unit_sha256'}
        and isinstance(value['seed'],str) and REV.fullmatch(value['seed'])
        and isinstance(value['policy'],str) and value['policy'].startswith('historical-baseline-v2')
        and isinstance(value['unit_sha256'],str) and SHA.fullmatch(value['unit_sha256'])
        and re.fullmatch(r'/opt/go-erp-live-updates/releases/policy-[a-f0-9]{64}',str(value['runtime']))
        and isinstance(value['pins'],dict) and value['pins'],'COHORT_INVALID')
    for name,row in value['pins'].items():
        need(re.fullmatch(r'[a-z0-9_]+\.(?:py|yml)',name) and isinstance(row,(list,tuple)) and len(row)==3
            and REV.fullmatch(row[0]) and type(row[1]) is int and 0<row[1]<=2*1024**2 and SHA.fullmatch(row[2]),
            'COHORT_FILE_PIN_INVALID')
    fields={key:item for key,item in value.items() if key!='runtime'}
    return repr(fields)[:-1]+", 'runtime': Path("+repr(str(value['runtime']))+")}"


def manual(tick_template,broker_template,*,previous,new):
    need(sha(tick_template)==TICK_SHA and sha(broker_template)==BROKER_SHA,'ORIGINAL_MANUAL_HELPERS_CHANGED')
    need(new.get('policy')==POLICY,'NEW_POLICY_REQUIRED')
    old_literal,new_literal=_cohort(previous),_cohort(new)
    replacements={'OLD_COHORT':old_literal,'NEW_COHORT':new_literal,'RUNTIME':'Path('+repr(str(new['runtime']))+')',
        'SEED':repr(new['seed']),'POLICY':repr(POLICY),'PINS':repr(new['pins']),'PINS_PENDING':'False'}
    raw=tick_template.decode();lines=raw.splitlines(keepends=True);found=[]
    for node in ast.parse(raw).body:
        if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) and node.targets[0].id in replacements:
            name=node.targets[0].id;found.append(name)
            lines[node.lineno-1:node.end_lineno]=[name+' = '+replacements[name]+'\n']+['']*(node.end_lineno-node.lineno)
    need(set(found)==set(replacements) and len(found)==len(replacements),'MANUAL_CONSTANT_CONTEXT_CHANGED')
    tick=''.join(lines).encode();compile(tick,'<shared-workspace-manual-tick>','exec')
    anchor=("TICK_SHA256 = '"+TICK_SHA+"'").encode()
    need(broker_template.count(anchor)==1,'BROKER_TICK_CONTEXT_CHANGED')
    broker=broker_template.replace(anchor,("TICK_SHA256 = '"+sha(tick)+"'").encode())
    compile(broker,'<shared-workspace-manual-broker>','exec')
    return {'go_erp_manual_tick_root.py':tick,'go_erp_manual_updates_root.py':broker}
