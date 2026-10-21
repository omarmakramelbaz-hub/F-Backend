import ast
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'deployment/activate_go_erp_complete_root.sh'


def body():
    return SCRIPT.read_text().split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]


def load():
    scope = {'__name__': 'complete_activation_test'}
    exec(compile(body(), str(SCRIPT), 'exec'), scope)
    return scope


class CohortTests(unittest.TestCase):
    def setUp(self):
        self.s = load()
        self.scanner = self.s['namespace']((ROOT / self.s['SCANNER']).read_bytes(), 'test_scanner')
        self.source = self.s['namespace']((ROOT / self.s['SOURCE']).read_bytes(), 'test_source')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.old = b'<?php old();\r\n'
        self.new = b'<?php updated();\n'
        self.write('app/Main.php', self.old)
        self.write('app/Retained.php', b'<?php retained();\n')
        self.write('public/css/dashboard-known.css', b'body { color: red; }\r\n')
        self.write('public/dashboard/branding/dashboard-brand.css', b'body { color: blue; }\n')
        self.head = '2' * 40
        record = self.source['record']
        self.manifest = {'expected_head': self.head, 'sources': [
            {'path': 'app/Main.php', 'before_blob': record(self.old)['blob'], 'after_blob': record(self.new)['blob']},
            {'path': 'app/New.php', 'before_blob': None, 'after_blob': record(b'<?php added();\n')['blob']}],
            'protected': [{'path': name, 'blob': record((self.root / name).read_bytes())['blob']}
                for name in ('app/Retained.php', 'public/css/dashboard-known.css',
                             'public/dashboard/branding/dashboard-brand.css')]}
        self.manifest['before_source'] = self.fingerprint()
        self.make_after()
        self.manifest['after_source'] = self.fingerprint()
        self.write('app/Main.php', self.old)
        (self.root / 'app/New.php').unlink()

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)

    def fingerprint(self):
        value = self.scanner['snapshot'](self.root, self.source, [], [], lambda: self.head)
        return {**self.source['digest'](value['files']), 'framework': '8.83.29'}

    def make_after(self):
        self.write('app/Main.php', self.new)
        self.write('app/New.php', b'<?php added();\n')

    def classify(self):
        return self.s['classifier'](self.root, self.manifest, self.scanner, self.source, lambda: self.head)

    def test_accepts_exact_before_with_absent_publication(self):
        self.assertEqual(self.classify(), 'BEFORE')

    def test_accepts_exact_after_with_added_publication(self):
        self.make_after()
        self.assertEqual(self.classify(), 'AFTER')

    def test_raw_only_drift_is_unknown_even_when_normalized_fingerprint_matches(self):
        self.write('app/Main.php', self.old.replace(b'\r\n', b'\n'))
        self.assertEqual(self.fingerprint(), self.manifest['before_source'])
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_unexpected_head_is_unknown(self):
        self.head = '3' * 40
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_protected_core_change_is_unknown(self):
        self.write('app/Retained.php', b'<?php retained_but_changed();\n')
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_external_gui_change_deletion_and_raw_only_change_are_unknown(self):
        name = 'public/css/dashboard-known.css'
        for data in (b'body { color: green; }\n', None, b'body { color: red; }\n'):
            with self.subTest(data=data):
                if data is None:
                    (self.root / name).unlink()
                else:
                    self.write(name, data)
                self.assertEqual(self.classify(), 'UNKNOWN')

    def test_branding_outside_core_is_protected(self):
        self.write('public/dashboard/branding/dashboard-brand.css', b'changed')
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_new_immediate_dashboard_gui_is_unknown(self):
        self.write('public/js/dashboard-new.js', b'console.log(1);')
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_partial_publication_is_unknown(self):
        self.write('app/New.php', b'<?php added();\n')
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_unlisted_core_addition_is_unknown(self):
        self.write('app/Unexpected.php', b'<?php unexpected();\n')
        self.assertEqual(self.classify(), 'UNKNOWN')

    def test_concurrent_metadata_change_refuses_cohort(self):
        original = self.scanner['snapshot']
        count = 0
        def snapshot(*args):
            nonlocal count
            result = original(*args)
            count += 1
            if count == 1:
                (self.root / 'app/Main.php').chmod(0o600)
            return result
        self.scanner['snapshot'] = snapshot
        with self.assertRaisesRegex(self.s['Stop'], 'SERVER_STATE_CHANGED_DURING_INSPECTION'):
            self.classify()

    def test_inspection_does_not_change_contents_or_permissions(self):
        def state():
            return {str(path.relative_to(self.root)): (path.read_bytes(), stat.S_IMODE(path.stat().st_mode),
                    path.stat().st_uid, path.stat().st_gid, path.stat().st_ino)
                    for path in self.root.rglob('*') if path.is_file()}
        before = state()
        self.assertEqual(self.classify(), 'BEFORE')
        self.assertEqual(state(), before)

    def test_symlink_and_fifo_are_rejected_without_following_or_blocking(self):
        name = self.root / 'app/Main.php'
        name.unlink()
        name.symlink_to(self.root / 'app/Retained.php')
        with self.assertRaises(self.scanner['DiagnosisError']):
            self.classify()
        name.unlink()
        os.mkfifo(name)
        with self.assertRaises(self.scanner['DiagnosisError']):
            self.classify()


