#!/usr/bin/env python3
"""Configure optional order review and manual replies; never run AI or send messages."""
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
BASE_HELPER_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
CACHE_PATH = 'bootstrap/cache/config.php'
DEFAULT_MODEL = 'gpt-5.6-terra'
TARGETS = (
    'WHATSAPP_ORDERS_ENABLED', 'WHATSAPP_ORDERS_MODE', 'WHATSAPP_ORDERS_MODEL',
    'WHATSAPP_ORDERS_API_KEY', 'WHATSAPP_ORDERS_AUTOMATION_ACTOR_ID',
    'WHATSAPP_ORDERS_ALLOWED_BRANCH_IDS', 'WHATSAPP_ORDERS_ACTIVATION_MESSAGE_ID',
    'WHATSAPP_ORDERS_ACTIVATION_EVENT_ID', 'WHATSAPP_REPLIES_ENABLED', 'WHATSAPP_REPLIES_ACCESS_TOKEN',
)
ASSIGNMENT = re.compile(r'^([ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*)(.*)$')
ID = re.compile(r'[1-9][0-9]{0,18}')
KEY = re.compile(r'sk-[A-Za-z0-9_-]{16,500}')
MODEL = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{0,79}')
WA_TOKEN = re.compile(r'[A-Za-z0-9._~-]{32,4096}')


def load_base():
    path = pathlib.Path(__file__).absolute().parent / 'install_whatsapp_inbox.py'
    for parent in reversed(path.parents):
        metadata = parent.lstat()
        if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_BASE_HELPER_PATH')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        metadata = os.fstat(descriptor)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
                or metadata.st_uid not in (0, os.geteuid()) or metadata.st_mode & 0o022):
            raise RuntimeError('UNSAFE_BASE_HELPER_OWNER_OR_TYPE')
        content = os.read(descriptor, 1024 * 1024 + 1)
    finally:
        os.close(descriptor)
    observed = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
    if observed != BASE_HELPER_BLOB:
        raise RuntimeError('BASE_HELPER_HASH_NEEDS_REVIEW')
    name = 'whatsapp_orders_config_verified_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


base = load_base()
SafeError = base.SafeError


def secure_snapshot(path, uid, root, absent_ok=False):
    item = base.snapshot(path, uid, root, absent_ok=absent_ok)
    if item is None:
        return None
    metadata = path.lstat()
    if (metadata.st_nlink != 1 or metadata.st_mode & 0o022
            or (metadata.st_dev, metadata.st_ino) != (item.device, item.inode)):
        raise SafeError('SECRET_FILE_LINK_OR_PERMISSIONS_NEEDS_REVIEW')
    return item


def parse_env(content):
    """Locate only literal target settings, preserving every unrelated byte."""
    try:
        text = content.decode('utf-8')
    except UnicodeError:
        raise SafeError('ENV_ENCODING_NEEDS_REVIEW') from None
    if text.startswith('\ufeff') or '\x00' in text:
        raise SafeError('ENV_ENCODING_NEEDS_REVIEW')
    lines, found, quote = text.splitlines(keepends=True), {}, None
    for index, line in enumerate(lines):
        body = line.rstrip('\r\n')
        if quote is None:
            match = ASSIGNMENT.match(body)
            if not match:
                continue
            prefix, name, rhs = match.groups()
            if name in TARGETS:
                if name in found:
                    raise SafeError('DUPLICATE_ORDERS_ENV_SETTING')
                literal = re.fullmatch(r'(?:"([A-Za-z0-9._:,/+~-]*)"|\'([A-Za-z0-9._:,/+~-]*)\'|([A-Za-z0-9._:,/+~-]*))([ \t]*(?:#.*)?)', rhs)
                if literal is None:
                    raise SafeError('ORDERS_ENV_LITERAL_NEEDS_REVIEW')
                value = next(value for value in literal.groups()[:3] if value is not None)
                ending = '\r\n' if line.endswith('\r\n') else '\n' if line.endswith('\n') else ''
                found[name] = (index, prefix, value, literal.group(4), ending)
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
    return text, lines, found


