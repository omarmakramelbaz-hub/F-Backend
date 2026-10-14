<?php

// Real workflow, validation, native evidence, reply reservation and accepted projection.
// Only catalog/extraction/AI/Graph boundaries use synthetic fixture values.
require __DIR__.'/whatsapp_order_address_branch_workflow_test.php';
require_once dirname(__DIR__).'/app/Support/WhatsAppOrderDialogue.php';
// Optional exact unified-inbox compatibility fixture; the default remains the current application source.
$replyTestSource=getenv('WA_ORDER_REPLY_TEST_SOURCE');
if (is_string($replyTestSource)&&$replyTestSource!=='') {
    $expected=realpath(__DIR__.'/fixtures/whatsapp_order_unified_reply/WhatsAppReplyService.php');
    $selected=realpath($replyTestSource);
    if ($expected===false||$selected!==$expected) throw new RuntimeException('INVALID_REPLY_TEST_SOURCE');
    $bytes=file_get_contents($selected);
    if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!=='f80378c7b61cc42c747bdceefd71b79c566de553')
        throw new RuntimeException('INVALID_REPLY_TEST_SOURCE');
    $replyTestSource=$selected;
} else $replyTestSource=null;
foreach (['WhatsAppVoiceMedia','WhatsAppInboxMedia','WhatsAppReplyService','WhatsAppOrderFollowupService'] as $class)
    require_once $class==='WhatsAppReplyService'&&$replyTestSource!==null?$replyTestSource:dirname(__DIR__).'/app/Services/Dashboard/'.$class.'.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as DialogueAi;
use App\Services\Dashboard\WhatsAppOrderFollowupService as DialogueService;
use App\Services\Dashboard\WhatsAppReplyService as DialogueReplies;
use App\Services\Dashboard\TakeawayCatalog as DialogueCatalog;
use App\Support\WhatsAppOrderDialogue as Dialogue;
use Illuminate\Support\Facades\DB as DialogueDB;
use Illuminate\Support\Facades\Crypt as DialogueCrypt;

