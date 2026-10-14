<?php
/** Real inventory/completion regressions on Laravel 8 + SQLite; no external effects.
 * ORDER_BOARD_TEST_VENDOR=/path/to/vendor php tests/order_board_inventory_runtime.php
 * Optional ORDER_BOARD_INVENTORY_BASELINE=/path/to/pre-fix/BranchInventory.php proves the original failure.
 */
namespace {
    require rtrim(getenv('ORDER_BOARD_TEST_VENDOR') ?: '', '/').'/autoload.php';
    spl_autoload_register(function ($class) {
        if (!str_starts_with($class, 'App\\')) return;
        $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if ($class === 'App\\Services\\Dashboard\\BranchInventory' && getenv('ORDER_BOARD_INVENTORY_BASELINE')) $file = getenv('ORDER_BOARD_INVENTORY_BASELINE');
        if (is_file($file)) require $file;
    });
    function app($abstract = null) { $app = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
    function config($key, $default = null) { return app('config')->get($key, $default); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function abort_unless($value, $status, $message = '') { if (!$value) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message); }
    function abort_if($value, $status, $message = '') { abort_unless(!$value,$status,$message); }
    function auth($guard = null) { return new class { public function user() { return null; } public function check() { return false; } }; }
    class Log { public static array $warnings=[]; public static function warning($message,$context=[]) { self::$warnings[]=$message; } }
    class FixtureDelivery { public array $events=[]; public function event($event,$id) { $this->events[]=$id; } }
    class FixtureNotifications { public array $sent=[]; public function send($user,$notification) { $this->sent[]=$user->id; } }
    class_alias(\Illuminate\Support\Facades\DB::class,'DB');
}
namespace App\Models {
    // Keep real persisted party identities without JWT/media/permission package boot.
    class User extends \Illuminate\Database\Eloquent\Model { protected $guarded=[]; }
    class Resturant extends \Illuminate\Database\Eloquent\Model { protected $guarded=[]; public function user() { return $this->belongsTo(User::class); } }
    class CouponWheel extends \Illuminate\Database\Eloquent\Model { protected $guarded=[]; }
}
namespace App\Notifications {
    class NotifyUserOrderStatusUpdatedNotification { public function __construct($order) {} }
    class NotifyOrderPriceTransferToWalletNotification { public function __construct($order,$amount) {} }
}
namespace App\Events { class OrderStatusUpdated { public function __construct($order) {} } }
namespace App\Services { class OrderBroadcastService { public static array $completed=[]; public static function complete($order) { self::$completed[]=$order->id; } } }
namespace {
    use App\Models\Order;
    use App\Services\Dashboard\BranchInventory;
    use App\Services\Dashboard\BranchStock;
    use App\Services\Dashboard\LegacyOrderCompletion;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Notification;
    use Illuminate\Support\Facades\Schema;

