#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
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

ROOT=pathlib.Path('/home/fasakha/public_html')
PHP='/opt/cpanel/ea-php82/root/usr/bin/php'
RUNUSER='/usr/sbin/runuser'
SYSTEMCTL='/usr/bin/systemctl'
LEGACY_NAME='enable_whatsapp_automatic_root.sh'
LEGACY_BLOB='d30c13bd13ca73e5aad54f558a259e18fb95147f'
APP_NAME='apply_whatsapp_cairo_origins.py'
MANIFEST_NAME='whatsapp_cairo_origins_manifest.json'
EXTRA_PINS={LEGACY_NAME:LEGACY_BLOB,APP_NAME:'bf4111db00dbc042c1aa2b23bdb1eb739240e261',MANIFEST_NAME:'5e51cdfd8b922cb00de84df0c33804eb85a7bc22'}
TARGET_PINS={
'app/Support/WhatsAppInboxProtocol.php':'ecde67c6a87ca8068bb077892fdbea522d89f217',
'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php':'1f0eb40a2b0e6590362beee71e8cbbbcb1d7535f',
'app/Services/Dashboard/PhoneDelivery.php':'227027cfdcd68720b74220e3ecd0abb9e156a5d7',
'app/Services/Dashboard/PosServiceTicket.php':'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
'app/Http/Controllers/Dashboard/PhoneOrdersController.php':'478776e72edf67d9eaaf00abd49d97a2fa4720c8',
'public/dashboard/js/phone-orders.js':'66597844cc01d9371b1952f79b22fb1833bf2ac0',
'app/Services/Dashboard/WhatsAppOrderWorkflow.php':'6c63b91d7fb70130ae472cea291fa2cee88b27dd',
}
DASHBOARD_PINS={'database/migrations/2026_10_12_000005_create_whatsapp_inbox_hidden_messages.php': '699e9b8f63452f4e8f09f8380f3905406ef9ddbf', 'app/Services/Dashboard/WhatsAppInboxMessage.php': '087cdbdf51dd87c411da3ed0cfa33980474b593f', 'app/Services/Dashboard/WhatsAppInboxMedia.php': 'ae1f6aa4d833de80637ea56b19719c649183456d', 'app/Services/Dashboard/WhatsAppReplyService.php': 'cce92c02d4a4400cd133fc1f7631fb94762f54c6', 'app/Services/Dashboard/WhatsAppInboxReadState.php': 'd26eb360f5b5576047edff14808c25950592ba30', 'app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php': '48af05084619f78480f472e75d30376235e77144', 'app/Http/Controllers/Dashboard/WhatsAppReplyController.php': '7223022bcf5b9acd374ba501a5e17609d9ad05b8', 'app/Http/Controllers/Dashboard/WhatsAppInboxController.php': '6f45b964d13504442539ced4c5862dfbbe921381', 'routes/whatsapp_replies.php': '61a5ea46e5bba6dcd971e1b2bc8b3cbaae3c22e8', 'routes/whatsapp_inbox.php': '524a38faa203d578abdcc6185e48709036d91e82', 'public/js/dashboard-whatsapp-inbox.js': 'cdcd1fddf21988d876d806ddd25fbd532c8f569e', 'public/js/dashboard-whatsapp-replies.js': '392569e73e951405a5de6ce676922e63377a6b0b', 'public/js/dashboard-whatsapp-orders.js': 'ad7f78fc490774280c31e01125bc262a92a2cb3d', 'public/css/dashboard-whatsapp-inbox.css': '639ac2223b4abcd679329000551d9c735e43b5af', 'public/css/dashboard-whatsapp-replies.css': 'aaf5d4b8a5c46b6aaaf43824c52ddefca00ba854', 'resources/views/admin/whatsapp/replies.blade.php': '6386debd6e20836cc1023cefc7868e214ce7f0f0', 'resources/views/admin/whatsapp/index.blade.php': '573f95d72e011a814b9b4f13eca256009c50fee8'}
ORIGINAL_DASHBOARD_LEGACY_PINS={'app/Services/Dashboard/WhatsAppReplyService.php': '7047caefba93935a25029ea99715dac645d9c24d', 'public/js/dashboard-whatsapp-inbox.js': '19117235ba454c5e73bbd291920f9f56c84ebea8', 'public/js/dashboard-whatsapp-replies.js': '304a0bc6acd2474adb7850664708f30bddbd6a29', 'resources/views/admin/whatsapp/replies.blade.php': 'ac114cfb5524f91a7e8753e75a751d82b43af965', 'resources/views/admin/whatsapp/index.blade.php': 'd56fe43423728bb9d48820cc51b34522cc1ad7cc'}
HELPER_PINS={
'activate_whatsapp_automatic.py':'008fff7a56e4c9d2ff849df6f653dfb6b6427371',
'configure_whatsapp_orders.py':'a0780b40adc404f81b5bd2b9934b50d13dc0797a',
'rotate_whatsapp_messaging_token.py':'b824ea3c09f0314d5debdb278a0d02d37f41bfce',
'rotate_whatsapp_catalog_token.py':'eaf40b872ec1dde0cbffa08e507e56a03f62591f',
'install_whatsapp_inbox.py':'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98',
'install_whatsapp_automatic_root.sh':'89662f5e9875355a8e7d629c37320c05ce40b5b5',
'install_whatsapp_capture_root.sh':'c3571f9cf4ab37171c9d6cbf2fc8ddae9e067316',
'whatsapp_ai_connection_check.php':'fa0bf7a51bff932c274c34398bddbc6aeed04e30',
**EXTRA_PINS,
}
class SafeError(Exception):pass
STAGE='STARTUP'
SNAPSHOT_REASONS=frozenset((
'UNSAFE_PATH','ANCESTOR_NOT_DIRECTORY_OR_SYMLINK','ANCESTOR_OWNER_NEEDS_REVIEW',
'REQUIRED_FILE_MISSING','FILE_NOT_READABLE_OR_SYMLINK','FILE_OWNER_OR_TYPE_NEEDS_REVIEW',
'FILE_TOO_LARGE','FILE_CHANGED_DURING_READ','CAIRO_REQUIRED_FILE_MISSING',
'CAIRO_FILE_MODE_OR_PAYLOAD_LINK_UNSAFE','CAIRO_FILE_CHANGED_DURING_READ',
'CAIRO_PAYLOAD_OWNER_UNSAFE','CAIRO_PAYLOAD_TYPE_OR_SIZE_UNSAFE','CAIRO_PAYLOAD_CHANGED_DURING_READ'))

