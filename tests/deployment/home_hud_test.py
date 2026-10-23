"""Exercise the narrow HUD source transaction without server or database access."""
from contextlib import ExitStack, redirect_stdout
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('home_hud_publisher', REPO/'deployment/apply_home_hud_root.py')
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)

PATHS = (
    'app/Services/Dashboard/HomeHudLinks.php',
    'public/dashboard/css/home-hud.css',
    'public/dashboard/js/home-hud.js',
    'resources/views/admin/home_hud_solar.blade.php',
    'resources/views/admin/home_hud.blade.php',
    'public/dashboard/js/branch-home.js',
    'public/dashboard/js/home-overview.js',
    'resources/views/admin/home.blade.php',
    'resources/views/admin/home_branch.blade.php',
    'app/Http/Controllers/Dashboard/HomeController.php',
)
NEW = frozenset(PATHS[:5])


class HomeHudPublicationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)/'application'
        self.root.mkdir()
        self.backup = Path(self.tmp.name)/'backup'
        self.before = {name: ('before '+name).encode() for name in PATHS if name not in NEW}
        self.after = {name: ('after '+name).encode() for name in PATHS}
        digest = lambda data: hashlib.sha256(data).hexdigest()
        pins = {name: (None if name in NEW else digest(self.before[name]), digest(self.after[name])) for name in PATHS}
        self.pin_patch = patch.object(module, 'PINS', pins)
        self.pin_patch.start(); self.addCleanup(self.pin_patch.stop)
        self.publisher = module.engine((REPO/'deployment/apply_paymob_order_confirmation_root.py').read_bytes())
        for name in PATHS:
            path = self.root/name
            path.parent.mkdir(parents=True, exist_ok=True)
            if name in self.before:
                path.write_bytes(self.before[name]); path.chmod(0o640)
        self.calls = []

    def runner(self, args, **kwargs):
        self.assertIs(kwargs['stdout'], subprocess.DEVNULL)
        self.assertIs(kwargs['stderr'], subprocess.DEVNULL)
        self.assertFalse(kwargs['check'])
        if args[:3] == [module.PHP, '-n', '-l']:
            contents = Path(args[3]).read_bytes()
            self.assertIn(contents, (self.after[module.SERVICE], self.after[module.CONTROLLER]))
            self.calls.append(('lint', contents))
        else:
            self.assertEqual(args, ['/usr/sbin/runuser', '-u', 'fasakha', '--', module.PHP,
                '-d', 'display_errors=0', '-d', 'log_errors=0', 'artisan', 'view:clear'])
            self.assertEqual(str(self.root), kwargs['cwd'])
            self.assert_current()
            self.calls.append(('view-clear', None))
        return types.SimpleNamespace(returncode=0)

    def publish(self, runner=None, payloads=None, backup=None):
        output = io.StringIO()
        with redirect_stdout(output):
            result = module.publish_package(self.root, self.publisher, self.after if payloads is None else payloads,
                self.backup if backup is None else backup, os.getuid(), os.getgid(), self.runner if runner is None else runner)
        return result, output.getvalue()

    def assert_original(self):
        for name in PATHS:
            if name in self.before:
                self.assertEqual(self.before[name], (self.root/name).read_bytes())
            else:
                self.assertFalse((self.root/name).exists())

    def assert_current(self):
        for name in PATHS:
            self.assertEqual(self.after[name], (self.root/name).read_bytes())

    def test_dependencies_assets_and_views_publish_before_controller_with_backup_and_fresh_cache_keys(self):
        future = 2000000000000000000
        for name in self.before:
            os.utime(self.root/name, ns=(future, future))
        writes = []
        def record(target, *args):
            self.assertEqual(2, len([call for call in self.calls if call[0] == 'lint']))
            self.assertFalse(any(call[0] == 'view-clear' for call in self.calls))
            writes.append(target.relative_to(self.root).as_posix())
            return module.cache_replace(target, *args)
        with patch.object(self.publisher, 'replace_file', side_effect=record):
            changed, output = self.publish()
        self.assertTrue(changed); self.assertIn('HOME_HUD_SOURCE_READY', output)
        self.assertEqual(list(PATHS), writes)
        self.assertEqual(module.CONTROLLER, writes[-1])
        self.assert_current()
        metadata = json.loads((self.backup/'metadata.json').read_bytes())
        self.assertEqual(set(PATHS), set(metadata))
        for name in PATHS:
            info = (self.root/name).stat()
            self.assertEqual((os.getuid(), os.getgid()), (info.st_uid, info.st_gid))
            self.assertEqual(0o644 if name in NEW else 0o640, info.st_mode & 0o777)
            self.assertEqual(name not in NEW, metadata[name]['existed'])
            if name not in NEW:
                self.assertEqual(self.before[name], (self.backup/name).read_bytes())
                self.assertGreater(info.st_mtime_ns//1000000000, future//1000000000)
        self.assertEqual('view-clear', self.calls[-1][0])

    def test_controller_failure_removes_new_dependencies_and_restores_existing_source_without_post_step(self):
        def fail_controller(target, *args):
            if target == self.root/module.CONTROLLER:
                raise OSError('injected publication failure with private context')
            return module.cache_replace(target, *args)
        with patch.object(self.publisher, 'replace_file', side_effect=fail_controller):
            with self.assertRaises(OSError): self.publish()
        self.assert_original()
        self.assertTrue((self.backup/'metadata.json').is_file())
        self.assertFalse(any(call[0] == 'view-clear' for call in self.calls))

    def test_intervening_source_edit_is_refused_by_second_plan_before_publication(self):
        changed = PATHS[5]
        def change_during_lint(args, **kwargs):
            value = self.runner(args, **kwargs)
            if len(self.calls) == 2:
                (self.root/changed).write_bytes(b'independent concurrent source edit')
            return value
        with self.assertRaisesRegex(RuntimeError, '^BASELINE_MISMATCH$'): self.publish(runner=change_during_lint)
        self.assertEqual(b'independent concurrent source edit', (self.root/changed).read_bytes())
        for name in NEW: self.assertFalse((self.root/name).exists())
        self.assertFalse(self.backup.exists())
        self.assertFalse(any(call[0] == 'view-clear' for call in self.calls))

    def test_unreviewed_payload_source_unknown_paths_and_symlink_are_rejected_before_writes(self):
        altered = dict(self.after); altered[PATHS[1]] += b'unreviewed'
        with self.assertRaisesRegex(RuntimeError, '^PACKAGE_HASH_INVALID$'): self.publish(payloads=altered)
        with self.assertRaisesRegex(RuntimeError, '^PACKAGE_PATHS_INVALID$'):
            self.publish(payloads={**self.after, 'routes/unreviewed.php': b'code'})
        with self.assertRaisesRegex(RuntimeError, '^PACKAGE_PATHS_INVALID$'):
            self.publisher.plan(self.root, {**module.manifest(), 'other': {}}, self.after)
        path = self.root/PATHS[5]
        path.write_bytes(b'other source')
        with self.assertRaisesRegex(RuntimeError, '^BASELINE_MISMATCH$'): self.publish()
        path.unlink(); path.symlink_to(self.root/PATHS[6])
        with self.assertRaisesRegex(RuntimeError, '^SYMLINK_REJECTED$'): self.publish()
        self.assertEqual(self.before[PATHS[6]], (self.root/PATHS[6]).read_bytes())
        self.assertFalse(self.calls); self.assertFalse(self.backup.exists())
        for name in NEW: self.assertFalse((self.root/name).exists())

    def test_php_syntax_failure_is_sanitized_and_does_not_publish_or_clear_views(self):
        def fail(args, **kwargs):
            self.assertEqual([module.PHP, '-n', '-l'], args[:3])
            return types.SimpleNamespace(returncode=1)
        with self.assertRaisesRegex(RuntimeError, '^PHP_SYNTAX_PREFLIGHT_FAILED$'): self.publish(runner=fail)
        self.assert_original(); self.assertFalse(self.backup.exists())
        self.assertFalse(self.calls)
        with self.assertRaisesRegex(RuntimeError, '^PHP_SYNTAX_PREFLIGHT_FAILED$'):
            module.lint_payloads(self.after, lambda *a, **k: (_ for _ in ()).throw(OSError('secret PHP path')))

    def test_view_clear_failure_keeps_committed_source_and_retry_does_not_republish(self):
        def fail_view(args, **kwargs):
            result = self.runner(args, **kwargs)
            return types.SimpleNamespace(returncode=1) if 'view:clear' in args else result
        with self.assertRaisesRegex(RuntimeError, '^POST_PUBLICATION_VIEW_CLEAR_FAILED$'): self.publish(runner=fail_view)
        self.assert_current(); self.assertTrue((self.backup/'metadata.json').is_file())
        retry_backup = Path(self.tmp.name)/'retry-backup'
        with patch.object(self.publisher, 'replace_file', side_effect=AssertionError('idempotent retry wrote source')):
            changed, output = self.publish(backup=retry_backup)
        self.assertFalse(changed); self.assertIn('HOME_HUD_SOURCE_ALREADY_APPLIED', output)
        self.assertFalse(retry_backup.exists()); self.assert_current()
        self.assertEqual(2, len([call for call in self.calls if call[0] == 'view-clear']))

    def test_concurrent_edit_is_preserved_when_rollback_cannot_recognize_a_written_file(self):
        retained = self.root/PATHS[0]
        def fail_controller(target, *args):
            if target == self.root/module.CONTROLLER:
                retained.write_bytes(b'independent edit after publication')
                raise OSError('injected failure')
            return module.cache_replace(target, *args)
        with patch.object(self.publisher, 'replace_file', side_effect=fail_controller):
            with self.assertRaisesRegex(RuntimeError, '^ROLLBACK_REVIEW_REQUIRED$'): self.publish()
        self.assertEqual(b'independent edit after publication', retained.read_bytes())
        for name, data in self.before.items(): self.assertEqual(data, (self.root/name).read_bytes())
        self.assertFalse(any(call[0] == 'view-clear' for call in self.calls))


class HomeHudScopeTests(unittest.TestCase):
    def test_frozen_roster_excludes_routes_and_financial_or_schema_changes(self):
        self.assertEqual(PATHS, tuple(module.PINS))
        self.assertEqual(NEW, {name for name, (before, _) in module.PINS.items() if before is None})
        for before, after in module.PINS.values():
            if before is not None: self.assertRegex(before, r'^[a-f0-9]{64}$')
            self.assertRegex(after, r'^[a-f0-9]{64}$')
        with self.assertRaisesRegex(RuntimeError, '^PUBLISH_ENGINE_PIN_INVALID$'): module.engine(b'unreviewed engine')

    def test_shared_watcher_lock_refuses_a_competing_publication(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary)/'watcher.lock'
            # CI may run as an unprivileged checkout owner; only the owner field
            # is translated here so the real flock contention remains exercised.
            actual_fstat = os.fstat
            def root_stat(fd):
                info = actual_fstat(fd)
                return types.SimpleNamespace(st_mode=info.st_mode, st_uid=0, st_nlink=info.st_nlink)
            with patch.object(module.os, 'fstat', side_effect=root_stat), ExitStack() as first, ExitStack() as second:
                module.acquire(first, path, 'WATCHER')
                with self.assertRaisesRegex(RuntimeError, '^WATCHER_BUSY_RETRY_AFTER_COMPLETION$'):
                    module.acquire(second, path, 'WATCHER')


if __name__ == '__main__':
    unittest.main()