    $app = new Container(); Container::setInstance($app);
    $database = new Manager($app);
    $database->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $database->setAsGlobal(); $database->bootEloquent();
    $app->instance('db',$database->getDatabaseManager());
    $app->bind('db.schema',fn () => $database->getConnection()->getSchemaBuilder());
    $app->instance('config',new \Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo'],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
        'settings'=>['cache'=>['enabled'=>false]]]));
    $database->getConnection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
    Facade::setFacadeApplication($app);
    $notifications = new FixtureNotifications(); Notification::swap($notifications);
    $delivery = new FixtureDelivery(); $app->instance(\App\Services\Dashboard\OrderProviderDelivery::class,$delivery);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-15 01:00:00','UTC'));
    Schema::create('users',function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('account_type');
        $t->decimal('balance',14,2)->default(1000); $t->decimal('delegate_fees',6,2)->default(0); $t->timestamps();
    });
    Schema::create('resturants',function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('name'); $t->timestamps(); });
    Schema::create('resturant_products',function (Blueprint $t) { $t->id(); $t->integer('resturant_id'); $t->integer('product_id'); $t->string('product_name'); $t->string('status')->default('show'); });
    Schema::create('product_features',function (Blueprint $t) { $t->id(); $t->integer('product_id'); $t->string('name'); });
    Schema::create('orders',function (Blueprint $t) {
        $t->id(); foreach (['resturant_id','user_id','delegate_id','coupon_wheel_id'] as $field) $t->integer($field)->nullable();
        foreach (['type','status','payment_type','accepted_notify','delegate_from_out','transfer_price_by','reason','order_no'] as $field) $t->string($field)->nullable();
        foreach (['vendor_tax','user_tax','delivery_price'] as $field) $t->decimal($field,14,2)->default(0); $t->timestamps();
    });
    Schema::create('carts',function (Blueprint $t) {
        $t->id(); $t->integer('order_id'); $t->integer('resturant_product_id'); $t->integer('product_feature')->nullable();
        $t->decimal('qty',12,3); $t->decimal('price',14,2); $t->decimal('updated_total',14,2)->nullable(); $t->timestamps();
    });
    Schema::create('wallets',function (Blueprint $t) {
        $t->id(); foreach (['order_id','from_user','to_user'] as $field) $t->integer($field)->nullable();
        foreach (['status','payment','type'] as $field) $t->string($field); $t->decimal('amount',14,2);
        $t->string('transfer_reference')->unique(); $t->timestamps();
    });
    Schema::create('settings',function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->text('payload'); });
    Schema::create('coupon_wheels',function (Blueprint $t) { $t->id(); $t->date('start_date'); $t->date('end_date'); });
    require dirname(__DIR__).'/database/migrations/2026_10_04_190000_create_branch_stock.php'; (new \CreateBranchStock())->up();
    require dirname(__DIR__).'/database/migrations/2026_10_04_210000_create_branch_inventory_recipes.php'; (new \CreateBranchInventoryRecipes())->up();
    DB::table('users')->insert([['id'=>1,'name'=>'Owner','account_type'=>'admin'],['id'=>10,'name'=>'Branch','account_type'=>'vendor'],
        ['id'=>20,'name'=>'Customer','account_type'=>'user'],['id'=>30,'name'=>'Courier','account_type'=>'delegate']]);
    DB::table('resturants')->insert([['id'=>363,'user_id'=>10,'name'=>'المحلة'],['id'=>364,'user_id'=>10,'name'=>'فرع ثانٍ']]);
    foreach (['app_balance'=>'1000.00','service_fees'=>'0','tax'=>'0'] as $name=>$value)
        DB::table('settings')->insert(['group'=>'general','name'=>$name,'payload'=>json_encode($value)]);
    DB::table('product_features')->insert([['id'=>139,'product_id'=>51,'name'=>'kilo'],['id'=>140,'product_id'=>51,'name'=>'half'],
        ['id'=>142,'product_id'=>51,'name'=>'quarter'],['id'=>141,'product_id'=>52,'name'=>'kilo']]);
    DB::table('resturant_products')->insert([
        ['id'=>2531,'resturant_id'=>363,'product_id'=>51,'product_name'=>'فسيخ نبروه 1 قطعة'],
        ['id'=>2532,'resturant_id'=>363,'product_id'=>51,'product_name'=>'وجبة صريحة'],
        ['id'=>2533,'resturant_id'=>363,'product_id'=>51,'product_name'=>'فسيخ دسوق 1 قطعة'],
        ['id'=>2534,'resturant_id'=>363,'product_id'=>51,'product_name'=>'منتج بلا ربط مخزون'],
        ['id'=>2535,'resturant_id'=>363,'product_id'=>51,'product_name'=>'وصفة بلا أساس'],
        ['id'=>2536,'resturant_id'=>363,'product_id'=>51,'product_name'=>'وصفة كاملة فارغة'],
        ['id'=>2537,'resturant_id'=>363,'product_id'=>52,'product_name'=>'منتج آخر'],
        ['id'=>2538,'resturant_id'=>364,'product_id'=>51,'product_name'=>'منتج فرع آخر'],
    ]);
    $component = ['ingredient_id'=>4,'name'=>'فسيخ كيلو سمكة','unit'=>'kg','quantity_units'=>1000000,'measure'=>'kg','quantity'=>'1'];
    function recipe($id,array $variants) { DB::table('branch_stock_recipes')->insert(['branch'=>'f:363','product_id'=>$id,'unit'=>'kg','revision'=>1,'variants'=>json_encode((object)$variants,JSON_UNESCAPED_UNICODE),'updated_by'=>1]); }
    recipe(2531,[0=>[$component]]);
    $double = $component; $double['quantity_units']=2000000; $double['quantity']='2';
    recipe(2532,[0=>[$component],139=>[$double]]);
    recipe(2535,[140=>[$component]]);
    recipe(2536,[0=>[$component],139=>[]]);
    recipe(2537,[0=>[$component]]);
    DB::table('branch_inventory')->insert([['branch'=>'f:363','ingredient_id'=>4,'quantity_units'=>20000000,'revision'=>1],
        ['branch'=>'f:364','ingredient_id'=>4,'quantity_units'=>50000000,'revision'=>1]]);
    $checks=0;
    function same($expected,$actual,$label) { global $checks; $checks++; if ($expected !== $actual) throw new \RuntimeException($label.': '.var_export($actual,true)); }
    function blocked(callable $call,$label) {
        try { $call(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { same(422,$error->getStatusCode(),$label); return; }
        throw new \RuntimeException($label.' was permitted');
    }
    function line($product=2531,$feature=139) { return ['product_id'=>$product,'feature_id'=>$feature,'name'=>'فسيخ','quantity_millis'=>880]; }
    function seedOrder($id,$product=2531,$feature=139,$status='accepted',$quantity='0.880',$courier=null): Order {
        DB::table('orders')->insert(['id'=>$id,'resturant_id'=>363,'user_id'=>20,'delegate_id'=>$courier,'type'=>'current','status'=>$status,
            'payment_type'=>'cash','accepted_notify'=>'yes','delegate_from_out'=>'in_resturant','order_no'=>'R363-'.$id,
            'vendor_tax'=>'10.00','delivery_price'=>'50.00','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('carts')->insert(['order_id'=>$id,'resturant_product_id'=>$product,'product_feature'=>$feature,'qty'=>$quantity,'price'=>'450.00',
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        return Order::withoutGlobalScopes()->findOrFail($id);
    }
    function state(): array {
        $out=[]; foreach (['orders','carts','users','settings','wallets','branch_inventory','branch_inventory_movements','branch_stock','branch_stock_movements','branch_recipe_sales'] as $table)
            $out[$table]=DB::table($table)->orderBy('id')->get()->toJson(); return $out;
    }
    function balance(): int { return (int)DB::table('branch_inventory')->where('branch','f:363')->where('ingredient_id',4)->value('quantity_units'); }
    function audit($order): array { return json_decode(DB::table('branch_recipe_sales')->where('source_type','app')->where('source_id',(string)$order)->value('snapshot'),true); }

    $inventory=app(BranchInventory::class); $completion=app(LegacyOrderCompletion::class);
    if (getenv('ORDER_BOARD_INVENTORY_BASELINE')) {
        $order=seedOrder(1); $before=state();
        blocked(fn () => $inventory->snapshots('f:363',[line()]),'baseline whole/kilo snapshot fails');
        blocked(fn () => $completion->complete($order),'baseline order completion fails');
        same($before,state(),'baseline failure mutates nothing');
        echo "Inventory baseline confirmed: owned kilo139/base0 recipe throws 422 and accepted 0.880 order cannot complete; no writes.\n";
        exit;
    }
    $quoted=app(BranchStock::class)->quoteLines('f:363',[line()])[0]['inventory'];
    same(139,$quoted['feature_id'],'quote preserves actual persisted kilo feature');
    same(0,$quoted['recipe_feature_id'],'quote records full-portion base recipe alias');
    same(1000000,$quoted['components'][0]['quantity_units'],'full portion uses accurate one-kilo component');
    $first=seedOrder(1); same(true,$completion->complete($first),'accepted branch-courier order completes');
    same('completed',DB::table('orders')->where('id',1)->value('status'),'completion status persisted');
    same(19120000,balance(),'fractional 0.880 kilo consumes exactly 880000 units');
    same(139,audit(1)['lines'][0]['recipe']['feature_id'],'sale audit retains actual kilo feature id');
    same(0,audit(1)['lines'][0]['recipe']['recipe_feature_id'],'sale audit records aliased recipe variant');
    same([['ingredient_id'=>4,'quantity_units'=>880000]],audit(1)['deductions'],'sale audit has exact fractional deduction');
    same(1,DB::table('wallets')->where('order_id',1)->count(),'real commission wallet entry created once');
    same(990.0,(float)DB::table('users')->where('id',10)->value('balance'),'real branch cash commission settles');
    same('1010.00',json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true),'app commission balance settles');
    $after=state(); same(false,$completion->complete($first),'repeat completion is idempotent'); same($after,state(),'repeat completion writes nothing');

    $shipped=seedOrder(2,2531,139,'shipped','0.880',30);
    same(true,$completion->complete($shipped),'shipped courier order completes');
    same(18240000,balance(),'shipped order consumes its own exact 0.880 kilo');
    same(2,DB::table('wallets')->where('order_id',2)->count(),'courier gross and commission transfers settle once');
    same(false,$completion->complete($shipped),'shipped completion retry is idempotent');
    same(2,DB::table('branch_inventory_movements')->count(),'two actual completed orders create two ingredient movements');
    same(50000000,(int)DB::table('branch_inventory')->where('branch','f:364')->value('quantity_units'),'other branch ingredient balance unaffected');

    $explicit=$inventory->snapshots('f:363',[line(2532)])[0]['inventory'];
    same(2000000,$explicit['components'][0]['quantity_units'],'explicit owned kilo recipe wins over base alias');
    same(false,array_key_exists('recipe_feature_id',$explicit),'explicit variant is not marked as alias');
    $explicitOrder=seedOrder(3,2532); same(true,$completion->complete($explicitOrder),'explicit kilo recipe completes');
    same(16480000,balance(),'explicit 2kg component consumes 1760000 units for 0.880 quantity');
    $raw=$inventory->snapshots('f:363',[line(2533)])[0]['inventory'];
    same(0,$raw['recipe_id'],'approved one-fish raw SKU uses generated recipe without saving invented recipe');
    same(4,$raw['components'][0]['ingredient_id'],'generated raw recipe binds correct one-fish ingredient');
    same(0,$raw['recipe_feature_id'],'generated raw full recipe recognizes owned kilo');
    $rawOrder=seedOrder(4,2533); same(true,$completion->complete($rawOrder),'approved raw SKU completes');
    same(15600000,balance(),'raw generated recipe consumes exactly 0.880 kilo');

    foreach ([[5,2531,140,'missing half recipe'],[6,2531,142,'missing quarter recipe'],[7,2531,141,'foreign kilo feature'],
        [8,2535,139,'missing base-zero recipe'],[9,2536,139,'empty explicit kilo recipe'],[10,2537,139,'kilo owned by another master product']] as [$id,$product,$feature,$label]) {
        $order=seedOrder($id,$product,$feature); $before=state();
        blocked(fn () => $completion->complete($order),$label); same($before,state(),$label.' failure rolls back all order, inventory and ledger writes');
    }
    $walletFailure=seedOrder(13,2531,140);
    DB::table('orders')->where('id',13)->update(['payment_type'=>'wallet']);
    DB::table('carts')->where('order_id',13)->update(['updated_total'=>'300.00']);
    $before=state();
    blocked(fn () => $completion->complete($walletFailure),'invalid recipe after attempted wallet adjustment refund');
    same($before,state(),'failed inventory rolls back the real refund ledger and both party balances');
    $zero=seedOrder(11,2531,139,'accepted','0.000'); $beforeBalance=balance();
    same(true,$completion->complete($zero),'persisted zero-quantity line is safely skipped');
    same($beforeBalance,balance(),'zero-quantity line consumes no stock');
    same([],audit(11)['deductions'],'zero quantity records no invented deductions');
    $unmapped=$inventory->snapshots('f:363',[line(2534)])[0]['inventory'];
    same(null,$unmapped['recipe_id'],'unknown product name receives no invented recipe');
    same([],$unmapped['components'],'unmapped product receives no invented stock components');
    $unmappedOrder=seedOrder(12,2534); same(true,$completion->complete($unmappedOrder),'existing unmapped sale policy records completion');
    same($beforeBalance,balance(),'unmapped product consumes no invented stock');
    same([['product_id'=>2534,'name'=>'منتج بلا ربط مخزون']],audit(12)['unmapped'],'unmapped product is explicitly audited');
    same(4,DB::table('branch_inventory_movements')->count(),'failed/zero/unmapped attempts create no stock movements');
    same(5,DB::table('branch_stock_recipes')->count(),'reading and completing never creates guessed stored recipes');
    same(0,DB::table('branch_stock')->count(),'ingredient sales never invent direct SKU balances');
    same([],Log::$warnings,'isolated after-commit notifications finish without hidden failures');
    same(12,count($notifications->sent),'only six successful orders notify two parties each');
    echo 'Order board inventory runtime: '.$checks." assertions passed; kilo/base alias, real stock/settlement completion, audits, idempotence and guard rollback verified.\n";
}
