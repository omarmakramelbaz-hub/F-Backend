#!/usr/bin/env python3
"""Apply the flat native dynamic GO logo; preserve authentication and all navigation."""
import argparse, datetime, fcntl, hashlib, os, pwd, re, shutil, stat, subprocess, tempfile
from pathlib import Path

OLD_SHA256 = 'c4013b5590885821a6584a90e04594f52abef6bf3867dda9ded3d94ccd60290e'
SHA256 = '440ef988e527a54fa5cde8f3dcb85db1ae161f1929b434e3230f1598abc38599'
PARTIAL = 'resources/views/admin/partials/go_erp_logo.blade.php'
START, END = b'/* go-erp-logo-stamp:start */', b'/* go-erp-logo-stamp:end */'
CSS = b'/* go-erp-logo-stamp:start */\n/* Flat native GO branding; decoration is limited to the login logo. */\n.go-erp-platform-logo[data-go-erp-vector] { color: #fd7201; filter: none; }\n.dashboard-theme .main-sidebar .go-erp-platform-logo[data-go-erp-vector] { color: #fd7201; }\n.dashboard-login-identity { display: flex; flex-direction: column; justify-content: center; min-height: 440px; }\n.dashboard-login-identity .dashboard-login-logo.go-erp-platform-logo { height: auto; max-height: 240px; }\n.dashboard-login-brand-title.go-erp-tagline {\n    max-width: 380px;\n    margin: 14px auto 0;\n    font-size: 10px;\n    font-weight: 400;\n    line-height: 1.8;\n    letter-spacing: .045em;\n    color: #ffffff;\n    text-wrap: balance;\n}\n.dashboard-login-identity .dashboard-login-brand-subtitle { display: none; }\n@media (max-width: 800px) {\n    .dashboard-login-identity { min-height: 0; }\n}\n@media (max-width: 400px) {\n    .dashboard-login-brand-title.go-erp-tagline { font-size: 9px; letter-spacing: .02em; }\n}\n/* The native logo bursts once on mount, then remains fully visible. */\n.go-erp-platform-logo[data-go-erp-explosion] { overflow: visible; pointer-events: none; }\n.go-erp-logo-rest { opacity: 1; }\n.go-erp-logo-fragments { opacity: 0; }\n.go-erp-login-flow { display: none; opacity: 0; pointer-events: none; }\n@media (prefers-reduced-motion: no-preference) {\n    .go-erp-platform-logo[data-go-erp-explosion] .go-erp-logo-rest {\n        animation: go-erp-logo-rest 1.45s linear 1 both;\n    }\n    .go-erp-platform-logo[data-go-erp-explosion] .go-erp-logo-fragments {\n        animation: go-erp-logo-fragments 1.45s linear 1 both;\n    }\n    .go-erp-platform-logo[data-go-erp-explosion] .go-erp-logo-fragment {\n        transform-box: view-box;\n        transform-origin: 530px 311px;\n        animation: go-erp-fragment-flight 1.45s cubic-bezier(.2, .7, .2, 1) 1 both;\n    }\n    .dashboard-login-logo[data-go-erp-dynamic] .go-erp-login-flow {\n        display: block;\n        animation: go-erp-flow-reveal .7s ease 1.45s 1 both;\n    }\n    .dashboard-login-logo[data-go-erp-dynamic] .go-erp-flow-track {\n        animation: go-erp-flow-track 18s linear 1.45s infinite;\n    }\n    .dashboard-login-logo[data-go-erp-dynamic] .go-erp-flow-current {\n        animation: go-erp-flow-current 22s linear 1.45s infinite;\n    }\n    .dashboard-login-logo[data-go-erp-dynamic] .go-erp-flow-orbit {\n        transform-box: view-box;\n        transform-origin: 0 0;\n        animation: go-erp-flow-orbit 24s linear 1.45s infinite;\n    }\n    .dashboard-login-logo[data-go-erp-dynamic] .go-erp-flow-orbit-two {\n        animation-duration: 28s;\n        animation-direction: reverse;\n    }\n    @keyframes go-erp-flow-reveal {\n        from { opacity: 0; }\n        to { opacity: 1; }\n    }\n    @keyframes go-erp-flow-track {\n        to { stroke-dashoffset: -268; }\n    }\n    @keyframes go-erp-flow-current {\n        to { stroke-dashoffset: -2120; }\n    }\n    @keyframes go-erp-flow-orbit {\n        to { transform: rotate(360deg); }\n    }\n    @keyframes go-erp-logo-rest {\n        0%, 4% { opacity: 1; }\n        5%, 87.9% { opacity: 0; }\n        88%, 100% { opacity: 1; }\n    }\n    @keyframes go-erp-logo-fragments {\n        0%, 4% { opacity: 0; }\n        5%, 87.9% { opacity: 1; }\n        88%, 100% { opacity: 0; }\n    }\n    @keyframes go-erp-fragment-flight {\n        0%, 4%, 86%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }\n        30%, 40% { transform: translate(var(--go-x), var(--go-y)) rotate(var(--go-r)) scale(.94); }\n    }\n}\n/* go-erp-logo-stamp:end */'
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


