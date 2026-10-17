<?php

// Isolated SQLite only: no Laravel bootstrap, .env, network, server, or live ERP data.
$autoload = getenv('PHONE_DELIVERY_TEST_AUTOLOAD') ?: getenv('CRM_TEST_AUTOLOAD');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
require $autoload ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (!str_starts_with($class, 'App\\Services\\')) return;
    $file = dirname(__DIR__).'/app/Services/'.str_replace('\\', '/', substr($class, 13)).'.php';
    if (is_file($file)) require_once $file;
});

use App\Services\Dashboard\{BranchDeliveryTariff, PhoneDelivery, PhoneMapProvider, PosServiceTicket};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract = null) { $container = Container::getInstance(); return $abstract ? $container->make($abstract) : $container; }
function config($key = null, $default = null) {
    if (is_array($key)) { foreach ($key as $name => $value) app('config')->set($name, $value); return; }
    return app('config')->get($key, $default);
}
function abort_unless($condition, $status, $message = '') { if (!$condition) throw new HttpException($status, $message); }
function abort_if($condition, $status, $message = '') { if ($condition) throw new HttpException($status, $message); }
function abort($status, $message = '') { throw new HttpException($status, $message); }

class PricingTestUser extends \Illuminate\Database\Eloquent\Model {
    protected $table = 'users';
    public function can($permission) { return false; }
}
class_alias(PricingTestUser::class, 'App\\Models\\User');

class PricingTestMaps extends PhoneMapProvider {
    public bool $active = false;
    public int $routeMeters = 95000;
    public int $calls = 0;
    public function enabled(): bool { return $this->active; }
    public function road(array $policy, float $lat, float $lng): array {
        $this->calls++;
        return ['meters' => $this->routeMeters, 'method' => 'road_osrm',
            'path' => [[$policy['latitude'], $policy['longitude']], [$lat, $lng]]];
    }
}

$container = new Container;
Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
$maps = new PricingTestMaps;
$container->instance(PhoneMapProvider::class, $maps);

Schema::create('users', function (Blueprint $table) {
    $table->id(); $table->string('name'); $table->string('account_type');
    $table->unsignedBigInteger('owner_resturant_id')->nullable();
    $table->string('app_scope')->default('fasakhansta'); $table->string('status')->default('accepted');
});
Schema::create('resturants', function (Blueprint $table) {
    $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('parent_id')->nullable();
    $table->string('name'); $table->string('control')->default('show');
    $table->decimal('lat', 10, 7); $table->decimal('lng', 10, 7); $table->decimal('km_price', 14, 2)->nullable();
    foreach (['default_0_1', 'default_1_2', 'default_2_3'] as $field) $table->decimal($field, 14, 2)->nullable();
});
foreach ([[10, 'Branch A'], [11, 'Branch B']] as [$id, $name]) {
    DB::table('users')->insert(['id' => $id, 'name' => $name, 'account_type' => 'vendor']);
}
$baseRates = ['km_price' => '12.00', 'default_0_1' => '10.00', 'default_1_2' => '20.00', 'default_2_3' => '30.00'];
foreach ([[100, 10, 'Branch A', null], [101, 11, 'Branch B', null], [301, 10, 'فرع شبرا الخيمة', null], [309, 19, 'المقطم ستور', 301]] as [$id, $user, $name, $parent]) {
    DB::table('resturants')->insert(['id' => $id, 'user_id' => $user, 'parent_id' => $parent,
        'name' => $name, 'lat' => 30, 'lng' => 31] + $baseRates);
}
// An unrelated global tariff must never supply the rounded-3 km price.
Schema::create('settings', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('payload'); });
DB::table('settings')->insert(['name' => 'default_2_3', 'payload' => '999.00']);

