<?php
namespace App\Services\Dashboard {
    // Explicit trap for the production CRM hooks. A replay must not reach either.
    class CustomerMessageEvents {
        public static int $inbounds=0;public static int $statuses=0;
        public function inbound(array$message):void{self::$inbounds++;}
        public function status(array$status):void{self::$statuses++;}
    }
}
namespace {
require __DIR__.'/whatsapp_inbox_database_test.php';
$migration->up();
require_once dirname(__DIR__).'/app/Services/Dashboard/WhatsAppInboxAccess.php';
require_once dirname(__DIR__).'/app/Services/Dashboard/WhatsAppInboxMessage.php';
require_once dirname(__DIR__).'/app/Services/Dashboard/WhatsAppNativeUnavailableReplay.php';
use App\Services\Dashboard\WhatsAppInboxConsumer as ReplayConsumer;
use App\Services\Dashboard\WhatsAppNativeUnavailableReplay as ReplayProof;
use App\Services\Dashboard\CustomerMessageEvents as ReplayCrm;
use Illuminate\Support\Facades\Crypt as ReplayCrypt;
use Illuminate\Support\Facades\DB as ReplayDB;
$replayChecks=0;
function replayExpect($ok,string$label):void{$GLOBALS['replayChecks']++;if(!$ok)throw new \RuntimeException('UNAVAILABLE_REPLAY_FAILED:'.$label);}
function replayMessage(string$id,int$timestamp=1691583200):array{
    return['id'=>$id,'from'=>'201111111111','from_user_id'=>'US.database1','timestamp'=>(string)$timestamp,
        'type'=>'unsupported','errors'=>[['code'=>131060,'title'=>'Unavailable','message'=>'Unavailable notification']],
        'context'=>['from'=>'201111111111','id'=>'wamid.context'],
        'referral'=>['source_type'=>'ad','source_id'=>'12345','source_url'=>'https://example.com/ad']];
}
function replayRows():array{
    $out=[];foreach(['whatsapp_webhook_events','whatsapp_inbox_conversations','whatsapp_inbox_messages','whatsapp_inbox_ingestion_failures']as$table)
        $out[$table]=ReplayDB::table($table)->orderBy($table==='whatsapp_inbox_ingestion_failures'?'event_id':'id')->get()->map(static fn($r)=>(array)$r)->all();
    return$out;
}
function replayEventProof(int$id):?array{
    $event=ReplayDB::table('whatsapp_webhook_events')->where('id',$id)->first();
    $payload=json_decode(ReplayCrypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR);
    $report=\App\Support\WhatsAppInboxProtocol::report($payload);
    if(count($report['messages'])!==1)return null;
    $key=hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$report['messages'][0]['message_id']);
    $message=ReplayDB::table('whatsapp_inbox_messages')->where('message_key',$key)->first();if(!$message)return null;
    $thread=ReplayDB::table('whatsapp_inbox_conversations')->where('id',$message->conversation_id)->first();
    $proof=ReplayProof::inspect($thread,$message,$event);if($proof===null)return null;
    $failure=ReplayDB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$id)->first();
    if($failure)$proof['failure']=['reason'=>$failure->reason,'attempts'=>(int)$failure->attempts,'last_attempted_at'=>$failure->last_attempted_at];
    return$proof;
}
function replayPrepare(int$count=3):array{
    dbReset();ReplayCrm::$inbounds=ReplayCrm::$statuses=0;$events=[];$proofs=[];
    for($i=0;$i<$count;$i++){
        $message=replayMessage('wamid.replay.'.$i);dbEvent(dbPayload([$message]));$r=dbConsume();
        replayExpect($r['messages_inserted']===1&&$r['errors']===0,'original native unavailable marker inserted');
        $message['timestamp']=(string)(1691583260+$i);$id=dbEvent(dbPayload([$message]));
        ReplayDB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$id,'reason'=>'CONFLICTING_MESSAGE','attempts'=>2,'last_attempted_at'=>'2026-10-17 19:00:00']);
        $proof=replayEventProof($id);replayExpect(is_array($proof),'same timestamp-only marker has complete pure audit proof');
        $events[]=$id;$proofs[$id]=$proof;
    }
    return[$events,$proofs];
}
function replayBlocked(array$events,array$proofs,string$label):void{
    $before=replayRows();$crm=[ReplayCrm::$inbounds,ReplayCrm::$statuses];$blocked=false;
    try{(new ReplayConsumer)->recoverUnavailableReplays($events,$proofs);}catch(\Throwable$error){$blocked=true;}
    replayExpect($blocked,$label.' rejected');replayExpect(replayRows()===$before,$label.' preserves all rows atomically');
    replayExpect($crm===[ReplayCrm::$inbounds,ReplayCrm::$statuses],$label.' invokes no CRM hooks');
}

