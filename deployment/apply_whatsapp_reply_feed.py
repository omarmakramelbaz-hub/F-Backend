#!/usr/bin/env python3
"""Publish the accepted-reply feed with guarded sources and additive view edits."""
import fcntl
import importlib.util
import json
import os
import pathlib
import pwd
import re
import shutil
import signal
import stat
import sys
import tempfile

SPINNER_BLOB = '34f5f42d7ee1369a80b0f4c5ae25c2224f8ed894'
SOURCE_PINS = {
    'app/Services/Dashboard/WhatsAppReplyService.php': (
        '2cfc072d8e25c40e2f8de6fec5f8eca5899219bd', '7047caefba93935a25029ea99715dac645d9c24d'),
    'public/js/dashboard-whatsapp-inbox.js': (
        '4d74ba3154fbab2ce1512ad605520666cf811bff', '19117235ba454c5e73bbd291920f9f56c84ebea8'),
    'public/js/dashboard-whatsapp-replies.js': (
        '8a5cf1ab3e802c991949756f55b868d37cb37f80', '304a0bc6acd2474adb7850664708f30bddbd6a29'),
}
VIEW_NAMES = {
    'resources/views/admin/whatsapp/index.blade.php': 'admin.whatsapp.index',
    'resources/views/admin/whatsapp/replies.blade.php': 'admin.whatsapp.replies',
}


