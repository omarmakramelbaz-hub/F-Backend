<?php
// Synthetic customer data; real source decoder, evidence and extraction validators.
// Fake HTTP only. No bootstrap, credentials, database, customers, tickets or delivery calls.
$addressRoot = dirname(__DIR__);
$addressBaseline = ($argv[1] ?? null) === '--baseline-request';
require $addressRoot . '/app/Support/WhatsAppInboxProtocol.php';
require $addressRoot . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require $addressRoot . '/app/Support/WhatsAppOrderExtraction.php';
require $addressRoot . '/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require $addressRoot . ($addressBaseline
    ? '/tests/fixtures/whatsapp_order_pre_fragmented_address/app/Services/Dashboard/WhatsAppOrderAiProvider.php'
    : '/app/Services/Dashboard/WhatsAppOrderAiProvider.php');
require $addressRoot . '/app/Services/Dashboard/WhatsAppOrderWorkflow.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Services\Dashboard\WhatsAppOrderConversationEvidence as Evidence;
use App\Services\Dashboard\WhatsAppOrderSourceSelection as Selection;
use App\Services\Dashboard\WhatsAppOrderWorkflow as Workflow;
use App\Support\WhatsAppOrderExtraction as Extraction;

$checks = 0;
function addressExpect($actual, $expected, string $label): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function addressCanonical($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = addressCanonical($child);
    return $value;
}
function addressSame($actual, $expected, string $label): void
{
    addressExpect(addressCanonical($actual), addressCanonical($expected), $label);
}
function addressRow(string $id, string $speaker, string $text, int $second): array
{
    return ['id' => $id, 'speaker' => $speaker,
        'sent_at' => '2026-10-13 12:00:' . sprintf('%02d', $second), 'text' => $text, 'location' => null];
}
function addressRows(string $address = 'مدينة التجربة المنصورة'): array
{
    return [
        addressRow('m1', 'customer', 'هو الفسيخ بكم عندكم', 1),
        addressRow('m2', 'business', 'الكيلو 2 حبه ب 450 والكيلو 3 حبه ب 400 ج يفندم', 2),
        addressRow('m3', 'customer', 'عايز كيلو ونص من الكيلو ٣ حبه', 3),
        addressRow('m4', 'customer', 'في رنجه', 4),
        addressRow('m5', 'business', 'كامل ولا مخلي يفندم؟', 5),
        addressRow('m6', 'customer', 'كيلو ونص كامل', 6),
        addressRow('m7', 'business', 'متاح رنجه يفندم الكيلو ب 220 ج رنجه مبطرخه', 7),
        addressRow('m8', 'customer', 'تمام اعملي كيلو', 8),
        addressRow('m9', 'business', 'حاجه تانيه يفندم؟', 9),
        addressRow('m10', 'customer', 'لا تمام', 10),
        addressRow('m11', 'business', 'كده الاوردر كيلو ونص من الكيلو 3 حبه كامل وكيلو رنجه', 11),
        addressRow('m12', 'customer', 'تمام', 12),
        addressRow('m13', 'business', 'استاذنك البيانات يفندم', 13),
        addressRow('m14', 'business', 'الاور', 14),
        addressRow('m15', 'customer', 'عميل تجريبي', 15),
        addressRow('m16', 'customer', 'رقم التليفون 01000000001', 16),
        addressRow('m17', 'customer', $address, 17),
        addressRow('m18', 'business', 'تم تاكيد الاورد', 18),
        addressRow('m19', 'business', 'والبيانات والعنوان', 19),
    ];
}
function addressReference(string $anchor, string $offer, string $quantity, int $clause, ?string $option = null): array
{
    return ['name_anchor_id' => $anchor, 'business_offer_id' => $offer,
        'customer_quantity_id' => $quantity, 'business_summary_id' => 'm11',
        'summary_clause_index' => $clause, 'customer_option_id' => $option];
}
function addressData(string $address = 'مدينة التجربة المنصورة', string $area = 'المنصورة'): array
{
    return ['decision' => 'CONFIRMED', 'customer' => ['name' => 'عميل تجريبي', 'phone' => '01000000001',
        'address' => $address, 'area' => $area, 'notes' => null],
        'branch_hint' => null, 'fulfillment' => 'DELIVERY', 'items' => [
            ['name' => 'الفسيخ', 'quantity' => '1.5', 'quantity_mode' => 'weight', 'option_hint' => 'كامل',
                'cart_reference' => null, 'conversation_reference' => addressReference('m1', 'm2', 'm3', 0, 'm6')],
            ['name' => 'رنجه', 'quantity' => '1', 'quantity_mode' => 'weight', 'option_hint' => null,
                'cart_reference' => null, 'conversation_reference' => addressReference('m4', 'm7', 'm8', 1)],
        ], 'approximate_total' => null, 'evidence' => [
            'request_ids' => ['m1', 'm3', 'm4', 'm6', 'm8', 'm15', 'm16', 'm17'],
            'confirmation_ids' => ['m11', 'm18', 'm19'], 'customer_acceptance_ids' => ['m12'], 'location_id' => null],
        'issues' => []];
}
function addressSpan(array $input, string $id, string $word): array
{
    foreach ($input['name_tokens'] as $record) {
        if ($record['message_id'] !== $id || $record['source'] !== 'text') continue;
        foreach ($record['tokens'] as $token) if ($token['text'] === $word) {
            return ['message_id' => $id, 'source' => 'text',
                'first_token' => $token['index'], 'last_token' => $token['index']];
        }
    }
    throw new RuntimeException('FAILED: synthetic native token absent');
}
function addressWire(array $input, array $data): array
{
    $wire = $data;
    foreach ($wire['items'] as &$item) {
        $ref = $item['conversation_reference'];
        $item['name_source'] = addressSpan($input, $ref['name_anchor_id'], $item['name']);
        $clauseId = null;
        foreach ($input['summary_clauses'] as $clause) {
            if ($clause['message_id'] === $ref['business_summary_id']
                && $clause['clause_index'] === $ref['summary_clause_index']) $clauseId = $clause['id'];
        }
        if ($clauseId === null) throw new RuntimeException('FAILED: synthetic native clause absent');
        $item['conversation_reference'] = ['business_offer_id' => $ref['business_offer_id'],
            'customer_quantity_id' => $ref['customer_quantity_id'], 'summary_clause_id' => $clauseId];
        $item['option_source'] = $item['option_hint'] === null ? null
            : addressSpan($input, $ref['customer_option_id'] ?? $ref['customer_quantity_id'], $item['option_hint']);
        unset($item['name'], $item['option_hint']);
    }
    unset($item);
    return $wire;
}
function addressRun(array $rows, array $data, string $model = 'fixture-model', ?callable $mutateWire = null): array
{
    $calls = 0; $input = null; $request = null; $wire = null;
    $provider = new Provider(static function ($body, $key) use (&$calls, &$input, &$request, &$wire, $data, $mutateWire): array {
        $calls++;
        addressExpect($key, 'sk-fragmented-address-synthetic-only', 'synthetic credential only');
        $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $input = json_decode($request['input'][0]['content'], true, 64, JSON_THROW_ON_ERROR);
        $wire = addressWire($input, $data);
        if ($mutateWire !== null) $mutateWire($wire);
        return ['status' => 200, 'body' => json_encode(['status' => 'completed', 'output' => [
            ['type' => 'message', 'role' => 'assistant', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($wire, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
        ]], JSON_THROW_ON_ERROR)];
    }, ['enabled' => true, 'mode' => 'auto', 'model' => $model, 'api_key' => 'sk-fragmented-address-synthetic-only']);
    return ['result' => $provider->extract($rows), 'calls' => $calls, 'input' => $input,
        'request' => $request, 'wire' => $wire];
}
function addressReject(array $run, string $label): void
{
    addressExpect($run['calls'], 1, $label . ' makes one fake call without retry');
    addressExpect($run['result']['ok'], false, $label . ' fails closed');
    addressExpect($run['result']['data'], null, $label . ' returns no accepted partial order');
    addressExpect(in_array($run['result']['reason'], ['INVALID_EVIDENCE', 'INVALID_EXTRACTION', 'INVALID_RESPONSE'], true), true,
        $label . ' retains original fixed rejection categories');
}
function addressBlob(string $file): string
{
    $bytes = file_get_contents($file);
    return sha1('blob ' . strlen($bytes) . "\0" . $bytes);
}
// The pure freshness predicate reads configuration and a clock, not DB or delivery services.
function now($timezone = null): DateTimeImmutable
{
    return new class('2026-10-14 00:00:00', new DateTimeZone('UTC')) extends DateTimeImmutable {
        public function addSeconds(int $seconds): self { return $this->modify('+' . $seconds . ' seconds'); }
    };
}
final class FragmentedAddressWorkflowClock extends Workflow
{
    public ?string $activation = '2026-10-13 12:00:00';
    protected function orderConfig(string $name, $default = null)
    {
        return $name === 'whatsapp_cart.activated_at' ? $this->activation : $default;
    }
}

try {
    if ($addressBaseline) {
        addressExpect(addressBlob($addressRoot . '/tests/fixtures/whatsapp_order_pre_fragmented_address/app/Services/Dashboard/WhatsAppOrderAiProvider.php'),
            '39f644ff97c7cf6182666a5aff3f6fb231cb0733', 'immutable previous Provider binding');
        $baseline = addressRun(addressRows(), addressData(), $argv[2] ?? 'fixture-model');
        addressExpect($baseline['result']['ok'], true, 'old validator already permits literal short acknowledged address');
        echo json_encode($baseline['request'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit(0);
    }
    $rows = addressRows(); $data = addressData();
    addressExpect(Extraction::validateTranscript($rows)['ok'], true, 'fragmented synthetic transcript is valid');
    addressExpect(Evidence::addressAcknowledged($data, $rows), true, 'short address with no street or GPS has actual data acknowledgment');
    addressExpect(Evidence::prove($data, $rows), ['ok' => true, 'reason' => null], 'exact fragmented basket remains semantically proved');
    foreach (['مدينة التجربة المنصورة' => 'المنصورة', 'حي التجربة المنصوره' => 'المنصوره',
        'منطقة المثال شبرا الخيمة' => 'شبرا الخيمة', 'مدينة التجربة المنصورة شارع المثال' => 'المنصورة'] as $address => $area) {
        $run = addressRun(addressRows($address), addressData($address, $area));
        addressSame($run['result'], Extraction::result(true, null, addressData($address, $area)), 'literal supplied address survives native decoding unchanged');
        addressExpect($run['calls'], 1, 'literal short address causes one fake request');
        addressExpect($run['result']['data']['customer']['address'], $address, 'complete address is preserved with exact spelling');
        addressExpect($run['result']['data']['customer']['area'], $area, 'area remains separate literal city');
        addressExpect($run['result']['data']['customer']['notes'], null, 'missing building details are not invented in notes');
        addressExpect($run['result']['data']['branch_hint'], null, 'address does not choose fulfillment branch or map origin');
        addressExpect($run['result']['data']['evidence']['location_id'], null, 'text address never manufactures a GPS source');
        addressExpect($run['result']['data']['approximate_total'], null, 'chat unit prices are not promoted to an ERP total');
        addressExpect($run['result']['data']['items'][0]['quantity'], '1.5', 'three fish per kilo stays a variant of 1.5 kg');
        addressExpect($run['result']['data']['items'][1]['quantity'], '1', 'renga quantity stays independently supported');
        addressExpect($run['input']['transcript'], addressRows($address), 'all separated customer and business fragments are retained');
    }
    $plan = Selection::build($rows);
    addressExpect($plan['ok'], true, 'real source selection plan builds');
    $decoded = Selection::decode(addressWire($plan['data']['input'], $data), $plan['data']);
    addressSame($decoded, Extraction::result(true, null, $data), 'real source decoder copies only returned grounded address fields');
    addressExpect(Extraction::committingRequestIds($data, $rows), ['m3', 'm8'], 'name, phone and address fragments do not become order commitments');

    $draft = $data; $draft['decision'] = 'DRAFT'; $draft['customer']['address'] = null; $draft['issues'] = ['MISSING_ADDRESS'];
    $draftRun = addressRun($rows, $draft);
    addressSame($draftRun['result'], Extraction::result(true, null, $draft), 'returned DRAFT with missing address remains unchanged');
    addressExpect($draftRun['calls'], 1, 'missing-address draft is not retried');
    addressExpect($draftRun['result']['data']['customer']['area'], 'المنصورة', 'area alone never triggers automatic address fill');
    addressExpect($draftRun['result']['data']['customer']['address'], null, 'provider never copies area into absent address');
    addressExpect(Extraction::committingRequestIds($draft, $rows), [], 'a missing-address draft cannot supply automatic commitment');
    $draftWithAddress = $data; $draftWithAddress['decision'] = 'DRAFT'; $draftWithAddress['issues'] = ['UNCONFIRMED'];
    addressSame(addressRun($rows, $draftWithAddress)['result'], Extraction::result(true, null, $draftWithAddress),
        'literal complete address does not promote a model draft to CONFIRMED');
    $areaOnlyRows = $rows; $areaOnlyRows[16]['text'] = 'المنصورة';
    addressSame(addressRun($areaOnlyRows, $draft)['result'], Extraction::result(true, null, $draft),
        'service-area-only answer stays a missing-address draft rather than application fallback');

    $guards = [
        'uncited-address-row' => static function (&$r, &$d): void {
            $d['evidence']['request_ids'] = array_values(array_diff($d['evidence']['request_ids'], ['m17']));
        },
        'invented-street' => static function (&$r, &$d): void { $d['customer']['address'] .= ' شارع غير مذكور'; },
        'invented-building' => static function (&$r, &$d): void { $d['customer']['address'] .= ' عمارة 99'; },
        'invented-neighborhood' => static function (&$r, &$d): void { $d['customer']['address'] = 'حي غير مذكور المنصورة'; },
        'missing-address-confirmed' => static function (&$r, &$d): void { $d['customer']['address'] = null; },
        'business-address-wrong-role' => static function (&$r, &$d): void { $r[16]['speaker'] = 'business'; },
        'business-address-in-confirmations-only' => static function (&$r, &$d): void {
            $r[16]['speaker'] = 'business';
            $d['evidence']['request_ids'] = array_values(array_diff($d['evidence']['request_ids'], ['m17']));
            $d['evidence']['confirmation_ids'][] = 'm17';
        },
        'address-in-acceptance-only' => static function (&$r, &$d): void {
            $d['evidence']['request_ids'] = array_values(array_diff($d['evidence']['request_ids'], ['m17']));
            $d['evidence']['customer_acceptance_ids'][] = 'm17';
        },
        'missing-data-request' => static function (&$r, &$d): void { $r[12]['text'] = 'شكرا'; },
        'missing-final-order-ack' => static function (&$r, &$d): void { $r[17]['text'] = 'شكرا'; },
        'missing-final-address-ack' => static function (&$r, &$d): void { $r[18]['text'] = 'شكرا'; },
        'uncited-final-address-ack' => static function (&$r, &$d): void { $d['evidence']['confirmation_ids'] = ['m11', 'm18']; },
        'missing-customer-basket-acceptance' => static function (&$r, &$d): void { $d['evidence']['customer_acceptance_ids'] = []; },
        'unrelated-city-no-customer-data-reply' => static function (&$r, &$d): void { $r[16]['text'] = 'بتوصلوا المنصورة؟'; },
        'later-changed-address' => static function (&$r, &$d): void { $r[] = addressRow('m20', 'customer', 'العنوان الجديد شارع البديل', 20); },
        'later-cancellation' => static function (&$r, &$d): void { $r[] = addressRow('m20', 'customer', 'الغي الطلب', 20); },
        'later-quantity-change' => static function (&$r, &$d): void { $r[] = addressRow('m20', 'customer', 'خليه 2 كيلو فسيخ', 20); },
        'pending-staff-confirmation' => static function (&$r, &$d): void { $r[17]['text'] = 'طلبك للفريق للمراجعة والتأكيد'; },
        'fabricated-location-reference' => static function (&$r, &$d): void { $d['evidence']['location_id'] = 'm17'; },
        'extra-generated-coordinates' => static function (&$r, &$d): void { $d['customer']['latitude'] = 31.0; $d['customer']['longitude'] = 31.0; },
        'chat-price-as-uncited-total' => static function (&$r, &$d): void { $d['approximate_total'] = '820'; },
        'uncited-invented-branch' => static function (&$r, &$d): void { $d['branch_hint'] = 'فرع شبرا'; },
        'selected-catalog-size-dropped' => static function (&$r, &$d): void { $r[10]['text'] = 'كده الاوردر كيلو ونص كامل وكيلو رنجه'; },
    ];
    foreach ($guards as $label => $mutate) {
        $r = $rows; $d = $data; $mutate($r, $d);
        addressReject(addressRun($r, $d), $label);
    }
    addressReject(addressRun($rows, $data, 'fixture-model', static function (&$wire): void {
        $wire['items'][0]['name'] = 'فسيخ نبروه ممتاز';
    }), 'generated-product-name-cannot-replace-native-source');
    addressExpect(Evidence::catalogOptionLabels($data['items'][0], $data, $rows, ['كامل']), null,
        'short address cannot drop selected priced fish size during catalog matching');
    addressExpect(Evidence::catalogOptionLabels($data['items'][0], $data, $rows, ['الكيلو 3 حبه كامل']), ['الكيلو 3 حبه كامل'],
        'only exact live combined variant and preparation can match the basket');
    $connectionRows = $rows;
    $connection = addressRow('m20', 'customer', 'ااختبار ربط واتساب', 20);
    $connection['sent_at'] = '2026-10-14 12:00:00'; $connectionRows[] = $connection;
    addressSame(addressRun($connectionRows, $data)['result'], Extraction::result(true, null, $data),
        'next-day connection check does not convert earlier accepted address/order into a test');
    $testRows = $rows; $testRows[2]['text'] .= ' WA-1234';
    $testRun = addressRun($testRows, $data);
    addressExpect($testRun['calls'], 0, 'explicit coded test stays blocked before transport');
    addressExpect($testRun['result']['data']['issues'], ['TEST_MESSAGE'], 'explicit coded test remains a NONE result');

    $clock = new FragmentedAddressWorkflowClock();
    $fresh = new ReflectionMethod(Workflow::class, 'freshAutomaticEvidence');
    addressExpect($fresh->invoke($clock, $data, $rows), true, 'post-activation literal-address order has actual fresh basket commitment');
    $clock->activation = '2026-10-13 12:00:09';
    addressExpect($fresh->invoke($clock, $data, $rows), false, 'later new address/name/phone cannot freshen an old basket commitment');
    $clock->activation = '2026-10-13 12:00:12';
    addressExpect($fresh->invoke($clock, $data, $rows), false, 'late final acknowledgment cannot freshen a pre-activation recap');
    $clock->activation = '2026-10-13 12:00:03';
    addressExpect($fresh->invoke($clock, $data, $rows), true, 'exact activation instant retains genuine selected amount');
    addressExpect($fresh->invoke($clock, $data, $rows, '2026-10-13 12:00:09'), false, 'later native floor cannot use customer address as commitment');
    $clock->activation = null;
    addressExpect($fresh->invoke($clock, $data, $rows), false, 'missing activation remains fail closed');
    $clock->activation = 'invalid';
    addressExpect($fresh->invoke($clock, $data, $rows), false, 'invalid activation remains fail closed');

    foreach (['fixture-model', 'gpt-6-luna', 'other-approved-model'] as $model) {
        $run = addressRun($rows, $data, $model);
        $process = proc_open([PHP_BINARY, __FILE__, '--baseline-request', $model],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $addressRoot);
        if (!is_resource($process)) throw new RuntimeException('FAILED: baseline child unavailable');
        fclose($pipes[0]); $raw = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
        addressExpect($code, 0, 'frozen baseline child completed without live HTTP');
        addressExpect($stderr, '', 'frozen baseline child has no diagnostics or leaked fixture output');
        $baselineRequest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $newInstructions = $run['request']['instructions']; $oldInstructions = $baselineRequest['instructions'];
        unset($run['request']['instructions'], $baselineRequest['instructions']);
        addressSame($run['request'], $baselineRequest, 'request body outside instructions exactly matches previous Provider');
        addressExpect($newInstructions !== $oldInstructions, true, 'address guidance is present in the request');
        $newLines = explode("\n", $newInstructions); $oldLines = explode("\n", $oldInstructions);
        $added = array_values(array_diff($newLines, $oldLines));
        addressExpect(count($added), 1, 'one added address paragraph is the complete prompt-only scope');
        addressExpect(array_values(array_diff($oldLines, $newLines)), [], 'no previous instructions were removed or weakened');
        addressExpect(array_values(array_filter($newLines, static fn($line) => $line !== $added[0])), $oldLines,
            'removing only the added paragraph restores exact original instruction ordering');
        foreach (['literal delivery-address text', 'neighborhood and city', 'without an address label, street, building number or GPS',
            'Copy that complete literal reply into customer.address', 'cite its customer row in request_ids',
            'copy the literal city or delivery-zone phrase separately into customer.area',
            'An unrelated city mention', 'Never derive an address from customer.area',
            'never add missing address details', 'separately checks confirmation, location and dispatch readiness'] as $phrase) {
            addressExpect(strpos($added[0], $phrase) !== false, true, 'new address instruction retains explicit capture or safety condition');
        }
        addressExpect($run['request']['store'], false, 'model storage remains disabled');
        addressExpect($run['request']['stream'], false, 'streaming behavior remains unchanged');
        addressExpect($run['request']['max_output_tokens'], 8192, 'existing output budget remains unchanged');
        addressExpect($run['request']['text']['format']['strict'], true, 'strict schema remains enabled');
        addressExpect(isset($run['request']['tools']), false, 'no action tools introduced');
        addressExpect(isset($run['request']['reasoning']), $model === 'gpt-6-luna', 'model-specific reasoning policy remains unchanged');
    }
    foreach (['WhatsAppOrderConversationEvidence' => '1bd223861e3772e10658df2d555b4bb040243a4a',
        'WhatsAppOrderSourceSelection' => '03db3211b80da905f07415fd16f1e7a62e0395b1'] as $name => $blob) {
        addressExpect(addressBlob($addressRoot . '/app/Services/Dashboard/' . $name . '.php'), $blob,
            'semantic proof and source decoder stay frozen during prompt repair');
    }
    addressExpect(addressBlob($addressRoot . '/app/Support/WhatsAppOrderExtraction.php'), '56d2b9575da152de3eac4876fd93140ee7bc9e39',
        'authoritative extraction validator remains unchanged');
    addressExpect(addressBlob($addressRoot . '/app/Services/Dashboard/WhatsAppOrderWorkflow.php'), 'b1bdc5962330c5f61a8ab6fa4c640d0fcae8799d',
        'workflow, activation, catalog, GPS and routing contracts remain unchanged');
    echo 'WHATSAPP_ORDER_FRAGMENTED_ADDRESS_CHECKS=' . $checks . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    echo "WHATSAPP_ORDER_FRAGMENTED_ADDRESS_TEST_FAILED\n";
    exit(1);
}
