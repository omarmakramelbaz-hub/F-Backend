<?php

// Actual Laravel 8 DB/Crypt/HTTP classes; all Graph traffic stays in Http::fake.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$vendor = getenv('WA_INBOX_TEST_VENDOR') ?: '/tmp/wa-workflow-deps/vendor';
if (is_dir($vendor)) $vendor .= '/autoload.php';
if (!is_file($vendor)) { fwrite(STDERR, "DASHBOARD_ACTIONS_VENDOR_REQUIRED\n"); exit(1); }
require $vendor;
if (!class_exists(\Illuminate\Foundation\Application::class) || !class_exists(\GuzzleHttp\HandlerStack::class)) {
    fwrite(STDERR, "DASHBOARD_ACTIONS_LARAVEL_FRAMEWORK_AND_GUZZLE_REQUIRED\n"); exit(1);
}

use App\Services\Dashboard\WhatsAppInboxConsumer;
use App\Services\Dashboard\WhatsAppInboxMedia;
use App\Services\Dashboard\WhatsAppInboxMessage;
use App\Services\Dashboard\WhatsAppInboxReadState;
use App\Services\Dashboard\WhatsAppReplyService;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

$container = new Container(); Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setEventDispatcher(new Dispatcher($container)); $capsule->setAsGlobal(); $capsule->bootEloquent();
$key = random_bytes(32);
$container->instance('config', new Repository([
    'app' => ['key' => 'base64:' . base64_encode($key), 'cipher' => 'AES-256-CBC'],
    'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite',
        'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
    'desktop_dashboard' => ['local' => false],
]));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); unset($key);
$container->instance(HttpFactory::class, new HttpFactory());
Facade::setFacadeApplication($container);
$source = dirname(__DIR__);
foreach (['Support/WhatsAppInboxProtocol.php', 'Services/Dashboard/WhatsAppInboxQuarantinedEvent.php',
    'Services/Dashboard/WhatsAppInboxConsumer.php', 'Services/Dashboard/WhatsAppInboxAccess.php',
    'Services/Dashboard/WhatsAppVoiceMedia.php',
    'Services/Dashboard/WhatsAppInboxReadState.php',
    'Services/Dashboard/WhatsAppInboxMessage.php', 'Services/Dashboard/WhatsAppInboxMedia.php',
    'Services/Dashboard/WhatsAppReplyService.php'] as $relative) require $source . '/app/' . $relative;
foreach (['2026_10_10_000001_create_whatsapp_inbox_tables.php',
    '2026_10_10_000003_create_whatsapp_reply_requests.php',
    '2026_10_10_000004_create_whatsapp_inbox_reads.php',
    '2026_10_12_000005_create_whatsapp_inbox_hidden_messages.php'] as $relative) require $source . '/database/migrations/' . $relative;
Schema::create('whatsapp_webhook_events', static function (Blueprint $table) {
    $table->bigIncrements('id'); $table->string('payload_hash', 64)->unique(); $table->longText('payload');
    $table->timestamp('received_at'); $table->timestamp('processed_at')->nullable();
});
(new CreateWhatsAppInboxTables())->up(); (new CreateWhatsAppReplyRequests())->up();
(new CreateWhatsAppInboxReads())->up();
(new CreateWhatsAppInboxHiddenMessages())->up();
Schema::create('users', static function (Blueprint $table) {
    $table->integer('id')->primary(); $table->string('account_type'); $table->integer('owner_resturant_id')->nullable();
    $table->text('protected_data')->nullable();
});
foreach (['orders', 'takeaway_orders', 'whatsapp_order_drafts'] as $table) Schema::create($table, static function (Blueprint $table) {
    $table->integer('id')->primary(); $table->text('protected_data');
});

$checks = 0; $sequence = 0; $httpCalls = []; $authCalls = 0; $authHook = null; $queryHook = null;
DB::listen(static function ($query) use (&$queryHook) { if ($queryHook !== null) $queryHook($query); });
$clockNow = CarbonImmutable::createFromTimestampUTC(1691586800);
$configuration = ['enabled' => true, 'access_token' => 'fixture-only-token-no-external-use'];
$authorize = static function ($actor) use (&$authCalls, &$authHook) {
    $authCalls++;
    if ($authHook !== null) $authHook($authCalls);
    $fresh = DB::table('users')->where('id', $actor->id ?? 0)->first();
    if (!$fresh || $fresh->account_type !== 'admin' || !empty($fresh->owner_resturant_id)) throw new HttpException(403);
    return $fresh;
};
$clock = static function () use (&$clockNow) { return $clockNow; };
$actor = (object) ['id' => 1]; $otherActor = (object) ['id' => 2];

function actionCheck(bool $condition, string $label): void
{
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($label);
}
function actionRefuses(callable $call, int $status, string $label): void
{
    try { $call(); } catch (HttpException $error) {
        actionCheck($error->getStatusCode() === $status, $label . ' HTTP status'); return;
    }
    throw new RuntimeException($label . ' unexpectedly accepted');
}
function actionUuid(): string
{
    global $sequence; $sequence++;
    return sprintf('cc001122-3344-4556-8778-%012d', $sequence);
}
function actionMessage(string $phone = '201111111111', string $user = 'US.actions1', string $type = 'text', ?array $body = null,
    string $direction = 'inbound', ?string $nativeOverride = null): array
{
    global $sequence; $sequence++;
    $native = $nativeOverride ?? ('wamid.actionsfixture' . $sequence);
    $message = ['id' => $native, 'timestamp' => '1691583200', 'type' => $type,
        $type => $body ?? ['body' => 'Protected source text ' . $sequence]];
    if ($direction === 'inbound') $message += ['from' => $phone, 'from_user_id' => $user];
    else $message += ['from' => '201285545554', 'to' => $phone, 'to_user_id' => $user];
    $raw = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269', 'changes' => [[
        'field' => $direction === 'inbound' ? 'messages' : 'smb_message_echoes', 'value' => ['messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
            $direction === 'inbound' ? 'messages' : 'message_echoes' => [$message]],
    ]]]]];
    $plain = json_encode($raw, JSON_THROW_ON_ERROR);
    DB::table('whatsapp_webhook_events')->insert(['payload_hash' => hash('sha256', $plain),
        'payload' => Crypt::encryptString($plain), 'received_at' => '2023-08-09 12:00:00']);
    $metrics = (new WhatsAppInboxConsumer())->consume(100);
    actionCheck($metrics['messages_inserted'] === 1 && $metrics['errors'] === 0, 'real protocol/consumer projects fixture');
    $row = DB::table('whatsapp_inbox_messages')->where('message_key', hash('sha256',
        'whatsapp-message-v1:468336579702269:515388018324075:' . $native))->first();
    actionCheck($row !== null, 'native ID maps to projected fixture');
    return ['conversation' => (int) $row->conversation_id, 'message' => (int) $row->id,
        'native' => $native, 'text' => $body['body'] ?? ($message['text']['body'] ?? null), 'phone' => $phone];
}
function actionReset(): array
{
    global $httpCalls, $authCalls, $authHook, $queryHook, $clockNow, $container;
    $httpCalls = []; $authCalls = 0; $authHook = null; $queryHook = null;
    $clockNow = CarbonImmutable::createFromTimestampUTC(1691586800);
    foreach (['whatsapp_reply_reaction_targets', 'whatsapp_inbox_hidden_messages', 'whatsapp_inbox_reads', 'whatsapp_reply_projection_keys', 'whatsapp_reply_requests', 'whatsapp_inbox_ingestion_failures',
        'whatsapp_inbox_messages', 'whatsapp_inbox_conversations', 'whatsapp_webhook_events', 'users',
        'orders', 'takeaway_orders', 'whatsapp_order_drafts'] as $table) DB::table($table)->delete();
    DB::table('users')->insert([
        ['id' => 1, 'account_type' => 'admin', 'owner_resturant_id' => null, 'protected_data' => null],
        ['id' => 2, 'account_type' => 'admin', 'owner_resturant_id' => null, 'protected_data' => null],
        ['id' => 601, 'account_type' => 'customer', 'owner_resturant_id' => null, 'protected_data' => 'Customer fixture untouched'],
    ]);
    foreach (['orders', 'takeaway_orders', 'whatsapp_order_drafts'] as $table)
        DB::table($table)->insert(['id' => 1, 'protected_data' => 'Order fixture untouched']);
    $container->instance(HttpFactory::class, new HttpFactory()); Http::clearResolvedInstance(HttpFactory::class);
    $fixture = actionMessage();
    DB::table('whatsapp_inbox_reads')->insert(['actor_id' => 1, 'conversation_id' => $fixture['conversation'],
        'seen_message_id' => 0, 'created_at' => '2023-08-09 12:00:00', 'updated_at' => '2023-08-09 12:00:00']);
    return $fixture;
}
function actionProtectedSnapshot(bool $includeReplies = false): array
{
    $snapshot = [];
    foreach (['whatsapp_webhook_events', 'whatsapp_inbox_messages', 'whatsapp_inbox_conversations',
        'whatsapp_inbox_reads', 'users', 'orders', 'takeaway_orders', 'whatsapp_order_drafts'] as $table)
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    if ($includeReplies) $snapshot['whatsapp_reply_requests'] = DB::table('whatsapp_reply_requests')->orderBy('id')->get()->toJson();
    return $snapshot;
}
function actionFake(callable $response): void
{
    global $httpCalls, $container;
    $container->instance(HttpFactory::class, new HttpFactory()); Http::clearResolvedInstance(HttpFactory::class);
    Http::fake(static function ($request) use (&$httpCalls, $response) {
        $httpCalls[] = ['url' => $request->url(), 'method' => $request->method(), 'data' => $request->data(),
            'authorization' => $request->header('Authorization')];
        return $response($request);
    });
}
$graphTransport = static function ($endpoint, $payload, $token, $multipart) {
    actionCheck(DB::connection()->transactionLevel() === 0, 'Graph action occurs outside transaction');
    actionCheck(!$multipart, 'dashboard action uses no unreviewed upload');
    actionCheck(DB::table('whatsapp_reply_requests')->orderByDesc('id')->value('state') === 'UNKNOWN',
        'durable uncertainty precedes the customer API');
    $response = Http::withoutRedirecting()->withToken($token)->post($endpoint, $payload);
    return ['status' => $response->status(), 'body' => $response->body()];
};
$mediaTransport = static function ($endpoint, $token, $maxBytes) {
    $response = Http::withoutRedirecting()->withToken($token)->get($endpoint);
    return ['status' => $response->status(), 'body' => $response->body()];
};

