"""Real frozen tick/prepare/main retain all CI behavior inside manual-job bounds."""
import contextlib
import copy
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = load('manual_tick_subject', ROOT / 'deployment/go_erp_manual_tick_root.py')
proof = load('manual_tick_frozen_fixture', ROOT / 'tests/test_go_erp_preview42_restoration.py')
PAYLOADS = proof.PAYLOADS
OLD = '2' * 64
NEW = '3' * 64
CANDIDATE43 = proof.subject.CANDIDATE43
CANDIDATE44 = '4' * 40


class Fixture:
    def __init__(self, path):
        self.path = path
        path.chmod(0o700)
        self.runtime = path / 'runtime'
        self.runtime.mkdir(mode=0o755)
        for name, raw in PAYLOADS.items():
            target = self.runtime / name
            target.write_bytes(raw)
            target.chmod(0o644)
        self.module = subject.load_runtime(self.runtime)
        self.publisher = proof.frozen_module('manual_tick_publisher_' + path.name, PAYLOADS['publish_go_erp_update.py'])
        self.work = path / 'work'
        self.work.mkdir(mode=0o700)
        for name in ('state', 'staging'): (self.work / name).mkdir(mode=0o700)
        self.live = path / 'live'
        (self.live / 'storage/app/desktop-updates').mkdir(parents=True, mode=0o755)
        self.checkout = path / 'checkout'
        (self.checkout / 'desktop-pos').mkdir(parents=True)
        (self.checkout / 'desktop-pos/package.json').write_text('{"version":"0.4.4-preview.41"}')
        self.events = []
        self.inventory = OLD
        self.fetch_result = None
        self.fetch_error = None
        w = object.__new__(self.module.Worker)
        self.worker = w
        w.work, w.live, w.checkout, w.web_uid = self.work, self.live, self.checkout, 0
        w.config = {'base_revision': subject.SEED, 'token': 'PRIVATE_TEST_TOKEN'}
        w.state_file = self.work / 'state/current.json'
        w.state = {'format': 1, 'base_revision': subject.SEED, 'counter': 43,
            'last_remote_sha': CANDIDATE43, 'observed': None, 'sequence_floor': 40054814945,
            'version_floor': '0.4.4-preview.42', 'published_history': [], 'dependency_guard': None,
            'published': {'commit': proof.subject.CANDIDATE42, 'inventorySha256': OLD,
                'release': {'version': '0.4.4-preview.42'}},
            'desired': {'commit': CANDIDATE43, 'version': '0.4.4-preview.43', 'inventorySha256': OLD,
                'candidatePolicy': subject.POLICY, 'pushed': True, 'status': 'waiting-ci',
                'candidatePath': str(self.work / 'staging' / ('candidate-43-' + OLD[:16])),
                'failures': 0, 'retry_at': 0}}
        w.clock = lambda: 100
        w.live_due, w.publishing = 0, False
        w.publisher = self.publisher
        def scan_snapshot(*args):
            self.events.append('scan')
            return {'metadata': {'format': 1, 'inventorySha256': self.inventory, 'inventory': {}}}
        def prepare_candidate(destination, snapshot, counter, **kwargs):
            self.events.append('prepare-source')
            return {'commit': CANDIDATE44, 'version': '0.4.4-preview.' + str(counter),
                'inventorySha256': self.inventory, 'candidatePolicy': subject.POLICY,
                'candidatePath': 'deployment/go_erp_live_source_candidate.json'}
        w.source = types.SimpleNamespace(CANDIDATE_POLICY=self.module.CANDIDATE_POLICY,
            PREVIEW31_SEED=subject.SEED, PREVIEW31_POLICY=subject.POLICY,
            scan_snapshot=scan_snapshot, prepare_candidate=prepare_candidate)
        w.check_dependencies = lambda: self.events.append('dependencies')
        def candidate(checkout, destination, seed):
            self.events.append('git-candidate')
            destination.mkdir(mode=0o700)
        w.git = types.SimpleNamespace(heartbeat=lambda: None, candidate=candidate,
            push=lambda *args: self.events.append('push'))
        w.client = types.SimpleNamespace(json=lambda *args, **kwargs: {'object': {'sha': CANDIDATE43}})
        def fetch(desired):
            self.events.append(('fetch', desired['commit']))
            if self.fetch_error is not None: raise self.fetch_error
            return self.fetch_result
        w.fetch_accepted = fetch
        w.save()

    def guards(self, inventory=None, candidate=None, allow=False):
        subject.install_guards(self.module, inventory, candidate, allow)


class ManualTickTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-manual-tick-')
        self.addCleanup(temporary.cleanup)
        self.f = Fixture(Path(temporary.name))

    def test_all_seven_payload_pins_and_original_acceptance_methods_match(self):
        for name, (blob, size, digest) in subject.PINS.items():
            raw = PAYLOADS[name]
            self.assertEqual((len(raw), hashlib.sha256(raw).hexdigest()), (size, digest))
            self.assertEqual(hashlib.sha1(b'blob ' + str(size).encode() + b'\0' + raw).hexdigest(), blob)
        original = self.f.module.Worker.fetch_accepted
        self.f.guards(OLD, CANDIDATE43)
        self.assertIs(self.f.module.Worker.fetch_accepted, original)
        self.assertEqual(self.f.module.PREVIEW31_SEED, subject.SEED)

    def test_actual_frozen_lost_feed_tick_keeps43_and_collects_without_allocating44(self):
        self.f.guards(OLD, CANDIDATE43)
        result = self.f.worker.tick()
        self.assertEqual(result, 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertEqual(self.f.worker.state['counter'], 43)
        self.assertEqual(self.f.worker.state['desired']['commit'], CANDIDATE43)
        self.assertIn(('fetch', CANDIDATE43), self.f.events)
        self.assertNotIn('git-candidate', self.f.events)

    def test_first_explicit_job_can_allocate44_once_using_original_prepare_and_push(self):
        self.f.inventory = NEW
        self.f.guards(NEW, allow=True)
        self.assertEqual(self.f.worker.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        state = self.f.worker.state
        self.assertEqual(state['counter'], 44)
        self.assertEqual(state['desired']['commit'], CANDIDATE44)
        self.assertTrue(state['desired']['pushed'])
        self.assertEqual(self.f.events.count('git-candidate'), 1)
        self.assertEqual(self.f.events.count('push'), 1)
        self.assertEqual(json.loads(self.f.worker.state_file.read_bytes())['counter'], 44)
        with self.assertRaisesRegex(self.f.module.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
            self.f.worker.prepare(self.f.worker.scan())
        self.assertEqual(state['counter'], 44)

    def test_unapproved_allocation_cannot_increase_counter_or_push(self):
        self.f.inventory = NEW
        self.f.guards(NEW)
        with self.assertRaisesRegex(self.f.module.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
            self.f.worker.tick()
        self.assertEqual(self.f.worker.state['counter'], 43)
        self.assertNotIn('git-candidate', self.f.events)
        self.assertNotIn('push', self.f.events)

    def test_latched_candidate_change_refuses_before_original_tick_or_state_write(self):
        self.f.guards(OLD, CANDIDATE44)
        before = self.f.worker.state_file.read_bytes()
        with self.assertRaisesRegex(self.f.module.WatchError, 'MANUAL_CANDIDATE_CHANGED'):
            self.f.worker.tick()
        self.assertEqual(self.f.events, [])
        self.assertEqual(self.f.worker.state_file.read_bytes(), before)

    def test_latched_source_change_keeps_original_error_backoff_and_never_rebuilds(self):
        self.f.inventory = NEW
        self.f.guards(OLD, CANDIDATE43)
        with self.assertRaisesRegex(self.f.module.WatchError, 'MANUAL_SOURCE_CHANGED'):
            self.f.worker.tick()
        desired = self.f.worker.state['desired']
        self.assertEqual((desired['failures'], desired['retry_at']), (1, 220))
        self.assertEqual(self.f.worker.state['counter'], 43)
        self.assertNotIn('git-candidate', self.f.events)

    def test_original_backoff_and_recovered_retry_build_do_not_allocate(self):
        desired = self.f.worker.state['desired']
        desired.update(status='retry-build', failures=2, retry_at=200)
        self.f.guards(OLD, CANDIDATE43)
        self.assertEqual(self.f.worker.tick(), 'GO_ERP_LIVE_UPDATE_RETRY_DEFERRED')
        self.assertNotIn(('fetch', CANDIDATE43), self.f.events)
        desired['retry_at'] = 0
        self.assertEqual(self.f.worker.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertIn(('fetch', CANDIDATE43), self.f.events)
        self.assertEqual(desired['failures'], 2)
        self.assertNotIn('git-candidate', self.f.events)

    def test_failed_ci_cannot_replace_a_latched_candidate(self):
        self.f.worker.state['desired']['status'] = 'retry-build'
        self.f.fetch_error = self.f.module.WatchError('GO_ERP_LIVE_CI_FAILED')
        self.f.guards(OLD, CANDIDATE43)
        with self.assertRaisesRegex(self.f.module.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
            self.f.worker.tick()
        self.assertEqual(self.f.worker.state['counter'], 43)
        self.assertNotIn('git-candidate', self.f.events)

    def test_prepared_latched_candidate_can_retry_original_push(self):
        desired = self.f.worker.state['desired']
        destination = self.f.work / 'staging' / ('candidate-43-' + OLD[:16])
        destination.mkdir(mode=0o700)
        desired.update(pushed=False, status='prepared', candidatePath=str(destination))
        self.f.guards(OLD, CANDIDATE43)
        self.assertEqual(self.f.worker.tick(), 'GO_ERP_LIVE_UPDATE_WAITING_CI')
        self.assertTrue(desired['pushed'])
        self.assertNotIn('git-candidate', self.f.events)

    def test_runtime_extra_file_changed_payload_and_links_are_refused(self):
        extra = self.f.runtime / 'unexpected.py'
        extra.write_text('private')
        with self.assertRaisesRegex(subject.Stop, 'RUNTIME_ROSTER_INVALID'):subject.load_runtime(self.f.runtime)
        extra.unlink()
        target = self.f.runtime / 'go_erp_live_updates.py'
        original = target.read_bytes()
        target.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
        with self.assertRaisesRegex(subject.Stop, 'RUNTIME_PIN_INVALID'):subject.load_runtime(self.f.runtime)
        target.unlink()
        target.symlink_to(self.f.path / 'private.py')
        with self.assertRaises(OSError):subject.load_runtime(self.f.runtime)

    def test_actual_original_main_owns_lock_before_constructing_worker(self):
        self.f.guards(OLD, CANDIDATE43)
        module = self.f.module
        source = self.f.worker.source
        def construct(*args):
            with open(self.f.work / 'watcher.lock', 'rb') as stream:
                with self.assertRaises(BlockingIOError):fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return self.f.worker
        with patch.object(module, 'WORK', self.f.work), patch.object(module, 'read_config', return_value=self.f.worker.config), \
                patch.object(module, 'load_module', side_effect=lambda name, path: source if name=='go_erp_live_source' else self.f.publisher), \
                patch.object(module, 'Worker', side_effect=construct), \
                patch.object(module.socket, 'gethostname', return_value='srv1836524'), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(subject.run_original(module), 0)
        self.assertEqual(output.getvalue(), 'GO_ERP_LIVE_UPDATE_WAITING_CI\n')

    def test_private_output_and_invalid_job_arguments_never_reach_console(self):
        def leaking(arguments):
            print('PRIVATE_CREDENTIAL_MUST_NOT_APPEAR')
            return 0
        with contextlib.redirect_stdout(io.StringIO()) as output:
            with self.assertRaisesRegex(subject.Stop, 'ORIGINAL_OUTPUT_UNREVIEWED'):
                subject.run_original(types.SimpleNamespace(main=leaking))
        self.assertEqual(output.getvalue(), '')
        for inventory, candidate, allow in [(True, None, False), (OLD, CANDIDATE43, True),
                (None, CANDIDATE43, False), (OLD, '../secret', False)]:
            with self.subTest(candidate=candidate), self.assertRaises(subject.Stop):
                subject.install_guards(self.f.module, inventory, candidate, allow)


if __name__ == '__main__':
    unittest.main()
