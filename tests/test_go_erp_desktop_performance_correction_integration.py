"""Coordinator ordering and owned correction resume routing."""
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

BASE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('correction_integration_rollout',
    BASE / 'deployment/upgrade_go_erp_desktop_performance_root.py')
roll = importlib.util.module_from_spec(spec)
spec.loader.exec_module(roll)


class CorrectionRouting(unittest.TestCase):
    def fixture(self, saved):
        value = roll.Rollout.__new__(roll.Rollout)
        value.bad_binding = {'fixture': 'immutable old binding'}
        flow = {'binding_sha256': roll.sha(roll.canonical(value.bad_binding)),
                'phase': 'prepared', 'current': {'original': 'observation'}}
        value.flow = None
        value.o = SimpleNamespace(_load=Mock(return_value={'phase': 'ready'}))
        value.e = SimpleNamespace(read_file=Mock(return_value=(roll.canonical(flow), None)))
        value.io = SimpleNamespace(parse=json.loads)
        value.invariant = Mock()
        value.current, value.payloads = object(), object()
        value.native = SimpleNamespace(PROFILES={})
        value.manual_values = Mock(return_value={'owned': b'bad manual source'})
        effects = SimpleNamespace(read_saved=Mock(return_value=saved),
            recover=Mock(return_value={'owned': 'pins'}))
        evidence = SimpleNamespace(collect=Mock(return_value={'verified': 'evidence'}))
        value.recovery = {'effects': effects, 'plan': object(), 'evidence': evidence}
        return value, flow, effects, evidence

    def test_completed_correction_uses_receipt_without_rechecking_old_only_state(self):
        value, flow, effects, evidence = self.fixture({'completed': True})
        value.e.read_file.side_effect = AssertionError('No old-only source observation on completed resume')
        verify = Mock(return_value=True)
        value.correct_pending(verify)
        effects.read_saved.assert_called_once_with(value, value.recovery['plan'], verify)
        effects.recover.assert_called_once_with(value, value.recovery['plan'], verify)
        evidence.collect.assert_not_called()
        value.manual_values.assert_not_called()
        self.assertEqual(value.manual_previous_pins, {'owned': 'pins'})

    def test_partial_correction_collects_from_saved_original_performance_record(self):
        value, flow, effects, evidence = self.fixture(None)
        saved = {'completed': False, 'performance_journal': flow}
        effects.read_saved.return_value = saved
        value.e.read_file.side_effect = AssertionError('Current root journal may already be corrected')
        observation = {'profile': {'core': 'original current core'}}
        verify = Mock(return_value=True)
        with patch.object(roll, '_reconstruct', return_value=observation) as reconstruct:
            value.correct_pending(verify)
        reconstruct.assert_called_once_with(value.o, value.current, value.payloads, flow['current'], verify)
        self.assertEqual(value.native.PROFILES[60], observation['profile'])
        evidence.collect.assert_called_once_with(value, observation,
            value.manual_values.return_value, verify, saved=saved)
        effects.recover.assert_called_once_with(value, value.recovery['plan'], verify,
            evidence={'verified': 'evidence'})

    def test_first_correction_requires_exact_old_binding_before_any_collector(self):
        value, flow, effects, evidence = self.fixture(None)
        value.e.read_file.return_value = (roll.canonical({**flow, 'binding_sha256': 'f' * 64}), None)
        with self.assertRaisesRegex(roll.Stop, 'FAILED_BINDING_CHANGED'):
            value.correct_pending(lambda: True)
        evidence.collect.assert_not_called()
        effects.recover.assert_not_called()
        value.o._load.assert_not_called()

    def test_failed_fresh_evidence_cannot_enter_correction_effects(self):
        value, flow, effects, evidence = self.fixture(None)
        evidence.collect.side_effect = RuntimeError('source or native evidence changed')
        with patch.object(roll, '_reconstruct', return_value={'profile': {}}), \
                self.assertRaisesRegex(RuntimeError, 'evidence changed'):
            value.correct_pending(lambda: True)
        effects.recover.assert_not_called()


if __name__ == '__main__':
    unittest.main()
