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
class PrimaryInformationProvider extends DialogueFixtureProvider {
    public function followupQuestion(array $context): array {
        if (($context['kind']??null)!=='PRODUCT_INFORMATION') return parent::followupQuestion($context);
        primaryExpect(PrimaryDB::connection()->transactionLevel()===0,'information composition outside transaction');
        primaryExpect(PrimaryDB::table('whatsapp_reply_requests')->orderByDesc('id')->value('state')==='CLAIMED','information reservation precedes AI');
        primaryExpect(\App\Support\WhatsAppOrderDialogue::validContext($context),'bounded grounded information context');
        DialogueFixtureProvider::$contexts[]=$context;
        if (DialogueFixtureProvider::$duringQuestion) (DialogueFixtureProvider::$duringQuestion)();
        $choice=\App\Support\WhatsAppOrderDialogue::fallback($context);
        if (($context['facts'][0]['subject']??null)==='هيرنج') $choice=['action'=>'ANSWER','candidate_ids'=>[],
            'fact_ids'=>[$context['facts'][0]['id']],'text'=>'هيرنج ده الاسم الإنجليزي لسمك الرنجة. الاسم لوحده مش بيوضح طريقة التجهيز. تحب أوضح لك حاجة تانية قبل ما نكمل طلبك؟','variant'=>'FRIENDLY'];
        return ['ok'=>true,'choice'=>$choice];
    }
}
class PrimaryFixtureControl {
    public static bool $analysisOnly=false;
    public function analysisOnly(string $channel): bool { return self::$analysisOnly; }
    public static bool $backlogGuard=false;
    public static bool $strictDispatchClock=false;
    public static bool $replyClockClosed=false;
    public static ?array $analysisSchedule=null;
    public function analysisGate(string $channel,?string $time=null,?int $id=null,?int $event=null): array {
        if (!self::$analysisOnly) return $this->gate($channel,$time,$id,$event);
        $replyClosed=self::$replyClockClosed; self::$replyClockClosed=false; self::$analysisOnly=false;
        try { $gate=$this->gate($channel,$time,$id,$event); }
        finally { self::$replyClockClosed=$replyClosed; self::$analysisOnly=true; }
        if (self::$analysisSchedule!==null&&($gate['effective']??false)===true) {
            $clock=\App\Support\MetaAiSchedule::analysisStatus(['enabled'=>true,'mode'=>'ANALYZE_ONLY','channels'=>[$channel],'schedule'=>self::$analysisSchedule],
                new \DateTimeImmutable(now('UTC')->format('Y-m-d H:i:s'),new \DateTimeZone('UTC')));
            if (!$clock['effective']) { $gate['effective']=false; $gate['reason']='ANALYSIS_OUTSIDE_HOURS'; }
        }
        return $gate;
    }
    public function processingGate(string $channel,?string $time=null,?int $id=null,?int $event=null): array {
        $replyClosed=self::$replyClockClosed; $analysisOnly=self::$analysisOnly;
        self::$replyClockClosed=false; self::$analysisOnly=false;
        try { $gate=$this->gate($channel,$time,$id,$event); }
        finally { self::$replyClockClosed=$replyClosed; self::$analysisOnly=$analysisOnly; }
        if ($analysisOnly) return (($this->analysisGate($channel,$time,$id,$event)['effective']??false)||($this->dispatchGate($channel)['effective']??false))
            ?$gate:array_replace($gate,['effective'=>false,'reason'=>'ANALYSIS_OUTSIDE_HOURS']);
        if (!$replyClosed||($this->dispatchGate($channel)['effective']??false)) return $gate;
        return array_replace($gate,['effective'=>false,'reason'=>'OUTSIDE_SCHEDULE']);
    }
    public function dispatchGate(string $channel): array {
        $replyClosed=self::$replyClockClosed; $analysisOnly=self::$analysisOnly;
        self::$replyClockClosed=false; self::$analysisOnly=false;
        try { $gate=$this->gate($channel); } finally { self::$replyClockClosed=$replyClosed; self::$analysisOnly=$analysisOnly; }
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
        if (self::$analysisOnly) return array_replace($out,['reason'=>'ANALYZE_ONLY']);
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
    PrimaryFixtureControl::$analysisSchedule=null; PrimaryFixtureControl::$analysisOnly=false; PrimaryFixtureControl::$backlogGuard=false; PrimaryFixtureControl::$strictDispatchClock=false; PrimaryFixtureControl::$replyClockClosed=false;
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
    $followup=new ReflectionProperty(\App\Services\Dashboard\WhatsAppOrderFollowupService::class,'provider');$followup->setAccessible(true);
    $followup->setValue(app(\App\Services\Dashboard\WhatsAppOrderFollowupService::class),new PrimaryInformationProvider());
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
primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION'&&str_contains(dialogueLastText(),'الفرع'),
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
primaryExpect($r['errors']===0&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION'
    &&str_contains(dialogueLastText(),'غير متاح')&&str_contains(dialogueLastText(),'لا يوجد سعر مؤكد')&&!preg_match('/[0-9]/u',dialogueLastText()),
    'price inquiry addresses the actual named unavailable product and honestly reports missing price or units');
primaryNoErp('price inquiry cannot create an order');

function primaryCompleteDraft(int$dayOffsetSeconds=0): int {
    primarySetup();$c=addConversation('US.primary-complete');
    if($dayOffsetSeconds!==0)dialogueAdvance(300+$dayOffsetSeconds);
    insertMessage($c,'wamid.primary.order','inbound','اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز 1 كيلو فسيخ.',1+$dayOffsetSeconds,null,'US.primary-complete');
    insertMessage($c,'wamid.primary.pin','inbound','',2+$dayOffsetSeconds,['lat'=>31.00,'long'=>31.40],'US.primary-complete');
    PrimaryAi::$data=extractionData();PrimaryAi::$data['decision']='DRAFT';
    PrimaryAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    PrimaryAi::$data['branch_hint']=null;PrimaryAi::$data['approximate_total']=null;
    PrimaryAi::$data['issues']=['UNCONFIRMED'];
    PrimaryAi::$data['evidence']=['request_ids'=>['m1'],'confirmation_ids'=>[],'customer_acceptance_ids'=>[],'location_id'=>'m2'];
    $r=primaryRun();
    primaryExpect($r['errors']===0&&count($GLOBALS['dialogueGraphs'])===1&&str_contains(dialogueLastText(),'تأكيد الطلب:'),
        'first complete native request receives a factual recap before any ERP');
    primaryNoErp('recap is not customer approval');
    insertMessage($c,'wamid.primary.approval','inbound','موافق',302+$dayOffsetSeconds,null,'US.primary-complete');dialogueAdvance(303+$dayOffsetSeconds);
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
$c=primaryCompleteDraft(82800);PrimaryFixtureControl::$strictDispatchClock=true;$quotes=0;
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

// Exhausted unchanged native input with no current cached draft is excluded before
// the candidate limit, so it cannot make every timer tick fail or starve real work.
primarySetup();$exhausted=[];
for($old=0;$old<12;$old++){
    $peer='US.primary.exhausted-'.$old;$blocked=addConversation($peer);
    insertMessage($blocked,'wamid.primary.exhausted.'.$old,'inbound','أهلاً',1,null,$peer);
    $ceiling=(int)PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$blocked)->max('id');
    PrimaryDB::table('whatsapp_order_scans')->insert(['conversation_id'=>$blocked,'analyzed_ceiling'=>0,'claimed_ceiling'=>$ceiling,
        'auto_floor'=>0,'attempts'=>3,'next_attempt_at'=>'2026-10-09 22:04:00','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $exhausted[]=$blocked;
}
$before=PrimaryDB::table('whatsapp_order_scans')->whereIn('conversation_id',$exhausted)->orderBy('conversation_id')->get()->toArray();
$c=primaryInbound();$r=primaryRun();
primaryExpect($r['errors']===0&&$r['threads_seen']===1&&PrimaryAi::$calls===1&&count($dialogueGraphs)===1,
    'more than candidate limit of exhausted unchanged threads cannot starve a fresh unanswered customer or fail worker');
primaryExpect(PrimaryDB::table('whatsapp_order_scans')->whereIn('conversation_id',$exhausted)->orderBy('conversation_id')->get()->toArray()==$before,
    'excluding an exhausted turn does not reset attempts backoff leases or any old evidence');
$r=primaryRun();primaryExpect($r['errors']===0&&$r['threads_seen']===0&&PrimaryAi::$calls===1,
    'later idle tick stays successful without repeating extraction for exhausted native input');

$blocked=$exhausted[0];$peer='US.primary.exhausted-0';
insertMessage($blocked,'wamid.primary.exhausted.new-turn','inbound','عايز فسيخ',302,null,$peer);dialogueAdvance(303);
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m2'];$r=primaryRun();
primaryExpect($r['errors']===0&&$r['threads_seen']===1&&PrimaryAi::$calls===2&&count($dialogueGraphs)===2
    &&(int)PrimaryDB::table('whatsapp_order_scans')->where('conversation_id',$blocked)->value('attempts')===1,
    'a genuine newer native ceiling starts one new bounded attempt after an exhausted older turn');
primaryNoErp('exhaustion recovery still cannot dispatch guessed facts');

// Exhaustion does not suppress cached approved work: no new AI extraction is needed.
$c=primaryCompleteDraft();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$ceiling=(int)PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
PrimaryDB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['attempts'=>3,'claimed_ceiling'=>$ceiling]);
$calls=PrimaryAi::$calls;PrimaryFixtureControl::$replyClockClosed=true;
\Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');$r=primaryRun();
primaryExpect($r['errors']===0&&$r['dispatched']===1&&PrimaryAi::$calls===$calls&&PrimaryDB::table('test_tickets')->count()===1,
    'current cached approved draft at attempt three resumes at opening without a new native message or extraction');

primarySetup();$c=primaryInbound();$workflow->analyze($c,(object)['id'=>7]);$calls=PrimaryAi::$calls;
$ceiling=(int)PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
PrimaryDB::table('whatsapp_order_scans')->where('conversation_id',$c)->update(['auto_floor'=>0,'attempts'=>3,'claimed_ceiling'=>$ceiling]);
$r=primaryRun();primaryExpect($r['errors']===0&&$r['threads_seen']===1&&PrimaryAi::$calls===$calls&&count($dialogueGraphs)===1,
    'same-floor current cached NONE at attempt three still welcomes unanswered customer without another extraction');
primaryNoErp('cached NONE clarification preserves no-order facts');

primarySetup();$c=primaryInbound('عايز فسيخ دليفري من المنصورة');
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['branch_hint']='المنصورة';PrimaryAi::$data['fulfillment']='DELIVERY';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m1'];
PrimaryDB::table('test_branches')->where('id',363)->update(['status'=>'closed']);
PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['field']==='QUANTITY',
    'active primary reply schedule asks missing quantity for resolved closed Mansoura branch while dispatch hours are closed');
primaryExpect(!str_contains(dialogueLastText(),'تم إرسال')&&!str_contains(dialogueLastText(),'اترسل')&&!str_contains(dialogueLastText(),'تم تأكيد'),
    'after-hours clarification cannot claim an order was sent or accepted by the branch');
primaryNoErp('closed-branch clarification never creates customer ticket kitchen print or payment');

$c=primaryCompleteDraft();PrimaryDB::table('test_branches')->where('id',363)->update(['status'=>'closed']);
PrimaryFixtureControl::$strictDispatchClock=true;$graphs=count($dialogueGraphs);$r=primaryRun();
primaryExpect($r['dispatched']===0&&PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='BRANCH_CLOSED'
    &&count($dialogueGraphs)===$graphs,'closed branch still blocks a complete approved ERP order without repeating its recap');
primaryNoErp('primary question-hours bypass does not bypass ERP branch-open policy');

// Screenshot regression: choosing from names and asking what a name means are
// different customer turns. The latter answers first and preserves the basket.
primarySetup();$c=primaryInbound('عايز رنجه تيك أواي من المنصورة');
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'رنجة هيرنج','available'=>true,'quantity_mode'=>'weight','price'=>'220.00','options'=>[]],
    ['id'=>12,'name'=>'رنجة هيرنج مطبخ','available'=>true,'quantity_mode'=>'weight','price'=>'240.00','options'=>[]]];
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['branch_hint']='المنصورة';PrimaryAi::$data['fulfillment']='PICKUP';
PrimaryAi::$data['items']=[['name'=>'رنجه','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m1'];$r=primaryRun();
primaryExpect($r['errors']===0&&dialogueLastContext()['kind']==='PRODUCT_CHOICE','actual menu choices precede the screenshot meaning question');
$basket=PrimaryAi::$data;
insertMessage($c,'wamid.primary.meaning','inbound','يعني ايه رنجه هيرنج',302,null,'US.primary-fixture');dialogueAdvance(303);$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===2&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION'
    &&str_contains(dialogueLastText(),'الاسم الإنجليزي')&&!str_contains(dialogueLastText(),'اكتب اسم الصنف')
    &&!str_contains(dialogueLastText(),'من الاختيارات'),'meaning question receives a natural actual answer instead of repeating the two choices');
