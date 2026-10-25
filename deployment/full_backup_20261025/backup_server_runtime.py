#!/usr/bin/env python3
"""Capture current root-owned GO ERP runtime data without following symlinks."""
import gzip
import hashlib
import json
import os
from contextlib import ExitStack
from pathlib import Path
import shutil
import signal
import stat
import subprocess
import tarfile

RESERVE = 3 * 1024 ** 3
FIXED_ROOTS = (Path('/etc/go-erp'), Path('/etc/fasakhansta'))
GLOB_ROOTS = ((Path('/opt'), 'go-erp-*'),
              (Path('/var/lib'), 'go-erp-*'),
              (Path('/etc'), 'fasakhansta-*'))


class RuntimeBackupError(RuntimeError):
    pass


def need(value, message):
    if not value:
        raise RuntimeBackupError(message)


def identity(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def safe_ancestors(path, include_self=True):
    need(path.is_absolute() and '..' not in path.parts, 'Runtime path is not absolute and canonical.')
    nodes = (*reversed(path.parents), path) if include_self else tuple(reversed(path.parents))
    for node in nodes:
        info = node.lstat()
        need(stat.S_ISDIR(info.st_mode), 'Runtime archive root has a linked or special ancestor.')


def discover_roots():
    selected = [path for path in FIXED_ROOTS if os.path.lexists(str(path))]
    for parent, pattern in GLOB_ROOTS:
        if parent.is_dir():
            selected.extend(parent.glob(pattern))
    result = []
    for path in sorted(set(selected), key=lambda item: (len(item.parts), str(item))):
        safe_ancestors(path, include_self=False)
        info = path.lstat()
        need(stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode),
             'Selected runtime root is linked or special.')
        if path.parent in (Path('/opt'), Path('/var/lib')):
            need(stat.S_ISDIR(info.st_mode), 'Selected runtime installation root is not a directory.')
        if not any(path == root or root in path.parents for root in result):
            result.append(path)
    need(result, 'No current server runtime roots were found.')
    return sorted(result)


def stable_file_hash(path, before):
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(os.fstat(fd)) == identity(before), 'Runtime file changed before hashing.')
        digest, size = hashlib.sha256(), 0
        while True:
            block = os.read(fd, 1024 ** 2)
            if not block:
                break
            size += len(block)
            need(size <= before.st_size, 'Runtime file grew while hashing.')
            digest.update(block)
        need(size == before.st_size and identity(os.fstat(fd)) == identity(before)
             and identity(path.lstat()) == identity(before), 'Runtime file changed while hashing.')
        return digest.hexdigest()
    finally:
        os.close(fd)


def inventory(roots):
    result = {}
    def failed(_):
        raise RuntimeBackupError('Cannot enumerate a selected runtime root.')
    def record(path):
        name = str(path).lstrip('/')
        need(name and '..' not in Path(name).parts and name not in result,
             'Runtime inventory has an invalid or duplicate path.')
        info = path.lstat()
        entry = {'mode': stat.S_IMODE(info.st_mode), 'uid': info.st_uid,
                 'gid': info.st_gid, 'identity': list(identity(info))}
        if stat.S_ISLNK(info.st_mode):
            entry.update(type='symlink', target=os.readlink(str(path)))
        elif stat.S_ISDIR(info.st_mode):
            entry['type'] = 'directory'
        elif stat.S_ISREG(info.st_mode):
            entry.update(type='file', size=info.st_size, sha256=stable_file_hash(path, info))
        else:
            raise RuntimeBackupError('Selected runtime data contains a special file.')
        result[name] = entry
    for root in roots:
        safe_ancestors(root, include_self=False)
        need(not root.is_symlink(), 'Selected runtime root is linked.')
        record(root)
        if not root.is_dir():
            continue
        for parent, directories, files in os.walk(str(root), followlinks=False, onerror=failed):
            directories.sort()
            files.sort()
            for name in directories + files:
                record(Path(parent) / name)
    return result


def unchanged(entries):
    for name, expected in entries.items():
        path = Path('/') / name
        need(list(identity(path.lstat())) == expected['identity'],
             'Runtime source changed during backup; the backup remains incomplete.')
        if expected['type'] == 'symlink':
            need(os.readlink(str(path)) == expected['target'], 'Runtime symlink changed during backup.')


def stop_child(process):
    if process.poll() is not None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        return
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()


