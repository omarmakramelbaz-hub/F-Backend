#!/usr/bin/env python3
"""Read current dashboard source as root using immutable reviewed readers.

Git metadata and reviewed blobs are read as fasakha. No PHP, database, fetch,
checkout, permission change, source publication or application write occurs.
"""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys

ROOT = Path('/home/fasakha/public_html')
BASELINE_APP = '6472433078a882b5fa9d1d548b6baa325a89e06c'
SELF = 'deployment/diagnose_go_erp_current_source_root.py'
EXPECTED_HEAD = 'ee52f1798a542d2c5625c863112315a8aaba02cf'
EXPECTED_SOURCE = {'format': 1, 'files': 1164, 'framework': '8.83.29',
                   'sha256': '130b4e77cdff3a3b54168ebeb124a27d9bbfc92970d5d3d740c7231bd18ff8fb'}
PINS = {
    'deployment/desktop_dashboard_server_manifest.json': 'af5c48005b53263e5d9c9d1b717986b54bf496c3',
    'tests/fixtures/desktop-actual-server-baseline.json': '577b20aaf75788a1212fa320f9b0c617d8168430',
    'deployment/diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
    'deployment/desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
}
ENV = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha', 'GIT_OPTIONAL_LOCKS': '0',
       'GIT_NO_LAZY_FETCH': '1', 'GIT_NO_REPLACE_OBJECTS': '1',
       'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
MAX_PAYLOAD = 2 * 1024 * 1024
MAX_REPORT = 8 * 1024 * 1024
EXCEPTION_CLASSES = frozenset(('Stop', 'DiagnosisError', 'OSError', 'PermissionError',
    'FileNotFoundError', 'NotADirectoryError', 'TimeoutExpired', 'UnicodeDecodeError',
    'JSONDecodeError', 'ValueError', 'KeyError', 'TypeError', 'RuntimeError',
    'SyntaxError', 'AssertionError'))


class Stop(Exception):
    pass


class State:
    def __init__(self):
        self.phase = 'INVOCATION'
        self.scanner_error = ()


def need(ok, reason):
    if not ok:
        raise Stop(reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()


def git(arguments, maximum=MAX_PAYLOAD, runner=subprocess.run):
    command = ['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i']
    command += [name + '=' + value for name, value in ENV.items()]
    command += ['/usr/bin/git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
                '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT)] + list(arguments)
    try:
        value = runner(command, env=ENV, stdin=subprocess.DEVNULL,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Stop('GIT_UNAVAILABLE_OR_TIMED_OUT') from None
    need(value.returncode == 0, 'REVIEWED_GIT_READ_FAILED')
    need(len(value.stdout) <= maximum and len(value.stderr) <= 65536, 'GIT_OUTPUT_LIMIT')
    return value.stdout


def source_file(revision, name, expected=None, reader=git):
    row = reader(['ls-tree', '-z', revision, '--', name], 1024)
    match = re.fullmatch(rb'100(?:644|755) blob ([a-f0-9]{40})\t' + re.escape(name.encode('ascii')) + rb'\0', row)
    need(match is not None, 'REVIEWED_FILE_INVALID')
    pin = match[1].decode('ascii')
    need(expected is None or pin == expected, 'REVIEWED_FILE_PIN_MISMATCH')
    size = reader(['cat-file', '-s', pin], 32)
    need(re.fullmatch(rb'[0-9]{1,8}\n', size) is not None and 0 < int(size) <= MAX_PAYLOAD,
         'REVIEWED_FILE_SIZE_INVALID')
    data = reader(['cat-file', 'blob', pin], MAX_PAYLOAD)
    need(len(data) == int(size) and blob(data) == pin, 'REVIEWED_FILE_BYTES_INVALID')
    return data


def private_entry(path):
    need(path.is_absolute() and path.resolve() == path, 'ENTRYPOINT_UNSAFE')
    for parent in path.parents:
        item = parent.lstat()
        sticky = parent in (Path('/tmp'), Path('/var/tmp')) and item.st_mode & stat.S_ISVTX
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0
             and (not item.st_mode & 0o022 or sticky), 'ENTRYPOINT_ANCESTOR_UNSAFE')
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
         and stat.S_IMODE(before.st_mode) == 0o600 and 0 < before.st_size <= MAX_PAYLOAD,
         'ENTRYPOINT_UNSAFE')
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        key = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_uid,
            item.st_gid, item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)
        chunks = bytearray()
        while len(chunks) <= MAX_PAYLOAD:
            data = os.read(fd, min(65536, MAX_PAYLOAD + 1 - len(chunks)))
            if not data:
                break
            chunks.extend(data)
        need(len(chunks) == before.st_size and key(before) == key(os.fstat(fd)) == key(path.lstat()),
             'ENTRYPOINT_CHANGED')
        return bytes(chunks)
    finally:
        os.close(fd)


def payloads(reader=git):
    return {name: source_file(BASELINE_APP, name, pin, reader) for name, pin in PINS.items()}


def namespace(data, name):
    result = {'__name__': name}
    exec(compile(data, name, 'exec'), result)
    return result


