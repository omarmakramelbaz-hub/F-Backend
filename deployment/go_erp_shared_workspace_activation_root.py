#!/usr/bin/env python3
"""Pinned activation effects; no production action is enabled by this file alone.

The loader must pin this file, source_io, env_plan and the PHP handler attestor,
hold the original rollout locks, and supply the exact compiled release binding.
Private receipts never contain subprocess output, environment values or passwords.
"""
import base64
import copy
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import selectors
import socket
import ssl
import stat
import subprocess
import time

ROOT = Path('/home/fasakha/public_html')
PRIVATE = Path('/var/lib/go-erp-manual-updates/private/shared-workspace-activation')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
FPM = 'ea-php82-php-fpm.service'
FPM_EXE = '/opt/cpanel/ea-php82/root/usr/sbin/php-fpm'
ORIGIN = 'https://fasakhaninja.com'
FLAG = 'DESKTOP_DASHBOARD_WORKSPACE_ENABLED'
APP_UID, APP_GID = 1001, 1003
COMPILED_ACTIVATION_PINS = None
SHA = re.compile(r'[a-f0-9]{64}\Z')
MAXIMUM = 131072
NAMES = frozenset(('https_readiness', 'verify_handler', 'activation_flag',
    'activation_cache', 'activation_route_cache', 'activation_fpm', 'activation_https', 'prepare_disabled'))


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_ACTIVATION_' + code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'


def sha(value):
    return hashlib.sha256(value).hexdigest()


def digest(value):
    return sha(canonical(value))


def env_enabled(raw):
    match = re.search(rb'(?m)^[ \t]*(?:export[ \t]+)?DESKTOP_DASHBOARD_WORKSPACE_ENABLED[ \t]*=[ \t]*'
        rb'(?P<value>true|false|0|1|"true"|"false"|\'true\'|\'false\')(?=[ \t#\r\n]|$)', raw)
    return match is not None and match['value'].strip(b'\'"') in (b'true', b'1')


def json_result(value):
    need(set(value) == {'returncode', 'stdout', 'stderr'} and value['returncode'] == 0
        and isinstance(value['stdout'], bytes) and isinstance(value['stderr'], bytes)
        and len(value['stdout']) + len(value['stderr']) <= MAXIMUM, 'PRIVATE_COMMAND_FAILED')
    try:
        result = json.loads(value['stdout'])
    except (ValueError, UnicodeError):
        raise Stop('GO_ERP_SHARED_WORKSPACE_ACTIVATION_PRIVATE_COMMAND_INVALID')
    need(isinstance(result, dict), 'PRIVATE_COMMAND_INVALID')
    return result


class ProcessRunner:
    """No shell; bounded private pipes; fixed identity, PHP and systemd unit.

    Only source-hash-approved PHP programs can execute. The input JSON is supplied
    through stdin, never argv. This class is shared with the two-path migrator.
    """
    def __init__(self, verify_locks, *, approved_program_hashes=None, clock=time.monotonic):
        need(callable(verify_locks), 'LOCK_VERIFIER_REQUIRED')
        self.verify_locks = verify_locks
        self.hashes = approved_program_hashes
        self.clock = clock

    def _verify(self):
        need(self.hashes is not None and isinstance(self.hashes, (set, frozenset)) and self.hashes
            and all(isinstance(item, str) and SHA.fullmatch(item) for item in self.hashes),
            'RUNNER_PINS_UNAVAILABLE')
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524'
            and self.verify_locks() is not False, 'RUNNER_IDENTITY_OR_LOCK_CHANGED')

    def _run(self, argv, raw, *, cwd, uid, gid, timeout, maximum):
        self._verify()
        need(cwd == str(ROOT) and (uid, gid) in ((APP_UID, APP_GID), (0, 0))
            and 0 < timeout <= 300 and 0 < maximum <= 2 * 1024**2
            and isinstance(raw, bytes) and len(raw) <= 1024**2, 'RUNNER_SCOPE_INVALID')
        def drop():
            os.setgroups([])
            os.setgid(gid)
            os.setuid(uid)
        proc = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, cwd=cwd, env={'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'},
            preexec_fn=drop, start_new_session=True, close_fds=True)
        # Helpers receive a bounded small request; concurrently drain both output
        # pipes so a malformed child cannot fill memory or deadlock stderr.
        selector = selectors.DefaultSelector()
        output = {'stdout': bytearray(), 'stderr': bytearray()}
        try:
            os.set_blocking(proc.stdin.fileno(), False)
            selector.register(proc.stdin, selectors.EVENT_WRITE, 'stdin')
            for key in output:
                stream = getattr(proc, key)
                os.set_blocking(stream.fileno(), False)
                selector.register(stream, selectors.EVENT_READ, key)
            offset, deadline = 0, self.clock() + timeout
            while selector.get_map():
                need(self.clock() < deadline, 'PRIVATE_COMMAND_TIMEOUT')
                for key, _mask in selector.select(min(.25, max(0, deadline - self.clock()))):
                    if key.data == 'stdin':
                        if offset < len(raw):
                            try:
                                offset += os.write(key.fileobj.fileno(), raw[offset:offset + 65536])
                            except BrokenPipeError:
                                offset = len(raw)
                        if offset == len(raw):
                            selector.unregister(key.fileobj)
                            key.fileobj.close()
                    else:
                        chunk = os.read(key.fileobj.fileno(), 65536)
                        if not chunk:
                            selector.unregister(key.fileobj)
                            key.fileobj.close()
                        else:
                            output[key.data].extend(chunk)
                            need(sum(map(len, output.values())) <= maximum, 'PRIVATE_COMMAND_LIMIT')
            status = proc.wait(timeout=max(.01, deadline - self.clock()))
            self._verify()
            return {'returncode': status, **{key: bytes(value) for key, value in output.items()}}
        finally:
            selector.close()
            if proc.poll() is None:
                os.killpg(proc.pid, 9)
                proc.wait()
            for stream in (proc.stdin, proc.stdout, proc.stderr):
                if stream is not None and not stream.closed:
                    stream.close()

    def php(self, program_bytes, input_data_dict, *, cwd=None, uid=1001, gid=1003,
            timeout=180, maximum=MAXIMUM):
        self._verify()
        need(isinstance(program_bytes, bytes) and 0 < len(program_bytes) <= 65536
            and sha(program_bytes) in self.hashes and isinstance(input_data_dict, dict)
            and (uid, gid) == (APP_UID, APP_GID),
            'PHP_PROGRAM_NOT_PINNED')
        code = program_bytes.decode('utf8', errors='strict')
        if code.startswith('<?php'):
            code = code[5:]
        need('?>' not in code and '\x00' not in code, 'PHP_PROGRAM_INVALID')
        return self._run([PHP, '-d', 'display_errors=0', '-r', code], canonical(input_data_dict),
            cwd=str(ROOT) if cwd is None else cwd, uid=uid, gid=gid, timeout=timeout, maximum=maximum)

    def systemctl(self, verb):
        need(verb in ('show', 'reload'), 'SYSTEMD_ACTION_INVALID')
        args = ['/usr/bin/systemctl', verb, FPM]
        if verb == 'show':
            args += ['--property=ActiveState,CanReload,MainPID', '--no-pager']
        return self._run(args, b'', cwd=str(ROOT), uid=0, gid=0, timeout=30, maximum=8192)


