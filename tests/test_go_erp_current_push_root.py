#!/usr/bin/env python3
"""Current-runtime prepared push keeps provenance and redacts transport failures."""
import importlib.util
import contextlib
import copy
import io
import json
from pathlib import Path
import subprocess
import types
import unittest
from unittest import mock

REPOSITORY=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('current_push_diagnosis_subject',
    REPOSITORY/'deployment/diagnose_go_erp_current_push_root.py')
subject=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(subject)

class LivePushClassifierTests(unittest.TestCase):
    def check(self,message,expected,code=1,stdout=b''):
        self.assertEqual(subject.classify(code,stdout,message),expected)

    def test_write_permission_denied(self):
        self.check(b'remote: Permission to private-owner/private-repo.git denied to private-user.\n'
            b'fatal: unable to access private URL: The requested URL returned error: 403',
            'WRITE_PERMISSION_DENIED')

    def test_workflow_permission_required(self):
        self.check(b'remote: refusing to allow a Personal Access Token to create or update workflow '
            b'`.github/workflows/private-build.yml` without `workflow` scope',
            'WORKFLOW_PERMISSION_REQUIRED')

    def test_authentication_failed(self):
        self.check(b"fatal: Authentication failed for 'https://private-token@private-host/private-repo/'",
            'AUTHENTICATION_FAILED')

    def test_modern_token_authentication_failures(self):
        for message in [b'remote: Invalid username or token. PRIVATE_TOKEN_SENTINEL',
                b'remote: Password authentication is not supported for Git operations.']:
            with self.subTest(message=message):self.check(message,'AUTHENTICATION_FAILED')

    def test_protected_branch(self):
        self.check(b'remote: error: GH006: Protected branch update failed for refs/heads/private-name.',
            'BRANCH_PROTECTED')

    def test_push_protection_precedes_generic_gh013_branch_rule(self):
        for message in [b'remote: error: GH013: Repository rule violations. Push cannot contain secrets. '
                b'PRIVATE_TOKEN_SENTINEL',b'remote: Push protection blocked private-file:42']:
            with self.subTest(message=message):self.check(message,'PUSH_PROTECTION')

    def test_repository_not_found_requires_known_message(self):
        self.check(b'remote: Repository not found. PRIVATE_TOKEN_SENTINEL','REPOSITORY_NOT_FOUND')
        self.check(b'private endpoint returned HTTP 404 PRIVATE_TOKEN_SENTINEL','UNKNOWN_PRIVATE')

    def test_dns_failure(self):
        self.check(b'fatal: unable to access private URL: Could not resolve host: github.com','NETWORK_DNS')

    def test_tls_failure(self):
        self.check(b'fatal: unable to access private URL: SSL certificate problem: certificate has expired',
            'NETWORK_TLS')

    def test_network_timeout(self):
        self.check(b'fatal: unable to access private URL: Connection timed out after 30000 milliseconds',
            'NETWORK_TIMEOUT')

    def test_remote_lease_changed(self):
        self.check(b'error: failed to push some refs to private URL','REMOTE_CHANGED',
            stdout=b'!\tprivate-commit:refs/heads/private-name\t[rejected] (stale info)\n')

    def test_unknown_failure_is_finite_private_category(self):
        for private in [b'PRIVATE_TOKEN_SENTINEL',b'{"token":"PRIVATE_TOKEN_SENTINEL"}',
                b'\x00\xffPRIVATE_TOKEN_SENTINEL\x80',b'/private/path/to/credentials']:
            with self.subTest(payload=private):self.check(private,'UNKNOWN_PRIVATE')

    def test_success_overrides_private_progress_output(self):
        self.check(b'PRIVATE_PROGRESS_SENTINEL','PUSH_SUCCEEDED',code=0,
            stdout=b'PRIVATE_SUCCESS_STDOUT')

