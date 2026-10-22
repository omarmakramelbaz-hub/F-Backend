#!/usr/bin/env python3
"""Diagnose one existing, verified candidate with the installed policy runtime.

This is a real guarded push, not a read-only probe. It never builds a replacement
candidate, calls tick(), downloads artifacts, publishes an installer, or changes
the installed runtime. The normal watcher owns CI acceptance and publication.
"""
import contextlib
import hashlib
import json
import math
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
import types

HELPER_PIN = '5e379867b27cc91b6494b7f5008802c5a8b0b936'
RUNTIME_NAME = 'policy-62f3d9e18d2a718978959b3fd141430872a98f900e4053a69f883ae0f4ad7da6'
REPOSITORY = 'omarmakramelbaz-hub/F-Backend'
POLICY = 'historical-baseline-v1'
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
CATEGORIES = frozenset(('PUSH_SUCCEEDED', 'PUSH_NOT_ATTEMPTED', 'WRITE_PERMISSION_DENIED',
    'WORKFLOW_PERMISSION_REQUIRED', 'AUTHENTICATION_FAILED', 'BRANCH_PROTECTED',
    'PUSH_PROTECTION', 'REPOSITORY_NOT_FOUND', 'NETWORK_DNS', 'NETWORK_TLS',
    'NETWORK_TIMEOUT', 'REMOTE_CHANGED', 'UNKNOWN_PRIVATE'))
PUBLIC_REASONS = frozenset(('HELPER_PATH_UNSAFE', 'HELPER_PARENT_UNSAFE', 'HELPER_FILE_UNSAFE',
    'HELPER_CHANGED', 'HELPER_PIN_INVALID', 'EFFECTIVE_UNIT_MISMATCH', 'WATCHER_BUSY',
    'EFFECTIVE_RUNTIME_MISMATCH', 'UNIT_QUERY_FAILED', 'UNIT_QUERY_INVALID',
    'UNIT_RUNTIME_MISMATCH', 'RUNTIME_POLICY_MISMATCH', 'STATE_INVALID',
    'BASE_REVISION_MISMATCH', 'NO_PREPARED_PUSH_PENDING', 'CANDIDATE_POLICY_MISMATCH',
    'CANDIDATE_IDENTITY_INVALID', 'CANDIDATE_PATH_INVALID', 'CANDIDATE_HEAD_MISMATCH',
    'DEPENDENCY_GUARD_MISMATCH', 'SOURCE_SUPERSEDED', 'EXISTING_CANDIDATE_VERIFICATION_FAILED',
    'STATE_CHANGED', 'INVOCATION_INVALID', 'ROOT_AND_HOST_REQUIRED',
    'DIRECTORY_UNSAFE', 'DIRECTORY_MODE_INVALID', 'DIRECTORY_CHANGED', 'FILE_UNSAFE',
    'FILE_CHANGED', 'FILE_SCOPE_INVALID', 'LOCK_UNSAFE', 'LOCK_CHANGED',
    'RUNTIME_ROSTER_INVALID', 'RUNTIME_PIN_INVALID', 'RUNTIME_PREFLIGHT_FAILED',
    'RUNTIME_FILES_MISSING'))
