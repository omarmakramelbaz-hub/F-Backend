#!/usr/bin/env python3
"""Real PHP coverage for the fixed, read-only forward transition diagnostic.

The database contains encrypted authenticated native fixtures, and live classes
remain the independently reconstructed f2ee/ecde baseline. Reviewed candidates
are evaluated from private pinned snapshots, exactly as on the server.
"""
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

import test_whatsapp_order_semantic_forward_php as forward
import test_whatsapp_order_semantic_recovery_php as fixtures

ROOT = pathlib.Path(__file__).resolve().parents[1]
DIAGNOSTIC = ROOT/'deployment/diagnose_whatsapp_forward_transition_root.py'
HELPER = fixtures.PREFIX+'WhatsAppNativePlaceholderHydration.php'
PROTOCOL = 'app/Support/WhatsAppInboxProtocol.php'
SENTINELS = ('DO_NOT_LEAK_REFERRAL_BODY_5945', 'DO_NOT_LEAK_EXTENSION_VALUE_5945',
             'never_emit_private_extension_5945', 'Confirmed fictional fish order',
             'Fictional fixture', 'wamid.', 'US.semantic', '201111111111')


def diagnostic_module():
    spec = importlib.util.spec_from_file_location('forward_transition_diagnostic_test', DIAGNOSTIC)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


SEED = r'''
require 'vendor/autoload.php';require 'bootstrap/app.php';
$db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
$event=$db::table('whatsapp_webhook_events')->where('id',5945)->first();
$raw=json_decode($crypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR);
$referral=&$raw['entry'][0]['changes'][0]['value']['messages'][0]['referral'];
$referral['body']='DO_NOT_LEAK_REFERRAL_BODY_5945';
$referral['never_emit_private_extension_5945']='DO_NOT_LEAK_EXTENSION_VALUE_5945';
$plain=json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
$db::table('whatsapp_webhook_events')->where('id',5945)->update([
 'payload'=>$crypt::encryptString($plain),'payload_hash'=>hash('sha256',$plain)]);
echo '{}';
'''


class DiagnosticFixture(forward.ForwardFixture):
    def mutate(self, *args):
        result = super().mutate(*args)
        if args == ('authentic_referrals',):
            self.f.run_php(SEED, [])
        return result

    def stage_baseline_pair(self):
        self.candidate = self.f.backup_parent/'forward-diagnostic-candidate'
        self.candidate.mkdir(mode=0o700)
        for name, path in (('WhatsAppNativePlaceholderHydration.php', HELPER),
                           ('WhatsAppInboxProtocol.php', PROTOCOL)):
            item = self.candidate/name
            item.write_bytes((self.f.root/path).read_bytes())
            item.chmod(0o600)
        old_helper, old_protocol = forward.exact_baseline_pair()
        self.f.write(HELPER, old_helper)
        self.f.write(PROTOCOL, old_protocol)
        self.f.pins[HELPER] = forward.BASELINE_HELPER_BLOB
        return self.candidate

    def diagnostic_args(self, expected=None):
        return ['preflight', json.dumps(self.f.pins), json.dumps(fixtures.IDS),
                self.forward_arg, self.old_scope if expected is None else expected,
                self.original_arg, str(self.candidate)]


class RejectedReferralFixture(DiagnosticFixture):
    def mutate(self, *args):
        result = super().mutate(*args)
        if args == ('authentic_referrals',):
            # This is part of the authentic raw event before the original failed
            # recovery receipt is created, so its original scope stays exact.
            program = SEED.replace("$referral['body']=", "$referral['source_id']=123456;\n$referral['body']=", 1)
            self.f.run_php(program, [])
        return result


class AllRejectedReferralFixture(DiagnosticFixture):
    def mutate(self, *args):
        result = super().mutate(*args)
        if args == ('authentic_referrals',):
            program = SEED.replace("$event=$db::table('whatsapp_webhook_events')->where('id',5945)->first();",
                "$ids=[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338];\n"
                "foreach($ids as$id){$event=$db::table('whatsapp_webhook_events')->where('id',$id)->first();")
            program = program.replace("$referral['body']=", "$referral['source_id']=123456;\n$referral['body']=", 1)
            program = program.replace("->where('id',5945)->update", "->where('id',$id)->update")
            program = program.replace("echo '{}';", "}echo '{}';")
            self.f.run_php(program, [])
        return result


