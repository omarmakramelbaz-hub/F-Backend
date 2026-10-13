#!/usr/bin/env python3
"""Read only the fixed failed forward preflight and its candidate predicates."""
import argparse
import ast
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import stat
import subprocess
import sys
import tempfile
import types

ROOT = pathlib.Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
OLD_BACKUP = 'order-semantic-ngb77kbf'
OLD_PIPELINE = 'semantic-pipeline-state-a_wh7l9m'
FORWARD_BACKUP = 'order-semantic-forward-ebr178vc'
FORWARD_PIPELINE = 'semantic-forward-pipeline-n32nlsny'
IDS = [5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338]
MAX_METADATA_OUTPUT = 32768
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
PUBLISHER_BLOB = 'ad881bc4529ce112890f1e73f1c323ab6664ea60'
DIAGNOSTIC_BLOB = '47392c374f7fa48cc084eb834033d9ef651e95ed'
FACTORY_BLOB = '68f2f8215a31b799df1f4d501110e89d39574618'
HELPER_PATH = 'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'
PROTOCOL_PATH = 'app/Support/WhatsAppInboxProtocol.php'
HELPER_BLOB = '89b98259217e02d300bc7aa5c70c7b58eb0dfbff'
PROTOCOL_BLOB = 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd'
BASELINE_HELPER_BLOB = 'f2ee72b55d9fd27478f5da852cc3533dbb6de747'
BASELINE_PROTOCOL_BLOB = 'ecde67c6a87ca8068bb077892fdbea522d89f217'
ACCESS_PATH = 'app/Services/Dashboard/WhatsAppInboxAccess.php'
ACCESS_BLOB = '186789d42113e81818de8d82a2327e3ee9d8d0b5'


def blob(data):
    return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()


def signature(s):
    return (s.st_dev,s.st_ino,s.st_uid,s.st_gid,s.st_mode,s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)


def verified_source(release,path,pin):
    prefix=['/sbin/runuser','-u','fasakha','--','git','-C',str(ROOT)]
    tree=subprocess.check_output(prefix+['ls-tree','-z',release,path],stderr=subprocess.DEVNULL,timeout=20)
    if tree!=b'100644 blob '+pin.encode()+b'\t'+path.encode()+b'\0':raise ValueError('SOURCE_TREE')
    value=subprocess.check_output(prefix+['show',release+':'+path],stderr=subprocess.DEVNULL,timeout=20)
    if not value or len(value)>8388608 or blob(value)!=pin:raise ValueError('SOURCE_PIN')
    return value


def load_read_module(data,name):
    # Frozen modules have declarations and an __main__ guard; no main is called.
    spec=importlib.util.spec_from_loader(name,loader=None)
    module=importlib.util.module_from_spec(spec);sys.modules[name]=module
    exec(compile(data,'<verified-read-only-module>','exec'),module.__dict__)
    return module


def factory_namespace(data):
    if blob(data)!=FACTORY_BLOB:raise ValueError('FACTORY_PIN')
    wanted={'ORIGINAL_RECOVERY_SHA256','CANDIDATE_HELPER_BLOB','CANDIDATE_PROTOCOL_BLOB',
            'BASELINE_PROTOCOL_BLOB','HEADER_PHP','BODY_PHP','recovery_php'}
    nodes=[]
    for node in ast.parse(data).body:
        name=node.name if isinstance(node,ast.FunctionDef)else None
        if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):name=node.targets[0].id
        if name in wanted:nodes.append(node)
    if len(nodes)!=len(wanted):raise ValueError('FACTORY_DECLARATIONS')
    ns={'hashlib':hashlib};exec(compile(ast.Module(body=nodes,type_ignores=[]),'<verified-read-only-factory>','exec'),ns)
    return types.SimpleNamespace(**ns)


def row_diagnostics_php():
    return ROW_PHP


