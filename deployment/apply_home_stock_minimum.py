#!/usr/bin/env python3
"""Install homepage low-stock highlighting without replacing independent live edits."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import stat
import subprocess
import tempfile


BASE_REVISION = 'd7b0f1cb122251adc34fb62f9a0be7a203660ffc'
SOURCE_PATHS = (
    'app/Services/Dashboard/HomeOverview.php',
    'public/dashboard/js/home-overview.js',
    'public/dashboard/js/branch-home.js',
    'public/dashboard/css/home-overview.css',
    'resources/lang/ar/home_overview.php',
    'resources/lang/en/home_overview.php',
)

MAX_BYTES = 4 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(command, cwd=None):
    return subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=120)


def git(root, *arguments):
    return run(['git', '-C', str(root), *arguments])


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for candidate in (path, *path.parents):
        require(not candidate.is_symlink(), 'SYMLINK_REJECTED: ' + str(candidate))


def read_source(path):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_UNLINKED_SOURCE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'FASAKHA_OWNED_SOURCE_REQUIRED: ' + str(path))
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body,
            'SOURCE_INVALID: ' + str(path))
    return body, info


def digest(body):
    return hashlib.sha256(body).hexdigest()


def restore_metadata(path, expected_body, original_info):
    """Git rewrites patched files under our private umask; retain live access bits."""
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid() and stream.read(MAX_BYTES + 1) == expected_body,
                'UNOWNED_SOURCE_METADATA_CHANGE: ' + str(path))
        if info.st_gid != original_info.st_gid:
            os.fchown(stream.fileno(), -1, original_info.st_gid)
        os.fchmod(stream.fileno(), stat.S_IMODE(original_info.st_mode))


def unchanged(path, original):
    body, info = read_source(path)
    old_body, old_info = original
    return (body == old_body and info.st_uid == old_info.st_uid
            and info.st_gid == old_info.st_gid
            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode)
            and info.st_ino == old_info.st_ino and info.st_dev == old_info.st_dev
            and info.st_mtime_ns == old_info.st_mtime_ns)


def preflight_destination(root, relative):
    """Git hunk checks do not check whether Git can create/replace a live file."""
    target = root / relative
    safe(target)
    parent = target.parent
    while not parent.exists():
        require(parent != root, 'APPLICATION_ROOT_INVALID')
        parent = parent.parent
    info = parent.stat()
    require(stat.S_ISDIR(info.st_mode), 'DESTINATION_PARENT_NOT_DIRECTORY: ' + relative)
    require(os.access(parent, os.W_OK | os.X_OK, effective_ids=True),
            'DESTINATION_PARENT_NOT_WRITABLE: ' + str(parent.relative_to(root))
            + '; file=' + relative + '; uid=' + str(info.st_uid) + '; gid=' + str(info.st_gid)
            + '; mode=' + format(stat.S_IMODE(info.st_mode), '04o')
            + '; effective_uid=' + str(os.geteuid()))


def patch_failure_category(stderr):
    """Expose an actionable filesystem category without printing Git's raw output."""
    observed = stderr.lower()
    categories = (
        ('permission_denied', (b'permission denied', b'operation not permitted')),
        ('read_only_filesystem', (b'read-only file system',)),
        ('storage_full', (b'no space left on device', b'disk quota exceeded')),
        ('destination_conflict', (b'already exists', b'not a directory', b'no such file or directory')),
        ('hunk_conflict', (b'does not apply', b'patch failed')),
    )
    return next((name for name, phrases in categories if any(phrase in observed for phrase in phrases)),
                'git_error')


def patch_check(directory, patch, reverse=False):
    command = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        command.append('--reverse')
    return run([*command, str(patch)], cwd=directory).returncode == 0


def patch_apply(directory, patch, reverse=False):
    command = ['git', 'apply', '--whitespace=nowarn']
    if reverse:
        command.append('--reverse')
    result = run([*command, str(patch)], cwd=directory)
    if result.returncode != 0:
        print('HOME_STOCK_MINIMUM_PATCH_DIAGNOSTIC category=' + patch_failure_category(result.stderr)
              + ' exit_code=' + str(result.returncode), flush=True)
    return result.returncode == 0


def lint_sources(php, directory):
    """Parse candidate PHP/JS without loading the application or sending mail."""
    node = shutil.which('node')
    for relative in SOURCE_PATHS:
        if relative.endswith('.php'):
            result = run([php, '-l', str(directory / relative)])
            require(result.returncode == 0, 'PHP_PARSE_INVALID: ' + relative)
        elif relative.endswith('.js') and node:
            result = run([node, '--check', str(directory / relative)])
            require(result.returncode == 0, 'JS_PARSE_INVALID: ' + relative)


def validate_release(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    for commit in (BASE_REVISION, revision):
        result = git(root, 'rev-parse', '--verify', commit + '^{commit}')
        require(result.returncode == 0 and result.stdout.decode().strip() == commit,
                'PINNED_COMMIT_UNAVAILABLE: ' + commit)
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines())
            == {'M\t' + path for path in SOURCE_PATHS}, 'RELEASE_SOURCE_MANIFEST_MISMATCH')
    for commit in (BASE_REVISION, revision):
        result = git(root, 'ls-tree', '-z', commit, '--', *SOURCE_PATHS)
        require(result.returncode == 0, 'RELEASE_TREE_UNAVAILABLE')
        entries = result.stdout.rstrip(b'\x00').split(b'\x00')
        require(len(entries) == len(SOURCE_PATHS), 'RELEASE_SOURCE_TREE_MISMATCH')
        for entry in entries:
            header, relative = entry.split(b'\t', 1)
            mode, kind, _ = header.split(b' ', 2)
            require(mode in (b'100644', b'100755') and kind == b'blob'
                    and relative.decode() in SOURCE_PATHS, 'RELEASE_SOURCE_NOT_REGULAR')


