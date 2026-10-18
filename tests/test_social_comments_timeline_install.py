"""Installer filesystem fixtures; no application boot, database, Meta calls or mail."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('timeline_install', ROOT / 'deployment/apply_social_comments_timeline.py')
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


class TimelineInstallTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.js = (ROOT / m.JS).read_bytes()
        cls.view = (ROOT / m.VIEW).read_bytes()
        cls.block, cls.link = m.timeline_block(cls.js), m.stylesheet_link(cls.view)
        cls.old_js = m.strip_js(cls.js, cls.block)
        cls.old_view = cls.view.replace(cls.link, b'', 1)
        cls.css = (ROOT / m.CSS).read_bytes()

    def test_exact_reversal_and_unrelated_live_changes(self):
        live = self.old_js + b'\n// Existing independent production change.\n'
        changed = m.transform_js(live, self.block)
        self.assertEqual(m.strip_js(changed, self.block), live)
        self.assertEqual(m.transform_js(changed, self.block), changed)
        self.assertEqual(m.transform_js(self.old_js, self.block), self.js)
        view = self.old_view + b'\n{{-- Independent live view change --}}\n'
        changed_view = m.transform_view(view, self.link)
        self.assertEqual(changed_view.replace(self.link, b'', 1), view)
        self.assertEqual(m.transform_view(changed_view, self.link), changed_view)

    def test_partial_or_changed_hooks_refuse(self):
        for broken in (self.old_js.replace(b'        function renderPosts() {', b'        function renderPostsV2() {'),
                       self.js.replace(b'timeline.beforePosts(post && identity(post));', b'timeline.changedHook();')):
            with self.assertRaises(m.Refused):
                m.transform_js(broken, self.block)

    def fixture(self, directory):
        root = Path(directory) / 'application'
        for path, data in [(m.JS, self.old_js), (m.VIEW, self.old_view)]:
            target = root / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            target.chmod(0o640)
        (root / m.CSS).parent.mkdir(parents=True, exist_ok=True)
        args = [str(root), 'fixture-php', str(ROOT / m.VIEW), str(ROOT / m.JS), str(ROOT / m.CSS),
                m.sha(self.view), m.sha(self.js), m.sha(self.css)]
        return root, args

    def test_apply_idempotence_backup_and_only_view_clear(self):
        calls = []
        with tempfile.TemporaryDirectory() as directory:
            root, args = self.fixture(directory)
            def command(argv, cwd=None, input=None):
                calls.append(argv)
                if argv[0] == 'fixture-php':
                    self.assertEqual(argv, ['fixture-php', 'artisan', 'view:clear'])
                    return b''
                result = subprocess.run(argv, input=input, capture_output=True, check=True)
                return result.stdout
            with patch.object(m, 'compile_check') as compile_check, patch.object(m, 'command', command):
                result = m.apply(*args)
                self.assertEqual(result['status'], 'APPLIED')
                self.assertEqual(result['files'], 3)
                self.assertEqual((root / m.JS).read_bytes(), self.js)
                self.assertEqual((root / m.VIEW).read_bytes(), self.view)
                self.assertEqual((root / m.CSS).read_bytes(), self.css)
                self.assertEqual((root / m.JS).stat().st_mode & 0o777, 0o640)
                backup = Path(result['backup'])
                self.assertEqual((backup / Path(m.JS).name).read_bytes(), self.old_js)
                self.assertEqual((backup / Path(m.VIEW).name).read_bytes(), self.old_view)
                self.assertEqual(m.apply(*args)['status'], 'ALREADY_APPLIED')
                self.assertEqual(compile_check.call_count, 2)
            self.assertEqual(sum(c[0] == 'fixture-php' for c in calls), 2)

    def test_clear_failure_rolls_back_only_owned_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root, args = self.fixture(directory)
            def command(argv, cwd=None, input=None):
                if argv[0] == 'fixture-php':
                    raise m.Refused('CACHE_CLEAR_FAILED')
                return b''
            with patch.object(m, 'compile_check'), patch.object(m, 'command', command):
                with self.assertRaisesRegex(m.Refused, 'ROLLED_BACK'):
                    m.apply(*args)
            self.assertEqual((root / m.JS).read_bytes(), self.old_js)
            self.assertEqual((root / m.VIEW).read_bytes(), self.old_view)
            self.assertFalse((root / m.CSS).exists())


if __name__ == '__main__':
    unittest.main()
