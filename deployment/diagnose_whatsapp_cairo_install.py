#!/usr/bin/env python3
"""Check the verified Cairo publisher without publishing or controlling workers."""
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import shutil
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
APP_BLOB = 'bf4111db00dbc042c1aa2b23bdb1eb739240e261'
MANIFEST_BLOB = '5e51cdfd8b922cb00de84df0c33804eb85a7bc22'
READINESS_CHECKS = (
    'CACHED_CONFIG_PATH', 'MODE', 'PARENT_SCHEMA', 'REGISTRY_SIZE',
    'REQUIRED_COLUMNS', 'REGISTRY_ROW_COUNT', 'REGISTERED_NAME',
    'REGISTERED_PARENT', 'ORIGIN_PIN', 'RATE_FORMAT',
    'SOURCE_CLASS_PATH', 'TARGET_ORIGIN_CONTRACT', 'TARGET_REGISTERED_ORIGIN',
    'TARGET_UNKNOWN_ORIGIN', 'TARGET_SOURCE_HOOK', 'TARGET_CONTROLLER_PATH',
)
SAFE_REASONS = frozenset((
    'UNSAFE_PATH', 'ANCESTOR_NOT_DIRECTORY_OR_SYMLINK', 'ANCESTOR_OWNER_NEEDS_REVIEW',
    'REQUIRED_FILE_MISSING', 'FILE_NOT_READABLE_OR_SYMLINK', 'FILE_OWNER_OR_TYPE_NEEDS_REVIEW',
    'FILE_TOO_LARGE', 'FILE_CHANGED_DURING_READ', 'PROJECT_ROOT_OWNER_OR_TYPE_NEEDS_REVIEW',
    'GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW', 'CAIRO_REQUIRED_FILE_MISSING',
    'CAIRO_PAYLOAD_OWNER_UNSAFE', 'CAIRO_FILE_MODE_OR_PAYLOAD_LINK_UNSAFE',
    'CAIRO_PAYLOAD_TYPE_OR_SIZE_UNSAFE', 'CAIRO_PAYLOAD_CHANGED_DURING_READ',
    'CAIRO_FILE_CHANGED_DURING_READ', 'CAIRO_MANIFEST_ARGUMENT_INVALID',
    'CAIRO_MANIFEST_HASH_MISMATCH', 'CAIRO_MANIFEST_SCOPE_INVALID',
    'ATOMIC_RENAME_UNAVAILABLE', 'ATOMIC_RENAME_FAILED', 'ATOMIC_EXCHANGE_CHECK_FAILED',
))


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def load_app():
    path = pathlib.Path(__file__).absolute().with_name('apply_whatsapp_cairo_origins.py')
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 1048576):
            raise RuntimeError()
        data = os.read(fd, 1048577)
        after = os.fstat(fd)
        key = lambda x: (x.st_dev, x.st_ino, x.st_uid, x.st_gid, x.st_mode,
                         x.st_nlink, x.st_size, x.st_mtime_ns, x.st_ctime_ns)
        if key(info) != key(after) or blob(data) != APP_BLOB:
            raise RuntimeError()
    finally:
        os.close(fd)
    name = 'verified_cairo_diagnostic_app'
    spec = importlib.util.spec_from_loader(name, loader=None)
    app = importlib.util.module_from_spec(spec)
    app.__file__ = str(path); sys.modules[name] = app
    exec(compile(data, str(path), 'exec'), app.__dict__)
    return app


