<?php

define('WHATSAPP_CATALOG_READINESS_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/deployment/whatsapp_catalog_mapping_readiness.php';
use App\Support\WhatsAppCatalogMappingReadiness as Inspector;

$checks = 0;
function expect($actual, $wanted, string $label): void {
    global $checks; $checks++;
    if ($actual !== $wanted) throw new RuntimeException('FAILED: ' . $label);
}
function response(array $data, array $paging = []): array {
    return ['status' => 200, 'body' => json_encode(['data' => $data, 'paging' => $paging], JSON_THROW_ON_ERROR)];
}
function product(string $retailer = 'catalog-feseekh-quarter', string $name = 'ربع كيلو فسيخ'): array {
    return ['id' => '1234567', 'retailer_id' => $retailer, 'name' => $name];
}
function erp(int $id = 11, string $name = 'فسيخ', string $mode = 'weight', bool $available = true): array {
    return ['id' => $id, 'name' => $name, 'quantity_mode' => $mode, 'available' => $available];
}

$calls = [];
$pages = [response([product()], ['next' => 'https://evil.example/?access_token=DO_NOT_FOLLOW', 'cursors' => ['after' => 'opaque+cursor/==']]),
    response([product('renga-half', 'نصف كيلو رنجة')])];
$inspector = new Inspector(function ($url, $token) use (&$calls, &$pages) {
    $calls[] = [$url, $token]; return array_shift($pages);
});
$result = $inspector->products('987654321', 'fixture-secret-bearer');
expect($result['status'], 'READY', 'bounded complete catalog');
expect($result['complete'], true, 'complete only after final page');
expect(count($result['products']), 2, 'two public catalog products');
expect(count($calls), 2, 'no unnecessary requests');
expect($calls[0][0], 'https://graph.facebook.com/v25.0/987654321/products?fields=id%2Cretailer_id%2Cname&limit=100', 'fixed endpoint and fields');
expect($calls[1][0], $calls[0][0] . '&after=opaque%2Bcursor%2F%3D%3D', 'cursor rebuilt on fixed Graph URL');
expect(strpos(json_encode($result), 'fixture-secret-bearer'), false, 'token absent from result');
expect($calls[0][1], 'fixture-secret-bearer', 'bearer passed only to transport');

$never = new Inspector(function () { throw new RuntimeException('UNEXPECTED_CALL'); });
expect($never->products('123/../456', 'fixture-token')['status'], 'INVALID_INPUT', 'catalog ID cannot change URL');
expect($never->products('123', "fixture\ntoken")['status'], 'INVALID_INPUT', 'header injection rejected');
expect($never->products('123', '')['pages_read'], 0, 'missing token no request');
$denied = new Inspector(fn () => ['status' => 400, 'body' => json_encode(['error' => ['code' => 200, 'message' => 'secret-token client text']])]);
expect($denied->products('123', 'secret-token')['status'], 'CATALOG_ACCESS_DENIED', 'permission refusal classified');
expect(strpos(json_encode($denied->products('123', 'secret-token')), 'secret-token'), false, 'API error message never returned');
$redirect = new Inspector(fn () => ['status' => 302, 'body' => '{}']);
expect($redirect->products('123', 'fixture-token')['status'], 'API_ERROR', 'redirect response not followed');
$large = new Inspector(fn () => ['status' => 200, 'body' => str_repeat('x', 1048577)]);
expect($large->products('123', 'fixture-token')['status'], 'INVALID_RESPONSE', 'large response bounded');
$malformed = new Inspector(fn () => ['status' => 200, 'body' => '{']);
expect($malformed->products('123', 'fixture-token')['status'], 'INVALID_RESPONSE', 'invalid JSON refused');
$rows = new Inspector(fn () => response(array_fill(0, 101, product())));
expect($rows->products('123', 'fixture-token')['status'], 'INVALID_RESPONSE', 'page item bound');
$duplicates = new Inspector(fn () => response([product(), product()]));
expect($duplicates->products('123', 'fixture-token')['status'], 'INVALID_PRODUCT_IDENTITY', 'duplicate retailer ambiguity refused');
$secretTitle = new Inspector(fn () => response([product('normal-id', 'fixture-token')]));
expect($secretTitle->products('123', 'fixture-token')['status'], 'INVALID_PRODUCT_IDENTITY', 'unexpected reflected credential refused');
$missingCursor = new Inspector(fn () => response([product()], ['next' => 'https://graph.facebook.com/next']));
expect($missingCursor->products('123', 'fixture-token')['status'], 'INVALID_PAGING', 'next URL cannot substitute cursor');
$pageNumber = 0;
$repeatedCursor = new Inspector(function () use (&$pageNumber) { return response([product('p' . ++$pageNumber)], ['next' => 'x', 'cursors' => ['after' => 'same']]); });
expect($repeatedCursor->products('123', 'fixture-token')['status'], 'INVALID_PAGING', 'cursor loop refused');
$pageNumber = 0;
$truncated = new Inspector(function () use (&$pageNumber) { return response([product('p' . ++$pageNumber)], ['next' => 'x', 'cursors' => ['after' => 'cursor' . $pageNumber]]); });
$result = $truncated->products('123', 'fixture-token');
expect($result['status'], 'CATALOG_TRUNCATED', 'three-page limit reported');
expect($result['pages_read'], 3, 'three network calls maximum');
expect($result['complete'], false, 'truncated catalog cannot certify mappings');

$inspector = new Inspector();
$candidate = $inspector->candidates([product()], [erp()], 'f:363')[0];
expect($candidate['status'], 'CANDIDATE_REQUIRES_MAPPING', 'explicit portion candidate only');
expect($candidate['candidates'][0]['product_id'], 11, 'ERP ID derived from scoped catalog');
expect($candidate['candidates'][0]['quantity_per_unit'], '0.25', 'quarter kg exact conversion');
expect($candidate['candidates'][0]['branch'], 'f:363', 'canonical mapping branch');
expect($candidate['candidates'][0]['option_id'], '', 'no feature guessed from weight');
$suffix = $inspector->candidates([product('p', 'فسيخ نصف كيلو')], [erp()], 'f:363')[0];
expect($suffix['candidates'][0]['quantity_per_unit'], '0.5', 'longer explicit suffix beats kilo overlap');
$piece = $inspector->candidates([product('p', 'وجبة ربع كيلو فسيخ')], [erp(22, 'وجبة ربع كيلو فسيخ', 'piece')], 'f:363')[0];
expect($piece['candidates'][0]['quantity_per_unit'], '1', 'portion meal sold per piece remains one meal');
$weightUnknown = $inspector->candidates([product('p', 'فسيخ')], [erp()], 'f:363')[0];
expect($weightUnknown['status'], 'UNIT_SEMANTICS_REQUIRED', 'weight listing alone cannot infer catalog package');
expect($weightUnknown['candidates'][0]['quantity_per_unit'], null, 'unknown package held');
$select = $inspector->candidates([product('p', 'فسيخ')], [erp(11, 'فسيخ', 'select')], 'f:363')[0];
expect($select['status'], 'UNIT_SEMANTICS_REQUIRED', 'operator-selectable quantity requires mapping');
expect($select['candidates'][0]['mode_resolution'], 'SELECT_REQUIRES_CHOICE', 'actual F catalog mode clearly identified');
expect($select['candidates'][0]['quantity_mode'], null, 'bare select title never guesses piece');
$selectQuarter = $inspector->candidates([product()], [erp(11, 'فسيخ', 'select')], 'f:363')[0];
expect($selectQuarter['status'], 'CANDIDATE_REQUIRES_MAPPING', 'unique base and explicit weight can propose select choice');
expect($selectQuarter['candidates'][0]['quantity_mode'], 'weight', 'proposed select mode is weight');
expect($selectQuarter['candidates'][0]['quantity_per_unit'], '0.25', 'proposed explicit quarter');
expect($selectQuarter['candidates'][0]['erp_quantity_mode'], 'select', 'proposal preserves real source mode');
expect($selectQuarter['candidates'][0]['mode_resolution'], 'SELECT_REQUIRES_CHOICE', 'proposal still needs configured choice');
$selectDuplicate = $inspector->candidates([product()], [erp(11, 'فسيخ', 'select'), erp(12, 'فسيخ', 'select')], 'f:363')[0];
expect($selectDuplicate['status'], 'AMBIGUOUS_MATCH', 'multiple select base identities held');
expect($selectDuplicate['candidates'][0]['quantity_mode'], null, 'ambiguous select never proposes mode');
expect($selectDuplicate['candidates'][0]['quantity_per_unit'], null, 'ambiguous select never proposes conversion');
$selectPackaged = $inspector->candidates([product('p', 'وجبة فسيخ ربع كيلو')], [erp(22, 'وجبة فسيخ', 'select')], 'f:363')[0];
expect($selectPackaged['status'], 'UNIT_SEMANTICS_REQUIRED', 'meal package cannot imply weight sale mode');
expect($selectPackaged['candidates'][0]['quantity_mode'], null, 'packaged select held');
$selectCan = $inspector->candidates([product('p', 'ربع كيلو علبة فسيخ')], [erp(22, 'علبة فسيخ', 'select')], 'f:363')[0];
expect($selectCan['status'], 'UNIT_SEMANTICS_REQUIRED', 'can package cannot imply weight sale mode');
$numeric = $inspector->candidates([product('11', 'رنجة')], [erp()], 'f:363')[0];
expect($numeric['status'], 'NO_EXACT_MATCH', 'numeric retailer ID never treated as ERP ID');
$fuzzy = $inspector->candidates([product('p', 'فسيخ نبروه')], [erp()], 'f:363')[0];
expect($fuzzy['status'], 'NO_EXACT_MATCH', 'no fuzzy or substring name mapping');
$ambiguous = $inspector->candidates([product()], [erp(), erp(12)], 'f:363')[0];
expect($ambiguous['status'], 'AMBIGUOUS_MATCH', 'duplicate names held');
$hidden = $inspector->candidates([product()], [erp(11, 'فسيخ', 'weight', false)], 'f:363')[0];
expect($hidden['status'], 'NO_EXACT_MATCH', 'unavailable ERP products excluded');
$whitespace = $inspector->candidates([product('p', '  وجبة   فسيخ  ')], [erp(22, 'وجبة فسيخ', 'piece')], 'f:363')[0];
expect($whitespace['status'], 'CANDIDATE_REQUIRES_MAPPING', 'whitespace normalization exact only');
$doublePortion = $inspector->candidates([product('p', 'ربع كيلو فسيخ نصف كيلو')], [erp()], 'f:363')[0];
expect($doublePortion['status'], 'NO_EXACT_MATCH', 'contradictory portion title held');
try { $inspector->candidates([], [], 'gs:363'); throw new RuntimeException('EXPECTED_BLOCK'); }
catch (InvalidArgumentException $e) { expect($e->getMessage(), 'INVALID_CATALOG_SCOPE', 'Fasakhansta branch only'); }
echo "PASSED {$checks} catalog mapping readiness checks\n";
