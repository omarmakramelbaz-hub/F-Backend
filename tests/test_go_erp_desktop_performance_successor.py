"""Isolated rejection and interruption checks; synthetic data is not a receipt."""
import copy
from contextlib import contextmanager
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

BASE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('audit_successor_under_test',
    BASE / 'deployment/go_erp_desktop_performance_successor.py')
r = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r)


def fixture():
    source = {'format': 1, 'files': 2, 'framework': '8.83.29', 'sha256': 'a' * 64}
    old_expected = {'core_revision': r.FAILED_CORE, 'source': source, 'inventory_sha256': r.INVENTORY}
    new_expected = {**old_expected, 'core_revision': r.NEW_CORE}
    published = {'commit': r.PREVIOUS_CANDIDATE, 'inventorySha256': 'b' * 64,
        'release': {'source_revision': r.PREVIOUS_CANDIDATE,
                    'version': '0.4.4-preview.60', 'sequence': 123}}
    pins = {'core': r.NEW_CORE, 'seed': r.NEW_SEED, 'old_runtime': 'policy-' + '4' * 64,
            'source': source, 'old_expected': old_expected, 'new_expected': new_expected,
            'previous_published': published}
    job = {'format': 1, 'kind': 'go-erp-warm-start-rollout',
        'core': r.FAILED_CORE, 'seed': r.FAILED_SEED, 'runtime': pins['old_runtime'],
        'phase': 'running', 'prepare_started': True, 'baseline_counter': 60,
        'baseline_candidate': r.PREVIOUS_CANDIDATE, 'baseline_remote': r.PREVIOUS_CANDIDATE,
        'remote_lease': r.PREVIOUS_CANDIDATE, 'candidate': r.FAILED_CANDIDATE,
        'version': '0.4.4-preview.61', 'inventory': r.INVENTORY, 'source': source}
    state = {'format': 1, 'base_revision': r.FAILED_SEED, 'counter': 61,
        'last_remote_sha': r.FAILED_CANDIDATE,
        'desired': {'commit': r.FAILED_CANDIDATE, 'version': '0.4.4-preview.61',
            'pushed': True, 'candidatePolicy': r.POLICY, 'inventorySha256': r.INVENTORY,
            'status': 'retry-build', 'failures': 1, 'retry_at': 900},
        'published': copy.deepcopy(published), 'version_floor': '0.4.4-preview.60',
        'sequence_floor': 123, 'published_history': [copy.deepcopy(published['release'])],
        'observed': {'seedRevision': r.FAILED_SEED, 'inventorySha256': r.INVENTORY}}
    return pins, job, state


