#!/usr/bin/env python3
"""Read only the exact pending performance transaction's bounded public facts."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys

CORE = '43b1498938d4550a4c63c267dbcde12b0bab7e81'
OLD_SEED = 'dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
SEED = '11c739039e5c6320b3542d414682d755c7902790'
CANDIDATE = 'd1976ce02c553939032241e7e724d26a9bbe7190'
INVENTORY = '531ba5ab285bafc999930a78b9212640cf3c13fe83365da260ace931b49964b7'
OLD_RUNTIME = 'policy-c876baefb7e60865e370bacd7c4fdf45cdbe00f80cce310495ed5447d71c1a37'
RUNTIME = 'policy-9d1cd8162a2a2a4a1de4acab091454d547a42696e11e35d9253dd5da5fc43397'
WORK = Path('/var/lib/go-erp-live-updates')
PRIVATE = Path('/var/lib/go-erp-manual-updates/private')
CONFIG = Path('/etc/go-erp/live-updates.json')
UNIT = Path('/etc/systemd/system/go-erp-live-updates.service')
MANUAL_RELEASES = Path('/opt/go-erp-manual-updates/releases')
PHASES = frozenset(('PREFLIGHT', 'RECOVERY', 'RUNTIME_INSTALL', 'CURRENT_PROBE',
    'RELEASE_GUARD', 'AUDIT', 'SEED_STAGE', 'SOURCE_PROBE', 'PATH_SWITCH',
    'DAEMON_RELOAD', 'UNIT_VERIFY', 'POSTFLIGHT', 'LOCK_RELEASE', 'DONE'))
REASONS = frozenset(('SEED_CORE_MISMATCH', 'SEED_PROOF_INVALID', 'SEED_VERIFIER_MODE_INVALID',
    'SEED_VERIFIER_PIN_INVALID', 'CHECKOUT_CHANGED', 'GIT_OPERATION_FAILED',
    'CONFIGURATION_SEED_UNREVIEWED', 'CONFIGURATION_INVALID', 'STATE_IDENTITY_INVALID',
    'SERVICE_UNIT_UNREVIEWED', 'EFFECTIVE_UNIT_UNREVIEWED', 'EFFECTIVE_UNIT_RELOAD_REQUIRED',
    'EFFECTIVE_RUNTIME_UNREVIEWED', 'EFFECTIVE_UNIT_QUERY_FAILED', 'EFFECTIVE_UNIT_QUERY_INVALID',
    'WATCHER_BUSY', 'LOCK_CHANGED', 'LOCK_UNSAFE', 'PAYLOAD_ROSTER_INVALID', 'PAYLOAD_PIN_INVALID',
    'RUNTIME_PATH_CHANGED', 'RUNTIME_ROSTER_INVALID', 'RUNTIME_PIN_INVALID', 'FILESYSTEM_MISMATCH',
    'RELEASE_PINS_PENDING', 'RECOVERY_INTENT_INVALID', 'RECOVERY_REQUIRES_REVIEW',
    'MULTIPLE_PENDING_MIGRATIONS', 'BEFORE_PATH_CHANGED', 'STAGED_PATH_CHANGED',
    'AFTER_PATH_CHANGED', 'STATE_CHANGED', 'SOURCE_CHANGED', 'PUBLIC_FEED_CHANGED',
    'SYSTEMD_COMMAND_FAILED', 'PRIVATE_FAILURE', 'INTERRUPTED'))
BOOLEANS = ('rollback_complete', 'configuration_changed', 'state_changed', 'checkout_changed',
            'unit_changed', 'recovery_required', 'audit_write_failed')
PROGRAMS = frozenset(('go_erp_manual_tick_root.py', 'go_erp_manual_updates_root.py',
                      'go_erp_published_download_root.py'))


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_PENDING_' + code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def parse(raw):
    def unique(items):
        value = {}
        for name, item in items:
            need(name not in value, 'DUPLICATE_FIELD')
            value[name] = item
        return value
    def nonfinite(_item):
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_PENDING_NONFINITE_VALUE')
    value = json.loads(raw, object_pairs_hook=unique, parse_constant=nonfinite)
    need(isinstance(value, dict), 'RECORD_INVALID')
    return value


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


class Reader:
    """All parents and files are opened read-only and checked through descriptors."""
    def __init__(self):
        self.parents, self.handles, self.locks, self.directories = [], [], [], {}

    def __enter__(self):
        return self

    def __exit__(self, *_arguments):
        for fd in reversed(self.handles):
            os.close(fd)

    def directory(self, path, private=False):
        path = Path(path)
        need(path.is_absolute() and '..' not in path.parts, 'PATH_INVALID')
        if '/' not in self.directories:
            self.directories['/'] = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            self.handles.append(self.directories['/'])
        fd = self.directories['/']
        traversed = Path('/')
        for index, name in enumerate(path.parts[1:]):
            traversed = traversed / name
            cached = self.directories.get(str(traversed))
            if cached is not None:
                fd = cached
                continue
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            self.handles.append(child)
            info = os.fstat(child)
            mode = stat.S_IMODE(info.st_mode)
            sticky_ancestor = index < len(path.parts) - 2 and mode == 0o1777
            need(stat.S_ISDIR(info.st_mode) and (info.st_uid, info.st_gid) == (0, 0)
                 and (sticky_ancestor or not mode & 0o7022), 'DIRECTORY_UNSAFE')
            self.parents.append((fd, name, child, identity(info)))
            self.directories[str(traversed)] = child
            fd = child
        if private:
            need(stat.S_IMODE(os.fstat(fd).st_mode) == 0o700, 'PRIVATE_DIRECTORY_UNSAFE')
        self.verify()
        return fd

    def verify(self):
        for parent, name, fd, before in self.parents:
            # Directory mtime/size can change as another read-only descriptor
            # is opened; bind its identity/owner/mode, then verify each file fully.
            current = os.fstat(fd)
            named = os.stat(name, dir_fd=parent, follow_symlinks=False)
            need(before[:6] == identity(current)[:6] == identity(named)[:6], 'DIRECTORY_CHANGED')
        for fd, parent, name, before in self.locks:
            need(before == identity(os.fstat(fd))
                 == identity(os.stat(name, dir_fd=parent, follow_symlinks=False)), 'LOCK_CHANGED')

    def _open(self, path, *, modes, maximum, optional=False, empty=False):
        path = Path(path)
        parent = self.directory(path.parent)
        try:
            fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        except FileNotFoundError:
            need(optional, 'FILE_MISSING')
            return None
        self.handles.append(fd)
        info = os.fstat(fd)
        need(stat.S_ISREG(info.st_mode) and (info.st_uid, info.st_gid, info.st_nlink) == (0, 0, 1)
             and stat.S_IMODE(info.st_mode) in modes
             and (0 if empty else 1) <= info.st_size <= maximum, 'FILE_UNSAFE')
        need(identity(info) == identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)), 'FILE_CHANGED')
        return fd, parent, info

    def read(self, path, maximum=16 * 1024**2, *, optional=False, modes=(0o600,)):
        opened = self._open(path, modes=modes, maximum=maximum, optional=optional)
        if opened is None:
            return None
        fd, parent, info = opened
        try:
            raw = bytearray()
            while len(raw) <= maximum:
                chunk = os.read(fd, min(65536, maximum + 1 - len(raw)))
                if not chunk:
                    break
                raw.extend(chunk)
            self.verify()
            need(len(raw) == info.st_size and identity(info) == identity(os.fstat(fd))
                 == identity(os.stat(Path(path).name, dir_fd=parent, follow_symlinks=False)), 'FILE_CHANGED')
            return bytes(raw)
        finally:
            self.handles.remove(fd)
            os.close(fd)

    def lock(self, path):
        fd, parent, info = self._open(path, modes=(0o600,), maximum=1024, empty=True)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('GO_ERP_DESKTOP_PERFORMANCE_PENDING_LOCK_BUSY') from None
        self.locks.append((fd, parent, Path(path).name, identity(info)))
        self.verify()


def base_kind(value):
    return 'current' if value == OLD_SEED else 'target' if value == SEED else 'other'


def bounded_failure(value):
    need(type(value.get('format')) is int and value['format'] == 1
         and value.get('kind') == 'go-erp-preview31-watcher-upgrade'
         and value.get('ok') is False, 'FAILURE_RECORD_INVALID')
    result = {'phase': value.get('phase') if value.get('phase') in PHASES else 'OTHER_REVIEW_REQUIRED',
              'reason': value.get('reason') if value.get('reason') in REASONS else 'OTHER_REVIEW_REQUIRED'}
    for name in BOOLEANS:
        item = value.get(name)
        need(item is None or type(item) is bool, 'FAILURE_BOOLEAN_INVALID')
        result[name] = item
    return result


def inspect(reader):
    reader.lock(PRIVATE / 'install.lock')
    reader.lock(PRIVATE / 'worker.lock')
    reader.lock(WORK / 'watcher.lock')
    reader.directory(PRIVATE, private=True)
    reader.directory(WORK, private=True)
    job = parse(reader.read(PRIVATE / ('shared-workspace-' + CORE + '.json')))
    need(job.get('kind') == 'go-erp-warm-start-rollout' and job.get('core') == CORE
         and job.get('seed') == SEED and job.get('runtime') == RUNTIME
         and job.get('inventory') == INVENTORY
         and job.get('phase') in ('preparing', 'prepared', 'running', 'ready')
         and type(job.get('prepare_started')) is bool
         and (job.get('candidate') is None or isinstance(job['candidate'], str)
              and re.fullmatch(r'[a-f0-9]{40}', job['candidate'])), 'NATIVE_JOB_CHANGED')
    config = parse(reader.read(CONFIG, 16384))
    state = parse(reader.read(WORK / 'state/current.json'))
    installation = parse(reader.read(PRIVATE / 'installation.json', 8192))
    manual = installation.get('runtime')
    need(installation.get('format') == 1 and installation.get('kind') == 'go-erp-manual-installation'
         and isinstance(manual, str) and re.fullmatch(r'[a-f0-9]{64}', manual), 'MANUAL_RECEIPT_INVALID')
    manual_root = MANUAL_RELEASES / manual
    manual_fd = reader.directory(manual_root)
    need(stat.S_IMODE(os.fstat(manual_fd).st_mode) == 0o555
         and set(os.listdir(manual_fd)) == PROGRAMS, 'MANUAL_RUNTIME_UNSAFE')
    hashes = {name: hashlib.sha256(reader.read(manual_root / name, 2 * 1024**2, modes=(0o444,))).hexdigest()
              for name in sorted(PROGRAMS)}
    need(hashlib.sha256(canonical(hashes)).hexdigest() == manual, 'MANUAL_RUNTIME_CHANGED')
    unit = reader.read(UNIT, 65536, modes=(0o644,))
    target_exec = ('ExecStart=/usr/bin/python3 -I -B /opt/go-erp-live-updates/releases/' + RUNTIME + '/go_erp_live_updates.py --config ' + str(CONFIG) + ' --once').encode()
    old_exec = target_exec.replace(RUNTIME.encode(), OLD_RUNTIME.encode())
    active_unit = 'target' if target_exec in unit.splitlines() else 'current' if old_exec in unit.splitlines() else 'other'
    work_fd = reader.directory(WORK, private=True)
    names = [name for name in os.listdir(work_fd)
             if re.fullmatch(r'warm-start-seed-upgrade-[a-f0-9]{32}', name)]
    need(len(names) <= 256, 'AUDIT_COUNT_EXCEEDED')
    matches = []
    for name in sorted(names):
        audit = WORK / name
        audit_fd = reader.directory(audit, private=True)
        before_raw = reader.read(audit / 'before-config.bin', 16384, optional=True)
        after_raw = reader.read(audit / 'exchange-config', 16384, optional=True)
        if before_raw is None or after_raw is None:
            continue
        before, after = parse(before_raw), parse(after_raw)
        if before.get('base_revision') != OLD_SEED or after.get('base_revision') not in (SEED, OLD_SEED):
            continue
        intent_raw = reader.read(audit / 'intent.json', optional=True)
        intent = None if intent_raw is None else parse(intent_raw)
        owned_intent = (intent is not None and intent.get('old_seed') == OLD_SEED
                        and intent.get('new_seed') == SEED and intent.get('core') == CORE
                        and intent.get('runtime') == '/opt/go-erp-live-updates/releases/' + RUNTIME)
        if after.get('base_revision') != SEED and not owned_intent:
            continue
        before_state = parse(reader.read(audit / 'before-state.bin'))
        if not (before_state.get('counter') == 60 and type(before_state.get('counter')) is int
                and before_state.get('base_revision') == OLD_SEED
                and before_state.get('last_remote_sha') == CANDIDATE):
            continue
        staged_unit = reader.read(audit / 'exchange-unit.service', 65536, modes=(0o644,))
        if target_exec not in staged_unit.splitlines() and not (owned_intent and old_exec in staged_unit.splitlines()):
            continue
        contents = set(os.listdir(audit_fd))
        flags = {key: reader.read(audit / key, optional=True) is not None
                 for key in ('intent.json', 'completed.json', 'rolled-back.json')}
        failures = []
        for record in sorted(contents):
            if re.fullmatch(r'failed-[a-f0-9]{32}\.json', record):
                failures.append(bounded_failure(parse(reader.read(audit / record))))
        need(len(failures) <= 16, 'FAILURE_COUNT_EXCEEDED')
        matches.append({'intent_present': flags['intent.json'], 'completed_present': flags['completed.json'],
                        'rolled_back_present': flags['rolled-back.json'], 'failures': failures})
    need(len(matches) <= 16, 'MATCH_COUNT_EXCEEDED')
    reader.verify()
    return {'format': 1, 'kind': 'go-erp-desktop-performance-pending-read-only', 'ok': True,
            'read_only': True, 'original_locks_held': True,
            'native_job_phase': job['phase'], 'native_job_prepare_started': job['prepare_started'],
            'native_job_candidate_present': job.get('candidate') is not None,
            'configuration_base': base_kind(config.get('base_revision')),
            'state_base': base_kind(state.get('base_revision')),
            'state_counter': '60' if type(state.get('counter')) is int and state['counter'] == 60
                else '61' if type(state.get('counter')) is int and state['counter'] == 61 else 'other',
            'active_unit': active_unit, 'manual_runtime': manual, 'manual_runtime_verified': True,
            'matched_audits': matches}


def main():
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_HOST_REQUIRED')
        need(len(sys.argv) == 1, 'ARGUMENTS_NOT_ALLOWED')
        with Reader() as reader:
            result = inspect(reader)
        print(json.dumps(result, sort_keys=True, separators=(',', ':'), allow_nan=False))
        return 0
    except BaseException as error:
        message = str(error)
        code = message if isinstance(error, Stop) and re.fullmatch(r'GO_ERP_DESKTOP_PERFORMANCE_PENDING_[A-Z_]{1,100}', message) else 'GO_ERP_DESKTOP_PERFORMANCE_PENDING_PRIVATE_FAILURE'
        print(json.dumps({'format': 1, 'kind': 'go-erp-desktop-performance-pending-read-only',
                          'ok': False, 'code': code}, sort_keys=True, separators=(',', ':')))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
