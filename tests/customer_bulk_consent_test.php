<?php
/** Real SQLite customer/receipt migrations and private filesystem backups; no .env, network or sends. */
$autoload = getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require $autoload;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/app/Services/Dashboard/CustomerDirectory.php';
require dirname(__DIR__).'/deployment/record_existing_customer_consents.php';
require dirname(__DIR__).'/database/migrations/2026_10_04_080000_create_branch_operations.php';
require dirname(__DIR__).'/database/migrations/2026_10_17_000001_create_crm_customer_directory.php';

$checks = 0;
function bulkCheck(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); echo 'PASS '.$message.PHP_EOL; }
function bulkReject(callable $action, string $reason): void
{
    try { $action(); } catch (RuntimeException $error) { bulkCheck($error->getMessage() === $reason, $reason); return; }
    throw new RuntimeException('Expected '.$reason);
}
function fixtureBulk(): void
{
    $container = new Container; Container::setInstance($container); $db = new Capsule($container);
    $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true]);
    $db->setAsGlobal(); $db->bootEloquent();
    $container->instance('db', $db->getDatabaseManager());
    $container->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances(); Facade::setFacadeApplication($container);
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable(); });
    DB::table('users')->insert(['id'=>1, 'account_type'=>'admin', 'owner_resturant_id'=>null]);
    Schema::create('pos_service_tickets', fn(Blueprint $t) => $t->id());
    (new CreateBranchOperations)->up(); (new CreateCrmCustomerDirectory)->up();
    // Sentinels must remain byte-for-byte untouched by the consent import.
    foreach (['crm_whatsapp_settings', 'crm_whatsapp_campaigns', 'crm_whatsapp_deliveries'] as $table) {
        Schema::create($table, function (Blueprint $t) { $t->id(); $t->string('sentinel'); });
        DB::table($table)->insert(['sentinel'=>'existing-'.$table]);
    }
}
function customerBulk(array $changes = []): int
{
    static $phone = 1000000000;
    return DB::table('crm_customers')->insertGetId($changes + ['phone'=>'+20'.(++$phone), 'phone_key'=>(string)$phone,
        'first_seen_at'=>'2026-10-01 10:00:00', 'last_seen_at'=>'2026-10-01 10:00:00',
        'created_at'=>'2026-10-01 10:00:00', 'updated_at'=>'2026-10-01 10:00:00']);
}
function customerStateBulk(int $id): array { return (array)DB::table('crm_customers')->where('id', $id)->first(); }
function fakeBackupBulk(array $body): array { return ['path'=>'/private/import/before.json', 'sha256'=>hash('sha256', json_encode($body))]; }

fixtureBulk();
$untouched = customerBulk();
$approved = customerBulk(['service_consent'=>true, 'service_consented_at'=>'2026-10-05 11:00:00',
    'service_consent_evidence'=>'original signed service permission', 'service_consented_by'=>8,
    'marketing_consent'=>true, 'marketing_consented_at'=>'2026-10-06 12:00:00',
    'marketing_consent_evidence'=>'original signed promotion permission', 'marketing_consented_by'=>9]);
$marketingStopped = customerBulk(['marketing_consent'=>false, 'marketing_consent_evidence'=>'customer withdrew promotions', 'marketing_consented_by'=>3]);
$serviceStopped = customerBulk(['service_consent'=>false, 'service_consent_evidence'=>'customer withdrew service', 'service_consented_by'=>4]);
$globalStopped = customerBulk(['opted_out_at'=>'2026-10-07 13:00:00', 'service_consent_evidence'=>'whatsapp-inbound:opt-out', 'marketing_consent_evidence'=>'whatsapp-inbound:opt-out']);
$invalid = customerBulk(['phone'=>'01000000123']);
$audited = customerBulk(['service_consented_at'=>'2026-10-07 12:00:00', 'marketing_consented_by'=>5]);
$prior = [];
foreach ([$untouched, $approved, $marketingStopped, $serviceStopped, $globalStopped, $invalid, $audited] as $id) $prior[$id] = customerStateBulk($id);
$sentinels = [];
foreach (['crm_whatsapp_settings', 'crm_whatsapp_campaigns', 'crm_whatsapp_deliveries'] as $table) $sentinels[$table] = DB::table($table)->get()->toJson();
$import = new CustomerCrmConsentImport(fn() => '2026-10-17 15:56:54');
$preview = $import->run();
bulkCheck(!$preview['applied'] && !$preview['replayed'] && $preview['counts']['snapshot_customers'] === 7, 'default preview snapshots existing rows');
bulkCheck($preview['counts']['customers_changed'] === 3 && $preview['counts']['service_granted'] === 2 && $preview['counts']['marketing_granted'] === 2, 'preview accurately counts eligible purpose grants');
bulkCheck(DB::table('branch_operation_commands')->count() === 0 && customerStateBulk($untouched) === $prior[$untouched], 'preview writes neither customers nor receipt');
bulkReject(fn() => $import->run(true, false, 'fakeBackupBulk'), 'OWNER_CONFIRMATION_REQUIRED');
bulkReject(fn() => $import->run(true, true), 'PRIVATE_BACKUP_REQUIRED');

