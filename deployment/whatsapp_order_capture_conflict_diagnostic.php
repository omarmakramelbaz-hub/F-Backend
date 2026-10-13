<?php
/** Fixed-scope, read-only capture diagnostic. Outputs difference names and booleans only. */
final class WhatsAppOrderCaptureConflictDiagnostic
{
    public const WABA = '468336579702269';
    public const PHONE = '515388018324075';
    private const TYPES = ['text','image','video','document','audio','sticker','location','contacts','interactive','button','reaction','order','system','referral','edit','revoke','unsupported','unknown','template','flow'];
    private const SOURCES = ['messages','standby','smb_message_echoes','dashboard_api'];

    public static function source($v): string { return in_array($v,self::SOURCES,true)?$v:'OTHER'; }
    public static function type($v): string { return in_array($v,self::TYPES,true)?$v:'OTHER'; }
    public static function direction($v): string { return in_array($v,['inbound','outbound'],true)?$v:'OTHER'; }
    public static function reason($v): string { return in_array($v,['CONFLICTING_MESSAGE','INVALID_EVENT','INVALID_ACCEPTED_REPLY','UNPROVEN_QUOTED_REPLY'],true)?$v:'OTHER'; }
    private static function identityKind($v): string
    {
        return is_string($v) && str_starts_with($v,'user:')?'USER':(is_string($v)&&str_starts_with($v,'phone:')?'PHONE':'OTHER');
    }
    private static function present($v): bool { return is_string($v) && $v!==''; }
    private static function canonical($v)
    {
        if (!is_array($v)) return $v;
        foreach ($v as $k=>$x) $v[$k]=self::canonical($x);
        if (!array_is_list($v)) ksort($v);
        return $v;
    }
    private static function time($v): ?int
    {
        if (!is_string($v)) return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new \DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d H:i:s')===$v?$d->getTimestamp():null;
    }

    /** Native comparison mirrors Consumer checks without invoking its mutating persist method. */
    public static function compare(array $incoming, ?array $saved, ?array $customer): array
    {
        $out=['incoming_source'=>self::source($incoming['source']??null),
            'incoming_direction'=>self::direction($incoming['direction']??null),'incoming_type'=>self::type($incoming['type']??null),
            'incoming_identity_kind'=>self::identityKind($incoming['peer_identity']??null),
            'incoming_user_present'=>self::present($incoming['peer_user_id']??null),
            'incoming_phone_present'=>self::present($incoming['peer_phone']??null),
            'saved_decodable'=>$saved!==null,'dto_difference_names'=>[], 'payload_difference_names'=>[],
            'routing_difference_names'=>[], 'context_difference_names'=>[], 'timestamp_equal'=>null,'timestamp_delta_seconds'=>null];
        if ($saved!==null) {
            $out['saved_source']=self::source($saved['source']??null);
            $out['saved_identity_kind']=self::identityKind($saved['peer_identity']??null);
            $out['identity_equal']=($saved['peer_identity']??null)===($incoming['peer_identity']??null);
            $out['user_both_present']=self::present($saved['peer_user_id']??null)&&self::present($incoming['peer_user_id']??null);
            $out['phone_both_present']=self::present($saved['peer_phone']??null)&&self::present($incoming['peer_phone']??null);
            $out['user_equal_when_both_present']=$out['user_both_present']?($saved['peer_user_id']===$incoming['peer_user_id']):null;
            $out['phone_equal_when_both_present']=$out['phone_both_present']?($saved['peer_phone']===$incoming['peer_phone']):null;
            foreach (['message_id','peer_identity','direction','type','text','sent_at'] as $f)
                if (!array_key_exists($f,$saved)||($saved[$f]!==($incoming[$f]??null))) $out['dto_difference_names'][]=$f;
            $rawSaved=$saved['content']['message']??[]; $rawIncoming=$incoming['content']['message']??[];
            $fields=['errors','text','image','video','document','audio','sticker','location','contacts','interactive','button','reaction','order','system'];
            if (in_array($incoming['type']??null,self::TYPES,true)) $fields[]= $incoming['type'];
            foreach (array_unique($fields) as $f) if (self::canonical($rawSaved[$f]??null)!==self::canonical($rawIncoming[$f]??null))
                $out['payload_difference_names'][]=$f;
            foreach (['from','from_user_id','to','to_user_id'] as $f)
                if (($rawSaved[$f]??null)!==($rawIncoming[$f]??null)) $out['routing_difference_names'][]=$f;
            foreach (['id','from','forwarded','frequently_forwarded','referred_product','referred_message','expiration'] as $f)
                if (self::canonical($rawSaved['context'][$f]??null)!==self::canonical($rawIncoming['context'][$f]??null)) $out['context_difference_names'][]=$f;
            $a=self::time($incoming['sent_at']??null); $b=self::time($saved['sent_at']??null);
            if ($a!==null && $b!==null) { $out['timestamp_equal']=$a===$b; $delta=$a-$b; $out['timestamp_delta_seconds']=max(-31536000,min(31536000,$delta)); $out['timestamp_delta_clamped']=abs($delta)>31536000; }
        }
        $out['customer_decodable']=$customer!==null;
        foreach (['phone'=>'peer_phone','user_id'=>'peer_user_id'] as $field=>$native) {
            $known=self::present($customer[$field]??null); $incomingKnown=self::present($incoming[$native]??null);
            $out['customer_'.$field.'_present']=$known;
            $out['customer_'.$field.'_both_present']=$known&&$incomingKnown;
            $out['customer_'.$field.'_compatible']=$customer===null?null:(!$known||!$incomingKnown||$customer[$field]===$incoming[$native]);
        }
        return $out;
    }

