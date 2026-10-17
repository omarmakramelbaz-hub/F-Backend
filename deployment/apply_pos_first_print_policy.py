#!/usr/bin/env python3
"""Apply the POS first-print policy with exact, reversible source patches."""
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


BASE_REVISION = '93857b2d83688ae94737b3e13295ccc08c1bde88'
# New helpers are published before their consumers, and removed last on rollback.
SOURCE_MANIFEST = {
    'app/Services/Dashboard/PosReceiptPrinting.php': 'A',
    'app/Services/Dashboard/TakeawayAccess.php': 'M',
    'app/Services/Dashboard/TakeawayService.php': 'M',
    'app/Services/Dashboard/PosServiceTicket.php': 'M',
    'app/Services/Dashboard/PhoneDeliveryBoard.php': 'M',
    'app/Http/Controllers/Dashboard/TakeawayController.php': 'M',
    'app/Http/Controllers/Dashboard/PosServiceController.php': 'M',
    'public/dashboard/js/takeaway-pos.js': 'M',
    'public/dashboard/js/dining-pos.js': 'M',
    'public/dashboard/js/phone-orders.js': 'M',
    'resources/views/admin/takeaway/receipt.blade.php': 'M',
    'resources/lang/ar/takeaway.php': 'M',
    'resources/lang/en/takeaway.php': 'M',
}


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
    descriptor, temporary = tempfile.mkstemp(prefix='.pos-first-print-', dir=path.parent)
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


def git(root, *arguments):
    return run(['git', '-C', str(root), *arguments])


def patch_check(directory, patch, reverse=False):
    arguments = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        arguments.append('--reverse')
    return run([*arguments, str(patch)], cwd=directory).returncode == 0


def lint_php(php, directory):
    for relative in SOURCE_MANIFEST:
        if not relative.endswith('.php') or relative.endswith('.blade.php'):
            continue
        result = run([php, '-l', str(directory / relative)])
        require(result.returncode == 0, 'PHP_SYNTAX_INVALID: ' + relative)


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
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_MANIFEST)
    expected = {status + '\t' + path for path, status in SOURCE_MANIFEST.items()}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            'RELEASE_SOURCE_MANIFEST_MISMATCH')

    private = root.parent / 'pos-first-print-policy-backups'
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
            for index, (relative, status) in enumerate(SOURCE_MANIFEST.items()):
                target = root / relative
                before = content(target)
                require(target.parent.is_dir(), 'REQUIRED_PARENT_MISSING: ' + relative)
                require(status == 'A' or before is not None,
                        'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                mode = stat.S_IMODE(target.stat().st_mode) if before is not None else 0o644
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                originals[relative] = (before, mode)

                patch = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                            '--full-index', '--unified=3', BASE_REVISION, revision, '--', relative)
                require(patch.returncode == 0 and 0 < len(patch.stdout) <= 4194304
                        and b'GIT binary patch' not in patch.stdout
                        and b'old mode ' not in patch.stdout
                        and (status != 'A' or b'new file mode 100644\n' in patch.stdout),
                        'RELEASE_PATCH_INVALID: ' + relative)
                patch_path = stage / ('patch-' + str(index) + '.diff')
                patch_path.write_bytes(patch.stdout)
                # Reverse first: additions and insertion-only hunks must not repeat.
                if patch_check(stage, patch_path, reverse=True):
                    continue
                require(patch_check(stage, patch_path), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                applied = run(['git', 'apply', '--whitespace=nowarn', str(patch_path)], cwd=stage)
                require(applied.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch.stdout, staged.read_bytes()))

            lint_php(php, stage)
            if not pending:
                print('POS_FIRST_PRINT_POLICY_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch for _, patch, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print('POS_FIRST_PRINT_POLICY_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Every target must still contain exactly the bytes checked above.
            for relative, _, _ in pending:
                require(content(root / relative) == originals[relative][0],
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
                # This does not use --index, checkout, reset, or whole-file replacement.
                applied = run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                require(applied.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    require(content(root / relative) == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(php, root)
            except BaseException:
                review = []
                # An unowned edit to an existing consumer may still need the new
                # helper or access methods. Keep the source group together instead
                # of removing dependencies underneath that preserved consumer.
                for relative, _, after in pending:
                    before, _ = originals[relative]
                    if before is None:
                        continue
                    try:
                        if content(root / relative) not in (before, after):
                            review.append(relative)
                    except (RuntimeError, OSError):
                        review.append(relative)
                if review:
                    receipt['retained_dependency_group'] = [relative for relative, _, _ in pending]
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
                            # An independently changed new helper can remain unused
                            # after the original consumers have been restored.
                            review.append(relative)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
                print(('POS_FIRST_PRINT_POLICY_ROLLBACK_REVIEW_REQUIRED' if review else
                       'POS_FIRST_PRINT_POLICY_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print('POS_FIRST_PRINT_POLICY_OK files=' + str(len(pending))
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
