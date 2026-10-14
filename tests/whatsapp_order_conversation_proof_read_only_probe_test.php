<?php
// Synthetic transcript and private fake HTTP only. No Laravel bootstrap, database,
// external request, credentials, source publication or ERP/order actions.
define('WHATSAPP_ORDER_CONVERSATION_PROOF_READ_ONLY_PROBE_TEST_ONLY', true);
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderWorkflow.php';
require dirname(__DIR__) . '/deployment/whatsapp_order_conversation_proof_observer.php';
require dirname(__DIR__) . '/deployment/whatsapp_order_conversation_proof_read_only_probe.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Services\Dashboard\WhatsAppOrderSourceSelection as Selection;
use App\Support\WhatsAppOrderExtraction as Extraction;
use WhatsAppOrderConversationProofObserver as Observer;
use WhatsAppOrderConversationProofReadOnlyProbe as Probe;

$checks = 0;
$publicMetadata = [];
function proofExpect($actual, $expected, string $label): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function proofRow(string $id, string $speaker, string $text, int $second): array
{
    return ['id'=>$id, 'speaker'=>$speaker, 'sent_at'=>'2026-10-13 12:00:'.sprintf('%02d',$second),
        'text'=>$text, 'location'=>null];
}
function proofRows(): array
{
    return [
        proofRow('m1','customer','الفسيخ بكام؟',1),
        proofRow('m2','business','فسيخ من الكيلو 3 حبه بسعر 300 جنيه، ومن الكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟',2),
        proofRow('m3','customer','عايز كيلو ونص من الكيلو 3 حبه',3),
        proofRow('m4','business','تمام',4),
        proofRow('m5','customer','في رنجه',5),
        proofRow('m6','business','رنجه بسعر 220 جنيه للكيلو. تحب قد إيه؟',6),
        proofRow('m7','customer','تمام اعملي كيلو',7),
        proofRow('m8','business','تحب الفسيخ كامل ولا تنظيف؟',8),
        proofRow('m9','customer','كيلو ونص كامل',9),
        proofRow('m10','business','طلبك: 1.5 كيلو فسيخ من الكيلو 3 حبه كامل؛ 1 كيلو رنجه',10),
        proofRow('m11','customer','تمام موافق',11),
        proofRow('m12','business','ابعت الاسم ورقم الهاتف والعنوان',12),
        proofRow('m13','customer','عميل تجريبي',13),
        proofRow('m14','customer','01000000001',14),
        proofRow('m15','customer','مدينة التجربة المنصورة شارع المثال',15),
        proofRow('m16','business','تم استلام الاسم ورقم الهاتف والعنوان وتأكيد الطلب',16),
    ];
}
function proofPlan(array $rows): array
{
    $built=Selection::build($rows);
    proofExpect($built['ok'],true,'synthetic full source plan is valid');
    return $built['data'];
}
function proofSpan(array $plan, string $id, string $word, string $source='text'): array
{
    foreach ($plan['input']['name_tokens'] as $row) {
        if ($row['message_id']!==$id || $row['source']!==$source) continue;
        foreach ($row['tokens'] as $token) if ($token['text']===$word)
            return ['message_id'=>$id,'source'=>$source,'first_token'=>$token['index'],'last_token'=>$token['index']];
    }
    throw new RuntimeException('Missing synthetic source token');
}
function proofClause(array $plan,int $ordinal): string
{
    foreach ($plan['input']['summary_clauses'] as $clause)
        if ($clause['message_id']==='m10' && $clause['clause_index']===$ordinal) return $clause['id'];
    throw new RuntimeException('Missing synthetic recap clause');
}
function proofWire(array $plan): array
{
    return ['decision'=>'CONFIRMED', 'customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001',
        'address'=>'مدينة التجربة المنصورة شارع المثال','area'=>'المنصورة','notes'=>null],
        'branch_hint'=>null,'fulfillment'=>'DELIVERY','items'=>[
            ['name_source'=>proofSpan($plan,'m1','فسيخ'),'quantity'=>'1.5','quantity_mode'=>'weight',
                'option_source'=>proofSpan($plan,'m9','كامل'),'cart_reference'=>null,
                'conversation_reference'=>['business_offer_id'=>'m2','customer_quantity_id'=>'m3','summary_clause_id'=>proofClause($plan,0)]],
            ['name_source'=>proofSpan($plan,'m5','رنجه'),'quantity'=>'1','quantity_mode'=>'weight',
                'option_source'=>null,'cart_reference'=>null,
                'conversation_reference'=>['business_offer_id'=>'m6','customer_quantity_id'=>'m7','summary_clause_id'=>proofClause($plan,1)]],
        ],'approximate_total'=>null,'evidence'=>[
            'request_ids'=>['m1','m3','m5','m7','m9','m13','m14','m15'],
            'confirmation_ids'=>['m10','m16'],'customer_acceptance_ids'=>['m11'],'location_id'=>null], 'issues'=>[]];
}
function proofConfiguration(): array
{
    return ['enabled'=>true,'mode'=>'auto','model'=>'fixture-model','api_key'=>'sk-proof-observer-PRIVATE_FIXTURE_ONLY'];
}
function proofResponse(array $wire): array
{
    return ['status'=>200,'curl_errno'=>0,'elapsed_ms'=>123,'body'=>json_encode([
        'status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[
            ['type'=>'output_text','text'=>json_encode($wire,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]],
        'usage'=>['input_tokens'=>9000,'output_tokens'=>700,'output_tokens_details'=>['reasoning_tokens'=>400]],
    ],JSON_THROW_ON_ERROR)];
}
function proofObserved(array $rows,array $response): array
{
    $calls=0;$body=null;$key=null;
    $observed=Observer::extract($rows,proofConfiguration(),static function ($request,$credential) use (&$calls,&$body,&$key,$response): array {
        $calls++;$body=$request;$key=$credential;return $response;
    });
    return ['observed'=>$observed,'calls'=>$calls,'body'=>$body,'key'=>$key];
}
function proofPlain(array $rows,array $response): array
{
    $calls=0;$body=null;$key=null;
    $provider=new Provider(static function ($request,$credential) use (&$calls,&$body,&$key,$response): array {
        $calls++;$body=$request;$key=$credential;return $response;
    },proofConfiguration());
    return ['result'=>$provider->extract($rows),'calls'=>$calls,'body'=>$body,'key'=>$key];
}

