"""Exercise deployment guards locally without network or production mutations."""
from contextlib import ExitStack
from copy import deepcopy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('dashboard_scrollbar_install', HERE / 'install.py')
INSTALL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALL)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.before = b'/* original dashboard styles */\nbody { color: #fff; }\n'
        self.after = self.before + INSTALL.PATCH
        self.entry = {'path': INSTALL.CSS, 'sha256': INSTALL.digest(self.after),
                      'baseline_sha256': INSTALL.digest(self.before)}
        self.patches = ExitStack()
        self.patches.enter_context(patch.object(INSTALL, 'BASELINE_SHA256', INSTALL.digest(self.before)))
        self.patches.enter_context(patch.object(INSTALL, 'BASELINE_BYTES', len(self.before)))

    def tearDown(self):
        self.patches.close()

    def test_manifest_is_one_non_null_css_baseline_only(self):
        INSTALL.validate_manifest([self.entry])
        self.assertEqual(INSTALL.TARGETS, {INSTALL.CSS})
        self.assertEqual(INSTALL.MAX_FILE, 256 * 1024)
        self.assertEqual(INSTALL.installation_order([INSTALL.CSS]), [INSTALL.CSS])
        for field, value in [('path', 'public/other.css'), ('path', '../.env'),
                             ('path', '/' + INSTALL.CSS), ('baseline_sha256', None),
                             ('baseline_sha256', '0' * 64), ('sha256', 'invalid')]:
            changed = deepcopy(self.entry)
            changed[field] = value
            with self.subTest(field=field, value=value), self.assertRaises(RuntimeError):
                INSTALL.validate_manifest([changed])
        for entries in ([], [self.entry, self.entry], [{**self.entry, 'source_url': 'https://elsewhere.test'}]):
            with self.assertRaises(RuntimeError):
                INSTALL.validate_manifest(entries)

    def test_css_is_exact_original_bytes_plus_exact_reviewed_patch(self):
        INSTALL.validate_css(self.after)
        self.assertIn(b'#go-hud::-webkit-scrollbar', INSTALL.PATCH)
        self.assertNotIn(b'overflow', INSTALL.PATCH)
        for data in (self.before, b'changed' + self.after[7:], self.after + b'body{overflow:hidden}',
                     self.after.replace(b'width: 0 !important', b'width: 9px !important'),
                     self.after[:-1], b'x' * (INSTALL.MAX_FILE + 1)):
            with self.subTest(size=len(data)), self.assertRaises(RuntimeError):
                INSTALL.validate_css(data)

    def test_read_release_uses_only_pinned_backend_manifest_and_css(self):
        revision = 'a' * 40
        manifest = json.dumps([self.entry]).encode()
        expected = [f'{INSTALL.REPOSITORY}/{revision}/{INSTALL.PREFIX}/manifest.json',
                    f'{INSTALL.REPOSITORY}/{revision}/{INSTALL.CSS}']
        responses = dict(zip(expected, (manifest, self.after)))
        calls = []

        def download(url, limit):
            calls.append(url)
            data = responses[url]
            self.assertLessEqual(len(data), limit)
            return data

        with patch.object(INSTALL, 'download', download):
            entries, files = INSTALL.read_release(revision, INSTALL.digest(manifest))
            self.assertEqual(entries, [self.entry])
            self.assertEqual(files, {INSTALL.CSS: self.after})
            self.assertEqual(calls, expected)
            with self.assertRaises(RuntimeError):
                INSTALL.read_release(revision, '0' * 64)

    def test_smoke_checks_complete_public_css_and_mime(self):
        revision = 'b' * 40
        with patch.object(INSTALL, 'download', return_value=self.after) as download:
            INSTALL.smoke(revision, {INSTALL.CSS: self.after})
            download.assert_called_once_with(
                INSTALL.ORIGIN + '/' + INSTALL.CSS[len('public/'):] + '?dashboard_root_scrollbar_verify=' + revision,
                INSTALL.MAX_FILE, 'text/css')
        with patch.object(INSTALL, 'download', return_value=b'wrong bytes'), self.assertRaises(RuntimeError):
            INSTALL.smoke(revision, {INSTALL.CSS: self.after})
        with self.assertRaises(RuntimeError):
            INSTALL.smoke(revision, {'public/other.css': self.after})


class TargetTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        (self.root / INSTALL.CSS).parent.mkdir(mode=0o755, parents=True)
        self.user = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
        self.root_patch = patch.object(INSTALL, 'ROOT', self.root)
        self.root_patch.start()
        self.old = b'old reviewed CSS'
        self.new = b'new reviewed CSS'
        self.files = {INSTALL.CSS: self.new}
        self.entries = [{'path': INSTALL.CSS, 'sha256': INSTALL.digest(self.new), 'baseline_sha256': INSTALL.digest(self.old)}]
        self.path = self.root / INSTALL.CSS
        self.path.write_bytes(self.old)
        self.path.chmod(0o640)

    def tearDown(self):
        self.root_patch.stop()
        self.temporary.cleanup()

    def test_atomic_publish_preserves_metadata_and_is_idempotent(self):
        with ExitStack() as stack:
            targets, before, changed = INSTALL.preflight(self.entries, self.files, self.user, stack)
            self.assertEqual(changed, [INSTALL.CSS])
            targets[INSTALL.CSS].write(self.new, before[INSTALL.CSS], before[INSTALL.CSS])
            self.assertEqual(self.path.read_bytes(), self.new)
            self.assertEqual(self.path.stat().st_mode & 0o777, 0o640)
            self.assertEqual(self.path.stat().st_uid, self.user.pw_uid)
            with ExitStack() as next_stack:
                _, _, changed = INSTALL.preflight(self.entries, self.files, self.user, next_stack)
                self.assertEqual(changed, [])

    def test_rollback_restores_bytes_and_mode(self):
        with ExitStack() as stack:
            targets, before, _ = INSTALL.preflight(self.entries, self.files, self.user, stack)
            published = targets[INSTALL.CSS].write(self.new, before[INSTALL.CSS], before[INSTALL.CSS])
            self.assertTrue(INSTALL.rollback({INSTALL.CSS: published}, targets, before))
            self.assertEqual(self.path.read_bytes(), self.old)
            self.assertEqual(self.path.stat().st_mode & 0o777, 0o640)

    def test_divergent_or_missing_baseline_rejected(self):
        self.path.write_bytes(b'unreviewed')
        with ExitStack() as stack, self.assertRaises(RuntimeError):
            INSTALL.preflight(self.entries, self.files, self.user, stack)
        self.path.unlink()
        with ExitStack() as stack, self.assertRaises(RuntimeError):
            INSTALL.preflight(self.entries, self.files, self.user, stack)

    def test_symlink_hardlink_and_writable_target_rejected(self):
        alternate = self.path.parent / 'other.css'
        alternate.write_bytes(self.old)
        self.path.unlink()
        self.path.symlink_to(alternate)
        with ExitStack() as stack, self.assertRaises(RuntimeError):
            INSTALL.Target(INSTALL.CSS, self.user, stack).read()
        self.path.unlink()
        os.link(alternate, self.path)
        with ExitStack() as stack, self.assertRaises(RuntimeError):
            INSTALL.Target(INSTALL.CSS, self.user, stack).read()
        self.path.unlink()
        self.path.write_bytes(self.old)
        self.path.chmod(0o666)
        with ExitStack() as stack, self.assertRaises(RuntimeError):
            INSTALL.Target(INSTALL.CSS, self.user, stack).read()

    def test_parent_swap_and_concurrent_mutation_rejected(self):
        with ExitStack() as stack:
            target = INSTALL.Target(INSTALL.CSS, self.user, stack)
            before = target.read()
            self.path.write_bytes(b'concurrent')
            with self.assertRaises(RuntimeError):
                target.write(self.new, before, before)
            public = self.root / 'public'
            public.rename(self.root / 'saved-public')
            public.symlink_to(self.root / 'saved-public', target_is_directory=True)
            with self.assertRaises(RuntimeError):
                target.read()

    def test_scope_and_rollback_do_not_overwrite_concurrent_change(self):
        with ExitStack() as stack, self.assertRaises(RuntimeError):
            INSTALL.Target('public/unapproved', self.user, stack)
        with self.assertRaises(RuntimeError):
            INSTALL.installation_order(['public/unapproved'])
        with ExitStack() as stack:
            targets, before, _ = INSTALL.preflight(self.entries, self.files, self.user, stack)
            published = targets[INSTALL.CSS].write(self.new, before[INSTALL.CSS], before[INSTALL.CSS])
            self.path.write_bytes(b'concurrent after publication')
            self.assertFalse(INSTALL.rollback({INSTALL.CSS: published}, targets, before))
            self.assertEqual(self.path.read_bytes(), b'concurrent after publication')


if __name__ == '__main__':
    unittest.main()
