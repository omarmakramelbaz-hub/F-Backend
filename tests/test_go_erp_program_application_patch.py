"""Run the audited real inode exchange cases through the new configurable module."""
import importlib.util
import copy
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value
PATCH = module('program_application_patch_test', ROOT / 'deployment/go_erp_program_application_patch.py')
PREVIOUS = module('program_application_patch_prior', ROOT / 'deployment/go_erp_wire_hash_application_patch.py')
PRIOR_TESTS = module('program_application_prior_cases', ROOT / 'tests/fixtures/program_prior_application.py')
# These inode/rollback cases use current reviewed payload bytes with exact old
# predecessors. The independent final-identity suite pins the release itself.
TARGETS = {name: (old, PATCH.blob((ROOT / name).read_bytes())) for name, (old, _) in PREVIOUS.PINS.items()}
PATCH.configure(PREVIOUS.BEFORE_SOURCE, PREVIOUS.AFTER_SOURCE, TARGETS)
PRIOR_TESTS.PATCH = PATCH


class ProgramExchangeTests(PRIOR_TESTS.ExchangeTests):
    """Inherited tests execute the new module with the last exact reviewed source fixture."""


class ConfigurationTests(unittest.TestCase):
    def test_same_source_or_empty_roster_is_refused_before_filesystem_actions(self):
        for before, after, pins in [(PREVIOUS.BEFORE_SOURCE, PREVIOUS.BEFORE_SOURCE, PREVIOUS.PINS),
                                    (PREVIOUS.BEFORE_SOURCE, PREVIOUS.AFTER_SOURCE, {})]:
            with self.subTest(pins=pins), self.assertRaisesRegex(PATCH.Stop, 'RELEASE_PINS_PENDING'):
                PATCH.configure(before, after, pins)
    def test_identical_file_pair_cannot_enter_exchange_roster(self):
        with self.assertRaisesRegex(PATCH.Stop, 'RELEASE_PINS_INVALID'):
            PATCH.configure(PREVIOUS.BEFORE_SOURCE, PREVIOUS.AFTER_SOURCE,
                            {'desktop/import.php': ('a' * 40, 'a' * 40)})

    def test_unsafe_exact_paths_are_refused_before_filesystem_actions(self):
        for name in ('../outside.js', '/absolute.js', 'public//same.js', 'public/./same.js',
                     'public/../outside.js', 'public/back\\slash.js', PATCH.MANIFEST):
            with self.subTest(path=name), self.assertRaisesRegex(PATCH.Stop, 'RELEASE_PINS_INVALID'):
                PATCH.configure(PREVIOUS.BEFORE_SOURCE, PREVIOUS.AFTER_SOURCE, {name: ('a' * 40, 'b' * 40)})