def load_spinner():
    import hashlib
    path = pathlib.Path(__file__).absolute().with_name('apply_whatsapp_reply_spinner_fix.py')
    for parent in reversed(path.parents):
        s = parent.lstat()
        if not stat.S_ISDIR(s.st_mode) or s.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_SPINNER_HELPER_PATH')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        s = os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_nlink != 1 or s.st_mode & 0o022
                or s.st_uid not in (0, os.geteuid()) or s.st_size > 1048576):
            raise RuntimeError('UNSAFE_SPINNER_HELPER_FILE')
        with os.fdopen(fd, 'rb', closefd=False) as stream:
            content = stream.read(1048577)
        t = os.fstat(fd)
        if (s.st_size, s.st_mtime_ns, s.st_ctime_ns) != (t.st_size, t.st_mtime_ns, t.st_ctime_ns):
            raise RuntimeError('SPINNER_HELPER_CHANGED')
    finally:
        os.close(fd)
    if hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() != SPINNER_BLOB:
        raise RuntimeError('SPINNER_HELPER_HASH_MISMATCH')
    spec = importlib.util.spec_from_file_location('verified_wa_reply_feed_spinner', path)
    module = importlib.util.module_from_spec(spec); sys.modules[spec.name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


spinner = load_spinner()
base = spinner.base
SafeError = base.SafeError
APP_ROOT = spinner.APP_ROOT
PHP = spinner.PHP


def read_payload(path, uid, root):
    item = base.snapshot(path, uid, root)
    if item.mode & 0o022 or path.lstat().st_nlink != 1:
        raise SafeError('UNSAFE_REPLY_FEED_PAYLOAD')
    return item.content


def load_manifest(path, digest, uid):
    if not re.fullmatch(r'[a-f0-9]{40}', digest):
        raise SafeError('INVALID_REPLY_FEED_MANIFEST_BLOB')
    payload = path.parent.parent
    content = read_payload(path, uid, payload)
    if base.blob_hash(content) != digest:
        raise SafeError('REPLY_FEED_MANIFEST_HASH_MISMATCH')
    try:
        data = json.loads(content)
        if (type(data.get('version')) is not int or data['version'] != 1
                or not isinstance(data.get('sources'), list) or not isinstance(data.get('views'), list)):
            raise ValueError()
        sources = {}
        for row in data['sources']:
            if set(row) != {'path', 'before_blob', 'after_blob'} or row['path'] in sources:
                raise ValueError()
            sources[row['path']] = (row['before_blob'], row['after_blob'])
        if sources != SOURCE_PINS:
            raise ValueError()
        views = {}
        for row in data['views']:
            if (set(row) != {'path', 'name', 'replacements'} or row['path'] in views
                    or VIEW_NAMES.get(row['path']) != row['name']
                    or not isinstance(row['replacements'], list) or not row['replacements']):
                raise ValueError()
            for pair in row['replacements']:
                if (set(pair) != {'old', 'new'} or not isinstance(pair['old'], str)
                        or not isinstance(pair['new'], str) or not pair['old'] or not pair['new']
                        or pair['old'] == pair['new']):
                    raise ValueError()
            views[row['path']] = row
        if set(views) != set(VIEW_NAMES):
            raise ValueError()
    except (ValueError, TypeError, KeyError, AttributeError):
        raise SafeError('REPLY_FEED_MANIFEST_SCOPE_INVALID') from None
    return payload, views


def view_proposal(content, replacements):
    newline = b'\r\n' if b'\r\n' in content else b'\n'
    if newline == b'\r\n' and b'\n' in content.replace(b'\r\n', b''):
        raise SafeError('REPLY_FEED_VIEW_MIXED_NEWLINES_NEEDS_REVIEW')
    candidate = content
    for pair in replacements:
        old, new = pair['old'].encode(), pair['new'].encode()
        old = old.replace(b'\r\n', b'\n').replace(b'\n', newline)
        new = new.replace(b'\r\n', b'\n').replace(b'\n', newline)
        if candidate.count(old) == 1 and candidate.count(new) == 0:
            candidate = candidate.replace(old, new, 1)
        elif candidate.count(old) == 0 and candidate.count(new) == 1:
            pass
        else:
            raise SafeError('REPLY_FEED_VIEW_REPLACEMENT_AMBIGUOUS')
    return candidate


COMPILE_PHP = spinner.COMPILE_PHP.replace(
    "find('admin.layouts.footer')", "find($argv[3])").replace(
    "getcwd() . '/resources/views/admin/layouts/footer.blade.php'", "getcwd() . '/' . $argv[4]")

SMOKE_PHP = r'''
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $input = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR);
    $compiler = app('blade.compiler');
    foreach ($input['views'] as $view) {
        $path = app('view')->getFinder()->find($view['name']);
        if ($path !== getcwd() . '/' . $view['path']) exit(1);
        if (!$compiler->isExpired($path)) {
            $cached = file_get_contents($compiler->getCompiledPath($path));
            if (!is_string($cached)) exit(1);
            // Recompilation by an intervening request is safe if the new markers exist.
            foreach ($view['markers'] as $marker)
                if (strpos($cached, $marker) === false) exit(1);
        }
    }
    $actor = \App\Models\User::find(1);
    if (!$actor || !app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess($actor)) exit(1);
    $thread = \Illuminate\Support\Facades\DB::table('whatsapp_inbox_conversations as c')
        ->leftJoin('whatsapp_reply_requests as r', function ($join) {
            $join->on('r.conversation_id', '=', 'c.id')->on('r.waba_id', '=', 'c.waba_id')
                ->on('r.phone_number_id', '=', 'c.phone_number_id');
        })->where('c.waba_id', '468336579702269')->where('c.phone_number_id', '515388018324075')
        ->orderByDesc('r.id')->orderByDesc('c.id')->first(['c.id']);
    if ($thread) {
        $state = app(\App\Services\Dashboard\WhatsAppReplyService::class)->state($actor, (int) $thread->id);
        if (($state['success'] ?? null) !== true || !is_array($state['recent_replies'] ?? null)) exit(1);
        foreach ($state['recent_replies'] as $reply) {
            foreach (['reply_id', 'source', 'accepted_at', 'projected_message_id'] as $field)
                if (!array_key_exists($field, $reply)) exit(1);
            if (!is_int($reply['reply_id']) || $reply['reply_id'] < 1
                || $reply['source'] !== 'dashboard_api'
                || ($reply['projected_message_id'] !== null
                    && (!is_int($reply['projected_message_id']) || $reply['projected_message_id'] < 1))) exit(1);
        }
    }
    exit(0);
} catch (\Throwable $error) { exit(1); }
'''


def apply(root, uid, manifest, digest, runner=spinner.command):
    base.validate_root(root, uid)
    payload, view_rows = load_manifest(manifest, digest, uid)
    parent = root.parent / 'whatsapp-release-backups'; base.private_parent(parent, uid)
    lock = os.open(parent / 'inbox-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        s = os.fstat(lock)
        if not stat.S_ISREG(s.st_mode) or s.st_uid != uid or s.st_nlink != 1 or stat.S_IMODE(s.st_mode) != 0o600:
            raise SafeError('REPLY_FEED_LOCK_NEEDS_REVIEW')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SafeError('ANOTHER_INBOX_INSTALL_IS_RUNNING') from None
        before, proposals = {}, {}
        for relative, (old, new) in SOURCE_PINS.items():
            source = read_payload(payload / relative, uid, payload)
            if base.blob_hash(source) != new:
                raise SafeError('REPLY_FEED_PAYLOAD_HASH_MISMATCH: ' + relative)
            original = base.snapshot(root / relative, uid, root)
            # Live release files may share an inode with a backup. Atomic exchange
            # publishes a fresh inode and retains every original hardlink intact.
            if original.mode & 0o022:
                raise SafeError('REPLY_FEED_LIVE_FILE_UNSAFE: ' + relative)
            if base.blob_hash(original.content) not in (old, new):
                raise SafeError('REPLY_FEED_LIVE_HASH_NEEDS_REVIEW: ' + relative)
            before[relative], proposals[relative] = original, source
        all_views = {**VIEW_NAMES, spinner.FOOTER_PATH: 'admin.layouts.footer'}
        for relative in all_views:
            original = base.snapshot(root / relative, uid, root)
            if original.mode & 0o022:
                raise SafeError('REPLY_FEED_LIVE_VIEW_UNSAFE: ' + relative)
            before[relative] = original
            proposals[relative] = (spinner.proposal(original.content) if relative == spinner.FOOTER_PATH
                                   else view_proposal(original.content, view_rows[relative]['replacements']))
        backup = pathlib.Path(tempfile.mkdtemp(prefix='reply-feed-', dir=parent)); backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev:
            raise SafeError('BACKUP_AND_PROJECT_REQUIRE_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700)
        base.probe_atomic_exchange(publications)
        stamps, smoke_views = {}, []
        for index, (relative, original) in enumerate(before.items()):
            spinner.private_write(backup / ('before-' + str(index)), original.content)
            candidate = backup / ('candidate-' + str(index)); spinner.private_write(candidate, proposals[relative])
            if relative in all_views:
                compiled = backup / ('compiled-' + str(index) + '.php')
                code, output = runner(root, [PHP, '-r', COMPILE_PHP, str(candidate), str(compiled),
                                            all_views[relative], relative], capture=True)
                try:
                    stamp = json.loads(output)['compiled_mtime']
                    if code != 0 or type(stamp) is not int or stamp < 0:
                        raise ValueError()
                except (ValueError, TypeError, KeyError):
                    raise SafeError('REPLY_FEED_TEMPLATE_COMPILE_FAILED: ' + relative) from None
                stamps[relative] = stamp
                code, _ = runner(root, [PHP, '-l', str(compiled)])
                markers = ([spinner.NEW_SELECTOR.decode()] if relative == spinner.FOOTER_PATH else
                           [pair['new'] for pair in view_rows[relative]['replacements']])
                # Blade translations may compile into PHP expressions: source hashes and the
                # compiler expiry check remain authoritative for those two application views.
                smoke_views.append({'path': relative, 'name': all_views[relative],
                                    'markers': markers if relative == spinner.FOOTER_PATH else []})
            elif relative.endswith('.php'):
                code, _ = runner(root, [PHP, '-l', str(candidate)])
            else:
                node = shutil.which('node')
                code, _ = runner(root, [node, '--check', str(candidate)]) if node else (0, b'')
            if code != 0:
                raise SafeError('REPLY_FEED_CANDIDATE_LINT_FAILED: ' + relative)
        spinner.private_write(backup / 'receipt.json', (json.dumps({'files': {
            relative: {'before_blob': base.blob_hash(item.content), 'after_blob': base.blob_hash(proposals[relative]),
                       'uid': item.uid, 'gid': item.gid, 'mode': item.mode}
            for relative, item in before.items()}}, sort_keys=True) + '\n').encode())
        smoke_input = backup / 'smoke.json'; spinner.private_write(smoke_input, json.dumps({'views': smoke_views}).encode())
        written, created = {}, []
        try:
            for relative, original in before.items():
                base.assert_snapshot(root / relative, original, uid, root)
            for relative, original in before.items():
                if original.content != proposals[relative]:
                    base.replace_file(root / relative, proposals[relative], original, uid, root, created,
                                      journal=written, relative=relative, private_directory=publications)
            for relative, stamp in stamps.items():
                current = written[relative].after if relative in written else before[relative]
                spinner.stamp_source(root / relative, current, uid, root, stamp)
            code, _ = runner(root, [PHP, '-l', str(root / 'app/Services/Dashboard/WhatsAppReplyService.php')])
            if code != 0:
                raise SafeError('REPLY_FEED_PUBLISHED_SERVICE_LINT_FAILED')
            code, _ = runner(root, [PHP, '-r', SMOKE_PHP, str(smoke_input)])
            if code != 0:
                raise SafeError('REPLY_FEED_READ_ONLY_SMOKE_FAILED')
            for relative, original in before.items():
                current = written[relative].after if relative in written else original
                base.assert_snapshot(root / relative, current, uid, root)
        except BaseException:
            restored = base.rollback(root, uid, before, written, created)
            for relative in all_views:
                if relative in written:
                    try:
                        spinner.stamp_source(root / relative, before[relative], uid, root, stamps[relative])
                    except BaseException:
                        restored = False
            status = ('REPLY_FEED_FILES_RESTORED' if restored else
                      'REPLY_FEED_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED')
            raise SafeError(status + '; BACKUP=' + str(backup)) from None
        return 'WHATSAPP_ACCEPTED_REPLY_FEED_READY', backup
    finally:
        os.close(lock)


def main():
    try:
        args = sys.argv[1:]
        if (len(args) != 6 or args[0] != '--root' or args[2] != '--manifest'
                or args[4] != '--manifest-blob' or pathlib.Path(args[1]) != APP_ROOT
                or os.geteuid() != pwd.getpwnam('fasakha').pw_uid):
            raise SafeError('USAGE_RUN_AS_FASAKHA_ROOT_MANIFEST_MANIFEST_BLOB')
        os.umask(0o077)
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('REPLY_FEED_INSTALL_INTERRUPTED')))
        status, backup = apply(APP_ROOT, os.geteuid(), pathlib.Path(args[3]), args[5])
        print(status); print('BACKUP=' + str(backup))
        return 0
    except SafeError as error:
        print(str(error), file=sys.stderr)
    except BaseException:
        print('REPLY_FEED_INSTALL_STOPPED_WITHOUT_SECRET_OUTPUT', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
