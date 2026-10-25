#!/usr/bin/env python3
"""Remove only two observed, archived desktop rollback directories after proof checks."""
from decimal import Decimal
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import tarfile
import tempfile
import types

ALLOWLIST = ('desktop-dashboard-release-backups/server-k_fqknbp',
             'desktop-dashboard-release-backups/server-4cb99kno')
PINNED = {'create_all_apps_backup_root.py': 'c7f3c6a672f2c775164fc373ff4458de8d3765e59ca5bff0a3e6a1d28a3d50d7',
          'create_current_backup_and_prune_root.py': '5e984e95e079ee38f3a4a838160e555b446c3ba0a6898cdd26dd6360bf95059f'}
EXTRA_REFERENCE_DIRS = (Path('/etc/go-erp'), Path('/var/lib/go-erp-live-updates/state'))
EXTRA_REFERENCE_FILES = (Path('/var/lib/go-erp-manual-updates/private/active.json'),
                         Path('/var/lib/go-erp-manual-updates/shared/status.json'))


class Stop(RuntimeError):
    pass


def need(value, code):
    if not value:
        raise Stop('POINT_BACKUP_' + code)


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def ancestors(path):
    need(path.is_absolute() and '..' not in path.parts, 'PATH_UNSAFE')
    for parent in reversed(path.parents):
        need(stat.S_ISDIR(parent.lstat().st_mode), 'ANCESTOR_LINK_OR_SPECIAL')


def load_split(backup):
    folder = backup / 'tools/full_backup_20261025'
    payloads = {}
    for name, sha in PINNED.items():
        path = folder / name
        ancestors(path)
        info = path.lstat()
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and not info.st_mode & 0o022 and 0 < info.st_size < 1024**2, 'REFERENCE_HELPER_UNSAFE')
        raw = path.read_bytes()
        need(identity(path.lstat()) == identity(info) and hashlib.sha256(raw).hexdigest() == sha,
             'REFERENCE_HELPER_PIN_INVALID')
        payloads[name] = raw
    core = types.ModuleType('create_all_apps_backup_root')
    core.__file__ = str(folder / 'create_all_apps_backup_root.py')
    previous = sys.modules.get(core.__name__)
    try:
        sys.modules[core.__name__] = core
        exec(compile(payloads['create_all_apps_backup_root.py'], core.__file__, 'exec'), core.__dict__)
        split = types.ModuleType('point_backup_reference_checks')
        split.__file__ = str(folder / 'create_current_backup_and_prune_root.py')
        exec(compile(payloads['create_current_backup_and_prune_root.py'], split.__file__, 'exec'), split.__dict__)
        need(split.HOME == Path('/home/fasakha') and split.core is core, 'REFERENCE_BINDING_INVALID')
        return split
    finally:
        if previous is None:
            sys.modules.pop(core.__name__, None)
        else:
            sys.modules[core.__name__] = previous


