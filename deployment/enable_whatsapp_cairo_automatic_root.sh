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
LEGACY_BLOB='84a6968a178fe975511d21dc1aab62b4659ff7be'
APP_NAME='apply_whatsapp_cairo_origins.py'
MANIFEST_NAME='whatsapp_cairo_origins_manifest.json'
EXTRA_PINS={LEGACY_NAME:LEGACY_BLOB,APP_NAME:'8139dd86184a610ae28794ed44fde3fdc0194ba3',MANIFEST_NAME:'e79f6c292fcdc6e94681e2b04e12efd908f1e206'}
TARGET_PINS={
'app/Support/WhatsAppInboxProtocol.php':'ecde67c6a87ca8068bb077892fdbea522d89f217',
'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php':'cdfdf44d5b01db6b9c78bb97b585e2a6463cd137',
'app/Services/Dashboard/PhoneDelivery.php':'227027cfdcd68720b74220e3ecd0abb9e156a5d7',
'app/Services/Dashboard/PosServiceTicket.php':'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
'app/Http/Controllers/Dashboard/PhoneOrdersController.php':'478776e72edf67d9eaaf00abd49d97a2fa4720c8',
'public/dashboard/js/phone-orders.js':'66597844cc01d9371b1952f79b22fb1833bf2ac0',
'app/Services/Dashboard/WhatsAppOrderWorkflow.php':'1b9d13a63187fa91db975e597b382fe79a9a5aa8',
}
HELPER_PINS={
'activate_whatsapp_automatic.py':'008fff7a56e4c9d2ff849df6f653dfb6b6427371',
'configure_whatsapp_orders.py':'a0780b40adc404f81b5bd2b9934b50d13dc0797a',
'rotate_whatsapp_messaging_token.py':'b824ea3c09f0314d5debdb278a0d02d37f41bfce',
'rotate_whatsapp_catalog_token.py':'eaf40b872ec1dde0cbffa08e507e56a03f62591f',
'install_whatsapp_inbox.py':'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98',
'install_whatsapp_automatic_root.sh':'4ac9bb0426407b349fba7e1ac8fabb830c566617',
'install_whatsapp_capture_root.sh':'c3571f9cf4ab37171c9d6cbf2fc8ddae9e067316',
'whatsapp_ai_connection_check.php':'fa0bf7a51bff932c274c34398bddbc6aeed04e30',
**EXTRA_PINS,
}
class SafeError(Exception):pass

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
    exec(compile(content.split(marker,1)[1][:-len('\nPY\n')],LEGACY_NAME,'exec'),ns);return ns

def load_legacy(payload):return legacy_program(read_root_payload(payload/'deployment'/LEGACY_NAME,LEGACY_BLOB).decode())

