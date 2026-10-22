#!/usr/bin/env python3
"""Read-only binding of today's checkout to its captured live-source inventory."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import types

BASE_REVISION = '75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
POLICY = 'historical-baseline-v1'
MAX_JSON = 16 * 1024 ** 2


class CandidateError(Exception):
    pass


def need(value, code):
    if not value:
        raise CandidateError('GO_ERP_CURRENT_CANDIDATE_' + code)


def git(root, arguments):
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
           'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull,
           'GIT_CONFIG_SYSTEM': os.devnull, 'GIT_NO_REPLACE_OBJECTS': '1',
           'GIT_NO_LAZY_FETCH': '1', 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0'}
    command = ['git', '-c', 'safe.directory=' + str(root), '-c', 'core.hooksPath=/dev/null',
               '-c', 'core.fsmonitor=false', '-c', 'core.autocrlf=false',
               '-c', 'core.attributesFile=/dev/null', '-C', str(root), *arguments]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            env=env, timeout=60, check=False)
    need(result.returncode == 0 and len(result.stdout) <= MAX_JSON, 'GIT_READ_FAILED')
    return result.stdout


def read(path, maximum):
    need(path.is_absolute(), 'PATH_INVALID')
    for parent in path.parents:
        info = parent.lstat()
        need(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode), 'PATH_LINK')
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_size <= maximum,
         'FILE_INVALID')
    identity = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_size,
                             item.st_mtime_ns, item.st_ctime_ns, item.st_nlink)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(os.fstat(descriptor)) == identity(before), 'FILE_CHANGED')
        chunks, total = [], 0
        while True:
            block = os.read(descriptor, min(65536, maximum + 1 - total))
            if not block:
                break
            chunks.append(block)
            total += len(block)
            need(total <= maximum, 'FILE_LIMIT')
        need(identity(os.fstat(descriptor)) == identity(before) == identity(path.lstat()),
             'FILE_CHANGED')
        return b''.join(chunks), before
    finally:
        os.close(descriptor)


def json_value(raw):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            need(key not in result, 'DUPLICATE_JSON')
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=unique)


def trusted_module(historical, revision, name):
    # Execute only bytes from the separate, exact trusted Git baseline, never
    # the candidate's own source-capture helper or mutable checkout helper.
    raw = git(historical, ['show', revision + ':deployment/' + name + '.py'])
    module = types.ModuleType('trusted_' + name)
    module.__file__ = str(historical / 'deployment' / (name + '.py'))
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module


def tracked_bytes(root, head, name, raw, info):
    blob = hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest().encode()
    need(git(root, ['ls-tree', '-z', head, '--', name])
         == b'100644 blob ' + blob + b'\t' + name.encode() + b'\0'
         and not info.st_mode & 0o111, 'DIRTY_METADATA_BYTES_OR_MODE')


def validate_checkout(root, historical, *, expected_base=BASE_REVISION):
    root, historical = Path(root).absolute(), Path(historical).absolute()
    need(root.resolve() == root and historical.resolve() == historical and root != historical,
         'ROOT_INVALID')
    need(git(historical, ['rev-parse', 'HEAD']).decode().strip() == expected_base, 'HISTORICAL_BASE')
    need(git(historical, ['diff-index', '--name-only', expected_base, '--']) == b'', 'HISTORICAL_DIRTY')
    source = trusted_module(historical, expected_base, 'go_erp_live_source')
    refresh = trusted_module(historical, expected_base, 'refresh_desktop_dashboard_server_manifest')
    head = git(root, ['rev-parse', 'HEAD']).decode().strip()
    commit = git(root, ['cat-file', 'commit', head])
    parents = [line[7:].decode() for line in commit.split(b'\n\n', 1)[0].splitlines()
               if line.startswith(b'parent ')]
    need(parents == [expected_base], 'CANDIDATE_PARENT')
    raw, candidate_info = read(root / source.CANDIDATE_PATH, MAX_JSON)
    tracked_bytes(root, head, source.CANDIDATE_PATH, raw, candidate_info)
    candidate = json_value(raw)
    need(isinstance(candidate, dict) and candidate.get('baseRevision') == expected_base,
         'CANDIDATE_BASE')
    sequence, version = candidate.get('versionSequence'), candidate.get('version')
    need(type(sequence) is int and 0 < sequence <= source.MAX_SEQUENCE
         and isinstance(version, str) and source.VERSION.fullmatch(version)
         and int(version.rsplit('.', 1)[1]) == sequence, 'CANDIDATE_VERSION')
    inventory = candidate.get('inventory')
    source._validate_inventory(inventory)
    metadata = source._metadata(expected_base, inventory)
    need(candidate == source._candidate_metadata(metadata, version, sequence), 'CANDIDATE_METADATA')
    info_raw, info_stat = read(root / 'desktop-pos/package.json', 65536)
    lock_raw, lock_stat = read(root / 'desktop-pos/package-lock.json', MAX_JSON)
    tracked_bytes(root, head, 'desktop-pos/package.json', info_raw, info_stat)
    tracked_bytes(root, head, 'desktop-pos/package-lock.json', lock_raw, lock_stat)
    info, lock = json_value(info_raw), json_value(lock_raw)
    need(info.get('version') == lock.get('version') == lock.get('packages', {}).get('', {}).get('version') == version,
         'PACKAGE_VERSION')
    baseline_tree = {}
    for entry in git(historical, ['ls-tree', '-r', '-z', '--full-tree', expected_base]).split(b'\0'):
        if entry:
            header, name = entry.split(b'\t', 1)
            baseline_tree[name.decode('utf-8')] = header.decode('ascii').split()
    need(all(name not in baseline_tree or inventory.get(name, {}).get('present')
             for name in source.CRITICAL), 'CRITICAL_SOURCE')
    selected, normalized, selected_paths, total = {}, {}, set(), 0
    for entry in git(root, ['ls-tree', '-r', '-z', '--full-tree', head]).split(b'\0'):
        if not entry:
            continue
        header, raw_name = entry.split(b'\t', 1)
        mode, kind, blob = header.decode('ascii').split()
        name = raw_name.decode('utf-8')
        if not source._in_scope(name) or source._runtime_excluded(name):
            continue
        need(source._path_ok(name) and kind == 'blob' and mode in ('100644', '100755'), 'TREE_PATH')
        data, actual = read(root / name, source.MAX_FILE_BYTES)
        need(hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest() == blob,
             'DIRTY_SOURCE_BYTES')
        if source._credential_json(name, data):
            continue
        total += len(data)
        need(total <= source.MAX_TOTAL_BYTES and len(selected) < source.MAX_FILES, 'TREE_LIMIT')
        row = inventory.get(name)
        need(row is not None and row['present'] and row['mode'] == mode
             and row['mode'] == ('100755' if actual.st_mode & 0o111 else '100644')
             and row['bytes'] == len(data), 'TREE_ROSTER_OR_MODE')
        if row['authority'] == 'trusted-base':
            need(baseline_tree.get(name) == [mode, 'blob', blob], 'RETAINED_BASE_MISMATCH')
        selected[name] = hashlib.sha256(data).hexdigest()
        selected_paths.add(name)
        if refresh.selected(name):
            normalized[name] = {'blob': blob, 'sha256': hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()}
    need(selected == candidate['packagedFiles'], 'COMPLETE_SOURCE_MAP')
    # Unknown local code must not influence CI's application while being absent
    # from the captured/packaged Git source. Follow the same upload exclusions.
    for prefix in source.ROOTS:
        directory = root / prefix
        if not directory.exists():
            continue
        need(not directory.is_symlink() and directory.is_dir(), 'SOURCE_ROOT_LINK')
        def unreadable(_):
            need(False, 'SOURCE_UNREADABLE')
        for parent, directories, names in os.walk(directory, followlinks=False, onerror=unreadable):
            for component in list(directories):
                path = Path(parent) / component
                name = path.relative_to(root).as_posix()
                if source._excluded(name):
                    directories.remove(component)
                else:
                    need(not path.is_symlink(), 'UNTRACKED_SOURCE_LINK')
            for component in names:
                path = Path(parent) / component
                name = path.relative_to(root).as_posix()
                if source._excluded(name) or name in selected_paths:
                    continue
                need(not path.is_symlink(), 'UNTRACKED_SOURCE_LINK')
                if name.startswith('public/') and path.suffix.lower() in source.PUBLIC_MEDIA_SUFFIXES:
                    continue
                need(False, 'UNTRACKED_SOURCE_ADDITION')
    need(refresh.scan(root) == normalized, 'NORMALIZED_SOURCE_MAP')
    fingerprint = refresh.digest(normalized)
    need(read(root / source.CANDIDATE_PATH, MAX_JSON)[0] == raw, 'CANDIDATE_CHANGED')
    return {'format': 1, 'kind': 'go-erp-current-candidate-ci', 'policy': POLICY,
            'revision': head, 'base_revision': expected_base, 'version': version,
            'inventory_sha256': candidate['inventorySha256'], 'source_files': len(selected),
            'source': fingerprint, 'application_changed': False, 'database_read': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--historical-root', required=True)
    arguments = parser.parse_args()
    try:
        result = validate_checkout(arguments.root, arguments.historical_root)
        print(json.dumps(result, sort_keys=True, separators=(',', ':')))
    except Exception as error:
        code = str(error) if isinstance(error, CandidateError) else 'GO_ERP_CURRENT_CANDIDATE_VALIDATION_FAILED'
        print(json.dumps({'ok': False, 'reason': code, 'application_changed': False, 'database_read': False}, sort_keys=True))
        raise SystemExit(1)


if __name__ == '__main__':
    main()
