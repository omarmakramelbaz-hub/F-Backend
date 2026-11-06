<?php
// Render real Blade views with the installed Laravel compiler, without booting the application or its database.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$receiptFailureContext = ['fixture'=>'bootstrap', 'locale'=>'bootstrap'];
set_exception_handler(static function (Throwable $error) use (&$receiptFailureContext) {
    $origin = $error;
    while ($origin->getPrevious()) $origin = $origin->getPrevious();
    $details = $receiptFailureContext + ['status'=>'COURIER_RECEIPT_RENDER_FAILED', 'exception'=>get_class($origin),
        'source'=>basename($origin->getFile()).':'.$origin->getLine()];
    if (preg_match('/^COURIER_CHECK_FAILED: ([A-Za-z0-9 _-]{1,140})$/D', $origin->getMessage(), $match)) $details['check']=$match[1];
    fwrite(STDERR,json_encode($details,JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
});
$root = realpath($argv[1] ?? dirname(__DIR__, 2));
$autoload = $argv[2] ?? __DIR__.'/vendor/autoload.php';
$output = $argv[3] ?? sys_get_temp_dir().'/receipt-render-'.bin2hex(random_bytes(6));
$overlay = $argv[4] ?? '';
require $autoload;
class ReceiptFixtureContainer extends \Illuminate\Container\Container {
    public string $locale = 'ar';
    public function getLocale() { return $this->locale; }
    public function publicPath($path = '') { global $root; return $root.'/public'.($path === '' ? '' : '/'.$path); }
}
class ReceiptFixtureLoader extends \Illuminate\Translation\FileLoader {
    public function load($locale, $group, $namespace = null) {
        global $overlay;
        $path=$overlay.'/resources/lang/'.$locale.'/'.$group.'.php';
        if ($overlay !== '' && ($namespace === null || $namespace === '*') && is_file($path)) return require $path;
        return parent::load($locale,$group,$namespace);
    }
}
$container = new ReceiptFixtureContainer;
\Illuminate\Container\Container::setInstance($container);
$container->instance('app', $container);
$container->instance('path.public', $root.'/public');
$container->instance('config', new \Illuminate\Config\Repository(['app'=>['locale'=>'ar','fallback_locale'=>'en']]));
$container->instance('url', new class {
    public function asset($path, $secure = null) { return 'https://receipt-fixture.invalid/'.ltrim($path,'/'); }
    public function route($name, $parameters = [], $absolute = true) { return '/fixture-route/'.$name; }
});
$container->instance('request', new class {
    public bool $dashboard = true;
    public function boolean($name) { return $name === 'dashboard_print' && $this->dashboard; }
});
if (!function_exists('app')) { function app($name = null) { $c=\Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); } }
if (!function_exists('config')) { function config($key = null, $default = null) { return app('config')->get($key, $default); } }
if (!function_exists('asset')) { function asset($path) { return app('url')->asset($path); } }
if (!function_exists('route')) { function route($name, $params = []) { return app('url')->route($name, $params); } }
if (!function_exists('public_path')) { function public_path($path = '') { return app()->publicPath($path); } }
if (!function_exists('request')) { function request() { return app('request'); } }
if (!function_exists('__')) { function __($key) { return app('translator')->get($key); } }
$files = new \Illuminate\Filesystem\Filesystem;
$compiled=$output.'/compiled'; if (!is_dir($compiled)) mkdir($compiled,0700,true);
$translator = new \Illuminate\Translation\Translator(new ReceiptFixtureLoader($files, $root.'/resources/lang'), 'ar');
$translator->setFallback('en'); $container->instance('translator', $translator);
$compiler=new \Illuminate\View\Compilers\BladeCompiler($files,$compiled);
$resolver=new \Illuminate\View\Engines\EngineResolver;
$resolver->register('blade', static fn () => new \Illuminate\View\Engines\CompilerEngine($compiler));
$paths=$overlay === '' ? [$root.'/resources/views'] : [$overlay.'/resources/views',$root.'/resources/views'];
$factory = new \Illuminate\View\Factory($resolver,new \Illuminate\View\FileViewFinder($files,$paths),new \Illuminate\Events\Dispatcher($container));
$factory->setContainer($container); $factory->share('__env',$factory); $container->instance('view',$factory);
require_once $root.'/app/Services/GoServices/Money.php';
$presenterPath='app/Services/Dashboard/PhoneDeliveryBatchReceipt.php';
require_once ($overlay !== '' ? $overlay : $root).'/'.$presenterPath;

use App\Services\Dashboard\PhoneDeliveryBatchReceipt;
use App\Services\GoServices\Money;

$checks=0;
function courierCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException('COURIER_CHECK_FAILED: '.$message);
    $checks++;
}
$base=[
    'id'=>17,'branch'=>['value'=>'f:94','name'=>'فرع المنصورة'],
    'cashier'=>['name'=>'موظف تجريبي'],'company'=>['name'=>'شركة توصيل تجريبية'],
    'courier_name'=>'مندوب تجريبي','created_at'=>'2026-11-06T18:00:00+02:00',
    'items'=>[
        ['ticket_id'=>101,'order_id'=>501,'total'=>'550.00'],
        ['ticket_id'=>102,'order_id'=>502,'total'=>'325.35'],
    ],
    'total'=>'875.35','payment_method'=>'cash','cash_received'=>'900.00','change'=>'24.65','payment_reference'=>'',
];
$receipts=[
    (object)['id'=>501,'branch'=>'f:94','channel'=>'phone','ticket_id'=>101,'total_cents'=>55000,'delivery_cents'=>5000],
    (object)['id'=>502,'branch'=>'f:94','channel'=>'phone','ticket_id'=>102,'total_cents'=>32535,'delivery_cents'=>2535],
];
$unchanged=serialize([$base,$receipts]);
$normal=PhoneDeliveryBatchReceipt::present($base,$receipts);
courierCheck($normal['orders_total']==='800.00' && $normal['delivery_total']==='75.35','separate delivery sum');
courierCheck($normal['items'][1]['total_without_delivery']==='300.00','saved order balance');
courierCheck($normal['total']===$base['total'] && $normal['cash_received']==='900.00' && $normal['change']==='24.65','collection unchanged');
courierCheck(serialize([$base,$receipts])===$unchanged,'inputs unchanged');
courierCheck(PhoneDeliveryBatchReceipt::present($base,array_reverse($receipts))===$normal,'receipt order independence');
$fixtures=['legacy-batch'=>$normal];

