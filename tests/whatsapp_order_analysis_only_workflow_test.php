<?php
// Existing SQLite/native-capture integration suite plus mode-specific external recaps.
require __DIR__.'/whatsapp_order_primary_ai_workflow_test.php';
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

foreach ([false,true] as $ack) {
    $c=analysisDraft(null,'موافق على الطلب',$ack);$r=primaryRun();
    analysisExpect($r['errors']===0&&$r['dispatched']===1&&PrimaryAi::$calls===1
        &&PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,
        'external native recap plus later genuine consent dispatches once'.($ack?' with exact non-mutating acknowledgement':''));
    analysisNoReplies('analysis-only creates no question AI, Graph request or sender reservation');
    primaryRun();analysisExpect(PrimaryDB::table('test_tickets')->count()===1&&PrimaryDB::table('test_print')->count()===1,
        'analysis-only duplicate worker cannot repeat ticket or print');
}
foreach (['2','0.5'] as $quantity) {
    analysisDraft(str_replace('الكمية: 1 كيلو','الكمية: '.$quantity.' كيلو',analysisRecap()),'موافق',true,true,$quantity);
    $r=primaryRun();analysisExpect($r['errors']===0&&$r['dispatched']===1,'actual native workflow dispatches '.$quantity.'kg from exact adjacent quantity/unit recap');
    analysisNoReplies('non-unit quantity still sends no dashboard reply');
}
// A previous dashboard proposal cannot take precedence over the owner's new external recap.
primarySetup();$peer='US.analysis-old-own';$c=addConversation($peer);
insertMessage($c,'wamid.analysis.old.request','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',1,null,$peer);
insertMessage($c,'wamid.analysis.old.pin','inbound','',2,['lat'=>31.04,'long'=>31.37],$peer);
PrimaryAi::$data=extractionData();PrimaryAi::$data['decision']='DRAFT';PrimaryAi::$data['issues']=['UNCONFIRMED'];
PrimaryAi::$data['evidence']['confirmation_ids']=[];PrimaryAi::$data['approximate_total']=null;
$r=primaryRun();analysisExpect(count($dialogueGraphs)===1,'fixture has one previously accepted dashboard proposal');
$graphs=count($dialogueGraphs);$receipts=PrimaryDB::table('whatsapp_reply_requests')->count();$contexts=count(DialogueFixtureProvider::$contexts);
PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;PrimaryDB::table('meta_ai_controls')->update(['revision'=>2]);
insertMessage($c,'wamid.analysis.external.new','outbound',analysisRecap(['الإجمالي التقريبي'=>'غير محدد']),302,null,$peer);
insertMessage($c,'wamid.analysis.external.accept','inbound','موافق على الطلب',303,null,$peer);dialogueAdvance(304);
PrimaryAi::$data['decision']='CONFIRMED';PrimaryAi::$data['issues']=[];PrimaryAi::$data['evidence']['confirmation_ids']=['m4'];PrimaryAi::$data['evidence']['customer_acceptance_ids']=['m5'];
$r=primaryRun();analysisExpect($r['errors']===0&&$r['dispatched']===1,'analysis-only takes current authenticated external recap instead of prior owned proposal');
analysisExpect(count($dialogueGraphs)===$graphs&&PrimaryDB::table('whatsapp_reply_requests')->count()===$receipts
    &&count(DialogueFixtureProvider::$contexts)===$contexts,'accepted older send never causes a new analysis-only send or composition');

