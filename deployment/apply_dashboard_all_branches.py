#!/usr/bin/env python3
"""Install only dashboard branch defaults, preserving independent live changes."""
import argparse
import datetime
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import signal
import shutil
import stat
import subprocess
import tempfile

BASE_REVISION = '754bf038af50dcade0d43f2f6b537a5b155de4d0'
NEW_PATHS = ()
MODIFIED_PATHS = (
    'app/Http/Controllers/Dashboard/BranchExpensesController.php',
    'app/Http/Controllers/Dashboard/BranchOperationsController.php',
    'app/Http/Controllers/Dashboard/BranchShiftClosingController.php',
    'app/Http/Controllers/Dashboard/BranchStockController.php',
    'app/Http/Controllers/Dashboard/DesktopPosController.php',
    'app/Http/Controllers/Dashboard/DineInController.php',
    'app/Http/Controllers/Dashboard/PosServiceController.php',
    'app/Http/Controllers/Dashboard/TakeawayController.php',
    'app/Services/Dashboard/BranchCustomers.php',
    'app/Services/Dashboard/BranchExpenses.php',
    'app/Services/Dashboard/BranchPayroll.php',
    'app/Services/Dashboard/BranchShiftClosing.php',
    'app/Services/Dashboard/BranchStock.php',
    'app/Services/Dashboard/DeliveryCompanies.php',
    'app/Services/Dashboard/PosServiceTable.php',
    'app/Services/Dashboard/TakeawayService.php',
    'public/dashboard/js/branch-expenses.js',
    'public/dashboard/js/branch-recipes.js',
    'public/dashboard/js/branch-shifts.js',
    'public/dashboard/js/branch-stock.js',
    'public/dashboard/js/dining-pos.js',
    'public/dashboard/js/home-overview.js',
    'public/dashboard/js/takeaway-pos.js',
    'resources/lang/ar/dining.php',
    'resources/lang/ar/takeaway.php',
    'resources/lang/en/dining.php',
    'resources/lang/en/takeaway.php',
    'resources/views/admin/branch_shifts/index.blade.php',
    'resources/views/admin/branch_stock/index.blade.php',
    'resources/views/admin/desktop_pos/index.blade.php',
    'resources/views/admin/dining/index.blade.php',
    'resources/views/admin/expenses/index.blade.php',
    'resources/views/admin/partials/search_part.blade.php',
    'resources/views/admin/reports/resturants_report.blade.php',
    'resources/views/admin/takeaway/index.blade.php',
)
SOURCE_PATHS = NEW_PATHS + MODIFIED_PATHS
BRANCH_EXPENSES = 'app/Services/Dashboard/BranchExpenses.php'
EXPENSE_LISTING_ANCHOR = (b'    public function listing(array $values,$actor,bool $export=false): array\n'
                          b'    {\n')
EXPENSE_DEFAULT_LINES = (
    b'        $actor=$this->access->actor($actor);\n',
    b"        if(!array_key_exists('branch',$values)&&$actor->account_type==='admin'&&empty($actor->owner_resturant_id))$values['branch']='all';\n",
)
MAX_BYTES = 4 * 1024 * 1024
MARKER = 'DASHBOARD_ALL_BRANCHES'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(command, cwd=None):
    return subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=180)


def git(root, *arguments):
    return run(['git', '-C', str(root), *arguments])


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for candidate in (path, *path.parents):
        require(not candidate.is_symlink(), 'SYMLINK_REJECTED: ' + str(candidate))


def read_source(path, absent_ok=False):
    safe(path)
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        if absent_ok:
            return None, None
        raise
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1,
                'REGULAR_UNLINKED_SOURCE_REQUIRED: ' + str(path))
        require(info.st_uid == os.geteuid(), 'FASAKHA_OWNED_SOURCE_REQUIRED: ' + str(path))
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body, 'SOURCE_INVALID: ' + str(path))
    return body, info


def digest(body):
    return None if body is None else hashlib.sha256(body).hexdigest()


def restore_metadata(path, expected_body, original_info):
    safe(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid() and stream.read(MAX_BYTES + 1) == expected_body,
                'UNOWNED_SOURCE_METADATA_CHANGE: ' + str(path))
        group = original_info.st_gid if original_info else os.getegid()
        if info.st_gid != group:
            os.fchown(stream.fileno(), -1, group)
        os.fchmod(stream.fileno(), stat.S_IMODE(original_info.st_mode) if original_info else 0o644)


