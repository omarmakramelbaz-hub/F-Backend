<?php

// Real SQLite transactions + Laravel encryption, fake Graph transport only.
// No server bootstrap, secrets, network requests, or real customer messages.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload)) { fwrite(STDERR, "REPLY_TEST_VENDOR_REQUIRED\n"); exit(1); }
require $autoload;

use App\Services\Dashboard\WhatsAppInboxConsumer;
use App\Services\Dashboard\WhatsAppReplyService;
use App\Services\Dashboard\WhatsAppVoiceMedia;
use Carbon\CarbonImmutable;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

if (!function_exists('config')) {
    function config($key = null, $default = null) { $r = Container::getInstance()->make('config'); return $key === null ? $r : $r->get($key, $default); }
}
if (!function_exists('now')) { function now($zone = null) { return \Carbon\Carbon::now($zone); } }
$container = new Container(); Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setEventDispatcher(new Dispatcher($container)); $capsule->setAsGlobal(); $capsule->bootEloquent();
$key = random_bytes(32);
$container->instance('config', new Repository(['app' => ['key' => 'base64:' . base64_encode($key), 'cipher' => 'AES-256-CBC'],
    'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite',
        'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]]]));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); unset($key);
Facade::setFacadeApplication($container);
require __DIR__ . '/../app/Support/WhatsAppInboxProtocol.php';
require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php';
require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxConsumer.php';
require __DIR__ . '/../app/Services/Dashboard/WhatsAppVoiceMedia.php';
require __DIR__ . '/../app/Services/Dashboard/WhatsAppReplyService.php';
require __DIR__ . '/../database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php';
require __DIR__ . '/../database/migrations/2026_10_10_000003_create_whatsapp_reply_requests.php';
Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
    $table->bigIncrements('id'); $table->string('payload_hash', 64)->unique(); $table->longText('payload');
    $table->timestamp('received_at'); $table->timestamp('processed_at')->nullable();
});
(new CreateWhatsAppInboxTables())->up(); (new CreateWhatsAppReplyRequests())->up();
Schema::create('test_reply_actors', function (Blueprint $table) {
    $table->unsignedBigInteger('id')->primary(); $table->string('account_type'); $table->unsignedBigInteger('owner_resturant_id')->nullable();
});

$count = 0; $sequence = 0; $calls = []; $fakeNow = CarbonImmutable::createFromTimestampUTC(1691586800);
$enabled = ['enabled' => true, 'access_token' => 'fixture-only-token-no-external-use'];
// Observe the actual executed builders in SQLite and also compile their MySQL SQL.
// SQLite cannot reproduce REPEATABLE READ, but this protects the necessary current-read locks.
class ReplyLockGrammar extends \Illuminate\Database\Query\Grammars\SQLiteGrammar
{
    public array $claimReads = [];
    public function compileSelect(\Illuminate\Database\Query\Builder $query)
    {
        if (DB::connection()->transactionLevel() > 0 && in_array($query->from,
            ['whatsapp_reply_requests', 'whatsapp_inbox_messages', 'whatsapp_inbox_conversations'], true)) {
            $mysql = new \Illuminate\Database\Query\Grammars\MySqlGrammar();
            $this->claimReads[] = ['table' => $query->from, 'locked' => $query->lock === true,
                'sql' => $mysql->compileSelect(clone $query)];
        }
        return parent::compileSelect($query);
    }
}
$lockGrammar = new ReplyLockGrammar();
DB::connection()->setQueryGrammar($lockGrammar);
$authCalls = 0; $authHook = null;
$authorize = function ($actor) use (&$authCalls, &$authHook) {
    $authCalls++;
    if ($authHook !== null) $authHook($authCalls);
    $fresh = DB::table('test_reply_actors')->where('id', $actor->id ?? 0)->first();
    if (!$fresh || $fresh->account_type !== 'admin' || !empty($fresh->owner_resturant_id)) throw new HttpException(403);
    return $fresh;
};
$clock = function () use (&$fakeNow) { return $fakeNow; };
function checkReply(bool $ok, string $label): void { global $count; if (!$ok) throw new RuntimeException($label); $count++; }
function refuseReply(callable $operation, int $status, string $label): void
{
    try { $operation(); } catch (HttpException $error) { checkReply($error->getStatusCode() === $status, $label); return; }
    throw new RuntimeException($label);
}
function resetReply(): array
{
    global $calls, $fakeNow, $authCalls, $authHook;
    $calls = []; $authCalls = 0; $authHook = null; $fakeNow = CarbonImmutable::createFromTimestampUTC(1691586800);
    foreach (['whatsapp_reply_requests', 'whatsapp_inbox_ingestion_failures', 'whatsapp_inbox_messages',
        'whatsapp_inbox_conversations', 'whatsapp_webhook_events', 'test_reply_actors'] as $table) DB::table($table)->delete();
    DB::table('test_reply_actors')->insert([['id' => 1, 'account_type' => 'admin', 'owner_resturant_id' => null],
        ['id' => 2, 'account_type' => 'admin', 'owner_resturant_id' => null]]);
    addReplyMessage('inbound', 1691583200);
    $conversation = (int) DB::table('whatsapp_inbox_conversations')->value('id');
    return [$conversation, (int) DB::table('whatsapp_inbox_messages')->value('id')];
}
function addReplyMessage(string $direction, int $time, string $phone = '201111111111'): void
{
    global $sequence;
    $sequence++;
    $raw = ['id' => 'wamid.replyfixture' . $sequence, 'timestamp' => (string) $time, 'type' => 'text', 'text' => ['body' => 'Inbound fixture only']];
    if ($direction === 'inbound') { $raw['from'] = $phone; $raw['from_user_id'] = 'US.fixture1'; }
    else { $raw['from'] = '201285545554'; $raw['to'] = $phone; $raw['to_user_id'] = 'US.fixture1'; }
    $field = $direction === 'inbound' ? 'messages' : 'smb_message_echoes';
    $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269', 'changes' => [[
        'field' => $field, 'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '515388018324075',
            'display_phone_number' => '201285545554'], $direction === 'inbound' ? 'messages' : 'message_echoes' => [$raw]],
    ]]]]];
    $plain = json_encode($payload, JSON_THROW_ON_ERROR);
    DB::table('whatsapp_webhook_events')->insert(['payload_hash' => hash('sha256', $plain),
        'payload' => Crypt::encryptString($plain), 'received_at' => '2023-08-09 12:00:00']);
    $metrics = (new WhatsAppInboxConsumer())->consume(100);
    if ($metrics['messages_inserted'] !== 1 || $metrics['errors'] !== 0) throw new RuntimeException('fixture projection failed');
}
function replyInput(int $inbound, string $text = 'Private operator response', ?string $uuid = null): array
{
    global $sequence;
    if ($uuid === null) { $sequence++; $uuid = sprintf('ab001122-3344-4556-8778-%012d', $sequence); }
    return ['client_request_id' => $uuid, 'expected_inbound_id' => $inbound, 'text' => $text];
}
function replyOk(string $phone = '201111111111'): array
{
    return ['status' => 200, 'body' => json_encode(['messaging_product' => 'whatsapp',
        'contacts' => [['input' => $phone, 'wa_id' => $phone]], 'messages' => [['id' => 'wamid.acceptedfixture']]])];
}
$normalTransport = function ($endpoint, $payload, $token, $multipart) use (&$calls) {
    checkReply(DB::connection()->transactionLevel() === 0, 'customer network boundary outside SQL transaction');
    checkReply(DB::table('whatsapp_reply_requests')->orderByDesc('id')->value('state') === 'UNKNOWN', 'uncertainty durable before customer API');
    checkReply($endpoint === 'https://graph.facebook.com/v25.0/515388018324075/messages' && !$multipart,
        'fixed HTTPS phone endpoint');
    checkReply($payload['to'] === '201111111111' && $payload['text']['preview_url'] === false,
        'server recipient and private plain text only');
    $calls[] = $payload; return replyOk();
};
$actor = (object) ['id' => 1];
$service = new WhatsAppReplyService($normalTransport, $authorize, $clock, $enabled);

