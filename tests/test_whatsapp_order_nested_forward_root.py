#!/usr/bin/env python3
"""Guard the nested forward coordinator's original-state restoration and failure paths."""
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
spec=importlib.util.spec_from_file_location('nested_forward_root_tested',ROOT/'deployment/resume_whatsapp_order_nested_forward_root.py')
m=importlib.util.module_from_spec(spec);sys.modules[spec.name]=m;spec.loader.exec_module(m)


class RootLifecycleTests(unittest.TestCase):
    def setUp(self):
        self.states={n:{'ActiveState':'inactive','MainPID':'0'}for n in m.UNITS};self.states[m.UNITS[0]]['ActiveState']='active'
        self.calls=[];self.journal=[];self.profile=('original',)
        self.prior={'configuration_fingerprint':'a'*64,'profiles':{n:list(self.profile)for n in m.UNITS},
            'before':{n:{'ActiveState':'inactive','MainPID':'0'}for n in m.UNITS}}
        self.prior['before'][m.UNITS[2]]['ActiveState']='active'
        self.quiet={'recovery_scope':'b'*64};self.original=(self.prior,self.quiet,b'journal',b'receipt',b'intent',('identities',))
        self.old_hash=m.blob(b'before');self.target_hash=m.blob(b'target')
        self.pub=types.SimpleNamespace(SOURCE_PINS={m.HELPER_PATH:(self.old_hash,self.target_hash)},base=types.SimpleNamespace(SafeError=m.SafeError),
                                       require_configuration=lambda *a:None)
        self.root=pathlib.Path('/home/fasakha/public_html');self.backup=self.root.parent/'whatsapp-release-backups/order-semantic-forward-fixture'
        self.profile_changed=False
        self.old=types.SimpleNamespace(SafeError=m.SafeError,unit_profile=lambda n: ('changed',)if self.profile_changed else self.profile,
            unit_state=lambda n,r:dict(self.states[n]),require_quiet=self.require_quiet,
            restore_timers=self.restore,fail_closed=self.fail_closed)
        snapshot=(types.SimpleNamespace(content=b'before'),('metadata',))
        self.guarded=('config',{m.HELPER_PATH:snapshot})
        self.published=('config',{m.HELPER_PATH:(types.SimpleNamespace(content=b'target'),('published-meta',))})
        self.pre={'status':'SEMANTIC_FORWARD_PREFLIGHT_READY','original_scope_valid':True,'transitions_verified':13,
            'status_7215_verified':True,'ai_calls':0,'orders_created':0}
        self.result={'status':'SEMANTIC_FORWARD_RECOVERY_READY','processed_total':14,'committed':True,
            'metrics':{'messages_hydrated':13,'status_records':1}}

    def runner(self,args):
        self.calls.append(args)
        if args[1]=='stop':
            for n in args[2:]:self.states[n]={'ActiveState':'inactive','MainPID':'0'}
        if args[1]=='start':self.states[args[2]]={'ActiveState':'active','MainPID':'0'}
        return 0,b''

    def require_quiet(self,runner):
        self.assertTrue(all(s['ActiveState']in('inactive','failed')for s in self.states.values()))

    def restore(self,before,profiles,runner):
        self.assertEqual(before,self.prior['before'])
        for n in m.UNITS:
            if n.endswith('.timer')and before[n]['ActiveState']=='active':runner(['/usr/bin/systemctl','start',n])

    def fail_closed(self,profiles,runner):
        runner(['/usr/bin/systemctl','stop',*m.UNITS])
        if self.profile_changed:raise ValueError('changed unit')
        runner(['/usr/bin/systemctl','start',m.UNITS[0]])

    def run_lifecycle(self,replies=None,journal=None,postcheck=None,capture=None,guards=None):
        replies=[dict(self.pre),dict(self.pre),dict(self.result)]if replies is None else replies
        with mock.patch.object(m,'validate_original',return_value=self.original),\
             mock.patch.object(m,'app_guard',side_effect=[self.guarded,self.guarded,self.published]if guards is None else guards),\
             mock.patch.object(m,'run_recovery',side_effect=replies),\
             mock.patch.object(m,'publish_pair')as publish,\
             mock.patch.object(m,'final_sources',side_effect=postcheck)as final:
            self.last_publish=publish
            result=m.orchestrate(self.pub,self.old,None,None,self.root,0,0,self.prior,self.quiet,self.original,
                {m.HELPER_PATH:(self.old_hash,self.target_hash)},{m.HELPER_PATH:b'target'},self.root/'candidate',self.backup,
                lambda fn:fn(),self.journal.append if journal is None else journal,self.runner,capture)
            return result,publish,final

    def assert_paused_with_capture(self):
        self.assertEqual(self.states[m.UNITS[2]]['ActiveState'],'inactive')
        self.assertEqual(self.states[m.UNITS[0]]['ActiveState'],'active')

    def test_success_restores_original_orders_timer_only(self):
        result,publish,final=self.run_lifecycle()
        self.assertEqual(result['processed_total'],14);publish.assert_called_once();final.assert_called_once()
        self.assertIn(['/usr/bin/systemctl','stop',*m.UNITS],self.calls)
        self.assertEqual([c for c in self.calls if c[1]=='start'],[['/usr/bin/systemctl','start',m.UNITS[2]]])
        self.assertEqual(self.states[m.UNITS[0]]['ActiveState'],'inactive')
        self.assertEqual(self.journal[-1]['phase'],'ORIGINAL_PIPELINES_RESTORED')

    def test_invalid_candidate_preflight_does_not_touch_workers(self):
        bad={**self.pre,'transitions_verified':12}
        with self.assertRaises(m.SafeError):self.run_lifecycle([bad])
        self.assertEqual(self.calls,[])

    def test_preflight_error_preserves_fixed_stage_without_mutation(self):
        with self.assertRaises(m.SafeError):self.run_lifecycle([m.SafeError('FORWARD_RECOVERY_TRANSITIONS_EVENT_5945')])
        self.assertEqual(self.calls,[])

    def test_quiescent_scope_failure_leaves_orders_paused_capture_active(self):
        with self.assertRaises(m.SafeError):self.run_lifecycle([self.pre,m.SafeError('FORWARD_RECOVERY_ORIGINAL_SCOPE')])
        self.assert_paused_with_capture();self.assertEqual(self.journal[-1]['phase'],'ORDERS_PAUSED_CAPTURE_ACTIVE')

    def test_recovery_failure_compensates_without_timer_resume(self):
        with self.assertRaises(m.SafeError):self.run_lifecycle([self.pre,self.pre,m.SafeError('FORWARD_RECOVERY_RECOVERY')])
        self.assert_paused_with_capture();self.assertNotIn(['/usr/bin/systemctl','start',m.UNITS[2]],self.calls)

    def test_wrong_success_envelope_does_not_resume_orders(self):
        with self.assertRaises(m.SafeError):self.run_lifecycle([self.pre,self.pre,{**self.result,'processed_total':13}])
        self.assert_paused_with_capture()

    def test_post_commit_source_change_keeps_orders_paused(self):
        with self.assertRaises(m.SafeError):self.run_lifecycle(postcheck=m.SafeError('FORWARD_POST_RECOVERY_SOURCE_CHANGED'))
        self.assertTrue(all(s['ActiveState']=='inactive'for s in self.states.values()))
        self.assertNotIn(['/usr/bin/systemctl','start',m.UNITS[2]],self.calls)

    def test_final_journal_failure_after_timer_start_repauses_orders(self):
        def journal(row):
            if row['phase']=='ORIGINAL_PIPELINES_RESTORED':raise OSError('journal unavailable')
            self.journal.append(row)
        with self.assertRaises(m.SafeError):self.run_lifecycle(journal=journal)
        self.assertIn(['/usr/bin/systemctl','start',m.UNITS[2]],self.calls);self.assert_paused_with_capture()

    def test_partial_timer_restore_failure_is_compensated(self):
        def restore(before,profiles,runner):runner(['/usr/bin/systemctl','start',m.UNITS[2]]);raise OSError('state unknown')
        self.old.restore_timers=restore
        with self.assertRaises(m.SafeError):self.run_lifecycle()
        self.assert_paused_with_capture()

    def test_changed_unit_profile_is_never_started(self):
        self.profile_changed=True
        with self.assertRaises(m.SafeError):self.run_lifecycle()
        self.assertEqual(self.calls,[])

    def test_validated_completed_receipt_can_restore_original_states(self):
        pre={**self.pre,'status':'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE'}
        final={**self.result,'status':'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE'}
        self.run_lifecycle([pre,pre,final])
        self.assertEqual(self.states[m.UNITS[2]]['ActiveState'],'active')

    def test_unreloaded_capture_file_never_authorizes_fallback_activation(self):
        def refresh():raise m.SafeError('CAPTURE_GUARD_DAEMON_RELOAD_UNVERIFIED')
        capture={'validate':lambda:self.profile,'refresh':refresh,'error_types':(m.SafeError,)}
        with self.assertRaisesRegex(m.SafeError,'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN'):
            self.run_lifecycle(capture=capture)
        self.assertEqual([c for c in self.calls if c[1]=='start'],[])
        self.assertTrue(all(s['ActiveState']=='inactive'for s in self.states.values()))
        self.assertEqual(self.journal[-1]['phase'],'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN')

    def test_loaded_capture_refresh_authorizes_only_its_new_effective_profile(self):
        refreshed=('refreshed-capture',)
        current={'capture':self.profile}
        self.old.unit_profile=lambda n:current['capture']if n==m.UNITS[1]else self.profile
        def refresh():current['capture']=refreshed;return refreshed
        capture={'validate':lambda:current['capture'],'refresh':refresh,'error_types':(m.SafeError,)}
        self.run_lifecycle(capture=capture)
        self.assertEqual(self.prior['profiles'][m.UNITS[1]],list(self.profile))
        self.assertIn({'phase':'CAPTURE_GUARD_VERIFIED'},self.journal)
        self.assertEqual([c for c in self.calls if c[1]=='start'],[['/usr/bin/systemctl','start',m.UNITS[2]]])

    def test_failed_recovery_after_verified_refresh_can_start_safe_capture(self):
        capture={'validate':lambda:self.profile,'refresh':lambda:self.profile,'error_types':(m.SafeError,)}
        with self.assertRaises(m.SafeError):self.run_lifecycle([self.pre,self.pre,m.SafeError('FORWARD_RECOVERY_RECOVERY')],capture=capture)
        self.assert_paused_with_capture();self.assertEqual(self.journal[-1]['phase'],'ORDERS_PAUSED_CAPTURE_ACTIVE')

    def test_safe_stage_and_boolean_commit_hint_survive_failure_journal(self):
        error=m.SafeError('FORWARD_RECOVERY_COMPLETE');error.committed_hint=True
        with self.assertRaisesRegex(m.SafeError,'FORWARD_RECOVERY_COMPLETE_ORDERS_PAUSED_CAPTURE_ACTIVE'):
            self.run_lifecycle([self.pre,self.pre,error])
        self.assertEqual(self.journal[-1],{'phase':'ORDERS_PAUSED_CAPTURE_ACTIVE',
            'failure_code':'FORWARD_RECOVERY_COMPLETE','committed_hint':True})

    def test_foreign_dependency_change_prevents_capture_fallback(self):
        calls={'validate':0}
        def validate():
            calls['validate']+=1
            if calls['validate']>1:raise m.SafeError('CAPTURE_GUARD_REVIEWED_SOURCE_MISMATCH')
            return self.profile
        capture={'validate':validate,'refresh':lambda:self.profile,'error_types':(m.SafeError,)}
        with self.assertRaisesRegex(m.SafeError,'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN'):
            self.run_lifecycle([self.pre,self.pre,m.SafeError('FORWARD_POST_RECOVERY_SOURCE_CHANGED')],capture=capture)
        self.assertEqual([c for c in self.calls if c[1]=='start'],[])

    def test_changed_configuration_prevents_capture_fallback(self):
        self.pub.require_configuration=lambda *a: (_ for _ in ()).throw(m.SafeError('FORWARD_CONFIGURATION_CHANGED'))
        capture={'validate':lambda:self.profile,'refresh':lambda:self.profile,'error_types':(m.SafeError,)}
        with self.assertRaisesRegex(m.SafeError,'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN'):
            self.run_lifecycle([self.pre,self.pre,m.SafeError('FORWARD_RECOVERY_COMPLETE')],capture=capture)
        self.assertEqual([c for c in self.calls if c[1]=='start'],[])

    def test_known_hash_source_replacement_during_quiescence_is_not_adopted(self):
        changed=('config',{m.HELPER_PATH:(types.SimpleNamespace(content=b'before'),('changed-inode',))})
        with self.assertRaisesRegex(m.SafeError,'SOURCE_CHANGED_DURING_QUIESCENCE'):
            self.run_lifecycle(guards=[self.guarded,changed],postcheck=m.SafeError('FORWARD_POST_RECOVERY_SOURCE_CHANGED'))
        self.last_publish.assert_not_called();self.assertTrue(all(s['ActiveState']=='inactive'for s in self.states.values()))

    def test_known_baseline_reversal_after_commit_is_not_safe_capture_fallback(self):
        actual=m.final_sources
        self.pub.guarded_snapshot=lambda *a:self.guarded[1][m.HELPER_PATH]
        with self.assertRaisesRegex(m.SafeError,'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN'):
            self.run_lifecycle(postcheck=lambda *a:actual(*a))
        self.assertEqual([c for c in self.calls if c[1]=='start'],[])


