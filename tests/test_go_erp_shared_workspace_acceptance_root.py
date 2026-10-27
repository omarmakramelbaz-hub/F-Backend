"""Native proof/GitHub boundary fixtures; no network, installer or server effects."""
import copy
import importlib.util
import io as memory
import os
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
io=module('acceptance_io','deployment/go_erp_shared_workspace_source_root.py')
subject=module('acceptance_subject','deployment/go_erp_shared_workspace_acceptance_root.py')
CANDIDATE='a'*40;VERSION='0.4.4-preview.61'

def proof(hashes,fingerprint):
    a,b='11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222'
    workspace='33333333-3333-4333-8333-333333333333'
    return {'format':2,'kind':'go-erp-shared-workspace-native','sourceRevision':CANDIDATE,
        'electronVersion':'42.0.0','sourceHashes':hashes,**{name:True for name in subject.TRUE_FIELDS},
        'productionMain':False,'productionProfile':False,'physicalWorkspaceId':workspace,
        'generation':'abcdef0123456789','database':'fasakhansta_dashboard_stage_abcdef0123456789',
        'sourceSequence':1,'sourceFingerprint':fingerprint,'certificateChecks':1,'firstEverOfflineActors':[11,1,10],
        'loginStageCalls':0,'cachedLoginMilliseconds':[2,3,4],'setupTimer':{'running':False,'elapsed_ms':10},
        'originalCommandIds':[a,b],'commandAttempts':[a,a,b],'nativePids':[100,101],
        'webOpcodeLaunches':[{'cacheId':'go-erp-web-'+str(index)*48,'enableCli':'1','fileCache':'',
            'fileCacheOnly':'0','pid':100+index%2} for index in range(4)],
        'serverInspection':{'format':2,'kind':'go-erp-workspace-native-server-inspection','sourceRevision':CANDIDATE,
            'sourceFingerprint':fingerprint,'workspaceCount':1,'custodianActorId':10,
            'workspaces':[{'workspace_id':workspace,'root_fingerprint':'f'*64}],
            'commands':[{'command_id':a,'actor_id':10,'route_name':'customers.save','status':'pending','sequence':1},
                {'command_id':b,'actor_id':11,'route_name':'customers.save','status':'pending','sequence':2}],
            'customers':[{'phone_key':'01012345671','actor_id':10,'branch':'f:100'},
                {'phone_key':'01012345672','actor_id':11,'branch':'f:100'}]},
        'requestCounts':{'offlineRequests':0,'totalRequests':5,'publicationRequests':1}}

