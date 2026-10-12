<?php
// Reuse the original isolated in-memory SQLite fixture; no production application.
require __DIR__ . '/whatsapp_inbox_database_test.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
$migration->up();
$checks = 0;
function echoDbCheck(bool $ok, string $label): void {
    global $checks; $checks++; dbCheck($ok, $label);
}
function echoDbPayload(string $text = 'Synthetic Agent reply', string $to = '201111111111'): array {
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '468336579702269', 'changes' => [[
        'field' => 'standby', 'value' => ['messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
            'standby' => ['message_echoes' => [['id' => 'wamid.standby.sqlite', 'timestamp' => '1691583200',
                'message' => ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual',
                    'to' => $to, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]]];
}
dbReset();
$incoming = dbMessage('wamid.customer.synthetic'); unset($incoming['from_user_id']);
dbEvent(dbPayload([$incoming])); dbConsume();
$thread = DB::table('whatsapp_inbox_conversations')->first();
$event = dbEvent(echoDbPayload()); $metrics = dbConsume();
$saved = DB::table('whatsapp_inbox_messages')->where('direction', 'outbound')->first();
$dto = $saved ? json_decode(Crypt::decryptString($saved->content), true, 512, JSON_THROW_ON_ERROR) : [];
echoDbCheck($metrics['messages_inserted'] === 1 && $metrics['quarantined_events'] === 0
    && $saved->conversation_id === $thread->id, 'canonical echo projects into exact native phone conversation');
echoDbCheck($dto['text'] === 'Synthetic Agent reply' && $dto['source'] === 'standby'
    && $dto['message_id'] === 'wamid.standby.sqlite' && !isset($dto['content']['message']['from']), 'encrypted literal echo DTO with outer ID and optional sender');
echoDbCheck(strpos($saved->content, 'Synthetic Agent reply') === false
    && DB::table('whatsapp_webhook_events')->where('id', $event)->value('processed_at') !== null, 'ciphertext and processed marker committed');
$flat = $dto['content']['message']; $flat['from'] = '201285545554';
dbEvent(dbPayload([$flat], 'smb_message_echoes')); $metrics = dbConsume();
echoDbCheck($metrics['messages_replayed'] === 1 && DB::table('whatsapp_inbox_messages')->where('direction', 'outbound')->count() === 1,
    'flat echo redelivery deduplicates native message');
$before = DB::table('whatsapp_inbox_messages')->where('id', $saved->id)->value('content');
$conflict = dbEvent(echoDbPayload('Conflicting copy')); $metrics = dbConsume();
echoDbCheck($metrics['quarantined_events'] === 1 && $metrics['errors'] === 0
    && DB::table('whatsapp_inbox_messages')->where('id', $saved->id)->value('content') === $before, 'conflicting wrapper cannot overwrite saved transcript');
echoDbCheck(DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $conflict)->value('reason') === 'CONFLICTING_MESSAGE'
    && DB::table('whatsapp_webhook_events')->where('id', $conflict)->value('processed_at') === null, 'raw conflict stays quarantined');
$crossPeer = dbEvent(echoDbPayload('Synthetic Agent reply', '201222222222')); $metrics = dbConsume();
echoDbCheck($metrics['quarantined_events'] === 1 && DB::table('whatsapp_inbox_conversations')->count() === 1,
    'same-ID different recipient never creates or merges another thread');
$bad = echoDbPayload(); $bad['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['message']['id'] = 'wamid.conflicting.inner';
$badEvent = dbEvent($bad); $metrics = dbConsume();
echoDbCheck($metrics['quarantined_events'] === 1 && DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $badEvent)->value('reason') === 'INVALID_EVENT'
    && DB::table('whatsapp_inbox_messages')->count() === 2, 'invalid wrapper causes no partial projection');
$later = echoDbPayload(); $later['entry'][0]['changes'][0]['value']['standby']['message_echoes'][0]['id'] = 'wamid.later.echo';
dbEvent($later); $metrics = dbConsume();
echoDbCheck($metrics['messages_inserted'] === 1 && DB::table('whatsapp_inbox_ingestion_failures')->count() === 3,
    'fresh valid echo processes while old quarantine is untouched');
echo "WHATSAPP_STANDBY_ECHO_DATABASE_CHECKS=$checks\n";
