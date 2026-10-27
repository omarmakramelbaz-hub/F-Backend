"""Frozen original Context binding tests; no runtime install/build/server access."""
import base64
import copy
from contextlib import nullcontext
import importlib.util
import json
from pathlib import Path
import types
import unittest
from unittest.mock import patch
import zlib

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('native_subject', ROOT / 'deployment/go_erp_shared_workspace_native_root.py')
subject = importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)
# Exact frozen template, bundled so a shallow CI checkout needs no fetch/network.
TEMPLATE = zlib.decompress(base64.b64decode('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'))


def pin(raw):return {'blob':subject.blob(raw),'size':len(raw),'sha256':subject.sha(raw)}


class Release:
    repository = Path('/not-opened/release.git')
    revision = '9' * 40
    def __init__(self):self.calls = [];self.difference = b''
    def git(self,*args):self.calls.append(args);return self.difference


class Engine:
    def __init__(self, files=None):self.files = files or {};self.writes = []
    def read_file(self,path,*_args,optional=False,**_kwargs):
        raw=self.files.get(str(path))
        if raw is None:
            if optional:return None
            raise AssertionError('Unexpected private read: '+str(path))
        return raw,None
    def atomic_write(self,path,raw,*_args):self.writes.append(str(path));self.files[str(path)]=raw
    canonical=staticmethod(subject.canonical)


def fixtures(counter=59):
    profile=subject.PROFILES[counter]
    source={'framework':'8.83.29','files':1,'sha256':'1'*64}
    candidate='5becb9bab38a91998d1b1341670b2b398e4ba70f' if counter==59 else '2'*40
    publication={'version':'0.4.4-preview.'+str(counter),'source_revision':candidate,
        'sequence':123,'source':source,'installer':{'sha256':'3'*64}}
    expected={'core':profile['core'],'source':source,'inventory':'4'*64}
    receipt={'candidate_revision':candidate,'version':publication['version'],**expected,
        'installer':publication['installer'],'employee_evidence':{'approved':True}}
    receipt_raw=subject.canonical(receipt);receipt_sha=subject.sha(receipt_raw)
    previous={'counter':counter,'candidate':candidate,'version':publication['version'],
        'inventory_sha256':'4'*64,'release_sha256':subject.sha(subject.canonical(publication)),
        'acceptance_receipt_sha256':receipt_sha}
    state={'format':1,'base_revision':profile['seed'],'counter':counter,'last_remote_sha':candidate,
        'desired':{'status':'published','pushed':True,'candidatePolicy':profile['policy'],'commit':candidate,
            'inventorySha256':'4'*64,'version':publication['version']},
        'published':{'commit':candidate,'inventorySha256':'4'*64,'release':publication},
        'version_floor':publication['version'],'sequence_floor':123,'published_history':[publication]}
    old={name:(b'# native fixture\n' if name.endswith('.py') else b'name: fixture\n') for name in (
        'desktop_dashboard_server_preflight.py','diagnose_desktop_dashboard_current_source.py',
        'go_erp_current_candidate_ci.yml','go_erp_live_source.py','go_erp_live_updates.py',
        'publish_go_erp_update.py','verify_go_erp_current_candidate_ci.py')}
    for name in ('go_erp_live_source.py','go_erp_live_updates.py'):
        old[name]=(b'SEED='+repr(profile['seed']).encode()+b'\nPOLICY='
            +repr(profile['policy']).encode()+b'\n')
    new={name:raw.replace(profile['seed'].encode(),b'b'*40).replace(
        profile['policy'].removeprefix('historical-baseline-v2').encode(),subject.SUFFIX)
        for name,raw in old.items()}
    manual={name:('# approved '+name+'\n').encode() for name in subject.MANUAL_NAMES}
    pins={'core':'a'*40,'seed':'b'*40,'web_release':'c'*40,'policy':subject.POLICY,
        'inventory_sha256':'5'*64,'contract_sha256':'6'*64,'context_sha256':'7'*64,
        'native_payloads':{name:pin(raw) for name,raw in new.items()},
        'manual_programs':{name:{'path':'deployment/'+('go_erp_published_download_root.py'
            if name=='go_erp_published_download_root.py' else 'go_erp_workspace_'+name[7:]),
            'sha256':subject.sha(raw)}for name,raw in manual.items()},'predecessor':previous}
    job={'format':1,'kind':'go-erp-warm-start-rollout','core':profile['core'],'seed':profile['seed'],
        'runtime':profile['runtime'],'phase':'ready','candidate':candidate,'version':publication['version'],
        'source':source,'inventory':'4'*64}
    path=subject.WORK/'program-rollout-authorizations'/('receipt-'+receipt_sha+'.json')
    completed={'authorization':{'candidate':candidate,'version':publication['version'],'release':publication,
        'installer':publication['installer'],'receipt_sha256':receipt_sha,'receipt_path':str(path),
        'employee_evidence':receipt['employee_evidence']}}
    files={str(subject.WORK/'state/current.json'):subject.canonical(state),
        str(subject.CONFIG):subject.canonical({'base_revision':profile['seed']}),
        str(subject.ROOT/'storage/app/desktop-updates/current.json'):subject.canonical(publication),
        str(subject.CONTROL/'private'/(profile['job_prefix']+profile['core']+'.json')):subject.canonical(job),
        str(subject.WORK/('program-rollout-completed-'+profile['core']+'.json')):subject.canonical(completed),
        str(path):receipt_raw,str(subject.WORK/'pending-program-acceptance.json'):receipt_raw}
    return pins,manual,old,new,state,publication,expected,files


