<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/tests/owner_mobile_runtime/vendor/autoload.php';
define('WCP_LIBRARY', true);
require __DIR__.'/install.php';

use App\Services\Dashboard\{CustomerDirectory, WhatsAppCustomerDirectory};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};

function expect_wcp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function rejected_wcp(callable $work): bool
{
    try { $work(); return false; } catch (Throwable $error) { return true; }
}

putenv('WCP_TESTING=1');
$app = new Container;
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository([
    'desktop_dashboard'=>['local'=>false],
]));
$app->instance('date', new Illuminate\Support\DateFactory);
$capsule = new Capsule($app);
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
$capsule->setAsGlobal();
$app->instance('db', $capsule->getDatabaseManager());
$app->bind('db.schema', fn() => $capsule->getConnection()->getSchemaBuilder());

Schema::create('crm_customers', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->string('phone', 20)->unique();
    $t->string('phone_key', 20);
    foreach (['name', 'address', 'area', 'delivery_notes'] as $field) $t->text($field)->nullable();
    foreach (['latitude', 'longitude'] as $field) $t->decimal($field, 12, 7)->nullable();
    foreach (['service', 'marketing'] as $purpose) {
        $t->boolean($purpose.'_consent')->default(false);
        $t->timestamp($purpose.'_consented_at')->nullable();
        $t->string($purpose.'_consent_evidence', 500)->nullable();
        $t->unsignedBigInteger($purpose.'_consented_by')->nullable();
    }
    foreach (['opted_out_at', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at'] as $field) $t->timestamp($field)->nullable();
});
Schema::create('crm_customer_sources', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->unsignedBigInteger('customer_id');
    $t->string('branch', 30);
    $t->string('channel', 20);
    foreach (['first_seen_at', 'last_seen_at', 'created_at', 'updated_at'] as $field) $t->timestamp($field)->nullable();
    $t->unique(['customer_id', 'branch', 'channel']);
});
Schema::create('crm_purchases', function (Blueprint $t): void { $t->bigIncrements('id'); });
Schema::create('branch_operation_commands', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->string('branch', 30);
    $t->unsignedBigInteger('actor_id');
    $t->uuid('request_key');
    $t->string('request_hash', 64);
    $t->string('kind', 40);
    $t->longText('result');
    $t->timestamps();
    $t->unique(['branch', 'actor_id', 'request_key']);
});

function seed_wcp(string $phone, array $extra=[]): int
{
    return DB::table('crm_customers')->insertGetId($extra + [
        'phone'=>$phone, 'phone_key'=>CustomerDirectory::phoneKey($phone),
        'service_consent'=>false, 'marketing_consent'=>false,
        'created_at'=>'2026-10-01 10:00:00', 'updated_at'=>'2026-10-01 10:00:00',
    ]);
}
function source_wcp(int $id, string $channel='whatsapp', string $branch=''): void
{
    DB::table('crm_customer_sources')->insert([
        'customer_id'=>$id, 'branch'=>$branch, 'channel'=>$channel,
        'created_at'=>'2026-10-01 10:00:00', 'updated_at'=>'2026-10-01 10:00:00',
    ]);
}
function row_wcp(int $id): array
{
    return (array) DB::table('crm_customers')->where('id', $id)->first();
}
function all_wcp(): string
{
    return json_encode(DB::table('crm_customers')->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR);
}

$directory = new CustomerDirectory;
$whatsapp = new WhatsAppCustomerDirectory($directory);
$new = $whatsapp->capture(['phone'=>'01000000001', 'name'=>'Verified sender']);
expect_wcp($new !== null && $new->phone === '+201000000001', 'Verified sender must normalize and capture');
foreach (['service', 'marketing'] as $purpose) {
    expect_wcp((bool)$new->{$purpose.'_consent'}
        && $new->{$purpose.'_consented_at'} !== null
        && trim((string)$new->{$purpose.'_consent_evidence'}) !== ''
        && (int)$new->{$purpose.'_consented_by'} === 1, 'New WhatsApp contacts require both complete owner-policy consents');
}
expect_wcp(DB::table('crm_customer_sources')->where('customer_id', $new->id)->where('channel', 'whatsapp')->count() === 1,
    'Capture must establish exactly one WhatsApp source');
