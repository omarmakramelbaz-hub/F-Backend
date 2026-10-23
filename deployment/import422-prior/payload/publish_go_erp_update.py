#!/usr/bin/env python3
"""Publish reviewed GO ERP CI bytes after the matching server source is installed.

Run as root on srv1836524 only. The receipt is an operator-reviewed CI input,
not a signature or an independent assertion that CI passed. Never manufacture
one from an untested installer. This command only writes the update publication
directory; it does not deploy application code, run PHP, or alter a database.
"""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import uuid

ROOT = Path('/home/fasakha/public_html')
HOST = 'srv1836524'
MAX_BYTES = 1024 ** 3
MAX_JSON = 512 * 1024
MAX_CONTRACT = 16384
MAX_SEQUENCE = 9007199254740991
SHA = re.compile(r'[a-f0-9]{64}\Z')
REVISION = re.compile(r'[a-f0-9]{40}\Z')
VERSION = re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\Z')
FRAMEWORK = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+\Z')
GUI = re.compile(r'public/(?:js/dashboard-[a-zA-Z0-9_.@-]*\.js|css/dashboard-[a-zA-Z0-9_.@-]*\.css)\Z')
READER_PINS = {
    'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
}


class PublicationError(Exception):
    pass


def need(condition, reason):
    if not condition:
        raise PublicationError('GO_ERP_UPDATE_' + reason)


def exact(value, keys):
    return isinstance(value, dict) and set(value) == set(keys)


def valid_source(value):
    return (exact(value, ('format', 'files', 'sha256', 'framework'))
            and type(value['format']) is int and value['format'] == 1
            and type(value['files']) is int and 0 < value['files'] <= 20000
            and isinstance(value['sha256'], str) and SHA.fullmatch(value['sha256'])
            and isinstance(value['framework'], str) and len(value['framework']) <= 100
            and FRAMEWORK.fullmatch(value['framework']))


def valid_gui(value):
    return (isinstance(value, dict) and len(value) <= 2000
            and all(isinstance(name, str) and GUI.fullmatch(name)
                    and isinstance(digest, str) and SHA.fullmatch(digest)
                    for name, digest in value.items())
            and len({name.lower() for name in value}) == len(value))


def snapshot_gui(value):
    need(all(item[1][5] == 1 and stat.S_ISREG(item[1][2])
             for item in value['snapshot']['additional_dashboard_gui'].values()), 'SERVER_GUI_INVALID')
    result = {name: item[0]['sha256']
              for name, item in value['snapshot']['additional_dashboard_gui'].items()}
    need(valid_gui(result), 'SERVER_GUI_INVALID')
    return result


def semantic_version(value):
    """Use the same bounded SemVer precedence rules as the native updater."""
    if not isinstance(value, str) or len(value) > 100:
        return None
    matched = VERSION.fullmatch(value)
    if matched is None:
        return None
    numbers = tuple(int(part) for part in matched.groups()[:3])
    prerelease = matched.group(4).split('.') if matched.group(4) is not None else []
    if (any(number > MAX_SEQUENCE for number in numbers)
            or any(re.fullmatch(r'[0-9]+', part)
                   and (len(part) > 1 and part[0] == '0' or int(part) > MAX_SEQUENCE)
                   for part in prerelease)):
        return None
    return numbers, prerelease


def compare_versions(left, right):
    first, second = semantic_version(left), semantic_version(right)
    need(first is not None and second is not None, 'VERSION_INVALID')
    if first[0] != second[0]:
        return 1 if first[0] > second[0] else -1
    a, b = first[1], second[1]
    if not a or not b:
        return 0 if not a and not b else -1 if a else 1
    for i in range(max(len(a), len(b))):
        if i >= len(a) or i >= len(b):
            return -1 if i >= len(a) else 1
        if a[i] == b[i]:
            continue
        an = re.fullmatch(r'[0-9]+', a[i]) is not None
        bn = re.fullmatch(r'[0-9]+', b[i]) is not None
        if an and bn:
            return 1 if int(a[i]) > int(b[i]) else -1
        if an != bn:
            return -1 if an else 1
        return 1 if a[i] > b[i] else -1
    return 0