$stored=json_decode(PrimaryCrypt::decryptString(PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('extraction')),true);
primaryExpect($stored===$basket,'meaning answer does not change product quantity customer facts or approvals');
primaryExpect(PrimaryDB::table('whatsapp_reply_requests')->orderByDesc('id')->value('state')==='ACCEPTED','information reply uses the existing accepted reservation ledger');
primaryNoErp('meaning question cannot execute an order');$graphs=count($dialogueGraphs);primaryRun();
primaryExpect(count($dialogueGraphs)===$graphs,'same meaning question cannot receive duplicate answers');
insertMessage($c,'wamid.primary.actual-choice','inbound','عايز رنجة هيرنج',304,null,'US.primary-fixture');dialogueAdvance(305);
PrimaryAi::$data['items'][0]['name']='رنجة هيرنج';PrimaryAi::$data['evidence']['request_ids']=['m1','m5'];$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===3&&dialogueLastContext()['field']==='QUANTITY'
    &&dialogueLastContext()['kind']==='MISSING','genuine product choice after explanation resumes the basket quantity question');
primaryNoErp('actual product choice still needs quantity and customer approval');

foreach (['يعني ايه رنجه هيرنج','مش فاهم قصدك ايه','ايه الفرق بين رنجة هيرنج ورنجة هيرنج مطبخ'] as $question) {
    primarySetup();$c=primaryInbound($question);$r=primaryRun();
    primaryExpect($r['errors']===0&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION'&&count($dialogueGraphs)===1,
        'fresh standalone meaning or deictic question is answered without pretending it is a purchase: '.$question);
    primaryNoErp('standalone informational turn has no ERP');
}
primarySetup();$c=primaryInbound('رنجة هيرنج بكام في المنصورة؟');
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'رنجة هيرنج','available'=>true,'quantity_mode'=>'weight','price'=>'220.00',
    'description'=>'اسم الصنف كما يظهر في المنيو.','options'=>[['id'=>'base','label'=>'كامل','price'=>'220.00']]]];$r=primaryRun();