ROW_PHP = r'''
 $rowDiagnostics=static function(object$thread,object$message,array$before,array$incoming):array{
  $class=\App\Services\Dashboard\WhatsAppNativePlaceholderHydration::class;
  $old=$before['content']['message']??[];$full=$incoming['content']['message']??[];
  $old=is_array($old)?$old:[];$full=is_array($full)?$full:[];
  $canonical=static function($value)use(&$canonical){if(!is_array($value))return$value;
   foreach($value as$k=>$v)$value[$k]=$canonical($v);if(!array_is_list($value))ksort($value,SORT_STRING);return$value;};
  $kind=static function(array$a,string$k):string{if(!array_key_exists($k,$a))return'MISSING';$v=$a[$k];
   return$v===null?'NULL':(is_array($v)?'ARRAY':(is_string($v)?'STRING':(is_bool($v)?'BOOLEAN':(is_int($v)?'INTEGER':(is_float($v)?'NUMBER':'OTHER')))));};
  $same=$canonical($old['referral']??null)===$canonical($full['referral']??null);
  $referral=$full['referral']??null;$array=is_array($referral);$r=$array?$referral:[];
  $enrichment=!$same;$mb=function_exists('mb_check_encoding');
  $reserved=['from','from_user_id','to','to_user_id','peer_identity','peer_phone','peer_user_id','waba_id','phone_number_id',
   'conversation_id','message_id','timestamp','context','errors','type','order','customer','customer_name','customer_phone',
   'name','phone','address','branch','items','quantity','price','amount','total','role','system','prompt','instruction','instructions','tool','tools'];
  $known=['source_type','source_id','source_url','headline','body','ctwa_clid','media_type','image_url','video_url','thumbnail_url','referrer_id'];
  $keyString=true;$keySyntax=true;$keyReserved=true;$scalar=true;$strings=true;$utf=true;$control=true;
  $unknown=['count'=>0,'keys_are_strings'=>true,'keys_match_syntax'=>true,'keys_not_reserved'=>true,
   'values_are_allowed_scalars'=>true,'strings_within_limit'=>true,'strings_valid_utf8'=>true,'strings_control_free'=>true,
   'has_nested_value'=>false,'has_float'=>false,'has_negative_integer'=>false];
  foreach($r as$k=>$v){
   $ks=is_string($k);$syntax=$ks&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$k)===1;$kr=$ks&&!in_array($k,$reserved,true);
   $allowed=is_string($v)||$v===null||is_bool($v)||(is_int($v)&&$v>=0);
   $limit=!is_string($v)||strlen($v)<=4096;$valid=!is_string($v)||($mb&&mb_check_encoding($v,'UTF-8'));
   $clean=!is_string($v)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v)===0;
   $keyString=$keyString&&$ks;$keySyntax=$keySyntax&&$syntax;$keyReserved=$keyReserved&&$kr;
   $scalar=$scalar&&$allowed;$strings=$strings&&$limit;$utf=$utf&&$valid;$control=$control&&$clean;
   if(!is_string($k)||!in_array($k,$known,true)){
    $unknown['count']=min(17,$unknown['count']+1);
    foreach(['keys_are_strings'=>$ks,'keys_match_syntax'=>$syntax,'keys_not_reserved'=>$kr,'values_are_allowed_scalars'=>$allowed,
     'strings_within_limit'=>$limit,'strings_valid_utf8'=>$valid,'strings_control_free'=>$clean]as$n=>$flag)$unknown[$n]=$unknown[$n]&&$flag;
    $unknown['has_nested_value']=$unknown['has_nested_value']||is_array($v)||is_object($v);
    $unknown['has_float']=$unknown['has_float']||is_float($v);$unknown['has_negative_integer']=$unknown['has_negative_integer']||(is_int($v)&&$v<0);
   }
  }
  $json=false;try{$json=strlen(json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))<=24576;}catch(\Throwable$e){}
  $referralReady=false;$exception='NONE';
  try{$method=new \ReflectionMethod($class,'referralTransition');$method->setAccessible(true);$referralReady=$method->invoke(null,$old,$full)===true;}
  catch(\TypeError$e){$exception='TYPE';}catch(\JsonException$e){$exception='ENCODING';}catch(\Throwable$e){$exception='OTHER';}
  $urlMethod=new \ReflectionMethod($class,'referralUrl');$urlMethod->setAccessible(true);$urls=[];
  foreach(['source_url','image_url','video_url','thumbnail_url']as$f){
   $present=array_key_exists($f,$r);$v=$r[$f]??null;$applicable=$f==='source_url'||($present&&$v!==null);$parsed=false;
   try{$parsed=is_string($v)?parse_url($v):false;}catch(\Throwable$e){}
   $actual=false;try{$actual=$urlMethod->invoke(null,$v)===true;}catch(\Throwable$e){}
   $urls[$f]=['present'=>$present,'type'=>$kind($r,$f),'applicable'=>$applicable,'actual_valid'=>$actual,
    'string_nonempty'=>is_string($v)&&$v!=='','within_limit'=>is_string($v)&&strlen($v)<=4096,
    'no_ascii_space_or_control'=>is_string($v)&&preg_match('/[\x00-\x20\x7f]/',$v)===0,
    'parse_success'=>is_array($parsed),'scheme_allowed'=>is_array($parsed)&&in_array(strtolower($parsed['scheme']??''),['http','https'],true),
    'host_valid'=>is_array($parsed)&&is_string($parsed['host']??null)&&$parsed['host']!==''&&strlen($parsed['host'])<=253,
    'credentials_absent'=>is_array($parsed)&&!array_key_exists('user',$parsed)&&!array_key_exists('pass',$parsed),
    'filter_valid'=>is_string($v)&&filter_var($v,FILTER_VALIDATE_URL)!==false];
  }
  $peer=[];foreach(['message_id','peer_identity','peer_phone','peer_user_id']as$f)$peer[$f]=array_key_exists($f,$before)&&$before[$f]===($incoming[$f]??null);
  $routing=[];foreach(['from','from_user_id','to','to_user_id','context']as$f)$routing[$f]=$canonical($old[$f]??null)===$canonical($full[$f]??null);
  $optional=[];foreach(['headline','body','ctwa_clid']as$f)$optional[$f]=!array_key_exists($f,$r)||is_string($r[$f]);
  $knownTypes=[];foreach(['source_type','source_id','media_type','headline','body','ctwa_clid']as$f)$knownTypes[$f]=$kind($r,$f);
  $projected=false;$http=null;try{$p=(new \App\Services\Dashboard\WhatsAppInboxMessage())->projected($thread,$message);
   $projected=($p['native_id']??null)===($before['message_id']??null)&&($p['kind']??null)==='unsupported'&&array_key_exists('text',$p)&&$p['text']===null;
  }catch(\Throwable$e){$code=method_exists($e,'getStatusCode')?$e->getStatusCode():null;$http=is_int($code)&&$code>=400&&$code<=599?$code:null;}
  $time=static function($v){if(!is_string($v))return null;$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new \DateTimeZone('UTC'));return$d&&$d->format('Y-m-d H:i:s')===$v?$d:null;};
  $a=$time($message->sent_at??null);$b=$time($incoming['sent_at']??null);
  $unsupported=!array_key_exists('unsupported',$old)||(is_array($old['unsupported'])&&!array_diff(array_keys($old['unsupported']),['type','raw_type'])
   &&!array_filter($old['unsupported'],static fn($v)=>!in_array($v,['unknown','unsupported'],true)));
  $errors=$old['errors']??null;
  $gates=[
   'thread_scope'=>($thread->waba_id??null)===\App\Services\Dashboard\WhatsAppInboxConsumer::WABA_ID&&($thread->phone_number_id??null)===\App\Services\Dashboard\WhatsAppInboxConsumer::PHONE_ID,
   'row_scope'=>($message->source??null)==='messages'&&($message->direction??null)==='inbound'&&($message->type??null)==='unsupported'&&(int)($message->conversation_id??0)===(int)($thread->id??0),
   'dto_shape'=>($incoming['source']??null)==='messages'&&($incoming['direction']??null)==='inbound'&&($incoming['type']??null)==='text'
    &&($before['type']??null)==='unsupported'&&($before['source']??null)===($message->source??null)&&($before['direction']??null)==='inbound'
    &&($before['sent_at']??null)===($message->sent_at??null)&&array_key_exists('text',$before)&&$before['text']===null&&!isset($before['content']['hydration']),
   'peer_matches'=>$peer,'routing_context_matches'=>$routing,
   'native_ids_types'=>($old['type']??null)==='unsupported'&&($full['type']??null)==='text'&&($old['id']??null)===($before['message_id']??null)&&($full['id']??null)===($before['message_id']??null),
   'incoming_text'=>is_string($incoming['text']??null)&&trim($incoming['text'])!==''&&($full['text']['body']??null)===$incoming['text'],
   'incoming_errors_absent'=>!array_key_exists('errors',$full),
   'old_error_131060'=>is_array($errors)&&array_is_list($errors)&&count($errors)===1&&is_array($errors[0])&&($errors[0]['code']??null)===131060,
   'old_keys_allowed'=>!array_diff(array_keys($old),['id','from','from_user_id','to','to_user_id','timestamp','type','errors','unsupported','context','referral']),
   'incoming_keys_allowed'=>!array_diff(array_keys($full),['id','from','from_user_id','to','to_user_id','timestamp','type','text','context','referral']),
   'unsupported_shape'=>$unsupported,'native_time_window'=>$a!==null&&$b!==null&&$b>=$a&&$b->getTimestamp()-$a->getTimestamp()<=3600,
   'referral_canonical_equal'=>$same,'old_referral_absent_or_null'=>!array_key_exists('referral',$old)||$old['referral']===null,
   'incoming_referral_associative'=>$array&&!array_is_list($r),'incoming_referral_nonempty'=>$r!==[],'incoming_referral_count_allowed'=>count($r)<=16,
   'keys_are_strings'=>$keyString,'keys_match_syntax'=>$keySyntax,'keys_not_reserved'=>$keyReserved,
   'values_are_allowed_scalars'=>$scalar,'strings_within_limit'=>$strings,'strings_valid_utf8'=>$utf,'strings_control_free'=>$control,'json_within_limit'=>$json,
   'source_type_allowed'=>in_array($r['source_type']??null,['ad','post'],true),
   'source_id_valid'=>is_string($r['source_id']??null)&&preg_match('/\A[1-9][0-9]{0,31}\z/',$r['source_id'])===1,
   'source_id_string_within_limit'=>is_string($r['source_id']??null)&&strlen($r['source_id'])<=32,
   'source_id_positive_decimal'=>is_string($r['source_id']??null)&&preg_match('/\A[1-9][0-9]*\z/',$r['source_id'])===1,
   'optional_strings_valid'=>$optional,'media_type_valid'=>!array_key_exists('media_type',$r)||(is_string($r['media_type'])&&in_array(strtolower($r['media_type']),['image','video'],true))];
  return['actual_transition_ready'=>$class::transition($thread,$message,$before,$incoming),'referral_ready'=>$referralReady,
   'referral_exception'=>$exception,'enrichment_applicable'=>$enrichment,'mbstring_available'=>$mb,
   'old_referral_present'=>array_key_exists('referral',$old),'old_referral_type'=>$kind($old,'referral'),
   'old_referral_empty_array'=>array_key_exists('referral',$old)&&$old['referral']===[],
   'incoming_referral_present'=>array_key_exists('referral',$full),'incoming_referral_type'=>$kind($full,'referral'),
   'incoming_referral_count'=>min(17,count($r)),'known_referral_types'=>$knownTypes,'gates'=>$gates,'referral_urls'=>$urls,'unknown_extensions'=>$unknown,
   'projected_ready'=>$projected,'projected_http'=>$http];
 };
'''


