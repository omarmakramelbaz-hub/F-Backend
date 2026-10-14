#!/usr/bin/env python3
"""Install only the reviewed order editor loading feedback and order rejection notice JavaScript."""
import argparse
import difflib
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
BASELINES = {
    'public/dashboard/js/order-board-items.js': '74a0070907d9ffbaa0f3b5aa7b6082bc1c345878',
    'public/dashboard/js/order-board.js': '5287afa43a603dd4a200498bc28cd283c683001b',
}
PUBLISH_ORDER = tuple(BASELINES)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def matches(lines, block, at_start=False, at_end=False):
    if not block:
        return []
    size = len(block)
    return [i for i in range(len(lines) - size + 1)
            if (not at_start or i == 0) and (not at_end or i + size == len(lines))
            and lines[i:i + size] == block]


def surgical_patch(current, baseline, reviewed, label):
    """Apply uniquely anchored eight-line-context hunks, refusing conflicting edits."""
    if baseline == reviewed:
        raise RuntimeError('RELEASE_CHANGE_MISSING: ' + label)
    if current == reviewed:
        return current
    old, new, live = [content.splitlines(keepends=True) for content in (baseline, reviewed, current)]
    for group in difflib.SequenceMatcher(a=old, b=new, autojunk=False).get_grouped_opcodes(8):
        a0, a1 = group[0][1], group[-1][2]
        b0, b1 = group[0][3], group[-1][4]
        before, after = old[a0:a1], new[b0:b1]
        start, end = a0 == 0, a1 == len(old)
        pre, post = matches(live, before, start, end), matches(live, after, start, end)
        if len(post) == 1 and (not pre or (len(pre) == 1 and len(after) > len(before)
                and post[0] <= pre[0] and pre[0] + len(before) <= post[0] + len(after))):
            continue
        if len(pre) == 1 and (not post or (len(post) == 1 and len(before) > len(after)
                and pre[0] <= post[0] and post[0] + len(after) <= pre[0] + len(before))):
            live[pre[0]:pre[0] + len(before)] = after
            continue
        raise RuntimeError('SOURCE_PATCH_CONFLICT: ' + label)
    return b''.join(live)


def safe(path):
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise RuntimeError('SYMLINK_PATH: ' + str(path))


def snapshot(path, uid):
    safe(path)
    if not path.exists():
        return None
    meta = path.stat()
    if not stat.S_ISREG(meta.st_mode) or meta.st_uid not in (0, uid) or meta.st_mode & 0o7022:
        raise RuntimeError('UNSAFE_FILE: ' + str(path))
    return {'bytes': path.read_bytes(), 'uid': meta.st_uid, 'gid': meta.st_gid,
            'mode': stat.S_IMODE(meta.st_mode), 'device': meta.st_dev, 'inode': meta.st_ino,
            'mtime': meta.st_mtime_ns, 'links': meta.st_nlink}


def atomic(path, data, mode, uid, gid):
    safe(path)
    if not path.parent.is_dir():
        raise RuntimeError('PARENT_DIRECTORY_MISSING: ' + str(path.parent))
    fd, temporary = tempfile.mkstemp(prefix='.order-editor-feedback-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def owned_command(root, arguments):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', *arguments], cwd=root, capture_output=True)
    if result.returncode:
        raise RuntimeError('APPLICATION_COMMAND_FAILED: ' + pathlib.Path(arguments[0]).name)
    return result.stdout


def source(root, revision, relative):
    data = owned_command(root, ['git', '-C', str(root), 'show', revision + ':' + relative])
    expected = owned_command(root, ['git', '-C', str(root), 'rev-parse', revision + ':' + relative]).decode().strip()
    if not re.fullmatch('[a-f0-9]{40}', expected) or blob(data) != expected:
        raise RuntimeError('SOURCE_BLOB_MISMATCH: ' + relative)
    return data