function actionAcceptFake(): void
{
    actionFake(static function ($request) {
        actionCheck($request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/515388018324075/messages',
            'customer-facing endpoint is the fixed scoped phone');
        $payload = $request->data();
        return Http::response(['messaging_product' => 'whatsapp',
            'contacts' => [['input' => $payload['to'], 'wa_id' => $payload['to']]],
            'messages' => [['id' => 'wamid.actionsaccepted' . actionUuid()]]], 200);
    });
}
function actionTextInput(array $fixture, string $text = 'Operator fixture reply'): array
{
    return ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'text' => $text];
}
$messages = new WhatsAppInboxMessage($authorize);
$container->instance(WhatsAppInboxMessage::class, $messages);
$service = new WhatsAppReplyService($graphTransport, $authorize, $clock, $configuration, null, $messages);
$readState = new WhatsAppInboxReadState($authorize);

$fixture = actionReset();
$resolved = $messages->resolve($actor, $fixture['conversation'], $fixture['message']);
actionCheck($resolved['native_id'] === $fixture['native'] && $resolved['peer_phone'] === $fixture['phone'],
    'resolver derives native ID and peer from the authenticated encrypted projection');
$other = actionMessage('201222222222', 'US.actions2');
actionRefuses(fn () => $messages->resolve($actor, $other['conversation'], $fixture['message']), 404,
    'message cannot be borrowed from another conversation');
DB::table('whatsapp_inbox_conversations')->where('id', $other['conversation'])->update(['waba_id' => '111111']);
actionRefuses(fn () => $messages->resolve($actor, $other['conversation'], $other['message']), 404,
    'different WABA is inaccessible');
actionCheck(!$httpCalls, 'resolver authorization has no external calls');
foreach (['user_only', 'phone_contact_bridge', 'user_contact_bridge'] as $identityMode) {
    actionReset(); $sequence++; $native = 'wamid.identityfixture' . $sequence;
    $rawMessage = ['id' => $native, 'timestamp' => '1691583200', 'type' => 'text', 'text' => ['body' => 'Identity fixture']];
    if ($identityMode !== 'phone_contact_bridge') $rawMessage['from_user_id'] = 'US.identityfixture';
    if ($identityMode === 'phone_contact_bridge') $rawMessage['from'] = '201333333333';
    $rawValue = ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '515388018324075',
        'display_phone_number' => '201285545554'], 'messages' => [$rawMessage]];
    if ($identityMode !== 'user_only') $rawValue['contacts'] = [['wa_id' => '201333333333', 'user_id' => 'US.identityfixture']];
    $raw = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269',
        'changes' => [['field' => 'messages', 'value' => $rawValue]]]]];
    $plain = json_encode($raw, JSON_THROW_ON_ERROR);
    DB::table('whatsapp_webhook_events')->insert(['payload_hash' => hash('sha256', $plain),
        'payload' => Crypt::encryptString($plain), 'received_at' => '2023-08-09 12:00:00']);
    $metrics = (new WhatsAppInboxConsumer())->consume(100);
    actionCheck($metrics['messages_inserted'] === 1 && $metrics['errors'] === 0, 'real protocol projects the supported identity shape');
    $row = DB::table('whatsapp_inbox_messages')->where('message_key', hash('sha256',
        'whatsapp-message-v1:468336579702269:515388018324075:' . $native))->first();
    $resolved = $messages->resolve($actor, (int) $row->conversation_id, (int) $row->id);
    actionCheck($resolved['native_id'] === $native
        && $resolved['peer_phone'] === ($identityMode === 'user_only' ? null : '201333333333'),
        'resolver accepts actual USER-only and documented contact-bound identity projections');
    $protected = actionProtectedSnapshot();
    $messages->hide($actor, (int) $row->conversation_id, (int) $row->id);
    actionRefuses(fn () => $messages->resolve($actor, (int) $row->conversation_id, (int) $row->id), 404,
        'supported identity shape is locally hideable');
    actionCheck(actionProtectedSnapshot() === $protected && !$httpCalls, 'identity-specific hiding changes only dashboard metadata');
}
foreach (['message_key', 'native_id', 'peer_identity', 'direction', 'source', 'timestamp'] as $tamper) {
    $fixture = actionReset();
    $row = DB::table('whatsapp_inbox_messages')->where('id', $fixture['message'])->first();
    if ($tamper === 'message_key') DB::table('whatsapp_inbox_messages')->where('id', $row->id)->update(['message_key' => str_repeat('0', 64)]);
    else {
        $dto = json_decode(Crypt::decryptString($row->content), true, 64, JSON_THROW_ON_ERROR);
        if ($tamper === 'native_id') $dto['content']['message']['id'] = 'wamid.client-injection';
        if ($tamper === 'peer_identity') $dto['peer_identity'] = 'user:US.wrong';
        if ($tamper === 'direction') $dto['direction'] = 'outbound';
        if ($tamper === 'source') $dto['source'] = 'smb_message_echoes';
        if ($tamper === 'timestamp') $dto['content']['message']['timestamp'] = '1691583300';
        DB::table('whatsapp_inbox_messages')->where('id', $row->id)->update([
            'content' => Crypt::encryptString(json_encode($dto, JSON_THROW_ON_ERROR))]);
    }
    actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $fixture['message']), 422,
        'native projection tamper ' . $tamper . ' fails closed');
    actionCheck(!$httpCalls && !DB::table('whatsapp_reply_requests')->exists(), 'projection tamper produces no send claim');
}

$fixture = actionReset(); actionAcceptFake();
$protected = actionProtectedSnapshot();
$input = actionTextInput($fixture); $input['reply_to_message_id'] = $fixture['message'];
$result = $service->send($actor, $fixture['conversation'], $input);
actionCheck($result['state'] === 'ACCEPTED' && count($httpCalls) === 1, 'quoted text is accepted once');
$payload = $httpCalls[0]['data'];
actionCheck($payload['context'] === ['message_id' => $fixture['native']]
    && $payload['to'] === $fixture['phone'] && $payload['text']['body'] === $input['text'],
    'quoted reply uses the server-resolved native ID and recipient');
actionCheck($service->send($actor, $fixture['conversation'], $input)['replayed'] && count($httpCalls) === 1,
    'quoted double click never sends twice');
$auditRow = DB::table('whatsapp_reply_requests')->first();
actionCheck(strpos($auditRow->audit, $fixture['native']) === false && strpos($auditRow->audit, $input['text']) === false,
    'quote audit keeps private message data encrypted');
actionCheck(actionProtectedSnapshot() === $protected, 'quoted send does not mutate raw history customers or orders');
$bad = actionTextInput($fixture); $bad['reply_to_message_id'] = 'wamid.client-chosen';
actionRefuses(fn () => $service->send($actor, $fixture['conversation'], $bad), 422,
    'browser cannot inject a native quoted ID');

$fixture = actionReset(); $outbound = actionMessage('201111111111', 'US.actions1', 'text', null, 'outbound');
actionAcceptFake();
$input = actionTextInput($fixture); $input['reply_to_message_id'] = $outbound['message'];
actionCheck($service->send($actor, $fixture['conversation'], $input)['state'] === 'ACCEPTED',
    'quoted outbound echo can use the same scoped customer window');
actionCheck($httpCalls[0]['data']['context'] === ['message_id' => $outbound['native']],
    'outbound quotation also uses the authenticated native ID');

$fixture = actionReset(); actionAcceptFake();
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍'];
$result = $service->reaction($actor, $fixture['conversation'], $fixture['message'], $input);
actionCheck($result['state'] === 'ACCEPTED' && count($httpCalls) === 1, 'reaction uses durable accepted send');
actionCheck($httpCalls[0]['data']['type'] === 'reaction'
    && $httpCalls[0]['data']['reaction'] === ['message_id' => $fixture['native'], 'emoji' => '👍'],
    'reaction native target is server resolved');
actionCheck($service->reaction($actor, $fixture['conversation'], $fixture['message'], $input)['replayed']
    && count($httpCalls) === 1, 'reaction replay never sends twice');
foreach (['not-an-emoji', str_repeat('👍', 40), "👍\0"] as $emoji) {
    $bad = $input; $bad['client_request_id'] = actionUuid(); $bad['emoji'] = $emoji;
    actionRefuses(fn () => $service->reaction($actor, $fixture['conversation'], $fixture['message'], $bad), 422,
        'invalid reaction payload is rejected');
}

$fixture = actionReset(); $target = actionMessage('201222222222', 'US.actions2'); actionAcceptFake();
$protected = actionProtectedSnapshot();
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $target['message'],
    'target_conversation_id' => $target['conversation']];
$result = $service->forward($actor, $fixture['conversation'], $fixture['message'], $input);
actionCheck($result['state'] === 'ACCEPTED' && count($httpCalls) === 1, 'forward sends to an existing scoped conversation');
actionCheck($httpCalls[0]['data']['to'] === $target['phone'] && $httpCalls[0]['data']['type'] === 'text'
    && $httpCalls[0]['data']['text']['body'] === $fixture['text'], 'forward copies server text to server-resolved target');
