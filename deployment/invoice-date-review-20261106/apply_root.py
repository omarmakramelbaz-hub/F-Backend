#!/usr/bin/env python3
"""Patch invoice history controls in place, preserving all unrelated live changes."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone

FEATURE = 'invoice-date-review-20261106'
PATHS = {
    'public/dashboard/js/takeaway-pos.js',
    'public/dashboard/css/takeaway-pos.css',
    'resources/lang/ar/takeaway.php',
    'resources/lang/en/takeaway.php',
}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def destination(project, relative):
    if relative not in PATHS:
        raise RuntimeError('INVOICE_HISTORY_UNREVIEWED_PATH')
    path = project / relative
    if path.resolve() != path or not path.is_file() or any(p.is_symlink() for p in [path, *path.parents]):
        raise RuntimeError('INVOICE_HISTORY_INVALID_PATH: ' + relative)
    return path


def compatibility(project):
    def source(relative):
        path = project / relative
        if path.resolve() != path or not path.is_file() or any(p.is_symlink() for p in [path, *path.parents]):
            raise RuntimeError('INVOICE_HISTORY_DATE_API_REQUIRED')
        return path.read_text()
    controller = source('app/Http/Controllers/Dashboard/TakeawayController.php')
    service = source('app/Services/Dashboard/TakeawayService.php')
    view = source('resources/views/admin/takeaway/index.blade.php')
    def receipts(text):
        match = re.search(r'public\s+function\s+receipts\s*\(', text)
        if not match:
            raise RuntimeError('INVOICE_HISTORY_DATE_API_REQUIRED')
        return re.split(r'\n\s*(?:public|private|protected)\s+function\s+', text[match.end():], maxsplit=1)[0]
    action = receipts(controller)
    listing = receipts(service)
    if ('date_format:Y-m-d' not in action or not re.search(r"\$values\s*\[\s*['\"]date['\"]\s*\]", listing)
            or not re.search(r"where\(\s*['\"]business_date['\"]\s*,\s*\$date\s*\)", listing)
            or any(not re.search(r"filemtime\(\s*public_path\(\s*['\"]dashboard/" + asset + r"['\"]\s*\)\s*\)", view)
                   for asset in ['js/takeaway-pos.js', 'css/takeaway-pos.css'])):
        raise RuntimeError('INVOICE_HISTORY_DATE_API_REQUIRED')


def patch_text(current, item):
    if 'append' in item:
        addition = item['append']
        marker = '/* invoice-date-review-20261106 */'
        if item['path'] != 'public/dashboard/css/takeaway-pos.css' or not isinstance(addition, str) or marker not in addition or not addition.strip():
            raise RuntimeError('INVOICE_HISTORY_INVALID_APPEND')
        if current.count(addition) == 1 and current.count(marker) == 1:
            return current
        if marker in current:
            raise RuntimeError('INVOICE_HISTORY_CSS_MARKER_CHANGED')
        return current + addition
    old, new = item.get('old'), item.get('new')
    if not isinstance(old, str) or not isinstance(new, str) or not old or not new or old == new:
        raise RuntimeError('INVOICE_HISTORY_INVALID_REPLACEMENT')
    if current.count(new) == 1 and current.replace(new, '', 1).count(old) == 0:
        return current
    if current.count(old) != 1 or current.count(new) != 0:
        raise RuntimeError('INVOICE_HISTORY_SOURCE_CHANGED: ' + item['path'])
    return current.replace(old, new, 1)


def plan(project, spec):
    files = spec.get('files', [])
    if spec.get('format') != 1 or spec.get('feature') != FEATURE or len(files) != len(PATHS) or {x.get('path') for x in files} != PATHS:
        raise RuntimeError('INVOICE_HISTORY_PATCH_SPEC_CHANGED')
    changes = []
    for item in files:
        path = destination(project, item['path'])
        before = path.read_bytes()
        after = patch_text(before.decode('utf-8'), item).encode('utf-8')
        changes.append({'path': item['path'], 'before': before, 'after': after, 'meta': path.stat()})
    return changes


def atomic_write(path, content, info):
    fd, temporary = tempfile.mkstemp(prefix='.invoice-history-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            # Asset URLs use filemtime() in seconds, so force a distinct version.
            modified = max(time.time_ns(), (info.st_mtime_ns // 1_000_000_000 + 1) * 1_000_000_000)
            os.utime(stream.fileno(), ns=(modified, modified))
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def run(command, project, phase):
    try:
        result = subprocess.run(command, cwd=project, capture_output=True, text=True, timeout=45)
    except subprocess.TimeoutExpired as error:
        raise RuntimeError('INVOICE_HISTORY_CHECK_TIMEOUT: ' + phase) from error
    if result.returncode:
        # Never disclose live application configuration or subprocess output.
        raise RuntimeError('INVOICE_HISTORY_CHECK_FAILED: ' + phase)


def stage_candidate(stage, changes):
    candidate = stage / 'candidate'
    candidate.mkdir(mode=0o755)
    candidate.chmod(0o755)
    for item in changes:
        path = candidate / item['path']
        parent = candidate
        for part in Path(item['path']).parts[:-1]:
            parent /= part
            parent.mkdir(mode=0o755, exist_ok=True)
            parent.chmod(0o755)
        path.write_bytes(item['after'])
        path.chmod(0o444)
    return candidate


def syntax_check(project, candidate, php, node):
    prefix = ['runuser', '-u', 'fasakha', '--']
    run(prefix + [node, '--check', str(candidate / 'public/dashboard/js/takeaway-pos.js')], project, 'javascript-syntax')
    for locale in ['ar', 'en']:
        run(prefix + [php, '-l', str(candidate / ('resources/lang/' + locale + '/takeaway.php'))], project, locale + '-translation-syntax')


def apply_plan(project, changes, backup, verify):
    changed = [item for item in changes if item['before'] != item['after']]
    if not changed:
        verify()
        return 0
    backup.mkdir(mode=0o700)
    records = []
    for item in changed:
        path = backup / item['path']
        path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        path.write_bytes(item['before'])
        path.chmod(0o600)
        info = item['meta']
        records.append({'path': item['path'], 'before_sha256': digest(item['before']), 'after_sha256': digest(item['after']),
                        'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode)})
    (backup / 'invoice-history.json').write_text(json.dumps(records, indent=2) + '\n')
    applied = []
    try:
        for item in changed:
            path = destination(project, item['path'])
            if path.read_bytes() != item['before']:
                raise RuntimeError('INVOICE_HISTORY_CONCURRENT_SOURCE_CHANGE')
            atomic_write(path, item['after'], item['meta'])
            applied.append(item)
        verify()
    except BaseException:
        conflict = False
        for item in reversed(applied):
            path = destination(project, item['path'])
            if path.read_bytes() != item['after']:
                conflict = True
                continue
            atomic_write(path, item['before'], item['meta'])
        if conflict:
            raise RuntimeError('INVOICE_HISTORY_ROLLBACK_CONFLICT')
        raise
    return len(changed)


def main():
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('INVOICE_HISTORY_SERVER_REQUIRED')
    if len(sys.argv) != 3 or sys.argv[2] not in ['--apply', '--check']:
        raise RuntimeError('INVOICE_HISTORY_ARGUMENTS_REQUIRED')
    project = Path('/home/fasakha/public_html')
    account = pwd.getpwnam('fasakha')
    if project.resolve() != project or not (project / 'artisan').is_file() or project.stat().st_uid != account.pw_uid:
        raise RuntimeError('INVOICE_HISTORY_APPLICATION_REQUIRED')
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    if not os.access(php, os.X_OK):
        php = shutil.which('php')
    node = shutil.which('node') or shutil.which('nodejs')
    if not php or not node:
        raise RuntimeError('INVOICE_HISTORY_PHP_AND_NODE_REQUIRED')
    spec_path = Path(sys.argv[1]).resolve()
    stage = spec_path.parent
    if any(p.is_symlink() for p in [spec_path, *spec_path.parents]) or not spec_path.is_file():
        raise RuntimeError('INVOICE_HISTORY_SPEC_PATH_CHANGED')
    spec = json.loads(spec_path.read_text())
    with open('/var/lock/fasakhansta-invoice-history.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        compatibility(project)
        changes = plan(project, spec)
        candidate = stage_candidate(stage, changes)
        syntax_check(project, candidate, php, node)
        if sys.argv[2] == '--check':
            print(json.dumps({'status': 'INVOICE_DATE_REVIEW_READY', 'changed_files': sum(x['before'] != x['after'] for x in changes), 'order_data_changed': False}))
            return
        backup_root = Path('/home/fasakha/go-invoice-history-backups')
        if backup_root.resolve() != backup_root or backup_root.is_symlink():
            raise RuntimeError('INVOICE_HISTORY_BACKUP_PATH_CHANGED')
        backup_root.mkdir(mode=0o700, exist_ok=True)
        info = backup_root.stat()
        if not backup_root.is_dir() or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
            raise RuntimeError('INVOICE_HISTORY_PRIVATE_BACKUP_REQUIRED')
        backup = backup_root / (datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S') + '-' + os.urandom(4).hex())
        def verify():
            syntax_check(project, project, php, node)
            for item in changes:
                if destination(project, item['path']).read_bytes() != item['after']:
                    raise RuntimeError('INVOICE_HISTORY_INSTALLED_HASH_CHANGED')
        count = apply_plan(project, changes, backup, verify)
        print(json.dumps({'status': 'INVOICE_DATE_REVIEW_APPLIED_AND_VERIFIED', 'changed_files': count, 'backup': str(backup) if count else None, 'order_data_changed': False}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if message.startswith('INVOICE_HISTORY_') else 'INVOICE_HISTORY_RELEASE_FAILED', file=sys.stderr)
        sys.exit(1)