CACHE_PROGRAM = br'''<?php
$s=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
if(!is_array($s)||!in_array($s['action']??null,['inspect','config','routes'],true))exit(41);
chdir('/home/fasakha/public_html');require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
if($s['action']!=='inspect'){
    ob_start();$status=$kernel->call($s['action']==='config'?'config:cache':'route:cache',[]);ob_end_clean();
    if($status!==0)exit(42);
    $app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}
$cache=$app->getCachedConfigPath();if(!is_file($cache)||is_link($cache))exit(43);
$c=require $cache;$d=$c['desktop_dashboard']??null;
if(is_array($d)&&!array_key_exists('workspace_enabled',$d)&&($s['allow_absent_workspace']??false)===true)$d['workspace_enabled']=false;
if(!is_array($d)||!is_bool($d['workspace_enabled']??null)||($d['local']??null)!==false)exit(44);
$routes=[];foreach($app['router']->getRoutes()as$r){
    if(str_contains($r->uri(),'desktop-dashboard/workspace'))$routes[]=[$r->uri(),$r->methods(),$r->getActionName()];
}
usort($routes,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
echo json_encode(['workspace_enabled'=>$d['workspace_enabled'],'local'=>false,
 'config_sha256'=>hash_file('sha256',$cache),'config_path'=>$cache,
 'routes_path'=>$app->getCachedRoutesPath(),'routes_cached'=>$app->routesAreCached(),
 'routes'=>$routes],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
'''


class HTTPSClient:
    """TLS certificate and hostname verification, loopback connection, no redirect."""
    def get(self, path, token, *, maximum=MAXIMUM):
        need(re.fullmatch(r'/go-erp-readiness-[a-f0-9]{32}\.php', path)
            and re.fullmatch(r'[a-f0-9]{64}', token), 'HTTPS_SCOPE_INVALID')
        class Loopback(http.client.HTTPSConnection):
            def connect(self):
                sock = socket.create_connection(('127.0.0.1', 443), timeout=15)
                self.sock = self._context.wrap_socket(sock, server_hostname='fasakhaninja.com')
        connection = Loopback('fasakhaninja.com', timeout=15, context=ssl.create_default_context())
        try:
            connection.request('GET', path, headers={'X-Go-Erp-Readiness': token,
                'Cache-Control': 'no-cache', 'Accept': 'application/json'})
            response = connection.getresponse()
            need(response.status == 200 and response.getheader('Content-Type', '').split(';')[0]
                == 'application/json', 'HTTPS_NOT_HEALTHY')
            raw = response.read(maximum + 1)
            need(len(raw) <= maximum, 'HTTPS_LIMIT')
            return json.loads(raw)
        finally:
            connection.close()


class ProcGeneration:
    """Identify real FPM workers by PID/starttime, never PID alone."""
    def identity(self, pid):
        try:
            raw = Path('/proc/' + str(pid) + '/stat').read_bytes()
            need(len(raw) < 8192, 'PROC_STAT_LIMIT')
            end = raw.rfind(b')')
            fields = raw[end + 2:].split()
            need(end > 0 and len(fields) >= 20, 'PROC_STAT_INVALID')
            return {'pid': pid, 'ppid': int(fields[1]), 'start': int(fields[19])}
        except FileNotFoundError:
            return None

    def capture(self, master):
        need(os.readlink('/proc/' + str(master) + '/exe') == FPM_EXE, 'FPM_EXE_CHANGED')
        names = os.listdir('/proc')
        need(len(names) <= 65536, 'PROC_INVENTORY_LIMIT')
        result = []
        for name in names:
            if not name.isdigit():
                continue
            value = self.identity(int(name))
            if value is not None and value['ppid'] == master:
                try:
                    need(os.readlink('/proc/' + name + '/exe') == FPM_EXE, 'FPM_WORKER_EXE_CHANGED')
                except FileNotFoundError:
                    continue
                result.append(value)
        need(len(result) <= 4096, 'FPM_WORKER_LIMIT')
        return sorted(result, key=lambda item: item['pid'])

    def gone(self, workers):
        for item in workers:
            current = self.identity(item['pid'])
            # A replaced master can reparent an old worker to PID1 while its
            # request still runs. A PPID change is never evidence of a drain.
            if current is not None and current['start'] == item['start']:
                return False
        return True


