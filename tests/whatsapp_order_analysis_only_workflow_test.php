<?php
// Existing SQLite/native-capture integration suite plus mode-specific external recaps.
require __DIR__.'/whatsapp_order_primary_ai_workflow_test.php';
require_once dirname(__DIR__).'/app/Support/WhatsAppCustomerOrderConfirmation.php';
use App\Services\Dashboard\WhatsAppOrderAiProvider as PrimaryAi;
use App\Support\WhatsAppOrderExtraction as PrimaryExtraction;
use Illuminate\Support\Facades\DB as PrimaryDB;
use Illuminate\Support\Facades\Crypt as PrimaryCrypt;

$analysisChecks=0;
function analysisExpect($ok,string $label): void {
    $GLOBALS['analysisChecks']++;
    if (!$ok) throw new RuntimeException('ANALYSIS_ONLY_FAILED:'.$label);
}
function analysisRecap(array $changes=[]): string {
    $fields=array_replace(['الاسم'=>'عمر','رقم الهاتف'=>'201000000001','نوع الطلب'=>'دليفري','الفرع'=>'المنصورة',
        'العنوان'=>'شارع البحر','المنطقة'=>'المنصورة','ملاحظات'=>'لا يوجد','الإجمالي التقريبي'=>'100.00 جنيه'],$changes);
    $lines=['ملخص الطلب النهائي:','تأكيد الطلب:'];
    foreach ($fields as $key=>$value) $lines[]=$key.': '.$value;
    $lines[]='الأصناف:'; $lines[]='- فسيخ | الكمية: 1 كيلو';
    $lines[]='للموافقة على هذه البيانات اكتب: موافق على الطلب';
    return implode("\n",$lines);
}
function analysisDraft(?string $recap=null,?string $approval='موافق على الطلب',bool $ack=false,bool $pin=true,string $quantity='1'): int {
    primarySetup(); PrimaryFixtureControl::$analysisOnly=true; PrimaryFixtureControl::$replyClockClosed=true;
    $peer='US.analysis-only';$c=addConversation($peer);
    insertMessage($c,'wamid.analysis.request','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز '.$quantity.' كيلو فسيخ من المنصورة.',1,null,$peer);
    if ($pin) insertMessage($c,'wamid.analysis.pin','inbound','',2,['lat'=>31.04,'long'=>31.37],$peer);
    insertMessage($c,'wamid.analysis.summary','outbound',$recap??analysisRecap(),3,null,$peer);
    if ($approval!==null) insertMessage($c,'wamid.analysis.accept','inbound',$approval,4,null,$peer);
    if ($ack) insertMessage($c,'wamid.analysis.ack','outbound','جاري مراجعة البيانات لإرسال الطلب للفرع.',5,null,$peer);
    PrimaryAi::$data=extractionData();PrimaryAi::$data['items'][0]['quantity']=$quantity;
    PrimaryAi::$data['evidence']['confirmation_ids']=[$pin?'m3':'m2'];
    PrimaryAi::$data['evidence']['customer_acceptance_ids']=$approval===null?[]:[$pin?'m4':'m3'];
    PrimaryAi::$data['evidence']['location_id']=$pin?'m2':null;
    return $c;
}
function analysisNoReplies(string $label): void {
    analysisExpect(count($GLOBALS['dialogueGraphs'])===0&&count(DialogueFixtureProvider::$contexts)===0
        &&PrimaryDB::table('whatsapp_reply_requests')->count()===0,$label);
}

// Native ANALYZE_ONLY accepts only a full CUSTOMER recap and a separate exact
// customer confirmation. The old BUSINESS-summary protocol is deliberately no
// longer an order source, even if the provider fixture claims CONFIRMED.
foreach ([false,true] as $ack) {
    analysisDraft(null,'كل شئ تمام',$ack);$r=primaryRun();
    analysisExpect($r['errors']===0&&$r['dispatched']===0&&PrimaryAi::$calls===0,
        'business summary plus customer confirmation cannot call extraction or dispatch');
    analysisExpect(PrimaryDB::table('whatsapp_order_drafts')->value('status')==='NONE'
        &&PrimaryDB::table('whatsapp_order_drafts')->value('reason')==='NO_ORDER',
        'no native customer recap persists deterministic NONE rather than a model decision');
    primaryNoErp('business recap is not a customer order');analysisNoReplies('business recap remains silent');
}
foreach (['أهلاً','عايز فسيخ','يعني ايه هيرنج؟','كل شئ تمام'] as $text) {
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    $c=primaryInbound($text);PrimaryAi::$data=extractionData();
    insertMessage($c,'wamid.external.agent','outbound','أهلاً بحضرتك، تحب تطلب إيه؟',2,null,'US.primary-fixture');
    $r=primaryRun();
    analysisExpect($r['errors']===0&&PrimaryAi::$calls===0&&$r['dispatched']===0,
        'ordinary messages and confirmation without customer recap never invoke provider');
    analysisNoReplies('informational turns remain silent');primaryNoErp('nonorder input never produces ERP writes');
}
foreach ([null,'موافق على الطلب','موافق','تمام','كل شئ تمام؟','كل شئ تمام بس خليهم 2 كيلو'] as $approval) {
    analysisDraft(null,$approval);$r=primaryRun();
    analysisExpect(PrimaryAi::$calls===0,'provider cannot fill missing native customer summary');
    primaryNoErp('noncustomer summary is rejected regardless of approval wording');analysisNoReplies('rejected protocol never replies');
}
foreach (['CLAIMED','UNKNOWN'] as $state) {
    $c=analysisDraft();$now=now('UTC')->format('Y-m-d H:i:s');
    PrimaryDB::table('whatsapp_reply_requests')->insert(['waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
        'conversation_id'=>$c,'actor_id'=>7,'request_hash'=>hash('sha256',$state),'payload_hash'=>hash('sha256','pending-payload'),
        'kind'=>'text','inbound_message_id'=>PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->min('id'),
        'state'=>$state,'audit'=>PrimaryCrypt::encryptString('{}'),'claimed_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    primaryRun();analysisExpect(PrimaryAi::$calls===0,'mode transition waits for unresolved existing sender '.$state);
    primaryNoErp('unresolved send cannot race customer order');
}
echo 'whatsapp_order_analysis_only_workflow: '.$analysisChecks." checks passed\n";
