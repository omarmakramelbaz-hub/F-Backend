#!/usr/bin/env python3
"""Isolated native sidebar deployment fixtures; no live files or PHP execution."""
import contextlib
import importlib.util
import io
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location(
    'meta_sidebar_linker', pathlib.Path(__file__).resolve().parents[1]
    / 'deployment/link_meta_sidebar.py')
linker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(linker)

MENU = 'resources/views/admin/layouts/menu.blade.php'
WHATSAPP = 'resources/views/admin/whatsapp/sidebar.blade.php'
META = 'resources/views/admin/meta/sidebar.blade.php'
NAVIGATION = 'public/dashboard/js/dashboard-navigation.js'
NATIVE = (
    "// Native source with unrelated custom routes preserved.\n"
    "const priority = node => paths(node).some(path => /\\/admin\\/(dashboard|whatsapp|desktop-pos|phone-orders|custom-route)\\/?$/.test(path));\n"
    "    placeShortcut('whatsapp', 'dashboard');\n"
    "    placeShortcut('desktop-pos', 'go-stores');\n"
    "// Other native menu behavior stays untouched.\n"
)


class NavigationTransformTests(unittest.TestCase):
    def test_exact_delta_preserves_other_native_routes_and_calls(self):
        expected = NATIVE.replace('dashboard|whatsapp|desktop-pos',
                                  'dashboard|whatsapp|meta|desktop-pos').replace(
            "    placeShortcut('whatsapp', 'dashboard');\n",
            "    placeShortcut('whatsapp', 'dashboard');\n"
            "    placeShortcut('meta', 'whatsapp');\n")
        self.assertEqual(linker.navigation_contents(NATIVE), expected)
        self.assertEqual(linker.navigation_contents(expected), expected)

    def test_misplaced_existing_shortcut_moves_without_duplicates(self):
        source = NATIVE.replace('dashboard|whatsapp', 'dashboard|meta|whatsapp')
        source += "    placeShortcut(\"meta\", \"whatsapp\");\n"
        target = linker.navigation_contents(source)
        self.assertEqual(target.count("placeShortcut('meta', 'whatsapp');"), 1)
        self.assertIn("placeShortcut('whatsapp', 'dashboard');\n"
                      "    placeShortcut('meta', 'whatsapp');\n", target)
        self.assertEqual(linker.navigation_contents(target), target)

    def test_crlf_and_terminal_anchor_remain_idempotent(self):
        for newline in ('\n', '\r\n'):
            for trailing in (True, False):
                with self.subTest(newline=newline, trailing=trailing):
                    source = NATIVE.split("    placeShortcut('desktop-pos'", 1)[0]
                    if not trailing:
                        source = source.rstrip('\n')
                    source = source.replace('\n', newline)
                    target = linker.navigation_contents(source)
                    self.assertEqual(linker.navigation_contents(target), target)
                    self.assertIn("placeShortcut('whatsapp', 'dashboard');" + newline
                                  + "    placeShortcut('meta', 'whatsapp');" + newline, target)
                    if newline == '\r\n':
                        self.assertNotIn('\n', target.replace('\r\n', ''))

    def test_unknown_and_ambiguous_native_anchors_are_rejected(self):
        cases = [
            NATIVE.replace('const priority =', 'const preferred ='),
            NATIVE + NATIVE,
            NATIVE.replace('dashboard|whatsapp|desktop-pos', 'dashboard|desktop-pos'),
            NATIVE.replace('dashboard|whatsapp', 'dashboard|meta|meta|whatsapp'),
            NATIVE.replace("placeShortcut('whatsapp', 'dashboard');", "placeShortcut('whatsapp', 'home');"),
            NATIVE + "placeShortcut('meta', 'dashboard');\n",
            NATIVE + "placeShortcut('meta', 'whatsapp');\nplaceShortcut('meta', 'whatsapp');\n",
        ]
        for source in cases:
            with self.subTest(source=source), self.assertRaises(ValueError):
                linker.navigation_contents(source)


class SidebarLinkTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-sidebar-test-')
        self.root = pathlib.Path(self.temporary.name) / 'application'
        self.root.mkdir()
        self.original = {
            MENU: b"<ul>\n  @include('admin.whatsapp.sidebar')\n  @include('admin.meta.sidebar')\n</ul>\n",
            WHATSAPP: b'<li>Existing WhatsApp link</li>\n',
            META: b'<li>Existing server-authorized Meta link</li>\n',
            NAVIGATION: NATIVE.encode(),
            '.env': b'PRIVATE_VALUE=must-not-be-read-or-changed\n',
        }
        for relative, contents in self.original.items():
            self.write(relative, contents)
        self.calls = []
        self.output = ''

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, relative, contents, mode=0o640):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(contents)
        path.chmod(mode)

    def run_linker(self, fail=False):
        def run(args, **kwargs):
            self.calls.append((args, kwargs))
            if fail:
                raise subprocess.CalledProcessError(1, args)
            return subprocess.CompletedProcess(args, 0, b'', b'')
        output = io.StringIO()
        with mock.patch.object(sys, 'argv', ['link', '--root', str(self.root), '--php', 'php-fixture']), \
                mock.patch.object(linker.os, 'geteuid', return_value=1001), \
                mock.patch.object(linker.subprocess, 'run', side_effect=run), \
                contextlib.redirect_stdout(output):
            try:
                linker.main()
            finally:
                self.output = output.getvalue()

    def test_native_js_only_publication_has_private_backup_and_preserves_modes(self):
        before = {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino)
                  for rel in self.original}
        self.run_linker()
        for rel in self.original:
            path = self.root / rel
            self.assertEqual(path.stat().st_mode & 0o777, 0o640)
            if rel != NAVIGATION:
                self.assertEqual((path.read_bytes(), path.stat().st_ino), before[rel])
        self.assertEqual((self.root / NAVIGATION).read_text(), linker.navigation_contents(NATIVE))
        backup_parent = self.root.parent / 'meta-release-backups'
        self.assertEqual(backup_parent.stat().st_mode & 0o777, 0o700)
        backup = next(backup_parent.iterdir())
        saved = backup / NAVIGATION
        self.assertEqual(saved.read_bytes(), self.original[NAVIGATION])
        self.assertEqual(saved.stat().st_mode & 0o777, 0o600)
        self.assertEqual([path.relative_to(backup).as_posix() for path in backup.rglob('*') if path.is_file()], [NAVIGATION])
        self.assertEqual(self.calls[0][0], ['php-fixture', 'artisan', 'view:clear'])
        self.assertEqual(self.calls[0][1]['cwd'], self.root)
        self.assertIn('META_NATIVE_SIDEBAR_READY', self.output)
        self.assertIn('META_SIDEBAR_READY', self.output)
        after = {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino)
                 for rel in self.original}
        self.run_linker()
        self.assertEqual(after, {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino)
                                 for rel in self.original})
        self.assertEqual(len(list(backup_parent.iterdir())), 1)
        self.assertEqual(len(self.calls), 1)

    def test_cache_failure_rolls_back_all_three_changed_files(self):
        self.write(MENU, b"<ul>\n  @include('admin.whatsapp.sidebar')\n</ul>\n")
        self.write(WHATSAPP, self.original[WHATSAPP] + b"@include('admin.meta.sidebar')\n")
        before = {rel: (self.root / rel).read_bytes() for rel in self.original}
        with self.assertRaises(subprocess.CalledProcessError):
            self.run_linker(fail=True)
        for rel, contents in before.items():
            self.assertEqual((self.root / rel).read_bytes(), contents)
            self.assertEqual((self.root / rel).stat().st_mode & 0o777, 0o640)
        backup = next((self.root.parent / 'meta-release-backups').iterdir())
        for rel in (MENU, WHATSAPP, NAVIGATION):
            self.assertEqual((backup / rel).read_bytes(), before[rel])
        self.assertNotIn('META_SIDEBAR_READY', self.output)

    def test_unknown_native_source_stops_before_any_file_or_backup_changes(self):
        self.write(MENU, b"@include('admin.whatsapp.sidebar')\n")
        self.write(NAVIGATION, b'// Unrecognized native implementation\n')
        before = {rel: (self.root / rel).read_bytes() for rel in self.original}
        with self.assertRaisesRegex(ValueError, 'NATIVE_PRIORITY_ANCHOR_REQUIRED'):
            self.run_linker()
        self.assertEqual(before, {rel: (self.root / rel).read_bytes() for rel in self.original})
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_missing_native_script_supports_existing_blade_menu(self):
        (self.root / NAVIGATION).unlink()
        self.write(MENU, b"@include('admin.whatsapp.sidebar')")
        self.run_linker()
        self.assertEqual((self.root / MENU).read_bytes(),
                         b"@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar')\n")
        self.assertNotIn('META_NATIVE_SIDEBAR_READY', self.output)
        self.assertFalse((self.root / NAVIGATION).exists())

    def test_crlf_menu_moves_nested_meta_link_and_preserves_unrelated_markup(self):
        menu = b'<ul>\r\n\t<li>Native home</li>\r\n\t@includeIf("admin.whatsapp.sidebar")\r\n</ul>\r\n'
        sidebar = b'<li>WhatsApp</li>\r\n@includeIf("admin.meta.sidebar")\r\n'
        target = linker.sidebar_contents(menu.decode(), sidebar.decode())
        self.assertEqual(target, (
            '<ul>\r\n\t<li>Native home</li>\r\n\t@includeIf("admin.whatsapp.sidebar")\r\n'
            "\t@include('admin.meta.sidebar')\r\n</ul>\r\n",
            '<li>WhatsApp</li>\r\n'))
        self.assertEqual(linker.sidebar_contents(*target), target)

    def test_unrecognized_meta_hook_and_duplicate_whatsapp_anchor_fail_closed(self):
        cases = [
            b"@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar', ['custom' => true])\n",
            b"@include('admin.whatsapp.sidebar')\n@includeIf('admin.whatsapp.sidebar')\n",
        ]
        for menu in cases:
            with self.subTest(menu=menu):
                self.write(MENU, menu)
                with self.assertRaises(ValueError):
                    self.run_linker()
                self.assertEqual((self.root / MENU).read_bytes(), menu)
                self.assertEqual((self.root / NAVIGATION).read_bytes(), self.original[NAVIGATION])
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
