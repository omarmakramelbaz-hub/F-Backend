#!/usr/bin/env python3
"""Install the independently locked, two-published-release retention worker."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import uuid

WORKER_SHA256 = 'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293'
PUBLISHER_SHA256 = '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490'
INSTALL = Path('/opt/go-erp-release-retention')
CACHE = Path('/var/lib/go-erp-release-retention')
UNITS = Path('/etc/systemd/system')
SERVICE = 'go-erp-release-retention.service'
TIMER = 'go-erp-release-retention.timer'


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_RETENTION_INSTALL_' + code)


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def safe_directory(path):
    need(path.is_absolute() and path.resolve(strict=True) == path, 'DIRECTORY_UNSAFE')
    for parent in (*reversed(path.parents), path):
        info = parent.lstat()
        sticky = parent != path and bool(info.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0
             and (not info.st_mode & 0o022 or sticky), 'DIRECTORY_UNSAFE')


def read_regular(path, maximum, mode=None):
    safe_directory(path.parent)
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0
         and before.st_nlink == 1 and not before.st_mode & 0o022
         and (mode is None or (stat.S_IMODE(before.st_mode) == mode and before.st_gid == 0))
         and 0 < before.st_size <= maximum, 'FILE_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        need(identity(before) == identity(os.fstat(fd)), 'FILE_CHANGED')
        chunks, total = [], 0
        while total <= maximum:
            block = os.read(fd, min(65536, maximum + 1 - total))
            if not block:
                break
            chunks.append(block)
            total += len(block)
        need(total == before.st_size
             and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()),
             'FILE_CHANGED')
        return b''.join(chunks)
    finally:
        os.close(fd)


def present(path):
    try:
        path.lstat()
        return True
    except FileNotFoundError:
        return False


def ensure_directory(path, mode=0o755):
    safe_directory(path.parent)
    if not present(path):
        path.mkdir(mode=mode)
        os.chown(path, 0, 0)
        os.chmod(path, mode)
    safe_directory(path)
    info = path.lstat()
    need(info.st_gid == 0 and stat.S_IMODE(info.st_mode) == mode,
         'INSTALL_DIRECTORY_UNSAFE')


def check_output(path, wanted):
    if present(path):
        need(read_regular(path, max(len(wanted), 1), 0o644) == wanted,
             'EXISTING_OUTPUT_CONFLICT')


def install_file(path, wanted):
    """Commit an absent file without replacing an existing directory entry."""
    check_output(path, wanted)
    if present(path):
        return
    safe_directory(path.parent)
    temporary = path.parent / ('.retention-' + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(wanted)
            stream.flush()
            os.fchown(stream.fileno(), 0, 0)
            os.fchmod(stream.fileno(), 0o644)
            os.fsync(stream.fileno())
        # Commit without replacement; remove the staging link before execution.
        try:
            os.link(temporary, path, follow_symlinks=False)
        except FileExistsError:
            check_output(path, wanted)
        temporary.unlink()
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
        check_output(path, wanted)
    finally:
        if present(temporary):
            temporary.unlink()


def command(arguments, timeout=60):
    result = subprocess.run(arguments, stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            universal_newlines=True, timeout=timeout, check=False,
                            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin',
                                 'LANG': 'C', 'LC_ALL': 'C'})
    need(result.returncode == 0, 'COMMAND_FAILED')
    return result.stdout.strip()


def run_worker(path, mode):
    result = subprocess.run(['/usr/bin/python3', str(path), '--' + mode],
                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, universal_newlines=True, timeout=1200,
                            check=False, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin',
                                              'LANG': 'C', 'LC_ALL': 'C'})
    need(result.returncode != 75, 'WORKER_BUSY_RETRY_AFTER_UPDATE')
    if result.returncode != 0 and len(result.stdout) <= 1048576:
        try:
            failure = json.loads(result.stdout)
        except ValueError:
            failure = None
        if (isinstance(failure, dict) and failure.get('status') == 'blocked'
                and isinstance(failure.get('code'), str)
                and re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,200}', failure['code'])):
            raise Stop(failure['code'])
    need(result.returncode == 0 and not result.stderr.strip()
         and len(result.stdout) <= 1048576
         and len(result.stdout.strip().splitlines()) == 1, 'WORKER_FAILED')
    try:
        summary = json.loads(result.stdout)
    except ValueError:
        raise Stop('GO_ERP_RETENTION_INSTALL_WORKER_SUMMARY_INVALID')
    need(isinstance(summary, dict)
         and summary.get('status') == {'check': 'checked', 'apply': 'applied'}[mode]
         and summary.get('scope') == 'desktop_installers'
         and isinstance(summary.get('keep_versions'), list), 'WORKER_SUMMARY_INVALID')
    return summary


def service_text(worker):
    return ('''[Unit]
Description=GO ERP retain the latest two successfully published installers
After=local-fs.target

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 ''' + str(worker) + ''' --apply
SuccessExitStatus=75
TimeoutStartSec=20min
Nice=19
IOSchedulingClass=idle
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
''').encode()


TIMER_TEXT = b'''[Unit]
Description=Check GO ERP installer retention every five minutes

[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
AccuracySec=30s
Unit=go-erp-release-retention.service

[Install]
WantedBy=timers.target
'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--complete', action='store_true', required=True)
    parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
         'ROOT_AND_HOST_REQUIRED')
    need(re.fullmatch(r'[a-f0-9]{64}', WORKER_SHA256), 'WORKER_PIN_INVALID')
    os.umask(0o077)
    source = Path(__file__).absolute().parent / 'go_erp_release_retention_root.py'
    payload = read_regular(source, 1024 * 1024)
    need(hashlib.sha256(payload).hexdigest() == WORKER_SHA256, 'WORKER_PIN_INVALID')
    compile(payload, str(source), 'exec')
    publisher_source = source.parent / 'publish_go_erp_update.py'
    publisher_payload = read_regular(publisher_source, 1024 * 1024)
    need(hashlib.sha256(publisher_payload).hexdigest() == PUBLISHER_SHA256,
         'PUBLISHER_PIN_INVALID')
    compile(publisher_payload, str(publisher_source), 'exec')
    safe_directory(UNITS)
    ensure_directory(INSTALL)
    ensure_directory(CACHE, 0o700)
    worker = INSTALL / ('retention-' + WORKER_SHA256 + '.py')
    publisher = INSTALL / ('publisher-' + PUBLISHER_SHA256 + '.py')
    service = UNITS / SERVICE
    timer = UNITS / TIMER
    wanted_service = service_text(worker)
    check_output(worker, payload)
    check_output(publisher, publisher_payload)
    check_output(service, wanted_service)
    check_output(timer, TIMER_TEXT)
    for unit in (SERVICE, TIMER):
        for folder in (Path('/etc/systemd/system'), Path('/run/systemd/system'),
                       Path('/usr/local/lib/systemd/system'), Path('/usr/lib/systemd/system')):
            need(not present(folder / (unit + '.d')), 'UNIT_DROPIN_CONFLICT')
            if folder != UNITS:
                need(not present(folder / unit), 'UNIT_PROVIDER_CONFLICT')
    install_file(worker, payload)
    install_file(publisher, publisher_payload)
    run_worker(worker, 'check')
    applied = run_worker(worker, 'apply')
    install_file(service, wanted_service)
    install_file(timer, TIMER_TEXT)
    command(['/usr/bin/systemctl', 'daemon-reload'])
    for unit, expected in ((SERVICE, service), (TIMER, timer)):
        need(command(['/usr/bin/systemctl', 'show', unit, '-p', 'FragmentPath', '--value'])
             == str(expected), 'UNIT_FRAGMENT_MISMATCH')
        need(command(['/usr/bin/systemctl', 'show', unit, '-p', 'DropInPaths', '--value'])
             == '', 'UNIT_DROPIN_CONFLICT')
    command(['/usr/bin/systemctl', 'enable', '--now', TIMER])
    need(command(['/usr/bin/systemctl', 'is-enabled', TIMER]) == 'enabled',
         'TIMER_NOT_ENABLED')
    need(command(['/usr/bin/systemctl', 'is-active', TIMER]) == 'active',
         'TIMER_NOT_ACTIVE')
    check_output(worker, payload)
    check_output(publisher, publisher_payload)
    check_output(service, wanted_service)
    check_output(timer, TIMER_TEXT)
    print(json.dumps({'status': 'installed', 'scope': 'desktop_installers',
                      'keep_versions': applied['keep_versions'],
                      'removed_files': applied.get('removed_files'),
                      'reclaimed_bytes': applied.get('reclaimed_bytes'),
                      'timer': TIMER, 'timer_enabled': True, 'timer_active': True,
                      'interval_minutes': 5}, sort_keys=True))
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as error:
        code = str(error) if type(error) is Stop else 'GO_ERP_RETENTION_INSTALL_PRIVATE_FAILURE'
        print(json.dumps({'status': 'blocked', 'code': code}, sort_keys=True), file=sys.stderr)
        raise SystemExit(1)
