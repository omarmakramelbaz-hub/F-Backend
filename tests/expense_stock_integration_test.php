<?php
/**
 * Exercises real expense/category/inventory services against an isolated SQLite database.
 * No application bootstrap, .env, credentials, network, or production database is used.
 * Run with PHP 8+: EXPENSE_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/expense_stock_integration_test.php
 */
$autoload = getenv('EXPENSE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set EXPENSE_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
require $autoload;
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) return;
    $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{BranchExpenses, ExpenseCategories, ExpenseStockPurchases, PosServiceTicket};

function app($abstract = null, array $parameters = []) { $c = Container::getInstance(); return $abstract ? $c->make($abstract, $parameters) : $c; }
function config($key = null, $default = null) { if (is_array($key)) { foreach ($key as $k => $v) app('config')->set($k, $v); return; } return app('config')->get($key, $default); }
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($value, $status, $message = '') { if (!$value) throw new HttpException($status, $message); }
function abort_if($value, $status, $message = '') { if ($value) throw new HttpException($status, $message); }
function abort($status, $message = '') { throw new HttpException($status, $message); }
function route($name, $args = []) { return 'https://expense-test.invalid/'.$name.'?'.http_build_query($args); }
function database_path($path = '') { return dirname(__DIR__).'/database/'.$path; }
function trans($key, $replace = [], $locale = null) { return app('translator')->get($key, $replace, $locale); }
function __($key, $replace = [], $locale = null) { return trans($key, $replace, $locale); }
class ExpenseStockApplication extends Container { public function getLocale() { return 'ar'; } }
class ExpenseStockUser extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; public function can($permission) { return (int)$this->id===2&&$permission==='order-list'; } }
class_alias(ExpenseStockUser::class, 'App\\Models\\User');

$container = new ExpenseStockApplication;
Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$loader = new \Illuminate\Translation\ArrayLoader;
foreach (['ar', 'en'] as $locale) $loader->addMessages($locale, 'expenses', require dirname(__DIR__).'/resources/lang/'.$locale.'/expenses.php');
$translator = new \Illuminate\Translation\Translator($loader, 'ar');
$container->instance('translator', $translator);
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
config(['app.timezone' => 'Africa/Cairo']);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18T12:00:00Z'));

Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable(); });
Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); });
DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('name'); });
Schema::create('resturant_products', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); $t->string('product_name'); $t->string('status')->default('show'); });
foreach ([[1, 'admin', null], [2, 'admin', null], [10, 'vendor', null], [11, 'vendor', null], [12, 'resturant_owner', 100]] as [$id, $type, $parent]) DB::table('users')->insert(['id' => $id, 'name' => 'اختبار '.$id, 'account_type' => $type, 'owner_resturant_id' => $parent]);
DB::table('resturants')->insert([['id' => 100, 'user_id' => 10, 'name' => 'المحلة'], ['id' => 101, 'user_id' => 11, 'name' => 'المنصورة']]);
foreach ([
    '2026_10_03_140000_create_takeaway_pos.php' => 'CreateTakeawayPos',
    '2026_10_04_030000_create_branch_expenses.php' => 'CreateBranchExpenses',
    '2026_10_04_190000_create_branch_stock.php' => 'CreateBranchStock',
    '2026_10_04_210000_create_branch_inventory_recipes.php' => 'CreateBranchInventoryRecipes',
    '2026_10_06_120000_create_branch_expenses_categories.php' => 'CreateBranchExpensesCategories',
    '2026_10_06_200000_manage_expense_categories.php' => 'ManageExpenseCategories',
] as $file => $class) { require_once database_path('migrations/'.$file); (new $class)->up(); }

