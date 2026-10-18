<?php
// Real isolated SQLite, fake Meta marketing template management only. No customer message API calls.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) { fwrite(STDERR, "CRM_TEST_VENDOR_REQUIRED\n"); exit(1); }
require $autoload;

use App\Services\Dashboard\CustomerMessagingService;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
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
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder()); Facade::setFacadeApplication($container);
require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxAccess.php';
require __DIR__ . '/../app/Services/Dashboard/CustomerMessagingService.php';
require __DIR__ . '/../database/migrations/2026_10_17_000001_create_crm_customer_directory.php';
require __DIR__ . '/../database/migrations/2026_10_17_000002_create_crm_whatsapp_messaging.php';
(new CreateCrmCustomerDirectory())->up(); (new CreateCrmWhatsappMessaging())->up();
$container->instance('encrypter', new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));

$checks = 0; $calls = []; $rawTemplates = []; $mode = 'ok';
$body = 'عروض فسخانستا الجديدة متاحة الآن. تواصل معنا لمعرفة الأصناف والأسعار.';
$configuration = ['enabled' => false, 'feedback_enabled' => false, 'feedback_template_name' => '', 'feedback_language' => 'ar',
    'feedback_body' => 'طلب رقم {{1}} – فرع {{2}}', 'feedback_parameters' => ['source_id', 'branch'], 'access_token' => 'fixture-only', 'sender_enabled' => true];
$actor = (object) ['id' => 1];
$authorize = function ($actor) { if (($actor->id ?? 0) !== 1) throw new HttpException(403); return $actor; };
$clock = fn() => CarbonImmutable::parse('2026-10-19 01:00:00', 'UTC');
$transport = function ($method, $url, $payload, $token) use (&$calls, &$rawTemplates, &$mode) {
    $calls[] = [$method, $url, $payload];
    checkMarketing($token === 'fixture-only' && $url === 'https://graph.facebook.com/v25.0/468336579702269/message_templates', 'existing account and token only; customer message endpoint never called');
    if ($method === 'GET') {
        if ($mode === 'lookup-permission') return ['status' => 403, 'body' => '{"error":{"code":200,"message":"private"}}'];
        if ($mode === 'lookup-failure') return ['status' => 503, 'body' => '{}'];
        if ($mode === 'lookup-pagination') return ['status' => 200, 'body' => '{"data":[],"paging":{"next":"https://untrusted.invalid","cursors":{"after":"same"}}}'];
        return ['status' => 200, 'body' => json_encode(['data' => $rawTemplates], JSON_UNESCAPED_UNICODE)];
    }
    checkMarketing($method === 'POST' && !isset($payload['to'], $payload['messaging_product']), 'only template preparation POST permitted');
    checkMarketing(DB::table('crm_whatsapp_deliveries')->count() === 0 && DB::table('crm_whatsapp_campaigns')->count() === 0, 'preparation creates no delivery or campaign');
    if ($mode === 'create-rejected') return ['status' => 400, 'body' => '{"error":{"code":100,"error_subcode":123,"message":"private"}}'];
    if ($mode === 'create-permission') return ['status' => 400, 'body' => '{"error":{"code":"10","message":"private"}}'];
    if ($mode === 'create-malformed') return ['status' => 200, 'body' => '{}'];
    if ($mode === 'create-server') return ['status' => 503, 'body' => '{"error":{"code":2}}'];
    $raw = $payload + ['status' => 'PENDING'];
    if ($mode === 'create-category') $raw['category'] = 'UTILITY';
    $rawTemplates[] = $raw;
    if ($mode === 'create-timeout') throw new RuntimeException('transport interrupted after provider accepted');
    return ['status' => 200, 'body' => json_encode(['id' => '1234567890123', 'status' => 'PENDING', 'category' => $raw['category']])];
};
function checkMarketing($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejectMarketing(callable $operation, int $status, string $code): void {
    try { $operation(); } catch (HttpException $e) { checkMarketing($e->getStatusCode() === $status && $e->getMessage() === $code, $code); return; }
    throw new RuntimeException('Expected ' . $code);
}
function serviceMarketing(array $overrides = []): CustomerMessagingService {
    global $transport, $authorize, $clock, $configuration;
    return new CustomerMessagingService($transport, $authorize, $clock, array_merge($configuration, $overrides));
}
function templateMarketing(string $status = 'APPROVED', ?string $text = null, ?string $name = null): array {
    global $body; $text = $text ?? $body;
    return ['name' => $name ?? 'fasakhansta_promotion_' . substr(hash('sha256', $text), 0, 16), 'language' => 'ar', 'category' => 'MARKETING', 'status' => $status,
        'components' => [['type' => 'BODY', 'text' => $text]]];
}
function resetMarketing(): void {
    global $calls, $rawTemplates, $mode, $container;
    $calls = []; $rawTemplates = []; $mode = 'ok';
    $container->make('config')->set('desktop_dashboard.local', false);
    DB::table('crm_whatsapp_deliveries')->delete(); DB::table('crm_whatsapp_campaigns')->delete(); DB::table('crm_customers')->delete();
    DB::table('crm_whatsapp_settings')->where('id', 1)->update(['enabled' => false, 'feedback_enabled' => false, 'feedback_template_name' => '', 'feedback_language' => 'ar']);
}
function postsMarketing(): array { global $calls; return array_values(array_filter($calls, fn($call) => $call[0] === 'POST')); }
function queueMarketing(string $uuid): array {
    global $actor;
    return serviceMarketing()->queueCampaign($actor, ['client_request_id' => $uuid, 'template_name' => templateMarketing()['name'], 'language' => 'ar', 'parameters' => []]);
}

resetMarketing();
$status = serviceMarketing()->status();
checkMarketing($status['marketing_setup']['state'] === 'missing' && $status['marketing_setup']['can_prepare'] && !$status['marketing_templates'], 'empty approved list has explicit connected preparation path');
$service = serviceMarketing(); $result = $service->prepareMarketingTemplate($actor, ['body' => " \r\n" . $body . "\r\n ", 'name' => 'untrusted_name', 'language' => 'en_US']);
$post = postsMarketing()[0][2]; $name = 'fasakhansta_promotion_' . substr(hash('sha256', $body), 0, 16);
checkMarketing($post === ['name' => $name, 'language' => 'ar', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => $body]]], 'server owns content-derived name, Arabic language, static body and marketing category');
checkMarketing($result['created'] && $result['marketing_setup']['state'] === 'pending' && $result['marketing_setup']['name'] === $name
    && $result['marketing_setup']['body'] === $body && $result['marketing_setup']['can_prepare'], 'receipt preserves exact candidate text and pending lifecycle while allowing different future text');
