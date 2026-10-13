#!/usr/bin/env python3
"""Pinned Cairo activation ordering, timer recovery, staging and UTC cutover guards."""
import contextlib
import datetime
import importlib.util
import io
import json
import os
import pathlib
import shutil
import signal
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).absolute().parents[1]
TEXT = (ROOT / 'deployment/enable_whatsapp_cairo_automatic_root.sh').read_text()
MARKER = 'exec /usr/bin/python3 - "$@" <<\'PY\'\n'
assert TEXT.count(MARKER) == 1 and TEXT.endswith('\nPY\n')
NS = {'__name__': 'cairo_enable_fixture'}
exec(compile(TEXT.split(MARKER, 1)[1][:-len('\nPY\n')], 'cairo-wrapper', 'exec'), NS)
LEGACY = NS['legacy_program']((ROOT / 'deployment/enable_whatsapp_automatic_root.sh').read_text())
SPEC = importlib.util.spec_from_file_location('cairo_publication_test_support', ROOT / 'deployment/apply_whatsapp_cairo_origins.py')
APP = importlib.util.module_from_spec(SPEC); sys.modules[SPEC.name] = APP; SPEC.loader.exec_module(APP)


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned payload fixtures require root')
class CairoEnableTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.parent = pathlib.Path(self.temp.name); self.parent.chmod(0o755)
        self.root = self.parent / 'public_html'; self.root.mkdir()
        self.payload = self.parent / 'payload'; self.payload.mkdir(mode=0o755)
        self.targets = {}; self.originals = {}; source_pins = {}
        for index, relative in enumerate(NS['TARGET_PINS']):
            old = None if relative == APP.ORIGIN_PATH else ('fixture-old-' + str(index)).encode()
            new = ('fixture-target-' + str(index)).encode()
            self.originals[relative] = old; self.targets[relative] = new
            source_pins[relative] = (NS['blob'](old) if old else None, NS['blob'](new))
            if old is not None: self.write(self.root / relative, old)
            self.write(self.payload / relative, new)
        self.protected = {}
        for relative in dict.fromkeys((*APP.PROTECTED_PATHS, *APP.PROTECTED_BLOBS, *LEGACY['CAPTURE_PINS'])):
            if relative in self.targets: continue
            data = ('protected-cutoffs-mappings-and-feed:' + relative).encode()
            self.write(self.root / relative, data, 0o600 if relative == '.env' else 0o644)
            self.protected[relative] = data
        dashboard_pins = {}
        for relative in NS['DASHBOARD_PINS']:
            if relative not in self.protected:
                data = ('protected-dashboard-upgrade:' + relative).encode()
                self.write(self.root / relative, data); self.protected[relative] = data
            dashboard_pins[relative] = NS['blob'](self.protected[relative])
        patcher = mock.patch.dict(NS['DASHBOARD_PINS'], dashboard_pins, clear=True); patcher.start(); self.addCleanup(patcher.stop)
        helper_pins = {}
        for name in NS['HELPER_PINS']:
            data = (ROOT / 'deployment' / name).read_bytes()
            self.write(self.payload / 'deployment' / name, data)
            helper_pins[name] = NS['blob'](data)
        for table, pins in ((NS['TARGET_PINS'], {r: NS['blob'](v) for r, v in self.targets.items()}),
                            (NS['HELPER_PINS'], helper_pins),
                            (NS['EXTRA_PINS'], {r: helper_pins[r] for r in NS['EXTRA_PINS']})):
            patcher = mock.patch.dict(table, pins, clear=True); patcher.start(); self.addCleanup(patcher.stop)
        self.legacy = dict(LEGACY)
        self.legacy['SOURCE_PINS'] = {r: NS['blob'](self.protected[r]) for r in (
            *LEGACY['CAPTURE_PINS'], LEGACY['CART_PATH'], 'config/whatsapp_orders.php')}
        self.legacy['CAPTURE_PINS'] = {r: self.legacy['SOURCE_PINS'][r] for r in LEGACY['CAPTURE_PINS']}
        # guard_sources has a definition-time default; every wrapper call supplies an explicit profile.
        self.app = types.SimpleNamespace(
            SOURCE_PINS=source_pins, PROTECTED_PATHS=APP.PROTECTED_PATHS, SafeError=APP.SafeError,
            PROTECTED_BLOBS={r: NS['blob'](self.protected[r]) for r in APP.PROTECTED_BLOBS},
            LEGACY_READ_ONLY_MODELS=APP.LEGACY_READ_ONLY_MODELS,
            QUIET_UNITS=APP.QUIET_UNITS,
            guarded_snapshot=APP.guarded_snapshot, blob=APP.blob,
            assert_workers_quiet=APP.assert_workers_quiet)
        self.mode = 'review'; self.fingerprint = 'a' * 64; self.fault = None; self.log = []; self.proof_count = 0
        self.states = {
            LEGACY['CAPTURE_TIMER']: dict(LoadState='loaded', ActiveState='active', UnitFileState='enabled', MainPID='0'),
            LEGACY['CAPTURE_SERVICE']: dict(LoadState='loaded', ActiveState='inactive', UnitFileState='static', MainPID='0'),
            LEGACY['ORDER_TIMER']: dict(LoadState='loaded', ActiveState='inactive', UnitFileState='disabled', MainPID='0'),
            LEGACY['ORDER_SERVICE']: dict(LoadState='loaded', ActiveState='inactive', UnitFileState='static', MainPID='0'),
        }
        self.app_backup = '/home/fasakha/whatsapp-release-backups/cairo-origins-fixture'
        self.config_backup = '/home/fasakha/whatsapp-release-backups/automatic-activation-fixture'

    def write(self, path, data, mode=0o644):
        path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(data); path.chmod(mode)

    def validate(self, kind):
        self.log.append(('validate', kind))
        if self.fault == 'unit': raise NS['SafeError']('CAIRO_UNIT_DRIFT')

    def runner(self, args, timeout=60):
        self.log.append(tuple(args))
        if args[0] == NS['SYSTEMCTL']:
            op = args[1]
            if op == 'show':
                name = next(a for a in args if a in self.states)
                fields = [a.split('=', 1)[1] for a in args if a.startswith('--property=')]
                return 0, ('\n'.join(k + '=' + self.states[name][k] for k in fields) + '\n').encode()
            if self.fault == 'handoff' and op == 'disable' and LEGACY['CAPTURE_TIMER'] in args: return 1, b'private-child-error'
            for name in args[2:]:
                if name == '--runtime': continue
                if op in ('stop', 'start'): self.states[name]['ActiveState'] = 'inactive' if op == 'stop' else 'active'
                if op in ('enable', 'disable'): self.states[name]['UnitFileState'] = 'enabled' if op == 'enable' else 'disabled'
            return 0, b''
        if args[0] == '/usr/bin/bash':
            flag = args[2]
            if flag == '--stop':
                for name in (LEGACY['ORDER_TIMER'], LEGACY['ORDER_SERVICE']): self.states[name]['ActiveState'] = 'inactive'
            if flag == '--start':
                self.states[LEGACY['ORDER_TIMER']]['UnitFileState'] = 'enabled'
                if self.fault == 'start': return 1, b'private-child-error'
                self.states[LEGACY['ORDER_TIMER']]['ActiveState'] = 'active'
            return 0, b'WHATSAPP_UNIT_ACTION_OK\n'
        if args[0] == NS['RUNUSER'] and args[4] == NS['PHP']:
            if '-r' in args:
                if args[6] == NS['NATIVE_PROOF_PHP']:
                    self.proof_count += 1
                    if self.fault == 'proof_before' or (self.fault == 'proof_after' and self.proof_count > 1): return 1, b'private-proof-error'
                    return 0, json.dumps({'status': 'NATIVE_BUSINESS_PROJECTION_READY', 'source': 'standby', 'peer_binding': 'EXPLICIT_USER_AND_PHONE',
                        'event_id': 1, 'message_id': 2, 'inbound_message_id': 3, 'conversation_id': 4}).encode()
                self.assertEqual(args[6], NS['MODE_PHP'])
                return 0, json.dumps({'mode': self.mode, 'fingerprint': self.fingerprint}).encode()
            self.assertEqual(timeout, 240)
            if self.fault == 'ai': return 1, b'{"status":"AI_CONNECTION_FAILED","ai_calls":1}'
            return 0, b'{"status":"AI_CONNECTION_READY","ai_calls":1}'
        if any(pathlib.Path(str(arg)).name == NS['APP_NAME'] for arg in args):
            self.assertEqual(timeout, 300)
            if self.fault == 'foreign_legacy':
                self.fault = None
                raise self.legacy['SafeError']('UNTRUSTED_PRIVATE_CHILD_RESPONSE_MUST_NOT_APPEAR')
            if self.fault == 'app_status': return 1, b'GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW\n'
            if self.fault == 'app': return 1, b'private-child-error'
            for relative, data in self.targets.items(): self.write(self.root / relative, data)
            if self.fault == 'protected': self.write(self.root / 'public/js/dashboard-whatsapp-inbox.js', b'concurrent-feed-edit')
            if self.fault == 'cart_auto': self.write(self.root / LEGACY['CART_PATH'], b'concurrent-cart-edit')
            return 0, ('WHATSAPP_CAIRO_DELIVERY_ORIGINS_READY\nBACKUP=' + self.app_backup + '\n').encode()
        if args[0] == NS['RUNUSER'] and args[4] == '/usr/bin/env':
            self.assertEqual(timeout, 300)
            if self.fault == 'activation': return 1, ('ACTIVATION_FAILED; BACKUP=' + self.config_backup).encode()
            self.mode = 'auto'; self.fingerprint = 'b' * 64
            for relative in ('.env', 'bootstrap/cache/config.php', LEGACY['CART_PATH']): self.write(self.root / relative, b'authorized-new-cutover')
            return 0, ('WHATSAPP_AUTOMATIC_CONFIGURED_TIMER_START_REQUIRED\nBACKUP=' + self.config_backup + '\n').encode()
        raise AssertionError('Unexpected command: ' + repr(args))

    def run_wrapper(self, enable=True):
        self.output = io.StringIO()
        with contextlib.redirect_stdout(self.output):
            return NS['orchestrate'](self.payload, 0, self.runner, self.validate, self.root, self.legacy, self.app, enable=enable)

    def assert_capture_available(self):
        self.assertEqual(self.states[LEGACY['CAPTURE_TIMER']]['ActiveState'], 'active')
        self.assertEqual(self.states[LEGACY['CAPTURE_TIMER']]['UnitFileState'], 'enabled')
        self.assertEqual(self.states[LEGACY['ORDER_TIMER']]['ActiveState'], 'inactive')
        self.assertEqual(self.states[LEGACY['ORDER_TIMER']]['UnitFileState'], 'disabled')

    def automatic_prior_state(self):
        self.mode = 'auto'; self.states[LEGACY['CAPTURE_TIMER']].update(ActiveState='inactive', UnitFileState='disabled')
        self.states[LEGACY['ORDER_TIMER']].update(ActiveState='active', UnitFileState='enabled')

    def actual_unit_fixture(self):
        # Keep real unit safety checks and atomic installer publication. Only the
        # destination is a private root-owned fixture, and systemd commands are inert.
        temporary = tempfile.TemporaryDirectory(prefix='cairo-unit-fixture-', dir='/root'); self.addCleanup(temporary.cleanup)
        parent = pathlib.Path(temporary.name); units = parent / 'units'; units.mkdir(mode=0o755)
        validator = self.legacy['unit_validator'](self.payload / 'deployment')
        programs = next(cell.cell_contents for cell in validator.__closure__
                        if isinstance(cell.cell_contents, dict) and set(cell.cell_contents) == {'capture', 'orders'})
        for kind, program in programs.items():
            program['UNIT_DIRECTORY'] = units; program['BACKUP_DIRECTORY'] = parent / ('backups-' + kind)
        for name, data in programs['capture']['UNITS'].items(): self.write(units / name, data)
        def checked(kind):
            self.log.append(('real_validate', kind)); validator(kind)
        def installed(args, timeout=60):
            if args[0] == '/usr/bin/bash' and args[2] == '--install':
                self.log.append(tuple(args))
                try: status, backup = programs['orders']['install'](runner=lambda _args, capture=False: (0, b''))
                except programs['orders']['SafeError'] as error: return 1, str(error).encode()
                return 0, (status + ('\nBACKUP=' + str(backup) if backup else '') + '\n').encode()
            return self.runner(args, timeout)
        return checked, installed, programs, units

    def test_actual_pinned_unit_validator_allows_absent_orders_only_until_installed(self):
        validate, runner, programs, units = self.actual_unit_fixture()
        self.assertFalse((units / programs['orders']['TIMER']).exists())
        with self.assertRaisesRegex(self.legacy['SafeError'], 'UNIT_BYTES_CHANGED: orders'):
            validate('orders')
        self.log.clear()
        # The orchestrator must validate capture, publish the missing order units,
        # and only then validate those order bytes through the real helper.
        with contextlib.redirect_stdout(io.StringIO()):
            result = NS['orchestrate'](self.payload, 0, runner, validate, self.root, self.legacy, self.app, enable=False)
        self.assertEqual(result[0], 'WHATSAPP_CAIRO_CAPTURE_INSTALLED_REVIEW_ONLY'); self.assert_capture_available()
        for name, data in programs['orders']['UNITS'].items(): self.assertEqual((units / name).read_bytes(), data)
        install_at = next(i for i, a in enumerate(self.log) if a[0] == '/usr/bin/bash' and a[2] == '--install')
        first_order_validation = next(i for i, a in enumerate(self.log) if a == ('real_validate', 'orders'))
        self.assertLess(install_at, first_order_validation)

    def test_actual_capture_unit_drift_refuses_before_install_or_pause(self):
        validate, runner, programs, units = self.actual_unit_fixture()
        self.write(units / programs['capture']['SERVICE'], b'foreign-capture-unit')
        with self.assertRaisesRegex(NS['SafeError'], 'INSTALLED_UNIT_NEEDS_REVIEW: capture'):
            NS['orchestrate'](self.payload, 0, runner, validate, self.root, self.legacy, self.app, enable=False)
        self.assert_capture_available(); self.assertFalse((units / programs['orders']['TIMER']).exists())
        self.assertFalse(any(a[:2] == (NS['SYSTEMCTL'], 'stop') for a in self.log))

    def test_actual_order_installer_respects_required_644_despite_umask077(self):
        validate, runner, programs, units = self.actual_unit_fixture()
        previous = os.umask(0o077)
        try:
            status, backup = programs['orders']['install'](runner=lambda _args, capture=False: (0, b''))
            programs['orders']['write_private'](backup / 'private-probe', b'private fixture')
        finally: os.umask(previous)
        self.assertEqual(status, 'WHATSAPP_AUTOMATIC_TIMER_INSTALLED_NOT_STARTED')
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup / 'private-probe').stat().st_mode & 0o777, 0o600)
        for name, data in programs['orders']['UNITS'].items():
            self.assertEqual((units / name).read_bytes(), data)
            self.assertEqual((units / name).stat().st_mode & 0o777, 0o644)
        validate('orders')

    def test_actual_foreign_order_unit_is_retained_before_pause(self):
        validate, runner, programs, units = self.actual_unit_fixture()
        self.write(units / programs['orders']['SERVICE'], b'foreign-orders-unit')
        with self.assertRaisesRegex(NS['SafeError'], 'ORDER_UNIT_INSTALL_FAILED'):
            NS['orchestrate'](self.payload, 0, runner, validate, self.root, self.legacy, self.app, enable=False)
        self.assert_capture_available(); self.assertEqual((units / programs['orders']['SERVICE']).read_bytes(), b'foreign-orders-unit')
        self.assertFalse(any(a[:2] == (NS['SYSTEMCTL'], 'stop') for a in self.log))

    def main_failure(self, callback):
        output = io.StringIO(); error = io.StringIO(); previous = signal.getsignal(signal.SIGTERM)
        argv = ['cairo-wrapper', '--install-only', '--release', 'a' * 40, '--legacy-helper', str(ROOT / 'deployment/enable_whatsapp_automatic_root.sh')]
        try:
            with mock.patch.object(sys, 'argv', argv), mock.patch.object(NS['pwd'], 'getpwnam', return_value=types.SimpleNamespace(pw_uid=0)), \
                 mock.patch.dict(NS, {'stage_payload': lambda *_: None, 'orchestrate': callback}), \
                 contextlib.redirect_stdout(output), contextlib.redirect_stderr(error):
                code = NS['main']()
        finally: signal.signal(signal.SIGTERM, previous)
        self.assertEqual(code, 1); self.assertEqual(output.getvalue(), '')
        return error.getvalue()

    def test_foreign_app_guard_error_reaches_main_as_only_known_path_and_safe_reason(self):
        def guarded(*args, **kwargs): raise APP.SafeError('UNTRUSTED_PRIVATE_CHILD_RESPONSE_MUST_NOT_APPEAR')
        app = types.SimpleNamespace(guarded_snapshot=guarded, SafeError=APP.SafeError)
        def failed(*args, **kwargs): NS['checked_snapshot'](self.root, 0, app, APP.PHONE_PATH)
        error = self.main_failure(failed)
        self.assertEqual(error, 'CAIRO_SOURCE_PROFILE_FAILED: ' + APP.PHONE_PATH + '; REASON=VERIFIED_SNAPSHOT_GUARD_FAILED\n')
        self.assertNotIn('UNTRUSTED_PRIVATE', error)

    def test_foreign_legacy_guard_error_reaches_main_without_untrusted_message(self):
        legacy = dict(self.legacy)
        def guarded(*args, **kwargs): raise legacy['SafeError']('UNTRUSTED_PRIVATE_CHILD_RESPONSE_MUST_NOT_APPEAR')
        legacy['guard_sources'] = guarded
        def failed(*args, **kwargs): NS['checked_legacy_sources'](self.root, 0, legacy, legacy['CAPTURE_PINS'])
        self.assertEqual(self.main_failure(failed), 'CAIRO_IMMUTABLE_SOURCE_PROFILE_FAILED\n')

    def test_unexpected_main_exception_reports_stage_and_class_without_its_message(self):
        def failed(*args, **kwargs): raise RuntimeError('UNTRUSTED_PRIVATE_CHILD_RESPONSE_MUST_NOT_APPEAR')
        self.assertEqual(self.main_failure(failed), 'CAIRO_ENABLE_STOPPED_WITHOUT_SECRET_OUTPUT; STAGE=PAYLOAD_STAGING; EXCEPTION_CLASS=RuntimeError\n')

    def test_foreign_error_after_pause_is_sanitized_while_capture_recovers(self):
        self.fault = 'foreign_legacy'
        with self.assertRaisesRegex(NS['SafeError'], 'CAIRO_VERIFIED_LEGACY_OPERATION_FAILED.*ORDERS_DISABLED_CAPTURE_ACTIVE') as caught: self.run_wrapper()
        self.assertNotIn('UNTRUSTED_PRIVATE', str(caught.exception)); self.assert_capture_available()

    def test_known_child_failure_is_reported_without_changing_recovery_behavior(self):
        self.fault = 'app_status'
        with self.assertRaisesRegex(NS['SafeError'], 'APP_PUBLICATION_FAILED; APP_STATUS=GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW.*ORDERS_DISABLED_CAPTURE_ACTIVE'): self.run_wrapper(enable=False)
        self.assert_capture_available()

    def test_private_child_failure_becomes_unrecognized_without_leaking_output(self):
        self.fault = 'app'
        with self.assertRaisesRegex(NS['SafeError'], 'APP_PUBLICATION_FAILED; APP_STATUS=UNRECOGNIZED.*ORDERS_DISABLED_CAPTURE_ACTIVE') as caught: self.run_wrapper(enable=False)
        self.assertNotIn('private-child-error', str(caught.exception)); self.assert_capture_available()

    def test_review_orders_ai_pause_app_activation_start_and_boot_handoff(self):
        self.assertEqual(self.run_wrapper(), ('WHATSAPP_CAIRO_AUTOMATIC_ENABLED', self.app_backup, self.config_backup))
        find = lambda predicate: next(i for i, args in enumerate(self.log) if predicate(args))
        ai = find(lambda a: any(str(x).endswith('whatsapp_ai_connection_check.php') for x in a))
        proof = find(lambda a: NS['NATIVE_PROOF_PHP'] in a)
        pause = find(lambda a: a[:2] == (NS['SYSTEMCTL'], 'stop'))
        publish = find(lambda a: any(str(x).endswith(NS['APP_NAME']) for x in a))
        activate = find(lambda a: any(str(x).endswith('activate_whatsapp_automatic.py') for x in a))
        start = find(lambda a: a[0] == '/usr/bin/bash' and a[2] == '--start')
        handoff = find(lambda a: a == (NS['SYSTEMCTL'], 'disable', LEGACY['CAPTURE_TIMER']))
        self.assertLess(proof, ai); self.assertLess(ai, pause); self.assertLess(pause, publish); self.assertLess(publish, activate); self.assertLess(activate, start); self.assertLess(start, handoff)
        proof_calls = [i for i, a in enumerate(self.log) if NS['NATIVE_PROOF_PHP'] in a]
        self.assertEqual(len(proof_calls), 2); self.assertLess(publish, proof_calls[1]); self.assertLess(proof_calls[1], activate)
        pause_args = self.log[pause]
        self.assertEqual(set(pause_args[2:]), set(self.states), 'All four pipeline units are paused together')
        self.assertEqual(self.states[LEGACY['ORDER_TIMER']]['ActiveState'], 'active')

    def test_ai_failure_keeps_prior_pipeline_running_and_never_publishes(self):
        self.fault = 'ai'
        with self.assertRaisesRegex(NS['SafeError'], 'AI_CHECK_FAILED_PRIOR_PIPELINE_REMAINS_RUNNING'): self.run_wrapper()
        self.assert_capture_available()
        self.assertFalse(any(a[:2] == (NS['SYSTEMCTL'], 'stop') for a in self.log))

    def test_missing_native_proof_refuses_before_paid_ai_call_or_pause(self):
        self.fault = 'proof_before'
        with self.assertRaisesRegex(NS['SafeError'], 'NATIVE_BUSINESS_PROJECTION_PROOF_REQUIRED_PRIOR_PIPELINE_REMAINS_RUNNING'): self.run_wrapper()
        self.assert_capture_available()
        self.assertFalse(any(any(str(x).endswith('whatsapp_ai_connection_check.php') for x in a) for a in self.log))
        self.assertFalse(any(a[:2] == (NS['SYSTEMCTL'], 'stop') for a in self.log))

    def test_native_proof_loss_after_app_refuses_activation_and_recovers_capture(self):
        self.fault = 'proof_after'
        with self.assertRaisesRegex(NS['SafeError'], 'NATIVE_BUSINESS_PROJECTION_PROOF_REQUIRED.*ORDERS_DISABLED_CAPTURE_ACTIVE.*VERIFIED_APP_EXTENSION_RETAINED'): self.run_wrapper()
        self.assert_capture_available()
        self.assertFalse(any(any(str(x).endswith('activate_whatsapp_automatic.py') for x in a) for a in self.log))

    def test_unknown_live_source_fails_before_bootstrap_or_paid_call(self):
        self.write(self.root / APP.PHONE_PATH, b'unknown-live-source')
        with self.assertRaisesRegex(NS['SafeError'], 'RUNTIME_SOURCE_NEEDS_REVIEW'): self.run_wrapper()
        self.assertEqual(self.log, [])

    def test_tampered_payload_is_rejected_before_any_command(self):
        self.write(self.payload / APP.PHONE_PATH, b'tampered-stage')
        with self.assertRaisesRegex(NS['SafeError'], 'STAGING_HASH_OR_RACE_FAILED'): self.run_wrapper()
        self.assertEqual(self.log, [])

    def test_unit_drift_refuses_before_paid_ai_check_or_pause(self):
        self.fault = 'unit'
        with self.assertRaisesRegex(NS['SafeError'], 'UNIT_DRIFT'): self.run_wrapper()
        self.assertEqual([a for a in self.log if a[0] == 'validate'], [('validate', 'capture')])
        self.assertFalse(any(any(str(x).endswith('whatsapp_ai_connection_check.php') for x in a) for a in self.log))
        self.assertFalse(any(a[:2] == (NS['SYSTEMCTL'], 'stop') for a in self.log))

    def test_start_failure_retains_verified_app_but_recovers_capture(self):
        self.fault = 'start'
        with self.assertRaisesRegex(NS['SafeError'], 'ORDERS_DISABLED_CAPTURE_ACTIVE.*VERIFIED_APP_EXTENSION_RETAINED'): self.run_wrapper()
        self.assert_capture_available()
        for relative, data in self.targets.items(): self.assertEqual((self.root / relative).read_bytes(), data)
        self.assertNotIn('private-child-error', self.output.getvalue())

    def test_activation_failure_keeps_orders_stopped_and_retains_app_backup(self):
        self.fault = 'activation'
        with self.assertRaisesRegex(NS['SafeError'], 'AUTOMATIC_ACTIVATION_FAILED.*ORDERS_DISABLED_CAPTURE_ACTIVE.*APP_BACKUP=.*VERIFIED_APP_EXTENSION_RETAINED'): self.run_wrapper()
        self.assert_capture_available()
        self.assertEqual(self.mode, 'review')

    def test_failed_capture_boot_handoff_stops_orders_and_restores_capture(self):
        self.fault = 'handoff'
        with self.assertRaisesRegex(NS['SafeError'], 'CAPTURE_BOOT_HANDOFF_FAILED.*ORDERS_DISABLED_CAPTURE_ACTIVE'): self.run_wrapper()
        self.assert_capture_available()

    def test_existing_auto_skips_activation_and_preserves_cutoff_files(self):
        self.automatic_prior_state(); before = {r: (self.root / r).read_bytes() for r in ('.env', 'bootstrap/cache/config.php', LEGACY['CART_PATH'])}
        self.assertEqual(self.run_wrapper(), ('WHATSAPP_CAIRO_AUTOMATIC_ENABLED', self.app_backup, None))
        self.assertFalse(any(any(str(x).endswith('activate_whatsapp_automatic.py') for x in a) for a in self.log))
        for relative, data in before.items(): self.assertEqual((self.root / relative).read_bytes(), data)

    def test_install_only_review_skips_ai_proof_and_activation_and_preserves_config(self):
        before = {r: (self.root / r).read_bytes() for r in self.protected}
        self.assertEqual(self.run_wrapper(enable=False), ('WHATSAPP_CAIRO_CAPTURE_INSTALLED_REVIEW_ONLY', self.app_backup, None))
        self.assert_capture_available(); self.assertEqual(self.proof_count, 0); self.assertEqual(self.mode, 'review')
        self.assertFalse(any(any(str(x).endswith(('whatsapp_ai_connection_check.php', 'activate_whatsapp_automatic.py')) for x in a) for a in self.log))
        for relative, data in before.items(): self.assertEqual((self.root / relative).read_bytes(), data)

    def test_install_only_prior_auto_pauses_automation_without_resetting_cutoffs(self):
        self.automatic_prior_state(); before = {r: (self.root / r).read_bytes() for r in self.protected}
        self.assertEqual(self.run_wrapper(enable=False), ('WHATSAPP_CAIRO_CAPTURE_INSTALLED_AUTOMATION_PAUSED', self.app_backup, None))
        self.assert_capture_available(); self.assertEqual(self.proof_count, 0); self.assertEqual(self.mode, 'auto')
        for relative, data in before.items(): self.assertEqual((self.root / relative).read_bytes(), data)

    def test_auto_failure_with_capture_previously_off_enables_and_starts_fallback(self):
        self.automatic_prior_state(); self.fault = 'start'
        with self.assertRaisesRegex(NS['SafeError'], 'ORDERS_DISABLED_CAPTURE_ACTIVE'): self.run_wrapper()
        self.assert_capture_available()
        self.assertIn((NS['SYSTEMCTL'], 'enable', LEGACY['CAPTURE_TIMER']), self.log)
        self.assertIn((NS['SYSTEMCTL'], 'start', LEGACY['CAPTURE_TIMER']), self.log)

    def test_whole_flow_detects_protected_feed_edit_and_retains_concurrent_bytes(self):
        self.fault = 'protected'
        with self.assertRaisesRegex(NS['SafeError'], 'CAIRO_DASHBOARD_RUNTIME_NEEDS_REVIEW: public/js/dashboard-whatsapp-inbox.js'): self.run_wrapper()
        self.assert_capture_available(); self.assertEqual((self.root / 'public/js/dashboard-whatsapp-inbox.js').read_bytes(), b'concurrent-feed-edit')

    def test_auto_cart_source_edit_cannot_hide_behind_unchanged_effective_cache(self):
        self.automatic_prior_state(); self.fault = 'cart_auto'
        with self.assertRaisesRegex(NS['SafeError'], 'PROTECTED_SOURCE_CHANGED_AFTER_PUBLICATION'): self.run_wrapper()
        self.assert_capture_available(); self.assertEqual((self.root / LEGACY['CART_PATH']).read_bytes(), b'concurrent-cart-edit')

    def test_staging_under_restrictive_umask_keeps_all_nested_root_dirs_traversable(self):
        stage = self.parent / 'nested-stage'; stage.mkdir(mode=0o755)
        originals = {'deployment/' + name: (self.payload / 'deployment' / name).read_bytes() for name in NS['HELPER_PINS']}
        originals.update(self.targets)
        def git(args):
            if 'cat-file' in args: return 0, b'commit\n'
            return 0, originals[args[-1].split(':', 1)[1]]
        old_mask = os.umask(0o077)
        try: NS['stage_payload'](stage, 'a' * 40, git)
        finally: os.umask(old_mask)
        for path in stage.rglob('*'):
            self.assertEqual(path.stat().st_uid, 0)
            self.assertEqual(path.stat().st_mode & 0o777, 0o755 if path.is_dir() else 0o644)
        # Some managed containers prohibit UID changes even for root. The exact public
        # permission bits above remain mandatory; exercise actual traversal when permitted.
        child_code = '''import os,pathlib,sys
try: os.setgroups([]); os.setgid(65534); os.setuid(65534)
except PermissionError: sys.exit(77)
assert all(p.read_bytes() for p in pathlib.Path(sys.argv[1]).rglob('*') if p.is_file())
'''
        child = subprocess.run(['/usr/bin/python3', '-c', child_code, str(stage)], capture_output=True)
        self.assertIn(child.returncode, (0, 77), 'Nonowner traversal failed despite the required public permissions')


