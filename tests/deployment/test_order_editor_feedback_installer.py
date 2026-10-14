"""python3 -m unittest discover -s tests/deployment -p 'test_order_editor_feedback_installer.py'"""
import importlib.util
import json
import os
import pathlib
import stat
import subprocess
import tempfile
import types
import unittest
from unittest import mock

PACKAGE = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('order_editor_feedback_installer', PACKAGE / 'deployment/install_order_editor_feedback.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class PatchAndIntegrityTest(unittest.TestCase):
    def test_unrelated_live_change_is_preserved_and_rerun_is_idempotent(self):
        base = b'old unrelated setting\n' + b'stable\n' * 30 + b'old save callback\n'
        reviewed = base.replace(b'old save callback', b'new save callback')
        current = base.replace(b'old unrelated setting', b'operator unrelated setting')
        expected = current.replace(b'old save callback', b'new save callback')
        actual = installer.surgical_patch(current, base, reviewed, 'editor.js')
        self.assertEqual(actual, expected)
        self.assertEqual(installer.surgical_patch(actual, base, reviewed, 'editor.js'), expected)

    def test_conflicting_callback_is_not_overwritten(self):
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            installer.surgical_patch(b'header\noperator\nfooter\n', b'header\nold\nfooter\n', b'header\nnew\nfooter\n', 'editor.js')

    def test_source_bytes_must_match_immutable_git_blob(self):
        payload = b'reviewed release source\n'
        with mock.patch.object(installer, 'owned_command', side_effect=[payload, (installer.blob(payload) + '\n').encode()]):
            self.assertEqual(installer.source(PACKAGE, 'a' * 40, 'editor.js'), payload)
        with mock.patch.object(installer, 'owned_command', side_effect=[payload, b'f' * 40 + b'\n']):
            with self.assertRaisesRegex(RuntimeError, 'SOURCE_BLOB_MISMATCH'):
                installer.source(PACKAGE, 'a' * 40, 'editor.js')


class WrapperTest(unittest.TestCase):
    def parse(self, *flags):
        content = (PACKAGE / 'deployment/apply_order_editor_feedback_root.sh').read_text()
        parser = content.split('\nshift\n', 1)[1].split('\neditor_feedback_tmp=', 1)[0]
        return subprocess.run(['bash', '-s', '--', *flags], input=parser + '\nprintf "%s\\n" "${editor_feedback_flags[@]}"\n', text=True, capture_output=True)

    def test_check_and_empty_options_are_forwarded(self):
        for flags in ((), ('--check',)):
            with self.subTest(flags=flags):
                result = self.parse(*flags)
                self.assertEqual(result.returncode, 0)
                self.assertEqual(result.stdout.strip().splitlines(), list(flags))

    def test_unknown_or_duplicate_flags_are_rejected(self):
        for flags in (('--other',), ('--repair-permissions',), ('--check', '--check')):
            with self.subTest(flags=flags):
                self.assertNotEqual(self.parse(*flags).returncode, 0)


class InstallerTransactionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name) / 'public_html'
        self.root.mkdir()
        self.release = 'a' * 40
        self.before, self.after, self.calls = {}, {}, []
        for relative in installer.BASELINES:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            self.before[relative] = b'header\nold callback\nfooter\n'
            self.after[relative] = b'header\nnew callback\nfooter\n'
            path.write_bytes(self.before[relative])
            os.chmod(path, 0o755)
        self.unrelated = self.root / 'unrelated.php'
        self.unrelated.write_bytes(b'unrelated PHP source')

    def tearDown(self):
        self.temporary.cleanup()

    def source(self, root, revision, relative):
        self.calls.append((revision, relative))
        return self.after[relative] if revision == self.release else self.before[relative]

    def execute(self, check=False):
        with mock.patch.object(installer, 'source', side_effect=self.source):
            installer._install_locked(self.root, self.release, check, os.getuid())

    def test_preflight_preserves_bytes_modes_inodes_and_creates_no_backup(self):
        tracked = {relative: (self.root / relative).stat() for relative in installer.BASELINES}
        self.execute(check=True)
        for relative, before in tracked.items():
            path = self.root / relative
            after = path.stat()
            self.assertEqual(path.read_bytes(), self.before[relative])
            self.assertEqual((before.st_mode, before.st_ino, before.st_mtime_ns),
                             (after.st_mode, after.st_ino, after.st_mtime_ns))
        self.assertFalse((self.root.parent / 'order-editor-feedback-backups').exists())

    def test_public_preflight_creates_no_update_lock(self):
        user = types.SimpleNamespace(pw_uid=self.root.stat().st_uid)
        with mock.patch.object(installer, 'APP_ROOT', self.root), \
                mock.patch.object(installer.os, 'geteuid', return_value=0), \
                mock.patch.object(installer.pwd, 'getpwnam', return_value=user), \
                mock.patch.object(installer, '_install_locked') as locked:
            installer.install(self.root, self.release, check_only=True)
            locked.assert_called_once_with(self.root, self.release, True, user.pw_uid)
        self.assertFalse((self.root.parent / '.order-editor-feedback-update.lock').exists())

    def test_success_uses_each_exact_baseline_and_backs_up_only_two_files(self):
        alias = self.root.parent / 'old-editor-hardlink.js'
        first = next(iter(installer.BASELINES))
        os.link(self.root / first, alias)
        self.execute()
        for relative, expected in self.after.items():
            path = self.root / relative
            self.assertEqual(path.read_bytes(), expected)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o755)
            self.assertIn((installer.BASELINES[relative], relative), self.calls)
        self.assertEqual(alias.read_bytes(), self.before[first])
        self.assertEqual(self.unrelated.read_bytes(), b'unrelated PHP source')
        backup = next((self.root.parent / 'order-editor-feedback-backups').iterdir())
        manifest = json.loads((backup / 'manifest.json').read_text())
        self.assertEqual(manifest['baselines'], installer.BASELINES)
        self.assertEqual(set(manifest['files']), set(installer.BASELINES))
        self.assertEqual(manifest['target_blobs'], {relative: installer.blob(payload) for relative, payload in self.after.items()})
        for relative in installer.BASELINES:
            self.assertEqual((backup / relative).read_bytes(), self.before[relative])

    def test_already_installed_release_keeps_current_inodes(self):
        for relative, expected in self.after.items():
            (self.root / relative).write_bytes(expected)
        before = {relative: (self.root / relative).stat().st_ino for relative in installer.BASELINES}
        self.execute()
        self.assertEqual(before, {relative: (self.root / relative).stat().st_ino for relative in installer.BASELINES})

    def test_conflict_in_second_file_prevents_any_publication(self):
        second = list(installer.BASELINES)[1]
        (self.root / second).write_bytes(b'header\noperator callback\nfooter\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            self.execute()
        first = next(iter(installer.BASELINES))
        self.assertEqual((self.root / first).read_bytes(), self.before[first])
        self.assertFalse((self.root.parent / 'order-editor-feedback-backups').exists())

    def test_second_publication_failure_restores_first_file(self):
        first, second = installer.PUBLISH_ORDER
        atomic = installer.atomic
        def publish(path, data, mode, uid, gid):
            if path == self.root / second and data == self.after[second]:
                raise RuntimeError('TEST_PUBLICATION_FAILURE')
            atomic(path, data, mode, uid, gid)
        with mock.patch.object(installer, 'atomic', side_effect=publish):
            with self.assertRaisesRegex(RuntimeError, 'TEST_PUBLICATION_FAILURE'):
                self.execute()
        for relative, expected in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)

    def test_rollback_preserves_concurrent_operator_edit(self):
        first, second = installer.PUBLISH_ORDER
        atomic = installer.atomic
        def publish(path, data, mode, uid, gid):
            if path == self.root / second and data == self.after[second]:
                (self.root / first).write_bytes(b'operator concurrent edit')
                raise RuntimeError('TEST_PUBLICATION_FAILURE')
            atomic(path, data, mode, uid, gid)
        with mock.patch.object(installer, 'atomic', side_effect=publish):
            with self.assertRaisesRegex(RuntimeError, 'TEST_PUBLICATION_FAILURE'):
                self.execute()
        self.assertEqual((self.root / first).read_bytes(), b'operator concurrent edit')
        self.assertEqual((self.root / second).read_bytes(), self.before[second])

    def test_unsafe_source_permissions_and_symlinks_are_rejected(self):
        first = next(iter(installer.BASELINES))
        path = self.root / first
        for mode in (0o775, 0o777, 0o2775, 0o1775):
            os.chmod(path, mode)
            with self.subTest(mode=mode), self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
                self.execute(check=True)
        path.unlink()
        path.symlink_to(self.unrelated)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_PATH'):
            self.execute(check=True)

    def test_missing_source_is_rejected(self):
        first = next(iter(installer.BASELINES))
        (self.root / first).unlink()
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_FILE_MISSING'):
            self.execute(check=True)

    def test_public_entry_point_refuses_other_root_or_nonimmutable_release(self):
        for root, release in ((self.root, self.release), (installer.APP_ROOT, 'main')):
            with self.subTest(root=root, release=release), self.assertRaisesRegex(RuntimeError, 'FIXED_ROOT_RELEASE_REQUIRED'):
                installer.install(root, release, check_only=True)


if __name__ == '__main__':
    unittest.main()
