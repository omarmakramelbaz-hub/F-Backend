#!/usr/bin/env python3
"""Apply a small PHP method bundle without resetting the mixed live checkout."""
import argparse
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile


TARGETS = (
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Services/Dashboard/PhoneDeliveryBoard.php',
    'app/Http/Controllers/Api/V1/Auth/AddressController.php',
    'app/Http/Controllers/Api/V1/Auth/UserController.php',
    'app/Http/Traits/SendNotification.php',
)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def no_links(path):
    path = Path(path)
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    current = Path(path.anchor)
    for component in path.parts[1:]:
        current /= component
        require(not current.is_symlink(), 'SYMLINK_NOT_ALLOWED')


def read_state(path):
    no_links(path)
    info = path.stat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, 'REGULAR_PRIVATE_FILE_REQUIRED')
    require(info.st_uid == os.geteuid(), 'APPLICATION_FILE_OWNER_MISMATCH')
    require(info.st_size <= 2 * 1024 * 1024, 'SOURCE_TOO_LARGE')
    data = path.read_bytes()
    after = path.stat()
    require((info.st_ino, info.st_mtime_ns, info.st_size) ==
            (after.st_ino, after.st_mtime_ns, after.st_size), 'SOURCE_CHANGED_WHILE_READING')
    return {'data': data, 'sha256': digest(data), 'uid': info.st_uid, 'gid': info.st_gid,
            'mode': stat.S_IMODE(info.st_mode), 'inode': info.st_ino, 'mtime': info.st_mtime_ns}


def inspect(path, php, helper):
    result = subprocess.run([php, str(helper), str(path)], capture_output=True, timeout=60)
    require(result.returncode == 0, 'METHOD_INSPECTION_FAILED')
    try:
        return json.loads(result.stdout)['methods']
    except (ValueError, KeyError, TypeError):
        raise RuntimeError('METHOD_INSPECTION_INVALID') from None


def lint(path, php):
    result = subprocess.run([php, '-l', str(path)], capture_output=True, timeout=60)
    require(result.returncode == 0, 'PHP_SYNTAX_INVALID')


def build_plan(root, release_dir, php):
    helper = release_dir / 'deployment/dashboard_method_tokens.php'
    manifest_path = release_dir / 'deployment/dashboard_guarded_methods.json'
    no_links(helper)
    no_links(manifest_path)
    manifest = json.loads(manifest_path.read_bytes())
    require(manifest.get('version') == 1 and list(manifest.get('files', {})) == list(TARGETS),
            'MANIFEST_TARGETS_INVALID')
    require(digest(helper.read_bytes()) == manifest['tokenizer_sha256'], 'TOKENIZER_HASH_MISMATCH')
    plan = []
    with tempfile.TemporaryDirectory(prefix='dashboard-method-preflight-') as temporary:
        stage = Path(temporary)
        for relative, entry in manifest['files'].items():
            target = root / relative
            source = release_dir / relative
            no_links(source)
            source_data = source.read_bytes()
            require(digest(source_data) == entry['payload_sha256'], 'PAYLOAD_HASH_MISMATCH: ' + relative)
            original = read_state(target)
            current = inspect(target, php, helper)
            proposed = inspect(source, php, helper)
            replacements = []
            for name, rule in entry['methods'].items():
                require(name in current and name in proposed, 'METHOD_REQUIRED: ' + relative + '::' + name)
                require(proposed[name]['hash'] == rule['after'], 'RELEASE_METHOD_HASH_MISMATCH')
                added = rule.get('adds', {})
                for extra, expected in added.items():
                    require(extra in proposed and proposed[extra]['hash'] == expected,
                            'RELEASE_HELPER_HASH_MISMATCH')
                if current[name]['hash'] == rule['after']:
                    require(all(extra in current and current[extra]['hash'] == expected
                                for extra, expected in added.items()),
                            'INSTALLED_HELPER_CHANGED: ' + relative + '::' + name)
                    continue
                require(current[name]['hash'] in rule['before'],
                        'LIVE_METHOD_CHANGED: ' + relative + '::' + name)
                require(not any(extra in current for extra in added), 'LIVE_HELPER_COLLISION: ' + relative)
                end = max([proposed[name]['end']] + [proposed[extra]['end'] for extra in added])
                block = source_data[proposed[name]['start']:end]
                replacements.append((current[name]['start'], current[name]['end'], block))
            candidate = original['data']
            for start, end, block in sorted(replacements, reverse=True):
                candidate = candidate[:start] + block + candidate[end:]
            staged = stage / relative
            staged.parent.mkdir(parents=True, exist_ok=True)
            staged.write_bytes(candidate)
            actual = inspect(staged, php, helper)
            for name, rule in entry['methods'].items():
                require(actual[name]['hash'] == rule['after'], 'STAGED_METHOD_HASH_MISMATCH')
                require(all(actual.get(extra, {}).get('hash') == expected
                            for extra, expected in rule.get('adds', {}).items()),
                        'STAGED_HELPER_HASH_MISMATCH')
            lint(staged, php)
            if candidate != original['data']:
                plan.append({'path': relative, 'before': original, 'after': candidate})
    return plan


