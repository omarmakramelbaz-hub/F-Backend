#!/usr/bin/env python3
"""Capture live application source and commit an isolated, source-bound PC candidate.

This module never runs application code, reads the live Git repository, pushes a
ref, or handles a credential. Only ``metadata`` is suitable for durable JSON;
``_files`` contains private in-memory source bytes. The caller owns its lock,
monotonic version counter, authenticated push, and last accepted metadata.
"""
import configparser
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess


REPOSITORY = 'omarmakramelbaz-hub/F-Backend'
REMOTE_URL = 'https://github.com/' + REPOSITORY + '.git'
LIVE_BRANCH = 'codex/go-erp-live-source-20261021'
CANDIDATE_PATH = 'deployment/go_erp_live_source_candidate.json'
CANDIDATE_POLICY = 'historical-baseline-v1'
POLICY_ASSETS = {
    '.github/workflows/go-erp-update-tests.yml': ('go_erp_current_candidate_ci.yml',
        '176abf7f1b4b533e486acf15b98a80bce3ecc38a9ed73bf78204cd82373cbf3e'),
    'deployment/verify_go_erp_current_candidate_ci.py': ('verify_go_erp_current_candidate_ci.py',
        '5033087ee07982bb61d64fbe52e306a58d84f43b7841e44eb5b067d4b57c8173'),
}
ROOTS = frozenset(('app', 'bootstrap', 'config', 'database', 'desktop', 'public', 'resources', 'routes'))
NEW_CODE_SUFFIXES = frozenset(('.php', '.js', '.cjs', '.mjs', '.css', '.html', '.json'))
PUBLIC_MEDIA_SUFFIXES = frozenset(('.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.ico', '.svg',
    '.woff', '.woff2', '.ttf', '.eot', '.otf', '.mp3', '.wav', '.ogg', '.mp4', '.webm', '.pdf', '.zip'))
CRITICAL = frozenset(('artisan', 'bootstrap/app.php', 'app/Http/Kernel.php', 'config/app.php', 'routes/web.php'))
MAX_FILES = 20000
MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_TOTAL_BYTES = 512 * 1024 * 1024
MAX_SEQUENCE = 9007199254740991
SHA = re.compile(r'[0-9a-f]{64}\Z')
REVISION = re.compile(r'[0-9a-f]{40}\Z')
VERSION = re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-preview\.([1-9][0-9]*)\Z')
_EXCLUDED_NAME = re.compile(r'^(?:\.env(?:[._-].*)?|[^/]*(?:firebase[^/]*credentials|credentials[^/]*firebase|service[-_]account)[^/]*|firebase\.json(?:[._-].*)?|[^/]+\.(?:key|pem)(?:[._-].*)?|error_log(?:[._-].*)?)$', re.I)
_SECRET_KEYS = re.compile(r'^(?:private_key|private_key_id|client_secret|refresh_token|access_token)$', re.I)
_DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC


class SourceError(Exception):
    """A bounded failure containing a reason code, never source or Git output."""


def _need(condition, reason):
    if not condition:
        raise SourceError('GO_ERP_LIVE_SOURCE_' + reason)


def _canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'), allow_nan=False).encode('utf-8')


def _digest(data):
    return hashlib.sha256(data).hexdigest()


def _path_ok(name):
    # Approved public assets include spaces and an en dash. Preserve exact raw
    # BMP names: Python code-point sorting then agrees with JS UTF-16 sorting.
    # These remain safe POSIX descriptor names and Windows extraction paths.
    if not isinstance(name, str) or not 0 < len(name) <= 512:
        return False
    if any(ord(char) < 32 or 127 <= ord(char) <= 159 or 0xD800 <= ord(char) <= 0xDFFF
           or ord(char) > 0xFFFF or char in '\\:<>"|?*' for char in name):
        return False
    for part in name.split('/'):
        if part in ('', '.', '..') or part.lower() == '.git' or part.endswith(('.', ' ')):
            return False
        if re.fullmatch(r'(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])', part.split('.', 1)[0], re.I):
            return False
    return True


def _in_scope(name):
    return name == 'artisan' or name.split('/')[0] in ROOTS


def _runtime_excluded(name):
    # Mirror runtime-source-policy.cjs exactly, including its public link rules.
    lower = name.lower()
    return (lower.startswith('bootstrap/cache/')
        or lower in ('public/storage', 'public/storage1')
        or lower.startswith(('public/storage/', 'public/storage1/'))
        or any(_EXCLUDED_NAME.fullmatch(part) for part in name.split('/')))


def _excluded(name):
    # Never inspect server data. A harmless approved .gitignore inside uploads
    # may still be retained from the trusted base because build-runtime packages
    # tracked files under public; no live upload bytes are read or imported.
    lower = name.lower()
    return (_runtime_excluded(name) or lower in ('bootstrap/cache', 'public/uploads', 'public/node_modules')
            or lower.startswith(('public/uploads/', 'public/node_modules/')))