def plan(login, css, current_partial, partial):
    require(hashlib.sha256(current_partial).hexdigest() in (OLD_SHA256, SHA256), 'LIVE_PARTIAL_CHANGED_NEEDS_REVIEW')
    refs = list(re.finditer(rb'\b(?:xlink:)?href\s*=\s*(["\'])(.*?)\1', partial, re.S | re.I))
    require(len(refs) == len(re.findall(rb'\b(?:xlink:)?href\s*=', partial, re.I)) and all(ref.group(2).startswith(b'#') for ref in refs), 'NONLOCAL_LOGO_REFERENCE_REJECTED')
    require(not re.search(rb'https?://|url\(\s*["\']?//|data:|javascript:', partial.replace(b'http://www.w3.org/2000/svg', b''), re.I), 'EXTERNAL_LOGO_RESOURCE_REJECTED')
    require(hashlib.sha256(partial).hexdigest() == SHA256 and partial.count(b'<svg') == partial.count(b'</svg>') == 1 and b'data-go-erp-vector=' in partial and not re.search(rb'<(?:img|image|feImage|filter|script|foreignObject)\b|\b(?:on[a-z]+|filter)\s*=|data:image|base64', partial, re.I), 'PARTIAL_HASH_OR_FORMAT_MISMATCH')
    require(login.count(INCLUDE) == login.count(b'admin.partials.go_erp_logo') == 1, 'INLINE_LOGO_NOT_INSTALLED')
    require(login.count(NEW_TITLE) == 1 and login.count(b'class="dashboard-login-brand-title') == 1, 'ENGLISH_SLOGAN_NOT_INSTALLED')
    wrappers = re.findall(rb'<div class="dashboard-login-brand">\s*' + re.escape(INCLUDE) + rb'\s*</div>', login)
    require(len(wrappers) == 1 and login.count(b'class="dashboard-login-brand"') == 1, 'NONCLICKABLE_LOGO_WRAPPER_NOT_INSTALLED')
    links = list(LINK.finditer(login))
    require(len(links) == 1 and login.count(b'dashboard/branding/dashboard-brand.css') == 1, 'LOGIN_CSS_LINK_NEEDS_REVIEW')
    link = links[0]
    login = login[:link.start()] + link.group(1) + b'?v=20261017-go-dynamic-1' + link.group(2) + login[link.end():]
    require(css.count(START) == css.count(END) == 1 and css.index(START) < css.index(END), 'STAMP_MARKERS_NEED_REVIEW')
    css = css[:css.index(START)] + CSS + css[css.index(END) + len(END):]
    return login, css, partial


def stage(directory, body, info):
    fd, name = tempfile.mkstemp(prefix='.go-dynamic-logo-', dir=directory)
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
    parser.add_argument('--partial', type=Path, required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    root = args.root
    safe(root)
    partial, _ = read(args.partial)
    paths = [root / p for p in ('resources/views/admin/login.blade.php', 'public/dashboard/branding/dashboard-brand.css', PARTIAL)]
    before = [read(path) for path in paths]
    desired = plan(*(item[0] for item in before), partial)
    changed = [i for i in range(3) if before[i][0] != desired[i]]
    if args.check or not changed:
        print('GO_ERP_DYNAMIC_LOGO_CHECK_OK' if args.check else 'GO_ERP_DYNAMIC_LOGO_ALREADY_CURRENT')
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
            for i in [index for index in (2, 1, 0) if index in changed]:
                require(unchanged(paths[i], before[i]), 'TARGET_CHANGED_DURING_PUBLICATION')
                os.replace(staged[i], paths[i])
                published.append(i)
            subprocess.run(command, cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(all(read(path)[0] == desired[i] for i, path in enumerate(paths)), 'FINAL_CHECK_FAILED')
            print('GO_ERP_DYNAMIC_LOGO_OK\nBACKUP ' + str(backup))
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