def set_stage(value):
    global STAGE
    STAGE=value

def checked_snapshot(root,uid,app,relative,**options):
    try:return app.guarded_snapshot(root/relative,uid,root,**options)
    except app.SafeError as error:
        reason=str(error)if str(error)in SNAPSHOT_REASONS else'VERIFIED_SNAPSHOT_GUARD_FAILED'
        raise SafeError('CAIRO_SOURCE_PROFILE_FAILED: '+relative+'; REASON='+reason)from None

def checked_legacy_sources(root,uid,legacy,pins):
    try:return legacy['guard_sources'](root,uid,pins)
    except legacy['SafeError'] as error:
        prefix='AUTOMATIC_LIVE_SOURCE_NEEDS_REVIEW: '
        value=str(error);relative=value[len(prefix):]if value.startswith(prefix)else None
        if relative in pins:raise SafeError('CAIRO_IMMUTABLE_SOURCE_PROFILE_FAILED: '+relative)from None
        raise SafeError('CAIRO_IMMUTABLE_SOURCE_PROFILE_FAILED')from None

def safe_legacy_reason(error,legacy):
    value=str(error)
    fixed=frozenset(('AUTOMATIC_GUARDED_PARENT_UNSAFE','AUTOMATIC_GUARDED_FILE_UNSAFE',
    'AUTOMATIC_GUARDED_BLOB_OR_RACE_FAILED','AUTOMATIC_UNIT_HELPER_FORMAT_INVALID',
    'AUTOMATIC_UNIT_STATE_UNAVAILABLE','AUTOMATIC_ORDER_WORKER_NOT_QUIESCENT',
    'AUTOMATIC_PRIOR_ENABLED_STATE_REQUIRES_REVIEW','AUTOMATIC_ENABLED_STATE_RESTORE_FAILED'))
    if value in fixed:return value
    prefixes={'AUTOMATIC_LIVE_SOURCE_NEEDS_REVIEW: ':legacy['SOURCE_PINS'],
    'AUTOMATIC_HELPER_PIN_FAILED: ':legacy['HELPER_PINS'],
    'AUTOMATIC_UNIT_BYTES_CHANGED: ':('capture','orders'),
    'AUTOMATIC_UNIT_ACTION_FAILED: ':tuple(kind+' '+flag for kind in('capture','orders')for flag in('--install','--start','--stop'))}
    for prefix,allowed in prefixes.items():
        if value.startswith(prefix)and value[len(prefix):]in allowed:return value
    return 'CAIRO_VERIFIED_LEGACY_OPERATION_FAILED'

def checked_validator(callback,legacy):
    def validate(kind):
        try:return callback(kind)
        except legacy['SafeError']:
            raise SafeError('CAIRO_INSTALLED_UNIT_NEEDS_REVIEW: '+kind)from None
    return validate

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def read_root_payload(path,digest):
    for parent in reversed(path.parents):
        s=parent.lstat()
        if not stat.S_ISDIR(s.st_mode)or s.st_uid!=0:raise SafeError('CAIRO_STAGING_ANCESTOR_UNSAFE')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        s=os.fstat(fd)
        if not stat.S_ISREG(s.st_mode)or s.st_uid!=0 or s.st_nlink!=1 or s.st_mode&0o022 or s.st_size>2097152:raise SafeError('CAIRO_STAGING_FILE_UNSAFE')
        data=os.read(fd,2097153);after=os.fstat(fd)
        key=lambda x:(x.st_uid,x.st_gid,stat.S_IMODE(x.st_mode),x.st_dev,x.st_ino,x.st_nlink,x.st_size,x.st_mtime_ns,x.st_ctime_ns)
        if key(s)!=key(after)or blob(data)!=digest:raise SafeError('CAIRO_STAGING_HASH_OR_RACE_FAILED')
        return data
    finally:os.close(fd)

