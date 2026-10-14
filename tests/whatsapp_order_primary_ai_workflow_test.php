<?php
// The real workflow, native parser, extraction, reply ledger and accepted projection run
// against SQLite. Only AI, Graph, control clock/policy and ERP boundaries are fixtures.
require __DIR__.'/whatsapp_order_dialogue_workflow_test.php';
require_once __DIR__.'/../app/Support/MetaAiSchedule.php';


use App\Services\Dashboard\WhatsAppOrderWorkflow as PrimaryWorkflow;
use App\Services\Dashboard\WhatsAppOrderAiProvider as PrimaryAi;
use App\Services\Dashboard\PhoneDelivery as PrimaryDelivery;
use App\Services\Dashboard\PosServiceTicket as PrimaryPos;
use App\Support\WhatsAppOrderExtraction as PrimaryExtraction;
use Illuminate\Support\Facades\DB as PrimaryDB;
use Illuminate\Support\Facades\Schema as PrimarySchema;
use Illuminate\Support\Facades\Crypt as PrimaryCrypt;

$primaryChecks=0;
function primaryExpect($ok,string $label): void {
    $GLOBALS['primaryChecks']++;
    if (!$ok) throw new RuntimeException('PRIMARY_AI_FAILED:'.$label);
}
class PrimaryFixtureControl {
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

    public static int $reads=0;
    public function gate(string $channel,?string $time=null,?int $id=null,?int $event=null): array {
        self::$reads++;
        $row=PrimaryDB::table('meta_ai_controls')->where('id',1)->first();
        $out=['effective'=>false,'reason'=>'DISABLED','revision'=>$row?(int)$row->revision:null,
            'actor_id'=>null,'actor'=>null,'floor'=>null];
        if (!$row||!$row->enabled||!$row->schedule_active||self::$replyClockClosed||$channel!=='whatsapp') return $out;
        $actor=PrimaryDB::table('test_users')->where('id',$row->actor_id)->where('active',1)->first();
        if (!$actor||!$actor->can_checkout) return $out;
        $floor=['message_floor'=>(int)$row->message_floor,'event_floor'=>(int)$row->event_floor,'started_at_utc'=>$row->started_at_utc];
        if (($id!==null||$time!==null)&&($id===null||$time===null||$id<=$floor['message_floor']
            ||$time<$floor['started_at_utc']||$time>now('UTC')->format('Y-m-d H:i:s'))) return $out;
        return array_replace($out,['effective'=>true,'reason'=>null,'actor_id'=>(int)$actor->id,'actor'=>$actor,'floor'=>$floor],self::$backlogGuard?['backlog_version'=>1,'original_activation_started_at_utc'=>'2026-10-09 22:05:00']:[]);
    }
}
PrimarySchema::create('meta_ai_controls',function($t){
    $t->integer('id')->primary();$t->integer('revision');$t->boolean('enabled');$t->boolean('schedule_active');
    $t->integer('actor_id');$t->integer('message_floor');$t->integer('event_floor');$t->string('started_at_utc');
});
app()->instance('App\\Services\\Dashboard\\MetaAiControl',new PrimaryFixtureControl());
function primarySetup(): void {
    dialogueSetup();
    PrimaryFixtureControl::$backlogGuard=false; PrimaryFixtureControl::$strictDispatchClock=false; PrimaryFixtureControl::$replyClockClosed=false;
    PrimaryDB::table('meta_ai_controls')->delete();
    PrimaryDB::table('meta_ai_controls')->insert(['id'=>1,'revision'=>1,'enabled'=>true,'schedule_active'=>true,
        'actor_id'=>7,'message_floor'=>0,'event_floor'=>0,'started_at_utc'=>'2026-10-09 22:00:00']);
    app('config')->set('whatsapp_orders.enabled',false);
    app('config')->set('whatsapp_orders.mode','review');
    app('config')->set('whatsapp_orders.automation_actor_id',8);
    app('config')->set('whatsapp_orders.activation_message_id',null);
    app('config')->set('whatsapp_orders.activation_event_id',null);
    app('config')->set('whatsapp_cart.activated_at',null);
    app()->instance(PrimaryAi::class,new PrimaryAi());
    PrimaryFixtureControl::$reads=0;
    app()->instance(PrimaryDelivery::class,new PrimaryDelivery());
}
function primaryInbound(string $text='أهلاً'): int {
    $c=addConversation('US.primary-fixture');
    insertMessage($c,'wamid.primary.first','inbound',$text,1,null,'US.primary-fixture');
    PrimaryAi::$data=PrimaryExtraction::none();
    return $c;
}
function primaryRun(): array {
    global $workflow;
    $config=app('config')->all();$result=$workflow->process();
    primaryExpect(app('config')->all()===$config,'primary authority never mutates original configuration');
    return $result;
}
function primaryNoErp(string $label): void {
    primaryExpect(PrimaryDB::table('test_tickets')->count()===0&&PrimaryDB::table('test_print')->count()===0
        &&PrimaryDB::table('branch_customers')->count()===0&&PrimaryDB::table('test_billing')->count()===0,$label);
}

