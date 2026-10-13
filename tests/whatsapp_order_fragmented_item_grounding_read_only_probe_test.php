<?php
// Current fragmented helper/provider/validator, isolated fake HTTP: no bootstrap, DB or network.
define('WHATSAPP_ORDER_FRAGMENTED_ITEM_GROUNDING_READ_ONLY_PROBE_TEST_ONLY', true);
$probeFile = dirname(__DIR__) . '/deployment/whatsapp_order_fragmented_item_grounding_read_only_probe.php';
require $probeFile;
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderWorkflow.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
use WhatsAppOrderFragmentedItemGroundingReadOnlyProbe as P;
use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Services\Dashboard\WhatsAppOrderWorkflow as Workflow;
use App\Support\WhatsAppOrderExtraction as Extraction;
use App\Services\Dashboard\WhatsAppOrderConversationEvidence as Evidence;
$checks = 0; $observations = [];
function itemProbeExpect($actual, $expected, string $label): void {
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function itemProbeRows(): array {
    return [
        ['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-13 12:00:01','text'=>'مرحبا','location'=>null],
        ['id'=>'m2','speaker'=>'business','sent_at'=>'2026-10-13 12:00:02','text'=>'أهلا بحضرتك','location'=>null],
        ['id'=>'m3','speaker'=>'customer','sent_at'=>'2026-10-13 12:00:03','text'=>'عايز 1 كيلو فسيخ نبروه','location'=>null],
        ['id'=>'m4','speaker'=>'business','sent_at'=>'2026-10-13 12:00:04','text'=>'تفاصيل الطلب: 1 كيلو فسيخ نبروه','location'=>null],
        ['id'=>'m5','speaker'=>'customer','sent_at'=>'2026-10-13 12:00:05','text'=>'تمام موافق','location'=>null],
    ];
}
function itemProbeData(): array {
    return ['decision'=>'DRAFT','customer'=>['name'=>null,'phone'=>null,'address'=>null,'area'=>null,'notes'=>null],
        'branch_hint'=>null,'fulfillment'=>'UNKNOWN',
        'items'=>[['name'=>'فسيخ نبروه','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null,'cart_reference'=>null,'conversation_reference'=>null]],
        'approximate_total'=>null,'evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m2'],
            'customer_acceptance_ids'=>['m5'],'location_id'=>null],'issues'=>['UNCONFIRMED']];
}
function itemProbeConfiguration(): array {
    return ['enabled'=>true,'mode'=>'auto','model'=>'gpt-6-luna','api_key'=>'sk-item-probe-private-fixture-key'];
}
function itemProbeState(?array $rows = null): array {
    return ['configured'=>true,'floor'=>4016,'ceiling'=>5500,'configuration_fingerprint'=>str_repeat('a',64),
        'snapshot'=>['complete'=>true,'hash'=>str_repeat('b',64),'transcript'=>$rows ?? itemProbeRows(),
            'keys'=>['m1'=>'SECRET_NATIVE_KEY']],
        'status_event_7215'=>['status'=>'READY','scope_matches'=>true,'statuses_count'=>1,'truncated'=>false,
            'statuses'=>[['status_type'=>'STRING','status'=>'played','id_valid'=>true]]]];
}
function itemProbeResponse(array $data): array {
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant',
        'content'=>[['type'=>'output_text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]]],JSON_THROW_ON_ERROR),
        'curl_errno'=>0,'elapsed_ms'=>17];
}
function itemProbeTransport(array $response, int &$calls, ?array &$seen = null): callable {
    return static function ($body, $key) use ($response, &$calls, &$seen): array {
        $calls++; $seen = ['body'=>$body,'key'=>$key];
        itemProbeExpect($key,'sk-item-probe-private-fixture-key','only fake transport receives the private fixture key');
        $input=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        itemProbeExpect([$input['store'],$input['stream']],[false,false],'provider privacy/request flags unchanged');
        itemProbeExpect($input['text']['format']['strict'],true,'current strict JSON schema used');
        return $response;
    };
}
function itemProbeMetadata(array $booleans, int $index = 1, array $extra = []): array {
    $flags=[
        'literal_match_cited_request','literal_match_cited_confirmation','literal_match_acceptance_only',
        'literal_match_uncited_customer','literal_match_uncited_business','literal_match_any_transcript',
        'arabic_folded_any_transcript','arabic_folded_cited_request','arabic_folded_cited_confirmation',
        'arabic_folded_uncited_customer','arabic_folded_uncited_business','context_reference_present',
        'context_anchor_exists','context_anchor_customer','context_anchor_request_cited','literal_match_context_anchor',
        'arabic_folded_context_anchor','all_name_tokens_in_cited','all_name_tokens_in_context_anchor',
        'name_includes_known_family','name_includes_unit','name_includes_whole','name_includes_size_count',
    ];
    $out=['index'=>$index];
    foreach($flags as$position=>$flag)$out[$flag]=$booleans[$position]??false;
    $out['name_includes_known_family']=true;
    foreach($extra as$flag=>$value) {
        if(!in_array($flag,$flags,true)||!is_bool($value))throw new RuntimeException('Bad fixed fixture flag');
        $out[$flag]=$value;
    }
    return $out;
}
function itemProbeEmpty(): array { return ['status'=>'NOT_APPLICABLE','items_count'=>0,'items'=>[]]; }
function itemProbeAssertMetadata(array $metadata, string $label): void {
    itemProbeExpect(array_keys($metadata),['status','items_count','items'],$label.' exact top-level metadata keys');
    itemProbeExpect(in_array($metadata['status'],['NOT_APPLICABLE','ITEM_NAME_GROUNDING'],true),true,$label.' fixed status');
    itemProbeExpect(is_int($metadata['items_count']) && $metadata['items_count']>=0 && $metadata['items_count']<=30,true,$label.' bounded count');
    itemProbeExpect(array_is_list($metadata['items']) && count($metadata['items'])===$metadata['items_count'],true,$label.' bounded exact list');
    if ($metadata['status']==='NOT_APPLICABLE') itemProbeExpect($metadata,itemProbeEmpty(),$label.' no spurious metadata');
    foreach($metadata['items']as$index=>$item) {
        itemProbeExpect(array_keys($item),array_keys(itemProbeMetadata(array_fill(0,6,false))),$label.' exact per-item keys');
        itemProbeExpect($item['index'],$index+1,$label.' consecutive numeric index');
        foreach(array_slice($item,1)as$key=>$value) itemProbeExpect(is_bool($value),true,$label.' boolean '.$key);
    }
}
$workflow=(new ReflectionClass(Workflow::class))->newInstanceWithoutConstructor();
$safeMethod=new ReflectionMethod(Workflow::class,'safeAnalysisFailure');$safeMethod->setAccessible(true);
$safe=static fn($result):array=>$safeMethod->invoke($workflow,$result);

// Observing the private response must not replace, repair, recite or retry the validator's result.
$rows=itemProbeRows();$data=itemProbeData();$response=itemProbeResponse($data);
$calls=0;$seen=null;
$observed=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport($response,$calls,$seen));
$directCalls=0;$directSeen=null;
$direct=(new Provider(itemProbeTransport($response,$directCalls,$directSeen),itemProbeConfiguration()))->extract($rows);
itemProbeExpect(array_keys($observed),['result','item_grounding'],'observer exposes only result and fixed morphology');
itemProbeExpect(P::ITEM_FLAGS,array_slice(array_keys(itemProbeMetadata([])),1),'exact independent 23-flag contract');
itemProbeExpect($observed['result'],$direct,'real provider result returned unchanged');
itemProbeExpect($seen,$directSeen,'observer leaves private provider request unchanged');
itemProbeExpect([$calls,$directCalls],[1,1],'one request per explicit independent invocation');
itemProbeExpect($observed['result']['diagnostic']['validation_field'],'ITEM_NAME','fixture reaches actual early item-name rejection');
itemProbeExpect($observed['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
    'items'=>[itemProbeMetadata([false,false,false,true,true,true])]],'omitted product citations identified without reciting them');
$observations[]=$observed['item_grounding'];

// JSON serialization may drop an integral float's .0; observed input must still bind exact provider bytes.
foreach([[30.0,31.0],[30.125,31.375]]as$coordinates) {
    $rows=itemProbeRows();$rows[0]['location']=['lat'=>$coordinates[0],'long'=>$coordinates[1]];
    $calls=0;$seen=null;$response=itemProbeResponse(itemProbeData());
    $located=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport($response,$calls,$seen));
    $directCalls=0;$directSeen=null;$direct=(new Provider(itemProbeTransport($response,$directCalls,$directSeen),itemProbeConfiguration()))->extract($rows);
    itemProbeExpect([$calls,$directCalls],[1,1],'float-location fixture one request per independent extraction');
    itemProbeExpect($located['result'],$direct,'float-location observation preserves current provider failure');
    itemProbeExpect($seen,$directSeen,'float-location observer preserves exact provider input bytes');
    itemProbeExpect($located['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
        'items'=>[itemProbeMetadata([false,false,false,true,true,true])]],'float serialization cannot suppress correctly scoped morphology');
    $observations[]=$located['item_grounding'];
}