foreach (['free'=>0,'manual'=>3755,'one-cent'=>1] as $name=>$fee) {
    $batch=$base;$batch['items']=[$base['items'][0]];$batch['total']='550.00';
    $saved=clone $receipts[0];$saved->delivery_cents=$fee;
    $result=PhoneDeliveryBatchReceipt::present($batch,[$saved]);
    courierCheck($result['delivery_total']===Money::decimal($fee),'saved fee '.$name);
    courierCheck(Money::minor($result['orders_total'])+$fee===55000,'no double count '.$name);
    $fixtures[$name]=$result;
}

// A missing original receipt must never be interpreted as zero-cost delivery.
$missing=PhoneDeliveryBatchReceipt::present($base,[$receipts[0]]);
courierCheck(!$missing['delivery_breakdown_complete'] && $missing['delivery_total']===null,'missing source unknown');
courierCheck($missing['items'][0]['delivery']==='50.00' && $missing['items'][1]['delivery']===null,'known lines retained');
courierCheck($missing['total']==='875.35','missing source preserves total');
$fixtures['missing-source']=$missing;

foreach (['branch'=>'f:95','channel'=>'dine','ticket_id'=>103,'total_cents'=>99999,'delivery_cents'=>-1] as $field=>$value) {
    $bad=clone $receipts[1];$bad->$field=$value;
    $result=PhoneDeliveryBatchReceipt::present($base,[$receipts[0],$bad]);
    courierCheck(!$result['delivery_breakdown_complete'] && $result['items'][1]['delivery']===null,'reject mismatched '.$field);
}
foreach ([null,32536] as $fee) {
    $bad=clone $receipts[1];$bad->delivery_cents=$fee;
    courierCheck(PhoneDeliveryBatchReceipt::present($base,[$receipts[0],$bad])['delivery_total']===null,'reject unavailable or excessive fee');
}
$bad=$base;$bad['total']='875.36';
courierCheck(PhoneDeliveryBatchReceipt::present($bad,$receipts)['delivery_total']===null,'reject inconsistent batch sum');

