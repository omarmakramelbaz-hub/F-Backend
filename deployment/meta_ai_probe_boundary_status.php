<?php
// Bounded read-only classification. No text, customer facts, marker values or message keys are emitted.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();

function metaProbeBoundaryBase(): array
{
    return ['diagnostic'=>'READ_ONLY_META_AI_PROBE_BOUNDARY','writes'=>0,'ai_calls'=>0,
        'message_sends'=>0,'graph_gets'=>0,'scope'=>'CLASSIFICATION_ONLY_NOT_REPAIR_OR_ORDER_AUTHORITY'];
}
function metaProbeBoundaryCall($object,string $name,array $arguments=[])
{
    $method=new ReflectionMethod($object,$name); $method->setAccessible(true);
    return $method->invokeArgs(is_object($object)?$object:null,$arguments);
}
function metaProbeBoundaryArguments(array $arguments): int
{
    if (count($arguments)===1) return 31;
    if (count($arguments)!==3||$arguments[1]!=='--conversation'
        ||!is_string($arguments[2])||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$arguments[2])) throw new RuntimeException();
    return (int)$arguments[2];
}
function metaProbeBoundaryObserve($db,$workflow,$control,int $conversation): array
{
    $out=metaProbeBoundaryBase(); $stage='READ_ONLY_SESSION';
    try {
        if ($conversation<1||$conversation>999999999) throw new RuntimeException();
        $connection=$db::connection(); $driver=$connection->getDriverName();
        if ($driver==='mysql') $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
        elseif ($driver==='sqlite') $connection->getPdo()->exec('PRAGMA query_only = ON');
        else throw new RuntimeException();
        $out['read_only_session']=true; $out['driver']=$driver;
        $stage='SCOPE'; $thread=metaProbeBoundaryCall($workflow,'conversation',[$conversation,false]);
        if ((int)$thread->id!==$conversation) throw new RuntimeException();
        $stage='CONTROL'; $gate=$control->processingGate('whatsapp');
        if (!is_int($gate['revision']??null)||$gate['revision']<0) throw new RuntimeException();
        $controlBefore=$db::table('meta_ai_controls')->where('id',1)->first();
        if (!$controlBefore) throw new RuntimeException();
        $out['conversation_id']=$conversation; $out['control_revision']=$gate['revision'];
        $stage='BOUNDS'; $scan=$db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first();
        $ceiling=(int)$db::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->max('id');
        $floor=metaProbeBoundaryCall($workflow,'automaticFloor',[$conversation,$scan,false]);
        if (!is_int($floor)||$floor<0||$ceiling<=$floor) throw new RuntimeException();
        $bounds=$db::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
            ->where('id','>',$floor)->where('id','<=',$ceiling)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(LENGTH(content)),0) AS bytes')->first();
        if (!$bounds||(int)$bounds->n<1||(int)$bounds->n>60||(int)$bounds->bytes>4194304) throw new RuntimeException();
        $stage='SNAPSHOT'; $snapshot=metaProbeBoundaryCall($workflow,'snapshot',[$conversation,$floor,$ceiling,false]);
        $transcript=$snapshot['transcript']??null; $keys=$snapshot['keys']??null;
        if (($snapshot['complete']??false)!==true||!is_array($transcript)||!is_array($keys)
            ||count($transcript)!==(int)$bounds->n||count($keys)!==count($transcript)
            ||!is_string($snapshot['hash']??null)
            ||!(\App\Support\WhatsAppOrderExtraction::validateTranscript($transcript)['ok']??false)) throw new RuntimeException();
        $stage='NATIVE_BINDINGS'; $metadata=$db::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
            ->where('id','>',$floor)->where('id','<=',$ceiling)->orderBy('id')->limit(61)
            ->get(['id','message_key','direction','type','sent_at'])->keyBy('message_key');
        if (count($metadata)!==count($transcript)) throw new RuntimeException();
        $rows=[]; $latest=null;
        foreach ($transcript as $ordinal=>$row) {
            $key=$keys[$row['id']]??null; $native=is_string($key)?$metadata->get($key):null;
            if (!$native||$native->sent_at!==$row['sent_at']
                ||$native->direction!==($row['speaker']==='customer'?'inbound':'outbound')) throw new RuntimeException();
            $kind=in_array($native->type,['text','image','audio','video','document','sticker','location','order','contacts',
                'unsupported','reaction','interactive','button','system','referral','edit','revoke','unknown','template','flow'],true)?$native->type:'OTHER';
            $text=$row['text']??null; $cart=$row['cart']['text']??null;
            $codedText=is_string($text)&&preg_match('/\bWA-[0-9]{4}\b/i',$text)===1;
            $codedCart=is_string($cart)&&preg_match('/\bWA-[0-9]{4}\b/i',$cart)===1;
            $customer=$row['speaker']==='customer';
            $rows[]=['ordinal'=>$ordinal+1,'native_id'=>(int)$native->id,'speaker'=>$row['speaker'],
                'native_kind'=>$kind,'sent_at_utc'=>$row['sent_at'],'coded_text'=>$codedText,'coded_cart'=>$codedCart,
                'operational_test_statement'=>$customer&&is_string($text)&&metaProbeBoundaryCall(\App\Services\Dashboard\WhatsAppOrderConversationEvidence::class,'testIntent',[$text]),
                'explicit_new_order'=>$customer&&is_string($text)&&metaProbeBoundaryCall(\App\Services\Dashboard\WhatsAppOrderConversationEvidence::class,'newOrder',[$text])];
            if ($customer) $latest=$ordinal;
        }
        $stage='CLASSIFICATION'; $evidence=\App\Services\Dashboard\WhatsAppOrderConversationEvidence::class;
        $ledger=metaProbeBoundaryCall($evidence,'ledger',[$transcript]);
        if (!is_array($ledger)||count($ledger)!==2) throw new RuntimeException();
        [$indexed,$positions]=$ledger; $test=metaProbeBoundaryCall($evidence,'lastTest',[$indexed,$positions]);
        $boundary=$test===null?null:metaProbeBoundaryCall($evidence,'newOrderAfter',[$indexed,$positions,$test]);
        $episode=$evidence::episodeTranscript($transcript);
        $coded=null;
        foreach ($rows as $row) if ($row['coded_text']||$row['coded_cart']) $coded=$row['ordinal'];
        $classification=['snapshot_probe_present'=>\App\Support\WhatsAppOrderExtraction::hasProbe($transcript),
            'historical_coded_probe_present'=>$coded!==null,
            'last_coded_probe_ordinal'=>$coded,
            'operational_test_unresolved'=>$evidence::operationalTest($transcript),
            'last_operational_test_ordinal'=>$test===null?null:$test+1,
            'new_order_after_test_ordinal'=>$boundary===null?null:$boundary+1,
            'episode_first_ordinal'=>isset($episode[0]['id'],$positions[$episode[0]['id']])?$positions[$episode[0]['id']]+1:null,
            'latest_customer_ordinal'=>$latest===null?null:$latest+1,
            'latest_customer_probe_present'=>$latest===null?null:\App\Support\WhatsAppOrderExtraction::hasProbe([$transcript[$latest]])];
        $stage='STABILITY'; $threadAfter=metaProbeBoundaryCall($workflow,'conversation',[$conversation,false]);
        $snapshotAfter=metaProbeBoundaryCall($workflow,'snapshot',[$conversation,$floor,$ceiling,false]);
        $gateAfter=$control->processingGate('whatsapp');
        $scopeUnchanged=$threadAfter==$thread;
        $ceilingUnchanged=$ceiling===(int)$db::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->max('id');
        $snapshotUnchanged=($snapshotAfter['complete']??false)===true&&$snapshotAfter===$snapshot;
        $scanAfter=$db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first();
        $scanUnchanged=$scanAfter==$scan;
        $floorUnchanged=metaProbeBoundaryCall($workflow,'automaticFloor',[$conversation,$scanAfter,false])===$floor;
        $controlUnchanged=($gateAfter['revision']??null)===$gate['revision']
            &&$db::table('meta_ai_controls')->where('id',1)->first()==$controlBefore;
        $out+=['floor'=>$floor,'ceiling'=>$ceiling,'snapshot_messages'=>count($transcript),'classification'=>$classification,'rows'=>$rows,
            'scope_unchanged'=>$scopeUnchanged,'ceiling_unchanged'=>$ceilingUnchanged,'snapshot_unchanged'=>$snapshotUnchanged,
            'scan_unchanged'=>$scanUnchanged,'floor_unchanged'=>$floorUnchanged,'control_revision_unchanged'=>$controlUnchanged,
            'observation_unchanged'=>$scopeUnchanged&&$ceilingUnchanged&&$snapshotUnchanged&&$scanUnchanged&&$floorUnchanged&&$controlUnchanged];
        $out['status']=$out['observation_unchanged']?'READ_READY':'OBSERVATION_CHANGED';
    } catch (Throwable $ignored) {
        $out=metaProbeBoundaryBase()+['status'=>'READ_FAILED','stage'=>$stage];
    }
    return $out;
}