[$thread, $inbound] = resetReply();
$state = $service->state($actor, $thread);
checkReply($state['available'] && $state['can_reply'] && $state['latest_inbound_id'] === $inbound
    && $state['window_expires_at'] === gmdate('Y-m-d\TH:i:s\Z', 1691583200 + 86400), 'actual inbound defines window');
$input = replyInput($inbound); $result = $service->send($actor, $thread, $input);
checkReply($result['state'] === 'ACCEPTED' && !$result['replayed'] && count($calls) === 1, 'accepted is one external call');
$replyReadCount = 0;
foreach ($lockGrammar->claimReads as $read) {
    checkReply($read['locked'] && str_ends_with($read['sql'], 'for update'), 'claim SELECT uses a MySQL current locking read');
    if ($read['table'] === 'whatsapp_reply_requests') $replyReadCount++;
}
checkReply($replyReadCount === 2, 'both existing UUID and unresolved gate use current reads after waiting for thread lock');
$row = DB::table('whatsapp_reply_requests')->first();
checkReply($row->actor_id == 1 && $row->send_started_at !== null && $row->completed_at !== null, 'durable actor and send audit');
checkReply(strpos($row->audit, $input['text']) === false && strpos($row->audit, '201111111111') === false
    && strpos($row->audit, $input['client_request_id']) === false && strpos($row->response_details, 'wamid.acceptedfixture') === false,
    'database contains ciphertext for message recipient UUID and remote ID');
