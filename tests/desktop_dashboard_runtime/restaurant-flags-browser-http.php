<?php
// Arguments: immutable original application, private Maria port, artifact directory, frozen fixture SHA.
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\{Resturant,User};
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};
(function()use($argv){
    $fixture=__DIR__;$artifact=$argv[3];$script=$fixture.'/restaurant-flags-browser.cjs';$application=realpath($argv[1]);
    if(!preg_match('/^[a-f0-9]{40}$/D',$argv[4]??''))throw new RuntimeException('A frozen restaurant flag fixture SHA is required.');
    if(!is_dir($artifact)&&!mkdir($artifact,0700,true))throw new RuntimeException('Restaurant flag artifact directory unavailable.');
    $fixtureFiles=['restaurant-flags-browser-http.php','restaurant-flags-browser.cjs','legacy.php','full-schema.php','fixtures/deployed-schema-20261008.json'];
    $repository=dirname($fixture,2);$fixtureBindings=[];
    $verifyFixtures=function()use($fixtureFiles,$repository,$fixture,$argv,&$fixtureBindings){
        foreach($fixtureFiles as $file){
            $git=proc_open(['git','-C',$repository,'cat-file','blob',$argv[4].':tests/desktop_dashboard_runtime/'.$file],[['pipe','r'],['pipe','w'],['pipe','w']],$gitPipes);
            fclose($gitPipes[0]);$bytes=stream_get_contents($gitPipes[1]);fclose($gitPipes[1]);$error=stream_get_contents($gitPipes[2]);fclose($gitPipes[2]);
            if(proc_close($git)!==0||hash('sha256',$bytes)!==hash_file('sha256',$fixture.'/'.$file))throw new RuntimeException('Frozen restaurant flag fixture differs: '.$file);
            $fixtureBindings[$file]=hash('sha256',$bytes);
        }
    };$verifyFixtures();
    $manifest=json_decode(file_get_contents(dirname($application).'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$commit=$manifest['sourceRevision'];
    if(!preg_match('/^[a-f0-9]{40}$/D',$commit))throw new RuntimeException('Restaurant flag application revision unavailable.');
    $verifySource=function()use($manifest,$application){foreach($manifest['sourceHashes'] as $file=>$hash)if(hash_file('sha256',$application.'/'.$file)!==$hash)throw new RuntimeException('Immutable restaurant flag application differs: '.$file);};$verifySource();
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
    $receipt=['format'=>1,'kind'=>'original-restaurant-two-flags-browser','sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],
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
        if(!$receipt['completed']){fwrite(STDERR,'Original restaurant flag browser fixture or cleanup did not complete.'.PHP_EOL);exit(1);}
    });
    $receipt['phpVersion']=PHP_VERSION;$receipt['laravel']=\Illuminate\Foundation\Application::VERSION;$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn();
    verify($receipt['laravel']==='8.83.29','actual restaurant checkbox proof uses original Laravel8.83.29');
    $classes=['App\\Models\\Resturant'=>'app/Models/Resturant.php','App\\Services\\Dashboard\\DesktopDashboardRestaurantFlags'=>'app/Services/Dashboard/DesktopDashboardRestaurantFlags.php',
        'App\\Http\\Controllers\\Dashboard\\ResturantController'=>'app/Http/Controllers/Dashboard/ResturantController.php','App\\Repositories\\ResturantRepository'=>'app/Repositories/ResturantRepository.php',
        'Illuminate\\Foundation\\Application'=>'vendor/laravel/framework/src/Illuminate/Foundation/Application.php'];
    foreach($classes as $class=>$file)if(realpath((new ReflectionClass($class))->getFileName())!==realpath($application.'/'.$file))throw new RuntimeException('Original restaurant browser class source differs: '.$class);
    $receipt['reflectionBindings']=count($classes);$stamp='2020-01-01 00:00:00';
    DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
    DB::table('roles')->insert(['id'=>11,'name'=>'Original Restaurant Checkbox Reader','guard_name'=>'admin','created_at'=>$stamp,'updated_at'=>$stamp]);
    $actor=User::withoutGlobalScopes()->findOrFail(1);$actor->assignRole(\Spatie\Permission\Models\Role::findById(11,'admin'));
    $read=\Spatie\Permission\Models\Permission::create(['name'=>'resturant-list','guard_name'=>'admin']);$actor->givePermissionTo($read);
    verify(!$actor->hasRole('Super Admin')&&$actor->roles->pluck('id')->all()===[11]&&$actor->getAllPermissions()->pluck('name')->all()===['resturant-list'],
        'actual role11 restaurant checkbox actor has only original restaurant read authority and no edit/POS grant');
    DB::table('resturants')->where('id',100)->update(['is_featured'=>'no','under_contract'=>'no','created_at'=>$stamp,'updated_at'=>$stamp]);
    $device=(string)\Illuminate\Support\Str::uuid();$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$device,'name'=>'original restaurant checkbox browser','nonce'=>bin2hex(random_bytes(32))],$actor);
    $snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
    config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>$device]);DB::purge();$import=app(DesktopDashboardImport::class)->import($snapshot);
    verify(app(DesktopDashboardImport::class)->verify($import)['verified']&&count($snapshot['tables'])===106,'actual restaurant checkbox browser uses a verified fresh original106 schema import');
    $receipt['verifiedImport']=true;$receipt['importedSnapshotTables']=count($snapshot['tables']);
    DB::table('media')->insert(['id'=>96001,'model_type'=>Resturant::class,'model_id'=>100,'collection_name'=>'browser-retention-proof','name'=>'browser-retained','file_name'=>'retained.txt',
        'mime_type'=>'text/plain','disk'=>'public','conversions_disk'=>'public','size'=>strlen('restaurant-flags-retention'),'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}']);
    $media=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail(96001);$file=$media->getPath();mkdir(dirname($file),0700,true);file_put_contents($file,'restaurant-flags-retention');$mediaHash=hash_file('sha256',$file);
    $before=(array)DB::table('resturants')->where('id',100)->first();verify(count($before)===29,'actual original restaurant checkbox target has all29 inspected columns');
    // Every original table is compared, including full media rows, financial rows,
    // permissions and unrelated restaurants. Only the target's flags/timestamp are omitted.
    $businessFingerprint=function()use($report){
        $tables=[];foreach(array_keys($report['tables']) as $table){$rows=[];
            foreach(DB::table($table)->get() as $raw){$row=(array)$raw;if($table==='resturants'&&(int)$row['id']===100)unset($row['is_featured'],$row['under_contract'],$row['updated_at']);ksort($row);$rows[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
            sort($rows);$tables[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];}
        ksort($tables);return ['tables'=>count($tables),'sha256'=>hash('sha256',json_encode($tables,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    };$businessBefore=$businessFingerprint();$receipt['originalBusinessTablesBefore']=$businessBefore;
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
    $origin='http://127.0.0.1:'.$port;$token=bin2hex(random_bytes(32));$env=getenv();
    foreach(['DB_DATABASE'=>$stage,'APP_URL'=>$origin,'DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ORIGIN'=>$origin,
        'DESKTOP_DASHBOARD_TOKEN'=>$token,'DESKTOP_DASHBOARD_CONTROL_TOKEN'=>bin2hex(random_bytes(32))] as $key=>$value)$env[$key]=$value;
    $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$artifact.'/http.log','w'],['file',$artifact.'/http.log','a']],$pipes,$application,$env);$httpStopped=false;
    try{
        for($n=0;$n<100;$n++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
        $browser=proc_open(['node',$script],[['pipe','r'],['file',$artifact.'/browser.log','w'],['file',$artifact.'/browser-errors.log','w']],$browserPipes,$application,$env);$browserStopped=false;
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$token,'sourceCommit'=>$commit,'fixtureRevision'=>$argv[4]]));fclose($browserPipes[0]);
        $deadline=microtime(true)+180;do{$status=proc_get_status($browser);if(!$status['running'])break;usleep(100000);}while(microtime(true)<$deadline);
        if($status['running'])proc_terminate($browser);$exit=proc_close($browser);$browserStopped=true;if($exit<0&&!$status['running'])$exit=$status['exitcode'];
        $output=file_get_contents($artifact.'/browser.log');echo $output;fwrite(STDERR,file_get_contents($artifact.'/browser-errors.log'));$receipt['browserExitCode']=$exit;
        if($exit!==0){if(is_file($profile.'/logs/laravel.log'))copy($profile.'/logs/laravel.log',$artifact.'/laravel.log');throw new RuntimeException('Actual original restaurant checkbox browser failed; inspect original HTTP/browser logs.');}
        preg_match('/RESTAURANT_FLAGS_BROWSER_PROOF (\{[^\r\n]+\})/',$output,$match);$proof=json_decode($match[1]??'null',true);$receipt['browserProof']=$proof;
        verify(($proof['sourceCommit']??null)===$commit&&($proof['fixtureRevision']??null)===$argv[4]&&($proof['browserPlatform']??null)==='win32'
            &&($proof['browser']??null)==='Windows Edge'&&($proof['originalCheckboxOnchange']??false)&&($proof['newPageErrors']??null)===0,
            'actual Windows Edge uses the original checkbox onchange controls with no new page errors and exact frozen source');
        $retryProof=$proof['retries']??[];
        verify(($proof['committedResponsesLost']??null)===2&&($proof['oppositeSameUuidRejected']??null)===2&&($proof['originalValueRetrySameUuid']??null)===2
            &&($proof['freshUuidOnlyAfterAcceptedNavigation']??false)&&count($retryProof)===2
            &&array_column($retryProof,'field')===['is_featured','under_contract']
            &&array_column($retryProof,'persistedAfterOppositeStillChecked')===[true,true]
            &&array_column($retryProof,'actualCheckboxControls')===[true,true],
            'both actual checkbox writes retain same UUID through lost302 and unchanged persisted flag after opposite409 before accepted retry/navigation');
        $rows=DB::table('desktop_dashboard_commands')->orderBy('sequence')->get();$operations=$proof['operations']??[];
        verify($rows->count()===4&&count($operations)===4&&count(array_unique(array_column($operations,'command')))===4,
            'four actual checked/unchecked writes produce exactly four distinct journal commands despite both lost-response retries');
        $expected=$before;$firstCommand=$operations[0]['command'];
        $canonical=function($value)use(&$canonical){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);foreach($value as $key=>$item)$value[$key]=$canonical($item);return $value;};
        $exact=function($left,$right)use($canonical){return $canonical($left)===$canonical($right);};
        $expectedOperations=[['resturants.changeStatus','is_featured',true],['resturants.changeStatus','is_featured',false],
            ['resturants.changeUnderContract','under_contract',true],['resturants.changeUnderContract','under_contract',false]];
        $localId=function($value,string $entity,bool $typed)use($firstCommand){
            if(!$typed){if($value!==100)throw new RuntimeException('First restaurant checkbox must use the exact imported integer identity.');return $value;}
            if(!is_array($value))throw new RuntimeException('Later restaurant checkbox must retain its typed dependency identity.');$reference=$value['$desktop_ref']??null;
            if(count($value)!==1||!is_array($reference)||count($reference)!==3||array_diff(array_keys($reference),['entity','command_id','local_id'])
                ||($reference['entity']??null)!==$entity||($reference['command_id']??null)!==$firstCommand
                ||($reference['local_id']??null)!==100)throw new RuntimeException('Actual restaurant checkbox journal reference differs.');return $reference['local_id'];
        };
        foreach($rows as $index=>$row){
            $operation=$operations[$index];$payload=json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR);
            $saved=json_decode(Crypt::decryptString($row->local_result_cipher),true,512,JSON_THROW_ON_ERROR);$result=$saved['result'];
            $dependencies=json_decode($row->dependencies,true,512,JSON_THROW_ON_ERROR);
            verify([$operation['route_name'],$operation['field'],$operation['checked']]===$expectedOperations[$index]
                &&$row->command_id===$operation['command']&&$row->route_name===$operation['route_name']&&(int)$row->actor_id===1&&$row->status==='pending'
                &&$localId($payload['parameters']['resturant']??null,'menu_restaurant',$index>0)===100&&$payload['values']['idempotency_key']===$row->command_id
                &&$dependencies===($index===0?[]:[$firstCommand])&&$saved['references']===['menu_restaurant'=>100]
                &&($operation['checked']?($payload['values'][$operation['field']]??null)==='on':!array_key_exists($operation['field'],$payload['values'])),
                'actual original restaurant checkbox journal binds route, actor, target, UUID and exact checked/omitted value '.$index);
            $fact=$payload['facts']['restaurant_flag_before'];$fact['row']['id']=$localId($fact['row']['id'],'menu_restaurant',$index>0);$fact['branch']['id']=$localId($fact['branch']['id'],'menu_restaurant',$index>0);
            verify(count($fact['row'])===29&&$exact($fact['row'],$expected)&&$fact['added_by']===['id'=>1]&&$fact['owner']===['id'=>10]
                &&$fact['parent']===null&&$exact($fact['branch'],['kind'=>'f','id'=>100]),
                'actual original restaurant checkbox saves all29 before-state columns and exact original relations '.$index);
            $after=$result['after']['row'];$prior=$expected;$prior[$operation['field']]=$operation['checked']?'yes':'no';
            $compared=$after;unset($compared['updated_at'],$prior['updated_at']);
            verify(count($after)===29&&$exact($compared,$prior)&&$result['http']['status']===302&&$result['http']['location']==='/admin/resturants'
                &&$result['success']===trans('messages.UpdateSuccessfully')&&$result['references']===['menu_restaurant'=>100],
                'actual original restaurant checkbox changes only its flag and timestamp while retaining original302/success '.$index);$expected=$after;
        }
        verify($exact((array)DB::table('resturants')->where('id',100)->first(),$expected)&&$expected['is_featured']==='no'&&$expected['under_contract']==='no',
            'actual final restaurant row exactly matches the four original checked/unchecked results');
        $receipt['originalBusinessTablesAfter']=$businessFingerprint();$receipt['originalBusinessTablesUnchanged']=$receipt['originalBusinessTablesAfter']===$businessBefore;
        verify($receipt['originalBusinessTablesUnchanged']&&$businessBefore['tables']===106,
            'all106 original business tables remain identical outside the target flags/timestamp, including other rows and media');
        verify(is_file($file)&&hash_file('sha256',$file)===$mediaHash,'actual original restaurant checkbox writes and same-UUID retries preserve attached file bytes');
        $receipt['mediaFileSha256']=$mediaHash;$receipt['mediaPreserved']=true;$receipt['targetColumnCount']=29;$receipt['commands']=4;
        $fixtureCompleted=true;
        echo 'ORIGINAL_RESTAURANT_FLAGS_BROWSER_COMPLETE '.json_encode(['sourceRevision'=>$commit,'fixtureRevision'=>$argv[4],'schemaInventory'=>106,'checks'=>$GLOBALS['count']??0,'commands'=>4,'fullDashboard'=>false]).PHP_EOL;
    }finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);$httpStopped=true;}
})();
