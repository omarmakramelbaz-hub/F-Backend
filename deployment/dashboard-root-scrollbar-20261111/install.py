#!/usr/bin/env python3
"""Append only the reviewed dashboard root and HUD scrollbar-visual override."""
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
import sys
import tempfile
import time
import urllib.request
import uuid

ROOT = Path('/home/fasakha/public_html')
SITE_USER = 'fasakha'
HOSTNAME = 'srv1836524'
RELEASE = 'dashboard-root-scrollbar-20261111'
REPOSITORY = 'https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend'
PREFIX = 'deployment/dashboard-root-scrollbar-20261111'
CSS = 'public/dashboard/branding/dashboard-brand.css'
BASELINE_SHA256 = '78bdd502399aeb6d7114e762a3fb4c80e86d2a6b2f60dd57b67690d2efc7e434'
BASELINE_BYTES = 50982
BASELINES = {CSS: BASELINE_SHA256}
TARGETS = frozenset((CSS,))
BACKUPS = Path('/root/fasakhansta-dashboard-root-scrollbar-backups')
LOCK = Path('/var/lock/fasakhansta-dashboard-root-scrollbar.lock')
MAX_FILE = 256 * 1024
MAX_TOTAL = MAX_FILE
PATCH = b"""
/* dashboard-root-hud-scrollbar-20261111: hide only page and HUD scrollbar chrome. */
html,
body.dashboard-theme,
body.dashboard-login-page,
#go-hud {
    scrollbar-width: none !important;
}
html::-webkit-scrollbar,
body.dashboard-theme::-webkit-scrollbar,
body.dashboard-login-page::-webkit-scrollbar,
#go-hud::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
"""
SHA256 = re.compile(r'^[a-f0-9]{64}$')
REVISION = re.compile(r'^[a-f0-9]{40}$')
ORIGIN = 'https://fasakhaninja.com'


