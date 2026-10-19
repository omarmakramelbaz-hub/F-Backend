<?php
// Arguments: immutable original application, private Maria port, artifact directory, frozen fixture SHA.
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\{Resturant,User};
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};
(function()use($argv){
    $fixture=__DIR__;$artifact=$argv[3];$script=$fixture.'/restaurant-menu-copy-browser.cjs';$application=realpath($argv[1]);
    if(!preg_match('/^[a-f0-9]{40}$/D',$argv[4]??''))throw new RuntimeException('A frozen restaurant menu copy fixture SHA is required.');
    if(!is_dir($artifact)&&!mkdir($artifact,0700,true))throw new RuntimeException('Restaurant menu copy artifact directory unavailable.');
    $fixtureFiles=['restaurant-menu-copy-browser-http.php','restaurant-menu-copy-browser.cjs','browser-sound.cjs','runtime-engine.php','legacy.php','full-schema.php','fixtures/deployed-schema-20261008.json'];
    $repository=dirname($fixture,2);$fixtureBindings=[];
    $verifyFixtures=function()use($fixtureFiles,$repository,$fixture,$argv,&$fixtureBindings){
        foreach($fixtureFiles as $file){
            $git=proc_open(['git','-C',$repository,'cat-file','blob',$argv[4].':tests/desktop_dashboard_runtime/'.$file],[['pipe','r'],['pipe','w'],['pipe','w']],$gitPipes);
            fclose($gitPipes[0]);$bytes=stream_get_contents($gitPipes[1]);fclose($gitPipes[1]);$error=stream_get_contents($gitPipes[2]);fclose($gitPipes[2]);
            if(proc_close($git)!==0||hash('sha256',$bytes)!==hash_file('sha256',$fixture.'/'.$file))throw new RuntimeException('Frozen restaurant menu copy fixture differs: '.$file);
            $fixtureBindings[$file]=hash('sha256',$bytes);
        }
    };$verifyFixtures();
    $manifest=json_decode(file_get_contents(dirname($application).'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$commit=$manifest['sourceRevision'];
    if(!preg_match('/^[a-f0-9]{40}$/D',$commit))throw new RuntimeException('Restaurant menu copy application revision unavailable.');
    $verifySource=function()use($manifest,$application){foreach($manifest['sourceHashes'] as $file=>$hash)if(hash_file('sha256',$application.'/'.$file)!==$hash)throw new RuntimeException('Immutable restaurant menu copy application differs: '.$file);};$verifySource();
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
    $receipt=['format'=>1,'kind'=>'original-restaurant-menu-copy-browser','sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],
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
        if(!$receipt['completed']){fwrite(STDERR,'Original restaurant menu copy browser fixture or cleanup did not complete.'.PHP_EOL);exit(1);}
    });
    $receipt['phpVersion']=PHP_VERSION;require_once __DIR__.'/runtime-engine.php';
$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    verify($receipt['laravel']===DesktopRuntimeEngineProof::expected($application),'actual menu-copy browser uses its independently locked installed engine');
    $classes=['App\\Models\\ResturantProduct'=>'app/Models/ResturantProduct.php','App\\Models\\Resturant'=>'app/Models/Resturant.php',
        'App\\Http\\Controllers\\Api\\V1\\Vendor\\ResturantProductController'=>'app/Http/Controllers/Api/V1/Vendor/ResturantProductController.php',
        'App\\Http\\Requests\\Api\\Vendor\\CopyMenuRequest'=>'app/Http/Requests/Api/Vendor/CopyMenuRequest.php',
        'App\\Services\\Dashboard\\DesktopDashboardRestaurantMenuCopy'=>'app/Services/Dashboard/DesktopDashboardRestaurantMenuCopy.php',
        'App\\Services\\Dashboard\\DesktopDashboardProductImages'=>'app/Services/Dashboard/DesktopDashboardProductImages.php',
        'Spatie\\MediaLibrary\\MediaCollections\\Models\\Media'=>'vendor/spatie/laravel-medialibrary/src/MediaCollections/Models/Media.php',
        'Spatie\\MediaLibrary\\MediaCollections\\FileAdder'=>'vendor/spatie/laravel-medialibrary/src/MediaCollections/FileAdder.php',
        'Illuminate\\Foundation\\Application'=>'vendor/laravel/framework/src/Illuminate/Foundation/Application.php'];
    foreach($classes as $class=>$file)if(realpath((new ReflectionClass($class))->getFileName())!==realpath($application.'/'.$file))throw new RuntimeException('Original menu-copy browser class source differs: '.$class);
    $receipt['reflectionBindings']=count($classes);$stamp='2020-01-01 00:00:00';$addons=['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
    DB::table('users')->where('id',1)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>101]);DB::table('resturants')->where('id',100)->update(['parent_id'=>101,'created_at'=>$stamp,'updated_at'=>$stamp]);
    DB::table('roles')->insert(['id'=>11,'name'=>'Original Parent Menu Reader','guard_name'=>'admin','created_at'=>$stamp,'updated_at'=>$stamp]);
    $actor=User::withoutGlobalScopes()->findOrFail(1);$actor->assignRole(\Spatie\Permission\Models\Role::findById(11,'admin'));
    $read=\Spatie\Permission\Models\Permission::create(['name'=>'resturant-list','guard_name'=>'admin']);$actor->givePermissionTo($read);
    verify(!$actor->hasRole('Super Admin')&&$actor->getAllPermissions()->pluck('name')->all()===['resturant-list'],
        'actual original menu-copy actor has only restaurant show authority; the source declares no copy permission');
    $sourceIds=[22001,22002,22003,22004];$names=['صنف السعر الموجود','صنف الصورة الأولى','صنف الصورة الثانية','صنف بلا صورة'];
    for($index=1;$index<4;$index++)DB::table('products')->insert(['id'=>$index+1,'added_by'=>1,'category_id'=>1,'name_ar'=>'كتالوج النسخ '.$index,'name_en'=>'Copy catalog '.$index,'status'=>'show','created_at'=>$stamp,'updated_at'=>$stamp]);
    foreach($sourceIds as $index=>$id)DB::table('resturant_products')->insert(['id'=>$id,'added_by'=>11,'resturant_id'=>101,'product_id'=>$index+1,'category_id'=>1,
        'product_name'=>$names[$index],'product_description'=>'وصف مصدر النسخ '.$index,'product_price'=>(string)(111*($index+1)),
        'price'=>json_encode(array_combine($addons,['1','2','3','4','5','6'])),'status'=>'show','highest_rated'=>'no','created_at'=>$stamp,'updated_at'=>$stamp]);
    DB::table('resturant_products')->where('id',1)->update(['price'=>json_encode(array_fill_keys($addons,0)),'highest_rated'=>'no','created_at'=>$stamp,'updated_at'=>$stamp]);
    $pngA=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=',true);
    $pngB=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);$sourceFiles=[];
    foreach([[23300,1,$pngB],[23301,22001,$pngA],[23302,22002,$pngA],[23303,22003,$pngB]] as $index=>[$id,$product,$bytes]){
        DB::table('media')->insert(['id'=>$id,'model_type'=>\App\Models\ResturantProduct::class,'model_id'=>$product,'uuid'=>null,'collection_name'=>'product_image',
            'name'=>'صورة أصلية '.$index,'file_name'=>'صورة-أصلية-'.$index.'.png','mime_type'=>'image/png','disk'=>'products','conversions_disk'=>'products','size'=>strlen($bytes),
            'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}','order_column'=>$index+1,'created_at'=>$stamp,'updated_at'=>$stamp]);
        $file=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($id)->getPath();if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);file_put_contents($file,$bytes);$sourceFiles[$file]=hash('sha256',$bytes);
    }
    $device=(string)\Illuminate\Support\Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'original parent-menu browser','nonce'=>bin2hex(random_bytes(32))],$actor);
    $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
    config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();$import=app(DesktopDashboardImport::class)->import($snapshot);
    verify(app(DesktopDashboardImport::class)->verify($import)['verified']&&count($snapshot['tables'])===106&&count($snapshot['media'])===4,
        'actual menu-copy browser imports the original106 scoped schema and complete source/target PNG proofs');
    $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=106;$receipt['sourceImagesImported']=4;
    $beforeTarget=(array)DB::table('resturant_products')->where('id',1)->first();$beforeSources=DB::table('resturant_products')->whereIn('id',$sourceIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $beforeSourceMedia=DB::table('media')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $fingerprint=function(array $products=[],array $media=[])use($report){$inventory=[];
        foreach(array_keys($report['tables']) as $table){$parts=[];foreach(DB::table($table)->get() as $raw){$row=(array)$raw;
            if($table==='resturant_products'&&in_array((int)$row['id'],$products,true)||$table==='media'&&in_array((int)$row['id'],$media,true))continue;
            if($table==='resturant_products'&&(int)$row['id']===1)unset($row['product_price'],$row['updated_at']);ksort($row);$parts[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
            sort($parts);$inventory[$table]=['rows'=>count($parts),'sha256'=>hash('sha256',implode("\n",$parts))];}ksort($inventory);return ['tables'=>count($inventory),'sha256'=>hash('sha256',json_encode($inventory,JSON_THROW_ON_ERROR))];
    };$beforeBusiness=$fingerprint();$receipt['originalBusinessTablesBefore']=$beforeBusiness;
    $returnQuery='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';$returnPath='/admin/resturants/100?'.$returnQuery;
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();
    foreach(['DB_DATABASE'=>$stage,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ORIGIN'=>$origin,
        'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32)),'QUEUE_CONNECTION'=>'database','BROADCAST_DRIVER'=>'log'] as $key=>$value)$env[$key]=$value;
    $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$application.'/public',$application.'/desktop/router.php'],
        [['pipe','r'],['file',$artifact.'/http.log','w'],['file',$artifact.'/http.log','a']],$pipes,$application,$env);$httpStopped=false;
    try{
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browser=proc_open(['node',$script],[['pipe','r'],['file',$artifact.'/browser.log','w'],['file',$artifact.'/browser-errors.log','w']],$browserPipes,$application,$env);$browserStopped=false;
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$token,'sourceCommit'=>$commit,'fixtureRevision'=>$argv[4],'returnPath'=>$returnPath,
            'sources'=>$sourceIds,'clonedNames'=>array_slice($names,1),'imageHashes'=>[hash('sha256',$pngA),hash('sha256',$pngB)]]));fclose($browserPipes[0]);
        $deadline=microtime(true)+180;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=true;if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/browser-errors.log'));$receipt['browserExitCode']=$exit;
        if($exit!==0){if(is_file($profile.'/logs/laravel.log'))copy($profile.'/logs/laravel.log',$artifact.'/laravel.log');throw new RuntimeException('Actual original menu-copy browser failed; inspect original HTTP/browser logs.');}
        preg_match('/RESTAURANT_MENU_COPY_BROWSER_PROOF (\{[^\r\n]+\})/',$output,$match);$proof=json_decode($match[1]??'null',true);$receipt['browserProof']=$proof;
        verify(($proof['sourceCommit']??null)===$commit&&($proof['fixtureRevision']??null)===$argv[4]&&($proof['browserPlatform']??null)==='win32'
            &&($proof['browser']??null)==='Windows Edge'&&($proof['newPageErrors']??null)===0&&($proof['force']??null)===false&&($proof['domInjection']??null)===false,
            'actual Windows Edge runs the frozen original parent-menu controls without new page errors, force or injected controls');
        verify(($proof['originalForm']??false)&&($proof['originalChooseAddWay']??false)&&($proof['originalCopyModal']??false)&&($proof['actualAssociatedFormControls']??false)
            &&($proof['exactSerializedCsrfAndUuid']??false)&&($proof['originalCheckboxOrder']??false)&&($proof['freshUuidOnlyAfterAcceptedNavigation']??false),
            'original choose/add and copy modals serialize their actual owned ordered checkboxes, CSRF and one UUID');
        $retry=$proof['retry']??[];verify(($retry['committedResponseLost']??false)&&($retry['changedSameUuidStatus']??null)===409&&($retry['originalSelectionSameUuidStatus']??null)===302
            &&($retry['originalFormRetained']??false)&&($retry['originalCheckboxes']??false)&&($retry['resourceType']??null)==='fetch'&&($retry['isNavigationRequest']??null)===false,
            'the real original copy form survives lost302, rejects a changed selection409 and retries the exact ordered selection with one UUID');
        $entries=DB::table('desktop_dashboard_commands')->orderBy('sequence')->get();$operations=$proof['operations']??[];
        verify($entries->count()===2&&count($operations)===2&&count(array_unique(array_column($operations,'command')))===2,'two accepted checkbox batches create exactly two commands despite the lost-response retry');
        $adapter=app(\App\Services\Dashboard\DesktopDashboardRestaurantMenuCopy::class);$results=[];$payloads=[];
        $normal=function($value)use(&$normal){if(!is_array($value))return $value;if(isset($value['$desktop_ref']))return $value['$desktop_ref']['local_id'];foreach($value as $key=>$part)$value[$key]=$normal($part);return $value;};
        foreach($entries as $index=>$entry){$payload=json_decode(Crypt::decryptString($entry->command_cipher),true,512,JSON_THROW_ON_ERROR);
            $saved=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);$result=$saved['result'];$original=$normal($payload);$payloads[]=$original;$results[]=$result;
            verify($entry->route_name==='copy_menu'&&$entry->command_id===$operations[$index]['command']&&(int)$entry->actor_id===1&&$entry->status==='pending'
                &&$original['parameters']===[]&&$original['files']===[]&&(int)$original['values']['resturant_id']===100
                &&array_map('intval',$original['values']['restraunt_product'])===$sourceIds&&$original['values']['idempotency_key']===$entry->command_id
                &&$saved['references']===$adapter->outputs($result)&&$result['http']['status']===302&&$result['http']['location']===$returnPath
                &&$result['success']===__('main.menue copied successfully'),
                'original checkbox journal preserves exact route, ordered batch, source actors, complete outputs and original302 flash '.$index);
            verify(count($original['facts']['restaurant_menu_copy_before']['target']['row'])===29&&$original['facts']['restaurant_menu_copy_before']['actor']===['id'=>1]
                &&array_column(array_column($original['facts']['restaurant_menu_copy_before']['sources'],'row'),'id')===$sourceIds
                &&$original['facts']['restaurant_menu_copy_return']===['query'=>$returnQuery,'filter_restaurant_id'=>101],
                'each original copy captures all29 restaurant fields, ordered complete source states and original query bytes '.$index);
            verify(array_column($result['after']['steps'],'mode')===($index===0?['price','create','create','create']:['price','price','price','price'])
                &&array_column($result['after']['steps'],'source_product')===$sourceIds&&count($result['after']['products'])===4,
                'cached initial target membership preserves original price-only versus clone decisions '.$index);
        }
        $created=array_column(array_slice($results[0]['after']['steps'],1),'target_product');$newMedia=array_values(array_filter(array_column($results[0]['after']['steps'],'new_media')));
        verify($created===($proof['visibleIds']??null)&&count(array_unique($created))===3&&count($newMedia)===2
            &&array_column($results[1]['after']['steps'],'target_product')===array_column($results[0]['after']['steps'],'target_product'),
            'three original clones and two copied images retain their identities through the subsequent price-only batch');
        $stripTime=function(array $row){unset($row['updated_at']);return $row;};$existing=$results[0]['after']['products'][0];$expected=$beforeTarget;$expected['product_price']='111.00';
        verify($stripTime($existing['row'])===$stripTime($expected),
            'an existing catalog match changes only original product_price and its timestamp');
        // Explicit comparisons keep every source field and existing image distinct from new clone metadata.
        verify($existing['media']===[$beforeSourceMedia[0]]&&$existing['row']['price']===$beforeTarget['price'],
            'an existing catalog match changes only original product_price/timestamp and preserves addon prices and image rows');
        foreach(array_slice($results[0]['after']['products'],1) as $index=>$state){$source=$beforeSources[$index+1];$expected=$source;$expected['id']=$created[$index];$expected['resturant_id']=100;$expected['added_by']=1;
            unset($expected['created_at'],$expected['updated_at']);$actual=$state['row'];unset($actual['created_at'],$actual['updated_at']);
            verify(count($state['row'])===13&&$actual===$expected,'the original replicate preserves every source product field except destination actor/id/times '.$index);}
        $imageCopies=[];foreach([1,2] as $selection){$destination=$results[0]['after']['products'][$selection];$media=$destination['media'];$sourceMedia=$beforeSourceMedia[$selection+1];
            verify(count($media)===1&&count($media[0])===18&&(int)$media[0]['model_id']===$created[$selection-1]&&$media[0]['name']===$sourceMedia['name']
                &&$media[0]['file_name']===$sourceMedia['file_name']&&$media[0]['mime_type']===$sourceMedia['mime_type']&&$media[0]['collection_name']==='product_image'&&$media[0]['disk']==='products'
                &&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-5[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$media[0]['uuid'])
                &&$media[0]['created_at']===$media[0]['updated_at'],
                'actual original FileAdder copies canonical source filename/name and complete18-column PNG metadata '.$selection);
            $facts=array_values(array_filter($results[0]['after']['images'],fn($image)=>(int)$image['row']['id']===(int)$media[0]['id']));$file=$facts[0]['files'][0];$path=$profile.'/app/public/'.$file['path'];$digest=$selection===1?hash('sha256',$pngA):hash('sha256',$pngB);
            verify(is_file($path)&&hash_file('sha256',$path)===$digest&&$file['sha256']===$digest,'a complete copied PNG has the same bytes as its immutable source '.$selection);$imageCopies[]=['path'=>$file['path'],'sha256'=>$digest];}
        verify($results[0]['after']['products'][3]['media']===[]&&$results[0]['after']['images']===$results[1]['after']['images'],
            'a source with no image creates no media and the second price-only batch leaves all image identities/bytes unchanged');
        verify(DB::table('resturant_products')->whereIn('id',$sourceIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$beforeSources
            &&DB::table('media')->whereIn('id',array_column($beforeSourceMedia,'id'))->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$beforeSourceMedia,
            'the complete original parent source products and existing media rows remain immutable');
        foreach($sourceFiles as $path=>$digest)verify(is_file($path)&&hash_file('sha256',$path)===$digest,'the original source/existing target PNG bytes remain unchanged: '.basename($path));
        $receipt['originalBusinessTablesAfter']=$fingerprint($created,$newMedia);$receipt['originalBusinessTablesUnchanged']=$receipt['originalBusinessTablesAfter']===$beforeBusiness;
        verify($receipt['originalBusinessTablesUnchanged']&&DB::table('jobs')->count()===0,'all106 original business tables remain unchanged outside the exact target menu/image effects; no local provider jobs run');
        $receipt['commands']=2;$receipt['targetColumnCount']=29;$receipt['productColumnCount']=13;$receipt['mediaColumnCount']=18;$receipt['copiedProducts']=3;$receipt['copiedImages']=2;
        $receipt['mediaFiles']=$imageCopies;$receipt['sourceImagesPreserved']=true;$receipt['fullImagesCopied']=true;$receipt['originalForm']=true;
        $fixtureCompleted=true;echo 'ORIGINAL_RESTAURANT_MENU_COPY_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],'schemaInventory'=>106,'checks'=>$GLOBALS['count']??0,'commands'=>2,'fullDashboard'=>false]).PHP_EOL;
    }finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);$httpStopped=true;}
})();
