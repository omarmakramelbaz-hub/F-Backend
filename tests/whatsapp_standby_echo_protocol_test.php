<?php
// Pure official-shape fixtures. No network, customer database or application boot.
require __DIR__ . '/../app/Support/WhatsAppInboxProtocol.php';
use App\Support\WhatsAppInboxProtocol as Protocol;
$checks = 0;
function standbyCheck(bool $ok, string $label): void {
    global $checks; $checks++; if (!$ok) throw new RuntimeException($label);
}
function standbyEcho(array $inner = [], array $outer = []): array {
    return array_replace(['id' => 'wamid.standby.text', 'timestamp' => '1691583200',
        'message' => array_replace(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
            'to' => '201000000001', 'type' => 'text', 'text' => ['body' => 'Synthetic Agent echo', 'preview_url' => true],
            'context' => ['message_id' => 'wamid.synthetic.previous']], $inner)], $outer);
}
function standbyPayload(array $records, array $channel = []): array {
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269',
        'changes' => [['field' => 'standby', 'value' => ['messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
            'standby' => array_replace(['message_echoes' => $records], $channel)]]]]]];
}
function standbyReject(array $payload, string $label): void {
    $r = Protocol::report($payload);
    standbyCheck($r['messages'] === [] && $r['quarantined_count'] > 0, $label);
}
try {
    $p = standbyPayload([standbyEcho()]); $r = Protocol::report($p); $m = $r['messages'][0] ?? [];
    standbyCheck(count($r['messages']) === 1 && $r['quarantined_count'] === 0, 'official wrapper accepted');
    standbyCheck($m['direction'] === 'outbound' && $m['source'] === 'standby', 'native standby provenance');
    standbyCheck($m['message_id'] === 'wamid.standby.text' && $m['sent_at'] === gmdate('Y-m-d H:i:s', 1691583200), 'outer ID/time authoritative');
    standbyCheck($m['peer_identity'] === 'phone:201000000001' && $m['peer_user_id'] === null, 'phone peer not invented or borrowed');
    standbyCheck($m['text'] === 'Synthetic Agent echo' && !isset($m['author']), 'literal text with no Agent author attribution');
    standbyCheck($m['content']['message']['context'] === ['message_id' => 'wamid.synthetic.previous']
        && $m['content']['message']['text']['preview_url'] === true, 'send request provenance preserved');
    standbyCheck(!isset($m['content']['message']['from']), 'sender not manufactured');
    $p['entry'][0]['changes'][0]['value']['metadata'] = ['phone_number_id' => '515388018324075'];
    standbyCheck(count(Protocol::messages($p)) === 1, 'scoped asset does not require display-phone sender');
    $p = standbyPayload([standbyEcho(['from' => '201285545554'])]);
    standbyCheck(count(Protocol::messages($p)) === 1, 'explicit matching business sender accepted');
    $p = standbyPayload([standbyEcho(['id' => 'wamid.standby.text', 'timestamp' => 1691583200])]);
    standbyCheck(count(Protocol::messages($p)) === 1, 'matching duplicate inner ID/time accepted');

    foreach ([['messaging_product' => 'messenger'], ['messaging_product' => null], ['recipient_type' => 'group'],
        ['id' => 'wamid.other'], ['timestamp' => '1691583201'], ['timestamp' => true], ['type' => 'template', 'template' => ['name' => 'unrendered']],
        ['type' => 'image', 'image' => ['id' => 'synthetic.media']], ['type' => 'interactive', 'interactive' => ['type' => 'flow']],
        ['to' => '201285545554'], ['to' => 'bad'], ['to_user_id' => ' '], ['from' => '201999999999'],
        ['recipient_id' => '201000000002'], ['recipient_user_id' => 'US.other'], ['sender' => ['id' => '201285545554']],
        ['recipient' => ['id' => '201000000002']], ['from_user_id' => 'US.sender'], ['message' => ['to' => '201000000002']],
        ['statuses' => []], ['image' => ['id' => 'ambiguous.body']], ['text' => ['body' => 123]], ['text' => ['body' => str_repeat('x', 65537)]]]
        as $bad) standbyReject(standbyPayload([standbyEcho($bad)]), 'invalid inner metadata/type/identity/body');
    foreach ([['id' => ''], ['timestamp' => null], ['timestamp' => '1e9'], ['timestamp' => '0'], ['timestamp' => time() + 90000],
        ['message' => null], ['message' => []], ['message' => [['type' => 'text']]], ['type' => 'text'],
        ['to' => '201000000002'], ['from' => '201285545554'], ['text' => ['body' => 'different']],
        ['template' => ['name' => 'unhydrated']], ['padding' => str_repeat('x', 262144)]] as $bad)
        standbyReject(standbyPayload([standbyEcho([], $bad)]), 'invalid wrapper or original record bound');
    foreach (['messages', 'statuses', 'message_echo', 'unknown_channel'] as $key)
        standbyReject(standbyPayload([standbyEcho()], [$key => []]), 'mixed/unknown standby channel held');
    $p = standbyPayload([standbyEcho()]); $p['entry'][0]['changes'][0]['value']['messages'] = [];
    standbyReject($p, 'mixed top level channel held');
    $p = standbyPayload([standbyEcho()]); $p['entry'][0]['changes'][0]['value']['standby']['metadata'] = ['phone_number_id' => 'foreign'];
    standbyReject($p, 'nested scope conflict held');
    $p = standbyPayload([standbyEcho()]); $p['entry'][0]['id'] = '111111111111';
    standbyCheck(Protocol::messages($p) === [] && Protocol::report($p)['ignored_count'] === 1, 'foreign WABA ignored');
    $p = standbyPayload([standbyEcho()]); $p['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '222222222222';
    standbyCheck(Protocol::messages($p) === [] && Protocol::report($p)['ignored_count'] === 1, 'foreign phone asset ignored');
    foreach (['messages', 'smb_message_echoes'] as $source) {
        $p = standbyPayload([standbyEcho()]); $v =& $p['entry'][0]['changes'][0]; $v['field'] = $source;
        $v['value']['message_echoes'] = $v['value']['standby']['message_echoes']; unset($v['value']['standby']);
        standbyReject($p, 'wrapper never unwrapped in different field'); unset($v);
    }
    $p = standbyPayload([standbyEcho()]); $v =& $p['entry'][0]['changes'][0]['value'];
    $v['message_echoes'] = $v['standby']['message_echoes']; unset($v['standby']);
    standbyReject($p, 'direct standby wrapper not guessed'); unset($v);
    $p = standbyPayload([]); $p['entry'][0]['changes'][0]['value']['standby'] = ['messages' => [standbyEcho()]];
    standbyReject($p, 'inbound wrapper not guessed');

    $wrapped = standbyEcho(['to_user_id' => 'US.synthetic']);
    $flat = $wrapped['message'] + ['id' => $wrapped['id'], 'timestamp' => $wrapped['timestamp']];
    $flat['from'] = '201285545554';
    $p = standbyPayload([$wrapped]);
    $p['entry'][0]['changes'][] = ['field' => 'smb_message_echoes', 'value' => ['messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'], 'message_echoes' => [$flat]]];
    $r = Protocol::report($p);
    standbyCheck(count($r['messages']) === 1 && $r['quarantined_count'] === 0
        && $r['messages'][0]['peer_identity'] === 'user:US.synthetic'
        && $r['messages'][0]['content']['sources'] === ['standby', 'smb_message_echoes'], 'exact paired flat/wrapped duplicate dedup');
    $different = $p; $different['entry'][0]['changes'][1]['value']['message_echoes'][0]['to'] = '201000000002';
    standbyReject($different, 'cross-peer same native ID quarantined');
    $different = $p; unset($different['entry'][0]['changes'][1]['value']['message_echoes'][0]['text']['preview_url']);
    standbyReject($different, 'preview flag body difference preserves strict conflict policy');
    $different = $p; unset($different['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['message']['to_user_id']);
    unset($different['entry'][0]['changes'][1]['value']['message_echoes'][0]['to']);
    standbyReject($different, 'disjoint phone/user aliases never merged');
    $p = standbyPayload([standbyEcho(), standbyEcho(['to' => '201000000002'], ['id' => 'wamid.other.peer'])]);
    standbyCheck(count(Protocol::messages($p)) === 2, 'equal text never links different recipients');
    $p = standbyPayload([standbyEcho(['to_user_id' => 'US.synthetic'])], ['contacts' => [['wa_id' => '201000000001', 'user_id' => 'US.other']]]);
    standbyReject($p, 'contradictory contact bridge held');
    echo "WHATSAPP_STANDBY_ECHO_PROTOCOL_CHECKS=$checks\n";
} catch (Throwable $e) { fwrite(STDERR, 'STANDBY_ECHO_TEST_FAILED: ' . $e->getMessage() . "\n"); exit(1); }
