import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch


PACKAGE = Path(__file__).resolve().parents[1]
ROOT = PACKAGE.parents[1]
CREATION = ROOT / 'deployment/paymob_checkout_speed_20261112'
SPEC = importlib.util.spec_from_file_location('checkout_diagnostic', PACKAGE / 'diagnostics.py')
diag = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(diag)
PUBLISHER = diag.load_publisher(CREATION / 'publish.py')
TEMPLATE = (CREATION / 'checking_out.php.template').read_bytes()
PHP = shutil.which('php')
SECRET = 'SYNTHETIC_PRIVATE_SENTINEL_8d91fcb0'
CONTROLLER = ('''<?php
namespace App\\Http\\Controllers\\Payment;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Auth;
use App\\Models\\Order;
use App\\Models\\Payment;
class PaymobController {
    public function __construct() { $this->privateValue = 'PRIVATE_CONSTRUCTOR_VALUE'; }
    public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)
    {
        $json['token'] = 'Token SYNTHETIC_PRIVATE_SENTINEL_8d91fcb0';
        $link = 'https://accept.paymob.com/unifiedcheckout/?publicKey=SYNTHETIC_PUBLIC_SENTINEL&clientSecret=';
        return $this->successResponse(['order_id' => $order_id, 'link' => $link]);
    }
    public function unrelated() { return 'OUTSIDE_METHOD_PRIVATE_VALUE'; }
}
''').encode()
WALLET = br'''<?php
namespace App\Http\Controllers\Api\V1\User;
class WalletController {
    public function charging_wallet($request) {
        if($user->email==null ){
            return $this->successResponse($user->balance,__('api.please update your info and enter email first'));
        }
        $wallet=Wallet::create([]);
    }
    public function unrelated() { return 'OUTSIDE_WALLET_PRIVATE_VALUE'; }
}
'''


class ReaderAndCandidateTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name) / 'application'
        self.root.mkdir()
        for name, content in zip(diag.FILES, [CONTROLLER, WALLET]):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)

    def tearDown(self):
        self.temporary.cleanup()

    def test_reader_metadata_without_mutation(self):
        name = next(iter(diag.FILES))
        value, metadata = diag.read_source(self.root, name)
        self.assertEqual(CONTROLLER, value)
        self.assertEqual(hashlib.sha256(CONTROLLER).hexdigest(), metadata['sha256'])
        self.assertEqual(os.getuid(), metadata['uid'])
        self.assertEqual(len(CONTROLLER), metadata['bytes'])
        self.assertEqual(CONTROLLER, (self.root / name).read_bytes())

    def test_read_scope_symlinks_hardlinks_and_oversize_rejected(self):
        with self.assertRaises(RuntimeError):
            diag.read_source(self.root, '.env')
        name = next(iter(diag.FILES)); path = self.root / name; other = path.with_suffix('.original')
        path.rename(other); path.symlink_to(other)
        with self.assertRaises(RuntimeError): diag.read_source(self.root, name)
        path.unlink(); os.link(other, path)
        with self.assertRaises(RuntimeError): diag.read_source(self.root, name)
        path.unlink(); path.write_bytes(b'x' * (diag.MAX_BYTES + 1))
        with self.assertRaises(RuntimeError): diag.read_source(self.root, name)

    def test_candidate_is_hash_only_and_does_not_write_or_execute_source(self):
        name = next(iter(diag.FILES))
        marker = Path(self.temporary.name) / 'must-not-exist'
        source = CONTROLLER.replace(b'<?php', ('<?php file_put_contents(' + repr(str(marker)) + ", 'executed');").encode(), 1)
        before = {str(path): path.read_bytes() for path in self.root.rglob('*') if path.is_file()}
        candidate = diag.candidate_hash(source, name, PUBLISHER, TEMPLATE)
        self.assertEqual({'status', 'sha256'}, set(candidate))
        self.assertEqual('TRANSFORMED_IN_MEMORY', candidate['status'])
        self.assertRegex(candidate['sha256'], '^[a-f0-9]{64}$')
        self.assertNotIn(SECRET, json.dumps(candidate))
        self.assertFalse(marker.exists())
        self.assertEqual(before, {str(path): path.read_bytes() for path in self.root.rglob('*') if path.is_file()})

    def test_candidate_unsupported_exception_never_exposes_source(self):
        class Exploding:
            CONTROLLER = next(iter(diag.FILES))
            WALLET = list(diag.FILES)[1]
            def controller_target(self, *args): raise RuntimeError(SECRET)
        result = diag.candidate_hash(CONTROLLER, next(iter(diag.FILES)), Exploding(), TEMPLATE)
        self.assertEqual({'status': 'UNSUPPORTED'}, result)

    def test_wallet_candidate_remains_memory_only(self):
        name = list(diag.FILES)[1]
        result = diag.candidate_hash(WALLET, name, PUBLISHER, TEMPLATE)
        self.assertEqual('TRANSFORMED_IN_MEMORY', result['status'])
        self.assertEqual(WALLET, (self.root / name).read_bytes())

    def test_php_errors_and_stderr_are_not_forwarded(self):
        class Failure:
            returncode = 1
            stdout = SECRET.encode()
            stderr = SECRET.encode()
        with patch.object(diag.subprocess, 'run', return_value=Failure()):
            self.assertEqual({'status': 'SANITIZER_UNAVAILABLE'}, diag.sanitize_source(CONTROLLER, 'checkingOut', 'php'))


