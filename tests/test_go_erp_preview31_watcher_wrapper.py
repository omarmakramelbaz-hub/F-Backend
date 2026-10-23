"""Execute the real wrapper with isolated service, clock and helper fixtures."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[1]
WRAPPER = REPO / 'deployment/run_go_erp_preview31_watcher_root.sh'
HELPER = REPO / 'deployment/upgrade_go_erp_preview31_watcher_root.py'
TIMER = 'go-erp-live-updates.timer'
SERVICE = 'go-erp-live-updates.service'


def git_blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


class WrapperFixture:
    def __init__(self, timer='active', helper_status=0, busy=False, busy_checks=0,
                 signal_on_sleep=False, restore_fails=False, worker_start_fails=False,
                 service_state=None, tamper_helper=False):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-preview31-wrapper-')
        self.root = Path(self.temporary.name)
        self.bin = self.root / 'bin'; self.bin.mkdir()
        self.package = self.root / 'deployment'; self.package.mkdir(mode=0o700)
        (self.package / 'preview31-watcher').mkdir(mode=0o700)
        self.events = self.root / 'events.jsonl'
        self.config = self.root / 'config.json'
        self.config.write_text(json.dumps({'timer': timer, 'checks': 0, 'busy': busy,
            'busy_checks': busy_checks, 'signal_on_sleep': signal_on_sleep,
            'restore_fails': restore_fails, 'worker_start_fails': worker_start_fails,
            'service_state': service_state}))
        helper = (
            'import json,sys\nfrom pathlib import Path\n'
            'assert len(sys.argv)==3 and sys.argv[2]=="--upgrade"\n'
            'assert Path(sys.argv[1])==Path(__file__).parent/"preview31-watcher"\n'
            'assert Path(sys.argv[1]).is_dir()\n'
            f'with open({str(self.events)!r},"a") as output:\n'
            '    output.write(json.dumps(["helper",sys.argv[1],sys.argv[2]])+"\\n")\n'
            f'print(json.dumps({{"fixture_helper":True,"ok":{helper_status == 0!r}}}))\n'
            f'sys.exit({helper_status})\n'
        ).encode()
        self.helper = self.package / HELPER.name
        self.helper.write_bytes(helper + (b'\n# changed after review\n' if tamper_helper else b''))
        self.helper.chmod(0o644)
        text = WRAPPER.read_text()
        matches = re.findall(r'^go_helper=[a-f0-9]{40}$', text, re.M)
        if len(matches) != 1:
            raise AssertionError('The real wrapper must pin exactly one reviewed helper.')
        text = text.replace(matches[0], 'go_helper=' + git_blob(helper))
        self.wrapper = self.package / WRAPPER.name
        self.wrapper.write_text(text)
        self.executable('id', '#!/bin/sh\nprintf "0\\n"\n')
        self.executable('hostname', '#!/bin/sh\nprintf "srv1836524\\n"\n')
        self.executable('sleep', r'''#!/usr/bin/python3
import json,os,signal,sys
with open(os.environ['WRAPPER_EVENTS'],'a') as output:
    output.write(json.dumps(['sleep',*sys.argv[1:]])+'\n')
if json.load(open(os.environ['WRAPPER_CONFIG']))['signal_on_sleep']:
    os.kill(os.getppid(),signal.SIGTERM)
''')
        self.executable('systemctl', r'''#!/usr/bin/python3
import json,os,sys
args=sys.argv[1:]
with open(os.environ['WRAPPER_EVENTS'],'a') as output:
    output.write(json.dumps(['systemctl',*args])+'\n')
with open(os.environ['WRAPPER_CONFIG']) as source: config=json.load(source)
timer='go-erp-live-updates.timer'
service='go-erp-live-updates.service'
if args==['show',timer,'-p','ActiveState','--value']:
    print(config['timer']);sys.exit(0)
if args==['stop',timer]:
    config['timer']='inactive'
elif args==['start',timer]:
    if config['restore_fails']:sys.exit(1)
    config['timer']='active'
elif args==['is-active','--quiet',timer]:
    sys.exit(0 if config['timer']=='active' else 3)
elif args==['show',service,'-p','ActiveState','--value']:
    config['checks']+=1
    with open(os.environ['WRAPPER_CONFIG'],'w') as output:json.dump(config,output)
    print(config['service_state'] or ('activating' if config['busy'] or config['checks']<=config['busy_checks'] else 'inactive'))
    sys.exit(0)
elif args==['start','--no-block',service]:
    sys.exit(1 if config['worker_start_fails'] else 0)
else:
    sys.exit(98)
with open(os.environ['WRAPPER_CONFIG'],'w') as output:json.dump(config,output)
''')

    def executable(self, name, text):
        path = self.bin / name; path.write_text(text); path.chmod(0o755)

    def run(self, arguments=()):
        environment = os.environ.copy()
        environment.update(PATH=str(self.bin) + ':/usr/bin:/bin',
            WRAPPER_EVENTS=str(self.events), WRAPPER_CONFIG=str(self.config))
        return subprocess.run(['/bin/bash', str(self.wrapper), *arguments],
            env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)

    def calls(self):
        return ([json.loads(line) for line in self.events.read_text().splitlines()]
                if self.events.exists() else [])

    def helper_calls(self):
        return [row for row in self.calls() if row[0] == 'helper']

    def close(self):
        self.temporary.cleanup()


class Preview31WatcherWrapperTests(unittest.TestCase):
    def fixture(self, **options):
        fixture = WrapperFixture(**options)
        self.addCleanup(fixture.close)
        return fixture

    def assert_service_mutations(self, fixture, worker_started, timer_restored):
        calls = fixture.calls()
        mutations = [row for row in calls if row[0] == 'systemctl'
                     and row[1] in ('start', 'stop', 'restart', 'kill', 'reset-failed')]
        expected = [['systemctl', 'stop', TIMER]]
        if worker_started:
            expected.append(['systemctl', 'start', '--no-block', SERVICE])
        if timer_restored:
            expected.append(['systemctl', 'start', TIMER])
        self.assertEqual(mutations, expected)
        # An existing worker is never stopped or force-killed in any path.
        self.assertNotIn(['systemctl', 'stop', SERVICE], calls)
        self.assertFalse(any(row[:2] == ['systemctl', 'kill'] for row in calls))

    def test_real_wrapper_pins_the_exact_reviewed_helper_before_timer_changes(self):
        matches = re.findall(r'^go_helper=([a-f0-9]{40})$', WRAPPER.read_text(), re.M)
        self.assertEqual(matches, [git_blob(HELPER.read_bytes())])

    def test_success_starts_only_the_reviewed_worker_after_helper_then_restores_timer(self):
        fixture = self.fixture()
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(fixture.helper_calls()), 1)
        calls = fixture.calls()
        helper = calls.index(fixture.helper_calls()[0])
        self.assertLess(calls.index(['systemctl', 'stop', TIMER]), helper)
        self.assertLess(helper, calls.index(['systemctl', 'start', '--no-block', SERVICE]))
        self.assertLess(calls.index(['systemctl', 'start', '--no-block', SERVICE]),
                        calls.index(['systemctl', 'start', TIMER]))
        self.assertIn('GO_ERP_REVIEWED_BUILD_WORKER_REQUESTED', result.stdout)
        self.assertIn('GO_ERP_TIMER_RESTORED', result.stdout)
        self.assertEqual(json.loads(fixture.config.read_text())['timer'], 'active')
        self.assert_service_mutations(fixture, worker_started=True, timer_restored=True)

    def test_failed_or_busy_helper_restores_timer_without_starting_a_worker(self):
        for status in (7, 75):
            with self.subTest(status=status):
                fixture = self.fixture(helper_status=status)
                result = fixture.run()
                self.assertEqual(result.returncode, status, result.stderr)
                self.assertEqual(len(fixture.helper_calls()), 1)
                self.assertNotIn('GO_ERP_REVIEWED_BUILD_WORKER_REQUESTED', result.stdout)
                self.assert_service_mutations(fixture, worker_started=False, timer_restored=True)

    def test_busy_existing_service_waits_at_most_ninety_seconds_without_force_stop(self):
        fixture = self.fixture(busy=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 75, result.stderr)
        self.assertEqual(fixture.helper_calls(), [])
        self.assertEqual(fixture.calls().count(['sleep', '5']), 18)
        self.assertEqual(json.loads(fixture.config.read_text())['checks'], 19)
        self.assertEqual(result.stdout.count('GO_ERP_PREVIEW31_WAITING_SERVICE '), 18)
        self.assertIn('waited_seconds=85 max_wait_seconds=90', result.stdout)
        self.assertIn('GO_ERP_WAITING_FOR_EXISTING_SERVICE_WITHOUT_STOPPING_IT', result.stdout)
        self.assert_service_mutations(fixture, worker_started=False, timer_restored=True)

    def test_existing_service_finishing_during_wait_allows_one_upgrade(self):
        fixture = self.fixture(busy_checks=3)
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(fixture.calls().count(['sleep', '5']), 3)
        self.assertEqual(len(fixture.helper_calls()), 1)
        self.assert_service_mutations(fixture, worker_started=True, timer_restored=True)

    def test_originally_inactive_or_failed_timer_is_not_started(self):
        for state in ('inactive', 'failed'):
            with self.subTest(state=state):
                fixture = self.fixture(timer=state)
                result = fixture.run()
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(json.loads(fixture.config.read_text())['timer'], 'inactive')
                self.assertNotIn('GO_ERP_TIMER_RESTORED', result.stdout)
                self.assert_service_mutations(fixture, worker_started=True, timer_restored=False)

    def test_sigterm_while_waiting_restores_timer_without_upgrade_or_new_worker(self):
        fixture = self.fixture(busy=True, signal_on_sleep=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 143, result.stderr)
        self.assertEqual(fixture.helper_calls(), [])
        self.assertEqual(fixture.calls().count(['sleep', '5']), 1)
        self.assert_service_mutations(fixture, worker_started=False, timer_restored=True)

    def test_timer_restore_failure_changes_success_to_visible_error(self):
        fixture = self.fixture(restore_fails=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn('GO_ERP_TIMER_RESTORE_FAILED', result.stderr)
        self.assertNotIn('GO_ERP_TIMER_RESTORED', result.stdout)
        self.assert_service_mutations(fixture, worker_started=True, timer_restored=True)

    def test_worker_start_failure_still_restores_timer(self):
        fixture = self.fixture(worker_start_fails=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn('GO_ERP_REVIEWED_BUILD_WORKER_START_FAILED', result.stderr)
        self.assertNotIn('GO_ERP_REVIEWED_BUILD_WORKER_REQUESTED', result.stdout)
        self.assert_service_mutations(fixture, worker_started=True, timer_restored=True)

    def test_tampered_helper_or_arguments_are_rejected_before_timer_changes(self):
        fixture = self.fixture(tamper_helper=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.calls(), [])
        fixture = self.fixture()
        result = fixture.run(arguments=('unexpected',))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.calls(), [])

    def test_unknown_timer_or_service_states_stop_without_running_helper(self):
        fixture = self.fixture(timer='unknown')
        result = fixture.run()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(fixture.helper_calls(), [])
        self.assertFalse(any(row[1] in ('start', 'stop') for row in fixture.calls()))
        self.assertIn('GO_ERP_TIMER_STATE_UNREVIEWED', result.stderr)
        fixture = self.fixture(service_state='unknown')
        result = fixture.run()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(fixture.helper_calls(), [])
        self.assertIn('GO_ERP_SERVICE_STATE_UNREVIEWED', result.stderr)
        self.assert_service_mutations(fixture, worker_started=False, timer_restored=True)


if __name__ == '__main__':
    unittest.main()
