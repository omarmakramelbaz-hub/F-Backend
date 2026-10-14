<?php
// Observe the complete post-recap customer tail. No planner, writes, AI or transport.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
$out=['diagnostic'=>'READ_ONLY_ORDER_CUSTOMER_TAIL','writes'=>0,'ai_calls'=>0,'planner_executed'=>false,
    'hint_scope'=>'DIAGNOSTIC_ONLY_NOT_APPROVAL_OR_COVERAGE','customer_tail'=>[]];
$stage='ARGUMENTS';
function waTailEnum($value,array $allowed): string
{ return is_string($value)&&in_array($value,$allowed,true)?$value:'UNKNOWN'; }
function waTailTimeValid($value): bool
{
    if (!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$value)!==1) return false;
    $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $time!==false&&$time->format('Y-m-d H:i:s')===$value;
}
function waTailInvoke($target,string $method,...$args)
{
    if (!in_array($method,['snapshot','positiveApproval','literal','phoneLiteral'],true)) throw new RuntimeException();
    $reflection=new ReflectionMethod($target,$method); $reflection->setAccessible(true);
    return $reflection->invoke(is_object($target)?$target:null,...$args);
}
function waTailTextMetadata($text): array
{
    $text=is_string($text)?$text:'';
    $words=preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY);
    return ['text_present'=>trim($text)!=='','text_length'=>min(20000,mb_strlen($text,'UTF-8')),
        'text_word_count'=>is_array($words)?min(10000,count($words)):0,
        'approval_keyword_present'=>preg_match('/(?<![\p{L}\p{N}])(?:تمام|نعم|موافق|أوافق|اوافق|حاضر|اوكي|أوكي|ماشي|ok|okay|yes)(?![\p{L}\p{N}])/iu',$text)===1,
        'question_or_quote_present'=>preg_match('/[?؟"«»“”]/u',$text)===1,
        'whole_decline_or_stop'=>preg_match('/\A\s*(?:لا|لأ|كلا|no|nope|stop)[.!،]?\s*\z/iu',$text)===1,
        'cancel_keyword_present'=>preg_match('/(?:إلغ|الغ|لغي|مش\s+(?:عايز|عاوز|موافق)|لا\s+(?:أريد|اريد|ترسل)|cancel|not\s+want)/iu',$text)===1,
        'change_keyword_present'=>preg_match('/(?:(?:غير|غيّر|بدل|بدّل)\s+(?:الطلب|الأوردر|الاوردر|البيانات|العنوان)|change\s+the\s+order)/iu',$text)===1,
        'new_order_keyword_present'=>preg_match('/(?:(?:طلب|أوردر|اوردر)\s+(?:جديد|تاني|ثاني)|new\s+order)/iu',$text)===1,
        'probe_keyword_present'=>preg_match('/(?<![\p{L}\p{N}])(?:تجربة|تجربه|اختبار|test|probe|WA-20[0-9]{2})(?![\p{L}\p{N}])/iu',$text)===1];
}
try {
    if (count($argv)!==5||$argv[1]!=='--conversation'||$argv[3]!=='--draft'
        ||!preg_match('/\A[1-9][0-9]{0,9}\z/',$argv[2])||!preg_match('/\A[1-9][0-9]{0,9}\z/',$argv[4])) throw new RuntimeException();
    $cid=(int)$argv[2]; $did=(int)$argv[4];
    $stage='OWNER'; $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html')) throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'App\\Services\\Dashboard\\WhatsAppOrderAiProvider'=>'50dba4e16e2bec8811b6f37c3a6becf428d61a52',
        'App\\Services\\Dashboard\\WhatsAppReplyService'=>'f0373c39d4a00d3ae67366c34949712adfd51858',
        'App\\Services\\Dashboard\\WhatsAppOrderWorkflow'=>'e2b558a5de68080b85b12189e73239190aace507',
        'App\\Services\\Dashboard\\WhatsAppOrderFollowupService'=>'f8cbd5f86aeedf34d5281aec3d960ff31dc8b6eb',
        'App\\Services\\Dashboard\\WhatsAppInboxAccess'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'App\\Services\\Dashboard\\WhatsAppInboxMessage'=>'bedd91db5266d83bdce84a04836e988518891728',
        'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence'=>'1bd223861e3772e10658df2d555b4bb040243a4a',
        'App\\Support\\WhatsAppOrderExtraction'=>'56d2b9575da152de3eac4876fd93140ee7bc9e39',
    ];
    foreach ($pins as $class=>$pin) {
        $path=getcwd().'/'.str_replace('\\','/',lcfirst($class)).'.php'; $stat=lstat($path);
        if (!is_array($stat)||($stat['mode']&0170000)!==0100000||($stat['mode']&0022)!==0
            ||$stat['uid']!==$owner['uid']||realpath($path)!==$path||$stat['size']>262144) throw new RuntimeException();
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin) throw new RuntimeException();
    }
    $stage='BOOTSTRAP'; require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach ($pins as $class=>$pin) if ((new ReflectionClass($class))->getFileName()!==getcwd().'/'.str_replace('\\','/',lcfirst($class)).'.php') throw new RuntimeException();
    $db=\Illuminate\Support\Facades\DB::class; $crypt=\Illuminate\Support\Facades\Crypt::class;
    $access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    $evidence=\App\Services\Dashboard\WhatsAppOrderConversationEvidence::class;
    $service=\App\Services\Dashboard\WhatsAppOrderFollowupService::class;
    $stage='DRAFT';
    $draft=$db::table('whatsapp_order_drafts as d')->join('whatsapp_inbox_conversations as c','c.id','=','d.conversation_id')
        ->where('d.id',$did)->where('d.conversation_id',$cid)->where('c.waba_id',$access::WABA_ID)->where('c.phone_number_id',$access::PHONE_ID)
        ->select('d.evidence_floor','d.evidence_ceiling','d.evidence_hash','d.ticket_id')
        ->selectRaw('CASE WHEN LENGTH(d.extraction)<=262144 THEN d.extraction ELSE NULL END AS extraction')->first();
    if (!$draft||!is_string($draft->extraction)) throw new RuntimeException();
    $plain=$crypt::decryptString($draft->extraction); if (strlen($plain)>131072) throw new RuntimeException();
    $data=json_decode($plain,true,32,JSON_THROW_ON_ERROR); if (!is_array($data)) throw new RuntimeException();
    $out+=['conversation_id'=>$cid,'draft_id'=>$did,'ticket_present'=>$draft->ticket_id!==null];
    $stage='SNAPSHOT'; $workflow=app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    if (!is_object($workflow)||get_class($workflow)!==\App\Services\Dashboard\WhatsAppOrderWorkflow::class) throw new RuntimeException();
    $snapshot=waTailInvoke($workflow,'snapshot',$cid,(int)$draft->evidence_floor,(int)$draft->evidence_ceiling,false);
    $transcript=$snapshot['transcript']??null; $keys=$snapshot['keys']??null;
    if (!is_array($transcript)||!is_array($keys)||!is_string($snapshot['hash']??null)||count($transcript)>60) throw new RuntimeException();
    $checked=\App\Support\WhatsAppOrderExtraction::validateDetailed($data,$transcript);
    $current=is_string($draft->evidence_hash)&&hash_equals($draft->evidence_hash,$snapshot['hash'])
        &&!$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id','>',$draft->evidence_ceiling)->exists();
    $out+=['snapshot_complete'=>($snapshot['complete']??false)===true,'snapshot_current_at_read'=>$current,
        'extraction_valid'=>($checked['ok']??false)===true,
        'raw_decision'=>waTailEnum($data['decision']??null,['NONE','DRAFT','CONFIRMED','CANCELLED']),
        'checked_decision'=>waTailEnum($checked['data']['decision']??null,['NONE','DRAFT','CONFIRMED','CANCELLED'])];
    if (!$out['snapshot_complete']||!$current||!$out['extraction_valid']||$out['raw_decision']!=='CONFIRMED'||$out['checked_decision']!=='CONFIRMED') {
        $out['status']='OBSERVATION_BLOCKED'; $out['stage']='SNAPSHOT_GUARD';
    } else {
        $stage='LEDGER';
        $thread=$db::table('whatsapp_inbox_conversations')->where('id',$cid)->where('waba_id',$access::WABA_ID)
            ->where('phone_number_id',$access::PHONE_ID)->first(['id','waba_id','phone_number_id','peer_hash']);
        $rows=$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id','>',$draft->evidence_floor)
            ->where('id','<=',$draft->evidence_ceiling)->orderBy('sent_at')->orderBy('id')->limit(61)
            ->get(['id','conversation_id','message_key','source','direction','type','sent_at'])->all();
        if (!$thread||count($rows)!==count($transcript)||count($keys)!==count($transcript)||count($rows)>60) throw new RuntimeException();
        $ledger=[]; $seen=[];
        foreach ($rows as $position=>$row) {
            $message=$transcript[$position]??null; $id='m'.($position+1);
            $speaker=$row->direction==='inbound'?'customer':($row->direction==='outbound'?'business':null);
            if (!is_array($message)||($message['id']??null)!==$id||($message['speaker']??null)!==$speaker
                ||($message['sent_at']??null)!==$row->sent_at||!waTailTimeValid($row->sent_at)
                ||!is_string($row->message_key)||preg_match('/\A[a-f0-9]{64}\z/',$row->message_key)!==1
                ||isset($seen[$row->message_key])||($keys[$id]??null)!==$row->message_key) throw new RuntimeException();
            $seen[$row->message_key]=true;
            $ledger[$id]=['ordinal'=>$position+1,'row'=>$row];
        }
        $episode=$evidence::episodeTranscript($transcript); $final=-1;
        foreach ($episode as $position=>$message) if ($message['speaker']==='business'
            &&in_array($message['id'],$data['evidence']['confirmation_ids'],true)) $final=$position;
        if ($final<0) throw new RuntimeException();
        $out['final_cited_business_ordinal']=$ledger[$episode[$final]['id']]['ordinal'];
        $out['ledger_rows_matched']=count($rows); $out['tail_complete']=true; $out['all_tail_authenticated']=true;
        $versions=[];
        $projector=new \App\Services\Dashboard\WhatsAppInboxMessage();
        foreach ($episode as $position=>$message) {
            if ($position<=$final||$message['speaker']!=='customer') continue;
            $entry=$ledger[$message['id']]; $row=$entry['row'];
            $metadata=['ordinal'=>$entry['ordinal'],'relative_to_final'=>$position-$final,
                'native_kind'=>waTailEnum($row->type,['text','location','order','audio','image','video','document','unsupported','sticker','interactive','button','contacts','reaction','system']),
                'source'=>waTailEnum($row->source,['messages','standby','smb_message_echoes','dashboard_api']),
                'authentic_projected'=>false,'native_text_matches_snapshot'=>null,
                'caption_status'=>'UNAUTHENTICATED','caption_length'=>null,
                'snapshot_text_present'=>is_string($message['text'])&&trim($message['text'])!=='',
                'snapshot_text_length'=>is_string($message['text'])?min(16000,mb_strlen($message['text'],'UTF-8')):null,
                'cited_request'=>in_array($message['id'],$data['evidence']['request_ids'],true),
                'cited_acceptance'=>in_array($message['id'],$data['evidence']['customer_acceptance_ids'],true),
                'snapshot_whole_positive'=>waTailInvoke($service,'positiveApproval',$message['text'])===true];
            try {
                $native=$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id',$row->id)
                    ->select('id','conversation_id','message_key','source','direction','type','sent_at')
                    ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
                if (!$native||$native->message_key!==$row->message_key||$native->source!==$row->source
                    ||$native->direction!==$row->direction||$native->type!==$row->type||$native->sent_at!==$row->sent_at) throw new RuntimeException();
                $versions[(int)$row->id]=is_string($native->content)?hash('sha256',$native->content):null;
                $projection=$projector->projected($thread,$native);
                if (($projection['direction']??null)!=='inbound'||($projection['kind']??null)!==$row->type
                    ||($projection['sent_at']??null)!==$row->sent_at||($projection['native_message_key']??null)!==$row->message_key) throw new RuntimeException();
                $text=$projection['text']; $body=$projection['raw'][$row->type]??null;
                $hasCaption=is_array($body)&&array_key_exists('caption',$body); $caption=$hasCaption?$body['caption']:null;
                $metadata['authentic_projected']=true; $metadata['native_text_matches_snapshot']=$text===$message['text'];
                $metadata['caption_status']=!$hasCaption?'ABSENT':($caption===null?'NULL':($caption===''?'EMPTY':'TEXT'));
                $metadata['caption_length']=is_string($caption)?min(20000,mb_strlen($caption,'UTF-8')):null;
                $metadata+=waTailTextMetadata($text);
                $metadata['native_whole_positive']=waTailInvoke($service,'positiveApproval',$text)===true;
                $metadata['matches_customer_fields']=[]; $metadata['item_name_match_ordinals']=[]; $metadata['item_option_match_ordinals']=[];
                foreach (['name','phone','address','area'] as $field) {
                    $value=$data['customer'][$field]??null;
                    $metadata['matches_customer_fields'][$field]=is_string($value)&&is_string($text)
                        &&waTailInvoke($evidence,$field==='phone'?'phoneLiteral':'literal',$value,$text)===true;
                }
                foreach ($data['items'] as $index=>$item) if (is_string($text)) {
                    if (waTailInvoke($evidence,'literal',$item['name'],$text)===true) $metadata['item_name_match_ordinals'][]=$index+1;
                    if (is_string($item['option_hint']??null)&&waTailInvoke($evidence,'literal',$item['option_hint'],$text)===true) $metadata['item_option_match_ordinals'][]=$index+1;
                }
            } catch (Throwable $ignore) { $out['all_tail_authenticated']=false; }
            unset($native,$projection,$body,$caption,$text);
            $out['customer_tail'][]=$metadata;
        }
        $out['post_final_customer_count']=count($out['customer_tail']);
        $stage='FINAL_CURRENT';
        $finalSnapshot=waTailInvoke($workflow,'snapshot',$cid,(int)$draft->evidence_floor,(int)$draft->evidence_ceiling,false);
        $out['snapshot_current_at_end']=($finalSnapshot['complete']??false)===true&&hash_equals($snapshot['hash'],$finalSnapshot['hash'])
            &&!$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id','>',$draft->evidence_ceiling)->exists();
        // The semantic snapshot does not cover every raw caption. Recheck the
        // complete metadata ledger plus one bounded ciphertext at a time privately.
        $finalRows=$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id','>',$draft->evidence_floor)
            ->where('id','<=',$draft->evidence_ceiling)->orderBy('sent_at')->orderBy('id')->limit(61)
            ->get(['id','conversation_id','message_key','source','direction','type','sent_at'])->all();
        $out['native_ledger_current_at_end']=count($finalRows)===count($rows)&&count($versions)===count($out['customer_tail']);
        foreach ($rows as $position=>$row) {
            if (($finalRows[$position]??null)!=$row) { $out['native_ledger_current_at_end']=false; break; }
        }
        foreach ($versions as $id=>$fingerprint) {
            $same=$db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)->where('id',$id)
                ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
            $current=$same&&is_string($same->content)?hash('sha256',$same->content):null;
            if (!$same||$current!==$fingerprint) { $out['native_ledger_current_at_end']=false; break; }
            unset($same,$current);
        }
        $stage='CAPTURE';
        $out['pending_raw_events']=$db::table('whatsapp_webhook_events as e')->whereNull('e.processed_at')
            ->whereNotExists(function ($query) use ($db) {
                $query->select($db::raw('1'))->from('whatsapp_inbox_ingestion_failures as f')->whereColumn('f.event_id','e.id');
            })->count();
        $out['pending_scope']='GLOBAL_UNQUARANTINED_CAPTURE_QUEUE';
        $out['status']=$out['snapshot_current_at_end']&&$out['native_ledger_current_at_end']?'READ_READY':'OBSERVATION_BLOCKED';
        if ($out['status']==='OBSERVATION_BLOCKED') { $out['customer_tail']=[]; $out['stage']='FINAL_CURRENT'; }
    }
} catch (Throwable $ignore) { $out['customer_tail']=[]; $out['status']='READ_FAILED'; $out['stage']=$stage; }
while (ob_get_level()) ob_end_clean();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($out['status']==='READ_READY'?0:1);
