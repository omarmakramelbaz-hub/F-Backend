#!/usr/bin/env python3
"""Review/apply one pinned importer file; never bootstrap PHP or change the watcher.

Dry-run is the default. Package this file, the exact adjacent fingerprint helper,
and the reviewed importer in their repository-relative paths. Only --apply writes
application code. A watcher lock conflict returns 75 and leaves its timer alone.
"""
import argparse
import contextlib
import ctypes
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import socket
import stat
import subprocess
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
TARGET = Path('app/Services/Dashboard/DesktopDashboardImport.php')
SCANNER = Path('deployment/refresh_desktop_dashboard_server_manifest.py')
SCANNER_SHA = '2712b88a5c09fa1af0f18b33d4df9992db3505346356a521528cb4a54d4804d4'
OLD_SHA = 'b7b8aae60d3406850e7b765fe5c3e6710d6e01423884d46fca7747f26da701cc'
NEW_SHA = 'e50fdeb5f16fb9bb5b37527bed30dc8ab8a9127fa3d7f013de4a4f7063f119db'
BEFORE_SOURCE = {'format': 1, 'files': 1326, 'framework': '8.83.29',
                 'sha256': '5f09c4ecc61909e4fe4cbc313b0ea31349e676a70d47415d6047c25ad9fba98a'}
AFTER_SOURCE = {'format': 1, 'files': 1326, 'framework': '8.83.29',
                'sha256': '08e84b198c6bbbd4d917226f96b73b6762f1d3a9d2a34d56446defab2f7ab44b'}
PREFIX = 'import-memory-deploy-'
LIMIT = 8 * 1024 * 1024


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop(reason)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def key(item):
    # renameat2 changes ctime; retain inode, contents, owner/mode and mtime.
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns)


def exact(item):
    return (*key(item), item.st_ctime_ns)


def dirkey(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid)


class Directory:
    def __init__(self, path, private=False):
        self.path = Path(path).absolute()
        self.rows = []
        need('..' not in self.path.parts, 'DIRECTORY_UNSAFE')
        fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.rows.append((fd, os.fstat(fd), None, None))
        try:
            for name in self.path.parts[1:]:
                parent = fd
                fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                self.rows.append((fd, os.fstat(fd), parent, name))
            self.fd = fd
            if private:
                item = os.fstat(fd)
                need((item.st_uid, item.st_gid, stat.S_IMODE(item.st_mode)) == (0, 0, 0o700),
                     'PRIVATE_DIRECTORY_UNSAFE')
            self.verify()
        except BaseException:
            self.close()
            raise

    def verify(self):
        for fd, item, parent, name in self.rows:
            need(dirkey(os.fstat(fd)) == dirkey(item), 'DIRECTORY_CHANGED')
            if parent is not None:
                need(dirkey(os.stat(name, dir_fd=parent, follow_symlinks=False)) == dirkey(item),
                     'DIRECTORY_CHANGED')

    def close(self):
        for fd, *_ in reversed(self.rows):
            os.close(fd)
        self.rows = []


def read_at(directory, name, root_owned=False):
    directory.verify()
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
             and 0 < before.st_size <= LIMIT and not before.st_mode & 0o7000
             and not before.st_mode & 0o022, 'FILE_UNSAFE')
        if root_owned:
            need((before.st_uid, before.st_gid) == (0, 0), 'PACKAGE_OWNER_INVALID')
        data = bytearray()
        while len(data) <= LIMIT:
            part = os.read(fd, min(65536, LIMIT + 1 - len(data)))
            if not part:
                break
            data.extend(part)
        need(len(data) == before.st_size and exact(before) == exact(os.fstat(fd)) ==
             exact(os.stat(name, dir_fd=directory.fd, follow_symlinks=False)), 'FILE_CHANGED')
        directory.verify()
        return bytes(data), before
    finally:
        os.close(fd)


def write_at(directory, name, data, owner=(0, 0), mode=0o600):
    directory.verify()
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600,
                 dir_fd=directory.fd)
    try:
        offset = 0
        while offset < len(data):
            count = os.write(fd, data[offset:])
            need(count > 0, 'WRITE_FAILED')
            offset += count
        os.fchown(fd, *owner)
        os.fchmod(fd, mode)
        os.fsync(fd)
    finally:
        os.close(fd)
    os.fsync(directory.fd)