$dialogueChecks=0; $dialogueGraphs=[]; $dialogueGraphMode='ACCEPTED'; $dialogueGraphHook=null;
function dialogueExpect($condition,string $label): void {
    $GLOBALS['dialogueChecks']++;
    if (!$condition) throw new RuntimeException('DIALOGUE_WORKFLOW_FAILED:'.$label);
}
class DialogueFixtureProvider extends DialogueAi {
    public static array $contexts=[];
    public static string $choiceMode='ASK';
    public static $duringQuestion=null;
    public function followupQuestion(array $context): array {
        dialogueExpect(DialogueDB::connection()->transactionLevel()===0,'dialogue AI runs outside transaction');
        dialogueExpect(DialogueDB::table('whatsapp_reply_requests')->orderByDesc('id')->value('state')==='CLAIMED',
            'durable global reservation precedes contextual AI');
        dialogueExpect(Dialogue::validContext($context),'provider receives a valid bounded dialogue context');
        self::$contexts[]=$context;
        if (self::$duringQuestion) (self::$duringQuestion)();
        if (self::$choiceMode==='FAIL') return ['ok'=>false,'choice'=>null,'reason'=>'TRANSPORT_ERROR'];
        if (self::$choiceMode==='INVALID') return ['ok'=>true,'choice'=>['action'=>'ASK','candidate_ids'=>['c999'],'variant'=>'SHORT'],'reason'=>null];
        return ['ok'=>true,'choice'=>['action'=>self::$choiceMode==='WAIT'?'WAIT':'ASK',
            'candidate_ids'=>self::$choiceMode==='WAIT'?[]:array_slice(array_column($context['candidates'],'id'),0,6),
            'variant'=>'POLITE'],'reason'=>null];
    }
    public function followupVariant(array $fields): array { return ['ok'=>true,'variant'=>'SHORT']; }
}
class DialogueFixtureCatalog extends DialogueCatalog {
    public static ?array $products=null;
    public function listing($values,$actor): array {
        if (self::$products===null) return parent::listing($values,$actor);
        app(\App\Services\Dashboard\TakeawayAccess::class)->branch($values['branch'],$actor);
        return ['items'=>self::$products,'pagination'=>['last_page'=>1]];
    }
}
class DialogueMixedBasketPos extends \App\Services\Dashboard\PosServiceTicket {
    public function quote($channel,array $values,$actor): array {
        abort_unless($channel==='phone'&&isset($values['delivery_quote_hash']),422,'MIXED_PHONE_CONTRACT');
        app(\App\Services\Dashboard\TakeawayAccess::class)->branch($values['branch'],$actor);
        foreach ($values['items'] as $item)
            abort_unless(in_array($item['product_id'],[11,12],true)&&$item['quantity_mode']==='weight'
                &&(float)$item['quantity']>0&&$item['option_id']==='',422,'MIXED_CATALOG_CONTRACT');
        $total=10000+self::$drift;
        return ['quote_hash'=>self::fingerprint([$values['branch'],$values['items'],$total]),
            'total'=>number_format($total/100,2,'.',''),'total_cents'=>$total];
    }
}
function dialogueSetup(): void {
    global $dialogueGraphs,$dialogueGraphMode,$dialogueGraphHook;
    addressFlowSetup(); \Carbon\Carbon::setTestNow('2026-10-09 22:05:00');
    \Carbon\CarbonImmutable::setTestNow('2026-10-09 22:05:00');
    DialogueDB::table('whatsapp_inbox_ingestion_failures')->delete();
    DialogueFixtureProvider::$contexts=[]; DialogueFixtureProvider::$choiceMode='ASK';
    DialogueFixtureProvider::$duringQuestion=null; DialogueFixtureCatalog::$products=null;
    $dialogueGraphs=[]; $dialogueGraphMode='ACCEPTED'; $dialogueGraphHook=null;
    app('config')->set('whatsapp_replies',['enabled'=>true,'access_token'=>'fixture-token-never-real']);
    app('config')->set('desktop_dashboard.local',false);
    app()->instance(\App\Services\Dashboard\PosServiceTicket::class,new \App\Services\Dashboard\PosServiceTicket());
    $auth=static fn($actor)=>app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
    $clock=static fn()=>\Carbon\CarbonImmutable::now('UTC');
    $transport=static function(string $endpoint,array $payload,string $token,bool $multipart) use (&$dialogueGraphs,&$dialogueGraphMode,&$dialogueGraphHook): array {
        dialogueExpect(DialogueDB::connection()->transactionLevel()===0,'Graph boundary runs outside transaction');
        dialogueExpect(str_ends_with($endpoint,'/messages')&&!$multipart&&$payload['type']==='text',
            'contextual workflow permits only one text message boundary');
        dialogueExpect(DialogueDB::table('whatsapp_reply_requests')->orderByDesc('id')->value('state')==='UNKNOWN',
            'send uncertainty committed before Graph');
        $dialogueGraphs[]=$payload;
        if ($dialogueGraphHook) ($dialogueGraphHook)();
        if ($dialogueGraphMode==='UNKNOWN') return ['status'=>0,'body'=>''];
        return ['status'=>200,'body'=>json_encode(['messaging_product'=>'whatsapp',
            'contacts'=>[['input'=>$payload['to'],'wa_id'=>$payload['to']]],
            'messages'=>[['id'=>'wamid.dialogue-fixture.'.count($dialogueGraphs)]]])];
    };
    app()->instance(DialogueCatalog::class,new DialogueFixtureCatalog());
    app()->instance(DialogueService::class,new DialogueService(
        new DialogueReplies($transport,$auth,$clock,['enabled'=>true,'access_token'=>'fixture-token-never-real']),
        new DialogueFixtureProvider()));
}
function dialogueDraft(string $request='عايز فسيخ',bool $pin=true,string $business='تمام، نكمل بيانات الطلب.'): int {
    $peer='US.dialogue-fixture'; $conversation=addConversation($peer);
    insertMessage($conversation,'wamid.dialogue-request','inbound',
        'اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. '.$request,1,null,$peer);
    if ($pin) insertMessage($conversation,'wamid.dialogue-point','inbound','',2,['lat'=>31.00,'long'=>31.40],$peer);
    insertMessage($conversation,'wamid.dialogue-business','outbound',$business,3,null,$peer);
    DialogueAi::$data=extractionData();
    DialogueAi::$data['decision']='DRAFT';
    DialogueAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    DialogueAi::$data['branch_hint']=null; DialogueAi::$data['approximate_total']=null;
    DialogueAi::$data['items']=[['name'=>'فسيخ','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null]];
    DialogueAi::$data['evidence']=['request_ids'=>['m1'],'confirmation_ids'=>[],
        'customer_acceptance_ids'=>[],'location_id'=>$pin?'m2':null];
    DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','MISSING_QUANTITY'];
    return $conversation;
}
function dialogueRun(): array {
    global $workflow; $configuration=app('config')->all(); $result=$workflow->process();
    dialogueExpect($result['errors']===0,'contextual processing has no unexpected error');
    dialogueExpect(app('config')->all()===$configuration,'contextual processing preserves activation and configuration');
    return $result;
}
function dialogueNoErp(string $label): void {
    dialogueExpect(DialogueDB::table('test_tickets')->count()===0&&DialogueDB::table('test_print')->count()===0
        &&DialogueDB::table('branch_customers')->count()===0&&DialogueDB::table('test_billing')->count()===0,$label);
}
function dialogueLastContext(): array {
    dialogueExpect(count(DialogueFixtureProvider::$contexts)>0,'contextual planner was called');
    return DialogueFixtureProvider::$contexts[count(DialogueFixtureProvider::$contexts)-1];
}
function dialogueLastText(): string {
    global $dialogueGraphs;
    return $dialogueGraphs[count($dialogueGraphs)-1]['text']['body']??'';
}
function dialogueAdvance(int $second=301): void {
    $date=\Carbon\CarbonImmutable::parse('2026-10-09 22:00:00 UTC')->addSeconds($second);
    \Carbon\Carbon::setTestNow($date); \Carbon\CarbonImmutable::setTestNow($date);
    DialogueDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]);
}
function dialogueBarePrepare(): array {
    global $dialogueGraphs;
    dialogueSetup(); $conversation=dialogueDraft(); dialogueRun();
    insertMessage($conversation,'wamid.dialogue-bare-quantity','inbound','كيلو ونص',361,null,'US.dialogue-fixture');
    dialogueAdvance(362);
    DialogueAi::$data['items'][0]=['name'=>'فسيخ','quantity'=>'1.5','quantity_mode'=>'weight','option_hint'=>null,
        'cart_reference'=>null,'conversation_reference'=>null];
    DialogueAi::$data['evidence']['request_ids']=['m1','m5'];
    DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
    dialogueRun();
    dialogueExpect(count($dialogueGraphs)===2&&str_contains(dialogueLastText(),'1.5 كيلو فسيخ')
        &&str_contains(dialogueLastText(),'اكتب موافق'),
        'bare actual amount directly answering named question prepares factual recap before any prior matching recap');
    dialogueNoErp('bare contextual amount only prepares approval question');
    $actualClause=null;
    foreach (\App\Services\Dashboard\WhatsAppOrderConversationEvidence::clauses(dialogueLastText()) as $index=>$clause)
        if (str_contains($clause,'1.5 كيلو فسيخ')) $actualClause=$index;
    dialogueExpect(is_int($actualClause),'final contextual citation selects an actual rendered recap clause');
    return [$conversation,$actualClause];
}
function dialogueBareFinalData(int $actualClause): void {
    DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
    DialogueAi::$data['items'][0]['conversation_reference']=['name_anchor_id'=>'m1','business_offer_id'=>'m4',
        'customer_quantity_id'=>'m5','business_summary_id'=>'m6','summary_clause_index'=>$actualClause,'customer_option_id'=>null];
    DialogueAi::$data['evidence']['confirmation_ids']=['m6'];
    DialogueAi::$data['evidence']['customer_acceptance_ids']=['m7'];
}

