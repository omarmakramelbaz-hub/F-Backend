#!/usr/bin/env python3
"""Pause only positively identified Supervisor programs belonging to this app.

No process killing, Supervisor configuration changes, group wildcards or global
service stops. Call prepare before maintenance; call pause(record) after it.
The caller must persist each receipt in a root-private location before actions.
"""
import configparser
import glob
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shlex
import shutil
import signal
import stat
import subprocess
import time


class QueueSafetyError(RuntimeError):
    pass


def fail(code):
    raise QueueSafetyError(code)


class LinuxProc:
    def pids(self):
        return [int(path.name) for path in Path('/proc').iterdir() if path.name.isdecimal()]

    def topology(self):
        result = []
        for pid in self.pids():
            try:
                fields = (Path('/proc') / str(pid) / 'stat').read_text().rsplit(')', 1)[1].split()
                result.append({'pid': pid, 'ppid': int(fields[1]), 'pgrp': int(fields[2]), 'session': int(fields[3])})
            except (FileNotFoundError, ProcessLookupError):
                continue
            except (OSError, ValueError, IndexError):
                fail('QUEUE_PROCESS_TOPOLOGY_UNREADABLE')
        return result

    def read(self, pid):
        directory = Path('/proc') / str(pid)
        status = {}
        for line in (directory / 'status').read_text().splitlines():
            key, separator, value = line.partition(':')
            if separator:
                status[key] = value.strip()
        fields = (directory / 'stat').read_text().rsplit(')', 1)[1].split()
        arguments = (directory / 'cmdline').read_bytes()
        if len(arguments) > 65536:
            fail('QUEUE_PROCESS_ARGUMENTS_TOO_LARGE')
        return {'pid': pid, 'ppid': int(fields[1]), 'pgrp': int(fields[2]), 'session': int(fields[3]), 'starttime': fields[19],
                'uids': [int(value) for value in status['Uid'].split()],
                'exe': os.readlink(directory / 'exe'), 'cwd': os.readlink(directory / 'cwd'),
                'argv': [part.decode('utf-8') for part in arguments.rstrip(b'\0').split(b'\0')],
                'sig_caught': int(status.get('SigCgt', '0'), 16),
                'cgroups': [line.split(':', 2)[2] for line in (directory / 'cgroup').read_text().splitlines() if line.count(':') >= 2]}


def default_runner(arguments, timeout=15, cwd=None):
    return subprocess.run(arguments, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          timeout=timeout, check=False, cwd=cwd)


