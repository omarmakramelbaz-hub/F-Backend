#!/usr/bin/env python3
"""Real encrypted fixed-fourteen recovery with bounded nested ad metadata.

Each native extension exists in authenticated raw JSON before the original
failed recovery writes its encrypted before-images. Runtime source evaluation,
SQL transactions, provenance and workflow callbacks are the reviewed classes.
No plaintext native or customer data is emitted by these test helpers.
"""
import importlib.util
import json
import pathlib
import stat
import sys
import tempfile
import unittest

import test_whatsapp_order_semantic_forward_php as forward
import test_whatsapp_order_semantic_recovery_php as fixtures

ROOT = pathlib.Path(__file__).resolve().parents[1]
MODULE = ROOT/'deployment/whatsapp_order_nested_forward_recovery.py'
HELPER = fixtures.PREFIX+'WhatsAppNativePlaceholderHydration.php'
PROTOCOL = 'app/Support/WhatsAppInboxProtocol.php'
SCALAR_HELPER_BLOB = '89b98259217e02d300bc7aa5c70c7b58eb0dfbff'
PROTOCOL_BLOB = 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd'


def nested_module():
    spec = importlib.util.spec_from_file_location('nested_forward_php_tested', MODULE)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def nested_php():
    return nested_module().recovery_php(fixtures.recovery_php())


NESTED_SEED = r'''
require 'vendor/autoload.php';require 'bootstrap/app.php';
$db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
$ids=[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338];$scenario=$argv[1]??'nested';
foreach($ids as$i=>$id){
 $event=$db::table('whatsapp_webhook_events')->where('id',$id)->first();
 $raw=json_decode($crypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR);
 $native=&$raw['entry'][0]['changes'][0]['value']['messages'][0];$referral=&$native['referral'];
 $referral['opaque_attribute']=['placement'=>'PRIVATE_NESTED_AD_SENTINEL',
  'flags'=>[true,false,null,5],'renditions'=>[['format'=>'image','asset_url'=>'https://cdn.example.test/private-ad']]];
 if($id===5945){
  if($scenario==='deep'){
   $value='PRIVATE_DEPTH_SENTINEL';for($depth=0;$depth<12;$depth++)$value=['opaque_level'=>$value];
   $referral['opaque_attribute']=$value;
  }elseif($scenario==='nodes')$referral['opaque_attribute']=array_fill(0,5,array_fill(0,26,1));
  elseif($scenario==='container_items')$referral['opaque_attribute']=array_fill(0,33,1);
  elseif($scenario==='string')$referral['opaque_attribute']=['opaque_string'=>str_repeat('x',4097)];
  elseif($scenario==='referral_bytes')$referral['opaque_attribute']=array_fill(0,8,str_repeat('x',4096));
  elseif($scenario==='known_id_array')$referral['source_id']=['opaque_id'=>'123456789'];
  elseif($scenario==='known_body_array')$referral['body']=['opaque_body'=>'PRIVATE_KNOWN_BODY'];
  elseif($scenario==='known_media_array')$referral['media_type']=['opaque_format'=>'image'];
  elseif($scenario==='known_url_array')$referral['source_url']=['opaque_url'=>'https://example.test/private'];
  elseif($scenario==='float')$referral['opaque_attribute']=['opaque_number'=>1.5];
  elseif($scenario==='negative')$referral['opaque_attribute']=['opaque_number'=>-1];
  elseif($scenario==='invalid_key')$referral['opaque_attribute']=["opaque\0key"=>'PRIVATE_KEY_SENTINEL'];
  elseif($scenario==='reserved_key')$referral['instructions']='PRIVATE_RESERVED_SENTINEL';
  elseif($scenario==='control')$referral['opaque_attribute']=['opaque_string'=>"PRIVATE_CONTROL_SENTINEL\0"];
  elseif($scenario==='context')$native['context']=['id'=>'wamid.PRIVATE_CONTEXT_SENTINEL'];
 }
 $oldReferral=null;
 if(($scenario==='equal_6514'&&$id===6514)||($id===5945&&in_array($scenario,['existing_change','existing_remove'],true))){
  $oldReferral=$referral;
  if($scenario==='existing_change')$oldReferral['body']='PRIVATE_EXISTING_REFERRAL';
  $message=$db::table('whatsapp_inbox_messages')->where('id',1001+$i)->first();
  $before=json_decode($crypt::decryptString($message->content),true,512,JSON_THROW_ON_ERROR);
  $before['content']['message']['referral']=$oldReferral;
  $db::table('whatsapp_inbox_messages')->where('id',$message->id)->update([
   'content'=>$crypt::encryptString(json_encode($before,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
  if($scenario==='existing_remove')unset($native['referral']);
 }
 $plain=json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
 $db::table('whatsapp_webhook_events')->where('id',$id)->update([
  'payload'=>$crypt::encryptString($plain),'payload_hash'=>hash('sha256',$plain)]);
 unset($native,$referral);
}
$db::table('whatsapp_order_drafts')->insert(['id'=>1,'conversation_id'=>501,'evidence_floor'=>900,'evidence_ceiling'=>1001,
 'evidence_hash'=>str_repeat('b',64),'status'=>'NONE','reason'=>'NO_ORDER','extraction'=>$crypt::encryptString('{"decision":"NONE","private":"PRIVATE_DRAFT_SENTINEL"}'),
 'customer_command_key'=>'00000000-0000-4000-8000-000000000011','ticket_command_key'=>'00000000-0000-4000-8000-000000000012',
 'created_at'=>'2026-10-12 20:20:00','updated_at'=>'2026-10-12 20:20:00']);
echo '{}';
'''