def load_app(payload):
    path=payload/'deployment'/APP_NAME;data=read_root_payload(path,EXTRA_PINS[APP_NAME])
    name='verified_cairo_publisher';module=importlib.util.module_from_spec(importlib.util.spec_from_loader(name,loader=None));module.__file__=str(path);sys.modules[name]=module
    exec(compile(data,str(path),'exec'),module.__dict__)
    if {path:pins[1]for path,pins in module.SOURCE_PINS.items()}!=TARGET_PINS:raise SafeError('CAIRO_APP_SOURCE_PROFILE_MISMATCH')
    module.load_manifest(payload/'deployment'/MANIFEST_NAME,EXTRA_PINS[MANIFEST_NAME],0);return module

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
 $waba='468336579702269';$phone='515388018324075';$since=gmdate('Y-m-d H:i:s',time()-7200);$bytes=0;
 $decode=static function($cipher)use($crypt){
  if(!is_string($cipher)||strlen($cipher)>4194304)throw new \RuntimeException();
  $plain=$crypt::decryptString($cipher);if(strlen($plain)>1048576)throw new \RuntimeException();
  $value=json_decode($plain,true,256,JSON_THROW_ON_ERROR);if(!is_array($value))throw new \RuntimeException();return [$value,strlen($plain)];
 };
 $events=$db::table('whatsapp_webhook_events')->where('received_at','>=',$since)->whereNotNull('processed_at')->orderByDesc('id')->limit(200)->get(['id','payload']);
 foreach($events as$event){
  if($db::table('whatsapp_inbox_ingestion_failures')->where('event_id',$event->id)->exists())continue;
  try{[$raw,$length]=$decode($event->payload);}catch(\Throwable$e){continue;}
  $bytes+=$length;if($bytes>16777216)break;
  $report=\App\Support\WhatsAppInboxProtocol::report($raw,$waba,$phone);
  if(($report['quarantined_count']??1)!==0)continue;
  foreach($report['messages']as$dto){
   if(($dto['source']??null)!=='standby'||($dto['direction']??null)!=='outbound'||($dto['type']??null)!=='text'
    ||!is_string($dto['text']??null)||trim($dto['text'])===''||!is_string($dto['message_id']??null)
    ||!is_string($dto['peer_user_id']??null)||$dto['peer_user_id']===''||!is_string($dto['peer_phone']??null)||$dto['peer_phone']===''
    ||!is_string($dto['sent_at']??null)||$dto['sent_at']<$since||$dto['sent_at']>gmdate('Y-m-d H:i:s',time()+5))continue;
   $key=hash('sha256','whatsapp-message-v1:'.$waba.':'.$phone.':'.$dto['message_id']);
   $message=$db::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id')
    ->where('c.waba_id',$waba)->where('c.phone_number_id',$phone)->where('m.message_key',$key)
    ->where('m.direction','outbound')->where('m.source','standby')->where('m.type','text')
    ->where('m.sent_at','>=',$since)->first(['m.id','m.conversation_id','m.content']);
   if(!$message)continue;
   try{[$saved]=$decode($message->content);}catch(\Throwable$e){continue;}
   foreach(['message_id','direction','source','type','text','peer_user_id','peer_phone']as$field)
    if(($saved[$field]??null)!==($dto[$field]??null))continue 2;
   $inbound=$db::table('whatsapp_inbox_messages')->where('conversation_id',$message->conversation_id)->where('direction','inbound')
    ->where('type','text')->where('sent_at','>=',$since)->where('sent_at','<=',$dto['sent_at'])->orderByDesc('id')->limit(20)->get(['id','content']);
   foreach($inbound as$incoming){
    try{[$peer]=$decode($incoming->content);}catch(\Throwable$e){continue;}
    if(($peer['direction']??null)!=='inbound'||($peer['peer_user_id']??null)!==$dto['peer_user_id']
     ||(($peer['peer_phone']??null)!==null&&$peer['peer_phone']!==$dto['peer_phone']))continue;
    while(ob_get_level()>0)ob_end_clean();
    echo json_encode(['status'=>'NATIVE_STANDBY_PROJECTION_READY','source'=>'standby','peer_binding'=>'EXPLICIT_USER_AND_PHONE',
     'event_id'=>(int)$event->id,'message_id'=>(int)$message->id,'inbound_message_id'=>(int)$incoming->id,'conversation_id'=>(int)$message->conversation_id],JSON_THROW_ON_ERROR);exit(0);
   }
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
    if (not isinstance(value,dict)or set(value)!=fields or value['status']!='NATIVE_STANDBY_PROJECTION_READY'
            or value['source']!='standby'or value['peer_binding']!='EXPLICIT_USER_AND_PHONE'
            or any(type(value[key])is not int or value[key]<1 for key in ('event_id','message_id','inbound_message_id','conversation_id'))):
        raise SafeError('CAIRO_NATIVE_STANDBY_PROJECTION_PROOF_REQUIRED_PRIOR_PIPELINE_REMAINS_RUNNING')
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
    result=legacy['guard_sources'](root,uid,immutable)
    if current_mode=='review':result.update(legacy['guard_sources'](root,uid,{legacy['CART_PATH']:legacy['SOURCE_PINS'][legacy['CART_PATH']]}))
    for relative,(old,new)in app.SOURCE_PINS.items():
        item,metadata=app.guarded_snapshot(root/relative,uid,root,absent_ok=old is None)
        if item is None:
            if target or old is not None:raise SafeError('CAIRO_REQUIRED_RUNTIME_MISSING')
        elif app.blob(item.content)not in((new,)if target else(old,new)):raise SafeError('CAIRO_RUNTIME_SOURCE_NEEDS_REVIEW: '+relative)
        result[relative]=metadata
    for relative in dict.fromkeys(('.env','bootstrap/cache/config.php',*app.PROTECTED_PATHS,*app.PROTECTED_BLOBS)):
        required=relative in ('.env','bootstrap/cache/config.php')or relative in app.PROTECTED_BLOBS
        item,metadata=app.guarded_snapshot(root/relative,uid,root,absent_ok=not required,legacy_read_only=relative in app.LEGACY_READ_ONLY_MODELS)
        if relative in app.PROTECTED_BLOBS and (item is None or app.blob(item.content)!=app.PROTECTED_BLOBS[relative]):raise SafeError('CAIRO_PROTECTED_SOURCE_PROFILE_CHANGED: '+relative)
        result[relative]=metadata
    return result

def backup_path(output,kind):
    found=re.findall(rb'BACKUP=(/home/fasakha/whatsapp-release-backups/'+kind+rb'-[A-Za-z0-9_-]+)',output)
    return found[0].decode('ascii')if len(set(found))==1 else None

def orchestrate(payload,uid,runner=None,validate=None,root=ROOT,legacy=None,app=None,enable=False):
    verify_payload(payload);legacy=legacy or load_legacy(payload);app=app or load_app(payload);runner=runner or legacy['command'];helpers=payload/'deployment'
    profiles(root,uid,app,legacy,'auto');initial=mode(runner,enable);baseline=profiles(root,uid,app,legacy,initial['mode']);validate=validate or legacy['unit_validator'](helpers)
    for kind in('capture','orders'):validate(kind)
    legacy['run_unit'](helpers,'orders','--install',runner)
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
        if code!=0 or not output.startswith(b'WHATSAPP_CAIRO_DELIVERY_ORIGINS_READY\n')or app_backup is None:raise SafeError('CAIRO_APP_PUBLICATION_FAILED')
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
        reason=str(error)if isinstance(error,(SafeError,legacy['SafeError']))else'CAIRO_ENABLE_INTERRUPTED'
        status='ORDERS_DISABLED_CAPTURE_ACTIVE'if recovered else'CAPTURE_RECOVERY_REQUIRES_REVIEW'
        suffix=('; APP_BACKUP='+app_backup if app_backup else'')+('; CONFIG_BACKUP='+config_backup if config_backup else'')
        if published:suffix+='; VERIFIED_APP_EXTENSION_RETAINED'
        raise SafeError(reason+'; '+status+suffix)from None

def main():
    try:
        args=sys.argv[1:]
        if os.geteuid()!=0 or len(args)!=5 or args[0]not in('--install-only','--enable')or args[1]!='--release'or not re.fullmatch('[a-f0-9]{40}',args[2])or args[3]!='--legacy-helper':raise SafeError('RUN_ROOT_CAIRO_INSTALL_ONLY_OR_ENABLE_WITH_PINNED_RELEASE_AND_LEGACY_HELPER')
        uid=pwd.getpwnam('fasakha').pw_uid;temporary=pathlib.Path('/tmp');info=temporary.lstat()
        if not stat.S_ISDIR(info.st_mode)or info.st_uid!=0 or not info.st_mode&stat.S_ISVTX:raise SafeError('CAIRO_STICKY_ROOT_TEMP_UNAVAILABLE')
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(SafeError('CAIRO_ENABLE_INTERRUPTED')))
        legacy=legacy_program(read_root_payload(pathlib.Path(args[4]),LEGACY_BLOB).decode())
        with tempfile.TemporaryDirectory(prefix='fasakhansta-whatsapp-cairo-',dir=temporary)as folder:
            payload=pathlib.Path(folder);payload.chmod(0o755);stage_payload(payload,args[2],legacy['command']);status,app_backup,config_backup=orchestrate(payload,uid,enable=args[0]=='--enable')
        print(status);print('APP_BACKUP='+app_backup)
        if config_backup:print('CONFIG_BACKUP='+config_backup)
        return 0
    except SafeError as error:print(str(error),file=sys.stderr)
    except BaseException:print('CAIRO_ENABLE_STOPPED_WITHOUT_SECRET_OUTPUT',file=sys.stderr)
    return 1

if __name__=='__main__':sys.exit(main())
PY
