#!/usr/bin/env python3
"""Bind a live candidate to the exact reviewed import diagnostic core.

The policy seed may change only this verifier, its tests and the update workflow.
The candidate must then be a single child of that seed, changing only captured
application source, its version fields and captured-source metadata.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import types

CORE = '6f5133af48ae03d626f0ac6ee02ff3a724c72407'
LEGACY_BASES = frozenset((
    '75c3fe50b3609ef5822904a23d7c4ed0afd07eff',
    '7523ff6888a6e3d0a6f5b7712c553909f12c043b',
    '52f7f5d22e5a2026e5bd5732bbb7893125b6ef9d',
))
REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
POLICY = 'native-close-reopen-v1'
POLICY_FILES = frozenset((
    '.github/workflows/go-erp-update-tests.yml',
    'deployment/verify_go_erp_preview31_seed.py',
    'tests/test_go_erp_preview31_seed.py',
))
CANDIDATE = 'deployment/go_erp_live_source_candidate.json'
VERSION_FILES = frozenset(('desktop-pos/package.json', 'desktop-pos/package-lock.json'))
SOURCE_ROOTS = frozenset(('app', 'bootstrap', 'config', 'database', 'desktop',
                          'public', 'resources', 'routes'))
REV = re.compile(r'[a-f0-9]{40}\Z')
MAX_JSON = 16 * 1024 ** 2
ENV = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
       'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull,
       'GIT_CONFIG_SYSTEM': os.devnull, 'GIT_NO_REPLACE_OBJECTS': '1',
       'GIT_NO_LAZY_FETCH': '1', 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}


class SeedError(Exception):
    pass


def need(value, reason):
    if not value:
        raise SeedError('GO_ERP_PREVIEW31_SEED_' + reason)


def git(root, *arguments, maximum=32 * 1024 ** 2):
    result = subprocess.run(['git', '-c', 'credential.helper=', '-c', 'core.hooksPath=/dev/null',
        '-c', 'core.autocrlf=false', '-c', 'core.fsmonitor=false',
        '-c', 'core.attributesFile=/dev/null', '-c', 'submodule.recurse=false',
        '-c', 'http.followRedirects=false', '-C', str(root), *arguments],
        env=ENV, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, timeout=180, check=False)
    need(result.returncode == 0 and len(result.stdout) <= maximum, 'GIT_FAILED')
    return result.stdout


def parents(root, revision):
    raw = git(root, 'cat-file', 'commit', revision)
    return [line[7:].decode('ascii') for line in raw.split(b'\n\n', 1)[0].splitlines()
            if line.startswith(b'parent ')]


def validate_seed(root, revision, core=CORE):
    need(isinstance(revision, str) and REV.fullmatch(revision)
         and isinstance(core, str) and REV.fullmatch(core), 'REVISION_INVALID')
    need(revision != core, 'POLICY_SEED_REQUIRED')
    need(parents(root, revision) == [core], 'POLICY_PARENT_INVALID')
    changes = git(root, 'diff', '--raw', '--no-abbrev', '--no-renames', core, revision, '--').decode('utf-8').splitlines()
    seen = set()
    for line in changes:
        match = re.fullmatch(r':(000000|100644) 100644 [a-f0-9]{40} [a-f0-9]{40} [AM]\t(.+)', line)
        need(match is not None and match[2] in POLICY_FILES and match[2] not in seen,
             'UNREVIEWED_POLICY_DELTA')
        seen.add(match[2])
    need(seen == POLICY_FILES, 'POLICY_INCOMPLETE')
    return {'core': core, 'seed': revision, 'policy_files': sorted(seen)}


def json_object(raw):
    def unique(pairs):
        value = {}
        for name, item in pairs:
            need(name not in value, 'DUPLICATE_JSON_FIELD')
            value[name] = item
        return value
    def nonfinite(_):
        raise SeedError('GO_ERP_PREVIEW31_SEED_NONFINITE_JSON')
    value = json.loads(raw, object_pairs_hook=unique, parse_constant=nonfinite)
    need(isinstance(value, dict), 'JSON_OBJECT_REQUIRED')
    return value


def candidate_bytes(root):
    path = root / CANDIDATE
    for ancestor in path.parents:
        item = ancestor.lstat()
        need(stat.S_ISDIR(item.st_mode) and not stat.S_ISLNK(item.st_mode), 'CANDIDATE_PATH_INVALID')
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
         and 0 < before.st_size <= MAX_JSON, 'CANDIDATE_FILE_INVALID')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        identity = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_size,
                                 item.st_mtime_ns, item.st_ctime_ns, item.st_nlink)
        chunks, total = [], 0
        while True:
            block = os.read(fd, 65536)
            if not block:
                break
            total += len(block)
            need(total <= MAX_JSON, 'CANDIDATE_FILE_LIMIT')
            chunks.append(block)
        data = b''.join(chunks)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
             == identity(path.lstat()), 'CANDIDATE_CHANGED')
        return data
    finally:
        os.close(fd)


def source_path(name):
    return (isinstance(name, str) and 0 < len(name) <= 512
            and not any(ord(char) < 32 or ord(char) == 127 for char in name)
            and '\\' not in name and ':' not in name
            and all(part not in ('', '.', '..') for part in name.split('/'))
            and (name == 'artisan' or name.split('/')[0] in SOURCE_ROOTS))


def validate_candidate_delta(root, revision, seed, candidate):
    need(parents(root, revision) == [seed], 'CANDIDATE_PARENT_INVALID')
    inventory = candidate.get('inventory')
    need(isinstance(inventory, dict) and 0 < len(inventory) <= 20000
         and all(source_path(name) for name in inventory), 'CANDIDATE_INVENTORY_INVALID')
    allowed = set(inventory) | set(VERSION_FILES) | {CANDIDATE}
    names = git(root, 'diff', '--name-only', '-z', '--no-renames', seed, revision, '--').split(b'\0')
    need(all(name.decode('utf-8') in allowed for name in names if name), 'FORBIDDEN_CANDIDATE_DELTA')
    return allowed


def original_verifier(root, seed):
    """Execute only the original verifier from the independently validated seed."""
    raw = git(root, 'show', seed + ':deployment/verify_go_erp_current_candidate_ci.py', maximum=1024 ** 2)
    value = types.ModuleType('reviewed_preview31_original_candidate_verifier')
    value.__file__ = str(root / 'deployment/verify_go_erp_current_candidate_ci.py')
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    return value


def legacy_verify(root, historical):
    spec = importlib.util.spec_from_file_location('reviewed_preview31_legacy_candidate',
        Path(__file__).with_name('verify_go_erp_media_capacity_seed.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value.verify(root, historical)


def verify(root, historical):
    root, historical = Path(root).absolute(), Path(historical).absolute()
    need(root.resolve() == root and historical.resolve() == historical and root != historical,
         'ROOT_INVALID')
    raw = candidate_bytes(root)
    candidate = json_object(raw)
    base = candidate.get('baseRevision')
    if base in LEGACY_BASES:
        return legacy_verify(root, historical)
    need(isinstance(base, str) and REV.fullmatch(base), 'CANDIDATE_BASE_INVALID')
    try:
        proof = validate_seed(root, base)
    except SeedError as error:
        if str(error) != 'GO_ERP_PREVIEW31_SEED_GIT_FAILED':
            raise
        git(root, 'fetch', '--quiet', '--depth=32', '--no-tags', '--no-recurse-submodules', REMOTE, base)
        proof = validate_seed(root, base)
    revision = git(root, 'rev-parse', 'HEAD').decode('ascii').strip()
    validate_candidate_delta(root, revision, base, candidate)
    original = original_verifier(root, base)
    with tempfile.TemporaryDirectory(prefix='go-erp-preview31-candidate-') as temporary:
        seed = Path(temporary) / 'seed'
        git(root, 'worktree', 'add', '--quiet', '--detach', str(seed), base)
        try:
            result = original.validate_checkout(root, seed, expected_base=base)
        finally:
            git(root, 'worktree', 'remove', '--force', str(seed))
    need(candidate_bytes(root) == raw, 'CANDIDATE_CHANGED')
    result['policy'] = POLICY
    result['reviewed_native_import_core'] = proof['core']
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--historical-root', required=True)
    arguments = parser.parse_args()
    try:
        print(json.dumps(verify(arguments.root, arguments.historical_root), sort_keys=True))
    except Exception as error:
        reason = str(error) if isinstance(error, SeedError) else 'GO_ERP_PREVIEW31_SEED_VALIDATION_FAILED'
        print(json.dumps({'ok': False, 'code': reason, 'application_changed': False, 'database_read': False}, sort_keys=True))
        raise SystemExit(1)
