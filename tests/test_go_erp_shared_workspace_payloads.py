"""Exact old collector/manual byte derivation; no process, server or allocation."""
import ast
import json
import os
import importlib.util
from pathlib import Path
import sys
import types
import unittest
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tests'))
import go_erp_owner_fast_open_reference as reference
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
subject=module('workspace_payloads','deployment/go_erp_shared_workspace_payloads.py')
performance=module('performance_payloads','deployment/go_erp_owner_fast_open_payloads.py')
tick=module('original_tick','deployment/go_erp_owner_fast_open_tick_root.py')

def frozen(raw):
    value=types.ModuleType('shared_worker_fixture');value.__file__='<pinned-native-worker>';exec(compile(raw,value.__file__,'exec'),value.__dict__);return value

class PayloadTests(unittest.TestCase):
    def setUp(self):
        self.hashes={'helper':'1'*64,'fixture':'2'*64,'nativeModule':'3'*64}
        self.packaged={'main.cjs':'4'*64,'workspace-policy.cjs':'5'*64,'workspace-native.cjs':'6'*64}
    def derive(self,payloads):
        return subject.native(payloads,performance,seed='a'*40,startup_hashes=self.hashes,packaged_native_hashes=self.packaged)
    def test_59_and_performance60_converge_to_same_new_worker_with_all_old_functions_retained(self):
        old=dict(reference.NEW);fast=dict(old)
        fast['go_erp_live_updates.py']=performance.transform(old['go_erp_live_updates.py'])
        fast['go_erp_live_updates.py']=fast['go_erp_live_updates.py'].replace(subject.OLD_SEED.encode(),b'b'*40).replace(subject.OLD_SUFFIX.encode(),subject.FAST_SUFFIX.encode())
        result=self.derive(old);self.assertEqual(self.derive(fast),result)
        worker=frozen(result['go_erp_live_updates.py'])
        self.assertEqual(worker.PREVIEW31_SEED,'a'*40);self.assertEqual(worker.PREVIEW31_POLICY,'historical-baseline-v2'+subject.SUFFIX)
        def functions(raw):return {node.name:ast.get_source_segment(raw.decode(),node) for node in ast.parse(raw.decode()).body if isinstance(node,(ast.FunctionDef,ast.ClassDef))}
        prior=functions(performance.transform(old['go_erp_live_updates.py']));after=functions(result['go_erp_live_updates.py'])
        self.assertEqual(set(prior),set(after))
        for name,raw in prior.items():
            if name!='verify_installed_startup_source':self.assertEqual(after[name],raw,name)
        self.assertIn("'packagedNativeHashes'",after['verify_installed_startup_source'])
        self.assertIn(repr(dict(sorted(self.packaged.items()))),after['verify_installed_startup_source'])
    @unittest.skipUnless(os.geteuid()==0,'The original verifier requires root-owned CI artifact files')
    def test_actual_original_verifier_requires_every_new_workspace_asar_hash(self):
        worker=frozen(self.derive(reference.NEW)['go_erp_live_updates.py'])
        value={'format':1,'kind':'go-erp-startup-source-native','sourceRevision':'c'*40,
            'sourceHashes':self.hashes,'packagedNativeHashes':self.packaged,'installedAsarCode':True,
            **{name:True for name in performance.TRUE_FLAGS},**{name:False for name in performance.FALSE_FLAGS}}
        with tempfile.TemporaryDirectory(prefix='workspace-asar-proof-') as folder:
            path=Path(folder)/performance.PROOF
            path.write_text(json.dumps(value));path.chmod(0o600)
            worker.verify_installed_startup_source(Path(folder),{'commit':'c'*40})
            for name in self.packaged:
                bad={**value,'packagedNativeHashes':{key:item for key,item in self.packaged.items() if key!=name}}
                path.write_text(json.dumps(bad))
                with self.subTest(name=name),self.assertRaisesRegex(worker.WatchError,'CI_INSTALLED_STARTUP_PROOF_INVALID'):
                    worker.verify_installed_startup_source(Path(folder),{'commit':'c'*40})
    def test_changed_old_code_or_incomplete_new_asar_map_fails_before_any_install(self):
        payloads=dict(reference.NEW);payloads['go_erp_live_updates.py']+=b'\n'
        with self.assertRaisesRegex(subject.Stop,'ORIGINAL_WORKER_CHANGED'):self.derive(payloads)
        with self.assertRaisesRegex(subject.Stop,'EXACT_ASAR_NATIVE_MAP_REQUIRED'):
            subject.native(reference.NEW,performance,seed='a'*40,startup_hashes=self.hashes,packaged_native_hashes={'main.cjs':'1'*64})
    def test_manual_tick_derives_only_constants_and_broker_only_new_tick_hash(self):
        old_tick=(ROOT/'deployment/go_erp_owner_fast_open_tick_root.py').read_bytes()
        old_broker=(ROOT/'deployment/go_erp_owner_fast_open_manual_root.py').read_bytes()
        new=dict(tick.NEW_COHORT);new.update(seed='a'*40,policy=subject.POLICY)
        new['runtime']=Path('/opt/go-erp-live-updates/releases/policy-'+'b'*64)
        result=subject.manual(old_tick,old_broker,previous=tick.NEW_COHORT,new=new)
        def functions(raw):return {node.name:ast.get_source_segment(raw.decode(),node) for node in ast.parse(raw.decode()).body if isinstance(node,(ast.FunctionDef,ast.ClassDef))}
        self.assertEqual(functions(old_tick),functions(result['go_erp_manual_tick_root.py']))
        self.assertEqual(functions(old_broker),functions(result['go_erp_manual_updates_root.py']))
        new_tick=frozen(result['go_erp_manual_tick_root.py'])
        self.assertEqual(new_tick.NEW_COHORT['policy'],subject.POLICY);self.assertEqual(new_tick.OLD_COHORT,tick.NEW_COHORT)
        self.assertIn(subject.sha(result['go_erp_manual_tick_root.py']).encode(),result['go_erp_manual_updates_root.py'])

if __name__=='__main__':unittest.main()