RUNTIME_REASONS = frozenset('GO_ERP_LIVE_'+name for name in (
    'GIT_OPERATION_FAILED', 'GIT_DEADLINE', 'GIT_OUTPUT_LIMIT', 'PUSH_REVISION_INVALID',
    'SOURCE_SUPERSEDED', 'DEPENDENCY_CHANGE_REQUIRES_REVIEWED_SEED',
    'TRUSTED_SEED_CHANGED', 'REMOTE_BRANCH_INVALID', 'REMOTE_BRANCH_CHANGED_OUTSIDE_WORKER',
    'CONFIG_PARENT_UNSAFE', 'CONFIG_INVALID', 'CONFIG_TOKEN_INVALID', 'ASKPASS_CHANGED',
    'DURABLE_STATE_INVALID', 'FILE_UNSAFE', 'FILE_CHANGED', 'DIRECTORY_UNSAFE',
    'DIRECTORY_OWNERSHIP_UNSAFE', 'PATH_UNSAFE', 'JSON_INVALID', 'DUPLICATE_JSON_FIELD',
    'GITHUB_UNAVAILABLE', 'GITHUB_HTTP_400', 'GITHUB_HTTP_401', 'GITHUB_HTTP_403',
    'GITHUB_HTTP_404', 'GITHUB_HTTP_409', 'GITHUB_HTTP_422', 'GITHUB_HTTP_429',
    'GITHUB_HTTP_500', 'GITHUB_HTTP_502', 'GITHUB_HTTP_503', 'GITHUB_HTTP_504',
    'API_SCOPE_INVALID', 'API_JSON_LIMIT',
    'SOURCE_CANDIDATE_BASE', 'SOURCE_CANDIDATE_POLICY', 'SOURCE_TRUSTED_PATH',
    'SOURCE_GIT_TRUST', 'SOURCE_GIT_LINK', 'SOURCE_GIT_EXTERNAL_OBJECTS',
    'SOURCE_GIT_FILE', 'SOURCE_PACKAGED_TREE_MISMATCH', 'SOURCE_PACKAGE_VERSION'))


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop(reason)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def private_helper(name):
    """Load only the already reviewed, immutable upgrade utility; never call it."""
    path = Path(name)
    need(path.is_absolute() and path.resolve() == path, 'HELPER_PATH_UNSAFE')
    parents = []
    for parent in (path.parent, *path.parent.parents):
        item = parent.lstat()
        sticky = parent != path.parent and bool(item.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0
             and (not item.st_mode & 0o022 or sticky), 'HELPER_PARENT_UNSAFE')
        parents.append((parent, item))
    need(stat.S_IMODE(parents[0][1].st_mode) == 0o700, 'HELPER_PARENT_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
             before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o600)
             and 0 < before.st_size <= 512*1024, 'HELPER_FILE_UNSAFE')
        data = bytearray()
        while len(data) <= 512*1024:
            part = os.read(fd, min(65536, 512*1024+1-len(data)))
            if not part:
                break
            data.extend(part)
        data = bytes(data)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
             == identity(path.lstat()), 'HELPER_CHANGED')
        need(all(identity(parent.lstat())[:5] == identity(saved)[:5]
                 for parent, saved in parents), 'HELPER_CHANGED')
        need(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
             == HELPER_PIN, 'HELPER_PIN_INVALID')
        namespace = {'__name__': 'reviewed_current_push_utilities'}
        exec(compile(data, str(path), 'exec'), namespace)
        return types.SimpleNamespace(**namespace)
    finally:
        os.close(fd)


def validate_unit_properties(properties, runtime, helper):
    need(properties.get('FragmentPath') == str(helper.UNIT)
         and properties.get('DropInPaths') == ''
         and properties.get('NeedDaemonReload') == 'no', 'EFFECTIVE_UNIT_MISMATCH')
    need(properties.get('ActiveState') in ('inactive', 'failed'), 'WATCHER_BUSY')
    expected = ('/usr/bin/python3 -I -B '+str(runtime/'go_erp_live_updates.py')
                +' --config '+str(helper.CONFIG)+' --once')
    value = properties.get('ExecStart', '')
    entries = re.findall(r'argv\[\]=([^;]+);', value)
    need(len(entries) == 1 and entries[0].strip() == expected
         and value.count('path=') == 1 and 'path=/usr/bin/python3 ;' in value,
         'EFFECTIVE_RUNTIME_MISMATCH')


def effective_unit(runtime, helper):
    result = subprocess.run(['/usr/bin/systemctl', 'show', 'go-erp-live-updates.service',
        '--no-pager', '-p', 'FragmentPath', '-p', 'DropInPaths', '-p', 'NeedDaemonReload',
        '-p', 'ExecStart', '-p', 'ActiveState'], stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=15,
        env={'PATH': '/usr/bin:/bin', 'LANG': 'C'})
    need(result.returncode == 0 and len(result.stdout) <= 16384, 'UNIT_QUERY_FAILED')
    rows = result.stdout.decode('utf-8', 'strict').splitlines()
    need(all('=' in row for row in rows), 'UNIT_QUERY_INVALID')
    properties = dict(row.split('=', 1) for row in rows)
    need(len(properties) == len(rows), 'UNIT_QUERY_INVALID')
    validate_unit_properties(properties, runtime, helper)


@contextlib.contextmanager
def installed_current(helper):
    runtime = helper.RELEASES / RUNTIME_NAME
    with contextlib.ExitStack() as stack:
        directory = helper.Directory(runtime, 0o755)
        stack.callback(directory.close)
        unit_parent = helper.Directory(helper.UNIT.parent)
        stack.callback(unit_parent.close)
        def verify():
            helper.validate_runtime(directory, helper.PINS)
            need(helper.read_at(unit_parent, helper.UNIT.name, helper.MAX_FILE, 0o644)
                 == helper.service_unit(runtime), 'UNIT_RUNTIME_MISMATCH')
            effective_unit(runtime, helper)
        verify()
        source, worker, publisher = [helper.module_at(directory, name, helper.PINS[name])
            for name in ('go_erp_live_source.py', 'go_erp_live_updates.py', 'publish_go_erp_update.py')]
        need(source.CANDIDATE_POLICY == worker.CANDIDATE_POLICY == helper.POLICY == POLICY,
             'RUNTIME_POLICY_MISMATCH')
        yield source, worker, publisher, verify


