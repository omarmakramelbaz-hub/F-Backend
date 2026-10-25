"""Exercise backup completion, interruption and privacy without cPanel/network access."""
from contextlib import redirect_stderr, redirect_stdout
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('fresh_full_backup_driver_test',
    ROOT / 'deployment/create_fresh_full_backup_root.py')
driver = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(driver)


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


class DriverTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='fresh-backup-driver-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.base = self.root / 'full-backups'
        self.base.mkdir(mode=0o700)
        self.old = self.base / '20261020T000547Z-current-existing'
        self.old.mkdir()
        (self.old / 'home-current.tar.gz').write_bytes(b'old complete archive must survive')
        (self.old / 'backup.ok').write_text('VERIFIED\n')
        self.home = self.root / 'home'
        self.home.mkdir()
        self.point = self.home / 'desktop-dashboard-release-backups'
        self.point.mkdir()
        (self.point / 'code.tar').write_bytes(b'point recovery copy must survive')
        self.before = self.old_and_point_inventory()
        self.package = self.root / 'package'
        self.package.mkdir()
        for name in ['create_fresh_full_backup_root.py'] + list(driver.PINS):
            path = self.package / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('# synthetic pinned helper fixture\n')
        self.databases = {'databases': ['fasakha_db', 'fasakha_dev_db']}
        self.inventory = {'.': {'type': 'directory'}}
        self.runtime_root = self.root / 'go-erp-state'
        self.runtime_root.mkdir()

    def old_and_point_inventory(self):
        return {str(path): path.read_bytes() for root in (self.old, self.point)
                for path in root.rglob('*') if path.is_file()}

    def new_destination(self, name='new'):
        destination = self.base / name
        destination.mkdir(mode=0o700)
        (destination / 'payload.bin').write_bytes(b'new private backup bytes')
        return destination

    def checksum_core(self, destination, failure=False):
        def run(args, **kwargs):
            self.assertFalse((destination / 'backup.ok').exists())
            self.assertEqual(args, ['sha256sum', '--check', '--quiet', 'SHA256SUMS'])
            self.assertEqual(kwargs.get('cwd'), str(destination))
            if failure:
                raise driver.Stop('SYNTHETIC_CHECKSUM_FAILURE')
            for row in (destination / 'SHA256SUMS').read_text().splitlines():
                expected, relative = row.split('  ', 1)
                self.assertEqual(digest(destination / relative), expected)
            return ''
        return types.SimpleNamespace(sha256=digest, run=run)

    def test_seal_writes_completion_only_after_checksum_and_final_guard(self):
        destination = self.new_destination()
        events = []
        core = self.checksum_core(destination)
        run = core.run
        def checked(*args, **kwargs):
            events.append('checksums')
            return run(*args, **kwargs)
        core.run = checked
        def final_guard():
            self.assertFalse((destination / 'backup.ok').exists())
            self.assertTrue((destination / 'SHA256SUMS').is_file())
            events.append('guard')
        driver.seal(destination, {'kind': 'test'}, core, final_guard)
        self.assertEqual(events, ['checksums', 'guard'])
        self.assertIn('FRESH_FULL_BACKUP_VERIFIED', (destination / 'backup.ok').read_text())
        notes = (destination / 'RESTORE-NOTES.txt').read_text()
        self.assertIn('not an atomic VPS/disk image', notes)
        self.assertIn('No earlier full/partial backup is deleted', notes)
        self.assertEqual(self.old_and_point_inventory(), self.before)

    def test_checksum_failure_never_marks_backup_complete_or_changes_old_copies(self):
        destination = self.new_destination()
        guard = mock.Mock()
        with self.assertRaisesRegex(driver.Stop, 'SYNTHETIC_CHECKSUM_FAILURE'):
            driver.seal(destination, {'kind': 'test'}, self.checksum_core(destination, True), guard)
        guard.assert_not_called()
        self.assertFalse((destination / 'backup.ok').exists())
        self.assertEqual(self.old_and_point_inventory(), self.before)

    def test_final_guard_failure_never_marks_backup_complete(self):
        destination = self.new_destination()
        def changed_lock():
            raise driver.Stop('LOCK_CHANGED')
        with self.assertRaisesRegex(driver.Stop, 'LOCK_CHANGED'):
            driver.seal(destination, {'kind': 'test'}, self.checksum_core(destination), changed_lock)
        self.assertFalse((destination / 'backup.ok').exists())
        self.assertEqual(self.old_and_point_inventory(), self.before)

    @unittest.skipUnless(os.geteuid() == 0, 'helper ownership checks require root')
    def test_helper_pin_mismatch_fails_without_importing_or_pruning(self):
        helper = self.package / 'pinned.py'
        helper.write_text('raise RuntimeError("must not execute")\n')
        helper.chmod(0o600)
        with self.assertRaisesRegex(driver.Stop, 'HELPER_PIN_MISMATCH'):
            driver.read_pinned(helper, '0' * 64)
        self.assertEqual(self.old_and_point_inventory(), self.before)

    @unittest.skipUnless(os.geteuid() == 0, 'helper ownership checks require root')
    def test_helper_symlink_is_rejected(self):
        helper = self.package / 'pinned.py'
        helper.symlink_to(self.old / 'home-current.tar.gz')
        with self.assertRaisesRegex(driver.Stop, 'HELPER_UNSAFE'):
            driver.read_pinned(helper, digest(self.old / 'home-current.tar.gz'))
        self.assertEqual(self.old_and_point_inventory(), self.before)

    def fixtures(self, destination, fail=None):
        events = []
        def phase(name, result):
            def call(*args, **kwargs):
                events.append(name)
                self.assertFalse((destination / 'backup.ok').exists())
                if fail == name:
                    raise driver.Stop('SYNTHETIC_' + name.upper())
                return result(*args, **kwargs) if callable(result) else result
            return call
        account = destination / 'account/cpmove-fasakha.tar.gz'
        home = destination / 'home-current.tar.gz'
        def package(_):
            account.write_bytes(b'synthetic account collector bytes')
            return account
        def mirrors(path, logs):
            path.mkdir()
            (path / 'source.refs').write_text('ref fixture\n')
            return {'repositories': ['fixture']}
        def stream(_, candidates):
            self.assertEqual(candidates, [])
            home.write_bytes(b'synthetic home collector bytes')
            return home, []
        def config(_):
            (destination / 'server-config.tar.gz').write_bytes(b'config fixture')
            return ['etc/systemd/system']
        def runtime_backup(_, core):
            (destination / 'server-runtime.tar.gz').write_bytes(b'runtime fixture')
            return {'roots': [str(self.runtime_root)]}
        core = self.checksum_core(destination, fail == 'checksum')
        core.HOME, core.ACCOUNT = self.home, 'fasakha'
        core.copy_sources = phase('sources', mirrors)
        core.copy_system_config = phase('config', config)
        split = types.SimpleNamespace(
            package_account=phase('account', package),
            verify_sql_package=phase('sql', {'fasakha_db': {'completion_footer': True}}),
            stream_home=phase('home', stream),
            verify_home=phase('home_verify', {'inventory_entries_verified': 1}),
            database_inventory_from_map=phase('database_recheck', self.databases),
            RUNTIME_DIRS=('public_html/storage/logs',))
        runtime = types.SimpleNamespace(backup=phase('runtime', runtime_backup))
        progress = types.SimpleNamespace(set=lambda stage: None)
        return core, split, runtime, progress, events

    def test_collection_failure_at_every_phase_preserves_old_and_denies_marker(self):
        phases = ('account', 'sql', 'sources', 'home', 'home_verify', 'config',
                  'runtime', 'database_recheck', 'checksum')
        for phase in phases:
            with self.subTest(phase=phase):
                destination = self.new_destination('failed-' + phase)
                core, split, runtime, progress, events = self.fixtures(destination, phase)
                with mock.patch.object(driver, 'PACKAGE', self.package):
                    with self.assertRaises(driver.Stop):
                        driver.collect(destination, core, split, runtime, progress,
                                       self.databases, self.inventory, [self.runtime_root], {})
                self.assertFalse((destination / 'backup.ok').exists())
                self.assertEqual(self.old_and_point_inventory(), self.before)

    def test_collection_success_preserves_prior_copies_and_declares_coverage(self):
        destination = self.new_destination()
        core, split, runtime, progress, events = self.fixtures(destination)
        guard = mock.Mock()
        with mock.patch.object(driver, 'PACKAGE', self.package):
            manifest = driver.collect(destination, core, split, runtime, progress,
                                      self.databases, self.inventory, [self.runtime_root], {}, guard)
        guard.assert_called_once_with()
        self.assertTrue((destination / 'backup.ok').is_file())
        self.assertTrue(manifest['point_backups_included'])
        self.assertFalse(manifest['earlier_backups_deleted'])
        self.assertTrue(manifest['online_capture_window'])
        self.assertFalse(manifest['restore_rehearsal'])
        self.assertEqual(self.old_and_point_inventory(), self.before)

    def test_uncovered_operational_storage_link_fails_without_dereferencing_contents(self):
        outside = self.root / 'outside-storage'
        outside.mkdir()
        secret = outside / 'customer.dat'
        secret.write_bytes(b'private external data')
        public = self.home / 'public_html'
        public.mkdir()
        (public / 'storage').symlink_to(outside, target_is_directory=True)
        inventory = {'public_html/storage': {'type': 'symlink', 'target': str(outside)}}
        with self.assertRaisesRegex(driver.Stop, 'EXTERNAL_APPLICATION_STORAGE_NOT_COVERED'):
            driver.external_home_links(inventory, self.home, [])
        self.assertEqual(secret.read_bytes(), b'private external data')
        result = driver.external_home_links(inventory, self.home, [outside])
        self.assertTrue(result[0]['server_runtime_covered'])

    def test_busy_update_lock_prevents_any_collector_or_destination_creation(self):
        calls = []
        def lock_file(path, stack):
            calls.append(path)
            if len(calls) == 2:
                raise BlockingIOError('updater currently owns this lock')
            return object()
        core = types.SimpleNamespace(HOME=self.home, PKGACCT='/usr/local/cpanel/scripts/pkgacct')
        locks = types.SimpleNamespace(lock_file=lock_file)
        real_is_file = Path.is_file
        def is_file(path):
            return True if path == Path('/var/cpanel/users/fasakha') else real_is_file(path)
        before_children = set(self.base.iterdir())
        with mock.patch.object(driver, 'BASE', self.base), \
             mock.patch.object(driver, 'load_dependencies', return_value=(core, object(), object(), locks)), \
             mock.patch.object(driver.socket, 'gethostname', return_value='srv1836524'), \
             mock.patch.object(driver.os, 'geteuid', return_value=0), \
             mock.patch.object(driver.os, 'chdir'), \
             mock.patch.object(driver.shutil, 'which', return_value='/usr/bin/available'), \
             mock.patch.object(Path, 'is_file', is_file), \
             mock.patch.object(sys, 'argv', ['backup', '--complete']), \
             mock.patch.object(driver, 'collect') as collect:
            with self.assertRaises(BlockingIOError):
                driver.main()
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0].parent, self.base)
        collect.assert_not_called()
        self.assertEqual(set(self.base.iterdir()), before_children)
        self.assertEqual(self.old_and_point_inventory(), self.before)

    def test_failed_command_does_not_disclose_captured_child_output(self):
        secret = 'DATABASE_PASSWORD_SHOULD_STAY_PRIVATE'
        command = [sys.executable, '-c',
                   'import sys; print("' + secret + '"); print("' + secret + '",file=sys.stderr);sys.exit(2)']
        output, errors = io.StringIO(), io.StringIO()
        usage = types.SimpleNamespace(free=driver.RESERVE + 1024**3)
        with mock.patch.object(driver.shutil, 'disk_usage', return_value=usage), \
             redirect_stdout(output), redirect_stderr(errors):
            with self.assertRaisesRegex(driver.Stop, 'BACKUP_COMMAND_FAILED_OUTPUT_PRIVATE') as raised:
                driver.monitored_run(self.base)(command)
        self.assertNotIn(secret, str(raised.exception) + output.getvalue() + errors.getvalue())

    def test_low_disk_stops_only_owned_process_group(self):
        unrelated = subprocess.Popen([sys.executable, '-c', 'import time;time.sleep(10)'],
                                     start_new_session=True, stdout=subprocess.DEVNULL,
                                     stderr=subprocess.DEVNULL)
        self.addCleanup(lambda: unrelated.poll() is None and unrelated.terminate())
        launched, signalled = [], []
        real_popen, real_killpg = subprocess.Popen, os.killpg
        def launch(*args, **kwargs):
            process = real_popen(*args, **kwargs)
            launched.append(process)
            return process
        def killpg(group, sig):
            signalled.append(group)
            return real_killpg(group, sig)
        usage = types.SimpleNamespace(free=driver.RESERVE - 1)
        try:
            with mock.patch.object(driver.subprocess, 'Popen', side_effect=launch), \
                 mock.patch.object(driver.shutil, 'disk_usage', return_value=usage), \
                 mock.patch.object(driver.os, 'killpg', side_effect=killpg):
                with self.assertRaisesRegex(driver.Stop, 'OPERATING_SPACE_RESERVE_REACHED'):
                    driver.monitored_run(self.base)([sys.executable, '-c', 'import time;time.sleep(10)'])
            self.assertEqual(len(launched), 1)
            self.assertEqual(signalled, [launched[0].pid])
            self.assertNotEqual(launched[0].pid, os.getpgrp())
            self.assertIsNotNone(launched[0].poll())
            self.assertIsNone(unrelated.poll())
        finally:
            if unrelated.poll() is None:
                unrelated.terminate()
            unrelated.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
