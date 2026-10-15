<?php
// Actual owner/control/SQLite update; no application boot or live service boundaries.
require __DIR__.'/meta_ai_control_test.php';
$source=file_get_contents(__DIR__.'/../deployment/meta_ai_enable_analysis_only.php');
$start=strpos($source,'function metaAnalysisModeArguments');
$end=strpos($source,"\n\$out=['diagnostic'=>'META_AI_ANALYSIS_ONLY_MODE_CHANGE'");
if ($start===false||$end===false) throw new RuntimeException('MODE_HELPER_LAYOUT');
eval(substr($source,$start,$end-$start));
$modeChecks=0;
function modeExpect($ok,string $label): void {
    $GLOBALS['modeChecks']++;if (!$ok) throw new RuntimeException('MODE_CHANGE_FAILED:'.$label);
}
modeExpect(metaAnalysisModeArguments(['mode.php','--expected-revision','10'])===10,'explicit revision accepted');
foreach ([['mode.php'],['mode.php','--expected-revision','-1'],['mode.php','--force','10'],
    ['mode.php','--expected-revision','01'],['mode.php','--expected-revision','10','--enable']] as $arguments) {
    try {metaAnalysisModeArguments($arguments);modeExpect(false,'reject invalid arguments');}
    catch (RuntimeException $ignored) {modeExpect(true,'only explicit bounded revision allowed');}
}
(new CreateMetaAiControls)->up();
$control->clock='2026-10-17 12:00:00';
$people->users[1]->active=true;
$policy=\App\Services\Dashboard\MetaAiControl::defaults();$policy['enabled']=true;
$policy['dispatch_schedule']=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'00:30','all_day'=>true];
$control->update(new \App\Models\User,$policy,0,true);
$db=\Illuminate\Support\Facades\DB::class;
$before=$db::table('meta_ai_controls')->first();
$network=count($transportCalls);$prepares=count($business->calls);$configBefore=app('config')->all();
$out=metaAnalysisModeApply($db,$access,$control,1);
modeExpect($out['status']==='ANALYSIS_ONLY_ENABLED'&&$out['writes']===1&&$out['revision_after']===2,'actual mode changes exactly one row/revision');
modeExpect($control->state()['policy']['mode']==='ANALYZE_ONLY'&&!$control->gate('whatsapp')['effective']
    &&$control->processingGate('whatsapp')['effective'],'analysis active and automatic replies closed');
modeExpect($db::table('meta_ai_controls')->value('activations')===$before->activations,'original activation bytes retained');
$expected=$policy;$expected['mode']='ANALYZE_ONLY';
modeExpect($control->state()['policy']===\App\Support\MetaAiSchedule::validate($expected),'every non-mode setting retained including saved all_day');
modeExpect($db::table('meta_ai_controls')->value('handoff')===null,'stale full-mode pause attestation invalidated');
modeExpect(count($transportCalls)===$network&&count($business->calls)===$prepares&&app('config')->all()===$configBefore,'no Graph handoff preparation or config changes');
modeExpect($out['ai_calls']===0&&$out['message_sends']===0&&$out['erp_writes']===0&&$out['graph_gets']===0,'mode helper has no live service boundary');
$saved=$db::table('meta_ai_controls')->first();$again=metaAnalysisModeApply($db,$access,$control,2);
modeExpect($again['status']==='ALREADY_ANALYSIS_ONLY'&&$again['writes']===0&&$db::table('meta_ai_controls')->first()==$saved,'repeat call is a strict no-op');
$stale=metaAnalysisModeApply($db,$access,$control,1);
modeExpect($stale['reason']==='REVISION_CONFLICT'&&$stale['writes']===0&&$db::table('meta_ai_controls')->first()==$saved,'stale revision never clobbers another save');
$off=$control->state()['policy'];$off['enabled']=false;
$db::table('meta_ai_controls')->where('id',1)->update(['policy'=>json_encode($off)]);
$disabled=metaAnalysisModeApply($db,$access,$control,2);
modeExpect($disabled['reason']==='MASTER_DISABLED'&&$disabled['writes']===0,'helper never re-enables an owner-disabled policy');
$db::table('meta_ai_controls')->where('id',1)->update((array)$before);
$people->users[1]->active=false;$revoked=metaAnalysisModeApply($db,$access,$control,1);$people->users[1]->active=true;
modeExpect($revoked['status']==='MODE_CHANGE_BLOCKED'&&$revoked['writes']===0&&$db::table('meta_ai_controls')->first()==$before,'revoked persisted owner cannot change mode');
\Illuminate\Support\Facades\Schema::create('whatsapp_reply_requests',function(\Illuminate\Database\Schema\Blueprint $table){
    $table->integer('id')->primary();$table->string('waba_id');$table->string('phone_number_id');$table->string('state');
});
foreach (['CLAIMED','UNKNOWN'] as $state) {
    $db::table('whatsapp_reply_requests')->insert(['id'=>1,'waba_id'=>\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID,
        'phone_number_id'=>\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID,'state'=>$state]);
    $receipt=$db::table('whatsapp_reply_requests')->first();$pending=metaAnalysisModeApply($db,$access,$control,1);
    modeExpect($pending['reason']==='REPLIES_PENDING'&&$pending['writes']===0&&$db::table('meta_ai_controls')->first()==$before
        &&$db::table('whatsapp_reply_requests')->first()==$receipt,'uncertain/in-flight send cannot be overlapped or rewritten '.$state);
    $db::table('whatsapp_reply_requests')->delete();
}
modeExpect(count($transportCalls)===$network&&count($business->calls)===$prepares,'all blocked paths remain offline');
foreach (['commit-then-throw','bad-result','later-policy'] as $case) {
    $db::table('meta_ai_controls')->where('id',1)->update((array)$before);
    $wrapped=new class($control,$case) {
        private $control;private string $case;
        public function __construct($control,string $case){$this->control=$control;$this->case=$case;}
        public function state(){return $this->control->state();}
        public function update($owner,$policy,$expected,$paused){
            $result=$this->control->update($owner,$policy,$expected,$paused);
            if ($this->case==='commit-then-throw') throw new RuntimeException('PRIVATE_POST_COMMIT_ERROR');
            if ($this->case==='bad-result') $result['revision']=999;
            if ($this->case==='later-policy') \Illuminate\Support\Facades\DB::table('meta_ai_controls')->where('id',1)->increment('revision');
            return $result;
        }
    };
    $uncertain=metaAnalysisModeApply($db,$access,$wrapped,1);
    modeExpect($uncertain['status']==='MODE_CHANGE_BLOCKED'
        &&$uncertain['writes']===($case==='commit-then-throw'?null:1),'post-commit failure never claims zero writes '.$case);
    modeExpect(!isset($uncertain['settings_preserved'])&&!str_contains(json_encode($uncertain),'PRIVATE'),'no false settings-preservation claim or exception text '.$case);
}
echo 'META_AI_ANALYSIS_ONLY_MODE_CHANGE_TESTS='.$modeChecks." PASS\n";
