#!/usr/bin/env python3
"""Run bounded server cleanup after verifying the owner's fresh full backup."""
from contextlib import ExitStack
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import stat
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
PACKAGE = Path(__file__).absolute().parent
BASE = Path('/root/fasakhansta-full-backups')
CACHES = (Path('/root/.cache/composer'), Path('/root/.cache/pip'))


def need(value, message):
    if not value:
        raise RuntimeError(message)


def module(name, path, digest=None):
    info = path.lstat()
    need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
         and not info.st_mode & 0o022, 'UNSAFE_CLEANUP_DEPENDENCY')
    raw = path.read_bytes()
    if digest:
        need(hashlib.sha256(raw).hexdigest() == digest, 'CLEANUP_DEPENDENCY_PIN_MISMATCH')
    spec = importlib.util.spec_from_file_location(name, str(path))
    result = importlib.util.module_from_spec(spec)
    sys.modules[name] = result
    exec(compile(raw, str(path), 'exec'), result.__dict__)
    return result


def process_uses(path):
    prefix = str(path) + '/'
    for process in Path('/proc').iterdir():
        if not process.name.isdigit() or int(process.name) == os.getpid():
            continue
        names = [process / 'cwd', process / 'exe']
        try:
            names.extend((process / 'fd').iterdir())
        except OSError:
            pass
        for name in names:
            try:
                target = os.readlink(str(name)).replace(' (deleted)', '')
            except OSError:
                continue
            if target == str(path) or target.startswith(prefix):
                return True
    return False


def cache_fingerprint(path):
    result = []
    device = path.lstat().st_dev
    for parent, dirs, files in os.walk(str(path), followlinks=False):
        for item in [Path(parent)] + [Path(parent) / name for name in dirs + files]:
            info = item.lstat()
            need(info.st_uid == 0 and info.st_dev == device and not info.st_mode & 0o022
                 and (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)), 'UNSAFE_CACHE_ENTRY')
            need(stat.S_ISDIR(info.st_mode) or info.st_nlink == 1, 'LINKED_CACHE_ENTRY')
            result.append((str(item.relative_to(path)), info.st_dev, info.st_ino, info.st_mode,
                           info.st_uid, info.st_gid, info.st_size, info.st_mtime_ns, info.st_ctime_ns))
    return sorted(set(result))


def clean_caches(audit):
    removed, skipped = [], []
    for path in CACHES:
        if not os.path.lexists(str(path)):
            continue
        quarantine = None
        try:
            for parent in (*reversed(path.parents), path):
                info = parent.lstat()
                need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                     'UNSAFE_CACHE_DIRECTORY')
            need(path.stat().st_dev == audit.stat().st_dev, 'CACHE_FILESYSTEM_MISMATCH')
            # Mount roots and bind sources both make a cache unsuitable for removal.
            for line in Path('/proc/self/mountinfo').read_text().splitlines():
                fields = line.split()
                for raw in fields[3:5]:
                    import re
                    mount = re.sub(r'\\([0-7]{3})', lambda match: chr(int(match.group(1), 8)), raw)
                    need(mount != str(path) and not mount.startswith(str(path) + '/'), 'MOUNTED_CACHE')
            need(not process_uses(path), 'CACHE_IN_USE')
            before = cache_fingerprint(path)
            need(not process_uses(path) and before == cache_fingerprint(path), 'CACHE_CHANGED')
            quarantine = audit / ('cache-' + path.name)
            os.rename(str(path), str(quarantine))
            # Rename changes the top directory ctime, but never the descendant contents.
            after = cache_fingerprint(quarantine)
            need([row for row in before if row[0] != '.'] == [row for row in after if row[0] != '.']
                 and next(row for row in before if row[0] == '.')[1:3]
                 == next(row for row in after if row[0] == '.')[1:3], 'CACHE_CHANGED')
            need(not process_uses(quarantine), 'CACHE_IN_USE')
            need(getattr(shutil.rmtree, 'avoids_symlink_attacks', False), 'SAFE_REMOVAL_UNAVAILABLE')
            shutil.rmtree(str(quarantine))
            removed.append(str(path))
        except (OSError, RuntimeError) as error:
            if quarantine is not None and quarantine.exists() and not os.path.lexists(str(path)):
                os.rename(str(quarantine), str(path))
            skipped.append({'path': str(path), 'reason': type(error).__name__ + ':' + str(error)})
    return {'removed_cache_paths': removed, 'skipped_cache_paths': skipped}


