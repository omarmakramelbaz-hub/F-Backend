#!/usr/bin/env python3
"""Observe public runtime source hashes; never import or execute the publisher."""
import ast
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import sys

ROOT = pathlib.Path('/home/fasakha/public_html')
RELEASE = '94befa10cab10c29cbeb504dc13b67256f28f102'
MANIFEST = 'deployment/apply_whatsapp_customer_confirmation.py'
MANIFEST_BLOB = '6a2e413bf51da394b3a66301dfafcb82ac7a2d25'
MAX_MANIFEST = 1048576
MAX_SOURCE = 8388608
TARGETS = ('app/Support/WhatsAppCustomerOrderConfirmation.php',
           'app/Services/Dashboard/WhatsAppOrderWorkflow.php')
PUBLIC_PATH = re.compile(r'app/(?:Support|Services/Dashboard)/[A-Za-z][A-Za-z0-9_]*\.php\Z')
HASH = re.compile(r'[a-f0-9]{40}\Z')


class Blocked(Exception):
    pass


def blob(content):
    return hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()


def git(root, arguments):
    try:
        result = subprocess.run(['git', '-C', str(root), *arguments], stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, timeout=15, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Blocked('MANIFEST_UNAVAILABLE') from None
    if result.returncode != 0 or len(result.stdout) > MAX_MANIFEST:
        raise Blocked('MANIFEST_UNAVAILABLE')
    return result.stdout


def manifest(root, runner=git):
    tree = runner(root, ['ls-tree', '-z', RELEASE, MANIFEST])
    if tree != b'100644 blob '+MANIFEST_BLOB.encode()+b'\t'+MANIFEST.encode()+b'\0':
        raise Blocked('MANIFEST_TREE_MISMATCH')
    size = runner(root, ['cat-file', '-s', MANIFEST_BLOB]).strip()
    if not re.fullmatch(b'[0-9]{1,7}', size) or not 0 < int(size) <= MAX_MANIFEST:
        raise Blocked('MANIFEST_SIZE_INVALID')
    source = runner(root, ['cat-file', 'blob', MANIFEST_BLOB])
    if len(source) != int(size) or blob(source) != MANIFEST_BLOB:
        raise Blocked('MANIFEST_HASH_MISMATCH')
    return parse_manifest(source)


def parse_manifest(source):
    # Every other statement, including imports and function bodies, is ignored.
    # ast.literal_eval accepts values, never Python calls or executable expressions.
    if len(source) > MAX_MANIFEST or blob(source) != MANIFEST_BLOB:
        raise Blocked('MANIFEST_HASH_MISMATCH')
    try:
        wanted = {'RUNTIME_PINS', 'TARGET_PINS', 'INSTALLED_SOURCE_PROFILES'}
        declarations = {}
        for node in ast.parse(source).body:
            if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
                name = node.targets[0].id
                if name in wanted:
                    if name in declarations: raise ValueError()
                    declarations[name] = ast.literal_eval(node.value)
        if set(declarations) != wanted: raise ValueError()
        runtime = declarations['RUNTIME_PINS']; targets = declarations['TARGET_PINS']
        installed = declarations['INSTALLED_SOURCE_PROFILES']
        if not isinstance(runtime, dict) or not 1 <= len(runtime) <= 32: raise ValueError()
        if not isinstance(targets, dict) or tuple(targets) != TARGETS: raise ValueError()
        for path, allowed in runtime.items():
            if (not PUBLIC_PATH.fullmatch(path) or not isinstance(allowed, tuple) or not 1 <= len(allowed) <= 4
                    or len(set(allowed)) != len(allowed) or any(not isinstance(value, str) or not HASH.fullmatch(value) for value in allowed)):
                raise ValueError()
        for path, pair in targets.items():
            if (not isinstance(pair, tuple) or len(pair) != 2
                    or pair[0] is not None and (not isinstance(pair[0], str) or not HASH.fullmatch(pair[0]))
                    or not isinstance(pair[1], str) or not HASH.fullmatch(pair[1])):
                raise ValueError()
        if set(runtime) & set(targets) or not isinstance(installed, dict) or len(installed) > 8: raise ValueError()
        profiles = {'BASELINE': {path: pair[0] for path, pair in targets.items()}}
        for name, pins in installed.items():
            if (not isinstance(name, str) or not re.fullmatch('[A-Z][A-Z0-9_]{0,63}', name)
                    or name in ('BASELINE', 'CURRENT') or not isinstance(pins, dict) or tuple(pins) != TARGETS
                    or any(not isinstance(value, str) or not HASH.fullmatch(value) for value in pins.values())):
                raise ValueError()
            profiles[name] = pins
        profiles['CURRENT'] = {path: pair[1] for path, pair in targets.items()}
        return runtime, targets, profiles
    except (ValueError, TypeError, SyntaxError, KeyError, RecursionError):
        raise Blocked('MANIFEST_LITERAL_SCOPE_INVALID') from None


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def metadata(info):
    return {'uid': info.st_uid, 'gid': info.st_gid, 'mode': format(stat.S_IMODE(info.st_mode), '04o'),
            'links': info.st_nlink, 'size': info.st_size, 'device': info.st_dev, 'inode': info.st_ino,
            'mtime_ns': info.st_mtime_ns, 'ctime_ns': info.st_ctime_ns}


def parents(path, uid):
    values = []
    for parent in reversed(path.parents):
        info = parent.lstat()
        if stat.S_ISLNK(info.st_mode): raise Blocked('SYMLINK_PARENT')
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, uid): raise Blocked('PARENT_UNSAFE')
        values.append((info.st_dev, info.st_ino, stat.S_IFMT(info.st_mode), info.st_uid, info.st_gid))
    return tuple(values)


