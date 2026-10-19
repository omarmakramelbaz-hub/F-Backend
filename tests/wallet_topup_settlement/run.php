<?php
// Real disposable MariaDB transactions; every gateway response is a local fake.
if (PHP_SAPI !== 'cli') exit(1);
$port = $argv[1] ?? ''; $autoload = $argv[2] ?? '';
if (!ctype_digit((string) $port) || !is_file($autoload)) exit(1);
require $autoload;
spl_autoload_register(static function ($class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__, 2) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require_once $file;
});

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\{DB, Facade};
use App\Services\Payments\{PaymobTransactionInquiry, WalletTopupSettlement};

function config($key = null, $default = null) {
    $repository = Container::getInstance()->make('config');
    if ($key === null) return $repository;
    if (is_array($key)) { foreach ($key as $name => $value) $repository->set($name, $value); return; }
    return $repository->get($key, $default);
}
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function app($key = null) { $container = Container::getInstance(); return $key === null ? $container : $container->make($key); }

function bootSettlementFixture(string $database, int $port): void {
    $container = new Container; Container::setInstance($container);
    $container->instance('config', new Repository(['wallet_topups' => ['api_key' => 'fixture-only',
        'integrations' => ['visa' => '345', 'v_cash' => '346'], 'live' => true]]));
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
        'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'strict' => true]);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn() => DB::connection()->getSchemaBuilder());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
}

function settledGatewayTransaction(array $changes = []): array {
    return array_replace(['id' => 99001, 'order' => ['id' => 88001, 'merchant_order_id' => '73'],
        'amount_cents' => 5000, 'currency' => 'EGP', 'integration_id' => 345, 'is_live' => true,
        'success' => true, 'pending' => false, 'is_auth' => false, 'is_capture' => false,
        'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
        'is_void' => false, 'is_refund' => false, 'error_occured' => false,
        'has_parent_transaction' => false], $changes);
}

class SettlementGatewayFixture extends PaymobTransactionInquiry
{
    public $calls = [];
    private $object;
    public function __construct(array $object) { $this->object = $object; }
    public function transactionForOrder(string $gatewayOrderId, ?string $transactionId = null): array {
        $this->calls[] = [$gatewayOrderId, $transactionId]; return $this->object;
    }
}

class InquiryTransportFixture extends PaymobTransactionInquiry
{
    public $requests = [];
    public $authentication = ['token' => 'fixture_token'];
    protected function request(string $method, string $path, array $payload = [], ?string $token = null): array {
        $this->requests[] = [$method, $path, $payload, $token];
        return $path === '/api/auth/tokens' ? $this->authentication : settledGatewayTransaction();
    }
}

if (($argv[3] ?? '') === '--worker') {
    $database = $argv[4] ?? '';
    if (!preg_match('/\Awallet_settlement_test_[0-9]+\z/', $database)) exit(1);
    bootSettlementFixture($database, (int) $port);
    try {
        $service = new WalletTopupSettlement(new SettlementGatewayFixture(settledGatewayTransaction()));
        $result = $service->settle(75, ($argv[5] ?? '') === 'callback' ? '99001' : null);
        echo json_encode($result, JSON_THROW_ON_ERROR); exit(0);
    } catch (Throwable $error) { fwrite(STDERR, get_class($error) . ':' . $error->getMessage()); exit(1); }
}

$pdo = new PDO('mysql:host=127.0.0.1;port=' . (int) $port, 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'wallet_settlement_test_' . getmypid();
$pdo->exec('CREATE DATABASE `' . $database . '`'); $pdo->exec('USE `' . $database . '`');
bootSettlementFixture($database, (int) $port);
$checks = 0;
function settlementExpect($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++; echo 'PASS ' . $label . PHP_EOL;
}
function snapshot(): array {
    $out = [];
    foreach (['payments', 'orders', 'wallets', 'users'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn($row) => (array) $row)->all();
    }
    return $out;
}
function resetSettlementFixture(): void {
    foreach (['payments', 'orders', 'wallets', 'users'] as $table) DB::table($table)->delete();
    DB::table('users')->insert(['id' => 24, 'balance' => 123.45, 'account_type' => 'user', 'updated_at' => '2026-10-19 10:00:00']);
    DB::table('wallets')->insert(['id' => 36, 'from_user' => 24, 'to_user' => 24, 'type' => 'charging',
        'payment' => 'visa', 'amount' => 50.0, 'status' => 'pending', 'created_at' => '2026-10-19 10:00:00', 'updated_at' => '2026-10-19 10:00:00']);
    DB::table('orders')->insert(['id' => 73, 'user_id' => 24, 'wallet_id' => 36, 'type' => 'wallet',
        'status' => null, 'created_at' => '2026-10-19 10:00:00', 'updated_at' => '2026-10-19 10:00:00']);
    DB::table('payments')->insert(['id' => 75, 'order_id' => 73, 'user_id' => 24, 'intention_order_id' => 88001,
        'transaction_id' => null, 'total_price' => '50.00', 'status' => '',
        'created_at' => '2026-10-19 10:00:00', 'updated_at' => '2026-10-19 10:00:00']);
    config(['wallet_topups.integrations.visa' => '345', 'wallet_topups.integrations.v_cash' => '346', 'wallet_topups.live' => true]);
}
function settlementRefuses(string $label, array $gatewayChanges = [], ?callable $change = null): void {
    resetSettlementFixture(); if ($change !== null) $change();
    $before = snapshot(); $refused = false;
    try { (new WalletTopupSettlement(new SettlementGatewayFixture(settledGatewayTransaction($gatewayChanges))))->settle(75); }
    catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'WALLET_TOPUP_'); }
    settlementExpect($refused, $label . ' is refused');
    settlementExpect(snapshot() === $before, $label . ' preserves all payment, order, wallet and balance fields');
}