    /** Read-only component checks mirror the guarded accepted-evidence implementation. */
    public static function receiptProof(object $helper, object $thread, object $row): array
    {
        $proof=array_fill_keys(['row_shape','audit_decode','response_decode','native_id_valid','remote_key_matches',
            'request_hmac','payload_hmac','index_ready','index_receipt_match','inbound_exists','inbound_projected',
            'recipient_matches','customer_identity_matches','chronology_window','accepted_future'],null);
        $proof['valid']=false; $proof['reason']=null;
        $read=static function(string $method,...$args) use($helper) {
            $r=new \ReflectionMethod($helper,$method); $r->setAccessible(true); return $r->invoke($helper,...$args);
        };
        $db=\Illuminate\Support\Facades\DB::class; $crypt=\Illuminate\Support\Facades\Crypt::class;
        $proof['row_shape']=$row->state==='ACCEPTED'&&$row->kind==='text'&&$row->waba_id===self::WABA
            &&$row->phone_number_id===self::PHONE&&(int)$row->conversation_id===(int)$thread->id&&(int)$row->actor_id>0;
        $audit=null; $response=null;
        try { $audit=$read('decode',$row->audit,65536); $proof['audit_decode']=true; } catch (\Throwable $ignore) { $proof['audit_decode']=false; }
        try { $response=$read('decode',$row->response_details,65536); $proof['response_decode']=true; } catch (\Throwable $ignore) { $proof['response_decode']=false; }
        $native=is_array($response)?($response['message_id']??null):null; $scope=self::WABA.':'.self::PHONE.':';
        $proof['native_id_valid']=is_string($native)&&preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/',$native)===1;
        if ($proof['native_id_valid']) $proof['remote_key_matches']=is_string($row->remote_message_key)
            &&hash_equals($row->remote_message_key,hash('sha256','whatsapp-reply-message-v1:'.$scope.$native));
        if ($audit!==null) {
            $uuid=$audit['client_request_id']??null;
            $proof['request_hmac']=is_string($uuid)&&is_string($row->request_hash)
                &&hash_equals($row->request_hash,hash_hmac('sha256','whatsapp-reply-request-v1:'.$row->actor_id.':'.$row->conversation_id.':'.strtolower($uuid),$crypt::getKey()));
            try {
                $values=$read('payloadValues',$row,$audit);
                $proof['payload_hmac']=is_string($row->payload_hash)&&hash_equals($row->payload_hash,
                    hash_hmac('sha256','whatsapp-reply-payload-v1:'.json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$crypt::getKey()));
            } catch (\Throwable $ignore) { $proof['payload_hmac']=false; }
        }
        $index=$db::table(\App\Services\Dashboard\WhatsAppInboxMessage::INDEX)->where('reply_id',$row->id)->first();
        $proof['index_ready']=$index&&(bool)$index->valid&&(int)$index->conversation_id===(int)$row->conversation_id
            &&$index->waba_id===$row->waba_id&&$index->phone_number_id===$row->phone_number_id&&$index->kind==='text'
            &&$proof['native_id_valid']&&$index->message_key===hash('sha256','whatsapp-message-v1:'.$scope.$native);
        $proof['index_receipt_match']=$index&&is_string($row->response_details)&&is_string($row->remote_message_key)
            &&$index->receipt_hash===hash('sha256',$row->response_details.':'.$row->remote_message_key.':'.$row->conversation_id);
        $inbound=$db::table('whatsapp_inbox_messages')->where('conversation_id',$thread->id)->where('id',$row->inbound_message_id)
            ->where('direction','inbound')->select('id','conversation_id','message_key','direction','type','source','sent_at')
            ->selectRaw('CASE WHEN LENGTH(content) <= 8388608 THEN content ELSE NULL END AS content')->first();
        $proof['inbound_exists']=$inbound!==null;
        if ($inbound) {
            $dto=null; $projected=null;
            try { $projected=(new \App\Services\Dashboard\WhatsAppInboxMessage())->projected($thread,$inbound); $proof['inbound_projected']=true; }
            catch (\Throwable $ignore) { $proof['inbound_projected']=false; }
            try { $dto=$read('decode',$inbound->content,8388608); } catch (\Throwable $ignore) {}
            if ($projected!==null&&$audit!==null) $proof['recipient_matches']=$projected['peer_phone']===($audit['recipient']??null);
            if ($dto!==null&&$audit!==null) {
                try {
                    $customer=$read('decode',$thread->customer,65536);
                    $proof['customer_identity_matches']=(($customer['phone']??null)===null||$customer['phone']===($audit['recipient']??null))
                        &&(($customer['user_id']??null)===null||$customer['user_id']===($dto['peer_user_id']??null));
                } catch (\Throwable $ignore) { $proof['customer_identity_matches']=false; }
            }
            try {
                $accepted=$read('time',$row->completed_at); $started=$read('time',$row->send_started_at);
                $claimed=$read('time',$row->claimed_at); $at=$read('time',$inbound->sent_at);
                $proof['accepted_future']=$accepted>now('UTC');
                $proof['chronology_window']=$claimed<=$started&&$started<=$accepted&&$at<=$started
                    &&$accepted->getTimestamp()<$at->getTimestamp()+86400&&!$proof['accepted_future'];
            } catch (\Throwable $ignore) { $proof['chronology_window']=false; }
        }
        try { $read('receipt',$thread,$row,false); $proof['valid']=true; }
        catch (\Throwable $error) { $proof['reason']=self::reason($error->getMessage()); }
        return $proof;
    }

