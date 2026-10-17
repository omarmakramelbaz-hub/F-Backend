#!/usr/bin/env python3
"""Install only the two GO platform logos, preserving all live application edits."""
import argparse, datetime, fcntl, hashlib, os, pwd, re, shutil, stat, subprocess, tempfile
from pathlib import Path
from types import SimpleNamespace

SHA256 = '6d3a0bec28a32f4b61bc9e28cd6bebe4ffff8f3baf8abbf7f7b3bbbce7491947'
ASSET = 'public/dashboard/branding/go-erp-logo-navy.png'
START, END = b'/* go-erp-platform-logos:start */', b'/* go-erp-platform-logos:end */'
CSS = b'/* go-erp-platform-logos:start */\n/* Only the two platform logos; existing page geometry and receipt branding stay intact. */\n.dashboard-login-logo.go-erp-platform-logo {\n    height: 380px;\n}\n@media (max-width: 800px) {\n    .dashboard-login-logo.go-erp-platform-logo { height: 235px; }\n}\n@media (max-width: 400px) {\n    .dashboard-login-logo.go-erp-platform-logo { height: 205px; }\n}\n.main-sidebar .dashboard-brand-logo.go-erp-platform-logo {\n    width: 208px;\n    height: 88px;\n    max-width: 100%;\n    flex: 0 1 208px;\n    object-fit: contain;\n}\n.main-sidebar .go-erp-platform-logo + .dashboard-brand-copy {\n    display: none !important;\n}\n.dashboard-theme.dashboard-navigation-ready .main-sidebar.dashboard-sidebar-compact .dashboard-brand-logo.go-erp-platform-logo {\n    height: 72px;\n}\n.sidebar-collapse .main-sidebar:not(:hover) .dashboard-brand-logo.go-erp-platform-logo {\n    width: 48px;\n    height: 48px;\n    flex-basis: 48px;\n}\n/* go-erp-platform-logos:end */'
SOURCE = b"{{ asset('dashboard/branding/go-erp-logo-navy.png') }}"
OLD_SOURCE = b"{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}"
IMAGES = re.compile(rb"<img\b(?:\"[^\"]*\"|'[^']*'|[^'\">])*>", re.I)
LINK = re.compile(rb"""(href="\{\{\s*asset\('dashboard/branding/dashboard-brand\.css'\)\s*\}\})(?:\?[^"\r\n]*)?(")""")


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def safe(path):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for part in reversed((path, *path.parents)):
        require(not part.is_symlink(), 'SYMLINK_REJECTED')


def read(path):
    safe(path)
    with path.open('rb') as stream:
        info = os.fstat(stream.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_size <= 16777216, 'INVALID_FILE')
        return stream.read(), info


def unchanged(path, before):
    safe(path)
    if before is None:
        return not path.exists()
    body, info = read(path)
    return body == before[0] and all(getattr(info, k) == getattr(before[1], k)
        for k in ('st_ino', 'st_dev', 'st_uid', 'st_gid', 'st_mode', 'st_mtime_ns', 'st_ctime_ns'))


def image(body, anchor):
    attr = lambda key: re.compile(rb'\b' + key + rb'\s*=\s*(["\'])(.*?)\1', re.S)
    matches = []
    for tag in IMAGES.finditer(body):
        classes = list(attr(b'class').finditer(tag.group()))
        if any(anchor in c.group(2).split() for c in classes):
            require(len(classes) == 1, 'DUPLICATE_IMAGE_CLASS')
            matches.append((tag, classes[0]))
    require(len(matches) == 1, 'LOGO_ANCHOR_NEEDS_REVIEW')
    tag, cls = matches[0]
    value = tag.group()
    tokens = cls.group(2).split()
    require(tokens.count(anchor) == 1 and tokens.count(b'go-erp-platform-logo') <= 1, 'LOGO_CLASS_INVALID')
    if b'go-erp-platform-logo' not in tokens:
        tokens.append(b'go-erp-platform-logo')
    for key, new in ((b'class', b' '.join(tokens)), (b'src', SOURCE), (b'alt', b'GO ERP SYSTEM')):
        attrs = list(attr(key).finditer(value))
        require(len(attrs) == 1, 'LOGO_ATTRIBUTE_NEEDS_REVIEW')
        a = attrs[0]
        if key == b'src':
            require(a.group(2) in (OLD_SOURCE, SOURCE), 'UNEXPECTED_LOGO_SOURCE')
        value = value[:a.start(2)] + new + value[a.end(2):]
    return body[:tag.start()] + value + body[tag.end():]