const PROOF_CHECKS = [
    'ITEM_DECLARATION'=>['item_array','item_name_valid','declared_amount_valid'],
    'CUSTOMER_SELECTION'=>['anchor_literal','anchor_safe','offer_safe','positive_selection','bare_selection',
        'direct_offer_answer','offer_names_item','quantity_question','alternatives','amount_matches'],
    'OFFER_VARIANT'=>['selected_variant_present','selected_variant_offered'],
    'SUMMARY_AMOUNT'=>['clause_present','clause_unique','clause_safe','alternatives','amount_matches'],
    'SUMMARY_VARIANT'=>['summary_variant_unique','summary_variant_matches'],
    'SUMMARY_OPTION'=>['option_valid','option_in_summary'],
    'CUSTOMER_OPTION'=>['option_after_quantity','option_before_summary','option_in_customer','amount_matches'],
];
function proofMetadata(array $metadata): void
{
    proofExpect(array_keys($metadata),['status','subreason','field','item_ordinal','checks'],'proof metadata has exactly five reviewed fields');
    proofExpect(in_array($metadata['status'],['PROOF_FAILED','NOT_APPLICABLE','OBSERVER_STOPPED'],true),true,'proof status is a fixed enum');
    if ($metadata['status']==='PROOF_FAILED') {
        proofExpect(isset(PROOF_CHECKS[$metadata['subreason']]),true,'proof failure is one of exactly seven reviewed gates');
        proofExpect($metadata['field'],'ITEM','proof exposes no private validation field value');
        proofExpect(is_int($metadata['item_ordinal']) && $metadata['item_ordinal']>=1 && $metadata['item_ordinal']<=30,true,'proof item ordinal is bounded');
        proofExpect(array_keys($metadata['checks']),PROOF_CHECKS[$metadata['subreason']],'proof has the exact gate-specific boolean flags');
        foreach ($metadata['checks'] as $value) proofExpect(is_bool($value),true,'proof check value is strictly boolean');
    } else {
        proofExpect([$metadata['field'],$metadata['item_ordinal'],$metadata['checks']],[null,null,[]],'nonfailure metadata carries no item or predicate observations');
        proofExpect(in_array($metadata['subreason'],['NONE','NO_PROVIDER_CALL','RESPONSE_NOT_INSPECTABLE','UNKNOWN_METADATA','PLAN_INVALID',
            'SOURCE_DECODE_FAILED','OBSERVER_EXCEPTION','PROOF_MISMATCH','SOURCE_PIN_INVALID','CLONE_INVALID','PROVIDER_MISMATCH'],true),true,
            'nonfailure subreason is a fixed reviewed enum');
    }
    $GLOBALS['publicMetadata'][]=$metadata;
}
function proofEquivalence(array $rows,array $response,string $label): array
{
    $observed=proofObserved($rows,$response);$plain=proofPlain($rows,$response);
    proofExpect($observed['calls'],1,$label.' makes exactly one observed fake provider call');
    proofExpect($plain['calls'],1,$label.' original Provider makes one fake call');
    proofExpect($observed['body'],$plain['body'],$label.' forwards the exact original request bytes');
    proofExpect($observed['key'],$plain['key'],$label.' forwards the unchanged synthetic private credential');
    proofExpect(array_keys($observed['observed']),['result','metadata'],$label.' returns only the untouched result and bounded metadata');
    proofExpect($observed['observed']['result'],$plain['result'],$label.' keeps the exact original Provider failure or success');
    proofMetadata($observed['observed']['metadata']);
    return $observed['observed'];
}
function proofNormalized(array $rows): array
{
    $plan=proofPlan($rows);$decoded=Selection::decode(proofWire($plan),$plan);
    proofExpect($decoded['ok'],true,'synthetic source selection normalizes before the unchanged semantic proof');
    return $decoded['data'];
}
function proofExpectGate(array $metadata,string $gate,int $ordinal=1): void
{
    proofMetadata($metadata);
    $facts=['posix_available'=>function_exists('posix_geteuid'),'cwd_is_real'=>realpath(getcwd())===getcwd()];
    foreach ([
        'EVIDENCE'=>['App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence','app/Services/Dashboard/WhatsAppOrderConversationEvidence.php','14521ca74822bb0359ccec81c5cb986feaa75da4'],
        'SELECTION'=>['App\\Services\\Dashboard\\WhatsAppOrderSourceSelection','app/Services/Dashboard/WhatsAppOrderSourceSelection.php','03db3211b80da905f07415fd16f1e7a62e0395b1'],
        'PROVIDER'=>['App\\Services\\Dashboard\\WhatsAppOrderAiProvider','app/Services/Dashboard/WhatsAppOrderAiProvider.php','39f644ff97c7cf6182666a5aff3f6fb231cb0733'],
    ] as $label=>[$class,$relative,$pin]) {
        $path=getcwd().'/'.$relative;$stat=lstat($path);$bytes=file_get_contents($path);
        $facts[$label]=['owner_matches'=>function_exists('posix_geteuid')&&$stat['uid']===posix_geteuid(),
            'regular'=>($stat['mode']&0170000)===0100000,'safe_mode'=>($stat['mode']&0022)===0,
            'reflected_path_matches'=>(new ReflectionClass($class))->getFileName()===$path,
            'real_path_matches'=>realpath($path)===$path,
            'blob_matches'=>sha1('blob '.strlen($bytes)."\0".$bytes)===$pin];
    }
    proofExpect([$metadata['status'],$metadata['subreason'],$metadata['field'],$metadata['item_ordinal']],['PROOF_FAILED',$gate,'ITEM',$ordinal],
        'exact original proof rejection maps to its first reviewed gate and bounded item ordinal '.json_encode(['metadata'=>Observer::sanitize($metadata),'facts'=>$facts],JSON_THROW_ON_ERROR));
}
function proofProbe(array $state,callable $safe,array $observed,bool $authorized=true,?callable $change=null): array
{
    $reads=0;$calls=0;
    $report=Probe::runObserved($authorized,static function () use ($state,&$reads,$change): array {
        $reads++;return $change===null?$state:$change($state,$reads);
    },static function () use ($observed,&$calls): array {$calls++;return $observed;},$safe);
    return ['report'=>$report,'reads'=>$reads,'calls'=>$calls];
}
/** Isolate deliberately changed source bytes and a pre-existing clone class without touching the checkout. */
function proofIsolated(array $data,array $rows,string $case): array
{
    $directory=sys_get_temp_dir().'/wa-proof-fixture-'.bin2hex(random_bytes(8));
    if (!mkdir($directory,0700)) throw new RuntimeException('Cannot create isolated synthetic fixture');
    $paths=['app/Support/WhatsAppInboxProtocol.php','app/Services/Dashboard/WhatsAppOrderConversationEvidence.php',
        'app/Support/WhatsAppOrderExtraction.php','app/Services/Dashboard/WhatsAppOrderSourceSelection.php',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php'];
    $files=[];$folders=[];
    try {
        foreach ($paths as $path) {
            $target=$directory.'/'.$path;$parent=dirname($target);
            if (!is_dir($parent) && !mkdir($parent,0700,true)) throw new RuntimeException('Cannot make isolated synthetic directory');
            $bytes=file_get_contents(dirname(__DIR__).'/'.$path);
            if (($case==='evidence-source-drift' && str_contains($path,'ConversationEvidence'))
                || ($case==='selection-source-drift' && str_contains($path,'SourceSelection'))
                || ($case==='provider-source-drift' && str_contains($path,'AiProvider'))) $bytes.="\n";
            if (file_put_contents($target,$bytes)!==strlen($bytes)) throw new RuntimeException('Cannot write isolated synthetic source');
            chmod($target,0600);$files[]=$target;
        }
        $program='chdir('.var_export($directory,true).');';
        foreach ($paths as $path) $program.='require '.var_export($directory.'/'.$path,true).';';
        $program.='require '.var_export(dirname(__DIR__).'/deployment/whatsapp_order_conversation_proof_observer.php',true).';';
        if ($case==='clone-class-collision') $program.='eval('.var_export('namespace App\\Services\\Dashboard; final class WhatsAppOrderConversationProofTrace {}',true).');';
        $program.='$data=json_decode(base64_decode('.var_export(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),true).'),true,64,JSON_THROW_ON_ERROR);';
        $program.='$rows=json_decode(base64_decode('.var_export(base64_encode(json_encode($rows,JSON_THROW_ON_ERROR)),true).'),true,64,JSON_THROW_ON_ERROR);';
        $program.='echo json_encode(WhatsAppOrderConversationProofObserver::inspect($data,$rows),JSON_THROW_ON_ERROR);';
        $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $command=[PHP_BINARY];
        // Preserve an explicit standalone test-runtime ini; normal CI runtimes
        // continue to load their usual scanned extensions and configuration.
        if (is_string(php_ini_loaded_file()) && php_ini_scanned_files()===false) {
            $command[]='-n';$command[]='-c';$command[]=php_ini_loaded_file();
        }
        $command[]='-r';$command[]=$program;
        $process=proc_open($command,$descriptors,$pipes,$directory);
        if (!is_resource($process)) throw new RuntimeException('Cannot start isolated synthetic PHP fixture');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);
        proofExpect($code,0,$case.' isolated fixture has no PHP exception');
        proofExpect($stderr,'',$case.' isolated fixture prints no trace, code or raw private values');
        $metadata=json_decode($output,true,64,JSON_THROW_ON_ERROR);proofMetadata($metadata);
        return $metadata;
    } finally {
        foreach ($files as $path) unlink($path);
        foreach (['app/Services/Dashboard','app/Services','app/Support','app'] as $path) if (is_dir($directory.'/'.$path)) rmdir($directory.'/'.$path);
        rmdir($directory);
    }
}