// Separate scopes distinguish omitted citations from acceptance-only, paraphrase and spelling changes.
foreach(['omitted-customer','uncited-business','acceptance-only','paraphrase','arabic-spelling','renga-spelling',
    'arabic-diacritics','arabic-tatweel','arabic-hamza','arabic-alif-maqsura','disjoint-composite','case-insensitive']as$case) {
    $rows=itemProbeRows();$data=itemProbeData();$expected=array_fill(0,6,false);$extra=[];
    if($case==='omitted-customer') {$rows[3]['text']='تفاصيل الطلب محفوظة';$expected=[false,false,false,true,false,true];}
    if($case==='uncited-business') {$rows[2]['text']='أحتاج الطلب';$expected=[false,false,false,false,true,true];}
    if($case==='acceptance-only') {$rows[2]['text']='أحتاج الطلب';$rows[3]['text']='تفاصيل الطلب محفوظة';
        $rows[4]['text']='موافق على 1 كيلو فسيخ نبروه';$expected=[false,false,true,false,false,true];}
    if($case==='paraphrase') $data['items'][0]['name']='فسيخ مملح فاخر';
    if($case==='arabic-spelling') {$data['items'][0]['name']='فسيخ نبروة';$expected[6]=true;}
    if($case==='renga-spelling') {$data['items'][0]['name']='رنجة';$rows[2]['text']='عايز 1 كيلو رنجه';
        $rows[3]['text']='تفاصيل الطلب محفوظة';$expected[6]=true;}
    if($case==='arabic-diacritics') {$data['items'][0]['name']='فَسيخ نبروه';$expected[6]=true;}
    if($case==='arabic-tatweel') {$data['items'][0]['name']='فسـيخ نبروه';$expected[6]=true;}
    if($case==='arabic-hamza') {$data['items'][0]['name']='آيس';$rows[2]['text']='عايز ايس';
        $rows[3]['text']='تفاصيل الطلب محفوظة';$expected[6]=true;}
    if($case==='arabic-alif-maqsura') {$data['items'][0]['name']='بلطى';$rows[2]['text']='عايز بلطي';
        $rows[3]['text']='تفاصيل الطلب محفوظة';$expected[6]=true;}
    if($case==='disjoint-composite') {$rows[2]['text']='عايز فسيخ';$rows[3]['text']='الصنف من نبروه';}
    if($case==='case-insensitive') {$data['items'][0]['name']='Herring';$rows[2]['text']='1 kilo HERRING';
        $rows[3]['text']='details saved';$expected=[false,false,false,true,false,true];}
    if(in_array($case,['arabic-spelling','arabic-diacritics','arabic-tatweel'],true))$extra=['arabic_folded_uncited_customer'=>true,'arabic_folded_uncited_business'=>true];
    if(in_array($case,['renga-spelling','arabic-hamza','arabic-alif-maqsura'],true))$extra=['arabic_folded_uncited_customer'=>true];
    if(in_array($case,['arabic-hamza','arabic-alif-maqsura','arabic-diacritics','arabic-tatweel'],true))$extra['name_includes_known_family']=false;
    $calls=0;$response=itemProbeResponse($data);
    $observed=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport($response,$calls));
    itemProbeExpect($calls,1,$case.' exactly one real provider request');
    itemProbeExpect($observed['result']['diagnostic']['validation_category'],'LITERAL_NOT_CITED',$case.' authoritative grounding still rejects');
    itemProbeExpect($observed['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
        'items'=>[itemProbeMetadata($expected,1,$extra)]],$case.' exact scope morphology');
    itemProbeAssertMetadata($observed['item_grounding'],$case);
    $observations[]=$observed['item_grounding'];
}

