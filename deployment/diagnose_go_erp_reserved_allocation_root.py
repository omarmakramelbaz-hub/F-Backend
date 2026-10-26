#!/usr/bin/env python3
"""Replay the reserved .54 allocation in a private clone; never allocate or push."""
import contextlib
import errno
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import types

WORK = Path('/var/lib/go-erp-live-updates')
CONTROL = Path('/var/lib/go-erp-manual-updates')
ROOT = Path('/home/fasakha/public_html')
RUNTIME = Path('/opt/go-erp-live-updates/releases/policy-c5fca0a576be84564425d7de160a95d0825a20f5545d52ef911b4349778ebbe6')
SEED = '1556e002f18d00b9e8f9ba81590843e0d8697318'
CORE = 'a237b4c048887c6af1b0fb53fe02c414c1d17cd6'
FAILED = '5563faf5aa1e2e62157c4b3b9638592a02854c59'
PUBLISHED = 'afb333bbddd9dbc908e3c3674d99c766ed5c4053'
INVENTORY = '525a481eb2d13dc47879b48f4a6110cbd702f2c0d5fdad5e49d663dcafe75d21'
OLD_POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v1'
POLICY = 'historical-baseline-v2-native-close-reopen-program-contract-warm-start-accounts-v2'
REMOTE = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
UNIT_SHA = '53360a08c673e25ca45c3310c1b9c75a100950dca7839182449e29467c773653'
PINS = {
    'go_erp_live_source.py': (38827, 'c4f22a3f89b930bd467942e835db82ccd3e3a98d73f52e949fed212c4a4fa737'),
    'go_erp_live_updates.py': (70029, 'db4b38fc42a1dec898dceda2b11cb657667ef70343d970199328da53cbf76101'),
}
CODE = re.compile(r'GO_ERP_[A-Z0-9_]{1,120}\Z')
OPERATIONS = frozenset(('clone', 'remote', 'checkout', 'rev-parse', 'diff-index', 'ls-files',
    'cat-file', 'ls-tree', 'hash-object', 'update-index', 'write-tree', 'commit-tree', 'update-ref', 'symbolic-ref'))


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_ALLOCATION_PROBE_' + code)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
        value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def parents(path):
    need(path.is_absolute() and path.resolve() == path, 'PATH_UNSAFE')
    for parent in reversed(path.parents):
        info = parent.lstat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
            and (not info.st_mode & 0o022 or bool(info.st_mode & stat.S_ISVTX)), 'PARENT_UNSAFE')


def read(path, maximum, modes=(0o600,), optional=False):
    parents(path)
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    except FileNotFoundError:
        if optional:
            return None
        raise
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == before.st_gid == 0
            and before.st_nlink == 1 and stat.S_IMODE(before.st_mode) in modes
            and 0 < before.st_size <= maximum, 'FILE_UNSAFE')
        data = bytearray()
        while len(data) <= maximum:
            block = os.read(fd, min(65536, maximum + 1 - len(data)))
            if not block:
                break
            data.extend(block)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd))
            == identity(path.lstat()), 'FILE_CHANGED')
        return bytes(data)
    finally:
        os.close(fd)


def parse(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            need(key not in result, 'JSON_INVALID')
            result[key] = value
        return result
    def invalid(_):
        raise Stop('GO_ERP_ALLOCATION_PROBE_JSON_INVALID')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=invalid)
    need(isinstance(value, dict), 'JSON_INVALID')
    return value