def _credential_json(name, data):
    if not re.search(r'\.json(?:[._-][^/]*)?$', name, re.I):
        return False
    try:
        value = json.loads(data)
    except RecursionError:
        # An otherwise valid deeply nested JSON document must not evade the
        # credential walker by exceeding the parser's recursion limit.
        raise SourceError('GO_ERP_LIVE_SOURCE_JSON_DEPTH') from None
    except (ValueError, UnicodeError):
        return False
    def inspect(item):
        if isinstance(item, dict):
            return (item.get('type') == 'service_account'
                or any((_SECRET_KEYS.fullmatch(key) and isinstance(val, str) and bool(val)) or inspect(val)
                       for key, val in item.items()))
        return isinstance(item, list) and any(inspect(val) for val in item)
    try:
        return inspect(value)
    except RecursionError:
        raise SourceError('GO_ERP_LIVE_SOURCE_JSON_DEPTH') from None


def _identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_nlink, info.st_uid, info.st_gid,
            info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _open_root(path, trusted=False):
    """Open each absolute ancestor without following a link; retain identities."""
    text = os.fspath(path)
    _need(isinstance(text, str) and os.path.isabs(text) and '\x00' not in text, 'ROOT_PATH')
    parts = text.split('/')[1:]
    _need(parts and all(part not in ('', '.', '..') for part in parts), 'ROOT_PATH')
    fd = os.open('/', _DIR_FLAGS)
    ancestry = [('/', _identity(os.fstat(fd)))]
    try:
        for index, part in enumerate(parts):
            following = os.open(part, _DIR_FLAGS, dir_fd=fd)
            os.close(fd)
            fd = following
            info = os.fstat(fd)
            if trusted:
                # Root-owned sticky /tmp is safe for a root-created private
                # entry. The checkout itself must never be shared writable.
                sticky_ancestor = index < len(parts)-1 and bool(info.st_mode & stat.S_ISVTX)
                _need(info.st_uid == 0 and (not info.st_mode & 0o022 or sticky_ancestor), 'TRUSTED_ANCESTRY')
            ancestry.append(('/'.join(parts[:index+1]), _identity(info)))
        return fd, ancestry
    except (OSError, SourceError):
        os.close(fd)
        raise


def _read_at(root_fd, name, web_uid=None, trusted=False):
    _need(_path_ok(name), 'PATH')
    fd = os.dup(root_fd)
    directories = []
    try:
        for part in name.split('/')[:-1]:
            following = os.open(part, _DIR_FLAGS, dir_fd=fd)
            directories.append(_identity(os.fstat(following)))
            os.close(fd)
            fd = following
        file_fd = os.open(name.split('/')[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK, dir_fd=fd)
        try:
            before = os.fstat(file_fd)
            _need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1, 'FILE_IDENTITY')
            _need(before.st_uid == 0 if trusted else before.st_uid in (0, web_uid), 'FILE_OWNER')
            _need(not before.st_mode & 0o002, 'WORLD_WRITABLE')
            if trusted:
                _need(not before.st_mode & 0o022, 'TRUSTED_FILE_MODE')
            _need(before.st_size <= MAX_FILE_BYTES, 'FILE_LIMIT')
            chunks = []
            size = 0
            while True:
                block = os.read(file_fd, min(1024*1024, MAX_FILE_BYTES+1-size))
                if not block:
                    break
                chunks.append(block)
                size += len(block)
                _need(size <= MAX_FILE_BYTES, 'FILE_LIMIT')
            _need(_identity(before) == _identity(os.fstat(file_fd)), 'FILE_CHANGED')
            _need(_identity(before) == _identity(os.stat(name.split('/')[-1], dir_fd=fd, follow_symlinks=False)), 'FILE_REPLACED')
            return b''.join(chunks), _identity(before), directories
        finally:
            os.close(file_fd)
    finally:
        os.close(fd)