// Context classifications inspect the supplied typed reference and exact anchor only; they never repair it.
function itemProbeReference(string $anchor='m3'): array {
    return ['name_anchor_id'=>$anchor,'business_offer_id'=>'m4','customer_quantity_id'=>'m5',
        'business_summary_id'=>'m2','summary_clause_index'=>0,'customer_option_id'=>null];
}
foreach(['uncited-anchor','folded-cited-anchor','tokens-across-citations','tokens-in-anchor',
    'unit-weight','unit-piece','whole-arabic','whole-english','size-arabic','size-english',
    'no-family','family-sardine']as$case) {
    $rows=itemProbeRows();$data=itemProbeData();$flags=array_fill(0,7,false);$extra=[];
    $data['items'][0]['conversation_reference']=itemProbeReference();
    $extra=['context_reference_present'=>true,'context_anchor_exists'=>true,'context_anchor_customer'=>true];
    if($case==='uncited-anchor') {$flags=[false,false,false,true,true,true];
        $extra+=['literal_match_context_anchor'=>true,'all_name_tokens_in_context_anchor'=>true];}
    if($case==='folded-cited-anchor') {$data['items'][0]['name']='فسيخ نبروة';$data['evidence']['request_ids'][]='m3';
        $flags[6]=true;$extra+=['context_anchor_request_cited'=>true,'arabic_folded_cited_request'=>true,
            'arabic_folded_uncited_business'=>true,'arabic_folded_context_anchor'=>true];}
    if($case==='tokens-across-citations') {$data['items'][0]['name']='فسيخ كامل';$data['evidence']['request_ids'][]='m3';
        $rows[1]['text']='التجهيز كامل';$rows[3]['text']='تفاصيل محفوظة';
        $extra+=['context_anchor_request_cited'=>true,'all_name_tokens_in_cited'=>true,'name_includes_whole'=>true];}
    if($case==='tokens-in-anchor') {$data['items'][0]['name']='فسيخ كامل';$rows[2]['text']='فسيخ رائع كامل';
        $rows[3]['text']='تفاصيل محفوظة';$extra+=['all_name_tokens_in_context_anchor'=>true,'name_includes_whole'=>true];}
    if($case==='unit-weight') {$data['items'][0]['name']='فسيخ كيلو';$extra+=['name_includes_unit'=>true,'all_name_tokens_in_context_anchor'=>true];}
    if($case==='unit-piece') {$data['items'][0]['name']='فسيخ حبة';$extra+=['name_includes_unit'=>true];}
    if($case==='whole-arabic') {$data['items'][0]['name']='فسيخ كامل';$extra+=['name_includes_whole'=>true];}
    if($case==='whole-english') {$data['items'][0]['name']='whole herring';$extra+=['name_includes_whole'=>true];}
    if($case==='size-arabic') {$data['items'][0]['name']='فسيخ من الكيلو 3 حبه';$extra+=['name_includes_unit'=>true,'name_includes_size_count'=>true];}
    if($case==='size-english') {$data['items'][0]['name']='herring 3 fish per kilo';$extra+=['name_includes_unit'=>true,'name_includes_size_count'=>true];}
    if($case==='no-family') {$data['items'][0]['name']='طبق غير موجود';$extra+=['name_includes_known_family'=>false];}
    if($case==='family-sardine')$data['items'][0]['name']='سردين';
    $calls=0;$seen=null;$response=itemProbeResponse($data);
    $classified=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport($response,$calls,$seen));
    $directCalls=0;$directSeen=null;$original=(new Provider(itemProbeTransport($response,$directCalls,$directSeen),itemProbeConfiguration()))->extract($rows);
    itemProbeExpect($classified['result'],$original,$case.' diagnostic leaves current provider failure unchanged');
    itemProbeExpect($seen,$directSeen,$case.' diagnostic leaves exact request unchanged');
    itemProbeExpect([$calls,$directCalls],[1,1],$case.' one request per independent fixture');
    itemProbeExpect([$classified['result']['reason'],$classified['result']['diagnostic']['validation_category'],
        $classified['result']['diagnostic']['validation_field']],['INVALID_EVIDENCE','LITERAL_NOT_CITED','ITEM_NAME'],
        $case.' only exact item-name failure activates metadata');
    itemProbeExpect($classified['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
        'items'=>[itemProbeMetadata($flags,1,$extra)]],$case.' fixed diagnostic facts');
    itemProbeAssertMetadata($classified['item_grounding'],$case);
    $observations[]=$classified['item_grounding'];
}

