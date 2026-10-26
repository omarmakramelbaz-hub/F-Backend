"""A retry resumes the reviewed .59 candidate without retiring or reallocating it."""
import copy
import json
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

from test_go_erp_navigation_rollout import subject, published_state, TEST_CORE, TEST_SEED


class SameCandidateCIReviewTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.work = self.root / 'work'; self.work.mkdir()
        self.audit = self.root / 'audit'; self.audit.mkdir(mode=0o700)
        (self.work / 'state').mkdir()
        self.path = self.work / 'state/current.json'
        self.value = published_state()
        self.value.update(base_revision=TEST_SEED, counter=59, last_remote_sha='1' * 40)
        self.value['desired'].update(commit='1' * 40, version='0.4.4-preview.59',
            candidatePolicy=subject.POLICY, inventorySha256='e' * 64, pushed=True,
            status='retry-build', failures=3, retry_at=1793000000)
        self.value['keep_extension'] = {'future': [1, 2, 3]}
        self.write(self.path, self.value)
        self.job = {'candidate': '1' * 40, 'version': '0.4.4-preview.59', 'inventory': 'e' * 64,
            'prepare_started': True, 'baseline_counter': 58, 'audit': str(self.audit)}
        self.events = []; self.writes = []; self.failed = None; self.change_on_ci = False
        self.template = types.SimpleNamespace(CORE=TEST_CORE, SEED=TEST_SEED, WORK=self.work)
        self.ctx = types.SimpleNamespace(job=self.job, state=lambda: json.loads(self.path.read_bytes()),
            live=lambda state: self.events.append('source'), candidate=lambda state: self.events.append('candidate'),
            e=types.SimpleNamespace(canonical=self.canonical, read_file=self.read, atomic_write=self.atomic),
            worker=types.SimpleNamespace(read_config=lambda: {'base_revision': TEST_SEED, 'token': 'PRIVATE_TEST_TOKEN'},
                GitHub=lambda token: object(), accepted_run=self.accepted_run))

    @staticmethod
    def canonical(value):
        return json.dumps(value, sort_keys=True, separators=(',', ':')).encode() + b'\n'

    def write(self, path, value):
        path.write_bytes(self.canonical(value))

    def read(self, path, *args, optional=False, **kwargs):
        if not path.exists() and optional: return None
        return path.read_bytes(), None

    def atomic(self, path, raw, uid, gid, mode):
        self.assertEqual((uid, gid, mode), (0, 0, 0o600))
        self.writes.append(path); path.write_bytes(raw)

    def accepted_run(self, client, workflow, revision):
        self.assertEqual(revision, self.job['candidate']); self.events.append('ci:' + workflow)
        if self.change_on_ci:
            self.change_on_ci = False
            changed = copy.deepcopy(self.value); changed['counter'] = 60; self.write(self.path, changed)
        if workflow == self.failed: return None
        return {'id': 100 + len(self.events), 'run_attempt': 2}

    def review(self):
        return subject.review_existing_ci(self.ctx, self.template, self.value)

    def test_original_all_three_ci_success_is_audited_before_only_retry_state_changes(self):
        before = copy.deepcopy(self.value)
        reviewed = self.review()
        wanted = copy.deepcopy(before); wanted['desired']['status'] = 'waiting-ci'; wanted['desired']['retry_at'] = 0
        self.assertEqual(reviewed, wanted)
        self.assertEqual(json.loads(self.path.read_bytes()), wanted)
        self.assertEqual(self.value, before)
        self.assertEqual(self.writes[-1], self.path)
        self.assertEqual(len(self.writes), 3)
        self.assertEqual(len([event for event in self.events if event.startswith('ci:')]), 3)
        record = json.loads(next(self.audit.glob('navigation-ci-review-*.json')).read_bytes())
        self.assertEqual(record['candidate'], self.job['candidate'])
        self.assertEqual(record['before_state_sha256'], subject.sha(self.canonical(before)))
        self.assertEqual(set(record['runs']), {'desktop-dashboard-tests.yml', 'go-erp-update-tests.yml', 'whatsapp-integrated-regressions.yml'})
        for key in ('counter', 'published', 'published_history', 'version_floor', 'sequence_floor', 'last_remote_sha', 'keep_extension'):
            self.assertEqual(reviewed[key], before[key])
        self.assertEqual(reviewed['desired']['failures'], 3)

    def test_missing_candidate_or_an_extra_reservation_never_enters_original_ci_or_writes(self):
        changes = [('counter', 60), ('counter', True), ('last_remote_sha', 'f' * 40)]
        for key, value in changes:
            before = copy.deepcopy(self.value); self.value[key] = value
            with self.subTest(field=key), self.assertRaises(subject.Stop): self.review()
            self.value = before
        for key, value in [('candidate', None), ('baseline_counter', 59), ('baseline_counter', True),
                ('prepare_started', False), ('version', '0.4.4-preview.60')]:
            before = copy.deepcopy(self.job); self.job[key] = value
            with self.subTest(job=key), self.assertRaises(subject.Stop): self.review()
            self.job.clear(); self.job.update(before)
        self.assertEqual(self.events, []); self.assertEqual(self.writes, [])

    def test_incomplete_original_ci_does_not_reset_failed_state(self):
        self.failed = 'whatsapp-integrated-regressions.yml'; before = self.path.read_bytes()
        with self.assertRaisesRegex(subject.Stop, 'SAME_CANDIDATE_SUCCESSFUL_CI_REQUIRED'): self.review()
        self.assertEqual(self.path.read_bytes(), before); self.assertEqual(self.writes, [])

    def test_original_ci_exception_is_not_hidden_or_downgraded(self):
        class OriginalCIError(Exception): pass
        def failed(*args): raise OriginalCIError('GO_ERP_LIVE_CI_FAILED')
        self.ctx.worker.accepted_run = failed
        with self.assertRaisesRegex(OriginalCIError, 'GO_ERP_LIVE_CI_FAILED'): self.review()
        self.assertEqual(self.writes, [])

    def test_changed_source_or_state_after_network_review_never_overwrites_it(self):
        self.change_on_ci = True
        with self.assertRaisesRegex(subject.Stop, 'STATE_CHANGED_DURING_CI_REVIEW'): self.review()
        self.assertEqual(json.loads(self.path.read_bytes())['counter'], 60)
        self.assertEqual(self.writes, [])

    def test_current_waiting_or_published_state_is_not_rewritten_or_reauthorized(self):
        for status in ('prepared', 'waiting-ci', 'published'):
            self.value['desired']['status'] = status
            self.assertIs(self.review(), self.value)
        self.assertEqual(self.events, []); self.assertEqual(self.writes, [])

    def test_pending_release_pins_stop_before_template_loading_or_external_operations(self):
        with patch.object(subject, 'PINS_PENDING', True), \
                patch.object(subject.os, 'geteuid', return_value=0), patch.object(subject.socket, 'gethostname', return_value='srv1836524'), \
                patch.object(subject.sys, 'argv', ['upgrade', '--git-dir', '/unread/repository', '--release', 'a' * 40, '--complete']), \
                patch.object(subject, 'read_template', side_effect=AssertionError('Must not read pending package')):
            with self.assertRaisesRegex(subject.Stop, 'NATIVE_PINS_PENDING'): subject.main()


if __name__ == '__main__':
    unittest.main()
