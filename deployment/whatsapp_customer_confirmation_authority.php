<?php
// Observe the existing primary-authority guards. No repair, provider, projector,
// sender, worker, quote or ERP method is invoked by this read-only CLI.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_AUTHORITY','writes'=>0,
    'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,'worker_actions'=>0,
    'scope'=>'EXISTING_AUTHORITY_GUARD_OBSERVATION_NOT_REPAIR_OR_ORDER_AUTHORITY'];
$stage='ARGUMENTS';
function waAuthorityArguments(array$args):array{
    $names=['--conversation'=>'conversation','--expected-floor'=>'floor',
        '--expected-ceiling'=>'ceiling','--expected-revision'=>'revision'];$values=[];
    for($i=1;$i<count($args);$i++){
        $name=$names[$args[$i]]??null;
        if($name===null||isset($values[$name])||!isset($args[$i+1])
            ||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$args[$i+1]))throw new RuntimeException('ARGUMENTS_INVALID');
        $values[$name]=(int)$args[++$i];
    }
    if(count($values)!==4||$values['conversation']<1||$values['revision']<1||$values['ceiling']<=$values['floor'])
        throw new RuntimeException('ARGUMENTS_INVALID');
    return$values;
}
function waAuthorityCall($object,string$name,array$args=[]){
    $method=new ReflectionMethod($object,$name);$method->setAccessible(true);return$method->invokeArgs($object,$args);
}
function waAuthorityError(Throwable$error):array{
    $database=$error instanceof PDOException||is_a($error,'Illuminate\\Database\\QueryException');
    $info=$error->errorInfo??null;$sqlstate=is_array($info)?($info[0]??null):null;
    if(!is_string($sqlstate)||!preg_match('/\A[A-Z0-9]{5}\z/D',$sqlstate))$sqlstate=null;
    $number=is_array($info)?filter_var($info[1]??null,FILTER_VALIDATE_INT):false;
    return['category'=>$database?'DATABASE':'EXCEPTION','sqlstate'=>$sqlstate,
        'driver_errno'=>$number!==false&&$number>=0&&$number<=65535?$number:null];
}
function waAuthorityObserve(callable$call):array{
    try{return['ok'=>true,'value'=>$call()];}catch(Throwable$error){return['ok'=>false,'error'=>waAuthorityError($error)];}
}
function waAuthorityTime($value):?string{
    if(!is_string($value))return null;
    $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return$time&&$time->format('Y-m-d H:i:s')===$value&&substr($value,0,4)!=='0000'?$value:null;
}
function waAuthorityInteger($value):?int{
    $number=filter_var($value,FILTER_VALIDATE_INT);return$number!==false&&$number>=0?$number:null;
}
function waAuthorityCode($value):?string{
    return is_string($value)&&preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D',$value)?$value:null;
}
function waAuthoritySources(array$pins,array$protocols,?array$expected=null):array{
    $result=[];
    foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)
            throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');
        $actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        if(!in_array($actual,$path==='app/Support/WhatsAppInboxProtocol.php'?$protocols:[$pin],true)
            ||$expected!==null&&($expected[$path]??null)!==$actual)throw new RuntimeException('SOURCE_CHANGED');
        $result[$path]=$actual;
    }
    return$result;
}
/** Actor identity is retained only in the private comparison signature. */
function waAuthorityGate(array$gate):array{
    $floor=is_array($gate['floor']??null)?$gate['floor']:[];
    return['effective'=>($gate['effective']??null)===true,'reason'=>waAuthorityCode($gate['reason']??null),
        'revision'=>waAuthorityInteger($gate['revision']??null),'mode'=>waAuthorityCode($gate['mode']??null),
        'activation'=>['message_floor'=>waAuthorityInteger($floor['message_floor']??null),
            'event_floor'=>waAuthorityInteger($floor['event_floor']??null),
            'started_at_utc'=>waAuthorityTime($floor['started_at_utc']??null)],
        'backlog_version'=>waAuthorityInteger($gate['backlog_version']??null),
        'original_activation_started_at_utc'=>waAuthorityTime($gate['original_activation_started_at_utc']??null)];
}
function waAuthorityGateSignature(array$gate):array{
    return[waAuthorityGate($gate),$gate['actor_id']??null];
}
function waAuthorityControlSignature(array$state):array{
    return[$state['available']??null,$state['revision']??null,$state['policy']??null,$state['updated_at_utc']??null];
}
/** Exact failure predicates used by primaryCaptureClear; no payload is selected. */
function waAuthorityFailures(array$gate,?array$latest):array{
    $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
    $failures='whatsapp_inbox_ingestion_failures';$events='whatsapp_webhook_events';
    $public=['failures_table_present'=>$schema::hasTable($failures),'events_table_present'=>$schema::hasTable($events),
        'events_received_at_present'=>false,'scope'=>'GLOBAL_FAILURE_METADATA_USING_EXISTING_CAPTURE_GUARD'];
    if(!$public['failures_table_present']||!$public['events_table_present'])return['public'=>$public,'signature'=>null];
    $public['events_received_at_present']=$schema::hasColumn($events,'received_at');
    $floor=$gate['floor']??null;$original=$gate['original_activation_started_at_utc']??null;
    if(($gate['effective']??null)!==true||!is_array($floor)||!is_int($floor['event_floor']??null))
        return['public'=>$public+['gate_available'=>false],'signature'=>null];
    $public['gate_available']=true;
    $aggregate=static function($query,string$column):array{
        return['count'=>(int)(clone$query)->count(),'min_event_id'=>waAuthorityInteger((clone$query)->min($column)),
            'max_event_id'=>waAuthorityInteger((clone$query)->max($column))];
    };
    $public['forward']=$aggregate($db::table($failures)->where('event_id','>',$floor['event_floor']),'event_id');
    $applies=($gate['backlog_version']??null)===1&&is_string($original)&&$latest!==null&&$latest['sent_at']<=$original;
    $public['historical_guard_applies']=$applies;$public['historical']=null;
    if($applies){
        if(!$public['events_received_at_present'])$historical=$db::table($failures);
        else$historical=$db::table($failures.' as ai_failure')->leftJoin($events.' as ai_event','ai_event.id','=','ai_failure.event_id')
            ->where('ai_failure.event_id','<=',$floor['event_floor'])->where(function($query)use($floor,$original){
                $query->whereNull('ai_event.id')->orWhereNull('ai_event.received_at')->orWhere(function($range)use($floor,$original){
                    $range->where('ai_event.received_at','>=',$floor['started_at_utc'])->where('ai_event.received_at','<=',$original);
                });
            });
        $public['historical']=$aggregate($historical,$public['events_received_at_present']?'ai_failure.event_id':'event_id');
    }
    // Bound the private stability signature and include historical event timing.
    $metadata=$db::table($failures.' as f')->leftJoin($events.' as e','e.id','=','f.event_id')->orderBy('f.event_id')->limit(1001)
        ->get($public['events_received_at_present']?['f.event_id','f.reason','f.attempts','f.last_attempted_at','e.id as captured_event_id','e.received_at']:
            ['f.event_id','f.reason','f.attempts','f.last_attempted_at','e.id as captured_event_id'])->all();
    $public['stability_metadata_complete']=count($metadata)<=1000;
    return['public'=>$public,'signature'=>$public['stability_metadata_complete']?hash('sha256',serialize([$public,$metadata])):null];
}
try{
    $options=waAuthorityArguments($argv);$conversation=$options['conversation'];
    $stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException('OWNER_INVALID');
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/WhatsAppCustomerOrderConfirmation.php'=>'0e32b2f095f20442012f900b243af22478d620c1',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'fc9042a9cb110ecb4773861bfbe832f2b344df6c',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
    ];
    $protocols=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];
    $sources=waAuthoritySources($pins,$protocols);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();
    $stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');$out['read_only_session']=true;
    $stage='SCOPE';
    if(!$db::table('whatsapp_inbox_conversations')->where('id',$conversation)
        ->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)
        ->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID)->exists())throw new RuntimeException('CONVERSATION_SCOPE_MISMATCH');
    $out['conversation_id']=$conversation;$out['expected_floor']=$options['floor'];$out['expected_ceiling']=$options['ceiling'];
    $out['expected_control_revision']=$options['revision'];$out['read_at_utc']=gmdate('Y-m-d H:i:s');
    $control=$app->make(\App\Services\Dashboard\MetaAiControl::class);$state=$control->state();
    $stage='CONTROL';
    if(($state['available']??null)!==true||($state['policy']['enabled']??null)!==true
        ||($state['policy']['mode']??null)!=='ANALYZE_ONLY'||($state['revision']??null)!==$options['revision'])throw new RuntimeException('CONTROL_EXPECTATION_CHANGED');
    $workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $stage='NATIVE_BOUNDS';$ceiling=waAuthorityCall($workflow,'ceiling',[$conversation]);
    if($ceiling!==$options['ceiling'])throw new RuntimeException('CEILING_EXPECTATION_CHANGED');
    $activation=waAuthorityCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    if(!is_int($activation))throw new RuntimeException('ACTIVATION_UNAVAILABLE');
    $floor=waAuthorityCall($workflow,'customerReceiptFloor',[$conversation,max($activation,(int)waAuthorityCall($workflow,'dispatchedCeiling',[$conversation])),$ceiling]);
    if($floor!==$options['floor'])throw new RuntimeException('FLOOR_EXPECTATION_CHANGED');
    $stage='SNAPSHOT';$snapshot=waAuthorityCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    if(($snapshot['complete']??null)!==true||!is_string($snapshot['hash']??null)||!is_array($snapshot['transcript']??null))throw new RuntimeException('SNAPSHOT_UNAVAILABLE');
    $scan=$db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first();
    $out['snapshot_complete']=true;$out['snapshot_messages']=count($snapshot['transcript']);
    $proof=\App\Support\WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']);
    $out['customer_receipt_valid']=($proof['ok']??null)===true;$out['customer_receipt_reason']=waAuthorityCode($proof['reason']??null);
    $stage='GUARDS';$observations=[];
    $observations['probe_present']=waAuthorityObserve(static fn()=>\App\Support\WhatsAppOrderExtraction::hasProbe($snapshot['transcript']));
    $native=waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'primaryInbound',[$conversation,$snapshot]));
    $latest=$native['ok']&&is_array($native['value'])?$native['value']:null;
    $observations['native_inbound_authenticated']=$native['ok']?['ok'=>true,'value'=>$latest!==null]:$native;
    $out['latest_inbound']=$latest===null?null:['id'=>waAuthorityInteger($latest['id']??null),
        'sent_at_utc'=>waAuthorityTime($latest['sent_at']??null),
        'kind'=>in_array($latest['kind']??null,['text','image','audio','video','document','sticker','location','order','contacts','interactive','button','reaction','unsupported'],true)?$latest['kind']:'OTHER'];
    $readAt=$out['read_at_utc'];$freshAfter=gmdate('Y-m-d H:i:s',time()-86400);
    $observations['latest_inbound_fresh']=['ok'=>true,'value'=>$latest!==null&&$latest['sent_at']>$freshAfter];
    $observations['latest_inbound_not_future']=['ok'=>true,'value'=>$latest!==null&&$latest['sent_at']<=$readAt];
    foreach(['analysis_only'=>'analysisOnly','analysis_replies_settled'=>'analysisRepliesSettled']as$name=>$method)
        $observations[$name]=waAuthorityObserve(static fn()=>waAuthorityCall($workflow,$method,[$conversation]));
    $observations['customer_cancelled']=waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'primaryCancelled',[$latest['text']??null]));
    $observations['capture_clear']=$latest===null?['ok'=>true,'value'=>false]:waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'primaryCaptureClear',[$conversation,$latest]));
    $baseGate=waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'controlGate',[$conversation]));
    $inboundGate=$latest===null?['ok'=>true,'value'=>[]]:waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'controlGate',[$conversation,$latest]));
    $baseToken=waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'controlToken',[$conversation]));
    $inboundToken=$latest===null?['ok'=>true,'value'=>null]:waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'controlToken',[$conversation,$latest]));
    $authority=waAuthorityObserve(static fn()=>waAuthorityCall($workflow,'primaryAuthority',[$conversation,$snapshot]));
    foreach(['base_control_gate'=>$baseGate,'inbound_control_gate'=>$inboundGate]as$name=>$observation)
        $out[$name]=$observation['ok']?waAuthorityGate($observation['value']):['error'=>$observation['error']];
    $observations['base_token_available']=$baseToken['ok']?['ok'=>true,'value'=>is_array($baseToken['value'])]:$baseToken;
    $observations['inbound_token_available']=$inboundToken['ok']?['ok'=>true,'value'=>is_array($inboundToken['value'])]:$inboundToken;
    $observations['tokens_match']=['ok'=>true,'value'=>$baseToken['ok']&&$inboundToken['ok']&&is_array($baseToken['value'])&&$baseToken['value']===$inboundToken['value']];
    $observations['primary_authority_available']=$authority['ok']?['ok'=>true,'value'=>is_array($authority['value'])]:$authority;
    $observations['primary_authority_matches_base']=['ok'=>true,'value'=>$authority['ok']&&$baseToken['ok']&&is_array($baseToken['value'])&&$authority['value']===$baseToken['value']];
    $out['guards']=[];
    foreach($observations as$name=>$observation)$out['guards'][$name]=$observation['ok']&&is_bool($observation['value'])?['ready'=>true,'value'=>$observation['value']]:['ready'=>false,'error'=>$observation['error']??['category'=>'INVALID_RESULT']];
    $failures=waAuthorityObserve(static fn()=>waAuthorityFailures($inboundGate['value']??[],$latest));
    $out['capture_failures']=$failures['ok']?$failures['value']['public']:['error'=>$failures['error']];
    $reason='OBSERVATIONAL_CLEAR';
    foreach(['probe_present'=>[false,'PROBE_PRESENT'],'native_inbound_authenticated'=>[true,'NATIVE_INBOUND_UNAUTHENTICATED'],
        'latest_inbound_fresh'=>[true,'INBOUND_EXPIRED'],'latest_inbound_not_future'=>[true,'INBOUND_FUTURE'],
        'analysis_only'=>[true,'MODE_NOT_ANALYZE_ONLY'],'analysis_replies_settled'=>[true,'REPLIES_UNSETTLED'],
        'customer_cancelled'=>[false,'CUSTOMER_CANCELLED'],'capture_clear'=>[true,'CAPTURE_BLOCKED'],
        'base_token_available'=>[true,'BASE_CONTROL_BLOCKED'],'inbound_token_available'=>[true,'INBOUND_CONTROL_BLOCKED'],
        'tokens_match'=>[true,'AUTHORITY_TOKEN_CHANGED'],'primary_authority_available'=>[true,'PRIMARY_AUTHORITY_UNAVAILABLE'],
        'primary_authority_matches_base'=>[true,'PRIMARY_AUTHORITY_MISMATCH']]as$name=>[$expected,$failure]){
        if(!$out['guards'][$name]['ready']){$reason='GUARD_OBSERVATION_ERROR';break;}
        if($out['guards'][$name]['value']!==$expected){$reason=$failure;break;}
    }
    $out['reason']=$reason;
    $stage='CURRENTNESS';$currentSnapshot=waAuthorityCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    $out['snapshot_unchanged_during_read']=($currentSnapshot['complete']??null)===true&&($currentSnapshot['hash']??null)===$snapshot['hash']
        &&waAuthorityCall($workflow,'ceiling',[$conversation])===$ceiling;
    $currentActivation=waAuthorityCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    $currentFloor=waAuthorityCall($workflow,'customerReceiptFloor',[$conversation,max((int)$currentActivation,
        (int)waAuthorityCall($workflow,'dispatchedCeiling',[$conversation])),$ceiling]);
    $out['activation_floor_unchanged_during_read']=$currentActivation===$activation&&$currentFloor===$floor;
    $out['control_unchanged_during_read']=waAuthorityControlSignature($control->state())===waAuthorityControlSignature($state)
        &&$baseGate['ok']&&$inboundGate['ok']
        &&waAuthorityGateSignature(waAuthorityCall($workflow,'controlGate',[$conversation]))===waAuthorityGateSignature($baseGate['value'])
        &&($latest===null||waAuthorityGateSignature(waAuthorityCall($workflow,'controlGate',[$conversation,$latest]))===waAuthorityGateSignature($inboundGate['value']));
    $out['scan_unchanged_during_read']=serialize($db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first())===serialize($scan);
    $laterFailures=waAuthorityObserve(static fn()=>waAuthorityFailures($inboundGate['value']??[],$latest));
    $out['failure_metadata_unchanged_during_read']=$failures['ok']&&$laterFailures['ok']&&$failures['value']['signature']!==null
        &&$failures['value']['signature']===$laterFailures['value']['signature'];
    $stage='FINAL_SOURCE_GUARD';waAuthoritySources($pins,$protocols,$sources);$out['source_unchanged_during_read']=true;
    if($connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $stable=$out['snapshot_unchanged_during_read']&&$out['activation_floor_unchanged_during_read']&&$out['control_unchanged_during_read']&&$out['scan_unchanged_during_read']&&$out['failure_metadata_unchanged_during_read'];
    $out['status']=$stable?'READ_READY':'OBSERVATION_CHANGED';
}catch(Throwable$error){
    $allowed=['ARGUMENTS_INVALID','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED','CONVERSATION_SCOPE_MISMATCH',
        'CONTROL_EXPECTATION_CHANGED','CEILING_EXPECTATION_CHANGED','ACTIVATION_UNAVAILABLE','FLOOR_EXPECTATION_CHANGED','SNAPSHOT_UNAVAILABLE'];
    $out['status']='READ_BLOCKED';$out['stage']=$stage;$out['reason']=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'OBSERVATION_ERROR';
    if($out['reason']==='OBSERVATION_ERROR')$out['error']=waAuthorityError($error);
}
while(ob_get_level())ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(($out['status']??null)==='READ_READY'?0:1);
