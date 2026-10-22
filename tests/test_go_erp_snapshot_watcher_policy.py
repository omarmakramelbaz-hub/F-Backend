#!/usr/bin/env python3
"""Exact snapshot-transport seed authority and private producer/worker agreement."""
from contextlib import contextmanager
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import types
import unittest
from unittest import mock


REPO = Path(__file__).resolve().parents[1]
PAYLOAD = REPO / 'deployment/snapshot-watcher'
PREVIOUS = REPO / 'deployment/capacity-watcher'
TRANSPORT_SEED = '52f7f5d22e5a2026e5bd5732bbb7893125b6ef9d'
ROSTER = ('go_erp_live_source.py', 'go_erp_live_updates.py',
          'publish_go_erp_update.py', 'go_erp_current_candidate_ci.yml',
          'verify_go_erp_current_candidate_ci.py')


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


source = module('snapshot_transport_watcher_source', PAYLOAD / 'go_erp_live_source.py')
worker = module('snapshot_transport_watcher_worker', PAYLOAD / 'go_erp_live_updates.py')
previous_source = module('snapshot_transport_previous_source', PREVIOUS / 'go_erp_live_source.py')


class SnapshotPayloadTests(unittest.TestCase):
    def test_only_source_and_worker_change_from_the_capacity_payload(self):
        self.assertEqual(sorted(path.name for path in PAYLOAD.iterdir() if path.is_file()), sorted(ROSTER))
        changed = [name for name in ROSTER
                   if (PAYLOAD / name).read_bytes() != (PREVIOUS / name).read_bytes()]
        self.assertEqual(changed, ['go_erp_live_source.py', 'go_erp_live_updates.py'])
        for name in ROSTER[2:]:
            self.assertEqual((PAYLOAD / name).read_bytes(), (PREVIOUS / name).read_bytes())

    def test_new_seed_is_one_exact_reviewed_revision_with_matching_policy(self):
        self.assertRegex(source.TRANSPORT_SEED, r'\A[a-f0-9]{40}\Z')
        self.assertEqual(source.TRANSPORT_SEED, TRANSPORT_SEED)
        self.assertEqual(source.TRANSPORT_SEED, worker.TRANSPORT_SEED)
        self.assertNotEqual(source.TRANSPORT_SEED, source.CAPACITY_SEED)
        self.assertEqual(source.TRANSPORT_POLICY, worker.TRANSPORT_POLICY)
        self.assertEqual(source.TRANSPORT_POLICY, source.CANDIDATE_POLICY + '-snapshot-transport-seed-v1')
        self.assertNotEqual(source.TRANSPORT_POLICY, source.CAPACITY_POLICY)

    def test_exact_transport_seed_requires_the_matching_reviewed_producer(self):
        controller = types.SimpleNamespace(config={'base_revision': source.TRANSPORT_SEED}, source=source)
        self.assertEqual(worker.Worker.candidate_policy(controller), source.TRANSPORT_POLICY)
        controller.source = previous_source
        with self.assertRaisesRegex(worker.WatchError, 'TRANSPORT_POLICY_UNREVIEWED'):
            worker.Worker.candidate_policy(controller)
        for seed, policy in ((source.CAPACITY_SEED, source.TRANSPORT_POLICY),
                             (source.TRANSPORT_SEED, source.CANDIDATE_POLICY)):
            controller.source = types.SimpleNamespace(CANDIDATE_POLICY=source.CANDIDATE_POLICY,
                                                     TRANSPORT_SEED=seed, TRANSPORT_POLICY=policy)
            with self.subTest(seed=seed, policy=policy):
                with self.assertRaisesRegex(worker.WatchError, 'TRANSPORT_POLICY_UNREVIEWED'):
                    worker.Worker.candidate_policy(controller)

    def test_capacity_and_other_historical_worker_policies_remain_unchanged(self):
        self.assertEqual(source.CAPACITY_SEED, '7523ff6888a6e3d0a6f5b7712c553909f12c043b')
        self.assertEqual(source.CAPACITY_SEED, worker.CAPACITY_SEED)
        self.assertEqual(source.CAPACITY_POLICY, previous_source.CAPACITY_POLICY)
        self.assertEqual(source.CANDIDATE_POLICY, previous_source.CANDIDATE_POLICY)
        controller = types.SimpleNamespace(config={'base_revision': source.CAPACITY_SEED}, source=previous_source)
        self.assertEqual(worker.Worker.candidate_policy(controller), source.CAPACITY_POLICY)
        controller.source = types.SimpleNamespace(CANDIDATE_POLICY=source.CANDIDATE_POLICY)
        with self.assertRaisesRegex(worker.WatchError, 'CAPACITY_POLICY_UNREVIEWED'):
            worker.Worker.candidate_policy(controller)
        controller.config['base_revision'] = 'f' * 40
        self.assertNotIn(controller.config['base_revision'], (source.CAPACITY_SEED, source.TRANSPORT_SEED))
        self.assertEqual(worker.Worker.candidate_policy(controller), source.CANDIDATE_POLICY)


