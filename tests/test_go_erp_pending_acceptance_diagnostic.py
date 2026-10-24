"""Acceptance diagnostics stay read only and expose only fixed safe fields."""
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('pending_diagnostic',ROOT/'deployment/diagnose_go_erp_pending_acceptance_root.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='pending-diagnostic-');self.addCleanup(temporary.cleanup)
        self.root=Path(temporary.name);self.work=self.root/'work';self.control=self.root/'control'
        for path in (self.work/'state',self.control/'private'):
            path.mkdir(mode=0o700,parents=True)
        self.work.chmod(0o700)
        for path in (self.control/'private/worker.lock',self.work/'watcher.lock'):
            path.write_bytes(b'');path.chmod(0o600)
        self.commit='26c6f8b6d80ab8da05b88a00789483a51a4c9a2a';self.core='42846d6715edef9c9de926fd010aae52cf410e92'
        self.folder=self.work/'program-acceptance'/(self.commit+'-abcdefgh')
        (self.folder/'ci').mkdir(mode=0o700,parents=True)
        self.folder.chmod(0o700)
        self.employee={'proof':'not printed','private_secret':'DO_NOT_PRINT_EMPLOYEE'}
        employee_raw=self.write(self.folder/'employee.json',self.employee)
        self.source={'format':1,'files':1355,'framework':'8.83.29','sha256':'a'*64}
        installer={'sha256':'b'*64,'bytes':123,'path':'/api/desktop-dashboard/update/installer/'+'b'*64}
        self.pending={'format':1,'kind':'go-erp-program-acceptance','core':self.core,
            'candidate_revision':self.commit,'version':'0.4.4-preview.42','source':self.source,
            'inventory_sha256':'c'*64,'installer':installer,
            'ci':{'directory':str(self.folder/'ci'),'run_id':40054814945,'run_attempt':1},
            'employee_evidence':{'path':str(self.folder/'employee.json'),
                'sha256':hashlib.sha256(employee_raw).hexdigest()}}
        pending_raw=self.write(self.work/'pending-program-acceptance.json',self.pending)
        self.write(self.folder/'acceptance.json',self.pending)
        self.digest=hashlib.sha256(pending_raw).hexdigest()
        receipt=self.work/'program-rollout-authorizations'/('receipt-'+self.digest+'.json')
        self.write(receipt,self.pending)
        self.release={'version':'0.4.4-preview.42','sequence':40054814945,'source_revision':self.commit,
            'source':self.source,'installer':installer,'gui':{}}
        self.completed={'format':1,'kind':'go-erp-program-rollout-completed','core':self.core,
            'source':self.source,'inventory_sha256':'c'*64,'release':self.release,
            'canonical_publication_verified':True,'authorization':{'candidate':self.commit,
                'version':'0.4.4-preview.42','release':self.release,'receipt_path':str(receipt),
                'receipt_sha256':self.digest,'employee_evidence':self.pending['employee_evidence']}}
        self.complete_path=self.work/('program-rollout-completed-'+self.core+'.json')
        self.write(self.complete_path,self.completed)
        self.state={'format':1,'counter':46,'desired':{'commit':subject.FAILED,'version':'0.4.4-preview.46'},
            'published':{'commit':self.commit,'release':self.release},'published_history':[self.release],
            'private_secret':'DO_NOT_PRINT_STATE'}
        self.write(self.work/'state/current.json',self.state)
        self.write(self.work/'program-rollout-hold.json',{'format':1,'kind':'go-erp-program-rollout-hold',
            'core':'6f5133af48ae03d626f0ac6ee02ff3a724c72407','source':self.source,
            'inventory_sha256':'d'*64,'authorization':None})
    def write(self,path,value):
        path.parent.mkdir(parents=True,mode=0o700,exist_ok=True)
        raw=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
        path.write_bytes(raw);path.chmod(0o600);return raw
    def inspect(self):return subject.diagnose(self.work,self.control)
    def test_historical42_is_distinguished_from_failed46_without_reading_or_printing_credentials(self):
        result=self.inspect();bindings=result['bindings']
        self.assertTrue(result['pending']['schema_valid']);self.assertEqual(result['pending']['version'],'0.4.4-preview.42')
        for key in ('pending_matches_published','retained_acceptance_equal','employee_digest_matches',
                'completion_binding_matches','completion_canonical_publication_verified','immutable_receipt_equal'):
            self.assertTrue(bindings[key],key)
        self.assertFalse(bindings['pending_is_failed46']);self.assertFalse(bindings['pending_matches_desired'])
        self.assertFalse(bindings['hold_matches_pending']);self.assertTrue(bindings['hold_authorization_is_null'])
        text=json.dumps(result)
        self.assertNotIn('DO_NOT_PRINT',text);self.assertNotIn(str(self.root),text)
        self.assertFalse(result['credentials_read']);self.assertFalse(result['network_accessed'])
    def test_all_existing_file_bytes_modes_and_mtimes_remain_unchanged(self):
        def snapshot():return {str(path): (path.read_bytes(),path.stat().st_mode,path.stat().st_mtime_ns)
            for path in self.root.rglob('*') if path.is_file()}
        before=snapshot();self.inspect();self.assertEqual(snapshot(),before)
    def test_absent_optional_receipts_are_reported_without_creating_directories_or_files(self):
        self.complete_path.unlink()
        (self.folder/'acceptance.json').unlink()
        result=self.inspect();self.assertFalse(result['bindings']['completion_present'])
        self.assertFalse(result['bindings']['immutable_receipt_scope_valid'])
        self.assertFalse(result['bindings']['retained_acceptance_equal'])
        self.assertFalse(self.complete_path.exists())
    def test_unknown_reference_paths_are_not_followed_and_never_printed(self):
        self.pending['ci']['directory']=str(self.root/'DO_NOT_PRINT_CI/ci')
        self.pending['employee_evidence']['path']=str(self.root/'DO_NOT_PRINT_EMPLOYEE.json')
        self.write(self.work/'pending-program-acceptance.json',self.pending)
        actual=os.open
        with patch.object(subject.os,'open',wraps=actual) as opened:result=self.inspect()
        self.assertFalse(any('DO_NOT_PRINT' in str(call.args[0]) for call in opened.call_args_list))
        self.assertFalse(result['bindings']['ci_reference_scope_valid'])
        self.assertFalse(result['bindings']['employee_reference_scope_valid'])
        self.assertNotIn('DO_NOT_PRINT',json.dumps(result))
    def test_missing_optional_reference_parents_preserve_pending_identity_without_creating_them(self):
        shutil.rmtree(self.folder)
        shutil.rmtree(self.work/'program-rollout-authorizations')
        result=self.inspect()
        self.assertEqual(result['pending']['version'],'0.4.4-preview.42')
        self.assertFalse(result['bindings']['retained_acceptance_equal'])
        self.assertFalse(result['bindings']['employee_digest_matches'])
        self.assertFalse(result['bindings']['immutable_receipt_equal'])
        self.assertFalse(self.folder.exists())
    def test_absent_hold_authorization_and_foreign_completed_format_are_not_treated_as_valid(self):
        self.write(self.work/'program-rollout-hold.json',{'format':1,'kind':'go-erp-program-rollout-hold'})
        self.completed['format']=True;self.write(self.complete_path,self.completed)
        result=self.inspect();self.assertFalse(result['bindings']['hold_authorization_is_null'])
        self.assertFalse(result['bindings']['completion_binding_matches'])
    def test_unknown_fields_and_malformed_safe_fields_cannot_leak_arbitrary_strings(self):
        self.pending.update(private_secret='DO_NOT_PRINT_UNKNOWN',version='DO_NOT_PRINT_VERSION',core='DO_NOT_PRINT_CORE')
        self.pending['source']['framework']='DO_NOT_PRINT_FRAMEWORK'
        self.write(self.work/'pending-program-acceptance.json',self.pending)
        result=self.inspect();self.assertFalse(result['pending']['schema_valid'])
        self.assertIsNone(result['pending']['version']);self.assertIsNone(result['pending']['core'])
        self.assertNotIn('DO_NOT_PRINT',json.dumps(result))
    def test_failed46_pending_is_reported_separately_from_any_completed_older_proof(self):
        self.pending.update(candidate_revision=subject.FAILED,version='0.4.4-preview.46')
        self.write(self.work/'pending-program-acceptance.json',self.pending)
        result=self.inspect();self.assertTrue(result['bindings']['pending_is_failed46'])
        self.assertTrue(result['bindings']['pending_matches_desired'])
        self.assertFalse(result['bindings']['completion_binding_matches'])
    def test_changed_evidence_or_retained_receipt_is_reported_without_authorizing_it(self):
        self.write(self.folder/'employee.json',{'tampered':True})
        self.write(self.folder/'acceptance.json',{'tampered':True})
        result=self.inspect();self.assertFalse(result['bindings']['employee_digest_matches'])
        self.assertFalse(result['bindings']['retained_acceptance_equal'])
    def test_unsafe_private_file_permissions_and_hardlinks_are_rejected(self):
        path=self.work/'pending-program-acceptance.json';path.chmod(0o644)
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.inspect()
        path.chmod(0o600);os.link(path,self.root/'pending-hardlink')
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.inspect()
    def test_private_parent_permissions_remain_strict_and_reference_strings_are_not_inferred(self):
        self.work.chmod(0o755)
        with self.assertRaisesRegex(subject.Stop,'DIRECTORY_UNSAFE'):self.inspect()
        self.work.chmod(0o700);self.pending['ci']['directory']=123
        self.pending['employee_evidence']['path']=456
        self.write(self.work/'pending-program-acceptance.json',self.pending)
        result=self.inspect();self.assertFalse(result['pending']['schema_valid'])
        self.assertFalse(result['bindings']['ci_reference_scope_valid'])
        self.assertFalse(result['bindings']['employee_reference_scope_valid'])
    def test_symlink_input_and_duplicate_json_keys_are_rejected(self):
        path=self.work/'pending-program-acceptance.json';saved=self.root/'saved.json';path.rename(saved);path.symlink_to(saved)
        with self.assertRaises(OSError):self.inspect()
        path.unlink();path.write_bytes(b'{"format":1,"format":1}');path.chmod(0o600)
        with self.assertRaisesRegex(subject.Stop,'DUPLICATE_JSON_FIELD'):self.inspect()
    def test_size_limit_and_nonfinite_json_are_rejected(self):
        path=self.work/'pending-program-acceptance.json';path.write_bytes(b' '*(2*1024**2+1))
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.inspect()
        path.write_bytes(b'{"value":NaN}')
        with self.assertRaisesRegex(subject.Stop,'JSON_NONFINITE'):self.inspect()
    def test_busy_existing_locks_fail_without_blocking_or_creating_replacements(self):
        path=self.control/'private/worker.lock';fd=os.open(path,os.O_RDONLY)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(subject.Stop,'RELEASE_PROCESS_BUSY'):self.inspect()
        finally:os.close(fd)
    def test_missing_lock_is_not_created_and_wrong_host_cannot_read_anything(self):
        path=self.control/'private/worker.lock';path.unlink()
        with self.assertRaises(FileNotFoundError):self.inspect()
        self.assertFalse(path.exists())
        with patch.object(subject.socket,'gethostname',return_value='another-host'),patch.object(subject,'diagnose') as read:
            with self.assertRaisesRegex(subject.Stop,'ROOT_HOST_REQUIRED'):subject.main()
            read.assert_not_called()


if __name__=='__main__':unittest.main()