class OrchestrationTests(unittest.TestCase):
    def setUp(self):
        self.s = load()

    def execute(self, states, fail=None):
        self.actions = []
        iterator = iter(states)
        def action(name):
            def run():
                self.actions.append(name)
                if fail == name:
                    raise self.s['Stop']('GO_ERP_COMPLETE_TEST_STOP')
            return run
        return self.s['execute'](lambda: next(iterator), *[action(name)
            for name in ('prepare', 'activate', 'postflight', 'install')])

    def test_before_prepares_activates_checks_and_installs_in_order(self):
        self.assertEqual(self.execute(['BEFORE', 'BEFORE', 'AFTER', 'AFTER']),
                         {'initial_state': 'BEFORE', 'activation_ran': True})
        self.assertEqual(self.actions, ['prepare', 'activate', 'postflight', 'install'])

    def test_after_still_requires_fresh_postflight_and_skips_nonidempotent_installer(self):
        self.assertEqual(self.execute(['AFTER', 'AFTER']), {'initial_state': 'AFTER', 'activation_ran': False})
        self.assertEqual(self.actions, ['postflight', 'install'])

    def test_unknown_state_has_no_mutating_actions(self):
        with self.assertRaisesRegex(self.s['Stop'], 'UNREVIEWED_SERVER_STATE_NO_APPLICATION_CHANGES'):
            self.execute(['UNKNOWN'])
        self.assertEqual(self.actions, [])

    def test_permission_failure_never_activates(self):
        with self.assertRaises(self.s['Stop']):
            self.execute(['BEFORE'], fail='prepare')
        self.assertEqual(self.actions, ['prepare'])

    def test_changed_cohort_after_preparation_never_activates(self):
        with self.assertRaisesRegex(self.s['Stop'], 'SERVER_CHANGED_AFTER_PERMISSION_PREPARATION'):
            self.execute(['BEFORE', 'UNKNOWN'])
        self.assertEqual(self.actions, ['prepare'])

    def test_missing_or_partial_installed_stage_never_runs_setup(self):
        with self.assertRaisesRegex(self.s['Stop'], 'ACTIVATED_SOURCE_NOT_EXACT'):
            self.execute(['BEFORE', 'BEFORE', 'UNKNOWN'])
        self.assertEqual(self.actions, ['prepare', 'activate'])

    def test_both_states_stop_on_failed_schema_flags_or_authentication_postflight(self):
        for states, actions in [(['AFTER'], ['postflight']),
                (['BEFORE', 'BEFORE', 'AFTER'], ['prepare', 'activate', 'postflight'])]:
            with self.subTest(states=states):
                with self.assertRaises(self.s['Stop']):
                    self.execute(states, fail='postflight')
                self.assertEqual(self.actions, actions)

    def test_late_source_change_never_runs_setup(self):
        with self.assertRaisesRegex(self.s['Stop'], 'SERVER_CHANGED_DURING_POSTFLIGHT'):
            self.execute(['AFTER', 'UNKNOWN'])
        self.assertEqual(self.actions, ['postflight'])


