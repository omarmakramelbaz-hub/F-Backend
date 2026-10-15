<?php
// Actual Meta normalizer, encrypted capture, send reservation, projection, workflow and proof.
// AI/Graph/control clock and existing ERP boundaries are isolated fixtures.
require __DIR__.'/whatsapp_order_dialogue_workflow_test.php';
require_once __DIR__.'/../app/Support/MetaAiSchedule.php';

foreach (['Support/MetaMessagingProtocol','Services/Dashboard/MetaInboxAccess','Services/Dashboard/MetaInvalidEvent',
    'Services/Dashboard/MetaInboxConsumer','Services/Dashboard/MetaUploadMedia','Services/Dashboard/MetaReplyService',
    'Services/Dashboard/MetaOrderNativeEvidence','Services/Dashboard/MetaOrderFollowupService','Services/Dashboard/MetaOrderWorkflow'] as $file)
    require_once __DIR__.'/../app/'.$file.'.php';
foreach (glob(__DIR__.'/../database/migrations/2026_10_11_*create_meta*.php') as $file) require_once $file;
foreach (['CreateMetaWebhookEventsTable','CreateMetaInboxTables','CreateMetaOrderDrafts','CreateMetaReplyRequests','CreateMetaInboxReads'] as $klass) (new $klass)->up();
\Illuminate\Support\Facades\Schema::create('meta_ai_controls',function($t){
    $t->integer('id')->primary();$t->integer('revision');$t->boolean('enabled');$t->boolean('schedule_active');
    $t->integer('actor_id');$t->integer('message_floor');$t->integer('event_floor');$t->string('started_at_utc');
});

use Illuminate\Support\Facades\DB as MetaDB;
use Illuminate\Support\Facades\Crypt as MetaCrypt;
use App\Services\Dashboard\MetaOrderWorkflow;
use App\Services\Dashboard\MetaOrderFollowupService;
use App\Services\Dashboard\MetaOrderNativeEvidence;
use App\Services\Dashboard\MetaReplyService;
use App\Services\Dashboard\MetaInboxConsumer;
use App\Services\Dashboard\WhatsAppOrderAiProvider as MetaAi;
use App\Support\WhatsAppOrderExtraction as MetaExtraction;
use App\Support\WhatsAppOrderDialogue as MetaDialogue;

$metaPrimaryChecks=0;$metaGraphs=[];$metaQuestionCallback=null;
function metaPrimaryExpect($ok,string $label): void {
    $GLOBALS['metaPrimaryChecks']++; if(!$ok)throw new RuntimeException('META_PRIMARY_FAILED:'.$label);
}
class MetaPrimaryFixtureControl {
    public static bool $backlogGuard=false;
    public static bool $strictDispatchClock=false;
    public static bool $replyClockClosed=false;
    public function processingGate(string $channel,?string $time=null,?int $id=null,?int $event=null): array {
        $replyClosed=self::$replyClockClosed; self::$replyClockClosed=false;
        try { $gate=$this->gate($channel,$time,$id,$event); }
        finally { self::$replyClockClosed=$replyClosed; }
        if (!$replyClosed||($this->dispatchGate($channel)['effective']??false)) return $gate;
        return array_replace($gate,['effective'=>false,'reason'=>'OUTSIDE_SCHEDULE']);
    }
    public function dispatchGate(string $channel): array {
        $replyClosed=self::$replyClockClosed; self::$replyClockClosed=false;
        try { $gate=$this->gate($channel); } finally { self::$replyClockClosed=$replyClosed; }
        if (self::$strictDispatchClock&&($gate['effective']??false)===true) {
            $clock=\App\Support\MetaAiSchedule::dispatchStatus(['enabled'=>true,'channels'=>[$channel],'schedule'=>['enabled'=>false,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'23:00','all_day'=>true]],new \DateTimeImmutable(now('UTC')->format('Y-m-d H:i:s'),new \DateTimeZone('UTC')));
            if (!$clock['effective']) { $gate['effective']=false; $gate['reason']='DISPATCH_OUTSIDE_HOURS'; }
        }
        return $gate;
    }

