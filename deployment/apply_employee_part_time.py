#!/usr/bin/env python3
"""Apply only employee part-time attendance changes and their additive schema."""
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

BASE_REVISION = 'a1a2764cab407a8bc2a871403dfcdee304bba220'
MIGRATION = 'database/migrations/2026_10_18_160000_add_employee_part_time_schedule.php'
NEW_PATHS = (MIGRATION,)
MODIFIED_PATHS = (
    'app/Services/Dashboard/EmployeeAttendanceRules.php',
    'app/Services/Dashboard/EmployeeAttendanceDeductionCap.php',
    'app/Services/Dashboard/BranchPayroll.php',
    'public/dashboard/js/branch-operations.js',
)
SOURCE_PATHS = NEW_PATHS + MODIFIED_PATHS
MAX_BYTES = 4 * 1024 * 1024
EMPLOYEE_JS = 'public/dashboard/js/branch-operations.js'
PART_TIME_JS_BLOB = '11b1a99c105594e574c327d74b4b16425b1503cc'
CLOCK_JS_BEFORE = '038a2d2d7b5c28cb59bc00bbfd0131501aa62d1c'
CLOCK_JS_AFTER = '1160afb81c92879f0b957fb2305dea5617dde055'


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
    require(len(body) <= MAX_BYTES and b'\x00' not in body,
            'SOURCE_INVALID: ' + str(path))
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
    return (body == old_body and info.st_uid == old_info.st_uid
            and info.st_gid == old_info.st_gid
            and stat.S_IMODE(info.st_mode) == stat.S_IMODE(old_info.st_mode)
            and info.st_ino == old_info.st_ino and info.st_dev == old_info.st_dev
            and info.st_mtime_ns == old_info.st_mtime_ns)


def patch_check(directory, patch, reverse=False):
    command = ['git', 'apply', '--check', '--whitespace=nowarn']
    if reverse:
        command.append('--reverse')
    return run([*command, str(patch)], cwd=directory).returncode == 0


def patch_apply(directory, patch, reverse=False):
    command = ['git', 'apply', '--whitespace=nowarn']
    if reverse:
        command.append('--reverse')
    return run([*command, str(patch)], cwd=directory).returncode == 0


def blob_sha(body):
    return hashlib.sha1(b'blob ' + str(len(body)).encode() + b'\x00' + body).hexdigest()


def scoped_js_candidate(before, released):
    """Preserve the fully pinned, reviewed live clock variant outside four employee edits."""
    observed = blob_sha(before)
    require(observed in (CLOCK_JS_BEFORE, CLOCK_JS_AFTER), 'LIVE_CHANGE_NEEDS_REVIEW: ' + EMPLOYEE_JS)
    require(blob_sha(released) == PART_TIME_JS_BLOB, 'EMPLOYEE_JS_RELEASE_NEEDS_REVIEW')
    if observed == CLOCK_JS_AFTER:
        return before
    current, wanted = before.decode('utf-8'), released.decode('utf-8')

    def region(source, start, end):
        require(source.count(start) == source.count(end) == 1,
                'EMPLOYEE_JS_BOUNDARY_NEEDS_REVIEW')
        first, last = source.index(start), source.index(end)
        require(first < last, 'EMPLOYEE_JS_BOUNDARY_ORDER_INVALID')
        return source[first:last]

    attendance = region(current, 'function attendanceCell(item){',
                        'function recordAttendance(item,status,action){')
    helpers_and_attendance = region(wanted, 'function employeeSchedule(item){',
                                    'function recordAttendance(item,status,action){')
    edits = (
        ('var names={morning:', "var names={part_time:'حضور دوام جزئي',morning:"),
        ('labelNode(item.job_title,item.shift)', 'labelNode(item.job_title,employeeSchedule(item))'),
        ("field(f,'shift','الوردية',source.shift);",
         "field(f,'shift','الوردية',source.shift);employeeScheduleFields(f,source);"),
        (attendance, helpers_and_attendance),
    )
    for old, new in edits:
        require(current.count(old) == 1, 'EMPLOYEE_JS_ANCHOR_NEEDS_REVIEW')
        current = current.replace(old, new, 1)
    candidate = current.encode('utf-8')
    require(blob_sha(candidate) == CLOCK_JS_AFTER, 'EMPLOYEE_JS_CANDIDATE_NEEDS_REVIEW')
    return candidate


