#!/usr/bin/env python3
"""Publish the fixed WhatsApp dashboard upgrade; preserve unrelated runtime state."""
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time

ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
MIGRATION = 'database/migrations/2026_10_12_000005_create_whatsapp_inbox_hidden_messages.php'
MESSAGE = 'app/Services/Dashboard/WhatsAppInboxMessage.php'
MEDIA = 'app/Services/Dashboard/WhatsAppInboxMedia.php'
VIEWS = {'resources/views/admin/whatsapp/index.blade.php': 'admin.whatsapp.index',
         'resources/views/admin/whatsapp/replies.blade.php': 'admin.whatsapp.replies'}
# Dependency-first publication; route entrypoints precede the two final templates.
SOURCE_PINS = {'database/migrations/2026_10_12_000005_create_whatsapp_inbox_hidden_messages.php': (None,
                                                                                     '699e9b8f63452f4e8f09f8380f3905406ef9ddbf'),
 'app/Services/Dashboard/WhatsAppInboxMessage.php': ('f88843b6abc227c1697476cd9a6217331070f250',
                                                     '087cdbdf51dd87c411da3ed0cfa33980474b593f'),
 'app/Services/Dashboard/WhatsAppInboxMedia.php': ('998efb85b37cb5e26497d82a2f7816513b6435a7',
                                                   'ae1f6aa4d833de80637ea56b19719c649183456d'),
 'app/Services/Dashboard/WhatsAppReplyService.php': ('87d71d1834d9ca4569b922360ca813653d9a5c85',
                                                     'cce92c02d4a4400cd133fc1f7631fb94762f54c6'),
 'app/Services/Dashboard/WhatsAppInboxReadState.php': ('de6303603d52cc9a4f5777e4265cf984d7b7574b',
                                                       'd26eb360f5b5576047edff14808c25950592ba30'),
 'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php': (None,
                                                                       '48af05084619f78480f472e75d30376235e77144'),
 'app/Http/Controllers/Dashboard/WhatsAppReplyController.php': ('5077f194a00cb1c107d3d931cd9fba3d9e28e5b3',
                                                                '7223022bcf5b9acd374ba501a5e17609d9ad05b8'),
 'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': ('f4756907b0aa199a0a5d3a2ab40a1a2f09321756',
                                                                '6f45b964d13504442539ced4c5862dfbbe921381'),
 'routes/whatsapp_replies.php': ('15230fa1267ba6d9dcd4467b6c6ec4686031b41d',
                                 '61a5ea46e5bba6dcd971e1b2bc8b3cbaae3c22e8'),
 'routes/whatsapp_inbox.php': ('b05d9e9b982477cf5873f6d60786ef9458e2c336',
                               '524a38faa203d578abdcc6185e48709036d91e82'),
 'public/js/dashboard-whatsapp-inbox.js': ('3fb1d2f66a1c648a6d2ba2e6df0a9e91c8614ebc',
                                           'cdcd1fddf21988d876d806ddd25fbd532c8f569e'),
 'public/js/dashboard-whatsapp-replies.js': ('43e059064f450db2a56e9ee6668909ab855cb28b',
                                             '392569e73e951405a5de6ce676922e63377a6b0b'),
 'public/js/dashboard-whatsapp-orders.js': ('25a41c3f39ec724094de64fab58e4e0477bd44ac',
                                            'ad7f78fc490774280c31e01125bc262a92a2cb3d'),
 'public/css/dashboard-whatsapp-inbox.css': ('d4e1f32b816da1219b32de856ea484f07cecd1fa',
                                             '639ac2223b4abcd679329000551d9c735e43b5af'),
 'public/css/dashboard-whatsapp-replies.css': ('e957197f0fbb20836dca1743edce0b35d41863ac',
                                               'aaf5d4b8a5c46b6aaaf43824c52ddefca00ba854'),
 'resources/views/admin/whatsapp/replies.blade.php': ('96ac53c7afb01c4aa1eef4b8417192abc024a2ec',
                                                      '6386debd6e20836cc1023cefc7868e214ce7f0f0'),
 'resources/views/admin/whatsapp/index.blade.php': ('d56fe43423728bb9d48820cc51b34522cc1ad7cc',
                                                    '573f95d72e011a814b9b4f13eca256009c50fee8')}
PROTECTED_PINS = {'resources/views/admin/layouts/footer.blade.php': '886f076b7d57dc4d71fd24e63c27bd9fcf34f11e',
 'app/Support/WhatsAppInboxProtocol.php': 'ecde67c6a87ca8068bb077892fdbea522d89f217',
 'app/Services/Dashboard/WhatsAppOrderWorkflow.php': '6c63b91d7fb70130ae472cea291fa2cee88b27dd',
 'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php': '1f0eb40a2b0e6590362beee71e8cbbbcb1d7535f',
 'app/Services/Dashboard/PhoneDelivery.php': '227027cfdcd68720b74220e3ecd0abb9e156a5d7',
 'app/Services/Dashboard/PosServiceTicket.php': 'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
 'app/Http/Controllers/Dashboard/PhoneOrdersController.php': '478776e72edf67d9eaaf00abd49d97a2fa4720c8',
 'public/dashboard/js/phone-orders.js': '66597844cc01d9371b1952f79b22fb1833bf2ac0',
 'app/Services/Dashboard/WhatsAppInboxAccess.php': '186789d42113e81818de8d82a2327e3ee9d8d0b5',
 'app/Services/Dashboard/WhatsAppVoiceMedia.php': '5a6d67a69dbbef185a783680dfe46a1730318380',
 'app/Http/Controllers/Dashboard/WhatsAppInboxReadController.php': 'e893f79a2696aa033ed0cdcd71e4d735cf153b9b',
 'routes/whatsapp_reads.php': 'd2aa9b0819c2f15dc985e24787d30f3c5da92fbb',
 'config/whatsapp_replies.php': 'c5ede60634743a6170b143a4fd4fd382d6afe9d6',
 'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484',
 'app/Services/Dashboard/TakeawayCatalog.php': '49943493fffb5405c87a3001f36c339caa2edf31',
 'app/Services/Dashboard/PhoneMapProvider.php': 'bb360aa46868b432a8a1cf5dff48cdc50b62c860',
 'app/Services/Dashboard/PhoneDeliveryBoard.php': '07fbc51fecfe8faac20c079d560d66c724993766',
 'app/Services/Dashboard/PosBranchPrinting.php': '021a53ff471c594fcef75509327aace74bcbf86c',
 'app/Http/Controllers/Dashboard/PosServiceController.php': '93a3467054dcc03b82c6be99aa5bd4613bcbd4eb',
 'public/dashboard/js/dashboard-location-picker.js': 'e66fc338b8921fa13368673ba4b0c61d96bfdf7a',
 'public/dashboard/js/phone-address-search.js': '4c55ec1f2ff0e4fae4105a96daf5fdc76f440c93',
 'resources/views/admin/phone_orders/index.blade.php': '2902da5d8aaab940640646d496a8a6f8670cf6de',
 'resources/views/admin/phone_orders/batch_receipt.blade.php': '708a574088e7e9e77728efe8fd4ce0d611316bae',
 'resources/views/admin/maps/assets.blade.php': 'd1654a972b5dbba699a0db88af1f038777501c3c',
 'app/Models/Resturant.php': '42f548283bac10bdd0b098e782540e521abfffb2',
 'app/Models/Area.php': '205999cf4ee2dae74960ce725a1aab2632dc2f64',
 'database/migrations/2026_10_10_000003_create_whatsapp_reply_requests.php': 'a01763f04ff6326e952db611f213ab24397a55d2',
 'database/migrations/2026_10_10_000004_create_whatsapp_inbox_reads.php': '2b827af2267e5926348441d065ebfd4fbfc6efd9'}
