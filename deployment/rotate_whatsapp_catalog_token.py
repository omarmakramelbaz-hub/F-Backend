#!/usr/bin/env python3
"""Rotate only the WhatsApp reply token after fixed read-only catalog probes."""
import fcntl
import getpass
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile
import warnings
from dataclasses import replace

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
DIAGNOSTIC_BLOB = 'bb97e51c54181f7694ecb9918668fb668affabf3'
CACHE_PATH = 'bootstrap/cache/config.php'
TARGET = 'WHATSAPP_REPLIES_ACCESS_TOKEN'
TOKEN = re.compile(r'[A-Za-z0-9._~-]{32,4096}')
ASSIGNMENT = re.compile(r'^([ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*)(.*)$')


def verified_helper(name, wanted):
    path = pathlib.Path(__file__).absolute().parent / name
    for parent in reversed(path.parents):
        metadata = parent.lstat()
        if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_PINNED_HELPER_PATH')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        metadata = os.fstat(descriptor)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
                or metadata.st_uid not in (0, os.geteuid()) or metadata.st_mode & 0o022
                or metadata.st_size > 1024 * 1024):
            raise RuntimeError('UNSAFE_PINNED_HELPER_OWNER_OR_TYPE')
        content = os.read(descriptor, 1024 * 1024 + 1)
        after = os.fstat(descriptor)
        if (metadata.st_size, metadata.st_mtime_ns, metadata.st_ctime_ns) != (
                after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('PINNED_HELPER_CHANGED_DURING_READ')
    finally:
        os.close(descriptor)
    observed = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
    if observed != wanted:
        raise RuntimeError('PINNED_HELPER_HASH_NEEDS_REVIEW')
    return path, content


def load_base():
    path, content = verified_helper('install_whatsapp_inbox.py', BASE_BLOB)
    name = 'whatsapp_catalog_rotation_verified_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


base = load_base()
SafeError = base.SafeError


def secure_snapshot(path, uid, root, absent_ok=False):
    item = base.snapshot(path, uid, root, absent_ok=absent_ok)
    if item is not None:
        metadata = path.lstat()
        if (metadata.st_nlink != 1 or metadata.st_mode & 0o022
                or (metadata.st_dev, metadata.st_ino) != (item.device, item.inode)):
            raise SafeError('SECRET_FILE_LINK_OR_PERMISSIONS_NEEDS_REVIEW')
    return item


def validate_token(token):
    if (not isinstance(token, str) or TOKEN.fullmatch(token) is None
            or re.fullmatch(r'[A-Fa-f0-9]{32,}', token) is not None):
        raise SafeError('INVALID_WHATSAPP_TOKEN_INPUT')
    return token


def prompt_token(opener=open, hidden=getpass.getpass):
    try:
        with warnings.catch_warnings():
            warnings.simplefilter('error', getpass.GetPassWarning)
            with opener('/dev/tty', 'w', encoding='utf-8') as tty:
                return validate_token(hidden('New WhatsApp + catalog token (hidden): ', stream=tty).strip())
    except (OSError, EOFError, KeyboardInterrupt, getpass.GetPassWarning):
        raise SafeError('SECURE_TERMINAL_INPUT_UNAVAILABLE') from None


def env_proposal(content, token):
    """Replace one literal value, without normalizing any other dotenv bytes."""
    validate_token(token)
    try:
        text = content.decode('utf-8')
    except UnicodeError:
        raise SafeError('ENV_ENCODING_NEEDS_REVIEW') from None
    if text.startswith('\ufeff') or '\x00' in text:
        raise SafeError('ENV_ENCODING_NEEDS_REVIEW')
    lines, found, quote = text.splitlines(keepends=True), None, None
    for index, line in enumerate(lines):
        body = line.rstrip('\r\n')
        if quote is None:
            match = ASSIGNMENT.match(body)
            if not match:
                continue
            prefix, name, rhs = match.groups()
            if name == TARGET:
                if found is not None:
                    raise SafeError('DUPLICATE_TOKEN_ENV_SETTING')
                literal = re.fullmatch(r'(?:"([A-Za-z0-9._~-]*)"|\'([A-Za-z0-9._~-]*)\'|([A-Za-z0-9._~-]*))([ \t]*(?:#.*)?)', rhs)
                if literal is None:
                    raise SafeError('TOKEN_ENV_LITERAL_NEEDS_REVIEW')
                value = next(v for v in literal.groups()[:3] if v is not None)
                ending = '\r\n' if line.endswith('\r\n') else '\n' if line.endswith('\n') else ''
                found = (index, prefix, value, literal.group(4), ending)
                continue
            value = rhs.lstrip(' \t')
            if value[:1] not in ('\'', '"'):
                continue
            quote, scan = value[0], value[1:]
        else:
            scan = body
        escaped = False
        for char in scan:
            if escaped:
                escaped = False
            elif char == '\\':
                escaped = True
            elif char == quote:
                quote = None
                break
    if quote is not None:
        raise SafeError('ENV_QUOTE_NEEDS_REVIEW')
    if found is None:
        newline = '\r\n' if '\r\n' in text else '\n'
        return (text + ('' if not text or text.endswith(('\n', '\r')) else newline)
                + TARGET + '="' + token + '"' + newline).encode('utf-8')
    index, prefix, current, comment, ending = found
    if current != token:
        lines[index] = prefix + '"' + token + '"' + comment + ending
    return ''.join(lines).encode('utf-8')


PHP_BOOTSTRAP = r'''
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('whatsapp_orders.mode') !== 'review') exit(1);
'''
PHP_CLOSE = r'''
} catch (\Throwable $error) { exit(1); }
'''

READ_CURRENT_PHP = PHP_BOOTSTRAP + r'''
    $all = config()->all();
    if (!is_array($all['whatsapp_replies'] ?? null)
        || !array_key_exists('access_token', $all['whatsapp_replies'])) exit(1);
    $bytes = serialize($all);
    if (file_put_contents($argv[1], $bytes) !== strlen($bytes) || !chmod($argv[1], 0600)) exit(1);
    exit(0);
''' + PHP_CLOSE

PREFLIGHT_PHP = PHP_BOOTSTRAP + r'''
    $input = json_decode(file_get_contents($argv[1]), true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_keys($input) !== ['token'] || !is_string($input['token'])) exit(1);
    $bytes = file_get_contents($argv[2]);
    if (!is_string($bytes) || sha1('blob ' . strlen($bytes) . "\0" . $bytes) !==
        'bb97e51c54181f7694ecb9918668fb668affabf3') exit(1);
    define('WHATSAPP_CATALOG_ACCESS_DIAGNOSTIC_LIBRARY_ONLY', true);
    eval(substr($bytes, 5));
    $secret = config('whatsapp.app_secret');
    $report = (new \App\Support\WhatsAppCatalogAccessDiagnostic())->inspect(
        $input['token'], is_string($secret) ? $secret : null);
    $phone = ['status' => 'FAILED', 'http' => null, 'phone_matches' => false];
    $curl = null;
    try {
        if (!function_exists('curl_init')) throw new \RuntimeException();
        $body = ''; $curl = curl_init('https://graph.facebook.com/v25.0/515388018324075?fields=id');
        curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $input['token'], 'Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        if (curl_exec($curl) === false) throw new \RuntimeException();
        $phone['http'] = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $phone['phone_matches'] = is_array($decoded) && !isset($decoded['error'])
            && ($decoded['id'] ?? null) === '515388018324075';
        if ($phone['http'] === 200 && $phone['phone_matches']) $phone['status'] = 'READY';
    } catch (\Throwable $error) {
        $phone['phone_matches'] = false;
    } finally {
        if ($curl !== null) curl_close($curl);
    }
    $report['phone_access_check'] = $phone;
    unset($input, $secret);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($report, JSON_THROW_ON_ERROR); exit(0);
''' + PHP_CLOSE

# Parse the usual Laravel var_export cache, preserving all formatting and comments.
# No cached namespace is rebuilt. A strict value comparison validates the one edit.
CACHE_CANDIDATE_PHP = r'''
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    $bytes = file_get_contents($argv[1]);
    $input = json_decode(file_get_contents($argv[2]), true, 4, JSON_THROW_ON_ERROR);
    if (!is_string($bytes) || !is_array($input) || array_keys($input) !== ['token']
        || !is_string($input['token'])) exit(1);
    $tokens = []; $offset = 0;
    foreach (token_get_all($bytes, TOKEN_PARSE) as $raw) {
        $id = is_array($raw) ? $raw[0] : null; $text = is_array($raw) ? $raw[1] : $raw;
        if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true))
            $tokens[] = [$id, $text, $offset];
        $offset += strlen($text);
    }
    $i = 0; $locations = [];
    $parse = function (array $path) use (&$parse, &$tokens, &$i, &$locations) {
        $first = $tokens[$i] ?? null;
        if ($first === null) throw new \RuntimeException();
        if ($first[0] === T_ARRAY || $first[1] === '[') {
            if ($first[0] === T_ARRAY) {
                $i++; if (($tokens[$i][1] ?? null) !== '(') throw new \RuntimeException();
                $close = ')';
            } else $close = ']';
            $i++; $keys = [];
            while (($tokens[$i][1] ?? null) !== $close) {
                $key = $tokens[$i] ?? null;
                if ($key === null || !in_array($key[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true)) throw new \RuntimeException();
                $value = $key[0] === T_LNUMBER ? (int) $key[1] : eval('return ' . $key[1] . ';');
                $identity = serialize($value);
                if (isset($keys[$identity])) throw new \RuntimeException();
                $keys[$identity] = true; $i++;
                if (($tokens[$i][0] ?? null) !== T_DOUBLE_ARROW) throw new \RuntimeException();
                $i++; $parse(array_merge($path, [$value]));
                if (($tokens[$i][1] ?? null) === ',') $i++;
                elseif (($tokens[$i][1] ?? null) !== $close) throw new \RuntimeException();
            }
            $i++; return;
        }
        if ($path === ['whatsapp_replies', 'access_token']) {
            if ($first[0] !== T_CONSTANT_ENCAPSED_STRING
                && !($first[0] === T_STRING && strtolower($first[1]) === 'null')) throw new \RuntimeException();
            $locations[] = [$first[2], strlen($first[1])];
        }
        if ($first[1] === '-') {
            $i++; $first = $tokens[$i] ?? null;
            if (!in_array($first[0] ?? null, [T_LNUMBER, T_DNUMBER], true)) throw new \RuntimeException();
        } elseif (!in_array($first[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)
            && !($first[0] === T_STRING && in_array(strtolower($first[1]), ['true', 'false', 'null'], true))) throw new \RuntimeException();
        $i++;
    };
    if (($tokens[$i++][0] ?? null) !== T_RETURN) exit(1);
    if (!in_array($tokens[$i][0] ?? null, [T_ARRAY], true) && ($tokens[$i][1] ?? null) !== '[') exit(1);
    $parse([]);
    if (($tokens[$i++][1] ?? null) !== ';' || $i !== count($tokens) || count($locations) !== 1) exit(1);
    $original = require $argv[1];
    if (!is_array($original) || !is_array($original['whatsapp_replies'] ?? null)
        || !array_key_exists('access_token', $original['whatsapp_replies'])) exit(1);
    $expected = $original; $expected['whatsapp_replies']['access_token'] = $input['token'];
    [$start, $length] = $locations[0];
    $candidate = substr_replace($bytes, var_export($input['token'], true), $start, $length);
    if (file_put_contents($argv[3], $candidate) !== strlen($candidate) || !chmod($argv[3], 0600)
        || (require $argv[3]) !== $expected) exit(1);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

SMOKE_PHP = PHP_BOOTSTRAP + r'''
    $original = unserialize(file_get_contents($argv[1]), ['allowed_classes' => false]);
    $input = json_decode(file_get_contents($argv[2]), true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($original) || !is_array($input) || array_keys($input) !== ['token']
        || !is_array($original['whatsapp_replies'] ?? null)) exit(1);
    $original['whatsapp_replies']['access_token'] = $input['token'];
    if (config()->all() !== $original) exit(1);
    while (ob_get_level() > 0) ob_end_clean();
    echo '{"token_updated":true,"other_configuration_preserved":true,"mode_review":true}'; exit(0);
''' + PHP_CLOSE


def command(root, arguments, capture=False, timeout=120):
    try:
        result = subprocess.run(arguments, cwd=root, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=timeout, check=False)
        if capture and len(result.stdout) > 65536:
            return 1, b''
        return result.returncode, result.stdout if capture else b''
    except (OSError, subprocess.TimeoutExpired):
        return 1, b''


def write_private(path, content):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(content); stream.flush(); os.fsync(stream.fileno())


def preflight_ready(report):
    if not isinstance(report, dict):
        return False
    scope = report.get('token_scope_check')
    if not isinstance(scope, dict) or scope.get('status') != 'READY':
        return False
    positives = ('token_valid', 'app_matches', 'catalog_management',
                 'whatsapp_business_management', 'whatsapp_business_messaging')
    probes = report.get('probes')
    phone = report.get('phone_access_check')
    if (not isinstance(probes, list) or not isinstance(phone, dict)
            or phone.get('status') != 'READY' or phone.get('http') != 200
            or phone.get('phone_matches') is not True):
        return False
    return (all(scope.get(key) is True for key in positives)
            and report.get('conclusion') == 'REQUESTED_PRODUCTS_READ_WORKS'
            and report.get('writes') == 0 and type(report.get('writes')) is int
            and any(isinstance(item, dict) and item.get('probe') == 'PRODUCTS_FIELDS'
                    and item.get('status') == 'READY' and item.get('http') == 200
                    for item in probes))


def rotate(root, uid, runner=command, prompt=prompt_token):
    base.validate_root(root, uid)
    _, diagnostic = verified_helper('whatsapp_catalog_access_diagnostic.php', DIAGNOSTIC_BLOB)
    parent = root.parent / 'whatsapp-release-backups'; base.private_parent(parent, uid)
    lock = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock)
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o600):
            raise SafeError('TOKEN_ROTATION_LOCK_NEEDS_REVIEW')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_ORDERS_INSTALL_OR_CONFIGURATION_IS_RUNNING') from None
        before = {'.env': secure_snapshot(root / '.env', uid, root),
                  CACHE_PATH: secure_snapshot(root / CACHE_PATH, uid, root, absent_ok=True)}
        backup = pathlib.Path(tempfile.mkdtemp(prefix='catalog-token-', dir=parent)); backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('BACKUP_AND_PROJECT_REQUIRE_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700); base.probe_atomic_exchange(publications)
        for relative, item in before.items():
            if item is not None:
                write_private(backup / ('env.before' if relative == '.env' else 'config.before.php'), item.content)
        current_path = backup / 'effective.before.bin'
        code, _ = runner(root, ['php', '-r', READ_CURRENT_PHP, str(current_path)])
        if code != 0:
            raise SafeError('TOKEN_ROTATION_REQUIRES_EXISTING_REVIEW_CONFIGURATION')
        secure_snapshot(current_path, uid, root)
        token = validate_token(prompt())
        proposals = {'.env': env_proposal(before['.env'].content, token)}
        input_path = backup / 'token.json'; write_private(input_path, json.dumps({'token': token}).encode())
        diagnostic_path = backup / 'catalog-access.php'; write_private(diagnostic_path, diagnostic)
        code, output = runner(root, ['php', '-r', PREFLIGHT_PHP, str(input_path), str(diagnostic_path)], capture=True)
        try:
            report = json.loads(output)
        except (ValueError, TypeError):
            report = None
        if code != 0 or not preflight_ready(report):
            raise SafeError('TOKEN_PREFLIGHT_FAILED_EXISTING_CONFIGURATION_UNCHANGED')
        if before[CACHE_PATH] is not None:
            candidate = backup / 'config.candidate.php'
            code, _ = runner(root, ['php', '-r', CACHE_CANDIDATE_PHP,
                                    str(backup / 'config.before.php'), str(input_path), str(candidate)])
            if code != 0:
                raise SafeError('TOKEN_CACHE_STAGING_FAILED_CONFIGURATION_UNCHANGED')
            proposals[CACHE_PATH] = secure_snapshot(candidate, uid, root).content
            if runner(root, ['php', '-l', str(candidate)])[0] != 0:
                raise SafeError('TOKEN_CACHE_LINT_FAILED_CONFIGURATION_UNCHANGED')
        written, created = {}, []
        try:
            for relative, original in before.items():
                base.assert_snapshot(root / relative, original, uid, root)
            for relative, content in proposals.items():
                original = before[relative]
                if content != original.content or original.mode != 0o600:
                    base.replace_file(root / relative, content, original, uid, root, created,
                                      restore=replace(original, mode=0o600), journal=written,
                                      relative=relative, private_directory=publications)
            code, output = runner(root, ['php', '-r', SMOKE_PHP, str(current_path), str(input_path)], capture=True)
            expected = {'token_updated': True, 'other_configuration_preserved': True, 'mode_review': True}
            try:
                readiness = json.loads(output)
            except (ValueError, TypeError):
                readiness = None
            if (code != 0 or readiness != expected or not isinstance(readiness, dict)
                    or any(type(value) is not bool for value in readiness.values())):
                raise SafeError('TOKEN_EFFECTIVE_CONFIGURATION_CHECK_FAILED')
            for relative, original in before.items():
                expected_file = written[relative].after if relative in written else original
                base.assert_snapshot(root / relative, expected_file, uid, root)
            return 'WHATSAPP_CATALOG_TOKEN_ROTATED_REVIEW_ONLY', backup, expected
        except BaseException:
            restored = True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try:
                        restored = base.recover_publication(publication, root, uid) and restored
                    except BaseException:
                        restored = False
                for relative, original in before.items():
                    try:
                        restored = secure_snapshot(root / relative, uid, root, absent_ok=True) == original and restored
                    except BaseException:
                        restored = False
            status = ('TOKEN_CONFIGURATION_RESTORED' if restored
                      else 'TOKEN_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED')
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
    finally:
        os.close(lock)


def main():
    try:
        if sys.argv[1:] != ['--root', str(APP_ROOT)] or os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_AS_FASAKHA_FOR_CONFIRMED_PROJECT_ROOT')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('TOKEN_ROTATION_INTERRUPTED')))
        status, backup, readiness = rotate(APP_ROOT, os.geteuid())
        print(status); print('BACKUP=' + str(backup)); print('READINESS=' + json.dumps(readiness, sort_keys=True))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('TOKEN_ROTATION_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
