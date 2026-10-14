#!/usr/bin/env python3
"""Surgical public-comment AI rollout; policy stays off until an Owner changes it."""
import argparse
import difflib
import fcntl
import hashlib
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
# Include the deployed AI UI when applying later comment-page upgrades. The
# variant patch still requires exact reviewed bytes or uniquely anchored hunks.
BASES = (
    'e07724ccdccc7fede01a44c274a78bf108484366',
    '99101eccb0be057fe0fd75c1e1848e81591113f3',
    '86e5271d76030e53da33ee680d99d9a09a742b54',
    '2ab99920fcd769ec65c7ddff5cee1e663bf9a661',
    'b6cd8463464dd02ee1646b050c16df8afbf44086',
)
# Introduced AI files require exact reviewed bytes from an installed release.
# Preserve the original release as an upgrade source; newer worker/provider
# versions must not be mistaken for unknown local edits. Unknown edits stay blocked.
AI_FILE_BASE = 'a89fffcb3926b56fa812d23a556d691cfdb69dd4'
AI_FILE_BASES = (
    AI_FILE_BASE,
    '2ab99920fcd769ec65c7ddff5cee1e663bf9a661',
    'b6cd8463464dd02ee1646b050c16df8afbf44086',
)
MANIFEST = 'deployment/meta_comment_ai_manifest.json'
MIGRATION = 'database/migrations/2026_10_15_010000_create_meta_comment_ai_tables.php'
DIAGNOSTIC = 'deployment/meta_comment_ai_diagnostic.php'
WEB_ROUTES = 'routes/web.php'
EXISTING_FILES = (
    'app/Services/Dashboard/MetaCommentsService.php',
    'app/Http/Controllers/Dashboard/MetaCommentsController.php',
    'public/js/dashboard-meta-comments.js',
    'public/css/dashboard-meta-comments.css',
    'resources/views/admin/meta/comments.blade.php',
)
NEW_FILES = (
    'app/Services/Dashboard/MetaCommentAiDispatchLock.php',
    'app/Services/Dashboard/MetaCommentReplyGate.php',
    'app/Services/Dashboard/MetaCommentsReadCache.php',
    'app/Services/Dashboard/MetaCommentAiReplyPolicy.php',
    'app/Services/Dashboard/MetaCommentAiProvider.php',
    'app/Services/Dashboard/MetaCommentAiControl.php',
    'app/Services/Dashboard/MetaCommentAiWorker.php',
    'app/Console/Commands/ProcessMetaCommentReplies.php',
    'app/Http/Controllers/Dashboard/MetaCommentAiController.php',
    'config/meta_comment_ai.php',
    'public/js/dashboard-meta-comment-ai.js',
    'public/css/dashboard-meta-comment-ai.css',
    MIGRATION,
    DIAGNOSTIC,
    'routes/meta_comment_ai.php',
)
SOURCE_FILES = (*NEW_FILES, *EXISTING_FILES, WEB_ROUTES)
# UI/controller consumers are exposed only after migration and route registration.
AFTER_MIGRATION = (*EXISTING_FILES[:1], WEB_ROUTES, *EXISTING_FILES[1:])
UNIT_ROOT = pathlib.Path('/etc/systemd/system')
UNIT_NAMES = ('fasakhansta-meta-comment-ai.service', 'fasakhansta-meta-comment-ai.timer')
UNIT_INPUTS = tuple('deployment/systemd/' + name for name in UNIT_NAMES)
ALL_INPUTS = (*SOURCE_FILES, *UNIT_INPUTS)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def matches(lines, block, at_start=False, at_end=False):
    if not block:
        return []
    size = len(block)
    return [i for i in range(len(lines) - size + 1)
            if (not at_start or i == 0) and (not at_end or i + size == len(lines))
            and lines[i:i + size] == block]