def publish(path, data, metadata):
    descriptor, name = tempfile.mkstemp(prefix='.dashboard-method-', dir=path.parent)
    temporary = Path(name)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), metadata['mode'])
            os.fchown(stream.fileno(), metadata['uid'], metadata['gid'])
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink()


def receipt_write(path, receipt):
    publish(path, (json.dumps(receipt, indent=2) + '\n').encode(),
            {'mode': 0o600, 'uid': os.geteuid(), 'gid': os.getegid()})


def install(root, release_dir, php, release, check_only=False):
    root = Path(root)
    release_dir = Path(release_dir)
    no_links(root)
    no_links(release_dir)
    require(root.is_dir() and root.resolve() == root, 'APPLICATION_ROOT_INVALID')
    require(re.fullmatch(r'[0-9a-f]{40}', release), 'FULL_RELEASE_REVISION_REQUIRED')
    plan = build_plan(root, release_dir, php)
    if check_only:
        print('DASHBOARD_METHODS_PREFLIGHT_OK files=' + str(len(plan)))
        return None
    if not plan:
        print('DASHBOARD_METHODS_OK already_current=1 files=0')
        return None

    private = root.parent / 'dashboard-guarded-method-backups'
    no_links(private)
    private.mkdir(mode=0o700, exist_ok=True)
    info = private.stat()
    require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700,
            'PRIVATE_BACKUP_DIRECTORY_REQUIRED')
    lock_path = private / 'install.lock'
    no_links(lock_path)
    descriptor = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        plan = build_plan(root, release_dir, php)
        if not plan:
            print('DASHBOARD_METHODS_OK already_current=1 files=0')
            return None
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
        backup = Path(tempfile.mkdtemp(prefix=stamp + release[:10] + '-', dir=private))
        receipt = {'release': release, 'status': 'prepared', 'files': []}
        for item in plan:
            before = item['before']
            require(read_state(root / item['path']) == before, 'LIVE_FILE_CHANGED_BEFORE_BACKUP')
            saved = backup / item['path']
            saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            saved.write_bytes(before['data'])
            saved.chmod(0o600)
            require(digest(saved.read_bytes()) == before['sha256'], 'BACKUP_HASH_MISMATCH')
            receipt['files'].append({'path': item['path'], 'before_sha256': before['sha256'],
                'after_sha256': digest(item['after']), 'mode': before['mode'],
                'uid': before['uid'], 'gid': before['gid']})
        receipt_path = backup / 'receipt.json'
        receipt_write(receipt_path, receipt)
        written = []
        try:
            for item in plan:
                path = root / item['path']
                require(read_state(path) == item['before'], 'LIVE_FILE_CHANGED_BEFORE_INSTALL')
                attempt = {'item': item, 'installed': None}
                written.append(attempt)
                publish(path, item['after'], item['before'])
                installed = read_state(path)
                require(installed['data'] == item['after'], 'LIVE_FILE_CHANGED_DURING_INSTALL')
                require(all(installed[key] == item['before'][key] for key in ('uid', 'gid', 'mode')),
                        'LIVE_METADATA_CHANGED_DURING_INSTALL')
                attempt['installed'] = installed
                lint(path, php)
            receipt['status'] = 'installed'
            receipt_write(receipt_path, receipt)
        except BaseException:
            review = []
            for attempt in reversed(written):
                item = attempt['item']
                installed = attempt['installed']
                path = root / item['path']
                try:
                    current = read_state(path)
                    metadata_matches = all(current[key] == item['before'][key] for key in ('uid', 'gid', 'mode'))
                    if current['data'] == item['before']['data'] and metadata_matches:
                        continue  # This publish failed before replacing the original.
                    if installed is not None:
                        require(current == installed, 'OUTSIDE_EDIT_REQUIRES_REVIEW')
                    else:
                        require(current['data'] == item['after'] and metadata_matches,
                                'UNCONFIRMED_PUBLISH_REQUIRES_REVIEW')
                    publish(path, item['before']['data'], item['before'])
                    require(read_state(path)['data'] == item['before']['data'], 'RESTORE_HASH_MISMATCH')
                except (RuntimeError, OSError):
                    review.append(item['path'])
            receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
            receipt['review'] = review
            receipt_write(receipt_path, receipt)
            print(('DASHBOARD_METHODS_ROLLBACK_REVIEW_REQUIRED' if review else
                   'DASHBOARD_METHODS_CODE_RESTORED') + '; BACKUP=' + str(backup))
            raise
        print('DASHBOARD_METHODS_OK files=' + str(len(plan)) + '; BACKUP=' + str(backup))
        return backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True)
    parser.add_argument('--release-dir', required=True)
    parser.add_argument('--php', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    install(Path(args.root), Path(args.release_dir), args.php, args.release, args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        message = str(error) if isinstance(error, RuntimeError) else 'INSTALLER_PREFLIGHT_FAILED'
        print('STOPPED: ' + message)
        raise SystemExit(1)