$state = $service->state($actor, $thread, $input['client_request_id']);
checkReply($state['request_state'] === 'ACCEPTED' && $state['recent_replies'][0]['text'] === $input['text']
    && $state['recent_replies'][0]['actor_id'] === 1 && !isset($state['recent_replies'][0]['message_id']), 'private UUID lookup and manual reply audit');
checkReply($service->send($actor, $thread, $input)['replayed'] && count($calls) === 1, 'double click never resends');
$changed = $input; $changed['text'] = 'Different message';
refuseReply(fn() => $service->send($actor, $thread, $changed), 409, 'same UUID changed payload rejected');
$fakeNow = $fakeNow->addDays(2);
checkReply($service->send($actor, $thread, $input)['replayed'] && count($calls) === 1, 'accepted UUID replay survives window expiry');

[$thread, $inbound] = resetReply();
$timeout = new WhatsAppReplyService(function () use (&$calls) { $calls[] = 1; return ['status' => 0, 'body' => '']; }, $authorize, $clock, $enabled);
$input = replyInput($inbound);
checkReply($timeout->send($actor, $thread, $input)['state'] === 'UNKNOWN', 'timeout remains uncertain');
checkReply($timeout->send($actor, $thread, $input)['replayed'] && count($calls) === 1, 'unknown same UUID cannot retry');
checkReply($timeout->send((object) ['id' => 2], $thread, replyInput($inbound))['reason'] === 'UNKNOWN_PENDING'
    && count($calls) === 1, 'unknown blocks new UUID from any admin after browser reset');
$state = $timeout->state($actor, $thread, $input['client_request_id']);
checkReply(!$state['can_reply'] && $state['request_state'] === 'UNKNOWN' && $state['reason'] === 'UNKNOWN_PENDING', 'uncertain state resolves browser abort');
checkReply($timeout->state((object) ['id' => 2], $thread, $input['client_request_id'])['request_state'] === null,
    'UUID lookup is actor and conversation scoped');

