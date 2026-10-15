<?php
require __DIR__.'/meta_order_analysis_only_workflow_test.php';
use App\Services\Dashboard\WhatsAppOrderAiProvider as ScheduleMetaAi;
use Illuminate\Support\Facades\DB as ScheduleMetaDB;

$metaScheduleChecks=0;
function metaScheduleExpect($ok,string $label):void {
    $GLOBALS['metaScheduleChecks']++;if(!$ok)throw new RuntimeException('META_ANALYSIS_SCHEDULE_FAILED:'.$label);
}
function metaScheduleClock(string $utc):void {
    \Carbon\Carbon::setTestNow($utc);\Carbon\CarbonImmutable::setTestNow($utc);
}
function metaScheduleHours(string $start='23:00',string $end='02:00',array $days=[5]):array {
    return ['enabled'=>true,'days'=>$days,'start'=>$start,'end'=>$end,'all_day'=>false];
}
function metaScheduleDraft(string $channel):int {
    $c=metaAnalysisDraft($channel);MetaPrimaryFixtureControl::$analysisSchedule=metaScheduleHours();
    MetaPrimaryFixtureControl::$strictDispatchClock=true;return$c;
}
class MetaScheduleRaceControl extends MetaPrimaryFixtureControl {
    public static bool $closeInsideClaim=false;
    public function analysisGate(string $channel,?string $time=null,?int $id=null,?int $event=null):array {
        if(self::$closeInsideClaim&&ScheduleMetaDB::connection()->transactionLevel()>0) {
            self::$closeInsideClaim=false;self::$analysisSchedule=metaScheduleHours();
        }
        return parent::analysisGate($channel,$time,$id,$event);
    }
}

