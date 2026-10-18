<?php
// The labeled customer copy uses real native normalization and the existing ERP path.
// All contact details, coordinates and catalog identities below are synthetic.
require __DIR__.'/whatsapp_customer_confirmation_day_workflow_test.php';
use Illuminate\Support\Facades\DB as LabeledDB;
use App\Services\Dashboard\WhatsAppOrderAiProvider as LabeledAi;
use App\Services\Dashboard\PosServiceTicket as LabeledPos;

$labeledWorkflowChecks=0;
function labeledWorkflowExpect($ok,string$label):void{
    $GLOBALS['labeledWorkflowChecks']++;if(!$ok)throw new RuntimeException('LABELED_BRANCH_WORKFLOW_FAILED:'.$label);
}
function labeledWorkflowText():string{
    return "تمام يا أستاذ عميل، دي فورمة طلب فرع شبرا ✅\n*ملخص الطلب - فرع شبرا*\n"
        ."- الصنف: سردين (كيلو) مخلي صافي — 250 ج\n- الإجمالي: 250 ج بدون توصيل\n"
        ."- الاسم: عميل افتراضي\n- العنوان: شارع افتراضي شبرا\n- التليفون: 01000000001\n"
        ."- اللوكيشن: 31.04, 31.37\n- الفرع: شبرا الخيمة (الشارع الجديد) — 0200000000\n"
        ."- التوصيل: خلال 60 لـ 90 دقيقة، والتكلفة بيحددها المندوب حسب اللوكيشن\n"
        .'انسخ الملخص ده وابعته تاني مع كتابة *كل شئ تمام* علشان أأكد الطلب للفرع فوراً.';
}
function labeledWorkflowSetup(?string$approval='كل شئ تمام',?string$text=null):int{
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;dayControl();
    customerRecapClock('2026-10-09 22:05:00');$c=addConversation('US.customer-confirmed');
    insertMessage($c,'wamid.labeled.summary','inbound',$text??labeledWorkflowText(),2,null,'US.customer-confirmed');
    if($approval!==null)insertMessage($c,'wamid.labeled.approval','inbound',$approval,3,null,'US.customer-confirmed');
    DialogueFixtureCatalog::$products=[['id'=>701,'name'=>'سردين','available'=>true,'quantity_mode'=>'weight','price'=>'200.00',
        'options'=>[['id'=>'f:0:extra_clear','label'=>'مخلي صافي','price'=>'250.00']]]];
    app()->instance(LabeledPos::class,new ApprovedAliasPos());
    LabeledAi::$data=\App\Support\WhatsAppOrderExtraction::none();return$c;
}

