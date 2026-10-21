#!/usr/bin/env python3
"""Metadata-only hints for suppressed backup filenames; never archive authority."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

REPOSITORY = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, REPOSITORY/path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


subject = load('redacted_inventory_subject', 'deployment/archive_go_erp_meta_backups_root.py')
source = load('redacted_inventory_source', 'deployment/go_erp_live_source.py')


class RedactedInventoryTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-redacted-inventory-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)/'root'
        self.root.mkdir(mode=0o750)
        self.stem = 'app/Services/Dashboard/MetaOAuthCredentials.php'
        self.manifest = {'sources': [], 'protected': [{'path': self.stem}]}
        patch = mock.patch.object(source, '_git', return_value=self.stem.encode()+b'\0')
        patch.start(); self.addCleanup(patch.stop)

    def put(self, name):
        path = self.root/name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(b'PRIVATE_BACKUP_CONTENT_MUST_NOT_BE_OPENED')
        path.chmod(0o640)
        return path

    def report(self, **options):
        return subject.unclassified_inventory(source, self.root, Path('/unused-seed'),
            manifest=self.manifest, **options)

    def test_numeric_suffix_identifies_only_public_stem_digest(self):
        name = self.stem+'.bak.1785856307'
        path = self.put(name); before = path.stat()
        report = self.report()
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['unclassified_count'], 1)
        self.assertEqual(report['redacted_paths'], 1)
        self.assertEqual(report['unclassified_additions'], [])
        self.assertEqual(report['redacted_additions'], [{
            'source_root': 'app', 'known_manifest_php_stem': True,
            'manifest_php_stem_sha256': hashlib.sha256(self.stem.encode()).hexdigest(),
            'category': 'numeric-backup', 'backup_suffix': '.bak.1785856307'}])
        encoded = json.dumps(report)
        self.assertNotIn('MetaOAuthCredentials', encoded)
        self.assertNotIn('PRIVATE_BACKUP_CONTENT', encoded)
        after = path.stat()
        self.assertEqual(subject.exact(before), subject.exact(after))

    def test_timestamp_and_exact_legacy_suffixes_are_bounded(self):
        suffixes = ['.bak-20261020-042159', subject.SUFFIX]
        for suffix in suffixes:
            self.put(self.stem+suffix)
        report = self.report()
        self.assertTrue(report['inventory_complete'])
        self.assertEqual({row['backup_suffix'] for row in report['redacted_additions']}, set(suffixes))
        self.assertEqual({row['category'] for row in report['redacted_additions']},
                         {'numeric-backup', 'meta-backup'})

    def test_unknown_or_partial_stem_never_claims_manifest_authority(self):
        names = ['app/Services/Dashboard/UnknownCredentials.php.bak.1785856307',
                 self.stem+'Extra.bak.1785856307']
        for name in names:
            self.put(name)
        report = self.report()
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['redacted_paths'], 2)
        for row in report['redacted_additions']:
            self.assertEqual(row, {'source_root': 'app',
                'known_manifest_php_stem': False, 'category': 'unknown-stem'})
        encoded = json.dumps(report)
        for marker in ['UnknownCredentials', 'MetaOAuthCredentials', '1785856307']:
            self.assertNotIn(marker, encoded)

    def test_arbitrary_or_non_exact_numeric_suffix_is_never_printed(self):
        suffixes = ['.bak.PRIVATE_SECRET_TOKEN', '.bak.1785856307_SECRET',
                    '.bak-20261020-042159_SECRET', '.bak-meta-20261020-044958']
        for suffix in suffixes:
            self.put(self.stem+suffix)
        report = self.report()
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['redacted_paths'], len(suffixes))
        for row in report['redacted_additions']:
            self.assertTrue(row['known_manifest_php_stem'])
            self.assertEqual(row['category'], 'other-suffix')
            self.assertNotIn('backup_suffix', row)
        encoded = json.dumps(report)
        for marker in ['PRIVATE_SECRET_TOKEN', '1785856307_SECRET', '044958', 'MetaOAuthCredentials']:
            self.assertNotIn(marker, encoded)

    def test_private_names_are_excluded_before_stat_and_no_file_is_opened(self):
        self.put(self.stem+'.bak.1785856307')
        for name in ['config/.env', 'app/service-account.json',
                     'bootstrap/cache/private.php', 'public/uploads/private.json']:
            self.put(name)
        real_open, real_stat = os.open, os.stat
        def directory_open(name, flags, *args, **kwargs):
            self.assertTrue(flags & os.O_DIRECTORY, 'Inventory opened file bytes')
            return real_open(name, flags, *args, **kwargs)
        def private_stat(name, *args, **kwargs):
            self.assertNotIn(Path(str(name)).name, {'.env', 'service-account.json', 'cache', 'uploads'})
            return real_stat(name, *args, **kwargs)
        with mock.patch.object(os, 'open', side_effect=directory_open), \
                mock.patch.object(os, 'stat', side_effect=private_stat):
            report = self.report()
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['unclassified_count'], 1)
        self.assertEqual(report['redacted_paths'], 1)
        encoded = json.dumps(report)
        for marker in ['.env', 'service-account', 'private.php', 'private.json', 'PRIVATE_BACKUP_CONTENT']:
            self.assertNotIn(marker, encoded)

    def test_redacted_output_is_capped_without_hiding_total_count(self):
        for index in range(101):
            self.put(self.stem+'.bak.'+str(index).zfill(10))
        report = self.report()
        self.assertFalse(report['inventory_complete'])
        self.assertEqual(report['redacted_paths'], 101)
        self.assertEqual(report['unclassified_count'], 101)
        self.assertEqual(len(report['redacted_additions']), 100)

    def test_missing_manifest_cannot_claim_known_stem(self):
        self.put(self.stem+'.bak.1785856307')
        report = subject.unclassified_inventory(source, self.root, Path('/unused-seed'))
        self.assertTrue(report['inventory_complete'])
        self.assertEqual(report['redacted_additions'], [{'source_root': 'app',
            'known_manifest_php_stem': False, 'category': 'unknown-stem'}])

    def test_inventory_failure_keeps_private_exception_hidden(self):
        self.put(self.stem+'.bak.1785856307')
        with mock.patch.object(source, '_git', side_effect=RuntimeError('PRIVATE_GIT_SECRET')):
            report = self.report()
        self.assertFalse(report['inventory_complete'])
        self.assertNotIn('PRIVATE_GIT_SECRET', json.dumps(report))
        self.assertEqual(report['redacted_additions'], [])


if __name__ == '__main__':
    unittest.main()