class ContractTests(unittest.TestCase):
    def test_original_receipts_are_scoped_private_and_include_inode_metadata_cas(self):
        spec=importlib.util.spec_from_file_location('forward_original_diag',ROOT/'deployment/diagnose_whatsapp_order_semantic_recovery.py')
        diag=importlib.util.module_from_spec(spec);sys.modules[spec.name]=diag;spec.loader.exec_module(diag)
        with tempfile.TemporaryDirectory(prefix='forward-original-receipts-')as folder:
            parent=pathlib.Path(folder);parent.chmod(0o700)
            pipeline=parent/m.OLD_PIPELINE;pipeline.mkdir(mode=0o700)
            backup=parent/m.OLD_BACKUP;backup.mkdir(mode=0o700)
            pub=types.SimpleNamespace(SOURCE_PINS={'app/fixed.php':('before','target')},RECOVERY_EVENT_IDS=tuple(diag.IDS))
            prior={'phase':'PRIOR_PIPELINES','before':{n:{'ActiveState':'inactive'}for n in m.UNITS},
                   'profiles':{n:[]for n in m.UNITS},'configuration_fingerprint':'a'*64}
            prior['before'][m.UNITS[2]]['ActiveState']='active'
            quiet={'phase':'QUIESCENT','recovery_scope':'b'*64,'recovery_pending':13}
            rows=[prior,quiet,{'phase':'CODE_PUBLISHED','backup':str(backup)},{'phase':'ORDERS_PAUSED_CAPTURE_ACTIVE'}]
            values={pipeline/'journal.jsonl':b''.join((json.dumps(r)+'\n').encode()for r in rows),
                    backup/'receipt.json':json.dumps({'targets':{'app/fixed.php':'target'}}).encode(),
                    backup/'recovery-intent.json':json.dumps({'event_ids':diag.IDS,'pending':13,'scope_hash':'b'*64}).encode(),
                    backup/'recovery-before-images.enc':b'bounded encrypted original beforeimage'}
            for path,data in values.items():path.write_bytes(data);path.chmod(0o600)
            first=m.validate_original(diag,pub,parent,os.geteuid())
            self.assertEqual(first[0],prior);self.assertEqual(len(first[-1]),4)
            path=backup/'recovery-before-images.enc';bytes_before=path.read_bytes();path.unlink();path.write_bytes(bytes_before);path.chmod(0o600)
            second=m.validate_original(diag,pub,parent,os.geteuid())
            self.assertNotEqual(first[-1],second[-1])
            (backup/'recovery-complete.json').write_bytes(b'{}');(backup/'recovery-complete.json').chmod(0o600)
            with self.assertRaises(m.SafeError):m.validate_original(diag,pub,parent,os.geteuid())

    def test_factory_receives_lexical_validated_backup_paths(self):
        root=pathlib.Path('/home/fasakha/public_html');calls=[]
        pub=types.SimpleNamespace(PHP='/php82',RECOVERY_PHP='original',RECOVERY_EVENT_IDS=(5945,))
        factory=types.SimpleNamespace(recovery_php=lambda original:'derived')
        def runner(cwd,args,capture=False):calls.append((cwd,args,capture));return 0,json.dumps({'ai_calls':0,'orders_created':0}).encode()
        m.run_recovery(pub,factory,root,{},root.parent/'whatsapp-release-backups/order-semantic-forward-x','a'*64,
            root.parent/'whatsapp-release-backups/order-semantic-ngb77kbf',root.parent/'candidate','preflight',runner)
        args=calls[0][1]
        self.assertEqual(args[6],str(root)+'/../whatsapp-release-backups/order-semantic-forward-x')
        self.assertEqual(args[8],str(root)+'/../whatsapp-release-backups/order-semantic-ngb77kbf')

    def test_failure_output_is_enum_and_fixed_event_only(self):
        pub=types.SimpleNamespace(PHP='/php',RECOVERY_PHP='original',RECOVERY_EVENT_IDS=(5945,))
        factory=types.SimpleNamespace(recovery_php=lambda _: 'program');root=pathlib.Path('/home/fasakha/public_html')
        out={'stage':'TRANSITIONS','event_id':5945,'ai_calls':0,'orders_created':0}
        with self.assertRaisesRegex(m.SafeError,'FORWARD_RECOVERY_TRANSITIONS_EVENT_5945'):
            m.run_recovery(pub,factory,root,{},root/'backup','a'*64,root/'old',None,'preflight',lambda *a,**k:(1,json.dumps(out).encode()))
        out['stage']='PRIVATE CUSTOMER TOKEN';out['event_id']='PRIVATE ID'
        with self.assertRaisesRegex(m.SafeError,'^FORWARD_RECOVERY_NEEDS_REVIEW$'):
            m.run_recovery(pub,factory,root,{},root/'backup','a'*64,root/'old',None,'preflight',lambda *a,**k:(1,json.dumps(out).encode()))

    def test_existing_locks_are_required_and_no_missing_file_is_created(self):
        with tempfile.TemporaryDirectory(prefix='forward-lock-test-')as folder:
            parent=pathlib.Path(folder)
            with self.assertRaises(m.SafeError):m.acquire_existing_locks(parent,os.geteuid())
            self.assertEqual(list(parent.iterdir()),[])
            for name in('orders-install.lock','inbox-install.lock'):
                p=parent/name;p.write_bytes(b'');p.chmod(0o600)
            fds=m.acquire_existing_locks(parent,os.geteuid())
            try:
                with self.assertRaises(m.SafeError):m.acquire_existing_locks(parent,os.geteuid())
            finally:
                for fd in fds:os.close(fd)

    def test_explicit_resume_names_require_both_nonempty_bounded_basenames(self):
        m.validate_resume_names(None,None)
        m.validate_resume_names('order-semantic-forward-test','semantic-forward-pipeline-test')
        for a,b in ((None,'semantic-forward-pipeline-test'),('order-semantic-forward-test',None),('',''),
                    ('../order-semantic-forward-test','semantic-forward-pipeline-test'),
                    ('order-semantic-forward-test','/tmp/semantic-forward-pipeline-test')):
            with self.subTest(names=(a,b)),self.assertRaises(m.SafeError):m.validate_resume_names(a,b)

    def test_private_resume_plan_binds_both_original_and_forward_receipts(self):
        plan=m.forward_plan(pathlib.Path('order-semantic-forward-test'),{'configuration_fingerprint':'a'*64},
                            {'recovery_scope':'b'*64},{m.HELPER_PATH:('old','new')})
        raw=(json.dumps(plan)+'\n'+json.dumps({'phase':'ORDERS_PAUSED_CAPTURE_ACTIVE'})+'\n').encode()
        self.assertEqual(m.validate_forward_journal(raw,plan)[0],plan)
        for change in ({'backup':'order-semantic-forward-other'},{'original_backup':'order-semantic-other'},
                       {'original_scope':'c'*64},{'targets':{m.HELPER_PATH:'unreviewed'}}):
            corrupted=(json.dumps({**plan,**change})+'\n').encode()
            with self.subTest(change=change),self.assertRaises(m.SafeError):m.validate_forward_journal(corrupted,plan)
        with self.assertRaises(m.SafeError):m.validate_forward_journal(raw+b'{"phase":"UNAUTHENTICATED_RESUME"}\n',plan)

    def test_failure_hint_is_boolean_and_not_a_resume_authorization(self):
        root=pathlib.Path('/home/fasakha/public_html')
        pub=types.SimpleNamespace(PHP='/php',RECOVERY_PHP='original',RECOVERY_EVENT_IDS=(5945,))
        factory=types.SimpleNamespace(recovery_php=lambda _: 'program')
        out={'stage':'COMPLETE','committed':True,'ai_calls':0,'orders_created':0}
        with self.assertRaises(m.SafeError)as ctx:
            m.run_recovery(pub,factory,root,{},root/'backup','a'*64,root/'old',None,'recover',lambda *a,**k:(1,json.dumps(out).encode()))
        self.assertTrue(ctx.exception.committed_hint)
        self.assertFalse(m.preflight_ready({'status':'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE','committed':True}))

    def test_pair_already_current_does_not_recreate_publications_directory(self):
        with tempfile.TemporaryDirectory(prefix='forward-publish-test-')as folder:
            root=pathlib.Path(folder);backup=root/'backup';backup.mkdir();(backup/'publications').mkdir()
            content=b'target';snapshot=(types.SimpleNamespace(content=content),('meta',))
            pub=types.SimpleNamespace(require_quiet=lambda *a:None,require_configuration=lambda *a:None,
                guarded_snapshot=lambda *a:snapshot,base=types.SimpleNamespace(command=None))
            self.assertIsNone(m.publish_pair(pub,root,os.geteuid(),None,{'x':snapshot},{'x':('old',m.blob(content))},{'x':content},backup))


