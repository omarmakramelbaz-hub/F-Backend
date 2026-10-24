"""Exercise the failed46 successor without relaxing native acceptance gates."""
import ast
import copy
from contextlib import contextmanager, redirect_stdout
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

ROOT=Path(__file__).resolve().parents[1]

def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module);return module

subject=load('program_contract_upgrade_subject',ROOT/'deployment/upgrade_go_erp_program_contract_root.py')
runtime=load('program_contract_upgrade_runtime_fixture',ROOT/'tests/test_go_erp_program_contract_tick.py')
frozen=runtime.original_tests.proof.frozen_module
OLD=runtime.OLD_PAYLOADS
NEW=runtime.NEW_PAYLOADS
old_source=frozen('contract_old_actual_source',OLD['go_erp_live_source.py'])
new_source=frozen('contract_new_actual_source',NEW['go_erp_live_source.py'])
old_worker=frozen('contract_old_actual_worker',OLD['go_erp_live_updates.py'])
new_worker=frozen('contract_new_actual_worker',NEW['go_erp_live_updates.py'])

def normalized(files):
    rows=[{'path':path,'sha256':subject.sha(raw),'bytes':len(raw)} for path,raw in sorted(files.items())]
    return {'format':1,'files':len(rows),'framework':'8.83.29',
        'sha256':subject.sha(json.dumps(rows,sort_keys=True,separators=(',',':')).encode())}

def failed_state():
    return {'format':1,'base_revision':subject.OLD_SEED,'counter':46,
        'last_remote_sha':subject.FAILED_CANDIDATE,'observed':None,
        'desired':{'commit':subject.FAILED_CANDIDATE,'version':subject.FAILED_VERSION,
            'candidatePolicy':subject.OLD_POLICY,'inventorySha256':'a'*64,'pushed':True,
            'status':'retry-build','failures':2,'retry_at':12345},
        'published':{'commit':'b'*40,'release':{'version':'0.4.4-preview.42'}},
        'published_history':[{'version':'0.4.4-preview.42'}],
        'version_floor':'0.4.4-preview.42','sequence_floor':40054814945,
        'dependency_guard':{'composer.lock':'c'*64},'unrelated_future_field':{'keep':True}}


