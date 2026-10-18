import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


REPO = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('media_install', REPO / 'deployment/apply_meta_media_user_agent.py')
install = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(install)


def valid():
    return {'status': 'READY', 'category': 'VALID_IMAGE', 'http_status': 200, 'bytes': 123,
            'deployed_validator_accepts': True, 'resolver_status': 'PUBLIC_IP_PINNED'}


def probe(sha, before=False):
    instagram = {'channel': 'instagram', 'result': valid()}
    if before:
        instagram['result'] = {'status': 'FAILED', 'category': 'REDIRECT_NOT_FOLLOWED', 'http_status': 302,
                               'redirect_path_class': 'UNSUPPORTED_BROWSER', 'redirect_observed_host': 'www.facebook.com'}
        instagram['browser_agent_result'] = valid()
    return {'status': 'COMPLETE', 'phase': 'COMPLETE', 'writes': 0, 'message_sends': 0, 'graph_gets': 0,
            'redirects_followed': 0, 'service_sha256': sha,
            'channels': [{'channel': 'messenger', 'result': valid()}, instagram]}


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'public_html'
        self.target = self.root / install.SERVICE
        self.target.parent.mkdir(parents=True)
        self.old, self.new, self.diag = b'old source\n', b'new source\n', b'diagnostic\n'
        self.target.write_bytes(self.old)
        self.target.chmod(0o640)
        self.source, self.diagnostic = Path(self.temp.name) / 'source.php', Path(self.temp.name) / 'diagnostic.php'
        self.source.write_bytes(self.new)
        self.diagnostic.write_bytes(self.diag)
        self.old_sha, self.new_sha = install.digest(self.old), install.digest(self.new)
        for name, value in [('OLD_SHA', self.old_sha), ('NEW_SHA', self.new_sha), ('DIAGNOSTIC_SHA', install.digest(self.diag))]:
            mock = patch.object(install, name, value)
            mock.start()
            self.addCleanup(mock.stop)
        self.before, self.after = probe(self.old_sha, True), probe(self.new_sha)
        self.command = patch.object(install.subprocess, 'run', return_value=SimpleNamespace(returncode=0))
        self.command.start()
        self.addCleanup(self.command.stop)

    def apply(self, responses):
        with patch.object(install, 'run_probe', side_effect=responses):
            return install.apply(self.root, '/php', self.source, self.diagnostic)

    def test_success_one_file_backup_and_mode(self):
        result = self.apply([self.before, self.after])
        self.assertEqual(result['status'], 'APPLIED_READY')
        self.assertEqual(self.target.read_bytes(), self.new)
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o640)
        backup = Path(result['backup'])
        self.assertFalse(backup.is_relative_to(self.root))
        self.assertEqual((backup / 'MetaMediaService.php').read_bytes(), self.old)
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)

    def test_baseline_not_reproduced_no_change(self):
        data = copy.deepcopy(self.before)
        data['channels'][1]['result'] = valid()
        with self.assertRaisesRegex(install.Refused, 'BASELINE_NOT_REPRODUCED') as error:
            self.apply([data])
        self.assertIs(error.exception.evidence, data)
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_candidate_rejected_no_change(self):
        self.before['channels'][1]['browser_agent_result']['http_status'] = 302
        with self.assertRaisesRegex(install.Refused, 'BROWSER_CANDIDATE_REJECTED'):
            self.apply([self.before])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_failed_verify_rolls_back(self):
        self.after['channels'][1]['result']['status'] = 'FAILED'
        with self.assertRaisesRegex(install.Refused, 'VERIFY_FAILED_ROLLED_BACK'):
            self.apply([self.before, self.after])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_messenger_regression_rolls_back(self):
        self.after['channels'][0]['result']['status'] = 'FAILED'
        with self.assertRaisesRegex(install.Refused, 'VERIFY_FAILED_ROLLED_BACK'):
            self.apply([self.before, self.after])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_concurrent_change_never_rolled_back(self):
        def changed(*args):
            self.target.write_bytes(b'another release')
            raise install.Refused('PROBE_FAILED')
        with patch.object(install, 'run_probe', side_effect=[self.before, None]) as call:
            call.side_effect = lambda *args: self.before if call.call_count == 1 else changed()
            with self.assertRaisesRegex(install.Refused, 'VERIFY_FAILED_CONCURRENT_DRIFT'):
                install.apply(self.root, '/php', self.source, self.diagnostic)
        self.assertEqual(self.target.read_bytes(), b'another release')

    def test_deployed_source_drift_refused(self):
        self.target.write_bytes(b'unknown')
        with self.assertRaisesRegex(install.Refused, 'DEPLOYED_SOURCE_DRIFT'):
            self.apply([])

    def test_prewrite_drift_refused(self):
        def changed(*args):
            self.target.write_bytes(b'another release')
            return self.before
        with patch.object(install, 'run_probe', side_effect=changed):
            with self.assertRaisesRegex(install.Refused, 'PREWRITE_SOURCE_DRIFT'):
                install.apply(self.root, '/php', self.source, self.diagnostic)
        self.assertEqual(self.target.read_bytes(), b'another release')

    def test_idempotency_still_verifies(self):
        self.target.write_bytes(self.new)
        result = self.apply([self.after])
        self.assertEqual(result['status'], 'ALREADY_READY')
        self.assertFalse((self.root.parent / 'meta-media-agent-backups').exists())

    def test_release_source_hash_refused(self):
        self.source.write_bytes(b'untrusted')
        with self.assertRaisesRegex(install.Refused, 'RELEASE_SOURCE_REJECTED'):
            self.apply([])

    def test_diagnostic_hash_refused(self):
        self.diagnostic.write_bytes(b'untrusted')
        with self.assertRaisesRegex(install.Refused, 'RELEASE_SOURCE_REJECTED'):
            self.apply([])

    def test_lint_failure_no_change(self):
        install.subprocess.run.return_value = SimpleNamespace(returncode=1)
        with self.assertRaisesRegex(install.Refused, 'PHP_LINT_FAILED'):
            self.apply([])
        self.assertEqual(self.target.read_bytes(), self.old)

    def test_snapshot_strips_upstream_strings(self):
        self.before['channels'][0]['result'].update(url='https://private.example/token', category='https://private.example/token')
        output = json.dumps(install.snapshot(self.before))
        self.assertNotIn('private.example', output)
        self.assertIn('UNSUPPORTED_BROWSER', output)


class PinnedReleaseTests(unittest.TestCase):
    def test_current_release_files_match_pins(self):
        self.assertEqual(install.digest((REPO / install.SERVICE).read_bytes()), install.NEW_SHA)
        self.assertEqual(install.digest((REPO / 'deployment/meta_media_diagnostic.php').read_bytes()), install.DIAGNOSTIC_SHA)


if __name__ == '__main__':
    unittest.main()
