<?php
// Fake provider only; the diagnostic is included without booting Laravel or running its CLI main.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) { fwrite(STDERR, "CRM_TEST_VENDOR_REQUIRED\n"); exit(1); }
require $autoload;
ob_start(); require __DIR__ . '/../deployment/diagnose_customer_followup_template.php'; $includedOutput = ob_get_clean();
$checks = 0;
function checkDiagnostic($value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
checkDiagnostic($includedOutput === '', 'including diagnostic never runs main or bootstraps production');
$endpoint = 'https://graph.facebook.com/v25.0/468336579702269/message_templates';
$token = 'fixture-secret-token'; $calls = [];
$transport = function ($method, $url, $payload, $provided) use (&$calls, $token) {
    $calls[] = [$method, $url, $payload];
    checkDiagnostic($provided === $token, 'existing token passed only to guarded transport');
    return ['status' => $method === 'GET' ? 200 : 400, 'body' => json_encode($method === 'GET' ? ['data' => []] : ['error' => [
        'code' => 100, 'error_subcode' => '2388047', 'type' => 'OAuthException',
        'message' => "Rejected {$token}; Bearer unexpected-secret; access_token=another-secret",
        'error_user_title' => 'قالب غير صالح', 'error_user_msg' => "Details\n{$token}",
        'error_data' => ['details' => 'Authorization: hidden-secret', 'private_payload' => 'private-never-expose'],
        'fbtrace_id' => 'trace-never-expose', 'access_token' => 'raw-never-expose']])];
};
$diagnostic = new CustomerFollowupTemplateDiagnostic($transport);
$out = $diagnostic->run(function () use ($diagnostic, $endpoint, $token) {
    $diagnostic->request('GET', $endpoint, ['fields' => 'name,status'], $token);
    $diagnostic->request('POST', $endpoint, ['name' => 'fasakhansta_order_feedback_v1'], $token);
    throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'FEEDBACK_TEMPLATE_PROVIDER_REJECTED');
});
checkDiagnostic(!$out['ok'] && $out['service'] === ['http_status' => 422, 'code' => 'FEEDBACK_TEMPLATE_PROVIDER_REJECTED'], 'safe service rejection retained');
checkDiagnostic(count($out['provider']) === 2 && $out['provider'][0] === ['method' => 'GET', 'http_status' => 200], 'bounded successful preflight summary contains no raw body');
$error = $out['provider'][1]['error'];
checkDiagnostic($error['code'] === 100 && $error['error_subcode'] === 2388047 && $error['type'] === 'OAuthException'
    && $error['error_user_title'] === 'قالب غير صالح', 'Meta numeric code, subcode and review explanation retained');
$encoded = json_encode($out, JSON_UNESCAPED_UNICODE);
foreach ([$token, 'unexpected-secret', 'another-secret', 'hidden-secret', 'private-never-expose', 'trace-never-expose', 'raw-never-expose'] as $secret) {
    checkDiagnostic(strpos($encoded, $secret) === false, 'output redacts credential/private field ' . $secret);
}
$safe = CustomerFollowupTemplateDiagnostic::safeProviderError(['message' => str_repeat('X', 2000), 'error_user_msg' => "Line\nBreak\x00",
    'error_data' => ['details' => ['unsupported' => 'value']], 'code' => -1, 'error_subcode' => 'not-a-code'], $token);
checkDiagnostic(strlen($safe['message']) === 600 && $safe['error_user_msg'] === 'Line Break ' && !isset($safe['code'], $safe['error_subcode'], $safe['details']), 'safe provider fields bounded and control characters removed');
foreach ([['POST', 'https://graph.facebook.com/v25.0/515388018324075/messages'], ['GET', $endpoint . '?access_token=never'],
    ['POST', 'https://untrusted.invalid/message_templates'], ['DELETE', $endpoint]] as $blocked) {
    $before = count($calls);
    $out = $diagnostic->run(fn() => $diagnostic->request($blocked[0], $blocked[1], [], $token));
    checkDiagnostic(!$out['ok'] && $out['service']['code'] === 'DIAGNOSTIC_REQUEST_BLOCKED' && count($calls) === $before, 'guard blocks alternate endpoint/method before transport');
}
$diagnostic = new CustomerFollowupTemplateDiagnostic(function () use ($token) { throw new RuntimeException('curl failure ' . $token); });
$out = $diagnostic->run(fn() => $diagnostic->request('GET', $endpoint, [], $token));
checkDiagnostic(!$out['ok'] && $out['service']['code'] === 'DIAGNOSTIC_TRANSPORT_UNAVAILABLE'
    && $out['provider'] === [['method' => 'GET', 'transport_error' => true]] && strpos(json_encode($out), $token) === false, 'transport exception never leaks provider message or credentials');
