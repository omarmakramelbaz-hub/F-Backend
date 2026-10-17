#!/usr/bin/env python3
"""Install the reviewed dashboard theme without replacing the live layout."""

import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile


HEADER = 'resources/views/admin/layouts/header.blade.php'
ASSETS = (
    'public/dashboard/branding/dashboard-professional.css',
    'public/dashboard/branding/dashboard-professional-modules.css',
)
START = b'<!-- fasa-professional-design:start -->'
END = b'<!-- fasa-professional-design:end -->'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
MAX_BYTES = 2 * 1024 * 1024
INFO_KEYS = (
    'st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_size',
    'st_mtime_ns', 'st_ctime_ns',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute() and '..' not in path.parts, 'ABSOLUTE_SAFE_PATH_REQUIRED')
    for part in reversed((path, *path.parents)):
        require(not part.is_symlink(), 'SYMLINK_REJECTED: ' + str(part))


def snapshot(path, missing_ok=False):
    safe(path)
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    except FileNotFoundError:
        require(missing_ok and path.parent.is_dir(), 'TARGET_MISSING: ' + str(path))
        return None
    with os.fdopen(fd, 'rb') as stream:
        before = os.fstat(stream.fileno())
        require(stat.S_ISREG(before.st_mode) and before.st_size <= MAX_BYTES,
                'INVALID_TARGET: ' + str(path))
        body = stream.read(MAX_BYTES + 1)
        after = os.fstat(stream.fileno())
    require(len(body) <= MAX_BYTES and all(getattr(before, key) == getattr(after, key)
            for key in INFO_KEYS), 'TARGET_CHANGED_DURING_READ: ' + str(path))
    return body, after


def same(path, original):
    current = snapshot(path, missing_ok=True)
    if original is None or current is None:
        return original is current
    return current[0] == original[0] and all(
        getattr(current[1], key) == getattr(original[1], key) for key in INFO_KEYS)


def git_bytes(root, *arguments):
    environment = {key: value for key, value in os.environ.items()
                   if not key.startswith('GIT_')}
    environment.update(GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL='/dev/null',
                       GIT_OPTIONAL_LOCKS='0', GIT_NO_REPLACE_OBJECTS='1',
                       GIT_TERMINAL_PROMPT='0')
    command = ['git', '-c', 'safe.directory=' + str(root), '-C', str(root), *arguments]
    result = subprocess.run(command, env=environment, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=30, check=False)
    require(result.returncode == 0, 'PINNED_RELEASE_UNAVAILABLE')
    require(len(result.stdout) <= MAX_BYTES, 'RELEASE_ASSET_TOO_LARGE')
    return result.stdout


def release_assets(root, release):
    require(re.fullmatch(r'[0-9a-f]{40}', release) is not None, 'FULL_RELEASE_SHA_REQUIRED')
    require(git_bytes(root, 'cat-file', '-t', release).strip() == b'commit',
            'RELEASE_MUST_BE_A_COMMIT')
    desired = {}
    for relative in ASSETS:
        body = git_bytes(root, 'show', release + ':' + relative)
        require(body.strip() and b'\x00' not in body, 'INVALID_RELEASE_CSS')
        desired[relative] = body
    return desired