$rows=proofRows();$data=proofNormalized($rows);$beforeRows=$rows;$beforeData=$data;
proofExpect(Extraction::validateDetailed($data,$rows)['ok'],true,'complete synthetic fragmented order is confirmed by unchanged validator');
$metadata=Observer::inspect($data,$rows);proofMetadata($metadata);
proofExpect($metadata['status'],'NOT_APPLICABLE','successful actual fragmented proof is never labeled a failed gate');
proofExpect($rows,$beforeRows,'read-only observer leaves native messages unchanged');
proofExpect($data,$beforeData,'read-only observer leaves normalized names, quantities and citations unchanged');
$good=proofEquivalence($rows,proofResponse(proofWire(proofPlan($rows))),'successful native-source fragmented order');
proofExpect($good['result']['ok'],true,'observed Provider preserves genuine confirmed extraction');
proofExpect($good['metadata']['status'],'NOT_APPLICABLE','successful Provider extraction has no failed proof predicates');

$cases=[
    'ITEM_DECLARATION'=>static function (&$caseRows,&$caseData): void {
        $caseData['items'][0]['quantity']=null;$caseData['items'][0]['quantity_mode']='unknown';
    },
    'CUSTOMER_SELECTION'=>static function (&$caseRows,&$caseData): void {
        $caseRows[1]['text']='فسيخ من الكيلو 3 حبه بسعر 300 جنيه، ومن الكيلو 2 حبه بسعر 350 جنيه.';
        $caseData['items'][0]['conversation_reference']['customer_quantity_id']='m9';
    },
    'OFFER_VARIANT'=>static function (&$caseRows,&$caseData): void {
        $caseRows[1]['text']='فسيخ من الكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟';
    },
    'SUMMARY_AMOUNT'=>static function (&$caseRows,&$caseData): void {
        $caseRows[9]['text']='طلبك: 2 كيلو فسيخ من الكيلو 3 حبه كامل؛ 1 كيلو رنجه';
    },
    'SUMMARY_VARIANT'=>static function (&$caseRows,&$caseData): void {
        $caseRows[9]['text']='طلبك: 1.5 كيلو فسيخ من الكيلو 2 حبه كامل؛ 1 كيلو رنجه';
    },
    'SUMMARY_OPTION'=>static function (&$caseRows,&$caseData): void {
        $caseRows[9]['text']='طلبك: 1.5 كيلو فسيخ من الكيلو 3 حبه؛ 1 كيلو رنجه';
    },
    'CUSTOMER_OPTION'=>static function (&$caseRows,&$caseData): void {
        $caseRows[8]['text']='2 كيلو كامل';
    },
];
$failures=[];$inspections=[];
foreach ($cases as $gate=>$mutate) {
    $caseRows=$rows;$caseData=$data;$mutate($caseRows,$caseData);$originalRows=$caseRows;$originalData=$caseData;
    $validated=Extraction::validateDetailed($caseData,$caseRows);
    proofExpect([$validated['ok'],$validated['reason'],$validated['validation_category'],$validated['validation_field']],
        [false,'INVALID_EVIDENCE','CONVERSATION_ITEM_UNGROUNDED','ITEM'],$gate.' is exactly the real production failure category');
    $inspection=Observer::inspect($caseData,$caseRows);proofExpectGate($inspection,$gate);
    proofExpect($caseRows,$originalRows,$gate.' observation cannot repair fragmented customer messages');
    proofExpect($caseData,$originalData,$gate.' observation cannot repair a reference, item or evidence list');
    $failure=proofEquivalence($caseRows,proofResponse($caseData),$gate.' unchanged Provider rejection');
    proofExpect($failure['result']['ok'],false,$gate.' diagnostic never converts invalid evidence to an extraction');
    proofExpect($failure['result']['data'],null,$gate.' returns no replacement basket or ERP facts');
    proofExpect($failure['result']['diagnostic']['validation_category'],'CONVERSATION_ITEM_UNGROUNDED',$gate.' original failure remains unchanged');
    proofExpect($failure['metadata'],$inspection,$gate.' single captured response reports the same bounded inspection');
    $failures[$gate]=['rows'=>$caseRows,'data'=>$caseData,'observed'=>$failure];$inspections[$gate]=$inspection;
}
proofExpect($inspections['CUSTOMER_SELECTION']['checks']['positive_selection'],false,'later bare preparation answer is not a positive quantity request');
proofExpect($inspections['CUSTOMER_SELECTION']['checks']['quantity_question'],false,'price offer without quantity question cannot commit the later bare answer');
proofExpect($inspections['CUSTOMER_SELECTION']['checks']['direct_offer_answer'],false,'later preparation answer is not adjacent to the business offer');
proofExpect($inspections['OFFER_VARIANT']['checks'],['selected_variant_present'=>true,'selected_variant_offered'=>false],
    'selected three-fish size is reported only as mismatch booleans, never copied as a numeric value');
