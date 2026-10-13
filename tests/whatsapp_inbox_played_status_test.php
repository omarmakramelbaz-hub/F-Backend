<?php
/** Pure protocol regressions: played is a receipt, never conversation or order evidence. */
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';

use App\Support\WhatsAppInboxProtocol as Protocol;

$checks = 0;
function playedExpect($actual, $expected, string $label): void
{
    global $checks;
    $checks++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function playedValue(): array
{
    return [
        'messaging_product' => 'whatsapp',
        'metadata' => ['phone_number_id' => '515388018324075'],
        'contacts' => [['wa_id' => '201000000001', 'user_id' => 'US.played-fixture']],
        'statuses' => [['id' => 'wamid.PLAYED.01', 'status' => 'played']],
    ];
}
function playedPayload(array $value, string $source = 'messages'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '468336579702269', 'changes' => [['field' => $source, 'value' => $value]],
    ]]];
}
function playedReport(array $payload, int $statuses, int $quarantined, int $ignored, int $messages, string $label): array
{
    $before = json_encode($payload, JSON_THROW_ON_ERROR);
    $report = Protocol::report($payload);
    playedExpect($report['status_count'], $statuses, $label . ' receipt count');
    playedExpect($report['quarantined_count'], $quarantined, $label . ' quarantine count');
    playedExpect($report['ignored_count'], $ignored, $label . ' ignored count');
    playedExpect(count($report['messages']), $messages, $label . ' message count');
    playedExpect(json_encode($payload, JSON_THROW_ON_ERROR), $before, $label . ' raw input unchanged');
    return $report;
}

$report = playedReport(playedPayload(playedValue()), 1, 0, 0, 0, 'observed played receipt with absent profile');
playedExpect($report, ['messages' => [], 'quarantined_count' => 0, 'ignored_count' => 0, 'status_count' => 1],
    'played does not create a name, native message, read mapping or order evidence');

$value = playedValue(); unset($value['contacts']);
playedReport(playedPayload($value), 1, 0, 0, 0, 'played without contacts');
$value = playedValue(); $value['contacts'][0]['profile'] = ['name' => 'عميل الاختبار'];
playedReport(playedPayload($value), 1, 0, 0, 0, 'played with valid existing profile');

foreach (['sent', 'delivered', 'read', 'failed', 'deleted'] as $status) {
    $value = playedValue(); $value['statuses'][0]['status'] = $status;
    playedReport(playedPayload($value), 1, 0, 0, 0, 'existing ' . $status);
    playedReport(playedPayload($value, 'standby'), 1, 0, 0, 0, 'existing standby ' . $status);
}
$value = playedValue(); $value['statuses'][] = ['id' => 'wamid.SENT.02', 'status' => 'sent'];
playedReport(playedPayload($value), 2, 0, 0, 0, 'multiple valid status-only receipts');

foreach ([null, 7, [], '', 'invalid id', "invalid\nline", str_repeat('a', 513)] as $id) {
    $value = playedValue(); $value['statuses'][0]['id'] = $id;
    playedReport(playedPayload($value), 0, 1, 0, 0, 'invalid played receipt identifier');
}
$value = playedValue(); unset($value['statuses'][0]['id']);
playedReport(playedPayload($value), 0, 1, 0, 0, 'missing played identifier');
foreach ([null, true, 1, [], 'PLAYED', 'playing', 'unknown'] as $status) {
    $value = playedValue(); $value['statuses'][0]['status'] = $status;
    playedReport(playedPayload($value), 0, 1, 0, 0, 'invalid played status');
}
foreach ([null, 'statuses', ['named' => ['id' => 'wamid.PLAYED.01', 'status' => 'played']]] as $statuses) {
    $value = playedValue(); $value['statuses'] = $statuses;
    playedReport(playedPayload($value), 0, 1, 0, 0, 'invalid status list');
}
$value = playedValue(); $value['statuses'] = ['not a status record'];
playedReport(playedPayload($value), 0, 1, 0, 0, 'invalid status record');

playedReport(playedPayload(playedValue(), 'standby'), 0, 1, 0, 0, 'played is not accepted as standby content');
playedReport(playedPayload(playedValue(), 'smb_message_echoes'), 0, 1, 0, 0, 'played is not a message echo');
$value = playedValue(); $value['messages'] = [];
playedReport(playedPayload($value), 0, 2, 0, 0, 'played with an empty message channel is not status-only');
$value = playedValue(); $value['message_echoes'] = [];
playedReport(playedPayload($value), 0, 2, 0, 0, 'played with an echo channel is not status-only');
$value = playedValue(); $value['messages'] = [[
    'id' => 'wamid.INBOUND.03', 'from' => '201000000001', 'timestamp' => (string) (time() - 60),
    'type' => 'text', 'text' => ['body' => 'رسالة اختبار'],
]];
playedReport(playedPayload($value), 0, 1, 0, 1, 'played cannot authorize a mixed incoming-message channel');
$value['statuses'][0]['status'] = 'sent';
playedReport(playedPayload($value), 1, 0, 0, 1, 'existing mixed sent receipt remains valid');

foreach ([null, 'contact', ['named' => ['wa_id' => '201000000001']]] as $contacts) {
    $value = playedValue(); $value['contacts'] = $contacts;
    playedReport(playedPayload($value), 0, 1, 0, 0, 'invalid played contact list');
}
foreach ([['wa_id' => 'bad-phone'], ['user_id' => "invalid user"], ['profile' => null],
    ['profile' => 'invalid profile'], ['profile' => ['name' => []]], ['profile' => ['name' => str_repeat('n', 513)]]] as $patch) {
    $value = playedValue(); $value['contacts'][0] = array_replace($value['contacts'][0], $patch);
    playedReport(playedPayload($value), 0, 1, 0, 0, 'invalid played contact identity or profile');
}
$value = playedValue(); unset($value['statuses']); $value['contacts'][0]['profile'] = 'invalid profile';
$value['messages'] = [['id' => 'wamid.INBOUND.04', 'from' => '201000000001',
    'timestamp' => (string) (time() - 60), 'type' => 'text', 'text' => ['body' => 'اختبار']]];
playedReport(playedPayload($value), 0, 1, 0, 0, 'normal message invalid profile remains quarantined');

$payload = playedPayload(playedValue()); $payload['entry'][0]['id'] = '999';
playedReport($payload, 0, 0, 1, 0, 'wrong WhatsApp account is ignored');
$value = playedValue(); $value['metadata']['phone_number_id'] = '999';
playedReport(playedPayload($value), 0, 0, 1, 0, 'wrong WhatsApp phone is ignored');
$value = playedValue(); $value['metadata']['phone_number_id'] = null;
playedReport(playedPayload($value), 0, 1, 0, 0, 'missing scope is quarantined');
$value = playedValue(); $value['messaging_product'] = 'other';
playedReport(playedPayload($value), 0, 1, 0, 0, 'wrong product is quarantined');

echo 'WHATSAPP_INBOX_PLAYED_STATUS_CHECKS=' . $checks . "\n";
