<?php

require __DIR__.'/../deployment/meta_event_identity_diagnostic.php';

$checks = 0;
function metaIdentityCheck(bool $value, string $label): void
{
    global $checks;
    if (!$value) throw new \RuntimeException($label);
    $checks++;
}
function metaIdentitySafe(array $report): void
{
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    foreach (['998877665544', '887766554433', 'PRIVATE_CUSTOMER', 'PRIVATE_TEXT', 'PRIVATE_NAME',
        'PRIVATE_ADDRESS', 'PRIVATE_TOKEN', 'PRIVATE_ERROR', 'PRIVATE_KEY', 'PRIVATE_URL', 'PRIVATE_MID',
        'PRIVATE_SCOPE', 'PRIVATE_TYPE', 'PRIVATE_APP', 'PRIVATE_SECRET'] as $secret) {
        metaIdentityCheck(strpos($json, $secret) === false, 'private_value_redacted');
    }
}
function metaIdentityPayload(string $object = 'page', string $account = '101', string $source = 'messaging'): array
{
    return ['object' => $object, 'entry' => [['id' => $account, $source => [[
        'sender' => ['id' => '998877665544'], 'recipient' => ['id' => $account],
        'timestamp' => (time() - 3) * 1000,
        'message' => ['text' => 'PRIVATE_TEXT PRIVATE_NAME PRIVATE_ADDRESS'],
    ]]]]];
}
function metaIdentityReject(callable $call, string $label): void
{
    $rejected = false;
    try { $call(); } catch (\InvalidArgumentException | \UnexpectedValueException $e) { $rejected = true; }
    metaIdentityCheck($rejected, $label);
}

