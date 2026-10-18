<?php
// One explicit owner decision for one captured receipt. Default mode only checks.
// The workflow retains all automatic receipt, routing, sale and duplicate guards.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'WHATSAPP_CUSTOMER_CONFIRMATION_OWNER_CHOICE','writes'=>0,
    'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,
    'scope'=>'ONE_OWNER_APPROVED_CURRENT_RECEIPT_ITEM_NOT_GLOBAL_ALIAS',
    'check_scope'=>'RECEIPT_AND_CATALOG_ONLY_FINAL_ERP_QUOTE_ON_EXECUTE'];
$stage='ARGUMENTS';$called=false;$execute=false;
function waOwnerChoiceArguments(array$arguments):array{
    $names=['--conversation'=>'conversation','--draft'=>'draft','--expected-floor'=>'floor',
        '--expected-ceiling'=>'ceiling','--expected-revision'=>'control_revision',
        '--expected-draft-revision'=>'draft_revision'];$values=[];$mode=null;
    for($i=1;$i<count($arguments);$i++){
        $flag=$arguments[$i];
        if(in_array($flag,['--check','--execute'],true)){
            if($mode!==null)throw new RuntimeException('ARGUMENTS_INVALID');$mode=$flag;continue;
        }
        $name=$names[$flag]??null;
        if($name===null||isset($values[$name])||!isset($arguments[$i+1])
            ||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$arguments[$i+1]))throw new RuntimeException('ARGUMENTS_INVALID');
        $values[$name]=(int)$arguments[++$i];
    }
    $approved=['conversation'=>31,'draft'=>2218,'floor'=>6559,'ceiling'=>10303,'control_revision'=>15,'draft_revision'=>2];
    if(count($values)!==count($approved))throw new RuntimeException('ARGUMENTS_INVALID');
    foreach($approved as$key=>$value)if(($values[$key]??null)!==$value)throw new RuntimeException('OWNER_CHOICE_SCOPE_NOT_APPROVED');
    return$approved+['execute'=>$mode==='--execute'];
}
function waOwnerChoiceSources(array$pins,array$protocols,?array$expected=null):array{
    $observed=[];
    foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)
            throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');
        $actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        $allowed=$path==='app/Support/WhatsAppInboxProtocol.php'?$protocols:[$pin];
        if(!in_array($actual,$allowed,true)||$expected!==null&&($expected[$path]??null)!==$actual)
            throw new RuntimeException('SOURCE_CHANGED');
        $observed[$path]=$actual;
    }
    return$observed;
}
function waOwnerChoiceResult(array$result,array$options):array{
    $status=$result['status']??null;$dispatch=$status==='DISPATCHED';$already=$status==='ALREADY_DISPATCHED';
    if(($result['success']??null)!==true||!in_array($status,$options['execute']?['DISPATCHED','ALREADY_DISPATCHED']:['READY_TO_DISPATCH','ALREADY_DISPATCHED'],true)
        ||($result['draft_id']??null)!==$options['draft']||($result['conversation_id']??null)!==$options['conversation']
        ||($result['branch']??null)!=='f:363'||($result['ai_calls']??null)!==0||($result['message_sends']??null)!==0
        ||!array_key_exists('writes',$result)||$result['writes']!==($dispatch?null:0)
        ||!array_key_exists('erp_writes',$result)||$result['erp_writes']!==($dispatch?null:0)
        ||!is_array($result['items']??null)||!array_is_list($result['items'])||count($result['items'])!==5)
        throw new RuntimeException('RESULT_UNVERIFIED');
    $items=[];
    foreach($result['items']as$item){
        if(!is_array($item)||!is_int($item['product_id']??null)||$item['product_id']<1||($item['option_id']??null)!==''
            ||!is_string($item['quantity']??null)||!preg_match('/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?\z/D',$item['quantity'])
            ||(float)$item['quantity']<=0
            ||!in_array($item['quantity_mode']??null,['weight','piece'],true))throw new RuntimeException('RESULT_UNVERIFIED');
        $items[]=['product_id'=>$item['product_id'],'option_id'=>'','quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode']];
    }
    $last=$items[4];
    if($last!==['product_id'=>2557,'option_id'=>'','quantity'=>'2','quantity_mode'=>'piece'])throw new RuntimeException('RESULT_UNVERIFIED');
    if(($dispatch||$already)&&(!is_int($result['ticket_id']??null)||$result['ticket_id']<1)
        ||!$dispatch&&!$already&&($result['ticket_id']??null)!==null)throw new RuntimeException('RESULT_UNVERIFIED');
    return['status'=>$status,'draft_id'=>$options['draft'],'conversation_id'=>$options['conversation'],'branch'=>'f:363',
        'items'=>$items,'ticket_id'=>$result['ticket_id']??null,'writes'=>$result['writes'],'erp_writes'=>$result['erp_writes']];
}
try{
    $options=waOwnerChoiceArguments($argv);$execute=$options['execute'];$out['mode']=$execute?'EXECUTE':'CHECK';
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
        'app/Services/Dashboard/TakeawayAccess.php'=>'d063ee444c347c41620916b4513d0f03785141aa',
        'app/Services/Dashboard/TakeawayCatalog.php'=>'e96fce33037a06330f95d6a91553950cdf32d763',
    ];
    $protocols=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];
    $sources=waOwnerChoiceSources($pins,$protocols);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();$stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $pdo=$connection->getPdo();$pdo->exec('SET SESSION TRANSACTION READ ONLY');$out['read_only_session']=true;
    $stage='SCOPE';$access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    if(!$db::table('whatsapp_inbox_conversations')->where('id',$options['conversation'])
        ->where('waba_id',$access::WABA_ID)->where('phone_number_id',$access::PHONE_ID)->exists())throw new RuntimeException('CONVERSATION_SCOPE_MISMATCH');
    $row=$db::table('whatsapp_order_drafts')->where('id',$options['draft'])->where('conversation_id',$options['conversation'])
        ->first(['id','conversation_id','evidence_floor','evidence_ceiling','evidence_hash']);
    if(!$row||(int)$row->evidence_floor!==$options['floor']||(int)$row->evidence_ceiling!==$options['ceiling']
        ||!is_string($row->evidence_hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$row->evidence_hash))throw new RuntimeException('DRAFT_EXPECTATION_CHANGED');
    $expected=['conversation'=>$options['conversation'],'control_revision'=>$options['control_revision'],
        'draft_revision'=>$options['draft_revision'],'floor'=>$options['floor'],'ceiling'=>$options['ceiling'],'snapshot_hash'=>$row->evidence_hash];
    $workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $stage='FINAL_SOURCE_GUARD';waOwnerChoiceSources($pins,$protocols,$sources);
    if($connection->transactionLevel()!==0)throw new RuntimeException('TRANSACTION_ALREADY_ACTIVE');
    $stage='OWNER_CHOICE';
    if($execute){$pdo->exec('SET SESSION TRANSACTION READ WRITE');$out['read_only_session']=false;}
    $called=true;if($execute){$out['writes']=null;$out['erp_writes']=null;}
    $result=$workflow->resolveCustomerReceiptChoice($options['draft'],$expected,$execute);$stage='RESULT';
    if(!is_array($result))throw new RuntimeException('RESULT_UNVERIFIED');
    $out=array_replace($out,waOwnerChoiceResult($result,$options));
}catch(Throwable$error){
    $out['status']=$called&&$execute?'OWNER_CHOICE_UNVERIFIED':'OWNER_CHOICE_BLOCKED';$out['stage']=$stage;
    $reason=$error->getMessage();
    $allowed=['ARGUMENTS_INVALID','OWNER_CHOICE_SCOPE_NOT_APPROVED','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED',
        'CONVERSATION_SCOPE_MISMATCH','DRAFT_EXPECTATION_CHANGED','TRANSACTION_ALREADY_ACTIVE','RESULT_UNVERIFIED',
        'AI_CONTROL_CHANGED','STALE_TRANSCRIPT','ANALYSIS_BUSY','ANALYSIS_BACKOFF','ANALYSIS_OUTSIDE_HOURS','AUTO_NOT_CONFIGURED',
        'CAPTURE_PENDING','ACCEPTED_REPLIES_PENDING','TRANSCRIPT_SETTLING','AI_CONTROL_BLOCKED','DISPATCH_OUTSIDE_HOURS',
        'CUSTOMER_ORDER_PREVIOUS_DAY','CATALOG_UNRESOLVED','LOCATION_UNCONFIRMED','DRAFT_REVISION_CHANGED',
        'OWNER_CHOICE_CLI_ONLY','OWNER_CHOICE_EXPECTATION_INVALID','OWNER_CHOICE_SCOPE_CHANGED','OWNER_CHOICE_REPLAY_CHANGED',
        'OWNER_CHOICE_MODE_REQUIRED','OWNER_CHOICE_REVIEW_REQUIRED','CAPTURE_REVIEW_REQUIRED','CUSTOMER_RECAP_REPLAY',
        'BRANCH_UNRESOLVED','BRANCH_POLICY_UNAVAILABLE'];
    $out['reason']=in_array($reason,$allowed,true)?$reason:'UNVERIFIED';
}
while(ob_get_level())ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'],['READY_TO_DISPATCH','DISPATCHED','ALREADY_DISPATCHED'],true)?0:1);