def surgical_patch(current, baseline, reviewed, label):
    """Patch uniquely anchored hunks; reject overlap with a concurrent live change."""
    if baseline == reviewed:
        raise RuntimeError('RELEASE_CHANGE_MISSING: ' + label)
    if current == reviewed:
        return current
    old, new, live = [content.splitlines(keepends=True) for content in (baseline, reviewed, current)]
    for group in difflib.SequenceMatcher(a=old, b=new, autojunk=False).get_grouped_opcodes(8):
        a0, a1 = group[0][1], group[-1][2]
        b0, b1 = group[0][3], group[-1][4]
        before, after = old[a0:a1], new[b0:b1]
        start, end = a0 == 0, a1 == len(old)
        pre, post = matches(live, before, start, end), matches(live, after, start, end)
        if len(post) == 1 and (not pre or (len(pre) == 1 and len(after) > len(before)
                and post[0] <= pre[0] and pre[0] + len(before) <= post[0] + len(after))):
            continue
        if len(pre) == 1 and (not post or (len(post) == 1 and len(before) > len(after)
                and pre[0] <= post[0] and post[0] + len(after) <= pre[0] + len(before))):
            live[pre[0]:pre[0] + len(before)] = after
            continue
        raise RuntimeError('SOURCE_PATCH_CONFLICT: ' + label)
    return b''.join(live)


def safe(path):
    for candidate in (path, *path.parents):
        if candidate.is_symlink():
            raise RuntimeError('SYMLINK_PATH: ' + str(path))


def snapshot(path, uid, repair_gid=None):
    safe(path)
    if not path.exists():
        return None
    meta = path.stat()
    trusted_group = repair_gid is not None and meta.st_uid == uid and meta.st_gid == repair_gid
    if (not stat.S_ISREG(meta.st_mode) or meta.st_uid not in (0, uid)
            or meta.st_mode & 0o7002 or (meta.st_mode & 0o020 and not trusted_group)):
        raise RuntimeError('UNSAFE_FILE: ' + str(path))
    return {'bytes': path.read_bytes(), 'uid': meta.st_uid, 'gid': meta.st_gid,
            'mode': stat.S_IMODE(meta.st_mode), 'device': meta.st_dev, 'inode': meta.st_ino,
            'mtime': meta.st_mtime_ns, 'links': meta.st_nlink}


def atomic(path, data, mode, uid, gid):
    safe(path)
    if not path.parent.is_dir():
        raise RuntimeError('PARENT_DIRECTORY_MISSING: ' + str(path.parent))
    fd, temporary = tempfile.mkstemp(prefix='.meta-comment-ai-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def owned_command(root, arguments):
    result = subprocess.run(['runuser', '-u', 'fasakha', '--', *arguments], cwd=root, capture_output=True)
    if result.returncode:
        raise RuntimeError('APPLICATION_COMMAND_FAILED: ' + pathlib.Path(arguments[0]).name)
    return result.stdout


def readable_sources(root, php, relatives):
    """Staging uses 0644; check the live files as the actual application user too."""
    script = ('$paths=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);$bad=[];'
              'foreach($paths as $path){if(!is_readable($path))$bad[]=$path;}'
              'echo json_encode($bad,JSON_THROW_ON_ERROR);')
    paths = [str(root / relative) for relative in relatives]
    try:
        unreadable = json.loads(owned_command(root, [php, '-r', script, json.dumps(paths)]))
    except (ValueError, TypeError):
        raise RuntimeError('APPLICATION_READABILITY_CHECK_FAILED')
    if not isinstance(unreadable, list) or any(path not in paths for path in unreadable):
        raise RuntimeError('APPLICATION_READABILITY_CHECK_FAILED')
    if unreadable:
        raise RuntimeError('APPLICATION_FILE_NOT_READABLE: ' + str(pathlib.Path(unreadable[0]).relative_to(root)))


def readiness_failure(data, phase):
    known = {'LIVE_SERVER_REQUIRED','CENTRAL_INBOX_NOT_READY','AI_SCHEMA_DIFFERS','COMMENTS_LEDGER_MISSING',
             'AI_READINESS_FATAL','AI_READINESS_EARLY_EXIT',
             'AI_POLICY_INVALID','VIEW_CACHE_PATH_UNSUPPORTED','AI_TABLES_NOT_READY','AI_ROUTES_INVALID','AI_UNIQUE_GUARDS_MISSING'}
    reason = data.get('reason') if isinstance(data, dict) else None
    reason = reason if reason in known else 'AI_READINESS_FAILED'
    detail = data.get('diagnostic') if isinstance(data, dict) else None
    if isinstance(detail, dict) and detail.get('phase') == phase \
            and isinstance(detail.get('step'), str) and re.fullmatch('[a-z][a-z0-9_:]{0,79}', detail['step']) \
            and isinstance(detail.get('type'), str) and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_\\]{0,159}', detail['type']) \
            and isinstance(detail.get('file'), str) and re.fullmatch(r'[A-Za-z0-9_.-]{1,100}\.php', detail['file']) \
            and type(detail.get('line')) is int and 0 < detail['line'] < 1000000:
        suffix = ('/' + detail['fatal_kind']) if detail.get('fatal_kind') in {
            'CLASS_REDECLARATION','FUNCTION_REDECLARATION','METHOD_SIGNATURE','MEMORY_LIMIT','TIME_LIMIT','OTHER'} else ''
        return reason + ': ' + phase + '/' + detail['step'] + '/' + detail['type'] + '/' + detail['file'] + ':' + str(detail['line']) + suffix
    return reason + ': ' + phase


