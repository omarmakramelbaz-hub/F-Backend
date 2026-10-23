<?php
namespace App\Services\Dashboard {
    // Observe actual production source/media reads without replacing their I/O.
    function file_get_contents(...$arguments){
        if($GLOBALS['handoff_fence']??false)throw new \RuntimeException('Full source/file read inside archive fence.');
        return \file_get_contents(...$arguments);
    }
    function hash_file(...$arguments){
        if($GLOBALS['handoff_fence']??false)throw new \RuntimeException('Full attachment hash inside archive fence.');
        return \hash_file(...$arguments);
    }
}
namespace {
// Actual Laravel, migrations, source binding, InnoDB and print-ledger integration.
// Every database, row, attachment, capability and encryption key is disposable.
use Illuminate\Support\Facades\{DB,Schema};
use App\Services\Dashboard\{DesktopDashboardArchive as Archive,DesktopDashboardBootstrap as Json,
    DesktopDashboardDeltaCodec as Codec,DesktopDashboardDeltaLocal as Local,DesktopDashboardHandoff as Handoff,
    DesktopDashboardImport as Importer,DesktopDashboardRefresh as Refresh,DesktopDashboardSource as Source};
$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||!is_file($application.'/desktop/bootstrap.php')||$port<1024||$port>65535)throw new RuntimeException('Usage: delta-handoff.php application private-MariaDB-port');
$profile=sys_get_temp_dir().'/desktop-delta-handoff-'.bin2hex(random_bytes(8));
foreach(['private','logs','framework/cache/data','framework/sessions','framework/views','bootstrap/cache','source/app/public/products','final/app/public/products'] as $directory)mkdir($profile.'/'.$directory,0700,true);
$names=[];foreach(['source','capture','final','ddl'] as $name)$names[$name]='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach($names as $database)$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$complete=false;$checks=0;$GLOBALS['handoff_fence']=false;
register_shutdown_function(function()use($pdo,$names,$profile,&$complete){
    foreach($names as $database)$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($profile);
    if(!$complete){fwrite(STDERR,'DESKTOP_DELTA_HANDOFF_DID_NOT_COMPLETE'.PHP_EOL);exit(1);}
});
$device='01234567-1234-4234-8234-123456789abc';$first='11234567-1234-4234-8234-123456789abc';
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_DEVICE_ID'=>$device,
    'DESKTOP_DASHBOARD_STORAGE'=>$profile.'/source','APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$names['source'],'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'log',
    'APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