def sizes():
    # Exact independent roots: never traverse the cPanel virtfs bind-mount mirror.
    paths = ['/root/fasakhansta-full-backups', '/home/fasakha', '/var/lib/go-erp-live-updates',
             '/var/lib/go-erp-manual-updates', '/var/lib/mysql', '/var/log', '/var/cache',
             '/root/.cache', '/usr/local/cpanel', '/usr/tmpDSK', '/tmp']
    result = {}
    for path in paths:
        if not os.path.exists(path):
            continue
        measured = subprocess.run(['du', '-x', '-s', '-B1', '--', path],
                                  stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                  universal_newlines=True)
        if measured.stdout.split():
            result[path] = int(measured.stdout.split()[0])
    return result


def root_breakdown():
    measured = subprocess.run(['du', '-x', '-B1', '--max-depth=1',
                               '--exclude=/home/virtfs', '/'], stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL, universal_newlines=True)
    output = measured.stdout
    return {row.split(None, 1)[1]: int(row.split(None, 1)[0])
            for row in output.splitlines() if row.strip()}


def home_breakdown():
    measured = subprocess.run(['du', '-x', '-B1', '--max-depth=1', '/home/fasakha'],
                              stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                              universal_newlines=True)
    return {row.split(None, 1)[1]: int(row.split(None, 1)[0])
            for row in measured.stdout.splitlines() if row.strip()}


def upgrade_staging_inventory():
    result = []
    root = Path('/var/lib/go-erp-live-updates')
    for path in sorted(root.glob('*-seed-upgrade-*')):
        if not path.is_dir() or path.is_symlink():
            continue
        checkout = path / 'exchange-checkout'
        if not checkout.is_dir() or checkout.is_symlink():
            continue
        row = {'name': path.name, 'intent_present': (path / 'intent.json').is_file(),
               'completed_present': (path / 'completed.json').is_file(),
               'rolled_back_present': (path / 'rolled-back.json').is_file()}
        measured = subprocess.run(['du', '-x', '-s', '-B1', '--', str(checkout)],
                                  stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                  universal_newlines=True)
        row['allocated_bytes'] = int(measured.stdout.split()[0]) if measured.stdout.split() else None
        result.append(row)
    return result


