#!/usr/bin/env python3
"""Enable the native solar GO logo in the sidebar; preserve login and all navigation."""
import argparse, datetime, fcntl, hashlib, os, pwd, re, shutil, stat, subprocess, tempfile
from pathlib import Path

OLD_SHA256 = '5e4c8a49880787a58d9bf6f1f4d848641e54fad7780247af018c67bb1e300ab9'
SHA256 = '79db48dcdc165e096d7d6d3bc1247e756ec583da620a3749824ce69299b575c5'
PARTIAL = 'resources/views/admin/partials/go_erp_logo.blade.php'
START, END = b'/* go-erp-logo-stamp:start */', b'/* go-erp-logo-stamp:end */'
CSS = b'/* go-erp-logo-stamp:start */\n/* Shared native GO Solar System branding for login and dashboard. */\n.go-erp-platform-logo[data-go-erp-vector] { color: #fd7201; filter: none; }\n.dashboard-theme .main-sidebar .go-erp-platform-logo[data-go-erp-vector] { color: #fd7201; }\n.dashboard-login-identity { display: flex; flex-direction: column; justify-content: center; min-height: 440px; }\n.dashboard-login-identity .dashboard-login-logo.go-erp-platform-logo { width: min(360px, 100%); height: auto; max-height: 380px; }\n.dashboard-login-brand-title.go-erp-tagline {\n    max-width: 380px;\n    margin: 14px auto 0;\n    font-size: 10px;\n    font-weight: 400;\n    line-height: 1.8;\n    letter-spacing: .045em;\n    color: #ffffff;\n    text-wrap: balance;\n}\n.dashboard-login-identity .dashboard-login-brand-subtitle { display: none; }\n@media (max-width: 800px) {\n    .dashboard-login-identity { min-height: 0; }\n    .dashboard-login-identity .dashboard-login-logo.go-erp-platform-logo { width: min(260px, 100%); max-height: 275px; }\n}\n@media (max-width: 400px) {\n    .dashboard-login-brand-title.go-erp-tagline { font-size: 9px; letter-spacing: .02em; }\n    .dashboard-login-identity .dashboard-login-logo.go-erp-platform-logo { width: min(230px, 100%); max-height: 245px; }\n}\n/* The native logo bursts once on mount, then remains fully visible. */\n.go-erp-platform-logo[data-go-erp-explosion] { overflow: visible; pointer-events: none; }\n.go-erp-logo-rest { opacity: 1; }\n.go-erp-logo-compact { display: none; }\n.go-erp-logo-fragments { opacity: 0; }\n.go-erp-login-flow { display: none; opacity: 0; pointer-events: none; }\n@media (prefers-reduced-motion: no-preference) {\n    .go-erp-platform-logo[data-go-erp-explosion] .go-erp-logo-rest {\n        animation: go-erp-logo-rest 1.45s linear 1 both;\n    }\n    .go-erp-platform-logo[data-go-erp-explosion] .go-erp-logo-fragments {\n        animation: go-erp-logo-fragments 1.45s linear 1 both;\n    }\n    .go-erp-platform-logo[data-go-erp-explosion] .go-erp-logo-fragment {\n        transform-box: view-box;\n        transform-origin: 530px 311px;\n        animation: go-erp-fragment-flight 1.45s cubic-bezier(.2, .7, .2, 1) 1 both;\n    }\n    .go-erp-platform-logo[data-go-erp-dynamic] .go-erp-login-flow {\n        display: block;\n        animation: go-erp-flow-reveal .7s ease 1.45s 1 both;\n    }\n    .go-erp-platform-logo[data-go-erp-solar] .go-erp-solar-rotor,\n    .go-erp-platform-logo[data-go-erp-solar] .go-erp-solar-counter {\n        transform-box: view-box;\n        transform-origin: 0 0;\n        animation-duration: var(--orbit-period);\n        animation-timing-function: linear;\n        animation-delay: 1.45s;\n        animation-iteration-count: infinite;\n        animation-fill-mode: both;\n    }\n    .go-erp-platform-logo[data-go-erp-solar] .go-erp-solar-rotor { animation-name: go-erp-solar-orbit; }\n    .go-erp-platform-logo[data-go-erp-solar] .go-erp-solar-counter { animation-name: go-erp-solar-counter; }\n    .go-erp-platform-logo[data-go-erp-solar] .go-erp-solar-corona { animation: go-erp-solar-corona 8s ease-in-out 1.45s infinite; }\n    @keyframes go-erp-flow-reveal {\n        from { opacity: 0; }\n        to { opacity: 1; }\n    }\n    @keyframes go-erp-solar-orbit {\n        from { transform: rotate(var(--orbit-start)); }\n        to { transform: rotate(var(--orbit-end)); }\n    }\n    @keyframes go-erp-solar-counter {\n        from { transform: rotate(var(--counter-start)); }\n        to { transform: rotate(var(--counter-end)); }\n    }\n    @keyframes go-erp-solar-corona {\n        0%, 100% { opacity: .7; }\n        50% { opacity: 1; }\n    }\n    @keyframes go-erp-logo-rest {\n        0%, 4% { opacity: 1; }\n        5%, 87.9% { opacity: 0; }\n        88%, 100% { opacity: 1; }\n    }\n    @keyframes go-erp-logo-fragments {\n        0%, 4% { opacity: 0; }\n        5%, 87.9% { opacity: 1; }\n        88%, 100% { opacity: 0; }\n    }\n    @keyframes go-erp-fragment-flight {\n        0%, 4%, 86%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }\n        30%, 40% { transform: translate(var(--go-x), var(--go-y)) rotate(var(--go-r)) scale(.94); }\n    }\n}\n@media (prefers-reduced-motion: reduce) {\n    .go-erp-platform-logo[data-go-erp-solar] .go-erp-login-flow { display: block; opacity: 1; }\n}\n/* Fit the shared Solar System into the existing sidebar brand slot. */\n.dashboard-theme .main-sidebar .dashboard-brand { padding-block: 4px; }\n.dashboard-theme .main-sidebar .dashboard-brand-logo.go-erp-platform-logo[data-go-erp-solar] { height: 128px; }\n.dashboard-theme.dashboard-navigation-ready .main-sidebar.dashboard-sidebar-compact .dashboard-brand-logo.go-erp-platform-logo[data-go-erp-solar] { height: 104px; }\n.dashboard-theme.sidebar-collapse .main-sidebar:not(:hover) .dashboard-brand-logo.go-erp-platform-logo[data-go-erp-solar] {\n    width: 48px;\n    height: 48px;\n    flex-basis: 48px;\n}\n.dashboard-theme.sidebar-collapse .main-sidebar:not(:hover) .go-erp-logo-stage { display: none; }\n.dashboard-theme.sidebar-collapse .main-sidebar:not(:hover) .go-erp-logo-compact { display: inline; }\n/* go-erp-logo-stamp:end */'
MENU_INCLUDE = b"@include('admin.partials.go_erp_logo', ['logoClass' => 'dashboard-brand-logo go-erp-platform-logo'])"


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


