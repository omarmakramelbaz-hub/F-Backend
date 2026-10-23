"""No exception message or malformed recovery attribute becomes public output."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import types
import unittest
from unittest import mock


ROOT=Path(__file__).resolve().parents[1]


class FixtureSeedFailureTests(unittest.TestCase):
    def setUp(self):
        path=ROOT/'deployment/upgrade_go_erp_fixture_seed_root.py'
        spec=importlib.util.spec_from_file_location('fixture_seed_failure',path)
        self.m=importlib.util.module_from_spec(spec);spec.loader.exec_module(self.m)

    def result(self,error):
        output=io.StringIO()
        with mock.patch.object(self.m,'execute',side_effect=error), \
             mock.patch.object(self.m,'load_mode',side_effect=RuntimeError('PRIVATE_SECONDARY')), \
             contextlib.redirect_stdout(output):
            status=self.m.main(['--package','/no-public-account-values','--mode','prepare'])
        return status,json.loads(output.getvalue()),output.getvalue()

    def test_foreign_uppercase_and_stop_subclass_remain_private_without_str(self):
        class Foreign(self.m.Stop):
            def __str__(self):raise AssertionError('private string was evaluated')
        for error in (ValueError('PRIVATE_ACCOUNT_TOKEN'),Foreign('GO_ERP_FIXTURE_SEED_CANDIDATE_INVALID')):
            with self.subTest(type=type(error).__name__):
                status,value,raw=self.result(error)
                self.assertEqual(status,1)
                self.assertEqual(value['reason'],'GO_ERP_FIXTURE_SEED_PRIVATE_FAILURE')
                self.assertNotIn('PRIVATE_ACCOUNT_TOKEN',raw)
                self.assertNotIn('PRIVATE_SECONDARY',raw)

    def test_incomplete_or_malformed_rollback_never_reports_success_or_exposes_attribute(self):
        for attribute in (False,'PRIVATE_RECOVERY_VALUE'):
            error=ValueError('PRIVATE_ACCOUNT_TOKEN');error.rollback_complete=attribute
            status,value,raw=self.result(error)
            self.assertEqual(status,78);self.assertFalse(value['rollback_complete'])
            self.assertFalse(value['application_contents_changed']);self.assertFalse(value['database_changed'])
            self.assertNotIn('PRIVATE_RECOVERY_VALUE',raw)

    def test_durable_authorization_is_retained_and_reported_incomplete_after_postcheck_failure(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'durable-authorization.json'
            def record(*args):path.write_text('{"authorization":"genuine-bound-fixture"}')
            class Directory:
                def __init__(self,*args):pass
                def close(self):pass
            ctx=types.SimpleNamespace(utility=types.SimpleNamespace(Directory=Directory,module_at=lambda *args:None),
                runtime=Path(folder),pins={'go_erp_live_updates.py':'a'*40},
                gate=types.SimpleNamespace(program_gate_authorize=record),expected={},publisher=None,
                publication=lambda:None,check_after=mock.Mock(side_effect=ValueError('PRIVATE_ACCOUNT_VALUE')))
            with self.assertRaisesRegex(self.m.Stop,'AUTHORIZATION_REQUIRES_REVIEW') as raised:
                self.m.authorize(ctx,{'desired':{'commit':'b'*40}},{},0,lambda:None,'receipt')
            self.assertFalse(raised.exception.rollback_complete)
            self.assertTrue(path.exists());self.assertIn('genuine-bound-fixture',path.read_text())
            status,value,raw=self.result(raised.exception)
            self.assertEqual(status,78);self.assertEqual(value['hold_changed'],'unknown')
            self.assertFalse(value['rollback_complete']);self.assertNotIn('PRIVATE_ACCOUNT_VALUE',raw)


if __name__=='__main__':unittest.main()
