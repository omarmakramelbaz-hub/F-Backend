<?php
// Disposable MySQL/MariaDB only. No application bootstrap, environment or gateway.
if (PHP_SAPI !== 'cli') exit(1);
[$script, $port, $autoload] = $argv + [null, null, null];
if (!ctype_digit((string) $port) || !is_file((string) $autoload)) exit(1);
require $autoload;
require __DIR__ . '/../../deployment/payment_schema/PaymentSchemaRepair.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\{DB, Facade, Schema};

$pdo = new PDO('mysql:host=127.0.0.1;port=' . (int) $port, 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'payment_schema_test_' . getmypid();
$pdo->exec('CREATE DATABASE `' . $database . '`');
$pdo->exec('USE `' . $database . '`');
$checks = 0;
function expect($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $checks++;
    echo 'PASS ' . $label . PHP_EOL;
}
function failure(callable $operation, string $code): void {
    try { $operation(); } catch (RuntimeException $error) {
        expect($error->getMessage() === $code, $code); return;
    }
    throw new RuntimeException('Expected refusal: ' . $code);
}
function legacy(PDO $pdo, string $amount = '', string $table = 'payments'): void {
    $pdo->exec('CREATE TABLE `' . $table . '` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(255) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
        transaction_id VARCHAR(255) NULL, intention_order_id INT NULL' . $amount . ') ENGINE=InnoDB');
}
function rows(PDO $pdo, string $table): array {
    return $pdo->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

try {
    failure(fn() => new PaymentSchemaRepair(new PDO('sqlite::memory:')), 'PAYMENT_SCHEMA_MYSQL_REQUIRED');
    failure(fn() => new PaymentSchemaRepair($pdo, 'bad-prefix'), 'PAYMENT_SCHEMA_INVALID_TABLE_PREFIX');
    $repair = new PaymentSchemaRepair($pdo);
    failure(fn() => $repair->apply(), 'PAYMENT_SCHEMA_PAYMENTS_TABLE_REQUIRED');
    $pdo->exec('CREATE TABLE payments (id INT PRIMARY KEY)');
    failure(fn() => $repair->apply(), 'PAYMENT_SCHEMA_REQUIRED_COLUMNS_MISSING');
    expect(count($pdo->query('SHOW COLUMNS FROM payments')->fetchAll()) === 1,
        'failed preflight leaves partial table unchanged');
    $pdo->exec('DROP TABLE payments');
    legacy($pdo);
    foreach (['orders', 'wallets', 'users'] as $table) {
        $pdo->exec('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, value VARCHAR(255))');
        $pdo->exec("INSERT INTO `" . $table . "` VALUES (1, 'unchanged financial history')");
    }
    $pdo->exec("INSERT INTO payments (user_id,order_id,status,transaction_id,intention_order_id,created_at,updated_at)
        VALUES (7,11,'1','9001',801,'2026-10-01 10:00:00','2026-10-01 10:05:00'),
               (8,12,NULL,NULL,802,'2026-10-01 11:00:00','2026-10-01 11:00:00')");
    $old = rows($pdo, 'payments');
    expect(!$repair->inspect()['ready'], 'check detects missing amount without changing the table');
    expect(rows($pdo, 'payments') === $old, 'check preserves historical rows');
    $beforeWaits = $pdo->query('SELECT @@SESSION.lock_wait_timeout, @@SESSION.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM);
    $result = $repair->apply();
    expect($result['ready'] && $result['action'] === 'added_total_price', 'repair adds the missing amount');
    expect($result['total_price'] === ['type' => 'decimal(15,2) unsigned', 'nullable' => true],
        'added amount uses nullable exact unsigned decimals');
    $after = rows($pdo, 'payments');
    foreach ($after as &$row) { expect($row['total_price'] === null, 'historical unknown amount remains null'); unset($row['total_price']); }
    unset($row);
    expect($after === $old, 'historical IDs, statuses, references and timestamps remain identical');
    expect($repair->apply()['action'] === 'unchanged', 'second repair is idempotent');
    expect($beforeWaits === $pdo->query('SELECT @@SESSION.lock_wait_timeout, @@SESSION.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM),
        'session lock waits are restored');
    $insert = $pdo->prepare('INSERT INTO payments (user_id,order_id,status,total_price) VALUES (9,13,NULL,?)');
    $insert->execute(['9999999999999.99']);
    expect($pdo->query('SELECT total_price FROM payments WHERE id=3')->fetchColumn() === '9999999999999.99',
        'new checkout amount roundtrips exactly at full precision');
    foreach (['orders', 'wallets', 'users'] as $table) {
        expect(rows($pdo, $table) === [['id' => 1, 'value' => 'unchanged financial history']],
            $table . ' financial data remains unchanged');
    }
    $pdo->beginTransaction();
    failure(fn() => $repair->apply(), 'PAYMENT_SCHEMA_TRANSACTION_NOT_ALLOWED');
    expect($pdo->inTransaction(), 'repair does not commit an enclosing transaction');
    $pdo->rollBack();
    $pdo->exec('DROP TABLE payments');
    legacy($pdo, ', total_price DECIMAL(14,2) NOT NULL DEFAULT 0');
    $pdo->exec("INSERT INTO payments(user_id,order_id,status,total_price) VALUES (7,11,'1',123.45)");
    $compatible = rows($pdo, 'payments');
    expect($repair->apply()['action'] === 'unchanged', 'compatible existing decimal amount is accepted');
    expect(rows($pdo, 'payments') === $compatible, 'compatible amount precision is preserved without rewriting');
    $pdo->exec('DROP TABLE payments');
    foreach (['DECIMAL(11,2)', 'DECIMAL(17,4)', 'DOUBLE', 'VARCHAR(255)'] as $type) {
        legacy($pdo, ', total_price ' . $type . ' NULL');
        failure(fn() => $repair->apply(), 'PAYMENT_SCHEMA_EXISTING_AMOUNT_INCOMPATIBLE');
        expect(count($pdo->query('SHOW COLUMNS FROM payments')->fetchAll()) === 9,
            'incompatible existing amount is retained for review');
        $pdo->exec('DROP TABLE payments');
    }
    legacy($pdo, ', total_price DECIMAL(15,2) AS (user_id + order_id) STORED');
    failure(fn() => $repair->apply(), 'PAYMENT_SCHEMA_EXISTING_AMOUNT_INCOMPATIBLE');
    $pdo->exec('DROP TABLE payments');
    legacy($pdo, '', 'branch_payments');
    expect((new PaymentSchemaRepair($pdo, 'branch_'))->apply()['ready'], 'connection table prefix is honored');
    expect(!$pdo->query("SHOW TABLES LIKE 'payments'")->fetchColumn(), 'prefix repair does not create unprefixed table');

    // DDL must refuse a held metadata lock in a bounded time, then release its advisory lock.
    legacy($pdo);
    $other = new PDO('mysql:host=127.0.0.1;port=' . (int) $port . ';dbname=' . $database,
        'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lock = 'payment-schema:' . substr(hash('sha256', $database . ':payments'), 0, 48);
    $lockStatement = $other->prepare('SELECT GET_LOCK(?, 0)'); $lockStatement->execute([$lock]);
    expect((int) $lockStatement->fetchColumn() === 1, 'independent connection holds repair advisory lock');
    $started = microtime(true);
    failure(fn() => $repair->apply(), 'PAYMENT_SCHEMA_LOCK_UNAVAILABLE');
    expect(microtime(true) - $started < 12 && !$repair->inspect()['ready'],
        'advisory lock contention is bounded and leaves schema unchanged');
    $lockStatement = $other->prepare('SELECT RELEASE_LOCK(?)'); $lockStatement->execute([$lock]);
    $other->beginTransaction(); $other->query('SELECT * FROM payments')->fetchAll();
    $started = microtime(true); $timedOut = false;
    try { $repair->apply(); } catch (PDOException $error) { $timedOut = (int) ($error->errorInfo[1] ?? 0) === 1205; }
    expect($timedOut && microtime(true) - $started < 12, 'held metadata lock stops repair within bounded wait');
    $other->rollBack();
    expect(!$repair->inspect()['ready'], 'failed DDL leaves amount missing without any row change');
    expect($repair->apply()['ready'], 'repair can retry after a failed DDL releases its advisory lock');
    expect($beforeWaits === $pdo->query('SELECT @@SESSION.lock_wait_timeout, @@SESSION.innodb_lock_wait_timeout')->fetch(PDO::FETCH_NUM),
        'session waits restore after DDL failure');

    // Production Laravel config uses strict=false. Preserve its legacy status
    // default behavior while inserting the exact fields used by checkingOut.
    $pdo->exec('DROP TABLE payments'); legacy($pdo);
    $pdo->exec('ALTER TABLE payments MODIFY COLUMN status VARCHAR(255) NOT NULL');
    $sqlMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    try {
        $pdo->exec("SET SESSION sql_mode = ''");
        $repair->apply();
        $checkout = $pdo->prepare('INSERT INTO payments (order_id,user_id,intention_order_id,total_price) VALUES (?,?,?,?)');
        $checkout->execute([701, 702, 703, '125.75']);
        $payment = rows($pdo, 'payments')[0];
        expect($payment['total_price'] === '125.75' && (int) $payment['intention_order_id'] === 703,
            'production-shaped checkout insert succeeds after amount repair');
        expect($payment['status'] === '' && $payment['created_at'] === null && $payment['updated_at'] === null,
            'non-strict legacy status behavior stays intact without status or timestamp edits');
    } finally {
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($sqlMode));
    }

    // Exercise the corrective migration against the same genuine database.
    $pdo->exec('DROP TABLE payments'); legacy($pdo);
    $container = new Container; Container::setInstance($container);
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port,
        'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4']);
    $capsule->setAsGlobal();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn() => DB::connection()->getSchemaBuilder());
    Facade::setFacadeApplication($container);
    require __DIR__ . '/../../database/migrations/2026_10_19_190000_repair_payments_total_price.php';
    $migration = new RepairPaymentsTotalPrice; $migration->up(); $migration->up(); $migration->down();
    expect($repair->inspect()['ready'], 'corrective migration is idempotent and rollback retains financial column');
    $pdo->exec('DROP TABLE payments');
    // Avoid unrelated legacy foreign-key parents while testing the original create migration.
    foreach (['users', 'orders'] as $table) { $pdo->exec('DROP TABLE `' . $table . '`');
        $pdo->exec('CREATE TABLE `' . $table . '` (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB'); }
    require __DIR__ . '/../../database/migrations/2024_06_26_114211_create_payments_table.php';
    (new CreatePaymentsTable)->up();
    expect(Schema::hasColumn('payments', 'total_price'), 'original create migration includes amount on fresh databases');
    $migration->up();
    expect(Schema::hasColumn('payments', 'total_price') && !Schema::hasColumn('payments', 'intention_order_id'),
        'corrective migration leaves fresh original schema intact without gateway columns');
    echo 'PAYMENT SCHEMA TESTS PASSED: ' . $checks . PHP_EOL;
} finally {
    if (isset($other) && $other->inTransaction()) $other->rollBack();
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
