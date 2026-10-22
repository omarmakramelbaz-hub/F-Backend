<?php
// CI-only synthetic wire generator. No customer data, database or production .env.
use App\Services\Dashboard\{DesktopDashboardBootstrap as Canonical,DesktopDashboardSchema};
$application=realpath($argv[1]??'');$inputFile=$argv[2]??'';$output=$argv[3]??'';
if(!$application||!is_file($inputFile)||!$output)throw new RuntimeException('Synthetic fixture arguments differ.');
require $application.'/app/Services/Dashboard/DesktopDashboardBootstrap.php';
require $application.'/app/Services/Dashboard/DesktopDashboardSchema.php';
$input=json_decode(file_get_contents($inputFile),true,16,JSON_THROW_ON_ERROR);
$head=['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>'22222222-2222-4222-8222-222222222222',
    'device_id'=>$input['device'],'actor_id'=>1,'branches'=>['f:1'],'source'=>$input['source'],
    'coverage'=>['full_dashboard'=>false,'print_protocol'=>1],'media'=>[],
    'enrollment'=>['enrollment_hash'=>str_repeat('a',64),'enrolled_at'=>'2026-10-22 10:34:59','base_branches'=>['f:1']]];
function fixtureDdl(string $table): string {
    $columns=['`id` bigint NOT NULL','`c00` double NULL','`c01` bigint NULL'];
    for($n=2;$n<32;++$n)$columns[]='`c'.sprintf('%02d',$n).'` varchar(64) NULL';
    $extra=match($table){
        'users'=>['`account_type` varchar(32) NULL','`status` varchar(32) NULL','`owner_resturant_id` bigint NULL'],
        'model_has_roles'=>['`role_id` bigint NULL','`model_id` bigint NULL','`model_type` varchar(100) NULL'],
        'model_has_permissions'=>['`permission_id` bigint NULL','`model_id` bigint NULL','`model_type` varchar(100) NULL'],
        'role_has_permissions'=>['`permission_id` bigint NULL','`role_id` bigint NULL'],
        'roles','permissions'=>['`guard_name` varchar(32) NULL','`name` varchar(100) NULL'],default=>[],
    };
    return 'CREATE TABLE `'.$table.'` ('.implode(',',array_merge($columns,$extra)).',PRIMARY KEY (`id`)) ENGINE=InnoDB';
}
function fixturePut($stream,string $bytes): void {if(fwrite($stream,$bytes)!==strlen($bytes))throw new RuntimeException('Synthetic wire write failed.');}
$names=array_merge(['users'],array_values(array_diff(DesktopDashboardSchema::TABLES,['users'])));
if(count($names)!==119)throw new RuntimeException('Synthetic table inventory changed.');
$stream=fopen($output,'xb');if(!$stream)throw new RuntimeException('Synthetic output unavailable.');
fixturePut($stream,substr(Canonical::json($head),0,-1).',"tables":{');$schema=[];$totalRows=0;
foreach($names as $index=>$table){
    if($index)fixturePut($stream,',');$ddl=fixtureDdl($table);$schema[$table]=hash('sha256',$ddl);
    fixturePut($stream,Canonical::json($table).':{"ddl":'.Canonical::json($ddl).',"rows":[');
    $hash=hash_init('sha256');hash_update($hash,'[');$rows=$index<10?3400:0;
    for($id=1;$id<=$rows;++$id){
        $row=['id'=>$id,'c00'=>1.0,'c01'=>9007199254740993];
        for($n=2;$n<32;++$n)$row['c'.sprintf('%02d',$n)]='synthetic-value';
        if($table==='users')$row+=['account_type'=>'admin','status'=>'accepted','owner_resturant_id'=>null];
        $json=($id>1?',':'').Canonical::json($row);fixturePut($stream,$json);hash_update($hash,$json);++$totalRows;
    }
    hash_update($hash,']');fixturePut($stream,'],"sha256":'.Canonical::json(hash_final($hash)).'}');
}
ksort($schema);fixturePut($stream,'},"schema_hash":'.Canonical::json(hash('sha256',Canonical::json($schema))).'}');fclose($stream);
$bytes=filesize($output);if($totalRows!==34000||$bytes<24*1024*1024||$bytes>28*1024*1024)throw new RuntimeException('Synthetic scale differs.');
echo json_encode(['tables'=>count($names),'rows'=>$totalRows,'wire_bytes'=>$bytes],JSON_THROW_ON_ERROR).PHP_EOL;
