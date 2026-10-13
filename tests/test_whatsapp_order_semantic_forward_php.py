#!/usr/bin/env python3
"""Real SQLite/Crypt coverage for the controlled fourteen-event forward resume.

Uses the actual coordinator-derived PHP, Consumer, Workflow, Hydration, Protocol
and protected fingerprint. The original failed journal is immutable evidence.
"""
import importlib.util
import json
import os
import pathlib
import stat
import sys
import tempfile
import unittest

import test_whatsapp_order_semantic_recovery_php as fixtures

ROOT=pathlib.Path(__file__).resolve().parents[1]
COORDINATOR=ROOT/'deployment/whatsapp_order_semantic_forward_recovery.py'
BASELINE_HELPER_BLOB='f2ee72b55d9fd27478f5da852cc3533dbb6de747'
BASELINE_PROTOCOL_BLOB='ecde67c6a87ca8068bb077892fdbea522d89f217'
LEGACY_TARGET_HELPER_BLOB='89b98259217e02d300bc7aa5c70c7b58eb0dfbff'
LEGACY_TARGET_HELPER=ROOT/'tests/fixtures/whatsapp_forward_v1/app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'
METRICS={'events_seen':14,'events_processed':14,'events_skipped':0,'messages_inserted':0,
         'messages_replayed':0,'messages_hydrated':13,'quarantined_events':0,
         'ignored_records':0,'status_records':1,'errors':0}


def legacy_target_helper():
    helper=LEGACY_TARGET_HELPER.read_bytes()
    if fixtures.blob(helper)!=LEGACY_TARGET_HELPER_BLOB:
        raise AssertionError('The published scalar forward Helper fixture must remain exact')
    return helper


def exact_baseline_pair():
    # Independently pin reconstructed old bytes; no CI dependency on scratch or network.
    helper=legacy_target_helper().decode()
    helper=helper.replace("foreach (['from','from_user_id','to','to_user_id','context'] as $field)",
                          "foreach (['from','from_user_id','to','to_user_id','context','referral'] as $field)")
    helper=helper.replace('            if (!self::referralTransition($raw,$full)) return false;\n','')
    start=helper.index('\n\n    /** An unavailable-content notification')
    end=helper.index('\n    private static function time(',start)
    helper=(helper[:start]+helper[end:]).encode()
    protocol=(ROOT/'app/Support/WhatsAppInboxProtocol.php').read_text()
    start=protocol.index('                // Coexistence can emit a played receipt without message content.')
    end=protocol.index('                foreach ($channel[$key] as $status) {',start)
    protocol=protocol[:start]+protocol[end:]
    start=protocol.index('                    if (is_array($status) && self::identifier')
    end=protocol.index('\n                        $report[',start)
    protocol=(protocol[:start]+"                    if (is_array($status) && self::identifier($status['id'] ?? null, 512) && in_array($status['status'] ?? null, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)) {"+protocol[end:]).encode()
    if fixtures.blob(helper)!=BASELINE_HELPER_BLOB or fixtures.blob(protocol)!=BASELINE_PROTOCOL_BLOB:
        raise AssertionError('Exact original published source baseline must be preserved')
    return helper,protocol


def forward_php():
    spec=importlib.util.spec_from_file_location('semantic_forward_coordinator_php_test',COORDINATOR)
    module=importlib.util.module_from_spec(spec);sys.modules[spec.name]=module
    spec.loader.exec_module(module)
    return module.recovery_php(fixtures.recovery_php())


