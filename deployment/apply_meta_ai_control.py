#!/usr/bin/env python3
"""Publish the reviewed AI control and one disabled policy schema; never process messages."""
import contextlib
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import sys
import tempfile

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
PREFIX = 'app/Services/Dashboard/'
# Publish pure support/dependencies before consumers and the workflow entrypoint.
# Existing configuration, activation, cutoffs and order/reply rows are preserved.
# Only the declared new disabled policy schema may be created by its pinned migration.
SOURCE_PINS = {
    'app/Support/MetaAiSchedule.php': (None, '739244b40c1115105a72c42534eba37d696bf4fe'),
    'app/Services/Dashboard/MetaBusinessAgent.php': (None, '4b2ad29c21ea092af94ad3fa1bace90ad4dd4672'),
    'app/Services/Dashboard/MetaAiControl.php': (None, '66df1b61f9d47050a0d3ce870b560589f4e01824'),
    'app/Support/WhatsAppOrderDialogue.php': (None, '33253285d24ba7110f58744cad9c3a87e1bef06a'),
    'app/Support/WhatsAppOrderExtraction.php': ('56d2b9575da152de3eac4876fd93140ee7bc9e39', '1b518728c5f63db4ba1ef84149fb0aafec02a98a'),
    'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php': ('1bd223861e3772e10658df2d555b4bb040243a4a', 'bfe69bb72cc586c68823463fd3f1885915774d02'),
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php': ('50dba4e16e2bec8811b6f37c3a6becf428d61a52', 'b7b2797f1aad6747d1e4a34e546e4f6421c99a4c'),
    'app/Services/Dashboard/WhatsAppOrderFollowupService.php': ('919e1e1407792cfa0caefd868e1b3c79284e8a3c', 'be38be4938cf0fb6725baf38ebd4fcb69e6d692f'),
    'app/Services/Dashboard/MetaOrderNativeEvidence.php': (None, 'f11710e1439867ba63571a690526496684120ef5'),
    'app/Services/Dashboard/MetaOrderFollowupService.php': (None, 'd14a3c6e84624ee5b2d20997b7f11159b9101720'),
    'app/Services/Dashboard/MetaMediaService.php': ('bc97cf1170ef0e4aedb06cfb1a8954522f831ce4', 'cfde72fdeb12f308e15daa81ec0ae7ce196aef9e'),
    'app/Services/Dashboard/MetaReplyService.php': ('84c686bba5acc0dd3696015f09854aae3f8c4448', 'c4226df516aa5f8b59999a8979f9f2ab43cf0e28'),
    'app/Services/Dashboard/WhatsAppOrderWorkflow.php': ('e2b558a5de68080b85b12189e73239190aace507', 'ee4263c6b7bd827d9bf481db237779c189782b40'),
    'app/Services/Dashboard/MetaOrderWorkflow.php': ('e13362de65706f9ee5e5dab8acc24220bca5cdb0', 'd6cbf86c96ad44a0e0a65c6894085500594ced8f'),
    'app/Services/Dashboard/MetaInboxConsumer.php': ('f7dda06f617de711a3f6a363edda0185e3973794', '3b7e9cb9abfabc85c0b78199d0bf9d2565424f42'),
    'app/Http/Controllers/Dashboard/MetaAiControlController.php': (None, '3cb87c03aaabdd7b1bb01f047caf201649c8db6f'),
    'app/Console/Commands/ProcessMetaAiMessages.php': (None, 'b5b8bd556cab89ef0f44f6d1e8acff718f150a4f'),
    'database/migrations/2026_10_15_000001_create_meta_ai_controls.php': (None, '3c6c7ef4d5fbf437f49cc7c2bb94ce82595b77ae'),
    'resources/views/admin/meta/ai_controls.blade.php': (None, '9a560c33fd4d3f1a87390a56a72a372396db72fe'),
    'public/js/dashboard-meta-ai-controls.js': (None, 'fc34fca42cf8dd80f7916bacc1780444a51afab4'),
    'public/css/dashboard-meta-ai-controls.css': (None, '293c1db7c3a4c3dc2720fd9216c450ac172c37e8'),
    'routes/meta_ai_controls.php': (None, '8a89374a33002b88f7ed593d6aab5817aa3cf2a4'),
    'resources/views/admin/meta/channels.blade.php': ('6c15d00e98405f2fa13cb4583b51bee175caeccf', 'c922fd431ca3df5b12f6aeef05c0885bc0da3049'),
    'routes/meta_inbox.php': ('2893265daae77c55f73f33e887bc953c619172d5', '2b5f992b8392be450a032d71d5a625a8aeed822e'),
}
BASELINE_SOURCE_PROFILES = {
    'WHATSAPP_TAIL': {path: pair[0] for path,pair in SOURCE_PINS.items()},
    'WHATSAPP_CONTEXTUAL': dict({path: pair[0] for path,pair in SOURCE_PINS.items()}, **{'app/Support/WhatsAppOrderDialogue.php': '1f689612ba2de4c1fd4ef5a678775809ca579cfe', 'app/Support/WhatsAppOrderExtraction.php': '1b518728c5f63db4ba1ef84149fb0aafec02a98a', 'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php': 'bfe69bb72cc586c68823463fd3f1885915774d02', 'app/Services/Dashboard/WhatsAppOrderAiProvider.php': 'b28733a0d013ead2628391af851f9793dc232f3c', 'app/Services/Dashboard/WhatsAppOrderFollowupService.php': '0086777e10fd0e88726f849cb571abcd4c76d1e1', 'app/Services/Dashboard/WhatsAppOrderWorkflow.php': '7e5fc1c8ee920ff5322702ff6aac3a862e3b06d9'}),
}
# The shipped message hub changes exactly these two publication targets. Bind
# each complete source profile to its matching read-only controller foundation.
HUB_SOURCE_OVERRIDES = {
    'app/Services/Dashboard/MetaReplyService.php': '8786a2099281188db1d20d7c6e23a429a97a6cff',
    'resources/views/admin/meta/channels.blade.php': '490d12487f274627ed29ed5c44042fb466a6716e',
}
for baseline in ('WHATSAPP_TAIL','WHATSAPP_CONTEXTUAL'):
    BASELINE_SOURCE_PROFILES[baseline+'_HUB']=dict(BASELINE_SOURCE_PROFILES[baseline],**HUB_SOURCE_OVERRIDES)
