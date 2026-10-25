#!/usr/bin/env python3
"""Make a fresh verified application/account backup; never delete older backups."""
import argparse
from contextlib import ExitStack
import datetime
import hashlib
import importlib
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time

PACKAGE = Path(__file__).absolute().parent
BASE = Path('/root/fasakhansta-full-backups')
RESERVE = 3 * 1024**3
PINS = {
    'go_erp_release_retention_root.py': 'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293',
    'full_backup_20261025/create_all_apps_backup_root.py': 'c7f3c6a672f2c775164fc373ff4458de8d3765e59ca5bff0a3e6a1d28a3d50d7',
    'full_backup_20261025/create_current_backup_and_prune_root.py': '5e984e95e079ee38f3a4a838160e555b446c3ba0a6898cdd26dd6360bf95059f',
    'full_backup_20261025/backup_server_runtime.py': 'e6cf3bb40358fe461eb858d1ce34130c047b19089bf288cffaa5dbb87b1d4b9f',
}


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop(code)


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')


def file_identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def safe_directory(path, root_owned=True):
    need(path.is_absolute() and path.resolve(strict=True) == path, 'DIRECTORY_LINK_OR_PATH')
    for parent in (*reversed(path.parents), path):
        item = parent.lstat()
        sticky = parent != path and bool(item.st_mode & stat.S_ISVTX)
        need(stat.S_ISDIR(item.st_mode)
             and (not root_owned or item.st_uid == 0)
             and (not root_owned or not item.st_mode & 0o022 or sticky), 'DIRECTORY_OWNER_OR_MODE')


def read_pinned(path, expected):
    safe_directory(path.parent)
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
         and not before.st_mode & 0o022 and 0 < before.st_size < 2 * 1024**2, 'HELPER_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(file_identity(os.fstat(fd)) == file_identity(before), 'HELPER_CHANGED')
        parts, total = [], 0
        while total <= before.st_size:
            part = os.read(fd, 65536)
            if not part:
                break
            total += len(part)
            parts.append(part)
        raw = b''.join(parts)
        need(len(raw) == before.st_size and file_identity(os.fstat(fd)) == file_identity(before)
             and file_identity(path.lstat()) == file_identity(before), 'HELPER_CHANGED')
    finally:
        os.close(fd)
    need(hashlib.sha256(raw).hexdigest() == expected, 'HELPER_PIN_MISMATCH')
    return raw


def load_dependencies():
    for name, sha in PINS.items():
        raw = read_pinned(PACKAGE / name, sha)
        compile(raw, str(PACKAGE / name), 'exec')
    sys.path.insert(0, str(PACKAGE / 'full_backup_20261025'))
    core = importlib.import_module('create_all_apps_backup_root')
    split = importlib.import_module('create_current_backup_and_prune_root')
    runtime = importlib.import_module('backup_server_runtime')
    locks = importlib.import_module('go_erp_release_retention_root')
    need(core.HOME == Path('/home/fasakha') and core.BASE == BASE
         and core.ACCOUNT == 'fasakha' and split.core is core, 'HELPER_BINDING_MISMATCH')
    return core, split, runtime, locks


class Progress:
    def __init__(self, base):
        self.base, self.stage = base, 'preflight'
        self.started = time.monotonic()
        self.event = threading.Event()
        self.thread = threading.Thread(target=self._heartbeat, daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_):
        self.event.set()
        self.thread.join(timeout=1)

    def set(self, stage):
        self.stage = stage
        print('BACKUP_STAGE=' + stage, flush=True)

    def _heartbeat(self):
        while not self.event.wait(30):
            try:
                free = shutil.disk_usage(str(self.base)).free
                print('BACKUP_PROGRESS stage=%s elapsed_seconds=%d free_gib=%.2f' %
                      (self.stage, time.monotonic() - self.started, free / 1024**3), flush=True)
            except OSError:
                pass


def stop_own_process(process):
    if process.poll() is not None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        return
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()


def monitored_run(base):
    def run(args, log=None, env=None, cwd=None):
        environment = dict(os.environ if env is None else env)
        environment.update({'GIT_TERMINAL_PROMPT': '0', 'GIT_ASKPASS': '/bin/false',
                            'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'})
        with tempfile.TemporaryFile(mode='w+b') as captured, tempfile.TemporaryFile(mode='w+b') as errors:
            output = open(str(log), 'ab') if log is not None else captured
            try:
                process = subprocess.Popen(args, cwd=cwd, env=environment,
                                           stdout=output, stderr=subprocess.STDOUT if log is not None else errors,
                                           stdin=subprocess.DEVNULL, start_new_session=True)
                try:
                    started = time.monotonic()
                    while process.poll() is None:
                        need(shutil.disk_usage(str(base)).free > RESERVE, 'OPERATING_SPACE_RESERVE_REACHED')
                        need(time.monotonic() - started < 2 * 3600, 'BACKUP_COMMAND_TIMEOUT')
                        time.sleep(0.25)
                    need(process.returncode == 0, 'BACKUP_COMMAND_FAILED_OUTPUT_PRIVATE')
                finally:
                    stop_own_process(process)
                if log is not None:
                    return ''
                captured.seek(0)
                return captured.read().decode('utf-8', errors='replace')
            finally:
                if log is not None:
                    output.close()
    return run


