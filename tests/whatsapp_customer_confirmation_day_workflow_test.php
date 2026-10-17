<?php
// Native timestamps govern Cairo calendar days; no text date or arrival ID can revive an old order.
require __DIR__.'/whatsapp_customer_compact_confirmation_workflow_test.php';
use Illuminate\Support\Facades\DB as DayDB;
use App\Services\Dashboard\WhatsAppOrderAiProvider as DayAi;
use App\Services\Dashboard\PosServiceTicket as DayPos;

$dayChecks=0;
function dayExpect($ok,string$label):void{
    $GLOBALS['dayChecks']++;if(!$ok)throw new RuntimeException('CUSTOMER_ORDER_DAY_FAILED:'.$label);
}
function daySecond(string$utc):int{return strtotime($utc.' UTC')-strtotime('2026-10-09 22:00:00 UTC');}
function dayControl():void{
    DayDB::table('meta_ai_controls')->update(['started_at_utc'=>'2026-10-08 00:00:00']);
}
function dayCompact(string$nativeUtc,string$nowUtc,?string$legacyApprovalUtc=null):int{
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;dayControl();
    customerRecapClock($nowUtc);$c=addConversation('US.customer-confirmed');
    insertMessage($c,'wamid.day.confirmed','inbound',$legacyApprovalUtc===null?compactText():customerRecapText(),
        daySecond($nativeUtc),null,'US.customer-confirmed');
    if($legacyApprovalUtc!==null)insertMessage($c,'wamid.day.approved','inbound','كل شيء تمام',daySecond($legacyApprovalUtc),null,'US.customer-confirmed');
    DialogueFixtureCatalog::$products=$legacyApprovalUtc===null?[compactWholeHerring()]
        :[['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','price'=>'100.00','options'=>[]]];
    app()->instance(DayPos::class,new ApprovedAliasPos());return$c;
}
function dayInspect(int$c):array{
    global$workflow;$m=new ReflectionMethod($workflow,'snapshot');$m->setAccessible(true);
    return$m->invoke($workflow,$c,0,(int)DayDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id'));
}

// These pairs have the SAME UTC date but different Cairo calendar dates.
foreach([null,'2026-10-09 20:59:20']as$approval){
    dayCompact('2026-10-09 20:59:00','2026-10-09 21:01:00',$approval);primaryRun();
    customerRecapNoErp('yesterday Cairo confirmed order younger than 24 hours is not eligible');
    dayExpect(DayDB::table('whatsapp_order_drafts')->value('reason')==='CUSTOMER_ORDER_PREVIOUS_DAY',
        'compact and legacy recap authority expire at Cairo midnight, not UTC midnight or 24 hours');
}
dayCompact('2026-10-09 20:59:00','2026-10-09 21:01:00','2026-10-09 21:00:30');primaryRun();
customerRecapNoErp('today approval cannot revive yesterday customer recap');
dayExpect(DayDB::table('whatsapp_order_drafts')->value('reason')==='CUSTOMER_ORDER_PREVIOUS_DAY',
    'legacy recap date itself must be today even when approval is new');

// Different UTC dates can still be the SAME Cairo calendar day.
dayCompact('2026-10-09 21:30:00','2026-10-10 06:00:00');$r=primaryRun();
dayExpect($r['errors']===0&&$r['dispatched']===1&&DayAi::$calls===0,
    'current Cairo-day order sent before UTC midnight remains eligible at 09:00 Cairo');
customerRecapSilent('same-day calendar check never replies');
dayCompact('2026-10-09 22:15:00','2026-10-09 22:16:00');PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
dayExpect($r['dispatched']===0&&$r['deferred']===1,'today after-hours order can remain pending for same-day opening');
customerRecapClock('2026-10-10 06:00:00');$r=primaryRun();dayExpect($r['dispatched']===1,
    'same Cairo day queued compact order resumes at 09:00');

// Only the newest full confirmed customer recap supplies the basket and dates.
$c=dayCompact('2026-10-09 20:59:00','2026-10-09 21:10:00');
insertMessage($c,'wamid.day.new-current','inbound',str_replace('كيلو رنجة مبطرخ','نصف كيلو رنجة مبطرخ',compactText()),
    daySecond('2026-10-09 21:09:00'),null,'US.customer-confirmed');$r=primaryRun();
dayExpect($r['errors']===0&&$r['dispatched']===1&&DayPos::$lastPayload['items'][0]['quantity']==='0.5',
    'new today complete recap replaces yesterday recap rather than merging quantities');
$stored=customerRecapStored();dayExpect(($stored['evidence_receipt']['recap_id']??null)==='m2',
    'today native recap is the actual same-message order authority');
$c=dayCompact('2026-10-09 21:01:00','2026-10-09 21:10:00');
insertMessage($c,'wamid.day.latest-current','inbound',str_replace('كيلو رنجة مبطرخ','ربع كيلو رنجة مبطرخ',compactText()),
    daySecond('2026-10-09 21:09:00'),null,'US.customer-confirmed');$r=primaryRun();
dayExpect($r['errors']===0&&$r['dispatched']===1&&DayPos::$lastPayload['items'][0]['quantity']==='0.25',
    'two today confirmed recaps select only latest whole basket');

// Yesterday's actual completed order stays immutable; a NEW current-day full
// order may repeat that basket. The same old native receipt may never be reused.
$c=dayCompact('2026-10-09 20:58:00','2026-10-09 20:59:00');$r=primaryRun();
dayExpect($r['dispatched']===1,'yesterday order fixture genuinely completed before Cairo midnight');
$old=DayDB::table('test_tickets')->first();$oldDraft=DayDB::table('whatsapp_order_drafts')->first();
customerRecapClock('2026-10-09 21:01:00');primaryRun();
dayExpect(DayDB::table('test_tickets')->count()===1,'old native command cannot create another ticket after midnight');
insertMessage($c,'wamid.day.new-same-basket','inbound',compactText(),daySecond('2026-10-09 21:01:00'),null,'US.customer-confirmed');
customerRecapClock('2026-10-09 21:02:00');$r=primaryRun();
dayExpect($r['errors']===0&&$r['dispatched']===1&&DayDB::table('test_tickets')->count()===2,
    'new today confirmed basket identical to yesterday can create a new current-day unpaid order');
dayExpect((array)DayDB::table('test_tickets')->where('id',$old->id)->first()===(array)$old
    &&(array)DayDB::table('whatsapp_order_drafts')->where('id',$oldDraft->id)->first()===(array)$oldDraft,
    'current-day fresh order never changes yesterday completed ticket or draft');
insertMessage($c,'wamid.day.same-day-repeat','inbound',compactText(),daySecond('2026-10-09 21:03:00'),null,'US.customer-confirmed');
customerRecapClock('2026-10-09 21:04:00');$r=primaryRun();
dayExpect($r['dispatched']===0&&DayDB::table('test_tickets')->count()===2
    &&DayDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='CUSTOMER_RECAP_REPLAY',
    'same-day new native ID cannot duplicate the already dispatched basket');

// Ordinary AI-confirmed orders also need current-day order authority, not a
// current greeting or contact row appended after yesterday's confirmed order.
function dayGeneric(string$requestUtc,string$summaryUtc,string$approvalUtc,string$nowUtc,?string$latest=null):int{
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;dayControl();customerRecapClock($nowUtc);
    $c=addConversation('US.analysis-only');
    insertMessage($c,'wamid.day.generic-request','inbound','اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز 1 كيلو فسيخ من المنصورة.',
        daySecond($requestUtc),null,'US.analysis-only');
    insertMessage($c,'wamid.day.generic-pin','inbound','',daySecond($requestUtc)+1,['lat'=>31.04,'long'=>31.37],'US.analysis-only');
    insertMessage($c,'wamid.day.generic-summary','outbound',analysisRecap(),daySecond($summaryUtc),null,'US.analysis-only');
    insertMessage($c,'wamid.day.generic-approval','inbound','موافق على الطلب',daySecond($approvalUtc),null,'US.analysis-only');
    if($latest!==null)insertMessage($c,'wamid.day.generic-latest','inbound',$latest,daySecond($nowUtc)-1,null,'US.analysis-only');
    DayAi::$data=extractionData();DayAi::$data['evidence']['confirmation_ids']=['m3'];DayAi::$data['evidence']['customer_acceptance_ids']=['m4'];
    return$c;
}
foreach(['أهلاً','اسمي عمر','موافق على الطلب']as$latest){
    $c=dayGeneric('2026-10-09 20:58:00','2026-10-09 20:58:10','2026-10-09 20:58:20','2026-10-09 21:01:00',$latest);
    $snapshot=dayInspect($c);$m=new ReflectionMethod($workflow,'freshAutomaticEvidence');$m->setAccessible(true);
    dayExpect($m->invoke($workflow,DayAi::$data,$snapshot['transcript'],null,$c)===false,
        'generic historical order authority is not refreshed by today '.$latest);
    primaryRun();customerRecapNoErp('old generic order with current nonorder turn remains in review');
}
$c=dayGeneric('2026-10-09 20:58:00','2026-10-09 21:00:10','2026-10-09 21:00:20','2026-10-09 21:01:00');
primaryRun();customerRecapNoErp('today merchant summary and OK do not renew yesterday actual customer purchase request');
dayExpect(DayDB::table('whatsapp_order_drafts')->value('reason')==='CUSTOMER_ORDER_PREVIOUS_DAY',
    'generic genuine committing customer request must itself belong to current Cairo day');
dayGeneric('2026-10-09 21:00:00','2026-10-09 21:00:10','2026-10-09 21:00:20','2026-10-09 21:01:00');$r=primaryRun();
dayExpect($r['errors']===0&&$r['dispatched']===1,'fully current-day native external recap and approval remain supported');

// Midnight can occur inside a quote or inside the ERP save boundary. The latter
// must roll the enclosing transaction back, including customer/kitchen/print.
dayCompact('2026-10-09 20:58:00','2026-10-09 20:59:00');
app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static fn()=>customerRecapClock('2026-10-09 21:00:00');
primaryRun();customerRecapNoErp('crossing Cairo midnight during quote cannot commit previous-day order');
class DaySaveRolloverPos extends ApprovedAliasPos {
    public function save($channel,array$values,$actor):array{
        $result=parent::save($channel,$values,$actor);customerRecapClock('2026-10-09 21:00:00');return$result;
    }
}
dayCompact('2026-10-09 20:58:00','2026-10-09 20:59:00');app()->instance(DayPos::class,new DaySaveRolloverPos());
$r=primaryRun();dayExpect($r['dispatched']===0&&DayPos::$saveCalls===1,
    'ERP boundary fixture really crossed midnight after its save attempt');
customerRecapNoErp('midnight after ERP save rolls back customer ticket kitchen print and billing together');

echo 'whatsapp_customer_confirmation_day_workflow: '.$dayChecks." checks passed\n";
