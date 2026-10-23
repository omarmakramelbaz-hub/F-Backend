"""Immutable final package, predecessor blobs and full source fingerprint proof."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value
M = load('program_release_adapter_identity', ROOT / 'deployment/upgrade_go_erp_program_watcher_root.py')
PATCH = load('program_release_application_identity', ROOT / 'deployment/go_erp_program_application_patch.py')
SCANNER = load('program_release_scanner_identity', ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py')


def git_bytes(revision, path):
    result = subprocess.run(['git', '-c', 'safe.directory=' + str(ROOT), 'show', revision + ':' + path],
                            cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    return result.stdout if result.returncode == 0 else None


class FinalIdentityTests(unittest.TestCase):
    def test_candidate_seed_retains_exact_core_desktop_fixture_workflow(self):
        # Read immutable commit bytes: shallow CI fetches deliberately do not
        # expose parent traversal through revision-walk formatting.
        commit = subprocess.check_output(['git', 'cat-file', '-p', M.NEW_SEED], cwd=ROOT, text=True)
        parents = [line.split()[1] for line in commit.split('\n\n', 1)[0].splitlines() if line.startswith('parent ')]
        self.assertEqual(parents, [M.CORE])
        workflow = '.github/workflows/desktop-dashboard-tests.yml'
        core = git_bytes(M.CORE, workflow)
        self.assertIsNotNone(core)
        self.assertEqual(git_bytes(M.NEW_SEED, workflow), core)
        self.assertIn(b'employee-identity-http.php', core)
        self.assertIn(b'original-employee-identity-windows', core)
        self.assertIn(b'helperBinding', core)
        update_workflow = '.github/workflows/go-erp-update-tests.yml'
        expected_update = git_bytes(M.CORE, update_workflow)
        prior_core = b'04950fe07bbbb69a759bb6eace79bf32c7a1a500'
        self.assertEqual(expected_update.count(prior_core), 1)
        expected_update = expected_update.replace(prior_core, M.CORE.encode('ascii'))
        seed_update = git_bytes(M.NEW_SEED, update_workflow)
        self.assertEqual(seed_update, expected_update)
        self.assertIn(b'test_go_erp_preview31_seed.py', seed_update)
        self.assertIn(b'origin '+M.CORE.encode('ascii'), seed_update)
    def test_final_package_pins_are_complete_and_source_is_exact(self):
        self.assertRegex(M.CORE, r'\A[a-f0-9]{40}\Z'); self.assertNotEqual(M.CORE, '0' * 40)
        self.assertRegex(M.NEW_SEED, r'\A[a-f0-9]{40}\Z'); self.assertNotEqual(M.NEW_SEED, '0' * 40)
        self.assertNotEqual(M.NEW_SEED, M.CORE)
        self.assertRegex(M.NEW_INVENTORY, r'\A[a-f0-9]{64}\Z'); self.assertNotEqual(M.NEW_INVENTORY, '0' * 64)
        self.assertTrue(M.TARGET_PINS)
        self.assertEqual(M.blob((ROOT / 'deployment' / M.APPLICATION_MODULE).read_bytes()), M.APPLICATION_BLOB)
        self.assertEqual(M.blob((ROOT / 'deployment' / M.GATE_MODULE).read_bytes()), M.GATE_BLOB)
        self.assertEqual(M.blob((ROOT / 'deployment' / M.EMPLOYEE_MODULE).read_bytes()), M.EMPLOYEE_MODULE_BLOB)
        self.assertEqual(M.blob((ROOT / 'deployment/verify_go_erp_employee_schema.php').read_bytes()), M.EMPLOYEE_PROBE_BLOB)
        self.assertEqual(M.blob((ROOT / M.MIGRATION_PATH).read_bytes()), M.EMPLOYEE_MIGRATION_BLOB)
        self.assertEqual(M.blob((ROOT / 'deployment/import422-prior' / M.BASE_MODULE).read_bytes()), M.BASE_BLOB)
        self.assertEqual(SCANNER.digest(SCANNER.scan(ROOT)), M.NEW_SOURCE)
        for name, target in M.TARGET_PINS.items():
            self.assertEqual(M.blob((ROOT / name).read_bytes()), target, name)
        original = json.loads(git_bytes(M.COHORTS['preview35']['reference'], M.VERSIONS[2]))['inventory']
        for name in M.TARGET_PINS:
            data = (ROOT / name).read_bytes()
            mode = subprocess.check_output(['git', 'ls-tree', M.CORE, '--', name], cwd=ROOT, text=True).split()[0]
            original[name] = {'authority': 'live', 'present': True, 'mode': mode,
                              'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        canonical = json.dumps(original, ensure_ascii=False, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()
        self.assertEqual(hashlib.sha256(canonical).hexdigest(), M.NEW_INVENTORY)
        for cohort_name, cohort in M.COHORTS.items():
            self.assertEqual(set(M.BEFORE_PINS[cohort_name]), set(M.TARGET_PINS))
            for name, before in M.BEFORE_PINS[cohort_name].items():
                data = git_bytes(cohort['reference'], name)
                self.assertEqual(M.blob(data) if data is not None else None, before, (cohort_name, name))


@unittest.skipUnless(os.geteuid() == 0, 'real root-owned inode exchanges')
class FinalCohortExchangeTests(unittest.TestCase):
    def test_exact_target_roster_applies_and_rolls_back_from_both_predecessors(self):
        self.assertTrue(M.TARGET_PINS, 'final release pins required')
        for cohort_name, cohort in M.COHORTS.items():
            with self.subTest(cohort=cohort_name), tempfile.TemporaryDirectory(prefix='program-cohort-exchange-') as tmp:
                base = Path(tmp); root, package, audit = base / 'live', base / 'package', base / 'audit'
                root.mkdir(mode=0o755); package.mkdir(mode=0o700); audit.mkdir(mode=0o700)
                shutil.copyfile(ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py',
                                package / 'refresh_desktop_dashboard_server_manifest.py')
                pairs, originals = {}, {}
                for name, target in M.TARGET_PINS.items():
                    before = M.BEFORE_PINS[cohort_name][name]
                    if before == target: continue
                    pairs[name] = (before, target)
                    live = root / name; live.parent.mkdir(parents=True, exist_ok=True)
                    if before is not None:
                        data = git_bytes(cohort['reference'], name)
                        self.assertEqual(M.blob(data), before); live.write_bytes(data); originals[name] = data
                    staged = package / 'application' / name; staged.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(ROOT / name, staged)
                self.assertTrue(pairs)
                (root / 'artisan').write_text('<?php // fixture outside target roster\n')
                before_map = SCANNER.scan(root); after_map = copy.deepcopy(before_map)
                for name in pairs:
                    if SCANNER.selected(name): after_map[name] = SCANNER.file_record(ROOT / name)
                PATCH.configure(SCANNER.digest(before_map), SCANNER.digest(after_map), pairs)
                lock_fd = os.open(base / 'watcher.lock', os.O_RDWR | os.O_CREAT | os.O_EXCL, 0o600)
                patch = PATCH.ApplicationPatch(root, package, audit, 0, 0, lock_fd)
                inodes = {name: (root / name).stat().st_ino for name in originals}
                try:
                    PATCH.verify_before(root, package)
                    patch.prepare(); patch.apply(); patch.verify()
                    for name in pairs: self.assertEqual((root / name).read_bytes(), (ROOT / name).read_bytes())
                    patch.rollback()
                    for name, (before, _) in pairs.items():
                        if before is None: self.assertFalse((root / name).exists())
                        else:
                            self.assertEqual((root / name).read_bytes(), originals[name])
                            self.assertEqual((root / name).stat().st_ino, inodes[name])
                finally:
                    if patch.stage_fd is not None: patch._cleanup()
                    os.close(lock_fd)


if __name__ == '__main__': unittest.main()
