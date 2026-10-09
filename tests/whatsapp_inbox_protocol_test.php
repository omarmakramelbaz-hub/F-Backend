<?php

require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';

use App\Support\WhatsAppInboxProtocol as Protocol;

define('TEST_NOW', time());
$checks = 0;
function expect($actual, $expected, string $label): void
{
    global $checks;
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException('FAILED: ' . $label);
    }
}

function message(array $overrides = []): array
{
    return array_replace([
        'id' => 'wamid.test001', 'timestamp' => (string) (TEST_NOW - 120),
        'from' => '201000000001', 'from_user_id' => 'US.TEST_ONE',
        'type' => 'text', 'text' => ['body' => 'WA-0101 test only'],
    ], $overrides);
}

function value(array $overrides = []): array
{
    return array_replace([
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
        'messages' => [message()],
    ], $overrides);
}

function payload(string $field = 'messages', ?array $body = null): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [
        ['id' => '468336579702269', 'changes' => [['field' => $field, 'value' => $body ?? value()]]],
    ]];
}

function echoValue(array $overrides = []): array
{
    $body = value();
    unset($body['messages']);
    $body['message_echoes'] = [[
        'id' => 'wamid.echo001', 'timestamp' => (string) (TEST_NOW - 60),
        'from' => '201285545554', 'to' => '+201000000001', 'to_user_id' => 'US.TEST_ONE',
        'type' => 'text', 'text' => ['body' => 'WA-0101 acknowledged'],
    ]];
    return array_replace($body, $overrides);
}