try{
    $app=require $application.'/desktop/bootstrap.php';
    $check=function(bool $value,string $message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
    $deny=function(callable $work,int $status,string $message)use($check){
        try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$check($error->getStatusCode()===$status,$message);return;}
        throw new RuntimeException('Expected denial: '.$message);
    };
    $check(in_array($app->version(),['8.83.29','12.70.0'],true),'the captured application boots a supported actual Laravel engine');
    $check(realpath((new ReflectionClass(Handoff::class))->getFileName())===realpath($application.'/app/Services/Dashboard/DesktopDashboardHandoff.php'),'the real captured handoff service is loaded by the actual framework');
    $switch=function(string $database,string $storage)use($app){
        DB::purge('mysql');config(['database.connections.mysql.database'=>$database,
            'filesystems.disks.local.root'=>$storage.'/app','filesystems.disks.public.root'=>$storage.'/app/public']);
        $app->useStoragePath($storage);DB::reconnect('mysql');
    };
    $definitions=[
        'users'=>'`id` bigint unsigned NOT NULL PRIMARY KEY,`name` varchar(255) NOT NULL,`account_type` varchar(30) NOT NULL,`status` varchar(30) NOT NULL,`owner_resturant_id` bigint unsigned NULL,`password` varchar(255) NULL',
        'settings'=>'`id` bigint unsigned NOT NULL PRIMARY KEY,`name` varchar(255) NOT NULL,`payload` longtext NOT NULL',
    ];
    $rows=['users'=>[['id'=>1,'name'=>'Synthetic owner','account_type'=>'admin','status'=>'accepted','owner_resturant_id'=>null,'password'=>'!synthetic']],
        'settings'=>[['id'=>1,'name'=>'synthetic','payload'=>'original']]];
    $tables=[];$schema=[];
    foreach($definitions as $table=>$columns){
        $pdo->exec('CREATE TABLE `'.$names['ddl'].'`.`'.$table.'` ('.$columns.') ENGINE=InnoDB');
        $ddl=$pdo->query('SHOW CREATE TABLE `'.$names['ddl'].'`.`'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $tables[$table]=['ddl'=>$ddl,'rows'=>$rows[$table],'sha256'=>hash('sha256',Json::json($rows[$table]))];$schema[$table]=hash('sha256',$ddl);
    }
    $source=app(Source::class)->fingerprint();ksort($schema);
    $image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6wGQAAAAASUVORK5CYII=');
    file_put_contents($profile.'/source/app/public/products/check.png',$image);file_put_contents($profile.'/final/app/public/products/check.png',$image);
    $media=[['path'=>'products/check.png','sha256'=>hash('sha256',$image),'bytes'=>strlen($image),'mime'=>'image/png']];
    $coverage=['full_dashboard'=>true,'print_protocol'=>1,'catalog_scopes'=>0,'media'=>true];
    $enrollment=['enrollment_hash'=>str_repeat('a',64),'enrolled_at'=>'2026-10-23 12:00:00','base_branches'=>['f:1']];
    $snapshot=['format'=>1,'kind'=>'initial-dashboard-data','snapshot_id'=>$first,'device_id'=>$device,'actor_id'=>1,'branches'=>['f:1'],
        'schema_hash'=>hash('sha256',Json::json($schema)),'coverage'=>$coverage,'source'=>$source,'tables'=>$tables,'media'=>$media,'enrollment'=>$enrollment,'created_scopes'=>[]];
    $switch($names['source'],$profile.'/source');$original=app(Importer::class)->import($snapshot);
    $check(Schema::hasTable('desktop_dashboard_local_state')&&DB::table('desktop_dashboard_devices')->where('id',$device)->exists(),'real Import provisions the original private state and print enrollment');
    $command='71234567-1234-4234-8234-123456789abc';$historic='51234567-1234-4234-8234-123456789abc';
    DB::table('desktop_dashboard_commands')->insert(['sequence'=>7,'device_id'=>$device,'command_id'=>$command,'actor_id'=>1,'route_name'=>'orders.create',
        'request_hash'=>str_repeat('b',64),'command_cipher'=>'synthetic','local_result_cipher'=>'synthetic','dependencies'=>'[]','status'=>'acknowledged','created_at'=>now(),'updated_at'=>now()]);
    DB::table('desktop_dashboard_archived_commands')->insert(['device_id'=>$device,'command_id'=>$historic,'actor_id'=>1,'route_name'=>'orders.create',
        'request_hash'=>str_repeat('c',64),'source_database'=>'fasakhansta_dashboard_stage_dddddddddddddddd']);
    $token=bin2hex(random_bytes(32));$captureInput=['action'=>'capture','source_database'=>$names['source'],'device_id'=>$device,'actor_id'=>1,
        'snapshot_id'=>$first,'schema_hash'=>$original['schema_hash'],'source'=>$source,'capture_token'=>$token];
    $switch($names['capture'],$profile.'/source');$capture=app(Local::class)->run($captureInput);
    $check($capture['sequence']===7&&$capture['replay']['rows']===2,'real repeatable-read capture includes both acknowledged and historic replay protection');
    $next='21234567-1234-4234-8234-123456789abc';$deltaTables=[];$nextSchema=[];
    foreach($capture['index']['tables'] as $table=>$index){
        $ddl=$pdo->query('SHOW CREATE TABLE `'.$names['capture'].'`.`'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $values=$pdo->query('SELECT * FROM `'.$names['capture'].'`.`'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        if($table==='settings')$values[]=['id'=>2,'name'=>'synthetic new','payload'=>'server change'];
        $deltaTables[$table]=['ddl'=>$ddl,'delta'=>Codec::delta($table,$values,1,$ddl,$index)];$nextSchema[$table]=hash('sha256',$ddl);
    }
    ksort($nextSchema);$delta=['format'=>1,'kind'=>'dashboard-data-delta','snapshot_id'=>$next,'device_id'=>$device,'actor_id'=>1,
        'branches'=>['f:1'],'coverage'=>$coverage,'source'=>$source,'schema_hash'=>hash('sha256',Json::json($nextSchema)),
        'tables'=>$deltaTables,'media'=>$media,'enrollment'=>$enrollment,'created_scopes'=>[]];
    $switch($names['final'],$profile.'/final');$receipt=app(Local::class)->run(['action'=>'reconstruct','capture_database'=>$names['capture'],
        'capture_token'=>$token,'capture'=>$capture,'delta'=>$delta]);
    $check($receipt['delta_replay']===$capture['replay']&&DB::table('desktop_dashboard_archived_commands')->count()===2,'real reconstruction and Import prestage an authenticated complete replay projection');
    $check(DB::table('settings')->count()===2&&DB::table('desktop_dashboard_commands')->count()===0,'server changes reach only the inactive candidate with an empty accepted-command ledger');
    $checked=json_decode(json_encode(app(Handoff::class)->capture($receipt,$token),JSON_THROW_ON_ERROR),true,128,JSON_THROW_ON_ERROR);
    $seal=$checked['media_seal'];$proof=$checked['archive_handoff'];unset($checked['media_seal'],$checked['archive_handoff']);
    $check($checked['verified']===true&&$proof['payload']['source_identity']===['snapshot_id'=>$first,'schema_hash'=>$original['schema_hash']],'real preverify authenticates source, enrollment, table counts, replay digest and media bytes before fencing');
    $fenceQueries=[];DB::listen(function($event)use(&$fenceQueries){
        if(!($GLOBALS['handoff_fence']??false))return;$fenceQueries[]=$event->sql;
        if(preg_match('/\bCOUNT\s*\(|\bINSERT\b.*\bSELECT\b|desktop_dashboard_archived_commands/i',$event->sql))throw new RuntimeException('Historical scan/copy inside archive fence.');
    });
    $refreshId='31234567-1234-4234-8234-123456789abc';$refreshToken=bin2hex(random_bytes(32));
    $switch($names['source'],$profile.'/source');$fence=app(Refresh::class)->begin($device,$refreshId,$refreshToken);
    $check($fence['held']===true&&$fence['fenced_sequence']===7,'real refresh begin owns the active write fence at its captured journal sequence');
    $switch($names['final'],$profile.'/final');$GLOBALS['handoff_fence']=true;
    try{$result=app(Archive::class)->seal($checked,$names['source'],$refreshId,$refreshToken,$proof['payload']['source_identity'],$seal,$proof,$token);}
    finally{$GLOBALS['handoff_fence']=false;}
    $check($result===['archived_commands'=>2]&&count($fenceQueries)>0,'real fast Archive succeeds using fixed rows and stat-only handoff with no history scans or full byte reads');
    $check(DB::table('desktop_dashboard_archived_commands')->count()===2,'real fast Archive preserves the prestaged UUID ledger without duplicate insertion');
    $forged=$proof;$forged['signature']=str_repeat('0',64);$deny(fn()=>app(Archive::class)->seal($checked,$names['source'],$refreshId,$refreshToken,$proof['payload']['source_identity'],$seal,$forged,$token),409,'forged private proof is rejected by actual Laravel before activation');
    $switch($names['source'],$profile.'/source');app(Refresh::class)->cancel($device,$refreshId,$refreshToken);
    DB::transaction(function()use($device){
        DB::table('settings')->where('id',1)->update(['payload'=>'concurrent local operation']);
        DB::table('desktop_dashboard_commands')->insert(['sequence'=>8,'device_id'=>$device,'command_id'=>'81234567-1234-4234-8234-123456789abc','actor_id'=>1,'route_name'=>'orders.create',
            'request_hash'=>str_repeat('d',64),'command_cipher'=>'synthetic','local_result_cipher'=>'synthetic','dependencies'=>'[]','status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
    });
    $laterId='41234567-1234-4234-8234-123456789abc';$laterToken=bin2hex(random_bytes(32));
    $deny(fn()=>app(Refresh::class)->begin($device,$laterId,$laterToken),409,'a local operation recorded during preparation prevents the real refresh fence while pending');
    DB::table('desktop_dashboard_commands')->where('sequence',8)->update(['status'=>'acknowledged']);
    $laterFence=app(Refresh::class)->begin($device,$laterId,$laterToken);
    $check($laterFence['fenced_sequence']===8,'acknowledging the concurrent operation retains its newer journal sequence');
    $switch($names['final'],$profile.'/final');$deny(fn()=>app(Archive::class)->seal($checked,$names['source'],$laterId,$laterToken,$proof['payload']['source_identity'],$seal,$proof,$token),409,'real fast Archive rejects the old capture after a newer acknowledged local operation');
    $switch($names['source'],$profile.'/source');app(Refresh::class)->cancel($device,$laterId,$laterToken);
    $check(DB::table('settings')->where('id',1)->value('payload')==='concurrent local operation'&&DB::table('desktop_dashboard_commands')->count()===2,'rejected cutover preserves all original concurrent business and journal rows');
    $check(DB::table('desktop_dashboard_local_state')->where('device_id',$device)->value('state')==='ready','explicit fence cancellation restores the original writable generation');
    $complete=true;echo 'DESKTOP_DELTA_HANDOFF_COMPLETE '.json_encode(['checks'=>$checks,'runtimeFramework'=>$app->version(),'actualFramework'=>true,
        'actualMariaDB'=>true,'actualPrintProtocol'=>1,'fullVerificationBeforeFence'=>true,'noHistoryScanUnderFence'=>true,'productionEnvironmentLoaded'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);}
}
