#!/usr/bin/env python3
"""The leftovers the owner approved removing from srv1836524 on 2026-11-02, each only once it is in the full backup:
  - the test site's files (/home/fasakha/public_html/test.fasakhaninja.com, which no longer answers);
  - faskhaNinja (an app project copied into the site folder; the site never uses it);
  - the old updates system's state (/var/lib/go-erp-live-updates; its service is off);
  - six old copies of the site's .env next to it, and three files that mistyped shell commands created.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/cleanup_server_root.py            what is there (changes nothing)
  python3 -I -B deployment/cleanup_server_root.py --apply    removes each one the backup holds whole

--apply reads the backup's archives once and removes a folder only when the archive has at least as many entries
under it as the disk does, and a file only when the archive has it. Anything not held whole is left in place and
named. Only the paths listed here are ever removed. Names and sizes are printed; never a file's content.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess

HOST = 'srv1836524'
SITE = Path('/home/fasakha/public_html')
BACKUP = Path('/root/fasakhansta-full-backups/20261102T114454Z-before-refactor')
LIVE_UPDATES = Path('/var/lib/go-erp-live-updates')
SITE_FOLDERS = ('test.fasakhaninja.com', 'faskhaNinja')
SITE_FILES = (
    '.env.backup-before-db-fix',
    '.env.backup-before-google-client-fix-20260829-061021',
    '.env.backup-before-social-auth-20260828-030036',
    '.env.bad-social-auth-20260828-031516',
    '.env.bak-cache-20261020-045526',
    '.env.save',
    '-ef | grep cpauthd',
    ".send_order_email', [",
    "elect('exception')",
)
LOW = ['nice', '-n', '15', 'ionice', '-c', '3']


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_SERVER_CLEANUP_' + reason)


def on_disk(path):
    """(entries, bytes) of a folder or file, never following links; None when it is not there."""
    if not os.path.lexists(str(path)):
        return None
    if not path.is_dir() or path.is_symlink():
        return 1, os.lstat(str(path)).st_size
    entries, size = 1, 0
    for folder, folders, files in os.walk(str(path)):
        for name in folders + files:
            entries += 1
            try:
                size += os.lstat(os.path.join(folder, name)).st_size
            except OSError:
                pass
    return entries, size


def archive_names(archive, wanted):
    """For each wanted name: how many archive entries are it or under it. Reads the whole listing (no early stop)."""
    counts = {name: 0 for name in wanted}
    listing = subprocess.Popen(LOW + ['tar', '-tzf', str(archive)], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL)
    for raw in listing.stdout:
        entry = raw.rstrip(b'\n').decode('utf-8', 'surrogateescape').rstrip('/')
        for name in wanted:
            if entry == name or entry.startswith(name + '/'):
                counts[name] += 1
    listing.stdout.close()
    need(listing.wait() == 0, 'BACKUP_ARCHIVE_UNREADABLE: ' + archive.name)
    return counts


def targets():
    """(path on disk, the backup's archive holding it, its name in that archive, is a folder). The archives hold the
    home folder as fasakha/... and the server state as var/lib/..."""
    found = [(SITE / name, 'home-current.tar.gz', 'fasakha/public_html/' + name, True) for name in SITE_FOLDERS]
    found.append((LIVE_UPDATES, 'server-runtime.tar.gz', 'var/lib/go-erp-live-updates', True))
    return found + [(SITE / name, 'home-current.tar.gz', 'fasakha/public_html/' + name, False) for name in SITE_FILES]


def megabytes(size):
    return '{:.1f} MB'.format(size / 1048576.0)


def plan():
    lines = ['GO_ERP_SERVER_CLEANUP_PLAN']
    total = 0
    for path, archive, name, folder in targets():
        found = on_disk(path)
        if found is None:
            lines.append('already gone: {}'.format(path))
            continue
        total += found[1]
        lines.append('{}: {} {}, {}'.format(path, found[0], 'entries' if folder else 'file', megabytes(found[1])))
    lines.append('to free: ' + megabytes(total))
    return lines


def apply():
    manifest = json.loads((BACKUP / 'manifest.json').read_text())
    parts = {part['file']: part for part in manifest['parts']}
    present = [(path, archive, name, folder, on_disk(path)) for path, archive, name, folder in targets()]
    present = [item for item in present if item[4] is not None]
    lines, kept, freed = ['GO_ERP_SERVER_CLEANUP_APPLY'], [], 0
    for archive in sorted(set(item[1] for item in present)):
        need(archive in parts and (BACKUP / archive).stat().st_size == parts[archive]['bytes'], 'BACKUP_PART_CHANGED: ' + archive)
        counts = archive_names(BACKUP / archive, [item[2] for item in present if item[1] == archive])
        for path, _, name, folder, (entries, size) in (item for item in present if item[1] == archive):
            if counts[name] < entries:
                kept.append(path)
                lines.append('kept (the backup holds {} of its {} entries): {}'.format(counts[name], entries, path))
                continue
            if folder:
                shutil.rmtree(str(path))
            else:
                os.unlink(str(path))
            freed += size
            lines.append('removed: {} ({})'.format(path, megabytes(size)))
    lines.append('freed: ' + megabytes(freed))
    usage = shutil.disk_usage('/')
    lines.append('disk: {} free of {}'.format(megabytes(usage.free), megabytes(usage.total)))
    lines.append('GO_ERP_SERVER_CLEANUP_DONE' if not kept else 'GO_ERP_SERVER_CLEANUP_PARTIAL ({} kept)'.format(len(kept)))
    return lines, not kept


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--apply', action='store_true')
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    need((BACKUP / 'manifest.json').is_file(), 'BACKUP_NOT_FOUND')
    if options.apply:
        lines, ok = apply()
    else:
        lines, ok = plan(), True
    print('\n'.join(lines), flush=True)
    if not ok:
        raise SystemExit(1)


if __name__ == '__main__':
    try:
        main()
    except Stop as error:
        print(str(error), flush=True)
        raise SystemExit(1)
    except Exception as error:  # noqa: BLE001
        print('GO_ERP_SERVER_CLEANUP_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
