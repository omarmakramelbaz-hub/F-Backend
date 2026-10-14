"""python3 -m unittest discover -s tests/deployment -p 'test_order_board_lifecycle_installer.py'"""
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
SPEC = importlib.util.spec_from_file_location('order_board_lifecycle_installer', PACKAGE / 'deployment/order_board_lifecycle_installer.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class SurgicalPatchTest(unittest.TestCase):
    def test_live_meta_overlay_survives_and_patch_is_idempotent(self):
        base = b'meta old\n' + b'stable\n' * 25 + b'old audio\n'
        reviewed = base.replace(b'old audio', b'new audio')
        current = base.replace(b'meta old', b'meta newer live integration')
        expected = current.replace(b'old audio', b'new audio')
        patched = installer.surgical_patch(current, base, reviewed, 'board_detail.blade.php')
        self.assertEqual(patched, expected)
        self.assertEqual(installer.surgical_patch(patched, base, reviewed, 'board_detail.blade.php'), expected)

    def test_conflicting_audio_overlay_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            installer.surgical_patch(b'header\noperator audio\nfooter\n', b'header\nold\nfooter\n', b'header\nnew\nfooter\n', 'footer')

    def test_duplicate_patch_anchors_are_rejected(self):
        padding = b'stable\n' * 10
        block = padding + b'old\n' + padding
        base = b'lead\n' + block + b'trailer\n'
        reviewed = base.replace(b'old\n', b'new\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            installer.surgical_patch(b'lead\n' + block + block + b'trailer\n', base, reviewed, 'ambiguous')


class SourceIntegrityTest(unittest.TestCase):
    def test_git_source_blob_is_verified(self):
        payload = b'reviewed source\n'
        with mock.patch.object(installer, 'owned_command', side_effect=[payload, (installer.blob(payload) + '\n').encode()]):
            self.assertEqual(installer.source(pathlib.Path('/app'), 'a' * 40, 'file.php'), payload)
        with mock.patch.object(installer, 'owned_command', side_effect=[payload, b'f' * 40 + b'\n']):
            with self.assertRaisesRegex(RuntimeError, 'SOURCE_BLOB_MISMATCH'):
                installer.source(pathlib.Path('/app'), 'a' * 40, 'file.php')

    def test_cache_paths_cannot_escape_views_directory(self):
        for paths in (['/tmp/other.php'], ['storage/framework/views/../other.php'], ['storage/framework/views/abc.php'] * 2, None):
            with self.subTest(paths=paths), self.assertRaisesRegex(RuntimeError, 'COMPILED_VIEW_PATH_UNSUPPORTED'):
                installer.validated_caches({'view_caches': paths})


class AtomicFileTest(unittest.TestCase):
    def test_atomic_publish_keeps_old_hardlink_contents(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            path, alias = root / 'source.php', root / 'historical.php'
            path.write_bytes(b'old')
            os.link(path, alias)
            installer.atomic(path, b'new', 0o640, os.getuid(), os.getgid())
            self.assertEqual(alias.read_bytes(), b'old')
            self.assertEqual(path.read_bytes(), b'new')
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o640)

    def test_symlink_leaf_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            original, link = root / 'source', root / 'link'
            original.write_bytes(b'old')
            link.symlink_to(original)
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_PATH'):
                installer.atomic(link, b'new', 0o644, os.getuid(), os.getgid())
            self.assertEqual(original.read_bytes(), b'old')


class PermissionTest(unittest.TestCase):
    def path(self, uid=1001, gid=1003, mode=0o775):
        path = mock.Mock()
        path.parents = ()
        path.is_symlink.return_value = False
        path.exists.return_value = True
        path.read_bytes.return_value = b'reviewed'
        path.stat.return_value = types.SimpleNamespace(st_mode=stat.S_IFREG | mode,
            st_uid=uid, st_gid=gid, st_dev=1, st_ino=1, st_mtime_ns=1, st_nlink=1)
        return path

    def test_group_write_exception_requires_explicit_matching_owner_and_primary_group(self):
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            installer.snapshot(self.path(), 1001)
        self.assertEqual(installer.snapshot(self.path(), 1001, 1003)['mode'], 0o775)
        for uid, gid in ((0, 1003), (1001, 9999), (9999, 1003)):
            with self.subTest(uid=uid, gid=gid), self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
                installer.snapshot(self.path(uid=uid, gid=gid), 1001, 1003)
        for mode in (0o777, 0o2775, 0o1775, 0o4775):
            with self.subTest(mode=mode), self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
                installer.snapshot(self.path(mode=mode), 1001, 1003)


class WrapperTest(unittest.TestCase):
    def parse(self, *flags):
        content = (PACKAGE / 'deployment/apply_order_board_lifecycle_root.sh').read_text()
        parser = content.split('\nshift\n', 1)[1].split('\nboard_lifecycle_tmp=', 1)[0]
        return subprocess.run(['bash', '-s', '--', *flags], input=parser + '\nprintf "%s\\n" "${board_lifecycle_flags[@]}"\n', text=True, capture_output=True)

    def test_known_flags_and_order(self):
        for flags in ((), ('--check',), ('--repair-permissions',), ('--check', '--repair-permissions'), ('--repair-permissions', '--check')):
            with self.subTest(flags=flags):
                result = self.parse(*flags)
                self.assertEqual(result.returncode, 0)
                self.assertEqual(result.stdout.strip().splitlines(), list(flags))

    def test_unknown_or_duplicate_flags_fail(self):
        for flags in (('--other',), ('--check', '--check'), ('--repair-permissions', '--repair-permissions')):
            with self.subTest(flags=flags):
                self.assertNotEqual(self.parse(*flags).returncode, 0)


class InstallerTransactionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name) / 'public_html'
        self.root.mkdir()
        self.release = 'a' * 40
        self.before, self.after = {}, {}
        for relative in installer.EXISTING_FILES:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            self.before[relative] = b'first\nold feature\nlast\n'
            self.after[relative] = b'first\nnew feature\nlast\n'
            path.write_bytes(self.before[relative])
        for relative in installer.NEW_FILES:
            (self.root / relative).parent.mkdir(parents=True, exist_ok=True)
            self.after[relative] = b'new source\n'
        self.cache_relative = 'storage/framework/views/abcd1234.php'
        self.cache = self.root / self.cache_relative
        self.cache.parent.mkdir(parents=True)
        self.cache.write_bytes(b'original compiled detail view')
        self.card_cache_relative = 'storage/framework/views/abcd5678.php'
        self.card_cache = self.root / self.card_cache_relative
        self.card_cache.write_bytes(b'original compiled card view')
        self.unrelated_cache = self.cache.parent / 'cccc.php'
        self.unrelated_cache.write_bytes(b'unrelated compiled template')
        self.commands = []
        self.runtime_calls = 0

    def tearDown(self):
        self.temporary.cleanup()

    def source(self, root, revision, relative):
        return (self.before if revision == installer.BASE else self.after)[relative]

    def command(self, root, arguments):
        self.commands.append(arguments)
        return b''

    def runtime(self, root, php, probe, views_manifest):
        self.runtime_calls += 1
        mapping = {
            'resources/views/admin/orders/board_detail.blade.php': self.cache_relative,
            'resources/views/admin/orders/board_card.blade.php': self.card_cache_relative,
        }
        return {'ok': True, 'view_caches': [mapping[relative] for relative in json.loads(views_manifest.read_text())]}

    def execute(self, check=False, runtime=None, repair=False):
        with mock.patch.object(installer, 'source', side_effect=self.source), \
                mock.patch.object(installer, 'owned_command', side_effect=self.command), \
                mock.patch.object(installer, 'runtime', side_effect=runtime or self.runtime):
            installer._install_locked(self.root, self.release, '/usr/bin/php', check, os.getuid(), os.getgid(), repair)

    def test_preflight_changes_no_source_cache_inode_or_mode(self):
        tracked = {p: p.stat() for p in [self.cache, self.card_cache, *(self.root / p for p in self.before)]}
        self.execute(check=True)
        for path, old in tracked.items():
            actual = path.stat()
            self.assertEqual((old.st_ino, old.st_mode, old.st_mtime_ns), (actual.st_ino, actual.st_mode, actual.st_mtime_ns))
        self.assertTrue(self.cache.exists())
        self.assertTrue(self.card_cache.exists())
        self.assertFalse((self.root.parent / 'order-board-lifecycle-backups').exists())
        self.assertFalse(any('artisan' in command for command in self.commands))
        self.assertFalse((self.root / installer.NEW_FILES[0]).exists())

    def test_public_preflight_does_not_create_a_lock(self):
        user = types.SimpleNamespace(pw_uid=self.root.stat().st_uid, pw_gid=self.root.stat().st_gid)
        with mock.patch.object(installer, 'APP_ROOT', self.root), \
                mock.patch.object(installer, 'PHP', '/usr/bin/php'), \
                mock.patch.object(installer.os, 'geteuid', return_value=0), \
                mock.patch.object(installer.pwd, 'getpwnam', return_value=user), \
                mock.patch.object(installer, '_install_locked') as locked:
            installer.install(self.root, self.release, '/usr/bin/php', check_only=True)
            locked.assert_called_once_with(self.root, self.release, '/usr/bin/php', True, user.pw_uid, user.pw_gid, False)
        self.assertFalse((self.root.parent / '.order-board-lifecycle-update.lock').exists())

    def test_public_entry_point_refuses_nonfixed_root_php_or_revision(self):
        for root, release, php in ((self.root, self.release, installer.PHP),
                                  (installer.APP_ROOT, self.release, '/usr/bin/php'),
                                  (installer.APP_ROOT, 'main', installer.PHP)):
            with self.subTest(root=root, release=release, php=php), self.assertRaisesRegex(RuntimeError, 'FIXED_ROOT_RELEASE_REQUIRED'):
                installer.install(root, release, php, check_only=True)

    def test_success_backs_up_sources_and_invalidates_only_changed_view(self):
        self.execute()
        for relative, expected in self.after.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)
        self.assertFalse(self.cache.exists())
        self.assertFalse(self.card_cache.exists())
        self.assertEqual(self.unrelated_cache.read_bytes(), b'unrelated compiled template')
        backup = next((self.root.parent / 'order-board-lifecycle-backups').iterdir())
        manifest = json.loads((backup / 'manifest.json').read_text())
        self.assertEqual(manifest['baseline'], installer.BASE)
        self.assertEqual(manifest['target_blobs'], {relative: installer.blob(payload) for relative, payload in self.after.items()})
        self.assertIsNone(manifest['files'][installer.NEW_FILES[0]])
        self.assertEqual((backup / self.cache_relative).read_bytes(), b'original compiled detail view')
        self.assertEqual((backup / self.card_cache_relative).read_bytes(), b'original compiled card view')
        self.assertFalse(any('artisan' in command for command in self.commands))
        self.assertTrue(any('-l' in command for command in self.commands))

    def test_already_installed_view_does_not_clear_its_cache(self):
        for relative, expected in self.after.items():
            (self.root / relative).write_bytes(expected)
        self.execute()
        self.assertEqual(self.cache.read_bytes(), b'original compiled detail view')
        self.assertEqual(self.card_cache.read_bytes(), b'original compiled card view')

    def test_only_changed_card_or_detail_view_cache_is_invalidated(self):
        views = {
            'resources/views/admin/orders/board_detail.blade.php': self.cache,
            'resources/views/admin/orders/board_card.blade.php': self.card_cache,
        }
        for unchanged in views:
            with self.subTest(unchanged=unchanged):
                for relative, cache in views.items():
                    (self.root / relative).write_bytes(self.after[relative] if relative == unchanged else self.before[relative])
                    cache.write_bytes(b'cached view to preserve if unchanged')
                self.execute()
                for relative, cache in views.items():
                    self.assertEqual(cache.exists(), relative == unchanged)

    def test_actual_production_baseline_transaction_and_read_only_rerun(self):
        # These are the real reviewed files; no alternate local-baseline alias is used.
        for relative in installer.EXISTING_FILES:
            self.before[relative] = subprocess.check_output(['git', 'show', installer.BASE + ':' + relative], cwd=PACKAGE)
            self.after[relative] = (PACKAGE / relative).read_bytes()
            (self.root / relative).write_bytes(self.before[relative])
        for relative in installer.NEW_FILES:
            self.after[relative] = (PACKAGE / relative).read_bytes()
        self.execute(check=True)
        for relative, expected in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)
        self.execute()
        for relative, expected in self.after.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)
        published = {relative: (self.root / relative).stat().st_ino for relative in self.after}
        self.execute(check=True)
        self.assertEqual(published, {relative: (self.root / relative).stat().st_ino for relative in self.after})

    def test_conflict_prevents_all_publication_and_cache_changes(self):
        (self.root / installer.EXISTING_FILES[-1]).write_bytes(b'first\noperator edit\nlast\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            self.execute()
        self.assertFalse((self.root / installer.NEW_FILES[0]).exists())
        self.assertTrue(self.cache.exists())
        self.assertFalse((self.root.parent / 'order-board-lifecycle-backups').exists())

    def test_new_file_collision_is_not_overwritten(self):
        path = self.root / installer.NEW_FILES[0]
        path.write_bytes(b'operator new file')
        with self.assertRaisesRegex(RuntimeError, 'NEW_FILE_COLLISION'):
            self.execute()
        self.assertEqual(path.read_bytes(), b'operator new file')

    def test_runtime_failure_rolls_back_sources_new_file_and_changed_cache(self):
        def failure(root, php, probe, manifest):
            if self.runtime_calls:
                raise RuntimeError('TEST_VERIFY_FAILURE')
            return self.runtime(root, php, probe, manifest)
        with self.assertRaisesRegex(RuntimeError, 'TEST_VERIFY_FAILURE'):
            self.execute(runtime=failure)
        for relative, expected in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)
        self.assertFalse((self.root / installer.NEW_FILES[0]).exists())
        self.assertEqual(self.cache.read_bytes(), b'original compiled detail view')
        self.assertEqual(self.card_cache.read_bytes(), b'original compiled card view')

    def test_concurrent_operator_edit_is_preserved_during_rollback(self):
        path = self.root / installer.EXISTING_FILES[0]
        def failure(root, php, probe, manifest):
            if self.runtime_calls:
                path.write_bytes(b'operator concurrent edit')
                raise RuntimeError('TEST_VERIFY_FAILURE')
            return self.runtime(root, php, probe, manifest)
        with self.assertRaisesRegex(RuntimeError, 'TEST_VERIFY_FAILURE'):
            self.execute(runtime=failure)
        self.assertEqual(path.read_bytes(), b'operator concurrent edit')
        for relative in installer.EXISTING_FILES[1:]:
            self.assertEqual((self.root / relative).read_bytes(), self.before[relative])

    def test_concurrent_recompiled_cache_is_preserved_during_rollback(self):
        def failure(root, php, probe, manifest):
            if self.runtime_calls:
                self.cache.write_bytes(b'concurrently compiled view')
                raise RuntimeError('TEST_VERIFY_FAILURE')
            return self.runtime(root, php, probe, manifest)
        with self.assertRaisesRegex(RuntimeError, 'TEST_VERIFY_FAILURE'):
            self.execute(runtime=failure)
        self.assertEqual(self.cache.read_bytes(), b'concurrently compiled view')
        self.assertEqual(self.card_cache.read_bytes(), b'original compiled card view')

    def test_trusted_permission_repair_is_opt_in_and_preflight_is_read_only(self):
        for relative in installer.EXISTING_FILES:
            os.chmod(self.root / relative, 0o775)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.execute()
        self.execute(check=True, repair=True)
        for relative in installer.EXISTING_FILES:
            self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o775)
        self.execute(repair=True)
        for relative in installer.EXISTING_FILES:
            self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o755)

    def test_permission_repair_refuses_group_writable_compiled_cache(self):
        os.chmod(self.cache, 0o775)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.execute(repair=True)
        self.assertFalse((self.root / installer.NEW_FILES[0]).exists())

    def test_runtime_failure_restores_original_group_write_modes(self):
        for relative in installer.EXISTING_FILES:
            os.chmod(self.root / relative, 0o775)
        def failure(root, php, probe, manifest):
            if self.runtime_calls:
                raise RuntimeError('TEST_VERIFY_FAILURE')
            return self.runtime(root, php, probe, manifest)
        with self.assertRaisesRegex(RuntimeError, 'TEST_VERIFY_FAILURE'):
            self.execute(runtime=failure, repair=True)
        for relative, expected in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)
            self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o775)

    def test_publish_order_has_service_before_referencing_consumers(self):
        self.assertEqual(set(installer.PUBLISH_ORDER), set(installer.EXISTING_FILES + installer.NEW_FILES))
        self.assertEqual(len(installer.PUBLISH_ORDER), len(set(installer.PUBLISH_ORDER)))
        self.assertLess(installer.PUBLISH_ORDER.index(installer.NEW_FILES[0]),
                        installer.PUBLISH_ORDER.index('app/Services/Dashboard/OrderBoardService.php'))
        self.assertLess(installer.PUBLISH_ORDER.index('app/Services/Dashboard/GoStoreBoardActions.php'),
                        installer.PUBLISH_ORDER.index('app/Services/Dashboard/OrderBoardService.php'))
        self.assertLess(installer.PUBLISH_ORDER.index('app/Services/Dashboard/OrderBoardClock.php'),
                        installer.PUBLISH_ORDER.index('app/Services/Dashboard/GoStoreBoardActions.php'))
        self.assertLess(installer.PUBLISH_ORDER.index('app/Services/Dashboard/OrderBoardClock.php'),
                        installer.PUBLISH_ORDER.index('app/Observers/OrderBoardClockObserver.php'))
        self.assertLess(installer.PUBLISH_ORDER.index('app/Http/Controllers/CheckoutController.php'),
                        installer.PUBLISH_ORDER.index('app/Services/Dashboard/OrderBoardService.php'))
        self.assertLess(installer.PUBLISH_ORDER.index('public/dashboard/css/order-board.css'),
                        installer.PUBLISH_ORDER.index('resources/views/admin/orders/board_card.blade.php'))
        self.assertLess(installer.PUBLISH_ORDER.index('app/Services/Dashboard/OrderBoardService.php'),
                        installer.PUBLISH_ORDER.index('app/Http/Controllers/Dashboard/OrderBoardController.php'))



if __name__ == '__main__':
    unittest.main()
