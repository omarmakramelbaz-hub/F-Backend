#!/usr/bin/env python3
"""Fresh original CI plus bounded workspace proof before original authorization.

Downloads use the original hash-verified GitHub client and its artifact digest
checks. No installer is published here. The artifact/proof schema stays absent
until the actual native lifecycle run emits its accepted proof.
"""
import copy
from pathlib import Path
import re
import stat
import time
import zipfile
from urllib.parse import quote

PINS = None
LIMIT = 128 * 1024
ARCHIVE_LIMIT = 1024 * 1024
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
WORKFLOWS = ('desktop-dashboard-tests.yml', 'go-erp-update-tests.yml', 'whatsapp-integrated-regressions.yml')
NATIVE_WORKFLOW = 'go-erp-shared-workspace-native.yml'
FOUNDATION_WORKFLOW = 'go-erp-shared-workspace-tests.yml'
FOUNDATION_JOBS = ('authority-and-financial-regressions (sqlite)',
    'authority-and-financial-regressions (mysql)', 'windows-native-regressions', 'actual-private-print-ledger')
ARTIFACT = 'shared-workspace-native-windows'
MEMBER = 'workspace-runtime-native.json'
VERIFIER = '.github/scripts/verify-workspace-runtime-native.cjs'
TRUE_FIELDS = ('actualElectron','actualEncryptedMetadata','actualPhpMariaDB','productionFactory','syntheticPublication',
    'onePreparation','zeroOfflineHttp','samePhysicalDatabase','aPendingVisibleToB','bWritesKeepOriginalActor',
    'globalSequenceAThenB','actualProtectedView','cachedRememberedCloseReopen','explicitLogoutRequiresPassword',
    'noHeavyLoginWork','retiredLegacyPreserved','lostAcceptedReplyRetriedSameUuid','noChildrenAfterStop',
    'fixedCliOpcodeDisabled','fixedCliNotAttached','persistentWebOpcodeEnabled','completed')
SAFE = 9007199254740991
PROGRAMS = frozenset(('desktop/workspace-archive.php','desktop/workspace-control.php',
    'desktop/workspace-delta.php','desktop/workspace-import.php','desktop/workspace-verify.php'))


class Stop(Exception): pass
class Pending(Stop): pass
def need(value, code):
    if not value: raise Stop('GO_ERP_SHARED_WORKSPACE_ACCEPTANCE_' + code)