class FailedBaseline(unittest.TestCase):
    def test_exact_failed61_is_separate_from_genuine_published60(self):
        pins, job, state = fixture(); original = copy.deepcopy((pins, job, state))
        self.assertEqual(r.checked_failed_state(state, None, pins, job), state['desired'])
        self.assertEqual(r.checked_failed_state(state, pins['previous_published']['release'], pins, job), state['desired'])
        self.assertEqual((pins, job, state), original)

    def test_job_allocation_or_binding_changes_are_rejected(self):
        for key, value in [('format', True), ('core', '9' * 40), ('seed', '9' * 40),
                ('runtime', 'policy-' + '9' * 64), ('phase', 'ready'), ('phase', 'prepared'),
                ('prepare_started', False), ('baseline_counter', 61),
                ('baseline_candidate', r.FAILED_CANDIDATE), ('baseline_remote', r.FAILED_CANDIDATE),
                ('remote_lease', r.FAILED_CANDIDATE), ('candidate', '9' * 40),
                ('version', '0.4.4-preview.62'), ('inventory', '9' * 64), ('source', {})]:
            pins, job, _state = fixture(); job[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(r.Stop):
                r.checked_failed_job(job, pins)

    def test_changed_candidate_status_counter_or_failure_budget_is_rejected(self):
        mutations = [('counter', 60), ('counter', 62), ('counter', 61.0), ('format', True),
            ('base_revision', '9' * 40), ('last_remote_sha', '9' * 40)]
        for key, value in mutations:
            pins, job, state = fixture(); state[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(r.Stop):
                r.checked_failed_state(state, None, pins, job)
        for key, value in [('commit', '9' * 40), ('version', '0.4.4-preview.62'),
                ('pushed', False), ('candidatePolicy', 'foreign'), ('inventorySha256', '9' * 64),
                ('status', 'blocked'), ('status', 'published'), ('status', 'waiting-ci'),
                ('failures', 0), ('failures', 3), ('failures', True),
                ('retry_at', -1), ('retry_at', float('nan')), ('retry_at', float('inf'))]:
            pins, job, state = fixture(); state['desired'][key] = value
            with self.subTest(key=key, value=value), self.assertRaises(r.Stop):
                r.checked_failed_state(state, None, pins, job)

    def test_later_or_changed_publication_and_observation_are_rejected(self):
        for mutation in ('published', 'version_floor', 'sequence_floor', 'ahead_history',
                         'observed_seed', 'observed_inventory', 'public_feed'):
            pins, job, state = fixture(); publication = None
            if mutation == 'published': state['published']['commit'] = r.FAILED_CANDIDATE
            elif mutation == 'version_floor': state['version_floor'] = '0.4.4-preview.61'
            elif mutation == 'sequence_floor': state['sequence_floor'] += 1
            elif mutation == 'ahead_history': state['published_history'].append({'version': '0.4.4-preview.61'})
            elif mutation == 'observed_seed': state['observed']['seedRevision'] = '9' * 40
            elif mutation == 'observed_inventory': state['observed']['inventorySha256'] = '9' * 64
            else: publication = {'version': '0.4.4-preview.61'}
            with self.subTest(mutation=mutation), self.assertRaises(r.Stop):
                r.checked_failed_state(state, publication, pins, job)

    def test_gate_may_only_change_exact_core_and_cannot_embed_authorization(self):
        for mutation in ('inventory', 'source', 'extra', 'authorization', 'no_delta', 'published61'):
            pins, _job, _state = fixture()
            if mutation == 'inventory': pins['new_expected']['inventory_sha256'] = '9' * 64
            elif mutation == 'source': pins['new_expected']['source'] = {}
            elif mutation == 'extra': pins['new_expected']['extra'] = 1
            elif mutation == 'authorization':
                pins['old_expected']['authorization'] = pins['new_expected']['authorization'] = None
            elif mutation == 'no_delta': pins['new_expected'] = copy.deepcopy(pins['old_expected'])
            else: pins['previous_published']['release']['version'] = '0.4.4-preview.61'
            with self.subTest(mutation=mutation), self.assertRaises(r.Stop): r.checked_pins(pins)


class MemoryIO:
    def __init__(self):
        self.files = {}; self.writes = []; self.private_writes = []; self.fail_after = None
        self.count = 0; self.directories = set()

    def interrupt(self):
        self.count += 1
        if self.count == self.fail_after: raise RuntimeError('injected durable-write interruption')

    def read_file(self, path, _limit, **kwargs):
        path = Path(path)
        if path not in self.files:
            if kwargs.get('optional'): return None
            raise FileNotFoundError(path)
        return self.files[path], None

    def directory(self, path, _uid, _gid, **_kwargs):
        if Path(path) not in self.directories: raise FileNotFoundError(path)

    def private_directory(self, path, verify):
        verify(); self.directories.add(Path(path))

    def private_record(self, path, value, verify):
        verify(); path = Path(path)
        raw = value if isinstance(value, bytes) else r.canonical(value)
        if path in self.files:
            if self.files[path] != raw: raise RuntimeError('immutable record changed')
            return
        self.files[path] = raw; self.private_writes.append(path); self.interrupt()

    def atomic_write(self, path, raw, *_args):
        self.files[Path(path)] = raw; self.writes.append(Path(path)); self.interrupt()

    canonical = staticmethod(r.canonical)


class HoldTransition(unittest.TestCase):
    def fixture(self):
        pins, job, state = fixture(); io = MemoryIO()
        audit = Path('/var/lib/go-erp-manual-updates/private/audits') / ('desktop-audit-hold-' + pins['core'])
        pending = b'{"synthetic":"original published60 pending receipt"}\n'
        io.files[r.HOLD] = r.canonical(r._hold(pins['old_expected']))
        io.files[r.PENDING] = pending
        old_record = Path('/var/lib/go-erp-manual-updates/private/shared-workspace-' + r.FAILED_CORE + '.json')
        io.files[old_record] = r.canonical(job)
        context = SimpleNamespace(e=io, base=SimpleNamespace(parse=json.loads),
                                  state=lambda: state, ready=Mock(return_value=False),
                                  authorized=Mock())
        stages = []; verify = Mock(return_value=True)
        def validate(stage): stages.append(stage); return True
        def run(): return r.replace_hold(context, io, audit, verify, validate, pins, pending)
        return SimpleNamespace(pins=pins, io=io, audit=audit, pending=pending, old_record=old_record,
            context=context, stages=stages, verify=verify, validate=validate, run=run)

    def test_only_hold_changes_and_immutable_before_images_are_retained(self):
        f = self.fixture(); old = f.io.files[f.old_record]; before = f.io.files[r.HOLD]
        self.assertTrue(f.run())
        self.assertEqual(f.io.writes, [r.HOLD])
        self.assertEqual(f.io.files[f.old_record], old)
        self.assertEqual(f.io.files[r.PENDING], f.pending)
        self.assertEqual(f.io.files[f.audit / 'hold-before.bin'], before)
        self.assertEqual(json.loads(f.io.files[r.HOLD]), r._hold(f.pins['new_expected']))
        self.assertFalse(f.run()); self.assertEqual(f.io.writes, [r.HOLD])

    def test_authorized_or_foreign_old_hold_and_pending_are_rejected_before_writes(self):
        for change in ('authorized', 'foreign', 'pending', 'completed61'):
            f = self.fixture()
            if change == 'authorized': f.io.files[r.HOLD] = r.canonical(r._hold(f.pins['old_expected'], {'candidate': r.FAILED_CANDIDATE}))
            elif change == 'foreign': f.io.files[r.HOLD] = r.canonical({'kind': 'foreign'})
            elif change == 'pending': f.io.files[r.PENDING] = b'foreign receipt'
            else: f.io.files[r.FAILED_COMPLETED] = b'{}'
            with self.subTest(change=change), self.assertRaises(r.Stop): f.run()
            self.assertEqual(f.io.writes, []); self.assertEqual(f.io.private_writes, [])

    def test_every_durable_write_interruption_resumes_without_old_record_rewrite(self):
        for write in range(1, 7):
            f = self.fixture(); old = f.io.files[f.old_record]; f.io.fail_after = write
            with self.subTest(write=write), self.assertRaisesRegex(RuntimeError, 'interruption'): f.run()
            f.io.fail_after = None
            f.run()
            self.assertEqual(f.io.files[f.old_record], old)
            self.assertEqual(json.loads(f.io.files[r.HOLD]), r._hold(f.pins['new_expected']))
            self.assertIn(f.audit / 'completed.json', f.io.files)
            self.assertEqual(f.io.writes, [r.HOLD])

    def test_tampered_intent_or_concurrent_hold_is_retained_and_rejected(self):
        for change in ('intent', 'after_image', 'hold'):
            f = self.fixture(); f.io.fail_after = 4
            with self.assertRaises(RuntimeError): f.run()
            f.io.fail_after = None
            if change == 'intent':
                value = json.loads(f.io.files[f.audit / 'intent.json']); value['to_seed'] = '9' * 40
                f.io.files[f.audit / 'intent.json'] = r.canonical(value)
            elif change == 'after_image': f.io.files[f.audit / 'hold-after.bin'] = b'{}'
            else: f.io.files[r.HOLD] = b'{"foreign":true}'
            before = dict(f.io.files)
            with self.subTest(change=change), self.assertRaises(r.Stop): f.run()
            self.assertEqual(f.io.files, before); self.assertEqual(f.io.writes, [])

    def test_lock_or_required_baseline_failure_cannot_enter_writes(self):
        for target in ('lock', 'baseline'):
            f = self.fixture()
            verify = (lambda: False) if target == 'lock' else f.verify
            validate = (lambda _stage: False) if target == 'baseline' else f.validate
            with self.subTest(target=target), self.assertRaises(r.Stop):
                r.replace_hold(f.context, f.io, f.audit, verify, validate, f.pins, f.pending)
            self.assertEqual(f.io.writes, []); self.assertEqual(f.io.private_writes, [])

    def test_completed_wal_cannot_accept_a_foreign_hold(self):
        f = self.fixture(); f.run(); f.io.files[r.HOLD] = r.canonical(r._hold(f.pins['old_expected']))
        with self.assertRaisesRegex(r.Stop, 'COMPLETED_HOLD_FOREIGN'): f.run()
        self.assertEqual(f.io.writes, [r.HOLD])

    def test_completed_retired_hold_requires_original_completed_gate_proof(self):
        f = self.fixture(); f.run(); del f.io.files[r.HOLD]
        with self.assertRaisesRegex(r.Stop, 'RETIRED_HOLD_WITHOUT_COMPLETED_GATE'): f.run()
        f.context.ready.return_value = True
        self.assertFalse(f.run())
        self.assertNotIn(r.HOLD, f.io.files)
        self.assertEqual(f.io.writes, [r.HOLD])

    def test_completed_successor_authorization_is_verified_and_never_overwritten(self):
        f = self.fixture(); f.run()
        authorized = r.canonical(r._hold(f.pins['new_expected'], {'synthetic': 'successor62 receipt'}))
        f.io.files[r.HOLD] = authorized
        self.assertFalse(f.run()); f.context.authorized.assert_called_once_with()
        self.assertEqual(f.io.files[r.HOLD], authorized); self.assertEqual(f.io.writes, [r.HOLD])
        f.context.authorized.side_effect = RuntimeError('original receipt verifier rejected')
        with self.assertRaisesRegex(RuntimeError, 'receipt verifier rejected'): f.run()
        self.assertEqual(f.io.files[r.HOLD], authorized)


class GeneralBinding(unittest.TestCase):
    def fixture(self):
        pins, job, state = fixture(); order = []
        class Original:
            def live(self, state): return self.original_live(state)
            def candidate(self, state): order.append('original-candidate'); return state['desired']
            def tick(self, allow, budget): order.append(('original-tick', allow, budget)); return 'original-tick'
            def prepare(self, utility): order.append(('original-prepare', utility)); return 'original-prepare'
            def authorized(self): order.append('original-authorized'); return 'original-authorized'
            def job_record(self): order.append('original-job-record')
            def complete(self): order.append('original-complete'); return 'original-complete'
        template = SimpleNamespace(CORE=pins['core'], SEED=pins['seed'], OLD_CORE=r.FAILED_CORE,
            OLD_SEED=r.FAILED_SEED, Context=Original, allocation_unconsumed=lambda *_args: True,
            quiet=Mock())
        previous = b'{"synthetic":"genuine published60 pending receipt"}\n'
        proof = {'failed_job_raw': r.canonical(job), 'failed_job': job,
                 'old_hold_unauthorized': True, 'previous_pending_raw': previous}
        verify = Mock(return_value=proof)
        transition = Mock(side_effect=lambda _context: order.append('hold-transition'))
        accepted = {'candidate_revision': '8' * 40, 'version': '0.4.4-preview.62',
                    'latest_required_ci_accepted': True, 'full_native_lifecycle_accepted': True}
        acceptance = Mock(return_value=accepted)
        verify_outer = Mock(return_value=True)
        bound = r.bind_general_context(template, pins, verify, transition, acceptance, verify_outer)
        context = bound(); context.base = SimpleNamespace(parse=json.loads)
        context.job = {'candidate': None,
            'audit': '/var/lib/go-erp-manual-updates/private/audits/warm-start-' + 'a' * 32}
        context.expected = pins['new_expected']
        context.state = lambda: copy.deepcopy(state)
        context.live = Mock(side_effect=lambda _state: order.append('live'))
        context.original_live = Mock(return_value={'synthetic': 'fresh complete original source scan'})
        context.e = MemoryIO(); context.ready = Mock(return_value=False)
        context.prior_hold = lambda optional=False: (r.canonical(r._hold(pins['old_expected'])), r._hold(pins['old_expected']))
        @contextmanager
        def watcher_lock(_work): yield lambda: True
        context.utility = SimpleNamespace(watcher_lock=watcher_lock)
        return SimpleNamespace(pins=pins, job=job, state=state, order=order, template=template,
            verify=verify, transition=transition, acceptance=acceptance, accepted=accepted,
            context=context, proof=proof, previous=previous, verify_outer=verify_outer)

    def test_original_prepare_and_allocator_tick_are_called_after_narrow_checks(self):
        f = self.fixture(); self.assertEqual(f.context.prepare('original-utility'), 'original-prepare')
        self.assertEqual(f.order, ['hold-transition', ('original-prepare', 'original-utility')])
        self.assertEqual(f.context.tick(True, 17), 'original-tick')
        self.assertEqual(f.order[-1], ('original-tick', True, 17))
        self.assertEqual(f.context.verify_predecessor(), r.canonical(f.job))
        f.context.quiet_hold()
        self.assertEqual(f.template.checked_published_state(f.state, None), f.state['desired'])

    def test_counter62_cannot_allocate_again_and_candidate61_cannot_be_accepted(self):
        f = self.fixture(); f.state['counter'] = 62; f.state['desired']['version'] = '0.4.4-preview.62'
        with self.assertRaisesRegex(r.Stop, 'ALREADY_CONSUMED'): f.context.tick(True)
        f.state['counter'] = 61; f.state['desired']['version'] = '0.4.4-preview.61'
        with self.assertRaisesRegex(r.Stop, 'ONE_SUCCESSOR_COUNTER_REQUIRED'): f.context.candidate(f.state)
        with self.assertRaisesRegex(r.Stop, 'ONE_SUCCESSOR_COUNTER_REQUIRED'): f.context.tick(False)
        self.assertEqual(f.order, [])

    def test_full_workspace_acceptance_precedes_original_authorization(self):
        f = self.fixture(); f.state['counter'] = 62
        f.state['desired'].update(commit='8' * 40, version='0.4.4-preview.62')
        self.assertEqual(f.context.authorized(), 'original-authorized')
        self.assertEqual(f.order, ['live', 'original-candidate', 'original-authorized'])
        f.acceptance.assert_called_once_with(f.context, f.state['desired'])

    def test_missing_full_acceptance_cannot_enter_original_authorization(self):
        for key in ('candidate_revision', 'version', 'latest_required_ci_accepted', 'full_native_lifecycle_accepted'):
            f = self.fixture(); f.state['counter'] = 62
            f.state['desired'].update(commit='8' * 40, version='0.4.4-preview.62')
            del f.accepted[key]
            with self.subTest(key=key), self.assertRaisesRegex(r.Stop, 'FULL_WORKSPACE_ACCEPTANCE_REQUIRED'):
                f.context.authorized()
            self.assertNotIn('original-authorized', f.order)

    def test_original_job_record_keeps_previous_pending_receipt_as_a_before_image(self):
        f = self.fixture(); f.context.job_record()
        path = Path(f.context.job['audit']) / 'previous-pending-acceptance.json'
        self.assertEqual(f.order, ['original-job-record'])
        self.assertEqual(f.context.e.files[path], f.previous)
        f.context.job_record(); self.assertEqual(f.context.e.writes, [path])
        f.context.e.files[path] = b'foreign pending'
        with self.assertRaisesRegex(r.Stop, 'PREVIOUS_PENDING_AUDIT_CHANGED'): f.context.job_record()
        self.assertEqual(f.context.e.files[path], b'foreign pending')

    def review_fixture(self):
        f = self.fixture()
        f.state['counter'] = 62
        f.state['last_remote_sha'] = '8' * 40
        f.state['desired'].update(commit='8' * 40, version='0.4.4-preview.62')
        f.context.job.update(candidate='8' * 40, version='0.4.4-preview.62',
                             prepare_started=True, baseline_counter=61, inventory=r.INVENTORY)
        accepted_run = Mock(return_value={'id': 42, 'run_attempt': 1})
        f.context.worker = SimpleNamespace(read_config=lambda: {'base_revision': f.pins['seed'], 'token': 'SYNTHETIC'},
            GitHub=Mock(return_value='synthetic-local-client'),
            WORKFLOWS={'first.yml': {}, 'second.yml': {}}, accepted_run=accepted_run)
        return f

    def test_same62_ci_review_requires_every_original_workflow_and_audits_transition(self):
        f = self.review_fixture(); before = copy.deepcopy(f.state)
        result = f.context.review_existing_ci()
        self.assertEqual(f.state, before)
        self.assertEqual(f.context.worker.accepted_run.call_count, 2)
        for call in f.context.worker.accepted_run.call_args_list:
            self.assertEqual(call.args[2], '8' * 40)
        self.assertEqual(result['desired']['status'], 'waiting-ci')
        self.assertEqual(result['desired']['retry_at'], 0)
        writes = f.context.e.writes
        self.assertEqual(writes[-1], r.WORK / 'state/current.json')
        self.assertEqual(len(writes), 3)
        self.assertTrue(writes[0].name.startswith('successor-ci-state-'))
        self.assertTrue(writes[1].name.startswith('successor-ci-review-'))
        self.assertEqual(json.loads(f.context.e.files[writes[0]]), before)

    def test_failed_or_wrong_candidate_ci_review_cannot_write_state(self):
        for mutation in ('not_accepted', 'failed61', 'counter63', 'job_counter', 'config'):
            f = self.review_fixture()
            if mutation == 'not_accepted': f.context.worker.accepted_run.return_value = None
            elif mutation == 'failed61': f.state['desired']['commit'] = r.FAILED_CANDIDATE
            elif mutation == 'counter63': f.state['counter'] = 63
            elif mutation == 'job_counter': f.context.job['baseline_counter'] = 60
            else: f.context.worker.read_config = lambda: {'base_revision': r.FAILED_SEED}
            with self.subTest(mutation=mutation), self.assertRaises(r.Stop): f.context.review_existing_ci()
            self.assertEqual(f.context.e.writes, [])

    def test_complete_keeps_old60_pending_receipt_and_calls_original_complete(self):
        f = self.review_fixture(); f.state['desired']['status'] = 'waiting-ci'
        f.context.e.files[r.PENDING] = f.previous
        path = Path(f.context.job['audit']) / 'previous-pending-acceptance.json'
        f.context.e.files[path] = f.previous
        self.assertEqual(f.context.complete(), 'original-complete')
        self.assertEqual(f.order, ['original-complete'])
        f.context.e.files[r.PENDING] = b'{"synthetic":"foreign pending"}'
        with self.assertRaisesRegex(r.Stop, 'PENDING_ACCEPTANCE_CHANGED'): f.context.complete()

    def test_complete_verifies_retained62_receipt_through_full_authorization(self):
        f = self.review_fixture(); f.state['desired']['status'] = 'waiting-ci'
        receipt = {**f.context.expected, 'candidate_revision': '8' * 40, 'version': '0.4.4-preview.62'}
        f.context.e.files[r.PENDING] = r.canonical(receipt)
        self.assertEqual(f.context.complete(), 'original-complete')
        self.assertEqual(f.order, ['live', 'original-candidate', 'original-authorized', 'original-complete'])
        receipt['version'] = '0.4.4-preview.61'; f.context.e.files[r.PENDING] = r.canonical(receipt)
        with self.assertRaisesRegex(r.Stop, 'RETAINED_ACCEPTANCE_BINDING_CHANGED'): f.context.complete()

    def test_one_exact_transient_retries_the_full_original_live_check(self):
        f = self.fixture(); del f.context.live
        f.context.original_live.side_effect = [RuntimeError('GO_ERP_LIVE_SOURCE_ANCESTRY_CHANGED'), {'fresh': 'second complete scan'}]
        with patch.object(r.time, 'sleep') as sleep:
            self.assertEqual(f.context.live(f.state), {'fresh': 'second complete scan'})
        self.assertEqual(f.context.original_live.call_count, 2)
        sleep.assert_called_once_with(0.25)
        self.assertGreaterEqual(f.verify_outer.call_count, 4)
        self.assertEqual(f.order, []); f.acceptance.assert_not_called()

    def test_three_exact_transients_propagate_without_allocator_or_publication(self):
        f = self.fixture(); del f.context.live
        error = RuntimeError('GO_ERP_LIVE_SOURCE_ANCESTRY_CHANGED')
        f.context.original_live.side_effect = error
        with patch.object(r.time, 'sleep') as sleep, self.assertRaises(RuntimeError) as caught:
            f.context.live(f.state)
        self.assertIs(caught.exception, error)
        self.assertEqual(f.context.original_live.call_count, 3)
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [0.25, 0.5])
        self.assertEqual(f.order, []); f.acceptance.assert_not_called()

    def test_any_other_live_error_or_lost_lock_is_immediate(self):
        for message in ('GO_ERP_LIVE_SOURCE_FILE_CHANGED', 'GO_ERP_LIVE_SOURCE_ANCESTRY_CHANGED extra'):
            f = self.fixture(); del f.context.live
            f.context.original_live.side_effect = RuntimeError(message)
            with patch.object(r.time, 'sleep') as sleep, self.assertRaises(RuntimeError): f.context.live(f.state)
            self.assertEqual(f.context.original_live.call_count, 1); sleep.assert_not_called()
            self.assertEqual(f.order, []); f.acceptance.assert_not_called()
        f = self.fixture(); del f.context.live; f.verify_outer.return_value = False
        with self.assertRaisesRegex(r.Stop, 'HELD_LOCK_CHANGED'): f.context.live(f.state)
        f.context.original_live.assert_not_called(); self.assertEqual(f.order, [])


if __name__ == '__main__': unittest.main()
