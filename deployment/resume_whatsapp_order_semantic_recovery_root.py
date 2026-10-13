#!/usr/bin/env python3
"""Forward-recover the fixed failed attempt and restore its original pipelines."""
import argparse
import ast
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import select
import signal
import stat
import subprocess
import sys
import tempfile
import time

ROOT=pathlib.Path('/home/fasakha/public_html')
OLD_BACKUP='order-semantic-ngb77kbf'
OLD_PIPELINE='semantic-pipeline-state-a_wh7l9m'
BASE_BLOB='f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
PUBLISHER_BLOB='ad881bc4529ce112890f1e73f1c323ab6664ea60'
OLD_ROOT_BLOB='be981e4cf3bda3e4d45aad591a8d0e5dace16f62'
DIAGNOSTIC_BLOB='47392c374f7fa48cc084eb834033d9ef651e95ed'
FACTORY_BLOB='68f2f8215a31b799df1f4d501110e89d39574618'
CAPTURE_GUARD_BLOB='1e598a799d302ab90a899a679b224c67e574dbd0'
CAPTURE_LEGACY_BLOB='c3571f9cf4ab37171c9d6cbf2fc8ddae9e067316'
HELPER_AFTER='89b98259217e02d300bc7aa5c70c7b58eb0dfbff'
PROTOCOL_AFTER='c64f8cf89437bb7822fc767202f7cf5112bfd7fd'
ACCESS_PATH='app/Services/Dashboard/WhatsAppInboxAccess.php'
ACCESS_BLOB='186789d42113e81818de8d82a2327e3ee9d8d0b5'
HELPER_PATH='app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'
PROTOCOL_PATH='app/Support/WhatsAppInboxProtocol.php'
UNITS=tuple('fasakhansta-whatsapp-'+v for v in ('capture.timer','capture.service','orders.timer','orders.service'))


class SafeError(Exception):pass


def blob(value):return hashlib.sha1(b'blob '+str(len(value)).encode()+b'\0'+value).hexdigest()


def metadata(path):
    s=path.lstat()
    return (s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)


def command(args,timeout=60):
    try:
        r=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=timeout)
        return (r.returncode,r.stdout) if len(r.stdout)<=1048576 else (1,b'')
    except (OSError,subprocess.TimeoutExpired):return 1,b''


def source(release,path,pin,runner=command):
    code,tree=runner(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'ls-tree','-z',release,path])
    if code or tree!=b'100644 blob '+pin.encode()+b'\t'+path.encode()+b'\0':raise SafeError('FORWARD_RELEASE_TREE_MISMATCH')
    code,data=runner(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'show',release+':'+path])
    if code or not data or blob(data)!=pin:raise SafeError('FORWARD_RELEASE_BLOB_MISMATCH')
    return data


def load_module(data,name,path):
    spec=importlib.util.spec_from_loader(name,loader=None);module=importlib.util.module_from_spec(spec)
    module.__file__=str(path);sys.modules[name]=module;exec(compile(data,str(path),'exec'),module.__dict__)
    return module


def write_new(path,data,uid=None,gid=None):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as s:
        if uid is not None:os.fchown(s.fileno(),uid,gid)
        s.write(data);s.flush();os.fsync(s.fileno())


def acquire_existing_locks(parent,uid):
    fds=[]
    try:
        for name in ('orders-install.lock','inbox-install.lock'):
            path=parent/name;fd=os.open(path,os.O_RDWR|os.O_NOFOLLOW|os.O_NONBLOCK);fds.append(fd)
            s=os.fstat(fd);p=path.lstat()
            if (not stat.S_ISREG(s.st_mode)or s.st_uid!=uid or s.st_nlink!=1 or stat.S_IMODE(s.st_mode)!=0o600
                    or (s.st_dev,s.st_ino)!=(p.st_dev,p.st_ino)):raise SafeError('FORWARD_LOCK_UNSAFE')
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        return fds
    except BaseException:
        for fd in fds:os.close(fd)
        raise SafeError('FORWARD_LOCKS_UNAVAILABLE')from None


