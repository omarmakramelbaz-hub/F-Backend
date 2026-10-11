#!/usr/bin/env python3
"""Exercise real cache expressions and messaging-only publication safeguards."""
import copy
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


DEPLOYMENT = pathlib.Path(__file__).resolve().parent.parent / 'deployment'
PHP = os.environ.get('WHATSAPP_TEST_PHP', 'php')
OLD = 'EAA' + 'old_reply_token_' * 5
NEW = 'EAA' + 'new_reply_token_' * 5
REPORT = {
    'diagnostic': 'READ_ONLY_CATALOG_ACCESS',
    'writes': 0,
    'conclusion': 'ACCESS_OR_REQUEST_UNRESOLVED',
    'token_scope_check': {
        'status': 'READY', 'token_valid': True, 'app_matches': True,
        'catalog_management': True, 'business_management': True,
        'whatsapp_business_management': True,
        'whatsapp_business_messaging': True,
    },
    'phone_access_check': {
        'status': 'READY', 'http': 200, 'phone_matches': True,
        'is_on_biz_app': True, 'cloud_api': True,
    },
    'probes': [
        {'probe': 'WABA_CATALOG_ASSOCIATION', 'status': 'API_ERROR',
         'http': 400, 'code': 10},
        {'probe': 'PRODUCTS_FIELDS', 'status': 'API_ERROR',
         'http': 400, 'code': 100, 'subcode': 33},
    ],
}

AUTOLOAD = r'''<?php
namespace CacheFixture {
    enum Mode: string { case Stable = 'stable'; }
    final class Value {
        public function __construct(public array $data) {}
        public static function __set_state(array $properties): self {
            return new self($properties['data']);
        }
    }
}
'''

BOOTSTRAP = r'''<?php
class FixtureConfig {
    public array $values = [];
    public function all(): array { return $this->values; }
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
    public function bootstrap(): void {
        global $fixture;
        $fixture->values = require 'bootstrap/cache/config.php';
    }
}
class FixtureApp {
    public function make($class): FixtureKernel { return new FixtureKernel(); }
}
return new FixtureApp();
'''

CACHE_TEMPLATE = r'''<?php
// Formatting and unrelated expressions must survive byte for byte.
return array (
  'unrelated_before' => array (
    'mode' => \CacheFixture\Mode::Stable,
    'state' => \CacheFixture\Value::__set_state(array (
      'data' => array ('text' => 'commas, [brackets] {braces}', 'list' => array (4, -7)),
    )),
    'same_token' => '__OLD__',
    'positional' => array (true, false, null, -0.25, array (3, 8)),
  ),
  'whatsapp_orders' => array ('mode' => 'review', 'enabled' => true),
  'whatsapp_replies' => array (
    'access_token' => '__OLD__', // Keep the adjacent comment.
    'future' => \CacheFixture\Value::__set_state(array ('data' => array ('voice' => true))),
  ),
  'unrelated_after' => array ('mode' => \CacheFixture\Mode::Stable),
);
'''


def load_helper():
    spec = importlib.util.spec_from_file_location(
        'tested_whatsapp_messaging_token_rotation',
        DEPLOYMENT / 'rotate_whatsapp_messaging_token.py')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