class NativeFactoryTests(unittest.TestCase):
    def setUp(self):
        self.pins,self.manual,self.old,self.new,self.state,self.publication,self.expected,self.files=fixtures()
        self.release=Release();self.engine=Engine(self.files);self.lock=True

    def configure(self,**options):
        options.setdefault('pins',self.pins);options.setdefault('package_payloads',self.manual)
        options.setdefault('verify_locks',lambda:self.lock)
        return subject.configure(TEMPLATE,self.release,self.engine,**options)

    def test_exact_frozen_template_is_bound_and_original_implementation_is_retained(self):
        self.assertEqual(subject.TEMPLATE_SHA256,subject.sha(TEMPLATE))
        configured=self.configure()
        self.assertIs(configured.Context.__mro__[1],configured.original_context)
        for name in ('state','live','archive','ready','save','captured_metadata','manual'):
            self.assertIs(getattr(configured.Context,name),getattr(configured.original_context,name))
        self.assertEqual([],self.engine.writes)

    def test_no_final_pins_means_no_template_execution_or_runtime_effect(self):
        with self.assertRaisesRegex(subject.Stop,'FINAL_PINS_UNAVAILABLE'):
            subject.configure(TEMPLATE,self.release,self.engine,verify_locks=lambda:True)
        self.assertEqual([],self.release.calls);self.assertEqual([],self.engine.writes)

    def test_changed_template_bytes_and_changed_package_payloads_are_rejected(self):
        with self.assertRaisesRegex(subject.Stop,'FROZEN_TEMPLATE_PIN_CHANGED'):
            subject.configure(TEMPLATE+b'\n',self.release,self.engine,pins=self.pins,
                package_payloads=self.manual,verify_locks=lambda:True)
        bad=dict(self.manual);bad['go_erp_manual_tick_root.py']+=b'# changed\n'
        with self.assertRaisesRegex(subject.Stop,'MANUAL_PROGRAM_BYTES_CHANGED'):
            self.configure(package_payloads=bad)

    def test_both_exact_predecessor_profiles_configure_the_actual_original_globals(self):
        for counter in (59,60):
            pins,manual,*_=fixtures(counter)
            configured=self.configure(pins=pins,package_payloads=manual)
            profile=subject.PROFILES[counter]
            self.assertEqual(profile['seed'],configured.OLD_SEED)
            self.assertEqual(profile['core'],configured.OLD_CORE)
            self.assertEqual(profile['runtime'],configured.OLD_RUNTIME)
            self.assertEqual(profile['operator_release'],configured.PREVIOUS)
            self.assertEqual(profile['manual_pins'],configured.OLD_MANUAL_PINS)
            self.assertEqual(subject.POLICY,configured.POLICY)

    def test_backend_guard_requires_reviewed_web_tree_and_retains_dependency_guard(self):
        configured=self.configure();configured.checked_native_core(self.release)
        self.assertEqual(2,len(self.release.calls))
        self.assertIn(self.pins['web_release'],self.release.calls[0]);self.assertIn(self.pins['core'],self.release.calls[0])
        self.assertEqual(('composer.json','composer.lock'),self.release.calls[1][-2:])
        self.release.difference=b'app/Unreviewed.php\n'
        with self.assertRaisesRegex(subject.Stop,'CORE_BACKEND_DIFFERS_FROM_REVIEWED_WEB'):
            configured.checked_native_core(self.release)

    def test_original_native_derivation_and_each_final_payload_triple_are_checked(self):
        configured=self.configure();oldpins={name:(subject.blob(raw),len(raw),subject.sha(raw))for name,raw in self.old.items()}
        self.assertEqual(self.new,configured.derive_native_payloads(self.old,oldpins,self.pins['seed']))
        changed=dict(self.old);changed['go_erp_live_source.py']+=b'# extra\n'
        with self.assertRaisesRegex(subject.Stop,'OLD_NATIVE_PAYLOAD_CHANGED'):
            configured.derive_native_payloads(changed,oldpins,self.pins['seed'])
        configured=self.configure(native_transform=lambda values:{**values,'go_erp_live_updates.py':b'# bypass gate\n'})
        with self.assertRaisesRegex(subject.Stop,'DERIVED_NATIVE_PAYLOAD_CHANGED'):
            configured.derive_native_payloads(self.old,oldpins,self.pins['seed'])

    def test_actual_original_tick_is_called_only_for_unconsumed_baseline_or_same_next_counter(self):
        configured=self.configure();context=configured.Context.__new__(configured.Context)
        context.job={'baseline_counter':59,'baseline_remote':self.state['last_remote_sha'],
            'baseline_candidate':self.state['desired']['commit']}
        context.state=lambda:copy.deepcopy(self.state)
        with patch.object(configured.original_context,'tick',return_value=(0,'ORIGINAL_TICK'))as tick:
            self.assertEqual((0,'ORIGINAL_TICK'),context.tick(True));tick.assert_called_once_with(True,2700)
            self.state['counter']=60;self.state['desired']['version']='0.4.4-preview.60'
            with self.assertRaisesRegex(subject.Stop,'BASELINE_ALLOCATION_ALREADY_CONSUMED'):context.tick(True)
            context.tick(False)
            self.state['counter']=61;self.state['desired']['version']='0.4.4-preview.61'
            with self.assertRaisesRegex(subject.Stop,'ONE_TARGET_COUNTER_REQUIRED'):context.tick(False)

    def test_workspace_acceptance_is_mandatory_even_through_inherited_complete_authorization(self):
        configured=self.configure();context=configured.Context.__new__(configured.Context)
        with self.assertRaisesRegex(subject.Stop,'WORKSPACE_ACCEPTANCE_VERIFIER_REQUIRED'):context.authorized()
        accepted={'candidate_revision':'8'*40,'version':'0.4.4-preview.60','contract_sha256':self.pins['contract_sha256'],
            **{name:True for name in ('latest_required_ci_accepted','full_native_lifecycle_accepted',
                'installed_asar_accepted','original_program_gate_accepted','employee_acceptance_accepted')}}
        configured=self.configure(workspace_acceptance=lambda *_args:copy.deepcopy(accepted))
        context=configured.Context.__new__(configured.Context);context.state=lambda:{};context.live=lambda _state:None
        context.candidate=lambda _state:{'commit':'8'*40,'version':'0.4.4-preview.60'}
        with patch.object(configured.original_context,'authorized',return_value='ORIGINAL_AUTH')as authorize:
            self.assertEqual('ORIGINAL_AUTH',context.authorized());authorize.assert_called_once()
            accepted['full_native_lifecycle_accepted']=False
            with self.assertRaisesRegex(subject.Stop,'WORKSPACE_ACCEPTANCE_REQUIRED'):context.authorized()
            self.assertEqual(1,authorize.call_count)

    def test_already_ready_native_candidate_skips_retired_hold_reauthorization(self):
        configured=self.configure();context=configured.Context.__new__(configured.Context)
        context.e=self.engine;context.utility=types.SimpleNamespace(watcher_lock=lambda _path:nullcontext())
        context.job={'candidate':'8'*40,'version':'0.4.4-preview.60'};context.expected={'core':self.pins['core']}
        context.base=types.SimpleNamespace(parse=json.loads);context.state=lambda:{}
        context.ready=lambda _state:True;context.review_existing_ci=lambda:None
        self.engine.files[str(subject.WORK/'pending-program-acceptance.json')]=subject.canonical({
            'candidate_revision':'8'*40,'version':context.job['version'],**context.expected})
        with patch.object(configured,'quiet'),patch.object(configured.original_context,'complete',return_value='READY'), \
                patch.object(context,'authorized',side_effect=AssertionError('retired hold was reauthorized')):
            self.assertEqual('READY',context.complete())

    def test_original_predecessor_program_gate_and_private_receipt_are_reverified(self):
        configured=self.configure();context=configured.Context.__new__(configured.Context)
        context.job=None;context.state=lambda:copy.deepcopy(self.state);context.e=self.engine
        context.base=types.SimpleNamespace(parse=json.loads)
        profile=subject.PROFILES[59];context.prior_job_path=subject.CONTROL/'private'/(profile['job_prefix']+profile['core']+'.json')
        gate=[]
        context.old_worker=types.SimpleNamespace(program_gate_expected=lambda *_args:self.expected,
            program_gate_completed=lambda *_args:gate.append(True)or True)
        proof=context.previous_proof();self.assertEqual(3,len(proof));self.assertEqual([True],gate)
        self.engine.files[str(subject.WORK/'program-rollout-authorizations'/('receipt-'+self.pins['predecessor']['acceptance_receipt_sha256']+'.json'))]+=b'\n'
        with self.assertRaisesRegex(subject.Stop,'PREDECESSOR_ACCEPTANCE_BYTES_CHANGED'):context.previous_proof()

    def test_current_source_state_history_and_floors_are_exact_for_published_predecessor(self):
        self.assertEqual(self.state['desired'],subject.checked_published_state(self.state,self.publication,self.pins))
        for key,value in (('counter',60),('sequence_floor',True),('base_revision','f'*40)):
            changed=copy.deepcopy(self.state);changed[key]=value
            with self.assertRaises(subject.Stop):subject.checked_published_state(changed,self.publication,self.pins)

    def test_lock_loss_blocks_configuration_and_candidate_core_read(self):
        self.lock=False
        with self.assertRaisesRegex(subject.Stop,'HELD_LOCK_VERIFIER_REQUIRED'):self.configure()
        self.lock=True;configured=self.configure();self.lock=False
        with self.assertRaisesRegex(subject.Stop,'HELD_LOCK_CHANGED'):
            configured.checked_native_core(self.release)
        context=configured.Context.__new__(configured.Context)
        with self.assertRaisesRegex(subject.Stop,'TICK_SCOPE_INVALID'):context.tick(False)