@unittest.skipUnless(PHP, 'PHP runtime required; the focused CI installs PHP 8.2')
class TokenizerTests(unittest.TestCase):
    def sanitize(self, source):
        result = diag.sanitize_source(source, 'checkingOut', PHP)
        self.assertEqual('OK', result['status'], result)
        return result

    def test_private_literals_comments_constructor_and_outer_methods_removed(self):
        source = CONTROLLER.replace(b"$json['token']", ("/* " + SECRET + " */\n $json['token']").encode(), 1)
        result = self.sanitize(source)
        encoded = json.dumps(result)
        for private in [SECRET, 'PRIVATE_CONSTRUCTOR_VALUE', 'OUTSIDE_METHOD_PRIVATE_VALUE', 'SYNTHETIC_PUBLIC_SENTINEL']:
            self.assertNotIn(private, encoded)
        self.assertEqual('App\\Http\\Controllers\\Payment', result['namespace'])
        self.assertEqual({'Http': True, 'Auth': True, 'Order': True, 'Payment': True}, result['expected_imports'])
        self.assertIn("'order_id'", result['method']['source'])
        self.assertEqual('__construct', result['constructor']['method'])
        self.assertNotIn('unrelated', result['method']['source'])

    def test_quotes_heredoc_nowdoc_backticks_and_interpolation_never_leak(self):
        body = r'''
        $one = 'PRIVATE_SINGLE_QUOTE\'S_END';
        $two = "PRIVATE_DOUBLE_QUOTE\"END";
        $three = "PRIVATE_INTERPOLATED {$user->name} PRIVATE_INTERPOLATED_END";
        $four = "PRIVATE_NESTED {$user->{"PRIVATE_INNER_$token"}} PRIVATE_NESTED_END";
        $five = `PRIVATE_BACKTICK_$token`;
        $six = <<<PRIVATE_HEREDOC_LABEL
PRIVATE_HEREDOC_BODY {$user->name}
PRIVATE_HEREDOC_LABEL;
        $seven = <<<'PRIVATE_NOWDOC_LABEL'
PRIVATE_NOWDOC_BODY
PRIVATE_NOWDOC_LABEL;
        // PRIVATE_COMMENT_VALUE
        /** PRIVATE_DOC_VALUE */
        return ['amount' => 50];
        '''
        source = ('<?php class X { public function checkingOut() {' + body + '} }').encode()
        result = self.sanitize(source)
        self.assertNotIn('PRIVATE_', json.dumps(result))
        self.assertIn("'amount' => '[redacted number]'", result['method']['source'])

    def test_numeric_and_identifier_secrets_are_masked(self):
        source = b'''<?php class X { public function checkingOut() {
            $PRIVATE_IDENTIFIER_SECRET = 123456789012345;
            PRIVATE_IDENTIFIER_CALL(0xDEADBEEF, 1.23456789e20);
            $token = 123; $small = 7; $zero = 0;
            return ['amount' => 50];
        } }'''
        result = self.sanitize(source)
        for private in ['PRIVATE_IDENTIFIER', '123456789012345', 'DEADBEEF', '1.23456789e20']:
            self.assertNotIn(private, json.dumps(result))
        self.assertIn("$token = '[redacted number]'", result['method']['source'])
        self.assertNotRegex(result['method']['source'], r'=\s*(?:123|7|0)\s*;')

    def test_public_urls_require_exact_allowlist_and_never_retain_query_or_secret_path(self):
        source = b'''<?php class X { public function checkingOut() {
            $one = 'https://accept.paymob.com/v1/intention/';
            $two = 'https://accept.paymob.com/v1/intention/?token=PRIVATE_QUERY';
            $three = 'https://example.test/PRIVATE_PATH';
            $four = 'https://user:PRIVATE_PASSWORD@example.test/';
        } }'''
        result = self.sanitize(source)
        self.assertIn('https://accept.paymob.com/v1/intention/', result['method']['source'])
        self.assertNotIn('PRIVATE_', json.dumps(result))
        self.assertNotIn('example.test', result['method']['source'])

    def test_known_grouped_imports_and_fully_qualified_public_names_remain_reviewable(self):
        source = br'''<?php
        namespace App\Http\Controllers\Payment;
        use Illuminate\Support\Facades\{Auth, Http};
        use App\Models\{Order, Payment};
        class PaymobController { public function checkingOut() {
            return new \App\Http\Controllers\Payment\PaymobController();
        } }
        '''
        result = self.sanitize(source)
        self.assertEqual({'Http': True, 'Auth': True, 'Order': True, 'Payment': True}, result['expected_imports'])
        self.assertIn(r'\App\Http\Controllers\Payment\PaymobController', result['method']['source'])

    def test_source_is_tokenized_not_executed(self):
        with tempfile.TemporaryDirectory() as temporary:
            marker = Path(temporary) / 'executed'
            source = ('<?php file_put_contents(' + repr(str(marker)) + ", 'PRIVATE_EXECUTION'); "
                + 'class X { public function checkingOut() { return true; } }').encode()
            self.sanitize(source)
            self.assertFalse(marker.exists())

    def test_string_changes_preserve_structural_hash_without_exposing_values(self):
        first = self.sanitize(CONTROLLER)
        second = self.sanitize(CONTROLLER.replace(SECRET.encode(), b'ANOTHER_SYNTHETIC_SECRET'))
        self.assertEqual(first['method']['structure_sha256'], second['method']['structure_sha256'])
        self.assertNotEqual(first['method']['raw_sha256'], second['method']['raw_sha256'])


if __name__ == '__main__':
    unittest.main()
