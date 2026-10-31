<?php
declare(strict_types=1);

namespace GoErp\Operations;

use PDO;
use RuntimeException;
use Throwable;

/** Read-only evidence for the reported phone conflict and independent-owner provisioning. */
final class InspectOwnerDashboards
{
    public static function phone(string $value): ?string
    {
        $value = strtr(trim($value), array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ));
        $value = preg_replace('/[\s().-]+/u', '', $value);
        if (!is_string($value)) return null;
        if (str_starts_with($value, '0020')) $value = substr($value, 4);
        elseif (str_starts_with($value, '+20')) $value = substr($value, 3);
        elseif (str_starts_with($value, '20')) $value = substr($value, 2);
        if (str_starts_with($value, '0')) $value = substr($value, 1);
        return preg_match('/^1[0125][0-9]{8}$/D', $value) ? $value : null;
    }

    /** Never return SHOW GRANTS text: older servers can include a password hash in it. */
    public static function privileges(array $grants): array
    {
        $result = ['global_create_database' => false, 'global_create_user' => false,
            'global_grant_option' => false];
        foreach ($grants as $grant) {
            if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s+/i', $grant, $match)) continue;
            $all = preg_match('/(?:^|,\s*)ALL(?: PRIVILEGES)?(?:,|$)/i', $match[1]) === 1;
            $result['global_create_database'] = $result['global_create_database'] || $all
                || preg_match('/(?:^|,\s*)CREATE(?:,|$)/i', $match[1]) === 1;
            $result['global_create_user'] = $result['global_create_user'] || $all
                || preg_match('/(?:^|,\s*)CREATE USER(?:,|$)/i', $match[1]) === 1;
            $result['global_grant_option'] = $result['global_grant_option']
                || stripos($grant, 'WITH GRANT OPTION') !== false;
        }
        return $result;
    }

    public static function inspect(PDO $db, string $requestedPhone): array
    {
        $wanted = self::phone($requestedPhone);
        if ($wanted === null) throw new RuntimeException('GO_OWNER_AUDIT_INVALID_PHONE');
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true) || $db->inTransaction()) {
            throw new RuntimeException('GO_OWNER_AUDIT_UNSUPPORTED_CONNECTION');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $previousQueryOnly = null;
        if ($driver === 'mysql') $db->exec('SET TRANSACTION READ ONLY');
        else {
            $previousQueryOnly = (int) $db->query('PRAGMA query_only')->fetchColumn();
            $db->exec('PRAGMA query_only=ON');
        }
        try {
            $db->beginTransaction();
            $columns = self::columns($db, $driver, 'users');
            foreach (['id', 'name', 'email', 'mobile', 'account_type', 'status'] as $required) {
                if (!in_array($required, $columns, true)) throw new RuntimeException('GO_OWNER_AUDIT_USERS_SCHEMA_REQUIRED');
            }
            // Explicit allowlist: passwords, tokens, session data and credential hashes are never selected.
            $fields = array_values(array_intersect(['id', 'name', 'email', 'mobile', 'account_type',
                'status', 'added_by', 'owner_resturant_id', 'pending_vendor_id'], $columns));
            $query = $db->query('SELECT '.implode(',', array_map([self::class, 'quote'], $fields))
                ." FROM users WHERE account_type IN ('admin','vendor','resturant_owner') ORDER BY id LIMIT 100001");
            $accounts = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($accounts) > 100000) throw new RuntimeException('GO_OWNER_AUDIT_ACCOUNT_LIMIT');
            $owner = null; $matches = []; $types = [];
            foreach ($accounts as $row) {
                $types[$row['account_type']] = ($types[$row['account_type']] ?? 0) + 1;
                if ((int) $row['id'] === 1) $owner = self::account($row);
                if (self::phone((string) ($row['mobile'] ?? '')) === $wanted) $matches[] = self::account($row);
            }
            if ($owner === null || $owner['account_type'] !== 'admin') {
                throw new RuntimeException('GO_OWNER_AUDIT_PRIMARY_OWNER_REQUIRED');
            }
            $schema = self::schema($db, $driver);
            $counts = [];
            foreach (['resturants', 'go_stores', 'roles', 'permissions', 'settings'] as $table) {
                if (in_array($table, $schema['tables'], true)) {
                    $counts[$table] = (int) $db->query('SELECT COUNT(*) FROM '.self::quote($table))->fetchColumn();
                }
            }
            $privileges = $driver === 'mysql'
                ? self::privileges($db->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN)) : null;
            return ['status' => 'GO_OWNER_DASHBOARDS_INSPECTED', 'read_only' => true,
                'primary_owner' => $owner,
                'phone_conflict' => ['requested_phone_suffix' => substr($wanted, -4),
                    'matching_dashboard_accounts' => $matches,
                    'conflicting_account_ids' => array_values(array_map(static fn(array $row): int => $row['id'],
                        array_filter($matches, static fn(array $row): bool => $row['id'] !== 1))),
                    'phone_reassigned' => false],
                'dashboard_account_counts' => $types, 'schema' => [
                    'driver' => $driver, 'table_count' => count($schema['tables']),
                    'view_count' => $schema['views'], 'trigger_count' => $schema['triggers'],
                    'stored_routine_count' => $schema['routines'], 'event_count' => $schema['events'],
                    'external_foreign_keys' => $schema['external_foreign_keys'],
                    'definition_sha256' => $schema['hash'], 'bootstrap_table_counts' => $counts,
                    'existing_owner_partition_columns' => array_values(array_intersect(
                        ['tenant_id', 'dashboard_id', 'workspace_id', 'system_owner_id'], $columns)),
                    'existing_owner_registry_tables' => array_values(array_filter($schema['tables'],
                        static fn(string $name): bool => preg_match('/^(owner_dashboards|dashboard_owners|tenants|tenant_users)$/D', $name) === 1))],
                'database_provisioning_privileges' => $privileges,
                'changes_applied' => false];
        } finally {
            if ($db->inTransaction()) $db->rollBack();
            if ($previousQueryOnly !== null) $db->exec('PRAGMA query_only='.$previousQueryOnly);
        }
    }

    private static function account(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email'],
            'account_type' => (string) $row['account_type'], 'status' => (string) $row['status'],
            'has_mobile' => trim((string) ($row['mobile'] ?? '')) !== '',
            'added_by' => isset($row['added_by']) ? (int) $row['added_by'] : null,
            'restaurant_id' => isset($row['owner_resturant_id']) ? (int) $row['owner_resturant_id'] : null,
            'pending_profile_id' => isset($row['pending_vendor_id']) ? (int) $row['pending_vendor_id'] : null];
    }

    private static function columns(PDO $db, string $driver, string $table): array
    {
        if ($driver === 'sqlite') return array_column($db->query('PRAGMA table_info('.self::quote($table).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $query = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function schema(PDO $db, string $driver): array
    {
        if ($driver === 'sqlite') {
            $definitions = $db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
            $tables = array_column(array_filter($definitions, static fn(array $r): bool => $r['type'] === 'table'), 'name');
            return ['tables' => $tables, 'views' => count(array_filter($definitions, static fn(array $r): bool => $r['type'] === 'view')),
                'triggers' => count(array_filter($definitions, static fn(array $r): bool => $r['type'] === 'trigger')),
                'routines' => 0, 'events' => 0, 'external_foreign_keys' => 0,
                'hash' => hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR))];
        }
        $all = $db->query('SELECT TABLE_NAME AS name,TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
        $tables = array_column(array_filter($all, static fn(array $r): bool => $r['type'] === 'BASE TABLE'), 'name');
        $definitions = $db->query('SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        return ['tables' => $tables, 'views' => count($all) - count($tables),
            'triggers' => (int) $db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn(),
            'routines' => (int) $db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn(),
            'events' => (int) $db->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn(),
            'external_foreign_keys' => (int) $db->query('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>DATABASE()')->fetchColumn(),
            'hash' => hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR))];
    }

    private static function quote(string $value): string { return '`'.str_replace('`', '``', $value).'`'; }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    try {
        if ($argc !== 3 || realpath($argv[1]) !== '/home/fasakha/public_html' || $argv[2] !== '--inspect') {
            throw new RuntimeException('GO_OWNER_AUDIT_ARGUMENTS_REQUIRED');
        }
        $root = $argv[1]; chdir($root);
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $pdo = $app->make('db')->connection()->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('GO_OWNER_AUDIT_MYSQL_REQUIRED');
        $report = InspectOwnerDashboards::inspect($pdo, '01064464499');
        $report['runtime'] = ['php' => PHP_VERSION, 'laravel' => $app->version(),
            'queue_driver' => config('queue.default'), 'session_driver' => config('session.driver'),
            'cache_driver' => config('cache.default'), 'storage_driver' => config('filesystems.default'),
            'desktop_local' => config('desktop_dashboard.local') === true,
            'config_cached' => $app->configurationIsCached(),
            'root_front_controller' => is_file($root.'/index.php'),
            'public_front_controller' => is_file($root.'/public/index.php'),
            'owner_phone_fix_installed' => class_exists(\App\Services\Dashboard\OwnerMobile::class),
            'owner_role_label_installed' => class_exists(\App\Services\Dashboard\DashboardAccountRole::class)];
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException && preg_match('/^GO_OWNER_AUDIT_[A-Z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'GO_OWNER_AUDIT_DATABASE_OR_RUNTIME_CHECK_FAILED';
        fwrite(STDERR, $code.PHP_EOL); exit(1);
    }
}
