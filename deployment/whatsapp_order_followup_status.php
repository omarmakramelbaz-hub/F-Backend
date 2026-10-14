<?php
// Observation only: no reply generation, projection, analysis, quote or dispatch.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
$out=['diagnostic'=>'READ_ONLY_ORDER_FOLLOWUP_STATUS','writes'=>0,'ai_calls'=>0];
$stage='ARGUMENTS';
try {
    if (count($argv)!==3||$argv[1]!=='--conversation'||!preg_match('/\A[1-9][0-9]{0,9}\z/',$argv[2])) throw new RuntimeException();
    $cid=(int)$argv[2];
    $stage='OWNER'; $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html')) throw new RuntimeException();
    $stage='SOURCE_GUARD';
    foreach ([
        'WhatsAppOrderAiProvider'=>'50dba4e16e2bec8811b6f37c3a6becf428d61a52',
        'WhatsAppReplyService'=>'f0373c39d4a00d3ae67366c34949712adfd51858',
        'WhatsAppOrderFollowupService'=>'ca0e78765596f45263f19ffb626dd7452945f119',
        'WhatsAppOrderWorkflow'=>'e2b558a5de68080b85b12189e73239190aace507',
        'WhatsAppInboxAccess'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
    ] as $name=>$pin) {
        $bytes=file_get_contents('app/Services/Dashboard/'.$name.'.php');
        if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin) throw new RuntimeException();
    }
    $stage='BOOTSTRAP'; require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;
    $stage='CONVERSATION';
    $thread=$db::table('whatsapp_inbox_conversations')->where('id',$cid)
        ->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)
        ->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID)->first(['id']);
    if (!$thread) throw new RuntimeException();
    $out+=['conversation_id'=>$cid,'enabled'=>(bool)config('whatsapp_orders.enabled'),
        'mode'=>config('whatsapp_orders.mode')==='auto'?'auto':'review',
        'replies_enabled'=>(bool)config('whatsapp_replies.enabled'),
        'reply_token_present'=>is_string(config('whatsapp_replies.access_token'))&&trim(config('whatsapp_replies.access_token'))!=='',
        'drafts'=>[],'automatic_replies'=>[]];
    $stage='DRAFTS';
    foreach ($db::table('whatsapp_order_drafts')->where('conversation_id',$cid)->orderByDesc('id')->limit(3)
        ->get(['id','status','reason','ticket_id','assigned_branch','updated_at']) as $row) {
        $out['drafts'][]=['id'=>(int)$row->id,
            'status'=>in_array($row->status,['REVIEW','READY','NONE','CANCELLED','DISPATCHED'],true)?$row->status:'UNKNOWN',
            'reason'=>is_string($row->reason)&&preg_match('/\A[A-Z_]{1,64}\z/',$row->reason)?$row->reason:null,
            'ticket_id'=>$row->ticket_id===null?null:(int)$row->ticket_id,
            'branch'=>is_string($row->assigned_branch)&&preg_match('/\A[fvc]:[0-9]{1,10}\z/',$row->assigned_branch)?$row->assigned_branch:null,
            'updated_at'=>$row->updated_at];
    }
    $stage='REPLIES';
    foreach ($db::table('whatsapp_reply_requests')->where('conversation_id',$cid)
        ->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)
        ->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID)
        ->where('kind','text')->orderByDesc('id')->limit(20)
        ->select('id','state','inbound_message_id','reason','send_started_at','updated_at')
        ->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit')->get() as $row) {
        if (!is_string($row->audit)) throw new RuntimeException();
        $plain=\Illuminate\Support\Facades\Crypt::decryptString($row->audit);
        if (strlen($plain)>65536) throw new RuntimeException();
        $audit=json_decode($plain,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($audit)) throw new RuntimeException();
        $marker=$audit['order_followup']??null; if ($marker===null) continue;
        if (!\App\Services\Dashboard\WhatsAppOrderFollowupService::validMarker($marker,$cid,
            $audit['client_request_id']??'',(int)$row->inbound_message_id)) throw new RuntimeException();
        $out['automatic_replies'][]=['id'=>(int)$row->id,
            'state'=>in_array($row->state,['CLAIMED','UNKNOWN','ACCEPTED','FAILED'],true)?$row->state:'UNKNOWN',
            'kind'=>$marker['kind'],'fields'=>$marker['fields'],
            'inbound_message_id'=>(int)$row->inbound_message_id,
            'reason'=>is_string($row->reason)&&preg_match('/\A[A-Z_]{1,64}\z/',$row->reason)?$row->reason:null,
            'send_started'=>$row->send_started_at!==null,'updated_at'=>$row->updated_at];
    }
    $stage='CAPTURE';
    $out['pending_raw_events']=$db::table('whatsapp_webhook_events as e')->whereNull('e.processed_at')
        ->whereNotExists(function ($q) use ($db) {
            $q->select($db::raw('1'))->from('whatsapp_inbox_ingestion_failures as f')->whereColumn('f.event_id','e.id');
        })->count();
    $out['status']='READ_READY';
} catch (Throwable $ignore) { $out['status']='READ_FAILED'; $out['stage']=$stage; }
while (ob_get_level()) ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($out['status']==='READ_READY'?0:1);
