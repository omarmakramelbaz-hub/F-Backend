#!/usr/bin/env python3
"""Derive the controlled forward metadata recovery from the frozen read closure.

The root coordinator owns publication, dual locks, quiescence and worker resume.
This factory never expands the recovery to a global quarantine retry.
"""
import hashlib

ORIGINAL_RECOVERY_SHA256 = '66aa6a308ab20d4dd8f0d92aa78d0117934189eaddf9ddc03d5a657515e9b24d'
CANDIDATE_HELPER_BLOB = '89b98259217e02d300bc7aa5c70c7b58eb0dfbff'
CANDIDATE_PROTOCOL_BLOB = 'c64f8cf89437bb7822fc767202f7cf5112bfd7fd'
BASELINE_PROTOCOL_BLOB = 'ecde67c6a87ca8068bb077892fdbea522d89f217'


def recovery_php(original_embedded_recovery):
    if (not isinstance(original_embedded_recovery, str)
            or hashlib.sha256(original_embedded_recovery.encode()).hexdigest() != ORIGINAL_RECOVERY_SHA256):
        raise ValueError('SEMANTIC_FORWARD_ORIGINAL_PROGRAM_MISMATCH')
    start = ' $gather=static function(bool$lock)'
    end = " if($phase==='preflight'){"
    if original_embedded_recovery.count(start) != 1 or original_embedded_recovery.count(end) != 1:
        raise ValueError('SEMANTIC_FORWARD_ORIGINAL_GATHER_MISMATCH')
    gather = original_embedded_recovery.split(start, 1)[1].split(end, 1)[0]
    program = HEADER_PHP + start + gather + BODY_PHP
    return (program.replace('__CANDIDATE_HELPER_BLOB__', CANDIDATE_HELPER_BLOB)
            .replace('__CANDIDATE_PROTOCOL_BLOB__', CANDIDATE_PROTOCOL_BLOB)
            .replace('__BASELINE_PROTOCOL_BLOB__', BASELINE_PROTOCOL_BLOB))


