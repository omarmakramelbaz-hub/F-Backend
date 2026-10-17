#!/usr/bin/env python3
"""Apply only the customer CRM feature, preserving unrelated live dashboard changes."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import tempfile


def run(args, cwd=None, timeout=120):
    return subprocess.run(args, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)


def require(value, message):
    if not value:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for parent in (path, *path.parents):
        require(not parent.is_symlink(), 'SYMLINK_REJECTED')


def digest(body):
    return hashlib.sha256(body).hexdigest()


def source(root, revision, path):
    result = run(['git', '-C', str(root), 'show', revision + ':' + path])
    require(result.returncode == 0 and len(result.stdout) <= 2097152, 'RELEASE_SOURCE_UNAVAILABLE: ' + path)
    return result.stdout


def atomic(path, body, mode=0o644):
    safe(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix='.crm-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(body)
            stream.flush()
            os.fchmod(stream.fileno(), mode)
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    args = parser.parse_args()
    require(re.fullmatch('[0-9a-f]{40}', args.revision), 'FULL_REVISION_REQUIRED')
    root = Path(args.root).resolve()
    require(root == Path(args.root) and root.is_dir(), 'APPLICATION_ROOT_INVALID')
    safe(root)
    require(os.geteuid() == pwd.getpwnam('fasakha').pw_uid, 'RUN_AS_FASAKHA_REQUIRED')
    parent = root.parent / 'customer-crm-release-backups'
    safe(parent)
    parent.mkdir(mode=0o700, exist_ok=True)
    require(stat.S_IMODE(parent.stat().st_mode) == 0o700, 'PRIVATE_BACKUP_REQUIRED')
    lock_path = parent / 'install.lock'
    safe(lock_path)
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        manifest = json.loads(source(root, args.revision, 'deployment/customer_crm_manifest.json'))
        require(manifest.get('version') == 1, 'MANIFEST_INVALID')
        with tempfile.TemporaryDirectory(prefix='customer-crm-', dir=parent) as directory:
            stage = Path(directory)
            changes = []
            conflicts = []
            for entry in manifest['files']:
                relative = entry['path']
                require(re.fullmatch(r'(?:app|config|database|public|resources|routes|desktop-pos)/[A-Za-z0-9_./-]+', relative)
                        and '..' not in Path(relative).parts, 'MANIFEST_PATH_INVALID')
                target = root / relative
                safe(target)
                before = target.read_bytes() if target.exists() else None
                require(before is None or target.is_file(), 'TARGET_NOT_REGULAR: ' + relative)
                release = source(root, args.revision, relative)
                require(digest(release) == entry['after'], 'RELEASE_HASH_MISMATCH: ' + relative)
                if before == release:
                    continue
                if before is None:
                    require(entry['before'] is None, 'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                    after = release
                elif digest(before) == entry['before']:
                    after = release
                else:
                    require(entry['before'] is not None, 'NEW_FILE_ALREADY_MODIFIED: ' + relative)
                    staged = stage / relative
                    staged.parent.mkdir(parents=True, exist_ok=True)
                    staged.write_bytes(before)
                    patch = stage / 'feature.patch'
                    selected = None
                    installed = False
                    # Alternate hunks describe the same CRM-only change against reviewed
                    # live lifecycle versions; every candidate still needs exact context.
                    candidates = [entry['patch'], *entry.get('compatible_patches', [])]
                    for candidate in candidates:
                        patch.write_text(candidate)
                        reverse = run(['git', 'apply', '--reverse', '--check', '--whitespace=nowarn', str(patch)], cwd=stage)
                        if reverse.returncode == 0:
                            installed = True
                            break
                        check = run(['git', 'apply', '--check', '--whitespace=nowarn', str(patch)], cwd=stage)
                        if check.returncode == 0:
                            selected = candidate
                            break
                    if installed:
                        continue
                    if selected is None:
                        conflicts.append(relative)
                        continue
                    patch.write_text(selected)
                    apply = run(['git', 'apply', '--whitespace=nowarn', str(patch)], cwd=stage)
                    require(apply.returncode == 0, 'PATCH_FAILED: ' + relative)
                    after = staged.read_bytes()
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                staged.write_bytes(after)
                if relative.endswith('.php') and not relative.endswith('.blade.php'):
                    check = run([args.php, '-l', str(staged)])
                    require(check.returncode == 0, 'PHP_SYNTAX_INVALID: ' + relative)
                changes.append((relative, target, before, after, stat.S_IMODE(target.stat().st_mode) if target.exists() else 0o644))
            require(not conflicts, 'LIVE_CHANGE_NEEDS_REVIEW: ' + ', '.join(conflicts))
            print('CRM_PREFLIGHT_OK files=' + str(len(changes)), flush=True)
            if args.check:
                return
            backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + args.revision[:10] + '-', dir=parent))
            receipt = []
            for relative, target, before, after, mode in changes:
                require((target.read_bytes() if target.exists() else None) == before, 'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
                if before is not None:
                    copy = backup / relative
                    copy.parent.mkdir(parents=True, exist_ok=True)
                    copy.write_bytes(before)
                receipt.append({'path': relative, 'existed': before is not None, 'before': digest(before) if before else None, 'after': digest(after)})
            (backup / 'receipt.json').write_text(json.dumps({'revision': args.revision, 'files': receipt}, indent=2))
            published = []
            try:
                for relative, target, before, after, mode in changes:
                    atomic(target, after, mode)
                    published.append((target, before, after, mode))
                commands = [['route:clear'], ['config:clear'], ['view:clear']]
                commands += [['migrate', '--force', '--path=' + path] for path in manifest['migrations']]
                commands += [['crm:customers-sync', '--bootstrap'], ['route:list', '--path=admin/customers']]
                with (backup / 'install.log').open('wb') as log:
                    for command in commands:
                        result = run([args.php, str(root / 'artisan'), *command], cwd=root, timeout=600)
                        log.write(result.stdout + result.stderr)
                        require(result.returncode == 0, 'APPLICATION_CHECK_FAILED: ' + command[0])
            except BaseException:
                for target, before, after, mode in reversed(published):
                    if target.exists() and target.read_bytes() == after:
                        if before is None:
                            target.unlink()
                        else:
                            atomic(target, before, mode)
                for command in ('route:clear', 'config:clear', 'view:clear'):
                    run([args.php, str(root / 'artisan'), command], cwd=root)
                print('CRM_CODE_RESTORED; database additions and imported customer data retained; BACKUP=' + str(backup), flush=True)
                raise
            print('CUSTOMER_CRM_INSTALLED; BACKUP=' + str(backup), flush=True)
            print('Check approved templates, messaging settings and customer consent in the customer list.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
