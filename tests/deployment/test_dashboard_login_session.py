import contextlib
import importlib.util
import io
import os
import pathlib
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('login_update', ROOT / 'deployment/install_dashboard_login_session.py')
update = importlib.util.module_from_spec(spec)
spec.loader.exec_module(update)
RELEASE = 'a' * 40


class LoginInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name) / 'app'
        self.baselines, self.reviewed = {}, {}
        for relative in update.PUBLISH_ORDER:
            self.reviewed[relative] = (ROOT / relative).read_bytes()
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            if relative in update.BASELINES:
                baseline = subprocess.check_output(['git', 'show', update.BASELINE + ':' + relative], cwd=ROOT)
                self.baselines[relative] = baseline
                target.write_bytes(baseline)
                target.chmod(0o644)
        self.calls = []
        self.fail_cache = False

        def command(root, arguments):
            self.calls.append(arguments)
            if arguments[-1] == 'view:clear' and self.fail_cache:
                raise RuntimeError('test cache clear failure')
            return b''

        self.patches = [
            patch.object(update, 'source', side_effect=lambda root, rev, relative:
                         self.reviewed[relative] if rev == RELEASE else self.baselines[relative]),
            patch.object(update, 'owned_command', side_effect=command),
            patch.object(update.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())),
        ]
        for p in self.patches:
            p.start()
            self.addCleanup(p.stop)

    def run_update(self, check=False):
        with contextlib.redirect_stdout(io.StringIO()):
            update._install_locked(self.root, RELEASE, check, os.getuid())

    def test_publish_preserves_other_live_edits_and_is_idempotent(self):
        relative = 'app/Http/Controllers/Dashboard/HomeController.php'
        target = self.root / relative
        target.write_bytes(target.read_bytes() + b'\n// independent live change\n')
        self.run_update()
        for name, content in self.reviewed.items():
            expected = content + b'\n// independent live change\n' if name == relative else content
            self.assertEqual(expected, (self.root / name).read_bytes())
        self.assertEqual('view:clear', self.calls[-1][-1])
        before = {name: update.snapshot(self.root / name, os.getuid()) for name in update.PUBLISH_ORDER}
        self.run_update()
        self.assertEqual(before, {name: update.snapshot(self.root / name, os.getuid()) for name in update.PUBLISH_ORDER})

    def test_preflight_does_not_publish_or_backup(self):
        self.run_update(check=True)
        for relative, data in self.baselines.items():
            self.assertEqual(data, (self.root / relative).read_bytes())
        self.assertFalse((self.root / update.NEW_FILES[0]).exists())
        self.assertFalse((self.root.parent / 'dashboard-login-session-backups').exists())
        self.assertFalse(any(args[-1] == 'view:clear' for args in self.calls))

    def test_failed_cache_clear_rolls_back_sources_and_new_file(self):
        self.fail_cache = True
        with self.assertRaisesRegex(RuntimeError, 'cache clear failure'):
            self.run_update()
        for relative, data in self.baselines.items():
            self.assertEqual(data, (self.root / relative).read_bytes())
        self.assertFalse((self.root / update.NEW_FILES[0]).exists())

    def test_unknown_new_middleware_is_refused_before_any_publish(self):
        (self.root / update.NEW_FILES[0]).write_bytes(b'<?php // another deployment\n')
        with self.assertRaisesRegex(RuntimeError, 'NEW_FILE_CONFLICT'):
            self.run_update()
        for relative, data in self.baselines.items():
            self.assertEqual(data, (self.root / relative).read_bytes())

    def test_conflicting_login_edit_is_refused(self):
        target = self.root / 'resources/views/admin/login.blade.php'
        target.write_bytes(target.read_bytes().replace(b'autocomplete="current-password"', b'autocomplete="another-policy"'))
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            self.run_update()
        self.assertFalse((self.root / update.NEW_FILES[0]).exists())


if __name__ == '__main__':
    unittest.main()
