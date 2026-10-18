<?php
/**
 * Real SQL integration coverage for independent branch expense vocabularies.
 * EXPENSE_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/expense_branch_local_categories_integration_test.php
 */
require __DIR__.'/expense_basic_categories_integration_test.php';

use App\Services\Dashboard\{BranchExpenses, ExpenseCategories, ExpenseStockPurchases};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$localChecksStart = $checks;

function localCatalogSnapshot(): string
{
    $tables = ['branch_expense_categories' => 'id', 'branch_expense_category_settings' => 'category_key',
        'branch_expense_category_commands' => 'id', 'branch_expense_category_overrides' => 'id', 'stock_ingredients' => 'id'];
    $result = [];
    foreach ($tables as $table => $order) {
        if (!Schema::hasTable($table)) continue;
        $query = DB::table($table);
        if (Schema::hasColumn($table, $order)) $query->orderBy($order);
        else $query->orderBy('branch')->orderBy('category_key');
        $result[$table] = $query->get()->all();
    }
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function localCategory(int $key, string $branch, string $action, array $extra = [], $by = null): array
{
    $before = databaseSnapshot();
    $result = app(ExpenseCategories::class)->save($extra + ['branch' => $branch, 'action' => $action, 'idempotency_key' => keyFor($key)], $by ?? actor(1));
    check(databaseSnapshot() === $before, 'category '.$action.' '.$key.' preserves all expense, receipt, inventory and cash rows');
    return $result;
}

function localRejected(callable $callback, int $status, string $message): void
{
    $catalogBefore = localCatalogSnapshot();
    $financialBefore = databaseSnapshot();
    denied($callback, $status, $message);
    check(localCatalogSnapshot() === $catalogBefore && databaseSnapshot() === $financialBefore, $message.' leaves every category, command and financial row unchanged');
}

function localEdit(int $key, string $branch, string $categoryKey, string $name): array
{
    $item = app(ExpenseCategories::class)->catalog($branch)[$categoryKey];
    return localCategory($key, $branch, 'update', ['key' => $categoryKey, 'name' => $name, 'expected_revision' => $item['revision']]);
}

function localSqlFault(int $key, string $trigger, string $table, string $event, array $request): void
{
    $before = localCatalogSnapshot();
    $financialBefore = databaseSnapshot();
    DB::unprepared('CREATE TEMP TRIGGER '.$trigger.' BEFORE '.$event.' ON '.$table." BEGIN SELECT RAISE(ABORT, 'isolated local category write failure'); END");
    try {
        app(ExpenseCategories::class)->save($request + ['idempotency_key' => keyFor($key)], actor(1));
        throw new RuntimeException('Expected local category SQL fault '.$trigger);
    } catch (\Illuminate\Database\QueryException $error) {
        check(strpos($error->getMessage(), 'isolated local category write failure') !== false, $trigger.' reaches a real SQL write fault');
    } finally {
        DB::unprepared('DROP TRIGGER '.$trigger);
    }
    check(localCatalogSnapshot() === $before && databaseSnapshot() === $financialBefore, $trigger.' rolls back custom origins, branch overrides, audit commands and financial rows');
    $retried = app(ExpenseCategories::class)->save($request + ['idempotency_key' => keyFor($key)], actor(1));
    check($retried['success'] && !$retried['replayed'] && DB::table('branch_expense_category_commands')->where('request_key', keyFor($key))->count() === 1, $trigger.' retries the identical UUID with one completed command');
    check(databaseSnapshot() === $financialBefore, $trigger.' recovered category operation preserves all financial rows');
}

$localCategories = app(ExpenseCategories::class);
$localFinancialStart = databaseSnapshot();
$localSourceCreated = localCategory(20000, 'f:100', 'create', ['name' => 'مصروف خاص بالموقع الأول']);
$localSourceKey = $localSourceCreated['category']['key'];
check(isset($localCategories->choices(true, 'f:100')['active_categories'][$localSourceKey]) && !isset($localCategories->catalog('f:101')[$localSourceKey]), 'new Mahalla custom category is visible only in its origin until explicitly copied');
check(DB::table('branch_expense_categories')->where('id', substr($localSourceKey, 7))->value('origin_branch') === 'f:100', 'new custom row records its origin branch');
localRejected(fn () => saveExpense(20001, ['branch' => 'f:101', 'category' => $localSourceKey, 'quantity_kg' => null], 11), 422, 'target staff cannot post an unimported source custom key');
$localFirstCopy = localCategory(20002, 'f:101', 'apply_basic');
check($localFirstCopy['active_categories'][$localSourceKey] === 'مصروف خاص بالموقع الأول', 'explicit basic-list adoption copies a new source custom category into the target catalog');
$localTargetEdit = localEdit(20003, 'f:101', $localSourceKey, 'مصروف خاص بالموقع الثاني');
check($localTargetEdit['category']['name'] === 'مصروف خاص بالموقع الثاني' && $localCategories->catalog('f:100')[$localSourceKey]['name'] === 'مصروف خاص بالموقع الأول', 'editing an imported destination name leaves the source branch name unchanged');
$localSourceEdit = localEdit(20004, 'f:100', $localSourceKey, 'مصروف المصدر بعد التعديل');
check($localSourceEdit['category']['name'] === 'مصروف المصدر بعد التعديل' && $localCategories->catalog('f:101')[$localSourceKey]['name'] === 'مصروف خاص بالموقع الثاني', 'editing the source after adoption leaves the destination copied name unchanged');
$localBeforeRepeated = $localCategories->choices(true, 'f:101');
$localRepeatCopy = localCategory(20005, 'f:101', 'apply_basic');
check($localRepeatCopy['active_categories'] === $localBeforeRepeated['active_categories'] && $localRepeatCopy['active_categories'][$localSourceKey] === 'مصروف خاص بالموقع الثاني', 'a new adoption UUID preserves existing target renames');
$localTargetItem = $localCategories->catalog('f:101')[$localSourceKey];
localCategory(20006, 'f:101', 'delete', ['key' => $localSourceKey, 'expected_revision' => $localTargetItem['revision']]);
check(!$localCategories->catalog('f:101')[$localSourceKey]['active'] && $localCategories->catalog('f:100')[$localSourceKey]['active'], 'destination soft deletion leaves the source choice active');
$localAfterDeleteCopy = localCategory(20007, 'f:101', 'apply_basic');
check(!isset($localAfterDeleteCopy['active_categories'][$localSourceKey]) && $localCategories->catalog('f:101')[$localSourceKey]['name'] === 'مصروف خاص بالموقع الثاني', 'reapplying basics preserves a destination soft deletion and its independently edited name');
$localSecondSource = localCategory(20008, 'f:100', 'create', ['name' => 'مصروف أضيف للمصدر لاحقا']);
$localSecondKey = $localSecondSource['category']['key'];
check(!isset($localCategories->catalog('f:101')[$localSecondKey]), 'adding another source category does not silently publish it to a branch that previously copied basics');
$localSecondCopy = localCategory(20009, 'f:101', 'apply_basic');
check(($localSecondCopy['active_categories'][$localSecondKey] ?? null) === 'مصروف أضيف للمصدر لاحقا' && !isset($localSecondCopy['active_categories'][$localSourceKey]), 'a later adoption adds new source choices while preserving a target deletion');
$localTargetOnly = localCategory(20010, 'f:101', 'create', ['name' => 'مصروف محلي مستقل للموقع الثاني']);
$localTargetOnlyKey = $localTargetOnly['category']['key'];
check(!isset($localCategories->catalog('f:100')[$localTargetOnlyKey]), 'a custom choice created in Mansoura stays outside Mahalla catalog');
localRejected(fn () => saveExpense(20011, ['category' => $localTargetOnlyKey, 'quantity_kg' => null]), 422, 'Mahalla staff cannot submit a Mansoura-only custom category');
localCategory(20012, 'f:100', 'apply_basic');
check(!isset($localCategories->catalog('f:100')[$localTargetOnlyKey]), 'using Mahalla basics in Mahalla never reverse-imports a Mansoura-only category');

foreach (['purchase_pepper' => 'خضار خاص بالموقع الثاني', 'purchase_oil' => 'توريد عبوات للموقع الثاني'] as $localStockKey => $localStockName) {
    $localSourceName = $localCategories->catalog('f:100')[$localStockKey]['name'];
    localEdit($localStockKey === 'purchase_pepper' ? 20020 : 20021, 'f:101', $localStockKey, $localStockName);
    check($localCategories->catalog('f:100')[$localStockKey]['name'] === $localSourceName && $localCategories->catalog('f:101')[$localStockKey]['stock_category'] === $localStockKey, 'local rename '.$localStockKey.' preserves the source name and immutable stock identity');
}
foreach (['f:100', 'f:101'] as $localBranch) {
    $localStockMap = $localCategories->choices(true, $localBranch)['stock_categories'];
    foreach (ExpenseStockPurchases::CATEGORIES as $localStockKey => $localStockName) {
        check(($localStockMap[$localStockKey] ?? null) === $localStockKey, 'all six stock identities remain stable for '.$localBranch.' '.$localStockKey);
    }
}
check(databaseSnapshot() === $localFinancialStart, 'the complete cross-branch category create, copy, edit and delete workflow changes no financial history');

// Scoped category commands have branch identity as part of their immutable request.
$localScopedCommand = ['branch' => 'f:101', 'action' => 'create', 'name' => 'بند اختبار النطاق', 'idempotency_key' => keyFor(20030)];
$localScopedResult = $localCategories->save($localScopedCommand, actor(1));
$localBeforeReplay = localCatalogSnapshot();
check($localCategories->save($localScopedCommand, actor(1))['replayed'] && localCatalogSnapshot() === $localBeforeReplay, 'a scoped category command retries without another category, override or command');
localRejected(fn () => $localCategories->save(array_merge($localScopedCommand, ['branch' => 'f:100']), actor(1)), 409, 'a scoped UUID cannot be reused in another branch');
localRejected(fn () => $localCategories->save($localScopedCommand, actor(10)), 403, 'branch staff cannot replay an administrator category command');
$localStale = $localCategories->catalog('f:101')[$localScopedResult['category']['key']];
localEdit(20031, 'f:101', $localStale['key'], 'اسم بعد تعديل ناجح');
localRejected(fn () => localCategory(20032, 'f:101', 'update', ['key' => $localStale['key'], 'name' => 'تعديل بإصدار قديم', 'expected_revision' => $localStale['revision']]), 409, 'stale local category revision cannot overwrite a newer destination edit');
localRejected(fn () => $localCategories->save(['action' => 'create', 'name' => 'طلب جديد دون فرع', 'idempotency_key' => keyFor(20033)], actor(1)), 422, 'new category commands require an explicit branch');
localRejected(fn () => localCategory(20034, 'all', 'create', ['name' => 'طلب جميع الفروع']), 422, 'all-branches selection cannot mutate any catalog');

// Completed global commands remain compatible read-only replies after the upgrade.
$localGlobalName = 'اسم محفوظ من أمر عالمي قديم';
$localGlobalSnapshot = ['key' => 'maintenance', 'name' => $localGlobalName, 'active' => true, 'revision' => 7, 'stock_category' => null, 'canonical_key' => 'maintenance'];
DB::table('branch_expense_category_commands')->insert(['request_key' => keyFor(20040), 'request_hash' => hash('sha256', json_encode(['update', 'maintenance', $localGlobalName, 6])), 'actor_id' => 1, 'action' => 'update', 'snapshot' => json_encode($localGlobalSnapshot, JSON_UNESCAPED_UNICODE), 'created_at' => now('UTC')]);
$localGlobalBefore = localCatalogSnapshot();
$localGlobalFinancial = databaseSnapshot();
$localGlobalReply = $localCategories->save(['action' => 'update', 'key' => 'maintenance', 'name' => $localGlobalName, 'expected_revision' => 6, 'idempotency_key' => keyFor(20040)], actor(1));
check($localGlobalReply['replayed'] && $localGlobalReply['category'] === $localGlobalSnapshot && localCatalogSnapshot() === $localGlobalBefore && databaseSnapshot() === $localGlobalFinancial, 'completed legacy global category command replays its stored response without requiring a branch or mutating any branch');

// A pre-upgrade apply_basic audit did not physically copy names. Materialize its
// immutable historical names once, even if Mahalla has since renamed them.
DB::table('users')->insert(['id' => 13, 'name' => 'اختبار الفرع الثالث', 'account_type' => 'vendor', 'owner_resturant_id' => null]);
DB::table('resturants')->insert(['id' => 102, 'user_id' => 13, 'name' => 'نبروه']);
$localHistoricalSnapshot = ['source_branch' => 'f:100', 'source_name' => 'المحلة', 'target_branch' => 'f:102', 'target_name' => 'نبروه',
    'count' => 3, 'keys' => ['maintenance', 'purchase_oil', $localSecondKey],
    'categories' => ['maintenance' => 'الصيانة وقت النسخ القديم', 'purchase_oil' => 'الزيت وقت النسخ القديم', $localSecondKey => 'بند المصدر وقت النسخ القديم'],
    'applied_at' => '2026-10-18T10:00:00+00:00'];
DB::table('branch_expense_category_commands')->insert(['request_key' => keyFor(20041), 'request_hash' => hash('sha256', json_encode(['apply_basic', 'f:102'])), 'actor_id' => 1, 'action' => 'apply_basic', 'snapshot' => json_encode($localHistoricalSnapshot, JSON_UNESCAPED_UNICODE), 'created_at' => now('UTC')]);
$localHistoricalFinancial = databaseSnapshot();
$localHistoricalReplay = $localCategories->save(['action' => 'apply_basic', 'branch' => 'f:102', 'idempotency_key' => keyFor(20041)], actor(1));
$localHistoricTarget = $localCategories->choices(true, 'f:102');
check($localHistoricalReplay['replayed'] && $localHistoricTarget['active_categories']['maintenance'] === 'الصيانة وقت النسخ القديم' && $localHistoricTarget['active_categories']['purchase_oil'] === 'الزيت وقت النسخ القديم' && $localHistoricTarget['active_categories'][$localSecondKey] === 'بند المصدر وقت النسخ القديم', 'old apply-basic audit materializes its immutable historical target names rather than present-day source names');
check($localHistoricTarget['stock_categories']['purchase_oil'] === 'purchase_oil' && databaseSnapshot() === $localHistoricalFinancial, 'historical adoption materialization preserves canonical stock links and every financial row');
$localHistoricalRepeatedBefore = localCatalogSnapshot();
$localCategories->save(['action' => 'apply_basic', 'branch' => 'f:102', 'idempotency_key' => keyFor(20041)], actor(1));
check(localCatalogSnapshot() === $localHistoricalRepeatedBefore, 'legacy adoption materialization runs once and produces no duplicate writes on another retry');
localEdit(20042, 'f:102', 'maintenance', 'صيانة الموقع الثالث بعد النسخ');
$localHistoricalEditedBefore = localCatalogSnapshot();
$localCategories->save(['action' => 'apply_basic', 'branch' => 'f:102', 'idempotency_key' => keyFor(20041)], actor(1));
check($localCategories->catalog('f:102')['maintenance']['name'] === 'صيانة الموقع الثالث بعد النسخ' && localCatalogSnapshot() === $localHistoricalEditedBefore, 'retrying an old adoption after a local edit never restores the obsolete copied name');
localRejected(fn () => $localCategories->save(['action' => 'apply_basic', 'branch' => 'f:101', 'idempotency_key' => keyFor(20041)], actor(1)), 409, 'legacy adoption UUID remains bound to its original destination');

// Real database failures must roll back every category-side write together.
localSqlFault(20050, 'local_override_insert_fault', 'branch_expense_category_overrides', 'INSERT', ['branch' => 'f:101', 'action' => 'create', 'name' => 'بند اختبار فشل إدخال النسخة']);
$localFaultEdit = $localCategories->catalog('f:101')['purchase_oil'];
localSqlFault(20051, 'local_override_update_fault', 'branch_expense_category_overrides', 'UPDATE', ['branch' => 'f:101', 'action' => 'update', 'key' => 'purchase_oil', 'name' => 'توريد الزيت المحلي بعد الاستعادة', 'expected_revision' => $localFaultEdit['revision']]);
localSqlFault(20052, 'local_command_insert_fault', 'branch_expense_category_commands', 'INSERT', ['branch' => 'f:101', 'action' => 'create', 'name' => 'بند اختبار فشل الأمر']);
$localForFailedCopy = localCategory(20053, 'f:100', 'create', ['name' => 'بند اختبار فشل النسخ من المصدر']);
localSqlFault(20054, 'local_copy_override_fault', 'branch_expense_category_overrides', 'INSERT', ['branch' => 'f:101', 'action' => 'apply_basic']);
check(isset($localCategories->choices(true, 'f:101')['active_categories'][$localForFailedCopy['category']['key']]), 'retry after a copy-write fault imports the missing choice exactly once');

// After independent renames, stock quantities and presentation still resolve per branch.
$localStockFinancial = databaseSnapshot();
localRejected(fn () => saveExpense(20060, ['branch' => 'f:101', 'quantity_kg' => null, 'description' => ''], 11), 422, 'locally renamed pepper still requires kilograms');
$localPepper100 = quantityFor('f:100', 'فلفل');
$localPepper101 = quantityFor('f:101', 'فلفل');
$localTargetPurchase = saveExpense(20061, ['branch' => 'f:101', 'description' => '', 'quantity_kg' => '2.125'], 11)['expense'];
check($localTargetPurchase['description'] === '' && $localTargetPurchase['category_base_name'] === 'خضار خاص بالموقع الثاني' && $localTargetPurchase['category_name'] === 'خضار خاص بالموقع الثاني — فرع المنصورة', 'an empty optional description saves with the destination local name and destination branch label');
check(quantityFor('f:101', 'فلفل') === $localPepper101 + 2125000 && quantityFor('f:100', 'فلفل') === $localPepper100 && $localTargetPurchase['stock_category'] === 'purchase_pepper', 'destination linked expense receives only destination stock with the stable pepper identity');
$localSourcePurchase = saveExpense(20062, ['description' => null, 'quantity_kg' => '0.250'])['expense'];
check($localSourcePurchase['description'] === '' && $localSourcePurchase['category_base_name'] === $localCategories->catalog('f:100')['purchase_pepper']['name'] && $localSourcePurchase['category_base_name'] !== $localTargetPurchase['category_base_name'], 'source purchase uses its independent category name and still accepts a null optional description');
localRejected(fn () => saveExpense(20063, ['branch' => 'f:101', 'category' => $localSourceKey, 'quantity_kg' => null], 11), 422, 'destination staff cannot save a locally deleted copied choice');
$localSourceOnlyExpense = saveExpense(20064, ['category' => $localSourceKey, 'quantity_kg' => null, 'description' => ''])['expense'];
check($localSourceOnlyExpense['category_base_name'] === 'مصروف المصدر بعد التعديل', 'the source can still save its choice after destination deletion');
$localTargetOnlyExpense = saveExpense(20065, ['branch' => 'f:101', 'category' => $localTargetOnlyKey, 'quantity_kg' => null, 'description' => ''], 11)['expense'];
check($localTargetOnlyExpense['category_base_name'] === 'مصروف محلي مستقل للموقع الثاني', 'destination staff can save its independently created local custom choice');

$localAllFilters = ['branch' => 'all', 'from' => '2026-10-17', 'to' => '2026-10-18'];
$localAllReport = app(BranchExpenses::class)->listing($localAllFilters, actor(1), true);
$localReportedById = array_column($localAllReport['items'], null, 'id');
foreach ([$localSourcePurchase, $localTargetPurchase, $localSourceOnlyExpense, $localTargetOnlyExpense] as $localExpectedExpense) {
    $localShown = app(BranchExpenses::class)->show($localExpectedExpense['id'], actor(1))['expense'];
    $localReported = $localReportedById[$localExpectedExpense['id']] ?? null;
    check($localReported && $localReported['category_base_name'] === $localShown['category_base_name'] && $localReported['category_name'] === $localShown['category_name'] && $localReported['category_name'] === $localExpectedExpense['category_name'], 'all-branch export and show use the row own branch vocabulary for expense '.$localExpectedExpense['id']);
}
$localOrdinaryAll = app(BranchExpenses::class)->listing($localAllFilters, actor(1));
$localOrdinaryById = array_column($localOrdinaryAll['items'], null, 'id');
check($localOrdinaryById[$localTargetPurchase['id']]['category_name'] === $localTargetPurchase['category_name'] && $localOrdinaryById[$localSourcePurchase['id']]['category_name'] === $localSourcePurchase['category_name'], 'normal all-branch listing preserves different names for the same canonical purchase key');
$localMahallaListing = app(BranchExpenses::class)->listing(['branch' => 'f:100', 'from' => '2026-10-17', 'to' => '2026-10-18'], actor(10));
check(array_keys($localMahallaListing['branch_categories']) === ['f:100'] && !isset($localMahallaListing['categories'][$localTargetOnlyKey]), 'Mahalla staff receive only the authorized branch category map and no Mansoura-only choices');
$localMansouraListing = app(BranchExpenses::class)->listing(['branch' => 'f:101', 'from' => '2026-10-17', 'to' => '2026-10-18'], actor(11));
check(array_keys($localMansouraListing['branch_categories']) === ['f:101'] && $localMansouraListing['active_categories']['purchase_pepper'] === 'خضار خاص بالموقع الثاني', 'Mansoura staff receive only their own local category map');
localRejected(fn () => app(BranchExpenses::class)->listing($localAllFilters, actor(10)), 403, 'branch staff cannot inspect all-branch local vocabulary maps');

// The migration is additive and safe when the guarded deployment retries it.
$localMigrationCatalog = localCatalogSnapshot();
$localMigrationFinancial = databaseSnapshot();
(new ScopeExpenseCategoriesByBranch)->up();
check(localCatalogSnapshot() === $localMigrationCatalog && databaseSnapshot() === $localMigrationFinancial, 're-running the branch-category migration preserves all origins, overrides, commands and financial data');

// The offline dataset must include the exact same branch-local vocabulary.
$localDesktopForeign = localCategory(20070, 'f:100', 'create', ['name' => 'بند مصدر غير منسوخ لبيانات الكمبيوتر']);
$localDesktopQueries = app(\App\Services\Dashboard\DesktopDashboardData::class)->queries((object) ['id' => 1], actor(11), ['f:101']);
check(isset($localDesktopQueries['branch_expense_category_overrides']) && in_array('branch_expense_category_overrides', \App\Services\Dashboard\DesktopDashboardSchema::TABLES, true), 'desktop schema enrolls branch-local category overrides');
check(in_array('branch_expense_category_overrides', \App\Services\Dashboard\DesktopDashboardBootstrap::BRANCH_TABLES, true), 'desktop bootstrap treats category overrides as branch data');
$localDesktopDefinitions = $localDesktopQueries['branch_expense_categories']->pluck('id')->map(fn ($id) => (int) $id)->all();
check(!in_array((int) substr($localDesktopForeign['category']['key'], 7), $localDesktopDefinitions, true), 'Mansoura offline data excludes a foreign origin category that it never copied');
check(in_array((int) substr($localSourceKey, 7), $localDesktopDefinitions, true) && in_array((int) substr($localTargetOnlyKey, 7), $localDesktopDefinitions, true), 'offline data includes both destination custom definitions and copied definitions whose origins belong to another branch');
check(in_array(2, $localDesktopDefinitions, true) && DB::table('branch_expense_categories')->where('id', 2)->value('origin_branch') === null, 'offline data retains legacy global custom definitions');
$localDesktopOverrides = $localDesktopQueries['branch_expense_category_overrides']->get();
check($localDesktopOverrides->count() > 0 && $localDesktopOverrides->every(fn ($row) => $row->branch === 'f:101'), 'offline override export includes only the device branch and no foreign local names');

echo ($checks - $localChecksStart).' independent branch-category integration checks passed; '.$checks.' total real-service checks'.PHP_EOL;
