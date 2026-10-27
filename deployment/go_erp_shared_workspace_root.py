#!/usr/bin/env python3
"""Unified production root operator. Final release pins intentionally unavailable.

One command owns read-only predecessor discovery, source/schema preparation,
the original resumable native collector and the exact flag/cache/FPM activation.
No private server hash is a human prerequisite: trusted observations are captured
in root-private WAL and checked again before effects. Never run from web input.
"""
import argparse
import copy
from contextlib import ExitStack, contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys
import types

PINS = None
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1'
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
CONTROL = Path('/var/lib/go-erp-manual-updates')
PRIVATE = CONTROL / 'private'
CONFIG = Path('/etc/go-erp/live-updates.json')
TEMPLATE_RELEASE = '12d74b4f35737a2b1e271657b159137538c3edaf'
# The exact template pin is independently checked by native_root.configure too.
TEMPLATE_SHA = '70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861'
PHP_HANDLER_SHA = 'dc26aa53fb8c2636ff079a43d0f8062e75b837154315a7a3693b1e1e18828634'
LEGACY_RELEASE = '8db5adb538834a2152f74f6e55336223a4c95908'
ARCHIVE_RELEASE = '5628a33525e2af63b221f044eb0e650cb6a2b790'
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
CODE = re.compile(r'GO_ERP_[A-Z0-9_]{1,150}\Z')
ROLES = {'source':'go_erp_shared_workspace_source_root.py','states':'go_erp_shared_workspace_states.py',
    'plan':'go_erp_shared_workspace_plan.py','journal':'go_erp_shared_workspace_journal.py',
    'activation':'go_erp_shared_workspace_activation_root.py','migration':'go_erp_shared_workspace_migration_root.py',
    'native':'go_erp_shared_workspace_native_root.py','collector':'go_erp_shared_workspace_collector_root.py',
    'payloads':'go_erp_shared_workspace_payloads.py','acceptance':'go_erp_shared_workspace_acceptance_root.py'}