def release_patch(root, revision, relative):
    result = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--unified=3', BASE_REVISION, revision, '--', relative)
    body = result.stdout
    require(result.returncode == 0 and 0 < len(body) <= MAX_BYTES
            and body.startswith(('diff --git a/' + relative + ' b/' + relative + '\n').encode())
            and all(marker not in body for marker in
                    (b'GIT binary patch', b'Binary files ', b'old mode ', b'new mode ',
                     b'new file mode ', b'deleted file mode ')),
            'RELEASE_PATCH_INVALID: ' + relative)
    return body


def save_receipt(path, receipt):
    safe(path)
    descriptor, name = tempfile.mkstemp(prefix='.receipt-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump(receipt, stream, ensure_ascii=False, indent=2)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def rollback(root, pending, originals):
    """Reverse a hunk only while its file still has exactly our installed bytes."""
    review = []
    for relative, patch_path, after in reversed(pending):
        try:
            target = root / relative
            observed, info = read_source(target)
            before, old_info = originals[relative]
            if observed == before:
                require(info.st_gid == old_info.st_gid
                        and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode),
                        'UNOWNED_SOURCE_METADATA_CHANGE')
                continue
            require(observed == after, 'UNOWNED_CHANGE_DURING_ROLLBACK')
            restore_metadata(target, after, old_info)
            require(patch_check(root, patch_path, reverse=True), 'ROLLBACK_CHECK_FAILED')
            # Rollback also uses the exact inverse hunks, never source replacement.
            require(patch_apply(root, patch_path, reverse=True), 'ROLLBACK_PATCH_FAILED')
            restore_metadata(target, before, old_info)
            require(read_source(target)[0] == before, 'ROLLBACK_VERIFICATION_FAILED')
        except (RuntimeError, OSError, subprocess.SubprocessError):
            review.append(relative)
    return review


def install(root, revision, php, check_only=False):
    os.umask(0o077)
    validate_release(root, revision)
    private = root.parent / 'home-stock-minimum-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(private.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700, 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    lock_fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(lock_fd, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o600,
                'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals, pending = {}, []
            for index, relative in enumerate(SOURCE_PATHS):
                target = root / relative
                before, source_info = read_source(target)
                originals[relative] = (before, source_info)
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                staged.write_bytes(before)
                staged.chmod(stat.S_IMODE(source_info.st_mode))
                patch = stage / ('patch-' + str(index) + '.diff')
                patch.write_bytes(release_patch(root, revision, relative))
                # Reverse first prevents duplicate insertion-only hunks on reruns.
                if patch_check(stage, patch, reverse=True):
                    continue
                require(patch_check(stage, patch), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                require(patch_apply(stage, patch), 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch, staged.read_bytes()))

            lint_sources(php, stage)
            if not pending:
                print('HOME_STOCK_MINIMUM_OK already_current=1 files=0', flush=True)
                return None
            for relative, _, _ in pending:
                preflight_destination(root, relative)
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch.read_bytes() for _, patch, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            print('HOME_STOCK_MINIMUM_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=stamp + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION, 'status': 'prepared',
                       'patch_sha256': digest(combined.read_bytes()), 'files': []}
            for relative, _, after in pending:
                before, source_info = originals[relative]
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(before)
                saved.chmod(0o600)
                require(digest(saved.read_bytes()) == digest(before), 'BACKUP_HASH_MISMATCH')
                receipt['files'].append({'path': relative, 'before_sha256': digest(before),
                                         'after_sha256': digest(after),
                                         'mode': stat.S_IMODE(source_info.st_mode),
                                         'uid': source_info.st_uid, 'gid': source_info.st_gid})
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            receipt_path = backup / 'receipt.json'
            save_receipt(receipt_path, receipt)
            # Recheck after backup creation, immediately before applying the hunks.
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
            for relative, _, _ in pending:
                preflight_destination(root, relative)
            try:
                require(patch_apply(root, combined), 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    restore_metadata(root / relative, after, originals[relative][1])
                    installed, source_info = read_source(root / relative)
                    require(installed == after and source_info.st_gid == originals[relative][1].st_gid
                            and stat.S_IMODE(source_info.st_mode)
                            == stat.S_IMODE(originals[relative][1].st_mode),
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_sources(php, root)
            except BaseException:
                review = rollback(root, pending, originals)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                save_receipt(receipt_path, receipt)
                print(('HOME_STOCK_MINIMUM_ROLLBACK_REVIEW_REQUIRED' if review else
                       'HOME_STOCK_MINIMUM_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            save_receipt(receipt_path, receipt)
            print('HOME_STOCK_MINIMUM_OK files=' + str(len(pending))
                  + '; BACKUP=' + str(backup), flush=True)
            return backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()

    def interrupted(signum, frame):
        raise RuntimeError('INSTALL_INTERRUPTED_SIGNAL_' + str(signum))

    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    require(os.geteuid() == pwd.getpwnam('fasakha').pw_uid, 'RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.revision, args.php, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, KeyError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