def exact_live_patch(relative, before, after):
    """Use the actual live bytes as both the install and reverse-rollback patch base."""
    body = ''.join(difflib.unified_diff(
        before.decode('utf-8').splitlines(keepends=True),
        after.decode('utf-8').splitlines(keepends=True),
        fromfile='a/' + relative, tofile='b/' + relative, n=3)).encode('utf-8')
    require(body and len(body) <= MAX_BYTES, 'EMPLOYEE_JS_COMPAT_PATCH_INVALID')
    header = ('diff --git a/' + relative + ' b/' + relative + '\nindex '
              + blob_sha(before) + '..' + blob_sha(after) + ' 100644\n').encode()
    return header + body


def lint_sources(php, directory, application_root, cache_directory):
    """Compile disposable candidates without loading candidate application code."""
    autoload = application_root / 'vendor/autoload.php'
    require(autoload.is_file(), 'PHP_VENDOR_AUTOLOAD_REQUIRED')
    cache_directory.mkdir(mode=0o700, exist_ok=True)
    blade_program = r"""
require $argv[1];
$compiler = new Illuminate\View\Compilers\BladeCompiler(
    new Illuminate\Filesystem\Filesystem, $argv[3]
);
$source = file_get_contents($argv[2]);
if ($source === false) { throw new RuntimeException('BLADE_SOURCE_UNREADABLE'); }
token_get_all($compiler->compileString($source), TOKEN_PARSE);
"""
    node = shutil.which('node')
    for relative in SOURCE_PATHS:
        if relative.endswith('.blade.php'):
            result = run([php, '-r', blade_program, str(autoload),
                          str(directory / relative), str(cache_directory)])
            require(result.returncode == 0, 'BLADE_COMPILE_INVALID: ' + relative)
        elif relative.endswith('.php'):
            result = run([php, '-l', str(directory / relative)])
            require(result.returncode == 0, 'PHP_PARSE_INVALID: ' + relative)
        elif relative.endswith('.js') and node:
            result = run([node, '--check', str(directory / relative)])
            require(result.returncode == 0, 'JS_PARSE_INVALID: ' + relative)


SCHEMA_PHP = r"""
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $schema=\Illuminate\Support\Facades\Schema::class;$db=\Illuminate\Support\Facades\DB::class;
 $required=[
  'migrations'=>['migration'],
  'branch_employees'=>['id','branch','shift','revision','actor_id'],
  'branch_employee_days'=>['id','employee_id','day','status','checked_in_at','checked_out_at','attendance_rule_snapshot'],
  'branch_attendance_rules'=>['branch','shift','starts_at','ends_at','late_half_hour_cents','early_half_hour_cents','absence_cents','revision'],
  'branch_employee_entries'=>['employee_id','day','source_key','amount_cents','voided_at'],
  'branch_operation_commands'=>['branch','actor_id','request_key','request_hash','result'],
  'branch_employee_salaries'=>['employee_id','effective_month','amount_cents'],
  'branch_payrolls'=>['employee_id','month'],
 ];
 $migration='2026_10_18_160000_add_employee_part_time_schedule';
 foreach($required as $table=>$columns){
  if(!$schema::hasTable($table)||!$schema::hasColumns($table,$columns))throw new \RuntimeException();
 }
 $recorded=(int)$db::table('migrations')->where('migration',$migration)->count();
 if(!in_array($recorded,[0,1],true))throw new \RuntimeException();
 $columns=['schedule_type','attendance_start','attendance_end','attendance_shift'];
 $present=$schema::hasColumns('branch_employees',$columns);
 if($recorded===1&&!$present)throw new \RuntimeException();
 // An unexpected pre-existing column definition needs review before any files change.
 if($db::connection()->getDriverName()!=='mysql')throw new \RuntimeException();
 $definitions=$db::select('SHOW COLUMNS FROM `'.$db::connection()->getTablePrefix().'branch_employees`');
 foreach($definitions as$definition){
  if(!in_array($definition->Field,$columns,true))continue;
  if(in_array($definition->Field,['attendance_start','attendance_end'],true)){
   if(!preg_match('/^time(?:\(0\))?$/i',$definition->Type)||$definition->Null!=='YES')throw new \RuntimeException();
  }else{
   if(!preg_match('/^varchar\(20\)$/i',$definition->Type))throw new \RuntimeException();
   if($definition->Field==='schedule_type'&&($definition->Null!=='NO'||!in_array($definition->Default,['branch',"'branch'"],true)))throw new \RuntimeException();
   if($definition->Field==='attendance_shift'&&$definition->Null!=='YES')throw new \RuntimeException();
  }
 }
 foreach(array_diff(array_keys($required),['migrations'])as$table){
  $meta=$db::selectOne('SELECT ENGINE AS table_engine FROM information_schema.TABLES '
   .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$db::connection()->getTablePrefix().$table]);
  if(!$meta||strtoupper((string)$meta->table_engine)!=='INNODB')throw new \RuntimeException();
 }
 while(ob_get_level()>0)ob_end_clean();
 echo json_encode(['present'=>$present,'recorded'=>$recorded===1],JSON_THROW_ON_ERROR);
}catch(\Throwable $ignore){while(ob_get_level()>0)ob_end_clean();exit(1);}
"""


