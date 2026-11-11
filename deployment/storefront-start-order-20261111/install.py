#!/usr/bin/env python3
"""Install only the reviewed hero/navigation menu actions and their storefront bridges."""
import argparse
from contextlib import ExitStack
from datetime import datetime, timezone
import fcntl
import hashlib
from html.parser import HTMLParser
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
import urllib.parse
import urllib.request
import uuid

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
SITE_USER = 'fasakha'
HOSTNAME = 'srv1836524'
RELEASE = 'storefront-start-order-20261111'
REPOSITORY = 'https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend'
PREFIX = 'deployment/storefront-start-order-20261111'
BACKUPS = Path('/root/fasakhansta-storefront-start-order-backups')
LOCK = Path('/var/lock/fasakhansta-storefront-start-order.lock')
HOME_VIEW = 'resources/views/site/home.blade.php'
HERO_VIEW = 'resources/views/site/motion/hero.blade.php'
HEADER_VIEW = 'resources/views/site/motion/header.blade.php'
JS_PATH = 'public/storefront/storefront.js'
JS_VERSION = '20261111-start-order-1'
MOTION_JS_PATH = 'public/storefront/motion.js'
MOTION_JS_VERSION = '20261111-menu-nav-1'
SCRIPT_VERSIONS = {JS_PATH: JS_VERSION, MOTION_JS_PATH: MOTION_JS_VERSION}
ALLOWED = frozenset({HOME_VIEW, HERO_VIEW, HEADER_VIEW, JS_PATH, MOTION_JS_PATH})
BASELINES = {
    HOME_VIEW: '555d5ea5595d2f417eec81b79d89f6a15e1fe5f554f8310e269d31869e41f0c8',
    HERO_VIEW: 'ebaa6134a5abe8ac0bbade4d6f7098ffbbc1b8a7c68a17d8ef7fea5b54c54fd5',
    JS_PATH: '4bf2d3133c8ab1a4e75a522c6dcf2eece7b38d734e4590428febcb2249eb9c14',
    HEADER_VIEW: 'a654e5de3f58d28ad33f698c6c62c8e520b585eac0903cc9f07bd3247d951531',
    MOTION_JS_PATH: 'c296a4169214ba25e5b426e25ddb764d2d3f94c0c103b0c3f0e42f7cef08a539',
}
MAX_FILE = 2 * 1024 * 1024
MAX_TOTAL = 4 * 1024 * 1024
SHA256 = re.compile(r'^[a-f0-9]{64}$')
REVISION = re.compile(r'^[a-f0-9]{40}$')
HERO_REFERENCES = ('hero-motion-4k.mp4', 'hero-motion-hd.mp4', 'hero-motion-4k-poster.webp')


