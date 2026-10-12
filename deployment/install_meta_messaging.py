#!/usr/bin/env python3
"""Incremental feature installer; preserves unrelated installed routes, dashboard work and credentials."""
import argparse, hashlib, json, os, pathlib, re, subprocess, tempfile, datetime, stat

def run(args, root, capture=False):
    return subprocess.run(args,cwd=root,check=True,text=True,capture_output=capture)
def git(root,*args): return run(['git',*args],root,True).stdout

def sidebar_proposals(menu, whatsapp_sidebar):
    """Move only the known Meta include; retain the installed dashboard markup."""
    wa_include = re.compile(br"@include(?:If)?\([ \t]*(['\"])admin\.whatsapp\.sidebar\1[ \t]*\)")
    if b'admin.whatsapp.sidebar' not in menu:
        raise RuntimeError('INSTALLED_WHATSAPP_SIDEBAR_REQUIRED')
    if len(wa_include.findall(menu)) != 1 or menu.count(b'admin.whatsapp.sidebar') != 1:
        raise RuntimeError('WHATSAPP_SIDEBAR_ANCHOR_AMBIGUOUS')
    meta_include = br"@include(?:If)?\([ \t]*(['\"])admin\.meta\.sidebar\1[ \t]*\)"
    # A standalone include's indentation and line ending belong to that hook.
    # Inline includes lose only the directive, retaining surrounding markup.
    meta_line = re.compile(br'(?m)^[ \t]*' + meta_include + br'[ \t]*(?:\r?\n|$)')
    meta_inline = re.compile(meta_include)
    def remove(content):
        content = meta_inline.sub(b'', meta_line.sub(b'', content))
        if b'admin.meta.sidebar' in content:
            raise RuntimeError('META_SIDEBAR_HOOK_AMBIGUOUS')
        return content
    whatsapp_sidebar = remove(whatsapp_sidebar)
    original_anchor = wa_include.search(menu)
    canonical = re.match(br"\r?\n[ \t]*@include\([ \t]*(['\"])admin\.meta\.sidebar\1[ \t]*\)", menu[original_anchor.end():])
    newline = b'\r\n' if b'\r\n' in menu else b'\n'
    if canonical and len(meta_inline.findall(menu)) == 1 and menu.count(b'admin.meta.sidebar') == 1:
        return menu if menu.endswith(b'\n') else menu + newline, whatsapp_sidebar
    menu = remove(menu)
    anchor = wa_include.search(menu)
    line_start = menu.rfind(b'\n', 0, anchor.start()) + 1
    indent = re.match(br'[ \t]*', menu[line_start:anchor.start()]).group()
    menu = menu[:anchor.end()] + newline + indent + b"@include('admin.meta.sidebar')" + menu[anchor.end():]
    if not menu.endswith(b'\n'): menu += newline
    return menu, whatsapp_sidebar

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--root',required=True);parser.add_argument('--release',required=True);parser.add_argument('--php',required=True)
    args=parser.parse_args();root=pathlib.Path(args.root).resolve()
    if not re.fullmatch('[a-f0-9]{40}',args.release): raise RuntimeError('INVALID_RELEASE')
    if os.geteuid()==0: raise RuntimeError('RUN_AS_APPLICATION_USER')
    if not (root/'vendor/autoload.php').is_file(): raise RuntimeError('APPLICATION_RUNTIME_MISSING')
    manifest=json.loads(git(root,'show',args.release+':deployment/meta_messaging_manifest.json'))
    route_probe=git(root,'show',args.release+':deployment/meta_messaging_route_check.php').encode()
    targets={};modes={}
    def safe_path(path):
        if path.is_symlink() or any(p.is_symlink() for p in path.parents): raise RuntimeError('SYMLINK_TARGET')
    menu_rel='resources/views/admin/layouts/menu.blade.php'
    sidebar_rel='resources/views/admin/whatsapp/sidebar.blade.php'
    # Validate the actual installed anchor before publishing any feature files.
    sidebar_before={}
    for rel in (menu_rel,sidebar_rel):
        path=root/rel;safe_path(path)
        if not path.is_file():
            raise RuntimeError('INSTALLED_WHATSAPP_SIDEBAR_REQUIRED' if rel==menu_rel else 'REQUIRED_INSTALLATION_FILE: '+rel)
        modes[rel]=stat.S_IMODE(path.stat().st_mode)
        sidebar_before[rel]=path.read_bytes()
    sidebar_after=dict(zip((menu_rel,sidebar_rel),sidebar_proposals(sidebar_before[menu_rel],sidebar_before[sidebar_rel])))
    for rel in [*manifest['baseline'],*manifest['new_files']]:
        path=root/rel
        safe_path(path)
        modes[rel]=stat.S_IMODE(path.stat().st_mode) if path.exists() else 0o644
        target=git(root,'show',args.release+':'+rel).encode()
        old=path.read_bytes() if path.exists() else None
        if old==target: continue
        if rel in manifest['baseline']:
            # Git blob SHA over exact installed bytes, including newline.
            blob=hashlib.sha1(b'blob '+str(len(old or b'')).encode()+b'\0'+(old or b'')).hexdigest()
            if blob!=manifest['baseline'][rel]: raise RuntimeError('UNEXPECTED_INSTALLED_FILE: '+rel)
        elif old is not None: raise RuntimeError('EXISTING_META_FILE_DIFFERS: '+rel)
        targets[rel]=(old,target)
    blocks={
      'routes/api.php':("require __DIR__.'/meta_messaging.php';", "\nrequire __DIR__.'/meta_messaging.php';\n"),
      'routes/web.php':("'meta_inbox', 'meta_orders', 'meta_reads', 'meta_replies'", "\nforeach (['meta_inbox', 'meta_orders', 'meta_reads', 'meta_replies'] as $metaRoutes) {\n    require __DIR__.'/'.$metaRoutes.'.php';\n}\n"),
    }
    for rel,(marker,block) in blocks.items():
        path=root/rel
        safe_path(path)
        if not path.is_file() or path.is_symlink(): raise RuntimeError('REQUIRED_INSTALLATION_FILE: '+rel)
        modes[rel]=stat.S_IMODE(path.stat().st_mode)
        old=path.read_bytes()
        if marker not in old.decode(): targets[rel]=(old,old+block.encode())
    for rel,target in sidebar_after.items():
        if sidebar_before[rel]!=target: targets[rel]=(sidebar_before[rel],target)
    backup_parent=root.parent/'meta-release-backups';safe_path(backup_parent)
    backup_parent.mkdir(mode=0o700,exist_ok=True);os.chmod(backup_parent,0o700)
    backup=pathlib.Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S')+'-'+args.release[:8]+'-',dir=backup_parent))
    cached={rel:(root/rel).read_bytes() if (root/rel).is_file() else None for rel in ['bootstrap/cache/config.php','bootstrap/cache/routes-v7.php']}
    cache_modes={rel:stat.S_IMODE((root/rel).stat().st_mode) if (root/rel).is_file() else 0o600 for rel in cached}
    before={}
    phase='install'
    verification=None
    def atomic(path,content,mode=0o644):
        path.parent.mkdir(parents=True,exist_ok=True)
        fd,tmp=tempfile.mkstemp(prefix='.meta-install-',dir=path.parent)
        try:
            with os.fdopen(fd,'wb') as f: f.write(content)
            os.chmod(tmp,mode);os.replace(tmp,path)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
    try:
        probe_path=backup/'verify-meta-routes.php';probe_path.write_bytes(route_probe);os.chmod(probe_path,0o600)
        for rel,(old,target) in targets.items():
            path=root/rel
            if (path.read_bytes() if path.exists() else None)!=old: raise RuntimeError('CONCURRENT_EDIT: '+rel)
            if old is not None:
                stored=backup/rel;stored.parent.mkdir(parents=True,exist_ok=True);stored.write_bytes(old);os.chmod(stored,0o600)
            atomic(path,target,modes[rel]);before[rel]=(old,target)
        for rel in targets:
            if rel.endswith('.php') and not rel.endswith('.blade.php'): run([args.php,'-l',str(root/rel)],root,True)
        for rel in sorted(r for r in manifest['new_files'] if r.startswith('database/migrations/')):
            run([args.php,'artisan','migrate','--force','--path='+rel],root)
        run([args.php,'artisan','config:clear'],root)
        if cached['bootstrap/cache/config.php'] is not None: run([args.php,'artisan','config:cache'],root)
        run([args.php,'artisan','route:clear'],root)
        if cached['bootstrap/cache/routes-v7.php'] is not None: run([args.php,'artisan','route:cache'],root)
        if any(rel in targets for rel in (menu_rel,sidebar_rel)): run([args.php,'artisan','view:clear'],root)
        phase='verify-meta-routes'
        verification=run([args.php,str(probe_path)],root,True)
        checked=json.loads(verification.stdout)
        if checked!={'ok':True,'verified_routes':20}: raise RuntimeError('ROUTE_VERIFICATION_FAILED')
        print('META_ROUTES_VERIFIED=20')
        print('META_MESSAGING_INSTALLED');print('BACKUP='+str(backup))
        # Configuration remains separate; no token, business Agent or automatic order changes during installation.
    except Exception as error:
        if phase=='verify-meta-routes':
            diagnostic=backup/'route-check-failure.log'
            output=(getattr(error,'stdout',None) or (verification.stdout if verification is not None else ''))+'\n'+(getattr(error,'stderr',None) or '')
            diagnostic_saved=False
            try:
                descriptor=os.open(diagnostic,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
                with os.fdopen(descriptor,'w') as stream: stream.write(output)
                diagnostic_saved=True
            except OSError: pass # Diagnostic failure must never prevent code restoration.
            # A verifier failure prints structural diagnostics only; raw PHP output stays private.
            details={'reason':'ROUTE_VERIFICATION_FAILED'}
            try:
                decoded=json.loads(getattr(error,'stdout','') or '')
                allowed={'phase','reason','route','error_type','missing_class'}
                for key in allowed:
                    value=decoded.get(key)
                    if isinstance(value,str) and len(value)<=256 and re.fullmatch(r'[A-Za-z0-9_.\\:-]+',value): details[key]=value
            except (ValueError,TypeError,AttributeError): pass
            print('META_ROUTE_CHECK_FAILED='+json.dumps(details,sort_keys=True))
            print('PRIVATE_DIAGNOSTIC='+str(diagnostic) if diagnostic_saved else 'PRIVATE_DIAGNOSTIC_UNAVAILABLE')
        for rel,(old,target) in reversed(list(before.items())):
            path=root/rel
            if path.is_file() and path.read_bytes()==target:
                if old is None: path.unlink()
                else: atomic(path,old,modes[rel])
        for rel,content in cached.items():
            path=root/rel
            if content is None:
                if path.exists():path.unlink()
            else: atomic(path,content,cache_modes[rel])
        print('CODE_RESTORED; additive Meta tables and any captured data are retained')
        raise
if __name__=='__main__':main()
