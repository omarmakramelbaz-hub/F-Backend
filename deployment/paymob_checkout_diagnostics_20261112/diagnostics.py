#!/usr/bin/env python3
"""Read-only, secret-redacted inspection of the two deployed checkout methods."""
import sys
sys.dont_write_bytecode = True

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
FILES = {
    'app/Http/Controllers/Payment/PaymobController.php': 'checkingOut',
    'app/Http/Controllers/Api/V1/User/WalletController.php': 'charging_wallet',
}
MAX_BYTES = 131072
PACKAGE = Path(__file__).resolve().parent

PHP_SANITIZER = r'''
if (!function_exists('token_get_all')) {
    echo json_encode(['status' => 'TOKENIZER_UNAVAILABLE']); exit;
}
try {
    $source = stream_get_contents(STDIN, 131073);
    $method = $argv[1] ?? '';
    if (strlen($source) > 131072 || !in_array($method, ['checkingOut', 'charging_wallet'], true)) {
        throw new RuntimeException('INPUT_INVALID');
    }
    $strings = [
        'token', 'content-type', 'Content-Type', 'Accept', 'application/json', 'Authorization', 'Bearer ',
        'auth_token', 'delivery_needed', 'false', 'amount_cents', 'merchant_order_id', 'message', 'duplicate',
        'results', 'id', 'currency', 'EGP', 'integration_id', 'payment_methods', 'expiration', 'billing_data',
        'first_name', 'last_name', 'phone_number', 'email', 'apartment', 'floor', 'street', 'building',
        'shipping_method', 'postal_code', 'city', 'state', 'country', 'NA', 'metadata', 'client_secret',
        'intention_order_id', 'order_id', 'user_id', 'total_price', 'link', 'wallet', 'current', 'pending',
        'charging', 'from_user', 'to_user', 'type', 'status', 'amount', 'online', 'v_cash', 'visa', 'api', 'admin',
        'Success', 'Error', 'app.url', 'api/*', 'payment_method', 'paymob_card_payment', 'paymob_mobile_wallet_payment',
        'PAYMOB_SECRET_KEY', 'PAYMOB_PUBLIC_KEY', 'PAYMOB_API_KEY', 'PAYMOB_CARD_INTEGRATION_ID',
        'PAYMOB_CARD_IFRAME_ID', 'PAYMOB_MOBILE_WALLET_INTEGRATION_ID',
        'services.paymob.secret_key', 'services.paymob.public_key', 'services.paymob.api_key',
        'paymob.secret_key', 'paymob.public_key', 'paymob.api_key', 'wallet_topups.api_key',
        'api.wallet charge successfully', 'api.please update your info and enter email first', 'error',
        'https://accept.paymob.com/api/auth/tokens', 'https://accept.paymob.com/api/auth/tokens/',
        'https://accept.paymob.com/api/ecommerce/orders', 'https://accept.paymob.com/api/ecommerce/orders/',
        'https://accept.paymob.com/v1/intention', 'https://accept.paymob.com/v1/intention/',
        'https://accept.paymob.com/unifiedcheckout/', 'https://fasakhaninja.com',
        'https://fasakhaninja.com/pay-thanks', 'https://fasakhaninja.com/pay-false',
        '/api/wallet-topup/paymob/notify', '/api/wallet-topup/paymob/return',
        '/api/order-payments/paymob/notify', '/api/order-payments/paymob/return',
    ];
    $identifiers = [
        'checkingOut', 'charging_wallet', '__construct', 'Http', 'Auth', 'Order', 'Payment', 'Wallet', 'PaymobController',
        'Illuminate\\Support\\Facades\\Http', '\\Illuminate\\Support\\Facades\\Http',
        'Illuminate\\Support\\Facades\\Auth', '\\Illuminate\\Support\\Facades\\Auth',
        'App\\Models\\Order', '\\App\\Models\\Order', 'App\\Models\\Payment', '\\App\\Models\\Payment',
        'App\\Http\\Controllers\\Payment\\PaymobController', '\\App\\Http\\Controllers\\Payment\\PaymobController',
        'GeneralSettings', 'JWTAuth', 'User', 'Validator', 'Notification', 'config', 'env', 'auth',
        'response', 'redirect', 'app', 'request', 'rtrim', 'trim', 'count', 'explode', 'ceil', 'preg_split',
        'preg_match', 'is_array', 'is_string', 'is_int', 'is_float', 'is_finite', 'in_array', 'rawurlencode',
        'str_pad', 'intdiv', 'filter_var', 'withHeaders', 'withOptions', 'post', 'get', 'json', 'successful',
        'findOrFail', 'user', 'wantsJson', 'is', 'successResponse', 'errorResponse', 'createdResponse',
        'check', 'create', 'save', 'back', 'with', 'name', 'mobile', 'email', 'balance', 'grand_total',
        'user_tax', 'type', 'id', 'user_id', 'wallet_id', 'pending_vendor', 'payment', 'status', 'amount',
        'to_user', 'from_user', 'getLocale', 'true', 'false', 'null', 'FILTER_VALIDATE_EMAIL', 'STR_PAD_RIGHT',
        'STR_PAD_LEFT', '__', 'payment_method', 'total_price', 'intention_order_id', 'client_secret',
    ];
    $variables = [
        '$this', '$payment_method', '$integration_id', '$order_id', '$iframe_id_or_wallet_number', '$request',
        '$response', '$json', '$token', '$order', '$order_data', '$response_final', '$json_final', '$existing_order',
        '$paymob_order_id', '$amount_cents', '$user', '$name', '$first_name', '$last_name', '$response_final_final',
        '$response_final_final_json', '$client_secret', '$intention_order_id', '$pay', '$wallet', '$data',
        '$amount', '$amountCents', '$amountDecimal', '$payload', '$result', '$detail', '$clientSecret', '$gatewayOrder',
        '$gatewayAmount', '$amountMatches', '$amountParts', '$firstName', '$lastName', '$names', '$base', '$link',
        '$paymobAuthorization', '$paymobPublicKey', '$failure', '$error', '$arabic', '$message', '$code', '$uncertain',
    ];
    $keywords = ['public', 'private', 'protected', 'static', 'final', 'abstract', 'function', 'fn', 'if', 'elseif',
        'else', 'return', 'new', 'throw', 'try', 'catch', 'finally', 'foreach', 'as', 'for', 'while', 'do', 'switch',
        'case', 'default', 'break', 'continue', 'array', 'unset', 'isset', 'empty', 'echo', 'print', 'use', 'namespace',
        'class', 'extends', 'implements', 'const', 'readonly', 'and', 'or', 'xor', 'instanceof', 'int', 'string', 'bool',
        'float', 'void', 'mixed', '(int)', '(integer)', '(float)', '(double)', '(string)', '(bool)', '(boolean)',
        '(array)', '(object)', '(unset)'];
    $tokens = token_get_all($source);
    function token_text($token) { return is_array($token) ? $token[1] : $token; }
    function opaque_identifier($text, $variable = false) {
        return ($variable ? '$' : '') . 'redacted_identifier_' . substr(hash('sha256', $text), 0, 12);
    }
    function consume_heredoc($tokens, $index) {
        for ($j = $index + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_END_HEREDOC) return $j;
        }
        throw new RuntimeException('UNTERMINATED_LITERAL');
    }
    function consume_interpolation($tokens, $index) {
        $delimiter = $tokens[$index]; $depth = 0;
        for ($j = $index + 1; $j < count($tokens); $j++) {
            $token = $tokens[$j];
            if (is_array($token)) {
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
                elseif ($token[0] === T_START_HEREDOC) $j = consume_heredoc($tokens, $j);
                continue;
            }
            if ($depth === 0 && $token === $delimiter) return $j;
            if ($depth > 0) {
                if ($token === '"' || $token === '`') $j = consume_interpolation($tokens, $j);
                elseif ($token === '{') $depth++;
                elseif ($token === '}') $depth--;
            }
        }
        throw new RuntimeException('UNTERMINATED_LITERAL');
    }
    $units = []; $line = 1;
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i]; $raw = token_text($token); $id = is_array($token) ? $token[0] : null;
        $startLine = $line;
        if ($id === T_START_HEREDOC || $token === '"' || $token === '`') {
            $end = $id === T_START_HEREDOC ? consume_heredoc($tokens, $i) : consume_interpolation($tokens, $i);
            $raw = '';
            for ($j = $i; $j <= $end; $j++) $raw .= token_text($tokens[$j]);
            $i = $end; $id = T_CONSTANT_ENCAPSED_STRING; $safe = "'[redacted string]'";
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
            $value = substr($raw, 1, -1);
            // Emit a canonical safe spelling, never the original escape sequence.
            $safe = in_array($value, $strings, true) ? "'" . $value . "'" : "'[redacted string]'";
        } elseif (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE,
            T_START_HEREDOC, T_END_HEREDOC], true)) {
            $safe = ' '; $id = T_WHITESPACE;
        } elseif (in_array($id, [T_LNUMBER, T_DNUMBER, T_NUM_STRING], true)) {
            $safe = "'[redacted number]'";
        } elseif ($id === T_VARIABLE) {
            $safe = in_array($raw, $variables, true) ? $raw : opaque_identifier($raw, true);
        } elseif ($id === T_STRING || in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $safe = in_array($raw, $identifiers, true) ? $raw : opaque_identifier($raw);
        } elseif ($id === T_WHITESPACE) {
            $safe = $raw;
        } elseif (in_array(strtolower(trim($raw)), $keywords, true)
            || preg_match('/\A[(){}\[\];,:.?=+*\/\%&|!~<>@\\\\-]+\z/', $raw)) {
            $safe = $raw;
        } else {
            $safe = ' redacted_token ';
        }
        $line += substr_count($raw, "\n");
        $units[] = ['id' => $id, 'raw' => $raw, 'safe' => $safe, 'line' => $startLine];
    }
    function function_excerpt($units, $name, $optional = false) {
        $matches = [];
        foreach ($units as $i => $unit) {
            if ($unit['id'] !== T_FUNCTION) continue;
            for ($j = $i + 1; $j < count($units); $j++) {
                if ($units[$j]['id'] === T_STRING) {
                    if ($units[$j]['raw'] === $name) $matches[] = $i;
                    break;
                }
                if ($units[$j]['raw'] === '(' || $units[$j]['raw'] === '{') break;
            }
        }
        if (!$matches && $optional) return null;
        if (count($matches) !== 1) throw new RuntimeException('METHOD_COUNT_INVALID');
        $start = $matches[0];
        while ($start > 0 && in_array($units[$start - 1]['id'], [T_WHITESPACE, T_PUBLIC, T_PRIVATE,
            T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT], true)) $start--;
        $open = null; $parentheses = 0;
        for ($j = $matches[0] + 1; $j < count($units); $j++) {
            if ($units[$j]['id'] !== null) continue;
            if ($units[$j]['raw'] === '(') $parentheses++;
            elseif ($units[$j]['raw'] === ')') $parentheses--;
            elseif ($parentheses === 0 && $units[$j]['raw'] === '{') { $open = $j; break; }
            elseif ($parentheses === 0 && $units[$j]['raw'] === ';') break;
        }
        if ($open === null) throw new RuntimeException('METHOD_BODY_MISSING');
        $depth = 0; $end = null;
        for ($j = $open; $j < count($units); $j++) {
            if ($units[$j]['id'] !== null) continue;
            if ($units[$j]['raw'] === '{') $depth++;
            elseif ($units[$j]['raw'] === '}' && --$depth === 0) { $end = $j; break; }
        }
        if ($end === null) throw new RuntimeException('METHOD_BODY_MISSING');
        $raw = ''; $safe = '';
        for ($j = $start; $j <= $end; $j++) { $raw .= $units[$j]['raw']; $safe .= $units[$j]['safe']; }
        if (strlen($raw) > 49152 || strlen($safe) > 32768) throw new RuntimeException('METHOD_TOO_LARGE');
        return ['method' => $name, 'start_line' => $units[$start]['line'], 'raw_sha256' => hash('sha256', $raw),
            'structure_sha256' => hash('sha256', preg_replace('/\s+/', ' ', trim($safe))), 'source' => trim($safe)];
    }
    $expectedImports = [
        'Http' => 'Illuminate\\Support\\Facades\\Http', 'Auth' => 'Illuminate\\Support\\Facades\\Auth',
        'Order' => 'App\\Models\\Order', 'Payment' => 'App\\Models\\Payment',
    ];
    $imports = array_fill_keys(array_keys($expectedImports), false); $namespace = null; $unknownImports = [];
    for ($i = 0; $i < count($units); $i++) {
        if (in_array($units[$i]['id'], [T_CLASS, T_INTERFACE, T_TRAIT], true)) break;
        if ($units[$i]['id'] === T_NAMESPACE || $units[$i]['id'] === T_USE) {
            $kind = $units[$i]['id']; $text = '';
            $stops = $kind === T_NAMESPACE ? [';', '{'] : [';'];
            for ($j = $i + 1; $j < count($units) && !in_array($units[$j]['raw'], $stops, true); $j++) {
                if ($units[$j]['id'] !== T_WHITESPACE) $text .= $units[$j]['raw'];
            }
            if ($kind === T_NAMESPACE) {
                $namespace = in_array($text, ['App\\Http\\Controllers\\Payment', 'App\\Http\\Controllers\\Api\\V1\\User'], true)
                    ? $text : '[redacted namespace]';
            } else {
                $known = false;
                foreach ($expectedImports as $alias => $fqcn) {
                    if ($text === $fqcn || $text === $fqcn . 'as' . $alias) { $imports[$alias] = true; $known = true; }
                }
                $group = strpos($text, '\\{');
                if (!$known && $group !== false && substr($text, -1) === '}') {
                    $prefix = substr($text, 0, $group);
                    $members = explode(',', substr($text, $group + 2, -1)); $matched = [];
                    foreach ($members as $member) {
                        foreach ($expectedImports as $alias => $fqcn) {
                            if ($prefix . '\\' . $member === $fqcn) $matched[] = $alias;
                        }
                    }
                    if (count($matched) === count($members)) {
                        foreach ($matched as $alias) $imports[$alias] = true;
                        $known = true;
                    }
                }
                if (!$known) $unknownImports[] = hash('sha256', $text);
            }
        }
    }
    $whole = implode('', array_column($units, 'safe'));
    $report = ['status' => 'OK', 'namespace' => $namespace, 'expected_imports' => $imports,
        'other_import_hashes' => $unknownImports,
        'file_structure_sha256' => hash('sha256', preg_replace('/\s+/', ' ', trim($whole))),
        'method' => function_excerpt($units, $method), 'constructor' => function_excerpt($units, '__construct', true)];
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Never return exceptions, raw source, token content, or credential values.
    echo json_encode(['status' => 'SANITIZER_UNSUPPORTED']);
}
'''


