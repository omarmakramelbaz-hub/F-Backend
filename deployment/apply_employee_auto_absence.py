#!/usr/bin/env python3
"""Install only automatic employee absence and its dedicated current-user minute cron."""
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

BASE_REVISION = '9bf6ddc80d3bbb1a23042f29f9cea338ce7e4816'
SOURCE_PATHS = (
    'app/Services/Dashboard/EmployeeAutomaticAbsence.php',
    'app/Console/Commands/RecordEmployeeAutomaticAbsence.php',
)
MAX_BYTES = 4 * 1024 * 1024
CRON_MARKER = '# fasakhansta-employee-auto-absence-v1'
CRON_LINE = ('* * * * * /opt/cpanel/ea-php82/root/usr/bin/php '
             '/home/fasakha/public_html/artisan employees:auto-absence '
             '--no-interaction > /dev/null 2>&1 ' + CRON_MARKER).encode()
ACTIVATION_NAME = 'activation.json'

def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run(command, cwd=None):
    return subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=180,
                          env=dict(os.environ, LC_ALL='C') if command[0] == 'crontab' else None)


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




def cron_state():
    result = run(['crontab', '-l'])
    if result.returncode == 1 and result.stderr.strip().startswith(b'no crontab for '):
        return {'present': False, 'body': b''}
    require(result.returncode == 0 and len(result.stdout) <= MAX_BYTES
            and b'\x00' not in result.stdout, 'EMPLOYEE_AUTO_ABSENCE_CRON_UNREADABLE')
    return {'present': True, 'body': result.stdout}


def cron_candidate(state):
    lines = state['body'].splitlines()
    related = [line for line in lines if CRON_MARKER.encode() in line
               or (line.lstrip() and not line.lstrip().startswith(b'#')
                   and b'employees:auto-absence' in line)]
    require(not related or related == [CRON_LINE], 'EMPLOYEE_AUTO_ABSENCE_CRON_NEEDS_REVIEW')
    if related:
        return state
    body = state['body']
    return {'present': True, 'body': body + (b'\n' if body and not body.endswith(b'\n') else b'')
            + CRON_LINE + b'\n'}


def write_cron(state):
    if state['present']:
        result = subprocess.run(['crontab', '-'], input=state['body'], stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=180)
    else:
        result = run(['crontab', '-r'])
    require(result.returncode == 0, 'EMPLOYEE_AUTO_ABSENCE_CRON_WRITE_FAILED')


def publish_new(path, body):
    """Publish an entire new source atomically; an existing destination is never replaced."""
    safe(path)
    require(path.parent.is_dir(), 'SOURCE_PARENT_REQUIRED: ' + str(path))
    descriptor, temporary = tempfile.mkstemp(prefix='.employee-auto-absence-', suffix='.tmp', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), 0o644)
            stream.write(body); stream.flush(); os.fsync(stream.fileno())
        os.link(temporary, path, follow_symlinks=False)
    finally:
        os.unlink(temporary)


def remove_ours(path, body):
    observed, info = read_source(path, absent_ok=True)
    if observed is None:
        return
    require(observed == body and stat.S_IMODE(info.st_mode) == 0o644
            and info.st_gid == os.getegid(), 'UNOWNED_CHANGE_DURING_ROLLBACK: ' + str(path))
    current = path.lstat()
    require(current.st_ino == info.st_ino and current.st_dev == info.st_dev,
            'UNOWNED_CHANGE_DURING_ROLLBACK: ' + str(path))
    path.unlink()


def activation_body(path, today):
    body, info = read_source(path, absent_ok=True)
    if body is None:
        return json.dumps({'starts_on': today}, separators=(',', ':')).encode() + b'\n', None
    try:
        data = json.loads(body)
        day = data.get('starts_on') if isinstance(data, dict) and set(data) == {'starts_on'} else None
        require(isinstance(day, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}', day)
                and datetime.datetime.strptime(day, '%Y-%m-%d').date().isoformat() == day and day <= today,
                'EMPLOYEE_AUTO_ABSENCE_ACTIVATION_NEEDS_REVIEW')
    except (ValueError, TypeError):
        raise RuntimeError('EMPLOYEE_AUTO_ABSENCE_ACTIVATION_NEEDS_REVIEW')
    return body, info