def child_call(operation,uid,gid,close_fds=()):
    read,write=os.pipe();pid=os.fork();done=False
    if pid==0:
        os.close(read)
        try:
            for fd in close_fds:os.close(fd)
            os.setgroups([]);os.setgid(gid);os.setuid(uid);os.umask(0o077)
            result={'ok':True,'value':operation()}
        except BaseException as e:
            message=str(e);code=message if re.fullmatch('[A-Z0-9_]{1,100}',message)else'FORWARD_APP_PHASE_FAILED'
            result={'ok':False,'status':code}
            if type(getattr(e,'committed_hint',None))is bool:result['committed_hint']=e.committed_hint
        try:
            data=json.dumps(result,separators=(',',':')).encode()
            if len(data)>262144:raise ValueError()
            while data:
                n=os.write(write,data)
                if n<1:raise ValueError()
                data=data[n:]
            os.close(write);os._exit(0)
        except BaseException:os._exit(1)
    os.close(write)
    try:
        output=b'';deadline=time.monotonic()+180
        while time.monotonic()<deadline:
            ready,_,_=select.select([read],[],[],min(1,max(0,deadline-time.monotonic())))
            if not ready:continue
            data=os.read(read,65536)
            if not data:break
            output+=data
            if len(output)>262144:raise SafeError('FORWARD_CHILD_OUTPUT_LIMIT')
        else:raise SafeError('FORWARD_CHILD_TIMEOUT')
        _,status=os.waitpid(pid,0);done=True
        if status!=0:raise SafeError('FORWARD_CHILD_FAILED')
        value=json.loads(output)
        if not isinstance(value,dict)or type(value.get('ok'))is not bool:raise ValueError()
        if not value['ok']:
            error=SafeError(value.get('status','FORWARD_APP_PHASE_FAILED'))
            if type(value.get('committed_hint'))is bool:error.committed_hint=value['committed_hint']
            raise error
        return value['value']
    finally:
        os.close(read)
        if not done:
            try:os.kill(pid,signal.SIGTERM)
            except ProcessLookupError:pass
            until=time.monotonic()+10
            while time.monotonic()<until:
                finished,_=os.waitpid(pid,os.WNOHANG)
                if finished:done=True;break
                time.sleep(0.05)
            if not done:
                try:os.kill(pid,signal.SIGKILL)
                except ProcessLookupError:pass
                os.waitpid(pid,0)


def validate_original(diag,pub,parent,uid):
    raw,_=diag.private_file(parent/OLD_PIPELINE/'journal.jsonl',0,1048576,ancestry_uid=uid)
    entries=[json.loads(line)for line in raw.splitlines()if line]
    _,prior,quiet=diag.pipeline_summary(entries)
    if [e['phase']for e in entries]!=['PRIOR_PIPELINES','QUIESCENT','CODE_PUBLISHED','ORDERS_PAUSED_CAPTURE_ACTIVE']:
        raise SafeError('FORWARD_ORIGINAL_PIPELINE_PHASE_NEEDS_REVIEW')
    if quiet['recovery_pending']!=13:raise SafeError('FORWARD_ORIGINAL_PENDING_NEEDS_REVIEW')
    if prior['before'][UNITS[0]]['ActiveState']not in('inactive','failed')or prior['before'][UNITS[2]]['ActiveState']!='active':
        raise SafeError('FORWARD_ORIGINAL_TIMER_STATE_NEEDS_REVIEW')
    raw_receipt,_=diag.private_file(parent/OLD_BACKUP/'receipt.json',uid,1048576)
    receipt=json.loads(raw_receipt)
    if receipt.get('targets')!={p:v[1]for p,v in pub.SOURCE_PINS.items()}:raise SafeError('FORWARD_ORIGINAL_CODE_RECEIPT_NEEDS_REVIEW')
    raw_intent,_=diag.private_file(parent/OLD_BACKUP/'recovery-intent.json',uid,65536)
    intent=json.loads(raw_intent)
    if intent!={'event_ids':list(pub.RECOVERY_EVENT_IDS),'pending':13,'scope_hash':quiet['recovery_scope']}:
        raise SafeError('FORWARD_ORIGINAL_RECOVERY_INTENT_NEEDS_REVIEW')
    diag.private_file(parent/OLD_BACKUP/'recovery-before-images.enc',uid,33554432,read=False)
    complete,_=diag.private_file(parent/OLD_BACKUP/'recovery-complete.json',uid,65536,absent_ok=True)
    if complete is not None:raise SafeError('FORWARD_ORIGINAL_COMPLETION_NEEDS_REVIEW')
    if entries[2].get('backup')!=str(parent/OLD_BACKUP):raise SafeError('FORWARD_ORIGINAL_BACKUP_BINDING_NEEDS_REVIEW')
    identities=tuple((str(p),metadata(p),metadata(p.parent))for p in(
        parent/OLD_PIPELINE/'journal.jsonl',parent/OLD_BACKUP/'receipt.json',parent/OLD_BACKUP/'recovery-intent.json',
        parent/OLD_BACKUP/'recovery-before-images.enc'))
    return prior,quiet,raw,raw_receipt,raw_intent,identities


