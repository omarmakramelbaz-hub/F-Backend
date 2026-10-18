#!/usr/bin/env python3
"""Preserve live comment logic while installing the original platform post previews."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

VIEW = 'resources/views/admin/meta/comments.blade.php'
JS = 'public/js/dashboard-meta-comments.js'
CSS = 'public/css/dashboard-meta-comments-original.css'
PREVIOUS_JS_BLOB = '67c3def10fd7cb77551bea30fdaa78bda8d90447'
START = b'    // BEGIN META_COMMENTS_TIMELINE\n'
END = b'    // END META_COMMENTS_TIMELINE\n'

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


def replace_once(data, before, after):
    if data.count(before) != 1:
        fail('LIVE_ANCHOR_DRIFT')
    return data.replace(before, after, 1)


def timeline_block(reference):
    if reference.count(START) != 1 or reference.count(END) != 1:
        fail('RELEASE_TIMELINE_BLOCK_REJECTED')
    a, b = reference.index(START), reference.index(END) + len(END)
    return reference[a:b]


def previous_source(root):
    data = command(['git','-C',str(root),'cat-file','blob',PREVIOUS_JS_BLOB])
    actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
    if actual != PREVIOUS_JS_BLOB:
        fail('PREVIOUS_RELEASE_SOURCE_REJECTED')
    return data


def edits():
    return [
        (b'timelineVersion === 1', b'timelineVersion === 2'),
        (b'timelineVersion:1', b'timelineVersion:2'),
        (b'        function renderPosts() {\n            timeline.beforePosts(post && identity(post));',
         b"        function renderPosts() {\n            if (!timeline.needsRender(posts,post && identity(post),account && account.channel)) {\n                fields['post-count'].textContent = String(posts.size);\n                fields['more-posts'].hidden = !postCursor; fields['more-posts'].disabled = busy.has('posts');\n                return;\n            }\n            timeline.beforePosts(post && identity(post));"),
        (b'timeline.afterPosts(post && identity(post));', b'timeline.afterPosts(post && identity(post),post && post.permalink);'),
    ]


def strip_js(data, block, previous):
    for before, after in reversed(edits()):
        data = replace_once(data, after, before)
    return replace_once(data, block, previous)


def transform_js(data, block, previous):
    observed = timeline_block(data)
    if observed == block:
        original = strip_js(data, block, previous)
        if transform_js(original, block, previous) != data:
            fail('EXISTING_PREVIEW_DRIFT')
        return data
    if observed != previous:
        fail('LIVE_TIMELINE_BLOCK_DRIFT')
    changed = replace_once(data, previous, block)
    for before, after in edits():
        changed = replace_once(changed, before, after)
    if strip_js(changed, block, previous) != data:
        fail('JS_PRESERVATION_FAILED')
    return changed


def stylesheet_link(reference):
    matches = [line for line in reference.splitlines(keepends=True)
               if b'<link ' in line and b"asset('css/dashboard-meta-comments-original.css')" in line]
    if len(matches) != 1 or not matches[0].endswith(b'\n'):
        fail('REFERENCE_ASSET_REJECTED')
    return matches[0]


def transform_view(data, link):
    if b'dashboard-meta-comments-original.css' in data:
        plain = replace_once(data, link, b'')
        if transform_view(plain, link) != data:
            fail('EXISTING_VIEW_DRIFT')
        return data
    anchors = [line for line in data.splitlines(keepends=True)
               if b'<link ' in line and b"asset('css/dashboard-meta-comments-timeline.css')" in line]
    if len(anchors) != 1 or data.count(b"@push('custom-css')") != 1:
        fail('LIVE_VIEW_CSS_ANCHOR_DRIFT')
    start = data.index(b"@push('custom-css')")
    end = data.find(b'@endpush', start)
    anchor = data.index(anchors[0])
    if not start < anchor < end or b'<link ' in data[anchor + len(anchors[0]):end]:
        fail('LIVE_VIEW_CSS_STACK_DRIFT')
    changed = replace_once(data, anchors[0], anchors[0] + link)
    if changed.replace(link, b'', 1) != data:
        fail('VIEW_PRESERVATION_FAILED')
    return changed


def apply(root, php, reference, javascript, css, reference_sha, javascript_sha, css_sha):
    root = Path(root).resolve()
    view, source_js, stylesheet = [read(Path(path)) for path in (reference, javascript, css)]
    if [sha(body) for body in (view, source_js, stylesheet)] != [reference_sha, javascript_sha, css_sha]:
        fail('RELEASE_SOURCE_REJECTED')
    block, link = timeline_block(source_js), stylesheet_link(view)
    previous = timeline_block(previous_source(root))
    targets = [root / CSS, root / JS, root / VIEW]
    if any(parent.is_symlink() for target in targets for parent in [target, *target.parents]):
        fail('TARGET_PATH_REJECTED')
    originals = [read(targets[0], True), read(targets[1]), read(targets[2])]
    if originals[0] is not None and originals[0] != stylesheet:
        fail('EXISTING_ASSET_DRIFT')
    desired = [stylesheet, transform_js(originals[1], block, previous), transform_view(originals[2], link)]
    compile_check(root, php, desired[2])
    node = shutil.which('node')
    if node:
        command([node, '--check'], input=desired[1])
    plan = [(path, old, new, path.stat() if old is not None else None)
            for path, old, new in zip(targets, originals, desired) if old != new]
    if not plan:
        command([php, 'artisan', 'view:clear'], cwd=root)
        return {'status':'ALREADY_APPLIED','files':0}
    backup_root = root.parent / 'social-comments-original-backups'
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
    return {'status':'APPLIED','files':len(plan),'backup':str(backup),
            'live_logic_preserved':True,'layout':'ORIGINAL_POST_EMBED'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    for name in ['root','php','reference','javascript','css','reference-sha','javascript-sha','css-sha']:
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        result = apply(args.root,args.php,args.reference,args.javascript,args.css,
                       args.reference_sha,args.javascript_sha,args.css_sha)
    except Refused as error:
        result = {'status':'REFUSED','category':str(error)}
    except Exception:
        result = {'status':'REFUSED','category':'INSTALL_UNAVAILABLE'}
    print(json.dumps(result, sort_keys=True))
    raise SystemExit(0 if result['status'] in ['APPLIED','ALREADY_APPLIED'] else 1)