def json_at(directory, name, value):
    # A crash must never leave a partial fixed-name intent/commit marker.
    temporary = '.record-' + uuid.uuid4().hex + '.tmp'
    write_at(directory, temporary, (json.dumps(value, sort_keys=True) + '\n').encode())
    os.link(temporary, name, src_dir_fd=directory.fd, dst_dir_fd=directory.fd,
            follow_symlinks=False)
    os.fsync(directory.fd)
    os.unlink(temporary, dir_fd=directory.fd)
    os.fsync(directory.fd)


@contextlib.contextmanager
def watcher_lock(work):
    directory = Directory(work, private=True)
    fd = None
    try:
        fd = os.open('watcher.lock', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                     dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and
             (before.st_uid, before.st_gid, before.st_nlink, stat.S_IMODE(before.st_mode)) ==
             (0, 0, 1, 0o600), 'LOCK_UNSAFE')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('WATCHER_BUSY') from None

        def guard():
            directory.verify()
            need(exact(before) == exact(os.fstat(fd)) ==
                 exact(os.stat('watcher.lock', dir_fd=directory.fd, follow_symlinks=False)),
                 'LOCK_CHANGED')
        guard()
        yield guard, directory
        guard()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()


def exchange(directory, left, right):
    directory.verify()
    libc = ctypes.CDLL(None, use_errno=True)
    function = libc.renameat2
    function.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    function.restype = ctypes.c_int
    if function(directory.fd, os.fsencode(left), directory.fd, os.fsencode(right), 2):
        raise OSError(ctypes.get_errno(), 'atomic exchange failed')


@contextlib.contextmanager
def critical():
    previous = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGINT, signal.SIGTERM, signal.SIGHUP})
    try:
        yield
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous)


def load_scanner(review_root):
    directory = Directory(review_root / SCANNER.parent)
    try:
        data, _ = read_at(directory, SCANNER.name, root_owned=True)
        need(sha(data) == SCANNER_SHA, 'SCANNER_PIN_INVALID')
    finally:
        directory.close()
    module = types.ModuleType('reviewed_source_fingerprint')
    exec(compile(data, str(review_root / SCANNER), 'exec'), module.__dict__)
    return module


def cohort(scanner, live):
    first = scanner.scan(live)
    need(scanner.scan(live) == first, 'SOURCE_CHANGED_DURING_SCAN')
    return scanner.digest(first)


def php_lint(data):
    environment = {'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'}
    for arguments, input_data in [(['-n', '-r', 'exit(PHP_VERSION_ID>=80200&&PHP_VERSION_ID<80300?0:1);'], b''),
                                  (['-n', '-l'], data)]:
        result = subprocess.run([PHP, *arguments], input=input_data, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=30, env=environment)
        need(result.returncode == 0, 'PHP82_LINT_FAILED')


