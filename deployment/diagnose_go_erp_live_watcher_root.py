#!/usr/bin/env python3
"""Read-only diagnosis of the reviewed first live-source watcher checkpoints."""
import contextlib
import fcntl
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
import types

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
BASE = '75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
PARENT = '076c5a24973c0ddced4c68285a56fbe6294d4f68'
INSTALL = Path('/opt/go-erp-live-updates/releases') / BASE
SELF = 'deployment/diagnose_go_erp_live_watcher_root.py'
UID, GID = 1001, 1003
PINNED = {'go_erp_live_source.py': '4ddb4c9a6dd4011b8308075ca330fe165304ae31',
          'go_erp_live_updates.py': 'a916fd72c20699ca1710ca188a6999db02dfbe07'}
REV = re.compile(r'[a-f0-9]{40}\Z')
CODE = re.compile(r'GO_ERP_LIVE_[A-Z_]{1,100}\Z')
CLASSES = frozenset(('Stop', 'SourceError', 'WatchError', 'OSError', 'PermissionError',
    'FileNotFoundError', 'NotADirectoryError', 'ValueError', 'KeyError', 'TypeError',
    'RuntimeError', 'RecursionError', 'UnicodeDecodeError', 'JSONDecodeError'))


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_LIVE_DIAGNOSIS_' + code)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def directory_identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid)


class Directory:
    """Hold every trusted root-owned ancestor and reject named replacement."""
    def __init__(self, path, mode=None):
        need(path.is_absolute() and '..' not in path.parts, 'DIRECTORY_UNSAFE')
        self.rows = []
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.rows.append((fd, os.fstat(fd), None, None))
        try:
            for index, name in enumerate(path.parts[1:]):
                parent = fd
                fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                value = os.fstat(fd)
                self.rows.append((fd, value, parent, name))
                sticky = index < len(path.parts)-2 and bool(value.st_mode & stat.S_ISVTX)
                need(stat.S_ISDIR(value.st_mode) and value.st_uid == 0
                     and (not value.st_mode & 0o022 or sticky), 'DIRECTORY_UNSAFE')
            self.fd = fd
            if mode is not None:
                need(stat.S_IMODE(os.fstat(fd).st_mode) == mode, 'DIRECTORY_MODE_INVALID')
            self.verify()
        except BaseException:
            self.close()
            raise

    def verify(self):
        for fd, value, parent, name in self.rows:
            need(directory_identity(os.fstat(fd)) == directory_identity(value), 'DIRECTORY_CHANGED')
            if parent is not None:
                need(directory_identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == directory_identity(value), 'DIRECTORY_CHANGED')

    def read(self, name, mode, maximum, allowempty=False):
        need('/' not in name and name not in ('', '.', '..'), 'FILE_SCOPE_INVALID')
        self.verify()
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.fd)
        try:
            before = os.fstat(fd)
            need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
                 and stat.S_IMODE(before.st_mode) == mode and (allowempty or before.st_size > 0)
                 and before.st_size <= maximum, 'FILE_UNSAFE')
            result = bytearray()
            while len(result) <= maximum:
                part = os.read(fd, min(65536, maximum+1-len(result)))
                if not part:
                    break
                result.extend(part)
            need(len(result) == before.st_size and identity(before) == identity(os.fstat(fd))
                 == identity(os.stat(name, dir_fd=self.fd, follow_symlinks=False)), 'FILE_CHANGED')
            self.verify()
            return bytes(result)
        finally:
            os.close(fd)

    def close(self):
        for fd, *_ in reversed(self.rows):
            os.close(fd)
        self.rows = []