def file_hash(path, info):
    fd = os.open(str(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(os.fstat(fd)) == identity(info), 'FILE_CHANGED')
        digest, size = hashlib.sha256(), 0
        while True:
            chunk = os.read(fd, 1024**2)
            if not chunk:
                break
            size += len(chunk)
            need(size <= info.st_size, 'FILE_CHANGED')
            digest.update(chunk)
        need(size == info.st_size and identity(os.fstat(fd)) == identity(info)
             and identity(path.lstat()) == identity(info), 'FILE_CHANGED')
        return digest.hexdigest()
    finally:
        os.close(fd)


def snapshot(path):
    ancestors(path)
    top = path.lstat()
    need(stat.S_ISDIR(top.st_mode), 'TARGET_NOT_REGULAR_DIRECTORY')
    result = {}
    def record(item):
        info = item.lstat()
        need(info.st_dev == top.st_dev, 'NESTED_FILESYSTEM')
        # These two old desktop rollback trees normally have no extended ACLs
        # or xattrs. Preserve unusual metadata rather than guessing equivalence.
        need(not os.listxattr(str(item), follow_symlinks=False), 'EXTENDED_METADATA_REQUIRES_REVIEW')
        relative = str(item.relative_to(path))
        row = {'mode': stat.S_IMODE(info.st_mode), 'uid': info.st_uid, 'gid': info.st_gid,
               'mtime': info.st_mtime_ns // 1000000000, 'mtime_ns': info.st_mtime_ns,
               'identity': identity(info)}
        if stat.S_ISREG(info.st_mode):
            need(info.st_nlink >= 1, 'REGULAR_FILE_LINK_COUNT_INVALID')
            row.update(type='file', size=info.st_size, sha256=file_hash(item, info),
                       allocated_bytes=info.st_blocks * 512)
        elif stat.S_ISLNK(info.st_mode):
            row.update(type='symlink', target=os.readlink(str(item)))
        elif stat.S_ISDIR(info.st_mode):
            row.update(type='directory')
        else:
            raise Stop('POINT_BACKUP_SPECIAL_FILE')
        result[relative] = row
    record(path)
    def failed(_):
        raise Stop('POINT_BACKUP_UNREADABLE_TREE')
    for parent, dirs, files in os.walk(str(path), followlinks=False, onerror=failed):
        dirs.sort()
        files.sort()
        for name in dirs + files:
            record(Path(parent) / name)
    return result


def semantic(row):
    return {key: value for key, value in row.items() if key not in ('identity', 'allocated_bytes')}


def assert_snapshot(path, initial):
    current = snapshot(path)
    need(set(current) == set(initial), 'TREE_CHANGED')
    for name in current:
        need(semantic(current[name]) == semantic(initial[name]), 'TREE_CHANGED')
        # Rename may change only the moved top directory's ctime.
        before, after = initial[name]['identity'], current[name]['identity']
        need(before[:8] == after[:8] if name == '.' else before == after, 'TREE_IDENTITY_CHANGED')


def archive_name(name):
    while name.startswith('./'):
        name = name[2:]
    name = name.rstrip('/') or '.'
    need(not name.startswith('/') and '..' not in Path(name).parts and '\x00' not in name,
         'ARCHIVE_UNSAFE_PATH_OR_HARDLINK_TARGET')
    return '/'.join(Path(name).parts) or '.'


def archived_trees(archive_path, roots):
    # Index only digests and metadata in memory. Never extract any archived
    # customer content or expose unrelated paths/digests in the result.
    result = {name: {} for name in roots}
    regulars, hardlinks, seen = {}, {}, set()
    info = archive_path.lstat()
    need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
         and not info.st_mode & 0o022, 'HOME_ARCHIVE_UNSAFE')
    def metadata(member):
        extended = any(key.startswith(('SCHILY.xattr.', 'SCHILY.acl.')) for key in member.pax_headers)
        mtime_ns = int(Decimal(member.pax_headers.get('mtime', str(member.mtime))) * 1000000000)
        return member.mode, member.uid, member.gid, mtime_ns, extended
    def row(meta):
        need(not meta[4], 'ARCHIVED_EXTENDED_METADATA_REQUIRES_REVIEW')
        return {'mode': meta[0], 'uid': meta[1], 'gid': meta[2],
                'mtime': meta[3] // 1000000000, 'mtime_ns': meta[3]}
    with tarfile.open(str(archive_path), 'r|gz') as archive:
        for member in archive:
            name = archive_name(member.name)
            need(name not in seen, 'ARCHIVE_DUPLICATE_OR_UNSAFE_PATH')
            seen.add(name)
            need(len(seen) <= 2000000, 'ARCHIVE_ENTRY_LIMIT')
            meta = metadata(member)
            if member.isfile():
                digest, size = hashlib.sha256(), 0
                stream = archive.extractfile(member)
                need(stream is not None, 'ARCHIVE_FILE_UNREADABLE')
                for chunk in iter(lambda: stream.read(1024**2), b''):
                    digest.update(chunk)
                    size += len(chunk)
                need(size == member.size, 'ARCHIVE_FILE_TRUNCATED')
                regulars[name] = (size, digest.hexdigest(), meta)
            elif member.islnk():
                hardlinks[name] = (archive_name(member.linkname), meta)
            for prefix in roots:
                if name != prefix and not name.startswith(prefix + '/'):
                    continue
                relative = name[len(prefix):].lstrip('/') or '.'
                need(relative not in result[prefix], 'ARCHIVE_DUPLICATE_OR_UNSAFE_PATH')
                if member.islnk():
                    result[prefix][relative] = {'hardlink_member': name}
                    continue
                value = row(meta)
                if member.isfile():
                    size, digest, _ = regulars[name]
                    value.update(type='file', size=size, sha256=digest)
                elif member.issym():
                    value.update(type='symlink', target=member.linkname)
                elif member.isdir():
                    value['type'] = 'directory'
                else:
                    raise Stop('POINT_BACKUP_ARCHIVE_SPECIAL_FILE')
                result[prefix][relative] = value
            # Streaming extraction of regular members needs no TarInfo cache;
            # keeping it would duplicate the whole home inventory in memory.
            archive.members.clear()
    resolved = {}
    def resolve(name):
        trail, visiting = [], set()
        current = name
        while current not in regulars and current not in resolved:
            need(current not in visiting, 'ARCHIVE_HARDLINK_CYCLE')
            visiting.add(current)
            need(current in hardlinks, 'ARCHIVE_HARDLINK_TARGET_NOT_REGULAR_OR_MISSING')
            need(len(trail) < 4096, 'ARCHIVE_HARDLINK_CHAIN_LIMIT')
            target, meta = hardlinks[current]
            trail.append((current, meta))
            current = target
        payload = regulars[current] if current in regulars else resolved[current]
        for linked_name, meta in reversed(trail):
            need(meta == payload[2], 'ARCHIVE_HARDLINK_METADATA_CONFLICT')
            resolved[linked_name] = payload
        return payload
    for tree in result.values():
        for relative, value in list(tree.items()):
            if 'hardlink_member' not in value:
                continue
            size, digest, meta = resolve(value['hardlink_member'])
            value = row(meta)
            value.update(type='file', size=size, sha256=digest)
            tree[relative] = value
    need(identity(archive_path.lstat()) == identity(info), 'HOME_ARCHIVE_CHANGED')
    return result


def path_inside(path, root):
    return path == root or root in path.parents


def byte_counts(snapshots):
    logical, inodes = 0, {}
    for tree in snapshots.values():
        for item in tree.values():
            if item['type'] != 'file':
                continue
            logical += item['size']
            key = tuple(item['identity'][:2])
            if key not in inodes:
                inodes[key] = {'selected': 0, 'links': item['identity'][5],
                               'allocated': item['allocated_bytes']}
            inodes[key]['selected'] += 1
    # Shared outside aliases keep the inode allocated. XFS reflink sharing can
    # reduce actual reclamation further, so this is explicitly an estimate.
    estimate = sum(item['allocated'] for item in inodes.values() if item['selected'] == item['links'])
    return logical, estimate


def check_dependencies(candidates, split):
    inventory = split.dependency_check(candidates) or {}
    aliases = list(candidates)
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        fields = line.split()
        decoded = [re.sub(r'\\([0-7]{3})', lambda match: chr(int(match[1], 8)), field) for field in fields[3:5]]
        if len(decoded) != 2:
            continue
        source, target = map(Path, decoded)
        for candidate in candidates:
            need(not path_inside(source, candidate) and not path_inside(target, candidate), 'MOUNT_REFERENCE')
            if path_inside(candidate, source):
                aliases.append(target / candidate.relative_to(source))
    def reject(text):
        values = [text, text.replace('\\/', '/')]
        try:
            parsed = json.loads(text)
        except ValueError:
            parsed = None
        def strings(value):
            if isinstance(value, str):
                values.append(value)
            elif isinstance(value, dict):
                for key, item in value.items():
                    strings(key)
                    strings(item)
            elif isinstance(value, list):
                for item in value:
                    strings(item)
        strings(parsed)
        for value in values:
            for candidate in aliases:
                need(not re.search(re.escape(str(candidate)) + r'(?=$|[/\s;\x22\x27\x29\x7d\x00])', value), 'LIVE_REFERENCE')
    # Preserve root-owned updater evidence/config paths even if the account's
    # application and effective systemd settings do not mention them.
    files = set(EXTRA_REFERENCE_FILES)
    for root in EXTRA_REFERENCE_DIRS:
        if root.is_dir():
            files.update(path for path in root.rglob('*') if path.is_file() and not path.is_symlink())
    gitdirs = []
    if isinstance(inventory, dict):
        for name, row in inventory.items():
            if Path(name).name == '.git':
                path = split.HOME / name
                if row.get('type') == 'directory':
                    gitdirs.append(path)
                elif row.get('type') == 'file':
                    raw = path.read_text(errors='strict').strip()
                    need(raw.startswith('gitdir:'), 'GIT_DIRECTORY_FILE_INVALID')
                    target = Path(raw[7:].strip())
                    target = target if target.is_absolute() else path.parent / target
                    target = target.resolve()
                    need(not any(path_inside(target, candidate) for candidate in aliases), 'GIT_REFERENCE')
                    gitdirs.append(target)
    for gitdir in gitdirs:
        files.update([gitdir / 'config', gitdir / 'commondir', gitdir / 'gitdir'])
        files.update(gitdir.glob('worktrees/*/gitdir'))
        pointers = [gitdir / 'commondir', gitdir / 'gitdir'] + list(gitdir.glob('worktrees/*/gitdir'))
        for pointer in pointers:
            if pointer.is_file():
                target = Path(pointer.read_text(errors='strict').strip())
                target = target if target.is_absolute() else pointer.parent / target
                need(not any(path_inside(target.resolve(), candidate) for candidate in aliases), 'GIT_REFERENCE')
        config = gitdir / 'config'
        if config.is_file():
            for match in re.finditer(r'^\s*(?:worktree|path)\s*=\s*(.*?)\s*$', config.read_text(errors='strict'), re.M | re.I):
                text = match[1]
                if text.startswith('"'):
                    try:
                        text = json.loads(text)
                    except ValueError:
                        raise Stop('POINT_BACKUP_QUOTED_GIT_PATH_REQUIRES_REVIEW')
                target = Path(text).expanduser()
                target = target if target.is_absolute() else gitdir / target
                need(not any(path_inside(target.resolve(), candidate) for candidate in aliases), 'GIT_REFERENCE')
        alternate = gitdir / 'objects/info/alternates'
        if alternate.is_file():
            for line in alternate.read_text(errors='strict').splitlines():
                need(not line.startswith('"'), 'QUOTED_GIT_ALTERNATE_REQUIRES_REVIEW')
                target = Path(line)
                target = target if target.is_absolute() else gitdir / 'objects' / target
                need(not any(path_inside(target.resolve(), candidate) for candidate in aliases), 'GIT_REFERENCE')
    for path in files:
        if path.is_file():
            need(path.stat().st_size < 16 * 1024**2, 'REFERENCE_FILE_LIMIT')
            reject(path.read_text(errors='replace'))
    # Resolve jailed-shell bind aliases for currently open process paths too.
    for process in Path('/proc').iterdir():
        if not process.name.isdigit():
            continue
        try:
            reject((process / 'cmdline').read_bytes().decode(errors='replace'))
        except OSError:
            pass
        paths = [process / 'cwd', process / 'exe']
        try:
            paths.extend((process / 'fd').iterdir())
        except OSError:
            pass
        for path in paths:
            try:
                target = os.readlink(str(path)).replace(' (deleted)', '')
            except OSError:
                continue
            need(not any(path_inside(Path(target), candidate) for candidate in aliases), 'PROCESS_REFERENCE')


def clean(verified, split=None, apply=False):
    backup = Path(verified.latest_path) if hasattr(verified, 'latest_path') else Path(verified)
    if hasattr(verified, 'check'):
        verified.check()
    split = split or load_split(backup)
    home = Path(split.HOME)
    candidates = []
    for name in ALLOWLIST:
        need(name and not Path(name).is_absolute() and '..' not in Path(name).parts, 'ALLOWLIST_UNSAFE')
        path = home / name
        if os.path.lexists(str(path)):
            ancestors(path)
            need(stat.S_ISDIR(path.lstat().st_mode), 'TARGET_NOT_REGULAR_DIRECTORY')
            candidates.append(path)
    if not candidates:
        return {'point_backup_status': 'unchanged', 'point_backup_directories_removed': 0,
                'point_backup_bytes_removed': 0}
    check_dependencies(candidates, split)
    snapshots = {str(path.relative_to(home)): snapshot(path) for path in candidates}
    archived = archived_trees(backup / 'home-current.tar.gz', snapshots)
    for name, tree in snapshots.items():
        need({key: semantic(row) for key, row in tree.items()} == archived[name], 'CURRENT_TREE_DIFFERS_FROM_ARCHIVE')
    check_dependencies(candidates, split)
    for path in candidates:
        assert_snapshot(path, snapshots[str(path.relative_to(home))])
    if hasattr(verified, 'check'):
        verified.check()
    total, reclaim_estimate = byte_counts(snapshots)
    if not apply:
        return {'point_backup_status': 'checked', 'point_backup_directories_planned': len(candidates),
                'point_backup_bytes_planned': total, 'point_backup_byte_count_kind': 'logical_names',
                'point_backup_reclaimable_allocated_bytes_estimate': reclaim_estimate, 'removed_files': 0}
    need(getattr(shutil.rmtree, 'avoids_symlink_attacks', False), 'SAFE_RMTREE_UNAVAILABLE')
    quarantine = Path(tempfile.mkdtemp(prefix='point-backup-prune-', dir=str(backup.parent)))
    need(quarantine.stat().st_uid == 0 and not quarantine.stat().st_mode & 0o077, 'QUARANTINE_NOT_PRIVATE')
    moved = []
    try:
        for index, path in enumerate(candidates):
            tree = snapshots[str(path.relative_to(home))]
            assert_snapshot(path, tree)
            need(path.stat().st_dev == quarantine.stat().st_dev, 'QUARANTINE_FILESYSTEM_MISMATCH')
            target = quarantine / str(index)
            os.rename(str(path), str(target))
            moved.append((path, target, tree))
        for _, target, tree in moved:
            assert_snapshot(target, tree)
        if hasattr(verified, 'check'):
            verified.check()
        for _, target, _ in moved:
            shutil.rmtree(str(target))
        quarantine.rmdir()
    except BaseException:
        for original, target, _ in reversed(moved):
            if target.exists() and not os.path.lexists(str(original)):
                os.rename(str(target), str(original))
        if quarantine.exists() and not any(quarantine.iterdir()):
            quarantine.rmdir()
        raise
    return {'point_backup_status': 'applied', 'point_backup_directories_removed': len(moved),
            'point_backup_bytes_removed': total, 'removed_paths': [str(path) for path, _, _ in moved],
            'point_backup_byte_count_kind': 'logical_names_unlinked',
            'point_backup_reclaimable_allocated_bytes_estimate': reclaim_estimate,
            'latest_full_backup_preserved': True, 'runtime_directories_deleted': False}