def profile(payload):
    scanner = namespace(payload['deployment/diagnose_desktop_dashboard_current_source.py'], 'reviewed_root_source_scanner')
    source = namespace(payload['deployment/desktop_dashboard_server_preflight.py'], 'reviewed_root_source_preflight')
    fixture = json.loads(payload['tests/fixtures/desktop-actual-server-baseline.json'])
    manifest = json.loads(payload['deployment/desktop_dashboard_server_manifest.json'])
    before = {row['path']: row['before_blob'] for row in manifest['sources'] if row['before_blob'] is not None}
    before.update({row['path']: row['blob'] for row in manifest['protected']})
    actual = {name: item['blob'] for name, item in fixture['files'].items()}
    actual.update(fixture['gui'])
    need(len(fixture['files']) == 1164 and len(fixture['gui']) == 27
         and fixture['expected_head'] == manifest['expected_head'] == EXPECTED_HEAD
         and fixture['source'] == manifest['before_source'] == EXPECTED_SOURCE
         and dict(source['digest'](fixture['files']), framework='8.83.29') == EXPECTED_SOURCE
         and actual == before, 'REVIEWED_BASELINE_INCONSISTENT')
    return scanner, source, fixture, manifest


def head(reader=git):
    result = reader(['rev-parse', '--verify', 'HEAD'], 64)
    need(re.fullmatch(rb'[a-f0-9]{40}\n', result) is not None, 'HEAD_INVALID')
    return result[:-1].decode('ascii')


def diagnose(scanner, source, fixture, manifest, publication, state, root=ROOT, head_reader=head):
    state.scanner_error = scanner['DiagnosisError']
    gui = list(dict.fromkeys(list(fixture['gui']) + list(scanner['RELATED_GUI'])))
    state.phase = 'ROOT_SNAPSHOT_FIRST'
    before = scanner['snapshot'](root, source, gui, [], head_reader)
    state.phase = 'ROOT_SNAPSHOT_SECOND'
    after = scanner['snapshot'](root, source, gui, [], head_reader)
    state.phase = 'ROOT_SNAPSHOT_COMPARE'
    need(before == after, 'SNAPSHOT_CHANGED')
    state.phase = 'REDACTED_REPORT'
    result = scanner['report'](before, after, source, fixture, manifest, {'rows': []}, release=BASELINE_APP)
    for key in ('deployment_performed', 'metadata_changed', 'php_bootstrapped', 'database_read', 'source_content_printed'):
        need(result.get(key) is False, 'REPORT_PRIVACY_CONTRACT_INVALID')
    result['diagnostic_release'] = publication
    result['source_scanned_as'] = 'root'
    output = json.dumps(result, ensure_ascii=True, separators=(',', ':'))
    need(len(output.encode('ascii')) <= MAX_REPORT, 'REPORT_OUTPUT_LIMIT')
    return output


def safe_failure(error, state):
    name = type(error).__name__
    name = name if name in EXCEPTION_CLASSES else 'Exception'
    reason = 'UNEXPECTED_INSPECTION_FAILURE'
    if isinstance(error, Stop) and re.fullmatch(r'[A-Z][A-Z0-9_]{0,95}', str(error)):
        reason = str(error)
    elif isinstance(error, state.scanner_error) and re.fullmatch(r'DESKTOP_SOURCE_DIAGNOSIS_[A-Z_]{1,80}', str(error)):
        reason = str(error)
    phase = state.phase if re.fullmatch(r'[A-Z_]{1,40}', state.phase) else 'UNKNOWN'
    return 'GO_ERP_ROOT_SOURCE_DIAGNOSIS_STOPPED PHASE=' + phase + ' REASON=' + reason + ' CLASS=' + name


def execute(arguments, state):
    need(len(arguments) == 2 and arguments[0] == '--release' and re.fullmatch(r'[a-f0-9]{40}', arguments[1]),
         'PINNED_RELEASE_REQUIRED')
    publication = arguments[1]
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    try:
        uid = pwd.getpwnam('fasakha').pw_uid
    except KeyError:
        raise Stop('APPLICATION_ACCOUNT_UNAVAILABLE') from None
    need(ROOT.resolve() == ROOT, 'PROJECT_UNSAFE')
    for path in (ROOT, ROOT / '.git'):
        item = path.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == uid, 'PROJECT_UNSAFE')
    state.phase = 'REVIEWED_ENTRYPOINT'
    git(['merge-base', '--is-ancestor', BASELINE_APP, publication], 1024)
    need(private_entry(Path(__file__).absolute()) == source_file(publication, SELF), 'ENTRYPOINT_NOT_REVIEWED')
    state.phase = 'REVIEWED_PAYLOADS'
    payload = payloads()
    state.phase = 'REVIEWED_BASELINE'
    scanner, source, fixture, manifest = profile(payload)
    return diagnose(scanner, source, fixture, manifest, publication, state)


def main(arguments=None, out=sys.stdout, err=sys.stderr):
    state = State()
    try:
        output = execute(sys.argv[1:] if arguments is None else arguments, state)
    except Exception as error:
        print(safe_failure(error, state), file=err)
        return 1
    print('DESKTOP_CURRENT_SOURCE_DIAGNOSIS_BEGIN', file=out)
    print(output, file=out)
    print('DESKTOP_CURRENT_SOURCE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false', file=out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
