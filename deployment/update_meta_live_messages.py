#!/usr/bin/env python3
"""Apply the unified All inbox and compact composers while preserving provider scopes and live server customizations."""
import argparse, datetime, fcntl, hashlib, json, os, pathlib, pwd, re, stat, subprocess, tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
SOURCE_PINS = {'app/Http/Controllers/Dashboard/MetaInboxController.php': {'old': ['253315da8388b98b01c3f86ed489bd0b392e8ba7', '5447479c060fe26f79b0552470351c97601cfad8'], 'new': '2ea48e57353be531027fc3dfb503e5471f4b5fd7'}, 'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': {'old': ['51e160ec4fc01f1bda102b8a945e02a4b7261770', '6f1da086e5b930f8248eb856a09f0c9572fb9545'], 'new': '6f1da086e5b930f8248eb856a09f0c9572fb9545'}, 'public/css/dashboard-meta-inbox.css': {'old': ['887c4eb3bddce8f510f80697a5993b5a91ed46f8', 'abac27a42a882d9f0a56493a3806324720a1361c'], 'new': '79e8e815e024199316d2f5bf86be56bf21ededfd'}, 'public/js/dashboard-meta-inbox.js': {'old': ['b43bc4a3a1240f4924c037508ae04ea330bb7abe', 'eabbf9e0c04586ba8210c6a0c71a2ecf2b49dd33'], 'new': '15853304f93533ffac4670f1e92c57b999dab7c1'}, 'public/js/dashboard-meta-replies.js': {'old': ['c1902a470c35e07d66b79b1ff666f79211865a87', 'ec89061d647a11548dd7930cd7d573cfdf68783b'], 'new': 'aa956a1b06ea2da77434dd2e5f59d52cb5532c7a'}, 'public/js/dashboard-whatsapp-inbox.js': {'old': ['2d9d44d3bfcf0858cee9d2def96103e572c8d2ab', 'acb5b8df5a765ff1541215f4564bb78e6d801f35'], 'new': '964bc531f7119451a570cd9ee3e2a0996f2fd1af'}, 'public/js/dashboard-whatsapp-orders.js': {'old': ['ad7f78fc490774280c31e01125bc262a92a2cb3d', 'ecd33b427e2af3cf444ea116920fe7f1ec51dc8d'], 'new': 'ecd33b427e2af3cf444ea116920fe7f1ec51dc8d'}, 'resources/views/admin/meta/channels.blade.php': {'old': ['490d12487f274627ed29ed5c44042fb466a6716e', '6c15d00e98405f2fa13cb4583b51bee175caeccf'], 'new': '490d12487f274627ed29ed5c44042fb466a6716e'}, 'resources/views/admin/meta/index.blade.php': {'old': ['16716acdabc2fddbb1980bb03f00e249a73009c5', '3d1a2a7a2d5af960a9ecdf3fee4e8895ab468aa9'], 'new': '3d1a2a7a2d5af960a9ecdf3fee4e8895ab468aa9'}, 'resources/views/admin/meta/orders.blade.php': {'old': ['2ea32304cab378634e9aa1b5f107d993370f5f67', '91238d0263c9d4442634e5e965f603f992655090'], 'new': '91238d0263c9d4442634e5e965f603f992655090'}, 'resources/views/admin/meta/replies.blade.php': {'old': ['9251a7defe180ad1477d71a67f1827026b860ef8', '9c27ce0a501ef3926dbd1c53503d607ff353ca5a'], 'new': '9251a7defe180ad1477d71a67f1827026b860ef8'}, 'resources/views/admin/whatsapp/index.blade.php': {'old': ['57478905af637dd43b251568276b7f6e2e12f4ca', '89d395645bd530f715ac1539522335cbcfb1498b'], 'new': '57478905af637dd43b251568276b7f6e2e12f4ca'}, 'public/css/dashboard-meta-message-hub.css': {'old': ['631727d91df60dc2a65ded92b1b6cdda87de0afe'], 'new': '631727d91df60dc2a65ded92b1b6cdda87de0afe'}, 'public/js/dashboard-meta-message-hub.js': {'old': ['b84ea253a1919ba1d73f6e8d1fc70c5ec03b6e01'], 'new': 'b84ea253a1919ba1d73f6e8d1fc70c5ec03b6e01'}, 'resources/views/admin/meta/social-pane.blade.php': {'old': ['84dd676c46ce58ae690599106665600cfbac19ca'], 'new': '84dd676c46ce58ae690599106665600cfbac19ca'}, 'resources/views/admin/whatsapp/chat-pane.blade.php': {'old': ['779f1f80586390f31fd295fecc411e3e6448fa3b'], 'new': '779f1f80586390f31fd295fecc411e3e6448fa3b'}, 'app/Http/Controllers/Dashboard/MetaReplyController.php': {'old': ['7b600e4bd37924b85cf786e3beccb289be4cef19'], 'new': 'e44f77d67aeb2b519ae66b182080ebb88afdb7a8'}, 'app/Services/Dashboard/MetaReplyService.php': {'old': ['84c686bba5acc0dd3696015f09854aae3f8c4448'], 'new': '8786a2099281188db1d20d7c6e23a429a97a6cff'}, 'public/js/dashboard-whatsapp-replies.js': {'old': ['392569e73e951405a5de6ce676922e63377a6b0b'], 'new': '65d49087c9b98d621e8d3a11151751c18416881a'}}
PROTECTED_PINS = {'app/Services/Dashboard/WhatsAppInboxAccess.php': '186789d42113e81818de8d82a2327e3ee9d8d0b5', 'app/Services/Dashboard/WhatsAppInboxMessage.php': 'bedd91db5266d83bdce84a04836e988518891728', 'app/Services/Dashboard/WhatsAppInboxConsumer.php': 'e0909057496cb9f710928fd8c273b5c9d0adc97f', 'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php': '4b571f7f4a5cef9257c414886dba515c06dae2a1', 'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php': '9e221ba052589c1ffe474958b636a77ff5817b7d', 'app/Support/WhatsAppInboxProtocol.php': 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd', 'app/Services/Dashboard/MetaInboxAccess.php': '7faaba6645308c1997bae8182fb4b26dbc5e3b9a', 'app/Services/Dashboard/MetaUploadMedia.php': 'dff1ee0d6d2670fc3b96abccd0a0dcdd9775ea2f', 'app/Services/Dashboard/WhatsAppVoiceMedia.php': '5a6d67a69dbbef185a783680dfe46a1730318380', 'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484', 'public/css/dashboard-whatsapp-inbox.css': '639ac2223b4abcd679329000551d9c735e43b5af', 'public/css/dashboard-whatsapp-replies.css': 'aaf5d4b8a5c46b6aaaf43824c52ddefca00ba854', 'resources/views/admin/whatsapp/replies.blade.php': '6386debd6e20836cc1023cefc7868e214ce7f0f0', 'resources/views/admin/whatsapp/orders.blade.php': '54a9db4693091c4bb0a6b6924e73791e249f2308', 'app/Services/Dashboard/WhatsAppReplyService.php': 'f80378c7b61cc42c747bdceefd71b79c566de553', 'app/Services/Dashboard/MetaMediaService.php': 'bc97cf1170ef0e4aedb06cfb1a8954522f831ce4', 'app/Services/Dashboard/WhatsAppInboxMedia.php': '5b67c6042893e42b2828e7c8a27b21f7ec76364d'}
NEW_FILES = ('public/css/dashboard-meta-message-hub.css', 'public/js/dashboard-meta-message-hub.js', 'resources/views/admin/meta/social-pane.blade.php', 'resources/views/admin/whatsapp/chat-pane.blade.php')

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
  if (!method_exists(\App\Services\Dashboard\MetaReplyService::class,'acceptedReply')) throw new \RuntimeException('HUB_ROUTE_INVALID');
  $routes=$app['router']->getRoutes();
  foreach (['meta-inbox.whatsapp'=>['admin/meta/whatsapp','GET'],'meta-inbox.profile'=>['admin/meta/conversations/{conversation}/profile','POST']] as $name=>[$uri,$method]) {
   $r=$routes->getByName($name);
   if (!$r || $r->uri()!==$uri || !in_array($method,$r->methods(),true) || !in_array('IsAdmin',$r->gatherMiddleware(),true)) throw new \RuntimeException('HUB_ROUTE_INVALID');
  }
  foreach (['meta/index','meta/channels','meta/social-pane','meta/replies','meta/orders','whatsapp/index','whatsapp/chat-pane','whatsapp/replies','whatsapp/orders'] as $view) {
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
        foundations={}
        for rel,pin in PROTECTED_PINS.items():
            original=snapshot(root/rel,uid)
            if original is None or blob(original['bytes'])!=pin: raise RuntimeError('MESSAGE_FOUNDATION_CHANGED: '+rel)
            foundations[rel]=original
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
        # Snapshot equality preserves concurrent operator changes.
        parent=root.parent/'meta-release-backups';safe(parent)
        if parent.exists() and (not parent.is_dir() or parent.stat().st_uid not in (0,uid) or parent.stat().st_mode & 0o022): raise RuntimeError('BACKUP_PARENT_UNSAFE')
        parent.mkdir(mode=0o700,exist_ok=True)
        backup=pathlib.Path(tempfile.mkdtemp(prefix='live-messages-',dir=parent));os.chmod(backup,0o700)
        manifest={};written={}
        for rel,old in originals.items():
            if old:
                file=backup/rel;file.parent.mkdir(mode=0o700,parents=True,exist_ok=True);file.write_bytes(old['bytes']);os.chmod(file,0o600)
                manifest[rel]={k:v for k,v in old.items() if k!='bytes'}
            else: manifest[rel]=None
        (backup/'manifest.json').write_text(json.dumps(manifest));os.chmod(backup/'manifest.json',0o600)
        try:
            for rel,old in {**foundations,**originals}.items():
                if snapshot(root/rel,uid)!=old: raise RuntimeError('CONCURRENT_EDIT: '+rel)
            for rel,payload in targets.items():
                old=originals[rel]
                if old and payload==old['bytes']: continue
                if snapshot(root/rel,uid)!=old: raise RuntimeError('CONCURRENT_EDIT: '+rel)
                atomic(root/rel,payload,old['mode'] if old else 0o644,old['uid'] if old else uid,old['gid'] if old else gid)
                written[rel]=snapshot(root/rel,uid)
            result=run_php(probe,'verify',root,php)
            for rel,old in foundations.items():
                if snapshot(root/rel,uid)!=old: raise RuntimeError('MESSAGE_FOUNDATION_CHANGED: '+rel)
            for rel,published in written.items():
                if snapshot(root/rel,uid)!=published: raise RuntimeError('CONCURRENT_EDIT: '+rel)
            print(json.dumps({'status':'META_LIVE_MESSAGES_READY','updated_files':len(written),'backup':str(backup),'message_poll_ms':1000,'accepted_receipts_ready':True,**result}))
        except BaseException:
            complete=True
            for rel,published in reversed(list(written.items())):
                if snapshot(root/rel,uid)!=published: complete=False;continue
                old=originals[rel]
                if old: atomic(root/rel,old['bytes'],old['mode'],old['uid'],old['gid'])
                else: (root/rel).unlink()
            print('META_LIVE_MESSAGES_ROLLBACK_'+('RESTORED' if complete else 'NEEDS_REVIEW'))
            raise

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--root',required=True);parser.add_argument('--release',required=True);parser.add_argument('--php',default=PHP);args=parser.parse_args()
    try: install(pathlib.Path(args.root).absolute(),args.release,args.php)
    except Exception as error: print(str(error));raise SystemExit(1)