def env_proposal(content, configuration):
    text, lines, found = parse_env(content)
    feature, replies = configuration['whatsapp_orders'], configuration['whatsapp_replies']
    values = {
        'WHATSAPP_ORDERS_ENABLED': 'true' if feature['enabled'] else 'false',
        'WHATSAPP_ORDERS_MODE': 'review',
        'WHATSAPP_ORDERS_MODEL': feature['model'] or '',
        'WHATSAPP_ORDERS_API_KEY': feature['api_key'] or '',
        'WHATSAPP_ORDERS_AUTOMATION_ACTOR_ID': feature['automation_actor_id'] or '',
        'WHATSAPP_ORDERS_ALLOWED_BRANCH_IDS': ','.join(feature['allowed_branch_ids']),
        'WHATSAPP_ORDERS_ACTIVATION_MESSAGE_ID': '' if feature['activation_message_id'] is None else str(feature['activation_message_id']),
        'WHATSAPP_ORDERS_ACTIVATION_EVENT_ID': '' if feature['activation_event_id'] is None else str(feature['activation_event_id']),
        'WHATSAPP_REPLIES_ENABLED': 'true' if replies['enabled'] else 'false',
        'WHATSAPP_REPLIES_ACCESS_TOKEN': replies['access_token'] or '',
    }
    newline = '\r\n' if '\r\n' in text else '\n'
    additions = []
    for name, value in values.items():
        encoded = value if name.endswith('_ENABLED') else '"' + value + '"'
        if name in found:
            index, prefix, current, comment, ending = found[name]
            # Literal values already identical remain byte-for-byte unchanged.
            if current != value:
                lines[index] = prefix + encoded + comment + ending
        else:
            additions.append(name + '=' + encoded + newline)
    result = ''.join(lines)
    if additions:
        result += '' if not result or result.endswith(('\n', '\r')) else newline
        result += ''.join(additions)
    return result.encode('utf-8')


def numeric_id(value, allow_empty=False):
    if allow_empty and value == '':
        return None
    if not isinstance(value, str) or ID.fullmatch(value) is None or int(value) > 9223372036854775807:
        raise SafeError('INVALID_ACTOR_OR_BRANCH_ID')
    return value


def validate_feature(feature):
    expected = {'enabled', 'mode', 'model', 'api_key', 'automation_actor_id',
                'allowed_branch_ids', 'activation_message_id', 'activation_event_id'}
    if not isinstance(feature, dict) or set(feature) != expected:
        raise SafeError('INVALID_REVIEW_CONFIGURATION')
    if type(feature['enabled']) is not bool or feature['mode'] != 'review':
        raise SafeError('ONLY_HUMAN_REVIEW_IS_SUPPORTED')
    if feature['enabled'] and (not isinstance(feature['api_key'], str) or KEY.fullmatch(feature['api_key']) is None):
        raise SafeError('INVALID_API_KEY_FORMAT')
    if not feature['enabled'] and feature['api_key'] is not None:
        raise SafeError('INVALID_API_KEY_FORMAT')
    if feature['enabled'] and (not isinstance(feature['model'], str) or MODEL.fullmatch(feature['model']) is None):
        raise SafeError('INVALID_MODEL_FORMAT')
    if not feature['enabled'] and feature['model'] is not None:
        raise SafeError('INVALID_MODEL_FORMAT')
    actor = feature['automation_actor_id']
    if actor is not None:
        numeric_id(actor)
    branches = feature['allowed_branch_ids']
    if (not isinstance(branches, list) or len(branches) > 100 or len(set(branches)) != len(branches)
            or (branches and actor is None)):
        raise SafeError('INVALID_BRANCH_ALLOWLIST')
    for branch in branches:
        numeric_id(branch)
    for name in ['activation_message_id', 'activation_event_id']:
        ceiling = feature[name]
        if ceiling is None and not feature['enabled']:
            continue
        if type(ceiling) is not int or not 0 <= ceiling <= 9223372036854775807:
            raise SafeError('INVALID_ACTIVATION_CEILING')


