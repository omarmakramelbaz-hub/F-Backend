<?php
// Resolve only the three reviewed unavailable-notification retransmissions.
// The original inbox rows and raw payloads remain unchanged. No order work runs.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();umask(0077);
$out=['diagnostic'=>'WHATSAPP_UNAVAILABLE_REPLAY_RECOVERY','writes'=>0,'ai_calls'=>0,
    'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,'worker_actions'=>0,
    'scope'=>'THREE_PROVED_UNAVAILABLE_REPLAYS_NOT_ORDER_OR_CUSTOMER_AUTHORITY'];
$stage='ARGUMENTS';$called=false;$auditDirectory=null;
const WA_REPLAY_AUDIT_VERSION='whatsapp-unavailable-replay-audit-v1';
function waReplayArguments(array$args):array{
    $names=['--conversation'=>'conversation','--expected-floor'=>'floor','--expected-ceiling'=>'ceiling',
        '--expected-revision'=>'revision','--expected-event-floor'=>'event_floor','--events'=>'events'];$values=[];
    for($i=1;$i<count($args);$i++){
        $name=$names[$args[$i]]??null;
        if($name===null||isset($values[$name])||!isset($args[$i+1]))throw new RuntimeException('ARGUMENTS_INVALID');
        $value=$args[++$i];
        if($name==='events'){
            if($value!=='14983,16811,17608')throw new RuntimeException('ARGUMENTS_INVALID');$values[$name]=[14983,16811,17608];
        }else{
            if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value))throw new RuntimeException('ARGUMENTS_INVALID');$values[$name]=(int)$value;
        }
    }
    $expected=['conversation'=>11,'floor'=>6559,'ceiling'=>10046,'revision'=>13,'event_floor'=>13203,'events'=>[14983,16811,17608]];
    if(count($values)!==count($expected))throw new RuntimeException('ARGUMENTS_INVALID');
    foreach($expected as$key=>$value)if(($values[$key]??null)!==$value)throw new RuntimeException('ARGUMENTS_INVALID');return$expected;
}
function waReplayCall($object,string$name,array$args=[]){$m=new ReflectionMethod($object,$name);$m->setAccessible(true);return$m->invokeArgs($object,$args);}
function waReplayHash($value):string{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
function waReplayTime($value):bool{
    if(!is_string($value))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return$d&&$d->format('Y-m-d H:i:s')===$value&&substr($value,0,4)!=='0000';
}
function waReplaySources(array$pins,array$protocols,?array$expected=null):array{
    $observed=[];
    foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');
        $blob=sha1('blob '.strlen($bytes)."\0".$bytes);$allowed=$path==='app/Support/WhatsAppInboxProtocol.php'?$protocols:[$pin];
        if(!in_array($blob,$allowed,true)||$expected!==null&&($expected[$path]??null)!==$blob)throw new RuntimeException('SOURCE_CHANGED');$observed[$path]=$blob;
    }return$observed;
}
/** No plaintext customer data is returned or written to the audit. */
function waReplayContext($workflow,$control,array$options,bool$lock=false):array{
    $db=\Illuminate\Support\Facades\DB::class;$access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    $state=$control->state();$token=waReplayCall($workflow,'controlToken',[$options['conversation']]);
    if(($state['available']??null)!==true||($state['policy']['enabled']??null)!==true||($state['policy']['mode']??null)!=='ANALYZE_ONLY'
        ||($state['revision']??null)!==$options['revision']||!is_array($token)||($token['revision']??null)!==$options['revision']
        ||($token['event_floor']??null)!==$options['event_floor'])throw new RuntimeException('CONTROL_EXPECTATION_CHANGED');
    $query=$db::table('whatsapp_inbox_conversations')->where('id',$options['conversation'])->where('waba_id',$access::WABA_ID)->where('phone_number_id',$access::PHONE_ID);
    if($lock)$query->lockForUpdate();if(!$query->first(['id']))throw new RuntimeException('CONVERSATION_SCOPE_MISMATCH');
    $activation=waReplayCall($workflow,'activationBoundary',['activation_message_id',$options['conversation']]);
    $ceiling=waReplayCall($workflow,'ceiling',[$options['conversation'],$lock]);
    if(!is_int($activation)||$ceiling!==$options['ceiling'])throw new RuntimeException('NATIVE_EXPECTATION_CHANGED');
    $floor=waReplayCall($workflow,'customerReceiptFloor',[$options['conversation'],max($activation,(int)waReplayCall($workflow,'dispatchedCeiling',[$options['conversation'],$lock])),$ceiling]);
    if($floor!==$options['floor'])throw new RuntimeException('NATIVE_EXPECTATION_CHANGED');
    $snapshot=waReplayCall($workflow,'snapshot',[$options['conversation'],$floor,$ceiling,$lock]);
    if(($snapshot['complete']??null)!==true||!is_string($snapshot['hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$snapshot['hash']))throw new RuntimeException('SNAPSHOT_UNAVAILABLE');
    $proof=\App\Support\WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']??[]);
    if(($proof['ok']??null)!==true)throw new RuntimeException('CUSTOMER_PROOF_REQUIRED');
    return['revision'=>$state['revision'],'policy_fingerprint'=>waReplayHash($state['policy']),
        'control_updated_at'=>$state['updated_at_utc']??null,'authority_fingerprint'=>waReplayHash($token),
        'floor'=>$floor,'ceiling'=>$ceiling,'snapshot_hash'=>$snapshot['hash']];
}
function waReplayProof(int$id):array{
    $db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;$access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    $event=$db::table('whatsapp_webhook_events')->where('id',$id)->select('id','payload_hash','received_at','processed_at')
        ->selectRaw('CASE WHEN LENGTH(payload)<=8388608 THEN payload ELSE NULL END AS payload')->first();
    if(!$event||!is_string($event->payload)||strlen($event->payload)>8388608)throw new RuntimeException('RAW_PROOF_REQUIRED');
    $plain=$crypt::decryptString($event->payload);
    if(strlen($plain)>4194304||!is_string($event->payload_hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$event->payload_hash)
        ||!hash_equals($event->payload_hash,hash('sha256',$plain)))throw new RuntimeException('RAW_PROOF_REQUIRED');
    $payload=json_decode($plain,true,512,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new RuntimeException('RAW_PROOF_REQUIRED');
    $report=\App\Support\WhatsAppInboxProtocol::report($payload,$access::WABA_ID,$access::PHONE_ID);
    if(($report['quarantined_count']??null)!==0||($report['ignored_count']??null)!==0||($report['status_count']??null)!==0
        ||!is_array($report['messages']??null)||count($report['messages'])!==1)throw new RuntimeException('REPLAY_PROOF_REQUIRED');
    $message=$report['messages'][0];$key=hash('sha256','whatsapp-message-v1:'.$access::WABA_ID.':'.$access::PHONE_ID.':'.$message['message_id']);
    $existing=$db::table('whatsapp_inbox_messages')->where('message_key',$key)
        ->select('id','conversation_id','message_key','direction','source','type','sent_at','created_at','updated_at')
        ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
    if(!$existing)throw new RuntimeException('REPLAY_PROOF_REQUIRED');
    $thread=$db::table('whatsapp_inbox_conversations')->where('id',$existing->conversation_id)->first(['id','waba_id','phone_number_id','peer_hash']);
    if(!$thread)throw new RuntimeException('REPLAY_PROOF_REQUIRED');
    $proof=\App\Services\Dashboard\WhatsAppNativeUnavailableReplay::inspect($thread,$existing,$event);
    if(!is_array($proof))throw new RuntimeException('REPLAY_PROOF_REQUIRED');
    $failure=$db::table('whatsapp_inbox_ingestion_failures')->where('event_id',$id)->first(['reason','attempts','last_attempted_at']);
    if($event->processed_at===null){
        $attempts=$failure?filter_var($failure->attempts,FILTER_VALIDATE_INT):false;
        if(!$failure||$failure->reason!=='CONFLICTING_MESSAGE'||$attempts===false||$attempts<1||!waReplayTime($failure->last_attempted_at))throw new RuntimeException('FAILURE_EXPECTATION_CHANGED');
        $proof['failure']=['reason'=>$failure->reason,'attempts'=>$attempts,'last_attempted_at'=>$failure->last_attempted_at];
    }elseif(!waReplayTime($event->processed_at)||$failure!==null)throw new RuntimeException('FAILURE_EXPECTATION_CHANGED');
    return['proof'=>$proof,'processed'=>$event->processed_at!==null,'processed_at'=>$event->processed_at,
        'raw_ciphertext_sha256'=>hash('sha256',$event->payload)];
}
function waReplayDirectory(string$path,int$uid):array{
    clearstatcache(true,$path);$s=lstat($path);
    if(!is_array($s)||is_link($path)||realpath($path)!==$path||($s['mode']&0170000)!==0040000
        ||$s['uid']!==$uid||($s['mode']&0777)!==0700)throw new RuntimeException('AUDIT_DIRECTORY_UNSAFE');return$s;
}
function waReplayIdentity(array$s):array{return array_intersect_key($s,array_flip(['dev','ino','mode','uid','gid']));}
function waReplaySyncDirectory(string$path,int$uid,array$expected):void{
    $fd=fopen($path,'rb');if($fd===false)throw new RuntimeException('AUDIT_SYNC_FAILED');
    try{$opened=fstat($fd);
        if(!is_array($opened)||waReplayIdentity($opened)!==waReplayIdentity($expected)
            ||waReplayIdentity(waReplayDirectory($path,$uid))!==waReplayIdentity($opened)
            ||!fsync($fd))throw new RuntimeException('AUDIT_SYNC_FAILED');
    }finally{fclose($fd);}
}
function waReplayReadAudit(string$path,int$uid):array{
    clearstatcache(true,$path);$s=lstat($path);
    if(!is_array($s)||is_link($path)||($s['mode']&0170000)!==0100000||$s['uid']!==$uid||($s['mode']&0777)!==0600||$s['nlink']!==1||$s['size']>131072)throw new RuntimeException('AUDIT_FILE_UNSAFE');
    $fd=fopen($path,'rb');if($fd===false)throw new RuntimeException('AUDIT_READ_FAILED');
    try{$opened=fstat($fd);$bytes=stream_get_contents($fd,131073);$after=fstat($fd);
        if(!is_array($opened)||!is_array($after)||waReplayIdentity($s)!==waReplayIdentity($opened)||waReplayIdentity($opened)!==waReplayIdentity($after)
            ||!is_string($bytes)||strlen($bytes)>131072||strlen($bytes)!==$s['size'])throw new RuntimeException('AUDIT_READ_FAILED');
    }finally{fclose($fd);}
    $value=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);if(!is_array($value))throw new RuntimeException('AUDIT_READ_FAILED');return$value;
}
function waReplayWriteAudit(string$path,array$value,int$uid):void{
    $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
    if(strlen($bytes)>131072)throw new RuntimeException('AUDIT_SIZE_INVALID');
    $fd=fopen($path,'x+b');if($fd===false)throw new RuntimeException('AUDIT_WRITE_FAILED');
    try{
        if(!chmod($path,0600))throw new RuntimeException('AUDIT_WRITE_FAILED');$s=fstat($fd);
        if(!is_array($s)||($s['mode']&0170000)!==0100000||$s['uid']!==$uid||($s['mode']&0777)!==0600||$s['nlink']!==1)throw new RuntimeException('AUDIT_FILE_UNSAFE');
        $written=0;while($written<strlen($bytes)){$n=fwrite($fd,substr($bytes,$written));if(!is_int($n)||$n<1)throw new RuntimeException('AUDIT_WRITE_FAILED');$written+=$n;}
        if(!fflush($fd)||!fsync($fd))throw new RuntimeException('AUDIT_SYNC_FAILED');
        rewind($fd);$actual=stream_get_contents($fd,131073);clearstatcache(true,$path);$linked=lstat($path);
        if(!is_string($actual)||!hash_equals(hash('sha256',$bytes),hash('sha256',$actual))||!is_array($linked)
            ||waReplayIdentity($s)!==waReplayIdentity($linked)||$linked['nlink']!==1)throw new RuntimeException('AUDIT_WRITE_FAILED');
    }finally{fclose($fd);}
    if(waReplayReadAudit($path,$uid)!==$value)throw new RuntimeException('AUDIT_WRITE_FAILED');
}
try{
    $options=waReplayArguments($argv);$stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException('OWNER_INVALID');$uid=$owner['uid'];
    $stage='SOURCE_GUARD';$pins=[
        'app/Support/WhatsAppCustomerOrderConfirmation.php'=>'63cd0fcd2d9056fb0e5732ddc22f4423ee349da7',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'63c1e6e7e48c500227e0285707611b268e50b3ad',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
        'app/Services/Dashboard/WhatsAppNativeUnavailableReplay.php'=>'bc6febf49c85947684a38c16d00bd5b62fb56e60',
        'app/Services/Dashboard/WhatsAppInboxConsumer.php'=>'b169e92362531c1da565ef26bbc999a5a0fa73d8',
    ];$protocols=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];$sources=waReplaySources($pins,$protocols);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();$stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $pdo=$connection->getPdo();$pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);$control=$app->make(\App\Services\Dashboard\MetaAiControl::class);
    $stage='CONTEXT';$context=waReplayContext($workflow,$control,$options);
    $stage='REPLAY_PROOFS';$current=[];foreach($options['events']as$id)$current[$id]=waReplayProof($id);
    $stage='AUDIT';$parent='/home/fasakha/whatsapp-release-backups';$parentBefore=waReplayDirectory($parent,$uid);
    $auditDirectory=$parent.'/whatsapp-unavailable-replay-'.substr(waReplayHash([WA_REPLAY_AUDIT_VERSION,$options]),0,24);
    if(!file_exists($auditDirectory)&&!is_link($auditDirectory)&&!mkdir($auditDirectory,0700))throw new RuntimeException('AUDIT_WRITE_FAILED');
    $directoryBefore=waReplayDirectory($auditDirectory,$uid);$preparedPath=$auditDirectory.'/PREPARED.json';$committedPath=$auditDirectory.'/COMMITTED.json';
    if(file_exists($preparedPath)||is_link($preparedPath)){
        $audit=waReplayReadAudit($preparedPath,$uid);
        if(($audit['version']??null)!==WA_REPLAY_AUDIT_VERSION||($audit['state']??null)!=='PREPARED'||($audit['options']??null)!==$options
            ||($audit['sources']??null)!==$sources||($audit['context']??null)!==$context||!is_array($audit['proofs']??null)||!is_array($audit['raw_ciphertext_sha256']??null))throw new RuntimeException('AUDIT_EXPECTATION_CHANGED');
        $expectedProofs=$audit['proofs'];
        if(array_keys($expectedProofs)!==$options['events'])throw new RuntimeException('AUDIT_EXPECTATION_CHANGED');
        foreach($current as$id=>$value){$expected=$expectedProofs[$id];$proof=$value['proof'];
            if(!is_array($expected)||!isset($expected['failure'])||($audit['raw_ciphertext_sha256'][$id]??null)!==$value['raw_ciphertext_sha256'])throw new RuntimeException('AUDIT_EXPECTATION_CHANGED');
            if($value['processed'])$proof['failure']=$expected['failure'];if($proof!==$expected)throw new RuntimeException('AUDIT_EXPECTATION_CHANGED');
        }
    }else{
        $expectedProofs=[];$rawHashes=[];
        foreach($current as$id=>$value){if($value['processed'])throw new RuntimeException('PRIOR_AUDIT_REQUIRED');$expectedProofs[$id]=$value['proof'];$rawHashes[$id]=$value['raw_ciphertext_sha256'];}
        $audit=['version'=>WA_REPLAY_AUDIT_VERSION,'state'=>'PREPARED','created_at_utc'=>gmdate('Y-m-d H:i:s'),
            'options'=>$options,'sources'=>$sources,'context'=>$context,'proofs'=>$expectedProofs,'raw_ciphertext_sha256'=>$rawHashes];
        waReplayWriteAudit($preparedPath,$audit,$uid);
    }
    $out['audit_directory']=$auditDirectory;$out['event_ids']=$options['events'];
    waReplaySyncDirectory($auditDirectory,$uid,$directoryBefore);waReplaySyncDirectory($parent,$uid,$parentBefore);
    if(waReplayIdentity(waReplayDirectory($parent,$uid))!==waReplayIdentity($parentBefore)
        ||waReplayIdentity(waReplayDirectory($auditDirectory,$uid))!==waReplayIdentity($directoryBefore))throw new RuntimeException('AUDIT_DIRECTORY_UNSAFE');
    $stage='FINAL_PREFLIGHT';waReplaySources($pins,$protocols,$sources);
    if(waReplayContext($workflow,$control,$options)!==$context||$connection->transactionLevel()!==0)throw new RuntimeException('CONTEXT_EXPECTATION_CHANGED');
    if(waReplayReadAudit($preparedPath,$uid)!==$audit)throw new RuntimeException('AUDIT_EXPECTATION_CHANGED');
    $stage='SCOPED_RECOVERY';$pdo->exec('SET SESSION TRANSACTION READ WRITE');$called=true;$out['writes']=null;
    $result=$db::transaction(static function()use($workflow,$control,$options,$context,$expectedProofs,$app){
        if(waReplayContext($workflow,$control,$options,true)!==$context)throw new RuntimeException('CONTEXT_EXPECTATION_CHANGED');
        return$app->make(\App\Services\Dashboard\WhatsAppInboxConsumer::class)->recoverUnavailableReplays($options['events'],$expectedProofs);
    },3);
    $stage='RESULT';
    if(!is_array($result)||($result['success']??null)!==true||!is_int($result['writes']??null)||!is_int($result['events_processed']??null)
        ||!is_int($result['events_skipped']??null)||!is_int($result['messages_replayed']??null)||$result['events_processed']<0||$result['events_skipped']<0
        ||$result['events_processed']+$result['events_skipped']!==3||$result['writes']!==2*$result['events_processed']||$result['messages_replayed']!==3
        ||!is_array($result['receipts']??null)||!array_is_list($result['receipts'])||count($result['receipts'])!==3)throw new RuntimeException('RESULT_UNVERIFIED');
    $seen=[];foreach($result['receipts']as$receipt){$id=$receipt['event_id']??null;
        if(!is_int($id)||!isset($expectedProofs[$id])||isset($seen[$id])||count($receipt)!==count($expectedProofs[$id])+2
            ||!in_array($receipt['state']??null,['PROCESSED','ALREADY_PROCESSED'],true)||!waReplayTime($receipt['processed_at']??null))throw new RuntimeException('RESULT_UNVERIFIED');
        $base=$receipt;unset($base['state'],$base['processed_at']);if($base!==$expectedProofs[$id])throw new RuntimeException('RESULT_UNVERIFIED');$seen[$id]=true;
    }
    $stage='COMMITTED_AUDIT';$committed=['version'=>WA_REPLAY_AUDIT_VERSION,'state'=>'COMMITTED','prepared_sha256'=>waReplayHash($audit),
        'receipts'=>$result['receipts']];
    if(file_exists($committedPath)||is_link($committedPath)){
        $prior=waReplayReadAudit($committedPath,$uid);
        // A verified rerun changes only the result label from PROCESSED to ALREADY_PROCESSED.
        $normalize=static function(array$value):array{foreach($value['receipts']??[]as$i=>$receipt)$value['receipts'][$i]['state']='RESOLVED';return$value;};
        if($normalize($prior)!==$normalize($committed))throw new RuntimeException('AUDIT_EXPECTATION_CHANGED');
    }else waReplayWriteAudit($committedPath,$committed,$uid);
    waReplaySyncDirectory($auditDirectory,$uid,$directoryBefore);
    $out['status']=$result['events_processed']===0?'ALREADY_RESOLVED':'RESOLVED';$out['writes']=$result['writes'];
    $out['events_processed']=$result['events_processed'];$out['events_already_resolved']=$result['events_skipped'];
    $out['messages_replayed']=$result['messages_replayed'];$out['raw_payloads_preserved']=true;$out['original_messages_preserved']=true;
}catch(Throwable$error){
    $out['status']=$called?'RECOVERY_UNVERIFIED':'RECOVERY_BLOCKED';$out['stage']=$stage;
    if($auditDirectory!==null)$out['audit_directory']=$auditDirectory;
    $allowed=['ARGUMENTS_INVALID','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED','CONVERSATION_SCOPE_MISMATCH',
        'CONTROL_EXPECTATION_CHANGED','NATIVE_EXPECTATION_CHANGED','SNAPSHOT_UNAVAILABLE','CUSTOMER_PROOF_REQUIRED','RAW_PROOF_REQUIRED',
        'REPLAY_PROOF_REQUIRED','FAILURE_EXPECTATION_CHANGED','AUDIT_DIRECTORY_UNSAFE','AUDIT_FILE_UNSAFE','AUDIT_READ_FAILED','AUDIT_WRITE_FAILED',
        'AUDIT_SYNC_FAILED','AUDIT_SIZE_INVALID','AUDIT_EXPECTATION_CHANGED','PRIOR_AUDIT_REQUIRED','CONTEXT_EXPECTATION_CHANGED','RESULT_UNVERIFIED'];
    $out['reason']=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'UNVERIFIED';
}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'],['RESOLVED','ALREADY_RESOLVED'],true)?0:1);
