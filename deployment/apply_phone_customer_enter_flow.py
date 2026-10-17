#!/usr/bin/env python3
"""Install the phone customer Enter flow and clearer branch header with safe patches."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile


BASE_REVISION = '642dbfe4be0dc1097435bdb81e2606804b8b8955'
SOURCE_MANIFEST = {
    'public/dashboard/js/phone-orders.js': 'M',
    'public/dashboard/css/phone-orders.css': 'M',
}

MARKER = 'PHONE_CUSTOMER_ENTER_FLOW'


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
    for part in (path, *path.parents):
        require(not part.is_symlink(), 'SYMLINK_REJECTED: ' + str(part))


def content(path):
    safe(path)
    if not path.exists():
        return None
    require(path.is_file(), 'TARGET_NOT_REGULAR: ' + str(path))
    body = path.read_bytes()
    require(len(body) <= 4194304 and b'\x00' not in body, 'SOURCE_INVALID: ' + str(path))
    return body


def digest(body):
    return hashlib.sha256(body).hexdigest() if body is not None else None


def atomic(path, body, mode):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.phone-customer-enter-flow-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def patch_check(directory, patch, reverse=False):
    arguments = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        arguments.append('--reverse')
    return run([*arguments, str(patch)], cwd=directory).returncode == 0


def release_patch(root, revision, relative, status):
    result = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--unified=3', BASE_REVISION, revision, '--', relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= 4194304
            and b'GIT binary patch' not in result.stdout
            and b'Binary files ' not in result.stdout
            and b'old mode ' not in result.stdout
            and (status != 'A' or b'new file mode 100644\n' in result.stdout),
            'RELEASE_PATCH_INVALID: ' + relative)
    return result.stdout


def validate_release(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    require(root.is_absolute() and root.resolve() == root and root.is_dir(),
            'APPLICATION_ROOT_INVALID')
    safe(root)
    resolved = git(root, 'rev-parse', '--verify', revision + '^{commit}')
    require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision,
            'RELEASE_COMMIT_UNAVAILABLE')
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_MANIFEST)
    expected = {status + '\t' + path for path, status in SOURCE_MANIFEST.items()}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            'RELEASE_SOURCE_MANIFEST_MISMATCH')
    for relative in SOURCE_MANIFEST:
        tree = git(root, 'ls-tree', revision, '--', relative)
        require(tree.returncode == 0 and tree.stdout.startswith(b'100644 blob '),
                'RELEASE_FILE_MODE_INVALID: ' + relative)


def lint_php(php, directory):
    for relative in SOURCE_MANIFEST:
        if not relative.endswith('.php') or relative.endswith('.blade.php'):
            continue
        result = run([php, '-l', str(directory / relative)])
        require(result.returncode == 0, 'PHP_SYNTAX_INVALID: ' + relative)


def clear_views(php, root):
    result = run([php, 'artisan', 'view:clear', '--no-interaction'], cwd=root)
    require(result.returncode == 0, 'VIEW_CACHE_CLEAR_FAILED')


def install(root, revision, php, check_only=False):
    validate_release(root, revision)
    private = root.parent / 'phone-customer-enter-flow-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    require(private.is_dir() and stat.S_IMODE(private.stat().st_mode) == 0o700
            and private.stat().st_uid == os.geteuid(), 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals = {}
            pending = []
            conflicts = []
            for index, (relative, status) in enumerate(SOURCE_MANIFEST.items()):
                target = root / relative
                require(target.parent.is_dir(), 'REQUIRED_PARENT_MISSING: ' + relative)
                before = content(target)
                require(status == 'A' or before is not None, 'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                mode = stat.S_IMODE(target.stat().st_mode) if before is not None else 0o644
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                originals[relative] = (before, mode)
                patch_body = release_patch(root, revision, relative, status)
                patch = stage / ('patch-' + str(index) + '.diff')
                patch.write_bytes(patch_body)
                # Reverse first prevents duplicate insertion-only hunks or helpers.
                if patch_check(stage, patch, reverse=True):
                    continue
                if not patch_check(stage, patch):
                    conflicts.append(relative)
                    continue
                result = run(['git', 'apply', '--whitespace=nowarn', str(patch)], cwd=stage)
                require(result.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch_body, staged.read_bytes()))
            require(not conflicts, 'LIVE_CHANGE_NEEDS_REVIEW: ' + ', '.join(conflicts))
            lint_php(php, stage)
            if not pending:
                print(MARKER + '_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(body for _, body, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print(MARKER + '_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Include already-installed files in the race check. No checkout,
            # reset, index update or prior policy installer is used by this release.
            for relative, (before, mode) in originals.items():
                observed = content(root / relative)
                require(observed == before and (before is None
                        or stat.S_IMODE((root / relative).stat().st_mode) == mode),
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=prefix + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION,
                       'status': 'prepared', 'files': []}
            for relative, _, after in pending:
                before, mode = originals[relative]
                if before is not None:
                    saved = backup / relative
                    saved.parent.mkdir(parents=True, exist_ok=True)
                    saved.write_bytes(before)
                    saved.chmod(mode)
                receipt['files'].append({'path': relative, 'mode': mode,
                                         'existed': before is not None,
                                         'before': digest(before), 'after': digest(after)})
            receipt_path = backup / 'receipt.json'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            try:
                result = run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                require(result.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    require(content(root / relative) == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(php, root)
                clear_views(php, root)
            except BaseException:
                review = []
                for relative, _, after in pending:
                    try:
                        if content(root / relative) not in (originals[relative][0], after):
                            review.append(relative)
                    except (RuntimeError, OSError):
                        review.append(relative)
                if review:
                    # Retain the coherent group when another editor changed a
                    # consumer or helper; never overwrite independent live edits.
                    receipt['retained_source_group'] = [relative for relative, _, _ in pending]
                else:
                    for relative, _, after in reversed(pending):
                        target = root / relative
                        before, mode = originals[relative]
                        try:
                            observed = content(target)
                            if observed == after:
                                if before is None:
                                    target.unlink()
                                else:
                                    atomic(target, before, mode)
                            elif observed != before:
                                review.append(relative)
                        except (RuntimeError, OSError):
                            review.append(relative)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                if not review:
                    try:
                        clear_views(php, root)
                    except (RuntimeError, OSError, subprocess.SubprocessError):
                        receipt['view_cache_clear_failed_after_rollback'] = True
                receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
                print(MARKER + ('_ROLLBACK_REVIEW_REQUIRED' if review else '_CODE_RESTORED')
                      + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print(MARKER + '_OK files=' + str(len(pending)) + '; BACKUP=' + str(backup), flush=True)
            return backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    require(os.geteuid() == pwd.getpwnam('fasakha').pw_uid, 'RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.revision, args.php, check_only=args.check)
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
