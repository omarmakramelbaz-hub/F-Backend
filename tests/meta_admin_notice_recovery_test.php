<?php
$autoload = getenv('WA_INBOX_TEST_VENDOR'); if (is_dir($autoload)) $autoload .= '/autoload.php'; require $autoload;
function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
function now($tz = null) { return \Carbon\Carbon::now($tz); }
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new \ErrorException($message, 0, $severity, $file, $line); return false;
});

use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;

$c = new Container; Container::setInstance($c); $db = new Manager($c);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
$db->setEventDispatcher(new Dispatcher($c)); $db->setAsGlobal(); $db->bootEloquent(); $key = random_bytes(32);
$page = '118160256722323';
$accounts = [['channel' => 'messenger', 'account_id' => $page, 'access_token' => 'PRIVATE_TOKEN_SENTINEL'],
    ['channel' => 'instagram', 'account_id' => '202', 'access_token' => 'PRIVATE_IG_TOKEN_SENTINEL']];
$c->instance('config', new Repository(['meta_messaging' => ['accounts' => $accounts],
    'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]] ]));
$c->instance('db', $db->getDatabaseManager()); $c->instance('db.schema', $db->getConnection()->getSchemaBuilder());
$c->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); Facade::setFacadeApplication($c);
foreach (['Support/WhatsAppWebhookProtocol', 'Support/MetaMessagingProtocol', 'Services/Dashboard/WhatsAppInboxAccess',
    'Services/Dashboard/MetaInboxAccess'] as $file) require __DIR__.'/../app/'.$file.'.php';
require __DIR__.'/../database/migrations/2026_10_11_160000_create_meta_webhook_events_table.php';
require __DIR__.'/../database/migrations/2026_10_11_160001_create_meta_inbox_tables.php';
(new CreateMetaWebhookEventsTable)->up(); (new CreateMetaInboxTables)->up();
require __DIR__.'/../deployment/resolve_meta_admin_notices.php';
foreach (['orders', 'meta_order_drafts', 'meta_reply_requests', 'meta_inbox_reads'] as $table) {
    DB::statement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, sentinel TEXT NOT NULL)');
    DB::table($table)->insert(['id' => 1, 'sentinel' => 'UNCHANGED_'.$table]);
}
DB::table('meta_inbox_conversations')->insert(['id' => 1, 'waba_id' => 'messenger', 'phone_number_id' => $page,
    'peer_hash' => str_repeat('a', 64), 'customer' => Crypt::encryptString('PRIVATE_EXISTING_CUSTOMER'),
    'first_message_at' => '2026-10-13 07:00:00', 'last_message_at' => '2026-10-13 08:00:00',
    'created_at' => '2026-10-13 07:00:00', 'updated_at' => '2026-10-13 08:00:00']);
DB::table('meta_inbox_messages')->insert(['id' => 1, 'conversation_id' => 1, 'message_key' => str_repeat('b', 64),
    'type' => 'text', 'direction' => 'outbound', 'source' => 'message_echoes', 'content' => Crypt::encryptString('PRIVATE_EXISTING_MESSAGE'),
    'sent_at' => '2026-10-13 08:00:00', 'created_at' => '2026-10-13 08:00:00', 'updated_at' => '2026-10-13 08:00:00']);

$checks = 0;
function check($ok, string $label): void { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
function rows(string $table): array { return array_map(static fn ($r) => (array) $r, DB::table($table)->orderBy($table === 'meta_inbox_ingestion_failures' ? 'event_id' : 'id')->get()->all()); }
function dbState(): array { return ['events' => rows('meta_webhook_events'), 'failures' => rows('meta_inbox_ingestion_failures')]; }
function notice(int $id): array {
    global $page;
    return ['object' => 'page', 'entry' => [['id' => $page, 'time' => time(), 'messaging' => [[
        'sender' => ['id' => '90000'.$id], 'recipient' => ['id' => $page], 'timestamp' => (time() - 2) * 1000,
        'message' => ['admin_text' => 'PRIVATE_ADMIN_VALUE_'.$id.' إشعار إداري'],
    ]]]]];
}
function seed(?callable $alter = null): void {
    DB::table('meta_inbox_ingestion_failures')->delete(); DB::table('meta_webhook_events')->delete();
    foreach ([5, 12, 13, 18, 22, 31] as $id) {
        $payload = notice($id); if ($alter) $payload = $alter($payload, $id);
        $plain = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('meta_webhook_events')->insert(['id' => $id, 'payload_hash' => hash('sha256', $plain),
            'payload' => Crypt::encryptString($plain), 'received_at' => '2026-10-13 07:42:08', 'processed_at' => null]);
        DB::table('meta_inbox_ingestion_failures')->insert(['event_id' => $id, 'reason' => 'INVALID_EVENT',
            'attempts' => $id, 'last_attempted_at' => '2026-10-13 09:00:00']);
    }
}

$testHome = posix_getpwuid(posix_geteuid())['dir'];
$base = $testHome.'/meta-admin-recovery-test-'.bin2hex(random_bytes(8));
mkdir($base, 0700); $root = $base.'/public_html'; $auditParent = $base.'/meta-release-backups';
mkdir($root, 0755); mkdir($auditParent, 0700); $sequence = 0;
function auditDirectory(): string { global $auditParent, $sequence; $path = $auditParent.'/case-'.(++$sequence); mkdir($path, 0700); return $path; }
function resolver(): MetaAdminNoticeResolution { global $root, $auditParent; return new MetaAdminNoticeResolution($root, $auditParent, posix_geteuid()); }
function refused(string $label, ?string $reason = null): void {
    $before = dbState(); $directory = auditDirectory();
    try { resolver()->resolve($directory); check(false, $label); }
    catch (MetaAdminNoticeResolutionError $e) { check($reason === null || $e->getMessage() === $reason, $label); }
    check(dbState() === $before, $label.' preserves database');
    check(glob($directory.'/*.json') === [], $label.' refuses before audit or mutation');
}
function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') removeTree($path.'/'.$name);
    rmdir($path);
}
$untouchedTables = ['meta_inbox_conversations', 'meta_inbox_messages', 'orders', 'meta_order_drafts', 'meta_reply_requests', 'meta_inbox_reads'];
$untouched = []; foreach ($untouchedTables as $table) $untouched[$table] = rows($table);
$queryAudit = null; $writes = []; $firstMutationAudited = false;
DB::listen(function ($query) use (&$queryAudit, &$writes, &$firstMutationAudited) {
    if ($queryAudit === null || !preg_match('/\A(?:update|delete|insert)\b/i', $query->sql)) return;
    $writes[] = $query->sql;
    $files = glob($queryAudit.'/*.json');
    if (!$firstMutationAudited) {
        check(count($files) === 1, 'durable audit exists before the first database mutation');
        $audit = json_decode(file_get_contents($files[0]), true, 64, JSON_THROW_ON_ERROR);
        check(count($audit['captures']) === 5 && $audit['captures'][0]['prior_failure']['attempts'] === 5,
            'first mutation has original failure metadata');
        $firstMutationAudited = true;
    }
});

try {
    umask(0077); seed(); $before = dbState(); $directory = auditDirectory(); $queryAudit = $directory;
    $result = resolver()->resolve($directory); $queryAudit = null;
    check($result['status'] === 'META_ADMIN_NOTICES_RESOLVED' && $result['resolved'] === 5 && $result['already_resolved'] === 0,
        'exact reviewed administrative captures resolved');
    check($result['messages_created'] === 0 && count($writes) === 10, 'only five status updates and five specific marker deletions');
    foreach ($writes as $sql) check(preg_match('/\A(?:update "meta_webhook_events"|delete from "meta_inbox_ingestion_failures")/', $sql) === 1,
        'recovery never writes message, conversation, reply, order or unread tables');
    foreach ([5, 12, 13, 18, 22] as $id) {
        $event = DB::table('meta_webhook_events')->where('id', $id)->first();
        $old = array_values(array_filter($before['events'], fn ($r) => $r['id'] === $id))[0];
        check($event->processed_at !== null && $event->payload === $old['payload'] && $event->payload_hash === $old['payload_hash']
            && $event->received_at === $old['received_at'], 'raw administrative capture remains byte-for-byte encrypted');
        check(!DB::table('meta_inbox_ingestion_failures')->where('event_id', $id)->exists(), 'only reviewed marker cleared');
    }
    check(DB::table('meta_webhook_events')->where('id', 31)->value('processed_at') === null
        && DB::table('meta_inbox_ingestion_failures')->where('event_id', 31)->exists(), 'unreviewed quarantine untouched');
    $auditBytes = file_get_contents($result['audit']); $audit = json_decode($auditBytes, true, 64, JSON_THROW_ON_ERROR);
    check((fileperms($result['audit']) & 0777) === 0600 && (fileperms($directory) & 0777) === 0700, 'audit is private');
    check(strpos($auditBytes, 'PRIVATE_') === false && strpos($auditBytes, '900005') === false
        && strpos($auditBytes, 'admin_text') === false && strpos($auditBytes, 'payload"') === false,
        'audit contains no tokens, customer peer IDs, administrative value or encrypted payload');
    check($audit['reviewed_event_ids'] === [5, 12, 13, 18, 22] && $audit['captures'][4]['prior_failure'] === [
        'event_id' => 22, 'reason' => 'INVALID_EVENT', 'attempts' => 22, 'last_attempted_at' => '2026-10-13 09:00:00'],
        'original complete quarantine marker metadata retained');
    foreach ($untouchedTables as $table) check(rows($table) === $untouched[$table], 'existing '.$table.' unchanged');
    $before = dbState(); $result = resolver()->resolve($directory);
    check($result['status'] === 'META_ADMIN_NOTICES_ALREADY_RESOLVED' && $result['resolved'] === 0 && $result['already_resolved'] === 5,
        'repeated resolution is idempotent');
    check(dbState() === $before && count(glob($directory.'/*.json')) === 2, 'idempotent rerun creates new audit without changing database');

    foreach (['native-event', 'mixed-message', 'delivery', 'foreign-entry', 'foreign-recipient', 'same-route', 'unknown-entry',
        'unknown-event', 'unknown-top', 'outgoing-notice', 'empty-entry', 'blank-notice', 'controlled-notice', 'future-notice',
        'numeric-sender', 'instagram-object'] as $kind) {
        seed(function ($p, $id) use ($kind) {
            if ($id !== 22) return $p;
            $event = &$p['entry'][0]['messaging'][0];
            switch ($kind) {
                case 'native-event': $p['entry'][0]['messaging'][] = $event;
                    $p['entry'][0]['messaging'][1]['message'] = ['mid' => 'NATIVE_MID', 'text' => 'PRIVATE_CUSTOMER_TEXT']; break;
                case 'mixed-message': $event['message']['text'] = 'PRIVATE_CUSTOMER_TEXT'; break;
                case 'delivery': $p['entry'][0]['messaging'][] = ['delivery' => ['mids' => ['NATIVE_MID']]]; break;
                case 'foreign-entry': $p['entry'][] = ['id' => '999', 'messaging' => [$event]]; break;
                case 'foreign-recipient': $event['recipient']['id'] = '999'; break;
                case 'same-route': $event['sender']['id'] = $event['recipient']['id']; break;
                case 'unknown-entry': $p['entry'][0]['changes'] = [['field' => 'comments', 'value' => 'PRIVATE_COMMENT']]; break;
                case 'unknown-event': $event['unknown'] = 'PRIVATE_UNKNOWN'; break;
                case 'unknown-top': $p['unknown'] = 'PRIVATE_UNKNOWN'; break;
                case 'outgoing-notice': $peer = $event['sender']['id']; $event['sender']['id'] = $event['recipient']['id']; $event['recipient']['id'] = $peer; break;
                case 'empty-entry': $p['entry'][] = ['id' => $p['entry'][0]['id']]; break;
                case 'blank-notice': $event['message']['admin_text'] = ' '; break;
                case 'controlled-notice': $event['message']['admin_text'] = "CONTROL\0"; break;
                case 'future-notice': $event['timestamp'] = (time() + 90000) * 1000; break;
                case 'numeric-sender': $event['sender']['id'] = 9000022; break;
                case 'instagram-object': $p['object'] = 'instagram'; break;
            }
            return $p;
        });
        refused('changed or mixed capture refused: '.$kind, 'PAYLOAD_NEEDS_REVIEW');
    }
    seed(); DB::table('meta_webhook_events')->where('id', 22)->update(['payload_hash' => str_repeat('c', 64)]);
    refused('hash mismatch refused', 'CAPTURE_HASH_MISMATCH');
    seed(); DB::table('meta_webhook_events')->where('id', 22)->update(['payload' => 'INVALID_CIPHERTEXT']);
    refused('unreadable ciphertext refused', 'PAYLOAD_NEEDS_REVIEW');
    seed(); DB::table('meta_webhook_events')->where('id', 22)->update(['payload' => str_repeat('a', 8388609)]);
    refused('oversized encrypted payload refused', 'CAPTURE_NEEDS_REVIEW');
    seed(); $plain = str_repeat('a', 4194305); DB::table('meta_webhook_events')->where('id', 22)->update([
        'payload' => Crypt::encryptString($plain), 'payload_hash' => hash('sha256', $plain)]);
    refused('oversized plaintext refused', 'CAPTURE_HASH_MISMATCH'); unset($plain);
    seed(); DB::table('meta_inbox_ingestion_failures')->where('event_id', 22)->update(['reason' => 'OTHER_FAILURE']);
    refused('other quarantine reason retained', 'QUARANTINE_NEEDS_REVIEW');
    seed(); DB::table('meta_inbox_ingestion_failures')->where('event_id', 22)->delete();
    refused('unprocessed capture without reviewed failure refused', 'QUARANTINE_NEEDS_REVIEW');
    seed(); DB::table('meta_webhook_events')->where('id', 22)->update(['processed_at' => '2026-10-13 10:00:00']);
    refused('processed capture with marker needs review', 'QUARANTINE_NEEDS_REVIEW');
    seed(); DB::table('meta_inbox_ingestion_failures')->where('event_id', 22)->update(['attempts' => 0]);
    refused('invalid attempt counter refused', 'QUARANTINE_NEEDS_REVIEW');
    seed(); DB::table('meta_inbox_ingestion_failures')->where('event_id', 22)->update(['last_attempted_at' => 'PRIVATE_NOT_A_DATE']);
    refused('invalid failure timestamp cannot enter audit', 'QUARANTINE_NEEDS_REVIEW');
    seed(); DB::table('meta_inbox_ingestion_failures')->where('event_id', 22)->delete(); DB::table('meta_webhook_events')->where('id', 22)->delete();
    refused('missing reviewed capture refused', 'CAPTURE_NEEDS_REVIEW');
    seed(); config()->set('meta_messaging.accounts', [$accounts[1]]);
    refused('removed Page refused', 'ACCOUNT_NEEDS_REVIEW'); config()->set('meta_messaging.accounts', $accounts);
    seed(); config()->set('meta_messaging.accounts', [$accounts[0], $accounts[0]]);
    refused('duplicate configured Page refused', 'ACCOUNT_NEEDS_REVIEW'); config()->set('meta_messaging.accounts', $accounts);
    seed(); config()->set('desktop_dashboard.local', true);
    refused('local desktop database refused', 'ACCOUNT_NEEDS_REVIEW'); config()->set('desktop_dashboard.local', false);

    seed(); $before = dbState(); $directory = auditDirectory(); chmod($directory, 0755);
    try { resolver()->resolve($directory); check(false, 'public audit mode refused'); }
    catch (MetaAdminNoticeResolutionError $e) { check($e->getMessage() === 'AUDIT_PATH_NEEDS_REVIEW', 'public audit mode refused'); }
    check(dbState() === $before && glob($directory.'/*.json') === [], 'unsafe audit mode prevents mutations'); chmod($directory, 0700);
    $target = auditDirectory(); $link = $auditParent.'/linked-release'; symlink($target, $link);
    try { resolver()->resolve($link); check(false, 'symlink audit directory refused'); }
    catch (MetaAdminNoticeResolutionError $e) { check($e->getMessage() === 'AUDIT_PATH_NEEDS_REVIEW', 'symlink audit directory refused'); }
    check(dbState() === $before && glob($target.'/*.json') === [], 'symlink refusal prevents mutations'); unlink($link);
    mkdir($root.'/public-audit', 0700); $unsafe = new MetaAdminNoticeResolution($root, $root, posix_geteuid());
    try { $unsafe->resolve($root.'/public-audit'); check(false, 'audit inside webroot refused'); }
    catch (MetaAdminNoticeResolutionError $e) { check($e->getMessage() === 'AUDIT_PATH_NEEDS_REVIEW', 'audit inside webroot refused'); }
    check(dbState() === $before, 'public root refusal prevents mutations');

    seed(); $before = dbState(); $directory = auditDirectory();
    DB::statement('CREATE TRIGGER block_second_resolution BEFORE DELETE ON meta_inbox_ingestion_failures WHEN OLD.event_id = 12 BEGIN SELECT RAISE(ABORT, "TEST_RESOLUTION_FAILURE"); END');
    try { resolver()->resolve($directory); check(false, 'mid-resolution database failure'); }
    catch (\Illuminate\Database\QueryException $e) { check(true, 'mid-resolution database failure'); }
    check(dbState() === $before, 'mid-resolution failure rolls back every earlier marker and processed timestamp');
    $files = glob($directory.'/*.json'); check(count($files) === 1, 'original metadata audit retained after transaction rollback');
    $audit = json_decode(file_get_contents($files[0]), true, 64, JSON_THROW_ON_ERROR);
    check(count($audit['captures']) === 5 && $audit['captures'][1]['prior_failure']['event_id'] === 12,
        'rollback audit retains full original marker set');
    DB::statement('DROP TRIGGER block_second_resolution');
    $result = resolver()->resolve($directory); check($result['resolved'] === 5, 'successful retry after database failure resolves only reviewed notices');
    foreach ($untouchedTables as $table) check(rows($table) === $untouched[$table], 'failure and retry preserve '.$table);

    $args = $_SERVER['argv']; $_SERVER['argv'] = ['recovery', '--all-events'];
    try { MetaAdminNoticeResolution::run(); check(false, 'CLI cannot widen reviewed scope'); }
    catch (MetaAdminNoticeResolutionError $e) { check($e->getMessage() === 'INVALID_ARGUMENTS', 'CLI cannot widen reviewed scope'); }
    $_SERVER['argv'] = $args;
    echo "meta-admin-notice-recovery: $checks checks passed\n";
} finally { removeTree($base); }
