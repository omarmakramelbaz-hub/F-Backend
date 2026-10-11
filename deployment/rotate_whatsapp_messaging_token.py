#!/usr/bin/env python3
"""Rotate the messaging credential while preserving cached values and atomic rollback."""
import importlib.util
import json
import os
import pathlib
import pwd
import signal
import stat
import subprocess
import sys

ROTATION_BLOB = 'eaf40b872ec1dde0cbffa08e507e56a03f62591f'


def load_rotation():
    import hashlib
    path = pathlib.Path(__file__).absolute().with_name('rotate_whatsapp_catalog_token.py')
    for parent in reversed(path.parents):
        s = parent.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_PINNED_HELPER_PATH')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        s = os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_nlink != 1
                or s.st_uid not in (0, os.geteuid()) or s.st_mode & 0o022
                or s.st_size > 1048576):
            raise RuntimeError('UNSAFE_PINNED_HELPER_FILE')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            content = stream.read(1048577)
        after = os.fstat(fd)
        if (s.st_size, s.st_mtime_ns, s.st_ctime_ns) != (
                after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('PINNED_HELPER_CHANGED')
    finally:
        os.close(fd)
    if hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() != ROTATION_BLOB:
        raise RuntimeError('PINNED_HELPER_HASH_MISMATCH')
    spec = importlib.util.spec_from_file_location('verified_wa_messaging_base', path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


rotation = load_rotation()
SafeError = rotation.SafeError
APP_ROOT = rotation.APP_ROOT
seen = {}

SKIP_SIBLING_PHP = r'''
        if ($path !== [] && $path !== ['whatsapp_replies']
            && $path !== ['whatsapp_replies', 'access_token']) {
            $start = $i; $stack = [];
            $pairs = ['(' => ')', '[' => ']', '{' => '}'];
            while (isset($tokens[$i])) {
                [$id, $text] = $tokens[$i];
                if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $stack[] = '}';
                } elseif ($id === null) {
                    if (isset($pairs[$text])) $stack[] = $pairs[$text];
                    elseif (in_array($text, [')', ']', '}'], true)) {
                        if (!$stack) break;
                        if (array_pop($stack) !== $text) throw new \RuntimeException();
                    } elseif (($text === ',' || $text === ';') && !$stack) break;
                }
                $i++;
            }
            if ($i === $start || $stack) throw new \RuntimeException();
            return;
        }
'''


def replace_once(source, before, after):
    if source.count(before) != 1:
        raise RuntimeError('PINNED_PATCH_MARKER_MISMATCH')
    return source.replace(before, after)


def build_cache_candidate_php():
    source = rotation.CACHE_CANDIDATE_PHP
    prefix = "ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();\ntry {\n"
    source = replace_once(source, prefix, rotation.PHP_BOOTSTRAP + r'''
    $waEffective = config()->all();
    $waBefore = file_get_contents(dirname($argv[2]) . '/effective.before.bin');
    if (!is_string($waBefore) || serialize($waEffective) !== $waBefore
        || !is_array($waEffective['whatsapp_replies'] ?? null)) exit(1);
''')
    source = replace_once(
        source, '        if ($first[0] === T_ARRAY || $first[1] === \'[\') {',
        SKIP_SIBLING_PHP + '        if ($first[0] === T_ARRAY || $first[1] === \'[\') {')
    source = replace_once(
        source, '|| (require $argv[3]) !== $expected',
        '|| serialize(require $argv[3]) !== serialize($expected)')
    source = replace_once(source, '    exit(0);\n} catch', r'''
    $waEffective['whatsapp_replies']['access_token'] = $input['token'];
    $waExpected = serialize($waEffective);
    $waExpectedPath = dirname($argv[2]) . '/effective.expected.bin';
    $waStream = fopen($waExpectedPath, 'xb');
    if ($waStream === false) exit(1);
    $waWritten = fwrite($waStream, $waExpected);
    $waFlushed = fflush($waStream);
    fclose($waStream);
    if ($waWritten !== strlen($waExpected) || !$waFlushed
        || !chmod($waExpectedPath, 0600)) exit(1);
    exit(0);
} catch''')
    return source


CACHE_CANDIDATE_PHP = build_cache_candidate_php()
SMOKE_PHP = rotation.PHP_BOOTSTRAP + r'''
    $input = json_decode(file_get_contents($argv[2]), true, 4, JSON_THROW_ON_ERROR);
    $expected = file_get_contents(dirname($argv[2]) . '/effective.expected.bin');
    if (!is_array($input) || array_keys($input) !== ['token']
        || !is_string($expected)
        || config('whatsapp_replies.access_token') !== $input['token']
        || serialize(config()->all()) !== $expected) exit(1);
    while (ob_get_level() > 0) ob_end_clean();
    echo '{"token_updated":true,"other_configuration_preserved":true,"mode_review":true}'; exit(0);
''' + rotation.PHP_CLOSE

PREFLIGHT_PHP = replace_once(
    rotation.PREFLIGHT_PHP,
    '515388018324075?fields=id',
    '515388018324075?fields=id%2Cis_on_biz_app%2Cplatform_type')
PREFLIGHT_PHP = replace_once(
    PREFLIGHT_PHP,
    '$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);',
    r'''$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $phone['is_on_biz_app'] = is_array($decoded)
            && ($decoded['is_on_biz_app'] ?? null) === true;
        $phone['cloud_api'] = is_array($decoded)
            && ($decoded['platform_type'] ?? null) === 'CLOUD_API';''')


def preflight_ready(report):
    if not isinstance(report, dict):
        return False
    scope = report.get('token_scope_check')
    phone = report.get('phone_access_check')
    probes = report.get('probes')
    if not isinstance(scope, dict) or not isinstance(phone, dict) or not isinstance(probes, list):
        return False
    seen['catalog_readable'] = (
        report.get('conclusion') == 'REQUESTED_PRODUCTS_READ_WORKS'
        and any(isinstance(p, dict) and p.get('probe') == 'PRODUCTS_FIELDS'
                and p.get('status') == 'READY' and p.get('http') == 200 for p in probes))
    return (report.get('diagnostic') == 'READ_ONLY_CATALOG_ACCESS'
            and type(report.get('writes')) is int and report['writes'] == 0
            and scope.get('status') == 'READY'
            and all(scope.get(k) is True for k in (
                'token_valid', 'app_matches', 'whatsapp_business_management',
                'whatsapp_business_messaging'))
            and phone.get('status') == 'READY' and phone.get('http') == 200
            and all(phone.get(k) is True for k in ('phone_matches', 'is_on_biz_app', 'cloud_api')))


def private_directory(path, uid):
    s = path.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid != uid or stat.S_IMODE(s.st_mode) != 0o700:
        raise SafeError('TOKEN_BACKUP_DIRECTORY_NEEDS_REVIEW')


def private_snapshot(path, root, uid):
    item = rotation.secure_snapshot(path, uid, root)
    if item.mode != 0o600 or not item.content:
        raise SafeError('TOKEN_BACKUP_FILE_NEEDS_REVIEW')
    return item


def reuse_latest_token(root, uid):
    """Called by rotate() only after acquiring its existing configuration lock."""
    parent = root.parent / 'whatsapp-release-backups'
    private_directory(parent, uid)
    candidates = list(parent.glob('catalog-token-*/token.json'))
    if not candidates:
        raise SafeError('NO_PRIVATE_TOKEN_ATTEMPT_FOUND')
    path = max(candidates, key=lambda p: p.lstat().st_mtime_ns)
    private_directory(path.parent, uid)
    token_file = private_snapshot(path, root, uid)
    if len(token_file.content) > 131072:
        raise SafeError('TOKEN_BACKUP_FILE_NEEDS_REVIEW')
    for live, saved in (('.env', 'env.before'),
                        (rotation.CACHE_PATH, 'config.before.php')):
        previous = private_snapshot(path.parent / saved, root, uid)
        current = rotation.secure_snapshot(root / live, uid, root)
        if current.content != previous.content:
            raise SafeError('CONFIGURATION_CHANGED_SINCE_TOKEN_ATTEMPT')
    try:
        data = json.loads(token_file.content)
    except (TypeError, ValueError):
        raise SafeError('INVALID_PRIVATE_TOKEN_ATTEMPT') from None
    if not isinstance(data, dict) or set(data) != {'token'}:
        raise SafeError('INVALID_PRIVATE_TOKEN_ATTEMPT')
    return rotation.validate_token(data['token'])


def messaging_runner(root, arguments, capture=False, timeout=120):
    code, output = rotation.command(root, arguments, capture=capture, timeout=timeout)
    if code == 0 and len(arguments) > 2 and arguments[:2] == ['php', '-r']:
        if arguments[2] == CACHE_CANDIDATE_PHP:
            expected = pathlib.Path(arguments[4]).parent / 'effective.expected.bin'
            try:
                private_snapshot(expected, root, os.geteuid())
            except Exception:
                return 1, b''
    return code, output


def main():
    try:
        if sys.argv[1:] not in (['--root', str(APP_ROOT)],
                               ['--root', str(APP_ROOT), '--reuse-latest-token']):
            raise SafeError('CONFIRMED_PROJECT_ROOT_ARGUMENT_REQUIRED')
        uid = os.geteuid()
        if uid != pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_AS_FASAKHA_FOR_CONFIRMED_PROJECT_ROOT')
        os.umask(0o077)
        os.environ['PATH'] = '/opt/cpanel/ea-php82/root/usr/bin:' + os.environ.get('PATH', '')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('TOKEN_ROTATION_INTERRUPTED')))
        for php in (CACHE_CANDIDATE_PHP, SMOKE_PHP, PREFLIGHT_PHP):
            lint = subprocess.run(
                ['php', '-l'], input=('<?php\n' + php).encode(),
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
            if lint.returncode != 0:
                raise SafeError('MESSAGING_HELPER_PHP_LINT_FAILED')
        rotation.CACHE_CANDIDATE_PHP = CACHE_CANDIDATE_PHP
        rotation.SMOKE_PHP = SMOKE_PHP
        rotation.PREFLIGHT_PHP = PREFLIGHT_PHP
        rotation.preflight_ready = preflight_ready
        prompt = (lambda: reuse_latest_token(APP_ROOT, uid)) if '--reuse-latest-token' in sys.argv else rotation.prompt_token
        if rotation.secure_snapshot(APP_ROOT / rotation.CACHE_PATH, uid, APP_ROOT, absent_ok=True) is None:
            raise SafeError('MESSAGING_ROTATION_REQUIRES_EXISTING_CACHE')
        _, backup, readiness = rotation.rotate(APP_ROOT, uid, runner=messaging_runner, prompt=prompt)
        print('WHATSAPP_MESSAGING_TOKEN_SAVED_REVIEW_ONLY')
        print('BACKUP=' + str(backup))
        print('READINESS=' + json.dumps({
            **readiness, 'messaging_credential_checked': True,
            'catalog_readable': seen.get('catalog_readable', False)}, sort_keys=True))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('MESSAGING_TOKEN_UPDATE_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
