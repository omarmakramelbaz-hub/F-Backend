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

function goErp422SourceDigest(string $base): string
{
    // Independent of Laravel/autoload: reject an unreviewed application before
    // bootstrap.php, provider registration or application source is executed.
    goErp422Need(realpath($base) === $base && is_dir($base), 'APPLICATION_DIRECTORY_UNSAFE');
    $roots = ['app','bootstrap','config','database','desktop','resources','routes',
        'public/dashboard/js','public/dashboard/css','public/dashboard/vendor/desktop-external'];
    $files = []; $cases = []; $bytes = 0; $visited = 0;
    $add = static function(string $path, string $name) use (&$files, &$cases, &$bytes): void {
        goErp422Need(is_file($path) && !is_link($path) && preg_match('#^[a-zA-Z0-9_.@/-]+$#D', $name)
            && !isset($cases[strtolower($name)]), 'APPLICATION_SOURCE_FILE_UNSAFE');
        $cases[strtolower($name)] = true;
        $content = file_get_contents($path);
        goErp422Need(is_string($content), 'APPLICATION_SOURCE_UNREADABLE');
        $bytes += strlen($content);
        goErp422Need($bytes <= 64 * 1024 * 1024 && count($files) < 20000, 'APPLICATION_SOURCE_LIMIT');
        $files[$name] = hash('sha256', str_replace("\r\n", "\n", $content));
    };
    foreach ($roots as $root) {
        $directory = $base.'/'.$root;
        if (!is_dir($directory)) continue;
        goErp422Need(!is_link($directory), 'APPLICATION_SOURCE_LINK');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            goErp422Need(++$visited <= 100000, 'APPLICATION_SOURCE_ENTRY_LIMIT');
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (str_starts_with($name, 'bootstrap/cache/')
                || preg_match('#(?:^|/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$#i', $name)) continue;
            goErp422Need(!$file->isLink(), 'APPLICATION_SOURCE_LINK');
            if (preg_match('/\.(?:php|js|css|json|html|scss|vue)$/i', $name)) $add($file->getPathname(), $name);
        }
    }
    if (is_file($base.'/artisan')) $add($base.'/artisan', 'artisan');
    goErp422Need(count($files) > 0, 'APPLICATION_SOURCE_EMPTY');
    ksort($files, SORT_STRING);
    $digest = hash_init('sha256'); hash_update($digest, "desktop-dashboard-source:1\n");
    foreach ($files as $name => $hash) hash_update($digest, $name."\0".$hash."\n");
    return hash_final($digest);
}

function goErp422GuardedConnection($pdo, $database, $prefix, array $config, int &$guardedQueries)
{
    $connection = new class($pdo, $database, $prefix, $config) extends \Illuminate\Database\MySqlConnection {
        public function reconnect() { throw new GoErpImport422ProbeStopped('SQL_RECONNECT_BLOCKED'); }
    };
    goErp422Need(method_exists($connection, 'beforeExecuting'), 'SQL_GUARD_UNAVAILABLE');
    $connection->beforeExecuting(static function($query) use (&$guardedQueries) {
        goErp422Need(is_string($query) && goErp422ReadSql($query), 'SQL_WRITE_OR_LOCK_BLOCKED');
        $guardedQueries++;
    });
    return $connection;
}

function goErp422InstallConnectionGuards(int &$guardedQueries): void
{
    \Illuminate\Database\Connection::resolverFor('mysql', static function($pdo, $database, $prefix, $config) use (&$guardedQueries) {
        goErp422Need(($config['options'][PDO::MYSQL_ATTR_INIT_COMMAND] ?? null) === 'SET SESSION TRANSACTION READ ONLY',
            'DATABASE_READ_ONLY_INIT_MISSING');
        return goErp422GuardedConnection($pdo, $database, $prefix, $config, $guardedQueries);
    });
    foreach (['sqlite', 'pgsql', 'sqlsrv'] as $driver) {
        \Illuminate\Database\Connection::resolverFor($driver, static function() {
            throw new GoErpImport422ProbeStopped('DATABASE_DRIVER_BLOCKED');
        });
    }
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
    goErp422Need(goErp422SourceDigest($root) === '08e84b198c6bbbd4d917226f96b73b6762f1d3a9d2a34d56446defab2f7ab44b',
        'APPLICATION_SOURCE_UNREVIEWED');
    require $root.'/vendor/autoload.php';
    $guardedQueries = 0;
    goErp422InstallConnectionGuards($guardedQueries);
    $app = require $root.'/bootstrap/app.php';
    // Every MySQL connection is guarded from its creation, before providers are
    // registered. PDO's init command also makes each session READ ONLY before
    // provider registration/boot can query it. Non-MySQL drivers are blocked.
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function($app) {
        $default = $app['config']->get('database.default');
        $connections = $app['config']->get('database.connections');
        goErp422Need(is_array($connections) && ($connections[$default]['driver'] ?? '') === 'mysql'
            && extension_loaded('pdo_mysql') && defined('PDO::MYSQL_ATTR_INIT_COMMAND'), 'MYSQL_REQUIRED');
        foreach ($connections as $name => $connection) {
            if (($connection['driver'] ?? '') !== 'mysql') continue;
            goErp422Need(empty($connection['read']) && empty($connection['write']) && empty($connection['url']),
                'DATABASE_CONFIGURATION_UNREVIEWED');
            $connection['options'][PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
            $app['config']->set('database.connections.'.$name, $connection);
        }
    });
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    goErp422Need($app->version() === '8.83.29', 'FRAMEWORK_UNREVIEWED');
    goErp422Need(goErp422SourceDigest($root) === '08e84b198c6bbbd4d917226f96b73b6762f1d3a9d2a34d56446defab2f7ab44b',
        'APPLICATION_SOURCE_CHANGED');
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