SOURCE_PROFILE_PROTECTED = {
    'WHATSAPP_TAIL':'META_UNIFIED_MESSAGES', 'WHATSAPP_CONTEXTUAL':'META_UNIFIED_MESSAGES',
    'WHATSAPP_TAIL_HUB':'META_MESSAGE_HUB', 'WHATSAPP_CONTEXTUAL_HUB':'META_MESSAGE_HUB',
}
MIGRATION='database/migrations/2026_10_15_000001_create_meta_ai_controls.php'
LOCK_NAMES = ('orders-install.lock', 'inbox-install.lock')
UNITS = tuple('fasakhansta-whatsapp-'+name for name in
              ('capture.timer', 'capture.service', 'orders.timer', 'orders.service')) + (
    'fasakhansta-meta-messaging.timer','fasakhansta-meta-messaging.service',
    'fasakhansta-meta-ai.timer','fasakhansta-meta-ai.service')
NEW_UNITS=UNITS[-2:]
PROTECTED_PINS = {'app/Services/Dashboard/WhatsAppReplyService.php': 'f80378c7b61cc42c747bdceefd71b79c566de553', 'app/Services/Dashboard/WhatsAppOrderSourceSelection.php': '03db3211b80da905f07415fd16f1e7a62e0395b1', 'app/Services/Dashboard/TakeawayAccess.php': '6b27b7ac2faff6fdaf952494f90f2d8378a12484', 'app/Services/Dashboard/TakeawayCatalog.php': '49943493fffb5405c87a3001f36c339caa2edf31', 'app/Support/WhatsAppInboxProtocol.php': 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd', 'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php': '9e221ba052589c1ffe474958b636a77ff5817b7d', 'app/Services/Dashboard/WhatsAppInboxConsumer.php': 'e0909057496cb9f710928fd8c273b5c9d0adc97f', 'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php': '4b571f7f4a5cef9257c414886dba515c06dae2a1', 'app/Services/Dashboard/WhatsAppInboxMessage.php': 'bedd91db5266d83bdce84a04836e988518891728', 'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': '51e160ec4fc01f1bda102b8a945e02a4b7261770', 'app/Services/Dashboard/WhatsAppInboxAccess.php': '186789d42113e81818de8d82a2327e3ee9d8d0b5', 'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php': '1f0eb40a2b0e6590362beee71e8cbbbcb1d7535f', 'app/Services/Dashboard/PhoneDelivery.php': '227027cfdcd68720b74220e3ecd0abb9e156a5d7', 'app/Services/Dashboard/PosServiceTicket.php': 'ef4c7b6f4a1276c122634aafabb2d510926aaeef', 'app/Services/Dashboard/MetaInboxAccess.php': '7faaba6645308c1997bae8182fb4b26dbc5e3b9a', 'app/Support/MetaMessagingProtocol.php': 'd5c6cb4dfeaa1d6c8467025338d7d92d9ce5b0ed', 'app/Services/Dashboard/MetaUploadMedia.php': 'dff1ee0d6d2670fc3b96abccd0a0dcdd9775ea2f', 'app/Services/Dashboard/MetaProfileService.php': 'dad433a26db1219e08491553d6ef579e73b6f29a', 'app/Services/Dashboard/MetaOAuthCredentials.php': '248e256abe3ad8456bcf23bcc00dcbcea62a8491'}
PROTECTED_PROFILES = {'META_UNIFIED_MESSAGES': dict(PROTECTED_PINS),
    'META_MESSAGE_HUB':dict(PROTECTED_PINS,**{
        'app/Http/Controllers/Dashboard/WhatsAppInboxController.php':'6f1da086e5b930f8248eb856a09f0c9572fb9545'})}
# The reviewed POS item editor adds an optional, default-false catalog row lock.
# Accept its exact complete foundation without publishing or modifying that file.
CATALOG_LOCK_OVERRIDE = {
    'app/Services/Dashboard/TakeawayCatalog.php':'2ff0e19675d8678dfb6ca738767eeaa99f95dc47',
}
PROTECTED_PROFILE_FOUNDATIONS = {
    'META_UNIFIED_MESSAGES':'META_UNIFIED_MESSAGES',
    'META_MESSAGE_HUB':'META_MESSAGE_HUB',
    'META_UNIFIED_MESSAGES_CATALOG_LOCK':'META_UNIFIED_MESSAGES',
    'META_MESSAGE_HUB_CATALOG_LOCK':'META_MESSAGE_HUB',
}
for foundation in ('META_UNIFIED_MESSAGES','META_MESSAGE_HUB'):
    PROTECTED_PROFILES[foundation+'_CATALOG_LOCK']=dict(PROTECTED_PROFILES[foundation],**CATALOG_LOCK_OVERRIDE)
# Observed existing root-owned configuration, read only. These files are never
# publication targets, backups of configuration contents, or ownership changes.
ROOT_READ_ONLY_CONFIGURATION = frozenset((
    'config/go_services.php','config/jwt.php','config/mail.php','config/partner_auth.php',
))


def blob(content):
    return hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()


def load_base():
    path = pathlib.Path(__file__).absolute().with_name('install_whatsapp_inbox.py')
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError('META_AI_CONTROL_BASE_PATH_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 1048576):
            raise RuntimeError('META_AI_CONTROL_BASE_FILE_UNSAFE')
        content = os.read(fd, 1048577)
        after = os.fstat(fd)
        if (info.st_size, info.st_mtime_ns, info.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise RuntimeError('META_AI_CONTROL_BASE_CHANGED')
    finally:
        os.close(fd)
    if blob(content) != BASE_BLOB:
        raise RuntimeError('META_AI_CONTROL_BASE_HASH_MISMATCH')
    name = 'verified_meta_ai_control_base'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


base = load_base()
SafeError = base.SafeError


def metadata(path):
    info = path.lstat()
    return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev,
            info.st_ino, info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def root_configuration_snapshot(path, uid, root, expected_metadata):
    if path not in (root/name for name in ROOT_READ_ONLY_CONFIGURATION):
        raise SafeError('META_AI_CONTROL_ROOT_CONFIGURATION_SCOPE_INVALID')
    base.safe_ancestors(path,uid,root)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        info=os.fstat(fd)
        def signature(value):
            return (value.st_uid,value.st_gid,stat.S_IMODE(value.st_mode),value.st_dev,
                    value.st_ino,value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_nlink!=1
                or stat.S_IMODE(info.st_mode)!=0o644 or info.st_size>8388608):
            raise SafeError('META_AI_CONTROL_ROOT_CONFIGURATION_PROFILE_NEEDS_REVIEW')
        if signature(info)!=expected_metadata or metadata(path)!=expected_metadata:
            raise SafeError('META_AI_CONTROL_ROOT_CONFIGURATION_CHANGED_DURING_READ')
        with os.fdopen(fd,'rb',closefd=False) as stream:
            content=stream.read(8388609)
        if (len(content)>8388608 or signature(os.fstat(fd))!=expected_metadata
                or metadata(path)!=expected_metadata):
            raise SafeError('META_AI_CONTROL_ROOT_CONFIGURATION_CHANGED_DURING_READ')
        return base.Snapshot(content,info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode),info.st_dev,info.st_ino)
    finally:os.close(fd)


def guarded_snapshot(path, uid, root, absent_ok=False, configuration=False):
    try:
        before = metadata(path)
    except FileNotFoundError:
        if absent_ok:
            base.safe_ancestors(path, uid, root)
            return None, None
        raise SafeError('META_AI_CONTROL_REQUIRED_FILE_MISSING') from None
    # Atomic replacement retains legacy private hardlinks without modifying them.
    private = configuration and path in (root/'.env',root/'bootstrap/cache/config.php')
    if before[2] & 0o022 or (private and (before[5] != 1 or before[2] & 0o077)):
        raise SafeError('META_AI_CONTROL_MODE_OR_CONFIGURATION_LINK_UNSAFE')
    if configuration and before[0]==0 and path in (root/name for name in ROOT_READ_ONLY_CONFIGURATION):
        item=root_configuration_snapshot(path,uid,root,before)
    else:
        item = base.snapshot(path, uid, root)
    if metadata(path) != before:
        raise SafeError('META_AI_CONTROL_FILE_CHANGED_DURING_READ')
    return item, before


def configuration_snapshot(root, uid):
    directory = root/'config'
    base.safe_ancestors(directory/'probe', uid, root)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & 0o022:
        raise SafeError('META_AI_CONTROL_CONFIGURATION_DIRECTORY_UNSAFE')
    paths = ['.env', 'bootstrap/cache/config.php']
    for path in sorted(directory.iterdir()):
        # Include all existing direct files, rather than a selected WhatsApp subset.
        if path.is_dir() and not path.is_symlink():
            raise SafeError('META_AI_CONTROL_NESTED_CONFIGURATION_NEEDS_REVIEW')
        paths.append(str(path.relative_to(root)))
    result = {name: guarded_snapshot(root/name, uid, root,
              absent_ok=name=='bootstrap/cache/config.php', configuration=True) for name in paths}
    return metadata(directory), result


def configuration_unchanged(root, uid, expected):
    try:
        return configuration_snapshot(root, uid) == expected
    except (SafeError, OSError):
        return False


def configuration_fingerprint(value):
    directory, files=value
    rows=[directory,[(name,signature,None if item is None else hashlib.sha256(item.content).hexdigest())
          for name,(item,signature) in sorted(files.items())]]
    return hashlib.sha256(json.dumps(rows,separators=(',',':')).encode()).hexdigest()


def require_configuration(root, uid, expected):
    if not configuration_unchanged(root, uid, expected):
        raise SafeError('META_AI_CONTROL_CONFIGURATION_CHANGED')


@contextlib.contextmanager
def locks(root, uid, held_locks=None):
    parent = root.parent/'whatsapp-release-backups'
    base.private_parent(parent, uid)
    descriptors = []
    try:
        if held_locks is not None and len(held_locks) != len(LOCK_NAMES):
            raise SafeError('META_AI_CONTROL_LOCK_HANDOFF_INVALID')
        for index, name in enumerate(LOCK_NAMES):
            path = parent/name
            fd = (os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
                  if held_locks is None else held_locks[index])
            if held_locks is None:
                descriptors.append(fd)
            info = os.fstat(fd)
            link = path.lstat()
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1
                    or stat.S_IMODE(info.st_mode) != 0o600
                    or (info.st_dev, info.st_ino) != (link.st_dev, link.st_ino)):
                raise SafeError('META_AI_CONTROL_LOCK_UNSAFE')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise SafeError('META_AI_CONTROL_ANOTHER_INSTALL_RUNNING') from None
        yield
    finally:
        for fd in descriptors:
            os.close(fd)


READINESS_PHP = r'''
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
try {
 $pins=json_decode($argv[1]??'',true,16,JSON_THROW_ON_ERROR);
 $roster=['app/Support/MetaAiSchedule.php','app/Services/Dashboard/MetaBusinessAgent.php','app/Services/Dashboard/MetaAiControl.php','app/Support/WhatsAppOrderDialogue.php','app/Support/WhatsAppOrderExtraction.php','app/Services/Dashboard/WhatsAppOrderConversationEvidence.php','app/Services/Dashboard/WhatsAppOrderAiProvider.php','app/Services/Dashboard/WhatsAppOrderFollowupService.php','app/Services/Dashboard/MetaOrderNativeEvidence.php','app/Services/Dashboard/MetaOrderFollowupService.php','app/Services/Dashboard/MetaMediaService.php','app/Services/Dashboard/MetaReplyService.php','app/Services/Dashboard/WhatsAppOrderWorkflow.php','app/Services/Dashboard/MetaOrderWorkflow.php','app/Services/Dashboard/MetaInboxConsumer.php','app/Http/Controllers/Dashboard/MetaAiControlController.php','app/Console/Commands/ProcessMetaAiMessages.php','database/migrations/2026_10_15_000001_create_meta_ai_controls.php','resources/views/admin/meta/ai_controls.blade.php','public/js/dashboard-meta-ai-controls.js','public/css/dashboard-meta-ai-controls.css','routes/meta_ai_controls.php','resources/views/admin/meta/channels.blade.php','routes/meta_inbox.php'];
 if(!is_array($pins)||array_keys($pins)!==$roster)throw new \RuntimeException();
 // Verify the entire declared code set before executing Composer or any candidate.
 foreach($pins as $relative=>$expected){
  $path=getcwd().'/'.$relative;$info=lstat($path);
  if($expected===null){if($info!==false)throw new \RuntimeException();continue;}
  if(!$info||($info['mode']&0170000)!==0100000||realpath($path)!==$path)throw new \RuntimeException();
  $bytes=file_get_contents($path);
  if(sha1('blob '.strlen($bytes)."\0".$bytes)!==$expected)throw new \RuntimeException();
 }
 require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
 $kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
 if($app->getCachedConfigPath()!==getcwd().'/bootstrap/cache/config.php'||$app->routesAreCached())throw new \RuntimeException();
 foreach($pins as $relative=>$expected){
  if($expected===null||!str_starts_with($relative,'app/'))continue;
  $class='App\\'.str_replace('/','\\',substr($relative,4,-4));
  $r=new \ReflectionClass($class);$path=getcwd().'/'.$relative;
  if($r->getFileName()!==$path)throw new \RuntimeException();
  $bytes=file_get_contents($path);
  if(sha1('blob '.strlen($bytes)."\0".$bytes)!==$expected)throw new \RuntimeException();
 }
 $class=\App\Services\Dashboard\WhatsAppOrderWorkflow::class;
 if(!app($class)->available()||!array_key_exists('whatsapp:process-orders',$kernel->all()))throw new \RuntimeException();
 foreach([
  'whatsapp_reply_requests'=>['id','conversation_id','actor_id','waba_id','phone_number_id','request_hash','payload_hash',
   'inbound_message_id','state','kind','audit','response_details','remote_message_key','reason','claimed_at',
   'send_started_at','completed_at','created_at','updated_at'],
  'whatsapp_reply_projection_keys'=>['id','reply_id','conversation_id','waba_id','phone_number_id','kind','message_key',
   'receipt_hash','valid','created_at','updated_at'],
 ]as$table=>$required){
  if(!\Illuminate\Support\Facades\Schema::hasTable($table))throw new \RuntimeException();
  $columns=\Illuminate\Support\Facades\Schema::getColumnListing($table);
  foreach($required as$column)if(!in_array($column,$columns,true))throw new \RuntimeException();
 }
 foreach($pins as $relative=>$expected){
  if($expected!==null&&str_ends_with($relative,'.blade.php')){
   $compiled=$app['blade.compiler']->compileString(file_get_contents($relative));token_get_all($compiled,TOKEN_PARSE);
  }
 }
 if(($pins['app/Console/Commands/ProcessMetaAiMessages.php']??null)!==null
  &&!array_key_exists('meta:process-ai',$kernel->all()))throw new \RuntimeException();
 $mode=config('whatsapp_orders.mode');
 if(!in_array($mode,['review','auto'],true))throw new \RuntimeException();
 $value=['mode'=>$mode,'fingerprint'=>hash('sha256',serialize(config()->all()))];
 while(ob_get_level()>0)ob_end_clean();echo json_encode($value,JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''


CONTROL_STATE_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $schema=\Illuminate\Support\Facades\Schema::class;$db=\Illuminate\Support\Facades\DB::class;
 if(!$schema::hasTable('migrations'))throw new \RuntimeException();
 $recorded=$db::table('migrations')->where('migration','2026_10_15_000001_create_meta_ai_controls')->count();
 if(!in_array($recorded,[0,1],true))throw new \RuntimeException();
 $exists=$schema::hasTable('meta_ai_controls');
 if($exists!==($recorded===1))throw new \RuntimeException();
 $result=['present'=>$exists,'fingerprint'=>null,'enabled'=>false,'default_disabled'=>false];
 if($exists){
  $required=['id','policy','activations','handoff','revision','actor_id','updated_by','created_at','updated_at'];
  $columns=$schema::getColumnListing('meta_ai_controls');sort($required);sort($columns);
  if($required!==$columns||$db::table('meta_ai_controls')->count()!==1)throw new \RuntimeException();
  $row=$db::table('meta_ai_controls')->where('id',1)->select('id','revision','actor_id','updated_by','created_at','updated_at')
   ->selectRaw('CASE WHEN LENGTH(policy)<=4096 THEN policy ELSE NULL END AS policy')
   ->selectRaw('CASE WHEN LENGTH(activations)<=4096 THEN activations ELSE NULL END AS activations')
   ->selectRaw('CASE WHEN LENGTH(handoff)<=4096 THEN handoff ELSE NULL END AS handoff')
   ->selectRaw('CASE WHEN handoff IS NOT NULL AND LENGTH(handoff)>4096 THEN 1 ELSE 0 END AS handoff_oversized')->first();
  if(!$row||(int)$row->handoff_oversized!==0||!is_string($row->policy)||!is_string($row->activations))throw new \RuntimeException();
  $policy=json_decode($row->policy,true,8,JSON_THROW_ON_ERROR);
  $activations=json_decode($row->activations,true,8,JSON_THROW_ON_ERROR);
  if(!is_array($policy)||count($policy)!==3||!is_bool($policy['enabled']??null)
   ||!is_array($policy['channels']??null)||!is_array($policy['schedule']??null)||!is_array($activations))throw new \RuntimeException();
  $result['fingerprint']=hash('sha256',json_encode((array)$row,JSON_THROW_ON_ERROR));
  $result['enabled']=$policy['enabled'];
  $result['default_disabled']=$policy['enabled']===false&&$activations===[]&&$row->handoff===null
   &&(int)$row->revision===0&&$row->actor_id===null&&$row->updated_by===null;
 }
 while(ob_get_level())ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(\Throwable $ignore){exit(1);}
'''


def control_state(root, runner):
    code,output=runner(root,[PHP,'-r',CONTROL_STATE_PHP],capture=True)
    try:value=json.loads(output) if code==0 else None
    except (ValueError,TypeError):value=None
    if (not isinstance(value,dict) or set(value)!={'present','fingerprint','enabled','default_disabled'}
            or any(type(value[key]) is not bool for key in ('present','enabled','default_disabled'))
            or (value['present'] and (not isinstance(value['fingerprint'],str) or not re.fullmatch('[a-f0-9]{64}',value['fingerprint'])))
            or (not value['present'] and value['fingerprint'] is not None)):
        raise SafeError('META_AI_CONTROL_SCHEMA_OR_STATE_NEEDS_REVIEW')
    return value


def readiness(root, runner, pins):
    code, output = runner(root, [PHP, '-r', READINESS_PHP, json.dumps(pins, separators=(',', ':'))], capture=True)
    try:
        value = json.loads(output) if code == 0 else None
    except (ValueError, TypeError):
        value = None
    if (not isinstance(value, dict) or set(value) != {'mode', 'fingerprint'}
            or value['mode'] not in ('review', 'auto') or not isinstance(value['fingerprint'], str)
            or not re.fullmatch('[a-f0-9]{64}', value['fingerprint'])):
        raise SafeError('META_AI_CONTROL_READ_ONLY_BOOTSTRAP_FAILED')
    return value


def require_quiet(root, runner):
    for name in UNITS:
        args=['/usr/bin/systemctl', 'show', '--no-pager', '--property=LoadState', '--property=ActiveState']
        if name.endswith('.service'):
            args.append('--property=MainPID')
        code, output = runner(root, args+[name], capture=True)
        try:
            lines=output.decode('ascii').strip().splitlines(); values=dict(line.split('=',1) for line in lines)
            expected={'LoadState','ActiveState'}|({'MainPID'} if name.endswith('.service') else set())
            absent=name in NEW_UNITS and values.get('LoadState')=='not-found'
            if ((code!=0 and not (code==1 and absent)) or len(lines)!=len(values) or set(values)!=expected
                    or (values['LoadState']!='loaded' and not absent) or values['ActiveState'] not in ('inactive','failed')
                    or ('MainPID' in values and values['MainPID']!='0')):
                raise ValueError()
        except (ValueError, UnicodeError):
            raise SafeError('META_AI_CONTROL_WORKERS_NOT_QUIESCENT') from None


def private_write(path, content):
    temporary=path.with_name('.'+path.name+'.temporary')
    fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(content);stream.flush();os.fsync(stream.fileno())
        base.atomic_move_noreplace(temporary,path)
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        if temporary.exists():temporary.unlink()


def release_sources(root, uid, release, runner, pins):
    if not re.fullmatch('[0-9a-f]{40}', release):
        raise SafeError('META_AI_CONTROL_RELEASE_INVALID')
    code, kind=runner(root,['git','cat-file','-t',release],capture=True)
    if code!=0 or kind.strip()!=b'commit':
        raise SafeError('META_AI_CONTROL_RELEASE_NOT_COMMIT')
    sources={}
    for relative, (_, target) in pins.items():
        if not re.fullmatch('[0-9a-f]{40}',target):
            raise SafeError('META_AI_CONTROL_PIN_INVALID')
        code, tree=runner(root,['git','ls-tree','-z',release,relative],capture=True)
        expected=b'100644 blob '+target.encode()+b'\t'+relative.encode()+b'\0'
        if code!=0 or tree!=expected:
            raise SafeError('META_AI_CONTROL_RELEASE_SOURCE_MISMATCH')
        code, content=runner(root,['git','show',release+':'+relative],capture=True)
        if code!=0 or not content or len(content)>8388608 or blob(content)!=target:
            raise SafeError('META_AI_CONTROL_RELEASE_BLOB_MISMATCH')
        sources[relative]=content
    return sources


def select_protected_profile(values):
    observed={name:blob(item.content) for name,(item,_) in values.items()}
    for name,pins in PROTECTED_PROFILES.items():
        if set(pins)!=set(PROTECTED_PINS):
            raise SafeError('META_AI_CONTROL_PROTECTED_PROFILE_SCOPE_INVALID')
        if observed==pins:
            return name,pins
    raise SafeError('META_AI_CONTROL_PROTECTED_SOURCE_MISMATCH')


def protected_fingerprint(values):
    rows=[(name,signature,blob(item.content)) for name,(item,signature) in sorted(values.items())]
    return hashlib.sha256(json.dumps(rows,separators=(',',':')).encode()).hexdigest()


def verify_state(root,uid,expected_configuration,expected_sources,expected_protected,held_locks=None,expected_profile=None,expected_control=None):
    if (not isinstance(expected_sources,dict) or set(expected_sources)!=set(SOURCE_PINS)
            or expected_sources not in list(BASELINE_SOURCE_PROFILES.values())+[{path:pair[1] for path,pair in SOURCE_PINS.items()}]
            or not isinstance(expected_configuration,str) or not re.fullmatch('[a-f0-9]{64}',expected_configuration)
            or not isinstance(expected_protected,str) or not re.fullmatch('[a-f0-9]{64}',expected_protected)
            or expected_profile not in PROTECTED_PROFILES):
        raise SafeError('META_AI_CONTROL_STATE_SCOPE_INVALID')
    matching=[name for name,values in BASELINE_SOURCE_PROFILES.items() if values==expected_sources]
    if matching and not any(SOURCE_PROFILE_PROTECTED.get(name)==PROTECTED_PROFILE_FOUNDATIONS.get(expected_profile) for name in matching):
        raise SafeError('META_AI_CONTROL_STATE_PROFILE_PAIR_INVALID')
    base.validate_root(root,uid)
    with locks(root,uid,held_locks):
        configuration=configuration_snapshot(root,uid)
        if configuration_fingerprint(configuration)!=expected_configuration:
            raise SafeError('META_AI_CONTROL_STATE_CONFIGURATION_CHANGED')
        protected={name:guarded_snapshot(root/name,uid,root) for name in PROTECTED_PINS}
        try:profile_name,_=select_protected_profile(protected)
        except SafeError:raise SafeError('META_AI_CONTROL_STATE_PROTECTED_CHANGED') from None
        if profile_name!=expected_profile or protected_fingerprint(protected)!=expected_protected:
            raise SafeError('META_AI_CONTROL_STATE_PROTECTED_CHANGED')
        if control_state(root,base.command)['fingerprint']!=expected_control:
            raise SafeError('META_AI_CONTROL_STATE_CONTROL_CHANGED')
        sources={name:guarded_snapshot(root/name,uid,root,absent_ok=expected is None)
                 for name,expected in expected_sources.items()}
        observed={name:None if item is None else blob(item.content) for name,(item,_) in sources.items()}
        if observed!=expected_sources:raise SafeError('META_AI_CONTROL_STATE_SOURCE_CHANGED')
        # Bind the opened snapshot and complete config/protected roster again,
        # after all reads. The caller immediately guards each worker restart.
        require_configuration(root,uid,configuration)
        if any(guarded_snapshot(root/name,uid,root)!=value for name,value in protected.items()):
            raise SafeError('META_AI_CONTROL_STATE_PROTECTED_CHANGED')
        if any(guarded_snapshot(root/name,uid,root,absent_ok=True)!=value for name,value in sources.items()):
            raise SafeError('META_AI_CONTROL_STATE_SOURCE_CHANGED')
        if control_state(root,base.command)['fingerprint']!=expected_control:
            raise SafeError('META_AI_CONTROL_STATE_CONTROL_CHANGED')
        return {'status':'META_AI_CONTROL_STATE_READY',
                'configuration_fingerprint':expected_configuration,'sources':observed,
                'protected_fingerprint':expected_protected,'protected_profile':expected_profile,'control_fingerprint':expected_control}


def apply(root, uid, release, check_only=False, runner=base.command, pins=None, held_locks=None,
          expected_configuration=None,expected_protected=None,expected_profile=None,expected_control='UNSPECIFIED'):
    pins=SOURCE_PINS if pins is None else pins
    if tuple(pins)!=tuple(SOURCE_PINS):
        raise SafeError('META_AI_CONTROL_SCOPE_INVALID')
    base.validate_root(root, uid)
    with locks(root, uid, held_locks):
        config_before=configuration_snapshot(root,uid)
        config_digest=configuration_fingerprint(config_before)
        if expected_configuration is not None and config_digest!=expected_configuration:
            raise SafeError('META_AI_CONTROL_CONFIGURATION_CHANGED_SINCE_PREFLIGHT')
        protected={p:guarded_snapshot(root/p,uid,root) for p in PROTECTED_PINS}
        profile_name,profile_pins=select_protected_profile(protected)
        protected_digest=protected_fingerprint(protected)
        if ((expected_profile is not None and profile_name!=expected_profile)
                or (expected_protected is not None and protected_digest!=expected_protected)):
            raise SafeError('META_AI_CONTROL_PROTECTED_PROFILE_CHANGED_SINCE_PREFLIGHT')
        controls=None
        def unchanged():
            require_configuration(root,uid,config_before)
            if controls is not None and control_state(root,runner)!=controls:
                raise SafeError('META_AI_CONTROL_POLICY_CHANGED_DURING_INSTALL')
            for p,value in protected.items():
                if guarded_snapshot(root/p,uid,root)!=value:
                    raise SafeError('META_AI_CONTROL_PROTECTED_SOURCE_CHANGED')
        before={relative:guarded_snapshot(root/relative,uid,root,absent_ok=old is None)
                for relative,(old,_) in pins.items()}
        observed={relative:None if value[0] is None else blob(value[0].content) for relative,value in before.items()}
        profiles=dict(BASELINE_SOURCE_PROFILES,CURRENT={path:pair[1] for path,pair in pins.items()})
        source_profile=next((name for name,values in profiles.items() if observed==values),None)
        if source_profile is None:raise SafeError('META_AI_CONTROL_LIVE_SOURCE_NEEDS_REVIEW')
        if source_profile!='CURRENT' and SOURCE_PROFILE_PROTECTED.get(source_profile)!=PROTECTED_PROFILE_FOUNDATIONS.get(profile_name):
            raise SafeError('META_AI_CONTROL_SOURCE_FOUNDATION_PAIR_NEEDS_REVIEW')
        sources=release_sources(root,uid,release,runner,pins)
        initial=readiness(root,runner,observed)
        controls=control_state(root,runner)
        if expected_control!='UNSPECIFIED' and controls['fingerprint']!=expected_control:
            raise SafeError('META_AI_CONTROL_POLICY_CHANGED_SINCE_PREFLIGHT')
        if source_profile!='CURRENT' and controls['enabled']:
            raise SafeError('META_AI_CONTROL_ENABLED_WITH_LEGACY_RUNTIME_NEEDS_REVIEW')
        unchanged()
        parent=root.parent/'whatsapp-release-backups'
        # Lint private disposable candidates before changing any application file.
        with tempfile.TemporaryDirectory(prefix='.meta-ai-control-lint-',dir=parent) as folder:
            for index,(relative,content) in enumerate(sources.items()):
                staged=pathlib.Path(folder)/(str(index)+'.php');private_write(staged,content)
                code,_=runner(root,[PHP,'-l',str(staged)])
                if code!=0 or staged.read_bytes()!=content:
                    raise SafeError('META_AI_CONTROL_CANDIDATE_LINT_FAILED')
        unchanged()
        for relative,(item,signature) in before.items():
            if guarded_snapshot(root/relative,uid,root,absent_ok=True)!=(item,signature):
                raise SafeError('META_AI_CONTROL_SOURCE_CHANGED')
        if check_only:
            return {'status':'META_AI_CONTROL_PREFLIGHT_READY','changed':any(observed[p]!=pins[p][1] for p in pins),
                    'configuration_fingerprint':config_digest,'sources':observed,'protected_fingerprint':protected_digest,'protected_profile':profile_name,'control_fingerprint':controls['fingerprint'],'source_profile':source_profile}
        require_quiet(root,runner)
        if all(observed[p]==pins[p][1] for p in pins) and controls['present']:
            final=readiness(root,runner,observed)
            unchanged()
            if final!=initial:
                raise SafeError('META_AI_CONTROL_EFFECTIVE_CONFIGURATION_CHANGED')
            return {'status':'META_AI_CONTROL_ALREADY_CURRENT','backup':None,
                    'configuration_fingerprint':config_digest,'sources':observed,'protected_fingerprint':protected_digest,'protected_profile':profile_name,'control_fingerprint':controls['fingerprint'],'source_profile':source_profile}
        backup=pathlib.Path(tempfile.mkdtemp(prefix='meta-ai-control-',dir=parent));backup.chmod(0o700)
        if backup.stat().st_dev!=root.stat().st_dev:
            raise SafeError('META_AI_CONTROL_BACKUP_FILESYSTEM_MISMATCH')
        publications=backup/'publications';publications.mkdir(mode=0o700);base.probe_atomic_exchange(publications)
        private_write(backup/'receipt.json',(json.dumps({'version':1,'release':release,'sources':observed,
                      'targets':{p:v[1] for p,v in pins.items()},
                      'protected':profile_pins,'protected_fingerprint':protected_digest,'protected_profile':profile_name,
                      'configuration_fingerprint':config_digest,'control_fingerprint':controls['fingerprint'],'source_profile':source_profile},sort_keys=True)+'\n').encode())
        written={};created=[]
        try:
            for relative,content in sources.items():
                if observed[relative]==pins[relative][1]:
                    continue
                unchanged()
                base.replace_file(root/relative,content,before[relative][0],uid,root,created,
                    journal=written,relative=relative,private_directory=publications)
            require_quiet(root,runner)
            unchanged()
            migration_applied=False
            if not controls['present']:
                code,_=runner(root,[PHP,'artisan','migrate','--force','--path='+MIGRATION])
                if code!=0:raise SafeError('META_AI_CONTROL_SCOPED_MIGRATION_FAILED')
                after=control_state(root,runner)
                if not after['present'] or not after['default_disabled'] or after['enabled']:
                    raise SafeError('META_AI_CONTROL_MIGRATION_DEFAULT_NOT_DISABLED')
                controls=after;migration_applied=True
            unchanged()
            final=readiness(root,runner,{p:v[1] for p,v in pins.items()})
            if final!=initial:
                raise SafeError('META_AI_CONTROL_EFFECTIVE_CONFIGURATION_CHANGED')
            for relative in pins:
                base.assert_snapshot(root/relative,written[relative].after if relative in written else before[relative][0],uid,root)
            unchanged()
        except BaseException:
            restored=True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try:
                        if not base.recover_publication(publication,root,uid):
                            restored=False;break
                    except BaseException:
                        restored=False;break
                if restored:
                    for directory,inode in reversed(created):
                        try:
                            info=directory.lstat()
                            if stat.S_ISDIR(info.st_mode) and info.st_uid==uid and info.st_ino==inode:directory.rmdir()
                        except OSError:pass
            # All known workers are paused, but HTTP requests are not. An HTTP
            # process may retain newly loaded dialogue code after byte rollback.
            # Do not resume old order code even if no order/reply was submitted.
            exposure=any(p in written for p in SOURCE_PINS)
            try:unchanged();safe=restored and not exposure
            except (SafeError,OSError):safe=False
            raise SafeError(('META_AI_CONTROL_FILES_RESTORED' if safe else
                'META_AI_CONTROL_ROLLBACK_REVIEW_REQUIRED')+'; BACKUP='+str(backup)) from None
        return {'status':'META_AI_CONTROL_REPAIR_READY','backup':str(backup),
                'configuration_fingerprint':config_digest,'sources':{p:v[1] for p,v in pins.items()},
                'protected_fingerprint':protected_digest,'protected_profile':profile_name,'control_fingerprint':controls['fingerprint'],'source_profile':'CURRENT','migration_applied':migration_applied}


def main():
    import argparse
    parser=argparse.ArgumentParser(add_help=False)
    parser.add_argument('--root',required=True);parser.add_argument('--release',required=True)
    parser.add_argument('--check-only',action='store_true')
    args=parser.parse_args()
    try:
        def interrupted(signum,frame):raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM,interrupted);signal.signal(signal.SIGINT,interrupted)
        root=pathlib.Path(args.root);uid=pwd.getpwnam('fasakha').pw_uid
        if root!=APP_ROOT or os.geteuid()!=uid:raise SafeError('META_AI_CONTROL_EXECUTION_CONTEXT_INVALID')
        result=apply(root,uid,args.release,args.check_only)
        print(json.dumps(result,sort_keys=True))
    except BaseException as error:
        message=str(error) if isinstance(error,SafeError) else 'META_AI_CONTROL_STOPPED_WITHOUT_SECRET_OUTPUT'
        print(message,file=sys.stderr);raise SystemExit(1)


if __name__=='__main__':main()
