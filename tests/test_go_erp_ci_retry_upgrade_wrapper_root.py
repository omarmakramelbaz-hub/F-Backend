#!/usr/bin/env python3
"""Exercise the CI retry entrypoint with isolated Git/systemd fixtures."""
import importlib.util
from pathlib import Path
import unittest

REPO = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('ci_retry_wrapper_fixtures',
    REPO / 'tests/test_go_erp_checkout_policy_upgrade_wrapper_root.py')
fixtures = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixtures)
fixtures.WRAPPER = REPO / 'deployment/upgrade_go_erp_ci_retry_root.sh'
fixtures.HELPER_PATH = 'deployment/upgrade_go_erp_ci_retry_root.py'


class CiRetryWrapperTests(unittest.TestCase):
    def fixture(self, **options):
        value = fixtures.EntryFixture(**options)
        self.addCleanup(value.close)
        return value

    def assert_timer(self, value, stopped, started):
        calls = value.calls()
        self.assertEqual(calls.count(['systemctl', 'stop', 'go-erp-live-updates.timer']), stopped)
        self.assertEqual(calls.count(['systemctl', 'start', 'go-erp-live-updates.timer']), started)
        mutations = [row for row in calls if row[0] == 'systemctl'
                     and row[1] in ('start', 'stop', 'kill')]
        self.assertTrue(all(row[-1] == 'go-erp-live-updates.timer' for row in mutations))

    def test_success_binds_payload_to_release_and_restores_timer(self):
        value = self.fixture()
        result = value.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('GO_ERP_CI_RETRY_UPGRADE_READY', result.stdout)
        self.assertEqual(len(value.steps()), 1)
        self.assertFalse(Path(value.steps()[0][1]).parent.exists())
        parses = [row[-1] for row in value.calls() if row[0] == 'runuser' and 'rev-parse' in row]
        self.assertEqual(parses, [fixtures.REVISION + ':' + fixtures.HELPER_PATH,
            *[fixtures.REVISION + ':deployment/' + name for name in fixtures.PAYLOADS]])
        self.assert_timer(value, 1, 1)

    def test_failure_keeps_status_and_restores_timer(self):
        value = self.fixture(upgrade_status=7)
        result = value.run()
        self.assertEqual(result.returncode, 7)
        self.assertNotIn('GO_ERP_CI_RETRY_UPGRADE_READY', result.stdout)
        self.assert_timer(value, 1, 1)

    def test_inactive_timer_remains_inactive(self):
        value = self.fixture(active=False)
        result = value.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('GO_ERP_LIVE_TIMER_INACTIVE', result.stdout)
        self.assert_timer(value, 0, 0)

    def test_running_worker_is_never_terminated(self):
        value = self.fixture(busy=True)
        result = value.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(value.steps(), [])
        self.assertEqual(value.calls().count(['sleep', '5']), 9)
        self.assertIn('GO_ERP_CI_RETRY_WAITING_FOR_EXISTING_SERVICE', result.stdout)
        self.assert_timer(value, 1, 1)

    def test_signal_restores_timer(self):
        value = self.fixture(busy=True, signal_on_sleep=True)
        result = value.run()
        self.assertEqual(result.returncode, 143)
        self.assertEqual(value.steps(), [])
        self.assert_timer(value, 1, 1)

    def test_tamper_stops_before_service_changes(self):
        value = self.fixture(tamper=True)
        result = value.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(value.steps(), [])
        self.assertFalse(any(row[0] == 'systemctl' for row in value.calls()))


if __name__ == '__main__':
    unittest.main()