checkMarketing(!DB::table('crm_whatsapp_settings')->value('enabled') && !DB::table('crm_whatsapp_settings')->value('feedback_enabled')
    && DB::table('crm_whatsapp_campaigns')->count() === 0 && DB::table('crm_whatsapp_deliveries')->count() === 0, 'preparation never activates sender/feedback or queues messages');
$result = $service->prepareMarketingTemplate($actor, ['body' => $body]);
checkMarketing(!$result['created'] && count(postsMarketing()) === 1 && $result['marketing_setup']['state'] === 'pending', 'same normalized content is idempotent after fresh GET');
$status = serviceMarketing()->status();
checkMarketing(!$status['marketing_templates'] && count($status['marketing_setup']['managed_templates']) === 1
    && $status['marketing_setup']['managed_templates'][0]['body'] === $body && $status['marketing_setup']['state'] === 'pending', 'pending managed text visible for review but never in approved campaign selector');
$result = serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body . ' اطلب الآن.']);
checkMarketing($result['created'] && count(postsMarketing()) === 2 && $result['marketing_setup']['name'] !== $name, 'different authored content creates a separate deterministic template without editing old offers');
resetMarketing(); serviceMarketing()->prepareMarketingTemplate($actor, ['body' => "العرض الأول\r\nالعرض الثاني"]);
checkMarketing(postsMarketing()[0][2]['components'][0]['text'] === "العرض الأول\nالعرض الثاني", 'line endings normalized before hashing and creation');