def validate_release(value):
    need(exact(value, ('version', 'sequence', 'source_revision', 'source', 'installer', 'gui')), 'RELEASE_FIELDS')
    need(semantic_version(value['version']) is not None, 'VERSION_INVALID')
    need(type(value['sequence']) is int and 0 < value['sequence'] <= MAX_SEQUENCE, 'SEQUENCE_INVALID')
    need(isinstance(value['source_revision'], str) and REVISION.fullmatch(value['source_revision']), 'REVISION_INVALID')
    need(valid_source(value['source']), 'SOURCE_INVALID')
    need(valid_gui(value['gui']), 'GUI_INVALID')
    installer = value['installer']
    need(exact(installer, ('sha256', 'bytes', 'path'))
         and isinstance(installer['sha256'], str) and SHA.fullmatch(installer['sha256'])
         and type(installer['bytes']) is int and 2 <= installer['bytes'] <= MAX_BYTES
         and installer['path'] == '/api/desktop-dashboard/update/installer/' + installer['sha256'], 'INSTALLER_INVALID')
    return value


def validate_receipt(receipt):
    need(exact(receipt, ('format', 'kind', 'version', 'sequence', 'source_revision', 'source', 'installer', 'acceptance', 'gui'))
         and type(receipt['format']) is int and receipt['format'] == 1
         and receipt['kind'] == 'go-erp-desktop-release', 'RECEIPT_INVALID')
    acceptance = receipt['acceptance']
    need(exact(acceptance, ('windows_installer', 'runtime', 'connected', 'source_revision'))
         and all(acceptance[key] is True for key in ('windows_installer', 'runtime', 'connected'))
         and acceptance['source_revision'] == receipt['source_revision'], 'ACCEPTANCE_INCOMPLETE')
    installer = receipt['installer']
    need(exact(installer, ('sha256', 'bytes')), 'INSTALLER_INVALID')
    release = {key: receipt[key] for key in ('version', 'sequence', 'source_revision', 'source', 'gui')}
    release['installer'] = {**installer, 'path': '/api/desktop-dashboard/update/installer/' + str(installer['sha256'])}
    return validate_release(release)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def no_link_ancestors(path):
    need(path.is_absolute() and '..' not in path.parts, 'PATH_INVALID')
    for directory in reversed((path, *path.parents)):
        value = directory.lstat()
        need(stat.S_ISDIR(value.st_mode) and not stat.S_ISLNK(value.st_mode), 'DIRECTORY_UNSAFE')


def open_regular(path, maximum, root_owned=False):
    no_link_ancestors(path.parent)
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
         and 0 < before.st_size <= maximum, 'FILE_UNSAFE')
    if root_owned:
        need(before.st_uid == 0 and not before.st_mode & 0o022, 'PUBLICATION_OWNERSHIP_UNSAFE')
    descriptor = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(os.fstat(descriptor)) == identity(before), 'FILE_CHANGED')
        return descriptor, before
    except BaseException:
        os.close(descriptor)
        raise


def unchanged(path, descriptor, before):
    need(identity(before) == identity(os.fstat(descriptor)) == identity(path.lstat()), 'FILE_CHANGED')


def read_json(path, root_owned=False):
    descriptor, before = open_regular(path, MAX_JSON, root_owned)
    try:
        data = os.read(descriptor, MAX_JSON + 1)
        need(len(data) == before.st_size, 'JSON_LIMIT_OR_CHANGE')
        unchanged(path, descriptor, before)
        def unique(pairs):
            result = {}
            for key, value in pairs:
                need(key not in result, 'DUPLICATE_JSON_FIELD')
                result[key] = value
            return result
        return json.loads(data, object_pairs_hook=unique)
    finally:
        os.close(descriptor)


def readers():
    result = []
    folder = Path(__file__).absolute().parent
    for name, pin in READER_PINS.items():
        descriptor, before = open_regular(folder / name, 2 * 1024 ** 2)
        try:
            data = os.read(descriptor, 2 * 1024 ** 2 + 1)
            unchanged(folder / name, descriptor, before)
        finally:
            os.close(descriptor)
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        need(actual == pin, 'SOURCE_READER_PIN_MISMATCH')
        namespace = {'__name__': 'go_erp_pinned_source_reader'}
        exec(compile(data, name, 'exec'), namespace)
        result.append(namespace)
    return result


