<?php
// Synthetic transcript and private fake HTTP only. No Laravel bootstrap, database,
// external request, credentials, source publication or ERP/order actions.
define('WHATSAPP_ORDER_SOURCE_REFERENCE_READ_ONLY_PROBE_TEST_ONLY', true);
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__) . '/tests/fixtures/whatsapp_order_pre_anchor_citation/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require dirname(__DIR__) . '/tests/fixtures/whatsapp_order_pre_anchor_citation/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderWorkflow.php';
require dirname(__DIR__) . '/deployment/whatsapp_order_source_reference_observer.php';
require dirname(__DIR__) . '/deployment/whatsapp_order_source_reference_read_only_probe.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Services\Dashboard\WhatsAppOrderSourceSelection as Selection;
use App\Support\WhatsAppOrderExtraction as Extraction;
use WhatsAppOrderSourceReferenceObserver as Observer;
use WhatsAppOrderSourceReferenceReadOnlyProbe as Probe;

$checks = 0;
$publicMetadata = [];
function referenceExpect($actual, $expected, string $label): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function referenceRow(string $id, string $speaker, string $text, int $second): array
{
    return ['id'=>$id, 'speaker'=>$speaker, 'sent_at'=>'2026-10-13 12:00:'.sprintf('%02d',$second),
        'text'=>$text, 'location'=>null];
}
function referenceRows(): array
{
    return [
        referenceRow('m1','customer','الفسيخ بكام؟',1),
        referenceRow('m2','business','فسيخ من الكيلو 3 حبه بسعر 300 جنيه، ومن الكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟',2),
        referenceRow('m3','customer','عايز كيلو ونص من الكيلو 3 حبه',3),
        referenceRow('m4','business','تمام',4),
        referenceRow('m5','customer','في رنجه',5),
        referenceRow('m6','business','رنجه بسعر 220 جنيه للكيلو. تحب قد إيه؟',6),
        referenceRow('m7','customer','تمام اعملي كيلو',7),
        referenceRow('m8','business','تحب الفسيخ كامل ولا تنظيف؟',8),
        referenceRow('m9','customer','كيلو ونص كامل',9),
        referenceRow('m10','business','طلبك: 1.5 كيلو فسيخ من الكيلو 3 حبه كامل؛ 1 كيلو رنجه',10),
        referenceRow('m11','customer','تمام موافق',11),
        referenceRow('m12','business','ابعت الاسم ورقم الهاتف والعنوان',12),
        referenceRow('m13','customer','عميل تجريبي',13),
        referenceRow('m14','customer','01000000001',14),
        referenceRow('m15','customer','مدينة التجربة المنصورة شارع المثال',15),
        referenceRow('m16','business','تم استلام الاسم ورقم الهاتف والعنوان وتأكيد الطلب',16),
    ];
}
function referencePlan(array $rows): array
{
    $built=Selection::build($rows);
    referenceExpect($built['ok'],true,'synthetic full source plan is valid');
    return $built['data'];
}
function referenceSpan(array $plan, string $id, string $word, string $source='text'): array
{
    foreach ($plan['input']['name_tokens'] as $row) {
        if ($row['message_id']!==$id || $row['source']!==$source) continue;
        foreach ($row['tokens'] as $token) if ($token['text']===$word)
            return ['message_id'=>$id,'source'=>$source,'first_token'=>$token['index'],'last_token'=>$token['index']];
    }
    throw new RuntimeException('Missing synthetic source token');
}
function referenceClause(array $plan,int $ordinal): string
{
    foreach ($plan['input']['summary_clauses'] as $clause)
        if ($clause['message_id']==='m10' && $clause['clause_index']===$ordinal) return $clause['id'];
    throw new RuntimeException('Missing synthetic recap clause');
}
function referenceWire(array $plan): array
{
    return ['decision'=>'CONFIRMED', 'customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001',
        'address'=>'مدينة التجربة المنصورة شارع المثال','area'=>'المنصورة','notes'=>null],
        'branch_hint'=>null,'fulfillment'=>'DELIVERY','items'=>[
            ['name_source'=>referenceSpan($plan,'m1','فسيخ'),'quantity'=>'1.5','quantity_mode'=>'weight',
                'option_source'=>referenceSpan($plan,'m9','كامل'),'cart_reference'=>null,
                'conversation_reference'=>['business_offer_id'=>'m2','customer_quantity_id'=>'m3','summary_clause_id'=>referenceClause($plan,0)]],
            ['name_source'=>referenceSpan($plan,'m5','رنجه'),'quantity'=>'1','quantity_mode'=>'weight',
                'option_source'=>null,'cart_reference'=>null,
                'conversation_reference'=>['business_offer_id'=>'m6','customer_quantity_id'=>'m7','summary_clause_id'=>referenceClause($plan,1)]],
        ],'approximate_total'=>null,'evidence'=>[
            'request_ids'=>['m1','m3','m5','m7','m9','m13','m14','m15'],
            'confirmation_ids'=>['m10','m16'],'customer_acceptance_ids'=>['m11'],'location_id'=>null], 'issues'=>[]];
}
function referenceConfiguration(): array
{
    return ['enabled'=>true,'mode'=>'auto','model'=>'fixture-model','api_key'=>'sk-source-reference-PRIVATE_FIXTURE_ONLY'];
}
function referenceResponse(array $wire): array
{
    return ['status'=>200,'curl_errno'=>0,'elapsed_ms'=>123,'body'=>json_encode([
        'status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[
            ['type'=>'output_text','text'=>json_encode($wire,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]],
        'usage'=>['input_tokens'=>9000,'output_tokens'=>700,'output_tokens_details'=>['reasoning_tokens'=>400]],
    ],JSON_THROW_ON_ERROR)];
}
function referenceObserved(array $rows,array $response): array
{
    $calls=0;$body=null;$key=null;
    $observed=Observer::extract($rows,referenceConfiguration(),static function ($request,$credential) use (&$calls,&$body,&$key,$response): array {
        $calls++;$body=$request;$key=$credential;return $response;
    });
    return ['observed'=>$observed,'calls'=>$calls,'body'=>$body,'key'=>$key];
}
function referencePlain(array $rows,array $response): array
{
    $calls=0;$body=null;$key=null;
    $provider=new Provider(static function ($request,$credential) use (&$calls,&$body,&$key,$response): array {
        $calls++;$body=$request;$key=$credential;return $response;
    },referenceConfiguration());
    return ['result'=>$provider->extract($rows),'calls'=>$calls,'body'=>$body,'key'=>$key];
}
function referenceMetadata(array $metadata): void
{
    referenceExpect(array_keys($metadata),['status','subreason','field','item_ordinal'],'metadata contains only four reviewed keys');
    referenceExpect(in_array($metadata['status'],['SOURCE_REFERENCE_READY','SOURCE_REFERENCE_FAILED','NOT_OBSERVED','OBSERVER_STOPPED'],true),true,'metadata status is a fixed enum');
    referenceExpect(in_array($metadata['subreason'],Observer::SUBREASONS,true),true,'metadata subreason is an exact reviewed enum');
    referenceExpect(in_array($metadata['field'],[null,'ITEM','ITEM_NAME','ITEM_OPTION'],true),true,'metadata field is a fixed enum');
    referenceExpect($metadata['item_ordinal']===null || (is_int($metadata['item_ordinal']) && $metadata['item_ordinal']>=1 && $metadata['item_ordinal']<=30),true,'metadata ordinal is bounded and never a source ID or index');
    $GLOBALS['publicMetadata'][]=$metadata;
}
function referenceEquivalence(array $rows,array $response,string $label): array
{
    $observed=referenceObserved($rows,$response);$plain=referencePlain($rows,$response);
    referenceExpect($observed['calls'],1,$label.' makes exactly one observed fake HTTP call');
    referenceExpect($plain['calls'],1,$label.' has exactly one original provider call');
    referenceExpect($observed['body'],$plain['body'],$label.' forwards original request bytes unchanged');
    referenceExpect($observed['key'],$plain['key'],$label.' forwards original private credential unchanged');
    referenceExpect(array_keys($observed['observed']),['result','metadata'],$label.' returns only exact result and sanitized metadata');
    referenceExpect($observed['observed']['result'],$plain['result'],$label.' preserves exact original validated provider result');
    referenceMetadata($observed['observed']['metadata']);
    return $observed['observed'];
}

