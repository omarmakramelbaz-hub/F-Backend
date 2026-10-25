"""Exercise retention decisions and fail-closed filesystem behavior with synthetic releases."""
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
REFERENCE = ROOT / 'deployment/publish_go_erp_update.py'


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


worker = module('retention_worker_test', ROOT / 'deployment/go_erp_release_retention_root.py')
publisher = module('retention_reference_publisher_test', REFERENCE)


@unittest.skipUnless(os.geteuid() == 0, 'filesystem security tests require root ownership')
class RetentionTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-retention-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.paths = worker.Paths(self.root / 'manual', self.root / 'live',
                                  self.root / 'public', self.root / 'cache')
        self.archive = self.paths.manual / 'shared/installers'
        for path in (self.paths.manual / 'private', self.archive,
                     self.paths.live / 'state', self.paths.public, self.paths.cache):
            path.mkdir(parents=True, mode=0o700)
        self.payloads = {}
        self.releases = {number: self.make_release(number) for number in (49, 50, 51)}
        self.set_publication(51, 50, [49, 50, 51])
        for number in self.releases:
            self.add_binary_copies(self.releases[number])
        self.acceptance = self.add_acceptance(self.releases[49])

    def write(self, path, raw):
        path.write_bytes(raw)
        path.chmod(0o600)

    def write_json(self, path, value):
        self.write(path, worker.canonical(value))

    def make_release(self, number):
        payload = b'MZsynthetic-test-installer-' + str(number).encode()
        sha = hashlib.sha256(payload).hexdigest()
        self.payloads[sha] = payload
        release = {'version': '0.4.4-preview.' + str(number), 'sequence': number * 100,
                   'source_revision': format(number, '040x'),
                   'source': {'format': 1, 'files': 2, 'sha256': hashlib.sha256(str(number).encode()).hexdigest(),
                              'framework': '12.0.0'}, 'gui': {},
                   'installer': {'sha256': sha, 'bytes': len(payload),
                                 'path': '/api/desktop-dashboard/update/installer/' + sha}}
        publisher.validate_release(release)
        return release

    def set_publication(self, current, previous, history):
        latest, before = self.releases[current], self.releases[previous]
        self.downloads = {'format': 1, 'kind': 'go-erp-published-downloads',
                          'current': latest, 'previous': before}
        self.state = {'format': 1, 'published': {'release': latest, 'commit': latest['source_revision'],
                     'run_id': latest['sequence'], 'inventorySha256': 'a' * 64},
                     'version_floor': latest['version'], 'sequence_floor': latest['sequence'],
                     'desired': {'status': 'published', 'version': latest['version'],
                                 'commit': latest['source_revision'], 'inventorySha256': 'a' * 64},
                     'published_history': [self.releases[number] for number in history]}
        self.save_metadata()

    def save_metadata(self):
        self.write_json(self.paths.manual / 'shared/downloads.json', self.downloads)
        self.write_json(self.paths.public / 'current.json', self.downloads['current'])
        self.write_json(self.paths.live / 'state/current.json', self.state)

    def add_binary_copies(self, release):
        name = release['installer']['sha256'] + '.exe'
        for path in (self.archive / name, self.paths.public / name):
            self.write(path, self.payloads[release['installer']['sha256']])

    def add_acceptance(self, release):
        folder = self.paths.live / 'program-acceptance' / (release['source_revision'] + '-fixture')
        ci = folder / 'ci'
        ci.mkdir(parents=True, mode=0o700)
        envelope = {'format': 1, 'kind': 'go-erp-program-acceptance', 'version': release['version'],
                    'candidate_revision': release['source_revision'], 'installer': release['installer'],
                    'source': release['source'], 'ci': {'directory': str(ci), 'run_id': release['sequence'],
                                                      'run_attempt': 1}}
        receipt = {key: copy.deepcopy(release[key]) for key in ('version', 'sequence', 'source_revision', 'source', 'gui')}
        receipt.update(format=1, kind='go-erp-desktop-release',
                       installer={key: release['installer'][key] for key in ('sha256', 'bytes')},
                       acceptance={'windows_installer': True, 'runtime': True, 'connected': True,
                                   'source_revision': release['source_revision']})
        publisher.validate_receipt(receipt)
        self.write_json(folder / 'acceptance.json', envelope)
        self.write_json(ci / 'go-erp-update-release.json', receipt)
        self.write_json(folder / 'employee.json', {'test_evidence': True})
        self.write(ci / 'GO-ERP-Preview-Setup.exe', self.payloads[release['installer']['sha256']])
        return folder

    def copies(self, number):
        name = self.releases[number]['installer']['sha256'] + '.exe'
        return [self.archive / name, self.paths.public / name]

    def binary_inventory(self):
        return {str(path): path.read_bytes() for path in self.root.rglob('*.exe') if not path.is_symlink()}

    def metadata_inventory(self):
        return {str(path): path.read_bytes() for path in self.root.rglob('*.json') if self.paths.cache not in path.parents}

    def test_check_plans_without_deleting_then_apply_keeps_two_and_proofs(self):
        binaries, metadata = self.binary_inventory(), self.metadata_inventory()
        result = worker.run(self.paths, publisher)
        self.assertEqual(result['status'], 'checked')
        self.assertEqual(result['planned_files'], 3)
        self.assertEqual(self.binary_inventory(), binaries)
        result = worker.run(self.paths, publisher, apply=True)
        self.assertEqual(result['keep_versions'], ['0.4.4-preview.51', '0.4.4-preview.50'])
        self.assertEqual(result['removed_files'], 3)
        self.assertGreater(result['reclaimed_bytes'], 0)
        for number in (50, 51):
            self.assertTrue(all(path.exists() for path in self.copies(number)))
        self.assertTrue(all(not path.exists() for path in self.copies(49)))
        self.assertFalse((self.acceptance / 'ci/GO-ERP-Preview-Setup.exe').exists())
        self.assertEqual(self.metadata_inventory(), metadata)

    def test_waiting_candidate_blocks_all_deletion(self):
        self.state['desired']['status'] = 'waiting-ci'
        self.save_metadata()
        before = self.binary_inventory()
        with self.assertRaises(BlockingIOError):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_rollout_hold_blocks_all_deletion(self):
        self.write_json(self.paths.live / 'program-rollout-hold.json', {'active': True})
        before = self.binary_inventory()
        with self.assertRaises(BlockingIOError):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_public_feed_mismatch_blocks(self):
        self.write_json(self.paths.public / 'current.json', self.releases[50])
        before = self.binary_inventory()
        with self.assertRaisesRegex(worker.Stop, 'CANONICAL_FEED_MISMATCH'):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_missing_kept_copy_blocks(self):
        self.copies(50)[0].unlink()
        before = self.binary_inventory()
        with self.assertRaisesRegex(worker.Stop, 'RETAINED_INSTALLER_MISSING'):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_corrupt_kept_copy_blocks_before_any_deletion(self):
        path = self.copies(50)[0]
        self.write(path, b'MZ' + b'x' * (path.stat().st_size - 2))
        before = self.binary_inventory()
        with self.assertRaisesRegex(worker.Stop, 'INSTALLER_HASH_MISMATCH'):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_corrupt_old_copy_blocks_entire_plan(self):
        path = self.copies(49)[0]
        self.write(path, b'MZ' + b'x' * (path.stat().st_size - 2))
        before = self.binary_inventory()
        with self.assertRaisesRegex(worker.Stop, 'INSTALLER_HASH_MISMATCH'):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_candidate_symlink_rejected_and_target_survives(self):
        path = self.copies(49)[0]
        target = self.root / 'important.dat'
        self.write(target, b'important')
        path.unlink()
        path.symlink_to(target)
        with self.assertRaisesRegex(worker.Stop, 'FILE_OWNER_LINK_OR_MODE'):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(target.read_bytes(), b'important')
        self.assertTrue(self.copies(49)[1].exists())

    def test_candidate_hardlink_rejected(self):
        target = self.root / 'also-important.dat'
        os.link(self.copies(49)[0], target)
        with self.assertRaisesRegex(worker.Stop, 'FILE_OWNER_LINK_OR_MODE'):
            worker.run(self.paths, publisher, apply=True)
        self.assertTrue(target.exists())
        self.assertTrue(all(path.exists() for path in self.copies(49)))

    def test_directory_symlink_rejected(self):
        actual = self.root / 'real-cache'
        self.paths.cache.rename(actual)
        self.paths.cache.symlink_to(actual, target_is_directory=True)
        with self.assertRaisesRegex(worker.Stop, 'DIRECTORY_LINK_OR_SPECIAL'):
            worker.run(self.paths, publisher, apply=True)
        self.assertTrue(all(path.exists() for path in self.copies(49)))

    def test_lock_contention_aborts_without_deletion(self):
        path = self.paths.manual / 'private/worker.lock'
        fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            before = self.binary_inventory()
            with self.assertRaises(BlockingIOError):
                worker.run(self.paths, publisher, apply=True)
            self.assertEqual(self.binary_inventory(), before)
        finally:
            os.close(fd)

    def test_malicious_acceptance_directory_binding_rejected(self):
        path = self.acceptance / 'acceptance.json'
        envelope = json.loads(path.read_bytes())
        envelope['ci']['directory'] = '/home/virtfs/fasakha/ci'
        self.write_json(path, envelope)
        before = self.binary_inventory()
        with self.assertRaisesRegex(worker.Stop, 'OBSOLETE_ACCEPTANCE_BINDING_INVALID'):
            worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_unchanged_periodic_run_avoids_binary_hash_reads(self):
        worker.run(self.paths, publisher, apply=True)
        # The deleted acceptance receipt drops out of the candidate proof set.
        worker.run(self.paths, publisher, apply=True)
        with mock.patch.object(worker.Binary, 'verify', side_effect=AssertionError('unexpected large read')):
            result = worker.run(self.paths, publisher, apply=True)
        self.assertTrue(result['unchanged_fast_path'])
        self.assertEqual(result['removed_files'], 0)

    @unittest.skipUnless(Path('/proc/self/fd').is_dir(), 'Linux descriptor accounting required')
    def test_retained_old_acceptance_metadata_has_bounded_descriptor_use(self):
        worker.run(self.paths, publisher, apply=True)
        worker.run(self.paths, publisher, apply=True)
        release = self.releases[49]
        parent = self.paths.live / 'program-acceptance'
        for number in range(150):
            folder = parent / (release['source_revision'] + '-removed' + str(number))
            ci = folder / 'ci'
            ci.mkdir(parents=True, mode=0o700)
            self.write_json(folder / 'acceptance.json', {
                'format': 1, 'kind': 'go-erp-program-acceptance',
                'version': release['version'], 'candidate_revision': release['source_revision'],
                'installer': release['installer'], 'source': release['source'],
                'ci': {'directory': str(ci), 'run_id': release['sequence'], 'run_attempt': 1}
            })
        baseline = len(os.listdir('/proc/self/fd'))
        maximum = baseline
        original = worker.Directory.__init__
        def tracking(directory, path, stack):
            nonlocal maximum
            original(directory, path, stack)
            maximum = max(maximum, len(os.listdir('/proc/self/fd')))
        with mock.patch.object(worker.Directory, '__init__', tracking), \
                mock.patch.object(worker.Binary, 'verify', side_effect=AssertionError('unexpected large read')):
            for _ in range(3):
                result = worker.run(self.paths, publisher, apply=True)
                self.assertTrue(result['unchanged_fast_path'])
                self.assertEqual(len(os.listdir('/proc/self/fd')), baseline)
        self.assertLess(maximum, baseline + 40)

    def test_numeric_semver_and_new_publication_advance_retention(self):
        for number in (99, 100):
            self.releases[number] = self.make_release(number)
            self.add_binary_copies(self.releases[number])
        self.set_publication(100, 99, [49, 50, 51, 99, 100])
        result = worker.run(self.paths, publisher, apply=True)
        self.assertEqual(result['keep_versions'], ['0.4.4-preview.100', '0.4.4-preview.99'])
        for number in (49, 50, 51):
            self.assertTrue(all(not path.exists() for path in self.copies(number)))
        for number in (99, 100):
            self.assertTrue(all(path.exists() for path in self.copies(number)))

    def test_next_published_generation_keeps_52_51_and_removes_50(self):
        worker.run(self.paths, publisher, apply=True)
        self.releases[52] = self.make_release(52)
        self.add_binary_copies(self.releases[52])
        self.set_publication(52, 51, [50, 51, 52])
        metadata = self.metadata_inventory()
        result = worker.run(self.paths, publisher, apply=True)
        self.assertEqual(result['keep_versions'], ['0.4.4-preview.52', '0.4.4-preview.51'])
        self.assertEqual(result['removed_files'], 2)
        self.assertTrue(all(not path.exists() for path in self.copies(50)))
        for number in (51, 52):
            self.assertTrue(all(path.exists() for path in self.copies(number)))
        self.assertEqual(self.metadata_inventory(), metadata)

    def test_metadata_changed_after_verification_aborts_before_deletion(self):
        original = worker.Binary.verify
        changed = False
        def verify(binary):
            nonlocal changed
            original(binary)
            if not changed:
                changed = True
                self.state['desired']['status'] = 'waiting-ci'
                self.save_metadata()
        before = self.binary_inventory()
        with mock.patch.object(worker.Binary, 'verify', verify):
            with self.assertRaisesRegex(worker.Stop, 'METADATA_CHANGED'):
                worker.run(self.paths, publisher, apply=True)
        self.assertEqual(self.binary_inventory(), before)

    def test_retained_installer_replaced_after_verification_aborts(self):
        original = worker.Binary.verify
        changed = False
        def verify(binary):
            nonlocal changed
            original(binary)
            if not changed:
                changed = True
                target = self.copies(51)[0]
                replacement = target.parent / 'replacement'
                self.write(replacement, target.read_bytes())
                replacement.replace(target)
        with mock.patch.object(worker.Binary, 'verify', verify):
            with self.assertRaisesRegex(worker.Stop, 'INSTALLER_CHANGED'):
                worker.run(self.paths, publisher, apply=True)
        self.assertTrue(all(path.exists() for path in self.copies(49)))


if __name__ == '__main__':
    unittest.main()