// Full-phrase folded matches are reported independently for each scope, never per-token or as evidence.
foreach(['request','confirmation','uncited-customer','uncited-business','literal-preempts-fold',
    'token-only-fold','punctuation-not-folded','unrelated-lookalike']as$case) {
    $rows=itemProbeRows();$data=itemProbeData();$data['items'][0]['name']='فسيخ نبروة';
    $flags=array_fill(0,7,false);$extra=[];$rows[2]['text']='تفاصيل محفوظة';$rows[3]['text']='تفاصيل محفوظة';
    if($case==='request') {$rows[0]['text']='فسيخ نبروه';$flags[6]=true;$extra['arabic_folded_cited_request']=true;}
    if($case==='confirmation') {$rows[1]['text']='فسيخ نبروه';$flags[6]=true;$extra['arabic_folded_cited_confirmation']=true;}
    if($case==='uncited-customer') {$rows[2]['text']='فسيخ نبروه';$flags[6]=true;$extra['arabic_folded_uncited_customer']=true;}
    if($case==='uncited-business') {$rows[3]['text']='فسيخ نبروه';$flags[6]=true;$extra['arabic_folded_uncited_business']=true;}
    if($case==='literal-preempts-fold') {$rows[2]['text']='فسيخ نبروة';$flags=[false,false,false,true,false,true];}
    if($case==='token-only-fold') {$rows[0]['text']='فسيخ';$rows[1]['text']='نبروه';}
    if($case==='punctuation-not-folded')$rows[2]['text']='فسيخ، نبروه';
    if($case==='unrelated-lookalike')$rows[2]['text']='فسيخ نبراو';
    $calls=0;$classified=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($data),$calls));
    itemProbeExpect($calls,1,$case.' folded diagnostic performs no retry');
    itemProbeExpect($classified['result']['ok'],false,$case.' fold never changes authoritative rejection');
    itemProbeExpect($classified['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
        'items'=>[itemProbeMetadata($flags,1,$extra)]],$case.' whole-phrase scoped folding only');
    itemProbeAssertMetadata($classified['item_grounding'],$case);
    $observations[]=$classified['item_grounding'];
}

// The current validator normalizes whitespace and letter case, but never Arabic spelling/paraphrases.
foreach(['cited-request','cited-summary','whitespace']as$case) {
    $rows=itemProbeRows();$data=itemProbeData();
    if($case==='cited-request')$data['evidence']['request_ids'][]='m3';
    if($case==='cited-summary')$data['evidence']['confirmation_ids'][]='m4';
    if($case==='whitespace') {$data['evidence']['request_ids'][]='m3';$data['items'][0]['name']="فسيخ\t نبروه";
        $rows[2]['text']="عايز 1 كيلو فسيخ\n  نبروه";}
    $calls=0;$observed=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($data),$calls));
    itemProbeExpect($calls,1,$case.' one request');
    itemProbeExpect($observed['result']['ok'],true,$case.' existing literal semantics preserved');
    itemProbeExpect($observed['item_grounding'],itemProbeEmpty(),$case.' failure-only observer stays inactive');
    $observations[]=$observed['item_grounding'];
}

// All items are bounded and evaluated independently; no early failed item hides another matching scope.
$rows=itemProbeRows();$data=itemProbeData();
$data['items'][]=['name'=>'رنجة','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null,'cart_reference'=>null,'conversation_reference'=>null];
$rows[3]['text']='تفاصيل محفوظة';$rows[4]['text']='موافق على رنجة';
$calls=0;$multi=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($data),$calls));
itemProbeExpect($multi['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>2,'items'=>[
    itemProbeMetadata([false,false,false,true,false,true]),itemProbeMetadata([false,false,true,false,false,true],2)]],
    'each item has independent non-overlapping citation scopes');
$observations[]=$multi['item_grounding'];
$data['items']=array_fill(0,30,$data['items'][0]);$calls=0;
$bounded=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($data),$calls));
itemProbeExpect($bounded['item_grounding']['items_count'],30,'maximum schema item count remains supported');
itemProbeAssertMetadata($bounded['item_grounding'],'max-items');
$observations[]=$bounded['item_grounding'];