class NestedFixture(forward.ForwardFixture):
    def __init__(self, directory, scenario='nested', beforeimage_budget=False):
        self.scenario = scenario
        helper_bytes = (ROOT/HELPER).read_bytes()
        expected = nested_module().CANDIDATE_HELPER_BLOB
        if fixtures.blob(helper_bytes) != expected or expected == SCALAR_HELPER_BLOB:
            raise AssertionError('Reviewed new nested helper bytes must be explicit')
        super().__init__(directory, beforeimage_budget=beforeimage_budget, helper_bytes=helper_bytes)

    def mutate(self, *args):
        value = super().mutate(*args)
        if args == ('authentic_referrals',):
            self.f.run_php(NESTED_SEED, [self.scenario])
        return value

    def phase(self, phase, expected=None, expect_success=True, candidate=''):
        args = [phase, json.dumps(self.f.pins), json.dumps(fixtures.IDS), self.forward_arg,
                self.old_scope if expected is None else expected, self.original_arg, str(candidate)]
        result = self.f.run_php(nested_php(), args, expect_success=expect_success)
        return result.returncode, json.loads(result.stdout)

    def stage_before_production_pair(self):
        candidate = self.f.backup_parent/'nested-forward-candidate'
        candidate.mkdir(mode=0o700)
        for name, path in (('WhatsAppNativePlaceholderHydration.php', HELPER), ('WhatsAppInboxProtocol.php', PROTOCOL)):
            item = candidate/name
            item.write_bytes((self.f.root/path).read_bytes())
            item.chmod(0o600)
        old_helper, old_protocol = forward.exact_baseline_pair()
        self.f.write(HELPER, old_helper)
        self.f.write(PROTOCOL, old_protocol)
        self.f.pins[HELPER] = forward.BASELINE_HELPER_BLOB
        return candidate

    def publish_staged_pair(self, candidate):
        for name, path in (('WhatsAppNativePlaceholderHydration.php', HELPER), ('WhatsAppInboxProtocol.php', PROTOCOL)):
            data = (candidate/name).read_bytes()
            self.f.write(path, data)
            if path in self.f.pins:
                self.f.pins[path] = fixtures.blob(data)