primarySetup(); $c=primaryInbound();$r=primaryRun();
primaryExpect($r['errors']===0&&PrimaryAi::$calls===1,'first authenticated greeting is analyzed without a merchant turn');
primaryExpect(count($dialogueGraphs)===1&&str_contains(dialogueLastText(),'أهلاً')
    &&dialogueLastContext()['kind']==='GREETING','first greeting receives a useful welcome without an invented order');
primaryExpect(PrimaryDB::table('whatsapp_order_drafts')->value('status')==='NONE','greeting remains NONE in persisted order facts');
primaryNoErp('greeting creates no customer, ticket, kitchen or payment');
$before=count($dialogueGraphs);primaryRun();
primaryExpect(count($dialogueGraphs)===$before,'same initial inbound cannot receive a second automatic response');
$state=$workflow->state($c,(object)['id'=>7]);
primaryExpect($state['enabled']===true&&$state['mode']==='auto'&&$state['control']['revision']===1,
    'workflow state reflects fresh primary control rather than legacy disabled config');

primarySetup();$c=primaryInbound('عايز فسيخ');
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m1'];$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&count(DialogueFixtureProvider::$contexts)===1,
    'first purchase DRAFT asks missing information without requiring merchant handoff');
primaryExpect(dialogueLastContext()['field']==='BRANCH','first unresolved purchase asks city before collecting PII');
primaryNoErp('first incomplete order never executes');

primarySetup();$c=primaryInbound('عايز فسيخ من المنصورة');
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['branch_hint']='المنصورة';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m1'];primaryRun();
primaryExpect(dialogueLastContext()['field']==='FULFILLMENT','known city asks fulfillment before delivery address');
primaryNoErp('unknown fulfillment remains question-only');

primarySetup();$c=primaryInbound('عايز فسيخ تيك أواي من المنصورة');
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['branch_hint']='المنصورة';PrimaryAi::$data['fulfillment']='PICKUP';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m1'];primaryRun();
primaryExpect(dialogueLastContext()['field']==='QUANTITY'&&!str_contains(dialogueLastText(),'عنوان'),
    'explicit pickup asks product quantity without demanding delivery address');
primaryNoErp('pickup clarification cannot bypass existing ERP review');

primarySetup();$c=primaryInbound();primaryRun();
insertMessage($c,'wamid.primary.fast-order','inbound','عايز فسيخ',302,null,'US.primary-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:05:03');\Carbon\CarbonImmutable::setTestNow('2026-10-09 22:05:03');
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m3'];
$r=primaryRun();
primaryExpect($r['errors']===0&&PrimaryAi::$calls===2&&count($dialogueGraphs)===2&&dialogueLastContext()['field']==='BRANCH',
    'genuine native answer two seconds after own question bypasses old backoff without waiting sixty seconds');

foreach (['enabled','schedule_active'] as $field) {
    primarySetup();$c=primaryInbound();PrimaryDB::table('meta_ai_controls')->update([$field=>false]);$r=primaryRun();
    primaryExpect($r['disabled']===1&&PrimaryAi::$calls===0&&count($dialogueGraphs)===0,$field.' OFF blocks extraction and Graph');
    primaryNoErp($field.' OFF has no ERP writes');
}
primarySetup();$c=primaryInbound();PrimaryDB::table('meta_ai_controls')->delete();$r=primaryRun();
primaryExpect($r['disabled']===1&&PrimaryAi::$calls===0,'installed table with absent policy fails closed');

