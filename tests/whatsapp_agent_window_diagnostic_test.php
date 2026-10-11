<?php

require __DIR__ . '/../app/Support/WhatsAppInboxProtocol.php';
require __DIR__ . '/../deployment/whatsapp_agent_window_diagnostic.php';

$checks = 0;
function agentCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new \RuntimeException($label);
    $checks++;
}
function agentMessage(string $id = 'wamid.PRIVATE_IN', bool $echo = false): array
{
    return ['id' => $id, 'timestamp' => '1791669600', 'type' => 'text',
        'text' => ['body' => 'PRIVATE_NAME PRIVATE_ADDRESS 201064464499 EAAprivateTokenText'],
        'from' => $echo ? '201285545554' : '201050007771']
        + ($echo ? ['to' => '201050007771'] : ['from_user_id' => 'US.PRIVATE_CUSTOMER']);
}
function agentPayload(array $messages, string $field = 'messages', string $channel = 'messages'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269',
        'changes' => [['field' => $field, 'value' => ['messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
            'contacts' => [['wa_id' => '201050007771', 'profile' => ['name' => 'PRIVATE_NAME']]], $channel => $messages]]]]]];
}
function agentSafe(array $result): void
{
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (['201050007771', '201064464499', '201285545554', 'PRIVATE_', 'US.PRIVATE', 'wamid.PRIVATE',
        'EAAprivate', 'EVIL_FIELD', 'EVIL_TYPE', 'PRIVATE_RETAILER', 'PRIVATE_PRICE'] as $private) {
        agentCheck(strpos($json, $private) === false, 'private_value_suppressed');
    }
}
function agentRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $item) if ($item !== '.' && $item !== '..') agentRemove($path . '/' . $item);
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
}