def unchanged(path, original):
    body, info = read_source(path, absent_ok=original[0] is None)
    old_body, old_info = original
    if old_body is None:
        return body is None
    return (body == old_body and info.st_uid == old_info.st_uid and info.st_gid == old_info.st_gid
            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode)
            and info.st_ino == old_info.st_ino and info.st_dev == old_info.st_dev
            and info.st_mtime_ns == old_info.st_mtime_ns)


def patch_check(directory, patch, reverse=False):
    arguments = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        arguments.append('--reverse')
    return run([*arguments, str(patch)], cwd=directory).returncode == 0


def patch_apply(directory, patch, reverse=False):
    arguments = ['git', 'apply', '--whitespace=nowarn']
    if reverse:
        arguments.append('--reverse')
    return run([*arguments, str(patch)], cwd=directory).returncode == 0


def lint_sources(php, directory):
    """Parse disposable candidate code without loading its application classes."""
    node = shutil.which('node')
    for relative in SOURCE_PATHS:
        if relative.endswith('.php'):
            require(run([php, '-l', str(directory / relative)]).returncode == 0,
                    'PHP_SYNTAX_INVALID: ' + relative)
        elif relative.endswith('.js') and node:
            require(run([node, '--check', str(directory / relative)]).returncode == 0,
                    'JS_SYNTAX_INVALID: ' + relative)


