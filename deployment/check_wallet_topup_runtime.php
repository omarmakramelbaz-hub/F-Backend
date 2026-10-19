<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $root = $argv[1] ?? '';
    $phase = $argv[2] ?? 'after';
    if (realpath($root) !== $root || !in_array($phase, ['before', 'after'], true)) {
        throw new RuntimeException('WALLET_TOPUP_INVALID_RUNTIME_CHECK');
    }
    ob_start();
    try {
        require $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $url = (string) $app['config']->get('app.url');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('WALLET_TOPUP_HTTPS_APP_URL_REQUIRED');
        }
        if ($app->configurationIsCached()) throw new RuntimeException('WALLET_TOPUP_UNCACHED_CONFIG_REQUIRED');
        if (!function_exists('curl_init')) throw new RuntimeException('WALLET_TOPUP_CURL_REQUIRED');
        if (!trim((string) env('PAYMOB_API_KEY', ''))
            || !preg_match('/\A[1-9][0-9]*\z/', (string) env('PAYMOB_CARD_INTEGRATION_ID', ''))) {
            throw new RuntimeException('WALLET_TOPUP_GATEWAY_CONFIGURATION_REQUIRED');
        }
        $connection = $app['db']->connection();
        $prefix = $connection->getTablePrefix();
        $tables = array_map(static fn($name) => $prefix . $name, ['payments', 'orders', 'wallets', 'users']);
        $engines = $connection->select('SELECT ENGINE AS engine FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?,?)', $tables);
        if (count($engines) !== 4) throw new RuntimeException('WALLET_TOPUP_TRANSACTIONAL_TABLES_REQUIRED');
        foreach ($engines as $table) {
            if (strtolower((string) $table->engine) !== 'innodb') {
                throw new RuntimeException('WALLET_TOPUP_TRANSACTIONAL_TABLES_REQUIRED');
            }
        }
        if ($phase === 'after') {
            foreach (['notify' => 'POST', 'return' => 'GET'] as $name => $method) {
                $route = $app['router']->getRoutes()->getByName('wallet-topups.' . $name);
                if (!$route || !in_array($method, $route->methods(), true)
                    || $route->uri() !== 'api/wallet-topup/paymob/' . $name) {
                    throw new RuntimeException('WALLET_TOPUP_CALLBACK_ROUTE_REQUIRED');
                }
            }
            if (!class_exists(\App\Services\Payments\WalletTopupSettlement::class)
                || !class_exists(\App\Http\Controllers\Payment\WalletTopupController::class)) {
                throw new RuntimeException('WALLET_TOPUP_AUTOLOAD_REQUIRED');
            }
        }
    } finally { ob_end_clean(); }
    echo 'WALLET_TOPUP_RUNTIME_' . strtoupper($phase) . '_OK' . PHP_EOL;
} catch (Throwable $error) {
    $code = preg_match('/\AWALLET_TOPUP_[A-Z_]+\z/', $error->getMessage())
        ? $error->getMessage() : 'WALLET_TOPUP_RUNTIME_CHECK_FAILED';
    fwrite(STDERR, $code . PHP_EOL); exit(1);
}