class Stop(Exception): pass
def need(value,code):
    if not value: raise Stop('GO_ERP_SHARED_WORKSPACE_ROOT_'+code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()+b'\n'


class Package:
    def __init__(self,repository,revision):
        self.repository=Path(repository).absolute();self.revision=revision
        need(REV.fullmatch(revision) is not None,'RELEASE_REQUIRED')
    def git(self,*arguments):
        result=subprocess.run(['/usr/bin/git','--git-dir='+str(self.repository),'-c','credential.helper=',
            '-c','core.hooksPath=/dev/null','-c','core.attributesFile=/dev/null',*arguments],
            stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=180,
            env={'PATH':'/usr/bin:/bin','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null',
                'GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1',
                'GIT_TERMINAL_PROMPT':'0','GIT_OPTIONAL_LOCKS':'0'})
        need(result.returncode==0 and len(result.stdout)<=16*1024**2,'PINNED_GIT_READ_FAILED')
        return result.stdout
    def file(self,name,revision=None):return self.git('show',(revision or self.revision)+':'+name)


def load(raw,name,expected):
    need(isinstance(raw,bytes) and isinstance(expected,str) and SHA.fullmatch(expected) and sha(raw)==expected,
        'MODULE_PIN_CHANGED')
    value=types.ModuleType('workspace_root_'+name);value.__file__='<pinned-workspace-'+name+'>'
    sys.modules[value.__name__]=value;exec(compile(raw,value.__file__,'exec'),value.__dict__)
    return value


def checked_pins(pins):
    need(isinstance(pins,dict) and set(pins)=={'core','seed','web_release','policy','contract_sha256','helpers',
        'source_plans','routes','route_cache_path','migration_schemas','migration_hashes','native_payloads',
        'startup_hashes','packaged_native_hashes','acceptance','legacy_entry_sha256','migration_template_sha256'},'FINAL_PINS_UNAVAILABLE')
    need(pins['policy']==POLICY and all(isinstance(pins[key],str) and REV.fullmatch(pins[key])
        and pins[key]!='0'*40 for key in ('core','seed','web_release'))
        and isinstance(pins['contract_sha256'],str) and SHA.fullmatch(pins['contract_sha256'])
        and all(isinstance(pins[key],str) and SHA.fullmatch(pins[key]) for key in
            ('legacy_entry_sha256','migration_template_sha256'))
        and isinstance(pins['helpers'],dict) and set(pins['helpers'])==set(ROLES)
        and all(isinstance(value,str) and SHA.fullmatch(value) for value in pins['helpers'].values())
        and isinstance(pins['source_plans'],dict) and set(pins['source_plans'])=={'59','60'}
        and pins['route_cache_path']=='bootstrap/cache/routes-v7.php'
        and isinstance(pins['routes'],list) and pins['routes'],'FINAL_RELEASE_BINDING_INVALID')
    return copy.deepcopy(pins)


def original_template(package):
    return load(package.file('deployment/upgrade_go_erp_warm_start_root.py',TEMPLATE_RELEASE),'original',TEMPLATE_SHA)


def verify_outer_locks(io):
    """Find original engine-owned lock FDs; never authorize from a lock filename."""
    for path in (PRIVATE/'install.lock',PRIVATE/'worker.lock'):
        expected=path.lstat();found=False
        need(stat.S_ISREG(expected.st_mode) and (expected.st_uid,expected.st_gid,stat.S_IMODE(expected.st_mode),expected.st_nlink)
            ==(0,0,0o600,1),'ORIGINAL_LOCK_UNSAFE')
        for item in os.listdir('/proc/self/fd'):
            if not item.isdigit():continue
            try:current=os.fstat(int(item))
            except OSError:continue
            if io.identity(current)==io.identity(expected):
                try:fcntl.flock(int(item),fcntl.LOCK_EX|fcntl.LOCK_NB)
                except OSError:continue
                found=True;break
        need(found,'ORIGINAL_LOCK_NOT_HELD')


class Operator:
    def __init__(self,package,pins):
        self.package=package;self.pins=checked_pins(pins)
        self.modules={name:load(package.file('deployment/'+filename),name,pins['helpers'][name])
            for name,filename in ROLES.items()}
        self.io=self.modules['source'];self.states=self.modules['states']
        self._validate_package()
        self.template=original_template(package)
        self.engine=self.template.module(package.file('deployment/apply_go_erp_manual_updates_root.py',self.template.BOOTSTRAP),
            'workspace_original_engine',self.template.ENGINE_SHA)
        self.engine.parents(package.repository)
        need(package.repository.resolve()==package.repository and package.repository.stat().st_uid==0
            and not package.repository.stat().st_mode&0o022,'PACKAGE_REPOSITORY_UNSAFE')
        self.utility=self.template.module(package.file('deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py',self.template.PRIOR),
            'workspace_original_utility',self.template.UTILITY_BLOB,True)
        self.base=self.template.module(package.file('deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py',self.template.PRIOR),
            'workspace_original_base',self.template.BASE_BLOB,True)
        self.audit=PRIVATE/'audits'/('shared-workspace-root-'+pins['core'])
        self.current=PRIVATE/('shared-workspace-root-'+pins['core']+'.json')
        self.flow=None

    def _validate_package(self):
        """Reject incomplete public release bindings before any server mutation."""
        p=self.pins
        need(self.package.git('diff-tree','-r','--no-commit-id','--name-only','--no-renames','--no-ext-diff',
            p['web_release'],p['core'],'--',*self.modules['native'].BACKEND)==b'','CORE_BACKEND_DIFFERS_FROM_WEB')
        for counter,source in p['source_plans'].items():
            need(isinstance(source,dict) and set(source)=={'paths','new_directories','replacement_order'},'SOURCE_PLAN_INVALID')
            payloads={name:self.package.file(name,p['web_release']) for name in source['paths']}
            self.io.checked_plan(ROOT,p['web_release'],1001,1003,source['paths'],payloads,
                source['new_directories'],source['replacement_order'])
            need(set(self.states.MIGRATIONS)<=set(source['paths']) and all(source['paths'][name]['before'] is None
                and source['paths'][name]['after']==p['migration_hashes'][name] for name in self.states.MIGRATIONS),
                'EXACT_NEW_MIGRATION_PINS_REQUIRED')
            profile=self.modules['native'].PROFILES[int(counter)]
            need(self.package.git('diff-tree','-r','--no-commit-id','--name-only','--no-renames','--no-ext-diff',
                profile['core'],p['core'],'--','composer.json','composer.lock')==b'','DEPENDENCY_GUARD_CHANGED')
        # Only structural validation uses placeholder identity here; no receipt
        # or runtime pin is synthesized or persisted. Actual DB identity is read
        # under the original locks immediately before the migration WAL exists.
        self.states.migration_intent(p['web_release'],sha(canonical(p['source_plans']['59'])),
            sha(b'pure-schema-validation-only'),p['migration_hashes'],p['migration_schemas'])
        acceptance=self.modules['acceptance'].WorkspaceAcceptance(self.io,None,Path('/unused'),lambda:True,pins=p['acceptance'])
        acceptance._approved()
        need(p['acceptance']['contract_sha256']==p['contract_sha256'],'ACCEPTANCE_CONTRACT_PIN_CHANGED')
        contract=self.package.file('desktop/workspace-contract.json',p['core'])
        need(sha(contract)==p['contract_sha256'] and self.io.parse(contract).get('programs')==p['acceptance']['program_hashes'],
            'PROGRAM_CONTRACT_CHANGED')
        for name,expected in p['acceptance']['program_hashes'].items():
            need(sha(self.package.file(name,p['core']))==expected,'PROGRAM_BYTES_CHANGED')
        for name,expected in {**p['acceptance']['source_hashes'],
                '.github/workflows/'+self.modules['acceptance'].NATIVE_WORKFLOW:p['acceptance']['workflow_sha256'],
                '.github/workflows/'+self.modules['acceptance'].FOUNDATION_WORKFLOW:p['acceptance']['foundation_workflow_sha256'],
                self.modules['acceptance'].VERIFIER:p['acceptance']['verifier_sha256']}.items():
            need(sha(self.package.file(name,p['core']))==expected,'ACCEPTANCE_SOURCE_BYTES_CHANGED')
        self._migration_template()

    @contextmanager
    def held(self):
        e,t=self.engine,self.template;t.quiet(e)
        with ExitStack() as stack:
            e.acquire(stack,PRIVATE/'install.lock')
            e.run(['/usr/bin/systemctl','stop',t.WATCH]);t.quiet(e)
            e.acquire(stack,PRIVATE/'worker.lock')
            with self.utility.watcher_lock(WORK) as watcher:
                def verify():
                    verify_outer_locks(self.io);need(watcher() is not False,'WATCHER_LOCK_CHANGED');t.quiet(e)
                verify();yield verify;verify()

    def _save(self,value,verify):
        self.io.private_directory(self.audit,verify)
        self.io.private_record(self.audit/('checkpoint-'+sha(canonical(value))+'.json'),value,verify)
        self.engine.atomic_write(self.current,canonical(value),0,0,0o600)
        self.flow=copy.deepcopy(value)

    def _load(self):
        raw=self.engine.read_file(self.current,2*1024**2,uid=0,modes=(0o600,),optional=True)
        if raw is None:return None
        value=self.io.parse(raw[0]);need(value.get('release_pins_sha256')==sha(canonical(self.pins)),'ROOT_CHECKPOINT_PINS_CHANGED')
        record=self.io.read(self.audit/('checkpoint-'+sha(canonical(value))+'.json'),0,0,private=True)[0]
        need(record==canonical(value),'ROOT_CHECKPOINT_RECORD_CHANGED');return value

    def _legacy_resume(self):
        """Run the already-authorized pinned .60 command, retaining its exact job."""
        raw=self.package.file('deployment/upgrade_go_erp_owner_fast_open_root.py',LEGACY_RELEASE)
        need(sha(raw)==self.pins['legacy_entry_sha256'],'LEGACY_RESUMER_PIN_CHANGED')
        module=load(raw,'legacy_resume',self.pins['legacy_entry_sha256'])
        saved=sys.argv
        try:
            sys.argv=['pinned-legacy-resume','--git-dir',str(self.package.repository),'--release',LEGACY_RELEASE,'--complete']
            module.main()
        finally:sys.argv=saved

    def _predecessor(self):
        with self.held() as verify:
            self.flow=self._load()
            if self.flow is not None:return
            observed=self.modules['native'].bootstrap_predecessor(self.package,self.engine,verify_locks=verify)
            if observed['status']=='accepted-published':
                self._save({'format':1,'kind':'go-erp-shared-workspace-root-checkpoint',
                    'release_pins_sha256':sha(canonical(self.pins)),'predecessor_observation':observed,
                    'phase':'observed','prepared':None,'activation':None},verify)
                return
            self.io.private_directory(self.audit,verify)
            self.io.private_record(self.audit/('legacy-observation-'+observed['observation_sha256']+'.json'),observed,verify)
        # Do not hold the original install/worker locks while the exact legacy
        # coordinator acquires them. Its own published .59/.60 gates remain intact.
        self._legacy_resume()
        with self.held() as verify:
            observed=self.modules['native'].bootstrap_predecessor(self.package,self.engine,verify_locks=verify)
            need(observed['status']=='accepted-published','LEGACY_CHECKPOINT_NOT_READY')
            self._save({'format':1,'kind':'go-erp-shared-workspace-root-checkpoint',
                'release_pins_sha256':sha(canonical(self.pins)),'predecessor_observation':observed,
                'phase':'observed','prepared':None,'activation':None},verify)

    def _handler(self):
        raw=self.package.file('deployment/go_erp_navigation_php_root.py',LEGACY_RELEASE)
        module=load(raw,'php_handler',PHP_HANDLER_SHA)
        return module.verify(self.engine)

    def _migration_template(self):
        raw=self.package.file('deployment/go_erp_shared_workspace_migration.php')
        need(sha(raw)==self.pins['migration_template_sha256'],'MIGRATION_TEMPLATE_PIN_CHANGED')
        return raw

    def _activation(self,binding,verify,plan_sha,pins_sha):
        module=self.modules['activation'];source=self._source_plan()
        program=self.modules['migration'].materialize_program(self.io,self._migration_template(),self._migration_gate())
        runner=module.ProcessRunner(verify,approved_program_hashes=frozenset((module.sha(module.CACHE_PROGRAM),module.sha(program))))
        self.io.private_directory(module.PRIVATE,verify)
        actor=module.Activation(self.io,self.modules['plan'],runner,self._handler,verify,
            compiled_pins={'module_sha256':self.pins['helpers']['activation'],'plan_sha256':plan_sha,'pins_sha256':pins_sha,
                'binding':binding,'source_files':{name:row['after'] for name,row in source['paths'].items()},
                'routes':self.pins['routes'],'route_cache_path':self.pins['route_cache_path']})
        return actor,runner

    def _source_plan(self):
        counter=self.flow['predecessor_observation']['predecessor']['counter']
        value=self.pins['source_plans'][str(counter)]
        need(isinstance(value,dict) and set(value)=={'paths','new_directories','replacement_order'}
            and isinstance(value['paths'],dict),'SOURCE_PLAN_INVALID')
        return value

    def _migration_gate(self):
        return {'policy':POLICY,'release':self.pins['web_release'],
            'source_manifest_sha256':sha(canonical(self._source_plan())),
            'migration_hashes':self.pins['migration_hashes'],
            'schema_hashes':{name:self.states.schema_digest(schema) for name,schema in self.pins['migration_schemas'].items()}}

    def _migration_actor(self,runner,verify,database_sha):
        intent=self.states.migration_intent(self.pins['web_release'],sha(canonical(self._source_plan())),database_sha,
            self.pins['migration_hashes'],self.pins['migration_schemas'])
        template=self._migration_template()
        program=self.modules['migration'].materialize_program(self.io,template,self._migration_gate())
        return self.modules['migration'].MigrationActuator(self.io,self.states,runner,self.audit,intent,template,verify,verify,
            pins={'template_sha256':sha(template),'program_sha256':sha(program),'intent_sha256':self.states.digest(intent),
                'gate_sha256':sha(canonical(self._migration_gate())),
                'resume_helper_sha256':self.pins['helpers']['migration']})

    def _prepare(self):
        if self.flow['prepared'] is not None:return
        with self.held() as verify:
            self.modules['native'].revalidate_bootstrap(self.flow['predecessor_observation'],self.package,self.engine,verify_locks=verify)
            source=self._source_plan();plan_sha=sha(canonical(source));pins_sha=sha(canonical(self.pins))
            # Preparation explicitly has no allocated candidate. These values
            # cannot authorize an enable action or fabricate candidate acceptance.
            binding={'candidate_revision':None,'web_release':self.pins['web_release'],'native_core':self.pins['core'],
                'seed':self.pins['seed'],'policy':POLICY,'source_manifest_sha256':plan_sha,
                'contract_sha256':self.pins['contract_sha256'],'migration_receipt_sha256':None,
                'route_cache_required':os.path.lexists(ROOT/self.pins['route_cache_path'])}
            saved_binding=self.flow.get('preparation_binding')
            if saved_binding is None:
                actor,runner=self._activation(binding,verify,plan_sha,pins_sha)
                env=actor.preview_binding_env();binding.update({name:env[name] for name in
                    ('env_before_sha256','env_enabled_sha256','env_unchanged_bytes_sha256')})
                binding['php_handler_configuration_sha256']=sha(canonical(self._handler()['configuration']))
                self._save({**self.flow,'preparation_binding':binding},verify)
            else:
                binding=copy.deepcopy(saved_binding)
            actor,runner=self._activation(binding,verify,plan_sha,pins_sha)
            payloads={name:self.package.file(name,self.pins['web_release']) for name in source['paths']}
            transaction=self.modules['source'].Transaction(ROOT,PRIVATE/'audits',self.pins['web_release'],1001,1003,
                payloads,source['paths'],source['new_directories'],verify,replacement_order=source['replacement_order'])
            actor.prepare_env_disabled()
            transaction.apply(after_apply=lambda:actor.prepare_disabled())
            # A committed SourceTxn returns without repeating after_apply. A
            # resumed preparation must independently reprove its environment,
            # cache inodes, source and handler before the first SQL operation.
            actor.prepare_disabled()
            program=self.modules['migration'].materialize_program(self.io,
                self._migration_template(),self._migration_gate())
            identity=runner.php(program,{'database_id_sha256':None,'gate':self._migration_gate(),
                'migration_path':None,'mode':'identity','table':None},cwd=str(ROOT),uid=1001,gid=1003,timeout=180,maximum=131072)
            need(identity['returncode']==0,'PRIVATE_DATABASE_IDENTITY_FAILED')
            data=self.io.parse(identity['stdout']);need(data.get('workspace_enabled') is False and data.get('local') is False,
                'DATABASE_CONFIGURATION_NOT_DISABLED')
            migration=self._migration_actor(runner,verify,data['database_id_sha256'])
            for _ in range(3):
                _observed,snapshot=migration.observe();checkpoint=migration._checkpoint(snapshot)
                need(checkpoint['state']!='foreign-conflict','FOREIGN_SCHEMA_REQUIRES_REVIEW')
                if checkpoint['state']=='owned-complete':break
                path=next(name for name in self.states.MIGRATIONS if name not in checkpoint['completed_migrations'])
                remaining=[name for name in self.states.MIGRATIONS[path] if name in checkpoint['missing_tables']]
                owned=[name for name in self.states.MIGRATIONS[path] if name in checkpoint['owned_tables']]
                migration.apply({'migration_path':path,'migration_sha256':self.pins['migration_hashes'][path],
                    'intent_sha256':migration.intent_sha,'expected_checkpoint_sha256':self.states.digest(checkpoint),
                    'remaining_tables':remaining,'owned_tables':owned,'table_intents':{name:self.states.table_intent(migration.intent,name) for name in remaining},
                    'mode':'owned-resume' if owned or not remaining else 'fresh-reviewed-path',
                    'approved_resume_helper_sha256':self.pins['helpers']['migration'],'cwd':str(ROOT),
                    'php':'/opt/cpanel/ea-php82/root/usr/bin/php','run_as_uid':1001,'run_as_gid':1003,
                    'private_output_limit':131072,'flag_must_stay_false':True,'unrestricted_migrate_allowed':False,
                    'down_allowed':False,'drop_allowed':False,'financial_row_updates_allowed':False})
            need(checkpoint['state']=='owned-complete','SCHEMA_PREPARATION_NOT_COMPLETE')
            previous=self.flow['predecessor_observation']['predecessor'];profile=self.modules['native'].PROFILES[previous['counter']]
            tick,old_worker,_publisher=self.modules['native']._old_runtime(self.package,self.engine,previous['counter'])
            old=self.utility.Directory(tick.NEW_COHORT['runtime'],0o755)
            try:old_source=self.utility.module_at(old,'go_erp_live_source.py',tick.NEW_COHORT['pins']['go_erp_live_source.py'][0])
            finally:old.close()
            state=self.io.parse(self.engine.read_file(WORK/'state/current.json',16*1024**2,uid=0,modes=(0o600,))[0])
            dependencies=old_worker.dependency_snapshot(ROOT,1001)
            need(state.get('dependency_guard') in (None,dependencies),'DEPENDENCIES_CHANGED')
            captured=old_source.scan_snapshot(ROOT,WORK/'checkout',1001,state.get('observed'))
            self.io.private_record(self.audit/'captured-source.json',captured['metadata'],verify)
            prepared={'binding':binding,'database_id_sha256':data['database_id_sha256'],
                'migration_checkpoint':checkpoint,'inventory_sha256':captured['metadata']['inventorySha256']}
            self._save({**self.flow,'phase':'prepared','prepared':prepared},verify)

    def _native_configuration(self,verify,workspace_acceptance):
        previous=self.flow['predecessor_observation']['predecessor'];p=self.pins
        profile=self.modules['native'].PROFILES[previous['counter']]
        tick,_worker,_pub=self.modules['native']._old_runtime(self.package,self.engine,previous['counter'])
        old={name:self.engine.read_file(tick.NEW_COHORT['runtime']/name,2*1024**2,uid=0,modes=(0o644,))[0] for name in tick.NEW_COHORT['pins']}
        perf=load(self.package.file('deployment/go_erp_owner_fast_open_payloads.py',LEGACY_RELEASE),'performance_payloads',
            'e5bcfcdadcf29d3edbe7d0c0054c2d396e037df00ef67e48a97f9a5c68bc4bb4')
        def transform(values):return self.modules['payloads'].native(values,perf,seed=p['seed'],
            startup_hashes=p['startup_hashes'],packaged_native_hashes=p['packaged_native_hashes'])
        rebound={}
        for name,raw in old.items():
            if name in ('go_erp_live_source.py','go_erp_live_updates.py'):
                raw=raw.replace(profile['seed'].encode(),p['seed'].encode()).replace(
                    profile['policy'].removeprefix('historical-baseline-v2').encode(),self.modules['native'].SUFFIX)
            rebound[name]=raw
        values=transform(rebound)
        runtime=self.template.NATIVE_RELEASES/self.utility.runtime_name(values)
        cohort={'seed':p['seed'],'policy':POLICY,'runtime':runtime,'unit_sha256':sha(self.utility.service_unit(runtime)),
            'pins':{name:(self.template.blob(raw),len(raw),sha(raw)) for name,raw in values.items()}}
        manual=self.modules['payloads'].manual(self.package.file('deployment/go_erp_owner_fast_open_tick_root.py',LEGACY_RELEASE),
            self.package.file('deployment/go_erp_owner_fast_open_manual_root.py',LEGACY_RELEASE),previous=tick.NEW_COHORT,new=cohort)
        manual['go_erp_published_download_root.py']=self.package.file('deployment/go_erp_published_download_root.py',ARCHIVE_RELEASE)
        native_pins={'core':p['core'],'seed':p['seed'],'web_release':p['web_release'],'policy':POLICY,
            'inventory_sha256':self.flow['prepared']['inventory_sha256'],'contract_sha256':p['contract_sha256'],
            'context_sha256':p['helpers']['native'],'native_payloads':p['native_payloads'],
            'predecessor':previous,'manual_programs':{name:{'path':'deployment/'+{
                'go_erp_manual_tick_root.py':'go_erp_shared_workspace_tick_root.py',
                'go_erp_manual_updates_root.py':'go_erp_shared_workspace_manual_root.py',
                'go_erp_published_download_root.py':'go_erp_published_download_root.py'}[name],
                'sha256':sha(raw)} for name,raw in manual.items()}}
        return self.modules['native'].configure(self.package.file('deployment/upgrade_go_erp_warm_start_root.py',TEMPLATE_RELEASE),
            self.package,self.engine,pins=native_pins,package_payloads=manual,native_transform=transform,
            verify_locks=verify,workspace_acceptance=workspace_acceptance)

    def run(self):
        self._predecessor();self._prepare()
        acceptance_holder={}
        def acceptance(candidate,version,contract):
            return acceptance_holder['value'](candidate,version,contract)
        # Original execute owns its original lock order and durable native job.
        verify=lambda:verify_outer_locks(self.io)
        with self.held():template=self._native_configuration(verify,acceptance)
        base_context=template.Context;operator=self
        class Context(base_context):
            def __init__(self,release,engine):
                super().__init__(release,engine)
                acceptance_holder['value']=operator.modules['acceptance'].WorkspaceAcceptance(operator.io,self,
                    operator.audit,verify,pins=operator.pins['acceptance'])
        template.Context=Context
        native_result=template.execute(template.Release(self.package.repository,self.package.revision),self.engine,True)
        need(native_result.get('published') is True,'ORIGINAL_PUBLICATION_NOT_READY')
        with self.held() as held:
            context=Context(template.Release(self.package.repository,self.package.revision),self.engine)
            context.job_record();context.live(context.state());need(context.ready(context.state()),'ORIGINAL_PROGRAM_GATE_NOT_COMPLETE')
            desired=context.candidate(context.state());proof=acceptance(desired['commit'],desired['version'],self.pins['contract_sha256'])
            self._activate(context,proof,held)
            self._save({**self.flow,'phase':'ready','published':native_result},held)
        self.engine.run(['/usr/bin/systemctl','start',self.template.WATCH])
        return {**native_result,'database_changed':True,'database_new_tables':4,'financial_data_changed':False,
            'workspace_enabled':True,'credentials_disclosed':False,'automatic_updates_enabled':False}

    def _activate(self,context,proof,verify):
        binding=copy.deepcopy(self.flow['prepared']['binding']);binding['candidate_revision']=proof['candidate_revision']
        binding['migration_receipt_sha256']=self.states.digest(self.flow['prepared']['migration_checkpoint'])
        actor,_runner=self._activation(binding,verify,sha(canonical(binding)),sha(canonical(self.pins)))
        state=self.flow['activation'] or self.states.activation_intent(binding)
        gate={name:binding[name] for name in self.states.GATE_FIELDS}
        if state['phase']=='prepared':
            evidence={name:binding[name] for name in self.states.GATE_FIELDS}
            evidence.update({name:proof[name] for name in self.states.GATE_TRUE_FIELDS})
            evidence['private_receipt_sha256']=sha(canonical(proof))
            state=self.states.advance_activation(state,'accept_gates',evidence,compiled_gate=gate)
            self._save({**self.flow,'activation':state},verify)
        # Same exact concrete effect/receipt contract as Coordinator, with
        # durable pending actions and recovery before any replay.
        readiness=actor.invoke(actor._internal('https_readiness',{})) if state['phase']=='gates-accepted' else self.flow.get('readiness')
        if state['phase']=='gates-accepted':self._save({**self.flow,'readiness':readiness},verify)
        begins={'handler-verified':('begin_flag_write','flag_written','activation_flag'),
            'flag-written':('begin_cache_build','cache_built','activation_cache'),
            'cache-built':('begin_route_cache_build','route_cache_built','activation_route_cache'),
            'cache-ready':('begin_fpm_reload','fpm_reloaded','activation_fpm')}
        effects={'gates-accepted':('handler_verified','verify_handler'),'flag-write-pending':('flag_written','activation_flag'),
            'cache-build-pending':('cache_built','activation_cache'),'route-cache-build-pending':('route_cache_built','activation_route_cache'),
            'fpm-reload-pending':('fpm_reloaded','activation_fpm'),'fpm-ready':('https_verified','activation_https')}
        for _ in range(20):
            if state['phase']=='active':
                # The durable activation receipt proves the owned transition;
                # every successful invocation also gets a fresh HTTPS witness.
                fresh=actor.invoke(actor._internal('activation_https',{'verification_nonce':os.urandom(16).hex()}))
                self._save({**self.flow,'active_readiness':fresh},verify)
                return
            if state['phase'] in begins:
                event,_done,_name=begins[state['phase']]
                evidence={'intent_sha256':state['intent_sha256'],'action_intent_sha256':self.states.action_intent_sha256(state,event),
                    'private_receipt_sha256':readiness['private_receipt_sha256'],
                    'current_sha256':binding['env_before_sha256'],'owned_inode_verified':True,
                    'unchanged_bytes_sha256':binding['env_unchanged_bytes_sha256'],'target':'config-cache-only','unit':self.template.PHP_FPM_UNIT if hasattr(self.template,'PHP_FPM_UNIT') else 'ea-php82-php-fpm.service'}
                state=self.states.advance_activation(state,event,evidence,compiled_gate=gate)
                self._save({**self.flow,'activation':state},verify);continue
            event,name=effects[state['phase']]
            parameters={'event':event,'activation_intent_sha256':state['intent_sha256'],
                'action_intent_sha256':self.states.action_intent_sha256(state,event),'binding':binding,'facts':state['facts'],
                'false_flag_https_receipt_sha256':readiness['private_receipt_sha256']}
            action=actor._internal(name,parameters)
            pending=self.flow.get('pending_activation')
            if pending is None:
                self._save({**self.flow,'pending_activation':action},verify);receipt=actor.invoke(action)
            else:
                need(canonical(pending)==canonical(action),'ACTIVATION_PENDING_ACTION_CHANGED')
                recovered=actor.recover(action);need(recovered['status']=='complete','ACTIVATION_EFFECT_REQUIRES_REVIEW');receipt=recovered['receipt']
            state=self.states.advance_activation(state,event,receipt['result'],compiled_gate=gate)
            self._save({**self.flow,'activation':state,'pending_activation':None},verify)
        raise Stop('GO_ERP_SHARED_WORKSPACE_ROOT_ACTIVATION_STEP_LIMIT')


def main(arguments=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--git-dir',required=True);parser.add_argument('--release',required=True)
    parser.add_argument('--complete',required=True,action='store_true');args=parser.parse_args(arguments)
    pins=checked_pins(PINS)
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_REQUIRED')
    owner=pwd.getpwnam('fasakha');need((owner.pw_uid,owner.pw_gid)==(1001,1003),'APPLICATION_ACCOUNT_CHANGED')
    os.umask(0o077)
    result=Operator(Package(args.git_dir,args.release),pins).run()
    print(json.dumps({'format':1,'kind':'go-erp-shared-workspace-rollout','ok':True,**result}),flush=True)
    print('GO_ERP_SHARED_WORKSPACE_PUBLISHED_MANUAL_UPDATES_ONLY',flush=True)

if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error)
        print(code if CODE.fullmatch(code) else 'GO_ERP_SHARED_WORKSPACE_ROOT_PRIVATE_FAILURE',flush=True)
        raise SystemExit(1)