@unittest.skipUnless(shutil.which(PHP), 'real PHP executable is required')
class CacheExpressionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.helper = load_helper()
        cls.rotation = cls.helper.rotation

    def setUp(self):
        self.area = pathlib.Path(tempfile.mkdtemp(prefix='wa-messaging-tests-'))
        self.root = self.area / 'project'
        (self.root / 'vendor').mkdir(parents=True)
        (self.root / 'bootstrap/cache').mkdir(parents=True)
        (self.root / '.git').mkdir()
        (self.root / 'vendor/autoload.php').write_text(AUTOLOAD)
        (self.root / 'bootstrap/app.php').write_text(BOOTSTRAP)
        self.cache = self.root / 'bootstrap/cache/config.php'
        self.original = CACHE_TEMPLATE.replace('__OLD__', OLD).encode()
        self.cache.write_bytes(self.original)
        self.cache.chmod(0o600)
        self.env = self.root / '.env'
        self.env.write_bytes(('APP_KEY=unchanged\r\n'
                              'WHATSAPP_REPLIES_ACCESS_TOKEN="' + OLD
                              + '" # keep annotation\r\n').encode())
        self.env.chmod(0o600)
        self.original_env = self.env.read_bytes()
        self.backup = self.area / 'attempt'
        self.backup.mkdir(mode=0o700)
        self.before = self.backup / 'config.before.php'
        self.before.write_bytes(self.original)
        self.before.chmod(0o600)
        self.token = self.backup / 'token.json'
        self.token.write_text(json.dumps({'token': NEW}))
        self.token.chmod(0o600)
        self.effective = self.backup / 'effective.before.bin'
        result = self.run_php(self.rotation.READ_CURRENT_PHP, self.effective)
        self.assertEqual(result.returncode, 0)
        self.candidate = self.backup / 'config.candidate.php'

    def tearDown(self):
        shutil.rmtree(self.area)

    def run_php(self, code, *paths):
        return subprocess.run(
            [PHP, '-r', code, *map(str, paths)], cwd=self.root,
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=20)

    def stage(self):
        return self.run_php(self.helper.CACHE_CANDIDATE_PHP,
                            self.before, self.token, self.candidate)

    def smoke(self):
        return self.run_php(self.helper.SMOKE_PHP, self.effective, self.token)

    def test_qualified_enum_and_objects_preserve_other_cache_bytes(self):
        result = self.stage()
        self.assertEqual(result.returncode, 0)
        expected = self.original.replace(
            ("'access_token' => '" + OLD + "'").encode(),
            ("'access_token' => '" + NEW + "'").encode(), 1)
        self.assertEqual(self.candidate.read_bytes(), expected)
        self.assertEqual(self.cache.read_bytes(), self.original)
        self.assertEqual(stat.S_IMODE(self.candidate.stat().st_mode), 0o600)
        self.cache.write_bytes(self.candidate.read_bytes())
        smoke = self.smoke()
        self.assertEqual(smoke.returncode, 0)
        self.assertEqual(json.loads(smoke.stdout), {
            'token_updated': True, 'other_configuration_preserved': True,
            'mode_review': True})

    def test_smoke_rejects_changed_unrelated_object_property(self):
        self.assertEqual(self.stage().returncode, 0)
        self.cache.write_bytes(self.candidate.read_bytes().replace(
            b"'voice' => true", b"'voice' => false"))
        self.assertNotEqual(self.smoke().returncode, 0)

    def test_duplicate_target_and_nonliteral_target_are_rejected(self):
        marker = ("'access_token' => '" + OLD + "'").encode()
        for replacement in (
            marker + b", 'access_token' => 'duplicate'",
            b"'access_token' => ('prefix' . 'suffix')",
            b"'access_token' => array ('invalid')",
        ):
            with self.subTest(replacement=replacement):
                self.before.write_bytes(self.original.replace(marker, replacement))
                if self.candidate.exists():
                    self.candidate.unlink()
                self.assertNotEqual(self.stage().returncode, 0)
                self.assertEqual(self.cache.read_bytes(), self.original)

    def test_effective_configuration_drift_rejects_staging(self):
        self.cache.write_bytes(self.original.replace(b"'voice' => true", b"'voice' => false"))
        self.assertNotEqual(self.stage().returncode, 0)
        self.assertFalse(self.candidate.exists())

    def test_review_mode_is_required_before_staging(self):
        self.cache.write_bytes(self.original.replace(b"'mode' => 'review'", b"'mode' => 'active'"))
        self.assertNotEqual(self.stage().returncode, 0)
        self.assertFalse(self.candidate.exists())

    def rotate(self, fail_smoke=False):
        def runner(root, arguments, capture=False, timeout=120):
            if len(arguments) > 2 and arguments[2] == self.helper.PREFLIGHT_PHP:
                return 0, json.dumps(REPORT).encode()
            if (fail_smoke and len(arguments) > 2
                    and arguments[2] == self.helper.SMOKE_PHP):
                return 1, b''
            result = subprocess.run(
                [PHP, *arguments[1:]], cwd=root,
                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                timeout=timeout)
            return result.returncode, result.stdout if capture else b''
        with mock.patch.multiple(
                self.rotation,
                CACHE_CANDIDATE_PHP=self.helper.CACHE_CANDIDATE_PHP,
                SMOKE_PHP=self.helper.SMOKE_PHP,
                PREFLIGHT_PHP=self.helper.PREFLIGHT_PHP,
                preflight_ready=self.helper.preflight_ready):
            return self.rotation.rotate(
                self.root, os.geteuid(), runner=runner, prompt=lambda: NEW)

    def test_full_atomic_rotation_preserves_enum_objects_and_unrelated_bytes(self):
        _, backup, readiness = self.rotate()
        self.assertEqual(readiness, {
            'token_updated': True, 'other_configuration_preserved': True,
            'mode_review': True})
        self.assertEqual(self.env.read_bytes(), self.original_env.replace(
            OLD.encode(), NEW.encode()))
        self.assertEqual(self.cache.read_bytes(), self.original.replace(
            ("'access_token' => '" + OLD + "'").encode(),
            ("'access_token' => '" + NEW + "'").encode(), 1))
        self.assertEqual((backup / 'env.before').read_bytes(), self.original_env)
        self.assertEqual((backup / 'config.before.php').read_bytes(), self.original)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)

    def test_smoke_failure_restores_both_live_files_exactly(self):
        with self.assertRaisesRegex(
                self.rotation.SafeError, '^TOKEN_CONFIGURATION_RESTORED; BACKUP='):
            self.rotate(fail_smoke=True)
        self.assertEqual(self.env.read_bytes(), self.original_env)
        self.assertEqual(self.cache.read_bytes(), self.original)


class MessagingGuardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.helper = load_helper()

    def test_smb_catalog_failure_does_not_block_valid_messaging_token(self):
        self.assertTrue(self.helper.preflight_ready(copy.deepcopy(REPORT)))
        self.assertIs(self.helper.seen['catalog_readable'], False)

    def test_each_required_boolean_is_strict_and_catalog_success_is_independent(self):
        fields = {
            'token_scope_check': ('token_valid', 'app_matches',
                                  'whatsapp_business_management',
                                  'whatsapp_business_messaging'),
            'phone_access_check': ('phone_matches', 'is_on_biz_app', 'cloud_api'),
        }
        for namespace, keys in fields.items():
            for key in keys:
                for invalid in (False, None, 1, 'true'):
                    with self.subTest(namespace=namespace, key=key, invalid=invalid):
                        report = copy.deepcopy(REPORT)
                        report[namespace][key] = invalid
                        self.assertFalse(self.helper.preflight_ready(report))
        report = copy.deepcopy(REPORT)
        report['token_scope_check']['catalog_management'] = False
        report['token_scope_check']['business_management'] = False
        self.assertTrue(self.helper.preflight_ready(report))
        self.assertIs(self.helper.seen['catalog_readable'], False)

    def test_diagnostic_counter_and_identity_gate_candidate(self):
        for patch in (
            {'writes': False}, {'writes': '0'}, {'writes': 1},
            {'diagnostic': 'OTHER'}, {'phone_access_check': None},
            {'probes': None}, {'token_scope_check': None},
        ):
            with self.subTest(patch=patch):
                report = copy.deepcopy(REPORT)
                report.update(patch)
                self.assertFalse(self.helper.preflight_ready(report))


class PrivateCandidateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.helper = load_helper()

    def setUp(self):
        self.area = pathlib.Path(tempfile.mkdtemp(prefix='wa-private-candidate-tests-'))
        self.root = self.area / 'project'
        (self.root / 'bootstrap/cache').mkdir(parents=True)
        self.cache = self.root / 'bootstrap/cache/config.php'
        self.env = self.root / '.env'
        self.env.write_bytes(b'APP_KEY=unchanged\n')
        self.cache.write_bytes(b'<?php return [];\n')
        self.env.chmod(0o600)
        self.cache.chmod(0o600)
        self.parent = self.area / 'whatsapp-release-backups'
        self.parent.mkdir(mode=0o700)
        self.attempt = self.parent / 'catalog-token-previous'
        self.attempt.mkdir(mode=0o700)
        for name, content in (
            ('token.json', json.dumps({'token': NEW}).encode()),
            ('env.before', self.env.read_bytes()),
            ('config.before.php', self.cache.read_bytes()),
        ):
            path = self.attempt / name
            path.write_bytes(content)
            path.chmod(0o600)

    def tearDown(self):
        shutil.rmtree(self.area)

    def reuse(self):
        return self.helper.reuse_latest_token(self.root, os.geteuid())

    def test_private_candidate_reuse_preserves_live_configuration(self):
        before = self.env.read_bytes(), self.cache.read_bytes()
        self.assertEqual(self.reuse(), NEW)
        self.assertEqual((self.env.read_bytes(), self.cache.read_bytes()), before)

    def test_live_environment_or_cache_drift_refuses_reuse(self):
        for live in (self.env, self.cache):
            with self.subTest(live=live.name):
                before = live.read_bytes()
                live.write_bytes(before + b'changed')
                with self.assertRaisesRegex(
                        self.helper.SafeError, 'CONFIGURATION_CHANGED_SINCE_TOKEN_ATTEMPT'):
                    self.reuse()
                live.write_bytes(before)

    def test_nonprivate_or_symlink_candidate_refused(self):
        token = self.attempt / 'token.json'
        token.chmod(0o644)
        with self.assertRaises(self.helper.SafeError):
            self.reuse()
        token.chmod(0o600)
        contents = token.read_bytes()
        token.unlink()
        outside = self.area / 'outside-token.json'
        outside.write_bytes(contents)
        outside.chmod(0o600)
        token.symlink_to(outside)
        with self.assertRaises(self.helper.SafeError):
            self.reuse()


if __name__ == '__main__':
    if os.environ.get('WHATSAPP_REQUIRE_PHP_TESTS') == '1' and not shutil.which(PHP):
        raise SystemExit('PHP_REQUIRED_FOR_MESSAGING_ROTATION_TESTS')
    unittest.main()