def app_guard(pub,root,uid,expected,pair,pair_bytes):
    pub.base.validate_root(root,uid)
    config=pub.configuration_snapshot(root,uid)
    if pub.configuration_fingerprint(config)!=expected:raise SafeError('FORWARD_CONFIGURATION_CHANGED')
    before={}
    expected_pins={**{p:v[1]for p,v in pub.SOURCE_PINS.items()},**pub.PROTECTED_PINS,ACCESS_PATH:ACCESS_BLOB}
    for path,target in expected_pins.items():
        value=pub.guarded_snapshot(root/path,uid,root);digest=blob(value[0].content)
        allowed=pair[path]if path in pair else(target,)
        if digest not in allowed:raise SafeError('FORWARD_LIVE_SOURCE_NEEDS_REVIEW')
        before[path]=value
    if any(blob(pair_bytes[p])!=v[1]for p,v in pair.items()):raise SafeError('FORWARD_CANDIDATE_PIN_MISMATCH')
    return config,before


def run_recovery(pub,factory,root,pins,backup,original_scope,old_backup,candidate,phase,runner=None):
    runner=pub.base.command if runner is None else runner
    program=factory.recovery_php(pub.RECOVERY_PHP)
    backup_arg=str(root)+'/../whatsapp-release-backups/'+backup.name
    old_arg=str(root)+'/../whatsapp-release-backups/'+old_backup.name
    args=[pub.PHP,'-r',program,phase,json.dumps(pins),json.dumps(list(pub.RECOVERY_EVENT_IDS)),backup_arg,
          original_scope,old_arg,str(candidate)if candidate else'']
    code,output=runner(root,args,capture=True)
    try:value=json.loads(output)
    except (ValueError,TypeError):raise SafeError('FORWARD_RECOVERY_OUTPUT_INVALID')from None
    if code or not isinstance(value,dict)or value.get('ai_calls')!=0 or value.get('orders_created')!=0:
        stages={'ARGUMENTS','CODE','CANDIDATE_CODE','BOOTSTRAP','ORIGINAL_BACKUP','ORIGINAL_SCOPE','STATUS_7215','BEFOREIMAGE_BUDGET',
                'TRANSITIONS','RESUME','TRANSACTION','JOURNAL','RECOVERY','POSTCHECK','COMPLETE'}
        stage=value.get('stage')if isinstance(value,dict)else None
        event=value.get('event_id')if isinstance(value,dict)else None
        reason='FORWARD_RECOVERY_'+stage if stage in stages else'FORWARD_RECOVERY_NEEDS_REVIEW'
        if type(event)is int and event in list(pub.RECOVERY_EVENT_IDS)+[7215]:reason+='_EVENT_'+str(event)
        error=SafeError(reason)
        if isinstance(value,dict)and type(value.get('committed'))is bool:error.committed_hint=value['committed']
        raise error
    return value