INSPECT = r'''
require 'vendor/autoload.php';require 'bootstrap/app.php';
$db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
$ids=[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338];$scope='468336579702269:515388018324075:';
$out=['count'=>0,'native_proofs'=>true,'nested_metadata_exact'=>true,'before_images_exact'=>true,'only_customer_text'=>true,'transcript_metadata_absent'=>true];
$method=new \ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderWorkflow::class,'snapshot');$method->setAccessible(true);
$original=json_decode($crypt::decryptString(file_get_contents($argv[1])),true,512,JSON_THROW_ON_ERROR);
foreach($ids as$id){
 $event=$db::table('whatsapp_webhook_events')->where('id',$id)->first();
 $raw=json_decode($crypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR);
 $incoming=\App\Support\WhatsAppInboxProtocol::report($raw,'468336579702269','515388018324075')['messages'][0];
 $message=$db::table('whatsapp_inbox_messages')->where('message_key',hash('sha256','whatsapp-message-v1:'.$scope.$incoming['message_id']))->first();
 $thread=$db::table('whatsapp_inbox_conversations')->where('id',$message->conversation_id)->first();
 $dto=json_decode($crypt::decryptString($message->content),true,512,JSON_THROW_ON_ERROR);
 $out['count']++;
 $out['native_proofs']=$out['native_proofs']&&\App\Services\Dashboard\WhatsAppNativePlaceholderHydration::prove($thread,$message,$dto,$event,false);
 $out['nested_metadata_exact']=$out['nested_metadata_exact']&&$dto['content']['message']['referral']===$incoming['content']['message']['referral'];
 $out['before_images_exact']=$out['before_images_exact']&&$dto['content']['hydration']['before_ciphertext']===$original['messages'][(string)$message->id]['content'];
 $out['only_customer_text']=$out['only_customer_text']&&$dto['text']===$incoming['text'];
 $snapshot=$method->invoke(new \App\Services\Dashboard\WhatsAppOrderWorkflow(),(int)$thread->id,0,(int)$message->id,false);
 $serialized=json_encode($snapshot['transcript'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
 $out['transcript_metadata_absent']=$out['transcript_metadata_absent']&&strpos($serialized,'PRIVATE_NESTED_AD_SENTINEL')===false
  &&strpos($serialized,'opaque_attribute')===false&&strpos($serialized,'PRIVATE_DRAFT_SENTINEL')===false;
}
echo json_encode($out,JSON_THROW_ON_ERROR);
'''


FOCUSED = r'''
 $db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
 $event=$db::table('whatsapp_webhook_events')->where('id',5945)->first();
 $plain=$crypt::decryptString($event->payload);if(!hash_equals($event->payload_hash,hash('sha256',$plain)))throw new \RuntimeException();
 $raw=json_decode($plain,true,512,JSON_THROW_ON_ERROR);
 $incoming=\App\Support\WhatsAppInboxProtocol::report($raw,'468336579702269','515388018324075')['messages'][0];
 $key=hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$incoming['message_id']);
 $message=$db::table('whatsapp_inbox_messages')->where('message_key',$key)->first();
 $thread=$db::table('whatsapp_inbox_conversations')->where('id',$message->conversation_id)->first();
 $before=json_decode($crypt::decryptString($message->content),true,512,JSON_THROW_ON_ERROR);
 $scenario=$argv[8]??'';
 if($scenario==='object')$incoming['content']['message']['referral']['opaque_attribute']=(object)['opaque_string'=>'PRIVATE_OBJECT_SENTINEL'];
 elseif($scenario==='nested_object')$incoming['content']['message']['referral']['opaque_attribute']=['opaque_item'=>(object)['opaque_string'=>'PRIVATE_OBJECT_SENTINEL']];
 elseif($scenario==='invalid_utf8')$incoming['content']['message']['referral']['opaque_attribute']=['opaque_string'=>chr(255)];
 elseif($scenario==='key_length')$incoming['content']['message']['referral']['opaque_attribute']=[str_repeat('k',129)=>'PRIVATE_OVERSIZED_KEY'];
 else throw new \RuntimeException();
 $ready=\App\Services\Dashboard\WhatsAppNativePlaceholderHydration::transition($thread,$message,$before,$incoming);
 while(ob_get_level()>0)ob_end_clean();echo json_encode(['transition_ready'=>$ready,'writes'=>0,'ai_calls'=>0,'orders_created'=>0],JSON_THROW_ON_ERROR);
}catch(\Throwable$e){while(ob_get_level()>0)ob_end_clean();echo '{"focused_failed":true}';exit(1);}
'''


class NestedForwardPhpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixtures.SemanticRecoveryPhpTests.setUpClass()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-nested-forward-php-')
        self.f = NestedFixture(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def private_state(self):
        return {p.name: forward.file_identity(p) for p in self.f.original.iterdir()}

    def assert_protected_unchanged(self, before):
        after = self.f.f.control('dump')
        self.assertTrue(after['fixture_orders'] == before['fixture_orders'], 'Order metadata changed')
        self.assertTrue(after['fixture_ai_calls'] == before['fixture_ai_calls'], 'AI metadata changed')
        self.assertTrue(after['whatsapp_order_drafts'] == before['whatsapp_order_drafts'], 'Existing draft changed')
        self.assertEqual(self.f.f.configuration_snapshot(), self.f.f.config)
        self.f.original_unchanged(self)
        return after

    def reject_unchanged(self, phase='preflight', candidate=''):
        before = self.f.f.control('dump')
        original = self.private_state()
        journal = {p.name: forward.file_identity(p) for p in self.f.forward.iterdir()}
        code, value = self.f.phase(phase, expect_success=False, candidate=candidate)
        self.assertNotEqual(code, 0)
        self.assertFalse(value['committed'])
        self.assertEqual((value['ai_calls'], value['orders_created']), (0, 0))
        self.assertTrue(self.f.f.control('dump') == before, 'Rejected recovery changed a scoped row')
        self.assertEqual(self.private_state(), original)
        self.assertEqual({p.name: forward.file_identity(p) for p in self.f.forward.iterdir()}, journal)
        self.assert_protected_unchanged(before)
        return value

    def inspect_recovered(self):
        result = self.f.f.run_php(INSPECT, [self.f.original/'recovery-before-images.enc'])
        self.assertNotIn(b'PRIVATE_', result.stdout)
        self.assertNotIn(b'wamid.', result.stdout)
        self.assertEqual(json.loads(result.stdout), {'count': 13, 'native_proofs': True,
            'nested_metadata_exact': True, 'before_images_exact': True,
            'only_customer_text': True, 'transcript_metadata_absent': True})

    def assert_metadata_recovery(self, before, value):
        self.assertEqual(value['status'], 'SEMANTIC_FORWARD_RECOVERY_READY')
        self.assertTrue(value['committed'])
        self.assertEqual(value['metrics'], forward.METRICS)
        self.assertEqual((value['processed_total'], value['transitions_verified'], value['status_records']), (14, 13, 1))
        self.assertEqual((value['ai_calls'], value['orders_created']), (0, 0))
        after = self.assert_protected_unchanged(before)
        self.assertEqual(len(after['whatsapp_inbox_messages']), 13)
        self.assertEqual(len(after['whatsapp_inbox_conversations']), 13)
        self.assertEqual(after['whatsapp_inbox_ingestion_failures'], [])
        for old, new in zip(before['whatsapp_webhook_events'], after['whatsapp_webhook_events']):
            self.assertTrue({k: v for k, v in old.items() if k != 'processed_at'} ==
                            {k: v for k, v in new.items() if k != 'processed_at'}, 'Authenticated raw event changed')
            self.assertIsNotNone(new['processed_at'])
        for old, new in zip(before['whatsapp_inbox_messages'], after['whatsapp_inbox_messages']):
            self.assertTrue({k: v for k, v in old.items() if k not in ('type', 'content', 'sent_at', 'updated_at')} ==
                            {k: v for k, v in new.items() if k not in ('type', 'content', 'sent_at', 'updated_at')}, 'Native identity changed')
            self.assertEqual(new['type'], 'text')
        for old, new in zip(before['whatsapp_inbox_conversations'], after['whatsapp_inbox_conversations']):
            self.assertTrue({k: v for k, v in old.items() if k not in ('last_message_at', 'updated_at')} ==
                            {k: v for k, v in new.items() if k not in ('last_message_at', 'updated_at')}, 'Customer or peer binding changed')
        for old, new in zip(before['whatsapp_order_scans'], after['whatsapp_order_scans']):
            for key in ('conversation_id', 'auto_floor', 'claim_nonce', 'lease_until'):
                self.assertEqual(new[key], old[key])
        self.inspect_recovered()
        for name in ('forward-before-images.enc', 'forward-intent.json', 'forward-complete.json'):
            item = self.f.forward/name
            self.assertEqual(stat.S_IMODE(item.stat().st_mode), 0o600)
            self.assertEqual(item.stat().st_nlink, 1)
        encrypted = (self.f.forward/'forward-before-images.enc').read_bytes()
        self.assertNotIn(b'PRIVATE_', encrypted)
        self.assertNotIn(b'opaque_attribute', encrypted)

    def test_new_factory_changes_only_reviewed_helper_pin_in_original_fixed_program(self):
        expected = nested_module().CANDIDATE_HELPER_BLOB
        self.assertNotEqual(expected, SCALAR_HELPER_BLOB)
        self.assertEqual(nested_php(), forward.forward_php().replace(SCALAR_HELPER_BLOB, expected))
        self.assertEqual(fixtures.blob((ROOT/PROTOCOL).read_bytes()), PROTOCOL_BLOB)

    def test_actual_before_production_pair_preflights_only_private_new_nested_candidate(self):
        candidate = self.f.stage_before_production_pair()
        before = self.f.f.control('dump')
        candidates = {p.name: forward.file_identity(p) for p in candidate.iterdir()}
        code, value = self.f.phase('preflight', candidate=candidate)
        self.assertEqual(code, 0)
        self.assertEqual(value['status'], 'SEMANTIC_FORWARD_PREFLIGHT_READY')
        self.assertEqual((value['transitions_verified'], value['status_records']), (13, 1))
        self.assertEqual((value['ai_calls'], value['orders_created']), (0, 0))
        self.assertTrue(self.f.f.control('dump') == before)
        self.assertEqual({p.name: forward.file_identity(p) for p in candidate.iterdir()}, candidates)
        self.assertEqual(fixtures.blob((self.f.f.root/HELPER).read_bytes()), forward.BASELINE_HELPER_BLOB)
        self.assertEqual(fixtures.blob((self.f.f.root/PROTOCOL).read_bytes()), forward.BASELINE_PROTOCOL_BLOB)
        self.assertEqual(list(self.f.forward.iterdir()), [])
        self.assert_protected_unchanged(before)

    def test_frozen_scalar_candidate_refuses_same_authentic_scope_that_nested_candidate_accepts(self):
        candidate = self.f.stage_before_production_pair()
        helper = candidate/'WhatsAppNativePlaceholderHydration.php'
        reviewed_nested = helper.read_bytes()
        helper.write_bytes(forward.legacy_target_helper())
        before = self.f.f.control('dump')
        original = self.private_state()
        args = ['preflight', json.dumps(self.f.f.pins), json.dumps(fixtures.IDS), self.f.forward_arg,
                self.f.old_scope, self.f.original_arg, str(candidate)]
        result = self.f.f.run_php(forward.forward_php(), args, expect_success=False)
        self.assertNotEqual(result.returncode, 0)
        value = json.loads(result.stdout)
        self.assertEqual((value['stage'], value['event_id']), ('TRANSITIONS', 5945))
        self.assertFalse(value['committed'])
        self.assertEqual((value['ai_calls'], value['orders_created']), (0, 0))
        self.assertTrue(self.f.f.control('dump') == before)
        self.assertEqual(self.private_state(), original)
        self.assertEqual(list(self.f.forward.iterdir()), [])
        helper.write_bytes(reviewed_nested)
        code, value = self.f.phase('preflight', candidate=candidate)
        self.assertEqual(code, 0)
        self.assertEqual(value['status'], 'SEMANTIC_FORWARD_PREFLIGHT_READY')
        self.assertEqual(value['transitions_verified'], 13)
        self.assertTrue(self.f.f.control('dump') == before)
        self.assertEqual(self.private_state(), original)
        self.assertEqual(list(self.f.forward.iterdir()), [])
        self.assert_protected_unchanged(before)

    def test_all_thirteen_authentic_nested_extensions_and_played_are_recovered_atomically(self):
        before = self.f.f.control('dump')
        code, value = self.f.phase('recover')
        self.assertEqual(code, 0)
        self.assert_metadata_recovery(before, value)

    def test_twelve_enrichments_and_one_unchanged_existing_referral_keep_exact_provenance(self):
        with tempfile.TemporaryDirectory(prefix='wa-nested-forward-equal-') as folder:
            self.f = NestedFixture(folder, scenario='equal_6514')
            candidate = self.f.stage_before_production_pair()
            before = self.f.f.control('dump')
            self.assertEqual(self.f.phase('preflight', candidate=candidate)[0], 0)
            self.f.publish_staged_pair(candidate)
            code, value = self.f.phase('recover')
            self.assertEqual(code, 0)
            self.assert_metadata_recovery(before, value)

    def test_last_played_write_failure_rolls_back_all_thirteen_and_same_checkpoint_resumes(self):
        self.f.mutate('late_status_trigger')
        before = self.f.f.control('dump')
        code, value = self.f.phase('recover', expect_success=False)
        self.assertNotEqual(code, 0)
        self.assertEqual(value['stage'], 'STATUS_7215')
        self.assertFalse(value['committed'])
        self.assertTrue(self.f.f.control('dump') == before)
        self.assert_protected_unchanged(before)
        self.assertFalse((self.f.forward/'forward-complete.json').exists())
        journals = {name: forward.file_identity(self.f.forward/name)
                    for name in ('forward-before-images.enc', 'forward-intent.json')}
        self.f.mutate('drop_status_trigger')
        self.assertEqual(self.f.phase('preflight')[1]['status'], 'SEMANTIC_FORWARD_PREFLIGHT_READY')
        self.assertEqual({name: forward.file_identity(self.f.forward/name) for name in journals}, journals)
        code, value = self.f.phase('recover')
        self.assertEqual(code, 0)
        self.assert_metadata_recovery(before, value)
        self.assertEqual({name: forward.file_identity(self.f.forward/name) for name in journals}, journals)

    def test_completed_missing_receipt_is_read_only_until_full_proof_then_reconstructed(self):
        self.f.phase('recover')
        completion = self.f.forward/'forward-complete.json'
        completion.unlink()
        before = self.f.f.control('dump')
        journals = {p.name: forward.file_identity(p) for p in self.f.forward.iterdir()}
        code, value = self.f.phase('preflight')
        self.assertEqual(code, 0)
        self.assertEqual(value['status'], 'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE')
        self.assertFalse(value['completion_present'])
        self.assertFalse(completion.exists())
        self.assertTrue(self.f.f.control('dump') == before)
        self.assertEqual({p.name: forward.file_identity(p) for p in self.f.forward.iterdir()}, journals)
        code, value = self.f.phase('recover')
        self.assertEqual(code, 0)
        self.assertTrue(value['completion_present'])
        self.assertTrue(self.f.f.control('dump') == before)
        self.assertEqual({name: forward.file_identity(self.f.forward/name) for name in journals}, journals)
        self.assert_protected_unchanged(before)
        self.inspect_recovered()

    def test_corrupt_completed_native_marker_does_not_reconstruct_missing_receipt(self):
        self.f.phase('recover')
        completion = self.f.forward/'forward-complete.json'
        completion.unlink()
        self.f.f.control('mutate_marker')
        self.reject_unchanged()
        self.assertFalse(completion.exists())

    def test_deep_node_and_string_budgets_fail_before_journal_or_partial_hydration(self):
        for scenario in ('deep', 'nodes', 'container_items', 'string', 'referral_bytes'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory(prefix='wa-nested-forward-budget-') as folder:
                self.f = NestedFixture(folder, scenario=scenario)
                candidate = self.f.stage_before_production_pair()
                value = self.reject_unchanged(candidate=candidate)
                self.assertEqual(value['stage'], 'TRANSITIONS')
                self.assertEqual(value['event_id'], 5945)

    def test_known_referral_facts_are_not_reinterpreted_as_nested_customer_evidence(self):
        for scenario in ('known_id_array', 'known_body_array', 'known_media_array', 'known_url_array', 'reserved_key'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory(prefix='wa-nested-forward-known-') as folder:
                self.f = NestedFixture(folder, scenario=scenario)
                value = self.reject_unchanged()
                self.assertEqual(value['stage'], 'TRANSITIONS')
                self.assertEqual(value['event_id'], 5945)

    def test_nested_unsafe_scalar_key_and_control_types_remain_rejected(self):
        for scenario in ('float', 'negative', 'invalid_key', 'control'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory(prefix='wa-nested-forward-types-') as folder:
                self.f = NestedFixture(folder, scenario=scenario)
                self.assertEqual(self.reject_unchanged()['stage'], 'TRANSITIONS')

    def test_existing_referral_change_or_removal_and_core_context_conflict_remain_rejected(self):
        for scenario in ('existing_change', 'existing_remove', 'context'):
            with self.subTest(scenario=scenario), tempfile.TemporaryDirectory(prefix='wa-nested-forward-conflict-') as folder:
                self.f = NestedFixture(folder, scenario=scenario)
                self.assertEqual(self.reject_unchanged()['stage'], 'TRANSITIONS')

    def test_actual_private_candidate_rejects_php_objects_invalid_utf8_and_invalid_nested_key(self):
        candidate = self.f.stage_before_production_pair()
        header = nested_php().split(' $gather=static function(bool$lock)', 1)[0]
        before = self.f.f.control('dump')
        original = self.private_state()
        args = ['preflight', json.dumps(self.f.f.pins), json.dumps(fixtures.IDS), self.f.forward_arg,
                self.f.old_scope, self.f.original_arg, str(candidate)]
        for scenario in ('object', 'nested_object', 'invalid_utf8', 'key_length'):
            with self.subTest(scenario=scenario):
                result = self.f.f.run_php(header+FOCUSED, args+[scenario])
                self.assertEqual(json.loads(result.stdout), {'transition_ready': False, 'writes': 0, 'ai_calls': 0, 'orders_created': 0})
                self.assertNotIn(b'PRIVATE_', result.stdout)
                self.assertTrue(self.f.f.control('dump') == before)
                self.assertEqual(self.private_state(), original)
        self.assert_protected_unchanged(before)

    def test_aggregate_before_image_budget_remains_fixed_with_nested_metadata(self):
        with tempfile.TemporaryDirectory(prefix='wa-nested-forward-images-') as folder:
            self.f = NestedFixture(folder, beforeimage_budget=True)
            value = self.reject_unchanged()
            self.assertEqual(value['stage'], 'BEFOREIMAGE_BUDGET')
            self.assertEqual(list(self.f.forward.iterdir()), [])

    def test_unknown_quarantine_does_not_enter_fixed_fourteen_recovery(self):
        self.f.mutate('count_other_quarantines')
        before = self.f.f.control('dump')
        code, value = self.f.phase('recover')
        self.assertEqual(code, 0)
        self.assertEqual(value['metrics'], forward.METRICS)
        after = self.assert_protected_unchanged(before)
        for table, key in (('whatsapp_webhook_events', 'id'), ('whatsapp_inbox_ingestion_failures', 'event_id')):
            self.assertTrue([row for row in after[table] if row[key] == 8001] == [row for row in before[table] if row[key] == 8001])
        self.inspect_recovered()


if __name__ == '__main__':
    unittest.main()
