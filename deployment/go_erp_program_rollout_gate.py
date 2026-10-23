"""One-rollout publication hold; copied verbatim into the pinned watcher payload.

Candidate creation and CI remain available. Only the first reviewed candidate
is held for browser/Windows acceptance. A durable canonical publication proof
retires the hold; later automatic updates use the normal mandatory CI policy.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import tempfile
import zipfile


class ProgramGateError(Exception):
    pass


def program_gate_need(value, reason):
    if not value:
        raise ProgramGateError('GO_ERP_PROGRAM_GATE_' + reason)


def program_gate_identity(item):
    return item.st_dev,item.st_ino,item.st_mode,item.st_uid,item.st_gid,item.st_nlink,item.st_size,item.st_mtime_ns,item.st_ctime_ns


def program_gate_directory(path):
    path=Path(path).absolute()
    for parent in (path,*path.parents):
        item=parent.lstat()
        program_gate_need(stat.S_ISDIR(item.st_mode) and item.st_uid==0
            and (not item.st_mode&0o022 or parent!=path and bool(item.st_mode&stat.S_ISVTX)),'DIRECTORY_UNSAFE')
    program_gate_need(stat.S_IMODE(path.lstat().st_mode)==0o700,'DIRECTORY_NOT_PRIVATE')
    return path


def program_gate_bytes(path,limit=2*1024*1024):
    path=Path(path).absolute(); program_gate_directory(path.parent)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before=os.fstat(fd)
        program_gate_need(stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,stat.S_IMODE(before.st_mode))==(0,0,1,0o600)
            and 0<before.st_size<=limit,'FILE_UNSAFE')
        result=bytearray()
        while len(result)<=limit:
            part=os.read(fd,min(65536,limit+1-len(result)))
            if not part:break
            result.extend(part)
        program_gate_need(len(result)==before.st_size and program_gate_identity(before)==program_gate_identity(os.fstat(fd))==program_gate_identity(path.lstat()),'FILE_CHANGED')
        return bytes(result)
    finally:os.close(fd)


def program_gate_parse(raw):
    def unique(pairs):
        value={}
        for key,item in pairs:
            program_gate_need(key not in value,'DUPLICATE_FIELD');value[key]=item
        return value
    return json.loads(raw,object_pairs_hook=unique)


def program_gate_json(path):
    return program_gate_parse(program_gate_bytes(path))


def program_gate_write(path,value):
    path=Path(path);program_gate_directory(path.parent)
    fd,name=tempfile.mkstemp(prefix='.program-gate-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb',closefd=False) as output:
            output.write((json.dumps(value,sort_keys=True)+'\n').encode());output.flush();os.fsync(fd)
        os.replace(name,path)
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        os.close(fd)
        if os.path.exists(name):os.unlink(name)


def program_gate_expected(core,source,inventory):
    program_gate_need(isinstance(core,str) and re.fullmatch(r'[a-f0-9]{40}',core) and core!='0'*40
        and isinstance(source,dict) and set(source)=={'format','files','framework','sha256'}
        and source['format']==1 and type(source['files']) is int and source['files']>0
        and isinstance(source['sha256'],str) and re.fullmatch(r'[a-f0-9]{64}',source['sha256'])
        and isinstance(inventory,str) and re.fullmatch(r'[a-f0-9]{64}',inventory),'RELEASE_PINS_PENDING')
    return {'core':core,'source':source,'inventory_sha256':inventory}


def program_gate_prepare(work,expected):
    work=program_gate_directory(work);path=work/'program-rollout-hold.json'
    try:
        old=program_gate_json(path)
        program_gate_need(all(old.get(key)==value for key,value in expected.items()),'OTHER_ROLLOUT_PENDING')
        return old
    except FileNotFoundError:pass
    completed=work/('program-rollout-completed-'+expected['core']+'.json')
    try:
        old=program_gate_json(completed)
        program_gate_need(all(old.get(key)==value for key,value in expected.items()) and old.get('canonical_publication_verified') is True,'COMPLETION_UNREVIEWED')
        return old
    except FileNotFoundError:pass
    value={'format':1,'kind':'go-erp-program-rollout-hold',**expected,'authorization':None}
    program_gate_write(path,value);return value


def program_gate_employee_evidence(path,expected,desired):
    raw=program_gate_bytes(path);value=program_gate_parse(raw)
    program_gate_need(value.get('kind')=='original-employee-identity-browser'
        and value.get('sourceRevision')==desired['commit']==value.get('fixtureRevision')
        and value.get('sourceFingerprint')==expected['source'] and value.get('helperBinding')=='exact-git-revision'
        and value.get('sourceBinding')=='runtime-manifest' and type(value.get('schemaInventory')) is int and value['schemaInventory']==106
        and type(value.get('checks')) is int and value['checks']>=45
        and all(value.get(key) is True for key in ('completed','privateDatabasesDropped','httpStopped','browserStopped',
            'sourceVerifiedAfter','helpersVerifiedAfter','verifiedImport','privateMediaHttpVerified','consentJournalVerified','consentReplayVerified','duplicateReplayVerified','staleQueuedConsentRejected'))
        and all(value.get(key) is False for key in ('fullDashboard','providersReplaced','domInjection')),'EMPLOYEE_EVIDENCE_INCOMPLETE')
    proof=value.get('browserProof',{})
    for name,checks in [('central',('realUpload','immutableABA','staleConsentRejected','firstAcceptanceDatePreserved','withdrawal','branchReadOnly','outOfScopeDenied','regulationRendered')),
                        ('local',('privatePhotos','onlineUploadExplanation','consentJournal'))]:
        entry=proof.get(name,{})
        program_gate_need(entry.get('browser')=='Windows Edge' and entry.get('browserPlatform')=='win32'
            and entry.get('phase')==name and entry.get('domInjection') is False and entry.get('providersReplaced') is False
            and type(entry.get('newPageErrors')) is int and entry['newPageErrors']==0
            and type(entry.get('newEmployeeConsoleErrors')) is int and entry['newEmployeeConsoleErrors']==0
            and entry.get('privatePublicUrlDenied') is True and entry.get('listingRedacted') is True
            and entry.get('sourceCommit')==desired['commit']==entry.get('fixtureRevision')
            and all(entry.get(key) is True for key in checks),'BROWSER_EVIDENCE_INCOMPLETE')
        if name=='local':program_gate_need(entry.get('imagesDecoded') is True and entry.get('displayedPhotoSourcesBound') is True
            and type(entry.get('externalRequestsBlocked')) is int and entry['externalRequestsBlocked']==0,'LOCAL_EVIDENCE_INCOMPLETE')
    return hashlib.sha256(raw).hexdigest()


def program_gate_authorize(work,receipt_path,expected,state,verify_ci,read_publication=lambda:None):
    work=program_gate_directory(work);hold=program_gate_json(work/'program-rollout-hold.json')
    program_gate_need(all(hold.get(key)==value for key,value in expected.items()),'HOLD_IDENTITY_MISMATCH')
    raw=program_gate_bytes(receipt_path);receipt=program_gate_parse(raw)
    desired=state.get('desired')
    program_gate_need(isinstance(desired,dict) and desired.get('pushed') is True
        and desired.get('inventorySha256')==expected['inventory_sha256'] and desired.get('commit')==state.get('last_remote_sha')
        and desired.get('status') in ('waiting-ci','published'),'DESIRED_CANDIDATE_NOT_READY')
    program_gate_need(isinstance(receipt,dict) and set(receipt)=={'format','kind','core','source','inventory_sha256','candidate_revision','version','installer','ci','employee_evidence'}
        and receipt['format']==1 and receipt['kind']=='go-erp-program-acceptance'
        and all(receipt.get(key)==value for key,value in expected.items())
        and receipt['candidate_revision']==desired['commit'] and receipt['version']==desired['version'],'RECEIPT_BINDING_MISMATCH')
    ci=receipt['ci'];program_gate_need(isinstance(ci,dict) and set(ci)=={'directory','run_id','run_attempt'}
        and type(ci['run_id']) is int and ci['run_id']>0 and type(ci['run_attempt']) is int and ci['run_attempt']>0,'CI_REFERENCE_INVALID')
    directory=program_gate_directory(ci['directory'])
    # Reuse the installed watcher's full accepted Windows artifact verifier.
    # It checks native DPAPI/HTTP/printing and installer upgrade receipts. The
    # resumed worker independently authenticates CI provenance with GitHub.
    _,release=verify_ci(directory,{'id':ci['run_id'],'run_attempt':ci['run_attempt']},desired)
    program_gate_need(release['source_revision']==desired['commit'] and release['source']==expected['source']
        and release['version']==desired['version'] and release['installer']==receipt['installer']
        and (release['sequence']>state.get('sequence_floor',0) or (state.get('published') or {}).get('release')==release),'CI_RELEASE_MISMATCH')
    evidence=receipt['employee_evidence']
    program_gate_need(isinstance(evidence,dict) and set(evidence)=={'path','sha256'},'EVIDENCE_REFERENCE_INVALID')
    digest=program_gate_employee_evidence(evidence['path'],expected,desired)
    program_gate_need(digest==evidence['sha256'],'EVIDENCE_DIGEST_MISMATCH')
    receipt_sha=hashlib.sha256(raw).hexdigest()
    retained=work/'program-rollout-authorizations'
    try:retained.mkdir(mode=0o700)
    except FileExistsError:pass
    program_gate_directory(retained)
    immutable=retained/('receipt-'+receipt_sha+'.json')
    try:
        fd=os.open(immutable,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    except FileExistsError:program_gate_need(program_gate_bytes(immutable)==raw,'RETAINED_RECEIPT_CHANGED')
    else:
        try:
            with os.fdopen(fd,'wb',closefd=False) as output:output.write(raw);output.flush();os.fsync(fd)
        finally:os.close(fd)
    authorization={'candidate':desired['commit'],'version':desired['version'],'installer':release['installer'],
        'release':release,'receipt_path':str(immutable),'receipt_sha256':receipt_sha,
        'employee_evidence':evidence}
    if hold.get('authorization') is not None:
        previous=hold['authorization']
        if previous!=authorization:
            public=read_publication();published=state.get('published') or {}
            program_gate_need(previous.get('candidate')==desired['commit'] and desired['status']=='waiting-ci'
                and published.get('commit')!=desired['commit']
                and (public is None or public.get('source_revision')!=desired['commit']),'PUBLISHED_AUTHORIZATION_IMMUTABLE')
            program_gate_write(retained/('replaced-'+os.urandom(16).hex()+'.json'),previous)
    hold['authorization']=authorization;program_gate_write(work/'program-rollout-hold.json',hold)
    return hold


def program_gate_completed(work,expected,state,read_publication):
    try:value=program_gate_json(Path(work)/('program-rollout-completed-'+expected['core']+'.json'))
    except FileNotFoundError:return False
    program_gate_need(all(value.get(key)==item for key,item in expected.items())
        and value.get('canonical_publication_verified') is True,'COMPLETION_UNREVIEWED')
    auth=value.get('authorization')
    program_gate_need(isinstance(auth,dict) and auth.get('release')==value.get('release')
        and hashlib.sha256(program_gate_bytes(auth['receipt_path'])).hexdigest()==auth['receipt_sha256']
        and hashlib.sha256(program_gate_bytes(auth['employee_evidence']['path'])).hexdigest()==auth['employee_evidence']['sha256'],
        'COMPLETION_EVIDENCE_CHANGED')
    current=read_publication();published=state.get('published');desired=state.get('desired')
    return (isinstance(current,dict) and current.get('source')==expected['source']
        and isinstance(published,dict) and published.get('release')==current
        and isinstance(desired,dict) and published.get('commit')==desired.get('commit')==current.get('source_revision')
        and desired.get('status')=='published' and published.get('inventorySha256')==desired.get('inventorySha256')
        and current in state.get('published_history',[]) and state.get('version_floor')==current.get('version')
        and state.get('sequence_floor',0)>=current.get('sequence',1))


def program_gate_collect(worker,files,run,desired,release):
    """Retain actual accepted CI receipts; never invent a browser passed flag."""
    work=program_gate_directory(worker.work);hold=program_gate_json(work/'program-rollout-hold.json')
    program_gate_need(desired['inventorySha256']==hold['inventory_sha256'] and release['source']==hold['source'], 'HELD_SOURCE_SUPERSEDED')
    parent=work/'program-acceptance'
    try:parent.mkdir(mode=0o700)
    except FileExistsError:pass
    program_gate_directory(parent)
    folder=Path(tempfile.mkdtemp(prefix=desired['commit']+'-',dir=parent))
    repository='omarmakramelbaz-hub/F-Backend';branch='codex/go-erp-live-source-20261021'
    rows=worker.client.pages('/repos/'+repository+'/actions/runs/'+str(run['id'])+'/artifacts','artifacts')
    matching=[row for row in rows if row.get('name')=='original-employee-identity-windows']
    program_gate_need(len(matching)==1,'EMPLOYEE_ARTIFACT_MISSING_OR_AMBIGUOUS')
    artifact=matching[0];origin=artifact.get('workflow_run',{})
    program_gate_need(type(artifact.get('id')) is int and artifact['id']>0
        and type(artifact.get('size_in_bytes')) is int and 0<artifact['size_in_bytes']<=64*1024*1024
        and artifact.get('expired') is False and isinstance(artifact.get('digest'),str)
        and re.fullmatch(r'sha256:[a-f0-9]{64}',artifact['digest'])
        and origin.get('id')==run['id'] and origin.get('repository_id')==1311412084
        and origin.get('head_repository_id')==1311412084 and origin.get('head_sha')==desired['commit']
        and origin.get('head_branch')==branch,'EMPLOYEE_ARTIFACT_IDENTITY_INVALID')
    archive=folder/'employee-artifact.zip'
    worker.client.download(artifact['id'],archive,artifact['digest'][7:],artifact['size_in_bytes'],
        lambda:worker.guard_live(desired['inventorySha256']))
    with zipfile.ZipFile(archive) as bundle:
        entries=[entry for entry in bundle.infolist() if entry.filename=='receipt.json']
        program_gate_need(len(entries)==1,'EMPLOYEE_RECEIPT_MISSING_OR_AMBIGUOUS')
        entry=entries[0];kind=(entry.external_attr>>16)&0o170000
        program_gate_need(kind in (0,stat.S_IFREG) and not entry.flag_bits&1 and 0<entry.file_size<=2*1024*1024
            and entry.compress_type in (zipfile.ZIP_STORED,zipfile.ZIP_DEFLATED),'EMPLOYEE_RECEIPT_UNSAFE')
        data=bundle.read(entry);program_gate_need(len(data)==entry.file_size,'EMPLOYEE_RECEIPT_SIZE_INVALID')
    path=folder/'employee.json';fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb',closefd=False) as output:output.write(data);output.flush();os.fsync(fd)
    finally:os.close(fd)
    expected={key:hold[key] for key in ('core','source','inventory_sha256')}
    digest=program_gate_employee_evidence(path,expected,desired)
    os.rename(files,folder/'ci')
    envelope={'format':1,'kind':'go-erp-program-acceptance',**expected,'candidate_revision':desired['commit'],
        'version':desired['version'],'installer':release['installer'],
        'ci':{'directory':str(folder/'ci'),'run_id':run['id'],'run_attempt':run['run_attempt']},
        'employee_evidence':{'path':str(path),'sha256':digest}}
    program_gate_write(folder/'acceptance.json',envelope)
    program_gate_write(work/'pending-program-acceptance.json',envelope)
    # The hash-bound receipt is retained; the downloaded screenshot/log archive
    # is unnecessary for this publication decision and may contain large images.
    archive.unlink()
    return work/'pending-program-acceptance.json'


def program_gate_allows(work,desired,release):
    try:hold=program_gate_json(Path(work)/'program-rollout-hold.json')
    except FileNotFoundError:return True
    auth=hold.get('authorization')
    if not isinstance(auth,dict):return False
    if not (desired.get('commit')==auth.get('candidate') and desired.get('inventorySha256')==hold.get('inventory_sha256')
        and release==auth.get('release') and release.get('source')==hold.get('source')):return False
    program_gate_need(hashlib.sha256(program_gate_bytes(auth['receipt_path'])).hexdigest()==auth['receipt_sha256'],'AUTHORIZED_RECEIPT_CHANGED')
    program_gate_need(hashlib.sha256(program_gate_bytes(auth['employee_evidence']['path'])).hexdigest()==auth['employee_evidence']['sha256'],'AUTHORIZED_EVIDENCE_CHANGED')
    return True


def program_gate_retire(work,live,publisher):
    work=Path(work);path=work/'program-rollout-hold.json'
    try:hold=program_gate_json(path)
    except FileNotFoundError:return True
    auth=hold.get('authorization')
    if not isinstance(auth,dict):return False
    state=program_gate_json(work/'state/current.json');published=state.get('published')
    if not isinstance(published,dict):return False
    release=auth['release'];desired=state.get('desired')
    if not isinstance(desired,dict):return False
    if not (published.get('commit')==auth['candidate']==desired.get('commit')
        and published.get('inventorySha256')==hold['inventory_sha256']==desired.get('inventorySha256')
        and published.get('release')==release and desired.get('status')=='published'
        and release in state.get('published_history',[]) and state.get('sequence_floor',0)>=release['sequence']
        and state.get('version_floor')==release['version']):return False
    try:canonical=publisher.read_json(Path(live)/'storage/app/desktop-updates/current.json',root_owned=True)
    except FileNotFoundError:return False
    if canonical!=release:return False
    program_gate_need(program_gate_allows(work,desired,release),'PUBLICATION_AUTHORIZATION_CHANGED')
    completed={key:hold[key] for key in ('core','source','inventory_sha256')}
    completed.update(format=1,kind='go-erp-program-rollout-completed',release=release,authorization=auth,canonical_publication_verified=True)
    program_gate_write(work/('program-rollout-completed-'+hold['core']+'.json'),completed)
    os.unlink(path)
    fd=os.open(work,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:os.fsync(fd)
    finally:os.close(fd)
    return True
