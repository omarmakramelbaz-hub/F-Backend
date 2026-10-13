#!/usr/bin/env python3
"""Isolated deployment fixtures; never touch the live server or invoke PHP."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('meta_installer', pathlib.Path(__file__).resolve().parents[1] / 'deployment/install_meta_messaging.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
RELEASE = 'a' * 40
PROCESS_REL = 'app/Console/Commands/ProcessMetaMessages.php'
NAVIGATION_REL = 'public/dashboard/js/dashboard-navigation.js'
NAVIGATION_SOURCE = r'''(function () {
    const labels = { settings: 'الإعدادات والمالية' };
    const priority = node => paths(node).some(path => /\/admin\/(dashboard|whatsapp|desktop-pos|go-stores)\/?$/.test(path));
    function placeShortcut(route, afterRoute) { /* existing native implementation */ }
    placeShortcut('whatsapp', 'dashboard');
    placeShortcut('desktop-pos', 'go-stores');
    function groupNavigation() { /* custom grouping stays untouched */ }
})();
'''
NAVIGATION_TARGET = NAVIGATION_SOURCE.replace('dashboard|whatsapp|desktop-pos', 'dashboard|whatsapp|meta|desktop-pos').replace(
    "    placeShortcut('whatsapp', 'dashboard');\n",
    "    placeShortcut('whatsapp', 'dashboard');\n    placeShortcut('meta', 'whatsapp');\n")


class AssetReferenceTests(unittest.TestCase):
    def test_meta_inbox_versioned_assets_exist(self):
        root = pathlib.Path(__file__).resolve().parents[1]
        view = (root / 'resources/views/admin/meta/index.blade.php').read_text()
        assets = re.findall(r"filemtime\(public_path\(['\"]([^'\"]+)['\"]\)\)", view)
        self.assertTrue(assets, 'Meta inbox must declare its versioned assets')
        for asset in assets:
            with self.subTest(asset=asset):
                self.assertTrue((root / 'public' / asset).is_file(),
                                f'Meta inbox filemtime target is missing: {asset}')


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-install-test-')
        self.root = pathlib.Path(self.temporary.name) / 'project'
        self.root.mkdir()
        self.original = {
            'vendor/autoload.php': b'<?php // runtime\n',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': b'<?php // original shared workflow\n',
            'routes/api.php': b'<?php // installed custom API routes\n',
            'routes/web.php': b'<?php // installed custom dashboard routes\n',
            'resources/views/admin/whatsapp/sidebar.blade.php': b'installed WhatsApp link\n',
            'resources/views/admin/layouts/menu.blade.php': b"@include('admin.whatsapp.sidebar')\n",
            '.env': b'PRIVATE_SECRET=never-read-or-change\n',
            'bootstrap/cache/config.php': b'private cached configuration\n',
            'bootstrap/cache/routes-v7.php': b'original cached routes\n',
        }
        for rel, content in self.original.items():
            self.write(rel, content, 0o640)
        self.payloads = {
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': '<?php // extended shared workflow\n',
            'routes/meta_messaging.php': '<?php // new callback\n',
            'database/migrations/2026_10_11_160000_create_meta_webhook_events_table.php': '<?php // additive migration\n',
        }
        shared = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
        content = self.original[shared]
        baseline = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
        self.manifest = {'baseline': {shared: baseline}, 'new_files': [rel for rel in self.payloads if rel != shared]}
        self.calls = []
        self.fail = None
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 20})
        self.output = ''

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, rel, content, mode=0o644):
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def git(self, root, *args):
        rel = args[-1].split(':', 1)[1]
        if rel == 'deployment/meta_messaging_route_check.php':
            return '<?php // pinned route verifier\n'
        return json.dumps(self.manifest) if rel == 'deployment/meta_messaging_manifest.json' else self.payloads[rel]

    def runner(self, args, root, capture=False):
        self.calls.append(args)
        if 'route:list' in args:
            raise subprocess.CalledProcessError(1, args, output='Unrelated legacy controller does not exist')
        if self.fail and self.fail(args):
            raise subprocess.CalledProcessError(1, args)
        if 'config:clear' in args:
            (self.root / 'bootstrap/cache/config.php').unlink(missing_ok=True)
        if 'config:cache' in args:
            self.write('bootstrap/cache/config.php', b'new cached configuration\n')
        if 'route:clear' in args:
            (self.root / 'bootstrap/cache/routes-v7.php').unlink(missing_ok=True)
        if 'route:cache' in args:
            self.write('bootstrap/cache/routes-v7.php', b'new cached routes\n')
        return subprocess.CompletedProcess(args, 0, self.probe_output if args[-1].endswith('/verify-meta-routes.php') else '', '')

    def install(self):
        argv = ['install', '--root', str(self.root), '--release', RELEASE, '--php', 'php']
        output = io.StringIO()
        with mock.patch.object(sys, 'argv', argv), mock.patch.object(installer.os, 'geteuid', return_value=1001), \
                mock.patch.object(installer, 'git', side_effect=self.git), mock.patch.object(installer, 'run', side_effect=self.runner), \
                contextlib.redirect_stdout(output):
            try:
                installer.main()
            finally:
                self.output = output.getvalue()

    def installed_previous_release(self):
        self.payloads[PROCESS_REL] = '<?php // previously installed Meta processor\n'
        self.manifest['new_files'].append(PROCESS_REL)
        self.install()
        previous = (self.root / PROCESS_REL).read_bytes()
        blob = hashlib.sha1(b'blob ' + str(len(previous)).encode() + b'\0' + previous).hexdigest()
        self.payloads[PROCESS_REL] = '<?php // Meta processor with customer profiles\n'
        self.manifest['accepted_previous'] = {PROCESS_REL: [blob]}
        self.calls.clear()
        return previous

    def file_snapshot(self):
        return {str(path.relative_to(self.root)): (path.read_bytes(), path.stat().st_mode & 0o777)
                for path in self.root.rglob('*') if path.is_file()}

    def test_additive_install_preserves_routes_credentials_and_permissions(self):
        self.install()
        for rel in ['routes/api.php', 'routes/web.php', 'resources/views/admin/whatsapp/sidebar.blade.php']:
            self.assertTrue((self.root / rel).read_bytes().startswith(self.original[rel]))
            self.assertEqual((self.root / rel).stat().st_mode & 0o777, 0o640)
        self.assertEqual((self.root / '.env').read_bytes(), self.original['.env'])
        menu = self.root / 'resources/views/admin/layouts/menu.blade.php'
        self.assertEqual(menu.read_bytes(), b"@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar')\n")
        self.assertEqual(menu.stat().st_mode & 0o777, 0o640)
        migrations = [call for call in self.calls if 'migrate' in call]
        self.assertEqual(len(migrations), 1)
        self.assertIn('--path=database/migrations/2026_10_11_160000_create_meta_webhook_events_table.php', migrations[0])
        self.assertFalse(any('migrate:rollback' in call for call in self.calls))
        self.assertFalse(any('route:list' in call for call in self.calls))
        self.assertTrue(any('view:clear' in call for call in self.calls))
        self.assertIn('META_ROUTES_VERIFIED=20', self.output)
        backup = next((self.root.parent / 'meta-release-backups').iterdir())
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup / 'routes/api.php').stat().st_mode & 0o777, 0o600)
        self.assertEqual((backup / 'verify-meta-routes.php').stat().st_mode & 0o777, 0o600)
        self.assertFalse((self.root / NAVIGATION_REL).exists(), 'Ungrouped dashboards must not gain a new script')

    def test_repeat_keeps_bytes_and_inodes(self):
        self.install()
        rels = list(self.payloads) + ['routes/api.php', 'routes/web.php',
                                     'resources/views/admin/layouts/menu.blade.php',
                                     'resources/views/admin/whatsapp/sidebar.blade.php']
        before = {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino) for rel in rels}
        self.install()
        self.assertEqual(before, {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino) for rel in rels})

    def test_route_verification_uses_the_pinned_manifest_count(self):
        self.manifest['verified_routes'] = 25
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 25})
        self.install()
        self.assertIn('META_ROUTES_VERIFIED=25', self.output)

    def test_upgrade_replaces_only_explicitly_accepted_previous_feature_blob(self):
        previous = self.installed_previous_release()
        self.write(PROCESS_REL, previous, 0o640)
        before = self.file_snapshot()
        inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        backups = set((self.root.parent / 'meta-release-backups').iterdir())
        self.install()
        expected = dict(before)
        expected[PROCESS_REL] = (self.payloads[PROCESS_REL].encode(), 0o640)
        self.assertEqual(self.file_snapshot(), expected)
        for rel in before:
            if rel not in (PROCESS_REL, 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php'):
                self.assertEqual((self.root / rel).stat().st_ino, inodes[rel], rel)
        backup, = set((self.root.parent / 'meta-release-backups').iterdir()) - backups
        self.assertEqual((backup / PROCESS_REL).read_bytes(), previous)
        self.assertEqual((backup / PROCESS_REL).stat().st_mode & 0o777, 0o600)
        self.assertIn('META_MESSAGING_INSTALLED', self.output)
        current_inode = (self.root / PROCESS_REL).stat().st_ino
        self.install()
        self.assertEqual((self.root / PROCESS_REL).stat().st_ino, current_inode)

    def test_upgrade_unknown_previous_blob_stops_before_any_mutation(self):
        self.installed_previous_release()
        self.write(PROCESS_REL, b'<?php // unknown local processor changes\n', 0o640)
        profile_rel = 'app/Services/Dashboard/MetaCustomerProfile.php'
        self.payloads[profile_rel] = '<?php // new customer profile service\n'
        self.manifest['new_files'].append(profile_rel)
        before = self.file_snapshot()
        backups = set((self.root.parent / 'meta-release-backups').iterdir())
        with self.assertRaisesRegex(RuntimeError, 'EXISTING_META_FILE_DIFFERS: ' + PROCESS_REL):
            self.install()
        self.assertEqual(self.file_snapshot(), before)
        self.assertEqual(set((self.root.parent / 'meta-release-backups').iterdir()), backups)
        self.assertFalse((self.root / profile_rel).exists())
        self.assertEqual(self.calls, [])

    def test_failed_upgrade_restores_previous_feature_and_removes_new_service(self):
        previous = self.installed_previous_release()
        self.write(PROCESS_REL, previous, 0o640)
        before = self.file_snapshot()
        profile_rel = 'app/Services/Dashboard/MetaCustomerProfile.php'
        self.payloads[profile_rel] = '<?php // new customer profile service\n'
        self.manifest['new_files'].append(profile_rel)
        self.probe_output = json.dumps({'ok': False})
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
            self.install()
        self.assertEqual(self.file_snapshot(), before)
        self.assertFalse((self.root / profile_rel).exists())
        self.assertIn('CODE_RESTORED', self.output)
        self.assertFalse(any('migrate:rollback' in call or 'migrate:fresh' in call for call in self.calls))

    def test_previous_feature_allowlist_cannot_bypass_shared_file_baseline(self):
        shared = next(iter(self.manifest['baseline']))
        self.manifest['accepted_previous'] = {shared: ['a' * 40]}
        with self.assertRaisesRegex(RuntimeError, 'INVALID_ACCEPTED_PREVIOUS'):
            self.install()
        self.assertEqual(self.file_snapshot(),
                         {rel: (content, 0o640) for rel, content in self.original.items()})
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_malformed_previous_feature_allowlist_is_rejected_before_mutation(self):
        feature = self.manifest['new_files'][0]
        for accepted in ([], {feature: 'a' * 40}, {feature: []}, {feature: ['unknown']}, {feature: [None]}):
            with self.subTest(accepted=accepted):
                self.manifest['accepted_previous'] = accepted
                with self.assertRaisesRegex(RuntimeError, 'INVALID_ACCEPTED_PREVIOUS'):
                    self.install()
                self.assertEqual(self.file_snapshot(),
                                 {rel: (content, 0o640) for rel, content in self.original.items()})
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                self.assertEqual(self.calls, [])

    def test_sidebar_link_preserves_native_menu_markup_and_crlf(self):
        menu_rel = 'resources/views/admin/layouts/menu.blade.php'
        menu = (b'<ul class="native-dashboard-menu">\r\n\t<li>Home</li>\r\n'
                b"\t@include('admin.whatsapp.sidebar')\r\n"
                b'\t<li data-other-feature="kept">Desktop</li>\r\n</ul>\r\n')
        self.write(menu_rel, menu, 0o640)
        self.install()
        self.assertEqual((self.root / menu_rel).read_bytes(),
                         b'<ul class="native-dashboard-menu">\r\n\t<li>Home</li>\r\n'
                         b"\t@include('admin.whatsapp.sidebar')\r\n\t@include('admin.meta.sidebar')\r\n"
                         b'\t<li data-other-feature="kept">Desktop</li>\r\n</ul>\r\n')
        self.assertEqual((self.root / 'resources/views/admin/whatsapp/sidebar.blade.php').read_bytes(),
                         self.original['resources/views/admin/whatsapp/sidebar.blade.php'])

    def test_nested_and_misplaced_meta_links_move_without_duplicates(self):
        menu_rel = 'resources/views/admin/layouts/menu.blade.php'
        sidebar_rel = 'resources/views/admin/whatsapp/sidebar.blade.php'
        self.write(menu_rel, b'<ul>\n  @include("admin.meta.sidebar")\n  <li>Home</li>\n'
                   b"  @include('admin.whatsapp.sidebar')\n"
                   b"  @includeIf('admin.meta.sidebar')\n  <li>Desktop</li>\n"
                   b"  @include('admin.meta.sidebar')\n</ul>\n", 0o640)
        self.write(sidebar_rel, b'<li class="native-wa">WhatsApp</li>\n'
                   b"@include('admin.meta.sidebar')\n@includeIf('admin.meta.sidebar')\n", 0o640)
        self.install()
        self.assertEqual((self.root / menu_rel).read_bytes(),
                         b'<ul>\n  <li>Home</li>\n'
                         b"  @include('admin.whatsapp.sidebar')\n  @include('admin.meta.sidebar')\n"
                         b'  <li>Desktop</li>\n</ul>\n')
        self.assertEqual((self.root / sidebar_rel).read_bytes(), b'<li class="native-wa">WhatsApp</li>\n')
        self.assertEqual((self.root / menu_rel).stat().st_mode & 0o777, 0o640)
        self.assertEqual((self.root / sidebar_rel).stat().st_mode & 0o777, 0o640)
        before = {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino)
                  for rel in (menu_rel, sidebar_rel)}
        self.install()
        self.assertEqual(before, {rel: ((self.root / rel).read_bytes(), (self.root / rel).stat().st_ino)
                                 for rel in (menu_rel, sidebar_rel)})

    def test_inline_meta_hooks_preserve_surrounding_markup_and_stay_idempotent(self):
        menu = b"<ul>@include('admin.meta.sidebar')<li>Home</li>@include('admin.whatsapp.sidebar')</ul>"
        sidebar = b"<li>WhatsApp</li><aside>@include('admin.meta.sidebar')</aside>"
        proposed = installer.sidebar_proposals(menu, sidebar)
        self.assertEqual(proposed, (b"<ul><li>Home</li>@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar')</ul>\n",
                                    b'<li>WhatsApp</li><aside></aside>'))
        self.assertEqual(installer.sidebar_proposals(*proposed), proposed)

    def test_no_final_newline_menu_is_canonical_and_idempotent(self):
        rel = 'resources/views/admin/layouts/menu.blade.php'
        for menu in (b"@include('admin.whatsapp.sidebar')",
                     b"@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar')",
                     b"@includeIf('admin.whatsapp.sidebar')"):
            with self.subTest(menu=menu):
                self.write(rel, menu, 0o640)
                self.install()
                expected = (b"@includeIf('admin.whatsapp.sidebar')" if b'@includeIf' in menu
                            else b"@include('admin.whatsapp.sidebar')") + b"\n@include('admin.meta.sidebar')\n"
                self.assertEqual((self.root / rel).read_bytes(), expected)
                inode = (self.root / rel).stat().st_ino
                self.install()
                self.assertEqual((self.root / rel).read_bytes(), expected)
                self.assertEqual((self.root / rel).stat().st_ino, inode)

    def test_duplicate_whatsapp_anchor_is_rejected_before_publication(self):
        menu_rel = 'resources/views/admin/layouts/menu.blade.php'
        menu = b"@include('admin.whatsapp.sidebar')\n@include('admin.whatsapp.sidebar')\n"
        self.write(menu_rel, menu, 0o640)
        with self.assertRaisesRegex(RuntimeError, 'WHATSAPP_SIDEBAR_ANCHOR_AMBIGUOUS'):
            self.install()
        self.assertEqual((self.root / menu_rel).read_bytes(), menu)
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertEqual(self.calls, [])

    def test_missing_whatsapp_partial_and_unknown_meta_hook_are_rejected_before_publication(self):
        sidebar_rel = 'resources/views/admin/whatsapp/sidebar.blade.php'
        (self.root / sidebar_rel).unlink()
        with self.assertRaisesRegex(RuntimeError, 'REQUIRED_INSTALLATION_FILE'):
            self.install()
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.write(sidebar_rel, self.original[sidebar_rel], 0o640)
        menu = b"@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar', ['custom' => true])\n"
        self.write('resources/views/admin/layouts/menu.blade.php', menu, 0o640)
        with self.assertRaisesRegex(RuntimeError, 'META_SIDEBAR_HOOK_AMBIGUOUS'):
            self.install()
        self.assertEqual((self.root / 'resources/views/admin/layouts/menu.blade.php').read_bytes(), menu)
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())

    def test_native_navigation_is_patched_preserved_and_idempotent(self):
        self.write(NAVIGATION_REL, NAVIGATION_SOURCE.encode(), 0o640)
        self.install()
        script = self.root / NAVIGATION_REL
        self.assertEqual(script.read_bytes(), NAVIGATION_TARGET.encode())
        self.assertEqual(script.stat().st_mode & 0o777, 0o640)
        backup = next((self.root.parent / 'meta-release-backups').iterdir())
        self.assertEqual((backup / NAVIGATION_REL).read_bytes(), NAVIGATION_SOURCE.encode())
        self.assertEqual((backup / NAVIGATION_REL).stat().st_mode & 0o777, 0o600)
        before = script.read_bytes(), script.stat().st_ino
        self.install()
        self.assertEqual((script.read_bytes(), script.stat().st_ino), before)

    def test_native_script_only_repair_clears_view_cache_without_rewriting_menu(self):
        menu_rel = 'resources/views/admin/layouts/menu.blade.php'
        menu = b"@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar')\n"
        self.write(menu_rel, menu, 0o640)
        self.write(NAVIGATION_REL, NAVIGATION_SOURCE.encode(), 0o640)
        inode = (self.root / menu_rel).stat().st_ino
        self.install()
        self.assertEqual((self.root / menu_rel).read_bytes(), menu)
        self.assertEqual((self.root / menu_rel).stat().st_ino, inode)
        self.assertEqual((self.root / NAVIGATION_REL).read_bytes(), NAVIGATION_TARGET.encode())
        self.assertTrue(any('view:clear' in call for call in self.calls))

    def test_unknown_native_navigation_stops_before_feature_or_sidebar_writes(self):
        for source, error in (
                ('unrecognized installed grouping script', 'NATIVE_PRIORITY_ANCHOR_REQUIRED'),
                (NAVIGATION_SOURCE.replace("    placeShortcut('whatsapp', 'dashboard');\n", ''),
                 'NATIVE_SHORTCUT_ANCHOR_REQUIRED'),
                (NAVIGATION_SOURCE.replace('dashboard|whatsapp|desktop-pos', 'dashboard|whatsapp|whatsapp|desktop-pos'),
                 'NATIVE_PRIORITY_ROUTES_AMBIGUOUS'),
                (NAVIGATION_SOURCE + "placeShortcut('meta', 'dashboard');\n", 'NATIVE_META_SHORTCUT_AMBIGUOUS')):
            with self.subTest(error=error):
                self.write(NAVIGATION_REL, source.encode(), 0o640)
                with self.assertRaisesRegex(ValueError, error):
                    self.install()
                self.assertEqual((self.root / NAVIGATION_REL).read_bytes(), source.encode())
                self.assertEqual((self.root / 'resources/views/admin/layouts/menu.blade.php').read_bytes(),
                                 self.original['resources/views/admin/layouts/menu.blade.php'])
                self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
                self.assertFalse((self.root / self.manifest['new_files'][0]).exists())
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                self.assertEqual(self.calls, [])

    def test_native_navigation_is_restored_when_later_verification_fails(self):
        self.write(NAVIGATION_REL, NAVIGATION_SOURCE.encode(), 0o640)
        self.probe_output = json.dumps({'ok': False})
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
            self.install()
        self.assertEqual((self.root / NAVIGATION_REL).read_bytes(), NAVIGATION_SOURCE.encode())
        self.assertEqual((self.root / NAVIGATION_REL).stat().st_mode & 0o777, 0o640)
        self.assertEqual((self.root / 'resources/views/admin/layouts/menu.blade.php').read_bytes(),
                         self.original['resources/views/admin/layouts/menu.blade.php'])

    def test_native_navigation_symlink_is_rejected_before_publication(self):
        script = self.root / NAVIGATION_REL
        script.parent.mkdir(parents=True, exist_ok=True)
        original = self.root / 'native-navigation-original.js'
        original.write_bytes(NAVIGATION_SOURCE.encode())
        script.symlink_to(original)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'):
            self.install()
        self.assertEqual(original.read_bytes(), NAVIGATION_SOURCE.encode())
        self.assertFalse((self.root.parent / 'meta-release-backups').exists())
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_failed_route_cache_restores_code_and_private_caches(self):
        self.fail = lambda args: 'route:cache' in args
        with self.assertRaises(subprocess.CalledProcessError):
            self.install()
        for rel, content in self.original.items():
            self.assertEqual((self.root / rel).read_bytes(), content)
            self.assertEqual((self.root / rel).stat().st_mode & 0o777, 0o640)
        for rel in self.manifest['new_files']:
            self.assertFalse((self.root / rel).exists())

    def test_changed_shared_file_is_rejected_before_writes(self):
        shared = next(iter(self.manifest['baseline']))
        self.write(shared, b'external edits')
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_INSTALLED_FILE'):
            self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_existing_meta_file_and_missing_sidebar_are_rejected(self):
        self.write(self.manifest['new_files'][0], b'external Meta implementation')
        with self.assertRaisesRegex(RuntimeError, 'EXISTING_META_FILE_DIFFERS'):
            self.install()
        (self.root / self.manifest['new_files'][0]).unlink()
        self.write('resources/views/admin/layouts/menu.blade.php', b'custom menu without WhatsApp')
        with self.assertRaisesRegex(RuntimeError, 'INSTALLED_WHATSAPP_SIDEBAR_REQUIRED'):
            self.install()
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_symlink_backup_is_rejected_before_writes(self):
        (self.root.parent / 'meta-release-backups').symlink_to(self.root)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'):
            self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])

    def test_real_route_failure_restores_code_and_keeps_diagnostics_private(self):
        details = json.dumps({'ok': False, 'phase': 'routes', 'reason': 'ADMIN_MIDDLEWARE', 'route': 'meta-inbox.conversations',
                              'error_type': 'MetaMessagingRouteCheckFailure', 'access_token': 'fixture-sensitive-token'})
        old_runner = self.runner
        def failed_probe(args, root, capture=False):
            if args[-1].endswith('/verify-meta-routes.php'):
                raise subprocess.CalledProcessError(1, args, output=details, stderr='fixture-sensitive-stderr')
            return old_runner(args, root, capture)
        with mock.patch.object(self, 'runner', side_effect=failed_probe):
            with self.assertRaises(subprocess.CalledProcessError):
                self.install()
        self.assertIn('ADMIN_MIDDLEWARE', self.output)
        self.assertIn('meta-inbox.conversations', self.output)
        self.assertNotIn('fixture-sensitive', self.output)
        for rel, content in self.original.items():
            self.assertEqual((self.root / rel).read_bytes(), content)
        backup = next((self.root.parent / 'meta-release-backups').iterdir())
        diagnostic = backup / 'route-check-failure.log'
        self.assertEqual(diagnostic.stat().st_mode & 0o777, 0o600)
        self.assertIn('fixture-sensitive-stderr', diagnostic.read_text())
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_incomplete_route_verification_does_not_install(self):
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 2})
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
            self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())

    def test_retry_after_verification_rollback_succeeds_without_restoring_database(self):
        self.probe_output = json.dumps({'ok': False})
        with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
            self.install()
        self.probe_output = json.dumps({'ok': True, 'verified_routes': 20})
        self.install()
        self.assertIn('META_MESSAGING_INSTALLED', self.output)
        self.assertFalse(any('migrate:rollback' in call or 'migrate:fresh' in call for call in self.calls))

    def test_diagnostic_write_failure_does_not_prevent_rollback(self):
        self.probe_output = json.dumps({'ok': False})
        original_open = installer.os.open
        def failing_log_open(path, *args, **kwargs):
            if str(path).endswith('/route-check-failure.log'):
                raise OSError('fixture log storage unavailable')
            return original_open(path, *args, **kwargs)
        with mock.patch.object(installer.os, 'open', side_effect=failing_log_open):
            with self.assertRaisesRegex(RuntimeError, 'ROUTE_VERIFICATION_FAILED'):
                self.install()
        self.assertEqual((self.root / 'routes/api.php').read_bytes(), self.original['routes/api.php'])
        self.assertFalse((self.root / self.manifest['new_files'][0]).exists())
        self.assertIn('PRIVATE_DIAGNOSTIC_UNAVAILABLE', self.output)


if __name__ == '__main__':
    unittest.main()
