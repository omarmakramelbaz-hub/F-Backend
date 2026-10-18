#!/usr/bin/env python3
"""Guarded live fragments for post collapse and verified unanswered comment counts."""
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
CSS = 'public/css/dashboard-meta-comments-pending.css'
SERVICE = 'app/Services/Dashboard/MetaCommentsService.php'
CONTROLLER = 'app/Http/Controllers/Dashboard/MetaCommentsController.php'
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


JS_EDITS = [(b'timelineVersion === 2', b'timelineVersion === 3'), (b'timelineVersion:2', b'timelineVersion:3'), (b"if (post && identity(post) === key) { host.classList.add('has-post'); poll(); return; }", b'if (post && identity(post) === key) { resetPost(); renderPosts(); poll(); return; }'), (b"            if (!timeline.needsRender(posts,post && identity(post),account && account.channel)) {\n                fields['post-count'].textContent", b"            if (!timeline.needsRender(posts,post && identity(post),account && account.channel)) {\n                pendingPaint();\n                fields['post-count'].textContent"), (b'            timeline.afterPosts(post && identity(post),post && post.permalink);', b'            timeline.afterPosts(post && identity(post),post && post.permalink);\n            pendingPaint();'), (b"                if (state === 'ACCEPTED') {\n                    drafts.delete(key);", b"                if (state === 'ACCEPTED') {\n                    pendingAccepted(key);\n                    drafts.delete(key);"), (b'timeline.destroy(); closed = true;', b'timeline.destroy(); clearTimeout(pendingTimer); pendingTimer = null; closed = true;'), (b"            alert(''); postSnapshots.clear(); commentSnapshots.clear();", b"            alert(''); postSnapshots.clear(); commentSnapshots.clear(); pendingStates.clear(); pendingPaint();")]
CONTROLLER_EDITS = [(b"            $this->only($request, ['post_handle', 'cursor']);", b"            $this->only($request, ['post_handle', 'cursor', 'pending']);"), (b"            $input = $request->validate(['post_handle' => 'required|string|max:4096', 'cursor' => 'nullable|string|max:6000']);", b"            $input = $request->validate(['post_handle' => 'required|string|max:4096', 'cursor' => 'nullable|string|max:6000', 'pending' => 'nullable|in:1']);"), (b"            return $service->comments(auth('admin')->user(), $input['post_handle'], $input['cursor'] ?? null);", b"            if (($input['pending'] ?? null) !== null) return $service->pendingPage(auth('admin')->user(), $input['post_handle'], $input['cursor'] ?? null, false);\n            return $service->comments(auth('admin')->user(), $input['post_handle'], $input['cursor'] ?? null);"), (b"            $this->only($request, ['comment_handle', 'cursor']);", b"            $this->only($request, ['comment_handle', 'cursor', 'pending']);"), (b"            $input = $request->validate(['comment_handle' => 'required|string|max:4096', 'cursor' => 'nullable|string|max:6000']);", b"            $input = $request->validate(['comment_handle' => 'required|string|max:4096', 'cursor' => 'nullable|string|max:6000', 'pending' => 'nullable|in:1']);"), (b"            return $service->replies(auth('admin')->user(), $input['comment_handle'], $input['cursor'] ?? null);", b"            if (($input['pending'] ?? null) !== null) return $service->pendingPage(auth('admin')->user(), $input['comment_handle'], $input['cursor'] ?? null, true);\n            return $service->replies(auth('admin')->user(), $input['comment_handle'], $input['cursor'] ?? null);")]

def fragment(data, start, end):
    if data.count(start) != 1 or data.count(end) != 1:
        fail('RELEASE_FRAGMENT_DRIFT')
    a, b = data.index(start), data.index(end) + len(end)
    if a >= b:
        fail('FRAGMENT_BOUNDARY_DRIFT')
    return data[a:b]


def surgical(data, block, anchor, edits, marker):
    if marker in data:
        plain = replace_once(data, block, b'')
        for before, after in reversed(edits):
            plain = replace_once(plain, after, before)
        if surgical(plain, block, anchor, edits, marker) != data:
            fail('EXISTING_CHANGE_DRIFT')
        return data
    changed = replace_once(data, anchor, block + anchor)
    for before, after in edits:
        changed = replace_once(changed, before, after)
    plain = changed
    for before, after in reversed(edits):
        plain = replace_once(plain, after, before)
    if replace_once(plain, block, b'') != data:
        fail('LIVE_PRESERVATION_FAILED')
    return changed


def controller(data):
    done = b"'pending' => 'nullable|in:1'" in data
    result = data
    for before, after in (reversed(CONTROLLER_EDITS) if done else CONTROLLER_EDITS):
        result = replace_once(result, after if done else before, before if done else after)
    if done:
        if controller(result) != data:
            fail('CONTROLLER_CHANGE_DRIFT')
        return data
    plain = result
    for before, after in reversed(CONTROLLER_EDITS):
        plain = replace_once(plain, after, before)
    if plain != data:
        fail('CONTROLLER_PRESERVATION_FAILED')
    return result


def style_link(reference):
    lines = [line for line in reference.splitlines(keepends=True) if b'<link ' in line and b"asset('css/dashboard-meta-comments-pending.css')" in line]
    if len(lines) != 1 or not lines[0].endswith(b'\n'):
        fail('ASSET_REFERENCE_DRIFT')
    return lines[0]