def need(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def validate_manifest(payload):
    need(isinstance(BASELINE_SHA256, str) and SHA256.fullmatch(BASELINE_SHA256)
         and isinstance(BASELINE_BYTES, int) and 0 < BASELINE_BYTES < MAX_FILE,
         'The reviewed live CSS baseline must be frozen')
    need(isinstance(payload, list) and len(payload) == 1, 'Exactly one manifest entry is required')
    entry = payload[0]
    need(isinstance(entry, dict) and set(entry) == {'path', 'sha256', 'baseline_sha256'}, 'Invalid manifest entry')
    path = entry['path']
    need(isinstance(path, str) and path == CSS, 'Only the reviewed shared dashboard CSS is allowed')
    parts = PurePosixPath(path)
    need(not parts.is_absolute() and '..' not in parts.parts and str(parts) == path, 'Unsafe manifest path')
    need(isinstance(entry['sha256'], str) and SHA256.fullmatch(entry['sha256']), 'Invalid release SHA256')
    need(entry['baseline_sha256'] == BASELINE_SHA256, 'Unexpected CSS baseline')
    return payload


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        raise RuntimeError('Unexpected HTTP redirect')


HTTP = urllib.request.build_opener(NoRedirect())


def download(url, limit, content_type=None):
    need(url.startswith('https://'), 'HTTPS is required')
    request = urllib.request.Request(url, headers={'User-Agent': 'Fasakhansta-Dashboard-Root-Scrollbar-Installer', 'Accept': '*/*'})
    with HTTP.open(request, timeout=45) as response:
        need(response.status == 200 and response.geturl() == url, 'Unexpected download response')
        if content_type is not None:
            need(response.headers.get_content_type() == content_type, 'Unexpected public content type')
        data = response.read(limit + 1)
    need(len(data) <= limit, 'Download exceeds reviewed size limit')
    return data


def validate_css(data):
    need(0 < len(data) <= MAX_FILE, 'CSS exceeds reviewed size limit')
    data.decode('utf-8')
    need(isinstance(BASELINE_BYTES, int) and 0 < BASELINE_BYTES < len(data)
         and digest(data[:BASELINE_BYTES]) == BASELINE_SHA256,
         'The complete original CSS must be preserved byte-for-byte')
    need(data[BASELINE_BYTES:] == PATCH,
         'Only the exact reviewed root and HUD scrollbar-visual block may be appended')


def read_release(revision, manifest_hash):
    manifest_bytes = download(f'{REPOSITORY}/{revision}/{PREFIX}/manifest.json', 16 * 1024)
    need(digest(manifest_bytes) == manifest_hash, 'Pinned manifest hash mismatch')
    entries = validate_manifest(json.loads(manifest_bytes.decode('utf-8')))
    data = download(f'{REPOSITORY}/{revision}/{CSS}', MAX_FILE)
    need(len(data) + len(manifest_bytes) <= MAX_TOTAL and digest(data) == entries[0]['sha256'],
         'Release CSS hash/total size mismatch')
    validate_css(data)
    return entries, {CSS: data}


def identity(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def directory_identity(info):
    return identity(info)[:5]


class Target:
    """Hold directory descriptors so writes never follow an application symlink."""
    def __init__(self, relative, user, stack):
        need(relative in TARGETS, 'Unexpected destination path')
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
        name = '.dashboard-root-scrollbar-' + uuid.uuid4().hex
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
    need(set(files) == TARGETS, 'Unexpected release roster')
    url = ORIGIN + '/' + CSS[len('public/'):] + '?dashboard_root_scrollbar_verify=' + revision
    served = download(url, MAX_FILE, 'text/css')
    need(digest(served) == digest(files[CSS]), 'The reviewed shared dashboard CSS is not served')
    validate_css(served)


def installation_order(changed):
    need(set(changed) <= TARGETS, 'Unexpected publication target')
    return [CSS] if CSS in changed else []


def preflight(entries, files, user, stack):
    targets = {entry['path']: Target(entry['path'], user, stack) for entry in entries}
    before = {path: target.read() for path, target in targets.items()}
    changed = []
    for entry in entries:
        path, prior = entry['path'], before[entry['path']]
        if prior is not None and digest(prior['data']) == entry['sha256']:
            continue
        baseline = entry['baseline_sha256']
        need(prior is None and baseline is None or prior is not None and baseline is not None
             and digest(prior['data']) == baseline, 'Existing destination differs from reviewed baseline: ' + path)
        changed.append(path)
    return targets, before, changed


def rollback(written, targets, before):
    complete = True
    for path in reversed(list(written)):
        try:
            published = written[path] or targets[path].published
            if published is not None:
                prior = before[path]
                if prior is None:
                    targets[path].remove(published)
                else:
                    targets[path].write(prior['data'], published, prior)
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
    os.umask(0o077)
    user = pwd.getpwnam(SITE_USER)
    entries, files = read_release(revision, manifest_hash)
    lock_fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock_fd)
        need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
             and not stat.S_IMODE(info.st_mode) & 0o077, 'Unsafe production lock')
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        with ExitStack() as stack:
            targets, before, changed = preflight(entries, files, user, stack)
            verify_private_directory(BACKUPS)
            backup = Path(tempfile.mkdtemp(prefix=revision[:12] + '-', dir=BACKUPS))
            os.chmod(backup, 0o700)
            record = {'release': RELEASE, 'revision': revision, 'created_at': datetime.now(timezone.utc).isoformat(), 'files': {}}
            for path in changed:
                prior = before[path]
                record['files'][path] = None if prior is None else {key: prior[key] for key in ('mode', 'uid', 'gid')}
                if prior is not None:
                    saved = backup / path
                    saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                    private_write(saved, prior['data'])
            private_write(backup / 'manifest.json', json.dumps(record, indent=2).encode('utf-8'))
            written = {}
            try:
                for path in installation_order(changed):
                    written[path] = None
                    written[path] = targets[path].write(files[path], before[path], before[path])
                for attempt in range(3):
                    try:
                        smoke(revision, files)
                        break
                    except Exception:
                        if attempt == 2:
                            raise
                        time.sleep(2)
                print(json.dumps({'status': 'DASHBOARD_ROOT_SCROLLBAR_READY', 'revision': revision,
                                  'url': ORIGIN + '/' + CSS[len('public/'):],
                                  'changed_files': len(written), 'backup': str(backup), 'database_writes': 0}))
            except BaseException:
                complete = rollback(written, targets, before)
                print('DASHBOARD_ROOT_SCROLLBAR_ROLLBACK_' + ('RESTORED' if complete else 'NEEDS_REVIEW'), file=sys.stderr)
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
        manifest = (root / PREFIX / 'manifest.json').read_bytes()
        entries = validate_manifest(json.loads(manifest.decode('utf-8')))
        path = root / CSS
        need(root in path.resolve().parents and path.is_file() and not path.is_symlink(), 'Unsafe local release source')
        need(path.stat().st_size <= MAX_FILE, 'Local source exceeds reviewed size limit')
        data = path.read_bytes()
        need(len(data) + len(manifest) <= MAX_TOTAL and digest(data) == entries[0]['sha256'],
             'Local CSS hash/total size mismatch')
        validate_css(data)
        print('DASHBOARD_ROOT_SCROLLBAR_LOCAL_MANIFEST_OK')
        return
    need(isinstance(args.manifest_sha256, str) and SHA256.fullmatch(args.manifest_sha256), 'Pinned manifest SHA256 required')
    install(args.revision, args.manifest_sha256)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('DASHBOARD_ROOT_SCROLLBAR_FAILED: ' + str(error), file=sys.stderr)
        sys.exit(1)
