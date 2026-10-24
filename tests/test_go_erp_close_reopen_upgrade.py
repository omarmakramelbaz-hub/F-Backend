"""Exercise the actual frozen worker, preserved metadata and guarded coordinator."""
import copy
from contextlib import contextmanager, redirect_stdout
import fcntl
import io
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]

def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);value=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value);return value

subject=load('close_upgrade_subject',ROOT/'deployment/upgrade_go_erp_close_reopen_root.py')
fixture=load('close_upgrade_frozen_fixture',ROOT/'tests/test_go_erp_manual_tick.py')
PAYLOADS=fixture.PAYLOADS
PINS=fixture.subject.PINS


class NativePayloadTests(unittest.TestCase):
    def setUp(self):self.new=subject.derive_native_payloads(PAYLOADS,PINS,subject.SEED)
    def modules(self):
        return [fixture.proof.frozen_module('close_actual_'+name.replace('.py',''),self.new[name])
            for name in ('go_erp_live_source.py','go_erp_live_updates.py')]
    def test_only_source_and_worker_change_and_exact_new_binding_is_enforced(self):
        self.assertEqual({name for name,raw in self.new.items() if raw!=PAYLOADS[name]},
            {'go_erp_live_source.py','go_erp_live_updates.py'})
        source,worker=self.modules()
        self.assertEqual(source.PREVIEW31_SEED,subject.SEED);self.assertEqual(worker.PREVIEW31_SEED,subject.SEED)
        self.assertEqual(source.PREVIEW31_POLICY,subject.POLICY);self.assertEqual(worker.PREVIEW31_POLICY,subject.POLICY)
        old=fixture.proof.frozen_module('close_original_worker_proof',PAYLOADS['go_erp_live_updates.py'])
        import ast
        def proof(raw):
            tree=ast.parse(raw)
            return ast.get_source_segment(raw,next(v for v in tree.body if isinstance(v,ast.FunctionDef) and v.name=='verify_proof'))
        self.assertEqual(proof(PAYLOADS['go_erp_live_updates.py'].decode()),proof(self.new['go_erp_live_updates.py'].decode()))
        self.assertEqual(old.WORKFLOWS,worker.WORKFLOWS)
    def test_changed_prior_bytes_and_wrong_context_fail_closed(self):
        for raw in (b'changed',PAYLOADS['go_erp_live_updates.py'].replace(subject.SHORTCUT,b'altered')):
            changed=dict(PAYLOADS);changed['go_erp_live_updates.py']=raw
            with self.assertRaisesRegex(subject.Stop,'OLD_NATIVE_PAYLOAD_CHANGED'):
                subject.derive_native_payloads(changed,PINS,subject.SEED)
    def test_observed_removal_authority_and_entire_publication_history_are_preserved(self):
        old_source=fixture.proof.frozen_module('close_old_metadata_source',PAYLOADS['go_erp_live_source.py'])
        source,_=self.modules()
        inventory={'public/dashboard/js/retired.js':{'authority':'removed','present':False,'sha256':None,'bytes':0,'mode':'100644'}}
        state={'format':1,'base_revision':subject.OLD_SEED,'counter':46,'version_floor':'0.4.4-preview.45',
            'sequence_floor':40095839411,'published':{'commit':'a'*40,'release':{'version':'0.4.4-preview.45'}},
            'published_history':[{'version':'0.4.4-preview.42'},{'version':'0.4.4-preview.45'}],
            'dependency_guard':{'composer.lock':'b'*64},'desired':{'status':'published','commit':'a'*40},
            'last_remote_sha':'a'*40,'observed':old_source._metadata(subject.OLD_SEED,inventory)}
        before=copy.deepcopy(state);result=subject.migrate_state(state,old_source,source)
        self.assertEqual(state,before);self.assertEqual(result['base_revision'],subject.SEED)
        self.assertEqual(result['observed'],source._metadata(subject.SEED,inventory))
        for name in state:
            if name not in ('base_revision','observed'):self.assertEqual(result[name],state[name],name)
        self.assertEqual(result['observed']['removedFiles'],['public/dashboard/js/retired.js'])
    def test_new_policy_forces_one_build_even_when_published_app_inventory_is_unchanged(self):
        with tempfile.TemporaryDirectory() as name:
            f=fixture.Fixture(Path(name));_,module=self.modules();f.worker.__class__=module.Worker
            f.worker.config['base_revision']=subject.SEED
            f.worker.source.PREVIEW31_SEED=subject.SEED;f.worker.source.PREVIEW31_POLICY=subject.POLICY
            f.worker.state['base_revision']=subject.SEED
            f.worker.state['published']['release']={'version':'0.4.4-preview.42','sequence':40054814945,
                'source_revision':f.worker.state['published']['commit'],
                'source':{'format':1,'files':1,'framework':'8.83.29','sha256':'a'*64},'gui':{},
                'installer':{'sha256':'b'*64,'bytes':10,'path':'/api/desktop-dashboard/update/installer/'+'b'*64}}
            f.worker.publisher.read_json=lambda *args,**kw:f.worker.state['published']['release']
            f.worker.state['desired']['status']='published';f.worker.state['desired']['commit']=f.worker.state['published']['commit']
            f.worker.state['desired']['candidatePolicy']=subject.OLD_POLICY
            # The real original preparation pipeline is retained; the fixture
            # supplies only Git/source transports and records each allocation.
            def prepare_candidate(destination,snapshot,counter,**kw):
                f.events.append('prepare-source')
                return {'commit':fixture.CANDIDATE44,'version':'0.4.4-preview.'+str(counter),
                    'inventorySha256':f.inventory,'candidatePolicy':subject.POLICY,
                    'candidatePath':'deployment/go_erp_live_source_candidate.json'}
            f.worker.source.prepare_candidate=prepare_candidate
            f.worker.invalidate=lambda:None
            self.assertEqual(f.worker.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
            self.assertEqual(f.worker.state['counter'],44);self.assertEqual(f.events.count('git-candidate'),1)
            self.assertEqual(f.worker.state['published']['release']['version'],'0.4.4-preview.42')
            f.worker.tick();self.assertEqual(f.events.count('git-candidate'),1)
    def test_actual_preallocation_transient_preserves_original_backoff_and_unconsumed_permit(self):
        with tempfile.TemporaryDirectory() as name:
            f=fixture.Fixture(Path(name));_,module=self.modules();f.worker.__class__=module.Worker
            f.worker.config['base_revision']=subject.SEED
            f.worker.source.PREVIEW31_SEED=subject.SEED;f.worker.source.PREVIEW31_POLICY=subject.POLICY
            f.worker.state['base_revision']=subject.SEED
            job={'baseline_counter':f.worker.state['counter'],'baseline_remote':f.worker.state['last_remote_sha'],
                'baseline_candidate':f.worker.state['desired']['commit']}
            f.worker.check_dependencies=lambda:(_ for _ in ()).throw(RuntimeError('private transient'))
            with self.assertRaisesRegex(module.WatchError,'^GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT$'):
                f.worker.tick()
            self.assertTrue(subject.allocation_unconsumed(job,f.worker.state))
            self.assertEqual(f.worker.state['desired']['retry_at'],220)
            self.assertEqual(f.worker.state['desired']['failures'],1)
            self.assertNotIn('git-candidate',f.events)


class CoordinatorTests(unittest.TestCase):
    def engine(self,config,journal=None):
        def read(path,*args,**kwargs):
            if path==subject.CONFIG:return json.dumps(config).encode(),None
            if str(path).endswith('close-reopen-'+subject.CORE+'.json'):
                return (json.dumps(journal).encode(),None) if journal is not None else None
            return None
        return types.SimpleNamespace(read_file=read)
    def test_bootstrap_is_skipped_on_old_seed_after_the_coordinator_dual_runtime_commit(self):
        e=self.engine({'base_revision':subject.OLD_SEED},{'kind':'go-erp-close-reopen-rollout',
            'core':subject.CORE,'seed':subject.SEED})
        release=types.SimpleNamespace(file=lambda *args:(_ for _ in ()).throw(AssertionError('would downgrade dual runtime')))
        subject.bootstrap(release,e)
    def test_bootstrap_refuses_a_foreign_resume_journal(self):
        e=self.engine({'base_revision':subject.OLD_SEED},{'kind':'other','core':subject.CORE,'seed':subject.SEED})
        with self.assertRaisesRegex(subject.Stop,'BOOTSTRAP_JOURNAL_UNREVIEWED'):subject.bootstrap(None,e)
    def test_active_or_unfinished_manual_job_is_never_stopped(self):
        calls=[]
        def run(command):
            calls.append(command)
            if command[2]==subject.SERVICE:return 'active'
            return 'inactive'
        e=types.SimpleNamespace(run=run)
        with self.assertRaisesRegex(subject.Stop,'EXISTING_JOB_BUSY'):subject.quiet(e)
        self.assertTrue(all('stop' not in call for call in calls))
        e=types.SimpleNamespace(run=lambda a:'disabled' if 'UnitFileState' in a else 'inactive',
            read_file=lambda *a,**k:(b'owner-authorized unfinished job',None))
        with self.assertRaisesRegex(subject.Stop,'UNFINISHED_MANUAL_JOB_BUSY'):subject.quiet(e)
    def test_both_new_seed_and_mixed_slot_resume_call_original_recovery_before_live_checks(self):
        for seed in (subject.OLD_SEED,subject.SEED):
            with self.subTest(seed=seed):
                events=[];ctx=object.__new__(subject.Context)
                ctx.runtime=Path('/fixed/runtime');ctx.expected={'core':subject.CORE,'source':{},'inventory_sha256':'a'*64}
                ctx.job={'phase':'preparing','source':{},'inventory':'a'*64,'remote_lease':'b'*40}
                ctx.e=types.SimpleNamespace(read_file=lambda *a,**k:None,canonical=lambda value:json.dumps(value).encode(),
                    atomic_write=lambda *a,**k:events.append('hold'))
                ctx.old_tick=types.SimpleNamespace(PINS=PINS);ctx.utility=object();ctx.payloads={};ctx.pins={}
                ctx.base=types.SimpleNamespace(upgrade=lambda *a,**k:(events.append('original-recovery') or {'ok':True}))
                ctx.state=lambda:{'base_revision':subject.SEED}
                ctx.live=lambda state:events.append('validated-live')
                ctx.ready=lambda state:False;ctx.save=lambda:events.append('journal')
                ctx.prepare(object())
                self.assertEqual(events[0],'original-recovery');self.assertLess(events.index('original-recovery'),events.index('validated-live'))
                self.assertEqual(ctx.job['phase'],'prepared')
    def test_original_transaction_failure_cannot_be_treated_as_prepared(self):
        ctx=object.__new__(subject.Context);ctx.e=types.SimpleNamespace(read_file=lambda *a,**k:None)
        ctx.expected={};ctx.base=types.SimpleNamespace(upgrade=lambda *a,**k:{'ok':False,'reason':'SERVICE_UNIT_UNREVIEWED'})
        ctx.old_tick=types.SimpleNamespace(PINS=PINS);ctx.utility=object();ctx.payloads={};ctx.pins={}
        with self.assertRaisesRegex(subject.Stop,'NATIVE_TRANSACTION_SERVICE_UNIT_UNREVIEWED'):ctx.prepare(object())
    def test_dependency_guard_change_refuses_before_source_scan_or_ready(self):
        ctx=object.__new__(subject.Context);ctx.owner=types.SimpleNamespace(pw_uid=0)
        ctx.worker=types.SimpleNamespace(dependency_snapshot=lambda *a:{'composer.lock':'new'})
        source=types.SimpleNamespace(scan_snapshot=lambda *a:(_ for _ in ()).throw(AssertionError('must refuse first')))
        with self.assertRaisesRegex(subject.Stop,'DEPENDENCIES_CHANGED'):
            ctx.snapshot({'dependency_guard':{'composer.lock':'old'}},Path('/unused'),source)
    @contextmanager
    def watcher(self,*args):yield lambda:None

    def completed_context(self,fail=False):
        ctx=object.__new__(subject.Context);events=[]
        ctx.e=object();ctx.utility=types.SimpleNamespace(watcher_lock=self.watcher)
        ctx.job={'phase':'running','candidate':'a'*40,'version':'0.4.4-preview.45'}
        ctx.state=lambda:{};ctx.live=lambda state:events.append('fresh-source-and-controls')
        ctx.ready=lambda state:True
        ctx.candidate=lambda state:{'commit':'a'*40,'version':'0.4.4-preview.45'}
        def archive():
            events.append('verified-archive')
            if fail:raise subject.Stop('ARCHIVE_REJECTED')
            return {'available':True,'current_version':'0.4.4-preview.45'}
        ctx.archive=archive;ctx.save=lambda:events.append('saved-'+ctx.job['phase'])
        ctx.tick=lambda *args:(_ for _ in ()).throw(AssertionError('a published rollout must not allocate'))
        return ctx,events

    def test_completed_native_publication_is_archived_before_ready_is_saved(self):
        ctx,events=self.completed_context()
        with patch.object(subject,'quiet'):
            result=ctx.complete()
        self.assertEqual(result['current_version'],'0.4.4-preview.45')
        self.assertEqual(events,['fresh-source-and-controls','verified-archive','saved-ready'])

    def test_archive_failure_never_marks_an_otherwise_published_rollout_ready(self):
        ctx,events=self.completed_context(True)
        with patch.object(subject,'quiet'),self.assertRaisesRegex(subject.Stop,'ARCHIVE_REJECTED'):ctx.complete()
        self.assertEqual(ctx.job['phase'],'running');self.assertNotIn('saved-ready',events)

    def test_pending_owner_request_starts_only_after_worker_lock_release_and_archive_commit(self):
        with tempfile.TemporaryDirectory() as name:
            root=Path(name);control=root/'control';(control/'private').mkdir(mode=0o700,parents=True)
            engine=load('close_actual_lock_engine',ROOT/'deployment/apply_go_erp_manual_updates_root.py')
            events=[];active=False
            class FakeContext:
                def __init__(self,*args):
                    self.e=engine
                    self.utility=types.SimpleNamespace(watcher_lock=self_watcher)
                    self.job={'candidate':'a'*40,'version':'0.4.4-preview.45','public_nonce':'b'*32}
                def job_record(self):pass
                def manual(self):pass
                def public_status(self,state,code=None,claim=False):events.append('public-'+state)
                def prepare(self,*args):events.append('prepared')
                def complete(self):events.append('archive-committed');return {'available':True,'current_version':self.job['version']}
            self_watcher=self.watcher
            def runner(args):
                nonlocal active
                if args[:3]==['/usr/bin/systemctl','start',subject.WATCH]:
                    self.assertIn('archive-committed',events)
                    fd=os.open(control/'private/worker.lock',os.O_RDWR)
                    try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
                    finally:os.close(fd)
                    events.append('owner-request-can-lock');active=True
                if 'show' in args:return 'active' if active else 'inactive'
                return ''
            engine.run=runner
            with patch.object(subject,'CONTROL',control),patch.object(subject,'quiet'),\
                    patch.object(subject,'bootstrap'),patch.object(subject,'Context',FakeContext):
                result=subject.execute(object(),engine,True)
            self.assertTrue(result['published']);self.assertEqual(events[-1],'public-ready')
            self.assertLess(events.index('public-running'),events.index('prepared'))
            self.assertLess(events.index('archive-committed'),events.index('owner-request-can-lock'))
            self.assertLess(events.index('owner-request-can-lock'),events.index('public-ready'))

    def tick_context(self):
        ctx=object.__new__(subject.Context);ctx.manual_runtime=Path('/reviewed/manual/runtime')
        ctx.job={'inventory':'a'*64,'candidate':None};return ctx

    def test_worker_heartbeat_is_safe_and_reuses_one_child(self):
        ctx=self.tick_context();timeouts=[]
        class Child:
            returncode=0
            def __enter__(self):return self
            def __exit__(self,*args):pass
            def communicate(self,timeout=None):
                timeouts.append(timeout)
                if len(timeouts)==1:raise subprocess.TimeoutExpired('private-child',timeout)
                return b'GO_ERP_LIVE_UPDATE_WAITING_CI\n',b''
        output=io.StringIO()
        with patch.object(subject.subprocess,'Popen',return_value=Child()) as spawn,\
                patch.object(subject.time,'monotonic',side_effect=[0,0,30,30]),redirect_stdout(output):
            result=ctx.tick(False)
        self.assertEqual(result,(0,'GO_ERP_LIVE_UPDATE_WAITING_CI'));self.assertEqual(spawn.call_count,1)
        self.assertEqual(timeouts,[30,30]);self.assertEqual(output.getvalue(),'GO_ERP_CLOSE_REOPEN_WAITING_WORKER seconds=30\n')

    def test_worker_deadline_kills_only_its_child_without_printing_private_output(self):
        ctx=self.tick_context();child=types.SimpleNamespace(returncode=-9,killed=False,calls=0)
        class Process:
            def __enter__(self):return self
            def __exit__(self,*args):pass
            def kill(self):child.killed=True
            def communicate(self,timeout=None):
                child.calls+=1
                if timeout is not None:raise subprocess.TimeoutExpired('hidden',timeout)
                return b'PRIVATE BYTES',b'PRIVATE ERROR'
        output=io.StringIO()
        with patch.object(subject.subprocess,'Popen',return_value=Process()),\
                patch.object(subject.time,'monotonic',side_effect=[0,0,2701,2701]),redirect_stdout(output),\
                self.assertRaisesRegex(subject.Stop,'WORKER_TIMED_OUT_RETRY_SAME_COMMAND'):
            ctx.tick(False)
        self.assertTrue(child.killed);self.assertNotIn('PRIVATE',output.getvalue())

    def test_allocation_rearm_requires_unchanged_counter_remote_and_old_candidate(self):
        job={'baseline_counter':44,'baseline_remote':'a'*40,'baseline_candidate':'a'*40}
        state={'counter':44,'last_remote_sha':'a'*40,'desired':{'commit':'a'*40}}
        self.assertTrue(subject.allocation_unconsumed(job,state))
        for changed in ({'counter':45},{'last_remote_sha':'b'*40},{'desired':{'commit':'b'*40}}):
            self.assertFalse(subject.allocation_unconsumed(job,{**state,**changed}))

    def test_runtime_variant_paths_have_final_pinned_bytes(self):
        for name,digest in subject.MANUAL_PINS.items():
            self.assertEqual(subject.sha((ROOT/subject.PACKAGE_PROGRAM_PATHS[name]).read_bytes()),digest,name)


class PublicRolloutStatusTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='close-public-status-');self.addCleanup(temporary.cleanup)
        self.control=Path(temporary.name)/'control'
        self.owner=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid())
        self.engine=load('close_status_actual_engine',ROOT/'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop=subject.Stop;self.engine.provision(self.owner,self.control)
        self.ctx=object.__new__(subject.Context);self.ctx.e=self.engine;self.ctx.owner=self.owner
        self.ctx.base=types.SimpleNamespace(parse=json.loads)
        self.ctx.job={'public_nonce':'b'*32,'version':None,'private_proof':'DO_NOT_EXPOSE',
            'source':{'private':'DO_NOT_EXPOSE'},'candidate':'a'*40}
        self.path=self.control/'shared/status.json'
        scope=patch.object(subject,'CONTROL',self.control);scope.start();self.addCleanup(scope.stop)
    def test_status_whitelist_nonce_stability_and_metadata_match_the_real_web_schema(self):
        self.assertTrue(self.ctx.public_status('running',claim=True))
        self.ctx.job['version']='0.4.4-preview.45'
        self.assertTrue(self.ctx.public_status('waiting-ci'))
        value=json.loads(self.path.read_bytes())
        self.assertEqual(set(value),{'format','kind','state','nonce','updated_at','version','code'})
        self.assertEqual((value['nonce'],value['version'],value['state']),('b'*32,'0.4.4-preview.45','waiting-ci'))
        self.assertNotIn('DO_NOT_EXPOSE',self.path.read_text())
        info=self.path.stat();self.assertEqual((info.st_uid,info.st_gid,info.st_mode&0o777),(0,self.owner.pw_gid,0o640))
    def test_an_older_resume_journal_gains_one_persisted_public_nonce_only(self):
        ctx=self.ctx;ctx.job_path=self.control/'private'/('close-reopen-'+subject.CORE+'.json')
        ctx.runtime=Path('/reviewed/runtime')
        original={'format':1,'kind':'go-erp-close-reopen-rollout','core':subject.CORE,'seed':subject.SEED,
            'runtime':ctx.runtime.name,'source':{},'inventory':'a'*64,'phase':'prepared','prepare_started':False,
            'candidate':None,'version':None}
        self.engine.atomic_write(ctx.job_path,self.engine.canonical(original),0,0,0o600)
        ctx.worker=types.SimpleNamespace(program_gate_expected=lambda *args:{})
        ctx.job_record();nonce=ctx.job['public_nonce']
        self.assertRegex(nonce,'^[a-f0-9]{32}$')
        persisted=json.loads(ctx.job_path.read_bytes())
        self.assertEqual({key:value for key,value in persisted.items() if key!='public_nonce'},original)
        ctx.job_record();self.assertEqual(ctx.job['public_nonce'],nonce)
        self.assertEqual(json.loads(ctx.job_path.read_bytes()),persisted)
    def test_new_broker_nonce_and_its_lock_are_never_overwritten_after_path_activation(self):
        self.ctx.public_status('running',claim=True)
        value=json.loads(self.path.read_bytes());value['nonce']='c'*32;value['state']='queued'
        self.engine.atomic_write(self.path,self.engine.canonical(value),0,self.owner.pw_gid,0o640)
        before=self.path.read_bytes();self.assertFalse(subject.public_status_after_unlock(self.ctx,'ready'))
        self.assertEqual(self.path.read_bytes(),before)
        from contextlib import ExitStack
        with ExitStack() as stack:
            self.engine.acquire(stack,self.control/'private/worker.lock')
            self.assertFalse(subject.public_status_after_unlock(self.ctx,'ready'))
        self.assertEqual(self.path.read_bytes(),before)
    def test_private_prepare_failure_is_publicly_failed_without_touching_queued_requests(self):
        pending=self.control/'requests/pending.json';pending.write_bytes(b'owner request preserved')
        ctx=self.ctx;ctx.utility=types.SimpleNamespace(watcher_lock=CoordinatorTests.watcher)
        # Bound context manager supplying the actual original lock protocol.
        @contextmanager
        def watcher(*args):yield lambda:None
        ctx.utility.watcher_lock=watcher
        ctx.job_record=lambda:None;ctx.manual=lambda:None
        ctx.prepare=lambda *args:(_ for _ in ()).throw(RuntimeError('DO_NOT_EXPOSE_PRIVATE_FAILURE'))
        def run(args):return 'inactive'
        self.engine.run=run
        with patch.object(subject,'quiet'),patch.object(subject,'bootstrap'),\
                patch.object(subject,'Context',return_value=ctx),self.assertRaisesRegex(RuntimeError,'DO_NOT_EXPOSE'):
            subject.execute(object(),self.engine,True)
        value=json.loads(self.path.read_bytes())
        self.assertEqual((value['state'],value['code']),('failed','GO_ERP_CLOSE_REOPEN_UPDATE_FAILED'))
        self.assertNotIn('DO_NOT_EXPOSE',self.path.read_text());self.assertEqual(pending.read_bytes(),b'owner request preserved')


class ButtonBootstrapTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='close-button-bootstrap-');self.addCleanup(temporary.cleanup)
        self.path=Path(temporary.name);self.root=self.path/'app';self.root.mkdir(mode=0o755)
        self.control=self.path/'control';self.releases=self.path/'manual/releases'
        self.releases.mkdir(mode=0o755,parents=True)
        self.systemd=self.path/'systemd';self.systemd.mkdir(mode=0o755)
        self.owner=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid(),pw_name='fixture-web')
        self.engine=load('close_button_actual_engine',ROOT/'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop=subject.Stop;self.engine.PROGRAM_PINS=subject.OLD_MANUAL_PINS;self.engine.RELEASES=self.releases
        self.engine.provision(self.owner,self.control)
        program={name:(ROOT/'deployment'/name).read_bytes() for name in subject.OLD_MANUAL_PINS}
        runtime=self.engine.install_runtime(program,self.releases)
        self.units=self.engine.units(runtime)
        for name,raw in self.units.items():self.engine.atomic_write(self.systemd/name,raw,0,0,0o644)
        self.receipt=self.control/'private/installation.json'
        self.engine.atomic_write(self.receipt,self.engine.canonical({'format':1,'kind':'go-erp-manual-installation',
            'release':subject.BOOTSTRAP,'runtime':runtime.name,
            'units':{name:self.engine.sha(raw) for name,raw in self.units.items()}}),0,0,0o600)
        self.installer=(ROOT/'deployment/apply_go_erp_published_downloads_root.py').read_bytes()
        archive_install=load('close_button_actual_archive_install',ROOT/'deployment/apply_go_erp_published_downloads_root.py')
        for name,(_,digest) in archive_install.APP_PINS.items():
            raw=(ROOT/name).read_bytes();self.assertEqual(subject.sha(raw),subject.BUTTON_JS_SHA if name==subject.BUTTON_JS else digest)
            target=self.root/name;target.parent.mkdir(mode=0o755,parents=True,exist_ok=True)
            target.write_bytes(raw);target.chmod(0o644)
        self.publisher_path=self.path/'publish_go_erp_update.py'
        self.publisher_path.write_bytes(PAYLOADS['publish_go_erp_update.py']);self.publisher_path.chmod(0o644)
        installer_bytes=b'MZgenuine-small-fixture42';digest=subject.sha(installer_bytes)
        self.release42={'version':'0.4.4-preview.42','sequence':40054814945,'source_revision':'a'*40,
            'source':{'format':1,'files':1355,'framework':'8.83.29','sha256':'b'*64},'gui':{},
            'installer':{'bytes':len(installer_bytes),'sha256':digest,
                'path':'/api/desktop-dashboard/update/installer/'+digest}}
        shared=self.control/'shared';(shared/'installers').mkdir(mode=0o750)
        self.engine.atomic_write(shared/'installers'/(digest+'.exe'),installer_bytes,0,self.owner.pw_gid,0o640)
        self.engine.atomic_write(shared/'downloads.json',self.engine.canonical({'format':1,
            'kind':'go-erp-published-downloads','current':self.release42,'previous':None}),0,self.owner.pw_gid,0o640)
        self.config=self.path/'config.json';self.engine.atomic_write(self.config,
            self.engine.canonical({'base_revision':subject.OLD_SEED}),0,0,0o600)
        for name,value in {'ROOT':self.root,'CONTROL':self.control,'MANUAL_RELEASES':self.releases,
                'SYSTEMD':self.systemd,'CONFIG':self.config}.items():
            scope=patch.object(subject,name,value);scope.start();self.addCleanup(scope.stop)
        scope=patch.object(subject.pwd,'getpwnam',return_value=self.owner);scope.start();self.addCleanup(scope.stop)
        original=subject.module
        def bound(raw,name,*args):
            value=original(raw,name,*args)
            if name=='close_button_archive':
                loader=value.load_publisher
                value.load_publisher=lambda:loader(self.publisher_path)
            return value
        scope=patch.object(subject,'module',side_effect=bound);scope.start();self.addCleanup(scope.stop)
        self.release=types.SimpleNamespace(repository=self.path,file=lambda path,revision:self.installer)

    def test_new_button_ui_skips_reinstall_only_with_real_archive_runtime_and_controls(self):
        before={path:path.read_bytes() for path in (self.receipt,self.control/'shared/downloads.json')}
        with patch.object(subject.subprocess,'run',side_effect=AssertionError('must not reinstall preimage')):
            subject.bootstrap(self.release,self.engine)
        for path,raw in before.items():self.assertEqual(path.read_bytes(),raw)

    def test_unknown_other_app_file_receipt_unit_runtime_or_binary_never_skips(self):
        cases=[self.root/'routes/desktop_dashboard.php',self.systemd/subject.SERVICE,
            self.releases/json.loads(self.receipt.read_bytes())['runtime']/'go_erp_manual_updates_root.py',
            self.control/'shared/installers'/(self.release42['installer']['sha256']+'.exe')]
        for target in cases:
            with self.subTest(target=target):
                before=target.read_bytes();mode=target.stat().st_mode&0o777
                target.chmod(0o644);target.write_bytes(b'changed but no valid archive binding');target.chmod(mode)
                with patch.object(subject.subprocess,'run') as run:
                    with self.assertRaises(Exception):subject.bootstrap(self.release,self.engine)
                    run.assert_not_called()
                target.chmod(0o644);target.write_bytes(before);target.chmod(mode)
        receipt=json.loads(self.receipt.read_bytes());receipt['release']='f'*40
        self.engine.atomic_write(self.receipt,self.engine.canonical(receipt),0,0,0o600)
        with self.assertRaisesRegex(subject.Stop,'BUTTON_ARCHIVE_RECEIPT_UNREVIEWED'):
            subject.bootstrap(self.release,self.engine)

    def test_old_ui_still_runs_original_bootstrap_and_new_native_seed_skips_it(self):
        (self.root/subject.BUTTON_JS).write_bytes(b'original UI preimage')
        result=types.SimpleNamespace(returncode=0,stdout=(json.dumps({'ok':True,'path_active':True,
            'database_changed':False,'automatic_updates_enabled':False})+'\n'
            'GO_ERP_PUBLISHED_DOWNLOADS_READY_MANUAL_UPDATES_ONLY\n').encode())
        with patch.object(subject.subprocess,'run',return_value=result) as run:
            subject.bootstrap(self.release,self.engine)
        self.assertEqual(run.call_count,1);self.assertIn(subject.BOOTSTRAP,run.call_args.args[0])
        self.engine.atomic_write(self.config,self.engine.canonical({'base_revision':subject.SEED}),0,0,0o600)
        with patch.object(subject.subprocess,'run',side_effect=AssertionError('new seed must resume')):
            subject.bootstrap(self.release,self.engine)


if __name__=='__main__':unittest.main()
