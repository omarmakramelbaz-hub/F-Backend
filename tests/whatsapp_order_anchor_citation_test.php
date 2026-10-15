<?php
// Synthetic evidence and fake HTTP only. Actual pure provider, decoder and validators;
// no Laravel bootstrap, credentials, database, network, catalog mutation or ERP actions.
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
function sourceExpect($actual, $expected, string $label): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function sourceCanonical($value)
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = sourceCanonical($child);
    return $value;
}
function sourceSame($actual, $expected, string $label): void
{
    sourceExpect(sourceCanonical($actual), sourceCanonical($expected), $label);
}
function sourceRow(string $id, string $speaker, string $text, int $second): array
{
    return ['id' => $id, 'speaker' => $speaker,
        'sent_at' => '2026-10-13 12:00:' . sprintf('%02d', $second), 'text' => $text, 'location' => null];
}
function sourceRows(): array
{
    return [
        sourceRow('m1', 'customer', 'الفسيخ بكام؟', 1),
        sourceRow('m2', 'business', 'فسيخ من الكيلو 3 حبه بسعر 300 جنيه، ومن الكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟', 2),
        sourceRow('m3', 'customer', 'عايز كيلو ونص من الكيلو 3 حبه', 3),
        sourceRow('m4', 'business', 'تمام', 4),
        sourceRow('m5', 'customer', 'في رنجه', 5),
        sourceRow('m6', 'business', 'رنجه بسعر 220 جنيه للكيلو. تحب قد إيه؟', 6),
        sourceRow('m7', 'customer', 'تمام اعملي كيلو', 7),
        sourceRow('m8', 'business', 'تحب الفسيخ كامل ولا تنظيف؟', 8),
        sourceRow('m9', 'customer', 'كيلو ونص كامل', 9),
        sourceRow('m10', 'business', 'طلبك: 1.5 كيلو فسيخ من الكيلو 3 حبه كامل؛ 1 كيلو رنجه', 10),
        sourceRow('m11', 'customer', 'تمام موافق', 11),
        sourceRow('m12', 'business', 'ابعت الاسم ورقم الهاتف والعنوان', 12),
        sourceRow('m13', 'customer', 'عميل تجريبي', 13),
        sourceRow('m14', 'customer', '01000000001', 14),
        sourceRow('m15', 'customer', 'مدينة التجربة المنصورة شارع المثال', 15),
        sourceRow('m16', 'business', 'تم استلام الاسم ورقم الهاتف والعنوان وتأكيد الطلب', 16),
    ];
}
function sourceReference(string $anchor, string $offer, string $quantity, int $clause, ?string $option = null): array
{
    return ['name_anchor_id' => $anchor, 'business_offer_id' => $offer,
        'customer_quantity_id' => $quantity, 'business_summary_id' => 'm10',
        'summary_clause_index' => $clause, 'customer_option_id' => $option];
}
function sourceData(): array
{
    return ['decision' => 'CONFIRMED', 'customer' => ['name' => 'عميل تجريبي', 'phone' => '01000000001',
        'address' => 'مدينة التجربة المنصورة شارع المثال', 'area' => 'المنصورة', 'notes' => null],
        'branch_hint' => null, 'fulfillment' => 'DELIVERY', 'items' => [
            ['name' => 'فسيخ', 'quantity' => '1.5', 'quantity_mode' => 'weight', 'option_hint' => 'كامل',
                'cart_reference' => null, 'conversation_reference' => sourceReference('m1', 'm2', 'm3', 0, 'm9')],
            ['name' => 'رنجه', 'quantity' => '1', 'quantity_mode' => 'weight', 'option_hint' => null,
                'cart_reference' => null, 'conversation_reference' => sourceReference('m5', 'm6', 'm7', 1)],
        ], 'approximate_total' => null, 'evidence' => [
            'request_ids' => ['m1', 'm3', 'm5', 'm7', 'm9', 'm13', 'm14', 'm15'],
            'confirmation_ids' => ['m10', 'm16'], 'customer_acceptance_ids' => ['m11'], 'location_id' => null],
        'issues' => []];
}
function sourceConfig(): array
{
    return ['enabled' => true, 'mode' => 'auto', 'model' => 'fixture-model',
        'api_key' => 'sk-source-selection-private-fixture-key'];
}
function sourceResponse(array $data): array
{
    return ['status' => 200, 'body' => json_encode(['status' => 'completed', 'output' => [
        ['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]]],
    ]], JSON_THROW_ON_ERROR)];
}
/** One callback transport attempt; return the actual request and result for structural checks. */
function sourceExtract(array $rows, callable $respond): array
{
    $calls = 0; $request = null; $input = null; $requestBytes = null;
    $provider = new Provider(static function ($body, $key) use (&$calls, &$request, &$input, &$requestBytes, $respond): array {
        $calls++;
        $requestBytes = strlen($body);
        $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $input = json_decode($request['input'][0]['content'], true, 64, JSON_THROW_ON_ERROR);
        sourceExpect($key, 'sk-source-selection-private-fixture-key', 'fake transport receives only synthetic configuration');
        return sourceResponse($respond($input, $request));
    }, sourceConfig());
    $result = $provider->extract($rows);
    return ['result' => $result, 'calls' => $calls, 'request' => $request, 'input' => $input, 'bytes' => $requestBytes];
}
function sourceRejected(array $run, string $label): void
{
    sourceExpect($run['calls'], 1, $label . ' performs at most one fake HTTP call');
    sourceExpect($run['result']['ok'], false, $label . ' rejects before any downstream order action');
    sourceExpect($run['result']['data'], null, $label . ' never returns a repaired basket');
    sourceExpect(in_array($run['result']['reason'], ['INVALID_EVIDENCE', 'INVALID_EXTRACTION', 'INVALID_RESPONSE'], true),
        true, $label . ' returns a fixed fail-closed reason');
}

/** Derive an expected byte span from the original UTF-8 string, not from output text. */
function sourceNativeSubstring(string $text, int $start, int $end): string
{
    sourceExpect($start >= 0 && $end > $start && $end <= strlen($text), true, 'native byte span is bounded');
    $copied = substr($text, $start, $end - $start);
    sourceExpect(preg_match('//u', $copied), 1, 'native byte span retains whole UTF-8 code points');
    return $copied;
}
function sourceDraft(string $name, string $request = 'm1'): array
{
    $data = Extraction::none('UNCONFIRMED');
    $data['decision'] = 'DRAFT';
    $data['items'] = [['name' => $name, 'quantity' => null, 'quantity_mode' => 'unknown',
        'option_hint' => null, 'cart_reference' => null, 'conversation_reference' => null]];
    $data['evidence']['request_ids'] = [$request];
    return $data;
}
function sourceLabels(array $rows, string $speaker, bool $locations = false): array
{
    return array_values(array_map(static fn ($row) => $row['id'], array_filter($rows,
        static fn ($row) => $row['speaker'] === $speaker && ($locations ? $row['location'] !== null : trim($row['text'] ?? '') !== ''))));
}
function sourceNoHttp(array $rows, string $label, ?string $reason = null): array
{
    $run = sourceExtract($rows, static function (): array {
        throw new RuntimeException('This invalid input must not reach even fake HTTP.');
    });
    sourceExpect($run['calls'], 0, $label . ' rejects before any HTTP');
    sourceExpect($run['result']['ok'], false, $label . ' never silently truncates');
    sourceExpect($run['result']['data'], null, $label . ' has no decoded output');
    if ($reason !== null) sourceExpect($run['result']['reason'], $reason, $label . ' fixed input classification');
    return $run;
}

function sourceTokens(array $input, string $message, string $kind = 'text'): array
{
    foreach ($input['name_tokens'] as $source) {
        if ($source['message_id'] === $message && $source['source'] === $kind) return $source['tokens'];
    }
    throw new RuntimeException('Synthetic native text source is absent.');
}
/** Select actual offered token indices; never provide generated product text. */
function sourceSpan(array $input, string $message, string $first, ?string $last = null, string $kind = 'text'): array
{
    $tokens = sourceTokens($input, $message, $kind); $firstIndex = null; $lastIndex = null;
    foreach ($tokens as $token) {
        if ($firstIndex === null && $token['text'] === $first) $firstIndex = $token['index'];
        if ($firstIndex !== null && $token['text'] === ($last ?? $first)) $lastIndex = $token['index'];
    }
    if ($firstIndex === null || $lastIndex === null) throw new RuntimeException('Synthetic literal token selection is absent.');
    return ['message_id' => $message, 'source' => $kind, 'first_token' => $firstIndex, 'last_token' => $lastIndex];
}
function sourceClause(array $input, string $message, int $clause): string
{
    foreach ($input['summary_clauses'] as $choice) {
        if ($choice['message_id'] === $message && $choice['clause_index'] === $clause) return $choice['id'];
    }
    throw new RuntimeException('Synthetic actual business clause is absent.');
}
function sourceWire(array $input, ?array $data = null): array
{
    $wire = sourceWireOptionFields($data ?? sourceData(), $input);
    foreach ($wire['items'] as $index => &$item) {
        $anchor = $item['conversation_reference']['name_anchor_id'] ?? $wire['evidence']['request_ids'][0];
        $item['name_source'] = sourceSpan($input, $anchor, $item['name']);
        unset($item['name']);
        if ($item['conversation_reference'] !== null) {
            $reference = $item['conversation_reference'];
            $item['conversation_reference'] = [
                'business_offer_id' => $reference['business_offer_id'],
                'customer_quantity_id' => $reference['customer_quantity_id'],
                'summary_clause_id' => sourceClause($input, $reference['business_summary_id'], $reference['summary_clause_index']),
            ];
        }
    }
    unset($item);
    return sourceWireOptionFields($wire, $input);
}
/** Convert only explicitly prepared new wire fixtures; legacy DTO callbacks stay untouched. */
function sourceWireOptionFields(array $wire, array $input): array
{
    foreach ($wire['items'] as &$item) {
        if (!array_key_exists('option_hint', $item)) continue;
        $hint = $item['option_hint'];
        $item['option_source'] = null;
        if ($hint !== null) {
            $reference = $item['conversation_reference'];
            $message = $reference['customer_option_id'] ?? $reference['customer_quantity_id'] ?? null;
            if (!is_string($message)) throw new RuntimeException('Synthetic non-null option requires an explicit actual source row.');
            $words = preg_split('/\s+/u', $hint);
            $item['option_source'] = sourceSpan($input, $message, $words[0], $words[count($words) - 1]);
        }
        unset($item['option_hint']);
    }
    unset($item);
    return $wire;
}
function sourceEnumMembers(array $schema): int
{
    $total = isset($schema['enum']) && is_array($schema['enum']) ? count($schema['enum']) : 0;
    foreach ($schema as $key => $value) {
        if ($key === 'enum' || !is_array($value)) continue;
        $total += sourceEnumMembers($value);
    }
    return $total;
}
function sourceObjectBranches(array $schema): array
{
    return $schema['anyOf'] ?? [$schema];
}
function sourceAssertObject(array $schema, array $fields, string $label): void
{
    sourceExpect($schema['type'], 'object', $label . ' is an object');
    sourceExpect($schema['additionalProperties'], false, $label . ' rejects unoffered fields');
    $required = $schema['required']; $properties = array_keys($schema['properties']);
    sort($fields); sort($required); sort($properties);
    sourceExpect($required, $fields, $label . ' requires exactly the offered fields');
    sourceExpect($properties, $fields, $label . ' exposes exactly the offered fields');
}
function sourceShifted(array $rows, array $data, int $offset): array
{
    $shift = static fn (string $id): string => 'm' . ((int) substr($id, 1) + $offset);
    foreach ($rows as &$row) {
        $number = (int) substr($row['id'], 1) + $offset;
        $row['id'] = $shift($row['id']);
        $row['sent_at'] = '2026-10-13 12:00:' . sprintf('%02d', $number);
    }
    unset($row);
    foreach (['request_ids', 'confirmation_ids', 'customer_acceptance_ids'] as $field) {
        $data['evidence'][$field] = array_map($shift, $data['evidence'][$field]);
    }
    if ($data['evidence']['location_id'] !== null) $data['evidence']['location_id'] = $shift($data['evidence']['location_id']);
    foreach ($data['items'] as &$item) {
        foreach (['name_anchor_id', 'business_offer_id', 'customer_quantity_id', 'business_summary_id', 'customer_option_id'] as $field) {
            if ($item['conversation_reference'][$field] !== null) $item['conversation_reference'][$field] = $shift($item['conversation_reference'][$field]);
        }
    }
    unset($item);
    return [$rows, $data];
}
/** Forty rows, 200 actual business clauses and five bounded normalized carts. */
function sourceEnumBudgetRows(int $lineTotal): array
{
    $rows = []; $next = 1; $remaining = $lineTotal;
    for ($i = 1; $i <= 30; $i++) {
        $row = sourceRow('m' . $i, 'customer', 'ز', 1);
        $row['location'] = ['lat' => 0, 'long' => 0];
        if ($i <= 5) {
            $count = intdiv($remaining, 6 - $i); $remaining -= $count; $lines = [];
            for ($j = 0; $j < $count; $j++) {
                $lines[] = ['product_retailer_id' => 'r' . $next++, 'quantity' => 1, 'item_price' => '1.00', 'currency' => 'EGP'];
            }
            $row['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1', 'text' => 'ز', 'product_items' => $lines]);
        }
        $rows[] = $row;
    }
    for ($i = 31; $i <= 40; $i++) {
        $rows[] = sourceRow('m' . $i, 'business', implode('؛', array_fill(0, 20, 'ز')), 1);
    }
    return $rows;
}

