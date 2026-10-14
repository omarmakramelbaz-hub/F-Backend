<?php
// Synthetic evidence and fake HTTP only. Actual pure provider, decoder and validators;
// no Laravel bootstrap, credentials, database, network, catalog mutation or ERP actions.
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require __DIR__ . '/fixtures/whatsapp_order_pre_anchor_citation/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require __DIR__ . '/fixtures/whatsapp_order_pre_anchor_citation/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
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

try {
    $rows = sourceRows(); $data = sourceData();
    sourceExpect(Extraction::validateDetailed($data, $rows)['ok'], true, 'baseline fragmented fixture is fully confirmed by actual validation');
    sourceExpect(Evidence::prove($data, array_column($rows, null, 'id')), ['ok' => true, 'reason' => null],
        'baseline selected quantities and recap are independently proved');
    $planResult = Selection::build($rows);
    sourceExpect($planResult['ok'], true, 'valid episode builds actual source tables');
    $plan = $planResult['data'];
    sourceExpect(array_keys($plan), ['input', 'schema', 'sources', 'clauses'], 'plan has fixed proof-only keys');
    sourceExpect($plan['input']['transcript'], $rows, 'builder leaves original UTF-8 transcript completely unchanged');
    sourceExpect(array_keys($plan['input']), ['transcript', 'name_tokens', 'summary_clauses'], 'wire input has original dialogue plus bounded choices');
    sourceExpect(Selection::build($rows), $planResult, 'token and clause indices are deterministic for the same episode');
    sourceExpect(sourceEnumMembers($plan['schema']) <= 1000, true, 'complete dynamic schema remains inside enum budget');
    $wire = sourceWire($plan['input']);
    $decoded = Selection::decode($wire, $plan);
    sourceSame($decoded, Extraction::result(true, null, $data), 'source spans decode to exact legacy name and six-key references');
    sourceExpect($wire['evidence'], $decoded['data']['evidence'], 'decode adds no missing citations or location facts');
    sourceExpect(Selection::build($rows), $planResult, 'decoding does not mutate any source table or native row');
    $run = sourceExtract($rows, static fn ($input) => sourceWire($input));
    sourceExpect($run['calls'], 1, 'actual Provider performs exactly one private fake HTTP attempt');
    sourceSame($run['result'], Extraction::result(true, null, $data), 'new wire response runs unchanged full validation');
    sourceExpect($run['input']['transcript'], $rows, 'actual Provider sends original episode unchanged');
    sourceExpect($run['request']['text']['format']['schema'], $plan['schema'], 'Provider actually sends dynamic source schema');
    sourceExpect($run['request']['text']['format']['strict'], true, 'new response schema remains strict');
    sourceExpect($run['bytes'] <= 131072, true, 'entire request including tables and schema is byte bounded');
    sourceExpect([$run['request']['store'], $run['request']['stream']], [false, false], 'request remains non-stored and complete-response only');
    sourceExpect(isset($run['request']['tools']), false, 'source selection offers no tools');
    sourceExpect([$run['result']['data']['items'][0]['quantity'], $run['result']['data']['items'][1]['quantity']], ['1.5', '1'],
        'source selection does not turn size three fish per kilo into an order of three');
    sourceExpect($run['result']['data']['branch_hint'], null, 'a copied name never selects a fulfillment branch');
    sourceExpect($run['result']['data']['evidence']['location_id'], null, 'copied textual address never invents GPS');

    $itemBranches = sourceObjectBranches($plan['schema']['properties']['items']['items']);
    sourceExpect(count($itemBranches), 2, 'ordinary and contextual items are separate branches when no actual cart exists');
    foreach ($itemBranches as $index => $branch) {
        sourceAssertObject($branch, ['name_source', 'quantity', 'quantity_mode', 'option_source', 'cart_reference', 'conversation_reference'],
            'wire item branch ' . $index);
        sourceExpect(isset($branch['properties']['name']), false, 'new wire schema never asks the model to generate an item name');
        sourceExpect(isset($branch['properties']['option_hint']), false, 'new wire schema never asks the model to generate an option string');
        foreach (sourceObjectBranches($branch['properties']['name_source']) as $nameChoice) {
            sourceAssertObject($nameChoice, ['message_id', 'source', 'first_token', 'last_token'], 'name source branch ' . $index);
            $messageChoices = $nameChoice['properties']['message_id']['enum'];
            $kindChoices = $nameChoice['properties']['source']['enum'];
            sourceExpect(count($messageChoices), 1, 'each name branch binds one actual message ID');
            sourceExpect(count($kindChoices), 1, 'each name branch binds one actual source kind');
            $table = sourceTokens($plan['input'], $messageChoices[0], $kindChoices[0]);
            foreach (['first_token', 'last_token'] as $field) {
                sourceExpect($nameChoice['properties'][$field]['type'], 'integer', 'token selection requires integer index');
                sourceExpect(isset($nameChoice['properties'][$field]['enum']), false, 'token positions do not consume thousands of schema enum choices');
                sourceExpect($nameChoice['properties'][$field]['minimum'], 0, 'token lower bound is zero');
                sourceExpect($nameChoice['properties'][$field]['maximum'], count($table) - 1, 'token upper bound belongs to this exact source row');
            }
        }
        $optionChoices = sourceObjectBranches($branch['properties']['option_source']);
        sourceExpect(count(array_filter($optionChoices, static fn ($choice) => ($choice['type'] ?? null) === 'null')), 1,
            'option schema offers exactly one explicit null branch');
        foreach ($optionChoices as $optionChoice) {
            if (($optionChoice['type'] ?? null) === 'null') continue;
            sourceAssertObject($optionChoice, ['message_id', 'source', 'first_token', 'last_token'], 'option source choice');
            $messageChoices = $optionChoice['properties']['message_id']['enum'];
            $kindChoices = $optionChoice['properties']['source']['enum'];
            sourceExpect(count($messageChoices), 1, 'each option branch binds one actual row');
            sourceExpect(count($kindChoices), 1, 'each option branch binds one actual field');
            $table = sourceTokens($plan['input'], $messageChoices[0], $kindChoices[0]);
            foreach (['first_token', 'last_token'] as $field) {
                sourceExpect($optionChoice['properties'][$field]['type'], 'integer', 'option token bounds require integers');
                sourceExpect($optionChoice['properties'][$field]['minimum'], 0, 'option token lower bound remains zero');
                sourceExpect($optionChoice['properties'][$field]['maximum'], count($table) - 1, 'option token maximum belongs to this actual source field');
            }
        }
    }
    $contextBranch = null;
    foreach ($itemBranches as $branch) {
        if (($branch['properties']['conversation_reference']['type'] ?? null) === 'object') $contextBranch = $branch;
    }
    sourceExpect($contextBranch !== null, true, 'context branch is structurally available');
    $contextSchema = $contextBranch['properties']['conversation_reference'];
    sourceAssertObject($contextSchema, ['business_offer_id', 'customer_quantity_id', 'summary_clause_id'],
        'wire conversation reference');
    sourceExpect(isset($contextSchema['properties']['name_anchor_id']), false, 'redundant model-generated anchor is not offered in new wire schema');
    foreach (sourceObjectBranches($contextBranch['properties']['name_source']) as $nameChoice) {
        sourceExpect(in_array($nameChoice['properties']['message_id']['enum'][0], sourceLabels($rows, 'customer'), true), true,
            'derived anchors bind only actual customer text sources');
        sourceExpect($nameChoice['properties']['source']['enum'], ['text'], 'context anchor cannot borrow a cart text fragment');
    }
    foreach (sourceObjectBranches($contextBranch['properties']['option_source']) as $optionChoice) {
        if (($optionChoice['type'] ?? null) === 'null') continue;
        sourceExpect(in_array($optionChoice['properties']['message_id']['enum'][0], sourceLabels($rows, 'customer'), true), true,
            'context option schema offers only actual customer rows');
        sourceExpect($optionChoice['properties']['source']['enum'], ['text'], 'context option schema offers only native customer text');
    }
    sourceExpect($contextSchema['properties']['customer_quantity_id']['enum'], sourceLabels($rows, 'customer'), 'quantity sources use actual episode customer enum');
    sourceExpect($contextSchema['properties']['business_offer_id']['enum'], sourceLabels($rows, 'business'), 'offer sources use actual episode business enum');
    sourceExpect(isset($contextSchema['properties']['customer_option_id']), false, 'redundant option row is derived only from exact customer option source');
    sourceExpect($contextSchema['properties']['summary_clause_id']['enum'], array_column($plan['input']['summary_clauses'], 'id'), 'only actual offered business clause IDs may be selected');
    sourceExpect(isset($contextSchema['properties']['business_summary_id']), false, 'model never independently generates a summary row');
    sourceExpect(isset($contextSchema['properties']['summary_clause_index']), false, 'model never independently counts recap clauses');
    $evidenceSchema = $plan['schema']['properties']['evidence']['properties'];
    sourceExpect($evidenceSchema['request_ids']['items']['enum'], sourceLabels($rows, 'customer'), 'request IDs exclude business and missing labels');
    sourceExpect($evidenceSchema['confirmation_ids']['items']['enum'], sourceLabels($rows, 'business'), 'confirmation IDs exclude customer and missing labels');
    sourceExpect($evidenceSchema['customer_acceptance_ids']['items']['enum'], sourceLabels($rows, 'customer'), 'acceptance IDs exclude business and missing labels');
    sourceExpect($evidenceSchema['location_id']['enum'], [null], 'no customer GPS row means no location label can be offered');
    foreach ($plan['input']['summary_clauses'] as $choice) {
        sourceExpect(array_keys($choice), ['id', 'message_id', 'clause_index', 'text', 'derived'], 'summary choice has fixed transparent provenance');
        sourceExpect($choice['derived'], true, 'recap view is explicitly marked derived');
        sourceExpect(array_column($rows, null, 'id')[$choice['message_id']]['speaker'], 'business', 'recap choice binds an actual business row');
        sourceExpect(Evidence::clauses(array_column($rows, null, 'id')[$choice['message_id']]['text'])[$choice['clause_index']], $choice['text'],
            'recap choice maps precisely the unchanged helper clause index');
    }

    $articleTokens = sourceTokens($plan['input'], 'm1');
    $article = sourceSpan($plan['input'], 'm1', 'الفسيخ');
    $noun = sourceSpan($plan['input'], 'm1', 'فسيخ');
    sourceExpect($noun['first_token'], $article['first_token'] + 1, 'leading Arabic article has one explicit overlapping subtoken');
    $literalDraft = sourceDraft('الفسيخ');
    $literalWire = sourceWireOptionFields($literalDraft, $plan['input']); unset($literalWire['items'][0]['name']); $literalWire['items'][0]['name_source'] = $article;
    sourceExpect(Selection::decode($literalWire, $plan)['data']['items'][0]['name'], 'الفسيخ', 'full article spelling is copied exactly');
    $literalWire['items'][0]['name_source'] = $noun;
    sourceExpect(Selection::decode($literalWire, $plan)['data']['items'][0]['name'], 'فسيخ', 'article subtoken copies a contiguous native noun');
    sourceExpect($articleTokens[$article['first_token']]['text'], 'الفسيخ', 'token table retains exact full UTF-8 word');
    sourceExpect($articleTokens[$noun['first_token']]['text'], 'فسيخ', 'token table does not transliterate the subtoken');
    $prefixRows = [sourceRow('m1', 'customer', 'بالفسيخ والفسيخ للفسيخ', 1)];
    $prefixPlan = Selection::build($prefixRows);
    sourceExpect($prefixPlan['ok'], true, 'prefixed words can still be represented literally');
    sourceExpect(in_array('فسيخ', array_column(sourceTokens($prefixPlan['data']['input'], 'm1'), 'text'), true), false,
        'explicit article support never becomes generic Arabic stemming');

    $unknownRows = [sourceRow('m1', 'customer', 'عايز طبق جديد زعفراني خاص', 1)];
    $unknownData = sourceDraft('طبق جديد زعفراني خاص');
    $unknown = sourceExtract($unknownRows, static function ($input) use ($unknownData): array {
        $wire = sourceWireOptionFields($unknownData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'طبق', 'خاص');
        return $wire;
    });
    sourceExpect($unknown['calls'], 1, 'an unknown compound product needs exactly one fake request');
    sourceExpect($unknown['result']['ok'], true, 'unknown product wording is representable without a catalog name enum');
    sourceExpect($unknown['result']['data']['items'][0]['name'], $unknownData['items'][0]['name'], 'all consecutive words are exact original bytes');
    sourceExpect($unknown['result']['data']['decision'], 'DRAFT', 'source selection cannot promote unknown product hints into confirmation');
    sourceExpect($unknown['input']['transcript'], $unknownRows, 'compound product input stays unchanged');
    $unicodeRows = [sourceRow('m1', 'customer', 'عايز سمك «خاص» رقم ٤٢', 1)];
    $unicodeData = sourceDraft('سمك «خاص» رقم ٤٢');
    $unicode = sourceExtract($unicodeRows, static function ($input) use ($unicodeData): array {
        $wire = sourceWireOptionFields($unicodeData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'سمك', '٤٢');
        return $wire;
    });
    sourceExpect($unicode['result']['ok'], true, 'consecutive source span can include punctuation and Arabic digits');
    sourceExpect($unicode['result']['data']['items'][0]['name'], 'سمك «خاص» رقم ٤٢', 'Unicode punctuation and numeral spellings are never normalized');

    // Options copy one native phrase and retain the independent selection and catalog proof.
    sourceExpect($wire['items'][0]['option_source'], sourceSpan($plan['input'], 'm9', 'كامل'),
        'fragmented actual whole option selects its exact later customer row');
    sourceExpect($decoded['data']['items'][0]['option_hint'], 'كامل', 'public option is exact native UTF-8 text');
    sourceExpect(isset($decoded['data']['items'][0]['option_source']), false, 'public DTO contains normalized option_hint only');
    sourceExpect($decoded['data']['items'][1]['option_hint'], null, 'null option source remains genuinely absent');
    sourceExpect($decoded['data']['evidence'], $data['evidence'], 'option copying adds no customer or business citation');
    sourceExpect(Evidence::catalogOptionLabels($decoded['data']['items'][0], $decoded['data'], $rows, ['كامل']), null,
        'exact whole option cannot drop the selected three-fish-per-kilo catalog size');
    sourceExpect(Evidence::catalogOptionLabels($decoded['data']['items'][0], $decoded['data'], $rows, ['3 حبه كامل']), ['3 حبه كامل'],
        'catalog candidate still needs the independently proved and quoted selected size with preparation');
    $noOption = $wire; $noOption['items'][0]['option_source'] = null;
    $noOptionDecoded = Selection::decode($noOption, $plan);
    sourceExpect($noOptionDecoded['ok'], true, 'explicit absent option can decode without inventing a preparation');
    sourceExpect($noOptionDecoded['data']['items'][0]['option_hint'], null, 'null wire option never silently defaults to whole');
    sourceExpect(Extraction::validateDetailed($noOptionDecoded['data'], $rows)['ok'], true, 'accepted raw recap remains subject to unchanged independent evidence proof');
    sourceExpect(Evidence::catalogOptionLabels($noOptionDecoded['data']['items'][0], $noOptionDecoded['data'], $rows, ['كامل']), null,
        'null option source cannot bypass selected fish-size catalog guard');
    $fallbackData = $data; $fallbackData['items'][0]['option_hint'] = '3 حبه';
    $fallbackData['items'][0]['conversation_reference']['customer_option_id'] = null;
    $fallback = sourceExtract($rows, static fn ($input) => sourceWire($input, $fallbackData));
    sourceExpect($fallback['calls'], 1, 'quantity-row option fallback still uses one fake HTTP call');
    sourceExpect($fallback['result']['ok'], true, 'exact option phrase can bind its cited quantity row when later option ID is null');
    sourceExpect($fallback['result']['data']['items'][0]['option_hint'], '3 حبه', 'fallback copies size text exactly without turning it into quantity');
    sourceExpect($fallback['result']['data']['items'][0]['quantity'], '1.5', 'size phrase fallback never changes the sale amount');
    sourceExpect($fallback['result']['data']['items'][0]['conversation_reference']['customer_option_id'], 'm3',
        'same quantity-row option source derives its exact public option ID');
    sourceExpect($fallback['result']['data']['evidence'], $data['evidence'], 'quantity fallback adds no implied citations');

    foreach (['missing-row', 'wrong-field', 'unknown-field', 'empty-object', 'missing-first', 'extra-value',
        'negative-token', 'string-token', 'float-token', 'outside-table', 'reversed-span', 'uncited-native-option',
        'acceptance-only-option', 'business-option', 'wrong-customer-row', 'legacy-option-id-field',
        'legacy-null-option-id-field', 'array-of-sources', 'old-wire-option-hint', 'freeform-option-plus-source'] as $case) {
        $optionBad = sourceExtract($rows, static function ($input) use ($case): array {
            $wire = sourceWire($input); $option =& $wire['items'][0]['option_source'];
            if ($case === 'missing-row') $option['message_id'] = 'm60';
            if ($case === 'wrong-field') $option['source'] = 'cart_text';
            if ($case === 'unknown-field') $option['source'] = 'summary_clauses';
            if ($case === 'empty-object') $option = [];
            if ($case === 'missing-first') unset($option['first_token']);
            if ($case === 'extra-value') $option['value'] = 'كامل';
            if ($case === 'negative-token') $option['first_token'] = -1;
            if ($case === 'string-token') $option['first_token'] = (string) $option['first_token'];
            if ($case === 'float-token') $option['first_token'] = (float) $option['first_token'];
            if ($case === 'outside-table') $option['last_token'] = 999;
            if ($case === 'reversed-span') { $option['first_token'] = 2; $option['last_token'] = 1; }
            if (in_array($case, ['uncited-native-option', 'acceptance-only-option'], true)) {
                $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m9']));
                if ($case === 'acceptance-only-option') $wire['evidence']['customer_acceptance_ids'][] = 'm9';
            }
            if ($case === 'business-option') $option = sourceSpan($input, 'm10', 'كامل');
            if ($case === 'wrong-customer-row') $option = sourceSpan($input, 'm7', 'كيلو');
            if ($case === 'legacy-option-id-field') $wire['items'][0]['conversation_reference']['customer_option_id'] = 'm13';
            if ($case === 'legacy-null-option-id-field') $wire['items'][0]['conversation_reference']['customer_option_id'] = null;
            if ($case === 'array-of-sources') $option = [$option, sourceSpan($input, 'm3', '3', 'حبه')];
            if ($case === 'freeform-option-plus-source') $wire['items'][0]['option_hint'] = '3 حبه كامل';
            unset($option);
            if ($case === 'old-wire-option-hint') { unset($wire['items'][0]['option_source']); $wire['items'][0]['option_hint'] = 'كامل'; }
            return $wire;
        });
        sourceRejected($optionBad, 'option ' . $case);
        if ($case === 'wrong-customer-row') {
            sourceExpect($optionBad['result']['diagnostic']['validation_category'] !== 'SOURCE_REFERENCE_INVALID', true,
                'legally cited foreign-item option still fails the unchanged full association proof');
        } else {
            sourceExpect($optionBad['result']['diagnostic']['validation_category'], 'SOURCE_REFERENCE_INVALID', 'invalid option has fixed source failure category');
            sourceExpect($optionBad['result']['diagnostic']['validation_field'], str_starts_with($case, 'legacy-') ? 'ITEM' : 'ITEM_OPTION',
                'invalid option is attributed without exposing text');
        }
    }
    $foreignCartOptionRows = $rows;
    $foreignCartOptionRows[8]['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1', 'text' => 'كامل',
        'product_items' => [['product_retailer_id' => 'OPTION_FIXTURE_CART', 'quantity' => 1, 'item_price' => '1.00', 'currency' => 'EGP']]]);
    $foreignCartOption = sourceExtract($foreignCartOptionRows, static function ($input): array {
        $wire = sourceWire($input); $wire['items'][0]['option_source'] = sourceSpan($input, 'm9', 'كامل', null, 'cart_text'); return $wire;
    });
    sourceRejected($foreignCartOption, 'same-row cart caption contextual option');
    sourceExpect($foreignCartOption['result']['diagnostic']['validation_field'], 'ITEM_OPTION', 'context source kind remains native text despite matching row and word');
    $combinedOption = sourceExtract($rows, static function ($input): array {
        $wire = sourceWire($input); $wire['items'][0]['option_source'] = sourceSpan($input, 'm10', '3', 'كامل'); return $wire;
    });
    sourceRejected($combinedOption, 'business-combined size and preparation contextual option');
    $legacyCombined = sourceExtract($rows, static function (): array {
        $legacy = sourceData(); $legacy['items'][0]['option_hint'] = '3 حبه كامل'; return $legacy;
    });
    sourceRejected($legacyCombined, 'legacy combined size and preparation not said by selected customer option row');
    sourceExpect($legacyCombined['result']['diagnostic']['validation_category'] !== 'SOURCE_REFERENCE_INVALID', true,
        'legacy compatibility reruns full context association for synthesized combined option');
    $ordinaryOptionRows = [sourceRow('m1', 'customer', 'عايز فسيخ كامل', 1), sourceRow('m2', 'business', 'فسيخ كامل', 2),
        sourceRow('m3', 'customer', 'كامل', 3)];
    foreach (['customer-request', 'business-confirmation'] as $sourceRole) {
        $ordinaryOption = sourceExtract($ordinaryOptionRows, static function ($input) use ($sourceRole): array {
            $wire = sourceWireOptionFields(sourceDraft('فسيخ'), $input); unset($wire['items'][0]['name']);
            $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'فسيخ');
            $wire['items'][0]['option_source'] = sourceSpan($input, $sourceRole === 'customer-request' ? 'm1' : 'm2', 'كامل');
            if ($sourceRole === 'business-confirmation') $wire['evidence']['confirmation_ids'] = ['m2'];
            return $wire;
        });
        sourceExpect($ordinaryOption['calls'], 1, 'ordinary cited option uses one fake request');
        sourceExpect($ordinaryOption['result']['ok'], true, 'ordinary option can copy an explicitly cited actual source row');
        sourceExpect($ordinaryOption['result']['data']['items'][0]['option_hint'], 'كامل', 'ordinary option is copied exactly');
        sourceExpect($ordinaryOption['result']['data']['decision'], 'DRAFT', 'source data never promotes an ordinary option to a confirmed basket');
    }
    foreach (['uncited-business', 'acceptance-only-customer'] as $sourceRole) {
        $ordinaryOption = sourceExtract($ordinaryOptionRows, static function ($input) use ($sourceRole): array {
            $wire = sourceWireOptionFields(sourceDraft('فسيخ'), $input); unset($wire['items'][0]['name']);
            $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'فسيخ');
            $wire['items'][0]['option_source'] = sourceSpan($input, $sourceRole === 'uncited-business' ? 'm2' : 'm3', 'كامل');
            if ($sourceRole === 'acceptance-only-customer') $wire['evidence']['customer_acceptance_ids'] = ['m3'];
            return $wire;
        });
        sourceRejected($ordinaryOption, 'ordinary option ' . $sourceRole);
        sourceExpect($ordinaryOption['result']['diagnostic']['validation_category'], 'SOURCE_REFERENCE_INVALID',
            'matching option text elsewhere cannot substitute for the actual selected row citation');
        sourceExpect($ordinaryOption['result']['diagnostic']['validation_field'], 'ITEM_OPTION', 'ordinary option citation failure has fixed scoped diagnostic');
    }
    $optionUnicodeRows = [sourceRow('m1', 'customer', 'عايز فسيخ إضَافِي خاص', 1)];
    $optionUnicode = sourceExtract($optionUnicodeRows, static function ($input): array {
        $wire = sourceWireOptionFields(sourceDraft('فسيخ'), $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'فسيخ');
        $wire['items'][0]['option_source'] = sourceSpan($input, 'm1', 'إضَافِي', 'خاص'); return $wire;
    });
    sourceExpect($optionUnicode['result']['ok'], true, 'a multiword native option can be represented without generation');
    sourceExpect($optionUnicode['result']['data']['items'][0]['option_hint'], 'إضَافِي خاص', 'option diacritics and spelling remain exact native UTF-8');
    foreach ([200, 201] as $length) {
        $longOption = str_repeat('ر', $length);
        $longOptionRows = [sourceRow('m1', 'customer', 'عايز فسيخ ' . $longOption, 1)];
        $longOptionRun = sourceExtract($longOptionRows, static function ($input) use ($longOption): array {
            $wire = sourceWireOptionFields(sourceDraft('فسيخ'), $input); unset($wire['items'][0]['name']);
            $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'فسيخ');
            $wire['items'][0]['option_source'] = sourceSpan($input, 'm1', $longOption); return $wire;
        });
        sourceExpect($longOptionRun['calls'], 1, 'option length boundary uses one fake request');
        sourceExpect($longOptionRun['result']['ok'], $length === 200, 'exact option span is limited to 200 Unicode code points');
        if ($length === 200) sourceExpect($longOptionRun['result']['data']['items'][0]['option_hint'], $longOption, '200 two-byte option code points are not trimmed');
        else {
            sourceExpect($longOptionRun['result']['diagnostic']['validation_category'], 'SOURCE_REFERENCE_INVALID', 'overlong option has fixed source diagnostic');
            sourceExpect($longOptionRun['result']['diagnostic']['validation_field'], 'ITEM_OPTION', 'overlong option never falls back to a generated string');
        }
    }
    $uncitedOrdinary = sourceExtract($rows, static function ($input): array {
        $wire = sourceWireOptionFields(sourceDraft('فسيخ'), $input); $wire['evidence']['request_ids'] = [];
        unset($wire['items'][0]['name']); $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'فسيخ');
        return $wire;
    });
    sourceRejected($uncitedOrdinary, 'ordinary uncited authentic source');
    sourceExpect($uncitedOrdinary['result']['diagnostic']['validation_category'], 'LITERAL_NOT_CITED', 'source decoder never adds an ordinary citation');
    sourceExpect($uncitedOrdinary['result']['diagnostic']['validation_field'], 'ITEM_NAME', 'uncited source fails existing item grounding');

    // Malformed source references remain rejected even though the text exists elsewhere.
    foreach (['missing-message', 'business-name-for-context', 'different-customer-anchor', 'uncited-name',
        'negative-index', 'string-index', 'float-index', 'reversed-span', 'outside-table', 'missing-token-key',
        'extra-token-key', 'missing-source-kind', 'unknown-source-kind', 'wrong-source-kind', 'legacy-name-plus-source', 'unknown-clause', 'customer-clause', 'uncited-summary',
        'summary-row-plus-id', 'wrong-actual-clause', 'business-anchor', 'customer-offer', 'business-quantity',
        'business-option', 'cart-plus-context', 'span-object-extra-root'] as $case) {
        $bad = sourceExtract($rows, static function ($input) use ($case): array {
            $wire = sourceWire($input); $name =& $wire['items'][0]['name_source']; $context =& $wire['items'][0]['conversation_reference'];
            if ($case === 'missing-message') $name['message_id'] = 'm60';
            if ($case === 'business-name-for-context') $name = sourceSpan($input, 'm2', 'فسيخ');
            if ($case === 'different-customer-anchor') $name = sourceSpan($input, 'm5', 'رنجه');
            if ($case === 'uncited-name') $wire['evidence']['request_ids'] = array_values(array_diff($wire['evidence']['request_ids'], ['m1']));
            if ($case === 'negative-index') $name['first_token'] = -1;
            if ($case === 'string-index') $name['first_token'] = (string) $name['first_token'];
            if ($case === 'float-index') $name['first_token'] = 1.0;
            if ($case === 'reversed-span') { $name['first_token'] = 2; $name['last_token'] = 1; }
            if ($case === 'outside-table') $name['last_token'] = 99999;
            if ($case === 'missing-token-key') unset($name['last_token']);
            if ($case === 'extra-token-key') $name['name'] = 'فسيخ';
            if ($case === 'missing-source-kind') unset($name['source']);
            if ($case === 'unknown-source-kind') $name['source'] = 'summary_clauses';
            if ($case === 'wrong-source-kind') $name['source'] = 'cart_text';
            if ($case === 'legacy-name-plus-source') $wire['items'][0]['name'] = 'فسيخ';
            if ($case === 'unknown-clause') $context['summary_clause_id'] = 's99999';
            if ($case === 'customer-clause') $context['summary_clause_id'] = 'm3';
            if ($case === 'uncited-summary') $wire['evidence']['confirmation_ids'] = ['m16'];
            if ($case === 'summary-row-plus-id') $context['business_summary_id'] = 'm10';
            if ($case === 'wrong-actual-clause') $context['summary_clause_id'] = sourceClause($input, 'm10', 1);
            if ($case === 'business-anchor') $context['name_anchor_id'] = 'm2';
            if ($case === 'customer-offer') $context['business_offer_id'] = 'm3';
            if ($case === 'business-quantity') $context['customer_quantity_id'] = 'm2';
            if ($case === 'business-option') $context['customer_option_id'] = 'm8';
            if ($case === 'cart-plus-context') $wire['items'][0]['cart_reference'] = ['message_id' => 'm1', 'product_retailer_id' => 'fixture-retailer'];
            if ($case === 'span-object-extra-root') $wire['items'][0]['order_id'] = 999;
            unset($name, $context);
            return $wire;
        });
        sourceRejected($bad, $case);
    }

    // Cart labels remain proof of exact catalog lines and can never be contextual references.
    $cart = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id' => '1234567', 'text' => 'عايز طبق خاص',
        'product_items' => [['product_retailer_id' => 'SKU_SYNTHETIC_ONLY', 'quantity' => 1, 'item_price' => '100.00', 'currency' => 'EGP']]]);
    $cartRows = [sourceRow('m1', 'customer', 'عايز طبق خاص', 1), sourceRow('m2', 'business', 'بيانات طبق خاص', 2)];
    $cartRows[0]['cart'] = $cart;
    $cartPlan = Selection::build($cartRows);
    sourceExpect($cartPlan['ok'], true, 'actual normalized cart can offer source choices');
    $cartBranches = sourceObjectBranches($cartPlan['data']['schema']['properties']['items']['items']);
    sourceExpect(count($cartBranches), 3, 'a real cart adds the exclusive third wire branch');
    $cartBranch = null;
    foreach ($cartBranches as $branch) {
        if (($branch['properties']['cart_reference']['type'] ?? null) === 'object') $cartBranch = $branch;
    }
    sourceExpect($cartBranch !== null, true, 'cart branch exists only with an actual submitted line');
    sourceExpect($cartBranch['properties']['conversation_reference'], ['type' => 'null'], 'cart branch structurally excludes contextual selection');
    sourceExpect($contextBranch['properties']['cart_reference'], ['type' => 'null'], 'context branch structurally excludes submitted cart selection');
    sourceExpect($cartBranch['properties']['cart_reference']['properties']['message_id']['enum'], ['m1'], 'cart row enum never offers business rows');
    sourceExpect($cartBranch['properties']['cart_reference']['properties']['product_retailer_id']['enum'], ['SKU_SYNTHETIC_ONLY'],
        'cart line enum contains only the actual retailer ID');
    $dualRows = $cartRows;
    $dualRows[0]['text'] = 'اسمي عميل صناعي';
    $dualRows[0]['cart']['text'] = 'عايز طبق زعفراني خاص';
    $dualPlan = Selection::build($dualRows);
    sourceExpect($dualPlan['ok'], true, 'both independent native text and cart text remain available');
    $m1Tables = array_values(array_filter($dualPlan['data']['input']['name_tokens'], static fn ($table) => $table['message_id'] === 'm1'));
    sourceExpect(array_column($m1Tables, 'source'), ['text', 'cart_text'], 'dual-source row offers exactly two separately labeled token tables');
    sourceExpect($dualPlan['data']['sources']['m1:text']['text'], 'اسمي عميل صناعي', 'native text proof is not appended to the cart caption');
    sourceExpect($dualPlan['data']['sources']['m1:cart_text']['text'], 'عايز طبق زعفراني خاص', 'cart proof is not dropped or appended to native text');
    sourceExpect($dualPlan['data']['input']['transcript'], $dualRows, 'dual-source original row stays exact');
    $dualData = sourceDraft('طبق زعفراني خاص');
    $dualData['items'][0]['cart_reference'] = ['message_id' => 'm1', 'product_retailer_id' => 'SKU_SYNTHETIC_ONLY'];
    $dualRun = sourceExtract($dualRows, static function ($input) use ($dualData): array {
        $wire = sourceWireOptionFields($dualData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'طبق', 'خاص', 'cart_text');
        return $wire;
    });
    sourceExpect($dualRun['calls'], 1, 'dual-source cart name uses one fake provider request');
    sourceExpect($dualRun['result']['ok'], true, 'cart-only product phrase remains representable when native text contains customer details');
    sourceExpect($dualRun['result']['data']['items'][0]['name'], 'طبق زعفراني خاص', 'product phrase is copied only from selected original cart field');
    $cartOptionRows = $dualRows; $cartOptionRows[0]['cart']['text'] .= ' كامل';
    $cartOption = sourceExtract($cartOptionRows, static function ($input) use ($dualData): array {
        $wire = sourceWireOptionFields($dualData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'طبق', 'خاص', 'cart_text');
        $wire['items'][0]['option_source'] = sourceSpan($input, 'm1', 'كامل', null, 'cart_text'); return $wire;
    });
    sourceExpect($cartOption['calls'], 1, 'cart caption option uses one fake request');
    sourceExpect($cartOption['result']['ok'], true, 'actual cited cart caption can supply a cart item option');
    sourceExpect($cartOption['result']['data']['items'][0]['option_hint'], 'كامل', 'cart caption option is copied without joining native profile text');
    sourceExpect($cartOption['result']['data']['evidence'], $dualData['evidence'], 'cart option adds no citations');
    $dualTextData = sourceDraft('عميل صناعي');
    $dualTextRun = sourceExtract($dualRows, static function ($input) use ($dualTextData): array {
        $wire = sourceWireOptionFields($dualTextData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'عميل', 'صناعي');
        return $wire;
    });
    sourceExpect($dualTextRun['result']['data']['items'][0]['name'], 'عميل صناعي', 'explicit text selection copies its own field without borrowing cart words');
    $joinRun = sourceExtract($dualRows, static function ($input) use ($dualData): array {
        $wire = sourceWireOptionFields($dualData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'طبق', 'خاص', 'cart_text');
        $wire['items'][0]['name_source']['source'] = 'text+cart_text'; return $wire;
    });
    sourceRejected($joinRun, 'constructed text-cart source join');
    $cartAsContext = sourceWireOptionFields($dualData, $dualPlan['data']['input']); unset($cartAsContext['items'][0]['name']);
    $cartAsContext['items'][0]['name_source'] = sourceSpan($dualPlan['data']['input'], 'm1', 'طبق', 'خاص', 'cart_text');
    $cartAsContext['items'][0]['cart_reference'] = null;
    $cartAsContext['items'][0]['conversation_reference'] = ['business_offer_id' => 'm2', 'customer_quantity_id' => 'm1',
        'summary_clause_id' => sourceClause($dualPlan['data']['input'], 'm2', 0)];
    $cartContextDecoded = Selection::decode($cartAsContext, $dualPlan['data']);
    sourceExpect($cartContextDecoded['ok'], false, 'cart caption cannot derive a customer contextual name anchor');
    sourceExpect($cartContextDecoded['validation_category'], 'SOURCE_REFERENCE_INVALID', 'cart context incompatibility has fixed diagnostic');
    sourceExpect($cartContextDecoded['validation_field'], 'ITEM_NAME', 'cart context failure identifies the source-bound name');
    $cartRows[0]['text'] = null;
    $cartOnlyData = sourceDraft('طبق خاص');
    $cartOnlyData['items'][0]['cart_reference'] = ['message_id' => 'm1', 'product_retailer_id' => 'SKU_SYNTHETIC_ONLY'];
    $cartOnly = sourceExtract($cartRows, static function ($input) use ($cartOnlyData): array {
        $wire = sourceWireOptionFields($cartOnlyData, $input); unset($wire['items'][0]['name']);
        $wire['items'][0]['name_source'] = sourceSpan($input, 'm1', 'طبق', 'خاص', 'cart_text');
        return $wire;
    });
    sourceExpect($cartOnly['calls'], 1, 'cart-text selection still makes exactly one fake request');
    sourceExpect($cartOnly['result']['ok'], true, 'literal cart text can ground a DRAFT name without interpreting the retailer ID');
    sourceExpect($cartOnly['result']['data']['items'][0]['name'], 'طبق خاص', 'cart text copies original Unicode only');
    sourceExpect($cartOnly['result']['data']['items'][0]['cart_reference'], $cartOnlyData['items'][0]['cart_reference'], 'actual cart binding is retained exactly');
    sourceExpect($cartOnly['input']['transcript'], $cartRows, 'cart-only native transcript remains unchanged');

    // Compatibility accepts legacy strings only after every existing authoritative check.
    $legacy = sourceExtract($rows, static fn () => sourceData());
    sourceExpect($legacy['calls'], 1, 'legacy reply compatibility uses no retry or second prompt');
    sourceSame($legacy['result'], Extraction::result(true, null, $data), 'fully grounded legacy string output still passes unchanged validation');
    sourceExpect(str_contains(json_encode($legacy['request']['text']['format']['schema'], JSON_THROW_ON_ERROR), 'name_source'), true,
        'legacy compatibility never downgrades the new request schema');
    $legacyInvented = sourceExtract($rows, static function (): array {
        $bad = sourceData(); $bad['items'][0]['name'] = 'اسم منتج ملفق غير موجود'; return $bad;
    });
    sourceRejected($legacyInvented, 'legacy uncited generated product');
    sourceExpect($legacyInvented['result']['diagnostic']['validation_category'], 'LITERAL_NOT_CITED', 'legacy compatibility preserves exact literal grounding failure');
    sourceExpect($legacyInvented['result']['diagnostic']['validation_field'], 'ITEM_NAME', 'legacy name failure is still scoped to item name');

    foreach (['later-cancel', 'wrong-selected-size', 'fish-count-as-sale-quantity', 'missing-acceptance',
        'wrong-customer-option', 'duplicate-summary-clause', 'pending-handoff', 'second-independent-order'] as $case) {
        $guardRows = $rows;
        if ($case === 'later-cancel') $guardRows[] = sourceRow('m17', 'customer', 'الغي الطلب', 17);
        if ($case === 'wrong-selected-size') $guardRows[9]['text'] = 'طلبك: 1.5 كيلو فسيخ من الكيلو 2 حبه كامل؛ 1 كيلو رنجه';
        if ($case === 'pending-handoff') $guardRows[15]['text'] = 'تأكيد الطلب: تم إرسال طلبك للفريق لمراجعته وتأكيده';
        if ($case === 'second-independent-order') {
            $guardRows[] = sourceRow('m17', 'customer', 'عايز طلب جديد 1 كيلو رنجه', 17);
            $guardRows[] = sourceRow('m18', 'business', 'تم تأكيد الطلب الجديد: 1 كيلو رنجه', 18);
        }
        $bad = sourceExtract($guardRows, static function ($input) use ($case): array {
            $wire = sourceWire($input);
            if ($case === 'fish-count-as-sale-quantity') $wire['items'][0]['quantity'] = '3';
            if ($case === 'missing-acceptance') $wire['evidence']['customer_acceptance_ids'] = [];
            if ($case === 'wrong-customer-option') $wire['items'][0]['option_source'] = sourceSpan($input, 'm8', 'تنظيف');
            if ($case === 'duplicate-summary-clause') $wire['items'][1]['conversation_reference']['summary_clause_id'] = $wire['items'][0]['conversation_reference']['summary_clause_id'];
            return $wire;
        });
        sourceRejected($bad, $case . ' retains full validator safeguard');
    }

    // Native source bytes, UTF-8 and all limits are tested independently of fake responses.
    foreach ($plan['sources'] as $key => $source) {
        $id = explode(':', $key, 2)[0];
        $original = array_column($rows, null, 'id')[$id]['text'];
        sourceExpect($source['text'], $original, 'internal source proof retains original row bytes');
        foreach ($source['tokens'] as $position => $token) {
            sourceExpect($token['index'], $position, 'token indices remain stable and gapless');
            sourceExpect(sourceNativeSubstring($original, $token['start'], $token['end']), $token['text'],
                'each selected token is an exact bounded native substring');
        }
    }
    foreach ([200, 201] as $length) {
        $name = str_repeat('ر', $length);
        $lengthRows = [sourceRow('m1', 'customer', 'عايز ' . $name, 1)];
        $lengthPlan = Selection::build($lengthRows);
        sourceExpect($lengthPlan['ok'], true, 'whole transcript accepts this bounded source row');
        $lengthData = sourceDraft($name); $lengthWire = sourceWireOptionFields($lengthData, $lengthPlan['data']['input']); unset($lengthWire['items'][0]['name']);
        $lengthWire['items'][0]['name_source'] = sourceSpan($lengthPlan['data']['input'], 'm1', $name);
        $lengthDecoded = Selection::decode($lengthWire, $lengthPlan['data']);
        sourceExpect($lengthDecoded['ok'], $length === 200, 'copied source span uses 200 UTF-8 code points, not bytes');
        if ($length === 200) sourceExpect($lengthDecoded['data']['items'][0]['name'], $name, '200 two-byte Arabic code points are copied without trimming');
        else {
            sourceExpect($lengthDecoded['reason'], 'INVALID_EVIDENCE', '201 code points remain rejected');
            sourceExpect($lengthDecoded['validation_category'], 'SOURCE_REFERENCE_INVALID', 'overlong span has fixed source diagnostic');
            sourceExpect($lengthDecoded['validation_field'], 'ITEM_NAME', 'overlong source does not expose rejected text');
        }
    }
    $sixty = [];
    for ($i = 1; $i <= 60; $i++) $sixty[] = sourceRow('m' . $i, 'customer', 'مرحبا', 1);
    sourceExpect(Selection::build($sixty)['ok'], true, 'exactly sixty actual episode rows can be represented');
    $tooMany = $sixty; $tooMany[] = sourceRow('m61', 'customer', 'مرحبا', 1);
    sourceNoHttp($tooMany, 'sixty-one rows', 'INVALID_INPUT');
    sourceNoHttp([sourceRow('m1', 'customer', str_repeat('ر', 16001), 1)], 'single row over 16000 Unicode code points', 'INVALID_INPUT');
    sourceNoHttp([sourceRow('m1', 'customer', str_repeat('ر', 8000), 1), sourceRow('m2', 'customer', str_repeat('ر', 8001), 2)],
        '16001 aggregate Unicode code points', 'INPUT_TOO_LARGE');
    $invalidUtf8 = [sourceRow('m1', 'customer', "اسم\xff", 1)];
    sourceNoHttp($invalidUtf8, 'invalid native UTF-8', 'INVALID_INPUT');
    $clauses200 = [];
    for ($i = 1; $i <= 8; $i++) $clauses200[] = sourceRow('m' . $i, 'business', implode('؛', array_fill(0, 25, 'طبق خاص')), 1);
    $clauseLimit = Selection::build($clauses200);
    sourceExpect($clauseLimit['ok'], true, 'exactly 200 real business clauses can be offered');
    sourceExpect(count($clauseLimit['data']['input']['summary_clauses']), 200, 'no recap choices are dropped at the supported boundary');
    $clauses201 = $clauses200; $clauses201[0]['text'] .= '؛طبق إضافي';
    sourceNoHttp($clauses201, '201 actual summary choices', 'INPUT_TOO_LARGE');
    $enum1000Rows = sourceEnumBudgetRows(138);
    sourceExpect(count(array_filter($enum1000Rows, static fn ($row) => array_key_exists('cart', $row))), 5,
        '1000-enum boundary contains exactly five actual cart-bearing rows');
    foreach (array_slice($enum1000Rows, 0, 5) as $cartRow) {
        sourceExpect(is_array($cartRow['cart']), true, '1000-enum boundary cart construction never silently becomes null');
        sourceExpect(\App\Support\WhatsAppInboxProtocol::normalizedCart($cartRow['cart']), $cartRow['cart'],
            '1000-enum boundary retains each complete normalized cart before budget evaluation');
    }
    sourceExpect(array_sum(array_map(static fn ($row) => count($row['cart']['product_items'] ?? []), $enum1000Rows)), 138,
        '1000-enum boundary preserves all 138 actual product lines');
    sourceExpect(Extraction::validateTranscript($enum1000Rows)['ok'], true, 'enum boundary fixture is valid original bounded input');
    $enum1000 = Selection::build($enum1000Rows);
    sourceExpect($enum1000['ok'], true, 'exactly 1000 enum members remain representable by compiler');
    sourceExpect(sourceEnumMembers($enum1000['data']['schema']), 1000, 'enum budget counts the entire nested schema');
    sourceExpect(strlen(json_encode(['input' => $enum1000['data']['input'], 'schema' => $enum1000['data']['schema']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) <= 131072, true,
        'supported compiler enum boundary stays inside compiler byte budget');
    $enum1001Rows = sourceEnumBudgetRows(139);
    sourceExpect(count(array_filter($enum1001Rows, static fn ($row) => array_key_exists('cart', $row))), 5,
        '1001-enum boundary contains exactly five actual cart-bearing rows');
    foreach (array_slice($enum1001Rows, 0, 5) as $cartRow) {
        sourceExpect(is_array($cartRow['cart']), true, '1001-enum boundary cart construction never silently becomes null');
        sourceExpect(\App\Support\WhatsAppInboxProtocol::normalizedCart($cartRow['cart']), $cartRow['cart'],
            '1001-enum boundary retains each complete normalized cart before budget evaluation');
    }
    sourceExpect(array_sum(array_map(static fn ($row) => count($row['cart']['product_items'] ?? []), $enum1001Rows)), 139,
        '1001-enum boundary preserves all 139 actual product lines');
    sourceExpect(Extraction::validateTranscript($enum1001Rows)['ok'], true, 'one extra actual retailer line still meets original transcript contract');
    $enum1001 = Selection::build($enum1001Rows);
    sourceExpect($enum1001['ok'], false, '1001 schema enum members fail closed rather than omit a line or source');
    sourceExpect($enum1001['reason'], 'INPUT_TOO_LARGE', 'enum over-budget has a fixed input-size reason');
    sourceNoHttp($enum1001Rows, '1001 enum members', 'INPUT_TOO_LARGE');
    sourceNoHttp($enum1000Rows, 'complete request including instructions and encoded input over byte bound', 'INPUT_TOO_LARGE');
    $byteRows = [sourceRow('m1', 'customer', str_repeat('𐐀', 16000), 1)];
    sourceExpect(Extraction::validateTranscript($byteRows)['ok'], true, 'request byte overflow fixture stays inside original 16k character bound');
    sourceNoHttp($byteRows, 'serialized input tables and schema over 131072 bytes', 'INPUT_TOO_LARGE');

    // Dynamic choices use only the current actual episode, preserving supplied labels.
    [$shiftedRows, $shiftedData] = sourceShifted($rows, $data, 3);
    $old = sourceRow('m1', 'customer', 'اسمي اسم سابق وعنواني عنوان سابق وعايز فسيخ', 1);
    $old['location'] = ['lat' => 30.1, 'long' => 31.2];
    $episodeRows = array_merge([$old, sourceRow('m2', 'customer', 'هذا الطلب اختبار لا ترسله', 2),
        sourceRow('m3', 'customer', 'عايز طلب جديد كيلو ونص فسيخ كامل وكيلو رنجه', 3)], $shiftedRows);
    sourceExpect(Evidence::operationalTest($episodeRows), false, 'explicit new order establishes a post-test episode');
    $episode = sourceExtract($episodeRows, static fn ($input) => sourceWire($input, $shiftedData));
    sourceExpect($episode['calls'], 1, 'post-test genuine order still uses one fake provider call');
    sourceExpect($episode['result']['ok'], true, 'same full fragmented proof works with original shifted episode labels');
    sourceSame($episode['result']['data'], $shiftedData, 'source choices preserve all actual post-test facts and labels');
    sourceExpect($episode['input']['transcript'], Evidence::episodeTranscript($episodeRows), 'Provider sends only the independently bounded current episode');
    sourceExpect($episode['input']['transcript'][0]['id'], 'm3', 'episode filter never relabels to m1');
    sourceExpect(in_array('m1', array_column($episode['input']['name_tokens'], 'message_id'), true), false, 'old name source is not offered to model');
    sourceExpect(in_array('m2', array_column($episode['input']['name_tokens'], 'message_id'), true), false, 'old test row is not offered to model');
    sourceExpect($episode['request']['text']['format']['schema']['properties']['evidence']['properties']['location_id']['enum'], [null],
        'old GPS cannot appear in dynamic current-episode schema');
    $borrowed = sourceExtract($episodeRows, static function ($input) use ($shiftedData): array {
        $wire = sourceWire($input, $shiftedData); $wire['items'][0]['name_source'] = ['message_id' => 'm1', 'source' => 'text', 'first_token' => 0, 'last_token' => 0];
        $wire['evidence']['request_ids'][] = 'm1'; return $wire;
    });
    sourceRejected($borrowed, 'old episode source borrowing');
    $coded = $episodeRows; $coded[0]['text'] .= ' WA-1234';
    $codedRun = sourceExtract($coded, static function (): array { throw new RuntimeException('Coded test must not call fake HTTP.'); });
    sourceExpect($codedRun['calls'], 0, 'global coded test guard runs before source selection and episode filter');
    sourceExpect($codedRun['result']['data']['decision'], 'NONE', 'new source contract cannot bypass a global test marker');
    sourceExpect($codedRun['result']['data']['issues'], ['TEST_MESSAGE'], 'coded test retains fixed original reason');
    echo 'WHATSAPP_ORDER_OPTION_SOURCE_CHECKS=' . $checks . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    echo "WHATSAPP_ORDER_OPTION_SOURCE_TEST_FAILED\n";
    exit(1);
}
