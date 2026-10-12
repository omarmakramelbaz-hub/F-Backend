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
            SOURCE_PINS=source_pins, PROTECTED_PATHS=APP.PROTECTED_PATHS,
            PROTECTED_BLOBS={r: NS['blob'](self.protected[r]) for r in APP.PROTECTED_BLOBS},
            LEGACY_READ_ONLY_MODELS=APP.LEGACY_READ_ONLY_MODELS,
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
                    return 0, json.dumps({'status': 'NATIVE_STANDBY_PROJECTION_READY', 'source': 'standby', 'peer_binding': 'EXPLICIT_USER_AND_PHONE',
                        'event_id': 1, 'message_id': 2, 'inbound_message_id': 3, 'conversation_id': 4}).encode()
                self.assertEqual(args[6], NS['MODE_PHP'])
                return 0, json.dumps({'mode': self.mode, 'fingerprint': self.fingerprint}).encode()
            self.assertEqual(timeout, 240)
            if self.fault == 'ai': return 1, b'{"status":"AI_CONNECTION_FAILED","ai_calls":1}'
            return 0, b'{"status":"AI_CONNECTION_READY","ai_calls":1}'
        if any(pathlib.Path(str(arg)).name == NS['APP_NAME'] for arg in args):
            self.assertEqual(timeout, 300)
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
        with self.assertRaisesRegex(NS['SafeError'], 'NATIVE_STANDBY_PROJECTION_PROOF_REQUIRED_PRIOR_PIPELINE_REMAINS_RUNNING'): self.run_wrapper()
        self.assert_capture_available()
        self.assertFalse(any(any(str(x).endswith('whatsapp_ai_connection_check.php') for x in a) for a in self.log))
        self.assertFalse(any(a[:2] == (NS['SYSTEMCTL'], 'stop') for a in self.log))

    def test_native_proof_loss_after_app_refuses_activation_and_recovers_capture(self):
        self.fault = 'proof_after'
        with self.assertRaisesRegex(NS['SafeError'], 'NATIVE_STANDBY_PROJECTION_PROOF_REQUIRED.*ORDERS_DISABLED_CAPTURE_ACTIVE.*VERIFIED_APP_EXTENSION_RETAINED'): self.run_wrapper()
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
        with self.assertRaisesRegex(NS['SafeError'], 'PROTECTED_SOURCE_CHANGED_AFTER_PUBLICATION'): self.run_wrapper()
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
        for relative, pin in NS['TARGET_PINS'].items():
            self.assertRegex(pin, r'\A[0-9a-f]{40}\Z'); self.assertEqual(NS['blob']((ROOT / relative).read_bytes()), pin)
        for name, pin in NS['HELPER_PINS'].items():
            self.assertRegex(pin, r'\A[0-9a-f]{40}\Z'); self.assertEqual(NS['blob']((ROOT / 'deployment' / name).read_bytes()), pin)

    def test_native_gate_rejects_unbound_or_noninteger_evidence_reports(self):
        valid = {'status': 'NATIVE_STANDBY_PROJECTION_READY', 'source': 'standby', 'peer_binding': 'EXPLICIT_USER_AND_PHONE',
            'event_id': 1, 'message_id': 2, 'inbound_message_id': 3, 'conversation_id': 4}
        self.assertEqual(NS['native_proof'](lambda args: (0, json.dumps(valid).encode())), valid)
        for key, bad in (('source', 'smb_message_echoes'), ('peer_binding', 'INFERRED_BUID'), ('event_id', True), ('message_id', '2'), ('conversation_id', 0)):
            invalid = dict(valid); invalid[key] = bad
            with self.subTest(key=key, bad=bad), self.assertRaisesRegex(NS['SafeError'], 'NATIVE_STANDBY_PROJECTION_PROOF_REQUIRED'):
                NS['native_proof'](lambda args: (0, json.dumps(invalid).encode()))


class NativeSqliteGateTests(unittest.TestCase):
    def gate(self, scenario):
        php = os.getenv('WHATSAPP_TEST_PHP') or '/tmp/wa-workflow-runtime/php'
        vendor = pathlib.Path(os.getenv('WA_INBOX_TEST_VENDOR') or '/tmp/wa-workflow-deps/vendor')
        if not pathlib.Path(php).is_file() or not (vendor / 'autoload.php').is_file(): self.skipTest('Real Laravel/PHP SQLite runtime required')
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
$container->instance('encrypter',new \Illuminate\Encryption\Encrypter(str_repeat('x',32),'AES-256-CBC'));\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
Schema::create('whatsapp_webhook_events',function($t){$t->integer('id')->primary();$t->text('payload');$t->string('received_at');$t->string('processed_at')->nullable();});
Schema::create('whatsapp_inbox_ingestion_failures',function($t){$t->integer('event_id')->primary();});
Schema::create('whatsapp_inbox_conversations',function($t){$t->integer('id')->primary();$t->string('waba_id');$t->string('phone_number_id');});
Schema::create('whatsapp_inbox_messages',function($t){$t->integer('id')->primary();$t->integer('conversation_id');$t->string('message_key')->nullable();$t->string('direction');$t->string('source');$t->string('type');$t->string('sent_at');$t->text('content');});
$scenario=getenv('WA_GATE_SCENARIO');$waba='468336579702269';$phone='515388018324075';$sent=time()-60;
$echo=['id'=>'wamid.gate.synthetic','timestamp'=>(string)$sent,'message'=>[
 'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'201000000001','to_user_id'=>'US.GATE',
 'type'=>'text','text'=>['body'=>'Synthetic native business reply']]];