def readiness_php(app):
    php = app.READINESS_PHP
    if php.count('exit(1);') != len(READINESS_CHECKS) + 1:
        raise RuntimeError()
    ids = ','.join(str(value) for value in app.CAIRO_ORIGIN_NAMES)
    header = r'''
$diagStage='BOOTSTRAP';
$failed=static function($reason,$origin=null)use(&$diagStage){
 while(ob_get_level()>0)ob_end_clean();
 echo json_encode(['status'=>'FAILED','stage'=>$diagStage,'reason'=>$reason,
 'origin_id'=>is_int($origin)&&in_array($origin,[__IDS__],true)?$origin:null],JSON_THROW_ON_ERROR);
 exit(1);
};
'''.replace('__IDS__', ids)
    needle = "ob_start();\ntry {"
    if php.count(needle) != 1:
        raise RuntimeError()
    # Patch the frozen source before adding the callback's own exit statement.
    for reason in READINESS_CHECKS:
        argument = ',$id' if reason in ('REGISTERED_NAME', 'REGISTERED_PARENT', 'ORIGIN_PIN', 'RATE_FORMAT') else ''
        php = php.replace('exit(1);', "$failed('" + reason + "'" + argument + ');', 1)
    php = php.replace('exit(1);', "$failed('READINESS_EXCEPTION');", 1)
    php = php.replace(needle, 'ob_start();\n' + header + 'try {', 1)
    for old, new in (
        ("    if (!\\Illuminate\\Support\\Facades\\Schema::hasColumn", "    $diagStage='SCHEMA';\n    if (!\\Illuminate\\Support\\Facades\\Schema::hasColumn"),
        ("    $rows = ", "    $diagStage='REGISTRY';\n    $rows = "),
        ("    foreach (['PhoneDelivery'", "    $diagStage='CLASS_LOAD';\n    foreach (['PhoneDelivery'"),
    ):
        if php.count(old) != 1:
            raise RuntimeError()
        php = php.replace(old, new, 1)
    return php