def plan_header(body, release):
    require(b'\x00' not in body, 'INVALID_LIVE_HEADER')
    # Read the complete tag so Blade's -> expressions inside quoted attributes
    # cannot prematurely terminate it at the greater-than character.
    require(len(re.findall(rb'<body\b', body, re.I)) == 1, 'UNIQUE_BODY_REQUIRED')
    tag_pattern = rb'<body\b(?:[^\'\">]|\"[^\"]*\"|\'[^\']*\')*>'
    tags = list(re.finditer(tag_pattern, body, re.I | re.S))
    require(len(tags) == 1, 'BODY_TAG_NEEDS_REVIEW')
    tag = tags[0]
    classes = list(re.finditer(rb'\bclass\s*=\s*([\"\'])(.*?)\1', tag.group(), re.I | re.S))
    require(len(classes) == 1, 'UNIQUE_BODY_CLASS_REQUIRED')
    value = classes[0].group(2)
    require(len(re.findall(rb'(?:^|\s)dashboard-theme(?=\s|\{|$)', value)) == 1,
            'AUTHENTICATED_DASHBOARD_CLASS_REQUIRED')
    fasa = re.findall(rb'(?:^|\s)fasa-pro(?=\s|\{|$)', value)
    require(len(fasa) <= 1, 'DUPLICATE_THEME_CLASS')
    if not fasa:
        position = tag.start() + classes[0].start(2)
        body = body[:position] + value.replace(b'dashboard-theme', b'dashboard-theme fasa-pro', 1) + body[position + len(value):]

    newline = b'\r\n' if b'\r\n' in body else b'\n'
    require(body.count(START) == body.count(END) and body.count(START) <= 1,
            'THEME_MARKERS_NEED_REVIEW')
    if START in body:
        existing = re.compile(rb'(?m)^[ \t]*' + re.escape(START) + rb'[^\r\n]*\r?\n.*?^[ \t]*' + re.escape(END) + rb'[^\r\n]*(?:\r?\n|$)', re.S)
        blocks = list(existing.finditer(body))
        require(len(blocks) == 1, 'THEME_BLOCK_NEEDS_REVIEW')
        block = blocks[0]
        require(block.group().count(b'<link ') == 2
                and all(block.group().count(Path(relative).name.encode()) == 1 for relative in ASSETS)
                and b'<script' not in block.group().lower(), 'THEME_BLOCK_NEEDS_REVIEW')
        body = body[:block.start()] + body[block.end():]

    # Only this existing stylesheet is an allowed insertion anchor. No complete
    # layout replacement is used, so live authentication and Meta changes stay.
    anchors = list(re.finditer(rb'(?m)^([ \t]*)<link\b[^\r\n]*dashboard-brand\.css[^\r\n]*>[^\r\n]*(?:\r?\n|$)', body))
    require(len(anchors) == 1, 'BRAND_STYLESHEET_ANCHOR_NEEDS_REVIEW')
    require(not any(Path(relative).name.encode() in body for relative in ASSETS),
            'UNMARKED_THEME_LINK_NEEDS_REVIEW')
    anchor = anchors[0]
    indent = anchor.group(1)
    lines = [indent + START]
    for relative in ASSETS:
        public_path = relative[len('public/'):]
        lines.append(indent + b'<link rel="stylesheet" href="{{ asset(\''
                     + public_path.encode() + b"') }}?v=" + release.encode() + b'">')
    lines.append(indent + END)
    addition = newline.join(lines) + newline
    prefix = body[:anchor.end()]
    if not prefix.endswith(b'\n'):
        prefix += newline
    return prefix + addition + body[anchor.end():]


