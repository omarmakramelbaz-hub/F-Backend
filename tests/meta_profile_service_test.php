<?php
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload .= '/autoload.php';
    require $autoload;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function now($tz = null) { return \Carbon\Carbon::now($tz); }
    set_error_handler(function ($s, $m, $f, $l) { if ($s & E_ALL) throw new \ErrorException($m, 0, $s, $f, $l); return false; });
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
    use App\Services\Dashboard\MetaProfileService as Profiles;
    $c = new Container; Container::setInstance($c); $db = new Manager($c);
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $db->setEventDispatcher(new Dispatcher($c)); $db->setAsGlobal(); $db->bootEloquent(); $key = random_bytes(32);
    $accounts = [
        ['channel' => 'messenger', 'account_id' => '101', 'access_token' => str_repeat('a', 24)],
        ['channel' => 'instagram', 'account_id' => '202', 'access_token' => str_repeat('b', 24), 'login' => 'instagram'],
        ['channel' => 'instagram', 'account_id' => '303', 'access_token' => str_repeat('c', 24), 'login' => 'facebook'],
    ];
    $c->instance('config', new Repository(['app' => ['key' => 'base64:' . base64_encode($key)],
        'meta_messaging' => ['accounts' => $accounts, 'api_version' => 'v25.0'],
        'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]]]));
    $c->instance('db', $db->getDatabaseManager()); $c->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); Facade::setFacadeApplication($c);
    foreach (['Support/MetaMessagingProtocol', 'Services/Dashboard/WhatsAppInboxAccess', 'Services/Dashboard/MetaInboxAccess',
        'Services/Dashboard/MetaProfileService'] as $file) require __DIR__ . '/../app/' . $file . '.php';
    require __DIR__ . '/../database/migrations/2026_10_11_160000_create_meta_webhook_events_table.php';
    require __DIR__ . '/../database/migrations/2026_10_11_160001_create_meta_inbox_tables.php';
    (new \CreateMetaWebhookEventsTable)->up(); (new \CreateMetaInboxTables)->up();
    $checks = 0;
    function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function customer(int $id): array { return json_decode(Crypt::decryptString(DB::table('meta_inbox_conversations')->where('id', $id)->value('customer')), true, 16, JSON_THROW_ON_ERROR); }
    function saveCustomer(int $id, array $customer): void { DB::table('meta_inbox_conversations')->where('id', $id)->update(['customer' => Crypt::encryptString(json_encode($customer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))]); }
    function thread(string $channel, string $account, string $peer, array $extra = []): int {
        $scope = $channel . ':' . $account . ':';
        return (int) DB::table('meta_inbox_conversations')->insertGetId([
            'waba_id' => $channel, 'phone_number_id' => $account,
            'peer_hash' => hash_hmac('sha256', 'whatsapp-peer-v1:' . $scope . 'user:' . $peer, Crypt::getKey()),
            'customer' => Crypt::encryptString(json_encode($extra + ['name' => null, 'phone' => null, 'user_id' => $peer], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'first_message_at' => now('UTC'), 'last_message_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    function resetThreads(): void { DB::table('meta_inbox_conversations')->delete(); }
    $messenger = thread('messenger', '101', '900', ['phone' => '201000000000', 'order_contact' => ['name' => 'Order name']]);
    $ig = thread('instagram', '202', '900'); $igFb = thread('instagram', '303', '900');
    $known = thread('messenger', '101', '901', ['name' => 'اسم محفوظ']);
    $foreign = thread('messenger', '999', '900');
    $badHash = thread('messenger', '101', '902'); DB::table('meta_inbox_conversations')->where('id', $badHash)->update(['peer_hash' => str_repeat('0', 64)]);
    $calls = [];
    $service = new Profiles(function ($endpoint, $token) use (&$calls, $messenger) {
        check(DB::connection()->transactionLevel() === 0, 'network outside transaction');
        check(strpos($endpoint, 'access_token') === false && strpos($endpoint, $token) === false, 'token outside URL');
        $calls[] = $endpoint;
        if ($token === str_repeat('a', 24)) {
            check($endpoint === 'https://graph.facebook.com/v25.0/900?fields=id%2Cfirst_name%2Clast_name', 'Messenger native profile endpoint');
            $saved = customer($messenger); $saved['additional_contact'] = 'Preserve concurrent metadata'; saveCustomer($messenger, $saved);
            return ['status' => 200, 'body' => json_encode(['id' => '900', 'first_name' => 'أحمد', 'last_name' => 'محمد'])];
        }
        if ($token === str_repeat('b', 24)) {
            check($endpoint === 'https://graph.instagram.com/v25.0/900?fields=id%2Cname%2Cusername', 'Instagram Login profile host');
            return ['status' => 200, 'body' => json_encode(['id' => '900', 'name' => 'منى', 'username' => 'mona'])];
        }
        check($token === str_repeat('c', 24) && $endpoint === 'https://graph.facebook.com/v25.0/900?fields=id%2Cname%2Cusername', 'Facebook Login Instagram profile host and token');
        return ['status' => 200, 'body' => json_encode(['id' => '900', 'name' => '', 'username' => 'insta.customer'])];
    });
    $result = $service->refresh();
    check($result['attempted'] === 3 && $result['updated'] === 3 && count($calls) === 3, 'bounded configured profiles only');
    check(customer($messenger)['name'] === 'أحمد محمد' && customer($ig)['name'] === 'منى' && customer($igFb)['name'] === '@insta.customer', 'native display names and username fallback');
    check(customer($messenger)['phone'] === '201000000000' && customer($messenger)['order_contact']['name'] === 'Order name'
        && customer($messenger)['additional_contact'] === 'Preserve concurrent metadata', 'other customer data survives hydration');
    check(customer($known)['name'] === 'اسم محفوظ' && customer($foreign)['name'] === null && customer($badHash)['name'] === null, 'preserve known names and reject unscoped or corrupt identity');
    check(strpos(DB::table('meta_inbox_conversations')->where('id', $messenger)->value('customer'), 'أحمد') === false, 'name remains encrypted');
    check($service->refresh()['attempted'] === 0 && count($calls) === 3, 'known profiles are not requested again');

    resetThreads(); $mismatch = thread('messenger', '101', '910'); $failedCalls = 0;
    $wrong = new Profiles(function () use (&$failedCalls) { $failedCalls++; return ['status' => 200, 'body' => '{"id":"999","first_name":"Wrong customer"}']; });
    check($wrong->refresh()['failed'] === 1 && customer($mismatch)['name'] === null && customer($mismatch)['meta_profile']['reason'] === 'ID_MISMATCH', 'returned identity mismatch cannot name customer');
    check($wrong->refresh()['attempted'] === 0 && $failedCalls === 1, 'failed profile is throttled');
    $future = customer($mismatch)['meta_profile']['next_attempt_at']; check($future > gmdate('Y-m-d H:i:s', time() + 3500), 'retry delayed at least an hour');
    config()->set('meta_messaging.accounts.0.access_token', str_repeat('d', 24));
    check($wrong->refresh()['attempted'] === 1 && $failedCalls === 2, 'credential change retries old failure');
    config()->set('meta_messaging.accounts', $accounts);

    resetThreads(); $concurrent = thread('messenger', '101', '920'); $recursiveCalls = 0;
    $recursive = new Profiles(function () use (&$recursiveCalls) { $recursiveCalls++; return ['status' => 200, 'body' => '{"id":"920","first_name":"Nested"}']; });
    $outer = new Profiles(function () use ($recursive, $concurrent) {
        check($recursive->refresh()['attempted'] === 0, 'leased peer cannot be looked up concurrently');
        $saved = customer($concurrent); $saved['name'] = 'A manually saved name'; saveCustomer($concurrent, $saved);
        return ['status' => 200, 'body' => '{"id":"920","first_name":"Remote name"}'];
    });
    $outer->refresh(); check($recursiveCalls === 0 && customer($concurrent)['name'] === 'A manually saved name', 'concurrent known name wins');

    resetThreads(); $revoked = thread('messenger', '101', '930');
    $revoking = new Profiles(function () { config()->set('meta_messaging.accounts', []); return ['status' => 200, 'body' => '{"id":"930","first_name":"Revoked"}']; });
    check($revoking->refresh()['updated'] === 0 && customer($revoked)['name'] === null, 'revoked account cannot apply in-flight profile');
    config()->set('meta_messaging.accounts', $accounts);
    resetThreads(); $rotated = thread('messenger', '101', '931');
    $rotating = new Profiles(function () { config()->set('meta_messaging.api_version', 'v26.0'); return ['status' => 200, 'body' => '{"id":"931","first_name":"Old version"}']; });
    check($rotating->refresh()['updated'] === 0 && customer($rotated)['name'] === null, 'configuration changes invalidate in-flight result');
    config()->set('meta_messaging.api_version', 'v25.0');

    resetThreads(); $changing = thread('messenger', '101', '940');
    $identityChanging = new Profiles(function () use ($changing) {
        $saved = customer($changing); $saved['user_id'] = '941'; saveCustomer($changing, $saved);
        DB::table('meta_inbox_conversations')->where('id', $changing)->update(['peer_hash' => hash_hmac('sha256', 'whatsapp-peer-v1:messenger:101:user:941', Crypt::getKey())]);
        return ['status' => 200, 'body' => '{"id":"940","first_name":"Previous peer"}'];
    });
    check($identityChanging->refresh()['updated'] === 0 && customer($changing)['name'] === null, 'changed thread identity cannot consume old result');

    resetThreads(); $timeout = thread('messenger', '101', '950'); $timeoutCalls = 0;
    $throwing = new Profiles(function () use (&$timeoutCalls) { $timeoutCalls++; throw new \RuntimeException('Simulated transport exception'); });
    check($throwing->refresh()['failed'] === 1 && customer($timeout)['meta_profile']['state'] === 'RETRY', 'transport failure isolated and durable');
    check($throwing->refresh()['attempted'] === 0 && $timeoutCalls === 1, 'transport exception respects throttle');

    resetThreads(); thread('messenger', '101', '960');
    $invalid = new Profiles(function () { return ['status' => 200, 'body' => '{"id":"960","first_name":"Bad\\nName"}']; });
    check($invalid->refresh()['updated'] === 0 && DB::table('meta_inbox_messages')->count() === 0, 'invalid name neither stored nor turned into message');
    resetThreads(); thread('messenger', '101', '970');
    $rejected = new Profiles(function () { return ['status' => 403, 'body' => '{"error":{"message":"Do not record raw details"}}']; });
    check($rejected->refresh()['failed'] === 1 && customer((int) DB::table('meta_inbox_conversations')->value('id'))['meta_profile']['reason'] === 'META_REJECTED', 'Graph failures store fixed code only');

    resetThreads();
    for ($i = 0; $i < 205; $i++) thread('messenger', '101', (string) (1000 + $i), ['name' => 'Known']);
    for ($i = 0; $i < 12; $i++) thread('messenger', '101', (string) (2000 + $i));
    DB::table('meta_inbox_conversations')->update(['updated_at' => now('UTC')->subDay()]);
    $boundedCalls = 0;
    $bounded = new Profiles(function ($endpoint) use (&$boundedCalls) {
        $boundedCalls++; preg_match('~/([0-9]+)\?~', $endpoint, $match);
        return ['status' => 200, 'body' => json_encode(['id' => $match[1], 'first_name' => 'Backfilled'])];
    });
    $result = $bounded->refresh(10);
    check($boundedCalls === 0 && $result['scanned'] === 200, 'scan bounded even when early peers already named');
    $result = $bounded->refresh(10);
    check($boundedCalls === 10 && $result['updated'] === 10, 'rotating known-name scan does not starve later peers');
    check($bounded->refresh(10)['updated'] === 2 && $boundedCalls === 12, 'backfill advances across completed and skipped peers');
    foreach ([0, 11] as $limit) { try { $bounded->refresh($limit); check(false, 'invalid limit'); } catch (\InvalidArgumentException $e) { check(true, 'profile network batch limited to ten'); } }
    check(DB::table('meta_inbox_messages')->count() === 0 && DB::table('meta_webhook_events')->count() === 0, 'profile enrichment writes no history');
    config()->set('desktop_dashboard.local', true);
    check($bounded->refresh()['unavailable'] === 1, 'no profile network in offline desktop mode');
    echo "meta-profile-service: $checks checks passed\n";
}