def preflight_ready(value):
    return (isinstance(value,dict)and value.get('status')in('SEMANTIC_FORWARD_PREFLIGHT_READY','SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE')
        and value.get('original_scope_valid')is True and value.get('transitions_verified')==13
        and value.get('status_7215_verified')is True and value.get('ai_calls')==0 and value.get('orders_created')==0)


def final_sources(pub,root,uid,config,expected,pair):
    pub.require_configuration(root,uid,config)
    for path,value in expected.items():
        current=pub.guarded_snapshot(root/path,uid,root)
        if current!=value:raise SafeError('FORWARD_POST_RECOVERY_SOURCE_CHANGED')
        if path in pair and blob(current[0].content)!=pair[path][1]:raise SafeError('FORWARD_POST_RECOVERY_PAIR_CHANGED')


def publish_pair(pub,root,uid,config,before,pair,pair_bytes,backup):
    pub.require_quiet(root,pub.base.command);pub.require_configuration(root,uid,config)
    for p,v in before.items():
        if pub.guarded_snapshot(root/p,uid,root)!=v:raise SafeError('FORWARD_SOURCE_CHANGED')
    if all(blob(before[p][0].content)==v[1]for p,v in pair.items()):return None
    publications=backup/'publications'
    try:publications.mkdir(mode=0o700)
    except FileExistsError:
        s=publications.lstat()
        if not stat.S_ISDIR(s.st_mode)or s.st_uid!=uid or stat.S_IMODE(s.st_mode)!=0o700:
            raise SafeError('FORWARD_PUBLICATION_DIRECTORY_UNSAFE')
    pub.base.probe_atomic_exchange(publications)
    written={};created=[]
    try:
        for path,(_,target)in pair.items():
            if blob(before[path][0].content)==target:continue
            pub.require_configuration(root,uid,config)
            pub.base.replace_file(root/path,pair_bytes[path],before[path][0],uid,root,created,
                journal=written,relative=path,private_directory=publications)
        pub.require_configuration(root,uid,config);pub.require_quiet(root,pub.base.command)
        for path,(_,target)in pair.items():
            if blob(pub.guarded_snapshot(root/path,uid,root)[0].content)!=target:raise SafeError('FORWARD_PUBLICATION_PIN_CHANGED')
    except BaseException as error:
        with pub.base.blocked_signals():
            for p in reversed(list(written.values())):
                try:
                    if not pub.base.recover_publication(p,root,uid):break
                except BaseException:break
        raise SafeError('FORWARD_PUBLICATION_NEEDS_REVIEW')from None


def validate_resume_names(backup,pipeline):
    if (backup is None)!=(pipeline is None):raise SafeError('FORWARD_RESUME_PAIR_REQUIRED')
    if backup is not None and(not re.fullmatch('order-semantic-forward-[A-Za-z0-9_-]{1,80}',backup)
            or not re.fullmatch('semantic-forward-pipeline-[A-Za-z0-9_-]{1,80}',pipeline)):
        raise SafeError('FORWARD_RESUME_NAMES_INVALID')


def forward_plan(backup,prior,quiet,pair):
    return {'phase':'FORWARD_PLAN','backup':backup.name,'original_backup':OLD_BACKUP,'original_pipeline':OLD_PIPELINE,
            'original_scope':quiet['recovery_scope'],'configuration_fingerprint':prior['configuration_fingerprint'],
            'targets':{p:v[1]for p,v in pair.items()}}