def source_snapshot(root):
    source, diagnosis = readers()
    # The descriptor-based reader excludes credentials and Laravel caches before
    # opening a source file, and refuses source links and concurrent changes.
    current = diagnosis['snapshot'](root, source, [], [], lambda: 'source-only')
    descriptor = diagnosis['open_root'](root)
    try:
        child = diagnosis['open_directory'](descriptor, 'desktop')
        try:
            contract_data, _ = diagnosis['read_file'](child, 'source-contract.json', MAX_CONTRACT)
        finally:
            os.close(child)
        lock_data, _ = diagnosis['read_file'](descriptor, 'composer.lock', 8 * 1024 ** 2)
    finally:
        os.close(descriptor)
    contract = json.loads(contract_data)
    need(exact(contract, ('format', 'framework', 'supportedRuntimeFrameworks'))
         and type(contract['format']) is int and contract['format'] == 1
         and isinstance(contract['framework'], str) and FRAMEWORK.fullmatch(contract['framework'])
         and isinstance(contract['supportedRuntimeFrameworks'], list)
         and 1 <= len(contract['supportedRuntimeFrameworks']) <= 4
         and all(isinstance(value, str) and FRAMEWORK.fullmatch(value)
                 for value in contract['supportedRuntimeFrameworks'])
         and len(set(contract['supportedRuntimeFrameworks'])) == len(contract['supportedRuntimeFrameworks'])
         and contract['framework'] in contract['supportedRuntimeFrameworks'], 'FRAMEWORK_CONTRACT_INVALID')
    lock = json.loads(lock_data)
    framework = [item.get('version', '').lstrip('v') for item in lock.get('packages', [])
                 if item.get('name') == 'laravel/framework']
    need(len(framework) == 1 and framework[0] in contract['supportedRuntimeFrameworks'], 'FRAMEWORK_CONTRACT_INVALID')
    fingerprint = {**source['digest'](current['files']), 'framework': contract['framework']}
    return {'fingerprint': fingerprint, 'snapshot': current, 'contract': contract_data, 'lock': lock_data}


def verify_installer(path, expected, root_owned=False):
    descriptor, before = open_regular(path, MAX_BYTES, root_owned)
    try:
        need(before.st_size == expected['bytes'], 'INSTALLER_SIZE_MISMATCH')
        digest = hashlib.sha256()
        total = 0
        first = True
        while True:
            data = os.read(descriptor, 1024 ** 2)
            if not data:
                break
            if first:
                need(data.startswith(b'MZ'), 'INSTALLER_NOT_WINDOWS_EXE')
                first = False
            total += len(data)
            need(total <= expected['bytes'], 'INSTALLER_SIZE_MISMATCH')
            digest.update(data)
        unchanged(path, descriptor, before)
        need(total == expected['bytes'] and digest.hexdigest() == expected['sha256'], 'INSTALLER_HASH_MISMATCH')
    finally:
        os.close(descriptor)


def copy_installer(source, directory_fd, expected):
    descriptor, before = open_regular(source, MAX_BYTES)
    temporary = '.installer-' + uuid.uuid4().hex + '.tmp'
    output = None
    try:
        need(before.st_size == expected['bytes'], 'INSTALLER_SIZE_MISMATCH')
        output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory_fd)
        digest = hashlib.sha256()
        total = 0
        first = True
        while True:
            data = os.read(descriptor, 1024 ** 2)
            if not data:
                break
            if first:
                need(data.startswith(b'MZ'), 'INSTALLER_NOT_WINDOWS_EXE')
                first = False
            total += len(data)
            need(total <= expected['bytes'], 'INSTALLER_SIZE_MISMATCH')
            digest.update(data)
            view = memoryview(data)
            while view:
                view = view[os.write(output, view):]
        unchanged(source, descriptor, before)
        need(total == expected['bytes'] and digest.hexdigest() == expected['sha256'], 'INSTALLER_HASH_MISMATCH')
        os.fchmod(output, 0o644)
        os.fsync(output)
        os.close(output)
        output = None
        # The locked publication directory serializes this command, and hard
        # linking refuses to replace any existing hash-addressed artifact.
        os.link(temporary, expected['sha256'] + '.exe', src_dir_fd=directory_fd,
                dst_dir_fd=directory_fd, follow_symlinks=False)
        os.unlink(temporary, dir_fd=directory_fd)
        os.fsync(directory_fd)
    finally:
        os.close(descriptor)
        if output is not None:
            os.close(output)
        try:
            os.unlink(temporary, dir_fd=directory_fd)
        except FileNotFoundError:
            pass