class VersionedPinTests(unittest.TestCase):
    def test_published_forward_and_diagnostic_artifacts_remain_exact(self):
        pins={
            'deployment/resume_whatsapp_order_semantic_recovery_root.py':'b1cea63231f8664ec9bb6dd776ed94ff6eee2ea4',
            'deployment/whatsapp_order_semantic_forward_recovery.py':'68f2f8215a31b799df1f4d501110e89d39574618',
            'deployment/whatsapp_order_ai_forward_read_only_probe.php':'c756af71dccb91fdbf06eb577d4f351ee1eca5dd',
            'deployment/diagnose_whatsapp_forward_transition_root.py':'718f94d9961c670ea0a35e5c837f485ffafd000e',
            'deployment/refresh_whatsapp_capture_guard.py':'1e598a799d302ab90a899a679b224c67e574dbd0',
            'tests/fixtures/whatsapp_forward_v1/app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php':'89b98259217e02d300bc7aa5c70c7b58eb0dfbff'}
        for path,pin in pins.items():
            with self.subTest(path=path):self.assertEqual(m.blob((ROOT/path).read_bytes()),pin)

    def test_new_factory_and_reviewed_runtime_target_are_explicit(self):
        factory=ROOT/'deployment/whatsapp_order_nested_forward_recovery.py'
        self.assertEqual(m.blob(factory.read_bytes()),m.FACTORY_BLOB)
        self.assertEqual(m.blob((ROOT/m.HELPER_PATH).read_bytes()),m.HELPER_AFTER)
        self.assertNotEqual(m.HELPER_AFTER,'89b98259217e02d300bc7aa5c70c7b58eb0dfbff')
        self.assertEqual(m.PROTOCOL_AFTER,'c64f8cf89437bb7822fc767202f7cf5112bfd7fd')
        self.assertEqual(m.OLD_BACKUP,'order-semantic-ngb77kbf')
        self.assertEqual(m.OLD_PIPELINE,'semantic-pipeline-state-a_wh7l9m')

    def test_previous_failed_checkpoint_plan_cannot_be_rebound(self):
        backup=pathlib.Path('order-semantic-forward-ebr178vc')
        old_pair={m.HELPER_PATH:('f2ee72b55d9fd27478f5da852cc3533dbb6de747','89b98259217e02d300bc7aa5c70c7b58eb0dfbff'),
                  m.PROTOCOL_PATH:('ecde67c6a87ca8068bb077892fdbea522d89f217',m.PROTOCOL_AFTER)}
        new_pair={m.HELPER_PATH:(old_pair[m.HELPER_PATH][0],m.HELPER_AFTER),m.PROTOCOL_PATH:old_pair[m.PROTOCOL_PATH]}
        prior={'configuration_fingerprint':'a'*64};quiet={'recovery_scope':'b'*64}
        old=m.forward_plan(backup,prior,quiet,old_pair);new=m.forward_plan(backup,prior,quiet,new_pair)
        raw=(json.dumps(old)+'\n').encode();before=bytes(raw)
        with self.assertRaises(m.SafeError):m.validate_forward_journal(raw,new)
        self.assertEqual(raw,before)
        self.assertEqual(m.validate_forward_journal((json.dumps(new)+'\n').encode(),new),[new])


if __name__=='__main__':unittest.main()
