<?php
/** Real editor, board tenant scope, catalog pricing and SQLite writes; no gateway or server access.
 * ORDER_BOARD_TEST_VENDOR=/path/to/vendor php tests/order_board_items_runtime.php
 */
namespace {
    function app($abstract = null) { $app = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function __($key, $replace = []) { return app('translator')->get($key, $replace); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function abort($status, $message = '') { throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_unless($value, $status, $message = '') { if (!$value) abort($status, $message); }
    function abort_if($value, $status, $message = '') { if ($value) abort($status, $message); }
    function route($name, $parameters = []) { return '/'.$name.'/'.implode('/', $parameters); }
    $vendor = getenv('ORDER_BOARD_TEST_VENDOR');
    if (is_dir((string) $vendor)) $vendor .= '/autoload.php';
    if (!$vendor || !is_file($vendor)) throw new \RuntimeException('Set ORDER_BOARD_TEST_VENDOR to an installed Laravel 8 vendor directory.');
    require $vendor;
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'App\\')) {
            $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) require $file;
        }
    });
}
namespace App\Models {
    // The harness needs only persisted Eloquent identity; no JWT, media or permission plugin boot.
    class User extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public function can($ability, $arguments = []) { return (bool) $this->can_edit || $ability === 'order-list'; }
    }
}
namespace {
    use App\Models\User;
    use App\Services\Dashboard\OrderBoardItems;
    use App\Services\GoServices\Money;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;

