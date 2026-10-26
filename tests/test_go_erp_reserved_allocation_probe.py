"""The diagnostic may write only its private disposable clone, never publication state."""
import copy
import errno
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from contextlib import redirect_stdout
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('reserved_allocation_probe', ROOT / 'deployment/diagnose_go_erp_reserved_allocation_root.py')
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


def reviewed():
    release = {'version': '0.4.4-preview.52', 'source_revision': probe.PUBLISHED}
    state = {'format': 1, 'base_revision': probe.SEED, 'counter': 54,
             'last_remote_sha': probe.FAILED, 'version_floor': '0.4.4-preview.52',
             'desired': {'commit': probe.FAILED, 'version': '0.4.4-preview.53',
                 'candidatePolicy': probe.OLD_POLICY, 'inventorySha256': probe.INVENTORY,
                 'pushed': True, 'status': 'retry-build', 'failures': 3, 'retry_at': 1792975671.132929},
             'published': {'commit': probe.PUBLISHED, 'release': release},
             'published_history': [copy.deepcopy(release)], 'observed': {'unchanged': True},
             'dependency_guard': {'composer.lock': 'a' * 64}, 'future': {'retain': ['all', 'fields']}}
    job = {'format': 1, 'kind': 'go-erp-warm-start-repair-rollout', 'core': probe.CORE,
           'seed': probe.SEED, 'runtime': probe.RUNTIME.name, 'phase': 'running',
           'prepare_started': True, 'baseline_candidate': probe.FAILED,
           'baseline_remote': probe.FAILED, 'baseline_counter': 53, 'candidate': None,
           'version': None, 'transient_failures': 0, 'inventory': probe.INVENTORY}
    return state, job