@unittest.skipUnless(os.geteuid() == 0, 'Source authority fixture requires root.')
class SnapshotCandidateTests(unittest.TestCase):
    @contextmanager
    def candidate_fixture(self, historical=False):
        with tempfile.TemporaryDirectory(prefix='go-erp-snapshot-policy-', dir='/var/tmp') as name:
            base = Path(name)
            seed, live, checkout = (base / value for value in ('seed', 'live', 'candidate'))
            seed.mkdir(mode=0o700)
            for file in source.CRITICAL:
                target = seed / file
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(b'<?php // private source fixture\n')
            extras = {
                '.github/workflows/go-erp-update-tests.yml': b'run: independent-snapshot-transport-proof\r\n',
                'deployment/verify_go_erp_media_capacity_seed.py': b'# retained independent capacity proof\n',
                'desktop-pos/src/dashboard-download.cjs': b'// exact seed-owned raw snapshot bytes\r\nconst inputs = new WeakMap();\r\n',
                'desktop-pos/src/local-runtime.cjs': b'// exact seed-owned native import path\nconst input = readSnapshot.input(snapshot);\n',
                'desktop-pos/test/snapshot-transport.test.cjs': b'// independent PHP to JavaScript to PHP wire regression\n',
                'desktop-pos/package.json': json.dumps({'version': '0.4.4-preview.25'}).encode(),
                'desktop-pos/package-lock.json': json.dumps({'version': '0.4.4-preview.25', 'packages': {'': {'version': '0.4.4-preview.25'}}}).encode(),
            }
            if historical:
                extras.update(source._policy_assets())
            for file, data in extras.items():
                target = seed / file
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)

            def git(*args, root=seed):
                return subprocess.check_output(['git', '-c', 'safe.directory=' + str(root),
                                                '-c', 'core.autocrlf=false', '-c', 'core.hooksPath=/dev/null',
                                                '-c', 'commit.gpgsign=false', '-C', str(root), *args], stderr=subprocess.PIPE)

            git('init', '--quiet', '--template=')
            git('remote', 'add', 'origin', source.REMOTE_URL)
            git('add', '.')
            git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                'commit', '--quiet', '-m', 'reviewed private snapshot transport seed fixture')
            revision = git('rev-parse', 'HEAD').decode().strip()
            live.mkdir(mode=0o700)
            for file in source.CRITICAL:
                target = live / file
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(seed / file, target)
            subprocess.run(['git', '-c', 'core.autocrlf=false', 'clone', '--quiet', '--no-local',
                            '--no-hardlinks', str(seed), str(checkout)], check=True)
            git('remote', 'set-url', 'origin', source.REMOTE_URL, root=checkout)
            yield seed, live, checkout, revision, extras, git

    def assert_retained_seed_bytes(self, checkout, revision, extras, git):
        for file, data in extras.items():
            if file.endswith(('package.json', 'package-lock.json')):
                continue
            self.assertEqual((checkout / file).read_bytes(), data, file)
            self.assertEqual(git('show', 'HEAD:' + file, root=checkout), data, file)
        self.assertEqual(git('rev-parse', 'HEAD^', root=checkout).decode().strip(), revision)
        self.assertEqual(git('diff', '--name-only', revision, 'HEAD', root=checkout).decode().splitlines(),
                         ['deployment/go_erp_live_source_candidate.json',
                          'desktop-pos/package-lock.json', 'desktop-pos/package.json'])

    def test_exact_transport_seed_retains_raw_ci_and_fixed_native_bytes_and_reuses_candidate(self):
        with self.candidate_fixture() as (seed, live, checkout, revision, extras, git):
            with mock.patch.object(source, 'TRANSPORT_SEED', revision):
                snapshot = source.scan_snapshot(live, seed, 0)
                with mock.patch.object(source, '_policy_assets', side_effect=AssertionError('Historical CI must not replace the reviewed transport seed')):
                    result = source.prepare_candidate(checkout, snapshot, 27)
                    repeated = source.prepare_candidate(checkout, snapshot, 27)
                self.assertEqual(result['candidatePolicy'], source.TRANSPORT_POLICY)
                self.assertEqual(result['commit'], repeated['commit'])
                self.assertFalse(result['reused'])
                self.assertTrue(repeated['reused'])
                with mock.patch.object(worker, 'TRANSPORT_SEED', revision):
                    controller = types.SimpleNamespace(config={'base_revision': revision}, source=source)
                    self.assertEqual(worker.Worker.candidate_policy(controller), result['candidatePolicy'])
            self.assert_retained_seed_bytes(checkout, revision, extras, git)

            # A later commit is not covered by the reviewed seed exception.
            later = seed / 'desktop-pos/src/dashboard-download.cjs'
            later.write_bytes(later.read_bytes() + b'// unreviewed next seed\n')
            git('add', 'desktop-pos/src/dashboard-download.cjs')
            git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                'commit', '--quiet', '-m', 'unreviewed next seed')
            changed = source.scan_snapshot(live, seed, 0)
            self.assertNotEqual(changed['metadata']['seedRevision'], revision)
            with mock.patch.object(source, 'TRANSPORT_SEED', revision):
                with mock.patch.object(worker, 'TRANSPORT_SEED', revision):
                    controller = types.SimpleNamespace(config={'base_revision': changed['metadata']['seedRevision']}, source=source)
                    self.assertEqual(worker.Worker.candidate_policy(controller), source.CANDIDATE_POLICY)
                with mock.patch.object(source, '_policy_assets', side_effect=RuntimeError('original pinned historical gate')) as gate:
                    with self.assertRaisesRegex(RuntimeError, 'original pinned historical gate'):
                        source.prepare_candidate(checkout, changed, 28)
                    gate.assert_called_once_with()
            self.assertEqual(git('rev-parse', 'HEAD', root=checkout).decode().strip(), result['commit'])

    def test_exact_capacity_seed_still_retains_its_original_independent_gate(self):
        with self.candidate_fixture() as (seed, live, checkout, revision, extras, git):
            with mock.patch.object(source, 'CAPACITY_SEED', revision):
                snapshot = source.scan_snapshot(live, seed, 0)
                with mock.patch.object(source, '_policy_assets', side_effect=AssertionError('Capacity seed behavior must remain unchanged')):
                    result = source.prepare_candidate(checkout, snapshot, 27)
                    repeated = source.prepare_candidate(checkout, snapshot, 27)
                self.assertEqual(result['candidatePolicy'], source.CAPACITY_POLICY)
                self.assertEqual(result['commit'], repeated['commit'])
                self.assertTrue(repeated['reused'])
            self.assert_retained_seed_bytes(checkout, revision, extras, git)

    def test_other_historical_seed_still_uses_original_pinned_assets_and_policy(self):
        with self.candidate_fixture(historical=True) as (seed, live, checkout, revision, extras, git):
            snapshot = source.scan_snapshot(live, seed, 0)
            with mock.patch.object(source, '_policy_assets', wraps=source._policy_assets) as gate:
                result = source.prepare_candidate(checkout, snapshot, 27)
                repeated = source.prepare_candidate(checkout, snapshot, 27)
                self.assertEqual(gate.call_count, 2)
            self.assertEqual(result['candidatePolicy'], source.CANDIDATE_POLICY)
            self.assertEqual(result['commit'], repeated['commit'])
            self.assertTrue(repeated['reused'])
            self.assert_retained_seed_bytes(checkout, revision, extras, git)


if __name__ == '__main__':
    unittest.main()