def validate_configuration(configuration):
    if not isinstance(configuration, dict) or set(configuration) != {'whatsapp_orders', 'whatsapp_replies'}:
        raise SafeError('INVALID_CONFIGURATION_SUBTREES')
    validate_feature(configuration['whatsapp_orders'])
    replies = configuration['whatsapp_replies']
    if not isinstance(replies, dict) or set(replies) != {'enabled', 'access_token'} or type(replies['enabled']) is not bool:
        raise SafeError('INVALID_REPLY_CONFIGURATION')
    token = replies['access_token']
    if replies['enabled']:
        if (not isinstance(token, str) or WA_TOKEN.fullmatch(token) is None
                or re.fullmatch(r'[A-Fa-f0-9]{32,}', token) is not None):
            raise SafeError('INVALID_WHATSAPP_ACCESS_TOKEN_FORMAT')
    elif token is not None:
        raise SafeError('INVALID_WHATSAPP_ACCESS_TOKEN_FORMAT')


def prompt_configuration(current, opener=open, hidden=getpass.getpass):
    """All input goes through a real controlling tty. getpass fallback is forbidden."""
    try:
        with warnings.catch_warnings():
            warnings.simplefilter('error', getpass.GetPassWarning)
            with opener('/dev/tty', 'r+', encoding='utf-8') as tty:
                key = hidden('OpenAI API key (hidden; Enter preserves existing key): ', stream=tty).strip()
                if not key:
                    key = current.get('api_key')
                if key in ('', None):
                    key = None
                if key is not None and (not isinstance(key, str) or KEY.fullmatch(key) is None):
                    raise SafeError('INVALID_API_KEY_FORMAT')
                token = hidden('WhatsApp access token (hidden; Enter preserves existing token): ', stream=tty).strip()
                if not token:
                    token = current.get('access_token')
                if token in ('', None):
                    token = None

                def ask(label):
                    tty.write(label)
                    tty.flush()
                    answer = tty.readline(513)
                    if not answer or len(answer) > 512 or not answer.endswith('\n'):
                        raise SafeError('SECURE_TERMINAL_INPUT_UNAVAILABLE')
                    return answer.strip()

                model = (ask('Model [gpt-5.6-terra]: ') or DEFAULT_MODEL) if key is not None else None
                actor = (numeric_id(ask('Automation actor ID (optional for human review; Enter leaves unset): '), True)
                         if key is not None else None)
                branches = []
                if actor is not None:
                    values = ask('Allowed restaurant branch IDs, comma separated (optional): ')
                    if values:
                        branches = list(dict.fromkeys(numeric_id(part.strip()) for part in values.split(',')))
    except (OSError, EOFError, getpass.GetPassWarning, KeyboardInterrupt):
        raise SafeError('SECURE_TERMINAL_INPUT_UNAVAILABLE') from None
    previously_enabled = current.get('enabled') is True
    ceiling = (current.get('activation_message_id') if previously_enabled else current.get('message_ceiling')) if key is not None else current.get('activation_message_id')
    event_ceiling = (current.get('activation_event_id') if previously_enabled else current.get('event_ceiling')) if key is not None else current.get('activation_event_id')
    feature = {'enabled': key is not None, 'mode': 'review', 'model': model, 'api_key': key,
               'automation_actor_id': actor, 'allowed_branch_ids': branches,
               'activation_message_id': ceiling, 'activation_event_id': event_ceiling}
    configuration = {'whatsapp_orders': feature,
                     'whatsapp_replies': {'enabled': token is not None, 'access_token': token}}
    validate_configuration(configuration)
    return configuration


