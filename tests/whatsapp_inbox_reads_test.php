<?php

namespace {
    // Only temporary CI dependencies and an in-memory SQLite database are used.
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    if (!is_string($autoload) || !is_file($autoload) || !extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "The read-state fixture needs WA_INBOX_TEST_VENDOR and pdo_sqlite.\n");
        exit(1);
    }
    require $autoload;
}

namespace App\Models {
    class User extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'users';
        protected $guarded = [];
    }
}

namespace App\Services\Dashboard {
    // Authorization port mirrors the deployed service's fresh persisted identity contract.
    // Actual WhatsAppInboxAccess below provides the central-role and branch-owner restriction.
    class TakeawayAccess {
        public function actor($actor): \App\Models\User {
            $fresh = $actor && $actor->id ? \App\Models\User::withoutGlobalScopes()->find($actor->id) : null;
            \abort_unless($fresh && in_array($fresh->account_type, ['admin', 'vendor', 'resturant_owner', 'delegate'], true), 403);
            return $fresh;
        }
    }
}

namespace {
    use App\Models\User;
    use App\Services\Dashboard\WhatsAppInboxAccess;
    use App\Services\Dashboard\WhatsAppInboxReadState;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    if (!function_exists('app')) {
        function app($key = null) { return $key === null ? Container::getInstance() : Container::getInstance()->make($key); }
    }
    if (!function_exists('config')) {
        function config($key = null, $default = null) { return app('config')->get($key, $default); }
    }
    if (!function_exists('now')) {
        function now($timezone = null) { return \Carbon\Carbon::now($timezone); }
    }
    if (!function_exists('abort_unless')) {
        function abort_unless($condition, $status, $message = '') { if (!$condition) throw new HttpException($status, $message); }
    }

