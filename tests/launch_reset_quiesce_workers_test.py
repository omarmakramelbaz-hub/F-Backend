#!/usr/bin/env python3
from pathlib import Path
import json
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deployment/launch_reset'))
from quiesce_workers import APPLICATION_ROOT, FAMILIES, TimerQuiescer, WorkerPauseError


class FakeSystemctl:
    def __init__(self):
        self.values = {}
        self.sources = {}
        self.actions = []
        self.time = 0
        self.fail_stop = None
        for family in FAMILIES:
            timer = 'fasakhansta-' + family + '.timer'
            service = 'fasakhansta-' + family + '.service'
            for name in [timer, service]:
                self.values[name] = {'Id': name, 'LoadState': 'loaded', 'ActiveState': 'active' if name == timer else 'inactive',
                    'FragmentPath': '/etc/systemd/system/' + name, 'DropInPaths': ''}
            self.values[timer]['Triggers'] = service
            self.values[service].update(User='fasakha', WorkingDirectory=APPLICATION_ROOT, Type='oneshot')
            self.sources[timer] = ('[Timer]\nUnit=' + service + '\n').encode()
            self.sources[service] = ('[Service]\nUser=fasakha\nWorkingDirectory=' + APPLICATION_ROOT + '\nType=oneshot\n').encode()

    def show(self, name, timeout=3):
        return dict(self.values[name])

    def action(self, verb, name):
        self.actions.append((verb, name))
        self.values[name]['ActiveState'] = 'active' if verb == 'start' else 'inactive'
        if verb == 'stop' and name == self.fail_stop:
            raise WorkerPauseError('APPLICATION_SYSTEMD_COMMAND_FAILED')

    def sleep(self, seconds):
        self.time += seconds

    def quiescer(self):
        return TimerQuiescer(systemctl=self, read_unit=self.sources.__getitem__, monotonic=lambda: self.time,
                             sleep=self.sleep, progress=lambda message: None)