primaryExpect($r['errors']===0&&str_contains(implode(' ',array_column(dialogueLastContext()['facts'],'text')),'220.00 جنيه مصري لكل كيلو'),
    'price answer receives the actual current branch price and explicit sale unit');
$stored=json_decode(PrimaryCrypt::decryptString(PrimaryDB::table('whatsapp_order_drafts')->value('extraction')),true);
primaryExpect($stored['decision']==='NONE'&&$stored['customer']['address']===null,'question city is routing only and is never invented as a customer address');
primaryNoErp('branch-scoped price answer does not create an order');

primarySetup();$c=primaryInbound('رنجة هيرنج بكام في المنصورة؟');
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'رنجة هيرنج','available'=>true,'quantity_mode'=>'weight','price'=>'220.00','options'=>[]]];
DialogueFixtureProvider::$duringQuestion=static function(){DialogueFixtureCatalog::$products[0]['price']='260.00';};$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===0&&PrimaryDB::table('whatsapp_reply_requests')->value('state')==='FAILED',
    'price changes while information is composed invalidate its fresh fingerprint before Graph');
primaryNoErp('price-drift information cannot execute or send stale prices');

$c=primaryCompleteDraft();insertMessage($c,'wamid.primary.confirmed-question','inbound','يعني ايه فسيخ',304,null,'US.primary-complete');dialogueAdvance(305);
$workflow->analyze($c,(object)['id'=>7]);$calls=PrimaryAi::$calls;$r=primaryRun();
primaryExpect($r['errors']===0&&$r['dispatched']===0&&PrimaryAi::$calls===$calls&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION',
    'cached CONFIRMED older basket yields an answer when the actual latest turn is a question');
