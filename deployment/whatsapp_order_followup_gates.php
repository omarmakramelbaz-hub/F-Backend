<?php
// Read-only LOCATION candidate diagnostics. Branch, catalog and location eligibility
// remain unevaluated: this helper never calls the automatic planner or sends a reply.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
$out=['diagnostic'=>'READ_ONLY_ORDER_FOLLOWUP_GATES','writes'=>0,'ai_calls'=>0,
    'candidate_kind'=>'LOCATION_READ_ONLY_CANDIDATE','planner_executed'=>false,
    'eligibility'=>'NOT_EVALUATED_BRANCH_LOCATION_CATALOG_DEPENDENCIES',
    'runtime_bindings'=>[
        'workflow'=>['constructor_ready'=>false,'runtime_class_matches'=>false],
        'followup_service'=>['constructor_ready'=>false,'runtime_class_matches'=>false],
        'reply_service'=>['constructor_ready'=>false,'runtime_class_matches'=>false],
    ]];
$stage='ARGUMENTS';
function waFollowupGateEnum($value,array $allowed,string $fallback='UNKNOWN'): string
{ return is_string($value)&&in_array($value,$allowed,true)?$value:$fallback; }
function waFollowupGateTime($value,bool $iso=false): ?string
{
    if (!is_string($value)||!preg_match($iso?'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/'
        :'/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',$value)) return null;
    $format=$iso?'!Y-m-d\TH:i:s\Z':'!Y-m-d H:i:s';
    $date=DateTimeImmutable::createFromFormat($format,$value,new DateTimeZone('UTC'));
    return $date&&$date->format(substr($format,1))===$value?$value:null;
}
function waFollowupGateInvoke($target,string $method,...$args)
{
    $allowed=['snapshot','freshFollowupIntent','freshAutomaticEvidence','activationInstant','automaticActor',
        'confirmedCustomerHandoff','confirmedMediaQuestionHandoff','confirmedOrderQuestionHandoff','positiveApproval','validPlan','unaskedFields'];
    if (!in_array($method,$allowed,true)) throw new RuntimeException();
    $reflection=new ReflectionMethod($target,$method); $reflection->setAccessible(true);
    return $reflection->invoke(is_object($target)?$target:null,...$args);
}
function waFollowupGateInteger($value,int $maximum=PHP_INT_MAX): ?int
{
    $value=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$maximum]]);
    return is_int($value)?$value:null;
}
try {
    if (count($argv)!==5||$argv[1]!=='--conversation'||$argv[3]!=='--draft'
        ||!preg_match('/\A[1-9][0-9]{0,9}\z/',$argv[2])||!preg_match('/\A[1-9][0-9]{0,9}\z/',$argv[4])) throw new RuntimeException();
    $cid=(int)$argv[2]; $did=(int)$argv[4];
    $stage='OWNER'; $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html')) throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'WhatsAppOrderAiProvider'=>'50dba4e16e2bec8811b6f37c3a6becf428d61a52',
        'WhatsAppReplyService'=>'f0373c39d4a00d3ae67366c34949712adfd51858',
        'WhatsAppOrderWorkflow'=>'e2b558a5de68080b85b12189e73239190aace507',
        'WhatsAppOrderFollowupService'=>'919e1e1407792cfa0caefd868e1b3c79284e8a3c',
        'WhatsAppInboxAccess'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'WhatsAppInboxMessage'=>'bedd91db5266d83bdce84a04836e988518891728',
    ];
    foreach ($pins as $name=>$pin) {
        $path=getcwd().'/app/Services/Dashboard/'.$name.'.php'; $stat=lstat($path);
        if (!is_array($stat)||($stat['mode']&0170000)!==0100000||($stat['mode']&0022)!==0
            ||$stat['uid']!==$owner['uid']||realpath($path)!==$path||$stat['size']>262144) throw new RuntimeException();
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin) throw new RuntimeException();
    }
    $stage='BOOTSTRAP'; require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach ($pins as $name=>$pin) {
        $class='App\\Services\\Dashboard\\'.$name;
        if ((new ReflectionClass($class))->getFileName()!==getcwd().'/app/Services/Dashboard/'.$name.'.php') throw new RuntimeException();
    }
    $db=\Illuminate\Support\Facades\DB::class;
    $crypt=\Illuminate\Support\Facades\Crypt::class;
    $access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    $extraction=\App\Support\WhatsAppOrderExtraction::class;
    $serviceClass=\App\Services\Dashboard\WhatsAppOrderFollowupService::class;
    $runtime=[];
    foreach (['workflow'=>\App\Services\Dashboard\WhatsAppOrderWorkflow::class,
        'followup_service'=>$serviceClass,'reply_service'=>\App\Services\Dashboard\WhatsAppReplyService::class] as $key=>$class) {
        $stage='RUNTIME_'.strtoupper($key);
        $runtime[$key]=app($class);
        $out['runtime_bindings'][$key]['constructor_ready']=true;
        $out['runtime_bindings'][$key]['runtime_class_matches']=is_object($runtime[$key])&&get_class($runtime[$key])===$class;
        if (!$out['runtime_bindings'][$key]['runtime_class_matches']) throw new RuntimeException();
    }
    $workflow=$runtime['workflow']; $service=$runtime['followup_service']; $replies=$runtime['reply_service'];
    $stage='DRAFT';
    $draft=$db::table('whatsapp_order_drafts as d')->join('whatsapp_inbox_conversations as c','c.id','=','d.conversation_id')
        ->where('d.id',$did)->where('d.conversation_id',$cid)->where('c.waba_id',$access::WABA_ID)->where('c.phone_number_id',$access::PHONE_ID)
        ->select('d.id','d.conversation_id','d.status','d.reason','d.evidence_floor','d.evidence_ceiling','d.evidence_hash','d.ticket_id')
        ->selectRaw('CASE WHEN LENGTH(d.extraction)<=262144 THEN d.extraction ELSE NULL END AS extraction')->first();
    if (!$draft||!is_string($draft->extraction)) throw new RuntimeException();
    $stage='DRAFT_DECODE'; $plain=$crypt::decryptString($draft->extraction);
    if (strlen($plain)>131072) throw new RuntimeException();
    $data=json_decode($plain,true,32,JSON_THROW_ON_ERROR); if (!is_array($data)) throw new RuntimeException();
    $out+=['conversation_id'=>$cid,'draft_id'=>$did,
        'draft_status'=>waFollowupGateEnum($draft->status,['REVIEW','READY','NONE','CANCELLED','DISPATCHED']),
        'draft_reason'=>waFollowupGateEnum($draft->reason,['CONTEXT_INCOMPLETE','AI_UNAVAILABLE','INVALID_EXTRACTION','STALE_TRANSCRIPT',
            'NO_ORDER','CUSTOMER_CANCELLED','REVIEW_REQUIRED','PICKUP_REQUIRES_REVIEW','BRANCH_UNRESOLVED','CATALOG_UNRESOLVED',
            'LOCATION_UNCONFIRMED','PRICE_REQUIRES_REVIEW','CUSTOMER_CHANGE','BRANCH_CLOSED','BRANCH_POLICY_UNAVAILABLE',
            'AUTO_NOT_CONFIGURED','PROCESS_FAILED','CAPTURE_REVIEW_REQUIRED','RECOVERED_HISTORY_REVIEW']),
        'ticket_present'=>$draft->ticket_id!==null];
    $stage='SNAPSHOT';
    $snapshot=waFollowupGateInvoke($workflow,'snapshot',$cid,(int)$draft->evidence_floor,(int)$draft->evidence_ceiling,false);
    $transcript=$snapshot['transcript'];
    if (!is_array($transcript)||count($transcript)>60||!is_array($snapshot['keys']??null)||!is_string($snapshot['hash']??null)) throw new RuntimeException();
    $newer=$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id','>',$draft->evidence_ceiling)->exists();
    $checked=$extraction::validateDetailed($data,$transcript);
    $out+=['snapshot_complete'=>($snapshot['complete']??false)===true,
        'snapshot_current_at_read'=>is_string($draft->evidence_hash)&&hash_equals($draft->evidence_hash,$snapshot['hash'])&&!$newer,
        'valid'=>($checked['ok']??false)===true,
        'raw_decision'=>waFollowupGateEnum($data['decision']??null,['NONE','DRAFT','CONFIRMED','CANCELLED']),
        'normalized_checked_decision'=>waFollowupGateEnum($checked['data']['decision']??null,['NONE','DRAFT','CONFIRMED','CANCELLED']),
        'issues'=>array_values(array_unique(array_intersect($extraction::ISSUES,is_array($data['issues']??null)?$data['issues']:[]))),
        'native_pins_count'=>count(array_filter($transcript,static fn($r)=>($r['speaker']??null)==='customer'&&($r['location']??null)!==null))];
    $citedPin=$data['evidence']['location_id']??null;
    $out['native_pin_cited']=is_string($citedPin)&&count(array_filter($transcript,
        static fn($r)=>($r['id']??null)===$citedPin&&($r['speaker']??null)==='customer'&&($r['location']??null)!==null))===1;
    $episode=\App\Services\Dashboard\WhatsAppOrderConversationEvidence::episodeTranscript($transcript);
    $latest=null; $latestPosition=-1; $final=-1;
    foreach ($episode as $position=>$message) {
        if (($message['speaker']??null)==='customer') { $latest=$message; $latestPosition=$position; }
        if (($message['speaker']??null)==='business'&&in_array($message['id'],$data['evidence']['confirmation_ids']??[],true)) $final=$position;
    }
    $postFinalCounts=$final<0?'NOT_APPLICABLE':['total'=>0,'null_text'=>0,'whole_positive_cited'=>0];
    if (is_array($postFinalCounts)) foreach ($episode as $position=>$message) {
        if ($position<=$final||($message['speaker']??null)!=='customer') continue;
        $postFinalCounts['total']++;
        if (($message['text']??null)===null) $postFinalCounts['null_text']++;
        if (in_array($message['id'],$data['evidence']['customer_acceptance_ids']??[],true)
            &&waFollowupGateInvoke($serviceClass,'positiveApproval',$message['text']??null)===true) $postFinalCounts['whole_positive_cited']++;
    }
    $native=null;
    if ($latest!==null&&is_string($snapshot['keys'][$latest['id']]??null)) {
        $native=$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)
            ->where('message_key',$snapshot['keys'][$latest['id']])->where('direction','inbound')
            ->select('id','conversation_id','message_key','type','direction','source','sent_at')
            ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
    }
    $activation=waFollowupGateInvoke($workflow,'activationInstant');
    $notBefore=$activation===null?null:max($activation,waFollowupGateTime($snapshot['recovered_at']??null)??$activation);
    $intent=($out['valid']&&$notBefore!==null)?waFollowupGateInvoke($workflow,'freshFollowupIntent',$data,$episode,$notBefore):null;
    $freshEvidence=$out['valid']&&$notBefore!==null?waFollowupGateInvoke($workflow,'freshAutomaticEvidence',$data,$transcript,$notBefore):false;
    $latestText=is_string($latest['text']??null)?$latest['text']:'';
    $words=preg_split('/\s+/u',trim($latestText),-1,PREG_SPLIT_NO_EMPTY);
    $imageAuthenticated='NOT_APPLICABLE'; $imageCaptionStatus='NOT_APPLICABLE'; $imageCaptionLength=null; $imageReference='NOT_APPLICABLE';
    if (($native->type??null)==='image') {
        $stage='IMAGE_METADATA'; $imageAuthenticated=false; $imageCaptionStatus='UNAUTHENTICATED'; $imageReference=false;
        try {
            $thread=$db::table('whatsapp_inbox_conversations')->where('id',$cid)
                ->where('waba_id',$access::WABA_ID)->where('phone_number_id',$access::PHONE_ID)
                ->first(['id','waba_id','phone_number_id','peer_hash']);
            if (!$thread) throw new RuntimeException();
            $projection=(new \App\Services\Dashboard\WhatsAppInboxMessage())->projected($thread,$native);
            $body=$projection['raw']['image']??null;
            if (!is_array($body)||$projection['direction']!=='inbound'||$projection['kind']!=='image') throw new RuntimeException();
            $imageAuthenticated=true;
            $caption=$body['caption']??null;
            $imageCaptionStatus=!array_key_exists('caption',$body)?'ABSENT':($caption===null?'NULL':($caption===''?'EMPTY':'TEXT'));
            $imageCaptionLength=is_string($caption)?min(16000,mb_strlen($caption,'UTF-8')):null;
            $imageReference=is_string($body['id']??null)&&preg_match('/\A[^\x00-\x20\x7f]{1,512}\z/',$body['id'])===1;
            unset($projection,$body,$caption);
        } catch (Throwable $ignore) {}
    }
    $out+=['activation_valid'=>$activation!==null,'fresh_intent'=>$intent!==null,'fresh_automatic_evidence'=>$freshEvidence===true,
        'latest_customer_present'=>$latest!==null,'latest_customer_id'=>$native?(int)$native->id:null,
        'latest_customer_fresh'=>$latest!==null&&$notBefore!==null&&is_string($latest['sent_at']??null)&&$latest['sent_at']>=$notBefore,
        'latest_native_type'=>waFollowupGateEnum($native->type??null,['text','location','order','audio','image','video','document','unsupported']),
        'latest_image_authenticated'=>$imageAuthenticated,'latest_image_caption_status'=>$imageCaptionStatus,
        'latest_image_caption_length'=>$imageCaptionLength,'latest_image_reference_valid'=>$imageReference,
        'latest_text_present'=>trim($latestText)!=='','latest_text_length'=>min(16000,mb_strlen($latestText,'UTF-8')),
        'latest_text_word_count'=>is_array($words)?min(8000,count($words)):0,
        'approval_keyword_present'=>preg_match('/(?<![\p{L}\p{N}])(?:تمام|نعم|موافق|أوافق|اوافق|حاضر|اوكي|أوكي|ماشي|ok|okay|yes)(?![\p{L}\p{N}])/iu',$latestText)===1,
        'question_or_quote_present'=>preg_match('/[?؟"«»“”]/u',$latestText)===1,
        'change_or_cancel_keyword_present'=>preg_match('/(?:إلغ|الغ|لغي|مش\s+(?:عايز|عاوز|موافق)|لا\s+(?:أريد|اريد|ترسل)|(?:غير|غيّر|بدل|بدّل)\s+(?:الطلب|الأوردر|الاوردر|البيانات|العنوان)|cancel|not\s+want|change\s+the\s+order)/iu',$latestText)===1,
        'turn_hint_scope'=>'DIAGNOSTIC_ONLY_NOT_HANDOFF_AUTHORITY',
        'latest_customer_is_cited_request'=>$latest!==null&&in_array($latest['id'],$data['evidence']['request_ids']??[],true),
        'latest_customer_is_cited_acceptance'=>$latest!==null&&in_array($latest['id'],$data['evidence']['customer_acceptance_ids']??[],true),
        'latest_positive_approval'=>waFollowupGateInvoke($serviceClass,'positiveApproval',$latest['text']??null)===true,
        'latest_after_final_cited_business'=>$latest!==null&&$final>=0&&$latestPosition>$final,
        'post_final_customer_counts'=>$postFinalCounts,
        'fulfillment'=>waFollowupGateEnum(\App\Services\Dashboard\WhatsAppOrderAiProvider::followupFulfillment($data,$transcript),['DELIVERY','PICKUP','UNKNOWN']),
        'candidate_plan_available'=>false,'confirmed_customer_handoff'=>'NOT_APPLICABLE','media_question_handoff'=>'NOT_APPLICABLE',
        'general_question_handoff'=>'NOT_APPLICABLE','handoff_allowed'=>'NOT_APPLICABLE','approval_satisfied'=>'NOT_APPLICABLE',
        'approval_scope'=>'APPROVAL_GUARD_ONLY_NOT_ERP_ELIGIBILITY',
        'valid_plan'=>'NOT_APPLICABLE','unasked_fields'=>'NOT_APPLICABLE','render_ready'=>'NOT_APPLICABLE','valid_marker'=>'NOT_APPLICABLE',
        'automatic_actor_available'=>false,'sender_state'=>['can_reply'=>null,'reason'=>'NOT_APPLICABLE','latest_inbound_id'=>null,'window_expires_at'=>null],
        'tombstone_state'=>'NOT_APPLICABLE','tombstone_count'=>null];
    $plan=null;
    if ($out['valid']&&$snapshot['complete']===true&&$intent!==null&&$native
        &&is_string($snapshot['keys'][$intent]??null)&&is_string($draft->evidence_hash)) {
        $plan=['draft_id'=>$did,'conversation_id'=>$cid,'evidence_floor'=>(int)$draft->evidence_floor,
            'evidence_ceiling'=>(int)$draft->evidence_ceiling,'evidence_hash'=>$draft->evidence_hash,
            'latest_customer_id'=>(int)$native->id,'latest_customer_key'=>$native->message_key,'episode_customer_key'=>$snapshot['keys'][$intent],
            'fields'=>['LOCATION'],'data'=>$data,'transcript'=>$transcript];
        $out['candidate_plan_available']=true;
        $stage='CANDIDATE_GATES';
        $out['valid_plan']=waFollowupGateInvoke($serviceClass,'validPlan',$plan)===true;
        $out['confirmed_customer_handoff']=waFollowupGateInvoke($service,'confirmedCustomerHandoff',$plan)===true;
        try { $out['media_question_handoff']=waFollowupGateInvoke($service,'confirmedMediaQuestionHandoff',$plan)===true; }
        catch (Throwable $ignore) { $out['media_question_handoff']=false; }
        try { $out['general_question_handoff']=waFollowupGateInvoke($service,'confirmedOrderQuestionHandoff',$plan)===true; }
        catch (Throwable $ignore) { $out['general_question_handoff']=false; }
        $out['handoff_allowed']=$service->handoffAllowed($plan);
        try { $out['approval_satisfied']=$service->approvalSatisfied($cid,$data,$snapshot,false)===true; }
        catch (Throwable $ignore) { $out['approval_satisfied']=false; }
        $fields=waFollowupGateInvoke($service,'unaskedFields',$plan);
        if (!is_array($fields)||array_diff($fields,['LOCATION'])) throw new RuntimeException();
        $out['unasked_fields']=$fields;
        $uuid=$serviceClass::requestUuid($plan,$crypt::getKey());
        $marker=['version'=>'whatsapp-order-followup-v1','kind'=>'question','fields'=>['LOCATION'],
            'latest_customer_id'=>$plan['latest_customer_id'],'latest_customer_key'=>$plan['latest_customer_key'],
            'episode_customer_key'=>$plan['episode_customer_key'],'proposal_hash'=>null];
        $out['valid_marker']=$serviceClass::validMarker($marker,$cid,$uuid,$plan['latest_customer_id']);
        $text=$serviceClass::question(['LOCATION'],'SHORT',$data,$transcript);
        $out['render_ready']=is_string($text)&&$text!==''&&mb_strlen($text,'UTF-8')<=4096; unset($text);
    }
    $stage='ACTOR'; $actor=null;
    try { $actor=waFollowupGateInvoke($workflow,'automaticActor'); } catch (Throwable $ignore) {}
    $out['automatic_actor_available']=is_object($actor)&&is_numeric($actor->id??null)&&(int)$actor->id>0;
    if ($out['automatic_actor_available']) {
        $stage='SENDER_STATE'; $state=$replies->state($actor,$cid);
        $reasons=['REPLIES_UNAVAILABLE','NO_INBOUND','RECIPIENT_UNAVAILABLE','WINDOW_CLOSED','UNKNOWN_PENDING','STALE_INBOUND'];
        $out['sender_state']=['can_reply'=>($state['can_reply']??false)===true,
            'reason'=>($state['reason']??null)===null?null:waFollowupGateEnum($state['reason'],$reasons),
            'latest_inbound_id'=>is_int($state['latest_inbound_id']??null)&&$state['latest_inbound_id']>0?$state['latest_inbound_id']:null,
            'window_expires_at'=>waFollowupGateTime($state['window_expires_at']??null,true)];
        if ($plan!==null) {
            $stage='TOMBSTONE'; $prior=$replies->orderFollowupState($actor,$cid,$uuid,$plan['latest_customer_id']);
            $out['tombstone_state']=$prior===null?'NONE':waFollowupGateEnum($prior['state']??null,['ACCEPTED','FAILED','UNKNOWN']);
            $out['tombstone_count']=$prior===null?0:1;
        }
    }
    $stage='SCAN'; $scan=$db::table('whatsapp_order_scans')->where('conversation_id',$cid)
        ->first(['attempts','claimed_ceiling','analyzed_ceiling','auto_floor','claim_nonce','next_attempt_at','lease_until']);
    $actualCeiling=waFollowupGateInteger($db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->max('id'));
    $attempts=$scan===null?null:waFollowupGateInteger($scan->attempts,1000000);
    $claimed=waFollowupGateInteger($scan->claimed_ceiling??null);
    $autoFloor=waFollowupGateInteger($scan->auto_floor??null);
    $activationFloor=waFollowupGateInteger(config('whatsapp_orders.activation_message_id'));
    $now=now('UTC')->format('Y-m-d H:i:s');
    $lease=waFollowupGateTime($scan->lease_until??null); $next=waFollowupGateTime($scan->next_attempt_at??null);
    $noncePresent=is_string($scan->claim_nonce??null)&&$scan->claim_nonce!==''&&$scan->claim_nonce!=='0';
    $liveLease=$noncePresent&&$lease!==null&&$lease>=$now;
    $changed=$actualCeiling!==null&&$actualCeiling!==($claimed??0);
    $rebaseline=$scan===null||($scan->auto_floor??null)===null||($autoFloor!==null&&$activationFloor!==null&&$autoFloor<$activationFloor);
    $scanValuesValid=$scan===null||($attempts!==null&&$claimed!==null&&(($scan->auto_floor??null)===null||$autoFloor!==null)
        &&(($scan->lease_until??null)===null||$lease!==null)&&(($scan->next_attempt_at??null)===null||$next!==null));
    $limitAllows=$scanValuesValid&&$actualCeiling!==null&&($changed||($attempts??0)<3||$rebaseline);
    $backoffDue=($scan->next_attempt_at??null)===null||($next!==null&&$next<=$now);
    $out['scan']=['present'=>$scan!==null,'scan_values_valid'=>$scanValuesValid,'attempts'=>$attempts,'claimed_ceiling'=>$claimed,
        'analyzed_ceiling'=>waFollowupGateInteger($scan->analyzed_ceiling??null),'auto_floor'=>$autoFloor,
        'actual_message_ceiling'=>$actualCeiling,'ceiling_changed'=>$changed,'activation_rebaseline'=>$rebaseline,
        'claim_nonce_present'=>$noncePresent,'live_lease'=>$liveLease,'next_attempt_at'=>$next,'lease_until'=>$lease,
        'retry_limit_allows'=>$limitAllows,'backoff_due'=>$backoffDue,'retry_allowed'=>$limitAllows&&($backoffDue||$rebaseline)&&!$liveLease,
        'retry_scope'=>'COUNT_CEILING_BACKOFF_LEASE_ONLY'];
    $out['status']='READ_READY';
} catch (Throwable $ignore) { $out['status']='READ_FAILED'; $out['stage']=$stage; }
while (ob_get_level()) ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($out['status']==='READ_READY'?0:1);