class TimerQuiesceTest(unittest.TestCase):
    def test_prepare_is_read_only_and_pause_restore_only_prior_active_timers(self):
        system = FakeSystemctl()
        inactive = 'fasakhansta-meta-ai.timer'
        system.values[inactive]['ActiveState'] = 'inactive'
        controller = system.quiescer()
        controller.prepare()
        self.assertEqual(system.actions, [])
        controller.pause()
        self.assertEqual(len(system.actions), 3)
        controller.restore()
        self.assertEqual(len(system.actions), 6)
        self.assertNotIn(('start', inactive), system.actions)
        self.assertTrue(all(name.endswith('.timer') for verb, name in system.actions))
        controller.restore()
        self.assertEqual(len(system.actions), 6)

    def test_running_oneshot_finishes_naturally_without_stop_or_restart(self):
        system = FakeSystemctl()
        name = 'fasakhansta-customer-crm.service'
        system.values[name]['ActiveState'] = 'activating'
        controller = system.quiescer()
        def finish(seconds):
            system.sleep(seconds)
            if system.time >= 3:
                system.values[name]['ActiveState'] = 'inactive'
        controller.sleep = finish
        controller.prepare()
        controller.pause()
        self.assertEqual(system.time, 3)
        controller.restore()
        self.assertTrue(all(unit.endswith('.timer') for verb, unit in system.actions))

    def test_busy_service_fails_before_reset_and_caller_can_restore(self):
        system = FakeSystemctl()
        system.values['fasakhansta-meta-ai.service']['ActiveState'] = 'activating'
        controller = system.quiescer()
        controller.prepare()
        with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_BACKGROUND_WORKERS_BUSY_NO_RESET$'):
            controller.pause()
        self.assertEqual(system.time, 45)
        controller.restore()
        self.assertEqual(len(system.actions), 8)

    def test_scope_mismatch_or_dropin_fails_without_actions(self):
        for key, value in [('User', 'root'), ('WorkingDirectory', '/another/app'), ('DropInPaths', '/etc/secret.conf')]:
            system = FakeSystemctl()
            system.values['fasakhansta-meta-ai.service'][key] = value
            with self.assertRaises(WorkerPauseError):
                system.quiescer().prepare()
            self.assertEqual(system.actions, [])

    def test_source_service_scope_mismatch_is_rejected(self):
        system = FakeSystemctl()
        system.sources['fasakhansta-meta-ai.service'] = b'[Service]\nUser=root\n'
        with self.assertRaises(WorkerPauseError):
            system.quiescer().prepare()
        self.assertEqual(system.actions, [])

    def test_replaced_source_or_new_active_timer_fails_before_first_stop(self):
        system = FakeSystemctl()
        controller = system.quiescer()
        controller.prepare()
        system.sources['fasakhansta-meta-ai.timer'] += b'# changed\n'
        with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_UNIT_SOURCE_CHANGED$'):
            controller.pause()
        self.assertEqual(system.actions, [])

    def test_previously_inactive_timer_becoming_active_or_transient_is_rejected(self):
        for changed in ['active', 'activating', 'deactivating']:
            system = FakeSystemctl()
            name = 'fasakhansta-meta-ai.timer'
            system.values[name]['ActiveState'] = 'inactive'
            controller = system.quiescer()
            controller.prepare()
            system.values[name]['ActiveState'] = changed
            with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_TIMER_ACTIVE_STATE_CHANGED$'):
                controller.pause()
            self.assertEqual(system.actions, [])

    def test_partial_stop_failure_remains_restorable(self):
        system = FakeSystemctl()
        system.fail_stop = 'fasakhansta-meta-messaging.timer'
        controller = system.quiescer()
        controller.prepare()
        with self.assertRaises(WorkerPauseError):
            controller.pause()
        self.assertEqual(len(controller.stopped_timers), 2)
        controller.restore()
        self.assertEqual(controller.stopped_timers, [])
        self.assertEqual(sum(verb == 'start' for verb, name in system.actions), 2)

    def test_unknown_timer_target_is_rejected(self):
        system = FakeSystemctl()
        system.values['fasakhansta-meta-ai.timer']['Triggers'] = 'unrelated.service'
        with self.assertRaises(WorkerPauseError):
            system.quiescer().prepare()
        self.assertEqual(system.actions, [])

    def test_timer_cannot_propagate_stop_to_service_or_other_units(self):
        for key in ['ConsistsOf', 'BoundBy', 'RequiredBy', 'PropagatesStopTo']:
            system = FakeSystemctl()
            system.values['fasakhansta-meta-ai.timer'][key] = 'fasakhansta-meta-ai.service'
            with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_TIMER_STOP_PROPAGATION_REQUIRES_REVIEW$'):
                system.quiescer().prepare()
            self.assertEqual(system.actions, [])

    def test_restore_refuses_replaced_unit_but_restores_other_timers(self):
        system = FakeSystemctl()
        controller = system.quiescer()
        controller.prepare()
        controller.pause()
        system.sources['fasakhansta-meta-ai.service'] += b'# replaced\n'
        with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_TIMER_RESTORE_FAILED$'):
            controller.restore()
        self.assertNotIn(('start', 'fasakhansta-meta-ai.timer'), system.actions)
        self.assertEqual(controller.stopped_timers, ['fasakhansta-meta-ai.timer'])

    def test_receipt_written_before_actions_restores_partial_pause_in_fresh_process(self):
        system = FakeSystemctl()
        inactive = 'fasakhansta-meta-ai.timer'
        system.values[inactive]['ActiveState'] = 'inactive'
        controller = system.quiescer()
        controller.prepare()
        receipt = json.loads(json.dumps(controller.receipt()))
        self.assertEqual(system.actions, [])
        system.fail_stop = 'fasakhansta-meta-messaging.timer'
        with self.assertRaises(WorkerPauseError):
            controller.pause()
        restored = system.quiescer().load_receipt(receipt)
        restored.restore()
        self.assertNotIn(('start', inactive), system.actions)
        self.assertEqual(sum(verb == 'start' for verb, name in system.actions), 2)
        self.assertTrue(all(name.endswith('.timer') for verb, name in system.actions))

    def test_receipt_strict_schema_rejects_unknown_unit_and_invalid_hash(self):
        for field, value in [('digest', 'SECRET'), ('was_active', 1)]:
            system = FakeSystemctl()
            controller = system.quiescer()
            controller.prepare()
            receipt = controller.receipt()
            receipt['units']['fasakhansta-meta-ai.timer'][field] = value
            with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_TIMER_RECEIPT_INVALID$'):
                system.quiescer().load_receipt(receipt)
            self.assertEqual(system.actions, [])
        system = FakeSystemctl()
        controller = system.quiescer()
        controller.prepare()
        receipt = controller.receipt()
        receipt['units']['unrelated.timer'] = receipt['units'].pop('fasakhansta-meta-ai.timer')
        with self.assertRaisesRegex(WorkerPauseError, '^APPLICATION_TIMER_RECEIPT_INVALID$'):
            system.quiescer().load_receipt(receipt)
        self.assertEqual(system.actions, [])


if __name__ == '__main__':
    unittest.main()