    public static array $channels=['messenger','instagram'];
    public function gate(string $channel,?string $time=null,?int $id=null,?int $event=null): array {
        if (!in_array($channel,self::$channels,true)) return ['effective'=>false,'reason'=>'CHANNEL_DISABLED'];
        $row=MetaDB::table('meta_ai_controls')->where('id',1)->first();
        $out=['effective'=>false,'reason'=>'DISABLED','revision'=>$row?(int)$row->revision:null,'actor_id'=>null,'actor'=>null,'floor'=>null];
        if (!$row||!$row->enabled||!$row->schedule_active||self::$replyClockClosed) return $out;
        $actor=MetaDB::table('test_users')->where('id',$row->actor_id)->where('active',1)->first();
        if (!$actor||!$actor->can_checkout) return $out;
        $floor=['message_floor'=>(int)$row->message_floor,'event_floor'=>(int)$row->event_floor,'started_at_utc'=>$row->started_at_utc];
        if (($id!==null||$time!==null)&&($id===null||$time===null||$id<=$floor['message_floor']||$time<$floor['started_at_utc']
            ||$time>now('UTC')->format('Y-m-d H:i:s'))) return $out;
        return array_replace($out,['effective'=>true,'reason'=>null,'actor_id'=>(int)$actor->id,'actor'=>$actor,'floor'=>$floor],self::$backlogGuard?['backlog_version'=>1,'original_activation_started_at_utc'=>'2026-10-09 22:05:00']:[]);
    }
}
class MetaPrimaryFixtureProvider extends MetaAi {
    public static array $contexts=[];
    public function followupQuestion(array $context): array {
        metaPrimaryExpect(MetaDB::connection()->transactionLevel()===0,'Meta question AI outside transaction');
        metaPrimaryExpect(MetaDB::table('meta_reply_requests')->orderByDesc('id')->value('state')==='CLAIMED','Meta reservation precedes AI');
        metaPrimaryExpect(MetaDialogue::validContext($context),'bounded actual menu context');self::$contexts[]=$context;
        if ($GLOBALS['metaQuestionCallback']) ($GLOBALS['metaQuestionCallback'])();
        if (($context['kind']??null)==='PRODUCT_INFORMATION') {
            $choice=MetaDialogue::fallback($context);
            if (($context['facts'][0]['subject']??null)==='هيرنج') $choice=['action'=>'ANSWER','candidate_ids'=>[],
                'fact_ids'=>[$context['facts'][0]['id']],'text'=>'هيرنج ده الاسم الإنجليزي لسمك الرنجة. الاسم لوحده مش بيوضح طريقة التجهيز. تحب أوضح لك حاجة تانية قبل ما نكمل طلبك؟','variant'=>'FRIENDLY'];
            return ['ok'=>true,'choice'=>$choice];
        }
        return ['ok'=>true,'choice'=>['action'=>'ASK','candidate_ids'=>array_slice(array_column($context['candidates'],'id'),0,6),'variant'=>'POLITE']];
    }
    public function followupVariant(array $fields): array { return ['ok'=>true,'variant'=>'SHORT']; }
}
function metaPrimarySetup(): void {
    dialogueSetup();
    MetaPrimaryFixtureControl::$backlogGuard=false; MetaPrimaryFixtureControl::$strictDispatchClock=false; MetaPrimaryFixtureControl::$replyClockClosed=false;
    MetaDB::table('meta_ai_controls')->delete();
    MetaDB::table('meta_ai_controls')->insert(['id'=>1,'revision'=>1,'enabled'=>true,'schedule_active'=>true,
        'actor_id'=>7,'message_floor'=>0,'event_floor'=>0,'started_at_utc'=>'2026-10-09 22:00:00']);
    app('config')->set('whatsapp_orders.enabled',false);app('config')->set('whatsapp_orders.mode','review');
    app()->instance(MetaAi::class,new MetaAi());
    foreach (['meta_reply_requests','meta_order_drafts','meta_order_scans','meta_inbox_ingestion_failures','meta_inbox_messages','meta_inbox_conversations','meta_webhook_events'] as $table) MetaDB::table($table)->delete();
    app('config')->set('meta_orders',array_replace(app('config')->get('whatsapp_orders'),['allowed_branch_ids'=>[]]));
    app('config')->set('meta_cart',app('config')->get('whatsapp_cart'));
    app('config')->set('meta_messaging',['api_version'=>'v25.0','accounts'=>[
        ['channel'=>'messenger','account_id'=>'101','access_token'=>str_repeat('a',24)],
        ['channel'=>'instagram','account_id'=>'202','access_token'=>str_repeat('b',24),'login'=>'instagram']]]);
    app()->instance('App\\Services\\Dashboard\\MetaAiControl',new MetaPrimaryFixtureControl());
    MetaPrimaryFixtureControl::$channels=['messenger','instagram'];MetaPrimaryFixtureProvider::$contexts=[];
    $GLOBALS['metaGraphs']=[];$GLOBALS['metaQuestionCallback']=null;
    $replies=new MetaReplyService(static function($endpoint,$payload,$token):array {
        metaPrimaryExpect(MetaDB::connection()->transactionLevel()===0,'Meta Graph outside transaction');
        metaPrimaryExpect(MetaDB::table('meta_reply_requests')->orderByDesc('id')->value('state')==='UNKNOWN','Meta uncertainty precedes Graph');
        $GLOBALS['metaGraphs'][]=[$endpoint,$payload,$token];
        return ['status'=>200,'body'=>json_encode(['recipient_id'=>$payload['recipient']['id'],'message_id'=>'mid.meta.owned.'.count($GLOBALS['metaGraphs'])])];
    });
    app()->instance(MetaReplyService::class,$replies);
    app()->instance(MetaOrderFollowupService::class,new MetaOrderFollowupService($replies,new MetaPrimaryFixtureProvider()));
    app()->instance(MetaOrderWorkflow::class,new MetaOrderWorkflow());
}
function metaPrimaryMessage(string $channel,string $mid,?string $text,int $second=1,?array $point=null,bool $out=false,string $peer='900'): int {
    $account=$channel==='messenger'?'101':'202';$raw=['mid'=>$mid];if($text!==null)$raw['text']=$text;if($out)$raw['is_echo']=true;
    if($point!==null)$raw['attachments']=[['type'=>'location','payload'=>['coordinates'=>$point]]];
    $payload=['object'=>$channel==='messenger'?'page':'instagram','entry'=>[['id'=>$account,'messaging'=>[[
        'sender'=>['id'=>$out?$account:$peer],'recipient'=>['id'=>$out?$peer:$account],
        'timestamp'=>(strtotime('2026-10-09 22:00:00 UTC')+$second)*1000,'message'=>$raw]]]]];
    $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    MetaDB::table('meta_webhook_events')->insert(['payload_hash'=>hash('sha256',$json),'payload'=>MetaCrypt::encryptString($json),'received_at'=>now('UTC')]);
    $consumed=(new MetaInboxConsumer())->consume();metaPrimaryExpect($consumed['errors']===0,'genuine Meta inbound consumed');
    MetaDB::table('meta_inbox_messages')->update(['created_at'=>'2026-10-09 22:00:00']);
    return (int)MetaDB::table('meta_inbox_conversations')->where('waba_id',$channel)->where('phone_number_id',$account)
        ->where('peer_hash',hash_hmac('sha256','whatsapp-peer-v1:'.$channel.':'.$account.':user:'.$peer,MetaCrypt::getKey()))->value('id');
}
function metaPrimaryRun(): array {
    $config=app('config')->all();$r=app(MetaOrderWorkflow::class)->process();
    metaPrimaryExpect(app('config')->all()===$config,'Meta processing preserves configuration');return $r;
}
function metaPrimaryNoErp(string $label): void {
    metaPrimaryExpect(MetaDB::table('test_tickets')->count()===0&&MetaDB::table('test_print')->count()===0
        &&MetaDB::table('branch_customers')->count()===0&&MetaDB::table('test_billing')->count()===0,$label);
}
function metaPrimaryLastText():string { $graphs=$GLOBALS['metaGraphs'];return $graphs[count($graphs)-1][1]['message']['text']; }

