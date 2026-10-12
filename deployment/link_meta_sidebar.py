#!/usr/bin/env python3
"""Place the installed Meta inbox directly after WhatsApp without replacing the menu."""
import argparse
import os
import pathlib
import re
import stat
import subprocess
import tempfile

WA_LINK = re.compile(r"@include(?:If)?\(\s*(['\"])admin\.whatsapp\.sidebar\1\s*\)")
META_LINE = re.compile(r"(?m)^[\t ]*@include(?:If)?\(\s*(['\"])admin\.meta\.sidebar\1\s*\)[\t ]*(?:\r?\n|$)")


def sidebar_contents(menu, whatsapp):
    clean = META_LINE.sub('', menu)
    sidebar = META_LINE.sub('', whatsapp)
    if 'admin.meta.sidebar' in clean or 'admin.meta.sidebar' in sidebar:
        raise ValueError('UNRECOGNIZED_META_LINK')
    anchors = list(WA_LINK.finditer(clean))
    if len(anchors) != 1:
        raise ValueError('SINGLE_WHATSAPP_MENU_LINK_REQUIRED')
    anchor = anchors[0]
    prefix = clean[clean.rfind('\n', 0, anchor.start()) + 1:anchor.start()]
    indent = prefix if not prefix.strip() else ''
    newline = '\r\n' if '\r\n' in clean else '\n'
    linked = clean[:anchor.end()] + newline + indent + "@include('admin.meta.sidebar')" + clean[anchor.end():]
    if not linked.endswith('\n'):
        linked += newline
    return linked, sidebar


def atomic(path, data, mode):
    fd, temporary = tempfile.mkstemp(prefix='.meta-sidebar-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True)
    parser.add_argument('--php', required=True)
    args = parser.parse_args()
    if os.geteuid() == 0:
        raise ValueError('RUN_AS_APPLICATION_USER')
    root = pathlib.Path(args.root).resolve()
    paths = [root / 'resources/views/admin/layouts/menu.blade.php',
             root / 'resources/views/admin/whatsapp/sidebar.blade.php']
    partial = root / 'resources/views/admin/meta/sidebar.blade.php'
    for path in paths + [partial]:
        if not path.is_file() or path.is_symlink() or any(p.is_symlink() for p in path.parents):
            raise ValueError('INSTALLED_SIDEBAR_FILE_REQUIRED')
    old = [p.read_bytes() for p in paths]
    target = [s.encode() for s in sidebar_contents(*(s.decode() for s in old))]
    changes = [(p, before, after, stat.S_IMODE(p.stat().st_mode))
               for p, before, after in zip(paths, old, target) if before != after]
    if changes:
        parent = root.parent / 'meta-release-backups'
        if parent.is_symlink():
            raise ValueError('SYMLINK_BACKUP')
        parent.mkdir(mode=0o700, exist_ok=True)
        backup = pathlib.Path(tempfile.mkdtemp(prefix='sidebar-', dir=parent))
        print('BACKUP=' + str(backup))
        applied = []
        try:
            for path, before, after, mode in changes:
                if path.read_bytes() != before:
                    raise ValueError('CONCURRENT_SIDEBAR_EDIT')
                saved = backup / path.relative_to(root)
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(before)
                saved.chmod(0o600)
                atomic(path, after, mode)
                applied.append((path, before, after, mode))
            subprocess.run([args.php, 'artisan', 'view:clear'], cwd=root,
                           check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        except Exception:
            for path, before, after, mode in reversed(applied):
                if path.read_bytes() == after:
                    atomic(path, before, mode)
            raise
    print('META_SIDEBAR_READY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        reason = str(error)
        print('META_SIDEBAR_LINK_FAILED=' + (reason if re.fullmatch('[A-Z_]+', reason) else type(error).__name__))
        raise SystemExit(1)
