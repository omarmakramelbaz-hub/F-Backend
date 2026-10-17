#!/usr/bin/env python3
"""Install the unified phone/delivery screen with local-change-safe patches."""
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


BASE_REVISION = 'fe1d8499df06d72d3d482c18dc30792c043d7840'
CONSENT_BASE_REVISION = '43dd121c06c37076a086e452a21b32d151df91f9'
SOURCE_MANIFEST = (
    'public/dashboard/js/phone-orders.js',
    'public/dashboard/css/phone-orders.css',
    'resources/views/admin/phone_orders/index.blade.php',
    'resources/lang/ar/phone_orders.php',
    'resources/lang/en/phone_orders.php',
)
CONSENT_MANIFEST = (*SOURCE_MANIFEST,
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Http/Controllers/Dashboard/PosServiceController.php',
    'app/Services/Dashboard/TakeawayCatalog.php',
)
FIRST_PRINT_BASE_REVISION = '93857b2d83688ae94737b3e13295ccc08c1bde88'
FIRST_PRINT_MANIFEST = {
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
MARKER = 'PHONE_DELIVERY_UNIFIED'


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
    require(path.is_file(), 'REQUIRED_LIVE_FILE_MISSING: ' + str(path))
    body = path.read_bytes()
    require(len(body) <= 4194304 and b'\x00' not in body, 'SOURCE_INVALID: ' + str(path))
    return body


def digest(body):
    return hashlib.sha256(body).hexdigest()


def atomic(path, body, mode):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.phone-delivery-unified-', dir=path.parent)
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


def release_patch(root, before, after, relative):
    result = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--unified=3', before, after, '--', relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= 4194304
            and b'GIT binary patch' not in result.stdout
            and b'Binary files ' not in result.stdout
            and b'old mode ' not in result.stdout,
            'RELEASE_PATCH_INVALID: ' + relative)
    return result.stdout


def require_manifest(root, before, after, paths, marker):
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', before, after, '--', *paths)
    expected = {status + '\t' + path for path, status in paths.items()} if isinstance(paths, dict) else {'M\t' + path for path in paths}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            marker)
    for relative in paths:
        tree = git(root, 'ls-tree', after, '--', relative)
        require(tree.returncode == 0 and tree.stdout.startswith(b'100644 blob '),
                'RELEASE_FILE_MODE_INVALID: ' + relative)


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
    require_manifest(root, BASE_REVISION, revision, SOURCE_MANIFEST,
                     'RELEASE_SOURCE_MANIFEST_MISMATCH')
    require_manifest(root, CONSENT_BASE_REVISION, BASE_REVISION, CONSENT_MANIFEST,
                     'CONSENT_RELEASE_MANIFEST_MISMATCH')
    require_manifest(root, FIRST_PRINT_BASE_REVISION, CONSENT_BASE_REVISION, FIRST_PRINT_MANIFEST,
                     'FIRST_PRINT_RELEASE_MANIFEST_MISMATCH')


def lint_php(php, directory, paths):
    for relative in paths:
        if not relative.endswith('.php') or relative.endswith('.blade.php'):
            continue
        result = run([php, '-l', str(directory / relative)])
        require(result.returncode == 0, 'PHP_SYNTAX_INVALID: ' + relative)


