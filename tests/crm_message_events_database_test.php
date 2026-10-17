<?php

// Isolated SQLite/Illuminate integration. No application bootstrap or outbound transport.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) {
    fwrite(STDERR, "Set WA_INBOX_TEST_VENDOR to the isolated Composer autoloader.\n"); exit(1);
}
require $autoload;

use App\Services\Dashboard\CustomerDirectory;
use App\Services\Dashboard\CustomerMessageEvents;
use App\Services\Dashboard\WhatsAppInboxConsumer;
use App\Support\WhatsAppInboxProtocol;
use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

if (!function_exists('config')) {
    function config($key = null, $default = null) { return Container::getInstance()->make('config')->get($key, $default); }
}
if (!function_exists('now')) {
    function now($timezone = null) { return Carbon::now($timezone); }
}
$container = new Container(); Container::setInstance($container);
$capsule = new Capsule($container);
$connection = ['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true];
$capsule->addConnection($connection); $capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal(); $capsule->bootEloquent();
$key = str_repeat('k', 32);
$container->instance('config', new Repository(['app'=>['key'=>'base64:'.base64_encode($key), 'cipher'=>'AES-256-CBC'],
    'database'=>['default'=>'default', 'connections'=>['default'=>$connection]]]));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); unset($key);
Facade::setFacadeApplication($container);
Carbon::setTestNow('2026-10-17 12:00:00');

foreach (['app/Support/WhatsAppInboxProtocol.php', 'app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php', 'app/Services/Dashboard/CustomerDirectory.php',
    'app/Services/Dashboard/CustomerMessageEvents.php',
    'database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php',
    'database/migrations/2026_10_17_000001_create_crm_customer_directory.php',
    'database/migrations/2026_10_17_000002_create_crm_whatsapp_messaging.php',
    'database/migrations/2026_10_17_000004_create_crm_whatsapp_status_receipts.php'] as $file) require __DIR__.'/../'.$file;
Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
    $table->bigIncrements('id'); $table->char('payload_hash', 64)->unique();
    $table->longText('payload'); $table->timestamp('received_at'); $table->timestamp('processed_at')->nullable();
});
(new CreateWhatsAppInboxTables())->up();
(new CreateCrmCustomerDirectory())->up();
(new CreateCrmWhatsappMessaging())->up();
(new CreateCrmWhatsappStatusReceipts())->up();