def safe_state(state):
    need(isinstance(state, dict) and state.get('format') == 1
         and type(state.get('counter')) is int and 0 <= state['counter'] <= 9007199254740991,
         'STATE_INVALID')
    result = {'counter': state['counter'], 'published': state.get('published') is not None}
    desired = state.get('desired')
    if isinstance(desired, dict):
        for name in ('commit', 'version', 'status', 'failures', 'pushed', 'retry_at', 'candidatePolicy'):
            value = desired.get(name)
            valid = (name == 'commit' and isinstance(value, str) and REV.fullmatch(value)
                or name == 'version' and isinstance(value, str)
                    and re.fullmatch(r'[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}-preview\.[0-9]{1,16}', value)
                or name == 'status' and value in ('prepared', 'waiting-ci', 'published', 'blocked', 'retry-build')
                or name == 'failures' and type(value) is int and 0 <= value <= 100
                or name == 'pushed' and type(value) is bool
                or name == 'retry_at' and type(value) in (int, float) and 0 <= value <= 9007199254740991
                    and math.isfinite(value)
                or name == 'candidatePolicy' and value == POLICY)
            if valid:
                result[name] = value
    return result


def read_state(helper, worker):
    directory = helper.Directory(worker.WORK/'state', 0o700)
    try:
        raw = helper.read_at(directory, 'current.json', worker.MAX_JSON, 0o600)
        # Require the existing credential helper, so construction cannot create it.
        helper.read_at(directory, 'git-askpass', 4096, 0o700)
        state = worker.parse_json(raw)
        need(state.get('base_revision') == helper.BASE, 'BASE_REVISION_MISMATCH')
        safe_state(state)
        return raw, state
    finally:
        directory.close()


def verify_pending(state, worker):
    desired = state.get('desired')
    need(isinstance(desired, dict) and desired.get('status') == 'prepared'
         and desired.get('pushed') is False, 'NO_PREPARED_PUSH_PENDING')
    need(desired.get('candidatePolicy') == worker.CANDIDATE_POLICY == POLICY,
         'CANDIDATE_POLICY_MISMATCH')
    need(isinstance(desired.get('commit'), str) and REV.fullmatch(desired['commit'])
         and desired['commit'] != state.get('base_revision')
         and isinstance(desired.get('inventorySha256'), str) and SHA.fullmatch(desired['inventorySha256'])
         and type(state.get('counter')) is int and state['counter'] > 0, 'CANDIDATE_IDENTITY_INVALID')
    candidate = worker.WORK/'staging'/('candidate-'+str(state['counter'])+'-'+desired['inventorySha256'][:16])
    need(desired.get('candidatePath') == str(candidate), 'CANDIDATE_PATH_INVALID')
    return desired, candidate


def verify_candidate(instance, state, source, worker):
    desired, candidate = verify_pending(state, worker)
    # Check the already committed identity first: prepare_candidate's seed branch
    # writes files; its matching existing-candidate branch only verifies them.
    need(source._trusted_git(candidate) == desired['commit'], 'CANDIDATE_HEAD_MISMATCH')
    dependencies = worker.dependency_snapshot(instance.live, instance.web_uid)
    need(state.get('dependency_guard') == dependencies, 'DEPENDENCY_GUARD_MISMATCH')
    snapshot = instance.scan()
    need(worker.source_identity(snapshot) == desired['inventorySha256'], 'SOURCE_SUPERSEDED')
    result = source.prepare_candidate(candidate, snapshot, state['counter'])
    need(result.get('reused') is True
         and all(result.get(name) == desired[name]
                 for name in ('commit', 'version', 'inventorySha256', 'candidatePolicy')),
         'EXISTING_CANDIDATE_VERIFICATION_FAILED')
    return len(snapshot['metadata']['inventory'])


# The classifier and push binding below are the reviewed original implementation.

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