actionCheck($service->forward($actor, $fixture['conversation'], $fixture['message'], $input)['replayed']
    && count($httpCalls) === 1, 'forward replay cannot resend');
actionCheck(actionProtectedSnapshot() === $protected, 'forward does not mutate source history or customer/order records');
$bad = $input; $bad['client_request_id'] = actionUuid(); $bad['to'] = '201999999999';
actionRefuses(fn () => $service->forward($actor, $fixture['conversation'], $fixture['message'], $bad), 422,
    'forward rejects arbitrary recipients');
$bad = $input; $bad['client_request_id'] = actionUuid(); $bad['target_conversation_id'] = 999999;
actionRefuses(fn () => $service->forward($actor, $fixture['conversation'], $fixture['message'], $bad), 404,
    'forward cannot create a recipient from an unknown thread');

$fixture = actionReset(); $protected = actionProtectedSnapshot();
$result = $messages->hide($actor, $fixture['conversation'], $fixture['message']);
actionCheck($result['success'] && $result['dashboard_only'], 'hide is explicitly dashboard only');
actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $fixture['message']), 404,
    'hidden item is inaccessible to its hiding actor');
actionCheck($messages->resolve($otherActor, $fixture['conversation'], $fixture['message'])['native_id'] === $fixture['native'],
    'another actor retains the same encrypted native history');
$messages->hide($actor, $fixture['conversation'], $fixture['message']);
actionCheck(DB::table('whatsapp_inbox_hidden_messages')->count() === 1, 'repeated local hide is idempotent');
actionCheck(actionProtectedSnapshot() === $protected && !$httpCalls, 'local hide preserves raw history customer/order records without Graph call');

$fixture = actionReset();
DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $fixture['message']), 403,
    'source resolver reloads revoked persisted central role');
actionRefuses(fn () => $messages->hide($actor, $fixture['conversation'], $fixture['message']), 403,
    'hide reloads revoked persisted role');
DB::table('users')->where('id', 1)->update(['account_type' => 'admin', 'owner_resturant_id' => 301]);
actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $fixture['message']), 403,
    'branch admin cannot inspect central inbox');
actionCheck(!$httpCalls && !DB::table('whatsapp_inbox_hidden_messages')->exists(), 'authorization rejection makes no action');

$fixture = actionReset(); actionAcceptFake();
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍'];
$clockNow = $clockNow->addDays(2);
$result = $service->reaction($actor, $fixture['conversation'], $fixture['message'], $input);
actionCheck($result['state'] === 'FAILED' && $result['reason'] === 'WINDOW_CLOSED' && !$httpCalls,
    'reaction cannot open a closed customer window');

$fixture = actionReset(); $target = actionMessage('201222222222', 'US.actions2'); actionAcceptFake();
$latest = actionMessage('201222222222', 'US.actions2');
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $target['message'],
    'target_conversation_id' => $target['conversation']];
$result = $service->forward($actor, $fixture['conversation'], $fixture['message'], $input);
actionCheck($result['state'] === 'FAILED' && $result['reason'] === 'STALE_INBOUND' && !$httpCalls,
    'forward requires the current target inbound message');

$fixture = actionReset();
actionFake(static function () { throw new RuntimeException('Fixture ambiguous transport; never real network'); });
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍'];
actionCheck($service->reaction($actor, $fixture['conversation'], $fixture['message'], $input)['state'] === 'UNKNOWN',
    'reaction transport uncertainty remains durable');
actionCheck($service->reaction($actor, $fixture['conversation'], $fixture['message'], $input)['replayed']
    && count($httpCalls) === 1, 'uncertain reaction is never retried');
$next = $input; $next['client_request_id'] = actionUuid();
$result = $service->reaction($otherActor, $fixture['conversation'], $fixture['message'], $next);
actionCheck($result['reason'] === 'UNKNOWN_PENDING' && count($httpCalls) === 1,
    'uncertain action blocks new UUIDs from other administrators');

$fixture = actionReset(); actionAcceptFake();
$input = actionTextInput($fixture); $input['reply_to_message_id'] = $fixture['message'];
$queryHook = static function ($query) use (&$queryHook, $fixture) {
    if (preg_match('/\A\s*insert\b/i', $query->sql) && str_contains($query->sql, 'whatsapp_reply_requests')) {
        $queryHook = null;
        $row = DB::table('whatsapp_inbox_messages')->where('id', $fixture['message'])->first();
        $dto = json_decode(Crypt::decryptString($row->content), true, 64, JSON_THROW_ON_ERROR);
        $dto['text'] = 'Changed after the durable claim'; $dto['content']['message']['text']['body'] = $dto['text'];
        DB::table('whatsapp_inbox_messages')->where('id', $row->id)->update([
            'content' => Crypt::encryptString(json_encode($dto, JSON_THROW_ON_ERROR))]);
    }
};
$result = $service->send($actor, $fixture['conversation'], $input);
actionCheck($queryHook === null && $result['state'] === 'FAILED' && !$httpCalls,
    'native quote fingerprint is revalidated after its claim and before Graph');

$fixture = actionReset(); actionAcceptFake();
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍'];
$queryHook = static function ($query) use (&$queryHook) {
    if (preg_match('/\A\s*update\b/i', $query->sql) && str_contains($query->sql, 'whatsapp_reply_requests')
        && in_array('UNKNOWN', $query->bindings, true)) {
        $queryHook = null;
        DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
    }
};
$result = $service->reaction($actor, $fixture['conversation'], $fixture['message'], $input);
actionCheck($queryHook === null && $result['state'] === 'FAILED' && $result['reason'] === 'ACCESS_REVOKED' && !$httpCalls,
    'persisted authorization is reloaded at the final customer API boundary');

function actionImageFixture(string $bytes): array
{
    global $sequence; actionReset(); $sequence++;
    $mediaId = (string) (100000000 + $sequence);
    $fixture = actionMessage('201111111111', 'US.actions1', 'image',
        ['id' => $mediaId, 'mime_type' => 'image/png', 'sha256' => hash('sha256', $bytes)]);
    return $fixture + ['media_id' => $mediaId, 'digest' => hash('sha256', $bytes)];
}
function actionImageFake(array $fixture, string $bytes, array $metadataOverrides = [], int $metadataStatus = 200,
    int $downloadStatus = 200, ?string $downloadBytes = null, ?callable $onDownload = null): void
{
    $metadata = ['id' => $fixture['media_id'], 'mime_type' => 'image/png', 'file_size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes), 'url' => 'https://lookaside.fbsbx.com/whatsapp_business/fixture/' . $fixture['media_id']];
    $metadata = array_replace($metadata, $metadataOverrides);
    actionFake(static function ($request) use ($metadata, $metadataStatus, $downloadStatus, $downloadBytes, $onDownload, $bytes, $fixture) {
        actionCheck($request->method() === 'GET', 'protected image transport only retrieves bytes');
        if ($request->url() === 'https://graph.facebook.com/v25.0/' . $fixture['media_id'] . '?phone_number_id=515388018324075')
            return Http::response($metadata, $metadataStatus, ['Location' => 'http://127.0.0.1/private']);
        actionCheck($request->url() === $metadata['url'], 'only validated metadata supplies a CDN URL');
        if ($onDownload !== null) $onDownload();
        return Http::response($downloadBytes ?? $bytes, $downloadStatus,
            ['Content-Type' => 'image/png', 'Location' => 'http://127.0.0.1/private']);
    });
}
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5FoAAAAASUVORK5CYII=', true);
actionCheck(is_string($png) && getimagesizefromstring($png)['mime'] === 'image/png', 'image fixture uses actual decodable PNG');
$cacheDirectory = sys_get_temp_dir() . '/wa-dashboard-actions-' . bin2hex(random_bytes(12));
if (!mkdir($cacheDirectory, 0700)) throw new RuntimeException('Private fixture cache unavailable');
register_shutdown_function(static function () use ($cacheDirectory) {
    foreach (glob($cacheDirectory . '/*') ?: [] as $path) if (is_file($path) || is_link($path)) unlink($path);
    if (is_file($cacheDirectory . '/.cache.lock')) unlink($cacheDirectory . '/.cache.lock');
    rmdir($cacheDirectory);
});
$media = new WhatsAppInboxMedia($mediaTransport, $configuration, $cacheDirectory, $messages);
$fixture = actionReset(); $sequence++; $onlyUserNative = 'wamid.useronlyimage' . $sequence; $onlyUserMedia = (string) (200000000 + $sequence);
$raw = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269', 'changes' => [[
    'field' => 'messages', 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '515388018324075',
        'display_phone_number' => '201285545554'], 'messages' => [['id' => $onlyUserNative, 'timestamp' => '1691583200',
        'from_user_id' => 'US.useronlyimage', 'type' => 'image', 'image' => ['id' => $onlyUserMedia, 'mime_type' => 'image/png',
        'sha256' => hash('sha256', $png)]]]],
]]]]];
$plain = json_encode($raw, JSON_THROW_ON_ERROR);
DB::table('whatsapp_webhook_events')->insert(['payload_hash' => hash('sha256', $plain), 'payload' => Crypt::encryptString($plain),
    'received_at' => '2023-08-09 12:00:00']);
$metrics = (new WhatsAppInboxConsumer())->consume(100);
actionCheck($metrics['messages_inserted'] === 1 && $metrics['errors'] === 0, 'actual protocol projects USER-only image without inventing a phone');
$row = DB::table('whatsapp_inbox_messages')->where('message_key', hash('sha256',
    'whatsapp-message-v1:468336579702269:515388018324075:' . $onlyUserNative))->first();
