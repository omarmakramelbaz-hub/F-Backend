"""Cross-module control-flow tests. Fixtures are not installed-server receipts."""
import ast
import copy
from contextlib import contextmanager
import hashlib
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

BASE=Path(__file__).resolve().parents[1]


def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


roll=load('performance_rollout',BASE/'deployment/upgrade_go_erp_desktop_performance_root.py')
current=load('performance_rollout_current',BASE/'deployment/go_erp_desktop_performance_current.py')
assets=load('performance_rollout_source',BASE/'deployment/go_erp_desktop_performance_source.py')
payloads=load('performance_rollout_payloads',BASE/'deployment/go_erp_desktop_performance_payloads.py')


def original(name,fallback,digest):
    fixture={'go_erp_shared_workspace_native_root.py':'native_root.py',
             'upgrade_go_erp_warm_start_root.py':'warm_start_root.py'}[name]
    candidates=(BASE/'tests/fixtures/go_erp_desktop_performance'/fixture,BASE/'deployment'/name,BASE.parent/fallback)
    path=next(path for path in candidates if path.is_file())
    raw=path.read_bytes()
    if hashlib.sha256(raw).hexdigest()!=digest:raise AssertionError('Frozen native fixture changed')
    return raw


NATIVE=original('go_erp_shared_workspace_native_root.py','review/deployment/go_erp_shared_workspace_native_root.py',
    '292475c8a0de24f3203d5115a7efd69aeebe671b62c967fa9f03037956570347')
TEMPLATE=original('upgrade_go_erp_warm_start_root.py','perf_operator/dependencies/upgrade_go_erp_warm_start_root.py',
    '70bfb190e3b53fcb63ee76eb3ebfe16621be524e65ce623b4418386550081861')


class FrozenNativeContracts(unittest.TestCase):
    def test_actual_configure_uses_distinct_new_core_and_current_predecessor(self):
        native=SimpleNamespace()
        exec(compile(NATIVE,'<pinned-native-test>','exec'),native.__dict__)
        # The exact original native configure/template run; no Context or
        # installed runtime is constructed in this contract test.
        profile={'core':current.CORE,'seed':current.SEED,'runtime':'policy-'+'a'*64,
                 'policy':current.POLICY,'operator_release':roll.ROOT_RELEASE,
                 'tick_path':current.TICK_PATH,'job_prefix':'shared-workspace-',
                 'manual_pins':{name:str(index)*64 for index,name in enumerate(sorted(native.MANUAL_NAMES),1)}}
        native.PROFILES[60]=profile
        manual={name:b'ORIGINAL = True\n' for name in native.MANUAL_NAMES}
        previous={'counter':60,'candidate':current.CANDIDATE,'version':current.VERSION,
                  'inventory_sha256':current.INVENTORY,'release_sha256':'a'*64,
                  'acceptance_receipt_sha256':'b'*64}
        pins={'core':roll.CORE,'seed':roll.SEED,'web_release':roll.WEB_RELEASE,'policy':current.POLICY,
              'inventory_sha256':roll.EXPECTED_INVENTORY,'contract_sha256':'c'*64,
              'context_sha256':hashlib.sha256(NATIVE).hexdigest(),
              'native_payloads':{name:{'blob':row[0],'size':row[1],'sha256':row[2]}
                                 for name,row in roll.DERIVED_PINS.items()},
              'manual_programs':{name:{'path':'deployment/'+name,'sha256':roll.sha(raw)}
                                 for name,raw in manual.items()},'predecessor':previous}
        verified=[]
        configured=native.configure(TEMPLATE,SimpleNamespace(),SimpleNamespace(),pins=pins,
            package_payloads=manual,verify_locks=lambda:verified.append(True) or True,
            workspace_acceptance=lambda *_args:None)
        self.assertTrue(verified)
        self.assertEqual(configured.CORE,roll.CORE)
        self.assertEqual(configured.SEED,roll.SEED)
        self.assertNotEqual(configured.CORE,configured.SEED)
        self.assertEqual(configured.OLD_CORE,current.CORE)
        self.assertEqual(configured.OLD_SEED,current.SEED)
        self.assertEqual(configured.OLD_RUNTIME,profile['runtime'])
        self.assertEqual(configured.OLD_MANUAL_PINS,profile['manual_pins'])
        self.assertEqual(configured.INPUT_VERSION,current.VERSION)
        self.assertEqual(configured.MANUAL_PINS,{name:roll.sha(raw) for name,raw in manual.items()})

    def test_public_changes_match_actual_asset_and_native_bytes(self):
        root=next(path for path in (BASE,BASE.parent/'perf_current')
                  if all((path/'desktop-pos/src'/name).is_file() for name in roll.NATIVE_CHANGES))
        for name,digest in roll.NATIVE_CHANGES.items():
            self.assertEqual(roll.sha((root/'desktop-pos/src'/name).read_bytes()),digest)
        for name,pin in assets.PINS.items():
            self.assertEqual(roll.sha((root/name).read_bytes()),pin['after'])
        self.assertEqual(set(roll.DERIVED_PINS),set(payloads.CURRENT_PINS))
        changed={name for name,pin in payloads.CURRENT_PINS.items()
                 if tuple(roll.DERIVED_PINS[name])!=(pin['blob'],pin['size'],pin['sha256'])}
        self.assertEqual(changed,{'go_erp_live_updates.py','go_erp_live_source.py'})

    def test_no_application_database_or_environment_mutation_entrypoints(self):
        tree=ast.parse((BASE/'deployment/upgrade_go_erp_desktop_performance_root.py').read_text())
        attributes={node.func.attr for node in ast.walk(tree)
                    if isinstance(node,ast.Call) and isinstance(node.func,ast.Attribute)}
        self.assertFalse(attributes & {'_prepare','_activation','prepare_env_disabled','prepare_disabled',
                                     'materialize_program','php','chown','chmod','rollback'})


