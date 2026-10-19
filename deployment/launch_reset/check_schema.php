<?php
declare(strict_types=1);
require_once __DIR__.'/reset.php';

/** Match the reset's PHP array comparison; associative key order is immaterial. */
function launchSchemaDiff(array $expected, array $actual): array
{
    $changed = []; $missing = []; $new = []; $related = [];
    foreach ($expected as $table => $metadata) {
        if (!array_key_exists($table, $actual)) { $missing[] = $table; continue; }
        if ($metadata != $actual[$table]) {
            $fields = [];
            foreach (array_unique(array_merge(array_keys($metadata), array_keys($actual[$table]))) as $field) {
                if (!array_key_exists($field, $metadata) || !array_key_exists($field, $actual[$table]) || $metadata[$field] != $actual[$table][$field]) $fields[] = $field;
            }
            sort($fields);
            $changed[$table] = ['changed_fields' => $fields, 'expected' => $metadata, 'actual' => $actual[$table]];
        }
    }
    $guardColumns = ['branch', 'order_id', 'paid_order_id', 'ticket_id', 'purchase_id', 'expense_id', 'closing_id', 'batch_id', 'stock_id'];
    foreach ($actual as $table => $metadata) {
        if (array_key_exists($table, $expected)) continue;
        $columns = array_values(array_intersect($guardColumns, array_keys($metadata['columns'] ?? [])));
        $references = array_values(array_filter($metadata['references'] ?? [], fn($reference) => array_key_exists($reference['table'] ?? '', $expected)));
        $deleteReferences = array_values(array_filter($metadata['references'] ?? [], fn($reference) => in_array($reference['table'] ?? '', LaunchReset::DELETABLE_TABLES, true)));
        $isRelated = count($columns) > 0 || count($deleteReferences) > 0;
        $new[$table] = ['metadata' => $metadata, 'guard_columns' => $columns, 'references_expected_tables' => $references,
            'references_deleted_tables' => $deleteReferences, 'requires_relation_review' => $isRelated];
        if ($isRelated) $related[] = $table;
    }
    ksort($changed); sort($missing); ksort($new); sort($related);
    return ['expected_match' => !$changed && !$missing, 'expected_changed' => $changed,
        'expected_missing' => $missing, 'new_tables' => $new, 'new_relation_tables' => $related];
}

function launchSchemaFingerprint(array $schema): string
{
    $canonical = function (array $value) use (&$canonical): array {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $canonical($item);
        return $value;
    };
    return hash('sha256', json_encode($canonical($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function launchSchemaStrictPass(array $diff, bool $stable, int $triggerBefore, int $triggerAfter): bool
{
    return $diff['expected_match'] && !$diff['new_relation_tables'] && $stable && $triggerBefore === 0 && $triggerAfter === 0;
}

function launchCheckSchemaMain(array $args): int
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); $stage = 'arguments';
    try {
        $strict = count($args) === 3 && ($args[1] ?? '') === '--strict';
        $root = $strict ? $args[2] : ($args[1] ?? '');
        if ((!$strict && count($args) !== 2) || $root !== '/home/fasakha/public_html' || realpath($root) !== $root || is_link($root)) throw new RuntimeException();
        $stage = 'configuration';
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
        (new Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
        $config = $app['config']->get('database'); $capsule = new Illuminate\Database\Capsule\Manager();
        $capsule->addConnection($config['connections'][$config['default']]); $connection = $capsule->getConnection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') throw new RuntimeException();
        $stage = 'connection'; $pdo = $connection->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $stage = 'expected_schema';
        $rawExpected = json_decode(file_get_contents(__DIR__.'/inspected_schema.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rawExpected) || !$rawExpected) throw new RuntimeException();
        $stage = 'schema_before'; $before = launchSchema($pdo);
        $triggerBefore = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn();
        $expected = launchReviewedSchema($rawExpected, $before);
        $rawDiff = launchSchemaDiff($rawExpected, $before);
        $variants = [];
        if (isset($rawExpected['payments'], $expected['payments'], $before['payments'])
                && $rawExpected['payments'] != $expected['payments'] && $expected['payments'] == $before['payments']) {
            $variants[] = 'payments.total_price=decimal(15,2) unsigned nullable';
        }
        $diff = launchSchemaDiff($expected, $before); $hashBefore = launchSchemaFingerprint($before);
        $stage = 'schema_after'; $after = launchSchema($pdo);
        $triggerAfter = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn();
        $hashAfter = launchSchemaFingerprint($after);
        $stable = hash_equals($hashBefore, $hashAfter) && $triggerBefore === $triggerAfter;
        $strictPass = launchSchemaStrictPass($diff, $stable, $triggerBefore, $triggerAfter);
        if ($strict && $strictPass) {
            echo 'LAUNCH_SCHEMA_PREFLIGHT_PASSED='.json_encode(['read_only' => true,
                'expected_tables' => count($expected), 'actual_tables' => count($before),
                'schema_stable' => true, 'schema_sha256' => $hashBefore,
                'trigger_count' => 0, 'reviewed_variants' => $variants], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            return 0;
        }
        echo 'LAUNCH_SCHEMA_DIAGNOSTIC='.json_encode(['read_only' => true, 'at_utc' => gmdate('c'),
            'expected_tables' => count($expected), 'actual_tables_before' => count($before), 'actual_tables_after' => count($after),
            'raw_expected_schema_sha256' => launchSchemaFingerprint($rawExpected), 'expected_schema_sha256' => launchSchemaFingerprint($expected), 'schema_sha256_before' => $hashBefore,
            'schema_sha256_after' => $hashAfter, 'schema_stable' => $stable, 'strict_requested' => $strict, 'strict_pass' => $strictPass,
            'trigger_count_before' => $triggerBefore, 'trigger_count_after' => $triggerAfter, 'reviewed_variants' => $variants, 'raw_diff' => $rawDiff, 'diff' => $diff],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        return $strict && !$strictPass ? 1 : 0;
    } catch (Throwable $ignored) {
        fwrite(STDERR, 'SCHEMA_DIAGNOSTIC_FAILED stage='.$stage."\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(launchCheckSchemaMain($argv));