$onlyUserFixture = ['conversation' => (int) $row->conversation_id, 'message' => (int) $row->id, 'media_id' => $onlyUserMedia];
actionImageFake($onlyUserFixture, $png); $protected = actionProtectedSnapshot();
actionCheck($messages->resolve($actor, $onlyUserFixture['conversation'], $onlyUserFixture['message'])['peer_phone'] === null
    && $media->image($actor, $onlyUserFixture['conversation'], $onlyUserFixture['message']) === ['bytes' => $png, 'mime' => 'image/png'],
    'USER-only image can be viewed privately using native scope without a fabricated recipient');
actionCheck(actionProtectedSnapshot() === $protected, 'USER-only media leaves encrypted raw history and customer/order records unchanged');
$fixture = actionImageFixture($png); actionImageFake($fixture, $png); $protected = actionProtectedSnapshot();
$image = $media->image($actor, $fixture['conversation'], $fixture['message']);
actionCheck($image === ['bytes' => $png, 'mime' => 'image/png'] && count($httpCalls) === 2,
    'protected image returns verified bytes and MIME without Graph URL or token');
actionCheck(actionProtectedSnapshot() === $protected, 'image retrieval does not change private history customers orders or read markers');
$cacheFiles = glob($cacheDirectory . '/*.bin');
actionCheck(count($cacheFiles) === 2 && (fileperms($cacheFiles[0]) & 0777) === 0600 && file_get_contents($cacheFiles[0]) === $png,
    'cached image is a private byte file');
actionCheck($media->image($actor, $fixture['conversation'], $fixture['message']) === $image && count($httpCalls) === 2,
    'verified cached image avoids a second Graph request');
$messages->hide($actor, $fixture['conversation'], $fixture['message']);
actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 404,
    'cache cannot reveal an image hidden by its current actor');
actionCheck($media->image($otherActor, $fixture['conversation'], $fixture['message']) === $image && count($httpCalls) === 2,
    'another authorized actor retains scoped access to the same cached image');

foreach (['http://lookaside.fbsbx.com/private', 'https://127.0.0.1/private', 'https://localhost/private',
    'https://lookaside.fbsbx.com.attacker.example/private', 'https://attacker@lookaside.fbsbx.com/private',
    'https://attacker:password@lookaside.fbsbx.com/private', 'https://lookaside.fbsbx.com:444/private',
    'https://lookaside.fbsbx.com/private#secret'] as $url) {
    $fixture = actionImageFixture($png); actionImageFake($fixture, $png, ['url' => $url]);
    actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 502,
        'unsafe image metadata URL is rejected');
    actionCheck(count($httpCalls) === 1, 'unsafe metadata never reaches a CDN or private address');
}
foreach ([['id' => '999999999'], ['mime_type' => 'image/jpeg'], ['sha256' => str_repeat('0', 64)],
    ['file_size' => WhatsAppInboxMedia::MAX_BYTES + 1], ['file_size' => 0], ['file_size' => (string) strlen($png)]] as $metadata) {
    $fixture = actionImageFixture($png); actionImageFake($fixture, $png, $metadata);
    actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 502,
        'image metadata ID MIME digest and size require exact validation');
    actionCheck(count($httpCalls) === 1, 'invalid media metadata stops before byte download');
}
$fixture = actionImageFixture($png); actionImageFake($fixture, $png, [], 302);
actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 502,
    'Graph metadata redirect is refused');
actionCheck(count($httpCalls) === 1, 'Graph metadata redirect is never followed');
foreach ([['status' => 302, 'bytes' => $png], ['status' => 200, 'bytes' => 'not-image-data'],
    ['status' => 200, 'bytes' => str_repeat('x', WhatsAppInboxMedia::MAX_BYTES + 1)]] as $download) {
    $fixture = actionImageFixture($png); actionImageFake($fixture, $png, [], 200, $download['status'], $download['bytes']);
    actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 502,
        'redirect invalid content or oversized bytes cannot become protected image data');
    actionCheck(count($httpCalls) === 2, 'download failure has no redirect or retry');
}
$largeDimensionPng = substr_replace($png, pack('N', 12001), 16, 4);
actionCheck(getimagesizefromstring($largeDimensionPng)[0] === 12001, 'dimension fixture reaches the real image metadata parser');
$fixture = actionImageFixture($largeDimensionPng); actionImageFake($fixture, $largeDimensionPng);
actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 502,
    'authenticated bytes exceeding the image dimension bound are refused');

$fixture = actionImageFixture($png);
actionImageFake($fixture, $png, [], 200, 200, null, static function () use ($messages, $actor, $fixture) {
    $messages->hide($actor, $fixture['conversation'], $fixture['message']);
});
actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 404,
    'concurrent hide during a fresh download prevents returning bytes');
$fixture = actionImageFixture($png);
actionImageFake($fixture, $png, [], 200, 200, null, static function () {
    DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
});
actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 403,
    'concurrent role revocation during download prevents returning bytes');
$fixture = actionImageFixture($png); actionImageFake($fixture, $png);
$media->image($actor, $fixture['conversation'], $fixture['message']);
$authCalls = 0;
$authHook = static function ($count) use (&$authHook, $messages, $actor, $fixture) {
    if ($count === 3) {
        $authHook = null;
        $messages->hide($actor, $fixture['conversation'], $fixture['message']);
    }
};
actionRefuses(fn () => $media->image($actor, $fixture['conversation'], $fixture['message']), 404,
    'cached bytes recheck visibility after a concurrent hide');
actionCheck($authHook === null && count($httpCalls) === 2, 'cache race is tested without another network retrieval');

$addressGuard = new ReflectionMethod(WhatsAppInboxMedia::class, 'publicAddress');
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0',
    '255.255.255.255', '100.64.0.1', '224.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1',
    '198.18.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', 'not-an-IP'] as $address)
    actionCheck(!$addressGuard->invoke($media, $address), 'production DNS address guard excludes local shared reserved multicast and unsupported address families');
foreach (['8.8.8.8', '1.1.1.1', '157.240.1.35'] as $address)
    actionCheck($addressGuard->invoke($media, $address), 'production DNS address guard accepts public IPv4 only');

$fixture = actionImageFixture($png); $target = actionMessage('201222222222', 'US.actions2'); actionAcceptFake();
$result = $service->forward($actor, $fixture['conversation'], $fixture['message'], ['client_request_id' => actionUuid(),
    'expected_inbound_id' => $target['message'], 'target_conversation_id' => $target['conversation']]);
actionCheck($result['state'] === 'ACCEPTED' && end($httpCalls)['data']['image'] === ['id' => $fixture['media_id']]
    && end($httpCalls)['data']['to'] === $target['phone'], 'forwarded image uses its server media ID and an existing server recipient');
$imageReply = DB::table('whatsapp_reply_requests')->where('id', $result['reply_id'])->first();
$imageNative = json_decode(Crypt::decryptString($imageReply->response_details), true, 16, JSON_THROW_ON_ERROR)['message_id'];
$projection = DB::table(WhatsAppInboxMessage::INDEX)->where('reply_id', $imageReply->id)->first();
actionCheck($projection !== null && $projection->valid && $projection->kind === 'image', 'forwarded image receives canonical projection metadata in its acceptance completion');
actionImageFake($fixture, $png);
actionCheck($media->replyImage($actor, $target['conversation'], (int) $imageReply->id) === ['bytes' => $png, 'mime' => 'image/png'],
    'accepted forwarded image can use the same protected authenticated media path');
$imageEcho = actionMessage('201222222222', 'US.actions2', 'image',
    ['id' => $fixture['media_id'], 'mime_type' => 'image/png', 'sha256' => $fixture['digest']], 'outbound', $imageNative);
$container->instance(\Illuminate\Contracts\Routing\UrlGenerator::class, new \Illuminate\Routing\UrlGenerator(
    new \Illuminate\Routing\RouteCollection(), \Illuminate\Http\Request::create('http://localhost')));
$state = $service->state($actor, $target['conversation']);
$recentImage = array_values(array_filter($state['recent_replies'], static fn ($reply) => $reply['reply_id'] === (int) $imageReply->id))[0];
actionCheck($recentImage['projected_message_id'] === $imageEcho['message'] && $recentImage['projection_id'] === (int) $projection->id,
    'forwarded image audit deduplicates its native echo and exposes only safe projection metadata');
actionCheck($messages->selection($actor, $target['conversation'])['total_count'] === 2,
    'target inbound and forwarded image count as two logical items after projection');

$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture);
DB::table(WhatsAppInboxMessage::INDEX)->delete();
$legacyApi = (array) DB::table('whatsapp_reply_requests')->where('id', $api['id'])->first(); unset($legacyApi['id']);
$legacyIds = [];
foreach (['malformed', 'oversized', 'contradictory_native', 'duplicate_native'] as $kind) {
    $copy = $legacyApi; $copy['request_hash'] = hash('sha256', actionUuid()); $copy['payload_hash'] = hash('sha256', actionUuid());
    if ($kind === 'malformed') $copy['response_details'] = 'Malformed encrypted fixture receipt';
    if ($kind === 'oversized') $copy['response_details'] = str_repeat('x', 65537);
    if ($kind === 'contradictory_native') {
        $native = 'wamid.contradictory' . actionUuid();
        $copy['response_details'] = Crypt::encryptString(json_encode(['message_id' => $native], JSON_THROW_ON_ERROR));
        // The unchanged original hash belongs to a different native ID.
    }
    $legacyIds[] = DB::table('whatsapp_reply_requests')->insertGetId($copy);
}
$protected = actionProtectedSnapshot(true);
$firstBatch = $messages->backfillProjectionIndex(1);
actionCheck($firstBatch === ['processed' => 1, 'valid' => 1, 'invalid' => 0, 'remaining' => 4, 'complete' => false],
    'bounded index backfill processes valid legacy receipts without scanning the whole table');
