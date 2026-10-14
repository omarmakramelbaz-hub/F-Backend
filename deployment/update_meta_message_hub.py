#!/usr/bin/env python3
"""Apply the reviewed message hub files and preserve OAuth, replies, orders and sidebar customizations."""
import argparse, datetime, fcntl, hashlib, json, os, pathlib, pwd, re, stat, subprocess, tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
SOURCE_PINS = {'app/Http/Controllers/Dashboard/MetaInboxController.php': {'old': ['ff1f9c099e0c581d4561688121737ab4a57cd6f6'], 'new': '253315da8388b98b01c3f86ed489bd0b392e8ba7'}, 'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': {'old': ['7ce48a97f115eab4e1f2308c411d0dbc7f5d5f3a', 'cff016f77e6b0191eb2b7fd6295801439bd4a686'], 'new': '51e160ec4fc01f1bda102b8a945e02a4b7261770'}, 'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php': {'old': ['48af05084619f78480f472e75d30376235e77144'], 'new': '55bb1ac0cb35eed7e788735cc4793791d074745d'}, 'app/Services/Dashboard/MetaMediaService.php': {'old': ['8f0f0390e5aabad792595f6901e12a6784e25cbc'], 'new': 'bc97cf1170ef0e4aedb06cfb1a8954522f831ce4'}, 'app/Services/Dashboard/MetaProfileService.php': {'old': ['e0e309cc0ee61566165f9283f658f41b0862789a'], 'new': 'dad433a26db1219e08491553d6ef579e73b6f29a'}, 'app/Services/Dashboard/WhatsAppInboxMedia.php': {'old': ['ae1f6aa4d833de80637ea56b19719c649183456d'], 'new': '5b67c6042893e42b2828e7c8a27b21f7ec76364d'}, 'app/Services/Dashboard/WhatsAppReplyService.php': {'old': ['2cfc072d8e25c40e2f8de6fec5f8eca5899219bd', 'f0373c39d4a00d3ae67366c34949712adfd51858'], 'new': 'f80378c7b61cc42c747bdceefd71b79c566de553'}, 'public/css/dashboard-meta-inbox.css': {'old': ['ab4beecd1580769d917e3ddfe3b4139123d9dbbe'], 'new': '501cd0612a1e85fc30ec5d0bff4a0d6f38c7d7e0'}, 'public/js/dashboard-meta-inbox.js': {'old': ['6c6d56d2942554960ddf692641432179103fa70f'], 'new': 'eabbf9e0c04586ba8210c6a0c71a2ecf2b49dd33'}, 'public/js/dashboard-whatsapp-inbox.js': {'old': ['2f6de4626ec6108763f9cb024868233c7266dc36', '4d74ba3154fbab2ce1512ad605520666cf811bff'], 'new': '2d9d44d3bfcf0858cee9d2def96103e572c8d2ab'}, 'resources/views/admin/meta/channels.blade.php': {'old': [], 'new': '6c15d00e98405f2fa13cb4583b51bee175caeccf'}, 'resources/views/admin/meta/index.blade.php': {'old': ['32a40bc1b0529ae034e07ed60a7513f2c2c6853b'], 'new': '16716acdabc2fddbb1980bb03f00e249a73009c5'}, 'resources/views/admin/meta/sidebar.blade.php': {'old': ['34917f3199bf2ebeaad2f326d078191b4c4cfd1a'], 'new': 'cb321326b61eeebb2f8c1276e7b1be217c273d51'}, 'resources/views/admin/whatsapp/index.blade.php': {'old': ['37065b5ef7c15f49ab55c8a11e223f54552d34f8', '70d14e0b154934adf636fc18eba3546f3efad34e'], 'new': '89d395645bd530f715ac1539522335cbcfb1498b'}, 'resources/views/admin/whatsapp/sidebar.blade.php': {'old': ['1062c2a09a46f1561c449b4f794b755af3393cb5', '140d63fb48d45b128cc13f49fe16bf34d7d70e15', '88aa172c483f811e860e377ed69a8a1a1286ba83'], 'new': '4610de3333bab7e0e4e0e9855c7df9348b4cb597'}, 'routes/meta_inbox.php': {'old': ['41821cad641ca60c7fb9c20bf20d3a3cacd7d74e'], 'new': '2893265daae77c55f73f33e887bc953c619172d5'}, 'routes/whatsapp_inbox.php': {'old': ['524a38faa203d578abdcc6185e48709036d91e82', 'b05d9e9b982477cf5873f6d60786ef9458e2c336'], 'new': '08eaa489f53cb8b4db3012a0b33c1beafc6d0289'}}
NEW_FILES = ('resources/views/admin/meta/channels.blade.php',)

