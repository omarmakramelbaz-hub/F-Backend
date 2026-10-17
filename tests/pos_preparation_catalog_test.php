<?php
// Uses isolated dependencies and reflection fixtures; never boots the app or reads .env.
require getenv('POS_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\Services\\')) {
        $file = dirname(__DIR__).'/app/Services/'.str_replace('\\', '/', substr($class, 13)).'.php';
        if (is_file($file)) require_once $file;
    }
});

use App\Services\Dashboard\{TakeawayAccess, TakeawayCatalog};
use Illuminate\Container\Container;
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract = null) { $app = Container::getInstance(); return $abstract ? $app->make($abstract) : $app; }
function abort_unless($condition, $code, $message = '') { if (!$condition) throw new HttpException($code, $message); }
class PreparationApplication extends Container
{
    public string $locale = 'ar';
    public function getLocale() { return $this->locale; }
}
class PreparationAccess extends TakeawayAccess
{
    public function has(string $table, string $column): bool
    {
        return ($table === 'resturant_products' && $column === 'product_name')
            || ($table === 'product_features' && $column === 'product_id');
    }
}
$app = new PreparationApplication;
Container::setInstance($app);
$catalog = new TakeawayCatalog(new PreparationAccess);
$present = new ReflectionMethod(TakeawayCatalog::class, 'present');
$present->setAccessible(true);
$features = new ReflectionProperty(TakeawayCatalog::class, 'listingFeatures');
$features->setAccessible(true);
$features->setValue($catalog, [
    10 => (object) ['id'=>10, 'product_id'=>7, 'name'=>'half'],
    11 => (object) ['id'=>11, 'product_id'=>7, 'name'=>'quarter'],
]);
$branch = ['kind'=>'f'];
$row = (object) ['id'=>1, 'product_id'=>7, 'product_name'=>'رنجة', 'product_price'=>'450.00', 'status'=>'show',
    'price'=>json_encode(['extra_clean'=>'20.00', 'extra_clear'=>'50.00', 'extra_vacuim'=>'5.00'])];
$count = 0;
function check($condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
    echo 'PASS '.$message.PHP_EOL;
}
function productFor(array $extras): array {
    global $catalog, $present, $branch, $row;
    $fixture = clone $row;
    $fixture->price = json_encode($extras);
    return $present->invoke($catalog, $branch, $fixture, '/test-fish.png');
}
function lineFor(array $extras, array $item = []): array {
    global $catalog, $branch, $row;
    $fixture = clone $row;
    $fixture->price = json_encode($extras);
    return $catalog->line($branch, $fixture, $item + ['option_id'=>'', 'quantity_mode'=>'weight', 'quantity_millis'=>1000]);
}

$product = $present->invoke($catalog, $branch, $row, '/test-fish.png');
$preparations = $product['preparation_options'];
check(array_column($preparations, 'id') === ['', 'f:0:extra_clean', 'f:0:extra_clear'], 'three preparations retain trusted option identities in whole, cleaned, filleted order');
check(array_column($preparations, 'label') === ['كامل', 'تنظيف', 'مخلي'], 'Arabic preparation labels distinguish filleted from cleaned');
check(array_column($preparations, 'price') === ['450.00', '470.00', '500.00'], 'preparations show the full base price plus their configured extra');
check(array_column($preparations, 'feature_id') === [0, 0, 0]
    && array_column($preparations, 'product_clean') === ['', 'extra_clean', 'extra_clear'], 'preparation choices never contain a fractional feature or vacuum');
$legacy = array_column($product['options'], null, 'id');
check(isset($legacy['f:10:base'], $legacy['f:11:extra_clear'], $legacy['f:0:extra_vacuim'])
    && $legacy['f:11:extra_clear']['price'] === '125.00', 'legacy options retain fractions and vacuum at their existing prices');
check($legacy['f:0:extra_clear']['label'] === 'مخلي', 'existing extra_clear option uses the correct filleted label');
check(array_column(productFor(['extra_clean'=>0, 'extra_clear'=>null])['preparation_options'], 'price') === ['450.00', '450.00'], 'a configured zero-cost preparation is available while null is absent');
check(array_column(productFor(['extra_clean'=>'', 'extra_clear'=>'25.50'])['preparation_options'], 'id') === ['', 'f:0:extra_clear'], 'only configured preparations are shown when the cleaned price is empty');
check(count(productFor([])['preparation_options']) === 1, 'a product without preparation extras offers whole only');
foreach (['not-a-price', '-1.00', '1000000.01', '1.001'] as $invalid) {
    check(count(productFor(['extra_clean'=>$invalid, 'extra_clear'=>$invalid])['preparation_options']) === 1,
        'invalid preparation prices are excluded: '.$invalid);
}
$invalidBase = clone $row;
$invalidBase->product_price = 'invalid';
$invalidProduct = $present->invoke($catalog, $branch, $invalidBase, '');
check($invalidProduct['available'] === false && $invalidProduct['preparation_options'] === [], 'invalid base price offers no purchasable preparation');
$invalidBase->product_price = '-1.00';
$invalidProduct = $present->invoke($catalog, $branch, $invalidBase, '');
check($invalidProduct['available'] === false && $invalidProduct['preparation_options'] === [], 'a positive extra cannot make a product with an invalid negative base price purchasable');
$app->locale = 'en';
check(array_column(productFor(['extra_clean'=>0, 'extra_clear'=>0])['preparation_options'], 'label') === ['Whole', 'Cleaned', 'Filleted'], 'preparation labels follow the current application locale');
$app->locale = 'ar';
$go = (object) ['id'=>9, 'name'=>'Go product', 'available'=>true, 'price_cents'=>1999, 'unit'=>'piece',
    'options'=>json_encode([['id'=>'large', 'label'=>'Large', 'price_cents'=>2999]])];