try {
    $report = Protocol::report(payload());
    expect($report['quarantined_count'], 0, 'valid incoming');
    expect(count($report['messages']), 1, 'one incoming');
    $incoming = $report['messages'][0];
    expect($incoming['peer_identity'], 'user:US.TEST_ONE', 'opaque BUID preferred');
    expect($incoming['peer_phone'], '201000000001', 'phone retained');
    expect($incoming['direction'], 'inbound', 'incoming direction');
    expect($incoming['sent_at'], gmdate('Y-m-d H:i:s', TEST_NOW - 120), 'UTC timestamp');
    expect($incoming['content']['sources'], ['messages'], 'source evidence');
    expect(Protocol::messages(payload()), $report['messages'], 'messages convenience API');

    $echo = Protocol::messages(payload('smb_message_echoes', echoValue()))[0];
    expect($echo['direction'], 'outbound', 'echo direction');
    expect($echo['peer_identity'], $incoming['peer_identity'], 'explicit same peer');
    expect(isset($echo['author']), false, 'outbound never attributed to Agent');
    expect($echo['source'], 'smb_message_echoes', 'echo source');

    $phoneOnly = message();
    unset($phoneOnly['from_user_id']);
    $bridge = value(['messages' => [$phoneOnly], 'contacts' => [
        ['wa_id' => '201999999999', 'user_id' => 'US.UNRELATED', 'profile' => ['name' => 'Same name']],
        ['wa_id' => '201000000001', 'user_id' => 'US.TEST_ONE', 'profile' => ['name' => 'Customer One']],
    ]]);
    $bridged = Protocol::messages(payload('messages', $bridge))[0];
    expect($bridged['peer_identity'], 'user:US.TEST_ONE', 'explicit paired contact bridge');
    expect($bridged['customer_name'], 'Customer One', 'name follows matching identity not list position');
    $bridge['contacts'] = [['wa_id' => '201999999999', 'user_id' => 'US.UNRELATED', 'profile' => ['name' => 'Same name']]];
    expect(Protocol::messages(payload('messages', $bridge))[0]['peer_identity'], 'phone:201000000001', 'unrelated contacts cannot bridge');
    $onlyUser = message();
    unset($onlyUser['from']);
    expect(Protocol::messages(payload('messages', value(['messages' => [$onlyUser]])))[0]['peer_phone'], null, 'BUID-only no phone invention');

    $conflict = value(['contacts' => [['wa_id' => '201000000001', 'user_id' => 'US.OTHER']]]);
    expect(Protocol::report(payload('messages', $conflict))['quarantined_count'], 1, 'contradictory direct contact quarantined');
    $twoBridges = value(['messages' => [$phoneOnly], 'contacts' => [
        ['wa_id' => '201000000001', 'user_id' => 'US.TEST_ONE'],
        ['wa_id' => '201000000001', 'user_id' => 'US.OTHER'],
    ]]);
    expect(Protocol::messages(payload('messages', $twoBridges)), [], 'ambiguous bridges rejected');
    $pairConflict = value(['messages' => [message(), message(['id' => 'wamid.test002', 'from' => '201000000002'])]]);
    expect(Protocol::report(payload('messages', $pairConflict))['quarantined_count'], 2, 'contradictory same BUID pairs in one payload');

    $differentPeerEcho = echoValue();
    $differentPeerEcho['message_echoes'][0]['to'] = '201000000002';
    $differentPeerEcho['message_echoes'][0]['to_user_id'] = 'US.TEST_TWO';
    $twoPeers = payload();
    $twoPeers['entry'][0]['changes'][] = ['field' => 'smb_message_echoes', 'value' => $differentPeerEcho];
    expect(count(Protocol::messages($twoPeers)), 2, 'same test text is not a linking rule');
    expect(Protocol::messages($twoPeers)[1]['peer_identity'], 'user:US.TEST_TWO', 'distinct test peer remains distinct');

    $foreign = payload();
    $foreign['entry'][0]['id'] = '1636131124838697';
    expect(Protocol::report($foreign)['ignored_count'], 1, 'test WABA excluded');
    expect(Protocol::messages($foreign), [], 'test WABA no messages');
    $foreign = payload();
    $foreign['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '999999999999999';
    expect(Protocol::report($foreign)['ignored_count'], 1, 'foreign phone excluded');
    expect(Protocol::messages($foreign), [], 'foreign phone no messages');
    $foreign['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 515388018324075;
    expect(Protocol::report($foreign)['quarantined_count'], 1, 'scope type not coerced');
    $custom = payload();
    $custom['entry'][0]['id'] = '111111111111111';
    $custom['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '222222222222222';
    expect(count(Protocol::messages($custom, '111111111111111', '222222222222222')), 1, 'explicit alternate scope API');

    $status = value();
    unset($status['messages']);
    $status['statuses'] = [['id' => 'wamid.test001', 'status' => 'read', 'timestamp' => (string) TEST_NOW]];
    $statusReport = Protocol::report(payload('messages', $status));
    expect($statusReport['status_count'], 1, 'status-only counted');
    expect($statusReport['messages'], [], 'status not converted to message');
    expect($statusReport['quarantined_count'], 0, 'legitimate status-only not quarantined');
    expect(Protocol::report(payload('smb_message_echoes', $status))['quarantined_count'], 1, 'wrong status envelope quarantined');
    expect(Protocol::report(payload('messaging_handovers', value()))['ignored_count'], 1, 'handover not chat content');

    $standby = value();
    expect(Protocol::messages(payload('standby', $standby))[0]['source'], 'standby', 'direct scoped standby');
    $nested = $standby;
    unset($nested['messages']);
    $nested['standby'] = ['messages' => [message()]];
    expect(count(Protocol::messages(payload('standby', $nested))), 1, 'nested scoped standby');
    $nested['standby'] = ['message_echoes' => echoValue()['message_echoes']];
    expect(Protocol::messages(payload('standby', $nested))[0]['direction'], 'outbound', 'standby echo direction');
    $nested['standby']['metadata'] = ['phone_number_id' => '999999999999999'];
    expect(Protocol::report(payload('standby', $nested))['quarantined_count'], 1, 'conflicting nested scope quarantined');
    $legacy = ['object' => 'page', 'entry' => [['id' => '468336579702269', 'standby' => [message()]]]];
    expect(Protocol::messages($legacy), [], 'Messenger standby never treated as WhatsApp');
    $mixed = payload();
    $mixed['entry'][0]['standby'] = [message()];
    expect(Protocol::report($mixed)['quarantined_count'], 1, 'unsupported known entry standby retained for review');
    $nested = value();
    $nested['standby'] = ['messages' => [message()]];
    expect(Protocol::report(payload('standby', $nested))['quarantined_count'], 1, 'ambiguous mixed standby shape quarantined');
    expect(Protocol::report(['object' => 'whatsapp_business_account', 'entry' => null])['quarantined_count'], 1, 'malformed entry reported');
    $badMetadata = value(['metadata' => []]);
    expect(Protocol::report(payload('messages', $badMetadata))['quarantined_count'], 1, 'missing phone scope reported');

    foreach ([['messages', null], ['contacts', null], ['messages', ['not_a_list' => message()]], ['messages', []]] as [$key, $bad]) {
        expect(Protocol::report(payload('messages', value([$key => $bad])))['quarantined_count'], 1, 'malformed field ' . $key);
    }
    foreach ([['id' => ''], ['id' => "bad\nid"], ['timestamp' => '1e9'], ['timestamp' => '-1'], ['timestamp' => 0], ['timestamp' => TEST_NOW + 86460], ['timestamp' => 253402300800], ['type' => 'text', 'text' => ['body' => 123]], ['from' => 'invalid'], ['from_user_id' => ' ']] as $bad) {
        expect(Protocol::messages(payload('messages', value(['messages' => [message($bad)]]))), [], 'malformed known message rejected');
        expect(Protocol::report(payload('messages', value(['messages' => [message($bad)]])))['quarantined_count'], 1, 'malformed known message reported');
    }
    $noPeer = message();
    unset($noPeer['from'], $noPeer['from_user_id']);
    expect(Protocol::messages(payload('messages', value(['messages' => [$noPeer]]))), [], 'identity cannot come from unpaired contact');
    $businessPeer = message(['from' => '201285545554']);
    expect(Protocol::messages(payload('messages', value(['messages' => [$businessPeer]]))), [], 'business cannot be customer');
    $badEcho = echoValue();
    $badEcho['message_echoes'][0]['from'] = '201999999999';
    expect(Protocol::messages(payload('smb_message_echoes', $badEcho)), [], 'foreign echo sender rejected');

    $image = message(['type' => 'image', 'image' => ['id' => 'media001', 'caption' => 'menu']]);
    unset($image['text']);
    expect(Protocol::messages(payload('messages', value(['messages' => [$image]])))[0]['text'], null, 'media not manufactured as text');
    expect(Protocol::messages(payload('messages', value(['messages' => [$image]])))[0]['content']['message']['image']['id'], 'media001', 'media content preserved');
    $badImage = $image;
    $badImage['image'] = 'bad';
    expect(Protocol::report(payload('messages', value(['messages' => [$badImage]])))['quarantined_count'], 1, 'malformed media quarantined');
    $badImage['image'] = [];
    expect(Protocol::report(payload('messages', value(['messages' => [$badImage]])))['quarantined_count'], 1, 'empty media content quarantined');
    $location = message(['type' => 'location', 'location' => ['latitude' => 30.05, 'longitude' => 31.24]]);
    unset($location['text']);
    expect(count(Protocol::messages(payload('messages', value(['messages' => [$location]])))), 1, 'valid coordinates retained');
    $location['location']['latitude'] = 100;
    expect(Protocol::report(payload('messages', value(['messages' => [$location]])))['quarantined_count'], 1, 'invalid coordinates quarantined');
    $unknown = message(['type' => 'unknown', 'errors' => [['code' => 131051]]]);
    unset($unknown['text']);
    expect(count(Protocol::messages(payload('messages', value(['messages' => [$unknown]])))), 1, 'unsupported message evidence retained');
    $future = message(['type' => 'future_type', 'future_type' => ['value' => 'new']]);
    unset($future['text']);
    expect(Protocol::report(payload('messages', value(['messages' => [$future]])))['quarantined_count'], 1, 'future message type retained for review');

    $duplicates = payload();
    $duplicates['entry'][0]['changes'][] = ['field' => 'standby', 'value' => value()];
    expect(count(Protocol::messages($duplicates)), 1, 'same-ID copies deduplicated');
    expect(Protocol::messages($duplicates)[0]['content']['sources'], ['messages', 'standby'], 'duplicate sources merged');
    $enrichment = payload('standby', value(['messages' => [$phoneOnly]]));
    $enrichment['entry'][0]['changes'][] = ['field' => 'messages', 'value' => value(['messages' => [message()], 'contacts' => [['wa_id' => '201000000001', 'user_id' => 'US.TEST_ONE', 'profile' => ['name' => 'One']]]])];
    $enriched = Protocol::messages($enrichment);
    expect(count($enriched), 1, 'same-ID phone-only plus paired BUID copy deduplicated');
    expect($enriched[0]['peer_identity'], 'user:US.TEST_ONE', 'compatible paired copy enriches BUID');
    expect($enriched[0]['customer_name'], 'One', 'compatible copy enriches name');
    expect($enriched[0]['source'], 'messages', 'messages preferred when available');
    expect($enriched[0]['content']['sources'], ['messages', 'standby'], 'enriched source union');
    $unpairedCopies = payload('messages', value(['messages' => [$phoneOnly, $onlyUser]]));
    expect(Protocol::report($unpairedCopies)['quarantined_count'], 2, 'disjoint same-ID aliases without explicit pair quarantined');
    expect(Protocol::report(payload('messages', value(['messages' => [message(['text' => ['body' => str_repeat('x', 65537)]])]])))['quarantined_count'], 1, 'bounded text');
    $oversized = message(['padding' => str_repeat('x', 262144)]);
    expect(Protocol::report(payload('messages', value(['messages' => [$oversized]])))['quarantined_count'], 1, 'bounded full record');
    $duplicates['entry'][0]['changes'][1]['value']['messages'][0]['text']['body'] = 'different';
    expect(Protocol::report($duplicates)['quarantined_count'], 2, 'contradictory copies quarantined');
    $duplicates = payload('messages', value(['messages' => [$image, array_replace($image, ['image' => ['caption' => 'menu', 'id' => 'media001']])]]));
    expect(Protocol::report($duplicates)['quarantined_count'], 0, 'JSON object key order not conflict');
    $duplicates['entry'][0]['changes'][0]['value']['messages'][1]['image']['caption'] = 'different';
    expect(Protocol::report($duplicates)['quarantined_count'], 2, 'contradictory media copies quarantined');
    $userOnlyA = message();
    unset($userOnlyA['from']);
    $userOnlyB = array_replace($userOnlyA, ['from_user_id' => 'US.TEST_TWO']);
    $duplicates = payload('messages', value(['messages' => [$phoneOnly, $userOnlyA, $userOnlyB]]));
    expect(Protocol::report($duplicates)['quarantined_count'], 3, 'contradictory later same-ID aliases not hidden by first missing BUID');

    $before = payload();
    $copy = $before;
    Protocol::report($copy);
    expect($copy, $before, 'pure parser does not mutate raw envelope');
    echo 'WhatsApp inbox protocol: ' . $checks . " checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