def _git(root, arguments, data=None):
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
        'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull, 'GIT_CONFIG_SYSTEM': os.devnull,
        'GIT_NO_LAZY_FETCH': '1', 'GIT_TERMINAL_PROMPT': '0', 'GIT_OPTIONAL_LOCKS': '0',
        'GIT_ATTR_NOSYSTEM': '1', 'GIT_NO_REPLACE_OBJECTS': '1'}
    command = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
        '-c', 'core.autocrlf=false', '-c', 'core.attributesFile=/dev/null',
        '-c', 'core.untrackedCache=false', '-c', 'submodule.recurse=false',
        '-c', 'commit.gpgSign=false', '-c', 'tag.gpgSign=false', '-c', 'protocol.file.allow=never',
        '-c', 'user.name=GO ERP source watcher', '-c', 'user.email=go-erp-source@localhost',
        '-C', str(root), *arguments]
    try:
        process = subprocess.run(command, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                 env=env, timeout=120, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise SourceError('GO_ERP_LIVE_SOURCE_GIT_FAILURE') from None
    _need(process.returncode == 0, 'GIT_FAILURE')
    return process.stdout


def _git_blobs(root, object_names):
    """Read bounded raw objects in batches; never invoke a content filter."""
    names = sorted(set(object_names))
    _need(len(names) <= 50000 and all(REVISION.fullmatch(name) for name in names), 'GIT_OBJECTS')
    if not names:
        return {}
    sizes = {}
    report = _git(root, ['cat-file', '--batch-check=%(objectname) %(objecttype) %(objectsize)'],
                  ('\n'.join(names) + '\n').encode('ascii'))
    for line in report.splitlines():
        fields = line.decode('ascii').split(' ')
        _need(len(fields) == 3 and fields[0] in names and fields[1] == 'blob' and fields[2].isdigit(), 'GIT_OBJECTS')
        size = int(fields[2])
        _need(size <= MAX_FILE_BYTES, 'FILE_LIMIT')
        sizes[fields[0]] = size
    _need(set(sizes) == set(names) and sum(sizes.values()) <= MAX_TOTAL_BYTES, 'TOTAL_LIMIT')
    output = {}
    batch = []
    batch_size = 0
    def consume():
        if not batch:
            return
        data = _git(root, ['cat-file', '--batch'], ('\n'.join(batch) + '\n').encode('ascii'))
        offset = 0
        for expected in batch:
            end = data.find(b'\n', offset)
            _need(end >= 0, 'GIT_OBJECTS')
            header = data[offset:end].decode('ascii').split(' ')
            _need(header == [expected, 'blob', str(sizes[expected])], 'GIT_OBJECTS')
            offset = end + 1
            value = data[offset:offset+sizes[expected]]
            offset += sizes[expected]
            _need(len(value) == sizes[expected] and data[offset:offset+1] == b'\n', 'GIT_OBJECTS')
            offset += 1
            output[expected] = value
        _need(offset == len(data), 'GIT_OBJECTS')
    for name in names:
        if batch and (len(batch) >= 256 or batch_size + sizes[name] > 16*1024*1024):
            consume()
            batch = []
            batch_size = 0
        batch.append(name)
        batch_size += sizes[name]
    consume()
    return output


def _trusted_git(root):
    _need(os.geteuid() == 0, 'ROOT_REQUIRED')
    try:
        root_fd, ancestry = _open_root(root, trusted=True)
    except OSError:
        raise SourceError('GO_ERP_LIVE_SOURCE_TRUSTED_PATH') from None
    try:
        # A linked worktree or .git symlink would inherit another checkout's
        # mutable config. Root must create an independent, private clone.
        git_fd = os.open('.git', _DIR_FLAGS, dir_fd=root_fd)
        try:
            def walk(fd, prefix=''):
                info = os.fstat(fd)
                _need(info.st_uid == 0 and not info.st_mode & 0o022, 'GIT_TRUST')
                for name in os.listdir(fd):
                    path = prefix + name
                    item = os.stat(name, dir_fd=fd, follow_symlinks=False)
                    _need(item.st_uid == 0 and not item.st_mode & 0o022, 'GIT_TRUST')
                    _need(not stat.S_ISLNK(item.st_mode), 'GIT_LINK')
                    _need(path not in ('objects/info/alternates', 'objects/info/http-alternates', 'info/grafts', 'refs/replace')
                          and not path.startswith(('modules/', 'refs/replace/')), 'GIT_EXTERNAL_OBJECTS')
                    if stat.S_ISDIR(item.st_mode):
                        child = os.open(name, _DIR_FLAGS, dir_fd=fd)
                        try:
                            walk(child, path + '/')
                        finally:
                            os.close(child)
                    else:
                        _need(stat.S_ISREG(item.st_mode) and item.st_nlink == 1, 'GIT_FILE')
            walk(git_fd)
        finally:
            os.close(git_fd)
        # .git is intentionally invalid as a captured source path, so read its
        # trusted config using the already opened private Git directory.
        config_fd = os.open('.git/config', os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=root_fd)
        try:
            config_bytes = os.read(config_fd, 65537)
            _need(len(config_bytes) <= 65536, 'GIT_CONFIG_SIZE')
        finally:
            os.close(config_fd)
        config = configparser.RawConfigParser(strict=True)
        try:
            config.read_string(config_bytes.decode('utf-8'))
        except (ValueError, UnicodeError, configparser.Error):
            raise SourceError('GO_ERP_LIVE_SOURCE_GIT_CONFIG') from None
        for section in config.sections():
            values = dict(config[section])
            allowed = ({'repositoryformatversion', 'filemode', 'bare', 'logallrefupdates', 'ignorecase', 'symlinks', 'autocrlf', 'hookspath'} if section == 'core'
                else {'url', 'fetch'} if section == 'remote "origin"'
                else {'remote', 'merge'} if re.fullmatch(r'branch "[a-zA-Z0-9_./-]+"', section)
                else set())
            _need(bool(allowed) and set(values) <= allowed, 'GIT_CONFIG')
            if section == 'core':
                _need(values.get('bare', 'false') == 'false'
                    and values.get('repositoryformatversion', '0') == '0'
                    and values.get('autocrlf', 'false') == 'false'
                    and values.get('hookspath', '/dev/null') == '/dev/null', 'GIT_CONFIG')
        _need(config.get('remote "origin"', 'url', fallback='') == REMOTE_URL, 'GIT_REMOTE')
        revision = _git(root, ['rev-parse', '--verify', 'HEAD']).decode('ascii').strip()
        _need(REVISION.fullmatch(revision), 'GIT_REVISION')
        _need(not _git(root, ['diff-index', '--cached', '--name-only', revision, '--']), 'GIT_DIRTY')
        _need(not _git(root, ['ls-files', '--others', '--exclude-standard', '-z']), 'GIT_DIRTY')
        # git status normalizes text=auto before comparison. That is unsuitable
        # for a source authority whose CRLF bytes are deliberately retained.
        # Compare every tracked worktree file to its raw Git blob instead.
        indexed = [record for record in _git(root, ['ls-files', '--stage', '-z']).split(b'\0') if record]
        blob_names = [record.split(b'\t', 1)[0].decode('ascii').split(' ')[1] for record in indexed
                      if record.split(b' ', 1)[0] != b'160000']
        blobs = _git_blobs(root, blob_names)
        for record in indexed:
            if not record:
                continue
            header, raw_name = record.split(b'\t', 1)
            mode, blob, stage = header.decode('ascii').split(' ')
            name = raw_name.decode('utf-8')
            _need(stage == '0' and _path_ok(name), 'GIT_DIRTY')
            if mode == '160000':
                # The approved repository contains an unrelated dev gitlink.
                # Its target need not be present, and its tree must never be
                # entered or executed as part of the application snapshot.
                _need(not _in_scope(name), 'GIT_SOURCE_SUBMODULE')
                continue
            expected = blobs[blob]
            if mode == '120000':
                # An excluded tracked public/storage link is part of the clean
                # Git checkout but is never followed or part of the runtime.
                _need(_runtime_excluded(name), 'GIT_SOURCE_LINK')
                actual = os.readlink(Path(root) / name).encode('utf-8')
            else:
                _need(mode in ('100644', '100755'), 'GIT_TRACKED_TYPE')
                actual, identity, _ = _read_at(root_fd, name, trusted=True)
                _need(bool(identity[2] & 0o111) == (mode == '100755'), 'GIT_DIRTY')
            _need(expected == actual, 'GIT_DIRTY')
        return revision
    except OSError:
        raise SourceError('GO_ERP_LIVE_SOURCE_TRUSTED_PATH') from None
    finally:
        os.close(root_fd)


def _seed(root):
    revision = _trusted_git(root)
    records = _git(root, ['ls-tree', '-r', '-z', '--full-tree', revision]).split(b'\0')
    files = {}
    selected = []
    total = 0
    for record in records:
        if not record:
            continue
        header, raw_name = record.split(b'\t', 1)
        mode, kind, blob = header.decode('ascii').split(' ')
        try:
            name = raw_name.decode('utf-8')
        except UnicodeError:
            raise SourceError('GO_ERP_LIVE_SOURCE_PATH_ENCODING') from None
        _need(_path_ok(name), 'SEED_PATH')
        if not _in_scope(name) or _runtime_excluded(name):
            continue
        _need(kind == 'blob' and mode in ('100644', '100755'), 'SEED_TYPE')
        selected.append((name, blob, mode))
    blobs = _git_blobs(root, [blob for _, blob, _ in selected])
    for name, blob, mode in selected:
        data = blobs[blob]
        _need(len(data) <= MAX_FILE_BYTES, 'FILE_LIMIT')
        if _credential_json(name, data):
            continue
        total += len(data)
        _need(total <= MAX_TOTAL_BYTES and len(files) < MAX_FILES, 'TOTAL_LIMIT')
        files[name] = (data, mode)
    _need(bool(files), 'SEED_EMPTY')
    return revision, files


def _live_pass(root, seed_names, web_uid):
    root_fd, ancestry = _open_root(root)
    records, contents, directories = {}, {}, {}
    total = 0
    try:
        def visit(fd, prefix=''):
            nonlocal total
            directories[prefix] = _identity(os.fstat(fd))
            for component in sorted(os.listdir(fd)):
                name = prefix + component
                if not prefix and component not in ROOTS and component != 'artisan':
                    continue
                if _excluded(name):
                    continue
                _need(_path_ok(name), 'LIVE_PATH')
                info = os.stat(component, dir_fd=fd, follow_symlinks=False)
                _need(info.st_uid in (0, web_uid), 'FILE_OWNER')
                _need(not info.st_mode & 0o002, 'WORLD_WRITABLE')
                if stat.S_ISDIR(info.st_mode):
                    _need(name != 'artisan', 'FILE_TYPE')
                    child = os.open(component, _DIR_FLAGS, dir_fd=fd)
                    try:
                        _need(_identity(info) == _identity(os.fstat(child)), 'DIRECTORY_REPLACED')
                        visit(child, name + '/')
                        _need(_identity(info) == _identity(os.fstat(child)), 'DIRECTORY_CHANGED')
                    finally:
                        os.close(child)
                    continue
                _need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, 'LIVE_FILE_TYPE')
                if name not in seed_names and name != 'artisan' and (Path(name).suffix.lower() not in NEW_CODE_SUFFIXES
                        or not re.fullmatch(r'[A-Za-z0-9_./@-]+', name)):
                    # New public images/fonts/media are not source authority.
                    if name.startswith('public/') and Path(name).suffix.lower() in PUBLIC_MEDIA_SUFFIXES:
                        continue
                    raise SourceError('GO_ERP_LIVE_SOURCE_UNCLASSIFIED_ADDITION')
                data, identity, _ = _read_at(root_fd, name, web_uid)
                _need(identity == _identity(info), 'FILE_REPLACED')
                _need(not _credential_json(name, data), 'CREDENTIAL_JSON')
                total += len(data)
                _need(total <= MAX_TOTAL_BYTES and len(records) < MAX_FILES, 'TOTAL_LIMIT')
                records[name] = {'identity': identity, 'mode': '100755' if info.st_mode & 0o111 else '100644',
                                 'sha256': _digest(data), 'bytes': len(data)}
                contents[name] = data
            _need(directories[prefix] == _identity(os.fstat(fd)), 'DIRECTORY_CHANGED')
        visit(root_fd)
        # Reopen the entire ancestry: holding an FD alone would permit an
        # attacker to substitute the path after its old directory was opened.
        check_fd, current_ancestry = _open_root(root)
        os.close(check_fd)
        _need(ancestry == current_ancestry, 'ANCESTRY_CHANGED')
        return {'ancestry': ancestry, 'directories': directories, 'records': records}, contents
    except OSError:
        raise SourceError('GO_ERP_LIVE_SOURCE_UNSTABLE_PATH') from None
    finally:
        os.close(root_fd)


