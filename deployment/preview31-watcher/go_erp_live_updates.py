#!/usr/bin/env python3
"""Root-only 60-second source watcher and accepted Windows release publisher.

No live checkout, application source, database or SSH identity is changed. The
only live write is the root-owned desktop update publication directory. GitHub
credentials remain in private configuration and process memory, never Git URLs,
arguments, durable state, exception text or logs.
"""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import shutil
import signal
import socket
import stat
import struct
import subprocess
import sys
import tempfile
import time
import types
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile

REPOSITORY = 'omarmakramelbaz-hub/F-Backend'
REPOSITORY_ID = 1311412084
BRANCH = 'codex/go-erp-live-source-20261021'
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
CONFIG = Path('/etc/go-erp/live-updates.json')
POLL_SECONDS = 60
ARTIFACT = 'fasakhansta-dashboard-installer-preview'
WORKFLOWS = {'desktop-dashboard-tests.yml': {'connected-native', 'original-runtime', 'windows-installer'},
             'go-erp-update-tests.yml': {'update-contracts'},
             'whatsapp-integrated-regressions.yml': {'ui', 'runtime'}}
FILES = {'GO-ERP-Preview-Setup.exe': 1024 ** 3,
         'installer-checks.json': 512 * 1024, 'runtime-package.json': 16 * 1024 ** 2,
         'runtime-audit.json': 8 * 1024 ** 2, 'prerequisites/vcredist-package.json': 512 * 1024,
         'offline-print-native.json': 512 * 1024, 'outbox-review-native.json': 512 * 1024,
         'connected-request-native.json': 512 * 1024, 'go-erp-update-release.json': 512 * 1024,
         'go-erp-live-source-acceptance.json': 64 * 1024}
MAX_ARCHIVE = 1024 ** 3 + 64 * 1024 ** 2
MAX_JSON = 16 * 1024 ** 2
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')
SAFE_INTEGER = 9007199254740991
REMOTE = 'https://github.com/' + REPOSITORY + '.git'
DEPENDENCIES = ('composer.json', 'composer.lock', 'vendor/composer/installed.json', 'vendor/composer/installed.php')
CANDIDATE_POLICY = 'historical-baseline-v2'
CAPACITY_SEED = '7523ff6888a6e3d0a6f5b7712c553909f12c043b'
CAPACITY_POLICY = CANDIDATE_POLICY + '-capacity-seed-v1'
TRANSPORT_SEED = '52f7f5d22e5a2026e5bd5732bbb7893125b6ef9d'
TRANSPORT_POLICY = CANDIDATE_POLICY + '-snapshot-transport-seed-v1'
PREVIEW31_SEED = 'd6b2c6195e555a6b62a534709428daeedd0d015b'
PREVIEW31_POLICY = CANDIDATE_POLICY + '-native-import-memory-seed-v1'


class WatchError(Exception):
    pass


class Superseded(WatchError):
    pass


def need(value, code):
    if not value:
        raise WatchError('GO_ERP_LIVE_' + code)


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode('utf8')


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def exact(value, names):
    return isinstance(value, dict) and set(value) == set(names)


def positive(value):
    return type(value) is int and 0 < value <= SAFE_INTEGER


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def ancestors(path):
    need(path.is_absolute() and path.resolve() == path, 'PATH_UNSAFE')
    for parent in (path, *path.parents):
        info = parent.lstat()
        need(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode), 'DIRECTORY_UNSAFE')


def owned_directory(path, create=False, mode=0o700):
    if create:
        path.mkdir(mode=mode, parents=False, exist_ok=True)
    ancestors(path)
    info = path.lstat()
    need(info.st_uid == 0 and not info.st_mode & 0o022, 'DIRECTORY_OWNERSHIP_UNSAFE')
    return info


def regular_bytes(path, maximum, private=False):
    ancestors(path.parent)
    before = path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
         and not before.st_mode & (0o077 if private else 0o022)
         and 0 < before.st_size <= maximum, 'FILE_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        need(identity(os.fstat(fd)) == identity(before), 'FILE_CHANGED')
        result = bytearray()
        while len(result) <= maximum:
            chunk = os.read(fd, min(65536, maximum + 1 - len(result)))
            if not chunk:
                break
            result.extend(chunk)
        need(len(result) == before.st_size and identity(before) == identity(os.fstat(fd))
             == identity(path.lstat()), 'FILE_CHANGED')
        return bytes(result)
    finally:
        os.close(fd)


def parse_json(data):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            need(key not in result, 'DUPLICATE_JSON_FIELD')
            result[key] = value
        return result
    try:
        return json.loads(data, object_pairs_hook=unique)
    except (ValueError, UnicodeError):
        raise WatchError('GO_ERP_LIVE_JSON_INVALID') from None


def read_config(path=CONFIG):
    for parent in path.parents:
        info = parent.lstat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022, 'CONFIG_PARENT_UNSAFE')
    value = parse_json(regular_bytes(path, 16384, private=True))
    need(exact(value, ('format', 'repository', 'repository_id', 'branch', 'base_revision', 'token'))
         and type(value['format']) is int and value['format'] == 1
         and value['repository'] == REPOSITORY and value['repository_id'] == REPOSITORY_ID
         and value['branch'] == BRANCH and isinstance(value['base_revision'], str)
         and REV.fullmatch(value['base_revision']), 'CONFIG_INVALID')
    token = value['token']
    need(isinstance(token, str) and 20 <= len(token) <= 1024
         and all(33 <= ord(char) <= 126 for char in token), 'CONFIG_TOKEN_INVALID')
    return value


