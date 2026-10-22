#!/usr/bin/env python3
"""Actual private runtime exchange and reviewed-seed candidate generation."""
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
PAYLOAD = REPO / 'deployment/capacity-watcher'


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = module('capacity_watcher_upgrade', REPO / 'deployment/upgrade_go_erp_capacity_watcher_root.py')
fixtures = module('capacity_watcher_exchange_fixtures', REPO / 'tests/test_go_erp_checkout_policy_upgrade_root.py')
fixtures.subject = subject
source = module('capacity_watcher_source', PAYLOAD / 'go_erp_live_source.py')
worker = module('capacity_watcher_worker', PAYLOAD / 'go_erp_live_updates.py')


@unittest.skipUnless(os.geteuid() == 0, 'Private runtime fixture requires root.')
class CapacityRuntimeUpgradeTests(fixtures.CheckoutPolicyUpgradeTests):
    def audits(self):
        return list(self.work.glob('capacity-policy-upgrade-*'))

    def test_blocked_23_and_every_version_floor_are_preserved(self):
        state = json.loads(self.state.read_text())
        state.update(counter=23, version_floor='0.4.4-preview.22', sequence_floor=39812322690)
        state['desired'].update(version='0.4.4-preview.23', status='blocked',
            commit='72711c1531aeac3f3ca68cd86eb2b0ad91e442c9', candidatePolicy='historical-baseline-v2')
        self.state.write_text(json.dumps(state))
        self.secret_before = self.private_snapshot()
        report = self.upgrade()
        self.assertTrue(report['ok'], report)
        self.assertEqual(report['counter'], 23)
        self.assertFalse(report['configuration_read'])
        self.assertFalse(report['state_changed'])
        self.assert_private_unchanged()


class CapacityEffectiveUnitTests(fixtures.EffectiveUnitTests):
    pass


class CapacityPayloadTests(unittest.TestCase):
    def test_only_source_and_worker_change_from_exact_deployed_runtime(self):
        previous = {name: (REPO / 'deployment' / name).read_bytes() for name in subject.ROSTER}
        self.assertEqual(subject.runtime_name(previous), subject.OLD_RUNTIME_NAME)
        changed = []
        for name in subject.ROSTER:
            current = (PAYLOAD / name).read_bytes()
            self.assertEqual(subject.blob(current), subject.PINS[name])
            self.assertEqual(subject.blob(previous[name]), subject.OLD_PINS[name])
            if current != previous[name]:
                changed.append(name)
        self.assertEqual(changed, ['go_erp_live_source.py', 'go_erp_live_updates.py'])
        self.assertEqual(subject.BASE, source.CAPACITY_SEED)

    def test_capacity_seed_changes_candidate_policy_and_rejects_old_producer(self):
        controller = types.SimpleNamespace(config={'base_revision': source.CAPACITY_SEED}, source=source)
        self.assertEqual(worker.Worker.candidate_policy(controller), source.CAPACITY_POLICY)
        self.assertNotEqual(source.CAPACITY_POLICY, source.CANDIDATE_POLICY)
        controller.source = types.SimpleNamespace(CANDIDATE_POLICY=source.CANDIDATE_POLICY)
        with self.assertRaisesRegex(worker.WatchError, 'CAPACITY_POLICY_UNREVIEWED'):
            worker.Worker.candidate_policy(controller)
        controller.config['base_revision'] = 'f' * 40
        self.assertEqual(worker.Worker.candidate_policy(controller), source.CANDIDATE_POLICY)


@unittest.skipUnless(os.geteuid() == 0, 'Source authority fixture requires root.')
class CapacityCandidateTests(unittest.TestCase):
    def test_exact_capacity_seed_retains_its_independent_gate_and_reuses_same_candidate(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-capacity-candidate-', dir='/var/tmp') as name:
            base = Path(name)
            seed, live, checkout = (base / value for value in ('seed', 'live', 'candidate'))
            seed.mkdir(mode=0o700)
            for file in source.CRITICAL:
                target = seed / file
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('<?php // private fixture\n')
            extras = {
                '.github/workflows/go-erp-update-tests.yml': 'run: independent-capacity-seed-proof\n',
                'deployment/verify_go_erp_media_capacity_seed.py': '# fixed independent gate\n',
                'desktop-pos/src/dashboard-media.cjs': 'const MAX_TOTAL = 2 * 1024 ** 3;\n',
                'desktop-pos/package.json': json.dumps({'version': '0.4.4-preview.22'}),
                'desktop-pos/package-lock.json': json.dumps({'version': '0.4.4-preview.22', 'packages': {'': {'version': '0.4.4-preview.22'}}}),
            }
            for file, data in extras.items():
                target = seed / file
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(data)
            def git(*args, root=seed):
                return subprocess.check_output(['git', '-c', 'safe.directory=' + str(root), '-C', str(root), *args], stderr=subprocess.PIPE)
            git('init', '--quiet', '--template=')
            git('remote', 'add', 'origin', source.REMOTE_URL)
            git('add', '.')
            git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'fixed capacity seed')
            revision = git('rev-parse', 'HEAD').decode().strip()
            live.mkdir(mode=0o700)
            for file in source.CRITICAL:
                target = live / file
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(seed / file, target)
            subprocess.run(['git', 'clone', '--quiet', '--no-local', '--no-hardlinks', str(seed), str(checkout)], check=True)
            git('remote', 'set-url', 'origin', source.REMOTE_URL, root=checkout)
            with mock.patch.object(source, 'CAPACITY_SEED', revision):
                snapshot = source.scan_snapshot(live, seed, 0)
                with mock.patch.object(source, '_policy_assets', side_effect=AssertionError('Old template must not overwrite this exact seed')):
                    result = source.prepare_candidate(checkout, snapshot, 24)
                    repeated = source.prepare_candidate(checkout, snapshot, 24)
                self.assertEqual(result['candidatePolicy'], source.CAPACITY_POLICY)
                self.assertEqual(result['commit'], repeated['commit'])
                self.assertTrue(repeated['reused'])
            for file, data in extras.items():
                if file.endswith(('package.json', 'package-lock.json')):
                    continue
                self.assertEqual((checkout / file).read_text(), data)
            self.assertEqual(git('rev-parse', 'HEAD^', root=checkout).decode().strip(), revision)
            changed = git('diff', '--name-only', revision, 'HEAD', root=checkout).decode().splitlines()
            self.assertEqual(changed, ['deployment/go_erp_live_source_candidate.json', 'desktop-pos/package-lock.json', 'desktop-pos/package.json'])
            with mock.patch.object(source, '_policy_assets', side_effect=RuntimeError('old pinned gate')):
                with self.assertRaisesRegex(RuntimeError, 'old pinned gate'):
                    source.prepare_candidate(checkout, snapshot, 24)


if __name__ == '__main__':
    unittest.main()
