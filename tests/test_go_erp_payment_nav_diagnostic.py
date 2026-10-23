"""Pinned diagnostic privacy and read-only checks; no live VPS or service calls."""
import ast
import copy
import importlib.util
import io
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


M = module('payment_nav_diagnostic_tests', ROOT / 'deployment/diagnose_go_erp_payment_nav_prepare.py')


def blob(data):
    return M.hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


SOURCE = b'''class Stop(Exception):
    def __str__(self):
        raise AssertionError("diagnostic must never stringify an error")
class ProgramGateError(Exception):
    pass
def need(value, reason):
    if not value:
        raise Stop("GO_ERP_FIXED_" + reason)
def fixed():
    need(False, "KNOWN")
def dynamic(reason):
    need(False, reason)
def direct():
    raise Stop("DIRECT_FIXED")
def private():
    raise Stop("private_account@example.test")
def builtin():
    raise ValueError("DIRECT_FIXED")
def relay(action):
    action()
def uncalled():
    need(False, "IGNORED-lowercase")
    need(False, "DYNAMIC_" + "REASON")
'''


class ReporterTests(unittest.TestCase):
    def setUp(self):
        self.path = Path('/synthetic/private-package/reviewed.py')
        self.reporter = M.Reporter()
        self.reporter.register(self.path, SOURCE, 'GO_ERP_FIXED_')
        self.code = M.module(self.path, SOURCE)

    def failure(self, action):
        try:
            action()
        except BaseException as error:
            return self.reporter.failure(error)
        self.fail('fixture must fail')

    def test_ast_allowlist_contains_only_fixed_uppercase_literal_arguments(self):
        self.assertEqual(M.literal_reasons(SOURCE, 'GO_ERP_FIXED_'),
                         frozenset({'GO_ERP_FIXED_KNOWN', 'DIRECT_FIXED'}))

    def test_exact_pinned_stop_reports_basename_and_innermost_trusted_line_without_str(self):
        report = self.failure(self.code.fixed)
        self.assertEqual(report, {'error_class': 'Stop', 'reason': 'GO_ERP_FIXED_KNOWN',
                                 'file': 'reviewed.py', 'line': 8})
        self.assertNotIn('/synthetic', json.dumps(report))

    def test_dynamic_private_reason_and_nonliteral_argument_never_escape(self):
        for action in (lambda: self.code.dynamic('ACCOUNT_123_PRIVATE'), self.code.private,
                       lambda: self.code.dynamic(object())):
            report = self.failure(action)
            self.assertEqual(report['reason'], 'PRIVATE_FAILURE')
            self.assertNotIn('ACCOUNT_123', json.dumps(report))
            self.assertNotIn('account@example', json.dumps(report))

    def test_builtin_uppercase_text_is_private_despite_pinned_frame_and_literal_allowlist(self):
        report = self.failure(self.code.builtin)
        self.assertEqual(report, {'error_class': 'ValueError', 'reason': 'PRIVATE_FAILURE',
                                 'file': 'reviewed.py', 'line': 18})

    def test_foreign_stop_and_private_subclass_are_not_the_trusted_exact_class(self):
        class ForeignStop(Exception):
            def __str__(self): raise AssertionError('must not stringify foreign error')
        class PrivateStop(self.code.Stop):
            def __str__(self): raise AssertionError('must not stringify subtype')
        for kind in (ForeignStop, PrivateStop):
            def fail(): raise kind('DIRECT_FIXED')
            report = self.failure(lambda: self.code.relay(fail))
            self.assertEqual(report['reason'], 'PRIVATE_FAILURE')
            self.assertEqual(report['error_class'], 'ExternalError')
            self.assertNotIn('file', report)

    def test_wrong_globals_file_cannot_become_a_trusted_location(self):
        foreign = types.ModuleType('diagnostic_wrong_file')
        foreign.__file__ = '/different/private.py'
        exec(compile(SOURCE, str(self.path), 'exec'), foreign.__dict__)
        report = self.failure(foreign.fixed)
        self.assertEqual(report, {'error_class': 'ExternalError', 'reason': 'PRIVATE_FAILURE'})

    def test_step_only_records_static_status_and_fixed_diagnostic_fields(self):
        ok, value = self.reporter.step('fixed-check', self.code.fixed)
        self.assertIs(ok, False)
        self.assertIsNone(value)
        self.assertEqual(self.reporter.rows[0]['reason'], 'GO_ERP_FIXED_KNOWN')
        private = {'private_account': 'must not enter checks'}
        self.assertEqual(self.reporter.step('read-only-check', lambda: private), (True, private))
        self.assertEqual(self.reporter.rows[-1], {'step': 'read-only-check', 'ok': True})
        self.assertNotIn('private_account', json.dumps(self.reporter.rows))


