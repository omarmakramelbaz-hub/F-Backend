"""Replay the shipped allocator and canonical verifier without a network push.

Only an isolated temporary Git checkout, state, and staging directory are used.
The source bytes are the immutable captured inventory that failed in .53.
"""
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest

import test_go_erp_warm_start_repair_upgrade as reference

ROOT = Path(__file__).resolve().parents[1]
SEED = reference.subject.SEED
CORE = reference.subject.CORE
FAILED = reference.subject.FAILED_CANDIDATE
INVENTORY = reference.subject.INPUT_INVENTORY
POLICY = reference.subject.POLICY


def git(root, *arguments, data=None):
    result = subprocess.run(['/usr/bin/git', '-c', 'credential.helper=',
        '-c', 'core.hooksPath=/dev/null', '-C', str(root), *arguments],
        input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=180, check=False)
    if result.returncode:
        raise AssertionError('LOCAL_REPLAY_GIT_FAILED')
    return result.stdout


def captured_snapshot(source):
    captured = json.loads(git(ROOT, 'show', FAILED + ':deployment/go_erp_live_source_candidate.json'))
    metadata = source._metadata(SEED, captured['inventory'])
    tree = {}
    for item in git(ROOT, 'ls-tree', '-r', '-z', FAILED).split(b'\0'):
        if not item:
            continue
        fields, name = item.split(b'\t', 1)
        tree[name.decode()] = fields.decode().split(' ')[2]
    needed = {name: tree[name] for name, row in captured['inventory'].items() if row['present']}
    names = sorted(set(needed.values()))
    raw = git(ROOT, 'cat-file', '--batch', data=('\n'.join(names) + '\n').encode())
    blobs = {}; offset = 0
    for expected in names:
        end = raw.index(b'\n', offset)
        digest, kind, length = raw[offset:end].decode().split(' ')
        if digest != expected or kind != 'blob':
            raise AssertionError('LOCAL_REPLAY_OBJECT_CHANGED')
        offset = end + 1; count = int(length)
        blobs[digest] = raw[offset:offset + count]; offset += count + 1
    if offset != len(raw):
        raise AssertionError('LOCAL_REPLAY_OBJECT_BYTES_CHANGED')
    files = {name: blobs[digest] for name, digest in needed.items()}
    if metadata['inventorySha256'] != INVENTORY:
        raise AssertionError('LOCAL_REPLAY_INVENTORY_CHANGED')
    for name, data in files.items():
        if hashlib.sha256(data).hexdigest() != captured['inventory'][name]['sha256']:
            raise AssertionError('LOCAL_REPLAY_SOURCE_BYTES_CHANGED')
    return {'metadata': metadata, '_files': files}


def original_modules():
    # New module instances keep guard installation confined to this replay.
    frozen = reference.frozen
    worker = frozen('reserved_actual_worker', reference.NEW['go_erp_live_updates.py'])
    source = frozen('reserved_actual_source', reference.NEW['go_erp_live_source.py'])
    publisher = frozen('reserved_actual_publisher', reference.NEW['publish_go_erp_update.py'])
    return worker, source, publisher