dialogueSetup(); $conversation=dialogueDraft(); $source=DialogueAi::$data; dialogueRun();
$context=dialogueLastContext();
dialogueExpect($context['field']==='QUANTITY'&&$context['item_name']==='فسيخ',
    'actual missing amount question names the grounded product');
dialogueExpect(str_contains(dialogueLastText(),'فسيخ')&&!str_contains(dialogueLastText(),'اسم حضرتك'),
    'single contextual question does not reask existing contact facts');
dialogueExpect(count($dialogueGraphs)===1&&DialogueDB::table('whatsapp_reply_requests')->value('state')==='ACCEPTED',
    'one contextual text has an accepted durable receipt');
dialogueExpect(DialogueAi::$data===$source,'contextual question does not alter authoritative extraction');
dialogueNoErp('missing amount clarification has no ERP writes');
$calls=DialogueAi::$calls; dialogueAdvance(); dialogueRun();
dialogueExpect(count($dialogueGraphs)===1&&count(DialogueFixtureProvider::$contexts)===1&&DialogueAi::$calls===$calls,
    'owned question waits without repeated AI, extraction or message');
insertMessage($conversation,'wamid.dialogue-quantity-answer','inbound','عايز 1.5 كيلو فسيخ',361,null,'US.dialogue-fixture');
dialogueAdvance(362);
DialogueAi::$data['items'][0]['quantity']='1.5'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['evidence']['request_ids']=['m1','m5'];
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===2&&str_contains(dialogueLastText(),'1.5 كيلو فسيخ')
    &&str_contains(dialogueLastText(),'اكتب موافق'),'actual quantity answer leads to factual recap before any ERP');
dialogueNoErp('grounded quantity answer and model DRAFT still require actual recap approval');
insertMessage($conversation,'wamid.dialogue-approval','inbound','موافق',421,null,'US.dialogue-fixture');
dialogueAdvance(422);
DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
DialogueAi::$data['evidence']['confirmation_ids']=['m6'];
DialogueAi::$data['evidence']['customer_acceptance_ids']=['m7'];
$completed=dialogueRun();
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_tickets')->first()->branch==='f:363',
    'real later approval dispatches intended-branch order through unchanged ERP gates');
dialogueExpect(DialogueDB::table('test_print')->count()===1&&DialogueDB::table('test_tickets')->first()->payment_status==='unpaid'
    &&DialogueDB::table('test_billing')->count()===0,'contextual dialogue prints one unpaid order without billing');
dialogueRun();
dialogueExpect(DialogueDB::table('test_tickets')->count()===1&&DialogueDB::table('test_print')->count()===1
    &&count($dialogueGraphs)===2,'completed contextual dialogue remains at most once');

[$conversation,$actualClause]=dialogueBarePrepare();
insertMessage($conversation,'wamid.dialogue-bare-approval','inbound','موافق',421,null,'US.dialogue-fixture');
dialogueAdvance(422); dialogueBareFinalData($actualClause);
$completed=dialogueRun();
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_print')->count()===1,'short actual amount and later complete recap approval finish one order');
foreach (['شكرا','قال العميل موافق','موافق؟','مش موافق','2 كيلو فسيخ'] as $reply) {
    [$conversation,$actualClause]=dialogueBarePrepare();
    insertMessage($conversation,'wamid.dialogue-bare-not-approval','inbound',$reply,421,null,'US.dialogue-fixture');
    dialogueAdvance(422); dialogueBareFinalData($actualClause); dialogueRun();
    dialogueNoErp('bare quantity cannot turn courtesy, quote, question, refusal or changed amount into approval '.$reply);
}

foreach (['FAIL','INVALID'] as $choiceMode) {
    dialogueSetup(); dialogueDraft(); DialogueFixtureProvider::$choiceMode=$choiceMode; dialogueRun();
    dialogueExpect(count($dialogueGraphs)===1&&str_contains(dialogueLastText(),'فسيخ')
        &&!str_contains(dialogueLastText(),'c999'),'malformed or failed AI retains grounded fallback '.$choiceMode);
    dialogueNoErp('fallback asks facts without ERP '.$choiceMode);
}
dialogueSetup(); dialogueDraft(); DialogueFixtureProvider::$choiceMode='WAIT'; dialogueRun();
dialogueExpect(count($dialogueGraphs)===0&&DialogueDB::table('whatsapp_reply_requests')->count()===1
    &&DialogueDB::table('whatsapp_reply_requests')->value('state')==='FAILED'
    &&DialogueDB::table('whatsapp_reply_requests')->value('send_started_at')===null,
    'WAIT leaves one durable no-send tombstone without a customer message');
dialogueAdvance(); dialogueRun();
dialogueExpect(count(DialogueFixtureProvider::$contexts)===1&&count($dialogueGraphs)===0,
    'WAIT tombstone prevents repeated AI for same inbound');

dialogueSetup(); dialogueDraft(); $dialogueGraphMode='UNKNOWN'; dialogueRun();
dialogueExpect(count($dialogueGraphs)===1&&DialogueDB::table('whatsapp_reply_requests')->value('state')==='UNKNOWN',
    'uncertain contextual send preserves UNKNOWN');
dialogueAdvance(); dialogueRun();
dialogueExpect(count($dialogueGraphs)===1&&count(DialogueFixtureProvider::$contexts)===1,
    'UNKNOWN prevents repeated contextual AI or Graph');
$held=DialogueDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();
$heldPlan=(new ReflectionMethod($workflow,'missingInformationPlan'))->invoke($workflow,(int)$held->id,(object)['id'=>7]);
dialogueExpect(is_array($heldPlan),'uncertain receipt leaves actual contextual plan available only for observation');
$otherActor=app(DialogueService::class)->request((object)['id'=>8],$heldPlan,static fn()=>$heldPlan);
dialogueExpect($otherActor['status']==='ALREADY_ASKED'&&count($dialogueGraphs)===1
    &&count(DialogueFixtureProvider::$contexts)===1&&DialogueDB::table('whatsapp_reply_requests')->count()===1,
    'different central actor cannot create a second reservation, AI spend or uncertain send');

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ كامل أو مخلي');
DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect($context['kind']==='OPTION_CHOICE'&&array_column($context['candidates'],'label')===['كامل','مخلي'],
    'ambiguous real branch option exposes current catalog labels');