foreach (['messenger','instagram'] as $channel) {
    metaPrimarySetup();$conversation=metaPrimaryMessage($channel,'mid.first','أهلاً');MetaAi::$data=MetaExtraction::none();
    $r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&MetaAi::$calls===1&&count($metaGraphs)===1,'first native '.$channel.' greeting responds');
    metaPrimaryExpect(str_contains(metaPrimaryLastText(),'أهلاً')&&MetaDB::table('meta_order_drafts')->value('status')==='NONE','Meta greeting has no invented basket');
    metaPrimaryNoErp('Meta greeting never executes');metaPrimaryRun();metaPrimaryExpect(count($metaGraphs)===1,'same Meta inbound never sends twice');
    metaPrimaryMessage($channel,'mid.second','ممكن المنيو والأسعار؟',302);dialogueAdvance(303);$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===2,'second genuine '.$channel.' turn continues');
    metaPrimaryExpect(MetaPrimaryFixtureProvider::$contexts[count(MetaPrimaryFixtureProvider::$contexts)-1]['kind']==='PRODUCT_INFORMATION'
        &&str_contains(metaPrimaryLastText(),'الفرع'),'Meta menu price inquiry honestly asks the actual branch');
    metaPrimaryMessage($channel,'mid.third','المنصورة',304);dialogueAdvance(305);$r=metaPrimaryRun();
    $context=MetaPrimaryFixtureProvider::$contexts[count(MetaPrimaryFixtureProvider::$contexts)-1];
    metaPrimaryExpect($r['errors']===0&&$context['kind']==='CATALOG_INFORMATION'&&array_column($context['candidates'],'label')===['فسيخ'],'Meta branch answer lists only actual available catalog');
    metaPrimaryNoErp('Meta menu exchange cannot execute');
}

