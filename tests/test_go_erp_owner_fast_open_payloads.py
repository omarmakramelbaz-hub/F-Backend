"""The new immutable collector retains every original release and ZIP gate."""
import ast
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import types
import unittest

import test_go_erp_navigation_actual as actual
import test_go_erp_navigation_zip_compat_root as compatibility

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('owner_fast_open_payloads',
    ROOT / 'deployment/go_erp_owner_fast_open_payloads.py')
subject = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(subject)


def original_payload():
    template = actual.subject.configure(actual.subject.load_template(actual.rollout_tests.TEMPLATE))
    return template.derive_native_payloads(actual.reference.NEW,
        actual.reference.tick.NEW_COHORT['pins'], actual.subject.SEED)['go_erp_live_updates.py']


def frozen(raw):
    value = types.ModuleType('owner_fast_open_immutable_worker')
    value.__file__ = '<reviewed-owner-fast-open-worker>'
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    return value


class PayloadDerivationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old = original_payload()
        cls.new_input = cls.old.replace(subject.OLD_SEED.encode(), b'c' * 40).replace(
            subject.OLD_SUFFIX.encode(), subject.NEW_SUFFIX.encode())
        cls.new = subject.transform(cls.new_input)

    def fixture(self):
        value = compatibility.ExistingArtifactCompatibilityTests(
            'test_actual_original_verification_then_strict_installed_proof_accepts_minimal_parent_run')
        value.setUp()
        self.addCleanup(value.doCleanups)
        value.worker = frozen(self.new)
        value.original_proof_fixture()
        return value

    def test_input_is_exact_reviewed_worker_apart_from_original_cohort_rebinding(self):
        self.assertEqual(hashlib.sha256(self.old).hexdigest(), subject.OLD_WORKER_SHA256)
        self.assertEqual(subject._normalized_worker(self.old), self.old)
        self.assertEqual(subject._normalized_worker(self.new_input), self.old)
        self.assertEqual(frozen(self.new).PREVIEW31_SEED, 'c' * 40)
        self.assertTrue(frozen(self.new).PREVIEW31_POLICY.endswith(subject.NEW_SUFFIX))
        self.assertEqual(self.new_input.count(b'c' * 40), 1)
        for raw in (self.old + b'\n', self.old.replace(b'POLL_SECONDS = 60', b'POLL_SECONDS = 61'),
                self.old.replace(subject.OLD_SUFFIX.encode(), subject.OLD_SUFFIX[:-1].encode() + b'6'),
                self.new, b'', None):
            with self.subTest(kind=type(raw).__name__), self.assertRaises(subject.Stop):
                subject.transform(raw)

    def test_only_roster_and_one_extra_verification_are_added_to_original_code(self):
        def definitions(raw):
            source = raw.decode()
            return {node.name: ast.get_source_segment(source, node)
                for node in ast.parse(source).body
                if isinstance(node, (ast.FunctionDef, ast.ClassDef))}
        old, new = definitions(self.new_input), definitions(self.new)
        self.assertEqual(set(new), set(old) | {'verify_installed_startup_source'})
        for name in old:
            if name == 'verify_proof':
                self.assertEqual(new[name], old[name].replace('    return receipt, release',
                    '    verify_installed_startup_source(folder, desired)\n    return receipt, release'))
            else:
                self.assertEqual(new[name], old[name], name)
        original, updated = frozen(self.old), frozen(self.new)
        self.assertEqual(updated.FILES,
            {**original.FILES, subject.PROOF: subject.PROOF_LIMIT})
        self.assertEqual(updated.MAX_ARCHIVE, original.MAX_ARCHIVE)
        self.assertEqual(len(updated.FILES), 11)
        self.assertEqual(subject.transform(self.new_input), self.new)

    def test_exact_installed_proof_accepts_original_full_verifier_and_parent_minimal_run(self):
        fixture = self.fixture()
        receipt, release = fixture.worker.verify_proof(fixture.folder,
            {'id': subject_version_run(), 'run_attempt': 1}, fixture.desired, fixture.publisher)
        self.assertEqual(receipt['installer'], fixture.release['installer'])
        self.assertEqual(release, fixture.release)
        self.assertEqual(len(fixture.installer_calls), 1)
        self.assertTrue(fixture.installer_calls[0][1]['root_owned'])

    def test_new_receipt_is_bound_to_each_desired_candidate_not_fixed_59(self):
        fixture = self.fixture()
        fixture.proof['sourceRevision'] = 'd' * 40
        fixture.write(subject.PROOF, fixture.proof)
        fixture.worker.verify_installed_startup_source(fixture.folder, {'commit': 'd' * 40})
        for desired in ({'commit': compatibility.subject.CANDIDATE}, {'commit': 'not-a-revision'},
                {'commit': 1}, {}):
            with self.subTest(desired=desired), self.assertRaisesRegex(
                    fixture.worker.WatchError, 'CI_INSTALLED_STARTUP_PROOF_INVALID'):
                fixture.worker.verify_installed_startup_source(fixture.folder, desired)

    def test_schema_booleans_source_hashes_and_actual_production_claims_are_exact(self):
        fixture = self.fixture()
        self.assertEqual(len(fixture.proof), 29)
        cases = []
        for name in subject.TRUE_FLAGS:
            row = copy.deepcopy(fixture.proof); row[name] = False; cases.append(row)
            row = copy.deepcopy(fixture.proof); row[name] = 1; cases.append(row)
        for name in subject.FALSE_FLAGS:
            row = copy.deepcopy(fixture.proof); row[name] = True; cases.append(row)
            row = copy.deepcopy(fixture.proof); row[name] = 0; cases.append(row)
        for name, value in [('format', True), ('kind', 'other'), ('installedAsarCode', False)]:
            row = copy.deepcopy(fixture.proof); row[name] = value; cases.append(row)
        for name in ('helper', 'fixture', 'nativeModule'):
            row = copy.deepcopy(fixture.proof); row['sourceHashes'][name] = '0' * 64; cases.append(row)
        row = copy.deepcopy(fixture.proof); row['sourceHashes']['other'] = 'a' * 64; cases.append(row)
        row = copy.deepcopy(fixture.proof); del row['completed']; cases.append(row)
        row = copy.deepcopy(fixture.proof); row['other'] = True; cases.append(row)
        for index, row in enumerate(cases):
            fixture.write(subject.PROOF, row)
            with self.subTest(index=index), self.assertRaisesRegex(
                    fixture.worker.WatchError, 'CI_INSTALLED_STARTUP_PROOF_INVALID'):
                fixture.worker.verify_installed_startup_source(fixture.folder, fixture.desired)

    def test_original_native_and_installer_gates_run_before_new_receipt(self):
        fixture = self.fixture()
        (fixture.folder / subject.PROOF).unlink()
        fixture.write('connected-request-native.json', {'format': 1,
            'sourceRevision': compatibility.subject.CANDIDATE, 'platform': 'win32',
            'hardware': False, 'completed': False})
        with self.assertRaisesRegex(fixture.worker.WatchError, 'CI_NATIVE_PROOF_INCOMPLETE'):
            fixture.worker.verify_proof(fixture.folder, fixture.run, fixture.desired, fixture.publisher)
        self.assertEqual(fixture.installer_calls, [])
        fixture.original_proof_fixture()
        (fixture.folder / subject.PROOF).unlink()
        with self.assertRaises(FileNotFoundError):
            fixture.worker.verify_proof(fixture.folder, fixture.run, fixture.desired, fixture.publisher)
        self.assertEqual(len(fixture.installer_calls), 1)

    def test_original_extractor_accepts_exact_eleven_files_and_rejects_unknown_entry(self):
        fixture = self.fixture()
        archive = fixture.archive()
        destination = fixture.root / 'accepted'; destination.mkdir()
        fixture.worker.extract_artifact(archive, destination)
        self.assertEqual({str(path.relative_to(destination)) for path in destination.rglob('*')
            if path.is_file()}, set(fixture.worker.FILES))
        archive = fixture.archive(extra=('unknown.json', b'x'))
        destination = fixture.root / 'rejected'; destination.mkdir()
        with self.assertRaisesRegex(fixture.worker.WatchError, 'ZIP_FILE_INVALID'):
            fixture.worker.extract_artifact(archive, destination)

    def test_original_json_and_file_size_guards_protect_new_receipt(self):
        fixture = self.fixture()
        raw = json.dumps(fixture.proof).encode().replace(b'"format": 1', b'"format": 1, "format": 1')
        fixture.write(subject.PROOF, raw)
        with self.assertRaisesRegex(fixture.worker.WatchError, 'DUPLICATE_JSON_FIELD'):
            fixture.worker.verify_installed_startup_source(fixture.folder, fixture.desired)
        fixture.write(subject.PROOF, b'x' * (subject.PROOF_LIMIT + 1))
        with self.assertRaisesRegex(fixture.worker.WatchError, 'FILE_UNSAFE'):
            fixture.worker.verify_installed_startup_source(fixture.folder, fixture.desired)


def subject_version_run():
    return compatibility.subject.RUN_ID


if __name__ == '__main__':
    unittest.main()