/** Omit only the redundant inquiry-name citations proved by explicit source selection. */
function anchorWire(array $input, ?array $data = null): array
{
    $wire = sourceWire($input, $data);
    $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m1', 'm5']));
    return $wire;
}
function anchorExpected(): array
{
    $data = sourceData();
    $data['evidence']['request_ids'] = ['m3', 'm7', 'm9', 'm13', 'm14', 'm15', 'm1', 'm5'];
    return $data;
}
function anchorDecodeReject(array $wire, array $plan, string $label): void
{
    $decoded = Selection::decode($wire, $plan);
    sourceExpect($decoded['ok'], false, $label . ' never accepts invalid source or citation');
    sourceExpect($decoded['data'], null, $label . ' returns no partially modified evidence');
}

try {
    $rows = sourceRows(); $data = sourceData(); $plan = Selection::build($rows)['data'];
    sourceExpect(Extraction::validateDetailed($data, $rows)['ok'], true, 'synthetic fragmented order baseline is confirmed');
    $wire = anchorWire($plan['input']); $beforeWire = $wire; $beforePlan = $plan;
    $expected = anchorExpected();
    $decoded = Selection::decode($wire, $plan);
    sourceSame($decoded, Extraction::result(true, null, $expected), 'decoder derives only the two explicit contextual anchor citations');
    sourceExpect($wire, $beforeWire, 'decoding does not mutate the supplied wire or citations');
    sourceExpect($plan, $beforePlan, 'decoding does not mutate the native transcript, source table or schema');
    sourceExpect(Extraction::validateDetailed($decoded['data'], $rows)['ok'], true, 'unchanged authoritative validator approves every fragmented fact');
    sourceExpect(Evidence::prove($decoded['data'], array_column($rows, null, 'id')), ['ok' => true, 'reason' => null],
        'independent quantity, recap, acceptance and customer-data proof remains required');
    sourceExpect($decoded['data']['evidence']['confirmation_ids'], $wire['evidence']['confirmation_ids'], 'no confirmation citation is derived');
    sourceExpect($decoded['data']['evidence']['customer_acceptance_ids'], $wire['evidence']['customer_acceptance_ids'], 'no acceptance citation is derived');
    sourceExpect($decoded['data']['evidence']['location_id'], null, 'no location fact is derived');
    foreach ($decoded['data']['items'] as $i => $item) {
        sourceSame($item, $data['items'][$i], 'exact source-derived native item facts and references are preserved');
    }
    $run = sourceExtract($rows, static fn ($input) => anchorWire($input));
    sourceExpect($run['calls'], 1, 'production-shaped anchor omission requires exactly one fake transport call');
    sourceSame($run['result'], Extraction::result(true, null, $expected), 'actual Provider normalizes the redundant anchor before unchanged validation');
    sourceExpect($run['input']['transcript'], $rows, 'actual Provider sends all original fragmented rows unchanged');
    sourceExpect($run['request']['text']['format']['schema'], $plan['schema'], 'actual Provider retains strict native-source response schema');
    sourceExpect($run['request']['text']['format']['strict'], true, 'schema remains strict');
    sourceExpect([$run['request']['store'], $run['request']['stream']], [false, false], 'request retains non-stored complete response semantics');
    sourceExpect(isset($run['request']['tools']), false, 'provider cannot dispatch an order or call a tool');
    sourceExpect($run['result']['data']['branch_hint'], null, 'name citation never chooses a branch or Cairo map origin');
    sourceExpect($run['result']['data']['items'][0]['quantity'], '1.5', 'three fish per kilogram never becomes three kilograms');
    sourceExpect($run['result']['data']['customer'], $data['customer'], 'no customer name, phone or address is synthesized');
    $committing = Extraction::committingRequestIds($run['result']['data'], $rows);
    sourceExpect($committing, Extraction::committingRequestIds($data, $rows), 'fresh committing request identifiers remain exactly the original positive selections');
    sourceExpect(in_array('m1', $committing, true), false, 'derived price-question name anchor never becomes fresh automatic commitment');
    sourceExpect(in_array('m5', $committing, true), false, 'derived availability-inquiry name anchor never becomes fresh automatic commitment');

    // A already-cited anchor is retained in its exact position, never duplicated or sorted.
    $already = Selection::decode(sourceWire($plan['input']), $plan);
    sourceSame($already, Extraction::result(true, null, $data), 'complete original citations remain byte-for-byte identical');
    $oneMissing = sourceWire($plan['input']);
    $oneMissing['evidence']['request_ids'] = ['m5', 'm3', 'm7', 'm9', 'm13', 'm14', 'm15'];
    $oneDecoded = Selection::decode($oneMissing, $plan);
    sourceExpect($oneDecoded['data']['evidence']['request_ids'], ['m5', 'm3', 'm7', 'm9', 'm13', 'm14', 'm15', 'm1'],
        'existing request citation order is conserved and only the missing anchor is appended');
    $sameAnchor = anchorWire($plan['input']);
    $sameAnchor['items'][1]['name_source'] = $sameAnchor['items'][0]['name_source'];
    $sameDecoded = Selection::decode($sameAnchor, $plan);
    sourceExpect($sameDecoded['ok'], true, 'decoder can copy the same native anchor more than once without approving its meaning');
    sourceExpect(array_count_values($sameDecoded['data']['evidence']['request_ids'])['m1'], 1,
        'shared contextual native anchor is appended exactly once across all items');
    sourceExpect(Extraction::validateDetailed($sameDecoded['data'], $rows)['ok'], false,
        'derived citation never permits one anchor to fabricate a different ordered item');

    // Raw evidence is validated before additions. Nothing repairs malformed or foreign lists.
    foreach (['missing-evidence', 'null-evidence', 'extra-evidence-key', 'missing-request-list', 'null-request-list',
        'string-request-list', 'associative-request-list', 'duplicate-request', 'unknown-request', 'nonstring-request',
        'business-request', 'missing-confirmation-list', 'null-confirmation-list', 'duplicate-confirmation',
        'customer-confirmation', 'missing-acceptance-list', 'null-acceptance-list', 'duplicate-acceptance',
        'business-acceptance', 'foreign-location', 'string-list-index'] as $case) {
        $malformed = anchorWire($plan['input']);
        if ($case === 'missing-evidence') unset($malformed['evidence']);
        if ($case === 'null-evidence') $malformed['evidence'] = null;
        if ($case === 'extra-evidence-key') $malformed['evidence']['name_source_ids'] = ['m1'];
        if ($case === 'missing-request-list') unset($malformed['evidence']['request_ids']);
        if ($case === 'null-request-list') $malformed['evidence']['request_ids'] = null;
        if ($case === 'string-request-list') $malformed['evidence']['request_ids'] = 'm3';
        if ($case === 'associative-request-list') $malformed['evidence']['request_ids'] = ['name' => 'm3'];
        if ($case === 'duplicate-request') $malformed['evidence']['request_ids'][] = 'm3';
        if ($case === 'unknown-request') $malformed['evidence']['request_ids'][] = 'm999';
        if ($case === 'nonstring-request') $malformed['evidence']['request_ids'][] = 3;
        if ($case === 'business-request') $malformed['evidence']['request_ids'][] = 'm2';
        if ($case === 'missing-confirmation-list') unset($malformed['evidence']['confirmation_ids']);
        if ($case === 'null-confirmation-list') $malformed['evidence']['confirmation_ids'] = null;
        if ($case === 'duplicate-confirmation') $malformed['evidence']['confirmation_ids'][] = 'm10';
        if ($case === 'customer-confirmation') $malformed['evidence']['confirmation_ids'][] = 'm11';
        if ($case === 'missing-acceptance-list') unset($malformed['evidence']['customer_acceptance_ids']);
        if ($case === 'null-acceptance-list') $malformed['evidence']['customer_acceptance_ids'] = null;
        if ($case === 'duplicate-acceptance') $malformed['evidence']['customer_acceptance_ids'][] = 'm11';
        if ($case === 'business-acceptance') $malformed['evidence']['customer_acceptance_ids'][] = 'm16';
        if ($case === 'foreign-location') $malformed['evidence']['location_id'] = 'm999';
        if ($case === 'string-list-index') $malformed['evidence']['request_ids'] = ['3' => 'm3'];
        $original = $malformed;
        anchorDecodeReject($malformed, $plan, $case);
        sourceExpect($malformed, $original, $case . ' cannot mutate or normalize raw evidence');
        $bad = sourceExtract($rows, static fn () => $malformed);
        sourceRejected($bad, $case . ' actual Provider fails closed without retry');
    }

    // Valid list syntax does not prove a committing quantity, option or confirmation.
    foreach (['empty-request', 'uncited-quantity', 'uncited-option', 'uncited-summary', 'empty-acceptance',
        'missing-name-data', 'missing-phone-data', 'missing-address-data'] as $case) {
        $bad = sourceExtract($rows, static function ($input) use ($case): array {
            $wire = anchorWire($input);
            if ($case === 'empty-request') $wire['evidence']['request_ids'] = [];
            if ($case === 'uncited-quantity') $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m3']));
            if ($case === 'uncited-option') $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m9']));
            if ($case === 'uncited-summary') $wire['evidence']['confirmation_ids'] = ['m16'];
            if ($case === 'empty-acceptance') $wire['evidence']['customer_acceptance_ids'] = [];
            if ($case === 'missing-name-data') $wire['customer']['name'] = null;
            if ($case === 'missing-phone-data') $wire['customer']['phone'] = null;
            if ($case === 'missing-address-data') $wire['customer']['address'] = null;
            return $wire;
        });
        if (in_array($case, ['missing-name-data', 'missing-phone-data', 'missing-address-data'], true)) {
            sourceExpect($bad['calls'], 1, $case . ' uses one fake request');
            sourceExpect($bad['result']['ok'] === false || $bad['result']['data']['decision'] !== 'CONFIRMED', true,
                $case . ' cannot become a confirmed order by deriving a name citation');
        } else sourceRejected($bad, $case . ' never gets a non-name citation derived');
    }
    $quantityWire = anchorWire($plan['input']);
    $quantityWire['evidence']['request_ids'] = array_values(array_diff($quantityWire['evidence']['request_ids'], ['m3']));
    $quantityDecoded = Selection::decode($quantityWire, $plan);
    sourceExpect($quantityDecoded['ok'], false, 'uncited committing quantity fails before deriving any anchor');
    sourceExpect($quantityDecoded['data'], null, 'decoder never adds a committing-quantity citation or returns partial facts');

    // Choosing a quantity or acceptance row as the name is not a proof of that quantity.
    $sameNameQuantity = anchorWire($plan['input']);
    $sameNameQuantity['items'][0]['name_source'] = sourceSpan($plan['input'], 'm3', 'كيلو');
    $sameNameQuantity['evidence']['request_ids'] = array_values(array_diff($sameNameQuantity['evidence']['request_ids'], ['m3']));
    $sameNameQuantityDecoded = Selection::decode($sameNameQuantity, $plan);
    sourceExpect($sameNameQuantityDecoded['ok'], false, 'uncited quantity cannot be autocited incidentally by selecting it as a name');
    sourceExpect($sameNameQuantityDecoded['data'], null, 'same-row quantity and name have no partially augmented result');
    $sameNameOption = anchorWire($plan['input']);
    $sameNameOption['items'][0]['name_source'] = sourceSpan($plan['input'], 'm9', 'كامل');
    $sameNameOption['evidence']['request_ids'] = array_values(array_diff($sameNameOption['evidence']['request_ids'], ['m9']));
    $sameNameOptionDecoded = Selection::decode($sameNameOption, $plan);
    sourceExpect($sameNameOptionDecoded['ok'], false, 'uncited option cannot be autocited incidentally by selecting it as a name');
    sourceExpect($sameNameOptionDecoded['data'], null, 'same-row option and name return no partially augmented result');

    foreach (['business-name', 'unknown-name', 'foreign-source', 'negative-token', 'out-of-range-token', 'string-token',
        'legacy-extra-name', 'extra-context-anchor', 'cart-and-context', 'unknown-clause', 'changed-customer-anchor'] as $case) {
        $bad = sourceExtract($rows, static function ($input) use ($case): array {
            $wire = anchorWire($input);
            if ($case === 'business-name') $wire['items'][0]['name_source'] = sourceSpan($input, 'm2', 'فسيخ');
            if ($case === 'unknown-name') $wire['items'][0]['name_source']['message_id'] = 'm999';
            if ($case === 'foreign-source') $wire['items'][0]['name_source']['source'] = 'cart_text';
            if ($case === 'negative-token') $wire['items'][0]['name_source']['first_token'] = -1;
            if ($case === 'out-of-range-token') $wire['items'][0]['name_source']['last_token'] = 999;
            if ($case === 'string-token') $wire['items'][0]['name_source']['first_token'] = '0';
            if ($case === 'legacy-extra-name') $wire['items'][0]['name'] = 'فسيخ';
            if ($case === 'extra-context-anchor') $wire['items'][0]['conversation_reference']['name_anchor_id'] = 'm1';
            if ($case === 'cart-and-context') $wire['items'][0]['cart_reference'] = ['message_id' => 'm1', 'product_retailer_id' => 'r1'];
            if ($case === 'unknown-clause') $wire['items'][0]['conversation_reference']['summary_clause_id'] = 'clause999';
            if ($case === 'changed-customer-anchor') $wire['items'][0]['name_source'] = sourceSpan($input, 'm13', 'عميل');
            return $wire;
        });
        sourceRejected($bad, $case . ' is not repaired by anchor derivation');
    }

    // No generalized autocitation is introduced for ordinary, cart or legacy replies.
    $ordinary = sourceDraft('فسيخ'); $ordinary['evidence']['request_ids'] = [];
    $ordinaryWire = sourceWireOptionFields($ordinary, $plan['input']);
    unset($ordinaryWire['items'][0]['name']); $ordinaryWire['items'][0]['name_source'] = sourceSpan($plan['input'], 'm1', 'فسيخ');
    $ordinaryDecoded = Selection::decode($ordinaryWire, $plan);
    sourceExpect($ordinaryDecoded['data']['evidence']['request_ids'], [], 'ordinary native name cannot manufacture a request citation');
    $ordinaryRun = sourceExtract($rows, static fn () => $ordinaryWire);
    sourceRejected($ordinaryRun, 'uncited ordinary source');
    sourceExpect($ordinaryRun['result']['diagnostic']['validation_field'], 'ITEM_NAME', 'ordinary literal grounding remains authoritative');
    $legacy = sourceData(); $legacy['evidence']['request_ids'] = array_values(array_diff($legacy['evidence']['request_ids'], ['m1', 'm5']));
    $legacyDecoded = Selection::decode($legacy, $plan);
    sourceExpect($legacyDecoded['data'], $legacy, 'legacy free-form response receives no implied source citation');
    sourceRejected(sourceExtract($rows, static fn () => $legacy), 'uncited legacy context anchor');
    $legacyGood = sourceExtract($rows, static fn () => sourceData());
    sourceSame($legacyGood['result'], Extraction::result(true, null, $data), 'fully validated historical DTO compatibility remains intact');

    $cartRows = [sourceRow('m1', 'customer', 'فسيخ', 1)];
    $cartRows[0]['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1', 'text' => 'فسيخ', 'product_items' => [
        ['product_retailer_id' => 'r1', 'quantity' => 1, 'item_price' => '1.00', 'currency' => 'EGP']]]);
    $cartPlan = Selection::build($cartRows)['data'];
    $cart = sourceDraft('فسيخ'); $cart['evidence']['request_ids'] = [];
    $cart['items'][0]['cart_reference'] = ['message_id' => 'm1', 'product_retailer_id' => 'r1'];
    $cartWire = sourceWireOptionFields($cart, $cartPlan['input']); unset($cartWire['items'][0]['name']);
    $cartWire['items'][0]['name_source'] = sourceSpan($cartPlan['input'], 'm1', 'فسيخ', null, 'cart_text');
    $cartDecoded = Selection::decode($cartWire, $cartPlan);
    sourceExpect($cartDecoded['data']['evidence']['request_ids'], [], 'cart caption name cannot manufacture a cart request citation');
    sourceRejected(sourceExtract($cartRows, static fn () => $cartWire), 'uncited cart source');

    // Original valid cart-only requests remain legal metadata; blank GPS-only rows do not.
    $withCart = $rows; $extraCart = sourceRow('m17', 'customer', 'cart placeholder', 17);
    $extraCart['text'] = null;
    $extraCart['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1', 'product_items' => [
        ['product_retailer_id' => 'r1', 'quantity' => 1, 'item_price' => '1.00', 'currency' => 'EGP']]]);
    $withCart[] = $extraCart;
    sourceExpect(is_array($extraCart['cart']), true, 'synthetic cart-only row has an actual normalized cart');
    sourceExpect(Extraction::validateTranscript($withCart)['ok'], true, 'cart-only metadata fixture satisfies original transcript contract');
    $withCartPlan = Selection::build($withCart)['data']; $withCartWire = anchorWire($withCartPlan['input']);
    $withCartWire['evidence']['request_ids'][] = 'm17';
    $withCartDecoded = Selection::decode($withCartWire, $withCartPlan);
    sourceExpect($withCartDecoded['ok'], true, 'raw validation retains legal original cart-only request citations');
    sourceExpect($withCartDecoded['data']['evidence']['request_ids'], ['m3', 'm7', 'm9', 'm13', 'm14', 'm15', 'm17', 'm1', 'm5'],
        'valid cart-only request remains in its original order before the derived anchor citations');
    $emptyRequestRows = $rows; $emptyRequest = sourceRow('m17', 'customer', 'placeholder', 17);
    $emptyRequest['text'] = null; $emptyRequest['location'] = ['lat' => 0, 'long' => 0]; $emptyRequestRows[] = $emptyRequest;
    $emptyRequestPlan = Selection::build($emptyRequestRows)['data']; $emptyRequestWire = anchorWire($emptyRequestPlan['input']);
    $emptyRequestWire['evidence']['request_ids'][] = 'm17';
    anchorDecodeReject($emptyRequestWire, $emptyRequestPlan, 'GPS-only row cannot replace a customer request text');

    // All committing and routing safeguards run after the narrow citation normalization.
    foreach (['later-cancel', 'wrong-selected-size', 'fish-count-as-sale-quantity', 'wrong-quantity', 'duplicate-summary-clause',
        'pending-handoff', 'second-independent-order', 'missing-business-data-ack', 'quantity-is-question', 'wrong-option'] as $case) {
        $guardRows = $rows;
        if ($case === 'later-cancel') $guardRows[] = sourceRow('m17', 'customer', 'الغي الطلب', 17);
        if ($case === 'wrong-selected-size') $guardRows[9]['text'] = 'طلبك: 1.5 كيلو فسيخ من الكيلو 2 حبه كامل؛ 1 كيلو رنجه';
        if ($case === 'pending-handoff') $guardRows[15]['text'] = 'تأكيد الطلب: تم إرسال طلبك للفريق لمراجعته وتأكيده';
        if ($case === 'second-independent-order') {
            $guardRows[] = sourceRow('m17', 'customer', 'عايز طلب جديد 1 كيلو رنجه', 17);
            $guardRows[] = sourceRow('m18', 'business', 'تم تأكيد الطلب الجديد: 1 كيلو رنجه', 18);
        }
        if ($case === 'missing-business-data-ack') $guardRows[15]['text'] = 'شكرا';
        if ($case === 'quantity-is-question') $guardRows[6]['text'] = 'هو الكيلو بكام؟';
        $bad = sourceExtract($guardRows, static function ($input) use ($case): array {
            $wire = anchorWire($input);
            if ($case === 'fish-count-as-sale-quantity') $wire['items'][0]['quantity'] = '3';
            if ($case === 'wrong-quantity') $wire['items'][0]['quantity'] = '2';
            if ($case === 'duplicate-summary-clause') $wire['items'][1]['conversation_reference']['summary_clause_id'] = $wire['items'][0]['conversation_reference']['summary_clause_id'];
            if ($case === 'wrong-option') $wire['items'][0]['option_source'] = sourceSpan($input, 'm8', 'تنظيف');
            return $wire;
        });
        sourceRejected($bad, $case . ' retains full independent validation');
    }
    $nextDay = $rows; $later = sourceRow('m17', 'customer', 'ااختبار ربط واتساب', 17);
    $later['sent_at'] = '2026-10-14 12:00:00'; $nextDay[] = $later;
    sourceExpect(Evidence::operationalTest($nextDay), false, 'next-day connection test does not erase the genuine preceding order');
    $nextDayRun = sourceExtract($nextDay, static fn ($input) => anchorWire($input));
    sourceSame($nextDayRun['result'], Extraction::result(true, null, $expected), 'technical connection chatter does not invalidate prior committed fragmented order');
    foreach (['هذا الطلب اختبار لا ترسله', 'WA-1234'] as $marker) {
        $testRows = $rows; $testRows[] = sourceRow('m17', 'customer', $marker, 17);
        $testRun = sourceExtract($testRows, static function (): array { throw new RuntimeException('Explicit test order must never call fake HTTP.'); });
        sourceExpect($testRun['calls'], 0, 'explicit order test and coded guard run before any provider call');
        sourceExpect($testRun['result']['data']['decision'], 'NONE', 'test order cannot become a confirmed order through citation derivation');
        sourceExpect($testRun['result']['data']['issues'], ['TEST_MESSAGE'], 'test guard has original fixed classification');
    }
    $badRows = $rows; $badRows[0]['unoffered_field'] = 'bad';
    sourceNoHttp($badRows, 'malformed original transcript');
    $duplicateRows = $rows; $duplicateRows[1]['id'] = 'm1';
    sourceNoHttp($duplicateRows, 'duplicate original message identifiers');

    // Current episode source derivation preserves original labels and never borrows old evidence.
    [$shiftedRows, $shiftedData] = sourceShifted($rows, $data, 3);
    $old = sourceRow('m1', 'customer', 'اسمي اسم سابق وعنواني عنوان سابق وعايز فسيخ', 1);
    $old['location'] = ['lat' => 30.1, 'long' => 31.2];
    $episodeRows = array_merge([$old, sourceRow('m2', 'customer', 'هذا الطلب اختبار لا ترسله', 2),
        sourceRow('m3', 'customer', 'عايز طلب جديد كيلو ونص فسيخ كامل وكيلو رنجه', 3)], $shiftedRows);
    $episode = sourceExtract($episodeRows, static function ($input) use ($shiftedData): array {
        $wire = sourceWire($input, $shiftedData);
        $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m4', 'm8']));
        return $wire;
    });
    $shiftedExpected = $shiftedData;
    $shiftedExpected['evidence']['request_ids'] = array_merge(array_values(array_diff($shiftedExpected['evidence']['request_ids'], ['m4', 'm8'])), ['m4', 'm8']);
    sourceSame($episode['result'], Extraction::result(true, null, $shiftedExpected), 'current post-test episode derives only its exact current native name anchors');
    sourceExpect($episode['calls'], 1, 'valid current episode still needs exactly one fake provider call');
    sourceExpect($episode['input']['transcript'], Evidence::episodeTranscript($episodeRows), 'source selection uses only the independently bounded current episode');
    sourceExpect(in_array('m1', array_column($episode['input']['name_tokens'], 'message_id'), true), false, 'old name tokens are not offered');
    sourceExpect($episode['request']['text']['format']['schema']['properties']['evidence']['properties']['location_id']['enum'], [null],
        'old GPS cannot become current location metadata');
    $borrowed = sourceExtract($episodeRows, static function ($input) use ($shiftedData): array {
        $wire = sourceWire($input, $shiftedData);
        $wire['items'][0]['name_source'] = ['message_id' => 'm1', 'source' => 'text', 'first_token' => 0, 'last_token' => 0];
        $wire['evidence']['request_ids'][] = 'm1';
        return $wire;
    });
    sourceRejected($borrowed, 'old episode name and citation cannot be borrowed');
    $coded = $episodeRows; $coded[0]['text'] .= ' WA-1234';
    $codedRun = sourceExtract($coded, static fn ($input) => sourceWire($input, $shiftedData));
    sourceExpect($codedRun['calls'], 1, 'strict later new order isolates old coded marker before provider input');
    sourceSame($codedRun['result'], Extraction::result(true, null, $shiftedData), 'current evidence survives historical coded marker without borrowing old facts');
    sourceExpect($codedRun['input']['transcript'][0]['id'], 'm3', 'old coded marker does not alter current native labels');
    $currentCoded = $episodeRows; $currentCoded[3]['text'] .= ' WA-1234';
    $currentCodedRun = sourceExtract($currentCoded, static function (): array { throw new RuntimeException('Current coded marker must not call fake HTTP.'); });
    sourceExpect($currentCodedRun['calls'], 0, 'coded marker after current new-order boundary still blocks HTTP');
    sourceExpect($currentCodedRun['result']['data']['issues'], ['TEST_MESSAGE'], 'current coded marker keeps fixed test classification');
    echo 'WHATSAPP_ORDER_ANCHOR_CITATION_CHECKS=' . $checks . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    echo "WHATSAPP_ORDER_ANCHOR_CITATION_TEST_FAILED\n";
    exit(1);
}