def legacy_program(content):
    marker='exec /usr/bin/python3 - "$@" <<\'PY\'\n'
    if content.count(marker)!=1 or not content.endswith('\nPY\n'):raise SafeError('CAIRO_LEGACY_WRAPPER_FORMAT_INVALID')
    ns={'__name__':'verified_cairo_group_control'}
    exec(compile(content.split(marker,1)[1][:-len('\nPY\n')],LEGACY_NAME,'exec'),ns)
    if {path:ns['SOURCE_PINS'].get(path)for path in ORIGINAL_DASHBOARD_LEGACY_PINS}!=ORIGINAL_DASHBOARD_LEGACY_PINS:
        raise SafeError('CAIRO_LEGACY_DASHBOARD_PROFILE_UNEXPECTED')
    capture=dict(ns['CAPTURE_PINS']);unrelated={path:digest for path,digest in ns['SOURCE_PINS'].items()if path not in ORIGINAL_DASHBOARD_LEGACY_PINS}
    # Preserve definition-time SOURCE_PINS references by changing only these entries in place.
    ns['SOURCE_PINS'].update({path:DASHBOARD_PINS[path]for path in ORIGINAL_DASHBOARD_LEGACY_PINS})
    if capture!=ns['CAPTURE_PINS']or unrelated!={path:digest for path,digest in ns['SOURCE_PINS'].items()if path not in ORIGINAL_DASHBOARD_LEGACY_PINS}:
        raise SafeError('CAIRO_UNRELATED_LEGACY_PROFILE_CHANGED')
    return ns

def load_legacy(payload):return legacy_program(read_root_payload(payload/'deployment'/LEGACY_NAME,LEGACY_BLOB).decode())

def load_app(payload):
    path=payload/'deployment'/APP_NAME;data=read_root_payload(path,EXTRA_PINS[APP_NAME])
    name='verified_cairo_publisher';module=importlib.util.module_from_spec(importlib.util.spec_from_loader(name,loader=None));module.__file__=str(path);sys.modules[name]=module
    exec(compile(data,str(path),'exec'),module.__dict__)
    if {path:pins[1]for path,pins in module.SOURCE_PINS.items()}!=TARGET_PINS:raise SafeError('CAIRO_APP_SOURCE_PROFILE_MISMATCH')
    try:module.load_manifest(payload/'deployment'/MANIFEST_NAME,EXTRA_PINS[MANIFEST_NAME],0)
    except module.SafeError:
        raise SafeError('CAIRO_VERIFIED_MANIFEST_VALIDATION_FAILED')from None
    return module

def verify_payload(payload):
    for name,digest in HELPER_PINS.items():read_root_payload(payload/'deployment'/name,digest)
    for relative,digest in TARGET_PINS.items():read_root_payload(payload/relative,digest)

def stage_payload(destination,release,runner):
    code,kind=runner([RUNUSER,'-u','fasakha','--','git','-C',str(ROOT),'cat-file','-t',release])
    if code!=0 or kind.strip()!=b'commit':raise SafeError('CAIRO_RELEASE_NOT_A_COMMIT')
    files={'deployment/'+name:digest for name,digest in HELPER_PINS.items()};files.update(TARGET_PINS)
    for relative,digest in files.items():
        code,data=runner([RUNUSER,'-u','fasakha','--','git','-C',str(ROOT),'show',release+':'+relative])
        if code!=0 or not data or blob(data)!=digest:raise SafeError('CAIRO_PINNED_RELEASE_FILE_FAILED: '+relative)
        path=destination/relative;path.parent.mkdir(mode=0o755,parents=True,exist_ok=True)
        for directory in reversed(path.parent.parents):
            if directory==destination or destination in directory.parents:
                info=directory.lstat()
                if not stat.S_ISDIR(info.st_mode)or info.st_uid!=0:raise SafeError('CAIRO_STAGING_DIRECTORY_UNSAFE')
                directory.chmod(0o755)
        info=path.parent.lstat()
        if not stat.S_ISDIR(info.st_mode)or info.st_uid!=0:raise SafeError('CAIRO_STAGING_DIRECTORY_UNSAFE')
        path.parent.chmod(0o755)
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
        with os.fdopen(fd,'wb')as stream:stream.write(data);stream.flush();os.fchmod(stream.fileno(),0o644);os.fsync(stream.fileno())
    verify_payload(destination)

