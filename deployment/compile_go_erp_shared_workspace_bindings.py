#!/usr/bin/env python3
"""Offline, deterministic release binding. No fetch, git write, server or build.

seed-files emits exactly three reviewable changes from an accepted final core.
bindings verifies the actual seed commit before computing all dependent hashes.
Materialization additionally requires the parent's reviewed final-core CI receipt;
it writes review files only and never prints a production invocation.
"""
import argparse
import ast
import base64
import hashlib
import importlib.util
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import zlib

HERE=Path(__file__).resolve().parent
POLICY='historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
SHORT_POLICY=POLICY.removeprefix('historical-baseline-v2-')
POLICY_FILES=('.github/workflows/go-erp-update-tests.yml','deployment/verify_go_erp_preview31_seed.py',
    'tests/test_go_erp_preview31_seed.py')
BASELINES={'59':'5becb9bab38a91998d1b1341670b2b398e4ba70f','60':'be797004831cbd8ccfb7c011aac855d6c2964829'}
CACHE_SHA='b2b902c8dbcd2b0c6b7c0c5450ab2dda73da4c227b24e0e3def1d0e3489ec918'
REV=re.compile(r'[a-f0-9]{40}\Z');SHA=re.compile(r'[a-f0-9]{64}\Z')
STARTUP={'helper':'desktop-pos/src/startup-source-upgrade.cjs','fixture':'desktop-pos/test/generation-native.cjs',
    'nativeModule':'desktop-pos/test/startup-source-upgrade-native.cjs'}
WORKFLOWS=('desktop-dashboard-tests.yml','go-erp-update-tests.yml','whatsapp-integrated-regressions.yml',
    'go-erp-shared-workspace-tests.yml','go-erp-shared-workspace-native.yml')

