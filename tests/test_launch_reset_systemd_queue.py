#!/usr/bin/env python3
import copy
import json
import os
from pathlib import Path
import pwd
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
from systemd_queue import SystemdQueueQuiescer, QueueSafetyError


class FakeProc:
    def __init__(self):
        self.values = {}
    def pids(self):
        return list(self.values)
    def read(self, pid):
        if pid not in self.values:
            raise FileNotFoundError()
        return copy.deepcopy(self.values[pid])


class SystemdQueueTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='systemd-launch-queue-tests-')
        self.stage = Path(self.temp.name)
        self.root = self.stage / 'app'
        self.root.mkdir()
        (self.root / 'artisan').write_text('<?php // app entry\n')
        source = self.root / 'vendor/laravel/framework/src/Illuminate/Queue/Worker.php'
        source.parent.mkdir(parents=True)
        source.write_text('<?php pcntl_signal(SIGTERM, function () { $this->shouldQuit=true; });\n')
        self.actual = self.stage / 'ea-php82/php-cgi'
        self.actual.parent.mkdir()
        self.actual.write_bytes(b'CGI root-owned executable fixture' * 40000)
        self.actual.chmod(0o755)
        self.launcher = self.stage / 'php-selector'
        self.launcher.symlink_to(self.actual)
        self.resetphp = self.stage / 'reset-php'
        self.resetphp.write_text('reset PHP is separate and never executed')
        self.resetphp.chmod(0o755)
        self.systemctl = self.stage / 'systemctl'
        self.systemctl.write_text('root-owned systemctl fixture')
        self.systemctl.chmod(0o755)
        self.runtime = self.stage / 'run/systemd/system'
        self.runtime.mkdir(parents=True)
        self.fragment = self.stage / 'etc/systemd/system/laravel-queue.service'
        self.fragment.parent.mkdir(parents=True)
        self.fragment.write_text('[Service]\nUser=' + pwd.getpwuid(os.getuid()).pw_name + '\nExecStart=' + str(self.launcher) + ' ' + str(self.root / 'artisan') + ' queue:work\n')
        self.cgroup_root = self.stage / 'sys/fs/cgroup'
        self.cgroup_root.mkdir(parents=True)
        self.cg = self.cgroup_root / 'system.slice/laravel-queue.service'
        self.cg.mkdir(parents=True)
        self.uid = os.getuid()
        self.name = pwd.getpwuid(self.uid).pw_name
        self.proc = FakeProc()
        self.mainpid = 201
        self.worker_template = {'pid': 201, 'ppid': 1, 'starttime': '20100', 'uids': [self.uid] * 4,
            'pgrp': 201, 'session': 201, 'cwd': str(self.root), 'exe': str(self.actual),
            'argv': [str(self.launcher), str(self.root / 'artisan'), 'queue:work', '--sleep=3', '--tries=3'],
            'sig_caught': 1 << 14, 'cgroups': ['/system.slice/laravel-queue.service']}
        self.proc.values[201] = copy.deepcopy(self.worker_template)
        (self.cg / 'cgroup.procs').write_text('201\n')
        self.active, self.substate = 'active', 'running'
        self.loaded_override = False
        self.job = ''
        self.stop_ticks = None
        self.stop_never = False
        self.start_ticks = None
        self.start_handler_delay = 3
        self.nextpid = 300
        self.clock_value = 0
        self.calls = []
        self.messages = []
        self.receipts = []
        self.extra_properties = {}
        self.after_stop = None

    def tearDown(self):
        self.temp.cleanup()

    def manager(self):
        return SystemdQueueQuiescer(root=self.root, uid=self.uid, php=str(self.resetphp),
            runner=self.runner, proc=self.proc, runtime_root=self.runtime, cgroup_root=self.cgroup_root,
            systemctl=self.systemctl, progress=self.messages.append, clock=lambda: self.clock_value,
            sleep=self.advance)

    def advance(self, seconds):
        self.clock_value += seconds

    def finish_stop(self):
        self.proc.values.pop(self.mainpid, None)
        self.mainpid = 0
        self.active, self.substate, self.job = 'inactive', 'dead', ''
        (self.cg / 'cgroup.procs').write_text('')
        self.stop_ticks = None
        if self.after_stop:
            self.after_stop()
            self.after_stop = None

    def properties(self):
        if self.stop_ticks is not None and not self.stop_never:
            self.stop_ticks -= 1
            if self.stop_ticks <= 0:
                self.finish_stop()
        if self.start_ticks is not None:
            self.start_ticks -= 1
            if self.start_ticks <= self.start_handler_delay - 1 and self.mainpid == 0:
                self.nextpid += 1
                self.mainpid = self.nextpid
                value = copy.deepcopy(self.worker_template)
                value.update({'pid': self.mainpid, 'pgrp': self.mainpid, 'starttime': str(self.mainpid * 100), 'sig_caught': 0})
                self.proc.values[self.mainpid] = value
                (self.cg / 'cgroup.procs').write_text(str(self.mainpid) + '\n')
                self.active, self.substate, self.job = 'active', 'running', ''
            if self.start_ticks <= 0:
                self.proc.values[self.mainpid]['sig_caught'] = 1 << 14
                self.start_ticks = None
        manager = self.manager()
        values = {key: '' for key in manager.PROPERTIES}
        values.update({'Id': manager.UNIT, 'LoadState': 'loaded', 'ActiveState': self.active,
            'SubState': self.substate, 'Type': 'simple', 'MainPID': str(self.mainpid), 'ControlPID': '0',
            'ControlGroup': manager.GROUP if self.mainpid else '', 'User': self.name, 'Group': self.name,
            'WorkingDirectory': str(self.root), 'DynamicUser': 'no', 'FragmentPath': str(self.fragment),
            'DropInPaths': str(manager.override) if self.loaded_override else '',
            'NeedDaemonReload': 'yes' if manager.override.exists() != self.loaded_override else 'no',
            'RefuseManualStop': 'no', 'RefuseManualStart': 'no', 'KillMode': 'control-group', 'KillSignal': '15',
            'SendSIGKILL': 'no' if self.loaded_override else 'yes', 'SendSIGHUP': 'no',
            'TimeoutStopUSec': 'infinity' if self.loaded_override else '1min 30s', 'Restart': 'always',
            'UnitFileState': 'enabled', 'Environment': 'PRIVATE_CREDENTIAL=value', 'Job': self.job})
        args = [str(self.launcher), str(self.root / 'artisan'), 'queue:work', '--sleep=3', '--tries=3']
        values['ExecStart'] = '{ path=' + str(self.launcher) + ' ; argv[]=' + ' '.join(args) + ' ; ignore_errors=no ; start_time=[Mon 2026-10-19 12:00:00 UTC] ; stop_time=[n/a] ; pid=' + str(self.mainpid) + ' ; code=(null) ; status=0/0 }'
        values.update(self.extra_properties)
        return values

    def runner(self, arguments, timeout=15, cwd=None):
        self.calls.append(list(arguments))
        self.assertEqual(arguments[0], str(self.systemctl))
        operation = arguments[1]
        if operation == 'show':
            values = self.properties()
            return 0, ('\n'.join(key + '=' + value for key, value in values.items()) + '\n').encode()
        if operation == 'daemon-reload':
            override = self.manager().override
            if override.exists():
                self.assertEqual(override.read_bytes(), SystemdQueueQuiescer.OVERRIDE)
                self.assertEqual(override.stat().st_mode & 0o777, 0o644)
            self.loaded_override = override.exists()
            return 0, b''
        if operation == 'stop':
            self.assertEqual(arguments[2:], ['--no-block', SystemdQueueQuiescer.UNIT])
            self.assertTrue(self.loaded_override, 'stop must run only under no-kill/infinity')
            self.assertTrue(self.receipts, 'full original receipt must precede first mutation')
            self.active, self.substate, self.job = 'deactivating', 'stop-sigterm', '71'
            self.stop_ticks = 2
            return 0, b''
        if operation == 'start':
            self.assertEqual(arguments[2:], ['--no-block', SystemdQueueQuiescer.UNIT])
            self.assertFalse(self.loaded_override, 'original policy must be restored before start')
            self.assertFalse(self.manager().override.exists())
            self.assertEqual(self.mainpid, 0)
            self.active, self.substate, self.job = 'activating', 'start', '72'
            self.start_ticks = self.start_handler_delay
            return 0, b''
        self.fail('Unexpected systemctl operation')

    def changes(self):
        return [call[1] for call in self.calls if call[1] != 'show']

    def prepared(self):
        manager = self.manager()
        manager.prepare()
        self.receipts.append(manager.receipt())  # Outer root installer journals before pause.
        return manager

    def recover(self, receipt=None):
        manager = self.manager()
        manager.load_receipt(receipt or self.receipts[0])
        manager.restore()
        return manager

    def assert_original_running(self):
        self.assertEqual(self.active, 'active')
        self.assertFalse(self.loaded_override)
        self.assertFalse(self.manager().override.exists())
        self.assertEqual(self.proc.values[self.mainpid]['sig_caught'], 1 << 14)

    def test_complete_pause_restore_recovery_uses_php_cgi_without_execution(self):
        manager = self.prepared()
        self.assertFalse(self.changes())
        self.assertNotIn('PRIVATE_CREDENTIAL', json.dumps(manager.receipt()))
        self.assertNotIn('--tries=3', json.dumps(manager.receipt()))
        self.assertEqual(manager.receipt()['worker']['executable']['path'], str(self.actual))
        manager.pause()
        self.assertEqual(self.active, 'inactive')
        self.assertTrue(self.loaded_override)
        self.recover()
        self.assert_original_running()
        self.assertEqual(self.changes().count('stop'), 1)
        self.assertEqual(self.changes().count('start'), 1)
        self.recover()  # Idempotent repeated recovery does not restart again.
        self.assertEqual(self.changes().count('start'), 1)

    def test_receipt_then_crash_before_override_is_no_op_restore(self):
        self.prepared()
        self.recover()
        self.assert_original_running()
        self.assertFalse(self.changes())

    def test_atomic_override_write_crash_before_publication_leaves_no_partial_conf(self):
        manager = self.prepared()
        def interrupted(*args, **kwargs):
            self.assertFalse(manager.override.exists())
            raise OSError('simulated publication interruption')
        with patch('systemd_queue.os.link', side_effect=interrupted):
            with self.assertRaises(OSError):
                manager._publish_override()
        self.assertFalse(manager.override.exists())
        self.recover()
        self.assert_original_running()

    def test_crash_after_publish_before_reload_is_recovered_gracefully(self):
        manager = self.prepared()
        manager._publish_override()
        self.assertFalse(self.loaded_override)
        self.recover()
        self.assert_original_running()
        self.assertEqual(self.changes().count('stop'), 1)

    def test_crash_after_reload_before_stop_and_active_queued_stop_recover(self):
        manager = self.prepared()
        manager._arm()
        self.job = '71'  # PID 1 may still report active while stop is queued.
        self.recover()
        self.assert_original_running()
        self.assertEqual(self.changes().count('stop'), 1)

    def test_stop_timeout_and_second_restore_timeout_keep_protective_policy(self):
        manager = self.prepared()
        self.stop_never = True
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_STOP_PENDING_OVERRIDE_RETAINED'):
            manager.pause()
        self.assertEqual(self.clock_value, 45)
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_STOP_PENDING_OVERRIDE_RETAINED'):
            self.recover()
        self.assertEqual(self.clock_value, 90)
        self.assertTrue(manager.override.exists())
        self.assertTrue(self.loaded_override)
        self.assertNotIn('start', self.changes())
        self.assertTrue(any('WAITING_FOR_GRACEFUL_STOP' in message for message in self.messages))
        self.stop_never = False
        self.finish_stop()
        self.recover()
        self.assert_original_running()

    def test_crash_after_unlink_before_reload_then_after_reload_before_start(self):
        manager = self.prepared()
        manager.pause()
        manager.override.unlink()
        self.assertTrue(self.loaded_override)
        self.recover()
        self.assert_original_running()
        # Repeat with a fresh receipt for a second protected pause.
        manager = self.prepared()
        manager.pause()
        manager._remove_override()
        self.assertEqual(self.active, 'inactive')
        self.recover(self.receipts[-1])
        self.assert_original_running()

    def test_changed_override_or_unit_source_is_never_overwritten(self):
        manager = self.prepared()
        manager._publish_override()
        manager.override.write_bytes(b'changed administrator policy')
        before = list(self.changes())
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_OWN_OVERRIDE_CHANGED'):
            self.recover()
        self.assertEqual(manager.override.read_bytes(), b'changed administrator policy')
        self.assertEqual(before, self.changes())
        manager.override.write_bytes(manager.OVERRIDE)
        self.fragment.write_text(self.fragment.read_text() + '# root administrator edit\n')
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_SOURCE_CHANGED'):
            self.recover()
        self.assertEqual(before, self.changes())

    def test_v1_systemd_cgroup_layout_and_missing_stopped_cgroup_supported(self):
        old = self.cg
        self.cg = self.cgroup_root / 'systemd/system.slice/laravel-queue.service'
        self.cg.parent.mkdir(parents=True)
        old.rename(self.cg)
        manager = self.prepared()
        manager.pause()
        (self.cg / 'cgroup.procs').unlink()
        self.cg.rmdir()
        # Recreate for the test start simulation after validating stopped disappearance.
        fresh = self.manager()
        fresh.load_receipt(self.receipts[0])
        self.cg.mkdir()
        (self.cg / 'cgroup.procs').write_text('')
        fresh.restore()
        self.assert_original_running()

    def test_foreign_cgroup_pid_or_additional_app_worker_blocks_before_pause(self):
        (self.cg / 'cgroup.procs').write_text('201\n999\n')
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_FOREIGN_CGROUP_PROCESS'):
            self.manager().prepare()
        (self.cg / 'cgroup.procs').write_text('201\n')
        foreign = copy.deepcopy(self.worker_template)
        foreign.update({'pid': 999, 'starttime': '99900', 'cgroups': ['/other.service']})
        self.proc.values[999] = foreign
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_ADDITIONAL_OR_MISSING_APP_WORKER'):
            self.manager().prepare()
        self.assertFalse(self.changes())

    def test_additional_worker_unknown_prefix_with_attached_script_or_separator_refused(self):
        for script in (['-fartisan', 'queue:work'], ['--file=artisan', 'queue:listen'],
                       ['artisan', '--', 'queue:work'], [str(self.root / 'artisan'), '--', 'horizon:work']):
            with self.subTest(script=script):
                foreign = copy.deepcopy(self.worker_template)
                foreign.update({'pid': 999, 'starttime': '99900', 'cgroups': ['/other.service'],
                                'argv': [str(self.launcher), '-e'] + script})
                self.proc.values[999] = foreign
                with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_UNREVIEWED_APP_WORKER'):
                    self.manager().prepare()
        self.assertFalse(self.changes())

    def test_worker_appearing_after_stop_blocks_reset(self):
        manager = self.prepared()
        def foreign_worker():
            foreign = copy.deepcopy(self.worker_template)
            foreign.update({'pid': 999, 'starttime': '99900', 'cgroups': ['/other.service']})
            self.proc.values[999] = foreign
        self.after_stop = foreign_worker
        with self.assertRaisesRegex(QueueSafetyError, 'SYSTEMD_QUEUE_ADDITIONAL_OR_MISSING_APP_WORKER'):
            manager.pause()
        self.assertTrue(manager.override.exists())
        self.assertTrue(self.loaded_override)

    def test_unit_hooks_and_cross_service_propagation_refused(self):
        for property in ('ExecStop', 'ExecStartPost', 'TriggeredBy', 'RequiredBy'):
            with self.subTest(property=property):
                self.extra_properties[property] = 'PRIVATE_UNREVIEWED_HOOK'
                with self.assertRaises(QueueSafetyError):
                    self.manager().prepare()
                self.extra_properties.pop(property)
        self.assertFalse(self.changes())
        self.assertNotIn('PRIVATE_UNREVIEWED_HOOK', '\n'.join(self.messages))


if __name__ == '__main__':
    unittest.main()