class BootstrapTests(unittest.TestCase):
    def setUp(self):
        self.pins,self.manual,self.old,self.new,self.state,self.publication,self.expected,self.files=fixtures()
        self.engine=Engine(self.files);self.calls=[];self.lock=True
        self.release=types.SimpleNamespace(git=lambda *_args:(self.state['last_remote_sha']+'\t'+subject.REMOTE_REF+'\n').encode())
        self.accept_ci=True
        def accepted(_client,workflow,candidate):
            self.calls.append((workflow,candidate))
            return {'id':100+len(self.calls),'run_attempt':1} if self.accept_ci else None
        self.worker=types.SimpleNamespace(program_gate_expected=lambda *_args:self.expected,
            program_gate_completed=lambda *_args:True,
            read_config=lambda:{'base_revision':self.state['base_revision'],'token':'not-returned-private-token'},
            GitHub=lambda _token:object(),accepted_run=accepted)
        self.publisher=types.SimpleNamespace(validate_release=lambda value:value)

    def bootstrap(self):
        with patch.object(subject,'_old_runtime',return_value=(None,self.worker,self.publisher)), \
                patch.object(subject,'_legacy_source_checkpoint',return_value=False):
            return subject.bootstrap_predecessor(self.release,self.engine,verify_locks=lambda:self.lock)

    def update_state(self):self.engine.files[str(subject.WORK/'state/current.json')]=subject.canonical(self.state)

    def test_readonly_bootstrap_discovers_hashes_and_keeps_credentials_and_artifacts_private(self):
        result=self.bootstrap()
        self.assertEqual('accepted-published',result['status'])
        self.assertEqual(self.pins['predecessor'],result['predecessor'])
        self.assertEqual(set(subject.WORKFLOWS),set(result['original_ci']))
        self.assertEqual([],self.engine.writes)
        self.assertNotIn('not-returned-private-token',json.dumps(result))
        self.assertFalse(result['new_allocation_allowed']);self.assertFalse(result['artifact_replacement_allowed'])

    def test_missing_or_changed_pending_receipt_cannot_authorize_new_work(self):
        self.engine.files[str(subject.WORK/'pending-program-acceptance.json')]=subject.canonical({'different':True})
        with self.assertRaisesRegex(subject.Stop,'PREDECESSOR_PENDING_RECEIPT_CHANGED'):self.bootstrap()
        self.assertEqual([],self.engine.writes)

    def test_original_ci_is_fresh_and_failure_never_rewrites_state_or_retry_budget(self):
        self.accept_ci=False
        with self.assertRaisesRegex(subject.Stop,'ORIGINAL_PREDECESSOR_CI_REQUIRED'):self.bootstrap()
        self.assertEqual([],self.engine.writes)

    def test_canonical_remote_branch_must_still_match_the_accepted_predecessor(self):
        self.release.git=lambda *_args:('f'*40+'\t'+subject.REMOTE_REF+'\n').encode()
        with self.assertRaisesRegex(subject.Stop,'PREDECESSOR_CANONICAL_REMOTE_CHANGED'):self.bootstrap()

    def test_unrecognized_counter_or_published_policy_is_not_adopted(self):
        self.state['counter']=61;self.update_state()
        with self.assertRaisesRegex(subject.Stop,'UNKNOWN_PREDECESSOR_REQUIRES_REVIEW'):self.bootstrap()
        self.state['counter']=59;self.state['desired']['candidatePolicy']='foreign-policy';self.update_state()
        with self.assertRaisesRegex(subject.Stop,'PUBLICATION_HISTORY_OR_RECEIPT_CHANGED'):self.bootstrap()

    def test_published_fast60_is_proven_with_its_own_old_worker_and_receipts(self):
        self.pins,self.manual,self.old,self.new,self.state,self.publication,self.expected,self.files=fixtures(60)
        self.engine=Engine(self.files)
        result=self.bootstrap();self.assertEqual(60,result['predecessor']['counter'])
        self.assertEqual('accepted-published',result['status']);self.assertEqual([],self.engine.writes)

    def test_reserved_performance60_returns_only_resume_of_original_operator(self):
        fast=subject.PROFILES[60];oldcandidate=self.state['last_remote_sha']
        self.state['base_revision']=fast['seed'];self.state['counter']=60;self.state['last_remote_sha']='8'*40
        self.state['desired']={'status':'retry-build','pushed':True,'candidatePolicy':fast['policy'],
            'commit':'8'*40,'version':'0.4.4-preview.60','inventorySha256':'5'*64}
        self.update_state();self.engine.files[str(subject.CONFIG)]=subject.canonical({'base_revision':fast['seed']})
        job={'format':1,'kind':'go-erp-warm-start-rollout','core':fast['core'],'seed':fast['seed'],
            'runtime':fast['runtime'],'phase':'running','prepare_started':True,'remote_lease':oldcandidate,
            'baseline_counter':59,'baseline_candidate':oldcandidate,'baseline_remote':oldcandidate,
            'inventory':'5'*64,'candidate':'8'*40,'version':'0.4.4-preview.60'}
        self.engine.files[str(subject.CONTROL/'private'/('owner-fast-open-'+fast['core']+'.json'))]=subject.canonical(job)
        result=self.bootstrap()
        self.assertEqual('resume-legacy-required',result['status'])
        self.assertEqual(fast['operator_release'],result['legacy_operator_release'])
        self.assertFalse(result['new_allocation_allowed']);self.assertEqual([],self.calls);self.assertEqual([],self.engine.writes)

    def test_started_performance_job_before_seed_migration_resumes_old_checkpoint(self):
        fast=subject.PROFILES[60];candidate=self.state['last_remote_sha']
        job={'format':1,'kind':'go-erp-warm-start-rollout','core':fast['core'],'seed':fast['seed'],
            'runtime':fast['runtime'],'phase':'preparing','prepare_started':False,'remote_lease':candidate}
        self.engine.files[str(subject.CONTROL/'private'/('owner-fast-open-'+fast['core']+'.json'))]=subject.canonical(job)
        observed=self.bootstrap()
        self.assertEqual('resume-legacy-required',observed['status']);self.assertEqual(59,observed['counter'])
        self.assertFalse(observed['new_allocation_allowed']);self.assertEqual([],self.engine.writes)

    def test_old_exact_source_only_checkpoint_routes_to_original_operator(self):
        with patch.object(subject,'_legacy_source_checkpoint',return_value=True), \
                patch.object(subject,'_old_runtime',side_effect=AssertionError('new allocation path')):
            result=subject.bootstrap_predecessor(self.release,self.engine,verify_locks=lambda:True)
        self.assertEqual('resume-legacy-required',result['status']);self.assertEqual([],self.engine.writes)

    def test_root_private_observation_is_revalidated_instead_of_being_trusted_as_request_json(self):
        before=self.bootstrap()
        # Repeated original CI IDs in the fixture model the same latest runs.
        self.worker.accepted_run=lambda _client,workflow,_candidate:{
            'id':101+list(subject.WORKFLOWS).index(workflow),'run_attempt':1}
        with patch.object(subject,'_old_runtime',return_value=(None,self.worker,self.publisher)), \
                patch.object(subject,'_legacy_source_checkpoint',return_value=False):
            self.assertEqual(before,subject.revalidate_bootstrap(before,self.release,self.engine,verify_locks=lambda:True))
            changed=copy.deepcopy(before);changed['predecessor']['candidate']='f'*40
            with self.assertRaisesRegex(subject.Stop,'OBSERVATION_BYTES_CHANGED'):
                subject.revalidate_bootstrap(changed,self.release,self.engine,verify_locks=lambda:True)


if __name__=='__main__':unittest.main()