class Stop(Exception):pass
def need(value,code):
    if not value:raise Stop('GO_ERP_SHARED_WORKSPACE_BINDING_'+code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()+b'\n'
def blob(raw):return hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
def module(name):
    path=HERE/name;spec=importlib.util.spec_from_file_location('workspace_binding_'+path.stem,path)
    value=importlib.util.module_from_spec(spec);sys.modules[spec.name]=value;spec.loader.exec_module(value);return value

class Git:
    def __init__(self,path):self.reader=module('go_erp_shared_workspace_plan.py').Git(path)
    def run(self,*args):return self.reader.run(*args)
    def commit(self,revision):self.reader.commit(revision)
    def files(self,revision):return self.reader.files(revision)
    def file(self,path,revision):return self.run('show',revision+':'+path)

def constants(raw):
    tree=ast.parse(raw.decode());values={}
    for name in ('CORE','POLICY'):
        rows=[node for node in tree.body if isinstance(node,ast.Assign) and len(node.targets)==1
            and isinstance(node.targets[0],ast.Name) and node.targets[0].id==name]
        need(len(rows)==1 and isinstance(rows[0].value,ast.Constant) and isinstance(rows[0].value.value,str),'SEED_CONSTANT_CONTEXT')
        values[name]=rows[0].value.value
    need(REV.fullmatch(values['CORE']) and values['POLICY'].startswith('native-close-reopen-program-contract-'),'SEED_OLD_BINDING_INVALID')
    return values

def seed_files(git,core):
    git.commit(core);original={name:git.file(name,core) for name in POLICY_FILES}
    old=constants(original[POLICY_FILES[1]]);output=dict(original)
    for name,value in (('CORE',core),('POLICY',SHORT_POLICY)):
        pattern=rb'(?m)^'+name.encode()+rb" = '"+re.escape(old[name].encode())+rb"'$"
        need(len(re.findall(pattern,output[POLICY_FILES[1]]))==1,'SEED_ASSIGNMENT_CONTEXT')
        output[POLICY_FILES[1]]=re.sub(pattern,(name+' = '+repr(value)).encode(),output[POLICY_FILES[1]])
        anchor=('self.assertEqual(policy.'+name+', '+repr(old[name])+')').encode()
        need(output[POLICY_FILES[2]].count(anchor)==1,'SEED_TEST_CONTEXT')
        output[POLICY_FILES[2]]=output[POLICY_FILES[2]].replace(anchor,('self.assertEqual(policy.'+name+', '+repr(value)+')').encode())
    anchor=('      - name: Load the exact reviewed private import diagnostic core\n'
        '        run: git -c gc.auto=0 fetch --quiet --depth=16 --no-tags --no-recurse-submodules origin '+old['CORE']).encode()
    need(output[POLICY_FILES[0]].count(anchor)==1,'DIAGNOSTIC_FETCH_CONTEXT')
    output[POLICY_FILES[0]]=output[POLICY_FILES[0]].replace(anchor,anchor.replace(old['CORE'].encode(),core.encode()))
    need(all(original[name]!=output[name] for name in POLICY_FILES),'SEED_MUST_CHANGE_EXACT_THREE_FILES')
    for name in POLICY_FILES:
        if name.endswith('.py'):compile(output[name],name,'exec')
    return output

def verify_seed(git,core,seed):
    git.commit(seed);header=git.run('cat-file','commit',seed).split(b'\n\n',1)[0]
    need([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')]==[core],'SEED_SINGLE_CORE_PARENT_REQUIRED')
    expected=seed_files(git,core)
    rows=git.run('diff-tree','-r','--raw','--no-abbrev','--no-renames',core,seed).decode().splitlines()
    paths=[]
    for row in rows:
        match=re.fullmatch(r':100644 100644 [a-f0-9]{40} [a-f0-9]{40} M\t(.+)',row)
        need(match is not None,'SEED_MODE_OR_OPERATION_CHANGED');paths.append(match[1])
    need(set(paths)==set(POLICY_FILES) and len(paths)==3,'SEED_EXACT_THREE_FILES_REQUIRED')
    need(all(git.file(name,seed)==raw for name,raw in expected.items()),'SEED_BYTES_DIFFER_FROM_REVIEWED_TRANSFORM')
    return {'core':core,'seed':seed,'policy_files':{name:{'blob':blob(raw),'sha256':sha(raw)} for name,raw in expected.items()}}

def source_plan(git,baseline,web):
    plan=module('go_erp_shared_workspace_plan.py');source=module('go_erp_shared_workspace_source_root.py')
    git.commit(baseline);git.commit(web);before,after=git.files(baseline),git.files(web);paths={}
    for name in sorted(set(before)|set(after)):
        if before.get(name)==after.get(name):continue
        # Release-only test/workflow branches can have different rosters; none
        # is ever installed into the web root by this source transaction.
        if not name.startswith(('app/','bootstrap/','config/','database/','desktop/','public/','resources/','routes/')) and name!='artisan':continue
        plan.path_scope(name);need(name in after,'PRODUCTION_SOURCE_DELETION')
        # Historical PHP/view Git blobs include executable bits. Only the
        # reviewed new blob must be nonexecutable; deployment independently
        # captures and preserves the live file's validated 0640/0644 mode.
        need(after[name][:2]==('100644','blob') and (name not in before
            or before[name][:2] in (('100644','blob'),('100755','blob'))),'SOURCE_MODE_CHANGED')
        paths[name]={'before':None if name not in before else sha(git.file(name,baseline)),'after':sha(git.file(name,web))}
    need(set(name for name in paths if name.startswith('database/'))==set(plan.MIGRATIONS)
        and all(paths[name]['before'] is None for name in plan.MIGRATIONS),'EXACT_ADDITIVE_MIGRATIONS_REQUIRED')
    dirs=lambda files:{str(parent) for name in files for parent in PurePosixPath(name).parents if str(parent)!='.'}
    new=sorted(dirs(after)-dirs(before),key=lambda name:(name.count('/'),name))
    new=[name for name in new if any(path.startswith(name+'/') for path in paths)]
    order=sorted((name for name,row in paths.items() if row['before'] is not None),key=source.replacement_priority)
    payloads={name:git.file(name,web) for name in paths}
    source.checked_plan(Path('/home/fasakha/public_html'),web,1001,1003,paths,payloads,new,order)
    config=git.file('config/desktop_dashboard.php',web)
    need(re.search(rb"['\"]workspace_enabled['\"]\s*=>\s*env\(\s*['\"]DESKTOP_DASHBOARD_WORKSPACE_ENABLED['\"]\s*,\s*false\s*\)",config),'FLAG_DEFAULT_MUST_BE_FALSE')
    return {'paths':paths,'new_directories':new,'replacement_order':order}

def workspace_routes(git,web):
    provider=git.file('app/Providers/RouteServiceProvider.php',web)
    need(provider.count(b"Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));")==1
        and provider.count(b"Route::middleware('web')->group(base_path('routes/desktop_dashboard.php'));")==1,'ROUTE_PROVIDER_CONTEXT')
    result=[]
    for name,entry,prefix,namespace in (
        ('routes/desktop_workspace.php','routes/desktop_dashboard.php','admin/desktop-dashboard/workspace','Dashboard'),
        ('routes/desktop_workspace_api.php','routes/api.php','desktop-dashboard/workspace','Api')):
        raw=git.file(name,web).decode()
        need(git.file(entry,web).count(("require __DIR__.'"+'/'+PurePosixPath(name).name+"';").encode())==1,'ROUTE_INCLUDE_CONTEXT')
        controller='App\\Http\\Controllers\\'+namespace+'\\DesktopDashboardWorkspaceController'
        need(raw.count('use '+controller+';')==1 and raw.count("->prefix('"+prefix+"')")==1,'ROUTE_GROUP_CONTEXT')
        rows=re.findall(r"Route::(get|post)\('([a-z-]+)',\[DesktopDashboardWorkspaceController::class,'([A-Za-z]+)'\]\)",raw)
        need(len(rows)==(4 if namespace=='Dashboard' else 7) and raw.count('Route::')==len(rows)+1,'ROUTE_ROSTER_CONTEXT')
        for method,uri,action in rows:
            result.append([('api/' if namespace=='Api' else '')+prefix+'/'+uri,
                ['GET','HEAD'] if method=='get' else ['POST'],controller+'@'+action])
    return sorted(result,key=lambda value:json.dumps(value,separators=(',',':')).replace('/','\\/'))

NODE_ROSTER=r'''const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const input=JSON.parse(fs.readFileSync(0,'utf8')), exports={};
const virtual=Object.freeze({readdirSync:dir=>{assert.equal(dir,'/pinned/desktop-pos/src');return input.names;}});
const context={module:{exports},exports,process:Object.freeze({platform:'binding',env:Object.freeze({})}),__dirname:'/pinned/.github/scripts'};
context.require=name=>{if(name==='node:fs')return virtual;if(name==='node:path')return path;
 if(name==='node:crypto')return require('node:crypto');if(name==='node:assert/strict')return assert;
 if(name==='../../desktop-pos/src/dashboard-source.cjs')return Object.freeze({valid:()=>false,same:()=>false});
 throw Error('Unreviewed verifier import: '+name);};
vm.createContext(context);vm.runInContext(input.code,context,{timeout:1000});
vm.runInContext('answer=module.exports.sourceFiles("/pinned")',context,{timeout:1000});
process.stdout.write(JSON.stringify(context.answer));'''

def proof_maps(git,core):
    files=git.files(core)
    names=sorted(name.removeprefix('desktop-pos/src/') for name in files
        if re.fullmatch(r'desktop-pos/src/[^/]+\.cjs',name))
    need({'main.cjs','workspace-native.cjs','workspace-policy.cjs'}<=set(names),'COMPLETE_ASAR_ROSTER_REQUIRED')
    need(all(files['desktop-pos/src/'+name][:2]==('100644','blob') for name in names),'ASAR_SOURCE_MODE_CHANGED')
    verifier='.github/scripts/verify-workspace-runtime-native.cjs';code=git.file(verifier,core)
    node=shutil.which('node');need(node is not None,'NODE_REQUIRED_FOR_PINNED_ROSTER')
    run=subprocess.run([node,'-e',NODE_ROSTER],input=canonical({'code':code.decode(),'names':names}),
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10,env={'PATH':'/usr/bin:/bin'})
    need(run.returncode==0 and len(run.stdout)<=128*1024,'PINNED_SOURCE_ROSTER_FAILED')
    roster=json.loads(run.stdout)
    need(isinstance(roster,list) and len(roster)==len(set(roster)) and all(name in files and files[name][:2]==('100644','blob') for name in roster),
        'NATIVE_PROOF_ROSTER_CHANGED')
    lock=json.loads(git.file('desktop-pos/package-lock.json',core));electron=lock['packages']['node_modules/electron']['version']
    need(isinstance(electron,str) and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',electron),'ELECTRON_VERSION_REQUIRED')
    contract=git.file('desktop/workspace-contract.json',core);programs=json.loads(contract)['programs']
    acceptance=module('go_erp_shared_workspace_acceptance_root.py')
    need(set(programs)==acceptance.PROGRAMS and all(sha(git.file(name,core))==pin for name,pin in programs.items()),'FIVE_PROGRAM_CONTRACT_CHANGED')
    value={'contract_sha256':sha(contract),'program_hashes':programs,'electron_version':electron,
        'workflow_sha256':sha(git.file('.github/workflows/'+acceptance.NATIVE_WORKFLOW,core)),
        'foundation_workflow_sha256':sha(git.file('.github/workflows/'+acceptance.FOUNDATION_WORKFLOW,core)),
        'verifier_sha256':sha(code),'source_hashes':{name:sha(git.file(name,core)) for name in roster}}
    acceptance.WorkspaceAcceptance(None,None,Path('/unused'),lambda:True,pins=value)._approved()
    return ({name:sha(git.file(path,core)) for name,path in STARTUP.items()},
        {name:sha(git.file('desktop-pos/src/'+name,core)) for name in names},value)

def previous_payloads():
    raw=(HERE/'pins/shared_workspace_previous_native_59.json').read_bytes();need(sha(raw)==CACHE_SHA,'LEGACY_CACHE_CHANGED')
    value=json.loads(raw);need(value['operator_revision']=='33e08c9fec93501d2376eb88d9fd402043f8d792','LEGACY_CACHE_REVISION')
    # Each stream is bounded independently; this cached public predecessor was
    # verified against all seven original blob/size/SHA triples when exported.
    result={}
    for name,encoded in value['payloads'].items():
        compressed=base64.b64decode(encoded,validate=True);reader=zlib.decompressobj()
        data=reader.decompress(compressed,2*1024**2+1)
        need(len(data)<=2*1024**2 and reader.eof and not reader.unused_data and not reader.unconsumed_tail,'LEGACY_CACHE_LIMIT')
        result[name]=data
    need(len(result)==7,'LEGACY_CACHE_ROSTER');return result

def prerequisites():
    value=json.loads((HERE/'pins/shared_workspace_prerequisites_r13.json').read_bytes())
    raw=(HERE/'pins/shared_workspace_schema_accepted_r13.json').read_bytes();accepted=json.loads(raw)
    proof=value['schema_provenance']
    need(sha(raw)==proof['member_sha256'] and sha(canonical(value['migration_schemas']))
        ==proof['semantic_schemas_sha256']==accepted['semantic_schemas_sha256']
        and canonical(value['migration_schemas'])==canonical(accepted['schemas'])
        and canonical(value['migration_hashes'])==canonical(accepted['migration_hashes'])
        and value['migration_template_sha256']==proof['helper_sha256']==accepted['helper_sha256']
        and value['source_revision']==proof['source_revision']==accepted['source_revision'],
        'ACCEPTED_SCHEMA_PREREQUISITES_CHANGED')
    return value

def bindings(git,core,seed,web=None):
    root=module('go_erp_shared_workspace_root.py');native=module('go_erp_shared_workspace_native_root.py')
    proof=verify_seed(git,core,seed);web=web or core;git.commit(web)
    need(git.run('diff-tree','-r','--no-commit-id','--name-only','--no-renames',web,core,'--',*native.BACKEND)==b'','WEB_BACKEND_DIFFERS_FROM_CORE')
    startup,asar,acceptance=proof_maps(git,core)
    prereq=prerequisites()
    need(acceptance['contract_sha256']==prereq['contract_sha256'],'REVIEWED_CONTRACT_CHANGED')
    for name,pin in prereq['migration_hashes'].items():need(sha(git.file(name,web))==pin,'REVIEWED_MIGRATION_BYTES_CHANGED')
    payloads=module('go_erp_shared_workspace_payloads.py').native(previous_payloads(),module('go_erp_owner_fast_open_payloads.py'),
        seed=seed,startup_hashes=startup,packaged_native_hashes=asar)
    pins={'core':core,'seed':seed,'web_release':web,'policy':POLICY,'contract_sha256':acceptance['contract_sha256'],
        'helpers':{role:sha((HERE/name).read_bytes()) for role,name in root.ROLES.items()},
        'source_plans':{counter:source_plan(git,base,web) for counter,base in BASELINES.items()},
        'routes':workspace_routes(git,web),'route_cache_path':'bootstrap/cache/routes-v7.php',
        'migration_schemas':prereq['migration_schemas'],'migration_hashes':prereq['migration_hashes'],
        'migration_template_sha256':sha((HERE/'go_erp_shared_workspace_migration.php').read_bytes()),
        'native_payloads':{name:{'blob':blob(raw),'size':len(raw),'sha256':sha(raw)} for name,raw in payloads.items()},
        'startup_hashes':startup,'packaged_native_hashes':asar,'acceptance':acceptance,
        'legacy_entry_sha256':sha(git.file('deployment/upgrade_go_erp_owner_fast_open_root.py',root.LEGACY_RELEASE))}
    root.checked_pins(pins);need(pins['migration_template_sha256']==prereq['migration_template_sha256'],'ACCEPTED_SQL_HELPER_CHANGED')
    # Reuse the actual loader's complete public package checks without its
    # constructor, private filesystem, original Context, locks or actuators.
    operator=root.Operator.__new__(root.Operator);operator.pins=pins;operator.package=type('ReadOnlyPackage',(),{
        'git':lambda self,*args:git.run(*args),'file':lambda self,name,revision=None:
            (HERE.parent/name).read_bytes() if revision is None else git.file(name,revision)})()
    operator.modules={role:module(name) for role,name in root.ROLES.items()};operator.io=operator.modules['source'];operator.states=operator.modules['states']
    operator._validate_package()
    return pins,proof

def checked_approval(value,core):
    need(isinstance(value,dict) and value.get('kind')=='go-erp-reviewed-final-core-acceptance'
        and value.get('core')==core and value.get('all_latest_required_ci_accepted') is True
        and value.get('actual_native_artifact_strictly_verified') is True
        and value.get('installed_asar_artifact_strictly_verified') is True
        and isinstance(value.get('workflows'),dict) and set(value['workflows'])==set(WORKFLOWS),
        'FINAL_CORE_REVIEWED_ACCEPTANCE_REQUIRED')
    for name,row in value['workflows'].items():
        need(isinstance(row,dict) and row.get('head_sha')==core and row.get('event')=='push'
            and row.get('conclusion')=='success' and row.get('status')=='completed'
            and row.get('path')=='.github/workflows/'+name and type(row.get('id')) is int and row['id']>0
            and type(row.get('attempt')) is int and row['attempt']>0,'FINAL_CORE_CI_IDENTITY_REQUIRED')
    need(all(isinstance(value.get(name),str) and SHA.fullmatch(value[name]) and value[name]!='0'*64 for name in
        ('native_receipt_sha256','native_artifact_sha256','installed_asar_receipt_sha256')),'FINAL_CORE_RECEIPT_HASHES_REQUIRED')

def write_files(folder,files):
    need(not folder.exists(),'OUTPUT_MUST_BE_NEW');folder.mkdir(parents=True,mode=0o700)
    for name,raw in files.items():
        path=folder/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw)

def main(arguments=None):
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('mode',choices=('seed-files','bindings'))
    parser.add_argument('--git-dir',required=True);parser.add_argument('--core',required=True)
    parser.add_argument('--seed');parser.add_argument('--web');parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--materialize',action='store_true');parser.add_argument('--approval',type=Path);args=parser.parse_args(arguments)
    need(REV.fullmatch(args.core) and args.core!='0'*40,'FINAL_CORE_UNAVAILABLE');git=Git(args.git_dir)
    if args.mode=='seed-files':
        need(not args.materialize and args.seed is None,'SEED_STAGE_ONLY')
        files=seed_files(git,args.core);manifest={'format':1,'kind':'go-erp-shared-workspace-seed-plan','required_parent':args.core,
            'policy':SHORT_POLICY,'files':{name:{'mode':'100644','blob':blob(raw),'sha256':sha(raw)} for name,raw in files.items()},
            'seed_revision':None,'production_effects_allowed':False}
        files['seed-plan.json']=canonical(manifest);write_files(args.output,files)
    else:
        need(args.seed is not None and REV.fullmatch(args.seed) and args.seed!='0'*40,'ACTUAL_SEED_UNAVAILABLE')
        pins,lineage=bindings(git,args.core,args.seed,args.web)
        files={'root-pins.json':canonical(pins),'seed-lineage.json':canonical(lineage)}
        if args.materialize:
            need(args.approval is not None,'FINAL_CORE_REVIEWED_ACCEPTANCE_REQUIRED')
            approval=json.loads(args.approval.read_bytes());checked_approval(approval,args.core)
            raw=(HERE/'go_erp_shared_workspace_root.py').read_bytes();need(raw.count(b'PINS = None\n')==1,'ROOT_PIN_ANCHOR_CHANGED')
            files['deployment/go_erp_shared_workspace_root.py']=raw.replace(b'PINS = None\n',('PINS = '+repr(pins)+'\n').encode())
            shell=(HERE/'run_go_erp_shared_workspace_root.sh').read_bytes()
            for name,value in (('core',args.core),('seed',args.seed),('web',args.web or args.core)):
                anchor=('go_workspace_'+name+"=''\n").encode();need(shell.count(anchor)==1,'WRAPPER_PIN_ANCHOR_CHANGED')
                shell=shell.replace(anchor,('go_workspace_'+name+'='+repr(value)+'\n').encode())
            files['deployment/run_go_erp_shared_workspace_root.sh']=shell
            files['reviewed-core-acceptance.json']=canonical(approval)
        files['binding-manifest.json']=canonical({'format':1,'kind':'go-erp-shared-workspace-binding-output',
            'core':args.core,'seed':args.seed,'web_release':args.web or args.core,'materialized':args.materialize,
            'files':{name:sha(raw) for name,raw in files.items()},'server_effects_performed':False,
            'candidate_allocated':False,'installer_published':False,'production_command_emitted':False})
        write_files(args.output,files)
    print('GO_ERP_SHARED_WORKSPACE_BINDING_FILES_PREPARED')

if __name__=='__main__':
    try:main()
    except Stop as error:raise SystemExit(str(error))
