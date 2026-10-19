<?php
declare(strict_types=1);

/** Owner-authorized one-time launch reset. No model events, DDL, refunds or identity renumbering. */
final class LaunchReset
{
    public const FROM = '2026-10-01';
    public const THROUGH = '2026-10-19';
    private PDO $pdo;
    private array $schema;
    public array $deletes = [];
    public array $updates = [];
    public array $attendance = [];
    public array $inserted = [];

    public function __construct(PDO $pdo, array $schema) { $this->pdo = $pdo; $this->schema = $schema; }
    public static function name(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('UNSAFE_IDENTIFIER');
        return '`'.$name.'`';
    }
    public function query(string $sql, array $args = []): array
    {
        $statement = $this->pdo->prepare($sql); $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function has(string $table): bool { return isset($this->schema[$table]); }
    private function ids(string $table, string $where = '1=1', array $args = []): array
    {
        if (!$this->has($table)) return [];
        return array_column($this->query('SELECT id FROM '.self::name($table).' WHERE '.$where, $args), 'id');
    }
    private function branchIds(string $table): array { return $this->ids($table, "branch LIKE 'f:%'"); }
    private function children(string $table, string $column, array $ids, string $extra = '1=1', array $args = []): array
    {
        if (!$ids || !$this->has($table)) return [];
        $result = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $result = array_merge($result, $this->ids($table, self::name($column).' IN ('.implode(',', array_fill(0, count($chunk), '?')).') AND '.$extra, array_merge($chunk, $args)));
        }
        return array_values(array_unique($result));
    }
    private function remove(string $table, array $ids): void
    {
        if ($this->has($table)) $this->deletes[$table] = array_values(array_unique($ids));
    }
    private function change(string $table, array $ids, array $values, bool $revision = false): void
    {
        foreach (array_chunk($ids, 400) as $chunk) {
            if (!$chunk) continue;
            $rows = $this->query('SELECT * FROM '.self::name($table).' WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')', $chunk);
            foreach ($rows as $row) {
                $changes = $values;
                if ($revision) $changes['revision'] = (string) ((int) $row['revision'] + 1);
                $this->updates[$table][(string) $row['id']] = ['before' => $row, 'changes' => $changes];
            }
        }
    }

