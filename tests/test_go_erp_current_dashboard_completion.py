"""Guard completion routing around the unchanged installed CI/rollout gate."""
import contextlib
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('current_dashboard_completion',
    ROOT / 'deployment/complete_go_erp_current_dashboard_root.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)
CANDIDATE = 'b' * 40


class GateBoundary:
    """The original gate is a trusted boundary, never replaced in production."""
    def __init__(self, fixture):
        self.f = fixture
        self.authorizations = []
        self.complete = False

    def program_gate_json(self, path):
        return json.loads(Path(path).read_bytes())

    def program_gate_completed(self, work, expected, state, publication):
        self.f.events.append('original-completion-gate')
        return self.complete and publication() == self.f.release

    def program_gate_allows(self, work, desired, release):
        hold = self.program_gate_json(work / 'program-rollout-hold.json')
        return hold.get('authorization') == {'candidate': desired['commit'], 'release': release}

    def program_gate_authorize(self, work, receipt, expected, state, verify_ci, publication):
        value = self.program_gate_json(receipt)
        self.authorizations.append((work, receipt, copy.deepcopy(expected), copy.deepcopy(state)))
        # Only the trusted gate invokes the original installed proof verifier.
        verify_ci(Path(value['ci']['directory']),
            {'id': value['ci']['run_id'], 'run_attempt': value['ci']['run_attempt']}, state['desired'])
        hold = self.program_gate_json(work / 'program-rollout-hold.json')
        hold['authorization'] = {'candidate': state['desired']['commit'], 'release': self.f.release}
        self.f.write('program-rollout-hold.json', hold)


class Fixture:
    def __init__(self, path):
        self.path = path
        self.path.chmod(0o700)
        self.expected = {'core': subject.CORE, 'source': copy.deepcopy(subject.SOURCE),
            'inventory_sha256': subject.INVENTORY}
        self.events = []
        self.state = {'format': 1, 'base_revision': subject.SEED, 'counter': 40,
            'last_remote_sha': CANDIDATE, 'published': None,
            'desired': {'commit': CANDIDATE, 'version': '0.4.4-preview.40', 'pushed': True,
                'inventorySha256': subject.INVENTORY, 'status': 'waiting-ci', 'failures': 0, 'retry_at': 0}}
        self.write('state.json', self.state)
        self.hold = {'format': 1, 'kind': 'go-erp-program-rollout-hold',
            **self.expected, 'authorization': None}
        self.write('program-rollout-hold.json', self.hold)
        self.release = {'version': '0.4.4-preview.40', 'source_revision': CANDIDATE,
            'source': copy.deepcopy(subject.SOURCE), 'installer': {'sha256': 'c' * 64}}
        self.publisher = object()
        self.public = None
        self.ci_calls = []
        self.ci_error = None
        self.source_error = False
        self.changed_controls = False
        self.remote_revision = CANDIDATE
        self.gate = GateBoundary(self)
        self.worker = types.SimpleNamespace(verify_proof=self.proof,
            Worker=lambda *args, **kwargs: (_ for _ in ()).throw(AssertionError('Do not construct Worker')))
        @contextlib.contextmanager
        def held_lock(utility, work):
            with open(work / 'watcher.lock', 'w+b') as stream:
                os.chmod(stream.name, 0o600)
                fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.events.append('lock')
                yield stream.fileno(), lambda: self.events.append('verify-lock')
                self.events.append('unlock')
        self.prior = types.SimpleNamespace(held_lock=held_lock,
            assert_quiescent=lambda: self.events.append('quiescent'),
            require_no_pending=lambda *args: self.events.append('pending-transactions-checked'))
        self.utility, self.base = object(), object()

    def write(self, name, value):
        path = self.path / name
        path.write_text(json.dumps(value, sort_keys=True))
        path.chmod(0o600)

    def controls(self):
        raw = (self.path / 'state.json').read_bytes()
        state = json.loads(raw)
        if self.changed_controls and self.ci_calls:
            return {'state': raw + b'changed'}, state
        return {'state': raw, 'config': b'exact reviewed config', 'unit': b'exact reviewed unit'}, state

    def fresh(self, state):
        self.events.append('fresh-source')
        subject.need(not self.source_error, 'LIVE_SOURCE_CHANGED')

    def remote(self, wanted):
        subject.need(self.remote_revision == wanted, 'REMOTE_LEASE_CHANGED')

    def candidate(self, desired):
        self.events.append('original-candidate-proof')
        self.remote(desired['commit'])

    def publication(self):
        return self.public

    def proof(self, directory, run, desired, publisher):
        self.ci_calls.append((directory, copy.deepcopy(run), copy.deepcopy(desired), publisher))
        if self.ci_error:
            raise self.ci_error
        return {}, self.release

    def receipt(self):
        return {'format': 1, 'kind': 'go-erp-program-acceptance', **copy.deepcopy(self.expected),
            'candidate_revision': CANDIDATE, 'version': '0.4.4-preview.40',
            'installer': copy.deepcopy(self.release['installer']),
            'ci': {'directory': str(self.path / 'genuine-ci'), 'run_id': 40046091951, 'run_attempt': 1},
            'employee_evidence': {'path': str(self.path / 'employee.json'), 'sha256': 'd' * 64}}


class CompletionTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.f = Fixture(Path(temporary.name))
        patched = patch.object(subject, 'WORK', self.f.path)
        patched.start()
        self.addCleanup(patched.stop)

    def route(self, mode='status', receipt=None):
        return subject.route(self.f, mode, receipt)

    def pending(self, value=None):
        self.f.write('pending-program-acceptance.json', value or self.f.receipt())
        return self.f.path / 'pending-program-acceptance.json'

    def test_queued_candidate_status_collects_without_changing_control_or_hold(self):
        before = self.f.controls()
        result = self.route()
        self.assertEqual(result['worker_request'], 'collect')
        self.assertFalse(result['published'])
        self.assertEqual(self.f.controls(), before)
        self.assertEqual(self.f.gate.program_gate_json(self.f.path / 'program-rollout-hold.json'), self.f.hold)
        self.assertEqual(self.f.ci_calls, [])
        self.assertIn('original-candidate-proof', self.f.events)
        self.assertEqual(self.f.events.count('fresh-source'), 2)

    def test_initial_allocation_requires_original_remote_lease_and_no_publication(self):
        self.f.state.update(counter=39, desired=None, last_remote_sha=subject.OLD_CANDIDATE)
        self.f.write('state.json', self.f.state)
        self.f.remote_revision = subject.OLD_CANDIDATE
        self.assertEqual(self.route()['worker_request'], 'candidate')
        self.f.public = self.f.release
        with self.assertRaisesRegex(subject.Stop, '^UNEXPECTED_PUBLICATION$'):
            self.route()

    def test_actual_pending_envelope_routes_authorize_without_authorizing_during_status(self):
        self.pending()
        self.assertEqual(self.route()['worker_request'], 'authorize')
        self.assertEqual(self.f.gate.authorizations, [])

    def test_authorize_delegates_original_proof_then_only_requests_worker_publication(self):
        receipt = self.pending()
        before = self.f.controls()
        result = self.route('authorize', receipt)
        self.assertEqual(result['worker_request'], 'publish')
        self.assertFalse(result['published'])
        self.assertEqual(self.f.controls(), before)
        directory, run, desired, publisher = self.f.ci_calls[0]
        self.assertEqual(directory, self.f.path / 'genuine-ci')
        self.assertEqual(run, {'id': 40046091951, 'run_attempt': 1})
        self.assertEqual(desired, self.f.state['desired'])
        self.assertIs(publisher, self.f.publisher)
        self.assertIsNone(self.f.public)
        self.assertEqual(self.route()['worker_request'], 'publish')

    def test_failed_original_ci_proof_cannot_become_authorization(self):
        receipt = self.pending()
        self.f.ci_error = ValueError('rejected actual receipt')
        with self.assertRaises(ValueError):
            self.route('authorize', receipt)
        self.assertIsNone(self.f.gate.program_gate_json(self.f.path / 'program-rollout-hold.json')['authorization'])

    def test_foreign_receipt_binding_never_reaches_original_authorization(self):
        for field, replacement in [('candidate_revision', 'e' * 40), ('version', '0.4.4-preview.38'),
                ('core', 'e' * 40), ('inventory_sha256', 'e' * 64), ('source', {})]:
            receipt = self.f.receipt()
            receipt[field] = replacement
            with self.subTest(field=field):
                path = self.pending(receipt)
                with self.assertRaisesRegex(subject.Stop, '^PENDING_ACCEPTANCE_MISMATCH$'):
                    self.route('authorize', path)
        self.assertEqual(self.f.gate.authorizations, [])

    def test_source_or_remote_change_refuses_before_authorization(self):
        receipt = self.pending()
        self.f.source_error = True
        with self.assertRaisesRegex(subject.Stop, '^LIVE_SOURCE_CHANGED$'):
            self.route('authorize', receipt)
        self.f.source_error = False
        self.f.remote_revision = 'e' * 40
        with self.assertRaisesRegex(subject.Stop, '^REMOTE_LEASE_CHANGED$'):
            self.route('authorize', receipt)
        self.assertEqual(self.f.gate.authorizations, [])

    def test_verification_requires_real_completion_and_a_retired_hold(self):
        with self.assertRaisesRegex(subject.Stop, '^PUBLICATION_NOT_COMPLETE$'):
            self.route('verify')
        self.f.gate.complete = True
        self.f.public = self.f.release
        with self.assertRaisesRegex(subject.Stop, '^HOLD_NOT_RETIRED$'):
            self.route('verify')
        hold = self.f.path / 'program-rollout-hold.json'
        hold.unlink()
        hold.symlink_to(self.f.path / 'missing')
        with self.assertRaisesRegex(subject.Stop, '^HOLD_NOT_RETIRED$'):
            self.route('verify')
        hold.unlink()
        result = self.route('verify')
        self.assertTrue(result['published'])
        self.assertFalse(result['publication_held'])
        self.assertEqual(result['worker_request'], 'none')

    def test_post_authorization_race_is_reported_without_erasing_durable_authorization(self):
        receipt = self.pending()
        self.f.changed_controls = True
        with self.assertRaisesRegex(subject.Stop, '^CONTROL_STATE_CHANGED$'):
            self.route('authorize', receipt)
        self.assertIsNotNone(self.f.gate.program_gate_json(self.f.path / 'program-rollout-hold.json')['authorization'])

    def test_failed_or_foreign_desired_state_and_wrong_modes_are_rejected(self):
        original = copy.deepcopy(self.f.state)
        for key, value in [('inventorySha256', 'e' * 64), ('version', '0.4.4-preview.38'),
                ('pushed', False), ('status', 'blocked'), ('failures', 1), ('retry_at', 10)]:
            self.f.state = copy.deepcopy(original)
            self.f.state['desired'][key] = value
            self.f.write('state.json', self.f.state)
            with self.subTest(field=key), self.assertRaises(subject.Stop):
                self.route()
        self.f.write('state.json', original)
        with self.assertRaisesRegex(subject.Stop, '^RECEIPT_MODE_INVALID$'):
            self.route('authorize')
        with self.assertRaisesRegex(subject.Stop, '^RECEIPT_PATH_INVALID$'):
            self.route('authorize', self.f.path / 'foreign.json')

    def test_completion_module_is_bound_to_the_applied_recovery_helper(self):
        self.assertEqual(hashlib.sha256((ROOT / 'deployment' / subject.RECOVERY_NAME).read_bytes()).hexdigest(),
            subject.RECOVERY_SHA256)

    def test_changed_recovery_module_is_rejected_before_execution(self):
        path = self.f.path / subject.RECOVERY_NAME
        path.write_bytes(b'raise AssertionError("must never execute")\n')
        path.chmod(0o644)
        with self.assertRaisesRegex(subject.Stop, '^RECOVERY_MODULE_PIN_INVALID$'):
            subject.load_recovery(self.f.path)

    def test_foreign_configuration_seed_is_rejected_by_real_context_control_guard(self):
        ctx = subject.Context.__new__(subject.Context)
        ctx.original = types.SimpleNamespace(slots=lambda: ({}, {'base_revision': 'e' * 40},
            {'base_revision': subject.SEED}))
        with self.assertRaisesRegex(subject.Stop, '^CONFIGURATION_SEED_INVALID$'):
            ctx.controls()

    def test_real_candidate_guard_rejects_foreign_parent_and_native_deltas_before_worktree(self):
        for parent, changed, reason in [('e' * 40, '', 'CANDIDATE_PARENT_INVALID'),
                (subject.SEED, 'desktop-pos/src/main.cjs\0', 'CANDIDATE_NATIVE_OR_TEST_DELTA')]:
            ctx = subject.Context.__new__(subject.Context)
            commands = []
            candidate = {'inventory': {'app/approved.php': {}}, 'inventorySha256': subject.INVENTORY}
            def git(root, *args, **kwargs):
                commands.append(args)
                if args[:1] == ('ls-files',):
                    return b'100644 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa 0\tapproved\0'
                if args[:2] == ('cat-file', 'commit'):
                    return ('tree ' + 'a' * 40 + '\nparent ' + parent + '\n\nfixture').encode()
                if args[:1] == ('show',):
                    return json.dumps(candidate).encode()
                if args[:1] == ('diff',):
                    return changed.encode()
                return b''
            ctx.base = types.SimpleNamespace(git=git, checkout=lambda *args: None,
                REMOTE='https://example.invalid/repository', parse=json.loads)
            ctx.utility = object()
            ctx.remote = lambda revision: None
            ctx.prior = types.SimpleNamespace(candidate_metadata=lambda *args: candidate)
            with self.subTest(reason=reason), self.assertRaisesRegex(subject.Stop, '^' + reason + '$'):
                ctx.candidate(self.f.state['desired'])
            self.assertFalse(any(args[:1] == ('worktree',) for args in commands))


class LeaseTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.f = Fixture(Path(temporary.name))
        patched = patch.object(subject, 'WORK', self.f.path)
        patched.start()
        self.addCleanup(patched.stop)
        self.seed = subject.OLD_SEED
        self.snapshot_seeds = []
        self.changed = False
        self.slots_calls = 0
        self.original = types.SimpleNamespace(prior=self.f.prior, base=object(), utility=object(),
            worker=self.f.gate, slots=self.slots, snapshot=self.snapshot)
        self.adapter = types.SimpleNamespace(OLD_SEED=subject.OLD_SEED, OLD_CORE=subject.OLD_CORE,
            NEW_SEED=subject.SEED, CORE=subject.CORE,
            PREPARED_SOURCES={subject.INVENTORY: subject.SOURCE}, Context=lambda package: self.original)
        self.f.hold['core'] = subject.OLD_CORE
        self.f.write('program-rollout-hold.json', self.f.hold)

    def slots(self):
        self.slots_calls += 1
        state = copy.deepcopy(self.f.state)
        state['base_revision'] = self.seed
        controls = {'state': json.dumps(state).encode(), 'config': self.seed.encode(), 'unit': b'reviewed unit'}
        if self.changed and self.slots_calls > 1:
            controls['unit'] = b'concurrent unit'
        return controls, {'base_revision': self.seed}, state

    def snapshot(self, inventory, source, seed):
        self.snapshot_seeds.append(seed)
        subject.need(inventory == subject.INVENTORY and source == subject.SOURCE and seed == self.seed,
            'LIVE_SOURCE_CHANGED')

    def lease(self):
        with patch.object(subject, 'load_recovery', return_value=self.adapter), \
                patch.object(subject, 'Context', side_effect=AssertionError('New runtime must not load during lease')):
            return subject.lease(self.f.path)

    def test_old_exact_hold_routes_prepare_without_loading_new_runtime(self):
        result = self.lease()
        self.assertEqual(result['worker_request'], 'prepare')
        self.assertFalse(result['published'])
        self.assertTrue(result['publication_held'])
        self.assertEqual(self.snapshot_seeds, [subject.OLD_SEED, subject.OLD_SEED])

    def test_old_authorized_or_foreign_hold_cannot_route_prepare(self):
        for field, value in [('authorization', {'candidate': subject.OLD_CANDIDATE}),
                ('core', 'e' * 40), ('inventory_sha256', 'e' * 64), ('source', {})]:
            hold = copy.deepcopy(self.f.hold)
            hold[field] = value
            self.f.write('program-rollout-hold.json', hold)
            with self.subTest(field=field), self.assertRaisesRegex(subject.Stop, '^OLD_HOLD_DIFFERS_OR_AUTHORIZED$'):
                self.lease()

    def test_new_seed_routes_status_even_when_hold_is_already_retired(self):
        self.seed = subject.SEED
        (self.f.path / 'program-rollout-hold.json').unlink()
        result = self.lease()
        self.assertEqual(result['worker_request'], 'status')
        self.assertFalse(result['published'], 'Lease alone cannot claim completed publication')
        self.assertEqual(self.snapshot_seeds, [subject.SEED, subject.SEED])

    def test_new_seed_authorized_hold_is_left_for_the_full_status_gate(self):
        self.seed = subject.SEED
        self.f.hold['authorization'] = {'candidate': 'e' * 40}
        self.f.write('program-rollout-hold.json', self.f.hold)
        self.assertEqual(self.lease()['worker_request'], 'status')
        self.assertEqual(self.f.gate.program_gate_json(self.f.path / 'program-rollout-hold.json'), self.f.hold)

    def test_lease_rejects_foreign_seed_and_concurrent_control_changes(self):
        self.seed = 'e' * 40
        with self.assertRaisesRegex(subject.Stop, '^CONFIGURATION_SEED_INVALID$'):
            self.lease()
        self.seed = subject.OLD_SEED
        self.slots_calls = 0
        self.changed = True
        with self.assertRaisesRegex(subject.Stop, '^CONTROL_STATE_CHANGED$'):
            self.lease()

    def test_execute_dispatches_lease_before_full_context_and_forbids_receipt(self):
        with patch.object(subject.socket, 'gethostname', return_value='srv1836524'), \
                patch.object(subject, 'lease', return_value={'worker_request': 'prepare'}) as lease, \
                patch.object(subject, 'Context', side_effect=AssertionError('New runtime must not load during lease')):
            result = subject.execute(self.f.path, 'lease')
            self.assertEqual(result, {'worker_request': 'prepare'})
            lease.assert_called_once_with(self.f.path)
            with self.assertRaisesRegex(subject.Stop, '^RECEIPT_MODE_INVALID$'):
                subject.execute(self.f.path, 'lease', self.f.path / 'pending-program-acceptance.json')


if __name__ == '__main__':
    unittest.main()
