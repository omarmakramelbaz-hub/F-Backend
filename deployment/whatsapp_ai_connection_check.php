<?php

// One synthetic extraction attempt using the saved provider. No inbox/customer reads or application writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '0');
ob_start();
$aiCalls = 0;
$finish = static function (string $status, ?string $reason = null) use (&$aiCalls): void {
    while (ob_get_level() > 0) ob_end_clean();
    $output = ['status' => $status, 'ai_calls' => $aiCalls];
    if ($reason !== null) $output['reason'] = $reason;
    echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
};
try {
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
        $finish('AI_CONNECTION_FAILED', 'PROCESS_CHECK_UNAVAILABLE'); exit(1);
    }
    $owner = posix_getpwnam('fasakha');
    if (!is_array($owner) || posix_geteuid() !== $owner['uid']) {
        $finish('AI_CONNECTION_FAILED', 'WRONG_PROCESS_OWNER'); exit(1);
    }
    if (!chdir('/home/fasakha/public_html')) {
        $finish('AI_CONNECTION_FAILED', 'APPLICATION_ROOT_UNAVAILABLE'); exit(1);
    }
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $configuration = config('whatsapp_orders', []);
    if (!is_array($configuration) || !in_array($configuration['enabled'] ?? false, [true, 1, '1'], true)) {
        $finish('AI_CONNECTION_FAILED', 'DISABLED'); exit(1);
    }
    $model = $configuration['model'] ?? null; $key = $configuration['api_key'] ?? null;
    if (!is_string($model) || $model === '' || !is_string($key) || $key === '') {
        $finish('AI_CONNECTION_FAILED', 'NOT_CONFIGURED'); exit(1);
    }
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/', $model)
        || strlen($key) < 16 || strlen($key) > 512 || preg_match('/\s|[\x00-\x1f\x7f]/', $key)) {
        $finish('AI_CONNECTION_FAILED', 'INVALID_CONFIGURATION'); exit(1);
    }
    if (!function_exists('curl_init')) {
        $finish('AI_CONNECTION_FAILED', 'TRANSPORT_UNAVAILABLE'); exit(1);
    }
    $provider = new \App\Services\Dashboard\WhatsAppOrderAiProvider(null, $configuration);
    unset($configuration, $model, $key);
    $aiCalls = 1;
    $result = $provider->extract([['id' => 'm1', 'speaker' => 'customer', 'sent_at' => gmdate('Y-m-d H:i:s'),
        'text' => 'Hello, good morning.', 'location' => null]]);
    if (($result['ok'] ?? null) === true && ($result['data']['decision'] ?? null) === 'NONE') {
        $finish('AI_CONNECTION_READY'); exit(0);
    }
    $allowed = ['DISABLED', 'NOT_CONFIGURED', 'INVALID_CONFIGURATION', 'INVALID_INPUT', 'INPUT_TOO_LARGE',
        'TRANSPORT_ERROR', 'RESPONSE_TOO_LARGE', 'HTTP_ERROR', 'INVALID_RESPONSE', 'INCOMPLETE',
        'REFUSED', 'INVALID_EXTRACTION', 'INVALID_EVIDENCE'];
    $reason = is_array($result) && in_array($result['reason'] ?? null, $allowed, true) ? $result['reason'] : 'SYNTHETIC_GREETING_NOT_NONE';
    $finish('AI_CONNECTION_FAILED', $reason); exit(1);
} catch (\Throwable $error) {
    $finish('AI_CONNECTION_FAILED', 'CHECK_FAILED'); exit(1);
}