NATIVE_PROOF_PHP=r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
 $waba='468336579702269';$phone='515388018324075';$since=gmdate('Y-m-d H:i:s',time()-7200);$until=gmdate('Y-m-d H:i:s',time()+5);$bytes=0;
 $hashKey=config('app.key');if(is_string($hashKey)&&strpos($hashKey,'base64:')===0)$hashKey=base64_decode(substr($hashKey,7),true);
 if(!is_string($hashKey)||strlen($hashKey)<16)throw new \RuntimeException();
 $decode=static function($cipher)use($crypt){
  if(!is_string($cipher)||strlen($cipher)>4194304)throw new \RuntimeException();
  $plain=$crypt::decryptString($cipher);if(strlen($plain)>1048576)throw new \RuntimeException();
  $value=json_decode($plain,true,256,JSON_THROW_ON_ERROR);if(!is_array($value))throw new \RuntimeException();return [$value,strlen($plain),hash('sha256',$plain)];
 };
 $projected=static function($dto)use($db,$decode,$waba,$phone,$hashKey){
  $scope=$waba.':'.$phone.':';$key=hash('sha256','whatsapp-message-v1:'.$scope.$dto['message_id']);
  $peer=hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$dto['peer_identity'],$hashKey);
  $message=$db::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id')
   ->where('c.waba_id',$waba)->where('c.phone_number_id',$phone)->where('c.peer_hash',$peer)->where('m.message_key',$key)
   ->where('m.direction',$dto['direction'])->where('m.source',$dto['source'])->where('m.type',$dto['type'])
   ->where('m.sent_at',$dto['sent_at'])->first(['m.id','m.conversation_id','m.content']);
  if(!$message)return null;
  try{[$saved]=$decode($message->content);}catch(\Throwable$e){return null;}
  foreach(['message_id','peer_identity','direction','source','type','text','sent_at','peer_user_id','peer_phone']as$field)
   if(($saved[$field]??null)!==($dto[$field]??null))return null;
  $raw=$saved['content']['message']??null;$native=$dto['content']['message']??null;
  if(!is_array($raw)||!is_array($native)||($raw['id']??null)!==$dto['message_id']||($raw['type']??null)!==$dto['type']
   ||(string)($raw['timestamp']??'')!==(string)($native['timestamp']??'')||($raw['text']['body']??null)!==$dto['text'])return null;
  $peerField=$dto['direction']==='inbound'?'from':'to';$userField=$dto['direction']==='inbound'?'from_user_id':'to_user_id';
  // Missing fields may be supplied by the authenticated protocol's explicit
  // contacts pair. Present native fields must agree; never infer a peer here.
  foreach([$native,$raw]as$original){
   if(array_key_exists($userField,$original)&&$original[$userField]!==$dto['peer_user_id'])return null;
   if(array_key_exists($peerField,$original)&&(!is_string($original[$peerField])||ltrim($original[$peerField],'+')!==$dto['peer_phone']))return null;
   if(!array_key_exists($peerField,$original)&&!array_key_exists($userField,$original))return null;
  }
  return $message;
 };
 $inbound=[];$outbound=[];
 $events=$db::table('whatsapp_webhook_events')->where('received_at','>=',$since)->where('received_at','<=',$until)
  ->whereNotNull('processed_at')->orderByDesc('id')->limit(200)->get(['id','payload','payload_hash']);
 foreach($events as$event){
  if($db::table('whatsapp_inbox_ingestion_failures')->where('event_id',$event->id)->exists())continue;
  try{[$raw,$length,$hash]=$decode($event->payload);}catch(\Throwable$e){continue;}
  $bytes+=$length;if($bytes>16777216)break;
  if(!is_string($event->payload_hash)||!hash_equals($event->payload_hash,$hash))continue;
  $report=\App\Support\WhatsAppInboxProtocol::report($raw,$waba,$phone);
  if(($report['quarantined_count']??1)!==0)continue;
  foreach($report['messages']as$dto){
   if(($dto['type']??null)!=='text'
    ||!is_string($dto['text']??null)||trim($dto['text'])===''||!is_string($dto['message_id']??null)
    ||!is_string($dto['peer_user_id']??null)||$dto['peer_user_id']===''
    ||($dto['peer_identity']??null)!=='user:'.$dto['peer_user_id']
    ||!is_string($dto['sent_at']??null)||$dto['sent_at']<$since||$dto['sent_at']>$until)continue;
   $source=$dto['source']??null;$direction=$dto['direction']??null;
   if($direction==='inbound'&&in_array($source,['messages','standby'],true)){
    $message=$projected($dto);if($message)$inbound[(int)$message->conversation_id][]=['message'=>$message,'dto'=>$dto];continue;
   }
   if($direction!=='outbound'||!in_array($source,['standby','smb_message_echoes'],true)
    ||!is_string($dto['peer_phone']??null)||$dto['peer_phone']==='')continue;
   $message=$projected($dto);
   if(!$message)continue;
   $outbound[]=['event_id'=>(int)$event->id,'message'=>$message,'dto'=>$dto];
  }
 }
 foreach($outbound as$business){
  $message=$business['message'];$dto=$business['dto'];
  foreach($inbound[(int)$message->conversation_id]??[]as$prior){
    $incoming=$prior['message'];$peer=$prior['dto'];
    if($peer['sent_at']>$dto['sent_at']||$peer['peer_user_id']!==$dto['peer_user_id']
     ||(($peer['peer_phone']??null)!==null&&$peer['peer_phone']!==$dto['peer_phone']))continue;
    while(ob_get_level()>0)ob_end_clean();
    echo json_encode(['status'=>'NATIVE_BUSINESS_PROJECTION_READY','source'=>$dto['source'],'peer_binding'=>'EXPLICIT_USER_AND_PHONE',
     'event_id'=>$business['event_id'],'message_id'=>(int)$message->id,'inbound_message_id'=>(int)$incoming->id,'conversation_id'=>(int)$message->conversation_id],JSON_THROW_ON_ERROR);exit(0);
  }
 }
}catch(\Throwable$e){}
exit(1);
'''


def native_proof(runner):
    code,output=runner([RUNUSER,'-u','fasakha','--',PHP,'-r',NATIVE_PROOF_PHP])
    try:value=json.loads(output)if code==0 else None
    except(TypeError,ValueError):value=None
    fields={'status','source','peer_binding','event_id','message_id','inbound_message_id','conversation_id'}
    if (not isinstance(value,dict)or set(value)!=fields or value['status']!='NATIVE_BUSINESS_PROJECTION_READY'
            or value['source']not in('standby','smb_message_echoes')or value['peer_binding']!='EXPLICIT_USER_AND_PHONE'
            or any(type(value[key])is not int or value[key]<1 for key in ('event_id','message_id','inbound_message_id','conversation_id'))):
        raise SafeError('CAIRO_NATIVE_BUSINESS_PROJECTION_PROOF_REQUIRED_PRIOR_PIPELINE_REMAINS_RUNNING')
    return value


MODE_PHP=r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
try {
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $orders=config('whatsapp_orders');$mode=$orders['mode']??null;
 if(!is_array($orders)||!in_array($mode,['review','auto'],true))exit(1);
 if(($argv[1]??'enable')==='enable' && (($orders['enabled']??null)!==true||($orders['model']??null)!=='gpt-6-luna'||!is_string($orders['api_key']??null)||$orders['api_key']===''))exit(1);
 if($mode==='auto') {
  if((string)($orders['automation_actor_id']??'')!=='1')exit(1);
  $branches=$orders['allowed_branch_ids']??null;if(!is_array($branches))exit(1);$branches=array_map('strval',$branches);sort($branches,SORT_STRING);$expected=['94','301','307','363'];sort($expected,SORT_STRING);if($branches!==$expected)exit(1);
  foreach(['activation_message_id','activation_event_id']as$key)if(!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/',(string)($orders[$key]??'')))exit(1);
  $at=config('whatsapp_cart.activated_at');if(!is_string($at))exit(1);$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$at,new \DateTimeZone('UTC'));
  $errors=\DateTimeImmutable::getLastErrors();
  if(!$date||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0))||$date->format('Y-m-d H:i:s')!==$at||substr($at,0,4)==='0000'||$date>new \DateTimeImmutable('now +5 seconds',new \DateTimeZone('UTC')))exit(1);
 }
 $fingerprint=hash('sha256',serialize(config()->all()));while(ob_get_level()>0)ob_end_clean();echo json_encode(['mode'=>$mode,'fingerprint'=>$fingerprint],JSON_THROW_ON_ERROR);
}catch(\Throwable $e){exit(1);}
'''