    $container = new class extends Container {
        public function abort($status, $message = '', array $headers = []) { throw new HttpException($status, $message, null, $headers); }
    };
    Container::setInstance($container);
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $capsule->setEventDispatcher(new Dispatcher($container)); $capsule->setAsGlobal(); $capsule->bootEloquent();
    $container->instance('config', new Repository(['desktop_dashboard' => ['local' => false],
        'database' => ['default' => 'default', 'connections' => ['default' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]]]));
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
    Facade::setFacadeApplication($container);
    require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxAccess.php';
    require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxReadState.php';
    require __DIR__ . '/../database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php';
    require __DIR__ . '/../database/migrations/2026_10_10_000004_create_whatsapp_inbox_reads.php';
    Schema::create('users', function (Blueprint $table) {
        $table->bigIncrements('id'); $table->string('account_type'); $table->unsignedBigInteger('owner_resturant_id')->nullable();
        $table->boolean('enabled')->default(true); $table->timestamps();
    });
    Schema::create('whatsapp_webhook_events', function (Blueprint $table) { $table->bigIncrements('id'); });
    (new CreateWhatsAppInboxTables())->up(); (new CreateWhatsAppInboxReads())->up();
    DB::statement('PRAGMA foreign_keys = ON');
    foreach ([[1, 'admin', null], [2, 'admin', null], [3, 'vendor', null], [4, 'admin', 11]] as [$id, $role, $owner]) {
        DB::table('users')->insert(['id' => $id, 'account_type' => $role, 'owner_resturant_id' => $owner, 'enabled' => true]);
    }
    function checkRead(bool $condition, string $label): void {
        if (!$condition) throw new \RuntimeException('Read-state assertion failed: ' . $label);
    }
    function expectReadStatus(callable $call, int $status, string $label): void {
        try { $call(); } catch (HttpException $error) { checkRead($error->getStatusCode() === $status, $label); return; }
        throw new \RuntimeException('Expected denied read-state operation: ' . $label);
    }
    function seedReadThread(string $waba = WhatsAppInboxAccess::WABA_ID, string $phone = WhatsAppInboxAccess::PHONE_ID): int {
        return DB::table('whatsapp_inbox_conversations')->insertGetId(['waba_id' => $waba, 'phone_number_id' => $phone,
            'peer_hash' => hash('sha256', random_bytes(16)), 'customer' => 'Unread fixture ciphertext',
            'first_message_at' => '2026-10-10 00:00:00', 'last_message_at' => '2026-10-10 00:00:00',
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    function seedReadMessage(int $thread, string $direction = 'inbound'): int {
        return DB::table('whatsapp_inbox_messages')->insertGetId(['conversation_id' => $thread,
            'message_key' => hash('sha256', random_bytes(16)), 'direction' => $direction, 'type' => 'text', 'source' => 'messages',
            'content' => 'Private ciphertext must not be returned', 'sent_at' => '2026-10-10 00:00:00',
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }
    $reads = new WhatsAppInboxReadState(); $one = User::find(1); $two = User::find(2); $tests = 0;
    $thread = seedReadThread(); $other = seedReadThread();
    $foreign = seedReadThread('1636131124838697'); $otherPhone = seedReadThread(WhatsAppInboxAccess::WABA_ID, '900000000000000');
    $outboundOnly = seedReadThread();
    $first = seedReadMessage($thread); $out = seedReadMessage($thread, 'outbound'); $latest = seedReadMessage($thread);
    $otherMessage = seedReadMessage($other); $foreignMessage = seedReadMessage($foreign); seedReadMessage($otherPhone); seedReadMessage($outboundOnly, 'outbound');
    $state = $reads->unread($one); $items = array_column($state['conversations'], null, 'id');
    checkRead($reads->available() && $state['total_unread'] === 3 && $items[$thread]['unread_count'] === 2
        && $items[$thread]['latest_inbound_id'] === $latest && $items[$outboundOnly]['unread_count'] === 0
        && !isset($items[$foreign]) && !isset($items[$otherPhone]), 'scoped inbound count and actual migration'); $tests++;
    checkRead(strpos(json_encode($state), 'ciphertext') === false && strpos(json_encode($state), 'customer') === false,
        'numeric counts only, no customer or content'); $tests++;
    $reads->markRead($thread, $first, $one);
    checkRead($reads->unread($one, [$thread])['conversations'][0]['unread_count'] === 1
        && $reads->unread($two, [$thread])['conversations'][0]['unread_count'] === 2
        && $reads->unread($one, [$thread])['total_unread'] === 2, 'markers belong to each actor and total covers unselected threads'); $tests++;
    $reads->markRead($thread, $latest, $one);
    $old = $reads->markRead($thread, $first, $one);
    checkRead($old['seen_message_id'] === $latest && $reads->unread($one, [$thread])['conversations'][0]['unread_count'] === 0,
        'stale acknowledgement cannot decrease marker'); $tests++;
    $new = seedReadMessage($thread);
    checkRead($reads->unread($one, [$thread])['conversations'][0]['unread_count'] === 1
        && $reads->unread($one, [$thread])['conversations'][0]['latest_inbound_id'] === $new,
        'message arriving after displayed page remains unread'); $tests++;
    $marker = DB::table('whatsapp_inbox_reads')->where('actor_id', 1)->where('conversation_id', $thread)->value('seen_message_id');
    foreach ([[$out, 422], [$otherMessage, 422], [PHP_INT_MAX, 422], [0, 422], [null, 422]] as [$id, $status]) {
        expectReadStatus(fn () => $reads->markRead($thread, $id, $one), $status, 'invalid or undisplayed inbound acknowledgement');
    }
    checkRead((int) DB::table('whatsapp_inbox_reads')->where('actor_id', 1)->where('conversation_id', $thread)->value('seen_message_id') === (int) $marker,
        'invalid acknowledgement never advances marker'); $tests++;
    expectReadStatus(fn () => $reads->markRead($foreign, $foreignMessage, $one), 404, 'test WABA cannot be acknowledged');
    checkRead($reads->unread($one, [$foreign])['conversations'] === [], 'foreign IDs cannot expose counts'); $tests++;
    foreach ([3, 4] as $id) {
        $forged = new User(['id' => $id, 'account_type' => 'admin', 'owner_resturant_id' => null]);
        expectReadStatus(fn () => $reads->unread($forged), 403, 'selected role cannot grant central inbox');
        expectReadStatus(fn () => $reads->markRead($thread, $new, $forged), 403, 'selected role cannot mark private thread');
    }
    $tests++;
    DB::table('users')->where('id', 1)->update(['account_type' => 'vendor']);
    expectReadStatus(fn () => $reads->markRead($thread, $new, $one), 403, 'persisted revocation reread');
    expectReadStatus(fn () => $reads->unread($one), 403, 'persisted revocation on counts'); $tests++;
    expectReadStatus(fn () => $reads->unread($two, array_fill(0, 101, $thread)), 422, 'explicit IDs bounded');
    expectReadStatus(fn () => $reads->unread($two, ['1.5']), 422, 'IDs are exact integers');
    checkRead($reads->unread($two, [])['conversations'] === [] && $reads->unread($two, [])['total_unread'] === 4,
        'empty selection keeps global aggregate without loading histories'); $tests++;
    $duplicateBlocked = false;
    try { DB::table('whatsapp_inbox_reads')->insert(['actor_id' => 1, 'conversation_id' => $thread, 'seen_message_id' => 0]); }
    catch (\Illuminate\Database\QueryException $error) { $duplicateBlocked = true; }
    checkRead($duplicateBlocked && DB::table('whatsapp_inbox_reads')->count() === 1, 'actual composite uniqueness'); $tests++;
    $batch = [];
    for ($i = 0; $i < 1001; $i++) {
        $batch[] = ['waba_id' => WhatsAppInboxAccess::WABA_ID, 'phone_number_id' => WhatsAppInboxAccess::PHONE_ID,
            'peer_hash' => hash('sha256', 'bounded-read-fixture-' . $i), 'customer' => 'Never exposed',
            'first_message_at' => '2026-10-10 01:00:00', 'last_message_at' => '2026-10-10 01:00:00',
            'created_at' => now('UTC'), 'updated_at' => now('UTC')];
    }
    foreach (array_chunk($batch, 100) as $chunk) DB::table('whatsapp_inbox_conversations')->insert($chunk);
    $bounded = $reads->unread($two);
    checkRead(count($bounded['conversations']) === 1000 && $bounded['has_more'] === true
        && $bounded['total_unread'] === 4 && !in_array($thread, array_column($bounded['conversations'], 'id'), true),
        'default reply is bounded while total includes older unread thread'); $tests++;
    echo 'WHATSAPP_INBOX_READS_TESTS=' . $tests . "\n";
}
