#!/usr/bin/env python3
"""Apply the POS whole preparation label without replacing unrelated live edits."""
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


BASE_REVISION = 'b105fbf79a9c698a067213f26b0edd200516cbe7'
SOURCE_PATHS = (
    'public/dashboard/js/dining-pos.js',
    'public/dashboard/js/takeaway-pos.js',
    'app/Services/Dashboard/TakeawayCatalog.php',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(command, cwd=None):
    return subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=120)


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for part in (path, *path.parents):
        require(not part.is_symlink(), 'SYMLINK_REJECTED: ' + str(part))


def digest(content):
    return hashlib.sha256(content).hexdigest()


def atomic(path, content, mode):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.pos-whole-label-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def git(root, *arguments):
    return run(['git', '-C', str(root), *arguments])


def patch_check(directory, patch, reverse=False):
    arguments = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        arguments.append('--reverse')
    return run([*arguments, str(patch)], cwd=directory).returncode == 0


def lint_php(php, path):
    result = run([php, '-l', str(path)])
    require(result.returncode == 0, 'PHP_SYNTAX_INVALID: ' + path.name)


def install(root, revision, php, check_only=False):
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
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    expected = {'M\t' + path for path in SOURCE_PATHS}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            'RELEASE_MUST_MODIFY_ALL_THREE_POS_FILES')

    private = root.parent / 'pos-whole-label-backups'
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
            patches = []
            pending = []
            for relative in SOURCE_PATHS:
                target = root / relative
                safe(target)
                require(target.is_file(), 'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                before = target.read_bytes()
                require(len(before) <= 4194304 and b'\x00' not in before,
                        'LIVE_SOURCE_INVALID: ' + relative)
                mode = stat.S_IMODE(target.stat().st_mode)
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                staged.write_bytes(before)
                originals[relative] = (before, mode)

                patch = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                            '--full-index', '--unified=3', BASE_REVISION, revision, '--', relative)
                require(patch.returncode == 0 and 0 < len(patch.stdout) <= 4194304
                        and b'GIT binary patch' not in patch.stdout
                        and b'old mode ' not in patch.stdout,
                        'RELEASE_PATCH_INVALID: ' + relative)
                patch_path = stage / ('patch-' + str(len(patches)) + '.diff')
                patch_path.write_bytes(patch.stdout)
                patches.append(patch.stdout)
                # A reverse check comes first so pure insertions cannot be repeated.
                if patch_check(stage, patch_path, reverse=True):
                    continue
                require(patch_check(stage, patch_path), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                applied = run(['git', 'apply', '--whitespace=nowarn', str(patch_path)], cwd=stage)
                require(applied.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch.stdout, staged.read_bytes()))

            lint_php(php, stage / SOURCE_PATHS[2])
            if not pending:
                print('POS_WHOLE_LABEL_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch for _, patch, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print('POS_WHOLE_LABEL_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # No source file is changed until every target has passed preflight.
            for relative, _, _ in pending:
                safe(root / relative)
                require((root / relative).read_bytes() == originals[relative][0],
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=prefix + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION,
                       'status': 'prepared', 'files': []}
            for relative, _, after in pending:
                before, mode = originals[relative]
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(before)
                saved.chmod(mode)
                receipt['files'].append({'path': relative, 'mode': mode,
                                         'before': digest(before), 'after': digest(after)})
            receipt_path = backup / 'receipt.json'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            try:
                # git apply checks exact hunk context and preserves all other bytes.
                applied = run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                require(applied.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    require((root / relative).read_bytes() == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(php, root / SOURCE_PATHS[2])
            except BaseException:
                review = []
                for relative, _, after in reversed(pending):
                    target = root / relative
                    before, mode = originals[relative]
                    safe(target)
                    if target.is_file() and target.read_bytes() == after:
                        atomic(target, before, mode)
                    elif not target.is_file() or target.read_bytes() != before:
                        review.append(relative)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
                print(('POS_WHOLE_LABEL_ROLLBACK_REVIEW_REQUIRED' if review else
                       'POS_WHOLE_LABEL_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print('POS_WHOLE_LABEL_OK files=' + str(len(pending))
                  + '; BACKUP=' + str(backup), flush=True)
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


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