def checked_state(state, job):
    desired = state.get('desired') or {}
    published = state.get('published') or {}
    release = published.get('release') or {}
    need(type(state.get('format')) is int and state['format'] == 1
        and state.get('base_revision') == SEED and type(state.get('counter')) is int
        and state['counter'] == 54 and state.get('last_remote_sha') == FAILED
        and state.get('version_floor') == '0.4.4-preview.52'
        and desired.get('commit') == FAILED and desired.get('version') == '0.4.4-preview.53'
        and desired.get('candidatePolicy') == OLD_POLICY and desired.get('inventorySha256') == INVENTORY
        and desired.get('pushed') is True and desired.get('status') == 'retry-build'
        and type(desired.get('failures')) is int and desired['failures'] == 3
        and type(desired.get('retry_at')) in (int, float) and math.isfinite(desired['retry_at'])
        and desired['retry_at'] >= 0 and published.get('commit') == PUBLISHED
        and release.get('version') == '0.4.4-preview.52' and release.get('source_revision') == PUBLISHED
        and release in state.get('published_history', []), 'STATE_UNREVIEWED')
    need(type(job.get('format')) is int and job['format'] == 1
        and job.get('kind') == 'go-erp-warm-start-repair-rollout'
        and job.get('core') == CORE and job.get('seed') == SEED and job.get('runtime') == RUNTIME.name
        and job.get('phase') == 'running' and job.get('prepare_started') is True
        and job.get('baseline_candidate') == job.get('baseline_remote') == FAILED
        and type(job.get('baseline_counter')) is int and job['baseline_counter'] == 53
        and job.get('candidate') is None and job.get('version') is None
        and type(job.get('transient_failures')) is int and job['transient_failures'] == 0
        and job.get('inventory') == INVENTORY, 'JOB_UNREVIEWED')


def bounded_error(error, source_errors=()):
    if isinstance(error, (Stop, *source_errors)) and CODE.fullmatch(str(error)):
        return {'error_code': str(error), 'error_type': 'BOUNDED_CODE'}
    if isinstance(error, OSError):
        return {'error_code': 'GO_ERP_ALLOCATION_PROBE_OS_ERROR', 'error_type': 'OS_ERROR',
            'errno': error.errno if type(error.errno) is int and error.errno in errno.errorcode else None}
    return {'error_code': 'GO_ERP_ALLOCATION_PROBE_PRIVATE_EXCEPTION', 'error_type': 'PRIVATE_EXCEPTION'}


def git_failure_category(stderr):
    value = stderr[:65536].lower() if isinstance(stderr, bytes) else b''
    categories = (
        ('QUOTA', (b'disk quota exceeded',)),
        ('NO_SPACE', (b'no space left on device', b'disk full')),
        ('PERMISSION', (b'permission denied', b'operation not permitted', b'access is denied')),
        ('INDEX_LOCK', (b'index.lock', b'another git process')),
        ('DUBIOUS_OWNERSHIP', (b'dubious ownership', b'unsafe repository')),
        ('BAD_OBJECT', (b'bad object', b'not a valid object', b'invalid object', b'missing blob')),
        ('UNKNOWN_OPTION', (b'unknown option', b'unrecognized option')),
    )
    return next((name for name, terms in categories if any(term in value for term in terms)), 'OTHER')


class ExistingLocks:
    def __init__(self, paths):
        self.paths, self.fds = paths, []
    def __enter__(self):
        try:
            for path in self.paths:
                parents(path)
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
                self.fds.append(fd)
                info = os.fstat(fd)
                need(stat.S_ISREG(info.st_mode) and info.st_uid == info.st_gid == 0
                    and info.st_nlink == 1 and stat.S_IMODE(info.st_mode) == 0o600, 'LOCK_UNSAFE')
                try:
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    raise Stop('GO_ERP_ALLOCATION_PROBE_BUSY') from None
            return self
        except BaseException:
            self.__exit__(None, None, None)
            raise
    def __exit__(self, *_):
        for fd in reversed(self.fds):
            os.close(fd)
        self.fds.clear()


class Heartbeat:
    def __init__(self, source, worker, web_uid, observed, dependency, *, root=ROOT,
                 checkout=WORK / 'checkout', clock=time.monotonic, progress=lambda: None):
        self.source, self.worker, self.web_uid = source, worker, web_uid
        self.observed, self.dependency = observed, dependency
        self.root, self.checkout, self.clock, self.due = root, checkout, clock, 0
        self.snapshot = None
        self.progress = progress
    def __call__(self, force=False):
        self.progress()
        if not force and self.clock() < self.due:
            return
        self.due = self.clock() + 20
        need(self.worker.dependency_snapshot(self.root, self.web_uid) == self.dependency, 'DEPENDENCY_CHANGED')
        snapshot = self.source.scan_snapshot(self.root, self.checkout, self.web_uid, self.observed)
        need(snapshot['metadata']['seedRevision'] == SEED
            and snapshot['metadata']['inventorySha256'] == INVENTORY, 'SOURCE_CHANGED')
        self.snapshot = snapshot