class WorkspaceAcceptance:
    def __init__(self, io, context, audit_parent, verify_locks, *, pins=None,
            clock=time.monotonic, pause=time.sleep, timeout=7200):
        self.io,self.context,self.audit_parent,self.verify_locks=io,context,Path(audit_parent),verify_locks
        self.pins=PINS if pins is None else copy.deepcopy(pins)
        self.clock,self.pause,self.timeout=clock,pause,timeout

    def _approved(self):
        p=self.pins
        need(isinstance(p,dict) and set(p)=={'contract_sha256','program_hashes',
            'workflow_sha256','foundation_workflow_sha256','verifier_sha256','source_hashes','electron_version'},'FINAL_PROOF_PINS_UNAVAILABLE')
        need(isinstance(p['contract_sha256'],str) and SHA.fullmatch(p['contract_sha256'])
            and isinstance(p['program_hashes'],dict) and set(p['program_hashes'])==PROGRAMS
            and all(isinstance(value,str) and SHA.fullmatch(value) for value in p['program_hashes'].values())
            and isinstance(p['workflow_sha256'],str) and SHA.fullmatch(p['workflow_sha256'])
            and isinstance(p['foundation_workflow_sha256'],str) and SHA.fullmatch(p['foundation_workflow_sha256'])
            and isinstance(p['verifier_sha256'],str) and SHA.fullmatch(p['verifier_sha256'])
            and isinstance(p['source_hashes'],dict) and p['source_hashes']
            and all(isinstance(name,str) and re.fullmatch(r'[A-Za-z0-9_./-]+',name)
                and '..' not in name.split('/') and isinstance(value,str) and SHA.fullmatch(value)
                for name,value in p['source_hashes'].items())
            and isinstance(p['electron_version'],str) and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+',p['electron_version']),
            'PROOF_CONTRACT_INVALID')
        need(callable(self.verify_locks) and self.verify_locks() is not False,'LOCK_CHANGED')

    def _validate(self, value, candidate, version):
        p=self.pins
        exact=lambda a,b:self.io.canonical(a)==self.io.canonical(b)
        integer=lambda item,minimum=0,maximum=SAFE:type(item) is int and minimum<=item<=maximum
        uuid=lambda item:isinstance(item,str) and re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}',item)
        fingerprint=self.context.job['source']
        need(isinstance(value,dict) and type(value.get('format')) is int and value['format']==2
            and value.get('kind')=='go-erp-shared-workspace-native' and value.get('sourceRevision')==candidate
            and value.get('electronVersion')==p['electron_version'] and exact(value.get('sourceHashes'),p['source_hashes'])
            and all(value.get(name) is True for name in TRUE_FIELDS)
            and value.get('productionMain') is False and value.get('productionProfile') is False
            and uuid(value.get('physicalWorkspaceId')) and isinstance(value.get('generation'),str)
            and re.fullmatch(r'[a-f0-9]{16}',value['generation'])
            and value.get('database')=='fasakhansta_dashboard_stage_'+value['generation']
            and integer(value.get('sourceSequence'),1,1) and exact(value.get('sourceFingerprint'),fingerprint)
            and integer(value.get('certificateChecks'),1) and exact(value.get('firstEverOfflineActors'),[11,1,10])
            and integer(value.get('loginStageCalls'),0,0)
            and isinstance(value.get('cachedLoginMilliseconds'),list) and len(value['cachedLoginMilliseconds'])==3
            and all(integer(item,0,44999) for item in value['cachedLoginMilliseconds'])
            and isinstance(value.get('setupTimer'),dict) and value['setupTimer'].get('running') is False
            and integer(value['setupTimer'].get('elapsed_ms'),1)
            and isinstance(value.get('originalCommandIds'),list) and len(value['originalCommandIds'])==2
            and all(uuid(item) for item in value['originalCommandIds'])
            and value['originalCommandIds'][0]!=value['originalCommandIds'][1]
            and exact(value.get('commandAttempts'),[value['originalCommandIds'][0],value['originalCommandIds'][0],value['originalCommandIds'][1]])
            and isinstance(value.get('nativePids'),list) and value['nativePids']
            and all(integer(item,1) for item in value['nativePids']) and len(set(value['nativePids']))==len(value['nativePids']),
            'INSTALLED_WORKSPACE_LIFECYCLE_PROOF_INVALID')
        server=value.get('serverInspection')
        need(isinstance(server,dict) and integer(server.get('format'),2,2)
            and server.get('kind')=='go-erp-workspace-native-server-inspection' and server.get('sourceRevision')==candidate
            and exact(server.get('sourceFingerprint'),fingerprint) and integer(server.get('workspaceCount'),1,1)
            and integer(server.get('custodianActorId'),10,10) and isinstance(server.get('workspaces'),list)
            and len(server['workspaces'])==1 and server['workspaces'][0].get('workspace_id')==value['physicalWorkspaceId']
            and isinstance(server['workspaces'][0].get('root_fingerprint'),str)
            and SHA.fullmatch(server['workspaces'][0]['root_fingerprint'])
            and isinstance(server.get('commands'),list) and len(server['commands'])==2
            and exact([[row.get('command_id'),row.get('actor_id'),row.get('route_name'),row.get('status')]
                for row in server['commands']],[[value['originalCommandIds'][0],10,'customers.save','pending'],
                    [value['originalCommandIds'][1],11,'customers.save','pending']])
            and all(integer(row.get('sequence'),1) for row in server['commands'])
            and server['commands'][0]['sequence']<server['commands'][1]['sequence']
            and isinstance(server.get('customers'),list) and len(server['customers'])==2
            and exact([[row.get('phone_key'),row.get('actor_id'),row.get('branch')] for row in server['customers']],
                [['01012345671',10,'f:100'],['01012345672',11,'f:100']]), 'ORIGINAL_ACTOR_AND_SHARED_DATABASE_PROOF_INVALID')
        counts=value.get('requestCounts')
        need(isinstance(counts,dict) and integer(counts.get('offlineRequests'),0,0)
            and integer(counts.get('totalRequests'),1) and integer(counts.get('publicationRequests'),1), 'NATIVE_REQUEST_PROOF_INVALID')

    def __call__(self, candidate, version, contract):
        deadline=self.clock()+self.timeout
        while True:
            try:return self._collect(candidate,version,contract)
            except Pending:
                need(self.clock()<deadline,'SAME_CANDIDATE_CI_WAIT_EXPIRED')
                need(self.verify_locks() is not False,'LOCK_CHANGED')
                print('GO_ERP_SHARED_WORKSPACE_WAITING_SAME_CANDIDATE_CI',flush=True)
                self.pause(min(30,max(0,deadline-self.clock())))

    def _workflow(self, client, worker, name, candidate, required_jobs):
        endpoint='/repos/'+worker.REPOSITORY+'/actions/workflows/'+name+'/runs?branch='+quote(worker.BRANCH,safe='')+'&event=push&head_sha='+candidate
        rows=client.pages(endpoint,'workflow_runs')
        matches=[row for row in rows if row.get('head_sha')==candidate and row.get('event')=='push' and row.get('head_branch')==worker.BRANCH]
        if not matches:raise Pending('GO_ERP_SHARED_WORKSPACE_ACCEPTANCE_SAME_CANDIDATE_CI_PENDING')
        run=max(matches,key=lambda row:(row.get('id',0),row.get('run_attempt',0)))
        worker.run_identity(run,name,candidate)
        if run.get('status')!='completed':raise Pending('GO_ERP_SHARED_WORKSPACE_ACCEPTANCE_SAME_CANDIDATE_CI_PENDING')
        need(run.get('conclusion')=='success','LATEST_NATIVE_CI_SUCCESS_REQUIRED' if name==NATIVE_WORKFLOW
            else 'LATEST_FOUNDATION_CI_SUCCESS_REQUIRED')
        jobs=client.pages('/repos/'+worker.REPOSITORY+'/actions/runs/'+str(run['id'])+'/attempts/'+str(run['run_attempt'])+'/jobs','jobs')
        for expected in required_jobs:
            selected=[job for job in jobs if job.get('name')==expected]
            need(len(selected)==1 and selected[0].get('status')=='completed' and selected[0].get('conclusion')=='success'
                and selected[0].get('run_id')==run['id'] and selected[0].get('run_attempt')==run['run_attempt']
                and selected[0].get('head_sha')==candidate,'NATIVE_JOB_ACCEPTANCE_INCOMPLETE')
        return run

    def _collect(self, candidate, version, contract):
        self._approved()
        need(isinstance(candidate,str) and REV.fullmatch(candidate) and isinstance(version,str)
            and re.fullmatch(r'0\.4\.4-preview\.[1-9][0-9]{0,14}',version)
            and contract==self.pins['contract_sha256'],'CANDIDATE_OR_CONTRACT_CHANGED')
        context,worker=self.context,self.context.worker
        state=context.state(); desired=state.get('desired') or {}
        need(desired.get('commit')==candidate==state.get('last_remote_sha') and desired.get('version')==version
            and desired.get('pushed') is True,'DESIRED_CANDIDATE_CHANGED')
        context.live(state);context.candidate(state)
        release=context.release
        need(self.io.sha(release.file('.github/workflows/'+NATIVE_WORKFLOW,candidate))
            ==self.pins['workflow_sha256'],'WORKFLOW_SOURCE_PIN_CHANGED')
        need(self.io.sha(release.file('.github/workflows/'+FOUNDATION_WORKFLOW,candidate))
            ==self.pins['foundation_workflow_sha256'],'FOUNDATION_WORKFLOW_SOURCE_PIN_CHANGED')
        need(self.io.sha(release.file(VERIFIER,candidate))==self.pins['verifier_sha256'], 'PROOF_VERIFIER_SOURCE_CHANGED')
        for name,expected in self.pins['source_hashes'].items():
            need(self.io.sha(release.file(name,candidate))==expected,'NATIVE_PROOF_SOURCE_HASH_CHANGED')
        contract_raw=release.file('desktop/workspace-contract.json',candidate)
        need(self.io.sha(contract_raw)==contract and self.io.parse(contract_raw).get('programs')==self.pins['program_hashes'],
            'PACKAGED_PROGRAM_CONTRACT_CHANGED')
        config=worker.read_config(); client=worker.GitHub(config['token']); runs={}
        for name in WORKFLOWS:
            run=worker.accepted_run(client,name,candidate)
            if run is None:raise Pending('GO_ERP_SHARED_WORKSPACE_ACCEPTANCE_ORIGINAL_CI_PENDING')
            need(isinstance(run,dict) and type(run.get('id')) is int and run['id']>0
                and type(run.get('run_attempt')) is int and run['run_attempt']>0,'LATEST_ORIGINAL_CI_REQUIRED')
            runs[name]=run
        runs[FOUNDATION_WORKFLOW]=self._workflow(client,worker,FOUNDATION_WORKFLOW,candidate,FOUNDATION_JOBS)
        run=self._workflow(client,worker,NATIVE_WORKFLOW,candidate,('actual-shared-workspace',))
        runs[NATIVE_WORKFLOW]=run
        rows=client.pages('/repos/'+worker.REPOSITORY+'/actions/runs/'+str(run['id'])+'/artifacts','artifacts')
        matches=[row for row in rows if row.get('name')==ARTIFACT]
        need(len(matches)==1,'WORKSPACE_ARTIFACT_AMBIGUOUS_OR_MISSING')
        artifact=matches[0]; origin=artifact.get('workflow_run') or {}; digest=artifact.get('digest','')
        need(type(artifact.get('id')) is int and artifact['id']>0
            and type(artifact.get('size_in_bytes')) is int and 22<=artifact['size_in_bytes']<=ARCHIVE_LIMIT
            and artifact.get('expired') is False and isinstance(digest,str) and digest.startswith('sha256:')
            and SHA.fullmatch(digest[7:]) and origin.get('id')==run['id']
            and origin.get('repository_id')==origin.get('head_repository_id')==1311412084
            and origin.get('head_sha')==candidate and origin.get('head_branch')==worker.BRANCH,
            'WORKSPACE_ARTIFACT_IDENTITY_INVALID')
        folder=self.audit_parent/('workspace-acceptance-'+candidate+'-'+str(run['id'])+'-'+str(run['run_attempt']))
        self.io.private_directory(folder,self.verify_locks)
        archive=folder/'artifact.zip'
        existing=self.io.read(archive,0,0,optional=True,private=True,limit=ARCHIVE_LIMIT)
        if existing is None:
            client.download(artifact['id'],archive,digest[7:],artifact['size_in_bytes'],lambda: self.verify_locks())
        raw,info=self.io.read(archive,0,0,private=True,limit=ARCHIVE_LIMIT)
        need(len(raw)==artifact['size_in_bytes'] and self.io.sha(raw)==digest[7:],'ARTIFACT_BYTES_CHANGED')
        with zipfile.ZipFile(archive) as bundle:
            members=bundle.infolist()
            need(1<=len(members)<=2 and len({row.filename for row in members})==len(members)
                and {row.filename for row in members}<={MEMBER,'workspace-native-diagnostic.json'}
                and any(row.filename==MEMBER for row in members),'WORKSPACE_PROOF_ZIP_ROSTER_INVALID')
            for row in members:
                need((row.external_attr>>16)&0o170000 in (0,stat.S_IFREG)
                    and row.compress_type in (zipfile.ZIP_STORED,zipfile.ZIP_DEFLATED)
                    and not row.flag_bits&1 and 0<row.file_size<=LIMIT,'WORKSPACE_PROOF_ZIP_INVALID')
            member=next(row for row in members if row.filename==MEMBER);mode=(member.external_attr>>16)&0o170000
            need(member.filename==MEMBER and mode in (0,stat.S_IFREG)
                and member.compress_type in (zipfile.ZIP_STORED,zipfile.ZIP_DEFLATED)
                and not member.flag_bits&1 and 0<member.file_size<=LIMIT
                and member.compress_size<=ARCHIVE_LIMIT,'WORKSPACE_PROOF_ZIP_INVALID')
            proof_raw=bundle.read(member)
        need(self.io.identity(info)==self.io.identity(archive.lstat()),'ARTIFACT_CHANGED_DURING_READ')
        proof=self.io.parse(proof_raw);self._validate(proof,candidate,version)
        self.verify_locks();context.live(context.state());context.candidate(context.state())
        completed=self.context.ready(self.context.state()) is True
        record={'candidate_revision':candidate,'version':version,'contract_sha256':contract,
            'proof_sha256':self.io.sha(proof_raw),'artifact_sha256':digest[7:],
            'runs':{name:{'id':row['id'],'attempt':row['run_attempt']} for name,row in runs.items()},
            'latest_required_ci_accepted':True,'full_native_lifecycle_accepted':True,
            'installed_asar_accepted':completed,'original_program_gate_accepted':completed,'employee_acceptance_accepted':completed}
        # Original program/employee authorization is still called after this
        # additional prerequisite; this record never replaces those receipts.
        self.io.private_record(folder/'workspace-proof.json',proof_raw,self.verify_locks)
        self.io.private_record(folder/('accepted-'+self.io.sha(self.io.canonical(record))+'.json'),record,self.verify_locks)
        return record
