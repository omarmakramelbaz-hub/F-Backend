#!/usr/bin/env python3
"""Exclude the WhatsApp AJAX reply form from the shared submit-button spinner."""
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import signal
import stat
import subprocess
import sys
import tempfile
import time

INSTALLER_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
APP_ROOT = pathlib.Path('/home/fasakha/public_html')
FOOTER_PATH = 'resources/views/admin/layouts/footer.blade.php'
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
OLD_SELECTOR = b'$( "form" ).submit(function() {'
NEW_SELECTOR = b'$( "form" ).not("[data-wa-reply-form]").submit(function() {'
HANDLER = b'''    $( "form" ).submit(function() {
        $(this).find("button[type='submit']").html('<i class="fa fa-spinner fa-spin"></i>@lang('main.under click')');
    });'''


def load_installer():
    path = pathlib.Path(__file__).absolute().with_name('install_whatsapp_inbox.py')
    for parent in reversed(path.parents):
        s = parent.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_PINNED_INSTALLER_PATH')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        s = os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_nlink != 1
                or s.st_uid not in (0, os.geteuid()) or s.st_mode & 0o022
                or s.st_size > 1048576):
            raise RuntimeError('UNSAFE_PINNED_INSTALLER_FILE')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            content = stream.read(1048577)
        after = os.fstat(fd)
        if (s.st_size, s.st_mtime_ns, s.st_ctime_ns) != (
                after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('PINNED_INSTALLER_CHANGED')
    finally:
        os.close(fd)
    digest = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
    if digest != INSTALLER_BLOB:
        raise RuntimeError('PINNED_INSTALLER_HASH_MISMATCH')
    spec = importlib.util.spec_from_file_location('verified_wa_spinner_base', path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


base = load_installer()
SafeError = base.SafeError


def proposal(content):
    if content.count(OLD_SELECTOR) == 0 and content.count(NEW_SELECTOR) == 1:
        for newline in (b'\n', b'\r\n'):
            fixed = HANDLER.replace(b'\n', newline).replace(OLD_SELECTOR, NEW_SELECTOR)
            if content.count(fixed) == 1:
                return content
        raise SafeError('WHATSAPP_SPINNER_FIXED_HANDLER_NEEDS_REVIEW')
    if content.count(OLD_SELECTOR) != 1 or NEW_SELECTOR in content:
        raise SafeError('WHATSAPP_SPINNER_HANDLER_AMBIGUOUS')
    for newline in (b'\n', b'\r\n'):
        original = HANDLER.replace(b'\n', newline)
        if content.count(original) == 1:
            return content.replace(original, original.replace(OLD_SELECTOR, NEW_SELECTOR), 1)
    raise SafeError('WHATSAPP_SPINNER_HANDLER_NEEDS_REVIEW')


COMPILE_PHP = r'''
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $path = app('view')->getFinder()->find('admin.layouts.footer');
    if ($path !== getcwd() . '/resources/views/admin/layouts/footer.blade.php') exit(1);
    $compiler = app('blade.compiler');
    $bytes = file_get_contents($argv[1]);
    if (!is_string($bytes) || strlen($bytes) > 8388608) exit(1);
    $compiled = $compiler->compileString($bytes);
    if (!is_string($compiled) || strlen($compiled) > 8388608) exit(1);
    $stream = fopen($argv[2], 'xb');
    if ($stream === false) exit(1);
    $written = fwrite($stream, $compiled); $flushed = fflush($stream); fclose($stream);
    if ($written !== strlen($compiled) || !$flushed || !chmod($argv[2], 0600)) exit(1);
    $cache = $compiler->getCompiledPath($path);
    $stamp = is_file($cache) ? filemtime($cache) : 0;
    if (!is_int($stamp) || $stamp < 0) exit(1);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(['compiled_mtime' => $stamp], JSON_THROW_ON_ERROR);
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''

SMOKE_PHP = r'''
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $path = app('view')->getFinder()->find('admin.layouts.footer');
    if ($path !== getcwd() . '/resources/views/admin/layouts/footer.blade.php') exit(1);
    $bytes = file_get_contents($path);
    $marker = '$( "form" ).not("[data-wa-reply-form]").submit(function() {';
    if (!is_string($bytes) || substr_count($bytes, $marker) !== 1
        || strpos($bytes, '$( "form" ).submit(function() {') !== false) exit(1);
    $compiler = app('blade.compiler');
    if (!$compiler->isExpired($path)) {
        // A request may already have compiled this one footer after publication.
        $cached = file_get_contents($compiler->getCompiledPath($path));
        if (!is_string($cached) || substr_count($cached, $marker) !== 1) exit(1);
    }
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''


def command(root, arguments, capture=False, timeout=30):
    try:
        result = subprocess.run(arguments, cwd=root,
                                stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=timeout, check=False)
        if capture and len(result.stdout) > 4096:
            return 1, b''
        return result.returncode, result.stdout if capture else b''
    except (OSError, subprocess.TimeoutExpired):
        return 1, b''


def private_write(path, content):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(content); stream.flush(); os.fsync(stream.fileno())


def stamp_source(path, expected, uid, root, compiled_mtime):
    """Expire only this view lazily, without clearing other compiled templates."""
    base.assert_snapshot(path, expected, uid, root)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        s = os.fstat(fd)
        if (s.st_dev, s.st_ino, s.st_uid, s.st_gid, stat.S_IMODE(s.st_mode)) != (
                expected.device, expected.inode, expected.uid, expected.gid, expected.mode):
            raise SafeError('WHATSAPP_SPINNER_SOURCE_CHANGED_CONCURRENTLY')
        newest = max(time.time_ns(), (compiled_mtime + 1) * 1000000000, s.st_mtime_ns)
        os.utime(fd, ns=(s.st_atime_ns, newest))
    finally:
        os.close(fd)
    base.assert_snapshot(path, expected, uid, root)


def apply(root, uid, runner=command):
    base.validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'
    base.private_parent(parent, uid)
    fd = os.open(parent / 'inbox-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        s = os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_uid != uid
                or s.st_nlink != 1 or stat.S_IMODE(s.st_mode) != 0o600):
            raise SafeError('WHATSAPP_SPINNER_LOCK_NEEDS_REVIEW')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_INBOX_INSTALL_IS_RUNNING') from None
        before = base.snapshot(root / FOOTER_PATH, uid, root)
        if before.mode & 0o022 or (root / FOOTER_PATH).lstat().st_nlink != 1:
            raise SafeError('WHATSAPP_SPINNER_SOURCE_NEEDS_REVIEW')
        candidate = proposal(before.content)
        if candidate == before.content:
            code, _ = runner(root, [PHP, '-r', SMOKE_PHP])
            if code != 0:
                raise SafeError('WHATSAPP_SPINNER_FIXED_TEMPLATE_RECHECK_REQUIRED')
            return 'WHATSAPP_REPLY_SPINNER_ALREADY_FIXED', None
        backup = pathlib.Path(tempfile.mkdtemp(prefix='reply-spinner-', dir=parent))
        backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('BACKUP_AND_PROJECT_REQUIRE_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        private_write(backup / 'footer.before.blade.php', before.content)
        private_write(backup / 'footer.candidate.blade.php', candidate)
        private_write(backup / 'receipt.json', (json.dumps({
            'file': FOOTER_PATH, 'before_blob': base.blob_hash(before.content),
            'after_blob': base.blob_hash(candidate), 'uid': before.uid,
            'gid': before.gid, 'mode': before.mode}, sort_keys=True) + '\n').encode())
        code, output = runner(root, [PHP, '-r', COMPILE_PHP,
                                    str(backup / 'footer.candidate.blade.php'),
                                    str(backup / 'footer.compiled.php')], capture=True)
        try:
            report = json.loads(output)
            compiled_mtime = report['compiled_mtime']
            if code != 0 or type(compiled_mtime) is not int or compiled_mtime < 0:
                raise ValueError()
        except (ValueError, TypeError, KeyError):
            raise SafeError('WHATSAPP_SPINNER_TEMPLATE_COMPILE_FAILED') from None
        code, _ = runner(root, [PHP, '-l', str(backup / 'footer.compiled.php')])
        if code != 0:
            raise SafeError('WHATSAPP_SPINNER_TEMPLATE_LINT_FAILED')
        journal, created = {}, []
        try:
            publication = base.replace_file(root / FOOTER_PATH, candidate, before, uid, root, created,
                                            journal=journal, relative=FOOTER_PATH,
                                            private_directory=publications)
            stamp_source(root / FOOTER_PATH, publication.after, uid, root, compiled_mtime)
            code, _ = runner(root, [PHP, '-r', SMOKE_PHP])
            if code != 0:
                raise SafeError('WHATSAPP_SPINNER_TEMPLATE_SMOKE_FAILED')
            base.assert_snapshot(root / FOOTER_PATH, publication.after, uid, root)
        except BaseException:
            restored = base.rollback(root, uid, {FOOTER_PATH: before}, journal, created)
            if restored:
                try:
                    stamp_source(root / FOOTER_PATH, before, uid, root,
                                 max(compiled_mtime, int(time.time())))
                except BaseException:
                    raise SafeError('WHATSAPP_SPINNER_SOURCE_RESTORED_TEMPLATE_RECHECK_REQUIRED; BACKUP=' + str(backup)) from None
            status = ('WHATSAPP_SPINNER_SOURCE_RESTORED' if restored else
                      'WHATSAPP_SPINNER_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED')
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
        return 'WHATSAPP_REPLY_SPINNER_FIXED', backup
    finally:
        os.close(fd)


def main():
    try:
        if (sys.argv[1:] != ['--root', str(APP_ROOT)]
                or os.geteuid() != pwd.getpwnam('fasakha').pw_uid):
            raise SafeError('RUN_AS_FASAKHA_FOR_CONFIRMED_PROJECT_ROOT')
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('FIX_INTERRUPTED')))
        status, backup = apply(APP_ROOT, os.geteuid())
        print(status)
        if backup is not None:
            print('BACKUP=' + str(backup))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('WHATSAPP_SPINNER_FIX_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