def load_pinned(name):
    size, expected = PINS[name]
    raw = read(RUNTIME / name, size, (0o644,))
    need(len(raw) == size and digest(raw) == expected, 'RUNTIME_PIN_CHANGED')
    value = types.ModuleType('reserved_allocation_' + name[:-3])
    value.__file__ = str(RUNTIME / name)
    sys.modules[value.__name__] = value
    exec(compile(raw, value.__file__, 'exec'), value.__dict__)
    return value


def replay(source, worker, snapshot, heartbeat, temporary, events, progress=lambda stage: None):
    destination = temporary / 'candidate'
    (temporary / 'state').mkdir(mode=0o700)
    original_git = worker.Git(temporary, 'UNUSED-DIAGNOSTIC-TOKEN', heartbeat=heartbeat)
    run = original_git.run
    allowed = [
        ['clone', '--quiet', '--no-local', '--no-hardlinks', str(WORK / 'checkout'), str(destination)],
        ['remote', 'set-url', 'origin', REMOTE], ['checkout', '--quiet', '--detach', SEED],
    ]
    def candidate_git(arguments, cwd=None, timeout=180):
        need(allowed and list(arguments) == allowed[0], 'GIT_OPERATION_UNREVIEWED')
        allowed.pop(0)
        started = time.monotonic()
        try:
            return run(arguments, cwd, timeout)
        finally:
            events.append({'operation': arguments[0], 'milliseconds': round((time.monotonic()-started)*1000)})
    original_git.run = candidate_git
    progress('CLONE')
    original_git.candidate(WORK / 'checkout', destination, SEED)
    need(not allowed, 'GIT_SEQUENCE_INCOMPLETE')
    source_git = source._git
    process_module = getattr(source, 'subprocess', None)
    original_run = process_module.run if process_module is not None else None
    failure = {}
    def measured_process(*args, **kwargs):
        try:
            result = original_run(*args, **kwargs)
        except subprocess.TimeoutExpired:
            failure.update(failure_category='TIMEOUT')
            raise
        except OSError as error:
            failure.update(failure_category='OS_ERROR',
                errno=error.errno if type(error.errno) is int and error.errno in errno.errorcode else None)
            raise
        if result.returncode != 0:
            failure.update(failure_category=git_failure_category(result.stderr),
                exit_status=result.returncode if type(result.returncode) is int and -128 <= result.returncode <= 255 else None)
        return result
    def measured_git(root, arguments, data=None):
        operation = arguments[0] if arguments and arguments[0] in OPERATIONS else 'unreviewed'
        need(operation != 'unreviewed', 'SOURCE_GIT_OPERATION_UNREVIEWED')
        started = time.monotonic()
        failure.clear()
        try:
            return source_git(root, arguments, data)
        finally:
            events.append({'operation': operation, 'milliseconds': round((time.monotonic()-started)*1000), **failure})
    source._git = measured_git
    if process_module is not None:
        process_module.run = measured_process
    try:
        progress('PREPARE')
        result = source.prepare_candidate(destination, snapshot, 54, heartbeat=heartbeat)
        need(result.get('version') == '0.4.4-preview.54' and result.get('candidatePolicy') == POLICY
            and result.get('inventorySha256') == INVENTORY, 'RESULT_UNREVIEWED')
        return {key: result[key] for key in ('version', 'candidatePolicy', 'inventorySha256')}
    finally:
        source._git = source_git
        if process_module is not None:
            process_module.run = original_run


