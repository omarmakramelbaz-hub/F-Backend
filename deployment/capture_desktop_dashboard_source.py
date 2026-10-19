#!/usr/bin/env python3
"""Privately package source bytes unavailable in the reviewed repository, never business rows."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path('/home/fasakha/public_html')
LIMIT = 64 * 1024 * 1024


class CaptureError(Exception):
    pass


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def report_file(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 32 * 1024 * 1024:
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_INVALID')
    lines = path.read_bytes().splitlines()
    if (len(lines) != 3 or lines[0] != b'DESKTOP_DASHBOARD_UPDATES_INSPECTION_BEGIN'
            or lines[2] != b'DESKTOP_DASHBOARD_UPDATES_INSPECTION_END; DEPLOYMENT_PERFORMED=false'):
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_INVALID')
    value = json.loads(lines[1])
    source = value['source']
    if (any(value[key] is not False for key in ('deployment_performed', 'business_rows_exported',
                                               'application_files_changed'))
            or source['source_differences_truncated'] is not False
            or len(source['source_differences']) != source['source_difference_count']):
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_INCOMPLETE')
    return value


def known_blobs(value):
    if (not isinstance(value, dict) or value.get('format') != 1
            or not isinstance(value.get('known_blobs'), list)
            or not 1 <= len(value['known_blobs']) <= 20000
            or any(not isinstance(pin, str) or not re.fullmatch('[a-f0-9]{40}', pin)
                   for pin in value['known_blobs'])):
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_CATALOG_INVALID')
    return set(value['known_blobs'])


def read_relative(root_fd, name, diagnostic):
    path = Path(name)
    if path.is_absolute() or '..' in path.parts or not re.fullmatch('[A-Za-z0-9_.@/-]+', name):
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_PATH_INVALID')
    fd = os.dup(root_fd)
    try:
        for part in path.parts[:-1]:
            child = diagnostic.open_directory(fd, part)
            os.close(fd)
            fd = child
        return diagnostic.read_file(fd, path.name, 8 * 1024 * 1024)
    finally:
        os.close(fd)


def records(snapshot):
    result = {name: (pin, snapshot['metadata'][name]) for name, pin in snapshot['files'].items()}
    for name, value in snapshot['gui'].items():
        if value[0] is not None:
            result[name] = value
    result.update(snapshot.get('additional_dashboard_gui', {}))
    return result


def bundle(root, destination, report, available, diagnostic, source, head_reader):
    """Caller owns the private destination. Two complete snapshots bind all captured bytes."""
    gui = list(dict.fromkeys([row['path'] for row in report['source']['gui']]
                             + list(diagnostic.RELATED_GUI)))
    gui_pattern = re.compile(r'public/(?:js/dashboard-[A-Za-z0-9_.-]+\.js|css/dashboard-[A-Za-z0-9_.-]+\.css|dashboard/branding/[A-Za-z0-9_.-]+\.(?:css|svg|png|ico))\Z')
    if any(not gui_pattern.fullmatch(name) or source['SECRET'].search(name) for name in gui):
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_GUI_SCOPE_INVALID')
    before = diagnostic.snapshot(root, source, gui, [], head_reader)
    observed = report['source']
    if (before['head'] != observed['actual_head']
            or {**source['digest'](before['files']), 'framework': '8.83.29'} != observed['actual_source']):
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_SOURCE_CHANGED')
    for row in observed['source_differences']:
        if before['files'].get(row['path']) != row['actual']:
            raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_SOURCE_CHANGED')
    for row in observed['gui']:
        actual = before['gui'][row['path']][0]
        if (actual['blob'] if actual else None) != row['actual']:
            raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_GUI_CHANGED')
    extra = {name: value[0] for name, value in before['additional_dashboard_gui'].items()}
    if extra != {row['path']: row['actual'] for row in observed['additional_dashboard_gui']}:
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_REPORT_GUI_CHANGED')
    path = destination / 'desktop-source-for-pc.zip'
    total, copied, created = 0, [], False
    root_fd = diagnostic.open_root(root)
    try:
        archive = zipfile.ZipFile(path, 'x', zipfile.ZIP_DEFLATED, compresslevel=9)
        created = True
        with archive:
            path.chmod(0o600)
            for name, (pin, expected_metadata) in sorted(records(before).items()):
                if pin['blob'] in available:
                    continue
                data, metadata = read_relative(root_fd, name, diagnostic)
                total += len(data)
                if (total > LIMIT or source['record'](data) != pin
                        or diagnostic.identity(metadata) != expected_metadata):
                    raise CaptureError('DESKTOP_SOURCE_CAPTURE_CONTENT_CHANGED')
                archive.writestr('source/' + name, data)
                copied.append({'path': name, **pin, 'bytes': len(data)})
            after = diagnostic.snapshot(root, source, gui, [], head_reader)
            if before != after:
                raise CaptureError('DESKTOP_SOURCE_CAPTURE_SNAPSHOT_CHANGED')
            archive.writestr('inspection-report.json', json.dumps(report, separators=(',', ':')))
            archive.writestr('source-map.json', json.dumps(before['files'], sort_keys=True, separators=(',', ':')))
            archive.writestr('capture.json', json.dumps({
                'format': 1, 'kind': 'desktop-private-source-evidence', 'source': observed['actual_source'],
                'head': before['head'], 'files': copied, 'source_bytes': total,
                'business_rows_exported': False, 'application_files_changed': False,
                'environment_files_exported': False,
            }, separators=(',', ':')))
        return path, len(copied), hashlib.sha256(path.read_bytes()).hexdigest()
    except BaseException:
        # Only our newly created private archive can be removed. Existing files
        # and application sources never become cleanup targets.
        if created and path.is_file() and not path.is_symlink():
            path.unlink()
        raise
    finally:
        os.close(root_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', required=True)
    parser.add_argument('--catalog', required=True)
    options = parser.parse_args()
    account = pwd.getpwnam('fasakha')
    if os.geteuid() != account.pw_uid:
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_RUN_AS_FASAKHA')
    folder = Path(__file__).absolute().parent
    spec = importlib.util.spec_from_file_location('desktop_source_reader', folder/'diagnose_desktop_dashboard_current_source.py')
    diagnostic = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(diagnostic)
    source = {'__name__': 'desktop_capture_source_functions'}
    exec(compile((folder/'desktop_dashboard_server_preflight.py').read_bytes(), 'pinned-source-functions', 'exec'), source)
    report = report_file(Path(options.report))
    available = known_blobs(json.loads(Path(options.catalog).read_bytes()))
    home = Path('/home/fasakha')
    item = home.lstat()
    if home.resolve() != home or not stat.S_ISDIR(item.st_mode) or item.st_uid != account.pw_uid:
        raise CaptureError('DESKTOP_SOURCE_CAPTURE_HOME_UNSAFE')
    def head_reader():
        result = subprocess.run(['/usr/bin/git', '--no-optional-locks', '-C', str(ROOT), 'rev-parse', 'HEAD'],
                                env={**os.environ, 'GIT_NO_LAZY_FETCH': '1', 'GIT_NO_REPLACE_OBJECTS': '1'},
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True, timeout=15)
        value = result.stdout.decode('ascii').strip()
        if not re.fullmatch('[a-f0-9]{40}', value):
            raise CaptureError('DESKTOP_SOURCE_CAPTURE_HEAD_INVALID')
        return value
    os.umask(0o077)
    destination = Path(tempfile.mkdtemp(prefix='desktop-source-evidence-', dir=home))
    destination.chmod(0o700)
    path, count, digest = bundle(ROOT, destination, report, available, diagnostic, source, head_reader)
    print('DESKTOP_SOURCE_BUNDLE_READY')
    print('FILE=' + str(path))
    print('SOURCE_FILES=' + str(count))
    print('SHA256=' + digest)


if __name__ == '__main__':
    try:
        main()
    except BaseException:
        print('DESKTOP_SOURCE_CAPTURE_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
        sys.exit(1)