def schema_state(root, php):
    result = run([php, '-r', SCHEMA_PHP], cwd=root)
    try:
        state = json.loads(result.stdout) if result.returncode == 0 else None
    except (ValueError, TypeError):
        state = None
    require(isinstance(state, dict) and set(state) == {'present', 'recorded'}
            and all(type(value) is bool for value in state.values()),
            'EMPLOYEE_PART_TIME_SCHEMA_NEEDS_REVIEW')
    require(not state['recorded'] or state['present'],
            'EMPLOYEE_PART_TIME_RECORDED_MIGRATION_INCOMPLETE')
    return state


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
    expected = {'M\t' + path for path in MODIFIED_PATHS} | {'A\t' + path for path in NEW_PATHS}
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == expected,
            'RELEASE_SOURCE_MANIFEST_MISMATCH')
    for commit, paths in ((BASE_REVISION, MODIFIED_PATHS), (revision, SOURCE_PATHS)):
        result = git(root, 'ls-tree', '-z', commit, '--', *paths)
        require(result.returncode == 0, 'RELEASE_TREE_UNAVAILABLE')
        entries = result.stdout.rstrip(b'\x00').split(b'\x00')
        require(len(entries) == len(paths), 'RELEASE_SOURCE_TREE_MISMATCH')
        for entry in entries:
            header, relative = entry.split(b'\t', 1)
            mode, kind, _ = header.split(b' ', 2)
            require(mode in (b'100644', b'100755') and kind == b'blob'
                    and relative.decode() in paths, 'RELEASE_SOURCE_NOT_REGULAR')


def release_patch(root, revision, relative):
    result = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames',
                 '--full-index', '--unified=3', BASE_REVISION, revision, '--', relative)
    body = result.stdout
    prohibited = (b'GIT binary patch', b'Binary files ', b'old mode ', b'new mode ', b'deleted file mode ')
    require(result.returncode == 0 and 0 < len(body) <= MAX_BYTES
            and body.startswith(('diff --git a/' + relative + ' b/' + relative + '\n').encode())
            and all(marker not in body for marker in prohibited)
            and ((b'new file mode ' in body) == (relative in NEW_PATHS)),
            'RELEASE_PATCH_INVALID: ' + relative)
    return body