def source(root, revision, relative):
    data = owned_command(root, ['git', '-C', str(root), 'show', revision + ':' + relative])
    expected = owned_command(root, ['git', '-C', str(root), 'rev-parse', revision + ':' + relative]).decode().strip()
    if not re.fullmatch('[a-f0-9]{40}', expected) or blob(data) != expected:
        raise RuntimeError('SOURCE_BLOB_MISMATCH: ' + relative)
    return data


def manifest_payload(root, release, provided):
    if provided != source(root, release, MANIFEST):
        raise RuntimeError('RELEASE_MANIFEST_DIFFERS')
    try:
        data = json.loads(provided)
    except (ValueError, TypeError):
        raise RuntimeError('INVALID_RELEASE_MANIFEST')
    if not isinstance(data, dict) or set(data) != {'version', 'files'} or type(data['version']) is not int \
            or data['version'] != 1 or not isinstance(data['files'], dict) or set(data['files']) != set(ALL_INPUTS) \
            or any(not isinstance(pin, str) or not re.fullmatch('[a-f0-9]{40}', pin) for pin in data['files'].values()):
        raise RuntimeError('INVALID_RELEASE_MANIFEST')
    return data['files']


def variant_patch(root, release, relative, current, reviewed):
    if current == reviewed:
        return current
    baselines = list(dict.fromkeys(source(root, revision, relative) for revision in BASES))
    # Known deployed bytes identify their exact version even when newer differences
    # lie outside the AI hunk. This avoids two valid but behaviorally different results.
    for baseline in baselines:
        if current == baseline:
            return surgical_patch(current, baseline, reviewed, relative)
    results = set()
    for baseline in baselines:
        if baseline == reviewed:
            continue
        try:
            results.add(surgical_patch(current, baseline, reviewed, relative))
        except RuntimeError as error:
            if not str(error).startswith('SOURCE_PATCH_CONFLICT:'):
                raise
    if not results:
        raise RuntimeError('SOURCE_PATCH_CONFLICT: ' + relative)
    if len(results) != 1:
        raise RuntimeError('SOURCE_PATCH_AMBIGUOUS: ' + relative)
    return results.pop()


def web_contents(value):
    if not value.startswith('<?php') or 'meta_inbox' not in value or 'meta_comments' not in value or '?>' in value:
        raise RuntimeError('LIVE_COMMENTS_ROUTE_ANCHOR_REQUIRED')
    if 'meta_comment_ai' in value:
        direct = re.compile(r"(?m)^require __DIR__\s*\.\s*(['\"])/meta_comment_ai\.php\1;[ \t]*\r?$")
        loop = re.compile(r"foreach\s*\(\s*\[([^\]]*)\]\s+as\s+\$metaRoutes\s*\)\s*\{\s*"
                          r"require\s+__DIR__\s*\.\s*(['\"])/\2\s*\.\s*\$metaRoutes\s*\.\s*(['\"])\.php\3\s*;\s*\}")
        count = len(direct.findall(value)) + sum(len(re.findall(r"(['\"])meta_comment_ai\1", match[1])) for match in loop.finditer(value))
        if value.count('meta_comment_ai') != 1 or count != 1:
            raise RuntimeError('AI_ROUTE_REGISTRATION_AMBIGUOUS')
        return value
    newline = '\r\n' if '\r\n' in value else '\n'
    return value + ('' if value.endswith('\n') else newline) + newline + "require __DIR__.'/meta_comment_ai.php';" + newline