$lastBatch = $messages->backfillProjectionIndex(500);
actionCheck($lastBatch === ['processed' => 4, 'valid' => 0, 'invalid' => 4, 'remaining' => 0, 'complete' => true],
    'malformed oversized contradictory and duplicate-native receipts get non-actionable derived metadata');
actionCheck(actionProtectedSnapshot(true) === $protected, 'index backfill changes only the new metadata table and preserves every legacy receipt');
foreach ($legacyIds as $id) {
    $index = DB::table(WhatsAppInboxMessage::INDEX)->where('reply_id', $id)->first();
    actionCheck(!$index->valid && $index->message_key === null, 'unavailable legacy receipt never receives an invented native key');
    actionRefuses(fn () => $messages->resolveReply($actor, $fixture['conversation'], $id), 422,
        'unavailable legacy receipt cannot authorize quote reaction forward or media actions');
}
actionCheck($messages->selection($actor, $fixture['conversation'])['total_count'] === 6,
    'unavailable original API cards remain countable without fabricated projection identity');

function actionAcceptedReply(WhatsAppReplyService $service, $actor, array $fixture): array
{
    actionAcceptFake(); $input = actionTextInput($fixture);
    $result = $service->send($actor, $fixture['conversation'], $input);
    actionCheck($result['state'] === 'ACCEPTED', 'fixture has a real accepted durable API reply');
    $row = DB::table('whatsapp_reply_requests')->where('id', $result['reply_id'])->first();
    $native = json_decode(Crypt::decryptString($row->response_details), true, 16, JSON_THROW_ON_ERROR)['message_id'];
    return ['id' => (int) $row->id, 'native' => $native, 'text' => $input['text']];
}

$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture);
$resolved = $messages->resolveReply($actor, $fixture['conversation'], $api['id']);
actionCheck($resolved['native_id'] === $api['native'] && $resolved['text'] === $api['text'],
    'accepted API card resolves its encrypted native ID and content');
$input = actionTextInput($fixture); $input['reply_to_reply_id'] = $api['id'];
actionCheck($service->send($actor, $fixture['conversation'], $input)['state'] === 'ACCEPTED'
    && end($httpCalls)['data']['context'] === ['message_id' => $api['native']], 'accepted API card can be quoted using its server native ID');
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '❤️'];
actionCheck($service->reactionReply($actor, $fixture['conversation'], $api['id'], $input)['state'] === 'ACCEPTED'
    && end($httpCalls)['data']['reaction'] === ['message_id' => $api['native'], 'emoji' => '❤️'], 'accepted API card reaction uses its server native ID');
$target = actionMessage('201222222222', 'US.actions2');
$input = ['client_request_id' => actionUuid(), 'expected_inbound_id' => $target['message'], 'target_conversation_id' => $target['conversation']];
actionCheck($service->forwardReply($actor, $fixture['conversation'], $api['id'], $input)['state'] === 'ACCEPTED'
    && end($httpCalls)['data']['to'] === $target['phone'] && end($httpCalls)['data']['text']['body'] === $api['text'],
    'accepted API card forwards only its stored content to an existing target');

$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture);
$selection = $messages->selection($actor, $fixture['conversation']);
actionCheck($selection['max_message_id'] === $fixture['message'] && $selection['max_reply_id'] === $api['id']
    && $selection['total_count'] === 2 && $selection['select_all_available']
    && !isset($selection['message_ids'], $selection['reply_ids']), 'selection captures bounded native and accepted-index cutoffs without enumerating history');
$token = json_decode(Crypt::decryptString($selection['snapshot_token']), true, 64, JSON_THROW_ON_ERROR);
actionCheck(!str_contains($selection['snapshot_token'], $api['native']) && !str_contains($selection['snapshot_token'], $fixture['native']),
    'selection token keeps private selection contents encrypted');
$copied = (array) DB::table('whatsapp_reply_requests')->where('id', $api['id'])->first(); unset($copied['id']);
$copied['request_hash'] = hash('sha256', actionUuid()); $copied['payload_hash'] = hash('sha256', actionUuid());
$copied['state'] = 'CLAIMED'; $copied['response_details'] = null; $copied['remote_message_key'] = null;
$copied['send_started_at'] = null; $copied['completed_at'] = null;
$lateId = DB::table('whatsapp_reply_requests')->insertGetId($copied);
$laterAccepted = (array) DB::table('whatsapp_reply_requests')->where('id', $api['id'])->first(); unset($laterAccepted['id']);
$laterNative = 'wamid.preselectedlater' . actionUuid();
$laterAccepted['request_hash'] = hash('sha256', actionUuid()); $laterAccepted['payload_hash'] = hash('sha256', actionUuid());
$laterAccepted['response_details'] = Crypt::encryptString(json_encode(['message_id' => $laterNative], JSON_THROW_ON_ERROR));
$laterAccepted['remote_message_key'] = hash('sha256', 'whatsapp-reply-message-v1:468336579702269:515388018324075:' . $laterNative);
$laterAcceptedId = DB::table('whatsapp_reply_requests')->insertGetId($laterAccepted);
actionCheck($messages->indexReply(DB::table('whatsapp_reply_requests')->where('id', $laterAcceptedId)->first()),
    'already accepted later fixture has valid canonical projection metadata');
$selection = $messages->selection($actor, $fixture['conversation']);
actionCheck($lateId < $selection['max_reply_id'] && $selection['max_reply_id'] === $laterAcceptedId
    && $selection['reply_count'] === 2 && !DB::table(WhatsAppInboxMessage::INDEX)->where('reply_id', $lateId)->exists(),
    'claimed request older than the accepted reply cutoff is absent from the acceptance-index snapshot');
$token = json_decode(Crypt::decryptString($selection['snapshot_token']), true, 64, JSON_THROW_ON_ERROR);
$future = actionMessage();
$lateNative = 'wamid.lateaccepted' . actionUuid();
DB::table('whatsapp_reply_requests')->where('id', $lateId)->update(['state' => 'ACCEPTED',
    'response_details' => Crypt::encryptString(json_encode(['message_id' => $lateNative], JSON_THROW_ON_ERROR)),
    'remote_message_key' => hash('sha256', 'whatsapp-reply-message-v1:468336579702269:515388018324075:' . $lateNative),
    'completed_at' => '2023-08-09 11:53:20']);
actionCheck($messages->indexReply(DB::table('whatsapp_reply_requests')->where('id', $lateId)->first())
    && DB::table(WhatsAppInboxMessage::INDEX)->where('reply_id', $lateId)->value('id') > $selection['max_projection_id'],
    'late acceptance receives a newer monotonic projection marker even for an older request ID');
actionRefuses(fn () => $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'],
    'select_all' => false, 'message_ids' => [], 'reply_ids' => [$lateId]]), 422,
    'explicit late-accepted ID is outside the stable accepted snapshot despite its lower request ID');
$protected = actionProtectedSnapshot(true); $callsBefore = count($httpCalls);
$result = $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'], 'select_all' => true]);
actionCheck($result['dashboard_only'] && $result['hidden_count'] === 3, 'select all hides exactly the captured visible membership');
actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $fixture['message']), 404, 'captured native item is hidden');
actionRefuses(fn () => $messages->resolveReply($actor, $fixture['conversation'], $api['id']), 404, 'captured accepted API item is hidden');
actionRefuses(fn () => $messages->resolveReply($actor, $fixture['conversation'], $laterAcceptedId), 404, 'later already-accepted captured API item is hidden');
actionCheck($messages->resolve($actor, $fixture['conversation'], $future['message'])['native_id'] === $future['native'],
    'native item arriving after selection remains visible');
actionCheck($messages->resolveReply($actor, $fixture['conversation'], $lateId)['native_id'] === $lateNative,
    'older claimed request accepted after selection remains visible');
actionCheck($messages->resolve($otherActor, $fixture['conversation'], $fixture['message'])['native_id'] === $fixture['native']
    && $messages->resolveReply($otherActor, $fixture['conversation'], $api['id'])['native_id'] === $api['native'],
    'selection hide belongs only to its persisted actor');
actionCheck(actionProtectedSnapshot(true) === $protected && count($httpCalls) === $callsBefore,
    'bulk hide preserves raw history API audit read markers customers orders and creates no Graph calls');
actionCheck($messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'], 'select_all' => true])['hidden_count'] === 0,
    'same selection hide is idempotent');
$lateEcho = actionMessage('201111111111', 'US.actions1', 'text', ['body' => $api['text']], 'outbound', $lateNative);
actionCheck($messages->resolve($actor, $fixture['conversation'], $lateEcho['message'])['native_id'] === $lateNative,
    'projection of a late-accepted older request remains visible after bulk hide');
$capturedEcho = actionMessage('201111111111', 'US.actions1', 'text', ['body' => $api['text']], 'outbound', $api['native']);
actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $capturedEcho['message']), 404,
    'later projection of an already captured API message inherits its logical hide');
foreach ([$otherActor, $actor] as $wrongActor) {
    $badToken = $selection['snapshot_token'];
    if ($wrongActor === $actor) $badToken = substr($badToken, 0, -2) . '??';
    actionRefuses(fn () => $messages->hideSelected($wrongActor, $fixture['conversation'], ['snapshot_token' => $badToken, 'select_all' => true]),
        422, 'selection token cannot move between actors or accept corrupted ciphertext');
}
$expired = $token; $expired['expires'] = time() - 1;
actionRefuses(fn () => $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => Crypt::encryptString(json_encode($expired)), 'select_all' => true]),
    422, 'expired selection snapshot cannot hide history');

