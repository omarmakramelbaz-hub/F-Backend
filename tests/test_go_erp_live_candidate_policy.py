#!/usr/bin/env python3
"""Exercise the emitted candidate and its independent CI verifier, without network."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


fixture = load('policy_source_fixture', ROOT / 'tests/test_go_erp_live_source.py')
source = fixture.source
validator = load('current_candidate_verifier', ROOT / 'deployment/verify_go_erp_current_candidate_ci.py')


@unittest.skipUnless(os.geteuid() == 0, 'root-owned isolated candidate contract')
class CandidatePolicyTests(unittest.TestCase):
    git = fixture.LiveSourceTests.git
    write = staticmethod(fixture.LiveSourceTests.write)
    scan = fixture.LiveSourceTests.scan

    def setUp(self):
        fixture.LiveSourceTests.setUp(self)
        # The historical repo supplies immutable helper objects, not imports
        # from the current candidate or from its mutable checkout files.
        for name in ('go_erp_live_source', 'refresh_desktop_dashboard_server_manifest'):
            raw = subprocess.check_output(['git', '-c', 'safe.directory=' + str(ROOT), '-C', str(ROOT), 'show',
                validator.BASE_REVISION + ':deployment/' + name + '.py'])
            self.write(self.seed, 'deployment/' + name + '.py', raw)
        self.git('add', 'deployment')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
                 'commit', '-qm', 'pinned historical helpers')
        self.revision = self.git('rev-parse', 'HEAD').decode().strip()
        self.historical = self.base / 'historical'
        subprocess.run(['git', 'clone', '--quiet', '--no-local', str(self.seed),
                        str(self.historical)], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.snapshot = self.scan()

    def prepare(self):
        return source.prepare_candidate(self.seed, self.snapshot, 7)

    def verify(self):
        return validator.validate_checkout(self.seed, self.historical, expected_base=self.revision)

    def amend(self):
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
                 'commit', '--amend', '--no-edit', '-q')

    def test_real_candidate_policy_is_outside_application_authority(self):
        inventory = copy.deepcopy(self.snapshot['metadata'])
        result = self.prepare()
        self.assertEqual(result['candidatePolicy'], 'historical-baseline-v1')
        self.assertEqual(self.snapshot['metadata'], inventory)
        candidate = json.loads((self.seed / source.CANDIDATE_PATH).read_bytes())
        self.assertEqual(candidate['inventory'], inventory['inventory'])
        self.assertEqual(candidate['inventorySha256'], inventory['inventorySha256'])
        for destination, (name, digest) in source.POLICY_ASSETS.items():
            expected = (ROOT / 'deployment' / name).read_bytes()
            self.assertEqual(hashlib.sha256(expected).hexdigest(), digest)
            self.assertEqual((self.seed / destination).read_bytes(), expected)
            self.assertEqual(self.git('show', result['commit'] + ':' + destination), expected)
            self.assertNotIn(destination, candidate['inventory'])
        receipt = self.verify()
        self.assertEqual(receipt['revision'], result['commit'])
        self.assertEqual(receipt['inventory_sha256'], inventory['inventorySha256'])
        self.assertEqual(receipt['policy'], result['candidatePolicy'])
        self.assertEqual(self.prepare()['commit'], result['commit'])
        self.assertEqual((self.seed / 'app/Example.php').read_bytes(), b'<?php // live\n')

    def test_additional_application_change_needs_no_historical_manifest_change(self):
        self.write(self.live, 'app/AdditionalRealFeature.php', b'<?php // arbitrary future update\r\n')
        self.snapshot = self.scan()
        result = self.prepare()
        receipt = self.verify()
        self.assertEqual(receipt['source_files'], len(self.snapshot['_files']))
        self.assertEqual(result['candidatePolicy'], validator.POLICY)
        self.assertEqual((self.historical / 'app/Example.php').read_bytes(), b'<?php // old\n')

    def test_retry_rejects_committed_workflow_tampering(self):
        self.prepare()
        self.write(self.seed, '.github/workflows/go-erp-update-tests.yml', b'name: bypass\n')
        self.amend()
        with self.assertRaisesRegex(source.SourceError, 'CANDIDATE_POLICY'):
            self.prepare()

    def test_retry_rejects_committed_validator_tampering(self):
        self.prepare()
        self.write(self.seed, 'deployment/verify_go_erp_current_candidate_ci.py', b'print("PASS")\n')
        self.amend()
        with self.assertRaisesRegex(source.SourceError, 'CANDIDATE_POLICY'):
            self.prepare()

    def test_retry_rejects_dirty_workflow_bytes(self):
        self.prepare()
        path = self.seed / '.github/workflows/go-erp-update-tests.yml'
        path.write_bytes(path.read_bytes() + b'\n')
        with self.assertRaisesRegex(source.SourceError, 'GIT_DIRTY'):
            self.prepare()

    def test_retry_rejects_executable_policy_asset(self):
        self.prepare()
        (self.seed / 'deployment/verify_go_erp_current_candidate_ci.py').chmod(0o755)
        self.amend()
        with self.assertRaisesRegex(source.SourceError, 'CANDIDATE_POLICY'):
            self.prepare()

    def test_asset_digest_failure_stops_before_candidate_mutation(self):
        bundle = self.base / 'bundle'
        bundle.mkdir()
        module = self.write(bundle, 'go_erp_live_source.py', b'# module identity\n')
        for _, (name, _) in source.POLICY_ASSETS.items():
            self.write(bundle, name, (ROOT / 'deployment' / name).read_bytes())
        (bundle / 'go_erp_current_candidate_ci.yml').write_bytes(b'name: altered\n')
        with patch.object(source, '__file__', str(module)):
            with self.assertRaisesRegex(source.SourceError, 'POLICY_ASSET'):
                self.prepare()
        self.assertEqual(self.git('rev-parse', 'HEAD').decode().strip(), self.revision)
        self.assertFalse((self.seed / source.CANDIDATE_PATH).exists())

    def test_independent_validator_rejects_dirty_application_bytes(self):
        self.prepare()
        self.write(self.seed, 'app/Example.php', b'<?php // not captured\n')
        with self.assertRaisesRegex(validator.CandidateError, 'DIRTY_SOURCE_BYTES'):
            self.verify()

    def test_independent_validator_rejects_dirty_application_mode(self):
        self.prepare()
        (self.seed / 'app/Example.php').chmod(0o755)
        with self.assertRaisesRegex(validator.CandidateError, 'TREE_ROSTER_OR_MODE'):
            self.verify()

    def test_independent_validator_rejects_untracked_application_code(self):
        self.prepare()
        self.write(self.seed, 'app/Invisible.php', b'<?php // absent from inventory\n')
        with self.assertRaisesRegex(validator.CandidateError, 'UNTRACKED_SOURCE_ADDITION'):
            self.verify()

    def test_independent_validator_rejects_dirty_candidate_json(self):
        self.prepare()
        path = self.seed / source.CANDIDATE_PATH
        path.write_bytes(path.read_bytes() + b'\n')
        with self.assertRaisesRegex(validator.CandidateError, 'DIRTY_METADATA_BYTES_OR_MODE'):
            self.verify()

    def test_independent_validator_rejects_dirty_package_json(self):
        self.prepare()
        path = self.seed / 'desktop-pos/package.json'
        path.write_bytes(path.read_bytes() + b'\n')
        with self.assertRaisesRegex(validator.CandidateError, 'DIRTY_METADATA_BYTES_OR_MODE'):
            self.verify()

    def test_independent_validator_rejects_dirty_package_lock(self):
        self.prepare()
        path = self.seed / 'desktop-pos/package-lock.json'
        path.write_bytes(path.read_bytes() + b'\n')
        with self.assertRaisesRegex(validator.CandidateError, 'DIRTY_METADATA_BYTES_OR_MODE'):
            self.verify()

    def test_independent_validator_rejects_forged_retained_trusted_bytes(self):
        self.prepare()
        name = 'public/dashboard/vendor/desktop-external/asset.bin'
        forged = b'forged retained static asset'
        self.write(self.seed, name, forged)
        candidate = json.loads((self.seed / source.CANDIDATE_PATH).read_bytes())
        candidate['inventory'][name].update(bytes=len(forged), sha256=hashlib.sha256(forged).hexdigest())
        metadata = source._metadata(self.revision, candidate['inventory'])
        self.write(self.seed, source.CANDIDATE_PATH,
                   source._canonical(source._candidate_metadata(metadata, candidate['version'], 7)) + b'\n')
        self.amend()
        with self.assertRaisesRegex(validator.CandidateError, 'RETAINED_BASE_MISMATCH'):
            self.verify()

    def test_independent_validator_rejects_different_parent(self):
        self.prepare()
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
                 'commit', '--allow-empty', '-qm', 'unapproved parent')
        with self.assertRaisesRegex(validator.CandidateError, 'CANDIDATE_PARENT'):
            self.verify()

    def test_workflow_preserves_native_acceptance_and_pins_historical_repository(self):
        original = subprocess.check_output(['git', '-c', 'safe.directory=' + str(ROOT), '-C', str(ROOT), 'show',
            validator.BASE_REVISION + ':.github/workflows/go-erp-update-tests.yml']).decode()
        current = (ROOT / 'deployment/go_erp_current_candidate_ci.yml').read_text()
        marker = '      - name: Verify the exact current server source transformation\n'
        original_start = original.index(marker)
        original_end = original.index('\n      - name:', original_start + len(marker))
        replacement_start = current.index('      - name: Check out the immutable historical desktop baseline\n')
        replacement_end = current.index('\n      - name:', current.index('      - name: Verify the exact current live candidate independently\n'))
        self.assertEqual(original[:original_start], current[:replacement_start])
        self.assertEqual(original[original_end:], current[replacement_end:])
        for test in ('actual_baseline', 'expense_rejection_baseline', 'server_install', 'current_source_diagnosis'):
            self.assertEqual(current.count('python3 -B tests/desktop_dashboard_' + test + '_test.py'), 1)
        self.assertIn('ref: ' + validator.BASE_REVISION, current)
        self.assertIn('working-directory: historical-desktop-baseline', current)
        self.assertIn('origin 3b7d53e6021ce825dd6fc56218cb6ce25f72b3b4', current)


if __name__ == '__main__':
    unittest.main()
