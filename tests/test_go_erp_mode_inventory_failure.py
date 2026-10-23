"""The metadata-only entry reports fixed checks without account/error text."""
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]


class ModeFailureTests(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('mode_inventory_failure', ROOT/'deployment/upgrade_go_erp_mode_inventory_root.py')
        self.m = importlib.util.module_from_spec(spec);spec.loader.exec_module(self.m)

    def capture(self, action):
        with tempfile.TemporaryDirectory() as path:
            output = io.StringIO()
            with mock.patch.object(self.m, 'execute', side_effect=action), mock.patch('sys.stdout', output):
                status = self.m.main(['--package', path, '--mode', 'reconcile'])
        text = output.getvalue()
        self.assertEqual(len(text.splitlines()), 1)
        self.assertNotIn('PRIVATE_ACCOUNT_TOKEN', text)
        return status, json.loads(text)

    def test_fixed_own_check_has_line_and_reason_without_source_values(self):
        status, row = self.capture(lambda *args: self.m.need(False, 'MODE_INVALID'))
        self.assertEqual(status, 1)
        self.assertEqual(row['reason'], 'GO_ERP_MODE_RECONCILIATION_MODE_INVALID')
        self.assertEqual(row['diagnostic']['file'], 'upgrade_go_erp_mode_inventory_root.py')
        self.assertIs(type(row['diagnostic']['line']), int)
        self.assertIs(row['application_contents_changed'], False)
        self.assertIs(row['modes_changed'], False)

    def test_own_prefix_and_foreign_known_enum_cannot_expose_private_or_false_reason(self):
        for error in (self.m.Stop('GO_ERP_MODE_RECONCILIATION_PRIVATE_ACCOUNT_TOKEN'),
                      ValueError('GO_ERP_MODE_RECONCILIATION_MODE_INVALID')):
            with self.subTest(kind=type(error).__name__):
                def fail(*args): raise error
                status, row = self.capture(fail)
                self.assertEqual(status, 1)
                self.assertTrue(row['reason'].endswith('PRIVATE_FAILURE'))
                self.assertIs(row['credentials_disclosed'], False)


if __name__ == '__main__': unittest.main()
