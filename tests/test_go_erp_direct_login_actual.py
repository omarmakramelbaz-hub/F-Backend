"""Build .56 with the exact frozen worker and independently verify its seed lineage.

This test deliberately fails while rollout pins are pending. It must run only
after the reviewed native core and its exact three-file policy seed exist.
No remote push, live database, system service, or production state is used.
"""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest

from go_erp_direct_login_reference import REFERENCE as REPAIR_REFERENCE
import test_go_erp_warm_start_repair_upgrade as reference
import test_go_erp_direct_login_rollout as rollout_tests

ROOT = Path(__file__).resolve().parents[1]
subject = rollout_tests.subject
CAPTURED_INVENTORY = '525a481eb2d13dc47879b48f4a6110cbd702f2c0d5fdad5e49d663dcafe75d21'


def git(root, *arguments, data=None):
    result = subprocess.run(['/usr/bin/git', '-c', 'credential.helper=', '-c', 'core.hooksPath=/dev/null',
        '-C', str(root), *arguments], input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=180, check=False)
    if result.returncode:
        raise AssertionError('DIRECT_LOGIN_REPLAY_GIT_FAILED')
    return result.stdout


def final_modules():
    if subject.PINS_PENDING:
        raise AssertionError('DIRECT_LOGIN_REPLAY_REQUIRES_FINAL_CORE_AND_SEED_PINS')
    template = subject.configure(subject.load_template(rollout_tests.TEMPLATE))
    payloads = template.derive_native_payloads(reference.NEW, reference.tick.NEW_COHORT['pins'], subject.SEED)
    spec = importlib.util.spec_from_file_location('direct_login_actual_tick', ROOT / 'deployment/go_erp_direct_login_tick_root.py')
    tick = importlib.util.module_from_spec(spec); spec.loader.exec_module(tick)
    if tick.PINS_PENDING:
        raise AssertionError('DIRECT_LOGIN_REPLAY_TICK_PINS_PENDING')
    if tick.NEW_COHORT['seed'] != subject.SEED or tick.NEW_COHORT['policy'] != subject.POLICY:
        raise AssertionError('DIRECT_LOGIN_REPLAY_COHORT_BINDING_CHANGED')
    for name, raw in payloads.items():
        expected = (template.blob(raw), len(raw), template.sha(raw))
        if tuple(tick.NEW_COHORT['pins'][name]) != expected:
            raise AssertionError('DIRECT_LOGIN_REPLAY_NATIVE_COHORT_CHANGED')
    utility = template.module(git(ROOT, 'show', template.PRIOR
        + ':deployment/import422-prior/upgrade_go_erp_capacity_watcher_root.py'),
        'direct_login_actual_pin_utility', template.UTILITY_BLOB, True)
    if utility.runtime_name(payloads) != tick.NEW_COHORT['runtime'].name or hashlib.sha256(
            utility.service_unit(tick.NEW_COHORT['runtime'])).hexdigest() != tick.NEW_COHORT['unit_sha256']:
        raise AssertionError('DIRECT_LOGIN_REPLAY_RUNTIME_OR_UNIT_CHANGED')
    for name, path in [('go_erp_manual_tick_root.py', 'go_erp_direct_login_tick_root.py'),
            ('go_erp_manual_updates_root.py', 'go_erp_direct_login_manual_root.py')]:
        if hashlib.sha256((ROOT / 'deployment' / path).read_bytes()).hexdigest() != subject.MANUAL_PINS[name]:
            raise AssertionError('DIRECT_LOGIN_REPLAY_HELPER_PIN_CHANGED')
    worker = reference.frozen('direct_login_actual_worker', payloads['go_erp_live_updates.py'])
    source = reference.frozen('direct_login_actual_source', payloads['go_erp_live_source.py'])
    publisher = reference.frozen('direct_login_actual_publisher', payloads['publish_go_erp_update.py'])
    return worker, source, publisher, tick


def captured_snapshot(source):
    captured = json.loads(git(ROOT, 'show', subject.INPUT_CANDIDATE + ':deployment/go_erp_live_source_candidate.json'))
    metadata = source._metadata(subject.SEED, captured['inventory'])
    if metadata['inventorySha256'] != CAPTURED_INVENTORY:
        raise AssertionError('DIRECT_LOGIN_REPLAY_CAPTURE_CHANGED')
    tree = {}
    for record in git(ROOT, 'ls-tree', '-r', '-z', subject.INPUT_CANDIDATE).split(b'\0'):
        if record:
            fields, name = record.split(b'\t', 1)
            tree[name.decode()] = fields.decode().split(' ')[2]
    needed = {name: tree[name] for name, row in captured['inventory'].items() if row['present']}
    names = sorted(set(needed.values()))
    raw = git(ROOT, 'cat-file', '--batch', data=('\n'.join(names) + '\n').encode())
    blobs = {}; offset = 0
    for expected in names:
        end = raw.index(b'\n', offset)
        digest, kind, size = raw[offset:end].decode().split(' ')
        if digest != expected or kind != 'blob':
            raise AssertionError('DIRECT_LOGIN_REPLAY_OBJECT_CHANGED')
        offset = end + 1; length = int(size)
        blobs[digest] = raw[offset:offset + length]; offset += length + 1
    if offset != len(raw):
        raise AssertionError('DIRECT_LOGIN_REPLAY_OBJECT_BYTES_CHANGED')
    files = {name: blobs[digest] for name, digest in needed.items()}
    for name, raw in files.items():
        if hashlib.sha256(raw).hexdigest() != captured['inventory'][name]['sha256']:
            raise AssertionError('DIRECT_LOGIN_REPLAY_SOURCE_BYTES_CHANGED')
    return {'metadata': metadata, '_files': files}