def release_body(root, revision, relative):
    result = git(root, 'show', revision + ':' + relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= MAX_BYTES
            and b'\x00' not in result.stdout, 'RELEASE_SOURCE_INVALID: ' + relative)
    return result.stdout


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
    """Reverse only files whose complete installed bytes are still ours."""
    review = []
    for relative, patch_path, after in reversed(applied):
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
            require(patch_check(root, patch_path, reverse=True), 'ROLLBACK_CHECK_FAILED')
            require(patch_apply(root, patch_path, reverse=True), 'ROLLBACK_PATCH_FAILED')
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
    private = root.parent / 'employee-part-time-backups'
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
                before, source_info = read_source(root / relative, absent_ok=relative in NEW_PATHS)
                originals[relative] = (before, source_info)
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                    staged.chmod(stat.S_IMODE(source_info.st_mode))
                patch = stage / ('patch-' + str(index) + '.diff')
                patch.write_bytes(release_patch(root, revision, relative))
                if relative in NEW_PATHS and before is not None:
                    require(before == release_body(root, revision, relative),
                            'NEW_SOURCE_COLLISION_NEEDS_REVIEW: ' + relative)
                    continue
                # Reverse first prevents duplicate insertion-only hunks on reruns.
                if relative not in NEW_PATHS and patch_check(stage, patch, reverse=True):
                    continue
                if relative == EMPLOYEE_JS and not patch_check(stage, patch):
                    candidate = scoped_js_candidate(before, release_body(root, revision, relative))
                    if candidate == before:
                        continue
                    # Never relax the canonical patch's checks or replace the full live file.
                    # This fresh patch retains clock changes and reverses to these exact bytes.
                    patch.write_bytes(exact_live_patch(relative, before, candidate))
                require(patch_check(stage, patch), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                require(patch_apply(stage, patch), 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, patch, staged.read_bytes()))
            lint_sources(php, stage, root, stage / '.blade-cache')
            initial_schema = schema_state(root, php)
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            if not pending and initial_schema['recorded']:
                print('EMPLOYEE_PART_TIME_OK already_current=1 files=0', flush=True)
                return None
            print('EMPLOYEE_PART_TIME_PREFLIGHT_OK files=' + str(len(pending))
                  + ' migration_required=' + str(int(not initial_schema['recorded'])), flush=True)
            if check_only:
                return None
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=stamp + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION, 'status': 'prepared',
                       'migration': MIGRATION, 'schema_before': initial_schema,
                       'migration_attempted': False, 'database_rollback_attempted': False, 'files': []}
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
            (backup / 'release.diff').write_bytes(b''.join(p.read_bytes() for _, p, _ in pending))
            receipt_path = backup / 'receipt.json'
            save_receipt(receipt_path, receipt)
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]),
                        'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
            require(schema_state(root, php) == initial_schema, 'SCHEMA_CHANGED_DURING_PREFLIGHT')
            applied = []
            try:
                # Add the optional employee columns before enabling the new editor and attendance mode.
                for paths in (NEW_PATHS, MODIFIED_PATHS):
                    if paths == MODIFIED_PATHS and not initial_schema['recorded']:
                        receipt['migration_attempted'] = True
                        save_receipt(receipt_path, receipt)
                        result = run([php, 'artisan', 'migrate', '--force', '--path=' + MIGRATION], cwd=root)
                        require(result.returncode == 0, 'SCOPED_EMPLOYEE_PART_TIME_MIGRATION_FAILED')
                        ready = schema_state(root, php)
                        require(all(ready.values()), 'EMPLOYEE_PART_TIME_MIGRATION_INCOMPLETE')
                    for item in pending:
                        relative, patch, after = item
                        if relative not in paths:
                            continue
                        require(unchanged(root / relative, originals[relative]),
                                'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
                        applied.append(item)
                        require(patch_apply(root, patch), 'LIVE_PATCH_FAILED: ' + relative)
                        restore_metadata(root / relative, after, originals[relative][1])
                        require(read_source(root / relative)[0] == after,
                                'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_sources(php, root, root, stage / '.blade-cache')
                final_schema = schema_state(root, php)
                require(all(final_schema.values()), 'EMPLOYEE_PART_TIME_POSTCHECK_FAILED')
                for relative, _, after in pending:
                    require(read_source(root / relative)[0] == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
            except BaseException:
                review = rollback(root, applied, originals)
                receipt['status'] = 'rollback_review_required' if review else 'code_restored'
                receipt['review'] = review
                receipt['database_changes_may_remain'] = receipt['migration_attempted']
                save_receipt(receipt_path, receipt)
                print(('EMPLOYEE_PART_TIME_ROLLBACK_REVIEW_REQUIRED' if review else
                       'EMPLOYEE_PART_TIME_CODE_RESTORED') + ' database_changes_may_remain='
                      + str(int(receipt['migration_attempted'])) + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt['schema_after'] = final_schema
            save_receipt(receipt_path, receipt)
            print('EMPLOYEE_PART_TIME_OK files=' + str(len(pending))
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