// Durable ordinary ingestion: keep first native chronology even for a much later
// or earlier resend. Only raw processing metadata changes, never inbox facts.
foreach([1691583260,1691590400,1691583100]as$timestamp){
    dbReset();ReplayCrm::$inbounds=ReplayCrm::$statuses=0;$old=replayMessage('wamid.durable');dbEvent(dbPayload([$old]));dbConsume();
    $before=replayRows();$old['timestamp']=(string)$timestamp;$id=dbEvent(dbPayload([$old]));$raw=ReplayDB::table('whatsapp_webhook_events')->where('id',$id)->first();
    $r=dbConsume();$after=replayRows();
    replayExpect($r['events_processed']===1&&$r['messages_replayed']===1&&$r['messages_inserted']===0&&$r['errors']===0&&$r['quarantined_events']===0,'timestamp-only131060 retransmission replays');
    replayExpect($after['whatsapp_inbox_messages']===$before['whatsapp_inbox_messages']&&$after['whatsapp_inbox_conversations']===$before['whatsapp_inbox_conversations'],'replay leaves ciphertext chronology and customer range byte-identical');
    replayExpect(ReplayCrm::$inbounds===1&&ReplayCrm::$statuses===0,'replayed marker never reapplies CRM inbound consent');
    $done=ReplayDB::table('whatsapp_webhook_events')->where('id',$id)->first();replayExpect($done->payload===$raw->payload&&$done->payload_hash===$raw->payload_hash&&$done->processed_at!==null,'raw notification retained exactly and marked processed');
}
[$events,$proofs]=replayPrepare();$before=replayRows();$crm=[ReplayCrm::$inbounds,ReplayCrm::$statuses];
$result=(new ReplayConsumer)->recoverUnavailableReplays($events,$proofs);$after=replayRows();
replayExpect($result['success']&&$result['writes']===6&&$result['events_processed']===3&&$result['events_skipped']===0,'exact audited batch settles three failures');
replayExpect(count($result['receipts'])===3&&array_column($result['receipts'],'state')===['PROCESSED','PROCESSED','PROCESSED'],'per-event proof receipts returned for private post-audit');
replayExpect($after['whatsapp_inbox_messages']===$before['whatsapp_inbox_messages']&&$after['whatsapp_inbox_conversations']===$before['whatsapp_inbox_conversations']
    &&$after['whatsapp_inbox_ingestion_failures']===[],'recovery settles quarantine without changing any message or conversation');
replayExpect($crm===[ReplayCrm::$inbounds,ReplayCrm::$statuses]&&$result['ai_calls']===0&&$result['message_sends']===0&&$result['erp_writes']===0,'recovery is silent and does not apply CRM operations');
foreach($events as$index=>$id)replayExpect($before['whatsapp_webhook_events'][$index*2+1]['payload']===$after['whatsapp_webhook_events'][$index*2+1]['payload'],'recovery preserves encrypted raw evidence '.$id);
$recorded=replayRows();$again=(new ReplayConsumer)->recoverUnavailableReplays($events,$proofs);
replayExpect($again['writes']===0&&$again['events_skipped']===3&&array_column($again['receipts'],'state')===['ALREADY_PROCESSED','ALREADY_PROCESSED','ALREADY_PROCESSED']
    &&replayRows()===$recorded,'prepared audit supports idempotent all-processed reproof');

