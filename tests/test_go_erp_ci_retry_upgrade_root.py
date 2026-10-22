#!/usr/bin/env python3
"""Exercise the same-policy upgrade against the existing ownership/race fixtures."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

REPO = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = module('ci_retry_upgrade_subject', REPO / 'deployment/upgrade_go_erp_ci_retry_root.py')
fixtures = module('ci_retry_upgrade_fixtures', REPO / 'tests/test_go_erp_checkout_policy_upgrade_root.py')
# The fixture module is isolated from the original suite. Its real filesystem,
# ownership, lock, exchange, interruption, and rollback cases run on the new helper.
fixtures.subject = subject


@unittest.skipUnless(os.geteuid() == 0, 'Private runtime fixture requires root.')
class CiRetryUpgradeTests(fixtures.CheckoutPolicyUpgradeTests):
    def audits(self):
        return list(self.work.glob('ci-retry-upgrade-*'))

    def recovery_state(self, version=20, status='retry-build', failures=1):
        commits = {20: '7661411d1ae279d65522153c20c99dc8b4d1c12b',
                   21: 'a0e1da77e333f4ae04ed25ba0da001ddc4b5986e', 22: '2' * 40}
        state = json.loads(self.state.read_text())
        state.update(counter=version, sequence_floor=39688961800,
                     version_floor='0.4.4-preview.8', last_remote_sha=commits[version])
        state['desired'].update(version='0.4.4-preview.' + str(version),
            candidatePolicy='historical-baseline-v2', status=status, commit=commits[version],
            inventorySha256='44302b8404b7c02ff1c3f68a3a2fcbd6355a23230b8071a344e30988017e5f37',
            failures=failures, retry_at=1792685371.601132, pushed=True)
        state['published'] = {'release': {'version': '0.4.4-preview.8'},
                              'commit': '1868d8c1a19230dedb5740733404bf2987175973'}
        self.state.write_text(json.dumps(state))
        self.secret_before = self.private_snapshot()

    def test_same_policy_upgrade_preserves_failed_or_newer_candidate_and_floors(self):
        for version, status, failures in ((20, 'retry-build', 1), (21, 'waiting-ci', 1),
                                          (22, 'blocked', 3)):
            with self.subTest(version=version, status=status):
                self.recovery_state(version, status, failures)
                report = self.upgrade()
                self.assertTrue(report['ok'], report)
                self.assertEqual(report['kind'], 'go-erp-ci-retry-upgrade')
                self.assertEqual(report['candidate_policy'], 'historical-baseline-v2')
                self.assertEqual(report['counter'], version)
                self.assertFalse(report['state_changed'])
                self.assertFalse(report['configuration_read'])
                self.assert_private_unchanged()

    def test_exact_20_recovery_state_survives_idempotent_upgrade(self):
        self.recovery_state()
        self.assertTrue(self.upgrade()['ok'])
        report = self.upgrade()
        self.assertTrue(report['ok'], report)
        self.assertTrue(report['already_current'])
        self.assert_private_unchanged()

    def test_exact_20_recovery_state_survives_reload_rollback(self):
        self.recovery_state()
        calls = 0
        def command(arguments):
            nonlocal calls
            self.command(arguments)
            if arguments == ['/usr/bin/systemctl', 'daemon-reload']:
                calls += 1
                if calls == 1:
                    raise subject.Stop('SYSTEMD_COMMAND_FAILED')
        report = self.upgrade(command=command)
        self.assertFalse(report['ok'])
        self.assertTrue(report['rollback_complete'])
        self.assertEqual(self.unit.read_bytes(), self.unit_bytes)
        self.assert_private_unchanged()


class CiRetryEffectiveUnitTests(fixtures.EffectiveUnitTests):
    pass


class CiRetryPayloadBindingTests(unittest.TestCase):
    def test_only_worker_changes_from_exact_deployed_v2_runtime(self):
        self.assertEqual(set(subject.PINS), set(subject.OLD_PINS))
        predecessor = {}
        changed = []
        for name, pin in subject.PINS.items():
            data = (REPO / 'deployment' / name).read_bytes()
            self.assertEqual(subject.blob(data), pin, name)
            old = subprocess.check_output(['git', '-c', 'safe.directory=' + str(REPO),
                '-C', str(REPO), 'show',
                'b2adc9953bde8c501ba52f0f56636d91a0dff75d:deployment/' + name])
            predecessor[name] = old
            self.assertEqual(subject.blob(old), subject.OLD_PINS[name], name)
            if old != data:
                changed.append(name)
        self.assertEqual(changed, ['go_erp_live_updates.py'])
        self.assertEqual(subject.runtime_name(predecessor), subject.OLD_RUNTIME_NAME)
        current = {name: (REPO / 'deployment' / name).read_bytes() for name in subject.PINS}
        self.assertNotEqual(subject.runtime_name(current), subject.OLD_RUNTIME_NAME)

    def test_entrypoint_is_bound_to_exact_helper(self):
        wrapper = (REPO / 'deployment/upgrade_go_erp_ci_retry_root.sh').read_text()
        pin = next(line.split('=', 1)[1] for line in wrapper.splitlines()
                   if line.startswith('go_helper='))
        self.assertEqual(pin, subject.blob(
            (REPO / 'deployment/upgrade_go_erp_ci_retry_root.py').read_bytes()))


if __name__ == '__main__':
    unittest.main()