$diagnostic = new CustomerFollowupTemplateDiagnostic(fn() => ['status' => 200, 'body' => str_repeat('X', 1048577)]);
$out = $diagnostic->run(fn() => $diagnostic->request('GET', $endpoint, [], $token));
checkDiagnostic(!$out['ok'] && $out['service']['code'] === 'DIAGNOSTIC_INVALID_PROVIDER_RESPONSE', 'oversized raw response cannot enter diagnostics');
$out = $diagnostic->run(function () { throw new RuntimeException('unexpected secret-bearing exception'); });
checkDiagnostic($out['service']['code'] === 'DIAGNOSTIC_UNAVAILABLE' && !isset($out['service']['message']), 'unknown exception messages and backtraces never included');
$out = $diagnostic->run(fn() => ['success' => true, 'created' => false, 'feedback_setup' => ['state' => 'pending', 'private' => 'never-expose']]);
checkDiagnostic($out === ['ok' => true, 'created' => false, 'feedback_setup' => ['state' => 'pending'], 'provider' => []], 'existing pending template returns safe lifecycle result without a retry');
$statusCalls = [];
$rawRows = [['name' => 'other_template', 'language' => 'ar', 'category' => 'UTILITY', 'status' => 'APPROVED',
    'components' => [['text' => 'catalogue-private']]],
    ['name' => 'fasakhansta_order_feedback_v1', 'language' => 'ar', 'category' => 'MARKETING', 'status' => 'REJECTED',
    'rejected_reason' => 'Rejected ' . $token, 'components' => [['text' => 'body-private']]]];
$statusTransport = function ($method, $url, $payload, $provided) use (&$statusCalls, $rawRows) {
    $statusCalls[] = [$method, $url, $payload];
    return ['status' => 200, 'body' => json_encode(['data' => $rawRows])];
};
$statusDiagnostic = new CustomerFollowupTemplateDiagnostic($statusTransport, true);
$fixture = ['available' => true, 'reason' => null, 'enabled' => false, 'feedback_enabled' => false, 'feedback_ready' => false,
    'feedback_reason' => 'APPROVED_FEEDBACK_TEMPLATE_REQUIRED', 'feedback_template' => '', 'feedback_language' => 'ar',
    'feedback_body' => 'طلب رقم {{1}} – فرع {{2}}. شكرًا لاختيارك فسخانستا.',
    'feedback_setup' => ['state' => 'conflict', 'name' => 'fasakhansta_order_feedback_v1', 'language' => 'ar', 'category' => 'UTILITY',
        'template_status' => null, 'reason' => 'FEEDBACK_TEMPLATE_CONFLICT', 'can_prepare' => false,
        'matches' => array_fill(0, 25, ['name' => 'feedback', 'language' => 'ar', 'category' => 'UTILITY', 'status' => 'PENDING', 'body' => 'match-private'])],
    'feedback_templates' => array_fill(0, 25, ['name' => 'feedback', 'language' => 'ar', 'body' => 'template-private', 'parameters' => 2]),
    'total_count' => 12345, 'eligible_count' => 123, 'feedback_eligible_count' => 42, 'campaigns' => [['customer' => 'customer-private']],
    'marketing_templates' => [['body' => 'marketing-private']]];
$out = $statusDiagnostic->run(function () use ($statusDiagnostic, $endpoint, $token, $fixture) {
    $statusDiagnostic->request('GET', $endpoint, ['fields' => 'name,status', 'after' => 'cursor'], $token);
    return $fixture;
});
checkDiagnostic($out['ok'] && $out['status']['feedback_setup']['state'] === 'conflict' && !$out['status']['enabled'], 'read-only snapshot preserves conflict and inactive sender distinction');
checkDiagnostic($statusCalls[0][2]['fields'] === 'name,status,language,category,components,rejected_reason'
    && $statusCalls[0][2]['after'] === 'cursor', 'read-only GET adds rejection metadata while preserving bounded service pagination');
checkDiagnostic($out['provider'][0]['fixed_templates'] === [['name' => 'fasakhansta_order_feedback_v1', 'language' => 'ar',
    'category' => 'MARKETING', 'status' => 'REJECTED', 'rejected_reason' => 'Rejected [redacted]']], 'fixed-name raw category and rejection visible even when unsupported for feedback selector');