@unittest.skipUnless(os.geteuid() == 0, 'read_pinned enforces actual root-owned ancestry')
class PinnedReadTests(unittest.TestCase):
    def setUp(self):
        # Both /tmp and /workspace have writable ancestors and are correctly
        # rejected by this stricter diagnostic. Use an actual private ancestry.
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-diagnostic-read-', dir='/root')
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / 'pinned.py'
        self.data = b'class Stop(Exception): pass\n'
        self.path.write_bytes(self.data)
        self.path.chmod(0o644)

    def test_exact_pinned_read_opens_only_readonly_and_preserves_bytes_and_inode(self):
        before = M.identity(self.path.stat())
        calls = []
        original = M.os.open
        def opened(path, flags, *args, **kwargs):
            calls.append((path, flags))
            self.assertEqual(flags & os.O_ACCMODE, os.O_RDONLY)
            self.assertFalse(flags & (os.O_CREAT | os.O_TRUNC | os.O_APPEND))
            return original(path, flags, *args, **kwargs)
        with mock.patch.object(M.os, 'open', side_effect=opened), \
             mock.patch.object(M.os, 'write', side_effect=AssertionError('read must not write')), \
             mock.patch.object(M.os, 'replace', side_effect=AssertionError('read must not replace')):
            self.assertEqual(M.read_pinned(self.path, blob(self.data)), self.data)
        self.assertEqual(len(calls), 1)
        self.assertEqual(M.identity(self.path.stat()), before)
        self.assertEqual(self.path.read_bytes(), self.data)

    def test_wrong_blob_writable_file_link_and_unsafe_parent_are_rejected(self):
        with self.assertRaises(M.DiagnosticStop): M.read_pinned(self.path, '0' * 40)
        self.path.chmod(0o666)
        with self.assertRaises(M.DiagnosticStop): M.read_pinned(self.path, blob(self.data))
        self.path.chmod(0o644)
        link = self.path.parent / 'linked.py'
        link.symlink_to(self.path)
        with self.assertRaises(OSError): M.read_pinned(link, blob(self.data))
        self.path.parent.chmod(0o777)
        with self.assertRaises(M.DiagnosticStop): M.read_pinned(self.path, blob(self.data))
        self.path.parent.chmod(0o700)


class DiagnosticCallGraphTests(unittest.TestCase):
    def test_script_has_no_mutation_candidate_or_publication_calls(self):
        tree = ast.parse((ROOT / 'deployment/diagnose_go_erp_payment_nav_prepare.py').read_bytes())
        calls = set()
        for item in ast.walk(tree):
            if isinstance(item, ast.Call):
                if isinstance(item.func, ast.Name): calls.add(item.func.id)
                elif isinstance(item.func, ast.Attribute): calls.add(item.func.attr)
        forbidden = {'execute', 'transaction', 'candidate', 'state_guard', 'ApplicationPatch',
                     'apply', 'commit', 'rollback', 'write_at', 'json_at', 'program_gate_write',
                     'program_gate_authorize', 'program_gate_retire', 'fetch', 'save', 'upgrade'}
        self.assertFalse(calls & forbidden, calls & forbidden)
        self.assertIn('verify_before', calls)
        self.assertIn('refreshed_manifest', calls)

    def test_every_retained_module_pin_matches_reviewed_local_package_source(self):
        for name, (wanted, _) in M.PINS.items():
            if name in ('upgrade_go_erp_preview31_watcher_root.py', 'upgrade_go_erp_capacity_watcher_root.py'):
                path = ROOT / 'deployment/import422-prior' / name
            elif name == 'upgrade_go_erp_program_watcher_root.py':
                path = ROOT / 'deployment/nav-successor-prior' / name
            else: path = ROOT / 'deployment' / name
            with self.subTest(module=name): self.assertEqual(blob(path.read_bytes()), wanted)

    def test_top_level_failure_is_fixed_and_does_not_stringify(self):
        class PrivateError(Exception):
            def __str__(self): raise AssertionError('must not stringify top-level error')
        output = io.StringIO()
        with mock.patch.object(M, 'run', side_effect=PrivateError('private-account')), mock.patch('sys.stdout', output):
            self.assertEqual(M.main(), 0)
        value = json.loads(output.getvalue())
        self.assertEqual(value['checks'], [{'step': 'diagnostic', 'ok': False, 'reason': 'PRIVATE_FAILURE'}])
        self.assertIs(value['credentials_disclosed'], False)
        self.assertNotIn('private-account', output.getvalue())


