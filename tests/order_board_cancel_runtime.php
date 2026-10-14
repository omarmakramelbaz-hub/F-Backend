<?php
/** Actual cancellation services, Eloquent Order, transactions and SQLite; no production connection.
 * ORDER_CANCEL_TEST_VENDOR=/path/to/vendor php tests/order_board_cancel_runtime.php
 */
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    function app($abstract = null) { $app = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
    function config($key = null, $default = null) { return app('config')->get($key, $default); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function auth($guard = null) { return new class { public function user() { return null; } }; }
    function abort($status, $message = '') { throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_unless($value, $status, $message = '') { if (!$value) abort($status, $message); }
    function abort_if($value, $status, $message = '') { if ($value) abort($status, $message); }
    $vendor = getenv('ORDER_CANCEL_TEST_VENDOR'); if (is_dir((string) $vendor)) $vendor .= '/autoload.php';
    if (!$vendor || !is_file($vendor)) throw new \RuntimeException('Set ORDER_CANCEL_TEST_VENDOR to installed Laravel vendor.');
    require $vendor;
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'App\\')) {
            $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($path)) require $path;
        }
    });
    class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
    class_alias(\Illuminate\Support\Facades\DB::class, 'DB');
}
namespace App\Models {
    // Omit unrelated permission/media plugins while retaining actual persisted party relationships.
    class User extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
    class Resturant extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public function user() { return $this->belongsTo(User::class); }
    }
}
namespace App\Notifications {
    // Transport DTO fixtures: the isolated runtime intentionally has no notification drivers.
    class NotifyUserOrderStatusUpdatedNotification { public function __construct(public \App\Models\Order $order) {} }
    class NotifyResturantOrderCancelledNotification { public function __construct(public \App\Models\Order $order) {} }
    class NotifyUserCancelledOrderPriceNotification { public function __construct(public \App\Models\Order $order) {} }
    class NotifyDelegateShippingCancelledNotification { public function __construct(public \App\Models\Order $order) {} }
}
namespace App\Services\Dashboard {
    class OrderProviderDelivery {
        public bool $fail = false; public array $events = [];
        public function event($event, int $orderId): void {
            if ($this->fail) throw new \RuntimeException('Fixture provider unavailable');
            $this->events[] = [get_class($event), $orderId, \Illuminate\Support\Facades\DB::transactionLevel()];
        }
    }
}
namespace {
    use App\Models\Order;
    use App\Services\Dashboard\GoStoreBoardActions;
    use App\Services\Dashboard\LegacyOrderCancellation;
    use App\Services\Dashboard\OrderProviderDelivery;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;

