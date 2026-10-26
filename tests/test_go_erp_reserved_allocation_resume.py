"""A consumed .54 is auditable but can authorize only one new .55 allocation."""
import copy
from contextlib import contextmanager
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

import test_go_erp_warm_start_repair_upgrade as reference
from go_erp_performance_reference import REFERENCE as ENGINE_REFERENCE

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('reserved_resume_subject', ROOT / 'deployment/resume_go_erp_reserved_allocation_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)
REPAIR_RAW = (ROOT / 'deployment/upgrade_go_erp_warm_start_repair_root.py').read_bytes()


def reserved_state():
    value = reference.failed_state()
    value.update(base_revision=subject.SEED, counter=54, sequence_floor=40230867670)
    value['desired'].update(status='retry-build', failures=3)
    return value


def original_job():
    return {'format': 1, 'kind': 'go-erp-warm-start-repair-rollout',
        'core': subject.CORE, 'seed': subject.SEED, 'runtime': subject.RUNTIME,
        'phase': 'running', 'prepare_started': True, 'baseline_candidate': subject.FAILED,
        'baseline_remote': subject.FAILED, 'baseline_counter': 53, 'candidate': None,
        'version': None, 'transient_failures': 0, 'inventory': subject.INVENTORY,
        'source': {'format': 1, 'framework': '8.83.29', 'files': 2, 'sha256': 'a' * 64},
        'public_nonce': 'b' * 32, 'audit': '/retained/original/audit',
        'future_field': {'preserved': ['unknown', 123]}}


class ReviewedStateTests(unittest.TestCase):
    def test_exact_reserved_state_is_pure_and_every_identity_boundary_is_enforced(self):
        value = reserved_state(); before = copy.deepcopy(value)
        subject.checked_reserved_state(value, None)
        subject.checked_reserved_state(value, value['published']['release'])
        self.assertEqual(value, before)
        cases = [('counter', 53), ('counter', 55), ('counter', True),
            ('base_revision', 'f' * 40), ('last_remote_sha', 'f' * 40),
            ('sequence_floor', 40230867671), ('sequence_floor', True),
            ('version_floor', '0.4.4-preview.53'), ('published_history', [])]
        for field, changed in cases:
            state = reserved_state(); state[field] = changed
            with self.subTest(field=field, changed=changed), self.assertRaises(subject.Stop):
                subject.checked_reserved_state(state, None)
        for field, changed in [('commit', 'f' * 40), ('version', '0.4.4-preview.54'),
            ('candidatePolicy', subject.POLICY), ('inventorySha256', 'f' * 64), ('pushed', False),
            ('status', 'blocked'), ('failures', 2), ('failures', True), ('retry_at', float('inf')),
            ('retry_at', float('nan')), ('retry_at', -1)]:
            state = reserved_state(); state['desired'][field] = changed
            with self.subTest(desired=field, changed=changed), self.assertRaises(subject.Stop):
                subject.checked_reserved_state(state, None)
        with self.assertRaisesRegex(subject.Stop, 'PUBLIC_FEED_NOT_CURRENT'):
            subject.checked_reserved_state(value, {**value['published']['release'], 'version': '0.4.4-preview.51'})

    def test_original_job_accepts_only_the_unlatched_failed_attempt_without_mutation(self):
        value = original_job(); before = copy.deepcopy(value)
        subject.checked_original_job(value); self.assertEqual(value, before)
        for field, changed in [('baseline_counter', 54), ('baseline_counter', True),
            ('baseline_candidate', 'f' * 40), ('baseline_remote', 'f' * 40),
            ('candidate', 'f' * 40), ('version', '0.4.4-preview.54'), ('phase', 'prepared'),
            ('prepare_started', False), ('transient_failures', 1), ('runtime', 'wrong'),
            ('core', 'f' * 40), ('inventory', 'f' * 64), ('reserved_allocation', {})]:
            job = original_job(); job[field] = changed
            with self.subTest(field=field), self.assertRaises(subject.Stop):
                subject.checked_original_job(job)

    def test_original_coordinator_is_byte_pinned(self):
        subject.load_repair(REPAIR_RAW)
        with self.assertRaisesRegex(subject.Stop, 'ORIGINAL_REPAIR_PIN_INVALID'):
            subject.load_repair(REPAIR_RAW + b'\n')


class CheckpointTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-reserved-checkpoint-')
        self.addCleanup(temporary.cleanup); self.path = Path(temporary.name)
        self.work = self.path / 'work'; self.control = self.path / 'control'
        for path in (self.work / 'state', self.work / 'staging', self.control / 'private/audits'):
            path.mkdir(parents=True, mode=0o700)
        self.repair = subject.load_repair(REPAIR_RAW)
        self.context_type = subject.make_context(self.repair)
        for name, value in [('WORK', self.work), ('CONTROL', self.control)]:
            scope = patch.object(self.repair, name, value); scope.start(); self.addCleanup(scope.stop)
        self.engine = reference.load('reserved_actual_file_engine', ENGINE_REFERENCE / 'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop = subject.Stop
        self.ctx = ctx = object.__new__(self.context_type)
        ctx.e = self.engine; ctx.runtime = Path(subject.RUNTIME)
        ctx.owner = types.SimpleNamespace(pw_uid=0)
        ctx.job_path = self.control / 'private' / ('warm-start-repair-' + subject.CORE + '.json')
        ctx.job = original_job(); ctx.release = types.SimpleNamespace(revision='c' * 40)
        ctx.base = types.SimpleNamespace(parse=json.loads, REMOTE='https://github.com/omarmakramelbaz-hub/F-Backend.git')
        self.remote_revision = subject.FAILED; self.remote_calls = []
        def remote(*args, **kwargs):
            self.remote_calls.append((args, kwargs))
            return (self.remote_revision + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
        ctx.base.git = remote
        ctx.worker = reference.new_worker
        ctx.expected = ctx.worker.program_gate_expected(subject.CORE, ctx.job['source'], subject.INVENTORY)
        self.hold_path = self.work / 'program-rollout-hold.json'
        self.hold = {'format': 1, 'kind': 'go-erp-program-rollout-hold', **ctx.expected, 'authorization': None}
        self.state = reserved_state()
        self.state['observed'] = {'inventory': {'app/one.php': {'bytes': 20}},
            'inventorySha256': subject.INVENTORY}
        scope = patch.object(ctx.worker, 'dependency_snapshot', return_value=self.state['dependency_guard'])
        scope.start(); self.addCleanup(scope.stop)
        self.write(self.work / 'state/current.json', self.state)
        self.write(ctx.job_path, ctx.job); self.write(self.hold_path, self.hold)
        ctx.state = lambda: json.loads((self.work / 'state/current.json').read_bytes())
        ctx.live = lambda state: {'metadata': copy.deepcopy(state['observed'])}
        ctx.publication = lambda: None
        self.archive_checks = []; ctx.verify_published_archive = lambda state: self.archive_checks.append(copy.deepcopy(state))
        ctx.quiet_hold = lambda: None
        ctx.prior_hold = lambda: (self.hold_path.read_bytes(), json.loads(self.hold_path.read_bytes()))
        ctx.ready = lambda state: False
        ctx.save = lambda: self.write(ctx.job_path, ctx.job)

    def write(self, path, value, raw=False):
        self.engine.atomic_write(path, value if raw else self.engine.canonical(value), 0, 0, 0o600)

    def prepare(self):
        self.ctx.prepare(None)
        return Path(self.ctx.job['reserved_allocation']['audit'])

    def test_checkpoint_retains_exact_raw_inputs_and_changes_only_the_active_job_baseline(self):
        paths = [self.work / 'state/current.json', self.ctx.job_path, self.hold_path]
        before = {path: path.read_bytes() for path in paths}
        job = copy.deepcopy(self.ctx.job)
        audit = self.prepare()
        self.assertEqual((audit / 'state.json').read_bytes(), before[paths[0]])
        self.assertEqual((audit / 'job.json').read_bytes(), before[paths[1]])
        self.assertEqual((audit / 'hold.json').read_bytes(), before[paths[2]])
        self.assertEqual(paths[0].read_bytes(), before[paths[0]])
        self.assertEqual(self.hold_path.read_bytes(), before[self.hold_path])
        self.assertEqual(self.ctx.job['baseline_counter'], 54)
        self.assertEqual({k: v for k, v in self.ctx.job.items() if k != 'reserved_allocation'},
            {**job, 'baseline_counter': 54})
        self.assertFalse(self.ctx.verify_checkpoint()['attempt_started'])
        checkpoint = self.ctx.job_path.read_bytes()
        self.prepare()
        self.assertEqual(self.ctx.job_path.read_bytes(), checkpoint)
        self.assertEqual(list((self.control / 'private/audits').iterdir()), [audit])
        self.assertEqual(self.ctx.reserved_state, self.state)

    def test_original_complete_reaches_exactly_one_durable_attempt_through_scoped_legacy_checker(self):
        self.prepare()
        # Production job_record validates its retained predecessor first; this
        # test isolates its activation of the scoped legacy-check adapter.
        with patch.object(self.repair.Context, 'job_record', lambda ctx: None):
            self.ctx.job_record()
        reviewed = copy.deepcopy(self.state); reviewed['base_revision'] = self.repair.OLD_SEED
        before = copy.deepcopy(reviewed)
        self.repair.checked_failed_state(reviewed, None)
        self.assertEqual(reviewed, before)
        changed = copy.deepcopy(reviewed); changed['counter'] = 55
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_STATE_CHANGED'):
            self.repair.checked_failed_state(changed, None)
        @contextmanager
        def lock(*args):
            yield lambda: None
        self.ctx.utility = types.SimpleNamespace(watcher_lock=lock)
        self.ctx.public_status = lambda *args: None
        class CalledOriginalTick(Exception): pass
        calls = []
        def child(ctx, allow, budget):
            calls.append((allow, budget))
            self.assertTrue(json.loads(ctx.job_path.read_bytes())['reserved_allocation']['attempt_started'])
            raise CalledOriginalTick()
        original_state = (self.work / 'state/current.json').read_bytes()
        with patch.object(self.repair, 'quiet'), patch.object(self.repair.Context, 'tick', child), \
             patch.object(self.repair.time, 'monotonic', return_value=1000), \
             patch.object(self.repair.time, 'time', return_value=1000):
            with self.assertRaises(CalledOriginalTick): self.ctx.complete()
        self.assertEqual(calls, [(True, 7200)])
        self.assertEqual(self.ctx.job['baseline_counter'], 54)
        self.assertEqual(self.ctx.job['transient_failures'], 1)
        self.assertEqual((self.work / 'state/current.json').read_bytes(), original_state)
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_ATTEMPT_REQUIRES_REVIEW'):
            self.ctx.tick(True, 1)

    def test_hold_authority_remote_and_acceptance_changes_prevent_checkpoint_creation(self):
        for action, code in [(lambda: setattr(self, 'remote_revision', 'f' * 40), 'RESERVED_REMOTE_LEASE_CHANGED'),
            (lambda: self.write(self.hold_path, {**self.hold, 'authorization': {'unreviewed': True}}), 'RESERVED_HOLD_REQUIRES_REVIEW'),
            (lambda: self.write(self.work / ('program-rollout-completed-' + subject.CORE + '.json'), {'unexpected': True}),
                'RESERVED_ACCEPTANCE_REQUIRES_REVIEW')]:
            with self.subTest(code=code):
                self.remote_revision = subject.FAILED; self.write(self.hold_path, self.hold)
                completed = self.work / ('program-rollout-completed-' + subject.CORE + '.json')
                if completed.exists(): completed.unlink()
                action()
                with self.assertRaisesRegex(subject.Stop, code): self.prepare()
                self.assertNotIn('reserved_allocation', self.ctx.job)
                self.assertEqual(self.ctx.state(), self.state)
        self.assertEqual(list((self.control / 'private/audits').iterdir()), [])

    def test_changed_audit_bytes_and_original_job_fields_are_detected(self):
        audit = self.prepare()
        for path in [audit / 'state.json', audit / 'job.json', audit / 'hold.json', audit / 'manifest.json']:
            raw = path.read_bytes(); self.write(path, raw + b' ', raw=True)
            with self.subTest(path=path.name), self.assertRaises(subject.Stop): self.ctx.verify_checkpoint()
            self.write(path, raw, raw=True)
        self.ctx.job['future_field']['preserved'].append('mutation')
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_JOB_CHANGED'): self.ctx.verify_checkpoint()

    def test_attempt_is_durable_before_child_and_never_reallocated_even_after_failure(self):
        self.prepare(); called = []
        def child(ctx, allow, budget):
            persisted = json.loads(ctx.job_path.read_bytes())
            self.assertTrue(persisted['reserved_allocation']['attempt_started'])
            called.append((allow, budget)); return 1, 'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
        with patch.object(self.repair.Context, 'tick', child):
            self.assertEqual(self.ctx.tick(True, 123), (1, 'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'))
            with self.assertRaisesRegex(subject.Stop, 'RESERVED_ATTEMPT_REQUIRES_REVIEW'): self.ctx.tick(True, 123)
            with self.assertRaisesRegex(subject.Stop, 'RESERVED_ATTEMPT_REQUIRES_REVIEW'): self.ctx.prepare(None)
        self.assertEqual(called, [(True, 123)])
        self.assertEqual(self.ctx.state(), self.state)

    def test_consumed_55_without_a_candidate_is_stopped_and_success_cannot_allocate_56(self):
        self.prepare()
        self.ctx.job['reserved_allocation']['attempt_started'] = True; self.ctx.save()
        state = copy.deepcopy(self.state); state['counter'] = 55
        self.write(self.work / 'state/current.json', state)
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_CANDIDATE_REQUIRES_REVIEW'): self.ctx.prepare(None)
        state['desired'].update(commit='e' * 40, version='0.4.4-preview.55', candidatePolicy=subject.POLICY,
            status='waiting-ci', pushed=True)
        state['last_remote_sha'] = 'e' * 40; self.remote_revision = 'e' * 40
        self.write(self.work / 'state/current.json', state)
        self.ctx.prepare(None)
        self.ctx.job.update(candidate='e' * 40, version='0.4.4-preview.55'); self.ctx.save()
        with patch.object(self.repair.Context, 'tick', return_value=(0, 'GO_ERP_LIVE_UPDATE_WAITING_CI')) as child:
            self.assertEqual(self.ctx.tick(False, 100), (0, 'GO_ERP_LIVE_UPDATE_WAITING_CI'))
            self.assertEqual(child.call_count, 1)
            with self.assertRaisesRegex(subject.Stop, 'RESERVED_ATTEMPT_REQUIRES_REVIEW'): self.ctx.tick(True, 100)
        self.assertEqual(self.ctx.state(), state)
        state['counter'] = 56; self.write(self.work / 'state/current.json', state)
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_ALLOCATION_REQUIRES_REVIEW'): self.ctx.prepare(None)

    def test_unpushed_55_requires_exact_staged_head_and_accepts_only_the_same_interrupted_push(self):
        self.prepare()
        self.ctx.job['reserved_allocation']['attempt_started'] = True; self.ctx.save()
        candidate = self.work / 'staging' / ('candidate-55-' + subject.INVENTORY[:16])
        candidate.mkdir(mode=0o700)
        state = copy.deepcopy(self.state); state['counter'] = 55
        state['desired'].update(commit='e' * 40, version='0.4.4-preview.55', candidatePolicy=subject.POLICY,
            status='prepared', pushed=False, candidatePath=str(candidate))
        self.write(self.work / 'state/current.json', state)
        head = ['e' * 40]; remote = [subject.FAILED]
        def git(root, *arguments, **options):
            if arguments == ('rev-parse', 'HEAD'):
                self.assertEqual(root, candidate)
                return (head[0] + '\n').encode()
            self.assertEqual(root, self.work / 'checkout')
            return (remote[0] + '\trefs/heads/codex/go-erp-live-source-20261021\n').encode()
        self.ctx.base.git = git
        self.ctx.prepare(None)
        remote[0] = 'e' * 40  # Original push succeeded before local state was saved.
        self.ctx.prepare(None)
        self.assertEqual(self.ctx.state(), state)
        remote[0] = 'f' * 40
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_REMOTE_LEASE_CHANGED'): self.ctx.prepare(None)
        remote[0] = subject.FAILED; head[0] = 'f' * 40
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_CANDIDATE_HEAD_CHANGED'): self.ctx.prepare(None)
        head[0] = 'e' * 40; state['desired']['candidatePath'] = str(self.work / 'different')
        self.write(self.work / 'state/current.json', state)
        with self.assertRaisesRegex(subject.Stop, 'RESERVED_CANDIDATE_PATH_CHANGED'): self.ctx.prepare(None)
        self.assertEqual(self.ctx.state()['counter'], 55)


if __name__ == '__main__':
    unittest.main()
