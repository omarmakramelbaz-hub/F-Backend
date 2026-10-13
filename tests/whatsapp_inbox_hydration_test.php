<?php
namespace {
    $vendor=getenv('WA_INBOX_TEST_VENDOR'); if(is_string($vendor)&&is_dir($vendor)) $vendor.='/autoload.php';
    if(!is_string($vendor)||!is_file($vendor)) throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED'); require $vendor;
    function app($name=null){$c=\Illuminate\Container\Container::getInstance();return $name===null?$c:$c->make($name);}
    function config($name=null,$default=null){return $name===null?app('config'):app('config')->get($name,$default);}
    function now($zone=null){return \Carbon\Carbon::now($zone);}
    function abort_unless($condition,$status,$message='BLOCKED'){if(!$condition)throw new \RuntimeException($message,$status);}
    function abort_if($condition,$status,$message='BLOCKED'){if($condition)throw new \RuntimeException($message,$status);}
    set_error_handler(function($severity,$message,$file,$line){if($severity&E_ALL)throw new \ErrorException($message,0,$severity,$file,$line);return false;});
}
namespace App\Services\Dashboard {
    // Only the order fingerprint dependency is isolated; actual Consumer, Workflow metadata,
    // normalization, receipt proof, encryption and SQLite transactions execute below.
    class PosServiceTicket { public static function fingerprint(array $a):string {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;foreach($v as $k=>$x)$v[$k]=$sort($x);if(!array_is_list($v))ksort($v,SORT_STRING);return $v;};
        return hash('sha256',json_encode($sort($a),JSON_UNESCAPED_UNICODE));
    } }
}
namespace {
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\Crypt;
    use App\Services\Dashboard\WhatsAppInboxConsumer as Consumer;
    use App\Services\Dashboard\WhatsAppNativePlaceholderHydration as Proof;
    $c=new \Illuminate\Container\Container(); \Illuminate\Container\Container::setInstance($c);
    $c->instance('config',new \Illuminate\Config\Repository(['app'=>['key'=>str_repeat('k',32)],
        'whatsapp_orders'=>['enabled'=>true,'mode'=>'auto','activation_message_id'=>0,'activation_event_id'=>0],
        'whatsapp_cart'=>['activated_at'=>'2026-10-13 10:00:00','debounce_seconds'=>10]]));
    $db=new \Illuminate\Database\Capsule\Manager($c);$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $db->setAsGlobal();$db->bootEloquent();$c->instance('db',$db->getDatabaseManager());$c->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter',new \Illuminate\Encryption\Encrypter(str_repeat('k',32),'AES-256-CBC'));\Illuminate\Support\Facades\Facade::setFacadeApplication($c);
    \Carbon\Carbon::setTestNow('2026-10-13 10:30:00 UTC');
    foreach(['WhatsAppInboxAccess','WhatsAppInboxMessage','WhatsAppInboxQuarantinedEvent','WhatsAppNativePlaceholderHydration','WhatsAppInboxConsumer','WhatsAppOrderWorkflow'] as $n)
        require dirname(__DIR__).'/app/Services/Dashboard/'.$n.'.php';
    require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';
    Schema::create('whatsapp_webhook_events',function($t){$t->bigIncrements('id');$t->char('payload_hash',64)->unique();$t->longText('payload');$t->timestamp('received_at');$t->timestamp('processed_at')->nullable();});
    require dirname(__DIR__).'/database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php';(new \CreateWhatsAppInboxTables())->up();
    require dirname(__DIR__).'/database/migrations/2026_10_10_000002_create_whatsapp_order_drafts.php';(new \CreateWhatsAppOrderDrafts())->up();
    $checks=0;
    function hExpect($actual,$expected,$label){global $checks;$checks++;if($actual!==$expected)throw new \RuntimeException('FAILED: '.$label);}
    function hDecode($v){return json_decode(Crypt::decryptString($v),true,512,JSON_THROW_ON_ERROR);}
    function hRaw($id,$type,$timestamp,$extra=[]){return array_replace(['id'=>$id,'from'=>'201000000001','from_user_id'=>'US.hydration','timestamp'=>(string)$timestamp,'type'=>$type],$extra);}
    function hPayload(array $messages,$field='messages'){return ['object'=>'whatsapp_business_account','entry'=>[['id'=>Consumer::WABA_ID,'changes'=>[['field'=>$field,'value'=>[
        'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>Consumer::PHONE_ID,'display_phone_number'=>'201285545554'],
        'contacts'=>[['wa_id'=>'201000000001','user_id'=>'US.hydration','profile'=>['name'=>'عميل الاختبار']]],
        $field==='smb_message_echoes'?'message_echoes':'messages'=>$messages]]]]]];}
    function hEvent(array $payload){$json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return DB::table('whatsapp_webhook_events')->insertGetId([
        'payload_hash'=>hash('sha256',$json),'payload'=>Crypt::encryptString($json),'received_at'=>now('UTC'),'processed_at'=>null]);}
    function hFixture($beforePatch=[],$incomingPatch=[],$newSource='messages',$withFailure=true):array{
        foreach(['whatsapp_order_drafts','whatsapp_order_scans','whatsapp_inbox_ingestion_failures','whatsapp_inbox_messages','whatsapp_inbox_conversations','whatsapp_webhook_events'] as $table)DB::table($table)->delete();
        config()->set('whatsapp_orders.activation_message_id',0);config()->set('whatsapp_cart.activated_at','2026-10-13 10:00:00');
        $old=hRaw('wamid.HYDRATE','unsupported',strtotime('2026-10-13 10:00:00 UTC'),array_replace(['unsupported'=>['type'=>'unknown','raw_type'=>'unknown'],'errors'=>[['code'=>131060,'title'=>'Fixture unavailable']]],$beforePatch));
        $oldEvent=hEvent(hPayload([$old]));$base=(new Consumer())->consume(10);hExpect($base['messages_inserted'],1,'fixture native placeholder genuinely ingested');
        $row=DB::table('whatsapp_inbox_messages')->first();$cid=(int)$row->conversation_id;
        DB::table('whatsapp_order_scans')->insert(['conversation_id'=>$cid,'analyzed_ceiling'=>$row->id,'claimed_ceiling'=>$row->id,'auto_floor'=>0,'claim_nonce'=>null,'lease_until'=>null,
            'attempts'=>3,'next_attempt_at'=>'2026-10-13 11:00:00','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $draft=DB::table('whatsapp_order_drafts')->insertGetId(['conversation_id'=>$cid,'evidence_floor'=>0,'evidence_ceiling'=>$row->id,'evidence_hash'=>str_repeat('a',64),
            'status'=>'NONE','reason'=>'NO_ORDER','revision'=>1,'customer_command_key'=>'11111111-1111-4111-8111-111111111111','ticket_command_key'=>'22222222-2222-4222-8222-222222222222','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $full=hRaw('wamid.HYDRATE','text',strtotime('2026-10-13 10:20:00 UTC'),array_replace(['text'=>['body'=>'عايز نصف كيلو فسيخ دليفري'] ],$incomingPatch));
        $newEvent=hEvent(hPayload([$full],$newSource));
        if($withFailure)DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$newEvent,'reason'=>'CONFLICTING_MESSAGE','attempts'=>1,'last_attempted_at'=>now('UTC')]);
        return ['old'=>$old,'full'=>$full,'old_event'=>$oldEvent,'event'=>$newEvent,'row'=>$row,'cid'=>$cid,'draft'=>$draft];
    }
    function hRecover(array $f){return (new Consumer())->recoverUnavailableMessages([$f['event']]);}
    function hPreserved(array $f,$label){$after=DB::table('whatsapp_inbox_messages')->where('id',$f['row']->id)->first();
        hExpect($after->type,'unsupported',$label.' retains placeholder');hExpect($after->content,$f['row']->content,$label.' retains exact encrypted beforeimage');
        hExpect(DB::table('whatsapp_webhook_events')->where('id',$f['event'])->value('processed_at'),null,$label.' retains raw pending');
        hExpect(DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$f['event'])->exists(),true,$label.' retains quarantine');}

    $f=hFixture();$rawBefore=DB::table('whatsapp_webhook_events')->where('id',$f['event'])->first();$prior=(array)$f['row'];
    hExpect((new Consumer())->consume(10)['events_seen'],0,'ordinary capture never globally retries quarantines');
    $m=hRecover($f);hExpect($m['messages_hydrated'],1,'specific unavailable marker is hydrated');hExpect($m['events_processed'],1,'fixed target event commits');
    hExpect($m['errors'],0,'valid recovery no errors');hExpect($m['quarantined_events'],0,'valid recovery no new quarantine');
    $row=DB::table('whatsapp_inbox_messages')->where('id',$f['row']->id)->first();$dto=hDecode($row->content);$marker=$dto['content']['hydration'];
    hExpect($row->id,$f['row']->id,'same database message ID');hExpect($row->message_key,$f['row']->message_key,'same canonical WhatsApp key');
    hExpect($row->conversation_id,$f['cid'],'same conversation');hExpect($row->source,'messages','honest native source retained');
    hExpect($row->sent_at,'2026-10-13 10:20:00','actual native corrected timestamp');hExpect($row->created_at,$f['row']->created_at,'observation timestamp preserved');
    hExpect($marker['before'],hDecode($f['row']->content),'entire placeholder normalized beforeimage retained');hExpect($marker['before_ciphertext'],$f['row']->content,'encrypted original copy retained');
    hExpect($marker['before_ciphertext_sha256'],hash('sha256',$f['row']->content),'old ciphertext fingerprint');
    $raw=DB::table('whatsapp_webhook_events')->where('id',$f['event'])->first();$thread=DB::table('whatsapp_inbox_conversations')->where('id',$f['cid'])->first();
    hExpect($raw->payload,$rawBefore->payload,'original native raw content remains immutable');hExpect($raw->payload_hash,$rawBefore->payload_hash,'raw signed-body hash unchanged');
    hExpect($raw->processed_at,$marker['at_utc'],'same transaction event and evidence observation timestamp');hExpect(DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$f['event'])->exists(),false,'only resolved target quarantine cleared');
    hExpect(Proof::prove($thread,$row,$dto,$raw,false),true,'actual raw and beforeimage shared proof');
    $scan=DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->first();
    hExpect($scan->analyzed_ceiling,0,'actual Workflow metadata wakes cached NONE');hExpect($scan->claimed_ceiling,0,'new semantic revision attempt sequence');
    hExpect($scan->attempts,0,'only idle semantic revision attempts reset');hExpect($scan->auto_floor,0,'per-conversation cutoff unchanged');
    hExpect(config('whatsapp_orders.activation_message_id'),0,'global message activation unchanged');hExpect(config('whatsapp_cart.activated_at'),'2026-10-13 10:00:00','global activation time unchanged');
    hExpect(DB::table('whatsapp_order_drafts')->where('id',$f['draft'])->value('status'),'NONE','recovery itself does not analyze/create/dispatch orders');
    hExpect(hRecover($f)['events_skipped'],1,'fixed event recovery is idempotent');

    $copy=hPayload([$f['full']],'standby');$copyEvent=hEvent($copy);$stableCipher=$row->content;$stableScan=(array)$scan;
    $replay=(new Consumer())->consume(10);hExpect($replay['messages_replayed'],1,'genuine cross-source copy replays');hExpect($replay['messages_hydrated'],0,'replay is not a semantic correction');
    $replayed=DB::table('whatsapp_inbox_messages')->where('id',$row->id)->first();hExpect($replayed->content,$stableCipher,'cross-source replay cannot rewrite hydration proof sources');
    hExpect(Proof::prove($thread,$replayed,hDecode($replayed->content),$raw,false),true,'raw proof remains valid after legitimate standby replay');
    hExpect((array)DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->first(),$stableScan,'equivalent replay leaves attempt sequence untouched');
    hExpect(DB::table('whatsapp_webhook_events')->where('id',$copyEvent)->value('processed_at')!==null,true,'supplemental genuine raw source preserved processed');

    foreach(['raw_payload_hash','before_ciphertext_sha256'] as $field){$bad=$dto;$bad['content']['hydration'][$field]=str_repeat('b',64);hExpect(Proof::prove($thread,$row,$bad,$raw,false),false,'marker tamper '.$field);}
    $bad=$dto;$bad['text']='different';hExpect(Proof::prove($thread,$row,$bad,$raw,false),false,'current text must reparse from authenticated raw');
    $bad=$dto;$bad['content']['hydration']['before']['peer_phone']='201000000009';hExpect(Proof::prove($thread,$row,$bad,$raw,false),false,'before identity must match original ciphertext');
    hExpect(Proof::prove($thread,$row,$dto,$raw,true),false,'remaining quarantine prevents hydration proof');
    $rawBad=clone $raw;$rawBad->processed_at=null;hExpect(Proof::prove($thread,$row,$dto,$rawBad,false),false,'unprocessed raw never gets pending-proof bypass');
    $rawBad=clone $raw;$rawBad->payload_hash=str_repeat('c',64);hExpect(Proof::prove($thread,$row,$dto,$rawBad,false),false,'stored raw digest binding');
    $rawBad=clone $raw;$rawBad->payload=Crypt::encryptString(json_encode(hPayload([array_replace($f['full'],['text'=>['body'=>'other']])])));
    hExpect(Proof::prove($thread,$row,$dto,$rawBad,false),false,'changed raw payload cannot support marker');

    foreach([
        ['old_error',['errors'=>[['code'=>131051]]],[]],
        ['string_code',['errors'=>[['code'=>'131060']]],[]],
        ['multiple_errors',['errors'=>[['code'=>131060],['code'=>131060]]],[]],
        ['substantive_old_text',['text'=>['body'=>'old genuine content']],[]],
        ['substantive_unsupported',['unsupported'=>['body'=>'unexpected content']],[]],
        ['incoming_errors',[],['errors'=>[['code'=>131060]]]],
        ['incoming_null_errors',[],['errors'=>null]],
        ['different_user',[],['from_user_id'=>'US.different']],
        ['different_phone',[],['from'=>'201000000009']],
        ['different_context',[],['context'=>['id'=>'wamid.other']]],
        ['older_timestamp',[],['timestamp'=>(string)strtotime('2026-10-13 09:59:59 UTC')]],
        ['too_late_timestamp',[],['timestamp'=>(string)strtotime('2026-10-13 11:00:01 UTC')]],
    ] as [$label,$oldPatch,$newPatch]){$bad=hFixture($oldPatch,$newPatch);$r=hRecover($bad);hExpect($r['messages_hydrated'],0,$label.' never hydrates');hPreserved($bad,$label);}

    $f=hFixture([],[],'standby');hExpect(hRecover($f)['messages_hydrated'],0,'different native source never hydrates');hPreserved($f,'standby variant');
    $f=hFixture();DB::table('whatsapp_webhook_events')->where('id',$f['event'])->update(['payload_hash'=>str_repeat('d',64)]);
    hExpect(hRecover($f)['errors'],1,'raw hash mismatch stops fixed recovery');hPreserved($f,'raw hash');
    foreach(['DISPATCHED','READY'] as $status){$f=hFixture();DB::table('whatsapp_order_drafts')->where('id',$f['draft'])->update(['status'=>$status,'sealed_payload'=>$status==='READY'?'sealed':null]);
        hExpect(hRecover($f)['errors'],1,'protected '.$status.' evidence blocks hydration');hPreserved($f,$status);}
    $f=hFixture();DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->update(['claim_nonce'=>str_repeat('e',64),'lease_until'=>'2026-10-13 11:00:00']);
    $leased=(array)DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->first();
    hExpect(hRecover($f)['errors'],1,'live holder makes entire correction retry later');hPreserved($f,'live analysis');
    hExpect((array)DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->first(),$leased,'holder lease/nonce/counters/backoff untouched');

    $f=hFixture();$other=hEvent(hPayload([hRaw('wamid.OTHER','text',strtotime('2026-10-13 10:10:00 UTC'),['text'=>['body'=>'other']])]));
    DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$other,'reason'=>'INVALID_EVENT','attempts'=>1,'last_attempted_at'=>now('UTC')]);
    hExpect(hRecover($f)['messages_hydrated'],1,'fixed target hydrates alongside unrelated quarantine');
    hExpect(DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$other)->exists(),true,'unrelated global quarantine retained');
    hExpect(DB::table('whatsapp_webhook_events')->where('id',$other)->value('processed_at'),null,'unrelated raw remains unprocessed');

    $f=hFixture([],[],'messages',false);hExpect((new Consumer())->consume(10)['messages_hydrated'],1,'future genuine content copy hydrates automatically');
    $f=hFixture([],[],'messages',false);$json=json_encode(hPayload([$f['full'],hRaw('wamid.HYDRATE','text',strtotime('2026-10-13 10:20:00 UTC'),['text'=>['body'=>'contradiction']])]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    DB::table('whatsapp_webhook_events')->where('id',$f['event'])->update(['payload'=>Crypt::encryptString($json),'payload_hash'=>hash('sha256',$json)]);
    hExpect((new Consumer())->consume(10)['quarantined_events'],1,'mixed contradictory raw batch never partially hydrates');hPreserved($f,'mixed batch');
    $f=hFixture();
    $second=hRaw('wamid.LATER_MEMBER','text',strtotime('2026-10-13 10:10:00 UTC'),['text'=>['body'=>'original second native message']]);
    hEvent(hPayload([$second]));hExpect((new Consumer())->consume(10)['messages_inserted'],1,'second native message genuinely ingested before batch rollback');
    $scanBefore=(array)DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->first();
    $badSecond=$second;$badSecond['text']['body']='contradicting second native message';
    $batch=hEvent(hPayload([$f['full'],$badSecond]));$batchMetrics=(new Consumer())->consume(10);
    hExpect($batchMetrics['quarantined_events'],1,'later persisted member conflict rolls back earlier hydration');
    hPreserved($f,'later batch member');
    hExpect(DB::table('whatsapp_webhook_events')->where('id',$batch)->value('processed_at'),null,'early temporary processed marker rolls back on later conflict');
    hExpect((array)DB::table('whatsapp_order_scans')->where('conversation_id',$f['cid'])->first(),$scanBefore,'early semantic wake/counters roll back on later conflict');
    hExpect(DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$batch)->exists(),true,'only failed batch has new quarantine metadata');

    foreach([[],[0],[1,1],range(1,21),['1']] as $invalid){try{(new Consumer())->recoverUnavailableMessages($invalid);throw new \RuntimeException('unexpected accepted IDs');}catch(\InvalidArgumentException $e){hExpect(true,true,'invalid explicit recovery list rejected');}}
    echo 'WHATSAPP_INBOX_HYDRATION_CHECKS='.$checks."\n";
}
