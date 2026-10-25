#!/usr/bin/env python3
"""Keep the two published GO ERP installer generations, preserving proof metadata."""
import argparse
from contextlib import ExitStack
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import types
import uuid

SHA = re.compile(r'[a-f0-9]{64}\.exe\Z')
PUBLISHER_SHA256 = '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'
PUBLISHER = Path(__file__).absolute().parent / ('publisher-' + PUBLISHER_SHA256 + '.py')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def identity(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def parse(raw):
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, 'DUPLICATE_JSON_KEY')
            result[key] = value
        return result
    def invalid(_):
        raise Stop('NONFINITE_JSON')
    result = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    need(isinstance(result, dict), 'JSON_OBJECT_REQUIRED')
    return result


class Directory:
    def __init__(self, path, stack):
        self.path = Path(path)
        need(self.path.is_absolute() and '..' not in self.path.parts, 'DIRECTORY_PATH_INVALID')
        self.chain = []
        for parent in (*reversed(self.path.parents), self.path):
            info = parent.lstat()
            need(stat.S_ISDIR(info.st_mode), 'DIRECTORY_LINK_OR_SPECIAL')
            self.chain.append((parent, identity(info)[:5]))
        info = self.path.lstat()
        need(info.st_uid == 0 and not info.st_mode & 0o022, 'DIRECTORY_OWNER_OR_MODE')
        self.fd = os.open(self.path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        stack.callback(os.close, self.fd)
        self.before = identity(info)[:5]
        self.check()

    def check(self):
        for path, before in self.chain:
            need(identity(path.lstat())[:5] == before, 'DIRECTORY_CHANGED')
        need(identity(os.fstat(self.fd))[:5] == self.before, 'DIRECTORY_CHANGED')


def regular(info):
    need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
         and not info.st_mode & 0o022, 'FILE_OWNER_LINK_OR_MODE')


class Proof:
    def __init__(self, directory, name, maximum=16 * 1024**2):
        need('/' not in name and name not in ('', '.', '..'), 'FILE_NAME_INVALID')
        self.directory, self.name = directory, name
        directory.check()
        before = os.stat(name, dir_fd=directory.fd, follow_symlinks=False)
        regular(before)
        need(0 < before.st_size <= maximum, 'METADATA_SIZE_INVALID')
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
        try:
            need(identity(os.fstat(fd)) == identity(before), 'FILE_CHANGED')
            parts, size = [], 0
            while size <= maximum:
                part = os.read(fd, min(65536, maximum + 1 - size))
                if not part:
                    break
                parts.append(part)
                size += len(part)
            self.raw, self.before = b''.join(parts), identity(before)
            need(size == before.st_size and identity(os.fstat(fd)) == self.before, 'FILE_CHANGED')
        finally:
            os.close(fd)
        self.check()
        self.value = parse(self.raw)

    def check(self):
        self.directory.check()
        need(identity(os.stat(self.name, dir_fd=self.directory.fd, follow_symlinks=False)) == self.before,
             'METADATA_CHANGED')


def lock_file(path, stack):
    directory = Directory(path.parent, stack)
    fd = os.open(path.name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600,
                 dir_fd=directory.fd)
    stack.callback(os.close, fd)
    before = os.fstat(fd)
    regular(before)
    need(stat.S_IMODE(before.st_mode) == 0o600, 'LOCK_MODE_INVALID')
    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    directory.check()
    need(identity(os.stat(path.name, dir_fd=directory.fd, follow_symlinks=False)) == identity(before),
         'LOCK_CHANGED')
    return directory, path.name, fd, identity(before)


def check_locks(locks):
    for directory, name, fd, before in locks:
        directory.check()
        need(identity(os.fstat(fd)) == before
             and identity(os.stat(name, dir_fd=directory.fd, follow_symlinks=False)) == before, 'LOCK_CHANGED')


class Paths:
    def __init__(self, manual=Path('/var/lib/go-erp-manual-updates'),
                 live=Path('/var/lib/go-erp-live-updates'),
                 public=Path('/home/fasakha/public_html/storage/app/desktop-updates'),
                 cache=Path('/var/lib/go-erp-release-retention')):
        self.manual, self.live, self.public, self.cache = map(Path, (manual, live, public, cache))


