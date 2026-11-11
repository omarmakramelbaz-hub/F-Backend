#!/usr/bin/env python3
"""Install the reviewed storefront UI only; never reset Git or change application data."""
import argparse
from contextlib import ExitStack
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import socket
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
SITE_USER = 'fasakha'
HOSTNAME = 'srv1836524'
RELEASE = 'storefront-motion-20261111'
REPOSITORY = 'https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend'
PREFIX = 'deployment/storefront-motion-20261111'
BACKUPS = Path('/root/fasakhansta-storefront-motion-backups')
LOCK = Path('/var/lock/fasakhansta-storefront-motion.lock')
HOME_VIEW = 'resources/views/site/home.blade.php'
VIDEO_PATH = 'public/storefront/hero-motion.mp4'
# Exact reviewed UI allowlist, including a baseline-guarded directory bridge.
# Checkout services, routes, database and dashboard files are excluded.
ALLOWED = frozenset({
    HOME_VIEW,
    'resources/views/site/motion/header.blade.php',
    'resources/views/site/motion/hero.blade.php',
    'resources/views/site/motion/directory.blade.php',
    'resources/views/site/motion/products.blade.php',
    'resources/views/site/motion/visit.blade.php',
    'resources/views/site/motion/app.blade.php',
    'resources/views/site/motion/contact.blade.php',
    'resources/views/site/motion/footer.blade.php',
    'public/storefront/storefront.js',
    'public/storefront/motion.js',
    'public/storefront/motion.css',
    'public/storefront/motion-regular.woff',
    'public/storefront/motion-bold.woff',
    'public/storefront/motion-logo.webp',
    VIDEO_PATH,
    'public/storefront/hero-motion-poster.webp',
    'public/storefront/fasakhansta-qr.png',
    'public/storefront/fasakhansta-qr.svg',
})
BASELINES = {
    HOME_VIEW: 'ea480281ee2acbf39f8d1fa3a41a0dae07888bb6e671dfe34a369c51d1b50b28',
    'public/storefront/storefront.js': '1ce1f3a6997241e8074e0b31fa0539cbacd3cc05c69bcae3e0f8eeb2a4e88b45',
}
MAX_FILE = 16 * 1024 * 1024
MAX_TOTAL = 32 * 1024 * 1024
SHA256 = re.compile(r'^[a-f0-9]{64}$')
REVISION = re.compile(r'^[a-f0-9]{40}$')