    public function plan(string $run): array
    {
        $stamp = gmdate('Y-m-d H:i:s');
        $restaurants = $this->ids('resturants');
        if (!$restaurants) throw new RuntimeException('NO_RESTAURANT_SCOPE');
        $app = $this->children('orders', 'resturant_id', $restaurants, "type IN ('current','schedule')");
        $pos = $this->branchIds('takeaway_orders');
        $tickets = $this->branchIds('pos_service_tickets');
        $batches = $this->branchIds('phone_delivery_batches');
        $purchases = $this->branchIds('crm_purchases');
        $deliveries = $this->children('crm_whatsapp_deliveries', 'purchase_id', $purchases);
        $devices = $this->branchIds('desktop_pos_devices');

        if ($this->children('go_order_payments', 'order_id', $app)) throw new RuntimeException('GATEWAY_PAYMENT_REQUIRES_REVIEW');
        foreach (['desktop_dashboard_devices', 'desktop_dashboard_commands', 'desktop_dashboard_remote_attempts'] as $table) {
            if ($this->has($table) && $this->query('SELECT COUNT(*) AS n FROM '.self::name($table))[0]['n'] !== '0') throw new RuntimeException('NEW_DESKTOP_DASHBOARD_STATE_REQUIRES_REVIEW');
        }
        if ($this->has('jobs') && (int) $this->query('SELECT COUNT(*) AS n FROM jobs')[0]['n'] > 0) throw new RuntimeException('QUEUED_JOBS_REQUIRE_DRAINING');

        // Child records first; keep foreign-key enforcement enabled throughout.
        $this->remove('crm_customer_feedback_replies', $this->children('crm_customer_feedback_replies', 'delivery_id', $deliveries));
        $this->remove('crm_whatsapp_deliveries', $deliveries);
        $this->remove('crm_purchases', $purchases);
        $this->remove('desktop_pos_snapshots', $this->children('desktop_pos_snapshots', 'device_id', $devices));
        foreach (['desktop_pos_operations', 'desktop_pos_orders', 'branch_shift_print_jobs', 'branch_shift_sources',
            'branch_shift_closings', 'pos_branch_print_jobs', 'pos_service_commands', 'pos_service_kitchen_tickets',
            'phone_delivery_dispatches'] as $table) $this->remove($table, $this->branchIds($table));
        $this->remove('phone_delivery_batch_items', $this->children('phone_delivery_batch_items', 'batch_id', $batches));
        $this->remove('phone_delivery_batches', $batches);
        $this->remove('web_storefront_orders', $this->children('web_storefront_orders', 'ticket_id', $tickets));
        $this->remove('pos_service_tickets', $tickets);
        $this->remove('takeaway_till_entries', $this->branchIds('takeaway_till_entries'));
        $this->remove('takeaway_order_items', $this->children('takeaway_order_items', 'order_id', $pos));
        $this->remove('takeaway_orders', $pos);
        foreach (['branch_expense_commands', 'expense_inventory_receipts', 'branch_expenses', 'branch_recipe_sales',
            'branch_inventory_movements', 'branch_stock_movements', 'branch_employee_entries', 'branch_payrolls', 'branch_order_sequences'] as $table) $this->remove($table, $this->branchIds($table));
        foreach (['carts', 'payments', 'commissions', 'delegate_notifications', 'reviews', 'shippings', 'order_live_activities'] as $table) $this->remove($table, $this->children($table, 'order_id', $app));
        $this->remove('order_board_clocks', $this->children('order_board_clocks', 'order_id', $app, "source='legacy'"));
        $this->remove('notifications', $this->children('notifications', 'notifiable_id', $app, 'notifiable_type=?', ['App\\Models\\Order']));
        $this->remove('orders', $app);

        $kinds = ['app.order.print', 'employee.attendance', 'employee.automatic_absence', 'employee.entry', 'employee.void',
            'employee.notes', 'inventory.receive', 'stock.receive', 'phone.batch', 'phone.dispatch', 'payroll.close', 'payroll.pay'];
        $this->remove('branch_operation_commands', $this->ids('branch_operation_commands', "branch LIKE 'f:%' AND kind IN (".implode(',', array_fill(0, count($kinds), '?')).')', $kinds));

        // Preserve wallet amounts, counterparties and user balances. Archive the old association in the backup.
        $this->change('wallets', $this->children('wallets', 'order_id', $app), ['order_id' => null]);
        foreach (['whatsapp_order_drafts', 'meta_order_drafts'] as $table) {
            $this->change($table, $this->children($table, 'ticket_id', $tickets),
                ['ticket_id' => null, 'status' => 'CANCELLED', 'reason' => null, 'sealed_payload' => null, 'updated_at' => $stamp], true);
        }
        $this->change('pos_service_tables', $this->ids('pos_service_tables', "branch LIKE 'f:%' AND active_ticket_id IS NOT NULL"), ['active_ticket_id' => null, 'updated_at' => $stamp], true);
        foreach (['takeaway_tills' => 'balance_cents', 'branch_inventory' => 'quantity_units', 'branch_stock' => 'quantity_units'] as $table => $column) {
            $this->change($table, $this->branchIds($table), [$column => '0', 'updated_at' => $stamp], true);
        }
        $this->change('desktop_pos_devices', $devices, ['enabled' => '0', 'token_hash' => null, 'pair_hash' => null, 'pair_expires_at' => null, 'updated_at' => $stamp]);
        $minimums = $this->branchIds('branch_stock_minimums');
        $this->change('branch_stock_minimum_alerts', $this->children('branch_stock_minimum_alerts', 'minimum_id', $minimums, 'sent_at IS NULL AND cancelled_at IS NULL'),
            ['cancelled_at' => $stamp, 'lease_token' => null, 'lease_until' => null, 'updated_at' => $stamp]);

        $owner = $this->query('SELECT id,account_type,owner_resturant_id FROM users WHERE id=1');
        if (count($owner) !== 1 || $owner[0]['account_type'] !== 'admin' || $owner[0]['owner_resturant_id']) throw new RuntimeException('PRIMARY_OWNER_REQUIRED');
        $employees = $this->query("SELECT * FROM branch_employees WHERE branch LIKE 'f:%' ORDER BY id");
        if (count($employees) !== 21) throw new RuntimeException('EMPLOYEE_SCOPE_CHANGED');
        foreach ($employees as $employee) {
            if ($employee['hired_on'] > self::FROM || ($employee['left_on'] && $employee['left_on'] < self::THROUGH)) throw new RuntimeException('EMPLOYMENT_BOUNDARY_CHANGED');
            for ($day = new DateTimeImmutable(self::FROM); $day->format('Y-m-d') <= self::THROUGH; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $data = self::attendanceRow($employee, $date, $this->query('SELECT * FROM branch_attendance_rules WHERE branch=?', [$employee['branch']]), $run, $stamp);
                $old = $this->query('SELECT * FROM branch_employee_days WHERE employee_id=? AND day=?', [$employee['id'], $date]);
                if ($old) {
                    if (count($old) !== 1 || $old[0]['branch'] !== $employee['branch']) throw new RuntimeException('ATTENDANCE_IDENTITY_CONFLICT');
                    $this->change('branch_employee_days', [$old[0]['id']], $data, true);
                } else $this->attendance[] = $data + ['employee_id' => $employee['id'], 'day' => $date, 'revision' => '1', 'created_at' => $stamp];
            }
        }
        return ['employees' => count($employees), 'attendance_days' => count($this->attendance) + count($this->updates['branch_employee_days'] ?? []),
            'delete_counts' => array_map('count', $this->deletes), 'update_counts' => array_map('count', $this->updates),
            'wallet_amounts_and_user_balances' => 'preserved', 'bonus_permission' => 'primary_owner_only'];
    }

