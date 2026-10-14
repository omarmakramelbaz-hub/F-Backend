<?php
// Actual Cart persistence, actor-scoped board reads and Blade card/detail/print option labels.
// ORDER_BOARD_TEST_VENDOR=/path/to/vendor php tests/order_board_options_runtime.php
require rtrim(getenv('ORDER_BOARD_TEST_VENDOR') ?: '', '/').'/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) require $file;
    }
});

use App\Models\Cart;
use App\Services\Dashboard\OrderBoardService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

class OptionContainer extends Container { public function getLocale() { return 'ar'; } }
function app($abstract = null) { $app = Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
function config($key, $default = null) { return app('config')->get($key, $default); }
function route($name, $parameters = []) { return '/'.$name.'/'.implode('/', $parameters); }
function asset($path) { return '/'.$path; }
function request() { return app('request'); }
function csrf_field() { return '<input type="hidden" name="_token" value="fixture">'; }
function abort_unless($value, $status, $message = '') {
    if (!$value) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message);
}
function __($key) { [$group,$name] = explode('.', $key, 2); return $GLOBALS['optionTranslations'][$group][$name] ?? $key; }
$GLOBALS['optionTranslations'] = ['main'=>require dirname(__DIR__).'/resources/lang/ar/main.php',
    'order_board'=>require dirname(__DIR__).'/resources/lang/ar/order_board.php'];

$app = new OptionContainer(); Container::setInstance($app);
$database = new Manager($app);
$database->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
$database->setAsGlobal(); $database->bootEloquent();
$app->instance('db', $database->getDatabaseManager());
$app->bind('db.schema', fn () => $database->getConnection()->getSchemaBuilder());
$app->instance('config', new \Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo','key'=>'fixture'],
    'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]]]));
$app->instance('request', \Illuminate\Http\Request::create('/orders/applies','GET',['dashboard_print'=>1]));
Facade::setFacadeApplication($app);
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('mobile'); $t->string('app_scope'); });
Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('name'); });
Schema::create('products', function (Blueprint $t) { $t->id(); $t->boolean('has_clean'); });
Schema::create('resturant_products', function (Blueprint $t) {
    $t->id(); $t->integer('resturant_id'); $t->integer('product_id'); $t->string('product_name'); $t->text('price');
});
Schema::create('product_features', function (Blueprint $t) { $t->id(); $t->integer('product_id'); $t->string('name'); });
Schema::create('orders', function (Blueprint $t) {
    $t->id(); $t->integer('resturant_id'); $t->integer('user_id'); $t->string('type'); $t->string('status');
    $t->string('payment_type'); $t->string('accepted_notify')->nullable(); $t->decimal('total_price',14,2)->default(0);
    $t->decimal('delivery_price',14,2)->default(0); $t->decimal('user_tax',14,2)->default(0); $t->timestamps();
});
Schema::create('carts', function (Blueprint $t) {
    $t->id(); $t->integer('order_id'); $t->integer('resturant_product_id'); $t->integer('product_feature')->nullable();
    $t->string('product_clean')->nullable(); $t->string('option_label')->nullable(); $t->string('name')->nullable();
    $t->decimal('qty',12,3); $t->decimal('price',14,2); $t->decimal('updated_total',14,2)->nullable(); $t->timestamps();
});
DB::table('users')->insert(['id'=>900,'name'=>'عميل','mobile'=>'01000000000','app_scope'=>'fasakhansta']);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'فرع ١'],['id'=>101,'user_id'=>11,'name'=>'فرع ٢']]);
DB::table('products')->insert([['id'=>1000,'has_clean'=>1],['id'=>1001,'has_clean'=>0],['id'=>1002,'has_clean'=>1]]);
DB::table('resturant_products')->insert([
    ['id'=>1,'resturant_id'=>100,'product_id'=>1000,'product_name'=>'فسيخ','price'=>'{"extra_clear":20,"extra_clean":10}'],
    ['id'=>2,'resturant_id'=>100,'product_id'=>1001,'product_name'=>'بيبسي','price'=>'{}'],
    ['id'=>3,'resturant_id'=>101,'product_id'=>1002,'product_name'=>'صنف فرع آخر','price'=>'{}'],
]);
$unsafe = '<img src=x onerror="alert(1)"> تجهيز قديم';
DB::table('product_features')->insert([
    ['id'=>50,'product_id'=>1000,'name'=>'half'],['id'=>51,'product_id'=>1000,'name'=>'quarter'],
    ['id'=>52,'product_id'=>1002,'name'=>'large'],['id'=>53,'product_id'=>1000,'name'=>$unsafe],
]);
foreach ([[1,100,'accepted'],[2,101,'accepted'],[3,100,'completed']] as [$id,$branch,$status])
    DB::table('orders')->insert(['id'=>$id,'resturant_id'=>$branch,'user_id'=>900,'type'=>'current','status'=>$status,
        'payment_type'=>'cash','accepted_notify'=>'yes','created_at'=>'2026-10-14 12:00:00','updated_at'=>'2026-10-14 12:00:00']);