READ_CURRENT_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->getCachedConfigPath() !== getcwd() . '/bootstrap/cache/config.php') exit(1);
    foreach (['whatsapp_inbox_conversations', 'whatsapp_inbox_messages', 'whatsapp_order_drafts', 'whatsapp_order_scans'] as $table) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) exit(1);
    }
    $config = config('whatsapp_orders');
    $replies = config('whatsapp_replies');
    if (!is_array($config) || !is_array($replies) || !array_key_exists('activation_message_id', $config)
        || !array_key_exists('activation_event_id', $config)) exit(1);
    $boundary = static function ($value) {
        if ($value === '') return null;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value)
            && (strlen($value) < strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) <= 0))) return (int) $value;
        return $value;
    };
    $ceiling = (int) \Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages as m')
        ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
        ->where('c.waba_id', '468336579702269')->where('c.phone_number_id', '515388018324075')->max('m.id');
    $state = ['enabled' => ($config['enabled'] ?? null) === true,
        'api_key' => $config['api_key'] ?? null, 'activation_message_id' => $boundary($config['activation_message_id']),
        'activation_event_id' => $boundary($config['activation_event_id']),
        'message_ceiling' => $ceiling,
        'event_ceiling' => (int) \Illuminate\Support\Facades\DB::table('whatsapp_webhook_events')->max('id'),
        'access_token' => $replies['access_token'] ?? null];
    $bytes = json_encode($state, JSON_THROW_ON_ERROR);
    if (file_put_contents($argv[1], $bytes) !== strlen($bytes)) exit(1);
    chmod($argv[1], 0600);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

VALIDATE_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $configuration = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $feature = $configuration['whatsapp_orders'] ?? null;
    if (!is_array($feature) || $feature['mode'] !== 'review') exit(1);
    config($configuration);
    if (!app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class)->available()) exit(1);
    $actorId = $feature['automation_actor_id'];
    if ($actorId !== null) {
        $actor = \App\Models\User::withoutGlobalScopes()->find($actorId);
        if (!$actor) exit(1);
        $access = app(\App\Services\Dashboard\TakeawayAccess::class);
        $fresh = app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
        if (!($access->permissions($fresh)['can_checkout'] ?? false)) exit(1);
        foreach ($feature['allowed_branch_ids'] as $branchId) {
            $branch = $access->branch('f:' . $branchId, $fresh);
            if (($branch['value'] ?? null) !== 'f:' . $branchId) exit(1);
        }
    } elseif ($feature['allowed_branch_ids'] !== []) { exit(1); }
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