def validate_release(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    require(SOURCE_PATHS and len(set(SOURCE_PATHS)) == len(SOURCE_PATHS), 'RELEASE_MANIFEST_INVALID')
    for commit in (BASE_REVISION, revision):
        result = git(root, 'rev-parse', '--verify', commit + '^{commit}')
        require(result.returncode == 0 and result.stdout.decode().strip() == commit,
                'PINNED_COMMIT_UNAVAILABLE: ' + commit)
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                  '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    expected = {'M\t' + path for path in MODIFIED_PATHS} | {'A\t' + path for path in NEW_PATHS}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            'RELEASE_SOURCE_MANIFEST_MISMATCH')
    for commit, paths in ((BASE_REVISION, MODIFIED_PATHS), (revision, SOURCE_PATHS)):
        if not paths:
            continue
        result = git(root, 'ls-tree', '-z', commit, '--', *paths)
        entries = result.stdout.rstrip(b'\x00').split(b'\x00')
        require(result.returncode == 0 and len(entries) == len(paths), 'RELEASE_SOURCE_TREE_MISMATCH')
        for entry in entries:
            header, relative = entry.split(b'\t', 1)
            mode, kind, _ = header.split(b' ', 2)
            require(mode in (b'100644', b'100755') and kind == b'blob'
                    and relative.decode() in paths, 'RELEASE_SOURCE_NOT_REGULAR')


def release_body(root, revision, relative):
    result = git(root, 'show', revision + ':' + relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= MAX_BYTES
            and b'\x00' not in result.stdout, 'RELEASE_SOURCE_INVALID: ' + relative)
    return result.stdout


def expense_defaults_candidate(before, base, release):
    """Add only the reviewed default beside older live expense filter implementations."""
    addition = b''.join(EXPENSE_DEFAULT_LINES)
    require(base.count(EXPENSE_LISTING_ANCHOR) == 1
            and release == base.replace(EXPENSE_LISTING_ANCHOR,
                                        EXPENSE_LISTING_ANCHOR + addition, 1),
            'EXPENSE_DEFAULT_RELEASE_NEEDS_REVIEW')
    require(before.count(EXPENSE_LISTING_ANCHOR) == 1,
            'EXPENSE_DEFAULT_LIVE_ANCHOR_NEEDS_REVIEW')
    prefix, _, tail = before.partition(EXPENSE_LISTING_ANCHOR)
    listing, separator, _ = tail.partition(b'\n    public function ')
    require(separator and listing.endswith(b'    }'), 'EXPENSE_DEFAULT_LIVE_METHOD_NEEDS_REVIEW')
    if listing.startswith(addition):
        require(all(listing.count(line) == 1 for line in EXPENSE_DEFAULT_LINES),
                'EXPENSE_DEFAULT_DUPLICATE_LINES_NEED_REVIEW')
        return before
    require(all(line not in listing for line in EXPENSE_DEFAULT_LINES),
            'EXPENSE_DEFAULT_PARTIAL_LINES_NEED_REVIEW')
    # The older deployment keeps its query on this same line. Inserting directly
    # after the unique method anchor preserves that line and every category rule.
    require(listing.startswith(b'        $v=$this->filters($values);[$base,$branches]=$this->scope($v,$actor);'),
            'EXPENSE_DEFAULT_LIVE_METHOD_NEEDS_REVIEW')
    return prefix + EXPENSE_LISTING_ANCHOR + addition + tail


def merge_candidate(stage, relative, before, base, release):
    """Three-way merge preserves live edits and accepts already-applied release hunks."""
    if before == release:
        return before
    if relative in NEW_PATHS:
        require(before is None, 'NEW_SOURCE_COLLISION_NEEDS_REVIEW: ' + relative)
        return release
    require(before is not None, 'LIVE_SOURCE_REQUIRED: ' + relative)
    if before == base:
        return release
    expense_candidate = (expense_defaults_candidate(before, base, release)
                         if relative == BRANCH_EXPENSES else None)
    merge_dir = Path(tempfile.mkdtemp(prefix='merge-', dir=stage))
    current_path, base_path, release_path = (merge_dir / name for name in ('current', 'base', 'release'))
    current_path.write_bytes(before)
    base_path.write_bytes(base)
    release_path.write_bytes(release)
    result = run(['git', 'merge-file', '-p', '--diff3', str(current_path), str(base_path), str(release_path)])
    if result.returncode != 0 and expense_candidate is not None:
        return expense_candidate
    require(result.returncode == 0, 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
    require(0 < len(result.stdout) <= MAX_BYTES and b'\x00' not in result.stdout,
            'MERGED_SOURCE_INVALID: ' + relative)
    require(expense_candidate is None or result.stdout == expense_candidate,
            'EXPENSE_DEFAULT_MERGE_NEEDS_REVIEW')
    return result.stdout


def exact_patch(relative, before, after):
    """Patch the observed live bytes only; never substitute a release file wholesale."""
    source = '/dev/null' if before is None else 'a/' + relative
    parts = [('diff --git a/' + relative + ' b/' + relative + '\n').encode()]
    if before is None:
        parts.append(b'new file mode 100644\n')
    lines = difflib.diff_bytes(difflib.unified_diff, (before or b'').splitlines(keepends=True),
                              after.splitlines(keepends=True), fromfile=source.encode(),
                              tofile=('b/' + relative).encode(), n=3)
    for line in lines:
        parts.append(line if line.endswith(b'\n') else line + b'\n\\ No newline at end of file\n')
    return b''.join(parts)


COMPILED_VIEWS_PHP = r"""
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $compiler=$app->make('blade.compiler');$paths=[];
 foreach(json_decode($argv[1],true,512,JSON_THROW_ON_ERROR)as$relative){
  $source=realpath($relative);if($source===false)throw new \RuntimeException();
  $paths[]=$compiler->getCompiledPath($source);
 }
 while(ob_get_level()>0)ob_end_clean();
 echo json_encode($paths,JSON_THROW_ON_ERROR);
}catch(\Throwable $ignore){while(ob_get_level()>0)ob_end_clean();exit(1);}
"""


def compiled_views(root, php, pending):
    views = [relative for relative, _, _ in pending if relative.endswith('.blade.php')]
    if not views:
        return ()
    result = run([php, '-r', COMPILED_VIEWS_PHP, json.dumps(views)], cwd=root)
    try:
        values = json.loads(result.stdout) if result.returncode == 0 else None
    except (ValueError, TypeError):
        values = None
    require(isinstance(values, list) and len(values) == len(views)
            and all(isinstance(value, str) for value in values), 'COMPILED_VIEW_PATHS_NEED_REVIEW')
    paths = tuple(Path(value) for value in values)
    for path in paths:
        safe(path)
        require(path.parent == root / 'storage/framework/views' and path.suffix == '.php',
                'COMPILED_VIEW_LOCATION_NEEDS_REVIEW')
        read_source(path, absent_ok=True)
    return paths


def clear_compiled_views(paths):
    # Clear only compiled templates whose source is changed by this release.
    for path in paths:
        body, info = read_source(path, absent_ok=True)
        if body is not None:
            path.unlink()


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


def rollback(root, applied, originals):
    """Reverse only files whose complete installed bytes and metadata are still ours."""
    review = []
    for relative, patch, after in reversed(applied):
        try:
            target = root / relative
            before, old_info = originals[relative]
            observed, info = read_source(target, absent_ok=before is None)
            if observed == before:
                if before is not None:
                    require(info.st_gid == old_info.st_gid
                            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode),
                            'UNOWNED_SOURCE_METADATA_CHANGE')
                continue
            require(observed == after, 'UNOWNED_CHANGE_DURING_ROLLBACK')
            require(info.st_gid == (old_info.st_gid if old_info else os.getegid())
                    and stat.S_IMODE(info.st_mode)
                    == (stat.S_IMODE(old_info.st_mode) if old_info else 0o644),
                    'UNOWNED_SOURCE_METADATA_CHANGE')
            require(patch_check(root, patch, reverse=True), 'ROLLBACK_CHECK_FAILED')
            require(patch_apply(root, patch, reverse=True), 'ROLLBACK_PATCH_FAILED')
            if before is not None:
                restore_metadata(target, before, old_info)
            require(read_source(target, absent_ok=before is None)[0] == before,
                    'ROLLBACK_VERIFICATION_FAILED')
        except (RuntimeError, OSError, subprocess.SubprocessError):
            review.append(relative)
    return review


def install(root, revision, php, check_only=False):
    os.umask(0o077)
    validate_release(root, revision)
    private = root.parent / 'dashboard-all-branches-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(private.is_dir() and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700, 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    descriptor = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid()
                and stat.S_IMODE(info.st_mode) == 0o600, 'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals, pending = {}, []
            for index, relative in enumerate(SOURCE_PATHS):
                before, source_info = read_source(root / relative, absent_ok=relative in NEW_PATHS)
                originals[relative] = (before, source_info)
                base = None if relative in NEW_PATHS else release_body(root, BASE_REVISION, relative)
                release = release_body(root, revision, relative)
                after = merge_candidate(stage, relative, before, base, release)
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                    staged.chmod(stat.S_IMODE(source_info.st_mode))
                if after == before:
                    continue
                patch = stage / ('patch-' + str(index) + '.diff')
                patch.write_bytes(exact_patch(relative, before, after))
                require(patch_check(stage, patch), 'STAGED_PATCH_PREFLIGHT_FAILED: ' + relative)
                require(patch_apply(stage, patch), 'STAGED_PATCH_FAILED: ' + relative)
                require(staged.read_bytes() == after, 'STAGED_PATCH_VERIFICATION_FAILED: ' + relative)
                pending.append((relative, patch, after))
            lint_sources(php, stage)
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            if not pending:
                print(MARKER + '_OK already_current=1 files=0', flush=True)
                return None
            compiled = compiled_views(root, php, pending)
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch.read_bytes() for _, patch, _ in pending))
            require(patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print(MARKER + '_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=stamp + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION, 'status': 'prepared',
                       'compiled_views': [str(path) for path in compiled], 'files': []}
            for relative, patch, after in pending:
                before, source_info = originals[relative]
                saved = backup / relative
                saved.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    saved.write_bytes(before)
                    saved.chmod(0o600)
                    require(digest(saved.read_bytes()) == digest(before), 'BACKUP_HASH_MISMATCH')
                receipt['files'].append({'path': relative, 'before_sha256': digest(before),
                                         'after_sha256': digest(after),
                                         'mode': stat.S_IMODE(source_info.st_mode) if source_info else None,
                                         'uid': source_info.st_uid if source_info else None,
                                         'gid': source_info.st_gid if source_info else None})
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            receipt_path = backup / 'receipt.json'
            save_receipt(receipt_path, receipt)
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
            applied = []
            try:
                for item in pending:
                    relative, patch, after = item
                    require(unchanged(root / relative, originals[relative]),
                            'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
                    applied.append(item)
                    require(patch_apply(root, patch), 'LIVE_PATCH_FAILED: ' + relative)
                    restore_metadata(root / relative, after, originals[relative][1])
                    require(read_source(root / relative)[0] == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_sources(php, root)
                expected = {relative: after for relative, _, after in pending}
                for relative, (before, original_info) in originals.items():
                    installed, info = read_source(root / relative)
                    require(installed == expected.get(relative, before)
                            and info.st_gid == (original_info.st_gid if original_info else os.getegid())
                            and stat.S_IMODE(info.st_mode)
                            == (stat.S_IMODE(original_info.st_mode) if original_info else 0o644),
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                clear_compiled_views(compiled)
            except BaseException:
                review = rollback(root, applied, originals)
                try:
                    clear_compiled_views(compiled)
                except (RuntimeError, OSError):
                    review.append('compiled_views')
                receipt['status'] = 'rollback_review_required' if review else 'code_restored'
                receipt['review'] = review
                save_receipt(receipt_path, receipt)
                print(MARKER + ('_ROLLBACK_REVIEW_REQUIRED' if review else '_CODE_RESTORED')
                      + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            save_receipt(receipt_path, receipt)
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
    def interrupted(signum, frame):
        raise RuntimeError('INSTALL_INTERRUPTED')
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    install(Path(args.root), args.revision, args.php, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, KeyError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
