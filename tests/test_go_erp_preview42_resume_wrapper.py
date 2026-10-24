"""Run the existing shell recovery cases against the fixed-candidate wrapper."""
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('preview42_wrapper_contract_cases',
    ROOT / 'tests/test_go_erp_windows_fixtures_wrapper.py')
cases = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cases)
WRAPPER = ROOT / 'deployment/run_go_erp_preview42_resume_root.sh'
TEXT = WRAPPER.read_text()
PYTHON = re.findall(r"-c '\n(.*?)\n'", TEXT, re.S)
FUNCTIONS = TEXT[TEXT.index('go_worker() {'):TEXT.index('\n# The fixed-candidate rearm')]
LOOP = TEXT[TEXT.index('for ((go_round=0;'):TEXT.index('\ngo_entry verify')]


class Preview42WrapperTests(cases.WrapperTests):
    def response(self, action):
        return {**super().response(action),
            'candidate_revision': '26c6f8b6d80ab8da05b88a00789483a51a4c9a2a',
            'version': '0.4.4-preview.42'}

    def setUp(self):
        for key, value in dict(WRAPPER=WRAPPER, TEXT=TEXT, PYTHON=PYTHON,
                FUNCTIONS=FUNCTIONS, LOOP=LOOP).items():
            scoped = patch.object(cases, key, value)
            scoped.start()
            self.addCleanup(scoped.stop)

    def test_shell_and_every_embedded_python_program_parse(self):
        subprocess.run(['bash', '-n', str(WRAPPER)], check=True)
        self.assertEqual(len(PYTHON), 4)
        for program in PYTHON:
            compile(program, str(WRAPPER), 'exec')

    def test_allocation_request_stops_without_starting_any_worker(self):
        result, events = self.shell('go_action=candidate\n' + LOOP)
        self.assertEqual(result.returncode, 78)
        self.assertEqual(events, '')
        self.assertIn('GO_ERP_PREVIEW42_ALLOCATION_FORBIDDEN', result.stderr)
        self.assertNotIn('go_entry lease', TEXT)
        self.assertNotIn('prepared.json', TEXT)

    def test_every_status_revalidates_exact42_before_worker_request(self):
        self.assertEqual(self.protocol('status', self.response('collect')).returncode, 0)
        self.assertNotEqual(self.protocol('status', self.response('candidate')).returncode, 0)
        for key, bad in [('candidate_revision', 'f' * 40), ('version', '0.4.4-preview.43')]:
            self.assertNotEqual(self.protocol('status', {**self.response('collect'), key: bad}).returncode, 0)

    def test_rearm_script_pin_matches_exact_published_input(self):
        digest = hashlib.sha256((ROOT / 'deployment/rearm_go_erp_preview42_root.py').read_bytes()).hexdigest()
        self.assertIn('hexdigest()!="' + digest + '"', PYTHON[2])

    def test_rearm_output_cannot_select_another_candidate_or_bypass_publication(self):
        value = {'format': 1, 'kind': 'go-erp-preview42-rearm', 'ok': True,
            'rearmed': True, 'worker_request': 'collect', 'published': False,
            'publication_held': True, 'candidate_revision': '26c6f8b6d80ab8da05b88a00789483a51a4c9a2a',
            'version': '0.4.4-preview.42', 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'response.json'
            def check(item):
                path.write_text(json.dumps(item))
                return subprocess.run(['/usr/bin/python3', '-I', '-B', '-c', PYTHON[3], str(path)],
                    capture_output=True).returncode
            self.assertEqual(check(value), 0)
            for key, bad in [('candidate_revision', 'f' * 40), ('version', '0.4.4-preview.43'),
                    ('worker_request', 'candidate'), ('published', True), ('rearmed', 1),
                    ('database_changed', True), ('publication_held', False)]:
                with self.subTest(key=key):
                    self.assertNotEqual(check({**value, key: bad}), 0)


if __name__ == '__main__':
    unittest.main()