def lint_sources(php, directory):
    for relative in SOURCE_PATHS:
        require(run([php, '-l', str(directory / relative)]).returncode == 0,
                'PHP_PARSE_INVALID: ' + relative)


SCHEMA_PHP = r"""
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $schema=\Illuminate\Support\Facades\Schema::class;$db=\Illuminate\Support\Facades\DB::class;
 $required=[
 'users'=>['id','account_type','owner_resturant_id'],
 'branch_operation_commands'=>['branch','actor_id','request_key','request_hash','kind','result','created_at','updated_at'],
 'branch_employees'=>['id','branch','active','hired_on','left_on','shift','revision','schedule_type','attendance_start','attendance_end','attendance_shift'],
 'branch_employee_days'=>['id','branch','employee_id','day','status','check_in','check_out','checked_in_at','checked_out_at','attendance_rule_snapshot','notes','revision','actor_id','created_at','updated_at'],
 'branch_employee_entries'=>['id','branch','employee_id','day','kind','source_key','amount_cents','reason','notes','voided_at','voided_by','void_reason','revision','actor_id','created_at','updated_at'],
 'branch_attendance_rules'=>['branch','shift','starts_at','ends_at','late_half_hour_cents','early_half_hour_cents','absence_cents','revision'],
 'branch_payrolls'=>['employee_id','month'],
 ];
 if($db::connection()->getDriverName()!=='mysql')throw new \RuntimeException();
 foreach($required as$table=>$columns){
  if(!$schema::hasTable($table)||!$schema::hasColumns($table,$columns))throw new \RuntimeException();
  $meta=$db::selectOne('SELECT ENGINE AS table_engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$db::connection()->getTablePrefix().$table]);
  if(!$meta||strtoupper((string)$meta->table_engine)!=='INNODB')throw new \RuntimeException();
 }
 $owner=\App\Models\User::withoutGlobalScopes()->find(1);
 if(!$owner||$owner->account_type!=='admin'||!empty($owner->owner_resturant_id))throw new \RuntimeException();
 $access=$app->make(\App\Services\Dashboard\TakeawayAccess::class);
 $owner=$access->actor($owner);
 if(empty($access->permissions($owner)['can_checkout']))throw new \RuntimeException();
 foreach([
 'branch_employee_days'=>['employee_id','day'],
 'branch_employee_entries'=>['employee_id','day','source_key'],
 'branch_operation_commands'=>['branch','actor_id','request_key'],
 'branch_attendance_rules'=>['branch','shift'],
 'branch_payrolls'=>['employee_id','month'],
 ]as$table=>$wanted){
  $keys=[];foreach($db::select('SHOW INDEX FROM `'.$db::connection()->getTablePrefix().$table.'`')as$key){
   if((int)$key->Non_unique===0&&$key->Sub_part===null)$keys[$key->Key_name][(int)$key->Seq_in_index]=$key->Column_name;
  }
  $found=false;foreach($keys as$parts){ksort($parts);if(array_values($parts)===$wanted)$found=true;}
  if(!$found)throw new \RuntimeException();
 }
 $today=\App\Services\Dashboard\OperatingDay::date();
 while(ob_get_level()>0)ob_end_clean();echo json_encode(['today'=>$today],JSON_THROW_ON_ERROR);
}catch(\Throwable $ignore){while(ob_get_level()>0)ob_end_clean();exit(1);}
"""


def schema_state(root, php):
    result = run([php, '-r', SCHEMA_PHP], cwd=root)
    try:
        state = json.loads(result.stdout) if result.returncode == 0 else None
        valid = (isinstance(state, dict) and set(state) == {'today'}
                 and isinstance(state['today'], str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}', state['today'])
                 and datetime.datetime.strptime(state['today'], '%Y-%m-%d').date().isoformat() == state['today'])
    except (ValueError, TypeError):
        valid = False
    require(valid, 'EMPLOYEE_AUTO_ABSENCE_SCHEMA_NEEDS_REVIEW')
    return state


def verify_dry_run(result):
    prefix = b'Employee automatic absence: '
    try:
        output = result.stdout.strip()
        data = json.loads(output[len(prefix):]) if output.startswith(prefix) else None
        valid = (result.returncode == 0 and isinstance(data, dict)
                 and set(data) == {'ready','considered','recorded','skipped','errors'}
                 and data['ready'] is True and data['errors'] == 0
                 and all(type(data[key]) is int and data[key] >= 0
                         for key in ('considered','recorded','skipped','errors'))
                 and data['recorded'] + data['skipped'] == data['considered'])
    except (ValueError, TypeError):valid = False
    require(valid, 'EMPLOYEE_AUTO_ABSENCE_DRY_RUN_FAILED')