class GuardTests(unittest.TestCase):
    def test_only_exact_consumed_54_and_unlatched_job_are_accepted_without_mutation(self):
        state, job = reviewed(); before = copy.deepcopy((state, job))
        probe.checked_state(state, job)
        self.assertEqual((state, job), before)
        for field, value in [('counter', 53), ('counter', 55), ('counter', True),
                             ('base_revision', 'f' * 40), ('last_remote_sha', 'f' * 40)]:
            changed = copy.deepcopy(state); changed[field] = value
            with self.subTest(field=field, value=value), self.assertRaises(probe.Stop):
                probe.checked_state(changed, job)
        for field, value in [('commit', 'f' * 40), ('candidatePolicy', probe.POLICY),
                             ('pushed', False), ('status', 'waiting-ci'), ('failures', True),
                             ('retry_at', float('nan')), ('retry_at', float('inf'))]:
            changed = copy.deepcopy(state); changed['desired'][field] = value
            with self.subTest(field=field, value=value), self.assertRaises(probe.Stop):
                probe.checked_state(changed, job)
        for field, value in [('candidate', 'f' * 40), ('version', '0.4.4-preview.54'),
                             ('baseline_counter', 54), ('prepare_started', False),
                             ('transient_failures', 1), ('phase', 'ready')]:
            changed = copy.deepcopy(job); changed[field] = value
            with self.subTest(field=field, value=value), self.assertRaises(probe.Stop):
                probe.checked_state(state, changed)

    def test_duplicate_keys_nonfinite_and_nonobject_json_are_rejected(self):
        for raw in [b'{"counter":54,"counter":53}', b'{"counter":NaN}', b'[]']:
            with self.subTest(raw=raw), self.assertRaises(probe.Stop):
                probe.parse(raw)

    def test_private_errors_are_redacted_and_bounded_source_codes_and_errno_survive(self):
        class SourceError(Exception): pass
        secret = 'https://secret.invalid/?password=TOP_SECRET /root/private.json'
        for error in [RuntimeError(secret), SourceError(secret), SourceError('GO_ERP_SOURCE_' + 'A' * 150)]:
            value = probe.bounded_error(error, (SourceError,))
            self.assertEqual(value['error_type'], 'PRIVATE_EXCEPTION')
            self.assertNotIn('TOP_SECRET', json.dumps(value)); self.assertNotIn('private.json', json.dumps(value))
        self.assertEqual(probe.bounded_error(SourceError('GO_ERP_LIVE_SOURCE_GIT_FAILURE'), (SourceError,))['error_code'],
                         'GO_ERP_LIVE_SOURCE_GIT_FAILURE')
        value = probe.bounded_error(OSError(errno.ENOSPC, secret))
        self.assertEqual(value['errno'], errno.ENOSPC); self.assertNotIn('TOP_SECRET', json.dumps(value))

    def test_git_stderr_becomes_only_a_static_category(self):
        cases = [(b'disk quota exceeded TOP_SECRET', 'QUOTA'), (b'no space left on device TOP_SECRET', 'NO_SPACE'),
                 (b'Permission denied /root/TOP_SECRET', 'PERMISSION'), (b'index.lock TOP_SECRET', 'INDEX_LOCK'),
                 (b'dubious ownership TOP_SECRET', 'DUBIOUS_OWNERSHIP'), (b'bad object TOP_SECRET', 'BAD_OBJECT'),
                 (b'unknown option TOP_SECRET', 'UNKNOWN_OPTION'), (b'password=TOP_SECRET', 'OTHER'),
                 ('no space left on device TOP_SECRET', 'OTHER')]
        for value, expected in cases:
            with self.subTest(expected=expected):
                self.assertEqual(probe.git_failure_category(value), expected)


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned lock checks run as root in diagnostic CI.')
class FilesystemTests(unittest.TestCase):
    def test_existing_locks_are_readonly_nonblocking_and_never_created(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'lock'; path.write_bytes(b'held'); path.chmod(0o600)
            calls = []; original = os.open
            def opened(name, flags, *args, **kwargs):
                calls.append(flags); return original(name, flags, *args, **kwargs)
            with patch.object(probe.os, 'open', side_effect=opened):
                with probe.ExistingLocks([path]):
                    self.assertEqual(path.read_bytes(), b'held')
            self.assertEqual(len(calls), 1)
            self.assertEqual(calls[0] & os.O_ACCMODE, os.O_RDONLY)
            self.assertFalse(calls[0] & os.O_CREAT); self.assertFalse(calls[0] & os.O_TRUNC)
            missing = Path(folder) / 'missing'
            with self.assertRaises(FileNotFoundError):
                with probe.ExistingLocks([missing]): pass
            self.assertFalse(missing.exists())
            held = os.open(path, os.O_RDONLY)
            try:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaisesRegex(probe.Stop, 'BUSY'):
                    with probe.ExistingLocks([path]): pass
            finally: os.close(held)

    def test_read_rejects_links_and_wrong_modes_without_changing_bytes(self):
        with tempfile.TemporaryDirectory() as folder:
            file = Path(folder) / 'state'; file.write_bytes(b'{"unchanged":true}'); file.chmod(0o600)
            self.assertEqual(probe.read(file, 1024), b'{"unchanged":true}')
            link = Path(folder) / 'link'; link.symlink_to(file)
            with self.assertRaises(probe.Stop): probe.read(link, 1024)
            file.chmod(0o644)
            with self.assertRaises(probe.Stop): probe.read(file, 1024)
            self.assertEqual(file.read_bytes(), b'{"unchanged":true}')


class HeartbeatTests(unittest.TestCase):
    def test_readonly_dependency_and_source_checks_are_throttled_and_forceable(self):
        dependency = {'lock': 'known'}; observed = {'private': 'preserved'}; calls = []; clock = [0]
        source = types.SimpleNamespace(scan_snapshot=lambda *args: (calls.append(('scan', args)) or
            {'metadata': {'seedRevision': probe.SEED, 'inventorySha256': probe.INVENTORY}, '_files': {}}))
        worker = types.SimpleNamespace(dependency_snapshot=lambda *args: (calls.append(('dependency', args)) or dependency))
        before = copy.deepcopy((dependency, observed))
        guard = probe.Heartbeat(source, worker, 123, observed, dependency, clock=lambda: clock[0])
        guard(); self.assertEqual(len(calls), 2)
        clock[0] = 19; guard(); self.assertEqual(len(calls), 2)
        guard(force=True); self.assertEqual(len(calls), 4)
        clock[0] = 40; guard(); self.assertEqual(len(calls), 6)
        self.assertEqual((dependency, observed), before)
        worker.dependency_snapshot = lambda *args: {'lock': 'changed'}
        with self.assertRaisesRegex(probe.Stop, 'DEPENDENCY_CHANGED'): guard(force=True)
        self.assertEqual(len(calls), 6, 'Dependency drift stops before another source read.')
        worker.dependency_snapshot = lambda *args: dependency
        source.scan_snapshot = lambda *args: {'metadata': {'seedRevision': probe.SEED, 'inventorySha256': 'f' * 64}}
        with self.assertRaisesRegex(probe.Stop, 'SOURCE_CHANGED'): guard(force=True)


class ReplayTests(unittest.TestCase):
    def fake_modules(self, behavior):
        calls = []
        class Git:
            def __init__(self, work, token, heartbeat):
                self.work = work; self.heartbeat = heartbeat
            def run(self, arguments, cwd=None, timeout=180):
                calls.append(tuple(arguments)); return b''
            def candidate(self, checkout, destination, revision):
                self.run(['clone', '--quiet', '--no-local', '--no-hardlinks', str(checkout), str(destination)])
                destination.mkdir()
                self.run(['remote', 'set-url', 'origin', probe.REMOTE], destination)
                self.run(['checkout', '--quiet', '--detach', revision], destination)
        source = types.SimpleNamespace(_git=lambda *args: b'')
        def prepare(destination, snapshot, sequence, heartbeat):
            self.assertEqual(sequence, 54); self.assertEqual(destination.name, 'candidate')
            heartbeat(); source._git(destination, ['hash-object', '--stdin'], b'TOP_SECRET')
            behavior(source, destination)
            return {'version': '0.4.4-preview.54', 'candidatePolicy': probe.POLICY, 'inventorySha256': probe.INVENTORY}
        source.prepare_candidate = prepare
        return source, types.SimpleNamespace(Git=Git), calls

    def test_replay_runs_only_clone_origin_checkout_and_restores_git_on_success_and_failure(self):
        for failure in (False, True):
            def behavior(source, destination):
                if failure: raise RuntimeError('TOP_SECRET')
            source, worker, calls = self.fake_modules(behavior); original = source._git; events = []
            with tempfile.TemporaryDirectory() as folder:
                directory = Path(folder)
                if failure:
                    with self.assertRaisesRegex(RuntimeError, 'TOP_SECRET'):
                        probe.replay(source, worker, {}, lambda: None, directory, events)
                else:
                    self.assertEqual(probe.replay(source, worker, {}, lambda: None, directory, events)['version'], '0.4.4-preview.54')
            self.assertIs(source._git, original)
            self.assertEqual([row[0] for row in calls], ['clone', 'remote', 'checkout'])
            self.assertTrue(all(set(row) == {'operation', 'milliseconds'} for row in events))
            self.assertNotIn('TOP_SECRET', json.dumps(events))

    def test_unreviewed_git_operation_is_refused_without_exposing_arguments(self):
        source, worker, _ = self.fake_modules(lambda source, destination: source._git(destination, ['fetch', 'TOP_SECRET']))
        original = source._git; events = []
        with tempfile.TemporaryDirectory() as folder, self.assertRaisesRegex(probe.Stop, 'SOURCE_GIT_OPERATION_UNREVIEWED'):
            probe.replay(source, worker, {}, lambda: None, Path(folder), events)
        self.assertIs(source._git, original); self.assertNotIn('TOP_SECRET', json.dumps(events))

    def test_source_subprocess_failure_and_timeout_are_categorized_without_stderr_or_arguments(self):
        for timeout in (False, True):
            source, worker, _ = self.fake_modules(lambda source, destination: None)
            def process(*args, **kwargs):
                if timeout:
                    raise probe.subprocess.TimeoutExpired(['git', 'TOP_SECRET'], 120, stderr=b'TOP_SECRET')
                return types.SimpleNamespace(returncode=128, stdout=b'', stderr=b'No space left on device /root/TOP_SECRET')
            process_module = types.SimpleNamespace(run=process)
            def actual_git(*args, **kwargs):
                result = process_module.run(['git', 'TOP_SECRET'])
                if result.returncode: raise RuntimeError('TOP_SECRET')
                return result.stdout
            source._git = actual_git; source.subprocess = process_module; events = []
            with tempfile.TemporaryDirectory() as folder:
                with self.assertRaises((RuntimeError, probe.subprocess.TimeoutExpired)):
                    probe.replay(source, worker, {}, lambda: None, Path(folder), events)
            self.assertIs(source._git, actual_git); self.assertIs(process_module.run, process)
            self.assertEqual(events[-1]['failure_category'], 'TIMEOUT' if timeout else 'NO_SPACE')
            if not timeout: self.assertEqual(events[-1]['exit_status'], 128)
            self.assertNotIn('TOP_SECRET', json.dumps(events))


class MainTests(unittest.TestCase):
    def exercise(self, *, failure=False, tamper=False):
        state, job = reviewed(); raw_state = json.dumps(state).encode()
        unit = b'synthetic reviewed unit'; held = [False]; events = []; folders = []; scans = []
        state_path = probe.WORK / 'state/current.json'
        paths = {
            state_path: raw_state,
            probe.CONTROL / ('private/warm-start-repair-' + probe.CORE + '.json'): json.dumps(job).encode(),
            Path('/etc/go-erp/live-updates.json'): json.dumps({'base_revision': probe.SEED, 'token': 'TOP_SECRET'}).encode(),
            Path('/etc/systemd/system/go-erp-live-updates.service'): unit,
            probe.WORK / 'program-rollout-hold.json': b'{"authorization":null}',
            probe.ROOT / 'storage/app/desktop-updates/current.json': None,
        }
        before = copy.deepcopy(paths)
        class SourceError(Exception): pass
        class WatchError(Exception): pass
        source = types.SimpleNamespace(PREVIEW31_SEED=probe.SEED, PREVIEW31_POLICY=probe.POLICY, SourceError=SourceError)
        def scan(*args):
            scans.append(args)
            return {'metadata': {'seedRevision': probe.SEED, 'inventorySha256': probe.INVENTORY}, '_files': {}}
        source.scan_snapshot = scan
        worker = types.SimpleNamespace(PREVIEW31_SEED=probe.SEED, PREVIEW31_POLICY=probe.POLICY,
            WatchError=WatchError, dependency_snapshot=lambda *args: state['dependency_guard'])
        test_case = self
        class Locks:
            def __init__(self, names):
                test_case.assertTrue({probe.CONTROL / 'private/worker.lock', probe.WORK / 'watcher.lock'} <= set(names))
                self.names = names
            def __enter__(self):
                held[0] = True; events.append('locked'); return self
            def __exit__(self, *args):
                events.append('unlocked'); held[0] = False
        def read(path, maximum, modes=(0o600,), optional=False):
            self.assertTrue(held[0], 'Every protected read, including final verification, must precede unlock.')
            events.append('read')
            return paths[path]
        def replay(source_arg, worker_arg, snapshot, heartbeat, directory, operations, progress=lambda stage: None):
            self.assertTrue(held[0]); self.assertEqual(snapshot['metadata']['inventorySha256'], probe.INVENTORY)
            folders.append(directory)
            (directory / 'private-probe-marker').write_text('temporary clone only')
            if tamper: paths[state_path] = b'{"changed":true}'
            if failure: raise SourceError('GO_ERP_LIVE_SOURCE_GIT_FAILURE')
            return {'version': '0.4.4-preview.54', 'candidatePolicy': probe.POLICY, 'inventorySha256': probe.INVENTORY}
        actual_temporary = tempfile.TemporaryDirectory
        with actual_temporary() as parent:
            def private_temporary(*, prefix, dir):
                self.assertEqual(dir, '/var/tmp'); self.assertEqual(prefix, 'go-erp-reserved-allocation-probe-')
                return actual_temporary(prefix=prefix, dir=parent)
            output = io.StringIO()
            with patch.object(probe, 'ExistingLocks', Locks), patch.object(probe, 'read', side_effect=read), \
                 patch.object(probe, 'load_pinned', side_effect=lambda name: source if name == 'go_erp_live_source.py' else worker), \
                 patch.object(probe, 'replay', side_effect=replay), patch.object(probe, 'UNIT_SHA', probe.digest(unit)), \
                 patch.object(probe.os, 'geteuid', return_value=0), \
                 patch.object(probe.socket, 'gethostname', return_value='srv1836524'), \
                 patch.object(probe.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=123)), \
                 patch.object(probe.shutil, 'disk_usage', return_value=types.SimpleNamespace(free=1024**3)), \
                 patch.object(probe.sys, 'argv', ['probe']), \
                 patch.object(probe.tempfile, 'TemporaryDirectory', side_effect=private_temporary), redirect_stdout(output):
                code = probe.main()
            raw = output.getvalue()
            results = [line.removeprefix('GO_ERP_ALLOCATION_DIAG_RESULT ') for line in raw.splitlines()
                       if line.startswith('GO_ERP_ALLOCATION_DIAG_RESULT ')]
            self.assertEqual(len(results), 1)
            report = json.loads(results[0])
            self.assertNotIn('TOP_SECRET', raw); self.assertNotIn(str(parent), raw)
            self.assertEqual(len(folders), 1, report); self.assertFalse(folders[0].exists())
            self.assertTrue(report['temporary_removed']); self.assertTrue(report['source_and_dependency_verified_after'])
            self.assertGreaterEqual(len(scans), 2)
            self.assertFalse(held[0]); self.assertEqual(events[-1], 'unlocked')
            self.assertEqual(report['published'], False); self.assertEqual(report['database_read'], False)
            self.assertEqual(report['production_written'], False)
            self.assertEqual(paths, before if not tamper else {**before, state_path: b'{"changed":true}'})
            return code, report

    def test_main_success_cleans_temporary_files_and_verifies_controls_before_unlock(self):
        code, report = self.exercise()
        self.assertEqual(code, 0); self.assertTrue(report['ok']); self.assertTrue(report['protected_files_unchanged'])

    def test_main_source_failure_still_rechecks_source_cleans_temp_and_preserves_controls(self):
        code, report = self.exercise(failure=True)
        self.assertEqual(code, 1); self.assertFalse(report['ok']); self.assertTrue(report['protected_files_unchanged'])
        self.assertEqual(report['error_code'], 'GO_ERP_LIVE_SOURCE_GIT_FAILURE')

    def test_main_protected_state_change_cannot_be_reported_as_success(self):
        code, report = self.exercise(tamper=True)
        self.assertEqual(code, 1); self.assertFalse(report['ok']); self.assertFalse(report['protected_files_unchanged'])
        self.assertEqual(report['error_code'], 'GO_ERP_ALLOCATION_PROBE_PROTECTED_FILE_CHANGED')


if __name__ == '__main__': unittest.main()
