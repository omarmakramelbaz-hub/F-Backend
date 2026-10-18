<?php
/** Actual tracking/completion services with isolated SQLite and no provider/network calls.
 * ORDER_BOARD_TEST_VENDOR=/path/to/vendor php tests/order_tracking_sync_runtime.php
 */
namespace {
    $vendor = getenv('ORDER_BOARD_TEST_VENDOR');
    if (is_dir((string) $vendor)) $vendor .= '/autoload.php';
    if (!$vendor || !is_file($vendor)) throw new \RuntimeException('Set ORDER_BOARD_TEST_VENDOR to the isolated runtime vendor.');
    require $vendor;
    // The reusable runtime can belong to another checkout; always test this checkout.
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'App\\')) {
            $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) require $file;
        }
    }, true, true);
}
namespace Illuminate\Broadcasting {
    if (!class_exists(Channel::class)) { class Channel { public function __construct(public string $name) {} } }
    if (!class_exists(PrivateChannel::class)) { class PrivateChannel extends Channel { public function __construct($name) { parent::__construct('private-'.$name); } } }
    if (!trait_exists(InteractsWithSockets::class)) { trait InteractsWithSockets {} }
}
namespace Illuminate\Queue { if (!trait_exists(SerializesModels::class)) { trait SerializesModels {} } }
namespace Illuminate\Notifications { if (!class_exists(Notification::class)) { class Notification {} } }
namespace App\Models {
    class User extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; public function getMyTokensAttribute() { return []; } }
    class Resturant extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public function user() { return $this->belongsTo(User::class, 'user_id'); }
        public function getFirstMediaUrl(...$args) { throw new \RuntimeException('Media unavailable'); }
    }
    class Order extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public function resturant() { return $this->belongsTo(Resturant::class, 'resturant_id'); }
        public function user() { return $this->belongsTo(User::class, 'user_id'); }
    }
}
namespace {
    use App\Events\{OrderStatusUpdated, UserUpdated, OrderFinishedUpdated};
    use App\Models\{Order, User};
    use App\Notifications\{NotifyAcceptOrderNotification, NotifyUserOrderStatusUpdatedNotification};
    use App\Services\Dashboard\{BranchStock, LegacyOrderCompletion, OrderProviderDelivery};
    use App\Services\OrderTrackingState;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB, Facade, Schema};

    function app($abstract = null) { $c = Container::getInstance(); return $abstract === null ? $c : $c->make($abstract); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function __($key) { return $key; }
    function abort_unless($value, $status, $message = '') { if (!$value) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_if($value, $status, $message = '') { abort_unless(!$value, $status, $message); }
    function event($event) {
        if (($GLOBALS['fail_tracking_event'] ?? false) && $event instanceof OrderStatusUpdated) throw new \RuntimeException('Broadcast provider unavailable');
        $GLOBALS['delivered_events'][] = $event;
    }
    class Log { public static array $warnings = []; public static function warning($message, $context = []) { self::$warnings[] = [$message, $context]; } }
    class TrackingApplication extends Container {
        private array $callbacks = [];
        public function terminating($callback) { $this->callbacks[] = $callback; }
        public function terminate() { $callbacks = $this->callbacks; $this->callbacks = []; foreach ($callbacks as $callback) $callback(); }
    }
    class TrackingDelivery extends OrderProviderDelivery {
        public array $pushes = [];
        public array $registered = [];
        public function push(object $notification, $tokens, array $body, ?int $orderId = null): void { $this->pushes[] = $body; }
        public function event(object $event, ?int $orderId = null): void { $this->registered[] = $event; parent::event($event, $orderId); }
    }
    class TrackingStock extends BranchStock { public function __construct() {} public function appSale(Order $order): void {} }
    class TrackingNotifications {
        public array $attempts = [];
        public bool $failDatabase = false;
        public function send($user, $notice) {
            $this->attempts[] = $user->id;
            if ($this->failDatabase) throw new \RuntimeException('Notification database unavailable');
            return $notice->toDatabase($user);
        }
    }

    $app = new TrackingApplication(); Container::setInstance($app);
    $capsule = new Capsule($app);
    $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $capsule->getConnection()->setTransactionManager(new DatabaseTransactionsManager());
    $app->instance('db', $capsule->getDatabaseManager());
    $app->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
    $app['config']->set('app.timezone', 'UTC');
    $app->instance('log', new Log());
    $delivery = new TrackingDelivery(); $app->instance(OrderProviderDelivery::class, $delivery);
    $app->instance(BranchStock::class, new TrackingStock());
    $notices = new TrackingNotifications(); $app->instance('Illuminate\\Notifications\\ChannelManager', $notices);
    Facade::setFacadeApplication($app);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18 17:30:00', 'UTC'));
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('account_type'); $t->decimal('balance', 12, 2)->default(0); $t->timestamps(); });
    Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('name'); $t->timestamps(); });
    Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->text('payload'); });
    Schema::create('orders', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('delegate_id')->nullable();
        foreach (['order_no','status','type','payment_type','delegate_from_out','transfer_price_by','reason'] as $field) $t->string($field)->nullable();
        foreach (['grand_total','app_percentage','total','updated_total'] as $field) $t->decimal($field, 12, 2)->default(0);
        $t->timestamps();
    });
    DB::table('users')->insert([['id'=>10,'name'=>'Branch','account_type'=>'vendor'], ['id'=>20,'name'=>'Customer','account_type'=>'user']]);
    DB::table('resturants')->insert(['id'=>100,'user_id'=>10,'name'=>'Mahalla']);
    DB::table('settings')->insert(['group'=>'general','name'=>'app_balance','payload'=>'"0.00"']);

    $checks = 0;
    function same($expected, $actual, $label) { global $checks; $checks++; if ($expected !== $actual) throw new \RuntimeException($label.': '.var_export($actual, true)); }
    function fixture($id, $status = 'accepted', $courier = 'in_resturant'): Order {
        return Order::create(['id'=>$id,'user_id'=>20,'resturant_id'=>100,'order_no'=>'R100-17000-'.$id,'status'=>$status,
            'type'=>'current','payment_type'=>'cash','delegate_from_out'=>$courier,'grand_total'=>100,'total'=>100,'updated_total'=>100]);
    }
    $order = fixture(501);
    $state = OrderTrackingState::forOrder($order);
    same('R100-17000-501', $state['order_no'], 'saved display number differs from stable routing id');
    same(501, $state['order_id'], 'routing identity remains numeric');
    same('accepted', $state['status'], 'raw lifecycle is retained');
    same('On the Way', $state['live_activity_status'], 'branch courier acceptance maps to on the way');
    same(false, $state['is_terminal'], 'courier phase is active');
    same(1792344600, $state['updated_at'], 'order update timestamp is preserved');
    foreach (['pending'=>'Preparing','accepted'=>'Preparing','shipped'=>'On the Way','completed'=>'Delivered','cancelled'=>'Cancelled','declined'=>'Cancelled'] as $status=>$label) {
        $row = (object) ['id'=>501,'status'=>$status,'delegate_from_out'=>'out_resturant','updated_at'=>now()];
        $value = OrderTrackingState::forOrder($row);
        same($label, $value['live_activity_status'], $status.' uses correct live phase');
        same(in_array($status, ['completed','cancelled','declined']), $value['is_terminal'], $status.' terminal marker');
    }
    $payload = (new OrderStatusUpdated($order))->broadcastWith();
    same('On the Way', $payload['order_status'], 'broadcast preserves installed display status key');
    same('accepted', $payload['status'], 'broadcast includes raw status beside display phase');
    same('R100-17000-501', $payload['order_no'], 'broadcast carries canonical display number');
    same('Mahalla', $payload['restaurant_name'], 'restaurant display survives payload enrichment');
    $authorized = clone $order;
    $authorized->setRelation('resturant', new \App\Models\Resturant(['name'=>'Authorized branch snapshot']));
    same('Authorized branch snapshot', (new OrderStatusUpdated($authorized))->broadcastWith()['restaurant_name'],
        'broadcast preserves the already-authorized restaurant relation');

    // A recipient without ordinary FCM tokens still reaches the ActivityKit sender hook.
    $order->setRelation('resturant', null);
    $recipient = (object) ['account_type'=>'user','my_tokens'=>[]];
    $payload = (new NotifyUserOrderStatusUpdatedNotification($order))->toDatabase($recipient);
    same('live_activity', $payload['data']['notification_type'], 'existing status notification discriminator retained');
    same('accepted', $payload['data']['order_status'], 'notification raw status is retained');
    same('On the Way', $payload['data']['live_activity_status'], 'notification carries normalized phase');
    same('R100-17000-501', $payload['data']['order_no'], 'notification display number matches dashboard');
    $accepted = (new NotifyAcceptOrderNotification($order))->toDatabase($recipient);
    same(1, $accepted['data']['notification_type'], 'accept notification discriminator retained');
    same(2, count($delivery->pushes), 'no-token recipients reach push hook for both status and acceptance');

    // Database/media errors are independent of all committed tracking events.
    $delivery->registered = []; $notices->attempts = [];
    DB::beginTransaction();
    same(true, app(LegacyOrderCompletion::class)->complete($order), 'authorized completion commits state');
    same([], $delivery->registered, 'tracking waits for outer transaction commit');
    same([], $notices->attempts, 'database notifications wait for outer commit');
    DB::commit();
    same('completed', $order->fresh()->status, 'media failure cannot roll back completion');
    same([20,10], $notices->attempts, 'one recipient media failure cannot suppress another');
    $classes = array_map(fn ($event) => get_class($event), $delivery->registered);
    same(true, in_array(OrderStatusUpdated::class, $classes, true), 'tracking event registered despite media failure');
    same(true, in_array(UserUpdated::class, $classes, true), 'customer refresh event registered on completion');
    same(true, in_array(OrderFinishedUpdated::class, $classes, true), 'customer terminal event registered on completion');
    $GLOBALS['delivered_events'] = []; $GLOBALS['fail_tracking_event'] = true; $app->terminate();
    same('completed', $order->fresh()->status, 'broadcast provider failure cannot reopen a delivered order');
    same(true, count($GLOBALS['delivered_events']) >= 2, 'failed tracking provider does not suppress other events');
    same(false, app(LegacyOrderCompletion::class)->complete($order), 'completion retry does not notify or collect twice');

    $notices->failDatabase = true; $delivery->registered = [];
    $second = fixture(502);
    same(true, app(LegacyOrderCompletion::class)->complete($second), 'notification storage failure leaves completion successful');
    same('completed', $second->fresh()->status, 'notification storage failure retains committed terminal state');
    same(true, count(array_filter($delivery->registered, fn ($event) => $event instanceof OrderStatusUpdated)) === 1,
        'database notification failure cannot suppress live tracking registration');
    echo 'Order tracking synchronization runtime: '.$checks." assertions passed.\n";
}