def validate_forward_journal(raw,plan):
    rows=[json.loads(line)for line in raw.splitlines()if line]
    phases={'FORWARD_PLAN','ORIGINAL_PIPELINE_VERIFIED','QUIESCENT_PREFLIGHT_READY','REVIEWED_PAIR_PUBLISHED',
            'ALL_FOURTEEN_RECOVERED','ORIGINAL_PIPELINES_RESTORED','ORDERS_PAUSED_CAPTURE_ACTIVE','CAPTURE_GUARD_VERIFIED',
            'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN'}
    if(not 1<=len(rows)<=50 or rows[0]!=plan or any(not isinstance(r,dict)or r.get('phase')not in phases for r in rows)):
        raise SafeError('FORWARD_RESUME_JOURNAL_BINDING_NEEDS_REVIEW')
    return rows


def orchestrate(pub,old_root,diag,factory,root,uid,gid,prior,quiet,original,pair,pair_bytes,candidate,backup,
                invoke,write_journal,runner=command,capture=None):
    profiles={n:tuple(prior['profiles'][n])for n in UNITS};paused=False;capture_ready=capture is None
    if capture is not None:profiles[UNITS[1]]=capture['validate']()
    def original_unchanged():
        values=validate_original(diag,pub,root.parent/'whatsapp-release-backups',uid)
        if values[2:]!=original[2:]:raise SafeError('FORWARD_ORIGINAL_RECEIPTS_CHANGED')
    def profiles_unchanged():
        if any(old_root.unit_profile(n)!=profiles[n]for n in UNITS):raise SafeError('FORWARD_UNIT_PROFILE_CHANGED')
    def observe_paused_orders():
        for n in UNITS[2:]:
            s=old_root.unit_state(n,runner)
            if s['ActiveState']not in('inactive','failed')or s.get('MainPID','0')!='0':raise SafeError('FORWARD_ORDERS_ALREADY_RUNNING')
    profiles_unchanged();observe_paused_orders();original_unchanged()
    initial=app_guard(pub,root,uid,prior['configuration_fingerprint'],pair,pair_bytes)
    expected_sources=initial[1];pair_published=False
    observed={p:blob(v[0].content)for p,v in initial[1].items()if p in pub.SOURCE_PINS}
    first=invoke(lambda:run_recovery(pub,factory,root,observed,backup,quiet['recovery_scope'],root.parent/'whatsapp-release-backups'/OLD_BACKUP,candidate,'preflight'))
    if not preflight_ready(first):raise SafeError('FORWARD_PREFLIGHT_NOT_READY')
    write_journal({'phase':'ORIGINAL_PIPELINE_VERIFIED','original_pipeline':OLD_PIPELINE,'original_backup':OLD_BACKUP})
    try:
        paused=True
        code,_=runner(['/usr/bin/systemctl','stop',*UNITS])
        if code:raise SafeError('FORWARD_PIPELINES_STOP_FAILED')
        old_root.require_quiet(runner);profiles_unchanged();original_unchanged()
        guarded=app_guard(pub,root,uid,prior['configuration_fingerprint'],pair,pair_bytes)
        if guarded!=initial:raise SafeError('FORWARD_SOURCE_CHANGED_DURING_QUIESCENCE')
        observed={p:blob(v[0].content)for p,v in guarded[1].items()if p in pub.SOURCE_PINS}
        refreshed=invoke(lambda:run_recovery(pub,factory,root,observed,backup,quiet['recovery_scope'],root.parent/'whatsapp-release-backups'/OLD_BACKUP,candidate,'preflight'))
        if not preflight_ready(refreshed):raise SafeError('FORWARD_QUIESCENT_PREFLIGHT_NOT_READY')
        write_journal({'phase':'QUIESCENT_PREFLIGHT_READY'})
        if capture is not None:
            profiles[UNITS[1]]=capture['refresh']();capture_ready=True;profiles_unchanged()
            write_journal({'phase':'CAPTURE_GUARD_VERIFIED'})
        invoke(lambda:publish_pair(pub,root,uid,guarded[0],guarded[1],pair,pair_bytes,backup))
        write_journal({'phase':'REVIEWED_PAIR_PUBLISHED'})
        published=app_guard(pub,root,uid,prior['configuration_fingerprint'],pair,pair_bytes)
        for p,v in pair.items():
            if blob(published[1][p][0].content)!=v[1]:raise SafeError('FORWARD_PUBLISHED_TARGET_CHANGED')
        expected_sources=published[1];pair_published=True
        targets={p:v[1]for p,v in pub.SOURCE_PINS.items()};targets[HELPER_PATH]=pair[HELPER_PATH][1]
        result=invoke(lambda:run_recovery(pub,factory,root,targets,backup,quiet['recovery_scope'],root.parent/'whatsapp-release-backups'/OLD_BACKUP,None,'recover'))
        if result.get('status')not in('SEMANTIC_FORWARD_RECOVERY_READY','SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE')or result.get('processed_total')!=14 or result.get('committed')is not True:
            raise SafeError('FORWARD_RECOVERY_RESULT_NEEDS_REVIEW')
        final_sources(pub,root,uid,guarded[0],published[1],pair);profiles_unchanged();old_root.require_quiet(runner);original_unchanged()
        if capture is not None and capture['validate']()!=profiles[UNITS[1]]:raise SafeError('FORWARD_CAPTURE_PROFILE_CHANGED')
        write_journal({'phase':'ALL_FOURTEEN_RECOVERED','metrics':result.get('metrics')})
        old_root.restore_timers(prior['before'],profiles,runner)
        write_journal({'phase':'ORIGINAL_PIPELINES_RESTORED'})
        paused=False
        return result
    except BaseException as error:
        known=(SafeError,pub.base.SafeError,old_root.SafeError)+(capture.get('error_types',())if capture else())
        code=str(error)if isinstance(error,known)and re.fullmatch('[A-Z0-9_]{1,100}',str(error))else'FORWARD_RECOVERY_NEEDS_REVIEW'
        if paused:
            if capture_ready:
                try:
                    pub.require_configuration(root,uid,initial[0])
                    final_sources(pub,root,uid,initial[0],expected_sources,pair if pair_published else{})
                    if capture is not None and capture['validate']()!=profiles[UNITS[1]]:capture_ready=False
                except BaseException:capture_ready=False
            if not capture_ready:
                # A published unit file is not proof that systemd loaded it.
                # Keep all four quiet if refresh/reload has not succeeded.
                runner(['/usr/bin/systemctl','stop',*UNITS])
                try:old_root.require_quiet(runner)
                except BaseException:raise SafeError('FORWARD_PIPELINE_STATE_UNCERTAIN')from None
                try:write_journal({'phase':'ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN','failure_code':code})
                except BaseException:pass
                raise SafeError(code+'_ALL_PIPELINES_PAUSED_CAPTURE_UNCERTAIN')from None
            try:old_root.fail_closed(profiles,runner)
            except BaseException:raise SafeError('FORWARD_RECOVERY_AND_PIPELINES_NEED_REVIEW')from None
            failure={'phase':'ORDERS_PAUSED_CAPTURE_ACTIVE','failure_code':code}
            if type(getattr(error,'committed_hint',None))is bool:failure['committed_hint']=error.committed_hint
            try:write_journal(failure)
            except BaseException:pass
        raise SafeError(code+'_ORDERS_PAUSED_CAPTURE_ACTIVE')from None


