"""The larger private intent has a fixed budget; the rollout gate stays strict."""
import importlib.util
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest import mock


ROOT=Path(__file__).resolve().parents[1]


def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


@unittest.skipUnless(os.geteuid()==0,'actual private root-owned bounded readers')
class FixtureSeedLimitsTests(unittest.TestCase):
    def setUp(self):
        self.m=module('fixture_seed_limits_entry',ROOT/'deployment/upgrade_go_erp_fixture_seed_root.py')
        self.g=module('fixture_seed_limits_gate',ROOT/'deployment/go_erp_program_rollout_gate.py')
        self.temp=tempfile.TemporaryDirectory(prefix='go-erp-fixture-limits-')
        self.addCleanup(self.temp.cleanup)
        self.folder=Path(self.temp.name);self.folder.chmod(0o700)
        self.ctx=types.SimpleNamespace(gate=self.g)

    def sparse(self,name,limit):
        path=self.folder/name
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        try:os.ftruncate(fd,limit+1)
        finally:os.close(fd)
        return path

    def test_64_mib_intent_limit_rejects_oversize_sparse_inode_before_parse(self):
        self.assertEqual(self.m.MAX_INTENT,64*1024*1024)
        path=self.sparse('intent.json',self.m.MAX_INTENT)
        before=self.g.program_gate_identity(path.stat())
        with mock.patch.object(self.g,'program_gate_parse',side_effect=AssertionError('must not parse oversize bytes')):
            with self.assertRaisesRegex(self.g.ProgramGateError,'FILE_UNSAFE'):
                self.m.bounded_json(self.ctx,path,self.m.MAX_INTENT)
        self.assertEqual(self.g.program_gate_identity(path.stat()),before)

    def test_16_mib_state_limit_and_existing_2_mib_gate_limit_are_both_preserved(self):
        self.assertEqual(self.m.MAX_STATE,16*1024*1024)
        state=self.folder/'state';state.mkdir(mode=0o700)
        path=self.sparse('oversize-state.json',self.m.MAX_STATE)
        os.rename(path,state/'current.json')
        with mock.patch.object(self.m,'WORK',self.folder), \
             mock.patch.object(self.g,'program_gate_parse',side_effect=AssertionError('must not parse oversize state')):
            with self.assertRaisesRegex(self.g.ProgramGateError,'FILE_UNSAFE'):
                self.m.read_state(self.ctx)
        path=self.sparse('gate-default.json',2*1024*1024)
        with self.assertRaisesRegex(self.g.ProgramGateError,'FILE_UNSAFE'):
            self.g.program_gate_json(path)

    def test_writer_refuses_an_over_budget_intent_before_any_active_inode_exchange(self):
        fixture=module('fixture_seed_writer_order',ROOT/'tests/test_go_erp_fixture_seed_transaction.py')
        case=fixture.FixtureSeedTransactionTests(methodName='runTest')
        self.addCleanup(case.doCleanups)
        case.setUp()
        before=case.slot_snapshot()
        # A reduced test budget exercises the actual writer admission point
        # without constructing an artificial 64 MiB state or account payload.
        with mock.patch.object(case.m,'MAX_INTENT',1), \
             mock.patch.object(case.u,'exchange',side_effect=AssertionError('active slot must not exchange')):
            with self.assertRaisesRegex(case.m.Stop,'INTENT_TOO_LARGE'):
                case.transaction()
        self.assertEqual(case.slot_snapshot(),before)
        self.assertEqual(case.protected_snapshot(),case.protected)
        self.assertFalse((case.audits()[0]/'intent.json').exists())


if __name__=='__main__':unittest.main()
