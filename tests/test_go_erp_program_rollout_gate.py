"""Synthetic acceptance records, real private Git/worker/source/publisher boundary.

These are protocol tests. They neither execute Windows nor manufacture a real
release acceptance receipt. Production commands only consume external evidence.
"""
import copy
import hashlib
import io
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
import zipfile
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
M=module('program_gate_adapter_tests',ROOT/'deployment/upgrade_go_erp_program_watcher_root.py')
G=module('program_gate_tests',ROOT/'deployment/go_erp_program_rollout_gate.py')
F=module('program_gate_real_worker_fixture',ROOT/'tests/test_go_erp_live_updates.py')
raw={name:(ROOT/'deployment/wire-hash-prior/payload'/name).read_bytes() for name in M.RAW_PINS}
with patch.object(M,'NEW_SEED','e'*40):adapted=M.adapt_payloads(raw)
def loaded(name,data,file):
    value=types.ModuleType(name);value.__file__=str(file);sys.modules[name]=value;exec(compile(data,str(file),'exec'),value.__dict__);return value
W=loaded('program_gate_actual_adapted_worker',adapted['go_erp_live_updates.py'],ROOT/'deployment/wire-hash-prior/payload/go_erp_live_updates.py')
S=loaded('program_gate_actual_adapted_source',adapted['go_erp_live_source.py'],ROOT/'deployment/wire-hash-prior/payload/go_erp_live_source.py')
F.worker=W;F.source=S


def employee(expected,desired):
    value={'kind':'original-employee-identity-browser','sourceRevision':desired['commit'],'fixtureRevision':desired['commit'],
        'sourceFingerprint':expected['source'],'helperBinding':'exact-git-revision','sourceBinding':'runtime-manifest','schemaInventory':106,'checks':45,
        **{key:True for key in ('completed','privateDatabasesDropped','httpStopped','browserStopped','sourceVerifiedAfter','helpersVerifiedAfter',
            'verifiedImport','privateMediaHttpVerified','consentJournalVerified','consentReplayVerified','duplicateReplayVerified','staleQueuedConsentRejected')},
        'fullDashboard':False,'providersReplaced':False,'domInjection':False,'browserProof':{}}
    for name,checks in [('central',('realUpload','immutableABA','staleConsentRejected','firstAcceptanceDatePreserved','withdrawal','branchReadOnly','outOfScopeDenied','regulationRendered')),
                        ('local',('privatePhotos','onlineUploadExplanation','consentJournal'))]:
        value['browserProof'][name]={'browser':'Windows Edge','browserPlatform':'win32','sourceCommit':desired['commit'],
            'phase':name,'domInjection':False,'providersReplaced':False,'newPageErrors':0,'newEmployeeConsoleErrors':0,
            'privatePublicUrlDenied':True,'listingRedacted':True,'imagesDecoded':True,'displayedPhotoSourcesBound':True,'externalRequestsBlocked':0,
            'fixtureRevision':desired['commit'],**{key:True for key in checks}}
    return value