class EntryTests(unittest.TestCase):
    def setUp(self):
        self.s = load()

    def test_fixed_application_and_helper_pins_equal_reviewed_bytes(self):
        self.assertIsNotNone(self.s['REVISION'].fullmatch(self.s['APP_RELEASE']))
        for name, expected in {**self.s['APP_FILES'], **self.s['AUTOMATION_FILES']}.items():
            with self.subTest(name=name):
                self.assertEqual(self.s['blob']((ROOT / name).read_bytes()), expected)
        manifest = json.loads((ROOT / self.s['MANIFEST']).read_bytes())
        self.assertEqual(manifest['before_source']['files'], 1164)
        self.assertEqual(manifest['before_source']['sha256'],
                         'a6adf27f343f61a5264fd6b65c0aa8978f2825a0ed3e3379d522c89aac4ddfa6')
        self.assertEqual(manifest['after_source']['files'], 1326)
        self.assertEqual(manifest['after_source']['sha256'],
                         'ef2f7d28823d3c447b71e052df49e9eba1b4a0f3edb7ab44e9c4d42417e150b3')

    def test_reviewed_operations_import_does_not_run_cli(self):
        operations = self.s['namespace']((ROOT / self.s['OPERATIONS']).read_bytes(), 'test_ops', shell=True)
        self.assertTrue(callable(operations['activate']))
        self.assertTrue(callable(operations['postflight']))

    def test_invalid_python_payload_is_rejected(self):
        for data in (b'print(1)', b"<<'PY'\npass\n", b"<<'PY'\n<<'PY'\npass\nPY\n"):
            with self.subTest(data=data), self.assertRaisesRegex(self.s['Stop'], 'REVIEWED_PYTHON_PAYLOAD_INVALID'):
                self.s['python_payload'](data)

    @unittest.skipUnless(os.geteuid() == 0, 'Root-owned entry policy requires uid 0')
    def test_entry_requires_private_regular_single_link_and_safe_parent(self):
        with tempfile.TemporaryDirectory(dir='/tmp') as directory:
            folder = Path(directory)
            path = folder / 'entry.sh'
            path.write_bytes(b'reviewed')
            path.chmod(0o600)
            self.assertEqual(self.s['private_entry'](path), b'reviewed')
            path.chmod(0o644)
            with self.assertRaisesRegex(self.s['Stop'], 'ENTRYPOINT_UNSAFE'):
                self.s['private_entry'](path)
            path.chmod(0o600)
            os.link(path, folder / 'linked')
            with self.assertRaisesRegex(self.s['Stop'], 'ENTRYPOINT_UNSAFE'):
                self.s['private_entry'](path)
            (folder / 'linked').unlink()
            folder.chmod(0o777)
            with self.assertRaisesRegex(self.s['Stop'], 'ENTRYPOINT_ANCESTOR_UNSAFE'):
                self.s['private_entry'](path)

    def test_payload_requires_latest_fixed_branch_and_exact_entry_before_staging(self):
        calls = []
        revision = 'a' * 40
        self.s['git'] = lambda *args: calls.append(args) or (revision.encode() if args[0] == 'rev-parse' else b'')
        self.s['private_entry'] = lambda path: b'own'
        self.s['source_file'] = lambda rev, name, expected=None: b'own' if name == self.s['SELF'] else name.encode()
        value = self.s['payload'](revision, Path('/tmp/entry.sh'))
        self.assertEqual(set(value), set(self.s['APP_FILES']) | set(self.s['AUTOMATION_FILES']))
        self.assertEqual(calls[0][-1], 'refs/heads/codex/go-erp-activation-view-fix-20261021-r3')
        self.assertIn(('merge-base', '--is-ancestor', self.s['APP_RELEASE'], revision), calls)
        self.s['private_entry'] = lambda path: b'not reviewed'
        with self.assertRaisesRegex(self.s['Stop'], 'ENTRYPOINT_NOT_REVIEWED'):
            self.s['payload'](revision, Path('/tmp/entry.sh'))

    def test_superseded_release_stops_before_reading_any_script(self):
        calls = []
        self.s['git'] = lambda *args: calls.append(args) or b'b' * 40
        self.s['private_entry'] = lambda path: self.fail('Entry read before revision validation')
        with self.assertRaisesRegex(self.s['Stop'], 'RELEASE_SUPERSEDED'):
            self.s['payload']('a' * 40, Path('/tmp/entry.sh'))
        self.assertEqual(len(calls), 2)

    def test_source_file_rejects_wrong_pin_symlink_and_changed_bytes(self):
        data = b'reviewed'
        pin = self.s['blob'](data)
        mode = '100644'
        shown = data
        def git(*args):
            if args[0] == 'ls-tree':
                return (mode + ' blob ' + pin + '\tx\0').encode()
            return shown
        self.s['git'] = git
        self.assertEqual(self.s['source_file']('a' * 40, 'x', pin), data)
        with self.assertRaisesRegex(self.s['Stop'], 'REVIEWED_FILE_PIN_MISMATCH'):
            self.s['source_file']('a' * 40, 'x', 'f' * 40)
        mode = '120000'
        with self.assertRaisesRegex(self.s['Stop'], 'REVIEWED_FILE_INVALID'):
            self.s['source_file']('a' * 40, 'x')
        mode, shown = '100644', b'changed'
        with self.assertRaisesRegex(self.s['Stop'], 'REVIEWED_BYTES_INVALID'):
            self.s['source_file']('a' * 40, 'x')

    def test_stage_files_are_exclusive_private_and_exact(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            self.s['stage_payload'](folder, {'deployment/a.sh': b'a', 'deployment/b.py': b'b'})
            self.assertEqual((folder / 'a.sh').read_bytes(), b'a')
            self.assertEqual(stat.S_IMODE((folder / 'a.sh').stat().st_mode), 0o600)
            with self.assertRaises(FileExistsError):
                self.s['stage_payload'](folder, {'deployment/a.sh': b'replace'})
            self.assertEqual((folder / 'a.sh').read_bytes(), b'a')

    def test_permission_receipt_must_be_current_and_unblocked(self):
        report = {'release': self.s['APP_RELEASE'], 'private_or_source_contents_printed': False,
                  'application_file_contents_changed': False, 'repair': {'blocked': False}}
        def receipt(value, rc=0):
            return SimpleNamespace(returncode=rc, stderr=b'PRIVATE STDERR', stdout=(
                'GO_ERP_ACTIVATION_PERMISSIONS_BEGIN\n' + json.dumps(value) + '\n' +
                'GO_ERP_ACTIVATION_PERMISSIONS_END; APPLICATION_CONTENTS_CHANGED=false\n').encode())
        self.s['run'] = lambda *args, **kwargs: receipt(report)
        self.s['repair'](Path('/tmp/stage'))
        report['repair']['blocked'] = True
        with self.assertRaisesRegex(self.s['Stop'], 'PERMISSION_RECEIPT_INVALID'):
            self.s['repair'](Path('/tmp/stage'))
        report['release'] = 'b' * 40
        with self.assertRaisesRegex(self.s['Stop'], 'PERMISSION_RECEIPT_INVALID'):
            self.s['repair'](Path('/tmp/stage'))

    def test_setup_uses_final_release_and_only_echoes_fixed_success_receipt(self):
        calls = []
        expected = b'GO_ERP_LIVE_UPDATES_TIMER_INSTALLED\nINTERVAL_SECONDS=60\nSTATE=AWAITING_FIRST_ACCEPTED_BUILD\n'
        self.s['run'] = lambda argv, **kw: calls.append(argv) or SimpleNamespace(returncode=0, stdout=expected, stderr=b'')
        with patch('sys.stdout', new_callable=io.StringIO) as out:
            self.s['setup'](Path('/tmp/stage'), 'e' * 40)
        self.assertEqual(calls[0][-2:], ['--release', 'e' * 40])
        self.assertEqual(out.getvalue().encode(), expected)

    def test_setup_failure_redacts_unrecognized_output_and_token_text(self):
        self.s['run'] = lambda *args, **kw: SimpleNamespace(returncode=1,
            stdout=b'PRIVATE TOKEN', stderr=b'PRIVATE TOKEN\nGO_ERP_LIVE_SETUP_CONFIG_CONFLICT\n')
        with patch('sys.stdout', new_callable=io.StringIO) as out, patch('sys.stderr', new_callable=io.StringIO) as err:
            with self.assertRaisesRegex(self.s['Stop'], 'WATCHER_SETUP_STOPPED'):
                self.s['setup'](Path('/tmp/stage'), 'e' * 40)
        self.assertEqual(out.getvalue(), '')
        self.assertEqual(err.getvalue(), 'GO_ERP_LIVE_SETUP_CONFIG_CONFLICT\n')

    def test_actual_console_preserves_exit_status_and_hides_private_exceptions(self):
        for action, rc, expected in [('return 0', 0, ''), ('return 1', 1, ''),
                ("raise RuntimeError('PRIVATE_EXCEPTION_CONTENT')", 1, 'GO_ERP_COMPLETE_STOPPED_WITHOUT_PRIVATE_OUTPUT\n'),
                ("raise Stop('GO_ERP_COMPLETE_REVIEW_REQUIRED')", 1, 'GO_ERP_COMPLETE_REVIEW_REQUIRED\n')]:
            with self.subTest(action=action):
                tree = ast.parse(body())
                for node in tree.body:
                    if isinstance(node, ast.FunctionDef) and node.name == 'main':
                        node.body = ast.parse(action).body
                result = subprocess.run([sys.executable, '-I', '-B', '-c', ast.unparse(tree)],
                                        capture_output=True, text=True, check=False)
                self.assertEqual(result.returncode, rc)
                self.assertEqual(result.stderr, expected)
                self.assertNotIn('PRIVATE_EXCEPTION_CONTENT', result.stdout + result.stderr)

    def test_main_wires_both_states_to_fixed_application_and_final_watcher_revision(self):
        files = {name: (ROOT / name).read_bytes()
                 for name in set(self.s['APP_FILES']) | set(self.s['AUTOMATION_FILES'])}
        manifest = json.loads(files[self.s['MANIFEST']])
        for initial, states, expected in [('BEFORE', ['BEFORE', 'BEFORE', 'AFTER', 'AFTER'],
                ['repair', 'activate', 'postflight', 'setup']),
                ('AFTER', ['AFTER', 'AFTER'], ['postflight', 'setup'])]:
            with self.subTest(initial=initial), tempfile.TemporaryDirectory() as directory:
                s = load()
                project = Path(directory) / 'public_html'
                project.mkdir()
                (project / '.git').mkdir()
                s['ROOT'] = project
                s['sys'] = SimpleNamespace(argv=['-', '/tmp/reviewed-entry.sh', '--release', 'e' * 40])
                s['pwd'] = SimpleNamespace(getpwnam=lambda name: SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid()))
                s['socket'] = SimpleNamespace(gethostname=lambda: 'srv1836524')
                s['payload'] = lambda revision, entry: files
                actions, folders = [], []
                sequence = iter(states)
                s['classifier'] = lambda *args: next(sequence)
                def staged(folder):
                    folders.append(folder)
                    for name, data in files.items():
                        self.assertEqual((folder / Path(name).name).read_bytes(), data)
                def activate(folder, revision, release):
                    staged(folder)
                    self.assertEqual(revision, s['APP_RELEASE'])
                    self.assertEqual(release, {'source': manifest['after_source']})
                    actions.append('activate')
                def postflight(folder, release, uid, gid):
                    staged(folder)
                    self.assertEqual(release, {'source': manifest['after_source']})
                    self.assertEqual((uid, gid), (os.getuid(), os.getgid()))
                    actions.append('postflight')
                def setup(folder, revision):
                    self.assertEqual(revision, 'e' * 40)
                    actions.append('setup')
                s['namespace'] = lambda data, name, shell=False: (
                    {'activate': activate, 'postflight': postflight} if shell else {})
                s['repair'] = lambda folder: actions.append('repair')
                s['setup'] = setup
                with patch('os.geteuid', return_value=0), patch('sys.stdout', new_callable=io.StringIO) as out:
                    self.assertEqual(s['main'](), 0)
                self.assertEqual(actions, expected)
                self.assertTrue(folders)
                self.assertTrue(all(not folder.exists() for folder in folders))
                self.assertEqual(out.getvalue(), 'GO_ERP_COMPLETE_SERVER_AND_TIMER_READY\nSERVER_SOURCE=' +
                    manifest['after_source']['sha256'] + '\nCHECK_INTERVAL_SECONDS=60\n')

    def test_main_cleans_stage_and_emits_no_readiness_on_unknown_state(self):
        files = {name: (ROOT / name).read_bytes()
                 for name in set(self.s['APP_FILES']) | set(self.s['AUTOMATION_FILES'])}
        with tempfile.TemporaryDirectory() as directory:
            project = Path(directory)
            (project / '.git').mkdir()
            self.s['ROOT'] = project
            self.s['sys'] = SimpleNamespace(argv=['-', '/tmp/reviewed-entry.sh', '--release', 'e' * 40])
            self.s['pwd'] = SimpleNamespace(getpwnam=lambda name: SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid()))
            self.s['socket'] = SimpleNamespace(gethostname=lambda: 'srv1836524')
            self.s['payload'] = lambda revision, entry: files
            self.s['namespace'] = lambda *args, **kw: {}
            self.s['classifier'] = lambda *args: 'UNKNOWN'
            folders = []
            original = self.s['stage_payload']
            self.s['stage_payload'] = lambda folder, data: folders.append(folder) or original(folder, data)
            self.s['repair'] = lambda folder: self.fail('Unknown state tried permission repair')
            self.s['setup'] = lambda *args: self.fail('Unknown state tried watcher setup')
            with patch('os.geteuid', return_value=0), patch('sys.stdout', new_callable=io.StringIO) as out:
                with self.assertRaisesRegex(self.s['Stop'], 'UNREVIEWED_SERVER_STATE_NO_APPLICATION_CHANGES'):
                    self.s['main']()
            self.assertEqual(out.getvalue(), '')
            self.assertEqual(len(folders), 1)
            self.assertFalse(folders[0].exists())


if __name__ == '__main__':
    unittest.main()
