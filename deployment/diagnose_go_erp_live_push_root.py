#!/usr/bin/env python3
"""Run one original-worker retry with finite, credential-free push diagnostics."""
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import time

DOCTOR_PIN = '1842302a87b7e93253ad5be616c3ab3e56bdd4a1'
MODULE_PINS = {'go_erp_live_source.py':'4ddb4c9a6dd4011b8308075ca330fe165304ae31',
               'go_erp_live_updates.py':'a916fd72c20699ca1710ca188a6999db02dfbe07',
               'publish_go_erp_update.py':'f9a7353b0705383844a576ab6d0f5b1662912bfb'}
REPOSITORY = 'omarmakramelbaz-hub/F-Backend'
CATEGORIES = frozenset(('PUSH_SUCCEEDED','PUSH_NOT_ATTEMPTED','WRITE_PERMISSION_DENIED',
    'WORKFLOW_PERMISSION_REQUIRED','AUTHENTICATION_FAILED','BRANCH_PROTECTED',
    'PUSH_PROTECTION','REPOSITORY_NOT_FOUND',
    'NETWORK_DNS','NETWORK_TLS','NETWORK_TIMEOUT','REMOTE_CHANGED','UNKNOWN_PRIVATE'))
OUTCOMES = frozenset(('GO_ERP_LIVE_UPDATE_CURRENT','GO_ERP_LIVE_UPDATE_RETRY_DEFERRED',
    'GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED','GO_ERP_LIVE_UPDATE_WAITING_CI',
    'GO_ERP_LIVE_UPDATE_SUPERSEDED','GO_ERP_LIVE_UPDATE_PUBLISHED',
    'GO_ERP_LIVE_UPDATE_PUBLISHED_STORAGE_REVIEW_REQUIRED'))

class Stop(Exception):
    pass

def need(value,code):
    if not value:
        raise Stop(code)

def identity(value):
    return (value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
            value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)

def private_doctor(name):
    path = Path(name)
    need(path.is_absolute() and path.resolve() == path,'PRIVATE_DOCTOR_UNSAFE')
    parents = []
    for parent in (path.parent,*path.parent.parents):
        saved = parent.lstat()
        sticky = parent != path.parent and bool(saved.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(saved.st_mode) and saved.st_uid == 0
             and (not saved.st_mode & 0o022 or sticky),'PRIVATE_DOCTOR_UNSAFE')
        parents.append((parent,saved))
    need(stat.S_IMODE(parents[0][1].st_mode) == 0o700,'PRIVATE_DOCTOR_UNSAFE')
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        saved = os.fstat(fd)
        need(stat.S_ISREG(saved.st_mode) and (saved.st_uid,saved.st_gid,saved.st_nlink,
             stat.S_IMODE(saved.st_mode)) == (0,0,1,0o600) and 0 < saved.st_size <= 512*1024,
             'PRIVATE_DOCTOR_UNSAFE')
        data = os.read(fd,512*1024+1)
        need(len(data) == saved.st_size and identity(saved) == identity(os.fstat(fd))
             == identity(path.lstat()),'PRIVATE_DOCTOR_CHANGED')
        need(all(identity(parent.lstat())[:5] == identity(before)[:5] for parent,before in parents),
             'PRIVATE_DOCTOR_CHANGED')
        need(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() == DOCTOR_PIN,
             'PRIVATE_DOCTOR_PIN_INVALID')
        namespace = {'__name__':'reviewed_live_push_doctor'}
        exec(compile(data,str(path),'exec'),namespace)
        return namespace
    finally:
        os.close(fd)

