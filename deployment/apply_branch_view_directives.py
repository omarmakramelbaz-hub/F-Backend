#!/usr/bin/env python3
"""Separate adjacent Blade directives without replacing any other live source bytes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile

VIEWS = ('resources/views/admin/branch_stock/index.blade.php',
         'resources/views/admin/branch_shifts/index.blade.php',
         'resources/views/admin/expenses/index.blade.php')
OLD, NEW = b'@endif@foreach(', b'@endif\n@foreach('
MAX_BYTES = 4 * 1024 * 1024
META = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_size',
        'st_mtime_ns', 'st_ctime_ns')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute() and all(not p.is_symlink() for p in (path, *path.parents)),
            'ABSOLUTE_UNSYMLINKED_PATH_REQUIRED: ' + str(path))


def read_owned(path, optional=False):
    safe(path)
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except FileNotFoundError:
        if optional:
            return None
        raise
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1
                and info.st_uid == os.geteuid(), 'OWNED_REGULAR_FILE_REQUIRED: ' + str(path))
        body = stream.read(MAX_BYTES + 1)
    require(len(body) <= MAX_BYTES and b'\x00' not in body, 'FILE_INVALID: ' + str(path))
    return body, info


def unchanged(path, original):
    current = read_owned(path)
    return current[0] == original[0] and all(getattr(current[1], key) == getattr(original[1], key)
                                           for key in META)


def atomic_write(path, body, info, expected):
    safe(path)
    descriptor, temporary = tempfile.mkstemp(prefix='.blade-separator-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(body)
            stream.flush()
            os.fchown(stream.fileno(), -1, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            os.fsync(stream.fileno())
        require(unchanged(path, expected), 'SOURCE_CHANGED_DURING_INSTALL: ' + str(path))
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


BLADE_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 $root=$argv[1];$stage=$argv[3];
 // Laravel may refresh discovery manifests while booting; keep those writes private.
 foreach(['APP_PACKAGES_CACHE'=>'packages.php','APP_SERVICES_CACHE'=>'services.php'] as $key=>$name){
  $source=$root.'/bootstrap/cache/'.$name;$target=$stage.'/'.$name;
  if(is_file($source)&&!copy($source,$target))throw new \RuntimeException();
  putenv($key.'='.$target);$_ENV[$key]=$target;$_SERVER[$key]=$target;
 }
 require $root.'/vendor/autoload.php';
 $app=require $root.'/bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $compiler=$app->make('blade.compiler');$paths=[];
 foreach(json_decode($argv[2],true,512,JSON_THROW_ON_ERROR) as $entry){
  $source=realpath($root.'/'.$entry['relative']);
  if($source!==$root.'/'.$entry['relative'])throw new \RuntimeException();
  $body=file_get_contents($entry['candidate']);
  if($body===false)throw new \RuntimeException();
  $compiled=$compiler->compileString($body);
  if(file_put_contents($entry['compiled'],$compiled)===false)throw new \RuntimeException();
  $paths[]=$compiler->getCompiledPath($source);
 }
 while(ob_get_level()>0)ob_end_clean();echo json_encode($paths,JSON_THROW_ON_ERROR);
}catch(\Throwable $error){while(ob_get_level()>0)ob_end_clean();exit(1);}
'''


def preflight(root, php, stage, candidates):
    entries = []
    for index, (relative, after) in enumerate(candidates.items()):
        candidate, compiled = stage / (str(index) + '.blade.php'), stage / (str(index) + '.php')
        candidate.write_bytes(after)
        entries.append({'relative': relative, 'candidate': str(candidate), 'compiled': str(compiled)})
    result = subprocess.run([php, '-r', BLADE_PHP, str(root), json.dumps(entries), str(stage)],
                            cwd=root, capture_output=True, timeout=90)
    require(result.returncode == 0, 'INSTALLED_BLADE_COMPILER_PREFLIGHT_FAILED')
    try:
        values = json.loads(result.stdout)
    except (ValueError, TypeError):
        values = None
    require(isinstance(values, list) and len(values) == len(entries), 'COMPILED_VIEW_PATHS_INVALID')
    paths = {}
    for entry, value in zip(entries, values):
        require(isinstance(value, str), 'COMPILED_VIEW_PATH_INVALID')
        path = Path(value)
        safe(path)
        require(path.parent == root / 'storage/framework/views'
                and re.fullmatch(r'[0-9a-f]+\.php', path.name), 'COMPILED_VIEW_LOCATION_INVALID')
        read_owned(path, optional=True)
        check = subprocess.run([php, '-l', entry['compiled']], capture_output=True, timeout=30)
        require(check.returncode == 0, 'COMPILED_BLADE_PHP_INVALID: ' + entry['relative'])
        paths[entry['relative']] = path
    require(len(set(paths.values())) == len(paths), 'DUPLICATE_COMPILED_VIEW_PATH')
    return paths


def invalidate(paths):
    for path in paths:
        cached = read_owned(path, optional=True)
        if cached is not None:
            require(unchanged(path, cached), 'COMPILED_CACHE_CHANGED_DURING_INVALIDATION')
            path.unlink()


def receipt(path, status, revision, originals, pending):
    data = {'status': status, 'revision': revision, 'files': [
        {'path': relative, 'before_sha256': hashlib.sha256(originals[relative][0]).hexdigest(),
         'after_sha256': hashlib.sha256(after).hexdigest()}
        for relative, after in pending.items()]}
    path.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8')


def install(root, revision, php, check_only):
    os.umask(0o077)
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    safe(root)
    require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    private = root.parent / 'branch-view-directives-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == os.geteuid()
            and stat.S_IMODE(info.st_mode) == 0o700, 'PRIVATE_BACKUP_DIRECTORY_REQUIRED')
    lock_path = private / 'install.lock'
    safe(lock_path)
    descriptor = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_uid == os.geteuid()
                and stat.S_IMODE(info.st_mode) == 0o600, 'PRIVATE_LOCK_REQUIRED')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        originals, candidates, pending = {}, {}, {}
        for relative in VIEWS:
            original = read_owned(root / relative, optional=relative == VIEWS[1])
            if original is None:
                continue
            before, _ = original
            after = before.replace(OLD, NEW) if relative in VIEWS[:2] else before
            originals[relative], candidates[relative] = original, after
            if after != before:
                pending[relative] = after
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as directory:
            paths = preflight(root, php, Path(directory), candidates)
        for relative, original in originals.items():
            require(unchanged(root / relative, original), 'SOURCE_CHANGED_DURING_PREFLIGHT: ' + relative)
        print('BRANCH_VIEW_DIRECTIVES_PREFLIGHT_OK views=' + str(len(candidates))
              + ' files=' + str(len(pending)), flush=True)
        if check_only:
            return
        if not pending:
            print('BRANCH_VIEW_DIRECTIVES_OK already_current=1 files=0', flush=True)
            return
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=stamp + revision[:10] + '-', dir=private))
        for index, relative in enumerate(pending):
            (backup / (str(index) + '.original.blade.php')).write_bytes(originals[relative][0])
        receipt_path = backup / 'receipt.json'
        receipt(receipt_path, 'prepared', revision, originals, pending)
        installed = []
        try:
            for relative, after in pending.items():
                atomic_write(root / relative, after, originals[relative][1], originals[relative])
                installed.append(relative)
            for relative, after in candidates.items():
                require(read_owned(root / relative)[0] == after, 'INSTALLED_SOURCE_VERIFICATION_FAILED')
            invalidate([paths[relative] for relative in pending])
            receipt(receipt_path, 'applied', revision, originals, pending)
        except BaseException:
            review = []
            for relative in reversed(installed):
                try:
                    observed = read_owned(root / relative)
                    require(observed[0] == pending[relative], 'UNOWNED_CHANGE_DURING_ROLLBACK')
                    atomic_write(root / relative, originals[relative][0], originals[relative][1], observed)
                except (OSError, RuntimeError):
                    review.append(relative)
            try:
                invalidate([paths[relative] for relative in installed])
            except (OSError, RuntimeError):
                review.append('compiled-view-cache')
            receipt(receipt_path, 'rollback_needs_review' if review else 'rolled_back', revision, originals, pending)
            if review:
                raise RuntimeError('ROLLBACK_NEEDS_REVIEW: ' + ','.join(review) + '; BACKUP=' + str(backup))
            raise
        print('BRANCH_VIEW_DIRECTIVES_OK files=' + str(len(pending)) + '; BACKUP=' + str(backup), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    try:
        install(Path(args.root), args.revision, args.php, args.check)
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        raise SystemExit('STOPPED: ' + str(error))