def mode(runner,enable=True):
    code,output=runner([RUNUSER,'-u','fasakha','--',PHP,'-r',MODE_PHP,'enable'if enable else'install-only'])
    try:value=json.loads(output)if code==0 else None
    except (TypeError,ValueError):value=None
    if not isinstance(value,dict)or set(value)!={'mode','fingerprint'}or value['mode']not in('review','auto')or not isinstance(value['fingerprint'],str)or not re.fullmatch('[a-f0-9]{64}',value['fingerprint']):raise SafeError('CAIRO_EFFECTIVE_CONFIGURATION_NEEDS_REVIEW')
    return value

def profiles(root,uid,app,legacy,current_mode,target=False):
    immutable={path:digest for path,digest in legacy['SOURCE_PINS'].items()if path not in TARGET_PINS and path!=legacy['CART_PATH']}
    result=checked_legacy_sources(root,uid,legacy,immutable)
    for relative,digest in DASHBOARD_PINS.items():
        item,metadata=checked_snapshot(root,uid,app,relative)
        if item is None or app.blob(item.content)!=digest:raise SafeError('CAIRO_DASHBOARD_RUNTIME_NEEDS_REVIEW: '+relative)
        result[relative]=metadata
    if current_mode=='review':result.update(checked_legacy_sources(root,uid,legacy,{legacy['CART_PATH']:legacy['SOURCE_PINS'][legacy['CART_PATH']]}))
    for relative,(old,new)in app.SOURCE_PINS.items():
        item,metadata=checked_snapshot(root,uid,app,relative,absent_ok=old is None)
        if item is None:
            if target or old is not None:raise SafeError('CAIRO_REQUIRED_RUNTIME_MISSING')
        elif app.blob(item.content)not in((new,)if target else(old,new)):raise SafeError('CAIRO_RUNTIME_SOURCE_NEEDS_REVIEW: '+relative)
        result[relative]=metadata
    for relative in dict.fromkeys(('.env','bootstrap/cache/config.php',*app.PROTECTED_PATHS,*app.PROTECTED_BLOBS)):
        required=relative in ('.env','bootstrap/cache/config.php')or relative in app.PROTECTED_BLOBS
        item,metadata=checked_snapshot(root,uid,app,relative,absent_ok=not required,legacy_read_only=relative in app.LEGACY_READ_ONLY_MODELS)
        if relative in app.PROTECTED_BLOBS and (item is None or app.blob(item.content)!=app.PROTECTED_BLOBS[relative]):raise SafeError('CAIRO_PROTECTED_SOURCE_PROFILE_CHANGED: '+relative)
        result[relative]=metadata
    return result