PROTECTED_PATHS = ('.env',
 'app/Console/Commands/ConsumeWhatsAppInbox.php',
 'app/Console/Commands/ProcessWhatsAppOrders.php',
 'app/Console/Kernel.php',
 'app/Http/Controllers/Dashboard/PhoneOrdersController.php',
 'app/Http/Controllers/Dashboard/PosServiceController.php',
 'app/Http/Controllers/Dashboard/WhatsAppInboxReadController.php',
 'app/Models/Area.php',
 'app/Models/Resturant.php',
 'app/Services/Dashboard/BranchCustomers.php',
 'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php',
 'app/Services/Dashboard/PhoneDelivery.php',
 'app/Services/Dashboard/PhoneDeliveryBoard.php',
 'app/Services/Dashboard/PhoneMapProvider.php',
 'app/Services/Dashboard/PosBranchPrinting.php',
 'app/Services/Dashboard/PosServicePhone.php',
 'app/Services/Dashboard/PosServiceTicket.php',
 'app/Services/Dashboard/TakeawayAccess.php',
 'app/Services/Dashboard/TakeawayCatalog.php',
 'app/Services/Dashboard/TakeawayService.php',
 'app/Services/Dashboard/WhatsAppInboxAccess.php',
 'app/Services/Dashboard/WhatsAppInboxConsumer.php',
 'app/Services/Dashboard/WhatsAppOrderAiProvider.php',
 'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
 'app/Services/Dashboard/WhatsAppVoiceMedia.php',
 'app/Support/WhatsAppInboxProtocol.php',
 'app/Support/WhatsAppOrderExtraction.php',
 'bootstrap/cache/config.php',
 'bootstrap/cache/packages.php',
 'bootstrap/cache/services.php',
 'config/whatsapp.php',
 'config/whatsapp_cart.php',
 'config/whatsapp_orders.php',
 'config/whatsapp_replies.php',
 'database/migrations/2026_10_10_000003_create_whatsapp_reply_requests.php',
 'database/migrations/2026_10_10_000004_create_whatsapp_inbox_reads.php',
 'public/dashboard/js/dashboard-location-picker.js',
 'public/dashboard/js/phone-address-search.js',
 'public/dashboard/js/phone-orders.js',
 'public/js/dashboard-pos-service.js',
 'resources/views/admin/index.blade.php',
 'resources/views/admin/layouts/footer.blade.php',
 'resources/views/admin/layouts/menu.blade.php',
 'resources/views/admin/maps/assets.blade.php',
 'resources/views/admin/phone_orders/batch_receipt.blade.php',
 'resources/views/admin/phone_orders/index.blade.php',
 'resources/views/admin/whatsapp/orders.blade.php',
 'routes/admin.php',
 'routes/whatsapp.php',
 'routes/whatsapp_orders.php',
 'routes/whatsapp_reads.php',
 'vendor/composer/ClassLoader.php',
 'vendor/composer/autoload_classmap.php',
 'vendor/composer/autoload_psr4.php',
 'vendor/composer/autoload_real.php',
 'vendor/composer/autoload_static.php')
LEGACY_READ_ONLY = ('app/Models/Resturant.php', 'app/Models/Area.php')
REASONS = frozenset(('UNSAFE_PATH', 'ANCESTOR_NOT_DIRECTORY_OR_SYMLINK', 'ANCESTOR_OWNER_NEEDS_REVIEW',
    'REQUIRED_FILE_MISSING', 'FILE_NOT_READABLE_OR_SYMLINK', 'FILE_OWNER_OR_TYPE_NEEDS_REVIEW',
    'FILE_TOO_LARGE', 'FILE_CHANGED_DURING_READ', 'FILE_CHANGED_CONCURRENTLY'))
STAGE = 'BOOTSTRAP'


class SafeError(Exception):
    """Only fixed codes and roster paths may reach terminal output."""


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def load_base():
    path = pathlib.Path(__file__).absolute().with_name('install_whatsapp_inbox.py')
    for parent in reversed(path.parents):
        item = parent.lstat()
        if not stat.S_ISDIR(item.st_mode) or item.st_uid not in (0, os.geteuid()):
            raise SafeError('DASHBOARD_BASE_PATH_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        item = os.fstat(fd); data = os.read(fd, 1048577); after = os.fstat(fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid not in (0, os.geteuid()) or item.st_nlink != 1
                or item.st_mode & 0o022 or item.st_size > 1048576
                or (item.st_ino, item.st_size, item.st_mtime_ns, item.st_ctime_ns) !=
                (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns) or blob(data) != BASE_BLOB):
            raise SafeError('DASHBOARD_BASE_HASH_OR_METADATA_UNSAFE')
    finally:
        os.close(fd)
    name = 'verified_dashboard_upgrade_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(data, str(path), 'exec'), module.__dict__)
    return module