$old = '2026-10-01 10:00:00';
DB::table('crm_customers')->where('id', $new->id)->update([
    'service_consented_at'=>$old, 'marketing_consented_at'=>$old,
    'service_consent_evidence'=>'Earlier service', 'marketing_consent_evidence'=>'Earlier marketing',
    'service_consented_by'=>2, 'marketing_consented_by'=>2,
]);
$again = $whatsapp->capture(['phone'=>'01000000001', 'name'=>'Another name']);
expect_wcp($again->service_consented_at === $old && $again->marketing_consented_at === $old
    && $again->service_consent_evidence === 'Earlier service'
    && (int)$again->marketing_consented_by === 2, 'Repeated contact must preserve complete earlier consent metadata');
expect_wcp($again->name === 'Verified sender'
    && DB::table('crm_customer_sources')->where('customer_id', $new->id)->count() === 1,
    'Repeated capture must preserve established name and avoid duplicate links');

$directory->consent((int)$new->id, false, false, 'whatsapp-inbound:opt-out');
$stopped = $whatsapp->capture(['phone'=>'01000000001']);
expect_wcp(!(bool)$stopped->service_consent && !(bool)$stopped->marketing_consent
    && $stopped->opted_out_at !== null, 'An ordinary later message must never reverse STOP');

$partialId = seed_wcp('+201000000002', ['service_consent_evidence'=>'Recorded manual refusal', 'service_consented_by'=>2]);
$partial = $whatsapp->capture(['phone'=>'01000000002']);
expect_wcp(!(bool)$partial->service_consent && $partial->service_consent_evidence === 'Recorded manual refusal'
    && (bool)$partial->marketing_consent, 'Explicit purpose refusal must remain while the undecided purpose receives defaults');

$repairId = seed_wcp('+201000000003', [
    'service_consent'=>true, 'service_consented_at'=>$old, 'service_consent_evidence'=>'Keep service', 'service_consented_by'=>2,
    'marketing_consent'=>true, 'marketing_consent_evidence'=>'Keep marketing', 'marketing_consented_by'=>2,
]);
$repaired = $whatsapp->capture(['phone'=>'01000000003']);
expect_wcp($repaired->marketing_consented_at !== null
    && $repaired->marketing_consent_evidence === 'Keep marketing'
    && (int)$repaired->marketing_consented_by === 2
    && $repaired->service_consented_at === $old, 'Repair must fill missing active metadata without replacing populated metadata');

$count = DB::table('crm_customers')->count();
expect_wcp($whatsapp->capture(['phone'=>'not a phone']) === null
    && DB::table('crm_customers')->count() === $count, 'Invalid identities must not create contacts');

$generic = $directory->capture(['phone'=>'01000000004', 'name'=>'Ordinary delivery'], 'delivery', '');
expect_wcp(!(bool)$generic->service_consent && !(bool)$generic->marketing_consent,
    'Generic delivery capture must not inherit the WhatsApp rule');

$app->instance('owner.dashboard.runtime', ['fixture'=>true]);
$tenant = $whatsapp->capture(['phone'=>'01000000005']);
expect_wcp(!(bool)$tenant->service_consent && !(bool)$tenant->marketing_consent, 'Independent owners must not inherit primary-owner consent policy');
$app->offsetUnset('owner.dashboard.runtime');
$app['config']->set('desktop_dashboard.local', true);
$local = $whatsapp->capture(['phone'=>'01000000006']);
expect_wcp(!(bool)$local->service_consent && !(bool)$local->marketing_consent, 'Offline local capture must not invent primary consent');
$app['config']->set('desktop_dashboard.local', false);