// Transport and response defects are ignored by the item observer, while the real provider fails closed.
$message=['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode(itemProbeData(),JSON_THROW_ON_ERROR)]]];
$cases=[
    'malformed-json'=>['status'=>200,'body'=>'SECRET_MALFORMED_RESPONSE'],
    'oversized-response'=>['status'=>200,'body'=>str_repeat('SECRET_RESPONSE_',75000)],
    'http-error'=>['status'=>429,'body'=>'SECRET_HTTP_ERROR'],
    'refusal'=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant',
        'content'=>[['type'=>'refusal','refusal'=>'SECRET_REFUSAL']]]]],JSON_THROW_ON_ERROR)],
    'multi-output'=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[$message,$message]],JSON_THROW_ON_ERROR)],
    'oversized-output'=>['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant',
        'content'=>[['type'=>'output_text','text'=>str_repeat('SECRET_OUTPUT_',3000)]]]]],JSON_THROW_ON_ERROR)],
    'incomplete'=>['status'=>200,'body'=>json_encode(['status'=>'incomplete','output'=>[$message]],JSON_THROW_ON_ERROR)],
    'malformed-extraction'=>itemProbeResponse(['items'=>[['name'=>'SECRET_BAD_ITEM']]]),
];
$bad=itemProbeData();$bad['items']=array_fill(0,31,$bad['items'][0]);$cases['too-many-items']=itemProbeResponse($bad);
$bad=itemProbeData();$bad['items'][0]['name']=str_repeat('X',201);$cases['oversized-item']=itemProbeResponse($bad);
$bad=itemProbeData();$bad['evidence']['request_ids'][]='m60';$cases['nonexistent-citation']=itemProbeResponse($bad);
$bad=itemProbeData();$bad['evidence']['request_ids'][]='m4';$cases['wrong-speaker']=itemProbeResponse($bad);
$bad=itemProbeData();$bad['customer']['name']='SECRET_UNGROUNDED_CUSTOMER';$cases['earlier-field-failure']=itemProbeResponse($bad);
foreach($cases as$case=>$response) {
    $calls=0;$observed=P::extractObserved(itemProbeRows(),itemProbeConfiguration(),itemProbeTransport($response,$calls));
    itemProbeExpect($calls,1,$case.' no retry');
    itemProbeExpect($observed['result']['ok'],false,$case.' real provider rejects');
    itemProbeExpect($observed['item_grounding'],itemProbeEmpty(),$case.' unavailable raw extraction not interpreted');
    $observations[]=['result'=>$safe($observed['result']),'item_grounding'=>$observed['item_grounding']];
}
foreach(['bad-time','probe-marker','operational-test','non-list','too-large-transcript','too-many-rows']as$case) {
    $rows=itemProbeRows();
    if($case==='bad-time')$rows[0]['sent_at']='SECRET_TIME';
    if($case==='probe-marker')$rows[0]['text'].=' WA-1234';
    if($case==='operational-test')$rows[0]['text']='هذا الطلب اختبار لا ترسله';
    if($case==='non-list')$rows=array_column($rows,null,'id');
    if($case==='too-large-transcript')$rows[0]['text']=str_repeat('X',17000);
    if($case==='too-many-rows')$rows=array_fill(0,61,$rows[0]);
    $calls=0;$thrown=false;
    try {P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse(itemProbeData()),$calls));}
    catch(InvalidArgumentException $error) {$thrown=true;}
    itemProbeExpect($thrown,true,$case.' observer rejects unqualified input');
    itemProbeExpect($calls,0,$case.' cannot trigger private HTTP');
}

// Invalid typed references never reach item morphology: current validation remains authoritative.
foreach(['missing-anchor','business-anchor','malformed-label','non-object','missing-key','extra-key',
    'string-clause','negative-clause','cart-conflict']as$case) {
    $rows=itemProbeRows();$data=itemProbeData();$data['items'][0]['conversation_reference']=itemProbeReference();
    if($case==='missing-anchor')$data['items'][0]['conversation_reference']['name_anchor_id']='m60';
    if($case==='business-anchor')$data['items'][0]['conversation_reference']['name_anchor_id']='m4';
    if($case==='malformed-label')$data['items'][0]['conversation_reference']['name_anchor_id']='SECRET_ANCHOR';
    if($case==='non-object')$data['items'][0]['conversation_reference']='SECRET_REFERENCE';
    if($case==='missing-key')unset($data['items'][0]['conversation_reference']['customer_option_id']);
    if($case==='extra-key')$data['items'][0]['conversation_reference']['secret']='SECRET_REFERENCE';
    if($case==='string-clause')$data['items'][0]['conversation_reference']['summary_clause_index']='0';
    if($case==='negative-clause')$data['items'][0]['conversation_reference']['summary_clause_index']=-1;
    if($case==='cart-conflict')$data['items'][0]['cart_reference']=['message_id'=>'m3','product_retailer_id'=>'SECRET_RETAILER'];
    $calls=0;$result=P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($data),$calls));
    itemProbeExpect($calls,1,$case.' reference check performs one unchanged HTTP invocation');
    itemProbeExpect($result['result']['ok'],false,$case.' invalid contextual evidence rejected');
    itemProbeExpect($result['item_grounding'],itemProbeEmpty(),$case.' no diagnostic over an invalid anchor/reference');
    $observations[]=['result'=>$safe($result['result']),'item_grounding'=>$result['item_grounding']];
}

