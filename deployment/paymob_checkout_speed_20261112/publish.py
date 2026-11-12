#!/usr/bin/env python3
"""Patch two exact live baselines without publishing application credentials."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import socket
import stat
import subprocess
import tempfile

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BACKUPS = Path('/home/fasakha/paymob-checkout-backups')
PACKAGE = Path(__file__).resolve().parent
CONTROLLER = 'app/Http/Controllers/Payment/PaymobController.php'
WALLET = 'app/Http/Controllers/Api/V1/User/WalletController.php'
BASELINE = 'reviewed-live-diagnostic-658ad44e5f00dc3288215b98cc6a05d916fe6bdd'
ACCOUNT_UID = 1001
SPECS = {
    CONTROLLER: {
        'before': 'c679ab86629ccd103f2cbe8e96c96c274fba6ff84d726d912cf49ed8c4ec4a48',
        'after': 'de966706defacd95d07ffe34938f65f6f69819dab7a73bc1edbf021792b4d634',
        'attributes': [1001, 1003, 0o755],
    },
    WALLET: {
        'before': 'c8f7dec64024046eb1d59c480f8c731ab00c64d53147c8628abeb919251203aa',
        'after': 'a0b48a59a5ed92f31da049fd2962aa32148aa309007ecf05935509689ff4c023',
        'attributes': [0, 0, 0o644],
    },
}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def method_span(text):
    signature = '    public function checkingOut($payment_method, $integration_id, $order_id, $iframe_id_or_wallet_number)'
    matches = list(re.finditer('^' + re.escape(signature) + '[ \\t]*$', text, re.MULTILINE))
    if len(matches) != 1:
        raise RuntimeError('METHOD_SIGNATURE_MISMATCH')
    start = matches[0].start()
    end = text.find('\n    }', start)
    if end < 0:
        raise RuntimeError('METHOD_END_MISSING')
    return start, end + len('\n    }')


def controller_target(original, template):
    text = original.decode('utf-8')
    start, end = method_span(text)
    method = text[start:end]
    tokens = re.findall(r'''\$json\[['"]token['"]\]\s*=\s*(['"])(Token [A-Za-z0-9_-]+)\1\s*;''', method)
    public_keys = set(re.findall(r'publicKey=([A-Za-z0-9_-]+)&clientSecret=', method))
    if len(tokens) != 1 or len(public_keys) != 1:
        raise RuntimeError('PRIVATE_SETTINGS_PATTERN_MISMATCH')
    rendered = template.decode('utf-8').rstrip('\n')
    if rendered.count('__PAYMOB_AUTHORIZATION__') != 1 or rendered.count('__PAYMOB_PUBLIC_KEY__') != 1:
        raise RuntimeError('TEMPLATE_MARKERS_MISMATCH')
    rendered = rendered.replace('__PAYMOB_AUTHORIZATION__', tokens[0][1]).replace('__PAYMOB_PUBLIC_KEY__', next(iter(public_keys)))
    # Everything outside the active creation method remains byte-for-byte intact.
    return (text[:start] + rendered + text[end:]).encode('utf-8')


def wallet_target(original):
    text = original.decode('utf-8')
    before = 'if($user->email==null ){'
    after = 'if(!filter_var(trim((string) $user->email), FILTER_VALIDATE_EMAIL)){'
    success = "return $this->successResponse($user->balance,__('api.please update your info and enter email first'));"
    failure = "return $this->errorResponse(__('api.please update your info and enter email first'), 422);"
    if text.count(before) != 1 or text.count(success) != 1:
        raise RuntimeError('WALLET_VALIDATION_PATTERN_MISMATCH')
    if text.index(success) > text.index('$wallet=Wallet::create('):
        raise RuntimeError('WALLET_VALIDATION_ORDER_MISMATCH')
    return text.replace(before, after, 1).replace(success, failure, 1).encode('utf-8')


def source(root, name):
    if name not in (CONTROLLER, WALLET) or root.resolve() != root:
        raise RuntimeError('ROOT_OR_PATH_INVALID')
    path = root / name
    for parent in list(path.parents)[:len(Path(name).parts) - 1]:
        if parent.is_symlink() or not parent.is_dir():
            raise RuntimeError('SOURCE_PARENT_INVALID')
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > 131072:
        raise RuntimeError('SOURCE_TYPE_INVALID')
    return path, info


def attrs(info):
    return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))


def plan(root, specs, template):
    if set(specs) != {CONTROLLER, WALLET}:
        raise RuntimeError('PACKAGE_PATHS_INVALID')
    changes = []
    for name in (CONTROLLER, WALLET):
        path, info = source(root, name)
        original = path.read_bytes()
        entry = specs[name]
        if (set(entry) != {'before', 'after', 'attributes'}
                or any(not re.fullmatch('[a-f0-9]{64}', entry[key]) for key in ('before', 'after'))
                or not isinstance(entry['attributes'], list) or len(entry['attributes']) != 3
                or any(type(value) is not int for value in entry['attributes'])
                or min(entry['attributes']) < 0 or entry['attributes'][2] > 0o7777):
            raise RuntimeError('MANIFEST_INVALID')
        if attrs(info) != tuple(entry['attributes']):
            raise RuntimeError('SOURCE_ATTRIBUTES_MISMATCH')
        if digest(original) == entry['after']:
            continue
        if digest(original) != entry['before']:
            raise RuntimeError('BASELINE_MISMATCH')
        target = controller_target(original, template) if name == CONTROLLER else wallet_target(original)
        if digest(target) != entry['after']:
            raise RuntimeError('TARGET_HASH_MISMATCH')
        changes.append((name, original, target, attrs(info)))
    return changes


def lint_php(changes, binary):
    with tempfile.TemporaryDirectory(prefix='paymob-checkout-lint-') as temporary:
        os.chmod(temporary, 0o700)
        for index, (_, _, desired, _) in enumerate(changes):
            target = Path(temporary) / (str(index) + '.php')
            target.write_bytes(desired)
            target.chmod(0o600)
            result = subprocess.run([binary, '-l', str(target)], capture_output=True, check=False)
            if result.returncode != 0:
                # PHP diagnostics can contain source text, so never forward them.
                raise RuntimeError('PHP_LINT_FAILED')


def unchanged(root, change, expect_target=False):
    name, original, desired, expected_attrs = change
    path, info = source(root, name)
    if attrs(info) != expected_attrs or path.read_bytes() != (desired if expect_target else original):
        raise RuntimeError('SOURCE_CHANGED')
    return path


def replace(path, content, expected_attrs):
    uid, gid, mode = expected_attrs
    fd, temporary = tempfile.mkstemp(prefix='.paymob-checkout-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(content)
            output.flush()
            os.fchmod(output.fileno(), mode)
            os.fchown(output.fileno(), uid, gid)
            os.fsync(output.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def protected_directory(path):
    if not path.exists():
        path.mkdir(mode=0o700)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or path.is_symlink() or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise RuntimeError('BACKUP_DIRECTORY_INVALID')


def publish(root, changes, backup):
    for change in changes:
        unchanged(root, change)
    backup.mkdir(mode=0o700)
    metadata = {}
    for name, original, desired, expected_attrs in changes:
        saved = backup / name
        saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        fd = os.open(saved, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'wb') as output:
            output.write(original)
            output.flush()
            os.fsync(output.fileno())
        metadata[name] = {'before': digest(original), 'after': digest(desired), 'attributes': expected_attrs}
    audit = backup / 'metadata.json'
    audit.write_text(json.dumps(metadata, sort_keys=True) + '\n')
    audit.chmod(0o600)
    written = []
    try:
        for change in changes:
            path = unchanged(root, change)
            replace(path, change[2], change[3])
            written.append(change)
    except BaseException:
        # Restore only files still owned by this write; preserve concurrent edits.
        for change in reversed(written):
            try:
                path = unchanged(root, change, expect_target=True)
                replace(path, change[1], change[3])
            except (OSError, RuntimeError):
                pass
        raise


def rollback_plan(root, backup):
    protected_directory(backup)
    backup_owner = os.geteuid()
    audit = backup / 'metadata.json'
    info = audit.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != backup_owner or stat.S_IMODE(info.st_mode) != 0o600:
        raise RuntimeError('BACKUP_METADATA_INVALID')
    metadata = json.loads(audit.read_text())
    if not isinstance(metadata, dict) or not metadata or set(metadata) - set(SPECS):
        raise RuntimeError('BACKUP_METADATA_INVALID')
    changes = []
    for name, entry in metadata.items():
        expected = SPECS[name]
        if (not isinstance(entry, dict) or set(entry) != {'before', 'after', 'attributes'}
                or entry['before'] != expected['before'] or entry['after'] != expected['after']
                or not isinstance(entry['attributes'], list) or len(entry['attributes']) != 3
                or any(type(value) is not int for value in entry['attributes'])
                or entry['attributes'] != expected['attributes']):
            raise RuntimeError('BACKUP_METADATA_INVALID')
        saved, saved_info = source(backup, name)
        if (not stat.S_ISREG(saved_info.st_mode) or saved_info.st_nlink != 1 or saved_info.st_uid != backup_owner
                or stat.S_IMODE(saved_info.st_mode) != 0o600):
            raise RuntimeError('BACKUP_SOURCE_INVALID')
        original = saved.read_bytes()
        path, current_info = source(root, name)
        current = path.read_bytes()
        if digest(original) != expected['before'] or attrs(current_info) != tuple(entry['attributes']):
            raise RuntimeError('BACKUP_SOURCE_INVALID')
        if digest(current) == expected['before']:
            continue
        if digest(current) != expected['after']:
            raise RuntimeError('ROLLBACK_SOURCE_CHANGED')
        changes.append((name, current, original, attrs(current_info)))
    return changes


def require_account_identity():
    try:
        account = pwd.getpwnam('fasakha')
    except KeyError:
        raise RuntimeError('ACCOUNT_IDENTITY_MISMATCH') from None
    if account.pw_uid != ACCOUNT_UID:
        raise RuntimeError('ACCOUNT_IDENTITY_MISMATCH')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true', help='Publish after checks; default only checks.')
    parser.add_argument('--rollback', type=Path, help='Restore a protected backup only if its published files are unchanged.')
    arguments = parser.parse_args()
    if arguments.apply and arguments.rollback:
        raise RuntimeError('ARGUMENTS_INVALID')
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('SERVER_IDENTITY_MISMATCH')
    if not ROOT.is_dir() or ROOT.resolve() != ROOT:
        raise RuntimeError('APPLICATION_ROOT_INVALID')
    require_account_identity()
    lock = Path('/var/lock/fasakhansta-paymob-checkout.lock')
    if lock.is_symlink():
        raise RuntimeError('LOCK_INVALID')
    fd = os.open(lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'r+') as handle:
        info = os.fstat(handle.fileno())
        if info.st_uid != 0 or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise RuntimeError('LOCK_INVALID')
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if arguments.rollback:
            if arguments.rollback.parent != BACKUPS or not re.fullmatch('release-[a-f0-9]{16}', arguments.rollback.name):
                raise RuntimeError('BACKUP_PATH_INVALID')
            changes = rollback_plan(ROOT, arguments.rollback)
        else:
            template = (PACKAGE / 'checking_out.php.template').read_bytes()
            changes = plan(ROOT, SPECS, template)
        lint_php(changes, PHP)
        if not changes:
            print('PAYMOB_CHECKOUT_ALREADY_RESTORED' if arguments.rollback else 'PAYMOB_CHECKOUT_ALREADY_APPLIED')
            return
        if not arguments.apply and not arguments.rollback:
            print('PAYMOB_CHECKOUT_CHECK_PASSED')
            return
        protected_directory(BACKUPS)
        backup = BACKUPS / ('release-' + secrets.token_hex(8))
        publish(ROOT, changes, backup)
        print('PAYMOB_CHECKOUT_RESTORED' if arguments.rollback else 'PAYMOB_CHECKOUT_APPLIED')
        print('BACKUP=' + str(backup))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, ValueError, UnicodeError) as error:
        code = str(error) if isinstance(error, RuntimeError) else 'PUBLICATION_ERROR'
        if not re.fullmatch('[A-Z_]+', code):
            code = 'PUBLICATION_ERROR'
        raise SystemExit(code) from None
