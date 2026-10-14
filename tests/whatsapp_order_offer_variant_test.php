<?php
// Synthetic customer data; real parser/decoder/validator and fake HTTP only.
// No Laravel bootstrap, live credentials, database, media or ERP operations.
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Services\Dashboard\WhatsAppOrderConversationEvidence as Evidence;
use App\Services\Dashboard\WhatsAppOrderSourceSelection as Selection;
use App\Support\WhatsAppOrderExtraction as Extraction;

$checks = 0;
function offerExpect($actual, $expected, string $label): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function offerCanonical($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = offerCanonical($child);
    return $value;
}
function offerSame($actual, $expected, string $label): void
{
    offerExpect(offerCanonical($actual), offerCanonical($expected), $label);
}
function offerRow(string $id, string $speaker, string $text, int $second): array
{
    return ['id' => $id, 'speaker' => $speaker,
        'sent_at' => '2026-10-13 12:00:' . sprintf('%02d', $second), 'text' => $text, 'location' => null];
}
function offerRows(): array
{
    return [
        offerRow('m1', 'customer', 'فسيخ بكام عندكم؟', 1),
        offerRow('m2', 'business', 'الكيلو 2 حبه ب 450 والكيلو 3 حبه ب 400 ج يفندم', 2),
        offerRow('m3', 'customer', 'عايز كيلو ونص من الكيلو ٣ حبه', 3),
        offerRow('m4', 'customer', 'في رنجه', 4),
        offerRow('m5', 'business', 'كامل ولا مخلي يفندم؟', 5),
        offerRow('m6', 'customer', 'كيلو ونص كامل', 6),
        offerRow('m7', 'business', 'متاح رنجه يفندم الكيلو ب 220 ج رنجه مبطرخه', 7),
        offerRow('m8', 'customer', 'تمام اعملي كيلو', 8),
        offerRow('m9', 'business', 'حاجه تانيه يفندم؟', 9),
        offerRow('m10', 'customer', 'لا تمام', 10),
        offerRow('m11', 'business', 'كده الاوردر كيلو ونص من الكيلو 3 حبه كامل وكيلو رنجه', 11),
        offerRow('m12', 'customer', 'تمام', 12),
        offerRow('m13', 'business', 'استاذنك البيانات يفندم', 13),
        offerRow('m14', 'business', 'الاور', 14),
        offerRow('m15', 'customer', 'عميل تجريبي', 15),
        offerRow('m16', 'customer', 'رقم التليفون 01000000001', 16),
        offerRow('m17', 'customer', 'مدينة التجربة المنصورة شارع المثال', 17),
        offerRow('m18', 'business', 'تم تاكيد الاورد', 18),
        offerRow('m19', 'business', 'والبيانات والعنوان', 19),
    ];
}
function offerReference(string $anchor, string $offer, string $quantity, int $clause, ?string $option = null): array
{
    return ['name_anchor_id' => $anchor, 'business_offer_id' => $offer,
        'customer_quantity_id' => $quantity, 'business_summary_id' => 'm11',
        'summary_clause_index' => $clause, 'customer_option_id' => $option];
}
function offerData(): array
{
    return ['decision' => 'CONFIRMED', 'customer' => ['name' => 'عميل تجريبي', 'phone' => '01000000001',
        'address' => 'مدينة التجربة المنصورة شارع المثال', 'area' => 'المنصورة', 'notes' => null],
        'branch_hint' => null, 'fulfillment' => 'DELIVERY', 'items' => [
            ['name' => 'فسيخ', 'quantity' => '1.5', 'quantity_mode' => 'weight', 'option_hint' => 'كامل',
                'cart_reference' => null, 'conversation_reference' => offerReference('m1', 'm2', 'm3', 0, 'm6')],
            ['name' => 'رنجه', 'quantity' => '1', 'quantity_mode' => 'weight', 'option_hint' => null,
                'cart_reference' => null, 'conversation_reference' => offerReference('m4', 'm7', 'm8', 1)],
        ], 'approximate_total' => null, 'evidence' => [
            'request_ids' => ['m1', 'm3', 'm4', 'm6', 'm8', 'm15', 'm16', 'm17'],
            'confirmation_ids' => ['m11', 'm18', 'm19'], 'customer_acceptance_ids' => ['m12'], 'location_id' => null],
        'issues' => []];
}
function offerSpan(array $input, string $id, string $word, string $source = 'text'): array
{
    foreach ($input['name_tokens'] as $record) {
        if ($record['message_id'] !== $id || $record['source'] !== $source) continue;
        foreach ($record['tokens'] as $token) if ($token['text'] === $word) {
            return ['message_id' => $id, 'source' => $source,
                'first_token' => $token['index'], 'last_token' => $token['index']];
        }
    }
    throw new RuntimeException('FAILED: native synthetic token absent');
}
function offerWire(array $input, array $data): array
{
    $wire = $data;
    foreach ($wire['items'] as &$item) {
        $ref = $item['conversation_reference'];
        if ($ref !== null) {
            $item['name_source'] = offerSpan($input, $ref['name_anchor_id'], $item['name']);
            $clauseId = null;
            foreach ($input['summary_clauses'] as $clause) {
                if ($clause['message_id'] === $ref['business_summary_id']
                    && $clause['clause_index'] === $ref['summary_clause_index']) $clauseId = $clause['id'];
            }
            if ($clauseId === null) throw new RuntimeException('FAILED: native synthetic clause absent');
            $item['conversation_reference'] = ['business_offer_id' => $ref['business_offer_id'],
                'customer_quantity_id' => $ref['customer_quantity_id'], 'summary_clause_id' => $clauseId];
            $item['option_source'] = $item['option_hint'] === null ? null
                : offerSpan($input, $ref['customer_option_id'] ?? $ref['customer_quantity_id'], $item['option_hint']);
            unset($item['name'], $item['option_hint']);
        }
    }
    unset($item);
    return $wire;
}
function offerRun(array $rows, array $data, ?callable $mutateWire = null, bool $legacy = false): array
{
    $calls = 0; $input = null; $request = null;
    $provider = new Provider(static function ($body, $key) use (&$calls, &$input, &$request, $data, $mutateWire, $legacy): array {
        $calls++;
        offerExpect($key, 'sk-offer-variant-synthetic-only', 'only a synthetic credential reaches fake transport');
        $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $input = json_decode($request['input'][0]['content'], true, 64, JSON_THROW_ON_ERROR);
        $wire = $legacy ? $data : offerWire($input, $data);
        if ($mutateWire !== null) $mutateWire($wire, $input);
        return ['status' => 200, 'body' => json_encode(['status' => 'completed', 'output' => [
            ['type' => 'message', 'role' => 'assistant', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($wire, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]],
        ]], JSON_THROW_ON_ERROR)];
    }, ['enabled' => true, 'mode' => 'auto', 'model' => 'fixture-model', 'api_key' => 'sk-offer-variant-synthetic-only']);
    $result = $provider->extract($rows);
    return ['result' => $result, 'calls' => $calls, 'input' => $input, 'request' => $request];
}
function offerReject(array $run, string $label): void
{
    offerExpect($run['calls'], 1, $label . ' makes exactly one fake call without retry');
    offerExpect($run['result']['ok'], false, $label . ' fails closed');
    offerExpect($run['result']['data'], null, $label . ' returns no accepted partial basket');
    offerExpect(in_array($run['result']['reason'], ['INVALID_EVIDENCE', 'INVALID_EXTRACTION', 'INVALID_RESPONSE'], true), true,
        $label . ' uses the original fixed rejection classifications');
}

