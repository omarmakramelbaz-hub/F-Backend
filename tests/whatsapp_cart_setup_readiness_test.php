<?php

define('WHATSAPP_CART_SETUP_READINESS_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/deployment/whatsapp_cart_setup_readiness.php';
use App\Support\WhatsAppCartSetupReadiness as Setup;

$checks = 0;
function setupCheck($actual, $expected, string $label): void {
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function setupOrder(string $catalog = '1234567', string $retailer = '123'): array {
    return ['catalog_id' => $catalog, 'text' => 'PRIVATE_CUSTOMER_ORDER',
        'product_items' => [['product_retailer_id' => $retailer, 'quantity' => 1, 'item_price' => '99.00', 'currency' => 'EGP']]];
}
function setupPayload(string $catalog = '1234567', string $retailer = '123'): array {
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => Setup::WABA,
        'changes' => [['field' => 'messages', 'value' => ['messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => Setup::PHONE],
            'contacts' => [['wa_id' => '201050007771', 'profile' => ['name' => 'PRIVATE_NAME']]],
            'messages' => [['id' => 'wamid.PRIVATE', 'from' => '201050007771', 'type' => 'order',
                'order' => setupOrder($catalog, $retailer)]]]]]]]];
}
function setupDto(string $catalog = '1234567', string $retailer = '123'): array {
    return ['type' => 'order', 'direction' => 'inbound', 'source' => 'messages',
        'customer_name' => 'PRIVATE_NAME', 'peer_phone' => '201050007771', 'peer_user_id' => 'US.PRIVATE',
        'content' => ['message' => ['type' => 'order', 'order' => setupOrder($catalog, $retailer)]]];
}
function setupDiscovery(array $catalogs = ['1234567' => ['123']]): array {
    return ['catalogs' => $catalogs, 'event_ceiling' => 40, 'after_event' => 0,
        'raw_events_in_window' => 40, 'raw_events_read' => 40, 'raw_window_complete' => true,
        'scoped_projected_orders' => 2, 'projected_orders_read' => 2,
        'projected_order_window_complete' => true, 'unreadable_rows' => 0];
}
function setupErp(int $options = 0): array {
    $rows = [];
    if ($options === 23) {
        foreach (['extra_clean', 'extra_clear', 'extra_vacuim'] as $kind) {
            $rows[] = ['id' => 'f:0:' . $kind, 'label' => 'خيار صفر ' . $kind, 'price' => 'PRIVATE_PRICE'];
        }
        for ($feature = 1; $feature <= 5; $feature++) foreach (['base', 'extra_clean', 'extra_clear', 'extra_vacuim'] as $kind) {
            $rows[] = ['id' => 'f:' . $feature . ':' . $kind, 'label' => 'خيار ' . $feature . ' ' . $kind, 'price' => 'PRIVATE_PRICE'];
        }
    } else {
        for ($i = 0; $i < $options; $i++) $rows[] = ['id' => 'f:' . $i . ':extra_clean', 'label' => 'خيار ' . $i, 'price' => 'PRIVATE_PRICE'];
    }
    return ['items' => [['id' => 11, 'name' => 'فسيخ', 'quantity_mode' => 'select',
        'available' => true, 'price' => 'PRIVATE_PRICE', 'options' => $rows]], 'pagination' => ['last_page' => 1]];
}
function setupGraph(string $name = 'ربع كيلو فسيخ'): array {
    return ['status' => 'READY', 'http' => 200, 'api_error_code' => null, 'complete' => true,
        'products' => [['id' => '7654321', 'retailer_id' => '123', 'name' => $name]]];
}
function setupPrivate(array $report): void {
    $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach (['PRIVATE_', '201050007771', 'US.PRIVATE', 'wamid.PRIVATE', '99.00', 'customer_name', 'peer_phone'] as $private) {
        setupCheck(strpos($json, $private), false, 'private field absent ' . $private);
    }
}