[$thread, $inbound] = resetReply();
$broken = new WhatsAppReplyService(function () { throw new RuntimeException('private-token-error'); }, $authorize, $clock, $enabled);
$result = $broken->send($actor, $thread, replyInput($inbound));
checkReply($result['state'] === 'UNKNOWN' && strpos(json_encode($result), 'private-token-error') === false, 'transport exceptions remain fixed private uncertainty');
checkReply(DB::table('whatsapp_reply_requests')->value('state') === 'UNKNOWN', 'crash persisted unknown');

foreach ([302, 500, 200] as $http) {
    [$thread, $inbound] = resetReply();
    $bad = new WhatsAppReplyService(fn() => ['status' => $http, 'body' => '{private malformed response'], $authorize, $clock, $enabled);
    checkReply($bad->send($actor, $thread, replyInput($inbound))['state'] === 'UNKNOWN', 'redirect or ambiguous API body never followed or retried');
}
[$thread, $inbound] = resetReply();
$wrongRecipient = new WhatsAppReplyService(fn() => replyOk('201222222222'), $authorize, $clock, $enabled);
checkReply($wrongRecipient->send($actor, $thread, replyInput($inbound))['state'] === 'UNKNOWN', 'different response recipient cannot confirm acceptance');
[$thread, $inbound] = resetReply();
$reject = new WhatsAppReplyService(function () use (&$calls) { $calls[] = 1; return ['status' => 400,
    'body' => '{"error":{"code":100,"message":"PRIVATE_TOKEN_PHONE_TEXT"}}']; }, $authorize, $clock, $enabled);
$input = replyInput($inbound); $result = $reject->send($actor, $thread, $input);
checkReply($result['state'] === 'FAILED' && $result['reason'] === 'META_REJECTED', 'known Graph rejection is final safe failure');
checkReply($reject->send($actor, $thread, $input)['replayed'] && count($calls) === 1, 'known failed UUID also never resends');
checkReply(strpos(json_encode(DB::table('whatsapp_reply_requests')->first()), 'PRIVATE_TOKEN_PHONE_TEXT') === false,
    'Graph error body never retained');

[$thread, $inbound] = resetReply();
$fakeNow = CarbonImmutable::createFromTimestampUTC(1691583200 + 86400);
checkReply($service->send($actor, $thread, replyInput($inbound))['reason'] === 'WINDOW_CLOSED' && !$calls,
    '24-hour boundary closed exactly');
addReplyMessage('outbound', 1691583200 + 86300);
checkReply($service->state($actor, $thread)['reason'] === 'WINDOW_CLOSED', 'outbound echo never extends customer window');
[$thread, $inbound] = resetReply();
$fakeNow = CarbonImmutable::createFromTimestampUTC(1691583199);
checkReply($service->state($actor, $thread)['reason'] === 'RECIPIENT_UNAVAILABLE', 'future inbound timestamp cannot open window');
[$thread, $inbound] = resetReply();
checkReply($service->send($actor, $thread, replyInput($inbound + 1000))['reason'] === 'STALE_INBOUND'
    && !DB::table('whatsapp_reply_requests')->exists() && !$calls, 'stale inbound fails before claim and send');
foreach (['to', 'phone', 'recipient', 'endpoint'] as $field) {
    $input = replyInput($inbound); $input[$field] = '201222222222';
    refuseReply(fn() => $service->send($actor, $thread, $input), 422, 'client cannot inject recipient endpoint');
}
foreach (['', str_repeat('x', 4097), "hidden\0text"] as $text) {
    refuseReply(fn() => $service->send($actor, $thread, replyInput($inbound, $text)), 422, 'invalid text refused');
}
$input = replyInput($inbound); $input['client_request_id'] = 'not-a-UUID';
refuseReply(fn() => $service->send($actor, $thread, $input), 422, 'invalid UUID refused');