try {
    $variants = new ReflectionMethod(Evidence::class, 'variants');
    $positiveOffers = [
        'الكيلو 2 حبه ب 450 والكيلو 3 حبه ب 400 ج يفندم',
        'الكيلو ٢ حبه ب ٤٥٠ والكيلو ٣ حبه ب ٤٠٠ ج يفندم',
        'الكيلو ۲ حبه ب ۴۵۰ والكيلو ۳ حبه ب ۴۰۰ ج يفندم',
        'الكيلو 2 حبة ب 450 وكيلو 3 حبة ب 400 ج يفندم',
        'من الكيلو 2 سمكات ب 450 ومن الكيلو 3 سمكات ب 400 ج يفندم',
        'الكيلو 2 حبات ب 450 و الكيلو 3 حبات ب 400 ج يفندم',
        'الكيلو 2 سمكه ب 450، والكيلو 3 سمكه ب 400 ج يفندم',
        'الكيلو 2 سمكة ب 450؛ والكيلو 3 سمكة ب 400 ج يفندم',
        'الكيلو 2 حبه ب 450، من الكيلو 3 حبه ب 400 ج يفندم',
        'الكيلو 2 حبه ب 450\nوالكيلو 3 حبه ب 400 ج يفندم',
    ];
    foreach ($positiveOffers as $index => $offerText) {
        $offerText = str_replace('\\n', "\n", $offerText);
        offerExpect($variants->invoke(null, $offerText), ['2', '3'], 'both native offered fish sizes recognized ' . $index);
        offerExpect(Evidence::quantities($offerText), [], 'fish sizes and prices are never sale amounts ' . $index);
        $rows = offerRows(); $rows[1]['text'] = $offerText; $data = offerData();
        offerExpect(Evidence::prove($data, $rows), ['ok' => true, 'reason' => null], 'complete fragmented order native proof ' . $index);
        $run = offerRun($rows, $data);
        offerExpect($run['calls'], 1, 'confirmed offer makes one fake provider request ' . $index);
        offerSame($run['result'], Extraction::result(true, null, $data), 'native source decoded order remains exact ' . $index);
        offerExpect($run['input']['transcript'], $rows, 'complete fragmented transcript remains unchanged ' . $index);
        offerExpect($run['request']['text']['format']['strict'], true, 'strict response schema retained ' . $index);
        offerExpect(isset($run['request']['tools']), false, 'no model action tools introduced ' . $index);
        offerExpect($run['result']['data']['items'][0]['quantity'], '1.5', 'three fish remains a size of 1.5 kg ' . $index);
        offerExpect($run['result']['data']['items'][0]['quantity_mode'], 'weight', 'sale remains by weight ' . $index);
        offerExpect($run['result']['data']['branch_hint'], null, 'parser never chooses a branch or Cairo map origin ' . $index);
        offerExpect($run['result']['data']['evidence']['location_id'], null, 'text address never invents GPS ' . $index);
    }
    foreach (['زوالكيلو 3 حبه', 'الوكيلو 3 حبه', 'ووالكيلو 3 حبه', 'abcوالكيلو 3 حبه', '1والكيلو 3 حبه',
        'و3 حبه لكل كيلو', 'الكيلو 123 حبه', 'الكيلو 0 حبه', 'الكيلو 3 حبهabc'] as $index => $text) {
        offerExpect($variants->invoke(null, $text), [], 'conjunction cannot cross arbitrary letter or number boundary ' . $index);
    }
    foreach (['2 حبات لكل كيلو و 3 حبات لكل كيلو', '2 سمكات في الكيلو؛ 3 سمكات في الكيلو',
        '2 حبه/كيلو، 3 حبه/كيلو'] as $index => $text) {
        offerExpect($variants->invoke(null, $text), ['2', '3'], 'existing number-first grammar unchanged ' . $index);
        offerExpect(Evidence::quantities($text), [], 'existing number-first sizes remain non-sale amounts ' . $index);
    }
    offerExpect($variants->invoke(null, '03حباتلكلكيلو'), ['3'], 'existing condensed number-first grammar remains compatible');
    offerExpect($variants->invoke(null, 'والكيلو ٣ حبه ب 400، الكيلو 3 حبه ب 400'), ['3'], 'same native fish size remains deduplicated');
    offerExpect(Evidence::quantities('عايز كيلو ونص من الكيلو ٣ حبه'), [['quantity' => 1500, 'mode' => 'weight']],
        'customer selected fish count is stripped before exact fractional sale amount');
    offerExpect(Evidence::quantities('عايز كيلو ونص ومن الكيلو ٣ حبه'), [['quantity' => 1500, 'mode' => 'weight']],
        'new conjunction variant is stripped before exact fractional sale amount');

    foreach (['actual-definite-name-anchor', 'first-offered-size', 'persian-selected-size', 'conjoined-selection', 'single-variant-inheritance',
        'existing-number-first-offer'] as $label) {
        $rows = offerRows(); $data = offerData();
        if ($label === 'actual-definite-name-anchor') {
            $rows[0]['text'] = 'هو الفسيخ بكم عندكم';
            $data['items'][0]['name'] = 'الفسيخ';
        }
        if ($label === 'first-offered-size') {
            $rows[2]['text'] = 'عايز كيلو ونص من الكيلو 2 حبه';
            $rows[10]['text'] = 'كده الاوردر كيلو ونص من الكيلو 2 حبه كامل وكيلو رنجه';
        }
        if ($label === 'persian-selected-size') $rows[2]['text'] = 'عايز كيلو ونص من الكيلو ۳ حبه';
        if ($label === 'conjoined-selection') $rows[2]['text'] = 'عايز كيلو ونص ومن الكيلو ٣ حبه';
        if ($label === 'single-variant-inheritance') {
            $rows[1]['text'] = 'الكيلو 3 حبه ب 400 ج يفندم';
            $rows[2]['text'] = 'عايز كيلو ونص';
        }
        if ($label === 'existing-number-first-offer') $rows[1]['text'] = '2 حبات لكل كيلو ب 450 و 3 حبات لكل كيلو ب 400 ج يفندم';
        $run = offerRun($rows, $data);
        offerSame($run['result'], Extraction::result(true, null, $data), $label . ' retains full positive semantic proof');
        offerExpect($run['calls'], 1, $label . ' uses one fake request');
        offerExpect($run['result']['data']['items'][0]['quantity'], '1.5', $label . ' cannot alter sale amount');
    }

    $guards = [
        'unoffered-four-fish' => static function (&$rows, &$data): void {
            $rows[2]['text'] = 'عايز كيلو ونص من الكيلو 4 حبه';
            $rows[10]['text'] = 'كده الاوردر كيلو ونص من الكيلو 4 حبه كامل وكيلو رنجه';
        },
        'mismatched-recap-size' => static function (&$rows, &$data): void {
            $rows[10]['text'] = 'كده الاوردر كيلو ونص من الكيلو 2 حبه كامل وكيلو رنجه';
        },
        'ambiguous-selected-sizes' => static function (&$rows, &$data): void {
            $rows[2]['text'] = 'عايز كيلو ونص من الكيلو 2 حبه والكيلو 3 حبه';
        },
        'unselected-offered-alternatives' => static function (&$rows, &$data): void {
            $rows[2]['text'] = 'عايز كيلو ونص';
        },
        'choice-is-a-question' => static function (&$rows, &$data): void {
            $rows[2]['text'] = 'الكيلو 3 حبه بكم؟';
        },
        'negative-selected-quantity' => static function (&$rows, &$data): void {
            $rows[2]['text'] = 'مش عايز كيلو ونص من الكيلو 3 حبه';
        },
        'alternative-customer-selection' => static function (&$rows, &$data): void {
            $rows[2]['text'] = 'عايز كيلو ونص من الكيلو 3 حبه أو الكيلو 2 حبه';
        },
        'fish-count-as-sale-weight' => static function (&$rows, &$data): void { $data['items'][0]['quantity'] = '3'; },
        'fish-count-as-sale-count' => static function (&$rows, &$data): void {
            $data['items'][0]['quantity'] = '3'; $data['items'][0]['quantity_mode'] = 'count';
        },
        'different-sale-amount' => static function (&$rows, &$data): void { $data['items'][0]['quantity'] = '2'; },
        'later-cancellation' => static function (&$rows, &$data): void { $rows[] = offerRow('m20', 'customer', 'الغي الطلب', 20); },
        'later-quantity-change' => static function (&$rows, &$data): void { $rows[] = offerRow('m20', 'customer', 'خليه 2 كيلو فسيخ', 20); },
        'later-address-change' => static function (&$rows, &$data): void { $rows[] = offerRow('m20', 'customer', 'العنوان الجديد شارع البديل', 20); },
        'later-pending-handoff' => static function (&$rows, &$data): void { $rows[] = offerRow('m20', 'business', 'طلبك للفريق للمراجعة والتأكيد', 20); },
        'separate-next-order' => static function (&$rows, &$data): void {
            $rows[] = offerRow('m20', 'customer', 'عايز طلب جديد 1 كيلو رنجه', 20);
            $rows[] = offerRow('m21', 'business', 'تم تأكيد الطلب الجديد: 1 كيلو رنجه', 21);
        },
        'no-final-data-ack' => static function (&$rows, &$data): void { $rows[18]['text'] = 'شكرا'; },
        'no-customer-acceptance' => static function (&$rows, &$data): void { $data['evidence']['customer_acceptance_ids'] = []; },
        'customer-option-negated' => static function (&$rows, &$data): void { $rows[5]['text'] = 'كيلو ونص مش كامل'; },
        'uncited-positive-quantity' => static function (&$rows, &$data): void {
            $data['evidence']['request_ids'] = array_values(array_diff($data['evidence']['request_ids'], ['m3']));
        },
        'uncited-positive-option' => static function (&$rows, &$data): void {
            $data['evidence']['request_ids'] = array_values(array_diff($data['evidence']['request_ids'], ['m6']));
        },
        'made-up-location-reference' => static function (&$rows, &$data): void { $data['evidence']['location_id'] = 'm17'; },
        'business-data-as-customer' => static function (&$rows, &$data): void { $rows[14]['speaker'] = 'business'; },
        'wrong-fish-topic-anchor' => static function (&$rows, &$data): void { $rows[0]['text'] = 'سردين أو فسيخ بكام؟'; },
        'actual-cart-not-accounted' => static function (&$rows, &$data): void {
            $rows[0]['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1',
                'product_items' => [['product_retailer_id' => 'retailer-synthetic', 'quantity' => 1,
                    'item_price' => '1.00', 'currency' => 'EGP']]]);
        },
    ];
    foreach ($guards as $label => $mutate) {
        $rows = offerRows(); $data = offerData(); $mutate($rows, $data);
        offerReject(offerRun($rows, $data), $label);
    }
    // Native source syntax remains strict even though the narrow price grammar expands.
    foreach (['business-quantity', 'duplicate-summary', 'cart-and-context', 'foreign-cart-name', 'invented-name',
        'uncited-legacy-anchor'] as $label) {
        $run = offerRun(offerRows(), offerData(), static function (&$wire, $input) use ($label): void {
            if ($label === 'business-quantity') $wire['items'][0]['conversation_reference']['customer_quantity_id'] = 'm2';
            if ($label === 'duplicate-summary') $wire['items'][1]['conversation_reference']['summary_clause_id'] = $wire['items'][0]['conversation_reference']['summary_clause_id'];
            if ($label === 'cart-and-context') $wire['items'][0]['cart_reference'] = ['message_id' => 'm1', 'product_retailer_id' => 'retailer-synthetic'];
            if ($label === 'foreign-cart-name') $wire['items'][0]['name_source']['source'] = 'cart_text';
            if ($label === 'invented-name') $wire['items'][0]['name_source']['last_token'] = 999;
            if ($label === 'uncited-legacy-anchor') {
                $wire = offerData();
                $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m1']));
            }
        });
        offerReject($run, $label);
    }
    $legacy = offerRun(offerRows(), offerData(), null, true);
    offerSame($legacy['result'], Extraction::result(true, null, offerData()), 'fully grounded historical DTO remains compatible');
    offerExpect($legacy['calls'], 1, 'historical DTO performs one fake call');
    $connectionRows = offerRows();
    $connection = offerRow('m20', 'customer', 'ااختبار ربط واتساب', 20);
    $connection['sent_at'] = '2026-10-14 12:00:00'; $connectionRows[] = $connection;
    offerExpect(Evidence::operationalTest($connectionRows), false, 'later connection check does not cancel a previous real order');
    offerSame(offerRun($connectionRows, offerData())['result'], Extraction::result(true, null, offerData()),
        'later connection check leaves prior proved order intact');
    $testRows = offerRows(); $testRows[2]['text'] .= ' WA-1234';
    $testRun = offerRun($testRows, offerData());
    offerExpect($testRun['calls'], 0, 'explicit coded test remains blocked before even fake HTTP');
    offerExpect($testRun['result']['data']['issues'], ['TEST_MESSAGE'], 'explicit coded test keeps original classification');
    echo 'WHATSAPP_ORDER_OFFER_VARIANT_CHECKS=' . $checks . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    echo "WHATSAPP_ORDER_OFFER_VARIANT_TEST_FAILED\n";
    exit(1);
}
