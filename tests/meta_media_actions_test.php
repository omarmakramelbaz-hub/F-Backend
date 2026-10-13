<?php
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload .= '/autoload.php';
    require $autoload;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($name = null, $default = null) { return $name === null ? app('config') : app('config')->get($name, $default); }
    function now($tz = null) { return \Carbon\Carbon::now($tz); }
    function abort_unless($ok, $status, $message = '') { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_if($ok, $status, $message = '') { abort_unless(!$ok, $status, $message); }
    function auth($guard = null) { return new class { public function user() { return $GLOBALS['media_actor']; } }; }
    function response($body = null, $status = 200) {
        if (func_num_args()) return new \Illuminate\Http\Response($body, $status);
        return new class {
            public function json($value, $status = 200) { return new \Illuminate\Http\JsonResponse($value, $status); }
        };
    }
    set_error_handler(function ($s, $m, $f, $l) { if ($s & E_ALL) throw new \ErrorException($m, 0, $s, $f, $l); return false; });
}
namespace App\Models { class User extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; protected $guarded = []; } }
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor): \App\Models\User {
            $fresh = $actor && $actor->id ? \App\Models\User::find($actor->id) : null;
            \abort_unless($fresh && $fresh->enabled, 403); return $fresh;
        }
    }
}
namespace App\Http\Controllers { class Controller extends \Illuminate\Routing\Controller {} }
namespace {
    use App\Models\User;
    use App\Services\Dashboard\MetaInboxAccess;
    use App\Services\Dashboard\MetaMediaService;
    use App\Services\Dashboard\MetaInboxReadState;
    use App\Http\Controllers\Dashboard\MetaInboxController;
    use App\Http\Controllers\Dashboard\MetaMessageActionsController;
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    $c = new class extends Container { public function getLocale() { return 'ar'; } };
    Container::setInstance($c); $db = new Manager($c);
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $db->setEventDispatcher(new Dispatcher($c)); $db->setAsGlobal(); $db->bootEloquent(); $key = random_bytes(32);
    $accounts = [['channel' => 'messenger', 'account_id' => '101'], ['channel' => 'instagram', 'account_id' => '202']];
    $c->instance('config', new Repository(['meta_messaging' => ['accounts' => $accounts],
        'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]]]));
    $c->instance('db', $db->getDatabaseManager()); $c->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); Facade::setFacadeApplication($c);
    foreach (['Support/WhatsAppWebhookProtocol', 'Support/WhatsAppInboxProtocol', 'Support/MetaMessagingProtocol',
        'Services/Dashboard/WhatsAppInboxAccess', 'Services/Dashboard/MetaInboxAccess', 'Services/Dashboard/MetaMediaService',
        'Services/Dashboard/MetaInvalidEvent', 'Services/Dashboard/MetaInboxConsumer', 'Services/Dashboard/MetaInboxReadState',
        'Http/Controllers/Dashboard/MetaInboxController', 'Http/Controllers/Dashboard/MetaMessageActionsController'] as $file) require __DIR__ . '/../app/' . $file . '.php';
    foreach (['2026_10_11_160000_create_meta_webhook_events_table', '2026_10_11_160001_create_meta_inbox_tables',
        '2026_10_11_160004_create_meta_inbox_reads', '2026_10_13_160000_create_meta_message_hides'] as $file) require __DIR__ . '/../database/migrations/' . $file . '.php';
    foreach (['CreateMetaWebhookEventsTable', 'CreateMetaInboxTables', 'CreateMetaInboxReads', 'CreateMetaMessageHides'] as $class) (new $class)->up();
    Schema::create('users', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->boolean('enabled')->default(true); });
    foreach ([[1, 'admin', null], [2, 'admin', null], [3, 'vendor', null], [4, 'admin', 17]] as [$id, $role, $branch]) {
        DB::table('users')->insert(['id' => $id, 'account_type' => $role, 'owner_resturant_id' => $branch, 'enabled' => true]);
    }
    $media_actor = User::find(1); $one = $media_actor; $two = User::find(2); $checks = 0;
    function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function denied(callable $callback, int $status, string $label) {
        try { $callback(); check(false, $label); } catch (HttpException $error) { check($error->getStatusCode() === $status, $label); }
    }
    function thread(string $channel = 'messenger', string $account = '101', string $peer = '900'): int {
        return (int) DB::table('meta_inbox_conversations')->insertGetId(['waba_id' => $channel, 'phone_number_id' => $account,
            'peer_hash' => hash_hmac('sha256', 'whatsapp-peer-v1:' . $channel . ':' . $account . ':user:' . $peer, Crypt::getKey()),
            'customer' => Crypt::encryptString(json_encode(['user_id' => $peer, 'name' => 'عميل', 'phone' => null])),
            'first_message_at' => now('UTC'), 'last_message_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    function message(int $thread, array $attachments, string $type = 'image'): int {
        $row = DB::table('meta_inbox_conversations')->where('id', $thread)->first();
        $customer = json_decode(Crypt::decryptString($row->customer), true); $mid = 'message-' . bin2hex(random_bytes(8));
        $sent = now('UTC')->format('Y-m-d H:i:s');
        $dto = ['channel' => $row->waba_id, 'account_id' => $row->phone_number_id, 'peer_identity' => 'user:' . $customer['user_id'],
            'peer_user_id' => $customer['user_id'], 'message_id' => $mid, 'type' => $type, 'direction' => 'inbound',
            'text' => $type === 'text' ? 'رسالة العميل الأصلية' : null, 'sent_at' => $sent,
            'content' => ['message' => ['id' => $mid, 'meta' => ['mid' => $mid, 'attachments' => $attachments]]]];
        return (int) DB::table('meta_inbox_messages')->insertGetId(['conversation_id' => $thread,
            'message_key' => hash('sha256', 'whatsapp-message-v1:' . $row->waba_id . ':' . $row->phone_number_id . ':' . $mid),
            'type' => $type, 'direction' => 'inbound', 'source' => 'messages', 'content' => Crypt::encryptString(json_encode($dto)),
            'sent_at' => $sent, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    $media = new MetaMediaService;
    foreach (['https://scontent.fbcdn.net/file?signature=private', 'https://lookaside.fbsbx.com/attachment', 'https://scontent.cdninstagram.com/photo'] as $url) check($media->safeUrl($url), 'approved HTTPS CDN host');
    foreach (['http://scontent.fbcdn.net/photo', 'https://fbcdn.net.evil.test/x', 'https://evilfbcdn.net/x', 'https://evil.test/x',
        'https://127.0.0.1/x', 'https://localhost/x', 'https://user:pass@scontent.fbcdn.net/x', 'https://scontent.fbcdn.net:444/x',
        'https://scontent.fbcdn.net/x#fragment', "https://scontent.fbcdn.net/x\n", 'https://scontent.fbcdn.net\\@evil.test/x'] as $url) check(!$media->safeUrl($url), 'untrusted URL rejected');
    $tid = thread(); $other = thread('instagram', '202'); $foreign = thread('messenger', '999');
    $attachments = [['type' => 'image', 'payload' => ['url' => 'https://scontent.fbcdn.net/photo?signature=private']],
        ['type' => 'audio', 'payload' => ['url' => 'https://lookaside.fbsbx.com/voice']],
        ['type' => 'file', 'payload' => ['url' => 'https://lookaside.fbsbx.com/document']],
        ['type' => 'image', 'payload' => ['url' => 'https://evil.test/private']],
        ['type' => 'location', 'payload' => ['url' => 'https://scontent.fbcdn.net/x']]];
    $mid = message($tid, $attachments); $foreignMid = message($foreign, $attachments);
    $snapshot = $media->message($tid, $mid, $one); $dto = $snapshot['dto'];
    $items = $media->descriptors($snapshot['thread'], $snapshot['message'], $dto);
    check(count($items) === 3 && $items[2]['type'] === 'document' && $items[1]['index'] === 1, 'safe normalized media kinds preserve source index');
    check($items[0]['url'] === '/admin/meta/conversations/' . $tid . '/messages/' . $mid . '/media/0'
        && strpos(json_encode($items), 'signature') === false && strpos(json_encode($items), 'fbcdn') === false, 'browser sees authenticated local URL only');
    check($media->source($tid, $mid, 0, $one)['url'] === $attachments[0]['payload']['url'], 'server source preserves signed CDN URL');
    denied(fn () => $media->source($other, $mid, 0, $one), 404, 'source cannot cross conversation');
    denied(fn () => $media->source($foreign, $foreignMid, 0, $one), 404, 'unconfigured account source denied');
    foreach ([3, 4] as $id) denied(fn () => $media->source($tid, $mid, 0, User::find($id)), 403, 'branch actor denied');
    foreach ([3, 4, 30, -1] as $index) denied(fn () => $media->source($tid, $mid, $index, $one), 404, 'unsupported attachment rejected');
    $broken = clone $snapshot['thread']; $broken->peer_hash = str_repeat('0', 64);
    check($media->descriptors($broken, $snapshot['message'], $dto) === [], 'bad customer HMAC suppressed');
    $wrong = clone $snapshot['message']; $wrong->message_key = str_repeat('0', 64);
    check($media->descriptors($snapshot['thread'], $wrong, $dto) === [], 'wrong source key suppressed');
    $wrongDto = $dto; $wrongDto['peer_identity'] = 'user:901';
    check($media->descriptors($snapshot['thread'], $snapshot['message'], $wrongDto) === [], 'DTO peer mismatch suppressed');

    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='); $calls = [];
    $fetch = new MetaMediaService(function ($url, $limit) use ($gif, &$calls) { $calls[] = [$url, $limit]; return ['status' => 200, 'content_type' => 'image/gif', 'body' => $gif]; });
    $c->instance(MetaMediaService::class, $fetch);
    $actions = new MetaMessageActionsController;
    $response = $actions->media((string) $tid, (string) $mid, '0', $fetch);
    check($response->getStatusCode() === 200 && $response->getContent() === $gif
        && $response->headers->get('content-type') === 'image/gif' && strpos($response->headers->get('content-disposition'), 'inline;') === 0, 'authorized image renders inline');
    check(strpos($response->headers->get('cache-control'), 'no-store') !== false
        && $response->headers->get('x-content-type-options') === 'nosniff'
        && $response->headers->get('referrer-policy') === 'no-referrer'
        && $response->headers->get('content-security-policy') === "default-src 'none'; sandbox", 'customer media private headers');
    check(count($calls) === 1 && $calls[0][1] === MetaMediaService::MAX_BYTES, 'transport only receives bounded source URL without credentials');
    foreach ([[302, 'image/gif', $gif, 502], [200, 'image/gif', '<html>not a picture</html>', 415],
        [200, 'text/plain', $gif, 415], [200, 'image/gif', str_repeat('x', MetaMediaService::MAX_BYTES + 1), 502]] as [$status, $mime, $body, $expected]) {
        $bad = new MetaMediaService(fn () => ['status' => $status, 'content_type' => $mime, 'body' => $body]);
        denied(fn () => $bad->fetch($media->source($tid, $mid, 0, $one)), $expected, 'redirect, mismatch, executable or oversized content rejected');
    }
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    $doc = new MetaMediaService(fn () => ['status' => 200, 'content_type' => 'application/pdf', 'body' => $pdf]);
    $docResponse = $actions->media((string) $tid, (string) $mid, '2', $doc);
    check($docResponse->getStatusCode() === 200 && strpos($docResponse->headers->get('content-disposition'), 'attachment;') === 0, 'documents use controlled download');
    $wav = 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 2) . "\0\0";
    $voice = new MetaMediaService(fn () => ['status' => 200, 'content_type' => 'audio/x-wav', 'body' => $wav]);
    $voiceFile = $voice->fetch($media->source($tid, $mid, 1, $one));
    check($voiceFile['mime'] === 'audio/wav' && $voiceFile['inline'] === true, 'verified WAV MIME aliases accepted');
    $m4a = pack('N', 32) . 'ftypM4A ' . pack('N', 0) . 'M4A mp42isom' . pack('N', 0);
    $voiceM4a = new MetaMediaService(fn () => ['status' => 200, 'content_type' => 'audio/mp4', 'body' => $m4a]);
    check($voiceM4a->fetch($media->source($tid, $mid, 1, $one))['mime'] === 'audio/mp4', 'verified M4A MIME alias accepted');
    // A complete stored ZIP with a 541-byte member also exercises PHP 8.2's buffer
    // MIME detector, which labels the previous tiny archive application/octet-stream.
    $zip = base64_decode('UEsDBBQAAAAAAAAATV1L8F3UHQIAAB0CAAAMAAAAZG9jdW1lbnQudHh0VmVyaWZpZWQgYXR0YWNobWVudCBmaXh0dXJlLiBhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYVBLAQIUAxQAAAAAAAAATV1L8F3UHQIAAB0CAAAMAAAAAAAAAAAAAACAAQAAAABkb2N1bWVudC50eHRQSwUGAAAAAAEAAQA6AAAARwIAAAAA');
    $office = new MetaMediaService(fn () => ['status' => 200, 'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'body' => $zip]);
    $officeFile = $office->fetch($media->source($tid, $mid, 2, $one));
    check($officeFile['mime'] === 'application/zip' && $officeFile['inline'] === false, 'Office claimed subtype served as verified container download');

    $raw = DB::table('meta_inbox_messages')->where('id', $mid)->first(); $reads = new MetaInboxReadState;
    $unreadBefore = $reads->unread($one); $firstHide = $actions->hide((string) $tid, (string) $mid, $media);
    $audit = DB::table('meta_message_hides')->first();
    check($firstHide->getStatusCode() === 200 && $firstHide->getData(true)['hidden'] === true, 'local hide accepted');
    check($actions->hide((string) $tid, (string) $mid, $media)->getStatusCode() === 200
        && DB::table('meta_message_hides')->count() === 1 && DB::table('meta_message_hides')->first() == $audit, 'repeated hide is immutable and idempotent');
    check($media->hiddenIds($one, [$mid]) === [$mid] && $media->hiddenIds($two, [$mid]) === [], 'hide belongs to authenticated actor');
    check($audit->message_key === $raw->message_key && (int) $audit->conversation_id === $tid
        && DB::table('meta_inbox_messages')->where('id', $mid)->first() == $raw
        && $reads->unread($one) === $unreadBefore, 'hide preserves capture, order evidence and read state');
    denied(fn () => $media->source($tid, $mid, 0, $one), 404, 'hidden media cannot be fetched or forwarded by actor');
    check($media->source($tid, $mid, 0, $two)['message_id'] === $mid, 'other central actor retains original media');
    Request::macro('validate', function ($rules) { return $this->all(); });
    $inbox = new MetaInboxController;
    $shown = $inbox->messages(new Request, (string) $tid, new MetaInboxAccess)->getData(true);
    check($shown['messages'][0]['id'] === $mid && $shown['messages'][0]['hidden'] === true
        && $shown['messages'][0]['type'] === 'system' && $shown['messages'][0]['media'] === []
        && $shown['messages'][0]['cart'] === null && $shown['messages'][0]['unavailable'] === false
        && $shown['last_id'] === $mid, 'hidden tombstones preserve polling and read acknowledgement IDs');
    $threads = $inbox->conversations(new Request, new MetaInboxAccess)->getData(true);
    $preview = array_column($threads['conversations'], null, 'id')[$tid];
    check($preview['preview']['hidden'] === true && strpos($preview['preview']['text'], 'حذف') !== false
        && $preview['account_key'] === hash('sha256', 'messenger:101'), 'peractor preview is hidden and account filter exposed');
    $media_actor = $two;
    $shownOther = $inbox->messages(new Request, (string) $tid, new MetaInboxAccess)->getData(true);
    check($shownOther['messages'][0]['hidden'] === false && count($shownOther['messages'][0]['media']) === 3, 'other actor sees original inbox');
    DB::table('users')->where('id', 2)->update(['account_type' => 'vendor']);
    denied(fn () => $media->source($tid, $mid, 0, $two), 403, 'persisted actor revocation immediately applied');
    DB::table('users')->where('id', 2)->update(['account_type' => 'admin']);
    $revoking = new MetaMediaService(function () use ($gif) { config()->set('meta_messaging.accounts', []); return ['status' => 200, 'content_type' => 'image/gif', 'body' => $gif]; });
    check($actions->media((string) $tid, (string) $mid, '0', $revoking)->getStatusCode() === 404, 'account revocation during download withholds bytes');
    config()->set('meta_messaging.accounts', $accounts); config()->set('desktop_dashboard.local', true);
    denied(fn () => $media->source($tid, $mid, 0, $two), 503, 'offline desktop cannot fetch received media');
    echo "meta-media-actions: $checks checks passed\n";
}