def stage(directory, body, info, restore_times=False):
    safe(directory)
    descriptor, name = tempfile.mkstemp(prefix='.dashboard-design-', dir=directory)
    path = Path(name)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            stream.write(body)
            stream.flush()
            if restore_times:
                os.utime(stream.fileno(), ns=(info.st_atime_ns, info.st_mtime_ns))
            os.fsync(stream.fileno())
        return path
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def sync_directory(directory):
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def clear_views(root):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', PHP, 'artisan', 'view:clear'],
                            cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=60, check=False)
    require(result.returncode == 0, 'VIEW_CLEAR_FAILED')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--release', required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = args.root
    safe(root)
    require(root.is_dir(), 'ROOT_DIRECTORY_REQUIRED')
    paths = {relative: root / relative for relative in (HEADER, *ASSETS)}
    originals = {relative: snapshot(path, missing_ok=relative != HEADER)
                 for relative, path in paths.items()}
    desired = release_assets(root, args.release)
    desired[HEADER] = plan_header(originals[HEADER][0], args.release)
    changed = [relative for relative in (HEADER, *ASSETS)
               if originals[relative] is None or originals[relative][0] != desired[relative]]
    require(all(same(path, originals[relative]) for relative, path in paths.items()),
            'TARGET_CHANGED_DURING_PREFLIGHT')
    if args.check or not changed:
        print('DASHBOARD_PROFESSIONAL_CHECK_OK' if args.check else 'DASHBOARD_PROFESSIONAL_ALREADY_CURRENT')
        for relative in (HEADER, *ASSETS):
            print(('UPDATE ' if relative in changed else 'CURRENT ') + relative)
        return

    require(os.geteuid() == 0, 'RUN_AS_ROOT_REQUIRED')
    require(Path(PHP).is_file() and shutil.which('runuser'), 'PHP_OR_RUNUSER_MISSING')
    private = root.parent / 'dashboard-design-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    private_info = private.stat()
    require(stat.S_ISDIR(private_info.st_mode) and stat.S_IMODE(private_info.st_mode) == 0o700
            and private_info.st_uid == 0, 'PRIVATE_BACKUP_DIRECTORY_REQUIRED')
    lock_path = private / 'apply.lock'
    safe(lock_path)
    lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(lock_fd, 'a+b') as lock:
        lock_info = os.fstat(lock.fileno())
        require(stat.S_ISREG(lock_info.st_mode) and stat.S_IMODE(lock_info.st_mode) == 0o600
                and lock_info.st_uid == 0, 'PRIVATE_LOCK_FILE_REQUIRED')
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError('ANOTHER_THEME_DEPLOYMENT_IS_RUNNING') from None
        require(all(same(path, originals[relative]) for relative, path in paths.items()),
                'TARGET_CHANGED_BEFORE_BACKUP')
        prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=prefix + args.release[:12] + '-', dir=private))
        staged, published, installed = {}, [], {}
        try:
            manifest = {'release': args.release, 'files': {}}
            for relative, original in originals.items():
                if original is None:
                    manifest['files'][relative] = {'existed': False}
                    continue
                require(same(paths[relative], original), 'TARGET_CHANGED_BEFORE_BACKUP')
                saved = backup / relative
                saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                saved_stage = stage(saved.parent, original[0], original[1], restore_times=True)
                os.replace(saved_stage, saved)
                manifest['files'][relative] = {'existed': True,
                    'sha256': hashlib.sha256(original[0]).hexdigest(),
                    **{key: getattr(original[1], key) for key in (*INFO_KEYS, 'st_atime_ns')}}
            manifest_fd = os.open(backup / 'manifest.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(manifest_fd, 'w') as stream:
                json.dump(manifest, stream, ensure_ascii=False, indent=2)
                stream.write('\n')
                stream.flush()
                os.fsync(stream.fileno())
            sync_directory(backup)
            header_info = originals[HEADER][1]
            for relative in changed:
                original = originals[relative]
                if original is None:
                    new_info = type('FileMetadata', (), {'st_uid': header_info.st_uid,
                        'st_gid': header_info.st_gid, 'st_mode': stat.S_IFREG | 0o644})()
                else:
                    new_info = original[1]
                staged[relative] = stage(paths[relative].parent, desired[relative], new_info)
            # Publish assets before enabling them in the layout.
            for relative in (*ASSETS, HEADER):
                if relative not in changed:
                    continue
                require(same(paths[relative], originals[relative]), 'TARGET_CHANGED_BEFORE_PUBLICATION')
                prepared = snapshot(staged[relative])
                os.replace(staged[relative], paths[relative])
                published.append(relative)
                current = snapshot(paths[relative])
                require(current[0] == prepared[0] and all(
                    getattr(current[1], key) == getattr(prepared[1], key)
                    for key in INFO_KEYS if key != 'st_ctime_ns'),
                    'TARGET_CHANGED_DURING_PUBLICATION: ' + relative)
                installed[relative] = current
                sync_directory(paths[relative].parent)
            clear_views(root)
            require(all(snapshot(path)[0] == desired[relative] for relative, path in paths.items()),
                    'FINAL_CONTENT_CHECK_FAILED')
            require(all(same(paths[relative], installed[relative]) for relative in published),
                    'TARGET_CHANGED_AFTER_PUBLICATION')
            print('DASHBOARD_PROFESSIONAL_DESIGN_OK\nBACKUP ' + str(backup))
        except BaseException:
            failures = []
            for relative in reversed(published):
                try:
                    require(relative in installed and same(paths[relative], installed[relative]),
                            'ROLLBACK_BLOCKED_BY_CONCURRENT_EDIT: ' + relative)
                    original = originals[relative]
                    if original is None:
                        paths[relative].unlink()
                    else:
                        restore = stage(paths[relative].parent, original[0], original[1], restore_times=True)
                        os.replace(restore, paths[relative])
                    sync_directory(paths[relative].parent)
                except BaseException as error:
                    failures.append(str(error))
            if published:
                try:
                    clear_views(root)
                except BaseException:
                    failures.append('ROLLBACK_VIEW_CLEAR_FAILED')
            if failures:
                raise RuntimeError('ROLLBACK_NEEDS_REVIEW; BACKUP ' + str(backup) + '; ' + '; '.join(failures)) from None
            print('ROLLBACK_RESTORED\nBACKUP ' + str(backup))
            raise
        finally:
            for temporary in staged.values():
                temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('STOPPED: ' + (str(error) if isinstance(error, RuntimeError)
                                      else type(error).__name__))