function metaPrimaryCompleteDraft(string $channel):int {
    metaPrimarySetup();$conversation=metaPrimaryMessage($channel,'mid.order','اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز 1 كيلو فسيخ.');
    metaPrimaryMessage($channel,'mid.pin',null,2,['lat'=>31,'long'=>31.4]);
    MetaAi::$data=extractionData();MetaAi::$data['decision']='DRAFT';
    MetaAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    MetaAi::$data['branch_hint']=null;MetaAi::$data['approximate_total']=null;MetaAi::$data['issues']=['UNCONFIRMED'];
    MetaAi::$data['evidence']=['request_ids'=>['m1'],'confirmation_ids'=>[],'customer_acceptance_ids'=>[],'location_id'=>'m2'];
    $r=metaPrimaryRun();metaPrimaryExpect($r['errors']===0&&count($GLOBALS['metaGraphs'])===1&&str_contains(metaPrimaryLastText(),'تأكيد الطلب:'),'actual '.$channel.' complete request receives own factual recap');
    metaPrimaryNoErp('Meta recap is not approval');
    metaPrimaryMessage($channel,'mid.approval','موافق',302);dialogueAdvance(303);
    MetaAi::$data['decision']='CONFIRMED';MetaAi::$data['issues']=[];MetaAi::$data['evidence']['confirmation_ids']=['m3'];MetaAi::$data['evidence']['customer_acceptance_ids']=['m4'];
    return $conversation;
}
foreach (['messenger','instagram'] as $channel) {
    $conversation=metaPrimaryCompleteDraft($channel);$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&$r['dispatched']===1&&MetaDB::table('test_tickets')->count()===1
        &&MetaDB::table('test_print')->count()===1&&MetaDB::table('test_tickets')->value('payment_status')==='unpaid','actual '.$channel.' own recap and customer approval execute one unpaid ERP and print');
    metaPrimaryRun();metaPrimaryExpect(MetaDB::table('test_tickets')->count()===1&&MetaDB::table('test_print')->count()===1,'Meta approval replay cannot duplicate ERP or print');
    metaPrimaryExpect(MetaDB::table('whatsapp_order_drafts')->count()===0&&MetaDB::table('whatsapp_reply_requests')->count()===0,'Meta automation cannot write WA ledgers');
}

