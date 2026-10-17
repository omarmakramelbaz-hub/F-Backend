#!/usr/bin/env python3
"""Move the existing desktop tab above printing without replacing live extensions."""
import argparse
import datetime
import fcntl
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import tempfile


OLD_FUNCTION = """    function placeShortcut(route, afterRoute) {
        const find = value => rootNodes.find(node => paths(node).some(path => path.replace(/\\/$/, '') === '/admin/' + value));
        const shortcut = find(route);
        const anchor = find(afterRoute);
        if (shortcut && anchor) anchor.after(shortcut);
    }""".encode()
NEW_FUNCTION = OLD_FUNCTION.replace(b"route, afterRoute)", b"route, anchorRoute, position = 'after')").replace(b"find(afterRoute)", b"find(anchorRoute)").replace(
    b"if (shortcut && anchor) anchor.after(shortcut);",
    b"if (shortcut && anchor) {\n            if (position === 'before') anchor.before(shortcut);\n            else anchor.after(shortcut);\n        }")
OLD_CALL = b"    placeShortcut('desktop-pos', 'go-stores');"
NEW_CALL = b"    placeShortcut('desktop-pos', 'print-settings', 'before');"
DESKTOP = ("                    @if(config('desktop_pos.enabled'))\n"
           "                    <li class=\"nav-item\"><a href=\"{{ route('desktop-pos.index') }}\" class=\"nav-link {{ request()->is('admin/desktop-pos*')?'active':'' }}\"><i class=\"nav-icon fas fa-desktop\"></i><p>برنامج الكمبيوتر</p></a></li>\n"
           "                    @endif\n").encode()
PRINT_LINE = re.compile(rb"(?m)^[ \t]*<li class=\"nav-item\"><a href=\"\{\{ route\('print-settings\.index'\) \}\}\"[^\r\n]*</li>\n")
GATE = b"@if(app(\\App\\Services\\Dashboard\\TakeawayService::class)->canAccess(auth('admin')->user()))"


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_ROOT_REQUIRED')
    for parent in reversed((path, *path.parents)):
        if parent.exists() or parent.is_symlink():
            require(not parent.is_symlink(), 'SYMLINK_REJECTED')


def read(path):
    safe(path)
    with path.open('rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_size <= 1048576, 'INVALID_TARGET')
        body = stream.read()
    return body, info


def same(path, original):
    body, info = read(path)
    before = original[1]
    return body == original[0] and all(getattr(info, key) == getattr(before, key)
        for key in ('st_ino', 'st_dev', 'st_uid', 'st_gid', 'st_mode', 'st_mtime_ns', 'st_ctime_ns'))


def plan(js, blade):
    require(js.count(b'function placeShortcut(') == 1, 'SHORTCUT_FUNCTION_NEEDS_REVIEW')
    if js.count(OLD_FUNCTION) == 1 and js.count(OLD_CALL) == 1 and NEW_CALL not in js:
        js = js.replace(OLD_FUNCTION, NEW_FUNCTION, 1).replace(OLD_CALL, NEW_CALL, 1)
    else:
        require(js.count(NEW_FUNCTION) == 1 and js.count(NEW_CALL) == 1 and OLD_CALL not in js,
                'SHORTCUT_PATTERNS_NEED_REVIEW')
    require(blade.count(DESKTOP) == 1 and blade.count(b"route('desktop-pos.index')") == 1,
            'DESKTOP_BLOCK_NEEDS_REVIEW')
    prints = list(PRINT_LINE.finditer(blade))
    require(len(prints) == 1 and blade.count(GATE) == 1, 'PRINT_GATE_NEEDS_REVIEW')
    start, depth, end = blade.index(GATE), 0, None
    for token in re.finditer(rb'@if\b|@endif\b', blade[start:]):
        depth += 1 if token.group() == b'@if' else -1
        if depth == 0:
            end = start + token.start()
            break
    require(end is not None and start < blade.index(DESKTOP) < end and start < prints[0].start() < end,
            'ACCESS_GATE_MISMATCH')
    blade = blade.replace(DESKTOP, b'', 1)
    anchor = list(PRINT_LINE.finditer(blade))
    require(len(anchor) == 1, 'PRINT_ANCHOR_NEEDS_REVIEW')
    return js, blade[:anchor[0].start()] + DESKTOP + blade[anchor[0].start():]


def stage(directory, body, info):
    descriptor, name = tempfile.mkstemp(prefix='.desktop-tab-', dir=directory)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
    except BaseException:
        Path(name).unlink(missing_ok=True)
        raise
    return Path(name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('/home/fasakha/public_html'))
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = args.root
    safe(root)
    paths = [root / 'public/dashboard/js/dashboard-navigation.js', root / 'resources/views/admin/layouts/menu.blade.php']
    originals = [read(path) for path in paths]
    desired = plan(*(item[0] for item in originals))
    changed = [i for i in range(2) if originals[i][0] != desired[i]]
    if args.check or not changed:
        print('DESKTOP_TAB_CHECK_OK' if args.check else 'DESKTOP_TAB_ALREADY_CURRENT')
        for i, path in enumerate(paths):
            print(('UPDATE ' if i in changed else 'CURRENT ') + str(path.relative_to(root)))
        return
    owner = pwd.getpwnam('fasakha')
    require(os.geteuid() in (0, owner.pw_uid), 'RUN_AS_ROOT_OR_FASAKHA')
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    require(Path(php).is_file() and (os.geteuid() != 0 or shutil.which('runuser')), 'PHP_OR_RUNUSER_MISSING')
    private = root.parent / 'sidebar-position-backups'
    safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    require(stat.S_IMODE(private.stat().st_mode) == 0o700, 'BACKUP_DIRECTORY_MODE_INVALID')
    with (private / 'apply.lock').open('a+b') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir=private))
        staged, published = {}, []
        try:
            for i, path in enumerate(paths):
                require(same(path, originals[i]), 'TARGET_CHANGED_BEFORE_BACKUP')
                saved = stage(backup, originals[i][0], originals[i][1])
                saved.rename(backup / ('navigation.js' if i == 0 else 'menu.blade.php'))
                if i in changed:
                    staged[i] = stage(path.parent, desired[i], originals[i][1])
            for i in changed:
                require(same(paths[i], originals[i]), 'TARGET_CHANGED_BEFORE_PUBLICATION')
                os.replace(staged[i], paths[i])
                published.append(i)
            command = (['runuser', '-u', 'fasakha', '--'] if os.geteuid() == 0 else []) + [php, 'artisan', 'view:clear']
            subprocess.run(command, cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(all(read(path)[0] == desired[i] for i, path in enumerate(paths)), 'FINAL_CHECK_FAILED')
            print('DESKTOP_TAB_POSITION_OK\nBACKUP ' + str(backup))
        except BaseException:
            for i in reversed(published):
                require(read(paths[i])[0] == desired[i], 'ROLLBACK_BLOCKED_BY_CONCURRENT_EDIT')
                restore = stage(paths[i].parent, originals[i][0], originals[i][1])
                os.replace(restore, paths[i])
            print('ROLLBACK_RESTORED\nBACKUP ' + str(backup))
            raise
        finally:
            for temporary in staged.values():
                temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        raise SystemExit('STOPPED: ' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__))