foreach (['أهلاً','عايز فسيخ','يعني ايه هيرنج؟'] as $text) {
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    $c=primaryInbound($text);PrimaryAi::$data=PrimaryExtraction::none();
    insertMessage($c,'wamid.external.agent','outbound','أهلاً بحضرتك، تحب تطلب إيه؟',2,null,'US.primary-fixture');
    $r=primaryRun();
    analysisExpect($r['errors']===0&&PrimaryAi::$calls===1,'external business turn can be analyzed without requiring unanswered reply authority');
    analysisNoReplies('incomplete and informational turns remain silent');primaryNoErp('no fixed approved recap is not an order');
}
foreach ([null,'موافق؟','"موافق"','موافق بس خليهم 2 كيلو','مش موافق'] as $approval) {
    analysisDraft(null,$approval);$r=primaryRun();
    primaryNoErp('missing, quoted, questioning, changed or negative consent never executes');analysisNoReplies('invalid consent does not ask again automatically');
}
foreach ([['الاسم'=>'عميل آخر'],['رقم الهاتف'=>'201000000002'],['العنوان'=>'عنوان آخر'],['المنطقة'=>'المحلة'],
    ['الفرع'=>'المحلة'],['نوع الطلب'=>'تيك أواي'],['ملاحظات'=>'بدون تنظيف'],['الإجمالي التقريبي'=>'200.00']] as $change) {
    analysisDraft(analysisRecap($change));$r=primaryRun();
    primaryNoErp('recap field mismatch cannot authorize an extracted older value');analysisNoReplies('recap mismatch remains silent');
}
foreach (['header','quantity','unit','option','extra-item','duplicate-field'] as $change) {
    $recap=analysisRecap();
    if ($change==='header') $recap=str_replace('ملخص الطلب النهائي:','تأكيد الطلب:',$recap);
    if ($change==='quantity') $recap=str_replace('الكمية: 1','الكمية: 2',$recap);
    if ($change==='unit') $recap=str_replace('1 كيلو','1 قطعة',$recap);
    if ($change==='option') $recap=str_replace('1 كيلو','1 كيلو | الاختيار: مخلي',$recap);
    if ($change==='extra-item') $recap.="\n- رنجة | الكمية: 1 كيلو";
    if ($change==='duplicate-field') $recap.="\nالاسم: عمر";
    analysisDraft($recap);primaryRun();primaryNoErp('malformed or different recap basket is not consent to extracted basket');analysisNoReplies('invalid protocol remains silent');
}
foreach (['customer-change','business-change','unrelated-ack','second-ack','uncited-approval'] as $change) {
    $c=analysisDraft();$peer='US.analysis-only';
    if ($change==='customer-change') insertMessage($c,'wamid.analysis.changed','inbound','خلي الطلب 2 كيلو',5,null,$peer);
    if ($change==='business-change') insertMessage($c,'wamid.analysis.changed','outbound','تمام تم تعديل الطلب إلى 2 كيلو',5,null,$peer);
    if ($change==='unrelated-ack') insertMessage($c,'wamid.analysis.changed','outbound','تم إرسال الطلب للفرع',5,null,$peer);
    if ($change==='second-ack') {
        insertMessage($c,'wamid.analysis.ack.1','outbound','جاري مراجعة البيانات لإرسال الطلب للفرع.',5,null,$peer);
        insertMessage($c,'wamid.analysis.ack.2','outbound','جاري مراجعة البيانات لإرسال الطلب للفرع.',6,null,$peer);
    }
    if ($change==='uncited-approval') PrimaryAi::$data['evidence']['customer_acceptance_ids']=[];
    primaryRun();primaryNoErp('post-recap uncited activity or an unsupported acknowledgement cannot execute');analysisNoReplies('ambiguous tail remains silent');
}
analysisDraft(null,'موافق على الطلب',true,false);primaryRun();primaryNoErp('fixed recap never bypasses native delivery point requirement');analysisNoReplies('missing location remains review without sending');

$c=analysisDraft();PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
analysisExpect($r['errors']===0&&$r['deferred']===1&&PrimaryDB::table('whatsapp_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'independent dispatch hours queue an externally approved order');primaryNoErp('outside dispatch schedule no ERP');analysisNoReplies('queued analysis-only never replies');
$calls=PrimaryAi::$calls;\Carbon\Carbon::setTestNow('2026-10-10 06:00:00');\Carbon\CarbonImmutable::setTestNow('2026-10-10 06:00:00');
$r=primaryRun();analysisExpect($r['dispatched']===1&&PrimaryAi::$calls===$calls,'09:00 cached queue resumes with reply clock closed and no repeated AI');analysisNoReplies('scheduled resume remains silent');

$c=analysisDraft();PrimaryAi::$callback=static function(){PrimaryDB::table('meta_ai_controls')->update(['revision'=>2]);};
primaryRun();primaryNoErp('control revision drift during extraction rejects result');analysisNoReplies('control drift creates no reply reservation');
$c=analysisDraft();PrimaryAi::$callback=static function()use($c){insertMessage($c,'wamid.analysis.inflight-change','inbound','الغي الطلب',6,null,'US.analysis-only');};
primaryRun();primaryNoErp('native customer cancellation during extraction rejects stale snapshot');analysisNoReplies('stale snapshot never replies');

foreach (['CLAIMED','UNKNOWN'] as $state) {
    $c=analysisDraft();$now=now('UTC')->format('Y-m-d H:i:s');
    PrimaryDB::table('whatsapp_reply_requests')->insert(['waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
        'conversation_id'=>$c,'actor_id'=>7,'request_hash'=>hash('sha256',$state),'payload_hash'=>hash('sha256','pending-payload'),
        'kind'=>'text','inbound_message_id'=>PrimaryDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->min('id'),
        'state'=>$state,'audit'=>PrimaryCrypt::encryptString('{}'),'claimed_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    $r=primaryRun();analysisExpect(PrimaryAi::$calls===0,'mode transition waits for unresolved existing sender '.$state);primaryNoErp('unresolved send cannot race external agent order');
}
echo 'whatsapp_order_analysis_only_workflow: '.$analysisChecks." checks passed\n";
