#!/usr/bin/env python3
"""Reuse the proven publication fixture; no network or real services are invoked."""
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import time
import types
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


repair = load('fulfillment_repair_tested', ROOT/'deployment/apply_whatsapp_order_fulfillment_repair.py')
fixtures = load('fulfillment_existing_fixtures', ROOT/'tests/test_whatsapp_order_fragmented_address_repair_install.py')
baseline_repair = fixtures.repair
wrapper = types.ModuleType('fulfillment_root_tested')
wrapper.__file__ = str(ROOT/'deployment/apply_whatsapp_order_fulfillment_repair_root.sh')
wrapper_text = pathlib.Path(wrapper.__file__).read_text()
exec(compile(wrapper_text.split("<<'PY'\n",1)[1].rsplit('\nPY\n',1)[0], wrapper.__file__, 'exec'), wrapper.__dict__)


class FulfillmentPublicationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.patch = mock.patch.object(fixtures, 'repair', repair)
        self.patch.start()
        self.f = fixtures.Fixture(self.temp.name)

    def tearDown(self):
        self.patch.stop()
        self.temp.cleanup()

    def test_one_provider_target_and_immutable_dependencies(self):
        self.assertEqual(list(repair.SOURCE_PINS), ['app/Services/Dashboard/WhatsAppOrderAiProvider.php'])
        old, target = next(iter(repair.SOURCE_PINS.values()))
        self.assertEqual(old, '1ac4ebbaeaef12a5da7d83f1c5a6daa024441db0')
        self.assertEqual(repair.BASE_BLOB, fixtures.wrapper.BASE_BLOB)
        self.assertEqual(repair.PROTECTED_PINS, baseline_repair.PROTECTED_PINS)
        self.assertEqual(wrapper.PUBLISHER_BLOB, repair.blob((ROOT/'deployment/apply_whatsapp_order_fulfillment_repair.py').read_bytes()))
        self.assertEqual(set(wrapper.HELPERS), {'apply_whatsapp_order_fulfillment_repair.py','install_whatsapp_inbox.py'})
        self.assertEqual(wrapper.BASE_BLOB, repair.BASE_BLOB)
        self.assertEqual(target,repair.blob((ROOT/'app/Services/Dashboard/WhatsAppOrderAiProvider.php').read_bytes()))

    def test_preflight_preserves_sources_config_and_workers(self):
        result = self.f.apply(True)
        self.assertEqual(result['status'], 'WHATSAPP_ORDER_FULFILLMENT_PREFLIGHT_READY')
        self.f.originals(self)
        self.f.unchanged_config(self)
        self.assertFalse(any(args[0] == '/usr/bin/systemctl' for args in self.f.calls))

    def test_publication_and_repeat_preserve_configuration(self):
        result = self.f.apply()
        self.assertEqual(result['status'], 'WHATSAPP_ORDER_FULFILLMENT_REPAIR_READY')
        self.f.unchanged_config(self)
        for path, expected in self.f.targets.items():
            self.assertEqual((self.f.root/path).read_bytes(), expected)
        again = self.f.apply()
        self.assertEqual(again['status'], 'WHATSAPP_ORDER_FULFILLMENT_ALREADY_CURRENT')
        self.assertIsNone(again['backup'])
        self.assertFalse(any(word in args for args in self.f.calls for word in
                             ('artisan','migrate','pull','reset','checkout','whatsapp:process-orders','whatsapp:consume-inbox')))

    def test_failed_readiness_restores_source_without_config_change(self):
        self.f.fail_target = True
        with self.assertRaises(repair.SafeError):
            self.f.apply()
        self.f.originals(self)
        self.f.unchanged_config(self)

    def test_extra_publication_target_is_rejected(self):
        self.f.pins['config/whatsapp_orders.php'] = ('a'*40,'b'*40)
        with self.assertRaises(repair.SafeError):
            self.f.apply()
        self.f.originals(self)
        self.f.unchanged_config(self)

    def test_wrapper_stages_only_pinned_helpers_and_rejects_changed_bytes(self):
        contents = {name:(ROOT/'deployment'/name).read_bytes() for name in wrapper.HELPERS}
        calls = []
        def runner(args):
            calls.append(args)
            name = args[-1].split(':')[-1].split('/')[-1]
            if 'ls-tree' in args:
                relative = 'deployment/'+name
                return 0, b'100644 blob '+wrapper.HELPERS[name].encode()+b'\t'+relative.encode()+b'\0'
            if 'show' in args:
                return 0, contents[name]
            self.fail('Unexpected staging command')
        with tempfile.TemporaryDirectory() as folder:
            target = pathlib.Path(folder)
            wrapper.stage_helpers(target,'a'*40,os.geteuid(),os.getegid(),runner)
            self.assertEqual(set(p.name for p in target.iterdir()),set(wrapper.HELPERS))
        contents['apply_whatsapp_order_fulfillment_repair.py'] += b'# tampered\n'
        with tempfile.TemporaryDirectory() as folder:
            with self.assertRaises(wrapper.SafeError):
                wrapper.stage_helpers(pathlib.Path(folder),'a'*40,os.geteuid(),os.getegid(),runner)
            self.assertFalse(list(pathlib.Path(folder).iterdir()))
        self.assertTrue(all('git' in call for call in calls))

    def test_refresh_triplet_is_optional_and_strict(self):
        self.assertIsNone(wrapper.refresh_arguments(None,None,None))
        self.assertEqual(wrapper.refresh_arguments('1','2','3'),(1,2,3))
        for values in ((None,'2','3'),('0','2','3'),('1','-2','3'),('1','2','3;secret'),('1','2','9'*30)):
            with self.subTest(values=values),self.assertRaises(wrapper.SafeError):
                wrapper.refresh_arguments(*values)

    @staticmethod
    def report(status='CACHED_DELIVERY_REFRESHED'):
        value={'diagnostic':'WHATSAPP_ORDER_FULFILLMENT_RECLASSIFY','status':status,
            'writes':2 if status=='CACHED_DELIVERY_REFRESHED' else 0,
            'database_rows_updated':2 if status=='CACHED_DELIVERY_REFRESHED' else 0,'ai_calls':0,'erp_writes':0}
        if status=='STOPPED':value['stage']='STATE'
        return value

    def workers(self,changed=True):
        w=fixtures.Workers();original=w.child
        def child(*args,**kwargs):
            value=json.loads(json.dumps(original(*args,**kwargs)).replace('FRAGMENTED_ADDRESS','FULFILLMENT'))
            if args[5] is True:value['result']['changed']=changed
            return value
        return w,child

    def test_refresh_runs_quiescent_before_restore_even_when_provider_current(self):
        for changed in (True,False):
            with self.subTest(changed=changed),mock.patch.object(fixtures,'wrapper',wrapper):
                w,child=self.workers(changed)
                def hook():
                    self.assertTrue(all(state=='inactive' for state in w.states.values()))
                    self.assertEqual(w.phases[-1],'CACHE_REFRESH_STARTED')
                    self.assertFalse(any(args[1]=='start' for args in w.calls))
                    return self.report()
                out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],w.runner,child,w.profile,w.record,post_publish=hook)
                self.assertEqual(out['cache_refresh']['status'],'CACHED_DELIVERY_REFRESHED')
                self.assertLess(w.phases.index('CACHE_REFRESH_FINISHED'),w.phases.index('RESTORED'))
                self.assertEqual(w.states[wrapper.UNITS[2]],'active')
                self.assertEqual(w.states[wrapper.UNITS[0]],'inactive')

    def test_safe_stopped_refresh_restores_but_unsafe_restart_guard_fails_closed(self):
        with mock.patch.object(fixtures,'wrapper',wrapper):
            w,child=self.workers()
            out=wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],w.runner,child,w.profile,w.record,
                post_publish=lambda:self.report('STOPPED'))
            self.assertEqual(out['cache_refresh']['status'],'STOPPED')
            self.assertEqual(w.states[wrapper.UNITS[2]],'active')
            w,child=self.workers()
            def unsafe():
                w.state_safe=False
                return self.report('STOPPED')
            with self.assertRaises(wrapper.SafeError):
                wrapper.orchestrate(pathlib.Path('/fixture'),'a'*40,1,1,[5,6],w.runner,child,w.profile,w.record,post_publish=unsafe)
            self.assertTrue(all(state=='inactive' for state in w.states.values()))

    def test_refresh_output_keeps_only_fixed_metadata(self):
        value=self.report();value['customer']='PRIVATE_SECRET';value['message']='PRIVATE_SECRET'
        self.assertNotIn('PRIVATE_SECRET',json.dumps(wrapper.sanitize_refresh(value)))
        for field in ('status','writes','ai_calls','erp_writes','database_rows_updated'):
            bad=dict(value);bad[field]='PRIVATE_SECRET'
            with self.subTest(field=field),self.assertRaises(wrapper.SafeError):wrapper.sanitize_refresh(bad)

    def test_optional_helper_is_staged_only_when_requested(self):
        contents={name:(ROOT/'deployment'/name).read_bytes() for name in wrapper.HELPERS}
        contents[wrapper.RECLASSIFY_HELPER]=(ROOT/'deployment'/wrapper.RECLASSIFY_HELPER).read_bytes()
        pins=dict(wrapper.HELPERS);pins[wrapper.RECLASSIFY_HELPER]=wrapper.RECLASSIFY_BLOB
        self.assertEqual(pins[wrapper.RECLASSIFY_HELPER],repair.blob(contents[wrapper.RECLASSIFY_HELPER]))
        def runner(args):
            name=args[-1].split(':')[-1].split('/')[-1]
            if 'ls-tree' in args:return 0,b'100644 blob '+pins[name].encode()+b'\tdeployment/'+name.encode()+b'\0'
            return 0,contents[name]
        with tempfile.TemporaryDirectory() as folder:
            wrapper.stage_helpers(pathlib.Path(folder),'a'*40,os.geteuid(),os.getegid(),runner,refresh=True)
            self.assertEqual(set(p.name for p in pathlib.Path(folder).iterdir()),set(pins))

    def test_verified_helper_transport_is_bounded_and_invalid_output_has_unknown_counts(self):
        with tempfile.TemporaryDirectory() as folder:
            path=pathlib.Path(folder)/wrapper.RECLASSIFY_HELPER
            source=(ROOT/'deployment'/wrapper.RECLASSIFY_HELPER).read_bytes();path.write_bytes(source);path.chmod(0o600)
            calls=[]
            def runner(args,timeout,input_bytes):
                calls.append((args,timeout,input_bytes))
                return 0,json.dumps(self.report()).encode()
            self.assertEqual(wrapper.call_reclassifier(path.parent,(1,2,3),os.geteuid(),runner)['writes'],2)
            self.assertEqual(calls[0][1],45);self.assertEqual(calls[0][2],source)
            self.assertIn(wrapper.PHP_STDIN,calls[0][0]);self.assertIn('--conversation=1',calls[0][0])
            self.assertNotIn('/dev/stdin',calls[0][0])
            out=wrapper.call_reclassifier(path.parent,(1,2,3),os.geteuid(),lambda *a,**k:(1,b'PRIVATE_SECRET'))
            self.assertIsNone(out['writes']);self.assertIsNone(out['database_rows_updated'])
            self.assertNotIn('PRIVATE_SECRET',json.dumps(out));self.assertEqual(out['status'],'STOPPED')
            self.assertEqual(wrapper.sanitize_refresh(out),out)

    def test_timeout_terminates_the_entire_process_group_before_return(self):
        code='import os,signal,subprocess,sys,time; print(os.getpgrp(),flush=True); subprocess.Popen([sys.executable,"-c","import time;time.sleep(30)"]); signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(30)'
        started=time.monotonic()
        groups=[];check=wrapper.group_running
        def observe(group):groups.append(group);return check(group)
        with mock.patch.object(wrapper,'group_running',side_effect=observe):
            status,output=wrapper.cache_command([sys.executable,'-c',code],timeout=0.1,grace=0.1)
        self.assertEqual(status,1);self.assertEqual(output,b'')
        self.assertTrue(groups);self.assertFalse(check(groups[-1]))
        self.assertLess(time.monotonic()-started,2)

    def test_inherited_stdin_php_argv_contract(self):
        php=os.environ.get('FULFILLMENT_TEST_PHP')
        if not php:self.skipTest('Local PHP fixture interpreter not configured')
        arguments=['--conversation=1','--draft=2','--ceiling=3','--refresh']
        code,output=wrapper.cache_command([php,'-n','-r',wrapper.PHP_STDIN,'--',*arguments],
            input_bytes=b'<?php echo json_encode(array_slice($argv,1));',timeout=2)
        self.assertEqual(code,0);self.assertEqual(json.loads(output),arguments)


if __name__ == '__main__':
    unittest.main()
