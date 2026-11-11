#!/usr/bin/env python3
"""Update only the reviewed copy and presentation of the dedicated links page."""
import argparse
from contextlib import ExitStack
from datetime import datetime, timezone
import fcntl
import hashlib
from html import unescape
from html.parser import HTMLParser
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import socket
import stat
import sys
import tempfile
import time
import urllib.request
from urllib.parse import urljoin, urlsplit
import uuid

ROOT = Path('/home/fasakha/public_html')
SITE_USER = 'fasakha'
HOSTNAME = 'srv1836524'
RELEASE = 'links-copy-20261111'
PAGE_RELEASE = 'links-hub-20261111'
REPOSITORY = 'https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend'
PREFIX = 'deployment/links-copy-20261111'
BACKUPS = Path('/root/fasakhansta-links-copy-backups')
LOCK = Path('/var/lock/fasakhansta-links-copy.lock')
PAGE = 'public/links.html'
BASELINES = {PAGE: '3fe271c3190fe1429e938a6288f4bf18163091111efe3b46f746199490783efc'}
ALLOWED = frozenset(BASELINES)
MAX_FILE = 4 * 1024 * 1024
MAX_TOTAL = MAX_FILE
SHA256 = re.compile(r'^[a-f0-9]{64}$')
REVISION = re.compile(r'^[a-f0-9]{40}$')
EXPECTED_HREFS = frozenset({
    'https://www.facebook.com/share/1PtQ9aq9aB/',
    'https://www.instagram.com/faskhansta/',
    'https://www.tiktok.com/@fasakhansta',
    'https://wa.me/Faskhansta',
    'https://wa.me/c/201285545554',
    'https://ipn.eg/S/omarmakramelbaz/instapay/90iWWJ',
    'http://vf.eg/vfcash?id=mt&qrId=SMdtgE',
    'https://fasakhaninja.com/downloadApp',
    'https://fasakhaninja.com/',
    'https://maps.app.goo.gl/virmer6AWCErHTm49?g_st=ac',
    'https://maps.app.goo.gl/fzAmYirXeDa4jMA67?g_st=aw',
    'https://maps.app.goo.gl/nch1a1jsMu54dvR3A?g_st=aw',
    'https://maps.app.goo.gl/xUWYBGSSYP4yXvvKA?g_st=aw',
})
LOGO_PATHS = frozenset({'/storefront/motion-logo.webp', '/dashboard/branding/fasakhansta-logo-transparent.png'})


