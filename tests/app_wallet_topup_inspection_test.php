<?php
// Pure response-privacy and request-bound checks; no credentials, database or network.
require __DIR__ . '/../deployment/inspect_app_wallet_topup.php';
$checks = 0;
function topupExpect($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++; echo 'PASS ' . $label . PHP_EOL;
}
$private = 'PRIVATE_SENTINEL_NEVER_PRINT';
$calls = [];
$attempts = [];
for ($id = 1; $id <= 8; $id++) $attempts[] = ['id' => $id, 'order_id' => 20 + $id, 'intention_order_id' => 30 + $id];
array_unshift($attempts, $attempts[0]);
$report = AppWalletTopupInspection::gateway($attempts, $private,
    static function ($path, $body) use (&$calls, $private) {
        $calls[] = [$path, array_keys($body)];
        if ($path === '/api/auth/tokens') return ['status' => 200, 'body' => ['token' => $private]];
        return ['status' => 200, 'body' => ['id' => 101, 'order' => ['id' => $body['order_id'], 'billing' => $private],
            'amount_cents' => 10000, 'currency' => 'EGP', 'integration_id' => 300,
            'success' => true, 'pending' => false, 'is_live' => true,
            'client_secret' => $private, 'billing_data' => ['email' => $private], 'source_data' => $private]];
    });
topupExpect(count($calls) === 5 && $report['selected_attempts'] === 4, 'one authentication and at most four distinct inquiries');
topupExpect($calls[0] === ['/api/auth/tokens', ['api_key']], 'authentication uses only the API key');
topupExpect($calls[1] === ['/api/ecommerce/orders/transaction_inquiry', ['auth_token', 'order_id']],
    'inquiry uses only authenticated order lookup');
topupExpect($report['transactions'][0]['transaction']['success'] === true
    && $report['transactions'][0]['transaction']['amount_cents'] === '10000', 'sanitized evidence retains settlement facts');
$encoded = json_encode($report);
topupExpect(strpos($encoded, $private) === false && strpos($encoded, 'billing_data') === false
    && strpos($encoded, 'client_secret') === false && strpos($encoded, 'source_data') === false,
    'tokens, customer data and raw response fields never enter the report');
$failure = AppWalletTopupInspection::gateway($attempts, $private, static function () use ($private) {
    throw new RuntimeException($private);
});
topupExpect($failure['authenticated'] === false && $failure['http_status_counts'] === ['transport_error' => 1]
    && strpos(json_encode($failure), $private) === false, 'transport exception is reduced to a count');
$httpFailure = AppWalletTopupInspection::gateway($attempts, $private,
    static fn() => ['status' => 401, 'body' => ['error' => $private, 'token' => $private]]);
topupExpect($httpFailure['http_status_counts'] === [401 => 1] && !$httpFailure['authenticated']
    && strpos(json_encode($httpFailure), $private) === false, 'HTTP failure payload and token are excluded');
$malformed = AppWalletTopupInspection::transaction(['id' => $private, 'order' => ['id' => $private],
    'currency' => $private, 'success' => $private, 'amount_cents' => $private]);
topupExpect($malformed['transaction_id'] === null && $malformed['order_id'] === null
    && $malformed['currency'] === null && $malformed['success'] === null, 'malformed scalar fields cannot carry private text');
topupExpect(AppWalletTopupInspection::gateway($attempts, '')['transactions'] === [], 'missing API key never makes inquiries');
$amount = new ReflectionMethod(AppWalletTopupInspection::class, 'amount'); $amount->setAccessible(true);
topupExpect($amount->invoke(null, 50.0) === '50' && $amount->invoke(null, 198.07) === '198.07',
    'PDO float wallet amounts and balances render as safe decimal strings');
topupExpect($amount->invoke(null, INF) === null && $amount->invoke(null, NAN) === null,
    'non-finite amounts cannot enter JSON');
topupExpect(AppWalletTopupInspection::transaction(['has_parent_transaction' => true])['has_parent_transaction'] === true,
    'parent transaction evidence is retained as a boolean');
echo 'WALLET TOPUP INSPECTION TESTS PASSED: ' . $checks . PHP_EOL;