def view(data, link):
    if b'dashboard-meta-comments-pending.css' in data:
        original = replace_once(data, link, b'')
        if view(original, link) != data:
            fail('VIEW_CHANGE_DRIFT')
        return data
    anchors = [line for line in data.splitlines(keepends=True) if b'<link ' in line and b"asset('css/dashboard-meta-comments-original.css')" in line]
    if len(anchors) != 1 or data.count(b"@push('custom-css')") != 1:
        fail('VIEW_ANCHOR_DRIFT')
    start = data.index(b"@push('custom-css')"); end = data.find(b'@endpush',start); position = data.index(anchors[0])
    if not start < position < end or b'<link ' in data[position + len(anchors[0]):end]:
        fail('VIEW_STACK_DRIFT')
    return replace_once(data,anchors[0],anchors[0] + link)


def apply(root, php, reference, javascript, css, service, controller_source, hashes):
    root = Path(root).resolve()
    sources = [read(Path(p)) for p in [css,service,controller_source,javascript,reference]]
    if [sha(data) for data in sources] != hashes:
        fail('RELEASE_SOURCE_REJECTED')
    sheet, service_source, controller_reference, script, template = sources
    service_block = fragment(service_source,b'    // BEGIN META_PENDING_COMMENT_COUNTS\n',b'    // END META_PENDING_COMMENT_COUNTS\n\n')
    js_block = fragment(script,b'        // BEGIN META_PENDING_BADGES\n',b'        // END META_PENDING_BADGES\n')
    link = style_link(template)
    targets = [root / name for name in [CSS,SERVICE,CONTROLLER,JS,VIEW]]
    if any(p.is_symlink() for target in targets for p in [target,*target.parents]):
        fail('TARGET_PATH_REJECTED')
    originals = [read(target, index == 0) for index,target in enumerate(targets)]
    if originals[0] is not None and originals[0] != sheet:
        fail('EXISTING_ASSET_DRIFT')
    if b'private function automationIdentity(' not in originals[1]:
        fail('LIVE_IDENTITY_HELPER_REQUIRED')
    desired = [sheet,
        surgical(originals[1],service_block,b'    private function commentList(',[],b'BEGIN META_PENDING_COMMENT_COUNTS'),
        controller(originals[2]),
        surgical(originals[3],js_block,b"        var emojiCategory = 'reactions', caret = {start:0,end:0};",JS_EDITS,b'BEGIN META_PENDING_BADGES'),
        view(originals[4],link)]
    compile_check(root,php,desired[4])
    for index in [1,2]:
        command([php,'-l'],input=desired[index])
    node = shutil.which('node')
    if node:
        command([node,'--check'],input=desired[3])
    plan = [(path,old,new,path.stat() if old is not None else None) for path,old,new in zip(targets,originals,desired) if old != new]
    if not plan:
        command([php,'artisan','view:clear'],cwd=root)
        return {'status':'ALREADY_APPLIED','files':0}
    backup_root = root.parent / 'social-comments-pending-backups'
    if backup_root.is_symlink():
        fail('BACKUP_PATH_REJECTED')
    backup_root.mkdir(mode=0o700,exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='release-',dir=backup_root))
    for path,old,new,metadata in plan:
        if old is not None:
            saved = backup / path.name; saved.write_bytes(old); saved.chmod(0o600)
        if read(path,True) != old:
            fail('PREWRITE_SOURCE_DRIFT')
    applied=[]
    try:
        for path,old,new,metadata in plan:
            if read(path,True) != old:
                fail('PREWRITE_SOURCE_DRIFT')
            atomic_write(path,new,metadata); applied.append((path,old,new,metadata))
        if any(read(path) != new for path,new in zip(targets,desired)):
            fail('POSTWRITE_SOURCE_DRIFT')
        command([php,'artisan','view:clear'],cwd=root)
        if any(read(path) != new for path,new in zip(targets,desired)):
            fail('POSTCLEAR_SOURCE_DRIFT')
    except Exception:
        drift=False
        for path,old,new,metadata in reversed(applied):
            if read(path,True) != new:
                drift=True
            elif old is None:
                path.unlink()
            else:
                atomic_write(path,old,metadata)
        fail('VERIFY_FAILED_CONCURRENT_DRIFT' if drift else 'VERIFY_FAILED_ROLLED_BACK')
    return {'status':'APPLIED','files':len(plan),'backup':str(backup),'live_logic_preserved':True,'database_changes':0,'feature':'POST_TOGGLE_AND_UNANSWERED_BADGES'}


if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    for name in ['root','php','reference','javascript','css','service','controller','hashes']:
        parser.add_argument('--'+name,required=True)
    args=parser.parse_args()
    try:
        result=apply(args.root,args.php,args.reference,args.javascript,args.css,args.service,args.controller,json.loads(args.hashes))
    except Refused as error:
        result={'status':'REFUSED','category':str(error)}
    except Exception:
        result={'status':'REFUSED','category':'INSTALL_UNAVAILABLE'}
    print(json.dumps(result,sort_keys=True))
    raise SystemExit(0 if result['status'] in ['APPLIED','ALREADY_APPLIED'] else 1)