class QueueQuiescer:
    def __init__(self, root, uid, php, runner=None, proc=None, supervisorctl=None,
                 clock=None, sleep=None, progress=None):
        self.root = str(Path(root).resolve())
        self.uid = int(uid)
        self.php = str(Path(php).resolve())
        self.runner = runner or default_runner
        self.proc = proc or LinuxProc()
        self.supervisorctl = supervisorctl or shutil.which('supervisorctl')
        self.clock = clock or time.monotonic
        self.sleep = sleep or time.sleep
        self.data = None
        self._interpreters = {}
        self._launchers = {}
        self.progress = progress or (lambda message: None)

    def _command(self, arguments, timeout=15, accepted=(0,), cwd=None):
        try:
            result = self.runner(arguments, timeout=timeout, cwd=cwd)
        except (OSError, subprocess.TimeoutExpired):
            fail('QUEUE_MANAGER_COMMAND_FAILED')
        code, output = result if isinstance(result, tuple) else (result.returncode, result.stdout)
        if code not in accepted:
            fail('QUEUE_MANAGER_COMMAND_FAILED')
        if isinstance(output, str):
            output = output.encode()
        if len(output) > 1048576:
            fail('QUEUE_MANAGER_REPLY_TOO_LARGE')
        try:
            return output.decode('utf-8')
        except UnicodeDecodeError:
            fail('QUEUE_MANAGER_REPLY_INVALID')

    def _trusted_file(self, path, root_owned=True):
        path = Path(path)
        if not path.is_absolute() or str(path.resolve()) != str(path) or path.is_symlink():
            fail('QUEUE_MANAGER_FILE_UNSAFE')
        try:
            descriptor = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(descriptor, 'rb') as stream:
                before = os.fstat(stream.fileno())
                content = stream.read(1048577)
                after = os.fstat(stream.fileno())
        except OSError:
            fail('QUEUE_MANAGER_FILE_UNREADABLE')
        if (not stat.S_ISREG(before.st_mode) or len(content) > 1048576
                or (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
            fail('QUEUE_MANAGER_FILE_CHANGED')
        if root_owned:
            if before.st_uid != 0 or before.st_mode & 0o022:
                fail('QUEUE_MANAGER_FILE_UNTRUSTED')
            for parent in path.parents:
                info = parent.stat()
                # A root-owned sticky ancestor such as /tmp cannot rename a
                # root-owned child. Every non-sticky directory must be private.
                if info.st_uid != 0 or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                    fail('QUEUE_MANAGER_FILE_UNTRUSTED')
        return content, {'path': str(path), 'sha256': hashlib.sha256(content).hexdigest(),
                         'uid': before.st_uid, 'mode': stat.S_IMODE(before.st_mode)}

    def _read(self, pid):
        try:
            return self.proc.read(int(pid))
        except (OSError, ValueError, KeyError, UnicodeError):
            fail('QUEUE_PROCESS_DISAPPEARED_OR_CHANGED')

    def _php_class(self, path):
        if path == self.php:
            return 'reset_php'
        if re.fullmatch(r'/opt/cpanel/ea-php[0-9]{2}/root/usr/bin/php', path):
            return 'cpanel_php'
        if re.fullmatch(r'/opt/alt/php[0-9]{2}/usr/bin/php', path):
            return 'cloudlinux_php'
        if re.fullmatch(r'/usr/(?:local/)?bin/php(?:[0-9]+(?:\.[0-9]+)?)?', path):
            return 'system_php'
        if re.fullmatch(r'/usr/(?:local/)?bin/ea-php-cli', path):
            return 'cpanel_selector'
        return 'other'

    def _binary_fingerprint(self, path):
        original = Path(path)
        if not original.is_absolute():
            fail('QUEUE_PHP_LAUNCHER_RELATIVE_REQUIRES_REVIEW')
        resolved = original.resolve()
        if self._php_class(str(resolved)) == 'other':
            fail('QUEUE_WORKER_PHP_PATH_UNSUPPORTED')
        try:
            # Validate both the launcher alias and canonical target ancestry.
            # Root-owned symlinks are allowed; their parent directories remain trusted.
            for candidate in (original, resolved):
                link = candidate.lstat()
                if link.st_uid != 0 or (not stat.S_ISLNK(link.st_mode) and link.st_mode & 0o022):
                    fail('QUEUE_WORKER_PHP_FILE_UNTRUSTED')
                for parent in candidate.parents:
                    info = parent.stat()
                    if info.st_uid != 0 or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                        fail('QUEUE_WORKER_PHP_FILE_UNTRUSTED')
            descriptor = os.open(str(resolved), os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(descriptor, 'rb') as stream:
                before = os.fstat(stream.fileno())
                if not stat.S_ISREG(before.st_mode) or not before.st_mode & 0o111 or before.st_size > 67108864:
                    fail('QUEUE_WORKER_PHP_FILE_UNSUPPORTED')
                digest = hashlib.sha256()
                total = 0
                while True:
                    chunk = stream.read(1048576)
                    if not chunk:
                        break
                    total += len(chunk)
                    if total > 67108864:
                        fail('QUEUE_WORKER_PHP_FILE_UNSUPPORTED')
                    digest.update(chunk)
                after = os.fstat(stream.fileno())
            if ((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                    != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                    or str(original.resolve()) != str(resolved)):
                fail('QUEUE_WORKER_PHP_FILE_CHANGED')
            return {'path': str(original), 'resolved': str(resolved), 'sha256': digest.hexdigest(),
                    'uid': before.st_uid, 'mode': stat.S_IMODE(before.st_mode),
                    'device': before.st_dev, 'inode': before.st_ino, 'size': before.st_size}
        except OSError:
            fail('QUEUE_WORKER_PHP_FILE_UNREADABLE')

    def _probe_php(self, launcher, expected):
        # -n disables all php.ini/autoprepend loading. No application bootstrap,
        # PHP prefix flags or environment values are executed by this probe.
        username = pwd.getpwuid(self.uid).pw_name
        code = "echo json_encode(['version'=>PHP_VERSION_ID,'binary'=>PHP_BINARY,'executable'=>readlink('/proc/self/exe')]);"
        output = self._command(['runuser', '-u', username, '--', launcher,
                                '--no-php-ini', '-r', code], timeout=15, cwd=self.root)
        try:
            value = json.loads(output)
            if (set(value) != {'version', 'binary', 'executable'} or type(value['version']) is not int
                    or value['version'] < 80000 or not isinstance(value['binary'], str)
                    or not isinstance(value['executable'], str)
                    or str(Path(value['executable']).resolve()) != expected):
                fail('QUEUE_WORKER_PHP_PROBE_MISMATCH')
            return value['version']
        except (TypeError, ValueError, KeyError):
            fail('QUEUE_WORKER_PHP_PROBE_INVALID')

    def _interpreter(self, path):
        fingerprint = self._binary_fingerprint(path)
        if fingerprint['resolved'] != path or self._php_class(path) == 'cpanel_selector':
            fail('QUEUE_WORKER_PHP_EXECUTABLE_NOT_CANONICAL')
        old = self._interpreters.get(path)
        if old and old['fingerprint'] != fingerprint:
            fail('QUEUE_WORKER_PHP_FILE_CHANGED')
        if not old:
            old = {'path': path, 'fingerprint': fingerprint, 'version': self._probe_php(path, path)}
            self._interpreters[path] = old
        return old

    def _launcher(self, path, interpreter):
        fingerprint = self._binary_fingerprint(path)
        version = self._probe_php(path, interpreter)
        record = {'path': path, 'fingerprint': fingerprint, 'interpreter': interpreter, 'version': version}
        key = (path, interpreter)
        if key in self._launchers and self._launchers[key] != record:
            fail('QUEUE_WORKER_PHP_LAUNCHER_CHANGED')
        self._launchers[key] = record
        return record

    def _php_script(self, arguments, cwd):
        """Parse PHP's prefix once; never search past a different selected script."""
        index = 1
        while index < len(arguments):
            argument = arguments[index]
            if argument in ('-q', '-n', '--no-php-ini'):
                index += 1
                continue
            if argument in ('-d', '-c', '--define', '--php-ini'):
                if index + 1 >= len(arguments) or not arguments[index + 1]:
                    fail('QUEUE_PHP_PREFIX_OPERAND_MISSING')
                # An artisan token consumed as an ini option is not the script.
                if arguments[index + 1] in ('artisan', './artisan', self.root + '/artisan'):
                    fail('QUEUE_PHP_ARTISAN_CONSUMED_AS_OPTION')
                index += 2
                continue
            if argument.startswith('--define=') or argument.startswith('--php-ini='):
                if not argument.partition('=')[2]:
                    fail('QUEUE_PHP_PREFIX_OPERAND_MISSING')
                index += 1
                continue
            if (argument.startswith('-d') or argument.startswith('-c')) and len(argument) > 2:
                index += 1
                continue
            style = ''
            if argument in ('-f', '--file'):
                index += 1
                if index >= len(arguments) or not arguments[index]:
                    fail('QUEUE_PHP_PREFIX_OPERAND_MISSING')
                argument = arguments[index]
            elif argument.startswith('--file='):
                style, argument = '--file=', argument.partition('=')[2]
            elif argument.startswith('-f') and len(argument) > 2:
                style, argument = '-f', argument[2:]
            elif argument.startswith('-'):
                fail('QUEUE_PHP_PREFIX_UNSUPPORTED')
            if not argument or argument == '-':
                fail('QUEUE_PHP_STDIN_UNSUPPORTED')
            artisan = argument == self.root + '/artisan' or (argument in ('artisan', './artisan') and cwd == self.root)
            if not artisan:
                return None
            task_index = index + 1
            if task_index < len(arguments) and arguments[task_index] == '--':
                task_index += 1
            if task_index >= len(arguments):
                return None
            return {'script_index': index, 'script_style': style,
                    'task_index': task_index, 'task': arguments[task_index]}
        return None

    def _normalized_arguments(self, arguments, parsed, interpreter):
        normalized = list(arguments)
        normalized[0] = interpreter
        normalized[parsed['script_index']] = parsed['script_style'] + self.root + '/artisan'
        return normalized

    def _profile(self, value, parsed=None, code=None):
        parent_kind = 'unavailable'
        unit = None
        try:
            parent = self.proc.read(value['ppid'])
            if self._manager(parent):
                parent_kind = 'supervisord'
            elif Path(parent.get('exe', '')).name == 'systemd':
                parent_kind = 'systemd'
            elif Path(parent.get('exe', '')).name in ('bash', 'sh', 'dash', 'zsh'):
                parent_kind = 'shell'
            else:
                parent_kind = 'other'
            for group in value.get('cgroups', []):
                for part in group.split('/'):
                    if re.fullmatch(r'[A-Za-z0-9_.@-]{1,120}\.service', part):
                        unit = part
        except (OSError, ValueError, KeyError, UnicodeError):
            pass
        classification = self._php_class(value.get('exe', ''))
        details = {'pid': value['pid'], 'task': parsed['task'] if parsed else 'unparsed',
                   'artisan_arg_index': parsed['script_index'] if parsed else None,
                   'cwd_match': value.get('cwd') == self.root,
                   'uids_match': {name: number == self.uid for name, number in zip(('real', 'effective', 'saved', 'filesystem'), value.get('uids', []))},
                   'launch_php_match': value.get('exe') == self.php, 'php_class': classification,
                   'interpreter_deleted': value.get('exe', '').endswith(' (deleted)'),
                   'term_handler_caught': bool(value.get('sig_caught', 0) & (1 << (signal.SIGTERM - 1))),
                   'parent_manager': parent_kind, 'systemd_unit': unit}
        if classification != 'other':
            try:
                fingerprint = self._binary_fingerprint(value['exe'])
                details['php_path'] = fingerprint['resolved']
            except QueueSafetyError:
                details['php_class'] = 'untrusted_or_unavailable'
        if code:
            details['code'] = code
        self.progress('QUEUE_WORKER_PROFILE=' + json.dumps(details, sort_keys=True))

    def _worker(self, value):
        if not value['uids'] or value['uids'][0] != self.uid:
            return None
        arguments = value['argv']
        # This is only a diagnostic candidacy check. Actual recognition is the
        # prefix parser below; later artisan-like application arguments cannot
        # turn a different PHP script into a worker.
        candidate = any((argument in ('artisan', './artisan', self.root + '/artisan')
                         or argument in ('-fartisan', '--file=artisan', '-f' + self.root + '/artisan', '--file=' + self.root + '/artisan'))
                        and index + 1 < len(arguments) and arguments[index + 1] in ('queue:work', 'queue:listen', 'horizon', 'horizon:work', '--')
                        for index, argument in enumerate(arguments))
        try:
            parsed = self._php_script(arguments, value['cwd'])
        except QueueSafetyError as error:
            if not candidate:
                return None
            self._profile(value, code=str(error))
            raise
        if parsed is None or parsed['task'] not in ('queue:work', 'queue:listen', 'horizon', 'horizon:work'):
            return None
        self._profile(value, parsed)
        if (parsed['task'] != 'queue:work' or value['cwd'] != self.root
                or value['uids'] != [self.uid] * 4):
            fail('QUEUE_WORKER_PROFILE_UNSUPPORTED')
        if not value.get('sig_caught', 0) & (1 << (signal.SIGTERM - 1)):
            fail('QUEUE_WORKER_GRACEFUL_TERM_UNAVAILABLE')
        interpreter = self._interpreter(value['exe'])
        normalized = self._normalized_arguments(arguments, parsed, interpreter['path'])
        timeout, sleep, rest = self._worker_limits(arguments[parsed['task_index'] + 1:])
        return {'pid': int(value['pid']), 'ppid': int(value['ppid']),
                'pgrp': int(value['pgrp']), 'session': int(value['session']),
                'starttime': str(value['starttime']), 'interpreter': interpreter['path'],
                'command_sha256': self._command_hash(normalized),
                'minimum_stopwait': max(120, timeout + sleep + rest + 30)}

    @staticmethod
    def _command_hash(arguments):
        return hashlib.sha256(json.dumps(arguments, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()

    @staticmethod
    def _worker_limits(arguments):
        limits = {'timeout': 60, 'sleep': 3, 'rest': 0}
        position = 0
        while position < len(arguments):
            argument = arguments[position]
            if argument == '--force':
                fail('QUEUE_WORKER_FORCE_UNSUPPORTED')
            for name in limits:
                if argument == '--' + name:
                    position += 1
                    if position >= len(arguments):
                        fail('QUEUE_WORKER_LIMIT_INVALID')
                    value = arguments[position]
                elif argument.startswith('--' + name + '='):
                    value = argument.partition('=')[2]
                else:
                    continue
                if not re.fullmatch('[0-9]{1,5}', value):
                    fail('QUEUE_WORKER_LIMIT_INVALID')
                limits[name] = int(value)
                break
            position += 1
        if not 1 <= limits['timeout'] <= 86400 or max(limits['sleep'], limits['rest']) > 3600:
            fail('QUEUE_WORKER_LIMIT_UNBOUNDED')
        return limits['timeout'], limits['sleep'], limits['rest']

    def _workers(self):
        workers = []
        for pid in self.proc.pids():
            try:
                value = self.proc.read(pid)
            except (OSError, ValueError, KeyError, UnicodeError):
                continue
            worker = self._worker(value)
            if worker is not None:
                workers.append(worker)
        return sorted(workers, key=lambda value: value['pid'])

    @staticmethod
    def _manager(value):
        return any(Path(argument).name == 'supervisord' for argument in value['argv'][:2])

    def _configuration_candidates(self, manager):
        arguments = manager['argv']
        explicit = []
        for index, argument in enumerate(arguments):
            if argument in ('-c', '--configuration') and index + 1 < len(arguments):
                explicit.append(arguments[index + 1])
            elif argument.startswith('--configuration='):
                explicit.append(argument.partition('=')[2])
            elif argument.startswith('-c') and len(argument) > 2:
                explicit.append(argument[2:])
        if len(explicit) > 1:
            fail('QUEUE_MANAGER_CONFIG_AMBIGUOUS')
        if explicit:
            path = Path(explicit[0])
            if not path.is_absolute():
                path = Path(manager['cwd']) / path
            return [str(path)]
        return [name for name in ('/etc/supervisord.conf', '/etc/supervisor/supervisord.conf') if Path(name).is_file()]

    def _config(self, main):
        content, fingerprint = self._trusted_file(main)
        try:
            parser = configparser.ConfigParser(interpolation=None, strict=True)
            parser.read_string(content.decode('utf-8'))
            included = []
            if parser.has_section('include'):
                patterns = parser.get('include', 'files', fallback='').replace('%(here)s', str(Path(main).parent))
                for pattern in patterns.split():
                    if '%' in pattern:
                        fail('QUEUE_MANAGER_CONFIG_INTERPOLATION_UNSUPPORTED')
                    if not Path(pattern).is_absolute():
                        pattern = str(Path(main).parent / pattern)
                    included.extend(glob.glob(pattern))
            included = sorted(set(included))
            if len(included) > 128:
                fail('QUEUE_MANAGER_CONFIG_TOO_LARGE')
            documents = [content.decode('utf-8')]
            fingerprints = [fingerprint]
            for name in included:
                data, meta = self._trusted_file(name)
                documents.append(data.decode('utf-8'))
                fingerprints.append(meta)
            if sum(len(document) for document in documents) > 4194304:
                fail('QUEUE_MANAGER_CONFIG_TOO_LARGE')
            merged = configparser.ConfigParser(interpolation=None, strict=True)
            merged.read_string('\n'.join(documents))
            endpoint = merged.get('supervisorctl', 'serverurl', fallback='http://localhost:9001')
            if not (re.fullmatch(r'unix:///[^\s]+', endpoint)
                    or re.fullmatch(r'http://(?:localhost|127\.0\.0\.1):[0-9]{1,5}', endpoint)):
                fail('QUEUE_MANAGER_ENDPOINT_UNSUPPORTED')
            return merged, fingerprints
        except (configparser.Error, UnicodeError, ValueError):
            fail('QUEUE_MANAGER_CONFIG_INVALID')

    @staticmethod
    def _status(output):
        result = {}
        for line in output.splitlines():
            match = re.match(r'^([A-Za-z0-9_.:-]+)\s+(RUNNING|STOPPED|STARTING|STOPPING|BACKOFF|EXITED|FATAL|UNKNOWN)\b(?:\s+pid\s+([0-9]+),)?', line)
            if not match or match[1] in result:
                fail('QUEUE_MANAGER_STATUS_INVALID')
            result[match[1]] = {'state': match[2], 'pid': int(match[3] or 0)}
        return result

    def _ctl(self, manager, *arguments, **kwargs):
        return self._command([self.supervisorctl, '-c', manager['config']] + list(arguments), **kwargs)

    @staticmethod
    def _expand(value, context):
        try:
            return value % context
        except (KeyError, TypeError, ValueError):
            fail('QUEUE_MANAGER_CONFIG_INTERPOLATION_UNSUPPORTED')

    def _program(self, config, manager_process, name, worker):
        candidates = []
        for section in config.sections():
            if not section.startswith('program:'):
                continue
            program = section.partition(':')[2]
            if not re.fullmatch('[A-Za-z0-9_.-]+', program):
                fail('QUEUE_MANAGER_PROGRAM_NAME_INVALID')
            group = program
            for group_section in config.sections():
                if group_section.startswith('group:') and program in [part.strip() for part in config.get(group_section, 'programs', fallback='').split(',')]:
                    if group != program:
                        fail('QUEUE_MANAGER_PROGRAM_AMBIGUOUS')
                    group = group_section.partition(':')[2]
            if group != name.split(':', 1)[0]:
                continue
            try:
                count = int(config.get(section, 'numprocs', fallback='1'))
                offset = int(config.get(section, 'numprocs_start', fallback='0'))
            except ValueError:
                fail('QUEUE_MANAGER_CONFIG_INVALID')
            if not 1 <= count <= 64 or not 0 <= offset <= 10000:
                fail('QUEUE_MANAGER_PROGRAM_COUNT_UNSUPPORTED')
            for number in range(offset, offset + count):
                context = {'program_name': program, 'group_name': group, 'process_num': number,
                           'numprocs': count, 'host_node_name': os.uname().nodename,
                           'here': str(Path(self._current_main).parent)}
                process = self._expand(config.get(section, 'process_name', fallback='%(program_name)s'), context)
                namespec = process if process == group else group + ':' + process
                if namespec != name:
                    continue
                user = self._expand(config.get(section, 'user', fallback=''), context)
                try:
                    configured_uid = int(user) if user.isdecimal() else pwd.getpwnam(user).pw_uid
                except (KeyError, ValueError):
                    fail('QUEUE_MANAGER_PROGRAM_USER_INVALID')
                if configured_uid != self.uid:
                    fail('QUEUE_MANAGER_PROGRAM_USER_INVALID')
                directory = self._expand(config.get(section, 'directory', fallback=manager_process['cwd']), context)
                if str(Path(directory).resolve()) != self.root:
                    fail('QUEUE_MANAGER_PROGRAM_DIRECTORY_INVALID')
                try:
                    arguments = shlex.split(self._expand(config.get(section, 'command'), context))
                except ValueError:
                    fail('QUEUE_MANAGER_PROGRAM_COMMAND_INVALID')
                if len(arguments) < 3:
                    fail('QUEUE_MANAGER_PROGRAM_COMMAND_INVALID')
                parsed = self._php_script(arguments, self.root)
                if parsed is None or parsed['task'] != 'queue:work':
                    fail('QUEUE_MANAGER_PROGRAM_COMMAND_INVALID')
                launcher = self._launcher(arguments[0], worker['interpreter'])
                normalized = self._normalized_arguments(arguments, parsed, launcher['interpreter'])
                if self._command_hash(normalized) != worker['command_sha256']:
                    fail('QUEUE_MANAGER_PROGRAM_COMMAND_INVALID')
                stop_signal = config.get(section, 'stopsignal', fallback='TERM').upper()
                try:
                    stopwait = int(config.get(section, 'stopwaitsecs', fallback='10'))
                except ValueError:
                    fail('QUEUE_MANAGER_STOP_POLICY_INVALID')
                self.progress('QUEUE_MANAGER_STOP_POLICY=' + json.dumps({'manager': 'supervisor', 'program': name,
                    'stopwaitsecs': stopwait, 'required_stopwaitsecs': worker['minimum_stopwait']}, sort_keys=True))
                if stop_signal not in ('TERM', 'SIGTERM', str(signal.SIGTERM)) or stopwait < worker['minimum_stopwait']:
                    fail('QUEUE_MANAGER_GRACEFUL_STOP_WINDOW_TOO_SHORT')
                flags = []
                for key in ('stopasgroup', 'killasgroup'):
                    value = config.get(section, key, fallback='false').lower()
                    if value not in ('true', 'false', '1', '0', 'yes', 'no', 'on', 'off'):
                        fail('QUEUE_MANAGER_GROUP_POLICY_INVALID')
                    flags.append(value in ('true', '1', 'yes', 'on'))
                group_signals = any(flags)
                if group_signals:
                    self._validate_isolated_group(worker)
                candidates.append({'program': name, 'before_state': 'RUNNING', 'stopwaitsecs': stopwait,
                                   'group_signals': group_signals, 'worker': worker})
        if len(candidates) != 1:
            fail('QUEUE_MANAGER_PROGRAM_AMBIGUOUS')
        return candidates[0]

    def _validate_isolated_group(self, worker):
        # Supervisor's group flags are safe only for a group containing this
        # worker alone. Check again immediately before its exact stop request.
        if worker['pgrp'] != worker['pid']:
            fail('QUEUE_MANAGER_WORKER_GROUP_NOT_ISOLATED')
        if hasattr(self.proc, 'topology'):
            processes = self.proc.topology()
        else:
            processes = []
            for pid in self.proc.pids():
                try:
                    processes.append(self.proc.read(pid))
                except (FileNotFoundError, ProcessLookupError):
                    continue
                except (OSError, ValueError, KeyError, UnicodeError):
                    fail('QUEUE_PROCESS_TOPOLOGY_UNREADABLE')
        for process in processes:
            if process['pid'] == worker['pid']:
                continue
            if process['pgrp'] == worker['pgrp'] or process['ppid'] == worker['pid']:
                fail('QUEUE_MANAGER_WORKER_GROUP_NOT_ISOLATED')

    def prepare(self):
        if self.data is not None:
            fail('QUEUE_MANAGER_ALREADY_PREPARED')
        workers = self._workers()
        sources = []
        managers = []
        if workers:
            if not self.supervisorctl:
                fail('QUEUE_MANAGER_SUPERVISORCTL_UNAVAILABLE')
            self.supervisorctl = str(Path(self.supervisorctl).resolve())
            _, controller_fingerprint = self._trusted_file(self.supervisorctl)
            sources.append(controller_fingerprint)
            for relative in ('artisan', 'vendor/laravel/framework/src/Illuminate/Queue/Worker.php'):
                data, meta = self._trusted_file(Path(self.root) / relative, root_owned=False)
                if relative.endswith('Worker.php') and b'pcntl_signal' not in data:
                    fail('QUEUE_WORKER_SIGNAL_SOURCE_UNSUPPORTED')
                sources.append(meta)
            by_parent = {}
            for worker in workers:
                by_parent.setdefault(worker['ppid'], []).append(worker)
            for parent, owned_workers in sorted(by_parent.items()):
                manager_process = self._read(parent)
                if not self._manager(manager_process):
                    fail('QUEUE_MANAGER_UNSUPPORTED_SUPERVISOR_REQUIRED')
                matches = []
                for main in self._configuration_candidates(manager_process):
                    config, fingerprints = self._config(main)
                    candidate = {'pid': parent, 'starttime': str(manager_process['starttime']), 'config': main,
                                 'config_files': fingerprints, 'programs': []}
                    output = self._ctl(candidate, 'pid').strip()
                    if output.isdecimal() and int(output) == parent:
                        matches.append((candidate, config))
                if len(matches) != 1:
                    fail('QUEUE_MANAGER_CONFIG_AMBIGUOUS')
                manager, config = matches[0]
                statuses = self._status(self._ctl(manager, 'status', accepted=(0, 3)))
                self._current_main = manager['config']
                for worker in owned_workers:
                    names = [name for name, value in statuses.items() if value == {'state': 'RUNNING', 'pid': worker['pid']}]
                    if len(names) != 1:
                        fail('QUEUE_MANAGER_PROCESS_MAPPING_AMBIGUOUS')
                    manager['programs'].append(self._program(config, manager_process, names[0], worker))
                managers.append(manager)
        self.data = {'version': 1, 'root': self.root, 'uid': self.uid, 'php': self.php,
                     'supervisorctl': self.supervisorctl, 'sources': sources,
                     'interpreters': list(self._interpreters.values()), 'launchers': list(self._launchers.values()),
                     'managers': managers, 'phase': 'prepared', 'requested': []}
        self._validate_saved()
        return self.receipt()

    def receipt(self):
        if self.data is None:
            fail('QUEUE_MANAGER_NOT_PREPARED')
        return json.loads(json.dumps(self.data))

    def load_receipt(self, data):
        if self.data is not None:
            fail('QUEUE_MANAGER_ALREADY_PREPARED')
        try:
            if (data['version'] != 1 or data['root'] != self.root or data['uid'] != self.uid
                    or data['php'] != self.php or not isinstance(data['managers'], list)
                    or len(data['managers']) > 16 or not isinstance(data['sources'], list)
                    or not isinstance(data['interpreters'], list) or not isinstance(data['launchers'], list)
                    or data['phase'] not in ('prepared', 'pause-intent', 'pausing', 'paused', 'restoring', 'restored')):
                fail('QUEUE_MANAGER_RECEIPT_INVALID')
            self.supervisorctl = data['supervisorctl']
            self.data = json.loads(json.dumps(data))
            self._interpreters = {item['path']: item for item in self.data['interpreters']}
            self._launchers = {(item['path'], item['interpreter']): item for item in self.data['launchers']}
            for manager in self.data['managers']:
                if (not isinstance(manager['pid'], int) or manager['pid'] < 1
                        or not str(manager['starttime']).isdecimal()
                        or len(manager['programs']) > 64):
                    fail('QUEUE_MANAGER_RECEIPT_INVALID')
                for program in manager['programs']:
                    if (not re.fullmatch('[A-Za-z0-9_.:-]+', program['program'])
                            or program['before_state'] != 'RUNNING'
                            or not re.fullmatch('[a-f0-9]{64}', program['worker']['command_sha256'])):
                        fail('QUEUE_MANAGER_RECEIPT_INVALID')
            self._validate_saved()
        except (KeyError, TypeError, ValueError):
            self.data = None
            fail('QUEUE_MANAGER_RECEIPT_INVALID')
        return self.receipt()

    def _validate_saved(self):
        if self.data is None:
            fail('QUEUE_MANAGER_NOT_PREPARED')
        for interpreter in self.data['interpreters']:
            if self._binary_fingerprint(interpreter['path']) != interpreter['fingerprint']:
                fail('QUEUE_WORKER_PHP_FILE_CHANGED')
            if self._probe_php(interpreter['path'], interpreter['path']) != interpreter['version']:
                fail('QUEUE_WORKER_PHP_VERSION_CHANGED')
        for launcher in self.data['launchers']:
            if self._binary_fingerprint(launcher['path']) != launcher['fingerprint']:
                fail('QUEUE_WORKER_PHP_LAUNCHER_CHANGED')
            if self._probe_php(launcher['path'], launcher['interpreter']) != launcher['version']:
                fail('QUEUE_WORKER_PHP_LAUNCHER_CHANGED')
        for meta in self.data['sources']:
            _, current = self._trusted_file(meta['path'], root_owned=False)
            if current != meta:
                fail('QUEUE_WORKER_SOURCE_CHANGED')
        if self.data['managers']:
            self._trusted_file(self.supervisorctl)
        for manager in self.data['managers']:
            process = self._read(manager['pid'])
            if str(process['starttime']) != manager['starttime'] or not self._manager(process):
                fail('QUEUE_MANAGER_IDENTITY_CHANGED')
            _, current = self._config(manager['config'])
            if current != manager['config_files']:
                fail('QUEUE_MANAGER_CONFIG_CHANGED')
            if self._ctl(manager, 'pid').strip() != str(manager['pid']):
                fail('QUEUE_MANAGER_IDENTITY_CHANGED')

    def _program_status(self, manager, program):
        statuses = self._status(self._ctl(manager, 'status', program['program'], accepted=(0, 3)))
        if set(statuses) != {program['program']}:
            fail('QUEUE_MANAGER_STATUS_INVALID')
        return statuses[program['program']]

    def pause(self, record=None):
        # The root caller may have persisted receipt() before invoking pause().
        # A supplied recorder additionally journals individual stop requests.
        if record is None:
            record = lambda receipt: None
        if not callable(record):
            fail('QUEUE_MANAGER_JOURNAL_REQUIRED')
        self._validate_saved()
        expected = sorted([program['worker'] for manager in self.data['managers'] for program in manager['programs']], key=lambda value: value['pid'])
        if self._workers() != expected:
            fail('QUEUE_WORKERS_CHANGED_BEFORE_PAUSE')
        self.data['phase'] = 'pause-intent'
        record(self.receipt())  # Full original RUNNING roster is durable before any stop.
        for manager in self.data['managers']:
            for program in manager['programs']:
                self._validate_saved()
                if self._program_status(manager, program) != {'state': 'RUNNING', 'pid': program['worker']['pid']}:
                    fail('QUEUE_WORKER_CHANGED_BEFORE_STOP')
                # Re-read PID start time/UID/arguments immediately before the exact manager request.
                if self._worker(self._read(program['worker']['pid'])) != program['worker']:
                    fail('QUEUE_WORKER_CHANGED_BEFORE_STOP')
                self.data['phase'] = 'pausing'
                self.data['requested'].append({'manager_pid': manager['pid'], 'program': program['program']})
                record(self.receipt())
                self.progress('QUEUE_PROGRAM_STOP_REQUESTED=' + program['program'])
                if program['group_signals']:
                    self._validate_isolated_group(program['worker'])
                self._ctl(manager, 'stop', program['program'], timeout=45)
                if self._program_status(manager, program)['state'] != 'STOPPED':
                    fail('QUEUE_WORKER_STOP_NOT_CONFIRMED')
        if self._workers():
            fail('QUEUE_WORKERS_REAPPEARED')
        self.data['phase'] = 'paused'
        record(self.receipt())
        return self.receipt()

    def restore(self, record=None):
        self._validate_saved()
        self.data['phase'] = 'restoring'
        if record:
            record(self.receipt())
        for manager in self.data['managers']:
            for program in manager['programs']:
                self._validate_saved()
                status = self._program_status(manager, program)
                if status['state'] in ('STARTING', 'STOPPING'):
                    deadline = self.clock() + 30
                    while status['state'] in ('STARTING', 'STOPPING') and self.clock() < deadline:
                        self.sleep(1)
                        status = self._program_status(manager, program)
                if status['state'] != 'RUNNING':
                    if status['state'] not in ('STOPPED', 'EXITED', 'FATAL', 'BACKOFF'):
                        fail('QUEUE_WORKER_RESTORE_STATE_UNSAFE')
                    self.progress('QUEUE_PROGRAM_START_REQUESTED=' + program['program'])
                    self._ctl(manager, 'start', program['program'], timeout=45)
                    status = self._program_status(manager, program)
                if status['state'] != 'RUNNING':
                    fail('QUEUE_WORKER_RESTORE_FAILED')
                worker = self._worker(self._read(status['pid']))
                if (worker is None or worker['ppid'] != manager['pid']
                        or worker['interpreter'] != program['worker']['interpreter']
                        or worker['command_sha256'] != program['worker']['command_sha256']):
                    fail('QUEUE_WORKER_RESTORE_PROFILE_CHANGED')
                if program['group_signals']:
                    self._validate_isolated_group(worker)
        self.data['phase'] = 'restored'
        if record:
            record(self.receipt())
        return self.receipt()
