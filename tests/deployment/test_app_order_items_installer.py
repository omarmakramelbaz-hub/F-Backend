"""Run with: python3 -m unittest discover -s tests/deployment -p 'test_app_order_items_installer.py'."""
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
SPEC = importlib.util.spec_from_file_location('app_order_items_installer', PACKAGE / 'deployment/install_app_order_items.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class SurgicalPatchTest(unittest.TestCase):
    def test_preserves_live_six_am_date_filter_outside_editor_change(self):
        base = b'whereDate(created_at, date);\n' + b'unchanged\n' * 25 + b'old editor\n' + b'end\n' * 10
        current = base.replace(b'whereDate(created_at, date);', b'OperatingDay::start(date); OperatingDay::end(date);')
        reviewed = base.replace(b'old editor', b'new editor')
        result = installer.surgical_patch(current, base, reviewed, 'service.php')
        self.assertIn(b'OperatingDay::start(date); OperatingDay::end(date);', result)
        self.assertIn(b'new editor', result)
        self.assertNotIn(b'old editor', result)

    def test_conflicting_local_change_refuses_to_overwrite(self):
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            installer.surgical_patch(b'header\noperator edit\nfooter\n', b'header\nold\nfooter\n', b'header\nnew\nfooter\n', 'file')

    def test_already_installed_hunk_preserves_unrelated_changes(self):
        base = b'old title\n' + b'body\n' * 25 + b'old end\n'
        reviewed = base.replace(b'old title', b'new title')
        current = reviewed.replace(b'old end', b'operator end')
        self.assertEqual(installer.surgical_patch(current, base, reviewed, 'file'), current)

    def test_append_is_idempotent(self):
        base, reviewed = b'prefix\n', b'prefix\nnew line\n'
        once = installer.surgical_patch(base, base, reviewed, 'file')
        self.assertEqual(once, reviewed)
        self.assertEqual(installer.surgical_patch(once, base, reviewed, 'file'), once)

    def test_two_distant_hunks_apply(self):
        base = b'first\n' + b'body\n' * 30 + b'last\n'
        reviewed = base.replace(b'first', b'FIRST').replace(b'last', b'LAST')
        self.assertEqual(installer.surgical_patch(base, base, reviewed, 'file'), reviewed)


class AtomicFileTest(unittest.TestCase):
    def test_publish_preserves_existing_hardlink_backup(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            live, alias = root / 'live.php', root / 'old-backup.php'
            live.write_bytes(b'old')
            os.link(live, alias)
            installer.atomic(live, b'new', 0o640, os.getuid(), os.getgid())
            self.assertEqual(live.read_bytes(), b'new')
            self.assertEqual(alias.read_bytes(), b'old')
            self.assertEqual(live.stat().st_mode & 0o777, 0o640)

    def test_symlink_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            (root / 'original').write_bytes(b'old')
            (root / 'link').symlink_to(root / 'original')
            with self.assertRaisesRegex(RuntimeError, 'SYMLINK_PATH'):
                installer.atomic(root / 'link', b'new', 0o644, os.getuid(), os.getgid())
            self.assertEqual((root / 'original').read_bytes(), b'old')


class SnapshotPermissionTest(unittest.TestCase):
    def path(self, uid=1001, gid=1003, mode=0o775):
        path = mock.Mock()
        path.parents = ()
        path.is_symlink.return_value = False
        path.exists.return_value = True
        path.read_bytes.return_value = b'reviewed source'
        path.stat.return_value = types.SimpleNamespace(st_mode=stat.S_IFREG | mode,
            st_uid=uid, st_gid=gid, st_dev=1, st_ino=1, st_mtime_ns=1, st_nlink=1)
        return path

    def test_default_strict_rejects_group_writable_source(self):
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            installer.snapshot(self.path(), 1001)

    def test_repair_accepts_only_application_owned_primary_group(self):
        self.assertEqual(installer.snapshot(self.path(), 1001, 1003)['mode'], 0o775)
        for uid, gid in ((1001, 1004), (2000, 1003), (0, 1003)):
            with self.subTest(uid=uid, gid=gid), self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
                installer.snapshot(self.path(uid, gid), 1001, 1003)

    def test_repair_refuses_world_write_and_special_bits(self):
        for mode in (0o777, 0o6642, 0o2775, 0o1775):
            with self.subTest(mode=oct(mode)), self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
                installer.snapshot(self.path(mode=mode), 1001, 1003)


class WrapperArgumentsTest(unittest.TestCase):
    def parse(self, *flags):
        wrapper = (PACKAGE / 'deployment/apply_app_order_items_root.sh').read_text()
        parser = wrapper.split('\nshift\n', 1)[1].split('\norder_items_tmp=', 1)[0]
        return subprocess.run(['bash', '-s', '--', *flags],
            input=parser + '\nprintf "%s\\n" "${order_items_flags[@]}"\n', text=True, capture_output=True)

    def test_both_flag_orders_and_single_flags_are_forwarded(self):
        for flags in ((), ('--check',), ('--repair-permissions',),
                ('--check', '--repair-permissions'), ('--repair-permissions', '--check')):
            with self.subTest(flags=flags):
                result = self.parse(*flags)
                self.assertEqual(result.returncode, 0)
                self.assertEqual(result.stdout.strip().splitlines(), list(flags))

    def test_unknown_and_duplicate_flags_are_rejected(self):
        for flags in (('--unknown',), ('--check', '--check'),
                ('--repair-permissions', '--repair-permissions')):
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
        (self.root / 'bootstrap/cache').mkdir(parents=True)
        self.commands = []

    def tearDown(self):
        self.temporary.cleanup()

    def source(self, root, revision, relative):
        return (self.before if revision == installer.BASE else self.after)[relative]

    def command(self, root, arguments):
        self.commands.append(arguments)
        return b''

    def runtime(self, root, php, probe, phase, views=()):
        return {'ok': True, 'routes_cached': False, 'route_cache': 'bootstrap/cache/routes-v7.php'}

    def execute(self, check=False, runtime=None, repair=False):
        with mock.patch.object(installer, 'source', side_effect=self.source), \
                mock.patch.object(installer, 'owned_command', side_effect=self.command), \
                mock.patch.object(installer, 'runtime', side_effect=runtime or self.runtime):
            installer._install_locked(self.root, self.release, '/usr/bin/php', check, os.getuid(), os.getgid(), repair)

    def make_sources_group_writable(self):
        for relative in installer.EXISTING_FILES:
            os.chmod(self.root / relative, 0o775)

    def test_group_write_repair_is_opt_in(self):
        self.make_sources_group_writable()
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.execute()
        self.assertFalse((self.root / installer.MIGRATION).exists())

    def test_repair_preflight_changes_no_modes_or_inodes(self):
        self.make_sources_group_writable()
        originals = {relative: (self.root / relative).stat() for relative in installer.EXISTING_FILES}
        self.execute(check=True, repair=True)
        for relative, before in originals.items():
            after = (self.root / relative).stat()
            self.assertEqual((after.st_ino, after.st_mode), (before.st_ino, before.st_mode))
            self.assertEqual((self.root / relative).read_bytes(), self.before[relative])
        self.assertFalse((self.root / installer.MIGRATION).exists())
        self.assertFalse(any('artisan' in command for command in self.commands))

    def test_repair_all_reviewed_targets_preserves_hardlinks_and_unrelated_file(self):
        self.make_sources_group_writable()
        for relative in installer.NEW_FILES:
            (self.root / relative).write_bytes(self.after[relative])
            os.chmod(self.root / relative, 0o775)
        first = installer.EXISTING_FILES[0]
        alias = self.root.parent / 'old-hardlink.php'
        os.link(self.root / first, alias)
        unrelated = self.root / 'unrelated.php'
        unrelated.write_bytes(b'unrelated')
        os.chmod(unrelated, 0o775)
        self.execute(repair=True)
        for relative, content in self.after.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
            self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o755)
        self.assertEqual(alias.read_bytes(), self.before[first])
        self.assertEqual(stat.S_IMODE(alias.stat().st_mode), 0o775)
        self.assertEqual(stat.S_IMODE(unrelated.stat().st_mode), 0o775)
        backup = next((self.root.parent / 'app-order-items-backups').iterdir())
        manifest = json.loads((backup / 'manifest.json').read_text())
        self.assertTrue(all(row['mode'] == 0o775 for row in manifest['files'].values() if row))

    def test_mode_only_repair_republishes_matching_payload(self):
        relative = installer.EXISTING_FILES[0]
        path = self.root / relative
        path.write_bytes(self.after[relative])
        os.chmod(path, 0o775)
        old_inode = path.stat().st_ino
        self.execute(repair=True)
        self.assertEqual(path.read_bytes(), self.after[relative])
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o755)
        self.assertNotEqual(path.stat().st_ino, old_inode)

    def test_repair_runtime_failure_restores_original_group_write_mode(self):
        self.make_sources_group_writable()
        def failure(root, php, probe, phase, views=()):
            if phase == 'verify':
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            return self.runtime(root, php, probe, phase, views)
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(runtime=failure, repair=True)
        for relative, content in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
            self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o775)
        for relative in installer.NEW_FILES:
            self.assertFalse((self.root / relative).exists())

    def test_repair_does_not_accept_group_writable_route_cache(self):
        cache = self.root / 'bootstrap/cache/routes-v7.php'
        cache.write_bytes(b'route cache')
        os.chmod(cache, 0o775)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.execute(repair=True)
        self.assertFalse((self.root / installer.MIGRATION).exists())

    def test_preflight_performs_no_publication_or_migration(self):
        self.execute(check=True)
        for relative, content in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
        self.assertFalse((self.root / installer.MIGRATION).exists())
        self.assertFalse((self.root.parent / 'app-order-items-backups').exists())
        self.assertFalse(any('artisan' in command for command in self.commands))

    def test_success_publishes_expected_files_and_keeps_backup(self):
        self.execute()
        for relative, content in self.after.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
        backup = next((self.root.parent / 'app-order-items-backups').iterdir())
        manifest = json.loads((backup / 'manifest.json').read_text())
        self.assertEqual(manifest['baseline'], installer.BASE)
        first = installer.EXISTING_FILES[0]
        self.assertEqual((backup / first).read_bytes(), self.before[first])
        self.assertEqual(manifest['files'][installer.MIGRATION], None)
        migration_call = next(command for command in self.commands if 'migrate' in command)
        self.assertIn('--path=' + installer.MIGRATION, migration_call)

    def test_publish_order_has_all_dependencies_before_route_helpers(self):
        self.assertEqual(set(installer.PUBLISH_ORDER), set(installer.EXISTING_FILES + installer.NEW_FILES))
        self.assertEqual(len(installer.PUBLISH_ORDER), len(set(installer.PUBLISH_ORDER)))
        original_command = self.command
        def inspect_order(root, arguments):
            if 'migrate' in arguments:
                self.assertTrue((self.root / installer.MIGRATION).exists())
                self.assertFalse((self.root / 'app/Services/Dashboard/OrderBoardItems.php').exists())
            if 'route:clear' in arguments:
                for relative in installer.NEW_FILES:
                    self.assertEqual((self.root / relative).read_bytes(), self.after[relative])
                relative = 'app/Http/Controllers/Dashboard/OrderBoardController.php'
                self.assertEqual((self.root / relative).read_bytes(), self.after[relative])
                relative = 'app/Services/Dashboard/OrderBoardService.php'
                self.assertEqual((self.root / relative).read_bytes(), self.before[relative])
                relative = 'resources/views/admin/orders/board.blade.php'
                self.assertEqual((self.root / relative).read_bytes(), self.before[relative])
            return original_command(root, arguments)
        self.command = inspect_order
        self.execute()

    def test_runtime_failure_rolls_back_sources_and_removes_new_files(self):
        def failure(root, php, probe, phase, views=()):
            if phase == 'verify':
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            return self.runtime(root, php, probe, phase, views)
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(runtime=failure)
        for relative, content in self.before.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
        for relative in installer.NEW_FILES:
            self.assertFalse((self.root / relative).exists())

    def test_conflict_fails_before_any_files_are_published(self):
        relative = installer.EXISTING_FILES[-1]
        (self.root / relative).write_bytes(b'first\noperator edit\nlast\n')
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            self.execute()
        self.assertFalse((self.root / installer.MIGRATION).exists())
        self.assertFalse((self.root.parent / 'app-order-items-backups').exists())

    def test_new_file_collision_is_preserved(self):
        relative = installer.NEW_FILES[-1]
        (self.root / relative).write_bytes(b'operator new file\n')
        with self.assertRaisesRegex(RuntimeError, 'NEW_FILE_COLLISION'):
            self.execute()
        self.assertEqual((self.root / relative).read_bytes(), b'operator new file\n')

    def test_failure_restores_the_original_route_cache(self):
        cache = self.root / 'bootstrap/cache/routes-v7.php'
        cache.write_bytes(b'original route cache')
        def failure(root, php, probe, phase, views=()):
            if phase == 'verify':
                cache.unlink()
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            result = self.runtime(root, php, probe, phase, views)
            result['routes_cached'] = True
            return result
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(runtime=failure)
        self.assertEqual(cache.read_bytes(), b'original route cache')

    def test_rollback_preserves_a_concurrent_operator_source_edit(self):
        relative = installer.EXISTING_FILES[0]
        def failure(root, php, probe, phase, views=()):
            if phase == 'verify':
                (self.root / relative).write_bytes(b'concurrent operator edit')
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            return self.runtime(root, php, probe, phase, views)
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(runtime=failure)
        self.assertEqual((self.root / relative).read_bytes(), b'concurrent operator edit')
        for path in installer.EXISTING_FILES[1:]:
            self.assertEqual((self.root / path).read_bytes(), self.before[path])

    def test_rollback_preserves_concurrent_unsafe_mode_and_restores_other_files(self):
        relative = installer.EXISTING_FILES[0]
        def failure(root, php, probe, phase, views=()):
            if phase == 'verify':
                os.chmod(self.root / relative, 0o775)
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            return self.runtime(root, php, probe, phase, views)
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(runtime=failure)
        self.assertEqual((self.root / relative).read_bytes(), self.after[relative])
        self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o775)
        for path in installer.EXISTING_FILES[1:]:
            self.assertEqual((self.root / path).read_bytes(), self.before[path])


if __name__ == '__main__':
    unittest.main()