def digest(value):
    return hashlib.sha256(value).hexdigest()


def read_source(root, name):
    if name not in FILES or root.resolve() != root or not root.is_dir():
        raise RuntimeError('SOURCE_PATH_INVALID')
    path = root / name
    for parent in list(path.parents)[:len(Path(name).parts) - 1]:
        if parent.is_symlink() or not parent.is_dir():
            raise RuntimeError('SOURCE_PATH_INVALID')
    before = path.lstat()
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > MAX_BYTES:
        raise RuntimeError('SOURCE_TYPE_INVALID')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        opened = os.fstat(source.fileno())
        if (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino):
            raise RuntimeError('SOURCE_CHANGED')
        if not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1 or opened.st_size > MAX_BYTES:
            raise RuntimeError('SOURCE_TYPE_INVALID')
        value = source.read(MAX_BYTES + 1)
        after = os.fstat(source.fileno())
    fields = ('st_dev', 'st_ino', 'st_uid', 'st_gid', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    if len(value) > MAX_BYTES or any(getattr(opened, key) != getattr(after, key) for key in fields):
        raise RuntimeError('SOURCE_CHANGED')
    return value, {'sha256': digest(value), 'bytes': len(value), 'uid': after.st_uid, 'gid': after.st_gid,
        'mode': format(stat.S_IMODE(after.st_mode), '04o'), 'crlf_lines': value.count(b'\r\n'),
        'utf8_bom': value.startswith(b'\xef\xbb\xbf')}


def sanitize_source(source, method, php=PHP):
    if method not in FILES.values() or len(source) > MAX_BYTES:
        raise RuntimeError('INPUT_INVALID')
    base = [php, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0']
    for extension in ([], ['-d', 'extension=tokenizer']):
        try:
            result = subprocess.run([*base, *extension, '-r', PHP_SANITIZER, method], input=source,
                capture_output=True, timeout=8, check=False)
            if result.returncode != 0 or len(result.stdout) > 98304:
                return {'status': 'SANITIZER_UNAVAILABLE'}
            report = json.loads(result.stdout)
            if not isinstance(report, dict):
                return {'status': 'SANITIZER_UNAVAILABLE'}
            if report.get('status') == 'TOKENIZER_UNAVAILABLE':
                continue
            if report.get('status') not in ('OK', 'SANITIZER_UNSUPPORTED'):
                return {'status': 'SANITIZER_UNAVAILABLE'}
            return report
        except (OSError, subprocess.TimeoutExpired, ValueError):
            return {'status': 'SANITIZER_UNAVAILABLE'}
    return {'status': 'TOKENIZER_UNAVAILABLE'}


def load_publisher(path):
    spec = importlib.util.spec_from_file_location('paymob_diagnostic_transform', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def candidate_hash(source, name, publisher, template):
    try:
        if name == publisher.CONTROLLER:
            candidate = publisher.controller_target(source, template)
        elif name == publisher.WALLET:
            candidate = publisher.wallet_target(source)
        else:
            return {'status': 'UNSUPPORTED'}
        return {'status': 'TRANSFORMED_IN_MEMORY', 'sha256': digest(candidate)}
    except Exception:
        return {'status': 'UNSUPPORTED'}


def inspect_sources(root, publisher, template, php=PHP):
    reports = {}
    for name, method in FILES.items():
        try:
            source, metadata = read_source(root, name)
            reports[name] = {'metadata': metadata, 'sanitized': sanitize_source(source, method, php),
                'candidate': candidate_hash(source, name, publisher, template)}
        except (OSError, RuntimeError):
            reports[name] = {'status': 'SOURCE_UNAVAILABLE'}
    return reports


def main():
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('SERVER_IDENTITY_MISMATCH')
    if ROOT.resolve() != ROOT or not ROOT.is_dir():
        raise RuntimeError('APPLICATION_ROOT_INVALID')
    publisher_path = PACKAGE / 'publish.py'
    template = (PACKAGE / 'checking_out.php.template').read_bytes()
    publisher = load_publisher(publisher_path)
    report = {'diagnostic': 'PAYMOB_SOURCE_READ_ONLY_V1',
        'publisher_sha256': digest(publisher_path.read_bytes()), 'template_sha256': digest(template),
        'files': inspect_sources(ROOT, publisher, template)}
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, ValueError, UnicodeError):
        raise SystemExit('READ_ONLY_DIAGNOSTIC_UNAVAILABLE') from None