checkDiagnostic($out['status']['expected_body_sha256'] === hash('sha256', $fixture['feedback_body']) && $out['status']['contains_substantive_suffix'], 'expected body hash and substantive suffix verify deployed feedback text without dumping body');
checkDiagnostic(count($out['status']['feedback_setup']['matches']) === 20 && count($out['status']['feedback_templates']) === 20, 'template identifier lists capped at twenty');
$encoded = json_encode($out);
foreach (['catalogue-private', 'body-private', 'match-private', 'template-private', 'customer-private', 'marketing-private',
    'total_count', 'eligible_count', 'campaigns', 'parameters', $token] as $private) {
    checkDiagnostic(strpos($encoded, $private) === false, 'status output excludes customer/catalogue/body field ' . $private);
}
foreach ([['POST', $endpoint], ['POST', 'https://graph.facebook.com/v25.0/515388018324075/messages'], ['DELETE', $endpoint]] as $blocked) {
    $before = count($statusCalls);
    $out = $statusDiagnostic->run(fn() => $statusDiagnostic->request($blocked[0], $blocked[1], [], $token));
    checkDiagnostic(!$out['ok'] && $out['service']['code'] === 'DIAGNOSTIC_REQUEST_BLOCKED' && count($statusCalls) === $before,
        'read-only mode rejects mutation and customer message endpoints before transport');
}
foreach (['pending', 'rejected', 'missing', 'conflict', 'unavailable', 'approved'] as $state) {
    $fixture['feedback_setup']['state'] = $state;
    $fixture['reason'] = $state === 'unavailable' ? 'TEMPLATE_LOOKUP_UNAVAILABLE' : null;
    $fixture['feedback_setup']['reason'] = $state === 'unavailable' ? 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED' : null;
    $fixture['enabled'] = $state === 'approved'; $fixture['feedback_enabled'] = false; $fixture['feedback_ready'] = $state === 'approved';
    $out = $statusDiagnostic->run(fn() => $fixture);
    checkDiagnostic($out['status']['feedback_setup']['state'] === $state && $out['status']['enabled'] === ($state === 'approved')
        && !$out['status']['feedback_enabled'] && $out['status']['feedback_ready'] === ($state === 'approved'), 'snapshot keeps lifecycle, readiness and activation distinct: ' . $state);
    if ($state === 'unavailable') checkDiagnostic($out['status']['feedback_setup']['reason'] === 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED', 'permission diagnostic retained in read-only setup');
}
$fixture['reason'] = 'customer-private'; $fixture['feedback_setup']['reason'] = 'customer-private';
$fixture['feedback_body'] = 'legacy {{2}}.';
$out = $statusDiagnostic->run(fn() => $fixture);
checkDiagnostic($out['status']['reason'] === 'UNRECOGNIZED_STATUS_REASON' && $out['status']['feedback_setup']['reason'] === 'UNRECOGNIZED_STATUS_REASON'
    && !$out['status']['contains_substantive_suffix'], 'unknown reason values never leak arbitrary text and old period-only body is identified');

$fallbackCalls = [];
$fallback = new CustomerFollowupTemplateDiagnostic(function ($method, $url, $payload) use (&$fallbackCalls, $rawRows) {
    $fallbackCalls[] = [$method, $url, $payload];
    return count($fallbackCalls) === 1
        ? ['status' => 400, 'body' => '{"error":{"code":100,"message":"Unknown field rejected_reason"}}']
        : ['status' => 200, 'body' => json_encode(['data' => $rawRows])];
}, true);
$out = $fallback->run(function () use ($fallback, $endpoint, $token, $fixture) {
    $response = $fallback->request('GET', $endpoint, ['fields' => 'name,status,language,category,components', 'after' => 'safe-cursor'], $token);
    checkDiagnostic($response['status'] === 200, 'unsupported optional rejection field falls back to standard successful lookup');
    return $fixture;
});
checkDiagnostic(count($fallbackCalls) === 2 && $fallbackCalls[1] === ['GET', $endpoint,
    ['fields' => 'name,status,language,category,components', 'after' => 'safe-cursor']], 'fallback repeats only fixed GET using original fields and cursor');
checkDiagnostic(count($out['provider']) === 2 && $out['provider'][0]['error']['code'] === 100
    && $out['provider'][1]['fixed_templates'][0]['category'] === 'MARKETING', 'first optional-field error remains safely visible alongside fallback raw metadata');
foreach ([[403, 200], [500, 100], [400, 10]] as $failure) {
    $attempts = 0;
    $oneAttempt = new CustomerFollowupTemplateDiagnostic(function () use (&$attempts, $failure) {
        $attempts++; return ['status' => $failure[0], 'body' => json_encode(['error' => ['code' => $failure[1]]])];
    }, true);
    $oneAttempt->run(fn() => $oneAttempt->request('GET', $endpoint, ['fields' => 'name,status'], $token));
    checkDiagnostic($attempts === 1, 'permissions/server errors never trigger optional-field retry');
}

echo "CRM_FEEDBACK_TEMPLATE_DIAGNOSTIC_OK {$checks}\n";
