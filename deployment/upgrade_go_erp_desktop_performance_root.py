#!/usr/bin/env python3
"""One resumable, current-cohort performance release through the original gate.

The verified standalone loader supplies this module and the frozen original
Operator. Only three public JavaScript files are changed. The existing native
transaction owns its locks, state/seed migration, one-candidate allocation,
same-candidate Windows/employee/installer proof and original publication.
There is no application database migration, environment write or FPM action.
"""
import copy
import hashlib
import json
from pathlib import Path
import re
import socket
import os
import sys
from types import SimpleNamespace

CORE = '43b1498938d4550a4c63c267dbcde12b0bab7e81'
BAD_SEED = '11c739039e5c6320b3542d414682d755c7902790'
SEED = '78f862c63d0ee75871417c286512e81f962934d6'
WEB_RELEASE = BAD_SEED
POLICY_VERIFIER_SHA256 = '42c91abce041d42a61b4f0db70294b7912f44afdbcf8dad8cd0d018e98b3831f'
BAD_RUNTIME = 'policy-9d1cd8162a2a2a4a1de4acab091454d547a42696e11e35d9253dd5da5fc43397'
ROOT_RELEASE = '1e651117deabb4c6935933ee424237d82bd316cb'
EXPECTED_INVENTORY = '531ba5ab285bafc999930a78b9212640cf3c13fe83365da260ace931b49964b7'
VERSION = '0.4.4-preview.61'
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
PRIVATE = Path('/var/lib/go-erp-manual-updates/private')
AUDIT = PRIVATE / 'audits' / ('desktop-performance-' + CORE)
JOURNAL = PRIVATE / ('desktop-performance-' + CORE + '.json')
NATIVE_CHANGES = {
    'main.cjs': '1ae3aa1056918dc7a34271a2da914dd2b737aa01d7c9134cfe14f9ee253184c2',
    'dashboard.cjs': '57cd03e330d4748a4d20134a75308b59b2e59f1e9566a40d528cf4d4eb6c1381',
    'workspace-delta.cjs': '8ca4435ea30e443936d74c58f47722adc1f65e117976198808ab8c713438a40b',
}
DERIVED_PINS = {
    'desktop_dashboard_server_preflight.py': ('4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',10515,'21a8f2ffa16e47322853c33de07af855d8fcb4cc6759750dfda84ca5a0db33bb'),
    'diagnose_desktop_dashboard_current_source.py': ('e7a7a8271481013a1c16167d6f7a6ff429687ef5',16743,'3e3c852b5916f0cf38d7deb16656e0c13f855f0b5bf8fb326b2e25250d3be7ed'),
    'go_erp_current_candidate_ci.yml': ('af4d9bb97572c84e26cb6750d380adde2f7e9423',7231,'203ca21186223cacb7feb98dc8eb372bceb258c4e0f01e4c7a8b917b417271ea'),
    'go_erp_live_source.py': ('1624f54228acc2010c98dbb4c012719a299312cf',38824,'6458f965ac87bc2a3a5a48569c9b8180f2a20375bb6fa30f8538c6cb14df0c1e'),
    'go_erp_live_updates.py': ('c1558ad66578695ad6c2a1523fa0b7831781d65d',79669,'a702ab81e2a06d5bb5d9d165309c263b21a234b8ceb49a3f1ba5d673c3d70904'),
    'publish_go_erp_update.py': ('f9a7353b0705383844a576ab6d0f5b1662912bfb',18089,'3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'),
    'verify_go_erp_current_candidate_ci.py': ('fb2c6ee364fae14062148846b654df8c96bba0de',10634,'e005df2cd2229188d79418754dedd1ea18cbfb5256727ee126a881dfa660461a'),
}
SHA = re.compile(r'[a-f0-9]{64}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def binding(operator, source, *, seed=None, native_pins=None):
    return {'format':1,'kind':'go-erp-desktop-performance-binding','core':CORE,'seed':SEED if seed is None else seed,
            'web_release':WEB_RELEASE,'original_release':ROOT_RELEASE,
            'original_pins_sha256':sha(canonical(operator.pins)),
            'source_plan_sha256':sha(canonical(source.PINS)),
            'inventory_sha256':EXPECTED_INVENTORY,'native_changes':NATIVE_CHANGES,
            'native_payloads':{name:list(row) for name,row in (DERIVED_PINS if native_pins is None else native_pins).items()}}


def _environment_invariants(operator):
    """Read existing environment/cache/handler through their original policies."""
    reader=operator.modules['activation'].Activation._read
    proxy=SimpleNamespace(io=operator.io)
    files={}
    for name in ('.env','bootstrap/cache/config.php','bootstrap/cache/routes-v7.php'):
        result=reader(proxy,ROOT/name,optional=name!='.env')
        files[name]=None if result is None else {'sha256':sha(result[0]),
            'identity':list(operator.io.identity(result[1]))}
    expected=operator.flow['prepared']['binding']['env_enabled_sha256']
    need(files['.env']['sha256']==expected,'WORKSPACE_ENABLED_ENV_CHANGED')
    handler=operator._handler()
    return {'files':files,'handler_configuration_sha256':sha(canonical(handler['configuration']))}


def _reconstruct(operator, current, payloads, saved, verify):
    """Rebuild the old public code even after active config/state has advanced."""
    def forbidden(*_args,**_kwargs):
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_UNEXPECTED_ACCEPTANCE_EFFECT')
    operator.flow=operator._load()
    configured=operator._native_configuration(verify,forbidden)
    release=configured.Release(operator.package.repository,operator.package.revision)
    manual={name:release.file(pin['path']) for name,pin in configured.approved_pins['manual_programs'].items()}
    pins={name:pin['sha256'] for name,pin in configured.approved_pins['manual_programs'].items()}
    need(pins==saved['profile']['manual_pins']
         and all(sha(raw)==pins[name] for name,raw in manual.items()),'RETAINED_MANUAL_CODE_CHANGED')
    cohort=current.cohort_from_tick(manual['go_erp_manual_tick_root.py'])
    need(cohort['runtime'].name==saved['profile']['runtime'],'RETAINED_NATIVE_RUNTIME_CHANGED')
    native={name:operator.engine.read_file(cohort['runtime']/name,2*1024**2,uid=0,modes=(0o644,))[0]
            for name in payloads.CURRENT_PINS}
    for name,raw in native.items():
        pin=payloads.CURRENT_PINS[name]
        need((payloads.blob(raw),len(raw),sha(raw))==(pin['blob'],pin['size'],pin['sha256']),
             'RETAINED_NATIVE_CODE_CHANGED')
    return {**copy.deepcopy(saved),'_manual_payloads':manual,'_native_payloads':native}


class Rollout:
    def __init__(self,package,operator,current,payloads,source,recovery=None):
        self.package,self.o,self.current,self.payloads,self.source=package,operator,current,payloads,source
        self.e,self.io=operator.engine,operator.io
        need(package.revision==operator.package.revision==ROOT_RELEASE,'ORIGINAL_PACKAGE_RELEASE_CHANGED')
        need(source.RELEASE==WEB_RELEASE and source.ROOT==ROOT,'SOURCE_BINDING_CHANGED')
        self.recovery=recovery
        self.flow=None
        self.root_api=sys.modules[operator.__class__.__module__]
        # Separate module: the frozen current factory still needs its historical
        # PROFILES entries to reconstruct the retained current manual helpers.
        raw=package.file('deployment/go_erp_shared_workspace_native_root.py')
        self.native=self.root_api.load(raw,'performance_native',operator.pins['helpers']['native'])
        self.acceptance_pins=copy.deepcopy(operator.pins['acceptance'])
        for name,digest in NATIVE_CHANGES.items():
            key='desktop-pos/src/'+name
            need(key in self.acceptance_pins['source_hashes'],'ACCEPTANCE_NATIVE_ROSTER_CHANGED')
            self.acceptance_pins['source_hashes'][key]=digest
        self.public_payloads={name:package.file(name,WEB_RELEASE) for name in source.PINS}
        source.checked_plan(self.io,self.public_payloads)
        self._reviewed_code()
        self.bad_values=self._failed_payloads()
        self.values=copy.deepcopy(self.bad_values)
        for name in ('go_erp_live_source.py','go_erp_live_updates.py'):
            self.values[name]=payloads.rebind_seed(self.values[name],BAD_SEED,SEED)
        self.derived_pins={name:(payloads.blob(raw),len(raw),sha(raw)) for name,raw in self.values.items()}
        self.bad_binding=binding(operator,source,seed=BAD_SEED,native_pins=DERIVED_PINS)
        self.bind=binding(operator,source,native_pins=self.derived_pins)
        self.bind_sha=sha(canonical(self.bind))
        self.manual_previous_pins=None

    def _failed_payloads(self):
        runtime=self.o.template.NATIVE_RELEASES/BAD_RUNTIME
        folder=self.o.utility.Directory(runtime,0o755)
        try:
            self.o.utility.validate_runtime(folder,{name:row[0] for name,row in DERIVED_PINS.items()})
            values={name:self.e.read_file(runtime/name,2*1024**2,uid=0,modes=(0o644,))[0]
                    for name in DERIVED_PINS}
        finally:
            folder.close()
        need({name:(self.payloads.blob(raw),len(raw),sha(raw)) for name,raw in values.items()}==DERIVED_PINS,
             'FAILED_NATIVE_BYTES_CHANGED')
        return values

    def _reviewed_code(self):
        verifier=self.root_api.load(self.package.file('deployment/verify_go_erp_preview31_seed.py',SEED),
            'performance_policy_seed',POLICY_VERIFIER_SHA256)
        need(verifier.CORE==CORE,'POLICY_SEED_CORE_CHANGED')
        proof=verifier.validate_seed(self.package.repository,SEED,core=CORE)
        need(proof.get('seed')==SEED and proof.get('core')==CORE,'POLICY_SEED_LINEAGE_CHANGED')
        p=self.acceptance_pins
        need(self.package.git('diff-tree','-r','--no-commit-id','--name-only','--no-renames','--no-ext-diff',
             WEB_RELEASE,CORE,'--',*self.native.BACKEND)==b'','CORE_BACKEND_DIFFERS_FROM_REVIEWED_WEB')
        contract=self.package.file('desktop/workspace-contract.json',CORE)
        need(sha(contract)==p['contract_sha256'] and self.io.parse(contract).get('programs')==p['program_hashes'],
             'PROGRAM_CONTRACT_CHANGED')
        files={**p['program_hashes'],**p['source_hashes'],
               '.github/workflows/'+self.o.modules['acceptance'].NATIVE_WORKFLOW:p['workflow_sha256'],
               '.github/workflows/'+self.o.modules['acceptance'].FOUNDATION_WORKFLOW:p['foundation_workflow_sha256'],
               self.o.modules['acceptance'].VERIFIER:p['verifier_sha256']}
        for name,digest in files.items():
            need(sha(self.package.file(name,CORE))==digest,'REVIEWED_ACCEPTANCE_SOURCE_CHANGED')

    def load(self):
        raw=self.e.read_file(JOURNAL,16*1024**2,uid=0,modes=(0o600,),optional=True)
        if raw is None:return None
        value=self.io.parse(raw[0])
        need(value.get('binding_sha256')==self.bind_sha and value.get('phase') in ('observed','prepared','ready'),
             'JOURNAL_BINDING_CHANGED')
        checkpoint=AUDIT/('checkpoint-'+sha(canonical(value))+'.json')
        need(self.io.read(checkpoint,0,0,private=True,limit=16*1024**2)[0]==canonical(value),
             'JOURNAL_CHECKPOINT_CHANGED')
        return value

    def save(self,value,verify):
        need(verify() is not False,'LOCK_CHANGED')
        self.io.private_directory(AUDIT,verify)
        self.io.private_record(AUDIT/('checkpoint-'+sha(canonical(value))+'.json'),value,verify)
        self.e.atomic_write(JOURNAL,canonical(value),0,0,0o600)
        self.flow=copy.deepcopy(value)

    def invariant(self):
        need(_environment_invariants(self.o)==self.flow['invariants'],'ENV_CACHE_OR_HANDLER_CHANGED')

    def _old_source(self,observation):
        self.native.PROFILES[60]=copy.deepcopy(observation['profile'])
        release=self.current.bootstrap_release(self.package,observation)
        tick,worker,_publisher=self.native._old_runtime(release,self.e,60)
        old=self.o.utility.Directory(tick.NEW_COHORT['runtime'],0o755)
        try:source=self.o.utility.module_at(old,'go_erp_live_source.py',tick.NEW_COHORT['pins']['go_erp_live_source.py'][0])
        finally:old.close()
        return release,worker,source

    def capture(self,observation):
        _release,worker,source=self._old_source(observation)
        state=self.io.parse(self.e.read_file(WORK/'state/current.json',16*1024**2,uid=0,modes=(0o600,))[0])
        need(state.get('base_revision')==self.current.SEED,'SOURCE_CAPTURE_BASE_CHANGED')
        dependency=worker.dependency_snapshot(ROOT,1001)
        need(state.get('dependency_guard') in (None,dependency),'DEPENDENCIES_CHANGED')
        result=source.scan_snapshot(ROOT,WORK/'checkout',1001,state.get('observed'))
        return result['metadata'],source

    def observe(self,verify):
        observation=self.current.observe(self.o,self.payloads,verify_locks=verify)
        release,_worker,_source=self._old_source(observation)
        previous=self.native.bootstrap_predecessor(release,self.e,verify_locks=verify)
        need(previous.get('status')=='accepted-published' and previous['predecessor']['counter']==60
             and previous['predecessor']['candidate']==self.current.CANDIDATE,'EXACT_CURRENT_PREDECESSOR_REQUIRED')
        before,source=self.capture(observation)
        need(before['inventorySha256']==self.current.INVENTORY,'CURRENT_LIVE_INVENTORY_CHANGED')
        projected=copy.deepcopy(before['inventory'])
        for name,raw in self.public_payloads.items():
            row=projected[name]
            need(row['authority']=='live' and row['present'] is True
                 and row['sha256']==self.source.PINS[name]['before'],'SOURCE_BEFORE_INVENTORY_CHANGED')
            row.update(bytes=len(raw),sha256=sha(raw))
        need(source._metadata(self.current.SEED,projected)['inventorySha256']==EXPECTED_INVENTORY,
             'PROJECTED_INVENTORY_CHANGED')
        value={'format':1,'kind':'go-erp-desktop-performance-journal','binding_sha256':self.bind_sha,
               'phase':'observed','current':self.current.record(observation),'predecessor':previous,
               'invariants':_environment_invariants(self.o),'inventory_sha256':EXPECTED_INVENTORY}
        self.save(value,verify)
        print('GO_ERP_DESKTOP_PERFORMANCE_CURRENT_COHORT_VERIFIED',flush=True)

    def prepare_source(self,verify):
        self.flow=self.load()
        if self.flow is None:self.observe(verify)
        self.o.flow=self.o._load()
        self.invariant()
        observation=_reconstruct(self.o,self.current,self.payloads,self.flow['current'],verify)
        self.native.PROFILES[60]=copy.deepcopy(observation['profile'])
        if self.flow['phase']=='observed':
            release=self.current.bootstrap_release(self.package,observation)
            self.native.revalidate_bootstrap(self.flow['predecessor'],release,self.e,verify_locks=verify)
        # The original committed branch rechecks its immutable before-images,
        # current bytes and owned inodes on every prepared/ready resume too.
        transaction=self.source.transaction(self.io,self.public_payloads,verify_locks=verify)
        transaction.apply()
        if self.flow['phase']=='observed':
            # A committed source WAL does not rerun its callback on resume.
            self.invariant()
            captured,_source=self.capture(observation)
            need(captured['inventorySha256']==EXPECTED_INVENTORY,'FRESH_CAPTURED_INVENTORY_CHANGED')
            self.io.private_record(AUDIT/'captured-source.json',captured,verify)
            self.save({**self.flow,'phase':'prepared','captured_metadata_sha256':sha(canonical(captured))},verify)
        captured_raw=self.io.read(AUDIT/'captured-source.json',0,0,private=True,limit=16*1024**2)[0]
        need(sha(captured_raw)==self.flow['captured_metadata_sha256'],'CAPTURED_METADATA_BYTES_CHANGED')
        captured=self.io.parse(captured_raw)
        _release,_worker,old_source=self._old_source(observation)
        need(isinstance(captured.get('inventory'),dict)
             and captured==old_source._metadata(self.current.SEED,captured['inventory'])
             and captured.get('inventorySha256')==EXPECTED_INVENTORY,'CAPTURED_METADATA_BINDING_CHANGED')
        self.invariant()
        print('GO_ERP_DESKTOP_PERFORMANCE_SOURCE_PREPARED',flush=True)
        return observation

    def manual_values(self,observation,seed):
        need(seed in (BAD_SEED,SEED),'MANUAL_SEED_CHANGED')
        values=self.bad_values if seed==BAD_SEED else self.values
        runtime=self.o.template.NATIVE_RELEASES/self.o.utility.runtime_name(values)
        cohort={'seed':seed,'policy':self.current.POLICY,'runtime':runtime,
                'unit_sha256':sha(self.o.utility.service_unit(runtime)),
                'pins':{name:(self.payloads.blob(raw),len(raw),sha(raw)) for name,raw in values.items()}}
        previous=self.current.cohort_from_tick(observation['_manual_payloads']['go_erp_manual_tick_root.py'])
        manual=self.o.modules['payloads'].manual(
            self.package.file('deployment/go_erp_owner_fast_open_tick_root.py',self.root_api.LEGACY_RELEASE),
            self.package.file('deployment/go_erp_owner_fast_open_manual_root.py',self.root_api.LEGACY_RELEASE),
            previous=previous,new=cohort)
        manual['go_erp_published_download_root.py']=observation['_manual_payloads']['go_erp_published_download_root.py']
        return manual

    def correct_pending(self,verify):
        need(isinstance(self.recovery,dict) and set(self.recovery)=={'plan','evidence','effects'},
             'CORRECTION_HELPERS_REQUIRED')
        effects=self.recovery['effects']
        saved=effects.read_saved(self,self.recovery['plan'],verify)
        if saved is not None and saved['completed']:
            self.manual_previous_pins=effects.recover(self,self.recovery['plan'],verify)
            return
        if saved is not None:
            self.flow=saved['performance_journal']
        else:
            raw=self.e.read_file(JOURNAL,16*1024**2,uid=0,modes=(0o600,))[0]
            self.flow=self.io.parse(raw)
        need(self.flow.get('binding_sha256')==sha(canonical(self.bad_binding)),'FAILED_BINDING_CHANGED')
        self.o.flow=self.o._load()
        self.invariant()
        observation=_reconstruct(self.o,self.current,self.payloads,self.flow['current'],verify)
        self.native.PROFILES[60]=copy.deepcopy(observation['profile'])
        bad_manual=self.manual_values(observation,BAD_SEED)
        evidence=self.recovery['evidence'].collect(self,observation,bad_manual,verify,saved=saved)
        self.manual_previous_pins=effects.recover(self,self.recovery['plan'],verify,evidence=evidence)

    def configure(self,observation,verify,acceptance):
        target=copy.deepcopy(self.payloads.CURRENT_NATIVE_HASHES);target.update(NATIVE_CHANGES)
        bad=self.payloads.native(observation['_native_payloads'],seed=BAD_SEED,packaged_native_hashes=target)
        need(bad==self.bad_values,'FAILED_DERIVATION_CHANGED')
        values=self.payloads.native(observation['_native_payloads'],seed=SEED,packaged_native_hashes=target)
        need(values==self.values and {name:(self.payloads.blob(raw),len(raw),sha(raw)) for name,raw in values.items()}==self.derived_pins,
             'DERIVED_SERVER_PROOF_CHANGED')
        manual=self.manual_values(observation,SEED)
        paths={'go_erp_manual_tick_root.py':'deployment/go_erp_shared_workspace_tick_root.py',
               'go_erp_manual_updates_root.py':'deployment/go_erp_shared_workspace_manual_root.py',
               'go_erp_published_download_root.py':'deployment/go_erp_published_download_root.py'}
        pins={'core':CORE,'seed':SEED,'web_release':WEB_RELEASE,'policy':self.current.POLICY,
              'inventory_sha256':EXPECTED_INVENTORY,'contract_sha256':self.acceptance_pins['contract_sha256'],
              'context_sha256':self.o.pins['helpers']['native'],
              'native_payloads':{name:{'blob':row[0],'size':row[1],'sha256':row[2]} for name,row in self.derived_pins.items()},
              'manual_programs':{name:{'path':paths[name],'sha256':sha(raw)} for name,raw in manual.items()},
              'predecessor':self.flow['predecessor']['predecessor']}
        def transform(payloads):
            return self.payloads.after_original_seed_rebind(payloads,seed=SEED,packaged_native_hashes=target)
        configured=self.native.configure(
            self.package.file('deployment/upgrade_go_erp_warm_start_root.py',self.native.TEMPLATE_RELEASE),
            self.package,self.e,pins=pins,package_payloads=manual,native_transform=transform,
            verify_locks=verify,workspace_acceptance=acceptance)
        need(isinstance(self.manual_previous_pins,dict),'OWNED_MANUAL_TRANSITION_REQUIRED')
        need(sha(canonical(dict(sorted(self.manual_previous_pins.items()))))==
             '64c479503f586268c4d191f2b85a2963d6e0473933cfea4cf09411f90bb189ea',
             'OWNED_MANUAL_PINS_CHANGED')
        # Only frozen manual() consumes this global. Previous native/runtime
        # and employee acceptance remain bound to the unchanged current profile.
        configured.OLD_MANUAL_PINS=copy.deepcopy(self.manual_previous_pins)
        return self.current.bind_configured_release(configured,observation)

    def run(self):
        # Only the original held scope stops the manual request path. The
        # automatic timer remains disabled throughout this explicit operation.
        with self.o.held() as held:
            self.correct_pending(held)
            print('GO_ERP_DESKTOP_PERFORMANCE_OWNED_SEED_CORRECTION_COMPLETE',flush=True)
            observation=self.prepare_source(held)
        def verify():
            self.root_api.verify_outer_locks(self.io)
            self.o.template.quiet(self.e)
            self.invariant()
            return True
        holder={}
        def acceptance(candidate,version,contract):
            return holder['value'](candidate,version,contract)
        # configure itself has no effects and requires held original locks.
        with self.o.held():
            configured=self.configure(observation,verify,acceptance)
        original_context=configured.Context
        owner=self
        class Context(original_context):
            def __init__(self,release,engine):
                super().__init__(release,engine)
                holder['value']=owner.o.modules['acceptance'].WorkspaceAcceptance(owner.io,self,AUDIT,verify,
                    pins=owner.acceptance_pins)
        configured.Context=Context
        print('GO_ERP_DESKTOP_PERFORMANCE_ORIGINAL_NATIVE_ROLLOUT_ACTIVE',flush=True)
        result=configured.execute(configured.Release(self.package.repository,self.package.revision),self.e,True)
        need(result.get('ok') is True and result.get('published') is True and result.get('version')==VERSION,
             'ORIGINAL_PUBLICATION_NOT_READY')
        with self.o.held() as held:
            context=Context(configured.Release(self.package.repository,self.package.revision),self.e)
            context.job_record();context.live(context.state())
            need(context.ready(context.state()),'ORIGINAL_PROGRAM_GATE_NOT_COMPLETE')
            desired=context.candidate(context.state())
            proof=acceptance(desired['commit'],desired['version'],self.acceptance_pins['contract_sha256'])
            need(proof.get('installed_asar_accepted') is True and proof.get('original_program_gate_accepted') is True
                 and proof.get('employee_acceptance_accepted') is True,'FINAL_ACCEPTANCE_NOT_COMPLETE')
            self.invariant()
            self.save({**self.flow,'phase':'ready','published':result},held)
        self.e.run(['/usr/bin/systemctl','start',self.o.template.WATCH])
        need(self.e.run(['/usr/bin/systemctl','show',self.o.template.WATCH,'-p','ActiveState','--value'])=='active',
             'PUBLISHED_MANUAL_PATH_ACTIVATION_REQUIRED')
        self.o.template.quiet(self.e)
        print('GO_ERP_DESKTOP_PERFORMANCE_ORIGINAL_PUBLICATION_ACCEPTED',flush=True)
        return {'format':1,'kind':'go-erp-desktop-performance-rollout','ok':True,'published':True,
                'version':VERSION,'candidate_revision':result['candidate_revision'],'workspace_enabled':True,
                'manual_only':True,'automatic_updates_enabled':False,'database_changed':False,
                'financial_data_changed':False,'credentials_disclosed':False}


def run(package,old_operator,current_helper,payload_helper,source_helper,*,recovery=None):
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_REQUIRED')
    return Rollout(package,old_operator,current_helper,payload_helper,source_helper,recovery).run()


if __name__=='__main__':
    raise SystemExit('GO_ERP_DESKTOP_PERFORMANCE_USE_PINNED_LOADER')
