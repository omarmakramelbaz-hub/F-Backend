#!/usr/bin/env python3
"""Token rotation preservation, preflight, real PHP cache edits and atomic rollback."""
import copy
import fcntl
import getpass
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
import unittest
import warnings

DEPLOYMENT = pathlib.Path(__file__).absolute().parent.parent / 'deployment'
spec = importlib.util.spec_from_file_location('whatsapp_catalog_token_rotation', DEPLOYMENT / 'rotate_whatsapp_catalog_token.py')
rotation = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = rotation
spec.loader.exec_module(rotation)
PHP = os.environ.get('WHATSAPP_TEST_PHP', 'php')
OLD = 'EAA' + 'old_reply_token_' * 5
NEW = 'EAA' + 'new_catalog_token_' * 5

REPORT = {
    'status': 'PARTIAL', 'writes': 0, 'conclusion': 'REQUESTED_PRODUCTS_READ_WORKS',
    'phone_access_check': {'status': 'READY', 'http': 200, 'phone_matches': True},
    'token_scope_check': {'status': 'READY', 'token_valid': True, 'app_matches': True,
                         'catalog_management': True, 'business_management': False,
                         'whatsapp_business_management': True, 'whatsapp_business_messaging': True},
    'probes': [{'probe': 'WABA_CATALOG_ASSOCIATION', 'status': 'API_ERROR', 'http': 400, 'code': 10},
               {'probe': 'PRODUCTS_FIELDS', 'status': 'READY', 'http': 200}]
}

BOOTSTRAP = r'''<?php
class FixtureConfig {
    public array $values = [];
    public function all() { return $this->values; }
}
$fixture = new FixtureConfig();
function config($key = null) {
    global $fixture;
    if ($key === null) return $fixture;
    $value = $fixture->values;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return null;
        $value = $value[$part];
    }
    return $value;
}
class FixtureKernel {
    public function bootstrap() {
        global $fixture;
        $fixture->values = require (is_file('bootstrap/cache/config.php')
            ? 'bootstrap/cache/config.php' : 'fixture-config.php');
        if (!is_file('bootstrap/cache/config.php')) {
            $env = file_get_contents('.env');
            if (!preg_match('/^WHATSAPP_REPLIES_ACCESS_TOKEN="([A-Za-z0-9._~-]+)"/m', $env, $match)) throw new RuntimeException();
            $fixture->values['whatsapp_replies']['access_token'] = $match[1];
        }
    }
}
class FixtureApp { public function make($class) { return new FixtureKernel(); } }
return new FixtureApp();
'''