def backup_path(output,kind):
    found=re.findall(rb'BACKUP=(/home/fasakha/whatsapp-release-backups/'+kind+rb'-[A-Za-z0-9_-]+)',output)
    return found[0].decode('ascii')if len(set(found))==1 else None

def app_failure_status(output,app):
    if not isinstance(output,bytes)or not output or len(output)>4096:return 'UNRECOGNIZED'
    try:value=output.rstrip(b'\r\n').decode('ascii')
    except UnicodeError:return 'UNRECOGNIZED'
    fixed=frozenset(('CAIRO_BASE_HELPER_VERIFICATION_FAILED','CAIRO_INSTALL_STOPPED_WITHOUT_SECRET_OUTPUT',
    'USAGE_RUN_AS_FASAKHA_ROOT_MANIFEST_MANIFEST_BLOB','CAIRO_INSTALL_INTERRUPTED',
    'CAIRO_MANIFEST_ARGUMENT_INVALID','CAIRO_MANIFEST_HASH_MISMATCH','CAIRO_MANIFEST_SCOPE_INVALID',
    'CAIRO_PAYLOAD_REQUIRES_PRIVATE_EXTERNAL_DIRECTORY','CAIRO_INSTALL_LOCK_UNSAFE',
    'CAIRO_ANOTHER_INSTALL_OR_CONFIGURATION_RUNNING','CAIRO_BACKUP_REQUIRES_SAME_FILESYSTEM',
    'CAIRO_READ_ONLY_READINESS_FAILED','CAIRO_EFFECTIVE_CONFIGURATION_CHANGED',
    'PROJECT_ROOT_OWNER_OR_TYPE_NEEDS_REVIEW','GIT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW',
    'PRIVATE_BACKUP_DIRECTORY_NEEDS_REVIEW','PROJECT_DIRECTORY_OWNER_OR_TYPE_NEEDS_REVIEW',
    'ATOMIC_RENAME_UNAVAILABLE','ATOMIC_RENAME_FAILED','ATOMIC_EXCHANGE_CHECK_FAILED',
    'FILE_CHANGED_CONCURRENTLY','FILE_CHANGED_DURING_ATOMIC_EXCHANGE',*SNAPSHOT_REASONS))
    if value in fixed:return value
    paths=set(app.SOURCE_PINS)|set(app.PROTECTED_PATHS)|set(app.PROTECTED_BLOBS)
    for prefix in('CAIRO_PAYLOAD_HASH_MISMATCH: ','CAIRO_REQUIRED_SOURCE_MISSING: ',
    'CAIRO_LIVE_HASH_NEEDS_REVIEW: ','CAIRO_PROTECTED_SOURCE_PROFILE_CHANGED: ',
    'CAIRO_PROTECTED_FILE_CHANGED: ','CAIRO_SOURCE_CHANGED_CONCURRENTLY: ',
    'CAIRO_CANDIDATE_LINT_OR_STAGING_FAILED: '):
        if value.startswith(prefix)and value[len(prefix):]in paths:return value
    prefix='CAIRO_WORKERS_MUST_BE_PAUSED_AND_QUIESCENT: '
    if value.startswith(prefix)and value[len(prefix):]in app.QUIET_UNITS:return value
    if re.fullmatch(r'CAIRO_(?:ORIGIN_FILES_RESTORED|ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED); BACKUP=/home/fasakha/whatsapp-release-backups/cairo-origins-[A-Za-z0-9_-]+',value):return value
    return 'UNRECOGNIZED'

