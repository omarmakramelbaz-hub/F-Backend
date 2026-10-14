<?php
// Observation only. This helper never projects, generates, sends, quotes or dispatches.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_META_AI_CONTROL_STATUS','writes'=>0,'ai_calls'=>0,'message_sends'=>0,'graph_gets'=>0];
$stage='ARGUMENTS';
function metaStatusCode($value,array$allowed,$fallback=null){return is_string($value)&&in_array($value,$allowed,true)?$value:$fallback;}
function metaStatusTime($value):?string{
    if(!is_string($value))return null;
    foreach(['Y-m-d H:i:s','Y-m-d\TH:i:s\Z','Y-m-d\TH:i:sP']as$format){
        $time=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));
        if($time&&$time->format($format)===$value&&substr($value,0,4)!=='0000')return$value;
    }return null;
}
function metaStatusInt($value):?int{$v=filter_var($value,FILTER_VALIDATE_INT);return$v!==false&&$v>=0?$v:null;}
function metaStatusBranch($value):?string{return is_string($value)&&preg_match('/\A(?:f|gs):[1-9][0-9]{0,18}\z/D',$value)?$value:null;}
function metaStatusCall($object,string$name,array$args=[]){$method=new ReflectionMethod($object,$name);$method->setAccessible(true);return$method->invokeArgs($object,$args);}
function metaStatusTicket(int$id,string$branch):array{
    $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
    $out=['status'=>'UNAVAILABLE'];
    if(!$schema::hasTable('pos_service_tickets')||!$schema::hasColumns('pos_service_tickets',
        ['id','branch','channel','status','payment_status','paid_order_id','bill_issued_at','last_kitchen_id']))return$out;
    $ticket=$db::table('pos_service_tickets')->where('id',$id)->where('branch',$branch)->where('channel','phone')
        ->first(['id','branch','status','payment_status','paid_order_id','bill_issued_at','last_kitchen_id']);
    if(!$ticket)return['status'=>'TICKET_SCOPE_MISMATCH'];
    $out=['status'=>'READ_READY','id'=>(int)$ticket->id,'branch'=>$branch,
        'ticket_status'=>metaStatusCode($ticket->status,['new','preparing','out_for_delivery','finished','cancelled'],'OTHER'),
        'payment_status'=>metaStatusCode($ticket->payment_status,['unpaid','paid'],'OTHER'),
        'paid_order_present'=>$ticket->paid_order_id!==null,'bill_issued'=>$ticket->bill_issued_at!==null,
        'kitchen_ticket_present'=>false,'print_jobs'=>[],'print_jobs_truncated'=>false,
        'print_scope'=>'QUEUE_OR_INVOCATION_STATUS_NOT_PHYSICAL_OUTPUT_PROOF'];
    $kid=metaStatusInt($ticket->last_kitchen_id);
    if($kid!==null&&$kid>0&&$schema::hasTable('pos_service_kitchen_tickets')&&$schema::hasColumns('pos_service_kitchen_tickets',['id','branch','ticket_id']))
        $out['kitchen_ticket_present']=$db::table('pos_service_kitchen_tickets')->where('id',$kid)->where('ticket_id',$id)->where('branch',$branch)->exists();
    if($schema::hasTable('pos_branch_print_jobs')&&$schema::hasColumns('pos_branch_print_jobs',['id','branch','ticket_id','kitchen_id','status'])){
        $rows=$db::table('pos_branch_print_jobs')->where('branch',$branch)->where('ticket_id',$id)->orderByDesc('id')->limit(4)
            ->get(['id','kitchen_id','status']);$out['print_jobs_truncated']=count($rows)>3;
        foreach($rows->take(3)as$row)$out['print_jobs'][]=['id'=>(int)$row->id,'kitchen_id'=>(int)$row->kitchen_id,
            'matches_latest_kitchen'=>$kid!==null&&(int)$row->kitchen_id===$kid,
            'state'=>metaStatusCode($row->status,['pending','claimed','invoked','failed'],'OTHER')];
    }
    return$out;
}
try{
    $options=['agent'=>false,'channel'=>null,'conversation'=>null];
    for($i=1;$i<count($argv);$i++){
        if($argv[$i]==='--agent-status'&&!$options['agent']){$options['agent']=true;continue;}
        if($argv[$i]==='--channel'&&$options['channel']===null&&isset($argv[$i+1])&&in_array($argv[$i+1],['whatsapp','messenger','instagram'],true)){$options['channel']=$argv[++$i];continue;}
        if($argv[$i]==='--conversation'&&$options['conversation']===null&&isset($argv[$i+1])&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$argv[$i+1])){$options['conversation']=(int)$argv[++$i];continue;}
        throw new RuntimeException();
    }
    if($options['conversation']!==null&&$options['channel']===null)throw new RuntimeException();
    $stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/MetaAiSchedule.php'=>'f05614f2b9f7db4c3596bac5a78d0e2de5e538df',
        'app/Services/Dashboard/MetaBusinessAgent.php'=>'4b2ad29c21ea092af94ad3fa1bace90ad4dd4672',
        'app/Services/Dashboard/MetaAiControl.php'=>'86639b7cbac148006d9f93b2589412932d2c03e2',
        'app/Support/WhatsAppOrderDialogue.php'=>'33253285d24ba7110f58744cad9c3a87e1bef06a',
        'app/Support/WhatsAppOrderExtraction.php'=>'1b518728c5f63db4ba1ef84149fb0aafec02a98a',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'bfe69bb72cc586c68823463fd3f1885915774d02',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php'=>'b7b2797f1aad6747d1e4a34e546e4f6421c99a4c',
        'app/Services/Dashboard/WhatsAppOrderFollowupService.php'=>'be38be4938cf0fb6725baf38ebd4fcb69e6d692f',
        'app/Services/Dashboard/MetaOrderNativeEvidence.php'=>'f11710e1439867ba63571a690526496684120ef5',
        'app/Services/Dashboard/MetaOrderFollowupService.php'=>'d14a3c6e84624ee5b2d20997b7f11159b9101720',
        'app/Services/Dashboard/MetaMediaService.php'=>'cfde72fdeb12f308e15daa81ec0ae7ce196aef9e',
        'app/Services/Dashboard/MetaReplyService.php'=>'c4226df516aa5f8b59999a8979f9f2ab43cf0e28',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'4daf361e4c17eb5eb8bec7249dfb8093df73e433',
        'app/Services/Dashboard/MetaOrderWorkflow.php'=>'0bc341a40717428feb47a4d6f42232e7b82afa40',
        'app/Services/Dashboard/MetaInboxConsumer.php'=>'3b7e9cb9abfabc85c0b78199d0bf9d2565424f42',
        'app/Http/Controllers/Dashboard/MetaAiControlController.php'=>'3cb87c03aaabdd7b1bb01f047caf201649c8db6f',
        'app/Console/Commands/ProcessMetaAiMessages.php'=>'9a953aceba5bd0c6cae0d4da60c1b23e42c9c0e7',
        'database/migrations/2026_10_15_000001_create_meta_ai_controls.php'=>'3c6c7ef4d5fbf437f49cc7c2bb94ce82595b77ae',
        'resources/views/admin/meta/ai_controls.blade.php'=>'cbc83afedd12f4bd0276b67d287e93aea1b42d46',
        'public/js/dashboard-meta-ai-controls.js'=>'c3f33b423b6228ca679f5b7ec292213a22c7bd5b',
        'public/css/dashboard-meta-ai-controls.css'=>'293c1db7c3a4c3dc2720fd9216c450ac172c37e8',
        'public/css/dashboard-meta-inbox-viewport.css'=>'1d3c4c9360619c6e3d7ab6a23d4188de36e96732',
        'routes/meta_ai_controls.php'=>'8a89374a33002b88f7ed593d6aab5817aa3cf2a4',
        'resources/views/admin/meta/channels.blade.php'=>'c922fd431ca3df5b12f6aeef05c0885bc0da3049',
        'routes/meta_inbox.php'=>'2b5f992b8392be450a032d71d5a625a8aeed822e',
        'app/Services/Dashboard/WhatsAppReplyService.php'=>'f80378c7b61cc42c747bdceefd71b79c566de553',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/MetaInboxAccess.php'=>'7faaba6645308c1997bae8182fb4b26dbc5e3b9a',
        'app/Support/MetaMessagingProtocol.php'=>'d5c6cb4dfeaa1d6c8467025338d7d92d9ce5b0ed',
        'app/Services/Dashboard/PosServiceTicket.php'=>'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
    ];
    foreach($pins as$path=>$pin){
        $out['source_guard_file']=$path;
        if(is_link($path)||!is_file($path)||filesize($path)>1048576)throw new RuntimeException();
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin){$out['source_guard_file']=$path;throw new RuntimeException();}
    }
    unset($out['source_guard_file']);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
    $stage='CONTROL';$control=app(\App\Services\Dashboard\MetaAiControl::class);$state=$control->state();
    $statusCodes=['ACTIVE','DISABLED','SCHEDULED_OFF','OUTSIDE_SCHEDULE','UNAVAILABLE','CHANNEL_DISABLED','INVALID_CHANNEL','STATE_CHANGED',
        'EXTERNAL_AGENT_PAUSE_REQUIRED','ACTIVATION_REQUIRED','ACCOUNT_UNAVAILABLE','CAPTURE_UNAVAILABLE','ACTOR_UNAVAILABLE','BEFORE_ACTIVATION','DISPATCH_OUTSIDE_HOURS'];
    $policy=is_array($state['policy']??null)?\App\Support\MetaAiSchedule::validate($state['policy']):null;
    $out['control']=['available'=>($state['available']??null)===true,'effective'=>($state['effective']??null)===true,
        'status'=>metaStatusCode($state['status']??null,$statusCodes,'UNAVAILABLE'),'revision'=>metaStatusInt($state['revision']??null),
        'policy'=>$policy,'local_time'=>metaStatusTime($state['local_time']??null),
        'handoff_method'=>metaStatusCode($state['handoff_method']??null,['OWNER_ATTESTED','VERIFIED_WHATSAPP_API']),
        'handoff_required'=>($state['handoff_required']??null)===true,'updated_at_utc'=>metaStatusTime($state['updated_at_utc']??null)];
    $dispatch=is_array($state['dispatch_state']??null)?$state['dispatch_state']:[];
    $out['control']['dispatch_state']=['effective'=>($dispatch['effective']??null)===true,
        'status'=>metaStatusCode($dispatch['status']??null,['ACTIVE','DISABLED','SCHEDULED_OFF'],'UNAVAILABLE'),
        'local_time'=>metaStatusTime($dispatch['local_time']??null)];
    $out['channels']=[];$out['conversations']=[];$revisions=[];
    foreach(['whatsapp','messenger','instagram']as$channel){
        $gate=$control->gate($channel);$revisions[]=metaStatusInt($gate['revision']??null);
        $out['channels'][]=['channel'=>$channel,'effective'=>($gate['effective']??null)===true,
            'reason'=>metaStatusCode($gate['reason']??null,$statusCodes),'revision'=>metaStatusInt($gate['revision']??null)];
    }
    $stage='READINESS';$keyReady=static function($value):bool{return is_string($value)&&strlen($value)>=16&&strlen($value)<=4096&&!preg_match('/[\s\x00-\x1f\x7f]/',$value);};
    $out['readiness']=['curl_available'=>function_exists('curl_init'),
        'whatsapp_ai_key_present'=>$keyReady(config('whatsapp_orders.api_key')),'whatsapp_model_present'=>is_string(config('whatsapp_orders.model'))&&trim(config('whatsapp_orders.model'))!=='',
        'meta_ai_key_present'=>$keyReady(config('meta_orders.api_key')),'meta_model_present'=>is_string(config('meta_orders.model'))&&trim(config('meta_orders.model'))!=='',
        'whatsapp_replies_enabled'=>config('whatsapp_replies.enabled')===true,'whatsapp_reply_token_present'=>$keyReady(config('whatsapp_replies.access_token')),
        'messenger_accounts'=>0,'messenger_accounts_with_token'=>0,'instagram_accounts'=>0,'instagram_accounts_with_token'=>0];
    foreach(\App\Services\Dashboard\MetaInboxAccess::accounts()as$account){$channel=$account['channel'];
        $out['readiness'][$channel.'_accounts']++;if($keyReady($account['access_token']??null))$out['readiness'][$channel.'_accounts_with_token']++;}
    foreach(['whatsapp','messenger','instagram']as$channel){
        if($options['channel']!==null&&$options['channel']!==$channel)continue;
        $prefix=$channel==='whatsapp'?'whatsapp':'meta';$table=$prefix.'_inbox_conversations';
        $stage='CONVERSATIONS';if(!$schema::hasTable($table))continue;
        $q=$db::table($table);
        if($prefix==='whatsapp')$q->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID);
        else{$q=\App\Services\Dashboard\MetaInboxAccess::scope($q);$q->where('waba_id',$channel);}
        if($options['conversation']!==null)$q->where('id',$options['conversation']);
        $threads=$q->orderByDesc('last_message_at')->orderByDesc('id')->limit(2)->get(['id','waba_id','phone_number_id','peer_hash','last_message_at']);
        foreach($threads as$thread){
            $cid=(int)$thread->id;$item=['channel'=>$channel,'conversation_id'=>$cid,'last_message_at'=>metaStatusTime($thread->last_message_at),
                'drafts'=>[],'replies'=>[],'reply_scope'=>'SENDER_WINDOW_AND_UNRESOLVED_ONLY_NOT_BROWSER_PERMISSION'];
            $stage='REPLY_WINDOW';
            try{
                $service=$prefix==='whatsapp'?new \App\Services\Dashboard\WhatsAppReplyService():new \App\Services\Dashboard\MetaReplyService();
                $sender=metaStatusCall($service,'snapshot',[$thread]);
                $configured=metaStatusCall($service,'configured',$prefix==='whatsapp'?[]:[\App\Services\Dashboard\MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id)]);
                $unresolved=metaStatusCall($service,'unresolved',[$cid]);
                $reason=!$configured?'REPLIES_UNAVAILABLE':($unresolved?'UNKNOWN_PENDING':($sender['reason']??null));
                $item['sender']=['available'=>$configured===true,'recipient_window_ready'=>$reason===null,
                    'reason'=>metaStatusCode($reason,['REPLIES_UNAVAILABLE','UNKNOWN_PENDING','NO_INBOUND','RECIPIENT_UNAVAILABLE','WINDOW_CLOSED']),
                    'latest_inbound_id'=>metaStatusInt($sender['inbound_id']??null),'window_expires_at'=>metaStatusTime($sender['expires']??null)];
            }catch(Throwable$ignore){$item['sender']=['available'=>false,'recipient_window_ready'=>false,'reason'=>'READ_UNAVAILABLE'];}
            $stage='DRAFTS';$draftTable=$prefix.'_order_drafts';
            if($schema::hasTable($draftTable))foreach($db::table($draftTable)->where('conversation_id',$cid)->orderByDesc('id')->limit(2)
                ->get(['id','status','reason','ticket_id','assigned_branch','updated_at'])as$row){
                $draft=['id'=>(int)$row->id,'status'=>metaStatusCode($row->status,['REVIEW','READY','NONE','CANCELLED','DISPATCHED'],'OTHER'),
                    'reason'=>metaStatusCode($row->reason,['REVIEW_REQUIRED','LOCATION_UNCONFIRMED','BRANCH_UNRESOLVED','INVALID_EXTRACTION','AI_UNAVAILABLE',
                        'CONTEXT_INCOMPLETE','PICKUP_REQUIRES_REVIEW','CAPTURE_PENDING','ACCEPTED_REPLIES_PENDING','DIALOGUE_INCOMPLETE','HUMAN_HANDOFF','DISPATCH_OUTSIDE_HOURS']),
                    'ticket_id'=>metaStatusInt($row->ticket_id),'branch'=>metaStatusBranch($row->assigned_branch),'updated_at'=>metaStatusTime($row->updated_at)];
                if($draft['status']==='DISPATCHED'&&$draft['ticket_id']!==null&&$draft['ticket_id']>0&&$draft['branch']!==null)
                    $draft['erp']=metaStatusTicket($draft['ticket_id'],$draft['branch']);
                $item['drafts'][]=$draft;
            }
            $stage='RECEIPTS';$replyTable=$prefix.'_reply_requests';
            if($schema::hasTable($replyTable))foreach($db::table($replyTable)->where('conversation_id',$cid)->where('waba_id',$thread->waba_id)
                ->where('phone_number_id',$thread->phone_number_id)->orderByDesc('id')->limit(5)
                ->select('id','state','kind','inbound_message_id','reason','send_started_at','completed_at','updated_at')
                ->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit')->get()as$row){
                $reply=['id'=>(int)$row->id,'state'=>metaStatusCode($row->state,['CLAIMED','UNKNOWN','ACCEPTED','FAILED'],'OTHER'),
                    'kind'=>metaStatusCode($row->kind,['text','audio','image','video','document','reaction'],'OTHER'),
                    'inbound_message_id'=>metaStatusInt($row->inbound_message_id),'ownership'=>'UNREADABLE','fields'=>[],
                    'reason'=>metaStatusCode($row->reason,['DELIVERY_UNKNOWN','META_REJECTED','STALE_INBOUND','ACCESS_REVOKED','REPLIES_UNAVAILABLE',
                        'WINDOW_CLOSED','RECIPIENT_UNAVAILABLE','UNKNOWN_PENDING','MEDIA_UPLOAD_FAILED','INVALID_VOICE','INVALID_ATTACHMENT']),
                    'send_started'=>$row->send_started_at!==null,'completed_at'=>metaStatusTime($row->completed_at),'updated_at'=>metaStatusTime($row->updated_at)];
                try{
                    if(!is_string($row->audit))throw new RuntimeException();$plain=\Illuminate\Support\Facades\Crypt::decryptString($row->audit);
                    if(strlen($plain)>65536)throw new RuntimeException();$audit=json_decode($plain,true,32,JSON_THROW_ON_ERROR);if(!is_array($audit))throw new RuntimeException();
                    if(!array_key_exists('order_followup',$audit))$reply['ownership']='MANUAL';
                    else{
                        $marker=$audit['order_followup'];$klass=$prefix==='whatsapp'?\App\Services\Dashboard\WhatsAppOrderFollowupService::class:\App\Services\Dashboard\MetaOrderFollowupService::class;
                        if(!$klass::validMarker($marker,$cid,$audit['client_request_id']??'',(int)$row->inbound_message_id))throw new RuntimeException();
                        $reply['ownership']='AUTOMATIC';$reply['fields']=$marker['fields'];
                    }
                }catch(Throwable$ignore){$reply['ownership']='UNREADABLE';}
                $item['replies'][]=$reply;
            }
            $stage='SCAN';$scanTable=$prefix.'_order_scans';
            if($schema::hasTable($scanTable)){$scan=$db::table($scanTable)->where('conversation_id',$cid)->first([
                'analyzed_ceiling','claimed_ceiling','attempts','next_attempt_at','lease_until','updated_at']);
                $item['scan']=$scan?['analyzed_ceiling'=>metaStatusInt($scan->analyzed_ceiling),'claimed_ceiling'=>metaStatusInt($scan->claimed_ceiling),
                    'attempts'=>metaStatusInt($scan->attempts),'next_attempt_at'=>metaStatusTime($scan->next_attempt_at),
                    'lease_until'=>metaStatusTime($scan->lease_until),'updated_at'=>metaStatusTime($scan->updated_at)]:null;}
            $out['conversations'][]=$item;
        }
    }
    $stage='CAPTURE';$out['capture']=[];
    foreach(['whatsapp','meta']as$prefix){
        if(!$schema::hasTable($prefix.'_webhook_events')||!$schema::hasTable($prefix.'_inbox_ingestion_failures')){
            $out['capture'][$prefix]=['available'=>false];continue;}
        $out['capture'][$prefix]=['available'=>true,'scope'=>'ALL_CAPTURED_ACCOUNTS_UNQUARANTINED',
            'pending_raw_events'=>$db::table($prefix.'_webhook_events as e')->whereNull('e.processed_at')->whereNotExists(function($q)use($prefix){
                $q->selectRaw('1')->from($prefix.'_inbox_ingestion_failures as f')->whereColumn('f.event_id','e.id');})->count(),
            'quarantined_events'=>$db::table($prefix.'_inbox_ingestion_failures')->count()];
    }
    $out['worker']=['command'=>'meta:process-ai','runtime_observed'=>false,'scope'=>'READ_SCAN_METADATA_ONLY_NO_PROCESS_INVOCATION'];
    $out['selection']=['channel'=>$options['channel'],'conversation_id'=>$options['conversation'],'matched'=>count($out['conversations'])];
    if($options['agent']){
        $stage='EXTERNAL_AGENT_GET';$out['graph_gets']=1;
        $agent=(new \App\Services\Dashboard\MetaBusinessAgent())->inspectWhatsApp();
        $out['external_agent']=['readable'=>($agent['readable']??null)===true,
            'enabled'=>is_bool($agent['enabled']??null)?$agent['enabled']:null,
            'status'=>metaStatusCode($agent['status']??null,['ACTIVE','PAUSED','UNVERIFIED'],'UNVERIFIED'),
            'http_status'=>metaStatusInt($agent['http_status']??null),'scope'=>'CURRENT_WHATSAPP_SETTINGS_GET_ONLY'];
    }
    $stage='FINAL_CONTROL';$fresh=$control->state();
    $out['control_unchanged_during_read']=($fresh['revision']??null)===($state['revision']??null)
        &&($fresh['policy']??null)===($state['policy']??null)&&($fresh['updated_at_utc']??null)===($state['updated_at_utc']??null)
        &&count(array_unique($revisions,SORT_REGULAR))===1&&($revisions[0]??null)===metaStatusInt($state['revision']??null);
    $out['status']='READ_READY';
}catch(Throwable$ignore){$out['status']='READ_FAILED';$out['stage']=$stage;}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($out['status']==='READ_READY'?0:1);
