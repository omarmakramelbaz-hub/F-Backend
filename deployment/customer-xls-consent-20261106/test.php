<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/tests/owner_mobile_runtime/vendor/autoload.php';
define('XLS_CONSENT_LIBRARY', true);
require __DIR__.'/consent.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};

function expect_xc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

putenv('XLS_CONSENT_TESTING=1');
$app = new Container;
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository);
$app->instance('date', new Illuminate\Support\DateFactory);
$capsule = new Capsule($app);
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
$capsule->setAsGlobal();
$app->instance('db', $capsule->getDatabaseManager());
$app->bind('db.schema', fn() => $capsule->getConnection()->getSchemaBuilder());

Schema::create('crm_customers', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->string('phone', 20)->unique();
    $t->string('name', 100)->nullable();
    foreach (['service', 'marketing'] as $purpose) {
        $t->boolean($purpose.'_consent')->default(false);
        $t->timestamp($purpose.'_consented_at')->nullable();
        $t->string($purpose.'_consent_evidence', 500)->nullable();
        $t->unsignedBigInteger($purpose.'_consented_by')->nullable();
    }
    $t->timestamp('opted_out_at')->nullable();
    $t->timestamp('updated_at')->nullable();
});
Schema::create('legacy_customer_import_datasets', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->unsignedInteger('schema_version');
    $t->string('data_sha256', 64)->unique();
    $t->string('backup_sha256', 64);
    $t->unsignedBigInteger('raw_count');
    $t->unsignedBigInteger('profile_count');
    $t->string('branch', 30);
});
Schema::create('legacy_customer_import_profiles', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->unsignedBigInteger('dataset_id');
    $t->string('phone', 20)->nullable();
    $t->unsignedBigInteger('crm_customer_id')->nullable();
});
Schema::create('branch_operation_commands', function (Blueprint $t): void {
    $t->bigIncrements('id');
    $t->string('branch', 30);
    $t->unsignedBigInteger('actor_id');
    $t->uuid('request_key');
    $t->string('request_hash', 64);
    $t->string('kind', 40);
    $t->longText('result');
    $t->timestamps();
    $t->unique(['branch', 'actor_id', 'request_key'], 'branch_operation_request_unique');
});

function fixture_xc(): void
{
    foreach (['branch_operation_commands', 'legacy_customer_import_profiles', 'legacy_customer_import_datasets', 'crm_customers'] as $table) {
        DB::table($table)->delete();
    }
    $old = '2026-10-01 10:00:00';
    $rows = [];
    for ($id = 1; $id <= 8; $id++) {
        $row = ['id'=>$id, 'phone'=>'+20100000000'.$id, 'name'=>'Fixture '.$id,
            'service_consent'=>false, 'marketing_consent'=>false, 'opted_out_at'=>null, 'updated_at'=>$old];
        foreach (['service', 'marketing'] as $purpose) {
            $row[$purpose.'_consented_at'] = null;
            $row[$purpose.'_consent_evidence'] = null;
            $row[$purpose.'_consented_by'] = null;
        }
        $rows[$id] = $row;
    }
    // Older CRM identities can retain their original local-format phone.
    $rows[1]['phone'] = '01000000001';
    foreach ([2=>['service'], 3=>['service','marketing'], 4=>['service','marketing'],
        5=>['service','marketing'], 6=>['marketing'], 7=>['service','marketing']] as $id=>$purposes) {
        foreach ($purposes as $purpose) {
            $rows[$id][$purpose.'_consent'] = true;
            $rows[$id][$purpose.'_consented_at'] = $old;
            $rows[$id][$purpose.'_consent_evidence'] = 'Earlier '.$purpose.' evidence';
            $rows[$id][$purpose.'_consented_by'] = 2;
        }
    }
    $rows[4]['service_consented_at'] = null;
    $rows[4]['marketing_consent_evidence'] = null;
    $rows[5]['opted_out_at'] = '2026-10-10 10:00:00';
    $rows[7]['service_consent_evidence'] = '';
    DB::table('crm_customers')->insert(array_values($rows));
    $dataset = DB::table('legacy_customer_import_datasets')->insertGetId([
        'schema_version'=>1, 'data_sha256'=>XC_DATA_SHA, 'backup_sha256'=>XC_SOURCE_SHA,
        'raw_count'=>9, 'profile_count'=>9, 'branch'=>'',
    ]);
    for ($id = 1; $id <= 7; $id++) {
        DB::table('legacy_customer_import_profiles')->insert([
            'dataset_id'=>$dataset, 'phone'=>'+20100000000'.$id, 'crm_customer_id'=>$id,
        ]);
    }
    DB::table('legacy_customer_import_profiles')->insert([
        ['dataset_id'=>$dataset, 'phone'=>'+201000000001', 'crm_customer_id'=>1],
        ['dataset_id'=>$dataset, 'phone'=>null, 'crm_customer_id'=>null],
    ]);
}

function state_xc(): string
{
    return json_encode(DB::table('crm_customers')->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR);
}

function rejected_xc(callable $work): bool
{
    try {
        $result = $work();
        return is_array($result) && ($result['status'] ?? '') === 'failed';
    } catch (Throwable $e) {
        return true;
    }
}