def classify(returncode,stdout,stderr):
    if returncode == 0:
        return 'PUSH_SUCCEEDED'
    def bounded(value):
        return value[:65536].decode('utf-8','replace').lower() if isinstance(value,bytes) else ''
    message = bounded(stdout)+'\n'+bounded(stderr)
    checks = (
        ('WORKFLOW_PERMISSION_REQUIRED',('workflow scope','workflows permission','workflow permission',
            'without `workflow` scope','without workflow scope','refusing to allow a personal access token to create or update workflow')),
        ('PUSH_PROTECTION',('push cannot contain secrets','push protection')),
        ('BRANCH_PROTECTED',('protected branch','gh006','gh013','repository rule violations')),
        ('AUTHENTICATION_FAILED',('authentication failed','bad credentials','invalid username or password',
            'invalid username or token','could not read username','password authentication was removed',
            'password authentication is not supported')),
        ('REPOSITORY_NOT_FOUND',('repository not found',)),
        ('WRITE_PERMISSION_DENIED',('write access to repository not granted','permission to ',
            'permission denied','the requested url returned error: 403')),
        ('NETWORK_DNS',('could not resolve host','name or service not known','temporary failure in name resolution')),
        ('NETWORK_TLS',('ssl certificate problem','certificate verify failed','tls handshake','ssl connect error')),
        ('NETWORK_TIMEOUT',('connection timed out','operation timed out','connection timeout')),
        ('REMOTE_CHANGED',('stale info','fetch first','non-fast-forward','remote ref updated since checkout')),
    )
    for category,patterns in checks:
        if any(pattern in message for pattern in patterns):
            return category
    return 'UNKNOWN_PRIVATE'

def classified_git(worker):
    """Keep the pinned implementation; inspect private output only for push failure."""
    original = worker.Git
    class ClassifiedGit(original):
        last_push_category = 'PUSH_NOT_ATTEMPTED'
        last_push_exit_code = None
        push_attempted = False

        def run(self,arguments,cwd=None,timeout=180):
            if not arguments or arguments[0] != 'push':
                return super().run(arguments,cwd,timeout)
            branch = 'refs/heads/'+worker.BRANCH
            lease = '--force-with-lease='+branch+':'
            worker.need(len(arguments) == 5 and arguments[1] == '--porcelain'
                and isinstance(arguments[2],str) and arguments[2].startswith(lease)
                and (arguments[2] == lease or worker.REV.fullmatch(arguments[2][len(lease):]))
                and arguments[3] == 'origin' and isinstance(arguments[4],str)
                and re.fullmatch(r'[a-f0-9]{40}:'+re.escape(branch),arguments[4]),'PUSH_REVISION_INVALID')
            env = {'PATH':'/usr/bin:/bin','LANG':'C.UTF-8',
                'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1',
                'GIT_OPTIONAL_LOCKS':'0','GIT_TERMINAL_PROMPT':'0','GIT_ASKPASS':str(self.askpass),
                'GO_ERP_GIT_TOKEN':self.token}
            command = ['/usr/bin/git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false',
                '-c','credential.helper=','-c','http.followRedirects=false','-c','http.sslVerify=true',*arguments]
            output,error = tempfile.TemporaryFile(),tempfile.TemporaryFile()
            process = None
            try:
                self.push_attempted = True
                self.last_push_category = 'UNKNOWN_PRIVATE'
                process = subprocess.Popen(command,cwd=cwd,env=env,stdout=output,stderr=error,start_new_session=True)
                start = time.monotonic()
                while process.poll() is None:
                    self.heartbeat()
                    worker.need(time.monotonic()-start <= timeout,'GIT_DEADLINE')
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        continue
                self.last_push_exit_code = process.returncode if type(process.returncode) is int and -255 <= process.returncode <= 255 else None
                output.seek(0); error.seek(0)
                self.last_push_category = classify(process.returncode,output.read(65536),error.read(65536))
                worker.need(process.returncode == 0,'GIT_OPERATION_FAILED')
                output.seek(0)
                data = output.read(worker.MAX_JSON+1)
                worker.need(len(data) <= worker.MAX_JSON,'GIT_OUTPUT_LIMIT')
                return data
            except Exception as failure:
                if type(failure) is worker.WatchError and str(failure) == 'GO_ERP_LIVE_GIT_DEADLINE':
                    self.last_push_category = 'NETWORK_TIMEOUT'
                raise
            finally:
                if process is not None and process.poll() is None:
                    with contextlib.suppress(ProcessLookupError):
                        os.killpg(process.pid,signal.SIGTERM)
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        with contextlib.suppress(ProcessLookupError):
                            os.killpg(process.pid,signal.SIGKILL)
                        process.wait(timeout=5)
                output.close(); error.close()
    return ClassifiedGit