@contextlib.contextmanager
def existing_lock(work):
    directory = Directory(work, 0o700)
    fd = None
    try:
        fd = os.open('watcher.lock', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
             and stat.S_IMODE(before.st_mode) == 0o600, 'LOCK_UNSAFE')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('GO_ERP_LIVE_DIAGNOSIS_WATCHER_BUSY') from None
        directory.verify()
        need(identity(before) == identity(os.stat('watcher.lock', dir_fd=directory.fd, follow_symlinks=False)), 'LOCK_CHANGED')
        yield
        need(identity(before) == identity(os.fstat(fd))
             == identity(os.stat('watcher.lock', dir_fd=directory.fd, follow_symlinks=False)), 'LOCK_CHANGED')
        directory.verify()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()


def load_installed(path, pins=PINNED):
    directory = Directory(path, 0o755)
    modules = []
    try:
        for name, pin in pins.items():
            data = directory.read(name, 0o644, 512*1024)
            need(blob(data) == pin, 'INSTALLED_HELPER_PIN_INVALID')
            module = types.ModuleType('reviewed_readonly_' + name[:-3])
            module.__file__ = str(path/name)
            exec(compile(data, str(path/name), 'exec'), module.__dict__)
            modules.append(module)
        directory.verify()
        return tuple(modules)
    finally:
        directory.close()


def safe_state(state):
    need(isinstance(state, dict) and state.get('format') == 1 and state.get('base_revision') == BASE
         and type(state.get('counter')) is int and 0 <= state['counter'] <= 9007199254740991, 'STATE_INVALID')
    result = {'counter': state['counter'], 'observed': state.get('observed') is not None,
              'desired': state.get('desired') is not None, 'published': state.get('published') is not None}
    desired = state.get('desired')
    if isinstance(desired, dict):
        for key in ('commit', 'version', 'status', 'failures', 'pushed'):
            value = desired.get(key)
            valid = (key == 'commit' and isinstance(value, str) and REV.fullmatch(value)
                or key == 'version' and isinstance(value, str) and re.fullmatch(r'[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}-preview\.[0-9]{1,16}', value)
                or key == 'status' and value in ('prepared', 'pushed', 'published', 'blocked', 'retry-build')
                or key == 'failures' and type(value) is int and 0 <= value <= 100
                or key == 'pushed' and type(value) is bool)
            if valid:
                result[key] = value
    return result


def failure(error, phase, source=None, worker=None):
    kind = type(error).__name__
    result = {'phase': phase, 'ok': False, 'exception_class': kind if kind in CLASSES else 'OtherException',
              'reason': 'PRIVATE_FAILURE'}
    approved = (Stop,) + (() if source is None else (source.SourceError,)) + (() if worker is None else (worker.WatchError,))
    if isinstance(error, approved) and CODE.fullmatch(str(error)):
        result['reason'] = str(error)
    checkpoints = {'_trusted_git': 'TRUSTED_SEED', '_seed': 'SEED', '_live_pass': 'LIVE_PASS',
                   'visit': 'LIVE_PASS', '_read_at': 'SOURCE_FILE', 'scan_snapshot': 'SOURCE_SNAPSHOT'}
    current, seen = error, set()
    for _ in range(4):
        if current is None or id(current) in seen:
            break
        seen.add(id(current))
        trace = current.__traceback__
        while trace is not None:
            frame = trace.tb_frame
            if source is not None and frame.f_globals is source.__dict__:
                if frame.f_code.co_name in checkpoints:
                    result['checkpoint'] = checkpoints[frame.f_code.co_name]
                for key in ('name', 'relative'):
                    name = frame.f_locals.get(key)
                    if (isinstance(name, str) and source._path_ok(name) and source._in_scope(name)
                            and not source._excluded(name) and re.fullmatch(r'[A-Za-z0-9_./@-]{1,512}', name)):
                        result['safe_path'] = name
            trace = trace.tb_next
        current = current.__context__
    return result


