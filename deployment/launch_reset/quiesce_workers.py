#!/usr/bin/env python3
"""Pause only the four reviewed application timers; let oneshot work finish."""
import hashlib
import os
from pathlib import Path
import re
import stat
import subprocess
import time


APPLICATION_ROOT = '/home/fasakha/public_html'
FAMILIES = ('whatsapp-orders', 'meta-messaging', 'meta-ai', 'customer-crm')


class WorkerPauseError(RuntimeError):
    """Fixed, public-safe operational error code."""


class Systemctl:
    def _run(self, arguments, timeout=10):
        try:
            result = subprocess.run(['systemctl', *arguments], stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                    timeout=timeout, check=False)
        except (OSError, subprocess.TimeoutExpired):
            raise WorkerPauseError('APPLICATION_SYSTEMD_COMMAND_FAILED') from None
        if result.returncode != 0:
            raise WorkerPauseError('APPLICATION_SYSTEMD_COMMAND_FAILED')
        return result.stdout.decode('utf-8', errors='replace')

    def show(self, name, timeout=3):
        properties = 'Id,LoadState,ActiveState,SubState,FragmentPath,DropInPaths,User,WorkingDirectory,Type,Triggers,ConsistsOf,BoundBy,RequiredBy,PropagatesStopTo'
        output = self._run(['show', name, '--property=' + properties], timeout=timeout)
        result = {}
        for line in output.splitlines():
            key, separator, value = line.partition('=')
            if separator and key in properties.split(','):
                if key in result:
                    raise WorkerPauseError('APPLICATION_SYSTEMD_PROPERTIES_AMBIGUOUS')
                result[key] = value
        return result

    def action(self, verb, name):
        if verb not in {'start', 'stop'}:
            raise WorkerPauseError('APPLICATION_SYSTEMD_ACTION_INVALID')
        self._run([verb, name])


def read_owned_unit(name):
    if name not in {'fasakhansta-' + family + '.' + kind for family in FAMILIES for kind in ('timer', 'service')}:
        raise WorkerPauseError('APPLICATION_UNIT_NAME_INVALID')
    directory = Path('/etc/systemd/system')
    for parent in [directory, *directory.parents]:
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
            raise WorkerPauseError('APPLICATION_UNIT_PARENT_UNSAFE')
    path = directory / name
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        raise WorkerPauseError('APPLICATION_UNIT_SOURCE_UNAVAILABLE') from None
    try:
        before = os.fstat(descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1
                or stat.S_IMODE(before.st_mode) & 0o022 or before.st_size > 32768):
            raise WorkerPauseError('APPLICATION_UNIT_SOURCE_UNSAFE')
        source = os.read(descriptor, 32769)
        after = os.fstat(descriptor)
        if len(source) != before.st_size or (before.st_dev, before.st_ino, before.st_mtime_ns, before.st_ctime_ns) != (after.st_dev, after.st_ino, after.st_mtime_ns, after.st_ctime_ns):
            raise WorkerPauseError('APPLICATION_UNIT_CHANGED_DURING_READ')
        return source
    finally:
        os.close(descriptor)


def source_values(source, section, key):
    try:
        text = source.decode('utf-8', errors='strict')
    except UnicodeError:
        raise WorkerPauseError('APPLICATION_UNIT_SOURCE_INVALID') from None
    current = None
    values = []
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith(('#', ';')):
            continue
        if line.endswith('\\'):
            raise WorkerPauseError('APPLICATION_UNIT_CONTINUATION_REQUIRES_REVIEW')
        if line.startswith('[') and line.endswith(']'):
            current = line[1:-1]
            continue
        name, separator, value = line.partition('=')
        if separator and current == section and name.strip() == key:
            values.append(value.strip())
    return values