try {
    $pdo->exec('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, balance DOUBLE NOT NULL, account_type VARCHAR(30), updated_at TIMESTAMP NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE wallets (id BIGINT UNSIGNED PRIMARY KEY, from_user BIGINT UNSIGNED, to_user BIGINT UNSIGNED,
        type VARCHAR(30), payment VARCHAR(30), amount DOUBLE NOT NULL, status VARCHAR(30), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE orders (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED, wallet_id BIGINT UNSIGNED,
        type VARCHAR(30), status VARCHAR(30) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE payments (id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED, user_id BIGINT UNSIGNED,
        intention_order_id BIGINT UNSIGNED, transaction_id VARCHAR(255) NULL, total_price DECIMAL(15,2) NULL,
        status VARCHAR(255) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB');
    resetSettlementFixture();
    $gateway = new SettlementGatewayFixture(settledGatewayTransaction());
    $service = new WalletTopupSettlement($gateway);
    $result = $service->settle(75);
    settlementExpect($result['state'] === 'credited' && $result['balance_before'] === '123.45'
        && $result['balance_after'] === '173.45' && $result['amount_cents'] === 5000,
        'verified fifty pound top-up credits 123.45 to 173.45 exactly');
    settlementExpect(abs((float) DB::table('users')->where('id', 24)->value('balance') - 173.45) < 0.000001,
        'credit is persisted in the legacy DOUBLE balance column');
    settlementExpect(DB::table('wallets')->where('id', 36)->value('status') === 'completed'
        && (string) DB::table('payments')->where('id', 75)->value('status') === '1'
        && (string) DB::table('payments')->where('id', 75)->value('transaction_id') === '99001',
        'payment, wallet and gateway transaction settle together');
    $credited = snapshot();
    $repeat = $service->settle(75, '99001');
    settlementExpect($repeat['state'] === 'unchanged' && snapshot() === $credited,
        'replayed legacy or HTTP callback cannot credit a second time');

    $inquiry = new InquiryTransportFixture;
    settlementExpect($inquiry->transactionForOrder('88001')['id'] === 99001 && count($inquiry->requests) === 2,
        'inquiry performs one authentication and one order lookup without a retry');
    settlementExpect($inquiry->requests[0] === ['POST', '/api/auth/tokens', ['api_key' => 'fixture-only'], null],
        'inquiry authentication uses configured API key only');
    settlementExpect($inquiry->requests[1][0] === 'POST'
        && $inquiry->requests[1][1] === '/api/ecommerce/orders/transaction_inquiry'
        && ($inquiry->requests[1][2]['order_id'] ?? null) === '88001'
        && ($inquiry->requests[1][2]['auth_token'] ?? null) === 'fixture_token',
        'inquiry binds the exact gateway order identifier');
    $inquiry = new InquiryTransportFixture; $inquiry->transactionForOrder('88001', '99001');
    settlementExpect($inquiry->requests[1] === ['GET', '/api/acceptance/transactions/99001', [], 'fixture_token'],
        'callback inquiry resolves exact transaction ID with authenticated lookup');
    $inquiry = new InquiryTransportFixture; $invalid = false;
    try { $inquiry->transactionForOrder('../unsafe'); }
    catch (RuntimeException $error) { $invalid = $error->getMessage() === 'WALLET_TOPUP_INVALID_IDENTIFIER'; }
    settlementExpect($invalid && $inquiry->requests === [], 'invalid gateway identifier cannot select an endpoint');
    $inquiry = new InquiryTransportFixture; $inquiry->authentication = ['token' => "invalid\ntoken"]; $invalid = false;
    try { $inquiry->transactionForOrder('88001'); }
    catch (RuntimeException $error) { $invalid = $error->getMessage() === 'WALLET_TOPUP_GATEWAY_AUTH_FAILED'; }
    settlementExpect($invalid && count($inquiry->requests) === 1, 'invalid authentication token stops before transaction lookup');

    foreach ([
        'failed payment' => ['success' => false], 'pending payment' => ['pending' => true],
        'voided payment' => ['is_voided' => true], 'refunded payment' => ['is_refunded' => true],
        'void operation' => ['is_void' => true], 'refund operation' => ['is_refund' => true],
        'gateway error' => ['error_occured' => true], 'authorization without capture' => ['is_auth' => true],
        'unsettled transaction' => ['is_standalone_payment' => false, 'is_capture' => false],
        'test-mode payment' => ['is_live' => false], 'amount mismatch' => ['amount_cents' => 4999],
        'currency mismatch' => ['currency' => 'USD'], 'integration mismatch' => ['integration_id' => 999],
        'gateway order mismatch' => ['order' => ['id' => 88002]],
    ] as $label => $changes) settlementRefuses($label, $changes);
    settlementRefuses('payment owner mismatch', [], fn() => DB::table('payments')->where('id', 75)->update(['user_id' => 999]));
    settlementRefuses('wallet owner mismatch', [], fn() => DB::table('wallets')->where('id', 36)->update(['to_user' => 999]));
    settlementRefuses('wallet sender mismatch', [], fn() => DB::table('wallets')->where('id', 36)->update(['from_user' => 999]));
    settlementRefuses('wallet amount mismatch', [], fn() => DB::table('wallets')->where('id', 36)->update(['amount' => 51]));
    settlementRefuses('duplicate gateway order', [], function () {
        $duplicate = (array) DB::table('payments')->where('id', 75)->first(); $duplicate['id'] = 76;
        DB::table('payments')->insert($duplicate);
    });
    settlementRefuses('historical paid payment with pending wallet', [], fn() => DB::table('payments')->where('id', 75)->update(['status' => '1', 'transaction_id' => '99001']));
    settlementRefuses('historical completed wallet without paid attempt', [], fn() => DB::table('wallets')->where('id', 36)->update(['status' => 'completed']));
    settlementRefuses('transaction already used by another attempt', [], function () {
        $duplicate = (array) DB::table('payments')->where('id', 75)->first();
        $duplicate['id'] = 76; $duplicate['intention_order_id'] = 88002;
        $duplicate['transaction_id'] = '99001'; $duplicate['status'] = '1';
        DB::table('payments')->insert($duplicate);
    });

    foreach (['payment_id' => 76, 'order_id' => 7389, 'wallet_id' => 3610, 'user_id' => 249,
        'gateway_order_id' => '88002', 'amount_cents' => 4999, 'integration_id' => '346'] as $key => $wrong) {
        resetSettlementFixture(); $before = snapshot(); $refused = false;
        try { (new WalletTopupSettlement(new SettlementGatewayFixture(settledGatewayTransaction())))->settle(75, null, [$key => $wrong]); }
        catch (RuntimeException $error) { $refused = str_starts_with($error->getMessage(), 'WALLET_TOPUP_'); }
        settlementExpect($refused && snapshot() === $before, 'pinned recovery ' . $key . ' mismatch refuses without writes');
    }

    foreach (['payments', 'wallets', 'users', 'orders'] as $table) {
        resetSettlementFixture(); $before = snapshot();
        $pdo->exec("CREATE TRIGGER reject_settlement_write BEFORE UPDATE ON `" . $table
            . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture write failure'");
        $failed = false;
        try { (new WalletTopupSettlement(new SettlementGatewayFixture(settledGatewayTransaction())))->settle(75); }
        catch (Throwable $error) { $failed = true; }
        $pdo->exec('DROP TRIGGER reject_settlement_write');
        settlementExpect($failed && snapshot() === $before, $table . ' failure rolls back every financial write');
    }

    resetSettlementFixture();
    // Hold the target order while independent HTTP-style and legacy-style workers
    // both reach the same transaction; release it after both processes start.
    $pdo->beginTransaction(); $pdo->query('SELECT id FROM orders WHERE id=73 FOR UPDATE')->fetchAll();
    $workers = [];
    foreach (['http', 'callback'] as $kind) {
        $pipes = [];
        $process = proc_open([getenv('WALLET_SETTLEMENT_TEST_PHP') ?: PHP_BINARY,
            __FILE__, (string) $port, $autoload, '--worker', $database, $kind],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start independent settlement worker');
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    usleep(250000); $pdo->commit();
    $states = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
        settlementExpect($code === 0, 'independent settlement worker succeeds: ' . $error);
        $states[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['state'];
    }
    sort($states);
    settlementExpect($states === ['credited', 'unchanged'], 'concurrent HTTP and legacy triggers settle exactly once');
    settlementExpect(abs((float) DB::table('users')->where('id', 24)->value('balance') - 173.45) < 0.000001,
        'concurrent triggers leave only one fifty pound wallet credit');
    require __DIR__ . '/inquiry_transport.php';
    echo 'WALLET TOPUP SETTLEMENT TESTS PASSED: ' . $checks . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