def main():
    need(len(sys.argv) == 2 and sys.argv[1] == '--apply', 'USE_APPLY')
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    os.chdir('/')
    retention = module('cleanup_retention', PACKAGE / 'go_erp_release_retention_root.py',
                       'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293')
    retention.PUBLISHER = Path('/opt/go-erp-release-retention') / ('publisher-' + retention.PUBLISHER_SHA256 + '.py')
    print('CLEANUP_STAGE=latest_two_program_versions', flush=True)
    try:
        with ExitStack() as stack:
            publisher = retention.load_publisher(stack)
            versions = retention.run(retention.Paths(), publisher, apply=True)
    except BlockingIOError:
        versions = {'status': 'deferred', 'reason': 'active_or_pending_update',
                    'retention_timer_unchanged': True, 'removed_files': 0}
    print(json.dumps(versions, sort_keys=True), flush=True)
    full = module('cleanup_full_backup', PACKAGE / 'prune_previous_full_backup_root.py')
    points = module('cleanup_point_backups', PACKAGE / 'clean_archived_point_backups_root.py')
    upgrades = module('cleanup_closed_upgrades', PACKAGE / 'clean_closed_upgrade_checkouts_root.py')
    before = shutil.disk_usage(str(BASE)).free
    with full.locked_backup() as verified, ExitStack() as stack:
        held = []
        for path in (Path('/var/lib/go-erp-manual-updates/private/install.lock'),
                     Path('/var/lib/go-erp-manual-updates/private/worker.lock'),
                     Path('/var/lib/go-erp-live-updates/watcher.lock')):
            held.append(retention.lock_file(path, stack))
        publication = retention.Directory(Path('/home/fasakha/public_html/storage/app/desktop-updates'), stack)
        fcntl.flock(publication.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        held.append(retention.lock_file(Path('/var/lib/go-erp-manual-updates/shared/.downloads.lock'), stack))
        verified.check()
        audit = Path(tempfile.mkdtemp(prefix='verified-cleanup-', dir=str(BASE)))
        tools = verified.latest_path / 'tools/full_backup_20261025'
        module('create_all_apps_backup_root', tools / 'create_all_apps_backup_root.py',
               'c7f3c6a672f2c775164fc373ff4458de8d3765e59ca5bff0a3e6a1d28a3d50d7')
        split = module('cleanup_home_dependencies', tools / 'create_current_backup_and_prune_root.py',
                       '5e984e95e079ee38f3a4a838160e555b446c3ba0a6898cdd26dd6360bf95059f')
        print('CLEANUP_STAGE=old_full_backup', flush=True)
        verified.progress.stage = 'remove_named_obsolete_full_backup'
        old = verified.prune_old(apply=True)
        retention.check_locks(held)
        publication.check()
        verified.check()
        print('CLEANUP_STAGE=archived_point_backups', flush=True)
        verified.progress.stage = 'verify_archived_point_backups'
        try:
            point_result = points.clean(verified, split, apply=True)
        except (points.Stop, split.core.BackupError) as error:
            verified.check()
            point_result = {'point_backup_status': 'review_required', 'reason': str(error)}
            print('POINT_BACKUPS_REVIEW_REQUIRED=' + str(error), flush=True)
        retention.check_locks(held)
        publication.check()
        verified.check()
        print('CLEANUP_STAGE=closed_historical_upgrade_checkouts', flush=True)
        verified.progress.stage = 'verify_closed_historical_upgrade_checkouts'
        def guard():
            verified.check()
            retention.check_locks(held)
            publication.check()
        try:
            upgrade_result = upgrades.clean(verified, points, split, apply=True, guard=guard)
        except (upgrades.Stop, points.Stop, split.core.BackupError) as error:
            guard()
            upgrade_result = {'closed_checkout_status': 'review_required', 'reason': str(error)}
            print('CLOSED_UPGRADE_CHECKOUTS_REVIEW_REQUIRED=' + str(error), flush=True)
        guard()
        print('CLEANUP_STAGE=regenerable_package_caches', flush=True)
        verified.progress.stage = 'regenerable_package_caches'
        cache_result = clean_caches(audit)
        after = shutil.disk_usage(str(BASE)).free
        verified.progress.stage = 'remaining_disk_inventory'
        report = {'status': 'completed', 'kept_full_backup': str(verified.latest_path),
                  'program_versions': versions, 'old_full_backup': old, 'point_backups': point_result,
                  'closed_upgrade_checkouts': upgrade_result,
                  'package_caches': cache_result, 'free_bytes_before': before, 'free_bytes_after': after,
                  'free_bytes_increased': after - before, 'directory_allocated_bytes': sizes(),
                  'root_allocated_bytes_excluding_virtfs': root_breakdown(),
                  'home_allocated_bytes': home_breakdown(),
                  'upgrade_staging_preserved_for_review': upgrade_staging_inventory(),
                  'finished_utc': datetime.datetime.now(datetime.timezone.utc).isoformat()}
        target = audit / 'cleanup-result.json'
        target.write_text(json.dumps(report, sort_keys=True, indent=2) + '\n')
        print(json.dumps(report, sort_keys=True), flush=True)
        print('CLEANUP_REPORT=' + str(target), flush=True)
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except BlockingIOError:
        print('VERIFIED_CLEANUP_BUSY_RETRY_AFTER_CURRENT_UPDATE', flush=True)
        raise SystemExit(75)
    except Exception as error:
        print('VERIFIED_CLEANUP_STOPPED=' + type(error).__name__ + ':' + str(error), flush=True)
        raise SystemExit(1)