foreach (['enabled','schedule_active','revision','actor_id'] as $field) {
    primarySetup();$c=primaryInbound();
    PrimaryAi::$callback=static function()use($field){PrimaryDB::table('meta_ai_controls')->update([$field=>in_array($field,['enabled','schedule_active'],true)?0:($field==='revision'?2:8)]);};
    $r=primaryRun();
    primaryExpect(PrimaryAi::$calls===1&&PrimaryDB::table('whatsapp_order_drafts')->count()===0&&count($dialogueGraphs)===0,
        $field.' changes while extraction is in flight reject the AI result');
    primaryNoErp($field.' drift while extraction has no ERP writes');
}
foreach (['enabled','schedule_active','revision','actor_id'] as $field) {
    primarySetup();$c=primaryInbound();
    DialogueFixtureProvider::$duringQuestion=static function()use($field){PrimaryDB::table('meta_ai_controls')->update([$field=>in_array($field,['enabled','schedule_active'],true)?0:($field==='revision'?2:8)]);};
    $r=primaryRun();
    primaryExpect(count(DialogueFixtureProvider::$contexts)===1&&count($dialogueGraphs)===0,
        $field.' changes during reserved question AI prevent Graph');
    primaryExpect(PrimaryDB::table('whatsapp_reply_requests')->value('state')==='FAILED',
        $field.' drift preserves one terminal no-send reservation');
    primaryNoErp($field.' question drift has no ERP');
}

foreach (['native','receipt','pending'] as $human) {
    primarySetup();$c=primaryInbound();
    if ($human==='native') insertMessage($c,'wamid.primary.manual','outbound','أهلاً، مع حضرتك الموظف.',2,null,'US.primary-fixture');
    else {
        $now=now('UTC')->format('Y-m-d H:i:s');
        PrimaryDB::table('whatsapp_reply_requests')->insert(['waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
            'conversation_id'=>$c,'actor_id'=>7,'request_hash'=>hash('sha256','manual-'.$human),'payload_hash'=>hash('sha256','manual-payload'),
            'kind'=>'text','inbound_message_id'=>PrimaryDB::table('whatsapp_inbox_messages')->value('id'),
            'state'=>$human==='pending'?'CLAIMED':'ACCEPTED','audit'=>PrimaryCrypt::encryptString(json_encode(['client_request_id'=>'a0000000-0000-4000-8000-000000000000','text'=>'human'])),
            'claimed_at'=>$now,'completed_at'=>$human==='pending'?null:$now,'created_at'=>$now,'updated_at'=>$now]);
    }
    $r=primaryRun();
    primaryExpect(PrimaryAi::$calls===0&&count($dialogueGraphs)===0,'human '.$human.' takeover stops primary AI before extraction');
}

primarySetup();$c=primaryInbound();PrimaryDB::table('meta_ai_controls')->update(['message_floor'=>PrimaryDB::table('whatsapp_inbox_messages')->value('id')]);$r=primaryRun();
primaryExpect(PrimaryAi::$calls===0&&count($dialogueGraphs)===0,'activation floor excludes already present inbound');
primarySetup();$c=primaryInbound();PrimaryDB::table('meta_ai_controls')->update(['started_at_utc'=>'2026-10-09 22:00:02']);$r=primaryRun();
primaryExpect(PrimaryAi::$calls===0&&count($dialogueGraphs)===0,'late projected pre-activation timestamp cannot start primary AI');
primarySetup();$c=primaryInbound('الغاء الطلب');$r=primaryRun();
primaryExpect(PrimaryAi::$calls===0&&count($dialogueGraphs)===0,'native cancellation cannot become a first clarification');

primarySetup();$c=primaryInbound('ممكن المنيو والأسعار؟');$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['field']==='BRANCH',
    'first menu inquiry asks the actual branch before showing catalog');
