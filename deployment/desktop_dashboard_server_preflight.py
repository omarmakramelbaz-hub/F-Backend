#!/usr/bin/env python3
"""Read source identities and schema metadata; never publish or migrate."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import threading

PREVIEW = '86f943397bc74d13aa8f172ad3f88de1186bc60e'
EXPECTED = 'ff1b6c1b150d0e8c895f7ce4c8e0382bfcef0bf0871681d6c7b88c2e6e966933'
ROOTS = ('app', 'bootstrap', 'config', 'database', 'desktop', 'resources', 'routes',
         'public/dashboard/js', 'public/dashboard/css', 'public/dashboard/vendor/desktop-external')
SECRET = re.compile(r'(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$', re.I)
SUFFIX = re.compile(r'\.(?:php|js|css|json|html|scss|vue)$', re.I)
SAFE = re.compile(r'[a-zA-Z0-9_.@/-]+\Z')
LIMIT = 64 * 1024 * 1024
GIT_ENV = {**os.environ, 'GIT_NO_LAZY_FETCH': '1', 'GIT_OPTIONAL_LOCKS': '0',
           'GIT_NO_REPLACE_OBJECTS': '1'}

class InspectionError(Exception):
    pass

def included(name):
    return (not name.startswith('bootstrap/cache/') and not SECRET.search(name)
            and (name == 'artisan' or bool(SUFFIX.search(name))))

def git_blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()

def digest(files):
    value = hashlib.sha256(b'desktop-dashboard-source:1\n')
    for name, record in sorted(files.items()):
        value.update(name.encode() + b'\0' + record['sha256'].encode() + b'\n')
    return {'format': 1, 'files': len(files), 'sha256': value.hexdigest()}

def record(data):
    return {'blob': git_blob(data), 'sha256': hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()}

def command(args, maximum=8*1024*1024, env=None):
    try:
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=90, check=False, env=env)
    except (OSError, subprocess.TimeoutExpired):
        raise InspectionError('READ_COMMAND_UNAVAILABLE') from None
    if result.returncode or len(result.stdout) > maximum:
        raise InspectionError('READ_COMMAND_FAILED')
    return result.stdout

def git(project, *args):
    # Avoid optional index locks while reading repository metadata.
    return command(['git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '-C', str(project), *args], env=GIT_ENV)

def preview_source(project):
    rows = git(project, 'ls-tree', '-r', '-z', PREVIEW).split(b'\0')
    selected = []
    for row in filter(None, rows):
        metadata, path = row.split(b'\t', 1)
        mode, kind, blob = metadata.decode('ascii').split()
        name = path.decode('utf-8')
        if not (name == 'artisan' or any(name.startswith(root + '/') for root in ROOTS)) or not included(name):
            continue
        if mode not in ('100644', '100755') or kind != 'blob' or not SAFE.fullmatch(name):
            raise InspectionError('PREVIEW_PATH_INVALID')
        selected.append((name, blob))
    files = {}
    total = 0
    # Individual requested objects keep pipe buffers bounded without reading secrets.
    process = subprocess.Popen(['git', '--no-optional-locks', '-C', str(project), 'cat-file', '--batch'],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               env=GIT_ENV)
    deadline = threading.Timer(90, lambda: process.kill() if process.poll() is None else None)
    deadline.daemon = True
    deadline.start()
    try:
        for name, blob in selected:
            process.stdin.write((blob + '\n').encode()); process.stdin.flush()
            header = process.stdout.readline().decode('ascii').strip().split()
            if len(header) != 3 or header[:2] != [blob, 'blob']:
                raise InspectionError('PREVIEW_OBJECT_UNAVAILABLE')
            size = int(header[2]); total += size
            if total > LIMIT or size < 0 or len(files) >= 20000:
                raise InspectionError('PREVIEW_SOURCE_LIMIT')
            data = process.stdout.read(size)
            if len(data) != size or process.stdout.read(1) != b'\n' or git_blob(data) != blob:
                raise InspectionError('PREVIEW_OBJECT_INVALID')
            files[name] = record(data)
        process.stdin.close()
        if process.wait(timeout=30):
            raise InspectionError('PREVIEW_READ_FAILED')
    finally:
        deadline.cancel()
        if process.poll() is None:
            process.kill(); process.wait()
        process.stdout.close()
        if not process.stdin.closed:
            process.stdin.close()
    if digest(files) != {'format': 1, 'files': 1075, 'sha256': EXPECTED}:
        raise InspectionError('PREVIEW_FINGERPRINT_INVALID')
    return files

def live_source(project):
    files, cases, total = {}, set(), 0
    def read(path):
        nonlocal total
        name = path.relative_to(project).as_posix()
        if not included(name):
            return
        if not SAFE.fullmatch(name) or name.lower() in cases:
            raise InspectionError('LIVE_PATH_INVALID')
        cases.add(name.lower())
        # O_NOFOLLOW plus descriptor identity checks reject links and concurrent writes.
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, 'rb') as source:
            before = os.fstat(source.fileno())
            if not stat.S_ISREG(before.st_mode) or before.st_size > LIMIT - total or len(files) >= 20000:
                raise InspectionError('LIVE_SOURCE_LIMIT_OR_TYPE')
            data = source.read(LIMIT - total + 1)
            after = os.fstat(source.fileno())
        key = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        if key(before) != key(after) or key(after) != key(path.lstat()):
            raise InspectionError('LIVE_SOURCE_CHANGED')
        total += len(data)
        if total > LIMIT:
            raise InspectionError('LIVE_SOURCE_LIMIT')
        files[name] = record(data)
    for root in ROOTS:
        folder = project / root
        # Check every existing ancestor, including public/dashboard.
        for ancestor in (folder, *folder.parents):
            if ancestor == project.parent:
                break
            if ancestor.is_symlink():
                raise InspectionError('LIVE_SOURCE_LINK')
        if not folder.exists():
            continue
        if not folder.is_dir():
            raise InspectionError('LIVE_ROOT_INVALID')
        def walk_error(_):
            raise InspectionError('LIVE_SOURCE_UNREADABLE')
        for parent, directories, names in os.walk(folder, followlinks=False, onerror=walk_error):
            # Laravel excludes bootstrap/cache before its per-file link validation.
            for name in directories + names:
                path = Path(parent) / name
                relative = path.relative_to(project).as_posix()
                if relative.startswith('bootstrap/cache/') or SECRET.search(relative):
                    continue
                if path.is_symlink():
                    raise InspectionError('LIVE_SOURCE_LINK')
            if Path(parent) == project / 'bootstrap':
                directories[:] = [name for name in directories if name != 'cache']
            for name in names:
                read(Path(parent) / name)
    if (project / 'artisan').exists() or (project / 'artisan').is_symlink():
        read(project / 'artisan')
    if not files:
        raise InspectionError('LIVE_SOURCE_EMPTY')
    return files

def schema_metadata(project, inspector, php):
    # Existing pinned inspector reads information_schema and route actions only.
    report = json.loads(command([php, inspector, str(project)]))
    tables = report.get('tables', {})
    selected = {name: table for name, table in tables.items()
                if name.startswith('desktop_dashboard_') or name.startswith('desktop_pos_')}
    routes = [row for row in report.get('routes', [])
              if 'desktop-dashboard' in row.get('uri', '') or 'desktop-pos' in row.get('uri', '')]
    keys = ('php_version', 'laravel_version', 'database_engine', 'extensions')
    return {**{key: report.get(key) for key in keys}, 'table_count': len(tables),
            'desktop_tables': selected, 'desktop_routes': routes,
            'desktop_migrations': report.get('desktop_migrations'),
            'desktop_dashboard_flags': report.get('desktop_dashboard_flags')}

def main():
    args = argparse.ArgumentParser(description=__doc__)
    args.add_argument('--project', required=True)
    args.add_argument('--schema-inspector', required=True)
    args.add_argument('--php', required=True)
    options = args.parse_args()
    project = Path(options.project).absolute()
    if project.resolve() != project or not (project / '.git').is_dir() or (project / '.git').is_symlink():
        raise InspectionError('PROJECT_PATH_INVALID')
    preview = preview_source(project)
    before = live_source(project)
    metadata = schema_metadata(project, options.schema_inspector, options.php)
    after = live_source(project)
    if before != after:
        raise InspectionError('LIVE_SOURCE_CHANGED_DURING_INSPECTION')
    differences = []
    for name in sorted(preview.keys() | after.keys()):
        live, target = after.get(name), preview.get(name)
        if live is None or target is None or live['sha256'] != target['sha256']:
            differences.append([name, live['blob'] if live else None, target['blob'] if target else None])
    result = {'kind': 'desktop-server-read-only-preflight', 'preview_revision': PREVIEW,
              'git_head': git(project, 'rev-parse', 'HEAD').decode().strip(),
              'live_source': {**digest(after), 'framework': metadata['laravel_version']},
              'preview_source': {**digest(preview), 'framework': '8.83.29'},
              'difference_columns': ['path', 'live_git_blob', 'preview_git_blob'],
              'difference_count': len(differences), 'differences': differences,
              'metadata': metadata, 'deployment_performed': False}
    print(json.dumps(result, ensure_ascii=True, separators=(',', ':')))

if __name__ == '__main__':
    try:
        main()
    except InspectionError as error:
        print('DESKTOP_PREFLIGHT_STOPPED; REASON=' + str(error), file=sys.stderr); sys.exit(1)
    except BaseException as error:
        # Do not print database/config/exception contents.
        print('DESKTOP_PREFLIGHT_STOPPED; CLASS=' + type(error).__name__, file=sys.stderr); sys.exit(1)
