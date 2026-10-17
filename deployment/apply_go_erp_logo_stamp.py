#!/usr/bin/env python3
"""Apply the native GO stamp treatment and exact login tagline only."""
import argparse, datetime, fcntl, os, pwd, re, shutil, stat, subprocess, tempfile
from pathlib import Path

START, END = b'/* go-erp-logo-stamp:start */', b'/* go-erp-logo-stamp:end */'
CSS = b'/* go-erp-logo-stamp:start */\n/* The native vector is pressed into the existing navy surface. */\n.go-erp-platform-logo[data-go-erp-vector] {\n    color: #294156;\n    filter: drop-shadow(0 -1px 0.6px rgba(0, 0, 0, .7))\n            drop-shadow(0 1px 0 rgba(191, 214, 234, .12));\n}\n.go-erp-platform-logo[data-go-erp-vector] > g { fill: currentColor; }\n.dashboard-theme .main-sidebar .go-erp-platform-logo[data-go-erp-vector] { color: #30495e; }\n.dashboard-login-brand-title.go-erp-tagline {\n    max-width: 360px;\n    margin-inline: auto;\n    font-size: 13px;\n    line-height: 1.9;\n    letter-spacing: .065em;\n    color: #c4d0dc;\n    text-wrap: balance;\n}\n@media (max-width: 400px) {\n    .dashboard-login-brand-title.go-erp-tagline { font-size: 11px; letter-spacing: .04em; }\n}\n/* go-erp-logo-stamp:end */'
OLD_TITLE = b'<p class="dashboard-login-brand-title">{{ $isArabic ? \'\xd8\xa5\xd8\xaf\xd8\xa7\xd8\xb1\xd8\xa9 \xd8\xaa\xd8\xb7\xd8\xa8\xd9\x8a\xd9\x82\xd8\xa7\xd8\xaa \xd8\xac\xd9\x88 \xd9\x88\xd9\x81\xd8\xb3\xd8\xae\xd8\xa7\xd9\x86\xd8\xb3\xd8\xaa\xd8\xa7\' : \'GO & Fasakhansta management\' }}</p>'
NEW_TITLE = b'<p class="dashboard-login-brand-title go-erp-tagline" lang="en" dir="ltr">THE INTELEGENT SYSTEM FOR SALES AND INVENTORY AND HR</p>'
INCLUDE = b"@include('admin.partials.go_erp_logo', ['logoClass' => 'dashboard-login-logo go-erp-platform-logo'])"
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


def plan(login, css):
    require(login.count(INCLUDE) == login.count(b'admin.partials.go_erp_logo') == 1, 'INLINE_LOGO_NOT_INSTALLED')
    require(login.count(OLD_TITLE) + login.count(NEW_TITLE) == 1 and login.count(b'class="dashboard-login-brand-title') == 1, 'LOGIN_TITLE_NEEDS_REVIEW')
    login = login.replace(OLD_TITLE, NEW_TITLE, 1)
    links = list(LINK.finditer(login))
    require(len(links) == 1 and login.count(b'dashboard/branding/dashboard-brand.css') == 1, 'LOGIN_CSS_LINK_NEEDS_REVIEW')
    link = links[0]
    login = login[:link.start()] + link.group(1) + b'?v=20261017-go-stamp-1' + link.group(2) + login[link.end():]
    if not css.count(START) and not css.count(END):
        css += b'\n' + CSS + b'\n'
    else:
        require(css.count(START) == css.count(END) == 1 and css.index(START) < css.index(END), 'STAMP_MARKERS_NEED_REVIEW')
        css = css[:css.index(START)] + CSS + css[css.index(END) + len(END):]
    return login, css


def stage(directory, body, info):
    fd, name = tempfile.mkstemp(prefix='.go-logo-stamp-', dir=directory)
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
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = args.root
    safe(root)
    paths = [root / p for p in ('resources/views/admin/login.blade.php', 'public/dashboard/branding/dashboard-brand.css')]
    before = [read(path) for path in paths]
    desired = plan(*(item[0] for item in before))
    changed = [i for i in range(2) if before[i][0] != desired[i]]
    if args.check or not changed:
        print('GO_ERP_LOGO_STAMP_CHECK_OK' if args.check else 'GO_ERP_LOGO_STAMP_ALREADY_CURRENT')
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
        try:
            for i, path in enumerate(paths):
                require(unchanged(path, before[i]), 'TARGET_CHANGED_BEFORE_BACKUP')
                if before[i]:
                    saved = stage(backup, before[i][0], before[i][1])
                    saved.rename(backup / (path.name + '.before'))
                if i in changed:
                    staged[i] = stage(path.parent, desired[i], before[i][1])
            require(all(unchanged(path, before[i]) for i, path in enumerate(paths)), 'TARGET_CHANGED_BEFORE_PUBLICATION')
            for i in [index for index in (1, 0) if index in changed]:
                require(unchanged(paths[i], before[i]), 'TARGET_CHANGED_DURING_PUBLICATION')
                os.replace(staged[i], paths[i])
                published.append(i)
            subprocess.run(command, cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(all(read(path)[0] == desired[i] for i, path in enumerate(paths)), 'FINAL_CHECK_FAILED')
            print('GO_ERP_LOGO_STAMP_OK\nBACKUP ' + str(backup))
        except BaseException:
            for i in reversed(published):
                require(read(paths[i])[0] == desired[i], 'ROLLBACK_BLOCKED_BY_CONCURRENT_EDIT')
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
