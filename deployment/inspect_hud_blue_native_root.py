#!/usr/bin/env python3
"""Read bounded native rollout metadata after the server solar UI update."""
import fcntl
import json
import os
from pathlib import Path
import re
import socket
import stat

WORK = Path('/var/lib/go-erp-live-updates')
CONFIG = Path('/etc/go-erp/live-updates.json')
FEED = Path('/home/fasakha/public_html/storage/app/desktop-updates/current.json')
SAFE = 9007199254740991
STATUSES = frozenset(('prepared', 'waiting-ci', 'retry-build', 'blocked', 'published'))


class Unavailable(Exception):
    pass


def need(value):
    if not value:
        raise Unavailable()


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
        info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def parents_are_directories(path):
    for parent in path.parents:
        need(stat.S_ISDIR(parent.lstat().st_mode))


def read_regular(path, limit, modes, optional=False):
    path = Path(path)
    need(path.is_absolute())
    try:
        parents_are_directories(path)
        before = path.lstat()
    except FileNotFoundError:
        if optional:
            return None
        raise Unavailable() from None
    need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid, before.st_nlink) == (0, 0, 1)
        and stat.S_IMODE(before.st_mode) in modes and 0 < before.st_size <= limit)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        need(identity(os.fstat(fd)) == identity(before))
        chunks, total = [], 0
        while total <= limit:
            block = os.read(fd, min(65536, limit + 1 - total))
            if not block:
                break
            chunks.append(block)
            total += len(block)
        need(total == before.st_size and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()))
        return b''.join(chunks), identity(before)
    finally:
        os.close(fd)


def parse(raw):
    def unique(pairs):
        value = {}
        for key, item in pairs:
            need(key not in value)
            value[key] = item
        return value
    def nonfinite(_):
        raise Unavailable()
    value = json.loads(raw, object_pairs_hook=unique, parse_constant=nonfinite)
    need(isinstance(value, dict))
    return value


def integer(value, minimum=0, maximum=SAFE):
    need(type(value) is int and minimum <= value <= maximum)
    return value


def sha(value, size=40, optional=False):
    if optional and value is None:
        return None
    need(isinstance(value, str) and re.fullmatch(r'[a-f0-9]{' + str(size) + '}', value) is not None)
    return value


def version(value, optional=False):
    if optional and value is None:
        return None
    need(isinstance(value, str) and len(value) <= 40)
    match = re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)', value)
    need(match is not None)
    integer(int(match[1]), 1)
    return value


def source(value):
    need(isinstance(value, dict) and set(value) == {'format', 'files', 'framework', 'sha256'})
    need(type(value['format']) is int and value['format'] == 1
        and isinstance(value['framework'], str)
        and re.fullmatch(r'[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}', value['framework']) is not None)
    return {'format': 1, 'files': integer(value['files'], 1, 20000),
        'framework': value['framework'], 'sha256': sha(value['sha256'], 64)}


def release(value):
    need(isinstance(value, dict))
    return {'version': version(value.get('version')), 'source_revision': sha(value.get('source_revision')),
        'source': source(value.get('source')), 'sequence': integer(value.get('sequence'), 1)}


def desired(value):
    if value is None:
        return None
    need(isinstance(value, dict) and value.get('status') in STATUSES and type(value.get('pushed')) is bool)
    return {'commit': sha(value.get('commit')), 'version': version(value.get('version')),
        'status': value['status'], 'inventory_sha256': sha(value.get('inventorySha256'), 64),
        'pushed': value['pushed']}


def published(value):
    if value is None:
        return None
    need(isinstance(value, dict))
    return {'commit': sha(value.get('commit')), 'inventory_sha256': sha(value.get('inventorySha256'), 64),
        'release': release(value.get('release'))}


def hold(value):
    if value is None:
        return None
    need(value.get('kind') == 'go-erp-program-rollout-hold'
        and type(value.get('format')) is int and value['format'] == 1)
    authorization = value.get('authorization')
    need(authorization is None or isinstance(authorization, dict))
    candidate = None
    if authorization is not None:
        candidate = sha(authorization.get('candidate'))
        version(authorization.get('version'))
    return {'core': sha(value.get('core')), 'source': source(value.get('source')),
        'inventory_sha256': sha(value.get('inventory_sha256'), 64),
        'authorized': authorization is not None, 'authorization_candidate': candidate}


def inspect(work=WORK, config=CONFIG, feed=FEED):
    work, config, feed = Path(work), Path(config), Path(feed)
    need(work.is_absolute())
    try:
        directory = work.lstat()
    except FileNotFoundError:
        return {'available': False, 'seed': None}
    parents_are_directories(work)
    need(stat.S_ISDIR(directory.st_mode) and (directory.st_uid, directory.st_gid) == (0, 0)
        and stat.S_IMODE(directory.st_mode) == 0o700)
    lock_path = work / 'watcher.lock'
    lock = os.open(lock_path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        lock_info = os.fstat(lock)
        need(stat.S_ISREG(lock_info.st_mode) and (lock_info.st_uid, lock_info.st_gid, lock_info.st_nlink,
            stat.S_IMODE(lock_info.st_mode)) == (0, 0, 1, 0o600))
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        records = ((config, 16384, (0o600,), False),
            (work / 'state/current.json', 16 * 1024**2, (0o600,), False),
            (work / 'program-rollout-hold.json', 16384, (0o600,), True),
            (feed, 65536, (0o600, 0o644), True))
        before = [read_regular(*record) for record in records]
        cfg, state, held, public = [parse(record[0]) if record is not None else None for record in before]
        seed = sha(cfg.get('base_revision'))
        need(type(state.get('format')) is int and state['format'] == 1
            and sha(state.get('base_revision')) == seed)
        result = {'available': True, 'seed': seed, 'counter': integer(state.get('counter')),
            'last_remote_sha': sha(state.get('last_remote_sha'), optional=True),
            'version_floor': version(state.get('version_floor'), optional=True),
            'sequence_floor': integer(state.get('sequence_floor', 0)),
            'desired': desired(state.get('desired')), 'published': published(state.get('published')),
            'hold': hold(held), 'feed': release(public) if public is not None else None}
        need(before == [read_regular(*record) for record in records]
            and identity(os.fstat(lock)) == identity(lock_path.lstat()) == identity(lock_info)
            and identity(work.lstat()) == identity(directory))
        return result
    finally:
        os.close(lock)


def main():
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524')
        result = inspect()
        print('HUD_BLUE_NATIVE_STATE ' + json.dumps(result, sort_keys=True, allow_nan=False))
        return 0
    except BaseException:
        print('HUD_BLUE_NATIVE_STATE_UNAVAILABLE')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
