#!/usr/bin/env python3
"""Exercise projection-only systemd lifecycle without root/systemd changes."""
import hashlib
import os
from pathlib import Path
import stat
import tempfile
import types
import unittest
from unittest import mock

HELPER = Path(__file__).resolve().parents[1] / 'deployment/install_whatsapp_capture_root.sh'


def load_helper():
    script = HELPER.read_text()
    code = script.split("<<'PY'\n", 1)[1].rsplit('\nPY', 1)[0]
    module = types.ModuleType('capture_helper')
    exec(compile(code, str(HELPER), 'exec'), module.__dict__)
    return module


class Runner:
    def __init__(self, helper):
        self.helper = helper
        self.calls = []
        self.fail = None
        self.states = {name: ('inactive', 'disabled') for name in
                       (helper.ORDER_TIMER, helper.ORDER_SERVICE, helper.TIMER, helper.SERVICE)}
        self.preflight = b'{"capture_ready":true}'
        self.show_error = False

    def __call__(self, args, capture=False):
        self.calls.append((args, capture))
        if self.fail and self.fail(args):
            return 1, b''
        if args[0] == '/usr/sbin/runuser':
            return 0, self.preflight
        if len(args) > 1 and args[1] == 'show':
            if self.show_error:
                return 1, b'UNKNOWN_STATE'
            active, enabled = self.states[args[2]]
            return 0, ('UnitFileState=' + enabled + '\nLoadState=loaded\nActiveState=' + active + '\n').encode()
        if args[1:3] == ['enable', '--now']:
            self.states[args[-1]] = ('active', 'enabled')
        if args[1:3] == ['disable', '--now']:
            self.states[args[-1]] = ('inactive', 'disabled')
        if args[1] == 'stop':
            self.states[args[-1]] = ('inactive', self.states[args[-1]][1])
        return 0, b''


