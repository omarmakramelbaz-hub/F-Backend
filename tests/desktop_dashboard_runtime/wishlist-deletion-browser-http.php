<?php
// Targeted original106 schema/import/UI. Arguments: immutable application, private Maria port, artifact, frozen fixture SHA.
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};
(function()use($argv){
    $fixture=__DIR__;$artifact=$argv[3];$script=$fixture.'/wishlist-deletion-browser.cjs';$application=realpath($argv[1]);
    if(!preg_match('/^[a-f0-9]{40}$/D',$argv[4]??''))throw new RuntimeException('A frozen fixture source SHA is required.');
    if(!is_dir($artifact)&&!mkdir($artifact,0700,true))throw new RuntimeException('Wishlist artifact directory unavailable.');
    $fixtureBindings=[];$repository=dirname($fixture,2);
    foreach(['wishlist-deletion-browser-http.php','wishlist-deletion-browser.cjs','legacy.php','full-schema.php','fixtures/deployed-schema-20261008.json'] as $file){
        $git=proc_open(['git','-C',$repository,'cat-file','blob',$argv[4].':tests/desktop_dashboard_runtime/'.$file],[['pipe','r'],['pipe','w'],['pipe','w']],$gitPipes);
        fclose($gitPipes[0]);$bytes=stream_get_contents($gitPipes[1]);fclose($gitPipes[1]);$error=stream_get_contents($gitPipes[2]);fclose($gitPipes[2]);
        if(proc_close($git)!==0||hash('sha256',$bytes)!==hash_file('sha256',$fixture.'/'.$file))throw new RuntimeException('Frozen Wishlist fixture source differs: '.$file);
        $fixtureBindings[$file]=hash('sha256',$bytes);
    }
    $manifest=json_decode(file_get_contents(dirname($application).'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$commit=$manifest['sourceRevision'];
    if(!preg_match('/^[a-f0-9]{40}$/D',$commit))throw new RuntimeException('Wishlist application source revision unavailable.');
    $verifySource=function()use($manifest,$application){foreach($manifest['sourceHashes'] as $file=>$hash)if(hash_file('sha256',$application.'/'.$file)!==$hash)throw new RuntimeException('Immutable application source differs: '.$file);};$verifySource();
    $vendorFingerprint=function()use($application){
        if(is_link($application.'/vendor'))throw new RuntimeException('A physical immutable vendor tree is required.');
        $paths=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($application.'/vendor',FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()||$file->isLink())$paths[]=substr($file->getPathname(),strlen($application.'/vendor/'));sort($paths);
        $digest=hash_init('sha256');foreach($paths as $file){$path=$application.'/vendor/'.$file;hash_update($digest,$file."\0".(is_link($path)?hash('sha256',readlink($path),true):hash_file('sha256',$path,true)));}return ['files'=>count($paths),'sha256'=>hash_final($digest)];
    };$vendorBefore=$vendorFingerprint();
    $receipt=['format'=>1,'kind'=>'original-wishlist-query-delete-browser','sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],
        'sourceFilesVerified'=>count($manifest['sourceHashes']),'sourceFingerprint'=>$manifest['sourceFingerprint'],'schemaInventory'=>106,'fixtureSourceBindings'=>$fixtureBindings,
        'fixtureSha256'=>hash_file('sha256',__FILE__),'browserFixtureSha256'=>hash_file('sha256',$script),
        'legacySeedSha256'=>hash_file('sha256',$fixture.'/legacy.php'),'fullSchemaFixtureSha256'=>hash_file('sha256',$fixture.'/full-schema.php'),
        'vendorBefore'=>$vendorBefore,'composerLockSha256'=>hash_file('sha256',$application.'/composer.lock'),
        'fullDashboard'=>false,'applicationValidated'=>false,'providersReplaced'=>false,'completed'=>false];
    $legacy=file_get_contents($fixture.'/legacy.php');$boundary=strpos($legacy,'$actor=User::withoutGlobalScopes()->findOrFail(10);');
    if(!$boundary)throw new RuntimeException('Original106 schema/seed boundary missing.');
    $prefix=substr($legacy,5,$boundary-5);
    // Only replace the shared test completion exit; it would prevent a failure receipt after database cleanup.
    $guard='$fixtureCompleted=false;register_shutdown_function(function()use(&$fixtureCompleted){if(!$fixtureCompleted){fwrite(STDERR,\'Original legacy fixture did not complete.\'.PHP_EOL);exit(1);}});';
    if(substr_count($prefix,$guard)!==1)throw new RuntimeException('Original shared test completion guard differs.');
    $prefix=str_replace($guard,'$fixtureCompleted=false;',$prefix);eval(str_replace('__DIR__',var_export($fixture,true),$prefix));
    $httpStopped=true;$browserStopped=true;
    register_shutdown_function(function()use(&$receipt,&$fixtureCompleted,&$httpStopped,&$browserStopped,$artifact,$pdo,$database,$stage,$verifySource,$vendorFingerprint,$vendorBefore){
        try{$verifySource();$receipt['sourceVerifiedAfter']=true;$receipt['vendorAfter']=$vendorFingerprint();$receipt['vendorUnchanged']=$receipt['vendorAfter']===$vendorBefore;}
        catch(Throwable $error){$receipt['sourceVerifiedAfter']=false;$receipt['bindingError']=$error->getMessage();}
        $receipt['checks']=$GLOBALS['count']??0;$receipt['httpStopped']=$httpStopped;$receipt['browserStopped']=$browserStopped;
        $receipt['privateDatabasesDropped']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('.$pdo->quote($database).','.$pdo->quote($stage).')')->fetchColumn()===0;
        $receipt['completed']=$fixtureCompleted&&$httpStopped&&$browserStopped&&($receipt['sourceVerifiedAfter']??false)&&($receipt['vendorUnchanged']??false)&&$receipt['privateDatabasesDropped'];
        file_put_contents($artifact.'/receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
        if(!$receipt['completed']){fwrite(STDERR,'Original wishlist browser fixture or cleanup did not complete.'.PHP_EOL);exit(1);}
    });
    $receipt['phpVersion']=PHP_VERSION;$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    $classes=['App\\Models\\Wishlist'=>'app/Models/Wishlist.php','App\\Services\\Dashboard\\DesktopDashboardWishlistDeletion'=>'app/Services/Dashboard/DesktopDashboardWishlistDeletion.php','App\\Http\\Controllers\\Dashboard\\UserController'=>'app/Http/Controllers/Dashboard/UserController.php','Illuminate\\Foundation\\Application'=>'vendor/laravel/framework/src/Illuminate/Foundation/Application.php'];
    foreach($classes as $class=>$file)if(realpath((new ReflectionClass($class))->getFileName())!==realpath($application.'/'.$file))throw new RuntimeException('Original browser class source differs: '.$class);
    $receipt['reflectionBindings']=count($classes);$stamp='2020-01-01 00:00:00';
    DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100,'app_scope'=>'go_admin']);
    DB::table('roles')->insert(['id'=>11,'name'=>'Original Wishlist Reader','guard_name'=>'admin','created_at'=>$stamp,'updated_at'=>$stamp]);
    $actor=User::withoutGlobalScopes()->findOrFail(1);$actor->assignRole(\Spatie\Permission\Models\Role::findById(11,'admin'));
    $read=\Spatie\Permission\Models\Permission::create(['name'=>'user-list','guard_name'=>'admin']);$actor->givePermissionTo($read);
    verify($actor->getAllPermissions()->pluck('name')->all()===['user-list'],'real original user-page actor has only its user read grant and no wishlist/resource/POS delete grant');
    DB::table('wallets')->update(['created_at'=>$stamp]);
    DB::table('wishlists')->insert([['id'=>94001,'resturant_id'=>100,'user_id'=>20,'created_at'=>$stamp,'updated_at'=>$stamp],['id'=>94002,'resturant_id'=>100,'user_id'=>20,'created_at'=>$stamp,'updated_at'=>$stamp]]);
    $device=(string)\Illuminate\Support\Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'original Wishlist browser','nonce'=>bin2hex(random_bytes(32))],$actor);
    $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
    config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();$import=app(DesktopDashboardImport::class)->import($snapshot);
    verify(app(DesktopDashboardImport::class)->verify($import)['verified']&&count($snapshot['tables'])===106,'actual Wishlist browser uses the verified original106 schema import');
    $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=count($snapshot['tables']);
    // Existing Wishlist media are not covered by snapshot media mapping. This private attachment proves retention only.
    DB::table('media')->insert(['id'=>94001,'model_type'=>\App\Models\Wishlist::class,'model_id'=>94001,'collection_name'=>'wishlist','name'=>'browser-retained','file_name'=>'retained.txt','mime_type'=>'text/plain','disk'=>'public','conversions_disk'=>'public','size'=>strlen('browser-query-retention'),'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}']);
    $media=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail(94001);$file=$media->getPath();mkdir(dirname($file),0700,true);file_put_contents($file,'browser-query-retention');$mediaHash=hash_file('sha256',$file);
    $preserved=['orders'=>(array)DB::table('orders')->where('id',1)->first(),'users'=>(array)DB::table('users')->where('id',20)->first(),'resturants'=>(array)DB::table('resturants')->where('id',100)->first(),'wishlists'=>(array)DB::table('wishlists')->where('id',94002)->first(),'media'=>(array)DB::table('media')->where('id',94001)->first()];
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();
    foreach(['DB_DATABASE'=>$stage,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ORIGIN'=>$origin,'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32))] as $key=>$value)$env[$key]=$value;
    $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$artifact.'/http.log','w'],['file',$artifact.'/http.log','a']],$pipes,$application,$env);$httpStopped=false;
    try{
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browser=proc_open(['node',$script],[['pipe','r'],['file',$artifact.'/browser.log','w'],['file',$artifact.'/browser-errors.log','w']],$browserPipes,$application,$env);$browserStopped=false;
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$token,'sourceCommit'=>$commit,'fixtureRevision'=>$argv[4]]));fclose($browserPipes[0]);
        $deadline=microtime(true)+120;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=true;if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/browser-errors.log'));$receipt['browserExitCode']=$exit;
        if($exit!==0){if(is_file($profile.'/logs/laravel.log'))copy($profile.'/logs/laravel.log',$artifact.'/laravel.log');throw new RuntimeException('Actual original Wishlist browser failed; inspect untouched original HTTP/browser logs.');}
        preg_match('/WISHLIST_DELETE_BROWSER_PROOF (\{[^\r\n]+\})/',$output,$match);$proof=json_decode($match[1]??'null',true);$row=DB::table('desktop_dashboard_commands')->first();$receipt['browserProof']=$proof;
        verify($row&&DB::table('desktop_dashboard_commands')->count()===1&&$row->command_id===($proof['command']??null)&&$row->route_name==='userwishlists.destroy'&&!DB::table('wishlists')->where('id',94001)->exists(),'actual original Wishlist button commits exactly its observed UUID and deletes only that Wishlist');
        $payload=json_decode(Crypt::decryptString($row->command_cipher),true);verify($payload['parameters']['id']===94001&&count($payload['facts']['wishlist_before']['row'])===5,'actual browser command binds the exact original Wishlist ID and all five before-state columns');
        foreach($preserved as $table=>$before)verify((array)DB::table($table)->where('id',$before['id'])->first()===$before,'actual original Wishlist browser preserves '.$table.' and its original data');
        verify(is_file($file)&&hash_file('sha256',$file)===$mediaHash,'actual original Wishlist query-delete and same-UUID retry preserve its attached file byte-for-byte');$receipt['mediaFileSha256']=$mediaHash;$receipt['mediaPreserved']=true;
        $fixtureCompleted=true;
        echo 'ORIGINAL_WISHLIST_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],'schemaInventory'=>106,'checks'=>$GLOBALS['count']??0,'fullDashboard'=>false]).PHP_EOL;
    }finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);$httpStopped=true;}
})();
