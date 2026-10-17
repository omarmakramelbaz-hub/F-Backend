#!/usr/bin/env python3
"""Exercise installer publication/rollback with a disposable application only."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('crm_installer', Path(__file__).parents[1] / 'deployment/apply_customer_crm.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class InstallerTest(unittest.TestCase):
    def test_preserves_unrelated_live_edit_and_rolls_back_failed_checks(self):
        for fail in (False, True):
            with self.subTest(fail=fail), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary) / 'app'
                root.mkdir()
                target = root / 'routes/fixture.php'
                target.parent.mkdir()
                before = b'<?php\n// unrelated setting\n// existing routes\n'
                after = before + b'// new customer routes\n'
                live = before.replace(b'unrelated setting', b'unrelated live extension')
                target.write_bytes(live)
                manifest = {'version': 1, 'files': [{'path': 'routes/fixture.php',
                    'before': hashlib.sha256(before).hexdigest(), 'after': hashlib.sha256(after).hexdigest(),
                    'patch': 'diff --git a/routes/fixture.php b/routes/fixture.php\n--- a/routes/fixture.php\n+++ b/routes/fixture.php\n@@ -3 +3,2 @@\n // existing routes\n+// new customer routes\n'}], 'migrations': []}
                def source(_root, _revision, relative):
                    return json.dumps(manifest).encode() if relative.endswith('manifest.json') else after
                original = INSTALLER.run
                def runner(args, cwd=None, timeout=120):
                    if args[0] == 'git':
                        return original(args, cwd, timeout)
                    if fail and 'crm:customers-sync' in args:
                        return subprocess.CompletedProcess(args, 1, b'', b'fixture failure')
                    return subprocess.CompletedProcess(args, 0, b'', b'')
                with patch.object(INSTALLER, 'source', source), patch.object(INSTALLER, 'run', runner), \
                     patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                     patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                     contextlib.redirect_stdout(io.StringIO()):
                    if fail:
                        with self.assertRaisesRegex(RuntimeError, 'APPLICATION_CHECK_FAILED'):
                            INSTALLER.main()
                        self.assertEqual(target.read_bytes(), live)
                    else:
                        INSTALLER.main()
                        self.assertEqual(target.read_bytes(), live + b'// new customer routes\n')
                        INSTALLER.main()
                        self.assertEqual(target.read_bytes(), live + b'// new customer routes\n')
                backups = list((root.parent / 'customer-crm-release-backups').glob('*/receipt.json'))
                self.assertEqual(len(backups), 1 if fail else 2)

    def test_conflicting_live_edit_fails_before_any_publication(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            root.mkdir()
            target = root / 'routes/fixture.php'
            target.parent.mkdir()
            live = b'<?php\n// custom replacement\n'
            target.write_bytes(live)
            after = b'<?php\n// expected baseline\n// new routes\n'
            manifest = {'version': 1, 'files': [{'path': 'routes/fixture.php', 'before': '0' * 64,
                'after': hashlib.sha256(after).hexdigest(), 'patch': '--- a/routes/fixture.php\n+++ b/routes/fixture.php\n@@ -2 +2,2 @@\n // expected baseline\n+// new routes\n'}], 'migrations': []}
            with patch.object(INSTALLER, 'source', side_effect=lambda _r, _v, p: json.dumps(manifest).encode() if p.endswith('manifest.json') else after), \
                 patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                 patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                 contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                    INSTALLER.main()
            self.assertEqual(target.read_bytes(), live)
            self.assertEqual(list((root.parent / 'customer-crm-release-backups').glob('*/receipt.json')), [])


if __name__ == '__main__':
    unittest.main()
