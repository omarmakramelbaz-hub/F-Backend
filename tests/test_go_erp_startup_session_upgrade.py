"""Exercise the startup window seed move without relaxing native acceptance gates."""
import ast
import copy
from contextlib import contextmanager
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
from go_erp_startup_session_reference import REFERENCE

def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module);return module

subject=load('startup_session_upgrade_subject',ROOT/'deployment/upgrade_go_erp_startup_session_root.py')
runtime=load('startup_session_upgrade_runtime_fixture',ROOT/'tests/test_go_erp_startup_session_tick.py')
previous=load('startup_session_previous_upgrade',REFERENCE/'deployment/upgrade_go_erp_performance_root.py')
frozen=runtime.original_tests.proof.frozen_module
OLD=runtime.OLD_PAYLOADS
NEW=runtime.NEW_PAYLOADS
old_source=frozen('startup_old_actual_source',OLD['go_erp_live_source.py'])
new_source=frozen('startup_new_actual_source',NEW['go_erp_live_source.py'])
old_worker=frozen('startup_old_actual_worker',OLD['go_erp_live_updates.py'])
new_worker=frozen('startup_new_actual_worker',NEW['go_erp_live_updates.py'])
RELEASE={'version':'0.4.4-preview.51','sequence':40054814949,'source_revision':subject.INPUT_CANDIDATE,
    'source':{'format':1,'files':1355,'framework':'8.83.29','sha256':'a'*64},'gui':{},
    'installer':{'sha256':'b'*64,'bytes':20,'path':'/api/desktop-dashboard/update/installer/'+'b'*64}}

def sha(raw):return hashlib.sha256(raw).hexdigest()

def published_state():
    release=copy.deepcopy(RELEASE)
    return {'format':1,'base_revision':subject.OLD_SEED,'counter':51,'last_remote_sha':subject.INPUT_CANDIDATE,'observed':None,
        'desired':{'commit':subject.INPUT_CANDIDATE,'version':'0.4.4-preview.51','candidatePolicy':subject.OLD_POLICY,
            'inventorySha256':'d'*64,'pushed':True,'status':'published'},
        'published':{'commit':subject.INPUT_CANDIDATE,'inventorySha256':'d'*64,'run_id':1,'run_attempt':1,'release':release},
        'published_history':[{'version':'0.4.4-preview.50'},copy.deepcopy(release)],
        'version_floor':'0.4.4-preview.51','sequence_floor':release['sequence'],
        'dependency_guard':{'composer.lock':'e'*64},'unrelated_future_field':{'keep':True}}


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
        self.assertEqual(old_worker.PREVIEW31_POLICY,subject.OLD_POLICY)
        self.assertEqual(runtime.subject.NEW_COHORT['runtime'],
            subject.NATIVE_RELEASES/runtime.original_tests.proof.prior_test.utility.runtime_name(actual))
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
    def test_changed_frozen_runtime_bytes_or_wrong_substitution_context_fail_closed(self):
        for target in ('go_erp_live_source.py','go_erp_live_updates.py'):
            changed=dict(OLD);changed[target]+=b'\n# drift\n'
            with self.assertRaisesRegex(subject.Stop,'OLD_NATIVE_PAYLOAD_CHANGED'):
                subject.derive_native_payloads(changed,runtime.subject.OLD_COHORT['pins'],subject.SEED)
        with self.assertRaisesRegex(subject.Stop,'NATIVE_RELEASE_INVALID'):
            subject.derive_native_payloads(OLD,runtime.subject.OLD_COHORT['pins'],subject.OLD_SEED)
    def test_state_rebind_preserves_publication_counter_history_and_removed_authority(self):
        state=published_state()
        inventory={'public/removed.js':{'authority':'removed','present':False,'sha256':None,'bytes':0,'mode':'100644'}}
        state['observed']=old_source._metadata(subject.OLD_SEED,inventory)
        original=copy.deepcopy(state);result=subject.migrate_state(state,old_source,new_source)
        self.assertEqual(state,original)
        self.assertEqual(result['base_revision'],subject.SEED)
        self.assertEqual(result['observed'],new_source._metadata(subject.SEED,inventory))
        for key in state:
            if key not in ('base_revision','observed'):self.assertEqual(result[key],state[key],key)
        low=published_state();low['counter']=50
        with self.assertRaisesRegex(subject.Stop,'STATE_IDENTITY_INVALID'):subject.migrate_state(low,old_source,new_source)


