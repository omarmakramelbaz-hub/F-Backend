<?php
// The real workflow, native parser, extraction, reply ledger and accepted projection run
// against SQLite. Only AI, Graph, control clock/policy and ERP boundaries are fixtures.
require __DIR__.'/whatsapp_order_dialogue_workflow_test.php';

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
    public static int $reads=0;
    public function gate(string $channel,?string $time=null,?int $id=null,?int $event=null): array {
        self::$reads++;
        $row=PrimaryDB::table('meta_ai_controls')->where('id',1)->first();
        $out=['effective'=>false,'reason'=>'DISABLED','revision'=>$row?(int)$row->revision:null,
            'actor_id'=>null,'actor'=>null,'floor'=>null];
        if (!$row||!$row->enabled||!$row->schedule_active||$channel!=='whatsapp') return $out;
        $actor=PrimaryDB::table('test_users')->where('id',$row->actor_id)->where('active',1)->first();
        if (!$actor||!$actor->can_checkout) return $out;
        $floor=['message_floor'=>(int)$row->message_floor,'event_floor'=>(int)$row->event_floor,'started_at_utc'=>$row->started_at_utc];
        if (($id!==null||$time!==null)&&($id===null||$time===null||$id<=$floor['message_floor']
            ||$time<$floor['started_at_utc']||$time>now('UTC')->format('Y-m-d H:i:s'))) return $out;
        return array_replace($out,['effective'=>true,'reason'=>null,'actor_id'=>(int)$actor->id,'actor'=>$actor,'floor'=>$floor]);
    }
}
PrimarySchema::create('meta_ai_controls',function($t){
    $t->integer('id')->primary();$t->integer('revision');$t->boolean('enabled');$t->boolean('schedule_active');
    $t->integer('actor_id');$t->integer('message_floor');$t->integer('event_floor');$t->string('started_at_utc');
});
app()->instance('App\\Services\\Dashboard\\MetaAiControl',new PrimaryFixtureControl());
function primarySetup(): void {
    dialogueSetup();
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

fwrite(STDOUT,'WHATSAPP_ORDER_PRIMARY_AI_WORKFLOW_TESTS='.$primaryChecks." PASS\n");