class CaptureRootTest(unittest.TestCase):
    def setUp(self):
        self.h = load_helper()
        self.temp = tempfile.TemporaryDirectory(prefix='capture-root-test-')
        self.base = Path(self.temp.name)
        self.h.ROOT = self.base / 'application'
        self.h.ROOT.mkdir()
        self.h.UNIT_DIRECTORY = self.base / 'units'
        self.h.UNIT_DIRECTORY.mkdir()
        self.h.BACKUP_DIRECTORY = self.base / 'private-backups'
        self.h.SYSTEM_UID = os.geteuid()
        # Fixture paths are inside /tmp, which deliberately permits writes. Keep
        # all checks below the private fixture boundary; no host unit is touched.
        def fixture_path(path, directory=False):
            self.assertIn(self.base, path.parents)
            for parent in path.parents:
                if parent == self.base.parent:
                    break
                info = parent.lstat()
                if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) & 0o022:
                    raise self.h.SafeError('SYSTEMD_PARENT_OWNER_OR_TYPE_NEEDS_REVIEW')
            info = path.lstat()
            if (info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o022
                    or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
                raise self.h.SafeError('SYSTEMD_PATH_OWNER_OR_TYPE_NEEDS_REVIEW')
            return info
        self.h.system_path = fixture_path
        self.uid_patch = mock.patch.object(self.h.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid()))
        self.uid_patch.start()
        self.runner = Runner(self.h)

    def tearDown(self):
        self.uid_patch.stop()
        self.temp.cleanup()

    def install(self):
        return self.h.install(self.runner)

    def sources(self):
        pins = {}
        for relative in self.h.SOURCE_PINS:
            path = self.h.ROOT / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            body = ('<?php // fixture for ' + relative + '\n').encode()
            path.write_bytes(body)
            path.chmod(0o644)
            pins[relative] = hashlib.sha1(b'blob ' + str(len(body)).encode() + b'\0' + body).hexdigest()
        self.h.SOURCE_PINS = pins

    def mutation_calls(self):
        return [args for args, _ in self.runner.calls if args[0] == '/usr/bin/systemctl'
                and args[1] in ('enable', 'disable', 'start', 'stop')]

    def test_service_is_projection_only_shared_lock_and_quarantine_success(self):
        unit = self.h.SERVICE_BYTES.decode()
        execution = [line for line in unit.splitlines() if line.startswith('ExecStart=')]
        self.assertEqual(len(execution), 1)
        self.assertTrue(execution[0].endswith('artisan whatsapp:consume-inbox --limit=500'))
        self.assertIn('/run/fasakhansta-whatsapp-orders/pipeline.lock', execution[0])
        self.assertNotIn('--retry-quarantined', unit)
        self.assertNotIn('whatsapp:process-orders', unit)
        self.assertIn('RuntimeDirectoryPreserve=yes', unit)
        self.assertIn('SuccessExitStatus=2', unit)
        self.assertIn('User=fasakha\nGroup=fasakha\nUMask=0077', unit)
        self.assertIn('OnUnitInactiveSec=10s', self.h.TIMER_BYTES.decode())

    def test_every_invocation_source_pins_match_git_source_guard(self):
        self.assertEqual(self.h.SOURCE_PINS['app/Services/Dashboard/WhatsAppInboxConsumer.php'],
                         'f993ff1eebc9c2a7be3b0bec93ab02732b96a46d')
        self.assertEqual(self.h.SOURCE_PINS['app/Console/Commands/ConsumeWhatsAppInbox.php'],
                         'dbd621a5117f2e92fd2f80d004305fcbcc467d4a')
        before = next(line for line in self.h.SERVICE_BYTES.decode().splitlines() if line.startswith('ExecStartPre='))
        self.assertIn('80253a13accf7a712130d99d73322e9d01ca73cd6f83c68db6cf5f0cd282948c', before)
        self.assertIn('b942e8830acfc3fb9d291c1d953f0a80a6b4132027a945fb6656d3e2f589c9f6', before)
        self.assertNotIn('$', before)

    def test_install_never_starts_and_is_idempotent(self):
        status, backup = self.install()
        self.assertEqual(status, 'WHATSAPP_CAPTURE_TIMER_INSTALLED_NOT_STARTED')
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        self.assertEqual(self.mutation_calls(), [])
        status, backup = self.install()
        self.assertEqual(status, 'WHATSAPP_CAPTURE_TIMER_ALREADY_INSTALLED')
        self.assertIsNone(backup)
        self.h.check_units()

    def test_install_under_private_umask(self):
        old = os.umask(0o077)
        try:
            self.install()
        finally:
            os.umask(old)
        self.h.check_units()

    def test_unrelated_existing_unit_retained(self):
        path = self.h.UNIT_DIRECTORY / self.h.SERVICE
        path.write_bytes(b'[Service]\nExecStart=unrelated\n')
        with self.assertRaisesRegex(self.h.SafeError, 'UNRELATED_UNIT_RETAINED'):
            self.install()
        self.assertEqual(path.read_bytes(), b'[Service]\nExecStart=unrelated\n')
        self.assertFalse((self.h.UNIT_DIRECTORY / self.h.TIMER).exists())
        self.assertEqual(self.runner.calls, [])

    def test_reject_symlink_hardlink_and_writable_unit(self):
        path = self.h.UNIT_DIRECTORY / self.h.SERVICE
        outside = self.base / 'outside'; outside.write_bytes(b'keep')
        path.symlink_to(outside)
        with self.assertRaises(self.h.SafeError): self.install()
        path.unlink(); os.link(outside, path)
        with self.assertRaises(self.h.SafeError): self.install()
        path.unlink(); path.write_bytes(self.h.SERVICE_BYTES); path.chmod(0o666)
        with self.assertRaises(self.h.SafeError): self.install()
        self.assertEqual(outside.read_bytes(), b'keep')

    def test_verify_failure_does_not_publish(self):
        self.runner.fail = lambda args: args[0] == '/usr/bin/systemd-analyze'
        with self.assertRaisesRegex(self.h.SafeError, 'VALIDATION_FAILED'): self.install()
        self.assertEqual(list(self.h.UNIT_DIRECTORY.iterdir()), [])

    def test_reload_failure_rolls_back_only_new_units(self):
        self.runner.fail = lambda args: args[1:2] == ['daemon-reload']
        with self.assertRaisesRegex(self.h.SafeError, 'NEW_UNITS_RESTORED'): self.install()
        self.assertEqual(list(self.h.UNIT_DIRECTORY.iterdir()), [])
        retained = list(self.h.BACKUP_DIRECTORY.glob('capture-*/retained-*'))
        self.assertEqual(len(retained), 2)

    def test_concurrent_unit_replacement_is_retained(self):
        original = self.runner.__call__
        def racing(args, capture=False):
            if args[1:2] == ['daemon-reload']:
                path = self.h.UNIT_DIRECTORY / self.h.SERVICE
                path.unlink(); path.write_bytes(b'concurrent different unit')
                return 1, b''
            return original(args, capture)
        with self.assertRaisesRegex(self.h.SafeError, 'CONCURRENT_UNIT_RETAINED'):
            self.h.install(racing)
        self.assertEqual((self.h.UNIT_DIRECTORY / self.h.SERVICE).read_bytes(), b'concurrent different unit')

    def test_valid_start_enables_only_capture_and_primes_projection(self):
        self.install(); self.sources()
        result = self.h.start(self.runner)
        self.assertEqual(result, 'WHATSAPP_CAPTURE_ONLY_TIMER_ACTIVE')
        self.assertEqual(self.mutation_calls(), [
            ['/usr/bin/systemctl', 'enable', '--now', self.h.TIMER],
            ['/usr/bin/systemctl', 'start', '--no-block', self.h.SERVICE]])

    def test_order_worker_active_or_timer_enabled_refuses_before_start(self):
        self.install(); self.sources()
        for name, state in [(self.h.ORDER_SERVICE, ('active', 'static')),
                            (self.h.ORDER_TIMER, ('inactive', 'enabled')),
                            (self.h.ORDER_TIMER, ('activating', 'disabled'))]:
            with self.subTest(name=name, state=state):
                self.runner.states[name] = state
                with self.assertRaises(self.h.SafeError): self.h.start(self.runner)
                self.assertEqual(self.mutation_calls(), [])
                self.runner.states[name] = ('inactive', 'disabled')

    def test_bad_preflight_or_unknown_systemd_state_never_enables(self):
        self.install(); self.sources()
        for bad in (b'{"capture_ready":false}', b'{"automatic_ready":true}', b'invalid json'):
            self.runner.preflight = bad
            with self.assertRaisesRegex(self.h.SafeError, 'CONFIGURATION_NOT_READY'): self.h.start(self.runner)
        self.runner.show_error = True
        with self.assertRaisesRegex(self.h.SafeError, 'STATE_CHECK_FAILED'): self.h.start(self.runner)
        self.assertEqual(self.mutation_calls(), [])

    def test_changed_or_unsafe_source_never_enables(self):
        self.install(); self.sources()
        path = self.h.ROOT / next(iter(self.h.SOURCE_PINS))
        old = path.read_bytes(); path.write_bytes(b'changed')
        with self.assertRaisesRegex(self.h.SafeError, 'SOURCE_PIN'): self.h.start(self.runner)
        path.write_bytes(old); path.chmod(0o666)
        with self.assertRaisesRegex(self.h.SafeError, 'SOURCE_OWNER'): self.h.start(self.runner)
        path.unlink(); path.symlink_to(self.base / 'missing')
        with self.assertRaisesRegex(self.h.SafeError, 'SOURCE_PATH'): self.h.start(self.runner)
        self.assertEqual(self.mutation_calls(), [])

    def test_start_failure_disables_capture_without_touching_orders(self):
        self.install(); self.sources()
        self.runner.fail = lambda args: args[1:3] == ['start', '--no-block']
        with self.assertRaisesRegex(self.h.SafeError, 'FIRST_PROJECTION_START'): self.h.start(self.runner)
        self.assertIn(['/usr/bin/systemctl', 'disable', '--now', self.h.TIMER], self.mutation_calls())
        self.assertIn(['/usr/bin/systemctl', 'stop', self.h.SERVICE], self.mutation_calls())
        for args in self.mutation_calls():
            self.assertNotIn(self.h.ORDER_TIMER, args)
            self.assertNotIn(self.h.ORDER_SERVICE, args)

    def test_stop_disables_reboot_restart_and_requires_confirmed_state(self):
        self.install()
        self.runner.states[self.h.TIMER] = ('active', 'enabled')
        self.runner.states[self.h.SERVICE] = ('active', 'static')
        self.assertEqual(self.h.stop(self.runner), 'WHATSAPP_CAPTURE_TIMER_AND_WORKER_STOPPED')
        self.assertEqual(self.runner.states[self.h.TIMER], ('inactive', 'disabled'))
        self.runner.show_error = True
        with self.assertRaisesRegex(self.h.SafeError, 'STATE_CHECK_FAILED'): self.h.stop(self.runner)


if __name__ == '__main__':
    unittest.main(verbosity=2)