$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture);
$selection = $messages->selection($actor, $fixture['conversation']);
$result = $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'],
    'select_all' => false, 'message_ids' => [], 'reply_ids' => [$api['id']]]);
actionCheck($result['reply_count'] === 1 && $result['message_count'] === 0, 'explicit API hide changes only selected local metadata');
$echo = actionMessage('201111111111', 'US.actions1', 'text', ['body' => $api['text']], 'outbound', $api['native']);
actionRefuses(fn () => $messages->resolve($actor, $fixture['conversation'], $echo['message']), 404,
    'later projection of the same accepted native ID inherits the local hide');
actionCheck($messages->resolve($otherActor, $fixture['conversation'], $echo['message'])['native_id'] === $api['native'],
    'another actor retains the projected alias');

$fixture = actionReset();
$row = DB::table('whatsapp_inbox_messages')->where('id', $fixture['message'])->first();
$dto = json_decode(Crypt::decryptString($row->content), true, 64, JSON_THROW_ON_ERROR);
for ($index = 0; $index < 1000; $index++) {
    $native = 'wamid.boundedselection' . $index . actionUuid(); $copy = $dto;
    $copy['message_id'] = $native; $copy['content']['message']['id'] = $native;
    DB::table('whatsapp_inbox_messages')->insert(['conversation_id' => $row->conversation_id,
        'message_key' => hash('sha256', 'whatsapp-message-v1:468336579702269:515388018324075:' . $native),
        'type' => $row->type, 'direction' => $row->direction, 'source' => $row->source,
        'content' => Crypt::encryptString(json_encode($copy, JSON_THROW_ON_ERROR)),
        'sent_at' => $row->sent_at, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
}
$selection = $messages->selection($actor, $fixture['conversation']);
actionCheck($selection['total_count'] === 1001 && $selection['select_all_available'] && !$selection['truncated'],
    'more than 1000 messages remain eligible for a stable constant-size select-all operation');
$latestFixture = $fixture; $latestFixture['message'] = (int) DB::table('whatsapp_inbox_messages')->where('conversation_id', $fixture['conversation'])->max('id');
$shadowApi = actionAcceptedReply($service, $actor, $latestFixture);
$shadowEcho = actionMessage('201111111111', 'US.actions1', 'text', ['body' => $shadowApi['text']], 'outbound', $shadowApi['native']);
DB::connection()->enableQueryLog();
$selection = $messages->selection($actor, $fixture['conversation']);
$selectionQueries = DB::connection()->getQueryLog(); DB::connection()->disableQueryLog(); DB::connection()->flushQueryLog();
actionCheck($selection['total_count'] === 1002 && $selection['message_count'] === 1002 && $selection['reply_count'] === 0
    && strlen($selection['snapshot_token']) < 4096, 'long-thread API and echo aliases are counted once without a large selection token');
foreach ($selectionQueries as $query) actionCheck(!str_contains(strtolower($query['query']), 'content')
    && !str_contains(strtolower($query['query']), 'audit'), 'whole-history selection does not load encrypted message or audit bodies');
$explicitIds = DB::table('whatsapp_inbox_messages')->where('conversation_id', $fixture['conversation'])->orderBy('id')->limit(101)->pluck('id')->map(fn ($id) => (int) $id)->all();
actionRefuses(fn () => $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'],
    'select_all' => false, 'message_ids' => $explicitIds, 'reply_ids' => []]), 422,
    'explicit selection cannot exceed 100 items');
actionRefuses(fn () => $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'],
    'select_all' => false, 'message_ids' => [999999999], 'reply_ids' => []]), 422,
    'explicit ID outside the encrypted snapshot cannot be hidden');
actionCheck(!DB::table('whatsapp_inbox_hidden_messages')->exists(), 'refused explicit large or forged selections create no local metadata');
$protected = actionProtectedSnapshot(true); $callsBefore = count($httpCalls);
$result = $messages->hideSelected($actor, $fixture['conversation'], ['snapshot_token' => $selection['snapshot_token'], 'select_all' => true]);
actionCheck($result['hidden_count'] === 1002 && DB::table(WhatsAppInboxMessage::HIDDEN)->count() === 1,
    'a long-thread logical hide stores one cutoff marker rather than deleting or scanning raw history');
actionCheck(actionProtectedSnapshot(true) === $protected && count($httpCalls) === $callsBefore,
    'long-thread hide preserves every original raw customer order read and API audit record without Graph calls');
actionCheck($messages->selection($actor, $fixture['conversation'])['total_count'] === 0
    && $messages->selection($otherActor, $fixture['conversation'])['total_count'] === 1002,
    'whole-history visibility remains isolated by actor with exact deduplicated counts');
$unread = $readState->unread($actor);
actionCheck($unread['total_unread'] === 1001 && $unread['total_unread_messages'] === 1001
    && $unread['total_unread_conversations'] === 0, 'whole-history hide changes the visible thread badge while preserving legacy unread-message counts');
$future = actionMessage();
actionCheck($messages->resolve($actor, $fixture['conversation'], $future['message'])['native_id'] === $future['native']
    && $messages->selection($actor, $fixture['conversation'])['total_count'] === 1, 'new long-thread arrival is visible beyond the captured cutoff');
$unread = $readState->unread($actor);
actionCheck($unread['total_unread'] === 1002 && $unread['total_unread_conversations'] === 1,
    'fresh incoming message immediately restores its hidden conversation badge');

$fixture = actionReset(); $second = actionMessage(); $otherThread = actionMessage('201222222222', 'US.actions2');
$unread = $readState->unread($actor);
actionCheck($unread['total_unread'] === 3 && $unread['total_unread_conversations'] === 2,
    'unread conversation badge counts distinct threads rather than individual messages');
$protected = actionProtectedSnapshot(); $messages->hide($actor, $fixture['conversation'], $fixture['message']);
$unread = $readState->unread($actor);
actionCheck($unread['total_unread'] === 3 && $unread['total_unread_conversations'] === 2,
    'one hidden item does not remove a thread with another visible unread incoming message');
$messages->hide($actor, $second['conversation'], $second['message']); $unread = $readState->unread($actor);
actionCheck($unread['total_unread'] === 3 && $unread['total_unread_messages'] === 3 && $unread['total_unread_conversations'] === 1
    && $readState->unread($otherActor)['total_unread_conversations'] === 2, 'individual hides reduce only their actor visible thread badge');
actionCheck(actionProtectedSnapshot() === $protected, 'badge changes through hiding leave every read marker and raw record intact');
$readState->markRead($otherThread['conversation'], $otherThread['message'], $actor);
$unread = $readState->unread($actor);
actionCheck($unread['total_unread'] === 2 && $unread['total_unread_conversations'] === 0,
    'explicit read marker removes the remaining visible unread thread separately from local hiding');
DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
actionRefuses(fn () => $readState->unread($actor), 403, 'unread badge reloads the persisted actor role');

$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture); $rawBefore = actionProtectedSnapshot();
for ($index = 0; $index < 21; $index++) {
    $reaction = $service->reactionReply($actor, $fixture['conversation'], $api['id'],
        ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍']);
    actionCheck($reaction['state'] === 'ACCEPTED', 'reaction fixture is durably accepted through the real service');
}
$state = $service->state($actor, $fixture['conversation']);
actionCheck(count($state['recent_replies']) === 1 && $state['recent_replies'][0]['reply_id'] === $api['id'],
    'more than 20 reaction receipts cannot evict the displayable accepted API message');
$reload = new WhatsAppReplyService($graphTransport, $authorize, $clock, $configuration, null, $messages);
$history = $reload->history($actor, $fixture['conversation']);
$overlay = $history['replies'][0]['reaction'];
actionCheck(count($history['replies']) === 1 && $overlay['emoji'] === '👍' && $overlay['source'] === 'dashboard_api'
    && $overlay['state'] === 'ACCEPTED' && $overlay['actor_id'] === 1 && $overlay['reply_id'] === $reaction['reply_id'],
    'latest accepted reaction and its provenance survive a service reload');
actionCheck(array_keys($overlay) === ['emoji', 'actor_id', 'accepted_at', 'source', 'state', 'reply_id']
    && !str_contains(json_encode($overlay), $api['native']), 'reaction overlay never exposes a native message ID or receipt body');
actionCheck(actionProtectedSnapshot() === $rawBefore, 'accepted reactions neither create pseudo-messages nor mutate raw history customers orders or read markers');
$reaction = $service->reactionReply($otherActor, $fixture['conversation'], $api['id'],
    ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '❤️']);
$echo = actionMessage('201111111111', 'US.actions1', 'text', ['body' => $api['text']], 'outbound', $api['native']);
DB::connection()->enableQueryLog();
$updates = $messages->updates($actor, $fixture['conversation'], [$echo['message']], [$api['id']]);
$updateQueries = DB::connection()->getQueryLog(); DB::connection()->disableQueryLog(); DB::connection()->flushQueryLog();
actionCheck($updates['messages'][0]['reaction'] === $updates['replies'][0]['reaction']
    && $updates['replies'][0]['reaction']['emoji'] === '❤️' && $updates['replies'][0]['reaction']['actor_id'] === 2
    && $updates['replies'][0]['reaction']['reply_id'] === $reaction['reply_id'],
    'native echo and accepted API card use the same latest business reaction key across authorized actors');
foreach ($updateQueries as $query) actionCheck(!str_contains(strtolower($query['query']), 'audit')
    && !str_contains(strtolower($query['query']), 'response_details') && !str_contains(strtolower($query['query']), 'content'),
    'older-item updates retrieve bounded metadata without loading historical encrypted bodies or reaction audits');
$rawBefore = actionProtectedSnapshot();
$cleared = $service->reaction($actor, $fixture['conversation'], $echo['message'],
    ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '']);