HEADER_PHP = r'''
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$committed=false;$stage='ARGUMENTS';$eventId=null;
try{
 $phase=$argv[1]??'';$pins=json_decode($argv[2]??'',true,16,JSON_THROW_ON_ERROR);
 $ids=json_decode($argv[3]??'',true,4,JSON_THROW_ON_ERROR);$backupArg=$argv[4]??'';
 $expected=$argv[5]??'';$originalArg=$argv[6]??'';$candidate=$argv[7]??'';
 $paths=['app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php','app/Support/WhatsAppOrderExtraction.php',
  'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php','app/Services/Dashboard/WhatsAppInboxMessage.php',
  'app/Services/Dashboard/WhatsAppOrderAiProvider.php','app/Services/Dashboard/WhatsAppInboxConsumer.php',
  'app/Services/Dashboard/WhatsAppReplyService.php','app/Http/Controllers/Dashboard/WhatsAppInboxController.php',
  'app/Services/Dashboard/WhatsAppOrderWorkflow.php'];
 if(!in_array($phase,['preflight','recover'],true)||!is_array($pins)||count($pins)!==9
  ||array_diff(array_keys($pins),$paths)||array_diff($paths,array_keys($pins))
  ||$ids!==[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7338]
  ||!is_string($expected)||!preg_match('/\A[a-f0-9]{64}\z/',$expected)
  ||!is_string($candidate)||($candidate!==''&&$phase!=='preflight'))throw new \RuntimeException();
 $signature=static fn(array$s)=>[$s['dev'],$s['ino'],$s['uid'],$s['gid'],$s['mode'],$s['nlink'],$s['size'],$s['mtime'],$s['ctime']];
 $read=static function(string$path,int$limit,bool$private=false)use($signature){
  clearstatcache(true,$path);$before=lstat($path);
  if(!$before||($before['mode']&0170000)!==0100000||realpath($path)!==$path
   ||$before['uid']!==posix_geteuid()||($before['mode']&0022)
   ||$before['size']<1||$before['size']>$limit
   ||($private&&(($before['mode']&0777)!==0600||$before['nlink']!==1)))throw new \RuntimeException();
  $stream=fopen($path,'rb');if(!$stream)throw new \RuntimeException();
  try{$opened=fstat($stream);if(!$opened||$signature($opened)!==$signature($before))throw new \RuntimeException();
   $bytes=stream_get_contents($stream,$limit+1);if(!is_string($bytes)||strlen($bytes)>$limit)throw new \RuntimeException();
   clearstatcache(true,$path);$after=lstat($path);$end=fstat($stream);
   if(!$after||!$end||$signature($after)!==$signature($before)||$signature($end)!==$signature($before))throw new \RuntimeException();
   return ['bytes'=>$bytes,'signature'=>$signature($before)];
  }finally{fclose($stream);}
 };
 $directory=static function(string$path):array{
  clearstatcache(true,$path);$s=lstat($path);
  if(!$s||($s['mode']&0170000)!==0040000||($s['mode']&0777)!==0700
   ||$s['uid']!==posix_geteuid()||realpath($path)!==$path)throw new \RuntimeException();
  return [$s['dev'],$s['ino'],$s['uid'],$s['gid'],$s['mode']];
 };
 $stage='CODE';$code=[];
 foreach($pins as$relative=>$hash){
  if(!is_string($hash)||!preg_match('/\A[a-f0-9]{40}\z/',$hash))throw new \RuntimeException();
  $path=getcwd().'/'.$relative;$entry=$read($path,8388608);
  if(sha1('blob '.strlen($entry['bytes'])."\0".$entry['bytes'])!==$hash)throw new \RuntimeException();
  $code[$path]=$entry;
 }
 $proofClasses=[
  'App\\Services\\Dashboard\\PosServiceTicket'=>['app/Services/Dashboard/PosServiceTicket.php','ef4c7b6f4a1276c122634aafabb2d510926aaeef'],
  'App\\Services\\Dashboard\\WhatsAppInboxAccess'=>['app/Services/Dashboard/WhatsAppInboxAccess.php','186789d42113e81818de8d82a2327e3ee9d8d0b5']];
 foreach($proofClasses as$class=>[$relative,$hash]){
  $path=getcwd().'/'.$relative;$entry=$read($path,1048576);
  if(sha1('blob '.strlen($entry['bytes'])."\0".$entry['bytes'])!==$hash)throw new \RuntimeException();$code[$path]=$entry;
 }
 $helper=getcwd().'/app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php';
 $protocol=getcwd().'/app/Support/WhatsAppInboxProtocol.php';
 $protocolEntry=$read($protocol,1048576);$protocolHash=sha1('blob '.strlen($protocolEntry['bytes'])."\0".$protocolEntry['bytes']);
 $code[$protocol]=$protocolEntry;
 if($candidate!==''){
  $stage='CANDIDATE_CODE';$candidateArg=$candidate;$candidate=realpath($candidate);
  if(!$candidate||$candidate!==$candidateArg)throw new \RuntimeException();$candidateIdentity=$directory($candidate);
  $helper=$candidate.'/WhatsAppNativePlaceholderHydration.php';$protocol=$candidate.'/WhatsAppInboxProtocol.php';
  foreach([$helper=>'__CANDIDATE_HELPER_BLOB__',$protocol=>'__CANDIDATE_PROTOCOL_BLOB__']as$path=>$hash){
   $entry=$read($path,1048576,true);
   if(sha1('blob '.strlen($entry['bytes'])."\0".$entry['bytes'])!==$hash)throw new \RuntimeException();
   $code[$path]=$entry;
  }
  if(!in_array($protocolHash,['__BASELINE_PROTOCOL_BLOB__','__CANDIDATE_PROTOCOL_BLOB__'],true))throw new \RuntimeException();
 }else{
  if($pins['app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php']!=='__CANDIDATE_HELPER_BLOB__'
   ||$protocolHash!=='__CANDIDATE_PROTOCOL_BLOB__')throw new \RuntimeException();
 }
 // Execute only the verified immutable snapshot, never reopen a mutable
 // candidate between the hash check and class definition. Both known source
 // files contain declarations only; their runtime methods still use real SQL.
 $memoryClasses=['App\\Services\\Dashboard\\WhatsAppNativePlaceholderHydration'=>$helper,
  'App\\Support\\WhatsAppInboxProtocol'=>$protocol];$memoryFiles=[];
 foreach($memoryClasses as$class=>$path){
  if(class_exists($class,false)||!str_starts_with($code[$path]['bytes'],'<?php'))throw new \RuntimeException();
  eval(substr($code[$path]['bytes'],5));$file=(new \ReflectionClass($class))->getFileName();
  if(!is_string($file)||!str_contains($file,"eval()'d code"))throw new \RuntimeException();$memoryFiles[$class]=$file;
 }
 $stage='BOOTSTRAP';require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 foreach($pins as$relative=>$hash){
  $class='App\\'.str_replace('/','\\',substr($relative,4,-4));
  $wanted=$relative==='app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'?$memoryFiles[$class]:getcwd().'/'.$relative;
  if((new \ReflectionClass($class))->getFileName()!==$wanted)throw new \RuntimeException();
 }
 if((new \ReflectionClass('App\\Support\\WhatsAppInboxProtocol'))->getFileName()!==$memoryFiles['App\\Support\\WhatsAppInboxProtocol'])throw new \RuntimeException();
 foreach($proofClasses as$class=>[$relative,$hash])
  if((new \ReflectionClass($class))->getFileName()!==getcwd().'/'.$relative)throw new \RuntimeException();
 foreach($code as$path=>$before)if($read($path,8388608,($candidate!==''&&str_starts_with($path,$candidate.'/')))!==$before)throw new \RuntimeException();
 if($candidate!==''&&$directory($candidate)!==$candidateIdentity)throw new \RuntimeException();
 $db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
'''


