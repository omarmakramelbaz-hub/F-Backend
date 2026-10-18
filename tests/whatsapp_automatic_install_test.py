#!/usr/bin/env python3
"""Pinned runtime and dedicated systemd publication fixtures; no network or live data."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timedelta, timezone
import unittest
from unittest import mock

PROJECT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('whatsapp_automatic_installer', PROJECT / 'deployment/install_whatsapp_automatic.py')
installer = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = installer
SPEC.loader.exec_module(installer)
base = installer.base
RELEASE = 'a' * 40
TEST_MANIFEST = ('app/Support/Fixture.php', 'config/whatsapp_cart.php', installer.WORKFLOW_PATH,
                 'app/Console/Commands/ProcessWhatsAppOrders.php')
OLD = {path: ('<?php // prior ' + str(index) + '\n').encode() for index, path in enumerate(TEST_MANIFEST)
       if path != 'config/whatsapp_cart.php'}
TARGET = {path: ('<?php // reviewed target ' + str(index) + '\n').encode() for index, path in enumerate(TEST_MANIFEST)}
SOURCE = {path: base.blob_hash(content) for path, content in OLD.items()}


def metadata(path):
    info = path.lstat()
    return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev, info.st_ino,
            info.st_size, info.st_mtime_ns, info.st_ctime_ns, info.st_nlink)


class Fixture:
    def __init__(self, directory, cached=True):
        self.uid = os.geteuid()
        self.root = pathlib.Path(directory) / 'project'; self.root.mkdir(mode=0o700)
        (self.root / '.git').mkdir(mode=0o700)
        for path, content in OLD.items(): self.write(path, content)
        for index, path in enumerate(installer.PROTECTED_PATHS):
            if path == installer.CACHE_PATH and not cached:
                (self.root / path).parent.mkdir(parents=True, exist_ok=True); continue
            self.write(path, ('<?php // protected original ' + str(index) + '\n').encode(),
                       0o600 if path in ('.env', installer.CACHE_PATH) else 0o644)
        self.originals = {p: (self.path(p).read_bytes(), metadata(self.path(p))) for p in installer.PROTECTED_PATHS
                          if self.path(p).exists()}
        self.cached = cached
        self.calls = []; self.hook = None; self.fail = None
        self.mode = b'100644'; self.kind = b'commit\n'; self.altered = None
        self.review = True; self.fingerprint = hashlib.sha256(b'effective-fixture').hexdigest()
        self.route_cache = 'bootstrap/cache/routes-v7.php'

    def path(self, relative): return self.root / relative

    def write(self, relative, content, mode=0o640):
        path = self.path(relative); path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content); path.chmod(mode)

    def runner(self, root, args, capture=False, timeout=60):
        self.calls.append(tuple(args))
        if self.hook: self.hook(args)
        if self.fail and self.fail(args): return 1, b'PRIVATE_ERROR_MUST_NOT_ESCAPE'
        if args[:3] == ['git', 'cat-file', '-t']: return 0, self.kind
        if args[:3] == ['git', 'ls-tree', '-z']:
            path = args[-1]
            return 0, self.mode + b' blob ' + base.blob_hash(TARGET[path]).encode() + b'\t' + path.encode() + b'\0'
        if args[:2] == ['git', 'show']:
            path = args[-1].split(':', 1)[1]
            return 0, TARGET[path] + (b' altered' if self.altered == path else b'')
        if args[:2] == ['php', '-l']: return 0, b''
        if args[:3] == ['php', '-r', installer.READINESS_PHP]:
            return 0, json.dumps({'review': self.review, 'fingerprint': self.fingerprint,
                                 'route_cache_path': self.route_cache}).encode()
        raise AssertionError('Unapproved command: ' + repr(args[:2]))

    def install(self): return installer.install(self.root, self.uid, RELEASE, self.runner, TEST_MANIFEST, SOURCE)

    def assert_protected(self, case):
        for path, old in self.originals.items():
            case.assertEqual((self.path(path).read_bytes(), metadata(self.path(path))), old, path)
        if not self.cached: case.assertFalse(self.path(installer.CACHE_PATH).exists())

    def assert_old(self, case):
        for path, content in OLD.items(): case.assertEqual(self.path(path).read_bytes(), content)
        case.assertFalse(self.path('config/whatsapp_cart.php').exists())


class AutomaticUpgradeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='whatsapp-auto-install-')
        self.fixture = Fixture(self.directory.name)

    def tearDown(self): self.directory.cleanup()

    def test_pinned_manifest_only_preserves_live_secrets_config_sidebar_routes_pos_bytes_and_metadata(self):
        f = self.fixture; status, backup = f.install()
        self.assertIn('READY_REVIEW_ONLY', status)
        for path, content in TARGET.items(): self.assertEqual(f.path(path).read_bytes(), content)
        f.assert_protected(self)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        receipt = json.loads((backup / 'receipt.json').read_bytes())
        self.assertEqual(receipt['release'], RELEASE)
        self.assertEqual(receipt['target_blobs'], {p: base.blob_hash(c) for p, c in TARGET.items()})
        protected_bytes = {original[0] for original in f.originals.values()}
        self.assertFalse(any(path.read_bytes() in protected_bytes for path in backup.rglob('*') if path.is_file()))
        self.assertFalse(any('artisan' in args or any(x in args for x in
            ('migrate', 'config:cache', 'config:clear', 'route:clear', 'checkout', 'reset', 'pull', 'systemctl'))
            for args in f.calls))

    def test_repeat_keeps_runtime_inodes_and_modes(self):
        f = self.fixture; f.install()
        before = {p: metadata(f.path(p)) for p in TEST_MANIFEST}
        status, _ = f.install(); self.assertIn('ALREADY_CURRENT', status)
        self.assertEqual(before, {p: metadata(f.path(p)) for p in TEST_MANIFEST})
        f.assert_protected(self)

    def test_current_valid_compiled_routes_cache_is_unchanged_on_repeat(self):
        f = self.fixture; f.install(); f.write(f.route_cache, b'<?php // current compiled routes\n', 0o644)
        cached = (f.path(f.route_cache).read_bytes(), metadata(f.path(f.route_cache)))
        status, backup = f.install(); self.assertIn('ALREADY_CURRENT', status)
        self.assertEqual((f.path(f.route_cache).read_bytes(), metadata(f.path(f.route_cache))), cached)
        self.assertFalse((backup / 'routes-cache.before.php').exists()); f.assert_protected(self)

    def test_uncached_and_prior_installer_hardlinked_configs_remain_unchanged(self):
        with tempfile.TemporaryDirectory() as directory:
            f = Fixture(directory, False)
            for path in installer.CONFIG_PATHS:
                alias = pathlib.Path(directory) / pathlib.Path(path).name
                os.link(f.path(path), alias)
                f.originals[path] = (f.path(path).read_bytes(), metadata(f.path(path)))
            f.install(); f.assert_protected(self)

    def test_unknown_existing_source_or_new_file_is_never_overwritten(self):
        for path in (installer.WORKFLOW_PATH, 'config/whatsapp_cart.php'):
            with self.subTest(path=path), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory); external = b'<?php // external change\n'; f.write(path, external)
                with self.assertRaises(base.SafeError): f.install()
                self.assertEqual(f.path(path).read_bytes(), external); f.assert_protected(self)

    def test_review_mode_required_before_git_staging_or_publication(self):
        f = self.fixture; f.review = False
        with self.assertRaises(base.SafeError): f.install()
        f.assert_old(self); f.assert_protected(self)
        self.assertFalse(any(args[0] == 'git' for args in f.calls))

    def test_invalid_pin_commit_kind_tree_mode_blob_bytes_and_php_lint_are_refused(self):
        for failure in ('pin', 'kind', 'symlink', 'executable', 'bytes', 'lint'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                if failure == 'kind': f.kind = b'tree\n'
                if failure == 'symlink': f.mode = b'120000'
                if failure == 'executable': f.mode = b'100755'
                if failure == 'bytes': f.altered = TEST_MANIFEST[0]
                if failure == 'lint': f.fail = lambda args: args[:2] == ['php', '-l']
                with self.assertRaises(base.SafeError):
                    if failure == 'pin': installer.install(f.root, f.uid, 'main', f.runner, TEST_MANIFEST, SOURCE)
                    else: f.install()
                f.assert_old(self); f.assert_protected(self)

    def test_symlink_live_file_or_ancestor_never_writes_referent(self):
        f = self.fixture; outside = pathlib.Path(self.directory.name) / 'outside.php'; outside.write_bytes(OLD[TEST_MANIFEST[0]])
        f.path(TEST_MANIFEST[0]).unlink(); f.path(TEST_MANIFEST[0]).symlink_to(outside)
        with self.assertRaises(base.SafeError): f.install()
        self.assertEqual(outside.read_bytes(), OLD[TEST_MANIFEST[0]]); self.assertTrue(f.path(TEST_MANIFEST[0]).is_symlink())

    def test_secret_hardlinks_are_refused(self):
        for path in ('.env', installer.CACHE_PATH):
            with self.subTest(path=path), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory); os.link(f.path(path), pathlib.Path(directory) / 'alias')
                with self.assertRaises(base.SafeError): f.install()
                f.assert_old(self)

    def test_all_published_runtime_files_restore_on_failed_post_smoke(self):
        f = self.fixture; calls = 0
        def fail(args):
            nonlocal calls
            if args[:3] == ['php', '-r', installer.READINESS_PHP]:
                calls += 1; return calls == 2
            return False
        f.fail = fail
        with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED'): f.install()
        f.assert_old(self); f.assert_protected(self)

    def test_interruption_after_atomic_syscall_restores_our_files(self):
        f = self.fixture; original = base.atomic_exchange; calls = 0
        def exchange(*args):
            nonlocal calls
            original(*args)
            if 'atomic-probe' not in str(args[0]):
                calls += 1
                if calls == 2: raise KeyboardInterrupt()
        with mock.patch.object(base, 'atomic_exchange', side_effect=exchange):
            with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED'): f.install()
        f.assert_old(self); f.assert_protected(self)

    def test_concurrent_entrypoint_edit_is_retained_with_earlier_dependencies(self):
        f = self.fixture
        def hook(args):
            if args[:3] == ['php', '-r', installer.READINESS_PHP] and f.path('config/whatsapp_cart.php').exists():
                f.write(TEST_MANIFEST[-1], b'<?php // external entry point\n'); raise RuntimeError('private')
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILE_RETAINED'): f.install()
        self.assertEqual(f.path(TEST_MANIFEST[-1]).read_bytes(), b'<?php // external entry point\n')
        self.assertTrue(f.path('config/whatsapp_cart.php').exists()); f.assert_protected(self)

    def test_configuration_mutation_through_retained_alias_keeps_new_runtime_guard(self):
        f = self.fixture; alias = pathlib.Path(self.directory.name) / 'config-alias'
        os.link(f.path(installer.CONFIG_PATHS[0]), alias)
        f.originals[installer.CONFIG_PATHS[0]] = (alias.read_bytes(), metadata(alias))
        def hook(args):
            if args[:3] == ['php', '-r', installer.READINESS_PHP] and f.path('config/whatsapp_cart.php').exists():
                alias.write_bytes(b'<?php // changed config\n')
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'RUNTIME_RETAINED'): f.install()
        self.assertEqual(f.path(installer.WORKFLOW_PATH).read_bytes(), TARGET[installer.WORKFLOW_PATH])

    def test_effective_config_drift_without_file_change_retains_new_guard_and_dependencies(self):
        f = self.fixture
        def hook(args):
            if args[:3] == ['php', '-r', installer.READINESS_PHP] and args[-1] == 'target':
                f.review = False; f.fingerprint = hashlib.sha256(b'changed-effective-mode').hexdigest()
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'RUNTIME_RETAINED'): f.install()
        for path, content in TARGET.items(): self.assertEqual(f.path(path).read_bytes(), content)
        f.assert_protected(self)

    def test_shared_orders_install_lock_prevents_simultaneous_upgrade(self):
        import fcntl
        f = self.fixture; parent = f.root.parent / 'whatsapp-release-backups'; parent.mkdir(mode=0o700)
        descriptor = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT, 0o600)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX)
            with self.assertRaisesRegex(base.SafeError, 'ANOTHER_ORDERS_INSTALL'): f.install()
        finally: os.close(descriptor)
        f.assert_old(self); f.assert_protected(self)

    def test_protected_owner_mismatch_is_refused_without_publication(self):
        from types import SimpleNamespace
        f = self.fixture; target_inode = f.path('.env').stat().st_ino; original = os.fstat
        def foreign_owner(descriptor):
            info = original(descriptor)
            if info.st_ino != target_inode: return info
            fields = {name: getattr(info, name) for name in ('st_dev','st_ino','st_mode','st_uid','st_gid',
                        'st_nlink','st_size','st_mtime_ns','st_ctime_ns')}
            fields['st_uid'] = f.uid + 1
            return SimpleNamespace(**fields)
        with mock.patch.object(os, 'fstat', side_effect=foreign_owner):
            with self.assertRaises(base.SafeError): f.install()
        f.assert_old(self); f.assert_protected(self)

    def test_only_framework_route_cache_is_retained_privately_and_config_cache_stays_identical(self):
        f = self.fixture; cached_routes = b'<?php // old compiled routes\n'; f.write(f.route_cache, cached_routes, 0o644)
        old_cache = base.snapshot(f.path(f.route_cache), f.uid, f.root)
        unrelated = 'bootstrap/cache/fixture-unrelated.php'; f.write(unrelated, b'<?php // unrelated cache\n')
        unrelated_before = (f.path(unrelated).read_bytes(), metadata(f.path(unrelated)))
        _, backup = f.install()
        self.assertFalse(f.path(f.route_cache).exists())
        self.assertEqual(base.snapshot(backup / 'routes-cache.before.php', f.uid, f.root), old_cache)
        self.assertEqual((f.path(unrelated).read_bytes(), metadata(f.path(unrelated))), unrelated_before)
        f.assert_protected(self)

    def test_failed_new_routes_smoke_restores_exact_compiled_cache_inode_with_runtime(self):
        f = self.fixture; f.write(f.route_cache, b'<?php // compiled routes\n', 0o644)
        cached = base.snapshot(f.path(f.route_cache), f.uid, f.root)
        f.fail = lambda args: args[:3] == ['php', '-r', installer.READINESS_PHP] and args[-1] == 'target'
        with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED'): f.install()
        self.assertEqual(base.snapshot(f.path(f.route_cache), f.uid, f.root), cached)
        f.assert_old(self); f.assert_protected(self)

    def test_external_cache_recreated_after_clear_is_retained_on_failure(self):
        f = self.fixture; f.write(f.route_cache, b'<?php // original routes\n', 0o644)
        def hook(args):
            if args[:3] == ['php', '-r', installer.READINESS_PHP] and args[-1] == 'target':
                f.write(f.route_cache, b'<?php // external compiled routes\n'); raise RuntimeError('private')
        f.hook = hook
        with self.assertRaisesRegex(base.SafeError, 'CONCURRENT_FILE_RETAINED'): f.install()
        self.assertEqual(f.path(f.route_cache).read_bytes(), b'<?php // external compiled routes\n')
        f.assert_old(self); f.assert_protected(self)

    def test_unsafe_framework_cached_path_or_symlink_is_refused_before_publication(self):
        for mode in ('outside', 'symlink'):
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as directory:
                f = Fixture(directory)
                if mode == 'outside': f.route_cache = '/etc/fixture.php'
                else:
                    outside = pathlib.Path(directory) / 'routes.php'; outside.write_bytes(b'<?php // external\n')
                    f.path(f.route_cache).symlink_to(outside)
                with self.assertRaises(base.SafeError): f.install()
                f.assert_old(self); f.assert_protected(self)

    def test_interruption_immediately_after_route_cache_move_restores_captured_inode(self):
        f = self.fixture; f.write(f.route_cache, b'<?php // compiled old routes\n', 0o644)
        cache = base.snapshot(f.path(f.route_cache), f.uid, f.root); original = base.atomic_move_noreplace
        def move(source, destination):
            original(source, destination)
            if pathlib.Path(source) == f.path(f.route_cache): raise KeyboardInterrupt()
        with mock.patch.object(base, 'atomic_move_noreplace', side_effect=move):
            with self.assertRaisesRegex(base.SafeError, 'FILES_RESTORED'): f.install()
        self.assertEqual(base.snapshot(f.path(f.route_cache), f.uid, f.root), cache)
        f.assert_old(self); f.assert_protected(self)


def root_module():
    source = (PROJECT / 'deployment/install_whatsapp_automatic_root.sh').read_text()
    body = source.split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]
    namespace = {'__name__': 'whatsapp_systemd_fixture'}
    exec(compile(body, 'install_whatsapp_automatic_root.sh.py', 'exec'), namespace)
    return namespace, source


class RootTimerTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='whatsapp-timer-fixture-')
        self.ns, self.source = root_module()
        self.units = pathlib.Path(self.directory.name) / 'units'; self.units.mkdir(mode=0o755)
        self.ns['UNIT_DIRECTORY'] = self.units
        self.ns['BACKUP_DIRECTORY'] = pathlib.Path(self.directory.name) / 'backups'
        self.ns['SYSTEM_UID'] = os.geteuid()
        # Fixture ancestors can be the runner's /tmp. Production retains the full fixed-path guard.
        def fixture_path(path, directory=False):
            info = path.lstat()
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o022 or not (
                    stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
                raise self.ns['SafeError']('fixture owner or type')
            return info
        self.ns['system_path'] = fixture_path
        self.calls = []; self.fail = None; self.preflight = True
        self.active = False

    def tearDown(self): self.directory.cleanup()

    def runner(self, args, capture=False):
        self.calls.append(tuple(args))
        if self.fail and self.fail(args): return 1, b'PRIVATE_SYSTEM_ERROR'
        if args[0] == '/usr/sbin/runuser': return 0, b'{"automatic_ready":true}' if self.preflight else b'{}'
        if args[:3] == ['/usr/bin/systemctl', 'is-active', '--quiet']:
            return (0 if self.active else 3), b''
        if args[:3] == ['/usr/bin/systemctl', 'enable', '--now']: self.active = True
        if args[:2] == ['/usr/bin/systemctl', 'stop']: self.active = False
        return 0, b''

    def install(self): return self.ns['install'](self.runner)

    def test_install_validates_and_adds_only_two_units_without_start_enable_or_other_services(self):
        status, backup = self.install()
        self.assertIn('INSTALLED_NOT_STARTED', status)
        self.assertEqual(set(p.name for p in self.units.iterdir()), set(self.ns['UNITS']))
        for name, content in self.ns['UNITS'].items():
            self.assertEqual((self.units / name).read_bytes(), content)
            self.assertEqual(stat.S_IMODE((self.units / name).stat().st_mode), 0o644)
        self.assertFalse(any('enable' in args or 'start' in args or 'runuser' in args for args in self.calls))
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)

    def test_repeat_preserves_unit_inode_and_performs_no_systemctl_calls(self):
        self.install(); before = {p.name: metadata(p) for p in self.units.iterdir()}; self.calls.clear()
        status, backup = self.install(); self.assertIn('ALREADY_INSTALLED', status); self.assertIsNone(backup)
        self.assertEqual(before, {p.name: metadata(p) for p in self.units.iterdir()}); self.assertEqual(self.calls, [])

    def test_unrelated_existing_unit_retained(self):
        path = self.units / self.ns['SERVICE']; path.write_bytes(b'[Service]\nExecStart=fixture\n'); path.chmod(0o644)
        with self.assertRaisesRegex(self.ns['SafeError'], 'UNRELATED_UNIT_RETAINED'): self.install()
        self.assertEqual(path.read_bytes(), b'[Service]\nExecStart=fixture\n'); self.assertEqual(self.calls, [])

    def test_symlink_or_hardlinked_existing_unit_refused(self):
        for kind in ('symlink', 'hardlink'):
            with self.subTest(kind=kind):
                path = self.units / self.ns['SERVICE']; outside = pathlib.Path(self.directory.name) / 'outside'
                outside.write_bytes(self.ns['SERVICE_BYTES']); outside.chmod(0o644)
                if kind == 'symlink': path.symlink_to(outside)
                else: os.link(outside, path)
                with self.assertRaises(self.ns['SafeError']): self.install()
                self.assertEqual(outside.read_bytes(), self.ns['SERVICE_BYTES']); path.unlink(); outside.unlink()

    def test_verify_or_daemon_reload_failure_never_leaves_our_new_units(self):
        for failing in ('verify', 'daemon-reload'):
            with self.subTest(failing=failing):
                self.fail = lambda args: failing in args
                with self.assertRaises(self.ns['SafeError']): self.install()
                self.assertEqual(list(self.units.iterdir()), [])

    def test_root_peer_unit_edit_after_publication_is_retained_without_overwrite(self):
        original = self.runner
        def runner(args, capture=False):
            result = original(args, capture)
            if args == ['/usr/bin/systemctl', 'daemon-reload']:
                path = self.units / self.ns['SERVICE']; path.write_bytes(b'[Service]\n# changed by peer\n')
            return result
        with self.assertRaisesRegex(self.ns['SafeError'], 'CONCURRENT_UNIT_RETAINED'): self.ns['install'](runner)
        self.assertEqual((self.units / self.ns['SERVICE']).read_bytes(), b'[Service]\n# changed by peer\n')
        self.assertFalse((self.units / self.ns['TIMER']).exists())

    def test_start_requires_fresh_auto_actor_branch_cutoff_preflight_then_enables_only_our_timer(self):
        self.install(); self.calls.clear(); self.preflight = False
        with self.assertRaisesRegex(self.ns['SafeError'], 'CONFIGURATION_NOT_READY'): self.ns['start'](self.runner)
        self.assertFalse(any('enable' in args for args in self.calls))
        self.preflight = True; self.calls.clear()
        self.assertEqual(self.ns['start'](self.runner), 'WHATSAPP_AUTOMATIC_TIMER_ACTIVE')
        self.assertEqual(self.calls[0][0:4], ('/usr/sbin/runuser', '-u', 'fasakha', '--'))
        self.assertIn(('/usr/bin/systemctl', 'enable', '--now', self.ns['TIMER']), self.calls)

    def test_scoped_stop_quiesces_only_our_timer_and_service(self):
        self.install(); self.active = True; self.calls.clear()
        self.assertIn('STOPPED', self.ns['stop'](self.runner))
        self.assertEqual(self.calls[0], ('/usr/bin/systemctl', 'stop', self.ns['TIMER'], self.ns['SERVICE']))

    def test_fresh_process_singleton_and_five_seconds_after_finish_are_fixed(self):
        service = self.ns['SERVICE_BYTES'].decode(); timer = self.ns['TIMER_BYTES'].decode()
        self.assertIn('User=fasakha\nGroup=fasakha\n', service)
        self.assertIn('RuntimeDirectoryMode=0700', service)
        self.assertIn('RuntimeDirectoryPreserve=yes', service)
        self.assertIn('/usr/bin/flock --nonblock --conflict-exit-code=0 ', service)
        self.assertIn('artisan whatsapp:process-orders --limit=10\n', service)
        self.assertNotIn('--loop', service); self.assertNotIn('queue:', service)
        self.assertIn('OnUnitInactiveSec=5s\n', timer); self.assertNotIn('OnUnitActiveSec=', timer)
        self.assertIn('RuntimeDirectory=fasakhansta-whatsapp-orders\n', service)
        self.assertIn("sys.argv[1] not in ('--install', '--start', '--stop')", self.source)
        self.assertIn("getConstant('AUTOMATIC_ACTIVATION_TIME_GUARD') !== 'whatsapp-auto-activation-time-v1'", self.ns['PREFLIGHT_PHP'])
        self.assertIn("$cutover->format('Y-m-d H:i:s') !== $activatedAt", self.ns['PREFLIGHT_PHP'])

    def test_real_php_preflight_rejects_invalid_or_missing_cutover_and_missing_runtime_guards(self):
        php = (os.environ.get('WHATSAPP_TEST_PHP') or os.environ.get('WA_INBOX_TEST_PHP') or shutil.which('php')
               or '/workspace/scratch/04d0c68ec142/php-runtime/php')
        if not pathlib.Path(php).is_file(): self.skipTest('PHP runtime unavailable for actual preflight validation')
        root = pathlib.Path(self.directory.name) / 'php-fixture'; root.mkdir()
        (root / 'vendor').mkdir(); (root / 'bootstrap').mkdir()
        state = {
            'whatsapp_orders': {'enabled': True, 'mode': 'auto', 'model': 'gpt-6-luna', 'api_key': 'fixture-only',
                'automation_actor_id': '1', 'allowed_branch_ids': ['94'],
                'activation_message_id': 100, 'activation_event_id': 200},
            'whatsapp_cart': {'product_mappings': {},
                'activated_at': datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')},
        }
        bootstrap = "<?php $GLOBALS['fixture_configuration']=json_decode(file_get_contents('fixture.json'),true); return new FixtureApplication();\n"
        (root / 'bootstrap/app.php').write_text(bootstrap)
        fixture_classes = r'''<?php
namespace App\Services\Dashboard {
    class WhatsAppOrderWorkflow { public const AUTOMATIC_ACTIVATION_TIME_GUARD = 'MARKER'; public function available() { return true; } }
    class WhatsAppInboxConsumer { public const QUARANTINE_BATCH_RECHECK = RECHECK; }
    class WhatsAppInboxAccess { public function actor($actor) { return $actor; } }
    class TakeawayAccess {
        public function permissions($actor) { return ['can_checkout'=>true]; }
        public function branch($value,$actor) { return ['value'=>$value]; }
    }
}
namespace App\Models { class User { public static function withoutGlobalScopes() { return new self(); } public function find($id) { return (object)['id'=>$id]; } } }
namespace {
    function config($key) { return $GLOBALS['fixture_configuration'][$key] ?? null; }
    function app($class) { return new $class(); }
    class FixtureConsoleKernel { public function bootstrap() {} public function all() { return ['whatsapp:process-orders'=>true]; } }
    class FixtureApplication {
        public function make($class) { return new FixtureConsoleKernel(); }
        public function getCachedConfigPath() { return getcwd().'/bootstrap/cache/config.php'; }
    }
}
'''
        script = root / 'preflight.php'; script.write_text('<?php\n' + self.ns['PREFLIGHT_PHP'])
        scenarios = ('valid','missing_time','bad_calendar','not_canonical','future','bad_guard','bad_consumer_guard','review','bad_branch')
        for scenario in scenarios:
            with self.subTest(scenario=scenario):
                candidate = json.loads(json.dumps(state)); marker = 'whatsapp-auto-activation-time-v1'; recheck = 'true'
                if scenario == 'missing_time': candidate['whatsapp_cart']['activated_at'] = None
                if scenario == 'bad_calendar': candidate['whatsapp_cart']['activated_at'] = '2026-02-30 12:00:00'
                if scenario == 'not_canonical': candidate['whatsapp_cart']['activated_at'] = '2026-1-1 01:00:00'
                if scenario == 'future': candidate['whatsapp_cart']['activated_at'] = (datetime.now(timezone.utc)+timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S')
                if scenario == 'bad_guard': marker = 'old-fixture-marker'
                if scenario == 'bad_consumer_guard': recheck = 'false'
                if scenario == 'review': candidate['whatsapp_orders']['mode'] = 'review'
                if scenario == 'bad_branch': candidate['whatsapp_orders']['allowed_branch_ids'] = ['094']
                (root / 'fixture.json').write_text(json.dumps(candidate))
                (root / 'vendor/autoload.php').write_text(fixture_classes.replace('MARKER', marker).replace('RECHECK;', recheck+';'))
                result = subprocess.run([php, str(script)], cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                        timeout=5, check=False)
                self.assertEqual(result.returncode, 0 if scenario == 'valid' else 1)
                self.assertEqual(result.stdout, b'{"automatic_ready":true}' if scenario == 'valid' else b'')
                self.assertEqual(result.stderr, b'')


if __name__ == '__main__': unittest.main()