class LivePushBindingTests(unittest.TestCase):
    token='PRIVATE_TOKEN_SENTINEL_8b3fd'

    def setUp(self):
        spec=importlib.util.spec_from_file_location('original_push_binding_worker',
            REPOSITORY/'deployment/go_erp_live_updates.py')
        self.worker=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.worker)
        self.original=self.worker.Git;self.calls=[]
        self.bound=subject.classified_git(self.worker)
        self.git=self.bound.__new__(self.bound)
        self.git.work=Path('/private/work');self.git.token=self.token
        self.git.askpass=Path('/private/state/git-askpass');self.git.heartbeat=lambda:None
        self.candidate=Path('/private/staging/candidate')

    def process(self,code,stdout=b'',stderr=b''):
        def launch(command,**options):
            self.calls.append((command,options))
            options['stdout'].write(stdout);options['stdout'].flush()
            options['stderr'].write(stderr);options['stderr'].flush()
            return types.SimpleNamespace(returncode=code,poll=lambda:code,wait=lambda **kwargs:code)
        return launch

    def arguments(self):
        return ['push','--porcelain','--force-with-lease=refs/heads/'+self.worker.BRANCH+':'+('2'*40),
            'origin',('1'*40)+':refs/heads/'+self.worker.BRANCH]

    def test_subclass_preserves_original_class_and_nonpush_run(self):
        self.assertTrue(issubclass(self.bound,self.original))
        self.assertIs(self.worker.Git,self.original)
        with mock.patch.object(self.original,'run',autospec=True,return_value=b'ORIGINAL_RESULT') as original:
            result=self.git.run(['status','--porcelain'],self.candidate)
        self.assertEqual(result,b'ORIGINAL_RESULT');original.assert_called_once()
        self.assertIs(original.call_args.args[0],self.git)
        self.assertEqual(original.call_args.args[1],['status','--porcelain'])

    def test_push_uses_original_normal_command_and_guarded_environment(self):
        arguments=self.arguments()
        with mock.patch.object(subprocess,'Popen',side_effect=self.process(0,b'PRIVATE_STDOUT_SENTINEL')):
            result=self.git.run(arguments,self.candidate)
        self.assertEqual(result,b'PRIVATE_STDOUT_SENTINEL');self.assertEqual(len(self.calls),1)
        command,options=self.calls[0]
        self.assertEqual(command,['/usr/bin/git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false',
            '-c','credential.helper=','-c','http.followRedirects=false','-c','http.sslVerify=true',*arguments])
        self.assertNotIn('--dry-run',command);self.assertNotIn(self.token,' '.join(command))
        self.assertEqual(options['cwd'],self.candidate);self.assertTrue(options['start_new_session'])
        env=options['env'];self.assertEqual(env['GO_ERP_GIT_TOKEN'],self.token)
        self.assertEqual(env['GIT_ASKPASS'],str(self.git.askpass));self.assertEqual(env['GIT_TERMINAL_PROMPT'],'0')
        self.assertEqual(env['GIT_CONFIG_NOSYSTEM'],'1');self.assertEqual(env['GIT_CONFIG_GLOBAL'],'/dev/null')
        self.assertEqual(self.git.last_push_category,'PUSH_SUCCEEDED')
        self.assertEqual(self.git.last_push_exit_code,0);self.assertTrue(self.git.push_attempted)
        self.assertIs(self.worker.Git,self.original)

    def test_nonzero_push_preserves_original_error_and_only_retains_finite_category(self):
        stderr=(b'remote: refusing to allow a Personal Access Token to create or update workflow '
            b'`.github/workflows/private-build.yml` without `workflow` scope '+self.token.encode())
        with mock.patch.object(subprocess,'Popen',side_effect=self.process(1,b'PRIVATE_STDOUT_SENTINEL',stderr)):
            with self.assertRaises(self.worker.WatchError) as captured:
                self.git.run(self.arguments(),self.candidate)
        self.assertEqual(str(captured.exception),'GO_ERP_LIVE_GIT_OPERATION_FAILED')
        self.assertEqual(self.git.last_push_category,'WORKFLOW_PERMISSION_REQUIRED')
        self.assertEqual(self.git.last_push_exit_code,1)
        encoded=json.dumps({'category':self.git.last_push_category,'exit_code':self.git.last_push_exit_code,
            'error':str(captured.exception)})
        for value in [self.token,'PRIVATE_STDOUT_SENTINEL','private-build','.github/']:
            self.assertNotIn(value,encoded)

    def test_unknown_push_binary_json_and_token_never_enter_public_error_fields(self):
        for payload in [self.token.encode(),b'{"token":"'+self.token.encode()+b'"}',
                b'\x00\xffPRIVATE_BINARY_SENTINEL\x80'+self.token.encode()]:
            with self.subTest(payload=payload):
                with mock.patch.object(subprocess,'Popen',side_effect=self.process(1,payload,payload)):
                    with self.assertRaises(self.worker.WatchError) as captured:
                        self.git.run(self.arguments(),self.candidate)
                self.assertEqual(self.git.last_push_category,'UNKNOWN_PRIVATE')
                encoded=json.dumps({'category':self.git.last_push_category,'error':str(captured.exception)})
                self.assertNotIn(self.token,encoded);self.assertNotIn('PRIVATE_BINARY_SENTINEL',encoded)

    def test_push_scope_or_shape_changes_are_refused_before_process_creation(self):
        valid=self.arguments()
        invalid=[['push','--dry-run',*valid[1:]],['push','--porcelain','origin','1'*40],
            [*valid[:-1],('1'*40)+':refs/heads/unapproved'],
            [*valid[:2],'--force-with-lease=refs/heads/unapproved:'+('2'*40),*valid[3:]]]
        with mock.patch.object(subprocess,'Popen') as launch:
            for arguments in invalid:
                with self.subTest(arguments=arguments):
                    with self.assertRaises(self.worker.WatchError):self.git.run(arguments,self.candidate)
            launch.assert_not_called()
        self.assertFalse(self.git.push_attempted)