def _metadata(seed_revision, inventory):
    packaged = {name: row['sha256'] for name, row in inventory.items() if row['present']}
    return {'format': 1, 'kind': 'go-erp-live-source-snapshot', 'seedRevision': seed_revision,
        'inventorySha256': _digest(_canonical(inventory)), 'inventory': inventory,
        'authoritativeLiveFiles': {name: row['sha256'] for name, row in inventory.items() if row['authority'] == 'live'},
        'packagedFiles': packaged,
        'retainedTrustedFiles': {name: row['sha256'] for name, row in inventory.items() if row['authority'] == 'trusted-base'},
        'removedFiles': [name for name, row in inventory.items() if not row['present']]}


def scan_snapshot(live_root, seed_root, web_uid, previous_metadata=None):
    """Double-scan a bounded live tree against a clean, root-owned Git seed.

    ``previous_metadata`` must be the last accepted metadata, even after a build
    is superseded. It preserves removal authority for files once seen live.
    """
    _need(type(web_uid) is int and web_uid >= 0, 'WEB_UID')
    try:
        seed_revision, seed = _seed(seed_root)
        first, private = _live_pass(live_root, set(seed), web_uid)
        second, repeated = _live_pass(live_root, set(seed), web_uid)
        _need(first == second and private == repeated, 'SNAPSHOT_CHANGED')
        _need(_trusted_git(seed_root) == seed_revision, 'SEED_CHANGED')
        prior = {}
        if previous_metadata is not None:
            _need(isinstance(previous_metadata, dict), 'PREVIOUS_METADATA')
            prior = previous_metadata.get('inventory', {})
            _validate_inventory(prior)
            _need(previous_metadata == _metadata(seed_revision, prior), 'PREVIOUS_METADATA')
        inventory = {}
        files = {}
        for name in sorted(set(seed) | set(first['records']) | set(prior)):
            _need(_path_ok(name) and _in_scope(name) and not _runtime_excluded(name), 'INVENTORY_PATH')
            current = first['records'].get(name)
            if current:
                row = {'authority': 'live', 'present': True, 'sha256': current['sha256'],
                       'bytes': current['bytes'], 'mode': current['mode']}
                files[name] = private[name]
            elif prior.get(name, {}).get('authority') in ('live', 'removed'):
                _need(name not in CRITICAL, 'CRITICAL_REMOVAL')
                row = {'authority': 'removed', 'present': False, 'sha256': None, 'bytes': 0,
                       'mode': prior[name]['mode']}
            elif name in seed:
                data, mode = seed[name]
                row = {'authority': 'trusted-base', 'present': True, 'sha256': _digest(data), 'bytes': len(data), 'mode': mode}
                files[name] = data
            else:
                raise SourceError('GO_ERP_LIVE_SOURCE_PREVIOUS_METADATA')
            inventory[name] = row
        return {'metadata': _metadata(seed_revision, inventory), '_files': files}
    except OSError:
        raise SourceError('GO_ERP_LIVE_SOURCE_UNSTABLE_PATH') from None


