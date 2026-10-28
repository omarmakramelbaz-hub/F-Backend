import base64
from contextlib import redirect_stdout
import hashlib
import importlib.util
import io
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('performance_launcher_tests',
    ROOT / 'deployment/run_go_erp_desktop_audit_successor_root.py')
entry = importlib.util.module_from_spec(spec)
spec.loader.exec_module(entry)


def public():
    return {'format': 1, 'kind': 'go-erp-desktop-performance-rollout',
        'ok': True, 'published': True, 'version': '0.4.4-preview.62',
        'candidate_revision': 'a' * 40, 'workspace_enabled': True,
        'manual_only': True, 'automatic_updates_enabled': False,
        'database_changed': False, 'financial_data_changed': False,
        'credentials_disclosed': False}


class Launcher(unittest.TestCase):
    def test_entire_bundle_is_authenticated_before_any_module_executes(self):
        raw = b'PUBLIC_HELPER = True\n'
        bundle = {role: (hashlib.sha256(raw).hexdigest(), base64.b64encode(raw).decode())
                  for role in entry.ROLES}
        bundle['coordinator'] = ('0' * 64, bundle['coordinator'][1])
        with patch.object(entry, 'BUNDLED_MODULES', bundle), patch.object(entry, 'load') as load:
            with self.assertRaisesRegex(entry.Stop, 'MODULE_HASH_CHANGED'):
                entry.helpers()
            load.assert_not_called()

    def test_bundle_is_not_runnable_until_complete(self):
        with patch.object(entry, 'BUNDLED_MODULES', {}):
            with self.assertRaisesRegex(entry.Stop, 'BUNDLE_MISSING'):
                entry.helpers()

    def test_public_output_rejects_private_extra_data_and_policy_changes(self):
        self.assertEqual(entry.public_result(public()), public())
        changes = ({'env': 'SECRET'}, {'database_changed': True},
            {'credentials_disclosed': True}, {'automatic_updates_enabled': True},
            {'format': True}, {'candidate_revision': 'main'},
            {'published': False}, {'version': '0.4.4-preview.60'},
            {'version': '0.4.4-preview.61'})
        for change in changes:
            with self.subTest(change=change), self.assertRaises(entry.Stop):
                entry.public_result({**public(), **change})

    def test_exception_output_never_includes_untrusted_error_text(self):
        try:
            raise ValueError('PRIVATE_CONFIG_AND_CREDENTIAL_VALUE')
        except ValueError as error:
            result = entry.failure(error, 'upgrade')
        raw = json.dumps(result)
        self.assertNotIn('PRIVATE_CONFIG', raw)
        self.assertEqual(result['code'], 'GO_ERP_DESKTOP_PERFORMANCE_UPGRADE_FAILURE')
        self.assertEqual(result['phase'], 'upgrade')

    def test_frozen_native_public_reason_is_preserved_without_raw_exception_text(self):
        result = entry.failure(RuntimeError('NATIVE_TRANSACTION_SEED_CORE_MISMATCH'), 'upgrade')
        self.assertEqual(result['code'],
            'GO_ERP_DESKTOP_PERFORMANCE_NATIVE_TRANSACTION_SEED_CORE_MISMATCH')
        for text in ('NATIVE_TRANSACTION_SECRET with spaces', 'NATIVE_TRANSACTION_' + 'X' * 71):
            self.assertEqual(entry.failure(RuntimeError(text), 'upgrade')['code'],
                'GO_ERP_DESKTOP_PERFORMANCE_UPGRADE_FAILURE')

    def test_host_and_argument_guards_precede_helpers_or_git(self):
        for uid, host, argv in ((1000, 'srv1836524', ['run.py']),
            (0, 'other-host', ['run.py']), (0, 'srv1836524', ['run.py', '--apply'])):
            out = io.StringIO()
            with patch.object(entry.os, 'geteuid', return_value=uid), \
                    patch.object(entry.socket, 'gethostname', return_value=host), \
                    patch.object(entry.sys, 'argv', argv), \
                    patch.object(entry, 'helpers') as helpers, \
                    patch.object(entry, 'retained_package') as retained, redirect_stdout(out):
                self.assertEqual(entry.main(), 1)
            helpers.assert_not_called()
            retained.assert_not_called()
            self.assertEqual(json.loads(out.getvalue())['phase'], 'host')

    def test_failed_or_invalid_upgrade_retains_package_and_never_claims_success(self):
        for value, error in ((None, RuntimeError('SECRET')), ({**public(), 'published': False}, None)):
            run = Mock(return_value=value, side_effect=error)
            modules = {'successor_coordinator': SimpleNamespace(run=run), 'current': object(),
                       'payloads': object(), 'source': object(), 'recovery_plan': object(),
                       'recovery_evidence': object(), 'recovery_effects': object()}
            package, operator = object(), object()
            original = SimpleNamespace(Package=Mock(return_value=package),
                Operator=Mock(return_value=operator), PINS=object())
            out = io.StringIO()
            with patch.object(entry.os, 'geteuid', return_value=0), \
                    patch.object(entry.socket, 'gethostname', return_value='srv1836524'), \
                    patch.object(entry.sys, 'argv', ['run.py']), \
                    patch.object(entry, 'helpers', return_value=modules), \
                    patch.object(entry, 'retained_package', return_value=(Path('/cache/release.git'), b'original')), \
                    patch.object(entry.tempfile, 'mkdtemp', return_value='/private/stage'), \
                    patch.object(entry, 'prepare_package', return_value=Path('/private/stage/release.git')), \
                    patch.object(entry, 'load', return_value=original), \
                    patch.object(entry.shutil, 'rmtree') as remove, redirect_stdout(out):
                self.assertEqual(entry.main(), 1)
            remove.assert_not_called()
            run.assert_called_once_with(package, operator, modules)
            lines = out.getvalue().splitlines()
            self.assertEqual(len(lines), 3)
            self.assertEqual(lines[0], 'GO_ERP_DESKTOP_PERFORMANCE_PACKAGE_PREPARING')
            self.assertIn('PACKAGE_RETAINED_RETRY_SAME_COMMAND', lines[-1])
            self.assertNotIn('SECRET', out.getvalue())

    def test_git_uses_explicit_public_remote_and_no_credential_or_hook_configuration(self):
        result = SimpleNamespace(returncode=0, stdout=b'public')
        with patch.object(entry.subprocess, 'run', return_value=result) as run:
            self.assertEqual(entry.git(Path('/private/release.git'), 'fetch', entry.REMOTE, entry.CORE), b'public')
        args, kwargs = run.call_args
        self.assertEqual(args[0][0], '/usr/bin/git')
        for value in ('credential.helper=', 'core.hooksPath=/dev/null',
                      'core.attributesFile=/dev/null', 'core.fsmonitor=false'):
            self.assertIn(value, args[0])
        self.assertEqual(kwargs['env']['GIT_NO_LAZY_FETCH'], '1')
        self.assertEqual(kwargs['env']['GIT_CONFIG_GLOBAL'], '/dev/null')
        self.assertEqual(kwargs['env']['GIT_TERMINAL_PROMPT'], '0')

    def test_only_verified_publication_removes_its_private_stage(self):
        run=Mock(return_value=public())
        modules={'successor_coordinator':SimpleNamespace(run=run)}
        package,operator=object(),object()
        original=SimpleNamespace(Package=Mock(return_value=package),
            Operator=Mock(return_value=operator),PINS=object())
        out=io.StringIO()
        with patch.object(entry.os,'geteuid',return_value=0), \
                patch.object(entry.socket,'gethostname',return_value='srv1836524'), \
                patch.object(entry.sys,'argv',['run.py']), \
                patch.object(entry,'helpers',return_value=modules), \
                patch.object(entry,'retained_package',return_value=(Path('/cache/release.git'),b'original')), \
                patch.object(entry.tempfile,'mkdtemp',return_value='/private/new-successor-stage'), \
                patch.object(entry,'prepare_package',return_value=Path('/private/new-successor-stage/release.git')), \
                patch.object(entry,'load',return_value=original), \
                patch.object(entry.shutil,'rmtree') as remove,redirect_stdout(out):
            self.assertEqual(entry.main(),0)
        run.assert_called_once_with(package,operator,modules)
        remove.assert_called_once_with(Path('/private/new-successor-stage'))
        lines=out.getvalue().splitlines()
        self.assertEqual(json.loads(lines[-1]),public())
        self.assertNotIn('PACKAGE_RETAINED',out.getvalue())


if __name__ == '__main__':
    unittest.main()