def load_publisher(stack):
    folder = Directory(PUBLISHER.parent, stack)
    # This is source, not JSON. Authenticate its immutable bytes before import.
    fd = os.open(PUBLISHER.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=folder.fd)
    try:
        before = os.fstat(fd)
        regular(before)
        need(0 < before.st_size < 2 * 1024**2, 'PUBLISHER_SIZE_INVALID')
        raw = b''
        while len(raw) <= before.st_size:
            part = os.read(fd, 65536)
            if not part:
                break
            raw += part
        need(len(raw) == before.st_size and identity(os.fstat(fd)) == identity(before)
             and identity(PUBLISHER.lstat()) == identity(before)
             and hashlib.sha256(raw).hexdigest() == PUBLISHER_SHA256, 'PUBLISHER_PIN_INVALID')
    finally:
        os.close(fd)
    folder.check()
    module = types.ModuleType('go_erp_retention_pinned_publisher')
    module.__file__ = str(PUBLISHER)
    sys.modules[module.__name__] = module
    exec(compile(raw, str(PUBLISHER), 'exec'), module.__dict__)
    return module


class Binary:
    def __init__(self, directory, name, expected=None):
        self.directory, self.name, self.expected = directory, name, expected
        directory.check()
        item = os.stat(name, dir_fd=directory.fd, follow_symlinks=False)
        regular(item)
        need(2 <= item.st_size <= 2 * 1024**3, 'INSTALLER_SIZE_INVALID')
        self.before = identity(item)
        if expected is not None:
            need(item.st_size == expected['bytes'], 'INSTALLER_SIZE_MISMATCH')

    @property
    def path(self):
        return self.directory.path / self.name

    def check(self):
        self.directory.check()
        need(identity(os.stat(self.name, dir_fd=self.directory.fd, follow_symlinks=False)) == self.before,
             'INSTALLER_CHANGED')

    def verify(self):
        self.check()
        fd = os.open(self.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                     dir_fd=self.directory.fd)
        try:
            need(identity(os.fstat(fd)) == self.before, 'INSTALLER_CHANGED')
            digest, size = hashlib.sha256(), 0
            first = True
            while True:
                part = os.read(fd, 1024**2)
                if not part:
                    break
                if first:
                    need(part.startswith(b'MZ'), 'INSTALLER_NOT_WINDOWS_EXE')
                    first = False
                size += len(part)
                need(size <= self.before[6], 'INSTALLER_CHANGED')
                digest.update(part)
            need(size == self.before[6] and identity(os.fstat(fd)) == self.before, 'INSTALLER_CHANGED')
            sha = self.expected['sha256'] if self.expected else self.name[:-4]
            need(digest.hexdigest() == sha, 'INSTALLER_HASH_MISMATCH')
        finally:
            os.close(fd)
        self.check()

    def unlink(self):
        self.check()
        os.unlink(self.name, dir_fd=self.directory.fd)
        os.fsync(self.directory.fd)