def _validate_snapshot(snapshot):
    _need(isinstance(snapshot, dict) and set(snapshot) == {'metadata', '_files'}, 'SNAPSHOT')
    metadata, files = snapshot['metadata'], snapshot['_files']
    _need(isinstance(metadata, dict) and isinstance(files, dict), 'SNAPSHOT')
    revision = metadata.get('seedRevision')
    inventory = metadata.get('inventory')
    _need(isinstance(revision, str) and REVISION.fullmatch(revision) and isinstance(inventory, dict), 'SNAPSHOT')
    _validate_inventory(inventory)
    for name, row in inventory.items():
        if row['present']:
            data = files.get(name)
            _need(isinstance(data, bytes) and len(data) == row['bytes'] and _digest(data) == row['sha256'], 'SNAPSHOT_BYTES')
            _need(not _credential_json(name, data), 'CREDENTIAL_JSON')
    _need(set(files) == set(metadata.get('packagedFiles', {})) and sum(map(len, files.values())) <= MAX_TOTAL_BYTES, 'SNAPSHOT_BYTES')
    _need(metadata == _metadata(revision, inventory), 'SNAPSHOT_METADATA')
    return metadata, files


def _validate_inventory(inventory):
    _need(isinstance(inventory, dict) and 0 < len(inventory) <= MAX_FILES, 'SNAPSHOT')
    for name, row in inventory.items():
        _need(_path_ok(name) and _in_scope(name) and not _runtime_excluded(name), 'INVENTORY_PATH')
        _need(isinstance(row, dict) and set(row) == {'authority', 'present', 'sha256', 'bytes', 'mode'}, 'INVENTORY')
        _need(row['mode'] in ('100644', '100755') and type(row['present']) is bool and type(row['bytes']) is int, 'INVENTORY')
        if row['present']:
            _need(row['authority'] in ('live', 'trusted-base') and 0 <= row['bytes'] <= MAX_FILE_BYTES, 'INVENTORY')
            _need(isinstance(row['sha256'], str) and SHA.fullmatch(row['sha256']), 'INVENTORY')
            _need(not _excluded(name) or row['authority'] == 'trusted-base', 'INVENTORY')
        else:
            _need(row['authority'] == 'removed' and row['sha256'] is None and row['bytes'] == 0 and name not in CRITICAL, 'INVENTORY')


