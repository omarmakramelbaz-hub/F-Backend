<?php
// Isolated real Laravel 8 resource/Eloquent regression; never connects to production.
// RESTAURANT_TEST_VENDOR=/path/to/vendor php tests/restaurant_menu_quantity_runtime.php
require rtrim(getenv('RESTAURANT_TEST_VENDOR') ?: '', '/').'/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) require $path;
    }
});

use App\Models\Cart;
use App\Http\Resources\Api\Vendor\ResturantProductResource;
use App\Http\Resources\Api\Vendor\ResturantSingleResource;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});
function auth($guard = null) { return $GLOBALS['testGuard']; }
class FixtureGuard {
    public $currentUser;
    public function user() { return $this->currentUser; }
}
class FixtureUser {
    public function carts() { return Cart::query()->where('user_id', 7); }
}
#[AllowDynamicProperties]
class FixtureProduct {
    public function getFirstMediaUrl(...$arguments) { return ''; }
}
#[AllowDynamicProperties]
class FixtureRestaurant {
    public function getFirstMediaUrl(...$arguments) { return ''; }
    public function is_fav() { return 0; }
    public function resturant_categorys() { return collect([]); }
}

$container = new Container();
Container::setInstance($container);
$request = Request::create('/api/resturants/363', 'GET');
$container->instance('request', $request);
$database = new Manager($container);
$database->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
$database->setAsGlobal();
$database->bootEloquent();
$database->schema()->create('carts', function (Blueprint $table) {
    $table->id(); $table->integer('user_id'); $table->integer('resturant_product_id');
    // MySQL PDO returns DECIMAL as text; simulate that actual hydration contract.
    $table->string('qty'); $table->string('order_id')->nullable();
    $table->string('product_feature')->nullable(); $table->string('product_clean')->nullable();
    $table->timestamps();
});
$GLOBALS['testGuard'] = new FixtureGuard();
$GLOBALS['testGuard']->currentUser = new FixtureUser();
$product = new FixtureProduct();
foreach (['id'=>501, 'added_by'=>17, 'resturant_id'=>363, 'product_name'=>'سمك',
    'product_description'=>'', 'product_price'=>450, 'category_id'=>9,
    'status'=>'show', 'highest_rated'=>'yes', 'created_at'=>'2026-10-14T18:00:00Z'] as $key=>$value) $product->$key = $value;
$product->price = json_encode(array_fill_keys(['extra_combo','extra_large','extra_medium',
    'extra_clean','extra_clear','extra_vacuim'], 0));
$product->vendor = (object)['name'=>'فرع'];
$product->resturant = (object)['name'=>'المنصورة'];
$product->category = (object)['name'=>'أسماك'];
$product->product = (object)['id'=>100, 'name'=>'سمك', 'has_clean'=>1,
    'subcategory_id'=>null, 'subcategory'=>null, 'product_features'=>collect([])];

$restaurant = new FixtureRestaurant();
foreach (['id'=>363,'user_id'=>17,'name'=>'المنصورة','effective_status'=>'opened',
    'avg_rate'=>0,'address'=>'المنصورة','delivery_time'=>'60 دقيقة','lat'=>'31.04',
    'lng'=>'31.38','service_fees'=>10,'close_at'=>'23:59','open_at'=>'00:00',
    'min_order_price'=>100,'km_price'=>12,'default_0_1'=>50,'default_1_2'=>50,
    'default_2_3'=>50,'created_at'=>'2026-10-14T18:00:00Z','under_contract'=>'no'] as $key=>$value) $restaurant->$key = $value;
$restaurant->user = (object)['name'=>'فرع','email'=>'fixture@example.test'];
$restaurant->resturant_areas = collect([]);
$restaurant->resturant_products = collect([$product]);
$restaurant->resturant_highest_rated_products = collect([$product]);

$assertions = 0;
function check($condition, $label) {
    $GLOBALS['assertions']++;
    if (!$condition) throw new RuntimeException($label);
}
function resolveJson($resource) {
    return json_decode(json_encode($resource->resolve($GLOBALS['request']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}
function checkLegacyItem(array $item, $expected, $precise) {
    // The installed Dart models assign these values directly to int? fields.
    check($item['latest_order_qty'] === $expected, 'legacy whole/null quantity contract');
    check($item['latest_order_qty'] === null || is_int($item['latest_order_qty']), 'installed app int? compatibility');
    check($item['latest_order_quantity'] === $precise, 'precise additive quantity');
    check($item['latest_order_id'] === 1007, 'latest order id remains integer');
    check($item['latest_order_product_feature'] === 3, 'latest feature id remains integer');
}

foreach (['1.000'=>[1,1], '2.000'=>[2,2], '0.500'=>[null,0.5],
    '0.250'=>[null,0.25], '1.125'=>[null,1.125]] as $stored=>[$expected,$precise]) {
    Cart::query()->delete();
    Cart::query()->create(['user_id'=>7,'resturant_product_id'=>501,'qty'=>$stored,
        'order_id'=>'1007','product_feature'=>'3','product_clean'=>'clean']);
    // A newer row belonging to another customer must not affect personal hints.
    Cart::query()->create(['user_id'=>8,'resturant_product_id'=>501,'qty'=>'99.000',
        'order_id'=>'9999','product_feature'=>'6','created_at'=>'2099-01-01 00:00:00']);
    $rawBefore = Cart::query()->get()->map->getRawOriginal()->all();
    checkLegacyItem(resolveJson(new ResturantProductResource($product)), $expected, $precise);
    $details = resolveJson(new ResturantSingleResource($restaurant));
    check(count($details['resturant_items']) === 1, 'restaurant menu survives logged-in history');
    checkLegacyItem($details['resturant_items'][0], $expected, $precise);
    checkLegacyItem($details['highest_rated'][0], $expected, $precise);
    check(Cart::query()->get()->map->getRawOriginal()->all() === $rawBefore, 'stored orders/weights unchanged');
}
$GLOBALS['testGuard']->currentUser = null;
$guest = resolveJson(new ResturantProductResource($product));
foreach (['latest_order_qty','latest_order_quantity','latest_order_id','latest_order_product_feature'] as $key) {
    check($guest[$key] === null, 'guest personal history stays null');
}
echo 'Restaurant menu quantity regression: '.$assertions." assertions passed\n";
