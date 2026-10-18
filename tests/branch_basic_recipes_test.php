<?php
/**
 * Real recipe-service integration checks against an isolated SQLite database.
 * No application bootstrap, .env, network, credentials, or production DB is used.
 * Run: BASIC_RECIPE_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/branch_basic_recipes_test.php
 */
$autoload = getenv('BASIC_RECIPE_TEST_AUTOLOAD') ?: (getenv('EXPENSE_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php');
if (!is_file($autoload)) throw new RuntimeException('Set BASIC_RECIPE_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
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
use App\Services\Dashboard\BranchBasicRecipes;

function app($abstract = null, array $parameters = []) { $c = Container::getInstance(); return $abstract ? $c->make($abstract, $parameters) : $c; }
function config($key = null, $default = null) { if (is_array($key)) { foreach ($key as $k => $v) app('config')->set($k, $v); return; } return app('config')->get($key, $default); }
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($value, $status, $message = '') { if (!$value) throw new HttpException($status, $message); }
function abort_if($value, $status, $message = '') { if ($value) throw new HttpException($status, $message); }
function abort($status, $message = '') { throw new HttpException($status, $message); }
function database_path($path = '') { return dirname(__DIR__).'/database/'.$path; }
class BasicRecipeApplication extends Container { public function getLocale() { return 'ar'; } }
class BasicRecipeUser extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; public function can($permission) { return false; } }
class_alias(BasicRecipeUser::class, 'App\\Models\\User');

$container = new BasicRecipeApplication;
Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'ar');
$container->instance('translator', $translator);
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
config(['app.timezone' => 'Africa/Cairo']);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18T16:00:00Z'));

Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->string('app_scope')->nullable(); $t->string('status')->default('accepted'); });
Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('name'); });
Schema::create('resturant_products', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('product_id')->nullable(); $t->string('product_name'); $t->string('prices')->default('{"base":100}'); $t->string('status')->default('show'); });
Schema::create('product_features', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->string('name'); });
Schema::create('go_stores', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('name'); });
Schema::create('pos_service_tickets', function (Blueprint $t) { $t->id(); $t->string('branch'); });
foreach ([
    '2026_10_03_140000_create_takeaway_pos.php' => 'CreateTakeawayPos',
    '2026_10_04_080000_create_branch_operations.php' => 'CreateBranchOperations',
    '2026_10_04_190000_create_branch_stock.php' => 'CreateBranchStock',
    '2026_10_04_210000_create_branch_inventory_recipes.php' => 'CreateBranchInventoryRecipes',
] as $file => $class) { require_once database_path('migrations/'.$file); (new $class)->up(); }

foreach ([[1, 'admin', null], [10, 'vendor', null], [11, 'vendor', null], [12, 'resturant_owner', 94], [13, 'vendor', null], [20, 'admin', 100], [30, 'vendor', null]] as [$id, $type, $parent]) DB::table('users')->insert(['id' => $id, 'name' => 'اختبار '.$id, 'account_type' => $type, 'owner_resturant_id' => $parent, 'app_scope' => $id === 30 ? 'go_partner' : null]);
DB::table('resturants')->insert([['id' => 94, 'user_id' => 10, 'parent_id' => null, 'name' => 'المَحَلَّة'], ['id' => 100, 'user_id' => 11, 'parent_id' => 94, 'name' => 'المنصورة'], ['id' => 101, 'user_id' => 13, 'parent_id' => null, 'name' => 'نبروه']]);
DB::table('go_stores')->insert(['id' => 1, 'user_id' => 30, 'name' => 'متجر جو']);