class SourceResume(unittest.TestCase):
    def fixture(self,phase):
        r=roll.Rollout.__new__(roll.Rollout)
        r.flow=None;r.io=SimpleNamespace(private_record=Mock())
        r.current=current;r.payloads=payloads
        r.o=SimpleNamespace(flow=None,_load=lambda:{'retained_original':'ready'})
        r.native=SimpleNamespace(PROFILES={},revalidate_bootstrap=Mock())
        r.package=SimpleNamespace()
        captured={'inventorySha256':roll.EXPECTED_INVENTORY,'inventory':{'fixture':'metadata'}}
        r.io.read=Mock(return_value=(roll.canonical(captured),None))
        r.io.parse=json.loads
        r.load=lambda:{'phase':phase,'current':{'profile':{'core':current.CORE}},
                       'predecessor':{'frozen':'proof'},'captured_metadata_sha256':roll.sha(roll.canonical(captured))}
        r.invariant=Mock()
        r.e=SimpleNamespace()
        r.public_payloads={'public/one.js':b'reviewed'}
        transaction=SimpleNamespace(apply=Mock())
        r.source=SimpleNamespace(transaction=Mock(return_value=transaction))
        r.observe=Mock(side_effect=AssertionError('No repeated current-only observation is allowed on resume'))
        r.capture=Mock(return_value=(captured,None))
        r._old_source=Mock(return_value=(None,None,SimpleNamespace(_metadata=lambda *_args:captured)))
        r.save=Mock(side_effect=lambda value,verify:setattr(r,'flow',value))
        return r,transaction

    def test_prepared_resume_avoids_current_only_observation_and_old_seed_scan(self):
        r,transaction=self.fixture('prepared')
        observation={'profile':{'core':current.CORE}}
        with patch.object(roll,'_reconstruct',return_value=observation):
            self.assertEqual(r.prepare_source(lambda:True),observation)
        r.observe.assert_not_called();r.capture.assert_not_called()
        r.source.transaction.assert_called_once();transaction.apply.assert_called_once()
        self.assertEqual(r.o.flow,{'retained_original':'ready'})

    def test_observed_resume_revalidates_before_original_source_wal_and_captures_after(self):
        r,transaction=self.fixture('observed')
        order=[]
        r.native.revalidate_bootstrap.side_effect=lambda *_args,**_kwargs:order.append('predecessor')
        transaction.apply.side_effect=lambda:order.append('apply')
        r.capture.side_effect=lambda *_args:order.append('fresh-capture') or (json.loads(r.io.read.return_value[0]),None)
        with patch.object(roll,'_reconstruct',return_value={'profile':{'core':current.CORE}}), \
                patch.object(current,'bootstrap_release',return_value='verified-release'):
            r.prepare_source(lambda:True)
        self.assertEqual(order,['predecessor','apply','fresh-capture'])
        self.assertEqual(r.flow['phase'],'prepared')
        r.io.private_record.assert_called_once()

    def test_source_failure_retains_observed_wal_without_native_install_or_rollback(self):
        r,transaction=self.fixture('observed')
        transaction.apply.side_effect=RuntimeError('injected source interruption')
        with patch.object(roll,'_reconstruct',return_value={'profile':{}}), \
                patch.object(current,'bootstrap_release',return_value='verified-release'), \
                self.assertRaisesRegex(RuntimeError,'injected source interruption'):
            r.prepare_source(lambda:True)
        self.assertEqual(r.flow['phase'],'observed')
        r.capture.assert_not_called();r.save.assert_not_called()

    def test_committed_source_bad_fresh_inventory_is_never_marked_prepared(self):
        r,transaction=self.fixture('observed')
        r.capture.return_value=({'inventorySha256':'f'*64},None)
        with patch.object(roll,'_reconstruct',return_value={'profile':{}}), \
                patch.object(current,'bootstrap_release',return_value='verified-release'), \
                self.assertRaisesRegex(roll.Stop,'FRESH_CAPTURED_INVENTORY_CHANGED'):
            r.prepare_source(lambda:True)
        transaction.apply.assert_called_once();r.save.assert_not_called()
        r.io.private_record.assert_not_called()