insertMessage($c,'wamid.primary.branch-answer','inbound','المنصورة',302,null,'US.primary-fixture');dialogueAdvance(303);
$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===2&&dialogueLastContext()['kind']==='CATALOG_INFORMATION'
    &&array_column(dialogueLastContext()['candidates'],'label')===['فسيخ'],
    'native branch reply shows only the actual available branch catalog without invented order facts');
primaryNoErp('catalog inquiry is not an order');
primaryExpect(PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('status')==='NONE',
    'menu information retains NONE persisted decision');

primarySetup();$c=primaryInbound('بكام الرنجة في المنصورة؟');
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','options'=>[]],
    ['id'=>12,'name'=>'رنجة','available'=>false,'quantity_mode'=>'weight','options'=>[]]];
$r=primaryRun();
primaryExpect($r['errors']===0&&dialogueLastContext()['kind']==='CATALOG_INFORMATION'
    &&array_column(dialogueLastContext()['candidates'],'label')===['فسيخ']&&!preg_match('/[0-9]/u',dialogueLastText()),
    'price inquiry uses actual available names and never guesses missing price or units');
primaryNoErp('price inquiry cannot create an order');

function primaryCompleteDraft(): int {
    primarySetup();$c=addConversation('US.primary-complete');
    insertMessage($c,'wamid.primary.order','inbound','اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز 1 كيلو فسيخ.',1,null,'US.primary-complete');
    insertMessage($c,'wamid.primary.pin','inbound','',2,['lat'=>31.00,'long'=>31.40],'US.primary-complete');
    PrimaryAi::$data=extractionData();PrimaryAi::$data['decision']='DRAFT';
    PrimaryAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    PrimaryAi::$data['branch_hint']=null;PrimaryAi::$data['approximate_total']=null;
    PrimaryAi::$data['issues']=['UNCONFIRMED'];
    PrimaryAi::$data['evidence']=['request_ids'=>['m1'],'confirmation_ids'=>[],'customer_acceptance_ids'=>[],'location_id'=>'m2'];
    $r=primaryRun();
    primaryExpect($r['errors']===0&&count($GLOBALS['dialogueGraphs'])===1&&str_contains(dialogueLastText(),'تأكيد الطلب:'),
        'first complete native request receives a factual recap before any ERP');
    primaryNoErp('recap is not customer approval');
    insertMessage($c,'wamid.primary.approval','inbound','موافق',302,null,'US.primary-complete');dialogueAdvance(303);
    PrimaryAi::$data['decision']='CONFIRMED';PrimaryAi::$data['issues']=[];
    PrimaryAi::$data['evidence']['confirmation_ids']=['m3'];PrimaryAi::$data['evidence']['customer_acceptance_ids']=['m4'];
    return $c;
}
$c=primaryCompleteDraft();$r=primaryRun();
primaryExpect($r['errors']===0&&$r['dispatched']===1&&PrimaryDB::table('test_tickets')->count()===1
    &&PrimaryDB::table('test_print')->count()===1&&PrimaryDB::table('test_tickets')->value('payment_status')==='unpaid',
    'primary first-turn recap plus actual customer approval executes exactly one unpaid order');
primaryRun();primaryExpect(PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,
    'primary approval replay does not duplicate order or print');

class PrimaryDriftingDelivery extends PrimaryDelivery {
    public static $afterQuote;
    public function quote(array $values,$actor): array {
        $result=parent::quote($values,$actor);
        if (self::$afterQuote) (self::$afterQuote)();
        return $result;
    }
}
foreach (['enabled','schedule_active','revision'] as $field) {
    $c=primaryCompleteDraft();$quotes=0;
    app()->instance(PrimaryDelivery::class,new PrimaryDriftingDelivery());
    PrimaryDriftingDelivery::$afterQuote=static function()use(&$quotes,$field){$quotes++;if($quotes===2)PrimaryDB::table('meta_ai_controls')->update([$field=>$field==='revision'?2:0]);};
    $r=primaryRun();
    primaryExpect($quotes>=2&&PrimaryDB::table('test_tickets')->count()===0&&PrimaryDB::table('branch_customers')->count()===0,
        $field.' change at final quote prevents customer and ERP writes');
}