def need(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def validate_manifest(payload):
    need(isinstance(payload, list) and len(payload) == 5, 'Exactly five manifest entries are required')
    seen = set()
    for entry in payload:
        need(isinstance(entry, dict) and set(entry) == {'path', 'sha256', 'baseline_sha256'}, 'Invalid manifest entry')
        path = entry['path']
        need(isinstance(path, str) and path in ALLOWED and path not in seen, 'Manifest path is not uniquely allowlisted')
        parts = PurePosixPath(path)
        need(not parts.is_absolute() and '..' not in parts.parts and str(parts) == path, 'Unsafe manifest path')
        need(isinstance(entry['sha256'], str) and SHA256.fullmatch(entry['sha256']), 'Invalid target hash')
        need(entry['baseline_sha256'] == BASELINES[path], 'Manifest baseline is not the reviewed 4K source: ' + path)
        seen.add(path)
    need(seen == ALLOWED, 'Manifest must contain only the two reviewed JS files and three views')
    return payload


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        raise RuntimeError('Unexpected download redirect')


HTTP = urllib.request.build_opener(NoRedirect())


def download(url, limit):
    need(url.startswith('https://'), 'HTTPS is required')
    request = urllib.request.Request(url, headers={'User-Agent': 'Fasakhansta-Start-Order-Installer', 'Accept': '*/*'})
    with HTTP.open(request, timeout=45) as response:
        need(response.status == 200 and response.geturl() == url, 'Unexpected download response')
        data = response.read(limit + 1)
    need(len(data) <= limit, 'Download exceeds reviewed size limit')
    return data


class PageMarkers(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.start_order = False
        self.scripts = []
        self.sources = []
        self.posters = []
        self.nav_stack = []
        self.nav_capture = None
        self.menu_nav = {'desktop': 0, 'mobile': 0}
        self.picker_label = False
        self.store_picker = 0

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'label':
            self.picker_label = (attrs.get('data-action') == 'choose-store'
                                 and {'branch-picker', 'header-store-picker'} <= set(attrs.get('class', '').split()))
        if tag == 'select' and attrs.get('id') == 'branch-select' and self.picker_label:
            self.store_picker += 1
        if tag == 'nav':
            classes = attrs.get('class', '').split()
            self.nav_stack.append('desktop' if 'f-nav' in classes else 'mobile' if 'f-mobile-menu' in classes else None)
        if tag in ('a', 'button') and self.nav_stack and self.nav_stack[-1] is not None:
            if attrs.get('data-action') == 'start-order' and 'data-scroll' not in attrs:
                self.nav_capture = {'tag': tag, 'nav': self.nav_stack[-1], 'parts': []}
        if tag == 'a' and attrs.get('data-action') == 'start-order' and attrs.get('href') == '#menu':
            self.start_order = True
        if tag == 'script' and attrs.get('src'):
            self.scripts.append(attrs['src'])
        if tag == 'source' and attrs.get('src'):
            self.sources.append((attrs['src'], attrs.get('media', '')))
        if tag == 'video' and attrs.get('poster'):
            self.posters.append(attrs['poster'])

    def handle_data(self, data):
        if self.nav_capture is not None:
            self.nav_capture['parts'].append(data)

    def handle_endtag(self, tag):
        if self.nav_capture is not None and tag == self.nav_capture['tag']:
            label = ' '.join(''.join(self.nav_capture['parts']).split())
            if label == 'منتجاتنا':
                self.menu_nav[self.nav_capture['nav']] += 1
            self.nav_capture = None
        if tag == 'nav' and self.nav_stack:
            self.nav_stack.pop()
        if tag == 'label':
            self.picker_label = False


def validate_sources(files):
    need(set(files) == ALLOWED, 'Unexpected source roster')
    home, hero = files[HOME_VIEW].decode('utf-8'), files[HERO_VIEW].decode('utf-8')
    page = PageMarkers()
    page.feed(hero)
    need(page.start_order, 'Hero CTA must use data-action=start-order and href=#menu')
    need(all(reference in hero for reference in HERO_REFERENCES), 'Existing 4K/HD/poster references must remain unchanged')
    need('(max-width: 767px)' in hero and 'hero-motion-4k-poster.webp' in home, 'Existing mobile condition/poster preload must remain')
    page = PageMarkers()
    page.feed(home)
    for path, version in SCRIPT_VERSIONS.items():
        need(any(path[len('public/'):] in src and src.endswith('?v=' + version) for src in page.scripts), 'The reviewed JS cache version is missing: ' + path)
        need('start-order' in files[path].decode('utf-8'), 'The reviewed start-order bridge is missing: ' + path)
    page = PageMarkers()
    page.feed(files[HEADER_VIEW].decode('utf-8'))
    need(page.menu_nav == {'desktop': 1, 'mobile': 1}, 'Both product navigation buttons must use the start-order action')
    need(page.store_picker == 1, 'The header store label must keep the choose-store action and branch-select control')
    need('choose-store' in files[JS_PATH].decode('utf-8'), 'The reviewed choose-store JS action is missing')


def read_release(revision, manifest_hash):
    manifest_bytes = download(f'{REPOSITORY}/{revision}/{PREFIX}/manifest.json', 16 * 1024)
    need(digest(manifest_bytes) == manifest_hash, 'Pinned manifest hash mismatch')
    entries = validate_manifest(json.loads(manifest_bytes.decode('utf-8')))
    files, total = {}, 0
    for entry in entries:
        data = download(f"{REPOSITORY}/{revision}/{entry['path']}", MAX_FILE)
        need(digest(data) == entry['sha256'], 'Downloaded file hash mismatch: ' + entry['path'])
        total += len(data)
        need(total <= MAX_TOTAL, 'Release exceeds reviewed total size limit')
        files[entry['path']] = data
    validate_sources(files)
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
        name = '.storefront-start-order-' + uuid.uuid4().hex
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


def smoke(revision, files):
    homepage = download('https://fasakhaninja.com/?start_order_verify=' + revision, MAX_FILE).decode('utf-8')
    page = PageMarkers()
    page.feed(homepage)
    need(page.start_order, 'The start-order CTA is not served in the homepage')
    need(page.menu_nav == {'desktop': 1, 'mobile': 1}, 'The desktop/mobile product navigation hooks are not served')
    need(page.store_picker == 1, 'The header choose-store label and branch-select control are not served')
    urls = [urllib.parse.urlsplit(urllib.parse.urljoin('https://fasakhaninja.com/', src)) for src in page.scripts]
    for path, version in SCRIPT_VERSIONS.items():
        matching = [url for url in urls if url.scheme == 'https' and url.netloc == 'fasakhaninja.com'
                    and url.path == '/' + path[len('public/'):] and url.query == 'v=' + version and not url.fragment]
        need(matching, 'The homepage is not serving the reviewed JS cache version: ' + path)
        served_js = download(urllib.parse.urlunsplit(matching[0]), MAX_FILE)
        need(digest(served_js) == digest(files[path]), 'The reviewed JS is not served: ' + path)
    source_paths = {urllib.parse.urlsplit(urllib.parse.urljoin('https://fasakhaninja.com/', src)).path for src, _ in page.sources}
    poster_paths = {urllib.parse.urlsplit(urllib.parse.urljoin('https://fasakhaninja.com/', src)).path for src in page.posters}
    need({'/storefront/hero-motion-4k.mp4', '/storefront/hero-motion-hd.mp4'} <= source_paths
         and '/storefront/hero-motion-4k-poster.webp' in poster_paths, 'The existing 4K hero references were not preserved')
    need(any('hero-motion-hd.mp4' in src and media == '(max-width: 767px)' for src, media in page.sources), 'The existing mobile hero condition was not preserved')


def installation_order(changed):
    need(set(changed) <= ALLOWED, 'Unexpected publication path')
    return [path for path in (JS_PATH, MOTION_JS_PATH, HERO_VIEW, HEADER_VIEW, HOME_VIEW) if path in changed]


def preflight(entries, user, stack):
    targets = {entry['path']: Target(entry['path'], user, stack) for entry in entries}
    before = {path: target.read() for path, target in targets.items()}
    changed = []
    for entry in entries:
        path, old = entry['path'], before[entry['path']]
        if old is not None and digest(old['data']) == entry['sha256']:
            continue
        need(old is not None and digest(old['data']) == entry['baseline_sha256'], 'Existing file differs from reviewed baseline: ' + path)
        changed.append(path)
    return targets, before, changed


def rollback(written, targets, before):
    complete = True
    for path in reversed(list(written)):
        try:
            published = written[path] or targets[path].published
            if published is not None:
                old = before[path]
                targets[path].write(old['data'], published, old)
        except Exception:
            complete = False
    return complete


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
    blade_paths = [HERO_VIEW, HEADER_VIEW, HOME_VIEW]
    for path in blade_paths:
        check_blade(files[path])
    lock_fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock_fd)
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and not stat.S_IMODE(info.st_mode) & 0o077, 'Unsafe production lock')
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        with ExitStack() as stack:
            targets, before, changed = preflight(entries, user, stack)
            verify_private_directory(BACKUPS)
            backup = Path(tempfile.mkdtemp(prefix=revision[:12] + '-', dir=BACKUPS))
            os.chmod(backup, 0o700)
            record = {'release': RELEASE, 'revision': revision, 'created_at': datetime.now(timezone.utc).isoformat(), 'files': {}}
            for path in changed:
                prior = before[path]
                record['files'][path] = {key: prior[key] for key in ('mode', 'uid', 'gid')}
                saved = backup / path
                saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                private_write(saved, prior['data'])
            private_write(backup / 'manifest.json', json.dumps(record, indent=2).encode('utf-8'))
            written = {}
            try:
                for path in installation_order(changed):
                    written[path] = None
                    written[path] = targets[path].write(files[path], before[path], before[path])
                refresh_compiled_views(blade_paths)
                for attempt in range(3):
                    try:
                        smoke(revision, files)
                        break
                    except Exception:
                        if attempt == 2:
                            raise
                        time.sleep(2)
                print(json.dumps({'status': 'STOREFRONT_START_ORDER_READY', 'revision': revision,
                                  'changed_files': len(written), 'backup': str(backup), 'database_writes': 0}))
            except BaseException:
                complete = rollback(written, targets, before)
                try:
                    refresh_compiled_views(blade_paths)
                except Exception:
                    complete = False
                print('STOREFRONT_START_ORDER_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'), file=sys.stderr)
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
        entries = validate_manifest(json.loads((root / PREFIX / 'manifest.json').read_text()))
        files, total = {}, 0
        for entry in entries:
            path = root / entry['path']
            need(root in path.resolve().parents and path.is_file() and not path.is_symlink(), 'Unsafe local source path')
            data = path.read_bytes()
            need(len(data) <= MAX_FILE, 'Local file exceeds reviewed size limit: ' + entry['path'])
            total += len(data)
            need(total <= MAX_TOTAL, 'Local release exceeds reviewed total size limit')
            need(digest(data) == entry['sha256'], 'Local file hash mismatch: ' + entry['path'])
            files[entry['path']] = data
        validate_sources(files)
        print('STOREFRONT_START_ORDER_LOCAL_MANIFEST_OK')
        return
    need(isinstance(args.manifest_sha256, str) and SHA256.fullmatch(args.manifest_sha256), 'Pinned manifest SHA256 required')
    install(args.revision, args.manifest_sha256)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('STOREFRONT_START_ORDER_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