dialogueExpect(str_contains(dialogueLastText(),'كامل')&&str_contains(dialogueLastText(),'مخلي'),
    'customer sees actual offered choices instead of a generic retry');
dialogueNoErp('catalog choice question cannot select or dispatch a product option');

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ');
DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
DialogueFixtureCatalog::$products=[
    ['id'=>11,'name'=>'فسيخ نبروه','available'=>true,'quantity_mode'=>'weight','options'=>[]],
    ['id'=>12,'name'=>'فسيخ دسوق','available'=>true,'quantity_mode'=>'weight','options'=>[]],
    ['id'=>13,'name'=>'فسيخ غير متاح','available'=>false,'quantity_mode'=>'weight','options'=>[]],
];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect($context['kind']==='PRODUCT_CHOICE'
    &&array_column($context['candidates'],'label')===['فسيخ نبروه','فسيخ دسوق'],
    'generic product ambiguity offers only actual available branch products');
dialogueExpect(str_contains(dialogueLastText(),'فسيخ نبروه')&&str_contains(dialogueLastText(),'فسيخ دسوق')
    &&!str_contains(dialogueLastText(),'غير متاح'),'customer receives real available menu choices');
dialogueNoErp('model candidate choice renders a question and never maps products automatically');

foreach (['فسيح','الفسيخ'] as $literal) {
    dialogueSetup(); dialogueDraft('عايز 1 كيلو '.$literal);
    DialogueAi::$data['items'][0]=['name'=>$literal,'quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null];
    DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
    dialogueRun(); $context=dialogueLastContext();
    dialogueExpect($context['kind']==='PRODUCT_CHOICE'&&$context['item_name']===$literal
        &&array_column($context['candidates'],'label')===['فسيخ'],
        'near spelling or article variant suggests exact live menu label without rewriting customer literal '.$literal);
    dialogueNoErp('suggested spelling is a question and never an automatic product mapping '.$literal);
}

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ كامل ومخلي');
DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect($context['kind']==='OPTION_CHOICE'&&array_column($context['candidates'],'label')===['كامل','مخلي'],
    'explicit ambiguity with attached waw requests actual options instead of premature factual recap');
dialogueNoErp('attached option ambiguity stays question only');

dialogueSetup(); $conversation=dialogueDraft('عايز 1 كيلو فسيخ كامل أو مخلي');
DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
dialogueRun();
dialogueExpect(dialogueLastContext()['kind']==='OPTION_CHOICE','actual named option question precedes short choice');
insertMessage($conversation,'wamid.dialogue-short-option','inbound','مخلي',361,null,'US.dialogue-fixture');
dialogueAdvance(362);
DialogueAi::$data['items'][0]['option_hint']='مخلي';
DialogueAi::$data['evidence']['request_ids']=['m1','m5'];
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===2&&str_contains(dialogueLastText(),'1 كيلو فسيخ مخلي')
    &&str_contains(dialogueLastText(),'اكتب موافق'),
    'short actual offered option answer prepares full selected basket recap without repeated choice question');
dialogueNoErp('bare option choice does not itself execute an ERP order');
$actualClause=null;
foreach (\App\Services\Dashboard\WhatsAppOrderConversationEvidence::clauses(dialogueLastText()) as $index=>$clause)
    if (str_contains($clause,'1 كيلو فسيخ مخلي')) $actualClause=$index;
dialogueExpect(is_int($actualClause),'selected option reference uses the actual recap clause');
insertMessage($conversation,'wamid.dialogue-option-approval','inbound','موافق',421,null,'US.dialogue-fixture');
dialogueAdvance(422); DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
DialogueAi::$data['items'][0]['cart_reference']=null;
DialogueAi::$data['items'][0]['conversation_reference']=['name_anchor_id'=>'m1','business_offer_id'=>'m4',
    'customer_quantity_id'=>'m1','business_summary_id'=>'m6','summary_clause_index'=>$actualClause,'customer_option_id'=>'m5'];
DialogueAi::$data['evidence']['confirmation_ids']=['m6'];
DialogueAi::$data['evidence']['customer_acceptance_ids']=['m7'];
$completed=dialogueRun();
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_print')->count()===1&&\App\Services\Dashboard\PosServiceTicket::$lastPayload['items'][0]['option_id']==='clean',
    'bare menu selection and later exact recap approval dispatch one order with the actual chosen ERP option');