def pending_state(worker):
    inventory = '2'*64
    return {
        'format': 1, 'base_revision': '3'*40, 'counter': 7,
        'desired': {
            'status': 'prepared', 'pushed': False, 'candidatePolicy': subject.POLICY,
            'commit': '1'*40, 'inventorySha256': inventory,
            'version': '0.4.4-preview.7', 'retry_at': 999, 'failures': 2,
            'candidatePath': str(worker.WORK/'staging'/('candidate-7-'+inventory[:16])),
        },
        'dependency_guard': {'sha256': '4'*64},
        'observed': {'PRIVATE_SOURCE_SENTINEL': True}, 'published': None,
    }


class EffectiveRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.runtime = Path('/opt/go-erp-live-updates/releases')/subject.RUNTIME_NAME
        self.helper = types.SimpleNamespace(
            UNIT=Path('/etc/systemd/system/go-erp-live-updates.service'),
            CONFIG=Path('/etc/go-erp/live-updates.json'))
        command = '/usr/bin/python3 -I -B '+str(self.runtime/'go_erp_live_updates.py')
        command += ' --config '+str(self.helper.CONFIG)+' --once'
        self.properties = {
            'FragmentPath': str(self.helper.UNIT), 'DropInPaths': '',
            'NeedDaemonReload': 'no', 'ActiveState': 'inactive',
            'ExecStart': '{ path=/usr/bin/python3 ; argv[]='+command+
                ' ; ignore_errors=no ; start_time=[n/a] ; stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }',
        }

    def test_exact_effective_runtime_accepts_idle_or_failed_service(self):
        for state in ('inactive', 'failed'):
            with self.subTest(state=state):
                self.properties['ActiveState'] = state
                subject.validate_unit_properties(self.properties, self.runtime, self.helper)

    def test_stale_systemd_state_dropins_and_foreign_unit_are_rejected(self):
        for key, value in (
                ('FragmentPath', '/private/other.service'),
                ('DropInPaths', '/etc/systemd/system/go-erp-live-updates.service.d/override.conf'),
                ('NeedDaemonReload', 'yes'), ('ActiveState', 'activating'),
                ('ActiveState', 'active'), ('ActiveState', 'deactivating')):
            properties = dict(self.properties); properties[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(subject.Stop):
                subject.validate_unit_properties(properties, self.runtime, self.helper)

    def test_old_runtime_extra_command_and_altered_arguments_are_rejected(self):
        valid = self.properties['ExecStart']
        invalid = [
            valid.replace(subject.RUNTIME_NAME, '75c3fe50b3609ef5822904a23d7c4ed0afd07eff'),
            valid+' '+valid,
            valid.replace(' --once ;', ' --once --unsafe ;'),
            valid.replace('path=/usr/bin/python3 ;', 'path=/bin/sh ;'),
            valid.replace(' --config '+str(self.helper.CONFIG), ' --config /private/config'),
            '',
        ]
        for value in invalid:
            properties = dict(self.properties, ExecStart=value)
            with self.subTest(value=value), self.assertRaises(subject.Stop):
                subject.validate_unit_properties(properties, self.runtime, self.helper)


class PendingCandidateTests(unittest.TestCase):
    def setUp(self):
        self.worker = types.SimpleNamespace(WORK=Path('/private/work'),
            CANDIDATE_POLICY=subject.POLICY)
        self.state = pending_state(self.worker)

    def test_exact_pending_identity_binds_counter_hash_path_and_policy(self):
        desired, path = subject.verify_pending(self.state, self.worker)
        self.assertIs(desired, self.state['desired'])
        self.assertEqual(path, Path(desired['candidatePath']))

    def test_unprepared_pushed_stale_policy_and_changed_paths_are_refused(self):
        invalid = [
            ('status', 'waiting-ci'), ('status', 'blocked'), ('pushed', True),
            ('pushed', 0), ('candidatePolicy', 'old-policy'),
            ('commit', self.state['base_revision']), ('commit', 'PRIVATE_COMMIT'),
            ('inventorySha256', 'PRIVATE_INVENTORY'),
            ('candidatePath', '/private/other-candidate'),
            ('candidatePath', str(self.worker.WORK/'staging'/'candidate-8-2222222222222222')),
        ]
        for key, value in invalid:
            state = copy.deepcopy(self.state); state['desired'][key] = value
            with self.subTest(key=key, value=value), self.assertRaises(subject.Stop):
                subject.verify_pending(state, self.worker)
        for counter in (0, -1, True):
            state = copy.deepcopy(self.state); state['counter'] = counter
            with self.subTest(counter=counter), self.assertRaises(subject.Stop):
                subject.verify_pending(state, self.worker)

    def test_state_report_omits_private_fields_and_invalid_typed_values(self):
        state = copy.deepcopy(self.state)
        state['PRIVATE_TOKEN_SENTINEL'] = 'secret'
        state['desired']['token'] = 'PRIVATE_TOKEN_SENTINEL'
        report = subject.safe_state(state)
        encoded = json.dumps(report, allow_nan=False)
        self.assertEqual(report['commit'], self.state['desired']['commit'])
        for private in ('PRIVATE_', '/private/', 'candidatePath', 'inventorySha256', 'dependency_guard'):
            self.assertNotIn(private, encoded)
        invalid = {'commit': 'PRIVATE_TOKEN_SENTINEL', 'version': 'PRIVATE_TOKEN_SENTINEL',
            'status': 'PRIVATE_TOKEN_SENTINEL', 'failures': True, 'pushed': 1,
            'retry_at': float('nan'), 'candidatePolicy': 'PRIVATE_TOKEN_SENTINEL'}
        state['desired'].update(invalid)
        report = subject.safe_state(state)
        for key in invalid:
            self.assertNotIn(key, report)
        self.assertNotIn('PRIVATE_', json.dumps(report, allow_nan=False))


class ExistingCandidateVerificationTests(unittest.TestCase):
    def fixture(self):
        worker = types.SimpleNamespace(WORK=Path('/private/work'), CANDIDATE_POLICY=subject.POLICY)
        state = pending_state(worker); desired = state['desired']
        snapshot = {'metadata': {'inventory': {'a': {}, 'b': {}}}}
        worker.dependency_snapshot = mock.Mock(return_value=state['dependency_guard'])
        worker.source_identity = mock.Mock(return_value=desired['inventorySha256'])
        result = {key: desired[key] for key in ('commit', 'version', 'inventorySha256', 'candidatePolicy')}
        result['reused'] = True
        source = types.SimpleNamespace(
            _trusted_git=mock.Mock(return_value=desired['commit']),
            prepare_candidate=mock.Mock(return_value=result))
        instance = types.SimpleNamespace(live=Path('/private/live'), web_uid=1001,
            scan=mock.Mock(return_value=snapshot))
        return worker, state, source, instance, snapshot

    def test_existing_candidate_only_checks_reused_commit_against_fresh_source(self):
        worker, state, source, instance, snapshot = self.fixture()
        self.assertEqual(subject.verify_candidate(instance, state, source, worker), 2)
        source.prepare_candidate.assert_called_once_with(
            Path(state['desired']['candidatePath']), snapshot, state['counter'])
        worker.dependency_snapshot.assert_called_once_with(instance.live, 1001)

    def test_seed_or_wrong_head_is_refused_before_candidate_preparation(self):
        for head in ('3'*40, '9'*40):
            worker, state, source, instance, _ = self.fixture()
            source._trusted_git.return_value = head
            with self.subTest(head=head), self.assertRaises(subject.Stop):
                subject.verify_candidate(instance, state, source, worker)
            source.prepare_candidate.assert_not_called(); instance.scan.assert_not_called()

    def test_dependency_or_source_drift_prevents_candidate_preparation(self):
        for cause in ('dependency', 'source'):
            worker, state, source, instance, _ = self.fixture()
            if cause == 'dependency': worker.dependency_snapshot.return_value = {'changed': True}
            else: worker.source_identity.return_value = '9'*64
            with self.subTest(cause=cause), self.assertRaises(subject.Stop):
                subject.verify_candidate(instance, state, source, worker)
            source.prepare_candidate.assert_not_called()

    def test_verifier_rejects_nonreused_or_mismatching_commit_version_and_policy(self):
        for field, value in (('reused', False), ('reused', 1), ('commit', '9'*40),
                ('version', '0.4.4-preview.8'), ('inventorySha256', '9'*64),
                ('candidatePolicy', 'old-policy')):
            worker, state, source, instance, _ = self.fixture()
            source.prepare_candidate.return_value[field] = value
            with self.subTest(field=field), self.assertRaises(subject.Stop):
                subject.verify_candidate(instance, state, source, worker)


class PreparedPushRetryTests(unittest.TestCase):
    def fixture(self, push_failure=False, constructor_failure=False, post_guard_failure=False):
        events, holder = [], {}
        class OriginalGit:
            def run(self, *args, **kwargs):
                raise AssertionError('Unexpected Git operation')
        class WatchError(Exception): pass
        class SourceError(Exception): pass
        class HelperStop(Exception): pass
        worker = types.SimpleNamespace(Git=OriginalGit, WORK=Path('/private/work'),
            CANDIDATE_POLICY=subject.POLICY, WatchError=WatchError)
        state = pending_state(worker); disk = copy.deepcopy(state)
        raw = json.dumps(state, sort_keys=True).encode()
        def read_config():
            events.append('read-config')
            return {'base_revision': state['base_revision'], 'token': 'PRIVATE_CONFIG_TOKEN'}
        worker.read_config = mock.Mock(side_effect=read_config)
        @contextlib.contextmanager
        def watcher_lock(path):
            self.assertEqual(path, worker.WORK); events.append('lock-enter')
            try: yield lambda: None
            finally: events.append('lock-exit')
        class Directory:
            def __init__(self, path, mode):
                self_path = worker.WORK/'checkout'
                if path != self_path or mode != 0o700:
                    raise AssertionError('Unexpected directory')
                events.append('seed-check')
            def close(self): events.append('seed-close')
        helper = types.SimpleNamespace(BASE=state['base_revision'], Stop=HelperStop,
            watcher_lock=watcher_lock, Directory=Directory)
        source = types.SimpleNamespace(SourceError=SourceError)
        class NoPublication:
            def __getattr__(self, name):
                raise AssertionError('Installer publication is outside this retry')
        publisher = NoPublication()
        class Worker:
            def __init__(self, config, given_source, given_publisher):
                events.append('construct'); holder['constructor_git'] = worker.Git
                if constructor_failure: raise RuntimeError('PRIVATE_CONSTRUCTOR_TOKEN')
                self.state = copy.deepcopy(disk); self.git = worker.Git()
                holder['instance'] = self
                self.live = Path('/private/live'); self.web_uid = 1001
            def guard_live(self, expected, force=False):
                if expected != state['desired']['inventorySha256'] or force is not True:
                    raise AssertionError('Original forced source guard omitted')
                events.append('guard')
                if post_guard_failure and events.count('guard') == 2:
                    raise WatchError('GO_ERP_LIVE_SOURCE_SUPERSEDED')
            def push(self, desired):
                events.append('push'); holder['retry_at_at_push'] = desired['retry_at']
                self.git.push_attempted = True
                self.git.last_push_category = 'WORKFLOW_PERMISSION_REQUIRED' if push_failure else 'PUSH_SUCCEEDED'
                self.git.last_push_exit_code = 1 if push_failure else 0
                if push_failure: raise WatchError('GO_ERP_LIVE_GIT_OPERATION_FAILED')
                desired.update(pushed=True, status='waiting-ci')
                self.state['last_remote_sha'] = desired['commit']
                disk.clear(); disk.update(copy.deepcopy(self.state))
                events.append('original-state-save')
            def tick(self): raise AssertionError('tick must never run')
            def fetch_accepted(self, *args): raise AssertionError('Artifacts must never be fetched')
            def prepare(self, *args): raise AssertionError('Replacement candidate must never be built')
            def save(self):
                if (events.count('guard') != 2 or self.state['desired']['pushed'] is not True
                        or self.state['desired']['retry_at'] != 0):
                    raise AssertionError('Backoff cannot change before confirmed push and post-guard')
                disk.clear(); disk.update(copy.deepcopy(self.state))
                events.append('backoff-state-save')
        worker.Worker = Worker
        def read(*args):
            self.assertEqual(args, (helper, worker)); events.append('read-state')
            return raw, copy.deepcopy(state)
        def verify(*args):
            self.assertIs(args[0], holder['instance']); events.append('verify-candidate')
            return 2540
        def runtime(): events.append('runtime-check')
        return types.SimpleNamespace(worker=worker, helper=helper, source=source, publisher=publisher,
            events=events, holder=holder, state=state, disk=disk, raw=raw,
            read=read, verify=verify, runtime=runtime, original=OriginalGit)

    def run_retry(self, f, **overrides):
        with mock.patch.object(subject, 'read_state', side_effect=overrides.get('read', f.read)), \
                mock.patch.object(subject, 'verify_candidate', side_effect=overrides.get('verify', f.verify)):
            return subject.retry_prepared(f.helper, f.source, f.worker, f.publisher,
                overrides.get('runtime', f.runtime))

    def test_only_existing_push_runs_under_lock_and_exact_guards(self):
        f = self.fixture(); report = self.run_retry(f)
        self.assertTrue(report['ok'], report)
        self.assertEqual(f.events, ['lock-enter', 'runtime-check', 'read-state', 'seed-check',
            'seed-close', 'read-config', 'construct', 'read-state', 'verify-candidate',
            'runtime-check', 'guard', 'push', 'original-state-save', 'guard',
            'backoff-state-save', 'lock-exit'])
        self.assertEqual(f.holder['retry_at_at_push'], 999)
        self.assertEqual(f.state['desired']['retry_at'], 999)
        self.assertEqual(f.disk['desired']['status'], 'waiting-ci')
        self.assertEqual(f.disk['desired']['retry_at'], 0)
        self.assertTrue(issubclass(f.holder['constructor_git'], f.original))
        self.assertIsNot(f.holder['constructor_git'], f.original)
        self.assertIs(f.worker.Git, f.original)
        self.assertEqual(report['category'], 'PUSH_SUCCEEDED')
        self.assertEqual(report['outcome'], 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertTrue(report['remote_write_attempted']); self.assertTrue(report['credentials_used'])
        for key in ('installer_publication_attempted', 'replacement_candidate_created',
                'application_contents_changed', 'database_changed', 'credentials_disclosed'):
            self.assertFalse(report[key])
        self.assertNotIn('PRIVATE_', json.dumps(report)); self.assertNotIn('/private/', json.dumps(report))

    def test_failed_push_preserves_durable_backoff_and_restores_original_git(self):
        f = self.fixture(push_failure=True); before = copy.deepcopy(f.disk)
        report = self.run_retry(f)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'PUSH')
        self.assertEqual(report['category'], 'WORKFLOW_PERMISSION_REQUIRED')
        self.assertEqual(report['exit_code'], 1); self.assertTrue(report['remote_write_attempted'])
        self.assertEqual(f.holder['retry_at_at_push'], 999); self.assertEqual(f.disk, before)
        self.assertEqual(f.events.count('push'), 1); self.assertEqual(f.events.count('guard'), 1)
        self.assertNotIn('original-state-save', f.events)
        self.assertIs(f.worker.Git, f.original); self.assertEqual(f.events[-1], 'lock-exit')
        self.assertNotIn('PRIVATE_', json.dumps(report))

    def test_source_drift_after_push_preserves_confirmed_state_and_existing_backoff(self):
        f = self.fixture(post_guard_failure=True); report = self.run_retry(f)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'GUARD_AFTER_PUSH')
        self.assertTrue(f.disk['desired']['pushed'])
        self.assertEqual(f.disk['desired']['status'], 'waiting-ci')
        self.assertEqual(f.disk['desired']['retry_at'], 999)
        self.assertNotIn('backoff-state-save', f.events)
        self.assertEqual(report['reason'], 'GO_ERP_LIVE_SOURCE_SUPERSEDED')
        self.assertIs(f.worker.Git, f.original)

    def test_stale_policy_and_already_pushed_state_stop_before_credentials(self):
        for field, value in (('candidatePolicy', 'old-policy'), ('pushed', True), ('status', 'blocked')):
            f = self.fixture(); f.state['desired'][field] = value
            with self.subTest(field=field):
                report = self.run_retry(f)
                self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'STATE')
                self.assertFalse(report['credentials_used']); f.worker.read_config.assert_not_called()
                self.assertNotIn('construct', f.events); self.assertNotIn('push', f.events)
                self.assertIs(f.worker.Git, f.original)

    def test_effective_runtime_mismatch_stops_before_state_credentials_or_git(self):
        f = self.fixture()
        def mismatch(): raise subject.Stop('EFFECTIVE_RUNTIME_MISMATCH')
        report = self.run_retry(f, runtime=mismatch)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'RUNTIME')
        self.assertFalse(report['credentials_used']); self.assertFalse(report['remote_write_attempted'])
        f.worker.read_config.assert_not_called()
        self.assertEqual(f.events, ['lock-enter', 'lock-exit']); self.assertIs(f.worker.Git, f.original)

    def test_state_change_during_worker_construction_prevents_push(self):
        f = self.fixture(); calls = 0
        def changing(*args):
            nonlocal calls
            calls += 1
            raw, state = f.read(*args)
            return (raw if calls == 1 else raw+b' '), state
        report = self.run_retry(f, read=changing)
        self.assertFalse(report['ok']); self.assertNotIn('verify-candidate', f.events)
        self.assertNotIn('push', f.events); self.assertIs(f.worker.Git, f.original)

    def test_candidate_verification_error_cannot_reach_push_or_publish(self):
        f = self.fixture()
        def mismatch(*args): raise subject.Stop('CANDIDATE_HEAD_MISMATCH')
        report = self.run_retry(f, verify=mismatch)
        self.assertFalse(report['ok']); self.assertEqual(report['phase'], 'VERIFY_CANDIDATE')
        self.assertNotIn('guard', f.events); self.assertNotIn('push', f.events)
        self.assertEqual(f.disk, f.state); self.assertIs(f.worker.Git, f.original)

    def test_constructor_failure_never_leaks_private_error_or_keeps_binding(self):
        f = self.fixture(constructor_failure=True); report = self.run_retry(f)
        self.assertFalse(report['ok']); self.assertEqual(report['reason'], 'UNKNOWN_PRIVATE')
        self.assertNotIn('PRIVATE_CONSTRUCTOR_TOKEN', json.dumps(report))
        self.assertNotIn('push', f.events); self.assertEqual(f.events[-1], 'lock-exit')
        self.assertIs(f.worker.Git, f.original)