$captured = null; $newAfterSnapshot = null;
$result = $import->run(true, true, function (array $body) use (&$captured, &$newAfterSnapshot) {
    $captured = $body;
    bulkCheck(!(bool)DB::table('crm_customers')->orderBy('id')->value('service_consent'), 'backup callback precedes first customer update');
    // Simulates a customer created after this operation took its fixed snapshot.
    $newAfterSnapshot = customerBulk();
    return fakeBackupBulk($body);
});
bulkCheck($result['applied'] && !$result['replayed'] && $result['counts'] === $preview['counts'], 'apply uses the previewed eligibility rules');
bulkCheck(count($captured['before_state']) === 3 && $captured['before_state'][0]['before']['id'] === $untouched, 'backup contains before-state for changed rows only');
$backupText = json_encode($captured['before_state'], JSON_UNESCAPED_UNICODE);
bulkCheck(!str_contains($backupText, 'phone') && !str_contains($backupText, 'name') && !str_contains($backupText, '+20'), 'backup excludes customer phone and profile PII');
bulkCheck($captured['original_obtained_at'] === null && $captured['original_method'] === 'unknown', 'historical permission time and method are not fabricated');
$fresh = customerStateBulk($untouched);
bulkCheck((bool)$fresh['service_consent'] && (bool)$fresh['marketing_consent'], 'owner confirmation grants both untouched purpose defaults');
bulkCheck($fresh['service_consented_by'] === 1 && $fresh['service_consented_at'] === '2026-10-17 15:56:54', 'actual recording time and primary owner attribution are stamped');
bulkCheck(str_contains($fresh['service_consent_evidence'], 'Owner-confirmed prior') && str_contains($fresh['service_consent_evidence'], 'original_obtained_at=unknown') && str_contains($fresh['service_consent_evidence'], CustomerCrmConsentImport::RUN_ID), 'evidence truthfully identifies declaration and import event');
bulkCheck(customerStateBulk($approved) === $prior[$approved], 'existing valid permissions and their complete audit remain unchanged');
$row = customerStateBulk($marketingStopped);
bulkCheck((bool)$row['service_consent'] && !(bool)$row['marketing_consent'] && $row['marketing_consent_evidence'] === $prior[$marketingStopped]['marketing_consent_evidence'] && $row['marketing_consented_by'] === 3, 'marketing-only withdrawal is preserved while untouched service is granted');
$row = customerStateBulk($serviceStopped);
bulkCheck(!(bool)$row['service_consent'] && (bool)$row['marketing_consent'] && $row['service_consent_evidence'] === $prior[$serviceStopped]['service_consent_evidence'] && $row['service_consented_by'] === 4, 'service-only withdrawal is preserved while untouched marketing is granted');
bulkCheck(customerStateBulk($globalStopped) === $prior[$globalStopped] && $result['counts']['global_opt_out_excluded'] === 1, 'global STOP is wholly excluded without audit rewrite');
bulkCheck(customerStateBulk($invalid) === $prior[$invalid] && $result['counts']['invalid_phone_excluded'] === 1, 'invalid or noncanonical identities are excluded');
bulkCheck(customerStateBulk($audited) === $prior[$audited], 'false purposes with old timestamp or actor audit cannot be imported');
bulkCheck(!(bool)customerStateBulk($newAfterSnapshot)['service_consent'] && $result['counts']['snapshot_max_id'] < $newAfterSnapshot, 'customers created after snapshot are excluded');
$receipt = DB::table('branch_operation_commands')->first();
bulkCheck(DB::table('branch_operation_commands')->count() === 1 && $receipt->kind === 'customer.consent.import' && $receipt->branch === 'crm:all' && $receipt->actor_id === 1 && $receipt->request_key === CustomerCrmConsentImport::RUN_ID, 'durable immutable receipt identifies one global owner event');
bulkCheck(json_decode($receipt->result, true)['backup']['sha256'] === $result['backup']['sha256'], 'receipt binds the durable before-state backup digest');
DB::table('crm_customers')->where('id', $untouched)->update(['service_consent'=>false, 'marketing_consent'=>false, 'opted_out_at'=>'2026-10-17 16:00:00', 'service_consent_evidence'=>'later STOP']);
$laterState = customerStateBulk($untouched); $laterNew = customerBulk(); $backupCalls = 0;
$replay = $import->run(true, true, function () use (&$backupCalls) { $backupCalls++; throw new RuntimeException('MUST_NOT_RUN'); });
bulkCheck($replay['replayed'] && $replay['counts'] === $result['counts'] && $backupCalls === 0, 'replay returns original receipt before taking another snapshot or backup');
bulkCheck(customerStateBulk($untouched) === $laterState && !(bool)customerStateBulk($laterNew)['marketing_consent'], 'replay cannot revive later STOP or include new customers');
bulkCheck(DB::table('branch_operation_commands')->count() === 1 && DB::table('branch_operation_commands')->first()->result === $receipt->result, 'replay never rewrites immutable receipt');
foreach ($sentinels as $table => $before) bulkCheck(DB::table($table)->get()->toJson() === $before, $table.' remains unchanged');