class ActualDirectLoginAllocationTests(unittest.TestCase):
    def fixture(self, directory):
        worker, source, publisher, tick = final_modules()
        snapshot = captured_snapshot(source)
        checkout = directory / 'seed'; checkout.mkdir()
        git(checkout, 'init', '--quiet')
        git(checkout, 'fetch', '--quiet', '--depth=2', '--no-tags', str(ROOT), subject.SEED)
        git(checkout, 'checkout', '--quiet', '--detach', subject.SEED)
        git(checkout, 'remote', 'add', 'origin', source.REMOTE_URL)
        work = directory / 'work'; work.mkdir(mode=0o700)
        for name in ('state', 'staging'):
            (work / name).mkdir(mode=0o700)
        live = directory / 'live'; (live / 'storage/app/desktop-updates').mkdir(parents=True)
        allocator = object.__new__(worker.Worker)
        allocator.work, allocator.live, allocator.checkout, allocator.web_uid = work, live, checkout, 0
        allocator.config = {'base_revision': subject.SEED, 'token': 'UNUSED-ISOLATED-REPLAY-TOKEN'}
        allocator.source, allocator.publisher = source, publisher
        allocator.state_file = work / 'state/current.json'
        allocator.state = rollout_tests.published_state()
        allocator.state.update(base_revision=subject.SEED, observed=snapshot['metadata'])
        allocator.clock = lambda: 100
        allocator.live_due, allocator.publishing = 0, False
        allocator.check_dependencies = lambda: None
        allocator.scan = lambda: snapshot
        allocator.git = worker.Git(work, 'UNUSED-ISOLATED-REPLAY-TOKEN')
        allocator.save()
        tick.install_guards(worker, CAPTURED_INVENTORY, allow_prepare=True, cohort=tick.NEW_COHORT)
        return worker, source, allocator, snapshot

    def test_original_prepare_creates_56_and_independent_canonical_verifier_accepts_the_exact_core(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-direct-login-actual-') as folder:
            worker, source, allocator, snapshot = self.fixture(Path(folder))
            before = copy.deepcopy(allocator.state)
            seed_head = git(allocator.checkout, 'rev-parse', 'HEAD')
            desired = allocator.prepare(snapshot)
            self.assertEqual(desired['version'], '0.4.4-preview.56')
            self.assertEqual(desired['candidatePolicy'], subject.POLICY)
            self.assertEqual(allocator.state['counter'], 56)
            self.assertFalse(desired['pushed'])
            self.assertEqual(desired['inventorySha256'], CAPTURED_INVENTORY)
            for key in ('published', 'published_history', 'version_floor', 'sequence_floor', 'last_remote_sha'):
                self.assertEqual(allocator.state[key], before[key], key)
            self.assertEqual(git(allocator.checkout, 'rev-parse', 'HEAD'), seed_head)
            self.assertEqual(git(allocator.checkout, 'status', '--porcelain'), b'')
            candidate = Path(desired['candidatePath'])
            raw = git(candidate, 'show', subject.SEED + ':deployment/verify_go_erp_preview31_seed.py')
            verifier = types.ModuleType('direct_login_actual_canonical_verifier')
            verifier.__file__ = str(candidate / 'deployment/verify_go_erp_preview31_seed.py')
            exec(compile(raw, verifier.__file__, 'exec'), verifier.__dict__)
            proof = verifier.verify(candidate, allocator.checkout)
            self.assertEqual(proof['revision'], desired['commit'])
            self.assertEqual(proof['version'], '0.4.4-preview.56')
            self.assertEqual(proof['reviewed_native_import_core'], subject.CORE)
            self.assertEqual(proof['inventory_sha256'], CAPTURED_INVENTORY)
            with self.assertRaisesRegex(worker.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
                allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 56)
            print('GO_ERP_DIRECT_LOGIN_ACTUAL_PREPARE_COMPLETE ' + json.dumps({'version': desired['version'],
                'canonical_verifier': True, 'captured_files': len(snapshot['_files']),
                'core': subject.CORE, 'seed': subject.SEED, 'counter': 56,
                'database_read': False, 'remote_push': False}), flush=True)

    def test_failed_original_creation_keeps_consumed_56_and_cannot_reserve_57(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-direct-login-failure-') as folder:
            worker, source, allocator, snapshot = self.fixture(Path(folder))
            before = copy.deepcopy(allocator.state)
            def fail(*args, **kwargs):
                raise source.SourceError('GO_ERP_LIVE_SOURCE_GIT_FAILURE')
            source.prepare_candidate = fail
            with self.assertRaisesRegex(source.SourceError, 'GO_ERP_LIVE_SOURCE_GIT_FAILURE'):
                allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 56)
            self.assertEqual(allocator.state['desired'], before['desired'])
            self.assertEqual(list((allocator.work / 'staging').iterdir()), [])
            self.assertEqual(json.loads(allocator.state_file.read_bytes())['counter'], 56)
            with self.assertRaisesRegex(worker.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
                allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 56)


if __name__ == '__main__':
    unittest.main()