try {
    $accounts = [
        ['channel' => 'messenger', 'account_id' => '101', 'access_token' => 'PRIVATE_TOKEN_PAGE', 'label' => 'PRIVATE_NAME'],
        ['channel' => 'instagram', 'account_id' => '202', 'access_token' => 'PRIVATE_TOKEN_INSTAGRAM', 'login' => 'instagram'],
        ['channel' => 'instagram', 'account_id' => '303', 'access_token' => 'PRIVATE_TOKEN_FACEBOOK', 'login' => 'facebook'],
    ];
    $diagnostic = new MetaEventIdentityDiagnostic($accounts);
    $payload = metaIdentityPayload();
    $incoming = $diagnostic->inspect($payload);
    $shape = $incoming['message_shapes'][0];
    metaIdentityCheck($incoming['kinds'] === ['messenger:101:messaging:message' => 1], 'fixed_message_kind');
    metaIdentityCheck($shape['direction'] === 'inbound' && $shape['sender_valid'] && $shape['recipient_valid'], 'incoming_routing_shape');
    metaIdentityCheck($shape['mid'] === ['present' => false, 'type' => 'null', 'bytes' => null], 'missing_native_mid_visible');
    metaIdentityCheck($shape['peer_matches_projected_sample'] === null, 'no_uninitialised_peer_match_claim');
    metaIdentityCheck($shape['peer_digits'] === 12 && $shape['field_types']['text'] === 'string'
        && $shape['field_types']['attachments'] === 'ABSENT', 'types_lengths_only');
    metaIdentitySafe($incoming);

    $alternate = $payload;
    $alternate['entry'][0]['messaging'][0]['message'] += ['id' => 'PRIVATE_MID', 'message_id' => 77,
        'PRIVATE_KEY_PRIVATE_CUSTOMER' => 'PRIVATE_TOKEN', 'metadata' => ['PRIVATE_NAME' => 'PRIVATE_TEXT'],
        'attachments' => [['type' => 'image', 'payload' => ['url' => 'PRIVATE_URL']]]];
    $alternateReport = $diagnostic->inspect($alternate);
    $shape = $alternateReport['message_shapes'][0];
    metaIdentityCheck($shape['mid']['present'] === false && $shape['id'] === ['present' => true, 'type' => 'string', 'bytes' => 11]
        && $shape['message_id'] === ['present' => true, 'type' => 'int', 'bytes' => null], 'alternate_ids_not_promoted_to_mid');
    metaIdentityCheck($shape['unknown_message_key_count'] === 1 && $shape['field_types']['metadata'] === 'array'
        && $shape['field_types']['attachments'] === 'array', 'unknown_keys_counted_not_returned');
    metaIdentitySafe($alternateReport);
    $nullMid = $payload; $nullMid['entry'][0]['messaging'][0]['message']['mid'] = null;
    metaIdentityCheck($diagnostic->inspect($nullMid)['message_shapes'][0]['mid']['present'] === true, 'missing_distinct_from_present_null');
    $hello = $payload; $hello['entry'][0]['messaging'][0]['message']['text'] = 'Hello world';
    metaIdentityCheck($diagnostic->inspect($hello)['message_shapes'][0]['text_matches_hello_world'] === true
        && $incoming['message_shapes'][0]['text_matches_hello_world'] === false, 'fixed_test_string_boolean_only');

    $echo = $payload;
    $echo['entry'][0]['messaging'][0]['sender']['id'] = '101';
    $echo['entry'][0]['messaging'][0]['recipient']['id'] = '998877665544';
    $echo['entry'][0]['messaging'][0]['message']['mid'] = 'PRIVATE_MID';
    $echo['entry'][0]['messaging'][0]['message']['is_echo'] = true;
    $echoReport = $diagnostic->inspect($echo);
    metaIdentityCheck($echoReport['kinds']['messenger:101:messaging:message'] === 1
        && $echoReport['message_shapes'] === [], 'ordinary_echo_counted_without_shape_noise');
    unset($echo['entry'][0]['messaging'][0]['message']['mid']);
    metaIdentityCheck($diagnostic->inspect($echo)['message_shapes'][0]['direction'] === 'outbound', 'unkeyed_echo_shape_visible');
    $standby = $diagnostic->inspect(metaIdentityPayload('page', '101', 'standby'));
    metaIdentityCheck($standby['message_shapes'][0]['source'] === 'standby'
        && $standby['kinds'] === ['messenger:101:standby:message' => 1], 'standby_preserved');
    $ig = $diagnostic->inspect(metaIdentityPayload('instagram', '202'));
    metaIdentityCheck($ig['message_shapes'][0]['account'] === 'instagram:202', 'instagram_separate_scope');
    $unknown = $diagnostic->inspect(metaIdentityPayload('page', '887766554433'));
    metaIdentityCheck($unknown === ['kinds' => [], 'changes' => [], 'message_shapes' => [], 'shapes_omitted' => 0], 'foreign_account_not_inspected');
    metaIdentitySafe($unknown);

    $other = $payload;
    $other['entry'][0]['messaging'] = [['read' => ['watermark' => 1]], ['PRIVATE_KEY' => 'PRIVATE_NAME'],
        ['delivery' => [], 'reaction' => ['PRIVATE_TEXT' => 'PRIVATE_CUSTOMER']]];
    $other['entry'][0]['changes'] = [['field' => 'comments', 'value' => ['PRIVATE_NAME' => 'PRIVATE_CUSTOMER']],
        ['field' => 'PRIVATE_KEY', 'value' => 'PRIVATE_TEXT']];
    $otherReport = $diagnostic->inspect($other);
    metaIdentityCheck($otherReport['kinds'] === ['messenger:101:messaging:read' => 1, 'messenger:101:messaging:OTHER' => 1,
        'messenger:101:messaging:delivery' => 1, 'messenger:101:messaging:reaction' => 1], 'non_message_kinds_fixed_whitelist');
    metaIdentityCheck($otherReport['changes'] === ['messenger:101:comments' => 1, 'messenger:101:OTHER' => 1], 'change_fields_fixed_whitelist');
    metaIdentitySafe($otherReport);
    $many = $payload; $many['entry'][0]['messaging'] = array_fill(0, 25, $payload['entry'][0]['messaging'][0]);
    $bounded = $diagnostic->inspect($many);
    metaIdentityCheck(count($bounded['message_shapes']) === 20 && $bounded['shapes_omitted'] === 5
        && $bounded['kinds']['messenger:101:messaging:message'] === 25, 'shape_cap_reports_omissions');
    foreach ([['object' => 'PRIVATE_NAME', 'entry' => []], ['object' => 'page', 'entry' => ['PRIVATE_KEY' => []]],
        ['object' => 'page', 'entry' => [['id' => 101]]], ['object' => 'page', 'entry' => array_fill(0, 101, ['id' => '101'])],
        ['object' => 'page', 'entry' => [['id' => '101', 'messaging' => array_fill(0, 1001, [])]]],
        ['object' => 'page', 'entry' => [['id' => '101', 'changes' => array_fill(0, 1001, [])]]]] as $bad)
        metaIdentityReject(static fn () => $diagnostic->inspect($bad), 'malformed_or_excessive_envelope_rejected');
    metaIdentityReject(static fn () => new MetaEventIdentityDiagnostic([$accounts[0], $accounts[0]]), 'duplicate_account_rejected');
    metaIdentityReject(static fn () => new MetaEventIdentityDiagnostic(array_fill(0, 11, $accounts[0])), 'account_count_bounded');
    metaIdentityReject(static fn () => new MetaEventIdentityDiagnostic([['channel' => 'messenger', 'account_id' => 101]]), 'numeric_account_not_coerced');

    $calls = [];
    $transport = static function (string $url, string $token) use (&$calls): array {
        $calls[] = ['url' => $url, 'token' => $token];
        if (strpos($url, '/debug_token?') !== false) return ['status' => 200, 'body' => json_encode(['data' => [
            'is_valid' => true, 'app_id' => '2177982783065133', 'type' => 'PAGE', 'profile_id' => '101',
            'user_id' => '998877665544', 'application' => 'PRIVATE_APP', 'scopes' => ['pages_messaging', 'PRIVATE_SCOPE'],
            'granular_scopes' => [['scope' => 'PRIVATE_SCOPE', 'target_ids' => ['887766554433']]],
        ]])];
        return ['status' => 200, 'body' => json_encode(strpos($url, 'graph.instagram.com') !== false
            ? ['id' => '202', 'name' => 'PRIVATE_NAME']
            : ['id' => '101', 'name' => 'PRIVATE_NAME', 'instagram_business_account' => ['id' => '303', 'name' => 'PRIVATE_NAME']])];
    };
    $graph = (new MetaEventIdentityDiagnostic($accounts, $transport))->graph(str_repeat('a', 32), 'v25.0');
    $page = $graph['accounts']['messenger:101'];
    metaIdentityCheck($graph['requests'] === 7 && count($calls) === 7, 'bounded_account_get_probes');
    metaIdentityCheck($page['token_debug']['token_valid'] === true && $page['token_debug']['app_matches'] === true
        && $page['token_debug']['profile_id_present'] === true && $page['token_debug']['profile_matches_configured_page'] === true,
        'app_validity_page_identity_booleans');
    metaIdentityCheck($page['token_debug']['token_type'] === 'PAGE' && $page['token_debug']['scopes']['pages_messaging'] === true
        && $page['token_debug']['scopes']['pages_show_list'] === false, 'token_type_scope_whitelist');
    metaIdentityCheck($page['token_asset']['matches_configured_account'] === true
        && $page['token_asset']['matches_configured_messenger_page'] === true
        && $page['linked_instagram']['matches_configured'] === true, 'asset_link_booleans');
    metaIdentityCheck($graph['accounts']['instagram:202']['token_debug']['status'] === 'FACEBOOK_DEBUG_NOT_APPLICABLE'
        && $graph['accounts']['instagram:202']['token_asset']['matches_configured_account'] === true, 'instagram_login_native_host');
    metaIdentityCheck($graph['accounts']['instagram:303']['token_asset']['matches_configured_account'] === null
        && $graph['accounts']['instagram:303']['linked_instagram']['matches_configured'] === true,
        'facebook_login_page_id_distinct_from_linked_ig');
    foreach ($calls as $call) {
        $parts = parse_url($call['url']);
        metaIdentityCheck(($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null,
            ['graph.facebook.com', 'graph.instagram.com'], true), 'fixed_https_graph_hosts');
        metaIdentityCheck(in_array($parts['path'] ?? null, ['/v25.0/debug_token', '/v25.0/me'], true), 'fixed_read_only_edges');
        if ($parts['path'] === '/v25.0/debug_token') {
            parse_str($parts['query'] ?? '', $query);
            metaIdentityCheck(isset($query['input_token']) && count($query) === 1
                && $call['token'] === '2177982783065133|'.str_repeat('a', 32), 'debug_uses_app_authorization');
        } else metaIdentityCheck(strpos($call['url'], 'PRIVATE_TOKEN') === false, 'asset_token_only_in_authorization');
    }
    metaIdentitySafe($graph);
    $mismatch = (new MetaEventIdentityDiagnostic([$accounts[0]], static function ($url) {
        return ['status' => 200, 'body' => json_encode(strpos($url, '/debug_token?') !== false ? ['data' => [
            'is_valid' => false, 'app_id' => '887766554433', 'type' => 'PRIVATE_TYPE', 'profile_id' => '998877665544',
            'scopes' => ['PRIVATE_SCOPE'],
        ]] : ['id' => '887766554433'])];
    }))->graph(str_repeat('a', 32), 'v25.0');
    $m = $mismatch['accounts']['messenger:101'];
    metaIdentityCheck($m['token_debug']['token_valid'] === false && $m['token_debug']['app_matches'] === false
        && $m['token_debug']['profile_matches_configured_page'] === false && $m['token_debug']['token_type'] === 'OTHER'
        && $m['token_asset']['matches_configured_account'] === false, 'identity_mismatch_preserved');
    metaIdentitySafe($mismatch);
    $failed = (new MetaEventIdentityDiagnostic([$accounts[0]], static fn () => ['status' => 400,
        'body' => json_encode(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'PRIVATE_ERROR PRIVATE_TOKEN',
            'error_user_title' => 'PRIVATE_NAME', 'fbtrace_id' => 'PRIVATE_CUSTOMER']])]))->graph(str_repeat('a', 32), 'v25.0');
    metaIdentityCheck($failed['accounts']['messenger:101']['token_debug']['status'] === 'API_REJECTED'
        && $failed['accounts']['messenger:101']['token_asset']['error_code'] === 100
        && $failed['accounts']['messenger:101']['token_asset']['error_subcode'] === 33, 'fixed_numeric_graph_error_only');
    metaIdentitySafe($failed);
    foreach ([['status' => 200, 'body' => 'PRIVATE_ERROR'], ['status' => 200, 'body' => str_repeat('x', 65537)],
        ['status' => 200, 'body' => 'null'], ['status' => 200, 'body' => json_encode(['data' => ['is_valid' => 'true']])]] as $response) {
        $invalid = (new MetaEventIdentityDiagnostic([$accounts[0]], static fn () => $response))->graph(str_repeat('a', 32), 'v25.0');
        metaIdentityCheck($invalid['accounts']['messenger:101']['token_debug']['status'] === 'INVALID_RESPONSE', 'invalid_graph_response_rejected');
        metaIdentitySafe($invalid);
    }
    $noSecret = (new MetaEventIdentityDiagnostic([$accounts[0]], $transport))->graph('PRIVATE_SECRET', 'v25.0');
    metaIdentityCheck($noSecret['requests'] === 1 && $noSecret['accounts']['messenger:101']['token_debug']['status'] === 'APP_SECRET_UNAVAILABLE',
        'missing_secret_skips_token_debug');
    $noToken = $accounts[0]; $noToken['access_token'] = "PRIVATE_TOKEN\n";
    $noCalls = 0;
    $credential = (new MetaEventIdentityDiagnostic([$noToken], static function () use (&$noCalls) { $noCalls++; throw new \RuntimeException('PRIVATE_ERROR'); }))
        ->graph(str_repeat('a', 32), 'v25.0');
    metaIdentityCheck($noCalls === 0 && $credential['requests'] === 0
        && $credential['accounts']['messenger:101']['token_asset']['status'] === 'CREDENTIAL_UNAVAILABLE', 'unsafe_credential_never_sent');
    $network = (new MetaEventIdentityDiagnostic([$accounts[0]], static function () { throw new \RuntimeException('PRIVATE_ERROR PRIVATE_TOKEN'); }))
        ->graph('', 'v25.0');
    metaIdentityCheck($network['accounts']['messenger:101']['token_asset']['status'] === 'TRANSPORT_FAILED', 'transport_exception_fixed_category');
    metaIdentitySafe($network);
    $linkFailure = (new MetaEventIdentityDiagnostic([$accounts[0], $accounts[2]], static function ($url) {
        return strpos($url, 'instagram_business_account') !== false
            ? ['status' => 400, 'body' => json_encode(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'PRIVATE_ERROR']])]
            : ['status' => 200, 'body' => json_encode(['id' => '101'])];
    }))->graph('', 'v25.0');
    metaIdentityCheck($linkFailure['accounts']['messenger:101']['token_asset']['status'] === 'READY'
        && $linkFailure['accounts']['messenger:101']['token_asset']['matches_configured_account'] === true
        && $linkFailure['accounts']['messenger:101']['linked_instagram']['status'] === 'API_REJECTED',
        'optional_link_failure_does_not_obscure_page_identity');
    metaIdentitySafe($linkFailure);
    metaIdentityReject(static fn () => $diagnostic->graph('', 'v25.0/PRIVATE_URL'), 'version_not_path_injectable');
    $more = []; for ($i = 1; $i <= 6; $i++) $more[] = ['channel' => 'messenger', 'account_id' => (string) $i, 'access_token' => 'PRIVATE_TOKEN'];
    $capped = (new MetaEventIdentityDiagnostic($more, $transport))->graph(str_repeat('a', 32), 'v25.0');
    metaIdentityCheck($capped['accounts_omitted'] === 2 && count($capped['accounts']) === 4 && $capped['requests'] === 8, 'graph_account_cap_explicit');

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
        $schema->create('meta_webhook_events', static function ($t) { $t->increments('id'); $t->text('payload'); });
        $schema->create('meta_inbox_ingestion_failures', static function ($t) { $t->integer('event_id'); $t->string('reason'); });
        $schema->create('meta_inbox_conversations', static function ($t) { $t->increments('id'); $t->string('waba_id'); $t->string('phone_number_id'); $t->string('peer_hash'); });
        $facade = \Illuminate\Support\Facades\DB::class; $crypt = \Illuminate\Support\Facades\Crypt::class;
        $peerHash = hash_hmac('sha256', 'whatsapp-peer-v1:messenger:101:user:998877665544', $crypt::getKey());
        $facade::table('meta_inbox_conversations')->insert(['waba_id' => 'messenger', 'phone_number_id' => '101', 'peer_hash' => $peerHash]);
        $foreignPeerHash = hash_hmac('sha256', 'whatsapp-peer-v1:messenger:101:user:887766554433', $crypt::getKey());
        $facade::table('meta_inbox_conversations')->insert(['waba_id' => 'messenger', 'phone_number_id' => '887766554433', 'peer_hash' => $foreignPeerHash]);
        $unmatched = $payload; $unmatched['entry'][0]['messaging'][0]['sender']['id'] = '887766554433';
        foreach ([$payload, $unmatched, $alternate] as $event)
            $facade::table('meta_webhook_events')->insert(['payload' => $crypt::encryptString(json_encode($event))]);
        $facade::table('meta_webhook_events')->insert(['payload' => 'PRIVATE_TOKEN_CORRUPT']);
        $facade::table('meta_inbox_ingestion_failures')->insert(['event_id' => 1, 'reason' => 'INVALID_EVENT']);
        $snapshot = static function () use ($facade): string {
            $out = []; foreach (['meta_webhook_events', 'meta_inbox_ingestion_failures', 'meta_inbox_conversations'] as $table)
                $out[$table] = $facade::table($table)->get()->toArray();
            return json_encode($out);
        };
        $before = $snapshot();
        $local = $diagnostic->local();
        metaIdentityCheck($before === $snapshot(), 'all_local_rows_unchanged');
        metaIdentityCheck($local['events_read'] === 4 && $local['through_event'] === 4
            && $local['unreadable_events'] === 1 && $local['sample_complete'] === true, 'readable_corrupt_capture_counts');
        $byEvent = []; foreach ($local['message_shapes'] as $shape) $byEvent[$shape['event_id']] = $shape;
        metaIdentityCheck($byEvent[1]['peer_matches_projected_sample'] === true && $byEvent[1]['quarantined'] === true
            && $byEvent[2]['peer_matches_projected_sample'] === false && $byEvent[2]['quarantined'] === false,
            'scoped_hmac_peer_matches_and_quarantine_boolean');
        metaIdentityCheck($local['projected_peer_samples']['messenger:101'] === ['limit' => 100, 'read' => 1, 'conversations' => 1, 'complete' => true]
            && $local['projected_peer_samples']['instagram:202']['conversations'] === 0
            && $local['projected_peer_samples']['instagram:202']['complete'] === true, 'projected_peer_sample_counts_scoped');
        metaIdentitySafe($local);
        for ($i = 0; $i < 52; $i++) $facade::table('meta_webhook_events')->insert(['payload' => $crypt::encryptString(json_encode($payload))]);
        $before = $snapshot(); $boundedLocal = $diagnostic->local();
        metaIdentityCheck($boundedLocal['events_in_range'] === 56 && $boundedLocal['events_read'] === 50
            && $boundedLocal['through_event'] === 56 && $boundedLocal['sample_complete'] === false
            && count($boundedLocal['message_shapes']) === 20 && $boundedLocal['shapes_omitted'] === 30,
            'latest_event_and_global_shape_bounds');
        metaIdentityCheck($before === $snapshot(), 'bounded_local_rows_unchanged');
        $db->getConnection()->enableQueryLog(); $db->getConnection()->flushQueryLog(); $diagnostic->local();
        $queries = $db->getConnection()->getQueryLog();
        metaIdentityCheck(count($queries) > 0 && !array_filter($queries,
            static fn ($q) => !preg_match('/\A\s*select\b/i', $q['query'])),
            'local_performs_no_mutation_queries');
        $db->getConnection()->disableQueryLog();
        metaIdentitySafe($boundedLocal);
        // An absent peer match in a bounded projected sample must not imply full-history absence.
        for ($i = 0; $i < 101; $i++) $facade::table('meta_inbox_conversations')->insert([
            'waba_id' => 'messenger', 'phone_number_id' => '101', 'peer_hash' => hash('sha256', 'fixture-peer-'.$i)]);
        $before = $snapshot(); $peerBounded = $diagnostic->local();
        metaIdentityCheck($peerBounded['projected_peer_samples']['messenger:101'] === ['limit' => 100, 'read' => 100, 'conversations' => 102, 'complete' => false]
            && $peerBounded['message_shapes'][0]['peer_matches_projected_sample'] === false, 'projected_peer_bound_and_incomplete_explicit');
        metaIdentityCheck($before === $snapshot(), 'projected_sample_rows_unchanged');
        // A legal near-limit plaintext expands during encryption; its ciphertext must remain readable.
        $large = $payload; $large['entry'][0]['messaging'][0]['message']['text'] = str_repeat('x', 4190000);
        $largePlain = json_encode($large, JSON_THROW_ON_ERROR); $largeEncrypted = $crypt::encryptString($largePlain);
        metaIdentityCheck(strlen($largePlain) <= 4194304 && strlen($largeEncrypted) > 4194304, 'fixture_ciphertext_expands_beyond_plaintext_cap');
        $largeId = $facade::table('meta_webhook_events')->insertGetId(['payload' => $largeEncrypted]);
        $largeLocal = $diagnostic->local();
        metaIdentityCheck($largeLocal['unreadable_events'] === 0 && $largeLocal['message_shapes'][0]['event_id'] === $largeId
            && $largeLocal['message_shapes'][0]['field_types']['text'] === 'string', 'legal_plaintext_not_rejected_for_ciphertext_expansion');
        $large['entry'][0]['messaging'][0]['message']['text'] = str_repeat('x', 4194305);
        $facade::table('meta_webhook_events')->insert(['payload' => $crypt::encryptString(json_encode($large, JSON_THROW_ON_ERROR))]);
        $oversizedLocal = $diagnostic->local();
        metaIdentityCheck($oversizedLocal['unreadable_events'] === 1, 'plaintext_byte_cap_enforced_after_decryption');
        metaIdentitySafe($largeLocal);
        $databaseChecks = true;
    }
    echo 'Meta event identity diagnostic: '.$checks.' checks passed; database fixtures '.($databaseChecks ? 'passed' : 'not available').".\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'META_EVENT_IDENTITY_DIAGNOSTIC_TEST_FAILED '.$error->getMessage()."\n"); exit(1);
}