def plan(paths, publisher, stack):
    locks = []
    for path in (paths.manual / 'private/install.lock', paths.manual / 'private/worker.lock',
                 paths.live / 'watcher.lock'):
        locks.append(lock_file(path, stack))
    public = Directory(paths.public, stack)
    fcntl.flock(public.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    locks.append(lock_file(paths.manual / 'shared/.downloads.lock', stack))
    shared = Directory(paths.manual / 'shared', stack)
    archive = Directory(paths.manual / 'shared/installers', stack)
    state_dir = Directory(paths.live / 'state', stack)
    proofs = [Proof(shared, 'downloads.json'), Proof(public, 'current.json'), Proof(state_dir, 'current.json')]
    downloads, feed, state = [proof.value for proof in proofs]
    need(set(downloads) == {'format', 'kind', 'current', 'previous'}
         and type(downloads['format']) is int and downloads['format'] == 1
         and downloads['kind'] == 'go-erp-published-downloads', 'DOWNLOADS_SCHEMA_INVALID')
    current, previous = (publisher.validate_release(downloads[key]) for key in ('current', 'previous'))
    keep = [current, previous]
    need(current['sequence'] > previous['sequence']
         and publisher.compare_versions(current['version'], previous['version']) > 0
         and current['installer']['sha256'] != previous['installer']['sha256'], 'KEEP_ORDER_INVALID')
    publisher.validate_release(feed)
    need(feed == current and type(state.get('format')) is int and state['format'] == 1,
         'CANONICAL_FEED_MISMATCH')
    published, desired = state.get('published'), state.get('desired')
    need(isinstance(published, dict) and published.get('release') == current
         and published.get('commit') == current['source_revision']
         and published.get('run_id') == current['sequence']
         and state.get('version_floor') == current['version']
         and type(state.get('sequence_floor')) is int and state['sequence_floor'] >= current['sequence'],
         'PUBLISHED_STATE_MISMATCH')
    need(isinstance(desired, dict), 'DESIRED_STATE_MISSING')
    if desired.get('status') != 'published':
        raise BlockingIOError('CANDIDATE_PENDING')
    need(desired.get('version') == current['version'] and desired.get('commit') == current['source_revision']
         and desired.get('inventorySha256') == published.get('inventorySha256'), 'DESIRED_STATE_MISMATCH')
    history = state.get('published_history')
    need(isinstance(history, list) and current in history and previous in history, 'HISTORY_MISMATCH')
    ordered = {}
    for entry in history:
        publisher.validate_release(entry)
        seq = entry['sequence']
        need(seq not in ordered or ordered[seq] == entry, 'HISTORY_CONFLICT')
        need(seq <= current['sequence'] and publisher.compare_versions(entry['version'], current['version']) <= 0,
             'HISTORY_FUTURE_RELEASE')
        ordered[seq] = entry
    need(sorted(ordered, reverse=True)[:2] == [current['sequence'], previous['sequence']], 'NOT_LATEST_TWO')
    # Preserve updater state and its small historic receipts. Native pruning
    # explicitly tolerates already missing obsolete installer files.
    for hold in ('program-rollout-hold.json',):
        if (paths.live / hold).exists() or (paths.live / hold).is_symlink():
            raise BlockingIOError('ROLLOUT_HOLD_ACTIVE')
    protected = {release['installer']['sha256'] for release in keep}
    retained, candidates, skipped = [], [], 0
    for directory in (archive, public):
        names = os.listdir(directory.fd)
        for release in keep:
            name = release['installer']['sha256'] + '.exe'
            need(name in names, 'RETAINED_INSTALLER_MISSING')
            retained.append(Binary(directory, name, release['installer']))
        for name in sorted(names):
            if SHA.fullmatch(name) and name[:-4] not in protected:
                candidates.append(Binary(directory, name))
    acceptance_path = paths.live / 'program-acceptance'
    if acceptance_path.exists() or acceptance_path.is_symlink():
        acceptance = Directory(acceptance_path, stack)
        for name in sorted(os.listdir(acceptance.fd)):
            need(re.fullmatch(r'[a-f0-9]{40}-[A-Za-z0-9_-]+', name), 'ACCEPTANCE_DIRECTORY_NAME_INVALID')
            with ExitStack() as entry_stack:
                folder = Directory(acceptance_path / name, entry_stack)
                ci_path = folder.path / 'ci'
                if not (folder.path / 'acceptance.json').exists():
                    skipped += 1
                    continue
                receipt = Proof(folder, 'acceptance.json')
                envelope = receipt.value
                need(envelope.get('format') == 1 and envelope.get('kind') == 'go-erp-program-acceptance',
                     'ACCEPTANCE_SCHEMA_INVALID')
                version = envelope.get('version')
                if publisher.compare_versions(version, previous['version']) >= 0:
                    continue
                ci = Directory(ci_path, entry_stack)
                installer_name = 'GO-ERP-Preview-Setup.exe'
                if installer_name not in os.listdir(ci.fd):
                    continue
                ci_proof = Proof(ci, 'go-erp-update-release.json')
                release = publisher.validate_receipt(ci_proof.value)
                need(release['version'] == version and name.startswith(release['source_revision'] + '-')
                     and envelope.get('candidate_revision') == release['source_revision']
                     and envelope.get('installer') == release['installer']
                     and envelope.get('source') == release['source']
                     and isinstance(envelope.get('ci'), dict)
                     and envelope['ci'].get('directory') == str(ci_path)
                     and envelope['ci'].get('run_id') == release['sequence']
                     and type(envelope['ci'].get('run_attempt')) is int and envelope['ci']['run_attempt'] > 0
                     and release['installer']['sha256'] not in protected
                     and release['source_revision'] != desired['commit'], 'OBSOLETE_ACCEPTANCE_BINDING_INVALID')
                proofs.extend((receipt, ci_proof))
                candidates.append(Binary(ci, installer_name, release['installer']))
                stack.enter_context(entry_stack.pop_all())
    check_locks(locks)
    return keep, retained, candidates, proofs, locks, skipped


def fingerprint(keep, binaries, proofs):
    payload = {'keep': keep,
               'files': [(str(item.path), item.before) for item in binaries],
               'proofs': [(str(item.directory.path / item.name), item.before,
                           hashlib.sha256(item.raw).hexdigest()) for item in proofs]}
    return hashlib.sha256(canonical(payload)).hexdigest()


def save_cache(directory, value):
    name = '.success-' + uuid.uuid4().hex
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory.fd)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(canonical(value))
            stream.flush()
            os.fsync(stream.fileno())
        directory.check()
        if 'last-success.json' in os.listdir(directory.fd):
            existing = os.stat('last-success.json', dir_fd=directory.fd, follow_symlinks=False)
            regular(existing)
        os.replace(name, 'last-success.json', src_dir_fd=directory.fd, dst_dir_fd=directory.fd)
        os.fsync(directory.fd)
    finally:
        if name in os.listdir(directory.fd):
            os.unlink(name, dir_fd=directory.fd)