TAIL_PHP = r'''
 $stage='ORIGINAL_SCOPE';$initial=$gather(false);$stage='STATUS_7215';$status=$played(false);
 if($initial['pending']!==13||!$status['pending']||$initial['json']!==$originalPlain||!hash_equals($expected,$initial['scope_hash']))throw new \RuntimeException();
 $stage='BEFOREIMAGE_BUDGET';
 if(strlen(json_encode(['original_bundle'=>$initial['bundle'],'status_event'=>$status['event'],'status_failure'=>$status['failure']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>16777216)throw new \RuntimeException();
 $stage='PREDICATES';$summaries=[];$detail=null;
 foreach($initial['bundle']['candidates']as$c){$eventId=$c['event_id'];
  $message=$initial['bundle']['messages'][(string)$c['message_id']];$thread=$initial['bundle']['threads'][(string)$c['conversation_id']];
  $event=null;foreach($initial['bundle']['events']as$e)if((int)$e['id']===$eventId)$event=$e;if(!is_array($event))throw new \RuntimeException();
  $before=json_decode($crypt::decryptString($message['content']),true,512,JSON_THROW_ON_ERROR);
  $raw=json_decode($crypt::decryptString($event['payload']),true,512,JSON_THROW_ON_ERROR);
  $report=\App\Support\WhatsAppInboxProtocol::report($raw,'468336579702269','515388018324075');
  if(count($report['messages']??[])!==1)throw new \RuntimeException();
  $row=$rowDiagnostics((object)$thread,(object)$message,$before,$report['messages'][0]);
  $summary=['event_id'=>$eventId,'transition_ready'=>$row['actual_transition_ready'],'referral_ready'=>$row['referral_ready']];
  if(!$row['referral_ready']){
   $checks=[];foreach(['referral_canonical_equal','old_referral_absent_or_null','incoming_referral_associative','incoming_referral_nonempty',
    'incoming_referral_count_allowed','keys_are_strings','keys_match_syntax','keys_not_reserved','values_are_allowed_scalars','strings_within_limit',
    'strings_valid_utf8','strings_control_free','json_within_limit','source_type_allowed','source_id_valid','source_id_string_within_limit','source_id_positive_decimal','media_type_valid']as$f)$checks[$f]=$row['gates'][$f];
   $checks['mbstring_available']=$row['mbstring_available'];$checks['enrichment_applicable']=$row['enrichment_applicable'];
   foreach($row['gates']['optional_strings_valid']as$f=>$v)$checks[$f.'_valid']=$v;
   foreach($row['referral_urls']as$f=>$v)$checks[$f.'_valid']=!$v['applicable']||$v['actual_valid'];
   $summary['referral_checks']=$checks;
  }
  $summaries[]=$summary;
  if($eventId===5945)$detail=['event_id'=>5945,'predicates'=>$row];
 }
 if(count($summaries)!==13||$detail===null)throw new \RuntimeException();$eventId=null;$immutableOriginal();
 foreach($code as$path=>$before)if($read($path,8388608,($candidate!==''&&str_starts_with($path,$candidate.'/')))!==$before)throw new \RuntimeException();
 if($candidate!==''&&$directory($candidate)!==$candidateIdentity)throw new \RuntimeException();
 $again=$gather(false);if($again!==$initial||$played(false)!==$status)throw new \RuntimeException();
 while(ob_get_level()>0)ob_end_clean();
 echo json_encode(['status'=>'FORWARD_TRANSITION_DIAGNOSTIC_READY','summaries'=>$summaries,'detail'=>$detail,
  'original_scope_valid'=>true,'status_7215_verified'=>true,'writes'=>0,'worker_actions'=>0,'ai_calls'=>0,'orders_created'=>0],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(\Throwable$e){while(ob_get_level()>0)ob_end_clean();
 $stages=['ARGUMENTS','CODE','CANDIDATE_CODE','BOOTSTRAP','ORIGINAL_BACKUP','ORIGINAL_SCOPE','STATUS_7215','BEFOREIMAGE_BUDGET','PREDICATES'];
 echo json_encode(['status'=>'FORWARD_TRANSITION_DIAGNOSTIC_NEEDS_REVIEW','stage'=>in_array($stage,$stages,true)?$stage:'UNKNOWN',
  'event_id'=>in_array($eventId,[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338],true)?$eventId:null,
  'writes'=>0,'worker_actions'=>0,'ai_calls'=>0,'orders_created'=>0],JSON_THROW_ON_ERROR);exit(1);}
'''


