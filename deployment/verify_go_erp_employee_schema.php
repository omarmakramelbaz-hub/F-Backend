<?php
/** Metadata-only probe for the one reviewed employee migration. Never reads employee rows. */
function go_erp_employee_schema_report(array $records, int $ledger): array
{
    $wanted = [
        'id_front_path'=>['varchar',300], 'id_front_hash'=>['char',64], 'id_front_mime'=>['varchar',20],
        'id_back_path'=>['varchar',300], 'id_back_hash'=>['char',64], 'id_back_mime'=>['varchar',20],
        'regulation_version'=>['varchar',20], 'regulation_accepted_at'=>['timestamp',null],
        'regulation_accepted_by'=>['bigint',null],
    ];
    if ($ledger < 0 || $ledger > 1) throw new RuntimeException('GO_ERP_EMPLOYEE_SCHEMA_LEDGER_INVALID');
    $present = [];
    foreach ($records as $record) {
        $row = (array)$record; $name = $row['COLUMN_NAME'] ?? null;
        if (!is_string($name) || !isset($wanted[$name]) || isset($present[$name]))
            throw new RuntimeException('GO_ERP_EMPLOYEE_SCHEMA_COLUMNS_INVALID');
        [$type,$length] = $wanted[$name];
        if (($row['DATA_TYPE'] ?? null) !== $type || ($row['IS_NULLABLE'] ?? null) !== 'YES'
            || ($length !== null && (string)($row['CHARACTER_MAXIMUM_LENGTH'] ?? '') !== (string)$length)
            || ($type === 'bigint' && !preg_match('/^bigint(?:\([0-9]+\))? unsigned$/D', $row['COLUMN_TYPE'] ?? '')))
            throw new RuntimeException('GO_ERP_EMPLOYEE_SCHEMA_COLUMN_UNREVIEWED');
        $present[$name] = true;
    }
    $names = array_keys($present); sort($names,SORT_STRING);
    if ($ledger === 1 && count($names) !== count($wanted))
        throw new RuntimeException('GO_ERP_EMPLOYEE_SCHEMA_LEDGER_INCOMPLETE');
    return ['format'=>1, 'kind'=>'go-erp-employee-schema', 'ok'=>true,
        'column_count'=>count($names), 'complete'=>count($names)===count($wanted), 'ledger'=>$ledger,
        'signature'=>hash('sha256',implode("\n",$names)."\nledger=".$ledger), 'business_rows_read'=>false];
}

if (defined('GO_ERP_EMPLOYEE_SCHEMA_TEST')) return;
try {
    require '/home/fasakha/public_html/vendor/autoload.php';
    $app = require '/home/fasakha/public_html/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION READ ONLY');
    $columns = Illuminate\Support\Facades\DB::select(
        "SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,COLUMN_TYPE
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branch_employees'
         AND COLUMN_NAME IN ('id_front_path','id_front_hash','id_front_mime','id_back_path','id_back_hash','id_back_mime',
                            'regulation_version','regulation_accepted_at','regulation_accepted_by')"
    );
    // A missing base table must not be mistaken for a pristine migration target.
    if (!Illuminate\Support\Facades\Schema::hasTable('branch_employees'))
        throw new RuntimeException('GO_ERP_EMPLOYEE_SCHEMA_TABLE_MISSING');
    $ledger = Illuminate\Support\Facades\DB::table('migrations')
        ->where('migration','2026_10_23_200000_add_employee_identity_and_regulation')->count();
    echo json_encode(go_erp_employee_schema_report($columns,(int)$ledger),JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $reason = preg_match('/^GO_ERP_EMPLOYEE_SCHEMA_[A-Z_]+$/D',$error->getMessage())
        ? $error->getMessage() : 'GO_ERP_EMPLOYEE_SCHEMA_PRIVATE_FAILURE';
    echo json_encode(['format'=>1,'kind'=>'go-erp-employee-schema','ok'=>false,'reason'=>$reason],JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
