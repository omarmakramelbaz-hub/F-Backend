#!/usr/bin/env python3
"""Gracefully quiesce the inspected laravel-queue.service, with no forced kill.

The outer root installer persists receipt() before pause().  Recovery infers
partial publication/reload/stop/start from the owned drop-in and PID 1 state.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time
import tempfile

from queue_manager import QueueQuiescer, QueueSafetyError, fail


class SystemdQueueQuiescer(QueueQuiescer):
    UNIT = 'laravel-queue.service'
    GROUP = '/system.slice/laravel-queue.service'
    OVERRIDE_NAME = 'zzzz-codex-actual-launch-reset.conf'
    OVERRIDE = b'[Service]\nSendSIGKILL=no\nTimeoutStopSec=infinity\n'
    PROPERTIES = ('Id LoadState ActiveState SubState Type MainPID ControlPID ControlGroup User Group '
                  'WorkingDirectory RootDirectory RootImage DynamicUser FragmentPath DropInPaths '
                  'NeedDaemonReload RefuseManualStop RefuseManualStart KillMode KillSignal SendSIGKILL '
                  'SendSIGHUP TimeoutStopUSec Restart ExecStart ExecStartPre ExecStartPost ExecCondition '
                  'ExecStop ExecStopPost Environment EnvironmentFiles UnitFileState TriggeredBy '
                  'PropagatesStopTo ConsistsOf BoundBy RequiredBy Job').split()
    REQUIRED = ('Id LoadState ActiveState SubState Type MainPID ControlPID ControlGroup User Group '
                'WorkingDirectory FragmentPath DropInPaths NeedDaemonReload RefuseManualStop '
                'KillMode KillSignal SendSIGKILL SendSIGHUP TimeoutStopUSec Restart ExecStart').split()
    HOOKS = ('ExecStartPre', 'ExecStartPost', 'ExecCondition', 'ExecStop', 'ExecStopPost')
    PROPAGATION = ('TriggeredBy', 'PropagatesStopTo', 'ConsistsOf', 'BoundBy', 'RequiredBy')
    STATIC = ('Id', 'Type', 'User', 'Group', 'WorkingDirectory', 'RootDirectory', 'RootImage',
              'DynamicUser', 'FragmentPath', 'RefuseManualStop', 'RefuseManualStart',
              'KillMode', 'KillSignal', 'SendSIGHUP', 'Restart', 'UnitFileState')

    def __init__(self, root, uid, php, progress=None, runner=None, proc=None, clock=None, sleep=None,
                 runtime_root='/run/systemd/system', cgroup_root='/sys/fs/cgroup', systemctl='/bin/systemctl'):
        super().__init__(root, uid, php, runner=runner, proc=proc, progress=progress, clock=clock, sleep=sleep)
        self.runtime_root = str(Path(runtime_root).resolve())
        self.cgroup_root = str(Path(cgroup_root).resolve())
        self.systemctl = str(Path(systemctl).resolve())
        self.override = Path(self.runtime_root) / (self.UNIT + '.d') / self.OVERRIDE_NAME

    def _php_class(self, path):
        # This backend never executes an interpreter probe. The root-owned
        # exact unit and observed PID bind both launcher and real php-cgi image.
        return 'exact_systemd_executable' if Path(path).is_absolute() else 'other'

    def _ctl(self, *arguments):
        return self._command([self.systemctl, *arguments], timeout=15)

    def _show(self):
        output = self._ctl('show', self.UNIT, '--no-pager', '--property=' + ','.join(self.PROPERTIES))
        values = {}
        for line in output.splitlines():
            key, separator, value = line.partition('=')
            if not separator or key not in self.PROPERTIES or key in values:
                fail('SYSTEMD_QUEUE_PROPERTY_REPLY_INVALID')
            values[key] = value
        if any(key not in values for key in self.REQUIRED):
            fail('SYSTEMD_QUEUE_REQUIRED_PROPERTIES_MISSING')
        return values

    @staticmethod
    def _number(value):
        if not re.fullmatch('[0-9]+', value):
            fail('SYSTEMD_QUEUE_PID_INVALID')
        return int(value)

    def _start_command(self, values):
        # Restrict this inspected unit to the ordinary unambiguous systemctl
        # rendering. Quoted/escaped or whitespace-bearing argv require review.
        match = re.fullmatch(r'\{\s*path=([^;{}]+?)\s*;\s*argv\[\]=(.*?)\s*;\s*ignore_errors=(yes|no)\s*;.*\}', values['ExecStart'])
        if not match or '{' in match[2] or '}' in match[2] or ';' in match[2] or '\\' in match[2] or '"' in match[2] or "'" in match[2]:
            fail('SYSTEMD_QUEUE_EXECSTART_REQUIRES_REVIEW')
        path, arguments = match[1].strip(), match[2].split()
        if not Path(path).is_absolute() or len(arguments) < 3 or arguments[0] != path or match[3] != 'no':
            fail('SYSTEMD_QUEUE_EXECSTART_REQUIRES_REVIEW')
        parsed = self._php_script(arguments, self.root)
        if parsed is None or parsed['task'] != 'queue:work':
            fail('SYSTEMD_QUEUE_EXECSTART_NOT_APP_QUEUE')
        return path, arguments, parsed

    def _base_profile(self, values):
        path, arguments, parsed = self._start_command(values)
        profile = {key: values.get(key, '') for key in self.STATIC}
        profile['exec_start_sha256'] = self._command_hash(arguments)
        profile['exec_path'] = path
        for key in self.HOOKS + self.PROPAGATION + ('Environment', 'EnvironmentFiles'):
            profile[key + '_sha256'] = hashlib.sha256(values.get(key, '').encode()).hexdigest()
        return profile

    def _validate_unit(self, values):
        if values['Id'] != self.UNIT or values['LoadState'] != 'loaded' or values['Type'] not in ('simple', 'exec'):
            fail('SYSTEMD_QUEUE_UNIT_UNSUPPORTED')
        import pwd
        import grp
        primary_gid = pwd.getpwuid(self.uid).pw_gid
        primary_group = grp.getgrgid(primary_gid).gr_name
        if (values['User'] not in (str(self.uid), self._username())
                or values['Group'] not in ('', str(primary_gid), primary_group)
                or values['WorkingDirectory'] != self.root or values.get('RootDirectory', '')
                or values.get('RootImage', '') or values.get('DynamicUser', 'no') not in ('', 'no')):
            fail('SYSTEMD_QUEUE_ACCOUNT_OR_DIRECTORY_CHANGED')
        if any(values.get(key, '') for key in self.HOOKS):
            fail('SYSTEMD_QUEUE_STOP_OR_START_HOOKS_REQUIRE_REVIEW')
        if any(values.get(key, '') for key in self.PROPAGATION):
            fail('SYSTEMD_QUEUE_PROPAGATION_REQUIRES_REVIEW')
        if values.get('EnvironmentFiles', ''):
            fail('SYSTEMD_QUEUE_ENVIRONMENT_FILES_REQUIRE_REVIEW')
        if (values['RefuseManualStop'] != 'no' or values.get('RefuseManualStart', 'no') != 'no'
                or values['KillMode'] != 'control-group' or values['KillSignal'] not in ('15', 'SIGTERM', 'TERM')
                or values['SendSIGHUP'] != 'no'):
            fail('SYSTEMD_QUEUE_STOP_POLICY_UNSUPPORTED')
        if self._number(values['ControlPID']) != 0:
            fail('SYSTEMD_QUEUE_CONTROL_PROCESS_BUSY')

    def _username(self):
        import pwd
        return pwd.getpwuid(self.uid).pw_name

    def _sources(self, values):
        fragment = values['FragmentPath']
        if not fragment or not Path(fragment).is_absolute():
            fail('SYSTEMD_QUEUE_FRAGMENT_UNSUPPORTED')
        _, source = self._trusted_file(fragment)
        result = [source]
        for relative in ('artisan', 'vendor/laravel/framework/src/Illuminate/Queue/Worker.php'):
            content, meta = self._trusted_file(Path(self.root) / relative, root_owned=False)
            if relative.endswith('Worker.php') and b'pcntl_signal' not in content:
                fail('SYSTEMD_QUEUE_GRACEFUL_HANDLER_SOURCE_CHANGED')
            result.append(meta)
        return result

    def _trusted_directory(self, path):
        path = Path(path)
        if not path.is_dir() or path.is_symlink() or str(path.resolve()) != str(path):
            fail('SYSTEMD_QUEUE_RUNTIME_DIRECTORY_UNSAFE')
        for item in (path, *path.parents):
            info = item.stat()
            if info.st_uid != 0 or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                fail('SYSTEMD_QUEUE_RUNTIME_DIRECTORY_UNTRUSTED')

    def _dropin_roster(self, fragment):
        roots = {self.runtime_root, str(Path(fragment).parent), '/etc/systemd/system',
                 '/usr/lib/systemd/system', '/lib/systemd/system'}
        result = set()
        for root in roots:
            directory = Path(root) / (self.UNIT + '.d')
            if directory.exists():
                self._trusted_directory(directory.resolve())
                for path in directory.glob('*.conf'):
                    if path != self.override:
                        result.add(str(path.resolve()))
        return sorted(result)

    def _members(self):
        relative = self.GROUP.lstrip('/')
        candidates = [Path(self.cgroup_root) / relative, Path(self.cgroup_root) / 'systemd' / relative]
        members = set()
        found = False
        for directory in candidates:
            if not directory.exists():
                continue
            found = True
            try:
                files = list(directory.rglob('cgroup.procs'))
                if not files:
                    fail('SYSTEMD_QUEUE_CGROUP_MEMBERS_UNAVAILABLE')
                for path in files:
                    content = path.read_text()
                    if len(content) > 1048576:
                        fail('SYSTEMD_QUEUE_CGROUP_MEMBERS_INVALID')
                    for item in content.split():
                        members.add(self._number(item))
            except OSError:
                fail('SYSTEMD_QUEUE_CGROUP_MEMBERS_UNREADABLE')
        return members, found

    def _inspect_worker(self, values, expected=None, require_handler=True):
        pid = self._number(values['MainPID'])
        if not pid or values['ControlGroup'] != self.GROUP:
            fail('SYSTEMD_QUEUE_MAIN_PID_OR_CGROUP_CHANGED')
        process = self._read(pid)
        parsed = self._php_script(process['argv'], process['cwd'])
        if (parsed is None or parsed['task'] != 'queue:work' or process['cwd'] != self.root
                or process['uids'] != [self.uid] * 4 or self.GROUP not in process.get('cgroups', [])):
            fail('SYSTEMD_QUEUE_WORKER_PROFILE_CHANGED')
        if require_handler and not process.get('sig_caught', 0) & (1 << 14):
            fail('SYSTEMD_QUEUE_WORKER_HANDLER_NOT_READY')
        executable = process['exe']
        if executable.endswith(' (deleted)'):
            fail('SYSTEMD_QUEUE_EXECUTABLE_DELETED')
        image = self._binary_fingerprint(executable)
        if image['resolved'] != executable:
            fail('SYSTEMD_QUEUE_EXECUTABLE_NOT_CANONICAL')
        launch_path, start_args, start_parsed = self._start_command(values)
        launcher = self._binary_fingerprint(launch_path)
        normalized = self._normalized_arguments(process['argv'], parsed, executable)
        configured = self._normalized_arguments(start_args, start_parsed, executable)
        # Ordinary queue arguments are unambiguous in systemctl's display.
        if any(re.search(r'\s|[;{}\\\"\']', argument) for argument in process['argv']):
            fail('SYSTEMD_QUEUE_ARGUMENT_ENCODING_REQUIRES_REVIEW')
        command = self._command_hash(normalized)
        if command != self._command_hash(configured):
            fail('SYSTEMD_QUEUE_UNIT_COMMAND_MISMATCH')
        if any(argument == '--force' or argument.startswith('--force=') for argument in process['argv'][parsed['task_index'] + 1:]):
            fail('SYSTEMD_QUEUE_FORCE_WORKER_UNSUPPORTED')
        worker = {'pid': pid, 'starttime': str(process['starttime']), 'executable': image,
                  'launcher': launcher, 'command_sha256': command}
        members, found = self._members()
        if not found or members != {pid}:
            fail('SYSTEMD_QUEUE_FOREIGN_CGROUP_PROCESS')
        if expected and (image != expected['executable'] or launcher != expected['launcher']
                         or command != expected['command_sha256']):
            fail('SYSTEMD_QUEUE_WORKER_IDENTITY_CHANGED')
        return worker

    def _inventory(self, expected):
        workers = set()
        for pid in self.proc.pids():
            try:
                process = self.proc.read(pid)
            except (OSError, ValueError, KeyError, UnicodeError):
                continue
            if not process.get('uids') or process['uids'][0] != self.uid:
                continue
            try:
                parsed = self._php_script(process['argv'], process['cwd'])
            except QueueSafetyError:
                # A potential app worker with unknown PHP prefix must not be
                # silently omitted. Ordinary unrelated CLI processes are ignored.
                args = process['argv']
                for index, argument in enumerate(args):
                    if argument.startswith('--file='):
                        argument = argument.partition('=')[2]
                    elif argument.startswith('-f') and len(argument) > 2:
                        argument = argument[2:]
                    artisan = (argument == self.root + '/artisan'
                               or (argument in ('artisan', './artisan') and process['cwd'] == self.root))
                    task_index = index + 1
                    if task_index < len(args) and args[task_index] == '--':
                        task_index += 1
                    if artisan and task_index < len(args) and args[task_index] in ('queue:work', 'queue:listen', 'horizon', 'horizon:work'):
                        fail('SYSTEMD_QUEUE_UNREVIEWED_APP_WORKER')
                continue
            if parsed and parsed['task'] in ('queue:work', 'queue:listen', 'horizon', 'horizon:work'):
                workers.add(int(pid))
        if workers != set(expected):
            self.progress('SYSTEMD_QUEUE_WORKER_INVENTORY=' + json.dumps({'pids': sorted(workers), 'expected': sorted(expected)}))
            fail('SYSTEMD_QUEUE_ADDITIONAL_OR_MISSING_APP_WORKER')

    def _override_present(self):
        if not self.override.exists() and not self.override.is_symlink():
            return False
        content, meta = self._trusted_file(self.override)
        if content != self.OVERRIDE or meta['mode'] != 0o644:
            fail('SYSTEMD_QUEUE_OWN_OVERRIDE_CHANGED')
        return True

    def _loaded_mode(self, values):
        paths = values['DropInPaths'].split()
        if paths == [str(self.override)]:
            if values['SendSIGKILL'] != 'no' or values['TimeoutStopUSec'] != 'infinity':
                fail('SYSTEMD_QUEUE_PROTECTIVE_POLICY_NOT_EFFECTIVE')
            return 'protected'
        if paths:
            fail('SYSTEMD_QUEUE_UNREVIEWED_DROPINS')
        if values['SendSIGKILL'] != self.data['original_send_sigkill'] or values['TimeoutStopUSec'] != self.data['original_timeout']:
            fail('SYSTEMD_QUEUE_ORIGINAL_POLICY_CHANGED')
        return 'original'

    def _validate_saved(self, values=None):
        if self.data is None:
            fail('SYSTEMD_QUEUE_NOT_PREPARED')
        values = values or self._show()
        self._validate_unit(values)
        if self._base_profile(values) != self.data['profile']:
            fail('SYSTEMD_QUEUE_EFFECTIVE_CONFIGURATION_CHANGED')
        if self._sources(values) != self.data['sources']:
            fail('SYSTEMD_QUEUE_SOURCE_CHANGED')
        if self._dropin_roster(values['FragmentPath']):
            fail('SYSTEMD_QUEUE_UNREVIEWED_DROPIN_FILES')
        if self._binary_fingerprint(self.systemctl) != self.data['controller']:
            fail('SYSTEMD_QUEUE_CONTROLLER_CHANGED')
        for key in ('executable', 'launcher'):
            item = self.data['worker'][key]
            if self._binary_fingerprint(item['path']) != item:
                fail('SYSTEMD_QUEUE_EXECUTABLE_OR_LAUNCHER_CHANGED')
        self._override_present()  # Any existing file must be our exact owned bytes.
        self._loaded_mode(values)
        return values

    def prepare(self):
        if self.data is not None:
            fail('SYSTEMD_QUEUE_ALREADY_PREPARED')
        self._trusted_directory(self.runtime_root)
        if self.override.exists() or self.override.is_symlink():
            fail('SYSTEMD_QUEUE_PREEXISTING_OVERRIDE_REQUIRES_RECOVERY')
        values = self._show()
        summary = {key: values.get(key, '') for key in ('Id', 'Type', 'ActiveState', 'SubState', 'MainPID',
                    'User', 'Group', 'WorkingDirectory', 'KillMode', 'KillSignal', 'SendSIGKILL', 'TimeoutStopUSec')}
        summary['hooks_present'] = any(values.get(key, '') for key in self.HOOKS)
        summary['dropins_present'] = bool(values['DropInPaths'])
        self._validate_unit(values)
        self.progress('SYSTEMD_QUEUE_PROFILE=' + json.dumps(summary, sort_keys=True))
        if (values['ActiveState'] != 'active' or values['SubState'] != 'running'
                or values['NeedDaemonReload'] != 'no' or values['DropInPaths'] or values.get('Job', '0') not in ('', '0')):
            fail('SYSTEMD_QUEUE_NOT_STABLE_RUNNING_UNIT')
        if self._dropin_roster(values['FragmentPath']):
            fail('SYSTEMD_QUEUE_UNREVIEWED_DROPIN_FILES')
        worker = self._inspect_worker(values)
        self._inventory({worker['pid']})
        self.data = {'version': 1, 'backend': 'exact-systemd', 'root': self.root, 'uid': self.uid, 'php': self.php,
            'unit': self.UNIT, 'runtime_root': self.runtime_root, 'cgroup_root': self.cgroup_root,
            'systemctl': self.systemctl, 'override_path': str(self.override),
            'override_sha256': hashlib.sha256(self.OVERRIDE).hexdigest(),
            'override_directory_existed': self.override.parent.exists(), 'profile': self._base_profile(values),
            'sources': self._sources(values), 'worker': worker, 'controller': self._binary_fingerprint(self.systemctl),
            'original_state': 'active', 'original_send_sigkill': values['SendSIGKILL'],
            'original_timeout': values['TimeoutStopUSec']}
        self._validate_saved(values)
        return self.receipt()

    def receipt(self):
        if self.data is None:
            fail('SYSTEMD_QUEUE_NOT_PREPARED')
        return json.loads(json.dumps(self.data))

    def load_receipt(self, data):
        if self.data is not None:
            fail('SYSTEMD_QUEUE_ALREADY_PREPARED')
        try:
            fixed = {'version': 1, 'backend': 'exact-systemd', 'root': self.root, 'uid': self.uid, 'php': self.php,
                     'unit': self.UNIT, 'runtime_root': self.runtime_root, 'cgroup_root': self.cgroup_root,
                     'systemctl': self.systemctl, 'override_path': str(self.override),
                     'override_sha256': hashlib.sha256(self.OVERRIDE).hexdigest(), 'original_state': 'active'}
            if any(data[key] != value for key, value in fixed.items()):
                fail('SYSTEMD_QUEUE_RECEIPT_INVALID')
            self.data = json.loads(json.dumps(data))
            self._validate_saved()
        except (KeyError, TypeError, ValueError):
            self.data = None
            fail('SYSTEMD_QUEUE_RECEIPT_INVALID')
        return self.receipt()

    def _sync_parent(self):
        descriptor = os.open(str(self.override.parent), os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)

    def _publish_override(self):
        if self._override_present():
            return
        self._trusted_directory(self.runtime_root)
        if not self.override.parent.exists():
            self.override.parent.mkdir(mode=0o755)
        self._trusted_directory(self.override.parent)
        descriptor, temporary = tempfile.mkstemp(prefix='.launch-queue-override-', dir=str(self.override.parent))
        temporary = Path(temporary)
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(self.OVERRIDE)
                stream.flush()
                os.fchmod(stream.fileno(), 0o644)
                os.fsync(stream.fileno())
            # link() publishes the complete durable inode atomically and refuses
            # to replace an existing administrator file.
            try:
                os.link(str(temporary), str(self.override), follow_symlinks=False)
            except FileExistsError:
                fail('SYSTEMD_QUEUE_OVERRIDE_PUBLICATION_RACE')
            self._sync_parent()
        finally:
            if temporary.exists():
                temporary.unlink()
                self._sync_parent()

    def _arm(self):
        values = self._validate_saved()
        self._publish_override()
        self._ctl('daemon-reload')
        values = self._validate_saved()
        if values['NeedDaemonReload'] != 'no' or self._loaded_mode(values) != 'protected':
            fail('SYSTEMD_QUEUE_NO_KILL_POLICY_NOT_LOADED')
        return values

    def _stopped(self, values):
        members, _ = self._members()
        return (values['ActiveState'] in ('inactive', 'failed') and self._number(values['MainPID']) == 0
                and self._number(values['ControlPID']) == 0 and not members and values.get('Job', '0') in ('', '0'))

    def _wait_stopped(self):
        deadline = self.clock() + 45
        next_progress = self.clock() + 5
        while True:
            values = self._validate_saved()
            if self._stopped(values):
                return values
            if self.clock() >= deadline:
                self.progress('SYSTEMD_QUEUE_STOP_PENDING_NO_KILL_POLICY_RETAINED')
                fail('SYSTEMD_QUEUE_STOP_PENDING_OVERRIDE_RETAINED')
            if self.clock() >= next_progress:
                self.progress('SYSTEMD_QUEUE_WAITING_FOR_GRACEFUL_STOP')
                next_progress = self.clock() + 5
            self.sleep(1)

    def pause(self, record=None):
        values = self._validate_saved()
        if values['ActiveState'] != 'active' or self._inspect_worker(values) != self.data['worker']:
            fail('SYSTEMD_QUEUE_WORKER_CHANGED_BEFORE_PAUSE')
        self._inventory({self.data['worker']['pid']})
        if record:
            record(self.receipt())  # Also safe when outer installer already journaled the full plan.
        self._arm()
        values = self._validate_saved()
        if self._inspect_worker(values) != self.data['worker']:
            fail('SYSTEMD_QUEUE_WORKER_CHANGED_BEFORE_STOP')
        self._inventory({self.data['worker']['pid']})
        self.progress('SYSTEMD_QUEUE_STOP_REQUESTED=' + self.UNIT)
        self._ctl('stop', '--no-block', self.UNIT)
        self._wait_stopped()
        self._inventory(set())
        if record:
            record(self.receipt())
        self.progress('SYSTEMD_QUEUE_QUIESCENT')
        return self.receipt()

    def _remove_override(self):
        if self._override_present():
            self.override.unlink()
            self._sync_parent()
        self._ctl('daemon-reload')
        values = self._validate_saved()
        if self._loaded_mode(values) != 'original' or values['NeedDaemonReload'] != 'no':
            fail('SYSTEMD_QUEUE_ORIGINAL_POLICY_NOT_RESTORED')
        if not self.data['override_directory_existed'] and self.override.parent.exists():
            try:
                self.override.parent.rmdir()  # Never remove other administrator-created content.
            except OSError:
                pass
        return values

    def _wait_ready(self):
        deadline = self.clock() + 45
        while True:
            values = self._validate_saved()
            if values['ActiveState'] == 'active' and values['SubState'] == 'running' and self._number(values['MainPID']):
                try:
                    self._inspect_worker(values, expected=self.data['worker'])
                    return values
                except QueueSafetyError as error:
                    if str(error) not in ('SYSTEMD_QUEUE_WORKER_HANDLER_NOT_READY', 'QUEUE_PROCESS_DISAPPEARED_OR_CHANGED'):
                        raise
            elif values['ActiveState'] not in ('active', 'activating'):
                fail('SYSTEMD_QUEUE_RESTORE_FAILED')
            if self.clock() >= deadline:
                fail('SYSTEMD_QUEUE_RESTORE_NOT_READY')
            self.sleep(1)

    def restore(self, record=None):
        values = self._validate_saved()
        owns_file = self._override_present()
        protected = self._loaded_mode(values) == 'protected'
        if owns_file or protected or values['ActiveState'] == 'deactivating':
            # A queued stop can still report active. Always reach truly stopped
            # under the protective policy before removing it, including recovery
            # after publication but before the original stop request.
            if not self._stopped(values):
                values = self._arm()
                if values['ActiveState'] in ('active', 'activating'):
                    self._inspect_worker(values, expected=self.data['worker'])
                    self._ctl('stop', '--no-block', self.UNIT)
                self._wait_stopped()  # Timeout retains file and infinity/no-kill.
            self._remove_override()
            values = self._validate_saved()
        if values['ActiveState'] in ('active', 'activating'):
            self._wait_ready()
        else:
            if not self._stopped(values):
                fail('SYSTEMD_QUEUE_RESTORE_STATE_UNSAFE')
            self.progress('SYSTEMD_QUEUE_START_REQUESTED=' + self.UNIT)
            self._ctl('start', '--no-block', self.UNIT)
            self._wait_ready()
        if record:
            record(self.receipt())
        self.progress('SYSTEMD_QUEUE_ORIGINAL_STATE_RESTORED')
        return self.receipt()