def probe(live, work, source, worker):
    report = {'format': 1, 'kind': 'go-erp-live-watcher-readonly', 'application_contents_changed': False,
              'database_changed': False, 'credentials_read': False}
    phase = 'LOCK'
    try:
        with existing_lock(work):
            phase = 'STATE'
            directory = Directory(work/'state', 0o700)
            try:
                # Validate the private parent and state descriptor before the original parser.
                expected = directory.read('current.json', 0o600, worker.MAX_JSON)
                actual = worker.regular_bytes(work/'state/current.json', worker.MAX_JSON, private=True)
                need(actual == expected, 'STATE_CHANGED')
                state = worker.parse_json(actual)
                report['state'] = safe_state(state)
                directory.verify()
            finally:
                directory.close()
            phase = 'DEPENDENCIES'
            dependencies = worker.dependency_snapshot(live, UID)
            report['dependency_guard_matches'] = state.get('dependency_guard') in (None, dependencies)
            if not report['dependency_guard_matches']:
                raise worker.WatchError('GO_ERP_LIVE_DEPENDENCY_CHANGE_REQUIRES_REVIEWED_SEED')
            phase = 'SOURCE'
            snapshot = source.scan_snapshot(live, work/'checkout', UID, state.get('observed'))
            report['source_files'] = len(snapshot['metadata']['inventory'])
            report.update(phase='DONE', ok=True)
    except Exception as error:
        report.update(failure(error, phase, source, worker))
    return report


def git(arguments, maximum=512*1024):
    environment = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha', 'GIT_NO_REPLACE_OBJECTS': '1',
        'GIT_NO_LAZY_FETCH': '1', 'GIT_OPTIONAL_LOCKS': '0', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
    result = subprocess.run(['/usr/sbin/runuser', '-u', 'fasakha', '--', '/usr/bin/env', '-i',
        *[key+'='+value for key,value in environment.items()], '/usr/bin/git', '--no-optional-locks',
        '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', '-C', str(ROOT), *arguments],
        env=environment, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    need(result.returncode == 0 and len(result.stdout) <= maximum and len(result.stderr) <= 65536, 'REVIEWED_GIT_UNAVAILABLE')
    return result.stdout


def execute(args):
    need(len(args) == 2 and args[0] == '--release' and REV.fullmatch(args[1]), 'PINNED_INVOCATION_REQUIRED')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    actor = pwd.getpwnam('fasakha')
    need((actor.pw_uid, actor.pw_gid) == (UID, GID) and ROOT.resolve() == ROOT, 'PROJECT_ACCOUNT_UNSAFE')
    git(['merge-base', '--is-ancestor', PARENT, args[1]], 1024)
    row = git(['ls-tree', '-z', args[1], '--', SELF], 1024)
    match = re.fullmatch(rb'100644 blob ([a-f0-9]{40})\t'+re.escape(SELF.encode())+rb'\0', row)
    need(match is not None, 'REVIEWED_ENTRY_MISSING')
    pin = match[1].decode('ascii')
    size = git(['cat-file', '-s', pin], 32)
    need(re.fullmatch(rb'[0-9]{1,6}\n', size) and 0 < int(size) <= 512*1024, 'REVIEWED_ENTRY_SIZE')
    entry = Path(__file__).absolute()
    directory = Directory(entry.parent, 0o700)
    try:
        data = directory.read(entry.name, 0o600, 512*1024)
        need(blob(data) == pin and git(['cat-file', 'blob', pin]) == data, 'REVIEWED_ENTRY_PIN_INVALID')
    finally:
        directory.close()
    source, worker = load_installed(INSTALL)
    return probe(ROOT, WORK, source, worker)


def main(args=None, out=sys.stdout):
    try:
        report = execute(sys.argv[1:] if args is None else args)
    except Exception as error:
        report = {'format': 1, 'kind': 'go-erp-live-watcher-readonly', 'application_contents_changed': False,
                  'database_changed': False, 'credentials_read': False, **failure(error, 'ENTRY')}
    print(json.dumps(report, sort_keys=True, separators=(',', ':')), file=out)
    return 0 if report.get('ok') else 1


if __name__ == '__main__':
    sys.exit(main())