@unittest.skipUnless(os.geteuid()==0,'actual root-owned publication boundary')
class WorkerHoldTests(unittest.TestCase):
    setUp=F.RootWorkerTests.setUp
    write=F.RootWorkerTests.write
    seed=F.RootWorkerTests.seed
    make_worker=F.RootWorkerTests.make_worker
    receipt=F.RootWorkerTests.receipt
    archive=F.RootWorkerTests.archive
    proof_files=F.RootWorkerTests.proof_files
    def prepared(self):
        value=self.make_worker()
        expected=G.program_gate_expected('c'*40,F.publisher.source_snapshot(self.live)['fingerprint'],W.source_identity(value.scan()))
        G.program_gate_prepare(self.work,expected)
        self.assertEqual(value.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
        return value,expected
    def fetch(self,value,desired,values,employee_changes=None,employee_copies=1):
        archive=self.archive(values);raw=archive.read_bytes()
        proof=json.loads(values['go-erp-live-source-acceptance.json']);accepted=F.run(revision=desired['commit'],run_id=proof['run_id'])
        artifact={'id':12,'digest':'sha256:'+F.sha(raw),'size_in_bytes':len(raw)}
        url='https://api.github.com/repos/'+W.REPOSITORY+'/actions/artifacts/12/zip'
        responses=[F.Response(raw,url)]
        hold_path=self.work/'program-rollout-hold.json'
        if hold_path.exists():
            hold=G.program_gate_json(hold_path);expected={key:hold[key] for key in ('core','source','inventory_sha256')}
            stream=io.BytesIO()
            with zipfile.ZipFile(stream,'w') as bundle:bundle.writestr('receipt.json',json.dumps(employee(expected,desired)))
            extra=stream.getvalue()
            employee_artifact={'id':13,'name':'original-employee-identity-windows','size_in_bytes':len(extra),'digest':'sha256:'+F.sha(extra),'expired':False,
                'workflow_run':{'id':accepted['id'],'repository_id':W.REPOSITORY_ID,'head_repository_id':W.REPOSITORY_ID,'head_sha':desired['commit'],'head_branch':W.BRANCH}}
            employee_artifact.update(employee_changes or {})
            value.client.pages=lambda path,key:[employee_artifact]*employee_copies
            responses.append(F.Response(extra,'https://api.github.com/repos/'+W.REPOSITORY+'/actions/artifacts/13/zip'))
        value.client.download=W.GitHub('synthetic-token',F.Opener(responses)).download
        with patch.object(W,'accepted_run',side_effect=lambda client,workflow,revision:accepted),patch.object(W,'accepted_artifact',return_value=artifact):
            return value.fetch_accepted(desired)
    def evidence(self,value,expected):
        desired=value.state['desired'];values=self.proof_files(desired)
        ci=self.base/'ci';ci.mkdir(mode=0o700)
        for name,data in values.items():self.write(name,data,ci,0o600)
        evidence=self.write('employee.json',employee(expected,desired),self.base,0o600)
        release=F.publisher.validate_receipt(json.loads(values['go-erp-update-release.json']))
        receipt={'format':1,'kind':'go-erp-program-acceptance',**expected,'candidate_revision':desired['commit'],
            'version':desired['version'],'installer':release['installer'],'ci':{'directory':str(ci),'run_id':500,'run_attempt':1},
            'employee_evidence':{'path':str(evidence),'sha256':F.sha(evidence.read_bytes())}}
        path=self.write('acceptance.json',receipt,self.base,0o600)
        return path,receipt,values
    def authorize(self,value,expected,path):
        return G.program_gate_authorize(self.work,path,expected,value.state,
            lambda folder,run,desired:W.verify_proof(folder,run,desired,F.publisher),self.publication)
    def publication(self):
        try:return F.publisher.validate_release(F.publisher.read_json(self.live/'storage/app/desktop-updates/current.json',root_owned=True))
        except FileNotFoundError:return None
    def test_hold_preserves_exact_desired_candidate_after_accepted_ci_and_restarts(self):
        value,expected=self.prepared();desired=copy.deepcopy(value.state['desired']);counter=value.state['counter']
        self.assertEqual(self.fetch(value,value.state['desired'],self.proof_files(desired)),'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED')
        self.assertFalse((self.live/'storage/app/desktop-updates/current.json').exists())
        self.assertEqual(value.state['desired'],desired);self.assertEqual(value.state['counter'],counter)
        envelope=G.program_gate_json(self.work/'pending-program-acceptance.json')
        self.assertEqual(envelope['candidate_revision'],desired['commit']);self.assertEqual(envelope['inventory_sha256'],desired['inventorySha256'])
        self.assertTrue(Path(envelope['ci']['directory']).joinpath('GO-ERP-Preview-Setup.exe').exists())
        self.assertIsNone(G.program_gate_json(self.work/'program-rollout-hold.json')['authorization'])
        authorized=self.authorize(value,expected,self.work/'pending-program-acceptance.json')
        self.assertEqual(authorized['authorization']['candidate'],desired['commit'])
        # The next real tick reuses A; neither a new commit nor a version is minted.
        self.assertEqual(value.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(value.state['desired']['commit'],desired['commit']);self.assertEqual(value.state['counter'],counter)
        self.assertEqual(len(self.pushes),1)
    def test_only_authorized_A_publishes_then_future_updates_are_automatic(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected);desired=value.state['desired']
        self.authorize(value,expected,path)
        other=copy.deepcopy(desired);other['commit']='f'*40
        release=F.publisher.validate_receipt(json.loads(values['go-erp-update-release.json']))
        self.assertFalse(G.program_gate_allows(self.work,other,release))
        self.assertEqual(self.fetch(value,desired,values),'GO_ERP_LIVE_UPDATE_PUBLISHED')
        self.assertFalse((self.work/'program-rollout-hold.json').exists())
        self.assertTrue((self.work/('program-rollout-completed-'+expected['core']+'.json')).exists())
        self.assertEqual(value.tick(),'GO_ERP_LIVE_UPDATE_CURRENT')
        self.write('public/css/site.css',b'body {color: green;}\n')
        value.client.json=lambda path,missing=False:{'object':{'sha':desired['commit']}}
        self.assertEqual(value.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
        future=value.state['desired'];self.assertNotEqual(future['commit'],desired['commit'])
        self.assertEqual(self.fetch(value,future,self.proof_files(future,sequence=501)),'GO_ERP_LIVE_UPDATE_PUBLISHED')
    def test_receipt_mismatch_missing_windows_checks_and_changed_evidence_fail_closed(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected)
        for field,bad in [('core','d'*40),('candidate_revision','f'*40),('inventory_sha256','d'*64),('installer',{'sha256':'f'*64,'bytes':1})]:
            wrong=copy.deepcopy(receipt);wrong[field]=bad;path.write_text(json.dumps(wrong))
            with self.subTest(field=field),self.assertRaises(G.ProgramGateError):self.authorize(value,expected,path)
        path.write_text(json.dumps(receipt));evidence=Path(receipt['employee_evidence']['path'])
        wrong=employee(expected,value.state['desired']);wrong['browserProof']['central']['realUpload']=False;evidence.write_text(json.dumps(wrong))
        with self.assertRaises(G.ProgramGateError):self.authorize(value,expected,path)
        self.assertIsNone(G.program_gate_json(self.work/'program-rollout-hold.json')['authorization'])
    def test_private_file_mode_symlink_and_changed_authorized_evidence_are_refused(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected)
        path.chmod(0o644)
        with self.assertRaises(G.ProgramGateError):self.authorize(value,expected,path)
        path.chmod(0o600);self.authorize(value,expected,path)
        Path(receipt['employee_evidence']['path']).write_bytes(b'changed synthetic evidence')
        release=F.publisher.validate_receipt(json.loads(values['go-erp-update-release.json']))
        with self.assertRaises(G.ProgramGateError):G.program_gate_allows(self.work,value.state['desired'],release)
        self.assertTrue((self.work/'program-rollout-hold.json').exists())
    def test_linux_working_tree_evidence_and_boolean_error_counts_never_certify_windows(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected)
        evidence=Path(receipt['employee_evidence']['path'])
        for fault in ['local-source','local-helper','wrong-platform','boolean-count','queued-consent','schema-count']:
            wrong=employee(expected,value.state['desired'])
            if fault=='local-source':wrong['sourceBinding']='local-working-tree-fingerprint'
            elif fault=='local-helper':wrong['helperBinding']='local-working-tree'
            elif fault=='wrong-platform':wrong['browserProof']['central']['browserPlatform']='linux'
            elif fault=='boolean-count':wrong['browserProof']['local']['newPageErrors']=False
            elif fault=='queued-consent':wrong['staleQueuedConsentRejected']=False
            else:wrong['schemaInventory']=True
            evidence.write_text(json.dumps(wrong));receipt['employee_evidence']['sha256']=F.sha(evidence.read_bytes());path.write_text(json.dumps(receipt))
            with self.subTest(fault=fault),self.assertRaises(G.ProgramGateError):self.authorize(value,expected,path)
        self.assertIsNone(G.program_gate_json(self.work/'program-rollout-hold.json')['authorization'])
    def test_canonical_feed_alone_cannot_retire_the_hold(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected);self.authorize(value,expected,path)
        release=F.publisher.validate_receipt(json.loads(values['go-erp-update-release.json']))
        self.write('storage/app/desktop-updates/current.json',release)
        self.assertFalse(G.program_gate_retire(self.work,self.live,F.publisher))
        self.assertTrue((self.work/'program-rollout-hold.json').exists())
    def test_confirmation_recovers_after_publication_and_refuses_a_different_canonical_feed(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected);self.authorize(value,expected,path)
        with patch.object(W,'program_gate_retire',return_value=False):
            self.assertEqual(self.fetch(value,value.state['desired'],values),'GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW')
        release=F.publisher.read_json(self.live/'storage/app/desktop-updates/current.json',root_owned=True)
        wrong=copy.deepcopy(release);wrong['source_revision']='f'*40
        self.write('storage/app/desktop-updates/current.json',wrong)
        self.assertFalse(G.program_gate_retire(self.work,self.live,F.publisher))
        self.assertTrue((self.work/'program-rollout-hold.json').exists())
        self.write('storage/app/desktop-updates/current.json',release)
        self.assertEqual(value.tick(),'GO_ERP_LIVE_UPDATE_CURRENT')
        self.assertFalse((self.work/'program-rollout-hold.json').exists())
    def test_employee_artifact_from_wrong_source_or_ambiguous_attempt_is_not_retained_as_acceptance(self):
        value,expected=self.prepared();desired=value.state['desired'];values=self.proof_files(desired)
        for changes,copies in [({'expired':True},1),({'workflow_run':{'head_sha':'f'*40}},1),({},2)]:
            with self.subTest(changes=changes,copies=copies),self.assertRaises(W.ProgramGateError):
                self.fetch(value,desired,values,changes,copies)
            self.assertFalse((self.work/'pending-program-acceptance.json').exists())
            self.assertTrue((self.work/'program-rollout-hold.json').exists())
            self.assertFalse((self.live/'storage/app/desktop-updates/current.json').exists())
    def test_explicit_revalidated_new_run_for_same_unpublished_A_replaces_authorization_without_new_version(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected);self.authorize(value,expected,path)
        desired=copy.deepcopy(value.state['desired']);counter=value.state['counter']
        rerun=self.proof_files(desired,sequence=501)
        self.assertEqual(self.fetch(value,value.state['desired'],rerun),'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED')
        pending=self.work/'pending-program-acceptance.json';authorization=self.authorize(value,expected,pending)
        self.assertEqual(authorization['authorization']['release']['sequence'],501)
        self.assertEqual(value.state['counter'],counter);self.assertEqual(value.state['desired']['commit'],desired['commit'])
        self.assertTrue(list((self.work/'program-rollout-authorizations').glob('replaced-*.json')))
        self.assertEqual(self.fetch(value,value.state['desired'],rerun),'GO_ERP_LIVE_UPDATE_PUBLISHED')
    def test_new_run_cannot_replace_authorization_after_canonical_A_was_published(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected)
        first=self.authorize(value,expected,path)['authorization'];desired=copy.deepcopy(value.state['desired'])
        self.assertEqual(self.fetch(value,value.state['desired'],self.proof_files(desired,sequence=501)),'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED')
        with patch.object(self,'publication',return_value=first['release']):
            with self.assertRaisesRegex(G.ProgramGateError,'PUBLISHED_AUTHORIZATION_IMMUTABLE'):
                self.authorize(value,expected,self.work/'pending-program-acceptance.json')
        self.assertEqual(G.program_gate_json(self.work/'program-rollout-hold.json')['authorization'],first)
    def test_early_retirement_before_new_source_scan_prevents_completed_A_from_stranding_B(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected);self.authorize(value,expected,path)
        old=value.state['desired']['commit']
        with patch.object(W,'program_gate_retire',return_value=False):
            self.assertEqual(self.fetch(value,value.state['desired'],values),'GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW')
        self.write('public/css/site.css',b'body {color: green;}\n');value.client.json=lambda path,missing=False:{'object':{'sha':old}}
        self.assertEqual(value.tick(),'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertFalse((self.work/'program-rollout-hold.json').exists())
        self.assertNotEqual(value.state['desired']['commit'],old)
    def test_completed_state_requires_retained_receipt_and_matching_current_durable_publication(self):
        value,expected=self.prepared();path,receipt,values=self.evidence(value,expected);self.authorize(value,expected,path)
        self.assertEqual(self.fetch(value,value.state['desired'],values),'GO_ERP_LIVE_UPDATE_PUBLISHED')
        self.assertTrue(G.program_gate_completed(self.work,expected,value.state,self.publication))
        self.assertEqual(G.program_gate_prepare(self.work,expected)['kind'],'go-erp-program-rollout-completed')
        current=self.live/'storage/app/desktop-updates/current.json';before=current.read_bytes();current.unlink()
        self.assertFalse(G.program_gate_completed(self.work,expected,value.state,self.publication))
        current.write_bytes(before);current.chmod(0o644)
        broken=copy.deepcopy(value.state);broken['published']['commit']='f'*40
        self.assertFalse(G.program_gate_completed(self.work,expected,broken,self.publication))
        completed=G.program_gate_json(self.work/('program-rollout-completed-'+expected['core']+'.json'))
        retained=Path(completed['authorization']['receipt_path']);retained.write_bytes(b'{}\n');retained.chmod(0o600)
        with self.assertRaisesRegex(G.ProgramGateError,'COMPLETION_EVIDENCE_CHANGED'):
            G.program_gate_completed(self.work,expected,value.state,self.publication)


if __name__=='__main__':unittest.main()
