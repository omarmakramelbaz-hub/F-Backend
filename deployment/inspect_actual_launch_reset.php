<?php
declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

/** A read-only inventory for the owner's launch reset. This file has no apply mode. */
final class ActualLaunchResetInspection
{
    public const FROM = '2026-10-01';
    public const THROUGH = '2026-10-19';

    private ConnectionInterface $db;
    private array $schema;

    public function __construct(ConnectionInterface $db, array $schema)
    {
        $this->db = $db;
        $this->schema = $schema;
    }

    private function has(string $table, array $columns = []): bool
    {
        return isset($this->schema[$table])
            && !array_diff($columns, array_keys($this->schema[$table]['columns']));
    }

    private function branch(string $table)
    {
        return $this->db->table($table)->where('branch', 'like', 'f:%');
    }

    private function orders()
    {
        return $this->db->table('orders')->whereIn('resturant_id', $this->db->table('resturants')->select('id'))
            ->whereIn('type', ['current', 'schedule']);
    }

    private function grouped($query, array $columns): array
    {
        return (clone $query)->select($columns)->selectRaw('COUNT(*) AS row_count')
            ->groupBy($columns)->orderBy($columns[0])->get()->map(fn($row) => (array) $row)->all();
    }

    public function collect(): array
    {
        $report = ['format' => 1, 'mode' => 'READ_ONLY_NO_DATA_CHANGED',
            'scope' => 'Fasakhansta restaurant branches (f:*); other branch families are counted separately',
            'attendance_from' => self::FROM, 'attendance_through' => self::THROUGH,
            'financial_history' => 'all recorded dates; not limited to October',
            'missing_required' => [], 'branch_tables' => [], 'related_rows' => [],
            'groups' => [], 'employment' => [], 'preserve_counts' => [], 'schema' => []];

        foreach (['resturants' => ['id'], 'orders' => ['id', 'resturant_id', 'type'],
            'branch_employees' => ['id', 'branch', 'hired_on', 'left_on', 'active'],
            'branch_employee_days' => ['employee_id', 'day', 'status', 'checked_in_at', 'checked_out_at', 'attendance_rule_snapshot'],
            'branch_employee_entries' => ['employee_id', 'branch', 'kind', 'amount_cents', 'voided_at'],
            'branch_payrolls' => ['employee_id', 'branch', 'month', 'snapshot'],
            'takeaway_orders' => ['id', 'branch'], 'takeaway_tills' => ['branch', 'balance_cents'],
            'branch_inventory' => ['branch', 'quantity_units'], 'branch_stock' => ['branch', 'quantity_units']]
            as $table => $columns) {
            if (!$this->has($table, $columns)) $report['missing_required'][] = $table;
        }

        // Inspect every installed branch-scoped table, including live-only additions.
        foreach ($this->schema as $table => $meta) {
            if (!$this->has($table, ['branch'])) continue;
            $report['branch_tables'][$table] = ['fasakhansta' => $this->branch($table)->count(),
                'other_or_unassigned' => $this->db->table($table)->where(function ($q) {
                    $q->whereNull('branch')->orWhere('branch', 'not like', 'f:%');
                })->count()];
            $report['schema'][$table] = $meta;
        }

        if ($this->has('resturants', ['id'])) {
            $report['restaurant_ids'] = $this->db->table('resturants')->orderBy('id')->pluck('id')->all();
            if ($this->has('orders', ['id', 'resturant_id', 'type'])) {
                $report['related_rows']['restaurant_app_orders'] = $this->orders()->count();
                $report['related_rows']['unassigned_restaurant_orders'] = $this->db->table('orders')
                    ->whereIn('type', ['current', 'schedule'])->where(function ($q) {
                        $q->whereNull('resturant_id')->orWhereNotIn('resturant_id', $this->db->table('resturants')->select('id'));
                    })->count();
                $groupColumns = array_values(array_filter(['type', 'status', 'payment_type'], fn($c) => $this->has('orders', [$c])));
                $report['groups']['app_orders'] = $this->grouped($this->orders(), $groupColumns);
                foreach (['carts', 'payments', 'commissions', 'delegate_notifications', 'reviews', 'shippings', 'wallets',
                    'go_order_payments', 'go_order_payment_receipts'] as $table) {
                    if ($this->has($table, ['order_id'])) {
                        $query = $this->db->table($table)->whereIn('order_id', $this->orders()->select('id'));
                        $report['related_rows'][$table] = $query->count();
                        if ($table === 'payments' && $this->has($table, ['status'])) $report['groups']['app_payments'] = $this->grouped($query, ['status']);
                    }
                }
                if ($this->has('order_board_clocks', ['source', 'order_id'])) {
                    $report['related_rows']['order_board_clocks_legacy'] = $this->db->table('order_board_clocks')
                        ->where('source', 'legacy')->whereIn('order_id', $this->orders()->select('id'))->count();
                }
                if ($this->has('notifications', ['notifiable_type', 'notifiable_id'])) {
                    $report['related_rows']['order_notifications'] = $this->db->table('notifications')
                        ->where('notifiable_type', 'App\\Models\\Order')->whereIn('notifiable_id', $this->orders()->select('id'))->count();
                }
            }
        }

        foreach ([['takeaway_order_items', 'order_id', 'takeaway_orders'],
            ['phone_delivery_batch_items', 'batch_id', 'phone_delivery_batches'],
            ['desktop_pos_snapshots', 'device_id', 'desktop_pos_devices'],
            ['whatsapp_order_drafts', 'ticket_id', 'pos_service_tickets'],
            ['meta_order_drafts', 'ticket_id', 'pos_service_tickets'],
            ['crm_whatsapp_deliveries', 'purchase_id', 'crm_purchases']]
            as [$child, $foreign, $parent]) {
            if ($this->has($child, [$foreign]) && $this->has($parent, ['id', 'branch'])) {
                $report['related_rows'][$child] = $this->db->table($child)
                    ->whereIn($foreign, $this->branch($parent)->select('id'))->count();
            }
        }
        if ($this->has('crm_customer_feedback_replies', ['delivery_id'])
            && $this->has('crm_whatsapp_deliveries', ['id', 'purchase_id']) && $this->has('crm_purchases', ['id', 'branch'])) {
            $report['related_rows']['crm_customer_feedback_replies'] = $this->db->table('crm_customer_feedback_replies')
                ->whereIn('delivery_id', $this->db->table('crm_whatsapp_deliveries')
                    ->whereIn('purchase_id', $this->branch('crm_purchases')->select('id'))->select('id'))->count();
        }

        foreach (['branch_employee_entries' => ['kind', 'voided_at'], 'branch_payrolls' => ['month', 'status'],
            'takeaway_orders' => ['channel', 'payment_method'], 'pos_service_tickets' => ['channel', 'status'],
            'branch_operation_commands' => ['kind'], 'branch_expenses' => ['status'],
            'desktop_pos_devices' => ['enabled'], 'desktop_pos_orders' => ['status'],
            'crm_purchases' => ['source_type', 'feedback_status']]
            as $table => $columns) {
            if ($this->has($table, array_merge(['branch'], $columns))) {
                // Do not expose void timestamps or any personal row contents.
                if ($table === 'branch_employee_entries') {
                    $report['groups'][$table] = $this->grouped($this->branch($table)->whereNull('voided_at'), ['kind']);
                    $report['related_rows']['nonvoided_employee_entry_cents'] = (string) $this->branch($table)->whereNull('voided_at')->sum('amount_cents');
                } else $report['groups'][$table] = $this->grouped($this->branch($table), $columns);
            }
        }

        if ($this->has('branch_employees', ['id', 'branch', 'hired_on', 'left_on', 'active'])) {
            $employees = $this->branch('branch_employees');
            $report['employment'] = ['employees' => $employees->count(),
                'active' => (clone $employees)->where('active', 1)->count(),
                'hired_after_october_1' => (clone $employees)->where('hired_on', '>', self::FROM)->count(),
                'left_before_october_19' => (clone $employees)->whereNotNull('left_on')->where('left_on', '<', self::THROUGH)->count(),
                'invalid_employment_dates' => 0, 'eligible_employee_days' => 0];
            foreach ((clone $employees)->select('hired_on', 'left_on')->get() as $employee) {
                if (!$employee->hired_on || ($employee->left_on && $employee->left_on < $employee->hired_on)) {
                    $report['employment']['invalid_employment_dates']++; continue;
                }
                $from = max(self::FROM, $employee->hired_on);
                $through = min(self::THROUGH, $employee->left_on ?: self::THROUGH);
                if ($from <= $through) $report['employment']['eligible_employee_days'] += (int)
                    (new DateTimeImmutable($from, new DateTimeZone('Africa/Cairo')))
                        ->diff(new DateTimeImmutable($through, new DateTimeZone('Africa/Cairo')))->days + 1;
            }
            if ($this->has('branch_employee_days', ['employee_id', 'day', 'status'])) {
                $days = $this->db->table('branch_employee_days')->whereIn('employee_id', $employees->select('id'))
                    ->whereBetween('day', [self::FROM, self::THROUGH]);
                $report['groups']['october_attendance'] = $this->grouped($days, ['status']);
            }
        }

        if ($this->has('branch_attendance_rules', ['branch', 'shift', 'starts_at', 'ends_at'])) {
            $report['groups']['attendance_schedules'] = $this->branch('branch_attendance_rules')
                ->select('branch', 'shift', 'starts_at', 'ends_at')->orderBy('branch')->orderBy('shift')
                ->get()->map(fn($r) => (array) $r)->all();
        }
        foreach (['takeaway_tills' => 'balance_cents', 'branch_inventory' => 'quantity_units', 'branch_stock' => 'quantity_units'] as $table => $column) {
            if ($this->has($table, ['branch', $column])) $report['groups'][$table] = $this->branch($table)
                ->select('branch')->selectRaw('COUNT(*) AS row_count, SUM('.$column.') AS balance, SUM(CASE WHEN '.$column.' <> 0 THEN 1 ELSE 0 END) AS nonzero_rows')
                ->groupBy('branch')->orderBy('branch')->get()->map(fn($r) => (array) $r)->all();
        }

        foreach (['desktop_dashboard_devices', 'desktop_dashboard_commands', 'desktop_dashboard_remote_attempts',
            'jobs', 'failed_jobs'] as $table) {
            if (!$this->has($table)) continue;
            $report['related_rows'][$table.'_global'] = $this->db->table($table)->count();
            if ($this->has($table, ['status'])) $report['groups'][$table] = $this->grouped($this->db->table($table), ['status']);
        }
        if ($this->has('desktop_dashboard_devices', ['enabled', 'branches'])) {
            $report['desktop_dashboard_enabled_scopes'] = ['fasakhansta_only' => 0, 'mixed' => 0, 'other' => 0, 'invalid' => 0];
            foreach ($this->db->table('desktop_dashboard_devices')->where('enabled', 1)->select('branches')->get() as $device) {
                $keys = json_decode($device->branches, true);
                if (!is_array($keys) || array_filter($keys, fn($key) => !is_string($key))) { $kind = 'invalid'; }
                else { $f = count(array_filter($keys, fn($key) => (bool) preg_match('/^f:[1-9][0-9]*$/D', $key)));
                    $kind = $f === 0 ? 'other' : ($f === count($keys) ? 'fasakhansta_only' : 'mixed'); }
                $report['desktop_dashboard_enabled_scopes'][$kind]++;
            }
        }

        foreach (['users', 'resturants', 'resturant_products', 'products', 'stock_ingredients', 'branch_stock_recipes',
            'branch_employees', 'branch_employee_salaries', 'branch_customers', 'crm_customers', 'user_address',
            'branch_delivery_companies', 'branch_attendance_rules', 'branch_expense_categories', 'crm_customer_sources',
            'go_store_orders', 'go_service_jobs', 'partner_service_requests'] as $table) {
            if ($this->has($table)) $report['preserve_counts'][$table] = $this->db->table($table)->count();
        }

        // Include physical schema for conventional and live-only references. No row bodies, names, phones, tokens or credentials.
        $referenceColumns = ['order_id', 'paid_order_id', 'ticket_id', 'purchase_id', 'batch_id', 'delivery_id',
            'closing_id', 'expense_id', 'stock_id', 'employee_id', 'notifiable_id'];
        foreach ($this->schema as $table => $meta) {
            if (array_intersect($referenceColumns, array_keys($meta['columns'])) ||
                preg_match('/^(desktop_|branch_|takeaway_|pos_service_|phone_delivery_|crm_(purchase|customer_feedback|whatsapp_deliver)|orders$|resturants$|notifications$)/', $table)) {
                $report['schema'][$table] = $meta;
            }
        }
        // Discover inbound foreign keys even when a live-only child uses an unfamiliar column name.
        $affected = array_fill_keys(['orders', 'takeaway_orders', 'pos_service_tickets', 'pos_service_kitchen_tickets',
            'branch_expenses', 'branch_shift_closings', 'phone_delivery_batches', 'crm_purchases', 'crm_whatsapp_deliveries'], true);
        do {
            $added = false;
            foreach ($this->schema as $table => $meta) {
                if (isset($affected[$table])) continue;
                foreach ($meta['references'] as $reference) {
                    if (!isset($affected[$reference['table']])) continue;
                    $affected[$table] = true;
                    $report['schema'][$table] = $meta;
                    $added = true;
                    break;
                }
            }
        } while ($added);
        ksort($report['schema']);
        return $report;
    }
}

