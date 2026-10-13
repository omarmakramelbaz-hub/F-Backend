<?php
namespace {
    $vendor = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($vendor) && is_dir($vendor)) $vendor .= '/autoload.php';
    if (!is_string($vendor) || !is_file($vendor)) throw new \RuntimeException('ISOLATED_TEST_VENDOR_REQUIRED');
    require $vendor;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($name = null, $default = null) { return $name === null ? app('config') : app('config')->get($name, $default); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    set_error_handler(function ($severity, $message, $file, $line) { if ($severity & E_ALL) throw new \ErrorException($message, 0, $severity, $file, $line); return false; });
}
namespace App\Services\Dashboard {
    // Only unrelated authorization/media/exception dependencies are isolated; receipt, reply,
    // projection, native parsing, encryption and DB transactions use production implementations.
    class WhatsAppInboxAccess { public const WABA_ID = '468336579702269'; public const PHONE_ID = '515388018324075'; }
    class WhatsAppVoiceMedia {}
    class WhatsAppInboxMedia { public function __construct(...$args) {} }
    class WhatsAppInboxQuarantinedEvent extends \RuntimeException {
        public function __construct($reason = 'INVALID_EVENT') { parent::__construct($reason); }
        public function reasonCode() { return $this->getMessage(); }
    }
}
namespace {
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\Crypt;
    use App\Services\Dashboard\WhatsAppAcceptedReplyEvidence as Evidence;
    use App\Services\Dashboard\WhatsAppInboxMessage as Message;
    use App\Services\Dashboard\WhatsAppReplyService as Reply;
    use App\Services\Dashboard\WhatsAppInboxConsumer as Consumer;
    use App\Support\WhatsAppInboxProtocol as Protocol;

    $container = new \Illuminate\Container\Container();
    \Illuminate\Container\Container::setInstance($container);
    $container->instance('config', new \Illuminate\Config\Repository(['app' => ['key' => str_repeat('k', 32)], 'desktop_dashboard' => ['local' => false]]));
    $db = new Capsule($container); $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setAsGlobal(); $db->bootEloquent();
    $container->instance('db', $db->getDatabaseManager());
    $container->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
    $container->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    \Carbon\Carbon::setTestNow('2026-10-13 10:10:00 UTC');
    \Carbon\CarbonImmutable::setTestNow('2026-10-13 10:10:00 UTC');

