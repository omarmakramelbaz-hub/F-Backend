"""Concrete loader integration with real temp inode/WAL effects and fake services."""
import copy
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
subject=module('concrete_workspace_root','deployment/go_erp_shared_workspace_root.py')
states=module('concrete_workspace_states','deployment/go_erp_shared_workspace_states.py')
fixture=module('concrete_activation_fixtures','tests/test_go_erp_shared_workspace_activation_root.py')
io=fixture.source


class RootGuardTests(unittest.TestCase):
    def test_unknown_final_pins_block_before_package_host_account_or_process(self):
        with patch.object(subject,'Package',side_effect=AssertionError('package opened')), \
                patch.object(subject.pwd,'getpwnam',side_effect=AssertionError('account queried')):
            with self.assertRaisesRegex(subject.Stop,'FINAL_PINS_UNAVAILABLE'):
                subject.main(['--git-dir','/not-opened','--release','a'*40,'--complete'])

    def test_shell_wrapper_unavailable_pins_exit_before_host_stage_or_network(self):
        result=subprocess.run(['/bin/bash',str(ROOT/'deployment/run_go_erp_shared_workspace_root.sh'),
            'a'*40,'--complete'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
        self.assertEqual(78,result.returncode)
        self.assertEqual(b'',result.stdout)
        self.assertEqual(b'GO_ERP_SHARED_WORKSPACE_FINAL_PINS_UNAVAILABLE\n',result.stderr)

    @unittest.skipUnless(os.geteuid()==0,'Original locks are root-private')
    def test_actual_lock_descriptor_and_inode_are_required(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(subject,'PRIVATE',Path(folder)):
            opened=[]
            try:
                for name in ('install.lock','worker.lock'):
                    path=Path(folder)/name;path.write_bytes(b'');path.chmod(0o600)
                with self.assertRaisesRegex(subject.Stop,'ORIGINAL_LOCK_NOT_HELD'):
                    subject.verify_outer_locks(io)
                for name in ('install.lock','worker.lock'):
                    stream=open(Path(folder)/name,'r+b');opened.append(stream)
                    fcntl.flock(stream.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
                subject.verify_outer_locks(io)
                peer=Path(folder)/'peer';peer.write_bytes(b'');peer.chmod(0o600)
                os.replace(peer,Path(folder)/'worker.lock')
                with self.assertRaisesRegex(subject.Stop,'ORIGINAL_LOCK_NOT_HELD'):
                    subject.verify_outer_locks(io)
            finally:
                for stream in opened:stream.close()

    @unittest.skipUnless(os.geteuid()==0,'Private checkpoint fixture requires root')
    def test_root_checkpoint_requires_immutable_hash_record_and_same_compiled_pins(self):
        with tempfile.TemporaryDirectory() as folder:
            operator=subject.Operator.__new__(subject.Operator)
            operator.io=io;operator.pins={'test_public_pin':'a'*64};operator.audit=Path(folder)/'audit'
            operator.current=Path(folder)/'current.json'
            def atomic(path,raw,*_identity):path.write_bytes(raw);path.chmod(0o600)
            def read(path,*_args,optional=False,**_kwargs):
                if not path.exists() and optional:return None
                return io.read(path,0,0,private=True)
            operator.engine=types.SimpleNamespace(atomic_write=atomic,read_file=read)
            value={'release_pins_sha256':subject.sha(subject.canonical(operator.pins)),'phase':'observed'}
            operator._save(value,lambda:None);self.assertEqual(value,operator._load())
            operator.pins['test_public_pin']='b'*64
            with self.assertRaisesRegex(subject.Stop,'ROOT_CHECKPOINT_PINS_CHANGED'):operator._load()
            operator.pins['test_public_pin']='a'*64
            record=next(operator.audit.glob('checkpoint-*'));record.write_bytes(b'{}\n')
            with self.assertRaisesRegex(subject.Stop,'ROOT_CHECKPOINT_RECORD_CHANGED'):operator._load()

    def test_actual_native_factory_and_manual_payload_wiring_for_both_predecessors(self):
        # Only read immutable local git objects and construct actual original
        # Python modules. No Context constructor, runtime install or tick occurs.
        sys.path.insert(0,str(ROOT/'tests'))
        import go_erp_owner_fast_open_reference as reference
        native=module('root_native_wiring','deployment/go_erp_shared_workspace_native_root.py')
        payloads=module('root_payload_wiring','deployment/go_erp_shared_workspace_payloads.py')
        performance=module('root_perf_wiring','deployment/go_erp_owner_fast_open_payloads.py')
        native_fixture=module('root_native_fixtures','tests/test_go_erp_shared_workspace_native_root.py')
        def gitfile(name,revision):
            return subprocess.check_output(['git','show',revision+':'+name],cwd=ROOT)
        template=subject.load(native_fixture.TEMPLATE,'root_factory_template',subject.TEMPLATE_SHA)
        utility=template.module(gitfile('deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py',template.PRIOR),
            'root_wiring_utility',template.UTILITY_BLOB,True)
        hashes={'helper':'1'*64,'fixture':'2'*64,'nativeModule':'3'*64}
        packaged={'main.cjs':'4'*64,'workspace-native.cjs':'5'*64,'workspace-policy.cjs':'6'*64}
        for counter in (59,60):
            with self.subTest(counter=counter):
                profile=native.PROFILES[counter]
                old=dict(reference.NEW)
                tick=reference.tick if counter==59 else module('wiring_fast_tick','deployment/go_erp_owner_fast_open_tick_root.py')
                if counter==60:
                    old['go_erp_live_updates.py']=performance.transform(old['go_erp_live_updates.py'])
                    for name in ('go_erp_live_source.py','go_erp_live_updates.py'):
                        old[name]=old[name].replace(native.PROFILES[59]['seed'].encode(),profile['seed'].encode()).replace(
                            native.PROFILES[59]['policy'].removeprefix('historical-baseline-v2').encode(),
                            profile['policy'].removeprefix('historical-baseline-v2').encode())
                target=payloads.native(old,performance,seed='b'*40,startup_hashes=hashes,packaged_native_hashes=packaged)
                operator=subject.Operator.__new__(subject.Operator)
                previous=native_fixture.fixtures(counter)[0]['predecessor']
                operator.flow={'predecessor_observation':{'predecessor':previous},'prepared':{'inventory_sha256':'9'*64}}
                operator.pins={'core':'a'*40,'seed':'b'*40,'web_release':'c'*40,'contract_sha256':'d'*64,
                    'startup_hashes':hashes,'packaged_native_hashes':packaged,'helpers':{'native':'e'*64},
                    'native_payloads':{name:{'blob':native.blob(raw),'size':len(raw),'sha256':native.sha(raw)} for name,raw in target.items()}}
                operator.package=types.SimpleNamespace(file=lambda name,revision=None:gitfile(name,revision),git=lambda *_args:b'')
                operator.modules={'native':native,'payloads':payloads};operator.template=template;operator.utility=utility
                class Engine:
                    def read_file(self,path,*_args,**_kwargs):
                        self.assertion=path.parent==tick.NEW_COHORT['runtime']
                        if not self.assertion:raise AssertionError('Unexpected native runtime read')
                        return old[path.name],None
                operator.engine=Engine()
                with patch.object(native,'_old_runtime',return_value=(tick,None,None)):
                    configured=operator._native_configuration(lambda:True,lambda *_args:None)
                self.assertEqual(profile['core'],configured.OLD_CORE)
                configured.checked_native_core(operator.package)
                derived=configured.derive_native_payloads(old,tick.NEW_COHORT['pins'],'b'*40)
                self.assertEqual(target,derived)
                self.assertEqual(7,len(configured.approved_pins['native_payloads']))


@unittest.skipUnless(os.geteuid()==0,'Actual root-private activation WAL requires root')
class RootActivationIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.fixture=fixture.ActivationTest(methodName='runTest');self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)
        self.actor=self.fixture.engine;self.saved=[]
        self.operator=subject.Operator.__new__(subject.Operator)
        self.operator.states=states;self.operator.pins={'contract_sha256':'8'*64}
        self.operator.template=types.SimpleNamespace()
        binding={**copy.deepcopy(self.fixture.pins['binding']),'candidate_revision':None,
            'web_release':'1'*40,'native_core':'2'*40,'seed':'3'*40,'policy':states.POLICY,
            'contract_sha256':'8'*64,'migration_receipt_sha256':None}
        self.operator.flow={'prepared':{'binding':binding,'migration_checkpoint':{'state':'owned-complete'}},
            'activation':None}
        def activation(value,_verify,plan_sha,pins_sha):
            self.actor.pins['binding']=copy.deepcopy(value)
            self.actor.pins['plan_sha256']=plan_sha;self.actor.pins['pins_sha256']=pins_sha
            return self.actor,self.fixture.runner
        self.operator._activation=activation
        self.operator._save=self.save
        self.proof={'candidate_revision':'4'*40,**{name:True for name in states.GATE_TRUE_FIELDS}}
    def save(self,value,verify):
        self.assertIsNot(verify(),False);self.saved.append(copy.deepcopy(value));self.operator.flow=copy.deepcopy(value)

    def test_root_drives_real_env_cache_reload_and_https_to_active_then_revalidates_resume(self):
        self.operator._activate(None,self.proof,lambda:True)
        self.assertEqual('active',self.operator.flow['activation']['phase'])
        self.assertTrue(fixture.subject.env_enabled(self.fixture.env.read_bytes()))
        self.assertEqual(1,self.fixture.runner.calls.count(('systemctl','reload')))
        self.assertEqual(3,len(self.fixture.https.calls))
        self.assertEqual([],list((self.fixture.root/'public').iterdir()))
        inode=self.fixture.env.stat().st_ino;calls=len(self.fixture.https.calls)
        self.operator._activate(None,self.proof,lambda:True)
        self.assertEqual(inode,self.fixture.env.stat().st_ino)
        self.assertEqual(calls+1,len(self.fixture.https.calls))
        self.assertEqual(1,self.fixture.runner.calls.count(('systemctl','reload')))

    def test_root_resumes_completed_flag_effect_without_replacing_or_allocating(self):
        def crash(value,verify):
            if value['activation']['phase']=='flag-written':raise fixture.Crash()
            self.save(value,verify)
        self.operator._save=crash
        with self.assertRaises(fixture.Crash):self.operator._activate(None,self.proof,lambda:True)
        self.assertEqual('flag-write-pending',self.operator.flow['activation']['phase'])
        inode=self.fixture.env.stat().st_ino;self.operator._save=self.save
        self.operator._activate(None,self.proof,lambda:True)
        self.assertEqual('active',self.operator.flow['activation']['phase'])
        self.assertEqual(inode,self.fixture.env.stat().st_ino)

    def test_missing_one_original_gate_keeps_flag_false_before_any_process(self):
        self.proof['employee_acceptance_accepted']=False
        with self.assertRaisesRegex(states.Stop,'FULL_ACCEPTANCE_REQUIRED'):
            self.operator._activate(None,self.proof,lambda:True)
        self.assertFalse(fixture.subject.env_enabled(self.fixture.env.read_bytes()))
        self.assertEqual([],self.fixture.runner.calls)

    def test_conditional_existing_route_cache_is_rebuilt_and_proved(self):
        self.operator.flow['prepared']['binding']['route_cache_required']=True
        route=self.fixture.root/'bootstrap/cache/routes-v7.php';route.write_bytes(b'old reviewed routes');route.chmod(0o644)
        self.operator._activate(None,self.proof,lambda:True)
        self.assertEqual(1,self.fixture.runner.calls.count(('php','routes')))
        self.assertIsNotNone(self.operator.flow['activation']['facts']['route_cache_sha256'])

    def test_active_resume_peer_cache_change_cannot_print_success(self):
        self.operator._activate(None,self.proof,lambda:True)
        self.fixture.runner.write_cache(False)
        with self.assertRaisesRegex(fixture.subject.Stop,'HTTPS_CACHE_FLAG_CHANGED'):
            self.operator._activate(None,self.proof,lambda:True)


if __name__=='__main__':unittest.main()
