#!/usr/bin/env python3
"""Run one owner-requested GO ERP release through the original proof gates.

The web account supplies only an audited nonce/actor/time request. This process
never executes a web-supplied command and never enables the minute timer.
"""
import argparse
import contextlib
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
import time
import types
import uuid

BASE = Path('/var/lib/go-erp-manual-updates')
NATIVE = Path('/var/lib/go-erp-live-updates/state/current.json')
FEED = Path('/home/fasakha/public_html/storage/app/desktop-updates/current.json')
TICK_SHA256 = '5f7fca1028d5f2763f614189d79ea5998668de837452daa6cc1a1cd0dac35104'
DOWNLOAD_ARCHIVE_SHA256 = '2b5f5adf102e7d0e149b1f05c52ae4207e298d26daa3140c409bddddbf60e17b'
SAFE = 9007199254740991
ACTIVE = frozenset(('queued', 'running', 'waiting-ci', 'publishing'))
STATES = ACTIVE | {'idle', 'ready', 'failed'}
NONCE = re.compile(r'[a-f0-9]{32}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
VERSION = re.compile(r'0\.4\.4-preview\.[1-9][0-9]{0,14}\Z')
CODE = re.compile(r'GO_ERP_[A-Z0-9_]{1,100}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_MANUAL_' + code)


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
        info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def parse(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            need(key not in result, 'JSON_INVALID')
            result[key] = value
        return result
    def nonfinite(_):
        raise Stop('GO_ERP_MANUAL_JSON_INVALID')
    value = json.loads(raw, object_pairs_hook=pairs, parse_constant=nonfinite)
    need(isinstance(value, dict), 'JSON_INVALID')
    return value


def read(path, maximum=16384, uid=0, gid=None, modes=(0o600,), optional=False):
    try:
        before = path.lstat()
    except FileNotFoundError:
        if optional:
            return None
        raise
    need(stat.S_ISREG(before.st_mode) and before.st_uid == uid
        and (gid is None or before.st_gid == gid) and before.st_nlink == 1
        and stat.S_IMODE(before.st_mode) in modes and 0 < before.st_size <= maximum,
        'FILE_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        need(identity(before) == identity(os.fstat(fd)), 'FILE_CHANGED')
        chunks, total = [], 0
        while total <= maximum:
            chunk = os.read(fd, min(65536, maximum + 1 - total))
            if not chunk:
                break
            total += len(chunk)
            chunks.append(chunk)
        need(total == before.st_size
            and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()), 'FILE_CHANGED')
        return b''.join(chunks)
    finally:
        os.close(fd)


def atomic(path, value, gid=0, mode=0o600):
    # Every caller supplies a file in an already verified root-owned directory.
    raw = json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode() + b'\n'
    need(len(raw) <= 16384, 'JSON_LIMIT')
    current = path.lstat() if path.exists() or path.is_symlink() else None
    if current is not None:
        need(stat.S_ISREG(current.st_mode) and current.st_uid == 0 and current.st_nlink == 1
            and not current.st_mode & 0o022, 'OUTPUT_UNSAFE')
    temporary = path.parent / ('.manual-' + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw)
            stream.flush()
            os.fchown(stream.fileno(), 0, gid)
            os.fchmod(stream.fileno(), mode)
            os.fsync(stream.fileno())
        if current is not None:
            need(identity(path.lstat()) == identity(current), 'OUTPUT_CHANGED')
        else:
            need(not path.exists() and not path.is_symlink(), 'OUTPUT_CHANGED')
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if temporary.exists():
            temporary.unlink()


def directory(path, uid, gid, mode):
    need(path.is_absolute() and path.resolve() == path, 'DIRECTORY_UNSAFE')
    for parent in path.parents:
        need(stat.S_ISDIR(parent.lstat().st_mode), 'DIRECTORY_UNSAFE')
    info = path.lstat()
    need(stat.S_ISDIR(info.st_mode) and (info.st_uid, info.st_gid,
        stat.S_IMODE(info.st_mode)) == (uid, gid, mode), 'DIRECTORY_UNSAFE')
    return info


def request(raw, now):
    value = parse(raw)
    need(set(value) == {'format', 'kind', 'nonce', 'actor_id', 'requested_at'}
        and type(value['format']) is int and value['format'] == 1
        and value['kind'] == 'go-erp-manual-release-request'
        and isinstance(value['nonce'], str) and NONCE.fullmatch(value['nonce'])
        and type(value['actor_id']) is int and 1 <= value['actor_id'] <= SAFE
        and type(value['requested_at']) is int
        and 0 <= value['requested_at'] <= SAFE and now - 900 <= value['requested_at'] <= now + 30,
        'REQUEST_INVALID')
    return value


def native_snapshot(state_path=NATIVE, feed_path=FEED):
    # Configuration and its token are deliberately not read by this broker.
    for path in (state_path, feed_path):
        for parent in path.parents:
            need(stat.S_ISDIR(parent.lstat().st_mode), 'NATIVE_PATH_UNSAFE')
    state = parse(read(state_path, maximum=16 * 1024**2))
    desired, published = state.get('desired'), state.get('published')
    need(desired is None or isinstance(desired, dict), 'NATIVE_STATE_INVALID')
    need(published is None or isinstance(published, dict), 'NATIVE_STATE_INVALID')
    result = {'candidate': None, 'inventory': None, 'version': None, 'status': None, 'ready': False}
    if desired is not None:
        need(isinstance(desired.get('commit'), str) and REV.fullmatch(desired['commit'])
            and isinstance(desired.get('inventorySha256'), str) and SHA.fullmatch(desired['inventorySha256'])
            and isinstance(desired.get('version'), str) and VERSION.fullmatch(desired['version'])
            and desired.get('status') in ('prepared', 'waiting-ci', 'retry-build', 'blocked', 'published'),
            'NATIVE_STATE_INVALID')
        result.update(candidate=desired['commit'], inventory=desired['inventorySha256'],
            version=desired['version'], status=desired['status'])
    raw = read(feed_path, maximum=512 * 1024, modes=(0o600, 0o644), optional=True)
    feed = parse(raw) if raw is not None else None
    if desired is not None and published is not None and feed is not None:
        result['ready'] = (desired['status'] == 'published'
            and published.get('commit') == desired['commit']
            and published.get('inventorySha256') == desired['inventorySha256']
            and published.get('release') == feed and feed.get('source_revision') == desired['commit']
            and feed.get('version') == desired['version'])
    return result


def invoke_tick(job):
    helper = Path(__file__).absolute().with_name('go_erp_manual_tick_root.py')
    for parent in helper.parents:
        info = parent.lstat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
            'RUNTIME_UNSAFE')
    raw = read(helper, maximum=256 * 1024, modes=(0o444,))
    need(hashlib.sha256(raw).hexdigest() == TICK_SHA256, 'TICK_PIN_INVALID')
    args = ['/usr/bin/python3', '-I', '-B', str(helper)]
    if job.get('_allow_prepare') is True:
        args += ['--allow-prepare']
    else:
        need(job['candidate'] is not None, 'ALLOCATION_REVIEW_REQUIRED')
        args += ['--candidate', job['candidate'], '--inventory', job['inventory']]
    result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, timeout=900, check=False)
    need(len(result.stdout) <= 16384 and len(result.stderr) <= 16384, 'TICK_OUTPUT_INVALID')
    codes = [line for line in (result.stdout + b'\n' + result.stderr).decode('ascii', errors='replace').splitlines()
        if CODE.fullmatch(line)]
    need(bool(codes), 'TICK_OUTPUT_INVALID')
    return result.returncode, codes[-1]


