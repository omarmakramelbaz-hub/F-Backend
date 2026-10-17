<?php
// Isolated real SQLite transactions, fake Graph only. Never boot the server or contact a customer.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) { fwrite(STDERR, "CRM_TEST_VENDOR_REQUIRED\n"); exit(1); }
require $autoload;

use App\Services\Dashboard\CustomerMessagingService;
use App\Services\Dashboard\CustomerMessageEvents;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

if (!function_exists('config')) {
    function config($key = null, $default = null) { $r = Container::getInstance()->make('config'); return $key === null ? $r : $r->get($key, $default); }
}
$container = new Container(); Container::setInstance($container); $capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setEventDispatcher(new Dispatcher($container)); $capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('config', new Repository(['desktop_dashboard' => ['local' => false],
    'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]]]));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC')); Facade::setFacadeApplication($container);
require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxAccess.php';
require __DIR__ . '/../app/Services/Dashboard/CustomerMessagingService.php';
require __DIR__ . '/../app/Services/Dashboard/CustomerDirectory.php';
require __DIR__ . '/../app/Services/Dashboard/CustomerMessageEvents.php';
require __DIR__ . '/../database/migrations/2026_10_17_000001_create_crm_customer_directory.php';
require __DIR__ . '/../database/migrations/2026_10_17_000002_create_crm_whatsapp_messaging.php';
require __DIR__ . '/../database/migrations/2026_10_17_000004_create_crm_whatsapp_status_receipts.php';
(new CreateCrmCustomerDirectory())->up(); (new CreateCrmWhatsappMessaging())->up();
(new CreateCrmWhatsappStatusReceipts())->up();

$checks = 0; $sequence = 0; $mode = 'accepted'; $calls = []; $posts = [];
$clockNow = CarbonImmutable::parse('2026-10-17 13:00:00', 'UTC'); $purchaseActive = true; $hook = null;
$feedbackBody = "رأيك يهمنا في المنتج والخدمة المقدمة، يُرجى تقديم ملاحظات حضرتك في هذه الرسالة لتحسين جودة المنتج والخدمة.\nطلب رقم {{1}} – فرع {{2}}";
$configuration = ['enabled' => false, 'feedback_enabled' => false, 'feedback_template_name' => '', 'feedback_language' => 'ar',
    'feedback_body' => $feedbackBody, 'feedback_parameters' => ['source_id', 'branch'], 'batch_size' => 25, 'per_minute' => 25,
    'access_token' => 'fixture-only', 'sender_enabled' => true, 'marketing_cooldown_hours' => 24];
$rawTemplates = [
    ['name' => 'order_feedback', 'language' => 'ar', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => $feedbackBody]]],
    ['name' => 'offer', 'language' => 'ar', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'عرضنا الجديد: {{1}}']]],
    ['name' => 'pending', 'language' => 'ar', 'category' => 'MARKETING', 'status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'Test']]],
    ['name' => 'wrong_feedback', 'language' => 'ar', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Different body']]],
    ['name' => 'media', 'language' => 'ar', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Test']]],
];
function checkCrm($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejectCrm(callable $operation, int $status, string $message): void {
    try { $operation(); } catch (HttpException $e) { checkCrm($e->getStatusCode() === $status, $message); return; }
    throw new RuntimeException($message);
}
$transport = function ($method, $url, $payload, $token) use (&$calls, &$posts, &$mode, &$rawTemplates, &$sequence, &$hook) {
    $calls[] = [$method, $url, $payload]; checkCrm($token === 'fixture-only', 'existing token only');
    if ($method === 'GET') {
        checkCrm($url === 'https://graph.facebook.com/v25.0/468336579702269/message_templates', 'fixed WABA template endpoint');
        if ($mode === 'metadata-failure') return ['status' => 500, 'body' => '{}'];
        return ['status' => 200, 'body' => json_encode(['data' => $rawTemplates], JSON_UNESCAPED_UNICODE)];
    }
    $posts[] = $payload;
    checkCrm(DB::connection()->transactionLevel() === 0, 'external send outside SQL transaction');
    checkCrm(DB::table('crm_whatsapp_deliveries')->where('state', 'unknown')->exists(), 'unknown receipt committed before send');
    checkCrm($url === 'https://graph.facebook.com/v25.0/515388018324075/messages', 'fixed existing phone endpoint');
    checkCrm($payload['type'] === 'template' && !isset($payload['text']) && preg_match('/^[1-9][0-9]{7,14}$/', $payload['to']), 'template and server-owned recipient');
    if ($hook !== null) { $run = $hook; $hook = null; $run(); }
    if ($mode === 'throw') throw new RuntimeException('fixture transport interruption');
    if ($mode === 'rate-limit') return ['status' => 429, 'body' => '{"error":{"code":130429}}'];
    if ($mode === 'reject') return ['status' => 400, 'body' => '{"error":{"code":131026}}'];
    if ($mode === 'server-error') return ['status' => 503, 'body' => '{"error":{"code":2}}'];
    if ($mode === 'malformed') return ['status' => 200, 'body' => '{}'];
    return ['status' => 200, 'body' => json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['input' => $payload['to'], 'wa_id' => $payload['to']]],
        'messages' => [['id' => 'wamid.fixture' . ++$sequence]]])];
};
$authorize = function ($actor) { if (($actor->id ?? 0) !== 1) throw new HttpException(403); return $actor; };
$clock = function () use (&$clockNow) { return $clockNow; };
$guard = function ($purchase) use (&$purchaseActive) { return $purchaseActive; };
function serviceCrm(?array $override = null): CustomerMessagingService {
    global $transport, $authorize, $clock, $configuration, $guard;
    return new CustomerMessagingService($transport, $authorize, $clock, $override === null ? $configuration : array_merge($configuration, $override), $guard);
}
$actor = (object) ['id' => 1];
function resetCrm(): void {
    global $calls, $posts, $mode, $clockNow, $purchaseActive, $hook;
    foreach (['crm_whatsapp_status_receipts', 'crm_customer_feedback_replies', 'crm_whatsapp_deliveries', 'crm_whatsapp_campaigns', 'crm_purchases', 'crm_customer_sources', 'crm_customers'] as $table) DB::table($table)->delete();
    DB::table('crm_whatsapp_settings')->where('id', 1)->update(['enabled' => false, 'feedback_enabled' => false, 'feedback_template_name' => '', 'feedback_language' => 'ar']);
    DB::table('crm_whatsapp_dispatch_gate')->where('id', 1)->update(['window_started_at' => null, 'window_count' => 0, 'blocked_until' => null]);
    $calls = []; $posts = []; $mode = 'accepted'; $purchaseActive = true; $hook = null; $clockNow = CarbonImmutable::parse('2026-10-17 13:00:00', 'UTC');
}
function enableCrm(bool $feedback = true): void {
    global $actor; serviceCrm()->configure($actor, ['enabled' => true, 'feedback_enabled' => $feedback,
        'feedback_template_name' => 'order_feedback', 'feedback_language' => 'ar']);
}
function customerCrm(string $phone, bool $service = true, bool $marketing = true, bool $out = false): int {
    global $clockNow; $stamp = $clockNow->subDays(2)->format('Y-m-d H:i:s');
    return DB::table('crm_customers')->insertGetId(['phone' => $phone, 'phone_key' => ltrim($phone, '+'), 'service_consent' => $service,
        'marketing_consent' => $marketing, 'service_consented_at' => $service ? $stamp : null, 'marketing_consented_at' => $marketing ? $stamp : null,
        'opted_out_at' => $out ? $stamp : null, 'first_seen_at' => $stamp, 'last_seen_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
}
function purchaseCrm(int $customer, int $age = 24): int {
    global $clockNow, $sequence; $paid = $clockNow->subHours($age);
    return DB::table('crm_purchases')->insertGetId(['customer_id' => $customer, 'branch' => 'f:1', 'channel' => 'takeaway',
        'source_type' => 'takeaway_orders', 'source_id' => (string) ++$sequence, 'paid_at' => $paid->format('Y-m-d H:i:s'),
        'feedback_due_at' => $paid->addHours(24)->format('Y-m-d H:i:s'), 'feedback_status' => 'pending', 'created_at' => $paid, 'updated_at' => $paid]);
}
function campaignCrm(?string $uuid = null, array $parameters = ['خصم خاص']): array {
    global $actor, $sequence;
    if ($uuid === null) $uuid = sprintf('11223344-5566-4778-899a-%012d', ++$sequence);
    return serviceCrm()->queueCampaign($actor, ['client_request_id' => $uuid, 'template_name' => 'offer', 'language' => 'ar', 'parameters' => $parameters]);
}

resetCrm();
checkCrm(serviceCrm()->process()['reason'] === 'CRM_SENDER_DISABLED' && count($calls) === 0, 'default-disabled worker does nothing');
$status = serviceCrm()->status();
checkCrm($status['available'] && !$status['enabled'] && count($status['marketing_templates']) === 1 && count($status['feedback_templates']) === 1,
    'setup can read approved supported templates while disabled');
rejectCrm(fn() => serviceCrm()->configure((object) ['id' => 2], ['enabled' => true, 'feedback_enabled' => false]), 403, 'central actor required');
rejectCrm(fn() => serviceCrm()->configure($actor, ['enabled' => false, 'feedback_enabled' => true]), 422, 'feedback cannot enable without sender');
rejectCrm(fn() => serviceCrm()->configure($actor, ['enabled' => true, 'feedback_enabled' => true, 'feedback_template_name' => 'wrong_feedback', 'feedback_language' => 'ar']), 422, 'feedback exact approved body required');
enableCrm(); checkCrm(serviceCrm()->status()['feedback_ready'], 'approved exact utility template activates');
$a = customerCrm('+201111111111'); $due = purchaseCrm($a); $future = purchaseCrm($a, 23);
$result = serviceCrm()->process();
checkCrm($result['queued_feedback'] === 1 && $result['accepted'] === 1 && count($posts) === 1, 'only purchase at elapsed 24h sends feedback');
checkCrm($posts[0]['template']['name'] === 'order_feedback' && $posts[0]['template']['components'][0]['parameters'][1]['text'] === 'رقم 1', 'feedback bound to order and branch');
checkCrm(DB::table('crm_purchases')->where('id', $due)->value('feedback_status') === 'accepted'
    && DB::table('crm_purchases')->where('id', $future)->value('feedback_status') === 'pending', 'purchase receipt synchronized');
serviceCrm()->process(); checkCrm(count($posts) === 1, 'feedback rerun cannot duplicate');

resetCrm(); enableCrm();
foreach ([[false, false, false], [true, true, true]] as $flags) purchaseCrm(customerCrm('+20111111111' . count($posts) . (int) $flags[2], ...$flags));
serviceCrm()->process(); checkCrm(count($posts) === 0 && DB::table('crm_whatsapp_deliveries')->where('state', 'skipped')->count() === 2, 'phone capture and opt-out never grant consent');
resetCrm(); enableCrm(); $a = customerCrm('+201111111111'); DB::table('crm_customers')->where('id', $a)->update(['service_consented_at' => null]); purchaseCrm($a);
serviceCrm()->process(); checkCrm(count($posts) === 0, 'consent evidence timestamp required');

resetCrm(); enableCrm(false); $a = customerCrm('+201111111111'); $b = customerCrm('+201222222222'); customerCrm('+201333333333', true, false); customerCrm('+201444444444', true, true, true);
$uuid = '11223344-5566-4778-899a-123456789012'; $campaign = campaignCrm($uuid);
checkCrm($campaign['recipient_count'] === 2 && !$campaign['replayed'] && count($posts) === 0, 'campaign click queues only explicit marketing consent');
$replay = campaignCrm($uuid); checkCrm($replay['replayed'] && $replay['campaign_id'] === $campaign['campaign_id'] && DB::table('crm_whatsapp_deliveries')->count() === 2, 'UUID idempotency preserves recipient snapshot');
rejectCrm(fn() => campaignCrm($uuid, ['Changed']), 409, 'same UUID with changed input conflicts');
rejectCrm(fn() => campaignCrm(null, []), 422, 'template parameter arity checked');
checkCrm(strpos(DB::table('crm_whatsapp_campaigns')->value('parameters'), 'خصم خاص') === false, 'campaign parameter text encrypted');
DB::table('crm_customers')->where('id', $b)->update(['marketing_consent' => false]);
serviceCrm()->process(); checkCrm(count($posts) === 1 && $posts[0]['to'] === '201111111111', 'consent rechecked after campaign snapshot');
$next = campaignCrm(); serviceCrm()->process();
checkCrm(count($posts) === 1 && DB::table('crm_whatsapp_deliveries')->where('campaign_id', $next['campaign_id'])->value('next_attempt_at') !== null, 'marketing cooldown stops repeated campaign clicks');

foreach (['throw', 'server-error', 'malformed'] as $failure) {
    resetCrm(); enableCrm(); $a = customerCrm('+201111111111'); purchaseCrm($a); $mode = $failure;
    serviceCrm()->process(); $mode = 'accepted'; serviceCrm()->process();
    checkCrm(count($posts) === 1 && DB::table('crm_whatsapp_deliveries')->value('state') === 'unknown', 'ambiguous outcome is never retried: ' . $failure);
    checkCrm(DB::table('crm_purchases')->value('feedback_status') === 'unknown', 'purchase ambiguity durable');
}
resetCrm(); enableCrm(false); customerCrm('+201111111111'); campaignCrm(); $mode = 'throw'; serviceCrm()->process();
checkCrm(DB::table('crm_whatsapp_campaigns')->value('status') === 'review_required', 'campaign unknown requires explicit delivery review');

resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111')); $mode = 'rate-limit';
serviceCrm()->process(); checkCrm(DB::table('crm_whatsapp_deliveries')->value('state') === 'queued', 'definitive rate rejection retries after backoff');
$mode = 'accepted'; serviceCrm()->process(); checkCrm(count($posts) === 1, 'rate limit blocks early retry');
$clockNow = $clockNow->addMinutes(5); serviceCrm()->process(); checkCrm(count($posts) === 2 && DB::table('crm_whatsapp_deliveries')->value('state') === 'accepted', 'rate retry resumes after safe cooldown');
resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111')); $mode = 'reject'; serviceCrm()->process(); $mode = 'accepted'; serviceCrm()->process();
checkCrm(count($posts) === 1 && DB::table('crm_whatsapp_deliveries')->value('state') === 'failed', 'definitive permanent failure never loops');

resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111')); $purchaseActive = false; serviceCrm()->process();
checkCrm(count($posts) === 0 && DB::table('crm_whatsapp_deliveries')->value('state') === 'skipped', 'cancelled or refunded source suppressed');
resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111'), 240); serviceCrm()->process();
checkCrm(count($posts) === 0 && DB::table('crm_whatsapp_deliveries')->value('state') === 'skipped', 'stale backlog expires');
resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111')); $hook = fn() => serviceCrm()->process(); serviceCrm()->process();
checkCrm(count($posts) === 1 && DB::table('crm_whatsapp_deliveries')->value('state') === 'accepted', 'overlapping worker cannot claim same in-flight message');
resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111')); purchaseCrm(customerCrm('+201222222222'));
serviceCrm(['per_minute' => 1])->process(); checkCrm(count($posts) === 1, 'global minute gate bounds concurrent dispatch capacity');
serviceCrm(['per_minute' => 1])->process(); checkCrm(count($posts) === 1, 'global throttle persists across workers');
$clockNow = $clockNow->addMinute(); serviceCrm(['per_minute' => 1])->process(); checkCrm(count($posts) === 2, 'throttle resets after a full minute');

resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111')); $mode = 'metadata-failure'; serviceCrm()->process();
checkCrm(count($posts) === 0 && DB::table('crm_purchases')->value('feedback_status') === 'pending', 'unverifiable template approval fails closed without losing purchase');
$container->make('config')->set('desktop_dashboard.local', true); checkCrm(serviceCrm()->process()['reason'] === 'LOCAL_DEVICE_DISABLED', 'desktop device never dispatches shared campaigns');
$container->make('config')->set('desktop_dashboard.local', false);

// Meta can deliver read/delivered callbacks before its HTTP acceptance is persisted.
resetCrm(); enableCrm(); purchaseCrm(customerCrm('+201111111111'));
\Carbon\Carbon::setTestNow($clockNow);
DB::table('crm_whatsapp_status_receipts')->insert(['provider_message_id'=>'wamid.expired-invoice',
    'delivery_status'=>'delivered', 'status_at'=>$clockNow->subDays(8)->format('Y-m-d H:i:s'),
    'received_at'=>$clockNow->subDays(8)->format('Y-m-d H:i:s'), 'created_at'=>$clockNow->subDays(8), 'updated_at'=>$clockNow->subDays(8)]);
$earlyMessage = null;
$hook = function () use (&$sequence, &$earlyMessage) {
    $earlyMessage = 'wamid.fixture'.($sequence + 1);
    $events = new CustomerMessageEvents();
    $events->status(['message_id'=>$earlyMessage, 'status'=>'read', 'sent_at'=>'2026-10-17 13:00:02', 'recipient_phone'=>'201111111111']);
    $events->status(['message_id'=>$earlyMessage, 'status'=>'delivered', 'sent_at'=>'2026-10-17 13:00:01', 'recipient_phone'=>'201111111111']);
    $events->status(['message_id'=>'wamid.unrelated-invoice', 'status'=>'delivered', 'sent_at'=>'2026-10-17 13:00:01']);
    checkCrm(DB::table('crm_whatsapp_deliveries')->value('provider_message_id') === null,
        'early callbacks arrive before native ID is attached');
};
serviceCrm()->process();
$receipt = DB::table('crm_whatsapp_deliveries')->first();
checkCrm(count($posts) === 1 && $receipt->state === 'accepted' && $receipt->provider_message_id === $earlyMessage,
    'early callbacks do not cause another send or replace acceptance');
checkCrm($receipt->delivery_status === 'read' && $receipt->read_at === '2026-10-17 13:00:02'
    && $receipt->delivered_at === '2026-10-17 13:00:01', 'acceptance reconciles early receipts without regressing read');
checkCrm(DB::table('crm_whatsapp_status_receipts')->where('provider_message_id', 'wamid.unrelated-invoice')->exists(),
    'unrelated invoice receipt is retained for later correlation');
checkCrm(!DB::table('crm_whatsapp_status_receipts')->where('provider_message_id', 'wamid.expired-invoice')->exists(),
    'old orphan receipts expire after seven days');
serviceCrm()->process(); checkCrm(count($posts) === 1, 'reconciled early receipt never requeues accepted delivery');
\Carbon\Carbon::setTestNow();

// Cursor traversal always reconstructs the fixed Graph endpoint; provider next URL is never followed.
resetCrm(); $paginationCalls = [];
$paginationTransport = function ($method, $url, $payload, $token) use (&$paginationCalls, $rawTemplates) {
    $paginationCalls[] = [$url, $payload];
    return ['status' => 200, 'body' => json_encode(isset($payload['after']) ? ['data' => [$rawTemplates[1]]] :
        ['data' => [$rawTemplates[0]], 'paging' => ['next' => 'https://untrusted.invalid/do-not-follow', 'cursors' => ['after' => 'cursor123']]])];
};
$paginated = new CustomerMessagingService($paginationTransport, $authorize, $clock, $configuration, $guard);
$status = $paginated->status(); checkCrm(count($paginationCalls) === 2 && count($status['marketing_templates']) === 1, 'bounded cursor pagination discovers templates after first page');
checkCrm($paginationCalls[0][0] === $paginationCalls[1][0] && $paginationCalls[1][1]['after'] === 'cursor123', 'pagination never forwards token to supplied next URL');

echo "CRM_WHATSAPP_MESSAGING_OK {$checks}\n";