    $app = new class extends Container { public function getLocale() { return 'ar'; } }; Container::setInstance($app);
    $db = new Capsule($app);
    $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true]);
    $db->setAsGlobal(); $db->bootEloquent();
    $app->instance('db', $db->getDatabaseManager());
    $app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
    $app->instance('config', new \Illuminate\Config\Repository(['app'=>['key'=>'editor-fixture-key', 'timezone'=>'Africa/Cairo'],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]]]));
    $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en');
    $app->instance('translator', $translator);
    $app->instance('validator', new \Illuminate\Validation\Factory($translator, $app));
    Facade::setFacadeApplication($app);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-14 18:30:00', 'UTC'));

    Schema::create('users', function (Blueprint $t) {
        $t->id(); foreach (['name','account_type','app_scope','status'] as $field) $t->string($field);
        $t->integer('owner_resturant_id')->nullable(); $t->boolean('can_edit')->default(false); $t->decimal('balance', 14, 2)->default(0); $t->timestamps();
    });
    Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('name'); $t->decimal('service_fees', 6, 2)->default(10); });
    Schema::create('orders', function (Blueprint $t) {
        $t->id(); foreach (['resturant_id','user_id','delegate_id'] as $field) $t->integer($field)->nullable();
        foreach (['type','status','payment_type','accepted_notify','delegate_from_out','transfer_price_by'] as $field) $t->string($field)->nullable();
        foreach (['total_price','vendor_tax','user_tax','tax','delivery_price'] as $field) $t->decimal($field, 14, 2)->default(0); $t->timestamps();
    });
    Schema::create('carts', function (Blueprint $t) {
        $t->id(); foreach (['order_id','user_id','resturant_id','resturant_product_id','product_feature'] as $field) $t->integer($field)->nullable();
        $t->string('product_clean')->nullable(); $t->string('reason_update_total')->nullable(); $t->decimal('qty', 12, 3);
        $t->decimal('price', 14, 2); $t->decimal('updated_total', 14, 2)->nullable(); $t->boolean('is_order')->default(true); $t->timestamps();
    });
    Schema::create('resturant_products', function (Blueprint $t) {
        $t->id(); $t->integer('resturant_id'); $t->integer('product_id'); $t->string('product_name'); $t->string('status')->default('show');
        $t->integer('category_id')->nullable(); $t->decimal('product_price', 14, 2); $t->text('price')->default('{}');
    });
    Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name_ar'); $t->string('name_en'); });
    Schema::create('branch_stock', function (Blueprint $t) {
        $t->id(); $t->string('branch'); $t->integer('product_id'); $t->string('unit'); $t->bigInteger('quantity_units'); $t->integer('revision');
    });
    Schema::create('branch_stock_movements', function (Blueprint $t) { $t->id(); });
    Schema::create('product_features', function (Blueprint $t) { $t->id(); $t->integer('product_id'); $t->string('name'); });
    Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->text('payload'); });
    Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); });
    Schema::create('payments', function (Blueprint $t) { $t->id(); $t->integer('order_id'); $t->string('status'); });
    Schema::create('go_stores', function (Blueprint $t) { $t->integer('user_id')->primary(); $t->string('name'); });
    Schema::create('go_store_products', function (Blueprint $t) {
        $t->id(); $t->integer('user_id'); $t->string('name'); $t->string('unit'); $t->integer('price_cents'); $t->boolean('available');
        $t->integer('revision')->default(1); $t->text('options')->default('[]');
    });
    Schema::create('go_store_orders', function (Blueprint $t) {
        $t->id(); $t->integer('store_id'); $t->integer('customer_id'); $t->text('snapshot');
        foreach (['status','fulfillment','payment_method','payment_status'] as $field) $t->string($field);
        foreach (['subtotal_cents','delivery_cents','total_cents','commission_bps','commission_cents','revision'] as $field) $t->integer($field);
        $t->timestamps();
    });
    Schema::create('wallets', function (Blueprint $t) {
        $t->id(); $t->integer('from_user')->nullable(); $t->integer('to_user')->nullable(); $t->string('transfer_reference')->unique();
        $t->decimal('amount', 14, 2); foreach (['status','payment','type'] as $field) $t->string($field); $t->timestamps();
    });
    foreach ([[1,'admin',null,1],[10,'vendor',100,0],[11,'vendor',101,0],[20,'user',null,0],[30,'vendor',null,0],[31,'vendor',null,0],
        [40,'admin',null,0],[41,'admin',100,0],[42,'resturant_owner',100,0],[43,'user',null,0],[44,'admin',101,0]] as [$id,$type,$branch,$edit]) {
        DB::table('users')->insert(['id'=>$id,'name'=>'Fixture '.$id,'account_type'=>$type,'app_scope'=>in_array($id,[30,31])?'go_partner':'fasakhansta',
            'status'=>'accepted','owner_resturant_id'=>$branch,'can_edit'=>$edit,'balance'=>'500.00']);
    }
    DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Branch A'],['id'=>101,'user_id'=>11,'name'=>'Branch B']]);
    DB::table('categories')->insert([['id'=>10,'name_ar'=>'أسماك','name_en'=>'Fish'],['id'=>11,'name_ar'=>'الفرع الآخر','name_en'=>'Other branch']]);
    DB::table('resturant_products')->insert([
        ['id'=>1,'resturant_id'=>100,'product_id'=>1000,'category_id'=>10,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{"extra_clean":"20.00"}'],
        ['id'=>2,'resturant_id'=>100,'product_id'=>1001,'category_id'=>10,'product_name'=>'Side','product_price'=>'20.00','price'=>'{}'],
        ['id'=>3,'resturant_id'=>101,'product_id'=>1002,'category_id'=>11,'product_name'=>'Other branch','product_price'=>'5.00','price'=>'{}'],
    ]);
    DB::table('branch_stock')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'kg','quantity_units'=>3500000,'revision'=>1]);
    DB::table('product_features')->insert([['id'=>50,'product_id'=>1000,'name'=>'half'],['id'=>51,'product_id'=>1000,'name'=>'quarter'],['id'=>52,'product_id'=>1002,'name'=>'half']]);
    DB::table('settings')->insert([['group'=>'general','name'=>'tax','payload'=>'"14.00"'],['group'=>'general','name'=>'service_fees','payload'=>'"5.00"'],['group'=>'general','name'=>'app_balance','payload'=>'"1000.00"']]);
    DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
    DB::table('go_stores')->insert([['user_id'=>30,'name'=>'Store A'],['user_id'=>31,'name'=>'Store B']]);
    DB::table('go_store_products')->insert([['id'=>10,'user_id'=>30,'name'=>'Rice','unit'=>'piece','price_cents'=>10000,'available'=>1],['id'=>11,'user_id'=>31,'name'=>'Other store','unit'=>'piece','price_cents'=>1000,'available'=>1]]);

    $checks = 0;
    function same($expected, $actual, string $label): void { global $checks; $checks++; if ($expected !== $actual) throw new \RuntimeException($label.': '.var_export($actual,true)); }
    function blocked(callable $call, int $status, string $label): void {
        try { $call(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { same($status,$error->getStatusCode(),$label); return; }
        catch (\Illuminate\Validation\ValidationException $error) { same($status,422,$label); return; }
        throw new \RuntimeException($label.' was allowed');
    }
    function editor(): OrderBoardItems { return app(OrderBoardItems::class); }
    function actor(int $id): User { return User::findOrFail($id); }
    function seedLegacy(int $id = 1): void {
        DB::table('orders')->insert(['id'=>$id,'resturant_id'=>100,'user_id'=>20,'type'=>'current','status'=>'accepted','payment_type'=>'cash',
            'total_price'=>'140.00','delivery_price'=>'50.00','user_tax'=>'18.20','created_at'=>now(),'updated_at'=>now()]);
        DB::table('carts')->insert([
            ['id'=>$id*10,'order_id'=>$id,'resturant_id'=>100,'user_id'=>20,'resturant_product_id'=>1,'product_feature'=>50,'product_clean'=>'extra_clean','price'=>'60.00','qty'=>'2.000','updated_total'=>null,'reason_update_total'=>null],
            ['id'=>$id*10+1,'order_id'=>$id,'resturant_id'=>100,'user_id'=>20,'resturant_product_id'=>2,'product_feature'=>null,'product_clean'=>null,'price'=>'20.00','qty'=>'1.000','updated_total'=>'10.00','reason_update_total'=>'Prior approved adjustment'],
        ]);
    }
    function payload(array $read): array { return ['expected_revision'=>$read['order']['revision'],'items'=>array_map(fn($line)=>array_intersect_key($line,array_flip(['line_id','product_id','quantity','option_id','feature_id','product_clean'])),$read['order']['items'])]; }

    seedLegacy();
    $read = editor()->read('legacy',1,actor(10));
    same('f:100',$read['branch']['value'],'editor uses order branch metadata');
    same('Branch A',$read['branch']['name'],'editor exposes branch label from POS');
    same([['id'=>10,'name'=>'أسماك']],$read['categories'],'POS categories remain scoped to order branch');
    same(10,$read['catalog'][0]['category_id'],'POS product category retained');
    same(true,array_key_exists('image_url',$read['catalog'][0]),'POS product image metadata retained');
    same('weight',$read['catalog'][0]['quantity_mode'],'POS quantity mode retained');
    same('3.5',$read['catalog'][0]['stock']['quantity'],'POS product stock display retained');
    DB::table('branch_stock')->where('product_id',1)->update(['quantity_units'=>2500000,'revision'=>2]);
    $afterOtherSale=editor()->read('legacy',1,actor(10));
    same('2.5',$afterOtherSale['catalog'][0]['stock']['quantity'],'POS product stock display updates');
    same($read['order']['revision'],$afterOtherSale['order']['revision'],'unrelated stock sales do not invalidate order quote');
    same('130.00',$read['order']['subtotal'],'manual line discount preserved in opened total');
    same('204.70',$read['order']['total'],'current subtotal plus stored tax, delivery and service');
    same('f:50:extra_clean',$read['order']['items'][0]['option_id'],'saved portion and cleaning retained');
    same('0.500',$read['order']['items'][0]['weight_per_unit'],'saved half portion exposes actual weight conversion');
    $halfOption=array_values(array_filter($read['catalog'][0]['options'],fn($option)=>$option['id']==='f:50:base'))[0];
    same('0.500',$halfOption['weight_per_unit'],'catalog half exposes actual weight conversion');
    $data = payload($read); $data['items'][0]['quantity']='0.850';
    $data['items'][]=['product_id'=>2,'quantity'=>'1.000','unit_price'=>'0.01','quantity_millis'=>1];
    editor()->save('legacy',1,$data,actor(10));
    same(0.85,(float)DB::table('carts')->where('id',10)->value('qty'),'fractional quantity persisted');
    same(50,(int)DB::table('carts')->where('id',10)->value('product_feature'),'portion preserved');
    same('extra_clean',DB::table('carts')->where('id',10)->value('product_clean'),'cleaning preserved');
    same('Prior approved adjustment',DB::table('carts')->where('id',11)->value('reason_update_total'),'untouched discount reason preserved');
    same(10.0,(float)DB::table('carts')->where('id',11)->value('updated_total'),'untouched explicit line total preserved');
    same(91.0,(float)DB::table('orders')->where('id',1)->value('total_price'),'base subtotal recalculated from persisted prices');
    same(11.34,(float)DB::table('orders')->where('id',1)->value('user_tax'),'user tax uses changed discounted subtotal');
    same(8.1,(float)DB::table('orders')->where('id',1)->value('vendor_tax'),'vendor commission uses changed subtotal');
    same(1.13,(float)DB::table('orders')->where('id',1)->value('tax'),'commission tax rounds correctly');
    same(20.0,(float)DB::table('carts')->orderByDesc('id')->value('price'),'new price and quantity injection ignored');
    blocked(fn()=>editor()->save('legacy',1,$data,actor(10)),409,'stale replay rejected');
    blocked(fn()=>editor()->read('legacy',1,actor(11)),404,'other branch cannot open editor');
    foreach ([1,10,40,41,42] as $userId) {
        $allowed=editor()->read('legacy',1,actor($userId));
        same('legacy:1',$allowed['order']['key'],'every authorized preparing-board account can open editor '.$userId);
        $card=app(\App\Services\Dashboard\OrderBoardService::class)->detail('legacy',1,actor($userId));
        same(true,isset($card['urls']['items']),'every authorized preparing-board account sees button '.$userId);
        editor()->save('legacy',1,payload($allowed),actor($userId));
        same(0.85,(float)DB::table('carts')->where('id',10)->value('qty'),'authorized preparing-board account can save '.$userId);
    }
    blocked(fn()=>editor()->read('legacy',1,actor(44)),404,'linked administrator cannot edit another branch');
    blocked(fn()=>editor()->read('legacy',1,actor(43)),403,'customer account never receives dashboard edit access');
    $current = editor()->read('legacy',1,actor(10)); $invalid = payload($current);
    $invalid['items'][]=['product_id'=>3,'quantity'=>'1'];
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'cross-branch product rejected');
    same(3,DB::table('carts')->where('order_id',1)->count(),'rejected save changes no lines');
    $invalid=payload($current); $invalid['items'][0]['line_id']=999;
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'foreign line id rejected');
    $invalid=payload($current); $invalid['items'][]=$invalid['items'][0];
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'duplicate saved line rejected');
    $invalid=payload($current); $invalid['items'][0]['option_id']='f:51:base';
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'saved extras cannot be silently changed');
    $invalid=payload($current); $invalid['items']=[];
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'empty order rejected');
    $invalid=payload($current); $invalid['items'][0]['quantity']='0';
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'zero weight rejected');
    $invalid=payload($current); $invalid['items'][0]['quantity']='0.0001';
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'excess weight precision rejected');
    $invalid=payload($current); $invalid['items'][]=['product_id'=>1,'quantity'=>'1','feature_id'=>52];
    blocked(fn()=>editor()->save('legacy',1,$invalid,actor(10)),422,'foreign feature rejected');
    DB::table('resturant_products')->where('id',2)->update(['product_price'=>'21.00']);
    blocked(fn()=>editor()->save('legacy',1,payload($current),actor(10)),409,'catalog price drift rejected');
    DB::table('resturant_products')->where('id',2)->update(['product_price'=>'20.00']);
    $current=editor()->read('legacy',1,actor(10)); $data=payload($current); array_splice($data['items'],1,1);
    editor()->save('legacy',1,$data,actor(10));
    same(0,DB::table('carts')->where('id',11)->count(),'removed line deleted from actual order');
    seedLegacy(2); DB::table('orders')->where('id',2)->update(['payment_type'=>'wallet']);
    blocked(fn()=>editor()->read('legacy',2,actor(10)),409,'wallet-paid order blocked');
    DB::table('orders')->where('id',2)->update(['payment_type'=>'cash','delegate_from_out'=>'in_resturant']);
    blocked(fn()=>editor()->read('legacy',2,actor(10)),409,'handed-over order blocked');
    DB::table('orders')->where('id',2)->update(['delegate_from_out'=>null]);
    DB::table('payments')->insert(['order_id'=>2,'status'=>'paid']);
    blocked(fn()=>editor()->read('legacy',2,actor(10)),409,'persisted payment evidence blocks edits');

    $snapshot=['customer_name'=>'Private fixture','notes'=>'Keep extras','items'=>[['product_id'=>10,'name'=>'Original rice name','unit'=>'piece','unit_price'=>'100.00','quantity'=>1,'line_total'=>'100.00','option_id'=>'','option_label'=>'Saved label','custom_note'=>'No substitutions']]];
    DB::table('go_store_orders')->insert(['id'=>200,'store_id'=>30,'customer_id'=>20,'status'=>'preparing','fulfillment'=>'delivery','payment_method'=>'cash','payment_status'=>'cash_due',
        'subtotal_cents'=>10000,'delivery_cents'=>5000,'total_cents'=>15000,'commission_bps'=>1000,'commission_cents'=>1000,'revision'=>2,'snapshot'=>json_encode($snapshot),'created_at'=>now(),'updated_at'=>now()]);
    DB::table('wallets')->insert(['from_user'=>30,'to_user'=>null,'transfer_reference'=>'gs:200:fee','amount'=>'10.00','status'=>'completed','payment'=>'wallet','type'=>'transfer','created_at'=>now(),'updated_at'=>now()]);
    $current=editor()->read('store',200,actor(30));
    same('0.00',$current['order']['pricing']['tax_rate'],'store preview has no legacy application tax');
    same('0.00',$current['order']['pricing']['service_rate'],'store preview has no legacy application service fee');
    same('150.00',$current['order']['total'],'store preview total is subtotal plus delivery');
    DB::table('settings')->where('name','tax')->update(['payload'=>'"15.00"']);
    same($current['order']['revision'],editor()->read('store',200,actor(30))['order']['revision'],'irrelevant legacy tax changes do not invalidate store quote');
    DB::table('settings')->where('name','tax')->update(['payload'=>'"14.00"']);
    $data=payload($current); $data['items'][0]['quantity']='2';
    editor()->save('store',200,$data,actor(30));
    $saved=DB::table('go_store_orders')->where('id',200)->first(); $snap=json_decode($saved->snapshot,true);
    same(25000,(int)$saved->total_cents,'store total includes unchanged delivery');
    same(2000,(int)$saved->commission_cents,'store commission recalculated');
    same(490.0,(float)DB::table('users')->where('id',30)->value('balance'),'accepted store additional commission debited');
    same('1010.00',json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true),'additional commission credited to app');
    same('No substitutions',$snap['items'][0]['custom_note'],'store customizations preserved');
    same('Saved label',$snap['items'][0]['option_label'],'stored option label preserved');
    blocked(fn()=>editor()->save('store',200,$data,actor(30)),409,'store retry cannot debit twice');
    blocked(fn()=>editor()->read('store',200,actor(31)),404,'other store denied');
    $current=editor()->read('store',200,actor(30)); $data=payload($current); $data['items'][0]['quantity']='1.5';
    blocked(fn()=>editor()->save('store',200,$data,actor(30)),422,'piece quantity must be integral');
    $data['items'][0]['quantity']='1'; editor()->save('store',200,$data,actor(30));
    same(500.0,(float)DB::table('users')->where('id',30)->value('balance'),'commission decrease returned to store');
    same('1000.00',json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true),'commission decrease removed from app balance');
    $current=editor()->read('store',200,actor(30)); $data=payload($current); $data['items'][0]['quantity']='2';
    DB::statement("CREATE TRIGGER fail_editor_wallet BEFORE INSERT ON wallets WHEN NEW.transfer_reference LIKE 'gs:200:items:%' BEGIN SELECT RAISE(ABORT, 'fixture ledger unavailable'); END");
    try { editor()->save('store',200,$data,actor(30)); throw new \RuntimeException('expected transactional failure'); }
    catch (\Illuminate\Database\QueryException $error) { same(500.0,(float)DB::table('users')->where('id',30)->value('balance'),'ledger failure rolls back wallet balance'); }
    same(10000,(int)DB::table('go_store_orders')->where('id',200)->value('subtotal_cents'),'ledger failure rolls back order');
    DB::statement('DROP TRIGGER fail_editor_wallet');
    DB::table('go_store_orders')->where('id',200)->update(['status'=>'ready']);
    $ready=editor()->read('store',200,actor(40));
    same('store:200',$ready['order']['key'],'order-list administrator can edit ready order in preparing column');
    $card=app(\App\Services\Dashboard\OrderBoardService::class)->detail('store',200,actor(40));
    same(true,isset($card['urls']['items']),'ready store order exposes editor button to authorized viewer');
    $data=payload($ready); $data['items'][0]['quantity']='2';
    $change=editor()->save('store',200,$data,actor(40));
    same('preparing',$change['from_group'],'ready order patch remains in preparing column');
    same(490.0,(float)DB::table('users')->where('id',30)->value('balance'),'ready order commission increase debits store once');
    same('ready',DB::table('go_store_orders')->where('id',200)->value('status'),'editing ready items preserves lifecycle state');
    DB::table('go_store_orders')->where('id',200)->update(['status'=>'out_for_delivery']);
    blocked(fn()=>editor()->read('store',200,actor(30)),409,'courier store order blocked');

    // Canonical saved line cents must match every existing model/board consumer.
    seedLegacy(3); DB::table('resturant_products')->where('id',2)->update(['product_price'=>'225.00']);
    $current=editor()->read('legacy',3,actor(10)); $data=payload($current);
    $data['items']=[['product_id'=>2,'quantity'=>'0.333'],['product_id'=>2,'quantity'=>'0.333']];
    editor()->save('legacy',3,$data,actor(10));
    $savedLines=DB::table('carts')->where('order_id',3)->get();
    same(149.86,round($savedLines->sum('updated_total'),2),'two fractional lines preserve canonical cents');
    $order=(new \App\Models\Order())->forceFill(['type'=>'current']); $order->setRelation('carts',$savedLines);
    same(149.86,$order->updated_total,'legacy settlement model agrees with editor rounding');
    $card=app(\App\Services\Dashboard\OrderBoardService::class)->detail('legacy',3,actor(10));
    same('149.86',$card['totals'][0]['amount'],'board receipt agrees with editor rounding');

    seedLegacy(4); DB::table('resturant_products')->where('id',2)->update(['product_price'=>'0.01']);
    $current=editor()->read('legacy',4,actor(10)); $data=payload($current);
    $data['items']=[['product_id'=>2,'quantity'=>'0.001']];
    editor()->save('legacy',4,$data,actor(10));
    $savedLines=DB::table('carts')->where('order_id',4)->get();
    same(0.0,(float)$savedLines[0]->updated_total,'sub-cent line stores explicit zero');
    $order=(new \App\Models\Order())->forceFill(['type'=>'current']); $order->setRelation('carts',$savedLines);
    same(0.0,$order->updated_total,'legacy model honors explicit zero');
    $current=editor()->read('legacy',4,actor(10));
    same('0.00',$current['order']['subtotal'],'editor honors explicit zero');
    $card=app(\App\Services\Dashboard\OrderBoardService::class)->detail('legacy',4,actor(10));
    same('0.00',$card['totals'][0]['amount'],'board honors explicit zero');
    editor()->save('legacy',4,payload($current),actor(10));
    same(0.0,(float)DB::table('carts')->where('order_id',4)->value('updated_total'),'unchanged zero override survives resave');
    echo 'Order board items runtime: '.$checks." assertions passed.\n";
}