primarySetup();
for ($old=0;$old<12;$old++) {
    $peer='US.primary-old-'.$old;$oldConversation=addConversation($peer);
    insertMessage($oldConversation,'wamid.primary.old.'.$old,'inbound','أهلاً',1,null,$peer);
}
PrimaryDB::table('meta_ai_controls')->update(['message_floor'=>PrimaryDB::table('whatsapp_inbox_messages')->max('id')]);
$c=primaryInbound();$r=primaryRun();
primaryExpect($r['threads_seen']===1&&PrimaryAi::$calls===1&&count($dialogueGraphs)===1,
    'more than the scan limit of pre-activation threads cannot starve the fresh customer');

primarySetup();
for ($old=0;$old<12;$old++) {
    $peer='US.primary-business-old-'.$old;$oldConversation=addConversation($peer);
    insertMessage($oldConversation,'wamid.primary.business-old.in.'.$old,'inbound','أهلاً',1,null,$peer);
    insertMessage($oldConversation,'wamid.primary.business-old.out.'.$old,'outbound','رد الموظف',2,null,$peer);
}
PrimaryDB::table('meta_ai_controls')->update(['message_floor'=>0,'started_at_utc'=>'2026-10-09 22:00:00']);
$c=primaryInbound();$r=primaryRun();
primaryExpect($r['threads_seen']===1&&PrimaryAi::$calls===1&&count($dialogueGraphs)===1,
    'more than the scan limit of business-ending threads cannot starve a fresh first inbound');

primarySetup();$c=primaryInbound();
DialogueFixtureProvider::$duringQuestion=static function(){PrimaryDB::table('meta_ai_controls')->update(['enabled'=>false]);};
primaryRun();PrimaryDB::table('meta_ai_controls')->update(['enabled'=>true,'revision'=>2]);
$row=PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();
$method=new ReflectionMethod($workflow,'missingInformationPlan');$method->setAccessible(true);
$primaryPlan=$method->invoke($workflow,(int)$row->id,(object)['id'=>7]);
primaryExpect(is_array($primaryPlan),'synthetic current primary plan is reviewable');
$legacyPlan=$primaryPlan;unset($legacyPlan['primary_ai']);PrimaryDB::table('meta_ai_controls')->update(['enabled'=>false]);
primaryExpect(!app(\App\Services\Dashboard\WhatsAppOrderFollowupService::class)->handoffAllowed($legacyPlan),
    'installed master OFF rejects captured legacy plan without primary authority');

// An approval after an employee's intervening turn cannot approve an older AI recap.
$c=primaryCompleteDraft();
insertMessage($c,'wamid.primary.manual-change','outbound','تأكيد الطلب: 2 كيلو فسيخ إلى شارع المثال المنصوره',301,null,'US.primary-complete');
PrimaryAi::$data['evidence']['customer_acceptance_ids']=['m5'];
$r=primaryRun();
primaryExpect($r['dispatched']===0&&PrimaryPos::$saveCalls===0,
    'own 1kg recap followed by human 2kg summary and موافق cannot execute stale 1kg');
primaryNoErp('intervening native human recap has no ERP writes');
foreach (['CLAIMED','ACCEPTED'] as $manualState) {
    $c=primaryCompleteDraft();$stamp='2026-10-09 22:05:01';
    PrimaryDB::table('whatsapp_reply_requests')->insert(['waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
        'conversation_id'=>$c,'actor_id'=>7,'request_hash'=>hash('sha256','manual-after-recap-'.$manualState),
        'payload_hash'=>hash('sha256','manual-after-recap-payload'),'kind'=>$manualState==='ACCEPTED'?'audio':'text',
        'inbound_message_id'=>PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->where('direction','inbound')->orderBy('id')->value('id'),
        'state'=>$manualState,'audit'=>PrimaryCrypt::encryptString(json_encode(['client_request_id'=>'a0000000-0000-4000-8000-000000000001','text'=>'manual employee turn'])),
        'claimed_at'=>$stamp,'completed_at'=>$manualState==='ACCEPTED'?$stamp:null,'created_at'=>$stamp,'updated_at'=>$stamp]);
    $r=primaryRun();
    primaryExpect($r['dispatched']===0&&PrimaryPos::$saveCalls===0,
        'manual '.$manualState.' receipt after recap but before later customer approval vetoes stale recap without native echo');
    primaryNoErp('manual no-echo takeover since recap has no ERP writes');
}