def need(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def validate_manifest(payload):
    need(isinstance(payload, list) and 1 <= len(payload) <= len(ALLOWED), 'Invalid manifest array')
    seen = set()
    for entry in payload:
        need(isinstance(entry, dict) and set(entry) == {'path', 'sha256', 'baseline_sha256'}, 'Invalid manifest entry')
        path = entry['path']
        need(isinstance(path, str) and path in ALLOWED and path not in seen, 'Manifest path is not uniquely allowlisted')
        parts = PurePosixPath(path)
        need(not parts.is_absolute() and '..' not in parts.parts and str(parts) == path, 'Unsafe manifest path')
        need(isinstance(entry['sha256'], str) and SHA256.fullmatch(entry['sha256']), 'Invalid target hash')
        baseline = entry['baseline_sha256']
        need(baseline is None or isinstance(baseline, str) and SHA256.fullmatch(baseline), 'Invalid baseline hash')
        need(baseline == BASELINES.get(path), 'Manifest baseline is not the reviewed source: ' + path)
        seen.add(path)
    need(seen == ALLOWED, 'Manifest must contain the complete reviewed storefront roster')
    return payload


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        raise RuntimeError('Unexpected download redirect')


HTTP = urllib.request.build_opener(NoRedirect())


def download(url, limit):
    need(url.startswith('https://'), 'HTTPS is required')
    request = urllib.request.Request(url, headers={'User-Agent': 'Fasakhansta-Storefront-Motion-Installer', 'Accept': '*/*'})
    with HTTP.open(request, timeout=45) as response:
        need(response.status == 200 and response.geturl() == url, 'Unexpected download response')
        data = response.read(limit + 1)
    need(len(data) <= limit, 'Download exceeds reviewed size limit')
    return data


def read_release(revision, manifest_hash):
    manifest_bytes = download(f'{REPOSITORY}/{revision}/{PREFIX}/manifest.json', 128 * 1024)
    need(digest(manifest_bytes) == manifest_hash, 'Pinned manifest hash mismatch')
    entries = validate_manifest(json.loads(manifest_bytes.decode('utf-8')))
    files, total = {}, 0
    for entry in entries:
        data = download(f"{REPOSITORY}/{revision}/{entry['path']}", MAX_FILE)
        need(digest(data) == entry['sha256'], 'Downloaded file hash mismatch: ' + entry['path'])
        total += len(data)
        need(total <= MAX_TOTAL, 'Release exceeds reviewed total size limit')
        files[entry['path']] = data
    return entries, files


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def directory_identity(info):
    return identity(info)[:5]


class Target:
    """Hold directory descriptors so writes never follow an application symlink."""
    def __init__(self, relative, user, stack):
        self.relative = relative
        self.path = ROOT / relative
        self.user = user
        self.published = None
        self.chain = []
        need(ROOT.resolve() == ROOT, 'Application root must not traverse symlinks')
        root_info = ROOT.lstat()
        need(stat.S_ISDIR(root_info.st_mode) and root_info.st_uid == user.pw_uid, 'Unexpected application root owner')
        self.root_identity = directory_identity(root_info)
        fd = os.open(ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        stack.callback(os.close, fd)
        need(directory_identity(os.fstat(fd)) == self.root_identity, 'Application root changed')
        for name in PurePosixPath(relative).parts[:-1]:
            before = os.stat(name, dir_fd=fd, follow_symlinks=False)
            need(stat.S_ISDIR(before.st_mode) and before.st_uid in (0, user.pw_uid)
                 and not before.st_mode & 0o002, 'Unsafe application parent: ' + relative)
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            stack.callback(os.close, child)
            need(directory_identity(os.fstat(child)) == directory_identity(before), 'Application parent changed')
            self.chain.append((fd, name, child, directory_identity(before)))
            fd = child
        self.fd = fd
        self.name = PurePosixPath(relative).name
        self.check()

    def check(self):
        need(directory_identity(ROOT.lstat()) == self.root_identity, 'Application root changed')
        for parent, name, child, expected in self.chain:
            need(directory_identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected
                 and directory_identity(os.fstat(child)) == expected, 'Application parent changed')

    def read(self):
        self.check()
        try:
            before = os.stat(self.name, dir_fd=self.fd, follow_symlinks=False)
        except FileNotFoundError:
            return None
        need(stat.S_ISREG(before.st_mode) and before.st_uid in (0, self.user.pw_uid)
             and before.st_nlink == 1 and not before.st_mode & 0o002 and before.st_size <= MAX_FILE,
             'Unsafe application file: ' + self.relative)
        fd = os.open(self.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.fd)
        try:
            need(identity(os.fstat(fd)) == identity(before), 'Application file changed')
            data = bytearray()
            while len(data) <= before.st_size:
                part = os.read(fd, min(65536, before.st_size + 1 - len(data)))
                if not part:
                    break
                data.extend(part)
            need(len(data) == before.st_size and identity(os.fstat(fd)) == identity(before), 'Application file changed')
        finally:
            os.close(fd)
        return {'identity': identity(before), 'data': bytes(data), 'mode': stat.S_IMODE(before.st_mode),
                'uid': before.st_uid, 'gid': before.st_gid}

    def write(self, data, expected, original=None):
        self.check()
        need(self.read() == expected, 'Concurrent application change: ' + self.relative)
        name = '.storefront-motion-' + uuid.uuid4().hex
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=self.fd)
        try:
            os.fchown(fd, original['uid'] if original else self.user.pw_uid, original['gid'] if original else self.user.pw_gid)
            os.fchmod(fd, original['mode'] if original else 0o644)
            with os.fdopen(fd, 'wb', closefd=False) as output:
                output.write(data)
                output.flush()
            os.fsync(fd)
            self.check()
            need(self.read() == expected, 'Concurrent application change: ' + self.relative)
            os.replace(name, self.name, src_dir_fd=self.fd, dst_dir_fd=self.fd)
            published = os.fstat(fd)
            self.published = {'identity': identity(published), 'data': data,
                              'mode': stat.S_IMODE(published.st_mode),
                              'uid': published.st_uid, 'gid': published.st_gid}
            os.fsync(self.fd)
        finally:
            os.close(fd)
            try:
                os.unlink(name, dir_fd=self.fd)
            except FileNotFoundError:
                pass
        current = self.read()
        need(current == self.published, 'Atomic write verification failed')
        return current

    def remove(self, expected):
        self.check()
        need(self.read() == expected, 'Concurrent application change during rollback')
        os.unlink(self.name, dir_fd=self.fd)
        os.fsync(self.fd)


def create_missing_parents(paths, user, stack, created):
    """Create only allowlisted asset/view parents after the complete baseline preflight."""
    for relative in paths:
        need(relative in ALLOWED, 'Unexpected parent creation target')
        need(ROOT.resolve() == ROOT, 'Application root changed')
        root_info = ROOT.lstat()
        fd = os.open(ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        stack.callback(os.close, fd)
        need(directory_identity(os.fstat(fd)) == directory_identity(root_info), 'Application root changed')
        for name in PurePosixPath(relative).parts[:-1]:
            made = False
            try:
                before = os.stat(name, dir_fd=fd, follow_symlinks=False)
            except FileNotFoundError:
                os.mkdir(name, 0o755, dir_fd=fd)
                made = True
                before = os.stat(name, dir_fd=fd, follow_symlinks=False)
            need(stat.S_ISDIR(before.st_mode) and before.st_uid in (0, user.pw_uid)
                 and not before.st_mode & 0o002, 'Unsafe application parent')
            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            stack.callback(os.close, child)
            need(directory_identity(os.fstat(child)) == directory_identity(before), 'Application parent changed')
            if made:
                os.fchown(child, user.pw_uid, user.pw_gid)
                os.fchmod(child, 0o755)
                os.fsync(fd)
                created.append((fd, name, child, directory_identity(os.fstat(child))))
            fd = child


def run_php(code, data=b''):
    result = subprocess.run(['runuser', '-u', SITE_USER, '--', PHP, '-r', code], cwd=ROOT,
                            input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45)
    need(result.returncode == 0, 'Homepage Blade compilation failed: ' + result.stderr.decode('utf-8', 'replace')[:400])
    return result.stdout


def check_blade(data):
    code = ("require '/home/fasakha/public_html/vendor/autoload.php';"
            "$compiler=new \\Illuminate\\View\\Compilers\\BladeCompiler(new \\Illuminate\\Filesystem\\Filesystem(),"
            "'/home/fasakha/public_html/storage/framework/views');"
            "echo $compiler->compileString(stream_get_contents(STDIN));")
    compiled = run_php(code, data)
    result = subprocess.run(['runuser', '-u', SITE_USER, '--', PHP, '-l'], cwd=ROOT,
                            input=compiled, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
    need(result.returncode == 0, 'Compiled homepage PHP syntax failed')


def refresh_compiled_views(paths):
    # Compile only the allowlisted Blade views. No framework bootstrap, database access,
    # migrations, optimize:clear, or unrelated dashboard cache invalidation.
    code = ("require '/home/fasakha/public_html/vendor/autoload.php';"
            "$compiler=new \\Illuminate\\View\\Compilers\\BladeCompiler(new \\Illuminate\\Filesystem\\Filesystem(),"
            "'/home/fasakha/public_html/storage/framework/views');"
            "$paths=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);"
            "foreach($paths as $path){if(is_file($path)){$compiler->compile($path);}else{"
            "$cached=$compiler->getCompiledPath($path);if(is_link($cached)){exit(23);}"
            "if(is_file($cached)&&!unlink($cached)){exit(24);}}}")
    run_php(code, json.dumps([str(ROOT / path) for path in paths]).encode('utf-8'))


def smoke(revision):
    query = '?storefront_verify=' + revision
    homepage = download('https://fasakhaninja.com/' + query, 2 * 1024 * 1024).decode('utf-8')
    need(RELEASE in homepage and '<video' in homepage and '/storefront/' + Path(VIDEO_PATH).name in homepage,
         'Homepage release/video marker not served')
    need('id="f-stores"' in homepage or "id='f-stores'" in homepage, 'Store directory marker not served')
    video_url = 'https://fasakhaninja.com/' + VIDEO_PATH[len('public/'):] + query
    request = urllib.request.Request(video_url, headers={'Range': 'bytes=0-1023', 'User-Agent': 'Fasakhansta-Storefront-Motion-Installer'})
    with HTTP.open(request, timeout=30) as response:
        need(response.status in (200, 206) and response.geturl() == video_url, 'Hero video is not served')
        if response.status == 206:
            need(response.headers.get('Content-Range', '').startswith('bytes 0-'), 'Invalid video range response')
        need(b'ftyp' in response.read(64), 'Hero video does not contain MP4 signature')


def verify_private_directory(path):
    if not path.exists():
        path.mkdir(mode=0o700)
    info = path.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700
         and path.resolve() == path, 'Backup directory must be private and root-owned')


def private_write(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb', closefd=False) as output:
            output.write(data)
            output.flush()
        os.fsync(fd)
    finally:
        os.close(fd)


def install(revision, manifest_hash):
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOSTNAME, 'Fixed production identity required')
    need(Path(PHP).is_file(), 'Expected production PHP binary is missing')
    os.umask(0o077)
    user = pwd.getpwnam(SITE_USER)
    entries, files = read_release(revision, manifest_hash)
    blade_paths = [path for path in files if path.endswith('.blade.php')]
    for path in blade_paths:
        check_blade(files[path])
    lock_fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock_fd)
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and not stat.S_IMODE(info.st_mode) & 0o077, 'Unsafe production lock')
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        with ExitStack() as stack:
            targets, before = {}, {}
            for entry in entries:
                path = entry['path']
                try:
                    targets[path] = Target(path, user, stack)
                    before[path] = targets[path].read()
                except FileNotFoundError:
                    # Missing parents are allowed only for reviewed additions.
                    need(entry['baseline_sha256'] is None, 'Replacement parent is missing: ' + path)
                    targets[path], before[path] = None, None
            changed = []
            for entry in entries:
                path, old = entry['path'], before[entry['path']]
                if old is not None and digest(old['data']) == entry['sha256']:
                    continue
                baseline = entry['baseline_sha256']
                need(old is None and baseline is None or old is not None and baseline is not None
                     and digest(old['data']) == baseline, 'Existing file differs from reviewed baseline: ' + path)
                changed.append(path)
            verify_private_directory(BACKUPS)
            backup = Path(tempfile.mkdtemp(prefix=revision[:12] + '-', dir=BACKUPS))
            os.chmod(backup, 0o700)
            record = {'release': RELEASE, 'revision': revision, 'created_at': datetime.now(timezone.utc).isoformat(), 'files': {}}
            for path in changed:
                old = before[path]
                record['files'][path] = None if old is None else {key: old[key] for key in ('mode', 'uid', 'gid')}
                if old is not None:
                    saved = backup / path
                    saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                    private_write(saved, old['data'])
            private_write(backup / 'manifest.json', json.dumps(record, indent=2).encode('utf-8'))
            written, created_directories = {}, []
            try:
                create_missing_parents(changed, user, stack, created_directories)
                for path in changed:
                    if targets[path] is None:
                        targets[path] = Target(path, user, stack)
                        need(targets[path].read() is None, 'Concurrent addition before deployment: ' + path)
                # Assets first; expose the replacement homepage last.
                order = [path for path in changed if path != HOME_VIEW] + ([HOME_VIEW] if HOME_VIEW in changed else [])
                for path in order:
                    written[path] = None
                    written[path] = targets[path].write(files[path], before[path], before[path])
                refresh_compiled_views(blade_paths)
                last = None
                for attempt in range(3):
                    try:
                        smoke(revision)
                        last = None
                        break
                    except Exception as error:
                        last = error
                        if attempt < 2:
                            time.sleep(2)
                if last:
                    raise last
                print(json.dumps({'status': 'STOREFRONT_MOTION_READY', 'revision': revision,
                                  'changed_files': len(written), 'backup': str(backup), 'database_writes': 0}))
            except BaseException:
                complete = True
                for path in reversed(list(written)):
                    try:
                        published = written[path] or targets[path].published
                        if published is None:
                            continue
                        old = before[path]
                        if old is None:
                            targets[path].remove(published)
                        else:
                            targets[path].write(old['data'], published, old)
                    except Exception:
                        complete = False
                try:
                    refresh_compiled_views(blade_paths)
                except Exception:
                    complete = False
                for parent, name, child, expected in reversed(created_directories):
                    try:
                        need(directory_identity(os.fstat(child)) == expected
                             and directory_identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == expected,
                             'Created application directory changed')
                        os.rmdir(name, dir_fd=parent)
                        os.fsync(parent)
                    except Exception:
                        complete = False
                print('STOREFRONT_MOTION_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'), file=sys.stderr)
                raise
    finally:
        os.close(lock_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--manifest-sha256')
    parser.add_argument('--check-local', type=Path)
    args = parser.parse_args()
    need(REVISION.fullmatch(args.revision), 'Pinned 40-character revision required')
    if args.check_local is not None:
        root = args.check_local.resolve()
        manifest = root / PREFIX / 'manifest.json'
        entries = validate_manifest(json.loads(manifest.read_text()))
        for entry in entries:
            path = root / entry['path']
            need(root in path.resolve().parents and path.is_file() and not path.is_symlink(), 'Unsafe local source path')
            need(digest(path.read_bytes()) == entry['sha256'], 'Local file hash mismatch: ' + entry['path'])
        print('STOREFRONT_MOTION_LOCAL_MANIFEST_OK')
        return
    need(isinstance(args.manifest_sha256, str) and SHA256.fullmatch(args.manifest_sha256), 'Pinned manifest SHA256 required')
    install(args.revision, args.manifest_sha256)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('STOREFRONT_MOTION_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