def probe(root, php, script, phase, stage, views):
    descriptor, result_path = tempfile.mkstemp(prefix='meta-comment-ai-result-', dir='/tmp')
    try:
        owner = root.stat()
        os.fchown(descriptor, owner.st_uid, owner.st_gid)
        result = subprocess.run(['runuser', '-u', 'fasakha', '--', php, str(script), str(root), phase, str(stage), str(views), result_path],
                                cwd=root, capture_output=True, timeout=60)
        os.lseek(descriptor, 0, os.SEEK_SET)
        payload = os.read(descriptor, 65537)
        if len(payload) > 65536:
            raise RuntimeError('AI_READINESS_OUTPUT_INVALID: ' + phase)
        try:
            data = json.loads(payload if payload else result.stdout)
        except (ValueError, TypeError):
            raise RuntimeError('AI_READINESS_OUTPUT_INVALID: ' + phase + '/exit=' + str(result.returncode))
    finally:
        opened = os.fstat(descriptor)
        os.close(descriptor)
        try:
            current = os.lstat(result_path)
            if (current.st_dev, current.st_ino) == (opened.st_dev, opened.st_ino): os.unlink(result_path)
        except FileNotFoundError:
            pass
    if result.returncode or not isinstance(data, dict) or data.get('ok') is not True:
        raise RuntimeError(readiness_failure(data, phase))
    if not isinstance(data.get('tables'),dict) or type(data['tables'].get('meta_comment_ai_settings')) is not bool \
            or type(data.get('tables_ready')) is not bool or type(data.get('configured')) is not bool \
            or (data.get('enabled') is not None and type(data['enabled']) is not bool) \
            or data.get('mode') not in (None,'always','scheduled') \
            or type(data.get('verified_routes')) is not int or data['verified_routes'] != (2 if phase=='verify' else 0):
        raise RuntimeError('AI_READINESS_RESULT_INVALID: ' + phase)
    caches = data.get('view_caches')
    if not isinstance(caches, list) or len(caches) != len(set(caches)) or any(not isinstance(p, str) or not re.fullmatch(
            r'storage/framework/views/[a-f0-9]+\.php', p) for p in caches):
        raise RuntimeError('VIEW_CACHE_PATH_UNSUPPORTED')
    if data.get('routes_cached') is not False:
        raise RuntimeError('CACHED_ROUTES_BLOCKED')
    return data


