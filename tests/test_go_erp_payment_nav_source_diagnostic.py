"""Hash-only known source drift, exact baseline normalization, and read-only flow."""
import ast
import contextlib
import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import types
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class SourceDiagnosticTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = module('source_diagnostic_tests', ROOT / 'deployment/diagnose_go_erp_payment_nav_source.py')
        cls.e = module('source_diagnostic_entry', ROOT / 'deployment/upgrade_go_erp_payment_nav_successor_root.py')
        cls.p = module('source_diagnostic_prior', ROOT / 'deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py')
        cls.s = module('source_diagnostic_scanner', ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py')
        cls.exporter = module('source_diagnostic_metadata_exporter', ROOT / 'deployment/wire-hash-prior/payload/go_erp_live_source.py')
        cls.original = cls.s.scan(ROOT)
        if cls.s.digest(cls.original) != cls.e.OLD_SOURCE:
            raise AssertionError('Test repository must remain the exact immutable prepared cohort')

    def setUp(self):
        self.entry = types.SimpleNamespace(**vars(self.e))
        self.inventory = {name: {'sha256': row['sha256'], 'present': True, 'authority': 'live'}
                          for name, row in self.original.items()}
        # Candidate inventories hash the original bytes. The scanner hashes
        # normalized bytes; only these exact20 immutable pairs bridge them.
        for name, (raw, normalized) in self.m.NORMALIZED_BASELINE_PINS.items():
            self.assertEqual(self.inventory[name]['sha256'], normalized)
            self.inventory[name]['sha256'] = raw
        self.inventory['public/fixture-unselected.bin'] = {'sha256': 'a' * 64, 'present': True, 'authority': 'live'}
        self.snapshot = self.exporter._metadata(self.entry.SEED, self.inventory)
        self.metadata = self.exporter._candidate_metadata(self.snapshot, self.entry.OLD_VERSION, 36)
        self.entry.OLD_INVENTORY = self.snapshot['inventorySha256']
        self.git_calls = []
        def git(path, *args):
            self.git_calls.append(args)
            self.assertEqual(args, ('show', self.entry.OLD_CANDIDATE + ':' + self.p.VERSIONS[2]))
            return json.dumps(self.metadata).encode()
        self.ctx = types.SimpleNamespace(base=types.SimpleNamespace(git=git, parse=json.loads), prior=self.p)

    def baselines(self):
        return self.m.source_baselines(self.ctx, self.entry, self.s)

    def test_independent_exporter_metadata_proves_three_known_source_fingerprints(self):
        before, payment, later = self.baselines()
        self.assertEqual(self.s.digest(before), self.entry.OLD_SOURCE)
        self.assertEqual(self.s.digest(payment), self.entry.BEFORE_SOURCE)
        self.assertEqual(self.s.digest(later), self.m.LATER_SOURCE)
        self.assertEqual((len(before), len(payment), len(later)), (1342, 1342, 1346))
        self.assertEqual(len(self.git_calls), 1)
        self.assertNotIn('public/fixture-unselected.bin', before)

    def test_baseline_metadata_or_raw_normalization_pin_tampering_is_refused(self):
        self.metadata['inventorySha256'] = 'e' * 64
        with self.assertRaises(self.m.DiagnosticStop): self.baselines()
        name = next(iter(self.m.NORMALIZED_BASELINE_PINS))
        self.inventory[name]['sha256'] = self.m.NORMALIZED_BASELINE_PINS[name][1]
        self.snapshot = self.exporter._metadata(self.entry.SEED, self.inventory)
        self.metadata = self.exporter._candidate_metadata(self.snapshot, self.entry.OLD_VERSION, 36)
        self.entry.OLD_INVENTORY = self.snapshot['inventorySha256']
        with self.assertRaises(self.m.DiagnosticStop): self.baselines()

    def test_payment_or_later_overlay_cannot_reinterpret_an_unknown_old_hash(self):
        name = next(iter(self.m.PAYMENT_AFTER))
        with mock.patch.dict(self.m.PAYMENT_AFTER, {name: 'e' * 64}):
            with self.assertRaises(self.m.DiagnosticStop): self.baselines()
        name = next(iter(self.m.LATER_PINS))
        _, after, pin = self.m.LATER_PINS[name]
        with mock.patch.dict(self.m.LATER_PINS, {name: ('f' * 64, after, pin)}):
            with self.assertRaises(self.m.DiagnosticStop): self.baselines()

    def test_only_reviewed_names_and_hashes_appear_unknown_filename_is_counted(self):
        before, payment, later = self.baselines()
        actual = copy.deepcopy(later)
        private_name = 'app/Services/private-account@example.test.php'
        actual[private_name] = {'sha256': 'd' * 64, 'contents': 'private-account-password'}
        result = self.m.compare_sources(before, payment, later, actual)
        self.assertEqual(result['selected_difference_count'], 14)
        self.assertEqual(result['unknown_selected_file_count'], 1)
        self.assertTrue(all(row['matches_later_release'] for row in result['selected_differences']))
        self.assertEqual({row['path'] for row in result['selected_differences']}, set(self.m.LATER_PINS))
        route = next(row for row in result['selected_differences'] if row['path'] == 'routes/api.php')
        self.assertEqual(route['prepared_sha256'], self.m.LATER_PINS['routes/api.php'][0])
        self.assertEqual(route['actual_sha256'], self.m.LATER_PINS['routes/api.php'][1])
        public = json.dumps(result)
        self.assertNotIn(private_name, public)
        self.assertNotIn('private-account-password', public)

    def test_diff_truncation_preserves_full_counts_and_missing_known_paths(self):
        before, payment, later = self.baselines()
        actual = copy.deepcopy(later)
        actual.pop('routes/api.php')
        with mock.patch.object(self.m, 'MAX_ROWS', 1):
            result = self.m.compare_sources(before, payment, later, actual)
        self.assertEqual(result['selected_difference_count'], 14)
        self.assertTrue(result['selected_differences_truncated'])
        self.assertEqual(len(result['selected_differences']), 1)
        result = self.m.compare_sources(before, payment, later, actual)
        route = next(row for row in result['selected_differences'] if row['path'] == 'routes/api.php')
        self.assertEqual(route['status'], 'missing')
        self.assertIsNone(route['actual_sha256'])
        self.assertFalse(route['matches_later_release'])

    def target_fixture(self):
        parent = self
        class PrivateError(Exception):
            def __str__(self): raise AssertionError('never stringify read failures')
        sentinel = b'SYNTHETIC_LATER_ROUTE_BLOB'
        names = sorted(self.p.TARGET_PINS)
        missing, unreadable = next(name for name in names if name != 'routes/api.php'), names[-1]
        class Reader:
            def __init__(self, *args): pass
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def _read(self, name):
                parent.assertIn(name, parent.p.TARGET_PINS)
                if name == unreadable: raise PrivateError('private-account-path')
                if name == missing: return None, None
                if name == 'routes/api.php': return sentinel, None
                return (ROOT / name).read_bytes(), None
        self.ctx.application = types.SimpleNamespace(PinnedSourceReader=Reader)
        self.ctx.owner = types.SimpleNamespace(pw_uid=0)
        original_blob = self.m.blob
        return mock.patch.object(self.m, 'blob', side_effect=lambda data:
            self.m.LATER_PINS['routes/api.php'][2] if data == sentinel else original_blob(data))

    def test_all42_prepared_paths_remain_fixed_and_known_later_route_is_classified(self):
        with self.target_fixture():
            result = self.m.prepared_targets(self.ctx, self.entry)
        self.assertEqual(result['prepared_target_count'], 42)
        self.assertEqual(result['prepared_target_matches'], 39)
        self.assertEqual(len(result['prepared_target_differences']), 3)
        route = next(row for row in result['prepared_target_differences'] if row['path'] == 'routes/api.php')
        self.assertEqual(route['prepared_blob'], self.p.TARGET_PINS['routes/api.php'])
        self.assertEqual(route['actual_blob'], self.m.LATER_PINS['routes/api.php'][2])
        self.assertIs(route['matches_later_release'], True)
        self.assertNotIn('private-account-path', json.dumps(result))
        self.assertTrue(all(row['path'] in self.p.TARGET_PINS for row in result['prepared_target_differences']))

    def test_run_failure_never_discloses_exception_text_or_private_class(self):
        class PrivateAccountError(Exception):
            def __str__(self): raise AssertionError('must not stringify')
        with mock.patch.object(self.m.socket, 'gethostname', return_value='srv1836524'), \
             mock.patch.object(self.m, 'read_entry', side_effect=PrivateAccountError('private-account-data')):
            result = self.m.run()
        self.assertFalse(result['ok'])
        self.assertEqual(result['failed_step'], 'pinned-context')
        self.assertEqual(result['reason'], 'READ_ONLY_CHECK_FAILED')
        self.assertNotIn('private-account', json.dumps(result))
        self.assertNotIn('PrivateAccountError', json.dumps(result))
        for key in ('application_writes', 'database_accessed', 'service_changed',
                    'candidate_fetch_performed', 'credentials_disclosed'):
            self.assertIs(result[key], False)

    def test_callgraph_has_no_fetch_or_mutation_and_git_reads_only_committed_metadata(self):
        tree = ast.parse((ROOT / 'deployment/diagnose_go_erp_payment_nav_source.py').read_bytes())
        forbidden = {'execute', 'transaction', 'candidate', 'state_guard', 'ApplicationPatch',
                     'apply', 'commit', 'rollback', 'write_at', 'json_at', 'program_gate_write',
                     'program_gate_authorize', 'program_gate_retire', 'fetch', 'save', 'upgrade'}
        for node in ast.walk(tree):
            if not isinstance(node, ast.Call): continue
            name = node.func.id if isinstance(node.func, ast.Name) else node.func.attr if isinstance(node.func, ast.Attribute) else None
            self.assertNotIn(name, forbidden)
            if name == 'git': self.assertEqual(ast.literal_eval(node.args[1]), 'show')


@unittest.skipUnless(os.geteuid() == 0, 'actual root-owned pinned read')
class SourceDiagnosticPinnedReadTests(unittest.TestCase):
    def test_retained_entry_pin_read_is_readonly_and_does_not_change_inode_or_bytes(self):
        m = SourceDiagnosticTests.m if hasattr(SourceDiagnosticTests, 'm') else module(
            'source_diag_pin_test', ROOT / 'deployment/diagnose_go_erp_payment_nav_source.py')
        data = (ROOT / 'deployment' / m.ENTRY).read_bytes()
        self.assertEqual(m.blob(data), m.ENTRY_PIN)
        with tempfile.TemporaryDirectory(prefix='go-erp-source-read-', dir='/root') as name:
            package = Path(name)
            path = package / m.ENTRY
            path.write_bytes(data); path.chmod(0o644)
            before = (path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns)
            original = os.open
            def opened(path, flags, *args, **kwargs):
                self.assertEqual(flags & os.O_ACCMODE, os.O_RDONLY)
                self.assertFalse(flags & (os.O_CREAT | os.O_TRUNC | os.O_APPEND))
                return original(path, flags, *args, **kwargs)
            with mock.patch.object(m.os, 'open', side_effect=opened), \
                 mock.patch.object(m.os, 'write', side_effect=AssertionError('must not write')):
                entry = m.read_entry(package)
            self.assertEqual(entry.OLD_CANDIDATE, 'dceb59ad489bbba6b6534a4da784f6d26c82b2ff')
            self.assertEqual((path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns), before)


if __name__ == '__main__': unittest.main()