@unittest.skipUnless(os.geteuid()==0,'Root-private downloaded proof fixtures require root runtime')
class AcceptanceTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='workspace-proof-private-');self.addCleanup(self.temp.cleanup)
        self.folder=Path(self.temp.name);self.completed=False;self.native_success=True;self.foundation_success=True
        self.pending=False;self.api_scopes=[]
        self.files={'.github/workflows/'+subject.NATIVE_WORKFLOW:b'pinned native workflow',
            '.github/workflows/'+subject.FOUNDATION_WORKFLOW:b'pinned foundation workflow',
            subject.VERIFIER:b'pinned actual native verifier','desktop-pos/src/workspace-native.cjs':b'actual native source fixture'}
        programs={name:'d'*64 for name in subject.PROGRAMS}
        contract=io.canonical({'format':2,'kind':'desktop-workspace-runtime-contract','protocol':2,
            'policy':'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1','programs':programs})
        self.files['desktop/workspace-contract.json']=contract
        hashes={'desktop-pos/src/workspace-native.cjs':io.sha(self.files['desktop-pos/src/workspace-native.cjs'])}
        self.fingerprint={'format':1,'files':1,'sha256':'b'*64,'framework':'8.83.29'}
        self.value=proof(hashes,self.fingerprint)
        self.pins={'contract_sha256':io.sha(contract),'program_hashes':programs,
            'workflow_sha256':io.sha(self.files['.github/workflows/'+subject.NATIVE_WORKFLOW]),
            'foundation_workflow_sha256':io.sha(self.files['.github/workflows/'+subject.FOUNDATION_WORKFLOW]),
            'verifier_sha256':io.sha(self.files[subject.VERIFIER]),'source_hashes':hashes,'electron_version':'42.0.0'}
        test=self
        class Client:
            def __init__(self,token): self.token=token
            def pages(self,path,key):
                test.api_scopes.append(path)
                foundation=subject.FOUNDATION_WORKFLOW in path or '/runs/45/' in path
                run={'id':44,'run_attempt':1,'head_sha':CANDIDATE,'head_branch':'codex/go-erp-live-source-20261021',
                    'event':'push','status':'completed','conclusion':'success' if test.native_success else 'failure'}
                if foundation:run.update(id=45,conclusion='success' if test.foundation_success else 'failure')
                if test.pending:run.update(status='in_progress',conclusion=None)
                if key=='workflow_runs': return [run]
                if key=='jobs':return [{'name':name,'status':'completed','conclusion':'success',
                    'run_id':run['id'],'run_attempt':1,'head_sha':CANDIDATE}
                    for name in (subject.FOUNDATION_JOBS if foundation else ('actual-shared-workspace',))]
                if key=='artifacts':
                    return [{'id':55,'name':subject.ARTIFACT,'size_in_bytes':len(test.archive()),'digest':'sha256:'+io.sha(test.archive()),
                        'expired':False,'workflow_run':{'id':44,'repository_id':1311412084,'head_repository_id':1311412084,
                            'head_sha':CANDIDATE,'head_branch':run['head_branch']}}]
                raise AssertionError('Unknown API scope')
            def download(self,artifact,path,digest,size,heartbeat):
                heartbeat();path.write_bytes(test.archive());path.chmod(0o600)
        class Worker:
            REPOSITORY='omarmakramelbaz-hub/F-Backend';BRANCH='codex/go-erp-live-source-20261021';GitHub=Client
            @staticmethod
            def read_config():return {'token':'private synthetic token'}
            @staticmethod
            def accepted_run(client,workflow,candidate):
                assert workflow in subject.WORKFLOWS and candidate==CANDIDATE
                return {'id':10+subject.WORKFLOWS.index(workflow),'run_attempt':1}
            @staticmethod
            def run_identity(run,workflow,candidate):
                assert workflow in (subject.NATIVE_WORKFLOW,subject.FOUNDATION_WORKFLOW) and run['head_sha']==candidate
        class Release:
            def file(self,name,revision):
                assert revision==CANDIDATE;return test.files[name]
        class Context:
            worker=Worker;release=Release();job={'source':test.fingerprint}
            def state(self):return {'last_remote_sha':CANDIDATE,'desired':{'commit':CANDIDATE,'version':VERSION,'pushed':True}}
            def live(self,state):pass
            def candidate(self,state):pass
            def ready(self,state):return test.completed
        self.ctx=Context()
        self.instance=subject.WorkspaceAcceptance(io,self.ctx,self.folder,lambda:None,pins=self.pins)

    def archive(self):
        output=memory.BytesIO()
        with zipfile.ZipFile(output,'w',zipfile.ZIP_STORED) as archive:
            # API digest and downloaded bytes model one immutable artifact;
            # wall-clock ZIP timestamps must not change between fixture calls.
            archive.writestr(zipfile.ZipInfo(subject.MEMBER,date_time=(2026,1,1,0,0,0)),io.canonical(self.value))
        return output.getvalue()

    def test_actual_receipt_scope_is_honest_and_original_gates_wait_for_original_completion(self):
        value=self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])
        self.assertTrue(value['full_native_lifecycle_accepted']);self.assertFalse(value['original_program_gate_accepted'])
        self.completed=True
        accepted=self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])
        self.assertTrue(accepted['original_program_gate_accepted']);self.assertTrue(accepted['employee_acceptance_accepted'])

    def test_each_actual_native_claim_missing_or_not_boolean_is_rejected(self):
        original=copy.deepcopy(self.value)
        for name in subject.TRUE_FIELDS:
            for bad in (False,1):
                self.value=copy.deepcopy(original);self.value[name]=bad
                with self.subTest(name=name,bad=bad),self.assertRaises(subject.Stop):self.instance._validate(self.value,CANDIDATE,VERSION)

    def test_each_restarted_web_launch_has_a_fresh_native_memory_namespace_without_shared_file_cache(self):
        mutations=(lambda v:v.pop('webOpcodeLaunches'),lambda v:v.update(webOpcodeLaunches=[]),
            lambda v:v.update(webOpcodeLaunches=v['webOpcodeLaunches'][:3]),
            lambda v:v['webOpcodeLaunches'].__setitem__(0,None),
            lambda v:v['webOpcodeLaunches'][0].update(pid=999),
            lambda v:v['webOpcodeLaunches'][0].update(pid=True),
            lambda v:v['webOpcodeLaunches'][0].update(cacheId='go-erp-web-short'),
            lambda v:v['webOpcodeLaunches'][0].update(cacheId='go-erp-web-'+'G'*48),
            lambda v:v['webOpcodeLaunches'][0].update(enableCli='0'),
            lambda v:v['webOpcodeLaunches'][0].update(enableCli=1),
            lambda v:v['webOpcodeLaunches'][0].update(fileCache='shared-serialized-cache'),
            lambda v:v['webOpcodeLaunches'][0].update(fileCacheOnly='1'),
            lambda v:v['webOpcodeLaunches'][0].update(unboundExtra=True))
        for index,mutate in enumerate(mutations):
            value=copy.deepcopy(self.value);mutate(value)
            with self.subTest(mutation=index),self.assertRaisesRegex(subject.Stop,'WEB_OPCODE_LAUNCH_PROOF_INVALID'):
                self.instance._validate(value,CANDIDATE,VERSION)
        value=copy.deepcopy(self.value)
        value['webOpcodeLaunches'][1]['cacheId']=value['webOpcodeLaunches'][0]['cacheId']
        with self.assertRaisesRegex(subject.Stop,'WEB_OPCODE_NAMESPACE_REUSED'):
            self.instance._validate(value,CANDIDATE,VERSION)

    def test_relabelled_production_main_wrong_actor_retry_sequence_or_offline_http_fails(self):
        mutations=(lambda v:v.update(productionMain=True),lambda v:v['serverInspection']['commands'][1].update(actor_id=10),
            lambda v:v.update(commandAttempts=list(reversed(v['commandAttempts']))),
            lambda v:v['requestCounts'].update(offlineRequests=1),lambda v:v.update(sourceRevision='b'*40),
            lambda v:v['serverInspection']['commands'][1].update(sequence=1),lambda v:v.update(firstEverOfflineActors=[10,11,1]))
        for mutate in mutations:
            value=copy.deepcopy(self.value);mutate(value)
            with self.assertRaises(subject.Stop):self.instance._validate(value,CANDIDATE,VERSION)

    def test_latest_failed_native_run_and_unknown_final_pins_cannot_accept(self):
        self.native_success=False
        with self.assertRaisesRegex(subject.Stop,'LATEST_NATIVE_CI_SUCCESS_REQUIRED'):
            self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])
        self.instance.pins=None
        with self.assertRaisesRegex(subject.Stop,'FINAL_PROOF_PINS_UNAVAILABLE'):
            self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])

    def test_foundation_is_fresh_for_the_captured_candidate_and_failure_cannot_reuse_core_ci(self):
        accepted=self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])
        self.assertEqual(5,len(accepted['runs']))
        self.assertEqual(45,accepted['runs'][subject.FOUNDATION_WORKFLOW]['id'])
        self.assertTrue(any(subject.FOUNDATION_WORKFLOW in path and 'head_sha='+CANDIDATE in path
            for path in self.api_scopes))
        self.foundation_success=False
        with self.assertRaisesRegex(subject.Stop,'LATEST_FOUNDATION_CI_SUCCESS_REQUIRED'):
            self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])

    def test_in_progress_extra_ci_polls_same_candidate_then_accepts_without_allocation(self):
        self.pending=True;now=[0];pauses=[]
        def pause(seconds):
            pauses.append(seconds);now[0]+=seconds;self.pending=False
        self.instance.clock=lambda:now[0];self.instance.pause=pause
        accepted=self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])
        self.assertEqual([30],pauses);self.assertTrue(accepted['latest_required_ci_accepted'])

    def test_missing_or_running_ci_has_a_bounded_wait(self):
        self.pending=True;self.instance.timeout=0
        with self.assertRaisesRegex(subject.Stop,'SAME_CANDIDATE_CI_WAIT_EXPIRED'):
            self.instance(CANDIDATE,VERSION,self.pins['contract_sha256'])

if __name__=='__main__':unittest.main()