def system_command(arguments):
    result = subprocess.run(arguments, capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('SYSTEMD_COMMAND_FAILED')
    return result.stdout.decode().strip()


def timer_state():
    state = {}
    for name in UNIT_NAMES:
        result = subprocess.run(['systemctl','show',name,'--property=LoadState','--property=FragmentPath',
                                 '--property=DropInPaths','--property=UnitFileState','--property=ActiveState'],
                                capture_output=True,timeout=30)
        properties = dict(line.split('=',1) for line in result.stdout.decode().splitlines() if '=' in line)
        if properties.get('LoadState') == 'not-found':
            if (UNIT_ROOT/name).exists() or properties.get('FragmentPath') or properties.get('DropInPaths'):
                raise RuntimeError('UNREVIEWED_TIMER_UNIT')
            state[name] = {'enabled':False,'active':False}; continue
        if result.returncode or properties.get('LoadState') != 'loaded': raise RuntimeError('SYSTEMD_COMMAND_FAILED')
        fragment = properties.get('FragmentPath',''); drops = properties.get('DropInPaths','')
        if drops or (fragment and fragment != str(UNIT_ROOT / name)):
            raise RuntimeError('UNREVIEWED_TIMER_UNIT')
        enabled = properties.get('UnitFileState',''); active = properties.get('ActiveState','')
        # Type=oneshot stays "activating" until ExecStart finishes. Starting a
        # timer with an elapsed OnBootSec can put its service there immediately.
        # These are service lifecycle transitions, not a change to timer policy.
        service_states = ('inactive', 'active', 'failed', 'activating', 'deactivating')
        allowed_states = service_states if name == UNIT_NAMES[0] else ('inactive', 'active', 'failed')
        if enabled not in ('', 'disabled', 'enabled', 'static') or active not in allowed_states:
            raise RuntimeError('UNREVIEWED_TIMER_STATE: ' + name)
        state[name] = {'enabled': enabled == 'enabled',
                       'active': active in ('active', 'activating', 'deactivating')}
    return state


def install(root, release, manifest, php=PHP, check_only=False, repair_permissions=False, install_timer=False):
    if os.geteuid() != 0 or root != APP_ROOT or php != PHP or not re.fullmatch('[a-f0-9]{40}', release):
        raise RuntimeError('FIXED_ROOT_RELEASE_REQUIRED')
    if 'APP_ROUTES_CACHE' in os.environ:
        raise RuntimeError('ROUTE_CACHE_OVERRIDE_BLOCKED')
    safe(root)
    user = pwd.getpwnam('fasakha')
    if root.stat().st_uid != user.pw_uid:
        raise RuntimeError('APPLICATION_OWNER_REQUIRED')
    arguments = (root, release, manifest, php, check_only, user.pw_uid, user.pw_gid, repair_permissions, install_timer)
    if check_only:
        return install_locked(*arguments)
    lock_path = root.parent / '.meta-comment-ai-update.lock'; safe(lock_path)
    lock = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        meta = os.fstat(lock)
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_nlink != 1 or stat.S_IMODE(meta.st_mode) != 0o600:
            raise RuntimeError('UPDATE_LOCK_UNSAFE')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return install_locked(*arguments)
    finally:
        os.close(lock)


def install_locked(root, release, manifest, php, check_only, uid, gid, repair_permissions=False, install_timer=False):
    pins = manifest_payload(root, release, manifest)
    with tempfile.TemporaryDirectory(prefix='meta-comment-ai-stage-', dir='/tmp') as temporary:
        stage = pathlib.Path(temporary); stage.chmod(0o755)
        originals, targets, modes, views = {}, {}, {}, {}
        def live(relative):
            return snapshot(root / relative, uid, gid if repair_permissions else None)
        for relative in SOURCE_FILES:
            payload = source(root, release, relative)
            if blob(payload) != pins[relative]:
                raise RuntimeError('SOURCE_MANIFEST_PIN_DIFFERS: ' + relative)
            old = live(relative)
            if relative == WEB_ROUTES:
                if old is None:
                    raise RuntimeError('LIVE_WEB_ROUTES_REQUIRED')
                web_contents(payload.decode('utf-8'))
                payload = web_contents(old['bytes'].decode('utf-8')).encode('utf-8')
            elif relative in EXISTING_FILES:
                if old is None:
                    raise RuntimeError('BASELINE_FILE_MISSING: ' + relative)
                payload = variant_patch(root, release, relative, old['bytes'], payload)
            elif old and old['bytes'] != payload:
                if not any(old['bytes'] == source(root, revision, relative) for revision in AI_FILE_BASES):
                    raise RuntimeError('NEW_FILE_COLLISION: ' + relative)
            originals[relative], targets[relative] = old, payload
            modes[relative] = old['mode'] & ~0o020 if old else 0o644
            checked = stage / relative; checked.parent.mkdir(parents=True, exist_ok=True); checked.write_bytes(payload); checked.chmod(0o644)
            for parent in checked.parents:
                parent.chmod(0o755)
                if parent == stage: break
            if relative.endswith('.blade.php'):
                views[relative] = str(checked)
            elif relative.endswith('.php'):
                owned_command(root, [php, '-l', str(checked)])
        unit_payloads, unit_originals = {}, {}
        for name, relative in zip(UNIT_NAMES, UNIT_INPUTS):
            payload = source(root, release, relative)
            if blob(payload) != pins[relative]: raise RuntimeError('UNIT_MANIFEST_PIN_DIFFERS')
            unit_payloads[name] = payload
            if install_timer:
                old = snapshot(UNIT_ROOT / name, 0)
                if old and old['bytes'] != payload: raise RuntimeError('UNREVIEWED_TIMER_UNIT')
                unit_originals[name] = old
                (stage / name).write_bytes(payload)
        prior_timer = timer_state() if install_timer else None
        if install_timer: system_command(['systemd-analyze', 'verify', *[str(stage / name) for name in UNIT_NAMES]])
        readable_sources(root, php, [path for path, old in originals.items() if old is not None])
        view_manifest = stage / 'views.json'; view_manifest.write_text(json.dumps(views)); view_manifest.chmod(0o644)
        readiness = probe(root, php, stage / DIAGNOSTIC, 'preflight', stage, view_manifest)
        compiled = readiness['view_caches']
        if len(compiled) != len(views): raise RuntimeError('VIEW_CACHE_PATH_UNSUPPORTED')
        caches = {path: snapshot(root / path, uid) for relative, path in zip(views, compiled)
                  if originals[relative] is None or originals[relative]['bytes'] != targets[relative]}
        repairs = [p for p, old in originals.items() if old and old['mode'] != modes[p]]
        if check_only:
            report = {'status': 'META_COMMENT_AI_PREFLIGHT_OK', 'files': len(targets), 'install_timer': install_timer,
                      'permission_repairs': repairs, 'readiness': readiness}
            print(json.dumps(report)); return report
        parent = root.parent / 'meta-comment-ai-backups'; safe(parent)
        if parent.exists() and (not parent.is_dir() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o077):
            raise RuntimeError('BACKUP_PARENT_UNSAFE')
        parent.mkdir(mode=0o700, exist_ok=True)
        backup = pathlib.Path(tempfile.mkdtemp(prefix=release[:12]+'-', dir=parent)); backup.chmod(0o700)
        stored = {**originals, **caches, **{'systemd/'+name: old for name, old in unit_originals.items()}}
        metadata = {'release': release, 'files': {}, 'timer_before': prior_timer,
                    'tables_retained': ['meta_comment_ai_settings','meta_comment_ai_targets','meta_comment_ai_poll_states'],
                    'target_blobs': {**{path: blob(value) for path, value in targets.items()},
                                     **{'systemd/'+name:blob(unit_payloads[name]) for name in unit_originals}}}
        for relative, old in stored.items():
            metadata['files'][relative] = None if old is None else {k:v for k,v in old.items() if k!='bytes'}
            if old:
                saved = backup / relative; saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                saved.write_bytes(old['bytes']); saved.chmod(0o600)
        (backup / 'manifest.json').write_text(json.dumps(metadata, indent=2)); (backup/'manifest.json').chmod(0o600)
        written, invalidated, units_written, timer_touched = {}, [], {}, False
        def publish(relative):
            old = originals[relative]
            if live(relative) != old: raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            if old is None or old['bytes'] != targets[relative] or old['mode'] != modes[relative]:
                target_uid=old['uid'] if old else uid; target_gid=old['gid'] if old else gid
                atomic(root/relative, targets[relative], modes[relative], target_uid, target_gid)
                written[relative] = None
                published=snapshot(root/relative,uid)
                if published is None or published['bytes'] != targets[relative] or published['mode'] != modes[relative] \
                        or published['uid'] != target_uid or published['gid'] != target_gid:
                    raise RuntimeError('CONCURRENT_EDIT: '+relative)
                written[relative] = published
        install_phase = 'publish_dependencies'
        try:
            for relative, old in originals.items():
                if live(relative) != old: raise RuntimeError('CONCURRENT_EDIT: ' + relative)
            for relative in NEW_FILES: publish(relative)
            install_phase = 'migration'
            owned_command(root, [php, 'artisan', 'migrate', '--path='+MIGRATION, '--force', '--no-interaction'])
            install_phase = 'post_migration'
            migrated = probe(root, php, stage / DIAGNOSTIC, 'post_migration', stage, view_manifest)
            if migrated.get('tables_ready') is not True: raise RuntimeError('AI_TABLES_NOT_READY')
            if not readiness['tables']['meta_comment_ai_settings'] and migrated.get('enabled') is not False:
                raise RuntimeError('NEW_POLICY_MUST_BE_OFF')
            install_phase = 'publish_page'
            for relative in AFTER_MIGRATION: publish(relative)
            for relative, old in caches.items():
                if snapshot(root/relative, uid) != old: raise RuntimeError('CONCURRENT_VIEW_CACHE_EDIT')
                if old:
                    (root/relative).unlink(); invalidated.append(relative)
            view_manifest.write_text(json.dumps({p:str(root/p) for p in views}))
            install_phase = 'verify'
            result = probe(root, php, root / DIAGNOSTIC, 'verify', root, view_manifest)
            if result['view_caches'] != compiled: raise RuntimeError('CONCURRENT_VIEW_CONFIGURATION_EDIT')
            for relative, published in written.items():
                if snapshot(root/relative, uid) != published: raise RuntimeError('CONCURRENT_EDIT: '+relative)
            if install_timer:
                install_phase = 'timer'
                # A oneshot worker may naturally finish while preflight/publication runs.
                # Only the timer's enable/active policy must remain stable here.
                if timer_state()[UNIT_NAMES[1]] != prior_timer[UNIT_NAMES[1]]: raise RuntimeError('CONCURRENT_TIMER_STATE_EDIT')
                for name in UNIT_NAMES:
                    old = unit_originals[name]
                    if snapshot(UNIT_ROOT/name, 0) != old: raise RuntimeError('CONCURRENT_TIMER_UNIT_EDIT')
                    if old is None:
                        atomic(UNIT_ROOT/name, unit_payloads[name], 0o644, 0, 0); units_written[name] = None
                        published=snapshot(UNIT_ROOT/name,0)
                        if published is None or published['bytes'] != unit_payloads[name] or published['mode'] != 0o644 \
                                or published['uid'] != 0 or published['gid'] != 0: raise RuntimeError('CONCURRENT_TIMER_UNIT_EDIT')
                        units_written[name] = published
                timer = UNIT_NAMES[1]
                if units_written: system_command(['systemctl','daemon-reload'])
                timer_touched = bool(units_written) or not prior_timer[timer]['enabled'] or not prior_timer[timer]['active']
                if not prior_timer[timer]['enabled']: system_command(['systemctl','enable',timer])
                if not prior_timer[timer]['active']: system_command(['systemctl','start',timer])
                final_timer = timer_state()
                if not final_timer[timer]['enabled'] or not final_timer[timer]['active']:
                    raise RuntimeError('TIMER_NOT_READY')
            else:
                final_timer = None
            report = {'status':'META_COMMENT_AI_READY', 'release':release, 'backup':str(backup),
                      'updated_files':len(written), 'timer_installed':install_timer,
                      'timer_state':final_timer,
                      'permission_repairs':repairs, 'readiness':result, 'provider_requests':0}
            print(json.dumps(report)); return report
        except BaseException:
            complete = True
            if timer_touched:
                try:
                    if any(snapshot(UNIT_ROOT/name,0) != units_written.get(name,unit_originals[name]) for name in UNIT_NAMES):
                        complete = False
                    else:
                        if not prior_timer[UNIT_NAMES[1]]['active']: system_command(['systemctl','stop',UNIT_NAMES[1]])
                        if not prior_timer[UNIT_NAMES[1]]['enabled']: system_command(['systemctl','disable',UNIT_NAMES[1]])
                        if not prior_timer[UNIT_NAMES[0]]['active']: system_command(['systemctl','stop',UNIT_NAMES[0]])
                except (OSError,RuntimeError): complete = False
            for name, published in reversed(list(units_written.items())):
                try:
                    if published is None: complete=False; continue
                    if snapshot(UNIT_ROOT/name,0) != published: complete=False; continue
                    old=unit_originals[name]
                    if old: atomic(UNIT_ROOT/name,old['bytes'],old['mode'],old['uid'],old['gid'])
                    else: (UNIT_ROOT/name).unlink()
                except (OSError,RuntimeError): complete=False
            if units_written:
                try: system_command(['systemctl','daemon-reload'])
                except RuntimeError: complete=False
            for relative, published in reversed(list(written.items())):
                try:
                    if published is None: complete=False; continue
                    if snapshot(root/relative,uid) != published: complete=False; continue
                    old=originals[relative]
                    if old: atomic(root/relative,old['bytes'],old['mode'],old['uid'],old['gid'])
                    else: (root/relative).unlink()
                except (OSError,RuntimeError): complete=False
            for relative in invalidated:
                try:
                    if snapshot(root/relative,uid) is not None: complete=False; continue
                    old=caches[relative]; atomic(root/relative,old['bytes'],old['mode'],old['uid'],old['gid'])
                except (OSError,RuntimeError): complete=False
            print(json.dumps({'status':'META_COMMENT_AI_FAILED','rollback':'RESTORED' if complete else 'NEEDS_REVIEW',
                              'backup':str(backup),'tables_retained':metadata['tables_retained'],'provider_requests':0,
                              'phase':install_phase}))
            raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',required=True); parser.add_argument('--release',required=True)
    parser.add_argument('--manifest',required=True); parser.add_argument('--php',default=PHP)
    parser.add_argument('--check',action='store_true'); parser.add_argument('--repair-permissions',action='store_true')
    parser.add_argument('--install-timer',action='store_true')
    args=parser.parse_args()
    try:
        install(pathlib.Path(args.root).absolute(),args.release,pathlib.Path(args.manifest).read_bytes(),args.php,
                args.check,args.repair_permissions,args.install_timer)
    except (Exception,KeyboardInterrupt) as error:
        print('META_COMMENT_AI_INSTALL_FAILED=' + (str(error) if isinstance(error,RuntimeError) else 'RUNTIME_OR_FILESYSTEM_FAILURE'))
        raise SystemExit(1)