try {
    $clock = new \DateTimeImmutable('2026-10-11 09:00:00', new \DateTimeZone('UTC'));
    $window = WhatsAppAgentWindowDiagnostic::window('2026-10-11', $clock);
    agentCheck($window['start_utc_inclusive'] === '2026-10-10 22:00:00'
        && $window['end_utc_exclusive'] === '2026-10-11 08:00:00' && $window['window_elapsed'] === true, 'cairo_morning_exact_utc_window');
    agentCheck(WhatsAppAgentWindowDiagnostic::window(null, new \DateTimeImmutable('2026-10-10 23:30:00', new \DateTimeZone('UTC')))['date_cairo'] === '2026-10-11', 'default_uses_cairo_day');
    agentCheck(WhatsAppAgentWindowDiagnostic::window('2026-01-11', $clock)['start_utc_inclusive'] === '2026-01-10 23:00:00', 'timezone_rules_not_fixed_offset');
    agentCheck(WhatsAppAgentWindowDiagnostic::window('2026-10-11', new \DateTimeImmutable('2026-10-11 07:59:59', new \DateTimeZone('UTC')))['window_elapsed'] === false, 'active_window_not_complete');
    $invalid = [['--date=2026-02-30'], ['--date=2026-1-11'], ['--date=PRIVATE_DATE'], ['--unsafe-option'],
        ['--date=2026-10-11', '--date=2026-10-12'], ['--date', '2026-10-11'], ['--date=2026-10-11\n']];
    foreach ($invalid as $arguments) {
        $rejected = false; try { WhatsAppAgentWindowDiagnostic::arguments($arguments); } catch (\Throwable $e) { $rejected = true; }
        agentCheck($rejected, 'invalid_date_option_rejected');
    }
    $meta = WhatsAppAgentWindowDiagnostic::metadata(agentPayload([agentMessage('wamid.PRIVATE_ECHO', true)], 'smb_message_echoes', 'message_echoes'));
    agentCheck($meta['native_echo_records'] === 1 && $meta['directions']['outbound'] === 1, 'scoped_echo_counted'); agentSafe($meta);
    $foreign = agentPayload([agentMessage()]); $foreign['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '999999999999';
    agentCheck(WhatsAppAgentWindowDiagnostic::metadata($foreign)['scope_changes'] === 0, 'foreign_phone_not_counted');
    $alternate = WhatsAppAgentWindowDiagnostic::metadata(agentPayload(agentMessage('wamid.PRIVATE_ALT', true), 'standby', 'message_echo'));
    agentCheck($alternate['normalizer']['messages'] === 0 && $alternate['peers'] !== [], 'unknown_echo_native_recipient_available_for_sampling_only');
    $matching = WhatsAppAgentWindowDiagnostic::metadata(agentPayload([agentMessage()]));
    $unrelatedMessage = agentMessage('wamid.PRIVATE_OTHER'); $unrelatedMessage['from'] = '201064464499'; unset($unrelatedMessage['from_user_id']);
    $unrelatedPayload = agentPayload([$unrelatedMessage]); $unrelatedPayload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] = '201064464499';
    $unrelated = WhatsAppAgentWindowDiagnostic::metadata($unrelatedPayload);
    $candidates = [];
    for ($i = 30; $i > 2; $i--) $candidates[] = ['id' => $i, 'echo' => false, 'cart' => false, 'peers' => $unrelated['peers'], 'inbound_peers' => $unrelated['inbound_peers']];
    $candidates[] = ['id' => 2, 'echo' => true, 'cart' => false, 'peers' => $alternate['peers'], 'inbound_peers' => []];
    $candidates[] = ['id' => 1, 'echo' => false, 'cart' => false, 'peers' => $matching['peers'], 'inbound_peers' => $matching['inbound_peers']];
    $selected = WhatsAppAgentWindowDiagnostic::select($candidates);
    agentCheck(isset($selected[2], $selected[1]) && count($selected) === 20, 'unknown_echo_keeps_older_exact_native_inbound_before_unrelated_traffic');
    $events = [['event_id' => 3, 'native_echo_event' => false, 'native_cart_event' => false, 'safe_data' => str_repeat('A', 25000)],
        ['event_id' => 2, 'native_echo_event' => true, 'native_cart_event' => false, 'safe_data' => str_repeat('A', 12000)],
        ['event_id' => 1, 'native_echo_event' => false, 'native_cart_event' => true, 'safe_data' => str_repeat('A', 12000)]];
    $json = WhatsAppAgentWindowDiagnostic::output(['diagnostic' => 'READ_ONLY_AGENT_WINDOW', 'status' => 'READY',
        'details_complete' => true, 'output_events_omitted' => 0, 'events' => $events]);
    $bounded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    agentCheck(strlen($json) <= 30720 && array_column($bounded['events'], 'event_id') === [2, 1]
        && $bounded['output_events_omitted'] === 1 && $bounded['status'] === 'PARTIAL', 'whole_echo_cart_events_survive_byte_budget');

    $root = '/home/fasakha/public_html'; $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    if (getenv('GITHUB_ACTIONS') !== 'true' || file_exists($root) || is_link($root)
        || !is_string($autoload) || !is_file($autoload) || !extension_loaded('pdo_sqlite')) throw new \RuntimeException('Isolated SQLite fixture required.');
    require $autoload; umask(0077);
    $temporary = tempnam(sys_get_temp_dir(), 'wa-agent-window-'); unlink($temporary); mkdir($temporary, 0700);
    mkdir($root . '/vendor', 0700, true); mkdir($root . '/bootstrap', 0700);
    register_shutdown_function(static function () use ($root, $temporary) { agentRemove($root); agentRemove($temporary); });
    $database = $temporary . '/database.sqlite'; touch($database); $key = random_bytes(32);
    putenv('WA_AGENT_FIXTURE_DB=' . $database); putenv('WA_AGENT_FIXTURE_KEY=' . base64_encode($key));
    putenv('WA_AGENT_WINDOW_TEST_NOW=2026-10-11 09:00:00'); putenv('WA_AGENT_FIXTURE_BOOT_LOG=' . $temporary . '/boot.log');
    file_put_contents($temporary . '/setup.php', <<<'PHP'
<?php
function agentFixtureBoot(): void {
    $container = new \Illuminate\Container\Container(); \Illuminate\Container\Container::setInstance($container);
    $capsule = new \Illuminate\Database\Capsule\Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => getenv('WA_AGENT_FIXTURE_DB'), 'prefix' => '']);
    $capsule->setAsGlobal(); $capsule->bootEloquent(); $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('encrypter', new \Illuminate\Encryption\Encrypter(base64_decode(getenv('WA_AGENT_FIXTURE_KEY'), true), 'AES-256-CBC'));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
}
PHP);
    require $temporary . '/setup.php'; agentFixtureBoot();
    $db = \Illuminate\Support\Facades\DB::class; $crypt = \Illuminate\Support\Facades\Crypt::class;
    $schema = $db::connection()->getSchemaBuilder();
    $schema->create('whatsapp_webhook_events', function ($t) { $t->increments('id'); $t->text('payload'); $t->string('received_at'); $t->string('processed_at')->nullable(); });
    $schema->create('whatsapp_inbox_ingestion_failures', function ($t) { $t->integer('event_id'); $t->string('reason'); });
    $schema->create('whatsapp_inbox_conversations', function ($t) { $t->increments('id'); $t->string('waba_id'); $t->string('phone_number_id'); });
    $schema->create('whatsapp_inbox_messages', function ($t) { $t->increments('id'); $t->integer('conversation_id'); $t->string('message_key'); $t->string('direction'); $t->string('source'); $t->string('type'); $t->text('content'); });
    $insert = static function (array $payload, string $received) use ($db, $crypt): int {
        return $db::table('whatsapp_webhook_events')->insertGetId(['payload' => $crypt::encryptString(json_encode($payload)), 'received_at' => $received, 'processed_at' => null]);
    };
    $in = agentPayload([agentMessage()]); $echo = agentPayload([agentMessage('wamid.PRIVATE_ECHO', true)], 'smb_message_echoes', 'message_echoes');
    $cartMessage = agentMessage('wamid.PRIVATE_CART'); $cartMessage['type'] = 'order'; unset($cartMessage['text']);
    $cartMessage['order'] = ['catalog_id' => '1234567890123', 'text' => 'PRIVATE_CART_TEXT', 'product_items' => [[
        'product_retailer_id' => 'PRIVATE_RETAILER', 'quantity' => 1, 'currency' => 'EGP', 'item_price' => '230.00']]];
    $cart = agentPayload([$cartMessage]);
    $insert($in, '2026-10-10 21:59:59'); $inId = $insert($in, '2026-10-10 22:00:00');
    $cartId = $insert($cart, '2026-10-10 22:15:00'); $echoId = $insert($echo, '2026-10-10 22:16:00');
    $insert($foreign, '2026-10-10 22:20:00');
    $status = agentPayload([['id' => 'wamid.PRIVATE_STATUS', 'recipient_id' => '201050007771', 'status' => 'read', 'timestamp' => '1791669600']], 'messages', 'statuses');
    for ($i = 0; $i < 90; $i++) $insert($status, '2026-10-10 22:30:00');
    $insert($in, '2026-10-11 08:00:00');
    $newMessage = agentMessage('wamid.PRIVATE_LATE'); $newMessage['from'] = '201064464499'; $newMessage['from_user_id'] = 'US.PRIVATE_OTHER';
    $latePayload = agentPayload([$newMessage]); $latePayload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] = '201064464499';
    $lateId = $insert($latePayload, '2026-10-11 07:59:59');
    foreach ([7 => $in, 8 => $echo] as $conversationId => $payload) {
        $db::table('whatsapp_inbox_conversations')->insert(['id' => $conversationId, 'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075']);
        $dto = \App\Support\WhatsAppInboxProtocol::messages($payload)[0];
        $db::table('whatsapp_inbox_messages')->insert(['id' => $conversationId, 'conversation_id' => $conversationId,
            'message_key' => hash('sha256', 'whatsapp-message-v1:468336579702269:515388018324075:' . $dto['message_id']),
            'direction' => $dto['direction'], 'source' => $dto['source'], 'type' => $dto['type'], 'content' => $crypt::encryptString(json_encode($dto))]);
    }
    $repo = realpath(__DIR__ . '/..');
    file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export($autoload, true) . '; require '
        . var_export($temporary . '/setup.php', true) . '; require ' . var_export($repo . '/app/Support/WhatsAppInboxProtocol.php', true) . ';');
    file_put_contents($root . '/bootstrap/app.php', <<<'PHP'
<?php
return new class { public function make($contract) { return new class { public function bootstrap() {
    file_put_contents(getenv('WA_AGENT_FIXTURE_BOOT_LOG'), "boot\n", FILE_APPEND);
    echo 'PRIVATE_BOOTSTRAP EAAprivateTokenText'; agentFixtureBoot();
}}; }};
PHP);
    $stage = $temporary . '/stage'; mkdir($stage, 0700);
    foreach (['whatsapp_agent_window_diagnostic.php', 'whatsapp_capture_gap_diagnostic.php'] as $name) { copy($repo . '/deployment/' . $name, $stage . '/' . $name); chmod($stage . '/' . $name, 0600); }
    $snapshot = static function () use ($db): string {
        $out = []; foreach (['whatsapp_webhook_events', 'whatsapp_inbox_ingestion_failures', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages'] as $table) $out[$table] = $db::table($table)->get()->toArray();
        return json_encode($out);
    };
    $run = static function (array $arguments = []) use ($stage, $snapshot): array {
        $before = $snapshot(); $php = getenv('WHATSAPP_TEST_PHP') ?: PHP_BINARY;
        $process = proc_open(array_merge([$php, $stage . '/whatsapp_agent_window_diagnostic.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
        agentCheck(is_resource($process), 'child_started'); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        agentCheck($errors === '', 'stderr_suppressed'); agentCheck(strlen($output) <= 30721, 'compact_terminal_output_limit');
        agentCheck($snapshot() === $before, 'all_persisted_database_rows_unchanged');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR); agentSafe($result);
        return [$result, $exit];
    };
    [$result, $exit] = $run();
    agentCheck($exit === 0 && $result['status'] === 'READY' && $result['window_events_at_ceiling'] === 95
        && $result['events_scanned'] === 95 && $result['window_complete'] === true, 'full_exact_morning_window_read');
    agentCheck($result['summary']['directions'] === ['inbound' => 3, 'outbound' => 1, 'OTHER' => 0]
        && $result['summary']['normalizer']['statuses'] === 90 && $result['summary']['native_echo_records'] === 1
        && $result['summary']['native_cart_records'] === 1, 'full_window_counts_despite_status_only_recent_tail');
    $byId = array_column($result['events'], null, 'event_id');
    agentCheck(array_keys($byId) === [$lateId, $echoId, $cartId, $inId], 'out_of_order_id_uses_received_at_boundary');
    $inRecord = $byId[$inId]['changes'][0]['records'][0]; $echoRecord = $byId[$echoId]['changes'][0]['records'][0];
    agentCheck($inRecord['native_identity']['from'] === $echoRecord['native_identity']['to'], 'single_invocation_labels_match_same_native_phone');
    agentCheck($inRecord['projected']['conversation_id'] === 7 && $echoRecord['projected']['conversation_id'] === 8
        && $inRecord['normalized'][0]['peer_namespace'] === 'USER' && $echoRecord['normalized'][0]['peer_namespace'] === 'PHONE', 'native_scope_projection_split_evidence_without_customer_identity');
    agentCheck($byId[$cartId]['changes'][0]['records'][0]['cart_shape']['catalog_id'] === '1234567890123', 'older_cart_detail_retained');
    foreach ($invalid as $arguments) {
        $beforeBoot = file_get_contents($temporary . '/boot.log'); [$bad, $exit] = $run($arguments);
        agentCheck($exit === 1 && $bad['status'] === 'FAILED' && file_get_contents($temporary . '/boot.log') === $beforeBoot, 'bad_arguments_fail_before_bootstrap');
    }
    putenv('WA_AGENT_WINDOW_TEST_NOW=2026-10-11 07:00:00'); [$active, $exit] = $run(['--date=2026-10-11']);
    agentCheck($exit === 0 && $active['window']['window_elapsed'] === false && $active['status'] === 'PARTIAL', 'still_active_morning_marked_partial');
    putenv('WA_AGENT_WINDOW_TEST_NOW=2026-10-11 09:00:00');
    $large = []; for ($i = 0; $i < 180; $i++) $large[] = agentMessage('wamid.PRIVATE_BATCH_' . $i);
    $insert(agentPayload($large), '2026-10-11 07:58:00');
    [$busy, $exit] = $run(); $busyIds = array_column($busy['events'], null, 'event_id');
    agentCheck($exit === 0 && count($busyIds[$echoId]['changes'][0]['records']) === 1
        && count($busyIds[$cartId]['changes'][0]['records']) === 1
        && $busyIds[$echoId]['changes'][0]['records'][0]['projected']['conversation_id'] === 8
        && $busyIds[$cartId]['changes'][0]['records'][0]['cart_shape']['line_count'] === 1, 'older_echo_cart_proof_survives_new_large_ordinary_batch');
    agentCheck($busy['status'] === 'PARTIAL' && array_sum($busy['detail_omissions']) > 0, 'large_shape_detail_omissions_explicit');
    for ($i = 0; $i < 510; $i++) $insert($status, '2026-10-11 07:59:00');
    [$limited, $exit] = $run();
    agentCheck($exit === 0 && $limited['events_scanned'] === 500 && $limited['window_complete'] === false
        && $limited['events_omitted_by_scan'] > 0 && $limited['status'] === 'PARTIAL', 'scan_limit_never_proves_missing_old_echo');
    agentCheck($limited['oldest_scanned_event_id'] !== null
        && $limited['next_older_through_event'] === $limited['oldest_scanned_event_id'] - 1
        && $limited['successfully_decoded_payload_bytes'] <= $limited['successfully_decoded_payload_byte_limit'], 'bounded_scan_metadata_supports_next_older_page');
    file_put_contents($stage . '/whatsapp_capture_gap_diagnostic.php', "\n", FILE_APPEND);
    $beforeBoot = file_get_contents($temporary . '/boot.log'); [$untrusted, $exit] = $run();
    agentCheck($exit === 1 && $untrusted['status'] === 'FAILED'
        && file_get_contents($temporary . '/boot.log') === $beforeBoot, 'modified_sibling_rejected_before_application_boot');
    echo "WhatsApp Agent window diagnostic: $checks checks passed.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'AGENT_WINDOW_TEST_FAILED ' . $error->getMessage() . "\n"); exit(1);
}