def main():
    report = {'format': 1, 'kind': 'go-erp-reserved-allocation-probe', 'ok': False,
        'published': False, 'database_read': False, 'production_written': False,
        'temporary_removed': True, 'stage': 'entry', 'operations': []}
    source_errors, captured, locks = (), {}, None
    last_progress = 0
    def progress(stage=None):
        nonlocal last_progress
        now = time.monotonic()
        if stage is not None or now - last_progress >= 30:
            need(stage is None or stage in ('SNAPSHOT', 'CLONE', 'PREPARE', 'VERIFY'), 'PHASE_UNREVIEWED')
            print('GO_ERP_ALLOCATION_DIAG_' + (stage or 'RUNNING'), flush=True)
            last_progress = now
    def capture(path, maximum, modes=(0o600,), optional=False):
        raw = read(path, maximum, modes, optional)
        captured[path] = (raw, maximum, modes, optional)
        return raw
    try:
        need(len(sys.argv) == 1 and os.geteuid() == 0
            and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
        os.umask(0o077)
        report['resources'] = {'work_free_mib': shutil.disk_usage(WORK).free // 1024**2,
            'temporary_free_mib': shutil.disk_usage('/var/tmp').free // 1024**2}
        with contextlib.suppress(OSError, ValueError):
            for line in Path('/proc/meminfo').read_text().splitlines():
                found = re.fullmatch(r'MemAvailable:\s+([0-9]+) kB', line)
                if found:
                    report['resources']['memory_available_kib'] = int(found[1])
        locks = ExistingLocks([CONTROL / 'private/worker.lock', WORK / 'watcher.lock'])
        locks.__enter__()
        report['stage'] = 'state'
        state_path = WORK / 'state/current.json'
        job_path = CONTROL / ('private/warm-start-repair-' + CORE + '.json')
        state = parse(capture(state_path, 16*1024**2))
        job = parse(capture(job_path, 16*1024**2)); checked_state(state, job)
        config = parse(capture(Path('/etc/go-erp/live-updates.json'), 16384))
        unit = capture(Path('/etc/systemd/system/go-erp-live-updates.service'), 65536, (0o644,))
        need(config.get('base_revision') == SEED and digest(unit) == UNIT_SHA, 'COHORT_CHANGED')
        capture(WORK / 'program-rollout-hold.json', 2*1024**2)
        report['stage'] = 'runtime'
        source = load_pinned('go_erp_live_source.py'); worker = load_pinned('go_erp_live_updates.py')
        source_errors = (source.SourceError, worker.WatchError)
        need(source.PREVIEW31_SEED == worker.PREVIEW31_SEED == SEED
            and source.PREVIEW31_POLICY == worker.PREVIEW31_POLICY == POLICY, 'RUNTIME_BINDING_CHANGED')
        guard = Heartbeat(source, worker, pwd.getpwnam('fasakha').pw_uid,
            state.get('observed'), state.get('dependency_guard'), progress=progress)
        report['stage'] = 'snapshot'; progress('SNAPSHOT'); guard(force=True)
        report['stage'] = 'isolated-replay'
        try:
            with tempfile.TemporaryDirectory(prefix='go-erp-reserved-allocation-probe-', dir='/var/tmp') as folder:
                report['temporary_removed'] = False
                report['result'] = replay(source, worker, guard.snapshot, guard, Path(folder), report['operations'], progress)
        finally:
            report['temporary_removed'] = 'folder' not in locals() or not Path(folder).exists()
        report['stage'] = 'final-snapshot'; guard(force=True)
        report['ok'] = True; report['stage'] = 'complete'
    except Exception as error:
        report.update(bounded_error(error, source_errors))
    finally:
        report['source_and_dependency_verified_after'] = False
        if 'guard' in locals() and 'folder' in locals():
            try:
                progress('VERIFY')
                guard(force=True)
                report['source_and_dependency_verified_after'] = True
            except Exception as error:
                report['ok'] = False
                report['verification_error_code'] = bounded_error(error, source_errors)['error_code']
        unchanged = True
        for path, (raw, maximum, modes, optional) in captured.items():
            try:
                unchanged = read(path, maximum, modes, optional) == raw and unchanged
            except Exception:
                unchanged = False
        report['protected_files_unchanged'] = unchanged
        if not unchanged:
            report['ok'] = False; report['error_code'] = 'GO_ERP_ALLOCATION_PROBE_PROTECTED_FILE_CHANGED'
        if locks is not None:
            locks.__exit__(None, None, None)
        # Expose operation names/durations only; source arguments and stderr never leave this process.
        operations = report['operations']
        report['operation_count'] = len(operations)
        report['operations'] = operations[-16:]
        print('GO_ERP_ALLOCATION_DIAG_RESULT ' + json.dumps(report, sort_keys=True), flush=True)
    return 0 if report['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