def validate_release(root, revision):
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    safe(root); require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    for commit in (BASE_REVISION, revision):
        result = git(root, 'rev-parse', '--verify', commit + '^{commit}')
        require(result.returncode == 0 and result.stdout.decode().strip() == commit, 'PINNED_COMMIT_UNAVAILABLE')
    require(git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0, 'RELEASE_BASE_MISMATCH')
    changed = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames', '--name-status', BASE_REVISION, revision, '--', *SOURCE_PATHS)
    require(changed.returncode == 0 and set(changed.stdout.decode().splitlines()) == {'A\t' + path for path in SOURCE_PATHS}, 'RELEASE_SOURCE_MANIFEST_MISMATCH')
    tree = git(root, 'ls-tree', '-z', revision, '--', *SOURCE_PATHS)
    entries = tree.stdout.rstrip(b'\x00').split(b'\x00')
    require(tree.returncode == 0 and len(entries) == len(SOURCE_PATHS), 'RELEASE_SOURCE_TREE_MISMATCH')
    for entry in entries:
        header, relative = entry.split(b'\t', 1)
        require(header.split(b' ', 2)[:2] == [b'100644', b'blob'] and relative.decode() in SOURCE_PATHS,
                'RELEASE_SOURCE_NOT_REGULAR')


def release_body(root, revision, relative):
    result = git(root, 'show', revision + ':' + relative)
    require(result.returncode == 0 and 0 < len(result.stdout) <= MAX_BYTES and b'\x00' not in result.stdout, 'RELEASE_SOURCE_INVALID: ' + relative)
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


