<?php
// Actual native capture/extraction/ERP flow with independently controlled Cairo clocks.
require __DIR__.'/whatsapp_customer_confirmation_workflow_test.php';
use App\Services\Dashboard\WhatsAppOrderAiProvider as ScheduleAi;
use Illuminate\Support\Facades\DB as ScheduleDB;

$scheduleChecks=0;
function scheduleExpect($ok,string $label):void {
    $GLOBALS['scheduleChecks']++;if(!$ok)throw new RuntimeException('ANALYSIS_SCHEDULE_FAILED:'.$label);
}
function scheduleClock(string $utc):void {
    \Carbon\Carbon::setTestNow($utc);\Carbon\CarbonImmutable::setTestNow($utc);
}
function scheduleHours(string $start='23:00',string $end='02:00',array $days=[5]):array {
    return ['enabled'=>true,'days'=>$days,'start'=>$start,'end'=>$end,'all_day'=>false];
}
function scheduleDraft():int {
    $c=customerRecapSetup();PrimaryFixtureControl::$analysisSchedule=scheduleHours();PrimaryFixtureControl::$strictDispatchClock=true;
    return $c;
}
class ScheduleRaceControl extends PrimaryFixtureControl {
    public static bool $closeInsideClaim=false;
    public function analysisGate(string $channel,?string $time=null,?int $id=null,?int $event=null):array {
        if(self::$closeInsideClaim&&ScheduleDB::connection()->transactionLevel()>0) {
            self::$closeInsideClaim=false;self::$analysisSchedule=scheduleHours();
        }
        return parent::analysisGate($channel,$time,$id,$event);
    }
}

$c=scheduleDraft();scheduleClock('2026-10-10 06:00:00');$r=primaryRun();
scheduleExpect($r['errors']===0&&ScheduleAi::$calls===0&&ScheduleDB::table('whatsapp_order_scans')->count()===0
    &&ScheduleDB::table('whatsapp_order_drafts')->count()===0,
    '09:00 dispatch-open analysis-closed unprocessed input consumes no attempt or AI');
analysisNoReplies('analysis-closed worker cannot ask or reserve');primaryNoErp('fresh closed analysis has no cached order');

