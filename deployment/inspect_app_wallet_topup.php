<?php

/** Bounded wallet-top-up diagnosis. No callbacks, settlement or business writes. */
final class AppWalletTopupInspection
{
    private const FILES = [
        'app/Http/Controllers/CheckoutController.php',
        'app/Http/Controllers/Payment/PaymobController.php',
        'app/Http/Controllers/Api/V1/User/WalletController.php',
        'routes/api.php', 'routes/web.php', 'routes/go_services.php',
        'config/database.php', 'config/dashboard_payments.php', 'config/go_services.php',
        'config/services.php', 'config/app.php', 'bootstrap/cache/config.php',
    ];

    private static function id($value): ?string
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[0-9]{1,20}\z/', (string) $value)
            ? (string) $value : null;
    }

    private static function amount($value): ?string
    {
        // Legacy wallet/balance columns may be DOUBLE and PDO returns a float.
        // This formats observed data for diagnosis only; it performs no accounting.
        if (is_float($value)) {
            if (!is_finite($value) || abs($value) >= 1.0e15) return null;
            $value = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        }
        return (is_int($value) || is_string($value)) && preg_match('/\A-?[0-9]{1,15}(?:\.[0-9]{1,4})?\z/', (string) $value)
            ? (string) $value : null;
    }

    private static function state($value): ?string
    {
        if ($value === null) return null;
        return in_array((string) $value, ['', '0', '1', 'pending', 'completed', 'paid', 'failed',
            'canceled', 'cancelled', 'success', 'processing', 'initiated', 'refund_pending',
            'online', 'v_cash', 'visa', 'cash', 'charging', 'wallet', 'payment'], true)
            ? (string) $value : 'OTHER';
    }

    private static function timestamp($value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)
            ? $value : null;
    }

    private static function present($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    /** The response allowlist never forwards billing_data, source_data or gateway tokens. */
    public static function transaction(array $object): array
    {
        $result = [];
        foreach (['success', 'pending', 'is_auth', 'is_capture', 'is_standalone_payment',
            'is_voided', 'is_refunded', 'is_void', 'is_refund', 'has_parent_transaction',
            'error_occured', 'is_live'] as $key) {
            $value = $object[$key] ?? null;
            $result[$key] = is_bool($value) ? $value
                : (in_array($value, [0, '0', 'false'], true) ? false
                : (in_array($value, [1, '1', 'true'], true) ? true : null));
        }
        $result['transaction_id'] = self::id($object['id'] ?? null);
        $result['order_id'] = self::id(is_array($object['order'] ?? null)
            ? ($object['order']['id'] ?? null) : ($object['order'] ?? null));
        $result['amount_cents'] = self::id($object['amount_cents'] ?? null);
        $result['currency'] = is_string($object['currency'] ?? null)
            && preg_match('/\A[A-Z]{3}\z/', $object['currency']) ? $object['currency'] : null;
        $result['integration_id'] = self::id($object['integration_id'] ?? null);
        return $result;
    }

    private static function request(string $path, array $body): array
    {
        if (!function_exists('curl_init')) return ['status' => 0, 'body' => null];
        $curl = curl_init('https://accept.paymob.com' . $path);
        $response = ''; $tooLarge = false;
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$response, &$tooLarge) {
                if (strlen($response) + strlen($chunk) > 1048576) { $tooLarge = true; return 0; }
                $response .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = $ok === false || $tooLarge ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = $status >= 200 && $status < 300 ? json_decode($response, true) : null;
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
    }

    public static function gateway(array $attempts, string $apiKey, ?callable $request = null): array
    {
        $result = ['enabled' => true, 'api_key_available' => self::present($apiKey),
            'selected_attempts' => 0, 'http_status_counts' => [], 'transactions' => []];
        $selected = []; $seen = [];
        foreach ($attempts as $attempt) {
            $id = self::id($attempt['intention_order_id'] ?? null);
            if ($id === null || isset($seen[$id])) continue;
            $seen[$id] = true; $selected[] = $attempt;
            if (count($selected) === 4) break;
        }
        $result['selected_attempts'] = count($selected);
        if (!$selected || !$result['api_key_available']) return $result;
        $request = $request ?? [self::class, 'request'];
        $call = static function ($path, $body) use ($request, &$result) {
            try { $response = $request($path, $body); } catch (\Throwable $error) { $response = []; }
            $status = (int) ($response['status'] ?? 0);
            if ($status < 100 || $status > 599) $status = 0;
            $key = $status === 0 ? 'transport_error' : (string) $status;
            $result['http_status_counts'][$key] = ($result['http_status_counts'][$key] ?? 0) + 1;
            return $status >= 200 && $status < 300 && is_array($response['body'] ?? null)
                ? $response['body'] : null;
        };
        $auth = $call('/api/auth/tokens', ['api_key' => $apiKey]);
        $token = $auth['token'] ?? null;
        $result['authenticated'] = is_string($token) && $token !== '' && strlen($token) < 16384;
        if (!$result['authenticated']) return $result;
        foreach ($selected as $attempt) {
            $object = $call('/api/ecommerce/orders/transaction_inquiry',
                ['auth_token' => $token, 'order_id' => (string) $attempt['intention_order_id']]);
            $result['transactions'][] = ['local_payment_id' => self::id($attempt['id'] ?? null),
                'local_order_id' => self::id($attempt['order_id'] ?? null),
                'queried_order_id' => self::id($attempt['intention_order_id']),
                'available' => $object !== null, 'transaction' => $object === null ? null : self::transaction($object)];
        }
        return $result;
    }

    public static function report($app, string $root, bool $gatewayCheck = false, ?callable $request = null): array
    {
        $connection = $app['db']->connection();
        if ($connection->getDriverName() !== 'mysql') throw new RuntimeException('WALLET_TOPUP_MYSQL_REQUIRED');
        $pdo = $connection->getPdo(); $prefix = $connection->getTablePrefix();
        if (!preg_match('/\A[A-Za-z0-9_]*\z/', $prefix)) throw new RuntimeException('WALLET_TOPUP_INVALID_PREFIX');
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $query = static function (string $sql, array $bindings = []) use ($pdo): array {
            $statement = $pdo->prepare($sql); $statement->execute($bindings);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        };
        $clock = $query('SELECT NOW() AS now, DATE_SUB(NOW(), INTERVAL 2 HOUR) AS since, '
            . '@@SESSION.sql_mode AS sql_mode, @@SESSION.time_zone AS time_zone')[0];
        $schema = []; $paymentColumns = [];
        foreach (['orders', 'wallets', 'payments', 'users'] as $table) {
            $columns = $query('SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $prefix . $table]);
            $schema[$table] = array_column($columns, 'name');
            if ($table === 'payments') foreach ($columns as $column) {
                $paymentColumns[] = ['name' => $column['name'], 'type' => $column['type'], 'nullable' => $column['nullable'] === 'YES'];
            }
        }
        $required = ['orders' => ['id', 'user_id', 'wallet_id', 'type', 'status', 'created_at', 'updated_at'],
            'wallets' => ['id', 'from_user', 'to_user', 'type', 'amount', 'status', 'created_at', 'updated_at'],
            'payments' => ['id', 'order_id', 'user_id', 'intention_order_id', 'transaction_id', 'total_price', 'status', 'created_at', 'updated_at'],
            'users' => ['id', 'balance']];
        $ready = []; foreach ($required as $table => $columns) $ready[$table] = !array_diff($columns, $schema[$table]);
        $orders = $ready['orders'] ? $query('SELECT id,user_id,wallet_id,status,created_at,updated_at '
            . 'FROM `' . $prefix . 'orders` WHERE type = ? AND (created_at >= ? OR updated_at >= ?) ORDER BY id DESC LIMIT 10',
            ['wallet', $clock['since'], $clock['since']]) : [];
        $walletFields = 'id,from_user,to_user,amount,status'
            . (in_array('payment', $schema['wallets'], true) ? ',payment' : '') . ',created_at,updated_at';
        $wallets = $ready['wallets'] ? $query('SELECT ' . $walletFields . ' '
            . 'FROM `' . $prefix . 'wallets` WHERE type = ? AND (created_at >= ? OR updated_at >= ?) ORDER BY id DESC LIMIT 10',
            ['charging', $clock['since'], $clock['since']]) : [];
        $walletIds = array_values(array_unique(array_filter(array_column($orders, 'wallet_id'))));
        if ($walletIds && $ready['wallets']) {
            $linked = $query('SELECT ' . $walletFields . ' FROM `' . $prefix . 'wallets` '
                . 'WHERE id IN (' . implode(',', array_fill(0, count($walletIds), '?')) . ')', $walletIds);
            $byId = []; foreach (array_merge($wallets, $linked) as $wallet) $byId[$wallet['id']] = $wallet;
            $wallets = array_values($byId);
        }
        $orderIds = array_column($orders, 'id');
        $payments = $orderIds && $ready['payments'] ? $query('SELECT id,order_id,user_id,intention_order_id,transaction_id,'
            . 'total_price,status,created_at,updated_at FROM `' . $prefix . 'payments` WHERE order_id IN ('
            . implode(',', array_fill(0, count($orderIds), '?')) . ') ORDER BY id DESC LIMIT 50', $orderIds) : [];
        $userIds = array_values(array_unique(array_filter(array_merge(array_column($orders, 'user_id'),
            array_column($wallets, 'from_user'), array_column($wallets, 'to_user')))));
        $users = $userIds && $ready['users'] ? $query('SELECT id,balance FROM `' . $prefix . 'users` WHERE id IN ('
            . implode(',', array_fill(0, count($userIds), '?')) . ') LIMIT 50', $userIds) : [];
        $safe = static function (array $rows): array {
            return array_map(static function ($row) {
                $out = []; foreach ($row as $key => $value) {
                    if ($key === 'id' || substr($key, -3) === '_id' || in_array($key, ['from_user', 'to_user'], true)) $out[$key] = self::id($value);
                    elseif (in_array($key, ['amount', 'balance', 'total_price'], true)) $out[$key] = self::amount($value);
                    elseif (in_array($key, ['created_at', 'updated_at'], true)) $out[$key] = self::timestamp($value);
                    elseif ($key === 'status' || $key === 'payment') $out[$key] = self::state($value);
                } return $out;
            }, $rows);
        };
        $hashes = []; foreach (self::FILES as $path) {
            $file = $root . '/' . $path;
            $hashes[$path] = is_file($file) && !is_link($file) ? hash_file('sha256', $file) : null;
        }
        $routes = []; foreach ($app['router']->getRoutes() as $route) {
            $uri = $route->uri(); $action = $route->getActionName();
            if ((stripos($uri, 'callback') !== false && preg_match('/paymob|pament|payment/i', $uri . ' ' . $action))
                || (stripos($uri, 'paymob') !== false && stripos($uri, 'webhook') !== false)) {
                $routes[] = ['name' => $route->getName(), 'methods' => $route->methods(), 'uri' => $uri, 'action' => $action];
                if (count($routes) === 20) break;
            }
        }
        $config = $app['config'];
        $report = ['kind' => 'app-wallet-topup-inspection', 'generated_at' => gmdate('c'),
            'window' => ['database_now' => self::timestamp($clock['now']), 'since' => self::timestamp($clock['since']), 'hours' => 2],
            'schema_ready' => $ready, 'payment_columns' => $paymentColumns,
            'database' => ['sql_modes' => array_values(array_filter(explode(',', $clock['sql_mode']),
                static fn($value) => preg_match('/\A[A-Z0-9_]+\z/', $value))),
                'strict_configured' => (bool) $connection->getConfig('strict')],
            'file_sha256' => $hashes, 'callback_routes' => $routes,
            'configuration_ready' => ['configuration_cached' => $app->configurationIsCached(),
                'dashboard_hmac' => self::present($config->get('dashboard_payments.hmac_secret')),
                'card_integration' => self::present($config->get('dashboard_payments.integrations.online')),
                'mobile_wallet_integration' => self::present($config->get('dashboard_payments.integrations.v_cash')),
                'go_services_hmac' => self::present($config->get('go_services.paymob.hmac_secret'))],
            'wallet_orders' => $safe($orders), 'charging_wallets' => $safe($wallets),
            'linked_payment_attempts' => $safe($payments), 'current_balances' => $safe($users),
            'callback_receipts_available' => false];
        if ($gatewayCheck) {
            $walletById = []; foreach ($wallets as $wallet) $walletById[$wallet['id']] = $wallet;
            $orderById = []; foreach ($orders as $order) $orderById[$order['id']] = $order;
            $pending = array_values(array_filter($payments, static function ($payment) use ($walletById, $orderById) {
                $order = $orderById[$payment['order_id']] ?? [];
                $wallet = $walletById[$order['wallet_id'] ?? ''] ?? [];
                return (string) ($payment['status'] ?? '') !== '1' || ($wallet['status'] ?? null) !== 'completed';
            }));
            $apiKey = function_exists('env') ? (string) env('PAYMOB_API_KEY', '') : '';
            $report['gateway_check'] = self::gateway($pending, $apiKey, $request);
        } else $report['gateway_check'] = ['enabled' => false];
        return $report;
    }
}

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $root = null; $gatewayCheck = false;
        for ($index = 1; $index < $argc; $index++) {
            if ($argv[$index] === '--root' && $root === null && isset($argv[$index + 1])) $root = $argv[++$index];
            elseif ($argv[$index] === '--gateway-check' && !$gatewayCheck) $gatewayCheck = true;
            else throw new RuntimeException('WALLET_TOPUP_INVALID_ARGUMENTS');
        }
        if (!is_string($root) || realpath($root) !== $root || !is_file($root . '/vendor/autoload.php')
            || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('WALLET_TOPUP_INVALID_ROOT');
        ob_start();
        try {
            require $root . '/vendor/autoload.php'; $app = require $root . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $report = AppWalletTopupInspection::report($app, $root, $gatewayCheck);
        } finally { ob_end_clean(); }
        echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        $code = preg_match('/\AWALLET_TOPUP_[A-Z_]+\z/', $error->getMessage())
            ? $error->getMessage() : 'WALLET_TOPUP_INSPECTION_FAILED';
        fwrite(STDERR, $code . PHP_EOL); exit(1);
    }
}