def stream_archive(destination, roots):
    archive = destination / 'server-runtime.tar.gz'
    log = destination / 'logs/server-runtime-stream.log'
    arguments = ['tar', '--acls', '--xattrs', '--numeric-owner', '--format=pax',
                 '--hard-dereference', '-cf', '-', '-C', '/', '--']
    arguments.extend(str(path).lstrip('/') for path in roots)
    env = os.environ.copy()
    env['LC_ALL'] = 'C'
    with ExitStack() as stack:
        outfd = os.open(str(archive), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        output = stack.enter_context(os.fdopen(outfd, 'wb'))
        logfd = os.open(str(log), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        errors = stack.enter_context(os.fdopen(logfd, 'wb'))
        process = subprocess.Popen(arguments, stdout=subprocess.PIPE, stderr=errors,
                                   start_new_session=True, cwd='/', env=env)
        try:
            with gzip.GzipFile(filename='', fileobj=output, mode='wb', compresslevel=3, mtime=0) as compressed:
                while True:
                    block = process.stdout.read(1024 ** 2)
                    if not block:
                        break
                    need(shutil.disk_usage(str(destination)).free > RESERVE,
                         'Operating space reserve reached during runtime backup.')
                    compressed.write(block)
            output.flush()
            os.fsync(output.fileno())
            process.stdout.close()
            process.wait()
            need(process.returncode == 0, 'Runtime archive changed or failed; see the private backup log.')
        finally:
            stop_child(process)
            if process.stdout:
                process.stdout.close()
    return archive


def verify_archive(archive_path, entries):
    found = set()
    regular_bytes = 0
    with tarfile.open(str(archive_path), 'r|gz') as archive:
        for member in archive:
            name = member.name.rstrip('/')
            need(name and not name.startswith('/') and '..' not in Path(name).parts,
                 'Runtime archive contains an unsafe path.')
            need(name in entries and name not in found, 'Runtime archive has missing, extra or duplicate paths.')
            expected = entries[name]
            need(member.mode == expected['mode'] and member.uid == expected['uid']
                 and member.gid == expected['gid'], 'Runtime archive ownership or permissions differ.')
            kind = expected['type']
            need((kind == 'file' and member.isfile()) or (kind == 'directory' and member.isdir())
                 or (kind == 'symlink' and member.issym()), 'Runtime archive entry type differs.')
            if kind == 'symlink':
                need(member.linkname == expected['target'], 'Runtime archive symlink target differs.')
            if kind == 'file':
                need(member.size == expected['size'], 'Runtime archive file size differs.')
                digest, size = hashlib.sha256(), 0
                stream = archive.extractfile(member)
                need(stream is not None, 'Cannot read runtime archive file.')
                for block in iter(lambda: stream.read(1024 ** 2), b''):
                    digest.update(block)
                    size += len(block)
                need(size == expected['size'] and digest.hexdigest() == expected['sha256'],
                     'Runtime archive file content differs.')
                regular_bytes += size
            found.add(name)
    need(found == set(entries), 'Runtime archive does not cover the complete initial inventory.')
    return {'inventory_entries_verified': len(found), 'regular_file_bytes_verified': regular_bytes}


def backup(destination, core):
    destination = Path(destination)
    safe_ancestors(destination)
    info = destination.lstat()
    need(info.st_uid == 0 and not info.st_mode & 0o077, 'Runtime backup output is not private.')
    safe_ancestors(destination / 'logs')
    roots = discover_roots()
    need(not any(root == destination or root in destination.parents for root in roots),
         'Runtime backup output is nested in its source.')
    entries = inventory(roots)
    payload = json.dumps(entries, ensure_ascii=True, sort_keys=True, indent=2).encode() + b'\n'
    name = destination / 'server-runtime-inventory.json'
    fd = os.open(str(name), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as output:
        output.write(payload)
        output.flush()
        os.fsync(output.fileno())
    unchanged(entries)
    archive = stream_archive(destination, roots)
    unchanged(entries)
    verified = verify_archive(archive, entries)
    core.check_gzip(archive)
    unchanged(entries)
    return {'archive': archive.name, 'roots': [str(path) for path in roots],
            'inventory': name.name, 'inventory_entries': len(entries),
            'regular_files': sum(entry['type'] == 'file' for entry in entries.values()),
            'archive_bytes': archive.stat().st_size, 'sha256': core.sha256(archive),
            **verified}
