#!/usr/bin/env python3
"""Original-worker push retry retains guards and reports finite public reasons."""
import importlib.util
import contextlib
import copy
import json
from pathlib import Path
import subprocess
import types
import unittest
from unittest import mock

REPOSITORY=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('live_push_diagnosis_subject',
    REPOSITORY/'deployment/diagnose_go_erp_live_push_root.py')
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

    def test_protected_branch(self):
        self.check(b'remote: error: GH006: Protected branch update failed for refs/heads/private-name.',
            'BRANCH_PROTECTED')

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

class LivePushRetryTests(unittest.TestCase):
    def fixture(self,pushed=False,tick_failure=False,constructor_failure=False):
        events=[];holder={};base='3'*40
        original_state={'counter':7,'desired':{'prepared':True,'pushed':pushed,'retry_at':999,
            'status':'prepared'},'observed':{'PRIVATE_SOURCE':True},'published':None}
        class OriginalGit:
            def __init__(self):pass
            def run(self,*args,**kwargs):raise AssertionError('Unexpected Git operation')
        worker=types.SimpleNamespace(Git=OriginalGit,WORK=Path('/private/work'),BASE=base,
            CONFIG=Path('/private/config.json'))
        class WatchError(Exception):pass
        worker.WatchError=WatchError
        def read_config(*args,**kwargs):
            events.append('read-config');return {'base_revision':base,'token':'PRIVATE_RETRY_TOKEN'}
        worker.read_config=read_config
        class OriginalWorker:
            def __init__(self,*args,**kwargs):
                events.append('construct');holder['constructor_git']=worker.Git
                if constructor_failure:raise RuntimeError('PRIVATE_CONSTRUCTOR_TOKEN')
                self.state=copy.deepcopy(original_state);self.git=worker.Git();holder['instance']=self
            def tick(self):
                events.append('tick');holder['retry_at']=self.state['desired']['retry_at']
                self.git.push_attempted=True;self.git.last_push_exit_code=1 if tick_failure else 0
                self.git.last_push_category='WORKFLOW_PERMISSION_REQUIRED' if tick_failure else 'PUSH_SUCCEEDED'
                if tick_failure:raise WatchError('GO_ERP_LIVE_GIT_OPERATION_FAILED')
                self.state['desired'].update(pushed=True,status='waiting-ci');return 'GO_ERP_LIVE_UPDATE_WAITING_CI'
        worker.Worker=OriginalWorker
        @contextlib.contextmanager
        def existing_lock(path):
            self.assertEqual(path,worker.WORK);events.append('lock-enter')
            try:yield
            finally:events.append('lock-exit')
        def safe_state(value):
            state=getattr(value,'state',value)
            return {'counter':state['counter'],'desired':bool(state['desired']),
                'observed':bool(state['observed']),'published':bool(state['published'])}
        doc={'existing_lock':existing_lock,'BASE':base,'safe_state':safe_state}
        return worker,doc,events,holder,original_state,OriginalGit

    def test_original_worker_ticks_once_under_lock_with_temporary_binding_and_memory_retry(self):
        worker,doc,events,holder,state,original=self.fixture()
        report=subject.retry_once(doc,object(),worker,object())
        self.assertEqual(events,['lock-enter','read-config','construct','tick','lock-exit'])
        self.assertTrue(issubclass(holder['constructor_git'],original))
        self.assertIsNot(holder['constructor_git'],original);self.assertIs(worker.Git,original)
        self.assertEqual(holder['retry_at'],0);self.assertEqual(state['desired']['retry_at'],999)
        self.assertTrue(report['ok']);self.assertEqual(report['outcome'],'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(report['category'],'PUSH_SUCCEEDED');self.assertTrue(report['remote_write_attempted'])
        self.assertTrue(report['credentials_used']);self.assertFalse(report['credentials_disclosed'])
        self.assertNotIn('PRIVATE_',json.dumps(report));self.assertNotIn('/private/',json.dumps(report))

    def test_push_failure_returns_filtered_diagnostic_and_always_restores_git(self):
        worker,doc,events,holder,state,original=self.fixture(tick_failure=True)
        report=subject.retry_once(doc,object(),worker,object())
        self.assertFalse(report['ok']);self.assertEqual(report['category'],'WORKFLOW_PERMISSION_REQUIRED')
        self.assertEqual(report['exit_code'],1);self.assertTrue(report['remote_write_attempted'])
        self.assertIs(worker.Git,original);self.assertEqual(events.count('tick'),1)
        self.assertEqual(events[-1],'lock-exit');self.assertNotIn('PRIVATE_',json.dumps(report))

    def test_already_pushed_desired_does_not_clear_backoff(self):
        worker,doc,events,holder,state,original=self.fixture(pushed=True)
        subject.retry_once(doc,object(),worker,object())
        self.assertEqual(holder['retry_at'],999);self.assertIs(worker.Git,original)
        self.assertEqual(events.count('tick'),1)

    def test_constructor_failure_restores_original_git_and_private_message_is_hidden(self):
        worker,doc,events,holder,state,original=self.fixture(constructor_failure=True)
        report=subject.retry_once(doc,object(),worker,object())
        self.assertFalse(report['ok']);self.assertIs(worker.Git,original)
        self.assertNotIn('tick',events);self.assertEqual(events[-1],'lock-exit')
        self.assertNotIn('PRIVATE_CONSTRUCTOR_TOKEN',json.dumps(report))

if __name__=='__main__':unittest.main()