class FiniteReasonTests(unittest.TestCase):
    def test_unknown_exception_payloads_are_not_public_reason_codes(self):
        class HelperStop(Exception): pass
        class SourceError(Exception): pass
        class WatchError(Exception): pass
        helper = types.SimpleNamespace(Stop=HelperStop)
        source = types.SimpleNamespace(SourceError=SourceError)
        worker = types.SimpleNamespace(WatchError=WatchError)
        for error in (RuntimeError('PRIVATE_TOKEN_SENTINEL'),
                subject.Stop('PRIVATE_TOKEN_SENTINEL'), HelperStop('PRIVATE_TOKEN_SENTINEL'),
                SourceError('GO_ERP_LIVE_PRIVATE_TOKEN_SENTINEL'),
                WatchError('GO_ERP_LIVE_PRIVATE_TOKEN_SENTINEL')):
            with self.subTest(error=type(error).__name__):
                self.assertEqual(subject.safe_reason(error, helper, source, worker), 'UNKNOWN_PRIVATE')

    def test_native_superseded_error_has_one_public_code_and_hides_other_subclasses(self):
        spec = importlib.util.spec_from_file_location('current_push_superseded_worker',
            REPOSITORY/'deployment/go_erp_live_updates.py')
        worker = importlib.util.module_from_spec(spec); spec.loader.exec_module(worker)
        class HelperStop(Exception): pass
        class SourceError(Exception): pass
        class UnreviewedSuperseded(worker.Superseded): pass
        helper = types.SimpleNamespace(Stop=HelperStop)
        source = types.SimpleNamespace(SourceError=SourceError)
        accepted = 'GO_ERP_LIVE_SOURCE_SUPERSEDED'
        self.assertEqual(subject.safe_reason(worker.Superseded(accepted), helper, source, worker), accepted)
        for error in (worker.Superseded('GO_ERP_LIVE_PRIVATE_TOKEN_SENTINEL'),
                worker.Superseded('PRIVATE_TOKEN_SENTINEL'),
                UnreviewedSuperseded(accepted)):
            with self.subTest(error=type(error).__name__, message=str(error)):
                self.assertEqual(subject.safe_reason(error, helper, source, worker), 'UNKNOWN_PRIVATE')


