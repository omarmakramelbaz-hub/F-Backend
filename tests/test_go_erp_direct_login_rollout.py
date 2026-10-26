"""A native-only upgrade preserves accepted .55 proof and freshly captured website source."""
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

from go_erp_direct_login_reference import REFERENCE as REPAIR_REFERENCE
import test_go_erp_warm_start_repair_upgrade as reference
from go_erp_performance_reference import REFERENCE as ENGINE_REFERENCE

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('direct_login_rollout_subject', ROOT / 'deployment/upgrade_go_erp_direct_login_root.py')
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
    value.update(base_revision=subject.OLD_SEED, counter=55, last_remote_sha=subject.INPUT_CANDIDATE,
        version_floor=subject.INPUT_VERSION, sequence_floor=40260000000)
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
    return {'format': 1, 'kind': 'go-erp-warm-start-repair-rollout', 'core': subject.OLD_CORE,
        'seed': subject.OLD_SEED, 'runtime': subject.OLD_RUNTIME, 'phase': 'ready',
        'candidate': subject.INPUT_CANDIDATE, 'version': subject.INPUT_VERSION,
        'source': copy.deepcopy(state['published']['release']['source']), 'inventory': state['published']['inventorySha256'],
        'baseline_counter': 54, 'preserve_unknown': {'proof': [1, 2, 3]}}