foreach (['APPROVED' => 'approved', 'PENDING' => 'pending', 'REJECTED' => 'rejected', 'PAUSED' => 'unavailable'] as $remote => $state) {
    resetMarketing(); $rawTemplates = [templateMarketing($remote)];
    $result = serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]);
    checkMarketing(!$result['created'] && !postsMarketing() && $result['marketing_setup']['state'] === $state, 'existing exact hashed template never duplicated ' . $remote);
    $status = serviceMarketing()->status();
    checkMarketing(count($status['marketing_templates']) === ($remote === 'APPROVED' ? 1 : 0), 'campaign selection remains approved-only ' . $remote);
}
foreach (['APPROVED', 'PENDING', 'REJECTED'] as $remote) {
    resetMarketing(); $rawTemplates = [templateMarketing($remote, $body, 'existing_offer')];
    $result = serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]);
    checkMarketing(!$result['created'] && !postsMarketing() && $result['marketing_setup']['name'] === 'existing_offer', 'same exact body under existing name reused without duplicate ' . $remote);
}
foreach (['body', 'category', 'header', 'buttons', 'missing', 'duplicate'] as $collision) {
    resetMarketing(); $rawTemplates = [templateMarketing('PENDING')];
    if ($collision === 'body') $rawTemplates[0]['components'][0]['text'] .= ' مختلف';
    if ($collision === 'category') $rawTemplates[0]['category'] = 'UTILITY';
    if ($collision === 'header') array_unshift($rawTemplates[0]['components'], ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'عنوان']);
    if ($collision === 'buttons') $rawTemplates[0]['components'][] = ['type' => 'BUTTONS', 'buttons' => []];
    if ($collision === 'missing') unset($rawTemplates[0]['components']);
    if ($collision === 'duplicate') $rawTemplates[] = templateMarketing('REJECTED');
    rejectMarketing(fn() => serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]), 409, 'MARKETING_TEMPLATE_CONFLICT');
    checkMarketing(!postsMarketing(), 'same hash name collision cannot create or overwrite ' . $collision);
}
resetMarketing(); $rawTemplates = [templateMarketing('PENDING')]; $rawTemplates[0]['language'] = 'en_US';
checkMarketing(serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body])['created'], 'different language does not impersonate Arabic candidate');
resetMarketing(); $utility = templateMarketing('APPROVED', $body, 'utility_feedback'); $utility['category'] = 'UTILITY';
$media = templateMarketing('APPROVED', $body, 'image_offer'); array_unshift($media['components'], ['type' => 'HEADER', 'format' => 'IMAGE']);
$buttons = templateMarketing('PENDING', $body, 'button_offer'); $buttons['components'][] = ['type' => 'BUTTONS', 'buttons' => []];
$rawTemplates = [$utility, $media, $buttons]; $status = serviceMarketing()->status();
checkMarketing(!$status['marketing_templates'] && $status['marketing_setup']['unsupported_count'] === 2
    && count($status['marketing_setup']['unsupported_templates']) === 2 && $status['marketing_setup']['state'] === 'unavailable'
    && $status['marketing_setup']['reason'] === 'MARKETING_TEMPLATE_FORMAT_UNSUPPORTED' && $status['marketing_setup']['can_prepare'], 'unsupported marketing formats distinguished from no marketing templates; utility template is never promoted');
checkMarketing(!isset($status['marketing_setup']['unsupported_templates'][0]['body'], $status['marketing_setup']['unsupported_templates'][0]['components']), 'unsupported diagnostics expose identifiers and lifecycle only');
resetMarketing(); $managedUtility = templateMarketing(); $managedUtility['category'] = 'UTILITY'; $rawTemplates = [$managedUtility];
checkMarketing(serviceMarketing()->status()['marketing_setup']['state'] === 'conflict', 'managed name reclassified as utility reported separately');
resetMarketing();
for ($i = 0; $i < 60; $i++) $rawTemplates[] = templateMarketing('PENDING', 'عرض ' . $i, 'offer_' . $i);
$rawTemplates[] = templateMarketing('APPROVED', 'عرض معتمد', 'approved_after_list_cap');
$status = serviceMarketing()->status();
checkMarketing(count($status['marketing_setup']['matches']) === 50 && $status['marketing_setup']['supported_count'] === 61
    && $status['marketing_setup']['state'] === 'approved' && count($status['marketing_templates']) === 1, 'aggregate approval considers all metadata beyond bounded lifecycle preview list');

