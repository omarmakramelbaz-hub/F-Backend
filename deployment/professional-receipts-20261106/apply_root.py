#!/usr/bin/env python3
"""Install only the approved receipt views and translations; never change order data."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

PATHS = {
    'resources/views/admin/takeaway/receipt.blade.php',
    'resources/views/admin/pos_service/kitchen.blade.php',
    'resources/views/admin/orders/board_detail.blade.php',
    'resources/views/printReceipt.blade.php',
    'resources/views/admin/printing/receipt.blade.php',
    'resources/views/admin/printing/receipt_context.blade.php',
    'resources/views/admin/printing/receipt_items.blade.php',
    'resources/views/admin/printing/receipt_styles.blade.php',
    'resources/views/admin/printing/order_receipt.blade.php',
    'resources/views/admin/printing/legacy_receipt.blade.php',
    'resources/lang/ar/receipt.php', 'resources/lang/en/receipt.php',
}


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def destination(project, relative):
    if relative not in PATHS:
        raise RuntimeError('RECEIPT_UNREVIEWED_PATH')
    target = project / relative
    if target.is_symlink() or any(parent.is_symlink() for parent in target.parents):
        raise RuntimeError('RECEIPT_SYMLINK_PATH')
    if target.resolve() != target or (target.exists() and not target.is_file()):
        raise RuntimeError('RECEIPT_INVALID_PATH')
    return target


def plan(project, manifest):
    files = manifest.get('files', [])
    if manifest.get('format') != 1 or manifest.get('feature') != 'professional-receipts-20261106' or len(files) != len(PATHS) or {item['path'] for item in files} != PATHS:
        raise RuntimeError('RECEIPT_MANIFEST_CHANGED')
    changes = []
    for item in files:
        path = destination(project, item['path'])
        current = path.read_bytes() if path.exists() else None
        after = item['content'].encode('utf-8')
        if digest(after) != item['after_sha256']:
            raise RuntimeError('RECEIPT_CANDIDATE_CHANGED')
        if digest(current) not in [item['before_sha256'], item['after_sha256']]:
            raise RuntimeError('RECEIPT_SOURCE_CHANGED: ' + item['path'])
        changes.append({'path': item['path'], 'before': current, 'after': after, 'meta': path.stat() if current is not None else None})
    return changes


def atomic_write(path, content, mode, uid, gid):
    fd, temporary = tempfile.mkstemp(prefix='.receipt-layout-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), uid, gid)
            os.fchmod(stream.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def apply_plan(project, changes, backup, owner, verify):
    changed = [item for item in changes if item['before'] != item['after']]
    if not changed:
        verify()
        return 0
    backup.mkdir(mode=0o700)
    records = []
    for item in changed:
        if item['before'] is not None:
            saved = backup / item['path']
            saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            saved.write_bytes(item['before'])
            saved.chmod(0o600)
        records.append({'path': item['path'], 'before_sha256': digest(item['before']), 'after_sha256': digest(item['after'])})
    (backup / 'receipt.json').write_text(json.dumps(records, indent=2) + '\n')
    applied = []
    try:
        for item in changed:
            path = destination(project, item['path'])
            current = path.read_bytes() if path.exists() else None
            if current != item['before']:
                raise RuntimeError('RECEIPT_CONCURRENT_SOURCE_CHANGE')
            if not path.parent.exists():
                # The only new directory in this release is the shared printing views.
                if path.parent != project / 'resources/views/admin/printing':
                    raise RuntimeError('RECEIPT_PARENT_MISSING')
                path.parent.mkdir(mode=0o755)
                os.chown(path.parent, *owner)
            info = item['meta']
            atomic_write(path, item['after'], stat.S_IMODE(info.st_mode) if info else 0o644,
                         info.st_uid if info else owner[0], info.st_gid if info else owner[1])
            applied.append(item)
        verify()
    except BaseException:
        conflict = False
        for item in reversed(applied):
            path = destination(project, item['path'])
            if not path.is_file() or path.read_bytes() != item['after']:
                conflict = True
                continue
            if item['before'] is None:
                path.unlink()
            else:
                info = item['meta']
                atomic_write(path, item['before'], stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
        if conflict:
            raise RuntimeError('RECEIPT_ROLLBACK_CONFLICT')
        raise
    return len(changed)


def run(command, cwd):
    result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError('RECEIPT_RUNTIME_VERIFICATION_FAILED')
    return result.stdout


def main():
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('RECEIPT_SERVER_REQUIRED')
    if len(sys.argv) != 3 or sys.argv[2] not in ['--apply', '--check']:
        raise RuntimeError('RECEIPT_ARGUMENTS_REQUIRED')
    project = Path('/home/fasakha/public_html')
    if project.resolve() != project or not (project/'artisan').is_file() or not (project/'vendor/autoload.php').is_file():
        raise RuntimeError('RECEIPT_APPLICATION_REQUIRED')
    account = pwd.getpwnam('fasakha')
    if project.stat().st_uid != account.pw_uid:
        raise RuntimeError('RECEIPT_APPLICATION_OWNER_CHANGED')
    owner = (account.pw_uid, account.pw_gid)
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    if not os.access(php, os.X_OK):
        php = shutil.which('php')
    if not php:
        raise RuntimeError('RECEIPT_PHP_REQUIRED')
    stage = Path(sys.argv[1]).resolve().parent
    manifest = json.loads(Path(sys.argv[1]).read_text())
    runner = stage/'render.php'
    if not runner.is_file() or not (stage/'fixtures.php').is_file():
        raise RuntimeError('RECEIPT_RENDER_FIXTURES_REQUIRED')
    with open('/var/lock/fasakhansta-receipt-layout.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        changes = plan(project, manifest)
        candidate = stage/'candidate'
        candidate.mkdir(mode=0o755)
        for item in changes:
            path = candidate/item['path']
            parent = candidate
            for part in Path(item['path']).parts[:-1]:
                parent = parent/part
                parent.mkdir(mode=0o755, exist_ok=True)
            path.write_bytes(item['after'])
            path.chmod(0o444)
        def render(label, overlay=''):
            output = stage/label
            output.mkdir(mode=0o700)
            os.chown(output, *owner)
            result = run(['runuser','-u','fasakha','--',php,str(runner),str(project),str(project/'vendor/autoload.php'),str(output),str(overlay)], project)
            proof = json.loads(result.splitlines()[-1])
            if proof.get('status') != 'PROFESSIONAL_RECEIPTS_RENDER_VERIFIED' or proof.get('fixtures', 0) < 17 or proof.get('locales') != 2:
                raise RuntimeError('RECEIPT_RENDER_PROOF_MISSING')
            return proof
        preflight = render('preflight', candidate)
        if sys.argv[2] == '--check':
            print(json.dumps({'status':'PROFESSIONAL_RECEIPTS_READY','fixtures':preflight['fixtures'],'changed_files':sum(item['before']!=item['after'] for item in changes)}))
            return
        backup_root = Path('/home/fasakha/go-receipt-layout-backups')
        if backup_root.resolve() != backup_root or backup_root.is_symlink():
            raise RuntimeError('RECEIPT_BACKUP_PATH_CHANGED')
        backup_root.mkdir(mode=0o700, exist_ok=True)
        backup = backup_root/(datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+os.urandom(4).hex())
        proof = {}
        def verify():
            run(['runuser','-u','fasakha','--',php,'artisan','view:clear'], project)
            proof.update(render('verified'))
            for item in changes:
                if destination(project,item['path']).read_bytes() != item['after']:
                    raise RuntimeError('RECEIPT_INSTALLED_HASH_CHANGED')
        try:
            count = apply_plan(project, changes, backup, owner, verify)
        except BaseException:
            subprocess.run(['runuser','-u','fasakha','--',php,'artisan','view:clear'], cwd=project, capture_output=True, timeout=60)
            raise
        print(json.dumps({'status':'PROFESSIONAL_RECEIPTS_APPLIED_AND_VERIFIED','changed_files':count,'fixtures':proof['fixtures'],'locales':proof['locales'],
                          'backup':str(backup) if count else None,'order_data_changed':False}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if message.startswith('RECEIPT_') else 'RECEIPT_RELEASE_FAILED', file=sys.stderr)
        sys.exit(1)