$value=['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>$phone,'display_phone_number'=>'201285545554'],
 'standby'=>['message_echoes'=>[$echo]]];
$raw=['object'=>'whatsapp_business_account','entry'=>[['id'=>$waba,'changes'=>[['field'=>'standby','value'=>$value]]]]];
$dto=\App\Support\WhatsAppInboxProtocol::messages($raw,$waba,$phone)[0];$stored=$dto;
$incoming=['direction'=>'inbound','source'=>'messages','type'=>'text','peer_user_id'=>'US.GATE','peer_phone'=>'201000000001','text'=>'Synthetic customer message'];
$received=gmdate('Y-m-d H:i:s');$projectedSource='standby';$conversation=4;$processed=$received;
if($scenario==='phone_only')unset($raw['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['message']['to_user_id']);
if($scenario==='unrelated_user')$incoming['peer_user_id']='US.UNRELATED';
if($scenario==='different_phone')$incoming['peer_phone']='201000000002';
if($scenario==='projected_text')$stored['text']='Different projected body';
if($scenario==='projected_dto_source')$stored['source']='smb_message_echoes';
if($scenario==='projected_row_source')$projectedSource='smb_message_echoes';
if($scenario==='old_received')$received=gmdate('Y-m-d H:i:s',time()-10800);
if($scenario==='old_native_time')$raw['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['timestamp']=(string)(time()-10800);
if($scenario==='future_native_time')$raw['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['timestamp']=(string)(time()+30);
if($scenario==='wrong_raw_scope')$raw['entry'][0]['id']='111111111111';
if($scenario==='status_only')$raw['entry'][0]['changes'][0]['value']['standby']=['statuses'=>[['id'=>'wamid.gate.synthetic','status'=>'read','timestamp'=>(string)$sent]]];
if($scenario==='unprocessed')$processed=null;
$encode=static fn($v)=>Crypt::encryptString(json_encode($v,JSON_THROW_ON_ERROR));
DB::table('whatsapp_webhook_events')->insert(['id'=>1,'payload'=>$encode($raw),'received_at'=>$received,'processed_at'=>$processed]);
if($scenario==='quarantine')DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>1]);
DB::table('whatsapp_inbox_conversations')->insert(['id'=>4,'waba_id'=>$scenario==='wrong_projected_scope'?'111111111111':$waba,'phone_number_id'=>$phone]);
if($scenario!=='raw_only')DB::table('whatsapp_inbox_messages')->insert(['id'=>2,'conversation_id'=>4,
 'message_key'=>hash('sha256','whatsapp-message-v1:'.$waba.':'.$phone.':'.$dto['message_id']),
 'direction'=>'outbound','source'=>$projectedSource,'type'=>'text','sent_at'=>$dto['sent_at'],'content'=>$encode($stored)]);
DB::table('whatsapp_inbox_messages')->insert(['id'=>3,'conversation_id'=>$scenario==='different_conversation'?5:4,'message_key'=>null,
 'direction'=>'inbound','source'=>'messages','type'=>'text','sent_at'=>gmdate('Y-m-d H:i:s',time()-120),'content'=>$encode($incoming)]);
if($scenario==='broken_cipher')DB::table('whatsapp_webhook_events')->where('id',1)->update(['payload'=>'invalid ciphertext']);
$connection=$capsule->getConnection();$connection->enableQueryLog();
register_shutdown_function(static function()use($connection){foreach($connection->getQueryLog()as$q){
 if(!preg_match('/\A\s*select\b/i',$q['query'])){fwrite(STDERR,"NATIVE_GATE_WROTE_DATABASE\n");exit(78);}
}});
return new class {function make($class){return new class {function bootstrap(){}};}};
''')
            env = os.environ.copy(); env.update(WA_GATE_VENDOR=str(vendor), WA_GATE_PROTOCOL=str(ROOT / 'app/Support/WhatsAppInboxProtocol.php'), WA_GATE_SCENARIO=scenario)
            return subprocess.run([php, '-r', NS['NATIVE_PROOF_PHP']], cwd=root, env=env, capture_output=True)

    def test_real_sqlite_crypt_and_protocol_require_matching_raw_projected_business_and_inbound(self):
        result = self.gate('valid')
        self.assertEqual(result.returncode, 0, result.stderr.decode()); self.assertEqual(result.stderr, b'')
        self.assertEqual(json.loads(result.stdout), {'status':'NATIVE_STANDBY_PROJECTION_READY','source':'standby','peer_binding':'EXPLICIT_USER_AND_PHONE',
            'event_id':1,'message_id':2,'inbound_message_id':3,'conversation_id':4})

    def test_real_gate_refuses_unbound_stale_quarantined_unprojected_or_conflicting_evidence(self):
        for scenario in ('phone_only','unrelated_user','different_phone','projected_text','projected_dto_source','projected_row_source',
                         'old_received','old_native_time','future_native_time','wrong_raw_scope','wrong_projected_scope','status_only',
                         'unprocessed','quarantine','raw_only','different_conversation','broken_cipher'):
            with self.subTest(scenario=scenario):
                result = self.gate(scenario)
                self.assertEqual(result.returncode, 1, result.stderr.decode()); self.assertEqual(result.stdout, b''); self.assertEqual(result.stderr, b'')


if __name__ == '__main__': unittest.main()