def read_source(root, relative, uid):
    if not PUBLIC_PATH.fullmatch(relative): raise Blocked('SOURCE_SCOPE_INVALID')
    path = root/relative; row = {'path': relative, 'actual': None, 'state': 'UNREADABLE', 'metadata': None}
    token = None; descriptor = None
    try:
        parent_token = parents(path, uid)
        try: before = path.lstat()
        except FileNotFoundError:
            row['state'] = 'MISSING'; return row, (parent_token, None)
        token = (parent_token, signature(before)); row['metadata'] = metadata(before)
        if stat.S_ISLNK(before.st_mode): raise Blocked('SYMLINK_FILE')
        if not stat.S_ISREG(before.st_mode): raise Blocked('NOT_REGULAR')
        if before.st_size > MAX_SOURCE: raise Blocked('SOURCE_TOO_LARGE')
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        opened = os.fstat(descriptor)
        if not stat.S_ISREG(opened.st_mode) or signature(opened) != signature(before): raise Blocked('CHANGED_DURING_READ')
        chunks = []; total = 0
        while True:
            chunk = os.read(descriptor, min(65536, MAX_SOURCE+1-total))
            if not chunk: break
            total += len(chunk)
            if total > MAX_SOURCE: raise Blocked('SOURCE_TOO_LARGE')
            chunks.append(chunk)
        if (signature(os.fstat(descriptor)) != signature(before) or signature(path.lstat()) != signature(before)
                or parents(path, uid) != parent_token or total != before.st_size):
            raise Blocked('CHANGED_DURING_READ')
        row['actual'] = blob(b''.join(chunks)); row['state'] = 'READ_READY'
    except Blocked as error:
        row['state'] = str(error)
    except (OSError, ValueError):
        row['state'] = 'UNREADABLE'
    finally:
        if descriptor is not None: os.close(descriptor)
    return row, token


def unchanged(root, relative, uid, token):
    if token is None: return False
    try:
        parent_token, expected = token; path = root/relative
        if parents(path, uid) != parent_token: return False
        try: observed = signature(path.lstat())
        except FileNotFoundError: observed = None
        return observed == expected
    except (Blocked, OSError):
        return False


def inspect(root, uid, parsed):
    runtime, targets, profiles = parsed; rows = {}; tokens = {}
    for path in (*runtime, *targets):
        rows[path], tokens[path] = read_source(root, path, uid)
    differences = []
    for path, allowed in runtime.items():
        row = rows[path]
        if row['state'] != 'READ_READY' or row['actual'] not in allowed:
            differences.append(dict(row, allowed=list(allowed)))
    observed = {path: rows[path]['actual'] for path in targets}
    valid_targets = all(rows[path]['state'] == 'READ_READY' or rows[path]['state'] == 'MISSING' and observed[path] is None for path in targets)
    matches = [name for name, pins in profiles.items() if valid_targets and pins == observed]
    stable = all(unchanged(root, path, uid, tokens[path]) for path in rows)
    return {'diagnostic': 'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_SOURCES', 'writes': 0,
            'ai_calls': 0, 'message_sends': 0, 'database_queries': 0, 'worker_actions': 0,
            'scope': 'PUBLIC_SOURCE_HASH_AND_METADATA_ONLY_NOT_INSTALL_PERMISSION',
            'manifest_release': RELEASE, 'manifest_blob': MANIFEST_BLOB,
            'runtime': {'checked_paths': len(runtime), 'mismatch_count': len(differences), 'differences': differences},
            'targets': {'profile_matches': matches, 'recognized_profile': bool(matches),
                'files': [dict(rows[path], expected_current=targets[path][1]) for path in targets]},
            'source_metadata_unchanged_during_read': stable,
            'status': 'READ_READY' if stable else 'OBSERVATION_CHANGED'}


def main():
    stage = 'ARGUMENTS'
    try:
        if len(sys.argv) != 1: raise Blocked('ARGUMENTS_INVALID')
        stage = 'OWNER'; uid = pwd.getpwnam('fasakha').pw_uid
        if os.geteuid() != uid: raise Blocked('OWNER_INVALID')
        stage = 'ROOT'; info = ROOT.lstat()
        if (not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or ROOT.resolve(strict=True) != ROOT):
            raise Blocked('ROOT_INVALID')
        parents(ROOT/'source-observation', uid)
        stage = 'MANIFEST'; parsed = manifest(ROOT)
        stage = 'SOURCES'; result = inspect(ROOT, uid, parsed)
    except (Blocked, OSError, KeyError):
        result = {'diagnostic': 'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_SOURCES', 'writes': 0,
            'ai_calls': 0, 'message_sends': 0, 'database_queries': 0, 'worker_actions': 0,
            'status': 'OBSERVATION_BLOCKED', 'stage': stage}
    output = json.dumps(result, indent=2, ensure_ascii=True)
    if len(output) > 65536:
        result = {'diagnostic': 'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_SOURCES',
            'writes': 0, 'status': 'OBSERVATION_BLOCKED', 'stage': 'OUTPUT_BOUND'}
        output = json.dumps(result)
    print(output)
    return 1 if result['status'] == 'OBSERVATION_BLOCKED' else 0


if __name__ == '__main__':
    raise SystemExit(main())