class RuntimePreflightReportingTests(unittest.TestCase):
    class HelperStop(Exception): pass

    def preflight(self, failure):
        helper = types.SimpleNamespace(Stop=self.HelperStop)
        read_config = mock.Mock(side_effect=AssertionError('Credentials must not be read'))
        worker = types.SimpleNamespace(read_config=read_config)
        @contextlib.contextmanager
        def unavailable(given_helper):
            self.assertIs(given_helper, helper)
            raise failure
            yield object(), worker, object(), lambda: None
        output = io.StringIO()
        with mock.patch.object(subject.os, 'geteuid', return_value=0), \
                mock.patch.object(subject.socket, 'gethostname', return_value='srv1836524'), \
                mock.patch.object(subject, 'private_helper', return_value=helper), \
                mock.patch.object(subject, 'installed_current', side_effect=unavailable), \
                mock.patch.object(subject, 'retry_prepared') as retry, \
                contextlib.redirect_stdout(output):
            result = subject.main(['/private/verified-helper.py', '--retry-prepared'])
        retry.assert_not_called(); read_config.assert_not_called()
        self.assertEqual(result, 1)
        report = json.loads(output.getvalue())
        self.assertEqual(report['phase'], 'PREFLIGHT'); self.assertFalse(report['ok'])
        self.assertFalse(report['credentials_disclosed'])
        self.assertNotIn('PRIVATE_', output.getvalue()); self.assertNotIn('/private/', output.getvalue())
        return report

    def test_helper_runtime_failure_preserves_only_whitelisted_public_reason(self):
        for message, expected in (
                ('RUNTIME_PIN_INVALID', 'GO_ERP_CURRENT_PUSH_RUNTIME_PIN_INVALID'),
                ('DIRECTORY_UNSAFE', 'GO_ERP_CURRENT_PUSH_DIRECTORY_UNSAFE'),
                ('PRIVATE_TOKEN_SENTINEL', 'GO_ERP_CURRENT_PUSH_RUNTIME_PREFLIGHT_FAILED')):
            with self.subTest(message=message):
                report = self.preflight(self.HelperStop(message))
                self.assertEqual(report['reason'], expected)

    def test_missing_runtime_paths_produce_finite_reason_before_credentials(self):
        for error in (FileNotFoundError('/private/PRIVATE_TOKEN_SENTINEL'),
                NotADirectoryError('/private/PRIVATE_TOKEN_SENTINEL')):
            with self.subTest(error=type(error).__name__):
                report = self.preflight(error)
                self.assertEqual(report['reason'], 'GO_ERP_CURRENT_PUSH_RUNTIME_FILES_MISSING')


if __name__ == '__main__':
    unittest.main()