def atomic_json(path, value):
    data = canonical(value) + b'\n'
    need(len(data) <= MAX_JSON, 'STATE_LIMIT')
    temporary = path.parent / ('.' + path.name + '-' + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        with contextlib.suppress(FileNotFoundError):
            temporary.unlink()


def require_space(path, amount):
    need(type(amount) is int and amount >= 0 and shutil.disk_usage(path).free >= amount + 64 * 1024 ** 2,
         'STORAGE_BLOCKED')


def dependency_snapshot(root, web_uid):
    """Hash four fixed dependency manifests; never retain or execute their bytes."""
    def once():
        ancestors(root)
        root_before = root.lstat()
        root_fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        result = {}
        try:
            need(identity(os.fstat(root_fd)) == identity(root_before), 'DEPENDENCY_ROOT_CHANGED')
            for relative in DEPENDENCIES:
                parent = os.dup(root_fd)
                descriptor = None
                try:
                    parts = relative.split('/')
                    try:
                        for component in parts[:-1]:
                            following = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                            os.close(parent)
                            parent = following
                        descriptor = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
                    except FileNotFoundError:
                        need(relative.startswith('vendor/'), 'DEPENDENCY_MANIFEST_MISSING')
                        result[relative] = None
                        continue
                    before = os.fstat(descriptor)
                    need(stat.S_ISREG(before.st_mode) and before.st_uid in (0, web_uid) and before.st_nlink == 1
                         and before.st_size <= 16 * 1024 ** 2, 'DEPENDENCY_MANIFEST_UNSAFE')
                    count, digest_value = 0, hashlib.sha256()
                    while True:
                        data = os.read(descriptor, 65536)
                        if not data:
                            break
                        count += len(data)
                        need(count <= 16 * 1024 ** 2, 'DEPENDENCY_MANIFEST_LIMIT')
                        digest_value.update(data)
                    need(count == before.st_size and identity(before) == identity(os.fstat(descriptor))
                         == identity(os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)), 'DEPENDENCY_MANIFEST_CHANGED')
                    result[relative] = digest_value.hexdigest()
                finally:
                    if descriptor is not None:
                        os.close(descriptor)
                    os.close(parent)
            need(result['vendor/composer/installed.json'] is not None or result['vendor/composer/installed.php'] is not None,
                 'DEPENDENCY_INSTALLED_METADATA_MISSING')
            need(identity(root_before) == identity(root.lstat()) == identity(os.fstat(root_fd)), 'DEPENDENCY_ROOT_CHANGED')
            return result
        finally:
            os.close(root_fd)
    first, second = once(), once()
    need(first == second, 'DEPENDENCY_SNAPSHOT_CHANGED')
    return first


def load_module(name, file):
    value = types.ModuleType(name)
    value.__file__ = str(file)
    sys.modules[name] = value
    exec(compile(regular_bytes(file, 2 * 1024 ** 2), str(file), 'exec'), value.__dict__)
    return value


def source_identity(snapshot):
    metadata = snapshot.get('metadata') if isinstance(snapshot, dict) else None
    need(isinstance(metadata, dict) and metadata.get('format') == 1
         and isinstance(metadata.get('inventorySha256'), str)
         and SHA.fullmatch(metadata['inventorySha256']), 'SNAPSHOT_INVALID')
    return metadata['inventorySha256']


def invalidate_feed(root=ROOT):
    directory = root / 'storage/app/desktop-updates'
    try:
        before = owned_directory(directory)
    except FileNotFoundError:
        return
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        need(identity(before) == identity(os.fstat(fd)), 'FEED_DIRECTORY_CHANGED')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            item = os.stat('current.json', dir_fd=fd, follow_symlinks=False)
        except FileNotFoundError:
            return
        need(stat.S_ISREG(item.st_mode) and item.st_uid == 0 and item.st_nlink == 1
             and not item.st_mode & 0o022, 'FEED_UNSAFE')
        need(directory.lstat().st_dev == before.st_dev and directory.lstat().st_ino == before.st_ino
             and identity(item) == identity(os.stat('current.json', dir_fd=fd, follow_symlinks=False)), 'FEED_CHANGED')
        os.unlink('current.json', dir_fd=fd)
        os.fsync(fd)
    finally:
        os.close(fd)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *arguments, **keywords):
        return None


