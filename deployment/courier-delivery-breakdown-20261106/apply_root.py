#!/usr/bin/env python3
"""Install a read-only courier receipt breakdown without changing sales or settlement data."""
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
from datetime import datetime, timezone

PATHS = {
    'app/Services/Dashboard/PhoneDeliveryBoard.php',
    'app/Services/Dashboard/PhoneDeliveryBatchReceipt.php',
    'resources/views/admin/phone_orders/batch_receipt.blade.php',
    'resources/lang/ar/phone_batch.php', 'resources/lang/en/phone_batch.php',
}


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def destination(project, relative):
    if relative not in PATHS:
        raise RuntimeError('COURIER_RECEIPT_UNREVIEWED_PATH')
    target = project / relative
    if target.is_symlink() or any(parent.is_symlink() for parent in target.parents):
        raise RuntimeError('COURIER_RECEIPT_SYMLINK_PATH')
    if target.resolve() != target or (target.exists() and not target.is_file()):
        raise RuntimeError('COURIER_RECEIPT_INVALID_PATH')
    return target


def plan(project, manifest):
    files = manifest.get('files', [])
    if manifest.get('format') != 1 or manifest.get('feature') != 'courier-delivery-breakdown-20261106' or len(files) != len(PATHS) or {item['path'] for item in files} != PATHS:
        raise RuntimeError('COURIER_RECEIPT_MANIFEST_CHANGED')
    changes = []
    for item in files:
        path = destination(project, item['path'])
        current = path.read_bytes() if path.exists() else None
        content = item['content'].encode('utf-8')
        if digest(content) != item['after_sha256']:
            raise RuntimeError('COURIER_RECEIPT_CANDIDATE_CHANGED')
        if item.get('mode') == 'patch':
            before = item['before'].encode('utf-8')
            if not before or digest(before) != item['before_sha256'] or current is None:
                raise RuntimeError('COURIER_RECEIPT_PATCH_CHANGED')
            if current.count(content) == 1 and current.count(before) == 0:
                after = current
            elif current.count(before) == 1 and current.count(content) == 0:
                after = current.replace(before, content, 1)
            else:
                raise RuntimeError('COURIER_RECEIPT_SOURCE_CHANGED: ' + item['path'])
        elif item.get('mode') == 'replace':
            if digest(current) not in [item['before_sha256'], item['after_sha256']]:
                raise RuntimeError('COURIER_RECEIPT_SOURCE_CHANGED: ' + item['path'])
            after = content
        else:
            raise RuntimeError('COURIER_RECEIPT_MANIFEST_CHANGED')
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
                raise RuntimeError('COURIER_RECEIPT_CONCURRENT_SOURCE_CHANGE')
            if not path.parent.is_dir():
                raise RuntimeError('COURIER_RECEIPT_PARENT_MISSING')
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
            raise RuntimeError('COURIER_RECEIPT_ROLLBACK_CONFLICT')
        raise
    return len(changed)


def stage_candidate(stage, changes):
    """Only reviewed source is readable across the root -> site account boundary."""
    candidate = stage/'candidate'
    candidate.mkdir(mode=0o755)
    # mkdir's requested mode is still filtered by the launcher's umask 077.
    candidate.chmod(0o755)
    for item in changes:
        path = candidate/item['path']
        parent = candidate
        for part in Path(item['path']).parts[:-1]:
            parent = parent/part
            parent.mkdir(mode=0o755, exist_ok=True)
            parent.chmod(0o755)
        path.write_bytes(item['after'])
        path.chmod(0o444)
    return candidate


def run(command, cwd, phase='runtime'):
    try:
        result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=60)
    except subprocess.TimeoutExpired as error:
        raise RuntimeError('COURIER_RECEIPT_RUNTIME_TIMEOUT: '+phase) from error
    if result.returncode:
        details = {}
        for line in (result.stdout+'\n'+result.stderr).splitlines():
            try:
                record = json.loads(line)
            except (TypeError, ValueError):
                continue
            if not isinstance(record, dict) or record.get('status') != 'COURIER_RECEIPT_RENDER_FAILED':
                continue
            for key in ['fixture', 'locale', 'exception', 'source', 'check']:
                value = record.get(key)
                if isinstance(value, str) and re.fullmatch(r'[A-Za-z0-9_ .:/\\-]{1,160}', value):
                    details[key] = value
        suffix = ': '+json.dumps(details, ensure_ascii=True) if details else ''
        raise RuntimeError('COURIER_RECEIPT_RUNTIME_VERIFICATION_FAILED: '+phase+suffix)
    return result.stdout


