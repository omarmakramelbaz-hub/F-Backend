"""Exact Preview 42 rearm uses real CI validators and atomic private files."""
import contextlib
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = module('preview42_rearm', ROOT / 'deployment/rearm_go_erp_preview42_root.py')
completion = module('preview42_original_completion', ROOT / 'deployment/complete_go_erp_windows_fixtures_root.py')
runtime = module('preview42_ci_boundary', ROOT / 'deployment/go_erp_live_updates.py')
utility = types.ModuleType('preview42_original_utility')
raw = subprocess.check_output(['git', 'show',
    '2d9e23a5157782882382c797a44ad15c317ae845:deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py'], cwd=ROOT)
exec(compile(raw, 'pinned_original_utility.py', 'exec'), utility.__dict__)


class Fixture:
    def __init__(self, path):
        self.path = path
        path.chmod(0o700)
        (path / 'state').mkdir(mode=0o700)
        self.state_path = path / 'state/current.json'
        self.expected = {'core': completion.CORE, 'source': copy.deepcopy(completion.SOURCE),
            'inventory_sha256': subject.INVENTORY}
        self.state = {'format': 1, 'base_revision': subject.SEED, 'counter': 42,
            'last_remote_sha': subject.CANDIDATE, 'published': None,
            'observed': {'seedRevision': subject.SEED, 'opaque': ['retained', 7]},
            'sequence_floor': 39967960771, 'version_floor': '0.4.4-preview.41',
            'published_history': [], 'other': {'retain': True},
            'desired': {'commit': subject.CANDIDATE, 'version': subject.VERSION, 'pushed': True,
                'inventorySha256': subject.INVENTORY, 'candidatePolicy': subject.POLICY,
                'status': 'retry-build', 'failures': 2, 'retry_at': 9007199254000.25,
                'other': ['preserve', {'exact': 1}]}}
        self.write_state(self.state)
        self.hold = {'format': 1, 'kind': 'go-erp-program-rollout-hold',
            **self.expected, 'authorization': None}
        self.write('program-rollout-hold.json', self.hold)
        self.events = []
        self.public = None
        self.config = {'base_revision': subject.SEED, 'token': 'private-test-token-never-output'}
        self.changed_controls = False
        self.source_error = False
        self.remote_revision = subject.CANDIDATE
        self.api_error = None
        self.artifact_delta = {}
        self.run_delta = {}
        self.job_delta = {}
        self.after_api = None
        self.locked = False
        self.base = types.SimpleNamespace(MAX_STATE=16 * 1024**2)
        self.utility = utility
        self.original = types.SimpleNamespace(slots=lambda: (*self.controls()[:1], self.config, self.controls()[1]))
        self.gate = types.SimpleNamespace(program_gate_bytes=lambda path: path.read_bytes(),
            program_gate_parse=json.loads, program_gate_json=lambda path: json.loads(path.read_bytes()),
            program_gate_completed=lambda *args: False)
        self.worker = types.SimpleNamespace(WORKFLOWS=runtime.WORKFLOWS, canonical=runtime.canonical,
            accepted_run=runtime.accepted_run, accepted_artifact=runtime.accepted_artifact,
            GitHub=self.client, Worker=lambda *args: self.fail('Worker constructor forbidden'))
        @contextlib.contextmanager
        def held_lock(unused, work):
            with open(work / 'watcher.lock', 'a+b') as stream:
                os.chmod(stream.name, 0o600)
                fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.locked = True
                self.events.append('lock')
                try:
                    yield stream.fileno(), lambda: self.events.append('verify-lock')
                finally:
                    self.locked = False
        self.prior = types.SimpleNamespace(held_lock=held_lock,
            assert_quiescent=lambda: self.events.append('quiescent'),
            require_no_pending=lambda *args: self.events.append('prior-pending-checked'))

    def fail(self, message):
        raise AssertionError(message)

    def write(self, name, value):
        path = self.path / name
        path.write_text(json.dumps(value, sort_keys=True))
        path.chmod(0o600)

    def write_state(self, state):
        # An intentionally noncanonical preimage must be backed up verbatim.
        self.state_path.write_text(json.dumps(state, indent=2) + '\n')
        self.state_path.chmod(0o600)

    def controls(self):
        raw = self.state_path.read_bytes()
        controls = {'state': raw, 'config': b'private pinned config', 'unit': b'pinned native unit'}
        if self.changed_controls:
            controls['unit'] += b' changed'
        return controls, json.loads(raw)

    def fresh(self, state):
        self.events.append('fresh')
        subject.need(not self.source_error, 'LIVE_SOURCE_CHANGED')

    def remote(self, revision):
        subject.need(self.remote_revision == revision, 'REMOTE_LEASE_CHANGED')

    def candidate(self, desired):
        self.events.append('original-candidate-proof')
        self.remote(desired['commit'])

    def publication(self):
        return self.public

    def client(self, token):
        assert self.locked and token == self.config['token']
        return types.SimpleNamespace(pages=self.pages)

    def pages(self, path, field):
        assert self.locked
        self.events.append(field)
        if self.api_error:
            raise self.api_error
        for workflow, (run_id, attempt) in subject.RUNS.items():
            if field == 'workflow_runs' and '/workflows/' + workflow + '/' in path:
                row = {'id': run_id, 'run_attempt': attempt, 'head_sha': subject.CANDIDATE,
                    'head_branch': runtime.BRANCH, 'event': 'push', 'pull_requests': [],
                    'path': '.github/workflows/' + workflow, 'status': 'completed', 'conclusion': 'success',
                    'repository': {'id': runtime.REPOSITORY_ID, 'full_name': runtime.REPOSITORY},
                    'head_repository': {'id': runtime.REPOSITORY_ID, 'full_name': runtime.REPOSITORY}}
                row.update(self.run_delta.get(workflow, {}))
                return [row]
            actual_id = self.run_delta.get(workflow, {}).get('id', run_id)
            actual_attempt = self.run_delta.get(workflow, {}).get('run_attempt', attempt)
            if field == 'jobs' and '/runs/' + str(actual_id) + '/' in path:
                rows = [{'name': name, 'run_id': actual_id, 'run_attempt': actual_attempt,
                    'head_sha': subject.CANDIDATE, 'status': 'completed', 'conclusion': 'success'}
                    for name in runtime.WORKFLOWS[workflow]]
                if self.job_delta:
                    rows[0].update(self.job_delta)
                return rows
        if field == 'artifacts':
            if self.after_api:
                self.after_api()
            return [{'name': runtime.ARTIFACT, **subject.ARTIFACT, 'expired': False,
                'workflow_run': {'id': subject.RUNS['desktop-dashboard-tests.yml'][0],
                    'repository_id': runtime.REPOSITORY_ID, 'head_repository_id': runtime.REPOSITORY_ID,
                    'head_sha': subject.CANDIDATE, 'head_branch': runtime.BRANCH}, **self.artifact_delta}]
        self.fail('Unexpected API path')


class RearmTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.f = Fixture(Path(temporary.name))
        changed = patch.object(completion, 'WORK', self.f.path)
        changed.start()
        self.addCleanup(changed.stop)

    def run_rearm(self):
        return subject.rearm(completion, self.f)

    def assert_untouched(self, before):
        self.assertEqual(self.f.state_path.read_bytes(), before)
        self.assertEqual(json.loads((self.f.path / 'program-rollout-hold.json').read_bytes()), self.f.hold)

    def test_exact_genuine_latest_attempts_change_only_status_and_keep_private_preimage(self):
        before = self.f.state_path.read_bytes()
        wanted = copy.deepcopy(self.f.state)
        wanted['desired']['status'] = 'waiting-ci'
        self.assertTrue(self.run_rearm())
        self.assertEqual(json.loads(self.f.state_path.read_bytes()), wanted)
        self.assertEqual(self.f.events.count('workflow_runs'), 3)
        self.assertEqual(self.f.events.count('jobs'), 3)
        self.assertEqual(self.f.events.count('artifacts'), 1)
        audit, = self.f.path.glob('preview42-rearm-*')
        self.assertEqual((audit / 'before-state.json').read_bytes(), before)
        self.assertEqual((audit / 'exchange-state.json').read_bytes(), before)
        self.assertTrue((audit / 'completed.json').is_file())
        self.assertEqual(audit.stat().st_mode & 0o777, 0o700)
        for path in audit.iterdir():
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_blocked_can_rearm_without_resetting_failure_or_future_backoff(self):
        self.f.state['desired'].update(status='blocked', failures=100, retry_at=completion.SAFE)
        self.f.write_state(self.f.state)
        self.assertTrue(self.run_rearm())
        desired = json.loads(self.f.state_path.read_bytes())['desired']
        self.assertEqual((desired['failures'], desired['retry_at']), (100, completion.SAFE))

    def test_failed_pending_or_foreign_ci_attempt_cannot_change_state(self):
        for delta in ({'conclusion': 'failure'}, {'status': 'in_progress'}, {'run_attempt': 1},
                {'id': 40054814913}, {'head_sha': 'f' * 40}):
            with self.subTest(delta=delta):
                before = self.f.state_path.read_bytes()
                self.f.run_delta = {'go-erp-update-tests.yml': delta}
                with self.assertRaises((subject.Stop, runtime.WatchError)):
                    self.run_rearm()
                self.assert_untouched(before)

    def test_original_job_and_artifact_validators_still_refuse_invalid_proofs(self):
        for kind, delta in [('job', {'conclusion': 'failure'}), ('job', {'head_sha': 'f' * 40}),
                ('artifact', {'expired': True}), ('artifact', {'id': 1}),
                ('artifact', {'digest': 'sha256:' + 'a' * 64})]:
            self.f.job_delta = delta if kind == 'job' else {}
            self.f.artifact_delta = delta if kind == 'artifact' else {}
            before = self.f.state_path.read_bytes()
            with self.subTest(kind=kind, delta=delta), self.assertRaises((subject.Stop, runtime.WatchError)):
                self.run_rearm()
            self.assert_untouched(before)

    def test_candidate_counter_policy_and_retry_metadata_are_exact(self):
        cases = [('counter', 43), ('base_revision', 'f' * 40), ('last_remote_sha', 'f' * 40),
            ('desired.commit', 'f' * 40), ('desired.version', '0.4.4-preview.43'),
            ('desired.candidatePolicy', 'foreign'), ('desired.inventorySha256', 'f' * 64),
            ('desired.pushed', False), ('desired.failures', True), ('desired.retry_at', float('inf')),
            ('desired.status', 'prepared')]
        original = copy.deepcopy(self.f.state)
        for field, value in cases:
            self.f.state = copy.deepcopy(original)
            parent, key = (self.f.state['desired'], field.split('.')[1]) if '.' in field else (self.f.state, field)
            parent[key] = value
            self.f.write_state(self.f.state)
            before = self.f.state_path.read_bytes()
            with self.subTest(field=field), self.assertRaises((subject.Stop, completion.Stop)):
                self.run_rearm()
            self.assert_untouched(before)

    def test_existing_authorization_acceptance_or_publication_is_never_rearmed(self):
        before = self.f.state_path.read_bytes()
        self.f.hold['authorization'] = {'candidate': subject.CANDIDATE}
        self.f.write('program-rollout-hold.json', self.f.hold)
        with self.assertRaisesRegex(subject.Stop, '^HOLD_DIFFERS_OR_AUTHORIZED$'):
            self.run_rearm()
        self.assert_untouched(before)
        self.f.hold['authorization'] = None
        self.f.write('program-rollout-hold.json', self.f.hold)
        self.f.write('pending-program-acceptance.json', {})
        with self.assertRaisesRegex(subject.Stop, '^ACCEPTANCE_ALREADY_PRESENT$'):
            self.run_rearm()
        (self.f.path / 'pending-program-acceptance.json').unlink()
        self.f.public = {'version': subject.VERSION}
        with self.assertRaisesRegex(subject.Stop, '^PUBLICATION_ALREADY_PRESENT$'):
            self.run_rearm()
        self.assert_untouched(before)

    def test_remote_source_and_control_races_refuse_before_write(self):
        for change in (lambda: setattr(self.f, 'remote_revision', 'f' * 40),
                lambda: setattr(self.f, 'source_error', True),
                lambda: setattr(self.f, 'changed_controls', True)):
            self.f.remote_revision = subject.CANDIDATE
            self.f.source_error = self.f.changed_controls = False
            self.f.after_api = change
            before = self.f.state_path.read_bytes()
            with self.assertRaises(subject.Stop):
                self.run_rearm()
            self.assert_untouched(before)

    def test_write_failure_after_exchange_rolls_back_exact_original_state(self):
        before = self.f.state_path.read_bytes()
        original = utility.json_at
        def fail_completed(directory, name, value):
            if name == 'completed.json':
                raise OSError('injected durable marker failure')
            return original(directory, name, value)
        with patch.object(utility, 'json_at', side_effect=fail_completed):
            with self.assertRaises(OSError):
                self.run_rearm()
        self.assert_untouched(before)
        audit, = self.f.path.glob('preview42-rearm-*')
        self.assertTrue((audit / 'rolled-back.json').is_file())

    def test_waiting_and_published_reruns_never_rearm_or_call_ci(self):
        for status in ('waiting-ci', 'published'):
            self.f.state['desired']['status'] = status
            self.f.write_state(self.f.state)
            before = self.f.state_path.read_bytes()
            with self.subTest(status=status):
                self.assertFalse(self.run_rearm())
                self.assert_untouched(before)
        self.assertNotIn('workflow_runs', self.f.events)

    def test_completion_pin_and_execute_idempotent_delegation(self):
        path = ROOT / 'deployment' / subject.BASE_NAME
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), subject.BASE_SHA256)
        with tempfile.TemporaryDirectory() as package_name:
            package = Path(package_name)
            package.chmod(0o700)
            (package / subject.BASE_NAME).write_bytes(path.read_bytes())
            (package / subject.BASE_NAME).chmod(0o644)
            actual = subject.load_completion(package)
            self.assertEqual(actual.SEED, subject.SEED)
            (package / subject.BASE_NAME).write_bytes(path.read_bytes() + b'\n')
            with self.assertRaisesRegex(subject.Stop, '^BASE_MODULE_PIN_INVALID$'):
                subject.load_completion(package)
        base = types.SimpleNamespace(Context=lambda package: self.f,
            desired_state=completion.desired_state, route=lambda ctx, mode: {
                'ok': True, 'worker_request': 'collect', 'published': False, 'publication_held': True})
        with patch.object(subject, 'load_completion', return_value=base), \
                patch.object(subject, 'rearm', return_value=False), \
                patch.object(subject.socket, 'gethostname', return_value='srv1836524'):
            result = subject.execute(self.f.path, True)
        self.assertFalse(result['rearmed'])
        self.assertEqual(result['worker_request'], 'collect')


if __name__ == '__main__':
    unittest.main()
