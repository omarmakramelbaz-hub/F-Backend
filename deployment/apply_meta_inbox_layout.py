#!/usr/bin/env python3
"""Preserve the live AI panel while adding a reversible disclosure and style asset."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile

VIEW = 'resources/views/admin/meta/ai_controls.blade.php'
CSS = 'public/css/dashboard-meta-inbox-expanded.css'
BODY = b'        <div class="meta-ai-disclosure-body">\n'
END = b'        </div>\n    </details>\n'
MARKER = b'meta-ai-disclosure'


class Refused(Exception):
    pass


def sha(data):
    return hashlib.sha256(data).hexdigest()


def read(path, optional=False):
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise Refused('FILE_TYPE_REJECTED')
    return path.read_bytes() if path.exists() else (None if optional else fail('FILE_MISSING'))


def fail(category):
    raise Refused(category)


def section(data):
    found = list(re.finditer(rb'<section id="meta-ai-controls"[^>]* hidden>\n', data))
    if len(found) != 1 or data.count(b'</section>') != 1:
        fail('PANEL_BOUNDARY_REJECTED')
    start, end = found[0].end(), data.index(b'</section>')
    if start >= end:
        fail('PANEL_BOUNDARY_REJECTED')
    return start, end


def fragments(reference):
    start, end = section(reference)
    body = reference.find(BODY, start, end)
    if body < 0 or reference.count(BODY) != 1 or not reference[:end].endswith(END):
        fail('REFERENCE_WRAPPER_REJECTED')
    prefix = reference[start:body + len(BODY)]
    if not prefix.startswith(b'    <details class="meta-ai-disclosure">\n') or b' open' in prefix:
        fail('REFERENCE_DISCLOSURE_REJECTED')
    links = [line for line in reference.splitlines(keepends=True)
             if b'<link ' in line and b"asset('css/dashboard-meta-inbox-expanded.css')" in line]
    if len(links) != 1 or not links[0].endswith(b'\n'):
        fail('REFERENCE_ASSET_REJECTED')
    return prefix, links[0]


def plain(data, prefix, link):
    start, end = section(data)
    if not data[start:end].startswith(prefix) or not data[start:end].endswith(END) or data.count(link) != 1:
        fail('EXISTING_WRAPPER_DRIFT')
    return (data[:start] + data[start + len(prefix):end - len(END)] + data[end:]).replace(link, b'', 1)


def transform(data, prefix, link):
    if MARKER in data or b'dashboard-meta-inbox-expanded.css' in data:
        original = plain(data, prefix, link)
        if transform(original, prefix, link) != data:
            fail('EXISTING_LAYOUT_DRIFT')
        return data
    start, end = section(data)
    if not data[start:end].startswith(b'    <div class="meta-ai-controls-heading">\n'):
        fail('PANEL_START_REJECTED')
    links = [line for line in data.splitlines(keepends=True)
             if b'<link ' in line and b"asset('css/dashboard-meta-inbox-viewport.css')" in line]
    if len(links) != 1 or data.count(b"@push('custom-css')") != 1:
        fail('CSS_STACK_REJECTED')
    stack = data.index(b"@push('custom-css')")
    if not stack < data.index(links[0]) < data.find(b'@endpush', stack):
        fail('CSS_STACK_REJECTED')
    changed = (data[:start] + prefix + data[start:end] + END + data[end:]).replace(links[0], links[0] + link, 1)
    if plain(changed, prefix, link) != data:
        fail('REVERSIBILITY_REJECTED')
    return changed


def command(args, cwd=None, input=None):
    result = subprocess.run(args, cwd=cwd, input=input, capture_output=True, timeout=30, check=False)
    if result.returncode:
        fail('PHP_CHECK_FAILED')
    return result.stdout


def compile_check(root, php, view):
    code = 'require $argv[1]; $c=new Illuminate\\View\\Compilers\\BladeCompiler(new Illuminate\\Filesystem\\Filesystem(),$argv[3]); echo $c->compileString(file_get_contents($argv[2]));'
    with tempfile.TemporaryDirectory(prefix='meta-inbox-blade-') as directory:
        candidate = Path(directory) / 'candidate.blade.php'
        candidate.write_bytes(view)
        compiled = command([php, '-r', code, str(root / 'vendor/autoload.php'), str(candidate), directory])
        command([php, '-l'], input=compiled)


def atomic_write(path, data, metadata=None):
    fd, temporary = tempfile.mkstemp(prefix='.meta-inbox-layout-', dir=path.parent)
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
    prefix, link = fragments(reference)
    targets = [root / CSS, root / VIEW]
    if any(parent.is_symlink() for target in targets for parent in [target, *target.parents]):
        fail('TARGET_PATH_REJECTED')
    originals = [read(targets[0], True), read(targets[1])]
    if originals[0] is not None and originals[0] != stylesheet:
        fail('EXISTING_ASSET_DRIFT')
    desired = [stylesheet, transform(originals[1], prefix, link)]
    compile_check(root, php, desired[1])
    plan = [(path, old, new, path.stat() if old is not None else None)
            for path, old, new in zip(targets, originals, desired) if old != new]
    if not plan:
        command([php, 'artisan', 'view:clear'], cwd=root)
        return {'status': 'ALREADY_APPLIED', 'files': 0}
    backup_root = root.parent / 'meta-inbox-layout-backups'
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
        if plain(read(root / VIEW), prefix, link) != (plain(originals[1], prefix, link) if MARKER in originals[1] else originals[1]):
            fail('LIVE_BODY_CHANGED')
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
    return {'status': 'APPLIED', 'files': len(plan), 'backup': str(backup), 'live_panel_preserved': True}


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
