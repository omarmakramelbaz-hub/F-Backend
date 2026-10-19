<?php
declare(strict_types=1);

/** Read-only connection inventory. Never return raw SQL, hosts, users or database names. */
function launchConnectionSummary(array $row, string $database, string $self, array $transactions, bool $visible): ?array
{
    $id = (string) ($row['Id'] ?? '');
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id) || $id === $self || ($row['db'] ?? null) !== $database) return null;
    $commands = ['Sleep', 'Query', 'Execute', 'Prepare', 'Connect', 'Daemon', 'Statistics', 'Field List', 'Change user', 'Binlog Dump', 'Binlog Dump GTID', 'Killed', 'Set option', 'Reset stmt', 'Close stmt', 'Fetch'];
    $command = in_array($row['Command'] ?? null, $commands, true) ? $row['Command'] : 'Other';
    $state = strtolower((string) ($row['State'] ?? ''));
    $stateClass = $state === '' ? 'idle' : (str_contains($state, 'lock') ? 'lock_wait' : 'working');
    $seconds = (string) ($row['Time'] ?? '');
    return ['id' => $id, 'command' => $command, 'seconds' => preg_match('/^[0-9]{1,12}$/D', $seconds) ? (int) $seconds : null,
        'state_class' => $stateClass, 'open_transaction' => $visible ? isset($transactions[$id]) : null];
}

function launchConnectionSettings(array $row): array
{
    $result = [];
    foreach (['innodb_table_locks', 'autocommit', 'session_wait_timeout', 'global_wait_timeout'] as $key) {
        $value = (string) ($row[$key] ?? '');
        $result[$key] = preg_match('/^[0-9]{1,12}$/D', $value) ? (int) $value : null;
    }
    $version = (string) ($row['version'] ?? '');
    $result['version'] = preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(?:[-+].*)?$/D', $version, $match)
        ? $match[1].(stripos($version, 'MariaDB') !== false ? '-MariaDB' : '') : 'unrecognized';
    return $result;
}

function launchCheckConnectionsMain(array $args): int
{
    ini_set('display_errors', '0');
    $stage = 'arguments';
    try {
        if (count($args) !== 2 || $args[1] !== '/home/fasakha/public_html' || realpath($args[1]) !== $args[1] || is_link($args[1])) throw new RuntimeException();
        $root = $args[1]; $stage = 'configuration';
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
        (new Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
        $config = $app['config']->get('database'); $capsule = new Illuminate\Database\Capsule\Manager();
        $capsule->addConnection($config['connections'][$config['default']]); $connection = $capsule->getConnection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') throw new RuntimeException();
        $stage = 'connection'; $pdo = $connection->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $identity = $pdo->query('SELECT DATABASE() AS database_name, CONNECTION_ID() AS connection_id')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($identity) || !is_string($identity['database_name']) || !is_string($identity['connection_id'])) throw new RuntimeException();
        $stage = 'settings';
        $settings = $pdo->query('SELECT @@innodb_table_locks AS innodb_table_locks, @@autocommit AS autocommit, @@version AS version, @@SESSION.wait_timeout AS session_wait_timeout, @@GLOBAL.wait_timeout AS global_wait_timeout')->fetch(PDO::FETCH_ASSOC);
        echo 'DATABASE_SETTINGS='.json_encode(launchConnectionSettings($settings), JSON_THROW_ON_ERROR)."\n";
        for ($sample = 1; $sample <= 2; $sample++) {
            $stage = 'process_inventory'; $rows = $pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
            $visible = true; $transactions = [];
            try {
                foreach ($pdo->query('SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX')->fetchAll(PDO::FETCH_COLUMN) as $id) $transactions[(string) $id] = true;
            } catch (Throwable $ignored) { $visible = false; }
            $items = [];
            foreach ($rows as $row) { $item = launchConnectionSummary($row, $identity['database_name'], $identity['connection_id'], $transactions, $visible); if ($item !== null) $items[] = $item; }
            usort($items, fn($a, $b) => strlen($a['id']) <=> strlen($b['id']) ?: strcmp($a['id'], $b['id']));
            echo 'DATABASE_CONNECTION_SAMPLE='.json_encode(['sample' => $sample, 'at_utc' => gmdate('c'), 'transaction_visibility' => $visible ? 'available' : 'unavailable', 'connections' => $items], JSON_THROW_ON_ERROR)."\n";
            if ($sample === 1) sleep(3);
        }
        return 0;
    } catch (Throwable $ignored) {
        fwrite(STDERR, 'CONNECTION_DIAGNOSTIC_FAILED stage='.$stage."\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(launchCheckConnectionsMain($argv));
