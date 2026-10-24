#!/usr/bin/env python3
"""Retain the current and previous genuinely published desktop installers."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import sys
import types
import uuid

PUBLISHER = Path('/opt/go-erp-live-updates/releases/policy-acf5db0bbd0d415d753c0081a43de7b02b38509546a25382d69eb8aedbb7cc0a/publish_go_erp_update.py')
PUBLISHER_SHA256 = '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'
PUBLISHER_BLOB = 'f9a7353b0705383844a576ab6d0f5b1662912bfb'
MAX_MANIFEST = 1024 * 1024
SAFE = 9007199254740991
KIND = 'go-erp-published-downloads'


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_PUBLISHED_DOWNLOADS_' + code)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
        value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def parse(raw):
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, 'JSON_INVALID')
            result[key] = value
        return result
    def invalid(value):
        raise Stop('GO_ERP_PUBLISHED_DOWNLOADS_JSON_INVALID')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    need(isinstance(value, dict), 'JSON_INVALID')
    return value


def ancestors(path, root_owned=False):
    need(path.is_absolute() and path.resolve() == path, 'PATH_UNSAFE')
    for parent in (*reversed(path.parents), path):
        info = parent.lstat()
        sticky = parent != path and bool(info.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(info.st_mode) and (not root_owned or info.st_uid == 0)
            and (not root_owned or not info.st_mode & 0o022 or sticky), 'DIRECTORY_UNSAFE')


def read(path, maximum, modes, gid=None, optional=False):
    ancestors(path.parent)
    try:
        before = path.lstat()
    except FileNotFoundError:
        if optional:
            return None
        raise
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
        and (gid is None or before.st_gid == gid) and stat.S_IMODE(before.st_mode) in modes
        and 0 < before.st_size <= maximum, 'FILE_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(before) == identity(os.fstat(fd)), 'FILE_CHANGED')
        chunks, size = [], 0
        while size <= maximum:
            part = os.read(fd, min(65536, maximum + 1 - size))
            if not part:
                break
            chunks.append(part)
            size += len(part)
        need(size == before.st_size and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()),
            'FILE_CHANGED')
        return b''.join(chunks), identity(before)
    finally:
        os.close(fd)


def load_publisher(path=PUBLISHER):
    ancestors(path.parent, root_owned=True)
    raw, before = read(path, 2 * 1024**2, (0o644,), gid=0)
    need(hashlib.sha256(raw).hexdigest() == PUBLISHER_SHA256
        and hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() == PUBLISHER_BLOB,
        'PUBLISHER_PIN_INVALID')
    module = types.ModuleType('go_erp_downloads_pinned_publisher')
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(raw, str(path), 'exec'), module.__dict__)
    return module


def directory(path, gid, create=False):
    ancestors(path.parent, root_owned=True)
    try:
        info = path.lstat()
    except FileNotFoundError:
        need(create, 'DIRECTORY_MISSING')
        path.mkdir(mode=0o700)
        os.chown(path, 0, gid)
        os.chmod(path, 0o750)
        info = path.lstat()
    need(stat.S_ISDIR(info.st_mode) and (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) == (0, gid, 0o750),
        'DIRECTORY_UNSAFE')
    return info


def valid_release(publisher, value):
    result = publisher.validate_release(value)
    need(len(canonical(result)) <= publisher.MAX_JSON, 'RELEASE_LIMIT')
    return result


def manifest(publisher, value):
    need(set(value) == {'format', 'kind', 'current', 'previous'} and type(value['format']) is int
        and value['format'] == 1 and value['kind'] == KIND, 'MANIFEST_INVALID')
    current = valid_release(publisher, value['current'])
    previous = value['previous']
    if previous is not None:
        valid_release(publisher, previous)
        need(previous['sequence'] < current['sequence']
            and publisher.compare_versions(previous['version'], current['version']) < 0, 'GENERATION_ORDER_INVALID')
    return value


def native_release(publisher, state):
    need(type(state.get('format')) is int and state['format'] == 1, 'NATIVE_STATE_INVALID')
    published = state.get('published')
    if published is None:
        return None
    need(isinstance(published, dict), 'NATIVE_STATE_INVALID')
    release = valid_release(publisher, published.get('release'))
    need(published.get('commit') == release['source_revision']
        and isinstance(published.get('inventorySha256'), str)
        and re.fullmatch(r'[a-f0-9]{64}', published['inventorySha256'])
        and type(published.get('run_id')) is int and 0 < published['run_id'] <= SAFE
        and published['run_id'] == release['sequence']
        and type(published.get('run_attempt')) is int and 0 < published['run_attempt'] <= SAFE
        and state.get('version_floor') == release['version']
        and type(state.get('sequence_floor')) is int and state['sequence_floor'] >= release['sequence']
        and isinstance(state.get('published_history'), list) and release in state['published_history'],
        'PUBLISHED_IDENTITY_INVALID')
    return release


def verified_archive_binary(publisher, folder, release, gid):
    target = folder / (release['installer']['sha256'] + '.exe')
    item = target.lstat()
    need(stat.S_ISREG(item.st_mode) and (item.st_uid, item.st_gid, item.st_nlink, stat.S_IMODE(item.st_mode))
        == (0, gid, 1, 0o640), 'ARCHIVED_INSTALLER_UNSAFE')
    publisher.verify_installer(target, release['installer'], root_owned=True)
    need(identity(item) == identity(target.lstat()), 'ARCHIVED_INSTALLER_CHANGED')


def copy_installer(publisher, source, folder, release, gid):
    target = folder / (release['installer']['sha256'] + '.exe')
    if target.exists() or target.is_symlink():
        verified_archive_binary(publisher, folder, release, gid)
        return
    source_fd, source_before = publisher.open_regular(source, publisher.MAX_BYTES, root_owned=True)
    temporary = folder / ('.installer-' + uuid.uuid4().hex)
    output = None
    try:
        need(source_before.st_size == release['installer']['bytes'], 'INSTALLER_SIZE_MISMATCH')
        output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        digest, total, first = hashlib.sha256(), 0, True
        while True:
            part = os.read(source_fd, 1024**2)
            if not part:
                break
            if first:
                need(part.startswith(b'MZ'), 'INSTALLER_NOT_WINDOWS_EXE')
                first = False
            total += len(part)
            need(total <= release['installer']['bytes'], 'INSTALLER_SIZE_MISMATCH')
            digest.update(part)
            view = memoryview(part)
            while view:
                count = os.write(output, view)
                need(count > 0, 'COPY_FAILED')
                view = view[count:]
        publisher.unchanged(source, source_fd, source_before)
        need(total == release['installer']['bytes'] and digest.hexdigest() == release['installer']['sha256'],
            'INSTALLER_HASH_MISMATCH')
        os.fchown(output, 0, gid)
        os.fchmod(output, 0o640)
        os.fsync(output)
        os.close(output)
        output = None
        # Rename commits a separate inode; native publication pruning cannot
        # remove an archived download or interrupt its open stream.
        need(not target.exists() and not target.is_symlink(), 'ARCHIVE_TARGET_CHANGED')
        os.rename(temporary, target)
        fd = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:os.fsync(fd)
        finally:os.close(fd)
        verified_archive_binary(publisher, folder, release, gid)
    finally:
        os.close(source_fd)
        if output is not None:os.close(output)
        if temporary.exists():temporary.unlink()


def summary(value, changed, source):
    return {'format': 1, 'kind': KIND + '-result', 'available': value is not None,
        'changed': changed, 'current_version': value['current']['version'] if value else None,
        'previous_version': value['previous']['version'] if value and value['previous'] else None,
        'source': source, 'application_contents_changed': False,
        'database_changed': False, 'credentials_disclosed': False}


def archive_current(application_root, state_path, shared_path, web_gid):
    need(os.geteuid() == 0 and type(web_gid) is int and 0 <= web_gid <= 2**32-1, 'ROOT_REQUIRED')
    application_root, state_path, shared_path = map(Path, (application_root, state_path, shared_path))
    publisher = load_publisher()
    ancestors(state_path.parent, root_owned=True)
    shared_before = directory(shared_path, web_gid)
    folder = shared_path / 'installers'
    directory(folder, web_gid, create=True)
    lock = shared_path / '.downloads.lock'
    fd = os.open(lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
            and stat.S_IMODE(info.st_mode) == 0o600, 'LOCK_UNSAFE')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        path = shared_path / 'downloads.json'
        old = read(path, MAX_MANIFEST, (0o640,), gid=web_gid, optional=True)
        retained = manifest(publisher, parse(old[0])) if old else None
        if retained:
            for release in (retained['current'], retained['previous']):
                if release is not None:verified_archive_binary(publisher, folder, release, web_gid)
        state_raw = read(state_path, 16 * 1024**2, (0o600,), gid=0)
        state = parse(state_raw[0])
        release = native_release(publisher, state)
        feed_path = application_root / 'storage/app/desktop-updates/current.json'
        feed_raw = read(feed_path, publisher.MAX_JSON, (0o600, 0o644), optional=True)
        feed = valid_release(publisher, parse(feed_raw[0])) if feed_raw else None
        need(feed is None or feed == release, 'CANONICAL_STATE_MISMATCH')
        if release is None:
            return summary(retained, False, 'retained' if retained else 'none')
        if retained and release != retained['current']:
            need(release['sequence'] > retained['current']['sequence']
                and publisher.compare_versions(release['version'], retained['current']['version']) > 0,
                'OLDER_OR_CONFLICTING_PUBLICATION')
        source = application_root / 'storage/app/desktop-updates' / (release['installer']['sha256'] + '.exe')
        copy_installer(publisher, source, folder, release, web_gid)
        wanted = {'format': 1, 'kind': KIND, 'current': release,
            'previous': retained['previous'] if retained and retained['current'] == release
                else retained['current'] if retained else None}
        payload = canonical(wanted)
        need(len(payload) <= MAX_MANIFEST, 'MANIFEST_LIMIT')
        # Bind the native state and feed throughout the possibly large copy.
        need(read(state_path, 16 * 1024**2, (0o600,), gid=0) == state_raw,
            'NATIVE_STATE_CHANGED')
        need(read(feed_path, publisher.MAX_JSON, (0o600, 0o644), optional=True) == feed_raw,
            'CANONICAL_FEED_CHANGED')
        need(read(path, MAX_MANIFEST, (0o640,), gid=web_gid, optional=True) == old,
            'ARCHIVE_MANIFEST_CHANGED')
        need(identity(shared_before)[:5] == identity(shared_path.lstat())[:5], 'DIRECTORY_CHANGED')
        if retained == wanted:
            return summary(retained, False, 'canonical' if feed is not None else 'published-state')
        temporary = shared_path / ('.downloads-' + uuid.uuid4().hex)
        output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(output, 'wb') as stream:
                stream.write(payload)
                stream.flush()
                os.fchown(stream.fileno(), 0, web_gid)
                os.fchmod(stream.fileno(), 0o640)
                os.fsync(stream.fileno())
            need(read(path, MAX_MANIFEST, (0o640,), gid=web_gid, optional=True) == old,
                'ARCHIVE_MANIFEST_CHANGED')
            os.replace(temporary, path)
            parent = os.open(shared_path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:os.fsync(parent)
            finally:os.close(parent)
        finally:
            if temporary.exists():temporary.unlink()
        return summary(wanted, True, 'canonical' if feed is not None else 'published-state')
    finally:
        os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive-current', action='store_true', required=True)
    parser.parse_args()
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
        os.umask(0o077)
        result = archive_current(Path('/home/fasakha/public_html'),
            Path('/var/lib/go-erp-live-updates/state/current.json'),
            Path('/var/lib/go-erp-manual-updates/shared'), pwd.getpwnam('fasakha').pw_gid)
        print('GO_ERP_PUBLISHED_DOWNLOADS ' + json.dumps(result, sort_keys=True))
        return 0
    except Exception as error:
        code = str(error) if type(error) is Stop else 'GO_ERP_PUBLISHED_DOWNLOADS_PRIVATE_FAILURE'
        print(code, file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