def main():
    parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--release',required=True)
    parser.add_argument('--resume-backup');parser.add_argument('--resume-pipeline');args=parser.parse_args()
    locks=[];journal_fd=None;backup=None;pipeline=None
    try:
        def interrupted(signum,frame):raise KeyboardInterrupt()
        signal.signal(signal.SIGINT,interrupted);signal.signal(signal.SIGTERM,interrupted);os.umask(0o077)
        if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{40}',args.release):raise SafeError('FORWARD_ROOT_CONTEXT_INVALID')
        validate_resume_names(args.resume_backup,args.resume_pipeline)
        code,kind=command(['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT),'cat-file','-t',args.release])
        if code or kind.strip()!=b'commit':raise SafeError('FORWARD_RELEASE_NOT_COMMIT')
        user=pwd.getpwnam('fasakha');uid,gid=user.pw_uid,user.pw_gid;parent=ROOT.parent/'whatsapp-release-backups'
        files={
            'install_whatsapp_inbox.py':source(args.release,'deployment/install_whatsapp_inbox.py',BASE_BLOB),
            'apply_whatsapp_order_semantic_repair.py':source(args.release,'deployment/apply_whatsapp_order_semantic_repair.py',PUBLISHER_BLOB),
            'root.sh':source(args.release,'deployment/apply_whatsapp_order_semantic_repair_root.sh',OLD_ROOT_BLOB),
            'diag.py':source(args.release,'deployment/diagnose_whatsapp_order_semantic_recovery.py',DIAGNOSTIC_BLOB),
            'factory.py':source(args.release,'deployment/whatsapp_order_semantic_forward_recovery.py',FACTORY_BLOB),
            'capture.py':source(args.release,'deployment/refresh_whatsapp_capture_guard.py',CAPTURE_GUARD_BLOB),
            'capture-legacy.sh':source(args.release,'deployment/install_whatsapp_capture_root.sh',CAPTURE_LEGACY_BLOB)}
        pair={HELPER_PATH:('f2ee72b55d9fd27478f5da852cc3533dbb6de747',HELPER_AFTER),
              PROTOCOL_PATH:('ecde67c6a87ca8068bb077892fdbea522d89f217',PROTOCOL_AFTER)}
        pair_bytes={p:source(args.release,p,v[1])for p,v in pair.items()}
        with tempfile.TemporaryDirectory(prefix='wa-forward-root-')as folder:
            directory=pathlib.Path(folder)
            for n,data in files.items():write_new(directory/n,data)
            pub=load_module(files['apply_whatsapp_order_semantic_repair.py'],'verified_forward_legacy_publisher',directory/'apply_whatsapp_order_semantic_repair.py')
            root_program=files['root.sh'].split(b"exec /usr/bin/python3 - \"$@\" <<'PY'\n",1)[1][:-len(b'\nPY\n')]
            old_root=load_module(root_program,'verified_forward_legacy_root',directory/'root.py')
            diag=load_module(files['diag.py'],'verified_forward_legacy_diagnostic',directory/'diag.py')
            factory=load_module(files['factory.py'],'verified_forward_recovery_factory',directory/'factory.py')
            capture_guard=load_module(files['capture.py'],'verified_forward_capture_guard',directory/'capture.py')
            pub.base.private_parent(parent,uid);locks=acquire_existing_locks(parent,uid)
            original=validate_original(diag,pub,parent,uid);prior,quiet=original[:2]
            if args.resume_backup is not None:
                backup=parent/args.resume_backup;pipeline=parent/args.resume_pipeline
                s=backup.lstat()
                if not stat.S_ISDIR(s.st_mode)or s.st_uid!=uid or stat.S_IMODE(s.st_mode)!=0o700:
                    raise SafeError('FORWARD_RESUME_BACKUP_UNSAFE')
                pipeline_raw,_=diag.private_file(pipeline/'journal.jsonl',0,1048576,ancestry_uid=uid)
            else:
                backup=pathlib.Path(tempfile.mkdtemp(prefix='order-semantic-forward-',dir=parent));os.chown(backup,uid,gid);backup.chmod(0o700)
                pipeline=pathlib.Path(tempfile.mkdtemp(prefix='semantic-forward-pipeline-',dir=parent));pipeline.chmod(0o700)
                pipeline_raw=None
            plan=forward_plan(backup,prior,quiet,pair)
            if pipeline_raw is not None:
                validate_forward_journal(pipeline_raw,plan)
                journal_fd=os.open(pipeline/'journal.jsonl',os.O_WRONLY|os.O_APPEND|os.O_NOFOLLOW)
            else:journal_fd=os.open(pipeline/'journal.jsonl',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            journal_identity=metadata(pipeline/'journal.jsonl');pipeline_identity=metadata(pipeline)
            def write_journal(value):
                s=os.fstat(journal_fd);p=(pipeline/'journal.jsonl').lstat()
                if (metadata(pipeline)[:5]!=pipeline_identity[:5]or not stat.S_ISREG(s.st_mode)or s.st_uid!=0
                        or stat.S_IMODE(s.st_mode)!=0o600 or s.st_nlink!=1
                        or (s.st_dev,s.st_ino)!=(journal_identity[0],journal_identity[1])
                        or (s.st_dev,s.st_ino)!=(p.st_dev,p.st_ino)):
                    raise SafeError('FORWARD_PIPELINE_JOURNAL_CHANGED')
                data=(json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
                while data:
                    n=os.write(journal_fd,data)
                    if n<1:raise SafeError('FORWARD_PIPELINE_JOURNAL_FAILED')
                    data=data[n:]
                os.fsync(journal_fd)
            if pipeline_raw is None:write_journal(plan)
            with tempfile.TemporaryDirectory(prefix='.semantic-forward-candidate-',dir=parent)as candidate_folder:
                candidate=pathlib.Path(candidate_folder);os.chown(candidate,uid,gid);candidate.chmod(0o700)
                for path,data in pair_bytes.items():
                    target=candidate/pathlib.PurePosixPath(path).name
                    write_new(target,data,uid,gid)
                def invoke(fn):return child_call(fn,uid,gid,(journal_fd,))
                capture_policy={**{p:v[1]for p,v in pub.SOURCE_PINS.items()},**pub.PROTECTED_PINS,ACCESS_PATH:ACCESS_BLOB}
                for p,v in pair.items():capture_policy[p]=v
                checkpoint=pipeline/'capture-guard'
                original_capture=tuple(prior['profiles'][UNITS[1]])
                def capture_validate():
                    try:
                        return capture_guard.validate_capture_guard(ROOT,checkpoint,original_capture,capture_policy,command,
                            legacy_source=files['capture-legacy.sh'],source_uid=uid)
                    except capture_guard.SafeError as error:raise SafeError(str(error))from None
                def capture_refresh():
                    return capture_guard.refresh_capture_guard(ROOT,checkpoint,original_capture,capture_policy,command,
                        legacy_source=files['capture-legacy.sh'],source_uid=uid)
                capture={'validate':capture_validate,'refresh':capture_refresh,'error_types':(capture_guard.SafeError,)}
                result=orchestrate(pub,old_root,diag,factory,ROOT,uid,gid,prior,quiet,original,pair,pair_bytes,candidate,backup,invoke,write_journal,capture=capture)
            print('WHATSAPP_SEMANTIC_FORWARD_RECOVERY_READY')
            print('BACKUP='+str(backup));print('PIPELINE_BACKUP='+str(pipeline))
            print('FOURTEEN_EVENTS_PROVED; ORIGINAL_PIPELINES_RESTORED; CONFIGURATION_UNCHANGED; AI_CALLS=0; ORDERS_CREATED=0')
    except BaseException as error:
        print('WHATSAPP_SEMANTIC_FORWARD_RECOVERY_NEEDS_REVIEW',file=sys.stderr)
        if isinstance(error,SafeError)and re.fullmatch('[A-Z0-9_]{1,150}',str(error)):print('STAGE='+str(error),file=sys.stderr)
        if backup:print('BACKUP='+str(backup),file=sys.stderr)
        if pipeline:print('PIPELINE_BACKUP='+str(pipeline),file=sys.stderr)
        raise SystemExit(1)
    finally:
        if journal_fd is not None:os.close(journal_fd)
        for fd in locks:os.close(fd)


if __name__=='__main__':main()