NATIVE_MUTATE = r'''
require 'vendor/autoload.php';require 'bootstrap/app.php';
$db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
$ids=[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338];
$action=$argv[1]??'';
if($action==='authentic_referrals'){
 foreach($ids as$id){$row=$db::table('whatsapp_webhook_events')->where('id',$id)->first();
  $raw=json_decode($crypt::decryptString($row->payload),true,512,JSON_THROW_ON_ERROR);
  $raw['entry'][0]['changes'][0]['value']['messages'][0]['referral']=[
   'source_type'=>'ad','source_id'=>'123456789012345','source_url'=>'https://fb.me/fixture',
   'headline'=>'Fictional salted fish menu','body'=>'Fictional fish delivery'];
  $plain=json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  $db::table('whatsapp_webhook_events')->where('id',$id)->update(['payload'=>$crypt::encryptString($plain),'payload_hash'=>hash('sha256',$plain)]);
 }
 echo '{}';
}elseif($action==='played'){
 $status=$argv[2]??'played';$field=$argv[3]??'messages';
 $value=['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075'],
 'contacts'=>[['wa_id'=>'201000000001','user_id'=>'US.played-fixture']],
 'statuses'=>[['id'=>'wamid.PLAYED.01','status'=>$status]]];
 if(($argv[4]??'')==='mixed')$value['messages']=[['id'=>'wamid.mixed7215','from'=>'201000000001','from_user_id'=>'US.played-fixture',
  'timestamp'=>'1791831660','type'=>'text','text'=>['body'=>'Never create this extra message']]];
 $raw=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>$field,'value'=>$value]]]]];
 $plain=json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
 $db::table('whatsapp_webhook_events')->where('id',7215)->update(['payload'=>$crypt::encryptString($plain),'payload_hash'=>hash('sha256',$plain)]);
 $db::table('whatsapp_inbox_ingestion_failures')->where('event_id',7215)->update(['reason'=>'INVALID_EVENT']);echo '{}';
}elseif($action==='drop_late_trigger'){$db::unprepared('DROP TRIGGER fixture_late_failure');echo '{}';
}elseif($action==='late_status_trigger'){
 $db::unprepared("CREATE TRIGGER fixture_played_failure BEFORE UPDATE OF processed_at ON whatsapp_webhook_events WHEN NEW.id=7215 BEGIN SELECT RAISE(ABORT,'fixture last status failure'); END");echo '{}';
}elseif($action==='drop_status_trigger'){$db::unprepared('DROP TRIGGER fixture_played_failure');echo '{}';
}elseif($action==='raw7215_hash'){
 $db::table('whatsapp_webhook_events')->where('id',7215)->update(['payload_hash'=>str_repeat('0',64)]);echo '{}';
}elseif($action==='beforeimage_budget'){
 foreach([501,502]as$id){$r=$db::table('whatsapp_inbox_conversations')->where('id',$id)->first();
  $customer=json_decode($crypt::decryptString($r->customer),true,512,JSON_THROW_ON_ERROR);
  $customer['fixture_padding']=str_repeat('x',4000000);
  $db::table('whatsapp_inbox_conversations')->where('id',$id)->update(['customer'=>$crypt::encryptString(json_encode($customer,JSON_THROW_ON_ERROR))]);
 }
 $r=$db::table('whatsapp_webhook_events')->where('id',7215)->first();
 $raw=json_decode($crypt::decryptString($r->payload),true,512,JSON_THROW_ON_ERROR);
 $raw['entry'][0]['changes'][0]['value']['fixture_padding']=str_repeat('y',2200000);
 $plain=json_encode($raw,JSON_THROW_ON_ERROR);
 $db::table('whatsapp_webhook_events')->where('id',7215)->update(['payload'=>$crypt::encryptString($plain),'payload_hash'=>hash('sha256',$plain)]);echo '{}';
}elseif($action==='late_busy_trigger'){
 $db::unprepared("CREATE TRIGGER fixture_late_busy AFTER UPDATE OF type ON whatsapp_inbox_messages WHEN NEW.id=1001 BEGIN UPDATE whatsapp_order_scans SET claim_nonce='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',lease_until='2099-01-01 00:00:00' WHERE conversation_id=513; END");echo '{}';
}elseif($action==='count_other_quarantines'){
 $db::table('whatsapp_webhook_events')->insert(['id'=>8001,'payload_hash'=>str_repeat('a',64),'payload'=>$crypt::encryptString('{"untouched":true}'),
  'received_at'=>'2026-10-12 20:20:00','processed_at'=>null]);
 $db::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>8001,'reason'=>'INVALID_PAYLOAD','attempts'=>8,'last_attempted_at'=>'2026-10-12 20:20:00']);echo '{}';
}elseif($action==='stable_before_images'){
 echo file_get_contents($argv[2]);
}else throw new \RuntimeException('Unknown forward fixture action');
'''


def file_identity(path):
    s=path.lstat()
    return(path.read_bytes(),s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode),s.st_dev,s.st_ino,
           s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)