def diagnostic(root, uid, manifest, app, runner=None):
    runner = runner or app.base.command
    results = []

    def record(stage, callback, relative=None):
        try:
            extra = callback() or {}
            result = {'stage': stage, 'status': 'READY', **extra}
        except app.SafeError as error:
            value = str(error)
            result = {'stage': stage, 'status': 'FAILED', 'reason': value if value in SAFE_REASONS else 'VERIFIED_GUARD_FAILED'}
        except BaseException as error:
            name = type(error).__name__
            result = {'stage': stage, 'status': 'FAILED', 'reason': 'EXCEPTION',
                      'exception_class': name if re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}', name) else 'UNKNOWN'}
        if relative is not None:
            result['path'] = relative
        results.append(result)
        return result['status'] == 'READY'

    if not record('ROOT', lambda: app.base.validate_root(root, uid)):
        return results
    payload_holder = []
    def checked_manifest():
        payload_holder.append(app.load_manifest(manifest, MANIFEST_BLOB, uid))
    if not record('MANIFEST', checked_manifest):
        return results
    payload = payload_holder[0]

    def backup_parent():
        path = root.parent / 'whatsapp-release-backups'
        app.base.safe_ancestors(path, uid)
        try:
            item = path.lstat()
        except FileNotFoundError:
            return {'exists': False}
        if not stat.S_ISDIR(item.st_mode) or item.st_uid != uid or stat.S_IMODE(item.st_mode) != 0o700:
            return {'status': 'FAILED', 'reason': 'PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW'}
        if item.st_dev != root.stat().st_dev:
            return {'status': 'FAILED', 'reason': 'CAIRO_BACKUP_REQUIRES_SAME_FILESYSTEM', 'same_filesystem_as_app': False}
        return {'exists': True, 'same_filesystem_as_app': True}
    parent_ready = record('BACKUP_PARENT', backup_parent)
    for name in ('orders-install.lock', 'inbox-install.lock'):
        if not parent_ready:
            results.append({'stage': 'LOCK', 'status': 'SKIPPED', 'reason': 'BACKUP_PARENT_NOT_READY', 'path': name})
            continue
        def checked_lock(name=name):
            path = root.parent / 'whatsapp-release-backups' / name
            try:
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            except FileNotFoundError:
                return {'exists': False}
            try:
                item = os.fstat(fd)
                if (not stat.S_ISREG(item.st_mode) or item.st_uid != uid or item.st_nlink != 1
                        or stat.S_IMODE(item.st_mode) != 0o600):
                    return {'status': 'FAILED', 'reason': 'CAIRO_INSTALL_LOCK_UNSAFE'}
                try:
                    fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
                except BlockingIOError:
                    return {'status': 'FAILED', 'reason': 'CAIRO_ANOTHER_INSTALL_OR_CONFIGURATION_RUNNING'}
                return {'exists': True}
            finally:
                os.close(fd)
        record('LOCK', checked_lock, name)

    profile_ready = True
    for relative in dict.fromkeys((*app.PROTECTED_PATHS, *app.PROTECTED_BLOBS)):
        def checked_protected(relative=relative):
            item, _ = app.guarded_snapshot(root / relative, uid, root,
                absent_ok=relative not in app.PROTECTED_BLOBS,
                legacy_read_only=relative in app.LEGACY_READ_ONLY_MODELS)
            if relative in app.PROTECTED_BLOBS and (item is None or app.blob(item.content) != app.PROTECTED_BLOBS[relative]):
                return {'status': 'FAILED', 'reason': 'PROTECTED_HASH_MISMATCH'}
        profile_ready = record('PROTECTED_PROFILE', checked_protected, relative) and profile_ready
    candidates = {}
    for relative, (old, new) in app.SOURCE_PINS.items():
        def checked_source(relative=relative, old=old, new=new):
            candidate = app.payload_bytes(payload / relative, uid, payload)
            item, _ = app.guarded_snapshot(root / relative, uid, root, absent_ok=old is None)
            if app.blob(candidate) != new or (item is not None and app.blob(item.content) not in (old, new)) or (item is None and old is not None):
                return {'status': 'FAILED', 'reason': 'RUNTIME_HASH_MISMATCH'}
            candidates[relative] = candidate
        profile_ready = record('SOURCE_PROFILE', checked_source, relative) and profile_ready
    if profile_ready:
        def checked_readiness():
            code, output = runner(root, [app.PHP, '-r', readiness_php(app), 'source'], capture=True)
            if len(output) > 4096:
                raise RuntimeError()
            value = json.loads(output)
            if code == 0 and isinstance(value, dict) and set(value) == {'mode', 'config_fingerprint'} and value['mode'] in ('review', 'auto') and re.fullmatch('[a-f0-9]{64}', value['config_fingerprint']):
                return {'mode': value['mode']}
            if (isinstance(value, dict) and set(value) == {'status', 'stage', 'reason', 'origin_id'}
                    and value['status'] == 'FAILED' and value['stage'] in ('BOOTSTRAP', 'SCHEMA', 'REGISTRY', 'CLASS_LOAD')
                    and value['reason'] in (*READINESS_CHECKS, 'READINESS_EXCEPTION')
                    and (value['origin_id'] is None or type(value['origin_id']) is int and value['origin_id'] in app.CAIRO_ORIGIN_NAMES)):
                return {'status': 'FAILED', 'reason': value['reason'], 'readiness_stage': value['stage'], 'origin_id': value['origin_id']}
            raise RuntimeError()
        record('SOURCE_READINESS', checked_readiness)

    for name in app.QUIET_UNITS:
        def observed_worker(name=name):
            args = ['/usr/bin/systemctl', 'show', '--no-pager', '--property=LoadState', '--property=ActiveState']
            if name.endswith('.service'):
                args.append('--property=MainPID')
            code, output = runner(root, args + [name], capture=True)
            if code not in (0, 1) or len(output) > 1024:
                raise RuntimeError()
            fields = dict(line.split('=', 1) for line in output.decode('ascii').strip().splitlines())
            if not set(fields) <= {'LoadState', 'ActiveState', 'MainPID'} or fields.get('LoadState') not in ('loaded', 'not-found') or fields.get('ActiveState') not in ('active', 'inactive', 'failed', 'activating', 'deactivating'):
                raise RuntimeError()
            if 'MainPID' in fields and not re.fullmatch('[0-9]{1,12}', fields['MainPID']):
                raise RuntimeError()
            return {'load_state': fields['LoadState'], 'active_state': fields['ActiveState'],
                    'main_pid_zero': fields.get('MainPID') == '0' if 'MainPID' in fields else None}
        record('WORKER_OBSERVATION', observed_worker, name)

    if len(candidates) == len(app.SOURCE_PINS):
        with tempfile.TemporaryDirectory(prefix='.wa-cairo-diagnostic-', dir=root.parent) as folder:
            temporary = pathlib.Path(folder); temporary.chmod(0o700)
            same_device = temporary.stat().st_dev == root.stat().st_dev
            record('TEMPORARY_SAME_FILESYSTEM', lambda: {'same_filesystem_as_app': same_device, **({} if same_device else {'status': 'FAILED', 'reason': 'CAIRO_BACKUP_REQUIRES_SAME_FILESYSTEM'})})
            if same_device:
                record('PRIVATE_ATOMIC_PROBE', lambda: app.base.probe_atomic_exchange(temporary))
            else:
                results.append({'stage': 'PRIVATE_ATOMIC_PROBE', 'status': 'SKIPPED', 'reason': 'TEMPORARY_FILESYSTEM_DIFFERS'})
            for index, (relative, candidate) in enumerate(candidates.items()):
                def lint(relative=relative, candidate=candidate, index=index):
                    path = temporary / ('candidate-' + str(index) + pathlib.Path(relative).suffix)
                    app.private_write(path, candidate)
                    node = shutil.which('node') if relative.endswith('.js') else None
                    if relative.endswith('.js') and not node:
                        return {'status': 'SKIPPED', 'reason': 'NODE_UNAVAILABLE'}
                    args = [node, '--check', str(path)] if node else [app.PHP, '-l', str(path)]
                    code, _ = runner(root, args)
                    return {} if code == 0 else {'status': 'FAILED', 'reason': 'CANDIDATE_LINT_FAILED'}
                record('CANDIDATE_LINT', lint, relative)
    return results


