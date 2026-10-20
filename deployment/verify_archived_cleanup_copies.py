"""Verify archived cleanup candidates byte for byte, without extraction or writes."""
import gzip
import hashlib
import os
import posixpath
import stat
import tarfile
import zlib


class CoverageError(Exception):
    pass


def need(condition, message):
    if not condition:
        raise CoverageError(message)


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_size,
            info.st_mtime_ns, info.st_ctime_ns, info.st_nlink)


def digest_stream(stream):
    digest = hashlib.sha256()
    count = 0
    while True:
        block = stream.read(1024 * 1024)
        if not block:
            return count, digest.hexdigest()
        count += len(block)
        digest.update(block)


def hash_live(path, expected):
    need(hasattr(os, 'O_NOFOLLOW'), 'Safe file opening is unavailable.')
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            before = os.fstat(stream.fileno())
            need(stat.S_ISREG(before.st_mode) and signature(before) == expected,
                 'Live file changed before hashing: ' + path)
            count, digest = digest_stream(stream)
            need(signature(os.fstat(stream.fileno())) == expected and count == before.st_size,
                 'Live file changed while hashing: ' + path)
        need(signature(os.lstat(path)) == expected, 'Live file changed after hashing: ' + path)
        return digest
    except OSError:
        raise CoverageError('Cannot safely hash live file: ' + path)


def inventory(home, candidates, hashes=True):
    result = {}
    def entry(path):
        info = os.lstat(path)
        kind = ('file' if stat.S_ISREG(info.st_mode) else
                'directory' if stat.S_ISDIR(info.st_mode) else
                'symlink' if stat.S_ISLNK(info.st_mode) else 'other')
        need(kind != 'other', 'Special file in cleanup candidate: ' + path)
        row = {'type': kind, 'signature': signature(info), 'size': info.st_size}
        if kind == 'file' and hashes:
            row['sha256'] = hash_live(path, row['signature'])
        if kind == 'symlink':
            row['target'] = os.readlink(path)
            need(signature(os.lstat(path)) == row['signature'], 'Symlink changed: ' + path)
        result[os.path.relpath(path, home)] = row
    def walk_error(error):
        raise CoverageError('Cannot inventory cleanup candidate: ' + str(error.filename))
    for root in candidates:
        try:
            need(os.path.realpath(root) == root, 'Indirect cleanup candidate: ' + root)
            entry(root)
            need(result[os.path.relpath(root, home)]['type'] in ('file', 'directory'),
                 'Cleanup candidate root is a symlink: ' + root)
            if os.path.isdir(root):
                for parent, dirs, files in os.walk(root, followlinks=False, onerror=walk_error):
                    for name in sorted(dirs + files):
                        entry(os.path.join(parent, name))
        except FileNotFoundError:
            raise CoverageError('Missing cleanup candidate or entry: ' + root)
        except OSError:
            raise CoverageError('Cannot inventory cleanup candidate: ' + root)
    return result


def archive_name(value):
    while value.startswith('./'):
        value = value[2:]
    need(not value.startswith('/') and '..' not in value.split('/'),
         'Unsafe path in home archive.')
    return posixpath.normpath(value or '.')


def verify(home_path, archive_path, candidates):
    home = os.path.abspath(str(home_path))
    need(os.path.isdir(home) and os.path.realpath(home) == home, 'Invalid retained account home.')
    paths = [os.path.abspath(str(path)) for path in candidates]
    need(paths and len(set(paths)) == len(paths), 'Missing or duplicate cleanup candidates.')
    need(all(path.startswith(home + '/') for path in paths), 'Cleanup candidate is outside account home.')
    need(not any(left != right and left.startswith(right + '/') for left in paths for right in paths),
         'Overlapping cleanup candidates are unsupported.')
    expected = inventory(home, paths)
    found, seen, prior_files = set(), set(), {}
    try:
        with gzip.open(str(archive_path), 'rb') as compressed:
            with tarfile.open(fileobj=compressed, mode='r|') as archive:
                for member in archive:
                    name = archive_name(member.name)
                    need(name not in seen, 'Duplicate entry in home archive: ' + name)
                    seen.add(name)
                    archived_file = None
                    if member.isfile():
                        stream = archive.extractfile(member)
                        need(stream is not None, 'Unreadable regular archive member: ' + name)
                        archived_file = digest_stream(stream)
                        need(archived_file[0] == member.size, 'Truncated archive member: ' + name)
                        prior_files[name] = archived_file
                    elif member.islnk():
                        target = archive_name(member.linkname)
                        if name in expected:
                            need(target in prior_files, 'Unsupported forward or missing archive hardlink: ' + name)
                        archived_file = prior_files.get(target)
                        if archived_file is not None:
                            prior_files[name] = archived_file
                    if name not in expected:
                        continue
                    row = expected[name]
                    if row['type'] == 'file':
                        need(archived_file == (row['size'], row['sha256']),
                             'Archived file differs from live cleanup candidate: ' + name)
                    elif row['type'] == 'directory':
                        need(member.isdir(), 'Archived directory type mismatch: ' + name)
                    else:
                        need(member.issym() and member.linkname == row['target'],
                             'Archived symlink target mismatch: ' + name)
                    found.add(name)
            # Consume the gzip trailer as well; verify its CRC and truncation state.
            while compressed.read(1024 * 1024):
                pass
    except (OSError, EOFError, tarfile.TarError, zlib.error):
        raise CoverageError('Cannot completely read the retained home archive.')
    missing = sorted(set(expected) - found)
    need(not missing, 'Home archive is missing cleanup candidate entry: ' + (missing[0] if missing else ''))
    after = inventory(home, paths, hashes=False)
    baseline = {name: {key: value for key, value in row.items() if key != 'sha256'}
                for name, row in expected.items()}
    need(after == baseline, 'Cleanup candidates changed during archive verification.')
    report = {}
    for path in paths:
        prefix = os.path.relpath(path, home)
        files = sum(row['type'] == 'file' for name, row in expected.items()
                    if name == prefix or name.startswith(prefix + '/'))
        report[path] = {'verified': True, 'files': files, 'sha256_verified': files}
    return report
