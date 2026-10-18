<?php
/** Real Illuminate/SQLite policy and SKU resolution; no application bootstrap or production data. */
$autoload = getenv('STORE_FULFILLMENT_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set STORE_FULFILLMENT_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
require $autoload;
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\Services\\') !== 0) return;
    $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

use App\Services\Dashboard\FasakhanstaStoreFulfillment as Fulfillment;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

function abort_unless($condition, $status, $message = '') { if (!$condition) throw new HttpException($status, $message); }
$container = new Container; Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn()=>DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
Schema::create('resturants', function (Blueprint $t) { $t->integer('id')->primary(); $t->string('name'); $t->integer('parent_id')->nullable(); });
Schema::create('resturant_products', function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('resturant_id'); $t->integer('product_id')->nullable(); $t->string('product_name'); });
Schema::create('product_features', function (Blueprint $t) { $t->integer('id')->primary(); $t->integer('product_id'); $t->string('name'); });
DB::table('resturants')->insert([
    ['id'=>94, 'name'=>'Physical 94', 'parent_id'=>301],
    ['id'=>301, 'name'=>'شبرا', 'parent_id'=>null],
    ['id'=>307, 'name'=>'Physical 307', 'parent_id'=>null],
    ['id'=>363, 'name'=>'Physical 363', 'parent_id'=>null],
    ['id'=>309, 'name'=>'المقطم ستور', 'parent_id'=>null],
    ['id'=>310, 'name'=>'زايد ستور', 'parent_id'=>301],
    ['id'=>311, 'name'=>'التجمع ستور', 'parent_id'=>94],
    ['id'=>312, 'name'=>'Unrelated identity', 'parent_id'=>null],
    ['id'=>337, 'name'=>'  مدينة نصر  ستور  ', 'parent_id'=>0],
    ['id'=>900, 'name'=>'New store', 'parent_id'=>301],
    ['id'=>901, 'name'=>'Unrelated store', 'parent_id'=>94],
]);
DB::table('resturant_products')->insert([
    ['id'=>1001, 'resturant_id'=>309, 'product_id'=>501, 'product_name'=>'Original display label'],
    ['id'=>2001, 'resturant_id'=>301, 'product_id'=>501, 'product_name'=>'Canonical Shubra label'],
    ['id'=>2002, 'resturant_id'=>301, 'product_id'=>502, 'product_name'=>'Original display label'],
    ['id'=>1002, 'resturant_id'=>309, 'product_id'=>503, 'product_name'=>'  وجبة   رنجة  '],
    ['id'=>2003, 'resturant_id'=>301, 'product_id'=>504, 'product_name'=>'وجبة رنجة'],
    ['id'=>1003, 'resturant_id'=>309, 'product_id'=>null, 'product_name'=>'مياه'],
    ['id'=>2004, 'resturant_id'=>301, 'product_id'=>null, 'product_name'=>'مياه'],
    ['id'=>1004, 'resturant_id'=>309, 'product_id'=>505, 'product_name'=>'Unmapped'],
    ['id'=>1005, 'resturant_id'=>309, 'product_id'=>506, 'product_name'=>'Same shared ID twice'],
    ['id'=>2005, 'resturant_id'=>301, 'product_id'=>506, 'product_name'=>'Same shared ID twice'],
    ['id'=>2006, 'resturant_id'=>301, 'product_id'=>506, 'product_name'=>'Other same shared ID'],
    ['id'=>1006, 'resturant_id'=>309, 'product_id'=>null, 'product_name'=>'Duplicate name'],
    ['id'=>2007, 'resturant_id'=>301, 'product_id'=>507, 'product_name'=>'Duplicate name'],
    ['id'=>2008, 'resturant_id'=>301, 'product_id'=>508, 'product_name'=>'Duplicate name'],
    ['id'=>1007, 'resturant_id'=>309, 'product_id'=>509, 'product_name'=>'علبة 200 جرام'],
    ['id'=>2009, 'resturant_id'=>301, 'product_id'=>510, 'product_name'=>'علبة 400 جرام'],
    ['id'=>9999, 'resturant_id'=>901, 'product_id'=>501, 'product_name'=>'Foreign SKU'],
]);
DB::table('product_features')->insert([
    ['id'=>50, 'product_id'=>501, 'name'=>'half'],
    ['id'=>51, 'product_id'=>503, 'name'=>'quarter'],
    ['id'=>52, 'product_id'=>504, 'name'=>'quarter'],
    ['id'=>53, 'product_id'=>503, 'name'=>'large'],
    ['id'=>54, 'product_id'=>504, 'name'=>'duplicate'],
    ['id'=>55, 'product_id'=>504, 'name'=>'duplicate'],
    ['id'=>56, 'product_id'=>503, 'name'=>'duplicate'],
]);

