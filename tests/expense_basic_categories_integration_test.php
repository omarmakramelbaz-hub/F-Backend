<?php
/**
 * Real-service coverage for copying the Mahalla expense vocabulary into independent branch catalogs.
 * Uses the same isolated SQLite application as the stock/expense integration suite.
 * EXPENSE_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/expense_basic_categories_integration_test.php
 */
require __DIR__.'/expense_stock_integration_test.php';

use App\Services\Dashboard\{BranchExpenses, ExpenseCategories, ExpenseStockPurchases};
use Illuminate\Support\Facades\DB;

$basicChecksStart = $checks;

function basicRequest(int $key, array $extra = []): array
{
    return $extra + ['action' => 'apply_basic', 'branch' => 'f:101', 'idempotency_key' => keyFor($key)];
}

function applyBasic(int $key, array $extra = [], $by = null): array
{
    return app(ExpenseCategories::class)->save(basicRequest($key, $extra), $by ?? actor(1));
}

function basicCatalogSnapshot(): string
{
    $result = [];
    foreach (['branch_expense_categories' => 'id', 'branch_expense_category_settings' => 'category_key', 'branch_expense_category_commands' => 'id', 'stock_ingredients' => 'id'] as $table => $order) {
        $result[$table] = DB::table($table)->orderBy($order)->get()->all();
    }
    $result['branch_expense_category_overrides'] = DB::table('branch_expense_category_overrides')->orderBy('branch')->orderBy('category_key')->get()->all();
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function basicDenied(callable $callback, int $status, string $message): void
{
    $financialBefore = databaseSnapshot();
    $catalogBefore = basicCatalogSnapshot();
    denied($callback, $status, $message);
    check(databaseSnapshot() === $financialBefore && basicCatalogSnapshot() === $catalogBefore, $message.' leaves catalog, command audit, stock and cash unchanged');
}

$basicBeforeCatalog = app(ExpenseCategories::class)->choices(true, 'f:100');
$basicBeforeFinancial = databaseSnapshot();
$basicBeforeRows = DB::table('branch_expense_categories')->count();
$basicBeforeSettings = json_encode(DB::table('branch_expense_category_settings')->orderBy('category_key')->get());
$basicBeforeCommands = DB::table('branch_expense_category_commands')->count();
$basicApplied = applyBasic(2000);
$basicSnapshot = $basicApplied['basic_categories'];
check($basicApplied['success'] === true && $basicApplied['replayed'] === false, 'primary administrator can apply the basic expense categories to an explicit branch');
check($basicSnapshot['source_branch'] === 'f:100' && $basicSnapshot['source_name'] === 'المحلة' && $basicSnapshot['target_branch'] === 'f:101' && $basicSnapshot['target_name'] === 'المنصورة', 'adoption records the actual Mahalla source and the explicit destination branch');
check($basicSnapshot['categories'] === $basicBeforeCatalog['active_categories'] && $basicSnapshot['keys'] === array_keys($basicBeforeCatalog['active_categories']) && $basicSnapshot['count'] === count($basicBeforeCatalog['active_categories']), 'adoption snapshots the complete active Mahalla vocabulary, including categories with no recorded expenditure');
check(is_string($basicSnapshot['applied_at']) && strtotime($basicSnapshot['applied_at']) !== false, 'adoption snapshot contains a usable application timestamp');
check($basicApplied['active_categories'] === $basicBeforeCatalog['active_categories'] && $basicApplied['stock_categories'] === $basicBeforeCatalog['stock_categories'], 'first adoption copies the Mahalla names and preserves canonical material links');
check(DB::table('branch_expense_categories')->count() === $basicBeforeRows && json_encode(DB::table('branch_expense_category_settings')->orderBy('category_key')->get()) === $basicBeforeSettings, 'copying branch categories preserves the legacy global catalog and settings');
check(databaseSnapshot() === $basicBeforeFinancial, 'applying basic categories never creates or changes expenses, receipts, inventory movements or cash');
$basicCommand = DB::table('branch_expense_category_commands')->where('request_key', keyFor(2000))->first();
check($basicCommand && (int) $basicCommand->actor_id === 1 && $basicCommand->action === 'apply_basic' && json_decode($basicCommand->snapshot, true) === $basicSnapshot && DB::table('branch_expense_category_commands')->count() === $basicBeforeCommands + 1, 'one adoption command stores the source, destination and complete immutable vocabulary snapshot');

$basicBeforeRetry = basicCatalogSnapshot();
$basicReplayed = applyBasic(2000);
check($basicReplayed['replayed'] === true && $basicReplayed['basic_categories'] === $basicSnapshot && basicCatalogSnapshot() === $basicBeforeRetry && databaseSnapshot() === $basicBeforeFinancial, 'same adoption UUID replays the original snapshot without any second write');
basicDenied(fn () => applyBasic(2000, ['branch' => 'f:100']), 409, 'adoption UUID cannot be reused for another destination branch');
basicDenied(fn () => app(ExpenseCategories::class)->save(['branch' => 'f:101', 'action' => 'create', 'name' => 'بند جديد', 'idempotency_key' => keyFor(2000)], actor(1)), 409, 'adoption UUID cannot be reused for a different category action');
$basicRepeated = applyBasic(2001);
check($basicRepeated['replayed'] === false && $basicRepeated['basic_categories']['categories'] === $basicSnapshot['categories'] && DB::table('branch_expense_categories')->count() === $basicBeforeRows && databaseSnapshot() === $basicBeforeFinancial, 'a repeated click with a new UUID records another adoption without duplicating a category or financial entry');
$basicToSource = applyBasic(2002, ['branch' => 'f:100']);
check($basicToSource['basic_categories']['target_branch'] === 'f:100' && $basicToSource['basic_categories']['categories'] === $basicSnapshot['categories'], 'Mahalla can use its existing basic catalog without copying categories onto themselves');

foreach ([2, 10, 11, 12] as $basicActorId) {
    basicDenied(fn () => applyBasic(2010 + $basicActorId, [], actor($basicActorId)), 403, 'existing actor '.$basicActorId.' cannot adopt basic expense categories');
}
$basicForged = actor(10);
$basicForged->account_type = 'admin';
$basicForged->owner_resturant_id = null;
basicDenied(fn () => applyBasic(2023, [], $basicForged), 403, 'forged in-memory administrator attributes cannot bypass persisted category permissions');
foreach ([['account_type' => 'vendor'], ['owner_resturant_id' => 100]] as $basicOffset => $basicChange) {
    $basicStaleOwner = actor(1);
    DB::table('users')->where('id', 1)->update($basicChange);
    try {
        basicDenied(fn () => applyBasic(2024 + $basicOffset, [], $basicStaleOwner), 403, 'persisted primary-owner permission change '.$basicOffset.' overrides an earlier loaded administrator object');
    } finally {
        DB::table('users')->where('id', 1)->update(['account_type' => 'admin', 'owner_resturant_id' => null]);
    }
}

foreach (['all', 'gs:100', 'f:0', 'f:-1', 'f:0100', 'f:100x', '', ['f:101']] as $basicOffset => $basicInvalidBranch) {
    basicDenied(fn () => applyBasic(2030 + $basicOffset, ['branch' => $basicInvalidBranch]), 422, 'adoption rejects a missing, ambiguous or non-restaurant destination '.json_encode($basicInvalidBranch));
}
$basicMissingBranch = basicRequest(2040);
unset($basicMissingBranch['branch']);
basicDenied(fn () => app(ExpenseCategories::class)->save($basicMissingBranch, actor(1)), 422, 'adoption requires an explicit destination rather than inferring the first branch');
basicDenied(fn () => applyBasic(2041, ['branch' => 'f:99999']), 404, 'adoption rejects a restaurant branch that does not exist');
basicDenied(fn () => applyBasic(2042, ['idempotency_key' => 'invalid']), 422, 'adoption requires a valid request UUID');

DB::table('resturants')->where('id', 100)->update(['name' => 'المحلة الجديدة']);
try {
    basicDenied(fn () => applyBasic(2050), 422, 'adoption refuses an approximate Mahalla match when the exact source is missing');
} finally {
    DB::table('resturants')->where('id', 100)->update(['name' => 'المحلة']);
}
DB::table('resturants')->insert(['id' => 102, 'user_id' => 11, 'name' => 'فرع المحله']);
try {
    basicDenied(fn () => applyBasic(2051), 422, 'adoption refuses to guess between two equivalent Mahalla branches');
} finally {
    DB::table('resturants')->where('id', 102)->delete();
}
foreach (['المحله', 'فرع المحلة', '  فرع   المحله الكبرى  ', 'الْمَحَلَّة'] as $basicOffset => $basicSourceName) {
    DB::table('resturants')->where('id', 100)->update(['name' => $basicSourceName]);
    try {
        $basicNormalized = applyBasic(2060 + $basicOffset);
        check($basicNormalized['basic_categories']['source_branch'] === 'f:100' && $basicNormalized['basic_categories']['source_name'] === $basicSourceName, 'Mahalla source matching normalizes a supported spelling while preserving its real display name: '.$basicSourceName);
    } finally {
        DB::table('resturants')->where('id', 100)->update(['name' => 'المحلة']);
    }
}

$basicCategories = app(ExpenseCategories::class);
$basicRent = $basicCategories->catalog('f:100')['rent'];
$basicCategories->save(['branch' => 'f:100', 'action' => 'delete', 'key' => 'rent', 'expected_revision' => $basicRent['revision'], 'idempotency_key' => keyFor(2070)], actor(1));
$basicWithoutDeleted = applyBasic(2071);
check(!isset($basicWithoutDeleted['basic_categories']['categories']['rent']) && !in_array('rent', $basicWithoutDeleted['basic_categories']['keys'], true) && isset($basicWithoutDeleted['active_categories']['rent']), 'additive adoption excludes a deleted source category and preserves the destination existing choice');
check(DB::table('branch_expense_categories')->count() === $basicBeforeRows && databaseSnapshot() === $basicBeforeFinancial, 'adoption after category deletion still preserves original category rows and all financial history');

$basicPepper = $basicCategories->catalog('f:100')['purchase_pepper'];
$basicCategories->save(['branch' => 'f:100', 'action' => 'update', 'key' => 'purchase_pepper', 'name' => 'توريدات الخضار اليومية', 'expected_revision' => $basicPepper['revision'], 'idempotency_key' => keyFor(2072)], actor(1));
$basicHistoricalReplay = applyBasic(2000);
check($basicHistoricalReplay['replayed'] === true && $basicHistoricalReplay['basic_categories'] === $basicSnapshot && $basicHistoricalReplay['active_categories']['purchase_pepper'] === $basicSnapshot['categories']['purchase_pepper'] && isset($basicHistoricalReplay['active_categories']['rent']), 'retry returns the immutable adoption snapshot and unchanged independent destination choices');
$basicFreshAfterRename = applyBasic(2073);
check($basicFreshAfterRename['basic_categories']['categories']['purchase_pepper'] === 'توريدات الخضار اليومية' && $basicFreshAfterRename['active_categories']['purchase_pepper'] === $basicSnapshot['categories']['purchase_pepper'] && $basicFreshAfterRename['stock_categories']['purchase_pepper'] === 'purchase_pepper', 'fresh adoption snapshots latest Mahalla name and preserves the previously copied destination name and stock identity');

$basicTargetPepper = $basicCategories->catalog('f:101')['purchase_pepper'];
$basicCategories->save(['branch' => 'f:101', 'action' => 'update', 'key' => 'purchase_pepper', 'name' => 'خضار المنصورة اليومية', 'expected_revision' => $basicTargetPepper['revision'], 'idempotency_key' => keyFor(2074)], actor(1));
$basicTargetRent = $basicCategories->catalog('f:101')['rent'];
$basicCategories->save(['branch' => 'f:101', 'action' => 'delete', 'key' => 'rent', 'expected_revision' => $basicTargetRent['revision'], 'idempotency_key' => keyFor(2075)], actor(1));
$basicAfterTargetEdits = $basicCategories->choices(true, 'f:101');
$basicAfterLocalCopy = applyBasic(2076);
check($basicAfterLocalCopy['active_categories'] === $basicAfterTargetEdits['active_categories'] && $basicAfterLocalCopy['active_categories']['purchase_pepper'] === 'خضار المنصورة اليومية' && !isset($basicAfterLocalCopy['active_categories']['rent']), 'reapplying basic categories preserves destination local rename and soft deletion');
check($basicCategories->catalog('f:100')['purchase_pepper']['name'] === 'توريدات الخضار اليومية' && !$basicCategories->catalog('f:100')['rent']['active'], 'destination changes and reapplication leave Mahalla independent names and deletion unchanged');
foreach (ExpenseStockPurchases::CATEGORIES as $basicStockKey => $basicStockName) {
    check(isset($basicFreshAfterRename['basic_categories']['categories'][$basicStockKey]) && $basicFreshAfterRename['stock_categories'][$basicStockKey] === $basicStockKey, 'adopting the basic catalog keeps required kilograms attached to '.$basicStockName);
}
$basicDestinationListing = app(BranchExpenses::class)->listing(['branch' => 'f:101', 'from' => '2026-10-17', 'to' => '2026-10-18'], actor(1));
foreach ($basicDestinationListing['items'] as $basicListedExpense) {
    check($basicListedExpense['category_name'] === $basicListedExpense['category_base_name'].' — فرع المنصورة', 'destination expense '.$basicListedExpense['number'].' keeps its own branch label after basic category adoption');
}

$basicNewSource = $basicCategories->save(['branch' => 'f:100', 'action' => 'create', 'name' => 'تجهيزات المحلة الجديدة', 'idempotency_key' => keyFor(2077)], actor(1));
$basicNewSourceKey = $basicNewSource['category']['key'];
check(!isset($basicCategories->choices(true, 'f:101')['active_categories'][$basicNewSourceKey]), 'new Mahalla category stays unavailable in the other branch until copied');
$basicFaultCatalog = basicCatalogSnapshot();
$basicFaultFinancial = databaseSnapshot();
DB::unprepared("CREATE TEMP TRIGGER basic_category_command_fault BEFORE INSERT ON branch_expense_category_commands WHEN NEW.action = 'apply_basic' BEGIN SELECT RAISE(ABORT, 'isolated basic category write failure'); END");
try {
    applyBasic(2080);
    throw new RuntimeException('Expected injected adoption command write failure');
} catch (\Illuminate\Database\QueryException $error) {
    check(strpos($error->getMessage(), 'isolated basic category write failure') !== false, 'adoption reaches an injected real SQL command-write failure');
} finally {
    DB::unprepared('DROP TRIGGER basic_category_command_fault');
}
check(basicCatalogSnapshot() === $basicFaultCatalog && databaseSnapshot() === $basicFaultFinancial, 'failed adoption command leaves the catalog, prior audits, stock, expenses and cash unchanged');
$basicRecovered = applyBasic(2080);
check($basicRecovered['success'] === true && $basicRecovered['replayed'] === false && DB::table('branch_expense_category_commands')->where('request_key', keyFor(2080))->count() === 1, 'failed adoption can retry with the same UUID and record exactly one complete command');
check($basicRecovered['active_categories'][$basicNewSourceKey] === $basicNewSource['category']['name'] && $basicRecovered['basic_categories']['added_count'] === 1, 'successful retry copies the one missing source category exactly once');
check(databaseSnapshot() === $basicBeforeFinancial, 'all basic-category operations and rejected requests leave both branches financial and inventory history unchanged');

echo ($checks - $basicChecksStart).' basic-category integration checks passed; '.$checks.' total real-service checks'.PHP_EOL;
