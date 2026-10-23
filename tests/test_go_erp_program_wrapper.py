"""Execute the real Bash rollout shell against isolated services and an inert package.

Only its fixed work directory is rewritten. Git never connects to a remote and
systemctl never reaches the host; the downloaded adapter is a controlled Python
fixture executed by the wrapper's real /usr/bin/python3 interpreter.
"""
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
WRAPPER = ROOT / 'deployment/run_go_erp_program_root.sh'
REVISION = 'd' * 40

GIT = r'''#!/usr/bin/python3
import os,sys
from pathlib import Path
arguments=sys.argv[1:]
if 'rev-parse' in arguments:
    if arguments[-1]!='FETCH_HEAD':raise SystemExit(90)
    print(os.environ['GO_WRAPPER_FIXTURE_REVISION'])
elif 'show' in arguments:
    specification=arguments[-1]
    if not specification.startswith(os.environ['GO_WRAPPER_FIXTURE_REVISION']+':'):raise SystemExit(91)
    if specification.endswith(':deployment/upgrade_go_erp_program_watcher_root.py'):
        sys.stdout.buffer.write(Path(os.environ['GO_WRAPPER_FIXTURE_ADAPTER']).read_bytes())
elif 'fetch' in arguments or 'init' in arguments:
    pass
else:
    raise SystemExit(92)
'''

SYSTEMCTL = r'''#!/usr/bin/python3
import json,os,sys
from pathlib import Path
arguments=sys.argv[1:]
state_path=Path(os.environ['GO_WRAPPER_FIXTURE_STATE'])
state=json.loads(state_path.read_text())
with open(os.environ['GO_WRAPPER_FIXTURE_EVENTS'],'a',encoding='utf-8') as output:
    output.write(json.dumps(arguments)+'\n')
timer='go-erp-live-updates.timer';service='go-erp-live-updates.service'
if arguments==['show',timer,'-p','ActiveState','--value']:
    print(state['timer'])
elif arguments==['show',service,'-p','ActiveState','--value']:
    print(state['service'])
elif arguments==['stop',timer]:
    state['timer']='inactive'
elif arguments==['start',timer]:
    state['timer']='active'
elif arguments==['start','--no-block',service]:
    state['service']='activating'
elif arguments==['is-active','--quiet',timer]:
    raise SystemExit(0 if state['timer']=='active' else 3)
else:
    raise SystemExit(93)
state_path.write_text(json.dumps(state))
'''

ADAPTER = r'''import json,os,sys
from pathlib import Path
Path(os.environ['GO_WRAPPER_FIXTURE_ADAPTER_CALL']).write_text(json.dumps(sys.argv[1:]))
sys.stdout.buffer.write(Path(os.environ['GO_WRAPPER_FIXTURE_RESULT']).read_bytes())
raise SystemExit(int(os.environ['GO_WRAPPER_FIXTURE_ADAPTER_STATUS']))
'''