def run(paths, publisher, apply=False):
    with ExitStack() as stack:
        keep, retained, candidates, proofs, locks, skipped = plan(paths, publisher, stack)
        cache = Directory(paths.cache, stack)
        stamp = fingerprint(keep, retained, proofs)
        prior = None
        if 'last-success.json' in os.listdir(cache.fd):
            prior = Proof(cache, 'last-success.json').value
        fast = not candidates and prior == {'format': 1, 'fingerprint': stamp}
        # No large content reads on unchanged periodic runs. Every deletion
        # still requires freshly verified retained copies and candidate hashes.
        if not fast:
            for binary in (*retained, *candidates):
                binary.verify()
        for proof in proofs:
            proof.check()
        for binary in (*retained, *candidates):
            binary.check()
        check_locks(locks)
        count, allocated = 0, 0
        if apply:
            for binary in candidates:
                for proof in proofs:
                    proof.check()
                check_locks(locks)
                for keep_binary in retained:
                    keep_binary.check()
                item = os.stat(binary.name, dir_fd=binary.directory.fd, follow_symlinks=False)
                binary.unlink()
                count += 1
                allocated += item.st_blocks * 512
            save_cache(cache, {'format': 1, 'fingerprint': stamp})
        return {'status': 'applied' if apply else 'checked', 'scope': 'desktop_installers',
                'keep_versions': [entry['version'] for entry in keep],
                'planned_files': len(candidates), 'planned_bytes': sum(item.before[6] for item in candidates),
                'removed_files': count, 'reclaimed_bytes': allocated, 'unchanged_fast_path': fast,
                'unclassified_acceptance_directories': skipped,
                'metadata_preserved': True, 'database_changed': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    flags = parser.add_mutually_exclusive_group(required=True)
    flags.add_argument('--check', action='store_true')
    flags.add_argument('--apply', action='store_true')
    options = parser.parse_args()
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
        os.umask(0o077)
        with ExitStack() as stack:
            publisher = load_publisher(stack)
            result = run(Paths(), publisher, options.apply)
        print(canonical(result).decode().strip())
        return 0
    except BlockingIOError:
        print(canonical({'status': 'busy', 'code': 'GO_ERP_RETENTION_UPDATE_BUSY'}).decode().strip())
        return 75
    except Exception as error:
        code = str(error) if isinstance(error, Stop) else 'PRIVATE_VALIDATION_FAILURE'
        print(canonical({'status': 'blocked', 'code': 'GO_ERP_RETENTION_' + code}).decode().strip())
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