    /** Bounded envelope morphology, no raw names, phones, text, coordinates or identifiers. */
    public static function morphology(array $payload): array
    {
        $entries=$payload['entry']??null;
        $out=['object_matches'=>($payload['object']??null)==='whatsapp_business_account',
            'entry_is_list'=>is_array($entries)&&array_is_list($entries),'entry_count'=>is_array($entries)?count($entries):0,
            'changes'=>[],'truncated'=>false];
        foreach (array_slice(is_array($entries)?$entries:[],0,5) as $entry) {
            if (!is_array($entry)) { $out['changes'][]=['entry_is_array'=>false]; continue; }
            $changes=$entry['changes']??null;
            if (!is_array($changes)) { $out['changes'][]=['entry_is_array'=>true,'changes_is_list'=>false]; continue; }
            if (count($changes)>5) $out['truncated']=true;
            foreach (array_slice($changes,0,5) as $change) {
                if (count($out['changes'])>=10) { $out['truncated']=true; break 2; }
                $value=is_array($change)?($change['value']??null):null;
                $c=['entry_scope_matches'=>($entry['id']??null)===self::WABA,
                    'changes_is_list'=>array_is_list($changes),'change_is_array'=>is_array($change),
                    'field'=>self::source(is_array($change)?($change['field']??null):null),'value_is_array'=>is_array($value)];
                if (is_array($value)) {
                    $c['product_matches']=($value['messaging_product']??null)==='whatsapp';
                    $c['metadata_is_array']=is_array($value['metadata']??null);
                    $c['phone_scope_matches']=($value['metadata']['phone_number_id']??null)===self::PHONE;
                    $c['standby_present']=array_key_exists('standby',$value);
                    $c['standby_is_array']=is_array($value['standby']??null);
                    $channel=$c['standby_is_array']?$value['standby']:$value;
                    $contacts=$channel['contacts']??($value['contacts']??[]);
                    $c['contacts_is_list']=is_array($contacts)&&array_is_list($contacts);
                    $c['contacts_count']=is_array($contacts)?count($contacts):0;
                    if ($c['contacts_count']>5) $out['truncated']=true;
                    $c['contact_shapes']=[];
                    foreach (array_slice(is_array($contacts)?$contacts:[],0,5) as $contact) {
                        $c['contact_shapes'][]=['is_array'=>is_array($contact),'phone_present'=>is_array($contact)&&array_key_exists('wa_id',$contact),
                            'phone_valid'=>is_array($contact)&&is_string($contact['wa_id']??null)&&preg_match('/\A\+?[1-9][0-9]{7,14}\z/',$contact['wa_id'])===1,
                            'user_present'=>is_array($contact)&&array_key_exists('user_id',$contact),
                            'user_is_string'=>is_array($contact)&&is_string($contact['user_id']??null),
                            'profile_is_array'=>is_array($contact)&&is_array($contact['profile']??null)];
                    }
                    foreach (['messages','message_echoes','statuses'] as $f) {
                        $v=$channel[$f]??null;
                        $c[$f.'_present']=array_key_exists($f,$channel);
                        $c[$f.'_is_list']=is_array($v)&&array_is_list($v);
                        $c[$f.'_count']=is_array($v)?count($v):0;
                        if ($c[$f.'_count']>5) $out['truncated']=true;
                        if ($f==='statuses') continue;
                        $c[$f.'_shapes']=[];
                        foreach (array_slice(is_array($v)?$v:[],0,5) as $record) {
                            $wrapped=is_array($record)&&array_key_exists('message',$record);
                            $m=$wrapped?$record['message']:$record;
                            $shape=['is_array'=>is_array($record),'wrapped_message'=>$wrapped,'inner_is_array'=>is_array($m),
                                'type'=>self::type(is_array($m)?($m['type']??null):null),
                                'native_id_is_string'=>is_array($m)&&is_string($m['id']??null),
                                'timestamp_is_digits'=>is_array($m)&&(is_string($m['timestamp']??null)||is_int($m['timestamp']??null))&&preg_match('/\A[0-9]{1,10}\z/',(string)$m['timestamp'])===1,
                                'text_body_is_string'=>is_array($m)&&is_string($m['text']['body']??null)];
                            foreach (['from','from_user_id','to','to_user_id'] as $field) {
                                $shape[$field.'_present']=is_array($m)&&array_key_exists($field,$m);
                                $shape[$field.'_is_string']=is_array($m)&&is_string($m[$field]??null);
                            }
                            $c[$f.'_shapes'][]=$shape;
                        }
                    }
                }
                $out['changes'][]=$c;
            }
        }
        if (is_array($entries)&&count($entries)>5) $out['truncated']=true;
        return $out;
    }
}

