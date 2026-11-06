<?php
// Render real Blade views with the installed Laravel compiler, without booting the application or its database.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$receiptFailureContext = ['fixture'=>'bootstrap', 'locale'=>'bootstrap'];
set_exception_handler(static function (Throwable $error) use (&$receiptFailureContext) {
    $origin = $error;
    while ($origin->getPrevious()) $origin = $origin->getPrevious();
    $details = $receiptFailureContext + ['status'=>'RECEIPT_RENDER_FAILED', 'exception'=>get_class($origin),
        'source'=>basename($origin->getFile()).':'.$origin->getLine()];
    if (preg_match('/^RECEIPT_CHECK_FAILED: ([A-Za-z0-9 _-]{1,140})$/D', $origin->getMessage(), $match)) $details['check']=$match[1];
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
$fixtures=require __DIR__.'/fixtures.php';
$checks=0;
function receiptCheck($condition, $message) { global $checks; if (!$condition) throw new RuntimeException('RECEIPT_CHECK_FAILED: '.$message); $checks++; }
$rendered=[];
foreach (['ar','en'] as $locale) {
    $container->locale=$locale; $translator->setLocale($locale);
    foreach ($fixtures as $name=>$fixture) {
        $receiptFailureContext = ['fixture'=>$name, 'locale'=>$locale];
        app('request')->dashboard=empty($fixture['auto_print']);
        $html=$factory->make($fixture['view'],$fixture['data'])->render();
        $rendered[$locale][$name]=$html;
        receiptCheck(str_contains($html,'</html>'),'complete document '.$name);
        receiptCheck(!preg_match('/\{\{|@include\(|@foreach\(/',$html),'compiled markup '.$name);
        receiptCheck(substr_count($html,'<html ')===1,'single document '.$name);
        if ($name !== 'app-screen') {
            receiptCheck(str_contains($html,'max-width: 72mm') && !str_contains($html,'width: 58mm'),'full printable width '.$name);
            receiptCheck(str_contains($html,'width: 80px; height: 80px'),'large logo '.$name);
            receiptCheck(!str_contains($html,'ft-brand-name'),'no duplicate brand heading '.$name);
        }
        file_put_contents($output.'/'.$locale.'-'.$name.'.html',$html);
    }
}
$ar=$rendered['ar'];
$receiptFailureContext = ['fixture'=>'assertions', 'locale'=>'ar'];
foreach (['takeaway','phone','dine','mixed','unpaid','ticket','app','legacy'] as $name) receiptCheck(str_contains($ar[$name],'<bdi>500.00</bdi>') || $name === 'legacy','saved amounts '.$name);
receiptCheck(str_contains($ar['phone'],'550.00'),'delivery total');
receiptCheck(str_contains($ar['mixed'],'CARD-DEMO-123'),'mixed payment reference');
receiptCheck(str_contains($ar['mixed'],'300.00') && str_contains($ar['mixed'],'200.00'),'mixed payment split');
receiptCheck(str_contains($ar['unpaid'],'كشف حساب غير مسدد') && !str_contains($ar['unpaid'],'<div class="ft-tender">'),'unpaid tender hidden');
receiptCheck(str_contains($ar['cancelled'],'طلب ملغي'),'cancelled state retained');
foreach (['kitchen-phone','kitchen-dine'] as $name) {
    receiptCheck(str_contains($ar[$name],'data-dashboard-receipt="pos-kitchen"'),'kitchen marker');
    receiptCheck(!str_contains($ar[$name],'<div class="ft-summary">'),'kitchen no money summary');
    receiptCheck(!str_contains($ar[$name],'500.00') && !str_contains($ar[$name],'250.00'),'kitchen no prices');
    receiptCheck(str_contains($ar[$name],'<bdi>0.5</bdi> <span class="ft-unit">') && str_contains($ar[$name],'<bdi>1</bdi> <span class="ft-unit">'),'weights retained inline');
}
receiptCheck(str_contains($ar['kitchen-dine'],'طاولة ٧'),'kitchen dining table');
receiptCheck(str_contains($ar['phone'],'شقة ٥') && !str_contains($ar['phone'],'31.1234567'),'address retained without raw GPS clutter');
receiptCheck(str_contains($ar['long'],'1,234,567.89') && str_contains($ar['long'],'1234.567'),'large values retained');
receiptCheck(str_contains($ar['long'],'&lt;script&gt;UNSAFE_FIXTURE&lt;/script&gt;') && !str_contains($ar['long'],'<script>UNSAFE_FIXTURE'),'escaped notes');
receiptCheck(str_contains($ar['app'], '<bdi>0.5</bdi>') && str_contains($ar['app'],'-20.00'),'app weight and discount');
receiptCheck(str_contains($ar['app'],'مدفوع') && !str_contains($ar['app'],'<div class="ft-tender">'),'electronic payment has no invented cash tender');
receiptCheck(str_contains($ar['app-failed'],'فشل الدفع'),'failed payment retained');
receiptCheck(str_contains($ar['app-pending'],'لم يتم الاتفاق'),'unagreed total retained');
receiptCheck(str_contains($ar['app-screen'],'data-app-invoice') && str_contains($ar['app-screen'],'order-board-detail.js'),'app screen remains interactive');
receiptCheck(str_contains($ar['legacy'],'585.00') && str_contains($ar['legacy'],'الدور') && str_contains($ar['legacy'],'شقة'),'legacy accessors and address');
receiptCheck(str_contains($ar['auto-print'],"function(){window.print();}") && !str_contains($ar['takeaway'],"function(){window.print();}"),'native auto print guard');
receiptCheck(str_contains($ar['preview'],'data-dashboard-invoice-details="1"') && !str_contains($ar['preview'],'window.print()'),'details preview never auto prints');
receiptCheck(str_contains($rendered['en']['takeaway'],'dir="ltr"'),'English direction');
foreach (['ar','en'] as $locale) {
    $receiptFailureContext = ['fixture'=>'paper-sample', 'locale'=>$locale];
    $sample=$rendered[$locale]['paper-sample'];
    preg_match('/<tbody>(.*?)<\/tbody>/s',$sample,$rows);
    receiptCheck(substr_count($rows[1] ?? '', '<tr>')===9,'one row per sample item');
    receiptCheck(!preg_match('/<br\b|<small\b/i',$rows[1] ?? ''),'no forced item line breaks');
    receiptCheck(substr_count($rows[1] ?? '', '<span class="ft-item-label">')===9,'inline sample item labels');
    receiptCheck(str_contains($sample,'TA94-S2-0017</bdi>'),'complete single sample number');
    receiptCheck(str_contains($sample,'<bdi>0.525</bdi>') && str_contains($sample,'<bdi>1.03</bdi>'),'sample quantities not rounded');
    receiptCheck(str_contains($sample,'<bdi>131.25</bdi>') && str_contains($sample,'<bdi>463.5</bdi>'),'sample amounts not rounded');
    receiptCheck(substr_count($sample,'974.75')===2 && str_contains($sample,'1,000.00') && str_contains($sample,'25.25'),'sample totals and tender unchanged');
    receiptCheck(str_contains($rendered[$locale]['paper-kitchen'],'PH94-S2-0012</bdi>'),'complete single kitchen number');
}
echo json_encode(['status'=>'PROFESSIONAL_RECEIPTS_RENDER_VERIFIED','fixtures'=>count($fixtures),'locales'=>2,'checks'=>$checks,'output'=>$output],JSON_UNESCAPED_SLASHES)."\n";