proofExpect($inspections['SUMMARY_AMOUNT']['checks']['amount_matches'],false,'recap mismatch is reported without either customer or business quantity');
proofExpect($inspections['SUMMARY_VARIANT']['checks']['summary_variant_matches'],false,'recap size mismatch is fixed boolean only');
proofExpect($inspections['SUMMARY_OPTION']['checks']['option_in_summary'],false,'missing preparation option is fixed boolean only');
proofExpect($inspections['CUSTOMER_OPTION']['checks']['amount_matches'],false,'option message quantity mismatch is fixed boolean only');

// A genuine new-schema response choosing the later bare preparation row reproduces the same gate.
$nativeRows=$failures['CUSTOMER_SELECTION']['rows'];$nativePlan=proofPlan($nativeRows);$nativeWire=proofWire($nativePlan);
$nativeWire['items'][0]['conversation_reference']['customer_quantity_id']='m9';
$nativeWire['evidence']['request_ids']=array_values(array_diff($nativeWire['evidence']['request_ids'],['m1','m5']));
$nativeFailure=proofEquivalence($nativeRows,proofResponse($nativeWire),'native-source later preparation row');
proofExpectGate($nativeFailure['metadata'],'CUSTOMER_SELECTION');
proofExpect($nativeFailure['result']['diagnostic']['validation_category'],'CONVERSATION_ITEM_UNGROUNDED','actual native schema passes source checks then retains exact semantic rejection');

