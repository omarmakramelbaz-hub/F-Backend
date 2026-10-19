<?php
declare(strict_types=1);
require __DIR__.'/../deployment/launch_reset/reset.php';

$socket = $argv[1] ?? '';
if (!str_starts_with($socket, '/tmp/launch-reset-mariadb/') && $socket !== 'tcp:33089') throw new RuntimeException('ISOLATED_TEST_SOCKET_REQUIRED');
$baseDsn = $socket === 'tcp:33089' ? 'mysql:host=127.0.0.1;port=33089' : 'mysql:unix_socket='.$socket;
$name = 'launch_reset_fixture_'.getmypid();
$admin = new PDO($baseDsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
if (!str_starts_with((string) $admin->query('SELECT @@datadir')->fetchColumn(), '/tmp/launch-reset-mariadb/')) throw new RuntimeException('ISOLATED_TEST_DATADIR_REQUIRED');
$admin->exec('CREATE DATABASE '.LaunchReset::name($name).' CHARACTER SET utf8mb4');
$admin->exec('CREATE DATABASE '.LaunchReset::name($name.'_recovery').' CHARACTER SET utf8mb4');
$pdo = new PDO($baseDsn.';dbname='.$name, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
$pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
$source = json_decode(file_get_contents(__DIR__.'/../deployment/launch_reset/inspected_schema.json'), true, 512, JSON_THROW_ON_ERROR);
$source['payments']['columns']['total_price'] = 'decimal(15,2) unsigned nullable';
$source['users'] = ['engine' => 'InnoDB', 'columns' => ['id' => 'bigint unsigned auto_increment', 'account_type' => 'varchar(32)', 'owner_resturant_id' => 'bigint unsigned nullable', 'balance' => 'decimal(12,2)'], 'references' => [], 'unique_indexes' => ['PRIMARY' => ['id']]];
$source['jobs'] = ['engine' => 'InnoDB', 'columns' => ['id' => 'bigint unsigned auto_increment', 'payload' => 'longtext'], 'references' => [], 'unique_indexes' => ['PRIMARY' => ['id']]];
foreach ($source as $meta) foreach ($meta['references'] as $ref) if (!isset($source[$ref['table']])) $source[$ref['table']] = ['engine' => 'InnoDB', 'columns' => ['id' => 'bigint unsigned auto_increment'], 'references' => [], 'unique_indexes' => ['PRIMARY' => ['id']]];
foreach ($source as $table => $meta) {
    $fields = [];
    foreach ($meta['columns'] as $column => $type) {
        $nullable = str_contains($type, ' nullable'); $type = str_replace(' nullable', '', $type);
        $fields[] = LaunchReset::name($column).' '.$type.($nullable ? ' NULL' : ' NOT NULL');
    }
    foreach ($meta['unique_indexes'] ?? [] as $index => $columns) $fields[] = ($index === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE KEY '.LaunchReset::name($index)).' ('.implode(',', array_map([LaunchReset::class, 'name'], $columns)).')';
    $pdo->exec('CREATE TABLE '.LaunchReset::name($table).' ('.implode(',', $fields).') ENGINE=InnoDB');
}

function putLaunchRow(PDO $pdo, array $source, string $table, array $values): void
{
    $row = [];
    foreach ($source[$table]['columns'] as $column => $type) {
        if (array_key_exists($column, $values)) { $row[$column] = $values[$column]; continue; }
        if (str_contains($type, 'nullable')) $row[$column] = null;
        elseif (str_starts_with($type, 'enum(')) { preg_match("/^enum\('([^']+)'/", $type, $m); $row[$column] = $m[1]; }
        elseif (preg_match('/(?:int|decimal|double|float)/', $type)) $row[$column] = 0;
        elseif (str_starts_with($type, 'timestamp') || str_starts_with($type, 'datetime')) $row[$column] = '2026-10-19 12:00:00';
        elseif (str_starts_with($type, 'date')) $row[$column] = '2026-10-01';
        elseif (str_starts_with($type, 'time')) $row[$column] = '09:00:00';
        else $row[$column] = '';
    }
    $columns = array_keys($row); $statement = $pdo->prepare('INSERT INTO '.LaunchReset::name($table).' ('.implode(',', array_map([LaunchReset::class, 'name'], $columns)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
    $statement->execute(array_values($row));
}
foreach ([0, 1, 2, 3] as $id) putLaunchRow($pdo, $source, 'users', ['id' => $id, 'account_type' => $id === 1 ? 'admin' : 'user', 'owner_resturant_id' => null, 'balance' => $id === 2 ? '9876.54' : '0.00']);
foreach ($source as $table => $meta) if (!isset(json_decode(file_get_contents(__DIR__.'/../deployment/launch_reset/inspected_schema.json'), true)[$table]) && !in_array($table, ['users', 'jobs'])) {
    foreach ([0, 1] as $id) putLaunchRow($pdo, $source, $table, ['id' => $id]);
}
foreach ([94, 301] as $id) putLaunchRow($pdo, $source, 'resturants', ['id' => $id, 'user_id' => 1]);
putLaunchRow($pdo, $source, 'orders', ['id' => 10, 'user_id' => 2, 'resturant_id' => 94, 'type' => 'current', 'status' => 'completed', 'payment_type' => 'cash']);
putLaunchRow($pdo, $source, 'orders', ['id' => 11, 'user_id' => 2, 'resturant_id' => 94, 'type' => 'wallet', 'status' => 'completed']);
putLaunchRow($pdo, $source, 'orders', ['id' => 12, 'user_id' => 2, 'resturant_id' => null, 'type' => 'current', 'status' => 'pending']);
foreach (['carts', 'payments', 'commissions', 'delegate_notifications', 'reviews', 'shippings', 'order_live_activities'] as $table) putLaunchRow($pdo, $source, $table, ['id' => 10, 'order_id' => 10, 'user_id' => 2, 'delegate_id' => 3]);
putLaunchRow($pdo, $source, 'payments', ['id' => 11, 'order_id' => 11, 'user_id' => 2, 'total_price' => '125.50']);
putLaunchRow($pdo, $source, 'payments', ['id' => 12, 'order_id' => 12, 'user_id' => 2, 'total_price' => '200.75']);
putLaunchRow($pdo, $source, 'wallets', ['id' => 10, 'order_id' => 10, 'from_user' => 2, 'to_user' => 3, 'amount' => '125.5']);
putLaunchRow($pdo, $source, 'wallets', ['id' => 11, 'order_id' => 11, 'from_user' => 2, 'to_user' => 3, 'amount' => '99.25']);
foreach ([10 => 'f:94', 11 => 'gs:94'] as $id => $branch) {
    putLaunchRow($pdo, $source, 'takeaway_tills', ['id' => $id, 'branch' => $branch, 'balance_cents' => 123400, 'revision' => 1]);
    putLaunchRow($pdo, $source, 'pos_service_tables', ['id' => $id, 'branch' => $branch, 'name' => 'table '.$id, 'active_ticket_id' => $id, 'revision' => 1]);
    putLaunchRow($pdo, $source, 'takeaway_orders', ['id' => $id, 'branch' => $branch, 'till_id' => $id, 'ticket_id' => $id, 'actor_id' => 1, 'request_key' => 'request-'.$id, 'total_cents' => 123400, 'channel' => 'phone']);
    putLaunchRow($pdo, $source, 'pos_service_tickets', ['id' => $id, 'branch' => $branch, 'paid_order_id' => $id, 'table_id' => $id, 'channel' => 'phone', 'status' => 'finished', 'revision' => 1]);
    putLaunchRow($pdo, $source, 'takeaway_order_items', ['id' => $id, 'order_id' => $id]);
    putLaunchRow($pdo, $source, 'takeaway_till_entries', ['id' => $id, 'branch' => $branch, 'till_id' => $id, 'order_id' => $id, 'request_key' => 'till-'.$id]);
    putLaunchRow($pdo, $source, 'branch_expenses', ['id' => $id, 'branch' => $branch, 'amount_cents' => 10000]);
    putLaunchRow($pdo, $source, 'branch_expense_commands', ['id' => $id, 'branch' => $branch, 'expense_id' => $id, 'request_key' => 'expense-'.$id]);
    putLaunchRow($pdo, $source, 'branch_inventory', ['id' => $id, 'branch' => $branch, 'quantity_units' => -1000000, 'revision' => 1]);
    putLaunchRow($pdo, $source, 'branch_shift_closings', ['id' => $id, 'branch' => $branch, 'sequence' => 1, 'request_key' => 'shift-'.$id]);
    putLaunchRow($pdo, $source, 'branch_shift_sources', ['id' => $id, 'branch' => $branch, 'closing_id' => $id, 'source' => 'pos', 'source_id' => $id]);
}
putLaunchRow($pdo, $source, 'branch_attendance_rules', ['id' => 1, 'branch' => 'f:94', 'shift' => 'morning', 'starts_at' => '09:10:00', 'ends_at' => '19:00:00', 'revision' => 1]);
putLaunchRow($pdo, $source, 'branch_attendance_rules', ['id' => 2, 'branch' => 'f:94', 'shift' => 'evening', 'starts_at' => '15:10:00', 'ends_at' => '01:00:00', 'revision' => 1]);
for ($i = 1; $i <= 21; $i++) {
    putLaunchRow($pdo, $source, 'branch_employees', ['id' => $i, 'branch' => $i === 21 ? 'f:301' : 'f:94', 'name' => 'Test Employee '.$i, 'hired_on' => '2026-09-01', 'active' => 1, 'shift' => $i === 2 ? 'evening' : 'morning', 'schedule_type' => $i === 3 ? 'part_time' : 'branch', 'attendance_start' => $i === 3 ? '18:00:00' : null, 'attendance_end' => $i === 3 ? '22:00:00' : null, 'attendance_shift' => $i === 3 ? 'evening' : null, 'revision' => 1]);
    putLaunchRow($pdo, $source, 'branch_employee_salaries', ['id' => $i, 'branch' => 'f:94', 'employee_id' => $i, 'amount_cents' => 710000, 'effective_month' => '2026-09']);
}
putLaunchRow($pdo, $source, 'branch_employee_days', ['id' => 1, 'branch' => 'f:94', 'employee_id' => 1, 'day' => '2026-10-01', 'status' => 'unauthorized_absence', 'revision' => 1]);
putLaunchRow($pdo, $source, 'branch_employee_entries', ['id' => 1, 'branch' => 'f:94', 'employee_id' => 1, 'day' => '2026-09-20', 'kind' => 'advance', 'amount_cents' => 12345, 'revision' => 1]);
putLaunchRow($pdo, $source, 'branch_payrolls', ['id' => 1, 'branch' => 'f:94', 'employee_id' => 1, 'month' => '2026-10', 'status' => 'paid']);
putLaunchRow($pdo, $source, 'branch_customers', ['id' => 1, 'branch' => 'f:94', 'name' => "Customer quote ' slash \\ newline\nعربي", 'phone_key' => '01000000001']);
putLaunchRow($pdo, $source, 'branch_operation_commands', ['id' => 1, 'branch' => 'f:94', 'kind' => 'customer.save', 'request_key' => 'preserved']);
putLaunchRow($pdo, $source, 'branch_operation_commands', ['id' => 2, 'branch' => 'f:94', 'kind' => 'employee.entry', 'request_key' => 'deleted']);
// Enforce the real RESTRICT relationships and the order children during the reset test.
foreach (['takeaway_orders', 'pos_service_tickets', 'pos_service_commands', 'pos_service_kitchen_tickets', 'takeaway_order_items', 'takeaway_till_entries', 'branch_expense_commands', 'branch_shift_sources', 'payments', 'reviews', 'commissions', 'delegate_notifications', 'shippings'] as $table) {
    foreach ($source[$table]['references'] as $i => $ref) if (in_array($ref['table'], ['takeaway_tills', 'takeaway_orders', 'pos_service_tables', 'pos_service_tickets', 'branch_expenses', 'branch_shift_closings', 'orders'])) {
        $pdo->exec('ALTER TABLE '.LaunchReset::name($table).' ADD CONSTRAINT '.LaunchReset::name('test_'.$table.'_'.$i).' FOREIGN KEY ('.LaunchReset::name($ref['column']).') REFERENCES '.LaunchReset::name($ref['table']).' ('.LaunchReset::name($ref['target_column']).') ON DELETE '.$ref['delete_rule']);
    }
}

$schema = launchSchema($pdo);
$baseline = $schema; unset($baseline['payments']['columns']['total_price']);
if (launchReviewedSchema($baseline, $schema) != $schema) throw new RuntimeException('Reviewed payment schema not recognized from MariaDB metadata');
$readOnly = new PDO($baseDsn.';dbname='.$name, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
$readOnly->exec('SET SESSION TRANSACTION READ ONLY');
if (launchSchema($readOnly) != $schema) throw new RuntimeException('Read-only schema inspection changed metadata');
try { $readOnly->exec('UPDATE users SET balance=balance WHERE id=2'); throw new RuntimeException('Read-only connection allowed writes'); }
catch (PDOException $expectedReadOnly) { if ($expectedReadOnly->getCode() !== '25006') throw $expectedReadOnly; }
$readOnly = null;
$reset = new LaunchReset($pdo, $schema);
$pdo->exec('SET autocommit=0');
$lockSql = 'LOCK TABLES '.implode(',', array_map(fn($table) => LaunchReset::name($table).' WRITE', array_keys($schema)));
$pdo->exec($lockSql);
$plan = $reset->plan('mariadb-test');
$before = []; $preserved = [];
foreach ($schema as $table => $_) { $before[$table] = $reset->fingerprint($table, false); $preserved[$table] = $reset->fingerprint($table, true); }
$backupDir = '/tmp/launch-reset-mariadb/backup-'.getmypid(); mkdir($backupDir, 0700);
$backup = launchBackup($pdo, $schema, $backupDir);
$reset->apply(); $verification = $reset->verify();
foreach ($schema as $table => $_) if ($preserved[$table] !== $reset->fingerprint($table, true)) throw new RuntimeException('Unrelated row changed '.$table);
if ($pdo->query('SELECT balance FROM users WHERE id=2')->fetchColumn() !== '9876.54') throw new RuntimeException('Customer money changed');
if ($pdo->query('SELECT order_id FROM wallets WHERE id=10')->fetchColumn() !== null) throw new RuntimeException('Old wallet link retained');
if ($pdo->query('SELECT balance_cents FROM takeaway_tills WHERE id=11')->fetchColumn() !== '123400') throw new RuntimeException('Go drawer changed');
$pdo->exec('ROLLBACK');
foreach ($schema as $table => $_) if ($before[$table] !== $reset->fingerprint($table, false)) throw new RuntimeException('Rollback changed '.$table);
$pdo->exec('UNLOCK TABLES'); $pdo->exec('SET autocommit=1');

// The SQL backup is executable, including quoted/binary-safe multilingual profile contents.
$recovery = new PDO($baseDsn.';dbname='.$name.'_recovery', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]);
$recovery->exec(gzdecode(file_get_contents($backupDir.'/database-before.sql.gz')));
$restored = new LaunchReset($recovery, launchSchema($recovery));
foreach ($schema as $table => $_) if ($before[$table] !== $restored->fingerprint($table, false)) throw new RuntimeException('Backup restoration mismatch '.$table);

// Commit the same operation after rollback, with primary keys remaining monotonic.
$pdo->exec('SET autocommit=0'); $pdo->exec($lockSql);
$committed = new LaunchReset($pdo, $schema); $committed->plan('mariadb-commit'); $committed->apply(); $committed->verify();
$pdo->exec('COMMIT'); $pdo->exec('UNLOCK TABLES'); $pdo->exec('SET autocommit=1');
if ($pdo->query("SELECT COUNT(*) FROM branch_employee_days WHERE status IN ('present','morning','evening','part_time')")->fetchColumn() !== '399') throw new RuntimeException('Attendance was not durable');
if ($pdo->query('SELECT COUNT(*) FROM orders WHERE id IN (11,12)')->fetchColumn() !== '2') throw new RuntimeException('Other order families were removed');
if ($pdo->query('SELECT total_price FROM payments WHERE id=11')->fetchColumn() !== '125.50'
    || $pdo->query('SELECT total_price FROM payments WHERE id=12')->fetchColumn() !== '200.75') throw new RuntimeException('Preserved wallet/other order payment amount changed');
$recovery = null; $pdo = null;
$admin->exec('DROP DATABASE '.LaunchReset::name($name.'_recovery')); $admin->exec('DROP DATABASE '.LaunchReset::name($name));
echo "PASS MariaDB: reviewed payment metadata, read-only inspection, real FK ordering, locked rollback/commit, 399 attendance days, preserved wallet/other-order payment amounts, whole-table fingerprints and verified SQL backup restoration\n";