fixtureBulk(); $id = customerBulk(); $before = customerStateBulk($id);
bulkReject(fn() => $import->run(true, true, function () { throw new RuntimeException('BACKUP_FIXTURE_FAILED'); }), 'BACKUP_FIXTURE_FAILED');
bulkCheck(customerStateBulk($id) === $before && DB::table('branch_operation_commands')->count() === 0, 'backup failure rolls back every change and receipt');
$retry = $import->run(true, true, 'fakeBackupBulk');
bulkCheck($retry['applied'] && !(bool)$retry['replayed'], 'a completely rolled-back failure can be retried safely');

fixtureBulk(); $id = customerBulk(); $before = customerStateBulk($id);
DB::statement("CREATE TRIGGER reject_import_receipt BEFORE INSERT ON branch_operation_commands BEGIN SELECT RAISE(ABORT, 'receipt fixture failure'); END");
try { $import->run(true, true, 'fakeBackupBulk'); throw new RuntimeException('Expected receipt failure'); }
catch (\Illuminate\Database\QueryException $error) { bulkCheck(customerStateBulk($id) === $before && DB::table('branch_operation_commands')->count() === 0, 'receipt failure atomically rolls back customer grants'); }

fixtureBulk(); DB::table('users')->where('id', 1)->update(['account_type'=>'vendor']);
bulkReject(fn() => $import->run(), 'PRIMARY_OWNER_UNAVAILABLE');
DB::table('users')->where('id', 1)->update(['account_type'=>'admin', 'owner_resturant_id'=>20]);
bulkReject(fn() => $import->run(), 'PRIMARY_OWNER_UNAVAILABLE');

fixtureBulk(); $zero = $import->run(true, true, 'fakeBackupBulk'); customerBulk();
bulkCheck($zero['counts']['snapshot_customers'] === 0 && $import->run(true, true, 'fakeBackupBulk')['replayed'], 'even an empty successful snapshot cannot enroll future customers on retry');