// Trace state is cleared between calls; the successful basket cannot inherit a prior failure.
foreach (array_reverse(array_keys($cases)) as $gate) {
    $failure=$failures[$gate];proofExpectGate(Observer::inspect($failure['data'],$failure['rows']),$gate);
    $ready=Observer::inspect($data,$rows);proofMetadata($ready);
    proofExpect($ready['status'],'NOT_APPLICABLE','repeated inspection resets every previous failed gate');
    proofExpect($ready['checks'],[],'successful repeated proof retains no previous booleans');
}
$secondRows=$rows;$secondRows[9]['text']='طلبك: 1.5 كيلو فسيخ من الكيلو 3 حبه كامل؛ 2 كيلو رنجه';
$second=Observer::inspect($data,$secondRows);proofExpectGate($second,'SUMMARY_AMOUNT',2);

foreach (['source-invalid','customer-name-ungrounded','missing-acceptance','later-cancel','missing-data-ack','not-confirmed'] as $case) {
    $otherRows=$rows;$otherData=$data;
    if ($case==='source-invalid') $otherData['items'][0]['conversation_reference']['name_anchor_id']='m999';
    if ($case==='customer-name-ungrounded') $otherData['customer']['name']='PRIVATE_UNGROUNDED_CUSTOMER';
    if ($case==='missing-acceptance') $otherData['evidence']['customer_acceptance_ids']=[];
    if ($case==='later-cancel') $otherRows[]=proofRow('m17','customer','الغي الطلب',17);
    if ($case==='missing-data-ack') $otherRows[15]['text']='شكرا';
    if ($case==='not-confirmed') $otherData['decision']='DRAFT';
    $other=proofEquivalence($otherRows,proofResponse($otherData),$case.' not the requested proof gate');
    proofExpect($other['metadata']['status'],'NOT_APPLICABLE',$case.' never exposes unrelated proof state');
    proofExpect($other['metadata']['checks'],[],$case.' exposes no unrelated predicates or facts');
}

