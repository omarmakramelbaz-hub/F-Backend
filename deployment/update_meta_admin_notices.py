#!/usr/bin/env python3
"""Replace one reviewed Meta protocol file; no framework bootstrap or database work."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import re
import stat
import subprocess
import tempfile

TARGET = 'app/Support/MetaMessagingProtocol.php'
PREVIOUS_BLOB = 'ba20320835a33826b4b6bcc84f19c62569180931'
RELEASE_BLOB = 'd5c6cb4dfeaa1d6c8467025338d7d92d9ce5b0ed'
FOUNDATIONS = {
    'app/Services/Dashboard/MetaInboxAccess.php': '260b0a1cc93be44bf9e96f508b55420e5f639f64',
    'app/Services/Dashboard/MetaInboxConsumer.php': 'f7dda06f617de711a3f6a363edda0185e3973794',
}
PROBE_RESULT = {'ok': True, 'ignored_admin': 8, 'native_messages': 8, 'malformed_rejected': 8}
PROTOCOL_PROBE = br'''<?php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
try {
    // Only pure protocol functions are loaded. No autoloader, Laravel, database or network.
    require $argv[1];
    $accounts = [['channel' => 'messenger', 'account_id' => '101'], ['channel' => 'instagram', 'account_id' => '202']];
    $ignored = 0; $native = 0; $rejected = 0;
    foreach ([['page', '101'], ['instagram', '202']] as [$object, $account])
        foreach (['messaging', 'standby'] as $source) foreach ([false, true] as $outbound) {
            $event = ['sender' => ['id' => $outbound ? $account : '900'], 'recipient' => ['id' => $outbound ? '900' : $account],
                'timestamp' => (time() - 2) * 1000, 'message' => ['admin_text' => "Administrative notice\n"]];
            $payload = ['object' => $object, 'entry' => [['id' => $account, $source => [$event]]]];
            $result = \App\Support\MetaMessagingProtocol::report($payload, $accounts);
            if ($result !== ['messages' => [], 'ignored_count' => 1]) throw new \RuntimeException();
            $ignored++;
            $message = ['mid' => 'native-fixture-message', 'text' => 'Native fixture text', 'admin_text' => 'Native metadata'];
            if ($outbound) $message['is_echo'] = true;
            $payload['entry'][0][$source][0]['message'] = $message;
            $result = \App\Support\MetaMessagingProtocol::report($payload, $accounts);
            if ($result['ignored_count'] !== 0 || count($result['messages']) !== 1
                || $result['messages'][0]['message_id'] !== 'native-fixture-message'
                || $result['messages'][0]['text'] !== 'Native fixture text'
                || $result['messages'][0]['direction'] !== ($outbound ? 'outbound' : 'inbound')) throw new \RuntimeException();
            $native++;
            $payload['entry'][0][$source][0]['message'] = ['admin_text' => 'Administrative notice', 'text' => 'Unkeyed native text'];
            try { \App\Support\MetaMessagingProtocol::report($payload, $accounts); throw new \RuntimeException(); }
            catch (\InvalidArgumentException $error) {
                if ($error->getMessage() !== 'MESSAGE') throw new \RuntimeException();
                $rejected++;
            }
        }
    echo json_encode(['ok' => true, 'ignored_admin' => $ignored, 'native_messages' => $native, 'malformed_rejected' => $rejected], JSON_THROW_ON_ERROR);
} catch (\Throwable $error) { echo '{"ok":false}'; exit(1); }
'''


def run(args, root):
    return subprocess.run(args, cwd=root, capture_output=True, check=False)


def git_file(root, release, rel):
    result = run(['git', 'show', release + ':' + rel], root)
    if result.returncode: raise RuntimeError('RELEASE_FILE_UNAVAILABLE: ' + rel)
    return result.stdout


def blob(content):
    return hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()


def safe_path(path):
    if path.is_symlink() or any(parent.is_symlink() for parent in path.parents): raise RuntimeError('SYMLINK_TARGET')


def file_state(path):
    safe_path(path)
    if not path.is_file(): raise RuntimeError('REQUIRED_PROTOCOL_CONTEXT_FILE')
    info = path.stat()
    return (info.st_dev, info.st_ino, stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)


def directory_state(path):
    safe_path(path)
    if not path.is_dir(): raise RuntimeError('REQUIRED_PROTOCOL_CONTEXT_DIRECTORY')
    info = path.stat()
    return (info.st_dev, info.st_ino, stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)


def private_file(path, content):
    path.write_bytes(content)
    os.chmod(path, 0o600)


def atomic(path, content, metadata):
    safe_path(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.meta-admin-notice-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content)
            current = os.fstat(stream.fileno())
            if (current.st_uid, current.st_gid) != (metadata[3], metadata[4]):
                os.fchown(stream.fileno(), metadata[3], metadata[4])
            os.fchmod(stream.fileno(), metadata[2])
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)


def checked_lint(php, file, root):
    if run([php, '-l', str(file)], root).returncode: raise RuntimeError('STAGED_PROTOCOL_LINT_FAILED')


def verify(php, probe, target, root):
    result = run([php, str(probe), str(target)], root)
    try:
        decoded = json.loads(result.stdout)
        valid = result.returncode == 0 and decoded == PROBE_RESULT and decoded.get('ok') is True \
            and all(type(decoded.get(key)) is int for key in ('ignored_admin', 'native_messages', 'malformed_rejected'))
    except (ValueError, TypeError): valid = False
    if not valid: raise RuntimeError('META_ADMIN_NOTICE_PROTOCOL_VERIFICATION_FAILED')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--php', required=True)
    args = parser.parse_args()
    root = pathlib.Path(os.path.abspath(args.root))
    safe_path(root)
    if not re.fullmatch('[a-f0-9]{40}', args.release): raise RuntimeError('INVALID_RELEASE')
    if os.geteuid() == 0: raise RuntimeError('RUN_AS_APPLICATION_USER')
    directories = (root.parent, root, root / 'app', root / 'app/Support', root / 'app/Services', root / 'app/Services/Dashboard')
    context = {path: directory_state(path) for path in directories}
    if context[root][3] != os.geteuid() or not os.access(root / 'app/Support', os.W_OK | os.X_OK):
        raise RuntimeError('APPLICATION_DIRECTORY_OWNER_REQUIRED')
    foundations = {}
    for rel, expected in FOUNDATIONS.items():
        path = root / rel
        metadata = file_state(path)
        content = path.read_bytes()
        if blob(content) != expected: raise RuntimeError('META_FOUNDATION_DIFFERS: ' + rel)
        foundations[path] = (metadata, content)
    target_path = root / TARGET
    metadata = file_state(target_path)
    if metadata[3] != os.geteuid(): raise RuntimeError('APPLICATION_PROTOCOL_OWNER_REQUIRED')
    old = target_path.read_bytes()
    target = git_file(root, args.release, TARGET)
    if blob(target) != RELEASE_BLOB: raise RuntimeError('UNEXPECTED_RELEASE_PROTOCOL')
    if old != target and blob(old) != PREVIOUS_BLOB: raise RuntimeError('UNEXPECTED_INSTALLED_PROTOCOL')
    backup_parent = root.parent / 'meta-release-backups'
    safe_path(backup_parent)
    if backup_parent.exists() and (not backup_parent.is_dir() or directory_state(backup_parent)[3] != os.geteuid()):
        raise RuntimeError('PRIVATE_BACKUP_PARENT_REQUIRED')
    backup_parent.mkdir(mode=0o700, exist_ok=True)
    os.chmod(backup_parent, 0o700)
    prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S') + '-admin-notices-' + args.release[:8] + '-'
    backup = pathlib.Path(tempfile.mkdtemp(prefix=prefix, dir=backup_parent))
    # Capture backup directory identity too; the wrapper uses it for the separate audit.
    backup_context = {backup_parent: directory_state(backup_parent), backup: directory_state(backup)}
    staged = backup / 'MetaMessagingProtocol.php'
    private_file(staged, target)
    probe = backup / 'verify-admin-notice-protocol.php'
    private_file(probe, PROTOCOL_PROBE)
    checked_lint(args.php, staged, root)
    checked_lint(args.php, probe, root)
    verify(args.php, probe, staged, root)
    def recheck_context():
        for path, expected in {**context, **backup_context}.items():
            if directory_state(path) != expected: raise RuntimeError('CONCURRENT_PROTOCOL_CONTEXT_EDIT')
        for path, (expected_metadata, expected_content) in foundations.items():
            if file_state(path) != expected_metadata or path.read_bytes() != expected_content:
                raise RuntimeError('CONCURRENT_META_FOUNDATION_EDIT')
    recheck_context()
    if file_state(target_path) != metadata or target_path.read_bytes() != old: raise RuntimeError('CONCURRENT_PROTOCOL_EDIT')
    published = False
    try:
        if old != target:
            private_file(backup / 'previous-MetaMessagingProtocol.php', old)
            atomic(target_path, target, metadata)
            published = True
        verify(args.php, probe, target_path, root)
        recheck_context()
        current = file_state(target_path)
        if current[2:] != metadata[2:] or target_path.read_bytes() != target: raise RuntimeError('CONCURRENT_PROTOCOL_EDIT')
    except Exception:
        restored = True
        if published:
            try:
                for path, expected in context.items():
                    if directory_state(path) != expected: raise RuntimeError('CONCURRENT_PROTOCOL_CONTEXT_EDIT')
                current = file_state(target_path)
                if current[2:] != metadata[2:] or target_path.read_bytes() != target:
                    restored = False
                else:
                    # Restoring only our own exact bytes must also retain original ownership and permissions.
                    atomic(target_path, old, metadata)
            except (OSError, RuntimeError): restored = False
        print('META_ADMIN_NOTICE_CODE_RESTORED' if restored else 'META_ADMIN_NOTICE_ROLLBACK_INCOMPLETE')
        print('BACKUP=' + str(backup))
        raise
    print('META_ADMIN_NOTICE_PROTOCOL_VERIFIED')
    print('META_ADMIN_NOTICE_PROTOCOL_UPDATED=' + str(int(published)))
    print('BACKUP=' + str(backup))


if __name__ == '__main__':
    try: main()
    except (RuntimeError, OSError) as error:
        print('META_ADMIN_NOTICE_UPDATE_FAILED=' + (str(error) if isinstance(error, RuntimeError) else 'FILESYSTEM_ERROR'))
        raise SystemExit(1)