primaryNoErp('informational latest turn vetoes cached confirmation at the ERP review boundary');
$reviewer=new ReflectionMethod(PrimaryWorkflow::class,'automaticReview');$reviewer->setAccessible(true);$reason=null;
$row=PrimaryDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();$arguments=[$row,(object)['id'=>7],&$reason,true];
primaryExpect($reviewer->invokeArgs($workflow,$arguments)===null&&$reason==='REVIEW_REQUIRED','locked ERP review independently rejects the informational latest turn');
$intent=new ReflectionMethod(PrimaryWorkflow::class,'informationTurn');$intent->setAccessible(true);
$snapshot=new ReflectionMethod(PrimaryWorkflow::class,'snapshot');$snapshot->setAccessible(true);
$transcript=$snapshot->invoke($workflow,$c,(int)$row->evidence_floor,(int)$row->evidence_ceiling)['transcript'];
foreach(['تمام موافق بنفس السعر','عايز فسيخ كامل','عايز كيلو رنجة','عايز كيلو رنجة؟','عايز رنجة هيرنج لو سمحت؟','ممكن نص كيلو رنجة؟'] as $approval) {
    $copy=$transcript;$copy[count($copy)-1]['text']=$approval;
    primaryExpect($intent->invoke($workflow,$copy)===null,'normal affirmative selection is not diverted by incidental price or كامل: '.$approval);
}
foreach(['نص كيلو؟','٢ كيلو؟','ربع كيلو لو سمحت؟'] as $amount) {
    $copy=$transcript;$copy[count($copy)-2]['speaker']='business';$copy[count($copy)-2]['text']='بالنسبة لـ «رنجة هيرنج»، تحب قد إيه؟';$copy[count($copy)-1]['text']=$amount;
    primaryExpect($intent->invoke($workflow,$copy)===null,'direct quantity answer with courteous punctuation is not a definition question: '.$amount);
}
primarySetup();$c=primaryInbound('عايز فسيخ تيك أواي من المنصورة؟');
PrimaryAi::$data=PrimaryExtraction::none('MISSING_QUANTITY');PrimaryAi::$data['decision']='DRAFT';
PrimaryAi::$data['branch_hint']='المنصورة';PrimaryAi::$data['fulfillment']='PICKUP';
PrimaryAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
PrimaryAi::$data['evidence']['request_ids']=['m1'];$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['field']==='QUANTITY'
    &&dialogueLastContext()['kind']==='MISSING','courteous purchase question resumes normal missing quantity flow without informational diversion');
