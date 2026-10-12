#!/usr/bin/env python3
"""Root-owned staging and fixed child diagnostics, without touching live workers."""
import ast
import contextlib
import io
import json
import os
import pathlib
import signal
import stat
import subprocess
import sys
import tempfile
import time
import types
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).absolute().parents[1]
TEXT = (ROOT / 'deployment/apply_whatsapp_dashboard_upgrade_root.sh').read_text()
MARKER = 'exec /usr/bin/python3 -B - "$0" "$@" <<\'PY\'\n'
assert TEXT.count(MARKER) == 1 and TEXT.endswith('\nPY\n')
TREE = ast.parse(TEXT.split(MARKER, 1)[1][:-len('\nPY\n')])
assert isinstance(TREE.body[-1], ast.Expr) and isinstance(TREE.body[-1].value, ast.Call)
assert ast.unparse(TREE.body[-1]) == 'sys.exit(main())'
TREE.body.pop()
NS = {'__name__': 'dashboard_root_fixture'}
exec(compile(TREE, 'dashboard-root-wrapper', 'exec'), NS)
REAL_APP = (ROOT / NS['APP']).read_bytes()
ROSTER = NS['parsed_roster'](REAL_APP)


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned payload fixture required')
class RootStagingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wa-root-staging-test-')
        self.addCleanup(self.temp.cleanup)
        self.parent = pathlib.Path(self.temp.name)
        self.root = self.parent / 'public_html'; self.root.mkdir(); (self.root / '.git').mkdir()
        self.directory = self.parent / 'payload'; self.directory.mkdir()
        self.release = 'a' * 40
        self.payload = {relative: (ROOT / relative).read_bytes() for relative in ROSTER}
        pins = {relative: (old, NS['blob'](self.payload[relative])) for relative, (old, _) in ROSTER.items()}
        # Parse an actual publisher source with only its literal roster refreshed;
        # the deliberately unsafe suffix proves that root staging never executes it.
        tree = ast.parse(REAL_APP)
        for node in tree.body:
            if isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id == 'SOURCE_PINS' for target in node.targets):
                node.value = ast.parse(repr(pins), mode='eval').body
        self.app = (ast.unparse(ast.fix_missing_locations(tree)) + '\nraise RuntimeError("APP_CODE_EXECUTED_AS_ROOT")\n').encode()
        manifest = {'version': 1, 'sources': [{'path': relative, 'before_blob': old, 'after_blob': new}
                    for relative, (old, new) in pins.items()]}
        self.payload.update({NS['APP']: self.app, NS['MANIFEST']: json.dumps(manifest).encode(),
            'deployment/install_whatsapp_inbox.py': (ROOT / 'deployment/install_whatsapp_inbox.py').read_bytes()})
        helper_pins = {relative: NS['blob'](self.payload[relative]) for relative in NS['HELPER_PINS']}
        override = patch.dict(NS['HELPER_PINS'], helper_pins, clear=True)
        override.start(); self.addCleanup(override.stop)
        self.commands = []; self.child_directories = []

    def command(self, args, timeout=60):
        self.commands.append(args)
        if args[:6] == ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/git', '-C']:
            self.assertEqual(args[-2], 'show')
            revision, relative = args[-1].split(':', 1)
            self.assertEqual(revision, self.release)
            return 0, self.payload[relative], b''
        self.assertEqual(args[:6], ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/python3', '-B'])
        self.assertNotIn('artisan', args); self.assertNotIn('systemctl', args)
        helper = pathlib.Path(args[6]); directory = helper.parent.parent
        self.child_directories.append(directory)
        for path in (directory, *directory.rglob('*')):
            mode = stat.S_IMODE(path.lstat().st_mode)
            self.assertEqual(mode, 0o755 if path.is_dir() else 0o644)
            self.assertEqual(path.lstat().st_uid, 0)
        self.assertEqual(set(str(path.relative_to(directory)) for path in directory.rglob('*') if path.is_file()), set(self.payload))
        self.assertEqual(args[7:], ['--root', str(self.root), '--manifest', str(directory / NS['MANIFEST']),
            '--manifest-blob', NS['HELPER_PINS'][NS['MANIFEST']]])
        return 0, (b'WHATSAPP_DASHBOARD_UPGRADE_READY\n'
            b'BACKUP=/home/fasakha/whatsapp-release-backups/dashboard-ui-fixture123\n'
            b'PROJECTION_INDEX={"processed":1,"valid":1,"invalid":0}\n'), b''

    def test_real_roster_stages_all_17_current_payloads_under_umask_077_without_running_app_as_root(self):
        old = os.umask(0o077)
        try:
            with patch.dict(NS, {'command': self.command}):
                manifest = NS['stage_payload'](self.directory, self.release)
        finally:
            os.umask(old)
        self.assertEqual(manifest, self.directory / NS['MANIFEST'])
        self.assertEqual(len(ROSTER), 17)
        self.assertEqual(len(self.commands), 20)
        for relative, data in self.payload.items():
            path = self.directory / relative
            self.assertEqual(path.read_bytes(), data)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o644)
        for path in (self.directory, *[p for p in self.directory.rglob('*') if p.is_dir()]):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o755)

    def test_manifest_disagreement_and_tampered_file_fail_before_any_staging_write(self):
        original = self.payload[NS['MANIFEST']]
        value = json.loads(original); value['sources'][0]['before_blob'] = '0' * 40
        self.payload[NS['MANIFEST']] = json.dumps(value).encode()
        with patch.dict(NS['HELPER_PINS'], {NS['MANIFEST']: NS['blob'](self.payload[NS['MANIFEST']])}), patch.dict(NS, {'command': self.command}):
            with self.assertRaisesRegex(NS['SafeError'], 'HELPER_MANIFEST_DISAGREE'):
                NS['stage_payload'](self.directory, self.release)
        self.assertFalse(list(self.directory.iterdir()))
        self.payload[NS['MANIFEST']] = original
        relative = next(iter(ROSTER)); self.payload[relative] += b'\nTAMPERED\n'
        with patch.dict(NS, {'command': self.command}):
            with self.assertRaisesRegex(NS['SafeError'], 'PINNED_FILE_INVALID'):
                NS['stage_payload'](self.directory, self.release)
        self.assertFalse(list(self.directory.iterdir()))

    def test_main_delegates_only_git_reads_and_app_user_helper_then_cleans_private_staging(self):
        launcher = self.parent / 'launcher.sh'; launcher.write_text(TEXT); launcher.chmod(0o600)
        stdout = io.StringIO(); stderr = io.StringIO()
        old = os.umask(0o077); handler = signal.getsignal(signal.SIGTERM)
        try:
            with patch.dict(NS, {'ROOT': self.root, 'command': self.command}), \
                    patch.object(NS['pwd'], 'getpwnam', return_value=types.SimpleNamespace(pw_uid=0)), \
                    patch.object(sys, 'argv', ['python-root', str(launcher), '--release', self.release]), \
                    contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                code = NS['main']()
        finally:
            os.umask(old); signal.signal(signal.SIGTERM, handler)
        self.assertEqual(code, 0, stderr.getvalue()); self.assertEqual(stderr.getvalue(), '')
        self.assertTrue(stdout.getvalue().startswith('WHATSAPP_DASHBOARD_UPGRADE_READY\n'))
        self.assertEqual(len(self.child_directories), 1)
        self.assertFalse(self.child_directories[0].exists())
        self.assertTrue(all(args[4] in ('/usr/bin/git', '/usr/bin/python3') for args in self.commands))


