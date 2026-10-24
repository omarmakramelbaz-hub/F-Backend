"""Exercise the shell wrapper's real completion protocol before timer resumption."""
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class CompletionWrapperTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.script = ROOT / 'deployment/run_go_erp_current_dashboard_complete_root.sh'
        bodies = re.findall(r"python3 -I -B -c '([^']*)'", cls.script.read_text())
        cls.protocol = next(body for body in bodies if 'worker_request' in body)

    def invoke(self, mode='status', **changes):
        value = {'ok': True, 'kind': 'go-erp-current-dashboard-completion',
            'application_contents_changed': False, 'database_changed': False,
            'credentials_disclosed': False, 'worker_request': 'collect',
            'published': False, 'publication_held': True}
        value.update(changes)
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'result.json'
            path.write_text(json.dumps(value))
            return subprocess.run([sys.executable, '-I', '-B', '-c', self.protocol,
                str(path), mode], capture_output=True, check=False)

    def test_waiting_build_and_accepted_authorization_request_only_the_expected_worker(self):
        for mode, action in [('lease', 'prepare'), ('lease', 'status'), ('status', 'candidate'), ('status', 'collect'),
                ('status', 'authorize'), ('status', 'publish'), ('authorize', 'publish')]:
            with self.subTest(mode=mode, action=action):
                result = self.invoke(mode, worker_request=action)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, (action + '\n').encode())

    def test_completed_verification_accepts_only_explicit_publication_and_retired_hold(self):
        for mode in ('status', 'verify'):
            result = self.invoke(mode, worker_request='none', published=True, publication_held=False)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout, b'none\n')

    def test_pending_or_inconsistent_completion_cannot_resume_the_timer(self):
        cases = [('verify', {}), ('authorize', {'worker_request': 'none', 'published': True,
            'publication_held': False}), ('verify', {'worker_request': 'none'}),
            ('verify', {'worker_request': 'none', 'published': True, 'publication_held': True}),
            ('verify', {'worker_request': 'none', 'published': 1, 'publication_held': False}),
            ('status', {'worker_request': 'unsupported'}), ('status', {'ok': False}),
            ('status', {'kind': 'different-completion'}),
            ('status', {'application_contents_changed': True}),
            ('status', {'database_changed': True}), ('status', {'credentials_disclosed': True})]
        for mode, changes in cases:
            with self.subTest(mode=mode, changes=changes):
                result = self.invoke(mode, **changes)
                self.assertEqual(result.returncode, 78, result.stderr)
                self.assertEqual(result.stdout, b'')

    def test_shell_and_embedded_python_are_syntactically_valid(self):
        result = subprocess.run(['bash', '-n', str(self.script)], capture_output=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        for body in re.findall(r"python3 -I -B -c '([^']*)'", self.script.read_text()):
            compile(body, '<completion-wrapper-python>', 'exec')


if __name__ == '__main__':
    unittest.main()
