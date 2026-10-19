#!/usr/bin/env python3
import copy
import json
import os
from pathlib import Path
import signal
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
from queue_manager import QueueQuiescer, QueueSafetyError


class FakeProc:
    def __init__(self):
        self.values = {}

    def pids(self):
        return list(self.values)

    def read(self, pid):
        if pid not in self.values:
            raise FileNotFoundError()
        return copy.deepcopy(self.values[pid])


class QueueManagerTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix='launch-queue-manager-tests-')
        self.stage = Path(self.folder.name)
        self.root = self.stage / 'app'
        self.root.mkdir()
        self.php = self.stage / 'php'
        self.ctl = self.stage / 'supervisorctl'
        for path in (self.php, self.ctl):
            path.write_text('#!/bin/sh\nexit 0\n')
            path.chmod(0o755)
        (self.root / 'artisan').write_text('<?php // application entry\n')
        worker_source = self.root / 'vendor/laravel/framework/src/Illuminate/Queue/Worker.php'
        worker_source.parent.mkdir(parents=True)
        worker_source.write_text('<?php pcntl_signal(SIGTERM, function () { $this->shouldQuit = true; });\n')
        self.main = self.stage / 'supervisord.conf'
        self.include = self.stage / 'conf.d'
        self.include.mkdir()
        self.main.write_text('[supervisorctl]\nserverurl=unix:///tmp/queue-test.sock\n[include]\nfiles=conf.d/*.conf\n')
        self.proc = FakeProc()
        self.uid = os.getuid()
        self.proc.values[100] = {'pid': 100, 'ppid': 1, 'pgrp': 100, 'session': 100, 'starttime': '10000', 'uids': [0] * 4,
            'cwd': str(self.stage), 'exe': '/usr/bin/python3',
            'argv': ['/usr/bin/python3', '/usr/bin/supervisord', '-c', str(self.main)], 'sig_caught': 0}
        self.commands = []
        self.statuses = {}
        self.templates = {}
        self.nextpid = 300
        self.fail_stop = None
        self.journal = []
        self.add_worker('appqueue', 201)
        self.statuses['unrelated'] = {'state': 'STOPPED', 'pid': 0}

    def tearDown(self):
        self.folder.cleanup()

    def add_worker(self, program, pid, stopwait=150, group=None):
        group = group or program
        args = [str(self.php), 'artisan', 'queue:work', '--sleep=3', '--timeout=60', '--name=PRIVATE_ARGUMENT_VALUE']
        value = {'pid': pid, 'ppid': 100, 'pgrp': pid, 'session': 100, 'starttime': str(pid * 100), 'uids': [self.uid] * 4,
                 'cwd': str(self.root), 'exe': str(self.php), 'argv': args,
                 'sig_caught': 1 << (signal.SIGTERM - 1)}
        self.proc.values[pid] = value
        self.templates[group + ':' + program + '_00'] = value
        name = group + ':' + program + '_00'
        self.statuses[name] = {'state': 'RUNNING', 'pid': pid}
        import pwd
        config = ('[program:' + program + ']\ncommand=' + ' '.join(args) + '\ndirectory=' + str(self.root)
                  + '\nuser=' + pwd.getpwuid(self.uid).pw_name + '\nprocess_name=%(program_name)s_%(process_num)02d'
                  + '\nstopwaitsecs=' + str(stopwait) + '\nstopsignal=TERM\n')
        if group != program:
            config += '[group:' + group + ']\nprograms=' + program + '\n'
        (self.include / (program + '.conf')).write_text(config)
        return name

    def runner(self, args, timeout=15):
        self.commands.append(list(args))
        self.assertEqual(args[:3], [str(self.ctl), '-c', str(self.main)])
        command = args[3]
        if command == 'pid':
            return 0, b'100\n'
        if command == 'status':
            names = [args[4]] if len(args) == 5 else sorted(self.statuses)
            lines = []
            for name in names:
                value = self.statuses[name]
                lines.append(name + ' ' + value['state'] + (' pid ' + str(value['pid']) + ', uptime 1 day' if value['pid'] else ' Not started'))
            return 3 if any(self.statuses[name]['state'] != 'RUNNING' for name in names) else 0, ('\n'.join(lines) + '\n').encode()
        name = args[4]
        self.assertNotIn('*', name)
        self.assertNotEqual(name, 'all')
        if command == 'stop':
            self.assertTrue(self.journal, 'original full roster must be journaled before stopping')
            if name == self.fail_stop:
                return 1, b'PRIVATE_ERROR_SHOULD_NOT_ESCAPE'
            self.proc.values.pop(self.statuses[name]['pid'])
            self.statuses[name] = {'state': 'STOPPED', 'pid': 0}
            return 0, b'stopped\n'
        if command == 'start':
            self.nextpid += 1
            worker = copy.deepcopy(self.templates[name])
            worker['pid'], worker['pgrp'], worker['starttime'] = self.nextpid, self.nextpid, str(self.nextpid * 100)
            self.proc.values[self.nextpid] = worker
            self.statuses[name] = {'state': 'RUNNING', 'pid': self.nextpid}
            return 0, b'started\n'
        self.fail('Unexpected command')

    def instance(self):
        return QueueQuiescer(root=self.root, uid=self.uid, php=self.php, runner=self.runner,
                              proc=self.proc, supervisorctl=str(self.ctl), progress=lambda text: None)

    def mutations(self):
        return [args[3:] for args in self.commands if args[3] in ('stop', 'start')]

    def test_read_only_prepare_and_durable_restart_receipt(self):
        manager = self.instance()
        receipt = manager.prepare()
        self.assertFalse(self.mutations())
        self.assertNotIn('PRIVATE_ARGUMENT_VALUE', json.dumps(receipt))
        self.assertNotIn('argv', json.dumps(receipt))
        manager.pause(self.journal.append)
        self.assertEqual(self.mutations(), [['stop', 'appqueue:appqueue_00']])
        fresh = self.instance()
        fresh.load_receipt(json.loads(json.dumps(self.journal[-1])))
        fresh.restore(self.journal.append)
        self.assertEqual(self.mutations(), [['stop', 'appqueue:appqueue_00'], ['start', 'appqueue:appqueue_00']])
        fresh.restore()
        self.assertEqual(len(self.mutations()), 2)
        self.assertEqual(self.statuses['unrelated']['state'], 'STOPPED')

    def test_short_stopwait_rejected_before_any_mutation(self):
        path = self.include / 'appqueue.conf'
        path.write_text(path.read_text().replace('stopwaitsecs=150', 'stopwaitsecs=10'))
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_GRACEFUL_STOP_WINDOW_TOO_SHORT'):
            self.instance().prepare()
        self.assertFalse(self.mutations())

    def test_missing_signal_handler_and_unmanaged_workers_rejected(self):
        self.proc.values[201]['sig_caught'] = 0
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_WORKER_GRACEFUL_TERM_UNAVAILABLE'):
            self.instance().prepare()
        self.proc.values[201]['sig_caught'] = 1 << (signal.SIGTERM - 1)
        self.proc.values[100]['argv'] = ['/bin/bash']
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_UNSUPPORTED_SUPERVISOR_REQUIRED'):
            self.instance().prepare()
        self.assertFalse(self.mutations())

    def test_pid_reuse_and_config_changes_fail_before_stop(self):
        manager = self.instance()
        manager.prepare()
        self.proc.values[201]['starttime'] = '999999'
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_WORKERS_CHANGED_BEFORE_PAUSE'):
            manager.pause(self.journal.append)
        self.assertFalse(self.mutations())
        self.proc.values[201]['starttime'] = '20100'
        (self.include / 'appqueue.conf').write_text((self.include / 'appqueue.conf').read_text() + '# concurrent change\n')
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_CONFIG_CHANGED'):
            manager.pause(self.journal.append)
        self.assertFalse(self.mutations())

    def test_partial_pause_restores_all_original_running_programs_only(self):
        second = self.add_worker('second', 202, group='appworkers')
        manager = self.instance()
        manager.prepare()
        self.fail_stop = second
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_COMMAND_FAILED'):
            manager.pause(self.journal.append)
        receipt = self.journal[0]  # The pre-action receipt contains the full roster.
        self.assertEqual(len(receipt['managers'][0]['programs']), 2)
        fresh = self.instance()
        fresh.load_receipt(receipt)
        fresh.restore()
        self.assertEqual(self.mutations()[-1], ['start', 'appqueue:appqueue_00'])
        self.assertEqual(self.statuses[second], {'state': 'RUNNING', 'pid': 202})
        self.assertEqual(self.statuses['unrelated']['state'], 'STOPPED')

    def test_manager_identity_change_prevents_restore(self):
        manager = self.instance()
        manager.prepare()
        manager.pause(self.journal.append)
        self.proc.values[100]['starttime'] = 'new-manager'
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_IDENTITY_CHANGED'):
            self.instance().load_receipt(self.journal[-1])
        self.assertEqual(self.mutations(), [['stop', 'appqueue:appqueue_00']])

    def test_config_environment_interpolation_fails_safe(self):
        path = self.include / 'appqueue.conf'
        path.write_text(path.read_text().replace(str(self.php), '%(ENV_PHP_EXECUTABLE)s'))
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_CONFIG_INTERPOLATION_UNSUPPORTED'):
            self.instance().prepare()
        self.assertFalse(self.mutations())

    def test_supervisor_group_flags_only_for_isolated_worker_group(self):
        path = self.include / 'appqueue.conf'
        path.write_text(path.read_text() + 'stopasgroup=true\nkillasgroup=true\n')
        manager = self.instance()
        receipt = manager.prepare()
        self.assertTrue(receipt['managers'][0]['programs'][0]['group_signals'])
        manager.pause(self.journal.append)
        manager.restore()
        self.assertEqual(self.mutations(), [['stop', 'appqueue:appqueue_00'], ['start', 'appqueue:appqueue_00']])

    def test_group_member_appearing_after_prepare_blocks_stop(self):
        path = self.include / 'appqueue.conf'
        path.write_text(path.read_text() + 'stopasgroup=true\nkillasgroup=true\n')
        manager = self.instance()
        manager.prepare()
        member = copy.deepcopy(self.proc.values[100])
        member.update({'pid': 999, 'ppid': 201, 'pgrp': 201})
        self.proc.values[999] = member
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_WORKER_GROUP_NOT_ISOLATED'):
            manager.pause(self.journal.append)
        self.assertFalse(self.mutations())

    def test_shared_worker_group_rejected_before_pause(self):
        path = self.include / 'appqueue.conf'
        path.write_text(path.read_text() + 'killasgroup=true\n')
        self.proc.values[201]['pgrp'] = 100
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_MANAGER_WORKER_GROUP_NOT_ISOLATED'):
            self.instance().prepare()
        self.assertFalse(self.mutations())

    def test_no_workers_is_read_only_and_restores_nothing(self):
        self.proc.values.pop(201)
        manager = self.instance()
        self.assertEqual(manager.prepare()['managers'], [])
        manager.pause(self.journal.append)
        manager.restore()
        self.assertFalse(self.commands)


if __name__ == '__main__':
    unittest.main()