$updates = $messages->updates($otherActor, $fixture['conversation'], [$echo['message']], [$api['id']]);
actionCheck($cleared['state'] === 'ACCEPTED' && $updates['messages'][0]['reaction']['emoji'] === ''
    && $updates['replies'][0]['reaction']['reply_id'] === $cleared['reply_id'],
    'empty emoji is an accepted persistent removal shared by the native and API views');
actionCheck(actionProtectedSnapshot() === $rawBefore, 'reaction removal changes only its accepted API audit and derived reaction metadata');
$messages->hide($actor, $fixture['conversation'], $echo['message']);
$updates = $messages->updates($actor, $fixture['conversation'], [$echo['message']], [$api['id']]);
actionCheck($updates['messages'][0]['deleted'] && $updates['replies'][0]['deleted']
    && $updates['messages'][0]['reaction'] === null && $updates['replies'][0]['reaction'] === null,
    'updates reconcile hidden old native and API aliases without leaking their reaction');
$history = $service->history($actor, $fixture['conversation'], null, [$api['id']]);
actionCheck($history['replies'] === [] && $history['hidden_reply_ids'] === [$api['id']],
    'accepted history reconciles a previously loaded item hidden in another dashboard tab');
$foreign = actionMessage('201222222222', 'US.actions2');
actionRefuses(fn () => $messages->updates($actor, $fixture['conversation'], [$foreign['message']], []), 422,
    'older-item updates cannot borrow a native ID from another conversation');
actionRefuses(fn () => $messages->updates($actor, $fixture['conversation'], array_fill(0, 101, $echo['message']), []), 422,
    'older-item update IDs have a combined 100-item bound');
DB::table('users')->where('id', 1)->update(['owner_resturant_id' => 301]);
actionRefuses(fn () => $messages->updates($actor, $fixture['conversation'], [$echo['message']], []), 403,
    'reaction and visibility updates reload central-role authorization');

$fixture = actionReset(); $apiIds = [];
for ($index = 0; $index < 35; $index++) $apiIds[] = actionAcceptedReply($service, $actor, $fixture)['id'];
// Fixture-only removal models an older API-only thread whose native projection is absent.
DB::table('whatsapp_inbox_messages')->where('conversation_id', $fixture['conversation'])->delete();
actionCheck(DB::table('whatsapp_inbox_messages')->where('conversation_id', $fixture['conversation'])->count() === 0,
    'historical API-only fixture contains no native cards');
$protected = actionProtectedSnapshot(true); $callsBefore = count($httpCalls);
$firstPage = $service->history($actor, $fixture['conversation']);
actionCheck(actionProtectedSnapshot(true) === $protected, 'accepted history only reads original private data and receipt audit rows');
$firstIds = array_column($firstPage['replies'], 'reply_id'); $savedCursor = $firstPage['next_before_reply_id'];
actionCheck($firstIds === array_slice($apiIds, 15) && $firstPage['has_more'] && $savedCursor === $apiIds[15],
    'API-only history exposes exactly its newest 20 messages and a separate older cursor');
$sourceRow = (array) DB::table('whatsapp_reply_requests')->where('id', $apiIds[0])->first(); unset($sourceRow['id']);
$newNative = 'wamid.newhistoricalapi' . actionUuid();
$sourceRow['request_hash'] = hash('sha256', actionUuid()); $sourceRow['payload_hash'] = hash('sha256', actionUuid());
$sourceRow['response_details'] = Crypt::encryptString(json_encode(['message_id' => $newNative], JSON_THROW_ON_ERROR));
$sourceRow['remote_message_key'] = hash('sha256', 'whatsapp-reply-message-v1:468336579702269:515388018324075:' . $newNative);
$newApiId = DB::table('whatsapp_reply_requests')->insertGetId($sourceRow);
actionCheck($messages->indexReply(DB::table('whatsapp_reply_requests')->where('id', $newApiId)->first()),
    'new API-only fixture receives canonical acceptance metadata');
$polled = $service->history($actor, $fixture['conversation']);
actionCheck(count($polled['replies']) === 20 && end($polled['replies'])['reply_id'] === $newApiId,
    'future poll still returns only its newest 20 accepted API items');
$older = $service->history($actor, $fixture['conversation'], $savedCursor, $firstIds);
$olderIds = array_column($older['replies'], 'reply_id');
actionCheck($olderIds === array_slice($apiIds, 0, 15) && !$older['has_more'] && $older['next_before_reply_id'] === null
    && !$older['hidden_reply_ids'], 'saved API cursor retrieves exactly the remaining older messages despite a newer poll');
$combined = array_merge($firstIds, $olderIds); sort($combined);
actionCheck($combined === $apiIds && count(array_unique($combined)) === 35, 'API-only pagination preserves all old items without duplicates or a native cursor');
actionCheck(count($httpCalls) === $callsBefore, 'history and future polling never make customer-facing network calls');
foreach ([0, -1] as $before) actionRefuses(fn () => $service->history($actor, $fixture['conversation'], $before), 422,
    'history before cursor must be a positive reply ID');
foreach ([array_fill(0, 101, $apiIds[0]), [$apiIds[0] . ''], [999999999], [2 => $apiIds[0]]] as $known)
    actionRefuses(fn () => $service->history($actor, $fixture['conversation'], null, $known), 422,
        'history known IDs require a bounded scoped integer list');
DB::table('whatsapp_inbox_conversations')->where('id', $fixture['conversation'])->update(['phone_number_id' => '111111']);
actionRefuses(fn () => $service->history($actor, $fixture['conversation']), 404, 'accepted history cannot reveal a different business phone');

$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture);
$firstReaction = $service->reactionReply($actor, $fixture['conversation'], $api['id'],
    ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍']);
$secondReaction = $service->reactionReply($otherActor, $fixture['conversation'], $api['id'],
    ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '❤️']);
$canonical = DB::table(WhatsAppInboxMessage::INDEX)->where('reply_id', $api['id'])->value('message_key');
actionCheck($messages->reactions($actor, $fixture['conversation'], [$canonical])[$canonical]['reply_id'] === $secondReaction['reply_id'],
    'equal acceptance timestamps use the later receipt ID as a deterministic reaction tie-break');
$laterAcceptedAt = $clockNow->addSeconds(5)->format('Y-m-d H:i:s');
DB::table('whatsapp_reply_requests')->where('id', $firstReaction['reply_id'])->update(['completed_at' => $laterAcceptedAt]);
DB::table(WhatsAppInboxMessage::REACTIONS)->where('reply_id', $firstReaction['reply_id'])->update(['accepted_at' => $laterAcceptedAt]);
actionCheck($messages->reactions($actor, $fixture['conversation'], [$canonical])[$canonical]['reply_id'] === $firstReaction['reply_id'],
    'a lower request ID accepted later wins over a higher earlier-accepted request');

