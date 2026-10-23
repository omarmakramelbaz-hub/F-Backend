<?php
define('GO_ERP_EMPLOYEE_SCHEMA_TEST',true);
require __DIR__.'/../deployment/verify_go_erp_employee_schema.php';
function check_schema($condition,$message){if(!$condition)throw new RuntimeException($message);}
function refused_schema(callable $work){try{$work();}catch(Throwable $error){check_schema(str_starts_with($error->getMessage(),'GO_ERP_EMPLOYEE_SCHEMA_'),'Raw metadata escaped into failure.');return;}throw new RuntimeException('Invalid schema metadata accepted.');}
function fixture_columns(){
    $columns=[];
    foreach(['front','back'] as $side)foreach(['path'=>['varchar',300],'hash'=>['char',64],'mime'=>['varchar',20]] as $suffix=>[$type,$size])
        $columns[]=['COLUMN_NAME'=>'id_'.$side.'_'.$suffix,'DATA_TYPE'=>$type,'IS_NULLABLE'=>'YES','CHARACTER_MAXIMUM_LENGTH'=>$size,'COLUMN_TYPE'=>$type.'('.$size.')'];
    $columns[]=['COLUMN_NAME'=>'regulation_version','DATA_TYPE'=>'varchar','IS_NULLABLE'=>'YES','CHARACTER_MAXIMUM_LENGTH'=>20,'COLUMN_TYPE'=>'varchar(20)'];
    $columns[]=['COLUMN_NAME'=>'regulation_accepted_at','DATA_TYPE'=>'timestamp','IS_NULLABLE'=>'YES','CHARACTER_MAXIMUM_LENGTH'=>null,'COLUMN_TYPE'=>'timestamp'];
    $columns[]=['COLUMN_NAME'=>'regulation_accepted_by','DATA_TYPE'=>'bigint','IS_NULLABLE'=>'YES','CHARACTER_MAXIMUM_LENGTH'=>null,'COLUMN_TYPE'=>'bigint(20) unsigned'];
    return array_map(static fn($column)=>$column+['COLUMN_DEFAULT'=>null,'EXTRA'=>'','GENERATION_EXPRESSION'=>null],$columns);
}
$empty=go_erp_employee_schema_report([],0);check_schema(!$empty['complete']&&$empty['column_count']===0&&$empty['ledger']===0,'Pristine schema incorrectly marked ready.');
$columns=fixture_columns();$ready=go_erp_employee_schema_report($columns,1);
check_schema($ready['complete']&&$ready['column_count']===9&&$ready['ledger']===1&&$ready['business_rows_read']===false,'Complete schema not accepted.');
check_schema(go_erp_employee_schema_report(array_reverse($columns),1)===$ready,'Metadata row order affects readiness.');
check_schema(!go_erp_employee_schema_report(array_slice($columns,0,4),0)['complete'],'Partial schema not recognized for idempotent completion.');
refused_schema(static fn()=>go_erp_employee_schema_report(array_slice($columns,0,4),1));
foreach(['DATA_TYPE'=>'text','IS_NULLABLE'=>'NO','CHARACTER_MAXIMUM_LENGTH'=>299] as $field=>$value){$bad=$columns;$bad[0][$field]=$value;refused_schema(static fn()=>go_erp_employee_schema_report($bad,0));}
$bad=$columns;$bad[8]['COLUMN_TYPE']='bigint(20)';refused_schema(static fn()=>go_erp_employee_schema_report($bad,0));
$good=$columns;$good[8]['COLUMN_TYPE']='bigint unsigned';check_schema(go_erp_employee_schema_report($good,1)===$ready,'MariaDB/MySQL integer display width changed readiness.');
$good=$columns;foreach($good as &$column){$column['COLUMN_DEFAULT']='NULL';$column['GENERATION_EXPRESSION']='';}unset($column);
check_schema(go_erp_employee_schema_report($good,1)===$ready,'MariaDB nullable defaults changed readiness.');
foreach([
    [7,'COLUMN_TYPE','timestamp(6)'],[0,'COLUMN_TYPE','varchar(299)'],[8,'COLUMN_TYPE','bigint unsigned zerofill'],
    [7,'COLUMN_DEFAULT','current_timestamp()'],[7,'EXTRA','on update current_timestamp()'],
    [0,'COLUMN_DEFAULT',"'NULL'"],[0,'COLUMN_DEFAULT',''],[8,'COLUMN_DEFAULT','1'],
    [0,'EXTRA','VIRTUAL GENERATED'],[0,'GENERATION_EXPRESSION','concat(id)'],
] as [$index,$field,$value]){$bad=$columns;$bad[$index][$field]=$value;refused_schema(static fn()=>go_erp_employee_schema_report($bad,0));}
foreach(['COLUMN_DEFAULT','EXTRA','GENERATION_EXPRESSION'] as $field){$bad=$columns;unset($bad[0][$field]);refused_schema(static fn()=>go_erp_employee_schema_report($bad,0));}
refused_schema(static fn()=>go_erp_employee_schema_report([...$columns,$columns[0]],0));
$bad=$columns;$bad[0]['COLUMN_NAME']='PRIVATE unknown employee column';refused_schema(static fn()=>go_erp_employee_schema_report($bad,0));
foreach([-1,2] as $ledger)refused_schema(static fn()=>go_erp_employee_schema_report($columns,$ledger));
check_schema($empty['signature']!==$ready['signature']&&go_erp_employee_schema_report($columns,0)['signature']!==$ready['signature'],'Schema/migration-ledger changes not observable.');
echo "PASS metadata-only employee schema proof\n";