if (defined('WHATSAPP_CAPTURE_CONFLICT_DIAGNOSTIC_TEST_ONLY') && WHATSAPP_CAPTURE_CONFLICT_DIAGNOSTIC_TEST_ONLY === true) return;
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
$out=['diagnostic'=>'READ_ONLY_CAPTURE_CONFLICTS','writes'=>0,'ai_calls'=>0,'network_requests'=>0];
$stage='OWNER';
try {
    $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']) throw new \RuntimeException();
    $root='/home/fasakha/public_html';
    $pins=[
        'app/Services/Dashboard/WhatsAppInboxConsumer.php'=>'e54715313901be8baefb551566b7bb88f4afe45b',
        'app/Support/WhatsAppInboxProtocol.php'=>'ecde67c6a87ca8068bb077892fdbea522d89f217',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'b30f25cf6dfc4a84061a58316db583f892a439a4',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppReplyService.php'=>'1ef23df825287f8d3dedafcdb5d70ffeac7f1f29',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
    ];
    $stage='SOURCE_GUARD';
    $guard=static function($path,$expected) use($root) {
        $name=$root.'/'.$path; $info=@lstat($name);
        if (!$info||($info['mode']&0170000)!==0100000||$info['uid']!==posix_geteuid()
            ||realpath($name)!==$name||$info['size']<1||$info['size']>8388608) throw new \RuntimeException();
        $b=file_get_contents($name);
        if (!hash_equals($expected,sha1('blob '.strlen($b)."\0".$b))) throw new \RuntimeException();
    };
    foreach ($pins as $path=>$hash) $guard($path,$hash);
    $stage='BOOTSTRAP'; chdir($root); require 'vendor/autoload.php';
    $app=require 'bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach ([\App\Services\Dashboard\WhatsAppInboxConsumer::class=>'app/Services/Dashboard/WhatsAppInboxConsumer.php',
        \App\Support\WhatsAppInboxProtocol::class=>'app/Support/WhatsAppInboxProtocol.php',
        \App\Services\Dashboard\WhatsAppAcceptedReplyEvidence::class=>'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php',
        \App\Services\Dashboard\WhatsAppOrderWorkflow::class=>'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
        \App\Services\Dashboard\WhatsAppInboxMessage::class=>'app/Services/Dashboard/WhatsAppInboxMessage.php',
        \App\Services\Dashboard\WhatsAppReplyService::class=>'app/Services/Dashboard/WhatsAppReplyService.php',
        \App\Services\Dashboard\WhatsAppInboxAccess::class=>'app/Services/Dashboard/WhatsAppInboxAccess.php'] as $class=>$path) {
        if (realpath((new \ReflectionClass($class))->getFileName())!==$root.'/'.$path) throw new \RuntimeException();
        $guard($path,$pins[$path]);
    }
    $db=\Illuminate\Support\Facades\DB::class; $crypt=\Illuminate\Support\Facades\Crypt::class;
    $decode=static function($cipher,int $limit=8388608,?int $cipherLimit=null) use($crypt) {
        if (!is_string($cipher)||strlen($cipher)>($cipherLimit??$limit)) throw new \RuntimeException();
        $p=$crypt::decryptString($cipher);
        if (strlen($p)>$limit) throw new \RuntimeException('PLAINTEXT_LIMIT');
        $v=json_decode($p,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($v)) throw new \RuntimeException(); return $v;
    };
    $stage='ACTIVATION'; $activation=config('whatsapp_orders.activation_event_id');
    if ((!is_int($activation)&&!is_string($activation))||!preg_match('/\A(?:0|[1-9][0-9]*)\z/',(string)$activation)
        ||strlen((string)$activation)>strlen((string)PHP_INT_MAX)
        ||(strlen((string)$activation)===strlen((string)PHP_INT_MAX)&&strcmp((string)$activation,(string)PHP_INT_MAX)>0)) throw new \RuntimeException();
    $out['activation_event_id']=(int)$activation;
    $stage='PROCESS_GATES';
    $workflow=new \App\Services\Dashboard\WhatsAppOrderWorkflow();
    $out['process_gates']=['enabled'=>(bool)config('whatsapp_orders.enabled'),
        'mode'=>in_array(config('whatsapp_orders.mode'),['review','auto'],true)?config('whatsapp_orders.mode'):'OTHER',
        'workflow_available'=>$workflow->available(),'automation_actor_ready'=>false];
    try {
        $actorMethod=new \ReflectionMethod($workflow,'automaticActor'); $actorMethod->setAccessible(true);
        $actorMethod->invoke($workflow); $out['process_gates']['automation_actor_ready']=true;
    } catch (\Throwable $ignore) {}
    $stage='CAPTURE';
    $failures=$db::table('whatsapp_inbox_ingestion_failures')->where('event_id','>',(int)$activation)->orderBy('event_id')->limit(21)->get(['event_id','reason']);
    $out['events_truncated']=$failures->count()>20; $out['events']=[]; $budget=0;
    $scope=WhatsAppOrderCaptureConflictDiagnostic::WABA.':'.WhatsAppOrderCaptureConflictDiagnostic::PHONE.':';
    foreach ($failures->take(20) as $f) {
        $e=['event_id'=>(int)$f->event_id,'reason'=>WhatsAppOrderCaptureConflictDiagnostic::reason($f->reason),'raw_plaintext_within_consumer_limit'=>null];
        try {
            $raw=$db::table('whatsapp_webhook_events')->where('id',$f->event_id)
                ->select('id','processed_at')->selectRaw('CASE WHEN LENGTH(payload) <= 8388608 THEN payload ELSE NULL END AS payload')->first();
            if (!$raw) throw new \RuntimeException();
            $budget+=is_string($raw->payload)?strlen($raw->payload):0;
            if ($budget>16777216) { $e['status']='BYTE_BUDGET'; $out['events_truncated']=true; $out['events'][]=$e; break; }
            $payload=$decode($raw->payload,4194304,8388608); $e['raw_plaintext_within_consumer_limit']=true;
            $report=\App\Support\WhatsAppInboxProtocol::report($payload,WhatsAppOrderCaptureConflictDiagnostic::WABA,WhatsAppOrderCaptureConflictDiagnostic::PHONE);
            $e['raw_processed']=$raw->processed_at!==null;
            $e['report']=['messages_count'=>count($report['messages']),'quarantined_count'=>$report['quarantined_count'],
                'ignored_count'=>$report['ignored_count'],'status_count'=>$report['status_count']];
            if ($f->reason==='INVALID_EVENT'||$report['quarantined_count']>0) $e['morphology']=WhatsAppOrderCaptureConflictDiagnostic::morphology($payload);
            $e['messages']=[]; $e['messages_truncated']=count($report['messages'])>20;
            foreach (array_slice($report['messages'],0,20) as $incoming) {
                $key=hash('sha256','whatsapp-message-v1:'.$scope.$incoming['message_id']);
                $peer=hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$incoming['peer_identity'],$crypt::getKey());
                $savedRow=$db::table('whatsapp_inbox_messages')->where('message_key',$key)
                    ->select('id','conversation_id','message_key','direction','type','source','sent_at')
                    ->selectRaw('CASE WHEN LENGTH(content) <= 8388608 THEN content ELSE NULL END AS content')->first();
                $thread=$savedRow?$db::table('whatsapp_inbox_conversations')->where('id',$savedRow->conversation_id)->select('id','waba_id','phone_number_id','peer_hash')->selectRaw('CASE WHEN LENGTH(customer) <= 65536 THEN customer ELSE NULL END AS customer')->first()
                    :$db::table('whatsapp_inbox_conversations')->where('waba_id',WhatsAppOrderCaptureConflictDiagnostic::WABA)
                        ->where('phone_number_id',WhatsAppOrderCaptureConflictDiagnostic::PHONE)->where('peer_hash',$peer)->select('id','waba_id','phone_number_id','peer_hash')->selectRaw('CASE WHEN LENGTH(customer) <= 65536 THEN customer ELSE NULL END AS customer')->first();
                $saved=null; $customer=null;
                try { if ($savedRow) $saved=$decode($savedRow->content); } catch (\Throwable $ignore) {}
                try { if ($thread) $customer=$decode($thread->customer,65536); } catch (\Throwable $ignore) {}
                $m=WhatsAppOrderCaptureConflictDiagnostic::compare($incoming,$saved,$customer);
                $m['existing_message_id']=$savedRow?(int)$savedRow->id:null;
                $m['existing_conversation_id']=$thread?(int)$thread->id:null;
                $m['thread_scope_matches']=$thread&&$thread->waba_id===WhatsAppOrderCaptureConflictDiagnostic::WABA&&$thread->phone_number_id===WhatsAppOrderCaptureConflictDiagnostic::PHONE;
                $m['peer_hash_matches']=$thread?hash_equals((string)$thread->peer_hash,$peer):null;
                $m['column_direction_matches']=$savedRow?$savedRow->direction===$incoming['direction']:null;
                $m['column_type_matches']=$savedRow?$savedRow->type===$incoming['type']:null;
                $m['saved_column_source']=$savedRow?WhatsAppOrderCaptureConflictDiagnostic::source($savedRow->source):null;
                if ($savedRow&&$thread&&$savedRow->source==='dashboard_api') {
                    $m['api_receipt_echo_matches']=(new \App\Services\Dashboard\WhatsAppAcceptedReplyEvidence())->matchesNativeEcho($thread,$savedRow,$incoming,false);
                    // Receipt envelope is not a native DTO; do not label its fields native differences.
                    $m['dto_difference_names']=[]; $m['payload_difference_names']=[]; $m['routing_difference_names']=[]; $m['context_difference_names']=[];
                }
                $e['messages'][]=$m;
            }
            $e['status']='READY';
        } catch (\Throwable $ignore) {
            $e['status']=$ignore->getMessage()==='PLAINTEXT_LIMIT'?'OVERSIZE':'UNREADABLE';
            if ($e['status']==='OVERSIZE') $e['raw_plaintext_within_consumer_limit']=false;
        }
        $out['events'][]=$e;
    }
    $stage='CONVERSATION_11_RECEIPTS';
    $thread=$db::table('whatsapp_inbox_conversations')->where('id',11)->where('waba_id',WhatsAppOrderCaptureConflictDiagnostic::WABA)
        ->where('phone_number_id',WhatsAppOrderCaptureConflictDiagnostic::PHONE)->select('id','waba_id','phone_number_id','peer_hash')->selectRaw('CASE WHEN LENGTH(customer) <= 65536 THEN customer ELSE NULL END AS customer')->first();
    $stage='CONVERSATION_11_SCAN';
    $scan=$db::table('whatsapp_order_scans')->where('conversation_id',11)->first();
    $max=$db::table('whatsapp_inbox_messages')->where('conversation_id',11)->selectRaw('MAX(id) AS ceiling, MAX(created_at) AS observed_at')->first();
    $ceiling=(int)($max->ceiling??0); $observed=$max->observed_at??null;
    $dispatched=(int)$db::table('whatsapp_order_drafts')->where('conversation_id',11)->where('status','DISPATCHED')->max('evidence_ceiling');
    $messageActivation=config('whatsapp_orders.activation_message_id');
    if ((!is_int($messageActivation)&&!is_string($messageActivation))||!preg_match('/\A(?:0|[1-9][0-9]*)\z/',(string)$messageActivation)
        ||strlen((string)$messageActivation)>strlen((string)PHP_INT_MAX)
        ||(strlen((string)$messageActivation)===strlen((string)PHP_INT_MAX)&&strcmp((string)$messageActivation,(string)PHP_INT_MAX)>0)) throw new \RuntimeException();
    $messageActivation=(int)$messageActivation;
    $baseline=$scan===null||$scan->auto_floor===null||(int)$scan->auto_floor<$messageActivation;
    $seconds=config('whatsapp_cart.debounce_seconds',10);
    if (!is_int($seconds)&&!(is_string($seconds)&&preg_match('/\A[0-9]{1,3}\z/',$seconds))) $seconds=10;
    $seconds=max(0,min(120,(int)$seconds)); $now=now('UTC')->format('Y-m-d H:i:s');
    $matching=$db::table('whatsapp_order_drafts')->where('conversation_id',11)->where('evidence_ceiling',$ceiling)->orderByDesc('id')->first(['id','status','reason','evidence_floor','evidence_ceiling']);
    $needs=$ceiling>(int)($scan->analyzed_ceiling??0)||($ceiling===(int)($scan->analyzed_ceiling??0)&&$matching&&in_array($matching->status,['REVIEW','READY'],true));
    $settled=is_string($observed)&&$observed<=now('UTC')->subSeconds($seconds)->format('Y-m-d H:i:s');
    $backoff=$baseline||($scan->next_attempt_at??null)===null||$scan->next_attempt_at<=$now;
    $retry=$baseline||$ceiling!==(int)($scan->claimed_ceiling??0)||(int)($scan->attempts??0)<3;
    $freshFloor=max($messageActivation,(int)($scan->auto_floor??0),$dispatched);
    $out['conversation_11_scan']=['ceiling'=>$ceiling,'observed_at_utc'=>$observed,'activation_message_id'=>$messageActivation,
        'analyzed_ceiling'=>(int)($scan->analyzed_ceiling??0),'claimed_ceiling'=>(int)($scan->claimed_ceiling??0),
        'auto_floor'=>$scan&&$scan->auto_floor!==null?(int)$scan->auto_floor:null,'attempts'=>(int)($scan->attempts??0),
        'next_attempt_at_utc'=>$scan->next_attempt_at??null,'lease_until_utc'=>$scan->lease_until??null,
        'nonce_present'=>$scan&&is_string($scan->claim_nonce)&&$scan->claim_nonce!=='',
        'live_lease'=>$scan&&is_string($scan->claim_nonce)&&$scan->claim_nonce!==''&&is_string($scan->lease_until)&&$scan->lease_until>=$now,
        'dispatched_ceiling'=>$dispatched,'fresh_floor'=>$freshFloor,'baseline_needed'=>$baseline,'backoff_allowed'=>$backoff,
        'settled'=>$settled,'needs_analysis'=>(bool)$needs,'retry_allowed'=>$retry,
        'candidate_sql_eligible'=>$backoff&&$settled&&$ceiling>$messageActivation&&$ceiling>(int)($scan->auto_floor??0)&&$needs&&$retry,
        'fresh_segment_available'=>$ceiling>$freshFloor,
        'matching_draft'=>$matching?['id'=>(int)$matching->id,'status'=>in_array($matching->status,['NONE','CANCELLED','REVIEW','READY','DISPATCHED'],true)?$matching->status:'OTHER',
            'reason'=>in_array($matching->reason,['NO_ORDER','REVIEW_REQUIRED','AI_UNAVAILABLE','STALE_TRANSCRIPT','CAPTURE_REVIEW_REQUIRED','CUSTOMER_CANCELLED'],true)?$matching->reason:'OTHER',
            'floor'=>(int)$matching->evidence_floor,'ceiling'=>(int)$matching->evidence_ceiling]:null];
    $out['conversation_11_receipts']=[];
    $since=config('whatsapp_cart.activated_at');
    if (!is_string($since)||!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',$since)) throw new \RuntimeException();
    if ($thread) {
        $helper=new \App\Services\Dashboard\WhatsAppAcceptedReplyEvidence();
                $rows=$db::table('whatsapp_reply_requests')->where('conversation_id',11)->where('waba_id',WhatsAppOrderCaptureConflictDiagnostic::WABA)
            ->where('phone_number_id',WhatsAppOrderCaptureConflictDiagnostic::PHONE)->where('state','ACCEPTED')->where('kind','text')
            ->where('completed_at','>=',$since)->orderByDesc('id')->limit(21)->select('id','conversation_id','actor_id','waba_id','phone_number_id',
                'request_hash','payload_hash','inbound_message_id','kind','state','remote_message_key','claimed_at','send_started_at','completed_at')
            ->selectRaw('CASE WHEN LENGTH(audit) <= 65536 THEN audit ELSE NULL END AS audit')
            ->selectRaw('CASE WHEN LENGTH(response_details) <= 65536 THEN response_details ELSE NULL END AS response_details')->get();
        $out['conversation_11_receipts_truncated']=$rows->count()>20;
        $out['conversation_11_accepted_text_count_since_activation']=$db::table('whatsapp_reply_requests')->where('conversation_id',11)
            ->where('waba_id',WhatsAppOrderCaptureConflictDiagnostic::WABA)->where('phone_number_id',WhatsAppOrderCaptureConflictDiagnostic::PHONE)
            ->where('state','ACCEPTED')->where('kind','text')->where('completed_at','>=',$since)->count();
        $pendingMethod=new \ReflectionMethod($helper,'pending'); $pendingMethod->setAccessible(true);
        $out['conversation_11_pending_accepted_evidence_count']=$pendingMethod->invoke($helper,11,$since)->count();
        foreach ($rows->take(20) as $row) {
            $r=['reply_id'=>(int)$row->id,'completed_after_activation'=>true,'valid'=>false,'reason'=>null,'index_present'=>false,'index_valid'=>null,
                'projection_present'=>false,'projection_source'=>null];
            $index=$db::table(\App\Services\Dashboard\WhatsAppInboxMessage::INDEX)->where('reply_id',$row->id)->where('conversation_id',11)->first();
            if ($index) {
                $r['index_present']=true; $r['index_valid']=(bool)$index->valid;
                $projection=is_string($index->message_key)?$db::table('whatsapp_inbox_messages')->where('conversation_id',11)->where('message_key',$index->message_key)->first(['id','source']):null;
                $r['projection_present']=$projection!==null;
                if ($projection) { $r['projection_message_id']=(int)$projection->id; $r['projection_source']=WhatsAppOrderCaptureConflictDiagnostic::source($projection->source); }
            }
            $r['proof_groups']=WhatsAppOrderCaptureConflictDiagnostic::receiptProof($helper,$thread,$row);
            $r['valid']=$r['proof_groups']['valid']; $r['reason']=$r['proof_groups']['reason'];
            $out['conversation_11_receipts'][]=$r;
        }
    }
    while(ob_get_level()) ob_end_clean();
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch (\Throwable $error) {
    while(ob_get_level()) ob_end_clean(); $out['status']='FAILED'; $out['stage']=$stage;
    echo json_encode($out,JSON_PRETTY_PRINT)."\n"; exit(1);
}
