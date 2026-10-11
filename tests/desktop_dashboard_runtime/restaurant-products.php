<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardRestaurantProducts};

// Actual original GET controllers, complete deployed row shapes, and independent local/server databases.
(function() use($database,$ownerStage,$ownerDevice,$remoteDevice,$env,$application,$httpPort,$http,$profile,$origin){
    $switch=function(string $db,bool $local=false){config(['database.connections.mysql.database'=>$db,'desktop_dashboard.local'=>$local]);DB::purge();};
    $row=fn()=>(array)DB::table('resturant_products')->where('id',1)->first();
    $restore=function(string $table,array $state){$id=$state['id'];unset($state['id']);DB::table($table)->where('id',$id)->update($state);};
    $related=function(){ $data=[];foreach(['users','orders','carts','wallets','resturants','categories','products','product_features','media','reviews','wishlists'] as $table)
        $data[$table]=DB::table($table)->orderBy('id')->get()->map(fn($item)=>(array)$item)->all();return $data;};
    $saved=function(string $uuid){$entry=DB::table('desktop_dashboard_commands')->where('command_id',$uuid)->first();$result=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
        return ['command_id'=>$uuid,'actor_id'=>1,'route_name'=>$entry->route_name,'payload'=>json_decode(Crypt::decryptString($entry->command_cipher),true,512,JSON_THROW_ON_ERROR),
            'local_result'=>$result['result'],'local_references'=>$result['references'],'dependencies'=>json_decode($entry->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($entry->created_at,'UTC')->toIso8601String()];};
    $reject=function(callable $work,int $status,string $message){try{$work();throw new RuntimeException('Restaurant product unexpectedly succeeded.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$status,$message);}
        catch(\Illuminate\Validation\ValidationException $error){verify($status===422,$message);}};
    $switch($database);$serverBefore=$row();$serverCategory=(array)DB::table('categories')->where('id',1)->first();$serverCatalog=(array)DB::table('products')->where('id',1)->first();
    $baseline=$serverBefore;$baseline['status']='show';$baseline['highest_rated']='no';$baseline['created_at']='2019-01-01 00:00:00';$baseline['updated_at']='2020-01-01 00:00:00';$restore('resturant_products',$baseline);
    $fixtureMedia=88542;$mappedProduct=97731;$mappedMedia=88543;$mapCommand=null;
    $media=['id'=>$fixtureMedia,'model_type'=>\App\Models\ResturantProduct::class,'model_id'=>1,'uuid'=>null,'collection_name'=>'product_image','name'=>'unchanged image',
        'file_name'=>'unchanged.png','mime_type'=>'image/png','disk'=>'products','conversions_disk'=>'products','size'=>68,'manipulations'=>'[]','custom_properties'=>'{}',
        'generated_conversions'=>'{}','responsive_images'=>'{}','order_column'=>1,'created_at'=>'2019-01-01 00:00:00','updated_at'=>'2019-01-01 00:00:00'];
    $mediaFile=$profile.'/app/public/products/'.$fixtureMedia.'/unchanged.png';mkdir(dirname($mediaFile),0700,true);
    $imageBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);
    file_put_contents($mediaFile,$imageBytes);$media['size']=strlen($imageBytes);$mediaDigest=hash_file('sha256',$mediaFile);
    DB::table('media')->insert($media);$serverRelated=$related();
    $switch($ownerStage,true);$localBefore=$row();$localCategory=(array)DB::table('categories')->where('id',1)->first();$localCatalog=(array)DB::table('products')->where('id',1)->first();
    $localFeatures=DB::table('product_features')->where('product_id',1)->get()->map(fn($part)=>(array)$part)->all();
    $restore('resturant_products',$baseline);$restore('categories',$serverCategory);$restore('products',$serverCatalog);DB::table('product_features')->where('product_id',1)->delete();
    $switch($database);$serverFeatures=DB::table('product_features')->where('product_id',1)->get()->map(fn($part)=>(array)$part)->all();
    $switch($ownerStage,true);if($serverFeatures)DB::table('product_features')->insert($serverFeatures);DB::table('media')->insert($media);$localRelated=$related();
    $localScope=(array)DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->first();
    $localEnv=$env;$localEnv['DB_DATABASE']=$ownerStage;$localEnv['DESKTOP_DASHBOARD_LOCAL']='true';$web=null;$pipes=[];
    $returnQuery='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';
    $get=function(string $suffix,array $query,array $headers=[],string $method='GET')use($http,$origin,$returnQuery){return $http('/admin/resturant_products/1/update/'.$suffix.'?'.http_build_query($query),null,
        ['Referer: '.$origin.'/admin/resturants/100?'.$returnQuery,...$headers],$method);};
    try{
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/restaurant-products.log','a'],['file',$profile.'/restaurant-products.log','a']],$pipes,$application,$localEnv);
        for($n=0;$n<100;$n++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
        verify($http('/admin/notifications')[0]===200,'the original local owner session supplies product link authentication');
        $command=(string)Str::uuid();$query=['status'=>'hide','_desktop_command'=>$command];$countBefore=DB::table('desktop_dashboard_commands')->count();
        [$status,$body,$headers]=$get('status',$query);$firstAfter=$row();
        verify($status===302&&in_array('Location: '.$origin.'/admin/resturants/100?'.$returnQuery,$headers,true)&&$firstAfter['status']==='hide'&&$firstAfter['highest_rated']==='no'
            &&DB::table('desktop_dashboard_commands')->count()===$countBefore+1,'the original status GET writes its enum and journals one immutable operation with its branch redirect');
        $first=$saved($command);verify(count($first['payload']['facts']['restaurant_product_before']['row'])===13
            &&$first['local_result']['after']['row']===$firstAfter&&$first['local_result']['after']['media'][0]===$media
            &&$first['local_references']===['menu_restaurant'=>100,'menu_restaurant_product'=>1],'product toggle facts retain all13 columns, original media row, and typed product/branch identity');
        verify($first['payload']['facts']['restaurant_product_return']['query']===$returnQuery,'the original search/page/parent restaurant context is saved with its exact encoded query');
        verify($get('status',$query)[1]===$body&&$row()===$firstAfter&&DB::table('desktop_dashboard_commands')->count()===$countBefore+1,'a lost local product redirect reuses its exact receipt without another timestamp update');
        $bad=$query;$bad['status']='show';verify($get('status',$bad,['Accept: application/json'])[0]===409&&$row()===$firstAfter,'one product UUID cannot be reused with changed status');
        verify($get('highest_rated',['highest_rated'=>'yes','_desktop_command'=>$command],['Accept: application/json'])[0]===409&&$row()===$firstAfter,'two product link actions cannot share the same logical UUID');
        foreach([['_desktop_command'=>[$command],'status'=>'show'],['_desktop_command'=>(string)Str::uuid(),'status'=>['show']],['status'=>'show']] as $bad)
            verify($get('status',$bad,['Accept: application/json'])[0]===422&&$row()===$firstAfter,'malformed/missing product UUID or enum arrays create no mutation');
        verify($get('status',$query,['Accept: application/json','X-Fasakhansta-Command: '.Str::uuid()])[0]===409&&$row()===$firstAfter,'conflicting product header/query UUIDs cannot obtain a saved reply');
        verify($get('status',$query,['Accept: application/json'],'HEAD')[0]===405&&$row()===$firstAfter,'a HEAD probe cannot execute or replay a product write');
        foreach(['status'=>'disabled','owner_resturant_id'=>101] as $field=>$value){$old=DB::table('users')->where('id',1)->value($field);DB::table('users')->where('id',1)->update([$field=>$value]);
            try{verify($get('status',$query,['Accept: application/json'])[0]===403,'saved local product replies require current actor '.$field);}finally{DB::table('users')->where('id',1)->update([$field=>$old]);}}
        DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode(['f:101'])]);
        try{verify($get('status',$query,['Accept: application/json'])[0]===403,'a removed enrolled branch cannot obtain a saved local product reply');}
        finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScope['branches']]);}
        $secondCommand=(string)Str::uuid();verify($get('highest_rated',['highest_rated'=>'yes','_desktop_command'=>$secondCommand])[0]===302&&$row()['highest_rated']==='yes','the original highest rated GET runs its unchanged controller');$second=$saved($secondCommand);
        $thirdCommand=(string)Str::uuid();verify($get('status',['_desktop_command'=>$thirdCommand])[0]===302&&$row()['status']===null,'an absent original status query retains nullable enum semantics');$third=$saved($thirdCommand);
        $conflictCommand=(string)Str::uuid();verify($get('status',['status'=>'show','_desktop_command'=>$conflictCommand])[0]===302,'a complete product conflict fixture is created by the original controller');$conflict=$saved($conflictCommand);
        $rollbackCommand=(string)Str::uuid();$prior=$row();$journalBefore=DB::table('desktop_dashboard_commands')->count();
        DB::unprepared("CREATE TRIGGER reject_product_toggle_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture product toggle rollback'");
        try{verify($get('status',['status'=>'hide','_desktop_command'=>$rollbackCommand],['Accept: application/json'])[0]===500&&$row()===$prior&&DB::table('desktop_dashboard_commands')->count()===$journalBefore,'a failed product journal save rolls back its original enum and timestamp');}
        finally{DB::unprepared('DROP TRIGGER reject_product_toggle_journal');}
        verify($related()===$localRelated&&hash_file('sha256',$mediaFile)===$mediaDigest,'local product links preserve catalog, actual image bytes, branch, order and financial rows');
        fclose($pipes[0]);proc_terminate($web);proc_close($web);$web=null;
        $switch($database);$actor=\App\Models\User::withoutGlobalScopes()->findOrFail(1);
        foreach([$first,$second,$third] as $index=>$operation){$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation);
            verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation)&&$receipt['result']['http']['location']==='/admin/resturants/100?'.$returnQuery,'real product link replay returns one retained server receipt with original search context '.$index);
            $switch($ownerStage,true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$operation['command_id'],$receipt);$switch($database);}
        verify($row()['status']===null&&$row()['highest_rated']==='yes'&&$related()===$serverRelated&&hash_file('sha256',$mediaFile)===$mediaDigest,'the product chain reconciles only its two enums and preserves all original relations and actual image bytes');
        $beforeConflict=$row();
        foreach(['created_at'=>'2019-01-02 00:00:00','product_price'=>'222.22','product_name'=>'changed server product','price'=>'{"extra_combo":99}'] as $field=>$value){DB::table('resturant_products')->where('id',1)->update([$field=>$value]);
            $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$conflict),409,'changed complete product before-state conflicts on '.$field);$restore('resturant_products',$beforeConflict);}
        foreach([['categories','name_ar','changed category'],['products','name_ar','changed catalog'],['media','name','changed image']] as [$table,$field,$value]){
            $id=$table==='media'?$fixtureMedia:1;$old=DB::table($table)->where('id',$id)->value($field);DB::table($table)->where('id',$id)->update([$field=>$value]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$conflict),409,'changed product relation conflicts before replay: '.$table);}
            finally{DB::table($table)->where('id',$id)->update([$field=>$old]);}}
        $tampered=$conflict;$tampered['local_result']['after']['row']['highest_rated']='no';
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'a fabricated unrelated product after-field rolls back reconciliation');
        verify($row()===$beforeConflict&&!DB::table('desktop_dashboard_commands')->where('command_id',$conflictCommand)->exists(),'product after disagreement creates no mutation or success receipt');
        $tampered=$conflict;$tampered['local_references']['menu_restaurant_product']=97739;
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'a fabricated local product output integer cannot install an arbitrary dependency mapping');
        $tampered=$conflict;$tampered['local_result']['references']['menu_restaurant_product']=97739;
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'declared local product outputs must match the actual local after identity');
        $bad=$conflict;$bad['payload']['facts']['restaurant_product_before']['features']='broken';$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),422,'malformed product relation arrays give controlled422');
        $bad=$conflict;$bad['payload']['parameters']['id']=['$desktop_ref'=>['entity'=>'catalog_product','command_id'=>$first['command_id'],'local_id'=>1]];
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),422,'a catalog product reference cannot identify a restaurant product');
        foreach(['status'=>'disabled','owner_resturant_id'=>101] as $field=>$value){$old=DB::table('users')->where('id',1)->value($field);DB::table('users')->where('id',1)->update([$field=>$value]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$first),403,'cached server product receipts require current actor '.$field);}finally{DB::table('users')->where('id',1)->update([$field=>$old]);}}
        $vendor=\App\Models\User::withoutGlobalScopes()->findOrFail(10);$scope=(object)['actor_id'=>10,'branches'=>json_encode(['f:100']),'enabled'=>true];
        $switch($ownerStage,true);$firstFacts=app(DesktopDashboardJournal::class)->savedFacts($ownerDevice,$command,1,$first['route_name']);$switch($database);
        $untyped=$first['payload'];$untyped['parameters']=['id'=>1];$untyped['facts']=$firstFacts;
        verify(app(DesktopDashboardRestaurantProducts::class)->authorize($first['route_name'],$untyped,$vendor,$scope)->id===10,'the original vendor branch scope adds no resource edit permission to its product link');
        $oldOwner=DB::table('resturants')->where('id',100)->value('user_id');DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
        try{$reject(fn()=>app(DesktopDashboardRestaurantProducts::class)->authorize($first['route_name'],$untyped,$vendor,$scope),403,'a current branch ownership transfer revokes saved vendor product authority');}
        finally{DB::table('resturants')->where('id',100)->update(['user_id'=>$oldOwner]);}
        // The same local product/media integers may belong to different remote rows. Resolve explicit typed slots only.
        $alias=$baseline;$alias['id']=$mappedProduct;DB::table('resturant_products')->insert($alias);$aliasMedia=$media;$aliasMedia['id']=$mappedMedia;$aliasMedia['model_id']=$mappedProduct;DB::table('media')->insert($aliasMedia);
        $mapCommand=(string)Str::uuid();$mapReceipt=['device_id'=>$remoteDevice->id,'command_id'=>$mapCommand,'committed'=>true,'references'=>[
            ['entity'=>'menu_restaurant_product','local_id'=>1,'server_id'=>$mappedProduct],['entity'=>'menu_product_media.0','local_id'=>$fixtureMedia,'server_id'=>$mappedMedia]]];
        DB::table('desktop_dashboard_commands')->insert(['device_id'=>$remoteDevice->id,'command_id'=>$mapCommand,'actor_id'=>1,'route_name'=>'fixture.typed-product-map','request_hash'=>str_repeat('a',64),
            'command_cipher'=>Crypt::encryptString('{}'),'local_result_cipher'=>Crypt::encryptString('{}'),'server_result_cipher'=>Crypt::encryptString(json_encode($mapReceipt)),
            'dependencies'=>'[]','status'=>'acknowledged','attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $typed=$first;$typed['command_id']=(string)Str::uuid();$untyped['values']['idempotency_key']=$typed['command_id'];
        $typed['payload']=app(DesktopDashboardRestaurantProducts::class)->inputs($untyped,function(string $entity,$id)use($mapCommand,$fixtureMedia){
            if($entity==='menu_restaurant_product'||$entity==='menu_product_media'&&$id===$fixtureMedia)return ['$desktop_ref'=>['entity'=>$entity==='menu_product_media'?'menu_product_media.0':$entity,'command_id'=>$mapCommand,'local_id'=>$id]];return $id;});
        $typed['dependencies']=[$mapCommand];$unrelated=$row();$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$typed);
        verify(DB::table('resturant_products')->where('id',$mappedProduct)->value('status')==='hide'&&$row()===$unrelated
            &&collect($receipt['references'])->firstWhere('entity','menu_restaurant_product')['server_id']===$mappedProduct,'actual replay resolves typed product/media ID drift and leaves the unrelated remote integer untouched');
        echo 'Restaurant product original GET acceptance completed'.PHP_EOL;
    }finally{
        if(is_resource($web)){fclose($pipes[0]);proc_terminate($web);proc_close($web);}
        $switch($database);DB::table('media')->whereIn('id',[$fixtureMedia,$mappedMedia])->delete();DB::table('resturant_products')->where('id',$mappedProduct)->delete();if($mapCommand)DB::table('desktop_dashboard_commands')->where('command_id',$mapCommand)->delete();$restore('resturant_products',$serverBefore);
        $switch($ownerStage,true);DB::table('media')->where('id',$fixtureMedia)->delete();$restore('resturant_products',$localBefore);$restore('categories',$localCategory);$restore('products',$localCatalog);
        DB::table('product_features')->where('product_id',1)->delete();if($localFeatures)DB::table('product_features')->insert($localFeatures);
        if(is_file($mediaFile))unlink($mediaFile);if(is_dir(dirname($mediaFile)))rmdir(dirname($mediaFile));
        $switch($database);
    }
})();