    foreach (['WhatsAppInboxMessage', 'WhatsAppAcceptedReplyEvidence', 'WhatsAppReplyService', 'WhatsAppInboxConsumer'] as $class)
        require dirname(__DIR__) . '/app/Services/Dashboard/' . $class . '.php';
    require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
    Schema::create('whatsapp_webhook_events', function ($t) { $t->bigIncrements('id'); $t->longText('payload'); $t->timestamp('processed_at')->nullable(); $t->timestamps(); });
    require dirname(__DIR__) . '/database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php';
    require dirname(__DIR__) . '/database/migrations/2026_10_10_000003_create_whatsapp_reply_requests.php';
    (new \CreateWhatsAppInboxTables())->up(); (new \CreateWhatsAppReplyRequests())->up();
    Schema::create(Message::INDEX, function ($t) {
        $t->bigIncrements('id'); $t->unsignedBigInteger('reply_id')->unique(); $t->unsignedBigInteger('conversation_id');
        $t->string('waba_id'); $t->string('phone_number_id'); $t->char('message_key', 64)->nullable()->unique();
        $t->string('kind'); $t->char('receipt_hash', 64); $t->boolean('valid'); $t->timestamps();
    });
    $checks = 0;
    function expectEvidence($actual, $expected, $label) { global $checks; $checks++; if ($actual !== $expected) throw new \RuntimeException('FAILED: ' . $label); }
    function enc(array $x) { return Crypt::encryptString(json_encode($x, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)); }
    function scope() { return '468336579702269:515388018324075:'; }
    function fixtureNative($id, $direction, $text, $time, $source = null): array {
        $source ??= $direction === 'inbound' ? 'messages' : 'smb_message_echoes';
        $raw = ['id' => $id, 'type' => 'text', 'timestamp' => (string) strtotime($time . ' UTC'),
            $direction === 'inbound' ? 'from' : 'to' => '201000000001',
            $direction === 'inbound' ? 'from_user_id' : 'to_user_id' => 'US.fixture', 'text' => ['body' => $text]];
        return ['message_id' => $id, 'peer_identity' => 'user:US.fixture', 'peer_phone' => '201000000001', 'peer_user_id' => 'US.fixture',
            'customer_name' => 'عميل', 'type' => 'text', 'direction' => $direction, 'source' => $source, 'sent_at' => $time, 'text' => $text,
            'content' => ['sources' => [$source], 'message' => $raw]];
    }
    function addNative($id, $direction, $text, $time): int {
        $dto = fixtureNative($id, $direction, $text, $time);
        return DB::table('whatsapp_inbox_messages')->insertGetId(['conversation_id' => 1, 'message_key' => hash('sha256', 'whatsapp-message-v1:' . scope() . $id),
            'type' => 'text', 'direction' => $direction, 'source' => $dto['source'], 'content' => enc($dto), 'sent_at' => $time,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    function addReceipt($native, $text, $accepted = '2026-10-13 10:02:00', $extraAudit = []): object {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', DB::table('whatsapp_reply_requests')->count() + 1);
        $audit = array_replace(['client_request_id' => $uuid, 'text' => $text, 'recipient' => '201000000001', 'voice_sha256' => null,
            'attachment' => null, 'reply_to' => null, 'action' => null, 'source' => null, 'media' => null, 'emoji' => null], $extraAudit);
        $response = enc(['message_id' => $native]);
        $values = ['text', 1, $text, null];
        if (isset($audit['payload_values'])) $values = $audit['payload_values'];
        $rid = DB::table('whatsapp_reply_requests')->insertGetId(['conversation_id' => 1, 'actor_id' => 7,
            'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075', 'kind' => 'text', 'state' => 'ACCEPTED',
            'request_hash' => hash_hmac('sha256', 'whatsapp-reply-request-v1:7:1:' . $uuid, Crypt::getKey()),
            'payload_hash' => hash_hmac('sha256', 'whatsapp-reply-payload-v1:' . json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), Crypt::getKey()),
            'remote_message_key' => hash('sha256', 'whatsapp-reply-message-v1:' . scope() . $native), 'audit' => enc($audit),
            'response_details' => $response, 'inbound_message_id' => 1, 'claimed_at' => '2026-10-13 10:01:00',
            'send_started_at' => '2026-10-13 10:01:30', 'completed_at' => $accepted, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $row = DB::table('whatsapp_reply_requests')->where('id', $rid)->first();
        (new Message())->indexReply($row);
        return $row;
    }
    function thread() { return DB::table('whatsapp_inbox_conversations')->where('id', 1)->first(); }
    function blocked(callable $f, $label) { try { $f(); } catch (\Throwable $e) { expectEvidence(true, true, $label); return; } throw new \RuntimeException('FAILED: ' . $label); }
    function echoEvent(array $dto): int {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269', 'changes' => [['field' => 'smb_message_echoes', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
            'contacts' => [['wa_id' => '201000000001', 'user_id' => 'US.fixture', 'profile' => ['name' => 'عميل']]],
            'message_echoes' => [$dto['content']['message']]]]]]]];
        return DB::table('whatsapp_webhook_events')->insertGetId(['payload' => enc($payload), 'processed_at' => null, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    DB::table('whatsapp_inbox_conversations')->insert(['id' => 1, 'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075',
        'peer_hash' => hash_hmac('sha256', 'whatsapp-peer-v1:' . scope() . 'user:US.fixture', Crypt::getKey()),
        'customer' => enc(['name' => 'عميل', 'phone' => '201000000001', 'user_id' => 'US.fixture']),
        'first_message_at' => '2026-10-13 10:00:00', 'last_message_at' => '2026-10-13 10:00:00', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    addNative('wamid.INBOUND', 'inbound', 'عايز نصف كيلو فسيخ دليفري', '2026-10-13 10:00:00');
    $e = new Evidence();
    $r = addReceipt('wamid.API1', 'تم تأكيد الطلب نصف كيلو فسيخ');
    $mid = $e->projectAcceptedReply($r);
    expectEvidence(is_int($mid), true, 'accepted receipt advances single inbox cursor');
    $row = DB::table('whatsapp_inbox_messages')->where('id', $mid)->first(); $original = (array) $row;
    $content = json_decode(Crypt::decryptString($row->content), true);
    expectEvidence($row->source, 'dashboard_api', 'honest API source');
    expectEvidence(isset($content['content']['message']), false, 'no fabricated native payload');
    expectEvidence($row->sent_at, $r->completed_at, 'actual historical accepted timestamp');
    expectEvidence($e->unwrap(thread(), $row)['text'], 'تم تأكيد الطلب نصف كيلو فسيخ', 'pure validated transcript text');
    expectEvidence((new Message())->projected(thread(), $row)['native_id'], 'wamid.API1', 'protected reader understands receipt projection');
    expectEvidence($e->projectAcceptedReply($r), $mid, 'projection idempotent');
    blocked(function () use ($e, $r) { DB::transaction(fn () => $e->projectAcceptedReply($r)); }, 'projection refuses any uncommitted caller transaction');
    expectEvidence(DB::table('whatsapp_reply_requests')->where('id', $r->id)->value('state'), 'ACCEPTED', 'nested projection rejection retains durable acceptance');
    $native = fixtureNative('wamid.API1', 'outbound', 'تم تأكيد الطلب نصف كيلو فسيخ', '2026-10-13 10:01:50');
    expectEvidence($e->matchesNativeEcho(thread(), $row, $native), true, 'different genuine timestamp allowed');
    $eid = echoEvent($native); $metrics = (new Consumer())->consume(10);
    expectEvidence($metrics['events_processed'], 1, 'real Consumer processes matching native echo');
    expectEvidence($metrics['messages_replayed'], 1, 'same canonical message replayed');
    expectEvidence($metrics['quarantined_events'], 0, 'timestamp difference does not quarantine');
    expectEvidence((array) DB::table('whatsapp_inbox_messages')->where('id', $mid)->first(), $original, 'echo never rewrites receipt evidence');
    expectEvidence(DB::table('whatsapp_inbox_messages')->where('message_key', $row->message_key)->count(), 1, 'one row across API and echo');
    $wrong = $native; $wrong['text'] = 'طلب مختلف'; $wrong['content']['message']['text']['body'] = 'طلب مختلف';
    $badEvent = echoEvent($wrong); $metrics = (new Consumer())->consume(10);
    expectEvidence($metrics['quarantined_events'], 1, 'conflicting native echo quarantined');
    expectEvidence(DB::table('whatsapp_webhook_events')->where('id', $badEvent)->value('processed_at'), null, 'conflict retains raw event');
    expectEvidence(DB::table('whatsapp_inbox_ingestion_failures')->count(), 1, 'no global quarantine clearing');

    $nativeId = addNative('wamid.NATIVE_FIRST', 'outbound', 'تم تأكيد نصف كيلو فسيخ', '2026-10-13 10:01:50');
    $n = addReceipt('wamid.NATIVE_FIRST', 'تم تأكيد نصف كيلو فسيخ');
    expectEvidence($e->projectAcceptedReply($n), $nativeId, 'native-first race reuses projection');
    expectEvidence(DB::table('whatsapp_inbox_messages')->where('id', $nativeId)->value('source'), 'smb_message_echoes', 'native source stays native');

    $nativeFirstRow = DB::table('whatsapp_inbox_messages')->where('id', $nativeId)->first();
    $e->validateNativeReceipt(thread(), $nativeFirstRow);
    expectEvidence(true, true, 'native-first accepted counterpart read-only validation');
    DB::table('whatsapp_reply_requests')->where('id', $n->id)->update(['payload_hash' => str_repeat('9', 64)]);
    blocked(fn () => $e->validateNativeReceipt(thread(), $nativeFirstRow), 'native-first accepted tamper fails closed');
    DB::table('whatsapp_reply_requests')->where('id', $n->id)->update(['state' => 'UNKNOWN']);
    $e->validateNativeReceipt(thread(), $nativeFirstRow);
    expectEvidence(true, true, 'genuine native proof is not rejected due to UNKNOWN request');
    DB::table('whatsapp_reply_requests')->where('id', $n->id)->update(['state' => 'ACCEPTED', 'payload_hash' => $n->payload_hash]);
    foreach (['state' => 'UNKNOWN', 'kind' => 'audio'] as $field => $value) {
        $clone = clone $r; $clone->$field = $value;
        expectEvidence($e->projectAcceptedReply($clone), null, 'nonaccepted/nontext excluded');
    }
    foreach (['actor_id' => 8, 'payload_hash' => str_repeat('a', 64), 'remote_message_key' => str_repeat('b', 64),
        'inbound_message_id' => 987, 'completed_at' => '2026-10-13 09:00:00'] as $field => $value) {
        DB::table('whatsapp_reply_requests')->where('id', $r->id)->update([$field => $value]);
        blocked(fn () => $e->unwrap(thread(), $row), 'immutable receipt tampering rejected: ' . $field);
        DB::table('whatsapp_reply_requests')->where('id', $r->id)->update([$field => $r->$field]);
    }
    $audit = json_decode(Crypt::decryptString($r->audit), true); $audit['recipient'] = '201000000009';
    DB::table('whatsapp_reply_requests')->where('id', $r->id)->update(['audit' => enc($audit)]);
    blocked(fn () => $e->unwrap(thread(), $row), 'recipient contradiction rejected');
    DB::table('whatsapp_reply_requests')->where('id', $r->id)->update(['audit' => $r->audit]);
    $quoted = addReceipt('wamid.OLD_QUOTED', 'تم تأكيد الطلب', '2026-10-13 10:03:00', ['reply_to' => ['message_id' => 1, 'reply_id' => null]]);
    blocked(fn () => $e->projectAcceptedReply($quoted), 'unproven legacy quoted payload fails closed');
    $futureQuote = addReceipt('wamid.NEW_QUOTED', 'تم تأكيد الطلب', '2026-10-13 10:03:00', [
        'reply_to' => ['message_id' => 1, 'reply_id' => null],
        'payload_values' => ['text', 1, 'تم تأكيد الطلب', null, [1, null, null, null, null, str_repeat('f', 64)]]]);
    $futureQuoteId = $e->projectAcceptedReply($futureQuote);
    expectEvidence(is_int($futureQuoteId), true, 'future quoted payload fully HMAC-backed');
    $quotedProjection = DB::table('whatsapp_inbox_messages')->where('id', $futureQuoteId)->first();
    expectEvidence($e->unwrap(thread(), $quotedProjection)['quote_source'], ['message_id' => 1, 'reply_id' => null], 'HMAC-backed message quote pointer exposed honestly');
    expectEvidence((new Message())->projected(thread(), $quotedProjection)['quote_source'], ['message_id' => 1, 'reply_id' => null], 'protected message reader preserves quote pointer');
    expectEvidence(isset((new Message())->projected(thread(), $quotedProjection)['raw']['context']), false, 'quote pointer never fabricates native raw context');
    $quotedAudit = json_decode(Crypt::decryptString($futureQuote->audit), true);
    $changedQuote = $quotedAudit; $changedQuote['reply_to']['message_id'] = 999;
    DB::table('whatsapp_reply_requests')->where('id', $futureQuote->id)->update(['audit' => enc($changedQuote)]);
    blocked(fn () => $e->unwrap(thread(), $quotedProjection), 'altered quote pointer fails receipt/HMAC validation');
    DB::table('whatsapp_reply_requests')->where('id', $futureQuote->id)->update(['audit' => $futureQuote->audit]);
    $badQuote = addReceipt('wamid.BAD_QUOTE_POINTER', 'تم تأكيد الطلب', '2026-10-13 10:03:00', [
        'reply_to' => ['message_id' => 1, 'reply_id' => 2],
        'payload_values' => ['text', 1, 'تم تأكيد الطلب', null, [1, 2, null, null, null, str_repeat('f', 64)]]]);
    blocked(fn () => $e->projectAcceptedReply($badQuote), 'two quote pointers rejected even with matching HMAC');
    $replyQuote = addReceipt('wamid.REPLY_QUOTE', 'تم تأكيد الطلب', '2026-10-13 10:03:00', [
        'reply_to' => ['message_id' => null, 'reply_id' => (int) $r->id],
        'payload_values' => ['text', 1, 'تم تأكيد الطلب', null, [null, (int) $r->id, null, null, null, str_repeat('e', 64)]]]);
    $replyQuoteId = $e->projectAcceptedReply($replyQuote);
    expectEvidence($e->unwrap(thread(), DB::table('whatsapp_inbox_messages')->where('id', $replyQuoteId)->first())['quote_source'],
        ['message_id' => null, 'reply_id' => (int) $r->id], 'HMAC-backed accepted-reply quote pointer exposed');

    // Projection failure cannot undo a real ACCEPTED API response or trigger another provider send.
    $actor = (object) ['id' => 7]; $calls = 0;
    $service = new Reply(function ($resource, $payload, $multipart) use (&$calls) { $calls++; return ['status' => 200, 'body' => json_encode([
        'messaging_product' => 'whatsapp', 'contacts' => [['input' => '201000000001', 'wa_id' => '201000000001']], 'messages' => [['id' => 'wamid.SERVICE1']]])];
    }, fn ($actor) => $actor, fn () => new \DateTimeImmutable('2026-10-13 10:10:00 UTC'), ['enabled' => true, 'access_token' => str_repeat('fixture', 8)]);
    $uuid = '11111111-1111-4111-8111-111111111111';
    $result = $service->send($actor, 1, ['client_request_id' => $uuid, 'expected_inbound_id' => 1, 'text' => 'تأكيد طلب العميل نصف كيلو فسيخ']);
    expectEvidence($result['state'], 'ACCEPTED', 'real reply acceptance transaction succeeds');
    expectEvidence($calls, 1, 'one simulated transport call');
    $apiRow = DB::table('whatsapp_inbox_messages')->where('message_key', hash('sha256', 'whatsapp-message-v1:' . scope() . 'wamid.SERVICE1'))->first();
    expectEvidence($apiRow->source, 'dashboard_api', 'acceptance path projects immediately');
    $again = $service->send($actor, 1, ['client_request_id' => $uuid, 'expected_inbound_id' => 1, 'text' => 'تأكيد طلب العميل نصف كيلو فسيخ']);
    expectEvidence($again['state'], 'ACCEPTED', 'same client UUID accepted replay');
    expectEvidence($calls, 1, 'acceptance replay never sends again');

    $savedCustomer = thread()->customer; $failedCalls = 0;
    $failingProjection = new Reply(function () use (&$failedCalls) {
        $failedCalls++;
        DB::table('whatsapp_inbox_conversations')->where('id', 1)->update(['customer' => enc(['phone' => '201000000009', 'user_id' => 'US.fixture'])]);
        return ['status' => 200, 'body' => json_encode(['messaging_product' => 'whatsapp',
            'contacts' => [['input' => '201000000001', 'wa_id' => '201000000001']], 'messages' => [['id' => 'wamid.PROJECTION_FAILURE']]])];
    }, fn ($actor) => $actor, fn () => new \DateTimeImmutable('2026-10-13 10:10:00 UTC'), ['enabled' => true, 'access_token' => str_repeat('fixture', 8)]);
    $failedUuid = '22222222-2222-4222-8222-222222222222';
    $failedResult = $failingProjection->send($actor, 1, ['client_request_id' => $failedUuid, 'expected_inbound_id' => 1, 'text' => 'تم تأكيد الطلب']);
    expectEvidence($failedResult['state'], 'ACCEPTED', 'evidence failure cannot roll back provider acceptance');
    expectEvidence(DB::table('whatsapp_inbox_messages')->where('message_key', hash('sha256', 'whatsapp-message-v1:' . scope() . 'wamid.PROJECTION_FAILURE'))->exists(), false, 'unproven receipt is not inserted');
    $failedPending = $e->projectPending(20, 1, '2026-10-13 10:09:00');
    expectEvidence($failedPending['invalid'], 1, 'contradictory recipient blocks affected conversation');
    expectEvidence($failedPending['failed_conversation_ids'], [1], 'failure metrics disclose bounded conversation IDs only');
    expectEvidence($failedPending['pending_conversation_ids'], [1], 'pending metrics retain affected conversation');
    DB::table('whatsapp_inbox_conversations')->where('id', 1)->update(['customer' => $savedCustomer]);
    $failedReplay = $failingProjection->send($actor, 1, ['client_request_id' => $failedUuid, 'expected_inbound_id' => 1, 'text' => 'تم تأكيد الطلب']);
    expectEvidence($failedReplay['state'], 'ACCEPTED', 'provider-accepted failed projection replays safely');
    expectEvidence($failedCalls, 1, 'failed evidence projection never retries sending');
    $recovered = $e->projectPending(20, 1, '2026-10-13 10:09:00');
    expectEvidence($recovered['projected'], 1, 'bounded projection recovery uses receipt without provider');
    expectEvidence($recovered['complete'], true, 'recovered evidence completes affected conversation');

    // A post-activation query skips an earlier accepted receipt without changing its time.
    $old = addReceipt('wamid.OLD', 'تأكيد قديم', '2026-10-13 10:01:40');
    $pending = $e->projectPending(20, 1, '2026-10-13 10:03:01');
    expectEvidence($pending['processed'], 0, 'cutoff does not backfill historical receipts');
    expectEvidence(DB::table('whatsapp_inbox_messages')->where('message_key', hash('sha256', 'whatsapp-message-v1:' . scope() . 'wamid.OLD'))->exists(), false, 'old receipt not replayed after activation');
    $pending = $e->projectPending(1, 1, '2026-10-13 10:03:00');
    expectEvidence($pending['processed'], 1, 'bounded invalid receipt examined once');
    expectEvidence($pending['invalid'], 1, 'unprovable legacy receipt reported');
    expectEvidence($pending['complete'], false, 'incomplete evidence explicitly reported');
    DB::table(Message::INDEX)->where('reply_id', $n->id)->update(['valid' => false]);
    $badMapping = $e->projectPending(20, 1, '2026-10-13 10:01:59');
    expectEvidence($badMapping['invalid'] >= 1, true, 'invalid index cannot silently remove accepted evidence');
    expectEvidence(in_array(1, $badMapping['failed_conversation_ids'], true), true, 'invalid index flags affected conversation');
    Schema::drop(Message::INDEX);
    expectEvidence($e->projectPending()['complete'], false, 'missing receipt metadata fails closed');
    expectEvidence($e->projectPending()['failed_conversation_ids'], [1], 'missing receipt metadata marks bounded affected conversation');
    Schema::drop('whatsapp_reply_requests');
    expectEvidence($e->projectPending()['complete'], true, 'no legacy reply table remains a no-op');
    echo 'WHATSAPP_ACCEPTED_REPLY_EVIDENCE_CHECKS=' . $checks . "\n";
}
