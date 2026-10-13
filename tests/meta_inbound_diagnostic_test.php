<?php

require __DIR__.'/../app/Support/MetaMessagingProtocol.php';
require __DIR__.'/../deployment/meta_inbound_diagnostic.php';

$checks = 0;
function metaDiagnosticCheck(bool $value, string $label): void
{
    global $checks;
    if (!$value) throw new \RuntimeException($label);
    $checks++;
}
function metaDiagnosticSafe(array $report): void
{
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    foreach (['998877665544', '887766554433', 'PRIVATE_CUSTOMER', 'PRIVATE_TEXT', 'PRIVATE_NAME',
        'PRIVATE_ADDRESS', 'PRIVATE_TOKEN', 'PRIVATE_ERROR', 'PRIVATE_CALLBACK', 'PRIVATE_FIELD', 'PRIVATE_MID'] as $secret) {
        metaDiagnosticCheck(strpos($json, $secret) === false, 'private_value_redacted');
    }
}
function metaDiagnosticPayload(string $object = 'page', string $account = '101', string $source = 'messaging'): array
{
    return ['object' => $object, 'entry' => [['id' => $account, $source => [[
        'sender' => ['id' => '998877665544'], 'recipient' => ['id' => $account],
        'timestamp' => (time() - 3) * 1000,
        'message' => ['mid' => 'PRIVATE_MID', 'text' => 'PRIVATE_TEXT PRIVATE_NAME PRIVATE_ADDRESS'],
    ]]]]];
}

