<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardCommands,DesktopDashboardReconciliation,DesktopDashboardProductImages,DesktopDashboardProductEffects,DesktopDashboardRestaurantMenuCopy};

// Actual source copy controller, FileAdder and complete106-schema local/server databases.
(function()use($database,$ownerStage,$ownerDevice,$remoteDevice,$env,$application,$httpPort,$profile,$origin,&$cookies,$report){
    $startCount=$GLOBALS['count'];echo 'RESTAURANT_MENU_COPY_LOCAL_BEGIN'.PHP_EOL;
    $switch=function(bool $local)use($ownerStage,$database,$ownerDevice){config(['database.connections.mysql.database'=>$local?$ownerStage:$database,'desktop_dashboard.local'=>$local,'desktop_dashboard.device_id'=>$ownerDevice]);DB::purge();};
    $product=fn(int $id)=>(array)DB::table('resturant_products')->where('id',$id)->first();
    $media=fn(int $id)=>DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->where('model_id',$id)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $restore=function(string $table,array $row){$id=$row['id'];unset($row['id']);DB::table($table)->where('id',$id)->update($row);};
    $fingerprint=function(array $products=[],array $media=[])use($report){$inventory=[];foreach(array_keys($report['tables']) as $table){$parts=[];foreach(DB::table($table)->get() as $raw){$row=(array)$raw;
        if($table==='resturant_products'&&in_array((int)$row['id'],$products,true)||$table==='media'&&in_array((int)$row['id'],$media,true))continue;
        ksort($row);$parts[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}sort($parts);$inventory[$table]=hash('sha256',implode("\n",$parts));}return $inventory;};
    $saved=function(string $command){$row=DB::table('desktop_dashboard_commands')->where('command_id',$command)->first();if(!$row)throw new RuntimeException('Original menu-copy command missing.');
        $record=json_decode(Crypt::decryptString($row->local_result_cipher),true,512,JSON_THROW_ON_ERROR);return ['command_id'=>$command,'actor_id'=>1,'route_name'=>'copy_menu',
            'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true,512,JSON_THROW_ON_ERROR),'local_result'=>$record['result'],'local_references'=>$record['references'],
            'dependencies'=>json_decode($row->dependencies,true,512,JSON_THROW_ON_ERROR),'occurred_at'=>Carbon::parse($row->created_at,'UTC')->toIso8601String()];};
    $reject=function(callable $work,int $status,string $message){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$status,$message);return;}
        catch(\Illuminate\Validation\ValidationException $error){verify($status===422,$message);return;}throw new RuntimeException('Menu-copy unexpectedly succeeded: '.$message);};
    $sourceIds=[982101,982102,982103,982104,982105];$catalogIds=[982202,982203,982204];$fixtureMedia=[982300,982301,982302,982303];$commands=[];$created=[false=>[],true=>[]];$createdMedia=[false=>[],true=>[]];$paths=[];$old=[];$web=null;$pipes=[];$grandchild=982400;$completed=false;$entityBaseline=[];$originalBusiness=[];
    foreach([false,true] as $local){$switch($local);$entityBaseline[$local]=DB::table('desktop_dashboard_entities')->whereIn('device_id',[$ownerDevice,$remoteDevice->id])->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        DB::table('desktop_dashboard_entities')->whereIn('device_id',[$ownerDevice,$remoteDevice->id])->delete();}
    $imagesRoot=$profile.'/app/public/products';$filesBefore=[];if(is_dir($imagesRoot))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($imagesRoot,FilesystemIterator::SKIP_DOTS)) as $file)
        if($file->isFile())$filesBefore[$file->getPathname()]=true;
    $stamp='2020-01-01 00:00:00';$addons=['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
    $pngA=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=',true);
    $pngB=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);
    foreach([false,true] as $local){$switch($local);$originalBusiness[$local]=$fingerprint();$old[$local]=['actor'=>(array)DB::table('users')->where('id',1)->first(),'target'=>(array)DB::table('resturants')->where('id',100)->first(),'product'=>$product(1)];
        DB::table('users')->where('id',1)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>101]);DB::table('resturants')->where('id',100)->update(['parent_id'=>101]);
        $baseline=$old[$local]['product'];$baseline['price']=json_encode(array_fill_keys($addons,0));$baseline['created_at']=$stamp;$baseline['updated_at']=$stamp;$restore('resturant_products',$baseline);
        foreach($catalogIds as $i=>$id)DB::table('products')->insert(['id'=>$id,'added_by'=>1,'category_id'=>1,'name_ar'=>'كتالوج النسخ '.$i,'name_en'=>'Copy catalog '.$i,'status'=>'show','created_at'=>$stamp,'updated_at'=>$stamp]);
        foreach($sourceIds as $i=>$id){$catalog=[1,...$catalogIds,1][$i];DB::table('resturant_products')->insert(['id'=>$id,'added_by'=>11,'resturant_id'=>101,'product_id'=>$catalog,'category_id'=>1,
            'product_name'=>'مصدر النسخ '.$i,'product_description'=>'وصف مصدر النسخ '.$i,'product_price'=>(string)(111*($i+1)),
            'price'=>json_encode(array_combine($addons,['1','2','3','4','5','6'])),'status'=>'show','highest_rated'=>'no','created_at'=>$stamp,'updated_at'=>$stamp]);}
        foreach([[982300,1,$pngB],[982301,982101,$pngA],[982302,982102,$pngA],[982303,982103,$pngB]] as $i=>[$id,$owner,$bytes]){
            DB::table('media')->insert(['id'=>$id,'model_type'=>\App\Models\ResturantProduct::class,'model_id'=>$owner,'uuid'=>null,'collection_name'=>'product_image','name'=>'صورة مصدر النسخ '.$i,
                'file_name'=>'صورة-مصدر-'.$i.'.png','mime_type'=>'image/png','disk'=>'products','conversions_disk'=>'products','size'=>strlen($bytes),
                'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}','order_column'=>$i+1,'created_at'=>$stamp,'updated_at'=>$stamp]);
            $path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($id)->getPath();if(!isset($paths[$path])&&(file_exists($path)||is_link($path)))throw new RuntimeException('Menu-copy seed image path already exists.');
            if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);if(file_put_contents($path,$bytes)!==strlen($bytes))throw new RuntimeException('Menu-copy seed image write failed.');$paths[$path]=$bytes;}}
    $query='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';
    $request=function(array $form,array $headers=[])use($origin,$env,$query,&$cookies){$boundary='menu-copy-'.bin2hex(random_bytes(12));$body='';
        foreach($form as $name=>$value)foreach(is_array($value)?$value:[$value] as $part)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name.(is_array($value)?'[]':'')."\"\r\n\r\n".$part."\r\n";
        $body.='--'.$boundary."--\r\n";$headers=['X-Fasakhansta-Desktop: '.$env['DESKTOP_DASHBOARD_TOKEN'],'Referer: '.$origin.'/admin/resturants/100?'.$query,'Content-Type: multipart/form-data; boundary='.$boundary,...$headers];
        if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
        $reply=@file_get_contents($origin.'/admin/resturant/copy/items',false,stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]));
        $replyHeaders=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$replyHeaders[0]??'',$status);return [(int)($status[1]??0),$reply,$replyHeaders];};
    try{
        foreach([\App\Http\Controllers\Api\V1\Vendor\ResturantProductController::class=>'app/Http/Controllers/Api/V1/Vendor/ResturantProductController.php',
            \App\Http\Requests\Api\Vendor\CopyMenuRequest::class=>'app/Http/Requests/Api/Vendor/CopyMenuRequest.php',\Spatie\MediaLibrary\MediaCollections\FileAdder::class=>'vendor/spatie/laravel-medialibrary/src/MediaCollections/FileAdder.php'] as $class=>$file)
            verify(realpath((new ReflectionClass($class))->getFileName())===realpath($application.'/'.$file),'menu copy uses actual original runtime source '.$class);
        // Advance the real source clock between two updates of the SAME cached target model.
        $switch(true);$clockCommand=(string)Str::uuid();$commands[]=$clockCommand;$actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
        $probe=Request::create(url('/admin/resturant/copy/items'),'POST',['resturant_id'=>'100','restraunt_product'=>[(string)$sourceIds[0],(string)$sourceIds[4]],'_desktop_command'=>$clockCommand]);
        $route=clone app('router')->getRoutes()->getByName('copy_menu');$route->bind($probe);$probe->setRouteResolver(fn()=>$route);$probe->headers->set('Referer',url('/admin/resturants/100').'?'.$query);
        $clock=Carbon::getTestNow();$advance=true;$updates=0;
        app('events')->listen('eloquent.updating: '.\App\Models\ResturantProduct::class,function($row)use(&$advance,&$updates){if($advance&&(int)$row->id===1){$updates++;if($updates===1)Carbon::setTestNow(Carbon::now()->addSeconds(17));}});
        try{$payload=DB::transaction(fn()=>app(DesktopDashboardRestaurantMenuCopy::class)->payload('copy_menu',$probe,$clockCommand,$actor));
            $clockResult=app(DesktopDashboardProductImages::class)->guard(fn()=>app(DesktopDashboardProductEffects::class)->guard(fn()=>app(DesktopDashboardJournal::class)->execute($ownerDevice,$clockCommand,1,'copy_menu',$payload,[],fn()=>app(DesktopDashboardCommands::class)->execute('copy_menu',$payload,$actor))));
        }finally{$advance=false;Carbon::setTestNow($clock);}
        $clockEnvelope=$saved($clockCommand);verify($updates===2&&$clockResult['after']['products'][0]['row']['product_price']==='555.00'
            &&Carbon::parse($clockResult['after']['products'][0]['row']['updated_at'],config('app.timezone'))->setTimezone('UTC')->gt(Carbon::parse($clockResult['after']['started_at'],'UTC')),
            'actual two-source cached price updates preserve their final timestamp after the clock advances beyond batch start');
        $switch(false);$clockReceipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$clockEnvelope);
        verify($clockReceipt['result']['after']['products'][0]['row']===$clockResult['after']['products'][0]['row'],'actual replay pins the advanced final timestamp through both updates of the same cached model');
        $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$clockCommand,$clockReceipt);
        $runCopy=function(int $target,array $selection,string $command,string $returnQuery)use($ownerDevice){
            $actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);$probe=Request::create(url('/admin/resturant/copy/items'),'POST',
                ['resturant_id'=>(string)$target,'restraunt_product'=>array_map('strval',$selection),'_desktop_command'=>$command]);
            $route=clone app('router')->getRoutes()->getByName('copy_menu');$route->bind($probe);$probe->setRouteResolver(fn()=>$route);
            $probe->headers->set('Referer',url('/admin/resturants/'.$target).'?'.$returnQuery);
            $payload=DB::transaction(fn()=>app(DesktopDashboardRestaurantMenuCopy::class)->payload('copy_menu',$probe,$command,$actor));
            return app(DesktopDashboardProductImages::class)->guard(fn()=>app(DesktopDashboardProductEffects::class)->guard(fn()=>
                app(DesktopDashboardJournal::class)->execute($ownerDevice,$command,1,'copy_menu',$payload,[],fn()=>app(DesktopDashboardCommands::class)->execute('copy_menu',$payload,$actor))));
        };
        // A genuine no-op retains historical model clocks; its journal still records today's accepted batch.
        foreach([false,true] as $local){$switch($local);DB::table('resturant_products')->where('id',1)->update(['product_price'=>'555.00','updated_at'=>$stamp]);}
        $switch(true);$historic=$product(1);$historicImages=$media(1);$noopCommand=(string)Str::uuid();$commands[]=$noopCommand;$acceptedBefore=now('UTC');
        $noopResult=$runCopy(100,[$sourceIds[4]],$noopCommand,$query);$noop=$saved($noopCommand);
        verify($product(1)===$historic&&$media(1)===$historicImages&&$noopResult['after']['products'][0]['row']===$historic,
            'a real same-price original copy leaves every historical2020 product field and original media row byte-for-byte unchanged');
        $occurred=Carbon::parse($noop['occurred_at'],'UTC');$started=Carbon::parse($noopResult['after']['started_at'],'UTC');
        verify($occurred->gte($acceptedBefore->copy()->subSecond())&&$occurred->lte(now('UTC')->addSecond())&&abs($occurred->getTimestamp()-$started->getTimestamp())<=5
            &&$occurred->year>2020&&$noopResult['after']['products'][0]['row']['updated_at']===$stamp,
            'the true no-op journal occurrence follows the current accepted batch start independently of its unchanged2020 business timestamp');
        $switch(false);$noopReceipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$noop);
        verify($product(1)===$historic&&$media(1)===$historicImages&&$noopReceipt['result']['after']['products'][0]['row']===$historic,
            'actual reconciliation accepts the current no-op occurrence while retaining the exact historical target row and its old complete image');
        $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$noopCommand,$noopReceipt);
        foreach([false,true] as $local){$switch($local);$baseline=$old[$local]['product'];$baseline['price']=json_encode(array_fill_keys($addons,0));$baseline['created_at']=$stamp;$baseline['updated_at']=$stamp;$restore('resturant_products',$baseline);}
        $switch(true);$baselineProduct=$product(1);$sourceStates=DB::table('resturant_products')->whereIn('id',$sourceIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();$targetMedia=$media(1);
        $business=$fingerprint([1],[]);$localEnv=$env;$localEnv['DB_DATABASE']=$ownerStage;$localEnv['DESKTOP_DASHBOARD_LOCAL']='true';
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/menu-copy-local.log','a'],['file',$profile.'/menu-copy-local.log','a']],$pipes,$application,$localEnv);
        $csrf=[];for($i=0;$i<100;$i++){$headers=['X-Fasakhansta-Desktop: '.$env['DESKTOP_DASHBOARD_TOKEN'],'Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies))];
            $page=@file_get_contents($origin.'/admin/notifications',false,stream_context_create(['http'=>['header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>3]]));preg_match('/name="csrf-token" content="([^"]+)"/',$page??'',$csrf);if(!empty($csrf[1]))break;usleep(50000);}
        verify(!empty($csrf[1]),'original local menu-copy session supplies a real CSRF token');
        $foreignCommand=(string)Str::uuid();$foreign=['_token'=>$csrf[1],'_desktop_command'=>$foreignCommand,'resturant_id'=>'100','restraunt_product'=>['1']];
        verify($request($foreign)[0]===409&&!DB::table('desktop_dashboard_commands')->where('command_id',$foreignCommand)->exists()&&$fingerprint([1],[])===$business&&$product(1)===$baselineProduct,
            'a fresh actual HTML copy rejects a selected child product outside its original parent source and changes no original106-table business row');
        $command=(string)Str::uuid();$commands[]=$command;$selection=[$sourceIds[1],$sourceIds[2],$sourceIds[1],$sourceIds[0],$sourceIds[3]];
        $form=['_token'=>$csrf[1],'_desktop_command'=>$command,'resturant_id'=>'100','restraunt_product'=>array_map('strval',$selection)];
        DB::unprepared("CREATE TRIGGER reject_second_menu_image BEFORE INSERT ON media FOR EACH ROW BEGIN IF NEW.model_type='App\\\\Models\\\\ResturantProduct' AND NEW.name='صورة مصدر النسخ 3' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture second menu image failure'; END IF; END");
        try{verify($request($form)[0]===500&&DB::table('resturant_products')->where('resturant_id',100)->count()===1&&$product(1)===$baselineProduct&&$media(1)===$targetMedia
            &&!DB::table('desktop_dashboard_commands')->where('command_id',$command)->exists(),'a real failure while adding the second copied image atomically rolls back the whole ordered batch and success receipt');}
        finally{DB::unprepared('DROP TRIGGER reject_second_menu_image');}
        [$status,$body,$headers]=$request($form);$first=$saved($command);$after=$first['local_result']['after'];$steps=$after['steps'];
        $created[true]=array_values(array_unique(array_column(array_filter($steps,fn($step)=>$step['mode']==='create'),'target_product')));$createdMedia[true]=array_values(array_filter(array_column($steps,'new_media')));
        verify($status===302&&in_array('Location: '.$origin.'/admin/resturants/100?'.$query,$headers,true)&&array_column($steps,'mode')===['create','create','create','price','create']
            &&count($created[true])===4&&count($createdMedia[true])===3&&$steps[0]['target_product']!==$steps[2]['target_product'],
            'the same actual HTML batch retries after rollback and retains ordered duplicate absent-catalog creates, existing price-only and no-image semantics');
        verify($request($form)[1]===$body&&$saved($command)===$first&&DB::table('resturant_products')->where('resturant_id',100)->count()===5,
            'an original lost copy redirect retries its exact result without another clone or image allocation');
        $changed=$form;$changed['restraunt_product']=array_reverse($form['restraunt_product']);verify($request($changed)[0]===409,'a saved UUID cannot accept the same selection values in another order');
        $changed=$form;$changed['_desktop_command']=(string)Str::uuid();verify($request($changed)[0]===409,'a later initial target containing duplicate catalog matches is explicitly unsupported in the bounded preview');
        $duplicateImages=[];foreach([0,1,2] as $slot){$row=DB::table('media')->where('id',$steps[$slot]['new_media'])->first();$row=(array)$row;$path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($row['id'])->getPath();$bytes=$slot===1?$pngB:$pngA;$paths[$path]=$bytes;$duplicateImages[]=$row;
            verify(count($row)===18&&$row['file_name']===($slot===1?'صورة-مصدر-3.png':'صورة-مصدر-2.png')&&is_file($path)&&file_get_contents($path)===$bytes,
                'actual FileAdder copies the complete source PNG and its canonical Arabic name/extension for selected slot '.$slot);}
        verify(count(array_unique(array_column($duplicateImages,'uuid')))===3&&$duplicateImages[0]['uuid']!==$duplicateImages[2]['uuid'],
            'duplicate selections receive distinct deterministic media UUID slots instead of reusing one source identity');
        verify($media(1)===$targetMedia&&$product(1)['price']===$baselineProduct['price']&&$product(1)['product_price']==='111.00',
            'the existing target changes only price/timestamp and retains its complete old addon JSON and image rows');
        foreach(['source'=>['f:100'],'target'=>['f:101']] as $kind=>$branches){$oldBranches=DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->value('branches');DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode($branches)]);
            try{verify($request($form)[0]===403,'a saved local result still requires the current enrolled '.$kind.' branch');}finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$oldBranches]);}}
        $missing=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($createdMedia[true][0])->getPath();$bytes=file_get_contents($missing);unlink($missing);
        try{verify($request($form)[0]===503,'a saved copy redirect refuses success when a complete copied destination image is missing');}finally{file_put_contents($missing,$bytes);}
        file_put_contents($missing,$pngB);try{verify($request($form)[0]===503,'a copied destination image with changed bytes invalidates the saved success receipt');}finally{file_put_contents($missing,$bytes);}
        verify($fingerprint([1,...$created[true]],$createdMedia[true])===$business&&DB::table('resturant_products')->whereIn('id',$sourceIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$sourceStates,
            'all106 original tables outside the exact copied target effects remain unchanged, including complete parent source rows');
        foreach($fixtureMedia as $id){$path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($id)->getPath();verify(is_file($path)&&file_get_contents($path)===$paths[$path],'the original source/existing image bytes remain immutable: '.$id);}
        $switch(false);DB::statement('ALTER TABLE resturant_products AUTO_INCREMENT=200000000');DB::statement('ALTER TABLE media AUTO_INCREMENT=200000000');
        $beforeRemote=$fingerprint([1],[]);$bad=$first;$sourcePrice=DB::table('resturant_products')->where('id',$sourceIds[1])->value('product_price');DB::table('resturant_products')->where('id',$sourceIds[1])->update(['product_price'=>'999.00']);
        try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),409,'changed parent source product fields conflict before replay');}finally{DB::table('resturant_products')->where('id',$sourceIds[1])->update(['product_price'=>$sourcePrice]);}
        $sourcePath=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail(982302)->getPath();$sourceBytes=file_get_contents($sourcePath);file_put_contents($sourcePath,$pngB);
        try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$first),409,'changed complete source PNG bytes conflict before replay');}finally{file_put_contents($sourcePath,$sourceBytes);}
        $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$first);$server=$receipt['result']['after'];$created[false]=array_values(array_unique(array_column(array_filter($server['steps'],fn($step)=>$step['mode']==='create'),'target_product')));$createdMedia[false]=array_values(array_filter(array_column($server['steps'],'new_media')));
        verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$first)&&array_column($server['steps'],'mode')===array_column($steps,'mode')&&min($created[false])>=200000000,
            'actual reconciliation maps every duplicate clone into independent server IDs and returns the same immutable server receipt on retry');
        foreach($server['images'] as $image){$path=$profile.'/app/public/'.$image['files'][0]['path'];if(in_array((int)$image['row']['id'],$createdMedia[false],true))$paths[$path]=file_get_contents($path);}
        verify(array_column(array_filter($server['images'],fn($image)=>in_array((int)$image['row']['id'],$createdMedia[false],true)),'row')!==$duplicateImages
            &&$fingerprint([1,...$created[false]],$createdMedia[false])===$beforeRemote,
            'server physical media paths use mapped IDs while all106 unrelated business table rows remain unchanged');
        $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$command,$receipt);
        // Consume real produced product AND media identities in a second original copy, then consume that destination in a price-only copy.
        $scopeRows=[];foreach([false,true] as $local){$switch($local);$scopeRows[$local]=[
            'actor'=>(array)DB::table('users')->where('id',1)->first(),'branches'=>DB::table($local?'desktop_dashboard_local_state':'desktop_dashboard_devices')->where($local?'device_id':'id',$local?$ownerDevice:$remoteDevice->id)->value('branches')];
            DB::table('users')->where('id',1)->update(['account_type'=>'admin','owner_resturant_id'=>null]);
            $branch=(array)DB::table('resturants')->where('id',100)->first();$branch['id']=$grandchild;$branch['parent_id']=100;$branch['name']='فرع سلسلة النسخ';DB::table('resturants')->insert($branch);
            $branches=json_decode($scopeRows[$local]['branches'],true);$branches[]='f:'.$grandchild;DB::table($local?'desktop_dashboard_local_state':'desktop_dashboard_devices')->where($local?'device_id':'id',$local?$ownerDevice:$remoteDevice->id)->update(['branches'=>json_encode($branches)]);}
        try{
            $switch(true);$sourceProduct=$steps[0]['target_product'];$sourceImage=$steps[0]['new_media'];$sourceRow=$product($sourceProduct);$sourceImageRow=$media($sourceProduct);
            $chainCommand=(string)Str::uuid();$commands[]=$chainCommand;$chainQuery='q=copy-chain&resturant_id=100&page=3';$chainResult=$runCopy($grandchild,[$sourceProduct],$chainCommand,$chainQuery);$chain=$saved($chainCommand);
            $created[true][]=$chainResult['after']['steps'][0]['target_product'];$createdMedia[true][]=$chainResult['after']['steps'][0]['new_media'];
            $typedSource=data_get($chain['payload'],'values.restraunt_product.0.$desktop_ref');$typedImage=data_get($chain['payload'],'facts.restaurant_menu_copy_before.source_images.0.0.row.id.$desktop_ref');
            verify(in_array($command,$chain['dependencies'],true)&&$typedSource===['entity'=>'menu_restaurant_product.0','command_id'=>$command,'local_id'=>$sourceProduct]
                &&$typedImage===['entity'=>'menu_product_media.0','command_id'=>$command,'local_id'=>$sourceImage],
                'the actual second copy binds its prior copied source product and image to the first command dependency and exact output slots');
            $path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail(end($createdMedia[true]))->getPath();$paths[$path]=file_get_contents($path);
            $switch(false);$bad=$chain;data_set($bad['payload'],'values.restraunt_product.0.$desktop_ref.entity','catalog_product');
            $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),422,'a prior catalog slot cannot masquerade as the produced source restaurant product');
            $chainReceipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$chain);$chainServer=$chainReceipt['result']['after'];$chainServerStep=$chainServer['steps'][0];
            $created[false][]=$chainServerStep['target_product'];$createdMedia[false][]=$chainServerStep['new_media'];$serverSourceProduct=$server['steps'][0]['target_product'];$serverSourceImage=$server['steps'][0]['new_media'];
            $serverPath=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($chainServerStep['new_media'])->getPath();$paths[$serverPath]=file_get_contents($serverPath);
            verify($chainServerStep['source_product']===$serverSourceProduct&&$chainServerStep['source_media']===$serverSourceImage
                &&$chainServerStep['target_product']!==$chainResult['after']['steps'][0]['target_product']&&$chainServerStep['new_media']!==$chainResult['after']['steps'][0]['new_media']
                &&$chainServer['products'][0]['media'][0]['uuid']===$chainResult['after']['products'][0]['media'][0]['uuid']&&file_get_contents($serverPath)===$pngA
                &&$product($serverSourceProduct)===$server['products'][1]['row']&&$media($serverSourceProduct)===$server['products'][1]['media'],
                'real replay resolves both prior produced source identities, allocates independent destination IDs and preserves the immutable complete source PNG/UUID');
            $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$chainCommand,$chainReceipt);
            $chainNoopCommand=(string)Str::uuid();$commands[]=$chainNoopCommand;$chainNoopResult=$runCopy($grandchild,[$sourceProduct],$chainNoopCommand,$chainQuery);$chainNoop=$saved($chainNoopCommand);
            $typedDestination=data_get($chainNoop['payload'],'facts.restaurant_menu_copy_before.products.0.row.id.$desktop_ref');$typedDestinationImage=data_get($chainNoop['payload'],'facts.restaurant_menu_copy_before.target_images.0.0.row.id.$desktop_ref');
            verify(in_array($command,$chainNoop['dependencies'],true)&&in_array($chainCommand,$chainNoop['dependencies'],true)
                &&$typedDestination===['entity'=>'menu_restaurant_product.0','command_id'=>$chainCommand,'local_id'=>$chainResult['after']['steps'][0]['target_product']]
                &&$typedDestinationImage===['entity'=>'menu_product_media.0','command_id'=>$chainCommand,'local_id'=>$chainResult['after']['steps'][0]['new_media']]
                &&$product($sourceProduct)===$sourceRow&&$media($sourceProduct)===$sourceImageRow,
                'the fresh original price-only copy binds its complete destination product/media before-state to the preceding real copy while preserving source rows');
            $switch(false);$bad=$chainNoop;data_set($bad['payload'],'facts.restaurant_menu_copy_before.target_images.0.0.row.id.$desktop_ref.entity','catalog_product');
            $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),422,'a prior catalog slot cannot masquerade as complete copied destination media');
            $destination=$product($chainServerStep['target_product']);$destinationMedia=$media($chainServerStep['target_product']);$chainNoopReceipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$chainNoop);
            verify($chainNoopReceipt['result']['after']['steps'][0]['mode']==='price'&&$chainNoopReceipt['result']['after']['steps'][0]['new_media']===null
                &&$product($chainServerStep['target_product'])===$destination&&$media($chainServerStep['target_product'])===$destinationMedia
                &&file_get_contents($serverPath)===$pngA&&$chainNoopReceipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$chainNoop),
                'the actual typed destination consumer reuses its mapped price-only row/image without rewriting immutable files or allocating another destination');
            $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$chainNoopCommand,$chainNoopReceipt);
        }finally{foreach([false,true] as $local){$switch($local);$restore('users',$scopeRows[$local]['actor']);DB::table($local?'desktop_dashboard_local_state':'desktop_dashboard_devices')->where($local?'device_id':'id',$local?$ownerDevice:$remoteDevice->id)->update(['branches'=>$scopeRows[$local]['branches']]);}}
        verify(get_class(app(\Spatie\MediaLibrary\MediaCollections\Filesystem::class))===\Spatie\MediaLibrary\MediaCollections\Filesystem::class,
            'failed and completed batch guards restore the original Spatie filesystem binding for following CRUD operations');
        $completed=true;
    }finally{
        if(is_resource($web)){fclose($pipes[0]);proc_terminate($web);proc_close($web);}
        foreach([false,true] as $local){$switch($local);$ids=[...$sourceIds,...$created[$local]];DB::table('media')->whereIn('id',[...$fixtureMedia,...$createdMedia[$local]])->delete();DB::table('resturant_products')->whereIn('id',$ids)->delete();DB::table('resturants')->where('id',$grandchild)->delete();DB::table('products')->whereIn('id',$catalogIds)->delete();
            $restore('users',$old[$local]['actor']);$restore('resturants',$old[$local]['target']);$restore('resturant_products',$old[$local]['product']);
            if($commands)DB::table('desktop_dashboard_commands')->whereIn('command_id',$commands)->delete();
            DB::table('desktop_dashboard_entities')->whereIn('device_id',[$ownerDevice,$remoteDevice->id])->delete();if($entityBaseline[$local])DB::table('desktop_dashboard_entities')->insert($entityBaseline[$local]);}
        foreach($paths as $path=>$bytes)if(is_file($path))unlink($path);
        if(is_dir($imagesRoot))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($imagesRoot,FilesystemIterator::SKIP_DOTS)) as $file)
            if($file->isFile()&&!isset($filesBefore[$file->getPathname()]))unlink($file->getPathname());
        $switch(false);
    }
    if($completed){foreach([false,true] as $local){$switch($local);verify(DB::table('desktop_dashboard_entities')->whereIn('device_id',[$ownerDevice,$remoteDevice->id])->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$entityBaseline[$local]
        &&!DB::table('desktop_dashboard_commands')->whereIn('command_id',$commands)->exists()&&!DB::table('resturants')->where('id',$grandchild)->exists()
        &&(array)DB::table('users')->where('id',1)->first()===$old[$local]['actor']&&$fingerprint()===$originalBusiness[$local]
        &&DB::table($local?'desktop_dashboard_local_state':'desktop_dashboard_devices')->where($local?'device_id':'id',$local?$ownerDevice:$remoteDevice->id)->value('branches')===$scopeRows[$local]['branches'],
        'the '.($local?'local':'server').' copy fixture restores all106 business tables, exact original entity mappings/grants and owned temporary commands/branch');}
        $switch(false);echo 'RESTAURANT_MENU_COPY_LOCAL_COMPLETE '.json_encode(['checks'=>$GLOBALS['count']-$startCount,'schemaInventory'=>106,'productColumns'=>13,'mediaColumns'=>18,
            'originalMultipart'=>true,'originalController'=>true,'fullImagesCopied'=>true,'orderedDuplicates'=>true,'rollbackAtomic'=>true,'sourceImagesPreserved'=>true,
            'mappedServerIdentities'=>true,'repeatedPriceClock'=>true,'historicalNoopOccurrence'=>true,'typedProducerConsumerChain'=>true,'sourceAndTargetRevocation'=>true,'fullDashboard'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;}
})();