foreach (['ومخلي','بمخلي'] as $literalHint) {
    dialogueSetup(); $conversation=dialogueDraft('عايز 1 كيلو فسيخ '.$literalHint);
    DialogueAi::$data['items'][0]=['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>$literalHint];
    DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
    DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
    dialogueRun();
    dialogueExpect(count($dialogueGraphs)===1&&str_contains(dialogueLastText(),'1 كيلو فسيخ '.$literalHint)
        &&str_contains(dialogueLastText(),'اكتب موافق'),
        'single proved Arabic-prefixed native option retains its literal hint in actual recap '.$literalHint);
    $cached=DialogueDB::table('whatsapp_order_drafts')->first();
    $stored=json_decode(DialogueCrypt::decryptString($cached->extraction),true);
    dialogueExpect($stored['items'][0]['option_hint']===$literalHint,'option mapping preserves encrypted authoritative literal '.$literalHint);
    dialogueNoErp('prefix association alone never bypasses later factual approval '.$literalHint);
    insertMessage($conversation,'wamid.dialogue-prefixed-option-approval','inbound','موافق',361,null,'US.dialogue-fixture');
    dialogueAdvance(362); DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
    DialogueAi::$data['evidence']['confirmation_ids']=['m4'];
    DialogueAi::$data['evidence']['customer_acceptance_ids']=['m5'];
    $completed=dialogueRun();
    dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
        &&DialogueDB::table('test_print')->count()===1&&\App\Services\Dashboard\PosServiceTicket::$lastPayload['items'][0]['option_id']==='clean',
        'later actual approval maps one proven prefixed hint to the exact single live option '.$literalHint);
}
foreach (['وومخلي','abcومخلي','مخليabc','كامل ومخلي'] as $literalHint) {
    dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ '.$literalHint);
    DialogueAi::$data['items'][0]=['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>$literalHint];
    DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
    DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
    dialogueRun();
    dialogueExpect(count($dialogueGraphs)===0||!str_contains(dialogueLastText(),'اكتب موافق'),
        'multiple prefixes, embedded suffixes and mixed actual options never become a factual selection '.$literalHint);
    dialogueNoErp('unresolved prefixed or conflicting hint never maps an ERP option '.$literalHint);
}
dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ كامل ومخلي');
DialogueAi::$data['items'][0]=['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>'ومخلي'];
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===0||!str_contains(dialogueLastText(),'اكتب موافق'),
    'one prefixed model hint cannot discard a second actual conflicting option in native text');
dialogueNoErp('prefixed hint cannot hide complete whole-and-fillet conflict');

dialogueSetup(); dialogueDraft('عايز فسيخ، السمكة كيلو ونص');
DialogueAi::$data['items'][0]['quantity']='1.5'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH']; dialogueRun();
$context=dialogueLastContext();
dialogueExpect($context['kind']==='QUANTITY_ASSOCIATION'&&$context['field']==='QUANTITY'
    &&$context['item_name']==='فسيخ','fish-size statement asks actual order amount without reinterpreting fish weight');
dialogueNoErp('fish size cannot authorize an assumed order quantity');

// Rejected extraction of a reply to our own question permits clarification only.
dialogueSetup(); $conversation=dialogueDraft(); dialogueRun();
$validData=DialogueAi::$data;
insertMessage($conversation,'wamid.dialogue-unclear-answer','inbound','حبتين وسط',361,null,'US.dialogue-fixture');
dialogueAdvance(362); DialogueAi::$failure=true; DialogueAi::$failureReason='INVALID_EVIDENCE';
DialogueAi::$failureDiagnostic=['stage'=>'EXTRACTION','validation_category'=>'CONVERSATION_DATA_UNACKNOWLEDGED'];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect(count($dialogueGraphs)===2&&$context['kind']==='UNREADABLE'&&$context['field']==='QUANTITY',
    'unclear reply after accepted amount question clarifies that topic instead of reasking contact facts');
$rejected=DialogueDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();
$rejectedData=json_decode(DialogueCrypt::decryptString($rejected->extraction),true,32,JSON_THROW_ON_ERROR);
dialogueExpect($rejected->status==='REVIEW'&&$rejected->reason==='INVALID_EXTRACTION'
    &&isset($rejectedData['analysis_failure'])&&!array_key_exists('customer',$rejectedData)&&!array_key_exists('items',$rejectedData),
    'question-only recovery leaves rejected facts absent and encrypted failure intact');
dialogueNoErp('unreadable quantity answer cannot create customer, ticket, print or billing');
insertMessage($conversation,'wamid.dialogue-clear-answer','inbound','عايز 1.5 كيلو فسيخ',421,null,'US.dialogue-fixture');
dialogueAdvance(422); DialogueAi::$failure=false; DialogueAi::$data=$validData;
DialogueAi::$data['items'][0]['quantity']='1.5'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
DialogueAi::$data['evidence']['request_ids']=['m1','m7'];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===3&&str_contains(dialogueLastText(),'1.5 كيلو فسيخ')
    &&str_contains(dialogueLastText(),'اكتب موافق'),'normal fresh extraction after clarification produces actual recap');
dialogueNoErp('normal fresh extraction still awaits actual recap approval');
insertMessage($conversation,'wamid.dialogue-recovery-approval','inbound','موافق',481,null,'US.dialogue-fixture');
dialogueAdvance(482); DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
DialogueAi::$data['evidence']['confirmation_ids']=['m8'];
DialogueAi::$data['evidence']['customer_acceptance_ids']=['m9'];
$completed=dialogueRun();
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_print')->count()===1&&DialogueDB::table('test_tickets')->first()->payment_status==='unpaid',
    'clarification recovery reaches one unpaid printed ERP order only through normal validation and later approval');

dialogueSetup(); dialogueDraft(); DialogueAi::$failure=true; DialogueAi::$failureReason='INVALID_EVIDENCE';
dialogueRun(); $failedCalls=DialogueAi::$calls; dialogueRun();
dialogueExpect(count(DialogueFixtureProvider::$contexts)===0&&count($dialogueGraphs)===0&&DialogueAi::$calls===$failedCalls
    &&DialogueDB::table('whatsapp_order_drafts')->value('reason')==='INVALID_EXTRACTION',
    'initial invalid extraction without an accepted owned question does not trigger synthetic recovery or repeated AI');
dialogueNoErp('initial invalid extraction never creates ERP');

dialogueSetup(); $conversation=dialogueDraft('عايز فسيخ',true,'تم تأكيد الطلب');
$original=DialogueAi::$data; DialogueAi::$failure=true; DialogueAi::$failureReason='INVALID_EVIDENCE';
DialogueAi::$failureDiagnostic=['stage'=>'EXTRACTION','validation_category'=>'ITEM_REQUEST_QUANTITY','validation_field'=>'ITEM_QUANTITY'];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect(count($dialogueGraphs)===1&&$context['kind']==='UNREADABLE'&&$context['field']==='QUANTITY',
    'actual fresh purchase and completed merchant turn permit one initial clarification of rejected amount');
$failed=DialogueDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();
$failureData=json_decode(DialogueCrypt::decryptString($failed->extraction),true);
dialogueExpect($failed->reason==='INVALID_EXTRACTION'&&isset($failureData['analysis_failure'])
    &&!array_key_exists('customer',$failureData)&&!array_key_exists('items',$failureData),
    'initial clarification preserves rejected failure without promoting any extracted facts');