def archive_published(base=BASE, web_gid=None):
    """Keep successful installers independently of the changing live source."""
    helper = Path(__file__).absolute().with_name('go_erp_published_download_root.py')
    for parent in helper.parents:
        info = parent.lstat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
            'RUNTIME_UNSAFE')
    raw = read(helper, maximum=256 * 1024, modes=(0o444,))
    need(hashlib.sha256(raw).hexdigest() == DOWNLOAD_ARCHIVE_SHA256, 'DOWNLOAD_ARCHIVE_PIN_INVALID')
    module = types.ModuleType('go_erp_published_download_archive')
    module.__file__ = str(helper)
    exec(compile(raw, str(helper), 'exec'), module.__dict__)
    try:
        return module.archive_current(FEED.parents[3], NATIVE, Path(base) / 'shared', web_gid)
    except Exception as error:
        code = str(error)
        raise Stop(code if CODE.fullmatch(code) else 'GO_ERP_MANUAL_DOWNLOAD_ARCHIVE_FAILED') from None


class Broker:
    def __init__(self, base=BASE, web_uid=None, web_gid=None, clock=time.time,
            sleep=time.sleep, tick=invoke_tick, snapshot=native_snapshot, archive=archive_published):
        owner = pwd.getpwnam('fasakha') if web_uid is None or web_gid is None else None
        self.uid = owner.pw_uid if web_uid is None else web_uid
        self.gid = owner.pw_gid if web_gid is None else web_gid
        self.base, self.clock, self.sleep, self.tick, self.snapshot = Path(base), clock, sleep, tick, snapshot
        self.archive = archive
        self.private, self.shared, self.requests = (self.base / name for name in ('private', 'shared', 'requests'))
        self.jobs = self.private / 'jobs'
        self.active = self.private / 'active.json'
        self.pending = self.requests / 'pending.json'
        self.status_path = self.shared / 'status.json'

    def check_directories(self):
        directory(self.base, 0, 0, 0o755)
        directory(self.private, 0, 0, 0o700)
        directory(self.jobs, 0, 0, 0o700)
        directory(self.shared, 0, self.gid, 0o750)
        directory(self.requests, self.uid, self.gid, 0o700)

    def status(self, job, state, code=None):
        need(state in STATES and (code is None or CODE.fullmatch(code)), 'STATUS_INVALID')
        value = {'format': 1, 'kind': 'go-erp-manual-release-status', 'state': state,
            'nonce': job.get('nonce'), 'updated_at': int(self.clock()),
            'version': job.get('version'), 'code': code}
        atomic(self.status_path, value, gid=self.gid, mode=0o640)
        job['state'], job['code'] = state, code
        self.save(job)

    def save(self, job):
        atomic(self.jobs / (job['nonce'] + '.json'), job)
        if job['state'] in ACTIVE:
            atomic(self.active, job)

    def existing_job(self, path):
        raw = read(path, optional=True)
        if raw is None:
            return None
        job = parse(raw)
        need(isinstance(job.get('nonce'), str) and NONCE.fullmatch(job['nonce'])
            and job.get('state') in STATES
            and type(job.get('created_at')) is int and type(job.get('deadline')) is int
            and job['created_at'] + 7200 == job['deadline']
            and type(job.get('requested_at')) is int and type(job.get('actor_id')) is int
            and type(job.get('prepare_started')) is bool
            and type(job.get('transient_failures')) is int and 0 <= job['transient_failures'] <= 3
            and (job.get('baseline_candidate') is None or isinstance(job['baseline_candidate'], str)
                and REV.fullmatch(job['baseline_candidate'])),
            'JOB_INVALID')
        need((job.get('candidate') is None and job.get('inventory') is None and job.get('version') is None)
            or (isinstance(job.get('candidate'), str) and REV.fullmatch(job['candidate'])
                and isinstance(job.get('inventory'), str) and SHA.fullmatch(job['inventory'])
                and isinstance(job.get('version'), str) and VERSION.fullmatch(job['version'])), 'JOB_INVALID')
        return job

    @contextlib.contextmanager
    def request_lock(self):
        path = self.requests / 'request.lock'
        info = path.lstat()
        need(stat.S_ISREG(info.st_mode) and (info.st_uid, info.st_gid, info.st_nlink,
            stat.S_IMODE(info.st_mode)) == (self.uid, self.gid, 1, 0o600), 'REQUEST_LOCK_UNSAFE')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            deadline = time.monotonic() + 30
            while True:
                try:
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    need(time.monotonic() < deadline, 'REQUEST_BUSY')
                    time.sleep(0.05)
            need(identity(info) == identity(os.fstat(fd)) == identity(path.lstat()), 'REQUEST_LOCK_CHANGED')
            yield
            need(identity(info) == identity(os.fstat(fd)) == identity(path.lstat()), 'REQUEST_LOCK_CHANGED')
        finally:
            os.close(fd)

    def claim(self):
        with self.request_lock():
            try:
                raw = read(self.pending, maximum=4096, uid=self.uid, gid=self.gid, optional=True)
                if raw is None:
                    return None
                value = request(raw, int(self.clock()))
            except Exception:
                # Move the entry itself; never traverse an unsafe submitted path.
                if self.pending.exists() or self.pending.is_symlink():
                    os.rename(self.pending, self.private / ('rejected-' + uuid.uuid4().hex))
                raise Stop('GO_ERP_MANUAL_REQUEST_INVALID') from None
            previous = self.existing_job(self.jobs / (value['nonce'] + '.json'))
            if previous is not None:
                self.pending.unlink()
                if previous['state'] not in ACTIVE:
                    self.status(previous, previous['state'], previous.get('code'))
                    return None
                atomic(self.active, previous)
                return previous
            recent_raw = read(self.private / 'last-request.json', optional=True)
            recent = parse(recent_raw) if recent_raw is not None else None
            if recent is not None:
                need(type(recent.get('accepted_at')) is int and 0 <= recent['accepted_at'] <= SAFE,
                    'LEDGER_INVALID')
            now = int(self.clock())
            job = {**value, 'state': 'queued', 'code': None, 'created_at': now, 'deadline': now + 7200,
                'candidate': None, 'inventory': None, 'version': None, 'last_tick_at': None,
                'prepare_started': False, 'baseline_candidate': None, 'transient_failures': 0}
            # Record the nonce before consuming its only web-writable trigger.
            self.status(job, 'queued')
            self.pending.unlink()
            fd = os.open(self.requests, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
            if recent is not None:
                if now < recent['accepted_at'] + 300:
                    self.finish(job, 'failed', 'GO_ERP_MANUAL_COOLDOWN')
                    return None
            atomic(self.private / 'last-request.json', {'nonce': value['nonce'], 'accepted_at': now})
            return job

    def finish(self, job, state, code):
        self.status(job, state, code)
        active = self.existing_job(self.active)
        if active is not None:
            need(active['nonce'] == job['nonce'], 'ACTIVE_JOB_CHANGED')
            self.active.unlink()
            fd = os.open(self.private, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)

    def process(self, job):
        try:
            # Archive the last successful publication before a scan can remove
            # its current-feed pointer. This never allocates another build.
            self.archive(self.base, self.gid)
            while True:
                need(int(self.clock()) < job['deadline'], 'JOB_TIMED_OUT')
                self.check_directories()
                self.status(job, 'running' if job['candidate'] is None else 'waiting-ci')
                allow_prepare = False
                if job['candidate'] is None:
                    baseline = self.snapshot()
                    if job['prepare_started']:
                        # A child may have allocated before its parent crashed.
                        # Never issue a second allocation permit for that nonce.
                        need(baseline['candidate'] is not None and
                            (baseline['candidate'] != job['baseline_candidate'] or baseline['ready']),
                            'ALLOCATION_REVIEW_REQUIRED')
                        job.update(candidate=baseline['candidate'], inventory=baseline['inventory'],
                            version=baseline['version'])
                    else:
                        job['baseline_candidate'] = baseline['candidate']
                        job['prepare_started'] = True
                        allow_prepare = True
                    self.save(job)
                rc, code = self.tick({**job, '_allow_prepare': allow_prepare})
                need(type(rc) is int and isinstance(code, str) and CODE.fullmatch(code), 'TICK_OUTPUT_INVALID')
                now = int(self.clock())
                job['last_tick_at'] = now
                if rc == 0 and code == 'GO_ERP_LIVE_UPDATE_ALREADY_RUNNING':
                    # Original main did not construct Worker or enter tick.
                    if allow_prepare:
                        job['prepare_started'] = False
                    self.save(job)
                    self.status(job, 'waiting-ci')
                    self.sleep(5)
                    continue
                current = self.snapshot()
                if code != 'GO_ERP_LIVE_UPDATE_ALREADY_RUNNING' and current['candidate'] is not None:
                    if job['candidate'] is None:
                        job.update(candidate=current['candidate'], inventory=current['inventory'], version=current['version'])
                    need((job['candidate'], job['inventory'], job['version']) ==
                        (current['candidate'], current['inventory'], current['version']), 'CANDIDATE_CHANGED')
                self.save(job)
                if rc != 0:
                    if (rc == 1 and code == 'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT'
                            and job['candidate'] is not None
                            and (job['candidate'], job['inventory'], job['version']) ==
                                (current['candidate'], current['inventory'], current['version'])
                            and current['status'] in ('waiting-ci', 'prepared')
                            and job['transient_failures'] < 3):
                        # Only the original worker owns native failures/retry_at.
                        # The durable job counter bounds generic retries across
                        # broker restarts, and the candidate remains latched.
                        job['transient_failures'] += 1
                        self.status(job, 'waiting-ci', code)
                        self.sleep(30)
                        continue
                    raise Stop(code)
                if current['ready'] and code in ('GO_ERP_LIVE_UPDATE_CURRENT', 'GO_ERP_LIVE_UPDATE_PUBLISHED',
                        'GO_ERP_LIVE_UPDATE_PUBLISHED_STORAGE_REVIEW_REQUIRED'):
                    # Only a verified native publication advances the visible
                    # current/previous download pair; failed CI leaves it intact.
                    self.archive(self.base, self.gid)
                    self.finish(job, 'ready', 'GO_ERP_MANUAL_RELEASE_READY')
                    return
                need(current['status'] not in ('blocked', 'retry-build'), 'CI_REVIEW_REQUIRED')
                if code in ('GO_ERP_PROGRAM_PUBLICATION_HELD', 'GO_ERP_LIVE_UPDATE_PROGRAM_HELD',
                        'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED', 'GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW'):
                    raise Stop('GO_ERP_MANUAL_ACCEPTANCE_REVIEW_REQUIRED')
                self.status(job, 'publishing' if code == 'GO_ERP_LIVE_UPDATE_PUBLISHED' else 'waiting-ci')
                self.sleep(30)
        except BaseException as error:
            code = str(error) if isinstance(error, Stop) and CODE.fullmatch(str(error)) else 'GO_ERP_MANUAL_RELEASE_FAILED'
            self.finish(job, 'failed', code)
            if isinstance(error, (KeyboardInterrupt, SystemExit)):
                raise

    def run(self):
        self.check_directories()
        path = self.private / 'worker.lock'
        fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(fd)
            need(stat.S_ISREG(info.st_mode) and (info.st_uid, info.st_gid,
                info.st_nlink, stat.S_IMODE(info.st_mode)) == (0, 0, 1, 0o600), 'WORKER_LOCK_UNSAFE')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            job = self.existing_job(self.active)
            if job is not None and job['state'] not in ACTIVE:
                self.active.unlink()
                job = None
            job = job or self.claim()
            if job is not None:
                self.process(job)
            need(identity(info) == identity(os.fstat(fd)) == identity(path.lstat()), 'WORKER_LOCK_CHANGED')
        finally:
            os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', action='store_true', required=True)
    parser.parse_args()
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
        os.umask(0o077)
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(KeyboardInterrupt()))
        Broker().run()
        print('GO_ERP_MANUAL_REQUEST_COMPLETE')
        return 0
    except BaseException as error:
        code = str(error) if isinstance(error, Stop) and CODE.fullmatch(str(error)) else 'GO_ERP_MANUAL_REQUEST_FAILED'
        print(code)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