// Explicitly authorized unanswered backlog uses the current control floor, even when
// an older worker stored a higher auto_floor. Reuse safe cached extraction once.
foreach (['NONE','INVALID_EXTRACTION'] as $cachedKind) {
    primarySetup();$c=primaryInbound('عايز فسيخ');
    PrimaryDB::table('meta_ai_controls')->update(['enabled'=>false]);
    if ($cachedKind==='INVALID_EXTRACTION') { PrimaryAi::$failure=true;PrimaryAi::$failureReason='INVALID_EVIDENCE'; }
    $workflow->analyze($c,(object)['id'=>7]);PrimaryAi::$failure=false;
    $calls=PrimaryAi::$calls;$ceiling=(int)PrimaryDB::table('whatsapp_inbox_messages')->max('id');
    PrimaryDB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>$ceiling,'attempts'=>3]);
    PrimaryDB::table('meta_ai_controls')->update(['enabled'=>true,'revision'=>2]);
    $r=primaryRun();
    primaryExpect($r['errors']===0&&PrimaryAi::$calls===$calls&&count($dialogueGraphs)===1,
        'authorized cached '.$cachedKind.' unanswered turn receives clarification without repeated extraction');
    primaryExpect((int)PrimaryDB::table('whatsapp_order_scans')->value('auto_floor')===0,
        'owner-approved lower backlog cutoff supersedes the legacy scan watermark');
    primaryRun();primaryExpect(PrimaryAi::$calls===$calls&&count($dialogueGraphs)===1,
        'cached '.$cachedKind.' reply ledger prevents repeated response');
    primaryNoErp('cached unanswered clarification does not execute a guessed order');
}

