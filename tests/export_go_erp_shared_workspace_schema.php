<?php
/** Disposable CI only: export actual normalized schemas from the exact source. */
function schema_export_need($value): void { if (!$value) throw new RuntimeException('SCHEMA_EXPORT_GUARD'); }
schema_export_need(PHP_SAPI==='cli'&&getenv('GITHUB_ACTIONS')==='true'&&getenv('APP_ENV')==='testing');
schema_export_need($argc===6&&preg_match('/^[a-f0-9]{40}$/D',$argv[3])&&preg_match('/^[a-f0-9]{64}$/D',$argv[4]));
$root=realpath($argv[1]);$helper=realpath($argv[2]);$output=$argv[5];
schema_export_need(is_string($root)&&is_string($helper)&&hash_file('sha256',$helper)===$argv[4]
    &&getenv('DB_HOST')==='127.0.0.1'&&in_array(getenv('DB_PORT'),['3306','3307'],true)
    &&getenv('DB_USERNAME')==='root'&&getenv('DB_PASSWORD')==='test-only');
$raw=file_get_contents($helper);$marker="try {\n    ini_set('display_errors','0');";
schema_export_need(substr_count($raw,$marker)===1&&str_starts_with($raw,'<?php'));
// Load only the hash-verified pure functions, never its production CLI body.
eval(substr($raw,5,strpos($raw,$marker)-5));
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$server=new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$hashes=[];foreach(GO_ERP_WORKSPACE_MIGRATIONS as $path=>$spec){
    schema_export_need(is_file($root.'/'.$path)&&!is_link($root.'/'.$path));
    $hashes[$path]=hash_file('sha256',$root.'/'.$path);
}
$exports=[];
foreach([0,1] as $profile){
    $database='workspace_schema_ci_'.bin2hex(random_bytes(8));$owned=false;
    try{
        schema_export_need(!$server->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$database'")->fetchColumn());
        $server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$owned=true;
        $connection=config('database.connections.mysql');$connection['database']=$database;
        $connection['host']='127.0.0.1';$connection['port']=getenv('DB_PORT');$connection['username']='root';$connection['password']='test-only';
        $connection['charset']='utf8mb4';$connection['collation']='utf8mb4_unicode_ci';
        config(['database.default'=>'mysql','database.connections.mysql'=>$connection]);
        Illuminate\Support\Facades\DB::purge('mysql');$db=Illuminate\Support\Facades\DB::connection('mysql');
        $db->statement('SET SESSION explicit_defaults_for_timestamp = '.$profile);
        schema_export_need((int)$db->selectOne('SELECT @@explicit_defaults_for_timestamp AS actual')->actual===$profile);
        foreach(GO_ERP_WORKSPACE_MIGRATIONS as $path=>$spec){require_once $root.'/'.$path;$class=$spec['class'];(new $class())->up();}
        $tables=[];foreach(GO_ERP_WORKSPACE_MIGRATIONS as $spec)foreach($spec['tables'] as $table)$tables[$table]=workspace_table_schema($table);
        foreach(['desktop_dashboard_workspaces'=>'enrolled_at','desktop_dashboard_ws_command_auth'=>'created_at'] as $table=>$column){
            $matched=array_values(array_filter($tables[$table]['columns'],fn($row)=>$row['name']===$column));
            schema_export_need(count($matched)===1&&$matched[0]['nullable']===false
                &&$matched[0]['default']==='current_timestamp'&&$matched[0]['extra']==='');
        }
        $exports[(string)$profile]=$tables;
    }finally{
        Illuminate\Support\Facades\DB::disconnect('mysql');
        // Only a freshly random database created above by this process is owned.
        if($owned)$server->exec("DROP DATABASE `$database`");
    }
}
schema_export_need(workspace_canonical($exports['0'])===workspace_canonical($exports['1']));
$value=['format'=>1,'kind'=>'go-erp-shared-workspace-schema-export','source_revision'=>$argv[3],
    'operator_revision'=>getenv('GITHUB_SHA'),'helper_sha256'=>$argv[4],
    'migration_hashes'=>$hashes,'server_version'=>$server->getAttribute(PDO::ATTR_SERVER_VERSION),
    'timestamp_profiles'=>[0,1],'semantic_schemas_sha256'=>workspace_hash($exports['1']),'schemas'=>$exports['1']];
schema_export_need(file_put_contents($output,workspace_canonical($value),LOCK_EX)!==false);
echo 'GO_ERP_SHARED_WORKSPACE_SCHEMA_EXPORT_COMPLETE '.workspace_hash($exports['1'])."\n";
