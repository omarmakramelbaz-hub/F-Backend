#!/usr/bin/env python3
"""Incremental feature installer; preserves unrelated installed routes, dashboard work and credentials."""
import argparse, hashlib, json, os, pathlib, re, subprocess, tempfile, datetime, stat

def run(args, root, capture=False):
    return subprocess.run(args,cwd=root,check=True,text=True,capture_output=capture)
def git(root,*args): return run(['git',*args],root,True).stdout

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--root',required=True);parser.add_argument('--release',required=True);parser.add_argument('--php',required=True)
    args=parser.parse_args();root=pathlib.Path(args.root).resolve()
    if not re.fullmatch('[a-f0-9]{40}',args.release): raise RuntimeError('INVALID_RELEASE')
    if os.geteuid()==0: raise RuntimeError('RUN_AS_APPLICATION_USER')
    if not (root/'vendor/autoload.php').is_file(): raise RuntimeError('APPLICATION_RUNTIME_MISSING')
    manifest=json.loads(git(root,'show',args.release+':deployment/meta_messaging_manifest.json'))
    targets={};modes={}
    def safe_path(path):
        if path.is_symlink() or any(p.is_symlink() for p in path.parents): raise RuntimeError('SYMLINK_TARGET')
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
      'resources/views/admin/whatsapp/sidebar.blade.php':("@include('admin.meta.sidebar')", "\n@include('admin.meta.sidebar')\n"),
    }
    for rel,(marker,block) in blocks.items():
        path=root/rel
        safe_path(path)
        if not path.is_file() or path.is_symlink(): raise RuntimeError('REQUIRED_INSTALLATION_FILE: '+rel)
        modes[rel]=stat.S_IMODE(path.stat().st_mode)
        old=path.read_bytes()
        if marker not in old.decode(): targets[rel]=(old,old+block.encode())
    menu=root/'resources/views/admin/layouts/menu.blade.php'
    if not menu.is_file() or b"admin.whatsapp.sidebar" not in menu.read_bytes(): raise RuntimeError('INSTALLED_WHATSAPP_SIDEBAR_REQUIRED')
    backup_parent=root.parent/'meta-release-backups';safe_path(backup_parent)
    backup_parent.mkdir(mode=0o700,exist_ok=True);os.chmod(backup_parent,0o700)
    backup=pathlib.Path(tempfile.mkdtemp(prefix=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S')+'-'+args.release[:8]+'-',dir=backup_parent))
    cached={rel:(root/rel).read_bytes() if (root/rel).is_file() else None for rel in ['bootstrap/cache/config.php','bootstrap/cache/routes-v7.php']}
    cache_modes={rel:stat.S_IMODE((root/rel).stat().st_mode) if (root/rel).is_file() else 0o600 for rel in cached}
    before={}
    def atomic(path,content,mode=0o644):
        path.parent.mkdir(parents=True,exist_ok=True)
        fd,tmp=tempfile.mkstemp(prefix='.meta-install-',dir=path.parent)
        try:
            with os.fdopen(fd,'wb') as f: f.write(content)
            os.chmod(tmp,mode);os.replace(tmp,path)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
    try:
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
        listing=run([args.php,'artisan','route:list','--path=meta'],root,True).stdout
        if 'meta/messaging/webhook' not in listing or 'admin/meta' not in listing: raise RuntimeError('ROUTE_VERIFICATION_FAILED')
        print('META_MESSAGING_INSTALLED');print('BACKUP='+str(backup))
        # Configuration remains separate; no token, business Agent or automatic order changes during installation.
    except Exception:
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
