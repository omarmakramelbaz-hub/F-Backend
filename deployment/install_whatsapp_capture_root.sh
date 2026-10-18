#!/usr/bin/env bash
# Project encrypted captured WhatsApp events only. Never analyze or dispatch orders.
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
import ctypes
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import sys
import tempfile

ROOT = pathlib.Path('/home/fasakha/public_html')
SYSTEM_UID = 0
UNIT_DIRECTORY = pathlib.Path('/etc/systemd/system')
BACKUP_DIRECTORY = pathlib.Path('/var/lib/fasakhansta-whatsapp-systemd-backups')
PHP = pathlib.Path('/opt/cpanel/ea-php82/root/usr/bin/php')
SERVICE = 'fasakhansta-whatsapp-capture.service'
TIMER = 'fasakhansta-whatsapp-capture.timer'
ORDER_SERVICE = 'fasakhansta-whatsapp-orders.service'
ORDER_TIMER = 'fasakhansta-whatsapp-orders.timer'
SOURCE_PINS = {
    'app/Services/Dashboard/WhatsAppInboxConsumer.php': 'f993ff1eebc9c2a7be3b0bec93ab02732b96a46d',
    'app/Console/Commands/ConsumeWhatsAppInbox.php': 'dbd621a5117f2e92fd2f80d004305fcbcc467d4a',
}
SERVICE_BYTES = b'''[Unit]
Description=Project captured WhatsApp messages into the encrypted dashboard inbox
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=fasakha
Group=fasakha
UMask=0077
WorkingDirectory=/home/fasakha/public_html
RuntimeDirectory=fasakhansta-whatsapp-orders
RuntimeDirectoryMode=0700
RuntimeDirectoryPreserve=yes
ExecStartPre=/opt/cpanel/ea-php82/root/usr/bin/php -d display_errors=0 -d log_errors=0 -r "if (is_link('app/Services/Dashboard/WhatsAppInboxConsumer.php') || is_link('app/Console/Commands/ConsumeWhatsAppInbox.php') || hash_file('sha256', 'app/Services/Dashboard/WhatsAppInboxConsumer.php') !== '80253a13accf7a712130d99d73322e9d01ca73cd6f83c68db6cf5f0cd282948c' || hash_file('sha256', 'app/Console/Commands/ConsumeWhatsAppInbox.php') !== 'b942e8830acfc3fb9d291c1d953f0a80a6b4132027a945fb6656d3e2f589c9f6') exit(1);"
ExecStart=/usr/bin/flock --nonblock --conflict-exit-code=0 /run/fasakhansta-whatsapp-orders/pipeline.lock /opt/cpanel/ea-php82/root/usr/bin/php -d display_errors=0 -d log_errors=0 artisan whatsapp:consume-inbox --limit=500
SuccessExitStatus=2
TimeoutStartSec=600
KillMode=control-group
NoNewPrivileges=yes
PrivateTmp=yes
Nice=10
StandardOutput=journal
StandardError=journal
'''
TIMER_BYTES = b'''[Unit]
Description=Keep captured WhatsApp messages visible without order analysis

[Timer]
OnBootSec=5s
OnUnitInactiveSec=10s
AccuracySec=1s
RandomizedDelaySec=0
Unit=fasakhansta-whatsapp-capture.service

[Install]
WantedBy=timers.target
'''
UNITS = {SERVICE: SERVICE_BYTES, TIMER: TIMER_BYTES}
PREFLIGHT_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if ($app->getCachedConfigPath() !== getcwd() . '/bootstrap/cache/config.php'
        || config('whatsapp_orders.mode') !== 'review'
        || !array_key_exists('whatsapp:consume-inbox', $kernel->all())
        || (new \ReflectionClass(\App\Services\Dashboard\WhatsAppInboxConsumer::class))
            ->getConstant('QUARANTINE_BATCH_RECHECK') !== true) exit(1);
    foreach (['whatsapp_webhook_events', 'whatsapp_inbox_messages', 'whatsapp_inbox_conversations',
        'whatsapp_inbox_ingestion_failures'] as $table) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) exit(1);
    }
    echo '{"capture_ready":true}';
} catch (\Throwable $error) { exit(1); }
'''


class SafeError(Exception):
    pass


def command(arguments, capture=False):
    try:
        result = subprocess.run(arguments, cwd=ROOT, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=40, check=False)
        output = result.stdout if capture else b''
        return result.returncode, output if len(output) <= 65536 else b''
    except (OSError, subprocess.TimeoutExpired):
        return 1, b''


def system_path(path, directory=False):
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != SYSTEM_UID or stat.S_IMODE(info.st_mode) & 0o022:
            raise SafeError('SYSTEMD_PARENT_OWNER_OR_TYPE_NEEDS_REVIEW')
    info = path.lstat()
    if (info.st_uid != SYSTEM_UID or stat.S_IMODE(info.st_mode) & 0o022
            or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
        raise SafeError('SYSTEMD_PATH_OWNER_OR_TYPE_NEEDS_REVIEW')
    return info


def existing_unit(path):
    system_path(path.parent, True)
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return None
    except OSError:
        raise SafeError('SYSTEMD_UNIT_PATH_UNSAFE') from None
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != SYSTEM_UID or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) != 0o644 or info.st_size > 16384):
            raise SafeError('SYSTEMD_UNIT_OWNER_OR_TYPE_NEEDS_REVIEW')
        content = os.read(descriptor, 16385)
        after = os.fstat(descriptor)
        if (info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (
                after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise SafeError('SYSTEMD_UNIT_CHANGED_DURING_READ')
        return content, (info.st_dev, info.st_ino, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode))
    finally:
        os.close(descriptor)


def check_units():
    for name, content in UNITS.items():
        current = existing_unit(UNIT_DIRECTORY / name)
        if current is None or current[0] != content:
            raise SafeError('SYSTEMD_INSTALLED_UNIT_NEEDS_REVIEW')


def rename_no_replace(source, destination):
    library = ctypes.CDLL(None, use_errno=True)
    operation = library.renameat2
    operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    operation.restype = ctypes.c_int
    if operation(-100, os.fsencode(source), -100, os.fsencode(destination), 1) != 0:
        raise SafeError('SYSTEMD_ATOMIC_PUBLICATION_FAILED')


def write_private(path, content, mode=0o600):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(descriptor, 'wb') as stream:
        os.fchmod(stream.fileno(), mode)
        stream.write(content); stream.flush(); os.fsync(stream.fileno())


def install(runner=command):
    current = {name: existing_unit(UNIT_DIRECTORY / name) for name in UNITS}
    if any(value is not None and value[0] != UNITS[name] for name, value in current.items()):
        raise SafeError('SYSTEMD_EXISTING_UNRELATED_UNIT_RETAINED')
    if all(value is not None for value in current.values()):
        check_units()
        return 'WHATSAPP_CAPTURE_TIMER_ALREADY_INSTALLED', None
    try:
        BACKUP_DIRECTORY.mkdir(mode=0o700)
    except FileExistsError:
        pass
    system_path(BACKUP_DIRECTORY, True)
    if stat.S_IMODE(BACKUP_DIRECTORY.stat().st_mode) != 0o700:
        raise SafeError('SYSTEMD_PRIVATE_BACKUP_NEEDS_REVIEW')
    backup = pathlib.Path(tempfile.mkdtemp(prefix='capture-', dir=BACKUP_DIRECTORY)); backup.chmod(0o700)
    if backup.stat().st_dev != UNIT_DIRECTORY.stat().st_dev:
        raise SafeError('SYSTEMD_BACKUP_REQUIRES_SAME_FILESYSTEM')
    for name, content in UNITS.items():
        write_private(backup / name, content, 0o644)
    code, _ = runner(['/usr/bin/systemd-analyze', 'verify', str(backup / SERVICE), str(backup / TIMER)])
    if code != 0:
        raise SafeError('SYSTEMD_UNIT_VALIDATION_FAILED')
    published = {}
    try:
        for name, content in UNITS.items():
            path = UNIT_DIRECTORY / name
            if existing_unit(path) != current[name]:
                raise SafeError('SYSTEMD_UNIT_CHANGED_CONCURRENTLY')
            if current[name] is not None:
                continue
            prepared = existing_unit(backup / name)
            published[name] = prepared
            rename_no_replace(backup / name, path)
            if existing_unit(path) != prepared:
                raise SafeError('SYSTEMD_UNIT_CHANGED_CONCURRENTLY')
        code, _ = runner(['/usr/bin/systemctl', 'daemon-reload'])
        if code != 0:
            raise SafeError('SYSTEMD_DAEMON_RELOAD_FAILED')
        check_units()
    except BaseException:
        restored = True
        for name, expected in reversed(list(published.items())):
            path = UNIT_DIRECTORY / name
            try:
                observed = existing_unit(path)
                if observed is None:
                    continue
                if observed != expected:
                    restored = False; continue
                rename_no_replace(path, backup / ('retained-' + name))
            except BaseException:
                restored = False
        runner(['/usr/bin/systemctl', 'daemon-reload'])
        raise SafeError(('SYSTEMD_NEW_UNITS_RESTORED' if restored else
                         'SYSTEMD_ROLLBACK_STOPPED_CONCURRENT_UNIT_RETAINED') + '; BACKUP=' + str(backup)) from None
    return 'WHATSAPP_CAPTURE_TIMER_INSTALLED_NOT_STARTED', backup


def check_source_pins():
    allowed_uids = {SYSTEM_UID, pwd.getpwnam('fasakha').pw_uid}
    for relative, expected in SOURCE_PINS.items():
        path = ROOT / relative
        for parent in (ROOT, *path.parents):
            if parent == ROOT.parent:
                break
            if ROOT != parent and ROOT not in parent.parents:
                continue
            info = parent.lstat()
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid not in allowed_uids
                    or stat.S_IMODE(info.st_mode) & 0o022):
                raise SafeError('CAPTURE_SOURCE_PARENT_OWNER_OR_TYPE_NEEDS_REVIEW')
        try:
            descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        except OSError:
            raise SafeError('CAPTURE_SOURCE_PATH_UNSAFE') from None
        try:
            info = os.fstat(descriptor)
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in allowed_uids
                    or stat.S_IMODE(info.st_mode) & 0o022 or info.st_size > 1048576):
                raise SafeError('CAPTURE_SOURCE_OWNER_OR_TYPE_NEEDS_REVIEW')
            content = os.read(descriptor, 1048577)
            after = os.fstat(descriptor)
            if (info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (
                    after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise SafeError('CAPTURE_SOURCE_CHANGED_DURING_READ')
            actual = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
            if actual != expected:
                raise SafeError('CAPTURE_SOURCE_PIN_NEEDS_REVIEW')
        finally:
            os.close(descriptor)


def unit_state(name, runner):
    code, output = runner(['/usr/bin/systemctl', 'show', name, '--property=LoadState',
                           '--property=ActiveState', '--property=UnitFileState'], capture=True)
    try:
        properties = dict(line.split('=', 1) for line in output.decode('ascii').splitlines())
    except (UnicodeError, ValueError):
        properties = {}
    if code != 0 or set(properties) != {'LoadState', 'ActiveState', 'UnitFileState'}:
        raise SafeError('CAPTURE_SYSTEMD_STATE_CHECK_FAILED')
    return properties


def orders_stopped(runner):
    for name in (ORDER_TIMER, ORDER_SERVICE):
        properties = unit_state(name, runner)
        if (properties['LoadState'] != 'loaded'
                or properties['ActiveState'] not in ('inactive', 'failed')):
            raise SafeError('WHATSAPP_ORDER_WORKER_MUST_BE_STOPPED')
        if name == ORDER_TIMER and properties['UnitFileState'] not in ('disabled', 'masked'):
            raise SafeError('WHATSAPP_ORDER_TIMER_MUST_BE_DISABLED')


def start(runner=command):
    check_units()
    check_source_pins()
    orders_stopped(runner)
    code, output = runner(['/usr/sbin/runuser', '-u', 'fasakha', '--', str(PHP), '-d', 'display_errors=0',
                           '-d', 'log_errors=0', '-r', PREFLIGHT_PHP], capture=True)
    try:
        state = json.loads(output) if code == 0 else None
    except (TypeError, ValueError):
        state = None
    if state != {'capture_ready': True}:
        raise SafeError('CAPTURE_REVIEW_CONFIGURATION_NOT_READY')
    # Recheck immediately before enabling this projection-only timer.
    orders_stopped(runner)
    try:
        code, _ = runner(['/usr/bin/systemctl', 'enable', '--now', TIMER])
        if code != 0:
            raise SafeError('CAPTURE_TIMER_START_NEEDS_REVIEW')
        code, _ = runner(['/usr/bin/systemctl', 'is-active', '--quiet', TIMER])
        if code != 0:
            raise SafeError('CAPTURE_TIMER_ACTIVE_CHECK_FAILED')
        code, _ = runner(['/usr/bin/systemctl', 'start', '--no-block', SERVICE])
        if code != 0:
            raise SafeError('CAPTURE_FIRST_PROJECTION_START_NEEDS_REVIEW')
    except SafeError:
        # Roll back only these verified dedicated units, retaining orders settings.
        check_units()
        runner(['/usr/bin/systemctl', 'disable', '--now', TIMER])
        runner(['/usr/bin/systemctl', 'stop', SERVICE])
        raise
    return 'WHATSAPP_CAPTURE_ONLY_TIMER_ACTIVE'


def stop(runner=command):
    check_units()
    code, _ = runner(['/usr/bin/systemctl', 'disable', '--now', TIMER])
    if code != 0:
        raise SafeError('CAPTURE_TIMER_STOP_NEEDS_REVIEW')
    service_stop, _ = runner(['/usr/bin/systemctl', 'stop', SERVICE])
    if service_stop != 0:
        raise SafeError('CAPTURE_WORKER_STOP_NEEDS_REVIEW')
    for name in (TIMER, SERVICE):
        state = unit_state(name, runner)
        if (state['LoadState'] != 'loaded' or state['ActiveState'] not in ('inactive', 'failed')
                or name == TIMER and state['UnitFileState'] != 'disabled'):
            raise SafeError('CAPTURE_TIMER_STILL_ACTIVE')
    return 'WHATSAPP_CAPTURE_TIMER_AND_WORKER_STOPPED'


def main():
    try:
        if os.geteuid() != 0 or len(sys.argv) != 2 or sys.argv[1] not in ('--install', '--start', '--stop'):
            raise SafeError('RUN_FIXED_WHATSAPP_CAPTURE_HELPER_AS_ROOT_WITH_INSTALL_START_OR_STOP')
        uid = pwd.getpwnam('fasakha').pw_uid
        info = ROOT.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid:
            raise SafeError('WHATSAPP_CONFIRMED_ROOT_OWNER_OR_TYPE_NEEDS_REVIEW')
        system_path(PHP)
        if not os.access(PHP, os.X_OK):
            raise SafeError('WHATSAPP_CPANEL_PHP_NOT_EXECUTABLE')
        if sys.argv[1] == '--install':
            status, backup = install()
            print(status)
            if backup is not None: print('BACKUP=' + str(backup))
        else:
            print(start() if sys.argv[1] == '--start' else stop())
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('WHATSAPP_SYSTEMD_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
PY