dialogueNoErp('initial invalid extraction recovery is question only');
insertMessage($conversation,'wamid.dialogue-initial-recovery-answer','inbound','1.5 كيلو فسيخ',361,null,'US.dialogue-fixture');
dialogueAdvance(362); DialogueAi::$failure=false; DialogueAi::$data=$original;
DialogueAi::$data['items'][0]['quantity']='1.5'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH']; DialogueAi::$data['evidence']['request_ids']=['m1','m5'];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===2&&str_contains(dialogueLastText(),'1.5 كيلو فسيخ')
    &&str_contains(dialogueLastText(),'اكتب موافق'),'fresh normal extraction after initial recovery prepares full factual recap');
dialogueNoErp('initial recovery still needs a later actual recap approval');
insertMessage($conversation,'wamid.dialogue-initial-recovery-approval','inbound','موافق',421,null,'US.dialogue-fixture');
dialogueAdvance(422); DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
DialogueAi::$data['evidence']['confirmation_ids']=['m6']; DialogueAi::$data['evidence']['customer_acceptance_ids']=['m7'];
$completed=dialogueRun();
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_print')->count()===1,'initial recovery reaches one ERP order only after normal extraction and actual approval');

foreach (['NO_PURCHASE','TENTATIVE_MERCHANT','MERCHANT_QUESTION','NO_COMPLETION','FUTURE_MERCHANT','NEGATED_FUTURE'] as $blockedRecovery) {
    dialogueSetup();
    $request=$blockedRecovery==='NO_PURCHASE'?'فسيخ بكام؟':'عايز فسيخ';
    $business=['TENTATIVE_MERCHANT'=>'مبدئيًا تم تأكيد الطلب','MERCHANT_QUESTION'=>'تم تأكيد الطلب؟',
        'NO_COMPLETION'=>'تمام، نكمل بيانات الطلب.','FUTURE_MERCHANT'=>'سيتم تأكيد الطلب بعد مراجعة البيانات',
        'NEGATED_FUTURE'=>'لن يتم تأكيد الطلب'][$blockedRecovery]??'تم تأكيد الطلب';
    dialogueDraft($request,true,$business); DialogueAi::$failure=true; DialogueAi::$failureReason='INVALID_EVIDENCE';
    DialogueAi::$failureDiagnostic=['stage'=>'EXTRACTION','validation_field'=>'ITEM_QUANTITY'];
    dialogueRun();
    dialogueExpect(count($dialogueGraphs)===0&&count(DialogueFixtureProvider::$contexts)===0,
        'initial extraction failure cannot borrow absent intent or unfinished merchant authority '.$blockedRecovery);
    dialogueNoErp('blocked initial recovery cannot write ERP '.$blockedRecovery);
}

foreach (['WAIT','UNKNOWN','CANCEL'] as $unownedCase) {
    dialogueSetup(); $conversation=dialogueDraft();
    if ($unownedCase==='WAIT') DialogueFixtureProvider::$choiceMode='WAIT';
    if ($unownedCase==='UNKNOWN') $dialogueGraphMode='UNKNOWN';
    dialogueRun(); $firstGraphCount=count($dialogueGraphs);
    insertMessage($conversation,'wamid.dialogue-recovery-refusal','inbound',
        $unownedCase==='CANCEL'?'مش عايز الطلب':'حبتين وسط',361,null,'US.dialogue-fixture');
    dialogueAdvance(362); DialogueAi::$failure=true; DialogueAi::$failureReason='INVALID_EVIDENCE';
    DialogueFixtureProvider::$choiceMode='ASK'; $dialogueGraphMode='ACCEPTED'; dialogueRun();
    dialogueExpect(count(DialogueFixtureProvider::$contexts)===1&&count($dialogueGraphs)===$firstGraphCount,
        'rejected reply cannot recover through absent acceptance or actual cancellation '.$unownedCase);
    dialogueNoErp('blocked recovery never writes ERP '.$unownedCase);
}

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ كامل أو مخلي');
DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']];
DialogueFixtureProvider::$duringQuestion=static function(): void { DialogueCatalog::$options=[['id'=>'whole','label'=>'كامل فقط']]; };
dialogueRun();
dialogueExpect(count(DialogueFixtureProvider::$contexts)===1&&count($dialogueGraphs)===0,
    'live catalog drift during AI prevents stale choice text from reaching Graph');