base = load_base()


def metadata(path):
    item = path.lstat()
    return (item.st_uid, item.st_gid, stat.S_IMODE(item.st_mode), item.st_dev, item.st_ino,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def source(root, relative, uid, absent=False):
    path = root / relative
    try:
        try: first = metadata(path)
        except FileNotFoundError: first = None
        if relative in LEGACY_READ_ONLY:
            base.safe_ancestors(path, uid, root)
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            try:
                info = os.fstat(fd); data = os.read(fd, 8388609); after = os.fstat(fd)
                if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, uid) or len(data) > 8388608
                        or (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns) !=
                        (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
                    raise SafeError('DASHBOARD_LEGACY_MODEL_METADATA_UNSAFE: ' + relative)
                item = base.Snapshot(data, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev, info.st_ino)
            finally: os.close(fd)
        else: item = base.snapshot(path, uid, root, absent_ok=absent)
        stamp = metadata(path) if item is not None else None
        if first != stamp:
            raise SafeError('DASHBOARD_SOURCE_CHANGED_DURING_READ: ' + relative)
        if item is not None and item.mode & 0o022 and relative not in LEGACY_READ_ONLY:
            raise SafeError('DASHBOARD_LIVE_MODE_UNSAFE: ' + relative)
        return item, stamp
    except base.SafeError as error:
        reason = str(error) if str(error) in REASONS else 'VERIFIED_SOURCE_GUARD_FAILED'
        raise SafeError('DASHBOARD_SOURCE_NEEDS_REVIEW: ' + relative + '; REASON=' + reason) from None


def payload_bytes(path, uid):
    try:
        base.safe_ancestors(path, uid)
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            item = os.fstat(fd); data = os.read(fd, 8388609); after = os.fstat(fd)
            if (not stat.S_ISREG(item.st_mode) or item.st_uid not in (0, uid) or item.st_nlink != 1
                    or item.st_mode & 0o022 or len(data) > 8388608
                    or (item.st_dev, item.st_ino, item.st_size, item.st_mtime_ns, item.st_ctime_ns) !=
                    (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
                raise SafeError('DASHBOARD_PAYLOAD_METADATA_UNSAFE')
            return data
        finally:
            os.close(fd)
    except (base.SafeError, OSError):
        raise SafeError('DASHBOARD_PAYLOAD_UNREADABLE_OR_UNSAFE') from None


def manifest(path, pin, uid):
    if not path.is_absolute() or not re.fullmatch('[a-f0-9]{40}', pin):
        raise SafeError('DASHBOARD_MANIFEST_ARGUMENT_INVALID')
    data = payload_bytes(path, uid)
    if blob(data) != pin:
        raise SafeError('DASHBOARD_MANIFEST_HASH_MISMATCH')
    try:
        value = json.loads(data)
        if set(value) != {'version', 'sources'} or type(value['version']) is not int or value['version'] != 1:
            raise ValueError()
        pins = {}
        for row in value['sources']:
            if set(row) != {'path', 'before_blob', 'after_blob'} or row['path'] in pins:
                raise ValueError()
            if ((row['before_blob'] is not None and not re.fullmatch('[a-f0-9]{40}', row['before_blob']))
                    or not re.fullmatch('[a-f0-9]{40}', row['after_blob'])):
                raise ValueError()
            pins[row['path']] = (row['before_blob'], row['after_blob'])
        if pins != SOURCE_PINS:
            raise ValueError()
    except (ValueError, TypeError, KeyError, AttributeError):
        raise SafeError('DASHBOARD_MANIFEST_SCOPE_INVALID') from None
    return path.parent.parent


def write_private(path, data, mode=0o600):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(data); stream.flush(); os.fchmod(stream.fileno(), mode); os.fsync(stream.fileno())


def command(root, arguments, capture=False, timeout=90):
    process = subprocess.Popen(arguments, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, start_new_session=True)
    try:
        output, _ = process.communicate(timeout=timeout)
    except BaseException:
        try: os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError: pass
        try: process.communicate(timeout=10)
        except subprocess.TimeoutExpired:
            try: os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError: pass
            process.communicate(timeout=10)
        try: os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError: pass
        if not isinstance(sys.exc_info()[1], subprocess.TimeoutExpired): raise
        return 1, b''
    return process.returncode, output if capture and len(output) <= 65536 else b''


COMPILE_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $path=app('view')->getFinder()->find($argv[3]);if($path!==getcwd().'/'.$argv[4])exit(1);
 $compiler=app('blade.compiler');$bytes=file_get_contents($argv[1]);if(!is_string($bytes)||strlen($bytes)>8388608)exit(1);
 $compiled=$compiler->compileString($bytes);$stream=fopen($argv[2],'xb');if(!$stream)exit(1);
 $written=fwrite($stream,$compiled);fflush($stream);fclose($stream);if($written!==strlen($compiled)||!chmod($argv[2],0600))exit(1);
 $cache=$compiler->getCompiledPath($path);$stamp=is_file($cache)?filemtime($cache):0;
 while(ob_get_level()>0)ob_end_clean();echo json_encode(['compiled_mtime'=>$stamp],JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''

READINESS_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 $loader=require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if(!$loader instanceof \Composer\Autoload\ClassLoader)exit(1);
 $prefix=$loader->getPrefixesPsr4()['App\\']??[];$appRoot=false;foreach($prefix as$path)if(realpath($path)===getcwd().'/app')$appRoot=true;
 $classMap=$loader->getClassMap();$mapped=true;
 foreach(['App\\Services\\Dashboard\\WhatsAppInboxMessage'=>'app/Services/Dashboard/WhatsAppInboxMessage.php',
 'App\\Services\\Dashboard\\WhatsAppInboxMedia'=>'app/Services/Dashboard/WhatsAppInboxMedia.php',
 'App\\Http\\Controllers\\Dashboard\\WhatsAppInboxMessageController'=>'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php']as$class=>$relative)
 { $mappedPath=$classMap[$class]??null;
   $canonical=is_string($mappedPath)?realpath($mappedPath):false;
   if($canonical===false&&is_string($mappedPath)){ $parent=realpath(dirname($mappedPath));$canonical=$parent===false?false:$parent.'/'.basename($mappedPath); }
   if($canonical!==getcwd().'/'.$relative)$mapped=false; }
 $autoload=['app_psr4'=>$appRoot,'authoritative'=>$loader->isClassMapAuthoritative(),'new_classes_mapped'=>$mapped];
 $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
 $route=$app->getCachedRoutesPath();if(!is_string($route)||!in_array($route,[getcwd().'/bootstrap/cache/routes-v7.php',getcwd().'/bootstrap/cache/routes.php'],true))exit(1);
 $tables=['whatsapp_inbox_hidden_messages','whatsapp_reply_projection_keys','whatsapp_reply_reaction_targets'];$metadata=[];
 if($db::connection()->getDriverName()!=='mysql')exit(1);
 foreach($tables as$table){
  $exists=$schema::hasTable($table);$columns=[];$indexes=[];$foreign=[];
  if($exists){
   foreach($db::select('SELECT COLUMN_NAME name,DATA_TYPE type,IS_NULLABLE nullable,CHARACTER_MAXIMUM_LENGTH length,CHARACTER_SET_NAME charset,EXTRA extra,COLUMN_TYPE definition FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$db::connection()->getDatabaseName(),$table])as$c){
    $columns[]=['name'=>$c->name,'type'=>$c->type,'nullable'=>$c->nullable==='YES','length'=>$c->length===null?null:(int)$c->length,'charset'=>$c->charset,'auto'=>strpos($c->extra,'auto_increment')!==false,'unsigned'=>strpos($c->definition,'unsigned')!==false];
   }
   foreach($db::select('SHOW INDEX FROM `'.$table.'`')as$i){$name=$i->Key_name;$indexes[$name]['unique']=!(bool)$i->Non_unique;$indexes[$name]['columns'][(int)$i->Seq_in_index]=$i->Column_name;}
   foreach($indexes as&$i){ksort($i['columns']);$i['columns']=array_values($i['columns']);}unset($i);
   foreach($db::select('SELECT k.CONSTRAINT_NAME name,k.COLUMN_NAME column_name,k.REFERENCED_TABLE_NAME target,k.REFERENCED_COLUMN_NAME target_column,r.DELETE_RULE delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL',[$db::connection()->getDatabaseName(),$table])as$f){
    $foreign[$f->name]=['column'=>$f->column_name,'target'=>$f->target,'target_column'=>$f->target_column,'delete'=>$f->delete_rule];
   }
  }
  $metadata[$table]=['exists'=>$exists,'columns'=>$columns,'indexes'=>$indexes,'foreign_keys'=>$foreign];
 }
 $name='2026_10_12_000005_create_whatsapp_inbox_hidden_messages';
 if(!$schema::hasTable('migrations'))exit(1);
 $migrations=$db::table('migrations')->orderBy('id')->get(['id','migration','batch'])->toArray();
 $fingerprint=hash('sha256',serialize(config()->all()));
 while(ob_get_level()>0)ob_end_clean();echo json_encode(['config_fingerprint'=>$fingerprint,'route_path'=>$route,
  'route_cached'=>$app->routesAreCached(),'tables'=>$metadata,'migrations'=>$migrations,'autoload'=>$autoload],JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''

POSTFLIGHT_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $classes=[\App\Services\Dashboard\WhatsAppInboxMessage::class,\App\Services\Dashboard\WhatsAppInboxMedia::class,
  \App\Http\Controllers\Dashboard\WhatsAppInboxMessageController::class];
 foreach($classes as$class){$r=new \ReflectionClass($class);
  $expected=getcwd().'/app/'.str_replace('\\','/',substr($class,4)).'.php';
  if(!$r->isInstantiable()||realpath($r->getFileName())!==$expected)exit(1);}
 $routes=['whatsapp-inbox.updates'=>'GET','whatsapp-replies.history'=>'GET','whatsapp-inbox.selection'=>'GET','whatsapp-inbox.delete-selected'=>'POST','whatsapp-inbox.media'=>'GET',
 'whatsapp-inbox.reply-media'=>'GET','whatsapp-inbox.delete'=>'POST','whatsapp-replies.forward'=>'POST',
 'whatsapp-replies.reaction'=>'POST','whatsapp-replies.forward-reply'=>'POST','whatsapp-replies.reaction-reply'=>'POST',
 'whatsapp-replies.attachment'=>'POST','whatsapp-replies.attachment-download'=>'GET'];
 foreach($routes as$name=>$method){$route=app('router')->getRoutes()->getByName($name);
  if(!$route||!in_array($method,$route->methods(),true)||!in_array('IsAdmin',$route->gatherMiddleware(),true)
   ||!in_array('web',$route->gatherMiddleware(),true))exit(1);}
 if(app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->canAccess(null))exit(1);
 while(ob_get_level()>0)ob_end_clean();echo 'DASHBOARD_ROUTE_CONTRACT_READY';
}catch(\Throwable $e){exit(1);}
'''

BACKFILL_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $class=\App\Services\Dashboard\WhatsAppInboxMessage::class;
 if(!class_exists($class,false))require_once $argv[1];
 $reflection=new \ReflectionClass($class);$bytes=file_get_contents($reflection->getFileName());
 if(!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$argv[2])exit(1);
 $result=(new $class)->backfillProjectionIndex(200);
 while(ob_get_level()>0)ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''


TABLE_COLUMNS = {
    'whatsapp_inbox_hidden_messages': {
        'id': ('bigint', False, None, True, True), 'actor_id': ('bigint', False, None, False, True),
        'conversation_id': ('bigint', False, None, False, True), 'message_id': ('bigint', True, None, False, True),
        'reply_id': ('bigint', True, None, False, True), 'item_key': ('varchar', False, 32, False, False),
        'native_message_key': ('char', True, 64, False, False), 'message_cutoff': ('bigint', True, None, False, True),
        'projection_cutoff': ('bigint', True, None, False, True), 'created_at': ('timestamp', True, None, False, False),
        'updated_at': ('timestamp', True, None, False, False)},
    'whatsapp_reply_projection_keys': {
        'id': ('bigint', False, None, True, True), 'reply_id': ('bigint', False, None, False, True),
        'conversation_id': ('bigint', False, None, False, True), 'waba_id': ('varchar', False, 32, False, False),
        'phone_number_id': ('varchar', False, 32, False, False), 'kind': ('varchar', False, 16, False, False),
        'message_key': ('char', True, 64, False, False), 'receipt_hash': ('char', False, 64, False, False),
        'valid': ('tinyint', False, None, False, False), 'created_at': ('timestamp', True, None, False, False),
        'updated_at': ('timestamp', True, None, False, False)},
}
TABLE_COLUMNS['whatsapp_reply_reaction_targets'] = {
    'reply_id': ('bigint', False, None, False, True), 'conversation_id': ('bigint', False, None, False, True),
    'waba_id': ('varchar', False, 32, False, False), 'phone_number_id': ('varchar', False, 32, False, False),
    'actor_id': ('bigint', False, None, False, True), 'message_id': ('bigint', True, None, False, True),
    'target_reply_id': ('bigint', True, None, False, True), 'message_key': ('char', True, 64, False, False),
    'valid': ('tinyint', False, None, False, False), 'emoji': ('varchar', False, 32, False, False),
    'accepted_at': ('timestamp', False, None, False, False), 'receipt_hash': ('char', False, 64, False, False),
    'created_at': ('timestamp', True, None, False, False), 'updated_at': ('timestamp', True, None, False, False),
}
TABLE_INDEXES = {
    'whatsapp_inbox_hidden_messages': [(True, ['id']), (True, ['actor_id', 'conversation_id', 'item_key']),
        (False, ['actor_id', 'conversation_id']), (False, ['actor_id', 'conversation_id', 'native_message_key'])],
    'whatsapp_reply_projection_keys': [(True, ['id']), (True, ['reply_id']),
        (True, ['waba_id', 'phone_number_id', 'message_key']), (False, ['conversation_id', 'reply_id'])],
}
TABLE_INDEXES['whatsapp_reply_reaction_targets'] = [(True, ['reply_id']), (False, ['conversation_id', 'message_key', 'accepted_at', 'reply_id'])]
TABLE_FOREIGN = {
    'whatsapp_inbox_hidden_messages': {'wa_hidden_message_fk': ('message_id', 'whatsapp_inbox_messages'),
        'wa_hidden_thread_fk': ('conversation_id', 'whatsapp_inbox_conversations'), 'wa_hidden_reply_fk': ('reply_id', 'whatsapp_reply_requests')},
    'whatsapp_reply_projection_keys': {'wa_projection_reply_fk': ('reply_id', 'whatsapp_reply_requests'),
        'wa_projection_thread_fk': ('conversation_id', 'whatsapp_inbox_conversations')},
}


TABLE_FOREIGN['whatsapp_reply_reaction_targets'] = {'wa_reaction_request_fk': ('reply_id', 'whatsapp_reply_requests'), 'wa_reaction_thread_fk': ('conversation_id', 'whatsapp_inbox_conversations')}


def validate_schema(value, required=False):
    try:
        if set(value['tables']) != set(TABLE_COLUMNS): raise ValueError()
        for table, expected in TABLE_COLUMNS.items():
            row = value['tables'][table]
            if set(row) != {'exists', 'columns', 'indexes', 'foreign_keys'} or type(row['exists']) is not bool: raise ValueError()
            if not row['exists']:
                if required or row['columns'] or row['indexes'] or row['foreign_keys']: raise ValueError()
                continue
            columns = {}
            for c in row['columns']:
                if set(c) != {'name', 'type', 'nullable', 'length', 'auto', 'unsigned', 'charset'} or c['name'] in columns: raise ValueError()
                if any(type(c[k]) is not bool for k in ('nullable', 'auto', 'unsigned')): raise ValueError()
                if c['charset'] is not None and (not isinstance(c['charset'], str) or not re.fullmatch('[A-Za-z0-9_]{1,32}', c['charset'])): raise ValueError()
                if table == 'whatsapp_reply_reaction_targets' and c['name'] == 'emoji' and c['charset'] != 'utf8mb4': raise ValueError()
                columns[c['name']] = (c['type'], c['nullable'], c['length'], c['auto'], c['unsigned'])
            if columns != expected: raise ValueError()
            indexes = row['indexes']
            for index in indexes.values():
                if (set(index) != {'unique', 'columns'} or type(index['unique']) is not bool
                        or not isinstance(index['columns'], list) or not index['columns']
                        or any(name not in expected for name in index['columns'])): raise ValueError()
            if ({tuple(i['columns']) for i in indexes.values() if i['unique']}
                    != {tuple(names) for unique, names in TABLE_INDEXES[table] if unique}): raise ValueError()
            for unique, names in TABLE_INDEXES[table]:
                if not any(i == {'unique': unique, 'columns': names} for i in indexes.values()): raise ValueError()
            if set(row['foreign_keys']) != set(TABLE_FOREIGN[table]): raise ValueError()
            for name, (column, target) in TABLE_FOREIGN[table].items():
                f = row['foreign_keys'][name]
                if (set(f) != {'column', 'target', 'target_column', 'delete'} or f['column'] != column or f['target'] != target
                        or f['target_column'] != 'id' or f['delete'] not in ('RESTRICT', 'NO ACTION')): raise ValueError()
        rows = value['migrations']; ids = set()
        for row in rows:
            if (set(row) != {'id', 'migration', 'batch'} or type(row['id']) is not int or row['id'] < 1 or row['id'] in ids
                    or type(row['batch']) is not int or row['batch'] < 1 or not isinstance(row['migration'], str)
                    or not re.fullmatch('[A-Za-z0-9_]{1,180}', row['migration'])): raise ValueError()
            ids.add(row['id'])
    except (TypeError, KeyError, AttributeError, ValueError):
        raise SafeError('DASHBOARD_METADATA_SCHEMA_NEEDS_REVIEW') from None


def migration_preserved(initial, final):
    name = pathlib.Path(MIGRATION).stem
    before = [row for row in initial['migrations'] if row['migration'] != name]
    after = [row for row in final['migrations'] if row['migration'] != name]
    records = [row for row in final['migrations'] if row['migration'] == name]
    if before != after or len(records) != 1:
        raise SafeError('DASHBOARD_UNRELATED_MIGRATIONS_CHANGED')
    old = [row for row in initial['migrations'] if row['migration'] == name]
    if old and records != old: raise SafeError('DASHBOARD_METADATA_MIGRATION_RECORD_CHANGED')
    validate_schema(final, required=True)


def readiness(root, runner):
    code, output = runner(root, [PHP, '-r', READINESS_PHP], capture=True)
    try:
        value = json.loads(output)
        if (code != 0 or set(value) != {'config_fingerprint', 'route_path', 'route_cached', 'tables', 'migrations', 'autoload'}
                or not re.fullmatch('[a-f0-9]{64}', value['config_fingerprint']) or type(value['route_cached']) is not bool
                or value['route_path'] not in (str(root / 'bootstrap/cache/routes-v7.php'), str(root / 'bootstrap/cache/routes.php'))
                or not isinstance(value['tables'], dict) or not isinstance(value['migrations'], list)):
            raise ValueError()
    except (ValueError, TypeError, KeyError):
        raise SafeError('DASHBOARD_READ_ONLY_SCHEMA_OR_CACHE_CHECK_FAILED') from None
    validate_schema(value)
    try:
        autoload=value['autoload']
        if set(autoload)!= {'app_psr4','authoritative','new_classes_mapped'} or any(type(v)is not bool for v in autoload.values()):raise ValueError()
        if autoload['authoritative'] and not autoload['new_classes_mapped']:
            raise SafeError('DASHBOARD_AUTHORITATIVE_CLASSMAP_NEEDS_REVIEW')
        if not autoload['app_psr4'] and not autoload['new_classes_mapped']:
            raise SafeError('DASHBOARD_APP_PSR4_MAPPING_NEEDS_REVIEW')
    except (ValueError,TypeError,KeyError):raise SafeError('DASHBOARD_AUTOLOAD_PREFLIGHT_FAILED') from None
    return value


def backfill(root, payload, runner):
    totals = {'processed': 0, 'valid': 0, 'invalid': 0}
    for _ in range(1000):
        code, output = runner(root, [PHP, '-r', BACKFILL_PHP, str(payload / MESSAGE), SOURCE_PINS[MESSAGE][1]], capture=True)
        try:
            value = json.loads(output)
            if (code != 0 or set(value) != {'processed', 'valid', 'invalid', 'remaining', 'complete'}
                    or any(type(value[key]) is not int or value[key] < 0 for key in ('processed', 'valid', 'invalid', 'remaining'))
                    or type(value['complete']) is not bool or value['processed'] != value['valid'] + value['invalid']
                    or value['processed'] > 200 or value['complete'] != (value['remaining'] == 0)):
                raise ValueError()
        except (ValueError, TypeError, KeyError):
            raise SafeError('DASHBOARD_PROJECTION_INDEX_BACKFILL_NEEDS_REVIEW') from None
        for key in totals: totals[key] += value[key]
        if value['complete']: return totals
        if value['processed'] == 0:
            raise SafeError('DASHBOARD_PROJECTION_INDEX_BACKFILL_NO_PROGRESS')
    raise SafeError('DASHBOARD_PROJECTION_INDEX_BACKFILL_LIMIT_REACHED')


def profile(root, uid):
    result = {}
    for relative in PROTECTED_PATHS:
        item, stamp = source(root, relative, uid, absent=relative not in PROTECTED_PINS and relative not in ('.env', 'bootstrap/cache/config.php'))
        if relative in PROTECTED_PINS and (item is None or blob(item.content) != PROTECTED_PINS[relative]):
            raise SafeError('DASHBOARD_PROTECTED_HASH_NEEDS_REVIEW: ' + relative)
        result[relative] = (item, stamp)
    return result


def stamp_view(root, relative, item, uid, stamp):
    base.assert_snapshot(root / relative, item, uid, root)
    fd = os.open(root / relative, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if info.st_ino != item.inode or info.st_dev != item.device:
            raise SafeError('DASHBOARD_VIEW_CHANGED_CONCURRENTLY: ' + relative)
        os.utime(fd, ns=(info.st_atime_ns, max(time.time_ns(), (stamp + 1) * 1000000000, info.st_mtime_ns)))
    finally:
        os.close(fd)


def restore_sources(root, uid, journal, before, backup, created, stamps, committed, entry_guard, finish_entrypoint):
    """Restore owned versions; re-publish owned dependencies if a peer entrypoint appears."""
    restored_targets = {}
    def retain_dependency_set():
        # Compensation is dependency-first and CAS-only. Never overwrite peer files.
        for relative, expected in reversed(list(restored_targets.items())):
            publication = journal[relative]; repair = {}
            try:
                if source(root, relative, uid, absent=True) != expected: return False
                base.replace_file(root / relative, publication.after.content, expected[0], uid, root, created,
                    restore=publication.after, journal=repair, relative=relative, private_directory=backup / 'publications')
                for directory, inode in created:
                    try:
                        if directory.lstat().st_ino == inode: directory.chmod(0o755)
                    except FileNotFoundError: pass
                if relative in stamps:
                    stamp_view(root, relative, repair[relative].after, uid, max(stamps[relative], int(time.time())))
            except BaseException:
                if relative in repair:
                    try: base.recover_publication(repair[relative], root, uid)
                    except BaseException: pass
                return False
        return True
    def stop_restore():
        retain_dependency_set()
        return False
    with base.blocked_signals():
        for relative, publication in reversed(list(journal.items())):
            try:
                if not entry_guard():
                    return stop_restore()
                current = base.snapshot(root / relative, uid, root, absent_ok=True)
                if current == publication.before and relative not in committed: continue
                if current != publication.after:
                    return stop_restore()
                old = before[relative]
                if relative not in committed:
                    if not base.recover_publication(publication, root, uid):
                        return stop_restore()
                    expected_restored = publication.before
                elif old is None:
                    if not base.recover_publication(publication, root, uid):
                        return stop_restore()
                    expected_restored = None
                else:
                    saved = backup / ('before-' + str(list(SOURCE_PINS).index(relative)))
                    if payload_bytes(saved, uid) != old.content: return stop_restore()
                    restored = base.replace_file(root / relative, old.content, publication.after, uid, root, created,
                        restore=old, private_directory=backup / 'publications')
                    expected_restored = restored.after
                    if relative in stamps:
                        stamp_view(root, relative, restored.after, uid, max(stamps[relative], int(time.time())))
                observed = source(root, relative, uid, absent=True)
                if observed[0] != expected_restored:
                    return stop_restore()
                restored_targets[relative] = (expected_restored, observed[1])
                if not entry_guard():
                    return stop_restore()
            except BaseException: return stop_restore()
        if not entry_guard():
            return stop_restore()
        for path, inode in reversed(created):
            try:
                if path.lstat().st_ino == inode: path.rmdir()
            except OSError: pass
        if not finish_entrypoint():
            return stop_restore()
    return True


def apply(root, uid, manifest_path, manifest_pin, runner=command):
    global STAGE
    STAGE = 'ROOT'
    try: base.validate_root(root, uid)
    except base.SafeError as error:
        allowed = REASONS | {'PROJECT_ROOT_OWNER_OR_TYPE_NEEDS_REVIEW', 'GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW'}
        reason = str(error) if str(error) in allowed else 'VERIFIED_ROOT_GUARD_FAILED'
        raise SafeError('DASHBOARD_ROOT_NEEDS_REVIEW; REASON=' + reason) from None
    STAGE = 'MANIFEST'; payload = manifest(manifest_path, manifest_pin, uid)
    if payload == root or root in payload.parents:
        raise SafeError('DASHBOARD_PAYLOAD_MUST_BE_EXTERNAL')
    parent = root.parent / 'whatsapp-release-backups'; base.private_parent(parent, uid)
    fd = os.open(parent / 'inbox-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600:
            raise SafeError('DASHBOARD_INSTALL_LOCK_UNSAFE')
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise SafeError('DASHBOARD_ANOTHER_INSTALL_RUNNING') from None
        STAGE = 'SOURCES'; before = {}; signatures = {}; candidates = {}
        for relative, (old, target) in SOURCE_PINS.items():
            data = payload_bytes(payload / relative, uid)
            if blob(data) != target: raise SafeError('DASHBOARD_PAYLOAD_HASH_MISMATCH: ' + relative)
            item, stamp = source(root, relative, uid, absent=old is None)
            if (item is None and old is not None) or (item is not None and blob(item.content) not in (old, target)):
                raise SafeError('DASHBOARD_LIVE_HASH_NEEDS_REVIEW: ' + relative)
            before[relative] = item; signatures[relative] = stamp; candidates[relative] = data
        preserved = profile(root, uid)
        STAGE = 'PREFLIGHT'; initial = readiness(root, runner)
        route_relative = str(pathlib.Path(initial['route_path']).relative_to(root))
        route_before, route_stamp = source(root, route_relative, uid, absent=True)
        if initial['route_cached'] != (route_before is not None):
            raise SafeError('DASHBOARD_ROUTE_CACHE_CHANGED_BEFORE_INSTALL')
        STAGE = 'BACKUP'; backup = pathlib.Path(tempfile.mkdtemp(prefix='dashboard-ui-', dir=parent)); backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev: raise SafeError('DASHBOARD_BACKUP_REQUIRES_SAME_FILESYSTEM')
        publications = backup / 'publications'; publications.mkdir(mode=0o700); base.probe_atomic_exchange(publications)
        stamps = {}
        for index, (relative, data) in enumerate(candidates.items()):
            if before[relative] is not None: write_private(backup / ('before-' + str(index)), before[relative].content, 0o400)
            candidate = backup / ('candidate-' + str(index) + pathlib.Path(relative).suffix); write_private(candidate, data)
            if relative in VIEWS:
                compiled = backup / ('compiled-' + str(index) + '.php')
                code, output = runner(root, [PHP, '-r', COMPILE_PHP, str(candidate), str(compiled), VIEWS[relative], relative], capture=True)
                try:
                    value = json.loads(output); stamp = value['compiled_mtime']
                    if code != 0 or set(value) != {'compiled_mtime'} or type(stamp) is not int or stamp < 0: raise ValueError()
                except (ValueError, TypeError, KeyError): raise SafeError('DASHBOARD_BLADE_COMPILE_FAILED: ' + relative) from None
                stamps[relative] = stamp; code, _ = runner(root, [PHP, '-l', str(compiled)])
            elif relative.endswith('.php'): code, _ = runner(root, [PHP, '-l', str(candidate)])
            elif relative.endswith('.js'):
                node = shutil.which('node'); code, _ = runner(root, [node, '--check', str(candidate)]) if node else (0, b'')
            else: code = 0
            if code != 0: raise SafeError('DASHBOARD_CANDIDATE_LINT_FAILED: ' + relative)
        write_private(backup / 'database-before.json', (json.dumps({'tables': initial['tables'], 'migrations': initial['migrations']}, sort_keys=True) + '\n').encode(), 0o400)
        if route_before is not None: write_private(backup / 'routes-before.php', route_before.content, 0o400)
        write_private(backup / 'receipt.json', (json.dumps({'version': 1, 'files': {
            key: {'before_blob': blob(value.content) if value else None, 'after_blob': blob(candidates[key]),
                'uid': value.uid if value else uid, 'gid': value.gid if value else os.getegid(), 'mode': value.mode if value else 0o644}
            for key, value in before.items()}, 'route_cache': route_relative, 'route_was_cached': route_before is not None}, sort_keys=True) + '\n').encode(), 0o400)
        journal = {}; created = []; committed = set(); final_signatures = {}; route_displaced = None; route_saved_stamp = None
        def unchanged():
            if profile(root, uid) != preserved: raise SafeError('DASHBOARD_PROTECTED_FILE_CHANGED')
            for relative, item in before.items():
                if source(root, relative, uid, absent=item is None) != (item, signatures[relative]):
                    raise SafeError('DASHBOARD_SOURCE_CHANGED_CONCURRENTLY: ' + relative)
        try:
            STAGE = 'MIGRATION'; unchanged()
            migration_name = pathlib.Path(MIGRATION).stem
            recorded = [row for row in initial['migrations'] if row.get('migration') == migration_name]
            exists = [value['exists'] for value in initial['tables'].values()]
            if len(recorded) > 1 or (any(exists) and not (all(exists) and len(recorded) == 1)) or (recorded and not all(exists)):
                raise SafeError('DASHBOARD_METADATA_SCHEMA_NEEDS_REVIEW')
            if not recorded:
                code, _ = runner(root, [PHP, 'artisan', 'migrate', '--path=' + str(payload / MIGRATION), '--realpath', '--force'], timeout=180)
                if code != 0: raise SafeError('DASHBOARD_METADATA_MIGRATION_FAILED')
            migrated = readiness(root, runner)
            if migrated['config_fingerprint'] != initial['config_fingerprint'] or not all(value['exists'] for value in migrated['tables'].values()):
                raise SafeError('DASHBOARD_METADATA_MIGRATION_POSTFLIGHT_FAILED')
            migration_preserved(initial, migrated)
            write_private(backup / 'database-after.json', (json.dumps({'tables': migrated['tables'], 'migrations': migrated['migrations']}, sort_keys=True) + '\n').encode(), 0o400)
            STAGE = 'BACKFILL'; totals = backfill(root, payload, runner)
            unchanged()
            if source(root, route_relative, uid, absent=True) != (route_before, route_stamp):
                raise SafeError('DASHBOARD_ROUTE_CACHE_CHANGED_CONCURRENTLY')
            if route_before is not None:
                STAGE = 'ROUTE_CACHE'; route_displaced = publications / 'routes-displaced.php'
                base.atomic_move_noreplace(root / route_relative, route_displaced)
                saved_route = source(backup, 'publications/routes-displaced.php', uid)
                if saved_route[0] != route_before or saved_route[1][:-1] != route_stamp[:-1]:
                    raise SafeError('DASHBOARD_ROUTE_CACHE_CHANGED_DURING_REMOVAL')
                route_saved_stamp = saved_route[1]
            STAGE = 'PUBLICATION'
            for relative, old in before.items():
                if old is None or old.content != candidates[relative]:
                    base.replace_file(root / relative, candidates[relative], old, uid, root, created,
                        journal=journal, relative=relative, private_directory=publications)
                    committed.add(relative)
                    for directory, inode in created:
                        if directory.lstat().st_ino != inode: raise SafeError('DASHBOARD_CREATED_DIRECTORY_CHANGED')
                        directory.chmod(0o755)
                    if relative in stamps: stamp_view(root, relative, journal[relative].after, uid, stamps[relative])
                final_signatures[relative] = source(root, relative, uid)[1]
            STAGE = 'POSTFLIGHT'; backfill(root, payload, runner)
            code, output = runner(root, [PHP, '-r', POSTFLIGHT_PHP], capture=True)
            if code != 0 or output != b'DASHBOARD_ROUTE_CONTRACT_READY': raise SafeError('DASHBOARD_ROUTE_CONTRACT_POSTFLIGHT_FAILED')
            final = readiness(root, runner)
            if final['config_fingerprint'] != initial['config_fingerprint'] or final['route_cached']:
                raise SafeError('DASHBOARD_CONFIGURATION_OR_ROUTE_CACHE_CHANGED')
            migration_preserved(migrated, final)
            if profile(root, uid) != preserved: raise SafeError('DASHBOARD_PROTECTED_FILE_CHANGED')
            for relative, old in before.items():
                expected = journal[relative].after if relative in journal else old
                if source(root, relative, uid) != (expected, final_signatures[relative]):
                    raise SafeError('DASHBOARD_PUBLISHED_SOURCE_CHANGED_CONCURRENTLY: ' + relative)
        except BaseException as error:
            # A peer route-cache can reference every newly published dependency.
            # Establish entrypoint safety before removing any of that dependency set.
            def entry_guard():
                try:
                    current_route = source(root, route_relative, uid, absent=True)
                    expected_route = (None, None) if route_displaced is not None else (route_before, route_stamp)
                    if current_route != expected_route: return False
                    return route_displaced is None or source(backup, 'publications/routes-displaced.php', uid) == (route_before, route_saved_stamp)
                except BaseException: return False
            def finish_entrypoint():
                if not entry_guard(): return False
                if route_displaced is None: return entry_guard()
                try: base.atomic_move_noreplace(route_displaced, root / route_relative)
                except BaseException:
                    # An exception after the syscall is classified by retained inodes.
                    try:
                        return (not route_displaced.exists() and source(root, route_relative, uid)[0] == route_before)
                    except BaseException: return False
                try:
                    restored_route = source(root, route_relative, uid)
                    return restored_route[0] == route_before and restored_route[1][:-1] == route_stamp[:-1]
                except BaseException: return False
            restored = restore_sources(root, uid, journal, before, backup, created, stamps, committed, entry_guard, finish_entrypoint) if entry_guard() else False
            reason = str(error) if isinstance(error, SafeError) else 'DASHBOARD_UPGRADE_INTERRUPTED'
            status = 'FILES_RESTORED' if restored else 'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'
            raise SafeError(reason + '; ' + status + '; METADATA_TABLES_RETAINED; BACKUP=' + str(backup)) from None
        return 'WHATSAPP_DASHBOARD_UPGRADE_READY', backup, totals
    finally:
        os.close(fd)


def main():
    try:
        args = sys.argv[1:]
        if (len(args) != 6 or args[0] != '--root' or args[2] != '--manifest' or args[4] != '--manifest-blob'
                or pathlib.Path(args[1]) != ROOT or os.geteuid() != pwd.getpwnam('fasakha').pw_uid):
            raise SafeError('RUN_DASHBOARD_UPGRADE_AS_FASAKHA_WITH_ROOT_MANIFEST_BLOB')
        os.umask(0o077)
        signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(SafeError('DASHBOARD_UPGRADE_INTERRUPTED')))
        status, backup, totals = apply(ROOT, os.geteuid(), pathlib.Path(args[3]), args[5])
        print(status); print('BACKUP=' + str(backup)); print('PROJECTION_INDEX=' + json.dumps(totals, sort_keys=True)); return 0
    except SafeError as error: print(str(error), file=sys.stderr)
    except BaseException as error:
        name = type(error).__name__; name = name if re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}', name) else 'UNKNOWN'
        print('DASHBOARD_UPGRADE_STOPPED; STAGE=' + STAGE + '; EXCEPTION_CLASS=' + name, file=sys.stderr)
    return 1


if __name__ == '__main__': sys.exit(main())
