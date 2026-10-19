<?php
// Arguments: immutable original application, private Maria port, artifact directory, frozen fixture SHA.
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\{Resturant,User};
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};
(function()use($argv){
    $fixture=__DIR__;$artifact=$argv[3];$script=$fixture.'/restaurant-product-crud-browser.cjs';$application=realpath($argv[1]);
    if(!preg_match('/^[a-f0-9]{40}$/D',$argv[4]??''))throw new RuntimeException('A frozen restaurant product CRUD fixture SHA is required.');
    if(!is_dir($artifact)&&!mkdir($artifact,0700,true))throw new RuntimeException('Restaurant product CRUD artifact directory unavailable.');
    $fixtureFiles=['restaurant-product-crud-browser-http.php','restaurant-product-crud-browser.cjs','browser-sound.cjs','legacy.php','full-schema.php','fixtures/deployed-schema-20261008.json'];
    $repository=dirname($fixture,2);$fixtureBindings=[];
    $verifyFixtures=function()use($fixtureFiles,$repository,$fixture,$argv,&$fixtureBindings){
        foreach($fixtureFiles as $file){
            $git=proc_open(['git','-C',$repository,'cat-file','blob',$argv[4].':tests/desktop_dashboard_runtime/'.$file],[['pipe','r'],['pipe','w'],['pipe','w']],$gitPipes);
            fclose($gitPipes[0]);$bytes=stream_get_contents($gitPipes[1]);fclose($gitPipes[1]);$error=stream_get_contents($gitPipes[2]);fclose($gitPipes[2]);
            if(proc_close($git)!==0||hash('sha256',$bytes)!==hash_file('sha256',$fixture.'/'.$file))throw new RuntimeException('Frozen restaurant product CRUD fixture differs: '.$file);
            $fixtureBindings[$file]=hash('sha256',$bytes);
        }
    };$verifyFixtures();
    $manifest=json_decode(file_get_contents(dirname($application).'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$commit=$manifest['sourceRevision'];
    if(!preg_match('/^[a-f0-9]{40}$/D',$commit))throw new RuntimeException('Restaurant product CRUD application revision unavailable.');
    $verifySource=function()use($manifest,$application){foreach($manifest['sourceHashes'] as $file=>$hash)if(hash_file('sha256',$application.'/'.$file)!==$hash)throw new RuntimeException('Immutable restaurant product CRUD application differs: '.$file);};$verifySource();
    $vendorFingerprint=function()use($application){
        if(is_link($application.'/vendor'))throw new RuntimeException('A physical immutable vendor tree is required.');
        $paths=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($application.'/vendor',FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()||$file->isLink())$paths[]=substr($file->getPathname(),strlen($application.'/vendor/'));sort($paths);
        $digest=hash_init('sha256');foreach($paths as $file){$path=$application.'/vendor/'.$file;hash_update($digest,$file."\0".(is_link($path)?hash('sha256',readlink($path),true):hash_file('sha256',$path,true)));}return ['files'=>count($paths),'sha256'=>hash_final($digest)];
    };$vendorBefore=$vendorFingerprint();
    $graphFingerprint=function()use($application){
        $lock=json_decode(file_get_contents($application.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);
        $installed=json_decode(file_get_contents($application.'/vendor/composer/installed.json'),true,512,JSON_THROW_ON_ERROR);$packages=$installed['packages']??$installed;
        $expected=[];$actual=[];foreach($lock['packages'] as $package)$expected[$package['name']]=$package;
        foreach($packages as $package)$actual[$package['name']]=$package;ksort($expected);ksort($actual);
        if(array_keys($expected)!==array_keys($actual))throw new RuntimeException('Actual immutable production package inventory differs from lock.');
        $binding=[];foreach($expected as $name=>$package){
            if($actual[$name]['version']!==$package['version'])throw new RuntimeException('Actual installed package version differs: '.$name);
            foreach(['source','dist'] as $kind)if(isset($package[$kind]['reference'])&&($actual[$name][$kind]['reference']??null)!==$package[$kind]['reference'])throw new RuntimeException('Actual installed package reference differs: '.$name);
            $binding[$name]=['version'=>$package['version'],'source'=>$package['source']['reference']??null,'dist'=>$package['dist']['reference']??null];
        }
        return ['packages'=>count($binding),'sha256'=>hash('sha256',json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    };$graphBefore=$graphFingerprint();
    $receipt=['format'=>1,'kind'=>'original-restaurant-product-crud-browser','sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],
        'sourceFilesVerified'=>count($manifest['sourceHashes']),'sourceFingerprint'=>$manifest['sourceFingerprint'],'schemaInventory'=>106,'fixtureSourceBindings'=>$fixtureBindings,
        'fixtureSha256'=>hash_file('sha256',__FILE__),'browserFixtureSha256'=>hash_file('sha256',$script),
        'legacySeedSha256'=>hash_file('sha256',$fixture.'/legacy.php'),'fullSchemaFixtureSha256'=>hash_file('sha256',$fixture.'/full-schema.php'),
        'vendorBefore'=>$vendorBefore,'installedGraphBefore'=>$graphBefore,'composerLockSha256'=>hash_file('sha256',$application.'/composer.lock'),
        'schemaContract'=>'106 inspected column/FK shapes; original five-table permission migration; other defaults/indexes synthetic',
        'fullDashboard'=>false,'applicationValidated'=>false,'providersReplaced'=>false,'completed'=>false];
    $legacy=file_get_contents($fixture.'/legacy.php');$boundary=strpos($legacy,'$actor=User::withoutGlobalScopes()->findOrFail(10);');
    if(!$boundary)throw new RuntimeException('Original106 schema/seed boundary missing.');$prefix=substr($legacy,5,$boundary-5);
    $guard='$fixtureCompleted=false;register_shutdown_function(function()use(&$fixtureCompleted){if(!$fixtureCompleted){fwrite(STDERR,\'Original legacy fixture did not complete.\'.PHP_EOL);exit(1);}});';
    if(substr_count($prefix,$guard)!==1)throw new RuntimeException('Original shared test completion guard differs.');
    $prefix=str_replace($guard,'$fixtureCompleted=false;',$prefix);eval(str_replace('__DIR__',var_export($fixture,true),$prefix));
    $httpStopped=true;$browserStopped=true;
    register_shutdown_function(function()use(&$receipt,&$fixtureCompleted,&$httpStopped,&$browserStopped,$artifact,$pdo,$database,$stage,$verifySource,$verifyFixtures,$vendorFingerprint,$vendorBefore,$graphFingerprint,$graphBefore){
        try{$verifySource();$verifyFixtures();$receipt['sourceVerifiedAfter']=true;$receipt['fixturesVerifiedAfter']=true;
            $receipt['vendorAfter']=$vendorFingerprint();$receipt['vendorUnchanged']=$receipt['vendorAfter']===$vendorBefore;
            $receipt['installedGraphAfter']=$graphFingerprint();$receipt['installedGraphUnchanged']=$receipt['installedGraphAfter']===$graphBefore;}
        catch(Throwable $error){$receipt['sourceVerifiedAfter']=false;$receipt['bindingError']=$error->getMessage();}
        $receipt['checks']=$GLOBALS['count']??0;$receipt['httpStopped']=$httpStopped;$receipt['browserStopped']=$browserStopped;
        $receipt['privateDatabasesDropped']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME IN ('.$pdo->quote($database).','.$pdo->quote($stage).')')->fetchColumn()===0;
        $receipt['completed']=$fixtureCompleted&&$httpStopped&&$browserStopped&&($receipt['sourceVerifiedAfter']??false)&&($receipt['fixturesVerifiedAfter']??false)
            &&($receipt['vendorUnchanged']??false)&&($receipt['installedGraphUnchanged']??false)&&$receipt['privateDatabasesDropped'];
        file_put_contents($artifact.'/receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
        if(!$receipt['completed']){fwrite(STDERR,'Original restaurant product CRUD browser fixture or cleanup did not complete.'.PHP_EOL);exit(1);}
    });
    $receipt['phpVersion']=PHP_VERSION;$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    verify($receipt['laravel']==='8.83.29','actual product CRUD browser uses original Laravel8.83.29');
    $classes=['App\\Models\\ResturantProduct'=>'app/Models/ResturantProduct.php',
        'App\\Services\\Dashboard\\DesktopDashboardRestaurantProductCrud'=>'app/Services/Dashboard/DesktopDashboardRestaurantProductCrud.php',
        'App\\Services\\Dashboard\\DesktopDashboardProductImages'=>'app/Services/Dashboard/DesktopDashboardProductImages.php',
        'App\\Services\\Dashboard\\DesktopDashboardProductEffects'=>'app/Services/Dashboard/DesktopDashboardProductEffects.php',
        'App\\Http\\Controllers\\Dashboard\\ResturantController'=>'app/Http/Controllers/Dashboard/ResturantController.php',
        'App\\Http\\Requests\\Dashboard\\Resturant\\ResturantProductRequest'=>'app/Http/Requests/Dashboard/Resturant/ResturantProductRequest.php',
        'App\\Repositories\\ResturantProductRepository'=>'app/Repositories/ResturantProductRepository.php',
        'Spatie\\MediaLibrary\\MediaCollections\\Models\\Media'=>'vendor/spatie/laravel-medialibrary/src/MediaCollections/Models/Media.php'];
    foreach($classes as $class=>$file)if(realpath((new ReflectionClass($class))->getFileName())!==realpath($application.'/'.$file))throw new RuntimeException('Original product CRUD browser class source differs: '.$class);
    $receipt['reflectionBindings']=count($classes);$stamp='2020-01-01 00:00:00';
    DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
    DB::table('roles')->insert(['id'=>11,'name'=>'Original Restaurant Product Reader','guard_name'=>'admin','created_at'=>$stamp,'updated_at'=>$stamp]);
    $actor=User::withoutGlobalScopes()->findOrFail(1);$actor->assignRole(\Spatie\Permission\Models\Role::findById(11,'admin'));
    $read=\Spatie\Permission\Models\Permission::create(['name'=>'resturant-list','guard_name'=>'admin']);$actor->givePermissionTo($read);
    verify(!$actor->hasRole('Super Admin')&&$actor->roles->pluck('id')->all()===[11]&&$actor->getAllPermissions()->pluck('name')->all()===['resturant-list'],
        'actual role11 product CRUD actor has only the original show permission and no invented product edit grant');
    DB::table('resturants')->where('id',100)->update(['parent_id'=>null,'created_at'=>$stamp,'updated_at'=>$stamp]);
    DB::table('categories')->insert(['id'=>2,'added_by'=>1,'parent_id'=>1,'name_ar'=>'رنجة فرعية','name_en'=>'Herring subcategory','status'=>'show','order'=>2,'created_at'=>$stamp,'updated_at'=>$stamp]);
    DB::table('products')->where('id',1)->update(['subcategory_id'=>2,'has_clean'=>1,'created_at'=>$stamp,'updated_at'=>$stamp]);
    foreach(['combo','large','medium'] as $index=>$name)DB::table('product_features')->insert(['id'=>$index+1,'added_by'=>1,'product_id'=>1,'name'=>$name,'created_at'=>$stamp,'updated_at'=>$stamp]);
    $addons=['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
    DB::table('resturant_products')->where('id',1)->update(['price'=>json_encode(array_fill_keys($addons,0)),'highest_rated'=>'no','created_at'=>$stamp,'updated_at'=>$stamp]);
    $device=(string)\Illuminate\Support\Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'original restaurant product browser','nonce'=>bin2hex(random_bytes(32))],$actor);
    $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
    config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();$import=app(DesktopDashboardImport::class)->import($snapshot);
    verify(app(DesktopDashboardImport::class)->verify($import)['verified']&&count($snapshot['tables'])===106,'actual CRUD browser uses a verified fresh original106 schema import');
    $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=count($snapshot['tables']);
    verify(count((array)DB::table('resturant_products')->where('id',1)->first())===13,'actual original product has all13 inspected columns');
    $businessFingerprint=function(array $allowedMedia=[])use($report){
        $tables=[];foreach(array_keys($report['tables']) as $table){$rows=[];
            foreach(DB::table($table)->get() as $raw){$row=(array)$raw;if($table==='media'&&in_array((int)$row['id'],$allowedMedia,true))continue;
                ksort($row);$rows[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
            sort($rows);$tables[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];}
        ksort($tables);return ['tables'=>count($tables),'sha256'=>hash('sha256',json_encode($tables,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    };$businessBefore=$businessFingerprint();$receipt['originalBusinessTablesBefore']=$businessBefore;
    $imageA=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=',true);
    $imageB=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);
    $returnQuery='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';$returnPath='/admin/resturants/100?'.$returnQuery;
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();
    foreach(['DB_DATABASE'=>$stage,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ORIGIN'=>$origin,
        'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32)),'QUEUE_CONNECTION'=>'database','BROADCAST_DRIVER'=>'log'] as $key=>$value)$env[$key]=$value;
    $web=proc_open([PHP_BINARY,'-d','upload_max_filesize=6M','-d','post_max_size=16M','-S','127.0.0.1:'.$port,'-t',$application.'/public',$application.'/desktop/router.php'],
        [['pipe','r'],['file',$artifact.'/http.log','w'],['file',$artifact.'/http.log','a']],$pipes,$application,$env);$httpStopped=false;
    try{
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browser=proc_open(['node',$script],[['pipe','r'],['file',$artifact.'/browser.log','w'],['file',$artifact.'/browser-errors.log','w']],$browserPipes,$application,$env);$browserStopped=false;
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$token,'sourceCommit'=>$commit,'fixtureRevision'=>$argv[4],'returnPath'=>$returnPath,
            'imageA'=>base64_encode($imageA),'imageB'=>base64_encode($imageB)]));fclose($browserPipes[0]);
        $deadline=microtime(true)+180;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=true;if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/browser-errors.log'));$receipt['browserExitCode']=$exit;
        if($exit!==0){if(is_file($profile.'/logs/laravel.log'))copy($profile.'/logs/laravel.log',$artifact.'/laravel.log');throw new RuntimeException('Actual original restaurant product CRUD browser failed; inspect original HTTP/browser logs.');}
        preg_match('/RESTAURANT_PRODUCT_CRUD_BROWSER_PROOF (\{[^\r\n]+\})/',$output,$match);$proof=json_decode($match[1]??'null',true);$receipt['browserProof']=$proof;
        verify(($proof['sourceCommit']??null)===$commit&&($proof['fixtureRevision']??null)===$argv[4]&&($proof['browserPlatform']??null)==='win32'
            &&($proof['browser']??null)==='Windows Edge'&&($proof['newPageErrors']??null)===0&&($proof['force']??null)===false&&($proof['domInjection']??null)===false
            &&($proof['originalForm']??false)&&($proof['actualAssociatedFormControls']??false)&&($proof['exactSerializedCsrfAndUuid']??false),
            'actual Windows Edge runs frozen original CRUD forms without force, injected controls or new page errors');
        $lost=$proof['lostProof']??[];
        verify(($lost['committedResponseLost']??false)&&($lost['changedSameUuidStatus']??null)===409&&($lost['originalFileSameUuidStatus']??null)===302
            &&($lost['originalFormRetained']??false)&&($lost['originalFileRetained']??false)&&($lost['persistedNameUnchanged']??false)
            &&($lost['resourceType']??null)==='fetch'&&($lost['isNavigationRequest']??null)===false&&($proof['retainedFileAfterLoss']??false)
            &&($proof['changedSameUuid409']??false)&&($proof['originalRetry302']??false),
            'original add modal retains the real PNG and UUID through committed response loss, changed409 and exact-file retry302');
        verify(($proof['originalAddModal']??false)&&($proof['originalEditModal']??false)&&($proof['originalConfirmedDelete']??false)
            &&($proof['originalMultipartFiles']??false)&&($proof['originalAjaxCatalogAndAddons']??false)&&($proof['noImageUpdate']??false)&&($proof['replacementImageUpdate']??false)
            &&($proof['originalQueryReadPreserved']??false)&&($proof['freshUuidOnlyAfterAcceptedNavigation']??false)&&($proof['createUpdateReplaceDelete']??false),
            'original add/edit AJAX catalog and addon controls, optional image, replacement image and swal deletion remain usable');
        $rows=DB::table('desktop_dashboard_commands')->orderBy('sequence')->get();$operations=$proof['operations']??[];$product=(int)($proof['productId']??0);
        verify($rows->count()===4&&count($operations)===4&&count(array_unique(array_column($operations,'command')))===4&&$product>1,
            'one original create, two original edits and one original delete produce four immutable commands despite a lost create reply');
        $routes=['resturant_products.store','resturant_products.update','resturant_products.update','resturant_products.destroy'];$results=[];$payloads=[];$mediaIds=[];
        $adapter=app(\App\Services\Dashboard\DesktopDashboardRestaurantProductCrud::class);$normal=function($value)use(&$normal){
            if(!is_array($value))return $value;if(isset($value['$desktop_ref']))return $value['$desktop_ref']['local_id'];foreach($value as $key=>$part)$value[$key]=$normal($part);return $value;};
        foreach($rows as $index=>$row){
            $payload=json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR);$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
            $result=$saved['result'];$results[]=$result;$payloads[]=$payload;$original=$normal($payload);$op=$operations[$index];
            verify($row->route_name===$routes[$index]&&$op['route_name']===$row->route_name&&$row->command_id===$op['command']&&(int)$row->actor_id===1&&$row->status==='pending'
                &&$original['values']['idempotency_key']===$row->command_id&&$saved['references']===$adapter->outputs($result)
                &&$result['http']['status']===302&&$result['http']['location']===$returnPath
                &&$result['success']===trans($index===3?'messages.DeleteSuccessfully':'messages.AddSuccessfully'),
                'original browser CRUD journal preserves actor, route, UUID, complete outputs and original302 flash '.$index);
            verify($original['facts']['restaurant_product_crud_return']===['query'=>$returnQuery,'filter_restaurant_id'=>101]
                &&!array_key_exists('_desktop_command',$original['values'])&&!array_key_exists('_method',$original['values'])
                &&($index===3?$original['parameters']===['resturant'=>$product]:$original['parameters']===[]&&(int)$original['values']['resturant_id']===100
                    &&(int)$original['values']['added_by']===1&&(int)$original['values']['product_id']===1),
                'original query bytes and actual form associations enter the journal without transport mass-assignment fields '.$index);
            if($index<3){$after=$result['after']['product']['row'];
                verify(count($after)===13&&(int)$after['id']===$product&&$after['product_name']==='رنجة منتج المتصفح'&&$after['product_description']==='وصف المنتج من الشاشة الأصلية'
                    &&$after['product_price']===['123.00','124.00','125.00'][$index]
                    &&json_decode($after['price'],true)===array_combine($addons,['1','2','3','4','5','6'])
                    &&$after['status']===null&&$after['highest_rated']===null,
                    'actual original13-column product preserves all six extras, original nullable defaults and price '.$index);}
            foreach($result['after']['media'] as $media){$mediaIds[]=(int)$media['id'];verify(count($media)===18&&$media['model_type']===\App\Models\ResturantProduct::class
                &&(int)$media['model_id']===$product&&$media['collection_name']==='product_image'&&$media['disk']==='products',
                'actual original Spatie result includes complete18-column product media '.$index);}
        }
        $descriptor=function(string $name,string $bytes){return ['name'=>$name,'mime'=>'image/png','sha256'=>hash('sha256',$bytes),'base64'=>base64_encode($bytes)];};
        verify($payloads[0]['files']===['product_image'=>$descriptor('رنجة محلية.png',$imageA)]&&$payloads[1]['files']===[]
            &&$payloads[2]['files']===['product_image'=>$descriptor('صورة بديلة.png',$imageB)]&&$payloads[3]['files']===[],
            'original multipart files are immutable exact byte envelopes while no-image and delete commands contain none');
        verify($normal($payloads[0]['facts']['restaurant_product_crud_before'])['product']===null
            &&$normal($payloads[1]['facts']['restaurant_product_crud_before'])['product']['row']===$results[0]['after']['product']['row']
            &&$normal($payloads[2]['facts']['restaurant_product_crud_before'])['product']['row']===$results[1]['after']['product']['row']
            &&$normal($payloads[3]['facts']['restaurant_product_crud_before'])['product']['row']===$results[2]['after']['product']['row'],
            'each original browser edit/delete retains the complete preceding product before-state');
        verify($results[0]['after']['media']===$results[1]['after']['media']&&$results[0]['after']['images']===$results[1]['after']['images']
            &&count($results[2]['after']['media'])===1&&$results[2]['after']['media']===$results[3]['after']['media']
            &&$results[2]['after']['images']===$results[3]['after']['images']&&$results[3]['after']['product']===null,
            'optional-image edit preserves media and original query delete preserves the complete replacement media rows and images');
        $images=[];foreach([$results[0],$results[2]] as $index=>$result){$fact=$result['after']['images'][0]['files'][0];$path=$profile.'/app/public/'.$fact['path'];$bytes=$index===0?$imageA:$imageB;
            verify(is_file($path)&&file_get_contents($path)===$bytes&&hash_file('sha256',$path)===$fact['sha256'],
                'committed old and replacement product image bytes remain immutable after replacement and original deletion '.$index);$images[]=['path'=>$fact['path'],'sha256'=>$fact['sha256']];}
        verify(!DB::table('resturant_products')->where('id',$product)->exists()&&DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->where('model_id',$product)->get()->map(fn($row)=>(array)$row)->all()===$results[3]['after']['media']
            &&DB::table('jobs')->count()===0,'actual delete removes only the new product while preserving original orphan media and skipping all local provider jobs');
        $receipt['originalBusinessTablesAfter']=$businessFingerprint(array_values(array_unique($mediaIds)));$receipt['originalBusinessTablesUnchanged']=$receipt['originalBusinessTablesAfter']===$businessBefore;
        verify($receipt['originalBusinessTablesUnchanged']&&$businessBefore['tables']===106,'all106 original business tables remain identical outside the command-created product media');
        foreach(['originalForm','actualAssociatedFormControls','exactSerializedCsrfAndUuid','retainedFileAfterLoss','changedSameUuid409','originalRetry302','createUpdateReplaceDelete'] as $field)$receipt[$field]=$proof[$field];
        $receipt['mediaFiles']=$images;$receipt['mediaPreserved']=true;$receipt['targetColumnCount']=13;$receipt['mediaColumnCount']=18;$receipt['commands']=4;
        $fixtureCompleted=true;
        echo 'ORIGINAL_RESTAURANT_PRODUCT_CRUD_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],'schemaInventory'=>106,'checks'=>$GLOBALS['count']??0,'commands'=>4,'fullDashboard'=>false]).PHP_EOL;
    }finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);$httpStopped=true;}
})();