primaryNoErp('courteous purchase punctuation never grants order approval');
foreach(['أطلب إزاي؟','انتو شغالين امتى؟','إيه ده؟','ليه؟','عايز أعرف معنى هيرنج'] as $question) {
    primarySetup();$c=primaryInbound($question);$r=primaryRun();
    primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION',
        'common Egyptian information turn does not repeat a missing-field question: '.$question);
    $context=dialogueLastContext();
    primaryExpect(\App\Support\WhatsAppOrderDialogue::render($context,\App\Support\WhatsAppOrderDialogue::fallback($context))!=='',
        'actual procedural or missing business facts survive deterministic fallback rendering: '.$question);
    primaryNoErp('general question never supplies an order or approval');
}

$faqCases=[
    ['مواعيد العمل والتجهيز؟','10:00','01:00'],
    ['بتجهزوا امتى؟','10:00','01:00'],
    ['مدة التوصيل قد إيه؟','60','90'],
    ['إيه الفرق بين فسيخ نبروه ودسوق؟','ملحه خفيف','ملحه مظبوط'],
    ['يعني إيه مخلي ومتنضف؟','بدون عظم','فصل الرأس'],
    ['مبطرخة يعني إيه؟','مع البطارخ','منيو الفرع'],
    ['وجبة الميكس فيها إيه؟','فسيخ ورنجة وسردين','منيو الفرع'],
    ['هات رقم فرع المنصورة','0502353511','عبد السلام عارف'],
    ['عندكم فرع في زايد؟','مناطق خدمة عبر التطبيق','ليست فروعاً فعلية'],
    ['ممكن المنيو؟','https://wa.me/c/201285545554','منيو الفرع'],
    ['لينك تطبيق فسخانستا؟','https://fasakhaninja.com/downloadApp','تطبيق فسخانستا'],
    ['الجملة تبدأ من كام كيلو؟','50','الموظف'],
    ['إيه طرق الدفع؟','Vodafone Cash','InstaPay'],
];
foreach ($faqCases as [$question,$expected,$second]) {
    primarySetup();$c=primaryInbound($question);$r=primaryRun();
    primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['kind']==='PRODUCT_INFORMATION',
        'approved FAQ topic is answered through the actual reserved workflow: '.$question);
    $context=dialogueLastContext();$facts=implode(' ',array_column($context['facts'],'text'));
    primaryExpect(str_contains($facts,$expected)&&str_contains($facts,$second),'approved FAQ facts cover the requested topic: '.$question);
    primaryExpect(\App\Support\WhatsAppOrderDialogue::render($context,\App\Support\WhatsAppOrderDialogue::fallback($context))!=='',
        'actual curated FAQ context has a valid literal fallback: '.$question);
    primaryNoErp('FAQ answer never supplies an order or approval');
}
foreach (['رقم هاتف للتواصل بخصوص الطلب؟'=>'رقم تواصل واحد','عنوان التوصيل بالتفصيل إيه؟'=>'عنوان التوصيل',
    'ممكن تبعت لوكيشن عنوان التوصيل؟'=>'نقطة التوصيل'] as $business=>$expected) {
    primarySetup();$c=primaryInbound('أهلاً');primaryRun();
    insertMessage($c,'wamid.primary.faq.business','outbound',$business,302,null,'US.primary-fixture');
    insertMessage($c,'wamid.primary.faq.why','inbound','ليه؟',304,null,'US.primary-fixture');dialogueAdvance(305);$r=primaryRun();
    primaryExpect($r['errors']===0&&count($dialogueGraphs)===2&&str_contains(dialogueLastText(),$expected),
        'deictic why explains the immediately preceding business request: '.$business);
    primaryNoErp('explaining a contact or location request cannot confirm an order');
}

