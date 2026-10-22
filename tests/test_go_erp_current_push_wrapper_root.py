"""Exercise the real push wrapper with isolated Git and service-control fixtures."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[1]
WRAPPER = REPO / 'deployment/retry_go_erp_current_push_root.sh'
REVISION = '1' * 40
RETRY_PATH = 'deployment/diagnose_go_erp_current_push_root.py'
HELPER_PATH = 'deployment/upgrade_go_erp_live_runtime_root.py'


def git_blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


class PushEntryFixture:
    def __init__(self, active=True, retry_status=0, busy=False, signal_on_sleep=False,
                 signal_on_retry=False, restore_fails=False, tamper=None, wrong_ref=None):
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-current-push-wrapper-test-')
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.events = self.root / 'events.jsonl'
        self.operations = self.root / 'operations.jsonl'
        self.config = self.root / 'config.json'
        self.config.write_text(json.dumps({'active': active, 'busy': busy,
            'signal_on_sleep': signal_on_sleep, 'restore_fails': restore_fails,
            'tamper': tamper, 'wrong_ref': wrong_ref}))
        helper = b'# Private reviewed helper fixture. No execution expected.\n'
        self.helper_pin = git_blob(helper)
        retry = self.retry_script(helper, retry_status, signal_on_retry)
        self.retry_pin = git_blob(retry)
        self.blobs = self.root / 'blobs.json'
        self.blobs.write_text(json.dumps({self.helper_pin: helper.decode(),
            self.retry_pin: retry.decode()}))
        self.pins = self.root / 'pins.json'
        self.pins.write_text(json.dumps({HELPER_PATH: self.helper_pin, RETRY_PATH: self.retry_pin}))
        text = WRAPPER.read_text()
        text = text.replace('go_root=/home/fasakha/public_html',
                            'go_root=' + str(self.root / 'live'))
        text = re.sub(r'^go_retry=[a-f0-9]{40}$', 'go_retry=' + self.retry_pin,
                      text, flags=re.MULTILINE)
        text = re.sub(r'^go_helper=[a-f0-9]{40}$', 'go_helper=' + self.helper_pin,
                      text, flags=re.MULTILINE)
        self.wrapper = self.root / 'retry.sh'
        self.wrapper.write_text(text)
        self.executable('id', '#!/bin/sh\nprintf "0\\n"\n')
        self.executable('hostname', '#!/bin/sh\nprintf "srv1836524\\n"\n')
        self.executable('sleep', r'''#!/usr/bin/python3
import json,os,signal,sys
with open(os.environ['WRAPPER_EVENTS'],'a') as stream:
    stream.write(json.dumps(['sleep',*sys.argv[1:]])+'\n')
if json.load(open(os.environ['WRAPPER_CONFIG']))['signal_on_sleep']:
    os.kill(os.getppid(),signal.SIGTERM)
''')
        self.executable('runuser', r'''#!/usr/bin/python3
import json,os,sys
args=sys.argv[1:]
with open(os.environ['WRAPPER_EVENTS'],'a') as stream:
    stream.write(json.dumps(['runuser',*args])+'\n')
pins=json.load(open(os.environ['WRAPPER_PINS']))
blobs=json.load(open(os.environ['WRAPPER_BLOBS']))
config=json.load(open(os.environ['WRAPPER_CONFIG']))
if 'rev-parse' in args:
    path=args[-1].split(':',1)[1]
    print('0'*40 if path==config['wrong_ref'] else pins[path])
elif 'cat-file' in args and args[-2]=='blob':
    value=blobs[args[-1]]
    if config['tamper'] is not None and args[-1]==pins[config['tamper']]:
        value+='tampered\n'
    sys.stdout.write(value)
else:
    sys.exit(97)
''')
        self.executable('systemctl', r'''#!/usr/bin/python3
import json,os,sys
args=sys.argv[1:]
with open(os.environ['WRAPPER_EVENTS'],'a') as stream:
    stream.write(json.dumps(['systemctl',*args])+'\n')
config=json.load(open(os.environ['WRAPPER_CONFIG']))
if args==['is-active','--quiet','go-erp-live-updates.timer']:
    sys.exit(0 if config['active'] else 3)
if args==['stop','go-erp-live-updates.timer']:
    sys.exit(0)
if args==['start','go-erp-live-updates.timer']:
    sys.exit(1 if config['restore_fails'] else 0)
if args==['show','go-erp-live-updates.service','-p','ActiveState','--value']:
    print('active' if config['busy'] else 'inactive')
    sys.exit(0)
sys.exit(98)
''')

    def retry_script(self, helper, status, signal_on_retry):
        return ("import hashlib,json,os,signal,stat,sys\nfrom pathlib import Path\n"
            "assert len(sys.argv)==3 and sys.argv[2]=='--retry-prepared'\n"
            "path=Path(sys.argv[1]);directory=Path(__file__).parent\n"
            "assert path.parent==directory and path.name=='helper.py'\n"
            "assert sorted(item.name for item in directory.iterdir())==['helper.py','retry.py']\n"
            "assert stat.S_IMODE(directory.stat().st_mode)==0o700\n"
            "for item_path in (path,Path(__file__)):\n"
            "    item=item_path.lstat()\n"
            "    assert stat.S_ISREG(item.st_mode) and stat.S_IMODE(item.st_mode)==0o600\n"
            "    assert item.st_uid==os.geteuid() and item.st_nlink==1\n"
            f"assert hashlib.sha256(path.read_bytes()).hexdigest()=={hashlib.sha256(helper).hexdigest()!r}\n"
            f"with open({str(self.operations)!r},'a') as stream:\n"
            "    stream.write(json.dumps(['retry',str(directory),sys.argv[2]])+'\\n')\n"
            f"print(json.dumps({{'kind':'diagnosis','ok':{status == 0!r}}}))\n"
            + ("os.kill(os.getppid(),signal.SIGTERM)\n" if signal_on_retry else '')
            + f"sys.exit({status})\n").encode()

    def executable(self, name, data):
        path = self.bin / name
        path.write_text(data)
        path.chmod(0o755)

    def run(self, revision=REVISION):
        environment = os.environ.copy()
        environment.update(PATH=str(self.bin) + ':/usr/bin:/bin',
            WRAPPER_EVENTS=str(self.events), WRAPPER_CONFIG=str(self.config),
            WRAPPER_PINS=str(self.pins), WRAPPER_BLOBS=str(self.blobs))
        return subprocess.run(['/bin/bash', str(self.wrapper), '--release', revision],
            env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=20)

    def calls(self):
        return ([json.loads(line) for line in self.events.read_text().splitlines()]
                if self.events.exists() else [])

    def steps(self):
        return ([json.loads(line) for line in self.operations.read_text().splitlines()]
                if self.operations.exists() else [])

    def close(self):
        self.temp.cleanup()


class CurrentPushWrapperTests(unittest.TestCase):
    def fixture(self, **options):
        fixture = PushEntryFixture(**options)
        self.addCleanup(fixture.close)
        return fixture

    def assert_timer(self, fixture, stopped, started):
        calls = fixture.calls()
        self.assertEqual(calls.count(['systemctl', 'stop', 'go-erp-live-updates.timer']), stopped)
        self.assertEqual(calls.count(['systemctl', 'start', 'go-erp-live-updates.timer']), started)
        # The wrapper must never start, stop or kill the actual service.
        mutations = [row for row in calls if row[0] == 'systemctl' and row[1] in ('start', 'stop', 'kill')]
        self.assertTrue(all(row[1:] in (['start', 'go-erp-live-updates.timer'],
                                      ['stop', 'go-erp-live-updates.timer']) for row in mutations))

    def test_repository_payload_pins_match_the_exact_scripts(self):
        wrapper = WRAPPER.read_text()
        for variable, path in (('go_retry', RETRY_PATH), ('go_helper', HELPER_PATH)):
            match = re.search(r'^' + variable + r'=([a-f0-9]{40})$', wrapper, re.MULTILINE)
            self.assertIsNotNone(match)
            self.assertEqual(match[1], git_blob((REPO / path).read_bytes()))

    def test_success_stages_private_release_bound_files_before_stopping_timer(self):
        fixture = self.fixture()
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(fixture.steps()), 1)
        step = fixture.steps()[0]
        self.assertEqual(step[0], 'retry')
        self.assertEqual(step[2], '--retry-prepared')
        self.assertFalse(Path(step[1]).exists())
        calls = fixture.calls()
        parses = [row[-1] for row in calls if row[0] == 'runuser' and 'rev-parse' in row]
        self.assertEqual(parses, [REVISION + ':' + RETRY_PATH, REVISION + ':' + HELPER_PATH])
        stop_index = calls.index(['systemctl', 'stop', 'go-erp-live-updates.timer'])
        staged_indices = [i for i, row in enumerate(calls) if row[0] == 'runuser' and 'cat-file' in row]
        self.assertEqual(len(staged_indices), 2)
        self.assertTrue(all(index < stop_index for index in staged_indices))
        self.assertIn('GO_ERP_AUTOMATIC_SOURCE_BUILD_CONTINUES', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_diagnostic_failure_restores_timer_and_preserves_exit_code(self):
        fixture = self.fixture(retry_status=7)
        result = fixture.run()
        self.assertEqual(result.returncode, 7, result.stderr)
        self.assertEqual(len(fixture.steps()), 1)
        self.assertFalse(Path(fixture.steps()[0][1]).exists())
        self.assert_timer(fixture, stopped=1, started=1)

    def test_originally_inactive_timer_stays_inactive_even_after_failure(self):
        fixture = self.fixture(active=False, retry_status=7)
        result = fixture.run()
        self.assertEqual(result.returncode, 7, result.stderr)
        self.assertIn('GO_ERP_LIVE_TIMER_REMAINS_INACTIVE', result.stdout)
        self.assert_timer(fixture, stopped=0, started=0)

    def test_busy_service_times_out_without_diagnostic_or_service_interruption(self):
        fixture = self.fixture(busy=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.steps(), [])
        self.assertEqual(fixture.calls().count(['sleep', '5']), 9)
        self.assertIn('GO_ERP_CURRENT_PUSH_EXISTING_SERVICE_STILL_RUNNING', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_sigterm_during_wait_restores_timer_and_returns_143(self):
        fixture = self.fixture(busy=True, signal_on_sleep=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 143, result.stderr)
        self.assertEqual(fixture.steps(), [])
        self.assert_timer(fixture, stopped=1, started=1)

    def test_sigterm_after_diagnostic_returns_restores_timer_and_returns_143(self):
        fixture = self.fixture(signal_on_retry=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 143, result.stderr)
        self.assertEqual(len(fixture.steps()), 1)
        self.assertFalse(Path(fixture.steps()[0][1]).exists())
        self.assert_timer(fixture, stopped=1, started=1)

    def test_timer_restore_failure_is_reported_and_never_claims_continuation(self):
        fixture = self.fixture(restore_fails=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('GO_ERP_TIMER_RESTORE_FAILED', result.stderr)
        self.assertNotIn('GO_ERP_AUTOMATIC_SOURCE_BUILD_CONTINUES', result.stdout)
        # An initial start failure leaves the EXIT trap responsible for one retry.
        self.assert_timer(fixture, stopped=1, started=2)

    def test_tampered_payload_or_helper_stops_before_any_service_control(self):
        for path in (RETRY_PATH, HELPER_PATH):
            with self.subTest(path=path):
                fixture = self.fixture(tamper=path)
                result = fixture.run()
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(fixture.steps(), [])
                self.assertFalse(any(row[0] == 'systemctl' for row in fixture.calls()))

    def test_release_mismatch_stops_before_staging_or_service_control(self):
        for path in (RETRY_PATH, HELPER_PATH):
            with self.subTest(path=path):
                fixture = self.fixture(wrong_ref=path)
                result = fixture.run()
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(fixture.steps(), [])
                self.assertFalse(any(row[0] == 'systemctl' or 'cat-file' in row
                                     for row in fixture.calls()))

    def test_invalid_release_stops_before_any_external_operation(self):
        fixture = self.fixture()
        result = fixture.run(revision='not-a-commit')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.calls(), [])
        self.assertEqual(fixture.steps(), [])


if __name__ == '__main__':
    unittest.main()