@unittest.skipUnless(os.geteuid() == 0, 'The real wrapper explicitly requires root.')
class ProgramWrapperTests(unittest.TestCase):
    def invoke(self, result, mode='--prepare', timer='active', adapter_status=0, raw=False):
        temporary = tempfile.TemporaryDirectory(prefix='go-program-wrapper-')
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        work = root / 'work'
        work.mkdir(mode=0o700)
        os.chown(work, 0, 0)
        bin_directory = root / 'bin'
        bin_directory.mkdir(mode=0o700)
        for name, content in [('git', GIT), ('systemctl', SYSTEMCTL),
                              ('hostname', '#!/usr/bin/env bash\ntest "$#" -eq 1 && test "$1" = -s\nprintf "%s\\n" srv1836524\n')]:
            executable = bin_directory / name
            executable.write_text(content, encoding='utf-8')
            executable.chmod(0o700)
        state = root / 'state.json'
        state.write_text(json.dumps({'timer': timer, 'service': 'inactive'}))
        events = root / 'events.jsonl'
        adapter = root / 'adapter.py'
        adapter.write_text(ADAPTER)
        result_path = root / 'adapter-result.json'
        result_path.write_text(result if raw else json.dumps(result))
        adapter_call = root / 'adapter-call.json'
        original = WRAPPER.read_text(encoding='utf-8')
        fixed_directory = 'go_work=/var/lib/go-erp-live-updates'
        self.assertEqual(original.count(fixed_directory), 1)
        # This fixture must not accidentally permit an absolute host service or
        # network client to bypass the inert executables at the front of PATH.
        for unsafe in ('/usr/bin/systemctl', '/bin/systemctl', '/usr/bin/git', '/bin/git'):
            self.assertNotIn(unsafe, original)
        script = root / 'run.sh'
        script.write_text(original.replace(fixed_directory, 'go_work=' + shlex.quote(str(work))))
        acceptance = root / 'reviewed-receipt.json'
        acceptance.write_text('{"fixture":true}\n')
        arguments = ['/usr/bin/bash', str(script), REVISION, mode]
        if mode == '--publish':
            arguments.append(str(acceptance))
        environment = {
            'PATH': str(bin_directory) + ':/usr/bin:/bin', 'LANG': 'C.UTF-8',
            'GO_WRAPPER_FIXTURE_REVISION': REVISION,
            'GO_WRAPPER_FIXTURE_ADAPTER': str(adapter),
            'GO_WRAPPER_FIXTURE_STATE': str(state),
            'GO_WRAPPER_FIXTURE_EVENTS': str(events),
            'GO_WRAPPER_FIXTURE_RESULT': str(result_path),
            'GO_WRAPPER_FIXTURE_ADAPTER_STATUS': str(adapter_status),
            'GO_WRAPPER_FIXTURE_ADAPTER_CALL': str(adapter_call),
        }
        completed = subprocess.run(arguments, env=environment, stdin=subprocess.DEVNULL,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   text=True, timeout=20, check=False)
        calls = [json.loads(line) for line in events.read_text().splitlines()] if events.exists() else []
        self.assertTrue(adapter_call.is_file(), completed.stdout + completed.stderr)
        invocation = json.loads(adapter_call.read_text())
        self.assertIn(mode, invocation)
        self.assertEqual(invocation.count(mode), 1)
        if mode == '--publish':
            self.assertEqual(invocation[-2:], ['--acceptance-receipt', str(acceptance)])
        return completed, json.loads(state.read_text()), calls, list(work.glob('program-download.*'))

    @staticmethod
    def completed_result():
        return {'ok': True, 'kind': 'go-erp-program-already-completed',
                'worker_request': 'none', 'resume_previous_timer': True, 'publication_held': False}

    def assert_no_worker(self, calls):
        self.assertFalse(any(call[:1] == ['start'] and 'go-erp-live-updates.service' in call for call in calls))
        self.assertFalse(any(call[:1] == ['stop'] and 'go-erp-live-updates.service' in call for call in calls))

    def assert_held(self, outcome, expected_status=None):
        completed, state, calls, stages = outcome
        if expected_status is None:
            self.assertNotEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        else:
            self.assertEqual(completed.returncode, expected_status, completed.stdout + completed.stderr)
        self.assertEqual(state, {'timer': 'inactive', 'service': 'inactive'})
        self.assert_no_worker(calls)
        self.assertNotIn(['start', 'go-erp-live-updates.timer'], calls)
        self.assertEqual(len(stages), 1, 'An uncertain rollout package must remain available for recovery.')
        self.assertIn('GO_ERP_TIMER_LEFT_PAUSED_PUBLICATION_HELD', completed.stderr)

    def test_completed_prepare_and_publish_restore_previously_active_timer_without_worker(self):
        for mode in ('--prepare', '--publish'):
            for prior in ('active', 'activating', 'reloading'):
                with self.subTest(mode=mode, prior=prior):
                    completed, state, calls, stages = self.invoke(self.completed_result(), mode, prior)
                    self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
                    self.assertEqual(state, {'timer': 'active', 'service': 'inactive'})
                    self.assert_no_worker(calls)
                    self.assertEqual(calls.count(['start', 'go-erp-live-updates.timer']), 1)
                    self.assertEqual(stages, [])
                    self.assertIn('GO_ERP_PROGRAM_ALREADY_COMPLETED_NO_WORKER', completed.stdout)
                    self.assertNotIn('GO_ERP_TIMER_LEFT_PAUSED_PUBLICATION_HELD', completed.stderr)

    def test_completed_prepare_and_publish_preserve_previously_inactive_timer(self):
        for mode in ('--prepare', '--publish'):
            for prior in ('inactive', 'failed'):
                with self.subTest(mode=mode, prior=prior):
                    completed, state, calls, stages = self.invoke(self.completed_result(), mode, prior)
                    self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
                    self.assertEqual(state, {'timer': 'inactive', 'service': 'inactive'})
                    self.assert_no_worker(calls)
                    self.assertNotIn(['start', 'go-erp-live-updates.timer'], calls)
                    self.assertEqual(stages, [])
                    self.assertIn('GO_ERP_PROGRAM_ALREADY_COMPLETED_NO_WORKER', completed.stdout)
                    self.assertNotIn('GO_ERP_TIMER_LEFT_PAUSED_PUBLICATION_HELD', completed.stderr)

    def test_prepare_requests_one_candidate_worker_and_keeps_timer_paused(self):
        for prior in ('active', 'inactive'):
            with self.subTest(prior=prior):
                result = {'ok': True, 'worker_request': 'candidate', 'publication_held': True}
                completed, state, calls, stages = self.invoke(result, '--prepare', prior)
                self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
                self.assertEqual(state, {'timer': 'inactive', 'service': 'activating'})
                self.assertEqual(calls.count(['start', '--no-block', 'go-erp-live-updates.service']), 1)
                self.assertNotIn(['start', 'go-erp-live-updates.timer'], calls)
                self.assertEqual(stages, [])
                self.assertIn('GO_ERP_PROGRAM_CANDIDATE_REQUESTED_PUBLICATION_HELD', completed.stdout)

    def test_authorized_publish_resumes_one_worker_and_timer(self):
        for prior in ('active', 'inactive'):
            with self.subTest(prior=prior):
                result = {'ok': True, 'worker_request': 'authorized-publication', 'publication_held': False}
                completed, state, calls, stages = self.invoke(result, '--publish', prior)
                self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
                self.assertEqual(state, {'timer': 'active', 'service': 'activating'})
                self.assertEqual(calls.count(['start', '--no-block', 'go-erp-live-updates.service']), 1)
                self.assertEqual(calls.count(['start', 'go-erp-live-updates.timer']), 1)
                self.assertLess(calls.index(['start', '--no-block', 'go-erp-live-updates.service']),
                                calls.index(['start', 'go-erp-live-updates.timer']))
                self.assertEqual(stages, [])
                self.assertIn('GO_ERP_PROGRAM_TESTED_CANDIDATE_RESUMED', completed.stdout)

    def test_unknown_worker_request_fails_closed_without_a_worker_or_timer(self):
        for mode in ('--prepare', '--publish'):
            with self.subTest(mode=mode):
                self.assert_held(self.invoke({'ok': True, 'worker_request': 'unexpected'}, mode), 78)

    def test_already_completed_requires_all_fixed_completion_evidence(self):
        for change in ({'kind': 'another-kind'}, {'resume_previous_timer': False},
                       {'publication_held': True}, {'ok': False}):
            with self.subTest(change=change):
                result = {**self.completed_result(), **change}
                self.assert_held(self.invoke(result), 78)

    def test_worker_action_for_another_mode_fails_closed(self):
        for mode, action in (('--prepare', 'authorized-publication'), ('--publish', 'candidate')):
            with self.subTest(mode=mode, action=action):
                self.assert_held(self.invoke({'ok': True, 'worker_request': action}, mode), 78)

    def test_malformed_json_keeps_the_timer_paused_and_recovery_package(self):
        for body in ('{invalid-json', '', '[]', 'null'):
            with self.subTest(body=body):
                self.assert_held(self.invoke(body, raw=True))

    def test_failed_adapter_never_interprets_even_a_success_shaped_result(self):
        self.assert_held(self.invoke(self.completed_result(), adapter_status=78), 78)


if __name__ == '__main__':
    unittest.main()