$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; echo 'PASS '.$message.PHP_EOL; }
function denied($callback, $message, $contains = null) {
    try { $callback(); } catch (HttpException $error) { check($error->getStatusCode() === 422 && $error->getMessage() !== '' && ($contains === null || strpos($error->getMessage(),$contains) !== false), $message); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
function databaseSnapshot() {
    return json_encode(array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->all(), ['resturants','resturant_products','product_features']));
}
$before = databaseSnapshot();
DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
check(Fulfillment::branch('gs:309') === 'gs:309', 'Go stores retain their independent namespace');
foreach ([94,301,307,363] as $id) check(Fulfillment::branch('f:'.$id) === 'f:'.$id, 'physical branch '.$id.' remains independent');
foreach (['all','f:0309','f:-309','f:309extra'] as $value) check(Fulfillment::branch($value) === $value, 'noncanonical branch '.$value.' is not coerced');
check(DB::connection()->getQueryLog() === [], 'Go stores, physical branches and invalid selectors need no metadata queries');
check(Fulfillment::branch('f:309') === 'f:301', 'legacy registered store with no parent fulfills through Shubra');
check(Fulfillment::branch('f:337') === 'f:301', 'registered store names accept whitespace normalization');
check(Fulfillment::restaurantId(310) === 301 && Fulfillment::restaurantId(900) === 301, 'current and newly created direct children fulfill through Shubra');
foreach ([311,312,901,999] as $id) check(Fulfillment::restaurantId($id) === $id, 'unrelated or missing restaurant '.$id.' is never reassigned');
check(Fulfillment::sourceRestaurantIdsForBranch(301) === [301,309,310,337,900], 'Shubra reporting includes only its verified stores and physical identity');
check(Fulfillment::sourceRestaurantIdsForBranch(309) === [], 'store sales cannot also be counted as their own branch');
check(Fulfillment::sourceRestaurantIdsForBranch(94) === [94] && Fulfillment::sourceRestaurantIdsForBranch(311) === [311], 'other physical and unrelated accounting branches retain their own orders');

$line = ['product_id'=>1001,'name'=>'Customer-facing name','feature_id'=>50,'option_id'=>'f:50:extra_clean','option_label'=>'نصف / تنظيف',
    'quantity_millis'=>2000,'unit_price_cents'=>1234,'total_cents'=>2468,'inventory'=>['branch'=>'f:309','product_id'=>1001]];
$mapped = Fulfillment::inventoryLines('f:309', [$line])[0];
check($mapped['product_id'] === 2001 && $mapped['feature_id'] === 50, 'shared product identity wins over an unrelated name match');
check($mapped['origin_branch'] === 'f:309' && $mapped['origin_product_id'] === 1001 && $mapped['origin_feature_id'] === 50, 'inventory lines preserve the original store and SKU identity for audit');
check(!isset($mapped['inventory']), 'store recipe snapshots must be rebuilt for the fulfillment branch');
foreach (['name','option_id','option_label','quantity_millis','unit_price_cents','total_cents'] as $field) check($mapped[$field] === $line[$field], 'inventory translation preserves original '.$field);
$fallback = Fulfillment::inventoryLines('f:309', [['product_id'=>1002,'feature_id'=>51,'option_id'=>'f:51:base','quantity_millis'=>1000]])[0];
check($fallback['product_id'] === 2003 && $fallback['feature_id'] === 52 && $fallback['option_id'] === 'f:52:base', 'normalized exact name fallback translates feature and POS option IDs to the owning product');
$appLine = Fulfillment::inventoryLines('f:309', [['product_id'=>1002,'feature_id'=>51,'quantity_millis'=>1000]])[0];
check($appLine['feature_id'] === 52 && !array_key_exists('option_id',$appLine), 'app cart feature mapping works without a POS option ID');
check(Fulfillment::inventoryLines('f:309', [['product_id'=>1003]])[0]['product_id'] === 2004, 'simple products without a canonical product ID match exactly by name');
foreach (['f:301','f:94','gs:309','f:901'] as $origin) check(Fulfillment::inventoryLines($origin, [$line]) === [$line], 'unrouted '.$origin.' inventory lines stay byte-for-byte equivalent');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>9999]]), 'foreign source SKUs fail instead of taking another restaurant product');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1004]]), 'unmapped products fail clearly before stock deduction');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1005]]), 'duplicate shared-product links fail instead of falling back to an arbitrary matching name');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1006]]), 'duplicate normalized names fail before stock deduction');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1007]]), 'different can weights are never treated as the same product');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1002,'feature_id'=>50]]), 'a feature belonging to another source product is rejected');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1002,'feature_id'=>53]]), 'missing destination portion does not silently become the base portion');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1002,'feature_id'=>56]]), 'duplicate destination portions fail clearly');
denied(fn()=>Fulfillment::inventoryLines('f:309', [['product_id'=>1002,'feature_id'=>51,'option_id'=>'f:53:base']]), 'conflicting source feature and option identifiers are rejected');
check(databaseSnapshot() === $before, 'policy, reports and SKU mapping never mutate restaurant ownership, catalogs or features');
DB::table('resturants')->where('id',309)->update(['parent_id'=>94]);
check(Fulfillment::branch('f:309') === 'f:309' && !in_array(309,Fulfillment::sourceRestaurantIdsForBranch(301),true), 'live ownership changes are respected without stale process caches');
DB::table('resturants')->where('id',301)->delete();
denied(fn()=>Fulfillment::inventoryLines('f:310', [['product_id'=>1001]]), 'a missing physical recipient fails before resolving or deducting any stock', 'فرع شبرا غير متاح');
echo $checks.' store fulfillment integration checks passed'.PHP_EOL;
