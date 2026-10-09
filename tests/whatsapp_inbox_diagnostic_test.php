<?php

// Full CLI integration, only inside an isolated GitHub Actions container.
// The fixed application root must not exist; this test never inspects a real app.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_string($autoload) && is_dir($autoload)) $autoload .= '/autoload.php';
$root = '/home/fasakha/public_html';
if (getenv('GITHUB_ACTIONS') !== 'true' || file_exists($root) || is_link($root)
    || !is_string($autoload) || !is_file($autoload) || !extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Diagnostic fixture requires an isolated CI container, absent app root, and temporary SQLite dependencies.\n");
    exit(1);
}
require $autoload;
umask(0077);
$temporary = tempnam(sys_get_temp_dir(), 'wa-diagnostic-');
unlink($temporary);
mkdir($temporary, 0700);
mkdir($root . '/vendor', 0700, true);
mkdir($root . '/bootstrap', 0700);
function diagnosticFixtureRemove(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) return;
    foreach (scandir($directory) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . '/' . $entry;
        if (is_dir($path) && !is_link($path)) diagnosticFixtureRemove($path);
        else unlink($path);
    }
    rmdir($directory);
}
register_shutdown_function(function () use ($root, $temporary) {
    diagnosticFixtureRemove($root);
    diagnosticFixtureRemove($temporary);
});

$setup = <<<'PHP'
<?php
if (!function_exists('config')) {
    function config($key = null, $default = null) {
        $repository = \Illuminate\Container\Container::getInstance()->make('config');
        return $key === null ? $repository : $repository->get($key, $default);
    }
}
if (!function_exists('now')) {
    function now($timezone = null) { return \Carbon\Carbon::now($timezone); }
}
function diagnosticFixtureContainer(array $settings): void {
    $container = new \Illuminate\Container\Container();
    \Illuminate\Container\Container::setInstance($container);
    $connection = ['driver' => 'sqlite', 'database' => $settings['database'], 'prefix' => '', 'foreign_key_constraints' => true];
    $capsule = new \Illuminate\Database\Capsule\Manager($container);
    $capsule->addConnection($connection);
    $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $container->instance('config', new \Illuminate\Config\Repository([
        'app' => ['key' => 'base64:' . $settings['key'], 'cipher' => 'AES-256-CBC'],
        'database' => ['default' => 'default', 'connections' => ['default' => $connection]],
    ]));
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
    $container->instance('encrypter', new \Illuminate\Encryption\Encrypter(base64_decode($settings['key'], true), 'AES-256-CBC'));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
}
PHP;
file_put_contents($temporary . '/setup.php', $setup);
require $temporary . '/setup.php';
$repo = realpath(__DIR__ . '/..');
foreach (['app/Support/WhatsAppInboxProtocol.php', 'app/Services/Dashboard/WhatsAppInboxQuarantinedEvent.php',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php', 'database/migrations/2026_10_10_000001_create_whatsapp_inbox_tables.php'] as $file) {
    require $repo . '/' . $file;
}
$settings = ['database' => $temporary . '/fixture.sqlite', 'key' => base64_encode(random_bytes(32)), 'mode' => 'READY'];
touch($settings['database']);
$configuration = $temporary . '/settings.json';
file_put_contents($configuration, json_encode($settings, JSON_THROW_ON_ERROR));
diagnosticFixtureContainer($settings);
file_put_contents($root . '/vendor/autoload.php', "<?php\nrequire " . var_export($autoload, true)
    . ";\nrequire " . var_export($temporary . '/setup.php', true)
    . ";\nrequire " . var_export($repo . '/app/Support/WhatsAppInboxProtocol.php', true) . ";\n");
file_put_contents($root . '/bootstrap/app.php', <<<'PHP'
<?php
return new class {
    public function make($contract) {
        return new class {
            public function bootstrap() {
                $settings = json_decode(file_get_contents(getenv('WA_DIAGNOSTIC_FIXTURE_CONFIG')), true, 512, JSON_THROW_ON_ERROR);
                // Bootstrap output and exceptions deliberately contain private sentinels.
                echo 'PRIVATE_BOOTSTRAP_NOISE ' . $settings['key'];
                if ($settings['mode'] === 'BOOT_FAILURE') throw new \RuntimeException('PRIVATE_BOOTSTRAP_EXCEPTION EAAfixture_secret_token_never_print');
                diagnosticFixtureContainer($settings);
            }
        };
    }
};
PHP);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('payload_hash', 64)->unique();
    $table->longText('payload');
    $table->timestamp('received_at');
    $table->timestamp('processed_at')->nullable();
});
(new CreateWhatsAppInboxTables())->up();