class UtcModeTests(unittest.TestCase):
    def php_mode(self, cutoff, phase='enable', api_key='fixture-key'):
        php = os.getenv('WHATSAPP_TEST_PHP') or ('/tmp/wa-workflow-runtime/php' if pathlib.Path('/tmp/wa-workflow-runtime/php').is_file() else shutil.which('php'))
        if not php: self.skipTest('PHP required for the actual MODE_PHP date validation')
        with tempfile.TemporaryDirectory() as folder:
            root = pathlib.Path(folder); (root / 'vendor').mkdir(); (root / 'bootstrap').mkdir()
            (root / 'vendor/autoload.php').write_text('<?php\n')
            config = {'whatsapp_orders': {'enabled': True, 'model': 'gpt-6-luna', 'api_key': api_key, 'mode': 'auto', 'automation_actor_id': '1',
                'allowed_branch_ids': ['94', '301', '307', '363'], 'activation_message_id': '116', 'activation_event_id': '285'}, 'whatsapp_cart.activated_at': cutoff}
            (root / 'fixture.json').write_text(json.dumps(config))
            (root / 'bootstrap/app.php').write_text('''<?php
function config($key=null) { $values=json_decode(file_get_contents('fixture.json'),true); return $key===null ? new class($values) {private $values; function __construct($v){$this->values=$v;} function all(){return $this->values;}} : ($values[$key]??null); }
return new class {function make($class){return new class {function bootstrap(){}};}};
''')
            return subprocess.run([php, '-r', NS['MODE_PHP'], phase], cwd=root, capture_output=True)

    def test_next_whole_second_cutoff_is_valid(self):
        at = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(seconds=1)
        result = self.php_mode(at.strftime('%Y-%m-%d %H:%M:%S'))
        self.assertEqual(result.returncode, 0); self.assertEqual(json.loads(result.stdout)['mode'], 'auto')

    def test_excessive_future_and_invalid_calendar_cutoffs_are_refused(self):
        future = (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(minutes=1)).strftime('%Y-%m-%d %H:%M:%S')
        for value in (future, '2026-02-30 00:00:00', '0000-01-01 00:00:00', '2026-10-12T00:00:00Z'):
            with self.subTest(value=value):
                result = self.php_mode(value); self.assertNotEqual(result.returncode, 0); self.assertEqual(result.stdout, b'')

    def test_install_only_does_not_require_ai_credentials(self):
        at = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=1)).strftime('%Y-%m-%d %H:%M:%S')
        self.assertEqual(self.php_mode(at, 'install-only', api_key='').returncode, 0)
        self.assertNotEqual(self.php_mode(at, 'enable', api_key='').returncode, 0)


