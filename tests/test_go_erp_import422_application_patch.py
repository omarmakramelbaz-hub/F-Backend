"""Root-only real filesystem exchange tests; no service, PHP or database is run."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import shutil
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('patch', ROOT / 'deployment/go_erp_import422_application_patch.py')
PATCH = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PATCH)
SCAN_SPEC = importlib.util.spec_from_file_location('scanner', ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py')
SCANNER = importlib.util.module_from_spec(SCAN_SPEC)
SCAN_SPEC.loader.exec_module(SCANNER)


@unittest.skipUnless(os.geteuid() == 0, 'real inode exchange requires root')
class ExchangeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='go-erp-application-patch-')
        self.base = Path(self.directory.name)
        self.root, self.audit = self.base / 'live', self.base / 'audit'
        self.package = self.base / 'package'
        self.package.mkdir(mode=0o700)
        for name in [*PATCH.PINS, 'deployment/refresh_desktop_dashboard_server_manifest.py']:
            destination = self.package / ('application/' + name if name in PATCH.PINS else 'refresh_desktop_dashboard_server_manifest.py')
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, destination)
        self.root.mkdir(mode=0o755)
        self.audit.mkdir(mode=0o700)
        self.originals = {}
        for name, (old, _) in PATCH.PINS.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            if old is not None:
                data = subprocess.check_output(['git', 'show', '27eae737be190a9900fed67810393a0b68627119:' + name], cwd=ROOT)
                self.assertEqual(PATCH.blob(data), old)
                path.write_bytes(data)
                self.originals[name] = data
        (self.root / 'artisan').write_text('<?php // unrelated fixture source')
        self.before_map = SCANNER.scan(self.root)
        self.after_map = copy.deepcopy(self.before_map)
        for name in PATCH.PINS:
            self.after_map[name] = SCANNER.file_record(ROOT / name)
        self.real_before, self.real_after = PATCH.BEFORE_SOURCE, PATCH.AFTER_SOURCE
        PATCH.BEFORE_SOURCE, PATCH.AFTER_SOURCE = SCANNER.digest(self.before_map), SCANNER.digest(self.after_map)
        manifest = {'version': 1, 'expected_head': 'preserved-history', 'before_source': {'preserved': True},
            'after_source': PATCH.BEFORE_SOURCE,
            'sources': [{'path': name, 'before_blob': None, 'after_blob': PATCH.blob(data), 'phase': 2}
                        for name, data in self.originals.items()],
            'protected': [{'path': 'artisan', 'blob': self.before_map['artisan']['blob']}],
            'migrations': ['preserved-migration-roster']}
        (self.root / 'deployment').mkdir()
        (self.root / PATCH.MANIFEST).write_text(json.dumps(manifest))
        self.manifest = (self.root / PATCH.MANIFEST).read_bytes()
        lock = self.base / 'watcher.lock'
        self.lock_fd = os.open(lock, os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
        self.patch = PATCH.ApplicationPatch(self.root, self.package, self.audit, 0, 0, self.lock_fd)
        self.original_inodes = {name: (self.root / name).stat().st_ino for name in self.originals}

    def tearDown(self):
        PATCH.BEFORE_SOURCE, PATCH.AFTER_SOURCE = self.real_before, self.real_after
        os.close(self.lock_fd)
        if self.patch.stage_fd is not None:
            self.patch._cleanup()
        self.directory.cleanup()

    def test_real_apply_and_rollback_restore_original_inodes_and_manifest(self):
        summary = self.patch.prepare()
        self.assertEqual(summary['application_files'], 4)
        self.patch.apply()
        self.assertTrue(self.patch.verify())
        for name in PATCH.PINS:
            self.assertEqual((self.root / name).read_bytes(), (ROOT / name).read_bytes())
        manifest = json.loads((self.root / PATCH.MANIFEST).read_bytes())
        self.assertEqual(manifest['before_source'], {'preserved': True})
        self.assertEqual(manifest['expected_head'], 'preserved-history')
        self.assertEqual(manifest['migrations'], ['preserved-migration-roster'])
        self.patch.rollback()
        self.assertTrue(self.patch.rollback())
        self.assertEqual((self.root / PATCH.MANIFEST).read_bytes(), self.manifest)
        for name, data in self.originals.items():
            self.assertEqual((self.root / name).read_bytes(), data)
            self.assertEqual((self.root / name).stat().st_ino, self.original_inodes[name])
        self.assertFalse((self.root / 'desktop/import-diagnostic.php').exists())
        self.assertFalse(self.patch.stage.exists())

    def test_commit_removes_only_owned_stage_and_verifies_idempotent_state(self):
        self.patch.prepare()
        self.patch.apply()
        stage = self.patch.stage
        self.patch.commit()
        self.assertFalse(stage.exists())
        self.assertTrue((self.audit / 'application-committed.json').is_file())
        self.assertTrue(PATCH.verify_current(self.root, self.package, 0, 0, self.lock_fd)['already_current'])
        with self.assertRaisesRegex(PATCH.Stop, 'ALREADY_COMMITTED'):
            self.patch.rollback()

    def test_source_change_before_apply_does_not_publish_any_target(self):
        self.patch.prepare()
        (self.root / 'artisan').write_text('<?php // concurrent source change')
        with self.assertRaisesRegex(PATCH.Stop, 'SOURCE_CHANGED_BEFORE_APPLY'):
            self.patch.apply()
        for name, data in self.originals.items():
            self.assertEqual((self.root / name).read_bytes(), data)
        self.assertFalse((self.root / 'desktop/import-diagnostic.php').exists())

    def test_unexpected_published_writer_is_not_overwritten_on_rollback(self):
        self.patch.prepare()
        self.patch.apply()
        path = self.root / 'desktop/import.php'
        path.write_text('<?php // unexpected writer')
        with self.assertRaisesRegex(PATCH.Stop, 'ROLLBACK_TARGET_CHANGED'):
            self.patch.rollback()
        self.assertEqual(path.read_text(), '<?php // unexpected writer')
        self.assertTrue(all(slot['moved'] for slot in self.patch.slots))

    def test_changed_retained_original_is_not_restored(self):
        self.patch.prepare()
        self.patch.apply()
        first = self.patch.slots[0]
        (self.patch.stage / first['slot']).write_text('<?php // unexpected retained original')
        with self.assertRaisesRegex(PATCH.Stop, 'HELD_ORIGINAL_CHANGED'):
            self.patch.commit()
        with self.assertRaisesRegex(PATCH.Stop, 'ROLLBACK_ORIGINAL_CHANGED'):
            self.patch.rollback()
        self.assertEqual((self.root / first['name']).read_bytes(), first['wanted'])
        self.assertTrue(all(slot['moved'] for slot in self.patch.slots))

    def test_mid_apply_failure_and_outer_retry_roll_back_once(self):
        self.patch.prepare()
        original_rename = PATCH.rename
        calls = 0
        def failure(*args):
            nonlocal calls
            calls += 1
            if calls == 3:
                raise OSError('synthetic exchange failure')
            return original_rename(*args)
        with mock.patch.object(PATCH, 'rename', side_effect=failure):
            with self.assertRaisesRegex(OSError, 'synthetic exchange failure'):
                self.patch.apply()
        self.assertTrue(self.patch.rolled_back)
        self.assertTrue(self.patch.rollback())
        self.assertEqual((self.root / PATCH.MANIFEST).read_bytes(), self.manifest)
        for name, data in self.originals.items():
            self.assertEqual((self.root / name).read_bytes(), data)

    def test_early_prepare_failure_has_safe_no_mutation_rollback(self):
        (self.package / 'refresh_desktop_dashboard_server_manifest.py').write_text('# unreviewed scanner')
        with self.assertRaisesRegex(PATCH.Stop, 'SCANNER_PIN_INVALID'):
            self.patch.prepare()
        self.assertTrue(self.patch.rollback())
        self.assertTrue(self.patch.rollback())
        self.assertEqual((self.root / PATCH.MANIFEST).read_bytes(), self.manifest)

    def test_unreviewed_manifest_stops_before_exchanges(self):
        value = json.loads(self.manifest)
        value['after_source']['sha256'] = '0' * 64
        (self.root / PATCH.MANIFEST).write_text(json.dumps(value))
        with self.assertRaisesRegex(PATCH.Stop, 'MANIFEST_UNREVIEWED'):
            self.patch.prepare()
        self.assertFalse(self.patch.prepared)
        self.assertTrue(self.patch.rollback())
        self.assertTrue(self.patch.rollback())
        self.assertFalse((self.root / 'desktop/import-diagnostic.php').exists())


if __name__ == '__main__':
    unittest.main()