def _write_file(root_fd, name, data, mode):
    fd = os.dup(root_fd)
    try:
        for component in name.split('/')[:-1]:
            try:
                os.mkdir(component, 0o755, dir_fd=fd)
            except FileExistsError:
                pass
            following = os.open(component, _DIR_FLAGS, dir_fd=fd)
            info = os.fstat(following)
            _need(info.st_uid == 0 and not info.st_mode & 0o022, 'CANDIDATE_DIRECTORY')
            os.close(fd)
            fd = following
        leaf = name.split('/')[-1]
        try:
            old = os.stat(leaf, dir_fd=fd, follow_symlinks=False)
            _need(stat.S_ISREG(old.st_mode) and old.st_uid == 0 and old.st_nlink == 1 and not old.st_mode & 0o022, 'CANDIDATE_FILE')
            os.unlink(leaf, dir_fd=fd)
        except FileNotFoundError:
            pass
        out = os.open(leaf, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, mode, dir_fd=fd)
        try:
            view = memoryview(data)
            while view:
                written = os.write(out, view)
                _need(written > 0, 'WRITE_FAILED')
                view = view[written:]
            os.fchmod(out, mode)
            os.fsync(out)
        finally:
            os.close(out)
    finally:
        os.close(fd)


def _policy_assets():
    # Release companions are independently pinned bytes, never live authority.
    # CI checkouts can belong to the runner; installed root releases belong to
    # root. In either case only the module's owner (or root) can own a companion.
    module = Path(__file__).absolute()
    info = module.lstat()
    _need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and not info.st_mode & 0o022,
          'POLICY_MODULE')
    root_fd, ancestry = _open_root(module.parent)
    try:
        result = {}
        for name, (asset, expected) in POLICY_ASSETS.items():
            data, identity, _ = _read_at(root_fd, asset, web_uid=info.st_uid)
            _need(len(data) <= 1024 * 1024 and not identity[2] & 0o022
                  and _digest(data) == expected, 'POLICY_ASSET')
            result[name] = data
        check, current = _open_root(module.parent)
        os.close(check)
        _need(current == ancestry and _identity(module.lstat()) == _identity(info), 'POLICY_ASSET_CHANGED')
        return result
    finally:
        os.close(root_fd)