def safe_reason(error, helper, source, worker):
    if type(error) in (Stop, helper.Stop) and str(error) in PUBLIC_REASONS:
        return 'GO_ERP_CURRENT_PUSH_'+str(error)
    if type(error) in (source.SourceError, worker.WatchError) and str(error) in RUNTIME_REASONS:
        return str(error)
    if type(error) is getattr(worker, 'Superseded', None) and str(error) == 'GO_ERP_LIVE_SOURCE_SUPERSEDED':
        return str(error)
    return 'UNKNOWN_PRIVATE'


def retry_prepared(helper, source, worker, publisher, verify_runtime=lambda: None):
    report = {'kind': 'go-erp-current-runtime-push-diagnosis', 'ok': False,
        'runtime': RUNTIME_NAME, 'candidate_policy': POLICY, 'repository': REPOSITORY,
        'category': 'PUSH_NOT_ATTEMPTED', 'remote_write_attempted': False,
        'installer_publication_attempted': False, 'replacement_candidate_created': False,
        'application_contents_changed': False, 'database_changed': False,
        'credentials_used': False, 'credentials_disclosed': False, 'phase': 'LOCK'}
    original = worker.Git
    instance = None
    try:
        with helper.watcher_lock(worker.WORK):
            report['phase'] = 'RUNTIME'
            verify_runtime()
            report['phase'] = 'STATE'
            raw, state = read_state(helper, worker)
            report['state'] = safe_state(state)
            verify_pending(state, worker)
            seed = helper.Directory(worker.WORK/'checkout', 0o700)
            seed.close()
            report['phase'] = 'CONFIG'
            config = worker.read_config()
            report['credentials_used'] = True
            need(config['base_revision'] == helper.BASE, 'BASE_REVISION_MISMATCH')
            worker.Git = classified_git(worker)
            report['phase'] = 'CONSTRUCT'
            instance = worker.Worker(config, source, publisher)
            need(read_state(helper, worker)[0] == raw, 'STATE_CHANGED')
            report['phase'] = 'VERIFY_CANDIDATE'
            report['source_files'] = verify_candidate(instance, state, source, worker)
            report['candidate_reused'] = True
            verify_runtime()
            # Keep the original source/dependency guard, lease, state commit and
            # candidate retirement. Do not enter tick or artifact publication.
            report['phase'] = 'GUARD_BEFORE_PUSH'
            desired = instance.state['desired']
            instance.guard_live(desired['inventorySha256'], force=True)
            report['phase'] = 'PUSH'
            instance.push(desired)
            report['phase'] = 'GUARD_AFTER_PUSH'
            instance.guard_live(desired['inventorySha256'], force=True)
            # Clear backoff only after a successful original push and guard.
            # A rejected push leaves its durable retry schedule unchanged.
            desired['retry_at'] = 0
            instance.save()
            report.update(ok=True, phase='DONE', outcome='GO_ERP_LIVE_UPDATE_WAITING_CI')
    except Exception as error:
        report['reason'] = safe_reason(error, helper, source, worker)
    finally:
        worker.Git = original
        if instance is not None:
            git = instance.git
            category = getattr(git, 'last_push_category', 'PUSH_NOT_ATTEMPTED')
            report['category'] = category if category in CATEGORIES else 'UNKNOWN_PRIVATE'
            code = getattr(git, 'last_push_exit_code', None)
            report['exit_code'] = code if type(code) is int and -255 <= code <= 255 else None
            report['remote_write_attempted'] = getattr(git, 'push_attempted', False) is True
            with contextlib.suppress(Exception):
                report['state'] = safe_state(instance.state)
    return report


def execute(arguments):
    need(len(arguments) == 2 and arguments[1] == '--retry-prepared', 'INVOCATION_INVALID')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    helper = private_helper(arguments[0])
    try:
        with installed_current(helper) as (source, worker, publisher, verify):
            return retry_prepared(helper, source, worker, publisher, verify)
    except helper.Stop as error:
        raise Stop(str(error) if str(error) in PUBLIC_REASONS else 'RUNTIME_PREFLIGHT_FAILED') from None
    except (FileNotFoundError, NotADirectoryError):
        raise Stop('RUNTIME_FILES_MISSING') from None


def main(arguments=None):
    try:
        result = execute(sys.argv[1:] if arguments is None else arguments)
    except Exception as error:
        reason = ('GO_ERP_CURRENT_PUSH_'+str(error) if type(error) is Stop
                  and str(error) in PUBLIC_REASONS else 'UNKNOWN_PRIVATE')
        result = {'kind': 'go-erp-current-runtime-push-diagnosis', 'ok': False,
                  'phase': 'PREFLIGHT', 'reason': reason, 'credentials_disclosed': False}
    print(json.dumps(result, sort_keys=True, allow_nan=False))
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