    public static function attendanceRow(array $employee, string $day, array $rules, string $run, string $stamp): array
    {
        $part = ($employee['schedule_type'] ?? 'branch') === 'part_time';
        $shift = $part ? ($employee['attendance_shift'] ?? 'morning') : (in_array($employee['shift'], ['evening', 'مسائي', 'مساء'], true) ? 'evening' : 'morning');
        $rule = null; foreach ($rules as $candidate) if ($candidate['shift'] === $shift) $rule = $candidate;
        $start = $part ? $employee['attendance_start'] : ($rule['starts_at'] ?? null);
        $end = $part ? $employee['attendance_end'] : ($rule['ends_at'] ?? null);
        $in = null; $out = null; $status = 'present';
        $snapshot = ['administrative_full_attendance' => true, 'launch_reset' => $run, 'actual_punch' => false];
        if ($start && $end) {
            $at = function (string $time) use ($day): DateTimeImmutable {
                if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $time)) throw new RuntimeException('INVALID_SCHEDULE');
                $date = new DateTimeImmutable($day.' '.substr($time, 0, 5).':00', new DateTimeZone('Africa/Cairo'));
                return (int) $date->format('H') < 6 ? $date->modify('+1 day') : $date;
            };
            $a = $at($start); $b = $at($end); if ($b <= $a) $b = $b->modify('+1 day');
            $in = $a->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $out = $b->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $status = $part ? 'part_time' : $shift;
            $snapshot += ['shift' => $status, 'rule_revision' => (int) ($rule['revision'] ?? 0), 'scheduled_start' => $in, 'scheduled_end' => $out,
                'starts_at' => substr($start, 0, 5), 'ends_at' => substr($end, 0, 5), 'late_half_hour_cents' => (int) ($rule['late_half_hour_cents'] ?? 0),
                'early_half_hour_cents' => (int) ($rule['early_half_hour_cents'] ?? 0), 'absence_cents' => (int) ($rule['absence_cents'] ?? 0)];
        }
        return ['branch' => $employee['branch'], 'status' => $status, 'check_in' => $start ? substr($start, 0, 8) : null,
            'check_out' => $end ? substr($end, 0, 8) : null, 'checked_in_at' => $in, 'checked_out_at' => $out,
            'attendance_rule_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'notes' => 'اعتماد حضور كامل من المالك لبدء التشغيل الفعلي؛ أوقات الجدول اعتماد إداري وليست بصمة فعلية.',
            'actor_id' => '1', 'updated_at' => $stamp];
    }

    public function allowed(string $table): array
    {
        return array_values(array_unique(array_merge($this->deletes[$table] ?? [], array_keys($this->updates[$table] ?? []), $this->inserted[$table] ?? [])));
    }
    public function fingerprint(string $table, bool $excludeAllowed): string
    {
        $excluded = $excludeAllowed ? array_fill_keys($this->allowed($table), true) : [];
        $hashes = [];
        $statement = $this->pdo->query('SELECT * FROM '.self::name($table));
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['id'], $excluded[$row['id']])) continue;
            $hashes[] = self::rowHash($row);
        }
        sort($hashes, SORT_STRING);
        return hash('sha256', implode('', $hashes));
    }
    private static function rowHash(array $row): string
    {
        ksort($row); foreach ($row as &$value) if ($value !== null) $value = base64_encode((string) $value);
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
    public function apply(): void
    {
        // Break logical cycles before deleting tickets/orders; no financial reversal is performed.
        foreach ($this->updates as $table => $rows) foreach ($rows as $id => $change) {
            $fields = array_keys($change['changes']);
            $sql = 'UPDATE '.self::name($table).' SET '.implode(',', array_map(fn($field) => self::name($field).'=?', $fields)).' WHERE id=?';
            $statement = $this->pdo->prepare($sql); $statement->execute(array_merge(array_values($change['changes']), [(string) $id]));
        }
        foreach ($this->deletes as $table => $ids) foreach (array_chunk($ids, 400) as $chunk) {
            if (!$chunk) continue;
            $statement = $this->pdo->prepare('DELETE FROM '.self::name($table).' WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $statement->execute($chunk);
        }
        foreach ($this->attendance as $row) {
            $fields = array_keys($row);
            $statement = $this->pdo->prepare('INSERT INTO branch_employee_days ('.implode(',', array_map([self::class, 'name'], $fields)).') VALUES ('.implode(',', array_fill(0, count($fields), '?')).')');
            $statement->execute(array_values($row));
            $this->inserted['branch_employee_days'][] = $this->pdo->lastInsertId();
        }
    }
    public function verify(): array
    {
        foreach ($this->deletes as $table => $ids) if ($this->children($table, 'id', $ids)) throw new RuntimeException('DELETE_VERIFICATION_FAILED_'.$table);
        foreach ($this->updates as $table => $rows) foreach ($rows as $id => $change) {
            $actual = $this->query('SELECT * FROM '.self::name($table).' WHERE id=?', [(string) $id]);
            $expected = array_replace($change['before'], $change['changes']);
            if (count($actual) !== 1 || self::rowHash($expected) !== self::rowHash($actual[0])) throw new RuntimeException('UPDATE_VERIFICATION_FAILED_'.$table);
        }
        foreach (['takeaway_tills' => 'balance_cents', 'branch_inventory' => 'quantity_units', 'branch_stock' => 'quantity_units'] as $table => $column) {
            if ($this->ids($table, "branch LIKE 'f:%' AND ".self::name($column).'<>0')) throw new RuntimeException('NONZERO_BALANCE_'.$table);
        }
        $days = $this->query("SELECT employee_id,COUNT(*) AS n FROM branch_employee_days WHERE branch LIKE 'f:%' AND day>=? AND day<=? AND status IN ('present','morning','evening','part_time') GROUP BY employee_id", [self::FROM, self::THROUGH]);
        if (count($days) !== 21 || array_filter($days, fn($day) => (int) $day['n'] !== 19)) throw new RuntimeException('ATTENDANCE_VERIFICATION_FAILED');
        if ($this->branchIds('branch_employee_entries') || $this->branchIds('takeaway_orders') || $this->branchIds('branch_expenses')) throw new RuntimeException('FINANCIAL_ROWS_REMAIN');
        return ['employees' => 21, 'full_attendance_days' => 399, 'advances_deductions_bonuses' => 0, 'pos_invoices' => 0, 'expenses' => 0, 'inventory_and_drawer_balances' => 0];
    }
}

function launchWriteFile(string $path, string $contents): void
{
    $handle = fopen($path, 'xb'); if (!$handle) throw new RuntimeException('BACKUP_FILE_CREATE_FAILED');
    try { if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) throw new RuntimeException('BACKUP_FILE_WRITE_FAILED'); if (function_exists('fsync')) fsync($handle); }
    finally { fclose($handle); }
    chmod($path, 0600);
}