def publish(root, installer, receipt, snapshot=source_snapshot):
    """Internal root-injected entry point for isolated tests, never a CLI root override."""
    need(os.geteuid() == 0, 'ROOT_REQUIRED')
    root, installer = Path(root).absolute(), Path(installer).absolute()
    no_link_ancestors(root)
    release = validate_receipt(receipt)
    before = snapshot(root)
    need(before['fingerprint'] == release['source'], 'SERVER_SOURCE_MISMATCH')
    need(snapshot_gui(before) == release['gui'], 'SERVER_GUI_MISMATCH')
    parent = root / 'storage/app'
    no_link_ancestors(parent)
    directory = parent / 'desktop-updates'
    try:
        directory.mkdir(mode=0o755)
        os.chmod(str(directory), 0o755)
    except FileExistsError:
        pass
    named = directory.lstat()
    need(stat.S_ISDIR(named.st_mode) and named.st_uid == 0 and not named.st_mode & 0o022, 'PUBLICATION_OWNERSHIP_UNSAFE')
    fd = os.open(str(directory), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        need(identity(named) == identity(os.fstat(fd)), 'DIRECTORY_CHANGED')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise PublicationError('GO_ERP_UPDATE_PUBLICATION_BUSY') from None
        previous = None
        try:
            previous = validate_release(read_json(directory / 'current.json', root_owned=True))
        except FileNotFoundError:
            pass
        if previous is not None:
            need(release == previous or release['sequence'] > previous['sequence'], 'SEQUENCE_ROLLBACK_OR_REUSE')
            need(release == previous or compare_versions(release['version'], previous['version']) > 0, 'VERSION_ROLLBACK_OR_REUSE')
        artifact = directory / (release['installer']['sha256'] + '.exe')
        try:
            artifact.lstat()
        except FileNotFoundError:
            copy_installer(installer, fd, release['installer'])
        verify_installer(artifact, release['installer'], root_owned=True)
        after = snapshot(root)
        need(after == before and after['fingerprint'] == release['source'], 'SERVER_SOURCE_CHANGED')
        need(snapshot_gui(after) == release['gui'], 'SERVER_GUI_CHANGED')
        # Ensure the path still denotes the same owned directory before commit.
        no_link_ancestors(parent)
        need(directory.lstat().st_ino == os.fstat(fd).st_ino
             and directory.lstat().st_dev == os.fstat(fd).st_dev, 'DIRECTORY_CHANGED')
        if previous == release:
            return {'published': False, 'idempotent': True, 'release': release}
        data = (json.dumps(release, ensure_ascii=True, separators=(',', ':')) + '\n').encode('ascii')
        need(len(data) <= MAX_JSON, 'JSON_LIMIT_OR_CHANGE')
        temporary = '.current-' + uuid.uuid4().hex + '.tmp'
        output = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
        try:
            with os.fdopen(output, 'wb') as stream:
                stream.write(data)
                stream.flush()
                os.fchmod(stream.fileno(), 0o644)
                os.fsync(stream.fileno())
            os.replace(temporary, 'current.json', src_dir_fd=fd, dst_dir_fd=fd)
            os.fsync(fd)
        finally:
            try:
                os.unlink(temporary, dir_fd=fd)
            except FileNotFoundError:
                pass
        return {'published': True, 'idempotent': False, 'release': release}
    finally:
        os.close(fd)


def main(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--installer', required=True, help='Reviewed CI-produced Windows installer; never an untested local build')
    parser.add_argument('--receipt', required=True, help='Reviewed CI acceptance receipt for these exact installer/source bytes')
    options = parser.parse_args(arguments)
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    result = publish(ROOT, Path(options.installer), read_json(Path(options.receipt).absolute()))
    print('GO_ERP_UPDATE_PUBLISHED' if result['published'] else 'GO_ERP_UPDATE_ALREADY_PUBLISHED')
    print('VERSION=' + result['release']['version'])
    print('SOURCE=' + result['release']['source']['sha256'])
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except PublicationError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print('GO_ERP_UPDATE_PUBLICATION_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        sys.exit(1)