def main():
    os.umask(0o077)
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise RuntimeError('COURIER_RECEIPT_SERVER_REQUIRED')
    if len(sys.argv) != 3 or sys.argv[2] not in ['--apply', '--check']:
        raise RuntimeError('COURIER_RECEIPT_ARGUMENTS_REQUIRED')
    project = Path('/home/fasakha/public_html')
    if project.resolve() != project or not (project/'artisan').is_file() or not (project/'vendor/autoload.php').is_file():
        raise RuntimeError('COURIER_RECEIPT_APPLICATION_REQUIRED')
    account = pwd.getpwnam('fasakha')
    if project.stat().st_uid != account.pw_uid:
        raise RuntimeError('COURIER_RECEIPT_APPLICATION_OWNER_CHANGED')
    owner = (account.pw_uid, account.pw_gid)
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    if not os.access(php, os.X_OK):
        php = shutil.which('php')
    if not php:
        raise RuntimeError('COURIER_RECEIPT_PHP_REQUIRED')
    stage = Path(sys.argv[1]).resolve().parent
    manifest = json.loads(Path(sys.argv[1]).read_text())
    runner = stage/'render.php'
    if not runner.is_file():
        raise RuntimeError('COURIER_RECEIPT_RENDER_FIXTURES_REQUIRED')
    with open('/var/lock/fasakhansta-receipt-layout.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        changes = plan(project, manifest)
        candidate = stage_candidate(stage, changes)
        def render(label, overlay=''):
            output = stage/label
            output.mkdir(mode=0o700)
            os.chown(output, *owner)
            result = run(['runuser','-u','fasakha','--',php,str(runner),str(project),str(project/'vendor/autoload.php'),str(output),str(overlay)], project, phase=label)
            try:
                proof = json.loads(result.splitlines()[-1])
            except (ValueError, IndexError) as error:
                raise RuntimeError('COURIER_RECEIPT_RENDER_PROOF_MISSING: '+label) from error
            if proof.get('status') != 'COURIER_RECEIPT_RENDER_VERIFIED' or proof.get('fixtures', 0) < 7 or proof.get('locales') != 2:
                raise RuntimeError('COURIER_RECEIPT_RENDER_PROOF_MISSING: '+label)
            return proof
        for item in changes:
            run(['runuser','-u','fasakha','--',php,'-l',str(candidate/item['path'])], project, phase='syntax')
        preflight = render('preflight', candidate)
        if sys.argv[2] == '--check':
            print(json.dumps({'status':'COURIER_RECEIPT_READY','fixtures':preflight['fixtures'],'changed_files':sum(item['before']!=item['after'] for item in changes)}))
            return
        backup_root = Path('/home/fasakha/go-courier-receipt-backups')
        if backup_root.resolve() != backup_root or backup_root.is_symlink():
            raise RuntimeError('COURIER_RECEIPT_BACKUP_PATH_CHANGED')
        backup_root.mkdir(mode=0o700, exist_ok=True)
        backup = backup_root/(datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+os.urandom(4).hex())
        proof = {}
        def verify():
            run(['runuser','-u','fasakha','--',php,'artisan','view:clear'], project, phase='view-cache-clear')
            proof.update(render('verified'))
            for item in changes:
                if destination(project,item['path']).read_bytes() != item['after']:
                    raise RuntimeError('COURIER_RECEIPT_INSTALLED_HASH_CHANGED')
        try:
            count = apply_plan(project, changes, backup, owner, verify)
        except BaseException:
            subprocess.run(['runuser','-u','fasakha','--',php,'artisan','view:clear'], cwd=project, capture_output=True, timeout=60)
            raise
        print(json.dumps({'status':'COURIER_RECEIPT_APPLIED_AND_VERIFIED','changed_files':count,'fixtures':proof['fixtures'],'locales':proof['locales'],
                          'backup':str(backup) if count else None,'order_data_changed':False,'settlement_data_changed':False}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if message.startswith('COURIER_RECEIPT_') else 'COURIER_RECEIPT_RELEASE_FAILED', file=sys.stderr)
        sys.exit(1)