$checks = 0;
function checkCrm(bool $ok, string $label): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('CRM assertion failed: '.$label); }
function crmMessage(string $id, string $text = 'أهلاً', array $extra = []): array {
    return array_replace(['id'=>$id, 'timestamp'=>'1792237800', 'type'=>'text', 'from'=>'201000000001', 'from_user_id'=>'US.CRM', 'text'=>['body'=>$text]], $extra);
}
function crmPayload(array $records, string $key = 'messages', string $field = 'messages'): array {
    static $nonce = 0;
    return ['object'=>'whatsapp_business_account', 'fixture_nonce'=>++$nonce, 'entry'=>[[
        'id'=>WhatsAppInboxConsumer::WABA_ID, 'changes'=>[['field'=>$field, 'value'=>[
            'messaging_product'=>'whatsapp',
            'metadata'=>['phone_number_id'=>WhatsAppInboxConsumer::PHONE_ID, 'display_phone_number'=>'201285545554'],
            'contacts'=>[['wa_id'=>'201000000001', 'profile'=>['name'=>'عميل تجريبي']]], $key=>$records,
        ]]],
    ]]];
}
function crmEvent(array $payload): int {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return DB::table('whatsapp_webhook_events')->insertGetId(['payload_hash'=>hash('sha256', $json),
        'payload'=>Crypt::encryptString($json), 'received_at'=>now('UTC'), 'processed_at'=>null]);
}
function crmConsume(array $payload): array { crmEvent($payload); return (new WhatsAppInboxConsumer())->consume(100); }
function crmDelivery(int $customerId, string $messageId, string $when, string $kind = 'feedback'): int {
    return DB::table('crm_whatsapp_deliveries')->insertGetId(['customer_id'=>$customerId, 'kind'=>$kind,
        'state'=>'accepted', 'provider_message_id'=>$messageId, 'accepted_at'=>$when,
        'created_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
}

foreach (['STOP', ' stop! ', 'Unsubscribe', 'إلغاء', 'الغاء', 'توقف', 'إِلْغَاء', 'إلغاء الاشتراك'] as $text) {
    checkCrm(CustomerMessageEvents::isOptOut($text), 'whole opt-out directive '.$text);
}
foreach (['إلغاء الطلب', 'توقف الطلب', 'عايز الغاء اوردر', 'please stop by', '', 'stop order', null] as $text) {
    checkCrm(!CustomerMessageEvents::isOptOut($text), 'order cancellation is not an opt-out');
}

$r = crmConsume(crmPayload([crmMessage('wamid.crm.initial')]));
checkCrm($r['messages_inserted'] === 1 && $r['errors'] === 0, 'verified inbound projects without errors');
$customer = DB::table('crm_customers')->first();
checkCrm($customer->phone === '+201000000001' && $customer->name === 'عميل تجريبي', 'capture name and normalized phone immediately');
checkCrm(DB::table('crm_customer_sources')->count() === 0 && DB::table('crm_purchases')->count() === 0, 'WhatsApp inquiry does not invent a sales channel or purchase');
checkCrm(!(bool)$customer->service_consent && !(bool)$customer->marketing_consent, 'incoming message is not consent');

$directory = new CustomerDirectory(); $directory->consent($customer->id, true, true, 'isolated fixture consent');
crmConsume(crmPayload([crmMessage('wamid.crm.cancel-order', 'إلغاء الطلب')]));
checkCrm((bool)DB::table('crm_customers')->value('marketing_consent'), 'order cancellation preserves messaging consent');
crmConsume(crmPayload([crmMessage('wamid.crm.stop', 'STOP')]));
$opted = DB::table('crm_customers')->first();
checkCrm(!(bool)$opted->service_consent && !(bool)$opted->marketing_consent && $opted->opted_out_at !== null, 'exact STOP revokes both purposes');
checkCrm($opted->service_consent_evidence === 'whatsapp-inbound:opt-out', 'opt-out audit evidence retained');
$directory->consent($customer->id, true, true, 'new explicit fixture consent');
$buidOnly = crmMessage('wamid.crm.stop-buid', 'توقف'); unset($buidOnly['from']);
$r = crmConsume(crmPayload([$buidOnly]));
checkCrm($r['errors'] === 0 && !(bool)DB::table('crm_customers')->value('marketing_consent'), 'BUID-only directive reuses verified phone from the same thread');
$directory->consent($customer->id, true, true, 'new explicit fixture consent');
$r = crmConsume(crmPayload([crmMessage('wamid.crm.stop', 'STOP')], 'messages', 'standby'));
checkCrm($r['messages_replayed'] === 1 && (bool)DB::table('crm_customers')->value('marketing_consent'), 'replayed old STOP cannot revoke newer consent');

$first = crmDelivery($customer->id, 'wamid.crm.survey-first', '2026-10-16 10:00:00');
$latest = crmDelivery($customer->id, 'wamid.crm.survey-latest', '2026-10-17 10:00:00');
crmDelivery($customer->id, 'wamid.crm.survey-future', '2026-10-18 10:00:00');
$r = crmConsume(crmPayload([crmMessage('wamid.crm.feedback', 'المنتج ممتاز')]));
$reply = DB::table('crm_customer_feedback_replies')->first();
checkCrm($r['errors'] === 0 && (int)$reply->delivery_id === $latest, 'unquoted reply links to latest survey already sent');
checkCrm(Crypt::decryptString($reply->body) === 'المنتج ممتاز' && strpos($reply->body, 'المنتج') === false, 'feedback text encrypted at rest');
checkCrm($reply->inbox_message_id !== null, 'feedback retains inbox evidence link');
crmConsume(crmPayload([crmMessage('wamid.crm.feedback', 'المنتج ممتاز')], 'messages', 'standby'));
checkCrm(DB::table('crm_customer_feedback_replies')->count() === 1, 'inbound replay cannot duplicate feedback');
crmConsume(crmPayload([crmMessage('wamid.crm.feedback-explicit', 'تمام', ['context'=>['id'=>'wamid.crm.survey-first']])]));
checkCrm((int)DB::table('crm_customer_feedback_replies')->where('provider_message_id', 'wamid.crm.feedback-explicit')->value('delivery_id') === $first, 'explicit survey reply retains its order');
crmConsume(crmPayload([crmMessage('wamid.crm.unrelated', 'عنواني الجديد', ['context'=>['id'=>'wamid.crm.some-order-message']])]));
checkCrm(DB::table('crm_customer_feedback_replies')->count() === 2, 'unrelated quote not attached to a survey');

$unknownBuid = crmMessage('wamid.crm.no-known-phone', 'طلب جديد', ['from_user_id'=>'US.CRM_UNKNOWN']); unset($unknownBuid['from']);
$r = crmConsume(crmPayload([$unknownBuid]));
checkCrm($r['errors'] === 0 && DB::table('crm_customers')->count() === 1, 'unknown BUID never invents a phone or a CRM contact');
$echo = crmMessage('wamid.crm.echo', 'Agent reply', ['from'=>'201285545554', 'to'=>'201000000002', 'to_user_id'=>'US.CRM_OTHER']); unset($echo['from_user_id']);
$r = crmConsume(crmPayload([$echo], 'message_echoes', 'smb_message_echoes'));
checkCrm($r['messages_inserted'] === 1 && $r['errors'] === 0 && DB::table('crm_customers')->count() === 1 && DB::table('crm_customer_feedback_replies')->count() === 2, 'outbound echo does not invent inbound contact or feedback');

$statuses = crmPayload([['id'=>'wamid.crm.survey-latest', 'status'=>'read', 'timestamp'=>'1792238000', 'recipient_id'=>'201000000001']], 'statuses');
$report = WhatsAppInboxProtocol::report($statuses);
checkCrm($report['status_count'] === 1 && count($report['statuses']) === 1 && $report['messages'] === [], 'receipt parsed separately from messages');
$r = crmConsume($statuses);
checkCrm($r['status_records'] === 1 && $r['errors'] === 0, 'scoped receipt updates CRM atomically');
crmConsume(crmPayload([['id'=>'wamid.crm.survey-latest', 'status'=>'delivered', 'timestamp'=>'1792237900', 'recipient_id'=>'201000000001']], 'statuses'));
crmConsume(crmPayload([['id'=>'wamid.crm.survey-latest', 'status'=>'failed', 'timestamp'=>'1792238100', 'recipient_id'=>'201000000001']], 'statuses'));
$delivery = DB::table('crm_whatsapp_deliveries')->where('id', $latest)->first();
checkCrm($delivery->delivery_status === 'read' && $delivery->read_at !== null && $delivery->delivered_at !== null && $delivery->state === 'accepted', 'out-of-order failure never regresses read state or retries acceptance');
crmConsume(crmPayload([['id'=>'wamid.crm.survey-first', 'status'=>'read', 'timestamp'=>'1792238000', 'recipient_id'=>'201000000009']], 'statuses'));
checkCrm(DB::table('crm_whatsapp_deliveries')->where('id', $first)->value('delivery_status') === null, 'mismatched receipt recipient cannot update delivery');
$foreign = crmPayload([['id'=>'wamid.crm.survey-first', 'status'=>'read', 'timestamp'=>'1792238000']], 'statuses');
$foreign['entry'][0]['id'] = '999999999'; crmConsume($foreign);
checkCrm(DB::table('crm_whatsapp_deliveries')->where('id', $first)->value('delivery_status') === null, 'foreign account receipt cannot update delivery');

$campaign = crmDelivery($customer->id, 'wamid.crm.campaign', '2026-10-17 10:00:00', 'campaign');
crmConsume(crmPayload([['id'=>'wamid.crm.campaign', 'status'=>'delivered', 'timestamp'=>'1792237900']], 'statuses'));
checkCrm(DB::table('crm_whatsapp_deliveries')->where('id', $campaign)->value('delivery_status') === 'delivered', 'campaign recipients receive the same receipt bookkeeping');
checkCrm(DB::table('crm_whatsapp_status_receipts')->where('provider_message_id', 'wamid.crm.campaign')->exists(), 'scoped receipt remains durable');
checkCrm(DB::table('crm_whatsapp_status_receipts')->where('provider_message_id', 'wamid.crm.survey-latest')->value('recipient_hash') === hash('sha256', '+201000000001'), 'orphan receipt stores no plain phone');

// CRM failure must preserve the existing inbox transaction boundary and retry path.
DB::unprepared("CREATE TRIGGER crm_fixture_failure BEFORE INSERT ON crm_customer_feedback_replies BEGIN SELECT RAISE(ABORT, 'fixture CRM write failure'); END");
$id = crmEvent(crmPayload([crmMessage('wamid.crm.rollback', 'محتاج متابعة')]));
$r = (new WhatsAppInboxConsumer())->consume();
checkCrm($r['errors'] === 1 && DB::table('whatsapp_webhook_events')->where('id', $id)->value('processed_at') === null, 'CRM write failure leaves raw event retryable');
$key = hash('sha256', 'whatsapp-message-v1:'.WhatsAppInboxConsumer::WABA_ID.':'.WhatsAppInboxConsumer::PHONE_ID.':wamid.crm.rollback');
checkCrm(!DB::table('whatsapp_inbox_messages')->where('message_key', $key)->exists(), 'CRM failure rolls back inbox insertion');
DB::unprepared('DROP TRIGGER crm_fixture_failure');
$r = (new WhatsAppInboxConsumer())->consume();
checkCrm($r['messages_inserted'] === 1 && $r['errors'] === 0, 'retry commits both inbox and feedback after transient CRM failure');

echo "CRM message events: {$checks} assertions passed.\n";
