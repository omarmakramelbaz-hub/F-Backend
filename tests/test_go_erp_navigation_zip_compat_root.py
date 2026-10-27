"""One existing installer artifact gains one source-bound installed-ASAR proof."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import types
import unittest
import zipfile

import test_go_erp_navigation_actual as actual

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('navigation_zip_compat',
    ROOT / 'deployment/go_erp_navigation_zip_compat_root.py')
subject = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(subject)


class ExistingArtifactCompatibilityTests(unittest.TestCase):
    def setUp(self):
        self.worker, self.source, self.real_publisher, self.tick = actual.final_modules()
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-existing-artifact-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.folder = self.root / 'files'; self.folder.mkdir(mode=0o700)
        self.run = {'id':subject.RUN_ID,'run_attempt':1,'event':'push',
            'head_branch':'codex/go-erp-live-source-20261021','head_sha':subject.CANDIDATE,
            'pull_requests':[],'path':'.github/workflows/desktop-dashboard-tests.yml',
            'repository':{'id':1311412084,'full_name':'omarmakramelbaz-hub/F-Backend'},
            'head_repository':{'id':1311412084,'full_name':'omarmakramelbaz-hub/F-Backend'}}
        self.desired = {'commit':subject.CANDIDATE,'version':subject.VERSION,
            'inventorySha256':subject.INVENTORY}
        self.artifact = {'id':subject.ARTIFACT_ID,'name':'fasakhansta-dashboard-installer-preview',
            'size_in_bytes':subject.ARTIFACT_SIZE,'digest':subject.ARTIFACT_DIGEST,'expired':False,
            'workflow_run':{'id':subject.RUN_ID,'repository_id':1311412084,
                'head_repository_id':1311412084,'head_branch':'codex/go-erp-live-source-20261021',
                'head_sha':subject.CANDIDATE}}
        self.api_calls = []
        self.client = types.SimpleNamespace(pages=self.pages)
        self.proof = {'format':1,'kind':'go-erp-startup-source-native',
            'sourceRevision':subject.CANDIDATE,'installedAsarCode':True,
            'sourceHashes':dict(subject.SOURCE_HASHES),
            **{name:True for name in subject.TRUE_FLAGS},
            **{name:False for name in subject.FALSE_FLAGS}}

    def pages(self, endpoint, name):
        self.api_calls.append((endpoint,name))
        self.assertEqual(name,'artifacts')
        return [self.artifact]

    def write(self, name, value):
        path = self.folder / name
        path.parent.mkdir(parents=True,mode=0o700,exist_ok=True)
        path.write_bytes(value if isinstance(value,bytes) else json.dumps(value).encode())
        path.chmod(0o600)
        return path

    def original_proof_fixture(self):
        """Reach the original verifier using its real JSON/hash/native checks."""
        installer = {'sha256':hashlib.sha256(b'installer fixture').hexdigest(),'bytes':17}
        self.release = {'version':subject.VERSION,'source_revision':subject.CANDIDATE,
            'sequence':subject.RUN_ID,'source':{'files':1,'sha256':'a'*64},'installer':installer}
        receipt_bytes = json.dumps({'installer':installer}).encode()
        self.write('go-erp-update-release.json',receipt_bytes)
        package_files = {'application/reviewed.php':'b'*64}
        self.write('go-erp-live-source-acceptance.json',{
            'format':1,'kind':'go-erp-live-source-acceptance','repository':'omarmakramelbaz-hub/F-Backend',
            'branch':'codex/go-erp-live-source-20261021','revision':subject.CANDIDATE,
            'run_id':subject.RUN_ID,'run_attempt':1,'version':subject.VERSION,
            'candidate_inventory_sha256':subject.INVENTORY,
            'full_package_sha256':self.worker.digest(package_files),
            'update_receipt_sha256':hashlib.sha256(receipt_bytes).hexdigest(),'installer':installer})
        self.write('runtime-package.json',{'sourceRevision':subject.CANDIDATE,'version':subject.VERSION,
            'sourceFingerprint':self.release['source'],'files':package_files})
        self.write('installer-checks.json',{'sourceRevision':subject.CANDIDATE,'version':subject.VERSION,
            'installer':installer,**{name:True for name in ('installation','installedNativeExecutables',
                'installedDashboardRecovery','packagedLedger','uninstallRetention')},
            'automaticUpgrade':{name:True for name in ('waitedForExit','preservedJournal',
                'preservedUnrelatedFiles','registrationRetained','restartedInstalledExecutable')}})
        common = {'format':1,'sourceRevision':subject.CANDIDATE,'platform':'win32'}
        self.write('connected-request-native.json',{**common,'hardware':False,
            **{name:True for name in ('completed','actualElectronSessionFetch','actualWebRequest','actualDpapi')}})
        self.write('outbox-review-native.json',{**common,
            **{name:True for name in ('completed','actualDpapiAudit','durableIntentBeforeRetry','unchangedCommandId')}})
        self.write('offline-print-native.json',{**common,'hardware':False,'invocations':7,'accepted':6,
            'review':1,**{name:True for name in ('realPreloadIpc','refreshFence','encryptedRestartRecovery')}})
        self.write('GO-ERP-Preview-Setup.exe',b'installer fixture')
        self.write(subject.PROOF,self.proof)
        self.installer_calls = []
        self.publisher = types.SimpleNamespace(validate_receipt=lambda receipt:self.release,
            verify_installer=lambda *args,**kwargs:self.installer_calls.append((args,kwargs)))

    def archive(self, changes=None, extra=None):
        path = self.root / 'artifact.zip'
        with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED) as bundle:
            for name in (*subject.ORIGINAL_FILES,subject.PROOF):
                raw = b'fixture'
                target = name
                info = None
                if changes and name in changes:
                    target,raw,info = changes[name]
                bundle.writestr(info or target,raw)
            if extra:
                bundle.writestr(*extra)
        return path

    def test_install_extends_only_roster_and_two_bound_entry_points(self):
        unchanged = {name:getattr(self.worker,name) for name in
            ('extract_artifact','GitHub','program_gate_collect','program_gate_authorize')}
        methods = {name:getattr(self.worker.Worker,name) for name in ('prepare','tick','fetch_accepted','save')}
        original_files = dict(self.worker.FILES)
        self.assertIs(subject.install(self.worker),self.worker)
        self.assertEqual(self.worker.FILES,{**original_files,subject.PROOF:65536})
        self.assertEqual(subject.ORIGINAL_FILES,original_files)
        self.assertTrue(all(getattr(self.worker,name) is value for name,value in unchanged.items()))
        self.assertTrue(all(getattr(self.worker.Worker,name) is value for name,value in methods.items()))
        with self.assertRaisesRegex(self.worker.WatchError,'ORIGINAL_ROSTER_CHANGED'):
            subject.install(self.worker)

    def test_unknown_original_cohort_or_roster_cannot_be_modified(self):
        self.worker.PREVIEW31_SEED = 'a'*40
        with self.assertRaisesRegex(self.worker.WatchError,'COHORT_CHANGED'):
            subject.install(self.worker)
        self.worker.PREVIEW31_SEED = subject.SEED
        self.worker.FILES['unreviewed.json'] = 1024
        with self.assertRaisesRegex(self.worker.WatchError,'ORIGINAL_ROSTER_CHANGED'):
            subject.install(self.worker)
        del self.worker.FILES['unreviewed.json']
        self.worker.FILES['installer-checks.json']=float(subject.ORIGINAL_FILES['installer-checks.json'])
        with self.assertRaisesRegex(self.worker.WatchError,'ORIGINAL_ROSTER_CHANGED'):
            subject.install(self.worker)

    def test_old_frozen_extractor_rejects_new_receipt_then_same_extractor_accepts_exact_roster(self):
        archive = self.archive()
        first = self.root/'old';first.mkdir()
        with self.assertRaisesRegex(self.worker.WatchError,'ZIP_FILE_INVALID'):
            self.worker.extract_artifact(archive,first)
        original = self.worker.extract_artifact
        subject.install(self.worker)
        second = self.root/'reviewed';second.mkdir()
        self.worker.extract_artifact(archive,second)
        self.assertIs(self.worker.extract_artifact,original)
        self.assertEqual({str(p.relative_to(second)) for p in second.rglob('*') if p.is_file()},
            set(subject.ORIGINAL_FILES)|{subject.PROOF})

    def test_original_extractor_still_rejects_unknown_extra_symlink_path_and_oversize(self):
        subject.install(self.worker)
        symlink = zipfile.ZipInfo(subject.PROOF)
        symlink.create_system = 3;symlink.external_attr = (stat.S_IFLNK|0o777)<<16
        cases = [({},('unreviewed-extra.json',b'x')),
            ({subject.PROOF:('../escape.json',b'x',None)},None),
            ({subject.PROOF:(subject.PROOF,b'outside',symlink)},None),
            ({subject.PROOF:(subject.PROOF,b'x'*(subject.PROOF_LIMIT+1),None)},None)]
        for index,(changes,extra) in enumerate(cases):
            destination = self.root/str(index);destination.mkdir()
            with self.subTest(index=index),self.assertRaises(self.worker.WatchError):
                self.worker.extract_artifact(self.archive(changes,extra),destination)
        self.assertFalse((self.root/'escape.json').exists())

    def test_same_reviewed_artifact_identity_and_original_api_provenance_are_required(self):
        subject.install(self.worker)
        self.assertIs(self.worker.accepted_artifact(self.client,self.run,subject.CANDIDATE),self.artifact)
        self.assertEqual(len(self.api_calls),1)
        for name,value in [('id',subject.ARTIFACT_ID+1),('size_in_bytes',subject.ARTIFACT_SIZE+1),
                ('digest','sha256:'+'0'*64)]:
            original = self.artifact[name];self.artifact[name]=value
            with self.subTest(name=name),self.assertRaisesRegex(self.worker.WatchError,'ARTIFACT_CHANGED'):
                self.worker.accepted_artifact(self.client,self.run,subject.CANDIDATE)
            self.artifact[name]=original
        self.artifact['expired']=True
        with self.assertRaisesRegex(self.worker.WatchError,'CI_ARTIFACT_IDENTITY_INVALID'):
            self.worker.accepted_artifact(self.client,self.run,subject.CANDIDATE)

    def test_run_attempt_candidate_and_workflow_changes_cannot_inherit_artifact(self):
        subject.install(self.worker)
        for name,value in [('run_attempt',2),('event','workflow_dispatch'),
                ('head_sha','a'*40),('path','.github/workflows/other.yml')]:
            run={**self.run,name:value}
            with self.subTest(name=name),self.assertRaises(self.worker.WatchError):
                self.worker.accepted_artifact(self.client,run,subject.CANDIDATE)
        with self.assertRaises(self.worker.WatchError):
            self.worker.accepted_artifact(self.client,self.run,'a'*40)

    def test_actual_original_verification_then_strict_installed_proof_accepts_minimal_parent_run(self):
        self.original_proof_fixture();subject.install(self.worker)
        parent_run={'id':subject.RUN_ID,'run_attempt':1}
        receipt,release=self.worker.verify_proof(self.folder,parent_run,self.desired,self.publisher)
        self.assertEqual(release,self.release)
        self.assertEqual(receipt['installer'],self.release['installer'])
        self.assertEqual(len(self.installer_calls),1)
        self.assertTrue(self.installer_calls[0][1]['root_owned'])

    def test_missing_extra_or_changed_installed_proof_fields_hashes_and_boolean_types_fail(self):
        self.original_proof_fixture();subject.install(self.worker)
        cases=[]
        for field in subject.TRUE_FLAGS:
            value=copy.deepcopy(self.proof);value[field]=False;cases.append(value)
        for field in subject.FALSE_FLAGS:
            value=copy.deepcopy(self.proof);value[field]=True;cases.append(value)
        for field,value in [('format',True),('sourceRevision','a'*40),('installedAsarCode',False),
                ('kind','other'),('completed',1),('actualHttpsPublication',0)]:
            row=copy.deepcopy(self.proof);row[field]=value;cases.append(row)
        missing=copy.deepcopy(self.proof);del missing['completed'];cases.append(missing)
        extra={**self.proof,'unreviewed':True};cases.append(extra)
        changed=copy.deepcopy(self.proof);changed['sourceHashes']['helper']='0'*64;cases.append(changed)
        changed=copy.deepcopy(self.proof);changed['sourceHashes']['other']='0'*64;cases.append(changed)
        for index,value in enumerate(cases):
            self.write(subject.PROOF,value)
            before=len(self.installer_calls)
            with self.subTest(index=index),self.assertRaisesRegex(self.worker.WatchError,'INSTALLED_STARTUP_PROOF_INVALID'):
                self.worker.verify_proof(self.folder,self.run,self.desired,self.publisher)
            self.assertEqual(len(self.installer_calls),before+1)
        (self.folder/subject.PROOF).unlink()
        with self.assertRaises(FileNotFoundError):
            self.worker.verify_proof(self.folder,self.run,self.desired,self.publisher)

    def test_duplicate_receipt_keys_and_oversize_cannot_pass_original_json_file_guards(self):
        self.original_proof_fixture();subject.install(self.worker)
        raw=json.dumps(self.proof).encode().replace(b'"format": 1',b'"format": 1,"format": 1')
        self.write(subject.PROOF,raw)
        with self.assertRaisesRegex(self.worker.WatchError,'DUPLICATE_JSON_FIELD'):
            self.worker.verify_proof(self.folder,self.run,self.desired,self.publisher)
        self.write(subject.PROOF,b'x'*(subject.PROOF_LIMIT+1))
        with self.assertRaisesRegex(self.worker.WatchError,'FILE_UNSAFE'):
            self.worker.verify_proof(self.folder,self.run,self.desired,self.publisher)

    def test_original_installer_native_and_source_guards_execute_before_extra_receipt(self):
        self.original_proof_fixture();subject.install(self.worker)
        (self.folder/subject.PROOF).unlink()
        self.write('connected-request-native.json',{'format':1,'sourceRevision':subject.CANDIDATE,
            'platform':'win32','hardware':False,'completed':False})
        with self.assertRaisesRegex(self.worker.WatchError,'CI_NATIVE_PROOF_INCOMPLETE'):
            self.worker.verify_proof(self.folder,self.run,self.desired,self.publisher)
        self.assertEqual(self.installer_calls,[])

    def test_original_receipt_success_does_not_authorize_other_candidate_inventory_or_run(self):
        self.original_proof_fixture()
        original = self.worker.verify_proof
        calls=[]
        def reviewed(*args):
            calls.append(args)
            return original(*args)
        self.worker.verify_proof=reviewed
        subject.install(self.worker)
        # A different inventory is normally rejected by the original live-proof
        # comparison. Keep those reviewed fixtures internally coherent so the
        # additional fixed-candidate guard is the one that refuses the change.
        changed={**self.desired,'inventorySha256':'c'*64}
        live=json.loads((self.folder/'go-erp-live-source-acceptance.json').read_bytes())
        live['candidate_inventory_sha256']=changed['inventorySha256']
        self.write('go-erp-live-source-acceptance.json',live)
        with self.assertRaisesRegex(self.worker.WatchError,'CANDIDATE_CHANGED'):
            self.worker.verify_proof(self.folder,self.run,changed,self.publisher)
        self.assertEqual(len(calls),1)

    def test_fixed_candidate_version_run_and_release_bindings_follow_original_verification(self):
        self.original_proof_fixture()
        calls=[]
        def original_boundary(*args):
            calls.append(args)
            return {'installer':self.release['installer']},self.release
        self.worker.verify_proof=original_boundary
        subject.install(self.worker)
        for field,value in [('commit','a'*40),('version','0.4.4-preview.60'),('inventorySha256','b'*64)]:
            before=len(calls)
            with self.subTest(field=field),self.assertRaisesRegex(self.worker.WatchError,'CANDIDATE_CHANGED'):
                self.worker.verify_proof(self.folder,self.run,{**self.desired,field:value},self.publisher)
            self.assertEqual(len(calls),before+1)
        for field,value in [('id',subject.RUN_ID+1),('run_attempt',2),('run_attempt',True)]:
            before=len(calls)
            with self.subTest(field=field),self.assertRaisesRegex(self.worker.WatchError,'RUN_CHANGED'):
                self.worker.verify_proof(self.folder,{**self.run,field:value},self.desired,self.publisher)
            self.assertEqual(len(calls),before+1)
        original=copy.deepcopy(self.release)
        for field,value in [('version','0.4.4-preview.60'),('source_revision','a'*40),('sequence',subject.RUN_ID+1)]:
            self.release={**original,field:value}
            with self.subTest(field=field),self.assertRaisesRegex(self.worker.WatchError,'RELEASE_CHANGED'):
                self.worker.verify_proof(self.folder,self.run,self.desired,self.publisher)


if __name__ == '__main__':
    unittest.main()