class ForwardFixture:
    def __init__(self,directory,advance_clock=False,beforeimage_budget=False,helper_bytes=None):
        self.f=fixtures.RecoveryFixture(directory,advance_clock=advance_clock)
        helper=legacy_target_helper()if helper_bytes is None else helper_bytes
        if not isinstance(helper,bytes):raise AssertionError('Explicit test Helper must be immutable bytes')
        path=fixtures.PREFIX+'WhatsAppNativePlaceholderHydration.php'
        self.f.write(path,helper);self.f.pins[path]=fixtures.blob(helper)
        self.mutate('authentic_referrals');self.mutate('played')
        if beforeimage_budget:
            boot=self.f.root/'bootstrap/app.php'
            boot.write_bytes(boot.read_bytes().replace(b'<?php\n',b'<?php\nini_set("memory_limit","512M");\n',1))
            self.mutate('beforeimage_budget')
        self.old_scope=self.f.preflight()['scope_hash']
        # A trigger is not part of the encrypted scoped before-image. Its late
        # failure leaves the actual original recovery journal and all rows intact.
        self.f.control('trigger_late')
        code,value=self.f.phase('recover',self.old_scope,expect_success=False)
        if code==0 or value['committed']:raise AssertionError('Original failed journal fixture must roll back')
        self.mutate('drop_late_trigger')
        self.original=self.f.backup
        self.original_arg=self.f.backup_arg
        self.original_files={p.name:file_identity(p) for p in self.original.iterdir()}
        self.original_data=self.f.control('dump')
        self.current_scope=self.f.preflight()['scope_hash']
        if self.current_scope!=self.old_scope:raise AssertionError('Failed recovery must preserve exact original scoped data')
        self.forward=self.f.backup_parent/'order-semantic-forward-fixture'
        self.forward.mkdir(mode=0o700)
        self.forward_arg=str(self.f.root)+'/../whatsapp-release-backups/'+self.forward.name

    def mutate(self,*args):
        result=self.f.run_php(NATIVE_MUTATE,args)
        return json.loads(result.stdout)

    def phase(self,phase,expected=None,expect_success=True,candidate=''):
        # Coordinator invocation is fixed here once its public PHP contract is
        # frozen; all code is derived again for each actual execution.
        args=[phase,json.dumps(self.f.pins),json.dumps(fixtures.IDS),self.forward_arg,
              self.old_scope if expected is None else expected,self.original_arg,str(candidate)]
        result=self.f.run_php(forward_php(),args,expect_success=expect_success)
        return result.returncode,json.loads(result.stdout)

    def original_unchanged(self,case):
        case.assertEqual({p.name:file_identity(p) for p in self.original.iterdir()},self.original_files)
        case.assertEqual(self.f.configuration_snapshot(),self.f.config)


class SemanticForwardPhpTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='wa-semantic-forward-php-')
        self.f=ForwardFixture(self.temp.name)

    def tearDown(self):self.temp.cleanup()

    def reject_unchanged(self,expected=None):
        before=self.f.f.control('dump')
        code,value=self.f.phase('recover',expected,expect_success=False)
        self.assertNotEqual(code,0);self.assertFalse(value['committed'])
        self.assertEqual(value['ai_calls'],0);self.assertEqual(value['orders_created'],0)
        self.assertEqual(self.f.f.control('dump'),before)
        self.f.original_unchanged(self)
        return value

    def install_old_pair_and_stage_new(self):
        helper_path=fixtures.PREFIX+'WhatsAppNativePlaceholderHydration.php'
        protocol_path='app/Support/WhatsAppInboxProtocol.php'
        staged=self.f.f.backup_parent/'forward-candidate';staged.mkdir(mode=0o700)
        for name,path in (('WhatsAppNativePlaceholderHydration.php',helper_path),('WhatsAppInboxProtocol.php',protocol_path)):
            target=staged/name;target.write_bytes(self.f.f.root.joinpath(path).read_bytes());target.chmod(0o600)
        old_helper,old_protocol=exact_baseline_pair()
        self.f.f.write(helper_path,old_helper);self.f.f.write(protocol_path,old_protocol)
        self.f.f.pins[helper_path]=BASELINE_HELPER_BLOB
        return staged

    def test_successful_fixed_13_hydration_and_single_played_status_are_metadata_only(self):
        before=self.f.f.control('dump')
        code,value=self.f.phase('recover')
        self.assertEqual(code,0);self.assertTrue(value['committed'])
        self.assertEqual(set(value),{'status','committed','pending','total','processed_total','status_event_id','status_records',
            'metrics','original_scope_valid','transitions_verified','status_7215_verified','ai_calls','orders_created','scope_hash'})
        self.assertEqual(value['status'],'SEMANTIC_FORWARD_RECOVERY_READY');self.assertEqual(value['metrics'],METRICS)
        self.assertEqual((value['processed_total'],value['original_scope_valid'],value['transitions_verified'],value['status_7215_verified']),(14,True,13,True))
        self.assertEqual((value['ai_calls'],value['orders_created']),(0,0))
        after=self.f.f.control('dump')
        self.assertEqual(len(after['whatsapp_inbox_messages']),13)
        self.assertEqual(len(after['whatsapp_inbox_conversations']),13)
        self.assertTrue(all(p['proof'] and p['row_type']=='text' for p in self.f.f.control('prove')))
        self.assertEqual(after['whatsapp_inbox_ingestion_failures'],[])
        for old,new in zip(before['whatsapp_webhook_events'],after['whatsapp_webhook_events']):
            self.assertEqual({k:v for k,v in old.items() if k!='processed_at'},{k:v for k,v in new.items() if k!='processed_at'})
            self.assertIsNotNone(new['processed_at'])
        for old,new in zip(before['whatsapp_order_scans'],after['whatsapp_order_scans']):
            self.assertEqual(new['auto_floor'],old['auto_floor'])
            self.assertEqual(new['claim_nonce'],old['claim_nonce'])
        self.assertEqual(after['whatsapp_order_drafts'],before['whatsapp_order_drafts'])
        self.assertEqual(after['fixture_orders'],before['fixture_orders'])
        self.assertEqual(after['fixture_ai_calls'],before['fixture_ai_calls'])
        self.f.original_unchanged(self)

    def test_advancing_clock_preserves_current_native_proofs_after_fourteen_commit(self):
        with tempfile.TemporaryDirectory(prefix='wa-semantic-forward-clock-') as t:
            fixture=ForwardFixture(t,advance_clock=True)
            code,value=fixture.phase('recover')
            self.assertEqual(code,0);self.assertTrue(value['committed'])
            self.assertTrue(all(p['proof'] for p in fixture.f.control('prove')))
            fixture.original_unchanged(self)

    def test_late_7215_processed_marker_failure_rolls_back_all_13_hydrations(self):
        self.f.mutate('late_status_trigger');self.reject_unchanged()

    def test_busy_late_lease_does_not_clear_holder_or_partially_recover(self):
        self.f.f.control('busy_late');self.reject_unchanged()

    def test_lease_acquired_after_first_hydration_rolls_back_entire_fourteen_transaction(self):
        self.f.mutate('late_busy_trigger');value=self.reject_unchanged()
        self.assertEqual(value['stage'],'RECOVERY')

    def test_sealed_dispatched_ticket_evidence_cannot_enter_forward_recovery(self):
        for state in ('sealed','dispatched','ticket'):
            with self.subTest(state=state),tempfile.TemporaryDirectory(prefix='wa-semantic-forward-sealed-') as t:
                f=ForwardFixture(t);f.f.control('sealed',state);before=f.f.control('dump')
                code,value=f.phase('recover',expect_success=False)
                self.assertNotEqual(code,0);self.assertFalse(value['committed'])
                self.assertEqual(f.f.control('dump'),before);f.original_unchanged(self)

    def test_original_scope_cas_change_after_failed_attempt_refuses(self):
        self.f.f.control('mutate_scan');self.reject_unchanged()

    def test_native_target_raw_hash_change_refuses(self):
        self.f.f.control('mutate_raw',7338,'hash');self.reject_unchanged()

    def test_played_raw_hash_change_refuses(self):
        self.f.mutate('raw7215_hash');self.reject_unchanged()

    def test_played_wrong_source_mixed_message_unknown_status_refuse(self):
        for status,source,mixed in (('read','messages',''),('played','standby',''),('played','smb_message_echoes',''),('played','messages','mixed')):
            with self.subTest(status=status,source=source,mixed=mixed),tempfile.TemporaryDirectory(prefix='wa-semantic-forward-status-') as t:
                f=ForwardFixture(t);f.mutate('played',status,source,mixed);before=f.f.control('dump')
                code,value=f.phase('recover',expect_success=False)
                self.assertNotEqual(code,0);self.assertFalse(value['committed'])
                self.assertEqual(f.f.control('dump'),before);f.original_unchanged(self)

    def test_original_encrypted_before_images_cannot_be_replaced(self):
        path=self.f.original/'recovery-before-images.enc';content=path.read_bytes()
        path.write_bytes(content[:-1]+(b'A' if content[-1:]!=b'A' else b'B'))
        before=self.f.f.control('dump');code,value=self.f.phase('recover',expect_success=False)
        self.assertNotEqual(code,0);self.assertFalse(value['committed'])
        self.assertEqual(self.f.f.control('dump'),before)
        self.assertEqual(self.f.f.configuration_snapshot(),self.f.f.config)

    def test_fixed_forward_does_not_retry_other_quarantined_event(self):
        self.f.mutate('count_other_quarantines');before=self.f.f.control('dump')
        code,value=self.f.phase('recover')
        self.assertEqual(code,0);self.assertTrue(value['committed'])
        after=self.f.f.control('dump')
        for table,key in (('whatsapp_webhook_events','id'),('whatsapp_inbox_ingestion_failures','event_id')):
            self.assertEqual([r for r in after[table] if r[key]==8001],[r for r in before[table] if r[key]==8001])
        self.f.original_unchanged(self)

    def test_completed_repeat_proves_native_rows_before_skipping(self):
        self.f.phase('recover');before=self.f.f.control('dump')
        code,value=self.f.phase('recover')
        self.assertEqual(code,0)
        self.assertEqual(value['status'],'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE')
        self.assertEqual(value['metrics'],METRICS);self.assertTrue(value['completion_present'])
        self.assertEqual(self.f.f.control('dump'),before)
        self.assertTrue(all(p['proof'] for p in self.f.f.control('prove')))
        self.f.original_unchanged(self)

    def test_actual_old_live_pair_is_preflighted_using_private_new_candidate_memory(self):
        staged=self.install_old_pair_and_stage_new();before=self.f.f.control('dump')
        code,value=self.f.phase('preflight',candidate=staged)
        self.assertEqual(code,0)
        self.assertEqual(value,{'status':'SEMANTIC_FORWARD_PREFLIGHT_READY','committed':False,'pending':13,'total':13,
            'status_event_id':7215,'status_records':1,'original_scope_valid':True,'transitions_verified':13,
            'status_7215_verified':True,'ai_calls':0,'orders_created':0,'scope_hash':self.f.old_scope})
        self.assertEqual(self.f.f.control('dump'),before)
        self.assertEqual(fixtures.blob(self.f.f.root.joinpath(fixtures.PREFIX+'WhatsAppNativePlaceholderHydration.php').read_bytes()),BASELINE_HELPER_BLOB)
        self.assertEqual(fixtures.blob(self.f.f.root.joinpath('app/Support/WhatsAppInboxProtocol.php').read_bytes()),BASELINE_PROTOCOL_BLOB)
        self.assertEqual(list(self.f.forward.iterdir()),[]);self.f.original_unchanged(self)
        for name,path in (('WhatsAppNativePlaceholderHydration.php',fixtures.PREFIX+'WhatsAppNativePlaceholderHydration.php'),
                          ('WhatsAppInboxProtocol.php','app/Support/WhatsAppInboxProtocol.php')):
            data=(staged/name).read_bytes();self.f.f.write(path,data)
            if path in self.f.f.pins:self.f.f.pins[path]=fixtures.blob(data)
        self.assertEqual(self.f.phase('recover')[1]['metrics'],METRICS)

    def test_bad_candidate_preamble_never_executes(self):
        staged=self.install_old_pair_and_stage_new();path=staged/'WhatsAppNativePlaceholderHydration.php'
        path.write_bytes(b'<?php file_put_contents(getcwd()."/forbidden-candidate-executed", "bad");\n'+path.read_bytes()[5:])
        before=self.f.f.control('dump');code,value=self.f.phase('preflight',candidate=staged,expect_success=False)
        self.assertNotEqual(code,0);self.assertEqual(value['stage'],'CANDIDATE_CODE')
        self.assertFalse(self.f.f.root.joinpath('forbidden-candidate-executed').exists())
        self.assertEqual(self.f.f.control('dump'),before);self.f.original_unchanged(self)

    def test_candidate_private_file_and_directory_profiles_are_required(self):
        staged=self.install_old_pair_and_stage_new();file=staged/'WhatsAppInboxProtocol.php'
        for path,mode in ((file,0o644),(staged,0o755)):
            with self.subTest(path=path.name):
                before=self.f.f.control('dump');old=stat.S_IMODE(path.stat().st_mode);path.chmod(mode)
                code,value=self.f.phase('preflight',candidate=staged,expect_success=False)
                self.assertNotEqual(code,0);self.assertEqual(value['stage'],'CANDIDATE_CODE')
                path.chmod(old);self.assertEqual(self.f.f.control('dump'),before)
        self.f.original_unchanged(self)

    def test_missing_postcommit_completion_can_be_reconstructed_only_after_full_proof(self):
        self.f.phase('recover');path=self.f.forward/'forward-complete.json';path.unlink()
        before=self.f.f.control('dump')
        code,value=self.f.phase('preflight');self.assertEqual(code,0)
        self.assertEqual(value['status'],'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE')
        self.assertFalse(value['completion_present']);self.assertFalse(path.exists())
        self.assertEqual(self.f.f.control('dump'),before)
        code,value=self.f.phase('recover');self.assertEqual(code,0);self.assertTrue(value['completion_present'])
        self.assertEqual(value['metrics'],METRICS);self.assertTrue(path.exists())
        self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)
        self.assertEqual(self.f.f.control('dump'),before);self.f.original_unchanged(self)

    def test_missing_completion_with_corrupt_forward_intent_or_ciphertext_refuses(self):
        for name in ('forward-intent.json','forward-before-images.enc'):
            with self.subTest(name=name),tempfile.TemporaryDirectory(prefix='wa-semantic-forward-corrupt-') as t:
                f=ForwardFixture(t);f.phase('recover');f.forward.joinpath('forward-complete.json').unlink()
                path=f.forward/name;data=path.read_bytes();path.write_bytes(data[:-1]+b'!')
                before=f.f.control('dump');code,value=f.phase('recover',expect_success=False)
                self.assertNotEqual(code,0);self.assertEqual(value['stage'],'RESUME')
                self.assertFalse(f.forward.joinpath('forward-complete.json').exists())
                self.assertEqual(f.f.control('dump'),before);f.original_unchanged(self)

    def test_completed_scan_metadata_change_cannot_rebind_existing_completion(self):
        self.f.phase('recover');self.f.f.control('mutate_scan');self.reject_unchanged()

    def test_protected_fingerprint_and_access_pins_are_verified_before_bootstrap(self):
        for name in ('PosServiceTicket.php','WhatsAppInboxAccess.php'):
            with self.subTest(name=name),tempfile.TemporaryDirectory(prefix='wa-semantic-forward-protected-') as t:
                f=ForwardFixture(t);p=f.f.root/(fixtures.PREFIX+name);p.write_bytes(p.read_bytes()+b'\n/* foreign */\n')
                before=f.f.control('dump');code,value=f.phase('preflight',expect_success=False)
                self.assertNotEqual(code,0);self.assertEqual(value['stage'],'CODE')
                self.assertEqual(f.f.control('dump'),before);f.original_unchanged(self)

    def test_combined_before_image_budget_is_rejected_before_publication_or_journal(self):
        with tempfile.TemporaryDirectory(prefix='wa-semantic-forward-budget-') as t:
            f=ForwardFixture(t,beforeimage_budget=True)
            before=f.f.control('dump');code,value=f.phase('preflight',expect_success=False)
            self.assertNotEqual(code,0);self.assertEqual(value['stage'],'BEFOREIMAGE_BUDGET')
            self.assertFalse(value['committed']);self.assertEqual(list(f.forward.iterdir()),[])
            self.assertEqual(f.f.control('dump'),before);f.original_unchanged(self)

    def test_corrupt_completed_native_marker_never_claims_resume_success(self):
        self.f.phase('recover');self.f.f.control('mutate_marker');self.reject_unchanged()

    def test_late_rollback_can_resume_same_checkpoint_without_replacing_private_evidence(self):
        self.f.mutate('late_status_trigger');value=self.reject_unchanged()
        self.assertEqual(value['stage'],'STATUS_7215')
        self.assertFalse(self.f.forward.joinpath('forward-complete.json').exists())
        journals={name:file_identity(self.f.forward/name) for name in ('forward-before-images.enc','forward-intent.json')}
        self.f.mutate('drop_status_trigger');before=self.f.f.control('dump')
        code,value=self.f.phase('preflight');self.assertEqual(code,0)
        self.assertEqual(value['status'],'SEMANTIC_FORWARD_PREFLIGHT_READY')
        self.assertEqual(self.f.f.control('dump'),before)
        self.assertEqual({name:file_identity(self.f.forward/name) for name in journals},journals)
        code,value=self.f.phase('recover');self.assertEqual(code,0);self.assertTrue(value['committed'])
        self.assertEqual(value['metrics'],METRICS)
        self.assertEqual({name:file_identity(self.f.forward/name) for name in journals},journals)
        self.assertTrue(all(p['proof'] for p in self.f.f.control('prove')));self.f.original_unchanged(self)

    def test_pending_retry_rejects_changed_7215_before_state(self):
        self.f.mutate('late_status_trigger');self.reject_unchanged();self.f.mutate('drop_status_trigger')
        self.f.mutate('played') # Equivalent payload with new ciphertext still changes the fixed before-state.
        journals={p.name:file_identity(p) for p in self.f.forward.iterdir()}
        before=self.f.f.control('dump');code,value=self.f.phase('preflight',expect_success=False)
        self.assertNotEqual(code,0);self.assertEqual(value['stage'],'JOURNAL')
        self.assertEqual(self.f.f.control('dump'),before)
        self.assertEqual({p.name:file_identity(p) for p in self.f.forward.iterdir()},journals);self.f.original_unchanged(self)

    def test_pending_retry_rejects_corrupt_intent_or_before_image(self):
        for name in ('forward-intent.json','forward-before-images.enc'):
            with self.subTest(name=name),tempfile.TemporaryDirectory(prefix='wa-semantic-forward-pending-corrupt-') as t:
                f=ForwardFixture(t);f.mutate('late_status_trigger');f.phase('recover',expect_success=False)
                f.mutate('drop_status_trigger');path=f.forward/name;data=path.read_bytes();path.write_bytes(data[:-1]+b'!')
                journals={p.name:file_identity(p) for p in f.forward.iterdir()};before=f.f.control('dump')
                code,value=f.phase('preflight',expect_success=False)
                self.assertNotEqual(code,0);self.assertEqual(value['stage'],'JOURNAL')
                self.assertEqual(f.f.control('dump'),before)
                self.assertEqual({p.name:file_identity(p) for p in f.forward.iterdir()},journals);f.original_unchanged(self)

    def test_pending_retry_rejects_partial_pairs_unexplained_artifacts_and_completion(self):
        for variant in ('before_only','intent_only','unknown','complete'):
            with self.subTest(variant=variant),tempfile.TemporaryDirectory(prefix='wa-semantic-forward-pending-artifact-') as t:
                f=ForwardFixture(t);f.mutate('late_status_trigger');f.phase('recover',expect_success=False);f.mutate('drop_status_trigger')
                if variant=='before_only':f.forward.joinpath('forward-intent.json').unlink()
                elif variant=='intent_only':f.forward.joinpath('forward-before-images.enc').unlink()
                else:
                    path=f.forward/('alien.json' if variant=='unknown' else'forward-complete.json')
                    path.write_bytes(b'{}');path.chmod(0o600)
                journals={p.name:file_identity(p) for p in f.forward.iterdir()};before=f.f.control('dump')
                code,value=f.phase('preflight',expect_success=False)
                self.assertNotEqual(code,0);self.assertEqual(value['stage'],'JOURNAL')
                self.assertEqual(f.f.control('dump'),before)
                self.assertEqual({p.name:file_identity(p) for p in f.forward.iterdir()},journals);f.original_unchanged(self)

    def test_private_publication_records_are_allowed_and_never_modified(self):
        directory=self.f.forward/'publications';directory.mkdir(mode=0o700)
        path=directory/'original-helper.php';path.write_bytes(b'<?php /* original publication record */\n');path.chmod(0o600)
        before=file_identity(path)
        self.assertEqual(self.f.phase('preflight')[1]['status'],'SEMANTIC_FORWARD_PREFLIGHT_READY')
        self.assertEqual(self.f.phase('recover')[1]['metrics'],METRICS)
        self.assertEqual(file_identity(path),before);self.f.original_unchanged(self)


if __name__=='__main__':unittest.main()