class ProductionPinTests(unittest.TestCase):
    def test_reviewed_manifest_payload_and_helper_hashes_match_exact_wrapper_pins(self):
        manifest = json.loads((ROOT / 'deployment' / NS['MANIFEST_NAME']).read_bytes())
        self.assertEqual({row['path']: row['after_blob'] for row in manifest['sources']}, NS['TARGET_PINS'])
        self.assertEqual({path: pins[1] for path, pins in APP.SOURCE_PINS.items()}, NS['TARGET_PINS'])
        # Cairo's original publisher remains a frozen release contract. The
        # separately pinned evidence repair advances only this shared source.
        workflow = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
        legacy_blob = '6c63b91d7fb70130ae472cea291fa2cee88b27dd'
        repaired_blob = 'b30f25cf6dfc4a84061a58316db583f892a439a4'
        repair_spec = importlib.util.spec_from_file_location('cairo_evidence_repair_pin_fixture',
            ROOT / 'deployment/apply_whatsapp_order_evidence_repair.py')
        repair = importlib.util.module_from_spec(repair_spec)
        sys.modules[repair_spec.name] = repair; repair_spec.loader.exec_module(repair)
        self.assertEqual(NS['TARGET_PINS'][workflow], legacy_blob)
        self.assertEqual(repair.SOURCE_PINS[workflow], (legacy_blob, repaired_blob))
        for relative, pin in NS['TARGET_PINS'].items():
            self.assertRegex(pin, r'\A[0-9a-f]{40}\Z')
            self.assertEqual(NS['blob']((ROOT / relative).read_bytes()), repaired_blob if relative == workflow else pin)
        for name, pin in NS['HELPER_PINS'].items():
            self.assertRegex(pin, r'\A[0-9a-f]{40}\Z'); self.assertEqual(NS['blob']((ROOT / 'deployment' / name).read_bytes()), pin)

    @unittest.skipUnless(os.geteuid() == 0, 'Original Cairo staging requires a root-owned fixture')
    def test_original_cairo_stager_refuses_the_repaired_workflow_release_without_worker_actions(self):
        workflow = 'app/Services/Dashboard/WhatsAppOrderWorkflow.php'
        self.assertEqual(NS['TARGET_PINS'][workflow], '6c63b91d7fb70130ae472cea291fa2cee88b27dd')
        current = (ROOT / workflow).read_bytes()
        self.assertEqual(NS['blob'](current), 'b30f25cf6dfc4a84061a58316db583f892a439a4')
        calls = []
        def runner(args):
            calls.append(tuple(args))
            self.assertEqual(args[:4], [NS['RUNUSER'], '-u', 'fasakha', '--'])
            self.assertEqual(args[4:7], ['git', '-C', str(NS['ROOT'])])
            if args[7:9] == ['cat-file', '-t']:
                return 0, b'commit\n'
            self.assertEqual(args[7], 'show')
            relative = args[8].split(':', 1)[1]
            return 0, (ROOT / relative).read_bytes()
        with tempfile.TemporaryDirectory() as folder:
            payload = pathlib.Path(folder)
            with self.assertRaisesRegex(NS['SafeError'], 'CAIRO_PINNED_RELEASE_FILE_FAILED: ' + workflow):
                NS['stage_payload'](payload, 'a' * 40, runner)
            self.assertFalse((payload / workflow).exists())
        self.assertEqual(calls[-1][-1], 'a' * 40 + ':' + workflow)
        self.assertFalse(any(NS['SYSTEMCTL'] in args or '--enable' in args or '--install' in args for args in calls))

    def test_native_gate_rejects_unbound_or_noninteger_evidence_reports(self):
        valid = {'status': 'NATIVE_BUSINESS_PROJECTION_READY', 'source': 'standby', 'peer_binding': 'EXPLICIT_USER_AND_PHONE',
            'event_id': 1, 'message_id': 2, 'inbound_message_id': 3, 'conversation_id': 4}
        self.assertEqual(NS['native_proof'](lambda args: (0, json.dumps(valid).encode())), valid)
        for source in ('standby', 'smb_message_echoes'):
            accepted = dict(valid, source=source)
            self.assertEqual(NS['native_proof'](lambda args: (0, json.dumps(accepted).encode())), accepted)
        for key, bad in (('source', 'messages'), ('status', 'NATIVE_STANDBY_PROJECTION_READY'), ('peer_binding', 'INFERRED_BUID'), ('event_id', True), ('message_id', '2'), ('conversation_id', 0)):
            invalid = dict(valid); invalid[key] = bad
            with self.subTest(key=key, bad=bad), self.assertRaisesRegex(NS['SafeError'], 'NATIVE_BUSINESS_PROJECTION_PROOF_REQUIRED'):
                NS['native_proof'](lambda args: (0, json.dumps(invalid).encode()))

    def test_child_failure_extraction_accepts_only_fixed_guards_known_paths_units_or_backup(self):
        allowed = ['GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW', 'CAIRO_READ_ONLY_READINESS_FAILED',
            'CAIRO_CANDIDATE_LINT_OR_STAGING_FAILED: ' + APP.UI_PATH,
            'CAIRO_PROTECTED_FILE_CHANGED: .env',
            'CAIRO_WORKERS_MUST_BE_PAUSED_AND_QUIESCENT: ' + APP.QUIET_UNITS[0],
            'CAIRO_ORIGIN_FILES_RESTORED; BACKUP=/home/fasakha/whatsapp-release-backups/cairo-origins-safe123']
        for value in allowed:
            with self.subTest(value=value): self.assertEqual(NS['app_failure_status']((value + '\n').encode(), APP), value)

    def test_child_failure_extraction_rejects_unknown_multiline_oversized_or_private_reports(self):
        disallowed = [b'', None, 'GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW', b'x' * 4097,
            b'CAIRO_READ_ONLY_READINESS_FAILED\nPRIVATE_RESPONSE', b'PRIVATE_RESPONSE', b'\xff',
            b'CAIRO_PROTECTED_FILE_CHANGED: /home/fasakha/private-token.json',
            b'CAIRO_WORKERS_MUST_BE_PAUSED_AND_QUIESCENT: unrelated.service',
            b'CAIRO_ORIGIN_FILES_RESTORED; BACKUP=/etc/private',
            b'CAIRO_ORIGIN_FILES_RESTORED; BACKUP=/home/fasakha/whatsapp-release-backups/cairo-origins-../../private']
        for value in disallowed:
            with self.subTest(value=value): self.assertEqual(NS['app_failure_status'](value, APP), 'UNRECOGNIZED')