$temp = sys_get_temp_dir().'/xls-consent-test-'.bin2hex(random_bytes(6));
mkdir($temp, 0700);
fixture_xc();
$before = state_xc();
$preview = xc_preview();
expect_xc(($preview['counts']['total'] ?? null) === 7, 'Duplicate phone profiles must count one customer');
expect_xc(($preview['counts']['changed'] ?? null) === 6, 'Preview must include missing dates and missing evidence');
expect_xc(($preview['counts']['unchanged'] ?? null) === 1, 'Complete active consent must remain unchanged');
expect_xc(($preview['counts']['reactivated_optouts'] ?? null) === 1, 'A fresh owner attestation must count earlier opt-outs separately');
expect_xc(state_xc() === $before && DB::table('branch_operation_commands')->count() === 0, 'Preview must write no consent or receipt');

$outsider = (array) DB::table('crm_customers')->where('id', 8)->first();
$complete = (array) DB::table('crm_customers')->where('id', 3)->first();
$result = xc_apply($temp.'/success');
expect_xc(($result['status'] ?? '') === 'applied', 'First attestation must apply successfully');
expect_xc(($result['counts']['total'] ?? null) === 7 && ($result['counts']['changed'] ?? null) === 6,
    'Applied counts must match the distinct imported customer scope');
expect_xc(($result['counts']['service_eligible'] ?? null) === 7 && ($result['counts']['marketing_eligible'] ?? null) === 7,
    'Every imported target must be eligible for both messaging purposes');
expect_xc(($result['counts']['reactivated_optouts'] ?? null) === 1, 'Applied count must report refreshed opt-outs');
expect_xc((array) DB::table('crm_customers')->where('id', 8)->first() === $outsider, 'Customers outside this XLS must not change');
expect_xc((array) DB::table('crm_customers')->where('id', 3)->first() === $complete, 'Existing complete consent metadata must be preserved');
expect_xc(DB::table('crm_customers')->where('id', 1)->value('phone') === '01000000001', 'Consent updates must preserve CRM identity and stored phone');
expect_xc(DB::table('crm_customers')->where('id', 2)->value('service_consent_evidence') === 'Earlier service evidence',
    'Approving marketing must preserve earlier complete service evidence');
expect_xc(DB::table('crm_customers')->where('id', 6)->value('marketing_consented_by') === 2,
    'Approving service must preserve earlier complete marketing attribution');
foreach (DB::table('crm_customers')->whereBetween('id', [1, 7])->get() as $row) {
    foreach (['service', 'marketing'] as $purpose) {
        expect_xc((bool) $row->{$purpose.'_consent'} && $row->{$purpose.'_consented_at'} !== null
            && trim((string) $row->{$purpose.'_consent_evidence'}) !== '', 'Both purposes need flags, timestamps and evidence');
    }
    expect_xc($row->opted_out_at === null, 'The fresh attestation must clear older withdrawal on targeted contacts');
}
$reactivated = DB::table('crm_customers')->where('id', 5)->first();
expect_xc($reactivated->service_consented_at !== '2026-10-01 10:00:00'
    && $reactivated->marketing_consented_at !== '2026-10-01 10:00:00'
    && (int) $reactivated->service_consented_by === 1 && (int) $reactivated->marketing_consented_by === 1,
    'A reconsent must carry fresh dates and the owner attribution');
expect_xc(DB::table('branch_operation_commands')->count() === 1, 'One immutable operation receipt must be committed');
expect_xc(is_file(($result['backup_directory'] ?? '').'/before.json'), 'A private before snapshot must accompany the update');

// Re-running this instruction cannot undo a withdrawal received after it was applied.
DB::table('crm_customers')->where('id', 1)->update([
    'service_consent'=>false, 'marketing_consent'=>false, 'opted_out_at'=>'2026-11-07 10:00:00',
]);
$afterWithdrawal = state_xc();
$repeat = xc_apply($temp.'/repeat');
expect_xc(($repeat['status'] ?? '') === 'already_applied' && ($repeat['data_writes'] ?? null) === 0,
    'A repeat must return the existing receipt without data writes');
expect_xc(state_xc() === $afterWithdrawal && DB::table('branch_operation_commands')->count() === 1,
    'A repeat must preserve later customer withdrawals');

// Receipt and customer consent are one transaction; failed audit insertion rolls all of it back.
fixture_xc();
$beforeFailure = state_xc();
DB::unprepared("CREATE TRIGGER fail_xls_consent_receipt BEFORE INSERT ON branch_operation_commands BEGIN SELECT RAISE(ABORT, 'fixture-receipt-failure'); END");
expect_xc(rejected_xc(fn() => xc_apply($temp.'/rollback')), 'Injected audit failure must reject the update');
expect_xc(state_xc() === $beforeFailure && DB::table('branch_operation_commands')->count() === 0,
    'An audit failure must roll back every consent change and the receipt');
DB::unprepared('DROP TRIGGER fail_xls_consent_receipt');

fixture_xc();
DB::table('legacy_customer_import_datasets')->update(['backup_sha256'=>str_repeat('0', 64)]);
$scopeBefore = state_xc();
expect_xc(rejected_xc(fn() => xc_apply($temp.'/source-mismatch')), 'A different source hash must reject consent registration');
expect_xc(state_xc() === $scopeBefore && DB::table('branch_operation_commands')->count() === 0,
    'A rejected source must not change customers or receipts');

fixture_xc();
DB::table('legacy_customer_import_profiles')->where('crm_customer_id', 7)->delete();
expect_xc(rejected_xc(fn() => xc_preview()), 'Missing profile mapping must reject an incomplete dataset');

echo "CUSTOMER_XLS_CONSENT_TRANSACTION_TESTS_PASSED\n";