// Native insertion ids deliberately disagree with chronology. Historical carts, pins and
// coded business replies remain authenticated in the full proof but never join the new order.
function primaryEpisodeDraft(bool $withPin=true): int {
    primarySetup(); $c=addConversation('US.fixture');
    insertMessage($c,'wamid.episode.new','inbound','عايز طلب جديد. اسمي عميل جديد ورقمي 201000000002 وعنواني شارع الجديد المنصوره. عايز 1 كيلو فسيخ.',100);
    insertMessage($c,'wamid.episode.old','inbound','اسمي عميل قديم ورقمي 201000000001 وعنواني شارع القديم المنصوره. عايز 2 كيلو فسيخ.',1);
    insertCartMessage($c,'wamid.episode.old-cart',2,[['product_retailer_id'=>'old-fish','quantity'=>2,'item_price'=>'45.00','currency'=>'EGP']]);
    insertMessage($c,'wamid.episode.old-pin','inbound','',3,['lat'=>30.2,'long'=>30.4]);
    insertMessage($c,'wamid.episode.marker','outbound','تم اختبار WA-4321',4);
    // A final genuine customer row advances the ceiling after the delayed old echoes.
    insertMessage($c,'wamid.episode.continuation','inbound',$withPin?'':'دليفري لو سمحت',101,$withPin?['lat'=>31,'long'=>31.4]:null);
    PrimaryAi::$data=extractionData(); PrimaryAi::$data['decision']='DRAFT';
    PrimaryAi::$data['customer']=['name'=>'عميل جديد','phone'=>'201000000002','address'=>'شارع الجديد المنصوره','area'=>'المنصوره','notes'=>null];
    PrimaryAi::$data['branch_hint']=null;PrimaryAi::$data['approximate_total']=null;PrimaryAi::$data['issues']=['UNCONFIRMED'];
    PrimaryAi::$data['evidence']=['request_ids'=>['m5'],'confirmation_ids'=>[],'customer_acceptance_ids'=>[],'location_id'=>$withPin?'m6':null];
    return $c;
}
$c=primaryEpisodeDraft();$r=primaryRun();
primaryExpect($r['errors']===0&&PrimaryAi::$calls===1&&array_column(PrimaryAi::$lastTranscript,'id')===['m5','m6'],
    'fresh order after coded business marker sends only its chronologically scoped original labels to extraction');
