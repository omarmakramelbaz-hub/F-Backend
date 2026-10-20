"""Read-only fixture checks for the separately pinned console publication entry."""
import contextlib
import copy
import io
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'deployment/go_erp_ci_publish_root.sh'
CODE = SCRIPT.read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY\n', 1)[0]
NS = {'__name__': 'go_erp_console_fixture'}
exec(compile(CODE, str(SCRIPT), 'exec'), NS)


def release():
    return {'version': '0.4.4-preview.6', 'sequence': 39395150298,
            'source_revision': 'a' * 40,
            'source': {'format': 1, 'files': 1330, 'sha256': 'b' * 64, 'framework': '8.83.29'},
            'gui': {'public/js/dashboard-new.js': 'c' * 64},
            'installer': {'sha256': 'd' * 64, 'bytes': 512,
                          'path': '/api/desktop-dashboard/update/installer/' + 'd' * 64}}


class ConsolePublication(unittest.TestCase):
    def test_shell_is_valid_and_wrong_host_cannot_fetch_or_change_the_application(self):
        self.assertEqual(subprocess.run(['bash', '-n', str(SCRIPT)], capture_output=True).returncode, 0)
        result = subprocess.run(['bash', str(SCRIPT), '--release', 'a' * 40,
                                 '--run-id', '39395150298', '--artifact-directory', '/var/tmp/go-erp-ci-abcdefgh'],
                                capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, b'')
        self.assertIn(b'GO_ERP_CI_ROOT_AND_HOST_REQUIRED', result.stderr)

    def test_git_uses_clean_ambient_environment_and_immutable_object_reads(self):
        calls = []
        with patch.dict(NS, run=lambda args, timeout=120: calls.append(args) or types.SimpleNamespace(returncode=0, stdout=b'object', stderr=b'')):
            self.assertEqual(NS['git']('show', 'a' * 40 + ':deployment/publish_go_erp_update.py'), b'object')
        args = calls[0]
        self.assertIn('/usr/sbin/runuser', args)
        self.assertIn('-i', args)
        self.assertIn('GIT_NO_REPLACE_OBJECTS=1', args)
        self.assertIn('GIT_NO_LAZY_FETCH=1', args)
        self.assertEqual(NS['ENV']['PATH'], '/usr/bin:/bin')
        self.assertNotIn('SSH_AUTH_SOCK', NS['ENV'])

    def test_only_the_authorized_minute_branch_can_select_this_release(self):
        calls = []
        def git(*args):
            calls.append(args)
            return b'' if args[0] == 'fetch' else ('a' * 40 + '\n').encode()
        with patch.dict(NS, git=git):
            self.assertTrue(NS['latest']('a' * 40))
            self.assertFalse(NS['latest']('e' * 40))
        self.assertEqual(calls[0][-1], 'refs/heads/codex/go-erp-minute-updates-20261020')
        self.assertIn('--refmap=', calls[0])
        self.assertNotIn('reset', str(calls))

    def test_only_fixed_regular_commit_blobs_can_be_materialized(self):
        name = NS['PUBLISHER']
        data = b'print("reviewed")\n'
        row = ('100644 blob ' + NS['blob'](data) + '\t' + name + '\0').encode()
        with patch.dict(NS, git=lambda *args: row if args[0] == 'ls-tree' else data):
            self.assertEqual(NS['source_file']('a' * 40, name), data)
            with self.assertRaises(NS['Stop']):
                NS['source_file']('a' * 40, '/tmp/arbitrary.py')
        for invalid in [row.replace(b'100644', b'120000'), row.replace(b'100644', b'160000'),
                        row.replace(name.encode(), b'deployment/another.py'), row.replace(NS['blob'](data).encode(), b'f' * 40)]:
            with patch.dict(NS, git=lambda *args: invalid if args[0] == 'ls-tree' else data):
                with self.assertRaises(NS['Stop']):
                    NS['source_file']('a' * 40, name)

    def test_stage_rejects_a_different_running_helper_before_writing_readers(self):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            with patch.dict(NS, source_file=lambda revision, name: ('reviewed:' + name).encode(),
                            read_regular=lambda path, limit: b'different entrypoint'):
                with self.assertRaises(NS['Stop']):
                    NS['stage_release']('a' * 40, folder, Path('/tmp/entry.sh'))
            self.assertEqual(list(folder.iterdir()), [])

    def test_artifact_identity_must_bind_current_run_source_version_and_manifest(self):
        original = release()
        calls = []
        module = {'read_json': lambda path, root_owned: original,
                  'validate_receipt': lambda receipt: receipt,
                  'verify_installer': lambda path, expected, root_owned: calls.append((path, expected, root_owned))}
        args = [module, Path('/var/tmp/receipt.json'), Path('/var/tmp/setup.exe'), 'a' * 40,
                original['sequence'], original['version'], {'after_source': original['source']}]
        self.assertEqual(NS['validate_artifact'](*args), original)
        self.assertEqual(len(calls), 1)
        for index, invalid in [(3, 'e' * 40), (4, original['sequence'] + 1), (5, '0.4.4-preview.5'),
                               (6, {'after_source': {**original['source'], 'sha256': 'f' * 64}})]:
            changed = args[:]
            changed[index] = invalid
            calls.clear()
            with self.assertRaises(NS['Stop']):
                NS['validate_artifact'](*changed)
            self.assertEqual(calls, [])

    def test_source_and_every_gui_asset_must_match_two_stable_current_measurements(self):
        wanted = release()
        current = {'fingerprint': wanted['source'], 'gui': wanted['gui']}
        with patch.dict(NS, ROOT=Path('/reviewed/project')):
            module = {'source_snapshot': lambda root: copy.deepcopy(current), 'snapshot_gui': lambda value: value['gui']}
            self.assertTrue(NS['current_matches'](module, wanted))
            for key, changed in [('fingerprint', {**wanted['source'], 'sha256': 'f' * 64}),
                                 ('gui', {}), ('gui', {**wanted['gui'], 'public/css/dashboard-extra.css': 'e' * 64})]:
                other = copy.deepcopy(current)
                other[key] = changed
                module['source_snapshot'] = lambda root, value=other: value
                self.assertFalse(NS['current_matches'](module, wanted))
            sequence = iter([current, {**current, 'changed': True}])
            module['source_snapshot'] = lambda root: next(sequence)
            with self.assertRaises(NS['Stop']):
                NS['current_matches'](module, wanted)

    def test_activation_runs_only_the_fixed_reviewed_launcher_and_requires_its_exact_receipt(self):
        wanted = release()
        output = ('DESKTOP_DASHBOARD_SERVER_READY\nBACKUP=/home/fasakha/desktop-dashboard-release-backups/server-abcdefgh\nSOURCE=' + wanted['source']['sha256'] + '\n').encode()
        calls = []
        with patch.dict(NS, run=lambda args, timeout: calls.append(args) or types.SimpleNamespace(returncode=0, stdout=output, stderr=b'')):
            NS['activate'](Path('/tmp/approved'), 'a' * 40, wanted)
        self.assertEqual(calls[0], ['/usr/bin/bash', '/tmp/approved/apply_desktop_dashboard_server_root.sh', '--release', 'a' * 40])
        for code, stdout, stderr in [(0, output + b'extra\n', b''), (0, output.replace(b'b' * 64, b'f' * 64), b''),
                                      (1, b'', b'SECRET=never-print\nDESKTOP_NO_APPROVED_BASELINE_MATCHES\n')]:
            errors = io.StringIO()
            with patch.dict(NS, run=lambda args, timeout: types.SimpleNamespace(returncode=code, stdout=stdout, stderr=stderr)):
                with contextlib.redirect_stderr(errors), self.assertRaises(NS['Stop']):
                    NS['activate'](Path('/tmp/approved'), 'a' * 40, wanted)
            self.assertNotIn('SECRET', errors.getvalue())

    def test_no_publication_success_is_emitted_before_the_real_publishers_exact_receipt(self):
        wanted = release()
        good = ('GO_ERP_UPDATE_PUBLISHED\nVERSION=' + wanted['version'] + '\nSOURCE=' + wanted['source']['sha256'] + '\n').encode()
        for code, output in [(1, good), (0, good.replace(b'b' * 64, b'f' * 64)), (0, good + b'extra\n')]:
            printed = io.StringIO()
            with patch.dict(NS, run=lambda args, timeout: types.SimpleNamespace(returncode=code, stdout=output, stderr=b'')):
                with contextlib.redirect_stdout(printed), self.assertRaises(NS['Stop']):
                    NS['publish'](Path('/tmp/approved'), Path('/var/tmp/setup.exe'), Path('/var/tmp/receipt.json'), wanted)
            self.assertEqual(printed.getvalue(), '')
        printed = io.StringIO()
        with patch.dict(NS, run=lambda args, timeout: types.SimpleNamespace(returncode=0, stdout=good, stderr=b'')):
            with contextlib.redirect_stdout(printed):
                NS['publish'](Path('/tmp/approved'), Path('/var/tmp/setup.exe'), Path('/var/tmp/receipt.json'), wanted)
        self.assertEqual(printed.getvalue(), good.decode())


if __name__ == '__main__':
    unittest.main()
