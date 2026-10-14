"""Focused tests for the seven-file POS followup, independent of the first installer."""
import importlib.util
import json
import os
import pathlib
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

PACKAGE = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('app_order_items_pos_installer', PACKAGE / 'deployment/install_app_order_items_pos.py')
installer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(installer)


class PosUpgradeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name) / 'public_html'
        self.root.mkdir()
        self.release = 'a' * 40
        self.previous, self.reviewed = {}, {}
        for relative in installer.EXISTING_FILES:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            self.previous[relative] = b'old feature\n' + b'body\n' * 30 + b'local appendix\n'
            self.reviewed[relative] = self.previous[relative].replace(b'old feature', b'POS feature')
            path.write_bytes(self.previous[relative])
        self.foundation = self.root / 'app/Models/Order.php'
        self.foundation.parent.mkdir(parents=True, exist_ok=True)
        self.foundation.write_bytes(b'preserve original foundation')
        os.chmod(self.foundation, 0o775)
        (self.root / installer.CATALOG_STYLE).write_bytes(b'existing POS styles')
        self.commands = []

    def tearDown(self):
        self.temporary.cleanup()

    def source(self, root, revision, relative):
        self.assertIn(relative, installer.EXISTING_FILES)
        return (self.previous if revision == installer.BASE else self.reviewed)[relative]

    def command(self, root, arguments):
        self.commands.append(arguments)
        return b''

    def runtime(self, root, php, probe, phase, views=()):
        self.assertEqual(phase, 'verify')
        return {'ok': True, 'routes_cached': True, 'route_cache': 'bootstrap/cache/routes-v7.php'}

    def execute(self, check=False, repair=False, runtime=None):
        with mock.patch.object(installer, 'source', side_effect=self.source), \
                mock.patch.object(installer, 'owned_command', side_effect=self.command), \
                mock.patch.object(installer, 'runtime', side_effect=runtime or self.runtime):
            installer._install_locked(self.root, self.release, '/usr/bin/php', check,
                                      os.getuid(), os.getgid(), repair)

    def test_upgrade_uses_exact_installed_release_and_seven_file_scope(self):
        self.assertEqual(installer.BASE, 'adc9e46a86df569bd6dab15e9f4302fc1c55aa91')
        self.assertEqual(len(installer.EXISTING_FILES), 7)
        self.assertEqual(set(installer.PUBLISH_ORDER), set(installer.EXISTING_FILES))
        self.execute()
        for relative, content in self.reviewed.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
        self.assertEqual(self.foundation.read_bytes(), b'preserve original foundation')
        self.assertEqual(stat.S_IMODE(self.foundation.stat().st_mode), 0o775)
        artisan = [command[2:] for command in self.commands if len(command) > 1 and command[1] == 'artisan']
        self.assertEqual(artisan, [['view:clear']])
        backup = next((self.root.parent / 'app-order-items-pos-backups').iterdir())
        manifest = json.loads((backup / 'manifest.json').read_text())
        self.assertEqual(manifest['baseline'], installer.BASE)
        self.assertEqual(set(manifest['files']), set(installer.EXISTING_FILES))

    def test_missing_foundation_file_stops_before_publication(self):
        (self.root / installer.EXISTING_FILES[-1]).unlink()
        with self.assertRaisesRegex(RuntimeError, 'INSTALLED_ORDER_EDITOR_REQUIRED'):
            self.execute()
        self.assertEqual((self.root / installer.EXISTING_FILES[0]).read_bytes(), self.previous[installer.EXISTING_FILES[0]])
        self.assertFalse((self.root.parent / 'app-order-items-pos-backups').exists())

    def test_missing_existing_pos_stylesheet_stops_before_publication(self):
        (self.root / installer.CATALOG_STYLE).unlink()
        with self.assertRaisesRegex(RuntimeError, 'POS_CATALOG_STYLE_MISSING'):
            self.execute()
        self.assertEqual((self.root / installer.EXISTING_FILES[0]).read_bytes(), self.previous[installer.EXISTING_FILES[0]])
        self.assertFalse((self.root.parent / 'app-order-items-pos-backups').exists())

    def test_existing_pos_stylesheet_symlink_is_rejected(self):
        style = self.root / installer.CATALOG_STYLE
        style.unlink()
        style.symlink_to(self.foundation)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_PATH'):
            self.execute()
        self.assertFalse((self.root.parent / 'app-order-items-pos-backups').exists())

    def test_preserves_unrelated_edits_and_is_idempotent(self):
        relative = installer.EXISTING_FILES[0]
        path = self.root / relative
        path.write_bytes(self.previous[relative].replace(b'local appendix', b'operator appendix'))
        self.execute()
        expected = self.reviewed[relative].replace(b'local appendix', b'operator appendix')
        self.assertEqual(path.read_bytes(), expected)
        old_inode = path.stat().st_ino
        self.execute()
        self.assertEqual(path.read_bytes(), expected)
        self.assertEqual(path.stat().st_ino, old_inode)

    def test_conflict_stops_without_replacing_any_source(self):
        relative = installer.EXISTING_FILES[-1]
        path = self.root / relative
        path.write_bytes(self.previous[relative].replace(b'old feature', b'conflicting feature'))
        with self.assertRaisesRegex(RuntimeError, 'SOURCE_PATCH_CONFLICT'):
            self.execute()
        self.assertFalse((self.root.parent / 'app-order-items-pos-backups').exists())
        self.assertEqual((self.root / installer.EXISTING_FILES[0]).read_bytes(), self.previous[installer.EXISTING_FILES[0]])

    def test_preflight_requires_routes_and_fractional_storage_before_writes(self):
        for reason in ('ORDER_ITEMS_ROUTE_INVALID', 'CART_QUANTITY_NOT_FRACTIONAL', 'POS_CATALOG_STYLE_MISSING'):
            def unavailable(*arguments, **keywords):
                raise RuntimeError(reason)
            with self.subTest(reason=reason), self.assertRaisesRegex(RuntimeError, reason):
                self.execute(runtime=unavailable)
        self.assertFalse((self.root.parent / 'app-order-items-pos-backups').exists())
        for relative, content in self.previous.items():
            self.assertEqual((self.root / relative).read_bytes(), content)

    def test_preflight_does_not_change_source_modes_or_inodes(self):
        before = {}
        for relative in installer.EXISTING_FILES:
            path = self.root / relative
            os.chmod(path, 0o775)
            before[relative] = path.stat()
        self.execute(check=True, repair=True)
        for relative, old in before.items():
            current = (self.root / relative).stat()
            self.assertEqual((current.st_ino, current.st_mode), (old.st_ino, old.st_mode))
            self.assertEqual((self.root / relative).read_bytes(), self.previous[relative])
        self.assertFalse((self.root.parent / 'app-order-items-pos-backups').exists())
        self.assertFalse(any('artisan' in command for command in self.commands))

    def test_repair_is_scoped_atomic_and_keeps_hardlink_alias_unchanged(self):
        relative = installer.EXISTING_FILES[0]
        path = self.root / relative
        os.chmod(path, 0o775)
        alias = self.root.parent / 'previous-source.php'
        os.link(path, alias)
        self.execute(repair=True)
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o755)
        self.assertEqual(alias.read_bytes(), self.previous[relative])
        self.assertEqual(stat.S_IMODE(alias.stat().st_mode), 0o775)
        self.assertEqual(stat.S_IMODE(self.foundation.stat().st_mode), 0o775)

    def test_strict_default_refuses_group_write_and_flag_refuses_world_write(self):
        path = self.root / installer.EXISTING_FILES[0]
        os.chmod(path, 0o775)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.execute()
        os.chmod(path, 0o777)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.execute(repair=True)

    def test_runtime_failure_restores_installed_bytes_and_original_modes(self):
        for relative in installer.EXISTING_FILES:
            os.chmod(self.root / relative, 0o775)
        def failure(root, php, probe, phase, views=()):
            if not views:
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            return self.runtime(root, php, probe, phase, views)
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(repair=True, runtime=failure)
        for relative, content in self.previous.items():
            self.assertEqual((self.root / relative).read_bytes(), content)
            self.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o775)

    def test_concurrent_operator_mode_change_does_not_abort_other_rollbacks(self):
        relative = installer.EXISTING_FILES[0]
        def failure(root, php, probe, phase, views=()):
            if not views:
                os.chmod(self.root / relative, 0o775)
                raise RuntimeError('TEST_RUNTIME_FAILURE')
            return self.runtime(root, php, probe, phase, views)
        with self.assertRaisesRegex(RuntimeError, 'TEST_RUNTIME_FAILURE'):
            self.execute(runtime=failure)
        self.assertEqual((self.root / relative).read_bytes(), self.reviewed[relative])
        for target in installer.EXISTING_FILES[1:]:
            self.assertEqual((self.root / target).read_bytes(), self.previous[target])

    def test_unchanged_reviewed_source_preserves_unrelated_live_edits(self):
        relative = installer.EXISTING_FILES[0]
        self.reviewed[relative] = self.previous[relative]
        path = self.root / relative
        live = self.previous[relative].replace(b'local appendix', b'operator appendix')
        path.write_bytes(live)
        before = path.stat().st_ino
        self.execute()
        self.assertEqual(path.read_bytes(), live)
        self.assertEqual(path.stat().st_ino, before)


class PosWrapperTest(unittest.TestCase):
    def test_followup_helper_and_flag_forwarding(self):
        text = (PACKAGE / 'deployment/apply_app_order_items_pos_root.sh').read_text()
        self.assertIn('order_items_helper=install_app_order_items_pos.py', text)
        parser = text.split('\nshift\n', 1)[1].split('\norder_items_tmp=', 1)[0]
        flags = ['--repair-permissions', '--check']
        result = subprocess.run(['bash', '-s', '--', *flags],
            input=parser + '\nprintf "%s\\n" "${order_items_flags[@]}"\n', text=True, capture_output=True)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stdout.strip().splitlines(), flags)


if __name__ == '__main__':
    unittest.main()