$checks = 0;
function check($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; echo 'PASS '.$message.PHP_EOL; }
function denied(callable $callback, $statuses, string $message): void {
    $statuses = (array) $statuses;
    try { $callback(); }
    catch (HttpException $e) { check(in_array($e->getStatusCode(), $statuses, true), $message.' (HTTP '.$e->getStatusCode().')'); return; }
    catch (ValidationException $e) { check(in_array(422, $statuses, true) && count($e->errors()) > 0, $message.' (validation)'); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
function actor(int $id = 1) { return BasicRecipeUser::findOrFail($id); }
function keyFor(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
function tablesSnapshot(array $tables): string { $out = []; foreach ($tables as $table) if (Schema::hasTable($table)) $out[$table] = DB::table($table)->orderBy('id')->get()->all(); return json_encode($out, JSON_UNESCAPED_UNICODE); }
function databaseSnapshot(): string {
    return tablesSnapshot(['users', 'resturants', 'resturant_products', 'product_features', 'stock_ingredients', 'branch_basic_recipes', 'branch_stock_recipes', 'branch_operation_commands', 'branch_stock', 'branch_stock_movements', 'branch_inventory', 'branch_inventory_movements', 'branch_recipe_sales', 'takeaway_tills', 'takeaway_orders', 'takeaway_order_items', 'takeaway_till_entries']);
}
function noWrites(callable $callback, $statuses, string $message): void { $before = databaseSnapshot(); denied($callback, $statuses, $message); check(databaseSnapshot() === $before, $message.' leaves all data unchanged'); }
function component(int $ingredient, int $units = 500000): array { return ['ingredient_id' => $ingredient, 'name' => $ingredient === 16 ? 'بصل' : 'فسيخ كيلو سمكة', 'unit' => 'kg', 'quantity_units' => $units, 'measure' => 'g', 'quantity' => (string) ($units / 1000)]; }
function putRecipe(string $branch, int $product, array $variants, int $revision = 1): int { return DB::table('branch_stock_recipes')->insertGetId(['branch' => $branch, 'product_id' => $product, 'unit' => 'kg', 'variants' => json_encode((object) $variants, JSON_UNESCAPED_UNICODE), 'revision' => $revision, 'updated_by' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]); }
function recipe(int $product, string $branch = 'f:100'): ?object { return DB::table('branch_stock_recipes')->where('branch', $branch)->where('product_id', $product)->first(); }
function applyValues(int $key, string $hash, string $branch = 'f:100'): array { return ['branch' => $branch, 'idempotency_key' => keyFor($key), 'preview_hash' => $hash]; }
function withBaseline(callable $modify, callable $test): void {
    $row = (array) DB::table('branch_basic_recipes')->where('id', 1)->first();
    $payload = json_decode($row['snapshot'], true);
    $modify($payload);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    DB::table('branch_basic_recipes')->where('id', 1)->update(['snapshot' => $json, 'snapshot_hash' => hash('sha256', $json)]);
    try { $test(); }
    finally { DB::table('branch_basic_recipes')->where('id', 1)->update($row); }
}

// Several differently named menu SKUs intentionally share one product template.
foreach ([
    [1001, 94, 501, 'وجبة فسيخ عادي'], [1002, 94, 501, 'وجبة فسيخ بلس'],
    [1003, 94, 502, 'سَلَطة رنجة'], [1004, 94, 504, 'عينة متكررة'],
    [1005, 94, 505, 'طبق غير موجود'], [1006, 94, 506, 'طبق مكون مفقود'],
    [1007, 94, 507, 'طبق حجم خاص'], [1008, 94, 508, 'اسم قديم'],
    [1009, 94, 509, 'ساندوتش بلس'], [1010, 94, 509, 'ساندوتش توب'],
    [2001, 100, 501, 'وجبة فسيخ عادي'], [2002, 100, 501, 'وجبة فسيخ بلس'],
    [2003, 100, 602, 'سلطه رنجه'], [2004, 100, 504, 'عينة متكررة'],
    [2005, 100, 504, 'عينة متكررة'], [2006, 100, 506, 'طبق مكون مفقود'],
    [2007, 100, 607, 'طبق حجم خاص'], [2008, 100, 508, 'اسم جديد'],
    [2009, 100, 509, 'اسم آخر'], [2010, 100, 610, 'خاص بالمنصورة'],
] as [$id, $restaurant, $template, $name]) DB::table('resturant_products')->insert(['id' => $id, 'resturant_id' => $restaurant, 'product_id' => $template, 'product_name' => $name, 'prices' => '{"base":'.($id + 100).'}']);
DB::table('product_features')->insert([['id' => 41, 'product_id' => 501, 'name' => 'half'], ['id' => 51, 'product_id' => 502, 'name' => 'half'], ['id' => 61, 'product_id' => 602, 'name' => 'half'], ['id' => 71, 'product_id' => 507, 'name' => 'quarter'], ['id' => 81, 'product_id' => 607, 'name' => 'half'], ['id' => 91, 'product_id' => 506, 'name' => 'half']]);
putRecipe('f:94', 1001, [0 => [component(4)], 41 => [component(4, 250000)]]);
putRecipe('f:94', 1002, [0 => [component(4, 600000)]]);
putRecipe('f:94', 1003, [0 => [component(16, 100000)], 51 => [component(16, 50000)]]);
foreach ([1004, 1005, 1008, 1009] as $id) putRecipe('f:94', $id, [0 => [component(4)]]);
putRecipe('f:94', 1006, [0 => [component(4)], 91 => [component(999999, 250000)]]);
putRecipe('f:94', 1007, [0 => [component(4)], 71 => [component(4, 125000)]]);
$existingId = putRecipe('f:100', 2001, [0 => [component(4, 700000)], 99 => [component(16, 30000)]], 7);
foreach ([2004, 2005, 2006, 2007, 2008, 2009, 2010] as $id) putRecipe('f:100', $id, [0 => [component(4, 123000)]], 4);
$unmatched = [];
foreach ([2004, 2005, 2006, 2007, 2008, 2009, 2010] as $id) $unmatched[$id] = json_encode(recipe($id));

// Sentinel history and prices must never be rewritten by recipe installation.
DB::table('branch_inventory')->insert(['branch' => 'f:100', 'ingredient_id' => 4, 'quantity_units' => 1234567, 'revision' => 5]);
DB::table('branch_stock')->insert(['branch' => 'f:100', 'product_id' => 2001, 'unit' => 'kg', 'quantity_units' => 9876543, 'revision' => 6]);
DB::table('branch_recipe_sales')->insert(['branch' => 'f:100', 'source_type' => 'pos', 'source_id' => '500', 'snapshot' => '{"historic":true}', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
DB::table('takeaway_tills')->insert(['branch' => 'f:100', 'balance_cents' => 456789, 'tax_bps' => 0, 'revision' => 2]);
$service = app(BranchBasicRecipes::class);

// Installer preflight runs before the singleton migration and has no write side effects.
$before = databaseSnapshot();
$preflight = $service->freeze(true);
check(!Schema::hasTable('branch_basic_recipes') && databaseSnapshot() === $before, 'pre-migration freeze check creates no schema or data');
check(($preflight['frozen'] ?? null) === false, 'preflight reports that the baseline is not yet frozen');
check(preg_match('/^[a-f0-9]{64}$/D', $preflight['content_hash'] ?? ''), 'preflight exposes a valid content fingerprint before migration');
check(($service->status(['branch' => 'f:100'], actor())['available'] ?? null) === false, 'uninstalled baseline status is unavailable');
noWrites(fn () => $service->apply(applyValues(1, str_repeat('a', 64)), actor()), 503, 'uninstalled baseline cannot apply');
require_once database_path('migrations/2026_10_18_190000_create_branch_basic_recipes.php');
(new CreateBranchBasicRecipes)->up();
check(DB::table('branch_basic_recipes')->count() === 0, 'migration alone does not capture or apply recipes');
DB::table('resturants')->where('id', 94)->update(['name' => 'فرع آخر']);
noWrites(fn () => $service->freeze(), [409, 422], 'capture refuses source ID 94 if its Mahalla identity does not match');
DB::table('resturants')->where('id', 94)->update(['name' => 'المَحَلَّة']);
$before = databaseSnapshot();
$candidate = $service->freeze(true);
check(databaseSnapshot() === $before && DB::table('branch_basic_recipes')->count() === 0, 'post-migration check does not capture the baseline');
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18T16:05:00Z'));
$laterCandidate = $service->freeze(true);
check($laterCandidate['content_hash'] === $candidate['content_hash'] && $candidate['content_hash'] === $preflight['content_hash'], 'candidate content hash remains stable across advancing clock and migration');
check(databaseSnapshot() === $before, 'repeated source fingerprint checks write no source or snapshot data');
$sourceRow = (array) recipe(1001, 'f:94');
$changedVariants = json_decode($sourceRow['variants'], true);
$changedVariants[0] = [component(4, 550000)];
DB::table('branch_stock_recipes')->where('id', $sourceRow['id'])->update(['variants' => json_encode((object) $changedVariants, JSON_UNESCAPED_UNICODE)]);
try {
    $changedCandidate = $service->freeze(true);
    check($changedCandidate['source']['recipe_count'] === $candidate['source']['recipe_count'] && $changedCandidate['content_hash'] !== $candidate['content_hash'], 'same-count source quantity edit changes the reviewed content fingerprint');
    noWrites(fn () => $service->freeze(false, $candidate['content_hash']), 409, 'freeze rejects source content changed after the reviewed preflight');
    check(DB::table('branch_basic_recipes')->count() === 0, 'stale content fingerprint never inserts the immutable baseline');
} finally { DB::table('branch_stock_recipes')->where('id', $sourceRow['id'])->update($sourceRow); }
noWrites(fn () => $service->freeze(false, 'invalid-content-hash'), 422, 'freeze rejects malformed supplied content fingerprint');
$restoredCandidate = $service->freeze(true);
check($restoredCandidate['content_hash'] === $candidate['content_hash'], 'restoring source quantities restores the reviewed content fingerprint');
$captured = $service->freeze(false, $restoredCandidate['content_hash']);
check(DB::table('branch_basic_recipes')->count() === 1 && DB::table('branch_basic_recipes')->where('id', 1)->value('source_branch') === 'f:94', 'capture stores exactly one Mahalla baseline');
check($captured['frozen'] && $captured['content_hash'] === $restoredCandidate['content_hash'], 'freeze accepts the reviewed unchanged content fingerprint');
$frozen = tablesSnapshot(['branch_basic_recipes']);
$source = recipe(1001, 'f:94');
DB::table('branch_stock_recipes')->where('id', $source->id)->update(['variants' => json_encode((object) [0 => [component(4, 900000)]]), 'revision' => 2]);
$service->freeze();
check(tablesSnapshot(['branch_basic_recipes']) === $frozen, 'later captures preserve the original immutable baseline despite source edits');
$frozenCheck = $service->freeze(true);
check($frozenCheck['frozen'] && $frozenCheck['content_hash'] === $captured['content_hash'], 'existing frozen check preserves reviewed content hash after live source edits');

check($service->canApply(actor(1)) && $service->canApply(actor(12)), 'admin and restaurant owner can use the baseline');
check(!$service->canApply(actor(11)), 'branch vendor cannot apply baseline recipes');
noWrites(fn () => $service->apply(applyValues(2, str_repeat('a', 64)), actor(11)), 403, 'direct vendor apply is forbidden');
noWrites(fn () => $service->status(['branch' => 'f:101'], actor(12)), [403, 404], 'owner cannot preview outside their restaurant group');
noWrites(fn () => $service->apply(applyValues(3, str_repeat('a', 64), 'f:101'), actor(12)), [403, 404], 'owner cannot apply outside their restaurant group');
noWrites(fn () => $service->status(['branch' => 'gs:30'], actor()), [403, 404], 'GO catalog cannot preview Fasakhansta recipes');
noWrites(fn () => $service->apply(applyValues(4, str_repeat('a', 64), 'gs:30'), actor()), [403, 404], 'GO catalog cannot apply Fasakhansta recipes');

$before = databaseSnapshot();
$preview = $service->status(['branch' => 'f:100'], actor());
check(databaseSnapshot() === $before, 'preview calculates matches without writing any data');
check(($preview['available'] ?? false) && preg_match('/^[a-f0-9]{64}$/D', $preview['preview_hash'] ?? ''), 'preview returns a deterministic baseline and target fingerprint');
check(($preview['summary']['matched'] ?? -1) === 3, 'only exact normalized names match, including distinct SKUs sharing one template');
check(($preview['summary']['skipped'] ?? -1) === 6, 'ambiguous names, renamed templates, absent SKUs, invalid ingredients, and absent sizes are skipped');
check($service->status(['branch' => 'f:100'], actor())['preview_hash'] === $preview['preview_hash'], 'unchanged preview fingerprint is stable');

// A recipe change between preview and apply must conflict and preserve both versions.
DB::table('branch_stock_recipes')->where('id', $existingId)->update(['revision' => 8]);
noWrites(fn () => $service->apply(applyValues(10, $preview['preview_hash']), actor()), 409, 'stale target preview cannot overwrite a newer branch recipe');
$preview = $service->status(['branch' => 'f:100'], actor());
$before = databaseSnapshot();
DB::unprepared("CREATE TEMP TRIGGER basic_recipe_write_fault BEFORE INSERT ON branch_stock_recipes WHEN NEW.branch = 'f:100' AND NEW.product_id = 2002 BEGIN SELECT RAISE(ABORT, 'isolated recipe write failure'); END");
try { $service->apply(applyValues(11, $preview['preview_hash']), actor()); throw new RuntimeException('Expected injected recipe write failure.'); }
catch (\Illuminate\Database\QueryException $e) { check(strpos($e->getMessage(), 'isolated recipe write failure') !== false, 'apply reaches injected database failure'); }
finally { DB::unprepared('DROP TRIGGER basic_recipe_write_fault'); }
check(databaseSnapshot() === $before, 'partial apply failure rolls back all recipes and command receipts together');

$protectedTables = ['resturant_products', 'product_features', 'stock_ingredients', 'branch_stock', 'branch_stock_movements', 'branch_inventory', 'branch_inventory_movements', 'branch_recipe_sales', 'takeaway_tills', 'takeaway_orders', 'takeaway_order_items', 'takeaway_till_entries'];
$protectedBefore = tablesSnapshot($protectedTables);
$sourceBefore = json_encode(DB::table('branch_stock_recipes')->where('branch', 'f:94')->orderBy('id')->get());
$result = $service->apply(applyValues(11, $preview['preview_hash']), actor());
check(($result['success'] ?? false) && !($result['replayed'] ?? true), 'valid preview applies recipes after failed operation retries');
$normal = recipe(2001);
check((int) $normal->id === $existingId && (int) $normal->revision === 9, 'matched existing recipe keeps identity and increments the current revision');
$normalVariants = json_decode($normal->variants, true);
check((int) $normalVariants[0][0]['quantity_units'] === 500000 && (int) $normalVariants[41][0]['quantity_units'] === 250000 && !isset($normalVariants[99]), 'apply replaces all variants using frozen quantities rather than later source edits');
$plus = recipe(2002);
check($plus && (int) $plus->revision === 1 && (int) json_decode($plus->variants, true)[0][0]['quantity_units'] === 600000, 'a sibling SKU sharing the template receives its own distinct baseline recipe');
$salad = recipe(2003);
$saladVariants = $salad ? json_decode($salad->variants, true) : [];
check(isset($saladVariants[0], $saladVariants[61]) && !isset($saladVariants[51]) && (int) $saladVariants[61][0]['quantity_units'] === 50000, 'unique Arabic normalized name maps to another template and remaps feature IDs by semantic name');
foreach ($unmatched as $id => $snapshot) check(json_encode(recipe($id)) === $snapshot, 'skipped or unmatched recipe '.$id.' is fully preserved');
check(tablesSnapshot($protectedTables) === $protectedBefore, 'apply preserves prices, stock balances, cash, and historical sales');
check(json_encode(DB::table('branch_stock_recipes')->where('branch', 'f:94')->orderBy('id')->get()) === $sourceBefore && tablesSnapshot(['branch_basic_recipes']) === $frozen, 'apply preserves both live Mahalla recipes and immutable baseline');
$before = databaseSnapshot();
$replay = $service->apply(applyValues(11, $preview['preview_hash']), actor());
check(($replay['replayed'] ?? false) && databaseSnapshot() === $before, 'same operation retry returns saved result without applying twice');
noWrites(fn () => $service->apply(applyValues(11, str_repeat('b', 64)), actor()), 409, 'same operation key cannot be reused with different preview data');
noWrites(fn () => $service->apply(applyValues(12, $preview['preview_hash']), actor()), 409, 'another command cannot apply the already stale pre-application preview');
$current = $service->status(['branch' => 'f:100'], actor(12));
check(($current['summary']['changed'] ?? -1) === 0 && ($current['summary']['unchanged'] ?? -1) === 3, 'new preview identifies recipes already equal to the baseline');
$beforeRecipes = tablesSnapshot(['branch_stock_recipes']);
$ownerResult = $service->apply(applyValues(13, $current['preview_hash']), actor(12));
check(($ownerResult['success'] ?? false) && tablesSnapshot(['branch_stock_recipes']) === $beforeRecipes, 'authorized owner applying equal baseline does not bump revisions');

// Every value that can change the matching or component plan invalidates the preview.
$mutationKey = 20;
foreach ([
    ['resturant_products', 2001, 'product_name', 'اسم الصنف تغير', 'target product name'],
    ['product_features', 61, 'name', 'quarter', 'target size identity'],
    ['stock_ingredients', 4, 'unit', 'piece', 'ingredient unit'],
    ['stock_ingredients', 4, 'name', 'اسم خام تغير', 'ingredient display name'],
] as [$table, $id, $column, $value, $label]) {
    $fresh = $service->status(['branch' => 'f:100'], actor());
    $row = (array) DB::table($table)->where('id', $id)->first();
    DB::table($table)->where('id', $id)->update([$column => $value]);
    try {
        check($service->status(['branch' => 'f:100'], actor())['preview_hash'] !== $fresh['preview_hash'], 'preview fingerprints changed '.$label);
        noWrites(fn () => $service->apply(applyValues($mutationKey, $fresh['preview_hash']), actor()), 409, 'stale preview rejects changed '.$label);
    } finally { DB::table($table)->where('id', $id)->update($row); }
    ++$mutationKey;
}

// Two source recipes must never compete for the same branch recipe, even if one
// could match its shared template and the other would match its unique name.
withBaseline(function (&$payload) {
    $payload['products'][] = ['id' => 1050, 'name' => 'وجبة فسيخ عادي', 'template_id' => 999, 'status' => 'show'];
    foreach ($payload['recipes'] as $recipe) if ($recipe['product_id'] === 1001) { $recipe['id'] = 999; $recipe['product_id'] = 1050; $payload['recipes'][] = $recipe; break; }
}, function () use ($service) {
    $before = tablesSnapshot(['branch_stock_recipes']);
    $collision = $service->status(['branch' => 'f:100'], actor());
    check($collision['summary']['matched'] === 2 && $collision['summary']['skipped'] === 8, 'source destination collision skips both competing recipes');
    $service->apply(applyValues(30, $collision['preview_hash']), actor());
    check(tablesSnapshot(['branch_stock_recipes']) === $before, 'source collision preserves target recipe without first-source overwrite');
});
check(tablesSnapshot(['branch_basic_recipes']) === $frozen, 'collision fixture restores original baseline exactly');

// Corrupted source variants or typed IDs skip the entire recipe, including its
// otherwise valid base variant, instead of partially replacing the target.
$malformedKey = 40;
foreach ([
    'boolean variants' => fn ($variants) => true,
    'noncanonical feature ID' => fn ($variants) => ['01' => $variants[0]],
    'string component ID' => function ($variants) { $variants[41][0]['ingredient_id'] = '4'; return $variants; },
    'array component ID' => function ($variants) { $variants[41][0]['ingredient_id'] = [4]; return $variants; },
] as $label => $malform) {
    withBaseline(function (&$payload) use ($malform) {
        foreach ($payload['recipes'] as &$recipe) if ($recipe['product_id'] === 1001) $recipe['variants'] = json_encode($malform(json_decode($recipe['variants'], true)), JSON_UNESCAPED_UNICODE);
        unset($recipe);
    }, function () use ($service, $label, $malformedKey) {
        $before = tablesSnapshot(['branch_stock_recipes']);
        $malformed = $service->status(['branch' => 'f:100'], actor());
        check($malformed['summary']['matched'] === 2 && $malformed['summary']['skipped'] === 7, $label.' skips the whole source recipe');
        $service->apply(applyValues($malformedKey, $malformed['preview_hash']), actor());
        check(tablesSnapshot(['branch_stock_recipes']) === $before, $label.' leaves complete branch recipes intact');
    });
    ++$malformedKey;
}
check(tablesSnapshot(['branch_basic_recipes']) === $frozen, 'malformed fixtures restore original baseline exactly');

// A legacy malformed target is repairable from a valid baseline without PHP
// type errors in fingerprinting, and still advances its optimistic revision.
$row = (array) recipe(2001);
DB::table('branch_stock_recipes')->where('id', $row['id'])->update(['variants' => 'true']);
try {
    $repair = $service->status(['branch' => 'f:100'], actor());
    check($repair['summary']['changed'] === 1 && $repair['summary']['matched'] === 3, 'malformed boolean target recipe is identified as needing replacement');
    $service->apply(applyValues(50, $repair['preview_hash']), actor());
    $repaired = recipe(2001);
    check((int) $repaired->revision === (int) $row['revision'] + 1 && (int) json_decode($repaired->variants, true)[0][0]['quantity_units'] === 500000, 'valid baseline safely repairs malformed boolean target recipe');
} finally { DB::table('branch_stock_recipes')->where('id', $row['id'])->update($row); }

$row = (array) DB::table('branch_basic_recipes')->where('id', 1)->first();
$valid = $service->status(['branch' => 'f:100'], actor());
DB::table('branch_basic_recipes')->where('id', 1)->update(['snapshot_hash' => str_repeat('0', 64)]);
try {
    noWrites(fn () => $service->status(['branch' => 'f:100'], actor()), 503, 'tampered baseline checksum cannot be previewed');
    noWrites(fn () => $service->apply(applyValues(60, $valid['preview_hash']), actor()), 503, 'tampered baseline checksum cannot be applied');
} finally { DB::table('branch_basic_recipes')->where('id', 1)->update($row); }
check(tablesSnapshot(['branch_basic_recipes']) === $frozen, 'checksum fixture restores original immutable baseline');

echo $checks.' integration checks passed against real baseline recipe services'.PHP_EOL;