class GitHub:
    def __init__(self, token, opener=None, clock=time.monotonic):
        self.token = token
        self.opener = opener or urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
        self.clock = clock

    @staticmethod
    def allowed(url, artifact=False):
        try:
            parsed = urllib.parse.urlsplit(url)
            host = parsed.hostname or ''
            need(parsed.scheme == 'https' and not parsed.username and not parsed.password
                 and parsed.port in (None, 443) and len(url) <= 16384, 'HTTPS_URL_REQUIRED')
            cloud = host.endswith('.blob.core.windows.net') or host.endswith('.actions.githubusercontent.com')
            need(host == 'api.github.com' or artifact and (host == 'github.com' or cloud), 'HOST_NOT_ALLOWED')
            return host
        except ValueError:
            raise WatchError('GO_ERP_LIVE_HTTPS_URL_REQUIRED') from None

    def open(self, url, artifact=False):
        for _ in range(6):
            host = self.allowed(url, artifact)
            headers = {'Accept': 'application/vnd.github+json', 'User-Agent': 'GO-ERP-Live-Updates/1',
                       'X-GitHub-Api-Version': '2022-11-28'}
            if host == 'api.github.com':
                headers['Authorization'] = 'Bearer ' + self.token
            request = urllib.request.Request(url, headers=headers, method='GET')
            try:
                response = self.opener.open(request, timeout=20)
                if response.geturl() != url or response.status != 200:
                    response.close()
                    raise WatchError('GO_ERP_LIVE_UNEXPECTED_TRANSPORT_REDIRECT')
                return response
            except urllib.error.HTTPError as error:
                if artifact and error.code in (301, 302, 303, 307, 308):
                    location = error.headers.get('Location', '')
                    error.close()
                    url = urllib.parse.urljoin(url, location)
                    self.allowed(url, artifact=True)
                    continue
                status = error.code
                error.close()
                raise WatchError('GO_ERP_LIVE_GITHUB_HTTP_' + str(status)) from None
            except (OSError, urllib.error.URLError):
                raise WatchError('GO_ERP_LIVE_GITHUB_UNAVAILABLE') from None
        raise WatchError('GO_ERP_LIVE_ARTIFACT_REDIRECT_LIMIT')

    def json(self, path, missing=False):
        need(path.startswith('/repos/' + REPOSITORY + '/'), 'API_SCOPE_INVALID')
        try:
            response = self.open('https://api.github.com' + path)
        except WatchError as error:
            if missing and str(error) == 'GO_ERP_LIVE_GITHUB_HTTP_404':
                return None
            raise
        with response:
            data = response.read(MAX_JSON + 1)
        need(len(data) <= MAX_JSON, 'API_JSON_LIMIT')
        return parse_json(data)

    def pages(self, path, key):
        result = []
        for page in range(1, 11):
            value = self.json(path + ('&' if '?' in path else '?') + 'per_page=100&page=' + str(page))
            rows = value.get(key) if isinstance(value, dict) else None
            need(isinstance(rows, list), 'API_COLLECTION_INVALID')
            result.extend(rows)
            if len(rows) < 100:
                return result
        raise WatchError('GO_ERP_LIVE_API_COLLECTION_LIMIT')

    def download(self, artifact_id, target, expected_digest, expected_size, heartbeat):
        need(positive(artifact_id) and positive(expected_size) and expected_size <= MAX_ARCHIVE
             and isinstance(expected_digest, str) and SHA.fullmatch(expected_digest), 'ARTIFACT_IDENTITY_INVALID')
        total, result, started = 0, hashlib.sha256(), self.clock()
        response = self.open('https://api.github.com/repos/' + REPOSITORY + '/actions/artifacts/' + str(artifact_id) + '/zip', artifact=True)
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with response, os.fdopen(fd, 'wb') as output:
                while True:
                    heartbeat()
                    need(self.clock() - started <= 2400, 'ARTIFACT_DOWNLOAD_DEADLINE')
                    chunk = response.read(1024 ** 2)
                    if not chunk:
                        break
                    total += len(chunk)
                    need(total <= expected_size, 'ARTIFACT_SIZE_MISMATCH')
                    output.write(chunk)
                    result.update(chunk)
                output.flush()
                os.fsync(output.fileno())
            need(total == expected_size and result.hexdigest() == expected_digest, 'ARTIFACT_DIGEST_MISMATCH')
        except BaseException:
            with contextlib.suppress(FileNotFoundError):
                target.unlink()
            raise


def run_identity(run, workflow, revision):
    need(isinstance(run, dict) and positive(run.get('id')) and positive(run.get('run_attempt'))
         and run.get('event') == 'push' and run.get('head_branch') == BRANCH and run.get('head_sha') == revision
         and not run.get('pull_requests') and run.get('path', '').split('@')[0] == '.github/workflows/' + workflow
         and run.get('repository', {}).get('id') == REPOSITORY_ID
         and run.get('repository', {}).get('full_name') == REPOSITORY
         and run.get('head_repository', {}).get('id') == REPOSITORY_ID
         and run.get('head_repository', {}).get('full_name') == REPOSITORY, 'CI_RUN_IDENTITY_INVALID')
    return run


def accepted_run(client, workflow, revision):
    path = '/repos/' + REPOSITORY + '/actions/workflows/' + workflow + '/runs?branch=' + urllib.parse.quote(BRANCH, safe='') + '&event=push&head_sha=' + revision
    rows = client.pages(path, 'workflow_runs')
    candidates = [row for row in rows if row.get('head_sha') == revision and row.get('event') == 'push' and row.get('head_branch') == BRANCH]
    if not candidates:
        return None
    value = max(candidates, key=lambda item: (item.get('id', 0), item.get('run_attempt', 0)))
    run_identity(value, workflow, revision)
    if value.get('status') != 'completed':
        return None
    need(value.get('conclusion') == 'success', 'CI_FAILED')
    jobs = client.pages('/repos/' + REPOSITORY + '/actions/runs/' + str(value['id']) + '/attempts/' + str(value['run_attempt']) + '/jobs', 'jobs')
    for name in WORKFLOWS[workflow]:
        matching = [job for job in jobs if job.get('name') == name]
        need(len(matching) == 1 and matching[0].get('status') == 'completed' and matching[0].get('conclusion') == 'success'
             and matching[0].get('run_id') == value['id'] and matching[0].get('head_sha') == revision
             and matching[0].get('run_attempt') == value['run_attempt'], 'CI_JOB_ACCEPTANCE_INCOMPLETE')
    return value


def accepted_artifact(client, run, revision):
    rows = client.pages('/repos/' + REPOSITORY + '/actions/runs/' + str(run['id']) + '/artifacts', 'artifacts')
    matching = [row for row in rows if row.get('name') == ARTIFACT]
    need(len(matching) == 1, 'CI_ARTIFACT_AMBIGUOUS_OR_MISSING')
    item = matching[0]
    need(positive(item.get('id')) and positive(item.get('size_in_bytes')) and item['size_in_bytes'] <= MAX_ARCHIVE
         and item.get('expired') is False and isinstance(item.get('digest'), str)
         and item['digest'].startswith('sha256:') and SHA.fullmatch(item['digest'][7:])
         and item.get('workflow_run', {}).get('id') == run['id']
         and item['workflow_run'].get('repository_id') == REPOSITORY_ID
         and item['workflow_run'].get('head_repository_id') == REPOSITORY_ID
         and item['workflow_run'].get('head_sha') == revision and item['workflow_run'].get('head_branch') == BRANCH,
         'CI_ARTIFACT_IDENTITY_INVALID')
    return item


