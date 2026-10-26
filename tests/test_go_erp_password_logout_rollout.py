"""A native-only upgrade preserves accepted .57 proof and freshly captured website source."""
import ast
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from contextlib import contextmanager
from unittest.mock import patch

from go_erp_password_logout_reference import REFERENCE as REPAIR_REFERENCE
import go_erp_password_logout_reference as reference
from go_erp_performance_reference import REFERENCE as ENGINE_REFERENCE

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('password_logout_rollout_subject', ROOT / 'deployment/upgrade_go_erp_password_logout_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)
TEMPLATE = subprocess.check_output(['git', 'show', subject.TEMPLATE_RELEASE + ':deployment/upgrade_go_erp_warm_start_root.py'], cwd=ROOT)
TEST_CORE = 'c' * 40
TEST_SEED = 'd' * 40
TEST_PINS = {**subject.OLD_MANUAL_PINS, 'go_erp_manual_tick_root.py': 'a' * 64,
    'go_erp_manual_updates_root.py': 'b' * 64}


def configured():
    return subject.configure(subject.load_template(TEMPLATE), TEST_CORE, TEST_SEED, TEST_PINS)


def published_state():
    value = reference.failed_state()
    value.update(base_revision=subject.OLD_SEED, counter=57, last_remote_sha=subject.INPUT_CANDIDATE,
        version_floor=subject.INPUT_VERSION, sequence_floor=40352189539)
    value['desired'].update(commit=subject.INPUT_CANDIDATE, version=subject.INPUT_VERSION,
        candidatePolicy=subject.OLD_POLICY, inventorySha256='e' * 64, pushed=True,
        status='published', failures=0, retry_at=0)
    release = value['published']['release']
    release.update(version=subject.INPUT_VERSION, sequence=value['sequence_floor'], source_revision=subject.INPUT_CANDIDATE)
    value['published'].update(commit=subject.INPUT_CANDIDATE, inventorySha256='e' * 64,
        run_id=release['sequence'], run_attempt=1)
    value['published_history'] = [copy.deepcopy(release)]
    return value


def old_job(state):
    return {'format': 1, 'kind': 'go-erp-warm-start-rollout', 'core': subject.OLD_CORE,
        'seed': subject.OLD_SEED, 'runtime': subject.OLD_RUNTIME, 'phase': 'ready',
        'candidate': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
        'source': copy.deepcopy(state['published']['release']['source']), 'inventory': state['published']['inventorySha256'],
        'baseline_counter': 56, 'preserve_unknown': {'proof': [1, 2, 3]}}


class BindingTests(unittest.TestCase):
    def test_private_errors_never_escape_but_frozen_public_reason_codes_survive(self):
        class FrozenProgramError(Exception):
            pass
        self.assertEqual(subject.public_failure(FrozenProgramError('GO_ERP_PROGRAM_GATE_CI_RELEASE_MISMATCH')),
            'GO_ERP_PROGRAM_GATE_CI_RELEASE_MISMATCH')
        self.assertEqual(subject.public_failure(subject.Stop('LATCHED_CANDIDATE_CHANGED')), 'LATCHED_CANDIDATE_CHANGED')
        for error in (ValueError('PRIVATE_PASSWORD'), OSError('/private/token/path'),
                FrozenProgramError('GO_ERP_ERROR /private/token'), FrozenProgramError('GO_ERP_' + 'X' * 151)):
            self.assertEqual(subject.public_failure(error), 'PRIVATE_FAILURE')

    def test_manual_broker_and_tick_keep_the_original_request_and_publication_guards(self):
        def definitions(raw):
            return {node.name: ast.get_source_segment(raw, node) for node in ast.parse(raw).body
                if isinstance(node, (ast.ClassDef, ast.FunctionDef))}
        original_manual = (ROOT / 'deployment/go_erp_login_reopen_manual_root.py').read_text()
        current_manual = (ROOT / 'deployment/go_erp_password_logout_manual_root.py').read_text()
        self.assertEqual(definitions(current_manual), definitions(original_manual))
        original_tick = definitions((ROOT / 'deployment/go_erp_login_reopen_tick_root.py').read_text())
        current_tick = definitions((ROOT / 'deployment/go_erp_password_logout_tick_root.py').read_text())
        for name in original_tick:
            if name == 'main':
                self.assertIn("need(not PINS_PENDING, 'NATIVE_PINS_PENDING')", current_tick[name])
                self.assertEqual(current_tick[name], original_tick[name])
            else:
                self.assertEqual(current_tick[name].replace('go_erp_password_logout_pinned_source',
                    'go_erp_login_reopen_pinned_source'), original_tick[name], name)

    def test_exact_published_57_is_pure_and_unknown_reservations_or_publications_stop(self):
        state = published_state(); before = copy.deepcopy(state)
        subject.checked_published_state(state, state['published']['release'])
        self.assertEqual(state, before)
        for field, value in [('counter', 56), ('counter', 58), ('counter', True),
            ('base_revision', 'f' * 40), ('last_remote_sha', 'f' * 40), ('version_floor', '0.4.4-preview.52'),
            ('sequence_floor', True), ('sequence_floor', state['sequence_floor'] + 1), ('published_history', [])]:
            changed = published_state(); changed[field] = value
            with self.subTest(field=field), self.assertRaises(subject.Stop):
                subject.checked_published_state(changed, changed['published']['release'])
        for field, value in [('commit', 'f' * 40), ('version', '0.4.4-preview.58'),
            ('candidatePolicy', subject.POLICY), ('pushed', False), ('status', 'waiting-ci'),
            ('inventorySha256', 'f' * 64)]:
            changed = published_state(); changed['desired'][field] = value
            with self.subTest(desired=field), self.assertRaises(subject.Stop):
                subject.checked_published_state(changed, changed['published']['release'])
        with self.assertRaisesRegex(subject.Stop, 'PUBLIC_FEED_NOT_CURRENT'):
            subject.checked_published_state(state, None)

    def test_prior_job_is_exact_accepted_57_without_reinterpreting_its_old_source(self):
        state = published_state(); job = old_job(state); before = copy.deepcopy(job)
        subject.checked_previous_job(job, state); self.assertEqual(job, before)
        for field, value in [('phase', 'running'), ('core', 'f' * 40), ('seed', 'f' * 40),
            ('candidate', 'f' * 40), ('version', '0.4.4-preview.52'), ('inventory', 'f' * 64),
            ('runtime', 'other'), ('source', {'framework': '8.83.29'})]:
            changed = old_job(state); changed[field] = value
            with self.subTest(field=field), self.assertRaises(subject.Stop):
                subject.checked_previous_job(changed, state)

    def test_template_bytes_and_native_only_comparison_are_bound(self):
        with self.assertRaisesRegex(subject.Stop, 'ROLLOUT_TEMPLATE_PIN_INVALID'):
            subject.load_template(TEMPLATE + b'\n')
        template = configured(); calls = []
        template.checked_native_core(types.SimpleNamespace(git=lambda *args: calls.append(args) or b''))
        self.assertEqual(calls[0][5:7], (subject.OLD_CORE, TEST_CORE))
        self.assertNotIn(subject.INPUT_CANDIDATE, calls[0])
        with self.assertRaisesRegex(subject.Stop, 'NATIVE_CORE_APPLICATION_OR_DEPENDENCY_CHANGED'):
            template.checked_native_core(types.SimpleNamespace(git=lambda *args: b'app/changed.php\n'))
        with self.assertRaisesRegex(subject.Stop, 'NATIVE_PINS_PENDING'):
            subject.configure(subject.load_template(TEMPLATE), 'PENDING', TEST_SEED, TEST_PINS)

    def test_derived_runtime_changes_only_seed_policy_constants_and_preserves_all_state_history(self):
        template = configured()
        old = reference.NEW
        new = template.derive_native_payloads(old, reference.tick.NEW_COHORT['pins'], TEST_SEED)
        self.assertEqual({name for name in old if old[name] != new[name]},
            {'go_erp_live_source.py', 'go_erp_live_updates.py'})
        for name in old:
            expected = old[name]
            if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
                expected = expected.replace(subject.OLD_SEED.encode(), TEST_SEED.encode())
                expected = expected.replace(subject.OLD_SUFFIX, subject.SUFFIX)
            self.assertEqual(new[name], expected, name)
        source = reference.frozen('password_logout_test_new_source', new['go_erp_live_source.py'])
        state = published_state()
        inventory = {'app/current.php': {'authority': 'live', 'present': True, 'sha256': '1' * 64,
            'bytes': 12, 'mode': '100644'}, 'resources/removed.php': {'authority': 'removed', 'present': False,
            'sha256': None, 'bytes': 0, 'mode': '100644'}}
        state['observed'] = reference.new_source._metadata(subject.OLD_SEED, inventory)
        before = copy.deepcopy(state)
        migrated = template.migrate_state(state, reference.new_source, source)
        self.assertEqual(state, before)
        self.assertEqual(migrated['base_revision'], TEST_SEED)
        self.assertEqual(migrated['observed'], source._metadata(TEST_SEED, inventory))
        self.assertEqual({k: v for k, v in migrated.items() if k not in ('base_revision', 'observed')},
            {k: v for k, v in state.items() if k not in ('base_revision', 'observed')})


@unittest.skipUnless(os.geteuid() == 0, 'Production file and acceptance guards run as root in rollout CI.')
class FreshCaptureTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-password-logout-rollout-')
        self.addCleanup(temporary.cleanup); self.path = Path(temporary.name)
        self.work = self.path / 'work'; self.control = self.path / 'control'
        for path in (self.work / 'state', self.work / 'program-rollout-authorizations', self.control / 'private/audits'):
            path.mkdir(parents=True, mode=0o700)
        self.work.chmod(0o700)
        self.template = configured()
        for name, value in [('WORK', self.work), ('CONTROL', self.control)]:
            scope = patch.object(self.template, name, value); scope.start(); self.addCleanup(scope.stop)
        self.engine = reference.load('password_logout_test_engine', ENGINE_REFERENCE / 'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop = subject.Stop
        self.ctx = ctx = object.__new__(self.template.Context)
        ctx.e = self.engine; ctx.owner = types.SimpleNamespace(pw_gid=0)
        ctx.base = types.SimpleNamespace(parse=json.loads, normalized_source=lambda files: {'format': 1,
            'framework': '8.83.29', 'files': len(files), 'sha256': hashlib.sha256(b''.join(files.values())).hexdigest()})
        ctx.old_source = reference.new_source; ctx.old_worker = reference.new_worker
        ctx.worker = reference.new_worker
        ctx.runtime = Path('policy-reviewed-new-cohort')
        ctx.job_path = self.control / 'private' / ('password-logout-' + TEST_CORE + '.json')
        ctx.prior_job_path = self.control / 'private' / ('login-reopen-' + subject.OLD_CORE + '.json')
        ctx.job = None; ctx.release = types.SimpleNamespace(revision='9' * 40)
        self.current = published_state(); self.feed = copy.deepcopy(self.current['published']['release'])
        ctx.state = lambda: copy.deepcopy(self.current); ctx.publication = lambda: copy.deepcopy(self.feed)
        self.files = {'app/new-live.php': b'new website change after accepted .57\n'}
        self.inventory = {name: {'authority': 'live', 'present': True, 'sha256': hashlib.sha256(raw).hexdigest(),
            'bytes': len(raw), 'mode': '100644'} for name, raw in self.files.items()}
        ctx.snapshot = lambda state, checkout, source: {'metadata': source._metadata(state['base_revision'], self.inventory),
            '_files': self.files}
        self.previous = old_job(self.current)
        self.write(ctx.prior_job_path, self.previous)
        self.write(self.work / 'state/current.json', self.current)
        release = self.current['published']['release']; published = self.current['published']
        expected = ctx.old_worker.program_gate_expected(subject.OLD_CORE, self.previous['source'], self.previous['inventory'])
        evidence_path = self.work / 'retained-employee-evidence.json'; self.write(evidence_path, {'retained': True})
        evidence = {'path': str(evidence_path), 'sha256': hashlib.sha256(evidence_path.read_bytes()).hexdigest()}
        receipt = {'format': 1, 'kind': 'go-erp-program-acceptance', **expected,
            'candidate_revision': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
            'installer': release['installer'], 'ci': {'directory': str(self.work / 'retained-ci'),
                'run_id': published['run_id'], 'run_attempt': published['run_attempt']}, 'employee_evidence': evidence}
        self.receipt_raw = self.engine.canonical(receipt); digest = hashlib.sha256(self.receipt_raw).hexdigest()
        self.receipt_path = self.work / 'program-rollout-authorizations' / ('receipt-' + digest + '.json')
        self.write(self.receipt_path, self.receipt_raw, raw=True)
        completed = {'format': 1, 'kind': 'go-erp-program-rollout-completed', **expected, 'release': release,
            'authorization': {'candidate': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
                'installer': release['installer'], 'release': release, 'receipt_path': str(self.receipt_path),
                'receipt_sha256': digest, 'employee_evidence': evidence}, 'canonical_publication_verified': True}
        self.completed_path = self.work / ('program-rollout-completed-' + subject.OLD_CORE + '.json')
        self.write(self.completed_path, completed)
        self.pending_path = self.work / 'pending-program-acceptance.json'; self.write(self.pending_path, self.receipt_raw, raw=True)

    def write(self, path, value, raw=False):
        self.engine.atomic_write(path, value if raw else self.engine.canonical(value), 0, 0, 0o600)

    def test_fresh_source_after_57_is_captured_while_raw_old_proof_and_state_remain_unchanged(self):
        protected = [self.ctx.prior_job_path, self.work / 'state/current.json', self.receipt_path, self.completed_path, self.pending_path]
        before = {path: path.read_bytes() for path in protected}
        self.ctx.job_record(); job = self.ctx.job; audit = Path(job['audit'])
        self.assertEqual(job['source'], self.ctx.base.normalized_source(self.files))
        self.assertNotEqual(job['source'], self.previous['source'])
        self.assertEqual(job['published_inventory_at_entry'], self.previous['inventory'])
        self.assertNotEqual(job['inventory'], self.previous['inventory'])
        self.assertEqual(job['remote_lease'], subject.INPUT_CANDIDATE)
        self.assertIsNone(job['candidate'])
        self.assertEqual((audit / 'previous-state-raw.json').read_bytes(), before[self.work / 'state/current.json'])
        self.assertEqual((audit / 'previous-rollout.json').read_bytes(), before[self.ctx.prior_job_path])
        self.assertEqual((audit / 'previous-pending-acceptance.json').read_bytes(), self.receipt_raw)
        self.assertEqual({path: path.read_bytes() for path in protected}, before)
        # Resume verifies the captured plan instead of rescanning a new source.
        self.ctx.snapshot = lambda *args: (_ for _ in ()).throw(AssertionError('resume must preserve the captured source'))
        self.ctx.job_record()
        self.assertEqual(self.ctx.job, job)

    def test_unknown_hold_or_changed_retained_receipt_prevents_a_new_job_and_keeps_bytes(self):
        hold = self.work / 'program-rollout-hold.json'; self.write(hold, {'authorization': {'unknown': True}})
        raw = hold.read_bytes()
        with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_ROLLOUT_HOLD_PRESENT'):
            self.ctx.job_record()
        self.assertEqual(hold.read_bytes(), raw); self.assertFalse(self.ctx.job_path.exists()); hold.unlink()
        self.write(self.receipt_path, self.receipt_raw + b' ', raw=True)
        with self.assertRaises(Exception): self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())

    def test_pending_57_receipt_is_required_before_capture_but_never_replayed_as_new_authorization(self):
        self.pending_path.unlink()
        with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_PASSWORD_LOGOUT_PENDING_RECEIPT_CHANGED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())
        self.write(self.pending_path, self.receipt_raw, raw=True); self.ctx.job_record()
        self.assertEqual(self.pending_path.read_bytes(), self.receipt_raw)
        self.assertFalse((self.work / 'program-rollout-hold.json').exists())
        self.assertEqual(self.ctx.expected['core'], TEST_CORE)

    def test_original_completion_stops_after_a_consumed_58_failure_without_allocating_again(self):
        self.ctx.job_record()
        self.ctx.ready = lambda state: False
        self.ctx.live = lambda state: None
        self.ctx.public_status = lambda *args, **kwargs: None
        @contextmanager
        def lock(work):
            self.assertEqual(work, self.work)
            yield lambda: None
        self.ctx.utility = types.SimpleNamespace(watcher_lock=lock)
        calls = []
        def failed_tick(allow, budget):
            self.assertTrue(allow)
            calls.append((allow, budget))
            self.current['counter'] = 58
            self.current['desired']['status'] = 'retry-build'
            self.write(self.work / 'state/current.json', self.current)
            return 1, 'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
        self.ctx.tick = failed_tick
        with patch.object(self.template, 'quiet'), patch.object(self.template.time, 'monotonic', return_value=1000):
            with self.assertRaisesRegex(subject.Stop, 'ALLOCATION_REVIEW_REQUIRED'):
                self.ctx.complete()
        self.assertEqual(calls, [(True, 7200)])
        self.assertEqual(self.current['counter'], 58)
        self.assertEqual(self.ctx.job['baseline_counter'], 57)
        self.assertIsNone(self.ctx.job['candidate'])

    def current_candidate(self):
        self.ctx.job_record()
        candidate = '1' * 40
        self.ctx.job.update(candidate=candidate, version='0.4.4-preview.58', phase='running')
        self.current.update(base_revision=TEST_SEED, counter=58, last_remote_sha=candidate)
        self.current['desired'].update(commit=candidate, version=self.ctx.job['version'],
            candidatePolicy=subject.POLICY, inventorySha256=self.ctx.job['inventory'], status='waiting-ci')
        self.ctx.ready = lambda state: False
        receipt = json.loads(self.receipt_raw)
        receipt.update(**self.ctx.expected, candidate_revision=candidate, version=self.ctx.job['version'])
        self.ctx.save()
        self.write(self.work / 'state/current.json', self.current)
        return receipt

    def completion_scope(self, events):
        @contextmanager
        def lock(work):
            self.assertEqual(work, self.work)
            events.append('locked')
            try:
                yield lambda: None
            finally:
                events.append('unlocked')
        self.ctx.utility = types.SimpleNamespace(watcher_lock=lock)
        parent = self.template.Context.__mro__[1]
        def original_complete(context):
            self.assertIs(context, self.ctx)
            events.append('original-publication-loop')
            return {'original_worker': True}
        return patch.object(parent, 'complete', original_complete)

    def test_retained_current_receipt_resumes_original_authorization_before_publication_without_allocating(self):
        receipt = self.current_candidate(); self.write(self.pending_path, receipt)
        protected = [self.ctx.job_path, self.work / 'state/current.json', self.pending_path]
        before = {path: path.read_bytes() for path in protected}; events = []
        def authorize():
            self.assertEqual(events[-1], 'locked')
            events.append('original-authorization')
        self.ctx.authorized = authorize
        with self.completion_scope(events), patch.object(self.template, 'quiet'):
            self.assertEqual(self.ctx.complete(), {'original_worker': True})
        self.assertEqual(events, ['locked', 'original-authorization', 'unlocked', 'original-publication-loop'])
        self.assertEqual({path: path.read_bytes() for path in protected}, before)
        self.assertEqual(self.current['counter'], 58)

    def test_only_the_exact_archived_previous_receipt_can_fall_through_to_original_collection(self):
        self.current_candidate(); events = []
        self.ctx.authorized = lambda: self.fail('a previous receipt cannot authorize the new release')
        with self.completion_scope(events), patch.object(self.template, 'quiet'):
            self.ctx.complete()
        self.assertEqual(events, ['locked', 'unlocked', 'original-publication-loop'])
        unknown = json.loads(self.receipt_raw); unknown['candidate_revision'] = '2' * 40
        self.write(self.pending_path, unknown); events.clear()
        with self.completion_scope(events), patch.object(self.template, 'quiet'):
            with self.assertRaisesRegex(subject.Stop, 'PENDING_ACCEPTANCE_UNREVIEWED'):
                self.ctx.complete()
        self.assertNotIn('original-publication-loop', events)

    def test_changed_current_receipt_or_remote_revision_stops_before_collection(self):
        receipt = self.current_candidate(); events = []
        self.ctx.authorized = lambda: self.fail('changed bindings cannot authorize')
        for field, value in [('core', '2' * 40), ('source', {'private': 'changed'}),
                ('inventory_sha256', '2' * 64), ('version', '0.4.4-preview.59')]:
            changed = {**receipt, field: value}; self.write(self.pending_path, changed); events.clear()
            with self.subTest(field=field), self.completion_scope(events), patch.object(self.template, 'quiet'):
                with self.assertRaisesRegex(subject.Stop, 'ACCEPTANCE_BINDING_INVALID'):
                    self.ctx.complete()
            self.assertNotIn('original-publication-loop', events)
        self.write(self.pending_path, receipt); self.current['last_remote_sha'] = '3' * 40; events.clear()
        with self.completion_scope(events), patch.object(self.template, 'quiet'):
            with self.assertRaisesRegex(subject.Stop, 'LATCHED_CANDIDATE_CHANGED'):
                self.ctx.complete()
        self.assertNotIn('original-publication-loop', events)

    def test_original_authorization_failure_is_not_hidden_by_a_fresh_download(self):
        receipt = self.current_candidate(); self.write(self.pending_path, receipt); events = []
        before = self.pending_path.read_bytes()
        def fail():
            events.append('original-authorization')
            raise reference.new_worker.ProgramGateError('GO_ERP_PROGRAM_GATE_CI_RELEASE_MISMATCH')
        self.ctx.authorized = fail
        with self.completion_scope(events), patch.object(self.template, 'quiet'):
            with self.assertRaisesRegex(reference.new_worker.ProgramGateError, 'CI_RELEASE_MISMATCH'):
                self.ctx.complete()
        self.assertNotIn('original-publication-loop', events)
        self.assertEqual(self.pending_path.read_bytes(), before)
        self.assertEqual(self.current['counter'], 58)

    def test_malformed_pending_receipt_stops_without_overwriting_it_or_allocating(self):
        self.current_candidate(); events = []
        self.ctx.authorized = lambda: self.fail('malformed receipts cannot authorize')
        for raw in (b'[]\n', b'{"broken":'):
            self.write(self.pending_path, raw, raw=True); events.clear()
            with self.completion_scope(events), patch.object(self.template, 'quiet'):
                with self.assertRaises((subject.Stop, json.JSONDecodeError)):
                    self.ctx.complete()
            self.assertEqual(self.pending_path.read_bytes(), raw)
            self.assertNotIn('original-publication-loop', events)
            self.assertEqual(self.current['counter'], 58)


if __name__ == '__main__':
    unittest.main()