// Exercise actual private writer permissions, content durability and symlink refusal.
$scratch = sys_get_temp_dir().'/customer-bulk-consent-'.bin2hex(random_bytes(8));
mkdir($scratch, 0700); mkdir($scratch.'/public_html', 0700); $root = $scratch.'/public_html';
try {
    bulkCheck(CustomerCrmConsentPrivateBackup::snapshotMaximum($root) === null && !file_exists($scratch.'/customer-crm-consent-imports'), 'preview ceiling lookup creates no private files or directories');
    $writer = new CustomerCrmConsentPrivateBackup($root);
    fixtureBulk(); $afterFailureStopped = customerBulk(); $afterFailureGranted = customerBulk();
    DB::statement("CREATE TRIGGER reject_import_receipt BEFORE INSERT ON branch_operation_commands BEGIN SELECT RAISE(ABORT, 'receipt fixture failure'); END");
    try { $import->run(true, true, [$writer, 'save']); throw new RuntimeException('Expected receipt failure'); }
    catch (\Illuminate\Database\QueryException $error) { bulkCheck(DB::table('branch_operation_commands')->count() === 0 && !(bool)customerStateBulk($afterFailureGranted)['service_consent'], 'failed receipt rolls back grants after an actual durable before-backup'); }
    unset($error); // An exception trace retains its callable arguments and therefore the writer's file lock.
    $ceiling = CustomerCrmConsentPrivateBackup::snapshotMaximum($root);
    bulkCheck($ceiling === $afterFailureGranted, 'durable failed-attempt backup retains its original customer ceiling');
    $laterAfterFailure = customerBulk();
    DB::table('crm_customers')->where('id', $afterFailureStopped)->update(['opted_out_at'=>'2026-10-17 16:00:00', 'service_consent_evidence'=>'later STOP']);
    DB::statement('DROP TRIGGER reject_import_receipt');
    $retryPreview = $import->run(false, false, null, $ceiling);
    bulkCheck($retryPreview['counts']['snapshot_customers'] === 2 && $retryPreview['counts']['snapshot_max_id'] === $ceiling, 'preview reuses the saved audience ceiling after a failed attempt');
    $retry = $import->run(true, true, [$writer, 'save'], $ceiling);
    bulkCheck($retry['counts']['customers_changed'] === 1 && (bool)customerStateBulk($afterFailureGranted)['marketing_consent'] && !(bool)customerStateBulk($laterAfterFailure)['marketing_consent'], 'retry after receipt rollback excludes customers created after first attempted apply');
    bulkCheck(!(bool)customerStateBulk($afterFailureStopped)['service_consent'] && customerStateBulk($afterFailureStopped)['opted_out_at'] !== null, 'retry refreshes current audit and preserves a STOP received after the failed attempt');
    $saved = $retry['backup'];
    bulkCheck(is_file($saved['path']) && hash_file('sha256', $saved['path']) === $saved['sha256'], 'private writer durably saves exact checksum content');
    bulkCheck((fileperms($saved['path']) & 0777) === 0600 && (fileperms(dirname($saved['path'])) & 0777) === 0700 && (fileperms(dirname(dirname($saved['path']))) & 0777) === 0700, 'backup files are 0600 and directories 0700');
    file_put_contents($saved['path'], '{corrupt');
    bulkReject(fn() => CustomerCrmConsentPrivateBackup::snapshotMaximum($root), 'CRM_IMPORT_BACKUP_INVALID');
    file_put_contents($saved['path'], json_encode(['run_id'=>CustomerCrmConsentImport::RUN_ID, 'recorded_by'=>1, 'counts'=>['snapshot_max_id'=>'2']]));
    bulkReject(fn() => CustomerCrmConsentPrivateBackup::snapshotMaximum($root), 'CRM_IMPORT_BACKUP_INVALID');
    file_put_contents($saved['path'], json_encode(['run_id'=>'different-run', 'recorded_by'=>1, 'counts'=>['snapshot_max_id'=>2]]));
    bulkReject(fn() => CustomerCrmConsentPrivateBackup::snapshotMaximum($root), 'CRM_IMPORT_BACKUP_INVALID');
    unset($writer); unlink($saved['path']); symlink($scratch.'/unrelated', $saved['path']);
    $writer = new CustomerCrmConsentPrivateBackup($root);
    bulkReject(fn() => $writer->save([]), 'PRIVATE_BACKUP_SYMLINK_REJECTED'); unset($writer);
    unlink($saved['path']); rmdir(dirname($saved['path']));
    symlink($root, dirname($saved['path']));
    bulkReject(fn() => new CustomerCrmConsentPrivateBackup($root), 'PRIVATE_BACKUP_SYMLINK_REJECTED');
    unlink(dirname($saved['path'])); unlink($scratch.'/customer-crm-consent-imports/import.lock');
    rmdir($scratch.'/customer-crm-consent-imports');
} finally {
    if (is_dir($root)) rmdir($root);
    if (is_dir($scratch)) rmdir($scratch);
}
echo 'Customer bulk consent: '.$checks.' checks passed.'.PHP_EOL;