def _verify_policy(checkout, assets):
    root_fd, _ = _open_root(checkout, trusted=True)
    try:
        for name, data in assets.items():
            actual, identity, _ = _read_at(root_fd, name, trusted=True)
            expected_blob = _git(checkout, ['hash-object', '--stdin', '--no-filters'], data).strip()
            _need(actual == data and stat.S_IMODE(identity[2]) == 0o644
                  and _git(checkout, ['ls-tree', '-z', 'HEAD', '--', name])
                      == b'100644 blob ' + expected_blob + b'\t' + name.encode() + b'\0'
                  and _git(checkout, ['cat-file', 'blob', expected_blob.decode()]) == data,
                  'CANDIDATE_POLICY')
    finally:
        os.close(root_fd)


def prepare_candidate(checkout, snapshot, version_sequence, heartbeat=None):
    """Commit a private, clean checkout; retrying an unpushed candidate is idempotent."""
    metadata, files = _validate_snapshot(snapshot)
    policy = _policy_assets()
    _need(type(version_sequence) is int and 0 < version_sequence <= MAX_SEQUENCE, 'VERSION_SEQUENCE')
    _need(heartbeat is None or callable(heartbeat), 'HEARTBEAT')
    heartbeat = heartbeat or (lambda: None)
    heartbeat()
    current = _trusted_git(checkout)
    root_fd, _ = _open_root(checkout, trusted=True)
    try:
        if current != metadata['seedRevision']:
            # The caller can retry an uncertain push using the already committed
            # bytes and version, without producing another commit or version.
            try:
                saved, _, _ = _read_at(root_fd, CANDIDATE_PATH, trusted=True)
                candidate = json.loads(saved)
            except (OSError, ValueError, UnicodeError):
                raise SourceError('GO_ERP_LIVE_SOURCE_CANDIDATE_BASE') from None
            _need(isinstance(candidate, dict) and isinstance(candidate.get('version'), str)
                  and VERSION.fullmatch(candidate['version']), 'CANDIDATE_BASE')
            expected = _candidate_metadata(metadata, candidate['version'], version_sequence)
            _need(candidate == expected and _git(checkout, ['rev-parse', 'HEAD^']).decode().strip() == metadata['seedRevision'], 'CANDIDATE_BASE')
            _need(candidate['version'].endswith('-preview.' + str(version_sequence)), 'CANDIDATE_BASE')
            _verify_package(checkout, candidate['version'])
            _verify_tree(checkout, metadata, files)
            _verify_policy(checkout, policy)
            heartbeat()
            return _result(checkout, candidate, current, True)
        seed_revision, seed = _seed(checkout)
        _need(seed_revision == metadata['seedRevision'], 'CANDIDATE_BASE')
        for name, row in metadata['inventory'].items():
            if row['authority'] == 'trusted-base':
                _need(name in seed and seed[name] == (files[name], row['mode']), 'RETAINED_BASE_MISMATCH')
        package_data, _, _ = _read_at(root_fd, 'desktop-pos/package.json', trusted=True)
        lock_data, _, _ = _read_at(root_fd, 'desktop-pos/package-lock.json', trusted=True)
        try:
            package, lock = json.loads(package_data), json.loads(lock_data)
            match = VERSION.fullmatch(package['version'])
            _need(match is not None and lock['version'] == package['version']
                and lock['packages']['']['version'] == package['version'], 'PACKAGE_VERSION')
            _need(all(int(value) <= MAX_SEQUENCE for value in match.groups()), 'PACKAGE_VERSION')
        except (ValueError, UnicodeError, KeyError, TypeError):
            raise SourceError('GO_ERP_LIVE_SOURCE_PACKAGE_VERSION') from None
        _need(version_sequence > int(match[4]), 'VERSION_NOT_ADVANCED')
        version = '.'.join(match.groups()[:3]) + '-preview.' + str(version_sequence)
        package['version'] = lock['version'] = lock['packages']['']['version'] = version
        candidate = _candidate_metadata(metadata, version, version_sequence)
        writes = dict(files)
        writes['desktop-pos/package.json'] = (json.dumps(package, ensure_ascii=False, indent=2) + '\n').encode()
        writes['desktop-pos/package-lock.json'] = (json.dumps(lock, ensure_ascii=False, indent=2) + '\n').encode()
        writes[CANDIDATE_PATH] = _canonical(candidate) + b'\n'
        writes.update(policy)
        # No git add/checkout/commit: they can invoke attributes, filters or
        # hooks. Create exact raw blobs and populate the existing clean index.
        index_records = []
        for name in metadata['removedFiles']:
            heartbeat()
            parts = name.split('/')
            parent = os.dup(root_fd)
            try:
                try:
                    for component in parts[:-1]:
                        child = os.open(component, _DIR_FLAGS, dir_fd=parent)
                        os.close(parent)
                        parent = child
                    item = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
                    _need(stat.S_ISREG(item.st_mode) and item.st_uid == 0 and item.st_nlink == 1, 'CANDIDATE_FILE')
                    os.unlink(parts[-1], dir_fd=parent)
                except FileNotFoundError:
                    pass
            finally:
                os.close(parent)
            index_records.append(b'0 ' + b'0'*40 + b'\t' + name.encode('utf-8') + b'\0')
        for name, data in sorted(writes.items()):
            heartbeat()
            mode = metadata['inventory'].get(name, {}).get('mode', '100644')
            _write_file(root_fd, name, data, int(mode[-3:], 8))
            blob = _git(checkout, ['hash-object', '-w', '--stdin', '--no-filters'], data).strip()
            _need(REVISION.fullmatch(blob.decode('ascii')), 'GIT_BLOB')
            index_records.append(mode.encode('ascii') + b' ' + blob + b'\t' + name.encode('utf-8') + b'\0')
        _git(checkout, ['update-index', '-z', '--index-info'], b''.join(index_records))
        heartbeat()
        tree = _git(checkout, ['write-tree']).strip().decode('ascii')
        commit = _git(checkout, ['commit-tree', tree, '-p', seed_revision],
            ('Build GO ERP preview ' + version + '\n\nLive inventory: ' + metadata['inventorySha256'] + '\n').encode()).strip().decode('ascii')
        _need(REVISION.fullmatch(commit), 'GIT_COMMIT')
        _git(checkout, ['update-ref', 'refs/heads/' + LIVE_BRANCH, commit])
        _git(checkout, ['symbolic-ref', 'HEAD', 'refs/heads/' + LIVE_BRANCH])
        _need(_trusted_git(checkout) == commit, 'CANDIDATE_VERIFY')
        _verify_tree(checkout, metadata, files)
        _verify_policy(checkout, policy)
        heartbeat()
        return _result(checkout, candidate, commit, False)
    except OSError:
        raise SourceError('GO_ERP_LIVE_SOURCE_CANDIDATE_PATH') from None
    finally:
        os.close(root_fd)


