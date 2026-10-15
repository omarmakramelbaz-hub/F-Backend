<?php
// Explicit owner-requested mode change only; no model, Graph, reply or ERP calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();

function metaAnalysisModeArguments(array $arguments): int
{
    if (count($arguments)!==3||$arguments[1]!=='--expected-revision'
        ||!is_string($arguments[2])||!preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D',$arguments[2])) throw new RuntimeException('INVALID_ARGUMENTS');
    return (int)$arguments[2];
}
function metaAnalysisModeApply($db,$access,$control,int $expected): array
{
    $out=['diagnostic'=>'META_AI_ANALYSIS_ONLY_MODE_CHANGE','writes'=>0,'ai_calls'=>0,
        'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0];
    $stage='CURRENT_POLICY';
    try {
        $before=$db::table('meta_ai_controls')->where('id',1)->first();
        $state=$control->state();
        if (!$before||($state['available']??false)!==true||!is_int($state['revision']??null)
            ||!is_array($state['policy']??null)||!is_numeric($before->actor_id??null)
            ||(int)$before->actor_id<1) throw new RuntimeException('UNAVAILABLE');
        $out['revision_before']=$state['revision'];
        if ((int)$before->revision!==$state['revision']) throw new RuntimeException('REVISION_CONFLICT');
        if ($state['policy']['enabled']!==true) throw new RuntimeException('MASTER_DISABLED');
        if ($state['revision']!==$expected) throw new RuntimeException('REVISION_CONFLICT');
        $stage='OWNER'; $identity=new \App\Models\User; $identity->id=(int)$before->actor_id;
        $owner=$access->owner($identity);
        if (($state['policy']['mode']??null)==='ANALYZE_ONLY') {
            $out+=['status'=>'ALREADY_ANALYSIS_ONLY','revision_after'=>$state['revision'],'settings_preserved'=>true];
            return $out;
        }
        $policy=$state['policy']; $policy['mode']='ANALYZE_ONLY';
        $stage='MODE_CHANGE';
        // update() rechecks owner/revision and unsettled replies under the policy lock.
        // A post-commit read failure must never be reported as proven zero writes.
        $out['writes']=null;
        try { $after=$control->update($owner,$policy,$expected,false); }
        catch (Throwable $error) {
            $current=$db::table('meta_ai_controls')->where('id',1)->first();
            if ($current==$before) $out['writes']=0;
            throw $error;
        }
        $stage='VERIFY'; $stored=$db::table('meta_ai_controls')->where('id',1)->first();
        $out['writes']=1;
        $expectedPolicy=$state['policy']; $expectedPolicy['mode']='ANALYZE_ONLY';
        $storedPolicy=$stored&&is_string($stored->policy)?json_decode($stored->policy,true,8,JSON_THROW_ON_ERROR):null;
        $storedPolicy=is_array($storedPolicy)?\App\Support\MetaAiSchedule::validate($storedPolicy):null;
        if (!$stored||($after['available']??false)!==true||($after['revision']??null)!==$expected+1
            ||(int)$stored->revision!==$expected+1||$storedPolicy!==$expectedPolicy
            ||(int)$stored->actor_id!==(int)$owner->getKey()
            ||($after['policy']??null)!==$expectedPolicy
            ||($after['reply_state']['status']??null)!=='ANALYZE_ONLY'
            ||($after['reply_state']['effective']??true)!==false
            ||$stored->activations!==$before->activations||$stored->handoff!==null)
            throw new RuntimeException('VERIFY_FAILED');
        $out+=['status'=>'ANALYSIS_ONLY_ENABLED','revision_after'=>$after['revision'],
            'settings_preserved'=>true,'activation_floors_preserved'=>true,'automatic_replies_enabled'=>false];
    } catch (Throwable $error) {
        $reason=in_array($error->getMessage(),['MASTER_DISABLED','REVISION_CONFLICT','REPLIES_PENDING','UNAVAILABLE','VERIFY_FAILED'],true)
            ?$error->getMessage():'UNAVAILABLE';
        $out+=['status'=>'MODE_CHANGE_BLOCKED','reason'=>$reason,'stage'=>$stage];
    }
    return $out;
}

$out=['diagnostic'=>'META_AI_ANALYSIS_ONLY_MODE_CHANGE','writes'=>0,'ai_calls'=>0,
    'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0]; $stage='ARGUMENTS';
try {
    $expected=metaAnalysisModeArguments($argv);
    $stage='OWNER'; $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html')) throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaInboxAccess.php'=>'7faaba6645308c1997bae8182fb4b26dbc5e3b9a',
        'app/Services/Dashboard/MetaBusinessAgent.php'=>'4b2ad29c21ea092af94ad3fa1bace90ad4dd4672',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/TakeawayAccess.php'=>'6b27b7ac2faff6fdaf952494f90f2d8378a12484',
    ];
    foreach ($pins as $path=>$pin) {
        if (is_link($path)||!is_file($path)||filesize($path)>1048576) throw new RuntimeException();
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin) throw new RuntimeException();
    }
    $stage='BOOTSTRAP'; require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $out=metaAnalysisModeApply(\Illuminate\Support\Facades\DB::class,
        app(\App\Services\Dashboard\MetaInboxAccess::class),app(\App\Services\Dashboard\MetaAiControl::class),$expected);
} catch (Throwable $ignored) { $out+=['status'=>'MODE_CHANGE_BLOCKED','stage'=>$stage]; }
while (ob_get_level()) ob_end_clean();
echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status']??null,['ANALYSIS_ONLY_ENABLED','ALREADY_ANALYSIS_ONLY'],true)?0:1);
