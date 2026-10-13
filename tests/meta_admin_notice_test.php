<?php
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload .= '/autoload.php';
    require $autoload;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function now($tz = null) { return \Carbon\Carbon::now($tz); }
    function abort_unless($ok, $status, $message = 'BLOCKED') { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_if($ok, $status, $message = 'BLOCKED') { abort_unless(!$ok, $status, $message); }
    set_error_handler(function ($s, $m, $f, $l) { if ($s & E_ALL) throw new \ErrorException($m, 0, $s, $f, $l); return false; });
}
namespace App\Models { class User { public $id = 1; public $account_type = 'admin'; public $owner_resturant_id = null; public $active = true; } }
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor) { \abort_unless($actor && $actor->active, 403); return $actor; }
        public function permissions($actor) { return ['can_checkout' => true]; }
    }
    class PosServiceTicket {
        public static function fingerprint(array $value): string {
            $sort = function ($data) use (&$sort) {
                if (!is_array($data)) return $data;
                if (!array_is_list($data)) ksort($data);
                foreach ($data as $key => $item) $data[$key] = $sort($item);
                return $data;
            };
            return hash('sha256', json_encode($sort($value), JSON_UNESCAPED_UNICODE));
        }
    }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Facade;
    use App\Support\MetaMessagingProtocol as Protocol;
    use App\Services\Dashboard\MetaInboxConsumer as Consumer;

    $c = new Container; Container::setInstance($c); $db = new Manager($c);
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $db->setEventDispatcher(new Dispatcher($c)); $db->setAsGlobal(); $db->bootEloquent(); $key = random_bytes(32);
    $accounts = [
        ['channel' => 'messenger', 'account_id' => '101', 'access_token' => str_repeat('a', 24)],
        ['channel' => 'instagram', 'account_id' => '202', 'access_token' => str_repeat('b', 24), 'login' => 'instagram'],
    ];
    $c->instance('config', new Repository(['app' => ['key' => 'base64:' . base64_encode($key)],
        'meta_messaging' => ['accounts' => $accounts, 'api_version' => 'v25.0'],
        'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]] ]));
    $c->instance('db', $db->getDatabaseManager()); $c->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); Facade::setFacadeApplication($c);
    foreach (['Support/WhatsAppWebhookProtocol', 'Support/MetaMessagingProtocol', 'Services/Dashboard/WhatsAppInboxAccess',
        'Services/Dashboard/MetaInboxAccess', 'Services/Dashboard/MetaInvalidEvent', 'Services/Dashboard/MetaInboxConsumer',
        'Services/Dashboard/MetaInboxReadState', 'Services/Dashboard/MetaUploadMedia', 'Services/Dashboard/MetaReplyService',
        'Services/Dashboard/WhatsAppOrderWorkflow', 'Services/Dashboard/MetaOrderWorkflow'] as $file) require __DIR__ . '/../app/' . $file . '.php';
    foreach (glob(__DIR__ . '/../database/migrations/2026_10_11_*create_meta*.php') as $file) require $file;
    foreach (['CreateMetaWebhookEventsTable', 'CreateMetaInboxTables', 'CreateMetaOrderDrafts', 'CreateMetaReplyRequests', 'CreateMetaInboxReads'] as $klass) (new $klass)->up();

    $checks = 0;
    function check($ok, $label): void { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function notice(string $object = 'page', string $account = '101', string $peer = '900', bool $outbound = false, string $source = 'messaging'): array {
        return ['object' => $object, 'entry' => [['id' => $account, $source => [[
            'sender' => ['id' => $outbound ? $account : $peer], 'recipient' => ['id' => $outbound ? $peer : $account],
            'timestamp' => (time() - 2) * 1000, 'message' => ['admin_text' => "PRIVATE_ADMIN_NOTICE\nإشعار إداري\t"],
        ]]]]];
    }
    function rejected(array $payload, string $category, string $label): void {
        global $accounts;
        try { Protocol::report($payload, $accounts); check(false, $label); }
        catch (\InvalidArgumentException $e) { check($e->getMessage() === $category, $label); }
    }
    function capture(array $payload): int {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        return (int) DB::table('meta_webhook_events')->insertGetId(['payload_hash' => hash('sha256', $json),
            'payload' => Crypt::encryptString($json), 'received_at' => now('UTC')]);
    }
    function native(string $mid, string $text, string $peer = '900', bool $outbound = false): array {
        $payload = notice('page', '101', $peer, $outbound);
        $payload['entry'][0]['messaging'][0]['message'] = ['mid' => $mid, 'text' => $text, 'admin_text' => 'Native metadata']
            + ($outbound ? ['is_echo' => true] : []);
        return $payload;
    }

    foreach ([['page', '101'], ['instagram', '202']] as [$object, $account]) foreach ([false, true] as $outbound) foreach (['messaging', 'standby'] as $source) {
        $report = Protocol::report(notice($object, $account, '900', $outbound, $source), $accounts);
        check($report === ['messages' => [], 'ignored_count' => 1], 'pure administrative notice ignored across native channels and sources');
    }
    $payload = notice();
    $boundary = $payload; $boundary['entry'][0]['messaging'][0]['message']['admin_text'] = str_repeat('a', 100000);
    $boundary['entry'][0]['messaging'][0]['timestamp'] = (string) $boundary['entry'][0]['messaging'][0]['timestamp'];
    check(Protocol::report($boundary, $accounts) === ['messages' => [], 'ignored_count' => 1], 'bounded notice and native digit timestamp accepted');
    foreach ([null, false, 1, [], '', '   ', "\xff", str_repeat('a', 100001), "NUL\0", "CONTROL\x01", "DELETE\x7f"] as $value) {
        $bad = $payload; $bad['entry'][0]['messaging'][0]['message']['admin_text'] = $value;
        rejected($bad, 'MESSAGE', 'malformed administrative text rejected');
    }
    foreach ([['id' => 900], ['id' => '0'], ['id' => '0900'], [], null] as $sender) {
        $bad = $payload; $bad['entry'][0]['messaging'][0]['sender'] = $sender;
        rejected($bad, 'MESSAGE', 'administrative sender retains native ID validation');
    }
    $bad = $payload; $bad['entry'][0]['messaging'][0]['recipient']['id'] = 101;
    rejected($bad, 'MESSAGE', 'administrative recipient cannot be coerced');
    foreach ([null, 1.2, '1.2', '-1', []] as $stamp) {
        $bad = $payload; $bad['entry'][0]['messaging'][0]['timestamp'] = $stamp;
        rejected($bad, 'MESSAGE', 'administrative timestamp type cannot be coerced');
    }
    foreach ([946684799000, (time() + 86401) * 1000, -1] as $stamp) {
        $bad = $payload; $bad['entry'][0]['messaging'][0]['timestamp'] = $stamp;
        rejected($bad, 'TIME', 'administrative timestamp outside native bounds');
    }
    $bad = $payload; $bad['entry'][0]['messaging'][0]['recipient']['id'] = '303';
    rejected($bad, 'ROUTING', 'foreign administrative route rejected');
    $bad = $payload; $bad['entry'][0]['messaging'][0]['sender']['id'] = '101';
    rejected($bad, 'ROUTING', 'administrative self route rejected');
    foreach ([['text' => 'customer text'], ['attachments' => []], ['attachments' => [['type' => 'image']]],
        ['mid' => null], ['mid' => ''], ['mid' => "MID\0"], ['mid' => str_repeat('m', 513)], ['is_echo' => true], ['unknown' => 'metadata']] as $extra) {
        $bad = $payload; $bad['entry'][0]['messaging'][0]['message'] += $extra;
        rejected($bad, 'MESSAGE', 'mixed unkeyed or malformed native messages cannot be hidden as administrative');
    }
    $valid = native('native-incoming', 'CUSTOMER_TEXT');
    $dto = Protocol::report($valid, $accounts);
    check($dto['ignored_count'] === 0 && count($dto['messages']) === 1 && $dto['messages'][0]['message_id'] === 'native-incoming'
        && $dto['messages'][0]['direction'] === 'inbound' && $dto['messages'][0]['text'] === 'CUSTOMER_TEXT', 'native incoming message with admin metadata remains native');
    $echo = native('native-echo', 'BUSINESS_TEXT', '901', true);
    $dto = Protocol::report($echo, $accounts)['messages'][0];
    check($dto['direction'] === 'outbound' && $dto['source'] === 'message_echoes' && $dto['message_id'] === 'native-echo', 'native echo with admin metadata remains native');
    $image = notice('instagram', '202');
    $image['entry'][0]['messaging'][0]['message'] += ['mid' => 'native-image', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://example.com/native-image']]]];
    $dto = Protocol::report($image, $accounts)['messages'][0];
    check($dto['type'] === 'image' && $dto['channel'] === 'instagram' && $dto['message_id'] === 'native-image', 'native Instagram attachment with administrative metadata stays visible');
    $bad = $valid; $bad['entry'][0]['messaging'][0]['message']['is_echo'] = true;
    rejected($bad, 'ROUTING', 'native echo routing guard remains');
    $bad = $valid; unset($bad['entry'][0]['messaging'][0]['message']['admin_text']);
    $bad['entry'][0]['messaging'][0]['message']['mid'] = "MID\x01";
    rejected($bad, 'MESSAGE', 'native mid controls remain rejected');

    $consumer = new Consumer; $eventId = capture($payload); $result = $consumer->consume();
    check($result['events_processed'] === 1 && $result['messages_inserted'] === 0 && $result['quarantined_events'] === 0 && $result['errors'] === 0, 'administrative capture processes without creating a message');
    check(DB::table('meta_inbox_conversations')->count() === 0 && DB::table('meta_inbox_messages')->count() === 0, 'administrative capture never creates a customer conversation');
    $capture = DB::table('meta_webhook_events')->where('id', $eventId)->first();
    check($capture->processed_at !== null && json_decode(Crypt::decryptString($capture->payload), true, 64, JSON_THROW_ON_ERROR) === $payload, 'encrypted raw administrative capture preserved');
    check(strpos($capture->payload, 'PRIVATE_ADMIN_NOTICE') === false, 'administrative capture remains encrypted');
    $actor = new \App\Models\User; $reads = new \App\Services\Dashboard\MetaInboxReadState;
    check($reads->unread($actor)['total_unread'] === 0, 'administrative capture never creates unread evidence');
    $calls = 0;
    $replies = new \App\Services\Dashboard\MetaReplyService(function () use (&$calls) { $calls++; throw new \RuntimeException('UNEXPECTED_NETWORK'); });
    capture($echo); check($consumer->consume()['messages_inserted'] === 1, 'native echo projected normally');
    $thread = DB::table('meta_inbox_conversations')->where('waba_id', 'messenger')->first();
    $before = (array) $thread;
    capture(notice('page', '101', '901')); check($consumer->consume()['messages_inserted'] === 0, 'administrative notice for an existing native peer creates no message');
    check((array) DB::table('meta_inbox_conversations')->where('id', $thread->id)->first() === $before, 'administrative notice preserves customer and conversation chronology');
    $state = $replies->state($actor, (int) $thread->id);
    check(!$state['can_reply'] && $state['reason'] === 'NO_INBOUND' && $state['latest_inbound_id'] === null, 'administrative inbound cannot open an echo-only reply window');
    check($reads->unread($actor)['total_unread'] === 0 && $calls === 0, 'administrative handling causes no unread or network side effect');
    $expired = native('expired-native', 'OLD_CUSTOMER_TEXT', '901');
    $expired['entry'][0]['messaging'][0]['timestamp'] = (time() - 172800) * 1000;
    capture($expired); check($consumer->consume()['messages_inserted'] === 1, 'old real incoming message remains projected');
    $before = (array) DB::table('meta_inbox_conversations')->where('id', $thread->id)->first();
    capture(notice('page', '101', '901', false, 'standby')); check($consumer->consume()['messages_inserted'] === 0, 'standby administrative notice remains non-message');
    check((array) DB::table('meta_inbox_conversations')->where('id', $thread->id)->first() === $before, 'standby administrative notice leaves real message chronology intact');
    $state = $replies->state($actor, (int) $thread->id);
    check(!$state['can_reply'] && $state['reason'] === 'WINDOW_CLOSED', 'administrative notice cannot refresh an expired native reply window');
    check($reads->unread($actor)['total_unread'] === 1, 'unread counts only the real incoming message');
    $workflow = new \App\Services\Dashboard\MetaOrderWorkflow;
    $snapshot = new \ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderWorkflow::class, 'snapshot'); $snapshot->setAccessible(true);
    $transcript = $snapshot->invoke($workflow, (int) $thread->id, 0, PHP_INT_MAX, false)['transcript'];
    check(count($transcript) === 2 && strpos(json_encode($transcript), 'PRIVATE_ADMIN_NOTICE') === false, 'administrative notice never enters grounded order transcript');

    $combined = notice('page', '101', '902');
    $combined['entry'][0]['messaging'][] = native('fresh-native', 'NEW_CUSTOMER_TEXT', '902')['entry'][0]['messaging'][0];
    $report = Protocol::report($combined, $accounts);
    check($report['ignored_count'] === 1 && count($report['messages']) === 1, 'administrative and genuine native events can share an envelope');
    capture($combined); check($consumer->consume()['messages_inserted'] === 1, 'native event in administrative envelope still projects');
    $freshThread = DB::table('meta_inbox_conversations')->where('waba_id', 'messenger')->orderByDesc('id')->first();
    check($replies->state($actor, (int) $freshThread->id)['can_reply'], 'fresh native incoming alone opens reply window');
    $transcript = $snapshot->invoke($workflow, (int) $freshThread->id, 0, PHP_INT_MAX, false)['transcript'];
    check(count($transcript) === 1 && $transcript[0]['speaker'] === 'customer' && $transcript[0]['text'] === 'NEW_CUSTOMER_TEXT', 'order evidence uses only the genuine native event');
    $bad = notice('page', '101', '903');
    $bad['entry'][0]['messaging'][0]['message']['text'] = 'UNKEYED_CUSTOMER_TEXT';
    $bad['entry'][0]['messaging'][] = native('native-beside-malformed', 'REAL_TEXT', '903')['entry'][0]['messaging'][0];
    $before = DB::table('meta_inbox_messages')->count(); capture($bad); $result = $consumer->consume();
    check($result['quarantined_events'] === 1 && $result['messages_inserted'] === 0 && $result['errors'] === 0, 'mixed malformed envelope retains transactional quarantine');
    check(DB::table('meta_inbox_messages')->count() === $before, 'genuine event beside malformed mixed notice does not partially project');
    check($consumer->consume()['events_processed'] === 0 && $calls === 0, 'quarantined malformed notice is not retried and causes no network');
    echo "meta-admin-notice: $checks checks passed\n";
}
