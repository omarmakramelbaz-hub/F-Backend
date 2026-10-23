<?php
// Read-only account-scoped aggregates. No exporter, importer, route or journal is run.
final class GoErpImport422ProbeStopped extends RuntimeException {}

function goErp422Need($condition, string $code): void
{
    if (!$condition) throw new GoErpImport422ProbeStopped($code);
}

function goErp422ReadSql(string $query): bool
{
    $sql = trim($query);
    return (bool) preg_match('/\A(?:SELECT|SHOW)\b/i', $sql)
        && !preg_match('/;|--|#|\/\*|\b(?:FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|INTO\s+(?:OUTFILE|DUMPFILE)|GET_LOCK|RELEASE_LOCK|SLEEP|BENCHMARK|LOAD_FILE)\b/i', $sql);
}

function goErp422DdlSafe(string $table, string $ddl): bool
{
    if (strlen($ddl) >= 1024 * 1024 || !str_starts_with($ddl, 'CREATE TABLE `'.$table.'` (')) return false;
    $syntax = preg_replace('/\x27(?:[^\x27\\\\]|\\\\.|\x27\x27)*\x27|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`/s', '_', $ddl);
    $syntax = preg_replace('/\bON\s+(?:DELETE|UPDATE)\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION)\b/i', '', $syntax ?? '');
    $syntax = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\([0-9]*\))?/i', '', $syntax);
    return $syntax !== null && preg_match('/\bENGINE=InnoDB\b/i', $syntax)
        && !preg_match('/;|--|#|\/\*|REFERENCES\s+_\s*\.|\b(?:SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|RENAME|TRUNCATE|GRANT|REVOKE|INTO|OUTFILE|INFILE|TABLESPACE|DIRECTORY|FEDERATED|TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i', substr($syntax, strlen('CREATE TABLE')));
}

function goErp422Json($value, int $precision): string
{
    $before = ini_get('serialize_precision');
    try {
        goErp422Need(ini_set('serialize_precision', (string) $precision) !== false
            && ini_get('serialize_precision') === (string) $precision, 'PRECISION_SIMULATION_UNAVAILABLE');
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    } finally {
        ini_set('serialize_precision', $before);
    }
}

function goErp422Roundtrip($value, int $source, int $target): bool
{
    $wire = goErp422Json($value, $source);
    $decoded = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);
    return hash_equals(hash('sha256', $wire), hash('sha256', goErp422Json($decoded, $target)));
}

function goErp422FloatCount($value, int $depth = 0): int
{
    goErp422Need($depth <= 32, 'JSON_PROJECTION_DEPTH_LIMIT');
    if (is_float($value)) return 1;
    if (!is_array($value) && !is_object($value)) return 0;
    $count = 0;
    foreach ($value as $item) $count += goErp422FloatCount($item, $depth + 1);
    return $count;
}

