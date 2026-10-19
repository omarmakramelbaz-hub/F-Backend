#!/usr/bin/env python3
"""Keep the reviewed application workers paused around the one-time data reset."""
import argparse
import json
import os
from pathlib import Path
import pwd
import re
import stat

from quiesce_workers import TimerQuiescer
from systemd_queue import SystemdQueueQuiescer as QueueQuiescer

APPLICATION_ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'


class WorkerControlError(RuntimeError):
    pass


def validate_state_parent(path):
    path = Path(path)
    if not re.fullmatch(r'/tmp/actual-launch-reset\.[A-Za-z0-9]{8}/worker-state\.json', str(path)):
        raise WorkerControlError('WORKER_STATE_PATH_INVALID')
    parent = path.parent
    info = parent.lstat()
    if (parent.resolve() != parent or not stat.S_ISDIR(info.st_mode) or info.st_uid != 0
            or stat.S_IMODE(info.st_mode) & 0o022):
        raise WorkerControlError('WORKER_STATE_PARENT_UNSAFE')
    return path


def write_state(path, payload):
    path = validate_state_parent(path)
    encoded = json.dumps(payload, sort_keys=True).encode('utf-8')
    if len(encoded) > 262144:
        raise WorkerControlError('WORKER_STATE_TOO_LARGE')
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(encoded)
        stream.flush()
        os.fsync(stream.fileno())
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def read_state(path):
    path = validate_state_parent(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 262144):
            raise WorkerControlError('WORKER_STATE_FILE_UNSAFE')
        with os.fdopen(descriptor, 'rb', closefd=False) as stream:
            data = stream.read(262145)
        if len(data) != info.st_size:
            raise WorkerControlError('WORKER_STATE_FILE_CHANGED')
        return json.loads(data.decode('utf-8'))
    finally:
        os.close(descriptor)


class WorkerControl:
    def __init__(self, root, uid, php, timer_factory=TimerQuiescer,
                 queue_factory=QueueQuiescer, progress=print):
        self.root, self.uid, self.php = Path(root), uid, php
        self.timer_factory, self.queue_factory, self.progress = timer_factory, queue_factory, progress

    def instances(self):
        timers = self.timer_factory(progress=self.progress)
        queue = self.queue_factory(root=self.root, uid=self.uid, php=self.php, progress=self.progress)
        return timers, queue

    def prepare(self):
        timers, queue = self.instances()
        # Validate both groups before making any operational change.
        timers.prepare()
        queue.prepare()
        return timers, queue

    def check(self):
        self.prepare()
        self.progress('APPLICATION_WORKER_CONTROL_PREFLIGHT_PASSED')

    def pause(self, state):
        if not (self.root / 'storage/framework/down').is_file():
            raise WorkerControlError('WORKER_PAUSE_REQUIRES_MAINTENANCE')
        timers, queue = self.prepare()
        payload = {'version': 1, 'root': str(self.root), 'uid': self.uid,
                   'timers': timers.receipt(), 'queue': queue.receipt()}
        # Persist every original state before the first stop, including partially
        # failed stop commands. The shell trap can restore in a fresh process.
        write_state(state, payload)
        timers.pause()
        queue.pause()
        self.progress('APPLICATION_WORKERS_PAUSED_FOR_LAUNCH_RESET')

    def restore(self, state):
        # The shell first decides whether rollback/commit is verified and reopens
        # the application. A closed application must never resume direct workers.
        if (self.root / 'storage/framework/down').exists():
            raise WorkerControlError('WORKER_RESTORE_REQUIRES_REOPENED_APPLICATION')
        payload = read_state(state)
        if (not isinstance(payload, dict) or set(payload) != {'version', 'root', 'uid', 'timers', 'queue'}
                or type(payload['version']) is not int or payload['version'] != 1
                or payload['root'] != str(self.root) or type(payload['uid']) is not int or payload['uid'] != self.uid):
            raise WorkerControlError('WORKER_RECEIPT_INVALID')
        timers, queue = self.instances()
        # Validate the complete receipt before any start action.
        timers.load_receipt(payload['timers'])
        queue.load_receipt(payload['queue'])
        failed = False
        for group in (queue, timers):
            try:
                group.restore()
            except Exception as error:
                failed = True
                code = str(error)
                if re.fullmatch(r'[A-Z][A-Z0-9_]{0,119}', code):
                    self.progress('WORKER_GROUP_RESTORE_FAILED code=' + code)
        if failed:
            raise WorkerControlError('APPLICATION_WORKER_RESTORE_FAILED')
        self.progress('APPLICATION_WORKERS_RESTORED')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--php', required=True)
    parser.add_argument('--state', type=Path)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check', action='store_true')
    actions.add_argument('--pause', action='store_true')
    actions.add_argument('--restore', action='store_true')
    options = parser.parse_args()
    if not options.check and options.state is None:
        parser.error('--state is required for pause/restore')
    try:
        if (os.geteuid() != 0 or options.root != APPLICATION_ROOT or options.root.resolve() != options.root
                or options.php != PHP):
            raise WorkerControlError('WORKER_CONTROL_ARGUMENTS_INVALID')
        uid = pwd.getpwnam('fasakha').pw_uid
        if uid <= 0 or options.root.stat().st_uid != uid:
            raise WorkerControlError('WORKER_CONTROL_OWNER_INVALID')
        control = WorkerControl(options.root, uid, options.php, progress=lambda line: print(line, flush=True))
        if options.check:
            control.check()
        elif options.pause:
            control.pause(options.state)
        else:
            control.restore(options.state)
        return 0
    except Exception as error:
        code = str(error)
        if not re.fullmatch(r'[A-Z][A-Z0-9_]{0,119}', code):
            code = 'APPLICATION_WORKER_CONTROL_FAILED'
        print('WORKER_CONTROL_FAILED code=' + code, flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