def external_home_links(inventory, home, roots):
    external = []
    for name, record in inventory.items():
        if record['type'] != 'symlink':
            continue
        target = (home / name).resolve()
        if target == home or home in target.parents:
            continue
        covered = any(target == root or root in target.parents for root in roots)
        parts = Path(name).parts
        if any(part in ('storage', 'uploads', 'media') for part in parts):
            need(covered, 'EXTERNAL_APPLICATION_STORAGE_NOT_COVERED')
        external.append({'path': name, 'target': str(target), 'server_runtime_covered': covered})
    return external


def check_total_space(base, core, split, roots):
    account = split.current_size([])
    sql = int(core.run(['du', '-s', '-B1', '/var/lib/mysql']).split()[0]) if Path('/var/lib/mysql').is_dir() else 0
    server = sum(int(core.run(['du', '-s', '-B1', '--apparent-size', str(path)]).split()[0]) for path in roots)
    required = int(account * 2 + sql * 4 + server * 1.5 + 8 * 1024**3 + RESERVE)
    free = shutil.disk_usage(str(base)).free
    need(free > required, 'INSUFFICIENT_SPACE_FOR_NEW_BACKUP_OLD_BACKUP_PRESERVED')
    return {'home_apparent_bytes': account, 'mysql_bytes_upper_bound': sql,
            'server_runtime_apparent_bytes': server, 'required_bytes': required,
            'available_bytes_before': free, 'reserve_bytes': RESERVE}