class ChildOutputAndGroupTests(unittest.TestCase):
    def setUp(self):
        NS['approved_scope'] = NS['approved_status_scope'](REAL_APP)

    def test_child_output_requires_fixed_bounded_status_and_never_repeats_private_text(self):
        success = (b'WHATSAPP_DASHBOARD_UPGRADE_READY\nBACKUP=/home/fasakha/whatsapp-release-backups/dashboard-ui-fixture123\n'
                   b'PROJECTION_INDEX={"processed":1,"valid":1,"invalid":0}\n')
        self.assertEqual(len(NS['child_status'](success, b'PRIVATE STDERR', True)), 3)
        relative = next(iter(ROSTER))
        safe = ('DASHBOARD_SOURCE_NEEDS_REVIEW: ' + relative + '; REASON=FILE_OWNER_OR_TYPE_NEEDS_REVIEW\n').encode()
        self.assertEqual(NS['child_status'](b'PRIVATE STDOUT', safe, False), [safe.decode().strip()])
        stage = b'DASHBOARD_UPGRADE_STOPPED; STAGE=POSTFLIGHT; EXCEPTION_CLASS=RuntimeError\n'
        self.assertEqual(NS['child_status'](b'PRIVATE STDOUT', stage, False), [stage.decode().strip()])
        for data in (b'PRIVATE TOKEN TEXT', b'X' * 8193, b'\xff', success + b'PRIVATE TRAILING TEXT\n',
                b'PROJECTION_INDEX={"processed":true,"valid":1,"invalid":0}\n',
                b'DASHBOARD_SOURCE_NEEDS_REVIEW: /private/customer-path\n',
                b'DASHBOARD_UPGRADE_STOPPED; STAGE=PRIVATE_ALPHA_ONLY_TOKEN; EXCEPTION_CLASS=RuntimeError\n',
                ('DASHBOARD_SOURCE_NEEDS_REVIEW: ' + relative + '; REASON=PRIVATE_ALPHA_ONLY_TOKEN\n').encode()):
            with self.subTest(data_length=len(data)):
                self.assertIsNone(NS['child_status'](data, data, False))
        self.assertIsNone(NS['child_status'](success.replace(b'"valid":1', b'"valid":-1'), b'', True))

    def test_timeout_kills_and_reaps_its_private_process_group_without_live_worker_commands(self):
        with tempfile.TemporaryDirectory(prefix='wa-root-group-test-') as folder:
            marker = pathlib.Path(folder) / 'child.heartbeat'
            source = '''import os,pathlib,signal,time
child=os.fork()
if child==0:
 signal.signal(signal.SIGTERM,signal.SIG_IGN)
 os.close(1);os.close(2)
 counter=0
 while True:
  counter+=1;pathlib.Path(%r).write_text(str(counter));time.sleep(.01)
while not pathlib.Path(%r).is_file():time.sleep(.001)
time.sleep(60)
''' % (str(marker), str(marker))
            with self.assertRaisesRegex(NS['SafeError'], 'CHILD_TIMED_OUT'):
                NS['command']([sys.executable, '-c', source], timeout=.2)
            time.sleep(.05)
            stopped = marker.read_text()
            time.sleep(.1)
            self.assertEqual(marker.read_text(), stopped, 'private descendant survived group cleanup')


if __name__ == '__main__': unittest.main()