try {
    $accounts = [
        ['channel' => 'messenger', 'account_id' => '101', 'access_token' => 'PRIVATE_TOKEN_PAGE', 'label' => 'PRIVATE_NAME'],
        ['channel' => 'instagram', 'account_id' => '202', 'access_token' => 'PRIVATE_TOKEN_INSTAGRAM', 'login' => 'instagram'],
        ['channel' => 'instagram', 'account_id' => '303', 'access_token' => 'PRIVATE_TOKEN_PAGE', 'login' => 'facebook'],
    ];
    metaDiagnosticCheck(MetaInboundDiagnostic::arguments([])['app_id'] === '2177982783065133', 'pinned_app_default');
    metaDiagnosticCheck(MetaInboundDiagnostic::arguments(['--limit=200', '--after-event=10', '--through-event=20', '--app-id=404'])
        === ['app_id' => '404', 'limit' => 200, 'after_event' => 10, 'through_event' => 20], 'strict_cli_boundaries');
    foreach ([['--limit=0'], ['--limit=201'], ['--app-id=0'], ['--app-id=01'], ['--token=PRIVATE_TOKEN'],
        ['--limit=1', '--limit=2'], ['--through-event=1', '--after-event=1'], ['--after-event=9223372036854775808'],
        ['--through-event=20;PRIVATE_TOKEN'], ['--after-event=-1']] as $arguments) {
        $rejected = false;
        try { MetaInboundDiagnostic::arguments($arguments); } catch (\InvalidArgumentException $error) { $rejected = true; }
        metaDiagnosticCheck($rejected, 'invalid_argument_rejected');
    }
    $diagnostic = new MetaInboundDiagnostic($accounts);
    $payload = metaDiagnosticPayload();
    $incoming = $diagnostic->inspect($payload);
    metaDiagnosticCheck($incoming['accounts']['messenger:101']['raw']['inbound'] === 1
        && $incoming['accounts']['messenger:101']['normalized']['inbound'] === 1, 'actual_inbound_normalizer');
    metaDiagnosticCheck($incoming['accounts']['messenger:101']['raw']['latest_inbound_at_utc'] !== null, 'utc_timestamp_visible');
    metaDiagnosticSafe($incoming);
    $echo = $payload;
    $echo['entry'][0]['messaging'][0]['sender']['id'] = '101';
    $echo['entry'][0]['messaging'][0]['recipient']['id'] = '998877665544';
    $echo['entry'][0]['messaging'][0]['message']['is_echo'] = true;
    $outgoing = $diagnostic->inspect($echo);
    metaDiagnosticCheck($outgoing['accounts']['messenger:101']['raw']['outbound'] === 1
        && $outgoing['accounts']['messenger:101']['normalized']['outbound'] === 1, 'outbound_echo_separate');
    $standby = $diagnostic->inspect(metaDiagnosticPayload('page', '101', 'standby'));
    metaDiagnosticCheck($standby['accounts']['messenger:101']['raw']['standby_messages'] === 1
        && $standby['accounts']['messenger:101']['normalized']['standby_messages'] === 1, 'standby_counted_without_routing_change');
    $instagram = $diagnostic->inspect(metaDiagnosticPayload('instagram', '202'));
    metaDiagnosticCheck($instagram['accounts']['instagram:202']['normalized']['inbound'] === 1, 'instagram_scope');
    $foreign = $diagnostic->inspect(metaDiagnosticPayload('page', '887766554433'));
    metaDiagnosticCheck($foreign['unscoped_entries'] === 1 && $foreign['accounts'] === [], 'unknown_account_excluded');
    metaDiagnosticSafe($foreign);
    $admin = $payload;
    $admin['entry'][0]['messaging'][0]['message'] = ['admin_text' => 'PRIVATE_TEXT PRIVATE_CUSTOMER'];
    $adminScope = $diagnostic->inspect($admin)['accounts']['messenger:101'];
    metaDiagnosticCheck($adminScope['raw']['inbound'] === 0 && $adminScope['raw']['admin_notifications'] === 1
        && $adminScope['raw']['latest_inbound_at_utc'] === null
        && $adminScope['normalized']['inbound'] === 0 && $adminScope['normalized']['admin_notifications'] === 1
        && $adminScope['normalized']['ignored'] === 1 && $adminScope['normalized']['invalid_events'] === 0,
        'admin_handoff_notice_separate_from_customer_message');
    metaDiagnosticSafe($adminScope);
    $adminStandby = $admin;
    $adminStandby['entry'][0]['standby'] = $adminStandby['entry'][0]['messaging'];
    unset($adminStandby['entry'][0]['messaging']);
    $adminScope = $diagnostic->inspect($adminStandby)['accounts']['messenger:101'];
    metaDiagnosticCheck($adminScope['raw']['standby_messages'] === 0 && $adminScope['normalized']['standby_messages'] === 0
        && $adminScope['normalized']['admin_notifications'] === 1, 'admin_standby_not_customer_message');
    $invalidAdmin = $admin;
    $invalidAdmin['entry'][0]['messaging'][0]['message']['admin_text'] = '';
    $invalidScope = $diagnostic->inspect($invalidAdmin)['accounts']['messenger:101'];
    metaDiagnosticCheck($invalidScope['raw']['admin_notifications'] === 1
        && $invalidScope['normalized']['admin_notifications'] === 0
        && $invalidScope['normalized']['categories'] === ['MESSAGE' => 1], 'malformed_admin_marker_not_validated_notice');
    $mixedAdmin = $payload;
    $mixedAdmin['entry'][0]['messaging'][0]['message']['admin_text'] = 'PRIVATE_TEXT';
    $mixedScope = $diagnostic->inspect($mixedAdmin)['accounts']['messenger:101'];
    metaDiagnosticCheck($mixedScope['raw']['inbound'] === 1 && $mixedScope['raw']['admin_notifications'] === 0
        && $mixedScope['normalized']['inbound'] === 1, 'native_message_with_admin_context_remains_message');
    $bad = $payload;
    $bad['entry'][0]['messaging'][] = $payload['entry'][0]['messaging'][0];
    $bad['entry'][0]['messaging'][1]['recipient']['id'] = '887766554433';
    $broken = $diagnostic->inspect($bad);
    metaDiagnosticCheck($broken['accounts']['messenger:101']['raw']['inbound'] === 1
        && $broken['accounts']['messenger:101']['raw']['routing_mismatch'] === 1
        && $broken['accounts']['messenger:101']['normalized']['inbound'] === 0
        && $broken['accounts']['messenger:101']['normalized']['categories'] === ['ROUTING' => 1], 'whole_event_quarantine_gap_visible');
    metaDiagnosticSafe($broken);
    $oversized = $payload;
    $oversized['entry'][0]['messaging'] = array_fill(0, 1001, $payload['entry'][0]['messaging'][0]);
    $bounded = $diagnostic->inspect($oversized);
    metaDiagnosticCheck($bounded['accounts']['messenger:101']['raw']['records_omitted'] === 1
        && $bounded['accounts']['messenger:101']['normalized']['categories'] === ['EVENTS' => 1], 'bounded_shape_actual_normalizer_limit');

    $calls = [];
    $transport = static function (string $url, string $token) use (&$calls): array {
        $calls[] = ['url' => $url, 'token' => $token];
        if (strpos($url, '/subscriptions') !== false) {
            return ['status' => 200, 'body' => json_encode(['data' => [
                ['object' => 'page', 'active' => true, 'callback_url' => 'https://expected.example/api/meta/messaging/webhook',
                    'fields' => [['name' => 'messages'], ['name' => 'message_echoes'], ['name' => 'standby'], ['name' => 'PRIVATE_FIELD']]],
                ['object' => 'instagram', 'active' => false, 'callback_url' => 'PRIVATE_CALLBACK', 'fields' => ['messages']],
                ['object' => 'PRIVATE_CUSTOMER', 'callback_url' => 'PRIVATE_CALLBACK'],
            ]])];
        }
        return ['status' => 200, 'body' => json_encode(['data' => [
            ['id' => '404', 'name' => 'PRIVATE_NAME', 'subscribed_fields' => ['messages', 'message_echoes', 'PRIVATE_FIELD']],
            ['id' => '887766554433', 'name' => 'PRIVATE_NAME'],
        ]])];
    };
    $graph = (new MetaInboundDiagnostic($accounts, $transport))->graph('404', str_repeat('a', 32), 'v25.0',
        'https://expected.example/api/meta/messaging/webhook');
    metaDiagnosticCheck($graph['requests'] === 3 && count($calls) === 3, 'reuse_configured_page_for_facebook_login');
    metaDiagnosticCheck($graph['app_subscriptions']['objects'][0]['active'] === true
        && $graph['app_subscriptions']['objects'][0]['callback_matches'] === true
        && $graph['app_subscriptions']['objects'][1]['callback_matches'] === false, 'active_callback_checks');
    metaDiagnosticCheck($graph['app_subscriptions']['objects'][0]['fields'] === ['message_echoes', 'messages', 'standby']
        && $graph['app_subscriptions']['objects'][0]['unknown_field_count'] === 1, 'fields_whitelisted');
    metaDiagnosticCheck($graph['accounts']['messenger:101']['configured_account_subscriptions']['same_app_subscribed'] === true,
        'page_attached_to_same_app');
    metaDiagnosticCheck($graph['accounts']['instagram:303']['configured_page_subscription_checks'][0]['same_configured_token'] === true,
        'facebook_login_page_token_match_visible');
    metaDiagnosticCheck(strpos($calls[2]['url'], 'graph.instagram.com/v25.0/202/subscribed_apps') !== false
        && !array_filter($calls, static fn ($call) => strpos($call['url'], '/303/subscribed_apps') !== false), 'native_login_edges');
    metaDiagnosticSafe($graph);
    $failed = (new MetaInboundDiagnostic([$accounts[0]], static fn () => ['status' => 400,
        'body' => json_encode(['error' => ['code' => 190, 'message' => 'PRIVATE_ERROR PRIVATE_TOKEN']])]))
        ->graph('404', str_repeat('a', 32), 'v25.0', 'https://expected.example');
    metaDiagnosticCheck($failed['app_subscriptions']['status'] === 'TOKEN_INVALID'
        && $failed['accounts']['messenger:101']['configured_account_subscriptions']['status'] === 'TOKEN_INVALID', 'fixed_error_categories');
    metaDiagnosticSafe($failed);
    $partial = (new MetaInboundDiagnostic([$accounts[0]], static fn () => ['status' => 200,
        'body' => json_encode(['data' => [], 'paging' => ['next' => 'https://PRIVATE_TOKEN']])]))
        ->graph('404', '', 'v25.0', 'https://expected.example');
    metaDiagnosticCheck($partial['accounts']['messenger:101']['configured_account_subscriptions']['same_app_subscribed'] === null
        && $partial['accounts']['messenger:101']['configured_account_subscriptions']['complete'] === false, 'paginated_absence_not_false_negative');
    metaDiagnosticSafe($partial);
    $tooLarge = (new MetaInboundDiagnostic([$accounts[0]], static fn () => ['status' => 200, 'body' => str_repeat('x', 262145)]))
        ->graph('404', '', 'v25.0', 'https://expected.example');
    metaDiagnosticCheck($tooLarge['accounts']['messenger:101']['configured_account_subscriptions']['status'] === 'INVALID_RESPONSE', 'graph_response_byte_limit');
    $onlyFacebookInstagram = new MetaInboundDiagnostic([$accounts[2]], static function ($url) {
        return ['status' => 200, 'body' => json_encode(strpos($url, '/me?') !== false ? ['id' => '101']
            : ['data' => [['id' => '404', 'subscribed_fields' => ['messages']]]])];
    });
    $fallback = $onlyFacebookInstagram->graph('404', '', 'v25.0', 'https://expected.example');
    metaDiagnosticCheck($fallback['requests'] === 2
        && $fallback['accounts']['instagram:303']['token_asset_subscriptions']['subscriptions']['same_app_subscribed'] === true,
        'page_asset_discovery_only_without_configured_messenger');
    metaDiagnosticSafe($fallback);

    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
    $databaseChecks = false;
    if (is_string($autoload) && is_file($autoload) && extension_loaded('pdo_sqlite')) {
        require $autoload;
        $container = new \Illuminate\Container\Container;
        $db = new \Illuminate\Database\Capsule\Manager($container);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->setAsGlobal(); $db->bootEloquent();
        $container->instance('db', $db->getDatabaseManager());
        $container->instance('encrypter', new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $schema = $db->getConnection()->getSchemaBuilder();
        $schema->create('meta_webhook_events', static function ($t) { $t->increments('id'); $t->text('payload'); $t->string('received_at'); $t->string('processed_at')->nullable(); });
        $schema->create('meta_inbox_ingestion_failures', static function ($t) { $t->integer('event_id'); $t->string('reason'); });
        $schema->create('meta_inbox_conversations', static function ($t) { $t->increments('id'); $t->string('waba_id'); $t->string('phone_number_id'); $t->text('customer'); });
        $schema->create('meta_inbox_messages', static function ($t) { $t->increments('id'); $t->integer('conversation_id'); $t->string('direction'); $t->string('source'); $t->string('sent_at'); });
        $facade = \Illuminate\Support\Facades\DB::class;
        $crypt = \Illuminate\Support\Facades\Crypt::class;
        foreach ([$payload, $echo, $bad] as $i => $event) $facade::table('meta_webhook_events')->insert([
            'payload' => $crypt::encryptString(json_encode($event)), 'received_at' => gmdate('Y-m-d H:i:s', time() - 3 + $i),
            'processed_at' => $i === 1 ? gmdate('Y-m-d H:i:s') : null]);
        $facade::table('meta_webhook_events')->insert(['payload' => 'PRIVATE_TOKEN_CORRUPT', 'received_at' => gmdate('Y-m-d H:i:s'), 'processed_at' => null]);
        $facade::table('meta_inbox_ingestion_failures')->insert(['event_id' => 3, 'reason' => 'INVALID_EVENT']);
        foreach ([['name' => 'PRIVATE_NAME'], ['name' => null]] as $customer) $facade::table('meta_inbox_conversations')->insert([
            'waba_id' => 'messenger', 'phone_number_id' => '101', 'customer' => $crypt::encryptString(json_encode($customer))]);
        $facade::table('meta_inbox_conversations')->insert(['waba_id' => 'messenger', 'phone_number_id' => '887766554433', 'customer' => 'PRIVATE_NAME_CORRUPT']);
        $facade::table('meta_inbox_messages')->insert(['conversation_id' => 1, 'direction' => 'outbound', 'source' => 'message_echoes', 'sent_at' => gmdate('Y-m-d H:i:s')]);
        $snapshot = static function () use ($facade): string {
            $out = []; foreach (['meta_webhook_events', 'meta_inbox_ingestion_failures', 'meta_inbox_conversations', 'meta_inbox_messages'] as $table)
                $out[$table] = $facade::table($table)->get()->toArray();
            return json_encode($out);
        };
        $before = $snapshot();
        $local = $diagnostic->local(MetaInboundDiagnostic::arguments([]));
        metaDiagnosticCheck($before === $snapshot(), 'all_database_records_unchanged');
        metaDiagnosticCheck($local['capture']['events_read'] === 4 && $local['capture']['unreadable_events'] === 1
            && $local['capture']['quarantined_events_in_range'] === 1 && $local['capture']['pending_events_in_range'] === 2,
            'pending_quarantine_unreadable_separate');
        $scope = $local['accounts']['messenger:101'];
        metaDiagnosticCheck($scope['capture_sample']['normalized']['inbound'] === 1
            && $scope['capture_sample']['normalized']['outbound'] === 1
            && $scope['capture_sample']['normalized']['categories'] === ['ROUTING' => 1], 'raw_capture_and_actual_normalizer_counts');
        metaDiagnosticCheck($scope['projection']['conversations'] === 2 && $scope['projection']['inbound'] === 0
            && $scope['projection']['outbound'] === 1 && $scope['projection']['customer_name_sample']['named'] === 1
            && $scope['projection']['customer_name_sample']['nameless'] === 1, 'projection_names_scoped_without_disclosure');
        metaDiagnosticSafe($local);
        $boundedLocal = $diagnostic->local(MetaInboundDiagnostic::arguments(['--limit=1']));
        metaDiagnosticCheck($boundedLocal['capture']['sample_complete'] === false && $boundedLocal['capture']['events_omitted'] === 3
            && $boundedLocal['capture']['next_through_event'] === 3, 'bounded_capture_page_explicit');
        metaDiagnosticCheck($before === $snapshot(), 'bounded_database_reads_unchanged');
        $databaseChecks = true;
    }
    echo 'Meta inbound diagnostic: '.$checks.' checks passed; database fixtures '.($databaseChecks ? 'passed' : 'not available').".\n";
} catch (\Throwable $error) {
    // Only our fixed labels are printed. Fixture data and Graph responses never leave the test.
    fwrite(STDERR, 'META_INBOUND_DIAGNOSTIC_TEST_FAILED '.$error->getMessage()."\n"); exit(1);
}