class RotationTests(unittest.TestCase):
    def setUp(self):
        self.area = pathlib.Path(tempfile.mkdtemp(prefix='wa-token-tests-'))
        self.root = self.area / 'project'; self.root.mkdir()
        for directory in ['.git', 'vendor', 'bootstrap/cache']:
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        (self.root / 'vendor/autoload.php').write_text('<?php\n')
        (self.root / 'bootstrap/app.php').write_text(BOOTSTRAP)
        self.config = {
            'app': {'key': 'do_not_touch_application_key', 'url': 'https://example.invalid'},
            'whatsapp': {'app_secret': '1a' * 16, 'allowed_account_ids': ['468336579702269']},
            'whatsapp_orders': {'enabled': True, 'mode': 'review', 'model': 'gpt-6-luna',
                'api_key': 'sk-do_not_touch_key', 'automation_actor_id': None,
                'allowed_branch_ids': [94, 301, 307, 363], 'activation_message_id': 116,
                'activation_event_id': 2583, 'activation_at_utc': '2026-10-11 12:00:00',
                'cart_catalog_id': '627343999792522', 'cart_items': {'retailer1': {'product_id': 2575}},
                'debounce_seconds': 10, 'future_unknown': {'x': [True, None, -0.25, 'آمن']}},
            'whatsapp_replies': {'enabled': True, 'access_token': OLD, 'future_reply_setting': {'voice': True}},
            'custom_namespace': {'model': 'another_model', 'mapping': {'retailer': 123}}
        }
        input_path = self.area / 'configuration.json'; input_path.write_text(json.dumps(self.config))
        code = '$x=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo "<?php\n// preserve this cache comment\nreturn ".var_export($x,true).";\n";'
        original = subprocess.run([PHP, '-r', code, str(input_path)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout
        self.cache = self.root / rotation.CACHE_PATH; self.cache.write_bytes(original); self.cache.chmod(0o600)
        (self.root / 'fixture-config.php').write_bytes(original)
        self.env = self.root / '.env'
        self.env.write_bytes(('APP_KEY="unchanged$literal"\r\n# Keep this comment\r\n'
            'WHATSAPP_ORDERS_MODEL=gpt-6-luna\r\nWHATSAPP_ORDERS_MODE=review\r\n'
            'WHATSAPP_ORDERS_ACTIVATION_AT_UTC="2026-10-11 12:00:00"\r\n'
            'WHATSAPP_ORDERS_CART_ITEMS="unchanged mappings"\r\n'
            'WHATSAPP_REPLIES_ACCESS_TOKEN="' + OLD + '" # retain annotation\r\n'
            'CUSTOM_LAST=keep\r\n').encode())
        self.env.chmod(0o600)
        self.before_env = self.env.read_bytes(); self.before_cache = self.cache.read_bytes()
        self.calls = []; self.report = copy.deepcopy(REPORT); self.hook = None; self.fail_smoke = False

    def tearDown(self):
        shutil.rmtree(self.area)

    def runner(self, root, arguments, capture=False, timeout=120):
        self.calls.append(arguments)
        if len(arguments) > 2 and arguments[2] == rotation.PREFLIGHT_PHP:
            settings = pathlib.Path(arguments[3]); diagnostic = pathlib.Path(arguments[4])
            self.assertEqual(stat.S_IMODE(settings.stat().st_mode), 0o600)
            self.assertEqual(json.loads(settings.read_bytes()), {'token': NEW})
            self.assertEqual(rotation.base.blob_hash(diagnostic.read_bytes()), rotation.DIAGNOSTIC_BLOB)
            if self.hook is not None: self.hook('preflight')
            return 0, json.dumps(self.report).encode()
        if len(arguments) > 2 and arguments[2] == rotation.SMOKE_PHP:
            if self.hook is not None: self.hook('smoke')
            if self.fail_smoke: return 1, b''
        actual = [PHP] + arguments[1:]
        result = subprocess.run(actual, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=timeout, check=False)
        return result.returncode, result.stdout if capture else b''

    def rotate(self):
        return rotation.rotate(self.root, os.geteuid(), runner=self.runner, prompt=lambda: NEW)

    def assert_unchanged(self):
        self.assertEqual(self.env.read_bytes(), self.before_env)
        self.assertEqual(self.cache.read_bytes(), self.before_cache)

    def test_cached_rotation_preserves_all_other_bytes_and_unknown_fields(self):
        status, backup, readiness = self.rotate()
        self.assertEqual(status, 'WHATSAPP_CATALOG_TOKEN_ROTATED_REVIEW_ONLY')
        self.assertEqual(readiness, {'token_updated': True, 'other_configuration_preserved': True, 'mode_review': True})
        self.assertEqual(self.env.read_bytes(), self.before_env.replace(OLD.encode(), NEW.encode()))
        self.assertEqual(self.cache.read_bytes(), self.before_cache.replace(OLD.encode(), NEW.encode()))
        self.assertEqual((backup / 'env.before').read_bytes(), self.before_env)
        self.assertEqual((backup / 'config.before.php').read_bytes(), self.before_cache)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        for path in backup.rglob('*'):
            if path.is_file(): self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertNotIn(NEW, repr(self.calls))
        self.assertNotIn(OLD, repr(self.calls))
        self.assertNotIn('sk-do_not_touch_key', repr(self.calls))

    def test_uncached_rotation_leaves_cache_absent(self):
        self.cache.unlink()
        self.env.write_text('WHATSAPP_REPLIES_ACCESS_TOKEN="' + OLD + '"\nOTHER=keep\n')
        before = self.env.read_bytes()
        status, _, _ = self.rotate()
        self.assertEqual(status, 'WHATSAPP_CATALOG_TOKEN_ROTATED_REVIEW_ONLY')
        self.assertFalse(self.cache.exists())
        self.assertEqual(self.env.read_bytes(), before.replace(OLD.encode(), NEW.encode()))

    def test_equal_token_literals_in_other_settings_are_untouched(self):
        extra = "'custom_namespace' => \n  array (\n    'access_token' => '" + OLD + "',"
        cache = self.before_cache.replace(b"'custom_namespace' => \n  array (", extra.encode())
        self.cache.write_bytes(cache)
        env = self.before_env + ('OTHER_TOKEN="' + OLD + '"\r\n').encode()
        self.env.write_bytes(env)
        self.rotate()
        self.assertEqual(self.env.read_bytes(), env.replace(
            ('WHATSAPP_REPLIES_ACCESS_TOKEN="' + OLD + '"').encode(),
            ('WHATSAPP_REPLIES_ACCESS_TOKEN="' + NEW + '"').encode()))
        self.assertEqual(self.cache.read_bytes(), cache.replace(
            ("'access_token' => '" + OLD + "'").encode(),
            ("'access_token' => '" + NEW + "'").encode(), 1))
        self.assertEqual(self.cache.read_bytes().count(OLD.encode()), 1)
        self.assertEqual(self.env.read_bytes().count(OLD.encode()), 1)

    def test_all_required_preflight_signals_gate_publication(self):
        for signal in ['token_valid', 'app_matches', 'catalog_management',
                       'whatsapp_business_management', 'whatsapp_business_messaging']:
            with self.subTest(signal=signal):
                bad = copy.deepcopy(REPORT); bad['token_scope_check'][signal] = False
                self.assertFalse(rotation.preflight_ready(bad))
                bad['token_scope_check'][signal] = 1
                self.assertFalse(rotation.preflight_ready(bad))
        for patch in [{'probes': None}, {'probes': []}, {'writes': 1},
                      {'writes': False}, {'conclusion': 'CATALOG_VISIBLE_PRODUCTS_EDGE_UNAVAILABLE'}]:
            bad = copy.deepcopy(REPORT); bad.update(patch)
            self.assertFalse(rotation.preflight_ready(bad))
        self.assertTrue(rotation.preflight_ready(REPORT))
        bad = copy.deepcopy(REPORT); bad['probes'][1]['http'] = 400
        self.assertFalse(rotation.preflight_ready(bad))

    def test_failed_preflight_never_publishes_and_retains_safe_output(self):
        self.report['token_scope_check']['catalog_management'] = False
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_PREFLIGHT_FAILED_EXISTING_CONFIGURATION_UNCHANGED$'):
            self.rotate()
        self.assert_unchanged()
        self.assertFalse(any(args[2] == rotation.SMOKE_PHP for args in self.calls))

    def test_catalog_valid_token_without_current_phone_access_refused(self):
        self.report['phone_access_check'] = {'status': 'FAILED', 'http': 400, 'phone_matches': False}
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_PREFLIGHT_FAILED_EXISTING_CONFIGURATION_UNCHANGED$'):
            self.rotate()
        self.assert_unchanged()
        self.assertFalse(any(args[2] in [rotation.CACHE_CANDIDATE_PHP, rotation.SMOKE_PHP] for args in self.calls))

    def test_smoke_failure_rolls_back_both_files_exactly(self):
        self.fail_smoke = True
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_CONFIGURATION_RESTORED; BACKUP='):
            self.rotate()
        self.assert_unchanged()

    def test_publication_failure_after_atomic_exchange_is_recoverable(self):
        original = rotation.base.atomic_exchange
        def interrupted(source, target):
            original(source, target)
            raise RuntimeError('fixture interrupted after exchange')
        rotation.base.atomic_exchange = interrupted
        # Probe must succeed before injecting the publication fault.
        probe = rotation.base.probe_atomic_exchange
        rotation.base.probe_atomic_exchange = lambda directory: None
        try:
            with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_CONFIGURATION_RESTORED; BACKUP='):
                self.rotate()
            self.assert_unchanged()
        finally:
            rotation.base.atomic_exchange = original; rotation.base.probe_atomic_exchange = probe

    def test_post_publication_concurrent_edit_retained(self):
        concurrent = b'CONCURRENT_FILE=preserve\n'
        def hook(stage):
            if stage == 'smoke': self.env.write_bytes(concurrent)
        self.hook = hook; self.fail_smoke = True
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED; BACKUP='):
            self.rotate()
        self.assertEqual(self.env.read_bytes(), concurrent)
        self.assertEqual(self.cache.read_bytes(), self.before_cache)

    def test_prepublication_concurrent_edit_never_overwritten(self):
        concurrent = b'CONCURRENT_FILE=preserve-before-publication\n'
        self.hook = lambda stage: self.env.write_bytes(concurrent) if stage == 'preflight' else None
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED; BACKUP='):
            self.rotate()
        self.assertEqual(self.env.read_bytes(), concurrent)
        self.assertEqual(self.cache.read_bytes(), self.before_cache)

    def test_shared_configuration_lock_blocks_rotation(self):
        parent = self.root.parent / 'whatsapp-release-backups'; parent.mkdir(mode=0o700)
        descriptor = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT, 0o600)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(rotation.SafeError, '^ANOTHER_ORDERS_INSTALL_OR_CONFIGURATION_IS_RUNNING$'):
                self.rotate()
            self.assert_unchanged(); self.assertEqual(self.calls, [])
        finally:
            os.close(descriptor)

    def test_cache_non_review_mode_and_unsupported_expression_refused(self):
        self.cache.write_bytes(self.before_cache.replace(b"'mode' => 'review'", b"'mode' => 'auto'"))
        before = self.cache.read_bytes()
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_ROTATION_REQUIRES_EXISTING_REVIEW_CONFIGURATION$'):
            self.rotate()
        self.assertEqual(self.cache.read_bytes(), before); self.assertEqual(self.env.read_bytes(), self.before_env)
        self.assertFalse(any(args[2] == rotation.PREFLIGHT_PHP for args in self.calls))
        self.cache.write_bytes(self.before_cache.replace(b"'debounce_seconds' => 10", b"'debounce_seconds' => (5 + 5)"))
        before = self.cache.read_bytes(); self.calls = []
        with self.assertRaisesRegex(rotation.SafeError, '^TOKEN_CACHE_STAGING_FAILED_CONFIGURATION_UNCHANGED$'):
            self.rotate()
        self.assertEqual(self.cache.read_bytes(), before); self.assertEqual(self.env.read_bytes(), self.before_env)

    def test_symlinks_hardlinks_and_unsafe_mode_refused(self):
        target = self.area / 'env-target'; target.write_bytes(self.before_env)
        self.env.unlink(); self.env.symlink_to(target)
        with self.assertRaises(rotation.SafeError): self.rotate()
        self.assertEqual(target.read_bytes(), self.before_env)
        self.env.unlink(); os.link(target, self.env)
        with self.assertRaises(rotation.SafeError): self.rotate()
        self.env.unlink(); self.env.write_bytes(self.before_env); self.env.chmod(0o666)
        with self.assertRaises(rotation.SafeError): self.rotate()
        self.assertEqual(self.calls, [])

    def test_env_multiline_quotes_and_only_target_changes(self):
        text = 'MULTILINE="first\nWHATSAPP_REPLIES_ACCESS_TOKEN=fake_in_string\nlast"\n'
        text += '\texport WHATSAPP_REPLIES_ACCESS_TOKEN = \'old\' # comment\nUNCHANGED="a\\\"b"\n'
        actual = rotation.env_proposal(text.encode(), NEW)
        self.assertEqual(actual, text.replace("'old'", '"' + NEW + '"').encode())
        self.assertEqual(rotation.env_proposal(b'OTHER=keep', NEW),
                         ('OTHER=keep\nWHATSAPP_REPLIES_ACCESS_TOKEN="' + NEW + '"\n').encode())
        self.assertEqual(rotation.env_proposal(('WHATSAPP_REPLIES_ACCESS_TOKEN=\'' + NEW + '\'').encode(), NEW),
                         ('WHATSAPP_REPLIES_ACCESS_TOKEN=\'' + NEW + '\'').encode())
        for invalid in [b'WHATSAPP_REPLIES_ACCESS_TOKEN=x\nWHATSAPP_REPLIES_ACCESS_TOKEN=y\n',
                        b'WHATSAPP_REPLIES_ACCESS_TOKEN="${OTHER}"\n', b'OTHER="unclosed\n', b'\xef\xbb\xbfOTHER=x', b'OTHER=\x00']:
            with self.subTest(invalid=invalid):
                with self.assertRaises(rotation.SafeError): rotation.env_proposal(invalid, NEW)

    def test_hidden_input_no_echo_fallback_or_empty_token(self):
        class Tty(io.StringIO):
            def close(self): pass
        tty = Tty()
        opener = lambda *args, **kwargs: tty
        self.assertEqual(rotation.prompt_token(opener, lambda *args, **kwargs: NEW), NEW)
        def warning(*args, **kwargs):
            warnings.warn('fixture', getpass.GetPassWarning); return NEW
        with self.assertRaisesRegex(rotation.SafeError, '^SECURE_TERMINAL_INPUT_UNAVAILABLE$'):
            rotation.prompt_token(opener, warning)
        for invalid in ['', 'short', '1a' * 32, NEW + '\nextra']:
            with self.assertRaises(rotation.SafeError): rotation.prompt_token(opener, lambda *a, **k: invalid)

    def test_helper_hash_pin_and_no_meta_writes_in_preflight(self):
        _, diagnostic = rotation.verified_helper('whatsapp_catalog_access_diagnostic.php', rotation.DIAGNOSTIC_BLOB)
        self.assertEqual(rotation.base.blob_hash(diagnostic), rotation.DIAGNOSTIC_BLOB)
        with self.assertRaisesRegex(RuntimeError, '^PINNED_HELPER_HASH_NEEDS_REVIEW$'):
            rotation.verified_helper('whatsapp_catalog_access_diagnostic.php', '0' * 40)
        self.assertIn('WHATSAPP_CATALOG_ACCESS_DIAGNOSTIC_LIBRARY_ONLY', rotation.PREFLIGHT_PHP)
        self.assertNotIn('->extract', rotation.PREFLIGHT_PHP)
        for script in [rotation.READ_CURRENT_PHP, rotation.PREFLIGHT_PHP, rotation.CACHE_CANDIDATE_PHP, rotation.SMOKE_PHP]:
            result = subprocess.run([PHP, '-l'], input=('<?php\n' + script).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(result.returncode, 0, result.stdout.decode())


if __name__ == '__main__':
    unittest.main(verbosity=2)
