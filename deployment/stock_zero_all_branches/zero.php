<?php
declare(strict_types=1);

/** One-time physical stock adjustment, retaining receipts and sale replay protection. */
function goZeroBranchStock($db, array $branches, string $backupDirectory): array
{
    $source = 'owner-zero-20261106-v1';
    $requestKey = '56902ed2-5264-4ea5-bb11-573d445ff6ae';
    $reason = 'تصفير أرصدة البضاعة لجميع الفروع بطلب الأونر بتاريخ 6 نوفمبر 2026.';
    if (!$branches || count($branches) !== count(array_unique($branches))) throw new RuntimeException('GO_STOCK_SCOPE_INVALID');
    foreach ($branches as $branch) {
        if (!is_string($branch) || !preg_match('/^f:([1-9][0-9]{0,17})$/D', $branch)) throw new RuntimeException('GO_STOCK_PRIMARY_BRANCHES_ONLY');
    }
    usort($branches, static fn($a, $b) => (int)substr($a, 2) <=> (int)substr($b, 2));
    if (!is_dir($backupDirectory) || is_link($backupDirectory) || !is_writable($backupDirectory)) throw new RuntimeException('GO_STOCK_BACKUP_DIRECTORY_INVALID');
    $requestHash = hash('sha256', json_encode([$source, $branches], JSON_THROW_ON_ERROR));
    return $db->transaction(function () use ($db, $branches, $backupDirectory, $source, $requestKey, $requestHash, $reason) {
        // Receipts, sales, purchase expenses and stock observers all take these locks first.
        foreach ($branches as $branch) {
            if (!$db->table('resturants')->where('id', substr($branch, 2))->lockForUpdate()->first()) throw new RuntimeException('GO_STOCK_BRANCH_MISSING');
        }
        $receipts = $db->table('branch_operation_commands')->where('actor_id', 1)->where('request_key', $requestKey)->orderBy('id')->lockForUpdate()->get();
        if (!$receipts->isEmpty()) {
            $recordedBranches = $receipts->pluck('branch')->all();
            usort($recordedBranches, static fn($a, $b) => (int)substr($a, 2) <=> (int)substr($b, 2));
            if ($recordedBranches !== $branches) throw new RuntimeException('GO_STOCK_RESET_ALREADY_APPLIED_SCOPE_CHANGED');
            foreach ($receipts as $receipt) {
                if ($receipt->kind !== 'stock.zero' || !hash_equals($requestHash, $receipt->request_hash)) throw new RuntimeException('GO_STOCK_RESET_RECEIPT_MISMATCH');
                if ($receipt->result !== $receipts->first()->result) throw new RuntimeException('GO_STOCK_RESET_RECEIPT_MISMATCH');
            }
            $result = json_decode($receipts->first()->result, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($result) || ($result['status'] ?? '') !== 'GO_STOCK_ZERO_APPLIED') throw new RuntimeException('GO_STOCK_RESET_RECEIPT_INVALID');
            // A retry must not erase goods received after the first successful reset.
            $result['status'] = 'GO_STOCK_ZERO_ALREADY_APPLIED';
            return $result;
        }
        $inventory = $db->table('branch_inventory')->whereIn('branch', $branches)->orderBy('id')->lockForUpdate()->get();
        $stock = $db->table('branch_stock')->whereIn('branch', $branches)->orderBy('id')->lockForUpdate()->get();
        $ingredients = $db->table('stock_ingredients')->whereIn('id', $inventory->pluck('ingredient_id')->all())->get()->keyBy('id');
        $products = $db->table('resturant_products')->whereIn('id', $stock->pluck('product_id')->all())->get()->keyBy('id');
        $plan = [];
        $counts = [];
        foreach ($branches as $branch) $counts[$branch] = ['ingredients' => 0, 'products' => 0];
        foreach (['inventory' => $inventory, 'stock' => $stock] as $kind => $rows) {
            foreach ($rows as $row) {
                if (!is_numeric($row->quantity_units) || (string)(int)$row->quantity_units !== (string)$row->quantity_units
                    || abs((int)$row->quantity_units) > 1000000000000000 || (int)$row->revision < 1) throw new RuntimeException('GO_STOCK_BALANCE_INVALID');
                if ((int)$row->quantity_units === 0) continue;
                if ($kind === 'inventory') {
                    $item = $ingredients[$row->ingredient_id] ?? null;
                    if (!$item) throw new RuntimeException('GO_STOCK_INGREDIENT_MISSING');
                    $unit = $item->unit;
                    $name = $item->name;
                    $itemKey = 'ingredient_id'; $nameKey = 'name';
                    $table = 'branch_inventory'; $movementTable = 'branch_inventory_movements';
                    $counts[$row->branch]['ingredients']++;
                } else {
                    $item = $products[$row->product_id] ?? null;
                    $unit = $row->unit;
                    $name = $item && (int)$item->resturant_id === (int)substr($row->branch, 2) ? $item->product_name : 'صنف سابق #'.$row->product_id;
                    $itemKey = 'product_id'; $nameKey = 'product_name';
                    $table = 'branch_stock'; $movementTable = 'branch_stock_movements';
                    $counts[$row->branch]['products']++;
                }
                if (!in_array($unit, ['kg', 'piece'], true)) throw new RuntimeException('GO_STOCK_UNIT_INVALID');
                if ($db->table($movementTable)->where('stock_id', $row->id)->where('source_type', 'adjustment')->where('source_id', $source)->exists()) throw new RuntimeException('GO_STOCK_RESET_MOVEMENT_WITHOUT_RECEIPT');
                $plan[] = ['row' => $row, 'table' => $table, 'movement_table' => $movementTable, 'movement' => [
                    'stock_id' => $row->id, 'branch' => $row->branch, $itemKey => $row->$itemKey,
                    $nameKey => mb_substr((string)$name, 0, 255), 'unit' => $unit,
                    'quantity_units' => -(int)$row->quantity_units, 'balance_units' => 0,
                    'source_type' => 'adjustment', 'source_id' => $source, 'actor_id' => 1, 'supplier' => null, 'notes' => $reason,
                ]];
            }
        }
        $backup = $backupDirectory.'/before-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $payload = json_encode(['version' => 1, 'source' => $source, 'request_key' => $requestKey, 'branches' => $branches,
            'database' => $db->getDatabaseName(), 'reason' => $reason, 'inventory' => $inventory->all(), 'stock' => $stock->all(),
            'planned_adjustments' => array_map(static fn($item) => ['table' => $item['movement_table'], 'movement' => $item['movement']], $plan),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $handle = fopen($backup, 'xb');
        if ($handle === false) throw new RuntimeException('GO_STOCK_BACKUP_OPEN_FAILED');
        try {
            if (!chmod($backup, 0600) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('GO_STOCK_BACKUP_WRITE_FAILED');
        } finally { fclose($handle); }
        if (!hash_equals(hash('sha256', $payload), hash_file('sha256', $backup))) throw new RuntimeException('GO_STOCK_BACKUP_VERIFY_FAILED');
        $now = gmdate('Y-m-d H:i:s');
        foreach ($plan as $item) {
            $row = $item['row'];
            $db->table($item['table'])->where('id', $row->id)->update(['quantity_units' => 0, 'revision' => (int)$row->revision + 1, 'updated_at' => $now]);
            $id = $db->table($item['movement_table'])->insertGetId($item['movement'] + ['created_at' => $now, 'updated_at' => $now]);
            $current = $db->table($item['table'])->where('id', $row->id)->first();
            if (!$current || (int)$current->quantity_units !== 0 || (int)$current->revision !== (int)$row->revision + 1) throw new RuntimeException('GO_STOCK_BALANCE_VERIFY_FAILED');
            $movement = $db->table($item['movement_table'])->where('id', $id)->first();
            foreach ($item['movement'] as $key => $value) {
                if (!$movement || (string)$movement->$key !== (string)$value) throw new RuntimeException('GO_STOCK_MOVEMENT_VERIFY_FAILED');
            }
        }
        foreach (['branch_inventory', 'branch_stock'] as $table) {
            if ($db->table($table)->whereIn('branch', $branches)->where('quantity_units', '!=', 0)->exists()) throw new RuntimeException('GO_STOCK_NONZERO_REMAINS');
        }
        $result = ['status' => 'GO_STOCK_ZERO_APPLIED', 'branches' => $counts, 'branch_count' => count($branches),
            'changed_balances' => count($plan), 'remaining_nonzero' => 0, 'backup' => $backup];
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($branches as $branch) {
            $db->table('branch_operation_commands')->insert(['branch' => $branch, 'actor_id' => 1,
                'request_key' => $requestKey, 'request_hash' => $requestHash, 'kind' => 'stock.zero', 'result' => $encoded,
                'created_at' => $now, 'updated_at' => $now]);
        }
        return $result;
    });
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || $argc !== 2 || $argv[1] !== '--apply') throw new RuntimeException('GO_STOCK_ARGUMENTS');
    $root = '/home/fasakha/public_html';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('desktop_dashboard.local') === true
        || (class_exists(\App\Services\OwnerDashboards\Runtime::class) && \App\Services\OwnerDashboards\Runtime::tenant())) throw new RuntimeException('GO_STOCK_PRIMARY_SERVER_ONLY');
    $actor = \App\Models\User::withoutGlobalScopes()->find(1);
    if (!$actor || $actor->account_type !== 'admin' || $actor->status !== 'accepted' || !empty($actor->owner_resturant_id)) throw new RuntimeException('GO_STOCK_OWNER_INVALID');
    $db = $app->make('db')->connection();
    if ($db->getDriverName() !== 'mysql') throw new RuntimeException('GO_STOCK_MYSQL_REQUIRED');
    foreach (['resturants', 'branch_inventory', 'branch_stock', 'branch_inventory_movements', 'branch_stock_movements', 'branch_operation_commands'] as $table) {
        $meta = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db->getDatabaseName(), $db->getTablePrefix().$table]);
        if (!$meta || strcasecmp($meta->engine, 'InnoDB') !== 0) throw new RuntimeException('GO_STOCK_TRANSACTIONAL_TABLES_REQUIRED');
    }
    $branches = array_column($app->make(\App\Services\Dashboard\BranchStock::class)->branches($actor), 'value');
    echo json_encode(goZeroBranchStock($db, $branches, '/home/fasakha/private-stock-zero-20261106'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, preg_match('/^GO_STOCK_[A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage().PHP_EOL : "GO_STOCK_FAILED_NO_PARTIAL_DATABASE_CHANGES\n");
    exit(1);
}