function actualLaunchResetInspectionMain(array $arguments): int
{
    if (count($arguments) !== 3 || $arguments[1] !== '--check' || $arguments[2] !== '/home/fasakha/public_html') {
        fwrite(STDERR, "USAGE: php inspect_actual_launch_reset.php --check /home/fasakha/public_html\nNO_APPLY_MODE\n");
        return 2;
    }
    $stage = 'configuration';
    $connection = null;
    try {
        $root = $arguments[2];
        if (realpath($root) !== $root || is_link($root)) throw new RuntimeException('Invalid application root');
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        // Only environment and configuration loaders; never register/boot application providers or a console kernel.
        (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
        (new Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
        $configuration = $app['config']->get('database');
        $capsule = new Illuminate\Database\Capsule\Manager();
        $capsule->addConnection($configuration['connections'][$configuration['default']]);
        $connection = $capsule->getConnection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') throw new RuntimeException('Unsupported connection');
        $connection->disableQueryLog();
        $stage = 'read_only_transaction';
        // Enforced by MySQL/MariaDB, including against accidental writes added to the collector.
        $pdo = $connection->getPdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $connection->beginTransaction();
        $stage = 'schema';
        $schema = [];
        foreach ($connection->select('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME', ['BASE TABLE']) as $row) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $row->table_name)) throw new RuntimeException('Unsupported table identifier');
            $schema[$row->table_name] = ['engine' => $row->engine, 'columns' => [], 'references' => []];
        }
        foreach ($connection->select('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            if (isset($schema[$row->table_name])) $schema[$row->table_name]['columns'][$row->column_name] = $row->column_type.($row->is_nullable === 'YES' ? ' nullable' : '').($row->extra ? ' '.$row->extra : '');
        }
        foreach ($connection->select('SELECT k.TABLE_NAME AS table_name, k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS target_table, k.REFERENCED_COLUMN_NAME AS target_column, r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.COLUMN_NAME') as $row) {
            if (isset($schema[$row->table_name])) $schema[$row->table_name]['references'][] = ['column' => $row->column_name, 'table' => $row->target_table, 'target_column' => $row->target_column, 'delete_rule' => $row->delete_rule];
        }
        foreach ($connection->select('SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $row) {
            if (isset($schema[$row->table_name])) $schema[$row->table_name]['unique_indexes'][$row->index_name][] = $row->column_name;
        }
        $stage = 'counts';
        $report = (new ActualLaunchResetInspection($connection, $schema))->collect();
        $report['inspected_at_cairo'] = (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format(DateTimeInterface::ATOM);
        $report['database_version'] = $connection->selectOne('SELECT VERSION() AS version')->version;
        $report['live_source_hashes'] = [];
        foreach (['app/Services/Dashboard/BranchPayroll.php', 'app/Services/Dashboard/BranchShiftClosing.php',
            'app/Services/Dashboard/BranchInventory.php', 'app/Console/Kernel.php'] as $path) {
            $report['live_source_hashes'][$path] = is_file($root.'/'.$path) ? hash_file('sha256', $root.'/'.$path) : 'MISSING';
        }
        $connection->rollBack();
        echo "ACTUAL_LAUNCH_RESET_INSPECTION_BEGIN\n";
        foreach ($report as $key => $value) {
            if ($key === 'schema') {
                foreach ($value as $table => $meta) echo 'schema.'.$table.'='.json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            } else echo $key.'='.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        echo "ACTUAL_LAUNCH_RESET_INSPECTION_END\nNO_DATA_CHANGED\n";
        return 0;
    } catch (Throwable $error) {
        if ($connection && $connection->transactionLevel() > 0) $connection->rollBack();
        // PDO messages can disclose connection details; expose only the stage and error class.
        fwrite(STDERR, 'INSPECTION_FAILED stage='.$stage.' class='.get_class($error)."\nNO_RESET_EXECUTED\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(actualLaunchResetInspectionMain($argv));