// Observation sees the exact provider episode; uncited pre-test product history must not match.
$epochRows=[
    ['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-13 11:00:01','text'=>'فسيخ نبروه SECRET_OLD_ORDER','location'=>null],
    ['id'=>'m2','speaker'=>'customer','sent_at'=>'2026-10-13 11:00:02','text'=>'هذا الطلب اختبار لا ترسله','location'=>null],
    ['id'=>'m3','speaker'=>'customer','sent_at'=>'2026-10-13 11:00:03','text'=>'عايز طلب جديد كيلو رنجه','location'=>null],
];
foreach(itemProbeRows()as$position=>$row) {
    $row['id']='m'.($position+4);
    if($position===2)$row['text']='تفاصيل جديدة';
    if($position===3)$row['text']='تفاصيل محفوظة';
    $epochRows[]=$row;
}
$epochData=itemProbeData();$epochData['evidence']['request_ids']=['m4'];
$epochData['evidence']['confirmation_ids']=['m5'];$epochData['evidence']['customer_acceptance_ids']=['m8'];
itemProbeExpect(Extraction::hasProbe($epochRows),false,'explicit later new order establishes current epoch');
itemProbeExpect(Extraction::validateTranscript($epochRows)['ok'],true,'original pre-filter transcript remains qualified');
$calls=0;$seen=null;$response=itemProbeResponse($epochData);
$epochObserved=P::extractObserved($epochRows,itemProbeConfiguration(),itemProbeTransport($response,$calls,$seen));
$directCalls=0;$directSeen=null;$epochDirect=(new Provider(itemProbeTransport($response,$directCalls,$directSeen),itemProbeConfiguration()))->extract($epochRows);
itemProbeExpect($epochObserved['result'],$epochDirect,'epoch observation preserves the exact current-provider failure');
itemProbeExpect($seen,$directSeen,'epoch observation preserves exact provider request body and fixture key');
itemProbeExpect([$calls,$directCalls],[1,1],'epoch observation calls each independent provider once');
$epochRequest=json_decode($seen['body'],true,32,JSON_THROW_ON_ERROR);
$sentRows=json_decode($epochRequest['input'][0]['content'],true,32,JSON_THROW_ON_ERROR)['transcript'];
itemProbeExpect($sentRows,Evidence::episodeTranscript($epochRows),'observer uses the exact proved episode sent by current provider');
itemProbeExpect($sentRows[0]['id'],'m3','epoch retains original supplied labels rather than renumbering');
itemProbeExpect(count($sentRows),6,'only old operational-test episode is excluded');
itemProbeExpect(str_contains(json_encode($sentRows,JSON_THROW_ON_ERROR),'SECRET_OLD_ORDER'),false,'provider receives no pre-test order text');
itemProbeExpect($epochObserved['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
    'items'=>[itemProbeMetadata(array_fill(0,7,false))]],'old uncited product cannot set any-transcript or folded flags');
itemProbeAssertMetadata($epochObserved['item_grounding'],'post-test epoch');
$observations[]=$epochObserved['item_grounding'];
$oldCited=$epochData;$oldCited['evidence']['request_ids'][]='m1';$calls=0;
$oldBorrowed=P::extractObserved($epochRows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($oldCited),$calls));
itemProbeExpect($calls,1,'old citation failure never retries the provider');
itemProbeExpect($oldBorrowed['result']['diagnostic']['validation_category'],'EVIDENCE_ID_MISSING','excluded old citation fails against provider episode');
itemProbeExpect($oldBorrowed['item_grounding'],itemProbeEmpty(),'old cited history cannot acquire morphology metadata');
$observations[]=['result'=>$safe($oldBorrowed['result']),'item_grounding'=>$oldBorrowed['item_grounding']];
$reads=0;$calls=0;
$epochRun=P::run(true,static function()use(&$reads,$epochRows):array{$reads++;return itemProbeState($epochRows);},
    static function($rows)use(&$calls,$epochData):array {
        itemProbeExpect($rows[0]['id'],'m1','run passes original snapshot to provider wrapper before authoritative filtering');
        return P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($epochData),$calls));
    },$safe);
itemProbeExpect([$reads,$calls],[3,1],'epoch run proves stable original snapshot with one extraction');
itemProbeExpect($epochRun['output']['status'],'AI_EXTRACTION_FAILED','epoch diagnostic preserves actual failure');
itemProbeExpect($epochRun['output']['item_grounding'],$epochObserved['item_grounding'],'run returns only current-episode fixed morphology');
$observations[]=$epochRun;
$epochRows[0]['text'].=' WA-1234';$calls=0;$thrown=false;
try {P::extractObserved($epochRows,itemProbeConfiguration(),itemProbeTransport($response,$calls));}
catch(InvalidArgumentException $error) {$thrown=true;}
itemProbeExpect([$thrown,$calls],[true,0],'global old WA marker is checked before filtering and cannot cause HTTP');

// Stable observation plus pre/post checks; response morphology is disclosed only for this exact snapshot.
$reads=0;$calls=0;
$run=P::run(true,static function()use(&$reads):array{$reads++;return itemProbeState();},
    static function($rows)use(&$calls):array{return P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse(itemProbeData()),$calls));},$safe);
itemProbeExpect([$reads,$calls],[3,1],'stable observation and two CAS checks plus one provider request');
itemProbeExpect($run['output']['status'],'AI_EXTRACTION_FAILED','run keeps failed extraction status');
itemProbeExpect($run['output']['snapshot_current'],true,'run proves the observed snapshot current');
itemProbeExpect($run['output']['writes'],0,'zero runtime writes');
itemProbeExpect($run['output']['ai_calls'],1,'one provider invocation reported');
itemProbeExpect($run['output']['item_grounding'],['status'=>'ITEM_NAME_GROUNDING','items_count'=>1,
    'items'=>[itemProbeMetadata([false,false,false,true,true,true])]],'stable result contains only scoped booleans');
$observations[]=$run;
itemProbeExpect(P::authorized(['probe.php','--probe-one']),true,'exact CLI authorization');
foreach([['probe.php'],['probe.php','--probe-one','--probe-one'],['probe.php','--probe-one=1'],['probe.php','--conversation=12']]as$args)
    itemProbeExpect(P::authorized($args),false,'other argument combinations rejected');
$reads=0;$calls=0;
$denied=P::run(false,static function()use(&$reads):array{$reads++;return itemProbeState();},
    static function()use(&$calls):array{$calls++;return [];},$safe);