@unittest.skipUnless(os.geteuid() == 0, 'actual helper read-only source fixture')
class PureHelperDiagnosticTests(unittest.TestCase):
    def test_real_manifest_reconciliation_and_verify_before_only_report_public_booleans(self):
        cases = module('diagnostic_payment_fixture', ROOT / 'tests/test_go_erp_payment_nav_successor.py')
        fixture = cases.PaymentNavigationSuccessorTests(methodName='runTest')
        fixture.setUp()
        self.addCleanup(fixture.tearDown)
        # Code loading/pinning is tested independently above. Here the real
        # helper/scanner inspect the real fixture manifest and source; only
        # control/service observations and the module loader are substituted.
        class ReadOnlyContext:
            def __init__(self, ctx_package):
                self.prior, self.utility, self.base = fixture.ctx.prior, fixture.u, fixture.ctx.base
                self.controls = fixture.controls
                self.gate, self.old_expected, self.expected = fixture.g, fixture.old_expected, fixture.expected
            def execute(self, *args): raise AssertionError('forbidden execute')
            def transaction(self, *args): raise AssertionError('forbidden transaction')
            def candidate(self, *args): raise AssertionError('forbidden candidate')
            def state_guard(self, *args): raise AssertionError('forbidden state guard')
            def remote(self): return fixture.m.OLD_CANDIDATE
        entry = types.SimpleNamespace(Context=ReadOnlyContext, WORK=fixture.work, ROOT=fixture.live,
            OLD_SOURCE=fixture.m.OLD_SOURCE, BEFORE_SOURCE=fixture.before_source, NEW_SOURCE=fixture.after_source,
            NAV=fixture.m.NAV, BEFORE_NAV=fixture.m.BEFORE_NAV, AFTER_NAV=fixture.m.AFTER_NAV,
            OLD_CANDIDATE=fixture.m.OLD_CANDIDATE, OLD_INVENTORY=fixture.m.OLD_INVENTORY,
            OLD_VERSION=fixture.m.OLD_VERSION, hold_value=fixture.m.hold_value)
        original_module = M.module
        def loaded(path, data):
            if path.name == 'upgrade_go_erp_payment_nav_successor_root.py': return entry
            if path.name == 'go_erp_payment_nav_application_patch.py': return fixture.a
            return original_module(path, data)
        raw = {name: (ROOT / 'deployment' / name).read_bytes() if (ROOT / 'deployment' / name).exists() else b'pass\n'
               for name in M.PINS}
        before_manifest = fixture.manifest.read_bytes()
        before = {str(path): (path.read_bytes(), path.stat().st_ino)
                  for path in fixture.live.rglob('*') if path.is_file()}
        def diagnostic():
            with mock.patch.object(M, 'module', side_effect=loaded), \
                 mock.patch.object(M, 'read_pinned', side_effect=lambda path, wanted: raw[path.name]), \
                 mock.patch.object(M.socket, 'gethostname', return_value='srv1836524'), \
                 mock.patch.object(M.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=0)):
                return M.run(fixture.package)
        result = diagnostic()
        checks = {row['step']: row for row in result['checks']}
        self.assertTrue(checks['verify-before']['ok'])
        self.assertTrue(checks['pure-manifest-reconciliation']['ok'])
        self.assertEqual(result['source_cohort'], 'post-payment')
        self.assertIs(result['manifest_present'], True)
        self.assertIs(result['manifest_base_matches'], True)
        self.assertIs(result['remote_lease_checked'], True)
        self.assertIs(result['remote_lease_matches_original'], True)
        self.assertEqual(fixture.manifest.read_bytes(), before_manifest)
        self.assertEqual({str(path): (path.read_bytes(), path.stat().st_ino)
                          for path in fixture.live.rglob('*') if path.is_file()}, before)
        fixture.assert_controls_unchanged()
        for flag in ('application_writes', 'database_accessed', 'service_changed',
                     'candidate_fetch_performed', 'credentials_disclosed'):
            self.assertIs(result[flag], False)
        # A known bad metadata pin is diagnosed without serializing the
        # manifest, source fingerprint, account content or exception message.
        value = json.loads(before_manifest)
        value['protected'][1]['blob'] = 'e' * 40
        fixture.manifest.write_bytes(fixture.m.canonical(value))
        bad = diagnostic()
        self.assertTrue(any(row.get('ok') is False for row in bad['checks']))
        public = json.dumps(bad)
        self.assertNotIn('e' * 40, public)
        self.assertNotIn(str(fixture.live), public)
        self.assertNotIn('protected', public)


if __name__ == '__main__': unittest.main()