FOCUSED = r'''
 $db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
 $event=$db::table('whatsapp_webhook_events')->where('id',5945)->first();
 if(!$event||!is_string($event->payload)||strlen($event->payload)>8388608)throw new \RuntimeException();
 $plain=$crypt::decryptString($event->payload);
 if(strlen($plain)>4194304||!hash_equals($event->payload_hash,hash('sha256',$plain)))throw new \RuntimeException();
 $raw=json_decode($plain,true,512,JSON_THROW_ON_ERROR);
 $report=\App\Support\WhatsAppInboxProtocol::report($raw,'468336579702269','515388018324075');
 if(($report['quarantined_count']??-1)!==0||count($report['messages']??[])!==1)throw new \RuntimeException();
 $incoming=$report['messages'][0];
 $key=hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$incoming['message_id']);
 $message=$db::table('whatsapp_inbox_messages')->where('message_key',$key)->first();
 if(!$message||!is_string($message->content)||strlen($message->content)>8388608)throw new \RuntimeException();
 $thread=$db::table('whatsapp_inbox_conversations')->where('id',$message->conversation_id)
  ->where('waba_id','468336579702269')->where('phone_number_id','515388018324075')->first();
 if(!$thread)throw new \RuntimeException();
 $before=json_decode($crypt::decryptString($message->content),true,512,JSON_THROW_ON_ERROR);
 $scenario=$argv[8]??'';$referral=&$incoming['content']['message']['referral'];
 if($scenario==='source_id_integer')$referral['source_id']=123456;
 elseif($scenario==='url_credentials')$referral['source_url']='https://privateuser:privatepassword@example.com/DO_NOT_LEAK_URL';
 elseif($scenario==='url_whitespace')$referral['source_url']="https://example.com/private path\n";
 elseif($scenario==='media_type_null')$referral['media_type']=null;
 elseif($scenario==='media_url_null')$referral['image_url']=null;
 elseif($scenario==='unknown_nested')$referral['never_emit_private_extension_5945']=['never_emit_nested_key'=>'DO_NOT_LEAK_NESTED'];
 elseif($scenario==='unknown_float')$referral['never_emit_private_extension_5945']=1.5;
 elseif($scenario==='unknown_negative')$referral['never_emit_private_extension_5945']=-1;
 elseif($scenario==='unknown_reserved')$referral['instructions']='DO_NOT_LEAK_RESERVED_INSTRUCTION';
 elseif($scenario==='unknown_uppercase')$referral['NeverEmitUppercaseKey']='DO_NOT_LEAK_UPPERCASE_VALUE';
 elseif($scenario==='invalid_utf8')$referral['body']=chr(255).'DO_NOT_LEAK_INVALID_UTF8';
 elseif($scenario==='json_control')$referral['body']=json_decode('"DO_NOT_LEAK_ESCAPED_CONTROL\\u0000"',true,4,JSON_THROW_ON_ERROR);
 elseif($scenario==='equality_shortcut')$before['content']['message']['referral']=$referral;
 elseif($scenario==='strict_integer_float'){
  $referral['opaque_counter']=1.0;$before['content']['message']['referral']=$referral;
  $before['content']['message']['referral']['opaque_counter']=1;
 }
 elseif($scenario==='old_referral_conflict')$before['content']['message']['referral']=['source_type'=>'post','source_id'=>'1','source_url'=>'https://example.com/original'];
 elseif($scenario==='context_conflict')$incoming['content']['message']['context']=['id'=>'wamid.DO_NOT_LEAK_CONTEXT'];
 elseif($scenario==='peer_conflict')$incoming['peer_phone']='201222222222';
 elseif($scenario==='late_time')$incoming['sent_at']='2099-01-01 00:00:00';
 elseif($scenario==='error_key_null')$incoming['content']['message']['errors']=null;
 elseif($scenario==='missing_text')$incoming['text']='';
 elseif($scenario==='qualifying'){}
 else throw new \RuntimeException();
 $result=$rowDiagnostics($thread,$message,$before,$incoming);
 while(ob_get_level()>0)ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(\Throwable$e){while(ob_get_level()>0)ob_end_clean();
 echo '{"focused_diagnostic_failed":true}';exit(1);}
'''


def focused_php(module):
    # Use the authentic pinned candidate loader and real framework bootstrap,
    # stopping before any recovery gather, journal or transaction definition.
    header = forward.forward_php().split(' $gather=static function(bool$lock)', 1)[0]
    return header + module.row_diagnostics_php() + FOCUSED


class ForwardTransitionDiagnosticTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixtures.SemanticRecoveryPhpTests.setUpClass()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-forward-diagnostic-')
        self.fixture = DiagnosticFixture(self.temp.name)
        self.fixture.stage_baseline_pair()
        self.module = diagnostic_module()

    def tearDown(self):
        self.temp.cleanup()

    def snapshot(self):
        f = self.fixture
        return {
            'db': f.f.control('dump'), 'config': f.f.configuration_snapshot(),
            'original': {p.name: forward.file_identity(p) for p in f.original.iterdir()},
            'forward': {p.name: forward.file_identity(p) for p in f.forward.iterdir()},
            'candidate': {p.name: forward.file_identity(p) for p in f.candidate.iterdir()},
            'live': {path: forward.file_identity(f.f.root/path) for path in (HELPER, PROTOCOL)},
        }

    def assert_private(self, output):
        text = output.decode('utf-8')
        for sentinel in SENTINELS + ('DO_NOT_LEAK_URL', 'privateuser', 'privatepassword',
                                     'DO_NOT_LEAK_NESTED', 'never_emit_nested_key',
                                     'DO_NOT_LEAK_RESERVED', 'NeverEmitUppercaseKey',
                                     'DO_NOT_LEAK_UPPERCASE', 'DO_NOT_LEAK_INVALID_UTF8',
                                     'DO_NOT_LEAK_ESCAPED_CONTROL', 'DO_NOT_LEAK_CONTEXT'):
            self.assertNotIn(sentinel, text)
        # Real encrypted fixture blobs and signed-body hashes stay private too.
        data = self.fixture.f.control('dump')
        for event in data['whatsapp_webhook_events']:
            self.assertNotIn(event['payload'], text)
            self.assertNotIn(event['payload_hash'], text)
        for message in data['whatsapp_inbox_messages']:
            self.assertNotIn(message['content'], text)
            self.assertNotIn(message['message_key'], text)

    def run_read_only(self, focused=None, expected=None, success=True):
        before = self.snapshot()
        log = self.fixture.f.root/'query-log.jsonl'
        start = log.stat().st_size if log.exists() else 0
        program = (focused_php(self.module) if focused is not None
                   else self.module.diagnostic_php(fixtures.recovery_php()))
        args = self.fixture.diagnostic_args(expected)
        if focused is not None:
            args.append(focused)
        result = self.fixture.f.run_php(program, args, expect_success=success)
        self.assertEqual(self.snapshot(), before)
        queries = log.read_bytes()[start:].splitlines()
        for item in queries:
            sql = json.loads(item)['sql'].lstrip()
            self.assertNotRegex(sql, r'(?i)\A(?:insert|update|delete|replace|create|alter|drop|truncate|begin|commit|rollback|vacuum)\b')
        self.assert_private(result.stdout)
        self.assertEqual(result.stderr, b'')
        return result.returncode, json.loads(result.stdout)

    def assert_focused(self, scenario, transition, referral):
        code, value = self.run_read_only(focused=scenario)
        self.assertEqual(code, 0)
        self.assertIs(value['actual_transition_ready'], transition)
        self.assertIs(value['referral_ready'], referral)
        self.assertTrue(value['mbstring_available'])
        self.assertIn(value['referral_exception'], ('NONE', 'TYPE', 'ENCODING', 'OTHER'))
        self.assertIsInstance(value['gates'], dict)
        self.assertIsInstance(value['unknown_extensions'], dict)
        return value

    def test_private_candidate_proves_13_without_changing_baseline_or_receipts(self):
        code, value = self.run_read_only()
        self.assertEqual(code, 0)
        self.assertEqual(value['status'], 'FORWARD_TRANSITION_DIAGNOSTIC_READY')
        self.assertEqual([row['event_id'] for row in value['summaries']], list(fixtures.IDS))
        self.assertTrue(all(row['transition_ready'] and row['referral_ready'] for row in value['summaries']))
        self.assertEqual(value['detail']['event_id'], 5945)
        self.assertTrue(value['detail']['predicates']['actual_transition_ready'])
        self.assertEqual(tuple(value[key] for key in ('writes', 'worker_actions', 'ai_calls', 'orders_created')), (0, 0, 0, 0))
        self.assertEqual(fixtures.blob((self.fixture.f.root/HELPER).read_bytes()), forward.BASELINE_HELPER_BLOB)
        self.assertEqual(fixtures.blob((self.fixture.f.root/PROTOCOL).read_bytes()), forward.BASELINE_PROTOCOL_BLOB)
        self.assertEqual(list(self.fixture.forward.iterdir()), [])

    def test_fixed_original_scope_change_refuses_without_exposing_data(self):
        code, value = self.run_read_only(expected='0'*64, success=False)
        self.assertNotEqual(code, 0)
        self.assertNotEqual(value['status'], 'FORWARD_TRANSITION_DIAGNOSTIC_READY')
        self.assertEqual(tuple(value[key] for key in ('writes', 'worker_actions', 'ai_calls', 'orders_created')), (0, 0, 0, 0))

    def test_authenticated_rejected_5945_returns_safe_detail_and_other_12_summary(self):
        with tempfile.TemporaryDirectory(prefix='wa-forward-diagnostic-rejected-') as directory:
            self.fixture = RejectedReferralFixture(directory)
            self.fixture.stage_baseline_pair()
            code, value = self.run_read_only()
            self.assertEqual(code, 0)
            self.assertEqual(value['status'], 'FORWARD_TRANSITION_DIAGNOSTIC_READY')
            self.assertEqual([row['event_id'] for row in value['summaries']], list(fixtures.IDS))
            self.assertFalse(value['summaries'][0]['transition_ready'])
            self.assertFalse(value['summaries'][0]['referral_ready'])
            self.assertTrue(all(row['transition_ready'] and row['referral_ready'] for row in value['summaries'][1:]))
            self.assertFalse(value['detail']['predicates']['actual_transition_ready'])
            self.assertFalse(value['detail']['predicates']['referral_ready'])
            self.assertEqual(list(self.fixture.forward.iterdir()), [])

    def test_all_13_failed_referrals_have_compact_safe_checks_under_fixed_output_budget(self):
        with tempfile.TemporaryDirectory(prefix='wa-forward-diagnostic-all-rejected-') as directory:
            self.fixture = AllRejectedReferralFixture(directory)
            self.fixture.stage_baseline_pair()
            code, value = self.run_read_only()
            self.assertEqual(code, 0)
            self.assertEqual(value['status'], 'FORWARD_TRANSITION_DIAGNOSTIC_READY')
            self.assertEqual(len(value['summaries']), 13)
            for summary in value['summaries']:
                self.assertFalse(summary['transition_ready'])
                self.assertFalse(summary['referral_ready'])
                self.assertFalse(summary['referral_checks']['source_id_valid'])
                self.assertTrue(all(type(flag) is bool for flag in summary['referral_checks'].values()))
            self.assertEqual(self.module.MAX_METADATA_OUTPUT, 32768)
            self.assertLess(len(json.dumps(value, ensure_ascii=False).encode()), self.module.MAX_METADATA_OUTPUT)
            self.assertLess(len(json.dumps({'candidate_predicates': value, 'checkpoints': {
                'plan_bound': True, 'phases': ['FORWARD_PLAN'], 'backup_empty': True,
                'capture_guard_absent': True}, 'configuration_matches_original': True,
                'configuration_stable': True, 'writes': 0, 'worker_actions': 0,
                'ai_calls': 0, 'orders_created': 0}, ensure_ascii=False).encode()), self.module.MAX_METADATA_OUTPUT)

    def test_existing_durable_forward_before_images_are_not_modified_or_reconstructed(self):
        with tempfile.TemporaryDirectory(prefix='wa-forward-diagnostic-journal-') as directory:
            self.fixture = DiagnosticFixture(directory)
            self.fixture.mutate('late_status_trigger')
            code, value = self.fixture.phase('recover', expect_success=False)
            self.assertNotEqual(code, 0)
            self.assertFalse(value['committed'])
            self.fixture.mutate('drop_status_trigger')
            self.assertEqual({p.name for p in self.fixture.forward.iterdir()}, {'forward-before-images.enc', 'forward-intent.json'})
            self.fixture.stage_baseline_pair()
            code, value = self.run_read_only()
            self.assertEqual(code, 0)
            self.assertEqual(value['status'], 'FORWARD_TRANSITION_DIAGNOSTIC_READY')
            self.assertEqual({p.name for p in self.fixture.forward.iterdir()}, {'forward-before-images.enc', 'forward-intent.json'})

    def test_derived_diagnostic_has_no_journal_writer_consumer_or_transaction_path(self):
        program = self.module.diagnostic_php(fixtures.recovery_php())
        for forbidden in ('$write=static', '$journalProfile=static', '$pendingJournal=static',
                          '$db::transaction(', 'recoverUnavailableMessages(', 'noteProjectionRevision(',
                          "fopen($path,'xb')", 'fsync('):
            self.assertNotIn(forbidden, program)

    def test_referral_qualifies_and_all_sensitive_fixture_values_stay_private(self):
        value = self.assert_focused('qualifying', True, True)
        self.assertTrue(value['enrichment_applicable'])
        self.assertTrue(value['projected_ready'])
        self.assertEqual(value['referral_exception'], 'NONE')

    def test_source_id_numeric_value_is_not_reinterpreted_as_authentic_string(self):
        value = self.assert_focused('source_id_integer', False, False)
        self.assertFalse(value['gates']['source_id_valid'])
        self.assertEqual(value['known_referral_types']['source_id'], 'INTEGER')
        self.assertFalse(value['gates']['source_id_string_within_limit'])
        self.assertFalse(value['gates']['source_id_positive_decimal'])
        self.assertTrue(value['gates']['values_are_allowed_scalars'])

    def test_source_url_credentials_or_whitespace_are_distinguished_and_not_emitted(self):
        for scenario in ('url_credentials', 'url_whitespace'):
            with self.subTest(scenario=scenario):
                value = self.assert_focused(scenario, False, False)
                field = 'credentials_absent' if scenario == 'url_credentials' else 'no_ascii_space_or_control'
                self.assertFalse(value['referral_urls']['source_url'][field])
                self.assertFalse(value['referral_urls']['source_url']['actual_valid'])

    def test_optional_media_type_null_rejects_but_optional_media_url_null_accepts(self):
        value = self.assert_focused('media_type_null', False, False)
        self.assertFalse(value['gates']['media_type_valid'])
        value = self.assert_focused('media_url_null', True, True)
        self.assertFalse(value['referral_urls']['image_url']['applicable'])

    def test_unknown_nested_float_and_negative_scalars_are_rejected_without_names(self):
        for scenario in ('unknown_nested', 'unknown_float', 'unknown_negative'):
            with self.subTest(scenario=scenario):
                value = self.assert_focused(scenario, False, False)
                self.assertFalse(value['unknown_extensions']['values_are_allowed_scalars'])
                field = {'unknown_nested': 'has_nested_value', 'unknown_float': 'has_float',
                         'unknown_negative': 'has_negative_integer'}[scenario]
                self.assertTrue(value['unknown_extensions'][field])

    def test_reserved_and_invalid_unknown_key_shapes_are_rejected_without_names(self):
        for scenario in ('unknown_reserved', 'unknown_uppercase'):
            with self.subTest(scenario=scenario):
                value = self.assert_focused(scenario, False, False)
                field = 'keys_not_reserved' if scenario == 'unknown_reserved' else 'keys_match_syntax'
                self.assertFalse(value['unknown_extensions'][field])

    def test_invalid_utf8_and_json_escaped_control_reject_without_encoding_leak(self):
        for scenario in ('invalid_utf8', 'json_control'):
            with self.subTest(scenario=scenario):
                value = self.assert_focused(scenario, False, False)
                field = 'strings_valid_utf8' if scenario == 'invalid_utf8' else 'strings_control_free'
                self.assertFalse(value['gates'][field])

    def test_canonical_equality_shortcut_is_reported_as_not_applicable_enrichment(self):
        value = self.assert_focused('equality_shortcut', True, True)
        self.assertFalse(value['enrichment_applicable'])
        self.assertTrue(value['gates']['referral_canonical_equal'])

    def test_canonical_referral_comparison_preserves_integer_versus_float_distinction(self):
        value = self.assert_focused('strict_integer_float', False, False)
        self.assertTrue(value['enrichment_applicable'])
        self.assertFalse(value['gates']['referral_canonical_equal'])
        self.assertFalse(value['gates']['old_referral_absent_or_null'])
        self.assertFalse(value['unknown_extensions']['values_are_allowed_scalars'])

    def test_existing_referral_conflict_remains_immutable(self):
        value = self.assert_focused('old_referral_conflict', False, False)
        self.assertFalse(value['gates']['old_referral_absent_or_null'])

    def test_core_identity_context_time_and_text_mismatches_are_not_blame_on_referral(self):
        for scenario in ('context_conflict', 'peer_conflict', 'late_time', 'error_key_null', 'missing_text'):
            with self.subTest(scenario=scenario):
                value = self.assert_focused(scenario, False, True)
                self.assertTrue(value['enrichment_applicable'])
                checks = {'context_conflict': value['gates']['routing_context_matches']['context'],
                          'peer_conflict': value['gates']['peer_matches']['peer_phone'],
                          'late_time': value['gates']['native_time_window'],
                          'error_key_null': value['gates']['incoming_errors_absent'],
                          'missing_text': value['gates']['incoming_text']}
                self.assertFalse(checks[scenario])

    def test_unverified_candidate_cannot_execute_even_as_read_only_diagnostic(self):
        path = self.fixture.candidate/'WhatsAppNativePlaceholderHydration.php'
        path.write_bytes(b'<?php file_put_contents(getcwd()."/forbidden-diagnostic-executed","bad");\n'+path.read_bytes()[5:])
        code, value = self.run_read_only(success=False)
        self.assertNotEqual(code, 0)
        self.assertFalse((self.fixture.f.root/'forbidden-diagnostic-executed').exists())
        self.assertNotEqual(value['status'], 'FORWARD_TRANSITION_DIAGNOSTIC_READY')

    def test_combined_read_only_before_image_budget_is_fixed_and_never_expanded(self):
        with tempfile.TemporaryDirectory(prefix='wa-forward-diagnostic-budget-') as directory:
            self.fixture = DiagnosticFixture(directory, beforeimage_budget=True)
            self.fixture.stage_baseline_pair()
            code, value = self.run_read_only(success=False)
            self.assertNotEqual(code, 0)
            self.assertEqual(value['stage'], 'BEFOREIMAGE_BUDGET')
            self.assertEqual(tuple(value[key] for key in ('writes', 'worker_actions', 'ai_calls', 'orders_created')), (0, 0, 0, 0))
            self.assertEqual(list(self.fixture.forward.iterdir()), [])


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned checkpoint fixtures require the root CI container')
class ForwardDiagnosticCheckpointTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-forward-diagnostic-checkpoints-')
        self.module = diagnostic_module()
        self.diag = self.module.load_read_module(
            (ROOT/'deployment/diagnose_whatsapp_order_semantic_recovery.py').read_bytes(),
            'forward_diagnostic_checkpoint_read_guards')
        self.uid = os.geteuid()
        self.parent = pathlib.Path(self.temp.name)
        self.parent.chmod(0o700)
        self.backup = self.parent/self.module.FORWARD_BACKUP
        self.backup.mkdir(mode=0o700)
        self.pipeline = self.parent/self.module.FORWARD_PIPELINE
        self.pipeline.mkdir(mode=0o700)
        self.journal = self.pipeline/'journal.jsonl'
        self.prior = {'configuration_fingerprint': 'a'*64}
        self.quiet = {'recovery_scope': 'b'*64}
        self.plan = {'phase': 'FORWARD_PLAN', 'backup': self.module.FORWARD_BACKUP,
            'original_backup': self.module.OLD_BACKUP, 'original_pipeline': self.module.OLD_PIPELINE,
            'original_scope': self.quiet['recovery_scope'],
            'configuration_fingerprint': self.prior['configuration_fingerprint'],
            'targets': {self.module.HELPER_PATH: self.module.HELPER_BLOB,
                        self.module.PROTOCOL_PATH: self.module.PROTOCOL_BLOB}}
        self.write_plan(self.plan)

    def tearDown(self):
        self.temp.cleanup()

    def write_plan(self, plan):
        self.journal.write_text(json.dumps(plan)+'\n')
        self.journal.chmod(0o600)

    def checkpoint_snapshot(self):
        return (self.module.signature(self.parent.lstat()),
                self.module.signature(self.backup.lstat()),
                self.module.signature(self.pipeline.lstat()),
                forward.file_identity(self.journal))

    def check(self):
        return self.module.checkpoint_summary(self.diag, self.parent, self.uid, self.prior, self.quiet)

    def test_exact_fresh_root_plan_is_accepted_without_bytes_inode_mode_or_time_changes(self):
        self.assertEqual(self.module.FORWARD_BACKUP, 'order-semantic-forward-ebr178vc')
        self.assertEqual(self.module.FORWARD_PIPELINE, 'semantic-forward-pipeline-n32nlsny')
        self.assertEqual(self.module.OLD_BACKUP, 'order-semantic-ngb77kbf')
        self.assertEqual(self.module.OLD_PIPELINE, 'semantic-pipeline-state-a_wh7l9m')
        before = self.checkpoint_snapshot()
        summary, identities = self.check()
        self.assertEqual(summary, {'plan_bound': True, 'phases': ['FORWARD_PLAN'],
            'backup_empty': True, 'capture_guard_absent': True})
        self.assertEqual(identities[0], self.journal.read_bytes())
        self.assertEqual(self.checkpoint_snapshot(), before)

    def test_any_forward_backup_artifact_is_rejected_and_not_deleted(self):
        extra = self.backup/'forward-intent.json'
        extra.write_bytes(b'{}')
        extra.chmod(0o600)
        before = forward.file_identity(extra)
        with self.assertRaises(ValueError):
            self.check()
        self.assertEqual(forward.file_identity(extra), before)

    def test_capture_guard_or_extra_pipeline_entry_is_rejected_and_not_removed(self):
        for name in ('capture-guard', 'extra.json'):
            with self.subTest(name=name):
                item = self.pipeline/name
                if name == 'capture-guard':
                    item.mkdir(mode=0o700)
                else:
                    item.write_bytes(b'{}')
                    item.chmod(0o600)
                before = self.module.signature(item.lstat())
                with self.assertRaises(ValueError):
                    self.check()
                self.assertEqual(self.module.signature(item.lstat()), before)
                item.rmdir() if item.is_dir() else item.unlink()

    def test_plan_names_original_scope_configuration_and_exact_pair_targets_are_bound(self):
        changes = (
            {'backup': 'order-semantic-forward-other'},
            {'original_backup': 'order-semantic-other'},
            {'original_pipeline': 'semantic-pipeline-state-other'},
            {'original_scope': 'c'*64},
            {'configuration_fingerprint': 'd'*64},
            {'targets': {self.module.HELPER_PATH: self.module.BASELINE_HELPER_BLOB,
                         self.module.PROTOCOL_PATH: self.module.PROTOCOL_BLOB}},
            {'targets': {self.module.HELPER_PATH: self.module.HELPER_BLOB}},
            {'targets': {**self.plan['targets'], 'app/extra.php': 'e'*40}},
        )
        for change in changes:
            with self.subTest(change=change):
                self.write_plan({**self.plan, **change})
                before = self.checkpoint_snapshot()
                with self.assertRaises(ValueError):
                    self.check()
                self.assertEqual(self.checkpoint_snapshot(), before)

    def test_additional_phase_or_duplicate_plan_is_not_a_fresh_preflight_checkpoint(self):
        original = self.journal.read_bytes()
        for row in (self.plan, {'phase': 'ALL_FOURTEEN_RECOVERED'}):
            with self.subTest(phase=row['phase']):
                self.journal.write_bytes(original+(json.dumps(row)+'\n').encode())
                before = self.checkpoint_snapshot()
                with self.assertRaises(ValueError):
                    self.check()
                self.assertEqual(self.checkpoint_snapshot(), before)

    def test_journal_symlink_is_rejected_without_following_or_modifying_target(self):
        original = self.journal.read_bytes()
        self.journal.unlink()
        target = self.parent/'external-private-journal'
        target.write_bytes(original)
        target.chmod(0o600)
        before = forward.file_identity(target)
        self.journal.symlink_to(target)
        with self.assertRaises(ValueError):
            self.check()
        self.assertTrue(self.journal.is_symlink())
        self.assertEqual(forward.file_identity(target), before)

    def test_nonprivate_journal_mode_or_hardlink_is_rejected_without_normalization(self):
        self.journal.chmod(0o640)
        before = self.checkpoint_snapshot()
        with self.assertRaises(ValueError):
            self.check()
        self.assertEqual(self.checkpoint_snapshot(), before)
        self.journal.chmod(0o600)
        alias = self.parent/'journal-alias'
        os.link(self.journal, alias)
        before = self.checkpoint_snapshot()
        with self.assertRaises(ValueError):
            self.check()
        self.assertEqual(self.checkpoint_snapshot(), before)
        self.assertEqual(alias.stat().st_ino, self.journal.stat().st_ino)

    def test_wrong_root_journal_owner_and_nonprivate_checkpoint_directories_refuse(self):
        # The managed local filesystem exposes only the current UID. Change
        # exactly the reported leaf owner; keep real reads/profiles for all
        # other fields and paths, including fstat and descriptor race checks.
        before = self.checkpoint_snapshot()
        actual_lstat = pathlib.Path.lstat
        def wrong_owner(path, *args, **kwargs):
            info = actual_lstat(path, *args, **kwargs)
            if path == self.journal:
                values = list(info)
                values[4] = 12345
                return os.stat_result(values)
            return info
        with mock.patch.object(pathlib.Path, 'lstat', wrong_owner):
            with self.assertRaises(ValueError):
                self.check()
        self.assertEqual(self.checkpoint_snapshot(), before)
        for path in (self.backup, self.pipeline):
            with self.subTest(path=path.name):
                path.chmod(0o755)
                before = self.checkpoint_snapshot()
                with self.assertRaises(ValueError):
                    self.check()
                self.assertEqual(self.checkpoint_snapshot(), before)
                path.chmod(0o700)

    def test_original_four_phase_state_binds_quiet_count_backup_and_prior_timers(self):
        prior = {'phase': 'PRIOR_PIPELINES',
            'before': {unit: {'ActiveState': 'inactive'} for unit in self.diag.UNITS},
            'profiles': {unit: [] for unit in self.diag.UNITS},
            'configuration_fingerprint': 'a'*64}
        prior['before'][self.diag.UNITS[2]]['ActiveState'] = 'active'
        quiet = {'phase': 'QUIESCENT', 'recovery_scope': 'b'*64, 'recovery_pending': 13}
        rows = [prior, quiet,
            {'phase': 'CODE_PUBLISHED', 'backup': str(self.parent/self.module.OLD_BACKUP)},
            {'phase': 'ORDERS_PAUSED_CAPTURE_ACTIVE'}]
        before = self.checkpoint_snapshot()
        for capture_state in ('inactive', 'failed'):
            valid = json.loads(json.dumps(rows))
            valid[0]['before'][self.diag.UNITS[0]]['ActiveState'] = capture_state
            self.assertEqual(self.module.original_pipeline_state(self.diag, valid, self.parent), (valid[0], valid[1]))
            self.assertEqual(self.checkpoint_snapshot(), before)
        variants = []
        for kind in ('wrong_phase', 'reordered', 'pending', 'backup', 'relative_backup', 'capture_active', 'orders_inactive'):
            bad = json.loads(json.dumps(rows))
            if kind == 'wrong_phase':
                bad[3]['phase'] = 'RECOVERY_COMMITTED'
            elif kind == 'reordered':
                bad[1], bad[2] = bad[2], bad[1]
            elif kind == 'pending':
                bad[1]['recovery_pending'] = 12
            elif kind == 'backup':
                bad[2]['backup'] = str(self.parent/'order-semantic-other')
            elif kind == 'relative_backup':
                bad[2]['backup'] = self.module.OLD_BACKUP
            elif kind == 'capture_active':
                bad[0]['before'][self.diag.UNITS[0]]['ActiveState'] = 'active'
            else:
                bad[0]['before'][self.diag.UNITS[2]]['ActiveState'] = 'inactive'
            variants.append((kind, bad))
        for kind, bad in variants:
            with self.subTest(kind=kind):
                with self.assertRaises(ValueError):
                    self.module.original_pipeline_state(self.diag, bad, self.parent)
                self.assertEqual(self.checkpoint_snapshot(), before)

    def test_original_completion_must_be_absent_and_existing_file_or_symlink_is_untouched(self):
        original = self.parent/self.module.OLD_BACKUP
        original.mkdir(mode=0o700)
        completion = original/'recovery-complete.json'
        directory_before = self.module.signature(original.lstat())
        self.assertIsNone(self.module.require_absent(completion))
        self.assertEqual(self.module.signature(original.lstat()), directory_before)
        completion.write_bytes(b'{"fixture":"existing completion must remain untouched"}')
        completion.chmod(0o600)
        before = forward.file_identity(completion)
        with self.assertRaises(ValueError):
            self.module.require_absent(completion)
        self.assertEqual(forward.file_identity(completion), before)
        completion.unlink()
        target = self.parent/'completion-private-target'
        target.write_bytes(b'private completion target')
        target.chmod(0o600)
        target_before = forward.file_identity(target)
        for path in (target, self.parent/'absent-completion-target'):
            with self.subTest(target_exists=path.exists()):
                completion.symlink_to(path)
                link_before = self.module.signature(completion.lstat())
                with self.assertRaises(ValueError):
                    self.module.require_absent(completion)
                self.assertEqual(self.module.signature(completion.lstat()), link_before)
                self.assertEqual(forward.file_identity(target), target_before)
                completion.unlink()


if __name__ == '__main__':
    unittest.main()