def seal(destination, manifest, core, final_guard=lambda: None):
    (destination / 'manifest.json').write_text(json.dumps(manifest, sort_keys=True, indent=2) + '\n')
    (destination / 'RESTORE-NOTES.txt').write_text(
        'FRESH COMPLETE APPLICATION BACKUP: split cPanel account/home plus external server runtime.\n'
        'First verify SHA256SUMS and the completion marker. Rehearse restoration on an isolated server.\n'
        'account/cpmove-fasakha.tar.gz was built fresh with --skiphomedir --dbbackup=all. '
        'It must be restored together with home-current.tar.gz into /home/fasakha, preserving '
        'reviewed numeric owners, ACLs and xattrs. The native cPanel package contains account, '
        'domain, SSL, cron and owned database data/metadata.\n'
        'Review and selectively restore server-config.tar.gz and server-runtime.tar.gz from /. '
        'The latter contains the listed /etc, /opt and /var/lib GO ERP runtime/config/state paths, '
        'including protected credentials. Do not expose these archives publicly. '
        'Review service units and credentials before enabling them on a replacement server.\n'
        'Home includes local source edits, dependencies, uploads and existing account rollback '
        'backups. Only the exact Laravel runtime-content exclusions listed in manifest.json apply. '
        'Symlinks are preserved, not blindly dereferenced; review external_home_links. '
        'Seven fresh Git mirrors are separate source-history copies.\n'
        'This is an online backup collected over a capture window, not an atomic VPS/disk image. '
        'Updater publication was serialized; business writes were not disabled. '
        'SQL completion footers, archive coverage, runtime file hashes, gzip CRC, Git fsck and '
        'output SHA256 were checked. No database import or restore rehearsal was performed. '
        'OS binaries, Windows-PC-only data and external hosted files are outside this backup.\n'
        'No earlier full/partial backup is deleted by this command.\n')
    lines = []
    for path in sorted(destination.rglob('*')):
        if not path.is_file():
            continue
        need(not path.is_symlink(), 'OUTPUT_SYMLINK_UNSAFE')
        name = str(path.relative_to(destination))
        need('\n' not in name and '\\' not in name, 'OUTPUT_NAME_INVALID')
        lines.append(core.sha256(path) + '  ' + name + '\n')
    (destination / 'SHA256SUMS').write_text(''.join(lines))
    core.run(['sha256sum', '--check', '--quiet', 'SHA256SUMS'], cwd=str(destination))
    final_guard()
    marker = destination / 'backup.ok'
    fd, temporary_name = tempfile.mkstemp(prefix='.backup-ok-', dir=str(destination))
    temporary = Path(temporary_name)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write('FRESH_FULL_BACKUP_VERIFIED\nLAYOUT=SPLIT_HOME_SERVER_RUNTIME\n')
            stream.flush()
            os.fsync(stream.fileno())
        # Publish only a complete durable marker, without replacing any entry.
        os.link(str(temporary), str(marker), follow_symlinks=False)
    finally:
        temporary.unlink()
    directory = os.open(destination, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def collect(destination, core, split, runtime, progress, database_report, inventory, roots, space,
            final_guard=lambda: None):
    started = utc_now()
    (destination / 'logs').mkdir(mode=0o700)
    (destination / 'account').mkdir(mode=0o700)
    tools = destination / 'tools'
    tools.mkdir(mode=0o700)
    for name in ['create_fresh_full_backup_root.py'] + list(PINS):
        target = tools / name
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        shutil.copyfile(str(PACKAGE / name), str(target))
    (destination / 'home-inventory.json').write_text(json.dumps(inventory, indent=2))
    (destination / 'database-inventory.json').write_text(json.dumps(database_report, indent=2))
    external = external_home_links(inventory, core.HOME, roots)
    progress.set('account_and_databases')
    account = split.package_account(destination)
    sql = split.verify_sql_package(account, database_report['databases'])
    progress.set('seven_source_mirrors')
    sources = core.copy_sources(destination / 'sources', destination / 'logs')
    progress.set('current_home')
    home, warnings = split.stream_home(destination, [])
    progress.set('verify_home_coverage')
    home_check = split.verify_home(home, inventory, [])
    progress.set('server_configuration')
    configs = core.copy_system_config(destination)
    progress.set('go_erp_server_runtime')
    server = runtime.backup(destination, core)
    need(server['roots'] == [str(root) for root in roots], 'SERVER_ROOT_SET_CHANGED')
    need(split.database_inventory_from_map()['databases'] == database_report['databases'], 'DATABASE_OWNERSHIP_CHANGED')
    progress.set('seal_and_verify_all_checksums')
    manifest = {'format': 1, 'kind': 'fasakhansta-fresh-full-backup',
                'layout': 'split-home-plus-server-runtime', 'account': core.ACCOUNT,
                'started_utc': started, 'finished_utc': utc_now(),
                'database_verification': sql, 'home_verification': home_check,
                'server_runtime': server, 'server_config_paths': configs,
                'sources': sources, 'space_plan': space,
                'online_directory_mtime_warnings': warnings, 'external_home_links': external,
                'excluded_runtime_contents': [name + '/*' for name in split.RUNTIME_DIRS],
                'point_backups_included': True, 'earlier_backups_deleted': False,
                'online_capture_window': True, 'restore_rehearsal': False,
                'windows_pc_local_data_included': False}
    seal(destination, manifest, core, final_guard)
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--complete', action='store_true', required=True)
    parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    os.chdir('/')
    core, split, runtime, locks = load_dependencies()
    safe_directory(BASE)
    need(core.HOME.is_dir() and core.HOME.resolve() == core.HOME
         and Path('/var/cpanel/users/fasakha').is_file(), 'CPANEL_ACCOUNT_MISSING')
    for tool in (core.PKGACCT, 'git', 'tar', 'du', 'sha256sum', 'systemctl', 'runuser'):
        need(shutil.which(tool), 'REQUIRED_BACKUP_TOOL_MISSING')
    with ExitStack() as stack:
        held = [locks.lock_file(BASE / '.fresh-full-backup.lock', stack)]
        for path in (Path('/var/lib/go-erp-manual-updates/private/install.lock'),
                     Path('/var/lib/go-erp-manual-updates/private/worker.lock'),
                     Path('/var/lib/go-erp-live-updates/watcher.lock')):
            held.append(locks.lock_file(path, stack))
        publication = locks.Directory(Path('/home/fasakha/public_html/storage/app/desktop-updates'), stack)
        import fcntl
        fcntl.flock(publication.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        held.append(locks.lock_file(Path('/var/lib/go-erp-manual-updates/shared/.downloads.lock'), stack))
        core.run = monitored_run(BASE)
        split.run = core.run
        with Progress(BASE) as progress:
            progress.set('inventory_and_space')
            databases = split.database_inventory_from_map()
            roots = runtime.discover_roots()
            need(Path('/etc/go-erp') in roots, 'GO_ERP_CONFIG_NOT_COVERED')
            space = check_total_space(BASE, core, split, roots)
            inventory = split.home_inventory([])
            external_home_links(inventory, core.HOME, roots)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-fresh-full-')
            destination = Path(tempfile.mkdtemp(prefix=prefix, dir=str(BASE)))
            print('BACKUP=' + str(destination), flush=True)
            print('DATABASE_COUNT=' + str(len(databases['databases'])), flush=True)
            try:
                def final_guard():
                    locks.check_locks(held)
                    publication.check()
                collect(destination, core, split, runtime, progress, databases, inventory, roots, space, final_guard)
                print(json.dumps({'status': 'verified', 'backup': str(destination),
                                  'database_count': len(databases['databases']),
                                  'server_runtime_included': True, 'old_backups_deleted': False,
                                  'free_bytes_after': shutil.disk_usage(str(BASE)).free}, sort_keys=True), flush=True)
            except BaseException:
                print('INCOMPLETE_OR_UNCONFIRMED_BACKUP=' + str(destination), file=sys.stderr, flush=True)
                raise
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except BlockingIOError:
        print('FRESH_BACKUP_UPDATE_OR_BACKUP_BUSY_OLD_BACKUP_PRESERVED', file=sys.stderr)
        raise SystemExit(75)
    except KeyboardInterrupt:
        print('FRESH_BACKUP_INTERRUPTED_OLD_BACKUP_PRESERVED', file=sys.stderr)
        raise SystemExit(130)
    except Exception as error:
        code = str(error) if isinstance(error, Stop) else type(error).__name__
        print('FRESH_BACKUP_STOPPED_' + code, file=sys.stderr)
        raise SystemExit(1)
