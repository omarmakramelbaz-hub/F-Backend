#!/usr/bin/env python3
"""Orchestrator ordering and receipt safety without touching real workers."""
import copy
import json
import os
from pathlib import Path
import stat
import shutil
import subprocess
import sys
import tempfile
import unittest
import uuid
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/launch_reset'))
import worker_control as workers


class RecordingWorkers:
    def __init__(self, name, owner):
        self.name = name
        self.owner = owner

    def prepare(self):
        self.owner.events.append((self.name, 'prepare'))
        self.owner.fail_if(self.name, 'prepare')

    def receipt(self):
        self.owner.events.append((self.name, 'receipt'))
        return {'kind': self.name, 'was_active': self.name == 'timers'}

    def load_receipt(self, receipt):
        self.owner.events.append((self.name, 'load'))
        if receipt != {'kind': self.name, 'was_active': self.name == 'timers'}:
            raise RuntimeError('GROUP_RECEIPT_INVALID')
        self.owner.fail_if(self.name, 'load')

    def pause(self):
        # The durable file must already contain both original states, including
        # a group whose pause has not yet been attempted.
        payload = workers.read_state(self.owner.state)
        self.owner.assertEqual(set(payload), {'version', 'root', 'uid', 'timers', 'queue'})
        self.owner.assertEqual(payload['timers']['kind'], 'timers')
        self.owner.assertEqual(payload['queue']['kind'], 'queue')
        self.owner.events.append((self.name, 'pause'))
        self.owner.fail_if(self.name, 'pause')

    def restore(self):
        self.owner.assertFalse(self.owner.down.exists(), 'A worker resumed while the application was closed')
        self.owner.events.append((self.name, 'restore'))
        self.owner.fail_if(self.name, 'restore')


class WorkerControlTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix='worker-control-app-')
        self.application = Path(self.folder.name)
        self.down = self.application / 'storage/framework/down'
        self.down.parent.mkdir(parents=True)
        self.state_folder = Path('/tmp') / ('actual-launch-reset.' + uuid.uuid4().hex[:8])
        self.state_folder.mkdir(mode=0o700)
        self.state = self.state_folder / 'worker-state.json'
        self.events = []
        self.failures = set()
        self.instances = []
        self.progress = []
        self.uid = 1006
        self.control = self.new_control()

    def tearDown(self):
        self.folder.cleanup()
        shutil.rmtree(self.state_folder)

    def new_control(self):
        def timer_factory(**options):
            self.assertEqual(set(options), {'progress'})
            group = RecordingWorkers('timers', self)
            self.instances.append(group)
            return group

        def queue_factory(**options):
            self.assertEqual(options['root'], self.application)
            self.assertEqual(options['uid'], self.uid)
            self.assertEqual(options['php'], workers.PHP)
            self.assertEqual(set(options), {'root', 'uid', 'php', 'progress'})
            group = RecordingWorkers('queue', self)
            self.instances.append(group)
            return group
        return workers.WorkerControl(self.application, self.uid, workers.PHP,
            timer_factory=timer_factory, queue_factory=queue_factory, progress=self.progress.append)

    def fail_if(self, group, phase):
        if (group, phase) in self.failures:
            raise RuntimeError('SIMULATED_WORKER_FAILURE')

    def original_receipt(self):
        return {'version': 1, 'root': str(self.application), 'uid': self.uid,
                'timers': {'kind': 'timers', 'was_active': True},
                'queue': {'kind': 'queue', 'was_active': False}}

    def test_preflight_only_reads_and_validates_both_groups(self):
        with patch.object(workers, 'write_state', side_effect=AssertionError('Preflight wrote a receipt')):
            self.control.check()
        self.assertEqual(self.events, [('timers', 'prepare'), ('queue', 'prepare')])
        self.assertFalse(self.state.exists())
        self.assertFalse(self.down.exists())
        self.assertIn('APPLICATION_WORKER_CONTROL_PREFLIGHT_PASSED', self.progress)

    def test_invalid_preflight_never_stops_workers(self):
        self.failures.add(('queue', 'prepare'))
        with self.assertRaisesRegex(RuntimeError, '^SIMULATED_WORKER_FAILURE$'):
            self.control.check()
        self.assertFalse(any(phase in ('pause', 'restore') for group, phase in self.events))
        self.assertFalse(self.state.exists())

    def test_pause_requires_maintenance_before_validation_or_receipt(self):
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_PAUSE_REQUIRES_MAINTENANCE$'):
            self.control.pause(self.state)
        self.assertEqual(self.events, [])
        self.assertFalse(self.state.exists())

    def test_pause_validates_both_and_persists_complete_receipt_before_any_stop(self):
        self.down.write_text('{}')
        self.control.pause(self.state)
        self.assertEqual(workers.read_state(self.state), self.original_receipt())
        self.assertEqual(stat.S_IMODE(self.state.stat().st_mode), 0o600)
        self.assertEqual(self.events, [('timers', 'prepare'), ('queue', 'prepare'),
            ('timers', 'receipt'), ('queue', 'receipt'), ('timers', 'pause'), ('queue', 'pause')])
        self.assertTrue(self.down.exists())

    def test_receipt_failure_happens_before_first_stop(self):
        self.down.write_text('{}')
        self.state.write_text('existing receipt must survive')
        os.chmod(self.state, 0o600)
        with self.assertRaises(FileExistsError):
            self.control.pause(self.state)
        self.assertEqual(self.state.read_text(), 'existing receipt must survive')
        self.assertFalse(any(phase == 'pause' for group, phase in self.events))

    def test_partial_timer_or_queue_pause_restores_from_fresh_instances_after_reopening(self):
        for failed in ['timers', 'queue']:
            with self.subTest(failed=failed):
                if self.state.exists():
                    self.state.unlink()
                self.events = []
                self.failures = {(failed, 'pause')}
                self.down.write_text('{}')
                with self.assertRaisesRegex(RuntimeError, '^SIMULATED_WORKER_FAILURE$'):
                    self.control.pause(self.state)
                self.assertTrue(self.state.exists())
                if failed == 'timers':
                    self.assertNotIn(('queue', 'pause'), self.events)
                paused_instances = list(self.instances)
                self.events = []
                with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_RESTORE_REQUIRES_REOPENED_APPLICATION$'):
                    self.new_control().restore(self.state)
                self.assertEqual(self.events, [])
                self.down.unlink()
                self.new_control().restore(self.state)
                self.assertEqual(self.events, [('timers', 'load'), ('queue', 'load'), ('queue', 'restore'), ('timers', 'restore')])
                self.assertTrue(all(group not in paused_instances for group in self.instances[-2:]))
                self.assertTrue(self.state.exists(), 'Recovery receipt was removed')

    def test_restore_checks_complete_receipt_before_first_start(self):
        payload = self.original_receipt()
        payload['queue'] = {'invalid': 'queue receipt'}
        workers.write_state(self.state, payload)
        with self.assertRaisesRegex(RuntimeError, '^GROUP_RECEIPT_INVALID$'):
            self.control.restore(self.state)
        self.assertEqual(self.events, [('timers', 'load'), ('queue', 'load')])

    def test_restore_attempts_both_groups_and_keeps_receipt_after_one_fails(self):
        for failed in ['timers', 'queue']:
            with self.subTest(failed=failed):
                if self.state.exists():
                    self.state.unlink()
                self.events = []
                self.failures = {(failed, 'restore')}
                workers.write_state(self.state, self.original_receipt())
                with self.assertRaisesRegex(workers.WorkerControlError, '^APPLICATION_WORKER_RESTORE_FAILED$'):
                    self.new_control().restore(self.state)
                self.assertEqual(self.events[-2:], [('queue', 'restore'), ('timers', 'restore')])
                self.assertTrue(self.state.exists())

    def test_top_level_receipt_schema_and_identity_are_strict(self):
        original = self.original_receipt()
        invalid = []
        for key, value in [('version', 2), ('version', True), ('version', 1.0),
                           ('root', '/another/application'), ('uid', self.uid + 1), ('uid', float(self.uid))]:
            payload = copy.deepcopy(original)
            payload[key] = value
            invalid.append(payload)
        invalid.extend([[], {'version': 1}, dict(original, unexpected=True)])
        for payload in invalid:
            with self.subTest(payload=payload):
                if self.state.exists():
                    self.state.unlink()
                self.events = []
                workers.write_state(self.state, payload)
                with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_RECEIPT_INVALID$'):
                    self.control.restore(self.state)
                self.assertEqual(self.events, [])

    def test_state_path_must_be_inside_reviewed_root_owned_temporary_directory(self):
        for path in [self.application / 'worker-state.json', self.state.with_name('other.json'), Path('worker-state.json'),
                     Path('/tmp/actual-launch-reset.short/worker-state.json')]:
            with self.subTest(path=path), self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_PATH_INVALID$'):
                workers.write_state(path, {})
        os.chmod(self.state.parent, 0o777)
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_PARENT_UNSAFE$'):
            workers.write_state(self.state, {})
        os.chmod(self.state.parent, 0o700)
        original_lstat = Path.lstat
        def foreign_parent(path, *arguments, **options):
            info = original_lstat(path, *arguments, **options)
            if path == self.state.parent:
                values = list(info)
                values[4] = 1006
                return os.stat_result(values)
            return info
        with patch.object(Path, 'lstat', foreign_parent):
            with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_PARENT_UNSAFE$'):
                workers.write_state(self.state, {})

    def test_symlinked_state_parent_cannot_redirect_receipt_write(self):
        with tempfile.TemporaryDirectory(prefix='worker-real-state-') as other:
            link = self.state.parent
            link.rmdir()
            link.symlink_to(other, target_is_directory=True)
            try:
                with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_PARENT_UNSAFE$'):
                    workers.write_state(self.state, {})
                self.assertFalse((Path(other) / 'worker-state.json').exists())
            finally:
                link.unlink()
                link.mkdir(mode=0o700)

    def test_read_rejects_symlinks_hardlinks_fifo_wrong_owner_or_public_mode(self):
        target = self.state.parent / 'target'
        target.write_text('{}')
        os.chmod(target, 0o600)
        self.state.symlink_to(target)
        with self.assertRaises(OSError):
            workers.read_state(self.state)
        self.state.unlink()
        os.link(target, self.state)
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_FILE_UNSAFE$'):
            workers.read_state(self.state)
        self.state.unlink()
        target.unlink()
        os.mkfifo(self.state, 0o600)
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_FILE_UNSAFE$'):
            workers.read_state(self.state)
        self.state.unlink()
        workers.write_state(self.state, {})
        os.chmod(self.state, 0o644)
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_FILE_UNSAFE$'):
            workers.read_state(self.state)
        os.chmod(self.state, 0o600)
        original_fstat = os.fstat
        def foreign_file(descriptor):
            values = list(original_fstat(descriptor))
            values[4] = 1006
            return os.stat_result(values)
        with patch.object(workers.os, 'fstat', foreign_file):
            with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_FILE_UNSAFE$'):
                workers.read_state(self.state)

    def test_receipt_size_limit_applies_before_write_and_before_read(self):
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_TOO_LARGE$'):
            workers.write_state(self.state, {'huge': 'x' * 262144})
        self.assertFalse(self.state.exists())
        self.state.write_bytes(b'x' * 262145)
        os.chmod(self.state, 0o600)
        with self.assertRaisesRegex(workers.WorkerControlError, '^WORKER_STATE_FILE_UNSAFE$'):
            workers.read_state(self.state)

    def test_receipt_syncs_file_and_parent_directory_before_pause_can_begin(self):
        synced = []
        original_fsync = os.fsync
        def record_sync(descriptor):
            synced.append('directory' if stat.S_ISDIR(os.fstat(descriptor).st_mode) else 'file')
            return original_fsync(descriptor)
        with patch.object(workers.os, 'fsync', record_sync):
            workers.write_state(self.state, self.original_receipt())
        self.assertEqual(synced, ['file', 'directory'])

    def test_actual_shell_cleanup_reopens_before_resume_and_retains_uncertain_recovery(self):
        source = (ROOT / 'deployment/apply_actual_launch_reset_root.sh').read_text()
        cleanup = source[source.index('launch_cleanup() {'):source.index('trap launch_cleanup EXIT')]
        scenarios = [
            ('before-reset-failure', 1, 0, False, 0, 0, ['up', 'restore', 'remove'], False, 0),
            ('verified-rollback', 1, 1, True, 0, 0, ['up', 'restore', 'remove'], False, 0),
            ('verified-commit', 1, 1, True, 0, 0, ['up', 'restore', 'remove'], False, 0),
            ('uncertain-data-state', 1, 1, False, 0, 0, [], True, 1),
            ('artisan-up-failed', 1, 0, False, 1, 0, ['up'], True, 1),
            ('restore-failed', 1, 0, False, 0, 1, ['up', 'restore'], True, 1),
            ('before-maintenance', 0, 0, False, 0, 0, ['remove'], False, 0),
        ]
        for name, down, started, safe, fail_up, fail_restore, expected, retained, status in scenarios:
            with self.subTest(name=name), tempfile.TemporaryDirectory(prefix='worker-cleanup-test-') as directory:
                folder = Path(directory)
                backup = folder / 'backup'
                backup.mkdir()
                recovery = folder / 'recovery'
                recovery.mkdir()
                (recovery / 'worker-state.json').write_text('{}')
                if safe:
                    (backup / 'safe-to-reopen').touch()
                events = folder / 'events'
                script = '''
launch_tmp="$TEST_RECOVERY"
launch_backup="$TEST_BACKUP"
launch_worker_state="$launch_tmp/worker-state.json"
launch_root=/home/fasakha/public_html
launch_php=/opt/cpanel/ea-php82/root/usr/bin/php
launch_down="$TEST_DOWN"
launch_reset_started="$TEST_STARTED"
runuser(){ printf 'up\\n' >> "$TEST_EVENTS"; return "$TEST_FAIL_UP"; }
python3(){ printf 'restore\\n' >> "$TEST_EVENTS"; return "$TEST_FAIL_RESTORE"; }
rm(){ [[ "$#" = 3 && "$1" = -rf && "$2" = -- && "$3" = "$launch_tmp" ]] || return 99; printf 'remove\\n' >> "$TEST_EVENTS"; }
''' + cleanup + '\ntrue\nlaunch_cleanup\n'
                environment = dict(os.environ, TEST_RECOVERY=str(recovery), TEST_BACKUP=str(backup), TEST_EVENTS=str(events),
                    TEST_DOWN=str(down), TEST_STARTED=str(started), TEST_FAIL_UP=str(fail_up), TEST_FAIL_RESTORE=str(fail_restore))
                result = subprocess.run(['bash', '-c', script], env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                self.assertEqual(result.returncode, status, result.stderr)
                recorded = events.read_text().splitlines() if events.exists() else []
                self.assertEqual(recorded, expected)
                self.assertEqual('remove' not in recorded, retained)
                self.assertTrue(recovery.exists(), 'Mock cleanup unexpectedly deleted a path')
                if retained:
                    self.assertIn('WORKER_RECOVERY_DIRECTORY=', result.stdout)


if __name__ == '__main__':
    unittest.main()
