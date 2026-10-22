"""Run the real upgrade entry point against isolated service and Git fixtures."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


REPO = Path(__file__).resolve().parents[1]
WRAPPER = REPO / 'deployment/upgrade_go_erp_checkout_policy_root.sh'
REVISION = '1' * 40
HELPER_PATH = 'deployment/upgrade_go_erp_checkout_policy_root.py'
PAYLOADS = ('go_erp_live_source.py', 'go_erp_live_updates.py', 'publish_go_erp_update.py',
            'go_erp_current_candidate_ci.yml', 'verify_go_erp_current_candidate_ci.py')


def git_blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


class EntryFixture:
    def __init__(self, active=True, upgrade_status=0, busy=False, busy_checks=0,
                 signal_on_sleep=False, restore_fails=False, tamper=False):
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-checkout-policy-wrapper-test-')
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.events = self.root / 'events.jsonl'
        self.config = self.root / 'config.json'
        self.operations = self.root / 'operations.jsonl'
        self.config.write_text(json.dumps({'active': active, 'busy': busy,
            'busy_checks': busy_checks, 'checks': 0, 'signal_on_sleep': signal_on_sleep,
            'restore_fails': restore_fails, 'tamper': tamper}))
        payloads = {name: ('# reviewed fixture ' + name + '\n').encode() for name in PAYLOADS}
        helper = self.helper_script(payloads, upgrade_status)
        self.helper_pin = git_blob(helper)
        self.blobs = self.root / 'blobs.json'
        self.blobs.write_text(json.dumps({git_blob(data): data.decode()
            for data in (helper, *payloads.values())}))
        self.pins = self.root / 'pins.json'
        self.pins.write_text(json.dumps({HELPER_PATH: self.helper_pin,
            **{'deployment/' + name: git_blob(data) for name, data in payloads.items()}}))
        text = WRAPPER.read_text()
        text = text.replace('go_root=/home/fasakha/public_html',
                            'go_root=' + str(self.root / 'live'))
        helper_line = next(line for line in text.splitlines() if line.startswith('go_helper='))
        text = text.replace(helper_line, 'go_helper=' + self.helper_pin)
        self.wrapper = self.root / 'upgrade.sh'
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
    target=args[-1].split(':',1)[1]
    print(pins[target])
elif 'cat-file' in args and args[-2]=='blob':
    value=blobs[args[-1]]
    if config['tamper'] and args[-1]==pins['deployment/go_erp_live_source.py']:
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
    config['checks']+=1
    with open(os.environ['WRAPPER_CONFIG'],'w') as stream:json.dump(config,stream)
    print('active' if config['busy'] or config['checks']<=config['busy_checks'] else 'inactive')
    sys.exit(0)
sys.exit(98)
''')

    def helper_script(self, payloads, status):
        expected = {name: hashlib.sha256(data).hexdigest() for name, data in payloads.items()}
        return ("import hashlib,json,os,stat,sys\nfrom pathlib import Path\n"
            "assert len(sys.argv)==3 and sys.argv[2]=='--upgrade'\n"
            "payload=Path(sys.argv[1])\n"
            f"expected={expected!r}\n"
            "assert payload.is_dir() and stat.S_IMODE(payload.stat().st_mode)==0o700\n"
            "assert sorted(path.name for path in payload.iterdir())==sorted(expected)\n"
            "assert Path(__file__).parent==payload.parent and Path(__file__).parent!=payload\n"
            "for name,digest in expected.items():\n"
            "    path=payload/name;item=path.lstat()\n"
            "    assert stat.S_ISREG(item.st_mode) and stat.S_IMODE(item.st_mode)==0o600\n"
            "    assert item.st_uid==os.geteuid() and item.st_nlink==1\n"
            "    assert hashlib.sha256(path.read_bytes()).hexdigest()==digest\n"
            f"with open({str(self.operations)!r},'a') as stream:\n"
            "    stream.write(json.dumps(['upgrade',str(payload),sys.argv[2],sorted(expected)])+'\\n')\n"
            f"print(json.dumps({{'kind':'upgrade','ok':{status == 0!r}}}))\n"
            f"sys.exit({status})\n").encode()

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
            timeout=30)

    def calls(self):
        return ([json.loads(line) for line in self.events.read_text().splitlines()]
                if self.events.exists() else [])

    def steps(self):
        return ([json.loads(line) for line in self.operations.read_text().splitlines()]
                if self.operations.exists() else [])

    def close(self):
        self.temp.cleanup()


class CheckoutPolicyUpgradeWrapperTests(unittest.TestCase):
    def fixture(self, **options):
        fixture = EntryFixture(**options)
        self.addCleanup(fixture.close)
        return fixture

    def assert_timer(self, fixture, stopped, started):
        calls = fixture.calls()
        self.assertEqual(calls.count(['systemctl', 'stop', 'go-erp-live-updates.timer']), stopped)
        self.assertEqual(calls.count(['systemctl', 'start', 'go-erp-live-updates.timer']), started)
        # Only timer state changes are authorized; no service tick or termination.
        mutations = [row for row in calls if row[0] == 'systemctl' and row[1] in ('start', 'stop', 'kill')]
        self.assertTrue(all(row[1:] in (['start', 'go-erp-live-updates.timer'],
                                      ['stop', 'go-erp-live-updates.timer']) for row in mutations))

    def test_exact_five_private_payloads_are_release_bound_and_cleaned_after_success(self):
        fixture = self.fixture()
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(fixture.steps()), 1)
        step = fixture.steps()[0]
        self.assertEqual(step[0], 'upgrade')
        self.assertEqual(step[2:], ['--upgrade', sorted(PAYLOADS)])
        self.assertFalse(Path(step[1]).parent.exists())
        parses = [row[-1] for row in fixture.calls() if row[0] == 'runuser' and 'rev-parse' in row]
        self.assertEqual(parses, [REVISION + ':' + HELPER_PATH,
            *[REVISION + ':deployment/' + name for name in PAYLOADS]])
        calls=fixture.calls()
        stop=calls.index(['systemctl','stop','go-erp-live-updates.timer'])
        staged=[index for index,row in enumerate(calls) if row[0]=='runuser' and 'cat-file' in row]
        self.assertEqual(len(staged),len(PAYLOADS)+1)
        self.assertTrue(all(index<stop for index in staged))
        self.assertIn('GO_ERP_CHECKOUT_POLICY_UPGRADE_READY', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_upgrade_failure_restores_active_timer_and_retains_failure_status(self):
        fixture = self.fixture(upgrade_status=7)
        result = fixture.run()
        self.assertEqual(result.returncode, 7)
        self.assertEqual(len(fixture.steps()), 1)
        self.assertNotIn('GO_ERP_CHECKOUT_POLICY_UPGRADE_READY', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_originally_inactive_timer_stays_inactive(self):
        fixture = self.fixture(active=False)
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('GO_ERP_LIVE_TIMER_INACTIVE', result.stdout)
        self.assert_timer(fixture, stopped=0, started=0)

    def test_busy_service_is_waited_for_forty_five_seconds_without_tick_or_kill(self):
        fixture = self.fixture(busy=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.steps(), [])
        self.assertEqual(fixture.calls().count(['sleep', '5']), 9)
        self.assertEqual(result.stdout.count('GO_ERP_CHECKOUT_POLICY_WAITING_SERVICE '), 9)
        self.assertIn('waited_seconds=40 max_wait_seconds=45', result.stdout)
        self.assertIn('GO_ERP_CHECKOUT_POLICY_WAITING_FOR_EXISTING_SERVICE', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_service_finishing_before_bounded_wait_limit_proceeds(self):
        fixture = self.fixture(busy_checks=3)
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(fixture.calls().count(['sleep', '5']), 3)
        self.assertEqual(len(fixture.steps()), 1)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_sigterm_while_waiting_restores_timer_and_exits_143(self):
        fixture = self.fixture(busy=True, signal_on_sleep=True)
        result = fixture.run()
        self.assertEqual(result.returncode, 143, result.stderr)
        self.assertEqual(fixture.steps(), [])
        self.assert_timer(fixture, stopped=1, started=1)

    def test_restore_failure_is_visible(self):
        fixture = self.fixture(upgrade_status=7, restore_fails=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('GO_ERP_TIMER_RESTORE_FAILED', result.stderr)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_tampered_payload_stops_before_timer_changes(self):
        fixture = self.fixture(tamper=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.steps(), [])
        self.assertFalse(any(row[0] == 'systemctl' for row in fixture.calls()))

    def test_invalid_release_is_rejected_before_staging_or_timer_changes(self):
        fixture = self.fixture()
        result = fixture.run(revision='not-a-release')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.calls(), [])
        self.assertEqual(fixture.steps(), [])


if __name__ == '__main__':
    unittest.main()
