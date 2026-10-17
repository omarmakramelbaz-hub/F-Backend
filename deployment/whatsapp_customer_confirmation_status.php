<?php
// Read-only proof of captured customer recap and existing ERP receipt. No planner,
// extraction provider, analyze/process, quote, dispatch, projection or sender runs.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_STATUS',
    'writes'=>0,'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,
    'scope'=>'CUSTOMER_RECAP_OBSERVATION_NOT_ORDER_ELIGIBILITY'];
$stage='ARGUMENTS';$pdo=null;
function waConfirmationInt($value):?int{
    $n=filter_var($value,FILTER_VALIDATE_INT);return$n!==false&&$n>=0?$n:null;
}
function waConfirmationCode($value,array$allowed=[],?string$fallback=null):?string{
    if(!is_string($value)||!preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D',$value))return$fallback;
    return$allowed===[]||in_array($value,$allowed,true)?$value:$fallback;
}
function waConfirmationCall($object,string$name,array$args=[]){
    $method=new ReflectionMethod($object,$name);$method->setAccessible(true);return$method->invokeArgs($object,$args);
}
function waConfirmationGate(array$value):array{
    return['effective'=>($value['effective']??null)===true,
        'reason'=>waConfirmationCode($value['reason']??null),
        'revision'=>waConfirmationInt($value['revision']??null)];
}
function waConfirmationFields(array$data):array{
    $customer=is_array($data['customer']??null)?$data['customer']:[];$fields=[];
    foreach(['name','phone','address','area']as$key)$fields[$key]=is_string($customer[$key]??null)&&trim($customer[$key])!=='';
    return$fields;
}
function waConfirmationErp(int$id,string$branch):array{
    $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
    $out=['available'=>false];
    if(!$schema::hasTable('pos_service_tickets')||!$schema::hasColumns('pos_service_tickets',
        ['id','branch','channel','last_kitchen_id']))return$out;
    $ticket=$db::table('pos_service_tickets')->where('id',$id)->where('branch',$branch)->where('channel','phone')
        ->first(['id','last_kitchen_id']);
    if(!$ticket)return['available'=>true,'ticket_scope_verified'=>false];
    $out=['available'=>true,'ticket_scope_verified'=>true,'kitchen_observation_available'=>false,
        'print_queue_observation_available'=>false,'print_scope'=>'QUEUE_ENTRY_NOT_PHYSICAL_PRINTER_OUTPUT'];
    if($schema::hasTable('pos_service_kitchen_tickets')&&$schema::hasColumns('pos_service_kitchen_tickets',
        ['id','ticket_id','branch'])){
        $out['kitchen_observation_available']=true;
        $out['kitchen_ticket_present']=$ticket->last_kitchen_id!==null&&$db::table('pos_service_kitchen_tickets')
            ->where('id',$ticket->last_kitchen_id)->where('ticket_id',$id)->where('branch',$branch)->exists();
    }
    // This queue is optional on older independent POS installations. Its absence
    // is unavailable evidence, never a proof that printing did or did not occur.
    if($schema::hasTable('pos_branch_print_jobs')&&$schema::hasColumns('pos_branch_print_jobs',['ticket_id','branch'])){
        $out['print_queue_observation_available']=true;
        $out['print_jobs_present']=$db::table('pos_branch_print_jobs')->where('ticket_id',$id)->where('branch',$branch)->exists();
    }
    return$out;
}
try{
    $conversation=null;$draftId=null;
    for($i=1;$i<count($argv);$i++){
        if($argv[$i]==='--conversation'&&$conversation===null&&isset($argv[$i+1])
            &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$argv[$i+1])){$conversation=(int)$argv[++$i];continue;}
        if($argv[$i]==='--draft'&&$draftId===null&&isset($argv[$i+1])
            &&preg_match('/\A[1-9][0-9]{0,9}\z/D',$argv[$i+1])){$draftId=(int)$argv[++$i];continue;}
        throw new RuntimeException();
    }
    if($conversation===null)throw new RuntimeException();
    $stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/WhatsAppCustomerOrderConfirmation.php'=>'558440a772a7798c4f9c11a713685c79b7fdabda',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'d91ea2f4c84357e16e0556b205484d64cfce1079',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
    ];
    // Both exact reviewed protocols authenticate the same native messages. The
    // later CRM revision additionally projects scoped status receipts; no other
    // runtime drift is admitted and neither file is published by this repair.
    $compatibleProtocolPins=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];
    $observedSourceHashes=[];
    foreach($pins as$path=>$pin){
        $out['source_guard_file']=$path;
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)throw new RuntimeException();
        $bytes=file_get_contents($path);
        $allowed=$path==='app/Support/WhatsAppInboxProtocol.php'?$compatibleProtocolPins:[$pin];
        if(!is_string($bytes))throw new RuntimeException();$actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        if(!in_array($actual,$allowed,true))throw new RuntimeException();$observedSourceHashes[$path]=$actual;
    }
    unset($out['source_guard_file']);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();
    $stage='READ_ONLY_SESSION';if($connection->getDriverName()!=='mysql')throw new RuntimeException();
    $pdo=$connection->getPdo();$pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $out['read_only_session']=true;
    $stage='SCOPE';$thread=$db::table('whatsapp_inbox_conversations')->where('id',$conversation)
        ->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)
        ->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID)->first(['id']);
    if(!$thread)throw new RuntimeException();$out['conversation_id']=$conversation;
    $stage='CONTROL';$control=$app->make(\App\Services\Dashboard\MetaAiControl::class);$state=$control->state();
    $out['control']=['available'=>($state['available']??null)===true,
        'mode'=>waConfirmationCode($state['policy']['mode']??null,['ANALYZE_ONLY','REPLY_AND_ANALYZE']),
        'enabled'=>($state['policy']['enabled']??null)===true,
        'revision'=>waConfirmationInt($state['revision']??null),
        'analysis'=>waConfirmationGate($control->analysisGate('whatsapp')),
        'dispatch'=>waConfirmationGate($control->dispatchGate('whatsapp')),
        'automatic_replies'=>waConfirmationGate($control->gate('whatsapp'))];
    $stage='SNAPSHOT';$workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $activation=waConfirmationCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    $baseFloor=max((int)($activation??0),(int)waConfirmationCall($workflow,'dispatchedCeiling',[$conversation]));
    $ceiling=(int)waConfirmationCall($workflow,'ceiling',[$conversation]);
    // The workflow can select a complete authenticated self-contained recap
    // suffix when earlier history exceeded its normal bounded snapshot. This is
    // an observation boundary, never a stored activation/cutoff update.
    $floor=waConfirmationCall($workflow,'customerReceiptFloor',[$conversation,$baseFloor,$ceiling]);
    if(!is_int($floor)||$floor<$baseFloor||$floor!==$baseFloor&&$floor>$ceiling)throw new RuntimeException();
    $snapshot=waConfirmationCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    $out['base_floor']=$baseFloor;$out['floor']=$floor;$out['ceiling']=$ceiling;
    $out['self_contained_suffix_used']=$floor>$baseFloor;$out['activation_available']=$activation!==null;
    $out['snapshot_complete']=($snapshot['complete']??null)===true;
    $out['snapshot_messages']=is_array($snapshot['transcript']??null)?count($snapshot['transcript']):null;
    if(!$out['snapshot_complete']||!is_string($snapshot['hash']??null)||!is_array($snapshot['transcript']??null))throw new RuntimeException();
    $stage='CUSTOMER_RECEIPT';$parsed=\App\Support\WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']);
    if(!is_array($parsed)||!is_bool($parsed['ok']??null))throw new RuntimeException();
    $data=is_array($parsed['data']??null)?$parsed['data']:[];
    $receipt=is_array($data['evidence_receipt']??null)?$data['evidence_receipt']:[];
    $location=is_array($data['textual_location']??null)?$data['textual_location']:[];
    $valid=($parsed['ok']??null)===true&&($data['type']??null)==='CUSTOMER_CONFIRMED_RECAP';
    $out['candidate']=['valid'=>$valid,'reason'=>waConfirmationCode($parsed['reason']??null),
        'type'=>$valid?'CUSTOMER_CONFIRMED_RECAP':null,
        'customer_recap_captured'=>$valid&&is_string($receipt['recap_id']??null),
        'customer_approval_captured'=>$valid&&is_string($receipt['approval_id']??null),
        'items_count'=>is_array($data['items']??null)?count($data['items']):0,
        'fields_present'=>waConfirmationFields($data),
        'textual_location_present'=>$location!==[],
        'coordinate_order'=>waConfirmationCode($location['coordinate_order']??null,['LAT_LONG','LONG_LAT','UNRESOLVED','UNKNOWN']),
        'coordinates_valid'=>is_array($location['point']??null),
        'native_pins_count'=>count(array_filter($snapshot['transcript'],static fn($row)=>is_array($row['location']??null)))];
    $stage='DRAFT';$query=$db::table('whatsapp_order_drafts')->where('conversation_id',$conversation);
    if($draftId!==null)$query->where('id',$draftId);
    $row=$query->orderByDesc('id')->select('id','status','reason','evidence_floor','evidence_ceiling','evidence_hash','ticket_id','assigned_branch')
        ->selectRaw('CASE WHEN LENGTH(extraction)<=262144 THEN extraction ELSE NULL END AS extraction')->first();
    if($draftId!==null&&!$row)throw new RuntimeException();
    $out['draft']=null;
    if($row){
        $stored=[];
        if(is_string($row->extraction)){
            $plain=\Illuminate\Support\Facades\Crypt::decryptString($row->extraction);
            if(strlen($plain)>131072)throw new RuntimeException();
            $stored=json_decode($plain,true,32,JSON_THROW_ON_ERROR);if(!is_array($stored))throw new RuntimeException();
        }
        $branch=is_string($row->assigned_branch)&&preg_match('/\A(?:f|gs):[1-9][0-9]{0,18}\z/D',$row->assigned_branch)?$row->assigned_branch:null;
        $out['draft']=['id'=>(int)$row->id,
            'status'=>waConfirmationCode($row->status,['REVIEW','READY','NONE','CANCELLED','DISPATCHED'],'OTHER'),
            'reason'=>waConfirmationCode($row->reason),
            'uses_customer_receipt'=>($stored['type']??null)==='CUSTOMER_CONFIRMED_RECAP',
            'matches_current_snapshot'=>(int)$row->evidence_floor===$floor&&(int)$row->evidence_ceiling===$ceiling
                &&is_string($row->evidence_hash)&&hash_equals($snapshot['hash'],$row->evidence_hash),
            'ticket_id'=>waConfirmationInt($row->ticket_id),'branch'=>$branch];
        if($out['draft']['status']==='DISPATCHED'&&$out['draft']['ticket_id']!==null&&$branch!==null)
            $out['draft']['erp']=waConfirmationErp($out['draft']['ticket_id'],$branch);
    }
    $stage='FINAL_GUARD';$again=waConfirmationCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    $after=$control->state();
    $latestActivation=waConfirmationCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    $latestBaseFloor=max((int)($latestActivation??0),(int)waConfirmationCall($workflow,'dispatchedCeiling',[$conversation]));
    $latestFloor=waConfirmationCall($workflow,'customerReceiptFloor',[$conversation,$latestBaseFloor,$ceiling]);
    $out['snapshot_current_at_read']=(int)waConfirmationCall($workflow,'ceiling',[$conversation])===$ceiling
        &&$latestActivation===$activation&&$latestBaseFloor===$baseFloor&&$latestFloor===$floor
        &&($again['complete']??null)===true&&is_string($again['hash']??null)&&hash_equals($snapshot['hash'],$again['hash']);
    $out['control_unchanged_during_read']=($after['revision']??null)===($state['revision']??null)
        &&($after['policy']??null)===($state['policy']??null);
    foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)throw new RuntimeException();
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||!hash_equals($observedSourceHashes[$path],sha1('blob '.strlen($bytes)."\0".$bytes)))throw new RuntimeException();
    }
    $out['status']=$out['snapshot_current_at_read']&&$out['control_unchanged_during_read']?'READ_READY':'OBSERVATION_CHANGED';
}catch(Throwable$ignore){
    $out['status']='OBSERVATION_BLOCKED';$out['stage']=$stage;
}
while(ob_get_level())ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'],['READ_READY','OBSERVATION_CHANGED'],true)?0:1);
