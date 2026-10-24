<?php
declare(strict_types=1);
require __DIR__.'/../deployment/launch_reset_20261025/inspect.php';
$checks=0;
function lr25check(bool $value,string $label): void {global $checks;if(!$value)throw new RuntimeException($label);++$checks;}
function lr25blocked(callable $call,string $label): void {try{$call();}catch(Throwable $error){lr25check(true,$label);return;}throw new RuntimeException($label);}
$class=LaunchReset20261025Inspection::class;
lr25check($class::id('branch_stock')==='`branch_stock`','safe identifier quoted');
foreach (['bad;DELETE','path/name','a`b','',str_repeat('a',65)] as $name) lr25blocked(fn()=>$class::id($name),'unsafe identifier refused');
lr25check($class::canonical(['z'=>['b'=>1,'a'=>2],'a'=>3])===$class::canonical(['a'=>3,'z'=>['a'=>2,'b'=>1]]),'schema fingerprints ignore associative ordering');
$meta=['engine'=>'InnoDB','columns'=>['branch'=>['type'=>'varchar(30)','nullable'=>false,'default'=>'PRIVATE_DEFAULT_MUST_NOT_ESCAPE','extra'=>'']],'references'=>[],'unique_indexes'=>[]];
$public=$class::publicMeta($meta);
lr25check(!str_contains(json_encode($public),'PRIVATE_DEFAULT_MUST_NOT_ESCAPE'),'schema defaults redacted');
lr25check($public['columns']['branch']['default_sha256']===hash('sha256','PRIVATE_DEFAULT_MUST_NOT_ESCAPE'),'schema default remains hash-bound');
$report=$class::schemaReport(['branch_new'=>$meta],[]);
lr25check($report['extra_tables']===['branch_new']&&isset($report['changed_related_metadata']['branch_new']),'new related tables reported');
lr25check($class::schemaReport(['branch_new'=>$meta],$class::legacySchema(['branch_new'=>$meta]))['changed_tables']===[],'unchanged legacy schema recognized');
lr25check(!str_contains(json_encode($report),'PRIVATE_DEFAULT_MUST_NOT_ESCAPE'),'report does not expose defaults');
$large=[];for($i=0;$i<120;$i++){$item=$meta;for($j=0;$j<20;$j++)$item['columns']['column_'.$j]=$meta['columns']['branch'];$large['branch_'.$i]=$item;}
$largeReport=['schema'=>$class::schemaReport($large,[])];$encoded=$class::encodeReport($largeReport);$decoded=json_decode($encoded,true,512,JSON_THROW_ON_ERROR);
lr25check(strlen($encoded)<$class::LIMIT&&$decoded['schema']['metadata_compacted']===true,'large related schema has bounded compact representation');
lr25check($decoded['schema']['changed_related_metadata']['branch_1']['columns']['column_1']==='varchar(30)'&&isset($decoded['schema']['changed_related_metadata']['branch_1']['column_defaults_sha256']),'compaction preserves column types and default binding');

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
$pdo->sqliteCreateFunction('regexp',fn($pattern,$value)=>preg_match('~'.$pattern.'~D',(string)$value)===1,2);
$schema=[];
$make=function(string $table,array $columns,array $rows)use($pdo,&$schema):void{
    $defs=[];$meta=[];
    foreach($columns as $name=>$type){$defs[]=LaunchReset20261025Inspection::id($name).' '.$type;$meta[$name]=['type'=>$type,'nullable'=>true,'default'=>null,'extra'=>''];}
    $pdo->exec('CREATE TABLE '.LaunchReset20261025Inspection::id($table).' ('.implode(',',$defs).')');
    $schema[$table]=['engine'=>'InnoDB','columns'=>$meta,'references'=>[],'unique_indexes'=>[]];
    if(!$rows)return;$stmt=$pdo->prepare('INSERT INTO '.LaunchReset20261025Inspection::id($table).' VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
    foreach($rows as $row)$stmt->execute($row);
};
$make('users',['id'=>'INTEGER','balance'=>'TEXT','min_wallet'=>'TEXT'],[[1,'123.45','0'],[2,'900.00','10']]);
$make('wallets',['id'=>'INTEGER','from_user'=>'INTEGER','to_user'=>'INTEGER','amount'=>'TEXT','type'=>'TEXT','payment'=>'TEXT','status'=>'TEXT','transfer_reference'=>'TEXT','created_at'=>'TEXT','updated_at'=>'TEXT','order_id'=>'INTEGER'],[[1,1,2,'35.50','transfer','wallet','completed','PRIVATE_TRANSFER_SENTINEL','now','now',1]]);
$make('takeaway_tills',['id'=>'INTEGER','branch'=>'TEXT','balance_cents'=>'INTEGER'],[[1,'f:301',10000],[2,'g:900',30000],[3,'PRIVATE_BRANCH_SENTINEL',77]]);
$make('branch_inventory',['id'=>'INTEGER','branch'=>'TEXT','quantity_units'=>'INTEGER'],[[1,'f:301',2500],[2,'f:302',0]]);
$make('branch_stock',['id'=>'INTEGER','branch'=>'TEXT','quantity_units'=>'INTEGER'],[[1,'f:301',1500]]);
$make('resturants',['id'=>'INTEGER'],[[301]]);
$make('orders',['id'=>'INTEGER','resturant_id'=>'INTEGER','type'=>'TEXT','status'=>'TEXT'],[[1,301,'current','pending'],[2,301,'schedule',null],[3,999,'current','finished'],[4,null,'shipping','pending']]);
$make('payments',['id'=>'INTEGER','order_id'=>'INTEGER','status'=>'TEXT'],[[1,1,'1'],[2,2,null],[3,3,'0']]);
$make('notifications',['id'=>'INTEGER','type'=>'TEXT','data'=>'TEXT'],[[1,'App\\Notifications\\NotifyResturantOrderCreatedNotification','{"order_id":1,"phone":"PRIVATE_PHONE_SENTINEL"}']]);
$make('desktop_dashboard_devices',['id'=>'TEXT','enabled'=>'INTEGER'],[['device-a',1],['device-b',0]]);
$make('desktop_dashboard_commands',['sequence'=>'INTEGER','route_name'=>'TEXT','status'=>'TEXT'],[[1,'branch-expenses.save','pending'],[2,'takeaway.store','acknowledged'],[3,'PRIVATE HUMAN NAME','conflict']]);
$make('branch_operation_commands',['id'=>'INTEGER','branch'=>'TEXT','kind'=>'TEXT'],[[1,'f:301','employee.entry']]);
$before=$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA query_only=ON');
$financial=$class::financial($pdo,$schema);$desktop=$class::desktop($pdo,$schema);
lr25check($financial['table_counts']['takeaway_tills']['f_rows']===1&&$financial['table_counts']['takeaway_tills']['g_rows']===1&&$financial['table_counts']['takeaway_tills']['other_rows']===1,'financial scopes counted separately');
lr25check($financial['branch_values']===['f:301','f:302'],'only canonical branch identifiers leave collector');
lr25check((int)$financial['f_balance_aggregates']['takeaway_tills']['sum_units']===10000,'only restaurant drawer balances aggregated');
lr25check($financial['legacy_orders']['current_schedule_restaurant_orders']===2,'legacy orphan and shipping orders remain separately visible');
lr25check($financial['legacy_orders']['linked_counts']['payments']===2&&$financial['legacy_orders']['linked_counts']['wallets']===1,'child counts use selected parent identities');
lr25check($financial['legacy_orders']['payments_status_counts']==['1'=>1,'_null_'=>1],'gateway statuses counted without identifiers');
lr25check($desktop['desktop_dashboard_devices']===[0=>1,1=>1],'device enablement aggregate available');
lr25check($desktop['desktop_dashboard_commands']===['acknowledged'=>1,'conflict'=>1,'pending'=>1],'desktop pending state aggregate available');
lr25check($desktop['desktop_command_routes']['_other_']===1,'arbitrary route labels do not escape');
lr25check($financial['preservation_fingerprints']['wallets']['rows']===1&&strlen($financial['preservation_fingerprints']['wallets']['sha256'])===64,'wallet monetary rows fingerprinted');
$output=json_encode([$financial,$desktop],JSON_THROW_ON_ERROR);
foreach(['PRIVATE_TRANSFER_SENTINEL','PRIVATE_BRANCH_SENTINEL','PRIVATE_PHONE_SENTINEL','PRIVATE HUMAN NAME','123.45'] as $secret)lr25check(!str_contains($output,$secret),'business payloads remain private');
lr25check(strlen($output)<$class::LIMIT,'bounded fixture output');
lr25check($before===$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'collector preserves financial data');
lr25blocked(fn()=>$pdo->exec('DELETE FROM users'),'fixture connection really enforces read-only');
lr25check($class::grouped($pdo,$schema,'missing_table','status')===['available'=>false],'missing optional tables are reported');
lr25check($class::fingerprint($pdo,$schema,'users',['id','not_installed'])['missing_columns']===['not_installed'],'missing fingerprint columns are explicit');
$source=file_get_contents(__DIR__.'/../deployment/launch_reset_20261025/inspect.php');
lr25check(substr_count($source,'->exec(')===2&&str_contains($source,"->exec('SET TRANSACTION READ ONLY')"),'only reviewed transaction setup uses exec');
lr25check(strpos($source,"->exec('SET TRANSACTION READ ONLY')")<strpos($source,'->beginTransaction()'),'server read-only enforced before transaction');
lr25check(str_contains($source,'REFERENCED_TABLE_SCHEMA<>TABLE_SCHEMA')&&str_contains($source,"'foreign_key_visibility']='database_account'"),'cross-schema foreign references reported within account visibility');
foreach(['DELETE FROM','INSERT INTO','UPDATE ','TRUNCATE ','->commit(','->save(','bootProviders('] as $write)lr25check(!str_contains($source,$write),'no business writer/provider boot in inspector');
ob_start();$code=launchReset20261025InspectionMain(['inspect.php','wrong-root']);$failure=ob_get_clean();
lr25check($code===1&&str_contains($failure,'LAUNCH_RESET_INSPECTION_FAILED')&&!str_contains($failure,'wrong-root'),'invalid invocation prints only bounded reason');
echo 'PASS '.$checks.' launch reset inspection checks'.PHP_EOL;