foreach (['native-peer','forged-archive'] as $mutation) {
    $conversation=metaPrimaryCompleteDraft('messenger');
    if ($mutation==='native-peer') {
        $row=MetaDB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')->orderBy('id')->first();
        $native=json_decode(MetaCrypt::decryptString($row->content),true,64,JSON_THROW_ON_ERROR);
        $native['channel']='instagram';$native['account_id']='202';$native['peer_identity']='user:901';$native['peer_user_id']='901';
        MetaDB::table('meta_inbox_messages')->where('id',$row->id)->update(['content'=>MetaCrypt::encryptString(json_encode($native,JSON_THROW_ON_ERROR))]);
    } else {
        $receipt=MetaDB::table('meta_reply_requests')->where('conversation_id',$conversation)->where('state','ACCEPTED')->first();
        $audit=json_decode(MetaCrypt::decryptString($receipt->audit),true,32,JSON_THROW_ON_ERROR);$audit['text'].=' بيانات معدلة';
        MetaDB::table('meta_reply_requests')->where('id',$receipt->id)->update(['audit'=>MetaCrypt::encryptString(json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
    }
    $calls=MetaAi::$calls;metaPrimaryRun();
    metaPrimaryExpect(MetaAi::$calls===$calls,'changed '.$mutation.' is rejected before another AI call');
    metaPrimaryNoErp('changed '.$mutation.' cannot create a customer, ERP or print after apparent approval');
}

foreach (['native','receipt'] as $human) {
    $conversation=metaPrimaryCompleteDraft('instagram');
    if ($human==='native') metaPrimaryMessage('instagram','mid.staff-change','تأكيد الطلب: 2 كيلو فسيخ إلى شارع المثال المنصوره.',301,null,true);
    else {
        MetaDB::table('meta_reply_requests')->insert(['waba_id'=>'instagram','phone_number_id'=>'202','conversation_id'=>$conversation,
            'actor_id'=>7,'request_hash'=>hash('sha256','staff-change'),'payload_hash'=>hash('sha256','staff-change-payload'),'kind'=>'text',
            'inbound_message_id'=>MetaDB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')->min('id'),
            'state'=>'ACCEPTED','audit'=>MetaCrypt::encryptString('{"text":"تأكيد الطلب: 2 كيلو فسيخ"}'),
            'claimed_at'=>'2026-10-09 22:05:01','completed_at'=>'2026-10-09 22:05:01','created_at'=>'2026-10-09 22:05:01','updated_at'=>'2026-10-09 22:05:01']);
    }
    // The real customer approval is later, but the stale model still cites the old 1kg own recap.
    if ($human==='native') MetaAi::$data['evidence']['customer_acceptance_ids']=['m5'];
    $r=metaPrimaryRun();
    metaPrimaryNoErp('staff '.$human.' edit between own recap and customer approval invalidates stale basket consent');
}

foreach (['enabled','schedule_active','revision','actor_id'] as $field) {
    metaPrimarySetup();metaPrimaryMessage('messenger','mid.drift','أهلاً');MetaAi::$data=MetaExtraction::none();
    $GLOBALS['metaQuestionCallback']=static function()use($field){MetaDB::table('meta_ai_controls')->update([$field=>in_array($field,['enabled','schedule_active'],true)?0:($field==='revision'?2:8)]);};
    $r=metaPrimaryRun();metaPrimaryExpect(count($metaGraphs)===0&&MetaDB::table('meta_reply_requests')->value('state')==='FAILED','fresh '.$field.' drift after reserved AI prevents Meta Graph');
    metaPrimaryNoErp('Meta policy drift cannot execute');
}
foreach (['native','receipt'] as $human) {
    metaPrimarySetup();$conversation=metaPrimaryMessage('instagram','mid.handoff','أهلاً');MetaAi::$data=MetaExtraction::none();
    if($human==='native')metaPrimaryMessage('instagram','mid.human','معك الموظف',2,null,true);
    else MetaDB::table('meta_reply_requests')->insert(['waba_id'=>'instagram','phone_number_id'=>'202','conversation_id'=>$conversation,
        'actor_id'=>7,'request_hash'=>hash('sha256','manual'),'payload_hash'=>hash('sha256','manual-payload'),'kind'=>'text',
        'inbound_message_id'=>MetaDB::table('meta_inbox_messages')->value('id'),'state'=>'ACCEPTED',
        'audit'=>MetaCrypt::encryptString('{"text":"معك الموظف"}'),'claimed_at'=>now('UTC'),'completed_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $r=metaPrimaryRun();metaPrimaryExpect(MetaAi::$calls===0&&count($metaGraphs)===0,'actual '.$human.' human takeover suppresses Meta AI');
}
metaPrimarySetup();metaPrimaryMessage('messenger','mid.disabled','أهلاً');metaPrimaryMessage('instagram','mid.enabled','أهلاً');MetaAi::$data=MetaExtraction::none();
MetaPrimaryFixtureControl::$channels=['instagram'];$r=metaPrimaryRun();
metaPrimaryExpect($r['errors']===0&&MetaAi::$calls===1&&count($metaGraphs)===1&&str_contains($metaGraphs[0][0],'graph.instagram.com'),'disabled Messenger does not starve active Instagram');
metaPrimaryNoErp('isolated channels cannot invent orders');
foreach (['messenger','instagram'] as $channel) {
    $conversation=metaPrimaryCompleteDraft($channel);MetaPrimaryFixtureControl::$strictDispatchClock=true;$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&$r['deferred']===1&&MetaDB::table('meta_order_drafts')->orderByDesc('id')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
        'native '.$channel.' approved delivery is queued at01:05 Cairo without ERP');
    metaPrimaryNoErp('Meta after-hours approval waits');$calls=MetaAi::$calls;$graphs=count($metaGraphs);
    MetaPrimaryFixtureControl::$replyClockClosed=true;
    \Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');
    $r=metaPrimaryRun();metaPrimaryExpect($r['errors']===0&&$r['dispatched']===1&&MetaAi::$calls===$calls&&count($metaGraphs)===$graphs
        &&MetaDB::table('test_tickets')->count()===1&&MetaDB::table('test_print')->count()===1,
        'native '.$channel.'09:00 queued approval dispatches with separate reply schedule closed and no repeated AI');
    metaPrimaryRun();metaPrimaryExpect(MetaDB::table('test_tickets')->count()===1&&MetaDB::table('test_print')->count()===1,
        'native '.$channel.' queued replay never duplicates ticket or print');

    metaPrimarySetup();$conversation=metaPrimaryMessage($channel,'mid.old.cached','عايز فسيخ');MetaAi::$data=MetaExtraction::none();
    MetaDB::table('meta_ai_controls')->update(['enabled'=>false]);app(MetaOrderWorkflow::class)->analyze($conversation,(object)['id'=>7]);
    $calls=MetaAi::$calls;$ceiling=MetaDB::table('meta_inbox_messages')->max('id');
    MetaDB::table('meta_order_scans')->where('conversation_id',$conversation)->update(['auto_floor'=>$ceiling,'attempts'=>3]);
    MetaDB::table('meta_ai_controls')->update(['enabled'=>true,'revision'=>2]);$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&MetaAi::$calls===$calls&&count($metaGraphs)===1,
        'native '.$channel.' cached NONE unanswered turn survives legacy higher scan floor without another extraction');
    metaPrimaryNoErp('Meta backlog clarification cannot dispatch guessed orders');
}
metaPrimarySetup();metaPrimaryMessage('instagram','mid.only.erp.open','أهلاً');MetaAi::$data=MetaExtraction::none();
MetaPrimaryFixtureControl::$strictDispatchClock=true;MetaPrimaryFixtureControl::$replyClockClosed=true;
\Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');metaPrimaryRun();
metaPrimaryExpect(MetaAi::$calls===0&&count($metaGraphs)===0,'Meta dispatch-only open window cannot analyze or reply to a new inbound');

foreach (['messenger','instagram'] as $channel) {
    metaPrimarySetup();metaPrimaryMessage($channel,'mid.info.fresh-meaning','يعني ايه رنجه هيرنج');MetaAi::$data=MetaExtraction::none();$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===1&&str_contains(metaPrimaryLastText(),'الاسم الإنجليزي'),
        'actual '.$channel.' first informational turn answers the general meaning without a branch or invented order');
    metaPrimaryNoErp('Meta first definition never starts an order');
    metaPrimarySetup();metaPrimaryMessage($channel,'mid.info.faq-hours','بتجهزوا امتى؟');MetaAi::$data=MetaExtraction::none();$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===1&&str_contains(metaPrimaryLastText(),'10:00')&&str_contains(metaPrimaryLastText(),'01:00'),
        'actual '.$channel.' approved preparation hours are knowledge only and preserve existing control configuration');
    metaPrimaryNoErp('Meta FAQ hours do not authorize order execution');

    metaPrimarySetup();metaPrimaryMessage($channel,'mid.info.polite-purchase','عايز فسيخ تيك أواي من المنصورة؟');
    MetaAi::$data=MetaExtraction::none('MISSING_QUANTITY');MetaAi::$data['decision']='DRAFT';
    MetaAi::$data['branch_hint']='المنصورة';MetaAi::$data['fulfillment']='PICKUP';
    MetaAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
    MetaAi::$data['evidence']['request_ids']=['m1'];$r=metaPrimaryRun();
    $context=MetaPrimaryFixtureProvider::$contexts[count(MetaPrimaryFixtureProvider::$contexts)-1];
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===1&&$context['field']==='QUANTITY'&&$context['kind']==='MISSING',
        'actual '.$channel.' courteous purchase question follows normal missing quantity instead of product explanation');
    metaPrimaryNoErp('Meta courteous purchase is not order approval');

    metaPrimarySetup();$conversation=metaPrimaryMessage($channel,'mid.info.initial','عايز رنجه تيك أواي من المنصورة');
    DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'رنجة هيرنج','available'=>true,'quantity_mode'=>'weight','price'=>'220.00','options'=>[]],
        ['id'=>12,'name'=>'رنجة هيرنج مطبخ','available'=>true,'quantity_mode'=>'weight','price'=>'240.00','options'=>[]]];
    MetaAi::$data=MetaExtraction::none('MISSING_QUANTITY');MetaAi::$data['decision']='DRAFT';
    MetaAi::$data['branch_hint']='المنصورة';MetaAi::$data['fulfillment']='PICKUP';
    MetaAi::$data['items']=[['name'=>'رنجه','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
    MetaAi::$data['evidence']['request_ids']=['m1'];$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&MetaPrimaryFixtureProvider::$contexts[0]['kind']==='PRODUCT_CHOICE','actual '.$channel.' choice precedes meaning request');
    $basket=MetaAi::$data;metaPrimaryMessage($channel,'mid.info.meaning','يعني ايه رنجه هيرنج',302);dialogueAdvance(303);$r=metaPrimaryRun();
    $context=MetaPrimaryFixtureProvider::$contexts[count(MetaPrimaryFixtureProvider::$contexts)-1];
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===2&&$context['kind']==='PRODUCT_INFORMATION'
        &&str_contains(metaPrimaryLastText(),'الاسم الإنجليزي')&&!str_contains(metaPrimaryLastText(),'اكتب اسم الصنف'),
        'actual '.$channel.' screenshot question receives a natural explanation instead of repeating choices');
    $stored=json_decode(MetaCrypt::decryptString(MetaDB::table('meta_order_drafts')->orderByDesc('id')->value('extraction')),true);
    metaPrimaryExpect($stored===$basket,'actual '.$channel.' explanation preserves stored basket and missing quantities');
    metaPrimaryNoErp('Meta information is never purchase approval');$graphs=count($metaGraphs);metaPrimaryRun();
    metaPrimaryExpect(count($metaGraphs)===$graphs,'same '.$channel.' meaning inbound cannot be answered twice');
    metaPrimaryMessage($channel,'mid.info.selected','عايز رنجة هيرنج',304);dialogueAdvance(305);
    MetaAi::$data['items'][0]['name']='رنجة هيرنج';MetaAi::$data['evidence']['request_ids']=['m1','m5'];$r=metaPrimaryRun();
    $context=MetaPrimaryFixtureProvider::$contexts[count(MetaPrimaryFixtureProvider::$contexts)-1];
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===3&&$context['kind']==='MISSING'&&$context['field']==='QUANTITY',
        'actual '.$channel.' affirmative product choice after explanation resumes the quantity question');
    metaPrimaryNoErp('Meta selected product still needs genuine amount and later basket consent');

    metaPrimarySetup();metaPrimaryMessage($channel,'mid.info.price','رنجة هيرنج بكام في المنصورة؟');MetaAi::$data=MetaExtraction::none();
    DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'رنجة هيرنج','available'=>true,'quantity_mode'=>'weight','price'=>'220.00','options'=>[]]];
    $GLOBALS['metaQuestionCallback']=static function(){DialogueFixtureCatalog::$products[0]['price']='260.00';};$r=metaPrimaryRun();
    metaPrimaryExpect($r['errors']===0&&count($metaGraphs)===0&&MetaDB::table('meta_reply_requests')->value('state')==='FAILED',
        'actual '.$channel.' live price change during composition prevents stale Graph reply');
    metaPrimaryNoErp('Meta price drift cannot create an order');

    $conversation=metaPrimaryCompleteDraft($channel);metaPrimaryMessage($channel,'mid.info.cached-confirmed','يعني ايه فسيخ',304);dialogueAdvance(305);
    app(MetaOrderWorkflow::class)->analyze($conversation,(object)['id'=>7]);$calls=MetaAi::$calls;$r=metaPrimaryRun();
    $context=MetaPrimaryFixtureProvider::$contexts[count(MetaPrimaryFixtureProvider::$contexts)-1];
    metaPrimaryExpect($r['errors']===0&&$r['dispatched']===0&&MetaAi::$calls===$calls&&$context['kind']==='PRODUCT_INFORMATION',
        'actual '.$channel.' cached CONFIRMED basket is not executed while customer asks for explanation');
    metaPrimaryNoErp('Meta information veto protects cached approved ERP work');
}

echo 'META_ORDER_PRIMARY_AI_WORKFLOW_TESTS='.$metaPrimaryChecks." PASS\n";
