#!/usr/bin/env python3
"""Guarded upgrade fixtures: no production DB, Graph request or AI request."""
import importlib.util
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / 'deployment/install_whatsapp_orders.py'
SPEC = importlib.util.spec_from_file_location('whatsapp_orders_installer', MODULE_PATH)
installer = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = installer
SPEC.loader.exec_module(installer)
base = installer.base
RELEASE = 'a' * 40


class Fixture:
    def __init__(self, directory, cached=False, newline=b'\n'):
        self.uid = os.geteuid()
        self.root = pathlib.Path(directory) / 'project'
        self.root.mkdir(mode=0o700)
        (self.root / '.git').mkdir()
        self.core = {}
        for index, relative in enumerate(base.CORE_HASHES):
            content = b'<?php /* guarded modern core ' + str(index).encode() + b' */\n'
            self.write(relative, content)
            self.core[relative] = base.blob_hash(content)
        self.original_routes = b'<?php' + newline + b'// existing production routes' + newline
        self.routes_hash = base.blob_hash(self.original_routes)
        self.routes = base.route_proposal(self.original_routes, self.routes_hash)
        self.original_menu = b'<nav>' + newline + base.MENU_ANCHOR + newline + b'</nav>' + newline
        self.menu_hash = base.blob_hash(self.original_menu)
        self.menu = base.menu_proposal(self.original_menu, self.menu_hash)
        self.write(installer.ROUTES_PATH, self.routes, 0o640)
        self.write(base.MENU_PATH, self.menu, 0o600)
        self.write('app/Console/Kernel.php', b"<?php $this->load(__DIR__.'/Commands');\n")
        self.env = b'APP_SECRET=fixture-only-never-copied\n'
        self.write('.env', self.env, 0o600)
        self.old = {relative: b'<?php // old inbox ' + relative.encode() + b'\n'
                    for relative in (*installer.BASE_GUARDS, *installer.UPGRADE_PATHS)}
        for relative, content in self.old.items():
            self.write(relative, content, 0o640 if relative in installer.UPGRADE_PATHS else 0o644)
        self.target = {relative: b'<?php // target orders/replies/read ' + relative.encode() + b'\n'
                       for relative in installer.MANIFEST}
        self.cache = b"<?php return ['database'=>['password'=>'fixture-effective-secret'],'app'=>['debug'=>false]];\n"
        self.candidate = self.cache + b'// feature-only candidate\n'
        if cached:
            self.write(installer.CACHE_PATH, self.cache, 0o600)
        self.calls = []
        self.fail = None
        self.hook = None
        self.state = {key: False for key in installer.READINESS_KEYS}
        self.state.update(mode_supported=True, erp_schema_ready=True)

    def write(self, relative, content, mode=0o644):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(mode)

    def runner(self, directory, args, capture=False, timeout=60):
        self.calls.append((pathlib.Path(directory), tuple(args), capture))
        if self.hook:
            self.hook(directory, args)
        if self.fail and self.fail(args):
            return 1, b'SENSITIVE_SUBPROCESS_OUTPUT_MUST_NOT_APPEAR'
        if args[:3] == ['git', 'cat-file', '-t']:
            return 0, b'commit\n'
        if args[:3] == ['git', 'ls-tree', '-z']:
            return 0, b'100644 blob ' + b'b' * 40 + b'\t' + args[-1].encode() + b'\0'
        if args[:2] == ['git', 'show']:
            release, relative = args[-1].split(':', 1)
            return 0, (self.old if release == installer.BASE_RELEASE else self.target).get(relative, b'')
        if args[:3] == ['php', '-r', installer.CONFIG_CANDIDATE_PHP]:
            path = pathlib.Path(args[-1])
            path.write_bytes(self.candidate)
            path.chmod(0o600)
        if args[:3] == ['php', '-r', installer.SMOKE_PHP]:
            return 0, json.dumps(self.state).encode()
        return 0, b''

    def install(self):
        return installer.install(self.root, self.uid, RELEASE, self.runner,
                                 self.core, self.routes_hash, self.menu_hash)

    def assert_originals(self, testcase, cached=False):
        testcase.assertEqual((self.root / installer.ROUTES_PATH).read_bytes(), self.routes)
        testcase.assertEqual((self.root / base.MENU_PATH).read_bytes(), self.menu)
        testcase.assertEqual((self.root / '.env').read_bytes(), self.env)
        for relative in installer.UPGRADE_PATHS:
            testcase.assertEqual((self.root / relative).read_bytes(), self.old[relative])
            testcase.assertEqual(stat.S_IMODE((self.root / relative).stat().st_mode), 0o640)
        if cached:
            testcase.assertEqual((self.root / installer.CACHE_PATH).read_bytes(), self.cache)
            testcase.assertEqual(stat.S_IMODE((self.root / installer.CACHE_PATH).stat().st_mode), 0o600)
        else:
            testcase.assertFalse((self.root / installer.CACHE_PATH).exists())

    def additions(self):
        return [relative for relative in installer.MANIFEST if relative not in installer.UPGRADE_PATHS]


class OrdersInstallerTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='whatsapp-orders-install-test-')
        self.fixture = Fixture(self.directory.name)

    def tearDown(self):
        self.directory.cleanup()

    def test_additive_upgrade_preserves_core_menu_env_and_cache_absence(self):
        f = self.fixture
        result, backup, state = f.install()
        self.assertEqual(result, 'WHATSAPP_ORDERS_READY_CONFIG_REQUIRED')
        self.assertEqual(state, f.state)
        for relative, content in f.target.items():
            self.assertEqual((f.root / relative).read_bytes(), content)
        self.assertEqual((f.root / installer.ROUTES_PATH).read_bytes(), installer.route_proposal(f.routes, f.routes_hash))
        self.assertEqual((f.root / base.MENU_PATH).read_bytes(), f.menu)
        self.assertEqual((f.root / '.env').read_bytes(), f.env)
        self.assertFalse((f.root / installer.CACHE_PATH).exists())
        for relative, wanted in f.core.items():
            self.assertEqual(base.blob_hash((f.root / relative).read_bytes()), wanted)
        for relative in installer.BASE_GUARDS:
            self.assertEqual((f.root / relative).read_bytes(), f.old[relative])
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((backup / 'receipt.json').stat().st_mode), 0o600)
        self.assertFalse(any(path.read_bytes() == f.env for path in backup.rglob('*') if path.is_file()))
        migrations = [args for _, args, _ in f.calls if 'migrate' in args]
        self.assertEqual(migrations, [('php', 'artisan', 'migrate', '--path=' + p, '--force') for p in installer.MIGRATIONS])
        forbidden = ('config:clear', 'config:cache', 'optimize:clear', 'migrate:rollback',
                     'whatsapp:process-orders', 'whatsapp:consume-inbox', 'pull', 'reset', 'checkout')
        self.assertFalse(any(any(word in args for word in forbidden) for _, args, _ in f.calls))

    def test_identical_repeat_preserves_inode_and_metadata(self):
        f = self.fixture
        f.install()
        paths = (*installer.MANIFEST, installer.ROUTES_PATH, base.MENU_PATH)
        before = {p: base.snapshot(f.root / p, f.uid, f.root) for p in paths}
        f.install()
        self.assertEqual(before, {p: base.snapshot(f.root / p, f.uid, f.root) for p in paths})

    def test_partial_repeat_existing_feature_anchor_retains_repaired_dependencies(self):
        for anchor in (*installer.UPGRADE_PATHS, installer.ROUTES_PATH):
            with tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                content = (installer.route_proposal(f.routes, f.routes_hash) if anchor == installer.ROUTES_PATH
                           else f.target[anchor])
                f.write(anchor, content)
                f.fail = lambda args: 'migrate' in args
                with self.assertRaisesRegex(base.SafeError, 'DEPENDENCIES_RETAINED'): f.install()
                self.assertEqual((f.root / anchor).read_bytes(), content)
                for path in f.additions(): self.assertTrue((f.root / path).exists())

    def test_crlf_route_append_is_exact_and_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, newline=b'\r\n')
            f.install()
            route = (f.root / installer.ROUTES_PATH).read_bytes()
            self.assertEqual(route, f.routes + b''.join(b'\r\n' + line + b'\r\n' for line in installer.ROUTE_REQUIRES))
            f.install()
            self.assertEqual((f.root / installer.ROUTES_PATH).read_bytes(), route)

    def test_missing_or_changed_core_refuses_before_additions(self):
        f = self.fixture
        path = next(iter(f.core))
        (f.root / path).unlink()
        with self.assertRaises(base.SafeError): f.install()
        self.assertFalse(any((f.root / p).exists() for p in f.additions()))
        f.write(path, b'changed production core')
        with self.assertRaisesRegex(base.SafeError, 'CORE_HASH_NEEDS_REVIEW'): f.install()

    def test_old_inbox_guard_and_command_autoload_are_required(self):
        f = self.fixture
        relative = installer.BASE_GUARDS[0]
        f.write(relative, b'unreviewed old inbox changes')
        with self.assertRaisesRegex(base.SafeError, 'LIVE_INBOX_BASE_NEEDS_REVIEW'): f.install()
        f.write(relative, f.old[relative])
        f.write('app/Console/Kernel.php', b'<?php // no command loading')
        with self.assertRaisesRegex(base.SafeError, 'COMMAND_AUTOLOAD_NEEDS_REVIEW'): f.install()

    def test_modified_old_ui_and_missing_ui_are_refused(self):
        f = self.fixture
        for relative in installer.UPGRADE_PATHS:
            f.write(relative, f.old[relative] + b'external change\n')
            with self.assertRaisesRegex(base.SafeError, 'EXISTING_WHATSAPP_UI_NEEDS_REVIEW'): f.install()
            (f.root / relative).unlink()
            with self.assertRaisesRegex(base.SafeError, 'EXISTING_WHATSAPP_UI_NEEDS_REVIEW'): f.install()
            f.write(relative, f.old[relative])
        self.assertFalse(any((f.root / p).exists() for p in f.additions()))

    def test_routes_require_precise_deployed_inbox_prefix(self):
        f = self.fixture
        for content in (f.original_routes, f.routes + b'// unreviewed\n',
                        f.routes + b'\n' + installer.ORDERS_REQUIRE + b'\n'):
            with self.assertRaises(base.SafeError): installer.route_proposal(content, f.routes_hash)
        f.write(base.MENU_PATH, f.original_menu)
        with self.assertRaisesRegex(base.SafeError, 'REQUIRE_EXISTING_INBOX_MENU'): f.install()

    def test_new_addition_accepts_only_absent_or_identical(self):
        f = self.fixture
        path = f.additions()[0]
        f.write(path, f.target[path], 0o620)
        f.install()
        self.assertEqual(stat.S_IMODE((f.root / path).stat().st_mode), 0o620)
        f.target[path] += b'unreviewed next version\n'
        with self.assertRaisesRegex(base.SafeError, 'EXISTING_ADDITION_NEEDS_REVIEW'): f.install()

    def test_symlink_existing_file_and_ancestor_are_refused(self):
        f = self.fixture
        path = installer.UPGRADE_PATHS[0]
        (f.root / path).unlink()
        (f.root / path).symlink_to(f.root / '.env')
        with self.assertRaisesRegex(base.SafeError, 'FILE_NOT_READABLE_OR_SYMLINK'): f.install()
        (f.root / path).unlink()
        f.write(path, f.old[path])
        outside = pathlib.Path(self.directory.name) / 'outside'
        outside.mkdir()
        (f.root / 'config').symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(base.SafeError, 'ANCESTOR_NOT_DIRECTORY_OR_SYMLINK'): f.install()

    def test_staged_symlink_mode_or_lint_failure_causes_no_mutation(self):
        f = self.fixture
        actual = f.runner
        def symlink(directory, args, **kwargs):
            code, output = actual(directory, args, **kwargs)
            if args[:3] == ['git', 'ls-tree', '-z'] and args[3] == RELEASE:
                output = output.replace(b'100644', b'120000')
            return code, output
        f.runner = symlink
        with self.assertRaisesRegex(base.SafeError, 'PINNED_FILE_NOT_REGULAR'): f.install()
        f.runner = actual
        f.fail = lambda args: args[:2] == ['php', '-l']
        with self.assertRaisesRegex(base.SafeError, 'PINNED_PHP_LINT_FAILED'): f.install()
        f.assert_originals(self)

    def test_cached_install_publishes_private_candidate_preserving_presence(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, cached=True)
            result, backup, _ = f.install()
            self.assertEqual(result, 'WHATSAPP_ORDERS_READY_CONFIG_REQUIRED')
            self.assertEqual((f.root / installer.CACHE_PATH).read_bytes(), f.candidate)
            self.assertEqual(stat.S_IMODE((f.root / installer.CACHE_PATH).stat().st_mode), 0o600)
            self.assertEqual(stat.S_IMODE((backup / 'candidate-config.php').stat().st_mode), 0o600)
            self.assertEqual((f.root / '.env').read_bytes(), f.env)
            self.assertFalse(any('config:clear' in args or 'config:cache' in args for _, args, _ in f.calls))
            f.cache = f.candidate
            inode = (f.root / installer.CACHE_PATH).stat().st_ino
            f.install()
            self.assertEqual((f.root / installer.CACHE_PATH).stat().st_ino, inode)

    def test_each_migration_and_smoke_failure_restore_files_keep_schema(self):
        for failure in (*installer.MIGRATIONS, 'smoke'):
            with tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory, cached=True)
                f.fail = lambda args: (args[:3] == ['php', '-r', installer.SMOKE_PHP] if failure == 'smoke'
                                       else '--path=' + failure in args)
                with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED_TABLES_RETAINED') as error:
                    f.install()
                self.assertNotIn('SENSITIVE_', str(error.exception))
                f.assert_originals(self, cached=True)
                self.assertFalse(any((f.root / p).exists() for p in f.additions()))
                self.assertFalse(any('migrate:rollback' in args for _, args, _ in f.calls))

    def test_config_candidate_failure_restores_new_additions(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, cached=True)
            f.fail = lambda args: args[:3] == ['php', '-r', installer.CONFIG_CANDIDATE_PHP]
            with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED_TABLES_RETAINED'): f.install()
            f.assert_originals(self, cached=True)
            self.assertFalse(any((f.root / p).exists() for p in f.additions()))

    def test_post_exchange_interruption_restores_tracked_publication(self):
        f = self.fixture
        actual = base.atomic_exchange
        interrupted = False
        def exchange(source, destination):
            nonlocal interrupted
            actual(source, destination)
            if not interrupted and destination == f.root / installer.ROUTES_PATH:
                interrupted = True
                raise KeyboardInterrupt()
        with mock.patch.object(base, 'atomic_exchange', exchange):
            with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED_TABLES_RETAINED'): f.install()
        self.assertTrue(interrupted)
        f.assert_originals(self)
        self.assertFalse(any((f.root / p).exists() for p in f.additions()))

    def test_post_link_interruption_removes_journaled_new_addition(self):
        f = self.fixture
        actual = base.os.link
        interrupted = False
        def link(source, destination, **kwargs):
            nonlocal interrupted
            actual(source, destination, **kwargs)
            if not interrupted and pathlib.Path(destination) == f.root / f.additions()[0]:
                interrupted = True
                raise KeyboardInterrupt()
        with mock.patch.object(base.os, 'link', link):
            with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED_TABLES_RETAINED'): f.install()
        self.assertTrue(interrupted)
        f.assert_originals(self)
        self.assertFalse(any((f.root / p).exists() for p in f.additions()))

    def test_rollback_race_retains_external_anchor_and_dependencies(self):
        f = self.fixture
        actual = base.atomic_move_noreplace
        external = installer.route_proposal(f.routes, f.routes_hash) + b'// edited during recovery\n'
        changed = False
        f.fail = lambda args: args[:3] == ['php', '-r', installer.SMOKE_PHP]
        def move(source, destination):
            nonlocal changed
            if not changed and pathlib.Path(source) == f.root / installer.ROUTES_PATH:
                changed = True
                pathlib.Path(source).write_bytes(external)
            actual(source, destination)
        with mock.patch.object(base, 'atomic_move_noreplace', move):
            with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
        self.assertTrue(changed)
        self.assertEqual((f.root / installer.ROUTES_PATH).read_bytes(), external)
        for path in f.additions(): self.assertTrue((f.root / path).exists())

    def test_invalid_smoke_output_refuses_without_exposing_report(self):
        f = self.fixture
        actual = f.runner
        def runner(directory, args, **kwargs):
            code, output = actual(directory, args, **kwargs)
            if args[:3] == ['php', '-r', installer.SMOKE_PHP]:
                return 0, b'{"unexpected_secret":"MUST_NOT_PRINT"}'
            return code, output
        f.runner = runner
        with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED_TABLES_RETAINED') as error: f.install()
        self.assertNotIn('MUST_NOT_PRINT', str(error.exception))
        f.assert_originals(self)

    def test_pre_exchange_external_route_include_retains_dependencies(self):
        f = self.fixture
        actual = base.atomic_exchange
        external = f.routes + b"\nrequire __DIR__.'/whatsapp_replies.php'; // concurrent\n"
        changed = False
        def exchange(source, destination):
            nonlocal changed
            if not changed and destination == f.root / installer.ROUTES_PATH:
                changed = True
                destination.write_bytes(external)
            actual(source, destination)
        with mock.patch.object(base, 'atomic_exchange', exchange):
            with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
        self.assertEqual((f.root / installer.ROUTES_PATH).read_bytes(), external)
        for path in f.additions(): self.assertTrue((f.root / path).exists())

    def test_post_exchange_external_ui_include_retains_dependencies(self):
        f = self.fixture
        relative = installer.UPGRADE_PATHS[0]
        actual = base.atomic_exchange
        external = f.target[relative] + b"@include('admin.whatsapp.replies')\n<!-- concurrent -->\n"
        changed = False
        def exchange(source, destination):
            nonlocal changed
            actual(source, destination)
            if not changed and destination == f.root / relative:
                changed = True
                destination.write_bytes(external)
                raise KeyboardInterrupt()
        with mock.patch.object(base, 'atomic_exchange', exchange):
            with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
        self.assertEqual((f.root / relative).read_bytes(), external)
        for path in f.additions(): self.assertTrue((f.root / path).exists())

    def test_concurrent_unpublished_ui_anchor_retains_external_and_dependencies(self):
        f = self.fixture
        path = installer.UPGRADE_PATHS[0]
        external = f.old[path] + b"@include('admin.whatsapp.replies') // concurrent\n"
        changed = False
        def hook(directory, args):
            nonlocal changed
            if not changed and 'migrate' in args:
                changed = True
                f.write(path, external)
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
        self.assertEqual((f.root / path).read_bytes(), external)
        for p in f.additions(): self.assertTrue((f.root / p).exists())

    def test_concurrent_ui_mode_change_is_preserved_with_dependencies(self):
        f = self.fixture
        path = installer.UPGRADE_PATHS[0]
        changed = False
        def hook(directory, args):
            nonlocal changed
            if not changed and 'migrate' in args:
                changed = True
                (f.root / path).chmod(0o600)
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
        self.assertEqual((f.root / path).read_bytes(), f.old[path])
        self.assertEqual(stat.S_IMODE((f.root / path).stat().st_mode), 0o600)
        for p in f.additions(): self.assertTrue((f.root / p).exists())

    def test_concurrent_cache_creation_is_retained_with_dependencies(self):
        f = self.fixture
        external = b'<?php // concurrent effective cache referencing WhatsAppOrderWorkflow\n'
        def hook(directory, args):
            if args[:3] == ['php', '-r', installer.SMOKE_PHP]: f.write(installer.CACHE_PATH, external, 0o600)
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
        self.assertEqual((f.root / installer.CACHE_PATH).read_bytes(), external)
        for p in f.additions(): self.assertTrue((f.root / p).exists())

    def test_concurrent_cache_edit_before_exchange_preserves_edit(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, cached=True)
            actual = base.atomic_exchange
            external = f.cache + b'// changed concurrently\n'
            changed = False
            def exchange(source, destination):
                nonlocal changed
                if not changed and destination == f.root / installer.CACHE_PATH:
                    changed = True
                    destination.write_bytes(external)
                actual(source, destination)
            with mock.patch.object(base, 'atomic_exchange', exchange):
                with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILES_AND_DEPENDENCIES_RETAINED'): f.install()
            self.assertEqual((f.root / installer.CACHE_PATH).read_bytes(), external)
            for p in f.additions(): self.assertTrue((f.root / p).exists())

    def test_env_metadata_change_refuses_without_rewriting_env(self):
        f = self.fixture
        external = f.env + b'EXTERNAL_SETTING=changed\n'
        done = False
        def hook(directory, args):
            nonlocal done
            if not done and 'migrate' in args:
                done = True
                f.write('.env', external, 0o600)
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED_TABLES_RETAINED'): f.install()
        self.assertEqual((f.root / '.env').read_bytes(), external)

    def test_readiness_reports_configured_review_and_auto_gate_without_processing(self):
        f = self.fixture
        f.state = dict.fromkeys(installer.READINESS_KEYS, True)
        f.state['auto_mode'] = False
        self.assertEqual(f.install()[0], 'WHATSAPP_ORDERS_READY_CONFIGURED')
        f.state.update(auto_mode=True, auto_business_hours_ready=False)
        self.assertEqual(f.install()[0], 'WHATSAPP_ORDERS_READY_AUTOMATION_GATES_REQUIRED')
        f.state['erp_schema_ready'] = False
        self.assertEqual(f.install()[0], 'WHATSAPP_ORDERS_READY_ERP_SCHEMA_REQUIRED')

    def test_cache_candidate_php_preserves_other_effective_values(self):
        php = os.environ.get('WHATSAPP_TEST_PHP') or shutil.which('php')
        if not php: self.skipTest('PHP unavailable; Python publication fixtures remain independent')
        f = self.fixture
        # A tiny application supplies fresh feature config without touching any DB.
        f.write('vendor/autoload.php', b'''<?php
class FixtureKernel { function bootstrap() {} }
class FixtureApplication { function make($x) { return new FixtureKernel(); }
 function getCachedConfigPath() { return getenv('APP_CONFIG_CACHE'); } }
function config($name) { return $name === 'whatsapp_orders' ? ['enabled'=>false,'model'=>null] : ['enabled'=>false,'access_token'=>null]; }
''')
        f.write('bootstrap/app.php', b'<?php return new FixtureApplication();\n')
        cache = pathlib.Path(self.directory.name) / 'original-cache.php'
        cache.write_bytes(b"<?php return ['database'=>['password'=>'effective-only'],'app'=>['debug'=>false],'legacy'=>['null'=>null,'int'=>7]];\n")
        candidate = pathlib.Path(self.directory.name) / 'candidate.php'
        absent = pathlib.Path(self.directory.name) / 'absent-cache.php'
        run = subprocess.run([php, '-r', installer.CONFIG_CANDIDATE_PHP, str(cache), str(absent), str(candidate)],
                             cwd=f.root, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(run.returncode, 0)
        self.assertFalse(absent.exists())
        check = r"$a=require $argv[1];$b=require $argv[2];unset($b['whatsapp_orders'],$b['whatsapp_replies']);exit($a===$b?0:1);"
        self.assertEqual(subprocess.run([php, '-r', check, str(cache), str(candidate)],
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE).returncode, 0)
        self.assertEqual(stat.S_IMODE(candidate.stat().st_mode), 0o600)
        # Identical feature values keep exact original cache bytes on a repeat.
        original_candidate = candidate.read_bytes()
        repeat = pathlib.Path(self.directory.name) / 'repeat.php'
        self.assertEqual(subprocess.run([php, '-r', installer.CONFIG_CANDIDATE_PHP, str(candidate), str(absent), str(repeat)],
                                       cwd=f.root, stdout=subprocess.PIPE, stderr=subprocess.PIPE).returncode, 0)
        self.assertEqual(repeat.read_bytes(), original_candidate)


if __name__ == '__main__':
    unittest.main()