def diagnostic_php(original_embedded_recovery,factory_source=None):
    if factory_source is None:factory_source=pathlib.Path(__file__).with_name('whatsapp_order_semantic_forward_recovery.py').read_bytes()
    factory=factory_namespace(factory_source)
    program=factory.recovery_php(original_embedded_recovery)
    marker=' $transitions=static function(array$value)'
    if program.count(marker)!=1:raise ValueError('READ_ONLY_BOUNDARY')
    prefix=program.split(marker,1)[0]
    # The retained gather closure is called only with false. Strip its alternate
    # locking expressions as an additional static read-only boundary.
    prefix=prefix.replace('($lock?$q->lockForUpdate():$q)','$q')
    if any(token in prefix for token in ('lockForUpdate','::transaction','->update(','->delete(','$write=','recoverUnavailableMessages')):
        raise ValueError('READ_ONLY_QUERY_BOUNDARY')
    return prefix+row_diagnostics_php()+TAIL_PHP


def private_directory(path,uid):
    s=path.lstat()
    if not stat.S_ISDIR(s.st_mode)or s.st_uid!=uid or stat.S_IMODE(s.st_mode)!=0o700 or path.resolve()!=path:
        raise ValueError('PRIVATE_DIRECTORY')
    return signature(s)


def original_pipeline_state(diag,entries,parent):
    _,prior,quiet=diag.pipeline_summary(entries)
    if [e['phase']for e in entries]!=['PRIOR_PIPELINES','QUIESCENT','CODE_PUBLISHED','ORDERS_PAUSED_CAPTURE_ACTIVE']:
        raise ValueError('ORIGINAL_PHASES')
    if quiet['recovery_pending']!=13 or entries[2].get('backup')!=str(parent/OLD_BACKUP):
        raise ValueError('ORIGINAL_BINDING')
    if prior['before'][diag.UNITS[0]]['ActiveState']not in('inactive','failed')or prior['before'][diag.UNITS[2]]['ActiveState']!='active':
        raise ValueError('ORIGINAL_TIMERS')
    return prior,quiet


