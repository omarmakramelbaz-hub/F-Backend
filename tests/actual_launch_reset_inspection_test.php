<?php
declare(strict_types=1);

// Run with an isolated Illuminate database runtime; no production bootstrap or credentials.
$autoload = $argv[1] ?? '';
if (!is_file($autoload)) throw new RuntimeException('Pass the isolated vendor/autoload.php path');
require $autoload;
require __DIR__.'/../deployment/inspect_actual_launch_reset.php';

use Illuminate\Database\Capsule\Manager;

function checkLaunchInspection(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$capsule = new Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$db = $capsule->getConnection();
$definitions = [
    'resturants' => 'id INTEGER PRIMARY KEY',
    'orders' => 'id INTEGER PRIMARY KEY, resturant_id INTEGER, type TEXT, status TEXT, payment_type TEXT',
    'payments' => 'id INTEGER PRIMARY KEY, order_id INTEGER, status TEXT',
    'carts' => 'id INTEGER PRIMARY KEY, order_id INTEGER',
    'takeaway_orders' => 'id INTEGER PRIMARY KEY, branch TEXT, channel TEXT, payment_method TEXT',
    'takeaway_order_items' => 'id INTEGER PRIMARY KEY, order_id INTEGER',
    'takeaway_tills' => 'id INTEGER PRIMARY KEY, branch TEXT, balance_cents INTEGER',
    'pos_service_tickets' => 'id INTEGER PRIMARY KEY, branch TEXT, channel TEXT, status TEXT',
    'phone_delivery_batches' => 'id INTEGER PRIMARY KEY, branch TEXT',
    'phone_delivery_batch_items' => 'id INTEGER PRIMARY KEY, batch_id INTEGER',
    'branch_employees' => 'id INTEGER PRIMARY KEY, branch TEXT, hired_on TEXT, left_on TEXT, active INTEGER, name TEXT',
    'branch_employee_entries' => 'id INTEGER PRIMARY KEY, branch TEXT, employee_id INTEGER, day TEXT, kind TEXT, amount_cents INTEGER, voided_at TEXT',
    'branch_employee_days' => 'id INTEGER PRIMARY KEY, branch TEXT, employee_id INTEGER, day TEXT, status TEXT, checked_in_at TEXT, checked_out_at TEXT, attendance_rule_snapshot TEXT',
    'branch_payrolls' => 'id INTEGER PRIMARY KEY, branch TEXT, employee_id INTEGER, month TEXT, status TEXT, snapshot TEXT',
    'branch_inventory' => 'id INTEGER PRIMARY KEY, branch TEXT, quantity_units INTEGER',
    'branch_stock' => 'id INTEGER PRIMARY KEY, branch TEXT, quantity_units INTEGER',
    'branch_stock_recipes' => 'id INTEGER PRIMARY KEY, branch TEXT, variants TEXT',
    'order_board_clocks' => 'id INTEGER PRIMARY KEY, source TEXT, order_id INTEGER',
    'notifications' => 'id INTEGER PRIMARY KEY, notifiable_type TEXT, notifiable_id INTEGER',
    'crm_purchases' => 'id INTEGER PRIMARY KEY, branch TEXT, source_type TEXT, feedback_status TEXT',
    'crm_whatsapp_deliveries' => 'id INTEGER PRIMARY KEY, purchase_id INTEGER',
    'crm_customer_feedback_replies' => 'id INTEGER PRIMARY KEY, delivery_id INTEGER, body TEXT',
    'crm_customers' => 'id INTEGER PRIMARY KEY, phone TEXT, address TEXT, service_consent INTEGER',
    'desktop_pos_devices' => 'id TEXT PRIMARY KEY, branch TEXT, enabled INTEGER',
    'desktop_pos_snapshots' => 'id TEXT PRIMARY KEY, device_id TEXT, payload TEXT',
    'desktop_dashboard_devices' => 'id TEXT PRIMARY KEY, enabled INTEGER, branches TEXT, token_hash TEXT',
    'desktop_dashboard_commands' => 'id INTEGER PRIMARY KEY, status TEXT, command_cipher TEXT',
    'live_only_ledger' => 'id INTEGER PRIMARY KEY, branch TEXT, order_id INTEGER',
    'live_only_document_links' => 'id INTEGER PRIMARY KEY, unusual_parent INTEGER',
    'live_only_document_children' => 'id INTEGER PRIMARY KEY, unusual_link INTEGER',
];
$schema = [];
foreach ($definitions as $table => $definition) {
    $db->statement('CREATE TABLE '.$table.' ('.$definition.')');
    $columns = [];
    foreach ($db->select('PRAGMA table_info('.$table.')') as $column) $columns[$column->name] = $column->type;
    $schema[$table] = ['engine' => 'SQLite fixture', 'columns' => $columns, 'references' => []];
}
$schema['live_only_document_links']['references'][] = ['column' => 'unusual_parent', 'table' => 'orders', 'target_column' => 'id'];
$schema['live_only_document_children']['references'][] = ['column' => 'unusual_link', 'table' => 'live_only_document_links', 'target_column' => 'id'];
$rows = [
    'resturants' => [['id' => 1]],
    'orders' => [
        ['id' => 1, 'resturant_id' => 1, 'type' => 'current', 'status' => 'completed', 'payment_type' => 'cash'],
        ['id' => 2, 'resturant_id' => 1, 'type' => 'schedule', 'status' => 'pending', 'payment_type' => 'visa'],
        ['id' => 3, 'resturant_id' => null, 'type' => 'current', 'status' => 'pending', 'payment_type' => 'cash'],
        ['id' => 4, 'resturant_id' => 1, 'type' => 'wallet', 'status' => 'completed', 'payment_type' => 'wallet'],
        ['id' => 5, 'resturant_id' => 999, 'type' => 'current', 'status' => 'completed', 'payment_type' => 'cash'],
    ],
    'payments' => [['id' => 1, 'order_id' => 1, 'status' => 'paid'], ['id' => 2, 'order_id' => 4, 'status' => 'paid']],
    'carts' => [['id' => 1, 'order_id' => 1]],
    'takeaway_orders' => [['id' => 10, 'branch' => 'f:1', 'channel' => 'phone', 'payment_method' => 'cash'], ['id' => 11, 'branch' => 'gs:1', 'channel' => 'dining', 'payment_method' => 'cash']],
    'takeaway_order_items' => [['id' => 1, 'order_id' => 10], ['id' => 2, 'order_id' => 11]],
    'takeaway_tills' => [['id' => 1, 'branch' => 'f:1', 'balance_cents' => 12500], ['id' => 2, 'branch' => 'gs:1', 'balance_cents' => 55500]],
    'branch_employees' => [
        ['id' => 1, 'branch' => 'f:1', 'hired_on' => '2026-09-01', 'left_on' => null, 'active' => 1, 'name' => 'PRIVATE_EMPLOYEE'],
        ['id' => 2, 'branch' => 'f:1', 'hired_on' => '2026-10-10', 'left_on' => '2026-10-12', 'active' => 0, 'name' => 'PRIVATE_LEFT_EMPLOYEE'],
        ['id' => 3, 'branch' => 'gs:1', 'hired_on' => '2026-09-01', 'left_on' => null, 'active' => 1, 'name' => 'PRIVATE_GO_EMPLOYEE'],
    ],
    'branch_employee_entries' => [
        ['id' => 1, 'branch' => 'f:1', 'employee_id' => 1, 'day' => '2026-09-20', 'kind' => 'advance', 'amount_cents' => 1000, 'voided_at' => null],
        ['id' => 2, 'branch' => 'f:1', 'employee_id' => 1, 'day' => '2026-10-11', 'kind' => 'deduction', 'amount_cents' => 2000, 'voided_at' => null],
        ['id' => 3, 'branch' => 'f:1', 'employee_id' => 1, 'day' => '2026-10-12', 'kind' => 'bonus', 'amount_cents' => 9999, 'voided_at' => '2026-10-13'],
    ],
    'branch_employee_days' => [
        ['id' => 1, 'branch' => 'f:1', 'employee_id' => 1, 'day' => '2026-10-01', 'status' => 'unauthorized_absence'],
        ['id' => 2, 'branch' => 'f:1', 'employee_id' => 1, 'day' => '2026-09-30', 'status' => 'morning'],
        ['id' => 3, 'branch' => 'gs:1', 'employee_id' => 3, 'day' => '2026-10-01', 'status' => 'morning'],
    ],
    'branch_payrolls' => [['id' => 1, 'branch' => 'f:1', 'employee_id' => 1, 'month' => '2026-10', 'status' => 'paid', 'snapshot' => 'PRIVATE_PAYROLL']],
    'branch_inventory' => [['id' => 1, 'branch' => 'f:1', 'quantity_units' => -123], ['id' => 2, 'branch' => 'f:1', 'quantity_units' => 123]],
    'branch_stock' => [['id' => 1, 'branch' => 'f:1', 'quantity_units' => 500]],
    'branch_stock_recipes' => [['id' => 1, 'branch' => 'f:1', 'variants' => 'PRIVATE_RECIPE']],
    'order_board_clocks' => [['id' => 1, 'source' => 'legacy', 'order_id' => 1], ['id' => 2, 'source' => 'store', 'order_id' => 1]],
    'notifications' => [['id' => 1, 'notifiable_type' => 'App\\Models\\Order', 'notifiable_id' => 1], ['id' => 2, 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1]],
    'crm_purchases' => [['id' => 1, 'branch' => 'f:1', 'source_type' => 'pos', 'feedback_status' => 'pending'], ['id' => 2, 'branch' => 'gs:1', 'source_type' => 'store', 'feedback_status' => 'pending']],
    'crm_whatsapp_deliveries' => [['id' => 1, 'purchase_id' => 1], ['id' => 2, 'purchase_id' => 2]],
    'crm_customer_feedback_replies' => [['id' => 1, 'delivery_id' => 1, 'body' => 'PRIVATE_REPLY'], ['id' => 2, 'delivery_id' => 2, 'body' => 'PRIVATE_REPLY_2']],
    'crm_customers' => [['id' => 1, 'phone' => 'PRIVATE_PHONE', 'address' => 'PRIVATE_ADDRESS', 'service_consent' => 1]],
    'desktop_pos_devices' => [['id' => 'dev-f', 'branch' => 'f:1', 'enabled' => 1], ['id' => 'dev-g', 'branch' => 'gs:1', 'enabled' => 1]],
    'desktop_pos_snapshots' => [['id' => 'snap-f', 'device_id' => 'dev-f', 'payload' => 'PRIVATE_CACHE'], ['id' => 'snap-g', 'device_id' => 'dev-g', 'payload' => 'PRIVATE_CACHE']],
    'desktop_dashboard_devices' => [
        ['id' => 'f', 'enabled' => 1, 'branches' => '["f:1"]', 'token_hash' => 'PRIVATE_TOKEN'],
        ['id' => 'm', 'enabled' => 1, 'branches' => '["f:1","gs:1"]', 'token_hash' => 'PRIVATE_TOKEN'],
        ['id' => 'g', 'enabled' => 1, 'branches' => '["gs:1"]', 'token_hash' => 'PRIVATE_TOKEN'],
        ['id' => 'i', 'enabled' => 1, 'branches' => 'invalid-json', 'token_hash' => 'PRIVATE_TOKEN'],
    ],
    'desktop_dashboard_commands' => [['id' => 1, 'status' => 'pending', 'command_cipher' => 'PRIVATE_PAYLOAD']],
    'live_only_ledger' => [['id' => 1, 'branch' => 'f:1', 'order_id' => 1]],
];
foreach ($rows as $table => $records) foreach ($records as $record) $db->table($table)->insert($record);

function snapshotLaunchInspection($db, array $tables): string
{
    $snapshot = [];
    foreach ($tables as $table) $snapshot[$table] = $db->table($table)->orderBy('id')->get()->all();
    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
}
$before = snapshotLaunchInspection($db, array_keys($schema));
$db->statement('PRAGMA query_only = ON');
$db->beginTransaction();
$queries = [];
$db->enableQueryLog();
$report = (new ActualLaunchResetInspection($db, $schema))->collect();
foreach ($db->getQueryLog() as $query) checkLaunchInspection((bool) preg_match('/^select\b/i', $query['query']), 'Collector attempted a non-SELECT statement');
$db->rollBack();
checkLaunchInspection($before === snapshotLaunchInspection($db, array_keys($schema)), 'Database changed');
checkLaunchInspection($report['missing_required'] === [], 'Required fixture unexpectedly missing');
checkLaunchInspection($report['related_rows']['restaurant_app_orders'] === 2, 'App scope included wallet/orphan orders');
checkLaunchInspection($report['related_rows']['unassigned_restaurant_orders'] === 2, 'Unassigned orders not separated');
checkLaunchInspection($report['related_rows']['payments'] === 1, 'Wallet payments leaked into restaurant scope');
checkLaunchInspection($report['related_rows']['takeaway_order_items'] === 1, 'Go order items not separated');
checkLaunchInspection($report['branch_tables']['takeaway_orders'] === ['fasakhansta' => 1, 'other_or_unassigned' => 1], 'Branch counts incorrect');
checkLaunchInspection($report['employment']['employees'] === 2 && $report['employment']['eligible_employee_days'] === 22, 'Employment boundaries incorrect');
checkLaunchInspection($report['employment']['hired_after_october_1'] === 1 && $report['employment']['left_before_october_19'] === 1, 'Employment exceptions hidden');
checkLaunchInspection($report['groups']['october_attendance'] === [['status' => 'unauthorized_absence', 'row_count' => 1]], 'Attendance period or employee scope incorrect');
checkLaunchInspection($report['related_rows']['nonvoided_employee_entry_cents'] === '3000', 'Voided entries counted or pre-October advances excluded');
checkLaunchInspection($report['groups']['branch_payrolls'][0]['status'] === 'paid', 'Paid payroll not surfaced');
checkLaunchInspection($report['groups']['branch_inventory'][0]['nonzero_rows'] === 2, 'Offsetting nonzero stock hidden');
checkLaunchInspection($report['related_rows']['order_board_clocks_legacy'] === 1 && $report['related_rows']['order_notifications'] === 1, 'Polymorphic sources conflated');
checkLaunchInspection($report['related_rows']['crm_customer_feedback_replies'] === 1 && $report['related_rows']['crm_whatsapp_deliveries'] === 1, 'Purchase follow-up scope incorrect');
checkLaunchInspection($report['related_rows']['desktop_pos_snapshots'] === 1, 'Offline snapshots scope incorrect');
checkLaunchInspection($report['desktop_dashboard_enabled_scopes'] === ['fasakhansta_only' => 1, 'mixed' => 1, 'other' => 1, 'invalid' => 1], 'Offline scope exceptions hidden');
checkLaunchInspection(isset($report['schema']['live_only_ledger']), 'Live-only schema omitted');
checkLaunchInspection(isset($report['schema']['live_only_document_children']), 'Unconventional recursive reference omitted');
checkLaunchInspection(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'PRIVATE_'), 'Private row contents leaked');
checkLaunchInspection($report['preserve_counts']['crm_customers'] === 1 && $report['preserve_counts']['branch_stock_recipes'] === 1, 'Preservation counts missing');

$partial = $schema;
unset($partial['branch_payrolls']);
$partialReport = (new ActualLaunchResetInspection($db, $partial))->collect();
checkLaunchInspection(in_array('branch_payrolls', $partialReport['missing_required'], true), 'Missing critical table not reported');
echo "PASS: read-only database inventory, mixed scopes, employment boundaries, closed payroll, offline sync, private data exclusion, unknown references and missing schema\n";