foreach(['messenger','instagram']as$channel) {
    $c=metaScheduleDraft($channel);metaScheduleClock('2026-10-10 06:00:00');$r=metaPrimaryRun();
    metaScheduleExpect($r['errors']===0&&ScheduleMetaAi::$calls===0&&ScheduleMetaDB::table('meta_order_scans')->count()===0
        &&ScheduleMetaDB::table('meta_order_drafts')->count()===0,
        $channel.' dispatch-open analysis-closed unprocessed input spends no retry or AI');
    metaAnalysisSilent($channel.' off-hours analysis never asks');metaPrimaryNoErp($channel.' no verified cached order cannot dispatch');

    $c=metaScheduleDraft($channel);$r=metaPrimaryRun();
    metaScheduleExpect($r['errors']===0&&$r['deferred']===1&&ScheduleMetaAi::$calls===1
        &&ScheduleMetaDB::table('meta_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
        $channel.' Friday overnight analysis accepts Saturday 01:05 while dispatch waits');
    metaPrimaryNoErp($channel.' analysis hours do not bypass dispatch schedule');$calls=ScheduleMetaAi::$calls;
    metaScheduleClock('2026-10-10 06:00:00');$r=metaPrimaryRun();
    metaScheduleExpect($r['errors']===0&&$r['dispatched']===1&&ScheduleMetaAi::$calls===$calls
        &&ScheduleMetaDB::table('test_tickets')->count()===1&&ScheduleMetaDB::table('test_print')->count()===1,
        $channel.'09:00 cached verified approval dispatches with the analysis clock still closed');
    metaAnalysisSilent($channel.' cached queue sends no reply');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaDB::table('test_tickets')->count()===1&&ScheduleMetaDB::table('test_print')->count()===1,
        $channel.' scheduled replay never duplicates order or print');

    $c=metaScheduleDraft($channel);metaScheduleClock('2026-10-10 00:00:00');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaAi::$calls===0&&ScheduleMetaDB::table('meta_order_scans')->count()===0,
        $channel.' both schedules closed keep the retry budget untouched');metaAnalysisSilent('closed schedules stay silent');

    $c=metaScheduleDraft($channel);MetaPrimaryFixtureControl::$analysisSchedule=metaScheduleHours('09:00','10:00',[0,1,2,3,4,5,6]);
    metaScheduleClock('2026-10-10 05:59:59');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaAi::$calls===0&&ScheduleMetaDB::table('meta_order_scans')->count()===0,
        $channel.' one second before start no analysis claim');
    metaScheduleClock('2026-10-10 06:00:00');$r=metaPrimaryRun();
    metaScheduleExpect($r['dispatched']===1&&ScheduleMetaAi::$calls===1,
        $channel.' opening automatically resumes an already captured native approval');metaAnalysisSilent('opening does not reply');

    $c=metaScheduleDraft($channel);MetaPrimaryFixtureControl::$analysisSchedule=metaScheduleHours('09:00','10:00',[0,1,2,3,4,5,6]);
    metaScheduleClock('2026-10-10 06:30:00');app()->instance('App\\Services\\Dashboard\\MetaAiControl',new MetaScheduleRaceControl());
    MetaScheduleRaceControl::$closeInsideClaim=true;$r=metaPrimaryRun();
    metaScheduleExpect($r['errors']===0&&$r['deferred']===1&&ScheduleMetaAi::$calls===0
        &&ScheduleMetaDB::table('meta_order_scans')->count()===0,
        $channel.' clock closing between candidate selection and claim burns no retry');metaAnalysisSilent('claim race stays silent');

    $c=metaScheduleDraft($channel);MetaPrimaryFixtureControl::$analysisSchedule=metaScheduleHours('09:00','10:00',[0,1,2,3,4,5,6]);
    metaScheduleClock('2026-10-10 06:59:59');ScheduleMetaAi::$callback=static function(){metaScheduleClock('2026-10-10 07:00:00');};$r=metaPrimaryRun();
    metaScheduleExpect($r['errors']===0&&$r['deferred']===1&&ScheduleMetaAi::$calls===1
        &&ScheduleMetaDB::table('meta_order_drafts')->count()===0&&ScheduleMetaDB::table('meta_order_scans')->value('claim_nonce')===null,
        $channel.' analysis end during provider rejects result and releases lease');metaPrimaryNoErp('in-flight end cannot persist or dispatch');metaAnalysisSilent('provider race stays silent');

    foreach(['enabled','revision','mode']as$change) {
        $c=metaScheduleDraft($channel);ScheduleMetaAi::$callback=static function()use($change){
            if($change==='enabled')ScheduleMetaDB::table('meta_ai_controls')->update(['enabled'=>false]);
            else ScheduleMetaDB::table('meta_ai_controls')->update(['revision'=>2]);
            if($change==='mode')MetaPrimaryFixtureControl::$analysisOnly=false;
        };
        metaPrimaryRun();metaScheduleExpect(ScheduleMetaDB::table('meta_order_drafts')->count()===0,
            $channel.' fresh '.$change.' policy race rejects result');metaPrimaryNoErp('policy race vetoes writes');metaAnalysisSilent('policy race never replies');
    }

    $c=metaScheduleDraft($channel);metaPrimaryRun();$attempts=ScheduleMetaDB::table('meta_order_scans')->value('attempts');$calls=ScheduleMetaAi::$calls;
    metaPrimaryMessage($channel,'mid.schedule.changed','خلي الطلب 2 كيلو',400);metaScheduleClock('2026-10-10 06:00:00');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaAi::$calls===$calls&&ScheduleMetaDB::table('meta_order_scans')->value('attempts')===$attempts,
        $channel.' later native correction waits without re-extraction or retry burn');
    metaPrimaryNoErp('new native ceiling cannot execute the older cached basket');metaAnalysisSilent('pending correction stays silent');

    $c=metaScheduleDraft($channel);metaPrimaryRun();$calls=ScheduleMetaAi::$calls;
    ScheduleMetaDB::table('meta_order_drafts')->update(['evidence_hash'=>str_repeat('0',64)]);metaScheduleClock('2026-10-10 06:00:00');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaAi::$calls===$calls,$channel.' stale cached hash never opens off-hours AI');
    metaPrimaryNoErp('cached hash mismatch vetoes ERP dispatch');metaAnalysisSilent('stale cached data stays silent');

    $c=metaScheduleDraft($channel);metaPrimaryRun();$calls=ScheduleMetaAi::$calls;metaScheduleClock('2026-10-11 06:00:00');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaAi::$calls===$calls,$channel.' expired cached approval cannot reopen off-hours analysis');
    metaPrimaryNoErp('dispatch-open clock cannot revive customer approval older than24hours');metaAnalysisSilent('expired approval stays silent');

    $c=metaScheduleDraft($channel);ScheduleMetaDB::table('meta_ai_controls')->update(['enabled'=>false]);metaScheduleClock('2026-10-10 06:00:00');metaPrimaryRun();
    metaScheduleExpect(ScheduleMetaAi::$calls===0&&ScheduleMetaDB::table('meta_order_scans')->count()===0,
        $channel.' master OFF vetoes otherwise open dispatch without consuming retries');metaPrimaryNoErp('master OFF no order');

    $c=metaScheduleDraft($channel);metaScheduleClock('2026-10-10 06:00:00');$manual=app(\App\Services\Dashboard\MetaOrderWorkflow::class)->analyze($c,(object)['id'=>7]);
    metaScheduleExpect(($manual['success']??false)&&ScheduleMetaAi::$calls===1,
        $channel.' permitted explicit manual Analyze retains existing behavior');metaPrimaryNoErp('manual analysis is not dispatch');metaAnalysisSilent('manual analysis stays silent');
}
echo 'meta_order_analysis_schedule_workflow: '.$metaScheduleChecks." checks passed\n";