$rows=referenceRows();$plan=referencePlan($rows);$wire=referenceWire($plan);
$beforeWire=$wire;$beforePlan=$plan;
$metadata=Observer::inspect($wire,$plan);referenceMetadata($metadata);
referenceExpect($metadata['status'],'SOURCE_REFERENCE_READY','valid source references are diagnostic-ready');
referenceExpect($wire,$beforeWire,'inspection cannot mutate or add evidence citations');
referenceExpect($plan,$beforePlan,'inspection cannot mutate native source plan');
$good=referenceEquivalence($rows,referenceResponse($wire),'valid fragmented order');
referenceExpect($good['result']['ok'],true,'observed provider still validates the complete fragmented basket');
referenceExpect($good['result']['data']['evidence'],$wire['evidence'],'observer adds no evidence citations');
referenceExpect($good['metadata']['status'],'SOURCE_REFERENCE_READY','successful validated response retains source-ready metadata');

$cases=[
    'source-not-array'=>['SOURCE_SHAPE','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']='PRIVATE_SOURCE_TEXT';}],
    'source-missing-key'=>['SOURCE_SHAPE','ITEM_NAME',static function (&$data): void {unset($data['items'][0]['name_source']['last_token']);}],
    'source-extra-key'=>['SOURCE_SHAPE','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['PRIVATE_EXTRA']='PRIVATE_VALUE';}],
    'id-not-string'=>['SOURCE_ID_TYPE','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['message_id']=1;}],
    'unknown-source-kind'=>['SOURCE_KIND_INVALID','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['source']='PRIVATE_JOIN_TEXT';}],
    'string-index'=>['TOKEN_TYPE','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['first_token']='1';}],
    'negative-index'=>['TOKEN_NEGATIVE','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['first_token']=-1;}],
    'reversed-span'=>['TOKEN_ORDER_INVALID','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['first_token']=1;$data['items'][0]['name_source']['last_token']=0;}],
    'missing-row'=>['SOURCE_ROW_MISSING','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['message_id']='m60';}],
    'outside-table'=>['TOKEN_INDEX_MISSING','ITEM_NAME',static function (&$data): void {$data['items'][0]['name_source']['last_token']=100;}],
    'context-name-business'=>['CONTEXT_NAME_SPEAKER','ITEM_NAME',static function (&$data) use ($plan): void {$data['items'][0]['name_source']=referenceSpan($plan,'m2','فسيخ');}],
    'request-list-not-array'=>['CONTEXT_REQUEST_LIST_INVALID','ITEM_NAME',static function (&$data): void {$data['items'][0]['option_source']=null;$data['evidence']['request_ids']='PRIVATE_CITATION_LIST';}],
    'context-name-uncited'=>['CONTEXT_NAME_UNCITED','ITEM_NAME',static function (&$data): void {$data['evidence']['request_ids']=array_values(array_diff($data['evidence']['request_ids'],['m1']));}],
    'option-not-array'=>['SOURCE_SHAPE','ITEM_OPTION',static function (&$data): void {$data['items'][0]['option_source']='PRIVATE_OPTION';}],
    'option-uncited'=>['OPTION_UNCITED','ITEM_OPTION',static function (&$data): void {$data['evidence']['request_ids']=array_values(array_diff($data['evidence']['request_ids'],['m9']));}],
    'context-cart-conflict'=>['CONTEXT_CART_CONFLICT','ITEM',static function (&$data): void {$data['items'][0]['cart_reference']=['message_id'=>'m1','product_retailer_id'=>'PRIVATE_SKU'];}],
    'context-bad-shape'=>['CONTEXT_SHAPE','ITEM',static function (&$data): void {$data['items'][0]['conversation_reference']['PRIVATE_EXTRA']='PRIVATE_VALUE';}],
    'context-invalid-offer'=>['CONTEXT_OFFER_REFERENCE_INVALID','ITEM',static function (&$data): void {$data['items'][0]['conversation_reference']['business_offer_id']='m1';}],
    'context-invalid-quantity'=>['CONTEXT_QUANTITY_REFERENCE_INVALID','ITEM',static function (&$data): void {$data['items'][0]['conversation_reference']['customer_quantity_id']='m2';}],
    'context-option-business'=>['CONTEXT_OPTION_SPEAKER','ITEM_OPTION',static function (&$data) use ($plan): void {$data['items'][0]['option_source']=referenceSpan($plan,'m10','كامل');}],
    'context-option-not-request-cited'=>['CONTEXT_OPTION_UNCITED','ITEM_OPTION',static function (&$data): void {$data['evidence']['request_ids']=array_values(array_diff($data['evidence']['request_ids'],['m9']));$data['evidence']['confirmation_ids'][]='m9';}],
    'context-missing-clause'=>['CONTEXT_CLAUSE_INVALID','ITEM',static function (&$data): void {$data['items'][0]['conversation_reference']['summary_clause_id']='PRIVATE_CLAUSE_ID';}],
    'hybrid-legacy-name'=>['ITEM_SHAPE','ITEM',static function (&$data): void {$data['items'][0]['name']='PRIVATE_GENERATED_NAME';}],
    'hybrid-legacy-option'=>['ITEM_SHAPE','ITEM_OPTION',static function (&$data): void {$data['items'][0]['option_hint']='PRIVATE_GENERATED_OPTION';}],
];
$caseMetadata=[];
foreach ($cases as $label=>[$reason,$field,$mutate]) {
    $bad=$wire;$mutate($bad);$before=$bad;
    $details=Observer::inspect($bad,$plan);referenceMetadata($details);
    referenceExpect($details,['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>$reason,'field'=>$field,'item_ordinal'=>1],$label.' reports only a fixed first-failure detail');
    referenceExpect($bad,$before,$label.' diagnostic adds no source corrections or citations');
    $decoded=Selection::decode($bad,$plan);
    referenceExpect($decoded['ok'],false,$label.' actual unchanged decoder rejects');
    referenceExpect($decoded['validation_category'],'SOURCE_REFERENCE_INVALID',$label.' corresponds to actual decoder source failure');
    referenceExpect($decoded['validation_field'],$field,$label.' corresponds to actual decoder field');
    $run=referenceEquivalence($rows,referenceResponse($bad),$label);
    referenceExpect($run['result']['ok'],false,$label.' never repairs a rejected provider result');
    referenceExpect($run['result']['data'],null,$label.' never returns fabricated extraction data');
    referenceExpect($run['metadata'],$details,$label.' observed response produces same bounded source detail');
    $caseMetadata[$label]=$details;
}
referenceExpect($caseMetadata['missing-row']['subreason']!==$caseMetadata['context-name-uncited']['subreason'],true,
    'copiedSource lookup errors are distinguished from valid contextual source missing request citation');

$second=$wire;$second['items'][1]['name_source']['last_token']=100;
$secondDetail=Observer::inspect($second,$plan);referenceMetadata($secondDetail);
referenceExpect($secondDetail,['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'TOKEN_INDEX_MISSING','field'=>'ITEM_NAME','item_ordinal'=>2],
    'first failing item is identified by bounded ordinal without exposing source labels');
$longRows=$rows;$longRows[0]['text']=str_repeat('ص',201);$longPlan=referencePlan($longRows);$longWire=referenceWire($plan);
$longWire['items'][0]['name_source']=referenceSpan($longPlan,'m1',str_repeat('ص',201));
$longDetail=Observer::inspect($longWire,$longPlan);referenceMetadata($longDetail);
referenceExpect($longDetail,['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'SPAN_TOO_LONG','field'=>'ITEM_NAME','item_ordinal'=>1],
    'overlong copied UTF-8 span fails without exposing native phrase or token indices');
referenceEquivalence($longRows,referenceResponse($longWire),'overlong native span');

$cartRows=$rows;$cartRows[0]['cart']=\App\Support\WhatsAppInboxProtocol::cart([
    'catalog_id'=>'1234567','text'=>'فسيخ كامل','product_items'=>[
        ['product_retailer_id'=>'PRIVATE_SYNTHETIC_SKU','quantity'=>1,'item_price'=>'100.00','currency'=>'EGP']]]);
referenceExpect(is_array($cartRows[0]['cart']),true,'cart-field case uses a valid normalized native cart');
$cartPlan=referencePlan($cartRows);$cartWire=referenceWire($cartPlan);
$cartWire['items'][0]['name_source']=referenceSpan($cartPlan,'m1','فسيخ','cart_text');
$cartNameDetail=Observer::inspect($cartWire,$cartPlan);referenceMetadata($cartNameDetail);
referenceExpect($cartNameDetail,['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'CONTEXT_NAME_FIELD','field'=>'ITEM_NAME','item_ordinal'=>1],
    'valid cart source is distinguished from required contextual native text');
$cartWire=referenceWire($cartPlan);$cartWire['items'][0]['option_source']=referenceSpan($cartPlan,'m1','كامل','cart_text');
$cartOptionDetail=Observer::inspect($cartWire,$cartPlan);referenceMetadata($cartOptionDetail);
referenceExpect($cartOptionDetail,['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'CONTEXT_OPTION_FIELD','field'=>'ITEM_OPTION','item_ordinal'=>1],
    'valid cart option is distinguished from required contextual native text');

foreach (['changed-text','changed-tokens','changed-source','changed-schema','changed-clauses','missing-input'] as $case) {
    $poisoned=$plan;
    if ($case==='changed-text') $poisoned['sources']['m1:text']['text']='PRIVATE_POISONED_NATIVE';
    if ($case==='changed-tokens') $poisoned['sources']['m1:text']['tokens'][0]['start']=99999;
    if ($case==='changed-source') $poisoned['sources']['m1:text']['speaker']='business';
    if ($case==='changed-schema') $poisoned['schema']['PRIVATE_EXTRA']='PRIVATE_VALUE';
    if ($case==='changed-clauses') $poisoned['clauses']['PRIVATE_CLAUSE']=['message_id'=>'m1','clause_index'=>0];
    if ($case==='missing-input') unset($poisoned['input']);
    $detail=Observer::inspect($wire,$poisoned);referenceMetadata($detail);
    referenceExpect($detail,['status'=>'OBSERVER_STOPPED','subreason'=>'PLAN_INVALID','field'=>null,'item_ordinal'=>null],
        $case.' untrusted plan cannot manufacture a diagnostic');
}
$many=$wire;$many['items']=array_fill(0,31,$wire['items'][0]);$tooMany=Observer::inspect($many,$plan);referenceMetadata($tooMany);
referenceExpect($tooMany,['status'=>'OBSERVER_STOPPED','subreason'=>'ITEM_LIMIT_EXCEEDED','field'=>null,'item_ordinal'=>null],
    'source inspection has an explicit bounded item list');
$last=$wire;$last['items']=array_fill(0,30,$wire['items'][0]);$last['items'][29]['name_source']['last_token']=100;
$lastDetail=Observer::inspect($last,$plan);referenceMetadata($lastDetail);
referenceExpect($lastDetail['item_ordinal'],30,'largest eligible ordinal remains bounded');

// Source-ready only means decoding succeeded, never that semantic order proof succeeded.
$semantic=$wire;$semantic['items'][0]['quantity']='9';$semanticRun=referenceEquivalence($rows,referenceResponse($semantic),'wrong semantic quantity');
referenceExpect($semanticRun['metadata']['status'],'SOURCE_REFERENCE_READY','literal source lookup is distinguished from later semantic rejection');
referenceExpect($semanticRun['result']['ok'],false,'source-ready cannot bypass quantity proof');
referenceExpect($semanticRun['result']['data'],null,'source-ready does not create a replacement basket');
$legacy=Extraction::none('UNCONFIRMED');$legacy['decision']='DRAFT';$legacy['items']=[[
    'name'=>'PRIVATE_UNGROUNDED_NAME','quantity'=>null,'quantity_mode'=>'unknown','option_hint'=>null,
    'cart_reference'=>null,'conversation_reference'=>null]];$legacy['evidence']['request_ids']=['m1'];
$legacyRun=referenceEquivalence($rows,referenceResponse($legacy),'legacy ungrounded DTO');
referenceExpect($legacyRun['metadata']['status'],'SOURCE_REFERENCE_READY','legacy DTO remains the pinned decoder contract');
referenceExpect($legacyRun['result']['ok'],false,'legacy ungrounded name still fails the unchanged semantic validator');

$response=referenceResponse($wire);$envelope=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
$badResponses=[];
$badResponses['non-200']=$response;$badResponses['non-200']['status']=401;
$badResponses['truncated-json']=$response;$badResponses['truncated-json']['body']='{"PRIVATE_REJECTED_RESPONSE":';
$badResponses['oversized-response']=$response;$badResponses['oversized-response']['body']=str_repeat('PRIVATE_RAW_RESPONSE_',55000);
$badEnvelope=$envelope;$badEnvelope['status']='incomplete';$badEnvelope['PRIVATE_SECRET']='PRIVATE_RESPONSE_TOKEN';
$badResponses['incomplete']=$response;$badResponses['incomplete']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['output'][0]['role']='user';
$badResponses['wrong-role']=$response;$badResponses['wrong-role']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['output'][0]['content'][]=$badEnvelope['output'][0]['content'][0];
$badResponses['multiple-texts']=$response;$badResponses['multiple-texts']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['output'][0]['content']=[['type'=>'refusal','refusal'=>'PRIVATE_REFUSAL']];
$badResponses['refusal']=$response;$badResponses['refusal']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
$badEnvelope=$envelope;$badEnvelope['error']=['message'=>'PRIVATE_PROVIDER_ERROR'];
$badResponses['provider-error']=$response;$badResponses['provider-error']['body']=json_encode($badEnvelope,JSON_THROW_ON_ERROR);
foreach ($badResponses as $label=>$badResponse) {
    $run=referenceEquivalence($rows,$badResponse,$label);
    referenceExpect($run['result']['ok'],false,$label.' preserves original fail-closed provider rejection');
    referenceExpect($run['metadata'],['status'=>'NOT_OBSERVED','subreason'=>'RESPONSE_NOT_INSPECTABLE','field'=>null,'item_ordinal'=>null],
        $label.' never exposes or interprets a rejected provider response');
}
$zeroCalls=0;$disabled=Observer::extract($rows,['enabled'=>false],static function () use (&$zeroCalls): array {$zeroCalls++;throw new RuntimeException('PRIVATE_UNREACHABLE');});
referenceExpect($zeroCalls,0,'disabled provider cannot invoke even fake transport');
referenceExpect($disabled['result']['reason'],'DISABLED','disabled observer preserves original provider reason');
referenceMetadata($disabled['metadata']);
referenceExpect($disabled['metadata'],['status'=>'NOT_OBSERVED','subreason'=>'NO_PROVIDER_CALL','field'=>null,'item_ordinal'=>null],
    'absence of provider invocation is explicit without secret state');
foreach ([null,[],['status'=>'PRIVATE_STATUS','subreason'=>'PRIVATE_REASON','field'=>'PRIVATE_FIELD','item_ordinal'=>'PRIVATE_ID'],
    ['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'SOURCE_ROW_MISSING','field'=>'ITEM_NAME','item_ordinal'=>0],
    ['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'SOURCE_ROW_MISSING','field'=>'ITEM_NAME','item_ordinal'=>31],
    ['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'SOURCE_ROW_MISSING','field'=>'ITEM_NAME','item_ordinal'=>'1'],
    ['status'=>'SOURCE_REFERENCE_FAILED','subreason'=>'SOURCE_ROW_MISSING','field'=>'ITEM_NAME','item_ordinal'=>1,'PRIVATE_EXTRA'=>'PRIVATE_VALUE']] as $raw) {
    $sanitized=Observer::sanitize($raw);referenceMetadata($sanitized);
    referenceExpect($sanitized,['status'=>'NOT_OBSERVED','subreason'=>'UNKNOWN_METADATA','field'=>null,'item_ordinal'=>null],
        'malformed metadata is discarded without copying arbitrary data');
}

// The guarded probe wrapper exposes metadata only beside the same stable validated result.
$workflow=(new ReflectionClass(\App\Services\Dashboard\WhatsAppOrderWorkflow::class))->newInstanceWithoutConstructor();
$failureMethod=new ReflectionMethod($workflow,'safeAnalysisFailure');$failureMethod->setAccessible(true);
$safe=static fn ($result): array=>$failureMethod->invoke($workflow,$result);
$state=['configured'=>true,'floor'=>4016,'ceiling'=>5000,'configuration_fingerprint'=>str_repeat('a',64),
    'snapshot'=>['complete'=>true,'hash'=>str_repeat('b',64),'transcript'=>$rows,'keys'=>['m1'=>'PRIVATE_NATIVE_KEY']],
    'status_event_7215'=>['status'=>'READY','scope_matches'=>true,'statuses_count'=>1,'truncated'=>false,
        'statuses'=>[['status_type'=>'STRING','status'=>'played','id_valid'=>true]]]];
function referenceProbe(array $state,callable $safe,array $observed,bool $authorized=true,?callable $change=null): array
{
    $reads=0;$calls=0;
    $report=Probe::runObserved($authorized,static function () use ($state,&$reads,$change): array {
        $reads++;return $change===null?$state:$change($state,$reads);
    },static function () use ($observed,&$calls): array {$calls++;return $observed;},$safe);
    return ['report'=>$report,'reads'=>$reads,'calls'=>$calls];
}
$run=referenceProbe($state,$safe,$good);$report=$run['report'];
referenceExpect([$run['reads'],$run['calls']],[3,1],'stable observed probe performs bounded rechecks and one extraction');
referenceExpect($report['output']['status'],'AI_EXTRACTION_READY','guarded observed probe keeps validated extraction status');
referenceExpect($report['output']['source_reference'],$good['metadata'],'guarded stable probe exposes only matching sanitized source metadata');
referenceExpect([$report['output']['writes'],$report['output']['ai_calls'],$report['output']['snapshot_current']],[0,1,true],'stable probe accounting remains read-only and single-call');
$direct=Probe::run(true,static fn ()=>$state,static fn ()=>$good['result'],$safe);
$withoutMetadata=$report;unset($withoutMetadata['output']['source_reference']);
referenceExpect($withoutMetadata,$direct,'runObserved returns the identical guarded report plus source metadata only');
$publicMetadata[]=$report['output'];
$missing=$wire;$missing['evidence']['request_ids']=array_values(array_diff($missing['evidence']['request_ids'],['m1']));
$missingObserved=referenceEquivalence($rows,referenceResponse($missing),'context citation probe fixture');
$failed=referenceProbe($state,$safe,$missingObserved);
referenceExpect($failed['report']['output']['analysis_failure']['diagnostic']['validation_field'],'ITEM_NAME','actual citation failure keeps original diagnostic field');
referenceExpect($failed['report']['output']['source_reference']['subreason'],'CONTEXT_NAME_UNCITED','stable original source failure exposes its matching bounded subreason');
$publicMetadata[]=$failed['report']['output'];
$unknown=Observer::sanitize(null);
foreach (['unauthorized','incomplete','precall-changed','postcall-changed','postcall-floor','postcall-configuration','postcall-snapshot','postcall-exception'] as $case) {
    $testState=$state;$authorized=$case!=='unauthorized';$change=null;
    if ($case==='incomplete') $testState['snapshot']['complete']=false;
    if ($case==='precall-changed') $change=static function ($value,$read) {if ($read===2)$value['ceiling']++;return $value;};
    if ($case==='postcall-changed') $change=static function ($value,$read) {if ($read===3)$value['ceiling']++;return $value;};
    if ($case==='postcall-floor') $change=static function ($value,$read) {if ($read===3)$value['floor']++;return $value;};
    if ($case==='postcall-configuration') $change=static function ($value,$read) {if ($read===3)$value['configuration_fingerprint']=str_repeat('c',64);return $value;};
    if ($case==='postcall-snapshot') $change=static function ($value,$read) {if ($read===3)$value['snapshot']['hash']=str_repeat('d',64);return $value;};
    if ($case==='postcall-exception') $change=static function ($value,$read) {if ($read===3)throw new RuntimeException('PRIVATE_POSTCALL_ERROR');return $value;};
    $stopped=referenceProbe($testState,$safe,$missingObserved,$authorized,$change);
    referenceExpect($stopped['report']['output']['source_reference'],$unknown,$case.' exposes no source metadata from unqualified or stale state');
    referenceExpect($stopped['calls'],str_starts_with($case,'postcall-')?1:0,$case.' makes no retry or unauthorized extraction');
    referenceExpect(isset($stopped['report']['output']['extraction']),false,$case.' exposes no discarded extraction');
    $publicMetadata[]=$stopped['report']['output'];
}
foreach (['wrong-field','ready-on-source-failure','malicious-metadata','malformed-result'] as $case) {
    $fake=$missingObserved;
    if ($case==='wrong-field') $fake['metadata']['field']='ITEM_OPTION';
    if ($case==='ready-on-source-failure') $fake['metadata']=$good['metadata'];
    if ($case==='malicious-metadata') $fake['metadata']=['PRIVATE_SECRET'=>'PRIVATE_RAW'];
    if ($case==='malformed-result') $fake['result']='PRIVATE_RESPONSE';
    $sanitized=referenceProbe($state,$safe,$fake);
    referenceExpect($sanitized['calls'],1,$case.' does not retry extraction');
    referenceExpect($sanitized['report']['output']['source_reference'],$unknown,$case.' cannot expose mismatched or arbitrary source metadata');
    $publicMetadata[]=$sanitized['report']['output'];
}
$callbackCalls=0;$exception=Probe::runObserved(true,static fn ()=>$state,static function () use (&$callbackCalls): array {
    $callbackCalls++;throw new RuntimeException('PRIVATE_CALLBACK_TOKEN');
},$safe);
referenceExpect($callbackCalls,1,'throwing observed callback makes exactly one attempt');
referenceExpect($exception['output']['source_reference'],$unknown,'callback exception copies no raw response or error message');
$publicMetadata[]=$exception['output'];
$semanticProbe=referenceProbe($state,$safe,$semanticRun);
referenceExpect($semanticProbe['report']['output']['source_reference'],$semanticRun['metadata'],'stable semantic rejection may expose only independently matched source-ready detail');
referenceExpect($semanticProbe['report']['output']['status'],'AI_EXTRACTION_FAILED','source-ready diagnostics never override semantic extraction failure');
$publicMetadata[]=$semanticProbe['report']['output'];
$transportResult=referenceObserved($rows,$badResponses['non-200'])['observed'];
$transportProbe=referenceProbe($state,$safe,$transportResult);
referenceExpect($transportProbe['report']['output']['source_reference'],$unknown,'transport rejection exposes no unqualified source diagnostics');
referenceExpect($transportProbe['calls'],1,'transport rejection never triggers another attempt');
$publicMetadata[]=$transportProbe['report']['output'];
referenceExpect(Probe::authorized(['probe.php','--probe-one']),true,'exact probe-one authorization remains required');
foreach ([['probe.php'],['probe.php','--probe-one','--probe-one'],['probe.php','--probe-one=1'],['probe.php','--conversation=12']] as $arguments)
    referenceExpect(Probe::authorized($arguments),false,'alternate flags never authorize a provider request');

foreach (Probe::SOURCE_PINS as [$path,$expected]) {
    $sourcePath=in_array($path,['app/Services/Dashboard/WhatsAppOrderSourceSelection.php',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php'],true)
        ? 'tests/fixtures/whatsapp_order_pre_anchor_citation/'.$path : $path;
    $bytes=file_get_contents(dirname(__DIR__).'/'.$sourcePath);
    referenceExpect(sha1('blob '.strlen($bytes)."\0".$bytes),$expected,'guard matches the exact unchanged reviewed application source');
}
$observerBytes=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_order_source_reference_observer.php');
referenceExpect(sha1('blob '.strlen($observerBytes)."\0".$observerBytes),Probe::OBSERVER_BLOB,'guard matches exact reviewed observer bytes');
$probeBytes=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_order_source_reference_read_only_probe.php');
referenceExpect(preg_match('/(?:->|::)(?:consume|process|projectPending|projectAcceptedReply|analyze|dispatch|insert|update|delete|save)\s*\(/',$probeBytes),0,
    'guarded source diagnostic has no write or order-processing call sites');
referenceExpect(str_contains($probeBytes,'->lockForUpdate('),false,'guarded source diagnostic acquires no write locks');
referenceExpect(substr_count($probeBytes,'WhatsAppOrderSourceReferenceObserver::extract('),1,'production guarded observer has exactly one extraction call site');
referenceExpect(str_contains($probeBytes,'$snapshot->invoke($workflow, $cid, $floor, $ceiling, false)'),true,'snapshot retains explicit read-only invocation');
referenceExpect(str_contains($probeBytes,'->where(\'id\', 7215)'),true,'status observation remains fixed to the requested event');
referenceExpect(str_contains($observerBytes,'->extract($rows)'),true,'observer invokes the actual pinned provider');

$serialized=json_encode($publicMetadata,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach (['فسيخ','رنجه','كامل','عميل تجريبي','01000000001','مدينة التجربة','PRIVATE_','m1','m2','m3','first_token','last_token','name_source','option_source'] as $private)
    referenceExpect(str_contains($serialized,$private),false,'public metadata omits synthetic private marker');
echo 'WHATSAPP_ORDER_SOURCE_REFERENCE_READ_ONLY_PROBE_CHECKS='.$checks."\n";