def install(root, release, check_only=False):
    if os.geteuid() != 0 or root != APP_ROOT or not re.fullmatch('[a-f0-9]{40}', release):
        raise RuntimeError('FIXED_ROOT_RELEASE_REQUIRED')
    safe(root)
    user = pwd.getpwnam('fasakha')
    uid = user.pw_uid
    if root.stat().st_uid != uid:
        raise RuntimeError('APPLICATION_OWNER_REQUIRED')
    if check_only:
        return _install_locked(root, release, True, uid)
    lock_path = root.parent / '.order-editor-feedback-update.lock'
    safe(lock_path)
    lock = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        meta = os.fstat(lock)
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_nlink != 1 or stat.S_IMODE(meta.st_mode) != 0o600:
            raise RuntimeError('UPDATE_LOCK_UNSAFE')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return _install_locked(root, release, False, uid)
    finally:
        os.close(lock)


def _install_locked(root, release, check_only, uid):
    originals, targets = {}, {}
    for relative, baseline in BASELINES.items():
        old = snapshot(root / relative, uid)
        if old is None:
            raise RuntimeError('BASELINE_FILE_MISSING: ' + relative)
        reviewed = source(root, release, relative)
        targets[relative] = surgical_patch(old['bytes'], source(root, baseline, relative), reviewed, relative)
        originals[relative] = old
    if check_only:
        print(json.dumps({'status': 'ORDER_EDITOR_FEEDBACK_PREFLIGHT_OK', 'files': len(targets)}))
        return
    parent = root.parent / 'order-editor-feedback-backups'
    safe(parent)
    if parent.exists() and (not parent.is_dir() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o077):
        raise RuntimeError('BACKUP_PARENT_UNSAFE')
    parent.mkdir(mode=0o700, exist_ok=True)
    backup = pathlib.Path(tempfile.mkdtemp(prefix=release[:12] + '-', dir=parent))
    os.chmod(backup, 0o700)
    manifest = {'release': release, 'baselines': BASELINES, 'files': {},
                'target_blobs': {relative: blob(payload) for relative, payload in targets.items()}}
    for relative, old in originals.items():
        manifest['files'][relative] = {k: v for k, v in old.items() if k != 'bytes'}
        saved = backup / relative
        saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        saved.write_bytes(old['bytes'])
        os.chmod(saved, 0o600)
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    os.chmod(backup / 'manifest.json', 0o600)
    written = {}
    try:
        for relative, old in originals.items():
            if snapshot(root / relative, uid) != old:
                raise RuntimeError('CONCURRENT_EDIT: ' + relative)
        for relative in PUBLISH_ORDER:
            old, payload = originals[relative], targets[relative]
            if snapshot(root / relative, uid) != old:
                raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            if old['bytes'] != payload:
                atomic(root / relative, payload, old['mode'], old['uid'], old['gid'])
                written[relative] = snapshot(root / relative, uid)
        for relative, published in written.items():
            if snapshot(root / relative, uid) != published:
                raise RuntimeError('CONCURRENT_EDIT: ' + relative)
        print(json.dumps({'status': 'ORDER_EDITOR_FEEDBACK_READY', 'updated_files': len(written),
                          'backup': str(backup), 'release': release}))
    except BaseException:
        complete = True
        for relative, published in reversed(list(written.items())):
            try:
                if snapshot(root / relative, uid) != published:
                    complete = False
                    continue
                old = originals[relative]
                atomic(root / relative, old['bytes'], old['mode'], old['uid'], old['gid'])
            except (OSError, RuntimeError):
                complete = False
        print('ORDER_EDITOR_FEEDBACK_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'))
        raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--check', action='store_true', help='Read-only source/permission/patch preflight without locks, backups or application writes')
    args = parser.parse_args()
    try:
        install(pathlib.Path(args.root).absolute(), args.release, args.check)
    except Exception as error:
        print(str(error))
        raise SystemExit(1)