function diagnosticCheck(bool $condition, string $label): void
{
    if (!$condition) throw new DiagnosticFixtureAssertion($label);
}
final class DiagnosticFixtureAssertion extends RuntimeException
{
    public $fixedLabel;
    public function __construct(string $label) { $this->fixedLabel = $label; }
}
function diagnosticMessage(string $id, string $body, bool $echo = false, string $phone = '201111119999', string $user = 'US.private_fixture'): array
{
    $message = ['id' => $id, 'timestamp' => '1691583200', 'type' => 'text', 'text' => ['body' => $body]];
    if ($echo) return $message + ['from' => '201285545554', 'to' => $phone, 'to_user_id' => $user];
    return $message + ['from' => $phone, 'from_user_id' => $user];
}
function diagnosticPayload(array $messages, bool $echo = false): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '468336579702269', 'changes' => [[
            'field' => $echo ? 'smb_message_echoes' : 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => '515388018324075', 'display_phone_number' => '201285545554'],
                $echo ? 'message_echoes' : 'messages' => $messages,
            ],
        ]],
    ]]];
}
function diagnosticEvent(array $payload): int
{
    $plain = json_encode($payload, JSON_THROW_ON_ERROR);
    return DB::table('whatsapp_webhook_events')->insertGetId([
        'payload_hash' => hash('sha256', $plain), 'payload' => Crypt::encryptString($plain),
        'received_at' => now('UTC'), 'processed_at' => null,
    ]);
}
function diagnosticSnapshot(): string
{
    $snapshot = [];
    foreach (['whatsapp_webhook_events' => 'id', 'whatsapp_inbox_conversations' => 'id',
        'whatsapp_inbox_messages' => 'id', 'whatsapp_inbox_ingestion_failures' => 'event_id'] as $table => $column) {
        $snapshot[$table] = DB::table($table)->orderBy($column)->get()->toArray();
    }
    return json_encode($snapshot, JSON_THROW_ON_ERROR);
}
function diagnosticRun(string $repo, string $configuration, array $settings): array
{
    file_put_contents($configuration, json_encode($settings, JSON_THROW_ON_ERROR));
    $snapshot = diagnosticSnapshot();
    $environment = getenv();
    $environment['WA_DIAGNOSTIC_FIXTURE_CONFIG'] = $configuration;
    $process = proc_open([PHP_BINARY, $repo . '/deployment/whatsapp_inbox_diagnostic.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $environment);
    diagnosticCheck(is_resource($process), 'subprocess_started');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    diagnosticCheck($snapshot === diagnosticSnapshot(), 'read_only_database_snapshot');
    diagnosticCheck($errors === '', 'no_stderr_or_exception_trace');
    foreach (['201111119999', '201111118888', 'US.private_fixture', 'US.private_other',
        'PRIVATE_', 'wamid.private.', $settings['key'], 'EAAfixture_secret_token_never_print'] as $private) {
        diagnosticCheck(strpos($output, $private) === false, 'private_sentinel_redaction');
    }
    foreach (DB::table('whatsapp_inbox_messages')->get() as $row) {
        diagnosticCheck(strpos($output, $row->message_key) === false && strpos($output, $row->content) === false, 'message_hash_ciphertext_redaction');
    }
    foreach (DB::table('whatsapp_inbox_conversations')->get() as $row) {
        diagnosticCheck(strpos($output, $row->peer_hash) === false && strpos($output, $row->customer) === false, 'peer_hash_customer_redaction');
    }
    $body = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    diagnosticCheck(is_array($body), 'json_output');
    return [$body, $exit];
}

$tests = 0;
$case = 'NORMAL_CONFLICTS';
try {
    $consumer = new \App\Services\Dashboard\WhatsAppInboxConsumer();
    $inbound = diagnosticMessage('wamid.private.inbound', 'PRIVATE_CUSTOMER_TEXT WA-0121');
    $outbound = diagnosticMessage('wamid.private.outbound', 'PRIVATE_REPLY_TEXT WA-0121', true);
    diagnosticEvent(diagnosticPayload([$inbound]));
    diagnosticEvent(diagnosticPayload([$outbound], true));
    $metrics = $consumer->consume();
    diagnosticCheck($metrics['messages_inserted'] === 2 && $metrics['errors'] === 0, 'real_projection_fixture');

    $textChanged = $inbound;
    $textChanged['text']['body'] = 'PRIVATE_DIFFERENT_TEXT';
    $firstFailure = diagnosticEvent(diagnosticPayload([$textChanged]));
    $previewChanged = $outbound;
    $previewChanged['text']['preview_url'] = false;
    diagnosticEvent(diagnosticPayload([$previewChanged], true));
    diagnosticEvent(diagnosticPayload([diagnosticMessage('wamid.private.inbound', $inbound['text']['body'], false, '201111118888', 'US.private_other')]));
    diagnosticEvent(diagnosticPayload([diagnosticMessage('wamid.private.new_customer_identity', 'PRIVATE_IDENTITY_TEXT', false, '201111118888')]));
    $metrics = $consumer->consume();
    diagnosticCheck($metrics['quarantined_events'] === 4 && $metrics['errors'] === 0, 'actual_four_conflicting_events');
    diagnosticEvent(diagnosticPayload([diagnosticMessage('wamid.private.pending', 'PRIVATE_RUNNABLE_TEXT')]));
    [$result, $exit] = diagnosticRun($repo, $configuration, $settings);
    diagnosticCheck($exit === 0 && $result['status'] === 'READY' && $result['diagnostic'] === 'READ_ONLY', 'full_bootstrap_ready');
    diagnosticCheck($result['pending_events'] === 5 && $result['pending_runnable'] === 1 && $result['quarantine_total'] === 4, 'pending_excludes_quarantine');
    $comparisons = array_map(function ($failure) { return $failure['comparisons'][0]; }, $result['failures']);
    diagnosticCheck($comparisons[0]['dto_conflict'] && $comparisons[0]['dto_diff_fields'] === ['text'] && $comparisons[0]['body_conflict'], 'immutable_text_conflict');
    diagnosticCheck(!$comparisons[1]['dto_conflict'] && $comparisons[1]['body_conflict']
        && $comparisons[1]['text_comparison']['body_match'] && !$comparisons[1]['text_comparison']['old_preview_url_present']
        && $comparisons[1]['text_comparison']['new_preview_url_present'] && !$comparisons[1]['text_comparison']['preview_url_match'], 'preview_metadata_conflict');
    diagnosticCheck($comparisons[2]['scope_identity_or_columns_conflict'] && !$comparisons[2]['peer_hash_match'], 'peer_scope_conflict');
    diagnosticCheck(!$comparisons[3]['existing_message'] && $comparisons[3]['customer_identity_conflict']
        && $comparisons[3]['customer_identity_diff_fields'] === ['phone'], 'customer_identity_conflict');
    diagnosticCheck($result['probe']['same_conversation'] === 'YES' && $result['probe']['range_complete']
        && $result['probe']['inbound_matches'] === 1 && $result['probe']['outbound_matches'] === 1, 'verified_single_probe_conversation');
    $tests++;

    $case = 'CORRUPT_RAW';
    $savedPayload = DB::table('whatsapp_webhook_events')->where('id', $firstFailure)->value('payload');
    DB::table('whatsapp_webhook_events')->where('id', $firstFailure)->update(['payload' => 'PRIVATE_BROKEN_PAYLOAD']);
    [$result, $exit] = diagnosticRun($repo, $configuration, $settings);
    diagnosticCheck($result['status'] === 'PARTIAL' && $result['failures'][0]['status'] === 'FAILED'
        && $result['failures'][0]['failed_stage'] === 'FAILURE_PAYLOAD_DECRYPT', 'corrupt_raw_fixed_stage');
    diagnosticCheck(count($result['failures']) === 4 && $result['failures'][1]['comparisons'][0]['body_conflict'], 'later_failures_retained');
    DB::table('whatsapp_webhook_events')->where('id', $firstFailure)->update(['payload' => $savedPayload]);
    $tests++;

    $case = 'CORRUPT_PROBE';
    $oldest = DB::table('whatsapp_inbox_messages')->orderBy('id')->first();
    DB::table('whatsapp_inbox_messages')->where('id', $oldest->id)->update(['content' => 'PRIVATE_BROKEN_MESSAGE']);
    [$result, $exit] = diagnosticRun($repo, $configuration, $settings);
    diagnosticCheck($result['status'] === 'PARTIAL' && $result['probe']['status'] === 'FAILED'
        && $result['probe']['failed_stage'] === 'PROBE_CONTENT_DECRYPT' && !$result['probe']['range_complete']
        && $result['probe']['same_conversation'] !== 'YES', 'corrupt_probe_no_false_confirmation');
    diagnosticCheck($result['probe']['outbound_matches'] === 1 && $result['failures'][1]['comparisons'][0]['body_conflict'], 'verified_partial_results_retained');
    DB::table('whatsapp_inbox_messages')->where('id', $oldest->id)->update(['content' => $oldest->content]);
    $tests++;

    $case = 'BOOTSTRAP_FAILURE';
    [$result, $exit] = diagnosticRun($repo, $configuration, array_replace($settings, ['mode' => 'BOOT_FAILURE']));
    diagnosticCheck($exit === 1 && $result['diagnostic'] === 'FAILED' && $result['status'] === 'FAILED'
        && $result['failed_stage'] === 'BOOTSTRAP_KERNEL' && $result['error_category'] === 'OTHER', 'sanitized_bootstrap_exception');
    $tests++;

    $case = 'BOUNDED_PROBE';
    $fill = [];
    for ($index = 0; $index < 499; $index++) $fill[] = diagnosticMessage('wamid.private.fill' . $index, 'PRIVATE_FILLER_TEXT');
    diagnosticEvent(diagnosticPayload($fill));
    $consumer->consume();
    diagnosticEvent(diagnosticPayload([diagnosticMessage('wamid.private.latest_in', 'PRIVATE_LATEST_IN WA-0121')]));
    diagnosticEvent(diagnosticPayload([diagnosticMessage('wamid.private.latest_out', 'PRIVATE_LATEST_OUT WA-0121', true)], true));
    $metrics = $consumer->consume();
    diagnosticCheck($metrics['messages_inserted'] === 2 && $metrics['errors'] === 0, 'large_probe_fixture');
    [$result, $exit] = diagnosticRun($repo, $configuration, $settings);
    diagnosticCheck($result['probe']['scoped_messages_at_ceiling'] > 500 && $result['probe']['messages_scanned'] === 500
        && !$result['probe']['range_complete'] && $result['probe']['inbound_matches'] > 0 && $result['probe']['outbound_matches'] > 0
        && $result['probe']['same_conversation'] === 'PARTIAL', 'bounded_scan_never_false_yes');
    $tests++;

    // Isolate the two observed production shapes from the earlier bounded scan
    // and its four quarantine rows. This is the disposable CI database only.
    $case = 'MIXED_TYPES_AND_MEDIA';
    DB::transaction(function () {
        foreach (['whatsapp_inbox_ingestion_failures', 'whatsapp_inbox_messages',
            'whatsapp_inbox_conversations', 'whatsapp_webhook_events'] as $table) {
            DB::table($table)->delete();
        }
    });
    $unsupported = diagnosticMessage('wamid.private.unsupported_replay', 'PRIVATE_UNUSED_BODY');
    unset($unsupported['text']);
    $unsupported['type'] = 'unsupported';
    $unsupported['errors'] = [['code' => 131051, 'title' => 'PRIVATE_UNSUPPORTED_ERROR', 'details' => 'PRIVATE_ERROR_DETAILS']];
    $image = diagnosticMessage('wamid.private.image_replay', 'PRIVATE_UNUSED_CAPTION');
    unset($image['text']);
    $image['type'] = 'image';
    $image['image'] = ['id' => 'PRIVATE_OLD_MEDIA_ID', 'sha256' => 'PRIVATE_MEDIA_SHA256',
        'mime_type' => 'image/jpeg', 'caption' => 'PRIVATE_IMAGE_CAPTION'];
    diagnosticEvent(diagnosticPayload([$unsupported]));
    diagnosticEvent(diagnosticPayload([$image]));
    $metrics = $consumer->consume();
    diagnosticCheck($metrics['messages_inserted'] === 2 && $metrics['errors'] === 0, 'actual_unsupported_and_media_baseline');

    $laterText = diagnosticMessage('wamid.private.unsupported_replay', 'PRIVATE_LATER_TEXT');
    $laterText['timestamp'] = '1691583260';
    $laterImage = $image;
    $laterImage['timestamp'] = '1691583260';
    $laterImage['image']['id'] = 'PRIVATE_NEW_MEDIA_ID';
    diagnosticEvent(diagnosticPayload([$laterText]));
    diagnosticEvent(diagnosticPayload([$laterImage]));
    $metrics = $consumer->consume();
    diagnosticCheck($metrics['quarantined_events'] === 2 && $metrics['errors'] === 0, 'actual_mixed_type_and_media_conflicts');
    [$result, $exit] = diagnosticRun($repo, $configuration, $settings);
    diagnosticCheck($exit === 0 && $result['status'] === 'READY' && $result['messages'] === 2
        && count($result['failures']) === 2 && !$result['quarantine_rows_truncated'], 'mixed_types_diagnostic_complete');
    $crossType = $result['failures'][0]['comparisons'][0];
    diagnosticCheck($result['failures'][0]['status'] === 'READY' && $crossType['status'] === 'READY'
        && $crossType['type'] === 'text' && $crossType['saved_type'] === 'unsupported'
        && !$crossType['type_match'] && $crossType['scope_identity_or_columns_conflict']
        && $crossType['dto_conflict'] && $crossType['body_conflict']
        && $crossType['dto_diff_fields'] === ['type', 'text', 'sent_at']
        && $crossType['content_diff_fields'] === ['text', 'errors']
        && $crossType['incoming_sent_at_order'] === 'NEWER'
        && $crossType['text_comparison'] === null && $crossType['media_comparison'] === null,
        'unsupported_to_text_fixed_summary');
    $media = $result['failures'][1]['comparisons'][0];
    diagnosticCheck($result['failures'][1]['status'] === 'READY' && $media['status'] === 'READY'
        && $media['saved_type'] === 'image' && $media['type_match']
        && $media['dto_diff_fields'] === ['sent_at'] && $media['content_diff_fields'] === ['image']
        && $media['incoming_sent_at_order'] === 'NEWER' && $media['text_comparison'] === null,
        'image_replay_fixed_summary');
    diagnosticCheck(array_keys($media['media_comparison']) === ['id', 'sha256', 'mime_type', 'caption', 'filename']
        && $media['media_comparison']['id']['old_present'] && $media['media_comparison']['id']['new_present']
        && !$media['media_comparison']['id']['match'] && $media['media_comparison']['sha256']['match']
        && $media['media_comparison']['mime_type']['match'] && $media['media_comparison']['caption']['match']
        && !$media['media_comparison']['filename']['old_present'] && !$media['media_comparison']['filename']['new_present']
        && $media['media_comparison']['filename']['match'], 'private_media_field_flags_only');
    $tests++;
    echo 'WHATSAPP_INBOX_DIAGNOSTIC_TESTS=' . $tests . "\n";
} catch (Throwable $error) {
    $label = $error instanceof DiagnosticFixtureAssertion ? $error->fixedLabel : 'UNEXPECTED_RUNTIME';
    fwrite(STDERR, 'WHATSAPP_INBOX_DIAGNOSTIC_TEST_FAILED CASE=' . $case . ' CHECK=' . $label . "\n");
    exit(1);
}