class OriginalExecution(unittest.TestCase):
    def fixture(self):
        r=roll.Rollout.__new__(roll.Rollout)
        order=[];held=[False]
        @contextmanager
        def hold():
            self.assertFalse(held[0]);held[0]=True;order.append('lock')
            try:yield lambda:True
            finally:held[0]=False;order.append('unlock')
        def verify_outer(_io):
            self.assertTrue(held[0])
        r.o=SimpleNamespace(held=hold,template=SimpleNamespace(WATCH='go-erp-manual-updates.path',quiet=Mock()),
                            modules={})
        r.io=SimpleNamespace();r.root_api=SimpleNamespace(verify_outer_locks=verify_outer)
        r.flow={'phase':'prepared'};r.invariant=Mock()
        r.prepare_source=Mock(return_value={'verified':'old-cohort'})
        r.package=SimpleNamespace(repository='/private/release.git',revision=roll.ROOT_RELEASE)
        proof={'installed_asar_accepted':True,'original_program_gate_accepted':True,'employee_acceptance_accepted':True}
        r.acceptance_pins={'contract_sha256':'c'*64}
        def acceptance_factory(_io,context,_audit,verify,**_kwargs):
            def accept(*_args):
                verify();order.append('accepted');return proof
            return accept
        r.o.modules['acceptance']=SimpleNamespace(WorkspaceAcceptance=acceptance_factory)
        class Context:
            def __init__(self,*_args):
                order.append('context')
            def job_record(self):order.append('job')
            def state(self):return {'published':True}
            def live(self,state):order.append('live')
            def ready(self,state):return True
            def candidate(self,state):return {'commit':'a'*40,'version':roll.VERSION}
        result={'ok':True,'published':True,'version':roll.VERSION,'candidate_revision':'a'*40}
        configured=SimpleNamespace(Context=Context,Release=lambda *_args:'verified-release')
        def execute(*_args):
            self.assertFalse(held[0]);order.append('execute');return dict(result)
        configured.execute=Mock(side_effect=execute)
        def configure(*_args):
            self.assertTrue(held[0]);order.append('configure');return configured
        r.configure=Mock(side_effect=configure)
        r.e=SimpleNamespace(run=Mock(side_effect=lambda argv:'active' if 'show' in argv else ''))
        r.save=Mock(side_effect=lambda value,_verify:setattr(r,'flow',value))
        return r,configured,proof,order

    def test_original_execute_runs_after_configuration_locks_release_and_final_proof(self):
        r,configured,proof,order=self.fixture()
        result=r.run()
        configured.execute.assert_called_once_with('verified-release',r.e,True)
        self.assertLess(order.index('configure'),order.index('execute'))
        self.assertLess(order.index('execute'),order.index('accepted'))
        self.assertEqual(r.flow['phase'],'ready')
        self.assertTrue(result['manual_only']);self.assertFalse(result['automatic_updates_enabled'])
        self.assertFalse(result['database_changed']);self.assertFalse(result['credentials_disclosed'])
        self.assertEqual(r.e.run.call_args_list[-1].args[0],
            ['/usr/bin/systemctl','show','go-erp-manual-updates.path','-p','ActiveState','--value'])

    def test_original_failure_keeps_prepared_journal_for_same_candidate_resume(self):
        r,configured,proof,order=self.fixture()
        configured.execute.side_effect=RuntimeError('injected CI pending')
        with self.assertRaisesRegex(RuntimeError,'injected CI pending'):r.run()
        r.save.assert_not_called();self.assertEqual(r.flow['phase'],'prepared')
        r.e.run.assert_not_called()

    def test_final_incomplete_installed_acceptance_cannot_claim_ready(self):
        for key in ('installed_asar_accepted','original_program_gate_accepted','employee_acceptance_accepted'):
            r,configured,proof,order=self.fixture();proof[key]=False
            with self.subTest(key=key),self.assertRaisesRegex(roll.Stop,'FINAL_ACCEPTANCE_NOT_COMPLETE'):
                r.run()
            r.save.assert_not_called();r.e.run.assert_not_called()


if __name__=='__main__':unittest.main()
