<?php
// Real isolated SQLite, fake Meta template management only. Never boots production or sends messages.
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

$checks = 0; $calls = []; $rawTemplates = []; $mode = 'ok';
$body = "رأيك يهمنا في المنتج والخدمة المقدمة، يُرجى تقديم ملاحظات حضرتك في هذه الرسالة لتحسين جودة المنتج والخدمة.\nطلب رقم {{1}} – فرع {{2}}";
$preparedBody = $body . '.';
$configuration = ['enabled' => false, 'feedback_enabled' => false, 'feedback_template_name' => '', 'feedback_language' => 'ar',
    'feedback_body' => $body, 'feedback_parameters' => ['source_id', 'branch'], 'access_token' => 'fixture-only', 'sender_enabled' => true];
$actor = (object) ['id' => 1];
$authorize = function ($actor) { if (($actor->id ?? 0) !== 1) throw new HttpException(403); return $actor; };
$clock = fn() => CarbonImmutable::parse('2026-10-18 21:00:00', 'UTC');
$transport = function ($method, $url, $payload, $token) use (&$calls, &$rawTemplates, &$mode) {
    $calls[] = [$method, $url, $payload];
    checkSetup($token === 'fixture-only' && $url === 'https://graph.facebook.com/v25.0/468336579702269/message_templates', 'existing fixed WABA/token only');
    if ($method === 'GET') {
        if ($mode === 'lookup-permission') return ['status' => 403, 'body' => '{"error":{"code":200,"message":"do-not-expose"}}'];
        if ($mode === 'lookup-failure') return ['status' => 503, 'body' => '{}'];
        if ($mode === 'lookup-pagination') return ['status' => 200, 'body' => '{"data":[],"paging":{"next":"https://untrusted.invalid","cursors":{"after":"same"}}}'];
        return ['status' => 200, 'body' => json_encode(['data' => $rawTemplates], JSON_UNESCAPED_UNICODE)];
    }
    checkSetup($method === 'POST' && !isset($payload['to']) && !isset($payload['messaging_product']), 'preparation never calls customer message API');
    checkSetup(DB::table('crm_whatsapp_deliveries')->count() === 0, 'preparation never queues deliveries');
    if ($mode === 'create-rejected') return ['status' => 400, 'body' => '{"error":{"code":100,"message":"do-not-expose"}}'];
    if ($mode === 'create-permission') return ['status' => 400, 'body' => '{"error":{"code":10,"message":"do-not-expose"}}'];
    if ($mode === 'create-malformed') return ['status' => 200, 'body' => '{}'];
    $rawTemplates[] = $payload + ['status' => 'PENDING'];
    if ($mode === 'create-timeout') throw new RuntimeException('fixture interrupted after server accepted');
    return ['status' => 200, 'body' => '{"id":"1234567890123","status":"PENDING","category":"UTILITY"}'];
};
function checkSetup($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejectSetup(callable $operation, int $status, string $code): void {
    try { $operation(); } catch (HttpException $e) { checkSetup($e->getStatusCode() === $status && $e->getMessage() === $code, $code); return; }
    throw new RuntimeException('Expected ' . $code);
}
function serviceSetup(array $overrides = []): CustomerMessagingService {
    global $transport, $authorize, $clock, $configuration;
    return new CustomerMessagingService($transport, $authorize, $clock, array_merge($configuration, $overrides));
}
function templateSetup(string $status = 'APPROVED', ?string $text = null, string $name = 'fasakhansta_order_feedback_v1'): array {
    global $preparedBody;
    return ['name' => $name, 'language' => 'ar', 'category' => 'UTILITY', 'status' => $status,
        'components' => [['type' => 'BODY', 'text' => $text ?? $preparedBody]]];
}
function resetSetup(): void {
    global $calls, $rawTemplates, $mode, $container;
    $calls = []; $rawTemplates = []; $mode = 'ok';
    $container->make('config')->set('desktop_dashboard.local', false);
    DB::table('crm_whatsapp_settings')->where('id', 1)->update(['enabled' => false, 'feedback_enabled' => false, 'feedback_template_name' => '', 'feedback_language' => 'ar']);
}
function postsSetup(): array { global $calls; return array_values(array_filter($calls, fn($call) => $call[0] === 'POST')); }

resetSetup();
$service = serviceSetup(); $status = $service->status();
checkSetup($status['feedback_setup']['state'] === 'missing' && $status['feedback_setup']['can_prepare'] && !$status['feedback_ready']
    && !$status['enabled'] && !$status['feedback_enabled'] && !$status['feedback_templates'], 'empty approved list explains missing template without activation');
checkSetup($status['feedback_body'] === $preparedBody, 'new template preview has fixed terminal punctuation');
$service->status(); checkSetup(count($calls) === 1, 'empty template discovery caches within one service instance');
$result = $service->prepareFeedbackTemplate($actor); $post = postsSetup()[0];
checkSetup($result['success'] && $result['created'] && $result['feedback_setup']['state'] === 'pending' && !$result['feedback_setup']['can_prepare'], 'explicit prepare returns pending review');
checkSetup($post[2] === ['name' => 'fasakhansta_order_feedback_v1', 'language' => 'ar', 'category' => 'UTILITY',
    'components' => [['type' => 'BODY', 'text' => $preparedBody, 'example' => ['body_text' => [['12345', 'شبرا الخيمة']]]]]], 'fixed creation payload and deterministic example parameters');
checkSetup(!DB::table('crm_whatsapp_settings')->value('enabled') && !DB::table('crm_whatsapp_settings')->value('feedback_enabled')
    && DB::table('crm_whatsapp_campaigns')->count() === 0 && DB::table('crm_whatsapp_deliveries')->count() === 0, 'template creation leaves sender, feedback, campaigns and outbox disabled');
$result = $service->prepareFeedbackTemplate($actor);
checkSetup(!$result['created'] && count(postsSetup()) === 1 && $result['feedback_setup']['state'] === 'pending', 'same service refreshes Meta before repeated prepare and avoids duplicate');
$status = serviceSetup()->status();
checkSetup(!$status['feedback_templates'] && $status['feedback_setup']['matches'][0]['status'] === 'PENDING', 'pending lifecycle visible in diagnostics but absent from selector');
rejectSetup(fn() => serviceSetup()->configure($actor, ['enabled' => true, 'feedback_enabled' => true,
    'feedback_template_name' => 'fasakhansta_order_feedback_v1', 'feedback_language' => 'ar']), 422, 'APPROVED_FEEDBACK_TEMPLATE_REQUIRED');

foreach (['APPROVED' => 'approved', 'PENDING' => 'pending', 'REJECTED' => 'rejected'] as $remote => $state) {
    resetSetup(); $rawTemplates = [templateSetup($remote)]; $status = serviceSetup()->status();
    checkSetup($status['feedback_setup']['state'] === $state && !$status['feedback_setup']['can_prepare']
        && count($status['feedback_templates']) === ($remote === 'APPROVED' ? 1 : 0), 'existing exact fixed template ' . $state);
    $result = serviceSetup()->prepareFeedbackTemplate($actor);
    checkSetup(!$result['created'] && !postsSetup(), 'existing fixed lifecycle prevents duplicate ' . $state);
}
foreach (['APPROVED', 'PENDING'] as $remote) {
    resetSetup(); $rawTemplates = [templateSetup($remote, $body, 'legacy_feedback')];
    $result = serviceSetup()->prepareFeedbackTemplate($actor);
    checkSetup(!$result['created'] && !postsSetup() && $result['feedback_setup']['matches'][0]['name'] === 'legacy_feedback', 'reuse compatible legacy name ' . $remote);
}
foreach ([$body, $preparedBody] as $text) {
    resetSetup(); $rawTemplates = [templateSetup('APPROVED', $text)];
    serviceSetup()->configure($actor, ['enabled' => true, 'feedback_enabled' => true,
        'feedback_template_name' => 'fasakhansta_order_feedback_v1', 'feedback_language' => 'ar']);
    $status = serviceSetup()->status(); checkSetup($status['feedback_ready'] && $status['feedback_body'] === $text, 'configured exact legacy or new approved body remains selectable and shows its real text');
}
foreach (['body', 'category', 'buttons', 'malformed', 'duplicate'] as $conflict) {
    resetSetup(); $rawTemplates = [templateSetup('PENDING')];
    if ($conflict === 'body') $rawTemplates[0]['components'][0]['text'] .= ' ';
    if ($conflict === 'category') $rawTemplates[0]['category'] = 'MARKETING';
    if ($conflict === 'buttons') $rawTemplates[0]['components'][] = ['type' => 'BUTTONS', 'buttons' => []];
    if ($conflict === 'malformed') unset($rawTemplates[0]['components']);
    if ($conflict === 'duplicate') $rawTemplates[] = templateSetup('REJECTED');
    checkSetup(serviceSetup()->status()['feedback_setup']['state'] === 'conflict', 'fixed variant explains conflict ' . $conflict);
    rejectSetup(fn() => serviceSetup()->prepareFeedbackTemplate($actor), 409, 'FEEDBACK_TEMPLATE_CONFLICT');
    checkSetup(!postsSetup(), 'conflict cannot overwrite remote template ' . $conflict);
}
resetSetup(); $rawTemplates = [templateSetup('APPROVED', $body . 'Changed', 'unrelated')];
checkSetup(serviceSetup()->status()['feedback_templates'] === [] && serviceSetup()->status()['feedback_setup']['matches'] === [], 'arbitrary body alteration never broadens exact matching');
resetSetup(); $rawTemplates = [templateSetup('REJECTED', $body, 'old_rejected')];
checkSetup(serviceSetup()->prepareFeedbackTemplate($actor)['created'], 'rejected different-name template does not block fixed-name submission');

foreach (['lookup-failure' => 'TEMPLATE_LOOKUP_UNAVAILABLE', 'lookup-permission' => 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED',
    'lookup-pagination' => 'TEMPLATE_PAGINATION_LIMIT'] as $failure => $code) {
    resetSetup(); $mode = $failure; $status = serviceSetup()->status();
    checkSetup(!$status['available'] && $status['feedback_setup']['state'] === 'unavailable'
        && $status['feedback_setup']['reason'] === $code && !$status['feedback_setup']['can_prepare'], 'failed lookup never masquerades as missing ' . $failure);
    rejectSetup(fn() => serviceSetup()->prepareFeedbackTemplate($actor),
        $code === 'TEMPLATE_LOOKUP_PERMISSION_REQUIRED' ? 403 : ($code === 'TEMPLATE_PAGINATION_LIMIT' ? 422 : 503), $code);
    checkSetup(!postsSetup(), 'failed preflight never submits ' . $failure);
}
foreach (['create-rejected' => [422, 'FEEDBACK_TEMPLATE_PROVIDER_REJECTED'], 'create-permission' => [403, 'TEMPLATE_MANAGEMENT_PERMISSION_REQUIRED'],
    'create-malformed' => [503, 'FEEDBACK_TEMPLATE_CREATE_UNCONFIRMED']] as $failure => $expected) {
    resetSetup(); $mode = $failure;
    rejectSetup(fn() => serviceSetup()->prepareFeedbackTemplate($actor), $expected[0], $expected[1]);
    checkSetup(count(postsSetup()) === 1 && !DB::table('crm_whatsapp_settings')->value('enabled'), 'provider failure sanitized and sender unchanged ' . $failure);
}
resetSetup(); $mode = 'create-timeout'; $service = serviceSetup();
rejectSetup(fn() => $service->prepareFeedbackTemplate($actor), 503, 'FEEDBACK_TEMPLATE_CREATE_UNCONFIRMED');
$mode = 'ok'; $result = $service->prepareFeedbackTemplate($actor);
checkSetup(!$result['created'] && count(postsSetup()) === 1 && $result['feedback_setup']['state'] === 'pending', 'explicit retry after ambiguous create rechecks Meta and reuses its accepted pending template');
resetSetup(); rejectSetup(fn() => serviceSetup()->prepareFeedbackTemplate((object) ['id' => 2]), 403, '');
checkSetup(!$calls, 'central authorization precedes provider access');
resetSetup(); $container->make('config')->set('desktop_dashboard.local', true);
rejectSetup(fn() => serviceSetup()->prepareFeedbackTemplate($actor), 422, 'LOCAL_DEVICE_DISABLED');
checkSetup(!$calls, 'offline device cannot provision shared templates');
resetSetup(); rejectSetup(fn() => serviceSetup(['sender_enabled' => false])->prepareFeedbackTemplate($actor), 422, 'WHATSAPP_CONNECTION_UNAVAILABLE');
checkSetup(!$calls, 'disabled existing WhatsApp connection blocks preparation');
resetSetup(); rejectSetup(fn() => serviceSetup(['feedback_parameters' => ['branch']])->prepareFeedbackTemplate($actor), 422, 'FEEDBACK_TEMPLATE_CONFIGURATION_INVALID');
checkSetup(!$calls, 'unsupported configured order contract fails before provider access');

echo "CRM_FEEDBACK_TEMPLATE_SETUP_OK {$checks}\n";
