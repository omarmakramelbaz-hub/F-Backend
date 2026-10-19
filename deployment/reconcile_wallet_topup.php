<?php

// Reconcile exactly one saved top-up using fresh authenticated gateway evidence.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    $options = [];
    $allowed = ['root', 'payment-id', 'transaction-id', 'order-id', 'wallet-id', 'user-id',
        'gateway-order-id', 'amount-cents', 'integration-id'];
    for ($index = 1; $index < $argc; $index++) {
        $key = substr($argv[$index], 2);
        if (substr($argv[$index], 0, 2) !== '--' || !in_array($key, $allowed, true)
            || isset($options[$key]) || !isset($argv[$index + 1])) {
            throw new RuntimeException('WALLET_TOPUP_INVALID_RECOVERY_ARGUMENTS');
        }
        $options[$key] = $argv[++$index];
    }
    if (count($options) !== count($allowed)) throw new RuntimeException('WALLET_TOPUP_EXPECTATIONS_REQUIRED');
    $root = $options['root'];
    if (realpath($root) !== $root || !is_file($root . '/vendor/autoload.php')
        || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('WALLET_TOPUP_INVALID_ROOT');
    foreach ($options as $key => $value) {
        if ($key !== 'root' && !preg_match('/\A[1-9][0-9]{0,17}\z/', $value)) {
            throw new RuntimeException('WALLET_TOPUP_INVALID_RECOVERY_ARGUMENTS');
        }
    }
    ob_start();
    try {
        require $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $expected = [];
        foreach (['payment-id', 'order-id', 'wallet-id', 'user-id', 'gateway-order-id', 'amount-cents', 'integration-id'] as $key) {
            $expected[str_replace('-', '_', $key)] = $options[$key];
        }
        $result = $app->make(\App\Services\Payments\WalletTopupSettlement::class)
            ->settle((int) $options['payment-id'], $options['transaction-id'], $expected);
    } finally { ob_end_clean(); }
    echo json_encode(['kind' => 'wallet-topup-recovery'] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $code = preg_match('/\AWALLET_TOPUP_[A-Z_]+\z/', $error->getMessage())
        ? $error->getMessage() : 'WALLET_TOPUP_RECOVERY_FAILED';
    fwrite(STDERR, $code . PHP_EOL); exit(1);
}