function keyFor(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
function actor(int $id = 10) { return ExpenseStockUser::findOrFail($id); }
function payload(int $key, array $extra = []): array { return $extra + ['branch' => 'f:100', 'idempotency_key' => keyFor($key), 'occurred_on' => '2026-10-18', 'category' => 'purchase_pepper', 'description' => 'توريد الخامات', 'amount' => '125.50', 'payment_method' => 'cash', 'quantity_kg' => '1.250']; }
function saveExpense(int $key, array $extra = [], int $actorId = 10): array { return app(BranchExpenses::class)->save(payload($key, $extra), actor($actorId)); }
function reviewPayload(array $expense, string $action, int $key): array { return ['branch' => $expense['branch'], 'idempotency_key' => keyFor($key), 'expected_revision' => $expense['revision'], 'action' => $action, 'reason' => 'مراجعة الاختبار']; }
function reviewExpense(array $expense, string $action, int $key, int $actorId = 1): array { return app(BranchExpenses::class)->review($expense['id'], reviewPayload($expense, $action, $key), actor($actorId)); }
$checks = 0;
function check($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; echo 'PASS '.$message.PHP_EOL; }
function denied(callable $callback, int $status, string $message): void {
    try { $callback(); }
    catch (HttpException $e) { check($e->getStatusCode() === $status, $message.' (HTTP '.$e->getStatusCode().')'); return; }
    catch (ValidationException $e) { check($status === 422 && count($e->errors()) > 0, $message.' (validation)'); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
function databaseSnapshot(): string {
    $result = [];
    foreach (['branch_expenses', 'branch_expense_commands', 'expense_inventory_receipts', 'branch_inventory', 'branch_inventory_movements', 'takeaway_tills', 'takeaway_till_entries'] as $table) $result[$table] = DB::table($table)->orderBy('id')->get()->all();
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}
function noWrites(callable $callback, int $status, string $message): void { $before = databaseSnapshot(); denied($callback, $status, $message); check(databaseSnapshot() === $before, $message.' leaves ledger unchanged'); }
function faultRollback(string $trigger, string $table, callable $callback, string $message): void {
    $before = databaseSnapshot();
    DB::unprepared('CREATE TEMP TRIGGER '.$trigger.' BEFORE INSERT ON '.$table.' BEGIN SELECT RAISE(ABORT, \'isolated test write failure\'); END');
    try { $callback(); throw new RuntimeException('Expected write failure: '.$message); }
    catch (\Illuminate\Database\QueryException $e) { check(strpos($e->getMessage(), 'isolated test write failure') !== false, $message.' reaches injected write failure'); }
    finally { DB::unprepared('DROP TRIGGER '.$trigger); }
    check(databaseSnapshot() === $before, $message.' rolls back expenses, stock, cash, and commands together');
}
function quantityFor(string $branch, string $name): int {
    $ingredient = DB::table('stock_ingredients')->where('name', $name)->value('id');
    return (int) DB::table('branch_inventory')->where('branch', $branch)->where('ingredient_id', $ingredient)->value('quantity_units');
}

// Existing aliases and balances precede the new migration; history is never rebuilt.
foreach ([1 => 'مشتريات فلفل - المحلة', 2 => 'أدوات نظافة - فرع المحلة', 3 => 'أدوات نظافة نبروه', 4 => 'بصل نبروه'] as $id => $name) DB::table('branch_expense_categories')->insert(['id' => $id, 'name' => $name, 'name_hash' => hash('sha256', $name), 'created_by' => 1, 'request_key' => keyFor(800 + $id)]);
$legacyId = DB::table('branch_expenses')->insertGetId(['branch' => 'f:100', 'actor_id' => 10, 'occurred_on' => '2026-10-17', 'category' => 'custom_1', 'description' => 'مشتريات قديمة', 'amount_cents' => 5000, 'payment_method' => 'cash', 'status' => 'approved', 'revision' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
$legacySnapshot = json_encode(DB::table('branch_expenses')->where('id', $legacyId)->first());
$legacyInputs = [];
foreach ([['key' => 810, 'id' => $legacyId, 'category' => 'custom_1', 'description' => 'مشتريات قديمة'], ['key' => 811, 'id' => null, 'category' => 'purchases', 'description' => 'فلفل']] as $legacy) {
    if (!$legacy['id']) $legacy['id'] = DB::table('branch_expenses')->insertGetId(['branch' => 'f:100', 'actor_id' => 10, 'occurred_on' => '2026-10-17', 'category' => $legacy['category'], 'description' => $legacy['description'], 'amount_cents' => 5000, 'payment_method' => 'cash', 'status' => 'approved', 'revision' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    $input = ['branch' => 'f:100', 'idempotency_key' => keyFor($legacy['key']), 'occurred_on' => '2026-10-17', 'category' => $legacy['category'], 'description' => $legacy['description'], 'amount' => '50.00', 'payment_method' => 'cash', 'payment_reference' => '', 'supplier' => '', 'cost_center' => '', 'notes' => ''];
    DB::table('branch_expense_commands')->insert(['branch' => 'f:100', 'actor_id' => 10, 'request_key' => $input['idempotency_key'], 'request_hash' => PosServiceTicket::fingerprint(['save', $input, []]), 'expense_id' => $legacy['id'], 'kind' => 'create', 'revision' => 1, 'snapshot' => json_encode(DB::table('branch_expenses')->where('id', $legacy['id'])->first()), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    $legacyInputs[] = $input;
}
$pepperId = DB::table('stock_ingredients')->where('name', 'فلفل')->value('id');
DB::table('branch_inventory')->insert([['branch' => 'f:100', 'ingredient_id' => $pepperId, 'quantity_units' => 2000000, 'revision' => 4], ['branch' => 'f:101', 'ingredient_id' => $pepperId, 'quantity_units' => 9000000, 'revision' => 1]]);
DB::table('takeaway_tills')->insert([['branch' => 'f:100', 'balance_cents' => 100000, 'tax_bps' => 0, 'revision' => 1], ['branch' => 'f:101', 'balance_cents' => 50000, 'tax_bps' => 0, 'revision' => 1]]);
require_once database_path('migrations/2026_10_18_090000_link_expense_inventory_purchases.php');
(new LinkExpenseInventoryPurchases)->up();
check(json_encode(DB::table('branch_expenses')->where('id', $legacyId)->first()) === $legacySnapshot && DB::table('expense_inventory_receipts')->count() === 0, 'upgrade preserves historical expenses and creates no retrospective receipts');
check(quantityFor('f:100', 'فلفل') === 2000000 && quantityFor('f:101', 'فلفل') === 9000000, 'upgrade preserves existing branch balances');
foreach (['زيت', 'خل'] as $name) check(DB::table('stock_ingredients')->where('name', $name)->where('unit', 'kg')->count() === 1, 'upgrade provides one kilogram ingredient for '.$name);

$categories = app(ExpenseCategories::class);
$choices = $categories->choices(true);
$categorySequence=900;
foreach (ExpenseStockPurchases::CATEGORIES as $category => $name) {
    check(isset($choices['active_categories'][$category]), 'shared category '.$category.' is available to Mahalla and other branches');
    $rename=['action'=>'update','key'=>$category,'name'=>'مشتريات يومية '.++$categorySequence,'expected_revision'=>0,'idempotency_key'=>keyFor($categorySequence)];
    $before=databaseSnapshot();$renamed=$categories->save($rename,actor(1));
    check($renamed['category']['name']===$rename['name']&&$renamed['category']['revision']===1&&$renamed['category']['active'], 'primary owner can rename '.$category.' without resetting its revision');
    $fresh=(new ExpenseCategories)->catalog()[$category];
    check($fresh['name']===$rename['name']&&$fresh['revision']===1&&$fresh['stock_category']===$category, 'fresh catalog keeps renamed '.$category.' linked to its original material');
    check(databaseSnapshot()===$before, 'renaming '.$category.' never changes expenses or either ledger');
    $replayed=$categories->save($rename,actor(1));
    check($replayed['replayed']&&$replayed['category']===$renamed['category']&&DB::table('branch_expense_category_commands')->where('request_key',$rename['idempotency_key'])->count()===1, 'category rename replay preserves one revision and command for '.$category);
    denied(fn ()=>$categories->save(array_merge($rename,['idempotency_key'=>keyFor($categorySequence+100)]),actor(1)),409,'stale revision cannot rename '.$category.' again');
    denied(fn ()=>$categories->save(array_merge($rename,['name'=>'اسم مختلف']),actor(1)),409,'rename request key cannot be reused with different input for '.$category);
    $before = json_encode($categories->catalog());
    denied(fn () => $categories->save(['action' => 'delete', 'key' => $category, 'expected_revision' => 1, 'idempotency_key' => keyFor(890)], actor(1)), 422, 'linked '.$category.' still cannot be deleted');
    check(json_encode($categories->catalog()) === $before, 'linked category delete rejection preserves shared choices');
    foreach ([null, '', '0', '-1', '0.000', '1.0001', '1000000.001', '2e3'] as $invalid) noWrites(fn () => saveExpense(100, ['category' => $category, 'quantity_kg' => $invalid]), 422, $category.' rejects missing or invalid kilograms '.var_export($invalid, true));
}
$choices=$categories->choices(true);
check(($choices['stock_categories']['custom_1'] ?? null) === 'purchase_pepper', 'legacy material category alias resolves to the stock item');
check(($choices['stock_categories']['custom_4'] ?? null) === 'purchase_onion', 'branch suffix on legacy onion category resolves to the stock item');
check($categories->filterKeys('purchase_pepper')===['custom_1','purchase_pepper']&&$categories->catalog()['custom_1']['name']===$choices['active_categories']['purchase_pepper'], 'renamed material keeps original branch alias in its canonical group and display name');
foreach (['طماطم','tomatoes','أدوات نظافة',$choices['active_categories']['purchase_onion']] as $collision) denied(fn ()=>$categories->save(['action'=>'update','key'=>'purchase_pepper','name'=>$collision,'expected_revision'=>1,'idempotency_key'=>keyFor(980)],actor(1)),422,'linked rename rejects another shared name or material alias: '.$collision);
foreach ([2,10,11,12] as $actorId) {
    $before=json_encode($categories->catalog());
    denied(fn ()=>$categories->save(['action'=>'update','key'=>'purchase_pepper','name'=>'اسم غير مصرح','expected_revision'=>1,'idempotency_key'=>keyFor(981)],actor($actorId)),403,'existing actor '.$actorId.' cannot manage shared expense categories');
    check(json_encode($categories->catalog())===$before,'unauthorized category rename leaves vocabulary unchanged');
}
$cleaning = array_filter($choices['active_categories'], fn ($name) => mb_strpos($name, 'نظافة') !== false);
check(count($cleaning) === 1, 'duplicate branch-specific expense names have one shared active choice');
$categoryResult = $categories->save(['name' => 'أدوات نظافة المنصورة', 'idempotency_key' => keyFor(850)], actor(1));
check(DB::table('branch_expense_categories')->count() === 4, 'creating another branch alias reuses the shared category');
$categoryResult = $categories->save(['name' => 'أدوات نظافة - فرع المحلة', 'idempotency_key' => keyFor(802)], actor(1));
check($categoryResult['replayed'] && DB::table('branch_expense_categories')->count() === 4, 'legacy category creation retry accepts its original branch-qualified name hash');
foreach ($legacyInputs as $input) {
    $before = databaseSnapshot();
    $result = app(BranchExpenses::class)->save($input, actor());
    check($result['replayed'] && databaseSnapshot() === $before, 'pre-upgrade expense retry preserves original hash without demanding new kilograms: '.$input['category']);
    noWrites(fn () => app(BranchExpenses::class)->save(array_merge($input, ['amount' => '50.01']), actor()), 409, 'pre-upgrade request key still rejects changed amount');
}

// Saving immediately receives goods while awaiting financial approval.
$first = saveExpense(1, ['quantity_kg' => '١٫٢٥٠']);
$expense = $first['expense'];
check($expense['status'] === 'pending' && !$first['replayed'], 'new linked expense is pending');
check(quantityFor('f:100', 'فلفل') === 3250000 && quantityFor('f:101', 'فلفل') === 9000000, 'Arabic decimal kilograms increase only the selected branch stock');
check(DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents') === 100000 && DB::table('takeaway_till_entries')->count() === 0, 'pending expense leaves drawer cash unchanged');
check(DB::table('expense_inventory_receipts')->where('expense_id', $expense['id'])->count() === 1 && DB::table('branch_inventory_movements')->where('source_type', 'expense')->count() === 1, 'one linked receipt and one inventory ledger movement are recorded');
$beforeRename=databaseSnapshot();
$savedCommand=DB::table('branch_expense_commands')->where('expense_id',$expense['id'])->value('snapshot');
$materialRename=$categories->save(['action'=>'update','key'=>'purchase_pepper','name'=>'توريدات المطبخ','expected_revision'=>1,'idempotency_key'=>keyFor(910)],actor(1));
$renamedExpense=app(BranchExpenses::class)->show($expense['id'],actor())['expense'];
check($materialRename['category']['revision']===2&&$renamedExpense['category_base_name']==='توريدات المطبخ'&&$renamedExpense['category_name']==='توريدات المطبخ — فرع المحلة','expense displays updated shared name beside its actual branch');
check(databaseSnapshot()===$beforeRename&&DB::table('branch_expense_commands')->where('expense_id',$expense['id'])->value('snapshot')===$savedCommand,'renaming a category preserves existing expense receipt, ingredient and immutable audit snapshot');
check($renamedExpense['stock_category']==='purchase_pepper'&&$renamedExpense['stock_item_name']==='فلفل'&&$renamedExpense['stock_quantity_kg']==='1.25','renamed purchase retains original material and kilogram metadata');
noWrites(fn()=>saveExpense(982,['quantity_kg'=>null]),422,'second arbitrary material rename still requires kilograms');
$replay = saveExpense(1, ['quantity_kg' => '1.25']);
check($replay['replayed'] && $replay['expense']['id'] === $expense['id'] && quantityFor('f:100', 'فلفل') === 3250000, 'normalized retry returns original expense without adding goods twice');
noWrites(fn () => saveExpense(1, ['quantity_kg' => '1.251']), 409, 'reusing request key with changed weight is rejected');
noWrites(fn () => saveExpense(2, ['branch' => 'f:101']), 404, 'branch staff cannot add goods to another branch');
noWrites(fn () => saveExpense(2, ['approve' => true]), 403, 'branch staff cannot approve their purchase');
noWrites(fn () => saveExpense(2, ['payment_method' => 'card']), 422, 'linked expense accepts drawer cash only');
noWrites(fn () => saveExpense(2, ['category' => 'purchases', 'description' => 'فلفل', 'quantity_kg' => null]), 422, 'exact material name in legacy purchases cannot bypass kilogram validation');
noWrites(fn () => saveExpense(2, ['category' => 'custom_1', 'quantity_kg' => null]), 422, 'legacy material category cannot bypass kilogram validation');
noWrites(fn () => saveExpense(2, ['category' => 'water', 'quantity_kg' => '1']), 422, 'unrelated expense cannot receive accidental stock');

$approved = reviewExpense($expense, 'approve', 20);
check($approved['expense']['status'] === 'approved' && quantityFor('f:100', 'فلفل') === 3250000, 'approval does not receive the same purchase again');
check((int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents') === 87450 && DB::table('takeaway_till_entries')->count() === 1, 'approval debits cash exactly once');
$replay = reviewExpense($expense, 'approve', 20);
check($replay['replayed'] && DB::table('takeaway_till_entries')->count() === 1, 'approval retry has no duplicate drawer debit');
noWrites(fn () => reviewExpense($expense, 'approve', 21), 409, 'stale second approval is rejected');

$voided = reviewExpense($approved['expense'], 'void', 22);
check($voided['expense']['status'] === 'voided' && quantityFor('f:100', 'فلفل') === 2000000, 'voiding reverses the original stock receipt');
check((int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents') === 100000 && DB::table('takeaway_till_entries')->count() === 2, 'voiding returns the approved cash once');
$replay = reviewExpense($approved['expense'], 'void', 22);
check($replay['replayed'] && quantityFor('f:100', 'فلفل') === 2000000 && DB::table('branch_inventory_movements')->where('source_type', 'expense_reverse')->count() === 1, 'void retry never reverses stock twice');
noWrites(fn () => reviewExpense($approved['expense'], 'void', 23), 409, 'new key cannot void the same expense a second time');

$pending = saveExpense(3, ['category' => 'purchase_tomato', 'quantity_kg' => '2.345'])['expense'];
check(quantityFor('f:100', 'طماطم') === 2345000, 'decimal purchase stores exact quantity without floating point drift');
$rejected = reviewExpense($pending, 'reject', 30);
check($rejected['expense']['status'] === 'rejected' && quantityFor('f:100', 'طماطم') === 0 && DB::table('takeaway_till_entries')->count() === 2, 'rejecting a pending purchase reverses stock without moving cash');
check(reviewExpense($pending, 'reject', 30)['replayed'] && quantityFor('f:100', 'طماطم') === 0, 'reject retry preserves one reversal');

$sequence = 40;
foreach (['purchase_lemon' => 'ليمون', 'purchase_onion' => 'بصل', 'purchase_oil' => 'زيت', 'purchase_vinegar' => 'خل'] as $offset => $name) {
    $item = saveExpense(++$sequence, ['category' => $offset, 'quantity_kg' => '0.125', 'approve' => true], 1)['expense'];
    check($item['status'] === 'approved' && quantityFor('f:100', $name) === 125000, 'immediate approved purchase receives '.$name.' in kilograms');
}
$foreign = saveExpense(50, ['branch' => 'f:101', 'category' => 'purchase_oil', 'quantity_kg' => '3'], 11)['expense'];
check(quantityFor('f:101', 'زيت') === 3000000 && quantityFor('f:100', 'زيت') === 125000, 'same material has independent stock per branch');
$unlinked = saveExpense(51, ['category' => 'water', 'description' => 'فاتورة مياه', 'quantity_kg' => null])['expense'];
check(!DB::table('expense_inventory_receipts')->where('expense_id', $unlinked['id'])->exists(), 'ordinary expense remains independent of inventory');

// Real SQL failures establish atomicity across both ledgers, not just early validation.
faultRollback('expense_test_receipt_fault', 'branch_inventory_movements', fn () => saveExpense(60, ['category' => 'purchase_onion', 'quantity_kg' => '2']), 'inventory write failure on pending save');
faultRollback('expense_test_cash_fault', 'takeaway_till_entries', fn () => saveExpense(61, ['category' => 'purchase_onion', 'quantity_kg' => '2', 'approve' => true], 1), 'cash write failure after immediate inventory receipt');
$pending = saveExpense(62, ['category' => 'purchase_lemon', 'quantity_kg' => '2'])['expense'];
faultRollback('expense_test_approval_fault', 'takeaway_till_entries', fn () => reviewExpense($pending, 'approve', 63), 'cash write failure during approval');
$pending = reviewExpense($pending, 'approve', 63)['expense'];
faultRollback('expense_test_reverse_fault', 'branch_inventory_movements', fn () => reviewExpense($pending, 'void', 64), 'inventory write failure during cash refund and reversal');
check(reviewExpense($pending, 'void', 64)['expense']['status'] === 'voided', 'failed void command can retry successfully with the same key');
check(json_encode(DB::table('branch_expenses')->where('id', $legacyId)->first()) === $legacySnapshot && !DB::table('expense_inventory_receipts')->where('expense_id', $legacyId)->exists(), 'all new operations preserve legacy expense history');
$listing = app(BranchExpenses::class)->listing(['branch' => 'f:100', 'from' => '2026-10-17', 'to' => '2026-10-18', 'category' => 'purchase_pepper'], actor());
$listedIds = array_column($listing['items'], 'id');
check(in_array($legacyId, $listedIds, true) && in_array($expense['id'], $listedIds, true), 'shared material filter includes historical alias and new canonical expenses');
$otherListing = app(BranchExpenses::class)->listing(['branch' => 'f:101', 'from' => '2026-10-17', 'to' => '2026-10-18'], actor(11));
check($listing['active_categories'] === $otherListing['active_categories'], 'Mahalla and Mansoura receive exactly the same active expense vocabulary');
foreach ([1,2] as $actorId) {
    $all=app(BranchExpenses::class)->listing(['branch'=>'all','from'=>'2026-10-17','to'=>'2026-10-18'],actor($actorId),true);
    $branches=array_column($all['branches'],'name','value');
    check(count(array_unique(array_column($all['items'],'branch')))===2,'authorized admin '.$actorId.' sees expenses from both branches');
    foreach($all['items'] as $item) check($item['category_name']===$item['category_base_name'].' — فرع '.$branches[$item['branch']], 'all-branch export/list item '.$item['number'].' identifies its actual branch for actor '.$actorId);
}
denied(fn()=>app(BranchExpenses::class)->listing(['branch'=>'all','from'=>'2026-10-17','to'=>'2026-10-18'],actor()),403,'branch staff still cannot list all branches');
check(!$renamedExpense['can_edit'],'renaming expense choices does not enable editing recorded expenses');
check($voided['expense']['stock_quantity_kg'] === '1.25' && $voided['expense']['stock_item_name'] === 'فلفل' && $voided['expense']['stock_reversed'], 'expense response exposes original kilograms and reversed stock state');

// A shared non-stock category remains one logical item through its full lifecycle.
$cleaningKey = array_key_first($cleaning);
$cleaningItem = $categories->catalog()[$cleaningKey];
$aliasKeys = $categories->filterKeys($cleaningKey);
check(count($aliasKeys) === 2, 'non-stock branch aliases share one category identity before editing');
$deleted = $categories->save(['action' => 'delete', 'key' => $cleaningKey, 'expected_revision' => $cleaningItem['revision'], 'idempotency_key' => keyFor(860)], actor(1));
check(!array_filter($deleted['active_categories'], fn ($name) => $name === 'أدوات نظافة'), 'deleting shared category cannot expose an old branch alias');
foreach ($aliasKeys as $aliasKey) check(!$categories->catalog()[$aliasKey]['active'], 'deleted branch alias '.$aliasKey.' stays inactive');
$deleteReplay = $categories->save(['action' => 'delete', 'key' => $cleaningKey, 'expected_revision' => $cleaningItem['revision'], 'idempotency_key' => keyFor(860)], actor(1));
check($deleteReplay['replayed'] && !array_filter($deleteReplay['active_categories'], fn ($name) => $name === 'أدوات نظافة'), 'delete retry preserves the whole hidden category group');
$restored = $categories->save(['name' => 'أدوات نظافة شبرا الخيمة', 'idempotency_key' => keyFor(861)], actor(1));
$restoredKeys = array_keys(array_filter($restored['active_categories'], fn ($name) => $name === 'أدوات نظافة'));
check($restoredKeys === [$cleaningKey] && DB::table('branch_expense_categories')->count() === 4, 'creating a deleted branch-qualified name reactivates one original shared key');
check($categories->filterKeys($cleaningKey) === $aliasKeys, 'category restoration preserves historical alias filters');
$renamed = $categories->save(['action' => 'update', 'key' => $cleaningKey, 'name' => 'مستهلكات التنظيف', 'expected_revision' => $restored['category']['revision'], 'idempotency_key' => keyFor(862)], actor(1));
check(!array_filter($renamed['active_categories'], fn ($name) => $name === 'أدوات نظافة') && array_keys(array_filter($renamed['active_categories'], fn ($name) => $name === 'مستهلكات التنظيف')) === [$cleaningKey], 'renaming shared category leaves one new choice and no active old aliases');
foreach ($aliasKeys as $aliasKey) check($categories->catalog()[$aliasKey]['name'] === 'مستهلكات التنظيف', 'renaming updates display name for historical alias '.$aliasKey);
denied(fn()=>$categories->save(['action'=>'update','key'=>$cleaningKey,'name'=>'توريدات المطبخ','expected_revision'=>$renamed['category']['revision'],'idempotency_key'=>keyFor(983)],actor(1)),422,'ordinary category cannot collide with a renamed material category');
$sameName = $categories->save(['name' => 'مستهلكات التنظيف - فرع المنصورة', 'idempotency_key' => keyFor(863)], actor(1));
check($sameName['category']['key'] === $cleaningKey && DB::table('branch_expense_categories')->count() === 4 && array_keys(array_filter($sameName['active_categories'], fn ($name) => $name === 'مستهلكات التنظيف')) === [$cleaningKey], 'new request for renamed branch alias reuses exactly one shared key');
check($categories->filterKeys($cleaningKey) === $aliasKeys, 'renaming preserves category group identity and historical filters');
foreach ([[$aliasKeys[0], 6000], [$aliasKeys[1], 6000], ['rent', 10000]] as [$categoryKey, $amount]) DB::table('branch_expenses')->insert(['branch' => 'f:100', 'actor_id' => 10, 'occurred_on' => '2026-09-01', 'category' => $categoryKey, 'description' => 'سجل سابق قبل توحيد البنود', 'amount_cents' => $amount, 'payment_method' => 'cash', 'status' => 'approved', 'revision' => 1]);
$groupedSummary = app(BranchExpenses::class)->listing(['branch' => 'f:100', 'from' => '2026-09-01', 'to' => '2026-09-01'], actor())['summary'];
check($groupedSummary['top_category'] === $cleaningKey && $groupedSummary['top_amount'] === '120.00', 'top expense summary combines historical alias amounts before choosing the largest category');
check($groupedSummary['period'] === '220.00' && $groupedSummary['count'] === 3, 'grouping summary preserves individual historical expenses and total amounts');

// Description is optional; normalized empty values retain the existing non-null database contract.
foreach (['missing', 'null', 'blank', 'whitespace'] as $offset => $variant) {
    $input = payload(990 + $offset, ['category' => 'water', 'quantity_kg' => null]);
    if ($variant === 'missing') unset($input['description']);
    else $input['description'] = ['null' => null, 'blank' => '', 'whitespace' => " \t\n "][$variant];
    $result = app(BranchExpenses::class)->save($input, actor());
    check($result['expense']['description'] === '' && DB::table('branch_expenses')->where('id', $result['expense']['id'])->value('description') === '', 'optional '.$variant.' description is saved as an empty string');
    $before = databaseSnapshot();
    $input['description'] = '';
    $retry = app(BranchExpenses::class)->save($input, actor());
    check($retry['replayed'] && $retry['expense']['id'] === $result['expense']['id'] && databaseSnapshot() === $before, 'optional '.$variant.' description retry normalizes to the original command without writes');
}
$stockBefore = quantityFor('f:100', 'فلفل');
$withoutDescription = payload(994, ['quantity_kg' => '0.5']);
unset($withoutDescription['description']);
$result = app(BranchExpenses::class)->save($withoutDescription, actor());
check($result['expense']['description'] === '' && quantityFor('f:100', 'فلفل') === $stockBefore + 500000, 'linked purchase accepts no description and still receives its exact kilograms');
$before = databaseSnapshot();
check(app(BranchExpenses::class)->save($withoutDescription, actor())['replayed'] && databaseSnapshot() === $before, 'linked purchase without description receives stock only once on retry');
noWrites(fn () => saveExpense(995, ['description' => '', 'quantity_kg' => null]), 422, 'optional description does not relax required purchase kilograms');
noWrites(fn () => saveExpense(995, ['description' => ['unexpected'], 'category' => 'water', 'quantity_kg' => null]), 422, 'description still rejects arrays');
noWrites(fn () => saveExpense(995, ['description' => str_repeat('ب', 501), 'category' => 'water', 'quantity_kg' => null]), 422, 'description still enforces its 500 character maximum');

echo $checks.' integration checks passed against real expense/category/inventory services'.PHP_EOL;