class TimerQuiescer:
    def __init__(self, systemctl=None, read_unit=None, monotonic=None, sleep=None, progress=None, timeout=45):
        self.systemctl = systemctl or Systemctl()
        self.read_unit = read_unit or read_owned_unit
        self.monotonic = monotonic or time.monotonic
        self.sleep = sleep or time.sleep
        self.progress = progress or print
        if not isinstance(timeout, (int, float)) or not 0 < timeout <= 45:
            raise WorkerPauseError('APPLICATION_WORKER_DRAIN_TIMEOUT_INVALID')
        self.timeout = timeout
        self.units = {}
        self.stopped_timers = []
        self.prepared = False
        self.pause_started = False

    def _validate(self, name, expected_digest=None):
        source = self.read_unit(name)
        digest = hashlib.sha256(source).hexdigest()
        if expected_digest is not None and digest != expected_digest:
            raise WorkerPauseError('APPLICATION_UNIT_SOURCE_CHANGED')
        properties = self.systemctl.show(name)
        if (properties.get('Id') != name or properties.get('LoadState') != 'loaded'
                or properties.get('FragmentPath') != '/etc/systemd/system/' + name
                or properties.get('DropInPaths', '') != ''):
            raise WorkerPauseError('APPLICATION_UNIT_IDENTITY_REQUIRES_REVIEW')
        if properties.get('ActiveState') not in {'active', 'inactive', 'failed', 'activating', 'deactivating', 'reloading'}:
            raise WorkerPauseError('APPLICATION_UNIT_STATE_REQUIRES_REVIEW')
        if name.endswith('.service'):
            for key, expected in [('User', 'fasakha'), ('WorkingDirectory', APPLICATION_ROOT), ('Type', 'oneshot')]:
                if properties.get(key) != expected or source_values(source, 'Service', key) != [expected]:
                    raise WorkerPauseError('APPLICATION_SERVICE_SCOPE_REQUIRES_REVIEW')
        else:
            service = name[:-6] + '.service'
            if source_values(source, 'Timer', 'Unit') not in ([], [service]) or properties.get('Triggers', '').split() != [service]:
                raise WorkerPauseError('APPLICATION_TIMER_TARGET_REQUIRES_REVIEW')
            # A dependency could propagate stop(timer) to a running service.
            if any(properties.get(key, '').strip() for key in ['ConsistsOf', 'BoundBy', 'RequiredBy', 'PropagatesStopTo']):
                raise WorkerPauseError('APPLICATION_TIMER_STOP_PROPAGATION_REQUIRES_REVIEW')
        return digest, properties

    def prepare(self):
        if self.prepared or self.pause_started:
            raise WorkerPauseError('APPLICATION_TIMER_PREPARE_STATE_INVALID')
        reviewed = {}
        for family in FAMILIES:
            for kind in ('timer', 'service'):
                name = 'fasakhansta-' + family + '.' + kind
                digest, properties = self._validate(name)
                if kind == 'timer' and properties['ActiveState'] not in {'active', 'inactive', 'failed'}:
                    raise WorkerPauseError('APPLICATION_TIMER_TRANSITION_IN_PROGRESS')
                reviewed[name] = {'digest': digest, 'was_active': properties['ActiveState'] == 'active'}
        self.units = reviewed
        self.prepared = True
        self.progress('APPLICATION_WORKER_TIMER_PREFLIGHT_PASSED')

    def receipt(self):
        if not self.prepared:
            raise WorkerPauseError('APPLICATION_TIMER_RECEIPT_STATE_INVALID')
        return {'kind': 'fasakhansta-launch-timers-v1',
                'units': {name: dict(item) for name, item in self.units.items()}}

    def load_receipt(self, data):
        # Receipt is persisted by the root orchestrator before the first stop.
        # Treat every prior-active timer as restorable, even after partial pause.
        if self.prepared or self.pause_started:
            raise WorkerPauseError('APPLICATION_TIMER_RECEIPT_STATE_INVALID')
        names = {'fasakhansta-' + family + '.' + kind for family in FAMILIES for kind in ('timer', 'service')}
        if (not isinstance(data, dict) or set(data) != {'kind', 'units'}
                or data['kind'] != 'fasakhansta-launch-timers-v1'
                or not isinstance(data['units'], dict) or set(data['units']) != names):
            raise WorkerPauseError('APPLICATION_TIMER_RECEIPT_INVALID')
        reviewed = {}
        for family in FAMILIES:
            for kind in ('timer', 'service'):
                name = 'fasakhansta-' + family + '.' + kind
                item = data['units'][name]
                if (not isinstance(item, dict) or set(item) != {'digest', 'was_active'}
                        or not isinstance(item['digest'], str) or not re.fullmatch('[0-9a-f]{64}', item['digest'])
                        or type(item['was_active']) is not bool):
                    raise WorkerPauseError('APPLICATION_TIMER_RECEIPT_INVALID')
                reviewed[name] = dict(item)
        self.units = reviewed
        self.prepared = True
        self.pause_started = True
        self.stopped_timers = [name for name, item in reviewed.items() if name.endswith('.timer') and item['was_active']]
        return self

    def pause(self):
        if not self.prepared or self.pause_started:
            raise WorkerPauseError('APPLICATION_TIMER_PAUSE_STATE_INVALID')
        # Recheck every identity before making the first change.
        current = {name: self._validate(name, item['digest'])[1] for name, item in self.units.items()}
        for name, item in self.units.items():
            if name.endswith('.timer'):
                state = current[name]['ActiveState']
                if state not in {'active', 'inactive', 'failed'} or (state == 'active') != item['was_active']:
                    raise WorkerPauseError('APPLICATION_TIMER_ACTIVE_STATE_CHANGED')
        self.pause_started = True
        for name, item in self.units.items():
            if name.endswith('.timer') and item['was_active']:
                # Record before stop so callers can restore after partial command failure.
                self.stopped_timers.append(name)
                self.systemctl.action('stop', name)
        for name in self.units:
            if name.endswith('.timer') and self.systemctl.show(name).get('ActiveState') not in {'inactive', 'failed'}:
                raise WorkerPauseError('APPLICATION_TIMER_DID_NOT_PAUSE')
        self.progress('APPLICATION_WORKER_TIMERS_PAUSED count=' + str(len(self.stopped_timers)))
        started = self.monotonic()
        next_notice = 0
        def drain_state(name):
            remaining = self.timeout - (self.monotonic() - started)
            if remaining <= 0:
                raise WorkerPauseError('APPLICATION_BACKGROUND_WORKERS_BUSY_NO_RESET')
            return self.systemctl.show(name, timeout=min(3, remaining)).get('ActiveState')
        while True:
            busy = []
            for name in self.units:
                if name.endswith('.timer'):
                    if drain_state(name) not in {'inactive', 'failed'}:
                        raise WorkerPauseError('APPLICATION_TIMER_DID_NOT_PAUSE')
                    continue
                state = drain_state(name)
                if state not in {'inactive', 'failed'}:
                    busy.append(name)
            if not busy:
                self.progress('APPLICATION_ONESHOT_WORKERS_DRAINED')
                return
            elapsed = self.monotonic() - started
            if elapsed >= self.timeout:
                raise WorkerPauseError('APPLICATION_BACKGROUND_WORKERS_BUSY_NO_RESET')
            if elapsed >= next_notice:
                self.progress('APPLICATION_WORKER_DRAIN_WAIT seconds=' + str(int(elapsed)) + ' count=' + str(len(busy)))
                next_notice = elapsed + 5
            self.sleep(min(1, self.timeout - elapsed))

    def restore(self):
        # Caller owns the safe-to-reopen decision. Never start a completed oneshot.
        failed = False
        for name in list(reversed(self.stopped_timers)):
            try:
                service = name[:-6] + '.service'
                self._validate(service, self.units[service]['digest'])
                _, properties = self._validate(name, self.units[name]['digest'])
                if properties['ActiveState'] != 'active':
                    self.systemctl.action('start', name)
                if self.systemctl.show(name).get('ActiveState') != 'active':
                    raise WorkerPauseError('APPLICATION_TIMER_RESTORE_FAILED')
                self.stopped_timers.remove(name)
            except (WorkerPauseError, OSError):
                failed = True
        if failed:
            raise WorkerPauseError('APPLICATION_TIMER_RESTORE_FAILED')
        self.progress('APPLICATION_WORKER_TIMERS_RESTORED')