/** A complete table/data SQL backup on the same locked connection, verified before any DML. */
function launchBackup(PDO $pdo, array $schema, string $directory): array
{
    $path = $directory.'/database-before.sql.gz';
    $exclusive = fopen($path, 'xb'); if (!$exclusive) throw new RuntimeException('BACKUP_ALREADY_EXISTS');
    fclose($exclusive);
    $out = gzopen($path, 'wb6');
    if (!$out) throw new RuntimeException('BACKUP_OPEN_FAILED');
    chmod($path, 0600); $hash = hash_init('sha256'); $counts = []; $bytes = 0;
    $write = function (string $text) use ($out, $hash, &$bytes): void {
        if (gzwrite($out, $text) !== strlen($text)) throw new RuntimeException('BACKUP_WRITE_FAILED');
        hash_update($hash, $text); $bytes += strlen($text);
    };
    try {
        $write("-- Restore only into an operator-selected recovery database. Contains private application data.\nSET NAMES utf8mb4;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($schema as $table => $meta) {
            $name = LaunchReset::name($table); $create = $pdo->query('SHOW CREATE TABLE '.$name)->fetch(PDO::FETCH_NUM)[1];
            $write('DROP TABLE IF EXISTS '.$name.";\n".$create.";\n");
            $columns = array_keys(array_filter($meta['columns'], fn($type) => !preg_match('/\b(?:VIRTUAL|STORED) GENERATED\b/i', $type)));
            $statement = $pdo->query('SELECT '.implode(',', array_map([LaunchReset::class, 'name'], $columns)).' FROM '.$name);
            $counts[$table] = 0;
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                $values = array_map(fn($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), $row);
                $write('INSERT INTO '.$name.' ('.implode(',', array_map([LaunchReset::class, 'name'], $columns)).') VALUES ('.implode(',', $values).");\n");
                $counts[$table]++;
            }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n-- COMPLETE_BACKUP\n");
    } finally { gzclose($out); }
    $expected = hash_final($hash); $verify = hash_init('sha256'); $readBytes = 0; $in = gzopen($path, 'rb');
    if (!$in) throw new RuntimeException('BACKUP_REOPEN_FAILED');
    while (!gzeof($in)) { $buffer = gzread($in, 1048576); if ($buffer === false) throw new RuntimeException('BACKUP_READ_FAILED'); hash_update($verify, $buffer); $readBytes += strlen($buffer); }
    gzclose($in);
    if ($bytes !== $readBytes || !hash_equals($expected, hash_final($verify))) throw new RuntimeException('BACKUP_DIGEST_FAILED');
    $manifest = ['file' => basename($path), 'uncompressed_bytes' => $bytes, 'uncompressed_sha256' => $expected,
        'gzip_sha256' => hash_file('sha256', $path), 'rows' => $counts];
    launchWriteFile($directory.'/backup-verified.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    return $manifest;
}

function launchSchema(PDO $pdo): array
{
    $schema = [];
    foreach ($pdo->query("SELECT TABLE_NAME AS t,ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $row) {
        LaunchReset::name($row['t']); $schema[$row['t']] = ['engine' => $row['e'], 'columns' => [], 'references' => []];
    }
    foreach ($pdo->query('SELECT TABLE_NAME AS t,COLUMN_NAME AS c,COLUMN_TYPE AS typ,IS_NULLABLE AS nul,EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION') as $row) {
        if (isset($schema[$row['t']])) $schema[$row['t']]['columns'][$row['c']] = $row['typ'].($row['nul'] === 'YES' ? ' nullable' : '').($row['extra'] ? ' '.$row['extra'] : '');
    }
    foreach ($pdo->query('SELECT k.TABLE_NAME AS t,k.COLUMN_NAME AS c,k.REFERENCED_TABLE_NAME AS parent,k.REFERENCED_COLUMN_NAME AS col,r.DELETE_RULE AS rule FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.COLUMN_NAME') as $row) {
        if (isset($schema[$row['t']])) $schema[$row['t']]['references'][] = ['column' => $row['c'], 'table' => $row['parent'], 'target_column' => $row['col'], 'delete_rule' => $row['rule']];
    }
    foreach ($pdo->query('SELECT TABLE_NAME AS t,INDEX_NAME AS i,COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND NON_UNIQUE=0 ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX') as $row) {
        if (isset($schema[$row['t']])) $schema[$row['t']]['unique_indexes'][$row['i']][] = $row['c'];
    }
    return $schema;
}

function launchResetMain(array $args): int
{
    $pdo = null; $locked = false; $committed = false; $before = []; $stage = 'arguments'; $directory = null;
    try {
        if (count($args) !== 5 || $args[1] !== '--apply' || $args[2] !== '/home/fasakha/public_html'
            || !preg_match('#^/home/fasakha/actual-launch-reset-backups/[0-9TZ-]+-[a-zA-Z0-9]+$#D', $args[3])) throw new RuntimeException('INVALID_ARGUMENTS');
        [, , $root, $directory, $run] = $args;
        if (realpath($root) !== $root || is_link($root) || realpath($directory) !== $directory || is_link($directory) || (fileperms($directory) & 0077)) throw new RuntimeException('UNSAFE_PATH');
        if ((new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d') !== LaunchReset::THROUGH) throw new RuntimeException('RESET_DATE_EXPIRED');
        if (!is_file($root.'/storage/framework/down')) throw new RuntimeException('MAINTENANCE_REQUIRED');
        if (is_file(dirname($directory).'/committed.json')) throw new RuntimeException('RESET_ALREADY_COMMITTED');
        $stage = 'configuration';
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
        (new Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
        $config = $app['config']->get('database'); $capsule = new Illuminate\Database\Capsule\Manager();
        $capsule->addConnection($config['connections'][$config['default']]); $connection = $capsule->getConnection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') throw new RuntimeException('UNSUPPORTED_DATABASE');
        $pdo = $connection->getPdo(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->exec("SET NAMES utf8mb4"); $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
        $pdo->exec("SET SESSION lock_wait_timeout=15");
        $stage = 'schema_review'; $schema = launchSchema($pdo);
        $expected = json_decode(file_get_contents(__DIR__.'/inspected_schema.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($expected as $table => $meta) {
            if (!isset($schema[$table]) || $schema[$table] != $meta) throw new RuntimeException('INSPECTED_SCHEMA_CHANGED_'.$table);
        }
        foreach ($schema as $table => $meta) {
            if (isset($expected[$table])) continue;
            $columns = array_keys($meta['columns']);
            if (array_intersect(['branch', 'order_id', 'paid_order_id', 'ticket_id', 'purchase_id', 'expense_id', 'closing_id', 'batch_id', 'stock_id'], $columns)) throw new RuntimeException('UNREVIEWED_RELATED_TABLE_'.$table);
        }
        if ($pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn() !== '0') throw new RuntimeException('TRIGGERS_REQUIRE_REVIEW');
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn(); $connectionId = $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $idle = function () use ($pdo, $database, $connectionId): bool {
            foreach ($pdo->query('SHOW PROCESSLIST') as $process) if ((string) $process['Id'] !== (string) $connectionId && $process['db'] === $database) return false;
            return true;
        };
        $stage = 'draining_connections';
        for ($attempt = 0; !$idle() && $attempt < 30; $attempt++) usleep(1000000);
        if (!$idle()) throw new RuntimeException('OTHER_DATABASE_CONNECTIONS_ACTIVE');
        $stage = 'locking';
        // MySQL's documented InnoDB table-lock pattern: autocommit=0, LOCK TABLES, DML, COMMIT, UNLOCK.
        // START TRANSACTION is deliberately not issued: it would release the explicit table locks.
        $pdo->exec('SET autocommit=0');
        $pdo->exec('LOCK TABLES '.implode(',', array_map(fn($table) => LaunchReset::name($table).' WRITE', array_keys($schema)))); $locked = true;
        if (!$idle()) throw new RuntimeException('CONCURRENT_WRITER_DETECTED');
        $stage = 'planning'; $reset = new LaunchReset($pdo, $schema); $plan = $reset->plan($run);
        foreach (array_unique(array_merge(array_keys($reset->deletes), array_keys($reset->updates), ['branch_employee_days'])) as $table) {
            if (strtolower($schema[$table]['engine']) !== 'innodb') throw new RuntimeException('NONTRANSACTIONAL_TABLE_'.$table);
        }
        launchWriteFile($directory.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        echo 'RESET_PLAN='.json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $stage = 'backup'; $backup = launchBackup($pdo, $schema, $directory);
        echo 'BACKUP_VERIFIED tables='.count($backup['rows']).' rows='.array_sum($backup['rows'])."\n";
        $stage = 'preservation_baseline'; $preserved = [];
        foreach ($schema as $table => $_) { $before[$table] = $reset->fingerprint($table, false); $preserved[$table] = $reset->fingerprint($table, true); }
        if (!$idle()) throw new RuntimeException('CONCURRENT_WRITER_DETECTED');
        $stage = 'reset'; $reset->apply();
        $stage = 'verification'; $verification = $reset->verify();
        foreach ($schema as $table => $_) if (!hash_equals($preserved[$table], $reset->fingerprint($table, true))) throw new RuntimeException('PRESERVED_DATA_CHANGED_'.$table);
        if (!$idle()) throw new RuntimeException('CONCURRENT_WRITER_DETECTED');
        launchWriteFile($directory.'/commit-intent.json', json_encode(['run' => $run, 'verification' => $verification, 'backup_sha256' => $backup['gzip_sha256']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $stage = 'commit'; $pdo->exec('COMMIT'); $committed = true;
        launchWriteFile(dirname($directory).'/committed.json', json_encode(['run' => $run, 'backup_directory' => $directory, 'committed_at' => gmdate('c'), 'verification' => $verification], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $pdo->exec('UNLOCK TABLES'); $locked = false; $pdo->exec('SET autocommit=1');
        launchWriteFile($directory.'/safe-to-reopen', "COMMITTED_AND_VERIFIED\n");
        echo 'RESET_VERIFIED='.json_encode($verification, JSON_THROW_ON_ERROR)."\n";
        echo "ACTUAL_LAUNCH_RESET_COMMITTED\nBACKUP_DIRECTORY=".$directory."\nDESKTOP_POS_REPAIR_REQUIRED=reenroll_device_after_reset\n";
        return 0;
    } catch (Throwable $error) {
        $rollbackVerified = !$committed && !$locked;
        if ($pdo && $locked && !$committed) {
            try {
                $pdo->exec('ROLLBACK'); $rollbackVerified = true;
                if ($before) foreach ($before as $table => $digest) if (!hash_equals($digest, $reset->fingerprint($table, false))) $rollbackVerified = false;
            } catch (Throwable $ignored) { $rollbackVerified = false; }
        }
        if ($pdo && $locked) { try { $pdo->exec('UNLOCK TABLES'); } catch (Throwable $ignored) {} }
        if ($rollbackVerified && $directory && is_dir($directory) && !is_file($directory.'/safe-to-reopen')) {
            try { launchWriteFile($directory.'/safe-to-reopen', "NO_COMMIT_ROLLBACK_VERIFIED\n"); } catch (Throwable $ignored) {}
        }
        $code = $error instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_a-z]+$/D', $error->getMessage()) ? $error->getMessage() : get_class($error);
        fwrite(STDERR, 'RESET_FAILED stage='.$stage.' code='.$code.' committed='.($committed ? 'yes' : 'no').' rollback_verified='.($rollbackVerified ? 'yes' : 'no')."\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(launchResetMain($argv));