Setup::loadSibling(dirname(__DIR__) . '/deployment', (int) fileowner(__FILE__));
setupCheck(Setup::arguments([]), ['after_event' => 0, 'through_event' => null], 'defaults');
setupCheck(Setup::arguments(['--after-event=12', '--through-event=40']), ['after_event' => 12, 'through_event' => 40], 'bounded window');
foreach ([['--after-event=-1'], ['--after-event=01'], ['--after-event=1;id'], ['--through-event=0'],
    ['--through-event=12', '--after-event=12'], ['--after-event=1', '--after-event=2'], ['--branch=363'],
    ['--after-event=9999999999999999999']] as $arguments) {
    try { Setup::arguments($arguments); throw new RuntimeException('EXPECTED_ARGUMENT_REJECTION'); }
    catch (InvalidArgumentException $error) { setupCheck($error->getMessage(), 'INVALID_ARGUMENTS', 'strict arguments'); }
}
setupCheck(Setup::order(setupOrder())['retailer_ids'], ['123'], 'numeric retailer remains string');
setupCheck(Setup::order(setupOrder('1234567', 'sk-' . str_repeat('A', 20))), null, 'secret-shaped retailer refused');
$payload = setupPayload();
setupCheck(Setup::rawOrders($payload), [['catalog_id' => '1234567', 'retailer_ids' => ['123']]], 'raw scoped catalog only');
$foreign = $payload; $foreign['entry'][0]['id'] = '1111111';
setupCheck(Setup::rawOrders($foreign), [], 'foreign WABA excluded');
$foreign = $payload; $foreign['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '2222222';
setupCheck(Setup::rawOrders($foreign), [], 'foreign phone excluded');
$echo = $payload; $echo['entry'][0]['changes'][0]['field'] = 'smb_message_echoes';
setupCheck(Setup::rawOrders($echo), [], 'outbound cart does not establish customer selection');
setupCheck(Setup::projectedOrder(setupDto()), ['catalog_id' => '1234567', 'retailer_ids' => ['123']], 'projected old cart found');
$dto = setupDto(); $dto['direction'] = 'outbound';
setupCheck(Setup::projectedOrder($dto), null, 'outbound projected cart excluded');
$calls = [];
$reporter = new Setup(function ($catalog) use (&$calls) { $calls[] = $catalog; return setupGraph(); }, fn () => setupErp(23));
$discovery = setupDiscovery(); $discovery['PRIVATE_CUSTOMER_TEXT'] = 'PRIVATE_ADDRESS';
$report = $reporter->report($discovery);
setupCheck($calls, ['1234567'], 'one Graph fetch across four branches');
setupCheck($report['status'], 'READY', 'real 23 feature options supported');
setupCheck(count($report['branches']), 4, 'four fresh branch scopes');
setupCheck($report['branches'][0]['products'][0]['options'][0]['id'], 'f:0:extra_clean', 'zero-feature option kept');
setupCheck(count($report['branches'][0]['products'][0]['options']), 23, 'all real options available for mapping');
setupCheck($report['branches'][0]['products'][0]['options'][3]['id'], 'f:1:base', 'real feature base option kept');
setupCheck($report['branches'][0]['products'][0]['options'][22]['id'], 'f:5:extra_vacuim', 'last actual option combination kept');
setupCheck($report['catalogs'][0]['branch_candidates'][0]['candidates'][0]['candidates'][0]['quantity_per_unit'], '0.25', 'explicit unit proposal');
setupCheck($report['orders_created'], 0, 'no order creation');
setupCheck($report['mappings_written'], 0, 'no automatic mapping writes');
setupPrivate($report);
$unknown = (new Setup(fn () => setupGraph('فسيخة نصف كيلو'), fn () => setupErp()))->report(setupDiscovery());
setupCheck($unknown['catalogs'][0]['branch_candidates'][0]['candidates'][0]['status'], 'NO_EXACT_MATCH', 'fuzzy name never guessed');
setupCheck($unknown['branches'][0]['products'][0]['name'], 'فسيخ', 'unmatched public ERP identity available');
$numeric = (new Setup(fn () => setupGraph('رنجة'), fn () => setupErp()))->report(setupDiscovery());
setupCheck($numeric['catalogs'][0]['branch_candidates'][0]['candidates'][0]['status'], 'NO_EXACT_MATCH', 'numeric retailer is not ERP ID');
$truncated = (new Setup(fn () => setupGraph(), fn () => setupErp(60)))->report(setupDiscovery());
setupCheck($truncated['status'], 'PARTIAL', 'large option set partial');
setupCheck($truncated['branches'][0]['options_omitted'], 10, 'option omission explicit');
setupCheck(count($truncated['branches'][0]['products'][0]['options']), 50, 'options bounded');
setupCheck($truncated['catalogs'][0]['branch_candidates'], [], 'truncated options cannot certify mapping');
$denied = (new Setup(fn () => ['status' => 'CATALOG_ACCESS_DENIED', 'http' => 400, 'api_error_code' => 200,
    'complete' => false, 'products' => [], 'error_message' => 'PRIVATE_TOKEN'], fn () => setupErp()))->report(setupDiscovery());
setupCheck($denied['catalogs'][0]['graph_status'], 'CATALOG_ACCESS_DENIED', 'catalog permission error fixed status');
setupPrivate($denied);
$none = setupDiscovery([]); $none['raw_window_complete'] = false;
$absence = $reporter->report($none);
setupCheck($absence['status'], 'PARTIAL', 'incomplete absence inconclusive');
setupCheck($absence['cart_found'], false, 'bounded absence explicit');
setupCheck($reporter->report(setupDiscovery([]))['status'], 'NO_SCOPED_CART_FOUND', 'complete absence known');
$many = $reporter->report(setupDiscovery(['1234567' => ['123'], '1234568' => ['123'], '1234569' => ['123']]));
setupCheck($many['catalogs_omitted'], 1, 'catalog API count bound');
setupCheck(count($many['catalogs']), 2, 'only two observed catalogs requested');
$big = $report; $big['branches'][0]['products'] = [str_repeat('x', 600000)];
$compact = json_decode(Setup::output($big), true, 512, JSON_THROW_ON_ERROR);
setupCheck($compact['output_details_omitted'], true, 'oversize details explicitly omitted');
setupCheck($compact['branches'][0]['products'], [], 'fallback mutates actual branch array');
setupCheck(strlen(Setup::output($big)) <= 524288, true, 'first fallback byte bound');
$big['unexpected_huge_field'] = str_repeat('x', 600000);
$compact = json_decode(Setup::output($big), true, 512, JSON_THROW_ON_ERROR);
setupCheck(strlen(Setup::output($big)) <= 524288, true, 'second fallback byte bound');
setupCheck($compact['catalogs'][0]['catalog_id'], '1234567', 'second fallback retains public asset ID');

$temporary = sys_get_temp_dir() . '/whatsapp-cart-helper-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
try {
    $path = $temporary . '/whatsapp_catalog_mapping_readiness.php';
    file_put_contents($path, file_get_contents(dirname(__DIR__) . '/deployment/whatsapp_catalog_mapping_readiness.php'));
    chmod($path, 0644); touch($path, time(), time() - 86400);
    Setup::loadSibling($temporary, (int) fileowner(__FILE__));
    setupCheck(true, true, 'atime read change not treated as helper mutation');
    chmod($path, 0666);
    try { Setup::loadSibling($temporary, (int) fileowner(__FILE__)); throw new RuntimeException('EXPECTED_UNSAFE_MODE'); }
    catch (RuntimeException $error) { setupCheck($error->getMessage(), 'UNSAFE_CATALOG_HELPER', 'writable helper rejected'); }
    chmod($path, 0644); file_put_contents($path, '<?php throw new RuntimeException("PRIVATE_LEAK");');
    try { Setup::loadSibling($temporary, (int) fileowner(__FILE__)); throw new RuntimeException('EXPECTED_BAD_HASH'); }
    catch (RuntimeException $error) { setupCheck($error->getMessage(), 'UNVERIFIED_CATALOG_HELPER', 'unpinned code not evaluated'); }
    unlink($path); symlink(dirname(__DIR__) . '/deployment/whatsapp_catalog_mapping_readiness.php', $path);
    try { Setup::loadSibling($temporary, (int) fileowner(__FILE__)); throw new RuntimeException('EXPECTED_SYMLINK'); }
    catch (RuntimeException $error) { setupCheck($error->getMessage(), 'UNSAFE_CATALOG_HELPER', 'symlink rejected'); }
} finally { unlink($temporary . '/whatsapp_catalog_mapping_readiness.php'); rmdir($temporary); }

if (($argv[1] ?? null) === '--pure') { echo "PASSED {$checks} cart setup readiness checks (pure)\n"; exit; }
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
if (!is_string($autoload) || !is_file($autoload) || !extension_loaded('pdo_sqlite')) throw new RuntimeException('SQLITE_TEST_RUNTIME_REQUIRED');
require $autoload;
$container = new Illuminate\Container\Container();
$capsule = new Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$container->instance('db', $capsule->getDatabaseManager());
$encrypter = new Illuminate\Encryption\Encrypter(str_repeat('k', 32), 'AES-256-CBC');
$container->instance('encrypter', $encrypter);
Illuminate\Support\Facades\Facade::clearResolvedInstances();
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$schema = $capsule->getConnection()->getSchemaBuilder();
$schema->create('whatsapp_webhook_events', function ($table) { $table->increments('id'); $table->text('payload'); });
$schema->create('whatsapp_inbox_conversations', function ($table) { $table->increments('id'); $table->string('waba_id'); $table->string('phone_number_id'); });
$schema->create('whatsapp_inbox_messages', function ($table) { $table->increments('id'); $table->integer('conversation_id'); $table->string('type'); $table->string('direction'); $table->text('content'); });
$db = $capsule->getConnection();
$foreign = setupPayload('9999999'); $foreign['entry'][0]['id'] = '1111111';
foreach ([$foreign, setupPayload('1234567', '123'), setupPayload('1234568', '456')] as $payload) {
    $db->table('whatsapp_webhook_events')->insert(['payload' => $encrypter->encryptString(json_encode($payload))]);
}
$db->table('whatsapp_webhook_events')->insert(['payload' => 'PRIVATE_INVALID_CIPHERTEXT']);
$db->table('whatsapp_inbox_conversations')->insert([['id' => 1, 'waba_id' => Setup::WABA, 'phone_number_id' => Setup::PHONE],
    ['id' => 2, 'waba_id' => '1111111', 'phone_number_id' => Setup::PHONE]]);
$db->table('whatsapp_inbox_messages')->insert([['conversation_id' => 1, 'type' => 'order', 'direction' => 'inbound',
    'content' => $encrypter->encryptString(json_encode(setupDto('1234569', '789')))],
    ['conversation_id' => 2, 'type' => 'order', 'direction' => 'inbound', 'content' => $encrypter->encryptString(json_encode(setupDto('9999998')))]]);
$snapshot = static function () use ($db): string {
    $tables = [];
    foreach (['whatsapp_webhook_events', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages'] as $table) {
        $tables[$table] = $db->table($table)->orderBy('id')->get()->all();
    }
    return json_encode($tables, JSON_THROW_ON_ERROR);
};
$beforeSnapshot = $snapshot();
$db->enableQueryLog(); $db->flushQueryLog();
$found = Setup::discover(0);
setupCheck($found['catalogs'], [1234568 => ['456'], 1234567 => ['123'], 1234569 => ['789']], 'raw scope and persisted DTO scope only');
setupCheck($found['unreadable_rows'], 1, 'unreadable ciphertext counted without exception disclosure');
setupCheck($found['raw_events_read'], 4, 'raw row count bounded');
setupCheck($found['projected_orders_read'], 1, 'foreign projected conversation excluded');
setupCheck(Setup::discover(2, 3)['catalogs'], [1234568 => ['456'], 1234569 => ['789']], 'bounded raw window retains historical projected cart');
setupPrivate($reporter->report($found));
setupCheck($snapshot(), $beforeSnapshot, 'all database row contents preserved after discovery and report');
foreach ($db->getQueryLog() as $query) setupCheck(str_starts_with(strtolower(ltrim($query['query'])), 'select'), true, 'discovery executes SELECT only');
setupCheck($db->table('whatsapp_webhook_events')->count(), 4, 'raw records preserved');
setupCheck($db->table('whatsapp_inbox_messages')->count(), 2, 'projected records preserved');

echo "PASSED {$checks} cart setup readiness checks\n";