class FrozenRuntimeTests(unittest.TestCase):
    def test_only_two_native_binding_constants_change_and_every_payload_pin_matches(self):
        actual=subject.derive_native_payloads(OLD,runtime.subject.OLD_COHORT['pins'],subject.SEED)
        self.assertEqual(actual,NEW)
        self.assertEqual({path for path in OLD if OLD[path]!=actual[path]},
            {'go_erp_live_source.py','go_erp_live_updates.py'})
        for path,raw in actual.items():
            self.assertEqual((subject.blob(raw),len(raw),subject.sha(raw)),
                tuple(runtime.subject.NEW_COHORT['pins'][path]))
        self.assertEqual(new_worker.PREVIEW31_SEED,subject.SEED)
        self.assertEqual(new_worker.PREVIEW31_POLICY,subject.POLICY)
    def test_all_acceptance_proof_and_publication_functions_are_byte_identical(self):
        def functions(raw):
            text=raw.decode();tree=ast.parse(text)
            return {item.name:ast.get_source_segment(text,item) for item in tree.body
                if isinstance(item,(ast.FunctionDef,ast.ClassDef))}
        self.assertEqual(functions(OLD['go_erp_live_updates.py']),functions(NEW['go_erp_live_updates.py']))
        for path in ('publish_go_erp_update.py','verify_go_erp_current_candidate_ci.py',
                'go_erp_current_candidate_ci.yml'):
            self.assertEqual(OLD[path],NEW[path])
        self.assertEqual(old_worker.WORKFLOWS,new_worker.WORKFLOWS)
    def test_state_rebind_preserves_failed_candidate_backoff_counter_history_and_removed_authority(self):
        state=failed_state()
        inventory={'public/removed.js':{'authority':'removed','present':False,'sha256':None,'bytes':0,'mode':'100644'}}
        state['observed']=old_source._metadata(subject.OLD_SEED,inventory)
        original=copy.deepcopy(state);result=subject.migrate_state(state,old_source,new_source)
        self.assertEqual(state,original)
        self.assertEqual(result['base_revision'],subject.SEED)
        self.assertEqual(result['observed'],new_source._metadata(subject.SEED,inventory))
        for key in state:
            if key not in ('base_revision','observed'):self.assertEqual(result[key],state[key],key)
    def test_changed_frozen_runtime_bytes_or_wrong_substitution_context_fail_closed(self):
        for target in ('go_erp_live_source.py','go_erp_live_updates.py'):
            changed=dict(OLD);changed[target]+=b'\n# drift\n'
            with self.assertRaisesRegex(subject.Stop,'OLD_NATIVE_PAYLOAD_CHANGED'):
                subject.derive_native_payloads(changed,runtime.subject.OLD_COHORT['pins'],subject.SEED)
    def fixture(self,folder):
        fixture=runtime.original_tests
        cohort=runtime.subject.NEW_COHORT
        with patch.object(fixture,'subject',runtime.proxy_for(cohort)),patch.object(fixture,'PAYLOADS',NEW):
            value=fixture.Fixture(folder)
        state=value.worker.state;state.update(counter=46,last_remote_sha=subject.FAILED_CANDIDATE)
        state['desired'].update(commit=subject.FAILED_CANDIDATE,version=subject.FAILED_VERSION,
            candidatePolicy=subject.OLD_POLICY,status='retry-build',failures=2,retry_at=0)
        value.worker.client.json=lambda *a,**k:{'object':{'sha':subject.FAILED_CANDIDATE}}
        def prepare(destination,snapshot,counter,**kwargs):
            value.events.append('prepare-source')
            return {'commit':'4'*40,'version':'0.4.4-preview.'+str(counter),
                'inventorySha256':value.inventory,'candidatePolicy':subject.POLICY,
                'candidatePath':'deployment/go_erp_live_source_candidate.json'}
        value.worker.source.prepare_candidate=prepare
        return value
    def test_real_original_worker_retires_failed46_and_allocates_one47_only_after_the_js_inventory_delta(self):
        with tempfile.TemporaryDirectory() as name:
            fixture=self.fixture(Path(name));fixture.inventory=runtime.original_tests.NEW
            before=copy.deepcopy({key:fixture.worker.state[key] for key in
                ('published','published_history','version_floor','sequence_floor','dependency_guard')})
            runtime.subject.install_guards(fixture.module,fixture.inventory,None,True,runtime.subject.NEW_COHORT)
            self.assertEqual(fixture.worker.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
            self.assertEqual(fixture.worker.state['counter'],47)
            self.assertEqual(fixture.worker.state['desired']['version'],'0.4.4-preview.47')
            self.assertEqual(fixture.worker.state['desired']['candidatePolicy'],subject.POLICY)
            self.assertEqual({key:fixture.worker.state[key] for key in before},before)
            fixture.worker.tick()
            self.assertEqual(fixture.events.count('git-candidate'),1)
            self.assertEqual(fixture.worker.state['counter'],47)
    def test_unchanged_old_source_is_never_used_to_consume_a_new_allocation_permit(self):
        with tempfile.TemporaryDirectory() as name:
            fixture=self.fixture(Path(name));fixture.worker.state['desired']['status']='blocked'
            runtime.subject.install_guards(fixture.module,fixture.inventory,None,False,runtime.subject.NEW_COHORT)
            with self.assertRaisesRegex(fixture.module.WatchError,'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
                fixture.worker.tick()
            self.assertEqual(fixture.worker.state['counter'],46)
            self.assertNotIn('git-candidate',fixture.events)


class RetirementTests(unittest.TestCase):
    def test_only_the_exact_failed46_state_is_accepted_without_mutating_any_field(self):
        state=failed_state();before=copy.deepcopy(state)
        subject.checked_failed_state(state,{'before_inventory_sha256':'a'*64})
        self.assertEqual(state,before)
        state['desired']['status']='blocked'
        subject.checked_failed_state(state,{'before_inventory_sha256':'a'*64})
    def test_active_wrong_policy_unknown_version_remote_counter_and_zero_failures_are_refused(self):
        edits=[('status','waiting-ci'),('status','published'),('commit','d'*40),('version','0.4.4-preview.47'),
            ('candidatePolicy',subject.POLICY),('inventorySha256','d'*64),('pushed',False),('failures',0)]
        for key,value in edits:
            with self.subTest(key=key,value=value):
                state=failed_state();state['desired'][key]=value
                with self.assertRaisesRegex(subject.Stop,'EXACT_FAILED_PREVIEW46_REQUIRED'):
                    subject.checked_failed_state(state,{'before_inventory_sha256':'a'*64})
        for key,value in (('counter',47),('last_remote_sha','d'*40),('base_revision',subject.SEED)):
            state=failed_state();state[key]=value
            with self.assertRaisesRegex(subject.Stop,'EXACT_FAILED_PREVIEW46_REQUIRED'):
                subject.checked_failed_state(state,{'before_inventory_sha256':'a'*64})
    def test_a_published46_is_never_relabelled_as_failed_and_backoff_is_not_reset(self):
        state=failed_state();state['published']['commit']=subject.FAILED_CANDIDATE
        with self.assertRaisesRegex(subject.Stop,'FAILED_CANDIDATE_ALREADY_PUBLISHED'):
            subject.checked_failed_state(state,{'before_inventory_sha256':'a'*64})
        state=failed_state();state['desired']['retry_at']=float('nan')
        with self.assertRaisesRegex(subject.Stop,'FAILED_BACKOFF_INVALID'):
            subject.checked_failed_state(state,{'before_inventory_sha256':'a'*64})

    def runs(self):
        result=[]
        for number,workflow in subject.FAILED_RUNS.items():
            result.append({'id':number,'run_attempt':1,'event':'push','head_branch':old_worker.BRANCH,
                'head_sha':subject.FAILED_CANDIDATE,'pull_requests':[],
                'path':'.github/workflows/'+workflow,'status':'completed','conclusion':'failure',
                'repository':{'id':old_worker.REPOSITORY_ID,'full_name':old_worker.REPOSITORY},
                'head_repository':{'id':old_worker.REPOSITORY_ID,'full_name':old_worker.REPOSITORY}})
        return result
    def client(self,rows):
        return types.SimpleNamespace(pages=lambda *args:rows,
            json=lambda path:next(row for row in rows if path.endswith('/'+str(row['id']))))
    def test_known_failed_runs_use_the_original_repository_branch_revision_identity_validator(self):
        rows=self.runs();self.assertTrue(subject.verify_failed_runs(self.client(rows),old_worker))
        rows[0]['head_branch']='unreviewed'
        with self.assertRaisesRegex(old_worker.WatchError,'CI_RUN_IDENTITY_INVALID'):
            subject.verify_failed_runs(self.client(rows),old_worker)
    def test_running_rerun_changed_conclusion_or_later_push_run_requires_review(self):
        rows=self.runs();rows[0]['status']='in_progress'
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_CI_STILL_ACTIVE'):
            subject.verify_failed_runs(self.client(rows),old_worker)
        rows=self.runs();rows[0]['conclusion']='success'
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_CI_RESULT_CHANGED'):
            subject.verify_failed_runs(self.client(rows),old_worker)
        rows=self.runs();later={**rows[0],'id':rows[0]['id']+100};rows.append(later)
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_CI_RUN_CHANGED'):
            subject.verify_failed_runs(self.client(rows),old_worker)


class SourcePlanTests(unittest.TestCase):
    def setUp(self):
        self.before=b'known original button\n';self.after=b'known new buttons\n'
        for name,value in (('BUTTON_BEFORE',subject.sha(self.before)),('BUTTON_JS_SHA',subject.sha(self.after))):
            scope=patch.object(subject,name,value);scope.start();self.addCleanup(scope.stop)
        self.files={subject.BUTTON_JS:self.before,'app/example.php':b'unchanged\n'}
        self.inventory={path:{'authority':'live','present':True,'sha256':subject.sha(raw),
            'bytes':len(raw),'mode':'100644'} for path,raw in self.files.items()}
        self.snapshot={'metadata':old_source._metadata(subject.OLD_SEED,self.inventory),'_files':self.files}
        planned=subject.source_plan(self.snapshot,self.after,old_source,normalized)
        self.job={**planned,'before_source':normalized(self.files),'before_inventory':self.inventory,
            'before_inventory_sha256':self.snapshot['metadata']['inventorySha256']}
    def test_plan_changes_only_the_reviewed_js_bytes_and_keeps_removal_authority(self):
        subject.check_plan(self.job,old_source)
        self.assertNotEqual(self.job['inventory'],self.job['before_inventory_sha256'])
        changed={name for name in self.inventory if self.inventory[name]!=self.job['after_inventory'][name]}
        self.assertEqual(changed,{subject.BUTTON_JS})
        self.assertEqual(subject.validate_ui_snapshot(self.snapshot,self.job,old_source,normalized),'before')
        files={**self.files,subject.BUTTON_JS:self.after}
        after={'metadata':old_source._metadata(subject.OLD_SEED,self.job['after_inventory']),'_files':files}
        self.assertEqual(subject.validate_ui_snapshot(after,self.job,old_source,normalized),'after')
    def test_unknown_other_file_delta_in_intent_or_live_scan_is_rejected(self):
        damaged=copy.deepcopy(self.job);damaged['after_inventory']['app/example.php']['sha256']='f'*64
        with self.assertRaisesRegex(subject.Stop,'SOURCE_PLAN_CHANGED'):subject.check_plan(damaged,old_source)
        changed=copy.deepcopy(self.snapshot);changed['_files']['app/example.php']=b'unknown update'
        with self.assertRaisesRegex(subject.Stop,'UI_TRANSACTION_SOURCE_CHANGED'):
            subject.validate_ui_snapshot(changed,self.job,old_source,normalized)
    def test_same_source_and_unknown_js_preimage_never_allocate_a_successor(self):
        with self.assertRaisesRegex(subject.Stop,'BUTTON_PAYLOAD_CHANGED|BUTTON_PREIMAGE_REQUIRED'):
            subject.source_plan(self.snapshot,self.before,old_source,normalized)
        changed=copy.deepcopy(self.snapshot);changed['_files'][subject.BUTTON_JS]=b'not approved'
        with self.assertRaisesRegex(subject.Stop,'BUTTON_PREIMAGE_REQUIRED'):
            subject.source_plan(changed,self.after,old_source,normalized)


class UiRecoveryTests(SourcePlanTests):
    def setUp(self):
        super().setUp()
        temporary=tempfile.TemporaryDirectory(prefix='program-contract-ui-');self.addCleanup(temporary.cleanup)
        self.folder=Path(temporary.name);self.root=self.folder/'app';self.work=self.folder/'work';self.control=self.folder/'control'
        for path in (self.root,self.work,self.control):path.mkdir(mode=0o700)
        self.audit=self.control/'private/audits'/('program-contract-'+'a'*32);self.audit.mkdir(parents=True,mode=0o700)
        (self.control/'shared').mkdir(mode=0o750)
        js=self.root/subject.BUTTON_JS;js.parent.mkdir(parents=True);js.write_bytes(self.before);js.chmod(0o644)
        self.engine=load('program_contract_ui_actual_file_engine',ROOT/'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop=subject.Stop
        self.ctx=object.__new__(subject.Context);self.ctx.e=self.engine;self.ctx.owner=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid())
        self.ctx.job={**copy.deepcopy(self.job),'audit':str(self.audit),'ui_phase':'pending','phase':'preparing'}
        self.ctx.job_path=self.control/'private/new-job.json'
        self.ctx.base=types.SimpleNamespace(normalized_source=normalized,parse=json.loads)
        self.ctx.old_source=old_source;self.ctx.old_worker=old_worker
        self.ctx.expected=new_worker.program_gate_expected(subject.CORE,self.job['source'],self.job['inventory'])
        self.oldhold={'format':1,'kind':'go-erp-program-rollout-hold',
            **old_worker.program_gate_expected(subject.OLD_CORE,self.job['before_source'],self.job['before_inventory_sha256']),
            'authorization':None}
        self.hold=self.work/'program-rollout-hold.json'
        self.engine.atomic_write(self.hold,self.engine.canonical(self.oldhold),0,0,0o600)
        self.ctx.state=lambda:{'base_revision':subject.OLD_SEED}
        self.events=[];self.ctx.verify_failed=lambda state:self.events.append('failed-bound')
        self.ctx.mark_predecessor=lambda:self.events.append('superseded-bound')
        def snapshot(*args):
            files={**self.files,subject.BUTTON_JS:js.read_bytes()}
            inventory=copy.deepcopy(self.inventory)
            inventory[subject.BUTTON_JS].update(sha256=subject.sha(files[subject.BUTTON_JS]),bytes=len(files[subject.BUTTON_JS]))
            return {'metadata':old_source._metadata(subject.OLD_SEED,inventory),'_files':files}
        self.ctx.snapshot=snapshot
        button=(ROOT/'deployment/apply_go_erp_button_flow_root.py').read_bytes()
        archive=(ROOT/'deployment/go_erp_published_download_root.py').read_bytes()
        self.ctx.release=types.SimpleNamespace(file=lambda path,*args:self.after if path==subject.BUTTON_JS
            else button if path.endswith('apply_go_erp_button_flow_root.py') else archive)
        self.ctx.save=lambda:self.engine.atomic_write(self.ctx.job_path,self.engine.canonical(self.ctx.job),0,0,0o600)
        self.ctx.save()
        original=subject.module
        def module(raw,name,*args):
            value=original(raw,name,*args)
            if name=='program_contract_button':
                value.BEFORE=subject.sha(self.before);value.AFTER=subject.sha(self.after)
                value.repair42=lambda *args:{'version':'0.4.4-preview.42','verified':True,
                    'in_download_slots':True,'binary_repaired':False,'manifest_repaired':False}
            return value
        for name,value in (('ROOT',self.root),('WORK',self.work),('CONTROL',self.control)):
            scope=patch.object(subject,name,value);scope.start();self.addCleanup(scope.stop)
        scope=patch.object(subject,'module',side_effect=module);scope.start();self.addCleanup(scope.stop)
    def run_ui(self):
        with redirect_stdout(io.StringIO()):self.ctx.ui()
    def test_real_atomic_ui_write_and_hold_replacement_are_bound_to_saved_plan(self):
        self.run_ui()
        self.assertEqual((self.root/subject.BUTTON_JS).read_bytes(),self.after)
        self.assertEqual(self.ctx.job['ui_phase'],'applied')
        self.assertEqual(json.loads(self.hold.read_bytes()),{'format':1,'kind':'go-erp-program-rollout-hold',
            **self.ctx.expected,'authorization':None})
        self.assertEqual(self.events,['failed-bound','superseded-bound'])
    def test_crash_after_ui_write_before_applied_journal_save_resumes_without_extra_source_change(self):
        save=self.ctx.save
        def interrupted():
            if self.ctx.job['ui_phase']=='applied':raise RuntimeError('power loss after verified UI write')
            save()
        self.ctx.save=interrupted
        with self.assertRaisesRegex(RuntimeError,'power loss'):self.run_ui()
        self.assertEqual((self.root/subject.BUTTON_JS).read_bytes(),self.after)
        self.assertEqual(json.loads(self.ctx.job_path.read_bytes())['ui_phase'],'pending')
        self.assertEqual(json.loads(self.hold.read_bytes()),self.oldhold)
        self.ctx.job=json.loads(self.ctx.job_path.read_bytes());self.ctx.save=save
        self.run_ui();self.assertEqual(self.ctx.job['ui_phase'],'applied')
        self.assertEqual(json.loads(self.hold.read_bytes())['core'],subject.CORE)
    def test_crash_after_new_hold_before_native_migration_resumes_and_preserves_original_proof(self):
        self.run_ui();hold=self.hold.read_bytes();job=self.ctx.job_path.read_bytes()
        self.ctx.job=json.loads(job);self.run_ui()
        self.assertEqual(self.hold.read_bytes(),hold)
        self.assertEqual(self.ctx.job['verified42']['binary_repaired'],False)
    def test_authorized_or_unknown_old_hold_is_never_replaced(self):
        value={**self.oldhold,'authorization':{'unreviewed':'not for successor'}}
        self.engine.atomic_write(self.hold,self.engine.canonical(value),0,0,0o600)
        with self.assertRaisesRegex(subject.Stop,'ROLLOUT_HOLD_AUTHORIZED_OR_CHANGED'):self.run_ui()
        self.assertEqual(json.loads(self.hold.read_bytes()),value)
    def test_first_repair_evidence_is_preserved_after_later_successful_rechecks(self):
        actual=subject.module;calls=[]
        def module(raw,name,*args):
            value=actual(raw,name,*args)
            if name=='program_contract_button':
                def repair(engine,archive,owner,audit,*rest):
                    calls.append(audit)
                    (audit/'before-downloads.json').write_bytes(b'first original bad manifest' if len(calls)==1 else b'now genuine')
                    return {'version':'0.4.4-preview.42','verified':True,'in_download_slots':True,
                        'binary_repaired':len(calls)==1,'manifest_repaired':len(calls)==1}
                value.repair42=repair
            return value
        with patch.object(subject,'module',side_effect=module):
            self.run_ui();self.run_ui()
        self.assertTrue(self.ctx.job['verified42']['binary_repaired'])
        self.assertFalse(self.ctx.job['verified42_current']['binary_repaired'])
        self.assertTrue(self.ctx.job['verified42_binary_repaired_ever'])
        self.assertTrue(self.ctx.job['verified42_manifest_repaired_ever'])
        self.assertEqual(len(set(calls)),2)
        self.assertEqual((calls[0]/'before-downloads.json').read_bytes(),b'first original bad manifest')


class CoordinatorOrderTests(unittest.TestCase):
    @contextmanager
    def watcher(self,*args):yield lambda:None
    def test_archive_must_commit_before_ready_and_a_published_resume_never_allocates_again(self):
        ctx=object.__new__(subject.Context);events=[]
        ctx.e=object();ctx.utility=types.SimpleNamespace(watcher_lock=self.watcher)
        ctx.job={'phase':'running','candidate':'f'*40,'version':'0.4.4-preview.47'}
        ctx.state=lambda:{};ctx.live=lambda state:events.append('live-source-and-controls')
        ctx.ready=lambda state:True;ctx.candidate=lambda state:{'commit':'f'*40,'version':'0.4.4-preview.47'}
        ctx.archive=lambda:events.append('verified-archive-current47-previous42') or {'available':True}
        ctx.save=lambda:events.append('saved-'+ctx.job['phase'])
        ctx.tick=lambda *args:(_ for _ in ()).throw(AssertionError('no second allocation'))
        with patch.object(subject,'quiet'):ctx.complete()
        self.assertEqual(events,['live-source-and-controls','verified-archive-current47-previous42','saved-ready'])
    def test_failed_archive_keeps_the_rollout_unready_and_cannot_enable_manual_path(self):
        ctx=object.__new__(subject.Context);ctx.e=object();ctx.utility=types.SimpleNamespace(watcher_lock=self.watcher)
        ctx.job={'phase':'running','candidate':'f'*40,'version':'0.4.4-preview.47'}
        ctx.state=lambda:{};ctx.live=lambda state:None;ctx.ready=lambda state:True
        ctx.candidate=lambda state:{'commit':'f'*40,'version':'0.4.4-preview.47'}
        ctx.archive=lambda:(_ for _ in ()).throw(subject.Stop('ARCHIVE_INCOMPLETE'))
        ctx.save=lambda:(_ for _ in ()).throw(AssertionError('must not markready'))
        with patch.object(subject,'quiet'),self.assertRaisesRegex(subject.Stop,'ARCHIVE_INCOMPLETE'):ctx.complete()
        self.assertEqual(ctx.job['phase'],'running')
    def test_old_to_new_transaction_rechecks_failed46_even_after_a_saved_ui_step(self):
        ctx=object.__new__(subject.Context);ctx.job={'before_inventory_sha256':'a'*64}
        ctx.expected={};ctx.e=types.SimpleNamespace(read_file=lambda *a,**k:None)
        changed=failed_state();changed['counter']=47
        base=types.SimpleNamespace()
        base.upgrade=lambda *a,**k:base.migrated_state(changed,subject.OLD_SEED,subject.SEED)
        ctx.base=base;ctx.utility=object();ctx.payloads={};ctx.pins={};ctx.old_tick=types.SimpleNamespace(PINS={})
        ctx.snapshot=lambda *args:(_ for _ in ()).throw(AssertionError('must reject before a source read'))
        with self.assertRaisesRegex(subject.Stop,'EXACT_FAILED_PREVIEW46_REQUIRED'):ctx.prepare(object())
    def test_pending_owner_requests_are_not_stopped_deleted_or_consumed_by_the_quiet_guard(self):
        events=[]
        def run(args):
            events.append(args)
            return 'disabled' if args[-2]=='UnitFileState' else 'inactive'
        engine=types.SimpleNamespace(run=run,read_file=lambda *a,**k:None)
        subject.quiet(engine)
        self.assertFalse(any('stop' in args or 'start' in args for args in events))


class PendingAcceptancePreservationTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='program-contract-old-receipt-');self.addCleanup(temporary.cleanup)
        self.folder=Path(temporary.name);self.work=self.folder/'work';self.work.mkdir(mode=0o700)
        self.audit=self.folder/'audit';self.audit.mkdir(mode=0o700)
        self.pending=self.work/'pending-program-acceptance.json'
        self.backup=self.audit/'previous-pending-acceptance.json'
        self.engine=load('program_contract_receipt_actual_file_engine',ROOT/'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop=subject.Stop
        self.ctx=object.__new__(subject.Context);self.ctx.e=self.engine;self.ctx.job={'audit':str(self.audit),'ui_phase':'pending'}
        scope=patch.object(subject,'WORK',self.work);scope.start();self.addCleanup(scope.stop)
    def write(self,raw):self.engine.atomic_write(self.pending,raw,0,0,0o600)
    def test_absent_receipt_is_recorded_without_creating_a_pointer_or_audit_file(self):
        self.ctx.preserve_pending_acceptance()
        self.assertEqual(self.ctx.job['previous_pending_acceptance'],{'present':False,'bytes':0,'sha256':None})
        self.assertFalse(self.pending.exists());self.assertFalse(self.backup.exists())
        self.ctx.preserve_pending_acceptance()
    def test_unknown_opaque_old_bytes_are_preserved_exactly_and_never_parsed_or_authorized(self):
        raw=b'opaque retained receipt\x00not JSON\n'
        self.write(raw);before=self.pending.stat()
        with patch.object(subject.json,'loads',side_effect=AssertionError('must not interpret the retained pointer')):
            self.ctx.preserve_pending_acceptance();self.ctx.preserve_pending_acceptance()
        self.assertEqual(self.pending.read_bytes(),raw);self.assertEqual(self.backup.read_bytes(),raw)
        self.assertEqual(self.engine.identity(self.pending.stat()),self.engine.identity(before))
        self.assertEqual(self.ctx.job['previous_pending_acceptance'],{'present':True,'bytes':len(raw),'sha256':subject.sha(raw)})
        info=self.backup.stat();self.assertEqual((info.st_uid,info.st_gid,info.st_mode&0o777,info.st_nlink),(0,0,0o600,1))
    def test_changed_pending_bytes_before_ui_are_refused_without_overwriting_either_receipt(self):
        self.write(b'original');self.ctx.preserve_pending_acceptance()
        self.write(b'changed before source capture')
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ACCEPTANCE_CHANGED'):self.ctx.preserve_pending_acceptance()
        self.assertEqual(self.pending.read_bytes(),b'changed before source capture')
        self.assertEqual(self.backup.read_bytes(),b'original')
    def test_absent_to_present_and_present_to_absent_changes_are_refused_before_ui(self):
        self.ctx.preserve_pending_acceptance();self.write(b'newly appeared')
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ACCEPTANCE_CHANGED'):self.ctx.preserve_pending_acceptance()
        self.ctx.job.pop('previous_pending_acceptance');self.ctx.preserve_pending_acceptance();self.pending.unlink()
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ACCEPTANCE_CHANGED'):self.ctx.preserve_pending_acceptance()
    def test_tampered_or_missing_private_audit_does_not_get_recreated_over_the_baseline(self):
        self.write(b'original');self.ctx.preserve_pending_acceptance()
        self.engine.atomic_write(self.backup,b'tampered',0,0,0o600)
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ACCEPTANCE_AUDIT_CHANGED'):self.ctx.preserve_pending_acceptance()
        self.assertEqual(self.backup.read_bytes(),b'tampered')
        self.backup.unlink()
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ACCEPTANCE_AUDIT_CHANGED'):self.ctx.preserve_pending_acceptance()
        self.assertFalse(self.backup.exists())
    def test_pending_permissions_hardlinks_and_size_limit_remain_enforced(self):
        self.write(b'original');self.pending.chmod(0o644)
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.ctx.preserve_pending_acceptance()
        self.pending.chmod(0o600);os.link(self.pending,self.folder/'linked')
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.ctx.preserve_pending_acceptance()
        (self.folder/'linked').unlink();self.write(b'x'*(2*1024**2+1))
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.ctx.preserve_pending_acceptance()
    def authorization_context(self):
        desired={'commit':'f'*40,'version':'0.4.4-preview.47'}
        self.ctx.state=lambda:{'desired':desired};self.ctx.live=lambda state:None
        self.ctx.candidate=lambda state:desired
        self.ctx.expected=new_worker.program_gate_expected(subject.CORE,
            {'format':1,'files':1355,'framework':'8.83.29','sha256':'a'*64},'b'*64)
        self.ctx.worker=types.SimpleNamespace(program_gate_json=new_worker.program_gate_json,
            program_gate_authorize=Mock(),verify_proof=Mock())
        self.ctx.publication=lambda:None;self.ctx.publisher=object()
        return desired
    def test_old_or_malformed_pointer_never_reaches_the_original_authorization_gate(self):
        desired=self.authorization_context()
        for raw in (b'unknown non-JSON bytes',json.dumps({'candidate_revision':subject.FAILED_CANDIDATE,
                'version':subject.FAILED_VERSION,**self.ctx.expected}).encode()):
            self.write(raw)
            with self.assertRaises((ValueError,subject.Stop)):self.ctx.authorized()
            self.ctx.worker.program_gate_authorize.assert_not_called()
    def test_only_fresh_collector_bound_candidate_can_dispatch_to_the_unchanged_full_gate(self):
        desired=self.authorization_context();self.write(b'old opaque pointer');self.ctx.preserve_pending_acceptance()
        receipt={'candidate_revision':desired['commit'],'version':desired['version'],**self.ctx.expected}
        # Exercise the original collector's atomic writer, which runs only
        # after the existing complete CI and employee-evidence checks.
        new_worker.program_gate_write(self.pending,receipt)
        self.ctx.authorized();self.ctx.worker.program_gate_authorize.assert_called_once()
        args=self.ctx.worker.program_gate_authorize.call_args.args
        self.assertEqual(args[1],self.pending);self.assertEqual(args[2],self.ctx.expected)
        self.assertEqual(args[3]['desired'],desired)
        self.assertEqual(self.backup.read_bytes(),b'old opaque pointer')


if __name__=='__main__':unittest.main()