itemProbeExpect([$reads,$calls],[0,0],'unauthorized probe neither reads customer data nor calls extraction');
itemProbeExpect($denied['output']['reason'],'EXPLICIT_PROBE_REQUIRED','authorization required');
$observations[]=$denied;
foreach(['incomplete','below-floor','empty-range','customer-only','business-only','probe-marker','operational-test','non-list','invalid-time','oversized']as$case) {
    $state=itemProbeState();
    if($case==='incomplete')$state['snapshot']['complete']=false;
    if($case==='below-floor')$state['floor']=4015;
    if($case==='empty-range')$state['ceiling']=$state['floor'];
    if($case==='customer-only')$state['snapshot']['transcript']=[$state['snapshot']['transcript'][0]];
    if($case==='business-only')$state['snapshot']['transcript']=[$state['snapshot']['transcript'][1]];
    if($case==='probe-marker')$state['snapshot']['transcript'][0]['text'].=' WA-1234';
    if($case==='operational-test')$state['snapshot']['transcript'][0]['text']='هذا الطلب اختبار لا ترسله';
    if($case==='non-list')$state['snapshot']['transcript']=array_column($state['snapshot']['transcript'],null,'id');
    if($case==='invalid-time')$state['snapshot']['transcript'][0]['sent_at']='SECRET_TIME';
    if($case==='oversized')$state['snapshot']['transcript'][0]['text']=str_repeat('X',17000);
    $calls=0;$stopped=P::run(true,static fn()=>$state,static function()use(&$calls):array{$calls++;return [];},$safe);
    itemProbeExpect($calls,0,$case.' unqualified context never invokes provider');
    itemProbeExpect($stopped['output']['ai_calls'],0,$case.' zero paid requests reported');
    itemProbeExpect($stopped['output']['reason'],'CONTEXT_NOT_QUALIFIED',$case.' fail-closed classification');
    itemProbeExpect(isset($stopped['output']['item_grounding']),false,$case.' no observed metadata');
    $observations[]=$stopped;
}
$readyData=itemProbeData();$readyData['evidence']['request_ids'][]='m3';$calls=0;
$ready=P::run(true,static fn()=>itemProbeState(),static function($rows)use(&$calls,$readyData):array{
    return P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse($readyData),$calls));
},$safe);
itemProbeExpect($calls,1,'grounded extraction still makes only one request');
itemProbeExpect($ready['output']['status'],'AI_EXTRACTION_READY','grounded extraction remains diagnostic success');
itemProbeExpect($ready['output']['item_grounding'],itemProbeEmpty(),'success never attaches failure-only morphology');
itemProbeExpect($ready['output']['extraction']['grounded'],true,'current application validator rechecks success');
$observations[]=$ready;
foreach(['floor','ceiling','configuration','snapshot']as$change) {
    $reads=0;$calls=0;
    $stale=P::run(true,static function()use(&$reads,$change):array{
        $state=itemProbeState();if(++$reads===3) {
            if($change==='floor')$state['floor']++;
            if($change==='ceiling')$state['ceiling']++;
            if($change==='configuration')$state['configuration_fingerprint']=str_repeat('c',64);
            if($change==='snapshot')$state['snapshot']['hash']=str_repeat('d',64);
        }return $state;
    },static function($rows)use(&$calls):array{return P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse(itemProbeData()),$calls));},$safe);
    itemProbeExpect([$reads,$calls],[3,1],$change.' stale result not retried');
    itemProbeExpect($stale['output']['status'],'RESULT_DISCARDED',$change.' postcall CAS fails closed');
    itemProbeExpect($stale['output']['snapshot_current'],false,$change.' stale state reported');
    itemProbeExpect(isset($stale['output']['item_grounding']),false,$change.' stale grounding metadata suppressed');
    itemProbeExpect(isset($stale['output']['analysis_failure']),false,$change.' stale result diagnostic suppressed');
    itemProbeExpect(isset($stale['output']['extraction']),false,$change.' stale extraction suppressed');
    $observations[]=$stale;
}
$reads=0;$calls=0;
$prechanged=P::run(true,static function()use(&$reads):array{$state=itemProbeState();if(++$reads===2)$state['ceiling']++;return $state;},
    static function()use(&$calls):array{$calls++;return [];},$safe);
itemProbeExpect([$reads,$calls],[2,0],'precall state change blocks provider');
itemProbeExpect($prechanged['output']['reason'],'STATE_CHANGED_BEFORE_PROBE','precall changed state classification');
itemProbeExpect(isset($prechanged['output']['item_grounding']),false,'no metadata before provider');
$observations[]=$prechanged;
$reads=0;$calls=0;
$unreadable=P::run(true,static function()use(&$reads):array{if(++$reads===3)throw new RuntimeException('SECRET_POSTCALL');return itemProbeState();},
    static function($rows)use(&$calls):array{return P::extractObserved($rows,itemProbeConfiguration(),itemProbeTransport(itemProbeResponse(itemProbeData()),$calls));},$safe);
itemProbeExpect($calls,1,'unreadable postcall not retried');
itemProbeExpect($unreadable['output']['snapshot_current'],false,'unreadable postcall fails closed');
itemProbeExpect(isset($unreadable['output']['item_grounding']),false,'unreadable postcall discards observed metadata');
$observations[]=$unreadable;