$response=proofResponse(proofWire(proofPlan($rows)));$envelope=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
$badResponses=[];
$badResponses['http-error']=$response;$badResponses['http-error']['status']=401;
$badResponses['malformed-json']=$response;$badResponses['malformed-json']['body']='{"PRIVATE_REJECTED_RESPONSE":';
$badResponses['oversized-response']=$response;$badResponses['oversized-response']['body']=str_repeat('PRIVATE_RAW_RESPONSE_',55000);
$badEnvelope=$envelope;$badEnvelope['status']='incomplete';$badEnvelope['PRIVATE_SECRET']='PRIVATE_RESPONSE_TOKEN';
$badResponses['incomplete']=$response;$badResponses['incomplete']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['output'][0]['role']='user';
$badResponses['wrong-role']=$response;$badResponses['wrong-role']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['output'][0]['content'][]=$badEnvelope['output'][0]['content'][0];
$badResponses['multiple-texts']=$response;$badResponses['multiple-texts']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['output'][0]['content']=[['type'=>'refusal','refusal'=>'PRIVATE_REFUSAL']];
$badResponses['refusal']=$response;$badResponses['refusal']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
foreach ($badResponses as $label=>$badResponse) {
    $failure=proofEquivalence($rows,$badResponse,$label);
    proofExpect($failure['result']['ok'],false,$label.' retains original fail-closed Provider result');
    proofExpect($failure['metadata']['status'],'NOT_APPLICABLE',$label.' rejected response is never proof-inspected');
    proofExpect($failure['metadata']['checks'],[],$label.' response contents are never exposed');
}
$zeroCalls=0;$disabled=Observer::extract($rows,['enabled'=>false],static function () use (&$zeroCalls): array {$zeroCalls++;throw new RuntimeException('PRIVATE_UNREACHABLE');});
proofExpect($zeroCalls,0,'disabled provider makes no call');
proofExpect($disabled['result']['reason'],'DISABLED','observer preserves original disabled reason');
proofMetadata($disabled['metadata']);
proofExpect($disabled['metadata']['subreason'],'NO_PROVIDER_CALL','no-call metadata is bounded');

$unknown=Observer::sanitize(null);proofMetadata($unknown);
foreach ([null,[],['PRIVATE_SECRET'=>'PRIVATE_RESPONSE'],
    ['status'=>'PROOF_FAILED','subreason'=>'PRIVATE_REASON','field'=>'ITEM','item_ordinal'=>1,'checks'=>[]],
    ['status'=>'PROOF_FAILED','subreason'=>'CUSTOMER_SELECTION','field'=>'ITEM','item_ordinal'=>0,'checks'=>$inspections['CUSTOMER_SELECTION']['checks']],
    ['status'=>'PROOF_FAILED','subreason'=>'CUSTOMER_SELECTION','field'=>'ITEM','item_ordinal'=>31,'checks'=>$inspections['CUSTOMER_SELECTION']['checks']],
    ['status'=>'PROOF_FAILED','subreason'=>'CUSTOMER_SELECTION','field'=>'ITEM','item_ordinal'=>'1','checks'=>$inspections['CUSTOMER_SELECTION']['checks']],
    ['status'=>'PROOF_FAILED','subreason'=>'CUSTOMER_SELECTION','field'=>'ITEM_NAME','item_ordinal'=>1,'checks'=>$inspections['CUSTOMER_SELECTION']['checks']],
    ['status'=>'PROOF_FAILED','subreason'=>'CUSTOMER_SELECTION','field'=>'ITEM','item_ordinal'=>1,'checks'=>['PRIVATE_FLAG'=>true]],
    ['status'=>'PROOF_FAILED','subreason'=>'CUSTOMER_SELECTION','field'=>'ITEM','item_ordinal'=>1,'checks'=>$inspections['CUSTOMER_SELECTION']['checks'],'PRIVATE_EXTRA'=>'PRIVATE_VALUE']] as $raw) {
    proofExpect(Observer::sanitize($raw),$unknown,'malformed metadata cannot copy arbitrary fields, IDs, flags or quantities');
}
$wrongBoolean=$inspections['CUSTOMER_SELECTION'];$wrongBoolean['checks']['amount_matches']=1;
proofExpect(Observer::sanitize($wrongBoolean),$unknown,'boolean flags never accept integer coercion');
$wrongKeys=$inspections['CUSTOMER_SELECTION'];unset($wrongKeys['checks']['amount_matches']);
proofExpect(Observer::sanitize($wrongKeys),$unknown,'incomplete predicate map is never exposed');
foreach (['evidence-source-drift','selection-source-drift','provider-source-drift','clone-class-collision'] as $case) {
    $isolated=proofIsolated($failures['CUSTOMER_SELECTION']['data'],$failures['CUSTOMER_SELECTION']['rows'],$case);
    proofExpect($isolated['status'],'OBSERVER_STOPPED',$case.' prevents any trace from unreviewed code or a foreign clone class');
    proofExpect($isolated['subreason'],$case==='clone-class-collision'?'CLONE_INVALID':'SOURCE_PIN_INVALID',$case.' has a fixed reason');
    proofExpect($isolated['checks'],[],$case.' exposes no predicates from the unverified source');
}