foreach (['message_key', 'peer_hash', 'phone_number_id', 'waba_id', 'raw_recipient', 'user_identity', 'dto_time', 'raw_time'] as $tamper) {
    [$thread, $inbound] = resetReply();
    if ($tamper === 'message_key') DB::table('whatsapp_inbox_messages')->where('id', $inbound)->update(['message_key' => str_repeat('0', 64)]);
    elseif (in_array($tamper, ['peer_hash', 'phone_number_id', 'waba_id'], true)) {
        DB::table('whatsapp_inbox_conversations')->where('id', $thread)->update([$tamper => $tamper === 'peer_hash' ? str_repeat('0', 64) : '123456']);
    } else {
        $dto = json_decode(Crypt::decryptString(DB::table('whatsapp_inbox_messages')->where('id', $inbound)->value('content')), true);
        if ($tamper === 'raw_recipient') $dto['content']['message']['from'] = '201222222222';
        if ($tamper === 'user_identity') $dto['peer_user_id'] = 'US.attacker';
        if ($tamper === 'dto_time') $dto['sent_at'] = '2023-08-09 12:00:00';
        if ($tamper === 'raw_time') $dto['content']['message']['timestamp'] = '1691583300';
        DB::table('whatsapp_inbox_messages')->where('id', $inbound)->update(['content' => Crypt::encryptString(json_encode($dto))]);
    }
    if (in_array($tamper, ['phone_number_id', 'waba_id'], true)) refuseReply(fn() => $service->state($actor, $thread), 404, 'scope mismatch hidden');
    else checkReply($service->state($actor, $thread)['reason'] === 'RECIPIENT_UNAVAILABLE', 'persisted identity and raw origin verified');
    checkReply(!$calls && !DB::table('whatsapp_reply_requests')->exists(), 'tampered origin produces no external call or claim');
}

[$thread, $inbound] = resetReply();
$dto = json_decode(Crypt::decryptString(DB::table('whatsapp_inbox_messages')->where('id', $inbound)->value('content')), true);
$dto['peer_phone'] = null; unset($dto['content']['message']['from']);
DB::table('whatsapp_inbox_messages')->where('id', $inbound)->update(['content' => Crypt::encryptString(json_encode($dto))]);
checkReply($service->state($actor, $thread)['reason'] === 'RECIPIENT_UNAVAILABLE', 'BSUID-only peer unavailable without documented send contract');
[$thread, $inbound] = resetReply();
DB::table('test_reply_actors')->where('id', 1)->update(['account_type' => 'staff']);
refuseReply(fn() => $service->state($actor, $thread), 403, 'fresh persisted role excludes staff');
refuseReply(fn() => $service->send($actor, $thread, replyInput($inbound)), 403, 'send excludes revoked session role');
DB::table('test_reply_actors')->where('id', 1)->update(['account_type' => 'admin', 'owner_resturant_id' => 7]);
refuseReply(fn() => $service->state($actor, $thread), 403, 'restaurant admin lacks central inbox role');

[$thread, $inbound] = resetReply();
$authHook = function ($n) { if ($n === 2) DB::table('test_reply_actors')->where('id', 1)->update(['account_type' => 'staff']); };
refuseReply(fn() => $service->send($actor, $thread, replyInput($inbound)), 403, 'authorization reloaded immediately before claim');
checkReply(!DB::table('whatsapp_reply_requests')->exists() && !$calls, 'revocation before claim creates no outbox');
[$thread, $inbound] = resetReply();
$authHook = function ($n) { if ($n === 5) DB::table('test_reply_actors')->where('id', 1)->update(['account_type' => 'staff']); };
$result = $service->send($actor, $thread, replyInput($inbound));
checkReply($result['state'] === 'FAILED' && $result['reason'] === 'ACCESS_REVOKED' && !$calls,
    'authorization revocation at final send boundary prevents Graph call');
checkReply(DB::table('whatsapp_reply_requests')->value('state') === 'FAILED', 'known local no-send failure clears uncertainty');

[$thread, $inbound] = resetReply();
$authHook = function ($n) {
    if ($n === 4) DB::table('whatsapp_reply_requests')->update(['state' => 'FAILED', 'reason' => 'ACCESS_REVOKED']);
};
checkReply($service->send($actor, $thread, replyInput($inbound))['state'] === 'UNKNOWN' && !$calls
    && DB::table('whatsapp_reply_requests')->value('state') === 'FAILED', 'send-start compare-and-swap failure prevents API call and state overwrite');