BODY_PHP = r'''
 $stage='ORIGINAL_BACKUP';
 $parent=realpath(getcwd().'/../whatsapp-release-backups');if(!$parent)throw new \RuntimeException();$parentIdentity=$directory($parent);
 $resolveBackup=static function(string$arg)use($parent,$directory):array{
  if(!str_starts_with($arg,getcwd().'/../whatsapp-release-backups/'))throw new \RuntimeException();
  clearstatcache(true,$arg);$entry=lstat($arg);
  if(!$entry||($entry['mode']&0170000)!==0040000)throw new \RuntimeException();
  $path=realpath($arg);
  if(!$path||dirname($path)!==$parent||!preg_match('/\Aorder-semantic-[A-Za-z0-9_-]{1,80}\z/',basename($path)))throw new \RuntimeException();
  return [$path,$directory($path)];
 };
 [$original,$originalIdentity]=$resolveBackup($originalArg);[$backup,$backupIdentity]=$resolveBackup($backupArg);
 if($original===$backup)throw new \RuntimeException();
 $beforeFile=$read($original.'/recovery-before-images.enc',33554432,true);
 $intentFile=$read($original.'/recovery-intent.json',1048576,true);
 $originalPlain=$crypt::decryptString($beforeFile['bytes']);
 if(strlen($originalPlain)>16777216||!hash_equals($expected,hash('sha256',$originalPlain)))throw new \RuntimeException();
 $old=json_decode($originalPlain,true,512,JSON_THROW_ON_ERROR);$intent=json_decode($intentFile['bytes'],true,16,JSON_THROW_ON_ERROR);
 if(!is_array($old)||array_keys($old)!==['events','failures','messages','threads','scans','drafts','candidates']
  ||!is_array($intent)||array_diff(array_keys($intent),['event_ids','pending','scope_hash'])||count($intent)!==3
  ||$intent['event_ids']!==$ids||$intent['pending']!==13||$intent['scope_hash']!==$expected
  ||count($old['events'])!==13||count($old['failures'])!==13||count($old['candidates'])!==13)throw new \RuntimeException();
 $immutableOriginal=static function()use($directory,$read,$parent,$parentIdentity,$original,$originalIdentity,$beforeFile,$intentFile){
  if($directory($parent)!==$parentIdentity||$directory($original)!==$originalIdentity
   ||$read($original.'/recovery-before-images.enc',33554432,true)!==$beforeFile
   ||$read($original.'/recovery-intent.json',1048576,true)!==$intentFile)throw new \RuntimeException();
 };
 $sameExcept=static function(array$a,array$b,array$allowed):bool{
  if(array_keys($a)!==array_keys($b))return false;
  foreach($a as$k=>$v)if(!in_array($k,$allowed,true)&&$b[$k]!==$v)return false;return true;
 };
 $played=static function(bool$lock)use($db,$crypt){
  $columns=\Illuminate\Support\Facades\Schema::getColumnListing('whatsapp_webhook_events');$select=[];
  foreach($columns as$column)$select[]=$column==='payload'?$db::raw('CASE WHEN LENGTH(payload) <= 8388608 THEN payload ELSE NULL END AS payload'):$column;
  $q=$db::table('whatsapp_webhook_events')->where('id',7215)->select($select);$row=($lock?$q->lockForUpdate():$q)->first();
  if(!$row||!is_string($row->payload))throw new \RuntimeException();
  $plain=$crypt::decryptString($row->payload);
  if(strlen($plain)>4194304||!is_string($row->payload_hash)||!hash_equals($row->payload_hash,hash('sha256',$plain)))throw new \RuntimeException();
  $raw=json_decode($plain,true,512,JSON_THROW_ON_ERROR);
  $report=\App\Support\WhatsAppInboxProtocol::report($raw,'468336579702269','515388018324075');
  if(($report['quarantined_count']??-1)!==0||($report['ignored_count']??-1)!==0
   ||($report['status_count']??-1)!==1||($report['messages']??null)!==[]
   ||($raw['object']??null)!=='whatsapp_business_account'||!is_array($raw['entry']??null)||count($raw['entry'])!==1
   ||($raw['entry'][0]['id']??null)!=='468336579702269'||!is_array($raw['entry'][0]['changes']??null)||count($raw['entry'][0]['changes'])!==1
   ||($raw['entry'][0]['changes'][0]['field']??null)!=='messages')throw new \RuntimeException();
  $value=$raw['entry'][0]['changes'][0]['value']??null;
  if(!is_array($value)||($value['metadata']['phone_number_id']??null)!=='515388018324075'
   ||array_key_exists('messages',$value)||array_key_exists('message_echoes',$value)
   ||!is_array($value['statuses']??null)||count($value['statuses'])!==1||($value['statuses'][0]['status']??null)!=='played')throw new \RuntimeException();
  $q=$db::table('whatsapp_inbox_ingestion_failures')->where('event_id',7215);$failure=($lock?$q->lockForUpdate():$q)->first();
  if($row->processed_at===null){if(!$failure||$failure->reason!=='INVALID_EVENT')throw new \RuntimeException();}
  else{
   $date=is_string($row->processed_at)?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$row->processed_at,new \DateTimeZone('UTC')):false;
   if($failure||!$date||$date->format('Y-m-d H:i:s')!==$row->processed_at
    ||$row->processed_at>now('UTC')->addSeconds(5)->format('Y-m-d H:i:s'))throw new \RuntimeException();
  }
  return ['event'=>(array)$row,'failure'=>$failure?(array)$failure:null,'pending'=>$row->processed_at===null];
 };
 $transitions=static function(array$value)use($db,$crypt,&$eventId){
  foreach($value['bundle']['scans']as$scan)
   if(!empty($scan['claim_nonce'])&&is_string($scan['lease_until'])&&$scan['lease_until']>=now('UTC')->format('Y-m-d H:i:s'))throw new \RuntimeException();
  foreach($value['bundle']['candidates']as$c){$eventId=$c['event_id'];
   if(!$c['pending'])throw new \RuntimeException();
   $message=$value['bundle']['messages'][(string)$c['message_id']]??null;
   $thread=$value['bundle']['threads'][(string)$c['conversation_id']]??null;
   $event=null;foreach($value['bundle']['events']as$e)if((int)$e['id']===$c['event_id'])$event=$e;
   if(!is_array($message)||!is_array($thread)||!is_array($event))throw new \RuntimeException();
   $before=json_decode($crypt::decryptString($message['content']),true,512,JSON_THROW_ON_ERROR);
   $raw=json_decode($crypt::decryptString($event['payload']),true,512,JSON_THROW_ON_ERROR);
   $report=\App\Support\WhatsAppInboxProtocol::report($raw,'468336579702269','515388018324075');
   if(count($report['messages']??[])!==1||!\App\Services\Dashboard\WhatsAppNativePlaceholderHydration::transition((object)$thread,(object)$message,$before,$report['messages'][0]))throw new \RuntimeException();
  }$eventId=null;
 };
 $verifyAfter=static function(array$current,array$originalBundle)use($crypt,$sameExcept,$ids){
  if($current['pending']!==0)throw new \RuntimeException();$bundle=$current['bundle'];
  foreach(['events','messages','threads','scans','drafts','candidates']as$name)
   if(array_keys($bundle[$name])!==array_keys($originalBundle[$name]))throw new \RuntimeException();
  foreach($originalBundle['events']as$i=>$oldEvent)
   if((int)$oldEvent['id']!==$ids[$i]||$oldEvent['processed_at']!==null||!$sameExcept($oldEvent,$bundle['events'][$i],['processed_at'])
    ||!is_string($bundle['events'][$i]['processed_at'])||$bundle['failures'][$i]!==null)throw new \RuntimeException();
  foreach($originalBundle['messages']as$id=>$oldMessage){$row=$bundle['messages'][$id];
   if(!$sameExcept($oldMessage,$row,['type','content','sent_at','updated_at'])||$oldMessage['type']!=='unsupported'||$row['type']!=='text')throw new \RuntimeException();
   $dto=json_decode($crypt::decryptString($row['content']),true,512,JSON_THROW_ON_ERROR);$marker=$dto['content']['hydration']??null;
   if(!is_array($marker)||($marker['before_ciphertext']??null)!==$oldMessage['content']
    ||($marker['before_ciphertext_sha256']??null)!==hash('sha256',$oldMessage['content']))throw new \RuntimeException();
  }
  foreach($originalBundle['threads']as$id=>$oldThread)
   if(!$sameExcept($oldThread,$bundle['threads'][$id],['last_message_at','updated_at']))throw new \RuntimeException();
  foreach($originalBundle['scans']as$i=>$oldScan){$new=$bundle['scans'][$i];
   if(!$sameExcept($oldScan,$new,['analyzed_ceiling','claimed_ceiling','attempts','next_attempt_at','updated_at']))throw new \RuntimeException();
   $floor=0;foreach($originalBundle['drafts']as$draft)
    if($draft['conversation_id']==$oldScan['conversation_id']&&$draft['status']==='DISPATCHED')$floor=max($floor,(int)$draft['evidence_ceiling']);
   if(config('whatsapp_orders.mode')==='auto')$floor=max($floor,(int)config('whatsapp_orders.activation_message_id'),(int)($oldScan['auto_floor']??0));
   $revised=false;foreach($originalBundle['candidates']as$c)
    if($c['conversation_id']==$oldScan['conversation_id']&&$c['message_id']>$floor)$revised=true;
   if(!$revised){if($new!==$oldScan)throw new \RuntimeException();continue;}
   if((int)$new['analyzed_ceiling']!==0||(int)$new['claimed_ceiling']!==0||(int)$new['attempts']!==0)throw new \RuntimeException();
   $updated=is_string($new['updated_at'])?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$new['updated_at'],new \DateTimeZone('UTC')):false;
   if(!$updated||$updated->format('Y-m-d H:i:s')!==$new['updated_at']||$new['updated_at']>now('UTC')->addSeconds(5)->format('Y-m-d H:i:s'))throw new \RuntimeException();
   $debounce=config('whatsapp_cart.debounce_seconds',10);
   if(!is_int($debounce)&&!(is_string($debounce)&&preg_match('/\A[0-9]{1,3}\z/',$debounce)))$debounce=10;
   $debounce=max(0,min(120,(int)$debounce));
   if($debounce===0){if($new['next_attempt_at']!==null)throw new \RuntimeException();}
   else{$next=is_string($new['next_attempt_at'])?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$new['next_attempt_at'],new \DateTimeZone('UTC')):false;
    if(!$next||$next->format('Y-m-d H:i:s')!==$new['next_attempt_at']||abs($next->getTimestamp()-$updated->getTimestamp()-$debounce)>1)throw new \RuntimeException();}
  }
  if($bundle['drafts']!==$originalBundle['drafts'])throw new \RuntimeException();
  foreach($originalBundle['candidates']as$i=>$oldCandidate)
   if(!$sameExcept($oldCandidate,$bundle['candidates'][$i],['pending'])||!$oldCandidate['pending']||$bundle['candidates'][$i]['pending'])throw new \RuntimeException();
 };
 $beforeImage=static function(array$bundle,array$status):string{
  $plain=json_encode(['original_bundle'=>$bundle,'status_event'=>$status['event'],
   'status_failure'=>$status['failure']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  if(strlen($plain)>16777216)throw new \RuntimeException();return $plain;
 };
 $write=static function(string$name,string$bytes)use($backup,$backupIdentity,$directory,$read){
  if($directory($backup)!==$backupIdentity)throw new \RuntimeException();umask(0077);
  $path=$backup.'/'.$name;$stream=fopen($path,'xb');if(!$stream)throw new \RuntimeException();
  try{$opened=fstat($stream);if(!$opened||($opened['mode']&0170000)!==0100000||($opened['mode']&0777)!==0600
    ||$opened['uid']!==posix_geteuid()||$opened['nlink']!==1)throw new \RuntimeException();
   $offset=0;while($offset<strlen($bytes)){$n=fwrite($stream,substr($bytes,$offset));if(!$n)throw new \RuntimeException();$offset+=$n;}
   if(!fflush($stream)||(function_exists('fsync')&&!fsync($stream)))throw new \RuntimeException();
   clearstatcache(true,$path);$entry=lstat($path);
   if(!$entry||$entry['dev']!==$opened['dev']||$entry['ino']!==$opened['ino'])throw new \RuntimeException();
  }finally{fclose($stream);}
  if($directory($backup)!==$backupIdentity||$read($path,33554432,true)['bytes']!==$bytes)throw new \RuntimeException();
 };
 $journalProfile=static function()use($backup,$backupIdentity,$directory):array{
  if($directory($backup)!==$backupIdentity)throw new \RuntimeException();
  $entries=scandir($backup);if(!is_array($entries))throw new \RuntimeException();
  $present=['forward-before-images.enc'=>false,'forward-intent.json'=>false,'forward-complete.json'=>false];
  foreach($entries as$name){
   if(in_array($name,['.','..'],true))continue;
   if($name==='publications'){$directory($backup.'/publications');continue;}
   if(!array_key_exists($name,$present))throw new \RuntimeException();$present[$name]=true;
  }
  if($present['forward-before-images.enc']!==$present['forward-intent.json'])throw new \RuntimeException();
  return $present;
 };
 // An outer SQL rollback leaves its encrypted intent durable. A same-checkpoint
 // retry may reuse that exact pair, but can never overwrite or silently replace
 // the failed attempt's evidence with a newly approved data state.
 $pendingJournal=static function(array$bundle,array$status)use($journalProfile,$read,$backup,$crypt,$ids,$expected,$beforeImage){
  $profile=$journalProfile();if($profile['forward-complete.json'])throw new \RuntimeException();
  if(!$profile['forward-before-images.enc'])return null;
  $before=$read($backup.'/forward-before-images.enc',33554432,true);
  $intent=$read($backup.'/forward-intent.json',1048576,true);
  $plain=$crypt::decryptString($before['bytes']);
  if(strlen($plain)>16777216||$plain!==$beforeImage($bundle,$status))throw new \RuntimeException();
  $value=json_decode($intent['bytes'],true,16,JSON_THROW_ON_ERROR);$hash=hash('sha256',$before['bytes']);
  if($value!==['event_ids'=>$ids,'status_event_id'=>7215,'scope_hash'=>$expected,'before_images_sha256'=>$hash])throw new \RuntimeException();
  return ['before'=>$before,'intent'=>$intent,'cipher_hash'=>$hash];
 };
 $stage='ORIGINAL_SCOPE';$initial=$gather(false);$stage='STATUS_7215';$eventId=7215;$statusInitial=$played(false);$eventId=null;
 if($initial['pending']===0&&!$statusInitial['pending']){
  $stage='RESUME';
  $journalProfile();
  $forwardBeforeFile=$read($backup.'/forward-before-images.enc',33554432,true);
  $forwardIntentFile=$read($backup.'/forward-intent.json',1048576,true);
  clearstatcache(true,$backup.'/forward-complete.json');$completeEntry=lstat($backup.'/forward-complete.json');
  $forwardCompleteFile=$completeEntry!==false?$read($backup.'/forward-complete.json',1048576,true):null;
  $forwardPlain=$crypt::decryptString($forwardBeforeFile['bytes']);if(strlen($forwardPlain)>16777216)throw new \RuntimeException();
  $forward=json_decode($forwardPlain,true,512,JSON_THROW_ON_ERROR);$forwardIntent=json_decode($forwardIntentFile['bytes'],true,16,JSON_THROW_ON_ERROR);
  $complete=$forwardCompleteFile===null?null:json_decode($forwardCompleteFile['bytes'],true,16,JSON_THROW_ON_ERROR);
  if(!is_array($forward)||array_keys($forward)!==['original_bundle','status_event','status_failure']||$forward['original_bundle']!==$old
   ||!is_array($forwardIntent)||array_keys($forwardIntent)!==['event_ids','status_event_id','scope_hash','before_images_sha256']
   ||$forwardIntent['event_ids']!==$ids||$forwardIntent['status_event_id']!==7215||$forwardIntent['scope_hash']!==$expected
   ||$forwardIntent['before_images_sha256']!==hash('sha256',$forwardBeforeFile['bytes'])
   ||!array_key_exists('processed_at',$forward['status_event'])||$forward['status_event']['processed_at']!==null
   ||($forward['status_failure']['reason']??null)!=='INVALID_EVENT'
   ||!$sameExcept($forward['status_event'],$statusInitial['event'],['processed_at']))throw new \RuntimeException();
  $wanted=['events_seen'=>14,'events_processed'=>14,'events_skipped'=>0,'messages_inserted'=>0,'messages_replayed'=>0,
   'messages_hydrated'=>13,'quarantined_events'=>0,'ignored_records'=>0,'status_records'=>1,'errors'=>0];
  $verifyAfter($initial,$old);$immutableOriginal();
  $verifiedComplete=['status'=>'SEMANTIC_FORWARD_RECOVERY_READY','event_ids'=>$ids,'status_event_id'=>7215,'scope_hash'=>$expected,
   'before_images_sha256'=>$forwardIntent['before_images_sha256'],'status_payload_hash'=>$forward['status_event']['payload_hash'],
   'after_scope_hash'=>$initial['scope_hash'],'metrics'=>$wanted];
  if($complete!==null&&$complete!==$verifiedComplete)throw new \RuntimeException();
  if($complete===null&&$phase==='recover'){
   $write('forward-complete.json',json_encode($verifiedComplete,JSON_THROW_ON_ERROR));$immutableOriginal();$complete=$verifiedComplete;
  }
  $out=['status'=>'SEMANTIC_FORWARD_RECOVERY_ALREADY_COMPLETE','committed'=>true,'pending'=>0,'total'=>13,
   'processed_total'=>14,'status_event_id'=>7215,'status_records'=>1,'metrics'=>$wanted,'completion_present'=>$complete!==null,
   'original_scope_valid'=>true,'transitions_verified'=>13,'status_7215_verified'=>true,
   'ai_calls'=>0,'orders_created'=>0,'scope_hash'=>$expected];
 }else{
  if($initial['pending']!==13||!$statusInitial['pending']||$initial['json']!==$originalPlain
   ||!hash_equals($expected,$initial['scope_hash']))throw new \RuntimeException();
  $stage='TRANSITIONS';$transitions($initial);$immutableOriginal();
  $stage='BEFOREIMAGE_BUDGET';$beforeImage($initial['bundle'],$statusInitial);
  $stage='JOURNAL';$initialJournal=$pendingJournal($initial['bundle'],$statusInitial);
  if($phase==='preflight'){
   $out=['status'=>'SEMANTIC_FORWARD_PREFLIGHT_READY','committed'=>false,'pending'=>13,'total'=>13,
    'status_event_id'=>7215,'status_records'=>1,'original_scope_valid'=>true,'transitions_verified'=>13,
    'status_7215_verified'=>true,'ai_calls'=>0,'orders_created'=>0,'scope_hash'=>$expected];
  }else{
   $stage='TRANSACTION';$consumer=app(\App\Services\Dashboard\WhatsAppInboxConsumer::class);
   $forwardComplete=$db::transaction(static function()use($gather,$played,$expected,$originalPlain,$old,$statusInitial,$transitions,$beforeImage,$initialJournal,$pendingJournal,
    $write,$immutableOriginal,$crypt,$db,$consumer,$ids,$sameExcept,$verifyAfter,&$stage,&$eventId){
    $stage='ORIGINAL_SCOPE';$before=$gather(true);$stage='STATUS_7215';$eventId=7215;$statusBefore=$played(true);$eventId=null;
    if($before['pending']!==13||$before['json']!==$originalPlain||!hash_equals($expected,$before['scope_hash'])
     ||!$statusBefore['pending']||$statusBefore!==$statusInitial)throw new \RuntimeException();
    $stage='TRANSITIONS';$transitions($before);$immutableOriginal();
    $stage='BEFOREIMAGE_BUDGET';$plain=$beforeImage($before['bundle'],$statusBefore);
    $stage='JOURNAL';$journal=$pendingJournal($before['bundle'],$statusBefore);
    if($journal!==$initialJournal)throw new \RuntimeException();
    if($journal===null){
     $cipher=$crypt::encryptString($plain);$cipherHash=hash('sha256',$cipher);
     $write('forward-before-images.enc',$cipher);
     $write('forward-intent.json',json_encode(['event_ids'=>$ids,'status_event_id'=>7215,'scope_hash'=>$expected,
      'before_images_sha256'=>$cipherHash],JSON_THROW_ON_ERROR));
     $journal=$pendingJournal($before['bundle'],$statusBefore);if($journal===null)throw new \RuntimeException();
    }else{$cipherHash=$journal['cipher_hash'];}
    $stage='RECOVERY';$metrics=$consumer->recoverUnavailableMessages($ids);
    $wanted=['events_seen'=>13,'events_processed'=>13,'events_skipped'=>0,'messages_inserted'=>0,'messages_replayed'=>0,
     'messages_hydrated'=>13,'quarantined_events'=>0,'ignored_records'=>0,'status_records'=>0,'errors'=>0];
    if($metrics!==$wanted)throw new \RuntimeException();
    $stage='STATUS_7215';$eventId=7215;$statusCurrent=$played(true);
    if(!$statusCurrent['pending']||$statusCurrent!==$statusBefore)throw new \RuntimeException();
    $changed=$db::table('whatsapp_webhook_events')->where('id',7215)->whereNull('processed_at')
     ->where('payload_hash',$statusBefore['event']['payload_hash'])->where('payload',$statusBefore['event']['payload'])
     ->where('received_at',$statusBefore['event']['received_at'])->update(['processed_at'=>now('UTC')]);
    if($changed!==1)throw new \RuntimeException();
    $failure=$statusBefore['failure'];$deleted=$db::table('whatsapp_inbox_ingestion_failures')->where('event_id',7215)
     ->where('reason',$failure['reason'])->where('attempts',$failure['attempts'])->where('last_attempted_at',$failure['last_attempted_at'])->delete();
    if($deleted!==1)throw new \RuntimeException();$eventId=null;
    $stage='POSTCHECK';$after=$gather(true);$verifyAfter($after,$old);$statusAfter=$played(true);
    if($statusAfter['pending']||!$sameExcept($statusBefore['event'],$statusAfter['event'],['processed_at']))throw new \RuntimeException();
    $stage='JOURNAL';if($pendingJournal($before['bundle'],$statusBefore)!==$journal)throw new \RuntimeException();
    $immutableOriginal();
    $metrics['events_seen']++;$metrics['events_processed']++;$metrics['status_records']=1;
    return ['status'=>'SEMANTIC_FORWARD_RECOVERY_READY','event_ids'=>$ids,'status_event_id'=>7215,'scope_hash'=>$expected,
     'before_images_sha256'=>$cipherHash,'status_payload_hash'=>$statusBefore['event']['payload_hash'],
     'after_scope_hash'=>$after['scope_hash'],'metrics'=>$metrics];
   },1);$committed=true;$stage='COMPLETE';
   $write('forward-complete.json',json_encode($forwardComplete,JSON_THROW_ON_ERROR));$immutableOriginal();
   $out=['status'=>'SEMANTIC_FORWARD_RECOVERY_READY','committed'=>true,'pending'=>0,'total'=>13,
    'processed_total'=>14,'status_event_id'=>7215,'status_records'=>1,'metrics'=>$forwardComplete['metrics'],
    'original_scope_valid'=>true,'transitions_verified'=>13,'status_7215_verified'=>true,
    'ai_calls'=>0,'orders_created'=>0,'scope_hash'=>$expected];
  }
 }
 while(ob_get_level()>0)ob_end_clean();echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(\Throwable$e){while(ob_get_level()>0)ob_end_clean();echo json_encode([
 'status'=>'SEMANTIC_FORWARD_RECOVERY_NEEDS_REVIEW','committed'=>$committed,'stage'=>$stage,
 'event_id'=>$eventId,'ai_calls'=>0,'orders_created'=>0],JSON_THROW_ON_ERROR);exit(1);}
'''
