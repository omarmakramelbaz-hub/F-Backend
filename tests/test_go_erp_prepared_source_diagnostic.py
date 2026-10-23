"""Reviewed public hashes only; no control/account or mutation paths execute."""
import ast
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
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


class PrivateReadError(Exception):
    def __str__(self): raise AssertionError('diagnostics must not stringify private errors')


class PreparedSourceDiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.m = module('prepared_source_diagnostic_test', ROOT / 'deployment/diagnose_go_erp_prepared_source.py')
        self.p = module('prepared_source_diagnostic_prior', ROOT / 'deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py')
        self.real_mode = module('prepared_source_diagnostic_mode', ROOT / 'deployment/upgrade_go_erp_mode_inventory_root.py')
        self.confirmation = module('prepared_source_diagnostic_confirmation', ROOT / 'deployment/upgrade_go_erp_confirmation_nav_successor_root.py')
        self.names = set(self.p.TARGET_PINS) | set(self.real_mode.APPROVED_PINS) | {self.m.NAV}
        self.assertEqual(len(self.names), 59)
        self.data = {name: ('/* reviewed synthetic code: %s */\n' % name).encode() for name in self.names}
        pins = {name: self.m.blob(data) for name, data in self.data.items()}
        self.m.NAV_BLOB = pins[self.m.NAV]
        self.mode = types.SimpleNamespace(APPROVED_PINS={name: (None, pins[name]) for name in self.real_mode.APPROVED_PINS},
            ROOT=Path('/synthetic/live'), CANDIDATE=self.m.CANDIDATE, SEED=self.m.SEED,
            SOURCE=self.m.SOURCE, TARGET_INVENTORY=self.m.INVENTORY)
        self.ctx = types.SimpleNamespace(prior=types.SimpleNamespace(TARGET_PINS={name: pins[name] for name in self.p.TARGET_PINS}),
            old=types.SimpleNamespace(PREPARED_OVERRIDES={'routes/api.php': pins['routes/api.php']}),
            owner=types.SimpleNamespace(pw_uid=0), package=Path('/synthetic/package'))
        self.groups, allowed = self.m.expected_files(self.ctx, self.mode)
        self.expected = {name: {'blob': pin, 'mode': '100755' if name in self.real_mode.MODE_CORRECTIONS else '100644'}
                         for name, pin in allowed.items()}
        self.behavior, self.reads = {}, []
        parent = self
        class Reader:
            def __init__(self, root, uid): parent.assertEqual((root, uid), (parent.mode.ROOT, 0))
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def _read(self, name):
                parent.assertIn(name, parent.expected); parent.reads.append(name)
                behavior = parent.behavior.get(name)
                if behavior == 'unreadable': raise PrivateReadError('private-account@example.test password=private-value')
                if behavior == 'missing': return None, None
                data = b'private raw code must not be printed' if behavior == 'drift' else parent.data[name]
                mode = 0o755 if parent.expected[name]['mode'] == '100755' else 0o644
                if behavior == 'mode': mode ^= 0o111
                return data, types.SimpleNamespace(st_mode=stat.S_IFREG | mode)
        self.ctx.application = types.SimpleNamespace(PinnedSourceReader=Reader)

    def test_all_three_exact_rosters_match_and_shared_route_is_read_once(self):
        result = self.m.target_report(self.ctx, self.mode, self.groups, self.expected)
        self.assertEqual({key: row['files'] for key, row in result.items()}, {'prepared': 42, 'payment': 17, 'navigation': 1})
        self.assertTrue(all(row['matches'] == row['files'] and row['differences'] == [] for row in result.values()))
        self.assertEqual(len(self.reads), 59)
        self.assertEqual(self.reads.count('routes/api.php'), 1)
        self.assertNotIn('synthetic code', json.dumps(result))

    def test_drift_missing_mode_and_unreadable_file_continue_without_private_values(self):
        missing = next(name for name in self.groups['prepared'] if name != 'routes/api.php')
        self.behavior.update({'routes/api.php': 'drift', missing: 'missing',
            'app/Services/Dashboard/OrderBoardService.php': 'unreadable',
            'app/Http/Controllers/CheckoutController.php': 'mode'})
        result = self.m.target_report(self.ctx, self.mode, self.groups, self.expected)
        rows = {row['path']: row for group in result.values() for row in group['differences']}
        self.assertEqual(rows['routes/api.php']['status'], 'different')
        self.assertEqual(rows[missing]['status'], 'missing')
        self.assertEqual(rows['app/Services/Dashboard/OrderBoardService.php']['status'], 'unreadable')
        mode = rows['app/Http/Controllers/CheckoutController.php']
        self.assertEqual((mode['expected_mode'], mode['actual_mode']), ('100755', '100644'))
        self.assertEqual(len(self.reads), 59)
        self.assertTrue(all(set(row).issubset({'path', 'status', 'expected_blob', 'actual_blob',
            'expected_mode', 'actual_mode', 'known_variants'}) for row in rows.values()))
        public = json.dumps(result)
        for private in ('private-account', 'private-value', 'private raw code', 'PrivateReadError', 'synthetic code'):
            self.assertNotIn(private, public)

    def test_source_aggregate_counts_unknown_names_without_serializing_them(self):
        scanner = module('prepared_source_aggregate_scanner', ROOT / 'deployment/refresh_desktop_dashboard_server_manifest.py')
        actual = {'app/Known.php': {'sha256': 'a' * 64},
                  'app/private-account@example.test.php': {'sha256': 'b' * 64, 'raw': 'private-account-value'}}
        reader = types.SimpleNamespace(selected=scanner.selected, digest=scanner.digest, scan=lambda root: actual)
        self.ctx.application.pinned_scanner = lambda package: reader
        result = self.m.source_report(self.ctx, self.mode, {'app/Known.php': {'present': True}})
        self.assertEqual(result['unknown_selected_file_count'], 1)
        self.assertFalse(result['matches_expected'])
        self.assertEqual(result['actual']['files'], 2)
        self.assertNotIn('private-account', json.dumps(result))

    def test_exact_real_candidate_metadata_and_git_modes_use_only_readonly_object_queries(self):
        base = module('prepared_source_real_git', ROOT / 'deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py')
        with tempfile.TemporaryDirectory(prefix='prepared-source-object-cache-') as folder, \
             mock.patch.object(self.m, 'NAV_BLOB', '5817024fb309d86ccaae2c79ad21391aa8507ecb'):
            cache = Path(folder) / 'release.git'
            subprocess.check_call(['/usr/bin/git', 'init', '--quiet', '--bare', str(cache)])
            common = Path(subprocess.check_output(['/usr/bin/git', '-C', str(ROOT),
                'rev-parse', '--git-common-dir']).decode().strip())
            if not common.is_absolute(): common = (ROOT / common).resolve()
            (cache / 'objects/info/alternates').write_text(str(common / 'objects') + '\n')
            ctx = types.SimpleNamespace(prior=self.p, old=self.confirmation, base=base, candidate_root=cache)
            groups, allowed = self.m.expected_files(ctx, self.real_mode)
            original = base.git; calls = []
            def git(path, *args):
                calls.append(args[0]); self.assertEqual(path, cache)
                self.assertIn(args[0], ('cat-file', 'show', 'ls-tree'))
                return original(path, *args)
            with mock.patch.object(base, 'git', side_effect=git):
                rows, expected = self.m.candidate_baseline(ctx, self.real_mode, allowed)
        self.assertEqual(len(rows), 2558); self.assertEqual(len(expected), 59)
        self.assertEqual(calls, ['cat-file', 'show', 'ls-tree'])
        for name in self.real_mode.MODE_CORRECTIONS: self.assertEqual(expected[name]['mode'], '100755')

    def test_source_failure_preserves_target_report_and_never_calls_control_or_account_paths(self):
        guards = {}
        for name in ('controls', 'observe', 'capture_old', 'execute', 'transaction', 'candidate'):
            guard = mock.Mock(side_effect=AssertionError('forbidden ' + name)); guards[name] = guard
            setattr(self.ctx, name, guard)
        self.mode.Context = lambda package: self.ctx
        with mock.patch.object(self.m.socket, 'gethostname', return_value='srv1836524'), \
             mock.patch.object(self.m, 'read_mode', return_value=self.mode), \
             mock.patch.object(self.m, 'candidate_baseline', return_value=({}, self.expected)), \
             mock.patch.object(self.m, 'source_report', side_effect=PrivateReadError('private-account')):
            result = self.m.run(Path('/synthetic/package'))
        self.assertTrue(result['ok'])
        self.assertEqual(result['targets']['prepared']['matches'], 42)
        self.assertEqual(result['source'], {'status': 'unavailable', 'reason': 'READ_ONLY_CHECK_FAILED'})
        for guard in guards.values(): guard.assert_not_called()
        for flag in ('application_writes', 'database_accessed', 'control_values_read', 'service_changed',
                     'candidate_fetch_performed', 'credentials_disclosed'): self.assertIs(result[flag], False)
        self.assertNotIn('private-account', json.dumps(result))

    def test_unknown_constructor_failure_is_fixed_and_script_callgraph_is_readonly(self):
        with mock.patch.object(self.m.socket, 'gethostname', return_value='srv1836524'), \
             mock.patch.object(self.m, 'read_mode', side_effect=PrivateReadError('private-account-data')):
            result = self.m.run()
        self.assertFalse(result['ok']); self.assertEqual(result['failed_step'], 'pinned-context')
        self.assertEqual(result['reason'], 'READ_ONLY_CHECK_FAILED')
        self.assertNotIn('private-account', json.dumps(result))
        tree = ast.parse((ROOT / 'deployment/diagnose_go_erp_prepared_source.py').read_bytes())
        forbidden = {'controls', 'observe', 'capture_old', 'execute', 'transaction', 'candidate', 'read_state',
            'state_guard', 'fetch', 'apply', 'commit', 'rollback', 'write_at', 'json_at', 'system_command',
            'program_gate_write', 'program_gate_authorize', 'program_gate_retire'}
        for node in ast.walk(tree):
            if not isinstance(node, ast.Call): continue
            name = node.func.id if isinstance(node.func, ast.Name) else node.func.attr if isinstance(node.func, ast.Attribute) else None
            self.assertNotIn(name, forbidden)
            if name == 'git': self.assertIn(ast.literal_eval(node.args[1]), ('cat-file', 'show', 'ls-tree'))

    @unittest.skipUnless(os.geteuid() == 0, 'actual private root-owned pin read')
    def test_actual_mode_pin_is_readonly_and_preserves_bytes_and_inode(self):
        data = (ROOT / 'deployment' / self.m.MODE_NAME).read_bytes()
        self.assertEqual(self.m.blob(data), self.m.MODE_PIN)
        with tempfile.TemporaryDirectory(prefix='go-erp-prepared-source-pin-', dir='/root') as name:
            package = Path(name); path = package / self.m.MODE_NAME
            path.write_bytes(data); path.chmod(0o644)
            before = (path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns)
            original = os.open
            def opened(path, flags, *args, **kwargs):
                self.assertEqual(flags & os.O_ACCMODE, os.O_RDONLY)
                self.assertFalse(flags & (os.O_CREAT | os.O_TRUNC | os.O_APPEND))
                return original(path, flags, *args, **kwargs)
            with mock.patch.object(self.m.os, 'open', side_effect=opened), \
                 mock.patch.object(self.m.os, 'write', side_effect=AssertionError('must not write')):
                mode = self.m.read_mode(package)
            self.assertEqual(mode.CANDIDATE, self.m.CANDIDATE)
            self.assertEqual((path.read_bytes(), path.stat().st_ino, path.stat().st_mtime_ns), before)


if __name__ == '__main__': unittest.main()
