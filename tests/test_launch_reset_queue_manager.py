#!/usr/bin/env python3
import copy
import json
import os
from pathlib import Path
import signal
import shlex
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
        self.probe_targets = {}
        self.messages = []
        self.test_php_paths = set()
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

    def runner(self, args, timeout=15, cwd=None):
        self.commands.append(list(args))
        if args[0] == 'runuser':
            self.assertEqual(cwd, str(self.root))
            self.assertEqual(args[5:7], ['--no-php-ini', '-r'])
            self.assertIn("readlink('/proc/self/exe')", args[7])
            target = self.probe_targets.get(args[4], args[4])
            return 0, json.dumps({'version': 80306, 'binary': args[4], 'executable': target}).encode()
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
        manager = QueueQuiescer(root=self.root, uid=self.uid, php=self.php, runner=self.runner,
                              proc=self.proc, supervisorctl=str(self.ctl), progress=self.messages.append)
        original_classify = manager._php_class
        manager._php_class = lambda path: 'test_php' if path in self.test_php_paths else original_classify(path)
        return manager

    def mutations(self):
        return [args[3:] for args in self.commands if args[0] == str(self.ctl) and args[3] in ('stop', 'start')]

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

    def set_arguments(self, arguments, executable=None, launcher=None):
        name = 'appqueue:appqueue_00'
        for value in (self.proc.values[201], self.templates[name]):
            value['argv'] = list(arguments)
            if executable is not None:
                value['exe'] = str(executable)
        command = list(arguments)
        if launcher is not None:
            command[0] = str(launcher)
        path = self.include / 'appqueue.conf'
        content = path.read_text().splitlines()
        path.write_text('\n'.join('command=' + ' '.join(shlex.quote(value) for value in command) if line.startswith('command=') else line for line in content) + '\n')

    def test_common_php_prefixes_and_script_selectors_preserve_exact_command(self):
        variants = [
            ['-q', '-n', '-d', 'memory_limit=512M', '-c', '/etc/php.ini', 'artisan', 'queue:work'],
            ['-dmemory_limit=512M', '-c/etc/php.ini', 'artisan', 'queue:work'],
            ['-f', 'artisan', '--', 'queue:work'], ['--file', 'artisan', 'queue:work'],
            ['-fartisan', 'queue:work'], ['--file=artisan', 'queue:work'],
            ['--no-php-ini', '--define=memory_limit=512M', '--php-ini', '/etc/php.ini', 'artisan', 'queue:work']]
        hashes = set()
        for variant in variants:
            with self.subTest(prefix=variant):
                self.set_arguments([str(self.php)] + variant)
                receipt = self.instance().prepare()
                worker = receipt['managers'][0]['programs'][0]['worker']
                hashes.add(worker['command_sha256'])
                self.assertGreaterEqual(worker['minimum_stopwait'], 120)
                self.assertNotIn('memory_limit=512M', json.dumps(receipt))
        self.assertEqual(len(hashes), len(variants))
        self.assertFalse(self.mutations())

    def test_actual_interpreter_can_differ_from_reset_php_and_exceed_one_megabyte(self):
        actual = self.stage / 'php83'
        actual.write_bytes(b'root-owned-test-binary' * 60000)
        actual.chmod(0o755)
        self.test_php_paths.add(str(actual))
        self.set_arguments([str(actual), 'artisan', 'queue:work'], executable=actual)
        manager = self.instance()
        receipt = manager.prepare()
        self.assertEqual(receipt['php'], str(self.php))
        self.assertEqual(receipt['interpreters'][0]['path'], str(actual))
        self.assertGreater(receipt['interpreters'][0]['fingerprint']['size'], 1048576)
        manager.pause(self.journal.append)
        manager.restore()
        self.assertEqual(self.statuses['appqueue:appqueue_00']['state'], 'RUNNING')

    def test_cpanel_selector_probe_uses_actual_executable_even_with_aliased_php_binary(self):
        selector = self.stage / 'ea-php-cli'
        selector.write_text('#!/bin/sh\nroot-owned selector fixture\n')
        selector.chmod(0o755)
        self.test_php_paths.add(str(selector))
        self.probe_targets[str(selector)] = str(self.php)
        self.set_arguments([str(self.php), '-q', 'artisan', 'queue:work'], launcher=selector)
        receipt = self.instance().prepare()
        self.assertEqual(receipt['launchers'][0]['path'], str(selector))
        self.assertEqual(receipt['launchers'][0]['interpreter'], str(self.php))

    def test_php_launcher_alias_drift_is_rejected_before_any_stop(self):
        alias = self.stage / 'php-alias'
        alias.symlink_to(self.php)
        other = self.stage / 'php83'
        other.write_text('#!/bin/sh\nroot-owned other PHP fixture\n')
        other.chmod(0o755)
        self.test_php_paths.add(str(other))
        self.probe_targets[str(alias)] = str(self.php)
        self.set_arguments([str(self.php), 'artisan', 'queue:work'], launcher=alias)
        manager = self.instance()
        manager.prepare()
        alias.unlink()
        alias.symlink_to(other)
        self.probe_targets[str(alias)] = str(other)
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_WORKER_PHP_LAUNCHER_CHANGED'):
            manager.pause(self.journal.append)
        self.assertFalse(self.mutations())

    def test_prefix_execution_modes_missing_operands_and_consumed_artisan_rejected(self):
        manager = self.instance()
        for arguments in ([str(self.php), '-r', 'PRIVATE_SCRIPT_VALUE', 'artisan', 'queue:work'],
                          [str(self.php), '-d', 'artisan', 'artisan', 'queue:work'],
                          [str(self.php), '-c', 'artisan', 'artisan', 'queue:work']):
            self.set_arguments(arguments)
            with self.assertRaises(QueueSafetyError):
                self.instance().prepare()
        for arguments in ([str(self.php), '-d'], [str(self.php), '-c'], [str(self.php), '-f']):
            with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_PHP_PREFIX_OPERAND_MISSING'):
                manager._php_script(arguments, str(self.root))
        self.assertNotIn('PRIVATE_SCRIPT_VALUE', '\n'.join(self.messages))
        self.assertFalse(self.mutations())

    def test_later_artisan_argument_does_not_misclassify_another_php_script(self):
        self.set_arguments([str(self.php), 'other.php', 'artisan', 'queue:work'])
        self.assertEqual(self.instance().prepare()['managers'], [])
        self.assertFalse(self.mutations())

    def test_sanitized_profile_reports_uids_deleted_flag_and_parent_unit(self):
        value = self.proc.values[201]
        value['uids'][2] = self.uid + 1
        value['cgroups'] = ['/system.slice/supervisord.service']
        with self.assertRaisesRegex(QueueSafetyError, 'QUEUE_WORKER_PROFILE_UNSUPPORTED'):
            self.instance().prepare()
        detail = json.loads(next(message.partition('=')[2] for message in self.messages if message.startswith('QUEUE_WORKER_PROFILE=')))
        self.assertEqual(detail['parent_manager'], 'supervisord')
        self.assertEqual(detail['systemd_unit'], 'supervisord.service')
        self.assertFalse(detail['uids_match']['saved'])
        self.assertFalse(detail['interpreter_deleted'])
        self.assertNotIn('PRIVATE_ARGUMENT_VALUE', '\n'.join(self.messages))
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