def orchestrate(payload,uid,runner=None,validate=None,root=ROOT,legacy=None,app=None,enable=False):
    set_stage('PAYLOAD_VERIFICATION');verify_payload(payload)
    set_stage('LEGACY_LOAD');legacy=legacy or load_legacy(payload)
    set_stage('APP_LOAD');app=app or load_app(payload);runner=runner or legacy['command'];helpers=payload/'deployment'
    set_stage('SOURCE_PROFILE');profiles(root,uid,app,legacy,'auto')
    set_stage('MODE_READINESS');initial=mode(runner,enable)
    set_stage('SOURCE_PROFILE');baseline=profiles(root,uid,app,legacy,initial['mode'])
    set_stage('UNIT_VALIDATOR_LOAD')
    try:validator=validate or legacy['unit_validator'](helpers)
    except legacy['SafeError']:raise SafeError('CAIRO_VERIFIED_UNIT_HELPER_VALIDATION_FAILED')from None
    validate=checked_validator(validator,legacy)
    set_stage('CAPTURE_UNIT_VALIDATION');validate('capture')
    set_stage('ORDER_UNIT_INSTALL')
    try:legacy['run_unit'](helpers,'orders','--install',runner)
    except legacy['SafeError']:raise SafeError('CAIRO_ORDER_UNIT_INSTALL_FAILED')from None
    set_stage('BOTH_UNIT_VALIDATION')
    for kind in('capture','orders'):validate(kind)
    capture_before=legacy['unit_state'](legacy['CAPTURE_TIMER'],runner);orders_before=legacy['unit_state'](legacy['ORDER_TIMER'],runner)
    if enable:
        native_proof(runner)
        code,output=runner([RUNUSER,'-u','fasakha','--',PHP,str(helpers/'whatsapp_ai_connection_check.php')],timeout=240)
        try:ai=json.loads(output)
        except (ValueError,TypeError):ai=None
        if code!=0 or not isinstance(ai,dict)or ai.get('status')!='AI_CONNECTION_READY'or ai.get('ai_calls')!=1:raise SafeError('CAIRO_AI_CHECK_FAILED_PRIOR_PIPELINE_REMAINS_RUNNING')
        print('AI_CONNECTION='+json.dumps({'status':'AI_CONNECTION_READY','ai_calls':1}),flush=True)
    if mode(runner,enable)!=initial or profiles(root,uid,app,legacy,initial['mode'])!=baseline:raise SafeError('CAIRO_SOURCE_OR_CONFIGURATION_CHANGED_BEFORE_PAUSE')
    paused=False;app_backup=None;config_backup=None;published=False
    try:
        for kind in('capture','orders'):validate(kind)
        paused=True
        code,_=runner([SYSTEMCTL,'stop',legacy['CAPTURE_TIMER'],legacy['CAPTURE_SERVICE'],legacy['ORDER_TIMER'],legacy['ORDER_SERVICE']])
        if code!=0:raise SafeError('CAIRO_PIPELINE_PAUSE_FAILED')
        app.assert_workers_quiet(root,lambda _root,args,**kw:runner(args))
        if mode(runner,enable)!=initial or profiles(root,uid,app,legacy,initial['mode'])!=baseline:raise SafeError('CAIRO_SOURCE_OR_CONFIGURATION_CHANGED_AFTER_PAUSE')
        verify_payload(payload)
        code,output=runner([RUNUSER,'-u','fasakha','--','/usr/bin/python3','-B',str(helpers/APP_NAME),'--root',str(ROOT),'--manifest',str(helpers/MANIFEST_NAME),'--manifest-blob',EXTRA_PINS[MANIFEST_NAME]],timeout=300)
        app_backup=backup_path(output,b'cairo-origins')
        if code!=0 or not output.startswith(b'WHATSAPP_CAIRO_DELIVERY_ORIGINS_READY\n')or app_backup is None:raise SafeError('CAIRO_APP_PUBLICATION_FAILED; APP_STATUS='+app_failure_status(output,app))
        published=True
        if mode(runner,enable)!=initial:raise SafeError('CAIRO_CONFIGURATION_CHANGED_DURING_APP_PUBLICATION')
        after=profiles(root,uid,app,legacy,initial['mode'],target=True);protected={path:value for path,value in baseline.items()if path not in TARGET_PINS}
        if {path:after[path]for path in protected}!=protected:raise SafeError('CAIRO_PROTECTED_SOURCE_CHANGED_AFTER_PUBLICATION')
        if not enable:
            validate('orders');legacy['run_unit'](helpers,'orders','--stop',runner);legacy['require_inactive'](runner)
            code,_=runner([SYSTEMCTL,'disable',legacy['ORDER_TIMER']])
            if code!=0:raise SafeError('CAIRO_INSTALL_ONLY_ORDER_BOOT_STOP_FAILED')
            validate('capture');legacy['guard_sources'](root,uid,legacy['CAPTURE_PINS'])
            if initial['mode']=='auto' or capture_before['UnitFileState']not in('enabled','enabled-runtime'):
                code,_=runner([SYSTEMCTL,'enable',legacy['CAPTURE_TIMER']])
                if code!=0:raise SafeError('CAIRO_CAPTURE_INSTALL_BOOT_START_FAILED')
            code,_=runner([SYSTEMCTL,'start',legacy['CAPTURE_TIMER']])
            if code!=0 or legacy['unit_state'](legacy['CAPTURE_TIMER'],runner)['ActiveState']!='active':raise SafeError('CAIRO_CAPTURE_INSTALL_START_FAILED')
            if mode(runner,enable)!=initial or profiles(root,uid,app,legacy,initial['mode'],target=True)!=after:raise SafeError('CAIRO_INSTALL_ONLY_CONFIGURATION_CHANGED')
            status='WHATSAPP_CAIRO_CAPTURE_INSTALLED_REVIEW_ONLY'if initial['mode']=='review'else'WHATSAPP_CAIRO_CAPTURE_INSTALLED_AUTOMATION_PAUSED'
            return status,app_backup,None
        native_proof(runner)
        if initial['mode']=='review':
            code,output=runner([RUNUSER,'-u','fasakha','--','/usr/bin/env','PATH=/opt/cpanel/ea-php82/root/usr/bin:/usr/local/bin:/usr/bin:/bin','/usr/bin/python3','-B',str(helpers/'activate_whatsapp_automatic.py'),'--root',str(ROOT),'--actor','1','--branches','94,301,307,363'],timeout=300)
            config_backup=legacy['activation_backup'](output)
            if code!=0 or not output.startswith(b'WHATSAPP_AUTOMATIC_CONFIGURED_TIMER_START_REQUIRED\n')or config_backup is None:raise SafeError('CAIRO_AUTOMATIC_ACTIVATION_FAILED')
        target_mode=mode(runner,enable)
        if target_mode['mode']!='auto'or(initial['mode']=='auto'and target_mode!=initial):raise SafeError('CAIRO_EXISTING_AUTOMATIC_CUTOFFS_CHANGED')
        stable=profiles(root,uid,app,legacy,'auto',target=True);may_change={'.env','bootstrap/cache/config.php',legacy['CART_PATH']}if initial['mode']=='review'else set()
        if any(stable.get(path)!=value for path,value in after.items()if path not in may_change):raise SafeError('CAIRO_PROTECTED_SOURCE_CHANGED_AFTER_ACTIVATION')
        validate('orders');legacy['run_unit'](helpers,'orders','--start',runner)
        if legacy['unit_state'](legacy['ORDER_TIMER'],runner)['ActiveState']!='active':raise SafeError('CAIRO_AUTOMATIC_TIMER_NOT_ACTIVE')
        validate('capture');code,_=runner([SYSTEMCTL,'disable',legacy['CAPTURE_TIMER']])
        if code!=0 or legacy['unit_state'](legacy['ORDER_TIMER'],runner)['ActiveState']!='active':raise SafeError('CAIRO_CAPTURE_BOOT_HANDOFF_FAILED')
        return 'WHATSAPP_CAIRO_AUTOMATIC_ENABLED',app_backup,config_backup
    except BaseException as error:
        recovered=True
        if paused:
            try:
                validate('orders');legacy['run_unit'](helpers,'orders','--stop',runner);legacy['require_inactive'](runner)
                code,_=runner([SYSTEMCTL,'disable',legacy['ORDER_TIMER']])
                if code!=0:raise SafeError('CAIRO_ORDER_FALLBACK_BOOT_STOP_FAILED')
                validate('capture');legacy['guard_sources'](root,uid,legacy['CAPTURE_PINS'])
                if initial['mode']=='review':legacy['restore_enabled'](legacy['CAPTURE_TIMER'],capture_before,runner,validate,'capture')
                if initial['mode']=='auto' or capture_before['ActiveState']!='active':
                    code,_=runner([SYSTEMCTL,'enable',legacy['CAPTURE_TIMER']])
                    if code!=0:raise SafeError('CAIRO_CAPTURE_FALLBACK_BOOT_FAILED')
                code,_=runner([SYSTEMCTL,'start',legacy['CAPTURE_TIMER']])
                if code!=0 or legacy['unit_state'](legacy['CAPTURE_TIMER'],runner)['ActiveState']!='active':raise SafeError('CAIRO_CAPTURE_RECOVERY_FAILED')
            except BaseException:recovered=False
        reason=str(error)if isinstance(error,SafeError)else(safe_legacy_reason(error,legacy)if isinstance(error,legacy['SafeError'])else'CAIRO_ENABLE_INTERRUPTED')
        status='ORDERS_DISABLED_CAPTURE_ACTIVE'if recovered else'CAPTURE_RECOVERY_REQUIRES_REVIEW'
        suffix=('; APP_BACKUP='+app_backup if app_backup else'')+('; CONFIG_BACKUP='+config_backup if config_backup else'')
        if published:suffix+='; VERIFIED_APP_EXTENSION_RETAINED'
        raise SafeError(reason+'; '+status+suffix)from None