[$thread, $inbound] = resetReply();
$disabled = new WhatsAppReplyService($normalTransport, $authorize, $clock, ['enabled' => false, 'access_token' => $enabled['access_token']]);
checkReply(!$disabled->state($actor, $thread)['available'] && $disabled->send($actor, $thread, replyInput($inbound))['reason'] === 'REPLIES_UNAVAILABLE',
    'default disabled cannot activate or send');
foreach (['', 'invalid token with spaces', "private\nheader"] as $token) {
    $invalid = new WhatsAppReplyService($normalTransport, $authorize, $clock, ['enabled' => true, 'access_token' => $token]);
    checkReply(!$invalid->state($actor, $thread)['available'], 'missing or unsafe token fails readiness');
}
checkReply(!$calls, 'disabled and invalid credentials make no network calls');

// Reentrant fake transport emulates a simultaneous competing request after durable claim.
[$thread, $inbound] = resetReply();
$race = null; $inner = null;
$race = new WhatsAppReplyService(function () use (&$race, &$inner, &$calls, $actor, $thread, $inbound) {
    $calls[] = 1;
    $inner = $race->send($actor, $thread, replyInput($inbound));
    return replyOk();
}, $authorize, $clock, $enabled);
checkReply($race->send($actor, $thread, replyInput($inbound))['state'] === 'ACCEPTED'
    && $inner['reason'] === 'UNKNOWN_PENDING' && count($calls) === 1, 'different UUID concurrent claim cannot double send');
// A worker dying before send leaves CLAIMED blocked, exposed UNKNOWN, and never reclaimed.
[$thread, $inbound] = resetReply();
$input = replyInput($inbound); $timeout->send($actor, $thread, $input);
DB::table('whatsapp_reply_requests')->update(['state' => 'CLAIMED', 'send_started_at' => null, 'reason' => null]);
checkReply($timeout->send($actor, $thread, $input)['state'] === 'UNKNOWN' && count($calls) === 1
    && !$timeout->state($actor, $thread)['can_reply'], 'abandoned claimed ledger blocks instead of automatic retry');