def require_absent(path):
    try:path.lstat()
    except FileNotFoundError:return
    raise ValueError('ORIGINAL_COMPLETION_PRESENT')


def checkpoint_summary(diag,parent,uid,prior,quiet):
    backup=parent/FORWARD_BACKUP;pipeline=parent/FORWARD_PIPELINE
    backup_identity=private_directory(backup,uid);pipeline_identity=private_directory(pipeline,0)
    names=list(backup.iterdir())
    if names:raise ValueError('FORWARD_BACKUP_NOT_EMPTY')
    pipeline_names={p.name for p in pipeline.iterdir()}
    if pipeline_names!={'journal.jsonl'}:raise ValueError('FORWARD_PIPELINE_STATE')
    journal=pipeline/'journal.jsonl';before=signature(journal.lstat())
    raw,_=diag.private_file(journal,0,1048576,ancestry_uid=uid)
    rows=[json.loads(line)for line in raw.splitlines()if line]
    expected={'phase':'FORWARD_PLAN','backup':FORWARD_BACKUP,'original_backup':OLD_BACKUP,'original_pipeline':OLD_PIPELINE,
              'original_scope':quiet['recovery_scope'],'configuration_fingerprint':prior['configuration_fingerprint'],
              'targets':{HELPER_PATH:HELPER_BLOB,PROTOCOL_PATH:PROTOCOL_BLOB}}
    if rows!=[expected]:raise ValueError('FORWARD_PLAN_BINDING')
    return {'plan_bound':True,'phases':['FORWARD_PLAN'],'backup_empty':True,'capture_guard_absent':True},(raw,before,backup_identity,pipeline_identity)