foreach ([null, [], '', '   ', '{{1}}', '{{customer}}', '{عرض}', "نص\x00", "نص\t", "نص\x7F", "نص\u{0085}", "\xC3\x28", str_repeat('س', 1025)] as $invalid) {
    resetMarketing(); rejectMarketing(fn() => serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $invalid]), 422, 'INVALID_MARKETING_TEMPLATE_BODY');
    checkMarketing(!$calls, 'invalid UTF8, controls, braces, empty/oversized body rejected before provider');
}
resetMarketing(); checkMarketing(serviceMarketing()->prepareMarketingTemplate($actor, ['body' => str_repeat('س', 1024)])['created'], 'body maximum counts UTF8 characters rather than bytes');
foreach (['lookup-failure' => [503, 'TEMPLATE_LOOKUP_UNAVAILABLE'], 'lookup-permission' => [403, 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED'],
    'lookup-pagination' => [422, 'TEMPLATE_PAGINATION_LIMIT']] as $failure => $expected) {
    resetMarketing(); $mode = $failure; $status = serviceMarketing()->status();
    checkMarketing(!$status['marketing_setup']['can_prepare'] && $status['marketing_setup']['state'] === 'unavailable'
        && $status['marketing_setup']['reason'] === $expected[1], 'failed metadata never pretends no marketing templates exist ' . $failure);
    rejectMarketing(fn() => serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]), $expected[0], $expected[1]);
    checkMarketing(!postsMarketing(), 'failed fresh lookup cannot submit promotion');
}
foreach (['create-rejected' => [422, 'MARKETING_TEMPLATE_PROVIDER_REJECTED'], 'create-permission' => [403, 'TEMPLATE_MANAGEMENT_PERMISSION_REQUIRED'],
    'create-malformed' => [503, 'MARKETING_TEMPLATE_CREATE_UNCONFIRMED'], 'create-server' => [503, 'MARKETING_TEMPLATE_CREATE_UNCONFIRMED']] as $failure => $expected) {
    resetMarketing(); $mode = $failure;
    rejectMarketing(fn() => serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]), $expected[0], $expected[1]);
    checkMarketing(count(postsMarketing()) === 1 && !DB::table('crm_whatsapp_settings')->value('enabled'), 'safe provider error without changing sender ' . $failure);
}
resetMarketing(); $mode = 'create-timeout'; $service = serviceMarketing();
rejectMarketing(fn() => $service->prepareMarketingTemplate($actor, ['body' => $body]), 503, 'MARKETING_TEMPLATE_CREATE_UNCONFIRMED');
$mode = 'ok'; $result = $service->prepareMarketingTemplate($actor, ['body' => $body]);
checkMarketing(!$result['created'] && count(postsMarketing()) === 1 && $result['marketing_setup']['state'] === 'pending', 'explicit retry after ambiguous acceptance fetches/reuses pending template');
resetMarketing(); $mode = 'create-category'; $result = serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]);
checkMarketing($result['created'] && $result['marketing_setup']['state'] === 'conflict' && !serviceMarketing()->status()['marketing_templates'], 'provider recategorization never silently makes utility template eligible for marketing');
resetMarketing(); rejectMarketing(fn() => serviceMarketing()->prepareMarketingTemplate((object) ['id' => 2], ['body' => $body]), 403, '');
checkMarketing(!$calls, 'central authorization required before provider access');
resetMarketing(); $container->make('config')->set('desktop_dashboard.local', true);
rejectMarketing(fn() => serviceMarketing()->prepareMarketingTemplate($actor, ['body' => $body]), 422, 'LOCAL_DEVICE_DISABLED');
checkMarketing(!$calls, 'local offline installation cannot prepare shared marketing templates');

// Even after a prepare receipt, every new campaign performs its own approved category lookup.
resetMarketing(); $rawTemplates = [templateMarketing('PENDING')];
serviceMarketing()->configure($actor, ['enabled' => true, 'feedback_enabled' => false]);
$stamp = '2026-10-18 00:00:00';
DB::table('crm_customers')->insert(['phone' => '+201111111111', 'phone_key' => '201111111111', 'marketing_consent' => true,
    'marketing_consented_at' => $stamp, 'first_seen_at' => $stamp, 'last_seen_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
rejectMarketing(fn() => queueMarketing('11223344-5566-4778-899a-123456789011'), 422, 'APPROVED_MARKETING_TEMPLATE_REQUIRED');
checkMarketing(DB::table('crm_whatsapp_campaigns')->count() === 0 && DB::table('crm_whatsapp_deliveries')->count() === 0, 'pending template cannot queue a campaign');
$rawTemplates[0]['status'] = 'APPROVED'; $result = queueMarketing('11223344-5566-4778-899a-123456789012');
checkMarketing($result['recipient_count'] === 1 && DB::table('crm_whatsapp_campaigns')->count() === 1
    && DB::table('crm_whatsapp_deliveries')->count() === 1 && !postsMarketing(), 'fresh approved static marketing template permits consented campaign snapshot without customer send');
$rawTemplates[0]['status'] = 'PENDING';
rejectMarketing(fn() => queueMarketing('11223344-5566-4778-899a-123456789013'), 422, 'APPROVED_MARKETING_TEMPLATE_REQUIRED');
checkMarketing(DB::table('crm_whatsapp_campaigns')->count() === 1 && !postsMarketing(), 'later approval regression blocks new campaign despite prior successful approval');
echo "CRM_MARKETING_TEMPLATE_SETUP_OK {$checks}\n";