$workflow=(new ReflectionClass(\App\Services\Dashboard\WhatsAppOrderWorkflow::class))->newInstanceWithoutConstructor();
$failureMethod=new ReflectionMethod($workflow,'safeAnalysisFailure');$failureMethod->setAccessible(true);
$safe=static fn ($result): array=>$failureMethod->invoke($workflow,$result);
$state=['configured'=>true,'floor'=>4016,'ceiling'=>5000,'configuration_fingerprint'=>str_repeat('a',64),
    'snapshot'=>['complete'=>true,'hash'=>str_repeat('b',64),'transcript'=>$nativeRows,'keys'=>['m1'=>'PRIVATE_NATIVE_KEY']],
    'status_event_7215'=>['status'=>'READY','scope_matches'=>true,'statuses_count'=>1,'truncated'=>false,
        'statuses'=>[['status_type'=>'STRING','status'=>'played','id_valid'=>true]]]];
$failed=proofProbe($state,$safe,$nativeFailure);$report=$failed['report'];
proofExpect([$failed['reads'],$failed['calls']],[3,1],'stable guarded probe makes bounded reads and one extraction');
proofExpect($report['output']['status'],'AI_EXTRACTION_FAILED','probe preserves exact semantic failure status');
proofExpect($report['output']['analysis_failure']['diagnostic']['validation_category'],'CONVERSATION_ITEM_UNGROUNDED','probe failure is independently matched to original category');
proofExpect($report['output']['conversation_proof'],$nativeFailure['metadata'],'only stable matching gate metadata is exposed');
proofExpect([$report['output']['writes'],$report['output']['ai_calls'],$report['output']['snapshot_current']],[0,1,true],'guarded accounting remains read-only, current and single-call');
$direct=Probe::run(true,static fn ()=>$state,static fn ()=>$nativeFailure['result'],$safe);
$withoutMetadata=$report;unset($withoutMetadata['output']['conversation_proof']);
proofExpect($withoutMetadata,$direct,'observed probe keeps the original guarded report exactly plus metadata');
$publicMetadata[]=$report['output'];