primarySetup();$c=primaryInbound();
PrimaryDB::table('whatsapp_order_scans')->insert(['conversation_id'=>$c,'analyzed_ceiling'=>0,'claimed_ceiling'=>0,'attempts'=>2,
    'auto_floor'=>100,'claim_nonce'=>str_repeat('a',64),'lease_until'=>'2026-10-09 23:00:00','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$lease=PrimaryDB::table('whatsapp_order_scans')->first();primaryRun();$afterLease=PrimaryDB::table('whatsapp_order_scans')->first();
primaryExpect($afterLease->claim_nonce===$lease->claim_nonce&&$afterLease->lease_until===$lease->lease_until
    &&(int)$afterLease->attempts===2&&(int)$afterLease->auto_floor===100&&PrimaryAi::$calls===0,
    'authorizing backlog never resets another worker live lease or attempt history');

// Cheap native-window and answered predicates run before the bounded thread limit.
primarySetup();
for ($old=0;$old<12;$old++) {
    $peer='US.primary-expired-'.$old;$expired=addConversation($peer);
    insertMessage($expired,'wamid.primary.expired.'.$old,'inbound','أهلاً',1,null,$peer);
    PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$expired)->update(['sent_at'=>'2026-10-08 22:05:00']);
}
PrimaryDB::table('meta_ai_controls')->update(['started_at_utc'=>'2026-10-08 20:00:00']);$c=primaryInbound();$r=primaryRun();
primaryExpect($r['threads_seen']===1&&PrimaryAi::$calls===1&&count($dialogueGraphs)===1,
    'more than the bounded limit of expired unanswered turns cannot starve a fresh eligible backlog turn');

// Default dispatch hours are distinct from reply hours. The queued approval remains
// current and is repriced next morning without another extraction or customer turn.
$c=primaryCompleteDraft();PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
primaryExpect($r['errors']===0&&$r['deferred']===1&&PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    '01:05 Cairo approved delivery is durably deferred outside default dispatch hours');
primaryNoErp('outside dispatch hours writes no customer, ticket, payment or printer');
$calls=PrimaryAi::$calls;$graphs=count($dialogueGraphs);
PrimaryFixtureControl::$replyClockClosed=true;
\Carbon\Carbon::setTestNow('2026-10-10 05:59:59');\Carbon\CarbonImmutable::setTestNow('2026-10-10 05:59:59');
$r=primaryRun();primaryExpect(PrimaryAi::$calls===$calls&&count($dialogueGraphs)===$graphs&&PrimaryPos::$saveCalls===0,
    'both closed schedules cannot extract, reply or execute at 08:59:59 Cairo');
\Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');
$r=primaryRun();primaryExpect($r['errors']===0&&$r['dispatched']===1&&PrimaryAi::$calls===$calls
    &&count($dialogueGraphs)===$graphs&&PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,
    '09:00 Cairo dispatch-only window rechecks cached approval and queues one actual unpaid ERP order without AI or customer replies');
primaryRun();primaryExpect(PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,
    'morning queued approval retry cannot duplicate ticket or printing');

primarySetup();$c=primaryInbound();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
primaryExpect(count($dialogueGraphs)===1&&PrimaryAi::$calls===1,
    'reply-open dispatch-closed hours still welcome a first customer and ask missing facts');
primaryNoErp('reply-only hours cannot dispatch');
primarySetup();$c=primaryInbound();PrimaryFixtureControl::$strictDispatchClock=true;PrimaryFixtureControl::$replyClockClosed=true;
\Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');primaryRun();
primaryExpect(count($dialogueGraphs)===0&&PrimaryAi::$calls===0,
    'dispatch-only open window cannot analyze a new greeting or send a question');

// Revalidate the dispatch clock inside the actual customer/ticket transaction.
$c=primaryCompleteDraft();PrimaryFixtureControl::$strictDispatchClock=true;
// Close the dispatch schedule during the second, transactional delivery quote.
PrimaryFixtureControl::$strictDispatchClock=false;$quotes=0;
app()->instance(PrimaryDelivery::class,new PrimaryDriftingDelivery());
PrimaryDriftingDelivery::$afterQuote=static function()use(&$quotes){$quotes++;if($quotes===2)PrimaryFixtureControl::$strictDispatchClock=true;};
\Carbon\Carbon::setTestNow('2026-10-09 22:05:03');\Carbon\CarbonImmutable::setTestNow('2026-10-09 22:05:03');
$r=primaryRun();primaryExpect($quotes>=2&&$r['deferred']===1&&PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'dispatch schedule closing in the final quote vetoes the transaction and retains a resumable queue');
primaryNoErp('dispatch-clock drift during transaction cannot write customer, ticket or print');

// A real midnight boundary crossed while repricing must roll back the whole ERP transaction.
$c=primaryCompleteDraft();PrimaryFixtureControl::$strictDispatchClock=true;$quotes=0;
\Carbon\Carbon::setTestNow('2026-10-10 21:29:59');\Carbon\CarbonImmutable::setTestNow('2026-10-10 21:29:59');
app()->instance(PrimaryDelivery::class,new PrimaryDriftingDelivery());
PrimaryDriftingDelivery::$afterQuote=static function()use(&$quotes){$quotes++;if($quotes===2){
    \Carbon\Carbon::setTestNow('2026-10-10 21:30:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 21:30:00');
}};
$r=primaryRun();primaryExpect($quotes>=2&&$r['deferred']===1&&PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'literal Cairo 00:29:59 to 00:30:00 race during transactional quote retains the approved queue');
primaryNoErp('00:30 boundary creates no customer, ticket, kitchen, print or payment');

// A quarantined historical cancellation has unknown scope, so the newly authorized
// native backlog waits for capture review. Older unrelated quarantine stays historical.
foreach (['in-window','older-than-lookback'] as $where) {
    primarySetup();$c=primaryInbound();PrimaryFixtureControl::$backlogGuard=true;
    PrimaryDB::table('meta_ai_controls')->update(['started_at_utc'=>'2026-10-08 22:05:00','event_floor'=>100]);
    $received=$where==='in-window'?'2026-10-09 22:00:00':'2026-10-08 22:04:59';
    $event=PrimaryDB::table('whatsapp_webhook_events')->insertGetId(['payload_hash'=>hash('sha256','quarantined-'.$where),
        'payload'=>PrimaryCrypt::encryptString('{"unreadable":"cancel-or-change"}'),'received_at'=>$received,'processed_at'=>null]);
    PrimaryDB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$event,'reason'=>'INVALID_EVENT','attempts'=>1,'last_attempted_at'=>now('UTC')]);
    $r=primaryRun();
    primaryExpect($where==='in-window'?(PrimaryAi::$calls===0&&count($dialogueGraphs)===0):(PrimaryAi::$calls===1&&count($dialogueGraphs)===1),
        $where.' pre-activation quarantine is conservatively bounded for backlog extraction and reply');
    primaryNoErp('unknown quarantined input never creates guessed ERP facts');
}

