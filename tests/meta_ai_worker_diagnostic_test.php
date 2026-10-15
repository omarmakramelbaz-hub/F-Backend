<?php
// Real encrypted native SQLite workflow; diagnostic body executes with SQL query_only.
require __DIR__.'/whatsapp_order_primary_ai_workflow_test.php';
$source=file_get_contents(__DIR__.'/../deployment/meta_ai_worker_diagnostic.php');
$start=strpos($source,'function metaWorkerReadCall');$end=strpos($source,"try{\n    \$channel='whatsapp';");
if($start===false||$end===false)throw new RuntimeException('DIAGNOSTIC_FUNCTION_LAYOUT');
eval(substr($source,$start,$end-$start));
$start=strpos($source,"    \$stage='READ_ONLY_SESSION';");$end=strpos($source,"    \$out['source_unchanged_during_read']=true;");
if($start===false||$end===false)throw new RuntimeException('DIAGNOSTIC_BODY_LAYOUT');
$body=substr($source,$start,$end-$start);$checks=0;$GLOBALS['pins']=[];
$check=static function($ok,string$label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException('WORKER_DIAGNOSTIC_FAILED:'.$label);};
foreach(['retry','broken-native','analysis-only','analysis-scheduled-off','analysis-scheduled-off-cached']as$case){
    primarySetup();$conversation=primaryInbound('SECRET_CUSTOMER_MESSAGE');$channel='whatsapp';
    $scheduledOff=str_starts_with($case,'analysis-scheduled-off');
    $cacheCase=$case==='analysis-scheduled-off-cached';
    \PrimaryFixtureControl::$analysisOnly=$case==='analysis-only'||$scheduledOff;
    if($scheduledOff) \PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>range(0,6),'start'=>'09:00','end'=>'10:00','all_day'=>false];
    $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
    $ceiling=(int)$db::table('whatsapp_inbox_messages')->max('id');
    $db::table('whatsapp_order_scans')->insert(['conversation_id'=>$conversation,'analyzed_ceiling'=>0,'claimed_ceiling'=>$ceiling,
        'attempts'=>$case==='analysis-only'?0:3,'auto_floor'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    if($case==='broken-native')$db::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->update(['content'=>'SECRET_BAD_CIPHERTEXT']);
    if($cacheCase){
        // This diagnostic reports exact-cache reuse, never ERP approval eligibility.
        $cachedSnapshot=metaWorkerReadCall($workflow,'snapshot',[$conversation,0,$ceiling,false]);
        $db::table('whatsapp_order_drafts')->insert(['conversation_id'=>$conversation,'evidence_floor'=>0,'evidence_ceiling'=>$ceiling,
            'evidence_hash'=>$cachedSnapshot['hash'],'status'=>'READY','reason'=>null,'revision'=>1,
            'customer_command_key'=>'10000000-0000-4000-8000-000000000001','ticket_command_key'=>'10000000-0000-4000-8000-000000000002',
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    $before=$db::table('whatsapp_order_scans')->first();$content=$db::table('whatsapp_inbox_messages')->value('content');
    $draftBefore=$db::table('whatsapp_order_drafts')->first();
    $out=['writes'=>0,'ai_calls'=>0,'message_sends'=>0,'graph_gets'=>0];$stage='TEST';eval($body);
    $row=$out['threads'][0]??[];$json=json_encode($out,JSON_THROW_ON_ERROR);
    $check($out['read_only_session']===true&&$out['driver']==='sqlite','actual query_only session enabled');
    $check($row['status']===($case==='analysis-only'||$cacheCase?'READ_READY':'OBSERVATION_BLOCKED'),'typed pre-AI observation identified');
    $check($case==='analysis-only'?($out['mode']==='ANALYZE_ONLY'&&$out['processing_allowed']===true&&$out['analysis_allowed']===true&&$out['reply_allowed']===false)
        :($scheduledOff?($out['mode']==='ANALYZE_ONLY'&&$out['processing_allowed']===true&&$out['analysis_allowed']===false
            &&$out['dispatch_allowed']===true&&$out['reply_allowed']===false&&($cacheCase?$row['cache_return_expected']===true
                :($row['error']['reason']==='ANALYSIS_OUTSIDE_HOURS'&&$row['stage']==='CACHE_OR_CLAIM'&&$row['cache_return_expected']===false)))
            :($case==='retry'?($row['error']['reason']==='ANALYSIS_RETRY_LIMIT'&&$row['stage']==='CACHE_OR_CLAIM'):$row['stage']==='SNAPSHOT')),
        'analysis-only is never misreported as reply-schedule blocked; other stages remain exact');
    $check(!str_contains($json,'SECRET')&&!str_contains($json,'text')&&!str_contains($json,'ciphertext'),'no customer or exception prose in output');
    $check(\App\Services\Dashboard\WhatsAppOrderAiProvider::$calls===0&&count($GLOBALS['dialogueGraphs'])===0&&\App\Services\Dashboard\PosServiceTicket::$saveCalls===0,'no AI Graph or ERP boundary invoked');
    $check($db::table('whatsapp_order_scans')->first()==$before&&$db::table('whatsapp_inbox_messages')->value('content')===$content
        &&$db::table('whatsapp_order_drafts')->first()==$draftBefore,'scan lease counters, drafts and native bytes unchanged');
    $check($row['observation_unchanged']===true,'stable observation reported');
    $db::connection()->getPdo()->exec('PRAGMA query_only = OFF');
}
$safe=metaWorkerReadError(new RuntimeException('SECRET_TOKEN_AND_SQL'));
$check($safe['reason']==='UNCLASSIFIED'&&!str_contains(json_encode($safe),'SECRET'),'unknown exception messages suppressed');
$pdo=new PDOException('SECRET_SQL');$pdo->errorInfo=['HY000',1054,'SECRET_DB_ERROR'];$safe=metaWorkerReadError($pdo);
$check($safe['sqlstate']==='HY000'&&$safe['driver_code']===1054&&!str_contains(json_encode($safe),'SECRET'),'PDO diagnostic contains only state and numeric code');
echo 'META_AI_WORKER_DIAGNOSTIC_TESTS='.$checks." PASS\n";
