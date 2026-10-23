#!/usr/bin/env python3
"""Exercise the real unchanged publisher from the complete private runtime."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('preview31_publisher_upgrade',
    REPO / 'deployment/upgrade_go_erp_preview31_watcher_root.py')
subject = importlib.util.module_from_spec(spec); spec.loader.exec_module(subject)


@unittest.skipUnless(os.geteuid() == 0, 'Private publisher runtime fixture requires root.')
class PublisherRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-preview31-readers-', dir='/var/tmp')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        utility = self.base / 'upgrade_go_erp_capacity_watcher_root.py'
        utility.write_bytes((REPO / 'deployment/upgrade_go_erp_capacity_watcher_root.py').read_bytes())
        utility.chmod(0o644)
        self.u = subject.load_utility(utility)
        self.payload = {name: (REPO / 'deployment/preview31-watcher' / name).read_bytes() for name in subject.ROSTER}
        self.releases = self.base / 'releases'; self.releases.mkdir(mode=0o755)
        self.target = self.u.install_runtime(self.releases, self.payload, subject.PINS)
        self.live = self.base / 'public-source'; self.live.mkdir()
        self.files = {
            'artisan': b'<?php // source only\r\n',
            'app/Fixture.php': b'<?php // no application is executed\n',
            'desktop/source-contract.json': json.dumps({'format': 1, 'framework': '8.83.29',
                'supportedRuntimeFrameworks': ['8.83.29', '12.70.0']}).encode(),
            'public/dashboard/js/fixture.js': b'// fingerprinted dashboard code\r\n',
        }
        for name, data in self.files.items():
            path = self.live / name; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data)
        (self.live / 'composer.lock').write_text(json.dumps({'packages': [
            {'name': 'laravel/framework', 'version': 'v8.83.29'}]}))
        (self.live / '.env').write_text('MUST_NOT_BE_READ=private-fixture\n')
        (self.live / '.env').chmod(0)

    def publisher(self, target=None):
        directory = self.u.Directory(target or self.target, 0o755)
        try:
            return self.u.module_at(directory, 'publish_go_erp_update.py', subject.PINS['publish_go_erp_update.py'])
        finally:
            directory.close()

    def test_seven_file_runtime_executes_exact_real_readers_and_source_snapshot(self):
        publisher = self.publisher()
        actual = publisher.source_snapshot(self.live)
        self.assertEqual(actual['fingerprint'], subject.normalized_source(self.files))
        self.assertEqual(set(actual['snapshot']['files']), set(self.files))
        self.assertNotIn('.env', actual['snapshot']['files'])
        self.assertEqual(publisher.READER_PINS, {name: subject.PINS[name] for name in publisher.READER_PINS})
        self.assertEqual(set(path.name for path in self.target.iterdir()), set(subject.ROSTER))

    def test_old_five_file_layout_reproduces_missing_reader_failure(self):
        old_payload = {name: self.payload[name] for name in subject.OLD_ROSTER}
        old_pins = {name: subject.blob(data) for name, data in old_payload.items()}
        target = self.u.install_runtime(self.releases, old_payload, old_pins)
        publisher = self.publisher(target)
        with self.assertRaises(FileNotFoundError):
            publisher.source_snapshot(self.live)

    def test_missing_reader_is_refused_by_runtime_roster_and_real_publisher(self):
        publisher = self.publisher()
        (self.target / 'desktop_dashboard_server_preflight.py').unlink()
        with self.assertRaises(FileNotFoundError):
            publisher.source_snapshot(self.live)
        directory = self.u.Directory(self.target, 0o755)
        try:
            with self.assertRaisesRegex(self.u.Stop, 'RUNTIME_ROSTER_INVALID'):
                self.u.validate_runtime(directory, subject.PINS)
        finally:
            directory.close()

    def test_tampered_reader_cannot_execute(self):
        publisher = self.publisher()
        marker = self.base / 'must-not-execute'
        reader = self.target / 'desktop_dashboard_server_preflight.py'
        reader.write_text('from pathlib import Path\nPath(' + repr(str(marker)) + ').write_text("bad")\n')
        with self.assertRaisesRegex(publisher.PublicationError, 'SOURCE_READER_PIN_MISMATCH'):
            publisher.source_snapshot(self.live)
        self.assertFalse(marker.exists())

    def test_public_feed_is_read_only_and_revalidated_by_the_real_publisher(self):
        self.assertIsNone(subject.publication_snapshot(self.target, self.live, subject.PINS, self.u))
        directory = self.live / 'storage/app/desktop-updates'; directory.mkdir(parents=True)
        release = {'version': '0.4.4-preview.30', 'sequence': subject.REVIEWED_SEQUENCE,
                   'source_revision': subject.REVIEWED_REVISION, 'source': subject.EXPECTED_SOURCE,
                   'installer': {'sha256': 'a' * 64, 'bytes': 128,
                                 'path': '/api/desktop-dashboard/update/installer/' + 'a' * 64}, 'gui': {}}
        feed = directory / 'current.json'; feed.write_text(json.dumps(release)); feed.chmod(0o644)
        before = feed.read_bytes(); inode = feed.stat().st_ino
        self.assertEqual(subject.publication_snapshot(self.target, self.live, subject.PINS, self.u), release)
        self.assertEqual(feed.read_bytes(), before); self.assertEqual(feed.stat().st_ino, inode)
        release['installer']['path'] = '/foreign'
        feed.write_text(json.dumps(release))
        with self.assertRaises(Exception):
            subject.publication_snapshot(self.target, self.live, subject.PINS, self.u)


if __name__ == '__main__':
    unittest.main()
