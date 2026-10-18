<?php

// Real SQLite/Illuminate integration in an isolated, in-memory database only.
// CI supplies temporary Composer dependencies through WA_INBOX_TEST_VENDOR.
// No Laravel application bootstrap, production configuration, or network requests.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || $autoload === '' || !is_file($autoload)) {
    fwrite(STDERR, "WA_INBOX_TEST_VENDOR must identify the temporary test autoloader.\n");
    exit(1);
}
require $autoload;
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "The isolated database test requires pdo_sqlite.\n");
    exit(1);
}

use App\Services\Dashboard\WhatsAppInboxConsumer;
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
    function config($key = null, $default = null)
    {
        $repository = Container::getInstance()->make('config');
        if ($key === null) return $repository;
        return $repository->get($key, $default);
    }
}
if (!function_exists('now')) {
    function now($timezone = null) { return \Carbon\Carbon::now($timezone); }
}

$container = new Container();
Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection([
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    'foreign_key_constraints' => true,
]);
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$key = random_bytes(32);
$container->instance('config', new Repository([
    'app' => ['key' => 'base64:' . base64_encode($key), 'cipher' => 'AES-256-CBC'],
    'database' => [
        'default' => 'default',
        'connections' => ['default' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]],
    ],
]));
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter', new Encrypter($key, 'AES-256-CBC'));
unset($key);
Facade::setFacadeApplication($container);

require __DIR__ . '/../app/Support/WhatsAppInboxProtocol.php';
require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php';
require __DIR__ . '/../app/Services/Dashboard/WhatsAppInboxConsumer.php';
require __DIR__ . '/../database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php';

Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('payload_hash', 64)->unique();
    $table->longText('payload');
    $table->timestamp('received_at');
    $table->timestamp('processed_at')->nullable();
});
$migration = new CreateWhatsAppInboxTables();
$migration->up();
DB::statement('PRAGMA foreign_keys = ON');

function dbCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Isolated database assertion failed: ' . $label);
}
function dbReset(): void
{
    DB::transaction(function () {
        foreach (['whatsapp_inbox_ingestion_failures', 'whatsapp_inbox_messages',
            'whatsapp_inbox_conversations', 'whatsapp_webhook_events'] as $table) {
            DB::table($table)->delete();
        }
    });
}
function dbMessage(string $id, string $text = 'Database fixture', string $phone = '201111111111', string $user = 'US.database1'): array
{
    return [
        'id' => $id, 'from' => $phone, 'from_user_id' => $user,
        'timestamp' => '1691583200', 'type' => 'text', 'text' => ['body' => $text],
    ];
}
function dbPayload(array $messages, string $source = 'messages', string $waba = '468336579702269'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => $waba, 'changes' => [[
            'field' => $source, 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
                $source === 'smb_message_echoes' ? 'message_echoes' : 'messages' => $messages,
            ],
        ]],
    ]]];
}
function dbEvent(array $payload): int
{
    $plain = json_encode($payload, JSON_THROW_ON_ERROR);
    return DB::table('whatsapp_webhook_events')->insertGetId([
        'payload_hash' => hash('sha256', $plain), 'payload' => Crypt::encryptString($plain),
        'received_at' => now('UTC'), 'processed_at' => null,
    ]);
}
function dbConsume(int $limit = 100, bool $retry = false): array
{
    return (new WhatsAppInboxConsumer())->consume($limit, $retry);
}

$tests = 0;
dbCheck(Schema::hasTable('whatsapp_inbox_conversations') && Schema::hasTable('whatsapp_inbox_messages')
    && Schema::hasTable('whatsapp_inbox_ingestion_failures'), 'actual migration tables');
dbCheck((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1, 'SQLite foreign keys active');
$tests++;

$eventId = dbEvent(dbPayload([dbMessage('wamid.database1')]));
$metrics = dbConsume();
dbCheck($metrics['events_processed'] === 1 && $metrics['messages_inserted'] === 1
    && $metrics['errors'] === 0 && $metrics['quarantined_events'] === 0, 'real transaction projection');
$thread = DB::table('whatsapp_inbox_conversations')->first();
$message = DB::table('whatsapp_inbox_messages')->first();
$customer = json_decode(Crypt::decryptString($thread->customer), true, 512, JSON_THROW_ON_ERROR);
$dto = json_decode(Crypt::decryptString($message->content), true, 512, JSON_THROW_ON_ERROR);
dbCheck($customer['phone'] === '201111111111' && $dto['text'] === 'Database fixture', 'real Laravel cipher round trip');
dbCheck(strpos($thread->customer, '201111111111') === false && strpos($message->content, 'Database fixture') === false,
    'actual stored columns contain ciphertext');
dbCheck(DB::table('whatsapp_webhook_events')->where('id', $eventId)->value('processed_at') !== null,
    'raw processed marker committed');
$tests++;

dbEvent(dbPayload([dbMessage('wamid.database1')], 'standby'));
$metrics = dbConsume();
$saved = DB::table('whatsapp_inbox_messages')->first();
$dto = json_decode(Crypt::decryptString($saved->content), true, 512, JSON_THROW_ON_ERROR);
dbCheck($metrics['messages_replayed'] === 1 && DB::table('whatsapp_inbox_messages')->count() === 1
    && $dto['content']['sources'] === ['messages', 'standby'], 'replay source merge on real SQL');
$tests++;

// The database must independently enforce both named uniqueness constraints.
$uniqueBlocked = 0;
foreach ([
    ['table' => 'whatsapp_inbox_messages', 'row' => (array) $saved],
    ['table' => 'whatsapp_inbox_conversations', 'row' => (array) $thread],
] as $duplicate) {
    unset($duplicate['row']['id']);
    try {
        DB::table($duplicate['table'])->insert($duplicate['row']);
    } catch (\Illuminate\Database\QueryException $error) {
        $uniqueBlocked++;
    }
}
dbCheck($uniqueBlocked === 2 && DB::table('whatsapp_inbox_messages')->count() === 1,
    'actual message and scoped peer uniqueness');
$tests++;

$conflictId = dbEvent(dbPayload([dbMessage('wamid.database1', 'Conflicting fixture')]));
$snapshot = $saved->content;
$metrics = dbConsume();
dbCheck($metrics['quarantined_events'] === 1 && $metrics['errors'] === 0
    && DB::table('whatsapp_inbox_messages')->first()->content === $snapshot,
    'immutable replay conflict rolls back');
dbCheck(DB::table('whatsapp_webhook_events')->where('id', $conflictId)->value('processed_at') === null
    && DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $conflictId)->value('reason') === 'CONFLICTING_MESSAGE',
    'conflicting raw event retained with fixed reason');
