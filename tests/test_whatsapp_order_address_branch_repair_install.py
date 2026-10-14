#!/usr/bin/env python3
"""One Workflow publication; reuse known fixtures without real servers or workers."""
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parents[1]


def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);sys.modules[name]=module
    spec.loader.exec_module(module);return module


repair=load('address_branch_repair_tested',ROOT/'deployment/apply_whatsapp_order_address_branch_repair.py')
fixtures=load('address_branch_known_fixtures',ROOT/'tests/test_whatsapp_order_fragmented_address_repair_install.py')
baseline=fixtures.repair
wrapper=types.ModuleType('address_branch_root_tested')
wrapper.__file__=str(ROOT/'deployment/apply_whatsapp_order_address_branch_repair_root.sh')
body=pathlib.Path(wrapper.__file__).read_text().split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0]
exec(compile(body,wrapper.__file__,'exec'),wrapper.__dict__)


class AddressBranchInstallTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.patch=mock.patch.object(fixtures,'repair',repair);self.patch.start()
        self.f=fixtures.Fixture(self.temp.name)

    def tearDown(self):
        self.patch.stop();self.temp.cleanup()

    def test_workflow_only_source_and_provider_protected(self):
        target='app/Services/Dashboard/WhatsAppOrderWorkflow.php'
        provider='app/Services/Dashboard/WhatsAppOrderAiProvider.php'
        self.assertEqual(list(repair.SOURCE_PINS),[target])
        self.assertEqual(repair.SOURCE_PINS[target][0],'b1bdc5962330c5f61a8ab6fa4c640d0fcae8799d')
        historical=ROOT/'tests/fixtures/whatsapp_order_pre_followup'/target
        self.assertEqual(repair.SOURCE_PINS[target][1],repair.blob(historical.read_bytes()))
        self.assertNotIn(target,repair.PROTECTED_PINS)
        expected=dict(baseline.PROTECTED_PINS);expected.pop(target)
        expected[provider]='cbaaa8ed7014da5bad05d429151afa5ff377ceab'
        expected['app/Services/Dashboard/TakeawayAccess.php']='6b27b7ac2faff6fdaf952494f90f2d8378a12484'
        self.assertEqual(repair.PROTECTED_PINS,expected)
        self.assertEqual(repair.BASE_BLOB,baseline.BASE_BLOB)
        self.assertIn('count($pins)!==1',repair.READINESS_PHP)
        self.assertEqual(wrapper.PUBLISHER_BLOB,repair.blob((ROOT/'deployment/apply_whatsapp_order_address_branch_repair.py').read_bytes()))
        self.assertEqual(set(wrapper.HELPERS),{'apply_whatsapp_order_address_branch_repair.py','install_whatsapp_inbox.py'})
        self.assertEqual(wrapper.BASE_BLOB,repair.BASE_BLOB)

    def test_preflight_has_no_publication_or_worker_actions(self):
        out=self.f.apply(True)
        self.assertEqual(out['status'],'WHATSAPP_ORDER_ADDRESS_BRANCH_PREFLIGHT_READY')
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in self.f.calls))

    def test_workflow_publication_and_repeat_preserve_configuration(self):
        out=self.f.apply()
        self.assertEqual(out['status'],'WHATSAPP_ORDER_ADDRESS_BRANCH_REPAIR_READY')
        self.f.unchanged_config(self)
        for path,expected in self.f.targets.items():self.assertEqual((self.f.root/path).read_bytes(),expected)
        repeat=self.f.apply();self.assertEqual(repeat['status'],'WHATSAPP_ORDER_ADDRESS_BRANCH_ALREADY_CURRENT')
        self.assertIsNone(repeat['backup'])
        self.assertFalse(any(word in args for args in self.f.calls for word in
            ('artisan','migrate','pull','reset','checkout','whatsapp:process-orders','whatsapp:consume-inbox')))

    def test_workflow_exposure_after_failed_publication_requires_review(self):
        self.f.fail_target=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)

    def test_configuration_target_scope_is_rejected(self):
        self.f.pins['config/whatsapp_cart.php']=('a'*40,'b'*40)
        with self.assertRaises(repair.SafeError):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)

    def test_only_exact_pinned_helpers_are_staged(self):
        sources={name:(ROOT/'deployment'/name).read_bytes() for name in wrapper.HELPERS}
        def runner(args):
            name=args[-1].split(':')[-1].split('/')[-1]
            if 'ls-tree' in args:return 0,b'100644 blob '+wrapper.HELPERS[name].encode()+b'\tdeployment/'+name.encode()+b'\0'
            if 'show' in args:return 0,sources[name]
            self.fail('Unexpected staging command')
        with tempfile.TemporaryDirectory() as folder:
            wrapper.stage_helpers(pathlib.Path(folder),'a'*40,os.geteuid(),os.getegid(),runner)
            self.assertEqual(set(path.name for path in pathlib.Path(folder).iterdir()),set(wrapper.HELPERS))
        sources['apply_whatsapp_order_address_branch_repair.py']+=b'# changed\n'
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(wrapper.SafeError):wrapper.stage_helpers(pathlib.Path(folder),'a'*40,os.geteuid(),os.getegid(),runner)
            self.assertFalse(list(pathlib.Path(folder).iterdir()))

    def workers(self):
        workers=fixtures.Workers();original=workers.child
        def child(*args,**kwargs):
            return json.loads(json.dumps(original(*args,**kwargs)).replace('FRAGMENTED_ADDRESS','ADDRESS_BRANCH'))
        return workers,child

    def test_prior_timer_states_restore_without_processing_hook(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,child=self.workers()
            out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,child,workers.profile,workers.record)
            self.assertEqual(out['status'],'WHATSAPP_ORDER_ADDRESS_BRANCH_REPAIR_READY')
            self.assertEqual(workers.states[wrapper.UNITS[2]],'active')
            self.assertEqual(workers.states[wrapper.UNITS[0]],'inactive')
            self.assertEqual(workers.phases,['RECORDED','TIMERS_PAUSED','QUIESCENT','PUBLICATION_STARTED','PUBLISHED','RESTORED'])
            self.assertEqual([args[-1] for args in workers.calls if args[1]=='start'],[wrapper.UNITS[2]])

    def test_already_current_preserves_workers_and_unsafe_restart_fails_closed(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,original=self.workers()
            def current(*args,**kwargs):
                value=original(*args,**kwargs);value['result']['changed']=False;return value
            out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,current,workers.profile,workers.record)
            self.assertEqual(out['status'],'WHATSAPP_ORDER_ADDRESS_BRANCH_ALREADY_CURRENT');self.assertEqual(workers.calls,[])
            workers,original=self.workers()
            def unsafe(*args,**kwargs):
                value=original(*args,**kwargs)
                if args[5] is False:workers.state_safe=False
                return value
            with self.assertRaises(wrapper.SafeError):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,unsafe,workers.profile,workers.record)
            self.assertTrue(all(state=='inactive' for state in workers.states.values()))


if __name__=='__main__':unittest.main()
