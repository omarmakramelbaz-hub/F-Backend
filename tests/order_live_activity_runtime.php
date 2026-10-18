<?php
/** Real registration, sender, migration and provider hooks; in-memory SQLite and simulated provider only. */
namespace {
    $vendor = getenv('ORDER_BOARD_TEST_VENDOR');
    if (is_dir((string) $vendor)) $vendor .= '/autoload.php';
    if (!$vendor || !is_file($vendor)) throw new \RuntimeException('Set ORDER_BOARD_TEST_VENDOR to the isolated runtime vendor.');
    require $vendor;
    spl_autoload_register(function ($class) {
        if (str_starts_with($class, 'App\\')) {
            $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($file)) require $file;
        }
    }, true, true);
}
namespace App\Models {
    class Order extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public function resturant() { return $this->belongsTo(Resturant::class, 'resturant_id'); }
    }
    class Resturant extends \Illuminate\Database\Eloquent\Model { protected $guarded = []; }
}
namespace App\Events { class OrderStatusUpdated { public function __construct(public $order) {} } }
namespace {
    use App\Models\{Order, OrderLiveActivity};
    use App\Services\Dashboard\{OrderProviderDelivery, OrderLiveActivityService, FcmLiveActivityTransport};
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB, Facade, Schema};

    function app($abstract = null) { $c = Container::getInstance(); return $abstract === null ? $c : $c->make($abstract); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function config($key, $default = null) { return app('config')->get($key, $default); }
    function abort_unless($value, $status, $message = '') { if (!$value) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function event($event) { $GLOBALS['events'][] = $event; }
    class LiveTestApplication extends Container {
        private array $callbacks = [];
        public function terminating($callback) { $this->callbacks[] = $callback; }
        public function terminate() { $callbacks = $this->callbacks; $this->callbacks = []; foreach ($callbacks as $callback) $callback(); }
    }
    // Illuminate Encryption is not a dependency of this minimal runtime. This authenticated cipher
    // implements the same facade boundary while production uses Laravel's application encrypter.
    class LiveTestCipher {
        public function encryptString($text) {
            $iv = random_bytes(12); $tag = '';
            $cipher = openssl_encrypt($text, 'aes-256-gcm', str_repeat('k', 32), OPENSSL_RAW_DATA, $iv, $tag);
            return base64_encode($iv.$tag.$cipher);
        }
        public function decryptString($text) {
            $bytes = base64_decode($text, true);
            $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', str_repeat('k', 32), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
            if ($plain === false) throw new \RuntimeException('bad_ciphertext');
            return $plain;
        }
    }
    class LiveTestLogger { public array $warnings = []; public function warning($message, $context) { $this->warnings[] = [$message, $context]; } }
    class LiveTestTransport extends FcmLiveActivityTransport {
        public array $messages = [];
        public bool $refuse = false;
        public $duringSend;
        public function send(string $fcmToken, string $activityToken, array $state, int $timestamp, bool $terminal): void {
            check(DB::transactionLevel() === 0, 'provider HTTP executes outside the transaction');
            $this->messages[] = $this->message($fcmToken, $activityToken, $state, $timestamp, $terminal);
            if ($this->duringSend) { $callback = $this->duringSend; $this->duringSend = null; $callback(); }
            if ($this->refuse) throw new \RuntimeException('SECRET provider response and device token');
        }
    }
    class LiveHttpResponse {
        public function __construct(public bool $ok, public array $body) {}
        public function successful() { return $this->ok; }
        public function json($key) { return $this->body[$key] ?? null; }
    }
    class LiveHttpClient {
        public array $options = [];
        public array $requests = [];
        public $response;
        public function withToken($token) { return $this; }
        public function acceptJson() { return $this; }
        public function asJson() { return $this; }
        public function withOptions($options) { $this->options += $options; return $this; }
        public function timeout($seconds) { $this->options['timeout'] = $seconds; return $this; }
        public function post($url, $body) { $this->requests[] = [$url, $body]; return $this->response; }
    }
    class LiveAuthenticatedTransport extends FcmLiveActivityTransport { protected function accessToken(): string { return 'test-oauth-token'; } }
    $app = new LiveTestApplication; Container::setInstance($app);
    $app->instance('config', new \Illuminate\Config\Repository);
    $capsule = new Capsule($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $capsule->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
    $app->instance('db', $capsule->getDatabaseManager());
    $app->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
    $app['config']->set('app.timezone', 'UTC'); $app['config']->set('services.fcm.project_id', 'test-project');
    $app->instance('encrypter', new LiveTestCipher);
    $logger = new LiveTestLogger; $app->instance('log', $logger);
    $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
    $app->instance('validator', new \Illuminate\Validation\Factory($translator, $app));
    Facade::setFacadeApplication($app);
    $transport = new LiveTestTransport; $app->instance(FcmLiveActivityTransport::class, $transport);
    $service = app(OrderLiveActivityService::class); $app->instance(OrderLiveActivityService::class, $service);
    $delivery = new OrderProviderDelivery; $app->instance(OrderProviderDelivery::class, $delivery);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18 18:00:00', 'UTC'));
    Schema::create('orders', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('resturant_id'); $t->string('order_no'); $t->string('status'); $t->string('delegate_from_out')->nullable(); $t->timestamps(); });
    Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->string('name'); $t->timestamps(); });
    require dirname(__DIR__).'/database/migrations/2026_10_18_200000_create_order_live_activities_table.php';
    (new \CreateOrderLiveActivitiesTable)->up();
    DB::table('resturants')->insert(['id' => 363, 'name' => 'Fasakhansta']);
    foreach ([7375 => 10, 7376 => 20] as $id => $owner) DB::table('orders')->insert([
        'id' => $id, 'user_id' => $owner, 'resturant_id' => 363, 'order_no' => 'R363-'.$id,
        'status' => 'preparing', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    $actor = (object) ['id' => 10, 'account_type' => 'user'];
    $input = ['activity_id' => 'activity-device-A', 'activity_token' => str_repeat('ab', 32), 'fcm_token' => 'fcm-device-A-12345678901234567890'];
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new \RuntimeException('FAIL '.$label); $count++; echo 'PASS '.$label.PHP_EOL; }
    function denied($callback, $label) { try { $callback(); } catch (\Illuminate\Database\Eloquent\ModelNotFoundException | \Illuminate\Validation\ValidationException | \Symfony\Component\HttpKernel\Exception\HttpException $error) { check(true, $label); return; } throw new \RuntimeException('FAIL '.$label); }
    function lastMessage() { global $transport; return end($transport->messages)['message']; }
    function state($status) { DB::table('orders')->where('id', 7375)->update(['status' => $status, 'updated_at' => now('UTC')->addSecond()]); }

    denied(fn () => $service->register(7376, $actor, $input), 'cannot register another customer order');
    denied(fn () => $service->register(7375, (object) ['id' => 10, 'account_type' => 'vendor'], $input), 'vendor cannot register customer activity');
    denied(fn () => $service->register(7375, $actor, array_replace($input, ['activity_token' => 'invalid'])), 'invalid APNs token rejected');
    $result = $service->register(7375, $actor, $input);
    check($result['order_id'] === 7375 && $result['order_no'] === 'R363-7375', 'response distinguishes immutable ID from public order number');
    check($transport->messages === [], 'registration returns before contacting provider');
    $row = OrderLiveActivity::first();
    check(!str_contains($row->activity_token, $input['activity_token']) && !str_contains($row->fcm_token, $input['fcm_token']), 'push tokens encrypted at rest');
    check(!isset($row->toArray()['activity_token'], $row->toArray()['fcm_token']), 'serialized registration never exposes tokens');
    $app->terminate(); $message = lastMessage();
    check($message['token'] === $input['fcm_token'] && $message['apns']['live_activity_token'] === $input['activity_token'], 'FCM targets both device token and exact activity token');
    $content = $message['apns']['payload']['aps']['content-state'];
    check($content['order_id'] === '7375' && $content['order_no'] === 'R363-7375' && $content['order_status'] === 'Preparing' && is_float($content['updateId']), 'Swift content-state identity status and version schema match');
    $firstTime = $message['apns']['payload']['aps']['timestamp']; $sent = count($transport->messages);
    $service->register(7375, $actor, $input); $app->terminate();
    check(count($transport->messages) === $sent, 'duplicate token registration does not resend unchanged state');
    $second = array_replace($input, ['activity_id' => 'activity-device-B', 'activity_token' => str_repeat('cd', 32), 'fcm_token' => 'fcm-device-B-12345678901234567890']);
    $service->register(7375, $actor, $second); $app->terminate();
    check(OrderLiveActivity::count() === 2 && count($transport->messages) === $sent + 1, 'multiple customer devices track the same order independently');

    state('shipped'); $sent = count($transport->messages);
    $delivery->push(new \stdClass, [], ['data' => ['account_type' => 'user', 'order_status' => 'shipped']], 7375);
    $delivery->event(new \App\Events\OrderStatusUpdated(Order::find(7375)), 7375);
    check(count($transport->messages) === $sent, 'status hook keeps provider delivery after response');
    $app->terminate();
    check(count($transport->messages) === $sent + 2 && lastMessage()['apns']['payload']['aps']['content-state']['order_status'] === 'On the Way', 'status broadcasts and empty ordinary push tokens sync each live activity once');
    check(lastMessage()['apns']['payload']['aps']['timestamp'] > $firstTime, 'per-activity APNs timestamp advances within the same second');

    $rotated = array_replace($input, ['activity_token' => str_repeat('ef', 32), 'fcm_token' => 'fcm-rotated-A-12345678901234567890']);
    $service->register(7375, $actor, $rotated); $app->terminate();
    check(OrderLiveActivity::count() === 2 && lastMessage()['apns']['live_activity_token'] === $rotated['activity_token'], 'token rotation updates exact registration without duplicating device');
    denied(fn () => $service->unregister(7376, $actor, $input), 'cannot unregister another customer activity');
    $service->unregister(7375, $actor, $second);
    check(OrderLiveActivity::count() === 1, 'ending one device preserves other device registration');

    state('completed'); $transport->refuse = true; $service->deliverOrder(7375);
    $row = OrderLiveActivity::first();
    check($row->ended_at === null && $row->attempts === 1 && $row->activity_token !== '', 'failed terminal delivery preserves encrypted token for retry');
    check(!str_contains(json_encode($logger->warnings), 'SECRET'), 'provider failure logging contains no provider details or tokens');
    $transport->refuse = false; \Carbon\Carbon::setTestNow(now('UTC')->addMinute());
    check($service->retryPending() === 1, 'scheduled retry finds pending terminal delivery');
    $row = OrderLiveActivity::first(); $aps = lastMessage()['apns']['payload']['aps'];
    check($aps['event'] === 'end' && $aps['content-state']['order_status'] === 'Delivered' && $aps['dismissal-date'] === $aps['timestamp'], 'completion sends ActivityKit end with final delivered state');
    check($row->ended_at !== null && $row->activity_token === '' && $row->fcm_token === '', 'accepted terminal receipt closes registration and erases device secrets');
    $sent = count($transport->messages); $service->register(7375, $actor, $rotated); $app->terminate();
    check(count($transport->messages) === $sent, 'late duplicate registration cannot reopen acknowledged terminal activity');

    // Token arrives after order completion: catch-up still sends end to the new actual activity.
    $late = array_replace($input, ['activity_id' => 'activity-late', 'activity_token' => str_repeat('aa', 32)]);
    $service->register(7375, $actor, $late); $app->terminate();
    check(lastMessage()['apns']['payload']['aps']['event'] === 'end', 'late activity token receives terminal catch-up immediately');

    state('preparing'); $racing = array_replace($input, ['activity_id' => 'activity-racing', 'activity_token' => str_repeat('bb', 32)]);
    $service->register(7375, $actor, $racing);
    $transport->duringSend = function () use ($service, $actor, $racing) {
        state('completed');
        $service->register(7375, $actor, array_replace($racing, ['activity_token' => str_repeat('cc', 32)]));
    };
    $app->terminate();
    $race = OrderLiveActivity::where('activity_id_hash', hash('sha256', 'activity-racing'))->first();
    check($race->ended_at === null && $race->state_hash === null && $race->token_hash === hash('sha256', str_repeat('cc', 32)), 'stale HTTP receipt cannot acknowledge a rotated activity token');
    $app->terminate();
    $race->refresh(); check($race->ended_at !== null && lastMessage()['apns']['live_activity_token'] === str_repeat('cc', 32), 'new token receives final state after concurrent rotation');

    state('preparing'); $concurrent = array_replace($input, ['activity_id' => 'activity-concurrent', 'activity_token' => str_repeat('dd', 32)]);
    $service->register(7375, $actor, $concurrent);
    $transport->duringSend = function () use ($service) { state('completed'); $service->deliverOrder(7375); };
    $app->terminate();
    $concurrentRow = OrderLiveActivity::where('activity_id_hash', hash('sha256', 'activity-concurrent'))->first();
    check($concurrentRow->ended_at !== null && $concurrentRow->state_hash !== null, 'older update receipt cannot reopen a concurrently acknowledged end');
    $messages = array_slice($transport->messages, -2);
    check($messages[1]['message']['apns']['payload']['aps']['timestamp'] > $messages[0]['message']['apns']['payload']['aps']['timestamp'], 'concurrent end carries a greater APNs timestamp than pending older update');

    $before = OrderLiveActivity::count(); DB::beginTransaction();
    $service->register(7375, $actor, array_replace($input, ['activity_id' => 'activity-rollback'])); DB::rollBack();
    $sent = count($transport->messages); $app->terminate();
    check(OrderLiveActivity::count() === $before && count($transport->messages) === $sent, 'rolled-back registration sends nothing');
    date_default_timezone_set('Africa/Cairo');
    $app['config']->set('app.timezone', 'Africa/Cairo');
    state('preparing'); $service->register(7375, $actor, array_replace($input, ['activity_id' => 'activity-cairo'])); $app->terminate();
    \Carbon\Carbon::setTestNow(now('UTC')->addHours(7)); state('shipped');
    $sent = count($transport->messages); $service->deliverOrder(7375);
    check(count($transport->messages) === $sent + 1, 'UTC registration expiry remains eight hours in Cairo timezone');
    \Carbon\Carbon::setTestNow(now('UTC')->addHours(9)); $service->retryPending();
    check(OrderLiveActivity::count() === 0, 'expired registrations are pruned within bounded ActivityKit lifetime');

    $http = new LiveHttpClient; $app->instance('Illuminate\\Http\\Client\\Factory', $http);
    $http->response = new LiveHttpResponse(true, ['name' => 'projects/test-project/messages/receipt']);
    $actualTransport = new LiveAuthenticatedTransport;
    $actualTransport->send($input['fcm_token'], $input['activity_token'], $content, 100, false);
    check($http->options['connect_timeout'] === 4 && $http->options['timeout'] === 12 && count($http->requests) === 1, 'Laravel 8 HTTP transport uses bounded connect and overall timeouts');
    foreach ([new LiveHttpResponse(false, ['error' => ['message' => 'SECRET token']]), new LiveHttpResponse(true, []), new LiveHttpResponse(true, ['name' => 'receipt', 'error' => 'refused'])] as $refusal) {
        $http->response = $refusal;
        try { $actualTransport->send($input['fcm_token'], $input['activity_token'], $content, 100, true); throw new \LogicException('unacknowledged refusal'); }
        catch (\RuntimeException $error) { check($error->getMessage() === 'live_activity_provider_refusal', 'FCM refusal requires accepted receipt and exposes no provider body'); }
    }
    echo 'ORDER_LIVE_ACTIVITY_RUNTIME_OK checks='.$count.PHP_EOL;
}