$out=metaProbeBoundaryBase(); $stage='ARGUMENTS'; $pins=[];
try {
    $conversation=metaProbeBoundaryArguments($argv);
    $stage='OWNER'; $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html')) throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'bd0e1cc3536f7d84f3c31bc956317d3374004937',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaBusinessAgent.php'=>'4b2ad29c21ea092af94ad3fa1bace90ad4dd4672',
        'app/Services/Dashboard/MetaInboxAccess.php'=>'7faaba6645308c1997bae8182fb4b26dbc5e3b9a',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Services/Dashboard/WhatsAppOrderSourceSelection.php'=>'03db3211b80da905f07415fd16f1e7a62e0395b1',
        'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'=>'9e221ba052589c1ffe474958b636a77ff5817b7d',
        'app/Services/Dashboard/PosServiceTicket.php'=>'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
        'app/Services/Dashboard/TakeawayAccess.php'=>'6b27b7ac2faff6fdaf952494f90f2d8378a12484',
    ];
    foreach ($pins as $path=>$pin) {
        if (is_link($path)||!is_file($path)||filesize($path)>1048576) throw new RuntimeException();
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin) throw new RuntimeException();
    }
    $stage='BOOTSTRAP'; require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $control=app(\App\Services\Dashboard\MetaAiControl::class);
    if (get_class($control)!==\App\Services\Dashboard\MetaAiControl::class) throw new RuntimeException();
    $out=metaProbeBoundaryObserve(\Illuminate\Support\Facades\DB::class,
        new \App\Services\Dashboard\WhatsAppOrderWorkflow(),$control,$conversation);
    $out['source_unchanged_during_read']=true;
    foreach ($pins as $path=>$pin) {
        $bytes=is_link($path)||!is_file($path)||filesize($path)>1048576?false:file_get_contents($path);
        if (!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin) { $out['source_unchanged_during_read']=false; break; }
    }
} catch (Throwable $ignored) { $out=metaProbeBoundaryBase()+['status'=>'READ_FAILED','stage'=>$stage]; }
while (ob_get_level()) ob_end_clean();
echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