$count = 0;
function pricingCheck($condition, string $label): void {
    global $count;
    if (!$condition) throw new RuntimeException('PHONE_DELIVERY_BRANCH_PRICING_FAILED: '.$label);
    $count++;
    echo 'PASS '.$label.PHP_EOL;
}
function pricingDenied(callable $call, int $status, string $label): void {
    try { $call(); }
    catch (HttpException $exception) { pricingCheck($exception->getStatusCode() === $status, $label); return; }
    catch (\Illuminate\Validation\ValidationException $exception) { pricingCheck($status === 422, $label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
function pricingLocation(float $kilometers, string $branch = 'f:100'): array {
    return ['branch' => $branch, 'latitude' => 30 + rad2deg($kilometers / 6378.137),
        'longitude' => 31, 'location_confirmed' => true];
}

$rates = BranchDeliveryTariff::rates($baseRates);
foreach ([[0, '10.00'], [.49, '10.00'], [.5, '20.00'], [1.49, '20.00'], [1.5, '30.00'],
    [2.49, '30.00'], [2.5, '30.00'], [3.49, '30.00'], [3.5, '42.00']] as [$kilometers, $fee]) {
    $price = BranchDeliveryTariff::price($kilometers * 1000, $rates);
    pricingCheck($price['delivery_fee'] === $fee, 'app rounded-distance tariff at '.$kilometers.' km');
    pricingCheck(isset($price['pricing_rule'], $price['pricing_distance_meters'], $price['pricing_distance_km'], $price['pricing_rates']),
        'auditable tariff snapshot at '.$kilometers.' km');
}
foreach ([[0, 'default_0_1'], [500, 'default_1_2'], [1500, 'default_2_3'], [3000, 'default_2_3']] as [$meters, $tier]) {
    $price = BranchDeliveryTariff::price($meters, $rates);
    pricingCheck($price['pricing_rule'] === 'app_branch_tier' && $price['pricing_tier'] === $tier,
        'snapshot identifies registered branch tariff field at '.$meters.' meters');
}
$distancePrice = BranchDeliveryTariff::price(3500, $rates);
pricingCheck($distancePrice['pricing_rule'] === 'app_per_km' && $distancePrice['pricing_tier'] === null,
    'distance tariff is distinguishable from branch fixed tiers');
pricingCheck(BranchDeliveryTariff::price(12345.6789, $rates)['delivery_fee'] === '148.15',
    'above rounded 3 uses full unrounded distance times rate with cents rounding');
pricingCheck(BranchDeliveryTariff::rates((object) $baseRates) === $rates, 'database row and array normalize identically');
$zeroRates = BranchDeliveryTariff::rates(array_replace($baseRates, ['km_price' => '0.00']));
foreach ([0, 750, 2600, 90000] as $meters) {
    pricingCheck(BranchDeliveryTariff::price($meters, $zeroRates)['delivery_fee'] === '0.00', 'zero branch km rate is free at '.$meters.' meters');
}
$legacy = BranchDeliveryTariff::rates(['km_price' => '12.00']);
pricingCheck(BranchDeliveryTariff::price(1250, $legacy)['delivery_fee'] === '15.00', 'legacy schemas without tier columns keep branch per-kilometer tariff');
$missing = BranchDeliveryTariff::rates(['km_price' => '12.00', 'default_0_1' => null, 'default_1_2' => null, 'default_2_3' => null]);
pricingDenied(fn () => BranchDeliveryTariff::price(100, $missing), 422, 'existing null tier cannot silently become zero or a global fee');
pricingCheck(BranchDeliveryTariff::price(5000, $missing)['delivery_fee'] === '60.00', 'unused null tiers do not prevent a valid distance tariff');
$partial = BranchDeliveryTariff::rates(array_replace($baseRates, ['default_0_1' => 'bad']));
pricingDenied(fn () => BranchDeliveryTariff::price(100, $partial), 422, 'malformed selected tier fails closed');
pricingCheck(BranchDeliveryTariff::price(1000, $partial)['delivery_fee'] === '20.00', 'valid selected tier does not borrow another tier');

$actor = PricingTestUser::find(10);
$other = PricingTestUser::find(11);
$delivery = app(PhoneDelivery::class);
$location = pricingLocation(1);
$quote = $delivery->quote($location, $actor)['delivery'];
pricingCheck($quote['delivery_fee'] === '20.00', 'phone quote uses registered branch tier');
pricingCheck(abs((float) $quote['pricing_distance_meters'] - 1000) < .02, 'pricing distance matches app 6378137-meter Haversine radius');
$three = $delivery->quote(pricingLocation(3), $actor)['delivery'];
pricingCheck($three['delivery_fee'] === '30.00', 'rounded 3 uses branch tariff and ignores unrelated global settings');
pricingDenied(fn () => $delivery->quote($location, $other), 404, 'another branch account cannot quote foreign branch');
pricingDenied(fn () => $delivery->quote(array_replace($location, ['location_confirmed' => false]), $actor), 422, 'unconfirmed customer location remains blocked');

$maps->active = true;
$roadQuote = $delivery->quote($location, $actor)['delivery'];
pricingCheck($maps->calls === 1 && !empty($roadQuote['route_path']), 'road path remains available for map display');
pricingCheck($roadQuote['delivery_fee'] === '20.00' && $roadQuote['pricing_distance_meters'] === $quote['pricing_distance_meters'],
    '95-kilometer road detour cannot replace app billing distance');
pricingCheck($roadQuote['delivery_quote_hash'] === $quote['delivery_quote_hash'], 'visual road path does not invalidate the payable quote');
$maps->routeMeters = 150000;
$detour = $delivery->quote($location, $actor)['delivery'];
pricingCheck($detour['delivery_fee'] === '20.00' && $detour['pricing_distance_meters'] === $quote['pricing_distance_meters'],
    'route-provider distance changes cannot alter calculated tariff');
pricingCheck($detour['delivery_quote_hash'] === $quote['delivery_quote_hash'], 'different road distance retains the same authoritative quote hash');
$maps->active = false;

// Exercise the actual ticket delivery validation gate without creating a POS sale.
$ticketClass = new ReflectionClass(PosServiceTicket::class);
$ticketService = app(PosServiceTicket::class);
$ticketDelivery = $ticketClass->getMethod('delivery');
$values = $location + ['delivery_quote_hash' => $quote['delivery_quote_hash']];
$saved = $ticketDelivery->invoke($ticketService, $values, $actor);
pricingCheck($saved['cents'] === 2000, 'ticket trusts the authoritative branch tariff');
$manual = $ticketDelivery->invoke($ticketService, $values + ['delivery_fee_manual' => true, 'delivery_fee' => '37.25'], $actor);
pricingCheck($manual['cents'] === 3725 && $manual['snapshot']['calculated_delivery_fee'] === '20.00'
    && $manual['snapshot']['fee_mode'] === 'manual' && $manual['snapshot']['fee_actor_id'] === 10,
    'explicit per-order override preserves branch calculation and staff audit');
foreach (['default_0_1', 'default_1_2', 'default_2_3', 'km_price'] as $field) {
    $old = DB::table('resturants')->where('id', 100)->value($field);
    DB::table('resturants')->where('id', 100)->update([$field => (float) $old + 1]);
    $fresh = $delivery->quote($location, $actor)['delivery'];
    pricingCheck($fresh['delivery_quote_hash'] !== $quote['delivery_quote_hash'], 'changing '.$field.' invalidates previous tariff snapshot');
    pricingDenied(fn () => $ticketDelivery->invoke($ticketService, $values, $actor), 409,
        'ticket rejects stale '.$field.' even if selected fee stays the same');
    DB::table('resturants')->where('id', 100)->update([$field => $old]);
}

DB::table('resturants')->where('id', 309)->update(['default_0_1' => '60.00', 'default_1_2' => '70.00', 'default_2_3' => '80.00', 'km_price' => '18.00']);
$originLocation = pricingLocation(3, 'f:301') + ['delivery_origin_branch' => 'f:309'];
$origin = $delivery->quote($originLocation, $actor)['delivery'];
pricingCheck($origin['delivery_fee'] === '80.00', 'linked Cairo store supplies all its own tariff fields');
pricingCheck($origin['branch'] === 'f:301' && $origin['fulfillment_branch'] === 'f:301' && $origin['delivery_origin_branch'] === 'f:309',
    'delivery origin never changes the fulfillment and stock branch');
$originValues = $originLocation + ['delivery_quote_hash' => $origin['delivery_quote_hash']];
DB::table('resturants')->where('id', 309)->update(['default_2_3' => '81.00']);
pricingDenied(fn () => $ticketDelivery->invoke($ticketService, $originValues, $actor), 409,
    'origin store tariff change rejects its old payable quote');
DB::table('resturants')->where('id', 309)->update(['default_2_3' => '80.00']);
pricingDenied(fn () => $delivery->quote($originLocation, $other), 404, 'store origin does not bypass fulfillment branch authorization');
pricingDenied(fn () => $delivery->quote(pricingLocation(3) + ['delivery_origin_branch' => 'f:309'], $actor), 422,
    'Cairo store origin cannot attach to an unrelated fulfillment branch');
DB::table('resturants')->where('id', 309)->update(['parent_id' => 101]);
pricingDenied(fn () => $delivery->quote($originLocation, $actor), 422, 'changed store parent blocks origin use');
DB::table('resturants')->where('id', 309)->update(['parent_id' => 301, 'default_2_3' => null]);
pricingDenied(fn () => $delivery->quote($originLocation, $actor), 422, 'missing origin tier cannot borrow fulfillment or global tariff');

pricingCheck(DB::table('resturants')->where('id', 100)->first()->default_2_3 == 30,
    'tests preserve the main branch registered tariff');
echo 'PHONE_DELIVERY_BRANCH_PRICING_TESTS='.$count." PASS\n";