def _candidate_metadata(metadata, version, sequence):
    return {'format': 1, 'kind': 'go-erp-live-source-candidate', 'repository': REPOSITORY, 'branch': LIVE_BRANCH,
        'baseRevision': metadata['seedRevision'], 'version': version, 'versionSequence': sequence,
        **{key: metadata[key] for key in ('inventorySha256', 'inventory', 'authoritativeLiveFiles',
            'packagedFiles', 'retainedTrustedFiles', 'removedFiles')}}


def _verify_tree(checkout, metadata, files):
    _, committed = _seed(checkout)
    _need(set(committed) == set(files), 'PACKAGED_TREE_MISMATCH')
    _need(all(committed[name] == (data, metadata['inventory'][name]['mode']) for name, data in files.items()), 'PACKAGED_TREE_MISMATCH')


def _verify_package(checkout, version):
    _need(isinstance(version, str) and VERSION.fullmatch(version), 'PACKAGE_VERSION')
    root_fd, _ = _open_root(checkout, trusted=True)
    try:
        package_data, _, _ = _read_at(root_fd, 'desktop-pos/package.json', trusted=True)
        lock_data, _, _ = _read_at(root_fd, 'desktop-pos/package-lock.json', trusted=True)
        package, lock = json.loads(package_data), json.loads(lock_data)
        _need(package['version'] == lock['version'] == lock['packages']['']['version'] == version, 'PACKAGE_VERSION')
    except (OSError, ValueError, UnicodeError, KeyError, TypeError):
        raise SourceError('GO_ERP_LIVE_SOURCE_PACKAGE_VERSION') from None
    finally:
        os.close(root_fd)


def _result(checkout, candidate, commit, reused):
    return {'commit': commit, 'tree': _git(checkout, ['rev-parse', 'HEAD^{tree}']).decode().strip(),
        'version': candidate['version'], 'inventorySha256': candidate['inventorySha256'],
        'candidatePath': CANDIDATE_PATH, 'candidatePolicy': CANDIDATE_POLICY, 'reused': reused}