class ReplyFixtureVoice extends WhatsAppVoiceMedia
{
    public int $prepared = 0; public int $cleaned = 0; public bool $tamper = false;
    public function available(): bool { return true; }
    public function prepare(UploadedFile $file): array { $this->prepared++; return ['path' => $file->getPathname(),
        'mime' => 'audio/ogg', 'filename' => 'voice.ogg', 'input_sha256' => $this->tamper ? str_repeat('0', 64) : hash_file('sha256', $file->getPathname())]; }
    public function cleanup(string $path): void { $this->cleaned++; }
}
$voicePath = tempnam(sys_get_temp_dir(), 'wa-reply-fixture-'); file_put_contents($voicePath, 'local fake prepared codec fixture');
try {
    [$thread, $inbound] = resetReply(); $fixtureVoice = new ReplyFixtureVoice();
    $voiceTransport = function ($endpoint, $payload, $token, $multipart) use (&$calls) {
        checkReply(DB::connection()->transactionLevel() === 0, 'voice API outside SQL transaction');
        $calls[] = $payload;
        if ($multipart) {
            checkReply(str_ends_with($endpoint, '/media') && DB::table('whatsapp_reply_requests')->value('state') === 'CLAIMED'
                && $payload['type'] === 'audio/ogg' && $payload['file']['filename'] === 'voice.ogg', 'media upload follows durable claim with prepared private file');
            return ['status' => 200, 'body' => '{"id":"1234567890123"}'];
        }
        checkReply($payload['type'] === 'audio' && $payload['audio'] === ['id' => '1234567890123']
            && !isset($payload['audio']['link'], $payload['audio']['voice']) && $payload['to'] === '201111111111', 'voice uses uploaded media ID and verified recipient');
        return replyOk();
    };
    $voiceService = new WhatsAppReplyService($voiceTransport, $authorize, $clock, $enabled, $fixtureVoice);
    $voiceInput = replyInput($inbound); unset($voiceInput['text']);
    $file = new UploadedFile($voicePath, 'attacker;name.webm', 'untrusted/mime', UPLOAD_ERR_OK, true);
    $result = $voiceService->voice($actor, $thread, $voiceInput, $file);
    checkReply($result['state'] === 'ACCEPTED' && count($calls) === 2 && $fixtureVoice->cleaned === 1, 'voice upload and one send clean local recording');
    checkReply($voiceService->voice($actor, $thread, $voiceInput, $file)['replayed'] && count($calls) === 2
        && $fixtureVoice->prepared === 1, 'voice UUID replay skips conversion upload and resend');
    $audit = $voiceService->state($actor, $thread)['recent_replies'][0];
    checkReply($audit['kind'] === 'audio' && $audit['text'] === null && !isset($audit['path'], $audit['media_id']), 'voice private audit leaks no local path or media URL');
    file_put_contents($voicePath, 'different bytes same UUID');
    refuseReply(fn() => $voiceService->voice($actor, $thread, $voiceInput, $file), 409, 'same voice UUID changed recording rejected');

    [$thread, $inbound] = resetReply(); $fixtureVoice->tamper = true;
    $voiceInput = replyInput($inbound); unset($voiceInput['text']);
    checkReply($voiceService->voice($actor, $thread, $voiceInput, $file)['reason'] === 'INVALID_VOICE'
        && !$calls && !DB::table('whatsapp_reply_requests')->exists(), 'copied source bytes must match voice UUID digest');
    $fixtureVoice->tamper = false;
    [$thread, $inbound] = resetReply();
    $uploadFailure = new WhatsAppReplyService(fn() => ['status' => 0, 'body' => ''], $authorize, $clock, $enabled, $fixtureVoice);
    $voiceInput = replyInput($inbound); unset($voiceInput['text']);
    $result = $uploadFailure->voice($actor, $thread, $voiceInput, $file);
    checkReply($result['state'] === 'FAILED' && $result['reason'] === 'MEDIA_UPLOAD_FAILED'
        && DB::table('whatsapp_reply_requests')->value('send_started_at') === null, 'uncertain upload alone proves no customer delivery');
    [$thread, $inbound] = resetReply();
    $voiceUnknown = new WhatsAppReplyService(function ($endpoint) {
        return str_ends_with($endpoint, '/media') ? ['status' => 200, 'body' => '{"id":"1234"}'] : ['status' => 0, 'body' => ''];
    }, $authorize, $clock, $enabled, $fixtureVoice);
    $voiceInput = replyInput($inbound); unset($voiceInput['text']);
    checkReply($voiceUnknown->voice($actor, $thread, $voiceInput, $file)['state'] === 'UNKNOWN'
        && !$voiceUnknown->state($actor, $thread)['can_reply'], 'uncertain audio delivery blocks new requests');
    [$thread, $inbound] = resetReply();
    $voiceRevoked = new WhatsAppReplyService(function () { DB::table('test_reply_actors')->where('id', 1)->update(['account_type' => 'staff']);
        return ['status' => 200, 'body' => '{"id":"1234"}']; }, $authorize, $clock, $enabled, $fixtureVoice);
    $voiceInput = replyInput($inbound); unset($voiceInput['text']);
    $result = $voiceRevoked->voice($actor, $thread, $voiceInput, $file);
    checkReply($result['state'] === 'FAILED' && $result['reason'] === 'ACCESS_REVOKED'
        && DB::table('whatsapp_reply_requests')->value('send_started_at') === null, 'role revoked during upload prevents audio delivery');
} finally { unlink($voicePath); }

echo "WHATSAPP_REPLY_SERVICE_TESTS=$count PASS\n";
