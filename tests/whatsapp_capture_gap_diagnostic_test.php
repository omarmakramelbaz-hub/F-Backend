<?php

require __DIR__ . '/../app/Support/WhatsAppInboxProtocol.php';
require __DIR__ . '/../deployment/whatsapp_capture_gap_diagnostic.php';

$checks = 0;
function captureCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new \RuntimeException($label);
    $checks++;
}
function capturePayload(array $messages, string $source = 'messages', string $channel = 'messages', array $contacts = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '468336579702269', 'changes' => [['field' => $source, 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '201285545554', 'phone_number_id' => '515388018324075'],
            'contacts' => $contacts, $channel => $messages,
        ]]],
    ]]];
}
function captureMessage(bool $echo = false, bool $user = true): array
{
    $message = ['id' => $echo ? 'wamid.PRIVATE_ECHO' : 'wamid.PRIVATE_INBOUND', 'timestamp' => '1791597000',
        'type' => 'text', 'text' => ['body' => 'PRIVATE_NAME PRIVATE_ADDRESS 201064464499 EAAprivateTokenText']];
    $message += $echo ? ['from' => '201285545554', 'to' => '201050007771'] : ['from' => '201050007771'];
    if ($user) $message[$echo ? 'to_user_id' : 'from_user_id'] = 'US.PRIVATE_CUSTOMER';
    return $message;
}
function captureSafe(array $result): void
{
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (['201050007771', '201064464499', '201285545554', 'PRIVATE_', 'US.PRIVATE', 'wamid.PRIVATE',
        'EAAprivate', 'EVIL_FIELD_NAME', 'EVIL_TYPE_NAME'] as $private) {
        captureCheck(strpos($json, $private) === false, 'no_private_output_' . $private);
    }
}

