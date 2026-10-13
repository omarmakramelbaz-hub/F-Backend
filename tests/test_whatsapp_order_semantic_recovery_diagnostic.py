#!/usr/bin/env python3
"""The failed-recovery diagnostic reads receipts and actual scoped proofs only."""
import importlib.util
import json
import os
import pathlib
import re
import stat
import sys
import tempfile
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tests'))
import test_whatsapp_order_semantic_recovery_php as fixtures
spec=importlib.util.spec_from_file_location('semantic_recovery_diagnostic',ROOT/'deployment/diagnose_whatsapp_order_semantic_recovery.py')
diag=importlib.util.module_from_spec(spec);sys.modules[spec.name]=diag;spec.loader.exec_module(diag)


class PrivateReceiptTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='wa-semantic-diag-private-');self.parent=pathlib.Path(self.temp.name)
        self.parent.chmod(0o700);self.path=self.parent/'intent.json';self.path.write_bytes(b'private sentinel');self.path.chmod(0o600)

    def tearDown(self):self.temp.cleanup()

    def test_bounded_private_read_preserves_metadata_and_bytes(self):
        before=diag.signature(self.path.stat());value,metadata=diag.private_file(self.path,os.geteuid(),100)
        self.assertEqual(value,b'private sentinel');self.assertEqual(metadata,{'exists':True,'bytes':16})
        self.assertEqual(diag.signature(self.path.stat()),before)

    def test_before_images_are_only_statted_never_read(self):
        with mock.patch.object(diag.os,'fdopen',side_effect=AssertionError('encrypted beforeimages must not be read')):
            value,metadata=diag.private_file(self.path,os.geteuid(),100,read=False)
        self.assertIsNone(value);self.assertEqual(metadata['bytes'],16)

    def test_symlink_hardlink_mode_owner_oversize_and_directory_are_refused(self):
        self.path.chmod(0o644)
        with self.assertRaises(ValueError):diag.private_file(self.path,os.geteuid(),100)
        self.path.chmod(0o600)
        with self.assertRaises(ValueError):diag.private_file(self.path,os.geteuid(),3)
        alias=self.parent/'alias';os.link(self.path,alias)
        with self.assertRaises(ValueError):diag.private_file(self.path,os.geteuid(),100)
        alias.unlink();self.path.unlink();self.path.symlink_to(self.parent/'missing')
        with self.assertRaises(ValueError):diag.private_file(self.path,os.geteuid(),100)
        self.path.unlink();self.path.mkdir(mode=0o600)
        with self.assertRaises(ValueError):diag.private_file(self.path,os.geteuid(),100)

    def test_inode_replacement_between_outer_stat_and_open_is_refused(self):
        original=diag.os.open
        def racing_open(path,*args,**kwargs):
            self.path.unlink();self.path.write_bytes(b'other sentinel');self.path.chmod(0o600)
            return original(path,*args,**kwargs)
        with mock.patch.object(diag.os,'open',side_effect=racing_open),self.assertRaises(ValueError):
            diag.private_file(self.path,os.geteuid(),100)

    def test_optional_absent_receipt_and_fixed_paths(self):
        self.assertEqual(diag.private_file(self.parent/'absent',os.geteuid(),100,absent_ok=True),(None,None))
        self.assertEqual(diag.BACKUP_NAME,'order-semantic-ngb77kbf')
        self.assertEqual(diag.PIPELINE_NAME,'semantic-pipeline-state-a_wh7l9m')

    def test_corrupted_private_journal_never_forwards_unbounded_pending_value(self):
        prior={'phase':'PRIOR_PIPELINES','before':{u:{'ActiveState':'active'}for u in diag.UNITS},
               'profiles':{u:[]for u in diag.UNITS},'configuration_fingerprint':'a'*64}
        quiet={'phase':'QUIESCENT','recovery_scope':'b'*64,'recovery_pending':13}
        summary,_,_=diag.pipeline_summary([prior,quiet])
        self.assertEqual(summary['quiescent_pending'],13)
        for value in ('private secret',True,None,14,{'token':'private secret'}):
            with self.subTest(value=value),self.assertRaises(ValueError):
                diag.pipeline_summary([prior,{**quiet,'recovery_pending':value}])

    def test_wrong_owner_and_symlink_ancestor_refuse_before_any_read(self):
        with self.assertRaises(ValueError):diag.private_file(self.path,os.geteuid()+1,100)
        self.path.unlink();self.path.symlink_to(self.parent)
        with self.assertRaises(ValueError):diag.private_file(self.path/'file',os.geteuid(),100,absent_ok=True)


class ReadOnlyPhpDiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='wa-semantic-diag-php-');self.f=fixtures.RecoveryFixture(self.temp.name)

    def tearDown(self):self.temp.cleanup()

    def run_diagnostic(self,expected=None):
        if expected is None:expected=self.f.preflight()['scope_hash']
        before=self.f.control('dump');config=self.f.configuration_snapshot();backups=list(self.f.backup.iterdir())
        program=diag.diagnostic_php(fixtures.recovery_php())
        self.assertNotIn('lockForUpdate',program);self.assertNotIn('recoverUnavailableMessages',program)
        self.assertNotIn('noteProjectionRevision',program);self.assertNotIn('$write(',program)
        proof_pins={**self.f.pins,**{p:fixtures.blob((self.f.root/p).read_bytes())for p in (
            'app/Support/WhatsAppInboxProtocol.php',fixtures.PREFIX+'PosServiceTicket.php',fixtures.PREFIX+'WhatsAppInboxAccess.php')}}
        result=self.f.run_php(program,['preflight',json.dumps(self.f.pins),json.dumps(diag.IDS),'',expected,
            json.dumps(list(diag.REASONS)+['READ_ONLY_QUERY_GUARD','LOADED_CLASS_MISMATCH']),json.dumps(proof_pins)])
        text=result.stdout.decode();value=json.loads(text)
        self.assertEqual(self.f.control('dump'),before);self.assertEqual(self.f.configuration_snapshot(),config)
        self.assertEqual(list(self.f.backup.iterdir()),backups)
        self.assertNotIn('Confirmed fictional fish order',text);self.assertNotRegex(text,r'wamid\.[A-Za-z0-9]')
        self.assertNotRegex(text,r'"(?:payload|content|customer|claim_nonce|scope_hash|before_ciphertext)"')
        self.assertNotRegex(text,r'\b[a-f0-9]{64}\b')
        self.assertEqual((value['writes'],value['ai_calls'],value['orders_created']),(0,0,0))
        return value

    def test_actual_protected_fingerprint_and_native_projection_ready_for_all_thirteen(self):
        value=self.run_diagnostic();self.assertEqual(value['status'],'SCOPED_PREFLIGHT_READY')
        self.assertEqual((value['pending'],value['total']),(13,13));self.assertTrue(value['scope_matches_original_quiescent'])
        self.assertTrue(value['activation_message_id_valid']);self.assertTrue(all(c['loaded_source_matches']for c in value['classes']))
        self.assertEqual([r['event_id']for r in value['rows']],diag.IDS)
        for r in value['rows']:
            self.assertTrue(r['native_id_is_wamid']);self.assertTrue(r['raw_hash_valid']);self.assertTrue(r['old_projected_ready'])
            self.assertTrue(r['transition_ready']);self.assertTrue(r['routing_context_matches']);self.assertIsNone(r['old_projected_http'])

    def test_changed_scope_is_a_boolean_without_any_beforeimage_output(self):
        expected=self.f.preflight()['scope_hash'];self.f.control('mutate_scan')
        value=self.run_diagnostic(expected);self.assertFalse(value['scope_matches_original_quiescent'])

    def test_raw_hash_failure_is_a_fixed_enum_and_event_id(self):
        self.f.control('mutate_raw',7338,'hash')
        value=self.run_diagnostic('a'*64)
        self.assertEqual(value['status'],'SCOPED_PREFLIGHT_NOT_READY')
        self.assertEqual(value['first_failed_check'],'RAW_HASH_OR_PLAIN_SIZE');self.assertEqual(value['event_id'],7338)

    def test_context_conflict_reports_transition_false_without_attempting_recovery(self):
        self.f.control('mutate_placeholder','context')
        value=self.run_diagnostic();self.assertFalse(value['rows'][-1]['routing_context_matches'])
        self.assertFalse(value['rows'][-1]['transition_ready'])

    def test_active_lease_is_reported_without_resolving_or_resetting_it(self):
        self.f.control('busy_late');value=self.run_diagnostic()
        self.assertTrue(any(s['lease_active']for s in value['scans']))

    def test_processed_completion_requires_shared_proof_and_no_mutation(self):
        self.f.recover(self.f.preflight()['scope_hash']);value=self.run_diagnostic()
        self.assertEqual(value['pending'],0)
        self.assertTrue(all(r['completed_proof_ready']and r['marker_time_matches_raw']for r in value['rows']))
        self.assertTrue(all(r['before_ciphertext_within_workflow_budget']for r in value['rows']))

    def test_corrupt_completed_marker_reports_proof_guard(self):
        self.f.control('recover_first');self.f.control('mutate_marker');value=self.run_diagnostic('a'*64)
        self.assertEqual(value['first_failed_check'],'PROCESSED_HYDRATION_PROOF')

    def test_exact_pin_failure_stops_before_composer_bootstrap(self):
        self.f.pins[next(iter(self.f.pins))]='a'*40
        value=self.run_diagnostic('a'*64);self.assertEqual(value['first_failed_check'],'SOURCE_PIN')
        self.assertEqual(value['rows'],[]);self.assertEqual(value['classes'],[])

    def test_alternate_autoloaded_proof_class_refuses_before_projected_or_transition(self):
        real=self.f.root/fixtures.PREFIX/'WhatsAppNativePlaceholderHydration.php'
        self.f.write('alternate-hydration.php',real.read_bytes())
        boot=(self.f.root/'bootstrap/app.php').read_bytes()
        self.f.write('bootstrap/app.php',boot.replace(b'<?php',b"<?php require getcwd().'/alternate-hydration.php';",1))
        value=self.run_diagnostic('a'*64)
        self.assertEqual(value['first_failed_check'],'LOADED_CLASS_MISMATCH');self.assertEqual(value['rows'],[])
        self.assertTrue(value['classes'][0]['autoload_ready']);self.assertFalse(value['classes'][0]['loaded_source_matches'])


if __name__=='__main__':unittest.main()
