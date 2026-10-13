#!/usr/bin/env python3
"""Read-only source diagnosis tests using ordinary local filesystem fixtures."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location(
    'current_source_diagnosis_subject',
    REPO / 'deployment/diagnose_desktop_dashboard_current_source.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)
SOURCE = {'__name__': 'diagnosis_test_source_functions'}
exec(compile((REPO / 'deployment/desktop_dashboard_server_preflight.py').read_bytes(),
             'pinned-preflight-functions', 'exec'), SOURCE)
HEAD = 'e' * 40


class CurrentSourceDiagnosisTests(unittest.TestCase):
    def test_read_only_diagnosis_requires_one_pinned_commit_argument(self):
        pin='a'*40
        self.assertEqual(subject.release_argument(['diagnose.py','--release',pin]),pin)
        for arguments in [['diagnose.py'],['diagnose.py','--release','main'],
                          ['diagnose.py','--release','A'*40],['diagnose.py','--release',pin,'extra'],
                          ['diagnose.py','--other',pin]]:
            with self.subTest(arguments=arguments),self.assertRaisesRegex(subject.DiagnosisError,'REQUIRES_PINNED_RELEASE'):
                subject.release_argument(arguments)

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='source-diagnosis-test-')
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.root = self.folder / 'public_html'
        self.root.mkdir()
        self.file = self.write('app/main.php', b'<?php PRIVATE_SOURCE_SENTINEL\n')
        self.gui = self.write('public/js/inbox.js', b'GUI_PRIVATE_SENTINEL\n')
        uid, gid = self.file.stat().st_uid, self.file.stat().st_gid
        self.rows = [{'path': 'app/main.php', 'before': [uid, gid, '0644'],
                      'after': [uid, gid, '0755']}]

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(0o644)
        return path

    def scan(self):
        fd = subject.open_root(self.root)
        try:
            return subject.source_map(fd, SOURCE)
        finally:
            os.close(fd)

    def snapshot(self, head=HEAD):
        return subject.snapshot(self.root, SOURCE, ['public/js/inbox.js'],
                                self.rows, lambda: head)

    def baseline(self, value):
        fingerprint = {**SOURCE['digest'](value['files']), 'framework': '8.83.29'}
        fixture = {'files': value['files'], 'source': fingerprint,
                   'expected_head': HEAD,
                   'gui': {'public/js/inbox.js': value['gui']['public/js/inbox.js'][0]['blob']}}
        return fixture, {'after_source': fingerprint}, {'rows': self.rows}

    def report(self, before, after, fixture):
        return subject.report(before, after, SOURCE, *fixture)

    def facts(self):
        # Read access times can change; content and all mutation-sensitive facts
        # must remain identical across the diagnostic's read-only operations.
        return {str(path.relative_to(self.root)):
                (subject.identity(path.lstat()), path.read_bytes())
                for path in self.root.rglob('*') if path.is_file()}

    def test_secret_cache_and_key_sentinels_are_never_opened(self):
        for name in ['.env', 'config/.env.production', 'config/firebase_credentials.json',
                     'config/service-account-private.json', 'config/private.key',
                     'bootstrap/cache/private.php']:
            self.write(name, b'NEVER_OPEN_PRIVATE_SENTINEL\n')
        forbidden = {'.env', '.env.production', 'firebase_credentials.json',
                     'service-account-private.json', 'private.key', 'cache', 'private.php'}
        original = os.open

        def guarded(name, flags, *args, **kwargs):
            self.assertNotIn(Path(name).name, forbidden, 'Excluded private path was opened')
            return original(name, flags, *args, **kwargs)

        before = self.facts()
        with patch.object(subject.os, 'open', side_effect=guarded):
            files, _ = self.scan()
        self.assertEqual(set(files), {'app/main.php'})
        self.assertEqual(self.facts(), before)

    def test_fifo_is_rejected_without_reading_or_changing_it(self):
        fifo = self.root / 'app/queue.php'
        os.mkfifo(fifo, 0o644)
        before = subject.identity(fifo.lstat())
        with self.assertRaisesRegex(subject.DiagnosisError, 'FILE_UNSAFE'):
            self.scan()
        self.assertEqual(subject.identity(fifo.lstat()), before)
        self.assertTrue(stat.S_ISFIFO(fifo.lstat().st_mode))

    def test_leaf_and_intermediate_symlinks_never_open_their_destination(self):
        outside = self.folder / 'private-outside'
        outside.mkdir()
        sentinel = outside / 'never-open.php'
        sentinel.write_bytes(b'OUTSIDE_PRIVATE_SENTINEL\n')
        original = os.open

        def guarded(name, flags, *args, **kwargs):
            self.assertNotEqual(Path(name).name, sentinel.name)
            return original(name, flags, *args, **kwargs)

        leaf = self.root / 'app/link.php'
        leaf.symlink_to(sentinel)
        with patch.object(subject.os, 'open', side_effect=guarded):
            with self.assertRaisesRegex(subject.DiagnosisError, 'LINK'):
                self.scan()
        leaf.unlink()
        parent = self.root / 'public/dashboard'
        parent.mkdir(parents=True)
        (parent / 'js').symlink_to(outside, target_is_directory=True)
        before = subject.identity(sentinel.stat())
        with patch.object(subject.os, 'open', side_effect=guarded):
            with self.assertRaises((subject.DiagnosisError, OSError)):
                self.scan()
        self.assertEqual(subject.identity(sentinel.stat()), before)
        self.assertEqual(sentinel.read_bytes(), b'OUTSIDE_PRIVATE_SENTINEL\n')

    def test_crlf_only_change_reports_raw_drift_with_equal_normalized_fingerprint(self):
        baseline = self.snapshot()
        fixture = self.baseline(baseline)
        self.file.write_bytes(self.file.read_bytes().replace(b'\n', b'\r\n'))
        current = self.snapshot()
        result = self.report(current, self.snapshot(), fixture)
        self.assertTrue(result['source_matches_before'])
        self.assertEqual(result['source_difference_count'], 1)
        row = result['source_differences'][0]
        self.assertEqual((row['path'], row['change']), ('app/main.php', 'raw_only'))
        self.assertNotEqual(row['expected']['blob'], row['actual']['blob'])
        self.assertEqual(row['expected']['sha256'], row['actual']['sha256'])
        self.assertNotIn('PRIVATE_SOURCE_SENTINEL', json.dumps(result))

    def test_content_gui_metadata_and_head_drift_between_snapshots_reject_report(self):
        for kind in ['content', 'raw_only', 'gui', 'metadata', 'head']:
            with self.subTest(kind=kind):
                self.file.write_bytes(b'<?php PRIVATE_SOURCE_SENTINEL\n')
                self.file.chmod(0o644)
                self.gui.write_bytes(b'GUI_PRIVATE_SENTINEL\n')
                before = self.snapshot()
                fixture = self.baseline(before)
                if kind == 'content':
                    self.file.write_bytes(b'<?php CHANGED_PRIVATE_SOURCE\n')
                elif kind == 'raw_only':
                    self.file.write_bytes(b'<?php PRIVATE_SOURCE_SENTINEL\r\n')
                elif kind == 'gui':
                    self.gui.write_bytes(b'CHANGED_GUI_PRIVATE_SENTINEL\n')
                elif kind == 'metadata':
                    self.file.chmod(0o755)
                after = self.snapshot('f' * 40 if kind == 'head' else HEAD)
                with self.assertRaisesRegex(subject.DiagnosisError, 'SNAPSHOT_CHANGED'):
                    self.report(before, after, fixture)

    def test_complete_read_only_report_preserves_source_metadata_and_private_files(self):
        self.write('.env', b'PRIVATE_ENV_SENTINEL\n')
        before = self.facts()
        snapshot = self.snapshot()
        result = self.report(snapshot, self.snapshot(), self.baseline(snapshot))
        self.assertEqual(result['source_difference_count'], 0)
        self.assertEqual(result['publication_metadata_states']['original'], 1)
        self.assertEqual(self.facts(), before)
        for name in ['deployment_performed', 'metadata_changed', 'php_bootstrapped',
                     'database_read', 'source_content_printed']:
            self.assertFalse(result[name])
        output = json.dumps(result)
        for secret in ['PRIVATE_ENV_SENTINEL', 'PRIVATE_SOURCE_SENTINEL', 'GUI_PRIVATE_SENTINEL']:
            self.assertNotIn(secret, output)

    def test_absent_related_gui_and_multiply_linked_scope_are_reported(self):
        baseline = self.snapshot()
        os.link(self.file, self.folder / 'outside-alias.php')
        names = ['public/js/inbox.js', *subject.RELATED_GUI]
        before = subject.snapshot(self.root, SOURCE, names, self.rows, lambda: HEAD)
        after = subject.snapshot(self.root, SOURCE, names, self.rows, lambda: HEAD)
        result = self.report(before, after, self.baseline(baseline))
        self.assertEqual(result['publication_metadata_states']['unreviewed'], 1)
        self.assertEqual(result['unreviewed_metadata'][0]['metadata']['links'], 2)
        self.assertEqual(len(result['related_meta_gui']), 3)
        self.assertTrue(all(row['actual'] is None for row in result['related_meta_gui']))


if __name__ == '__main__':
    unittest.main()