def install(root, revision, php, check_only=False):
    os.umask(0o077); validate_release(root, revision)
    private = root.parent / 'employee-auto-absence-backups'
    safe(private); private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700, 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'; safe(lock_path)
    descriptor = os.open(lock_path, os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW|os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid()
                and stat.S_IMODE(info.st_mode) == 0o600, 'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX|fcntl.LOCK_NB)
        originals, wanted, pending = {}, {}, []
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            for relative in SOURCE_PATHS:
                originals[relative] = read_source(root / relative, absent_ok=True)
                wanted[relative] = release_body(root, revision, relative)
                before = originals[relative][0]
                require(before is None or before == wanted[relative], 'NEW_SOURCE_COLLISION_NEEDS_REVIEW: ' + relative)
                if before is None: pending.append(relative)
                target = stage / relative; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(wanted[relative])
            lint_sources(php, stage)
            state = schema_state(root, php)
            activation = private / ACTIVATION_NAME
            marker, marker_info = activation_body(activation, state['today'])
            initial_marker = (None if marker_info is None else marker, marker_info)
            before_cron = cron_state(); after_cron = cron_candidate(before_cron)
            for relative in SOURCE_PATHS:
                require(unchanged(root / relative, originals[relative]), 'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            require(unchanged(activation, initial_marker), 'ACTIVATION_CHANGED_DURING_PREFLIGHT')
            require(cron_state() == before_cron, 'CRON_CHANGED_DURING_PREFLIGHT')
            print('EMPLOYEE_AUTO_ABSENCE_PREFLIGHT_OK files=' + str(len(pending))
                  + ' activation_required=' + str(int(marker_info is None))
                  + ' cron_required=' + str(int(after_cron != before_cron)), flush=True)
            if check_only:return None
            if not pending and marker_info is not None and before_cron == after_cron:
                print('EMPLOYEE_AUTO_ABSENCE_OK already_current=1 files=0', flush=True);return None
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=stamp+revision[:10]+'-', dir=private))
            (backup / 'crontab.before').write_bytes(before_cron['body'])
            receipt = {'revision':revision,'base_revision':BASE_REVISION,'status':'prepared',
                       'files':pending,'cron_before_present':before_cron['present'],
                       'cron_before_sha256':digest(before_cron['body']),'cron_after_sha256':digest(after_cron['body']),
                       'activation_before_sha256':digest(initial_marker[0]),'activation_after_sha256':digest(marker),
                       'cron_attempted':False,'activation_attempted':False,'database_changes_attempted':False}
            receipt_path = backup / 'receipt.json';save_receipt(receipt_path, receipt)
            published = [];cron_attempted=False;marker_attempted=False;installed=dict(originals)
            try:
                for relative in SOURCE_PATHS:
                    require(unchanged(root / relative, originals[relative]), 'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
                require(unchanged(activation, initial_marker), 'ACTIVATION_CHANGED_BEFORE_INSTALL')
                require(cron_state() == before_cron, 'CRON_CHANGED_BEFORE_INSTALL')
                for relative in pending:
                    require(unchanged(root / relative, originals[relative]), 'LIVE_FILE_CHANGED_BEFORE_INSTALL: ' + relative)
                    publish_new(root / relative, wanted[relative]);published.append(relative)
                    installed[relative]=read_source(root / relative)
                    require(installed[relative][0] == wanted[relative], 'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                if marker_info is None:
                    receipt['activation_attempted']=True;save_receipt(receipt_path, receipt)
                    publish_new(activation, marker);marker_attempted=True
                installed_marker=read_source(activation)
                require(installed_marker[0] == marker, 'ACTIVATION_CHANGED_DURING_INSTALL')
                lint_sources(php, root)
                result = run([php, 'artisan', 'employees:auto-absence', '--dry-run', '--no-interaction', '--no-ansi'], cwd=root)
                verify_dry_run(result)
                require(unchanged(activation,installed_marker), 'ACTIVATION_CHANGED_DURING_INSTALL')
                for relative in SOURCE_PATHS:
                    require(unchanged(root / relative,installed[relative]), 'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                require(cron_state() == before_cron, 'CRON_CHANGED_BEFORE_WRITE')
                if before_cron != after_cron:
                    cron_attempted=receipt['cron_attempted']=True;save_receipt(receipt_path, receipt)
                    write_cron(after_cron)
                require(cron_state() == after_cron, 'CRON_POSTCHECK_FAILED')
                require(unchanged(activation,installed_marker), 'ACTIVATION_CHANGED_DURING_INSTALL')
                for relative in SOURCE_PATHS:
                    require(unchanged(root/relative,installed[relative]), 'LIVE_FILE_CHANGED_DURING_INSTALL: '+relative)
            except BaseException:
                review=[]
                if cron_attempted:
                    try:
                        current=cron_state()
                        if current != before_cron:
                            require(current == after_cron, 'CONCURRENT_CRON_CHANGE')
                            write_cron(before_cron)
                        require(cron_state() == before_cron, 'CRON_RESTORE_FAILED')
                    except (RuntimeError,OSError,subprocess.SubprocessError):review.append('crontab')
                if marker_attempted:
                    try:remove_ours(activation,marker)
                    except (RuntimeError,OSError):review.append(ACTIVATION_NAME)
                for relative in reversed(published):
                    try:remove_ours(root/relative,wanted[relative])
                    except (RuntimeError,OSError):review.append(relative)
                receipt['status']='rollback_review_required' if review else 'restored';receipt['review']=review
                save_receipt(receipt_path,receipt)
                print(('EMPLOYEE_AUTO_ABSENCE_ROLLBACK_REVIEW_REQUIRED' if review else 'EMPLOYEE_AUTO_ABSENCE_RESTORED')+'; BACKUP='+str(backup),flush=True)
                raise
            receipt['status']='installed';save_receipt(receipt_path,receipt)
            print('EMPLOYEE_AUTO_ABSENCE_OK files='+str(len(pending))+' cron=ready; BACKUP='+str(backup),flush=True)
            return backup


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',default='/home/fasakha/public_html');parser.add_argument('--revision',required=True)
    parser.add_argument('--php',default='/opt/cpanel/ea-php82/root/usr/bin/php');parser.add_argument('--check',action='store_true')
    args=parser.parse_args()
    require(os.geteuid()==pwd.getpwnam('fasakha').pw_uid,'RUN_AS_FASAKHA_REQUIRED')
    def interrupted(signum,frame):raise RuntimeError('INSTALL_INTERRUPTED')
    signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
    install(Path(args.root),args.revision,args.php,args.check)


if __name__=='__main__':
    try:main()
    except (RuntimeError,OSError,KeyError,subprocess.SubprocessError,ValueError) as error:
        print('STOPPED: '+str(error),flush=True);raise SystemExit(1)