    $app = new Container(); Container::setInstance($app); $db = new Capsule($app);
    $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
    $db->setAsGlobal(); $db->bootEloquent();
    $db->getConnection()->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());
    $app->instance('db', $db->getDatabaseManager());
    $app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
    $app->instance('config', new \Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo'],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]]]));
    $notices = new class {
        public bool $fail = false; public array $sent = [];
        public function send($person, $notification): void {
            if ($this->fail) throw new \RuntimeException('Fixture notices unavailable');
            $this->sent[] = [$person->id, get_class($notification), DB::transactionLevel()];
        }
    };
    $app->instance('notification', $notices);
    $app->instance(\Illuminate\Notifications\ChannelManager::class, $notices);
    $app->instance('log', new class { public function warning(...$arguments): void {} });
    $provider = new OrderProviderDelivery(); $app->instance(OrderProviderDelivery::class, $provider);
    Facade::setFacadeApplication($app);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-14 20:00:00', 'UTC'));
    Schema::create('users', function (Blueprint $t) {
        $t->id(); foreach (['name','account_type','app_scope','status'] as $field) $t->string($field);
        $t->decimal('balance', 14, 2)->default(0); $t->timestamps();
    });
    Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('name'); });
    Schema::create('orders', function (Blueprint $t) {
        $t->id(); foreach (['user_id','resturant_id','delegate_id'] as $field) $t->integer($field)->nullable();
        foreach (['status','type','payment_type','accepted_notify','delegate_from_out','transfer_price_by','reason','order_no'] as $field) $t->string($field)->nullable();
        $t->decimal('delivery_price', 14, 2)->default(50); $t->decimal('user_tax', 14, 2)->default(0); $t->timestamps();
    });
    Schema::create('carts', function (Blueprint $t) {
        $t->id(); $t->integer('order_id'); $t->decimal('price', 14, 2); $t->decimal('qty', 10, 3);
        $t->decimal('updated_total', 14, 2)->nullable(); $t->timestamps();
    });
    Schema::create('wallets', function (Blueprint $t) {
        $t->id(); foreach (['order_id','from_user','to_user'] as $field) $t->integer($field)->nullable();
        $t->decimal('amount', 14, 2); foreach (['status','payment','type'] as $field) $t->string($field);
        $t->string('transfer_reference')->nullable()->unique(); $t->timestamps();
    });
    Schema::create('payments', function (Blueprint $t) {
        $t->id(); $t->integer('order_id'); $t->integer('user_id'); $t->string('status');
        $t->decimal('total_price', 14, 2); $t->string('transaction_id')->nullable();
    });
    Schema::create('delegate_notifications', function (Blueprint $t) { $t->id(); $t->integer('order_id'); $t->integer('delegate_id'); });
    Schema::create('notifications', function (Blueprint $t) {
        $t->string('id')->primary(); $t->string('type'); $t->string('notifiable_type'); $t->integer('notifiable_id'); $t->text('data');
    });
    Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->text('payload'); });
    Schema::create('order_board_clocks', function (Blueprint $t) {
        $t->id(); $t->string('source'); $t->integer('order_id');
        foreach (['accepted_at','courier_at','closed_at'] as $field) $t->timestamp($field)->nullable(); $t->timestamps();
    });
    Schema::create('go_stores', function (Blueprint $t) { $t->integer('user_id')->primary(); $t->string('name'); });
    Schema::create('go_store_orders', function (Blueprint $t) {
        $t->id(); $t->integer('store_id'); $t->integer('customer_id'); $t->string('status'); $t->string('fulfillment');
        $t->integer('revision'); $t->integer('commission_cents'); $t->integer('total_cents');
        $t->string('payment_method'); $t->string('payment_status'); $t->string('reason')->nullable(); $t->timestamps();
    });
    Schema::create('branch_stock', function (Blueprint $t) { $t->id(); $t->integer('quantity_units'); });
    Schema::create('branch_stock_movements', function (Blueprint $t) { $t->id(); $t->integer('quantity_units'); });
    DB::table('branch_stock')->insert(['quantity_units'=>5000000]);
    DB::table('users')->insert([
        ['id'=>10,'name'=>'Customer','account_type'=>'user','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'250.00'],
        ['id'=>20,'name'=>'Store','account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted','balance'=>'1000.00'],
        ['id'=>30,'name'=>'Other','account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted','balance'=>'1000.00'],
        ['id'=>40,'name'=>'Assigned delegate','account_type'=>'delegate','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'500.00'],
        ['id'=>41,'name'=>'Invited delegate','account_type'=>'delegate','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'500.00'],
    ]);
    DB::table('resturants')->insert(['id'=>100,'user_id'=>20,'name'=>'Branch']);
    DB::table('go_stores')->insert([['user_id'=>20,'name'=>'Store'],['user_id'=>30,'name'=>'Other']]);
    DB::table('settings')->insert([
        ['group'=>'general','name'=>'service_fees','payload'=>'0'],
        ['group'=>'general','name'=>'app_balance','payload'=>'"0.00"'],
    ]);
    $checks = 0;
    function same($expected, $actual, string $label): void {
        global $checks; $checks++; if ($expected !== $actual) throw new \RuntimeException($label.': '.var_export($actual, true));
    }
    function blocked(callable $callback, int $status, string $label): void {
        try { $callback(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { same($status, $error->getStatusCode(), $label); return; }
        throw new \RuntimeException($label.' was allowed');
    }
    function balance(int $id): float { return (float) DB::table('users')->where('id',$id)->value('balance'); }
    function appBalance(): int { return \App\Services\GoServices\Money::minor(json_decode(DB::table('settings')->where('name','app_balance')->value('payload'),true)); }
    function snapshot(): array {
        $result=[];
        foreach (['users','orders','carts','wallets','payments','settings','go_store_orders','order_board_clocks','branch_stock','branch_stock_movements','delegate_notifications','notifications'] as $table) $result[$table]=DB::table($table)->orderBy('id')->get()->toJson();
        return $result;
    }
    function legacy(int $id, string $payment = 'cash', array $extra = []): Order {
        DB::table('orders')->insert($extra + ['id'=>$id,'resturant_id'=>100,'user_id'=>10,'type'=>'current','status'=>'pending',
            'payment_type'=>$payment,'accepted_notify'=>'yes','order_no'=>'F-'.$id,'delivery_price'=>'50.00','user_tax'=>'0.00','created_at'=>now(),'updated_at'=>now()]);
        DB::table('carts')->insert(['order_id'=>$id,'price'=>'100.00','qty'=>'1.000']);
        DB::table('order_board_clocks')->insert(['source'=>'legacy','order_id'=>$id,'accepted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        return Order::withoutGlobalScopes()->findOrFail($id);
    }
    function movement(array $values): void {
        DB::table('wallets')->insert($values + ['status'=>'completed','payment'=>'wallet','type'=>'transfer','created_at'=>now(),'updated_at'=>now()]);
    }
    function debit(int $id, string $amount = '150.00'): void { movement(['order_id'=>$id,'from_user'=>10,'to_user'=>null,'amount'=>$amount]); }
    function payment(int $id, string $status = '1', string $amount = '150.00', int $user = 10): void {
        DB::table('payments')->insert(['order_id'=>$id,'user_id'=>$user,'status'=>$status,'total_price'=>$amount,'transaction_id'=>'900'.$id]);
    }
    function store(int $id, array $changes = []): void {
        DB::table('go_store_orders')->insert($changes + ['id'=>$id,'store_id'=>20,'customer_id'=>10,'status'=>'pending','fulfillment'=>'delivery',
            'revision'=>1,'commission_cents'=>1000,'total_cents'=>15000,'payment_method'=>'cash','payment_status'=>'cash_due','created_at'=>now(),'updated_at'=>now()]);
    }
    $cancel = new LegacyOrderCancellation(); $go = new GoStoreBoardActions();

    $order = legacy(1); $before=snapshot(); $noticeBefore=count($notices->sent);
    same(true,$cancel->available($order),'accepted cash order can cancel');
    same(true,$cancel->cancel($order,'Customer called'),'cash cancellation commits');
    same('cancelled',DB::table('orders')->where('id',1)->value('status'),'cancel has its own terminal status');
    same('Customer called',DB::table('orders')->where('id',1)->value('reason'),'cancellation reason retained');
    same($before['wallets'],snapshot()['wallets'],'cash cancellation creates no ledger');
    same($before['users'],snapshot()['users'],'cash cancellation changes no balances');
    same($before['carts'],snapshot()['carts'],'cancelled invoice history remains intact');
    same($before['branch_stock'],snapshot()['branch_stock'],'cancellation never consumes or restores stock');
    same($before['branch_stock_movements'],snapshot()['branch_stock_movements'],'cancellation writes no stock movements');
    same(false,DB::table('order_board_clocks')->where('source','legacy')->where('order_id',1)->value('closed_at')===null,'legacy cancellation closes timer');
    same([\App\Events\OrderFinishedUpdated::class,1,0],$provider->events[0],'finished broadcast happens after commit');
    same(0,$notices->sent[$noticeBefore][2],'customer notification happens after commit');
    $committed=snapshot(); same(false,$cancel->cancel($order),'stale authorized replay does not cancel twice');
    same($committed,snapshot(),'cash replay is a strict no-op');

    $order=legacy(2,'wallet'); debit(2); $customer=balance(10);
    same(true,$cancel->cancel($order),'confirmed wallet debit refunded');
    same($customer+150.0,balance(10),'refund uses actual paid amount');
    same('admin',DB::table('orders')->where('id',2)->value('transfer_price_by'),'refund excluded from delayed moneyback');
    same(1,DB::table('wallets')->where('transfer_reference','ob:l:2:cancel')->count(),'one identifiable refund ledger');
    $committed=snapshot(); same(false,$cancel->cancel($order),'prepaid replay idempotent'); same($committed,snapshot(),'prepaid replay credits nothing');
    foreach (['online','v_cash'] as $index=>$method) {
        $id=3+$index; $order=legacy($id,$method); payment($id); $customer=balance(10);
        same(true,$cancel->cancel($order),'confirmed gateway payment cancels '.$method);
        same($customer+150.0,balance(10),'confirmed gateway original payment refunded '.$method);
    }
    $provider->fail=true; $notices->fail=true;
    same(true,$cancel->cancel(legacy(5)),'provider failures cannot undo committed cancellation');
    same('cancelled',DB::table('orders')->where('id',5)->value('status'),'provider failure leaves terminal state');
    $provider->fail=false; $notices->fail=false;

    $order=legacy(6,'cash',['status'=>'accepted','delegate_from_out'=>'out_resturant']); $before=snapshot();
    same(true,$cancel->canCancel($order),'courier search remains preparing and can cancel');
    same(true,$cancel->cancel($order),'searching-courier cash cancellation commits');
    same($before['users'],snapshot()['users'],'courier search cancellation charges nobody');
    same($before['wallets'],snapshot()['wallets'],'courier search creates no fee/refund');
    $order=legacy(7,'cash',['status'=>'accepted','delegate_from_out'=>'out_resturant','delegate_id'=>40]);
    DB::table('delegate_notifications')->insert([
        ['order_id'=>7,'delegate_id'=>40],['order_id'=>7,'delegate_id'=>41],['order_id'=>8,'delegate_id'=>40],
    ]);
    $inviteType='App\\Notifications\\NotifyDelegatesNewOrderNotification';
    $invite=['order_id'=>7,'resturant_id'=>100,'user_id'=>10];
    foreach ([
        ['ours-assigned',40,$inviteType,\App\Models\User::class,$invite],
        ['ours-pending',41,$inviteType,\App\Models\User::class,$invite],
        ['other-order',40,$inviteType,\App\Models\User::class,['order_id'=>8]+$invite],
        ['foreign-source',40,$inviteType,\App\Models\User::class,['source'=>'store']+$invite],
        ['wrong-rest',40,$inviteType,\App\Models\User::class,['resturant_id'=>101]+$invite],
        ['wrong-customer',40,$inviteType,\App\Models\User::class,['user_id'=>20]+$invite],
        ['wrong-type',40,'App\\Notifications\\NotifyUserOrderStatusUpdatedNotification',\App\Models\User::class,$invite],
        ['wrong-notifiable-type',40,$inviteType,'App\\Models\\Other',$invite],
        ['unrelated-recipient',30,$inviteType,\App\Models\User::class,$invite],
        ['padded-order-id',40,$inviteType,\App\Models\User::class,['order_id'=>'07']+$invite],
    ] as [$id,$recipient,$type,$notifiable,$data]) {
        DB::table('notifications')->insert(['id'=>$id,'notifiable_id'=>$recipient,'type'=>$type,'notifiable_type'=>$notifiable,'data'=>json_encode(['data'=>$data])]);
    }
    foreach (['malformed'=>'{broken','non-array'=>'{"data":true}'] as $id=>$data) {
        DB::table('notifications')->insert(['id'=>$id,'notifiable_id'=>40,'type'=>$inviteType,'notifiable_type'=>\App\Models\User::class,'data'=>$data]);
    }
    $before=snapshot(); $delegateBalance=balance(40);
    same(true,$cancel->canCancel($order),'assigned outside courier remains cancellable until actual handoff');
    same(true,$cancel->cancel($order),'assigned but unshipped order cancels');
    same(40,(int)DB::table('orders')->where('id',7)->value('delegate_id'),'assigned courier ID retained for history');
    same($delegateBalance,balance(40),'assigned courier cancellation charges no delivery fee');
    same($before['wallets'],snapshot()['wallets'],'assigned cash cancellation creates no money ledger');
    same($before['branch_stock'],snapshot()['branch_stock'],'assigned cancellation leaves stock intact');
    same(0,DB::table('delegate_notifications')->where('order_id',7)->count(),'only cancelled-order courier invitations removed');
    same(1,DB::table('delegate_notifications')->where('order_id',8)->count(),'other-order courier invitations preserved');
    same(0,DB::table('notifications')->whereIn('id',['ours-assigned','ours-pending'])->count(),'only matching persisted courier notices removed');
    same(10,DB::table('notifications')->count(),'foreign source/party/type/recipient/order and malformed notices preserved');
    same([\App\Events\DelegateUpdated::class,7,0],end($provider->events),'assigned courier event occurs after commit');
    same([40,\App\Notifications\NotifyDelegateShippingCancelledNotification::class,0],end($notices->sent),'assigned courier receives cancellation notice after commit');
    $order=legacy(8,'cash',['status'=>'another_delegate','delegate_from_out'=>'out_resturant','delegate_id'=>40]);
    same(true,$cancel->cancel($order),'accepted pending-phase courier search can cancel');

    foreach ([['status'=>'completed'],['status'=>'shipped'],['status'=>'declined'],['status'=>'cancelled'],
        ['accepted_notify'=>'no'],['delegate_from_out'=>'in_resturant'],
        ['transfer_price_by'=>'vendor'],['type'=>'shipping'],['payment_type'=>'unknown']] as $index=>$changes) {
        $row=(object)($changes + ['type'=>'current','status'=>'pending','payment_type'=>'cash','accepted_notify'=>'yes']);
        same(false,$cancel->canCancel($row),'state gate blocks '.json_encode($changes));
    }
    $order=legacy(20); DB::table('orders')->where('id',20)->update(['delegate_from_out'=>'in_resturant']); $before=snapshot();
    blocked(fn()=>$cancel->cancel($order),409,'handoff wins against stale cancellation screen'); same($before,snapshot(),'racing handoff leaves no cancellation writes');
    $order=legacy(21); DB::table('orders')->where('id',21)->update(['status'=>'completed']); $before=snapshot();
    blocked(fn()=>$cancel->cancel($order),409,'completion wins against stale cancellation screen'); same($before,snapshot(),'racing completion leaves no cancellation writes');
    $order=legacy(22,'cash',['status'=>'accepted','delegate_from_out'=>'out_resturant','delegate_id'=>40]);
    DB::table('orders')->where('id',22)->update(['status'=>'shipped']); $before=snapshot();
    blocked(fn()=>$cancel->cancel($order),409,'actual courier handoff wins against stale assigned cancellation screen');
    same($before,snapshot(),'shipped order is never cancelled or refunded');
    foreach ([['wallet','missing'],['wallet','wrong-amount'],['wallet','duplicate'],['wallet','prior-refund'],['online','failed'],['online','missing'],['online','wrong-party'],['online','wrong-amount'],['online','duplicate']] as $index=>[$method,$case]) {
        $id=30+$index; $order=legacy($id,$method);
        if ($method==='wallet' && $case!=='missing') debit($id,$case==='wrong-amount'?'140.00':'150.00');
        if ($case==='duplicate' && $method==='wallet') debit($id);
        if ($case==='prior-refund') movement(['order_id'=>$id,'from_user'=>null,'to_user'=>10,'amount'=>'150.00']);
        if ($method==='online' && $case!=='missing') payment($id,$case==='failed'?'0':'1',$case==='wrong-amount'?'140.00':'150.00',$case==='wrong-party'?30:10);
        if ($case==='duplicate' && $method==='online') payment($id);
        $before=snapshot(); blocked(fn()=>$cancel->cancel($order),409,'refund evidence rejects '.$method.' '.$case);
        same($before,snapshot(),'invalid payment evidence never mints money '.$case);
    }
    $order=legacy(50,'wallet'); debit(50); $before=snapshot(); $noticesBefore=count($notices->sent);
    DB::statement("CREATE TRIGGER cancel_refund_unavailable BEFORE INSERT ON wallets WHEN NEW.transfer_reference='ob:l:50:cancel' BEGIN SELECT RAISE(ABORT, 'fixture ledger unavailable'); END");
    try { $cancel->cancel($order); throw new \RuntimeException('Expected refund failure'); }
    catch (\Illuminate\Database\QueryException $error) { same($before,snapshot(),'failed refund ledger rolls back balance, order and clock atomically'); }
    same($noticesBefore,count($notices->sent),'rolled back refund sends no notification');
    DB::statement('DROP TRIGGER cancel_refund_unavailable');
    $order=legacy(55,'wallet',['status'=>'accepted','delegate_from_out'=>'out_resturant','delegate_id'=>40]); debit(55);
    DB::table('delegate_notifications')->insert(['order_id'=>55,'delegate_id'=>40]);
    DB::table('notifications')->insert(['id'=>'rollback-invite','type'=>$inviteType,'notifiable_type'=>\App\Models\User::class,
        'notifiable_id'=>40,'data'=>json_encode(['data'=>['order_id'=>55,'resturant_id'=>100,'user_id'=>10]])]);
    $before=snapshot(); $noticesBefore=count($notices->sent);
    DB::statement("CREATE TRIGGER invite_cleanup_unavailable BEFORE DELETE ON notifications WHEN OLD.id='rollback-invite' BEGIN SELECT RAISE(ABORT, 'fixture cleanup unavailable'); END");
    try { $cancel->cancel($order); throw new \RuntimeException('Expected invitation cleanup failure'); }
    catch (\Illuminate\Database\QueryException $error) { same($before,snapshot(),'cleanup failure rolls back refund, status, invitations and notifications together'); }
    same($noticesBefore,count($notices->sent),'failed courier cleanup sends no cancellation notice');
    DB::statement('DROP TRIGGER invite_cleanup_unavailable');

    store(100); same(true,$go->transition(100,20,'accept',1),'GO accepted');
    $owner=balance(20); $customer=balance(10); $fee=appBalance();
    same(true,$go->transition(100,20,'cancel',2,'Called customer'),'accepted GO cash cancels');
    same($owner+10.0,balance(20),'GO cash acceptance commission returned');
    same($fee-1000,appBalance(),'GO app commission reversed'); same($customer,balance(10),'GO cash cancellation refunds no unpaid money');
    same('cancelled',DB::table('go_store_orders')->where('id',100)->value('status'),'GO cancellation separate from rejection');
    same('cancelled',DB::table('go_store_orders')->where('id',100)->value('payment_status'),'cash obligation cancelled');
    same(1,DB::table('wallets')->where('transfer_reference','gs:100:fee-refund')->count(),'GO fee reversal recorded once');
    $committed=snapshot(); same(false,$go->transition(100,20,'cancel',2),'GO stale cancellation retry is idempotent'); same($committed,snapshot(),'GO replay changes no ledger');

    store(101,['payment_method'=>'wallet','payment_status'=>'held']); $go->transition(101,20,'accept',1); $customer=balance(10);
    same(true,$go->transition(101,20,'cancel',2),'GO held wallet cancellation');
    same($customer+150.0,balance(10),'GO customer hold refunded once');
    same('refunded',DB::table('go_store_orders')->where('id',101)->value('payment_status'),'GO hold marked refunded');
    same(1,DB::table('wallets')->where('transfer_reference','gs:101:refund')->count(),'GO hold refund ledger once');

    store(102,['payment_method'=>'card','payment_status'=>'paid']);
    movement(['transfer_reference'=>'gs:102:gross','from_user'=>null,'to_user'=>20,'amount'=>'150.00']); DB::table('users')->where('id',20)->increment('balance','150.00');
    $go->transition(102,20,'accept',1); $owner=balance(20); $customer=balance(10);
    same(true,$go->transition(102,20,'cancel',2),'GO paid gateway cancellation reverses actual credited gross');
    same($owner+10.0-150.0,balance(20),'GO paid gross and commission reversed separately');
    same($customer,balance(10),'GO gateway refund remains pending without inventing wallet credit');
    same('refund_pending',DB::table('go_store_orders')->where('id',102)->value('payment_status'),'GO gateway pending state preserved');
    same(1,DB::table('wallets')->where('transfer_reference','gs:102:reversal')->count(),'GO gross reversal once');

    store(103); $go->transition(103,20,'accept',1);
    movement(['transfer_reference'=>'gs:103:items:increase','from_user'=>20,'to_user'=>null,'amount'=>'5.00']);
    movement(['transfer_reference'=>'gs:103:items:decrease','from_user'=>null,'to_user'=>20,'amount'=>'2.00']);
    DB::table('users')->where('id',20)->decrement('balance','3.00');
    DB::table('settings')->where('name','app_balance')->update(['payload'=>json_encode(\App\Services\GoServices\Money::decimal(appBalance()+300))]);
    DB::table('go_store_orders')->where('id',103)->update(['commission_cents'=>1300,'revision'=>4]); $owner=balance(20);
    same(true,$go->transition(103,20,'cancel',4),'GO edited order cancellation reconciles fee deltas');
    same($owner+13.0,balance(20),'GO net edited commission refunded accurately');
    same(13.0,(float)DB::table('wallets')->where('transfer_reference','gs:103:fee-refund')->value('amount'),'GO net commission persisted in reversal');

    store(104); $go->transition(104,20,'accept',1); $before=snapshot();
    blocked(fn()=>$go->transition(104,20,'cancel',1),409,'stale GO revision rejected'); same($before,snapshot(),'stale GO revision writes nothing');
    blocked(fn()=>$go->transition(104,30,'cancel',2),404,'foreign store cannot cancel'); same($before,snapshot(),'foreign store rejection writes nothing');
    $go->transition(104,20,'ready',2); $go->transition(104,20,'dispatch',3); $before=snapshot();
    blocked(fn()=>$go->transition(104,20,'cancel',3),409,'dispatch wins against stale GO cancellation'); same($before,snapshot(),'GO dispatched order never rolls back to cancelled');
    same(false,DB::table('order_board_clocks')->where('source','store')->where('order_id',104)->value('courier_at')===null,'manual GO dispatch timestamps courier clock');

    foreach (['missing-fee','wrong-party-fee','fee-mismatch','paid-without-gross','prior-refund','failed-fee'] as $index=>$case) {
        $id=110+$index; store($id,$case==='paid-without-gross'?['payment_method'=>'card','payment_status'=>'paid']:[]); $go->transition($id,20,'accept',1);
        if ($case==='missing-fee') DB::table('wallets')->where('transfer_reference','gs:'.$id.':fee')->delete();
        if ($case==='wrong-party-fee') DB::table('wallets')->where('transfer_reference','gs:'.$id.':fee')->update(['from_user'=>30]);
        if ($case==='fee-mismatch') DB::table('go_store_orders')->where('id',$id)->update(['commission_cents'=>1200]);
        if ($case==='prior-refund') movement(['transfer_reference'=>'gs:'.$id.':refund','from_user'=>null,'to_user'=>10,'amount'=>'150.00']);
        if ($case==='failed-fee') DB::table('wallets')->where('transfer_reference','gs:'.$id.':fee')->update(['status'=>'failed']);
        $before=snapshot(); blocked(fn()=>$go->transition($id,20,'cancel',2),409,'GO financial evidence rejects '.$case); same($before,snapshot(),'GO invalid settlement rolls back all refund writes '.$case);
    }
    store(120,['payment_method'=>'wallet','payment_status'=>'held']); $go->transition(120,20,'accept',1); $before=snapshot();
    DB::statement("CREATE TRIGGER go_cancel_refund_unavailable BEFORE INSERT ON wallets WHEN NEW.transfer_reference='gs:120:refund' BEGIN SELECT RAISE(ABORT, 'fixture refund unavailable'); END");
    try { $go->transition(120,20,'cancel',2); throw new \RuntimeException('Expected GO refund failure'); }
    catch (\Illuminate\Database\QueryException $error) { same($before,snapshot(),'GO refund failure rolls back earlier fee refund, balances and status'); }
    DB::statement('DROP TRIGGER go_cancel_refund_unavailable');
    store(121,['commission_cents'=>0]); $go->transition(121,20,'accept',1); $beforeLedger=DB::table('wallets')->count();
    same(true,$go->transition(121,20,'cancel',2),'zero-commission GO cancellation needs no invented fee movement');
    same($beforeLedger,DB::table('wallets')->count(),'zero commission produces no fee refund ledger');
    store(122); $go->transition(122,20,'accept',1); $go->transition(122,20,'ready',2);
    same(true,$go->transition(122,20,'cancel',3),'ready order remains cancellable before handoff');
    same(false,DB::table('order_board_clocks')->where('source','store')->where('order_id',122)->value('closed_at')===null,'GO cancellation closes its clock immediately');
    store(123); $go->transition(123,20,'accept',1); $owner=balance(20);
    DB::table('settings')->where('name','app_balance')->update(['payload'=>'"-5.00"']);
    same(true,$go->transition(123,20,'cancel',2),'recorded commission returned even if app funds have since moved');
    same($owner+10.0,balance(20),'negative app balance cannot strand store commission');
    same(-1500,appBalance(),'app balance retains exact signed fee reversal');
    same(5000000,(int)DB::table('branch_stock')->value('quantity_units'),'all cancellation paths preserve stock');
    same(0,DB::table('branch_stock_movements')->count(),'all cancellation paths write no stock ledger');
    echo 'PASS order board cancellation runtime: '.$checks." checks\n";
}
