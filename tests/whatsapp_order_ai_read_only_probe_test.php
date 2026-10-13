<?php
// Real provider/extraction/failure sanitization with private fake HTTP; no bootstrap, DB or network.
define('WHATSAPP_ORDER_AI_READ_ONLY_PROBE_TEST_ONLY', true);
define('WHATSAPP_ORDER_FRAGMENTED_READ_ONLY_PROBE_TEST_ONLY', true);
define('WHATSAPP_ORDER_SOURCE_SELECTION_READ_ONLY_PROBE_TEST_ONLY', true);
define('WHATSAPP_ORDER_OPTION_SOURCE_READ_ONLY_PROBE_TEST_ONLY', true);
$probeVariant = $argv[1] ?? 'legacy';
$probeFiles = ['legacy' => 'whatsapp_order_ai_read_only_probe.php',
    'forward' => 'whatsapp_order_ai_forward_read_only_probe.php',
    'nested' => 'whatsapp_order_ai_nested_forward_read_only_probe.php',
    'fragmented' => 'whatsapp_order_fragmented_read_only_probe.php',
    'source-selection' => 'whatsapp_order_source_selection_read_only_probe.php',
    'option-source' => 'whatsapp_order_option_source_read_only_probe.php'];
if (!array_key_exists($probeVariant, $probeFiles)) throw new RuntimeException('Invalid probe fixture');
$probeFile = dirname(__DIR__) . '/deployment/' . $probeFiles[$probeVariant];
require $probeFile;
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
// Immutable production probes keep their exact reviewed application bytes.
$preFragmented = in_array($probeVariant,['source-selection','option-source'],true) ? dirname(__DIR__) . '/'
    : ($probeVariant === 'fragmented' ? dirname(__DIR__) . '/tests/fixtures/whatsapp_order_pre_source_selection/'
        : dirname(__DIR__) . '/tests/fixtures/whatsapp_order_pre_fragmented/');
if (in_array($probeVariant,['fragmented','source-selection','option-source'],true))
    require $preFragmented . 'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require $preFragmented . 'app/Support/WhatsAppOrderExtraction.php';
$selectionRuntime=$probeVariant==='source-selection' ? dirname(__DIR__).'/tests/fixtures/whatsapp_order_pre_option_source/' : $preFragmented;
if (in_array($probeVariant,['source-selection','option-source'],true))
    require $selectionRuntime . 'app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require $selectionRuntime . 'app/Services/Dashboard/WhatsAppOrderAiProvider.php';