foreach (['unauthorized','incomplete','precall-ceiling','postcall-ceiling','postcall-floor','postcall-configuration','postcall-snapshot','postcall-exception'] as $case) {
    $testState=$state;$authorized=$case!=='unauthorized';$change=null;
    if ($case==='incomplete') $testState['snapshot']['complete']=false;
    if ($case==='precall-ceiling') $change=static function ($value,$read) {if ($read===2)$value['ceiling']++;return $value;};
    if ($case==='postcall-ceiling') $change=static function ($value,$read) {if ($read===3)$value['ceiling']++;return $value;};
    if ($case==='postcall-floor') $change=static function ($value,$read) {if ($read===3)$value['floor']++;return $value;};
    if ($case==='postcall-configuration') $change=static function ($value,$read) {if ($read===3)$value['configuration_fingerprint']=str_repeat('c',64);return $value;};
    if ($case==='postcall-snapshot') $change=static function ($value,$read) {if ($read===3)$value['snapshot']['hash']=str_repeat('d',64);return $value;};
    if ($case==='postcall-exception') $change=static function ($value,$read) {if ($read===3)throw new RuntimeException('PRIVATE_POSTCALL_ERROR');return $value;};
    $stopped=proofProbe($testState,$safe,$nativeFailure,$authorized,$change);
    proofExpect($stopped['report']['output']['conversation_proof'],$unknown,$case.' exposes no stale or unauthorized trace');
    proofExpect($stopped['calls'],str_starts_with($case,'postcall-')?1:0,$case.' cannot retry or call extraction when unqualified');
    proofExpect(isset($stopped['report']['output']['extraction']),false,$case.' exposes no discarded extraction');
    $publicMetadata[]=$stopped['report']['output'];
}
foreach (['wrong-field','wrong-category','wrong-status','malicious-metadata','malformed-result'] as $case) {
    $fake=$nativeFailure;
    if ($case==='wrong-field') $fake['result']['diagnostic']['validation_field']='ITEM_NAME';
    if ($case==='wrong-category') $fake['result']['diagnostic']['validation_category']='LITERAL_NOT_CITED';
    if ($case==='wrong-status') $fake['metadata']=$good['metadata'];
    if ($case==='malicious-metadata') $fake['metadata']=['PRIVATE_SECRET'=>'PRIVATE_RAW'];
    if ($case==='malformed-result') $fake['result']='PRIVATE_RESPONSE';
    $sanitized=proofProbe($state,$safe,$fake);
    proofExpect($sanitized['calls'],1,$case.' never retries extraction');
    proofExpect($sanitized['report']['output']['conversation_proof'],$unknown,$case.' mismatched or arbitrary trace is suppressed');
    $publicMetadata[]=$sanitized['report']['output'];
}
$readyState=$state;$readyState['snapshot']['transcript']=$rows;
$success=proofProbe($readyState,$safe,$good);
proofExpect($success['report']['output']['status'],'AI_EXTRACTION_READY','guarded success remains an independently validated success');
proofExpect($success['report']['output']['conversation_proof'],$unknown,'successful extraction exposes no failure predicates');
$publicMetadata[]=$success['report']['output'];
$callbackCalls=0;$exception=Probe::runObserved(true,static fn ()=>$state,static function () use (&$callbackCalls): array {
    $callbackCalls++;throw new RuntimeException('PRIVATE_CALLBACK_TOKEN');
},$safe);
proofExpect($callbackCalls,1,'throwing observed callback attempts exactly once');
proofExpect($exception['output']['conversation_proof'],$unknown,'callback exception never copies private error or a stale gate');
$publicMetadata[]=$exception['output'];
proofExpect(Probe::authorized(['probe.php','--probe-one']),true,'exact explicit one-probe flag is required');
foreach ([['probe.php'],['probe.php','--probe-one','--probe-one'],['probe.php','--probe-one=1'],['probe.php','--conversation=12']] as $arguments)
    proofExpect(Probe::authorized($arguments),false,'alternate flags never authorize an extraction');

foreach (Probe::SOURCE_PINS as [$path,$expected]) {
    $bytes=file_get_contents(dirname(__DIR__).'/'.$path);
    proofExpect(sha1('blob '.strlen($bytes)."\0".$bytes),$expected,'probe verifies the unchanged reviewed application source');
}
$observerBytes=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_order_conversation_proof_observer.php');
proofExpect(sha1('blob '.strlen($observerBytes)."\0".$observerBytes),Probe::OBSERVER_BLOB,'probe verifies the exact reviewed observer bytes');
$probeBytes=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_order_conversation_proof_read_only_probe.php');
proofExpect(preg_match('/(?:->|::)(?:consume|process|projectPending|projectAcceptedReply|analyze|dispatch|insert|update|delete|save)\s*\(/',$probeBytes),0,
    'guarded proof observer has no writes or order-processing call sites');
proofExpect(str_contains($probeBytes,'->lockForUpdate('),false,'guarded proof observer obtains no write locks');
proofExpect(substr_count($probeBytes,'WhatsAppOrderConversationProofObserver::extract('),1,'guarded production observer has exactly one extraction call site');
proofExpect(str_contains($probeBytes,'$snapshot->invoke($workflow, $cid, $floor, $ceiling, false)'),true,'snapshot remains explicitly read-only');
proofExpect(str_contains($probeBytes,"->where('id', 7215)"),true,'status observation remains bound to requested event');

$serialized=json_encode($publicMetadata,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach (['فسيخ','رنجه','كامل','عميل تجريبي','01000000001','مدينة التجربة','PRIVATE_','m1','m2','m3',
    'first_token','last_token','name_source','option_source','customer_quantity_id'] as $private)
    proofExpect(str_contains($serialized,$private),false,'public predicate metadata omits native messages, identities, quantities and source labels');
echo 'WHATSAPP_ORDER_CONVERSATION_PROOF_READ_ONLY_PROBE_CHECKS='.$checks."\n";