class Activation:
    """Concrete Coordinator adapter with durable per-action intent/receipt files.

    Recovery never repeats a flag write, cache command or FPM reload. It checks
    the owned file inode / produced cache / captured old worker generation first.
    Unknown effects stay private and blocked instead of being overwritten.
    """
    def __init__(self, source_io, env_plan, runner, handler, verify_locks, *,
            compiled_pins=None, https=None, processes=None, clock=time.monotonic, pause=time.sleep):
        self.io, self.env_plan, self.runner, self.handler = source_io, env_plan, runner, handler
        self.verify_locks, self.clock, self.pause = verify_locks, clock, pause
        self.pins = COMPILED_ACTIVATION_PINS if compiled_pins is None else copy.deepcopy(compiled_pins)
        self.https, self.processes = https or HTTPSClient(), processes or ProcGeneration()

    def _approved(self, action):
        need(isinstance(self.pins, dict) and set(self.pins) == {'module_sha256', 'plan_sha256',
            'pins_sha256', 'binding', 'source_files', 'routes', 'route_cache_path'}, 'FINAL_PINS_UNAVAILABLE')
        need(action.get('name') in NAMES and action.get('plan_sha256') == self.pins['plan_sha256']
            and action.get('pins_sha256') == self.pins['pins_sha256']
            and action.get('code_sha256') == self.pins['module_sha256']
            and action.get('identity', {}).get('root') == str(ROOT)
            and action.get('identity', {}).get('uid') == APP_UID
            and action.get('identity', {}).get('gid') == APP_GID
            and action.get('automatic_updates_enabled') is False
            and action.get('allocation_allowed') is False
            and action.get('artifact_replacement_allowed') is False
            and action.get('financial_data_updates_allowed') is False
            and action.get('drop_tables_allowed') is False, 'ACTION_BINDING_CHANGED')
        need(callable(self.verify_locks) and self.verify_locks() is not False, 'LOCK_CHANGED')
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'HOST_OR_UID_CHANGED')
        binding = action.get('parameters', {}).get('binding')
        need(binding is None or canonical(binding) == canonical(self.pins['binding']), 'RELEASE_BINDING_CHANGED')
        self.io.directory(PRIVATE, 0, 0, private=True)

    def _source(self):
        files = self.pins['source_files']
        need(isinstance(files, dict) and files, 'SOURCE_PINS_REQUIRED')
        for name, expected in files.items():
            self.io.source_name(name)
            need(isinstance(expected, str) and SHA.fullmatch(expected), 'SOURCE_PIN_INVALID')
            raw, _info = self.io.read(ROOT / name, APP_UID, APP_GID)
            need(sha(raw) == expected, 'SOURCE_CHANGED')
        self.verify_locks()

    def _read(self, path, *, optional=False):
        if path.parent == ROOT / 'bootstrap/cache':
            # Laravel writes only this compiled cache directory. The source and
            # private audit directory policies retain their stricter grammar.
            self.io.directory(ROOT / 'bootstrap', APP_UID, APP_GID)
            info = path.parent.lstat()
            mode = stat.S_IMODE(info.st_mode)
            need(stat.S_ISDIR(info.st_mode) and path.parent.resolve() == path.parent
                and ((info.st_uid, info.st_gid, mode) in ((APP_UID, APP_GID, 0o755),
                    (APP_UID, APP_GID, 0o775), (0, 0, 0o755))), 'CACHE_DIRECTORY_UNSAFE')
        else:
            self.io.directory(path.parent, APP_UID, APP_GID)
        parent = self.io.parent_fd(path)
        try:
            try:
                before = os.stat(path.name, dir_fd=parent, follow_symlinks=False)
            except FileNotFoundError:
                need(optional, 'FILE_MISSING')
                return None
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
                and (before.st_uid, before.st_gid) in ((0, 0), (APP_UID, APP_GID))
                and stat.S_IMODE(before.st_mode) in (0o600, 0o640, 0o644)
                and 0 < before.st_size <= 16 * 1024**2, 'FILE_UNSAFE')
            fd = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
            with os.fdopen(fd, 'rb') as stream:
                raw = stream.read(16 * 1024**2 + 1)
                need(len(raw) == before.st_size and self.io.identity(before)
                    == self.io.identity(os.fstat(stream.fileno()))
                    == self.io.identity(os.stat(path.name, dir_fd=parent, follow_symlinks=False)), 'FILE_CHANGED')
            return raw, before
        finally:
            os.close(parent)

    def _folder(self, action):
        folder = PRIVATE / ('action-' + digest(action))
        self.io.private_directory(folder, self.verify_locks)
        return folder

    def _load(self, path):
        value = self.io.read(path, 0, 0, optional=True, private=True, limit=2 * 1024**2)
        if value is None:
            return None
        data = json.loads(value[0])
        need(value[0] == canonical(data), 'PRIVATE_RECORD_NOT_CANONICAL')
        return data

    def _save(self, path, value):
        self.io.private_record(path, canonical(value), self.verify_locks)

    def _finish(self, action, folder, result, witnesses):
        record = {'format': 1, 'action_sha256': digest(action), 'plan_sha256': self.pins['plan_sha256'],
            'pins_sha256': self.pins['pins_sha256'], 'result': result, 'witnesses': witnesses}
        self._save(folder / 'complete.json', record)
        pin = digest(record)
        value = {**result}
        if action['name'] not in ('https_readiness',):
            value['private_receipt_sha256'] = pin
        return {'action_sha256': digest(action), 'private_receipt_sha256': pin, 'result': value}

    def _base(self, action):
        p = action['parameters']
        return {key: p[key] for key in ('action_intent_sha256',)
            if key in p} | ({'intent_sha256': p['activation_intent_sha256']}
                if 'activation_intent_sha256' in p else {})

    def _env(self):
        raw, info = self._read(ROOT / '.env')
        before = self.pins['binding']['env_before_sha256']
        enabled = self.pins['binding']['env_enabled_sha256']
        need(sha(raw) in (before, enabled), 'ENV_UNKNOWN_BYTES')
        unchanged = sha(self.env_plan.workspace_env_bytes(raw, enabled=False))
        need(unchanged == self.pins['binding']['env_unchanged_bytes_sha256'], 'ENV_OTHER_BYTES_CHANGED')
        return raw, info

    def _handler(self):
        value = self.handler()
        need(isinstance(value, dict) and isinstance(value.get('configuration'), dict)
            and digest(value['configuration']) == self.pins['binding']['php_handler_configuration_sha256']
            and type(value.get('live', {}).get('master_pid')) is int, 'PHP_HANDLER_CHANGED')
        return value

    def _inspect(self, mode='inspect', *, verify_routes=True, allow_absent_workspace=False):
        result = json_result(self.runner.php(CACHE_PROGRAM, {'action': mode,
            'allow_absent_workspace': allow_absent_workspace}, cwd=str(ROOT),
            uid=APP_UID, gid=APP_GID, timeout=180, maximum=MAXIMUM))
        need(set(result) == {'workspace_enabled', 'local', 'config_sha256', 'config_path',
            'routes_path', 'routes_cached', 'routes'} and type(result['workspace_enabled']) is bool
            and result['local'] is False and result['config_path'] == str(ROOT / 'bootstrap/cache/config.php')
            and result['routes_path'] == str(ROOT / self.pins['route_cache_path'])
            and type(result['routes_cached']) is bool
            and (not verify_routes or canonical(result['routes']) == canonical(self.pins['routes'])),
            'CACHE_CONFIGURATION_CHANGED')
        raw, info = self._read(Path(result['config_path']))
        need(sha(raw) == result['config_sha256'] and (info.st_uid, info.st_gid) == (APP_UID, APP_GID),
            'CACHE_INODE_OR_BYTES_CHANGED')
        routes = self._read(Path(result['routes_path']), optional=True)
        need(result['routes_cached'] is (routes is not None), 'ROUTE_CACHE_CHANGED')
        need(self.pins['binding']['route_cache_required'] is (routes is not None), 'ROUTE_CACHE_POLICY_CHANGED')
        if routes is not None:
            need((routes[1].st_uid, routes[1].st_gid) == (APP_UID, APP_GID), 'ROUTE_CACHE_NOT_OWNED')
        return result, info, routes

    def _readiness(self, action):
        pin = action['parameters'].get('false_flag_https_receipt_sha256')
        need(isinstance(pin, str) and SHA.fullmatch(pin), 'FALSE_FLAG_READINESS_REQUIRED')
        record = self._load(PRIVATE / ('readiness-' + pin + '.json'))
        need(record is not None and digest(record) == pin
            and record['plan_sha256'] == self.pins['plan_sha256']
            and record['pins_sha256'] == self.pins['pins_sha256']
            and record['result']['workspace_enabled'] is False
            and record['result']['healthy'] is True
            and record['result']['served_configuration_verified'] is True
            and record['result']['source_manifest_sha256'] == self.pins['binding']['source_manifest_sha256']
            and record['result']['current_env_sha256'] == self.pins['binding']['env_before_sha256'],
            'FALSE_FLAG_READINESS_REQUIRED')

    def _flag(self, action, folder, recovering):
        self._readiness(action)
        binding = self.pins['binding']
        raw, info = self._env()
        if not recovering:
            cache, _cache_info, _routes = self._inspect()
            receipt = self._load(PRIVATE / ('readiness-' + action['parameters']['false_flag_https_receipt_sha256'] + '.json'))
            need(cache['workspace_enabled'] is False and cache['config_sha256'] == receipt['witnesses']['cache_sha256'],
                'FALSE_FLAG_CACHE_CHANGED')
        need(sha(raw) == binding['env_before_sha256'] or recovering, 'ENV_WRITE_NOT_OWNED')
        after = self.env_plan.workspace_env_bytes(raw, enabled=True)
        need(sha(after) == binding['env_enabled_sha256'], 'ENV_TARGET_CHANGED')
        raw, info = self._replace_env(action, folder, raw, info, after, recovering)
        result = self._base(action) | {'flag': FLAG, 'before_sha256': binding['env_before_sha256'],
            'enabled_sha256': binding['env_enabled_sha256'], 'unchanged_bytes_sha256': binding['env_unchanged_bytes_sha256'],
            'current_sha256': sha(raw), 'only_workspace_flag_changed': True, 'owned_inode_verified': True}
        return self._finish(action, folder, result, {'env_inode': self.io.inode(info)})

    def _replace_env(self, action, folder, raw, info, after, recovering):
        pending = self._load(folder / 'pending.json')
        target = ROOT / '.env'
        if pending is None:
            need(not recovering, 'ENV_WRITE_NOT_OWNED')
            pending = {'action_sha256': digest(action), 'before_inode': self.io.inode(info),
                'before_identity': list(self.io.identity(info)), 'before_base64': base64.b64encode(raw).decode(),
                'stage': '.go-erp-env-' + os.urandom(16).hex(), 'uid': info.st_uid, 'gid': info.st_gid,
                'mode': stat.S_IMODE(info.st_mode), 'after_sha256': sha(after)}
            self._save(folder / 'pending.json', pending)
        need(pending['action_sha256'] == digest(action) and pending['after_sha256'] == sha(after), 'ENV_INTENT_CHANGED')
        staged = self._load(folder / 'staged.json')
        if staged is not None and self.io.inode(info) == staged['inode']:
            need(sha(raw) == pending['after_sha256'], 'ENV_TARGET_NOT_OWNED')
        else:
            need(list(self.io.identity(info)) == pending['before_identity'], 'ENV_EFFECT_UNKNOWN')
            parent = self.io.parent_fd(target)
            try:
                parent_info = os.fstat(parent)
                if staged is None:
                    # An existing unreceipted temporary file is never adopted.
                    # The owned original environment still proves a fresh write
                    # is safe when no stage was created before a process crash.
                    fd = os.open(pending['stage'], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                        pending['mode'], dir_fd=parent)
                    with os.fdopen(fd, 'wb') as stream:
                        stream.write(after);stream.flush()
                        os.fchown(stream.fileno(), pending['uid'], pending['gid']);os.fchmod(stream.fileno(), pending['mode'])
                        os.fsync(stream.fileno());staged = {'inode': self.io.inode(os.fstat(stream.fileno()))}
                    self._save(folder / 'staged.json', staged)
                else:
                    stage_raw, stage_info = self._read(target.with_name(pending['stage']))
                    need(sha(stage_raw) == pending['after_sha256'] and self.io.inode(stage_info) == staged['inode']
                        and (stage_info.st_uid, stage_info.st_gid, stat.S_IMODE(stage_info.st_mode))
                            == (pending['uid'], pending['gid'], pending['mode']), 'ENV_STAGE_NOT_OWNED')
                self.verify_locks();self.io.same_parent(target, parent_info)
                need(list(self.io.identity(self._read(target)[1])) == pending['before_identity'], 'ENV_PEER_CHANGED')
                os.replace(pending['stage'], target.name, src_dir_fd=parent, dst_dir_fd=parent);os.fsync(parent)
            finally:
                os.close(parent)
            raw, info = self._read(target)
            need(sha(raw) == pending['after_sha256'] and self.io.inode(info) == staged['inode'], 'ENV_WRITE_VERIFY_FAILED')
        return raw, info

    def _cache(self, action, folder, recovering):
        self._readiness(action)
        need(sha(self._env()[0]) == self.pins['binding']['env_enabled_sha256'], 'FLAG_NOT_ENABLED')
        mode = 'routes' if action['name'] == 'activation_route_cache' else 'config'
        if not recovering:
            self._save(folder / 'pending.json', {'action_sha256': digest(action), 'mode': mode})
        else:
            need(self._load(folder / 'pending.json') == {'action_sha256': digest(action), 'mode': mode}, 'CACHE_EFFECT_UNKNOWN')
        value, info, routes = self._inspect('inspect' if recovering else mode,
            verify_routes=mode == 'routes' or not self.pins['binding']['route_cache_required'])
        need(value['workspace_enabled'] is True, 'CACHE_FLAG_STALE')
        base = self._base(action) | {'source_manifest_sha256': self.pins['binding']['source_manifest_sha256']}
        if mode == 'config':
            result = base | {'cache_sha256': value['config_sha256'], 'workspace_enabled': True,
                'local': False, 'env_sha256': sha(self._env()[0]), 'owned_cache_verified': True}
        else:
            need(routes is not None, 'ROUTE_CACHE_MISSING')
            result = base | {'route_cache_sha256': sha(routes[0]), 'workspace_routes_verified': True}
        return self._finish(action, folder, result, {'cache_inode': self.io.inode(info),
            'route_cache_inode': None if routes is None else self.io.inode(routes[1])})

    def _reload(self, action, folder, recovering):
        self._readiness(action)
        before = self._handler()
        cache, _info, routes = self._inspect()
        p = action['parameters']
        need(cache['config_sha256'] == p['facts']['cache_sha256']
            and (None if routes is None else sha(routes[0])) == p['facts']['route_cache_sha256'], 'CACHE_CHANGED_BEFORE_RELOAD')
        self._drain(action, folder, before, recovering)
        self._handler()
        after, _info, after_routes = self._inspect()
        need(after == cache and (None if after_routes is None else sha(after_routes[0]))
            == (None if routes is None else sha(routes[0])), 'CACHE_CHANGED_DURING_RELOAD')
        result = self._base(action) | {'unit': FPM, 'active': True,
            'handler_before_sha256': digest(before['configuration']), 'handler_after_sha256': digest(before['configuration']),
            'reload_completed': True, 'cache_sha256': cache['config_sha256'],
            'route_cache_sha256': None if routes is None else sha(routes[0])}
        return self._finish(action, folder, result, {'old_workers': self._load(folder / 'reload-pending.json')['workers'],
            'old_workers_gone': True})

    def _drain(self, action, folder, before, recovering):
        pending = self._load(folder / 'reload-pending.json')
        if pending is None:
            need(not recovering, 'FPM_EFFECT_UNKNOWN')
            pending = {'action_sha256': digest(action), 'workers': self.processes.capture(before['live']['master_pid'])}
            self._save(folder / 'reload-pending.json', pending)
            result = self.runner.systemctl('reload')
            need(result['returncode'] == 0 and len(result['stdout']) + len(result['stderr']) <= 8192, 'FPM_RELOAD_FAILED')
            self._save(folder / 'reload-issued.json', {'action_sha256': digest(action), 'acknowledged': True})
        else:
            need(pending['action_sha256'] == digest(action), 'FPM_INTENT_CHANGED')
            need(self._load(folder / 'reload-issued.json') == {'action_sha256': digest(action), 'acknowledged': True},
                'FPM_EFFECT_UNKNOWN')
        deadline = self.clock() + 180
        while not self.processes.gone(pending['workers']):
            need(self.clock() < deadline, 'FPM_OLD_GENERATION_STILL_RUNNING')
            need(self.verify_locks() is not False, 'LOCK_CHANGED')
            self.pause(.25)
        return pending['workers']

    def preview_binding_env(self):
        """Read only hashes. No environment bytes leave this private implementation."""
        need(self.verify_locks() is not False, 'LOCK_CHANGED')
        raw, _info = self._read(ROOT / '.env')
        disabled = self.env_plan.workspace_env_bytes(raw, enabled=False)
        enabled = self.env_plan.workspace_env_bytes(raw, enabled=True)
        # A pre-existing explicit true value cannot be silently deactivated.
        need(not env_enabled(raw), 'PREEXISTING_ENABLED_FLAG_REQUIRES_REVIEW')
        return {'env_original_sha256': sha(raw), 'env_before_sha256': sha(disabled),
            'env_enabled_sha256': sha(enabled), 'env_unchanged_bytes_sha256': sha(disabled)}

    def _internal(self, name, parameters):
        need(isinstance(self.pins, dict), 'FINAL_PINS_UNAVAILABLE')
        return {'name': name, 'plan_sha256': self.pins['plan_sha256'], 'pins_sha256': self.pins['pins_sha256'],
            'code_sha256': self.pins['module_sha256'], 'identity': {'root': str(ROOT), 'uid': APP_UID, 'gid': APP_GID},
            'parameters': parameters, 'financial_data_updates_allowed': False, 'drop_tables_allowed': False,
            'allocation_allowed': False, 'artifact_replacement_allowed': False, 'automatic_updates_enabled': False}

    def prepare_disabled(self, *, recovering=False):
        """SourceTxn.after_apply: preserve flag false, rebuild only owned caches.

        The normalized false bytes become activation's env_before pin. The original
        secret bytes and inode remain in the root-private pending receipt for an
        owned rollback. No migration, application cache flush or route clearing.
        """
        action = self._internal('prepare_disabled', {})
        self._approved(action);self._source()
        folder = self._folder(action)
        done = self._load(folder / 'complete.json')
        if done is not None:
            raw, info = self._env()
            need(sha(raw) == self.pins['binding']['env_before_sha256']
                and self.io.inode(info) == done['witnesses']['env_inode'], 'PREPARATION_RECEIPT_CHANGED')
            cache, cache_info, routes = self._inspect();self._handler()
            need(cache['workspace_enabled'] is False and cache['config_sha256'] == done['result']['cache_sha256']
                and self.io.inode(cache_info) == done['witnesses']['cache_inode']
                and (None if routes is None else self.io.inode(routes[1])) == done['witnesses']['route_cache_inode'],
                'PREPARATION_CACHE_RECEIPT_CHANGED')
            return self._finish(action, folder, done['result'], done['witnesses'])
        raw, info = self.prepare_env_disabled(recovering=recovering)
        disabled = self.env_plan.workspace_env_bytes(raw, enabled=False)
        if not recovering:
            self._inspect('config', verify_routes=False)
            if self.pins['binding']['route_cache_required']:
                self._inspect('routes')
        cache, cache_info, routes = self._inspect()
        need(cache['workspace_enabled'] is False, 'PREPARATION_FLAG_NOT_FALSE')
        handler = self._handler()
        workers = self._drain(action, folder, handler, recovering)
        self._source();self._handler()
        need(self._inspect()[0] == cache, 'PREPARATION_CACHE_CHANGED')
        return self._finish(action, folder, {'workspace_enabled': False, 'automatic_updates_enabled': False,
            'current_env_sha256': sha(raw), 'env_unchanged_bytes_sha256': sha(disabled),
            'cache_sha256': cache['config_sha256'], 'route_cache_sha256': None if routes is None else sha(routes[0]),
            'handler_configuration_sha256': digest(handler['configuration']), 'old_generation_drained': True},
            {'env_inode': self.io.inode(info), 'cache_inode': self.io.inode(cache_info),
                'route_cache_inode': None if routes is None else self.io.inode(routes[1]), 'old_workers': workers})

    def prepare_env_disabled(self, *, recovering=False):
        """Own the false flag inode before publishing any new application class.

        This does not bootstrap Laravel or require target source to exist. The
        same durable intent is later completed by prepare_disabled after source
        publication, and supplies original bytes for owned source rollback.
        """
        action = self._internal('prepare_disabled', {})
        self._approved(action)
        folder = self._folder(action)
        raw, info = self._read(ROOT / '.env')
        disabled = self.env_plan.workspace_env_bytes(raw, enabled=False)
        need(sha(disabled) == self.pins['binding']['env_before_sha256']
            and not env_enabled(raw), 'PREPARATION_ENV_CHANGED')
        return self._replace_env(action, folder, raw, info, disabled, recovering)

    def addition_removal_barrier(self, pending, verify_referrers, *, recovering=False):
        """Restore owned .env bytes/cache, drain old FPM workers, then permit deletion.

        verify_referrers is the pinned SourceTxn's actual readback callback; it must
        return its current restored snapshot digest. It is never request supplied.
        """
        need(isinstance(self.pins, dict), 'FINAL_PINS_UNAVAILABLE')
        need(isinstance(pending, dict) and pending.get('kind') == 'go-erp-shared-workspace-addition-removal-intent'
            and pending.get('fpm_unit') == FPM and pending.get('drain_required') is True
            and pending.get('php_handler_configuration_sha256') == self.pins['binding']['php_handler_configuration_sha256']
            and callable(verify_referrers), 'ROLLBACK_BARRIER_INTENT_INVALID')
        action = self._internal('prepare_disabled', {'rollback_pending_sha256': digest(pending)})
        self._approved(action)
        need(verify_referrers() == pending['restored_referrers_sha256'], 'RESTORED_REFERRERS_CHANGED')
        folder = self._folder(action)
        done = self._load(folder / 'complete.json')
        if done is not None:
            self._handler()
            raw, info = self._read(ROOT / '.env')
            cache, cache_info, routes = self._inspect(verify_routes=False, allow_absent_workspace=True)
            need(verify_referrers() == pending['restored_referrers_sha256']
                and sha(raw) == done['witnesses']['restored_env_sha256']
                and self.io.inode(info) == done['witnesses']['env_inode']
                and cache['workspace_enabled'] is False
                and cache['config_sha256'] == done['witnesses']['cache_sha256']
                and self.io.inode(cache_info) == done['witnesses']['cache_inode']
                and (None if routes is None else self.io.inode(routes[1])) == done['witnesses']['route_cache_inode']
                and self.processes.gone(done['witnesses']['old_workers']), 'ROLLBACK_BARRIER_RECEIPT_CHANGED')
            return {**done['result'], 'private_receipt_sha256': digest(done)}
        preparation = self._folder(self._internal('prepare_disabled', {}))
        original = self._load(preparation / 'pending.json')
        prepared = self._load(preparation / 'staged.json')
        need(original is not None and prepared is not None, 'ORIGINAL_ENVIRONMENT_NOT_OWNED')
        restore = base64.b64decode(original['before_base64'], validate=True)
        need(not env_enabled(restore) and sha(self.env_plan.workspace_env_bytes(restore, enabled=False))
            == self.pins['binding']['env_before_sha256'], 'ORIGINAL_ENVIRONMENT_CHANGED')
        raw, info = self._read(ROOT / '.env')
        owned = self.io.inode(info) == prepared['inode']
        # An activated inode is accepted only through its own durable env receipt.
        if not owned:
            for index, child in enumerate(PRIVATE.iterdir()):
                need(index < 2048, 'PRIVATE_RECEIPT_INVENTORY_LIMIT')
                if not re.fullmatch(r'action-[a-f0-9]{64}', child.name):
                    continue
                result = self._load(child / 'complete.json')
                if result is not None and result.get('plan_sha256') == self.pins['plan_sha256'] \
                        and result.get('pins_sha256') == self.pins['pins_sha256'] \
                        and result.get('result', {}).get('flag') == FLAG \
                        and result.get('witnesses', {}).get('env_inode') == self.io.inode(info):
                    owned = True
        if not recovering:
            need(owned and sha(raw) in (self.pins['binding']['env_before_sha256'],
                self.pins['binding']['env_enabled_sha256']), 'ROLLBACK_ENVIRONMENT_NOT_OWNED')
        raw, info = self._replace_env(action, folder, raw, info, restore, recovering)
        if not recovering:
            self._inspect('config', verify_routes=False, allow_absent_workspace=True)
            if self.pins['binding']['route_cache_required']:
                self._inspect('routes', verify_routes=False, allow_absent_workspace=True)
        cache, cache_info, routes = self._inspect(verify_routes=False, allow_absent_workspace=True)
        need(cache['workspace_enabled'] is False, 'ROLLBACK_CACHE_FLAG_NOT_FALSE')
        before = self._handler()
        workers = self._drain(action, folder, before, recovering)
        self._handler()
        need(verify_referrers() == pending['restored_referrers_sha256']
            and self.io.identity(self._read(ROOT / '.env')[1]) == self.io.identity(info)
            and self._inspect(verify_routes=False, allow_absent_workspace=True)[0] == cache,
            'ROLLBACK_BARRIER_CHANGED')
        proof = {'format': 1, 'kind': 'go-erp-shared-workspace-addition-removal-approved',
            'pending_sha256': digest(pending), 'php_handler_configuration_sha256': digest(before['configuration']),
            'fpm_unit': FPM, 'old_generation_captured_before_reload': True, 'drained_before_addition_removal': True,
            'reload_completed': True, 'no_old_generation_requests': True,
            'restored_referrers_sha256': pending['restored_referrers_sha256'], 'compiled_referrers_restored': True,
            'route_cache_policy_verified': True, 'workspace_enabled': False}
        receipt = self._finish(action, folder, proof, {'env_inode': self.io.inode(info),
            'restored_env_sha256': sha(raw), 'cache_sha256': cache['config_sha256'], 'cache_inode': self.io.inode(cache_info),
            'route_cache_inode': None if routes is None else self.io.inode(routes[1]), 'old_workers': workers})
        return {**proof, 'private_receipt_sha256': receipt['private_receipt_sha256']}

    def invoke(self, action):
        return self._perform(action, False)

    def recover(self, action):
        try:
            return {'status': 'complete', 'receipt': self._perform(action, True)}
        except Stop:
            return {'status': 'unknown', 'receipt': None}

    def _perform(self, action, recovering):
        self._approved(action);self._source()
        folder = self._folder(action)
        complete = self._load(folder / 'complete.json')
        if complete is not None:
            need(complete['action_sha256'] == digest(action), 'RECEIPT_ACTION_CHANGED')
            # The environment and source must still belong to the pinned release.
            raw, info = self._env()
            witnesses = complete['witnesses']
            if 'env_inode' in witnesses:
                need(self.io.inode(info) == witnesses['env_inode'], 'ENV_RECEIPT_INODE_CHANGED')
            if 'cache_inode' in witnesses:
                cache, cache_info, routes = self._inspect()
                need(self.io.inode(cache_info) == witnesses['cache_inode']
                    and (None if routes is None else self.io.inode(routes[1])) == witnesses['route_cache_inode'],
                    'CACHE_RECEIPT_INODE_CHANGED')
            if 'reload_completed' in complete['result']:
                cache, _cache_info, routes = self._inspect();self._handler()
                need(cache['config_sha256'] == complete['result']['cache_sha256']
                    and (None if routes is None else sha(routes[0])) == complete['result']['route_cache_sha256']
                    and self.processes.gone(witnesses['old_workers']), 'FPM_RECEIPT_CHANGED')
            return self._finish(action, folder, complete['result'], complete['witnesses'])
        name = action['name']
        if name == 'activation_flag':
            return self._flag(action, folder, recovering)
        if name in ('activation_cache', 'activation_route_cache'):
            return self._cache(action, folder, recovering)
        if name == 'activation_fpm':
            return self._reload(action, folder, recovering)
        if name == 'verify_handler':
            value = self._handler()
            return self._finish(action, folder, self._base(action) | {
                'configuration_sha256': digest(value['configuration'])}, {'master_pid': value['live']['master_pid']})
        return self._https(action, folder, recovering)

    def _https(self, action, folder, recovering):
        # The witness is installed only beneath the attested documentroot, never
        # from a request path, and requires an unpredictable header challenge.
        handler = self._handler()
        documentroot = Path(handler['configuration']['documentroot'])
        need(documentroot in (ROOT, ROOT / 'public'), 'DOCUMENTROOT_CHANGED')
        raw, env_info = self._env()
        enabled = action['name'] == 'activation_https'
        need(sha(raw) == self.pins['binding']['env_enabled_sha256' if enabled else 'env_before_sha256'], 'HTTPS_FLAG_CHANGED')
        cache, _info, routes = self._inspect()
        need(cache['workspace_enabled'] is enabled, 'HTTPS_CACHE_FLAG_CHANGED')
        pending = self._load(folder / 'pending.json')
        if pending is None:
            need(not recovering, 'HTTPS_EFFECT_UNKNOWN')
            pending = {'action_sha256': digest(action), 'name': 'go-erp-readiness-' + os.urandom(16).hex() + '.php',
                'token': os.urandom(32).hex(), 'nonce': os.urandom(32).hex()}
            self._save(folder / 'pending.json', pending)
        need(pending['action_sha256'] == digest(action), 'HTTPS_INTENT_CHANGED')
        program = ("<?php\nif(!hash_equals('" + sha(pending['token'].encode())
            + "',hash('sha256',(string)($_SERVER['HTTP_X_GO_ERP_READINESS']??'')))){http_response_code(404);exit;}\n"
            + "require '" + str(ROOT) + "/vendor/autoload.php';$app=require '" + str(ROOT)
            + "/bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            + "$p=$app->getCachedConfigPath();$v=['nonce'=>'" + pending['nonce']
            + "','workspace_enabled'=>config('desktop_dashboard.workspace_enabled'),'local'=>config('desktop_dashboard.local'),"
            + "'cache_sha256'=>hash_file('sha256',$p)];header('Content-Type: application/json');"
            + "header('Cache-Control: no-store');echo json_encode($v,JSON_THROW_ON_ERROR);").encode()
        path = documentroot / pending['name']
        owned = self._load(folder / 'probe.json')
        parent = self.io.parent_fd(path)
        try:
            if owned is None:
                need(not recovering, 'HTTPS_PROBE_NOT_OWNED')
                fd = os.open(path.name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640, dir_fd=parent)
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(program);stream.flush();os.fchown(stream.fileno(), APP_UID, APP_GID)
                    os.fchmod(stream.fileno(), 0o640);os.fsync(stream.fileno())
                    owned = {'inode': self.io.inode(os.fstat(stream.fileno())), 'sha256': sha(program)}
                os.fsync(parent);self._save(folder / 'probe.json', owned)
            current = self._read(path)
            need(sha(current[0]) == owned['sha256'] == sha(program)
                and self.io.inode(current[1]) == owned['inode'], 'HTTPS_PROBE_CHANGED')
            response = self.https.get('/' + path.name, pending['token'])
            need(response == {'nonce': pending['nonce'], 'workspace_enabled': enabled,
                'local': False, 'cache_sha256': cache['config_sha256']}, 'HTTPS_SERVED_CONFIGURATION_CHANGED')
            self._source();self._handler()
            need(self.io.identity(self._env()[1]) == self.io.identity(env_info), 'ENV_CHANGED_DURING_HTTPS')
            binding = self.pins['binding']
            if enabled:
                result = self._base(action) | {'origin': ORIGIN, 'workspace_enabled': True, 'local': False,
                    'source_manifest_sha256': binding['source_manifest_sha256'], 'cache_sha256': cache['config_sha256'],
                    'php_handler_configuration_sha256': binding['php_handler_configuration_sha256'], 'served_configuration_verified': True}
            else:
                result = {'origin': ORIGIN, 'source_manifest_sha256': binding['source_manifest_sha256'],
                    'handler_configuration_sha256': binding['php_handler_configuration_sha256'], 'healthy': True,
                    'served_configuration_verified': True, 'current_env_sha256': sha(raw),
                    'env_unchanged_bytes_sha256': binding['env_unchanged_bytes_sha256'], 'owned_env_inode_verified': True,
                    'workspace_enabled': False, 'automatic_updates_enabled': False}
            receipt = self._finish(action, folder, result, {'env_inode': self.io.inode(env_info),
                'cache_sha256': cache['config_sha256'], 'probe_inode': owned['inode']})
            if not enabled:
                self._save(PRIVATE / ('readiness-' + receipt['private_receipt_sha256'] + '.json'),
                    self._load(folder / 'complete.json'))
            return receipt
        finally:
            try:
                if owned is not None:
                    current = self._read(path, optional=True)
                    if current is not None:
                        need(self.io.inode(current[1]) == owned['inode'] and sha(current[0]) == owned['sha256'],
                            'HTTPS_PROBE_CLEANUP_NOT_OWNED')
                        os.unlink(path.name, dir_fd=parent);os.fsync(parent)
            finally:
                os.close(parent)


if __name__ == '__main__':
    raise SystemExit('GO_ERP_SHARED_WORKSPACE_ACTIVATION_FINAL_PINS_UNAVAILABLE')
