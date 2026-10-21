import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

REPOSITORY = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('root_diagnosis', REPOSITORY / 'deployment' / 'diagnose_go_erp_current_source_root.py')
diagnosis = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(diagnosis)
PUBLICATION = 'a' * 40


def reviewed_payload():
    return {name: subprocess.check_output(['/usr/bin/git', '-C', str(REPOSITORY), 'show', diagnosis.BASELINE_APP + ':' + name])
            for name in diagnosis.PINS}


class RootSourceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.payload = reviewed_payload()

    def test_all_four_payloads_are_exact_and_full_profile_is_consistent(self):
        for name, pin in diagnosis.PINS.items():
            self.assertEqual(diagnosis.blob(self.payload[name]), pin)
        scanner, source, fixture, manifest = diagnosis.profile(self.payload)
        self.assertEqual(len(fixture['files']), 1164)
        self.assertEqual(len(fixture['gui']), 27)
        self.assertEqual(source['digest'](fixture['files'])['sha256'], diagnosis.EXPECTED_SOURCE['sha256'])
        self.assertEqual(manifest['before_source'], diagnosis.EXPECTED_SOURCE)
        self.assertTrue(callable(scanner['snapshot']))

    def test_fixture_profile_mismatch_is_rejected(self):
        payload = dict(self.payload)
        fixture = json.loads(payload['tests/fixtures/desktop-actual-server-baseline.json'])
        fixture['gui'][next(iter(fixture['gui']))] = '0' * 40
        payload['tests/fixtures/desktop-actual-server-baseline.json'] = json.dumps(fixture).encode()
        with self.assertRaisesRegex(diagnosis.Stop, 'REVIEWED_BASELINE_INCONSISTENT'):
            diagnosis.profile(payload)

    def test_real_scanner_thirteen_changed_files_additional_source_and_gui(self):
        scanner, source, _fixture, _manifest = diagnosis.profile(self.payload)
        with tempfile.TemporaryDirectory(prefix='go-erp-root-diagnosis-test-') as temporary:
            root = Path(temporary)
            (root / 'app').mkdir()
            for index in range(13):
                path = root / 'app' / ('fixture-%02d.php' % index)
                path.write_bytes(('<?php /* before %d */\n' % index).encode())
                path.chmod(0o600)
            baseline = scanner['snapshot'](root, source, list(scanner['RELATED_GUI']), [], lambda: diagnosis.EXPECTED_HEAD)
            expected = dict(source['digest'](baseline['files']), framework='8.83.29')
            fixture = {'files': baseline['files'], 'gui': {}, 'expected_head': diagnosis.EXPECTED_HEAD, 'source': expected}
            manifest = {'before_source': expected, 'after_source': diagnosis.EXPECTED_SOURCE}
            for index in range(13):
                (root / 'app' / ('fixture-%02d.php' % index)).write_bytes(b'<?php /* PRIVATE_DO_NOT_PRINT changed */\n')
            (root / 'app' / 'additional.php').write_bytes(b'<?php /* PRIVATE_DO_NOT_PRINT additional */\n')
            (root / 'app' / '.env').write_bytes(b'SHOULD_NEVER_BE_OPENED_PRIVATE_TOKEN')
            (root / 'public' / 'js').mkdir(parents=True)
            gui = root / 'public' / 'js' / 'dashboard-extra.js'
            gui.write_bytes(b'/* PRIVATE_DO_NOT_PRINT GUI */\n')
            gui.chmod(0o600)
            state = diagnosis.State()
            output = diagnosis.diagnose(scanner, source, fixture, manifest, PUBLICATION, state,
                root=root, head_reader=lambda: diagnosis.EXPECTED_HEAD)
            report = json.loads(output)
            self.assertEqual(report['source_difference_count'], 14)
            self.assertEqual(report['actual_source']['files'], 14)
            self.assertEqual(report['source_scanned_as'], 'root')
            self.assertEqual(report['diagnostic_release'], PUBLICATION)
            self.assertEqual(report['release'], diagnosis.BASELINE_APP)
            added = [row for row in report['source_differences'] if row['change'] == 'added']
            self.assertEqual([row['path'] for row in added], ['app/additional.php'])
            self.assertEqual([row['path'] for row in report['additional_dashboard_gui']], ['public/js/dashboard-extra.js'])
            self.assertTrue(all(row['metadata']['uid'] == os.geteuid() for row in report['source_differences']))
            if os.geteuid() == 0:
                self.assertTrue(all(row['metadata']['uid'] == 0 for row in report['source_differences']))
            self.assertNotIn('PRIVATE_DO_NOT_PRINT', output)
            self.assertNotIn('SHOULD_NEVER_BE_OPENED', output)
            self.assertNotIn('app/.env', output)
            for key in ('deployment_performed', 'metadata_changed', 'php_bootstrapped', 'database_read', 'source_content_printed'):
                self.assertIs(report[key], False)

    def test_changed_second_snapshot_is_rejected_before_report(self):
        class DiagnosisError(Exception):
            pass
        scanner = {'DiagnosisError': DiagnosisError, 'RELATED_GUI': (),
                   'snapshot': mock.Mock(side_effect=[{'files': 1}, {'files': 2}]), 'report': mock.Mock()}
        state = diagnosis.State()
        with self.assertRaisesRegex(diagnosis.Stop, 'SNAPSHOT_CHANGED'):
            diagnosis.diagnose(scanner, {}, {'gui': {}}, {}, PUBLICATION, state)
        self.assertEqual(state.phase, 'ROOT_SNAPSHOT_COMPARE')
        scanner['report'].assert_not_called()

    def test_report_bound_and_privacy_flags_are_enforced(self):
        class DiagnosisError(Exception):
            pass
        report = {key: False for key in ('deployment_performed', 'metadata_changed', 'php_bootstrapped', 'database_read', 'source_content_printed')}
        scanner = {'DiagnosisError': DiagnosisError, 'RELATED_GUI': (), 'snapshot': lambda *_: {},
                   'report': lambda *_args, **_kwargs: dict(report)}
        report['database_read'] = True
        with self.assertRaisesRegex(diagnosis.Stop, 'REPORT_PRIVACY_CONTRACT_INVALID'):
            diagnosis.diagnose(scanner, {}, {'gui': {}}, {}, PUBLICATION, diagnosis.State())
        report['database_read'] = False
        report['test_padding'] = 'x' * 100
        with mock.patch.object(diagnosis, 'MAX_REPORT', 20):
            with self.assertRaisesRegex(diagnosis.Stop, 'REPORT_OUTPUT_LIMIT'):
                diagnosis.diagnose(scanner, {}, {'gui': {}}, {}, PUBLICATION, diagnosis.State())

    def test_actor_git_and_head_use_controlled_read_only_command(self):
        captured = []
        def runner(command, **kwargs):
            captured.append((command, kwargs))
            return SimpleNamespace(returncode=0, stdout=(diagnosis.EXPECTED_HEAD + '\n').encode(), stderr=b'')
        def reader(arguments, maximum):
            return diagnosis.git(arguments, maximum, runner=runner)
        self.assertEqual(diagnosis.head(reader), diagnosis.EXPECTED_HEAD)
        command, kwargs = captured[0]
        self.assertEqual(command[:6], ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i'])
        self.assertIn('GIT_CONFIG_GLOBAL=/dev/null', command)
        self.assertIn('GIT_NO_REPLACE_OBJECTS=1', command)
        self.assertIn('GIT_OPTIONAL_LOCKS=0', command)
        self.assertIn('core.fsmonitor=false', command)
        self.assertIn('core.hooksPath=/dev/null', command)
        self.assertEqual(command[-3:], ['rev-parse', '--verify', 'HEAD'])
        self.assertEqual(kwargs['env'], diagnosis.ENV)
        self.assertIs(kwargs['stdin'], subprocess.DEVNULL)
        self.assertNotIn('fetch', command)

    def test_actor_git_failure_does_not_expose_stderr(self):
        def runner(*_args, **_kwargs):
            return SimpleNamespace(returncode=1, stdout=b'PRIVATE', stderr=b'PRIVATE_TOKEN_FROM_STDERR')
        with self.assertRaisesRegex(diagnosis.Stop, '^REVIEWED_GIT_READ_FAILED$'):
            diagnosis.git(['rev-parse', 'HEAD'], runner=runner)

    def test_head_rejects_extra_output(self):
        with self.assertRaisesRegex(diagnosis.Stop, 'HEAD_INVALID'):
            diagnosis.head(lambda *_: (diagnosis.EXPECTED_HEAD + '\nPRIVATE\n').encode())

    def test_payload_pin_mode_size_and_bytes_are_checked(self):
        data = b'reviewed payload\n'
        pin = diagnosis.blob(data)
        name = 'deployment/reviewed.py'
        values = [b'100644 blob ' + pin.encode() + b'\t' + name.encode() + b'\0', str(len(data)).encode() + b'\n', data]
        def read_from(rows):
            iterator = iter(rows)
            return lambda *_: next(iterator)
        self.assertEqual(diagnosis.source_file(diagnosis.BASELINE_APP, name, pin, read_from(values)), data)
        with self.assertRaisesRegex(diagnosis.Stop, 'REVIEWED_FILE_PIN_MISMATCH'):
            diagnosis.source_file(diagnosis.BASELINE_APP, name, 'b' * 40, read_from(values))
        with self.assertRaisesRegex(diagnosis.Stop, 'REVIEWED_FILE_INVALID'):
            diagnosis.source_file(diagnosis.BASELINE_APP, name, pin, read_from([values[0].replace(b'100644', b'120000')]))
        with self.assertRaisesRegex(diagnosis.Stop, 'REVIEWED_FILE_SIZE_INVALID'):
            diagnosis.source_file(diagnosis.BASELINE_APP, name, pin, read_from([values[0], b'99999999\n']))
        with self.assertRaisesRegex(diagnosis.Stop, 'REVIEWED_FILE_BYTES_INVALID'):
            diagnosis.source_file(diagnosis.BASELINE_APP, name, pin, read_from([values[0], values[1], b'x' * len(data)]))

    def test_payloads_read_only_the_fixed_baseline_and_pins(self):
        rows = []
        def fake_source(revision, name, expected, reader):
            rows.append((revision, name, expected))
            return self.payload[name]
        with mock.patch.object(diagnosis, 'source_file', fake_source):
            self.assertEqual(diagnosis.payloads(lambda *_: b''), self.payload)
        self.assertEqual(rows, [(diagnosis.BASELINE_APP, name, pin) for name, pin in diagnosis.PINS.items()])

    def test_root_orchestration_and_diagnostic_self_binding(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-root-entry-test-') as temporary:
            root = Path(temporary)
            (root / '.git').mkdir()
            state = diagnosis.State()
            fake_git = mock.Mock(return_value=b'')
            fake_source = mock.Mock(return_value=b'exact diagnostic entry')
            fake_profile = mock.Mock(return_value=('scanner', 'source', 'fixture', 'manifest'))
            fake_diagnose = mock.Mock(return_value='{}')
            with mock.patch.object(diagnosis, 'ROOT', root), mock.patch.object(diagnosis.os, 'geteuid', return_value=0), mock.patch.object(diagnosis.socket, 'gethostname', return_value='srv1836524.example'), mock.patch.object(diagnosis.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.stat(root).st_uid)), mock.patch.object(diagnosis, 'git', fake_git), mock.patch.object(diagnosis, 'private_entry', return_value=b'exact diagnostic entry'), mock.patch.object(diagnosis, 'source_file', fake_source), mock.patch.object(diagnosis, 'payloads', return_value=self.payload), mock.patch.object(diagnosis, 'profile', fake_profile), mock.patch.object(diagnosis, 'diagnose', fake_diagnose):
                self.assertEqual(diagnosis.execute(['--release', PUBLICATION], state), '{}')
            fake_git.assert_called_once_with(['merge-base', '--is-ancestor', diagnosis.BASELINE_APP, PUBLICATION], 1024)
            fake_source.assert_called_once_with(PUBLICATION, diagnosis.SELF)
            fake_profile.assert_called_once_with(self.payload)
            self.assertEqual(fake_diagnose.call_args.args[:5], ('scanner', 'source', 'fixture', 'manifest', PUBLICATION))

    def test_main_permission_error_is_redacted_with_phase_and_class(self):
        def fail(_arguments, state):
            state.phase = 'ROOT_SNAPSHOT_FIRST'
            raise PermissionError('/home/private/SECRET_PASSWORD=do-not-print')
        out, err = io.StringIO(), io.StringIO()
        with mock.patch.object(diagnosis, 'execute', fail):
            self.assertEqual(diagnosis.main(['--release', PUBLICATION], out, err), 1)
        self.assertEqual(out.getvalue(), '')
        self.assertIn('PHASE=ROOT_SNAPSHOT_FIRST', err.getvalue())
        self.assertIn('CLASS=PermissionError', err.getvalue())
        self.assertNotIn('private', err.getvalue())
        self.assertNotIn('SECRET', err.getvalue())

    def test_scanner_fixed_reason_allowed_private_reason_rejected(self):
        class DiagnosisError(Exception):
            pass
        state = diagnosis.State()
        state.scanner_error = DiagnosisError
        state.phase = 'ROOT_SNAPSHOT_SECOND'
        safe = diagnosis.safe_failure(DiagnosisError('DESKTOP_SOURCE_DIAGNOSIS_LINK'), state)
        self.assertIn('REASON=DESKTOP_SOURCE_DIAGNOSIS_LINK', safe)
        unsafe = diagnosis.safe_failure(DiagnosisError('SECRET_TOKEN /root/private'), state)
        self.assertNotIn('SECRET', unsafe)
        self.assertNotIn('/root', unsafe)
        self.assertIn('CLASS=DiagnosisError', unsafe)

    def test_unknown_exception_class_and_bad_arguments_are_private(self):
        SecretException = type('SECRET_CLASS_NAME', (Exception,), {})
        output = diagnosis.safe_failure(SecretException('SECRET_CONTENT'), diagnosis.State())
        self.assertNotIn('SECRET', output)
        self.assertIn('CLASS=Exception', output)
        out, err = io.StringIO(), io.StringIO()
        self.assertEqual(diagnosis.main(['--release', 'SECRET_ARGUMENT'], out, err), 1)
        self.assertNotIn('SECRET', err.getvalue())
        self.assertIn('REASON=PINNED_RELEASE_REQUIRED', err.getvalue())

    def test_non_root_or_wrong_host_is_rejected_before_account_or_git(self):
        with mock.patch.object(diagnosis.os, 'geteuid', return_value=1001), mock.patch.object(diagnosis.pwd, 'getpwnam') as account:
            with self.assertRaisesRegex(diagnosis.Stop, 'ROOT_AND_HOST_REQUIRED'):
                diagnosis.execute(['--release', PUBLICATION], diagnosis.State())
            account.assert_not_called()
        with mock.patch.object(diagnosis.os, 'geteuid', return_value=0), mock.patch.object(diagnosis.socket, 'gethostname', return_value='other-host'):
            with self.assertRaisesRegex(diagnosis.Stop, 'ROOT_AND_HOST_REQUIRED'):
                diagnosis.execute(['--release', PUBLICATION], diagnosis.State())

    def test_success_bracket_receipt_and_zero_exit(self):
        out, err = io.StringIO(), io.StringIO()
        with mock.patch.object(diagnosis, 'execute', return_value='{"deployment_performed":false}'):
            self.assertEqual(diagnosis.main(['--release', PUBLICATION], out, err), 0)
        self.assertEqual(out.getvalue().splitlines(), ['DESKTOP_CURRENT_SOURCE_DIAGNOSIS_BEGIN', '{"deployment_performed":false}', 'DESKTOP_CURRENT_SOURCE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false'])
        self.assertEqual(err.getvalue(), '')


if __name__ == '__main__':
    unittest.main()