$large=$base;$large['items']=[];$large['total']='49999999.50';$large['cash_received']=$large['total'];$large['change']='0.00';$largeReceipts=[];
for($index=0;$index<50;$index++) {
    $large['items'][]=['ticket_id'=>1000+$index,'order_id'=>2000+$index,'total'=>'999999.99'];
    $largeReceipts[]=(object)['id'=>2000+$index,'branch'=>'f:94','channel'=>'phone','ticket_id'=>1000+$index,'total_cents'=>99999999,'delivery_cents'=>99999998];
}
$fixtures['large-batch']=PhoneDeliveryBatchReceipt::present($large,$largeReceipts);
courierCheck($fixtures['large-batch']['delivery_total']==='49999999.00' && $fixtures['large-batch']['orders_total']==='0.50','large exact money');
$escaped=$normal;$escaped['courier_name']='<script>UNSAFE_COURIER</script>';$fixtures['escaped']=$escaped;

foreach (['ar','en'] as $locale) {
    $container->locale=$locale;$translator->setLocale($locale);
    foreach ($fixtures as $name=>$batch) {
        $receiptFailureContext=['fixture'=>$name,'locale'=>$locale];
        $html=$factory->make('admin.phone_orders.batch_receipt',['batch'=>$batch])->render();
        courierCheck(substr_count($html,'<html ')===1 && str_contains($html,'</html>'),'complete document '.$name);
        courierCheck(!str_contains($html,'phone_batch.') && !str_contains($html,'phone_orders.'),'translated '.$name);
        courierCheck(substr_count($html,'<td>')===count($batch['items'])*4,'four columns '.$name);
        courierCheck(str_contains($html,'<bdi>'.$batch['total'].'</bdi>'),'original total printed '.$name);
        courierCheck(str_contains($html,'<bdi>'.$batch['cash_received'].'</bdi>') && str_contains($html,'<bdi>'.$batch['change'].'</bdi>'),'tender printed '.$name);
        courierCheck(str_contains($html,'dir="'.($locale==='ar'?'rtl':'ltr').'"'),'locale direction '.$name);
        if ($batch['delivery_breakdown_complete']) {
            courierCheck(str_contains($html,'<bdi>'.$batch['delivery_total'].'</bdi>'),'delivery total printed '.$name);
            courierCheck(!str_contains($html,'class="batch-note"'),'known source no warning '.$name);
        } else {
            courierCheck(str_contains($html,'class="batch-note"') && str_contains($html,'<bdi>—</bdi>'),'missing source identified');
        }
        if ($name==='escaped') courierCheck(str_contains($html,'&lt;script&gt;UNSAFE_COURIER&lt;/script&gt;') && !str_contains($html,'<script>UNSAFE_COURIER'),'escaped names');
        file_put_contents($output.'/'.$locale.'-'.$name.'.html',$html);
    }
}
echo json_encode(['status'=>'COURIER_RECEIPT_RENDER_VERIFIED','fixtures'=>count($fixtures),'locales'=>2,'checks'=>$checks,'output'=>$output],JSON_UNESCAPED_SLASHES)."\n";