try {
    captureCheck(WhatsAppCaptureGapDiagnostic::arguments([]) === ['after_event' => 0, 'through_event' => null, 'limit' => 30], 'recent_thirty_defaults');
    captureCheck(WhatsAppCaptureGapDiagnostic::arguments(['--after-event=12', '--through-event=30', '--limit=50'])
        === ['after_event' => 12, 'through_event' => 30, 'limit' => 50], 'strict_numeric_boundaries_and_limit');
    captureCheck(WhatsAppCaptureGapDiagnostic::arguments(['--after-event=0', '--through-event=' . PHP_INT_MAX, '--limit=1'])['through_event'] === PHP_INT_MAX, 'native_integer_limit_not_float');
    $invalidArguments = [
        ['--unsafe-option'], ['--limit=0'], ['--limit=51'], ['--limit=01'], ['--limit=-1'],
        ['--limit=+1'], ['--limit=1.0'], ['--limit=1', '--limit=2'], ['--limit', '1'],
        ['--after-event= 3'], ['--after-event=3\n'], ['--after-event=EAAprivateTokenText'],
        ['--after-event=9223372036854775808'], ['--after-event=99999999999999999999'],
        ['--through-event=0'], ['--through-event=10', '--after-event=10'],
        ['--through-event=9', '--after-event=10'], ['--after-event=1', '--after-event=2'],
    ];
    foreach ($invalidArguments as $arguments) {
        $rejected = false;
        try { WhatsAppCaptureGapDiagnostic::arguments($arguments); } catch (\Throwable $error) { $rejected = true; }
        captureCheck($rejected, 'invalid_argument_rejected');
    }
    $diagnostic = new WhatsAppCaptureGapDiagnostic();
    $in = $diagnostic->inspect(capturePayload([captureMessage()], 'messages', 'messages', [[
        'wa_id' => '201050007771', 'user_id' => 'US.PRIVATE_CUSTOMER', 'profile' => ['name' => 'PRIVATE_NAME'],
    ]]));
    $echo = $diagnostic->inspect(capturePayload([captureMessage(true)], 'smb_message_echoes', 'message_echoes'));
    $inRecord = $in['scoped_changes'][0]['records'][0];
    $outRecord = $echo['scoped_changes'][0]['records'][0];
    captureCheck($inRecord['native_identity']['from'] === $outRecord['native_identity']['to'], 'native_phone_correlates');
    captureCheck($inRecord['native_identity']['from_user_id'] === $outRecord['native_identity']['to_user_id'], 'native_user_correlates');
    captureCheck($in['scoped_changes'][0]['contacts'][0]['wa_id'] === $inRecord['native_identity']['from'], 'contact_phone_correlates');
    captureCheck($inRecord['normalized'][0]['peer_namespace'] === 'USER', 'actual_normalizer_user_preference_visible');
    captureCheck($outRecord['normalized'][0]['direction'] === 'outbound', 'actual_echo_direction_visible');
    captureSafe($in); captureSafe($echo);

    $phoneOnlyEcho = $diagnostic->inspect(capturePayload([captureMessage(true, false)], 'smb_message_echoes', 'message_echoes'));
    captureCheck($phoneOnlyEcho['scoped_changes'][0]['records'][0]['normalized'][0]['peer_namespace'] === 'PHONE', 'phone_namespace_divergence_visible');
    captureCheck($phoneOnlyEcho['scoped_changes'][0]['records'][0]['normalized'][0]['peer_phone'] === $inRecord['normalized'][0]['peer_phone'], 'same_native_phone_not_text');
    $different = captureMessage(true); $different['to'] = '201064464499'; $different['to_user_id'] = 'US.PRIVATE_OTHER';
    $differentResult = $diagnostic->inspect(capturePayload([$different], 'smb_message_echoes', 'message_echoes'));
    captureCheck($differentResult['scoped_changes'][0]['records'][0]['native_identity']['to'] !== $inRecord['native_identity']['from'], 'supplied_body_phone_does_not_correlate');
    captureSafe($differentResult);

    $alternate = capturePayload(captureMessage(true), 'standby', 'message_echo');
    $alternateResult = $diagnostic->inspect($alternate);
    captureCheck($alternateResult['scoped_changes'][0]['channels']['message_echo']['shape'] === 'OBJECT', 'unknown_singular_shape_visible');
    captureCheck($alternateResult['scoped_changes'][0]['records'][0]['normalized'] === [] && $alternateResult['normalizer']['quarantined'] > 0, 'unknown_shape_not_guessed_normalized');
    $nested = capturePayload(['message_echoes' => [captureMessage(true)]], 'standby', 'standby');
    $nestedResult = $diagnostic->inspect($nested);
    captureCheck($nestedResult['scoped_changes'][0]['records'][0]['channel'] === 'standby.message_echoes', 'nested_standby_channel');
    captureCheck($nestedResult['scoped_changes'][0]['records'][0]['normalized'][0]['source'] === 'standby', 'verified_nested_standby_normalized');
    $flatWrappedMessage = captureMessage(true, false);
    $wrappedMessage = $flatWrappedMessage;
    unset($wrappedMessage['id'], $wrappedMessage['timestamp'], $wrappedMessage['from']);
    $wrappedMessage['messaging_product'] = 'whatsapp'; $wrappedMessage['recipient_type'] = 'individual';
    $wrappedRecord = ['id' => $flatWrappedMessage['id'], 'timestamp' => $flatWrappedMessage['timestamp'], 'message' => $wrappedMessage];
    $wrappedPayload = capturePayload(['message_echoes' => [$wrappedRecord]], 'standby', 'standby');
    $wrappedDto = \App\Support\WhatsAppInboxProtocol::messages($wrappedPayload)[0];
    $lookups = [];
    $projection = new WhatsAppCaptureGapDiagnostic(static function (string $key, callable $sanitize) use (&$lookups, $wrappedDto): array {
        $lookups[] = $key;
        return ['status' => 'FOUND', 'conversation_id' => 42, 'identity' => $sanitize($wrappedDto), 'columns_agree' => true];
    });
    $wrappedResult = $projection->inspect($wrappedPayload);
    $wrappedDetail = $wrappedResult['scoped_changes'][0]['records'][0];
    captureCheck($lookups === [hash('sha256', 'whatsapp-message-v1:468336579702269:515388018324075:' . $wrappedRecord['id'])], 'wrapped_outer_id_used_for_actual_scoped_projection_key');
    captureCheck($wrappedDetail['message'] !== null && $wrappedDetail['sent_at_utc'] === $wrappedDto['sent_at']
        && $wrappedDetail['normalized'][0]['direction'] === 'outbound' && $wrappedDetail['normalized'][0]['source'] === 'standby'
        && $wrappedDetail['projected']['conversation_id'] === 42, 'canonical_wrapped_outer_id_time_normalization_and_projection_visible');
    captureSafe($wrappedResult);
    $badWrappers = [];
    $bad = $wrappedRecord; $bad['message']['id'] = 'wamid.PRIVATE_OTHER'; $badWrappers[] = $bad;
    $bad = $wrappedRecord; $bad['message']['timestamp'] = '1791597001'; $badWrappers[] = $bad;
    $bad = $wrappedRecord; $bad['to'] = '201064464499'; $badWrappers[] = $bad;
    $bad = $wrappedRecord; $bad['message']['recipient_id'] = '201064464499'; $badWrappers[] = $bad;
    foreach ($badWrappers as $badWrapper) {
        $badPayload = capturePayload(['message_echoes' => [$badWrapper]], 'standby', 'standby');
        // An unrelated valid flat copy with the same outer ID must not bless a rejected wrapper.
        $validCopy = capturePayload([$flatWrappedMessage], 'smb_message_echoes', 'message_echoes');
        $badPayload['entry'][0]['changes'][] = $validCopy['entry'][0]['changes'][0];
        $before = count($lookups); $badResult = $projection->inspect($badPayload);
        $badDetail = $badResult['scoped_changes'][0]['records'][0];
        captureCheck(count($lookups) === $before + 1 && $badDetail['normalized'] === []
            && $badDetail['projected']['status'] === 'WRAPPED_NOT_VALIDATED', 'rejected_wrapper_does_not_borrow_flat_copy_projection');
        captureSafe($badResult);
    }
    $unscoped = capturePayload([captureMessage()]); $unscoped['entry'][0]['standby'] = [captureMessage(true)];
    captureCheck($diagnostic->inspect($unscoped)['unscoped_entry_standby'] === 1, 'unscoped_entry_standby_observed_not_joined');

    $wrongScope = capturePayload([captureMessage()]); $wrongScope['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'OTHER_PRIVATE_SCOPE';
    captureCheck($diagnostic->inspect($wrongScope)['scoped_changes'] === [], 'foreign_phone_scope_excluded');
    $wrongScope['entry'][0]['id'] = 'OTHER_PRIVATE_WABA';
    captureCheck($diagnostic->inspect($wrongScope)['scoped_changes'] === [], 'foreign_waba_excluded');
    $evil = capturePayload([captureMessage()]); $evil['entry'][0]['changes'][0]['field'] = 'EVIL_FIELD_NAME';
    $evil['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'EVIL_TYPE_NAME';
    $evilResult = $diagnostic->inspect($evil);
    captureCheck($evilResult['scoped_changes'][0]['field'] === 'OTHER', 'unknown_field_whitelist');
    captureCheck($evilResult['scoped_changes'][0]['records'][0]['type'] === 'OTHER', 'unknown_type_whitelist');
    captureSafe($evilResult);
    $bounded = new WhatsAppCaptureGapDiagnostic();
    $large = $bounded->inspect(capturePayload(array_fill(0, 180, captureMessage())));
    captureCheck(count($large['scoped_changes'][0]['records']) === 160 && $large['records_omitted'] === 20, 'record_limit_omissions_explicit');
    captureCheck(count($large['scoped_changes'][0]['records'][0]['normalized']) === 1
        && $large['scoped_changes'][0]['records'][0]['normalizations_omitted'] === 0
        && $large['normalizations_omitted'] === 0, 'same_message_duplicates_do_not_expand_normalized_records');
    captureSafe($large);
    $secondLarge = $bounded->inspect(capturePayload(array_fill(0, 180, captureMessage(true)), 'smb_message_echoes', 'message_echoes'));
    captureCheck(count($secondLarge['scoped_changes'][0]['records']) === 0 && $secondLarge['records_omitted'] === 180, 'record_limit_applies_across_events');
    $oversizedEvents = [];
    for ($i = 600; $i > 0; $i--) $oversizedEvents[] = ['event_id' => $i, 'safe_shape' => str_repeat('A', 1000)];
    $oversized = ['diagnostic' => 'READ_ONLY_CAPTURE_GAP', 'status' => 'READY', 'window_complete' => true,
        'selected_range_complete' => true, 'details_truncated' => false, 'output_details_omitted' => false,
        'output_events_omitted' => 0, 'events' => $oversizedEvents];
    $boundedJson = WhatsAppCaptureGapDiagnostic::output($oversized);
    $boundedOutput = json_decode($boundedJson, true, 512, JSON_THROW_ON_ERROR);
    captureCheck(strlen($boundedJson) <= 524288, 'final_json_output_byte_limit');
    captureCheck($boundedOutput['window_complete'] === true && $boundedOutput['details_truncated'] === true
        && $boundedOutput['output_details_omitted'] === true && count($boundedOutput['events']) > 0
        && $boundedOutput['output_events_omitted'] === 600 - count($boundedOutput['events']), 'whole_events_retained_with_explicit_output_omissions');
    captureCheck($boundedOutput['events'][0] === $oversizedEvents[0]
        && $boundedOutput['events'][count($boundedOutput['events']) - 1] === $oversizedEvents[count($boundedOutput['events']) - 1], 'newest_complete_event_priority');
    $oversized['events'] = [['event_id' => 2, 'safe_shape' => str_repeat('A', 600000)], ['event_id' => 1, 'safe_shape' => 'bounded']];
    $retained = json_decode(WhatsAppCaptureGapDiagnostic::output($oversized), true, 512, JSON_THROW_ON_ERROR);
    captureCheck($retained['events'] === [$oversized['events'][1]] && $retained['output_events_omitted'] === 1, 'one_huge_event_does_not_erase_other_complete_events');
    $oversized['metadata'] = str_repeat('A', 600000);
    $minimal = WhatsAppCaptureGapDiagnostic::output($oversized);
    captureCheck(strlen($minimal) <= 524288 && json_decode($minimal, true)['details_truncated'] === true, 'metadata_overflow_still_bounded');
    $status = $diagnostic->inspect(capturePayload([['id' => 'wamid.PRIVATE_STATUS', 'recipient_id' => '201050007771',
        'recipient_user_id' => 'US.PRIVATE_CUSTOMER', 'timestamp' => '1791597000', 'status' => 'read']], 'messages', 'statuses'));
    captureCheck($status['scoped_changes'][0]['records'][0]['kind'] === 'STATUS', 'delivery_receipt_separate_from_message_body');
    captureCheck($status['scoped_changes'][0]['records'][0]['native_identity']['recipient_id'] === $inRecord['native_identity']['from'], 'status_native_recipient_correlates');
    captureSafe($status);
    $cart = captureMessage(); $cart['type'] = 'order'; unset($cart['text']);
    $cart['order'] = ['catalog_id' => '1234567890123', 'text' => 'PRIVATE_CART_DESCRIPTION', 'product_items' => [[
        'product_retailer_id' => 'PRIVATE_RETAILER_ID', 'quantity' => 2, 'currency' => 'EGP', 'item_price' => 'PRIVATE_PRICE',
    ]]];
    $cartResult = (new WhatsAppCaptureGapDiagnostic())->inspect(capturePayload([$cart]));
    captureCheck($cartResult['scoped_changes'][0]['records'][0]['cart_shape'] === [
        'catalog_id' => '1234567890123', 'line_count' => 1, 'quantities_valid' => true, 'currency_codes_valid' => true,
    ], 'business_catalog_and_cart_shape_without_product_values');
    captureSafe($cartResult);
    $cart['order']['catalog_id'] = 'PRIVATE_CART_CATALOG'; $cart['order']['product_items'][0]['quantity'] = -1;
    $cart['order']['product_items'][0]['currency'] = 'PRIVATE_CURRENCY';
    $badCart = (new WhatsAppCaptureGapDiagnostic())->inspect(capturePayload([$cart]));
    captureCheck($badCart['scoped_changes'][0]['records'][0]['cart_shape']['catalog_id'] === 'INVALID'
        && $badCart['scoped_changes'][0]['records'][0]['cart_shape']['quantities_valid'] === false
        && $badCart['scoped_changes'][0]['records'][0]['cart_shape']['currency_codes_valid'] === false, 'invalid_cart_values_redacted');
    captureSafe($badCart);

    // Full helper execution uses an isolated, absent fixed root and a private SQLite fixture.
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    $root = '/home/fasakha/public_html';
    if (getenv('GITHUB_ACTIONS') !== 'true' || file_exists($root) || is_link($root)
        || !is_string($autoload) || !is_file($autoload) || !extension_loaded('pdo_sqlite')) {
        throw new \RuntimeException('Integration requires isolated CI, absent root, and temporary SQLite dependencies.');
    }
    require $autoload;
    umask(0077);
    $temporary = tempnam(sys_get_temp_dir(), 'wa-gap-fixture-'); unlink($temporary); mkdir($temporary, 0700);
    mkdir($root . '/vendor', 0700, true); mkdir($root . '/bootstrap', 0700);
    function captureRemove(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $item) if ($item !== '.' && $item !== '..') captureRemove($path . '/' . $item);
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) unlink($path);
    }
    register_shutdown_function(static function () use ($root, $temporary) { captureRemove($root); captureRemove($temporary); });
    $database = $temporary . '/fixture.sqlite'; touch($database);
    $bootLog = $temporary . '/bootstrap-count';
    putenv('WA_GAP_FIXTURE_BOOT_LOG=' . $bootLog);
    $key = random_bytes(32);
    $setup = <<<'PHP'
<?php
function captureFixtureBoot(): void {
    $container = new \Illuminate\Container\Container();
    \Illuminate\Container\Container::setInstance($container);
    $capsule = new \Illuminate\Database\Capsule\Manager($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => getenv('WA_GAP_FIXTURE_DB'), 'prefix' => '']);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('encrypter', new \Illuminate\Encryption\Encrypter(base64_decode(getenv('WA_GAP_FIXTURE_KEY'), true), 'AES-256-CBC'));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
}
PHP;
    file_put_contents($temporary . '/setup.php', $setup); require $temporary . '/setup.php';
    putenv('WA_GAP_FIXTURE_DB=' . $database); putenv('WA_GAP_FIXTURE_KEY=' . base64_encode($key));
    captureFixtureBoot();
    $db = \Illuminate\Support\Facades\DB::class;
    $schema = $db::connection()->getSchemaBuilder();
    $schema->create('whatsapp_webhook_events', function ($table) { $table->increments('id'); $table->text('payload'); $table->string('received_at'); $table->string('processed_at')->nullable(); });
    $schema->create('whatsapp_inbox_ingestion_failures', function ($table) { $table->integer('event_id'); $table->string('reason'); });
    $schema->create('whatsapp_inbox_conversations', function ($table) { $table->increments('id'); $table->string('waba_id'); $table->string('phone_number_id'); });
    $schema->create('whatsapp_inbox_messages', function ($table) { $table->increments('id'); $table->integer('conversation_id'); $table->string('message_key'); $table->string('direction'); $table->string('source'); $table->string('type'); $table->text('content'); });
    $crypt = \Illuminate\Support\Facades\Crypt::class;
    $payload = capturePayload([captureMessage()]);
    foreach (['2026-10-10 01:46:59', '2026-10-10 01:48:00'] as $received) $db::table('whatsapp_webhook_events')->insert([
        'payload' => $crypt::encryptString(json_encode($payload)), 'received_at' => $received, 'processed_at' => null]);
    $db::table('whatsapp_webhook_events')->insert(['payload' => 'PRIVATE_CORRUPT_CIPHERTEXT', 'received_at' => '2026-10-10 01:49:00', 'processed_at' => null]);
    $db::table('whatsapp_inbox_ingestion_failures')->insert(['event_id' => 2, 'reason' => 'INVALID_EVENT']);
    $db::table('whatsapp_inbox_conversations')->insert(['id' => 7, 'waba_id' => '468336579702269', 'phone_number_id' => '515388018324075']);
    $dto = \App\Support\WhatsAppInboxProtocol::messages($payload)[0];
    $db::table('whatsapp_inbox_messages')->insert(['id' => 9, 'conversation_id' => 7,
        'message_key' => hash('sha256', 'whatsapp-message-v1:468336579702269:515388018324075:' . $dto['message_id']),
        'direction' => 'inbound', 'source' => 'messages', 'type' => 'text', 'content' => $crypt::encryptString(json_encode($dto))]);
    $repo = realpath(__DIR__ . '/..');
    file_put_contents($root . '/vendor/autoload.php', "<?php\nrequire " . var_export($autoload, true) . ";\nrequire " . var_export($temporary . '/setup.php', true)
        . ";\nrequire " . var_export($repo . '/app/Support/WhatsAppInboxProtocol.php', true) . ";\n");
    file_put_contents($root . '/bootstrap/app.php', <<<'PHP'
<?php
return new class { public function make($contract) { return new class { public function bootstrap() {
    file_put_contents(getenv('WA_GAP_FIXTURE_BOOT_LOG'), "boot\n", FILE_APPEND);
    echo 'PRIVATE_BOOTSTRAP_NOISE EAAprivateTokenText';
    if (getenv('WA_GAP_FIXTURE_FAIL') === 'true') throw new \RuntimeException('PRIVATE_BOOTSTRAP_EXCEPTION');
    captureFixtureBoot();
}}; }};
PHP);
    $snapshot = function () use ($db): string {
        $tables = []; foreach (['whatsapp_webhook_events', 'whatsapp_inbox_ingestion_failures', 'whatsapp_inbox_conversations', 'whatsapp_inbox_messages'] as $table) $tables[$table] = $db::table($table)->get()->toArray();
        return json_encode($tables);
    };
    $run = function (array $arguments = []) use ($repo, $snapshot): array {
        $before = $snapshot();
        $interpreter = getenv('WHATSAPP_TEST_PHP') ?: PHP_BINARY;
        $process = proc_open(array_merge([$interpreter, $repo . '/deployment/whatsapp_capture_gap_diagnostic.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, getenv());
        captureCheck(is_resource($process), 'integration_subprocess_started'); fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        captureCheck($before === $snapshot(), 'full_database_snapshot_unchanged');
        captureCheck($errors === '', 'no_stderr');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR); captureSafe($result);
        return [$result, $exit];
    };
    [$result, $exit] = $run();
    captureCheck($exit === 0 && $result['status'] === 'PARTIAL', 'corrupt_capture_reported_without_global_abort');
    captureCheck($result['events_read'] === 2 && $result['window_complete'] === true, 'fixed_utc_window_and_ceiling');
    captureCheck($result['unreadable_events'] === 1, 'bounded_corrupt_count');
    captureCheck($result['event_order'] === 'NEWEST_FIRST' && $result['events'][0]['event_id'] === 3, 'recent_events_inspected_newest_first');
    captureCheck($result['events'][1]['quarantine_reason'] === 'INVALID_EVENT', 'quarantine_retained_visible');
    $projection = $result['events'][1]['capture']['scoped_changes'][0]['records'][0]['projected'];
    captureCheck($projection['status'] === 'FOUND' && $projection['conversation_id'] === 7 && $projection['columns_agree'] === true, 'scoped_projection_comparison');
    foreach ($invalidArguments as $arguments) {
        $bootBefore = file_get_contents($bootLog);
        [$bad, $exit] = $run($arguments);
        captureCheck($exit === 1 && $bad['status'] === 'FAILED'
            && file_get_contents($bootLog) === $bootBefore, 'invalid_cli_arguments_fail_before_bootstrap');
    }
    for ($i = 0; $i < 90; $i++) $db::table('whatsapp_webhook_events')->insert([
        'payload' => $crypt::encryptString(json_encode($payload)), 'received_at' => '2026-10-10 01:50:00', 'processed_at' => null]);
    $cart['order']['catalog_id'] = '1234567890123'; $cart['order']['product_items'][0]['quantity'] = 2;
    $cart['order']['product_items'][0]['currency'] = 'EGP';
    $db::table('whatsapp_webhook_events')->insert(['payload' => $crypt::encryptString(json_encode(capturePayload([$cart]))),
        'received_at' => '2026-10-10 01:51:00', 'processed_at' => null]);
    $db::table('whatsapp_webhook_events')->insert(['payload' => $crypt::encryptString(json_encode(capturePayload([captureMessage(true)], 'smb_message_echoes', 'message_echoes'))),
        'received_at' => '2026-10-10 01:52:00', 'processed_at' => null]);
    [$recent, $exit] = $run();
    captureCheck($exit === 0 && $recent['events_read'] === 30 && $recent['event_limit'] === 30
        && $recent['window_events_at_ceiling'] === 94 && $recent['selected_range_events_at_ceiling'] === 94
        && $recent['window_complete'] === false && $recent['selected_range_complete'] === false
        && $recent['events_omitted_by_limit'] === 64 && $recent['next_through_event'] === 65, 'broader_window_and_recent_page_coverage_explicit');
    captureCheck($recent['events'][0]['event_id'] === 95
        && $recent['events'][0]['capture']['scoped_changes'][0]['records'][0]['normalized'][0]['direction'] === 'outbound'
        && $recent['events'][1]['capture']['scoped_changes'][0]['records'][0]['cart_shape']['catalog_id'] === '1234567890123', 'newest_echo_and_cart_survive_background_traffic');
    captureCheck($recent['records_omitted'] === 0 && $recent['normalizations_omitted'] === 0
        && $recent['output_details_omitted'] === false, 'default_recent_details_remain_useful');
    [$fresh, $exit] = $run(['--after-event=93']);
    captureCheck($exit === 0 && $fresh['events_read'] === 2 && $fresh['selected_range_complete'] === true
        && $fresh['window_complete'] === false && $fresh['historical_events_outside_range'] === 92
        && $fresh['events_omitted_by_limit'] === 0, 'fresh_floor_completeness_distinct_from_historical_window');
    [$page, $exit] = $run(['--after-event=93', '--limit=1']);
    captureCheck($exit === 0 && $page['events'][0]['event_id'] === 95 && $page['next_through_event'] === 94
        && $page['selected_range_complete'] === false && $page['events_omitted_by_limit'] === 1, 'fresh_range_page_limit');
    [$older, $exit] = $run(['--after-event=93', '--through-event=94', '--limit=1']);
    captureCheck($exit === 0 && $older['event_ceiling'] === 94 && $older['observed_event_ceiling'] === 95
        && $older['events'][0]['event_id'] === 94 && $older['selected_range_complete'] === true, 'explicit_frozen_upper_bound_pages_without_overlap');
    [$secondPage, $exit] = $run(['--through-event=' . $recent['next_through_event']]);
    $firstIds = array_column($recent['events'], 'event_id'); $secondIds = array_column($secondPage['events'], 'event_id');
    captureCheck($exit === 0 && $secondIds[0] === 65 && array_intersect($firstIds, $secondIds) === [], 'historical_recent_pages_are_disjoint');
    $db::table('whatsapp_webhook_events')->insert(['payload' => $crypt::encryptString(json_encode($payload)),
        'received_at' => '2026-10-10 01:53:00', 'processed_at' => null]);
    [$frozen, $exit] = $run(['--after-event=93', '--through-event=95']);
    captureCheck($exit === 0 && $frozen['observed_event_ceiling'] === 96 && $frozen['event_ceiling'] === 95
        && array_column($frozen['events'], 'event_id') === [95, 94], 'later_arrival_excluded_from_frozen_range');
    [$capped, $exit] = $run(['--through-event=9999', '--limit=50']);
    captureCheck($exit === 0 && $capped['through_event_requested'] === 9999 && $capped['event_ceiling'] === 96
        && $capped['events_read'] === 50, 'requested_future_ceiling_capped_to_observed_capture');
    putenv('WA_GAP_FIXTURE_FAIL=true'); [$bad, $exit] = $run(); putenv('WA_GAP_FIXTURE_FAIL');
    captureCheck($exit === 1 && $bad['status'] === 'FAILED', 'private_bootstrap_exception_suppressed');
    echo "WhatsApp capture gap diagnostic: $checks checks passed.\n";
} catch (\Throwable $error) {
    // Fixture failures expose only our fixed test label, never captured diagnostics.
    fwrite(STDERR, 'CAPTURE_GAP_TEST_FAILED ' . $error->getMessage() . "\n");
    exit(1);
}