def main():
    try:
        set_stage('ARGUMENT_VALIDATION');args=sys.argv[1:]
        if os.geteuid()!=0 or len(args)!=5 or args[0]not in('--install-only','--enable')or args[1]!='--release'or not re.fullmatch('[a-f0-9]{40}',args[2])or args[3]!='--legacy-helper':raise SafeError('RUN_ROOT_CAIRO_INSTALL_ONLY_OR_ENABLE_WITH_PINNED_RELEASE_AND_LEGACY_HELPER')
        set_stage('OWNER_LOOKUP');uid=pwd.getpwnam('fasakha').pw_uid;temporary=pathlib.Path('/tmp');info=temporary.lstat()
        if not stat.S_ISDIR(info.st_mode)or info.st_uid!=0 or not info.st_mode&stat.S_ISVTX:raise SafeError('CAIRO_STICKY_ROOT_TEMP_UNAVAILABLE')
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(SafeError('CAIRO_ENABLE_INTERRUPTED')))
        set_stage('LEGACY_BOOTSTRAP');legacy=legacy_program(read_root_payload(pathlib.Path(args[4]),LEGACY_BLOB).decode())
        with tempfile.TemporaryDirectory(prefix='fasakhansta-whatsapp-cairo-',dir=temporary)as folder:
            payload=pathlib.Path(folder);payload.chmod(0o755);set_stage('PAYLOAD_STAGING');stage_payload(payload,args[2],legacy['command']);status,app_backup,config_backup=orchestrate(payload,uid,enable=args[0]=='--enable')
        print(status);print('APP_BACKUP='+app_backup)
        if config_backup:print('CONFIG_BACKUP='+config_backup)
        return 0
    except SafeError as error:print(str(error),file=sys.stderr)
    except BaseException as error:
        name=type(error).__name__;name=name if re.fullmatch('[A-Za-z_][A-Za-z0-9_]{0,63}',name)else'UNKNOWN'
        print('CAIRO_ENABLE_STOPPED_WITHOUT_SECRET_OUTPUT; STAGE='+STAGE+'; EXCEPTION_CLASS='+name,file=sys.stderr)
    return 1

if __name__=='__main__':sys.exit(main())
PY
