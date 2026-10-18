"""Exercise the complete root wrapper in disposable repositories with a fake runuser."""
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
WRAPPER = ROOT / 'deployment/apply_direct_order_printing_root.sh'
SOURCE = ROOT / 'deployment/apply_direct_order_printing.py'


class DirectPrintingWrapperTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='direct-print-wrapper-')
        self.addCleanup(self.temporary.cleanup)
        folder = Path(self.temporary.name)
        self.app = folder / 'app'
        self.revision = self.git(ROOT, 'rev-parse', 'HEAD').strip()
        base = re.search(r"BASE_REVISION = '([0-9a-f]{40})'", SOURCE.read_text()).group(1)
        self.paths = [
            'public/dashboard/start-branch-pos-printing.cmd',
            'resources/lang/ar/printing.php',
            'resources/lang/en/printing.php',
            'resources/views/admin/print_settings/index.blade.php',
        ]
        self.git(ROOT, 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(self.app))
        self.git(self.app, 'checkout', base, '--', *self.paths)
        self.before = self.snapshots()
        self.expected = {path: self.git_bytes(ROOT, 'show', self.revision + ':' + path) for path in self.paths}
        php = os.environ.get('DIRECT_PRINT_TEST_PHP') or shutil.which('php')
        if not php:
            self.skipTest('Set DIRECT_PRINT_TEST_PHP to a PHP executable')
        self.script = folder / 'apply.sh'
        self.code = WRAPPER.read_text().replace(
            'direct_print_root=/home/fasakha/public_html', 'direct_print_root=' + shlex.quote(str(self.app))
        ).replace(
            'direct_print_php=/opt/cpanel/ea-php82/root/usr/bin/php', 'direct_print_php=' + shlex.quote(php)
        )
        self.log = folder / 'calls.jsonl'
        bin_path = folder / 'bin'
        bin_path.mkdir()
        # The fixture changes identity checks only; both installer phases and
        # their actual git patches, validation and backups execute unchanged.
        runuser = bin_path / 'runuser'
        runuser.write_text('''#!/usr/bin/env python3
import json, os, sys
assert sys.argv[1:4] == ['-u', 'fasakha', '--']
args = sys.argv[4:]
if args[0] == 'python3':
    with open(os.environ['DIRECT_PRINT_CALL_LOG'], 'a') as stream:
        stream.write(json.dumps(args) + '\\n')
    bootstrap = "import os,pwd,runpy,sys,types; original=pwd.getpwnam; pwd.getpwnam=lambda name: types.SimpleNamespace(pw_uid=os.geteuid()) if name=='fasakha' else original(name); sys.argv=sys.argv[1:]; runpy.run_path(sys.argv[0],run_name='__main__')"
    os.execvp('python3', ['python3', '-c', bootstrap, *args[1:]])
os.execvp(args[0], args)
''')
        runuser.chmod(0o755)
        identity = bin_path / 'id'
        identity.write_text('#!/bin/sh\n[ "$1" = "-u" ] || exit 1\nprintf "0\\n"\n')
        identity.chmod(0o755)
        self.environment = dict(os.environ, PATH=str(bin_path) + os.pathsep + os.environ['PATH'],
                                DIRECT_PRINT_CALL_LOG=str(self.log))

    def git_bytes(self, root, *arguments):
        return subprocess.check_output(['git', '-C', str(root), *arguments], stderr=subprocess.PIPE)

    def git(self, root, *arguments):
        return self.git_bytes(root, *arguments).decode()

    def snapshots(self):
        return {path: (self.app / path).read_bytes() for path in self.paths}

    def execute(self, code=None):
        self.script.write_text(code if code is not None else self.code)
        return subprocess.run(['bash', str(self.script), self.revision], env=self.environment,
                              text=True, capture_output=True, timeout=60)

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def test_complete_wrapper_extracts_checks_applies_and_is_idempotent(self):
        result = self.execute()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('DIRECT_ORDER_PRINTING_PREFLIGHT_OK files=4', result.stdout)
        self.assertIn('DIRECT_ORDER_PRINTING_OK files=4', result.stdout)
        self.assertEqual(self.snapshots(), self.expected)
        calls = self.calls()
        self.assertEqual(len(calls), 2)
        self.assertIn('--check', calls[0])
        self.assertNotIn('--check', calls[1])
        receipts = list((self.app.parent / 'direct-order-printing-backups').glob('*/receipt.json'))
        self.assertEqual(len(receipts), 1)
        for path in self.paths:
            self.assertEqual((receipts[0].parent / path).read_bytes(), self.before[path])
        again = self.execute()
        self.assertEqual(again.returncode, 0, again.stdout + again.stderr)
        self.assertIn('already_current=1', again.stdout)
        self.assertEqual(self.snapshots(), self.expected)

    def test_old_missing_source_path_stops_before_any_application_change(self):
        broken = self.code.replace('direct_print_source=deployment/apply_direct_order_printing.py',
                                   'direct_print_source=deployment/apply_pos_direct_print_policy.py')
        self.assertNotEqual(broken, self.code)
        result = self.execute(broken)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('apply_pos_direct_print_policy.py', result.stderr)
        self.assertEqual(self.calls(), [])
        self.assertEqual(self.snapshots(), self.before)


if __name__ == '__main__':
    unittest.main()