foreach([false,true]as$pin){
    $c=labeledWorkflowSetup();
    if($pin)insertMessage($c,'wamid.labeled.matching-pin','inbound','',4,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');
    $native=LabeledDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();$r=primaryRun();
    labeledWorkflowExpect($r['errors']===0&&$r['dispatched']===1&&LabeledAi::$calls===0,'native labeled recap and separate positive approval dispatch without AI');
    $data=customerRecapStored();$receipt=$data['evidence_receipt'];
    labeledWorkflowExpect(($receipt['summary_format']??null)==='LABELED_BRANCH_ORDER_SUMMARY'
        &&$receipt['recap_id']!==$receipt['approval_id']&&!isset($receipt['approval_basis']),
        'labeled copy never manufactures self approval or business evidence');
    labeledWorkflowExpect(LabeledPos::$lastPayload['items'][0]['product_id']===701
        &&LabeledPos::$lastPayload['items'][0]['quantity']==='1'&&LabeledPos::$lastPayload['items'][0]['quantity_mode']==='weight'
        &&LabeledPos::$lastPayload['items'][0]['option_id']==='f:0:extra_clear',
        'one kilogram and entire explicit preparation map only to the literal configured sale');
    labeledWorkflowExpect(LabeledDB::table('test_tickets')->value('branch')==='f:363',
        'proven coordinates choose Mansoura fixture despite descriptive Shubra header and address');
    labeledWorkflowExpect(LabeledDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
        'native ciphertext and source message IDs remain unchanged');
    primaryRun();labeledWorkflowExpect(LabeledDB::table('test_tickets')->count()===1&&LabeledDB::table('test_print')->count()===1,
        'repeated worker preserves one ERP ticket and one print job');
    customerRecapSilent('labeled receipt creates no internal customer reply');
}

foreach([null,'كل شئ تمام؟','كل شئ تمام بس غير الصنف']as$approval){
    labeledWorkflowSetup($approval);primaryRun();customerRecapNoErp('missing or conditional consent cannot send labeled receipt');
}
foreach(['change','unknown-text','conflicting-pin','cart']as$guard){
    $c=labeledWorkflowSetup();
    if($guard==='cart')customerRecapNativeExtra($c,'wamid.labeled.cart','order',4,
        ['catalog_id'=>'synthetic','product_items'=>[['product_retailer_id'=>'x','quantity'=>1,'item_price'=>'250','currency'=>'EGP']]]);
    elseif($guard==='conflicting-pin')insertMessage($c,'wamid.labeled.changed-pin','inbound','',4,['lat'=>31.13,'long'=>31.30],'US.customer-confirmed');
    else insertMessage($c,'wamid.labeled.later-text','inbound',$guard==='change'?'بدل السردين':'هل تم إرسال الطلب للفرع؟',4,null,'US.customer-confirmed');
    primaryRun();customerRecapNoErp('later unproved material turn remains blocked '.$guard);
}
labeledWorkflowSetup();DialogueFixtureCatalog::$products[0]['options'][0]['label']='مخلي';primaryRun();
customerRecapNoErp('parser does not drop صافي or infer an unapproved preparation alias');
labeledWorkflowExpect(LabeledDB::table('whatsapp_order_drafts')->value('reason')==='CATALOG_UNRESOLVED',
    'partial preparation wording remains explicit catalog review');
labeledWorkflowSetup();DialogueFixtureCatalog::$products[0]['available']=false;primaryRun();customerRecapNoErp('labeled receipt cannot override live availability');
labeledWorkflowSetup();PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
labeledWorkflowExpect($r['dispatched']===0&&$r['deferred']===1,'new grammar does not bypass dispatch clock');
labeledWorkflowSetup();customerRecapClock('2026-10-10 21:01:00');primaryRun();customerRecapNoErp('yesterday labeled authority cannot be renewed by worker');
labeledWorkflowExpect(LabeledDB::table('whatsapp_order_drafts')->value('reason')==='CUSTOMER_ORDER_PREVIOUS_DAY','native Cairo day still governs labeled approval');

// A current NONE extraction cache can use the existing exact receipt refresh.
$c=labeledWorkflowSetup();PrimaryFixtureControl::$analysisOnly=false;$workflow->analyze($c,(object)['id'=>7]);
$old=LabeledDB::table('whatsapp_order_drafts')->first();$calls=LabeledAi::$calls;
labeledWorkflowExpect($old->status==='NONE'&&$old->reason==='NO_ORDER'&&$old->ticket_id===null,
    'fixture has undelivered model NONE over the actual unchanged native snapshot');
PrimaryFixtureControl::$analysisOnly=true;customerRecapClock('2026-10-09 22:07:00');$r=primaryRun();
labeledWorkflowExpect($r['errors']===0&&$r['dispatched']===1&&LabeledAi::$calls===$calls,
    'existing normal worker refreshes recognized native receipt without another model request or force');
labeledWorkflowExpect(LabeledDB::table('whatsapp_order_drafts')->first()->id===$old->id
    &&LabeledDB::table('whatsapp_order_drafts')->first()->evidence_ceiling===$old->evidence_ceiling,
    'refresh retains draft identity and original native ceiling');
echo 'whatsapp_customer_labeled_branch_workflow: '.$labeledWorkflowChecks." checks passed\n";