// Untrusted callbacks cannot smuggle names, IDs, arbitrary strings, non-booleans or oversized lists.
$baseFailure=$observed=P::extractObserved(itemProbeRows(),itemProbeConfiguration(),itemProbeTransport(itemProbeResponse(itemProbeData()),$calls));
foreach(['extra-key','extra-item-key','bad-status','bad-count','count-mismatch','bad-index','non-boolean','too-many','not-list']as$case) {
    $malicious=$baseFailure;
    if($case==='extra-key')$malicious['item_grounding']['name']='SECRET_NAME';
    if($case==='extra-item-key')$malicious['item_grounding']['items'][0]['name']='SECRET_NAME';
    if($case==='bad-status')$malicious['item_grounding']['status']='SECRET_STATUS';
    if($case==='bad-count')$malicious['item_grounding']['items_count']='1';
    if($case==='count-mismatch')$malicious['item_grounding']['items_count']=0;
    if($case==='bad-index')$malicious['item_grounding']['items'][0]['index']=0;
    if($case==='non-boolean')$malicious['item_grounding']['items'][0]['literal_match_any_transcript']='SECRET_TEXT';
    if($case==='too-many') {$malicious['item_grounding']['items']=array_fill(0,31,itemProbeMetadata(array_fill(0,6,false)));$malicious['item_grounding']['items_count']=31;}
    if($case==='not-list')$malicious['item_grounding']['items']=['SECRET_KEY'=>$malicious['item_grounding']['items'][0]];
    $sanitized=P::run(true,static fn()=>itemProbeState(),static fn()=>$malicious,$safe);
    itemProbeExpect($sanitized['output']['item_grounding'],itemProbeEmpty(),$case.' metadata whitelist fails closed');
    $observations[]=$sanitized;
}
foreach(P::ITEM_FLAGS as$flag) {
    foreach(['wrong-type','missing']as$case) {
        $malicious=$baseFailure;
        if($case==='wrong-type')$malicious['item_grounding']['items'][0][$flag]='SECRET_BOOL';
        if($case==='missing')unset($malicious['item_grounding']['items'][0][$flag]);
        $sanitized=P::run(true,static fn()=>itemProbeState(),static fn()=>$malicious,$safe);
        itemProbeExpect($sanitized['output']['item_grounding'],itemProbeEmpty(),$case.' per-flag metadata whitelist: '.$flag);
        $observations[]=$sanitized;
    }
}
$falseSuccess=$baseFailure;$falseSuccess['result']=['ok'=>true,'data'=>itemProbeData()];
$falseSuccess=P::run(true,static fn()=>itemProbeState(),static fn()=>$falseSuccess,$safe);
itemProbeExpect($falseSuccess['output']['status'],'AI_EXTRACTION_FAILED','callback success cannot bypass current validator');
itemProbeExpect($falseSuccess['output']['analysis_failure']['diagnostic']['validation_field'],'ITEM_NAME','current validator independently rechecks callback success');
$observations[]=$falseSuccess;
foreach(['reason','category','field','status']as$case) {
    $differentFailure=$baseFailure;
    if($case==='reason')$differentFailure['result']['reason']='INVALID_EXTRACTION';
    if($case==='category')$differentFailure['result']['diagnostic']['validation_category']='ITEM_REQUEST_NAME';
    if($case==='field')$differentFailure['result']['diagnostic']['validation_field']='CUSTOMER_NAME';
    if($case==='status')$differentFailure['result']['ok']=true;
    $differentFailure=P::run(true,static fn()=>itemProbeState(),static fn()=>$differentFailure,$safe);
    itemProbeExpect($differentFailure['output']['item_grounding'],itemProbeEmpty(),$case.' metadata requires exact item-name rejection gate');
    $observations[]=$differentFailure;
}
$unknownFailure=['result'=>['ok'=>false,'reason'=>'SECRET_REASON','diagnostic'=>['stage'=>'SECRET_STAGE',
    'validation_category'=>'SECRET_CATEGORY','validation_field'=>'SECRET_FIELD','http_status'=>9999999,'curl_errno'=>-1,
    'elapsed_ms'=>'SECRET_MS','response_status'=>'SECRET_STATUS','incomplete_reason'=>'SECRET_REASON',
    'transport_category'=>'SECRET_TRANSPORT','input_tokens'=>-1],'error'=>'SECRET_RAW_ERROR'],
    'item_grounding'=>$baseFailure['item_grounding']];
$unknownFailure=P::run(true,static fn()=>itemProbeState(),static fn()=>$unknownFailure,$safe);
itemProbeExpect($unknownFailure['output']['item_grounding'],itemProbeEmpty(),'unknown failure suppresses item metadata');
itemProbeExpect($unknownFailure['output']['analysis_failure']['reason'],'UNKNOWN','arbitrary failure reason never copied');
foreach($unknownFailure['output']['analysis_failure']['diagnostic']as$field=>$value)
    itemProbeExpect($value,null,'arbitrary diagnostic removed '.$field);
$observations[]=$unknownFailure;

$serialized=json_encode($observations,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach(['فسيخ','نبروه','نبروة','رنجة','Herring','HERRING','SECRET_','sk-item-probe-private-fixture-key',
    'm1','m2','m3','m4','m5','https://','201000000001','31.04']as$secret)
    itemProbeExpect(str_contains($serialized,$secret),false,'no private response/input value emitted: '.$secret);
$source=file_get_contents($probeFile);
itemProbeExpect(preg_match('/(?:->|::)(?:consume|process|projectPending|projectAcceptedReply|analyze|dispatch|insert|update|delete|save)\s*\(/',$source),0,'no writes or pipeline processing');
itemProbeExpect(str_contains($source,'->lockForUpdate('),false,'no DB write locks');
itemProbeExpect(str_contains($source,'flock('),false,'no filesystem write locks');
itemProbeExpect(substr_count($source,'->extract('),1,'one current-provider extraction call site');
itemProbeExpect(str_contains($source,'$snapshot->invoke($workflow, $cid, $floor, $ceiling, false)'),true,'explicit read-only production snapshot');
foreach(P::SOURCE_PINS as[$path,$expected]) {
    $sourcePath = $path;
    $bytes=file_get_contents(dirname(__DIR__).'/'.$sourcePath);
    itemProbeExpect(sha1('blob '.strlen($bytes)."\0".$bytes),$expected,'reviewed production source pin '.$path);
}
echo 'WHATSAPP_ORDER_FRAGMENTED_ITEM_GROUNDING_READ_ONLY_PROBE_CHECKS='.$checks."\n";