def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def safe(path):
    for candidate in (path, *path.parents):
        if candidate.is_symlink(): raise RuntimeError('SYMLINK_PATH')

def snapshot(path, uid):
    safe(path)
    if not path.exists(): return None
    m=path.stat()
    if not stat.S_ISREG(m.st_mode) or m.st_uid not in (0,uid) or m.st_mode & 0o7022:
        raise RuntimeError('UNSAFE_SOURCE: '+str(path.relative_to(APP_ROOT)))
    return {'bytes':path.read_bytes(),'uid':m.st_uid,'gid':m.st_gid,'mode':stat.S_IMODE(m.st_mode),
            'device':m.st_dev,'inode':m.st_ino,'mtime':m.st_mtime_ns,'links':m.st_nlink}

def atomic(path, data, mode, uid, gid):
    safe(path); safe(path.parent)
    fd,tmp=tempfile.mkstemp(prefix='.message-hub-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as stream: stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chmod(tmp,mode);os.chown(tmp,uid,gid);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)

def sidebar(source):
    wa=re.compile(r"(?m)^[ \t]*@include(?:If)?\(\s*(['\"])admin\.whatsapp\.sidebar\1\s*\)[ \t]*(?:\r?\n|$)")
    meta=re.compile(r"(?m)^[ \t]*@include(?:If)?\(\s*(['\"])admin\.meta\.sidebar\1\s*\)[ \t]*(?:\r?\n|$)")
    if len(wa.findall(source))>1 or len(meta.findall(source))>1: raise RuntimeError('SIDEBAR_LINK_AMBIGUOUS')
    if not meta.search(source):
        if not wa.search(source): raise RuntimeError('SIDEBAR_LINK_MISSING')
        source=wa.sub("                @include('admin.meta.sidebar')\n",source)
    else: source=wa.sub('',source)
    if 'admin.whatsapp.sidebar' in source: raise RuntimeError('SIDEBAR_LINK_UNRECOGNIZED')
    return source

def navigation(source):
    wa=re.compile(r"(?m)^[ \t]*placeShortcut\((['\"])whatsapp\1,\s*(['\"])dashboard\2\);[ \t]*(?:\r?\n|$)")
    meta=re.compile(r"placeShortcut\((['\"])meta\1,\s*(['\"])(?:whatsapp|dashboard)\2\);")
    if len(wa.findall(source))>1 or len(meta.findall(source))!=1: raise RuntimeError('NAVIGATION_LINK_AMBIGUOUS')
    return meta.sub("placeShortcut('meta', 'dashboard');",wa.sub('',source))

PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level=ob_get_level();ob_start();$result=['ok'=>false];$exit=1;
try {
 require $argv[1].'/vendor/autoload.php';$app=require $argv[1].'/bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if ($app->routesAreCached()) throw new \RuntimeException('ROUTES_CACHED');
 $wa=$app->make(\App\Services\Dashboard\WhatsAppInboxAccess::class);
 $meta=$app->make(\App\Services\Dashboard\MetaInboxAccess::class);
 if (!$wa->available() || !$meta->available()) throw new \RuntimeException('INBOX_NOT_READY');
 foreach (['whatsapp_inbox_hidden_messages','whatsapp_reply_requests','meta_message_hides','meta_reply_requests'] as $table)
  if (!\Illuminate\Support\Facades\Schema::hasTable($table)) throw new \RuntimeException('MESSAGE_TABLE_MISSING');
 $waVoice=$app->make(\App\Services\Dashboard\WhatsAppVoiceMedia::class)->available();
 $metaVoice=$app->make(\App\Services\Dashboard\MetaUploadMedia::class)->audioAvailable();
 if ($argv[2]==='verify') {
  $routes=$app['router']->getRoutes();
  foreach (['meta-inbox.whatsapp'=>['admin/meta/whatsapp','GET'],'meta-inbox.profile'=>['admin/meta/conversations/{conversation}/profile','POST']] as $name=>[$uri,$method]) {
   $r=$routes->getByName($name);
   if (!$r || $r->uri()!==$uri || !in_array($method,$r->methods(),true) || !in_array('IsAdmin',$r->gatherMiddleware(),true)) throw new \RuntimeException('HUB_ROUTE_INVALID');
  }
  foreach (['meta/index','meta/channels','meta/sidebar','whatsapp/index','whatsapp/sidebar'] as $view) {
   $code=$app['blade.compiler']->compileString(file_get_contents($argv[1].'/resources/views/admin/'.$view.'.blade.php'));
   token_get_all($code,TOKEN_PARSE);
  }
 }
 $result=['ok'=>true,'whatsapp_voice_ready'=>$waVoice,'meta_voice_ready'=>$metaVoice,'profiles_available'=>count(\App\Services\Dashboard\MetaInboxAccess::accounts())>0];$exit=0;
} catch (\Throwable $error) { $result=['ok'=>false,'reason'=>in_array($error->getMessage(),['ROUTES_CACHED','INBOX_NOT_READY','MESSAGE_TABLE_MISSING','HUB_ROUTE_INVALID'],true)?$error->getMessage():'RUNTIME_PROBE_FAILED']; }
while(ob_get_level()>$level)ob_end_clean();echo json_encode($result,JSON_UNESCAPED_SLASHES);exit($exit);
'''

def run_php(file, phase, root, php):
    r=subprocess.run(['runuser','-u','fasakha','--',php,str(file),str(root),phase],cwd=root,capture_output=True)
    try: data=json.loads(r.stdout)
    except Exception: raise RuntimeError('RUNTIME_PROBE_FAILED')
    if r.returncode or data.get('ok') is not True: raise RuntimeError(data.get('reason','RUNTIME_PROBE_FAILED'))
    return data

def install(root, release, php):
    if os.geteuid()!=0 or root!=APP_ROOT or not re.fullmatch('[a-f0-9]{40}',release): raise RuntimeError('FIXED_ROOT_RELEASE_REQUIRED')
    safe(root)
    lockpath=root.parent/'.meta-message-hub-update.lock';safe(lockpath)
    fd=os.open(lockpath,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    lockinfo=os.fstat(fd)
    if not stat.S_ISREG(lockinfo.st_mode) or lockinfo.st_uid!=0 or lockinfo.st_nlink!=1 or stat.S_IMODE(lockinfo.st_mode)!=0o600: raise RuntimeError('UPDATE_LOCK_UNSAFE')
    fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
    user=pwd.getpwnam('fasakha');uid=user.pw_uid;gid=user.pw_gid
    if root.stat().st_uid!=uid: raise RuntimeError('APPLICATION_OWNER_REQUIRED')
    with tempfile.TemporaryDirectory(prefix='meta-message-hub-',dir='/tmp') as temp:
        stage=pathlib.Path(temp);os.chmod(stage,0o755)
        probe=stage/'runtime.php';probe.write_text(PROBE);os.chmod(probe,0o644)
        readiness=run_php(probe,'preflight',root,php)
        targets={}; originals={}
        for rel,pins in SOURCE_PINS.items():
            path=root/rel;old=snapshot(path,uid)
            payload=subprocess.run(['runuser','-u','fasakha','--','git','-C',str(root),'show',release+':'+rel],capture_output=True)
            if payload.returncode: raise RuntimeError('RELEASE_FILE_MISSING: '+rel)
            if blob(payload.stdout)!=pins['new']: raise RuntimeError('RELEASE_CONTENT_CHANGED: '+rel)
            if old is None:
                if rel not in NEW_FILES: raise RuntimeError('BASELINE_FILE_MISSING: '+rel)
            elif blob(old['bytes']) not in pins['old']+[pins['new']]: raise RuntimeError('BASELINE_CHANGED: '+rel)
            originals[rel]=old;targets[rel]=payload.stdout
            if rel.endswith('.php') and not rel.endswith('.blade.php'):
                checked=stage/path.name;checked.write_bytes(payload.stdout);os.chmod(checked,0o644)
                r=subprocess.run(['runuser','-u','fasakha','--',php,'-l',str(checked)],capture_output=True)
                if r.returncode: raise RuntimeError('PHP_LINT_FAILED: '+rel)
        for rel,transform in [('resources/views/admin/layouts/menu.blade.php',sidebar),('public/dashboard/js/dashboard-navigation.js',navigation)]:
            old=snapshot(root/rel,uid)
            if old is None:
                if 'navigation' in rel: continue
                raise RuntimeError('SIDEBAR_FILE_MISSING')
            originals[rel]=old;targets[rel]=transform(old['bytes'].decode()).encode()
        # Snapshot equality preserves concurrent operator changes.
        parent=root.parent/'meta-release-backups';safe(parent)
        if parent.exists() and (not parent.is_dir() or parent.stat().st_uid not in (0,uid) or parent.stat().st_mode & 0o022): raise RuntimeError('BACKUP_PARENT_UNSAFE')
        parent.mkdir(mode=0o700,exist_ok=True)
        backup=pathlib.Path(tempfile.mkdtemp(prefix='message-hub-',dir=parent));os.chmod(backup,0o700)
        manifest={};written={}
        for rel,old in originals.items():
            if old:
                file=backup/rel;file.parent.mkdir(mode=0o700,parents=True,exist_ok=True);file.write_bytes(old['bytes']);os.chmod(file,0o600)
                manifest[rel]={k:v for k,v in old.items() if k!='bytes'}
            else: manifest[rel]=None
        (backup/'manifest.json').write_text(json.dumps(manifest));os.chmod(backup/'manifest.json',0o600)
        try:
            for rel,old in originals.items():
                if snapshot(root/rel,uid)!=old: raise RuntimeError('CONCURRENT_EDIT: '+rel)
            for rel,payload in targets.items():
                old=originals[rel]
                if old and payload==old['bytes']: continue
                if snapshot(root/rel,uid)!=old: raise RuntimeError('CONCURRENT_EDIT: '+rel)
                atomic(root/rel,payload,old['mode'] if old else 0o644,old['uid'] if old else uid,old['gid'] if old else gid)
                written[rel]=snapshot(root/rel,uid)
            result=run_php(probe,'verify',root,php)
            for rel,published in written.items():
                if snapshot(root/rel,uid)!=published: raise RuntimeError('CONCURRENT_EDIT: '+rel)
            print(json.dumps({'status':'META_MESSAGE_HUB_READY','updated_files':len(written),'backup':str(backup),**result}))
        except BaseException:
            complete=True
            for rel,published in reversed(list(written.items())):
                if snapshot(root/rel,uid)!=published: complete=False;continue
                old=originals[rel]
                if old: atomic(root/rel,old['bytes'],old['mode'],old['uid'],old['gid'])
                else: (root/rel).unlink()
            print('META_MESSAGE_HUB_ROLLBACK_'+('RESTORED' if complete else 'NEEDS_REVIEW'))
            raise

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--root',required=True);parser.add_argument('--release',required=True);parser.add_argument('--php',default=PHP);args=parser.parse_args()
    try: install(pathlib.Path(args.root).absolute(),args.release,args.php)
    except Exception as error: print(str(error));raise SystemExit(1)