// A formerly incomplete narrow activation is reconsidered when the owner widens
// the authorized unanswered context; unchanged failed extracts still stay cached.
primarySetup();$c=primaryInbound('عايز فسيخ');PrimaryDB::table('meta_ai_controls')->update(['enabled'=>false]);
$workflow->analyze($c,(object)['id'=>7]);$calls=PrimaryAi::$calls;
$ceiling=(int)PrimaryDB::table('whatsapp_inbox_messages')->max('id');
$snapshotMethod=new ReflectionMethod($workflow,'snapshot');$snapshotMethod->setAccessible(true);
$narrow=$snapshotMethod->invoke($workflow,$c,$ceiling,$ceiling);
PrimaryDB::table('whatsapp_order_drafts')->where('conversation_id',$c)->update(['status'=>'REVIEW','reason'=>'CONTEXT_INCOMPLETE',
    'evidence_floor'=>$ceiling,'evidence_hash'=>$narrow['hash'],'extraction'=>null]);
PrimaryDB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>$ceiling]);
PrimaryDB::table('meta_ai_controls')->update(['enabled'=>true,'revision'=>2]);$r=primaryRun();
primaryExpect($r['errors']===0&&PrimaryAi::$calls===$calls+1&&count($dialogueGraphs)===1,
    'cached narrow CONTEXT_INCOMPLETE is reevaluated once under broader authorized native backlog');
primaryNoErp('previously incomplete native context is a clarification only');

primarySetup();
for ($old=0;$old<12;$old++) {
    $peer='US.primary.closed-'.$old;$closed=addConversation($peer);
    insertMessage($closed,'wamid.primary.closed.'.$old,'inbound','أهلاً',1,null,$peer);
    PrimaryAi::$data=PrimaryExtraction::none();$workflow->analyze($closed,(object)['id'=>7]);
    PrimaryDB::table('whatsapp_order_drafts')->where('conversation_id',$closed)->update(['status'=>$old%2?'CANCELLED':'DISPATCHED']);
    PrimaryDB::table('whatsapp_order_scans')->where('conversation_id',$closed)->update(['analyzed_ceiling'=>0]);
}
$calls=PrimaryAi::$calls;$c=primaryInbound();$r=primaryRun();
primaryExpect($r['threads_seen']===1&&PrimaryAi::$calls===$calls+1&&count($dialogueGraphs)===1,
    'closed and cancelled receipts with stale scan metadata are excluded before the candidate limit');
primaryNoErp('historical completed receipts cannot be revived as orders');

class PrimaryAfterSaveDriftPos extends PrimaryPos {
    public function save($channel,array $values,$actor): array {
        $result=parent::save($channel,$values,$actor);
        PrimaryDB::table('meta_ai_controls')->update(['revision'=>2]);
        return $result;
    }
}
$c=primaryCompleteDraft();app()->instance(PrimaryPos::class,new PrimaryAfterSaveDriftPos());$r=primaryRun();
primaryExpect($r['dispatched']===0&&$r['errors']===1,'fresh dispatch gate rejects revision drift even after the existing ERP boundary returns');
primaryNoErp('in-transaction policy revision drift rolls back customer ticket kitchen and printer together');

fwrite(STDOUT,'WHATSAPP_ORDER_PRIMARY_AI_WORKFLOW_TESTS='.$primaryChecks." PASS\n");
