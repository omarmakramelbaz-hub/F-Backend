import ast
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]


def load():
    source = (ROOT / 'deployment/install_go_erp_live_updates_root.sh').read_text()
    python = source.split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]
    tree = ast.parse(python)
    tree.body = [node for node in tree.body
                 if not isinstance(node, ast.If) or ast.unparse(node.test) != "__name__ == '__main__'"]
    scope = {'__name__': 'setup_test'}
    exec(compile(tree, 'setup_test', 'exec'), scope)
    return scope


class SetupTests(unittest.TestCase):
    def setUp(self):
        self.s = load()

    def test_actual_console_entries_preserve_success_and_redact_private_failures(self):
        for name, marker in [('install_go_erp_live_updates_root.sh', 'GO_ERP_LIVE_SETUP_STOPPED_WITHOUT_PRIVATE_OUTPUT'),
                             ('go_erp_ci_publish_root.sh', 'GO_ERP_CI_STOPPED_WITHOUT_PRIVATE_OUTPUT')]:
            source = (ROOT/'deployment'/name).read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]
            for action, expected, stderr in [('return 0', 0, ''), ('return 1', 1, ''),
                    ("raise RuntimeError('PRIVATE_EXCEPTION_CONTENT')", 1, marker+'\n')]:
                with self.subTest(entry=name, action=action):
                    tree = ast.parse(source)
                    for node in tree.body:
                        if isinstance(node, ast.FunctionDef) and node.name == 'main':
                            node.body = ast.parse(action).body
                    result = subprocess.run([sys.executable, '-I', '-B', '-c', ast.unparse(tree)],
                                            capture_output=True, text=True, check=False)
                    self.assertEqual(result.returncode, expected)
                    self.assertEqual(result.stderr, stderr)
                    self.assertNotIn('PRIVATE_EXCEPTION_CONTENT', result.stdout+result.stderr)

    def test_service_can_write_only_state_and_publication_subtree(self):
        units = self.s['unit_files']('a' * 40)
        text = units['go-erp-live-updates.service'].decode()
        self.assertIn('ProtectSystem=strict\n', text)
        self.assertIn('ProtectHome=read-only\n', text)
        self.assertIn('NoNewPrivileges=yes\n', text)
        writable = [line for line in text.splitlines() if line.startswith('ReadWritePaths=')]
        self.assertEqual(writable, ['ReadWritePaths=/var/lib/go-erp-live-updates '
            '/home/fasakha/public_html/storage/app/desktop-updates'])
        self.assertIn('/releases/' + 'a' * 40 + '/go_erp_live_updates.py', text)
        self.assertIn(' --once\n', text)
        self.assertNotIn('VPS_SSH', text)
        self.assertNotIn('ssh ', text)

    def test_timer_runs_each_minute_without_overlapping_service_instances(self):
        text = self.s['unit_files']('a' * 40)['go-erp-live-updates.timer'].decode()
        self.assertIn('OnUnitInactiveSec=60s\n', text)
        self.assertIn('AccuracySec=1s\n', text)
        self.assertIn('Unit=go-erp-live-updates.service\n', text)
        self.assertIn('RandomizedDelaySec=0\n', text)

    def test_unit_revision_is_not_shell_text(self):
        for value in ['main', 'a' * 39, 'a' * 40 + ';touch /tmp/unsafe', '../' + 'a' * 40]:
            with self.assertRaises(self.s['SetupError']):
                self.s['unit_files'](value)

    def test_git_read_has_no_ambient_credentials_or_replacement_objects(self):
        calls = []
        self.s['child'] = lambda argv, timeout=120: calls.append(argv) or b'ok'
        self.assertEqual(self.s['git']('show', 'a' * 40 + ':x'), b'ok')
        call = calls[0]
        self.assertEqual(call[:6], ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i'])
        self.assertIn('GIT_NO_REPLACE_OBJECTS=1', call)
        self.assertIn('GIT_OPTIONAL_LOCKS=0', call)
        self.assertNotIn('reset', call)

    def test_payload_requires_fixed_branch_and_exact_self_bytes(self):
        revision = 'a' * 40
        data = {path: path.encode() for path in self.s['FILES']}
        calls = []
        def git(*args):
            calls.append(args)
            if args[0] == 'fetch':
                return b''
            if args[0] == 'rev-parse':
                return revision.encode() + b'\n'
            if args[0] == 'ls-tree':
                path = args[-1]
                return b'100644 blob ' + self.s['blob'](data[path]).encode() + b'\t' + path.encode() + b'\0'
            if args[0] == 'show':
                return data[args[-1].split(':', 1)[1]]
            self.fail(args)
        self.s['git'] = git
        self.s['private_bytes'] = lambda path, limit: data[self.s['SELF']]
        out = self.s['payload'](revision, Path('/tmp/private/apply.sh'))
        self.assertEqual(set(out), {Path(path).name for path in data})
        self.assertEqual(calls[0][-1], 'refs/heads/codex/go-erp-activation-view-fix-20261021-r3')
        self.assertEqual(self.s['BRANCH'], 'codex/go-erp-live-source-20261021')
        self.s['private_bytes'] = lambda path, limit: b'unreviewed'
        with self.assertRaisesRegex(self.s['SetupError'], 'ENTRYPOINT_NOT_PINNED'):
            self.s['payload'](revision, Path('/tmp/private/apply.sh'))

    def test_payload_rejects_superseded_commit_before_reading_scripts(self):
        calls = []
        def git(*args):
            calls.append(args)
            return b'' if args[0] == 'fetch' else b'b' * 40 + b'\n'
        self.s['git'] = git
        with self.assertRaisesRegex(self.s['SetupError'], 'RELEASE_SUPERSEDED'):
            self.s['payload']('a' * 40, Path('/tmp/private/apply.sh'))
        self.assertEqual(len(calls), 2)

    def test_private_token_is_not_allowed_to_contain_header_injection(self):
        self.s['private_bytes'] = lambda path, limit: b'ghp_' + b'A' * 40 + b'\r\nOther: bad'
        with self.assertRaisesRegex(self.s['SetupError'], 'TOKEN_INVALID'):
            self.s['configured_token'](Path('/root/github-token'))

    def test_existing_config_token_is_reused_without_printing_it(self):
        token = 'github_pat_' + 'A' * 50
        with tempfile.TemporaryDirectory() as directory:
            self.s['CONFIG'] = Path(directory) / 'live-updates.json'
            self.s['CONFIG'].touch()
            self.s['private_bytes'] = lambda path, limit: json.dumps({
                'repository': self.s['REPOSITORY'], 'token': token}).encode()
            with patch('sys.stdout', new_callable=io.StringIO) as output:
                self.assertEqual(self.s['configured_token'](None), token)
            self.assertEqual(output.getvalue(), '')

    def test_wrong_repository_token_config_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            self.s['CONFIG'] = Path(directory) / 'live-updates.json'
            self.s['CONFIG'].touch()
            self.s['private_bytes'] = lambda path, limit: b'{"repository":"someone/other","token":"secret"}'
            with self.assertRaisesRegex(self.s['SetupError'], 'EXISTING_CONFIGURATION_INVALID'):
                self.s['configured_token'](None)

    def test_github_authentication_does_not_follow_redirects(self):
        self.assertIsNone(self.s['NoRedirect']().redirect_request(None, None, None, None, None, None))

    def test_repository_verification_checks_stable_id_and_actions_access(self):
        calls = []
        owner = self
        class Response:
            status = 200
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def read(self, limit):
                if len(calls) == 1:
                    return json.dumps({'id': owner.s['REPOSITORY_ID'], 'full_name': owner.s['REPOSITORY']}).encode()
                return b'{"workflow_runs":[]}'
        class Opener:
            def open(self, request, timeout):
                calls.append(request)
                owner.assertEqual(timeout, 20)
                return Response()
        token = 'ghp_' + 'A' * 40
        with patch.object(self.s['urllib'].request, 'build_opener', return_value=Opener()):
            self.s['verify_repository'](token)
        self.assertEqual(len(calls), 2)
        self.assertTrue(all(request.full_url.startswith('https://api.github.com/repos/' + self.s['REPOSITORY'])
                            for request in calls))
        self.assertTrue(all(request.headers['Authorization'] == 'Bearer ' + token for request in calls))

    def test_root_and_host_guard_happen_before_network_or_filesystem_changes(self):
        self.s['payload'] = lambda *args: self.fail('must not read git')
        with patch.object(self.s['os'], 'geteuid', return_value=1001), \
             patch.object(self.s['socket'], 'gethostname', return_value='srv1836524'), \
             patch.object(self.s['sys'], 'argv', ['python', '/tmp/setup.sh', '--release', 'a' * 40]):
            with self.assertRaisesRegex(self.s['SetupError'], 'ROOT_AND_HOST_REQUIRED'):
                self.s['main']()

    def test_directory_reader_rejects_symlink_ancestors(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'real').mkdir()
            (root / 'link').symlink_to(root / 'real', target_is_directory=True)
            with self.assertRaises(OSError):
                self.s['open_directory'](root / 'link')

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixtures')
    def test_setup_preserves_private_token_and_explicit_modes_under_umask_077(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.s.update(STATE=root/'state', CONFIG_DIR=root/'config', CONFIG=root/'config/live-updates.json',
                          INSTALL=root/'opt/releases', ROOT=root/'live', SYSTEMD_DIR=root/'units')
            (root/'units').mkdir(mode=0o755)
            (root/'live/storage/app').mkdir(parents=True, mode=0o755)
            calls = []
            self.s['child'] = lambda argv, timeout=120: calls.append(argv) or b''
            token = 'github_pat_' + 'A' * 50
            files = {'go_erp_live_updates.py': b'# reviewed worker\n'}
            before = os.umask(0o077)
            try:
                with patch('sys.stdout', new_callable=io.StringIO) as output:
                    self.s['setup']('a'*40, files, token)
            finally:
                os.umask(before)
            config = self.s['CONFIG']
            self.assertEqual(config.stat().st_mode & 0o777, 0o600)
            self.assertEqual(json.loads(config.read_bytes())['token'], token)
            self.assertEqual(self.s['STATE'].stat().st_mode & 0o777, 0o700)
            self.assertEqual((self.s['INSTALL']/('a'*40)).stat().st_mode & 0o777, 0o755)
            self.assertEqual((self.s['ROOT']/'storage/app/desktop-updates').stat().st_mode & 0o777, 0o755)
            self.assertNotIn(token, output.getvalue())
            self.assertIn('STATE=AWAITING_FIRST_ACCEPTED_BUILD', output.getvalue())
            self.assertTrue(any(call[:3] == ['/usr/bin/systemctl', 'enable', '--now'] for call in calls))
            with patch('sys.stdout', new_callable=io.StringIO):
                self.s['setup']('a'*40, files, token)
            with self.assertRaisesRegex(self.s['SetupError'], 'CONFIGURATION_REQUIRES_REVIEWED_UPGRADE'):
                self.s['setup']('b'*40, files, token)

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixtures')
    def test_setup_refuses_unrelated_existing_unit_before_installing_anything(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.s.update(STATE=root/'state', CONFIG_DIR=root/'config', CONFIG=root/'config/live-updates.json',
                          INSTALL=root/'opt/releases', ROOT=root/'live', SYSTEMD_DIR=root/'units')
            (root/'units').mkdir(mode=0o755)
            (root/'units/go-erp-live-updates.service').write_bytes(b'[Service]\nExecStart=/other/application\n')
            with self.assertRaisesRegex(self.s['SetupError'], 'UNIT_REQUIRES_REVIEWED_UPGRADE'):
                self.s['setup']('a'*40, {'worker.py': b'x'}, 'ghp_'+'A'*40)
            self.assertFalse(self.s['STATE'].exists())
            self.assertFalse(self.s['CONFIG'].exists())

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixtures')
    def test_feed_guard_requires_exact_installed_api_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'app').mkdir()
            file = root/'app/Feed.php'
            file.write_bytes(b'<?php // reviewed feed\n')
            file.chmod(0o644)
            self.s['ROOT'] = root
            self.s['FEED_PINS'] = {'app/Feed.php': self.s['blob'](file.read_bytes())}
            with patch.object(self.s['pwd'], 'getpwnam', return_value=SimpleNamespace(pw_uid=0)):
                self.s['require_installed_feed']()
                file.write_bytes(b'<?php // changed feed\n')
                with self.assertRaisesRegex(self.s['SetupError'], 'SERVER_ACTIVATION_REQUIRED'):
                    self.s['require_installed_feed']()

    @unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixtures')
    def test_private_token_reader_rejects_links_and_public_modes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'token'
            path.write_text('ghp_' + 'A' * 40)
            path.chmod(0o600)
            self.assertTrue(self.s['private_bytes'](path, 1024).startswith(b'ghp_'))
            path.chmod(0o644)
            with self.assertRaisesRegex(self.s['SetupError'], 'PRIVATE_INPUT_UNSAFE'):
                self.s['private_bytes'](path, 1024)
            path.chmod(0o600)
            (root / 'hardlink').hardlink_to(path)
            with self.assertRaisesRegex(self.s['SetupError'], 'PRIVATE_INPUT_UNSAFE'):
                self.s['private_bytes'](path, 1024)


if __name__ == '__main__':
    unittest.main()
