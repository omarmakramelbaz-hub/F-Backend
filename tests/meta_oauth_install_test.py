#!/usr/bin/env python3
"""Isolated installer fixtures: no real PHP, Graph, server, or business data writes."""
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('meta_oauth_installer', pathlib.Path(__file__).resolve().parents[1] / 'deployment/update_meta_oauth.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)
RELEASE = 'a' * 40
ORIGIN = 'https://dashboard.example.test'
SETTINGS = {'app_id': installer.APP_ID, 'config_id': installer.CONFIG_ID,
            'redirect_uri': ORIGIN + installer.CALLBACK_PATH, 'api_version': installer.API_VERSION}


class MetaOAuthInstallTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='meta-oauth-fixture-')
        self.root = pathlib.Path(self.tmp.name) / 'public_html'; self.root.mkdir()
        self.original = {
            'vendor/autoload.php': b'<?php // native runtime\n',
            'bootstrap/app.php': b'<?php // native bootstrap\n',
            'artisan': b'<?php // native artisan\n',
            '.env': b'PRIVATE=never-change-this\n',
            'bootstrap/cache/config.php': b'native secret cache\n',
            'routes/api.php': b'<?php // original webhook routes\n',
            'public/js/dashboard-meta-inbox.js': b'// deployed rich inbox\n',
            'app/Services/Dashboard/WhatsAppOrderWorkflow.php': b'<?php // other active work\n',
        }
        self.original.update({rel: ('<?php // live foundation ' + rel + '\n').encode()
                              for rel in installer.FOUNDATION_BLOBS})
        self.original.update({rel: ('<?php // live baseline ' + rel + '\n').encode()
                              for rel in installer.BASELINE_BLOBS})
        for rel, content in self.original.items(): self.write(rel, content)
        for rel in installer.NEW_FILES: (self.root / rel).parent.mkdir(parents=True, exist_ok=True)
        (self.root / 'storage/app').mkdir(parents=True)
        self.payloads = {rel: ('<?php // pinned OAuth overlay ' + rel + '\n').encode()
                         for rel in tuple(installer.BASELINE_BLOBS) + installer.NEW_FILES}
        self.base_patch = mock.patch.object(installer, 'BASELINE_BLOBS',
            {rel: installer.blob(self.original[rel]) for rel in installer.BASELINE_BLOBS})
        self.foundation_patch = mock.patch.object(installer, 'FOUNDATION_BLOBS',
            {rel: installer.blob(self.original[rel]) for rel in installer.FOUNDATION_BLOBS})
        self.base_patch.start(); self.foundation_patch.start()
        self.calls = []; self.output = ''; self.lint_snapshots = []
        self.fail_lint = False; self.fail_preflight = False; self.fail_verify = False
        self.preflight_action = None; self.verify_action = None
        self.probe = {'ok': True, 'origin': ORIGIN, 'app_secret_present': True,
                      'route_cache_override': False, 'routes_cached': False}
        self.verification = {'ok': True, 'verified_routes': 4, 'views_compiled': 2}

    def tearDown(self):
        self.base_patch.stop(); self.foundation_patch.stop(); self.tmp.cleanup()

    def write(self, rel, content, mode=0o644):
        path = self.root / rel; path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content); path.chmod(mode)

    def files(self):
        return {str(path.relative_to(self.root)): (path.read_bytes(), path.stat().st_mode & 0o777)
                for path in self.root.rglob('*') if path.is_file() and not path.is_symlink()}

    def git_file(self, root, release, rel):
        self.assertEqual(root, self.root); self.assertEqual(release, RELEASE)
        self.assertIn(rel, self.payloads)
        return self.payloads[rel]

    def runner(self, args, root):
        self.calls.append(args); self.assertEqual(root, self.root); self.assertEqual(args[0], 'php')
        if args[1] == '-l':
            staged = pathlib.Path(args[2])
            self.assertTrue(staged.is_relative_to(self.root.parent / 'meta-release-backups'))
            self.assertEqual(staged.stat().st_mode & 0o777, 0o600)
            self.lint_snapshots.append(self.files())
            return subprocess.CompletedProcess(args, int(self.fail_lint), b'', b'PRIVATE_TOKEN=do-not-print')
        if args[1].endswith('/oauth-runtime-preflight.php'):
            if self.preflight_action: self.preflight_action()
            return subprocess.CompletedProcess(args, int(self.fail_preflight), json.dumps(self.probe).encode(), b'PRIVATE_SECRET=do-not-print')
        self.assertTrue(args[1].endswith('/oauth-runtime-verify.php'))
        self.assertEqual(json.loads(args[3]), SETTINGS)
        self.assertEqual(json.loads((self.root / installer.SETTINGS_FILE).read_bytes()), SETTINGS)
        self.assertEqual((self.root / installer.SETTINGS_FILE).stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.root / installer.PRIVATE_DIR).stat().st_mode & 0o777, 0o700)
        if self.verify_action: self.verify_action()
        return subprocess.CompletedProcess(args, int(self.fail_verify), json.dumps(self.verification).encode(), b'PRIVATE_ACCESS_TOKEN=do-not-print')

    def update(self):
        output = io.StringIO()
        with mock.patch.object(installer, 'application_uid', return_value=os.geteuid()), \
             mock.patch.object(installer, 'git_file', side_effect=self.git_file), \
             mock.patch.object(installer, 'run', side_effect=self.runner), \
             mock.patch.dict(os.environ, {}, clear=True), contextlib.redirect_stdout(output):
            try: return installer.install(self.root, RELEASE, 'php')
            finally: self.output = output.getvalue()

    def assert_original(self):
        self.assertEqual(self.files(), {rel: (content, 0o644) for rel, content in self.original.items()})
        self.assertFalse((self.root / installer.PRIVATE_DIR).exists())

    def test_success_is_narrow_pinned_private_and_runtime_verified_without_artisan_or_graph(self):
        before = self.files(); inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        report = self.update()
        expected = dict(before); expected.update({rel: (data, 0o644) for rel, data in self.payloads.items()})
        expected[installer.SETTINGS_FILE] = ((json.dumps(SETTINGS, indent=2) + '\n').encode(), 0o600)
        self.assertEqual(self.files(), expected)
        self.assertTrue(all(snapshot == before for snapshot in self.lint_snapshots))
        for rel in before:
            if rel not in self.payloads: self.assertEqual((self.root / rel).stat().st_ino, inodes[rel], rel)
        self.assertEqual(report['status'], 'READY'); self.assertEqual(report['updated_files'], len(self.payloads) + 1)
        self.assertEqual(report['redirect_uri'], SETTINGS['redirect_uri'])
        self.assertEqual(report['connect_url'], ORIGIN + '/admin/meta/connection')
        self.assertEqual(report['graph_requests'], 0); self.assertEqual(report['database_writes'], 0)
        self.assertFalse(any('artisan' in args for args in self.calls))
        self.assertFalse(any((self.root / rel).exists() for rel in installer.ROUTE_CACHE_FILES))
        backup = pathlib.Path(report['backup']); self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        for rel in installer.BASELINE_BLOBS:
            saved = backup / 'original' / rel
            self.assertEqual(saved.read_bytes(), self.original[rel]); self.assertEqual(saved.stat().st_mode & 0o777, 0o600)
        self.assertNotIn('PRIVATE_', self.output)

    def test_repeat_is_idempotent_and_preserves_existing_encrypted_credentials_and_file_inodes(self):
        self.update()
        self.write(installer.CREDENTIALS_FILE, b'encrypted-private-credential', 0o600)
        before = self.files(); inodes = {rel: (self.root / rel).stat().st_ino for rel in before}
        report = self.update()
        self.assertEqual(report['updated_files'], 0); self.assertEqual(self.files(), before)
        self.assertEqual({rel: (self.root / rel).stat().st_ino for rel in before}, inodes)
        self.assertNotIn('encrypted-private', self.output)

    def test_new_dependencies_precede_access_routes_views_controller_and_settings_activation(self):
        native_atomic = installer.atomic; publications = []
        def observe(path, content, mode):
            if path.is_relative_to(self.root):
                publications.append(str(path.relative_to(self.root)))
                # All existing source backups exist before any application file is published.
                backups = list((self.root.parent / 'meta-release-backups').iterdir())
                self.assertEqual(len(backups), 1)
                for rel in installer.BASELINE_BLOBS:
                    self.assertEqual((backups[0] / 'original' / rel).read_bytes(), self.original[rel])
            return native_atomic(path, content, mode)
        with mock.patch.object(installer, 'atomic', side_effect=observe): self.update()
        self.assertEqual(publications, list(installer.PUBLISH_ORDER) + [installer.SETTINGS_FILE])

    def test_unknown_base_new_file_or_changed_foundation_blocks_before_application_changes(self):
        for rel in (next(iter(installer.BASELINE_BLOBS)), installer.NEW_FILES[0], next(iter(installer.FOUNDATION_BLOBS))):
            with self.subTest(rel=rel):
                previous = self.original.get(rel)
                self.write(rel, b'<?php // unreviewed operator change\n')
                before = self.files()
                with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_META_OAUTH_SOURCE|META_AUTH_FOUNDATION_DIFFERS'): self.update()
                self.assertEqual(self.files(), before); self.assertEqual(self.calls, [])
                (self.root / rel).unlink()
                if previous is not None: self.write(rel, previous)

    def test_missing_base_and_cached_routes_block_before_any_application_change(self):
        rel = next(iter(installer.BASELINE_BLOBS)); (self.root / rel).unlink()
        with self.assertRaisesRegex(RuntimeError, 'REQUIRED_BASELINE_MISSING'): self.update()
        self.write(rel, self.original[rel])
        for cache in installer.ROUTE_CACHE_FILES:
            self.write(cache, b'<?php // native cached routes\n')
            before = self.files()
            with self.assertRaisesRegex(RuntimeError, 'CACHED_ROUTES_BLOCKED'): self.update()
            self.assertEqual(self.files(), before); (self.root / cache).unlink()

    def test_symlink_file_parent_private_and_backup_block_before_source_changes(self):
        rel = installer.NEW_FILES[0]; path = self.root / rel
        path.symlink_to(self.root / '.env')
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
        path.unlink(); self.assert_original()
        private = self.root / installer.PRIVATE_DIR; private.parent.mkdir()
        private.symlink_to(self.root / 'storage/app')
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
        private.unlink(); private.parent.rmdir()
        backup = self.root.parent / 'meta-release-backups'; backup.symlink_to(self.root)
        with self.assertRaisesRegex(RuntimeError, 'SYMLINK_TARGET'): self.update()
        backup.unlink(); self.assert_original()

    def test_foreign_file_ownership_or_unsafe_mode_block_before_source_changes(self):
        rel = next(iter(installer.BASELINE_BLOBS)); path = self.root / rel
        path.chmod(0o666)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE_MODE'): self.update()
        path.chmod(0o644)
        native_lstat = pathlib.Path.lstat
        def foreign_lstat(candidate):
            metadata = native_lstat(candidate)
            if candidate == path:
                fields = list(metadata); fields[4] = os.geteuid() + 10000
                return os.stat_result(fields)
            return metadata
        with mock.patch.object(pathlib.Path, 'lstat', autospec=True, side_effect=foreign_lstat):
            with self.assertRaisesRegex(RuntimeError, 'FOREIGN_FILE_OWNER'): self.update()
        self.assert_original()

    def link_source(self, rel):
        alias = self.root.parent / ('source-alias-' + str(len(list(self.root.parent.glob('source-alias-*')))))
        os.link(self.root / rel, alias)
        return alias

    def alias_state(self, path):
        metadata = path.stat()
        return (metadata.st_dev, metadata.st_ino, metadata.st_uid, metadata.st_gid,
                metadata.st_mode & 0o777, path.read_bytes())

    def test_pinned_read_only_hardlinked_foundation_and_its_alias_are_unchanged(self):
        rel = next(iter(installer.FOUNDATION_BLOBS)); alias = self.link_source(rel)
        before = self.alias_state(alias); original_links = alias.stat().st_nlink
        report = self.update()
        self.assertEqual(report['status'], 'READY')
        self.assertEqual(self.alias_state(alias), before)
        self.assertEqual(self.alias_state(self.root / rel), before)
        self.assertEqual(alias.stat().st_nlink, original_links)

    def test_pinned_hardlinked_baseline_replacement_keeps_alias_bytes_inode_and_mode_unchanged(self):
        rel = next(iter(installer.BASELINE_BLOBS)); alias = self.link_source(rel)
        before = self.alias_state(alias)
        self.update()
        self.assertEqual((self.root / rel).read_bytes(), self.payloads[rel])
        self.assertNotEqual((self.root / rel).stat().st_ino, alias.stat().st_ino)
        self.assertEqual((self.root / rel).stat().st_nlink, 1)
        self.assertEqual(self.alias_state(alias), before)
        self.assertEqual(alias.stat().st_nlink, 1)

    def test_already_pinned_new_hardlinked_source_is_accepted_and_never_rewritten(self):
        rel = installer.NEW_FILES[0]; self.write(rel, self.payloads[rel]); alias = self.link_source(rel)
        before = self.alias_state(alias)
        report = self.update()
        self.assertEqual(report['updated_files'], len(self.payloads))
        self.assertEqual(self.alias_state(alias), before)
        self.assertEqual(self.alias_state(self.root / rel), before)
        self.assertEqual(alias.stat().st_nlink, 2)

    def test_rollback_restores_hardlinked_source_target_without_modifying_original_alias(self):
        rel = next(iter(installer.BASELINE_BLOBS)); alias = self.link_source(rel)
        before = self.alias_state(alias); self.fail_verify = True
        with self.assertRaisesRegex(RuntimeError, 'SANITIZED_RUNTIME_PROBE_FAILED'): self.update()
        self.assert_original(); self.assertIn('RESTORED', self.output)
        self.assertEqual(self.alias_state(alias), before)
        self.assertNotEqual((self.root / rel).stat().st_ino, alias.stat().st_ino)
        self.assertEqual((self.root / rel).stat().st_nlink, 1)

    def test_hardlinked_private_settings_and_credentials_still_reject_before_writes(self):
        for rel in (installer.SETTINGS_FILE, installer.CREDENTIALS_FILE):
            with self.subTest(rel=rel):
                private = self.root / installer.PRIVATE_DIR; private.mkdir(parents=True, mode=0o700)
                self.write(rel, b'private-fixture-do-not-print', 0o600); alias = self.link_source(rel)
                before = self.files(); alias_before = self.alias_state(alias)
                with self.assertRaisesRegex(RuntimeError, 'HARDLINK_TARGET'): self.update()
                self.assertEqual(self.files(), before); self.assertEqual(self.alias_state(alias), alias_before)
                self.assertEqual(self.calls, []); self.assertFalse((self.root.parent / 'meta-release-backups').exists())
                self.assertNotIn('private-fixture-do-not-print', self.output)
                (self.root / rel).unlink(); alias.unlink(); private.rmdir()
        self.assert_original()

    def test_unknown_hardlinked_source_bytes_are_still_rejected_by_exact_pin(self):
        rel = installer.NEW_FILES[0]; self.write(rel, b'<?php // unapproved linked source\n')
        alias = self.link_source(rel); before = self.files(); alias_before = self.alias_state(alias)
        with self.assertRaisesRegex(RuntimeError, 'UNEXPECTED_META_OAUTH_SOURCE'): self.update()
        self.assertEqual(self.files(), before); self.assertEqual(self.alias_state(alias), alias_before)
        self.assertEqual(self.calls, [])

    def test_concurrent_mutation_through_source_alias_is_detected_before_publication(self):
        rel = next(iter(installer.BASELINE_BLOBS)); alias = self.link_source(rel)
        change = b'<?php // later operator edit through hardlink\n'
        self.preflight_action = lambda: alias.write_bytes(change)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        self.assertEqual((self.root / rel).read_bytes(), change); self.assertEqual(alias.read_bytes(), change)
        for baseline in installer.BASELINE_BLOBS:
            if baseline != rel: self.assertEqual((self.root / baseline).read_bytes(), self.original[baseline])
        self.assertFalse((self.root / installer.SETTINGS_FILE).exists())
        for new in installer.NEW_FILES: self.assertFalse((self.root / new).exists())

    def test_newly_added_published_source_hardlink_is_preserved_as_concurrent_edit(self):
        rel = installer.NEW_FILES[0]; holder = []
        self.verify_action = lambda: holder.append(self.link_source(rel))
        with self.assertRaisesRegex(RuntimeError, 'HARDLINK_TARGET'): self.update()
        self.assertIn('INCOMPLETE', self.output)
        self.assertEqual((self.root / rel).read_bytes(), self.payloads[rel])
        self.assertEqual(holder[0].read_bytes(), self.payloads[rel])
        self.assertEqual(holder[0].stat().st_ino, (self.root / rel).stat().st_ino)
        self.assertFalse((self.root / installer.SETTINGS_FILE).exists())

    def test_lint_and_sanitized_bootstrap_failures_leave_all_application_files_unchanged(self):
        self.fail_lint = True
        with self.assertRaisesRegex(RuntimeError, 'STAGED_PHP_LINT_FAILED'): self.update()
        self.assert_original(); self.fail_lint = False; self.fail_preflight = True
        with self.assertRaisesRegex(RuntimeError, 'SANITIZED_RUNTIME_PROBE_FAILED'): self.update()
        self.assert_original(); self.assertNotIn('PRIVATE_', self.output)

    def test_runtime_origin_secret_and_cache_override_validation_precedes_publication(self):
        for key, value in (('origin', 'http://unsafe.test'), ('origin', 'https://owner:secret@host.test'),
                           ('origin', 'https://host.test/path'), ('origin', 'https://host.test:99999'),
                           ('app_secret_present', False), ('route_cache_override', True), ('routes_cached', True),
                           ('ok', 1)):
            with self.subTest(key=key, value=value):
                old = self.probe[key]; self.probe[key] = value
                with self.assertRaises(RuntimeError): self.update()
                self.assert_original(); self.probe[key] = old

    def test_concurrent_source_or_private_credentials_before_publication_is_preserved(self):
        rel = next(iter(installer.BASELINE_BLOBS)); content = b'<?php // later operator edit\n'
        self.preflight_action = lambda: self.write(rel, content)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        self.assertEqual((self.root / rel).read_bytes(), content)
        self.assertFalse((self.root / installer.SETTINGS_FILE).exists())
        self.write(rel, self.original[rel])
        self.preflight_action = lambda: self.write(installer.CREDENTIALS_FILE, b'encrypted-token', 0o600)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        self.assertEqual((self.root / installer.CREDENTIALS_FILE).read_bytes(), b'encrypted-token')
        self.assertEqual((self.root / rel).read_bytes(), self.original[rel])

    def test_verification_failure_rolls_back_every_source_and_settings_without_deleting_business_data(self):
        self.fail_verify = True
        with self.assertRaisesRegex(RuntimeError, 'SANITIZED_RUNTIME_PROBE_FAILED'): self.update()
        self.assert_original(); self.assertIn('RESTORED', self.output); self.assertNotIn('PRIVATE_', self.output)
        self.assertTrue((self.root / 'bootstrap/cache/config.php').exists())
        self.assertEqual((self.root / '.env').read_bytes(), self.original['.env'])

    def test_verification_demands_exact_boolean_success_and_integer_route_and_view_counts(self):
        for bad in ({'ok': True, 'verified_routes': 3, 'views_compiled': 2},
                    {'ok': True, 'verified_routes': 4.0, 'views_compiled': 2},
                    {'ok': True, 'verified_routes': 4, 'views_compiled': True}):
            self.verification = bad
            with self.assertRaisesRegex(RuntimeError, 'META_OAUTH_ROUTE_VERIFICATION_FAILED'): self.update()
            self.assert_original()

    def test_later_operator_changes_survive_rollback_and_are_reported_incomplete(self):
        rel = installer.NEW_FILES[0]; later = b'<?php // operator changes after install\n'
        self.verify_action = lambda: self.write(rel, later)
        with self.assertRaisesRegex(RuntimeError, 'CONCURRENT_EDIT'): self.update()
        self.assertEqual((self.root / rel).read_bytes(), later)
        for original in installer.BASELINE_BLOBS:
            self.assertEqual((self.root / original).read_bytes(), self.original[original])
        self.assertIn('INCOMPLETE', self.output)
        self.assertFalse((self.root / installer.SETTINGS_FILE).exists())

    def test_existing_settings_mismatch_and_unsafe_private_directory_are_never_overwritten(self):
        (self.root / installer.PRIVATE_DIR).mkdir(parents=True, mode=0o700)
        self.write(installer.SETTINGS_FILE, b'{"app_id":"another-app"}\n', 0o600)
        before = self.files()
        with self.assertRaisesRegex(RuntimeError, 'EXISTING_OAUTH_SETTINGS_DIFFER'): self.update()
        self.assertEqual(self.files(), before)
        (self.root / installer.PRIVATE_DIR).chmod(0o755)
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_PRIVATE_DIRECTORY'): self.update()
        self.assertEqual(self.files(), before)

    def test_application_account_guard_rejects_root_and_other_accounts(self):
        with mock.patch.object(installer.os, 'geteuid', return_value=0):
            with self.assertRaisesRegex(RuntimeError, 'RUN_AS_FASAKHA'): installer.application_uid()
        with mock.patch.object(installer.os, 'geteuid', return_value=123), \
             mock.patch.object(installer.pwd, 'getpwuid', return_value=type('User', (), {'pw_name': 'someone'})()):
            with self.assertRaisesRegex(RuntimeError, 'RUN_AS_FASAKHA'): installer.application_uid()

    def test_atomic_failure_after_first_source_change_restores_originals(self):
        original_atomic = installer.atomic
        failing = self.root / list(installer.BASELINE_BLOBS)[1]
        def failing_atomic(path, content, mode):
            if path == failing and content == self.payloads[str(path.relative_to(self.root))]:
                raise OSError('private internal failure')
            return original_atomic(path, content, mode)
        with mock.patch.object(installer, 'atomic', side_effect=failing_atomic):
            with self.assertRaises(OSError): self.update()
        self.assert_original(); self.assertIn('RESTORED', self.output)

    def test_real_php_components_bootstrap_verify_crypt_routes_settings_and_blade_without_rendering(self):
        repository = pathlib.Path(__file__).resolve().parents[1]
        php = repository.parent / 'php-runtime/php'
        vendor = repository.parent / 'meta-test-runtime/vendor/autoload.php'
        if not php.is_file() or not vendor.is_file(): self.skipTest('Optional isolated PHP component runtime unavailable')
        self.payloads = {rel: (repository / rel).read_bytes() for rel in self.payloads}
        autoload = "<?php\nfunction env($key, $default = null) { return $default; }\nrequire " + repr(str(vendor)) + ";\n"
        self.write('vendor/autoload.php', autoload.encode())
        self.write('app/Http/Controllers/Controller.php', b'<?php namespace App\\Http\\Controllers; class Controller extends \\Illuminate\\Routing\\Controller {}\n')
        bootstrap = r'''<?php
function app($abstract = null) {
    $app = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract);
}
function config($key = null, $default = null) {
    $config = app('config');
    if (is_array($key)) { foreach ($key as $name => $value) $config->set($name, $value); return null; }
    return $key === null ? $config : $config->get($key, $default);
}
function storage_path($path = '') { return dirname(__DIR__).'/storage'.($path !== '' ? '/'.$path : ''); }
class OAuthFixtureApp extends \Illuminate\Container\Container {
    public function routesAreCached() { return false; }
}
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    if (strpos($class, 'App\\') !== 0) return;
    $path = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});
$app = new OAuthFixtureApp(); \Illuminate\Container\Container::setInstance($app);
$app->instance('config', new \Illuminate\Config\Repository([
    'app' => ['url' => 'https://DASHBOARD.example.test:443/'],
    'meta_messaging' => ['app_secret' => 'private-fixture-secret-never-output'],
]));
$app->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
$app->instance('events', new \Illuminate\Events\Dispatcher($app));
$app->instance('router', new \Illuminate\Routing\Router($app['events'], $app));
$app->instance('blade.compiler', new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), $root.'/storage/framework/views'));
$app->instance(\Illuminate\Contracts\Console\Kernel::class, new class { public function bootstrap() {} });
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
\Illuminate\Support\Facades\Route::middleware('web')->group($root.'/routes/meta_inbox.php');
$app['router']->getRoutes()->refreshNameLookups();
return $app;
'''
        self.write('bootstrap/app.php', bootstrap.encode())
        output = io.StringIO()
        with mock.patch.object(installer, 'application_uid', return_value=os.geteuid()), \
             mock.patch.object(installer, 'git_file', side_effect=self.git_file), \
             mock.patch.dict(os.environ, {}, clear=True), contextlib.redirect_stdout(output):
            report = installer.install(self.root, RELEASE, str(php))
        self.assertEqual(report['status'], 'READY')
        self.assertEqual(report['verified_routes'], 4); self.assertEqual(report['views_compiled'], 2)
        self.assertEqual(report['redirect_uri'], SETTINGS['redirect_uri'])
        self.assertNotIn('private-fixture-secret', output.getvalue())
        self.assertFalse((self.root / 'storage/framework/views').exists())


if __name__ == '__main__': unittest.main()
