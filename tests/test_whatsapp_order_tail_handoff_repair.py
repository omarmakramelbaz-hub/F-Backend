#!/usr/bin/env python3
"""Pinned tail handoff-only publication; temporary files and mocked workers only."""
import importlib.util
import json
import os
import pathlib
import stat
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


repair=load('tail_handoff_repair_tested',ROOT/'deployment/apply_whatsapp_order_tail_handoff_repair.py')
baseline=load('tail_handoff_deployed_baseline',ROOT/'deployment/apply_whatsapp_order_media_handoff_repair.py')
fixtures=load('handoff_known_fixtures',ROOT/'tests/test_whatsapp_order_fragmented_address_repair_install.py')
wrapper=types.ModuleType('tail_handoff_root_tested')
wrapper.__file__=str(ROOT/'deployment/apply_whatsapp_order_tail_handoff_repair_root.sh')
body=pathlib.Path(wrapper.__file__).read_text().split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0]
exec(compile(body,wrapper.__file__,'exec'),wrapper.__dict__)


class TailHandoffInstallTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.patch=mock.patch.object(fixtures,'repair',repair);self.patch.start()
        self.f=fixtures.Fixture(self.temp.name)
        self.target='app/Services/Dashboard/WhatsAppOrderFollowupService.php'

    def tearDown(self):
        self.patch.stop();self.temp.cleanup()

    def test_exact_one_source_and_current_immutable_dependencies(self):
        self.assertEqual(list(repair.SOURCE_PINS),[self.target])
        self.assertEqual(wrapper.SOURCE_ROSTER,(self.target,))
        self.assertEqual(repair.SOURCE_PINS[self.target][0],'f8cbd5f86aeedf34d5281aec3d960ff31dc8b6eb')
        self.assertEqual(repair.SOURCE_PINS[self.target][1],repair.blob((ROOT/self.target).read_bytes()))
        expected=dict(baseline.PROTECTED_PINS)
        self.assertEqual(repair.PROTECTED_PINS,expected)
        self.assertNotIn(self.target,repair.PROTECTED_PINS)
        self.assertEqual(len(repair.PROTECTED_PINS),17)
        self.assertEqual(repair.BASE_BLOB,baseline.BASE_BLOB)
        self.assertIn("$roster=['"+self.target+"'];",repair.READINESS_PHP)
        self.assertIn('array_keys($pins)!==$roster',repair.READINESS_PHP)
        for call in ('->request(','->send(','->followupVariant(','->extract(','->process(','->projectPending('):
            self.assertNotIn(call,repair.READINESS_PHP)
        for name in ('request_hash','payload_hash','inbound_message_id','send_started_at','created_at','updated_at'):
            self.assertIn("'"+name+"'",repair.READINESS_PHP)
        self.assertEqual(wrapper.PUBLISHER_BLOB,repair.blob((ROOT/'deployment/apply_whatsapp_order_tail_handoff_repair.py').read_bytes()))
        self.assertEqual(set(wrapper.HELPERS),{'apply_whatsapp_order_tail_handoff_repair.py','install_whatsapp_inbox.py'})

    def test_preflight_changes_no_source_or_worker(self):
        out=self.f.apply(True)
        self.assertEqual(out['status'],'WHATSAPP_ORDER_TAIL_HANDOFF_PREFLIGHT_READY')
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in self.f.calls))

    def test_publication_and_repeat_preserve_configuration_and_receipt_scope(self):
        out=self.f.apply();self.assertEqual(out['status'],'WHATSAPP_ORDER_TAIL_HANDOFF_REPAIR_READY')
        self.assertEqual((self.f.root/self.target).read_bytes(),self.f.targets[self.target])
        receipt=json.loads((pathlib.Path(out['backup'])/'receipt.json').read_text())
        self.assertEqual(receipt['targets'],{self.target:self.f.pins[self.target][1]})
        before=repair.metadata(self.f.root/self.target)
        repeat=self.f.apply();self.assertEqual(repeat['status'],'WHATSAPP_ORDER_TAIL_HANDOFF_ALREADY_CURRENT')
        self.assertIsNone(repeat['backup']);self.assertEqual(repair.metadata(self.f.root/self.target),before)
        self.f.unchanged_config(self)
        self.assertFalse(any(word in args for args in self.f.calls for word in
            ('artisan','migrate','pull','reset','checkout','whatsapp:process-orders','whatsapp:consume-inbox')))

    def test_exposed_service_rollback_restores_exact_inode_but_requires_review(self):
        before=repair.base.snapshot(self.f.root/self.target,self.f.uid,self.f.root)
        self.f.fail_target=True
        with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.assertEqual(repair.base.snapshot(self.f.root/self.target,self.f.uid,self.f.root),before)
        self.f.originals(self);self.f.unchanged_config(self)

    def test_competing_service_edit_is_preserved(self):
        self.f.fail_target=True;recover=repair.base.recover_publication
        competing=b'<?php /* competing deployment */\n'
        def restore(publication,*args,**kwargs):
            self.f.write(self.target,competing)
            return recover(publication,*args,**kwargs)
        with mock.patch.object(repair.base,'recover_publication',restore):
            with self.assertRaisesRegex(repair.SafeError,'ROLLBACK_REVIEW_REQUIRED'):self.f.apply()
        self.assertEqual((self.f.root/self.target).read_bytes(),competing);self.f.unchanged_config(self)

    def test_unknown_live_service_is_rejected_before_commands(self):
        self.f.write(self.target,b'<?php /* foreign */\n')
        with self.assertRaisesRegex(repair.SafeError,'LIVE_SOURCE_NEEDS_REVIEW'):self.f.apply()
        self.assertEqual(self.f.calls,[]);self.f.unchanged_config(self)

    def test_extra_target_is_rejected_before_any_command(self):
        self.f.pins['app/Services/Dashboard/WhatsAppReplyService.php']=('a'*40,'b'*40)
        with self.assertRaisesRegex(repair.SafeError,'SCOPE_INVALID'):self.f.apply()
        self.assertEqual(self.f.calls,[]);self.f.originals(self);self.f.unchanged_config(self)

    def test_protected_dependency_change_rejects_before_source_or_workers(self):
        self.f.write('app/Support/Immutable.php',b'<?php /* concurrent dependency */\n')
        with self.assertRaisesRegex(repair.SafeError,'PROTECTED_SOURCE_MISMATCH'):self.f.apply()
        self.assertEqual(self.f.calls,[]);self.f.originals(self);self.f.unchanged_config(self)

    def test_configuration_race_during_candidate_lint_preserves_foreign_configuration(self):
        foreign=b'<?php return ["concurrent"=>true];\n'
        def hook(args):
            if args[:2]==[repair.PHP,'-l']:self.f.write('config/other.php',foreign)
        self.f.hook=hook
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_CHANGED'):self.f.apply()
        self.f.originals(self)
        self.assertEqual((self.f.root/'config/other.php').read_bytes(),foreign)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in self.f.calls))

    def test_lint_failure_does_not_touch_source_configuration_or_workers(self):
        self.f.fail_lint=True
        with self.assertRaisesRegex(repair.SafeError,'CANDIDATE_LINT_FAILED'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in self.f.calls))

    def test_source_hardlink_publication_retains_historical_alias(self):
        live=self.f.root/self.target;alias=self.f.root.parent/'historical-service-copy'
        os.link(live,alias);before=repair.metadata(alias);old=alias.read_bytes()
        self.f.apply()
        self.assertEqual(alias.read_bytes(),old)
        self.assertEqual(repair.metadata(alias)[:7],before[:7])
        self.assertNotEqual(live.stat().st_ino,alias.stat().st_ino)
        self.assertEqual(stat.S_IMODE(live.stat().st_mode),before[2])
        self.f.unchanged_config(self)

    def test_existing_protected_hardlink_is_read_only_and_preserved_exactly(self):
        protected=self.f.root/'app/Support/Immutable.php';alias=self.f.root.parent/'historical-inbox-copy'
        os.link(protected,alias);before=repair.metadata(protected);content=protected.read_bytes()
        self.f.apply()
        self.assertEqual(repair.metadata(protected),before)
        self.assertEqual(repair.metadata(alias),before)
        self.assertEqual(protected.read_bytes(),content)
        self.f.unchanged_config(self)

    def test_multilink_private_configuration_blocks_publication(self):
        os.link(self.f.root/'.env',self.f.root.parent/'env-alias')
        with self.assertRaisesRegex(repair.SafeError,'CONFIGURATION_LINK_UNSAFE'):self.f.apply()
        self.f.originals(self);self.assertEqual(self.f.calls,[])

    def test_live_source_symlink_is_rejected_without_following_or_operating_workers(self):
        path=self.f.root/self.target;path.unlink();path.symlink_to(self.f.root/'config/other.php')
        config_before=(self.f.root/'config/other.php').read_bytes()
        with self.assertRaises(repair.SafeError):self.f.apply()
        self.assertTrue(path.is_symlink())
        self.assertEqual((self.f.root/'config/other.php').read_bytes(),config_before)
        self.assertEqual(self.f.calls,[]);self.f.unchanged_config(self)

    def test_release_source_bytes_and_tree_mode_are_bound_before_publication(self):
        original=self.f.targets[self.target]
        self.f.targets[self.target]=original+b'foreign bytes'
        with self.assertRaisesRegex(repair.SafeError,'RELEASE_BLOB_MISMATCH'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
        self.f.targets[self.target]=original;self.f.tree_mode=b'100755';self.f.calls.clear()
        with self.assertRaisesRegex(repair.SafeError,'RELEASE_SOURCE_MISMATCH'):self.f.apply()
        self.f.originals(self);self.f.unchanged_config(self)
        self.assertFalse(any(args[0]=='/usr/bin/systemctl' for args in self.f.calls))

    def test_uncached_configuration_remains_absent_after_publish_and_repeat(self):
        with tempfile.TemporaryDirectory() as folder:
            fixture=fixtures.Fixture(folder,cached=False)
            self.assertEqual(fixture.apply()['status'],'WHATSAPP_ORDER_TAIL_HANDOFF_REPAIR_READY')
            self.assertFalse((fixture.root/'bootstrap/cache/config.php').exists())
            self.assertEqual(fixture.apply()['status'],'WHATSAPP_ORDER_TAIL_HANDOFF_ALREADY_CURRENT')
            fixture.unchanged_config(self)

    def test_restart_guard_rejects_old_source_after_publication(self):
        pre=self.f.apply(True)
        with mock.patch.object(repair,'SOURCE_PINS',self.f.pins),mock.patch.object(repair,'PROTECTED_PINS',self.f.protected):
            args=(self.f.root,self.f.uid,pre['configuration_fingerprint'])
            self.assertEqual(repair.verify_state(*args,pre['sources'],pre['protected_fingerprint'])['sources'],pre['sources'])
            self.f.apply();target={path:pin[1] for path,pin in self.f.pins.items()}
            self.assertEqual(repair.verify_state(*args,target,pre['protected_fingerprint'])['sources'],target)
            with self.assertRaisesRegex(repair.SafeError,'STATE_SOURCE_CHANGED'):
                repair.verify_state(*args,pre['sources'],pre['protected_fingerprint'])

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
        sources['apply_whatsapp_order_tail_handoff_repair.py']+=b'# changed\n'
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(wrapper.SafeError):wrapper.stage_helpers(pathlib.Path(folder),'a'*40,os.geteuid(),os.getegid(),runner)
            self.assertFalse(list(pathlib.Path(folder).iterdir()))

    def workers(self):
        workers=fixtures.Workers();original=workers.child
        def child(*args,**kwargs):
            return json.loads(json.dumps(original(*args,**kwargs)).replace('FRAGMENTED_ADDRESS','TAIL_HANDOFF'))
        return workers,child

    def test_original_timer_states_restore_without_processing_hook(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,child=self.workers()
            out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,child,workers.profile,workers.record)
            self.assertEqual(out['status'],'WHATSAPP_ORDER_TAIL_HANDOFF_REPAIR_READY')
            self.assertEqual(workers.states[wrapper.UNITS[2]],'active');self.assertEqual(workers.states[wrapper.UNITS[0]],'inactive')
            self.assertEqual([args[-1] for args in workers.calls if args[1]=='start'],[wrapper.UNITS[2]])

    def test_same_count_foreign_roster_cannot_pause_workers(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,original=self.workers()
            def foreign(*args,**kwargs):
                value=original(*args,**kwargs);sources=value['result']['sources']
                sources['app/Services/Dashboard/WhatsAppReplyService.php']=sources.pop(self.target)
                return value
            with self.assertRaisesRegex(wrapper.SafeError,'PREFLIGHT_OUTPUT_INVALID'):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,foreign,workers.profile,workers.record)
            self.assertEqual(workers.calls,[])

    def test_already_current_no_worker_action_and_unsafe_restart_fails_closed(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,original=self.workers()
            def current(*args,**kwargs):
                value=original(*args,**kwargs);value['result']['changed']=False;return value
            self.assertEqual(wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,current,workers.profile,workers.record)['status'],
                'WHATSAPP_ORDER_TAIL_HANDOFF_ALREADY_CURRENT')
            self.assertEqual(workers.calls,[])
            workers,original=self.workers()
            def unsafe(*args,**kwargs):
                value=original(*args,**kwargs)
                if args[5] is False:workers.state_safe=False
                return value
            with self.assertRaises(wrapper.SafeError):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,unsafe,workers.profile,workers.record)
            self.assertTrue(all(state=='inactive' for state in workers.states.values()))

    def test_manual_service_invocation_is_not_restarted_or_stopped(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,child=self.workers()
            workers.states[wrapper.UNITS[2]]='inactive';workers.states[wrapper.UNITS[3]]='active'
            with self.assertRaisesRegex(wrapper.SafeError,'MANUAL_WORKER_NEEDS_REVIEW'):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,child,workers.profile,workers.record)
            self.assertFalse(any(args[1] in ('stop','start') for args in workers.calls))

    def test_foreign_unit_after_publication_stays_stopped(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,original=self.workers()
            def foreign_unit(*args,**kwargs):
                value=original(*args,**kwargs)
                if args[5] is False:workers.changed=True
                return value
            with self.assertRaisesRegex(wrapper.SafeError,'RECOVERY_NEEDS_REVIEW'):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,foreign_unit,workers.profile,workers.record)
            self.assertTrue(all(state=='inactive' for state in workers.states.values()))
            self.assertFalse(any(args[1]=='start' for args in workers.calls))

    def test_failed_original_timer_restore_recovers_capture_without_processing_hook(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            workers,child=self.workers();workers.fail_order_start=True
            with self.assertRaisesRegex(wrapper.SafeError,'ORDERS_PAUSED_CAPTURE_ACTIVE'):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],workers.runner,child,workers.profile,workers.record)
            self.assertEqual(workers.states[wrapper.UNITS[0]],'active')
            self.assertEqual(workers.states[wrapper.UNITS[2]],'inactive')
            self.assertFalse(any(args[1]=='start' and args[-1].endswith('.service') for args in workers.calls))


if __name__=='__main__':unittest.main()
