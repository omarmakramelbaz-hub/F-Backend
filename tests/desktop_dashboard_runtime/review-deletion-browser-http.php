<?php
// Standalone targeted adapter: original full106 schema, actual import/router and original rendered Review form.
// Arguments: generated-application private-MariaDB-port artifact-directory.
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};
(function()use($argv){
    $fixture=__DIR__;$artifact=$argv[3];$reviewBrowserScript=$fixture.'/review-deletion-browser.cjs';
    if(!is_dir($artifact)&&!mkdir($artifact,0700,true))throw new RuntimeException('Review artifact directory unavailable.');
    $manifest=json_decode(file_get_contents(dirname(realpath($argv[1])).'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $commit=$manifest['sourceRevision'];if(!preg_match('/^[a-f0-9]{40}$/D',$commit))throw new RuntimeException('Review source revision unavailable.');
    foreach($manifest['sourceHashes'] as $file=>$hash)if(hash_file('sha256',realpath($argv[1]).'/'.$file)!==$hash)throw new RuntimeException('Review runtime source differs: '.$file);
    $receipt=['format'=>1,'kind'=>'original-review-delete-browser','sourceRevision'=>$commit,'sourceFilesVerified'=>count($manifest['sourceHashes']),
        'sourceFingerprint'=>$manifest['sourceFingerprint'],'schemaInventory'=>106,'fixtureSha256'=>hash_file('sha256',__FILE__),
        'browserFixtureSha256'=>hash_file('sha256',$reviewBrowserScript),'fullDashboard'=>false,'providersReplaced'=>false,'completed'=>false];
    $legacy=file_get_contents($fixture.'/legacy.php');$boundary=strpos($legacy,'$actor=User::withoutGlobalScopes()->findOrFail(10);');
    if(!$boundary)throw new RuntimeException('Original106 schema/seed boundary missing.');
    eval(str_replace('__DIR__',var_export($fixture,true),substr($legacy,5,$boundary-5)));
    $httpStopped=false;$browserStopped=false;
    register_shutdown_function(function()use(&$receipt,&$fixtureCompleted,&$httpStopped,&$browserStopped,$artifact,$pdo,$database,$stage){
        $receipt['completed']=$fixtureCompleted;$receipt['checks']=$GLOBALS['count']??0;$receipt['httpStopped']=$httpStopped;$receipt['browserStopped']=$browserStopped;
        $receipt['privateDatabasesDropped']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('.$pdo->quote($database).','.$pdo->quote($stage).')')->fetchColumn()===0;
        file_put_contents($artifact.'/receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
    });
    $receipt['phpVersion']=PHP_VERSION;$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    $stamp='2020-01-01 00:00:00';
    DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100,'app_scope'=>'go_admin']);
    DB::table('roles')->insert(['id'=>11,'name'=>'Original Review Reader','guard_name'=>'admin','created_at'=>$stamp,'updated_at'=>$stamp]);
    $actor=User::withoutGlobalScopes()->findOrFail(1);$actor->assignRole(\Spatie\Permission\Models\Role::findById(11,'admin'));
    $read=\Spatie\Permission\Models\Permission::create(['name'=>'resturant-list','guard_name'=>'admin']);$actor->givePermissionTo($read);
    verify($actor->getAllPermissions()->pluck('name')->all()===['resturant-list'],'real Review browser actor has only the original restaurant read grant and no delete/menu/order grant');
    DB::table('resturants')->where('id',100)->update(['created_at'=>$stamp]);
    DB::table('resturant_products')->where('resturant_id',100)->update(['created_at'=>$stamp,'price'=>json_encode(array_fill_keys(['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],0))]);
    DB::table('reviews')->insert(['id'=>94001,'resturant_id'=>100,'order_id'=>1,'user_id'=>20,'rate'=>5,'created_at'=>$stamp,'updated_at'=>$stamp]);
    $device=(string)\Illuminate\Support\Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'original Review browser','nonce'=>bin2hex(random_bytes(32))],$actor);
    $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
    config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();$import=app(DesktopDashboardImport::class)->import($snapshot);
    verify(app(DesktopDashboardImport::class)->verify($import)['verified']&&count($snapshot['tables'])===106,'actual Review browser uses the verified original106 schema import');
    $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=count($snapshot['tables']);
    $preserved=['orders'=>(array)DB::table('orders')->where('id',1)->first(),'users'=>(array)DB::table('users')->where('id',20)->first(),'resturants'=>(array)DB::table('resturants')->where('id',100)->first()];
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();
    foreach(['DB_DATABASE'=>$stage,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ORIGIN'=>$origin,'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32))] as $key=>$value)$env[$key]=$value;
    $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/review-browser-http.log','a'],['file',$profile.'/review-browser-http.log','a']],$pipes,$application,$env);
    try{
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browser=proc_open(['node',$reviewBrowserScript],[['pipe','r'],['file',$artifact.'/browser.log','w'],['file',$artifact.'/browser-errors.log','w']],$browserPipes,$application,$env);
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$token,'sourceCommit'=>$commit]));fclose($browserPipes[0]);
        $deadline=microtime(true)+120;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=true;if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/browser-errors.log'));
        if($exit!==0){fwrite(STDERR,substr(file_get_contents($profile.'/review-browser-http.log'),-12000));if(is_file($profile.'/logs/laravel.log'))fwrite(STDERR,substr(file_get_contents($profile.'/logs/laravel.log'),-12000));throw new RuntimeException('Actual original Review browser failed.');}
        preg_match('/REVIEW_DELETE_BROWSER_PROOF (\{[^\r\n]+\})/',$output,$match);$proof=json_decode($match[1]??'null',true);$row=DB::table('desktop_dashboard_commands')->first();
        $receipt['browserProof']=$proof;$receipt['browserExitCode']=$exit;
        verify($row&&DB::table('desktop_dashboard_commands')->count()===1&&$row->command_id===($proof['command']??null)&&$row->route_name==='resturant_reviews.destroy'&&!DB::table('reviews')->where('id',94001)->exists(),'actual original Review button commits exactly its observed UUID and deletes only its Review');
        $payload=json_decode(Crypt::decryptString($row->command_cipher),true);verify($payload['parameters']['review']===94001&&count($payload['facts']['review_before']['row'])===7,'actual browser command binds the exact original Review ID and full before-state');
        foreach($preserved as $table=>$before)verify((array)DB::table($table)->where('id',$before['id'])->first()===$before,'actual original Review browser preserves '.$table.' and its rating/balance/order data');
        $fixtureCompleted=true;
        echo 'ORIGINAL_REVIEW_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$commit,'schemaInventory'=>106,'checks'=>$GLOBALS['count']??0,'fullDashboard'=>false]).PHP_EOL;
    }finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);$httpStopped=true;copy($profile.'/review-browser-http.log',$artifact.'/http.log');}
})();