foreach(['cipher','payload','failure','unknown-field','wrong-id','wrong-type','unsorted','extra-event']as$guard){
    [$events,$proofs]=replayPrepare(2);$id=$events[1];
    if($guard==='cipher')$proofs[$id]['ciphertext_sha256']=str_repeat('0',64);
    if($guard==='payload')$proofs[$id]['payload_hash']=str_repeat('0',64);
    if($guard==='failure')ReplayDB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$id)->update(['attempts'=>3]);
    if($guard==='unknown-field')$proofs[$id]['force']=true;
    if($guard==='wrong-id')$proofs[$id]['message_id']++;
    if($guard==='wrong-type')$proofs[$id]['conversation_id']=(string)$proofs[$id]['conversation_id'];
    if($guard==='unsorted')$events=array_reverse($events);
    if($guard==='extra-event')$events[]=999999;
    replayBlocked($events,$proofs,'audit '.$guard.' expectation');
}
foreach(['context','referral','error','extra-error','other-code','outbound','text','peer','new-id','extra-body','hydration','hydration-null','bad-raw-hash','stored-time']as$guard){
    [$events,$proofs]=replayPrepare(1);$id=$events[0];$event=ReplayDB::table('whatsapp_webhook_events')->where('id',$id)->first();
    $payload=json_decode(ReplayCrypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR);$message=&$payload['entry'][0]['changes'][0]['value']['messages'][0];
    if($guard==='context')$message['context']['id']='wamid.different-context';
    if($guard==='referral')$message['referral']['source_id']='999';
    if($guard==='error')$message['errors'][0]['message']='Different error data';
    if($guard==='extra-error')$message['errors'][]=['code'=>131060];
    if($guard==='other-code')$message['errors'][0]['code']=131061;
    if($guard==='outbound'){$payload['entry'][0]['changes'][0]['field']='smb_message_echoes';$message['to']=$message['from'];$message['to_user_id']=$message['from_user_id'];$message['from']='201285545554';unset($message['from_user_id']);
        $payload['entry'][0]['changes'][0]['value']['message_echoes']=[$message];unset($payload['entry'][0]['changes'][0]['value']['messages']);}
    if($guard==='text'){$message['type']='text';$message['text']=['body'=>'Full text'];unset($message['errors']);}
    if($guard==='peer')$message['from_user_id']='US.another-user';
    if($guard==='new-id')$message['id']='wamid.different-native-id';
    if($guard==='extra-body')$message['text']=['body'=>'Untrusted hidden text'];
    if(in_array($guard,['hydration','hydration-null'],true)){
        $stored=ReplayDB::table('whatsapp_inbox_messages')->first();$dto=json_decode(ReplayCrypt::decryptString($stored->content),true,512,JSON_THROW_ON_ERROR);$dto['content']['hydration']=$guard==='hydration-null'?null:['version'=>'untrusted'];
        ReplayDB::table('whatsapp_inbox_messages')->where('id',$stored->id)->update(['content'=>ReplayCrypt::encryptString(json_encode($dto))]);
    }
    if($guard==='stored-time')ReplayDB::table('whatsapp_inbox_messages')->update(['sent_at'=>'2023-08-09 10:53:21']);
    unset($message);$json=json_encode($payload,JSON_THROW_ON_ERROR);ReplayDB::table('whatsapp_webhook_events')->where('id',$id)->update(['payload'=>ReplayCrypt::encryptString($json),
        'payload_hash'=>$guard==='bad-raw-hash'?str_repeat('0',64):hash('sha256',$json)]);
    replayExpect(replayEventProof($id)===null,'pure proof rejects '.$guard.' variation');
    replayBlocked($events,$proofs,'native '.$guard.' variation');
}
[$events,$proofs]=replayPrepare(2);$id=$events[1];
ReplayDB::statement('CREATE TRIGGER unavailable_replay_abort BEFORE UPDATE OF processed_at ON whatsapp_webhook_events WHEN NEW.id = '.$id.' BEGIN SELECT RAISE(ABORT, \'isolated replay rollback fixture\'); END');
replayBlocked($events,$proofs,'second event write failure');ReplayDB::statement('DROP TRIGGER unavailable_replay_abort');
echo 'whatsapp_native_unavailable_replay: '.$replayChecks." checks passed\n";
}