def plan(login, menu, css):
    login, menu = image(login, b'dashboard-login-logo'), image(menu, b'dashboard-brand-logo')
    links = list(LINK.finditer(login))
    require(len(links) == 1 and login.count(b'dashboard/branding/dashboard-brand.css') == 1, 'LOGIN_CSS_LINK_NEEDS_REVIEW')
    link = links[0]
    login = login[:link.start()] + link.group(1) + b'?v=20261017-go-logo-1' + link.group(2) + login[link.end():]
    if not css.count(START) and not css.count(END):
        css += b'\n' + CSS + b'\n'
    else:
        require(css.count(START) == css.count(END) == 1 and css.index(START) < css.index(END), 'CSS_MARKERS_NEED_REVIEW')
        css = css[:css.index(START)] + CSS + css[css.index(END) + len(END):]
    return login, menu, css


def stage(directory, body, info):
    fd, name = tempfile.mkstemp(prefix='.go-logo-', dir=directory)
    try:
        with os.fdopen(fd, 'wb') as stream:
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
    parser.add_argument('--asset', type=Path, required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = args.root
    safe(root)
    asset, _ = read(args.asset)
    require(asset.startswith(b'\x89PNG\r\n\x1a\n') and hashlib.sha256(asset).hexdigest() == SHA256, 'ASSET_HASH_OR_FORMAT_MISMATCH')
    paths = [root / p for p in ('resources/views/admin/login.blade.php', 'resources/views/admin/layouts/menu.blade.php', 'public/dashboard/branding/dashboard-brand.css', ASSET)]
    before = [read(p) for p in paths[:3]]
    safe(paths[3])
    before.append(read(paths[3]) if paths[3].exists() else None)
    desired = [*plan(*(x[0] for x in before[:3])), asset]
    changed = [i for i in range(4) if before[i] is None or before[i][0] != desired[i]]
    if args.check or not changed:
        print('GO_ERP_LOGOS_CHECK_OK' if args.check else 'GO_ERP_LOGOS_ALREADY_CURRENT')
        for i, path in enumerate(paths):
            print(('UPDATE ' if i in changed else 'CURRENT ') + str(path.relative_to(root)))
        return
    owner = pwd.getpwnam('fasakha')
    require(os.geteuid() in (0, owner.pw_uid), 'RUN_AS_ROOT_OR_FASAKHA')
    php = '/opt/cpanel/ea-php82/root/usr/bin/php'
    require(Path(php).is_file() and (os.geteuid() != 0 or shutil.which('runuser')), 'PHP_OR_RUNUSER_MISSING')
    safe(root / 'artisan')
    command = (['runuser', '-u', 'fasakha', '--'] if os.geteuid() == 0 else []) + [php, 'artisan', 'view:clear']
    private = root.parent / 'go-erp-logo-backups'
    safe(private)
    require(private != root and root not in private.parents, 'BACKUP_MUST_BE_PRIVATE')
    private.mkdir(mode=0o700, exist_ok=True)
    require(private.is_dir() and stat.S_IMODE(private.stat().st_mode) == 0o700, 'BACKUP_DIRECTORY_MODE_INVALID')
    lock_path = private / 'apply.lock'
    safe(lock_path)
    with os.fdopen(os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600), 'r+b') as lock:
        require(stat.S_ISREG(os.fstat(lock.fileno()).st_mode), 'INVALID_LOCK')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir=private))
        staged, published = {}, []
        asset_info = before[3][1] if before[3] else SimpleNamespace(st_uid=before[2][1].st_uid, st_gid=before[2][1].st_gid, st_mode=0o100644)
        try:
            for i, path in enumerate(paths):
                require(unchanged(path, before[i]), 'TARGET_CHANGED_BEFORE_BACKUP')
                if before[i]:
                    saved = stage(backup, before[i][0], before[i][1])
                    saved.rename(backup / (path.name + '.before'))
                if i in changed:
                    staged[i] = stage(path.parent, desired[i], before[i][1] if before[i] else asset_info)
            require(all(unchanged(path, before[i]) for i, path in enumerate(paths)), 'TARGET_CHANGED_BEFORE_PUBLICATION')
            for i in changed:
                require(unchanged(paths[i], before[i]), 'TARGET_CHANGED_DURING_PUBLICATION')
                os.replace(staged[i], paths[i])
                published.append(i)
            subprocess.run(command, cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(all(read(path)[0] == desired[i] for i, path in enumerate(paths)), 'FINAL_CHECK_FAILED')
            print('GO_ERP_LOGOS_OK\nBACKUP ' + str(backup))
        except BaseException:
            for i in reversed(published):
                require(read(paths[i])[0] == desired[i], 'ROLLBACK_BLOCKED_BY_CONCURRENT_EDIT')
                if before[i] is None:
                    paths[i].unlink()
                else:
                    restored = stage(paths[i].parent, before[i][0], before[i][1])
                    os.replace(restored, paths[i])
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
