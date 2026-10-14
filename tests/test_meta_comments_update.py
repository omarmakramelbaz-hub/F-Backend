#!/usr/bin/env python3
"""Isolated comments overlay deployment; no live PHP, Graph, or database writes."""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import tempfile
import types
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('meta_comments_update',
    pathlib.Path(__file__).resolve().parents[1] / 'deployment/update_meta_comments.py')
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)
RELEASE = 'a' * 40
PHP = 'php-comments-fixture'
BASELINE_SIDEBAR = b'''@if(\\Illuminate\\Support\\Facades\\Route::has('meta-inbox.index') && app(\\App\\Services\\Dashboard\\MetaInboxAccess::class)->canAccess(auth('admin')->user()))
<li class="nav-item"><a href="{{ route('meta-inbox.index') }}" class="nav-link {{ request()->is('admin/meta*', 'admin/whatsapp*') ? 'active' : '' }}"><i class="nav-icon fab fa-facebook-messenger" aria-hidden="true"></i><p>{{ app()->getLocale() === 'ar' ? '\xd8\xb1\xd8\xb3\xd8\xa7\xd8\xa6\xd9\x84 \xd9\x85\xd9\x8a\xd8\xaa\xd8\xa7' : 'Meta inbox' }}</p></a></li>
@endif
'''
LIVE_ROUTES = """<?php
// Deployed order-editor and custom routes must survive.
require __DIR__.'/whatsapp_inbox.php';
foreach (['meta_inbox', 'meta_orders', 'meta_reads', 'meta_replies'] as $metaRoutes) {
    require __DIR__.'/'.$metaRoutes.'.php';
}
Route::get('/custom-live', fn () => 'ok');
"""
NATIVE = r"""// Deployed native sidebar; keep all customizations.
const priority = node => paths(node).some(path => /\/admin\/(dashboard|whatsapp|meta|desktop-pos|phone-orders|custom-route)\/?$/.test(path));
    placeShortcut('meta', 'dashboard');
    placeShortcut('desktop-pos', 'go-stores');
// Native sidebar code ends here.
"""