def need(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def validate_manifest(payload):
    need(isinstance(payload, list) and len(payload) == 1, 'Exactly one manifest entry is required')
    seen = set()
    for entry in payload:
        need(isinstance(entry, dict) and set(entry) == {'path', 'sha256', 'baseline_sha256'}, 'Invalid manifest entry')
        path = entry['path']
        need(isinstance(path, str) and path in ALLOWED and path not in seen, 'Manifest path is not uniquely allowlisted')
        parts = PurePosixPath(path)
        need(not parts.is_absolute() and '..' not in parts.parts and str(parts) == path, 'Unsafe manifest path')
        need(isinstance(entry['sha256'], str) and SHA256.fullmatch(entry['sha256']), 'Invalid target hash')
        need(entry['baseline_sha256'] == BASELINES[path], 'Manifest baseline differs from the reviewed source: ' + path)
        seen.add(path)
    need(seen == ALLOWED, 'Manifest must contain only the dedicated links page')
    return payload


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        raise RuntimeError('Unexpected download redirect')


HTTP = urllib.request.build_opener(NoRedirect())


def download(url, limit):
    need(url.startswith('https://'), 'HTTPS is required')
    request = urllib.request.Request(url, headers={'User-Agent': 'Fasakhansta-Links-Copy-Installer', 'Accept': '*/*'})
    with HTTP.open(request, timeout=45) as response:
        need(response.status == 200 and response.geturl() == url, 'Unexpected download response')
        data = response.read(limit + 1)
    need(len(data) <= limit, 'Download exceeds reviewed size limit')
    return data


class PageMarkers(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.release = False
        self.hrefs = set()
        self.logo = None
        self.capture = None
        self.parts = []
        self.texts = {'h1': [], 'p': []}
        self.all_text = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        self.release |= attrs.get('data-release') == PAGE_RELEASE
        if tag in self.texts:
            self.capture, self.parts = tag, []
        if tag == 'a' and attrs.get('href'):
            self.hrefs.add(attrs['href'])
        if tag == 'img' and attrs.get('src'):
            url = urljoin('https://fasakhaninja.com/', attrs['src'])
            parsed = urlsplit(url)
            if parsed.scheme == 'https' and parsed.netloc == 'fasakhaninja.com' and parsed.path in LOGO_PATHS:
                self.logo = 'https://fasakhaninja.com' + parsed.path


    def handle_data(self, data):
        self.all_text.append(data)
        if self.capture is not None:
            self.parts.append(data)

    def handle_endtag(self, tag):
        if tag == self.capture:
            self.texts[tag].append(' '.join(''.join(self.parts).split()))
            self.capture = None


def validate_page(data):
    html = data.decode('utf-8')
    page = PageMarkers()
    page.feed(html)
    need(page.release, 'Dedicated links-page release marker is missing')
    need(EXPECTED_HREFS <= page.hrefs, 'One or more reviewed social/payment/branch links are missing')
    need(page.logo is not None, 'The page must use the reviewed same-origin logo')
    need(page.texts['h1'] == ['اللمة الحلوة ليها ناسها'], 'The single reviewed main headline is missing')
    removed = 'أحسن فسيخ في العالم'
    need(removed not in ' '.join(unescape(html).split())
         and removed not in ' '.join(''.join(page.all_text).split()), 'The removed subtitle remains in the links page')
    return page


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
    validate_page(files[PAGE])
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
        name = '.links-copy-' + uuid.uuid4().hex
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


def smoke(revision, files):
    query = '?links_copy_verify=' + revision
    page = download('https://fasakhaninja.com/links.html' + query, MAX_FILE)
    need(digest(page) == digest(files[PAGE]), 'The reviewed links page is not served')
    markers = validate_page(page)
    logo_url = markers.logo + query
    request = urllib.request.Request(logo_url, headers={'User-Agent': 'Fasakhansta-Links-Copy-Installer'})
    with HTTP.open(request, timeout=30) as response:
        need(response.status == 200 and response.geturl() == logo_url, 'Same-origin logo is not served')
        signature = response.read(16)
    need(signature.startswith(b'\x89PNG\r\n\x1a\n') or signature[:4] == b'RIFF' and signature[8:12] == b'WEBP', 'Unexpected same-origin logo format')


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


def publish(entries, files, user, revision):
    """Preflight every destination, then publish atomically with private rollback copies."""
    with ExitStack() as stack:
        targets = {entry['path']: Target(entry['path'], user, stack) for entry in entries}
        before = {path: target.read() for path, target in targets.items()}
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
        written = {}
        try:
            # The single allowlisted page is replaced atomically.
            for path in changed:
                written[path] = None
                written[path] = targets[path].write(files[path], before[path], before[path])
            return backup, written, targets, before, stack.pop_all()
        except BaseException:
            rollback(written, targets, before)
            raise


def rollback(written, targets, before):
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
    print('LINKS_COPY_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'), file=sys.stderr)
    return complete


def install(revision, manifest_hash):
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOSTNAME, 'Fixed production identity required')
    os.umask(0o077)
    user = pwd.getpwnam(SITE_USER)
    entries, files = read_release(revision, manifest_hash)
    lock_fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock_fd)
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and not stat.S_IMODE(info.st_mode) & 0o077, 'Unsafe production lock')
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        backup, written, targets, before, held = publish(entries, files, user, revision)
        with held:
            try:
                for attempt in range(3):
                    try:
                        smoke(revision, files)
                        break
                    except Exception:
                        if attempt == 2:
                            raise
                        time.sleep(2)
                print(json.dumps({'status': 'LINKS_COPY_READY', 'revision': revision,
                                  'changed_files': len(written), 'backup': str(backup), 'database_writes': 0}))
            except BaseException:
                rollback(written, targets, before)
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
        files = {}
        for entry in entries:
            path = root / entry['path']
            need(root in path.resolve().parents and path.is_file() and not path.is_symlink(), 'Unsafe local source path')
            files[entry['path']] = path.read_bytes()
            need(len(files[entry['path']]) <= MAX_FILE and digest(files[entry['path']]) == entry['sha256'], 'Local source hash/size mismatch: ' + entry['path'])
        need(sum(map(len, files.values())) <= MAX_TOTAL, 'Release exceeds reviewed total size limit')
        validate_page(files[PAGE])
        print('LINKS_COPY_LOCAL_MANIFEST_OK')
        return
    need(isinstance(args.manifest_sha256, str) and SHA256.fullmatch(args.manifest_sha256), 'Pinned manifest SHA256 required')
    install(args.revision, args.manifest_sha256)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('LINKS_COPY_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