class PublishedStateTests(unittest.TestCase):
    def test_only_a_quiet_fully_published_program_release_is_accepted_unchanged(self):
        state=published_state();before=copy.deepcopy(state)
        self.assertEqual(subject.checked_published_state(state,copy.deepcopy(RELEASE)),state['desired'])
        self.assertEqual(state,before)
    def test_active_blocked_or_unpublished_candidates_are_refused(self):
        edits=[('status','waiting-ci'),('status','blocked'),('status','retry-build'),('status','prepared'),
            ('commit','f'*40),('version','0.4.4-preview.52'),('candidatePolicy',subject.POLICY),
            ('inventorySha256','f'*64),('commit',None)]
        for key,value in edits:
            with self.subTest(key=key,value=value):
                state=published_state();state['desired'][key]=value
                with self.assertRaisesRegex(subject.Stop,'QUIET_PUBLISHED_PERFORMANCE_RELEASE_REQUIRED'):
                    subject.checked_published_state(state,copy.deepcopy(RELEASE))
        for key,value in (('counter',50),('last_remote_sha','f'*40),('base_revision',subject.SEED),
                ('version_floor','0.4.4-preview.50'),('published_history',[]),('desired',None),('published',None)):
            with self.subTest(key=key):
                state=published_state();state[key]=value
                with self.assertRaisesRegex(subject.Stop,'QUIET_PUBLISHED_PERFORMANCE_RELEASE_REQUIRED'):
                    subject.checked_published_state(state,copy.deepcopy(RELEASE))
    def test_missing_or_different_public_feed_is_refused(self):
        for feed in (None,{**RELEASE,'sequence':1}):
            with self.assertRaisesRegex(subject.Stop,'PUBLIC_FEED_NOT_CURRENT'):
                subject.checked_published_state(published_state(),feed)


    def test_another_fully_published_candidate_cannot_replace_the_exact_input(self):
        state=published_state();other='f'*40
        state['last_remote_sha']=state['desired']['commit']=state['published']['commit']=other
        with self.assertRaisesRegex(subject.Stop,'PUBLISHED_INPUT_CANDIDATE_CHANGED'):
            subject.checked_published_state(state,copy.deepcopy(RELEASE))



class FileTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='startup-session-files-');self.addCleanup(temporary.cleanup)
        self.folder=Path(temporary.name);self.work=self.folder/'work';self.control=self.folder/'control'
        self.work.mkdir(mode=0o700);(self.control/'private/audits').mkdir(parents=True,mode=0o700)
        from go_erp_performance_reference import REFERENCE as ORIGINAL_REFERENCE
        self.engine=load('startup_session_actual_file_engine',ORIGINAL_REFERENCE/'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop=subject.Stop
        for name,value in (('WORK',self.work),('CONTROL',self.control)):
            scope=patch.object(subject,name,value);scope.start();self.addCleanup(scope.stop)
        ctx=object.__new__(subject.Context);ctx.e=self.engine
        ctx.ensure_source=lambda:None
        ctx.base=types.SimpleNamespace(parse=json.loads,normalized_source=lambda files:{'format':1,'files':len(files),
            'framework':'8.83.29','sha256':sha(json.dumps(sorted(files)).encode())})
        ctx.prior_job_path=self.control/'private'/('performance-'+subject.OLD_CORE+'.json')
        ctx.job_path=self.control/'private'/('startup-session-'+subject.CORE+'.json')
        ctx.runtime=Path('/opt/go-erp-live-updates/releases')/runtime.subject.NEW_COHORT['runtime'].name
        ctx.worker=types.SimpleNamespace(program_gate_expected=new_worker.program_gate_expected)
        ctx.save=lambda:self.engine.atomic_write(ctx.job_path,self.engine.canonical(ctx.job),0,0,0o600)
        self.ctx=ctx
        self.prior={'format':1,'kind':'go-erp-performance-rollout','core':subject.OLD_CORE,'seed':subject.OLD_SEED,
            'runtime':subject.OLD_RUNTIME,'phase':'ready','candidate':'c'*40,'version':'0.4.4-preview.51'}
        self.write(ctx.prior_job_path,self.prior)
        self.write(self.work/('program-rollout-completed-'+subject.OLD_CORE+'.json'),{'format':1,
            'kind':'go-erp-program-rollout-completed','core':subject.OLD_CORE,'canonical_publication_verified':True})
    def write(self,path,value):self.engine.atomic_write(path,self.engine.canonical(value),0,0,0o600)
    def test_ready_previous_rollout_and_retired_hold_are_accepted(self):
        self.assertEqual(json.loads(self.ctx.verify_predecessor()),self.prior)
        self.ctx.quiet_hold()
    def test_unfinished_previous_rollout_missing_completion_or_present_hold_are_refused(self):
        for phase in ('preparing','prepared','running'):
            self.write(self.ctx.prior_job_path,{**self.prior,'phase':phase})
            with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ROLLOUT_NOT_READY'):self.ctx.verify_predecessor()
        self.write(self.ctx.prior_job_path,self.prior)
        (self.work/('program-rollout-completed-'+subject.OLD_CORE+'.json')).unlink()
        with self.assertRaisesRegex(subject.Stop,'FILE_MISSING'):self.ctx.verify_predecessor()
        self.write(self.work/'program-rollout-hold.json',{'format':1,'kind':'go-erp-program-rollout-hold','authorization':None})
        with self.assertRaisesRegex(subject.Stop,'PREVIOUS_ROLLOUT_HOLD_PRESENT'):self.ctx.quiet_hold()
    def first_run(self,state,feed):
        files={'app/example.php':b'unchanged\n'}
        inventory={'app/example.php':{'authority':'live','present':True,'sha256':sha(files['app/example.php']),
            'bytes':len(files['app/example.php']),'mode':'100644'}}
        reads=[]
        self.ctx.state=lambda:state;self.ctx.publication=lambda:feed
        def snapshot(current,checkout,source):
            reads.append(current['base_revision'])
            return {'metadata':old_source._metadata(subject.OLD_SEED,inventory),'_files':files}
        self.ctx.snapshot=snapshot;self.ctx.old_source=old_source
        self.ctx.target_source=lambda before:(self.ctx.base.normalized_source(files),'e'*64)
        with patch.object(subject,'INPUT_INVENTORY',old_source._metadata(subject.OLD_SEED,inventory)['inventorySha256']):
            self.ctx.job_record()
        return reads,'e'*64
    def test_first_run_binds_current_live_source_remote_lease_and_new_core_without_a_hold(self):
        reads,inventory=self.first_run(published_state(),copy.deepcopy(RELEASE))
        job=json.loads(self.ctx.job_path.read_bytes())
        self.assertEqual(reads,[subject.OLD_SEED])
        self.assertEqual((job['kind'],job['core'],job['seed'],job['phase'],job['remote_lease'],job['inventory']),
            ('go-erp-startup-session-rollout',subject.CORE,subject.SEED,'preparing',subject.INPUT_CANDIDATE,inventory))
        self.assertEqual(self.ctx.expected,new_worker.program_gate_expected(subject.CORE,job['source'],inventory))
        self.assertFalse((self.work/'program-rollout-hold.json').exists())
        audit=Path(job['audit'])
        self.assertEqual(json.loads((audit/'previous-rollout.json').read_bytes()),self.prior)
        self.assertEqual(json.loads((audit/'previous-state.json').read_bytes())['counter'],51)
        # A resumed run trusts the saved journal and never re-plans from new source.
        self.ctx.snapshot=lambda *a:(_ for _ in ()).throw(AssertionError('no second plan'))
        with patch.object(subject,'INPUT_INVENTORY',job['input_inventory']):
            self.ctx.job_record()
        self.assertEqual(self.ctx.job,job)
    def test_first_run_refuses_an_active_build_before_reading_source_or_writing_a_journal(self):
        state=published_state();state['desired']['status']='waiting-ci'
        with self.assertRaisesRegex(subject.Stop,'QUIET_PUBLISHED_PERFORMANCE_RELEASE_REQUIRED'):
            self.first_run(state,copy.deepcopy(RELEASE))
        self.assertFalse(self.ctx.job_path.exists())
        self.assertEqual(list((self.control/'private/audits').iterdir()),[])


class CoordinatorOrderTests(unittest.TestCase):
    @contextmanager
    def watcher(self,*args):yield lambda:None
    def test_archive_must_commit_before_ready_and_a_published_resume_never_allocates_again(self):
        ctx=object.__new__(subject.Context);events=[]
        ctx.e=object();ctx.utility=types.SimpleNamespace(watcher_lock=self.watcher)
        ctx.job={'phase':'running','candidate':'f'*40,'version':'0.4.4-preview.52'}
        ctx.state=lambda:{};ctx.live=lambda state:events.append('live-source-and-controls')
        ctx.ready=lambda state:True;ctx.candidate=lambda state:{'commit':'f'*40,'version':'0.4.4-preview.52'}
        ctx.archive=lambda:events.append('verified-archive-current51-previous50') or {'available':True}
        ctx.save=lambda:events.append('saved-'+ctx.job['phase'])
        ctx.tick=lambda *args:(_ for _ in ()).throw(AssertionError('no second allocation'))
        with patch.object(subject,'quiet'):ctx.complete()
        self.assertEqual(events,['live-source-and-controls','verified-archive-current51-previous50','saved-ready'])
    def test_failed_archive_keeps_the_rollout_unready(self):
        ctx=object.__new__(subject.Context);ctx.e=object();ctx.utility=types.SimpleNamespace(watcher_lock=self.watcher)
        ctx.job={'phase':'running','candidate':'f'*40,'version':'0.4.4-preview.52'}
        ctx.state=lambda:{};ctx.live=lambda state:None;ctx.ready=lambda state:True
        ctx.candidate=lambda state:{'commit':'f'*40,'version':'0.4.4-preview.52'}
        ctx.archive=lambda:(_ for _ in ()).throw(subject.Stop('ARCHIVE_INCOMPLETE'))
        ctx.save=lambda:(_ for _ in ()).throw(AssertionError('must not mark ready'))
        with patch.object(subject,'quiet'),self.assertRaisesRegex(subject.Stop,'ARCHIVE_INCOMPLETE'):ctx.complete()
        self.assertEqual(ctx.job['phase'],'running')
    def test_old_to_new_transaction_rechecks_the_quiet_publication_before_any_source_read(self):
        ctx=object.__new__(subject.Context);ctx.job={'remote_lease':subject.INPUT_CANDIDATE}
        ctx.expected={};ctx.e=types.SimpleNamespace(read_file=lambda *a,**k:None)
        changed=published_state();changed['desired']['status']='waiting-ci'
        base=types.SimpleNamespace()
        base.upgrade=lambda *a,**k:base.migrated_state(changed,subject.OLD_SEED,subject.SEED,copy.deepcopy(RELEASE))
        ctx.base=base;ctx.utility=object();ctx.payloads={};ctx.pins={};ctx.old_tick=types.SimpleNamespace(PINS={})
        ctx.snapshot=lambda *args:(_ for _ in ()).throw(AssertionError('must reject before a source read'))
        with self.assertRaisesRegex(subject.Stop,'QUIET_PUBLISHED_PERFORMANCE_RELEASE_REQUIRED'):ctx.prepare(object())
        moved=published_state();moved['last_remote_sha']='f'*40;moved['desired']['commit']=moved['published']['commit']='f'*40
        base.upgrade=lambda *a,**k:base.migrated_state(moved,subject.OLD_SEED,subject.SEED,copy.deepcopy(RELEASE))
        with self.assertRaisesRegex(subject.Stop,'PUBLISHED_INPUT_CANDIDATE_CHANGED'):ctx.prepare(object())
    def test_pending_owner_requests_are_not_stopped_deleted_or_consumed_by_the_quiet_guard(self):
        events=[]
        def run(args):
            events.append(args)
            return 'disabled' if args[-2]=='UnitFileState' else 'inactive'
        subject.quiet(types.SimpleNamespace(run=run,read_file=lambda *a,**k:None))
        self.assertFalse(any('stop' in args or 'start' in args for args in events))


class SourceCoordinatorTests(unittest.TestCase):
    def setUp(self):
        self.ctx=object.__new__(subject.Context)
        raw=subprocess.check_output(['git','show',subject.PRIOR+
            ':deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py'],cwd=ROOT)
        self.ctx.base=subject.module(raw,'session_actual_source_fingerprint',subject.BASE_BLOB,True)
        self.ctx.base.EXPECTED_SOURCE={'framework':'8.83.29'}
        self.ctx.old_source=old_source;self.ctx.source=new_source
        self.paths=('config/settings.php','desktop/bootstrap.php')
        self.entries={path:{'before':('<?php // before '+path+'\n').encode(),
            'after':('<?php // fixed '+path+'\n').encode(),
            'mode':'100755' if path==self.paths[0] else '100644'} for path in self.paths}
        self.ctx.source_entries=lambda:self.entries
        self.files={path:row['before'] for path,row in self.entries.items()}
        self.files.update({'app/unchanged.php':b'<?php // unchanged\r\n',
            'desktop/source-contract.json':b'{"framework":"8.83.29"}'})
        self.inventory={path:{'authority':'live','present':True,'sha256':sha(raw),
            'bytes':len(raw),'mode':self.entries.get(path,{}).get('mode','100644')}
            for path,raw in self.files.items()}
        self.before={'metadata':old_source._metadata(subject.OLD_SEED,self.inventory),'_files':self.files}

    def test_target_inventory_changes_exact_two_rows_and_uses_actual_source_fingerprint(self):
        original=copy.deepcopy(self.before);source,inventory=self.ctx.target_source(self.before)
        target=copy.deepcopy(self.inventory);files=dict(self.files)
        for path,row in self.entries.items():
            files[path]=row['after'];target[path].update(sha256=sha(row['after']),bytes=len(row['after']))
        self.assertEqual(inventory,old_source._metadata(subject.OLD_SEED,target)['inventorySha256'])
        self.assertEqual(source,self.ctx.base.normalized_source(files))
        self.assertNotEqual(inventory,self.before['metadata']['inventorySha256'])
        self.assertEqual(self.before,original)
        self.assertEqual({path for path in target if target[path]!=self.inventory[path]},set(self.paths))

    def test_wrong_before_bytes_authority_or_executable_mode_fail_before_source_writes(self):
        for key,value in (('sha256','f'*64),('mode','100644'),('authority','trusted-base')):
            changed=copy.deepcopy(self.before);changed['metadata']['inventory'][self.paths[0]][key]=value
            with self.assertRaisesRegex(subject.Stop,'SOURCE_INPUT_ROWS_CHANGED'):self.ctx.target_source(changed)

    def test_only_old_preparing_unallocated_state_may_apply_or_roll_back_source(self):
        for base,phase,candidate,method in ((subject.OLD_SEED,'preparing',None,'apply'),
            (subject.SEED,'preparing',None,'verify'),(subject.SEED,'prepared',None,'verify'),
            (subject.OLD_SEED,'preparing','c'*40,'verify'),(subject.SEED,'running','c'*40,'verify')):
            calls=[];receipt={'applied':True,'intent_sha256':'e'*64,'paths':list(self.paths)}
            tx=types.SimpleNamespace(apply=lambda *a,**k:calls.append('apply') or receipt,
                                     verify=lambda *a,**k:calls.append('verify') or receipt)
            self.ctx.state=lambda:{'base_revision':base}
            self.ctx.job={'phase':phase,'candidate':candidate,'source_receipt':None}
            self.ctx.source_transaction=lambda:tx;self.ctx.save=lambda:calls.append('save')
            self.ctx.ensure_source()
            self.assertEqual(calls,[method,'save']);self.assertEqual(self.ctx.job['source_receipt'],receipt)

    def test_postpatch_checks_require_target_inventory_on_both_old_and_new_native_seeds(self):
        source,target=self.ctx.target_source(self.before)
        fixed=copy.deepcopy(self.before)
        for path,row in self.entries.items():
            fixed['_files'][path]=row['after']
            fixed['metadata']['inventory'][path].update(sha256=sha(row['after']),bytes=len(row['after']))
        self.ctx.job={'source':source,'inventory':target}
        for base in (subject.OLD_SEED,subject.SEED):
            fixed['metadata']=(old_source if base==subject.OLD_SEED else new_source)._metadata(base,fixed['metadata']['inventory'])
            self.ctx.state=lambda:{'base_revision':base};seen=[]
            self.ctx.snapshot=lambda state,checkout,scanner:seen.append(scanner) or fixed
            self.assertIs(self.ctx.source_target_check(),fixed)
            self.assertEqual(seen,[old_source if base==subject.OLD_SEED else new_source])
        wrong=copy.deepcopy(fixed);wrong['metadata']['inventorySha256']=subject.INPUT_INVENTORY
        self.ctx.snapshot=lambda *args:wrong
        with self.assertRaisesRegex(subject.Stop,'PATCHED_SOURCE_CHANGED'):self.ctx.source_target_check()

    def test_interrupted_native_upgrade_with_old_state_seed_still_never_rolls_back_source(self):
        receipt={'applied':True,'intent_sha256':'e'*64,'paths':list(self.paths)}
        self.ctx.state=lambda:{'base_revision':subject.OLD_SEED}
        self.ctx.job={'phase':'preparing','candidate':None,'source_receipt':receipt}
        calls=[]
        def reject(validate):
            calls.append('verify');raise RuntimeError('native unit switched before state')
        self.ctx.source_transaction=lambda:types.SimpleNamespace(
            apply=lambda *a,**k:self.fail('mixed native crash must not enable source rollback'),verify=reject)
        self.ctx.save=lambda:self.fail('failed verification must not replace the receipt')
        with self.assertRaisesRegex(RuntimeError,'native unit switched before state'):self.ctx.ensure_source()
        self.assertEqual(calls,['verify']);self.assertEqual(self.ctx.job['source_receipt'],receipt)

    def test_source_syntax_uses_only_php_no_ini_parse_without_application_bootstrap(self):
        result=types.SimpleNamespace(returncode=0,stdout=b'No syntax errors',stderr=b'')
        with patch.object(subject.subprocess,'run',return_value=result) as run:
            self.ctx.source_syntax(Path('/private/source/exchange-0'))
        self.assertEqual(run.call_args.args[0],['/opt/cpanel/ea-php82/root/usr/bin/php','-n','-l','/private/source/exchange-0'])
        result.returncode=1;result.stderr=b'PRIVATE_SOURCE_DETAIL_MUST_NOT_APPEAR'
        with patch.object(subject.subprocess,'run',return_value=result),self.assertRaisesRegex(subject.Stop,'^PATCHED_PHP_SYNTAX_FAILED$'):
            self.ctx.source_syntax(Path('/private/source/exchange-0'))


class PinTests(unittest.TestCase):
    def test_manual_and_tick_pins_match_the_packaged_files_and_previous_installation(self):
        for name,path in subject.PACKAGE_PROGRAM_PATHS.items():
            raw=subprocess.check_output(['git','show',subject.ARCHIVE_RELEASE+':'+path],cwd=ROOT) if name=='go_erp_published_download_root.py' else (ROOT/path).read_bytes()
            self.assertEqual(sha(raw),subject.MANUAL_PINS[name],name)
        self.assertEqual(subject.OLD_MANUAL_PINS,previous.MANUAL_PINS)
        self.assertEqual(subject.OLD_TICK_SHA,sha((REFERENCE/'deployment/go_erp_performance_tick_root.py').read_bytes()))
        self.assertEqual((subject.OLD_SEED,subject.OLD_CORE,subject.OLD_POLICY),
            (previous.SEED,previous.CORE,previous.POLICY))
        self.assertEqual(runtime.subject.OLD_COHORT['runtime'].name,subject.OLD_RUNTIME)
        self.assertEqual((runtime.subject.NEW_COHORT['seed'],runtime.subject.NEW_COHORT['policy']),(subject.SEED,subject.POLICY))
        for name in ('BOOTSTRAP','PRIOR','BOOTSTRAP_INSTALL_SHA','ENGINE_SHA','BASE_BLOB','UTILITY_BLOB'):
            self.assertEqual(getattr(subject,name),getattr(previous,name),name)
    def test_unchanged_archive_helper_is_loaded_only_from_the_exact_previous_release(self):
        reads=[]
        def file(path,revision=None):
            reads.append((path,revision));return path.encode()
        ctx=object.__new__(subject.Context);ctx.release=types.SimpleNamespace(file=file)
        def stop_after_payloads(*args,**kwargs):raise subject.Stop('PAYLOADS_RECORDED')
        ctx.e=types.SimpleNamespace(install_runtime=lambda *args:None,read_file=stop_after_payloads)
        with self.assertRaisesRegex(subject.Stop,'PAYLOADS_RECORDED'):ctx.manual()
        for name,path in subject.PACKAGE_PROGRAM_PATHS.items():
            self.assertIn((path,subject.ARCHIVE_RELEASE if name=='go_erp_published_download_root.py' else None),reads)
    def test_exact_input_is_the_original_canonical_preview50_inventory(self):
        candidate=json.loads(self.git('show',subject.INPUT_CANDIDATE+':deployment/go_erp_live_source_candidate.json').stdout)
        self.assertEqual(candidate['version'],subject.INPUT_VERSION)
        self.assertEqual(candidate['inventorySha256'],subject.INPUT_INVENTORY)

    def git(self,*args):
        return subprocess.run(['git',*args],cwd=ROOT,capture_output=True,text=True)
    def test_seed_and_core_lineage_when_the_commits_are_available(self):
        if self.git('cat-file','-e',subject.SEED+'^{commit}').returncode:
            self.skipTest('seed commit not fetched')
        self.assertEqual(self.git('rev-parse',subject.SEED+'^').stdout.strip(),subject.CORE)
        self.assertEqual(self.git('merge-base','--is-ancestor',subject.INPUT_CANDIDATE,subject.CORE).returncode,0)
        verifier=self.git('show',subject.SEED+':deployment/verify_go_erp_preview31_seed.py').stdout
        self.assertIn("CORE = '"+subject.CORE+"'",verifier)
        self.assertIn("POLICY = '"+subject.POLICY.replace('historical-baseline-v2-','')+"'",verifier)
        changed=set(self.git('diff','--name-only',subject.CORE,subject.SEED).stdout.split())
        self.assertEqual(changed,{'.github/workflows/go-erp-update-tests.yml',
            'deployment/verify_go_erp_preview31_seed.py','tests/test_go_erp_preview31_seed.py'})
        core=set(self.git('diff','--name-only',subject.INPUT_CANDIDATE,subject.CORE).stdout.split())
        source_roots=('app/','bootstrap/','config/','database/','desktop/','public/','resources/','routes/')
        self.assertEqual({path for path in core if path.startswith(source_roots)},{'config/settings.php','desktop/bootstrap.php'})
        self.assertEqual(self.git('show',subject.PREVIOUS+':deployment/go_erp_performance_tick_root.py').stdout.encode(),
            (REFERENCE/'deployment/go_erp_performance_tick_root.py').read_bytes())


if __name__=='__main__':unittest.main()