require $preFragmented . 'app/Services/Dashboard/WhatsAppOrderWorkflow.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
if ($probeVariant === 'fragmented') class_alias(WhatsAppOrderFragmentedReadOnlyProbe::class, 'WhatsAppOrderAiReadOnlyProbe');
if ($probeVariant === 'source-selection') class_alias(WhatsAppOrderSourceSelectionReadOnlyProbe::class, 'WhatsAppOrderAiReadOnlyProbe');
if ($probeVariant === 'option-source') class_alias(WhatsAppOrderOptionSourceReadOnlyProbe::class, 'WhatsAppOrderAiReadOnlyProbe');
use WhatsAppOrderAiReadOnlyProbe as P;
use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Services\Dashboard\WhatsAppOrderWorkflow as Workflow;
use App\Support\WhatsAppOrderExtraction as Extraction;
$checks = 0;
function probeExpect($actual, $expected, string $label): void {
    $GLOBALS['checks']++; if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function probeRows(): array {
    return [
        ['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-13 12:00:01',
            'text'=>'عايز 0.5 كيلو فسيخ من المنصورة. اسمي عميل تجريبي ورقمي 201000000001 وعنواني شارع التجربة في المنصورة.','location'=>null],
        ['id'=>'m2','speaker'=>'business','sent_at'=>'2026-10-13 12:00:02',
            'text'=>'تأكيد طلب عميل تجريبي: 0.5 كيلو فسيخ من المنصورة إلى شارع التجربة، الإجمالي التقريبي 450 جنيه.','location'=>null],
        ['id'=>'m3','speaker'=>'customer','sent_at'=>'2026-10-13 12:00:03','text'=>'تمام موافق','location'=>['lat'=>31.04,'long'=>31.38]],
    ];
}
function probeData(): array {
    return ['decision'=>'CONFIRMED','customer'=>['name'=>'عميل تجريبي','phone'=>'201000000001',
        'address'=>'شارع التجربة','area'=>'المنصورة','notes'=>null],'branch_hint'=>'المنصورة','fulfillment'=>'DELIVERY',
        'items'=>[['name'=>'فسيخ','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>null,'cart_reference'=>null]],
        'approximate_total'=>'450','evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m2'],
            'customer_acceptance_ids'=>['m3'],'location_id'=>'m3'],'issues'=>['PRICE_ESTIMATE_ONLY']];
}
function probeState(): array {
    return ['configured'=>true,'floor'=>4016,'ceiling'=>4500,'configuration_fingerprint'=>str_repeat('a',64),
        'snapshot'=>['complete'=>true,'hash'=>str_repeat('b',64),'transcript'=>probeRows(),'keys'=>['m1'=>'SECRET_KEY']],
        'status_event_7215'=>['status'=>'READY','scope_matches'=>true,'statuses_count'=>1,'truncated'=>false,
            'statuses'=>[['status_type'=>'STRING','status'=>'delivered','id_valid'=>true]]]];
}
$workflow = (new ReflectionClass(Workflow::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(Workflow::class, 'safeAnalysisFailure'); $method->setAccessible(true);
$safe = static fn ($result): array => $method->invoke($workflow, $result);
function probeProvider(array $data, int &$calls): Provider {
    return new Provider(static function ($body, $key) use ($data, &$calls): array {
        $calls++;
        probeExpect(is_string($body) && str_contains($body,'transcript'), true, 'actual structured provider input');
        probeExpect($key, 'sk-probe-test-key-private-only', 'private fixture key supplied only to fake transport');
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant',
            'content'=>[['type'=>'output_text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]]],JSON_THROW_ON_ERROR)];
    }, ['enabled'=>true,'mode'=>'auto','model'=>'gpt-6-luna','api_key'=>'sk-probe-test-key-private-only']);
}
function probeRun(callable $read, callable $extract, callable $safe, bool $authorized = true): array {
    return P::run($authorized, $read, $extract, $safe);
}
$calls = 0; $provider = probeProvider(probeData(), $calls); $reads = 0;
$ready = probeRun(static function () use (&$reads): array { $reads++; return probeState(); }, static fn ($rows) => $provider->extract($rows), $safe);
probeExpect($ready['code'],0,'valid grounded extraction accepted diagnostically');
probeExpect($ready['output']['status'],'AI_EXTRACTION_READY','ready classification');
probeExpect($calls,1,'exactly one real provider fake HTTP request');
probeExpect($reads,3,'bounded observation plus pre and post CAS');
probeExpect($ready['output']['writes'],0,'no application writes reported');
probeExpect($ready['output']['ai_calls'],1,'exactly one extraction invocation');
probeExpect($ready['output']['snapshot_current'],true,'stable input and configuration');
probeExpect($ready['output']['input'],['messages'=>3,'customer'=>2,'business'=>1,'location'=>1,'cart'=>0,'complete'=>true,'valid'=>true],'speaker and input morphology');
probeExpect($ready['output']['extraction']['decision'],'CONFIRMED','fixed decision only');
probeExpect($ready['output']['extraction']['evidence_counts'],['request'=>1,'confirmation'=>1,'customer_acceptance'=>1],'counts not evidence labels');
probeExpect($ready['output']['extraction']['fields_present']['name'],true,'name presence not name value');
probeExpect($ready['output']['extraction']['fields_present']['notes'],false,'absent optional field');
probeExpect($ready['output']['extraction']['grounded'],true,'actual extraction grounding was rerun');
probeExpect(P::authorized(['probe.php','--probe-one']),true,'exact CLI flag authorizes one probe');
foreach ([['probe.php'],['probe.php','--probe-one','--probe-one'],['probe.php','--probe-one=1'],['probe.php','--conversation=12']] as $args)
    probeExpect(P::authorized($args),false,'all other CLI spellings denied');
$reads = 0; $calls = 0;
$denied = probeRun(static function () use (&$reads): array { $reads++; return probeState(); }, static function () use (&$calls): array { $calls++; return []; }, $safe, false);
probeExpect($denied['output']['reason'],'EXPLICIT_PROBE_REQUIRED','explicit authorization mandatory');
probeExpect([$reads,$calls],[0,0],'unauthorized invocation does not read customer data or call provider');
foreach (['incomplete','below-floor','empty-range','customer-only','business-only','test-marker','invalid-time','oversized'] as $case) {
    $state = probeState();
    if ($case === 'incomplete') $state['snapshot']['complete']=false;
    if ($case === 'below-floor') $state['floor']=4015;
    if ($case === 'empty-range') $state['ceiling']=4016;
    if ($case === 'customer-only') $state['snapshot']['transcript']=[$state['snapshot']['transcript'][0]];
    if ($case === 'business-only') $state['snapshot']['transcript']=[$state['snapshot']['transcript'][1]];
    if ($case === 'test-marker') $state['snapshot']['transcript'][0]['text'].=' WA-1234';
    if ($case === 'invalid-time') $state['snapshot']['transcript'][0]['sent_at']='SECRET_TIME';
    if ($case === 'oversized') $state['snapshot']['transcript'][0]['text']=str_repeat('X',17000);
    $calls=0;
    $stopped=probeRun(static fn ()=>$state,static function () use (&$calls): array {$calls++;return [];},$safe);
    probeExpect($stopped['output']['reason'],'CONTEXT_NOT_QUALIFIED',$case.' rejected before provider');
    probeExpect($calls,0,$case.' makes zero provider calls');
    probeExpect($stopped['output']['ai_calls'],0,$case.' reports zero extraction calls');
}
foreach (['ceiling','floor','configuration','snapshot'] as $changed) {
    $reads=0; $calls=0; $provider=probeProvider(probeData(),$calls);
    $stale=probeRun(static function () use (&$reads,$changed): array {
        $state=probeState(); if (++$reads===3) {
            if ($changed==='ceiling') $state['ceiling']++;
            if ($changed==='floor') $state['floor']++;
            if ($changed==='configuration') $state['configuration_fingerprint']=str_repeat('c',64);
            if ($changed==='snapshot') $state['snapshot']['hash']=str_repeat('d',64);
        } return $state;
    },static fn ($rows)=>$provider->extract($rows),$safe);
    probeExpect($stale['output']['status'],'RESULT_DISCARDED',$changed.' CAS discards after-call extraction');
    probeExpect($stale['output']['snapshot_current'],false,$changed.' stale input disclosed');
    probeExpect($calls,1,$changed.' has no retry or second call');
    probeExpect(isset($stale['output']['extraction']),false,$changed.' discarded result not exposed');
}
$reads=0; $calls=0;
$prechanged=probeRun(static function () use (&$reads): array {$state=probeState();if(++$reads===2)$state['ceiling']++;return $state;},
    static function () use (&$calls): array {$calls++;return [];},$safe);
probeExpect($prechanged['output']['reason'],'STATE_CHANGED_BEFORE_PROBE','precall concurrent update rejected');
probeExpect($calls,0,'precall CAS prevents paid request');
$reads=0; $calls=0; $provider=probeProvider(probeData(),$calls);
$recheckFail=probeRun(static function () use (&$reads): array {if(++$reads===3)throw new RuntimeException('SECRET_AFTER_CALL');return probeState();},
    static fn ($rows)=>$provider->extract($rows),$safe);
probeExpect($recheckFail['output']['snapshot_current'],false,'unreadable post-state fail closed');
probeExpect($recheckFail['output']['ai_calls'],1,'post-state failure does not reset call accounting');
probeExpect(isset($recheckFail['output']['extraction']),false,'post-state failure never exposes result');
$evil=probeData();$evil['customer']['name']='SECRET_UNGROUNDED_NAME';$calls=0;$provider=probeProvider($evil,$calls);
$rejected=probeRun(static fn()=>probeState(),static fn($rows)=>$provider->extract($rows),$safe);
probeExpect($rejected['output']['status'],'AI_EXTRACTION_FAILED','actual provider validates ungrounded output');
probeExpect($rejected['output']['analysis_failure']['reason'],'INVALID_EVIDENCE','fixed failure reason');
probeExpect($rejected['output']['analysis_failure']['diagnostic']['validation_category'],'LITERAL_NOT_CITED','fixed detailed category');
probeExpect($rejected['output']['analysis_failure']['diagnostic']['validation_field'],'CUSTOMER_NAME','fixed detailed field');
$evil=probeData();$evil['issues']=['SECRET_ISSUE'];
$fakeSuccess=probeRun(static fn()=>probeState(),static fn()=>['ok'=>true,'data'=>$evil],$safe);
probeExpect($fakeSuccess['output']['status'],'AI_EXTRACTION_FAILED','successful untrusted fixture cannot bypass probe validation');
probeExpect($fakeSuccess['output']['analysis_failure']['diagnostic']['validation_category'],'ISSUE_UNKNOWN','unknown issue never exposed');
$secretFailure=['ok'=>false,'reason'=>'SECRET_REASON','diagnostic'=>['stage'=>'SECRET_STAGE','validation_category'=>'<script>SECRET_XSS</script>',
    'validation_field'=>'SECRET_FIELD','http_status'=>99999999,'curl_errno'=>-1,'elapsed_ms'=>'SECRET_MS','response_status'=>'SECRET_RESPONSE',
    'incomplete_reason'=>'SECRET_INCOMPLETE','transport_category'=>'SECRET_TRANSPORT','input_tokens'=>-1],'error'=>'SECRET_RAW_ERROR'];
$failure=probeRun(static fn()=>probeState(),static fn()=>$secretFailure,$safe);
probeExpect($failure['output']['analysis_failure']['reason'],'UNKNOWN','arbitrary failure reason replaced');
foreach ($failure['output']['analysis_failure']['diagnostic'] as $field=>$value)
    probeExpect($value,null,'all arbitrary diagnostic fields erased '.$field);
$payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>P::WABA,'changes'=>[['field'=>'messages','value'=>[
    'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>P::PHONE],
    'contacts'=>[['profile'=>['name'=>'SECRET_PROFILE']]],'statuses'=>[['id'=>'wamid.SECRET_NATIVE','status'=>'held_for_quality_assessment']]]]]]]];
$status=P::statusMetadata($payload);
probeExpect($status['statuses'][0],['status_type'=>'STRING','status'=>'held_for_quality_assessment','id_valid'=>true],'supported status exact enum');
$payload['entry'][0]['changes'][0]['value']['statuses'][0]['status']=['SECRET_STATUS'];
$statusArray=P::statusMetadata($payload);
probeExpect($statusArray['statuses'][0]['status_type'],'ARRAY','raw status shape disclosed');
probeExpect($statusArray['statuses'][0]['status'],'OTHER','arbitrary status never copied');
$payload['entry'][0]['changes'][0]['value']['statuses']=array_fill(0,30,['id'=>'SECRET_ID','status'=>'SECRET_STATE']);
$statusMany=P::statusMetadata($payload);
probeExpect(count($statusMany['statuses']),5,'status list bounded');
probeExpect($statusMany['truncated'],true,'status truncation explicit');
$wrongScope=$payload;$wrongScope['entry'][0]['changes'][0]['value']['metadata']['phone_number_id']='SECRET_PHONE';
probeExpect(P::statusMetadata($wrongScope)['scope_matches'],false,'foreign phone statuses ignored');
$wrongScope=$payload;$wrongScope['entry'][0]['id']='SECRET_WABA';
probeExpect(P::statusMetadata($wrongScope)['scope_matches'],false,'foreign account statuses ignored');
$serialized=json_encode([$ready,$denied,$prechanged,$recheckFail,$rejected,$fakeSuccess,$failure,$status,$statusArray,$statusMany],JSON_THROW_ON_ERROR);
foreach (['201000000001','31.04','31.38','عميل تجريبي','شارع التجربة','SECRET_','sk-probe-test-key-private-only','m1','m2','m3','<script>'] as $secret)
    probeExpect(str_contains($serialized,$secret),false,'result contains no private value '.$secret);
$source=file_get_contents($probeFile);
probeExpect(preg_match('/(?:->|::)(?:consume|process|projectPending|projectAcceptedReply|analyze|dispatch|insert|update|delete|save)\s*\(/',$source),0,'no write or processing paths');
probeExpect(str_contains($source,'->lockForUpdate('),false,'probe does not acquire write locks');
probeExpect(substr_count($source,'->extract('),1,'one production provider extraction call site');
probeExpect(str_contains($source,'$snapshot->invoke($workflow, $cid, $floor, $ceiling, false)'),true,'explicit read-only snapshot invocation');
probeExpect(str_contains($source,'->where(\'id\', 7215)'),true,'status observation fixed to requested event');
if ($probeVariant !== 'legacy') foreach (P::SOURCE_PINS as [$path, $expected]) {
    // The original forward probe intentionally refuses the newer nested helper.
    // Its immutable source contract is tested against the exact reviewed v1 fixture.
    $sourcePath = $probeVariant === 'forward' && $path === 'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'
        ? 'tests/fixtures/whatsapp_forward_v1/' . $path : $path;
    if (!in_array($probeVariant,['fragmented','source-selection','option-source'],true) && in_array($path, ['app/Support/WhatsAppOrderExtraction.php',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php','app/Services/Dashboard/WhatsAppOrderWorkflow.php'], true))
        $sourcePath = 'tests/fixtures/whatsapp_order_pre_fragmented/' . $path;
    if ($probeVariant === 'fragmented' && in_array($path, ['app/Support/WhatsAppOrderExtraction.php',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php','app/Services/Dashboard/WhatsAppOrderWorkflow.php',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'], true))
        $sourcePath = 'tests/fixtures/whatsapp_order_pre_source_selection/' . $path;
    if ($probeVariant==='source-selection' && in_array($path,['app/Services/Dashboard/WhatsAppOrderSourceSelection.php',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php'],true))
        $sourcePath='tests/fixtures/whatsapp_order_pre_option_source/'.$path;
    $bytes = file_get_contents(dirname(__DIR__) . '/' . $sourcePath);
    probeExpect(sha1('blob ' . strlen($bytes) . "\0" . $bytes), $expected, $probeVariant . ' guard matches the reviewed runtime ' . $path);
}
echo 'WHATSAPP_ORDER_AI_READ_ONLY_PROBE_CHECKS='.$checks."\n";