def extract_artifact(archive, destination, heartbeat=lambda: None):
    total = 0
    # A small exact roster never needs ZIP64. Inspect its bounded end record
    # before ZipFile can allocate an attacker-sized central directory.
    before = archive.lstat()
    need(stat.S_ISREG(before.st_mode) and not stat.S_ISLNK(before.st_mode) and before.st_nlink == 1
         and before.st_uid == 0 and 22 <= before.st_size <= MAX_ARCHIVE, 'ZIP_ARCHIVE_UNSAFE')
    with archive.open('rb') as stream:
        stream.seek(max(0, before.st_size - 65557))
        tail = stream.read(65557)
    end = tail.rfind(b'PK\x05\x06')
    need(end >= 0 and len(tail) - end >= 22, 'ZIP_END_RECORD_INVALID')
    record = struct.unpack('<4s4H2LH', tail[end:end + 22])
    need(record[1] == record[2] == 0 and record[3] == record[4] <= len(FILES) + 1
         and record[5] <= 1024 * 1024 and record[6] + record[5] <= before.st_size
         and end + 22 + record[7] == len(tail), 'ZIP_DIRECTORY_LIMIT')
    with zipfile.ZipFile(archive) as bundle:
        rows = bundle.infolist()
        need(len(rows) <= len(FILES) + 1, 'ZIP_ENTRY_LIMIT')
        seen = set()
        for row in rows:
            name, kind = row.filename, stat.S_IFMT(row.external_attr >> 16)
            need(name and '\\' not in name and '\x00' not in name and ':' not in name
                 and not name.startswith('/') and '..' not in PurePosixPath(name).parts
                 and name.casefold() not in seen and not row.flag_bits & 1, 'ZIP_PATH_OR_FLAGS_INVALID')
            seen.add(name.casefold())
            if row.is_dir():
                need(name == 'prerequisites/' and row.file_size == 0 and kind in (0, stat.S_IFDIR), 'ZIP_DIRECTORY_INVALID')
                continue
            need(name in FILES and kind in (0, stat.S_IFREG) and row.compress_type in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED)
                 and 0 < row.file_size <= FILES[name], 'ZIP_FILE_INVALID')
            target = destination.joinpath(*PurePosixPath(name).parts)
            if target.parent != destination:
                target.parent.mkdir(mode=0o700, exist_ok=True)
            fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            size = 0
            with bundle.open(row) as source, os.fdopen(fd, 'wb') as output:
                while True:
                    heartbeat()
                    chunk = source.read(65536)
                    if not chunk:
                        break
                    size += len(chunk)
                    total += len(chunk)
                    need(size <= FILES[name] and total <= MAX_ARCHIVE, 'ZIP_EXPANDED_SIZE_LIMIT')
                    output.write(chunk)
                need(size == row.file_size, 'ZIP_EXPANDED_SIZE_MISMATCH')
                output.flush()
                os.fsync(output.fileno())
        need({row.filename for row in rows if not row.is_dir()} == set(FILES), 'ZIP_ROSTER_INCOMPLETE')


def verify_proof(folder, run, desired, publisher):
    receipt_bytes = regular_bytes(folder / 'go-erp-update-release.json', FILES['go-erp-update-release.json'])
    receipt = parse_json(receipt_bytes)
    release = publisher.validate_receipt(receipt)
    proof = parse_json(regular_bytes(folder / 'go-erp-live-source-acceptance.json', FILES['go-erp-live-source-acceptance.json']))
    need(exact(proof, ('format', 'kind', 'repository', 'branch', 'revision', 'run_id', 'run_attempt', 'version',
                       'candidate_inventory_sha256', 'full_package_sha256', 'update_receipt_sha256', 'installer'))
         and type(proof['format']) is int and proof['format'] == 1 and proof['kind'] == 'go-erp-live-source-acceptance'
         and proof['repository'] == REPOSITORY and proof['branch'] == BRANCH
         and proof['revision'] == desired['commit'] == release['source_revision']
         and positive(proof['run_id']) and positive(proof['run_attempt'])
         and proof['run_id'] == run['id'] == release['sequence'] and proof['run_attempt'] == run['run_attempt']
         and proof['version'] == desired['version'] == release['version']
         and proof['candidate_inventory_sha256'] == desired['inventorySha256']
         and proof['update_receipt_sha256'] == hashlib.sha256(receipt_bytes).hexdigest()
         and proof['installer'] == receipt['installer'], 'CI_LIVE_PROOF_MISMATCH')
    package = parse_json(regular_bytes(folder / 'runtime-package.json', FILES['runtime-package.json']))
    need(package.get('sourceRevision') == desired['commit'] and package.get('version') == desired['version']
         and package.get('sourceFingerprint') == release['source'] and isinstance(package.get('files'), dict)
         and 0 < len(package['files']) <= 100000
         and all(isinstance(name, str) and isinstance(value, str) and SHA.fullmatch(value) for name, value in package['files'].items())
         and digest(package['files']) == proof['full_package_sha256'], 'CI_FULL_RUNTIME_PROOF_MISMATCH')
    checks = parse_json(regular_bytes(folder / 'installer-checks.json', FILES['installer-checks.json']))
    need(checks.get('sourceRevision') == desired['commit'] and checks.get('version') == desired['version']
         and checks.get('installer') == receipt['installer']
         and all(checks.get(key) is True for key in ('installation', 'installedNativeExecutables', 'installedDashboardRecovery', 'packagedLedger', 'uninstallRetention'))
         and exact(checks.get('automaticUpgrade'), ('waitedForExit', 'preservedJournal', 'preservedUnrelatedFiles', 'registrationRetained', 'restartedInstalledExecutable'))
         and all(value is True for value in checks['automaticUpgrade'].values()), 'CI_INSTALLED_UPGRADE_PROOF_INCOMPLETE')
    native = {}
    for name in ('connected-request-native.json', 'outbox-review-native.json', 'offline-print-native.json'):
        value = parse_json(regular_bytes(folder / name, FILES[name]))
        need(value.get('format') == 1 and value.get('sourceRevision') == desired['commit'] and value.get('platform') == 'win32',
             'CI_NATIVE_PROOF_SOURCE_MISMATCH')
        native[name] = value
    connected, outbox, printing = [native[name] for name in ('connected-request-native.json', 'outbox-review-native.json', 'offline-print-native.json')]
    need(connected.get('hardware') is False and all(connected.get(key) is True for key in
         ('completed', 'actualElectronSessionFetch', 'actualWebRequest', 'actualDpapi'))
         and all(outbox.get(key) is True for key in ('completed', 'actualDpapiAudit', 'durableIntentBeforeRetry', 'unchangedCommandId'))
         and printing.get('hardware') is False and printing.get('invocations') == 7 and printing.get('accepted') == 6
         and printing.get('review') == 1 and all(printing.get(key) is True for key in
         ('realPreloadIpc', 'refreshFence', 'encryptedRestartRecovery')), 'CI_NATIVE_PROOF_INCOMPLETE')
    publisher.verify_installer(folder / 'GO-ERP-Preview-Setup.exe', release['installer'], root_owned=True)
    return receipt, release