function goErp422Run(): array
{
    goErp422Need(PHP_SAPI === 'cli', 'CLI_REQUIRED');
    $input = stream_get_contents(STDIN, 4097);
    goErp422Need(is_string($input) && strlen($input) <= 4096, 'PROBE_INPUT_INVALID');
    $input = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    goErp422Need(is_array($input) && array_keys($input) === ['fpm_serialize_precision']
        && is_string($input['fpm_serialize_precision'])
        && preg_match('/^-?[0-9]{1,2}$/D', $input['fpm_serialize_precision']), 'PROBE_INPUT_INVALID');
    $fpmPrecision = (int) $input['fpm_serialize_precision'];
    goErp422Need($fpmPrecision >= -1 && $fpmPrecision <= 30, 'PROBE_INPUT_PRECISION_INVALID');
    $before = ['sapi' => PHP_SAPI, 'php_version' => PHP_VERSION,
        'precision' => ini_get('precision'), 'serialize_precision' => ini_get('serialize_precision')];
    $root = '/home/fasakha/public_html';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $guardedQueries = 0;
    // PDO's init command makes the database session READ ONLY before provider
    // boot can query it. The pre-execution callback additionally refuses locks,
    // SQL writes and reconnects; no response discloses SQL or its bindings.
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function($app) {
        $default = $app['config']->get('database.default');
        $connection = $app['config']->get('database.connections.'.$default);
        goErp422Need(is_array($connection) && ($connection['driver'] ?? '') === 'mysql'
            && extension_loaded('pdo_mysql') && defined('PDO::MYSQL_ATTR_INIT_COMMAND'), 'MYSQL_REQUIRED');
        $connection['options'][PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
        $app['config']->set('database.connections.'.$default, $connection);
    });
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class, function($app) use (&$guardedQueries) {
        $connection = $app['db']->connection();
        goErp422Need($connection instanceof \Illuminate\Database\MySqlConnection
            && method_exists($connection, 'beforeExecuting'), 'SQL_GUARD_UNAVAILABLE');
        $connection->beforeExecuting(function($query) use (&$guardedQueries) {
            goErp422Need(is_string($query) && goErp422ReadSql($query), 'SQL_WRITE_OR_LOCK_BLOCKED');
            $guardedQueries++;
        });
        $connection->setReconnector(function() { throw new GoErpImport422ProbeStopped('SQL_RECONNECT_BLOCKED'); });
    });
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    goErp422Need($app->version() === '8.83.29', 'FRAMEWORK_UNREVIEWED');
    $source = app(\App\Services\Dashboard\DesktopDashboardSource::class)->fingerprint();
    goErp422Need(($source['sha256'] ?? '') === '08e84b198c6bbbd4d917226f96b73b6762f1d3a9d2a34d56446defab2f7ab44b', 'APPLICATION_SOURCE_UNREVIEWED');
    $connection = \Illuminate\Support\Facades\DB::connection();
    goErp422Need($connection->transactionLevel() === 0, 'TRANSACTION_ALREADY_ACTIVE');
    $readonly = $connection->select("SHOW SESSION VARIABLES WHERE Variable_name IN ('tx_read_only','transaction_read_only')");
    goErp422Need(count($readonly) >= 1 && count(array_filter($readonly, static fn($item) => (string)$item->Value !== 'ON' && (string)$item->Value !== '1')) === 0,
        'DATABASE_READ_ONLY_UNVERIFIED');
    $connection->beginTransaction();
    try {
        $devices = \Illuminate\Support\Facades\DB::table('desktop_dashboard_devices')
            ->where('enabled', true)->where('actor_id', 1)->where('enrolled_at', '2026-10-22 10:34:59')->get();
        goErp422Need(count($devices) === 1, 'DEVICE_MISSING_OR_AMBIGUOUS');
        $device = $devices[0];
        $actor = app(\App\Services\Dashboard\DesktopDashboardDevices::class)->actor($device);
        $data = app(\App\Services\Dashboard\DesktopDashboardData::class);
        goErp422Need($data->allAdministration($actor), 'ACCOUNT_SCOPE_UNREVIEWED');
        $branches = json_decode($device->branches, true, 512, JSON_THROW_ON_ERROR);
        goErp422Need(is_array($branches) && count($branches) > 0, 'DEVICE_BRANCHES_INVALID');
        $queries = $data->queries($device, $actor, $branches);
        $actorRows = isset($queries['users']) ? (clone $queries['users'])->where('id', $actor->id)->count() : 0;
        $modes = array_values(array_unique([$fpmPrecision, -1, 17]));
        $targets = [-1, 17];
        $tables = []; $ddlRejected = []; $schema = []; $total = 0; $bytes = 0;
        foreach ($queries as $table => $query) {
            goErp422Need(in_array($table, \App\Services\Dashboard\DesktopDashboardSchema::TABLES, true)
                && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table), 'TABLE_UNREVIEWED');
            $structure = (array) $connection->selectOne('SHOW CREATE TABLE `'.$table.'`');
            $ddl = (string) array_values($structure)[1];
            if (!goErp422DdlSafe($table, $ddl)) $ddlRejected[] = $table;
            $schema[$table] = hash('sha256', $ddl);
            $columns = $connection->select('SELECT COLUMN_NAME AS name,DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$connection->getDatabaseName(), $table]);
            $selected = []; $descriptors = [];
            foreach ($columns as $column) {
                $numeric = in_array(strtolower($column->type), ['float', 'double', 'real', 'decimal', 'numeric'], true);
                $json = strtolower($column->type) === 'json'
                    || in_array($column->name, ['payload', 'snapshot', 'data', 'metadata', 'custom_properties', 'responsive_images', 'context_snapshot'], true);
                if (!$numeric && !$json) continue;
                if ($query->columns !== null && $query->columns !== ['*'] && !in_array($column->name, $query->columns, true)) continue;
                goErp422Need(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $column->name), 'COLUMN_UNREVIEWED');
                $selected[] = $column->name;
                $descriptors[$column->name] = ['sql_type' => strtolower($column->type), 'php_types' => [],
                    'is_numeric_projection' => $numeric, 'json_float_values' => 0];
            }
            if (!$selected) continue;
            $rowCount = 0; $mismatches = [];
            foreach ($modes as $sourceMode) foreach ($targets as $targetMode) $mismatches[$sourceMode.'=>'.$targetMode] = 0;
            foreach ((clone $query)->select($selected)->cursor() as $record) {
                goErp422Need(++$total <= 500000, 'ROW_LIMIT');
                $rowCount++; $row = (array) $record; $projection = [];
                foreach ($row as $name => $value) {
                    $type = get_debug_type($value);
                    $descriptors[$name]['php_types'][$type] = ($descriptors[$name]['php_types'][$type] ?? 0) + 1;
                    if ($descriptors[$name]['is_numeric_projection']) $projection[$name] = $value;
                    elseif (is_string($value) && strlen($value) <= 65536) {
                        try { $decoded = json_decode($value, false, 32, JSON_THROW_ON_ERROR); }
                        catch (\JsonException $ignored) { continue; }
                        $descriptors[$name]['json_float_values'] += goErp422FloatCount($decoded);
                    }
                }
                $bytes += strlen(goErp422Json($projection, -1));
                goErp422Need($bytes <= 256 * 1024 * 1024, 'PROJECTION_BYTE_LIMIT');
                foreach ($modes as $sourceMode) foreach ($targets as $targetMode)
                    if (!goErp422Roundtrip($projection, $sourceMode, $targetMode)) $mismatches[$sourceMode.'=>'.$targetMode]++;
            }
            $tables[$table] = ['rows_checked' => $rowCount, 'columns' => $descriptors,
                'numeric_projection_mismatch_rows' => $mismatches];
        }
        ksort($schema);
        $schemaInvariant = true;
        foreach ($modes as $sourceMode) foreach ($targets as $targetMode)
            $schemaInvariant = $schemaInvariant && goErp422Roundtrip($schema, $sourceMode, $targetMode);
        return ['result' => 'GO_ERP_IMPORT422_READ_ONLY_CHECK', 'server_cli' => $before,
            'matched_devices' => 1, 'base_branch_count' => count($branches), 'actor_rows_in_users_query' => $actorRows,
            'actor_row_check_passes' => $actorRows === 1, 'tables_checked' => count($queries),
            'ddl_guard_rejected_tables' => $ddlRejected, 'schema_hash_precision_invariant' => $schemaInvariant,
            'numeric_tables' => $tables, 'source_precision_variants' => $modes, 'target_precision_variants' => $targets,
            'projection_scope' => 'base account queries; selected numeric/JSON columns; before redaction and FK closure',
            'catalog_grant_hashes_checked' => false, 'catalog_reason' => 'grant export requires locks; actual wire snapshot is not available',
            'actual_snapshot_checked' => false, 'desktop_actual_settings_checked' => false,
            'guarded_read_queries' => $guardedQueries, 'credentials_disclosed' => false,
            'schema_or_business_writes' => false];
    } finally {
        $connection->rollBack();
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        echo json_encode(goErp422Run(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        echo json_encode(['result' => 'GO_ERP_IMPORT422_READ_ONLY_STOPPED',
            'reason' => $error instanceof GoErpImport422ProbeStopped ? $error->getMessage() : get_class($error),
            'schema_or_business_writes' => false, 'credentials_disclosed' => false]).PHP_EOL;
        exit(1);
    }
}
