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
echo "CRM_FEEDBACK_TEMPLATE_DIAGNOSTIC_OK {$checks}\n";