def plan(current_partial, css, menu, partial):
    require(hashlib.sha256(current_partial).hexdigest() in (OLD_SHA256, SHA256), 'LIVE_PARTIAL_CHANGED_NEEDS_REVIEW')
    refs = list(re.finditer(rb'\b(?:xlink:)?href\s*=\s*(["\'])(.*?)\1', partial, re.S | re.I))
    require(len(refs) == len(re.findall(rb'\b(?:xlink:)?href\s*=', partial, re.I)) and all(ref.group(2).startswith(b'#') for ref in refs), 'NONLOCAL_LOGO_REFERENCE_REJECTED')
    require(not re.search(rb'https?://|url\(\s*["\']?//|data:|javascript:', partial.replace(b'http://www.w3.org/2000/svg', b''), re.I), 'EXTERNAL_LOGO_RESOURCE_REJECTED')
    require(hashlib.sha256(partial).hexdigest() == SHA256 and partial.count(b'<svg') == partial.count(b'</svg>') == 1 and b'data-go-erp-vector=' in partial and not re.search(rb'<(?:img|image|feImage|filter|script|foreignObject)\b|\b(?:on[a-z]+|filter)\s*=|data:image|base64', partial, re.I), 'PARTIAL_HASH_OR_FORMAT_MISMATCH')
    require(menu.count(MENU_INCLUDE) == menu.count(b'admin.partials.go_erp_logo') == 1, 'SIDEBAR_INLINE_LOGO_NOT_INSTALLED')
    wrappers = re.findall(rb'<div class="brand-link dashboard-brand">(.*?)</div>', menu, re.S)
    require(len(wrappers) == 1 and wrappers[0].count(MENU_INCLUDE) == 1 and menu.count(b'class="brand-link dashboard-brand"') == 1, 'NONCLICKABLE_SIDEBAR_LOGO_NOT_INSTALLED')
    require(css.count(START) == css.count(END) == 1 and css.index(START) < css.index(END), 'STAMP_MARKERS_NEED_REVIEW')
    css = css[:css.index(START)] + CSS + css[css.index(END) + len(END):]
    return partial, css


def stage(directory, body, info):
    fd, name = tempfile.mkstemp(prefix='.go-sidebar-solar-logo-', dir=directory)
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
    menu_path = root / 'resources/views/admin/layouts/menu.blade.php'
    menu_before = read(menu_path)
    paths = [root / PARTIAL, root / 'public/dashboard/branding/dashboard-brand.css']
    before = [read(path) for path in paths]
    desired = plan(before[0][0], before[1][0], menu_before[0], partial)
    changed = [i for i in range(2) if before[i][0] != desired[i]]
    if args.check or not changed:
        print('GO_ERP_SIDEBAR_SOLAR_LOGO_CHECK_OK' if args.check else 'GO_ERP_SIDEBAR_SOLAR_LOGO_ALREADY_CURRENT')
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
            require(all(unchanged(path, before[i]) for i, path in enumerate(paths)) and unchanged(menu_path, menu_before), 'TARGET_OR_MENU_CHANGED_BEFORE_PUBLICATION')
            for i in [index for index in (0, 1) if index in changed]:
                require(unchanged(paths[i], before[i]), 'TARGET_CHANGED_DURING_PUBLICATION')
                os.replace(staged[i], paths[i])
                published.append(i)
            subprocess.run(command, cwd=root, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            require(all(read(path)[0] == desired[i] for i, path in enumerate(paths)) and unchanged(menu_path, menu_before), 'FINAL_CHECK_FAILED')
            print('GO_ERP_SIDEBAR_SOLAR_LOGO_OK\nBACKUP ' + str(backup))
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