primaryExpect(count($dialogueGraphs)===1&&str_contains(dialogueLastText(),'عميل جديد')&&!str_contains(dialogueLastText(),'عميل قديم'),
    'new episode receives a recap from its own customer facts');
$row=PrimaryDB::table('whatsapp_order_drafts')->first();$reflect=new ReflectionMethod(PrimaryWorkflow::class,'snapshot');$reflect->setAccessible(true);
$full=$reflect->invoke($workflow,$c,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
primaryExpect(count($full['transcript'])===6&&str_contains($full['transcript'][3]['text'],'WA-4321')&&$full['hash']===$row->evidence_hash,
    'full authenticated snapshot and hash retain historical marker, cart and pin for currentness proof');
primaryNoErp('new-episode recap cannot itself approve execution');
insertMessage($c,'wamid.episode.approve','inbound','موافق',302);dialogueAdvance(303);
PrimaryAi::$data['decision']='CONFIRMED';PrimaryAi::$data['issues']=[];
PrimaryAi::$data['evidence']['confirmation_ids']=['m7'];PrimaryAi::$data['evidence']['customer_acceptance_ids']=['m8'];$r=primaryRun();
primaryExpect($r['errors']===0&&$r['dispatched']===1&&PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,
    'new native request, new pin, owned recap and genuine later approval execute despite unrelated old cart');
primaryExpect(PrimaryDB::table('branch_customers')->value('phone_key')===\App\Services\Dashboard\BranchCustomers::phoneKey('201000000002')
    &&PrimaryDB::table('branch_customers')->value('address')==='شارع الجديد المنصوره','only new episode customer reaches ERP');
primaryRun();primaryExpect(PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,'episode replay cannot duplicate ERP');
$c=primaryEpisodeDraft(false);$r=primaryRun();
primaryExpect($r['errors']===0&&count($dialogueGraphs)===1&&dialogueLastContext()['field']==='LOCATION',
    'old episode native pin is never borrowed to satisfy a new order location');
primaryNoErp('historical location cannot authorize new order');
$c=primaryEpisodeDraft();PrimaryAi::$data['customer']['name']='عميل قديم';PrimaryAi::$data['customer']['phone']='201000000001';
PrimaryAi::$data['customer']['address']='شارع القديم المنصوره';PrimaryAi::$data['items'][0]['quantity']='2';
PrimaryAi::$data['evidence']['request_ids']=['m1'];PrimaryAi::$data['evidence']['location_id']='m3';$r=primaryRun();
primaryExpect($r['errors']===0&&PrimaryDB::table('whatsapp_order_drafts')->value('reason')==='INVALID_EXTRACTION',
    'model or cached old identity, basket and pin citations fail validation against the full episode boundary');
primaryNoErp('old episode data never executes');
$c=primaryEpisodeDraft();primaryRun();
insertMessage($c,'wamid.episode.cached-approval','inbound','موافق',302);dialogueAdvance(303);
PrimaryAi::$data['decision']='CONFIRMED';PrimaryAi::$data['issues']=[];
PrimaryAi::$data['evidence']['confirmation_ids']=['m7'];PrimaryAi::$data['evidence']['customer_acceptance_ids']=['m8'];
$workflow->analyze($c,(object)['id'=>7]);$calls=PrimaryAi::$calls;
$stale=PrimaryAi::$data;$stale['evidence']['request_ids']=['m1'];$stale['evidence']['location_id']='m3';
$stale['customer']=['name'=>'عميل قديم','phone'=>'201000000001','address'=>'شارع القديم المنصوره','area'=>'المنصوره','notes'=>null];
$draftId=PrimaryDB::table('whatsapp_order_drafts')->where('conversation_id',$c)->max('id');
PrimaryDB::table('whatsapp_order_drafts')->where('id',$draftId)->update(['extraction'=>PrimaryCrypt::encryptString(json_encode($stale,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))]);
$r=primaryRun();
primaryExpect($r['dispatched']===0&&PrimaryAi::$calls===$calls,'unchanged authenticated snapshot cannot dispatch a cached result citing old episode facts');
primaryNoErp('cached old episode cannot reuse later genuine approval');
foreach (['عايز 1 كيلو فسيخ','عايز طلب جديد؟','قال لي "عايز طلب جديد"','مش عايز طلب جديد','إلغاء الطلب'] as $text) {
    primarySetup();$c=addConversation('US.fixture');insertMessage($c,'wamid.episode.block.marker','outbound','WA-4321',1);
    insertMessage($c,'wamid.episode.block.customer','inbound',$text,2);PrimaryAi::$data=PrimaryExtraction::none();$r=primaryRun();
    primaryExpect(PrimaryAi::$calls===0&&count($dialogueGraphs)===0,'coded history needs strict genuine new order, never quoted, cancelled or question intent: '.$text);
    primaryNoErp('blocked marker episode has no ERP');
}
$c=primaryEpisodeDraft();insertMessage($c,'wamid.episode.new-marker','outbound','WA-7654',102);
insertMessage($c,'wamid.episode.after-marker','inbound','تمام',103);$r=primaryRun();
primaryExpect(PrimaryAi::$calls===0&&count($dialogueGraphs)===0,'newer coded marker closes an otherwise clean fresh episode');primaryNoErp('current coded marker vetoes all order effects');

fwrite(STDOUT,'WHATSAPP_ORDER_PRIMARY_AI_WORKFLOW_TESTS='.$primaryChecks." PASS\n");