class TransformTests(unittest.TestCase):
    def test_routes_append_preserves_live_order_editor_and_all_other_registrations(self):
        target = updater.web_contents(LIVE_ROUTES)
        self.assertEqual(target, LIVE_ROUTES + "\nrequire __DIR__.'/meta_comments.php';\n")
        self.assertEqual(updater.web_contents(target), target)
        self.assertEqual(target.count('meta_comments'), 1)

    def test_routes_existing_reviewed_loop_is_idempotent(self):
        source = LIVE_ROUTES.replace("'meta_replies'", "'meta_replies', 'meta_comments'")
        self.assertEqual(updater.web_contents(source), source)

    def test_route_ambiguity_or_unknown_registration_fails_closed(self):
        cases = [LIVE_ROUTES + "// meta_comments: unknown previous customization\n",
                 LIVE_ROUTES + "require __DIR__.'/meta_comments.php';\n" * 2,
                 LIVE_ROUTES.replace('meta_inbox', 'other'), LIVE_ROUTES + '?>']
        for source in cases:
            with self.subTest(source=source), self.assertRaises(RuntimeError):
                updater.web_contents(source)

    def test_route_crlf_and_missing_terminal_newline_are_preserved(self):
        source = LIVE_ROUTES.rstrip('\n').replace('\n', '\r\n')
        target = updater.web_contents(source)
        self.assertTrue(target.startswith(source))
        self.assertNotIn('\n', target.replace('\r\n', ''))
        self.assertEqual(updater.web_contents(target), target)

    def test_navigation_preserves_native_order_calls_and_custom_paths(self):
        target = updater.navigation_contents(NATIVE)
        expected_base = NATIVE.replace('whatsapp|meta|desktop-pos', r'whatsapp|meta|meta\/comments|desktop-pos')
        self.assertEqual(target, expected_base + '\n' + updater.NAV_BLOCK)
        self.assertEqual(updater.navigation_contents(target), target)
        self.assertIn("placeShortcut('meta', 'dashboard');", target)
        self.assertEqual(target.count(updater.NAV_MARKER), 1)
        # The slash route uses explicit DOM ordering rather than an unknown native shortcut implementation.
        self.assertNotIn("placeShortcut('meta/comments'", target)

    def test_navigation_crlf_remains_idempotent(self):
        source = NATIVE.replace('\n', '\r\n')
        target = updater.navigation_contents(source)
        self.assertNotIn('\n', target.replace('\r\n', ''))
        self.assertEqual(updater.navigation_contents(target), target)

    def test_exact_deployed_v1_block_upgrades_to_v2_without_changing_native_customizations(self):
        for newline in ('\n', '\r\n'):
            with self.subTest(newline=newline):
                native = NATIVE.replace('whatsapp|meta|desktop-pos', r'whatsapp|meta|meta\/comments|desktop-pos')
                source = (native + '\n' + updater.PRIOR_NAV_BLOCK).replace('\n', newline)
                target = updater.navigation_contents(source)
                self.assertEqual(target, (native + '\n' + updater.NAV_BLOCK).replace('\n', newline))
                self.assertEqual(updater.navigation_contents(target), target)
                self.assertNotIn(updater.PRIOR_NAV_MARKER, target)
        altered = source.replace("document.querySelectorAll('a[href]')", "document.querySelectorAll('foreign[href]')")
        with self.assertRaisesRegex(RuntimeError, 'NATIVE_COMMENTS_ORDERING_DIFFERS'):
            updater.navigation_contents(altered)

    def test_navigation_runtime_handles_late_native_menu_grouping_and_exclusive_active_state(self):
        result = subprocess.run(['node', str(pathlib.Path(__file__).with_name('meta_comments_navigation_test.js'))],
                                input=updater.NAV_BLOCK, text=True, capture_output=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_unknown_or_ambiguous_navigation_is_rejected(self):
        for source in [NATIVE + NATIVE, NATIVE.replace('const priority', 'const preferred'),
                       NATIVE.replace('|meta|', '|meta|meta|'), NATIVE.replace('|meta|', '|'),
                       NATIVE + updater.NAV_MARKER, updater.navigation_contents(NATIVE).replace(
                           "at('/admin/meta/comments')", "at('/admin/foreign')")]:
            with self.subTest(source=source), self.assertRaises(RuntimeError):
                updater.navigation_contents(source)


class InstallTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='meta-comments-update-test-')
        self.root = pathlib.Path(self.temporary.name) / 'public_html'; self.root.mkdir()
        self.original = {
            'vendor/autoload.php': b'<?php // fixture runtime\n',
            'bootstrap/app.php': b'<?php // fixture bootstrap\n',
            'artisan': b'<?php // fixture artisan\n',
            '.env': b'PRIVATE_TOKEN=never-read-or-alter\n',
            'bootstrap/cache/config.php': b'<?php // unrelated private config cache\n',
            'routes/api.php': b'<?php // deployed Meta/WhatsApp webhook routes\n',
            'public/js/dashboard-meta-message-hub.js': b'// active message hub and order sound fix\n',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': b'<?php // bot ambiguity handling\n',
            updater.WEB_ROUTES: LIVE_ROUTES.encode(),
            updater.NAVIGATION: NATIVE.encode(),
            updater.SIDEBAR: BASELINE_SIDEBAR,
            'resources/views/admin/layouts/menu.blade.php': b"<ul>\n@include('admin.meta.sidebar')\n</ul>\n",
            'resources/views/admin/whatsapp/sidebar.blade.php': b'<li>Live WhatsApp partial</li>\n',
        }
        self.original.update({relative: ('<?php // trusted foundation ' + relative + '\n').encode()
                              for relative in updater.FOUNDATION_PINS})
        for relative, content in self.original.items():
            self.write(relative, content)
        for relative in updater.SOURCE_FILES:
            (self.root / relative).parent.mkdir(parents=True, exist_ok=True)
        self.payloads = {relative: ('<?php // reviewed comment overlay ' + relative + '\n').encode()
                         for relative in updater.SOURCE_FILES}
        self.manifest = json.dumps({'version': 1, 'files': {
            relative: updater.blob(content) for relative, content in self.payloads.items()}}, indent=2).encode()
        self.foundation_patch = mock.patch.object(updater, 'FOUNDATION_PINS', {
            relative: updater.blob(self.original[relative]) for relative in updater.FOUNDATION_PINS})
        self.foundation_patch.start()
        self.calls = []; self.git_reads = []; self.output = ''
        self.fail_lint = False; self.fail_preflight = False; self.fail_migration = False; self.fail_verify = False
        self.preflight_action = None; self.verify_action = None; self.publications = []
        self.before_lints = []
        self.native_atomic = updater.atomic

    def tearDown(self):
        self.foundation_patch.stop(); self.temporary.cleanup()

    def write(self, relative, content, mode=0o644):
        path = self.root / relative; path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content); path.chmod(mode)

    def files(self):
        return {path.relative_to(self.root).as_posix(): (path.read_bytes(), stat_mode(path))
                for path in self.root.rglob('*') if path.is_file() and not path.is_symlink()}

    def git_file(self, root, release, relative):
        self.assertEqual(root, self.root); self.assertEqual(release, RELEASE)
        self.git_reads.append(relative)
        if relative == updater.MANIFEST_PATH:
            return self.manifest
        self.assertIn(relative, self.payloads)
        return self.payloads[relative]

    def runner(self, arguments, root, timeout=60):
        self.assertEqual(root, self.root)
        self.calls.append(arguments)
        self.assertEqual(arguments[:5], ['runuser', '-u', 'fasakha', '--', PHP])
        if arguments[5] == '-l':
            staged = pathlib.Path(arguments[6])
            self.assertTrue(staged.is_file())
            self.assertEqual(stat_mode(staged), 0o644)
            for parent in staged.parents:
                if parent.name.startswith('meta-comments-stage-'):
                    self.assertEqual(stat_mode(parent), 0o755)
                    break
                self.assertEqual(stat_mode(parent), 0o755)
            self.before_lints.append(self.files())
            return subprocess.CompletedProcess(arguments, int(self.fail_lint), b'', b'PRIVATE_LINT_SECRET=do-not-print')
        if arguments[5] == 'artisan':
            self.assertEqual(arguments[6:], ['migrate', '--path=' + updater.MIGRATION, '--force', '--no-interaction'])
            self.assertTrue((self.root / updater.MIGRATION).exists())
            self.assertEqual((self.root / updater.WEB_ROUTES).read_bytes(), self.routes_before_update)
            return subprocess.CompletedProcess(arguments, int(self.fail_migration), b'PRIVATE_MIGRATION_SECRET=do-not-print', b'')
        self.assertTrue(arguments[5].endswith('/comments-runtime.php'))
        phase = arguments[7]
        if phase == 'preflight':
            if self.preflight_action:
                self.preflight_action()
            code = int(self.fail_preflight); verified = 0
        else:
            self.assertEqual(phase, 'verify')
            if self.verify_action:
                self.verify_action()
            code = int(self.fail_verify); verified = 6
        data = {'ok': True, 'configured_accounts': 2, 'verified_routes': verified, 'views_compiled': 2}
        return subprocess.CompletedProcess(arguments, code, json.dumps(data).encode(), b'PRIVATE_TOKEN=do-not-print')

    def atomic(self, path, data, mode, uid, gid):
        if self.root in path.parents:
            self.publications.append(path.relative_to(self.root).as_posix())
        return self.native_atomic(path, data, mode, uid, gid)

    def update(self, manifest=None):
        output = io.StringIO()
        self.routes_before_update = (self.root / updater.WEB_ROUTES).read_bytes()
        user = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
        with mock.patch.object(updater, 'APP_ROOT', self.root), mock.patch.object(updater, 'PHP', PHP), \
             mock.patch.object(updater.pwd, 'getpwnam', return_value=user), \
             mock.patch.object(updater, 'git_file', side_effect=self.git_file), \
             mock.patch.object(updater, 'run', side_effect=self.runner), \
             mock.patch.object(updater, 'atomic', side_effect=self.atomic), \
             mock.patch.dict(os.environ, {}, clear=True), contextlib.redirect_stdout(output):
            try:
                return updater.install(self.root, RELEASE, PHP, self.manifest if manifest is None else manifest)
            finally:
                self.output = output.getvalue()

    def test_success_is_narrow_pinned_and_keeps_live_inbox_orders_webhooks_and_config(self):
        before = self.files(); inodes = {relative: (self.root / relative).stat().st_ino for relative in before}
        report = self.update()
        expected = dict(before)
        expected.update({relative: (data, 0o644) for relative, data in self.payloads.items()})
        expected[updater.WEB_ROUTES] = (updater.web_contents(LIVE_ROUTES).encode(), 0o644)
        expected[updater.NAVIGATION] = (updater.navigation_contents(NATIVE).encode(), 0o644)
        self.assertEqual(self.files(), expected)
        self.assertTrue(all(snapshot == before for snapshot in self.before_lints))
        for relative in before:
            if relative not in self.publications:
                self.assertEqual((self.root / relative).stat().st_ino, inodes[relative], relative)
        self.assertEqual(report['status'], 'META_COMMENTS_READY')
        self.assertEqual(report['graph_requests'], 0); self.assertEqual(report['verified_routes'], 6)
        self.assertEqual(report['views_compiled'], 2)
        self.assertEqual(self.git_reads, [updater.MANIFEST_PATH] + list(updater.SOURCE_FILES))
        backup = pathlib.Path(report['backup']); self.assertEqual(stat_mode(backup), 0o700)
        for relative in (updater.SIDEBAR, updater.WEB_ROUTES, updater.NAVIGATION):
            saved = backup / 'original' / relative
            self.assertEqual(saved.read_bytes(), self.original[relative]); self.assertEqual(stat_mode(saved), 0o600)
        self.assertNotIn('PRIVATE_', self.output)
        self.assertEqual(self.publications[-4:], ['routes/meta_comments.php', updater.SIDEBAR, updater.WEB_ROUTES, updater.NAVIGATION])

    def test_repeat_is_idempotent_and_preserves_all_existing_file_inodes(self):
        self.update(); before = self.files()
        inodes = {relative: (self.root / relative).stat().st_ino for relative in before}
        self.publications = []
        report = self.update()
        self.assertEqual(report['updated_files'], 0); self.assertEqual(self.files(), before)
        self.assertEqual({relative: (self.root / relative).stat().st_ino for relative in before}, inodes)
        self.assertEqual(self.publications, [])

    def test_reviewed_diagnostic_upgrade_changes_only_diagnostic_and_rejects_unknown_bytes(self):
        self.update()
        relative = 'deployment/meta_comments_diagnostic.php'
        previous = b'<?php // reviewed previous comments diagnostic\n'
        self.write(relative, previous)
        before = self.files()
        inodes = {path: (self.root / path).stat().st_ino for path in before}
        self.publications = []
        with mock.patch.dict(updater.REVIEWED_PRIOR_FILES, {relative: (updater.blob(previous),)}):
            report = self.update()
        self.assertEqual(report['updated_files'], 1)
        self.assertEqual(self.publications, [relative])
        expected = dict(before); expected[relative] = (self.payloads[relative], 0o644)
        self.assertEqual(self.files(), expected)
        for path in before:
            if path != relative:
                self.assertEqual((self.root / path).stat().st_ino, inodes[path])
        self.assertEqual((pathlib.Path(report['backup']) / 'original' / relative).read_bytes(), previous)
        self.write(relative, previous + b'// unknown operator change\n')
        before = self.files(); self.calls = []; self.publications = []
        with mock.patch.dict(updater.REVIEWED_PRIOR_FILES, {relative: (updater.blob(previous),)}):
            with self.assertRaisesRegex(RuntimeError, 'UNREVIEWED_COMMENTS_TARGET'):
                self.update()
        self.assertEqual(self.files(), before)
        self.assertEqual(self.calls, []); self.assertEqual(self.publications, [])

    def test_reviewed_installed_feature_upgrade_preserves_every_other_file_and_restores_on_failure(self):
        self.update()
        changed = ('app/Services/Dashboard/MetaCommentsService.php',
                   'app/Http/Controllers/Dashboard/MetaCommentsController.php',
                   'resources/views/admin/meta/comments.blade.php',
                   'public/js/dashboard-meta-comments.js', 'public/css/dashboard-meta-comments.css', updater.SIDEBAR)
        reviewed = {}
        for relative in changed:
            previous = ('<?php // reviewed prior release ' + relative + '\n').encode()
            self.write(relative, previous); reviewed[relative] = (updater.blob(previous),)
        before = self.files(); self.publications = []
        self.fail_verify = True
        with mock.patch.dict(updater.REVIEWED_PRIOR_FILES, reviewed):
            with self.assertRaisesRegex(RuntimeError, 'SANITIZED_RUNTIME_PROBE_FAILED'):
                self.update()
        self.assertEqual(self.files(), before); self.assertIn('RESTORED', self.output)
        self.fail_verify = False; self.publications = []
        inodes = {relative: (self.root / relative).stat().st_ino for relative in before}
        with mock.patch.dict(updater.REVIEWED_PRIOR_FILES, reviewed):
            report = self.update()
        self.assertEqual(report['updated_files'], len(changed))
        self.assertEqual(set(self.publications), set(changed))
        expected = dict(before)
        expected.update({relative: (self.payloads[relative], 0o644) for relative in changed})
        self.assertEqual(self.files(), expected)
        for relative in before:
            if relative not in changed:
                self.assertEqual((self.root / relative).stat().st_ino, inodes[relative])

    def test_lint_and_preflight_failure_leave_application_bytes_untouched(self):
        before = self.files()
        self.fail_lint = True
        with self.assertRaisesRegex(RuntimeError, 'STAGED_PHP_LINT_FAILED'):
            self.update()
        self.assertEqual(self.files(), before)
        self.fail_lint = False; self.fail_preflight = True
        with self.assertRaisesRegex(RuntimeError, 'SANITIZED_RUNTIME_PROBE_FAILED'):
            self.update()
        self.assertEqual(self.files(), before)
        self.assertNotIn('PRIVATE_', self.output)

    def test_migration_and_verify_failure_restore_sources_without_drop_or_rollback_command(self):
        before = self.files()
        for failure in ('fail_migration', 'fail_verify'):
            with self.subTest(failure=failure):
                setattr(self, failure, True)
                self.publications = []
                with self.assertRaises(RuntimeError):
                    self.update()
                self.assertEqual(self.files(), before)
                self.assertIn('RESTORED', self.output)
                self.assertIn('table_left_on_rollback', self.output)
                self.assertFalse(any(any('rollback' in part or 'drop' in part for part in call) for call in self.calls))
                self.assertNotIn('PRIVATE_', self.output)
                setattr(self, failure, False)

    def test_concurrent_operator_change_after_publication_is_preserved_and_reported(self):
        relative = 'public/js/dashboard-meta-comments.js'
        operator_data = b'// operator independently replaced comment JS\n'
        self.verify_action = lambda: self.write(relative, operator_data)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'):
            self.update()
        self.assertEqual((self.root / relative).read_bytes(), operator_data)
        self.assertIn('NEEDS_REVIEW', self.output)
        for path, data in self.original.items():
            self.assertEqual((self.root / path).read_bytes(), data)

    def test_concurrent_live_order_source_before_publication_is_preserved(self):
        operator_data = self.original[updater.WEB_ROUTES] + b'// latest deployed order route\n'
        self.preflight_action = lambda: self.write(updater.WEB_ROUTES, operator_data)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'):
            self.update()
        self.assertEqual((self.root / updater.WEB_ROUTES).read_bytes(), operator_data)
        self.assertEqual(self.publications, [])

    def test_unknown_installed_feature_or_sidebar_files_refuse_before_php_or_backup_changes(self):
        for relative in ('app/Services/Dashboard/MetaCommentsService.php', updater.SIDEBAR):
            with self.subTest(relative=relative):
                prior = self.original.get(relative)
                self.write(relative, b'<?php // unknown live operator change\n')
                before = self.files()
                with self.assertRaisesRegex(RuntimeError, 'UNREVIEWED_COMMENTS_TARGET'):
                    self.update()
                self.assertEqual(self.files(), before); self.assertEqual(self.calls, [])
                self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                (self.root / relative).unlink()
                if prior is not None:
                    self.write(relative, prior)

    def test_manifest_and_source_pin_mismatch_refuse_before_application_writes(self):
        before = self.files()
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_MANIFEST_DIFFERS'):
            self.update(self.manifest + b' ')
        relative = updater.SOURCE_FILES[0]
        self.payloads[relative] += b'// changed after manifest\n'
        with self.assertRaisesRegex(RuntimeError, 'RELEASE_SOURCE_PIN_DIFFERS'):
            self.update()
        self.assertEqual(self.files(), before); self.assertEqual(self.calls, [])

    def test_hardlinked_foundation_is_read_only_and_preserves_other_link_inode(self):
        relative = 'app/Services/Dashboard/WhatsAppInboxAccess.php'
        link = self.root / 'app/Services/Dashboard/ReadOnlyAccessAlias.php'
        os.link(self.root / relative, link)
        metadata = link.stat(); original = link.read_bytes()
        self.update()
        self.assertEqual(link.read_bytes(), original)
        self.assertEqual(link.stat().st_ino, metadata.st_ino)
        self.assertEqual((self.root / relative).stat().st_nlink, 2)

    def test_hardlinked_write_target_and_symlink_target_refuse_without_changes(self):
        for hardlink in (True, False):
            with self.subTest(hardlink=hardlink):
                relative = updater.WEB_ROUTES
                alias = self.root / 'routes/live-route-alias.php'
                if hardlink:
                    os.link(self.root / relative, alias)
                    expected = 'HARDLINK_TARGET'
                else:
                    original = (self.root / relative).read_bytes()
                    (self.root / relative).unlink()
                    (self.root / relative).symlink_to(self.root / 'routes/api.php')
                    expected = 'SYMLINK_TARGET'
                with self.assertRaisesRegex(RuntimeError, expected):
                    self.update()
                self.assertEqual(self.calls, [])
                if hardlink:
                    alias.unlink()
                else:
                    (self.root / relative).unlink(); self.write(relative, original)

    def test_unsafe_mode_and_route_cache_refuse_before_changes(self):
        path = self.root / updater.SIDEBAR; path.chmod(0o666)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE_MODE'):
            self.update()
        path.chmod(0o644)
        relative = updater.ROUTE_CACHE_FILES[0]; self.write(relative, b'<?php // cached routes\n')
        before = self.files()
        with self.assertRaisesRegex(RuntimeError, 'CACHED_ROUTES_BLOCKED'):
            self.update()
        self.assertEqual(self.files(), before); self.assertEqual(self.calls, [])

    def test_staging_is_traversable_by_application_user_with_umask_077(self):
        before = os.umask(0o077)
        try:
            report = self.update()
        finally:
            os.umask(before)
        self.assertEqual(report['status'], 'META_COMMENTS_READY')


def stat_mode(path):
    return path.stat().st_mode & 0o777


if __name__ == '__main__':
    unittest.main()
