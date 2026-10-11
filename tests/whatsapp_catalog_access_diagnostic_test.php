<?php

define('WHATSAPP_CATALOG_ACCESS_DIAGNOSTIC_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/deployment/whatsapp_catalog_access_diagnostic.php';
use App\Support\WhatsAppCatalogAccessDiagnostic as Diagnostic;

$checks = 0;
function accessCheck($actual, $expected, string $label): void {
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function accessResponse(array $body, int $status = 200): array {
    return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
}
function accessError(int $code = 100, string $message = 'Unsupported get request. Object with ID PRIVATE_OBJECT does not exist, cannot be loaded due to missing permissions, or does not support this operation.'): array {
    return accessResponse(['error' => ['code' => $code, 'error_subcode' => 33, 'type' => 'OAuthException',
        'message' => $message, 'fbtrace_id' => 'PRIVATE_TRACE']], 400);
}
function accessSuccess(string $url): array {
    if (strpos($url, '/debug_token?') !== false) return accessResponse(['data' => [
        'is_valid' => true, 'app_id' => Diagnostic::APP, 'user_id' => 'PRIVATE_USER',
        'scopes' => ['business_management', 'whatsapp_business_management', 'whatsapp_business_messaging', 'private_scope'],
        'granular_scopes' => [['scope' => 'PRIVATE_SCOPE', 'target_ids' => ['PRIVATE_ASSET']]]]]);
    if (strpos($url, '/product_catalogs?') !== false) return accessResponse(['data' => [['id' => Diagnostic::CATALOG, 'name' => 'PRIVATE_CATALOG_TITLE']]]);
    if (strpos($url, '/products?') !== false) return accessResponse(['data' => [['id' => '7654321', 'retailer_id' => 'PRIVATE_RETAILER', 'name' => 'PRIVATE_PRODUCT_TITLE']]]);
    return accessResponse(['id' => Diagnostic::CATALOG, 'name' => 'PRIVATE_CATALOG_TITLE']);
}
function accessPrivate(array $result): void {
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (['PRIVATE_', 'EAAfixtureSecretToken', str_repeat('a', 32), 'input_token', 'fbtrace', 'granular_scopes', 'target_ids',
        'user_id', 'application', 'retailer_id', 'name', 'message', 'private_scope'] as $private) {
        accessCheck(strpos($json, $private), false, 'private response field absent ' . $private);
    }
    accessCheck(strlen($json) < 10240, true, 'report below ten KB');
}

$calls = [];
$token = 'EAAfixtureSecretToken'; $secret = str_repeat('a', 32);
$diagnostic = new Diagnostic(function ($url, $bearer) use (&$calls) {
    $calls[] = [$url, $bearer]; return accessSuccess($url);
});
$result = $diagnostic->inspect($token, $secret);
accessCheck($result['status'], 'READY', 'all probes succeeded');
accessCheck($result['conclusion'], 'REQUESTED_PRODUCTS_READ_WORKS', 'actual detailed product read establishes access');
accessCheck($result['network_requests'], 5, 'at most five fixed requests');
accessCheck($result['writes'], 0, 'read-only result');
accessCheck($result['token_scope_check']['token_valid'], true, 'token validity reported');
accessCheck($result['token_scope_check']['app_matches'], true, 'configured app match');
accessCheck($result['token_scope_check']['catalog_management'], false, 'absent catalog scope reported without inferring failure');
accessCheck($result['token_scope_check']['business_management'], true, 'business scope reported');
accessCheck($result['catalog_association']['present'], true, 'associated catalog found');
accessCheck($calls[0], ['https://graph.facebook.com/v25.0/debug_token?input_token=' . rawurlencode($token), Diagnostic::APP . '|' . $secret], 'fixed debug endpoint and app authentication');
accessCheck($calls[1][0], 'https://graph.facebook.com/v25.0/' . Diagnostic::WABA . '/product_catalogs?fields=id&limit=10', 'fixed scoped association endpoint');
accessCheck($calls[3][0], 'https://graph.facebook.com/v25.0/' . Diagnostic::CATALOG . '/products?fields=id&limit=1', 'minimal product request isolates fields');
accessCheck($calls[4][0], 'https://graph.facebook.com/v25.0/' . Diagnostic::CATALOG . '/products?fields=id%2Cretailer_id%2Cname&limit=1', 'same requested fields under one-product bound');
foreach (array_slice($calls, 1) as $call) accessCheck($call[1], $token, 'stored token sent only as bearer for asset requests');
accessPrivate($result);

$generic = new Diagnostic(fn () => accessError());
$result = $generic->inspect($token);
accessCheck($result['network_requests'], 4, 'missing app secret skips debug only');
accessCheck($result['token_scope_check']['status'], 'APP_SECRET_UNAVAILABLE', 'no credential guess');
accessCheck($result['conclusion'], 'ACCESS_OR_REQUEST_UNRESOLVED', 'code 100 never identifies the cause alone');
accessCheck($result['probes'][0]['code'], 100, 'safe code retained');
accessCheck($result['probes'][0]['subcode'], 33, 'safe subcode retained');
accessCheck($result['probes'][0]['error_type'], 'OAUTH_EXCEPTION', 'error type whitelist');
accessCheck($result['probes'][0]['unsupported_get_indication'], true, 'unsupported indication only');
accessCheck($result['probes'][0]['object_unavailable_indication'], true, 'object indication only');
accessCheck($result['probes'][0]['permission_indication'], true, 'permission wording indication only');
accessCheck($result['catalog_association']['present'], null, 'denied edge does not imply no association');
accessPrivate($result);

$fields = new Diagnostic(function ($url) {
    return strpos($url, 'retailer_id') !== false ? accessError(100, 'Tried accessing nonexisting field PRIVATE_FIELD on node type PRIVATE_TYPE') : accessSuccess($url);
});
$result = $fields->inspect($token);
accessCheck($result['conclusion'], 'FIELD_OR_EDGE_REQUEST_REQUIRES_REVIEW', 'working minimal read narrows failed fields request');
accessCheck($result['probes'][3]['field_indication'], true, 'explicit nonexisting field indication');
accessPrivate($result);

$edge = new Diagnostic(fn ($url) => strpos($url, '/products?') !== false ? accessError() : accessSuccess($url));
accessCheck($edge->inspect($token)['conclusion'], 'CATALOG_VISIBLE_PRODUCTS_EDGE_UNAVAILABLE', 'object visibility does not establish products access');
$wrongApp = new Diagnostic(function ($url) {
    if (strpos($url, '/debug_token?') !== false) return accessResponse(['data' => ['is_valid' => true, 'app_id' => '99999999', 'scopes' => []]]);
    return accessSuccess($url);
});
$result = $wrongApp->inspect($token, $secret);
accessCheck($result['token_scope_check']['app_matches'], false, 'wrong app recorded without exposing other app or skipping asset probes');
accessCheck($result['network_requests'], 5, 'wrong app still inspects actual asset access');
accessPrivate($result);
$invalid = new Diagnostic(function ($url) {
    return strpos($url, '/debug_token?') !== false ? accessResponse(['data' => ['is_valid' => false]]) : accessError(190, 'PRIVATE_TOKEN invalid');
});
$result = $invalid->inspect($token, $secret);
accessCheck($result['token_scope_check']['token_valid'], false, 'invalid token shape needs no app ID');
accessCheck($result['token_scope_check']['app_matches'], null, 'invalid token app unknown');
accessCheck($result['conclusion'], 'TOKEN_INVALID_OR_EXPIRED', 'explicit invalid token is actionable');
accessPrivate($result);

$paged = new Diagnostic(function ($url) {
    if (strpos($url, '/product_catalogs?') !== false) return accessResponse(['data' => [['id' => '9999999']],
        'paging' => ['next' => 'https://evil.example/PRIVATE_NEXT?access_token=PRIVATE_TOKEN']]);
    return accessSuccess($url);
});
$result = $paged->inspect($token);
accessCheck($result['catalog_association']['present'], null, 'incomplete association page cannot prove absence');
accessCheck($result['catalog_association']['complete'], false, 'association truncation explicit');
accessPrivate($result);
$absent = new Diagnostic(fn ($url) => strpos($url, '/product_catalogs?') !== false ? accessResponse(['data' => []]) : accessSuccess($url));
accessCheck($absent->inspect($token)['catalog_association']['present'], false, 'complete edge result target not returned');
accessCheck($absent->inspect($token)['catalog_association']['absence_means'], 'NOT_RETURNED_BY_THIS_TOKEN', 'edge visibility does not imply global unlinking');
$malformed = new Diagnostic(function ($url) {
    if (strpos($url, '/product_catalogs?') !== false) return accessResponse(['data' => array_fill(0, 11, ['id' => Diagnostic::CATALOG])]);
    return ['status' => 200, 'body' => '{PRIVATE_BROKEN_JSON'];
});
$result = $malformed->inspect($token, $secret);
accessCheck($result['catalog_association']['status'], 'INVALID_RESPONSE', 'association row bound');
accessCheck($result['probes'][4]['status'], 'INVALID_RESPONSE', 'bad JSON rejected');
accessPrivate($result);
$huge = new Diagnostic(fn () => ['status' => 200, 'body' => str_repeat('x', 1048577)]);
accessCheck($huge->inspect($token)['probes'][0]['status'], 'INVALID_RESPONSE', 'body size bound');
$redirect = new Diagnostic(fn () => ['status' => 302, 'body' => '{}']);
$result = $redirect->inspect($token);
accessCheck($result['probes'][0]['http'], 302, 'redirect response described without fetching its location');
accessCheck($result['network_requests'], 4, 'no adaptive redirects or followups');
$throwing = new Diagnostic(fn () => throw new RuntimeException('PRIVATE_HTTP_EXCEPTION'));
$result = $throwing->inspect($token, $secret);
accessCheck($result['probes'][0]['status'], 'TRANSPORT_ERROR', 'transport exceptions never exposed');
accessPrivate($result);
$rate = new Diagnostic(fn () => accessError(613, 'PRIVATE_RATE_MESSAGE'));
accessCheck($rate->inspect($token)['probes'][0]['rate_limit_indication'], true, 'fixed rate-limit indication');
foreach (['', "PRIVATE_TOKEN\n", str_repeat('x', 4097)] as $badToken) {
    $never = new Diagnostic(fn () => throw new RuntimeException('MUST_NOT_CALL'));
    accessCheck($never->inspect($badToken, $secret)['network_requests'], 0, 'invalid token input makes no request');
}
foreach (['', 'PRIVATE_SECRET', str_repeat('a', 31)] as $badSecret) accessCheck($diagnostic->inspect($token, $badSecret)['network_requests'], 4, 'invalid app secret is never used');

foreach ([[['PRIVATE_NESTED_SCOPE']], [123], ['invalid scope'], [''], array_fill(0, 101, 'catalog_management')] as $badScopes) {
    $malformedScope = new Diagnostic(function ($url) use ($badScopes) {
        if (strpos($url, '/debug_token?') !== false) return accessResponse(['data' => ['is_valid' => true, 'app_id' => Diagnostic::APP, 'scopes' => $badScopes]]);
        return accessSuccess($url);
    });
    $result = $malformedScope->inspect($token, $secret);
    accessCheck($result['token_scope_check']['status'], 'INVALID_RESPONSE', 'malformed scope list rejected');
    accessCheck($result['token_scope_check']['catalog_management'], null, 'malformed scopes cannot imply missing permission');
}
foreach ([null, [], ['next' => []], ['next' => 123], ['next' => '']] as $badPaging) {
    if ($badPaging === []) continue;
    $malformedPaging = new Diagnostic(function ($url) use ($badPaging) {
        if (strpos($url, '/product_catalogs?') !== false) return accessResponse(['data' => [], 'paging' => $badPaging]);
        return accessSuccess($url);
    });
    $result = $malformedPaging->inspect($token);
    accessCheck($result['catalog_association']['status'], 'INVALID_RESPONSE', 'malformed paging rejected');
    accessCheck($result['catalog_association']['present'], null, 'malformed paging cannot imply absent association');
}
$malformedId = new Diagnostic(function ($url) {
    if (strpos($url, '/products?') !== false) return accessResponse(['data' => [['id' => 'PRIVATE_NONCANONICAL_ID', 'retailer_id' => 'PRIVATE_RETAILER', 'name' => 'PRIVATE_NAME']]]);
    return accessSuccess($url);
});
$result = $malformedId->inspect($token);
accessCheck($result['probes'][3]['status'], 'INVALID_RESPONSE', 'product IDs must be canonical Graph IDs');
accessPrivate($result);
$malformedType = new Diagnostic(fn () => accessResponse(['error' => ['code' => 100, 'type' => ['PRIVATE_TYPE'], 'message' => 'PRIVATE_MESSAGE']], 400));
accessCheck($malformedType->inspect($token)['probes'][0]['error_type'], 'OTHER', 'non-string error type cannot expose values or throw');

echo "PASSED {$checks} catalog access diagnostic checks\n";