def prove_prerequisites(root, prepared, revision):
    """Normalize final staged copies and prove the full installed first-print policy."""
    with tempfile.TemporaryDirectory(prefix='phone-delivery-prerequisite-proof-') as temporary:
        stage = Path(temporary)
        for relative in dict.fromkeys((*CONSENT_MANIFEST, *FIRST_PRINT_MANIFEST)):
            target = stage / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content(prepared / relative))
        for label, before, after, paths in (
            ('unified', BASE_REVISION, revision, SOURCE_MANIFEST),
            ('consent', CONSENT_BASE_REVISION, BASE_REVISION, CONSENT_MANIFEST),
        ):
            for index, relative in enumerate(paths):
                patch = stage / (label + '-' + str(index) + '.diff')
                patch.write_bytes(release_patch(root, before, after, relative))
                require(patch_check(stage, patch, reverse=True),
                        MARKER + '_PREREQUISITE_PROOF_FAILED: ' + relative)
                result = run(['git', 'apply', '--reverse', '--whitespace=nowarn', str(patch)], cwd=stage)
                require(result.returncode == 0, 'PREREQUISITE_NORMALIZATION_FAILED: ' + relative)
        for index, relative in enumerate(FIRST_PRINT_MANIFEST):
            patch = stage / ('first-print-' + str(index) + '.diff')
            patch.write_bytes(release_patch(root, FIRST_PRINT_BASE_REVISION, CONSENT_BASE_REVISION, relative))
            require(patch_check(stage, patch, reverse=True),
                    MARKER + '_FIRST_PRINT_PREREQUISITE_REQUIRED: ' + relative)


def clear_views(php, root):
    result = run([php, 'artisan', 'view:clear', '--no-interaction'], cwd=root)
    require(result.returncode == 0, 'VIEW_CACHE_CLEAR_FAILED')


def install(root, revision, php, check_only=False):
    validate_release(root, revision)

    private = root.parent / 'phone-delivery-unified-backups'
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
            # Fixed prerequisite paths are staged in the same transaction. A live
            # 43dd installation may need all eight; fe1d needs only the five UI files.
            paths = tuple(dict.fromkeys((*CONSENT_MANIFEST, *FIRST_PRINT_MANIFEST)))
            for relative in paths:
                target = root / relative
                before = content(target)
                mode = stat.S_IMODE(target.stat().st_mode)
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                staged.write_bytes(before)
                originals[relative] = (before, mode)
            for index, relative in enumerate(CONSENT_MANIFEST):
                staged = stage / relative
                final_revision = revision if relative in SOURCE_MANIFEST else BASE_REVISION
                patch_body = release_patch(root, CONSENT_BASE_REVISION, final_revision, relative)
                patch = stage / ('patch-' + str(index) + '.diff')
                patch.write_bytes(patch_body)
                # Reverse first so insertion-only hunks cannot duplicate on reruns.
                if patch_check(stage, patch, reverse=True):
                    continue
                if relative in SOURCE_MANIFEST:
                    consent = stage / ('consent-' + str(index) + '.diff')
                    consent.write_bytes(release_patch(root, CONSENT_BASE_REVISION, BASE_REVISION, relative))
                    if patch_check(stage, consent, reverse=True):
                        # A complete fe1d file needs only the new UI hunks. This
                        # avoids applying a cumulative patch to partially installed hunks.
                        patch_body = release_patch(root, BASE_REVISION, revision, relative)
                        patch.write_bytes(patch_body)
                if not patch_check(stage, patch):
                    conflicts.append(relative)
                    continue
                result = run(['git', 'apply', '--whitespace=nowarn', str(patch)], cwd=stage)
                require(result.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch_body, staged.read_bytes()))
            require(not conflicts, 'LIVE_CHANGE_NEEDS_REVIEW: ' + ', '.join(conflicts))
            lint_php(php, stage, paths)
            prove_prerequisites(root, stage, revision)
            if not pending:
                print(MARKER + '_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(body for _, body, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print(MARKER + '_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Check every source file immediately before backup/publication,
            # including files recognized as already current.
            for relative, (before, mode) in originals.items():
                require(content(root / relative) == before
                        and stat.S_IMODE((root / relative).stat().st_mode) == mode,
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
                # Git patches preserve unrelated edits without reset/checkout/index changes.
                result = run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                require(result.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    require(content(root / relative) == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(php, root, paths)
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
                    # Keep the coherent source group when a concurrent editor
                    # changed one member; never overwrite somebody else's bytes.
                    receipt['retained_source_group'] = [relative for relative, _, _ in pending]
                else:
                    for relative, _, after in reversed(pending):
                        target = root / relative
                        before, mode = originals[relative]
                        try:
                            if content(target) == after:
                                atomic(target, before, mode)
                            elif content(target) != before:
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