foreach ([
    [1,1,50,'extra_clear',null], [2,1,null,'extra_clean',null], [3,1,null,null,null],
    [4,1,51,'extra_vacuim',null], [5,2,null,null,null], [6,1,null,'extra_foo',null],
    [7,1,52,'extra_clean',null], [8,1,53,'extra_clean',null], [9,1,null,'extra_clear','تجهيز قديم / مخلي'],
    [10,1,null,'extra_combo',null], [11,1,null,'extra_large',null], [12,1,null,'extra_medium',null],
] as [$id,$product,$feature,$clean,$label]) Cart::create(['id'=>$id,'order_id'=>1,'resturant_product_id'=>$product,
    'product_feature'=>$feature,'product_clean'=>$clean,'option_label'=>$label,'qty'=>'0.850','price'=>'120.00','updated_total'=>'99.50']);
Cart::create(['id'=>20,'order_id'=>2,'resturant_product_id'=>3,'product_clean'=>'extra_clean','qty'=>'1','price'=>'200']);
Cart::create(['id'=>30,'order_id'=>3,'resturant_product_id'=>999,'product_feature'=>50,'product_clean'=>'extra_clear',
    'option_label'=>'نصف محفوظ / مخلي','name'=>'صنف قديم محذوف','qty'=>'1','price'=>'450']);

$actor = (object) ['id'=>10,'account_type'=>'vendor'];
$checks = 0;
function same($expected, $actual, $label) { global $checks; $checks++; if ($expected !== $actual) throw new RuntimeException($label.': '.var_export($actual,true)); }
$before = DB::table('carts')->orderBy('id')->get()->toJson();
$connection = $database->getConnection(); $connection->enableQueryLog(); $connection->flushQueryLog();
$card = app(OrderBoardService::class)->detail('legacy',1,$actor);
$expected = ['نص / مخلي','تنظيف','كامل','ربع / فاكيوم','','','تنظيف',$unsafe.' / تنظيف',
    'تجهيز قديم / مخلي','كومبو','كبير','وسط'];
same($expected,array_column($card['items'],'option_label'),'persisted portion and preparation labels');
same('99.50',$card['items'][0]['line_total'],'saved approved amount is unchanged');
same(0.85,(float)$card['items'][0]['quantity'],'stored decimal quantity is unchanged');
same(false,str_contains(json_encode($card['items'],JSON_UNESCAPED_UNICODE),'extra_foo'),'invalid preparation key is not exposed');
same(false,str_contains($card['items'][6]['option_label'],'كبير'),'foreign feature label is not exposed');
same('نصف محفوظ / مخلي',app(OrderBoardService::class)->detail('legacy',3,$actor)['items'][0]['option_label'],
    'historical saved labels survive deleted products');
try { app(OrderBoardService::class)->detail('legacy',2,$actor); throw new RuntimeException('other branch order was visible'); }
catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { same(404,$error->getStatusCode(),'other branch order remains inaccessible'); }
foreach ($connection->getQueryLog() as $query) same(false,
    preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i',$query['query']) === 1,'option display performs no database writes');
$connection->disableQueryLog();
same($before,DB::table('carts')->orderBy('id')->get()->toJson(),'persisted selections and pricing remain exactly unchanged');

$cache = sys_get_temp_dir().'/order-option-blade-'.bin2hex(random_bytes(6)); mkdir($cache,0700);
try {
    $files = new \Illuminate\Filesystem\Filesystem();
    $compiler = new \Illuminate\View\Compilers\BladeCompiler($files,$cache);
    $resolver = new \Illuminate\View\Engines\EngineResolver();
    $resolver->register('blade',fn () => new \Illuminate\View\Engines\CompilerEngine($compiler));
    $resolver->register('php',fn () => new \Illuminate\View\Engines\PhpEngine($files));
    $views = new \Illuminate\View\Factory($resolver,new \Illuminate\View\FileViewFinder($files,[dirname(__DIR__).'/resources/views']),
        new \Illuminate\Events\Dispatcher($app));
    $cardHtml = $views->make('admin.orders.board_card',['card'=>$card])->render();
    same(true,str_contains($cardHtml,'نص / مخلي'),'card renders selected portion and deboning');
    same(true,str_contains($cardHtml,'تنظيف'),'card renders selected cleaning');
    $wholeCard = $card; $wholeCard['items'] = [$card['items'][2],$card['items'][4]];
    $wholeHtml = $views->make('admin.orders.board_card',['card'=>$wholeCard])->render();
    same(1,substr_count($wholeHtml,'class="ob-item-option"'),'whole choice is displayed while ordinary product has no suffix');
    foreach ([false,true] as $printing) {
        $html = $views->make('admin.orders.board_detail',['card'=>$card,'printing'=>$printing])->render();
        foreach (['نص / مخلي','تنظيف','كامل','ربع / فاكيوم','تجهيز قديم / مخلي'] as $label)
            same(true,str_contains($html,$label),($printing ? 'print' : 'details').' renders '.$label);
        same(false,str_contains($html,$unsafe),'feature names are HTML escaped in '.($printing ? 'print' : 'details'));
        same(true,str_contains($html,htmlspecialchars($unsafe,ENT_QUOTES,'UTF-8')),'escaped historical feature text is retained');
    }
} finally {
    foreach (glob($cache.'/*') ?: [] as $file) unlink($file);
    rmdir($cache);
}
echo 'Order board options runtime: '.$checks." assertions passed; real Cart persistence, labels, card/details/print, escaping and actor scopes verified.\n";