def write_candidate(path,data,uid,gid):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb')as stream:
        os.fchown(stream.fileno(),uid,gid);stream.write(data);stream.flush();os.fsync(stream.fileno())


def main():
    parser=argparse.ArgumentParser(add_help=False);parser.add_argument('--release',required=True);args=parser.parse_args()
    stage='ROOT_CONTEXT'
    try:
        if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{40}',args.release):raise ValueError()
        os.umask(0o077);user=pwd.getpwnam('fasakha');uid,gid=user.pw_uid,user.pw_gid;parent=ROOT.parent/'whatsapp-release-backups'
        stage='SOURCES'
        publisher=verified_source(args.release,'deployment/apply_whatsapp_order_semantic_repair.py',PUBLISHER_BLOB)
        base=verified_source(args.release,'deployment/install_whatsapp_inbox.py',BASE_BLOB)
        diagnostic=verified_source(args.release,'deployment/diagnose_whatsapp_order_semantic_recovery.py',DIAGNOSTIC_BLOB)
        factory=verified_source(args.release,'deployment/whatsapp_order_semantic_forward_recovery.py',FACTORY_BLOB)
        pair={HELPER_PATH:verified_source(args.release,HELPER_PATH,HELPER_BLOB),PROTOCOL_PATH:verified_source(args.release,PROTOCOL_PATH,PROTOCOL_BLOB)}
        diag=load_read_module(diagnostic,'verified_forward_transition_read_guards');guards=diag.readonly_guards(publisher,base)
        private_directory(parent,uid);stage='ORIGINAL_RECEIPTS'
        original_paths=[parent/OLD_PIPELINE/'journal.jsonl',parent/OLD_BACKUP/'receipt.json',
                        parent/OLD_BACKUP/'recovery-before-images.enc',parent/OLD_BACKUP/'recovery-intent.json']
        original_signatures={p:signature(p.lstat())for p in original_paths}
        original_directories={parent:private_directory(parent,uid)[:5],
                              parent/OLD_BACKUP:private_directory(parent/OLD_BACKUP,uid)[:5],
                              parent/OLD_PIPELINE:private_directory(parent/OLD_PIPELINE,0)[:5]}
        def original_read(path,owner,limit,read=True):
            if signature(path.lstat())!=original_signatures[path]:raise ValueError('ORIGINAL_RACE')
            data,_=diag.private_file(path,owner,limit,read=read,ancestry_uid=uid)
            if signature(path.lstat())!=original_signatures[path]:raise ValueError('ORIGINAL_RACE')
            return data
        pipeline_raw=original_read(original_paths[0],0,1048576)
        prior,quiet=original_pipeline_state(diag,[json.loads(line)for line in pipeline_raw.splitlines()if line],parent)
        require_absent(parent/OLD_BACKUP/'recovery-complete.json')
        receipt_raw=original_read(original_paths[1],uid,1048576);receipt=json.loads(receipt_raw)
        original_read(original_paths[2],uid,33554432,read=False)
        original_read(original_paths[3],uid,1048576,read=False)
        pins={p:v[1]for p,v in guards['SOURCE_PINS'].items()}
        if receipt.get('targets')!=pins:raise ValueError()
        stage='CHECKPOINTS';summary,checkpoint=checkpoint_summary(diag,parent,uid,prior,quiet)
        stage='CONFIGURATION';config=guards['configuration_snapshot'](ROOT,uid)
        if guards['configuration_fingerprint'](config)!=prior['configuration_fingerprint']:raise ValueError()
        stage='LIVE_SOURCES';observed={};source_snapshots={}
        targets={**pins,**guards['PROTECTED_PINS'],ACCESS_PATH:ACCESS_BLOB}
        for p,target in targets.items():
            snapshot=guards['guarded_snapshot'](ROOT/p,uid,ROOT);source_snapshots[p]=snapshot
            expected=BASELINE_HELPER_BLOB if p==HELPER_PATH else BASELINE_PROTOCOL_BLOB if p==PROTOCOL_PATH else target
            if blob(snapshot[0].content)!=expected:raise ValueError()
            if p in pins:observed[p]=expected
        stage='READ_ONLY_PHP';program=diagnostic_php(guards['RECOVERY_PHP'],factory)
        with tempfile.TemporaryDirectory(prefix='.wa-forward-transition-',dir=parent)as folder:
            candidate=pathlib.Path(folder);os.chown(candidate,uid,gid);candidate.chmod(0o700)
            for path,data in pair.items():write_candidate(candidate/pathlib.PurePosixPath(path).name,data,uid,gid)
            args_php=['/sbin/runuser','-u','fasakha','--',PHP,'-r',program,'preflight',json.dumps(observed),json.dumps(IDS),
                      str(ROOT)+'/../whatsapp-release-backups/'+FORWARD_BACKUP,quiet['recovery_scope'],
                      str(ROOT)+'/../whatsapp-release-backups/'+OLD_BACKUP,str(candidate)]
            result=subprocess.run(args_php,cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=60)
        if len(result.stdout)>MAX_METADATA_OUTPUT:raise ValueError()
        value=json.loads(result.stdout)
        if any(value.get(k)!=0 for k in ('writes','worker_actions','ai_calls','orders_created')):raise ValueError()
        if result.returncode or value.get('status')!='FORWARD_TRANSITION_DIAGNOSTIC_READY':
            allowed={'ARGUMENTS','CODE','CANDIDATE_CODE','BOOTSTRAP','ORIGINAL_BACKUP','ORIGINAL_SCOPE','STATUS_7215','BEFOREIMAGE_BUDGET','PREDICATES'}
            if value.get('stage')in allowed:stage='PHP_'+value['stage']
            raise ValueError()
        stage='FINAL_CAS'
        if guards['configuration_snapshot'](ROOT,uid)!=config:raise ValueError()
        if any(guards['guarded_snapshot'](ROOT/p,uid,ROOT)!=s for p,s in source_snapshots.items()):raise ValueError()
        if original_read(original_paths[0],0,1048576)!=pipeline_raw:raise ValueError()
        if original_read(original_paths[1],uid,1048576)!=receipt_raw:raise ValueError()
        if any(signature(p.lstat())!=s for p,s in original_signatures.items()):raise ValueError()
        if any(private_directory(p,s[2])[:5]!=s for p,s in original_directories.items()):raise ValueError()
        require_absent(parent/OLD_BACKUP/'recovery-complete.json')
        _,after=checkpoint_summary(diag,parent,uid,prior,quiet)
        if after!=checkpoint:raise ValueError()
        print(json.dumps({'diagnostic':'READ_ONLY_FORWARD_TRANSITION','checkpoints':summary,'configuration_matches_original':True,
                          'configuration_stable':True,'candidate_predicates':value,'writes':0,'worker_actions':0,'ai_calls':0,'orders_created':0},sort_keys=True))
    except BaseException:
        print('READ_ONLY_FORWARD_TRANSITION_DIAGNOSTIC_STOPPED; STAGE='+stage);raise SystemExit(1)


if __name__=='__main__':main()