def compact_checks(checks):
    groups = ('PROTECTED_PROFILE', 'SOURCE_PROFILE', 'CANDIDATE_LINT')
    retained = [check for check in checks if check['stage'] not in groups or check['status'] == 'FAILED']
    for stage in groups:
        values = [check for check in checks if check['stage'] == stage]
        if values:
            retained.append({'stage': stage + '_SUMMARY', 'status': 'FAILED' if any(value['status'] == 'FAILED' for value in values) else 'READY',
                'checks': len(values), 'ready': sum(value['status'] == 'READY' for value in values),
                'failed': sum(value['status'] == 'FAILED' for value in values), 'skipped': sum(value['status'] == 'SKIPPED' for value in values)})
    return retained[:42], len(retained) > 42


def main():
    stage = 'ARGUMENTS'
    try:
        if (sys.argv[1:3] != ['--root', str(APP_ROOT)] or len(sys.argv) != 5
                or sys.argv[3] != '--manifest' or os.geteuid() != pwd.getpwnam('fasakha').pw_uid):
            raise RuntimeError()
        os.umask(0o077); stage = 'VERIFIED_APP_LOAD'; app = load_app()
        stage = 'READ_ONLY_CHECKS'
        checks = diagnostic(APP_ROOT, os.geteuid(), pathlib.Path(sys.argv[4]), app)
        displayed, truncated = compact_checks(checks)
        print(json.dumps({'diagnostic': 'CAIRO_INSTALL_READ_ONLY', 'configuration_writes': 0,
            'runtime_writes': 0, 'worker_actions': 0, 'network_requests': 0,
            'ai_calls': 0, 'orders_created': 0, 'temporary_probe': any(check['stage'] == 'PRIVATE_ATOMIC_PROBE' and check['status'] != 'SKIPPED' for check in checks),
            'temporary_files_only': True, 'checks_total': len(checks), 'checks_truncated': truncated,
            'checks': displayed}, sort_keys=True))
        return 0
    except BaseException as error:
        name = type(error).__name__
        print(json.dumps({'diagnostic': 'CAIRO_INSTALL_READ_ONLY', 'status': 'FAILED', 'stage': stage,
            'exception_class': name if re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}', name) else 'UNKNOWN'}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
