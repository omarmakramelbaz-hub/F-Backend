#!/usr/bin/env python3
"""Offer the new GO ERP desktop app on the dashboard page "Desktop program", where the old app's versions were.

Run as root on srv1836524 from a clean checkout:
  python3 -I -B deployment/publish_go_erp_desktop_download_root.py --version 1.4.0 --sha512 BASE64 --revision COMMIT

It downloads that exact version from the GitHub release, refuses it unless its SHA-512 matches the value given
on the command line, then places it where the dashboard page reads published installers
(/var/lib/go-erp-manual-updates/shared). The version shown before becomes "previous version". Nothing else
changes: no site file, database, service or setting. Running it again with the same version changes nothing.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import tempfile
import urllib.request

HOST = 'srv1836524'
SHARED = Path('/var/lib/go-erp-manual-updates/shared')
REPOSITORY = 'https://github.com/omarmakramelbaz-hub/F-Backend'
FRAMEWORK = '8.83.29'
MAX_BYTES = 1024 ** 3
VERSION = re.compile(r'(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\Z')
REVISION = re.compile(r'[a-f0-9]{40}\Z')
SHA256 = re.compile(r'[a-f0-9]{64}\Z')


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_DESKTOP_DOWNLOAD_' + reason)


def directory(path, owner):
    """The dashboard only reads root-owned 0750 folders; their group is the site's group."""
    info = path.lstat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == owner and stat.S_IMODE(info.st_mode) == 0o750, 'FOLDER_' + path.name.upper())
    return info


def sync_directory(path):
    fd = os.open(str(path), os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_atomic(path, data, owner, gid):
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-desktop-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fchown(output.fileno(), owner, gid)
            os.fchmod(output.fileno(), 0o640)
            os.fsync(output.fileno())
        os.replace(temporary, str(path))
        sync_directory(path.parent)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def download(url, target, expected_sha512, opener=urllib.request.urlopen):
    """Streams the installer into the installers folder; returns (sha256, bytes) only if SHA-512 matches."""
    sha512 = hashlib.sha512()
    sha256 = hashlib.sha256()
    size = 0
    fd, temporary = tempfile.mkstemp(prefix='.go-erp-desktop-download-', dir=str(target))
    try:
        with os.fdopen(fd, 'wb') as output, opener(url, timeout=120) as response:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                need(size <= MAX_BYTES, 'INSTALLER_TOO_LARGE')
                sha512.update(chunk)
                sha256.update(chunk)
                output.write(chunk)
            output.flush()
            os.fsync(output.fileno())
        need(size > 0 and base64.b64encode(sha512.digest()).decode() == expected_sha512, 'INSTALLER_CHECKSUM_MISMATCH')
        return temporary, sha256.hexdigest(), size
    except BaseException:
        os.unlink(temporary)
        raise


def read_records(path):
    try:
        data = json.loads(path.read_bytes().decode('utf-8'))
    except FileNotFoundError:
        return None
    need(isinstance(data, dict) and data.get('format') == 1 and data.get('kind') == 'go-erp-published-downloads', 'RECORD_UNREADABLE')
    return data


def record(version, sequence, revision, sha, size):
    """The dashboard's published-download format. "source" describes the app itself here: the old
    program's server-source pinning does not apply to the new app, which syncs through its own API."""
    return {'version': version, 'sequence': sequence, 'source_revision': revision,
            'source': {'format': 1, 'files': 1, 'sha256': sha, 'framework': FRAMEWORK},
            'installer': {'sha256': sha, 'bytes': size, 'path': '/api/desktop-dashboard/update/installer/' + sha},
            'gui': {}}


def publish(shared, version, expected_sha512, revision, opener=urllib.request.urlopen, owner=0):
    need(VERSION.match(version) is not None, 'VERSION')
    need(REVISION.match(revision) is not None, 'REVISION')
    need(len(expected_sha512) == 88, 'SHA512')
    directory(shared, owner)
    installers = shared / 'installers'
    gid = directory(installers, owner).st_gid
    downloads = shared / 'downloads.json'
    current = read_records(downloads)
    old = current['current'] if current else None
    if old and old.get('version') == version:
        existing = installers / (old['installer']['sha256'] + '.exe')
        if existing.is_file() and existing.stat().st_size == old['installer']['bytes']:
            return {'result': 'GO_ERP_DESKTOP_DOWNLOAD_ALREADY_PUBLISHED', 'version': version, 'sha256': old['installer']['sha256']}
    url = '{0}/releases/download/desktop-v{1}/GO-ERP-Desktop-Setup-{1}.exe'.format(REPOSITORY, version)
    temporary, sha, size = download(url, installers, expected_sha512, opener)
    final = installers / (sha + '.exe')
    try:
        os.chown(temporary, owner, gid)
        os.chmod(temporary, 0o640)
        os.replace(temporary, str(final))
        sync_directory(installers)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    previous = None
    sequence = 1
    if old and SHA256.match(str(old.get('installer', {}).get('sha256', ''))):
        sequence = int(old.get('sequence', 0)) + 1
        if old.get('source_revision') != revision and old['installer']['sha256'] != sha and old.get('version') != version:
            previous = old
    payload = {'format': 1, 'kind': 'go-erp-published-downloads', 'current': record(version, sequence, revision, sha, size),
               'previous': previous}
    write_atomic(downloads, (json.dumps(payload, ensure_ascii=False, indent=2) + '\n').encode('utf-8'), owner, gid)
    keep = {sha + '.exe'} | ({previous['installer']['sha256'] + '.exe'} if previous else set())
    removed = 0
    for name in os.listdir(str(installers)):
        if re.match(r'[a-f0-9]{64}\.exe\Z', name) and name not in keep:
            os.unlink(str(installers / name))
            removed += 1
    check = read_records(downloads)
    need(check['current']['installer']['sha256'] == sha and final.stat().st_size == size, 'VERIFY')
    return {'result': 'GO_ERP_DESKTOP_DOWNLOAD_PUBLISHED', 'version': version, 'sha256': sha, 'bytes': size,
            'previous_version': previous['version'] if previous else None, 'older_files_removed': removed}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--version', required=True)
    parser.add_argument('--sha512', required=True)
    parser.add_argument('--revision', required=True)
    options = parser.parse_args()
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == HOST, 'ROOT_AND_HOST_REQUIRED')
    os.umask(0o077)
    print(json.dumps(publish(SHARED, options.version, options.sha512, options.revision), sort_keys=True), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if isinstance(error, Stop) and message.startswith('GO_ERP_')
              else 'GO_ERP_DESKTOP_DOWNLOAD_STOPPED: ' + type(error).__name__, flush=True)
        raise SystemExit(1)