class BindingTests(unittest.TestCase):
    def test_manual_broker_and_tick_keep_the_original_request_and_publication_guards(self):
        def definitions(raw):
            return {node.name: ast.get_source_segment(raw, node) for node in ast.parse(raw).body
                if isinstance(node, (ast.ClassDef, ast.FunctionDef))}
        original_manual = (REPAIR_REFERENCE / 'deployment/go_erp_warm_start_repair_manual_root.py').read_text()
        current_manual = (ROOT / 'deployment/go_erp_direct_login_manual_root.py').read_text()
        self.assertEqual(definitions(current_manual), definitions(original_manual))
        original_tick = definitions((REPAIR_REFERENCE / 'deployment/go_erp_warm_start_repair_tick_root.py').read_text())
        current_tick = definitions((ROOT / 'deployment/go_erp_direct_login_tick_root.py').read_text())
        for name in original_tick:
            if name == 'main':
                self.assertIn("need(not PINS_PENDING, 'NATIVE_PINS_PENDING')", current_tick[name])
                self.assertEqual(current_tick[name].replace("        need(not PINS_PENDING, 'NATIVE_PINS_PENDING')\n", ''),
                    original_tick[name])
            else:
                self.assertEqual(current_tick[name].replace('go_erp_direct_login_pinned_source',
                    'go_erp_warm_start_repair_pinned_source'), original_tick[name], name)

    def test_exact_published_55_is_pure_and_unknown_reservations_or_publications_stop(self):
        state = published_state(); before = copy.deepcopy(state)
        subject.checked_published_state(state, state['published']['release'])
        self.assertEqual(state, before)
        for field, value in [('counter', 54), ('counter', 56), ('counter', True),
            ('base_revision', 'f' * 40), ('last_remote_sha', 'f' * 40), ('version_floor', '0.4.4-preview.52'),
            ('sequence_floor', True), ('sequence_floor', state['sequence_floor'] + 1), ('published_history', [])]:
            changed = published_state(); changed[field] = value
            with self.subTest(field=field), self.assertRaises(subject.Stop):
                subject.checked_published_state(changed, changed['published']['release'])
        for field, value in [('commit', 'f' * 40), ('version', '0.4.4-preview.56'),
            ('candidatePolicy', subject.POLICY), ('pushed', False), ('status', 'waiting-ci'),
            ('inventorySha256', 'f' * 64)]:
            changed = published_state(); changed['desired'][field] = value
            with self.subTest(desired=field), self.assertRaises(subject.Stop):
                subject.checked_published_state(changed, changed['published']['release'])
        with self.assertRaisesRegex(subject.Stop, 'PUBLIC_FEED_NOT_CURRENT'):
            subject.checked_published_state(state, None)

    def test_prior_job_is_exact_accepted_55_without_reinterpreting_its_old_source(self):
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
        source = reference.frozen('direct_login_test_new_source', new['go_erp_live_source.py'])
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
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-direct-login-rollout-')
        self.addCleanup(temporary.cleanup); self.path = Path(temporary.name)
        self.work = self.path / 'work'; self.control = self.path / 'control'
        for path in (self.work / 'state', self.work / 'program-rollout-authorizations', self.control / 'private/audits'):
            path.mkdir(parents=True, mode=0o700)
        self.work.chmod(0o700)
        self.template = configured()
        for name, value in [('WORK', self.work), ('CONTROL', self.control)]:
            scope = patch.object(self.template, name, value); scope.start(); self.addCleanup(scope.stop)
        self.engine = reference.load('direct_login_test_engine', ENGINE_REFERENCE / 'deployment/apply_go_erp_manual_updates_root.py')
        self.engine.Stop = subject.Stop
        self.ctx = ctx = object.__new__(self.template.Context)
        ctx.e = self.engine; ctx.owner = types.SimpleNamespace(pw_gid=0)
        ctx.base = types.SimpleNamespace(parse=json.loads, normalized_source=lambda files: {'format': 1,
            'framework': '8.83.29', 'files': len(files), 'sha256': hashlib.sha256(b''.join(files.values())).hexdigest()})
        ctx.old_source = reference.new_source; ctx.old_worker = reference.new_worker
        ctx.worker = reference.new_worker
        ctx.runtime = Path('policy-reviewed-new-cohort')
        ctx.job_path = self.control / 'private' / ('direct-login-' + TEST_CORE + '.json')
        ctx.prior_job_path = self.control / 'private' / ('warm-start-repair-' + subject.OLD_CORE + '.json')
        ctx.job = None; ctx.release = types.SimpleNamespace(revision='9' * 40)
        self.current = published_state(); self.feed = copy.deepcopy(self.current['published']['release'])
        ctx.state = lambda: copy.deepcopy(self.current); ctx.publication = lambda: copy.deepcopy(self.feed)
        self.files = {'app/new-live.php': b'new website change after accepted .55\n'}
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

    def test_fresh_source_after_55_is_captured_while_raw_old_proof_and_state_remain_unchanged(self):
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

    def test_pending_55_receipt_is_required_before_capture_but_never_replayed_as_new_authorization(self):
        self.pending_path.unlink()
        with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_DIRECT_LOGIN_PENDING_RECEIPT_CHANGED'):
            self.ctx.job_record()
        self.assertFalse(self.ctx.job_path.exists())
        self.write(self.pending_path, self.receipt_raw, raw=True); self.ctx.job_record()
        self.assertEqual(self.pending_path.read_bytes(), self.receipt_raw)
        self.assertFalse((self.work / 'program-rollout-hold.json').exists())
        self.assertEqual(self.ctx.expected['core'], TEST_CORE)

    def test_original_completion_stops_after_a_consumed_56_failure_without_allocating_again(self):
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
            self.current['counter'] = 56
            self.current['desired']['status'] = 'retry-build'
            self.write(self.work / 'state/current.json', self.current)
            return 1, 'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
        self.ctx.tick = failed_tick
        with patch.object(self.template, 'quiet'), patch.object(self.template.time, 'monotonic', return_value=1000):
            with self.assertRaisesRegex(subject.Stop, 'ALLOCATION_REVIEW_REQUIRED'):
                self.ctx.complete()
        self.assertEqual(calls, [(True, 7200)])
        self.assertEqual(self.current['counter'], 56)
        self.assertEqual(self.ctx.job['baseline_counter'], 55)
        self.assertIsNone(self.ctx.job['candidate'])


if __name__ == '__main__':
    unittest.main()