$goProduct = $present->invoke($catalog, ['kind'=>'gs'], $go, '');
check(!array_key_exists('preparation_options', $goProduct)
    && $goProduct['price'] === '19.99'
    && $goProduct['options'] === [['id'=>'large', 'label'=>'Large', 'price'=>'29.99']], 'Go products preserve their existing catalog contract');
$line = $catalog->line($branch, $row, ['option_id'=>'f:0:extra_clean', 'quantity_mode'=>'weight', 'quantity_millis'=>250,
    'unit_price'=>'0.01', 'unit_price_cents'=>1, 'price'=>'0.01']);
check($line['option_id'] === 'f:0:extra_clean' && $line['unit_price'] === '470.00'
    && $line['quantity'] === '0.250' && $line['total'] === '117.50', 'selected preparation uses server price before applying the requested weight');
$whole = $catalog->line($branch, $row, ['option_id'=>'', 'quantity_mode'=>'weight', 'quantity_millis'=>500]);
check($whole['option_id'] === '' && $whole['option_label'] === 'كامل'
    && $whole['unit_price'] === '450.00' && $whole['total'] === '225.00', 'whole has its invoice label while retaining empty base option identity and weighted pricing');
check(lineFor(['extra_clean'=>0])['option_label'] === 'كامل'
    && lineFor(['extra_clear'=>'0.00'])['option_label'] === 'كامل', 'a valid zero-cost cleaned or filleted choice still labels the base as whole');
$explicitBase = lineFor(['extra_clean'=>'20.00'], ['option_id'=>'f:0:base']);
check($explicitBase['option_id'] === '' && $explicitBase['option_label'] === 'كامل', 'an explicit legacy base option normalizes to the same whole identity');
foreach ([[], ['extra_vacuim'=>'5.00'], ['extra_clean'=>null, 'extra_clear'=>'']] as $extras) {
    check(lineFor($extras)['option_label'] === '', 'plain, vacuum-only and unconfigured SKUs have no whole preparation label: '.json_encode($extras));
}
$drink = clone $row;
$drink->product_id = null;
$drink->product_name = 'مياه';
$drink->product_price = '20.00';
$drink->price = '{}';
$drinkLine = $catalog->line($branch, $drink, ['quantity_mode'=>'piece', 'quantity_millis'=>1000]);
check($drinkLine['option_id'] === '' && $drinkLine['option_label'] === '' && $drinkLine['total'] === '20.00', 'a drink without preparation extras keeps a blank invoice option label');
foreach (['not-a-price', '-1.00', '1000000.01', '1.001'] as $invalid) {
    check(lineFor(['extra_clean'=>$invalid, 'extra_clear'=>$invalid])['option_label'] === '',
        'invalid preparation configuration cannot label a plain base as whole: '.$invalid);
}
$overLimit = clone $row;
$overLimit->product_price = '1000000.00';
$overLimit->price = json_encode(['extra_clean'=>'0.01']);
check($catalog->line($branch, $overLimit, ['quantity_mode'=>'weight', 'quantity_millis'=>1000])['option_label'] === '',
    'a preparation whose combined price exceeds the limit does not label the base as whole');
$half = $catalog->line($branch, $row, ['option_id'=>'f:10:base', 'quantity_mode'=>'weight', 'quantity_millis'=>1000]);
$quarter = $catalog->line($branch, $row, ['option_id'=>'f:11:base', 'quantity_mode'=>'weight', 'quantity_millis'=>1000]);
check($half['option_id'] === 'f:10:base' && $half['option_label'] === 'نصف' && $half['unit_price'] === '225.00'
    && $quarter['option_id'] === 'f:11:base' && $quarter['option_label'] === 'ربع' && $quarter['unit_price'] === '112.50',
    'legacy half and quarter lines retain their own labels, identities and prices');
$filleted = lineFor(['extra_clear'=>'50.00'], ['option_id'=>'f:0:extra_clear']);
check($line['option_label'] === 'تنظيف' && $filleted['option_id'] === 'f:0:extra_clear' && $filleted['option_label'] === 'مخلي'
    && $filleted['unit_price'] === '500.00', 'cleaned and filleted invoice labels and identities remain distinct from whole');
$app->locale = 'en';
check(lineFor(['extra_clean'=>'20.00'])['option_label'] === 'Whole', 'whole invoice label follows application locale');
$app->locale = 'ar';
$goLine = $catalog->line(['kind'=>'gs'], $go, ['option_id'=>'large', 'quantity_mode'=>'select', 'quantity_millis'=>2000]);
check($goLine['unit_price'] === '29.99' && $goLine['total'] === '59.98', 'Go selected-option quantity pricing is unchanged');
$goBase = $catalog->line(['kind'=>'gs'], $go, ['option_id'=>'', 'quantity_mode'=>'piece', 'quantity_millis'=>1000]);
check($goBase['option_id'] === '' && $goBase['option_label'] === '' && $goBase['unit_price'] === '19.99', 'Go base products keep their existing empty label and identity');
try {
    $catalog->line($branch, $row, ['option_id'=>'f:0:extra_clear', 'product_clean'=>'extra_clean', 'quantity_mode'=>'weight', 'quantity_millis'=>1000]);
    throw new RuntimeException('A conflicting option must not be accepted');
} catch (HttpException $error) {
    check($error->getStatusCode() === 422, 'conflicting submitted preparation fields cannot override the selected option');
}
echo $count.' POS preparation catalog checks passed'.PHP_EOL;