class ActualOriginalAllocationTests(unittest.TestCase):
    def fixture(self, directory):
        worker, source, publisher = original_modules()
        snapshot = captured_snapshot(source)
        checkout = directory / 'seed'
        checkout.mkdir()
        git(checkout, 'init', '--quiet')
        # Fetch only the reviewed seed and its direct core parent into this
        # independent fixture. Unrelated ambient refs never enter the clone.
        git(checkout, 'fetch', '--quiet', '--depth=2', '--no-tags',
            str(ROOT), SEED)
        git(checkout, 'checkout', '--quiet', '--detach', SEED)
        git(checkout, 'remote', 'add', 'origin', source.REMOTE_URL)
        work = directory / 'work'; work.mkdir(mode=0o700)
        for name in ('state', 'staging'):
            (work / name).mkdir(mode=0o700)
        live = directory / 'live'
        (live / 'storage/app/desktop-updates').mkdir(parents=True)
        allocator = object.__new__(worker.Worker)
        allocator.work, allocator.live, allocator.checkout, allocator.web_uid = work, live, checkout, 0
        allocator.config = {'base_revision': SEED, 'token': 'UNUSED-LOCAL-REPLAY-TOKEN'}
        allocator.source, allocator.publisher = source, publisher
        allocator.state_file = work / 'state/current.json'
        allocator.state = reference.failed_state()
        allocator.state.update(base_revision=SEED, counter=54, observed=snapshot['metadata'])
        allocator.state['desired'].update(failures=3)
        allocator.clock = lambda: 100
        allocator.live_due, allocator.publishing = 0, False
        allocator.check_dependencies = lambda: None
        allocator.scan = lambda: snapshot
        allocator.git = worker.Git(work, 'UNUSED-LOCAL-REPLAY-TOKEN')
        allocator.save()
        reference.tick.install_guards(worker, INVENTORY, allow_prepare=True,
            cohort=reference.tick.NEW_COHORT)
        return worker, source, allocator, snapshot

    def test_actual_prepare_allocates_55_and_passes_shipped_canonical_verifier(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-reserved-actual-') as folder:
            worker, source, allocator, snapshot = self.fixture(Path(folder))
            before = copy.deepcopy(allocator.state)
            checkout_head = git(allocator.checkout, 'rev-parse', 'HEAD')
            desired = allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 55)
            self.assertEqual(desired['version'], '0.4.4-preview.55')
            self.assertEqual(desired['candidatePolicy'], POLICY)
            self.assertEqual(desired['inventorySha256'], INVENTORY)
            self.assertFalse(desired['pushed'])
            self.assertEqual(desired['status'], 'prepared')
            for key in ('published', 'published_history', 'version_floor', 'sequence_floor', 'last_remote_sha'):
                self.assertEqual(allocator.state[key], before[key], key)
            self.assertEqual(json.loads(allocator.state_file.read_bytes()), allocator.state)
            self.assertEqual(git(allocator.checkout, 'rev-parse', 'HEAD'), checkout_head)
            self.assertEqual(git(allocator.checkout, 'status', '--porcelain'), b'')
            candidate = Path(desired['candidatePath'])
            raw = git(candidate, 'show', SEED + ':deployment/verify_go_erp_preview31_seed.py')
            verifier = types.ModuleType('reserved_actual_canonical_verifier')
            verifier.__file__ = str(candidate / 'deployment/verify_go_erp_preview31_seed.py')
            exec(compile(raw, verifier.__file__, 'exec'), verifier.__dict__)
            proof = verifier.verify(candidate, allocator.checkout)
            self.assertEqual(proof['revision'], desired['commit'])
            self.assertEqual(proof['version'], '0.4.4-preview.55')
            self.assertEqual(proof['inventory_sha256'], INVENTORY)
            self.assertEqual(proof['reviewed_native_import_core'], CORE)
            with self.assertRaisesRegex(worker.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
                allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 55)
            print('GO_ERP_RESERVED_ACTUAL_PREPARE_COMPLETE ' + json.dumps({
                'version': desired['version'], 'canonical_verifier': True,
                'captured_files': len(snapshot['_files']), 'inventory': INVENTORY,
                'remote_push': False, 'database_read': False, 'counter': 55}), flush=True)

    def test_actual_prepare_retains_consumed_55_and_removes_incomplete_clone_on_source_failure(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-reserved-failure-') as folder:
            worker, source, allocator, snapshot = self.fixture(Path(folder))
            before = copy.deepcopy(allocator.state)
            def fail(*args, **kwargs):
                raise source.SourceError('GO_ERP_LIVE_SOURCE_GIT_FAILURE')
            source.prepare_candidate = fail
            with self.assertRaisesRegex(source.SourceError, 'GO_ERP_LIVE_SOURCE_GIT_FAILURE'):
                allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 55)
            self.assertEqual(allocator.state['desired'], before['desired'])
            self.assertEqual(list((allocator.work / 'staging').iterdir()), [])
            self.assertEqual(json.loads(allocator.state_file.read_bytes())['counter'], 55)
            with self.assertRaisesRegex(worker.WatchError, 'MANUAL_ALLOCATION_NOT_AUTHORIZED'):
                allocator.prepare(snapshot)
            self.assertEqual(allocator.state['counter'], 55)


if __name__ == '__main__':
    unittest.main()