dialogueNoErp('catalog drift cannot write ERP');

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ كامل أو مخلي');
DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
DialogueFixtureCatalog::$products=[
    ['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight',
        'options'=>[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']]],
    ['id'=>14,'name'=>'رنجة','available'=>false,'quantity_mode'=>'weight','options'=>[]],
];
DialogueFixtureProvider::$duringQuestion=static function(): void { DialogueFixtureCatalog::$products[1]['available']=true; };
dialogueRun();
dialogueExpect(count(DialogueFixtureProvider::$contexts)===1&&count($dialogueGraphs)===0,
    'availability drift outside offered candidate labels still invalidates exact catalog snapshot');
dialogueNoErp('private catalog fingerprint protects against unshown menu drift');

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ ورنجة');
DialogueAi::$data['items']=[
    ['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
    ['name'=>'رنجة','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null],
];
DialogueFixtureCatalog::$products=[
    ['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','options'=>[]],
    ['id'=>12,'name'=>'رنجة','available'=>true,'quantity_mode'=>'weight','options'=>[]],
];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect($context['field']==='QUANTITY'&&$context['item_name']==='رنجة'
    &&str_contains(dialogueLastText(),'رنجة'),
    'missing quantity on second item names that actual unresolved item');
dialogueNoErp('second-item quantity is never copied from resolved first product');

foreach ([true,false] as $citeUnbound) {
    dialogueSetup(); $conversation=dialogueDraft('عايز 1 كيلو فسيخ');
    insertMessage($conversation,'wamid.dialogue-unbound-new-quantity','inbound','2 كيلو',4,null,'US.dialogue-fixture');
    insertMessage($conversation,'wamid.dialogue-unbound-stale-summary','outbound',
        'تأكيد الطلب: 1 كيلو فسيخ إلى شارع المثال المنصوره',5,null,'US.dialogue-fixture');
    DialogueAi::$data['decision']='CONFIRMED';
    DialogueAi::$data['items'][0]['quantity']='1'; DialogueAi::$data['items'][0]['quantity_mode']='weight';
    DialogueAi::$data['issues']=['MISSING_BRANCH']; DialogueAi::$data['evidence']['request_ids']=$citeUnbound?['m1','m4']:['m1'];
    DialogueAi::$data['evidence']['confirmation_ids']=['m5'];
    dialogueRun();
    dialogueNoErp('separate '.($citeUnbound?'cited':'uncited').' unbound actual 2kg reply cannot execute an older 1kg order');
}

dialogueSetup(); $conversation=dialogueDraft('عايز 1 كيلو فسيخ');
app()->instance(\App\Services\Dashboard\PosServiceTicket::class,new DialogueMixedBasketPos());
insertMessage($conversation,'wamid.dialogue-mixed-second-product','inbound','وعايز رنجة',4,null,'US.dialogue-fixture');
insertMessage($conversation,'wamid.dialogue-mixed-merchant','outbound','تمام، نكمل بيانات الطلب.',5,null,'US.dialogue-fixture');
DialogueAi::$data['items']=[
    ['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
    ['name'=>'رنجة','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null],
];
DialogueAi::$data['evidence']['request_ids']=['m1','m4'];
DialogueFixtureCatalog::$products=[
    ['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','options'=>[]],
    ['id'=>12,'name'=>'رنجة','available'=>true,'quantity_mode'=>'weight','options'=>[]],
];
dialogueRun();
dialogueExpect(dialogueLastContext()['field']==='QUANTITY'&&dialogueLastContext()['item_name']==='رنجة',
    'mixed basket asks only the second product missing quantity');
insertMessage($conversation,'wamid.dialogue-mixed-bare-quantity','inbound','نصف كيلو',361,null,'US.dialogue-fixture');
dialogueAdvance(362);
DialogueAi::$data['items'][1]=['name'=>'رنجة','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>null,
    'cart_reference'=>null,'conversation_reference'=>null];
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH']; DialogueAi::$data['evidence']['request_ids']=['m1','m4','m7'];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===2&&str_contains(dialogueLastText(),'1 كيلو فسيخ')
    &&str_contains(dialogueLastText(),'0.5 كيلو رنجة')&&str_contains(dialogueLastText(),'اكتب موافق'),
    'actual bare second quantity produces complete recap retaining the separate first quantity');
dialogueNoErp('mixed direct and contextual basket needs later whole-basket approval');
$actualClause=null;
foreach (\App\Services\Dashboard\WhatsAppOrderConversationEvidence::clauses(dialogueLastText()) as $index=>$clause)
    if (str_contains($clause,'0.5 كيلو رنجة')) $actualClause=$index;
dialogueExpect(is_int($actualClause),'mixed basket contextual reference selects actual second recap clause');
insertMessage($conversation,'wamid.dialogue-mixed-approval','inbound','موافق',421,null,'US.dialogue-fixture');
dialogueAdvance(422); DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
DialogueAi::$data['items'][1]['conversation_reference']=['name_anchor_id'=>'m4','business_offer_id'=>'m6',
    'customer_quantity_id'=>'m7','business_summary_id'=>'m8','summary_clause_index'=>$actualClause,'customer_option_id'=>null];
DialogueAi::$data['evidence']['confirmation_ids']=['m8']; DialogueAi::$data['evidence']['customer_acceptance_ids']=['m9'];
$completed=dialogueRun();
$saved=\App\Services\Dashboard\PosServiceTicket::$lastPayload['items']??null;
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_print')->count()===1&&is_array($saved)&&count($saved)===2,
    'mixed basket actual whole-recap approval saves one order and one print');
dialogueExpect($saved[0]['product_id']===11&&$saved[0]['quantity']==='1'
    &&$saved[1]['product_id']===12&&$saved[1]['quantity']==='0.5',
    'actual ERP payload preserves both correct product identities and independently proved quantities');
dialogueExpect(DialogueDB::table('test_tickets')->first()->payment_status==='unpaid'
    &&DialogueDB::table('test_billing')->count()===0,'mixed basket remains unpaid without billing');
dialogueRun();
dialogueExpect(DialogueDB::table('test_tickets')->count()===1&&DialogueDB::table('test_print')->count()===1,
    'mixed basket replay creates no duplicate ticket or print');

// A common single customer message may name a second item without an amount.
// Only the later actual question naming that second item can associate its bare answer.
dialogueSetup(); $conversation=dialogueDraft('عايز 1 كيلو فسيخ و رنجة');
app()->instance(\App\Services\Dashboard\PosServiceTicket::class,new DialogueMixedBasketPos());
DialogueAi::$data['items']=[
    ['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
    ['name'=>'رنجة','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null],
];
DialogueFixtureCatalog::$products=[
    ['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','options'=>[]],
    ['id'=>12,'name'=>'رنجة','available'=>true,'quantity_mode'=>'weight','options'=>[]],
];
dialogueRun();
dialogueExpect(dialogueLastContext()['field']==='QUANTITY'&&dialogueLastContext()['item_name']==='رنجة',
    'shared original name row receives a real question naming only the unresolved second product');
insertMessage($conversation,'wamid.dialogue-shared-bare-quantity','inbound','نصف كيلو',361,null,'US.dialogue-fixture');
dialogueAdvance(362);
DialogueAi::$data['items'][1]=['name'=>'رنجة','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>null,
    'cart_reference'=>null,'conversation_reference'=>null];
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH']; DialogueAi::$data['evidence']['request_ids']=['m1','m5'];
dialogueRun();
dialogueExpect(count($dialogueGraphs)===2&&str_contains(dialogueLastText(),'1 كيلو فسيخ')
    &&str_contains(dialogueLastText(),'0.5 كيلو رنجة')&&str_contains(dialogueLastText(),'اكتب موافق'),
    'uniquely named later question resolves shared original names before full basket approval');
dialogueNoErp('shared original names plus bare answer remain unapproved before factual recap acceptance');
$actualClause=null;
foreach (\App\Services\Dashboard\WhatsAppOrderConversationEvidence::clauses(dialogueLastText()) as $index=>$clause)
    if (str_contains($clause,'0.5 كيلو رنجة')) $actualClause=$index;
dialogueExpect(is_int($actualClause),'shared-anchor final reference uses actual separately rendered second item clause');
insertMessage($conversation,'wamid.dialogue-shared-approval','inbound','موافق',421,null,'US.dialogue-fixture');
dialogueAdvance(422); DialogueAi::$data['decision']='CONFIRMED'; DialogueAi::$data['issues']=['MISSING_BRANCH'];
DialogueAi::$data['items'][1]['conversation_reference']=['name_anchor_id'=>'m1','business_offer_id'=>'m4',
    'customer_quantity_id'=>'m5','business_summary_id'=>'m6','summary_clause_index'=>$actualClause,'customer_option_id'=>null];
DialogueAi::$data['evidence']['confirmation_ids']=['m6']; DialogueAi::$data['evidence']['customer_acceptance_ids']=['m7'];
$completed=dialogueRun(); $saved=\App\Services\Dashboard\PosServiceTicket::$lastPayload['items']??null;
dialogueExpect($completed['dispatched']===1&&DialogueDB::table('test_tickets')->count()===1
    &&DialogueDB::table('test_print')->count()===1&&is_array($saved)&&count($saved)===2
    &&$saved[0]['product_id']===11&&$saved[0]['quantity']==='1'&&$saved[1]['product_id']===12&&$saved[1]['quantity']==='0.5',
    'actual approval of shared-anchor basket saves exactly the independently resolved two quantities');

dialogueSetup(); dialogueDraft('عايز 1 كيلو فسيخ مخلي و1 كيلو رنجة بطارخ أو ناعمة');
DialogueAi::$data['items']=[
    ['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>'مخلي'],
    ['name'=>'رنجة','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
];
DialogueAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH','AMBIGUOUS_ITEM'];
DialogueFixtureCatalog::$products=[
    ['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight',
        'options'=>[['id'=>'whole','label'=>'كامل'],['id'=>'clean','label'=>'مخلي']]],
    ['id'=>12,'name'=>'رنجة','available'=>true,'quantity_mode'=>'weight',
        'options'=>[['id'=>'roe','label'=>'بطارخ'],['id'=>'soft','label'=>'ناعمة']]],
];
dialogueRun(); $context=dialogueLastContext();
dialogueExpect($context['kind']==='OPTION_CHOICE'&&$context['item_name']==='رنجة'
    &&array_column($context['candidates'],'label')===['بطارخ','ناعمة'],
    'resolved first option is preserved while only ambiguous second item offers its actual options');
dialogueNoErp('ambiguous second product cannot be silently resolved from first selection');

dialogueSetup(); dialogueDraft('عايز فسيخ',true,'من فضلك حدد الفرع أو المدينة للطلب');
DialogueAi::$data['customer']['area']=null;
DialogueAi::$data['branch_hint']='شبرا الخيمة';
DialogueAi::$data['evidence']['request_ids']=['m1','m4'];
DialogueAi::$data['issues']=['UNCONFIRMED','CONFLICTING_DETAILS','AMBIGUOUS_BRANCH','MISSING_QUANTITY'];
insertMessage((int)DialogueDB::table('whatsapp_inbox_conversations')->value('id'),
    'wamid.dialogue-branch-conflict','inbound','الفرع شبرا الخيمة',4,null,'US.dialogue-fixture');
dialogueRun(); $context=dialogueLastContext();
dialogueExpect($context['field']==='BRANCH'&&$context['kind']==='CONFLICT',
    'conflicting literal address city and branch receive branch clarification before quantity questions');
dialogueNoErp('conflicting branch cannot route or create ERP automatically');

dialogueSetup(); $conversation=dialogueDraft();
DialogueFixtureProvider::$duringQuestion=static function() use ($conversation): void {
    insertMessage($conversation,'wamid.dialogue-cancel-during-ai','inbound','مش عايز الطلب',301,null,'US.dialogue-fixture');
};
dialogueRun();
dialogueExpect(count(DialogueFixtureProvider::$contexts)===1&&count($dialogueGraphs)===0
    &&DialogueDB::table('whatsapp_reply_requests')->value('send_started_at')===null,
    'new actual customer cancellation during contextual AI prevents Graph after reservation');
dialogueNoErp('late cancellation leaves ERP untouched');

foreach (['PENDING','WINDOW','CANCEL','NATIVE','REVOKED'] as $gate) {
    dialogueSetup(); $conversation=dialogueDraft();
    if ($gate==='PENDING') DialogueDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
    if ($gate==='WINDOW') { \Carbon\Carbon::setTestNow('2026-10-11 22:05:00'); \Carbon\CarbonImmutable::setTestNow('2026-10-11 22:05:00'); }
    if ($gate==='CANCEL') insertMessage($conversation,'wamid.dialogue-cancel','inbound','مش عايز الطلب',4,null,'US.dialogue-fixture');
    if ($gate==='NATIVE') {
        $native=DialogueDB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')->orderByDesc('id')->first();
        $dto=json_decode(DialogueCrypt::decryptString($native->content),true);
        $dto['content']['message']['from']='201999999999';
        DialogueDB::table('whatsapp_inbox_messages')->where('id',$native->id)->update(['content'=>DialogueCrypt::encryptString(json_encode($dto))]);
    }
    if ($gate==='REVOKED') DialogueDB::table('test_users')->where('id',7)->update(['active'=>0]);
    dialogueRun();
    dialogueExpect(count($dialogueGraphs)===0&&count(DialogueFixtureProvider::$contexts)===0,
        'existing gate blocks contextual AI and Graph '.$gate);
    dialogueNoErp('existing gate blocks ERP '.$gate);
}

echo 'WHATSAPP_ORDER_DIALOGUE_WORKFLOW_TESTS='.$dialogueChecks." PASS\n";
