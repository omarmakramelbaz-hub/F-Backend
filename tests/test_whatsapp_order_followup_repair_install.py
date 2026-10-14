#!/usr/bin/env python3
"""Four followup sources with a dependency-first publication; reuse known fixtures without real servers or workers."""
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


repair=load('followup_repair_tested',ROOT/'deployment/apply_whatsapp_order_followup_repair.py')
fixtures=load('followup_known_fixtures',ROOT/'tests/test_whatsapp_order_fragmented_address_repair_install.py')
baseline=fixtures.repair
wrapper=types.ModuleType('followup_root_tested')
wrapper.__file__=str(ROOT/'deployment/apply_whatsapp_order_followup_repair_root.sh')
body=pathlib.Path(wrapper.__file__).read_text().split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0]
exec(compile(body,wrapper.__file__,'exec'),wrapper.__dict__)


class FollowupInstallTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.patch=mock.patch.object(fixtures,'repair',repair);self.patch.start()
        self.f=fixtures.Fixture(self.temp.name)
        self.service='app/Services/Dashboard/WhatsAppOrderFollowupService.php'
        (self.f.root/self.service).unlink()
        self.f.old[self.service]=None
        self.f.pins[self.service]=(None,self.f.pins[self.service][1])

    def tearDown(self):
        self.patch.stop();self.temp.cleanup()

    def test_exact_source_pins_protected_roster_and_read_only_bootstrap(self):
        prefix='app/Services/Dashboard/'
        names=['WhatsAppOrderAiProvider','WhatsAppReplyService','WhatsAppOrderFollowupService','WhatsAppOrderWorkflow']
        targets=[prefix+name+'.php' for name in names]
        self.assertEqual(list(repair.SOURCE_PINS),targets)
        self.assertEqual(tuple(targets),wrapper.SOURCE_ROSTER)
        self.assertEqual([value[0] for value in repair.SOURCE_PINS.values()],
            ['cbaaa8ed7014da5bad05d429151afa5ff377ceab','1ef23df825287f8d3dedafcdb5d70ffeac7f1f29',
             None,'cf83ab9152167a613f7fc5667e30d2a59bb61c9a'])
        for target,(_,pin) in repair.SOURCE_PINS.items():
            self.assertEqual(pin,repair.blob((ROOT/target).read_bytes()))
            self.assertNotIn(target,repair.PROTECTED_PINS)
        expected=dict(baseline.PROTECTED_PINS)
        expected.pop(prefix+'WhatsAppOrderWorkflow.php')
        expected.pop(prefix+'WhatsAppReplyService.php')
        expected['app/Services/Dashboard/TakeawayAccess.php']='6b27b7ac2faff6fdaf952494f90f2d8378a12484'
        self.assertEqual(repair.PROTECTED_PINS,expected)
        self.assertEqual(repair.BASE_BLOB,baseline.BASE_BLOB)
        self.assertIn('array_keys($pins)!==$roster',repair.READINESS_PHP)
        self.assertIn('if($expected===null)continue;',repair.READINESS_PHP)
        for name in ('request_hash','payload_hash','inbound_message_id','reason','send_started_at','claimed_at','created_at','updated_at'):
            self.assertIn("'"+name+"'",repair.READINESS_PHP)
        for call in ('->request(','->send(','->followupVariant(','->extract(','->process(','->projectPending('):
            self.assertNotIn(call,repair.READINESS_PHP)
        self.assertEqual(wrapper.PUBLISHER_BLOB,repair.blob((ROOT/'deployment/apply_whatsapp_order_followup_repair.py').read_bytes()))
        self.assertEqual(set(wrapper.HELPERS),{'apply_whatsapp_order_followup_repair.py','install_whatsapp_inbox.py'})
        self.assertEqual(wrapper.BASE_BLOB,repair.BASE_BLOB)

    def test_preflight_has_no_publication_or_worker_actions(self):
        out=self.f.apply(True)
        self.assertEqual(out['status'],'WHATSAPP_ORDER_FOLLOWUP_PREFLIGHT_READY')
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in self.f.calls))

    def test_new_service_publication_and_repeat_preserve_configuration(self):
        out=self.f.apply()
        self.assertEqual(out['status'],'WHATSAPP_ORDER_FOLLOWUP_REPAIR_READY')
        self.f.unchanged_config(self)
        for path,expected in self.f.targets.items():self.assertEqual((self.f.root/path).read_bytes(),expected)
        repeat=self.f.apply();self.assertEqual(repeat['status'],'WHATSAPP_ORDER_FOLLOWUP_ALREADY_CURRENT')
        self.assertIsNone(repeat['backup'])
        self.assertFalse(any(word in args for args in self.f.calls for word in
            ('artisan','migrate','pull','reset','checkout','whatsapp:process-orders','whatsapp:consume-inbox')))

    def test_workflow_exposure_after_failed_publication_requires_review(self):
        self.f.fail_target=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)

    def test_dependencies_publish_before_workflow_and_rollback_in_reverse(self):
        publications=[];restorations=[]
        replace=repair.base.replace_file;recover=repair.base.recover_publication
        def publish(path,*args,**kwargs):
            publications.append(kwargs['relative']);return replace(path,*args,**kwargs)
        def restore(publication,*args,**kwargs):
            restorations.append(str(publication.path.relative_to(self.f.root)))
            return recover(publication,*args,**kwargs)
        self.f.fail_target=True
        with mock.patch.object(repair.base,'replace_file',publish),mock.patch.object(repair.base,'recover_publication',restore):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.assertEqual(publications,list(repair.SOURCE_PINS))
        self.assertEqual(restorations,list(reversed(repair.SOURCE_PINS)))
        self.f.originals(self);self.f.unchanged_config(self)

    def test_new_service_exposure_before_workflow_still_requires_review(self):
        replace=repair.base.replace_file
        def publish(path,*args,**kwargs):
            if kwargs['relative'].endswith('WhatsAppOrderWorkflow.php'):
                raise repair.SafeError('INJECTED_PUBLICATION_FAILURE')
            return replace(path,*args,**kwargs)
        with mock.patch.object(repair.base,'replace_file',publish):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)

    def test_competing_new_service_edit_is_preserved_and_orders_require_review(self):
        self.f.fail_target=True;recover=repair.base.recover_publication
        competing=b'<?php /* competing reviewed deployment */\n'
        def restore(publication,*args,**kwargs):
            if str(publication.path.relative_to(self.f.root))==self.service:
                self.f.write(self.service,competing)
            return recover(publication,*args,**kwargs)
        with mock.patch.object(repair.base,'recover_publication',restore):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.assertEqual((self.f.root/self.service).read_bytes(),competing)
        self.f.unchanged_config(self)

    def test_failed_workflow_restore_keeps_new_dependency_available(self):
        self.f.fail_target=True;recover=repair.base.recover_publication;restored=[]
        def restore(publication,*args,**kwargs):
            relative=str(publication.path.relative_to(self.f.root));restored.append(relative)
            if relative.endswith('WhatsAppOrderWorkflow.php'):return False
            return recover(publication,*args,**kwargs)
        with mock.patch.object(repair.base,'recover_publication',restore):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.assertEqual(restored,[list(repair.SOURCE_PINS)[-1]])
        for relative,content in self.f.targets.items():self.assertEqual((self.f.root/relative).read_bytes(),content)
        self.f.unchanged_config(self)

    def test_foreign_preexisting_followup_service_is_rejected_before_commands(self):
        self.f.write(self.service,b'<?php /* foreign */\n')
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[]);self.f.unchanged_config(self)

    def test_restart_guard_binds_absent_old_and_complete_new_roster(self):
        pre=self.f.apply(True)
        with mock.patch.object(repair,'SOURCE_PINS',self.f.pins),mock.patch.object(repair,'PROTECTED_PINS',self.f.protected):
            args=(self.f.root,self.f.uid,pre['configuration_fingerprint'])
            old=repair.verify_state(*args,pre['sources'],pre['protected_fingerprint'])
            self.assertIsNone(old['sources'][self.service])
            self.f.apply()
            target={path:pin[1] for path,pin in self.f.pins.items()}
            new=repair.verify_state(*args,target,pre['protected_fingerprint'])
            self.assertEqual(new['sources'],target)
            with self.assertRaisesRegex(repair.SafeError,'STATE_SOURCE_CHANGED'):
                repair.verify_state(*args,pre['sources'],pre['protected_fingerprint'])

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
        sources['apply_whatsapp_order_followup_repair.py']+=b'# changed\n'
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(wrapper.SafeError):wrapper.stage_helpers(pathlib.Path(folder),'a'*40,os.geteuid(),os.getegid(),runner)
            self.assertFalse(list(pathlib.Path(folder).iterdir()))

    def workers(self):
        workers=fixtures.Workers();original=workers.child
        def child(*args,**kwargs):
            return json.loads(json.dumps(original(*args,**kwargs)).replace('FRAGMENTED_ADDRESS','FOLLOWUP'))
        return workers,child

    def test_prior_timer_states_restore_without_processing_hook(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,child=self.workers()
            out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,child,workers.profile,workers.record)
            self.assertEqual(out['status'],'WHATSAPP_ORDER_FOLLOWUP_REPAIR_READY')
            self.assertEqual(workers.states[wrapper.UNITS[2]],'active')
            self.assertEqual(workers.states[wrapper.UNITS[0]],'inactive')
            self.assertEqual(workers.phases,['RECORDED','TIMERS_PAUSED','QUIESCENT','PUBLICATION_STARTED','PUBLISHED','RESTORED'])
            self.assertEqual([args[-1] for args in workers.calls if args[1]=='start'],[wrapper.UNITS[2]])

    def test_same_size_foreign_source_roster_cannot_pause_timers(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,original=self.workers()
            def foreign(*args,**kwargs):
                value=original(*args,**kwargs)
                sources=value['result']['sources'];sources['config/whatsapp_orders.php']=sources.pop(self.service)
                return value
            with self.assertRaisesRegex(wrapper.SafeError,'PREFLIGHT_OUTPUT_INVALID'):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,foreign,workers.profile,workers.record)
            self.assertEqual(workers.calls,[])

    def test_already_current_preserves_workers_and_unsafe_restart_fails_closed(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,original=self.workers()
            def current(*args,**kwargs):
                value=original(*args,**kwargs);value['result']['changed']=False;return value
            out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,current,workers.profile,workers.record)
            self.assertEqual(out['status'],'WHATSAPP_ORDER_FOLLOWUP_ALREADY_CURRENT');self.assertEqual(workers.calls,[])
            workers,original=self.workers()
            def unsafe(*args,**kwargs):
                value=original(*args,**kwargs)
                if args[5] is False:workers.state_safe=False
                return value
            with self.assertRaises(wrapper.SafeError):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,unsafe,workers.profile,workers.record)
            self.assertTrue(all(state=='inactive' for state in workers.states.values()))


if __name__=='__main__':unittest.main()
