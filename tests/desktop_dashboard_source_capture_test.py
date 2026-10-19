#!/usr/bin/env python3
"""Exercise private source evidence against ordinary disposable filesystem fixtures."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch
import zipfile

REPO = Path(__file__).resolve().parent.parent


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, REPO/'deployment'/filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = module('capture_subject', 'capture_desktop_dashboard_source.py')
diagnostic = module('capture_reader', 'diagnose_desktop_dashboard_current_source.py')
SOURCE = {'__name__': 'capture_test_source'}
exec(compile((REPO/'deployment/desktop_dashboard_server_preflight.py').read_bytes(), 'source-functions', 'exec'), SOURCE)
HEAD = 'a'*40


class SourceCaptureTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='desktop-source-test-')
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.root = self.folder/'public_html'
        self.root.mkdir()
        self.output = self.folder/'private'
        self.output.mkdir(mode=0o700)
        self.known = self.write('app/known.php', b'<?php KNOWN_SOURCE\n')
        self.unknown = self.write('app/current.php', b'<?php CURRENT_SOURCE\r\n')
        self.asset = self.write('public/js/dashboard-current.js', b'CURRENT_ASSET\n')
        self.available = {subject.blob(self.known.read_bytes())}
        self.report = self.report_now()

    def write(self, name, data):
        path = self.root/name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(0o644)
        return path

    def report_now(self):
        snapshot = diagnostic.snapshot(self.root, SOURCE, list(diagnostic.RELATED_GUI), [], lambda: HEAD)
        return {'source': {'actual_head': HEAD,
                           'actual_source': {**SOURCE['digest'](snapshot['files']), 'framework': '8.83.29'},
                           'source_differences': [], 'source_difference_count': 0,
                           'source_differences_truncated': False, 'gui': [],
                           'additional_dashboard_gui': [{'path': key, 'actual': value[0]}
                                                        for key, value in snapshot['additional_dashboard_gui'].items()]},
                'schema': {}, 'deployment_performed': False,
                'business_rows_exported': False, 'application_files_changed': False}

    def capture(self, report=None):
        return subject.bundle(self.root, self.output, report or self.report, self.available,
                              diagnostic, SOURCE, lambda: HEAD)

    def test_exact_missing_bytes_private_mode_and_complete_map_are_preserved(self):
        before = self.unknown.read_bytes()
        path, count, digest = self.capture()
        self.assertEqual(count, 2)
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertEqual(digest, hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as archive:
            self.assertEqual(set(archive.namelist()), {'source/app/current.php',
                'source/public/js/dashboard-current.js', 'capture.json', 'source-map.json', 'inspection-report.json'})
            self.assertEqual(archive.read('source/app/current.php'), before)
            metadata = json.loads(archive.read('capture.json'))
            self.assertFalse(metadata['business_rows_exported'])
            self.assertFalse(metadata['environment_files_exported'])
            self.assertEqual(len(metadata['files']), 2)
            self.assertEqual(json.loads(archive.read('source-map.json'))['app/current.php'], SOURCE['record'](before))
        self.assertEqual(self.unknown.read_bytes(), before)

    def test_environment_credentials_cache_and_business_storage_are_excluded(self):
        for name in ['.env', 'config/firebase_credentials.json', 'config/private.key',
                     'bootstrap/cache/private.php', 'storage/app/customer.json']:
            self.write(name, b'NEVER_EXPORT_PRIVATE_SENTINEL\n')
        path, _, _ = self.capture()
        with zipfile.ZipFile(path) as archive:
            for name in archive.namelist():
                self.assertNotIn(b'NEVER_EXPORT_PRIVATE_SENTINEL', archive.read(name))

    def test_unknown_blob_under_another_known_path_still_uses_content_identity(self):
        self.available.add(subject.blob(self.unknown.read_bytes()))
        path, count, _ = self.capture()
        self.assertEqual(count, 1)
        with zipfile.ZipFile(path) as archive:
            self.assertNotIn('source/app/current.php', archive.namelist())

    def test_stale_report_source_or_frontend_refuses_capture(self):
        self.unknown.write_bytes(b'<?php NEWER_SOURCE\n')
        with self.assertRaisesRegex(subject.CaptureError, 'REPORT_SOURCE_CHANGED'):
            self.capture()
        self.report = self.report_now()
        self.asset.write_bytes(b'NEWER_ASSET\n')
        with self.assertRaisesRegex(subject.CaptureError, 'REPORT_GUI_CHANGED'):
            self.capture()
        self.assertFalse((self.output/'desktop-source-for-pc.zip').exists())

    def test_source_change_during_capture_cleans_only_new_archive(self):
        original = subject.read_relative
        def changed(*args):
            result = original(*args)
            self.known.write_bytes(b'<?php CONCURRENT_KNOWN_FILE\n')
            return result
        with patch.object(subject, 'read_relative', side_effect=changed):
            with self.assertRaisesRegex(subject.CaptureError, 'SNAPSHOT_CHANGED'):
                self.capture()
        self.assertFalse((self.output/'desktop-source-for-pc.zip').exists())
        self.assertEqual(self.known.read_bytes(), b'<?php CONCURRENT_KNOWN_FILE\n')

    def test_existing_archive_is_never_overwritten_or_removed(self):
        path = self.output/'desktop-source-for-pc.zip'
        path.write_bytes(b'PREEXISTING_PRIVATE_ARCHIVE')
        with self.assertRaises(FileExistsError):
            self.capture()
        self.assertEqual(path.read_bytes(), b'PREEXISTING_PRIVATE_ARCHIVE')

    def test_size_limit_removes_partial_archive_without_changing_sources(self):
        before = self.unknown.read_bytes()
        with patch.object(subject, 'LIMIT', 1):
            with self.assertRaisesRegex(subject.CaptureError, 'CONTENT_CHANGED'):
                self.capture()
        self.assertFalse((self.output/'desktop-source-for-pc.zip').exists())
        self.assertEqual(self.unknown.read_bytes(), before)

    def test_unsafe_report_gui_cannot_reach_environment_file(self):
        self.write('.env', b'PRIVATE_ENV_SENTINEL')
        self.report['source']['gui'] = [{'path': '.env', 'actual': None}]
        with self.assertRaisesRegex(subject.CaptureError, 'GUI_SCOPE_INVALID'):
            self.capture()

    def test_symlink_source_and_traversal_are_refused(self):
        outside = self.folder/'outside.php'
        outside.write_bytes(b'OUTSIDE_PRIVATE_SENTINEL')
        self.unknown.unlink()
        self.unknown.symlink_to(outside)
        with self.assertRaises(diagnostic.DiagnosisError):
            self.capture()
        fd = diagnostic.open_root(self.root)
        try:
            with self.assertRaisesRegex(subject.CaptureError, 'PATH_INVALID'):
                subject.read_relative(fd, '../outside.php', diagnostic)
        finally:
            os.close(fd)
        self.assertEqual(outside.read_bytes(), b'OUTSIDE_PRIVATE_SENTINEL')

    def test_incomplete_reports_and_invalid_catalogs_are_rejected(self):
        file = self.folder/'report.txt'
        body = json.dumps(self.report).encode()
        file.write_bytes(b'DESKTOP_DASHBOARD_UPDATES_INSPECTION_BEGIN\n'+body+
                         b'\nDESKTOP_DASHBOARD_UPDATES_INSPECTION_END; DEPLOYMENT_PERFORMED=false\n')
        self.assertEqual(subject.report_file(file), self.report)
        self.report['source']['source_differences_truncated'] = True
        body = json.dumps(self.report).encode()
        file.write_bytes(b'DESKTOP_DASHBOARD_UPDATES_INSPECTION_BEGIN\n'+body+
                         b'\nDESKTOP_DASHBOARD_UPDATES_INSPECTION_END; DEPLOYMENT_PERFORMED=false\n')
        with self.assertRaisesRegex(subject.CaptureError, 'REPORT_INCOMPLETE'):
            subject.report_file(file)
        for catalog in ({}, {'format': 1, 'known_blobs': ['../.env']}, {'format': 1, 'known_blobs': []}):
            with self.assertRaisesRegex(subject.CaptureError, 'CATALOG_INVALID'):
                subject.known_blobs(catalog)


if __name__ == '__main__':
    unittest.main()