@unittest.skipUnless(os.geteuid() == 0, 'real root-owned inode exchanges')
class RawAssetExchangeTests(unittest.TestCase):
    NORMAL = 'desktop/import.php'
    RAW = 'public/dashboard/dist/js/adminlte.js'

    def setUp(self):
        self.original_config = copy.deepcopy((PATCH.BEFORE_SOURCE, PATCH.AFTER_SOURCE, PATCH.PINS))
        self.temporary = tempfile.TemporaryDirectory(prefix='program-exact-raw-pin-')
        self.base = Path(self.temporary.name)
        self.root, self.package, self.audit = [self.base / name for name in ('live', 'package', 'audit')]
        self.root.mkdir(mode=0o755); self.package.mkdir(mode=0o700); self.audit.mkdir(mode=0o700)
        shutil.copyfile(ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py',
                        self.package / 'refresh_desktop_dashboard_server_manifest.py')
        self.before = {self.NORMAL: b'<?php // old reviewed import\n', self.RAW: b'window.AdminLTE={old:true};\n'}
        self.after = {self.NORMAL: b'<?php // new reviewed import\n', self.RAW: b'window.AdminLTE={attendanceExcluded:true};\n'}
        for name in self.before:
            target = self.root / name; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(self.before[name])
            staged = self.package / 'application' / name; staged.parent.mkdir(parents=True, exist_ok=True); staged.write_bytes(self.after[name])
        (self.root / 'app').mkdir(); (self.root / 'app/keep.php').write_bytes(b'<?php // unchanged source\n')
        self.scanner = PRIOR_TESTS.SCANNER
        self.before_map = self.scanner.scan(self.root)
        self.after_map = copy.deepcopy(self.before_map)
        self.after_map[self.NORMAL] = self.scanner.file_record(self.package / 'application' / self.NORMAL)
        pins = {name: (PATCH.blob(self.before[name]), PATCH.blob(self.after[name])) for name in self.before}
        PATCH.configure(self.scanner.digest(self.before_map), self.scanner.digest(self.after_map), pins)
        self.manifest_value = {'version': 1, 'expected_head': 'reviewed-history', 'before_source': {'retained': True},
            'after_source': PATCH.BEFORE_SOURCE,
            'sources': [{'path': self.NORMAL, 'before_blob': None, 'after_blob': pins[self.NORMAL][0], 'phase': 2}],
            'protected': [{'path': self.RAW, 'blob': pins[self.RAW][0]},
                          {'path': 'app/keep.php', 'blob': self.before_map['app/keep.php']['blob']}],
            'migrations': ['unchanged-migration-history']}
        (self.root / 'deployment').mkdir(); (self.root / PATCH.MANIFEST).write_text(json.dumps(self.manifest_value))
        self.manifest_before = (self.root / PATCH.MANIFEST).read_bytes()
        self.inodes = {name: (self.root / name).stat().st_ino for name in (*self.before, PATCH.MANIFEST)}
        self.lock_fd = os.open(self.base / 'watcher.lock', os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
        self.patch = PATCH.ApplicationPatch(self.root, self.package, self.audit, 0, 0, self.lock_fd)

    def tearDown(self):
        if self.patch.stage_fd is not None: self.patch._cleanup()
        else: self.patch.close()
        os.close(self.lock_fd)
        PATCH.BEFORE_SOURCE, PATCH.AFTER_SOURCE, PATCH.PINS = self.original_config
        self.temporary.cleanup()

    def test_exact_raw_pin_applies_and_rolls_back_with_original_inodes_without_entering_fingerprint(self):
        self.assertNotIn(self.RAW, self.before_map); self.assertFalse(self.scanner.selected(self.RAW))
        unchanged = self.before_map['app/keep.php']
        PATCH.verify_before(self.root, self.package)
        self.patch.prepare(); self.assertNotIn(self.RAW, self.patch.after_map)
        self.assertEqual(self.patch.after_map['app/keep.php'], unchanged)
        self.patch.apply(); self.patch.verify()
        self.assertEqual(self.scanner.scan(self.root), self.after_map)
        self.assertTrue(PATCH.verify_current(self.root, self.package, 0, 0, self.lock_fd)['already_current'])
        manifest = json.loads((self.root / PATCH.MANIFEST).read_bytes())
        self.assertEqual(manifest['before_source'], self.manifest_value['before_source'])
        self.assertEqual(manifest['expected_head'], self.manifest_value['expected_head'])
        self.assertEqual(manifest['migrations'], self.manifest_value['migrations'])
        self.assertEqual([row for row in manifest['sources'] if row['path'] == self.RAW],
                         [{'path': self.RAW, 'before_blob': PATCH.PINS[self.RAW][0], 'after_blob': PATCH.PINS[self.RAW][1], 'phase': 2}])
        self.assertEqual(manifest['protected'], [self.manifest_value['protected'][1]])
        self.patch.rollback(); self.assertTrue(self.patch.rollback())
        for name, data in self.before.items():
            self.assertEqual((self.root / name).read_bytes(), data)
            self.assertEqual((self.root / name).stat().st_ino, self.inodes[name])
        self.assertEqual((self.root / PATCH.MANIFEST).read_bytes(), self.manifest_before)
        self.assertEqual((self.root / PATCH.MANIFEST).stat().st_ino, self.inodes[PATCH.MANIFEST])

    def test_commit_and_idempotent_check_verify_raw_bytes_even_when_manifest_is_absent(self):
        (self.root / PATCH.MANIFEST).unlink(); (self.root / 'deployment').rmdir()
        self.patch.prepare(); self.patch.apply(); self.patch.commit()
        self.assertNotIn(self.RAW, self.scanner.scan(self.root))
        self.assertTrue(PATCH.verify_current(self.root, self.package, 0, 0, self.lock_fd)['manifest_preserved_absent'])
        (self.root / self.RAW).write_bytes(b'window.unreviewed=true;\n')
        self.assertEqual(self.scanner.scan(self.root), self.after_map)
        with self.assertRaisesRegex(PATCH.Stop, 'AFTER_FILE_PIN_INVALID'):
            PATCH.verify_current(self.root, self.package, 0, 0, self.lock_fd)

    def test_readonly_and_prepare_checks_reject_raw_tampering_without_a_fingerprint_change(self):
        (self.root / self.RAW).write_bytes(b'window.unreviewed=true;\n')
        self.assertEqual(self.scanner.scan(self.root), self.before_map)
        with self.assertRaisesRegex(PATCH.Stop, 'BEFORE_FILE_UNREVIEWED'): PATCH.verify_before(self.root, self.package)
        with self.assertRaisesRegex(PATCH.Stop, 'BEFORE_FILE_UNREVIEWED'): self.patch.prepare()
        self.assertEqual((self.root / self.NORMAL).stat().st_ino, self.inodes[self.NORMAL])

    def test_all_raw_targets_are_checked_before_the_first_exchange(self):
        self.patch.prepare(); (self.root / self.RAW).write_bytes(b'window.concurrentWriter=true;\n')
        self.assertEqual(self.scanner.scan(self.root), self.before_map)
        with self.assertRaisesRegex(PATCH.Stop, 'TARGET_CHANGED'): self.patch.apply()
        self.assertTrue(all(not slot['moved'] for slot in self.patch.slots))
        self.assertEqual((self.root / self.NORMAL).stat().st_ino, self.inodes[self.NORMAL])
        self.assertEqual((self.root / self.NORMAL).read_bytes(), self.before[self.NORMAL])

    def test_raw_symlink_and_file_swaps_are_rejected_during_descriptor_reads(self):
        raw = self.root / self.RAW; outside = self.base / 'outside.js'; outside.write_bytes(self.before[self.RAW])
        raw.unlink(); raw.symlink_to(outside)
        with self.assertRaises(OSError): PATCH.verify_before(self.root, self.package)
        raw.unlink(); raw.write_bytes(self.before[self.RAW])
        original_read = PATCH.os.read; swapped = False
        def concurrent_read(fd, size):
            nonlocal swapped
            data = original_read(fd, size)
            if not swapped and os.readlink('/proc/self/fd/' + str(fd)) == str(raw):
                swapped = True; raw.unlink(); raw.write_bytes(self.before[self.RAW])
            return data
        with mock.patch.object(PATCH.os, 'read', side_effect=concurrent_read):
            with self.assertRaisesRegex(PATCH.Stop, 'SOURCE_FILE_CHANGED'): PATCH.verify_before(self.root, self.package)
        self.assertTrue(swapped)

    def test_repeated_rollback_still_checks_a_raw_asset_outside_the_source_digest(self):
        self.patch.prepare(); self.patch.apply(); self.patch.rollback()
        (self.root / self.RAW).write_bytes(b'window.writerAfterRollback=true;\n')
        with self.assertRaisesRegex(PATCH.Stop, 'ROLLBACK_FILE_DIFFERS'): self.patch.rollback()


if __name__ == '__main__': unittest.main()