$fixture = actionReset(); actionAcceptFake();
$validReaction = $service->reaction($actor, $fixture['conversation'], $fixture['message'],
    ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍']);
DB::table(WhatsAppInboxMessage::REACTIONS)->delete();
$baseReaction = (array) DB::table('whatsapp_reply_requests')->where('id', $validReaction['reply_id'])->first(); unset($baseReaction['id']);
$badReactionIds = [];
foreach (['malformed_audit', 'oversized_audit', 'wrong_source', 'wrong_receipt', 'invalid_emoji'] as $kind) {
    $copy = $baseReaction; $copy['request_hash'] = hash('sha256', actionUuid()); $copy['payload_hash'] = hash('sha256', actionUuid());
    if ($kind === 'malformed_audit') $copy['audit'] = 'Broken encrypted fixture reaction audit';
    elseif ($kind === 'oversized_audit') $copy['audit'] = str_repeat('x', 1048577);
    elseif ($kind === 'wrong_receipt') $copy['remote_message_key'] = str_repeat('0', 64);
    else {
        $audit = json_decode(Crypt::decryptString($copy['audit']), true, 64, JSON_THROW_ON_ERROR);
        if ($kind === 'wrong_source') $audit['source']['conversation'] = $fixture['conversation'] + 99999;
        if ($kind === 'invalid_emoji') $audit['emoji'] = 'untrusted text';
        $copy['audit'] = Crypt::encryptString(json_encode($audit, JSON_THROW_ON_ERROR));
    }
    $badReactionIds[] = DB::table('whatsapp_reply_requests')->insertGetId($copy);
}
$protected = actionProtectedSnapshot(true); $callsBefore = count($httpCalls);
$firstBatch = $messages->backfillProjectionIndex(1);
actionCheck($firstBatch === ['processed' => 1, 'valid' => 1, 'invalid' => 0, 'remaining' => 5, 'complete' => false],
    'reaction metadata backfill shares the bounded processing budget with display metadata');
$lastBatch = $messages->backfillProjectionIndex(500);
actionCheck($lastBatch === ['processed' => 5, 'valid' => 0, 'invalid' => 5, 'remaining' => 0, 'complete' => true],
    'malformed oversized foreign-source wrong-receipt and invalid-emoji audits become unavailable derived records');
foreach ($badReactionIds as $id) {
    $row = DB::table(WhatsAppInboxMessage::REACTIONS)->where('reply_id', $id)->first();
    actionCheck($row !== null && !$row->valid && $row->message_key === null, 'unproven reaction never receives a canonical target key');
}
actionCheck(actionProtectedSnapshot(true) === $protected && count($httpCalls) === $callsBefore,
    'reaction backfill preserves every original raw and API record and makes no customer-facing calls');
actionCheck($messages->backfillProjectionIndex(500)['processed'] === 0, 'invalid legacy reaction placeholders do not cause endless backfill');

// The new routes use the real legacy IsAdmin/lang/CSRF classes and real controllers.
// A request guard supplies fixture sessions; service access still reloads persisted roles.
$legacyRoot = getenv('WA_ACTIONS_BASELINE_ROOT') ?: $source;
foreach (['app/Http/Controllers/Controller.php', 'app/Http/Middleware/IsAdmin.php',
    'app/Http/Middleware/lang.php', 'app/Http/Middleware/VerifyCsrfToken.php'] as $relative) {
    $path = is_file($source . '/' . $relative) ? $source . '/' . $relative : $legacyRoot . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('DASHBOARD_ACTIONS_LEGACY_HTTP_SOURCE_REQUIRED');
    require_once $path;
}
require $source . '/app/Http/Controllers/Dashboard/WhatsAppInboxMessageController.php';
require $source . '/app/Http/Controllers/Dashboard/WhatsAppReplyController.php';
$previousContainer = $container;
$container = new \Illuminate\Foundation\Application($source);
foreach (['config', 'db', 'db.schema', 'encrypter', HttpFactory::class, WhatsAppInboxMessage::class] as $binding)
    $container->instance($binding, $previousContainer->make($binding));
$container->instance('env', 'production');
$container->instance(WhatsAppReplyService::class, $service); $container->instance(WhatsAppInboxMedia::class, $media);
Facade::setFacadeApplication($container); Facade::clearResolvedInstances();
$repository = $container->make('config');
$repository->set('session', ['lifetime' => 120, 'path' => '/', 'domain' => null, 'secure' => false, 'same_site' => 'lax']);
$repository->set('auth', ['defaults' => ['guard' => 'admin'], 'guards' => ['admin' => ['driver' => 'actions_fixture']]]);
$sessionStore = new \Illuminate\Session\Store('wa_actions', new \Illuminate\Session\ArraySessionHandler(120));
$sessionStore->start(); $sessionStore->put('_token', str_repeat('t', 40));
$container->instance('session', $sessionStore); $container->instance('session.store', $sessionStore);
$sessionActor = new \Illuminate\Auth\GenericUser(['id' => 1, 'account_type' => 'admin']);
$auth = new \Illuminate\Auth\AuthManager($container);
$auth->extend('actions_fixture', static function () use (&$sessionActor) {
    return new \Illuminate\Auth\RequestGuard(static function () use (&$sessionActor) { return $sessionActor; }, new \Illuminate\Http\Request());
});
$container->instance('auth', $auth);
if (!class_exists('Auth', false)) class_alias(\Illuminate\Support\Facades\Auth::class, 'Auth');
if (!class_exists('App', false)) class_alias(\Illuminate\Support\Facades\App::class, 'App');
$events = new Dispatcher($container); $filesystem = new \Illuminate\Filesystem\Filesystem();
$views = new \Illuminate\View\Factory(new \Illuminate\View\Engines\EngineResolver(),
    new \Illuminate\View\FileViewFinder($filesystem, []), $events);
$url = new \Illuminate\Routing\UrlGenerator(new \Illuminate\Routing\RouteCollection(), \Illuminate\Http\Request::create('http://localhost'));
$redirector = new \Illuminate\Routing\Redirector($url);
$container->instance('url', $url); $container->instance('redirect', $redirector);
$container->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new \Illuminate\Routing\ResponseFactory($views, $redirector));
$router = new \Illuminate\Routing\Router($events, $container); $container->instance('router', $router);
$router->middlewareGroup('web', [\App\Http\Middleware\VerifyCsrfToken::class]);
$router->aliasMiddleware('IsAdmin', \App\Http\Middleware\IsAdmin::class);
$router->aliasMiddleware('lang', \App\Http\Middleware\lang::class);
$router->group(['middleware' => 'web'], static function () use ($source) {
    require $source . '/routes/whatsapp_inbox.php'; require $source . '/routes/whatsapp_replies.php';
});
function actionRoute(string $method, string $path, array $parameters = [], ?string $csrfHeader = null, array $files = [])
{
    global $router, $container, $sessionStore;
    $server = $csrfHeader === null ? [] : ['HTTP_X_CSRF_TOKEN' => $csrfHeader];
    $request = \Illuminate\Http\Request::create('http://localhost' . $path, $method, $parameters, [], $files, $server);
    $request->setLaravelSession($sessionStore); $container->instance('request', $request);
    return $router->dispatch($request);
}
$fixture = actionReset();
$deletePath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/messages/' . $fixture['message'] . '/delete';
foreach ([null, 'wrong-token'] as $csrfHeader) {
    try { actionRoute('POST', $deletePath, [], $csrfHeader); throw new RuntimeException('CSRF rejection missing'); }
    catch (\Illuminate\Session\TokenMismatchException $error) { actionCheck(true, 'actual web middleware rejects missing or incorrect CSRF token'); }
    actionCheck(!DB::table('whatsapp_inbox_hidden_messages')->exists() && !$httpCalls, 'CSRF denial precedes local metadata and customer API actions');
}
$response = actionRoute('POST', $deletePath, [], str_repeat('t', 40));
actionCheck($response->getStatusCode() === 200 && json_decode($response->getContent(), true)['dashboard_only'],
    'real delete route accepts the browser CSRF header and delegates a local hide');
actionCheck(str_contains($response->headers->get('Cache-Control'), 'no-store')
    && $response->headers->get('X-Content-Type-Options') === 'nosniff', 'actual action controller emits private response headers');

$fixture = actionImageFixture($png); actionImageFake($fixture, $png);
$mediaPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/messages/' . $fixture['message'] . '/media';
$response = actionRoute('GET', $mediaPath);
actionCheck($response->getStatusCode() === 200 && $response->getContent() === $png
    && $response->headers->get('Content-Type') === 'image/png', 'real image route returns authenticated verified bytes');
actionCheck($response->headers->get('Referrer-Policy') === 'no-referrer'
    && $response->headers->get('X-Content-Type-Options') === 'nosniff'
    && str_contains($response->headers->get('Cache-Control'), 'private')
    && str_contains($response->headers->get('Cache-Control'), 'no-store')
    && $response->headers->get('Content-Security-Policy') === "default-src 'none'; sandbox"
    && $response->headers->get('Cross-Origin-Resource-Policy') === 'same-origin',
    'actual protected image response prohibits caching referrer leakage and active content');
DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
$callsBefore = count($httpCalls); $response = actionRoute('GET', $mediaPath);
actionCheck($response->getStatusCode() === 403 && $response->getContent() === '' && count($httpCalls) === $callsBefore,
    'a logged-in session cannot bypass revoked persisted role at the media controller');
$sessionActor = null; $auth->forgetGuards();
$response = actionRoute('GET', $mediaPath);
actionCheck($response->getStatusCode() === 302 && str_ends_with($response->headers->get('Location'), '/admin/login')
    && count($httpCalls) === $callsBefore, 'actual legacy IsAdmin rejects an unauthenticated route before private media');

$sessionActor = new \Illuminate\Auth\GenericUser(['id' => 1, 'account_type' => 'admin']); $auth->forgetGuards();
$fixture = actionReset(); $api = actionAcceptedReply($service, $actor, $fixture);
$historyPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/replies/history';
$response = actionRoute('GET', $historyPath, ['known_reply_ids' => [(string) $api['id']]]);
$history = json_decode($response->getContent(), true);
actionCheck($response->getStatusCode() === 200 && $history['replies'][0]['reply_id'] === $api['id']
    && str_contains($response->headers->get('Cache-Control'), 'no-store'), 'actual history route parses scoped known IDs and retains private response headers');
foreach ([['before_reply_id' => '0'], ['before_reply_id' => ['1']], ['known_reply_ids' => array_fill(0, 101, '1')],
    ['known_reply_ids' => ['not-an-ID']], ['private_native_id' => $api['native']]] as $query) {
    $response = actionRoute('GET', $historyPath, $query);
    actionCheck($response->getStatusCode() === 404 || $response->getStatusCode() === 422,
        'actual history controller rejects malformed cursor unknown fields and unbounded known IDs');
}
$updatesPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/messages/updates';
$response = actionRoute('GET', $updatesPath, ['message_ids' => [(string) $fixture['message']], 'reply_ids' => [(string) $api['id']]]);
$updates = json_decode($response->getContent(), true);
actionCheck($response->getStatusCode() === 200 && $updates['messages'][0]['id'] === $fixture['message']
    && $updates['replies'][0]['reply_id'] === $api['id'], 'actual older-item updates route parses both native and API ID sets');
$response = actionRoute('GET', $updatesPath, ['message_ids' => array_fill(0, 100, (string) $fixture['message']), 'reply_ids' => [(string) $api['id']]]);
actionCheck($response->getStatusCode() === 422, 'actual updates route enforces its combined ID bound');
$reactionPath = '/admin/whatsapp/conversations/' . $fixture['conversation'] . '/replies/' . $api['id'] . '/reaction';
try { actionRoute('POST', $reactionPath, ['client_request_id' => actionUuid(), 'expected_inbound_id' => $fixture['message'], 'emoji' => '👍']);
    throw new RuntimeException('Reaction CSRF rejection missing'); }
catch (\Illuminate\Session\TokenMismatchException $error) { actionCheck(true, 'actual accepted-API reaction route also requires the browser CSRF token'); }
$callsBefore = count($httpCalls); DB::table('users')->where('id', 1)->update(['account_type' => 'staff']);
$response = actionRoute('GET', $historyPath);
actionCheck($response->getStatusCode() === 403 && count($httpCalls) === $callsBefore,
    'read-only API history reloads persisted central actor authorization');

require __DIR__ . '/whatsapp_dashboard_attachments_test.php';

echo 'WHATSAPP_DASHBOARD_ACTION_CHECKS=' . $checks . "\n";