$beforeFailure = all_wcp();
DB::unprepared("CREATE TRIGGER fail_wcp_source BEFORE INSERT ON crm_customer_sources BEGIN SELECT RAISE(ABORT, 'fixture-source-failure'); END");
expect_wcp(rejected_wcp(fn() => $whatsapp->capture(['phone'=>'01000000007'])), 'A source-write failure must reject capture');
expect_wcp(all_wcp() === $beforeFailure, 'Capture identity and automatic consent must roll back together');
DB::unprepared('DROP TRIGGER fail_wcp_source');

// Isolate bounded historical reconciliation from the live-capture fixtures.
foreach (['branch_operation_commands', 'crm_customer_sources', 'crm_customers'] as $table) DB::table($table)->delete();
$eligibleId = seed_wcp('+201000000011');
$optoutId = seed_wcp('+201000000012', ['opted_out_at'=>'2026-10-10 10:00:00', 'service_consent_evidence'=>'STOP', 'marketing_consent_evidence'=>'STOP']);
$deniedId = seed_wcp('+201000000013', [
    'service_consent_evidence'=>'Manual decline', 'service_consented_by'=>2,
    'marketing_consent'=>true, 'marketing_consented_at'=>$old, 'marketing_consent_evidence'=>'Existing marketing', 'marketing_consented_by'=>2,
]);
$outsideId = seed_wcp('+201000000014');
source_wcp($eligibleId);
source_wcp($optoutId);
source_wcp($deniedId);
source_wcp($outsideId, 'delivery');
$outsideBefore = row_wcp($outsideId);
$optoutBefore = row_wcp($optoutId);
$before = all_wcp();
$preview = wcp_preview();
expect_wcp(($preview['counts']['total'] ?? null) === 3 && ($preview['counts']['changed'] ?? null) === 1,
    'Historical scope must contain WhatsApp contacts only and identify the undecided target');
expect_wcp(($preview['counts']['skipped_optouts'] ?? null) === 1 && all_wcp() === $before
    && DB::table('branch_operation_commands')->count() === 0, 'Preview must preserve withdrawals and perform no writes');
$temp = sys_get_temp_dir().'/wcp-consent-test-'.bin2hex(random_bytes(6));
mkdir($temp, 0700);

// A receipt insertion failure must roll back all historical consent changes.
$beforeAuditFailure = all_wcp();
DB::unprepared("CREATE TRIGGER fail_wcp_receipt BEFORE INSERT ON branch_operation_commands BEGIN SELECT RAISE(ABORT, 'fixture-receipt-failure'); END");
expect_wcp(rejected_wcp(fn() => wcp_apply($temp)), 'A receipt-write failure must reject historical reconciliation');
expect_wcp(all_wcp() === $beforeAuditFailure && DB::table('branch_operation_commands')->count() === 0,
    'Historical consent changes and the immutable receipt must roll back together');
DB::unprepared('DROP TRIGGER fail_wcp_receipt');

$result = wcp_apply($temp);
expect_wcp(($result['status'] ?? '') === 'applied', 'Historical reconciliation must apply');
$eligible = row_wcp($eligibleId);
expect_wcp((bool)$eligible['service_consent'] && (bool)$eligible['marketing_consent'], 'Undecided historical WhatsApp contact must receive both consents');
expect_wcp(row_wcp($outsideId) === $outsideBefore && row_wcp($optoutId) === $optoutBefore,
    'Backfill must preserve non-WhatsApp customers and explicit withdrawals');
expect_wcp(!(bool)row_wcp($deniedId)['service_consent'], 'Backfill must preserve explicit purpose refusal');
expect_wcp(DB::table('branch_operation_commands')->count() === 1, 'Historical changes require a committed durable receipt');

DB::table('crm_customers')->where('id', $eligibleId)->update([
    'service_consent'=>false, 'marketing_consent'=>false, 'opted_out_at'=>'2026-11-07 10:00:00',
]);
$afterStop = all_wcp();
$repeat = wcp_apply($temp);
expect_wcp(($repeat['status'] ?? '') === 'already_applied'
    && ($repeat['data_writes'] ?? 0) === 0 && all_wcp() === $afterStop,
    'A historical replay must never restore consent withdrawn after the original application');

echo "WHATSAPP_CUSTOMER_CONSENT_TESTS_PASSED\n";
