<?php
// Scoped CLI recovery only: record one already captured proved customer recap.
// No provider, projector, planner, quote, sender or ERP service is invoked here.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'WHATSAPP_CUSTOMER_CONFIRMATION_RECOVERY','writes'=>0,
    'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,
    'scope'=>'ONE_PROVED_CUSTOMER_RECEIPT_DRAFT_NOT_ORDER_DISPATCH'];
$stage='ARGUMENTS';$called=false;$pdo=null;
function waRecoveryArguments(array$arguments):array{
    $names=['--conversation'=>'conversation','--expected-floor'=>'floor',
        '--expected-ceiling'=>'ceiling','--expected-revision'=>'control_revision'];$values=[];
    for($i=1;$i<count($arguments);$i++){
        $name=$names[$arguments[$i]]??null;
        if($name===null||isset($values[$name])||!isset($arguments[$i+1])
            ||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$arguments[$i+1]))throw new RuntimeException('ARGUMENTS_INVALID');
        $values[$name]=(int)$arguments[++$i];
    }
    if(count($values)!==4||$values['conversation']<1||$values['control_revision']<1
        ||$values['ceiling']<=$values['floor'])throw new RuntimeException('ARGUMENTS_INVALID');
    return$values;
}
function waRecoveryCall($object,string$name,array$args=[]){
    $method=new ReflectionMethod($object,$name);$method->setAccessible(true);return$method->invokeArgs($object,$args);
}
function waRecoverySources(array$pins,array$compatibleProtocols,?array$expected=null):array{
    $observed=[];
    foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)
            throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');
        $actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        $allowed=$path==='app/Support/WhatsAppInboxProtocol.php'?$compatibleProtocols:[$pin];
        if(!in_array($actual,$allowed,true)||$expected!==null&&($expected[$path]??null)!==$actual)
            throw new RuntimeException('SOURCE_CHANGED');
        $observed[$path]=$actual;
    }
    return$observed;
}
try{
    $options=waRecoveryArguments($argv);$conversation=$options['conversation'];
    $stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException('OWNER_INVALID');
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/WhatsAppCustomerOrderConfirmation.php'=>'0e32b2f095f20442012f900b243af22478d620c1',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'4aa5cd5a5fb653ec1022dbee02e6768733f3dc19',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
    ];
    $compatibleProtocolPins=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];
    $observedSources=waRecoverySources($pins,$compatibleProtocolPins);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();
    $stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $pdo=$connection->getPdo();$pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $stage='SCOPE';$thread=$db::table('whatsapp_inbox_conversations')->where('id',$conversation)
        ->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)
        ->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID)->first(['id']);
    if(!$thread)throw new RuntimeException('CONVERSATION_SCOPE_MISMATCH');
    $out['conversation_id']=$conversation;$out['expected_floor']=$options['floor'];
    $out['expected_ceiling']=$options['ceiling'];$out['expected_control_revision']=$options['control_revision'];
    $stage='CONTROL';$state=$app->make(\App\Services\Dashboard\MetaAiControl::class)->state();
    if(($state['available']??null)!==true||($state['policy']['enabled']??null)!==true
        ||($state['policy']['mode']??null)!=='ANALYZE_ONLY'||($state['revision']??null)!==$options['control_revision'])
        throw new RuntimeException('CONTROL_EXPECTATION_CHANGED');
    $stage='NATIVE_BOUNDS';$workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $ceiling=waRecoveryCall($workflow,'ceiling',[$conversation]);
    if($ceiling!==$options['ceiling'])throw new RuntimeException('CEILING_EXPECTATION_CHANGED');
    $activation=waRecoveryCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    if(!is_int($activation))throw new RuntimeException('ACTIVATION_UNAVAILABLE');
    $floor=max($activation,(int)waRecoveryCall($workflow,'dispatchedCeiling',[$conversation]));
    $floor=waRecoveryCall($workflow,'customerReceiptFloor',[$conversation,$floor,$ceiling]);
    if($floor!==$options['floor']){
        // A normal worker may already have dispatched this exact receipt. Only
        // an existing draft at the supplied native bounds permits the locked
        // method to verify its immutable data and return ALREADY_RECORDED.
        $existing=$db::table('whatsapp_order_drafts')->where('conversation_id',$conversation)
            ->where('evidence_floor',$options['floor'])->where('evidence_ceiling',$ceiling)->count();
        if($existing!==1)throw new RuntimeException('FLOOR_EXPECTATION_CHANGED');
    }
    $stage='SNAPSHOT';$snapshot=waRecoveryCall($workflow,'snapshot',[$conversation,$options['floor'],$ceiling]);
    if(($snapshot['complete']??null)!==true||!is_array($snapshot['transcript']??null)
        ||!is_string($snapshot['hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$snapshot['hash']))
        throw new RuntimeException('SNAPSHOT_UNAVAILABLE');
    $proof=\App\Support\WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']);
    if(($proof['ok']??null)!==true||($proof['data']['type']??null)!=='CUSTOMER_CONFIRMED_RECAP'
        ||!is_array($proof['data']['textual_location']['point']??null))throw new RuntimeException('CUSTOMER_PROOF_REQUIRED');
    $stage='SCAN';$scan=$db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first();
    if(!$scan)throw new RuntimeException('SCAN_REQUIRED');
    $scanHash=waRecoveryCall($workflow,'customerReceiptRecoveryScanHash',[$scan]);
    if(!is_string($scanHash)||!preg_match('/\A[a-f0-9]{64}\z/D',$scanHash))throw new RuntimeException('SCAN_UNAVAILABLE');
    $expected=['control_revision'=>$options['control_revision'],'floor'=>$options['floor'],'ceiling'=>$ceiling,
        'snapshot_hash'=>$snapshot['hash'],'scan_hash'=>$scanHash];
    $stage='FINAL_SOURCE_GUARD';waRecoverySources($pins,$compatibleProtocolPins,$observedSources);
    $stage='SCOPED_RECOVERY';
    if($connection->transactionLevel()!==0)throw new RuntimeException('TRANSACTION_ALREADY_ACTIVE');
    $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    // After this boundary an exception may follow a commit. Never claim zero
    // writes merely because a result or the caller's connection was interrupted.
    $called=true;$out['writes']=null;
    $result=$workflow->recoverCustomerReceipt($conversation,$expected);
    $stage='RESULT';
    if(!is_array($result)||($result['success']??null)!==true
        ||!in_array($result['status']??null,['RECORDED','ALREADY_RECORDED'],true)
        ||!is_int($result['draft_id']??null)||$result['draft_id']<1
        ||($result['writes']??null)!==($result['status']==='RECORDED'?2:0)
        ||($result['ai_calls']??null)!==0||($result['message_sends']??null)!==0||($result['erp_writes']??null)!==0)
        throw new RuntimeException('RESULT_UNVERIFIED');
    $out['status']=$result['status'];$out['draft_id']=$result['draft_id'];$out['writes']=$result['writes'];
}catch(Throwable$error){
    $out['status']=$called?'RECOVERY_UNVERIFIED':'RECOVERY_BLOCKED';$out['stage']=$stage;
    $reason=$error->getMessage();
    $allowed=['ARGUMENTS_INVALID','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED','CONVERSATION_SCOPE_MISMATCH',
        'CONTROL_EXPECTATION_CHANGED','CEILING_EXPECTATION_CHANGED','ACTIVATION_UNAVAILABLE','FLOOR_EXPECTATION_CHANGED',
        'SNAPSHOT_UNAVAILABLE','CUSTOMER_PROOF_REQUIRED','SCAN_REQUIRED','SCAN_UNAVAILABLE','TRANSACTION_ALREADY_ACTIVE','RESULT_UNVERIFIED',
        'CUSTOMER_RECEIPT_RECOVERY_CLI_ONLY','CUSTOMER_RECEIPT_RECOVERY_EXPECTATION_INVALID','CUSTOMER_RECEIPT_RECOVERY_MODE_REQUIRED',
        'CUSTOMER_RECEIPT_RECOVERY_SCAN_MISSING','CUSTOMER_RECEIPT_RECOVERY_PROOF_REQUIRED','CUSTOMER_RECEIPT_RECOVERY_DRAFT_CONFLICT',
        'CUSTOMER_RECEIPT_RECOVERY_SCAN_CHANGED','CUSTOMER_RECEIPT_RECOVERY_NOT_EXHAUSTED','AI_CONTROL_CHANGED','STALE_TRANSCRIPT',
        'ANALYSIS_BUSY','ANALYSIS_BACKOFF','ANALYSIS_OUTSIDE_HOURS','AUTO_NOT_CONFIGURED',
        'CAPTURE_PENDING','ACCEPTED_REPLIES_PENDING','TRANSCRIPT_SETTLING','AI_CONTROL_BLOCKED'];
    $out['reason']=in_array($reason,$allowed,true)?$reason:'UNVERIFIED';
}
while(ob_get_level())ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'],['RECORDED','ALREADY_RECORDED'],true)?0:1);