def safe_reason(error,doc,worker):
    if type(error) is Stop and error.args and error.args[0] in {
            'BASE_REVISION_CHANGED','PRIVATE_DOCTOR_UNSAFE','PRIVATE_DOCTOR_CHANGED',
            'PRIVATE_DOCTOR_PIN_INVALID','ROOT_AND_HOST_REQUIRED','INVOCATION_INVALID'}:
        return 'GO_ERP_LIVE_PUSH_'+error.args[0]
    if type(error) is doc.get('Stop') and str(error) in {
            'GO_ERP_LIVE_DIAGNOSIS_WATCHER_BUSY','GO_ERP_LIVE_DIAGNOSIS_LOCK_UNSAFE',
            'GO_ERP_LIVE_DIAGNOSIS_LOCK_CHANGED','GO_ERP_LIVE_DIAGNOSIS_DIRECTORY_UNSAFE',
            'GO_ERP_LIVE_DIAGNOSIS_DIRECTORY_MODE_INVALID','GO_ERP_LIVE_DIAGNOSIS_DIRECTORY_CHANGED'}:
        return str(error)
    if type(error) is worker.WatchError and re.fullmatch(r'GO_ERP_LIVE_[A-Z_0-9]{1,100}',str(error)):
        return str(error)
    return 'UNKNOWN_PRIVATE'

def retry_once(doc,source,worker,publisher):
    result = {'kind':'go-erp-live-push-retry','ok':False,'repository':REPOSITORY,
              'credentials_used':False,'credentials_disclosed':False,'remote_write_attempted':False,
              'category':'PUSH_NOT_ATTEMPTED','exit_code':None}
    original = worker.Git
    instance = None
    try:
        with doc['existing_lock'](worker.WORK):
            config = worker.read_config()
            result['credentials_used'] = True
            need(config['base_revision'] == doc['BASE'],'BASE_REVISION_CHANGED')
            worker.Git = classified_git(worker)
            instance = worker.Worker(config,source,publisher)
            doc['safe_state'](instance.state)
            desired = instance.state.get('desired')
            if isinstance(desired,dict) and desired.get('status') == 'prepared' and desired.get('pushed') is False:
                desired['retry_at'] = 0
            outcome = instance.tick()
            need(outcome in OUTCOMES,'OUTCOME_INVALID')
            result.update(ok=True,outcome=outcome)
    except Exception as error:
        result.update(ok=False,reason=safe_reason(error,doc,worker))
    finally:
        worker.Git = original
        if instance is not None:
            git = instance.git
            category = getattr(git,'last_push_category','PUSH_NOT_ATTEMPTED')
            result['category'] = category if category in CATEGORIES else 'UNKNOWN_PRIVATE'
            code = getattr(git,'last_push_exit_code',None)
            result['exit_code'] = code if type(code) is int and -255 <= code <= 255 else None
            result['remote_write_attempted'] = getattr(git,'push_attempted',False) is True
            try:
                result['state'] = doc['safe_state'](instance.state)
                status = (instance.state.get('desired') or {}).get('status')
                if status == 'waiting-ci':
                    result['state']['status'] = status
            except Exception:
                pass
    return result

def execute(args):
    need(len(args) == 1,'INVOCATION_INVALID')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524','ROOT_AND_HOST_REQUIRED')
    doc = private_doctor(args[0])
    source,worker,publisher = doc['load_installed'](doc['INSTALL'],MODULE_PINS)
    return retry_once(doc,source,worker,publisher)

def main(args=None):
    try:
        result = execute(sys.argv[1:] if args is None else args)
    except Exception:
        result = {'kind':'go-erp-live-push-retry','ok':False,'reason':'UNKNOWN_PRIVATE',
                  'credentials_disclosed':False}
    print(json.dumps(result,sort_keys=True))
    return 0 if result['ok'] else 1

if __name__ == '__main__':
    sys.exit(main())
