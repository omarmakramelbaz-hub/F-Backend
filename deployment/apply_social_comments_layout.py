#!/usr/bin/env python3
"""Add the comments layout stylesheet without replacing the live comments view."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile

VIEW = 'resources/views/admin/meta/comments.blade.php'
CSS = 'public/css/dashboard-meta-comments-expanded.css'
ASSET = b"asset('css/dashboard-meta-comments-expanded.css')"
ANCHOR = b"asset('css/dashboard-meta-comment-ai.css')"


class Refused(Exception):
    pass


def fail(category):
    raise Refused(category)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def read(path, optional=False):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        fail('FILE_TYPE_REJECTED')
    return path.read_bytes() if path.exists() else (None if optional else fail('FILE_MISSING'))


def asset_line(reference):
    links = [line for line in reference.splitlines(keepends=True) if b'<link ' in line and ASSET in line]
    if len(links) != 1 or not links[0].endswith(b'\n') or reference.count(ASSET) != 1:
        fail('REFERENCE_ASSET_REJECTED')
    return links[0]


def transform(original, link):
    marker = b'dashboard-meta-comments-expanded.css'
    if marker in original:
        if original.count(link) != 1 or original.count(ASSET) != 1:
            fail('EXISTING_LAYOUT_DRIFT')
        stripped = original.replace(link, b'', 1)
        if marker in stripped or transform(stripped, link) != original:
            fail('EXISTING_LAYOUT_DRIFT')
        return original
    anchors = [line for line in original.splitlines(keepends=True) if b'<link ' in line and ANCHOR in line]
    if len(anchors) != 1 or not anchors[0].endswith(b'\n') or original.count(ANCHOR) != 1 \
            or original.count(b"@push('custom-css')") != 1:
        fail('CSS_ANCHOR_REJECTED')
    start = original.index(b"@push('custom-css')")
    end, anchor = original.find(b'@endpush', start), original.index(anchors[0])
    if not start < anchor < end or b'<link ' in original[anchor + len(anchors[0]):end]:
        fail('CSS_STACK_REJECTED')
    updated = original[:anchor + len(anchors[0])] + link + original[anchor + len(anchors[0]):]
    if updated.replace(link, b'', 1) != original:
        fail('REVERSIBILITY_REJECTED')
    return updated


def command(args, cwd=None, input=None):
    result = subprocess.run(args, cwd=cwd, input=input, capture_output=True, timeout=30, check=False)
    if result.returncode:
        fail('PHP_CHECK_FAILED')
    return result.stdout


def compile_check(root, php, view):
    code = 'require $argv[1]; $c=new Illuminate\\View\\Compilers\\BladeCompiler(new Illuminate\\Filesystem\\Filesystem(),$argv[3]); echo $c->compileString(file_get_contents($argv[2]));'
    with tempfile.TemporaryDirectory(prefix='social-comments-blade-') as directory:
        candidate = Path(directory) / 'candidate.blade.php'
        candidate.write_bytes(view)
        compiled = command([php, '-r', code, str(root / 'vendor/autoload.php'), str(candidate), directory])
        command([php, '-l'], input=compiled)


def atomic_write(path, data, metadata=None):
    fd, temporary = tempfile.mkstemp(prefix='.social-comments-layout-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            if metadata is not None:
                os.fchown(stream.fileno(), metadata.st_uid, metadata.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(metadata.st_mode) if metadata is not None else 0o644)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def apply(root, php, reference, css, reference_sha, css_sha):
    root = Path(root).resolve()
    reference, stylesheet = read(Path(reference)), read(Path(css))
    if sha(reference) != reference_sha or sha(stylesheet) != css_sha:
        fail('RELEASE_SOURCE_REJECTED')
    link = asset_line(reference)
    targets = [root / CSS, root / VIEW]
    if any(parent.is_symlink() for target in targets for parent in [target, *target.parents]):
        fail('TARGET_PATH_REJECTED')
    originals = [read(targets[0], True), read(targets[1])]
    if originals[0] is not None and originals[0] != stylesheet:
        fail('EXISTING_ASSET_DRIFT')
    desired = [stylesheet, transform(originals[1], link)]
    compile_check(root, php, desired[1])
    plan = [(path, old, new, path.stat() if old is not None else None)
            for path, old, new in zip(targets, originals, desired) if old != new]
    if not plan:
        command([php, 'artisan', 'view:clear'], cwd=root)
        return {'status': 'ALREADY_APPLIED', 'files': 0}
    backup_root = root.parent / 'social-comments-layout-backups'
    if backup_root.is_symlink():
        fail('BACKUP_PATH_REJECTED')
    backup_root.mkdir(mode=0o700, exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='release-', dir=backup_root))
    for path, old, new, metadata in plan:
        if old is not None:
            saved = backup / path.name
            saved.write_bytes(old)
            saved.chmod(0o600)
        if read(path, True) != old:
            fail('PREWRITE_SOURCE_DRIFT')
    applied = []
    try:
        for path, old, new, metadata in plan:
            if read(path, True) != old:
                fail('PREWRITE_SOURCE_DRIFT')
            atomic_write(path, new, metadata)
            applied.append((path, old, new, metadata))
        if any(read(path) != new for path, new in zip(targets, desired)):
            fail('POSTWRITE_SOURCE_DRIFT')
        if originals[1] != desired[1] and read(root / VIEW).replace(link, b'', 1) != originals[1]:
            fail('LIVE_VIEW_CHANGED')
        command([php, 'artisan', 'view:clear'], cwd=root)
        if any(read(path) != new for path, new in zip(targets, desired)):
            fail('POSTCLEAR_SOURCE_DRIFT')
    except Exception:
        drift = False
        for path, old, new, metadata in reversed(applied):
            if read(path, True) != new:
                drift = True
            elif old is None:
                path.unlink()
            else:
                atomic_write(path, old, metadata)
        fail('VERIFY_FAILED_CONCURRENT_DRIFT' if drift else 'VERIFY_FAILED_ROLLED_BACK')
    return {'status': 'APPLIED', 'files': len(plan), 'backup': str(backup), 'live_view_preserved': True}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    for name in ['root', 'php', 'reference', 'css', 'reference-sha', 'css-sha']:
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        result = apply(args.root, args.php, args.reference, args.css, args.reference_sha, args.css_sha)
    except Refused as error:
        result = {'status': 'REFUSED', 'category': str(error)}
    except Exception:
        result = {'status': 'REFUSED', 'category': 'INSTALL_UNAVAILABLE'}
    print(json.dumps(result, sort_keys=True))
    raise SystemExit(0 if result['status'] in ['APPLIED', 'ALREADY_APPLIED'] else 1)