$tests++;

$nextId = dbEvent(dbPayload([dbMessage('wamid.database2', 'Next fixture')]));
$metrics = dbConsume(1);
dbCheck($metrics['events_processed'] === 1 && DB::table('whatsapp_webhook_events')->where('id', $nextId)->value('processed_at') !== null,
    'real not-exists query skips quarantine without starving new rows');
$tests++;

// Replace only this test fixture's conflicting raw body, then explicitly retry it.
$fixed = json_encode(dbPayload([dbMessage('wamid.database1')]), JSON_THROW_ON_ERROR);
DB::table('whatsapp_webhook_events')->where('id', $conflictId)->update(['payload' => Crypt::encryptString($fixed)]);
$metrics = dbConsume(100, true);
dbCheck($metrics['events_processed'] === 1 && $metrics['messages_replayed'] === 1
    && !DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $conflictId)->exists(),
    'explicit retry removes quarantine marker in the success transaction');
$tests++;

dbReset();
$statusPayload = dbPayload([]);
unset($statusPayload['entry'][0]['changes'][0]['value']['messages']);
$statusPayload['entry'][0]['changes'][0]['value']['statuses'] = [['id' => 'wamid.status', 'status' => 'delivered']];
dbEvent($statusPayload);
$metrics = dbConsume();
dbCheck($metrics['events_processed'] === 1 && $metrics['status_records'] === 1
    && DB::table('whatsapp_inbox_messages')->count() === 0, 'status-only event real transaction');
$tests++;

dbReset();
$rollbackId = dbEvent(dbPayload([dbMessage('wamid.rollback1'), dbMessage('wamid.rollback2')]));
DB::unprepared("CREATE TRIGGER wa_fixture_fail_second BEFORE INSERT ON whatsapp_inbox_messages
WHEN (SELECT COUNT(*) FROM whatsapp_inbox_messages) = 1
BEGIN SELECT RAISE(ABORT, 'fixture transient insert failure'); END");
$metrics = dbConsume();
dbCheck($metrics['errors'] === 1 && $metrics['events_processed'] === 0 && $metrics['messages_inserted'] === 0,
    'real SQL error publishes no transaction success metrics');
dbCheck(DB::table('whatsapp_inbox_messages')->count() === 0 && DB::table('whatsapp_inbox_conversations')->count() === 0
    && DB::table('whatsapp_webhook_events')->where('id', $rollbackId)->value('processed_at') === null
    && DB::table('whatsapp_inbox_ingestion_failures')->count() === 0,
    'real database rolls back message, thread and processed marker');
DB::unprepared('DROP TRIGGER wa_fixture_fail_second');
$metrics = dbConsume();
dbCheck($metrics['events_processed'] === 1 && $metrics['messages_inserted'] === 2,
    'transient database error remains retryable');
$tests++;

dbReset();
$markerId = dbEvent(dbPayload([dbMessage('wamid.marker')]));
DB::unprepared("CREATE TRIGGER wa_fixture_fail_marker BEFORE UPDATE OF processed_at ON whatsapp_webhook_events
BEGIN SELECT RAISE(ABORT, 'fixture processed marker failure'); END");
$metrics = dbConsume();
dbCheck($metrics['errors'] === 1 && DB::table('whatsapp_inbox_messages')->count() === 0
    && DB::table('whatsapp_inbox_conversations')->count() === 0
    && DB::table('whatsapp_webhook_events')->where('id', $markerId)->value('processed_at') === null,
    'actual processed marker error rolls projections back');
DB::unprepared('DROP TRIGGER wa_fixture_fail_marker');
$tests++;

dbReset();
dbEvent(dbPayload([dbMessage('wamid.ignored')], 'messages', '1636131124838697'));
$metrics = dbConsume();
dbCheck($metrics['ignored_records'] === 1 && $metrics['events_processed'] === 1
    && DB::table('whatsapp_inbox_messages')->count() === 0, 'test account excluded from projection');
$tests++;

dbReset();
$migration->down();
dbCheck(!Schema::hasTable('whatsapp_inbox_conversations') && !Schema::hasTable('whatsapp_inbox_messages')
    && !Schema::hasTable('whatsapp_inbox_ingestion_failures') && Schema::hasTable('whatsapp_webhook_events'),
    'migration down retains raw webhook table');
$tests++;

echo 'WHATSAPP_INBOX_DATABASE_TESTS=' . $tests . "\n";