def deploy(review_root, live=ROOT, work=WORK, apply=False, lint=None, before_swap=None,
           after_swap=None, web_uid=1001):
    """Internal path/hooks are for isolated tests; CLI fixes all live paths."""
    report = {'format': 1, 'kind': 'go-erp-import-memory-server', 'ok': False,
              'dry_run': not apply, 'applied': False, 'already_current': False,
              'rollback_complete': True, 'manual_review': False,
              'OfflineReady': False, 'WatcherAcceptance': 'pending',
              'database_changed': False, 'watcher_changed': False, 'runtime_configuration_read': False,
              'credentials_read': False, 'before_source': BEFORE_SOURCE, 'after_source': AFTER_SOURCE}
    parent = audit = live_directory = None
    stage = None
    wanted_item = displaced_item = None
    displaced_bytes = None
    swapped = committed = False
    phase = 'PREFLIGHT'
    lock_manager = None
    lock_entered = False
    try:
        review_root, live, work = map(lambda value: Path(value).absolute(), (review_root, live, work))
        scanner = load_scanner(review_root)
        package = Directory(review_root / TARGET.parent)
        try:
            wanted, _ = read_at(package, TARGET.name, root_owned=True)
            need(sha(wanted) == NEW_SHA, 'PAYLOAD_PIN_INVALID')
        finally:
            package.close()
        (lint or php_lint)(wanted)
        lock_manager = watcher_lock(work)
        lock, work_directory = lock_manager.__enter__()
        lock_entered = True
        need(type(web_uid) is int and web_uid >= 0, 'WEB_OWNER_INVALID')
        live_directory = Directory(live)
        need(os.fstat(live_directory.fd).st_uid == web_uid, 'LIVE_ROOT_OWNER_INVALID')
        for name in os.listdir(work_directory.fd):
            if name.startswith(PREFIX):
                pending = Directory(work / name, private=True)
                try:
                    entries = set(os.listdir(pending.fd))
                    need('intent.json' not in entries or entries & {'committed.json', 'rolled-back.json'},
                         'PENDING_AUDIT_REQUIRES_REVIEW')
                finally:
                    pending.close()
        parent = Directory(live / TARGET.parent)
        before, before_item = read_at(parent, TARGET.name)
        need(before_item.st_uid in (0, web_uid), 'TARGET_OWNER_INVALID')
        source = cohort(scanner, live)
        lock(); parent.verify()
        if sha(before) == NEW_SHA:
            need(source == AFTER_SOURCE, 'AFTER_COHORT_MISMATCH')
            report.update(ok=True, already_current=True, phase='DONE')
            return report
        need(sha(before) == OLD_SHA, 'BEFORE_FILE_MISMATCH')
        need(source == BEFORE_SOURCE, 'BEFORE_COHORT_MISMATCH')
        if not apply:
            report.update(ok=True, phase='DRY_RUN')
            return report
        phase = 'AUDIT'
        name = PREFIX + uuid.uuid4().hex
        os.mkdir(name, 0o700, dir_fd=work_directory.fd); os.fsync(work_directory.fd)
        audit = Directory(work / name, private=True)
        report['audit_directory'] = str(audit.path)
        write_at(audit, 'before-importer.php', before)
        write_at(audit, 'wanted-importer.php', wanted)
        stage = '.go-erp-import-memory-' + uuid.uuid4().hex + '.tmp'
        write_at(parent, stage, wanted, (before_item.st_uid, before_item.st_gid),
                 stat.S_IMODE(before_item.st_mode))
        staged, wanted_item = read_at(parent, stage)
        need(staged == wanted, 'STAGED_FILE_CHANGED')
        json_at(audit, 'intent.json', {'format': 1, 'path': TARGET.as_posix(),
            'stage_name': stage, 'before_sha256': OLD_SHA, 'after_sha256': NEW_SHA,
            'before_source': BEFORE_SOURCE, 'after_source': AFTER_SOURCE,
            'before_identity': list(key(before_item)), 'wanted_identity': list(key(wanted_item))})
        phase = 'COMPARE_AND_SWAP'
        if before_swap is not None:
            before_swap()
        lock(); parent.verify(); audit.verify()
        current, current_item = read_at(parent, TARGET.name)
        need(current == before and exact(current_item) == exact(before_item), 'CAS_REFUSED')
        need(cohort(scanner, live) == BEFORE_SOURCE, 'SOURCE_CHANGED_BEFORE_SWAP')
        lock()
        current, current_item = read_at(parent, TARGET.name)
        staged, staged_item = read_at(parent, stage)
        need(current == before and exact(current_item) == exact(before_item), 'CAS_REFUSED')
        need(staged == wanted and exact(staged_item) == exact(wanted_item), 'STAGED_FILE_CHANGED')
        with critical():
            exchange(parent, stage, TARGET.name)
            swapped = True
            os.fsync(parent.fd)
            displaced_bytes, displaced_item = read_at(parent, stage)
            need(displaced_bytes == before and key(displaced_item) == key(before_item), 'CAS_DISPLACED_CHANGED')
        report['applied'] = True
        if after_swap is not None:
            after_swap()
        phase = 'POSTFLIGHT'
        current, current_item = read_at(parent, TARGET.name)
        need(current == wanted and key(current_item) == key(wanted_item), 'WANTED_FILE_CHANGED')
        need(cohort(scanner, live) == AFTER_SOURCE, 'AFTER_COHORT_MISMATCH')
        lock(); parent.verify(); audit.verify()
        current, current_item = read_at(parent, TARGET.name)
        need(current == wanted and key(current_item) == key(wanted_item), 'WANTED_FILE_CHANGED')
        with critical():
            json_at(audit, 'committed.json', {'format': 1, 'source': AFTER_SOURCE,
                'file_sha256': NEW_SHA, 'OfflineReady': False, 'WatcherAcceptance': 'pending'})
            committed = True
        displaced, item = read_at(parent, stage)
        need(displaced == before and key(item) == key(displaced_item), 'STAGING_CLEANUP_REFUSED')
        os.unlink(stage, dir_fd=parent.fd); os.fsync(parent.fd); stage = None
        report.update(ok=True, phase='DONE')
        return report
    except BaseException as error:
        reason = str(error) if isinstance(error, Stop) and re.fullmatch('[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        report.update(phase=phase, reason=reason)
        if swapped and not committed:
            try:
                lock()
                current, item = read_at(parent, TARGET.name)
                need(current == wanted and key(item) == key(wanted_item), 'ROLLBACK_CURRENT_CHANGED')
                displaced, held = read_at(parent, stage)
                # A racer displaced at the CAS boundary must also be preserved.
                if displaced_item is not None:
                    need(key(held) == key(displaced_item) and displaced == displaced_bytes,
                         'ROLLBACK_BACKUP_CHANGED')
                with critical():
                    exchange(parent, stage, TARGET.name)
                    os.fsync(parent.fd)
                restored, restored_item = read_at(parent, TARGET.name)
                removed, removed_item = read_at(parent, stage)
                need(restored == displaced and key(restored_item) == key(held)
                     and removed == wanted and key(removed_item) == key(wanted_item),
                     'ROLLBACK_EXCHANGE_RACED')
                report.update(applied=False, rollback_complete=True)
                json_at(audit, 'rolled-back.json', {'format': 1, 'restored_sha256': sha(restored)})
            except BaseException:
                # Do not try another exchange: retain every displaced version.
                report.update(rollback_complete=False, applied=True, manual_review=True,
                              staging_preserved=True)
        if stage and parent is not None and report['rollback_complete']:
            try:
                data, item = read_at(parent, stage)
                need(data == wanted and key(item) == key(wanted_item), 'STAGING_CLEANUP_REFUSED')
                os.unlink(stage, dir_fd=parent.fd); os.fsync(parent.fd)
            except BaseException:
                report.update(staging_preserved=True, manual_review=True)
        if reason == 'PENDING_AUDIT_REQUIRES_REVIEW':
            report['manual_review'] = True
        if audit is not None:
            try:
                if not swapped:
                    json_at(audit, 'rolled-back.json', {'format': 1, 'exchanges_started': False})
                json_at(audit, 'failed-' + uuid.uuid4().hex + '.json', report)
            except BaseException:
                report['audit_write_failed'] = True
        return report
    finally:
        if lock_entered:
            try:
                lock_manager.__exit__(None, None, None)
            except BaseException:
                report.update(ok=False, phase='LOCK_RELEASE', reason='LOCK_CHANGED')
        if audit is not None:
            audit.close()
        if parent is not None:
            parent.close()
        if live_directory is not None:
            live_directory.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--review-root', type=Path, default=Path(__file__).absolute().parent.parent)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    try:
        web_uid = pwd.getpwnam('fasakha').pw_uid
    except KeyError:
        raise Stop('WEB_ACCOUNT_MISSING') from None
    report = deploy(args.review_root, apply=args.apply, web_uid=web_uid)
    print(json.dumps(report, sort_keys=True))
    return 0 if report['ok'] else 75 if report.get('reason') == 'WATCHER_BUSY' else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Stop as error:
        print(json.dumps({'ok': False, 'reason': str(error), 'OfflineReady': False,
                          'WatcherAcceptance': 'pending'}, sort_keys=True))
        raise SystemExit(1)
