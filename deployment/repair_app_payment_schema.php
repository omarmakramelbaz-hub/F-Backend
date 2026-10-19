<?php

// CLI only; reports schema metadata and never contacts a payment gateway.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    $mode = null; $root = null;
    for ($index = 1; $index < $argc; $index++) {
        if (in_array($argv[$index], ['--check', '--apply'], true) && $mode === null) {
            $mode = $argv[$index];
        } elseif ($argv[$index] === '--root' && $root === null && isset($argv[$index + 1])) {
            $root = $argv[++$index];
        } else {
            throw new RuntimeException('PAYMENT_SCHEMA_INVALID_ARGUMENTS');
        }
    }
    if ($mode === null || !is_string($root) || !is_dir($root) || realpath($root) !== $root
        || !is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) {
        throw new RuntimeException('PAYMENT_SCHEMA_INVALID_APPLICATION_ROOT');
    }
    require_once __DIR__ . '/payment_schema/PaymentSchemaRepair.php';
    // An application provider must not turn the metadata report into unbounded output.
    ob_start();
    try {
        require $root . '/vendor/autoload.php';
        $app = require $root . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $connection = $app->make('db')->connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('PAYMENT_SCHEMA_MYSQL_REQUIRED');
        }
        $repair = new PaymentSchemaRepair($connection->getPdo(), $connection->getTablePrefix());
        $result = $mode === '--apply' ? $repair->apply() : $repair->inspect();
    } finally {
        ob_end_clean();
    }
    echo json_encode(['kind' => 'app-payment-schema', 'mode' => substr($mode, 2)] + $result,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['ready'] ? 0 : 2);
} catch (Throwable $error) {
    // Database exceptions may contain credentials, DSNs or customer values.
    $code = preg_match('/\APAYMENT_SCHEMA_[A-Z_]+\z/', $error->getMessage())
        ? $error->getMessage() : 'PAYMENT_SCHEMA_REPAIR_FAILED';
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}
