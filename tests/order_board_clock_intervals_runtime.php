<?php
/** Production clock, observer, completion money and GO transitions on isolated SQLite.
 * ORDER_BOARD_TEST_VENDOR=/path/vendor php tests/order_board_clock_intervals_runtime.php
 */
namespace {
    $vendor = getenv('ORDER_BOARD_TEST_VENDOR'); require rtrim($vendor, '/').'/autoload.php';
    function app($abstract = null) { $app = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function auth($guard = null) { return new class { public function user() { return null; } public function check() { return false; } }; }
    function abort($status, $message = '') { throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_unless($value, $status, $message = '') { if (!$value) abort($status, $message); }
    function abort_if($value, $status, $message = '') { if ($value) abort($status, $message); }
    spl_autoload_register(function ($class) { if (str_starts_with($class, 'App\\')) { $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php'; if (is_file($file)) require $file; } });
}
namespace App\Models {
    // Identity models exclude unrelated JWT/media/settings plugins; production Order remains real.
    class User extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
    class Resturant extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; public function user() { return $this->belongsTo(User::class); } }
    class CouponWheel extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
}
namespace App\Services\Dashboard {
    // Inventory rules have their own real recipe tests; record the completion boundary here.
    class BranchStock { public function appSale(\App\Models\Order $order): void { \Illuminate\Support\Facades\DB::table('fixture_stock_sales')->insertOrIgnore(['order_id'=>$order->id]); } }
    class OrderProviderDelivery { public function event($event, $id): void {} }
}
namespace App\Services { class OrderBroadcastService { public static function complete($order): void {} public static function outForDelivery($order): void {} } }
namespace App\Events { class OrderStatusUpdated { public function __construct($order) {} } }
namespace App\Notifications {
    class NotifyUserOrderStatusUpdatedNotification { public function __construct($order) {} }
    class NotifyOrderPriceTransferToWalletNotification { public function __construct($order, $amount) {} }
    class GoStoreOrderNotice { public function __construct($order, $toStore) {} }
}
namespace {
    use App\Models\Order;
    use App\Services\Dashboard\OrderBoardClock;
    use App\Services\Dashboard\GoStoreBoardActions;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;
    use Carbon\Carbon;
    $app = new class extends Container { public function runningInConsole() { return true; } }; Container::setInstance($app);
    $db = new Capsule($app); $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $db->setEventDispatcher(new \Illuminate\Events\Dispatcher($app)); $db->setAsGlobal(); $db->bootEloquent();
    $db->getConnection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
    $app->instance('db', $db->getDatabaseManager()); $app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
    $app->instance('config', new \Illuminate\Config\Repository(['app'=>['timezone'=>'UTC'],'settings'=>['cache'=>['enabled'=>false]],'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]]]));
    $app->instance('log', new class { public function warning($message, $context = []) {} });
    $app->instance('notification', new class { public function send($users, $notification) {} });
    Facade::setFacadeApplication($app); class_alias(DB::class, 'DB'); class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
    Schema::create('users', function (Blueprint $t) { $t->id(); foreach (['name','email','account_type','app_scope','status','connected'] as $f) $t->string($f)->nullable(); $t->decimal('balance',14,2)->default(0); $t->decimal('delegate_fees',6,2)->nullable(); $t->dateTime('expiration_date')->nullable(); $t->timestamps(); });
    Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('name')->default('branch'); });
    Schema::create('orders', function (Blueprint $t) { $t->id(); foreach (['resturant_id','user_id','delegate_id','coupon_wheel_id'] as $f) $t->integer($f)->nullable(); foreach (['type','status','payment_type','accepted_notify','delegate_from_out','transfer_price_by','reason','order_no'] as $f) $t->string($f)->nullable(); foreach (['vendor_tax','user_tax','delivery_price'] as $f) $t->decimal($f,14,2)->default(0); $t->timestamps(); });
    Schema::create('carts', function (Blueprint $t) { $t->id(); $t->integer('order_id'); $t->decimal('price',14,2); $t->decimal('qty',10,3)->default(1); $t->decimal('updated_total',14,2)->nullable(); });
    Schema::create('wallets', function (Blueprint $t) { $t->id(); foreach (['from_user','to_user','order_id'] as $f) $t->integer($f)->nullable(); foreach (['status','payment','type'] as $f) $t->string($f); $t->decimal('amount',14,2); $t->string('transfer_reference')->nullable()->unique(); $t->timestamps(); });
    Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->text('payload'); });
    Schema::create('payments', function (Blueprint $t) { $t->id(); $t->integer('order_id'); $t->integer('user_id'); $t->string('status'); $t->decimal('total_price',14,2)->default(0); });
    Schema::create('coupon_wheels', function (Blueprint $t) { $t->id(); $t->date('start_date')->nullable(); $t->date('end_date')->nullable(); });
    Schema::create('fixture_stock_sales', function (Blueprint $t) { $t->integer('order_id')->unique(); });
    Schema::create('order_board_clocks', function (Blueprint $t) { $t->id(); $t->string('source'); $t->integer('order_id'); $t->dateTime('accepted_at'); $t->dateTime('courier_at')->nullable(); $t->dateTime('closed_at')->nullable(); $t->timestamps(); $t->unique(['source','order_id']); });
    Schema::create('go_stores', function (Blueprint $t) { $t->id(); $t->integer('user_id'); });
    Schema::create('go_store_orders', function (Blueprint $t) { $t->id(); foreach (['customer_id','store_id','commission_cents','total_cents'] as $f) $t->integer($f); foreach (['status','fulfillment','payment_method','payment_status'] as $f) $t->string($f); $t->integer('revision')->default(1); $t->string('reason')->nullable(); $t->timestamps(); });
    DB::table('users')->insert([['id'=>10,'name'=>'branch','account_type'=>'vendor','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>500,'delegate_fees'=>null],['id'=>20,'name'=>'customer','account_type'=>'user','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>200,'delegate_fees'=>null],['id'=>30,'name'=>'courier','account_type'=>'delegate','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>500,'delegate_fees'=>20],['id'=>40,'name'=>'store','account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted','balance'=>200,'delegate_fees'=>null]]);
    DB::table('resturants')->insert(['id'=>100,'user_id'=>10]); DB::table('go_stores')->insert(['id'=>1,'user_id'=>40]);
    DB::table('settings')->insert([['group'=>'general','name'=>'app_balance','payload'=>'"1000.00"'],['group'=>'general','name'=>'service_fees','payload'=>'"0"']]);
    Order::observe(\App\Observers\OrderBoardClockObserver::class);
    $checks = 0;
    function same($expected, $actual, $message) { global $checks; $checks++; if ($expected !== $actual) throw new \RuntimeException($message.': expected '.var_export($expected,true).' got '.var_export($actual,true)); }
    function at($time) { Carbon::setTestNow(Carbon::parse('2026-10-15 '.$time, 'UTC')); }
    function clock() { return app(OrderBoardClock::class); }
    function legacy(array $extra = []) { $order = Order::withoutGlobalScopes()->create($extra + ['type'=>'current','status'=>'pending','payment_type'=>'cash','user_id'=>20,'resturant_id'=>100,'vendor_tax'=>10,'delivery_price'=>30,'order_no'=>'fixture']); DB::table('carts')->insert(['order_id'=>$order->id,'price'=>100,'qty'=>1]); return $order; }
    function resetFixture() { foreach (['orders','carts','order_board_clocks','wallets','fixture_stock_sales','payments','go_store_orders'] as $table) DB::table($table)->delete(); foreach ([10=>500,20=>200,30=>500,40=>200] as $id=>$balance) DB::table('users')->where('id',$id)->update(['balance'=>$balance]); DB::table('settings')->where('name','app_balance')->update(['payload'=>'"1000.00"']); at('06:00:00'); }
    function timestamp($field, $source = 'legacy') { return DB::table('order_board_clocks')->where('source',$source)->value($field); }
    resetFixture(); $order = legacy(); $order->update(['accepted_notify'=>'yes']);
    at('06:44:59'); same(0,clock()->run()['courier'],'44:59 does not dispatch');
    at('06:45:00'); same(1,clock()->run()['courier'],'45:00 dispatches'); same('2026-10-15 06:45:00',timestamp('courier_at'),'actual auto dispatch time persisted');
    at('08:14:59'); same(0,clock()->run()['completed'],'89:59 after courier stays active');
    at('08:15:00'); same(1,clock()->run()['completed'],'90:00 after courier completes'); same('completed',$order->fresh()->status,'completed status');
    same(490.0,(float)DB::table('users')->where('id',10)->value('balance'),'cash branch fee preserved'); same(1,DB::table('wallets')->count(),'one commission ledger entry'); same(1,DB::table('fixture_stock_sales')->count(),'one inventory completion'); clock()->run(); same(1,DB::table('wallets')->count(),'retry cannot settle again');
    resetFixture(); $order=legacy(); $order->update(['accepted_notify'=>'yes']); at('12:00:00'); same(1,clock()->run()['courier'],'late worker dispatches now'); same('accepted',$order->fresh()->status,'late first run cannot instantly complete'); same(0,DB::table('wallets')->count(),'late dispatch has no settlement');
    at('13:29:59'); same(0,clock()->run()['completed'],'late courier has full 90 minutes'); at('13:30:00'); same(1,clock()->run()['completed'],'late courier deadline');
    resetFixture(); $order=legacy(); $order->update(['accepted_notify'=>'yes']); at('06:05:00'); $order->update(['status'=>'accepted','delegate_from_out'=>'in_resturant']); same('2026-10-15 06:05:00',timestamp('courier_at'),'manual dispatch observer records time');
    at('06:20:00'); $order->update(['delegate_from_out'=>'in_resturant']); clock()->courier('legacy',$order->id); same('2026-10-15 06:05:00',timestamp('courier_at'),'assignment or retry does not restart courier timer'); same('2026-10-15 06:00:00',timestamp('accepted_at'),'manual dispatch preserves acceptance');
    at('07:34:59'); same(0,clock()->run()['completed'],'manual before 90 minute boundary'); at('07:35:00'); same(1,clock()->run()['completed'],'manual own-courier boundary');
    resetFixture(); $order=legacy(['delegate_id'=>30,'delegate_from_out'=>'out_resturant']); $order->update(['accepted_notify'=>'yes']); at('06:10:00'); $order->update(['status'=>'shipped']); same('2026-10-15 06:10:00',timestamp('courier_at'),'manual assigned courier timestamp'); at('07:40:00'); same(1,clock()->run()['completed'],'actual courier completes from dispatch'); same(590.0,(float)DB::table('users')->where('id',10)->value('balance'),'cash vendor settlement preserved'); same(394.0,(float)DB::table('users')->where('id',30)->value('balance'),'cash courier settlement preserved'); same(2,DB::table('wallets')->count(),'courier ledger remains exactly once');
    resetFixture(); $order=legacy(['status'=>'shipped','accepted_notify'=>'yes']); clock()->accepted('legacy',$order->id); at('10:00:00'); same(0,clock()->run()['completed'],'historical missing courier timestamp gets conservative window'); same('2026-10-15 10:00:00',timestamp('courier_at'),'missing courier timestamp seeded now'); at('10:10:00'); clock()->run(); same('2026-10-15 10:00:00',timestamp('courier_at'),'seed only once'); at('11:30:00'); same(1,clock()->run()['completed'],'historical courier waits 90 minutes');
    resetFixture(); $order=legacy(); $order->update(['accepted_notify'=>'yes']); DB::table('orders')->where('id',$order->id)->update(['status'=>'cancelled']); at('12:00:00'); same(0,clock()->run()['completed'],'cancelled order cannot complete'); same(0,DB::table('wallets')->count(),'cancel has no timer money movement'); same(0,DB::table('fixture_stock_sales')->count(),'cancel has no stock movement'); same(true,timestamp('closed_at')!==null,'terminal order clock closes');
    resetFixture(); $order=legacy(); $order->update(['accepted_notify'=>'yes']); at('06:45:00'); clock()->run(); app(\App\Services\Dashboard\LegacyOrderCompletion::class)->complete($order->fresh()); at('12:00:00'); same(0,clock()->run()['completed'],'manual completion wins over timer'); same(1,DB::table('wallets')->count(),'manual/timer overlap no duplicate accounting');
    resetFixture(); $order=legacy(); $order->update(['accepted_notify'=>'yes']); at('06:45:00'); clock()->run(); DB::table('orders')->where('id',$order->id)->update(['status'=>'cancelled']); at('08:15:00'); same(0,clock()->run()['completed'],'cancel after dispatch prevents automatic delivery'); same(0,DB::table('wallets')->count(),'cancelled courier has no automatic settlement');
    resetFixture(); $order=legacy(['status'=>'shipped','accepted_notify'=>'yes']); clock()->accepted('legacy',$order->id); DB::table('order_board_clocks')->update(['courier_at'=>'2026-10-15 06:00:00','closed_at'=>'2026-10-15 06:01:00']); at('12:00:00'); same(0,clock()->run()['completed'],'closed clock stays skipped even if retained row appears active');
    resetFixture(); $order=legacy(['payment_type'=>'online']); $order->update(['accepted_notify'=>'yes']); DB::table('payments')->insert(['order_id'=>$order->id,'user_id'=>20,'status'=>'1','total_price'=>100]); at('12:00:00'); same(0,clock()->run()['courier'],'insufficient successful payment does not dispatch'); DB::table('payments')->insert(['order_id'=>$order->id,'user_id'=>20,'status'=>'1','total_price'=>130]); same(1,clock()->run()['courier'],'verified adequate payment starts fresh courier window'); at('13:30:00'); same(1,clock()->run()['completed'],'verified electronic order completes after courier window'); same(620.0,(float)DB::table('users')->where('id',10)->value('balance'),'retained electronic gross settlement preserved');
    resetFixture(); date_default_timezone_set('Africa/Cairo'); app('config')->set('app.timezone','Asia/Dubai'); $order=legacy(); $order->update(['accepted_notify'=>'yes']); same('2026-10-15 06:00:00',timestamp('accepted_at'),'acceptance clock uses UTC across timezones'); at('06:45:00'); same(1,clock()->run()['courier'],'UTC dispatch boundary'); at('08:15:00'); same(1,clock()->run()['completed'],'UTC courier completion boundary'); date_default_timezone_set('UTC'); app('config')->set('app.timezone','UTC');
    foreach ([['cash','cash_due',190.0],['wallet','held',340.0]] as [$method,$payment,$expected]) {
        resetFixture(); $actions=app(GoStoreBoardActions::class); $id=DB::table('go_store_orders')->insertGetId(['customer_id'=>20,'store_id'=>40,'status'=>'pending','fulfillment'=>'delivery','revision'=>1,'total_cents'=>15000,'commission_cents'=>1000,'payment_method'=>$method,'payment_status'=>$payment,'created_at'=>now(),'updated_at'=>now()]); $actions->transition($id,40,'accept',1);
        at('06:44:59'); same('preparing',DB::table('go_store_orders')->where('id',$id)->value('status'),'GO before dispatch'); clock()->run(); at('06:45:00'); same(1,clock()->run()['courier'],'GO at 45 minutes'); same('2026-10-15 06:45:00',timestamp('courier_at','store'),'GO courier timestamp'); at('08:14:59'); same(0,clock()->run()['completed'],'GO before 90 from courier'); at('08:15:00'); same(1,clock()->run()['completed'],'GO courier deadline'); same($expected,(float)DB::table('users')->where('id',40)->value('balance'),'GO retained money lifecycle'); $count=DB::table('wallets')->count(); clock()->run(); same($count,DB::table('wallets')->count(),'GO no duplicate ledger');
    }
    resetFixture(); $actions=app(GoStoreBoardActions::class); $id=DB::table('go_store_orders')->insertGetId(['customer_id'=>20,'store_id'=>40,'status'=>'pending','fulfillment'=>'delivery','revision'=>1,'total_cents'=>15000,'commission_cents'=>1000,'payment_method'=>'cash','payment_status'=>'cash_due','created_at'=>now(),'updated_at'=>now()]); $actions->transition($id,40,'accept',1); at('06:10:00'); $actions->transition($id,40,'ready',2); $actions->transition($id,40,'dispatch',3); same('2026-10-15 06:10:00',timestamp('courier_at','store'),'manual GO dispatch hook'); at('07:39:59'); same(0,clock()->run()['completed'],'manual GO before deadline'); at('07:40:00'); same(1,clock()->run()['completed'],'manual GO completes from actual dispatch');
    echo "$checks production clock/observer/accounting interval checks passed.\n";
}