class NativeSqliteGateTests(unittest.TestCase):
    def gate(self, scenario, source='standby'):
        php = os.getenv('WHATSAPP_TEST_PHP') or '/tmp/wa-workflow-runtime/php'
        php = php if pathlib.Path(php).is_file() else shutil.which(php)
        vendor = pathlib.Path(os.getenv('WA_INBOX_TEST_VENDOR') or '/tmp/wa-workflow-deps/vendor')
        if vendor.name == 'autoload.php': vendor = vendor.parent
        if not php or not (vendor / 'autoload.php').is_file():
            if os.getenv('WHATSAPP_REQUIRE_PHP_TESTS') == '1': self.fail('Real Laravel/PHP SQLite runtime required')
            self.skipTest('Real Laravel/PHP SQLite runtime required')
        with tempfile.TemporaryDirectory() as folder:
            root = pathlib.Path(folder); (root / 'vendor').mkdir(); (root / 'bootstrap').mkdir()
            (root / 'vendor/autoload.php').write_text("<?php require getenv('WA_GATE_VENDOR').'/autoload.php';\n")
            (root / 'bootstrap/app.php').write_text(r'''<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
require getenv('WA_GATE_PROTOCOL');
if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('SQLITE_REQUIRED');
$capsule=new Capsule();$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$capsule->setAsGlobal();$capsule->bootEloquent();
$container=$capsule->getContainer();$container->instance('db',$capsule->getDatabaseManager());$container->instance('db.schema',$capsule->getConnection()->getSchemaBuilder());
$container['config']['app.key']='base64:'.base64_encode(str_repeat('x',32));
\Illuminate\Container\Container::setInstance($container);
$container->instance('encrypter',new \Illuminate\Encryption\Encrypter(str_repeat('x',32),'AES-256-CBC'));\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
Schema::create('whatsapp_webhook_events',function($t){$t->integer('id')->primary();$t->text('payload');$t->string('payload_hash');$t->string('received_at');$t->string('processed_at')->nullable();});
Schema::create('whatsapp_inbox_ingestion_failures',function($t){$t->integer('event_id')->primary();});
Schema::create('whatsapp_inbox_conversations',function($t){$t->integer('id')->primary();$t->string('waba_id');$t->string('phone_number_id');$t->string('peer_hash');});
Schema::create('whatsapp_inbox_messages',function($t){$t->integer('id')->primary();$t->integer('conversation_id');$t->string('message_key')->nullable();$t->string('direction');$t->string('source');$t->string('type');$t->string('sent_at');$t->text('content');});
$scenario=getenv('WA_GATE_SCENARIO');$source=getenv('WA_GATE_SOURCE');$waba='468336579702269';$phone='515388018324075';$sent=time()-60;
$echo=['id'=>'wamid.gate.synthetic','timestamp'=>(string)$sent,'message'=>[
 'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'201000000001','to_user_id'=>'US.GATE',
 'type'=>'text','text'=>['body'=>'Synthetic native business reply']]];
$value=['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>$phone,'display_phone_number'=>'201285545554'],
 'standby'=>['message_echoes'=>[$echo]]];
$outContactUser=in_array($scenario,['outbound_contact_user','both_contact_users','contact_user_absent','contact_user_wrong',
 'contact_saved_user_conflict','contact_saved_no_peer'],true);
$outContactPhone=in_array($scenario,['outbound_contact_phone','contact_phone_absent','contact_phone_wrong','contact_saved_phone_conflict'],true);
if($outContactUser)unset($echo['message']['to_user_id']);
if($outContactPhone)unset($echo['message']['to']);
if($outContactUser||$outContactPhone||in_array($scenario,['contact_user_conflict','contact_phone_conflict'],true)){
 $value['contacts']=[['wa_id'=>'201000000001','user_id'=>'US.GATE']];
 $value['standby']['message_echoes']=[$echo];
}
if($source==='smb_message_echoes'){
 unset($value['standby']);$value['message_echoes']=[$echo['message']+['id'=>$echo['id'],'timestamp'=>$echo['timestamp']]];
}
$raw=['object'=>'whatsapp_business_account','entry'=>[['id'=>$waba,'changes'=>[['field'=>$source,'value'=>$value]]]]];
$dto=\App\Support\WhatsAppInboxProtocol::messages($raw,$waba,$phone)[0];$stored=$dto;
$incomingMessage=['id'=>'wamid.customer.synthetic','timestamp'=>(string)(time()-120),'from'=>'201000000001',
 'from_user_id'=>'US.GATE','type'=>'text','text'=>['body'=>'Synthetic customer message']];
if($scenario==='user_only_inbound')unset($incomingMessage['from']);
$inContactUser=in_array($scenario,['inbound_contact_user','both_contact_users','inbound_contact_user_absent','inbound_contact_saved_user_conflict'],true);
$inContactPhone=$scenario==='inbound_contact_phone';
if($inContactUser)unset($incomingMessage['from_user_id']);
if($inContactPhone)unset($incomingMessage['from']);
if($scenario==='inbound_after_business')$incomingMessage['timestamp']=(string)($sent+30);
if($scenario==='inbound_old')$incomingMessage['timestamp']=(string)(time()-10800);
$incomingRaw=['object'=>'whatsapp_business_account','entry'=>[['id'=>$waba,'changes'=>[['field'=>'messages','value'=>[
 'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>$phone,'display_phone_number'=>'201285545554'],
 'messages'=>[$incomingMessage]]]]]]];
if($inContactUser||$inContactPhone)$incomingRaw['entry'][0]['changes'][0]['value']['contacts']=[['wa_id'=>'201000000001','user_id'=>'US.GATE']];
if($scenario==='standby_inbound'){
 $incomingRaw['entry'][0]['changes'][0]['field']='standby';
 $incomingValue=&$incomingRaw['entry'][0]['changes'][0]['value'];
 $incomingValue['standby']=['messages'=>$incomingValue['messages']];unset($incomingValue['messages']);
}
$incoming=\App\Support\WhatsAppInboxProtocol::messages($incomingRaw,$waba,$phone)[0];
if($scenario==='inbound_contact_user_absent')unset($incomingRaw['entry'][0]['changes'][0]['value']['contacts'][0]['user_id']);
if($scenario==='inbound_namespace')$incomingRaw['object']='page';
if($scenario==='inbound_raw_scope')$incomingRaw['entry'][0]['id']='111111111111';
$received=gmdate('Y-m-d H:i:s');$projectedSource=$source;$conversation=4;$processed=$received;
if($source==='standby'){
 $nativeMessage=&$raw['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['message'];
 $nativeTime=&$raw['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['timestamp'];
}else{
 $nativeMessage=&$raw['entry'][0]['changes'][0]['value']['message_echoes'][0];$nativeTime=&$nativeMessage['timestamp'];
}
if($scenario==='phone_only')unset($nativeMessage['to_user_id']);
if($scenario==='unrelated_user')$incoming['peer_user_id']='US.UNRELATED';
if($scenario==='different_phone')$incoming['peer_phone']='201000000002';
if($scenario==='projected_text')$stored['text']='Different projected body';
if($scenario==='projected_dto_source')$stored['source']=$source==='standby'?'smb_message_echoes':'standby';
if($scenario==='projected_row_source')$projectedSource=$source==='standby'?'smb_message_echoes':'standby';
if($scenario==='projected_dto_time')$stored['sent_at']=gmdate('Y-m-d H:i:s',time()-90);
if($scenario==='projected_native_time')$stored['content']['message']['timestamp']=(string)(time()-90);
if($scenario==='projected_identity')$stored['peer_identity']='user:US.UNRELATED';
if($scenario==='projected_native_user')$stored['content']['message']['to_user_id']='US.UNRELATED';
if($scenario==='projected_native_phone')$stored['content']['message']['to']='201000000002';
if($scenario==='projected_native_user_missing')unset($stored['content']['message']['to_user_id']);
if($scenario==='contact_saved_user_conflict')$stored['content']['message']['to_user_id']='US.UNRELATED';
if($scenario==='contact_saved_phone_conflict')$stored['content']['message']['to']='201000000002';
if($scenario==='contact_saved_no_peer'){unset($stored['content']['message']['to']);unset($stored['content']['message']['to_user_id']);}
if($scenario==='contact_user_conflict')$raw['entry'][0]['changes'][0]['value']['contacts'][0]['user_id']='US.UNRELATED';
if($scenario==='contact_phone_conflict')$raw['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id']='201000000002';
if($scenario==='contact_user_absent')unset($raw['entry'][0]['changes'][0]['value']['contacts'][0]['user_id']);
if($scenario==='contact_phone_absent')unset($raw['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id']);
if($scenario==='contact_user_wrong')$raw['entry'][0]['changes'][0]['value']['contacts'][0]['user_id']='US.UNRELATED';
if($scenario==='contact_phone_wrong')$raw['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id']='201000000002';
if($scenario==='old_received')$received=gmdate('Y-m-d H:i:s',time()-10800);
if($scenario==='future_received')$received=gmdate('Y-m-d H:i:s',time()+30);
if($scenario==='old_native_time')$nativeTime=(string)(time()-10800);
if($scenario==='future_native_time')$nativeTime=(string)(time()+30);
if($scenario==='wrong_raw_scope')$raw['entry'][0]['id']='111111111111';
if($scenario==='wrong_phone_scope')$raw['entry'][0]['changes'][0]['value']['metadata']['phone_number_id']='111111111111';
if($scenario==='unknown_raw_source')$raw['entry'][0]['changes'][0]['field']='unknown';
if($scenario==='messenger_namespace')$raw['object']='page';
if($scenario==='status_only')$raw['entry'][0]['changes'][0]['value']=['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>$phone],
 'statuses'=>[['id'=>'wamid.gate.synthetic','status'=>'read','timestamp'=>(string)$sent]]];
if($scenario==='unprocessed')$processed=null;
$encode=static fn($v)=>Crypt::encryptString(json_encode($v,JSON_THROW_ON_ERROR));
$businessEventId=in_array($scenario,['bounded_events','bounded_bytes'],true)?1000:1;
DB::table('whatsapp_webhook_events')->insert(['id'=>$businessEventId,'payload'=>$encode($raw),'payload_hash'=>$scenario==='bad_payload_hash'?str_repeat('0',64):hash('sha256',json_encode($raw,JSON_THROW_ON_ERROR)),
 'received_at'=>$received,'processed_at'=>$processed]);
if($scenario!=='inbound_raw_missing')DB::table('whatsapp_webhook_events')->insert(['id'=>2,'payload'=>$encode($incomingRaw),
 'payload_hash'=>$scenario==='inbound_bad_payload_hash'?str_repeat('0',64):hash('sha256',json_encode($incomingRaw,JSON_THROW_ON_ERROR)),
 'received_at'=>gmdate('Y-m-d H:i:s',time()-120),'processed_at'=>$scenario==='inbound_unprocessed'?null:gmdate('Y-m-d H:i:s')]);
if(in_array($scenario,['bounded_events','bounded_bytes'],true)){
 $noise=$incomingRaw;$noiseValue=&$noise['entry'][0]['changes'][0]['value'];unset($noiseValue['messages']);
 $noiseValue['statuses']=[['id'=>'wamid.status.synthetic','status'=>'read','timestamp'=>(string)$sent]];
 if($scenario==='bounded_bytes')$noiseValue['metadata']['fixture_padding']=str_repeat('x',500000);
 $noiseCipher=$encode($noise);$noiseHash=hash('sha256',json_encode($noise,JSON_THROW_ON_ERROR));
 $noiseCount=$scenario==='bounded_events'?199:36;
 for($i=0;$i<$noiseCount;$i++)DB::table('whatsapp_webhook_events')->insert(['id'=>10+$i,
  'payload'=>$noiseCipher,'payload_hash'=>$noiseHash,'received_at'=>gmdate('Y-m-d H:i:s'),'processed_at'=>gmdate('Y-m-d H:i:s')]);
}
if($scenario==='quarantine')DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>1]);
if($scenario==='inbound_quarantine')DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>2]);
DB::table('whatsapp_inbox_conversations')->insert(['id'=>4,'waba_id'=>$scenario==='wrong_projected_scope'?'111111111111':$waba,'phone_number_id'=>$phone,
 'peer_hash'=>$scenario==='wrong_peer_hash'?str_repeat('0',64):hash_hmac('sha256','whatsapp-peer-v1:'.$waba.':'.$phone.':user:US.GATE',str_repeat('x',32))]);
if($scenario!=='raw_only')DB::table('whatsapp_inbox_messages')->insert(['id'=>2,'conversation_id'=>4,
 'message_key'=>$scenario==='wrong_message_key'?str_repeat('0',64):hash('sha256','whatsapp-message-v1:'.$waba.':'.$phone.':'.$dto['message_id']),
 'direction'=>'outbound','source'=>$projectedSource,'type'=>'text',
 'sent_at'=>$scenario==='projected_row_time'?gmdate('Y-m-d H:i:s',time()-90):$dto['sent_at'],'content'=>$encode($stored)]);
if($scenario==='inbound_projected_native')$incoming['content']['message']['text']['body']='Different incoming body';
if($scenario==='inbound_projected_user')$incoming['content']['message']['from_user_id']='US.UNRELATED';
if($scenario==='inbound_projected_phone')$incoming['content']['message']['from']='201000000002';
if($scenario==='inbound_contact_saved_user_conflict')$incoming['content']['message']['from_user_id']='US.UNRELATED';
DB::table('whatsapp_inbox_messages')->insert(['id'=>3,'conversation_id'=>$scenario==='different_conversation'?5:4,
 'message_key'=>$scenario==='inbound_wrong_message_key'?str_repeat('0',64):hash('sha256','whatsapp-message-v1:'.$waba.':'.$phone.':'.$incoming['message_id']),
 'direction'=>'inbound','source'=>$incoming['source'],'type'=>'text',
 'sent_at'=>$scenario==='inbound_projected_row_time'?gmdate('Y-m-d H:i:s',time()-110):$incoming['sent_at'],'content'=>$encode($incoming)]);
if($scenario==='broken_cipher')DB::table('whatsapp_webhook_events')->where('id',1)->update(['payload'=>'invalid ciphertext']);
$connection=$capsule->getConnection();$connection->enableQueryLog();
register_shutdown_function(static function()use($connection){foreach($connection->getQueryLog()as$q){
 if(!preg_match('/\A\s*select\b/i',$q['query'])){fwrite(STDERR,"NATIVE_GATE_WROTE_DATABASE\n");exit(78);}
}});
return new class {function make($class){return new class {function bootstrap(){}};}};
''')
            env = os.environ.copy(); env.update(WA_GATE_VENDOR=str(vendor), WA_GATE_PROTOCOL=str(ROOT / 'app/Support/WhatsAppInboxProtocol.php'), WA_GATE_SCENARIO=scenario, WA_GATE_SOURCE=source)
            return subprocess.run([php, '-r', NS['NATIVE_PROOF_PHP']], cwd=root, env=env, capture_output=True)

    def test_real_sqlite_crypt_and_protocol_require_matching_raw_projected_business_and_inbound(self):
        for source in ('standby', 'smb_message_echoes'):
            for scenario in ('valid', 'user_only_inbound', 'standby_inbound','outbound_contact_user','outbound_contact_phone',
                             'inbound_contact_user','inbound_contact_phone','both_contact_users','projected_native_user_missing'):
                with self.subTest(source=source, scenario=scenario):
                    result = self.gate(scenario, source)
                    self.assertEqual(result.returncode, 0, result.stderr.decode()); self.assertEqual(result.stderr, b'')
                    self.assertEqual(json.loads(result.stdout), {'status':'NATIVE_BUSINESS_PROJECTION_READY','source':source,'peer_binding':'EXPLICIT_USER_AND_PHONE',
                        'event_id':1,'message_id':2,'inbound_message_id':3,'conversation_id':4})

    def test_real_gate_refuses_unbound_stale_quarantined_unprojected_or_conflicting_evidence(self):
        for scenario in ('phone_only','unrelated_user','different_phone','projected_text','projected_dto_source','projected_row_source',
                         'old_received','old_native_time','future_native_time','wrong_raw_scope','wrong_projected_scope','status_only',
                         'unprocessed','quarantine','raw_only','different_conversation','broken_cipher','projected_row_time','projected_dto_time',
                         'projected_native_time','projected_identity','projected_native_user','projected_native_phone',
                         'future_received','wrong_phone_scope','unknown_raw_source',
                         'messenger_namespace','bad_payload_hash','wrong_peer_hash','wrong_message_key','inbound_raw_missing',
                         'inbound_bad_payload_hash','inbound_unprocessed','inbound_quarantine','inbound_wrong_message_key',
                         'inbound_projected_native','inbound_projected_row_time','inbound_projected_user','inbound_projected_phone',
                         'bounded_events','bounded_bytes','inbound_after_business','inbound_old','inbound_namespace','inbound_raw_scope',
                         'contact_user_conflict','contact_phone_conflict','contact_user_absent','contact_phone_absent','contact_user_wrong',
                         'contact_phone_wrong','contact_saved_user_conflict','contact_saved_phone_conflict','contact_saved_no_peer',
                         'inbound_contact_user_absent','inbound_contact_saved_user_conflict'):
            for source in ('standby', 'smb_message_echoes'):
                with self.subTest(scenario=scenario, source=source):
                    result = self.gate(scenario, source)
                    self.assertEqual(result.returncode, 1, result.stderr.decode()); self.assertEqual(result.stdout, b''); self.assertEqual(result.stderr, b'')


if __name__ == '__main__': unittest.main()
