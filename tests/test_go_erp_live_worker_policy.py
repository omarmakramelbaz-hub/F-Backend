"""Exercise durable worker transitions with real isolated source/Git candidates.

GitHub is represented by the existing empty-CI transport fixture. Preparation,
version floors, private candidate retirement, source scans and durable JSON use
the production implementations; no live service or repository is contacted.
"""
import copy
import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import patch


def load_fixture():
    path = Path(__file__).with_name('test_go_erp_live_updates.py')
    spec = importlib.util.spec_from_file_location('go_erp_policy_worker_fixtures', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


fixtures = load_fixture()
worker, source, publisher = fixtures.worker, fixtures.source, fixtures.publisher


@unittest.skipUnless(os.geteuid() == 0, 'isolated root-owned worker transition contract')
class CandidatePolicyTransitions(unittest.TestCase):
    def setUp(self):
        self.fixture = fixtures.RootWorkerTests(methodName='runTest')
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.value = self.fixture.make_worker()

    def first_candidate(self):
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = self.value.state['desired']
        self.value.client.json = lambda path, missing=False: {'object': {'sha': self.value.state['last_remote_sha']}}
        return desired

    def published(self, desired, write_feed=False):
        release = publisher.validate_receipt(self.fixture.receipt(
            desired['version'], sequence=500, revision=desired['commit']))
        self.value.state['published'] = {
            'inventorySha256': desired['inventorySha256'], 'commit': desired['commit'],
            'run_id': 500, 'run_attempt': 1, 'release': release}
        self.value.state['sequence_floor'] = 500
        self.value.state['version_floor'] = desired['version']
        self.value.state['published_history'] = [copy.deepcopy(release)]
        if write_feed:
            self.fixture.write('storage/app/desktop-updates/current.json', release)
        return release

    def assert_migrates_once(self, status, policy=None):
        old = self.first_candidate()
        if policy is None:
            old.pop('candidatePolicy')
        else:
            old['candidatePolicy'] = policy
        old.update(status=status, failures=5, retry_at=self.value.clock() + 3600)
        self.value.state.update(counter=90, version_floor='0.4.4-preview.90', sequence_floor=777)
        history = [publisher.validate_receipt(self.fixture.receipt('0.4.4-preview.90', 777))]
        self.value.state['published_history'] = copy.deepcopy(history)
        inventory = source.scan_snapshot(self.fixture.live, self.value.checkout, 1001,
                                         self.value.state['observed'])['metadata']['inventorySha256']
        original_config = copy.deepcopy(self.value.config)
        dependency_guard = copy.deepcopy(self.value.state['dependency_guard'])
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        current = self.value.state['desired']
        self.assertNotEqual(current['commit'], old['commit'])
        self.assertEqual(current['candidatePolicy'], worker.CANDIDATE_POLICY)
        self.assertEqual(current['inventorySha256'], inventory)
        self.assertEqual(current['version'], '0.4.4-preview.91')
        self.assertEqual(self.value.state['counter'], 91)
        self.assertEqual(current['failures'], 0, 'the reviewed policy creates a new normal candidate')
        self.assertEqual(self.value.state['sequence_floor'], 777)
        self.assertEqual(self.value.state['version_floor'], '0.4.4-preview.90')
        self.assertEqual(self.value.state['published_history'], history)
        self.assertEqual(self.value.state['dependency_guard'], dependency_guard)
        self.assertEqual(self.value.config, original_config)
        self.assertFalse(Path(old['candidatePath']).exists())
        self.assertTrue(current['pushed'])
        self.assertEqual(current['status'], 'waiting-ci')
        self.assertEqual(len(self.fixture.pushes), 2)
        self.assertEqual(self.fixture.pushes[-1][2], old['commit'], 'remote lease remains bound to previous candidate')
        first_commit = current['commit']
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(self.value.state['desired']['commit'], first_commit)
        self.assertEqual(self.value.state['counter'], 91)
        self.assertEqual(len(self.fixture.pushes), 2)
        saved = worker.parse_json(self.value.state_file.read_bytes())
        self.assertEqual(saved['desired']['candidatePolicy'], worker.CANDIDATE_POLICY)
        self.assertEqual(saved['desired']['inventorySha256'], inventory)
        self.assertNotIn(original_config['token'], self.value.state_file.read_text())

    def test_blocked_old_policy_rebuilds_once_before_old_retry_and_block_gates(self):
        self.assert_migrates_once('blocked')

    def test_waiting_old_policy_rebuilds_once(self):
        self.assert_migrates_once('waiting-ci')

    def test_prepared_old_policy_rebuilds_once(self):
        self.value.check_dependencies()
        original = self.value.prepare(self.value.scan())
        original.pop('candidatePolicy')
        original_path = Path(original['candidatePath'])
        self.assertTrue(original_path.exists())
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertFalse(original_path.exists(), 'private old-policy preparation is retired normally')
        self.assertNotEqual(self.value.state['desired']['commit'], original['commit'])
        self.assertEqual(self.value.state['desired']['candidatePolicy'], worker.CANDIDATE_POLICY)
        self.assertEqual(self.value.state['counter'], 8)
        self.assertEqual(len(self.fixture.pushes), 1)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(self.value.state['counter'], 8)

    def test_explicit_different_policy_rebuilds_once(self):
        self.assert_migrates_once('blocked', policy='prior-reviewed-policy')

    def test_matching_accepted_feed_stays_current_without_policy_only_rebuild(self):
        desired = self.first_candidate()
        self.published(desired, write_feed=True)
        desired.pop('candidatePolicy')
        desired['status'] = 'published'
        before = copy.deepcopy(self.value.state)
        with patch.object(self.value, 'prepare', side_effect=AssertionError('unnecessary compiler rebuild')):
            self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_CURRENT')
        self.assertEqual(self.value.state['counter'], before['counter'])
        self.assertEqual(self.value.state['desired'], before['desired'])
        self.assertEqual(self.value.state['published'], before['published'])
        self.assertEqual(len(self.fixture.pushes), 1)

    def test_missing_feed_creates_only_one_waiting_replacement_across_minute_ticks(self):
        original = self.first_candidate()
        release = self.published(original)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        replacement = copy.deepcopy(self.value.state['desired'])
        self.assertNotEqual(replacement['commit'], original['commit'])
        for _ in range(3):
            self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
            self.assertEqual(self.value.state['desired'], replacement)
        self.assertEqual(self.value.state['counter'], 8)
        self.assertEqual(len(self.fixture.pushes), 2)
        self.assertEqual(self.value.state['published']['release'], release)
        self.assertEqual(self.value.state['sequence_floor'], 500)
        self.assertEqual(self.value.state['published_history'], [release])

    def test_mismatched_feed_creates_only_one_replacement_and_retains_high_water_marks(self):
        original = self.first_candidate()
        self.published(original)
        newer = publisher.validate_receipt(self.fixture.receipt('0.4.4-preview.20', sequence=900))
        self.fixture.write('storage/app/desktop-updates/current.json', newer)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        replacement = copy.deepcopy(self.value.state['desired'])
        self.assertEqual(replacement['version'], '0.4.4-preview.21')
        self.assertEqual(self.value.state['sequence_floor'], 900)
        self.assertEqual(self.value.state['version_floor'], '0.4.4-preview.20')
        self.assertFalse((self.fixture.live / 'storage/app/desktop-updates/current.json').exists())
        for _ in range(3):
            self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
            self.assertEqual(self.value.state['desired'], replacement)
        self.assertEqual(self.value.state['counter'], 21)
        self.assertEqual(len(self.fixture.pushes), 2)

    def test_restart_retains_persisted_waiting_replacement_with_missing_feed(self):
        original = self.first_candidate()
        self.published(original)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        before = copy.deepcopy(self.value.state)
        restarted = worker.Worker(self.value.config, source, publisher, client=self.value.client,
                                  work=self.fixture.work, live=self.fixture.live, web_uid=1001)
        restarted.git.push = lambda checkout, commit, previous: self.fixture.pushes.append((checkout, commit, previous))
        self.assertEqual(restarted.state, before)
        self.assertEqual(restarted.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(restarted.state['desired'], before['desired'])
        self.assertEqual(restarted.state['published'], before['published'])
        self.assertEqual(restarted.state['counter'], 8)
        self.assertEqual(len(self.fixture.pushes), 2)

    def test_active_recovery_backoff_is_retained_instead_of_version_churn(self):
        original = self.first_candidate()
        self.published(original)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = self.value.state['desired']
        desired.update(failures=2, retry_at=self.value.clock() + 3600)
        before = copy.deepcopy(desired)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_RETRY_DEFERRED')
        self.assertEqual(self.value.state['desired'], before)
        self.assertEqual(self.value.state['counter'], 8)
        self.assertEqual(len(self.fixture.pushes), 2)

    def test_active_recovery_ci_block_is_retained_for_review(self):
        original = self.first_candidate()
        self.published(original)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        desired = self.value.state['desired']
        desired.update(status='blocked', failures=3, retry_at=0)
        before = copy.deepcopy(desired)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED')
        self.assertEqual(self.value.state['desired'], before)
        self.assertEqual(self.value.state['counter'], 8)
        self.assertEqual(len(self.fixture.pushes), 2)

    def test_live_change_supersedes_existing_recovery_and_binds_actual_new_inventory(self):
        original = self.first_candidate()
        self.published(original)
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        replacement = copy.deepcopy(self.value.state['desired'])
        self.fixture.write('public/css/site.css', b'body {color: green;}\n')
        self.assertEqual(self.value.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        current = self.value.state['desired']
        actual = source.scan_snapshot(self.fixture.live, self.value.checkout, 1001,
                                      self.value.state['observed'])['metadata']['inventorySha256']
        self.assertNotEqual(current['commit'], replacement['commit'])
        self.assertNotEqual(current['inventorySha256'], replacement['inventorySha256'])
        self.assertEqual(current['inventorySha256'], actual)
        self.assertEqual(current['candidatePolicy'], worker.CANDIDATE_POLICY)
        self.assertEqual(self.value.state['counter'], 9)

    def test_unreviewed_loaded_source_policy_is_rejected_without_remote_write(self):
        with patch.object(source, 'CANDIDATE_POLICY', 'unreviewed-compiler'):
            with self.assertRaisesRegex(worker.WatchError, 'CANDIDATE_POLICY_UNREVIEWED'):
                self.value.tick()
        self.assertIsNone(self.value.state['desired'])
        self.assertEqual(self.fixture.pushes, [])

    def test_preparation_cannot_bind_an_unreviewed_result_policy(self):
        prepare = source.prepare_candidate
        def wrong_policy(*args, **kwargs):
            result = prepare(*args, **kwargs)
            result['candidatePolicy'] = 'different-policy'
            return result
        with patch.object(source, 'prepare_candidate', side_effect=wrong_policy):
            with self.assertRaisesRegex(worker.WatchError, 'CANDIDATE_INVALID'):
                self.value.tick()
        self.assertIsNone(self.value.state['desired'])
        self.assertEqual(self.fixture.pushes, [])
        self.assertEqual(list((self.fixture.work / 'staging').iterdir()), [])
        self.assertEqual(self.value.state['counter'], 7)


if __name__ == '__main__':
    unittest.main()
