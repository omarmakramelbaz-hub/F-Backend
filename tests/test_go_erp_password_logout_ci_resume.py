"""A reviewed CI retry can only resume the already consumed preview.58."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from contextlib import contextmanager
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('password_logout_ci_resume_subject',
    ROOT / 'deployment/resume_go_erp_password_logout_ci_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)
from test_go_erp_password_logout_rollout import published_state
spec = importlib.util.spec_from_file_location('password_logout_original_operator',
    ROOT / 'deployment/upgrade_go_erp_password_logout_root.py')
operator = importlib.util.module_from_spec(spec); spec.loader.exec_module(operator)


def inputs():
    previous = published_state()
    state = copy.deepcopy(previous)
    state.update(base_revision=operator.SEED, counter=58, last_remote_sha=subject.CANDIDATE)
    state['desired'].update(commit=subject.CANDIDATE, version=subject.VERSION,
        candidatePolicy=operator.POLICY, inventorySha256=subject.INVENTORY,
        status='retry-build', failures=2, retry_at=1793000000)
    state['unknown_extension'] = {'keep': [1, {'two': 'three'}]}
    job = {'format': 1, 'kind': 'go-erp-warm-start-rollout', 'core': operator.CORE,
        'seed': operator.SEED, 'runtime': subject.RUNTIME, 'phase': 'running',
        'prepare_started': True, 'candidate': subject.CANDIDATE, 'version': subject.VERSION,
        'inventory': subject.INVENTORY, 'remote_lease': operator.INPUT_CANDIDATE,
        'baseline_candidate': operator.INPUT_CANDIDATE, 'baseline_remote': operator.INPUT_CANDIDATE,
        'baseline_counter': 57}
    return state, job, previous


class StateTests(unittest.TestCase):
    def test_review_changes_only_retry_status_and_time_not_counter_failures_or_history(self):
        state, job, previous = inputs(); before = copy.deepcopy(state)
        subject.checked_consumed_state(operator, state, job, previous)
        reviewed = subject.reviewed_state(state)
        before['desired']['status'] = 'waiting-ci'; before['desired']['retry_at'] = 0
        self.assertEqual(reviewed, before)
        self.assertEqual(state['desired']['status'], 'retry-build')
        self.assertEqual(reviewed['counter'], 58)
        self.assertEqual(reviewed['desired']['failures'], 2)
        self.assertEqual(reviewed['published_history'], previous['published_history'])

    def test_wrong_or_new_allocation_and_changed_publication_fail_closed(self):
        changes = [('counter', 59), ('counter', True), ('base_revision', operator.OLD_SEED),
            ('last_remote_sha', 'f' * 40), ('version_floor', subject.VERSION),
            ('sequence_floor', subject.RUNS['desktop-dashboard-tests.yml']), ('published_history', [])]
        for key, value in changes:
            state, job, previous = inputs(); state[key] = value
            with self.subTest(field=key), self.assertRaises(subject.Stop):
                subject.checked_consumed_state(operator, state, job, previous)
        for key, value in [('commit', 'f' * 40), ('version', '0.4.4-preview.59'),
            ('candidatePolicy', operator.OLD_POLICY), ('inventorySha256', 'e' * 64),
            ('pushed', False), ('status', 'unreviewed'), ('failures', True)]:
            state, job, previous = inputs(); state['desired'][key] = value
            with self.subTest(desired=key), self.assertRaises(subject.Stop):
                subject.checked_consumed_state(operator, state, job, previous)

    def test_missing_or_changed_latched_job_cannot_reenter_initial_allocation(self):
        for key, value in [('candidate', None), ('version', None), ('phase', 'preparing'),
            ('prepare_started', False), ('baseline_counter', 58), ('core', operator.OLD_CORE),
            ('seed', operator.OLD_SEED), ('runtime', operator.OLD_RUNTIME),
            ('baseline_remote', 'f' * 40), ('remote_lease', 'f' * 40)]:
            state, job, previous = inputs(); job[key] = value
            with self.subTest(job=key), self.assertRaises(subject.Stop):
                subject.checked_consumed_state(operator, state, job, previous)

    def test_published_58_is_idempotent_only_with_original_publication_and_history(self):
        state, job, previous = inputs()
        release = copy.deepcopy(previous['published']['release'])
        release.update(version=subject.VERSION, sequence=subject.RUNS['desktop-dashboard-tests.yml'],
            source_revision=subject.CANDIDATE)
        state['published'].update(commit=subject.CANDIDATE, inventorySha256=subject.INVENTORY, release=release)
        state['desired']['status'] = 'published'; state['version_floor'] = subject.VERSION
        state['sequence_floor'] = release['sequence']; state['published_history'].append(release)
        job['phase'] = 'ready'
        subject.checked_consumed_state(operator, state, job, previous)
        state['published_history'].insert(0, release)
        with self.assertRaisesRegex(subject.Stop, 'PASSWORD_LOGOUT_PUBLICATION_CHANGED'):
            subject.checked_consumed_state(operator, state, job, previous)

    def test_published_58_accepts_only_the_original_three_installer_pruning_rule(self):
        state, job, previous = inputs()
        older = [copy.deepcopy(previous['published']['release']) for _ in range(2)]
        for number, release in enumerate(older):
            release['installer']['sha256'] = str(number + 1) * 64
        previous['published_history'] = older + previous['published_history']
        release = copy.deepcopy(previous['published']['release'])
        release.update(version=subject.VERSION, sequence=subject.RUNS['desktop-dashboard-tests.yml'],
            source_revision=subject.CANDIDATE)
        release['installer']['sha256'] = '8' * 64
        state['published'].update(commit=subject.CANDIDATE, inventorySha256=subject.INVENTORY, release=release)
        state['desired']['status'] = 'published'; state['version_floor'] = subject.VERSION
        state['sequence_floor'] = release['sequence']
        state['published_history'] = previous['published_history'][-2:] + [release]
        job['phase'] = 'ready'
        subject.checked_consumed_state(operator, state, job, previous)
        state['published_history'].pop(0)
        with self.assertRaisesRegex(subject.Stop, 'PASSWORD_LOGOUT_PUBLICATION_CHANGED'):
            subject.checked_consumed_state(operator, state, job, previous)

    def test_recovery_import_is_bound_to_unchanged_original_operator_bytes(self):
        raw = subprocess.check_output(['git', 'show', subject.OPERATOR +
            ':deployment/upgrade_go_erp_password_logout_root.py'], cwd=ROOT)
        self.assertEqual(hashlib.sha256(raw).hexdigest(), subject.OPERATOR_SHA256)
        self.assertEqual(raw, (ROOT / 'deployment/upgrade_go_erp_password_logout_root.py').read_bytes())


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.package = self.root / 'go-erp-password-logout.I5UDqYlp'
        self.package.mkdir(mode=0o700)
        self.repository = self.package / 'release.git'; self.repository.mkdir(mode=0o700)
        self.apply = self.package / 'apply.py'
        self.apply.write_bytes((ROOT / 'deployment/upgrade_go_erp_password_logout_root.py').read_bytes())
        self.apply.chmod(0o600)

    def test_root_private_exact_retained_package_passes_without_git(self):
        with patch.object(subject.subprocess, 'run', side_effect=AssertionError('No Git before validation')):
            self.assertEqual(subject.checked_repository(self.repository, self.root), self.repository)

    def test_wrong_or_shared_package_and_modified_operator_fail_before_git(self):
        with patch.object(subject.subprocess, 'run', side_effect=AssertionError('No Git before validation')):
            with self.assertRaises(subject.Stop): subject.checked_repository(self.repository)
            self.package.chmod(0o755)
            with self.assertRaises(subject.Stop): subject.checked_repository(self.repository, self.root)
            self.package.chmod(0o700); self.repository.chmod(0o755)
            with self.assertRaises(subject.Stop): subject.checked_repository(self.repository, self.root)
            self.repository.chmod(0o700); self.apply.write_bytes(b'print("untrusted")')
            with self.assertRaisesRegex(subject.Stop, 'PIN_CHANGED'):
                subject.checked_repository(self.repository, self.root)

    def test_symlink_repository_is_rejected_before_any_git_invocation(self):
        self.repository.rmdir(); (self.package / 'other.git').mkdir(mode=0o700)
        self.repository.symlink_to('other.git', target_is_directory=True)
        with patch.object(subject.subprocess, 'run', side_effect=AssertionError('No Git before validation')):
            with self.assertRaises(subject.Stop): subject.checked_repository(self.repository, self.root)


class RecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.work = self.root / 'work'; self.work.mkdir()
        self.control = self.root / 'control'; self.audit = self.control / 'private/audits' / ('warm-start-' + 'a' * 32)
        self.audit.mkdir(parents=True)
        self.state, self.job, previous = inputs(); self.job['audit'] = str(self.audit)
        self.job_path = self.control / 'private/password-logout.json'
        self.current = self.work / 'state/current.json'; self.current.parent.mkdir()
        self.write(self.current, self.state); self.write(self.job_path, self.job)
        self.write(self.audit / 'previous-state.json', previous)
        self.events = []; self.writes = []
        outer = self
        @contextmanager
        def lock(work):
            outer.assertEqual(work, outer.work); outer.events.append('lock')
            try: yield
            finally: outer.events.append('unlock')
        class Original:
            def job_record(self):
                outer.events.append('original-job'); self.job = outer.job
            def prepare(self, utility):
                raise AssertionError('Original prepare/allocation must never run')
            def complete(self):
                outer.events.append('original-complete')
                return 'ORIGINAL_ACCEPTANCE_AND_PUBLICATION'
        self.template = types.SimpleNamespace(Context=Original, WORK=self.work,
            CONTROL=self.control, quiet=lambda engine: self.events.append('quiet'))
        self.ctx = subject.make_context(operator, self.template)()
        self.ctx.job_path = self.job_path; self.ctx.job = self.job
        self.ctx.base = types.SimpleNamespace(parse=lambda raw: json.loads(raw))
        self.ctx.e = types.SimpleNamespace(read_file=self.read, canonical=self.canonical,
            atomic_write=self.atomic)
        self.ctx.state = lambda: json.loads(self.current.read_bytes())
        self.ctx.live = lambda state: self.events.append('live')
        self.ctx.candidate = lambda state: self.events.append('candidate')
        self.ctx.ready = lambda state: state['desired']['status'] == 'published'
        self.ctx.utility = types.SimpleNamespace(watcher_lock=lock)
        self.worker = types.SimpleNamespace(read_config=lambda: {'base_revision': operator.SEED, 'token': 'private'},
            GitHub=lambda token: object(), accepted_run=self.accepted_run)
        self.ctx.worker = self.worker
        self.failed_workflow = None

    @staticmethod
    def canonical(value): return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
    def write(self, path, value): path.write_bytes(self.canonical(value))
    def read(self, path, *args, optional=False, **kwargs):
        if not path.exists() and optional: return None
        return path.read_bytes(), None
    def atomic(self, path, raw, uid, gid, mode):
        self.assertEqual((uid, gid, mode), (0, 0, 0o600)); self.writes.append(path)
        path.write_bytes(raw)
    def accepted_run(self, client, workflow, candidate):
        self.assertEqual(candidate, subject.CANDIDATE); self.events.append('ci:' + workflow)
        if workflow == self.failed_workflow: return None
        return {'id': subject.RUNS[workflow], 'run_attempt': 2}

    def test_no_journal_and_wrong_counter_fail_before_original_or_writes(self):
        self.job_path.unlink()
        with self.assertRaisesRegex(subject.Stop, 'EXISTING_PASSWORD_LOGOUT_JOURNAL_REQUIRED'):
            self.ctx.job_record()
        self.assertEqual(self.events, []); self.assertEqual(self.writes, [])
        self.write(self.job_path, self.job); changed = copy.deepcopy(self.state); changed['counter'] = 59
        self.write(self.current, changed)
        with self.assertRaises(subject.Stop): self.ctx.job_record()
        self.assertEqual(self.events, []); self.assertEqual(self.writes, [])

    def test_prepare_is_readonly_and_never_calls_original_allocator(self):
        expected = {'core': operator.CORE, 'inventory': subject.INVENTORY}
        self.ctx.expected = expected
        self.write(self.work / 'program-rollout-hold.json', {'format': 1,
            'kind': 'go-erp-program-rollout-hold', **expected, 'authorization': None})
        self.ctx.prepare(types.SimpleNamespace(verify=lambda: self.events.append('verify-lock')))
        self.assertEqual(self.writes, [])
        self.assertEqual(self.events, ['verify-lock', 'live', 'candidate', 'verify-lock'])

    def test_incomplete_ci_blocks_state_change_and_original_completion(self):
        self.failed_workflow = 'whatsapp-integrated-regressions.yml'
        before = self.current.read_bytes()
        with self.assertRaisesRegex(subject.Stop, 'PASSWORD_LOGOUT_SUCCESSFUL_ORIGINAL_CI_REQUIRED'):
            self.ctx.complete()
        self.assertEqual(self.current.read_bytes(), before)
        self.assertEqual(self.writes, []); self.assertNotIn('original-complete', self.events)

    def test_successful_original_ci_audited_before_status_only_change_then_original_gate(self):
        before = self.current.read_bytes()
        result = self.ctx.complete()
        self.assertEqual(result, 'ORIGINAL_ACCEPTANCE_AND_PUBLICATION')
        reviewed = json.loads(self.current.read_bytes())
        self.assertEqual(reviewed, subject.reviewed_state(json.loads(before)))
        self.assertEqual(self.writes[-1], self.current)
        audits = sorted(self.audit.glob('password-logout-ci-*'))
        self.assertEqual(len(audits), 2)
        record = json.loads(next(path for path in audits if '-review-' in path.name).read_bytes())
        self.assertEqual(record['before_state_sha256'], subject.sha(before))
        self.assertEqual(record['runs'], {key: {'id': value, 'attempt': 2} for key, value in subject.RUNS.items()})
        self.assertLess(self.events.index('unlock'), self.events.index('original-complete'))
        self.assertEqual(sum(event.startswith('ci:') for event in self.events), 3)
        self.assertEqual(reviewed['desired']['failures'], 2)
        self.assertEqual(reviewed['counter'], 58)

    def test_live_source_or_concurrent_state_change_fails_before_state_write(self):
        def changed_live(state):
            if any(event.startswith('ci:') for event in self.events): raise RuntimeError('source changed')
            self.events.append('live')
        self.ctx.live = changed_live
        with self.assertRaisesRegex(RuntimeError, 'source changed'): self.ctx.complete()
        self.assertEqual(self.writes, []); self.assertNotIn('original-complete', self.events)

    def test_already_reviewed_pending_state_reuses_original_gate_without_rewriting(self):
        self.write(self.current, subject.reviewed_state(self.state))
        self.assertEqual(self.ctx.complete(), 'ORIGINAL_ACCEPTANCE_AND_PUBLICATION')
        self.assertEqual(self.writes, []); self.assertFalse(any(event.startswith('ci:') for event in self.events))


if __name__ == '__main__':
    unittest.main()