class Git:
    def __init__(self, work, token, heartbeat=lambda: None):
        self.work, self.token, self.heartbeat = work, token, heartbeat
        self.askpass = work / 'state/git-askpass'
        code = b'#!/bin/sh\ncase "$1" in *Username*) printf "%s\\n" x-access-token;; *) printf "%s\\n" "$GO_ERP_GIT_TOKEN";; esac\n'
        try:
            existing = regular_bytes(self.askpass, 4096, private=True)
            need(existing == code, 'ASKPASS_CHANGED')
        except FileNotFoundError:
            fd = os.open(self.askpass, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o700)
            with os.fdopen(fd, 'wb') as output:
                output.write(code)
                output.flush()
                os.fsync(output.fileno())

    def run(self, arguments, cwd=None, timeout=180):
        env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8',
               'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_NO_REPLACE_OBJECTS': '1',
               'GIT_OPTIONAL_LOCKS': '0', 'GIT_TERMINAL_PROMPT': '0', 'GIT_ASKPASS': str(self.askpass),
               'GO_ERP_GIT_TOKEN': self.token}
        command = ['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                   '-c', 'credential.helper=', '-c', 'http.followRedirects=false', '-c', 'http.sslVerify=true', *arguments]
        output, error = tempfile.TemporaryFile(), tempfile.TemporaryFile()
        process = subprocess.Popen(command, cwd=cwd, env=env, stdout=output, stderr=error, start_new_session=True)
        start = time.monotonic()
        try:
            while process.poll() is None:
                self.heartbeat()
                need(time.monotonic() - start <= timeout, 'GIT_DEADLINE')
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    continue
            need(process.returncode == 0, 'GIT_OPERATION_FAILED')
            output.seek(0)
            data = output.read(MAX_JSON + 1)
            need(len(data) <= MAX_JSON, 'GIT_OUTPUT_LIMIT')
            return data
        finally:
            if process.poll() is None:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    with contextlib.suppress(ProcessLookupError):
                        os.killpg(process.pid, signal.SIGKILL)
                    process.wait(timeout=5)
            output.close()
            error.close()

    def seed(self, revision):
        checkout = self.work / 'checkout'
        if not checkout.exists():
            temporary = self.work / 'staging' / ('seed-' + uuid.uuid4().hex)
            temporary.mkdir(mode=0o700)
            try:
                self.run(['init', '--quiet', str(temporary)])
                self.run(['remote', 'add', 'origin', REMOTE], temporary)
                self.run(['fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules', 'origin', revision], temporary)
                self.run(['checkout', '--quiet', '--detach', revision], temporary)
                os.rename(temporary, checkout)
            finally:
                if temporary.exists():
                    shutil.rmtree(temporary)
        owned_directory(checkout)
        need(self.run(['rev-parse', 'HEAD'], checkout).decode().strip() == revision
             and self.run(['remote', 'get-url', 'origin'], checkout).decode().strip() == REMOTE
             and not self.run(['status', '--porcelain', '--untracked-files=all'], checkout), 'TRUSTED_SEED_CHANGED')
        return checkout

    def candidate(self, checkout, destination, revision):
        self.run(['clone', '--quiet', '--no-local', '--no-hardlinks', str(checkout), str(destination)])
        self.run(['remote', 'set-url', 'origin', REMOTE], destination)
        self.run(['checkout', '--quiet', '--detach', revision], destination)

    def push(self, checkout, commit, previous):
        need(REV.fullmatch(commit) and (previous is None or REV.fullmatch(previous)), 'PUSH_REVISION_INVALID')
        lease = '--force-with-lease=refs/heads/' + BRANCH + ':' + (previous or '')
        self.run(['push', '--porcelain', lease, 'origin', commit + ':refs/heads/' + BRANCH], checkout)


class Worker:
    def __init__(self, config, source, publisher, client=None, work=WORK, live=ROOT, web_uid=None, clock=time.time):
        self.config, self.source, self.publisher = config, source, publisher
        self.client, self.work, self.live = client or GitHub(config['token']), work, live
        self.clock, self.web_uid = clock, pwd.getpwnam('fasakha').pw_uid if web_uid is None else web_uid
        owned_directory(work)
        for name in ('state', 'staging'):
            owned_directory(work / name, create=True)
        self.state_file = work / 'state/current.json'
        try:
            self.state = parse_json(regular_bytes(self.state_file, MAX_JSON, private=True))
            need(isinstance(self.state, dict) and self.state.get('format') == 1
                 and self.state.get('base_revision') == config['base_revision'] and type(self.state.get('counter')) is int,
                 'DURABLE_STATE_INVALID')
        except FileNotFoundError:
            self.state = {'format': 1, 'base_revision': config['base_revision'], 'counter': 0,
                          'observed': None, 'desired': None, 'published': None, 'last_remote_sha': None,
                          'sequence_floor': 0}
        self.state.setdefault('sequence_floor', 0)
        self.state.setdefault('version_floor', None)
        self.state.setdefault('published_history', [])
        self.state.setdefault('dependency_guard', None)
        self.git = Git(work, config['token'])
        self.checkout = self.git.seed(config['base_revision'])
        self.live_due = 0
        self.publishing = False

    def save(self):
        atomic_json(self.state_file, self.state)

    def candidate_policy(self):
        need(getattr(self.source, 'CANDIDATE_POLICY', None) == CANDIDATE_POLICY,
             'CANDIDATE_POLICY_UNREVIEWED')
        if self.config['base_revision'] == CAPACITY_SEED:
            need(getattr(self.source, 'CAPACITY_SEED', None) == CAPACITY_SEED
                 and getattr(self.source, 'CAPACITY_POLICY', None) == CAPACITY_POLICY,
                 'CAPACITY_POLICY_UNREVIEWED')
            return CAPACITY_POLICY
        if self.config['base_revision'] == PREVIEW31_SEED:
            need(getattr(self.source, 'PREVIEW31_SEED', None) == PREVIEW31_SEED
                 and getattr(self.source, 'PREVIEW31_POLICY', None) == PREVIEW31_POLICY,
                 'PREVIEW31_POLICY_UNREVIEWED')
            return PREVIEW31_POLICY
        if self.config['base_revision'] == TRANSPORT_SEED:
            need(getattr(self.source, 'TRANSPORT_SEED', None) == TRANSPORT_SEED
                 and getattr(self.source, 'TRANSPORT_POLICY', None) == TRANSPORT_POLICY,
                 'TRANSPORT_POLICY_UNREVIEWED')
            return TRANSPORT_POLICY
        return CANDIDATE_POLICY

    def scan(self):
        return self.source.scan_snapshot(self.live, self.checkout, self.web_uid, self.state.get('observed'))

    def check_dependencies(self):
        current = dependency_snapshot(self.live, self.web_uid)
        previous = self.state['dependency_guard']
        if previous is None:
            self.state['dependency_guard'] = current
            self.save()
        elif previous != current:
            if not self.publishing:
                try:
                    self.invalidate()
                finally:
                    invalidate_feed(self.live)
            raise WatchError('GO_ERP_LIVE_DEPENDENCY_CHANGE_REQUIRES_REVIEWED_SEED')

    def guard_live(self, expected, force=False):
        if not force and self.clock() < self.live_due:
            return
        self.live_due = self.clock() + 20
        try:
            self.check_dependencies()
            fresh = self.scan()
            if source_identity(fresh) != expected:
                self.state['observed'] = fresh['metadata']
                raise Superseded('GO_ERP_LIVE_SOURCE_SUPERSEDED')
        except BaseException:
            if not self.publishing:
                self.invalidate()
            raise

    def next_counter(self):
        info = parse_json(regular_bytes(self.checkout / 'desktop-pos/package.json', 65536))
        found = re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+-preview\.([1-9][0-9]*)', info.get('version', ''))
        need(found is not None, 'SEED_PREVIEW_VERSION_REQUIRED')
        previous = int(found[1])
        self.check_version_floor(info['version'])
        try:
            current = self.publisher.read_json(self.live / 'storage/app/desktop-updates/current.json', root_owned=True)
            current = self.publisher.validate_release(current)
            version = re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+-preview\.([1-9][0-9]*)', current.get('version', ''))
            if version:
                previous = max(previous, int(version[1]))
        except FileNotFoundError:
            pass
        self.state['counter'] = max(self.state['counter'], previous) + 1
        need(positive(self.state['counter']), 'VERSION_SEQUENCE_EXHAUSTED')
        self.save()
        return self.state['counter']

    def check_version_floor(self, seed_version=None):
        if seed_version is None:
            seed_version = parse_json(regular_bytes(self.checkout / 'desktop-pos/package.json', 65536)).get('version', '')
        if self.state['version_floor'] is not None:
            prefix = seed_version.split('-preview.')[0]
            match = re.fullmatch(re.escape(prefix) + r'-preview\.([1-9][0-9]*)', self.state['version_floor'])
            need(match is not None, 'TRUSTED_SEED_UPGRADE_REQUIRED')
            self.state['counter'] = max(self.state['counter'], int(match[1]))

    def invalidate(self):
        # Retain the already published version high-water mark before removing
        # its feed, including a restart whose private state was not yet saved.
        try:
            current = self.publisher.read_json(self.live / 'storage/app/desktop-updates/current.json', root_owned=True)
            current = self.publisher.validate_release(current)
            old_version = self.state['version_floor']
            if old_version is None or self.publisher.compare_versions(current['version'], old_version) > 0:
                self.state['version_floor'] = current['version']
            version = re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+-preview\.([1-9][0-9]*)', current.get('version', ''))
            if version:
                self.state['counter'] = max(self.state['counter'], int(version[1]))
            self.state['sequence_floor'] = max(self.state['sequence_floor'], current['sequence'])
            self.save()
        except FileNotFoundError:
            pass
        self.check_version_floor()
        invalidate_feed(self.live)

    def prepare(self, snapshot):
        policy = self.candidate_policy()
        expected, counter = source_identity(snapshot), self.next_counter()
        require_space(self.work, sum(row.get('bytes', 0) for row in snapshot['metadata']['inventory'].values()) * 3 + 128 * 1024 ** 2)
        destination = self.work / 'staging' / ('candidate-' + str(counter) + '-' + expected[:16])
        self.git.heartbeat = lambda: self.guard_live(expected)
        complete = False
        try:
            self.git.candidate(self.checkout, destination, self.config['base_revision'])
            result = self.source.prepare_candidate(destination, snapshot, counter,
                                                   heartbeat=lambda: self.guard_live(expected))
            need(isinstance(result, dict) and REV.fullmatch(result.get('commit', ''))
                 and result.get('inventorySha256') == expected
                 and result.get('candidatePath') == 'deployment/go_erp_live_source_candidate.json'
                 and result.get('candidatePolicy') == policy
                 and isinstance(result.get('version'), str), 'CANDIDATE_INVALID')
            desired = {key: result[key] for key in ('commit', 'version', 'inventorySha256', 'candidatePolicy')}
            desired['candidatePath'] = str(destination)
            desired.update({'pushed': False, 'failures': 0, 'retry_at': 0, 'status': 'prepared'})
            self.state['observed'], self.state['desired'] = snapshot['metadata'], desired
            self.save()
            complete = True
            return desired
        finally:
            self.git.heartbeat = lambda: None
            if not complete and destination.exists():
                shutil.rmtree(destination)

    def retire_candidate(self, desired):
        if not desired:
            return
        path = Path(desired.get('candidatePath', ''))
        need(path.parent == self.work / 'staging' and re.fullmatch(r'candidate-[1-9][0-9]*-[a-f0-9]{16}', path.name),
             'CANDIDATE_PATH_UNSAFE')
        try:
            owned_directory(path)
        except FileNotFoundError:
            return
        shutil.rmtree(path)

    def push(self, desired):
        remote = self.client.json('/repos/' + REPOSITORY + '/git/ref/heads/' + BRANCH, missing=True)
        actual = remote.get('object', {}).get('sha') if remote is not None else None
        need(actual is None or isinstance(actual, str) and REV.fullmatch(actual), 'REMOTE_BRANCH_INVALID')
        if actual != desired['commit']:
            need(actual in (None, self.state.get('last_remote_sha'), self.config['base_revision']), 'REMOTE_BRANCH_CHANGED_OUTSIDE_WORKER')
            self.git.heartbeat = lambda: self.guard_live(desired['inventorySha256'])
            try:
                self.git.push(Path(desired['candidatePath']), desired['commit'], actual)
            finally:
                self.git.heartbeat = lambda: None
        desired['pushed'], desired['status'] = True, 'waiting-ci'
        self.state['last_remote_sha'] = desired['commit']
        self.save()
        self.retire_candidate(desired)

    def prune_publications(self):
        history = self.state['published_history']
        seen, ordered = set(), []
        for release in reversed(history):
            self.publisher.validate_release(release)
            sha = release['installer']['sha256']
            if sha not in seen:
                seen.add(sha)
                ordered.append(release)
        keep, old = ordered[:3], ordered[3:]
        if not old:
            self.state['published_history'] = list(reversed(keep))
            return
        directory = self.live / 'storage/app/desktop-updates'
        before = owned_directory(directory)
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            current = self.publisher.validate_release(self.publisher.read_json(directory / 'current.json', root_owned=True))
            for release in old:
                sha = release['installer']['sha256']
                if sha == current['installer']['sha256']:
                    keep.append(release)
                    continue
                path = directory / (sha + '.exe')
                try:
                    item = path.lstat()
                except FileNotFoundError:
                    continue
                self.publisher.verify_installer(path, release['installer'], root_owned=True)
                need(identity(item) == identity(path.lstat()) and directory.lstat().st_ino == before.st_ino
                     and directory.lstat().st_dev == before.st_dev, 'PRUNE_IDENTITY_CHANGED')
                os.unlink(sha + '.exe', dir_fd=fd)
            os.fsync(fd)
            self.state['published_history'] = list(reversed(keep))
        finally:
            os.close(fd)

    def fetch_accepted(self, desired):
        runs = {}
        for workflow in WORKFLOWS:
            accepted = accepted_run(self.client, workflow, desired['commit'])
            if accepted is None:
                return None
            runs[workflow] = accepted
        run = runs['desktop-dashboard-tests.yml']
        artifact = accepted_artifact(self.client, run, desired['commit'])
        require_space(self.work, artifact['size_in_bytes'] + 2 * 1024 ** 3)
        stage = self.work / 'staging' / ('accepted-' + str(run['id']) + '-' + str(run['run_attempt']) + '-' + uuid.uuid4().hex)
        stage.mkdir(mode=0o700)
        try:
            heartbeat = lambda: self.guard_live(desired['inventorySha256'])
            archive = stage / 'accepted.zip'
            self.client.download(artifact['id'], archive, artifact['digest'][7:], artifact['size_in_bytes'], heartbeat)
            files = stage / 'files'
            files.mkdir(mode=0o700)
            extract_artifact(archive, files, heartbeat)
            receipt, release = verify_proof(files, run, desired, self.publisher)
            previous = self.state.get('published')
            need(release['sequence'] > self.state['sequence_floor'] or previous and previous.get('release') == release,
                 'RELEASE_SEQUENCE_ROLLBACK')
            self.guard_live(desired['inventorySha256'], force=True)
            def bound_snapshot(root):
                self.guard_live(desired['inventorySha256'], force=True)
                value = self.publisher.source_snapshot(root)
                value['live_inventory_sha256'] = desired['inventorySha256']
                self.guard_live(desired['inventorySha256'], force=True)
                return value
            require_space(self.live / 'storage/app/desktop-updates', release['installer']['bytes'])
            self.publishing = True
            try:
                result = self.publisher.publish(self.live, files / 'GO-ERP-Preview-Setup.exe', receipt, snapshot=bound_snapshot)
            finally:
                self.publishing = False
            need(result.get('release') == release and (result.get('published') is True or result.get('idempotent') is True), 'PUBLICATION_RECEIPT_INVALID')
            self.state['published'] = {'inventorySha256': desired['inventorySha256'], 'commit': desired['commit'],
                                       'run_id': run['id'], 'run_attempt': run['run_attempt'], 'release': release}
            self.state['sequence_floor'] = max(self.state['sequence_floor'], release['sequence'])
            self.state['version_floor'] = release['version']
            self.state['published_history'].append(release)
            desired['status'] = 'published'
            self.save()
            try:
                self.prune_publications()
                self.save()
                return 'GO_ERP_LIVE_UPDATE_PUBLISHED'
            except BaseException:
                return 'GO_ERP_LIVE_UPDATE_PUBLISHED_STORAGE_REVIEW_REQUIRED'
        except BaseException:
            self.invalidate()
            raise
        finally:
            shutil.rmtree(stage)

    def tick(self):
        try:
            policy = self.candidate_policy()
            self.check_dependencies()
            snapshot = self.scan()
            current = source_identity(snapshot)
            self.state['observed'] = snapshot['metadata']
            desired, published = self.state.get('desired'), self.state.get('published')
            if published and published.get('inventorySha256') == current:
                try:
                    feed = self.publisher.read_json(self.live / 'storage/app/desktop-updates/current.json', root_owned=True)
                    if feed == published['release']:
                        self.save()
                        return 'GO_ERP_LIVE_UPDATE_CURRENT'
                except FileNotFoundError:
                    pass
                # A lost feed requires one replacement build. Keep that build
                # while CI runs instead of replacing it on every minute tick.
                if desired is None or desired.get('commit') == published.get('commit'):
                    desired = None
                    self.state['desired'] = None
            if (desired is None or desired.get('inventorySha256') != current
                    or desired.get('candidatePolicy') != policy):
                self.invalidate()
                self.retire_candidate(desired)
                desired = self.prepare(snapshot)
            self.save()
            if desired.get('retry_at', 0) > self.clock():
                return 'GO_ERP_LIVE_UPDATE_RETRY_DEFERRED'
            if desired.get('status') == 'blocked':
                return 'GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED'
            if desired.get('status') == 'retry-build':
                if desired.get('pushed'):
                    # An exact rerun may have recovered this candidate during
                    # its build backoff. Keep all publication checks, and only
                    # replace it if CI still reports a completed failure.
                    self.guard_live(current, force=True)
                    try:
                        return self.fetch_accepted(desired) or 'GO_ERP_LIVE_UPDATE_WAITING_CI'
                    except WatchError as error:
                        if str(error) != 'GO_ERP_LIVE_CI_FAILED':
                            raise
                failures = desired['failures']
                self.retire_candidate(desired)
                desired = self.prepare(snapshot)
                desired['failures'] = failures
                self.save()
            if not desired.get('pushed'):
                self.push(desired)
            self.guard_live(current, force=True)
            return self.fetch_accepted(desired) or 'GO_ERP_LIVE_UPDATE_WAITING_CI'
        except Superseded:
            self.invalidate()
            self.state['desired'] = None
            self.save()
            return 'GO_ERP_LIVE_UPDATE_SUPERSEDED'
        except BaseException as error:
            self.invalidate()
            desired = self.state.get('desired')
            if desired:
                desired['failures'] = min(100, desired.get('failures', 0) + 1)
                desired['retry_at'] = self.clock() + min(3600, 60 * 2 ** min(desired['failures'], 6))
                if isinstance(error, WatchError) and str(error) == 'GO_ERP_LIVE_CI_FAILED':
                    desired['status'] = 'blocked' if desired['failures'] >= 3 else 'retry-build'
                    desired['retry_at'] = self.clock() + 900 * desired['failures']
            self.save()
            raise WatchError(str(error) if isinstance(error, WatchError) else 'GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT') from None


def main(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', default=str(CONFIG))
    parser.add_argument('--once', action='store_true')
    options = parser.parse_args(arguments)
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    need(Path(options.config) == CONFIG, 'FIXED_CONFIG_REQUIRED')
    config = read_config()
    installed = Path(__file__).absolute().parent
    source = load_module('go_erp_live_source', installed / 'go_erp_live_source.py')
    publisher = load_module('go_erp_live_publisher', installed / 'publish_go_erp_update.py')
    owned_directory(WORK)
    lock = os.open(WORK / 'watcher.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        item = os.fstat(lock)
        need(stat.S_ISREG(item.st_mode) and item.st_uid == 0 and item.st_nlink == 1 and not item.st_mode & 0o077, 'LOCK_UNSAFE')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print('GO_ERP_LIVE_UPDATE_ALREADY_RUNNING')
            return 0
        worker = Worker(config, source, publisher)
        while True:
            try:
                print(worker.tick(), flush=True)
            except WatchError as error:
                print(str(error), file=sys.stderr, flush=True)
                if options.once:
                    return 1
            if options.once:
                return 0
            time.sleep(POLL_SECONDS)
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        result = main()
    except Exception as error:
        print(str(error) if isinstance(error, WatchError) else 'GO_ERP_LIVE_NOT_CONFIGURED_OR_UNAVAILABLE', file=sys.stderr)
        result = 1
    sys.exit(result)