$c=scheduleDraft();$r=primaryRun();
scheduleExpect($r['errors']===0&&$r['deferred']===1&&ScheduleAi::$calls===0
    &&ScheduleDB::table('whatsapp_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'Saturday 01:05 belongs to Friday overnight analysis while dispatch is closed');
primaryNoErp('open analysis does not bypass dispatch hours');analysisNoReplies('overnight extraction stays silent');
$calls=ScheduleAi::$calls;scheduleClock('2026-10-10 06:00:00');$r=primaryRun();
scheduleExpect($r['errors']===0&&$r['dispatched']===1&&ScheduleAi::$calls===$calls
    &&ScheduleDB::table('test_tickets')->count()===1&&ScheduleDB::table('test_print')->count()===1,
    '09:00 cached verified approval dispatches while its independent analysis schedule is closed');
analysisNoReplies('cached scheduled dispatch composes no AI response');primaryRun();
scheduleExpect(ScheduleDB::table('test_tickets')->count()===1&&ScheduleDB::table('test_print')->count()===1,
    'cached scheduled replay cannot duplicate ticket or print');

$c=scheduleDraft();scheduleClock('2026-10-10 00:00:00');primaryRun();
scheduleExpect(ScheduleAi::$calls===0&&ScheduleDB::table('whatsapp_order_scans')->count()===0,
    'both Cairo schedules closed leave unprocessed input and retry budget untouched');
analysisNoReplies('both schedules closed stay silent');

$c=scheduleDraft();PrimaryFixtureControl::$analysisSchedule=scheduleHours('09:00','10:00',[0,1,2,3,4,5,6]);
scheduleClock('2026-10-10 05:59:59');primaryRun();
scheduleExpect(ScheduleAi::$calls===0&&ScheduleDB::table('whatsapp_order_scans')->count()===0,
    'one second before analysis start cannot claim an attempt');
scheduleClock('2026-10-10 06:00:00');$r=primaryRun();
scheduleExpect($r['dispatched']===1&&ScheduleAi::$calls===0,
    '09:00 opening picks up the existing unanswered native turn without a fresh message');analysisNoReplies('opening stays analysis-only');

$c=scheduleDraft();PrimaryFixtureControl::$analysisSchedule=scheduleHours('09:00','10:00',[0,1,2,3,4,5,6]);
scheduleClock('2026-10-10 06:30:00');app()->instance('App\\Services\\Dashboard\\MetaAiControl',new ScheduleRaceControl());
ScheduleRaceControl::$closeInsideClaim=true;$r=primaryRun();
scheduleExpect($r['errors']===0&&$r['deferred']===1&&ScheduleAi::$calls===0
    &&ScheduleDB::table('whatsapp_order_scans')->count()===0,
    'analysis closes after candidate selection but before claim without burning a retry');
analysisNoReplies('claim race stays silent');
app()->instance('App\\Services\\Dashboard\\MetaAiControl',new PrimaryFixtureControl());

$c=scheduleDraft();PrimaryFixtureControl::$analysisSchedule=scheduleHours('09:00','10:00',[0,1,2,3,4,5,6]);
scheduleClock('2026-10-10 06:59:59');$providerReached=false;
ScheduleAi::$callback=static function()use(&$providerReached){$providerReached=true;};$r=primaryRun();
scheduleExpect($r['errors']===0&&$r['dispatched']===1&&ScheduleAi::$calls===0&&!$providerReached,
    'current exact customer pair is deterministic even immediately before analysis schedule end');
analysisNoReplies('deterministic analysis boundary never invokes a reply provider');

$c=scheduleDraft();primaryRun();$attempts=ScheduleDB::table('whatsapp_order_scans')->value('attempts');$calls=ScheduleAi::$calls;
insertMessage($c,'wamid.schedule.changed','inbound','خلي الطلب 2 كيلو',400,null,'US.customer-confirmed');
scheduleClock('2026-10-10 06:00:00');primaryRun();
scheduleExpect(ScheduleAi::$calls===$calls&&ScheduleDB::table('whatsapp_order_scans')->value('attempts')===$attempts,
    'new native correction outside analysis does not re-extract or spend a retry');
primaryNoErp('a changed native ceiling cannot resume the older cached basket');analysisNoReplies('pending correction stays silent');

$c=scheduleDraft();primaryRun();$calls=ScheduleAi::$calls;
ScheduleDB::table('whatsapp_order_drafts')->update(['evidence_hash'=>str_repeat('0',64)]);
scheduleClock('2026-10-10 06:00:00');primaryRun();
scheduleExpect(ScheduleAi::$calls===$calls,'stale cached hash cannot trigger off-hours re-extraction');
primaryNoErp('cached snapshot mismatch cannot authorize ERP dispatch');analysisNoReplies('stale cached data stays silent');

$c=scheduleDraft();primaryRun();$calls=ScheduleAi::$calls;scheduleClock('2026-10-11 06:00:00');primaryRun();
scheduleExpect(ScheduleAi::$calls===$calls,'an expired cached approval cannot reopen analysis outside its clock');
primaryNoErp('dispatch-open schedule cannot revive customer approval older than24hours');analysisNoReplies('expired approval stays silent');

$c=scheduleDraft();ScheduleDB::table('meta_ai_controls')->update(['enabled'=>false]);scheduleClock('2026-10-10 06:00:00');primaryRun();
scheduleExpect(ScheduleAi::$calls===0&&ScheduleDB::table('whatsapp_order_scans')->count()===0,
    'master OFF overrides open dispatch and consumes no analysis attempt');primaryNoErp('master OFF creates no order');

$c=scheduleDraft();scheduleClock('2026-10-10 06:00:00');$manual=$workflow->analyze($c,(object)['id'=>7]);
scheduleExpect(($manual['success']??false)&&ScheduleAi::$calls===0,
    'an explicit permitted manual Analyze action uses deterministic native pair without provider');primaryNoErp('manual analysis does not dispatch');
analysisNoReplies('manual analysis composes no reply');
echo 'whatsapp_order_analysis_schedule_workflow: '.$scheduleChecks." checks passed\n";