CACHE_CANDIDATE_PHP = r'''
ini_set('display_errors', '0');
try {
    $original = require $argv[1];
    $configuration = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($original) || !is_array($configuration)
        || array_keys($configuration) !== ['whatsapp_orders', 'whatsapp_replies']) exit(1);
    if (($original['whatsapp_orders'] ?? null) === $configuration['whatsapp_orders']
        && ($original['whatsapp_replies'] ?? null) === $configuration['whatsapp_replies']) {
        $bytes = file_get_contents($argv[1]);
    } else {
        $original['whatsapp_orders'] = $configuration['whatsapp_orders'];
        $original['whatsapp_replies'] = $configuration['whatsapp_replies'];
        $bytes = '<?php return ' . var_export($original, true) . ';' . PHP_EOL;
    }
    if (!is_string($bytes) || file_put_contents($argv[3], $bytes) !== strlen($bytes)) exit(1);
    chmod($argv[3], 0600);
    if ((require $argv[3]) !== $original) exit(1);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

SMOKE_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $expected = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($expected)) exit(1);
    foreach ($expected as $namespace => $feature) {
      $actual = config($namespace);
      if (!is_array($actual) || !is_array($feature)) exit(1);
      foreach ($feature as $key => $value) {
        // Empty optional actor is the literal dotenv representation of null.
        $actualValue = $actual[$key] ?? null;
        if (in_array($key, ['automation_actor_id','api_key','model','access_token','activation_message_id','activation_event_id'], true)
            && $actualValue === '') $actualValue = null;
        if (in_array($key, ['activation_message_id','activation_event_id'], true) && is_string($actualValue)
            && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $actualValue)
            && (strlen($actualValue) < strlen((string) PHP_INT_MAX)
                || (strlen($actualValue) === strlen((string) PHP_INT_MAX) && strcmp($actualValue, (string) PHP_INT_MAX) <= 0))) {
            $actualValue = (int) $actualValue;
        }
        if ($actualValue !== $value) exit(1);
      }
    }
    if (!app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class)->available()) exit(1);
    $orders = $expected['whatsapp_orders'];
    $replies = $expected['whatsapp_replies'];
    echo json_encode(['review_enabled' => $orders['enabled'], 'key_configured' => $orders['api_key'] !== null,
        'model_configured' => $orders['model'] !== null, 'actor_configured' => $orders['automation_actor_id'] !== null,
        'branches_configured' => count($orders['allowed_branch_ids']) > 0,
        'historical_cutover_configured' => $orders['activation_message_id'] !== null && $orders['activation_event_id'] !== null,
        'automatic_dispatch_enabled' => false, 'manual_replies_enabled' => $replies['enabled'],
        'whatsapp_token_configured' => $replies['access_token'] !== null]);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''


def command(root, arguments, capture=False, timeout=40):
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
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())


def configure(root, uid, runner=command, prompt=prompt_configuration):
    base.validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'
    base.private_parent(parent, uid)
    # Shared with the additive orders installer, preventing simultaneous config publication.
    lock = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    backup = None
    try:
        lock_info = os.fstat(lock)
        if (not stat.S_ISREG(lock_info.st_mode) or lock_info.st_nlink != 1
                or lock_info.st_uid != uid or stat.S_IMODE(lock_info.st_mode) != 0o600):
            raise SafeError('ORDERS_CONFIGURATION_LOCK_NEEDS_REVIEW')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_ORDERS_INSTALL_OR_CONFIGURATION_IS_RUNNING') from None
        before = {'.env': secure_snapshot(root / '.env', uid, root),
                  CACHE_PATH: secure_snapshot(root / CACHE_PATH, uid, root, absent_ok=True)}
        parse_env(before['.env'].content)
        backup = pathlib.Path(tempfile.mkdtemp(prefix='orders-config-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('BACKUP_AND_PROJECT_REQUIRE_SAME_FILESYSTEM')
        publications = backup / 'publications'
        publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        for relative, item in before.items():
            if item is not None:
                write_private(backup / ('env.before' if relative == '.env' else 'config.before.php'), item.content)
        current_path = backup / 'current.json'
        code, _ = runner(root, ['php', '-r', READ_CURRENT_PHP, str(current_path)])
        if code != 0:
            raise SafeError('ORDERS_CONFIGURATION_READINESS_FAILED')
        try:
            current = json.loads(secure_snapshot(current_path, uid, root).content)
        except (ValueError, TypeError):
            raise SafeError('ORDERS_CONFIGURATION_READINESS_FAILED') from None
        configuration = prompt(current)
        validate_configuration(configuration)
        feature = configuration['whatsapp_orders']
        # Never trust a caller-supplied activation boundary.
        for name, source in [('activation_message_id', 'message_ceiling'), ('activation_event_id', 'event_ceiling')]:
            ceiling = current.get(name) if current.get('enabled') is True or not feature['enabled'] else current.get(source)
            if feature[name] != ceiling:
                raise SafeError('ACTIVATION_BOUNDARY_CHANGED')
        if feature['enabled'] and current.get('enabled') is not True:
            # Input can take minutes. Establish the initial boundary after input,
            # immediately before publication, instead of including old conversations.
            fresh_path = backup / 'activation-current.json'
            code, _ = runner(root, ['php', '-r', READ_CURRENT_PHP, str(fresh_path)])
            if code != 0:
                raise SafeError('ACTIVATION_READINESS_REFRESH_FAILED')
            try:
                fresh = json.loads(secure_snapshot(fresh_path, uid, root).content)
            except (ValueError, TypeError):
                raise SafeError('ACTIVATION_READINESS_REFRESH_FAILED') from None
            if fresh.get('enabled') is True:
                raise SafeError('CONFIGURATION_CHANGED_CONCURRENTLY')
            feature['activation_message_id'] = fresh.get('message_ceiling')
            feature['activation_event_id'] = fresh.get('event_ceiling')
            validate_configuration(configuration)
        settings = backup / 'review-settings.json'
        write_private(settings, json.dumps(configuration, ensure_ascii=True, separators=(',', ':')).encode())
        code, _ = runner(root, ['php', '-r', VALIDATE_PHP, str(settings)])
        if code != 0:
            raise SafeError('ACTOR_BRANCH_OR_ORDERS_READINESS_FAILED')
        proposals = {'.env': env_proposal(before['.env'].content, configuration)}
        if before[CACHE_PATH] is not None:
            candidate = backup / 'config.candidate.php'
            code, _ = runner(root, ['php', '-r', CACHE_CANDIDATE_PHP,
                                    str(backup / 'config.before.php'), str(settings), str(candidate)])
            if code != 0:
                raise SafeError('ORDERS_CONFIGURATION_CACHE_STAGING_FAILED')
            proposals[CACHE_PATH] = secure_snapshot(candidate, uid, root).content
            code, _ = runner(root, ['php', '-l', str(candidate)])
            if code != 0:
                raise SafeError('ORDERS_CONFIGURATION_CACHE_LINT_FAILED')
        written, created = {}, []
        try:
            for relative, original in before.items():
                if secure_snapshot(root / relative, uid, root, absent_ok=True) != original:
                    raise SafeError('CONFIGURATION_CHANGED_CONCURRENTLY')
            for relative, content in proposals.items():
                original = before[relative]
                if content != original.content or original.mode != 0o600:
                    base.replace_file(root / relative, content, original, uid, root, created,
                                      restore=replace(original, mode=0o600), journal=written,
                                      relative=relative, private_directory=publications)
            code, output = runner(root, ['php', '-r', SMOKE_PHP, str(settings)], capture=True)
            try:
                readiness = json.loads(output)
            except (ValueError, TypeError):
                readiness = None
            expected_keys = {'review_enabled', 'key_configured', 'model_configured', 'actor_configured',
                             'branches_configured', 'historical_cutover_configured', 'automatic_dispatch_enabled',
                             'manual_replies_enabled', 'whatsapp_token_configured'}
            expected_readiness = {
                'review_enabled': feature['enabled'], 'key_configured': feature['api_key'] is not None,
                'model_configured': feature['model'] is not None, 'actor_configured': feature['automation_actor_id'] is not None,
                'branches_configured': bool(feature['allowed_branch_ids']),
                'historical_cutover_configured': feature['activation_message_id'] is not None and feature['activation_event_id'] is not None,
                'automatic_dispatch_enabled': False,
                'manual_replies_enabled': configuration['whatsapp_replies']['enabled'],
                'whatsapp_token_configured': configuration['whatsapp_replies']['access_token'] is not None,
            }
            if (code != 0 or not isinstance(readiness, dict) or set(readiness) != expected_keys
                    or any(type(value) is not bool for value in readiness.values())
                    or readiness != expected_readiness):
                raise SafeError('ORDERS_CONFIGURATION_EFFECTIVE_CHECK_FAILED')
            for relative, original in before.items():
                expected = written[relative].after if relative in written else original
                if secure_snapshot(root / relative, uid, root, absent_ok=True) != expected:
                    raise SafeError('CONFIGURATION_CHANGED_CONCURRENTLY')
            return 'WHATSAPP_INBOX_FEATURES_CONFIGURED', backup, readiness
        except BaseException:
            restored = True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try:
                        restored = base.recover_publication(publication, root, uid) and restored
                    except BaseException:
                        restored = False
            status = ('ORDERS_CONFIGURATION_RESTORED' if restored
                      else 'ORDERS_CONFIGURATION_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED')
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
    finally:
        os.close(lock)


def main():
    try:
        if (sys.argv[1:] != ['--root', str(APP_ROOT)]
                or os.geteuid() != pwd.getpwnam('fasakha').pw_uid):
            raise SafeError('RUN_AS_FASAKHA_FOR_CONFIRMED_PROJECT_ROOT')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('CONFIGURATION_INTERRUPTED')))
        status, backup, readiness = configure(APP_ROOT, os.geteuid())
        print(status)
        print('BACKUP=' + str(backup))
        print('READINESS=' + json.dumps(readiness, sort_keys=True))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('ORDERS_CONFIGURATION_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
