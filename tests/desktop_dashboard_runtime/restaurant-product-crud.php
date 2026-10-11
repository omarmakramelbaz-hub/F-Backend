<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences,DesktopDashboardRestaurantProductCrud};

// Included after the actual106-schema owner fixture. No substitute controller, repository or media library.
(function()use($database,$ownerStage,$ownerDevice,$remoteDevice,$env,$application,$httpPort,$profile,$origin,&$cookies,$report){
    $switch=function(bool $local){config(['database.connections.mysql.database'=>$local?$ownerStage:$database,'desktop_dashboard.local'=>$local,'desktop_dashboard.device_id'=>$ownerDevice]);DB::purge();};
    $rows=fn(string $table)=>DB::table($table)->get()->map(fn($row)=>(array)$row)->all();
    $product=fn(int $id)=>(array)DB::table('resturant_products')->where('id',$id)->first();
    $media=fn(int $id)=>DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->where('model_id',$id)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $restore=function(string $table,array $row){$id=$row['id'];unset($row['id']);DB::table($table)->where('id',$id)->update($row);};
    $reject=function(callable $work,int $expected,string $message){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$expected,$message);return;}
        catch(\Illuminate\Validation\ValidationException $error){verify($expected===422,$message);return;}throw new RuntimeException('Restaurant product CRUD was not rejected: '.$message);};
    $saved=function(string $id){$entry=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();if(!$entry)throw new RuntimeException('Original CRUD did not save its command.');
        $result=json_decode(Crypt::decryptString($entry->local_result_cipher),true,512,JSON_THROW_ON_ERROR);
        return ['command_id'=>$id,'actor_id'=>(int)$entry->actor_id,'route_name'=>$entry->route_name,'payload'=>json_decode(Crypt::decryptString($entry->command_cipher),true,512,JSON_THROW_ON_ERROR),
            'local_result'=>$result['result'],'local_references'=>$result['references'],'dependencies'=>json_decode($entry->dependencies,true,512,JSON_THROW_ON_ERROR),
            'occurred_at'=>\Carbon\Carbon::parse($entry->created_at,'UTC')->toIso8601String()];};
    $fingerprint=function(array $allowedProducts=[],array $allowedMedia=[])use($report){$inventory=[];
        foreach(array_keys($report['tables']) as $table){$parts=[];foreach(DB::table($table)->get() as $raw){$row=(array)$raw;
            if($table==='resturant_products'&&in_array((int)$row['id'],$allowedProducts,true))continue;
            if($table==='media'&&in_array((int)$row['id'],$allowedMedia,true))continue;
            ksort($row);$parts[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
            sort($parts);$inventory[$table]=['rows'=>count($parts),'sha256'=>hash('sha256',implode("\n",$parts))];}ksort($inventory);return $inventory;};
    $readMedia=function(array $row){$original=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($row['id']);$path=$original->getPath();
        if(!is_file($path))throw new RuntimeException('Original product image is unavailable: '.$path);return ['path'=>$path,'bytes'=>file_get_contents($path),'sha256'=>hash_file('sha256',$path)];};
    $returnQuery='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';
    $request=function(string $path,array $form,array $files=[],array $headers=[])use($origin,$env,$returnQuery,&$cookies){
        $boundary='restaurant-product-crud-'.bin2hex(random_bytes(12));$body='';
        foreach($form as $name=>$value){foreach(is_array($value)?$value:[$value] as $part){$field=$name.(is_array($value)?'[]':'');
            $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$field."\"\r\n\r\n".$part."\r\n";}}
        foreach($files as $name=>$file)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name.'"; filename="'.$file['name']."\"\r\nContent-Type: ".$file['mime']."\r\n\r\n".$file['bytes']."\r\n";
        $body.='--'.$boundary."--\r\n";$headers=['X-Fasakhansta-Desktop: '.$env['DESKTOP_DASHBOARD_TOKEN'],'Referer: '.$origin.'/admin/resturants/100?'.$returnQuery,
            'Content-Type: multipart/form-data; boundary='.$boundary,...$headers];
        if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]);
        $reply=@file_get_contents($origin.$path,false,$context);$replyHeaders=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$replyHeaders[0]??'',$status);
        foreach($replyHeaders as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];
        return [(int)($status[1]??0),$reply,$replyHeaders];
    };
    $imageA=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=',true);
    $imageB=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);
    $uploadA=['name'=>'رنجة محلية.png','mime'=>'image/png','bytes'=>$imageA];$uploadB=['name'=>'صورة بديلة.png','mime'=>'image/png','bytes'=>$imageB];
    $base=['added_by'=>'1','resturant_id'=>'100','category_id'=>'1','product_id'=>'1','product_name'=>'رنجة منتج محلي','product_description'=>'وصف المنتج الأصلي','product_price'=>'123.00',
        'extra_combo'=>'1','extra_large'=>'2','extra_medium'=>'3','extra_clean'=>'4','extra_clear'=>'5','extra_vacuim'=>'6'];
    $originalBindings=[\App\Http\Controllers\Dashboard\ResturantController::class=>'app/Http/Controllers/Dashboard/ResturantController.php',
        \App\Repositories\ResturantProductRepository::class=>'app/Repositories/ResturantProductRepository.php',
        \App\Http\Requests\Dashboard\Resturant\ResturantProductRequest::class=>'app/Http/Requests/Dashboard/Resturant/ResturantProductRequest.php',
        \App\Models\ResturantProduct::class=>'app/Models/ResturantProduct.php',
        \Spatie\MediaLibrary\MediaCollections\Models\Media::class=>'vendor/spatie/laravel-medialibrary/src/MediaCollections/Models/Media.php'];
    foreach($originalBindings as $class=>$file)verify(realpath((new ReflectionClass($class))->getFileName())===realpath($application.'/'.$file),'restaurant CRUD uses the actual packaged original class '.$class);
    $serverSaved=[];$localSaved=[];$fixtureMediaIds=[88710,88711];$createdLocal=[];$createdServer=[];$commands=[];$mappingCommands=[];$paths=[];$web=null;$pipes=[];
    foreach([false,true] as $local){$switch($local);$snapshot=['product'=>$product(1),'media'=>$media(1),'category'=>(array)DB::table('categories')->where('id',1)->first(),
        'catalog'=>(array)DB::table('products')->where('id',1)->first(),'features'=>DB::table('product_features')->where('product_id',1)->get()->map(fn($part)=>(array)$part)->all()];
        if($local)$localSaved=$snapshot;else $serverSaved=$snapshot;}
    $baseline=$serverSaved['product'];$baseline['created_at']='2019-01-01 00:00:00';$baseline['updated_at']='2020-01-01 00:00:00';
    $baseline['price']=json_encode(array_fill_keys(['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],0));
    $originalMedia=['model_type'=>\App\Models\ResturantProduct::class,'model_id'=>1,'uuid'=>null,'name'=>'pre-existing Arabic PNG','mime_type'=>'image/png','disk'=>'products','conversions_disk'=>'products',
        'size'=>strlen($imageA),'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}','order_column'=>1,'created_at'=>'2019-01-01 00:00:00','updated_at'=>'2019-01-01 00:00:00'];
    foreach([false,true] as $local){$switch($local);$restore('resturant_products',$baseline);
        foreach([['id'=>88710,'collection_name'=>'product_image','file_name'=>'اصلية.png'],['id'=>88711,'collection_name'=>'untouched-collection','file_name'=>'خارج النطاق.png']] as $part){
            $row=$part+$originalMedia;DB::table('media')->insert($row);$path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($row['id'])->getPath();
            if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$imageA);$paths[$path]=$imageA;}}
    $switch(true);$restore('categories',$serverSaved['category']);$restore('products',$serverSaved['catalog']);DB::table('product_features')->where('product_id',1)->delete();
    if($serverSaved['features'])DB::table('product_features')->insert($serverSaved['features']);
    $entityBaseline=DB::table('desktop_dashboard_entities')->where('device_id',$ownerDevice)->get()->map(fn($part)=>(array)$part)->all();
    DB::table('desktop_dashboard_entities')->where('device_id',$ownerDevice)->delete();
    $localScope=(array)DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->first();
    $localBusiness=$fingerprint([1],[88710]);$beforeOriginalFile=$readMedia($media(1)[0]);$untouchedFile=$readMedia($media(1)[1]);
    $localEnv=$env;$localEnv['DB_DATABASE']=$ownerStage;$localEnv['DESKTOP_DASHBOARD_LOCAL']='true';
    try{
        $web=proc_open([PHP_BINARY,'-d','upload_max_filesize=6M','-d','post_max_size=16M','-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],
            [['pipe','r'],['file',$profile.'/restaurant-product-crud.log','a'],['file',$profile.'/restaurant-product-crud.log','a']],$pipes,$application,$localEnv);
        // A genuine original session must provide CSRF; no direct controller request substitutes for this boundary.
        $get=function(string $path)use($origin,$env,&$cookies){$headers=['X-Fasakhansta-Desktop: '.$env['DESKTOP_DASHBOARD_TOKEN']];if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
            $body=@file_get_contents($origin.$path,false,stream_context_create(['http'=>['header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]));
            $headers=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$headers[0]??'',$status);return [(int)($status[1]??0),$body];};
        for($n=0;$n<100;$n++){if($get('/_desktop/health')[0]===200)break;usleep(50000);}
        [$status,$page]=$get('/admin/notifications');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$csrf);
        verify($status===200&&!empty($csrf[1]),'the original owner login supplies actual restaurant CRUD CSRF');$base['_token']=$csrf[1];
        $form=$base;$form['_desktop_command']=(string)Str::uuid();$commands[]=$form['_desktop_command'];$beforeCount=DB::table('resturant_products')->count();
        [$status,$reply,$headers]=$request('/admin/resturant_products',$form,['product_image'=>$uploadA]);
        $created=(array)DB::table('resturant_products')->where('product_name',$form['product_name'])->first();$createdLocal[]=(int)($created['id']??0);
        verify($status===302&&count($created)===13&&DB::table('resturant_products')->count()===$beforeCount+1
            &&in_array('Location: '.$origin.'/admin/resturants/100?'.$returnQuery,$headers,true),'the original multipart restaurant controller creates all13 deployed columns with its unchanged branch redirect');
        verify(json_decode($created['price'],true)===array_combine(['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],['1','2','3','4','5','6'])
            &&$created['status']===null&&$created['highest_rated']===null,'original creation retains all six addon slots and deployed nullable enum defaults');
        $createdMedia=$media($created['id']);verify(count($createdMedia)===1&&$createdMedia[0]['collection_name']==='product_image'&&$createdMedia[0]['disk']==='products'
            &&$readMedia($createdMedia[0])['bytes']===$imageA,'the original Spatie product-image upload stores exact PNG bytes under its original disk and collection');
        $paths[$readMedia($createdMedia[0])['path']]=$imageA;$first=$saved($form['_desktop_command']);
        $sourceTime=\Carbon\Carbon::parse($created['created_at'],config('app.timezone'));$journalTime=\Carbon\Carbon::parse($first['occurred_at'],'UTC');
        verify(config('app.timezone')==='Africa/Cairo'&&abs($sourceTime->getTimestamp()-$journalTime->getTimestamp())<=5
            &&$sourceTime->getOffset()!==0&&abs(\Carbon\Carbon::parse($created['created_at'],'UTC')->getTimestamp()-$journalTime->getTimestamp())>3600,
            'the original product/media DATETIME is Cairo local time while the actual journal occurrence is UTC');
        verify($request('/admin/resturant_products',$form,['product_image'=>$uploadA])[1]===$reply&&$product($created['id'])===$created
            &&DB::table('resturant_products')->count()===$beforeCount+1,'losing an original create redirect reuses one exact result without another product or timestamp write');
        $changed=$form;$changed['product_name'].=' مختلف';verify($request('/admin/resturant_products',$changed,['product_image'=>$uploadA],['Accept: application/json'])[0]===409,'one create UUID cannot be reused with changed original fields');
        verify($request('/admin/resturant_products',$form,['product_image'=>$uploadB],['Accept: application/json'])[0]===409,'one create UUID cannot be reused with changed PNG bytes or filename');
        $createdPath=$readMedia($createdMedia[0])['path'];unlink($createdPath);
        try{verify($request('/admin/resturant_products',$form,['product_image'=>$uploadA],['Accept: application/json'])[0]===503,'a saved create reply cannot report success while its complete image bytes are missing');}finally{file_put_contents($createdPath,$imageA);}
        foreach(['status'=>'disabled','owner_resturant_id'=>101] as $field=>$value){$old=DB::table('users')->where('id',1)->value($field);DB::table('users')->where('id',1)->update([$field=>$value]);
            try{verify($request('/admin/resturant_products',$form,['product_image'=>$uploadA],['Accept: application/json'])[0]===403,'cached local CRUD replies check the current actor '.$field);}finally{DB::table('users')->where('id',1)->update([$field=>$old]);}}
        DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode(['f:101'])]);
        try{verify($request('/admin/resturant_products',$form,['product_image'=>$uploadA],['Accept: application/json'])[0]===403,'a removed enrolled branch cannot obtain a saved product create reply');}
        finally{DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScope['branches']]);}
        verify($first['payload']['facts']['restaurant_product_crud_before']['product']===null
            &&count($first['local_result']['after']['product']['row'])===13&&count($first['local_result']['after']['media'][0])===18
            &&$first['local_references']['menu_restaurant_product']===$created['id']&&$first['dependencies']===[],
            'the actual encrypted create binds full original product/media shapes and newly captured typed identities');
        $update=$base;$update['id']=(string)$created['id'];$update['_method']='put';$update['_desktop_command']=(string)Str::uuid();$update['product_name']='رنجة معدلة دون صورة';$update['product_price']='234.00';$commands[]=$update['_desktop_command'];
        [$status,$updateReply]=$request('/admin/resturant_products/update',$update);$withoutImage=$product($created['id']);$second=$saved($update['_desktop_command']);
        verify($status===302&&$withoutImage['product_name']===$update['product_name']&&$withoutImage['product_price']==='234.00'&&$media($created['id'])===$createdMedia
            &&$readMedia($createdMedia[0])['bytes']===$imageA&&$second['local_result']['success']===trans('messages.AddSuccessfully'),
            'the original update without an image preserves its complete prior collection and bytes and original AddSuccessfully response');
        verify(($second['payload']['values']['id']['$desktop_ref']['entity']??null)==='menu_restaurant_product'
            &&($second['payload']['values']['id']['$desktop_ref']['command_id']??null)===$first['command_id']&&in_array($first['command_id'],$second['dependencies'],true),
            'a local create-to-update chain transmits the explicitly typed created restaurant product dependency');
        verify($request('/admin/resturant_products/update',$update)[1]===$updateReply&&$product($created['id'])===$withoutImage,
            'a lost no-image update reply does not change the original timestamp or produce another media row');
        $replace=$update;$replace['_desktop_command']=(string)Str::uuid();$replace['product_name']='رنجة معدلة مع صورة';$commands[]=$replace['_desktop_command'];
        $priorImages=$media($created['id']);$priorProduct=$product($created['id']);$priorPath=$readMedia($priorImages[0])['path'];$priorBytes=file_get_contents($priorPath);
        DB::unprepared("CREATE TRIGGER reject_restaurant_crud_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.command_id='".$replace['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture restaurant CRUD journal rollback'; END IF; END");
        try{verify($request('/admin/resturant_products/update',$replace,['product_image'=>$uploadB],['Accept: application/json'])[0]===500
            &&$product($created['id'])===$priorProduct&&$media($created['id'])===$priorImages&&is_file($priorPath)&&file_get_contents($priorPath)===$priorBytes
            &&!DB::table('desktop_dashboard_commands')->where('command_id',$replace['_desktop_command'])->exists(),
            'a real failed outer journal insert restores the original updated row and original media rows without deleting prior PNG bytes');}
        finally{DB::unprepared('DROP TRIGGER reject_restaurant_crud_journal');}
        [$status,$replaceReply]=$request('/admin/resturant_products/update',$replace,['product_image'=>$uploadB]);$replacement=$media($created['id']);$third=$saved($replace['_desktop_command']);
        verify($status===302&&count($replacement)===1&&$replacement[0]['id']!==$priorImages[0]['id']&&$readMedia($replacement[0])['bytes']===$imageB
            &&!DB::table('media')->where('id',$priorImages[0]['id'])->exists()&&is_file($priorPath)&&file_get_contents($priorPath)===$imageA,
            'original image replacement clears the old collection row, adds one exact new PNG, and retains immutable old bytes for uncertain transaction recovery');
        $paths[$readMedia($replacement[0])['path']]=$imageB;
        verify($request('/admin/resturant_products/update',$replace,['product_image'=>$uploadB])[1]===$replaceReply&&$media($created['id'])===$replacement,
            'retrying a committed image replacement returns its original result without rewriting bytes or allocating another media ID');
        $delete=['_token'=>$csrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];$commands[]=$delete['_desktop_command'];$deletePath='/admin/resturant_products/'.$created['id'];
        [$status,$deleteReply]=$request($deletePath,$delete);$fourth=$saved($delete['_desktop_command']);
        verify($status===302&&!DB::table('resturant_products')->where('id',$created['id'])->exists()&&$media($created['id'])===$replacement
            &&$readMedia($replacement[0])['bytes']===$imageB&&$fourth['local_result']['after']['product']===null,
            'the exact original query deletion removes the product while preserving its original orphan media rows and complete PNG bytes');
        verify($request($deletePath,$delete)[1]===$deleteReply&&$media($created['id'])===$replacement,
            'a lost original delete redirect replays before binding the already removed product and preserves its orphan collection');
        // An already imported product proves that complete original images outside replacement remain untouched.
        $editExisting=$base;$editExisting['id']='1';$editExisting['_method']='put';$editExisting['_desktop_command']=(string)Str::uuid();$editExisting['product_name']='صنف قديم معدل محليا';$commands[]=$editExisting['_desktop_command'];
        verify($request('/admin/resturant_products/update',$editExisting)[0]===302&&is_file($beforeOriginalFile['path'])&&hash_file('sha256',$beforeOriginalFile['path'])===$beforeOriginalFile['sha256']
            &&DB::table('media')->where('id',88710)->exists()&&$readMedia((array)DB::table('media')->where('id',88711)->first())===$untouchedFile,
            'updating a pre-existing product without upload preserves its original PNG and every unrelated media collection');
        $existing=$saved($editExisting['_desktop_command']);
        $deleteExisting=['_token'=>$csrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];$commands[]=$deleteExisting['_desktop_command'];
        $beforeRestricted=$product(1);verify($request('/admin/resturant_products/1',$deleteExisting,[],['Accept: application/json'])[0]>=400&&$product(1)===$beforeRestricted
            &&DB::table('carts')->where('resturant_product_id',1)->count()===1&&!DB::table('desktop_dashboard_commands')->where('command_id',$deleteExisting['_desktop_command'])->exists(),
            'the actual restrictive original cart foreign key prevents product deletion without losing its cart, product or image');
        DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>json_encode(['f:100'])]);
        foreach([['added_by'=>'999999999'],['resturant_id'=>'101'],['category_id'=>['1']],['product_price'=>['1']],['unreviewed_field'=>'x']] as $override){$bad=array_replace($base,$override);$bad['_desktop_command']=(string)Str::uuid();
            verify(in_array($request('/admin/resturant_products',$bad,['product_image'=>$uploadA],['Accept: application/json'])[0],[403,422],true),
                'malformed or unauthorized original CRUD inputs create no business effect: '.implode(',',array_keys($override)));}
        DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['branches'=>$localScope['branches']]);
        $bad=$base;$bad['_desktop_command']=(string)Str::uuid();verify($request('/admin/resturant_products',$bad,['unreviewed_file'=>$uploadA],['Accept: application/json'])[0]===422,
            'an extra uploaded field cannot be silently dropped from a reviewed product operation');
        $badFile=$uploadA;$badFile['bytes']='not a PNG';verify($request('/admin/resturant_products',$bad,['product_image'=>$badFile],['Accept: application/json'])[0]===422,
            'the actual image upload validates bytes rather than trusting an image/png declaration');
        $allowedLocalMedia=array_merge([88710],array_column($createdMedia,'id'),array_column($replacement,'id'));
        verify($fingerprint([1,$created['id']],$allowedLocalMedia)===$localBusiness&&hash_file('sha256',$untouchedFile['path'])===$untouchedFile['sha256'],
            'all106 original business tables outside the exact target product/collection remain byte-equivalent, including orders, wallets, catalog and unrelated media');
        fclose($pipes[0]);proc_terminate($web);proc_close($web);$web=null;
        $chain=[$first,$second,$third,$fourth];$switch(false);$serverBusiness=$fingerprint([1],[88710]);
        // Occupy the imported integers on the server: dependency receipts must identify the newly created row instead.
        $collision=$baseline;$collision['id']=$created['id'];$collision['product_name']='صنف سيرفر مستقل بنفس الرقم';DB::table('resturant_products')->insert($collision);$createdServer[]=$collision['id'];
        $collisionMedia=$createdMedia[0];$collisionMedia['model_id']=$collision['id'];$collisionMedia['uuid']=(string)Str::uuid();$collisionMedia['name']='independent server media';$collisionMedia['order_column']=1000000;
        DB::table('media')->insert($collisionMedia);$fixtureMediaIds[]=$collisionMedia['id'];
        verify((int)DB::table('media')->max('order_column')===1000000&&(int)$createdMedia[0]['order_column']<1000000,
            'the actual server global Spatie media counter differs from the enrolled local snapshot');
        $unrelatedCollision=$product($collision['id']);$unrelatedMedia=$media($collision['id']);$mappedId=null;$mappedMediaIds=[];
        foreach($chain as $index=>$operation){$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation);
            verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$operation),'the actual original CRUD reconciliation preserves one exact retained server receipt '.$index);
            if($index===0){$mappedId=(int)collect($receipt['references'])->firstWhere('entity','menu_restaurant_product')['server_id'];$createdServer[]=$mappedId;
                verify($mappedId!==$created['id'],'the real server create receipt exposes its differing generated product ID');}
            if(in_array($index,[0,2],true)){$sourceImage=$operation['local_result']['after']['media'][0];$serverImage=$media($mappedId)[0];
                verify($serverImage['order_column']===$sourceImage['order_column']&&$serverImage['created_at']===$sourceImage['created_at']&&$serverImage['updated_at']===$sourceImage['updated_at']
                    &&$product($mappedId)['updated_at']===$operation['local_result']['after']['product']['row']['updated_at']&&$media($collision['id'])===$unrelatedMedia,
                    'real original create/replacement replay preserves recorded source order and Cairo timestamps despite an unrelated high server media counter '.$index);}
            foreach($receipt['references'] as $reference)if(str_starts_with($reference['entity'],'menu_product_media.'))$mappedMediaIds[]=$reference['server_id'];
            verify($product($collision['id'])===$unrelatedCollision&&$media($collision['id'])===$unrelatedMedia,'typed CRUD dependencies never mutate unrelated server rows with colliding local integers '.$index);
            $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$operation['command_id'],$receipt);$switch(false);
        }
        verify($mappedId!==null&&!DB::table('resturant_products')->where('id',$mappedId)->exists()&&count($media($mappedId))===1&&$readMedia($media($mappedId)[0])['bytes']===$imageB,
            'actual create/update/image-replace/delete replay maps product and image ID drift and retains original deleted-product media');
        $fixtureMediaIds=array_merge($fixtureMediaIds,$mappedMediaIds);
        $serverPrior=$product(1);$beforeCommands=DB::table('desktop_dashboard_commands')->count();
        foreach(['created_at'=>'2019-01-02 00:00:00','product_name'=>'تغيير مستقل على السيرفر','product_description'=>'وصف مستقل','product_price'=>'222.22','price'=>'{"extra_combo":99}','highest_rated'=>'yes'] as $field=>$value){
            DB::table('resturant_products')->where('id',1)->update([$field=>$value]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$existing),409,'complete original CRUD before-state conflicts on independently changed '.$field);}
            finally{$restore('resturant_products',$serverPrior);}}
        foreach([['categories',1,'name_ar','قسم مستقل'],['products',1,'name_ar','صنف كتالوج مستقل'],['media',88710,'name','image independently changed']] as [$table,$id,$field,$value]){
            $old=DB::table($table)->where('id',$id)->value($field);DB::table($table)->where('id',$id)->update([$field=>$value]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$existing),409,'complete original CRUD relation conflict preserves server data: '.$table);}
            finally{DB::table($table)->where('id',$id)->update([$field=>$old]);}}
        $oldImage=file_get_contents($beforeOriginalFile['path']);file_put_contents($beforeOriginalFile['path'],$imageB);
        try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$existing),409,'an independently changed original PNG cannot reconcile under stale before-image facts');}
        finally{file_put_contents($beforeOriginalFile['path'],$oldImage);}
        $tampered=$existing;$tampered['local_result']['after']['product']['row']['highest_rated']='yes';
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'a fabricated unrelated CRUD after-field rolls back the real original repository update');
        verify($product(1)===$serverPrior&&DB::table('desktop_dashboard_commands')->count()===$beforeCommands&&hash_file('sha256',$beforeOriginalFile['path'])===$beforeOriginalFile['sha256'],
            'rejected complete before/after states produce no server success receipt, row mutation or image loss');
        $tampered=$existing;$tampered['local_references']['menu_restaurant_product']=999999;
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),409,'a forged local CRUD output integer cannot install an arbitrary product mapping');
        $tampered=$existing;$tampered['payload']['values']['id']=['$desktop_ref'=>['entity'=>'catalog_product','command_id'=>$first['command_id'],'local_id'=>1]];
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),422,'a catalog product typed slot cannot masquerade as a restaurant product identity');
        $tampered=$existing;$tampered['payload']['facts']['restaurant_product_crud_before']['images'][0]['files'][0]['relative']='../escape.png';
        $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$tampered),422,'a forged persisted image path cannot escape its exact original media identity');
        $images=app(\App\Services\Dashboard\DesktopDashboardProductImages::class);$wire=$first['payload']['files'];
        verify($images->decoded($wire)[1]===$imageA&&$wire['product_image']['name']===$uploadA['name'],'the encrypted image envelope retains the complete actual PNG and original Arabic basename');
        foreach(['../escape.png','C:\\escape.png','CON.png','escape.png.','image.svg'] as $name){$bad=$wire;$bad['product_image']['name']=$name;
            $reject(fn()=>$images->decoded($bad),422,'the wire image rejects unsafe or unsupported original basename '.$name);}
        foreach(['mime'=>'image/jpeg','sha256'=>str_repeat('0',64),'base64'=>'not-base64!'] as $field=>$value){$bad=$wire;$bad['product_image'][$field]=$value;
            $reject(fn()=>$images->decoded($bad),422,'the wire image independently validates its '.$field);}
        $bad=$wire;$bad['product_image']['path']='products/88710/اصلية.png';$reject(fn()=>$images->decoded($bad),422,'a caller cannot choose a persisted media path through an extra envelope key');
        $bad=$wire;$bad['other']=$bad['product_image'];$reject(fn()=>$images->decoded($bad),422,'a caller cannot add unreviewed upload fields to the encrypted image envelope');
        foreach(['status'=>'disabled','owner_resturant_id'=>101] as $field=>$value){$old=DB::table('users')->where('id',1)->value($field);DB::table('users')->where('id',1)->update([$field=>$value]);
            try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$first),403,'retained server CRUD receipts check current actor '.$field);}
            finally{DB::table('users')->where('id',1)->update([$field=>$old]);}}
        $deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:101'])]);
        try{$reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$first),403,'removed server enrollment refuses even an already committed original product create receipt');}
        finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
        DB::unprepared("CREATE TRIGGER reject_restaurant_crud_replay BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.command_id='".$existing['command_id']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture restaurant CRUD replay rollback'; END IF; END");
        try{try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$existing);throw new RuntimeException('Original CRUD replay journal failure was ignored.');}
            catch(\Illuminate\Database\QueryException $error){verify($product(1)===$serverPrior&&hash_file('sha256',$beforeOriginalFile['path'])===$beforeOriginalFile['sha256']
                &&!DB::table('desktop_dashboard_commands')->where('command_id',$existing['command_id'])->exists(),'a failed real server journal insert rolls back original CRUD row/media effects and keeps its immutable PNG');}}
        finally{DB::unprepared('DROP TRIGGER reject_restaurant_crud_replay');}
        $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$existing);verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$existing)
            &&$product(1)['product_name']===$editExisting['product_name']&&hash_file('sha256',$beforeOriginalFile['path'])===$beforeOriginalFile['sha256'],
            'the real imported-product update reconciles once and preserves its pre-existing PNG across retained receipt retry');
        $switch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$existing['command_id'],$receipt);$switch(false);
        $allowedServerMedia=array_merge([88710,$collisionMedia['id']],$mappedMediaIds);
        verify($fingerprint([1,$collision['id'],$mappedId],$allowedServerMedia)===$serverBusiness&&$readMedia((array)DB::table('media')->where('id',88711)->first())===$untouchedFile,
            'actual CRUD reconciliation preserves all106 business tables outside the complete authorized product/image effect scope');
        // One further actual original HTTP update includes a complete feature and two imported images.
        $featureId=55710;$driftAliases=['users'=>55705,'resturants'=>55704,'categories'=>55706,'products'=>55707,'product_features'=>55708,'resturant_products'=>55701];
        $feature=['id'=>$featureId,'added_by'=>1,'product_id'=>1,'name'=>'combo','created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-01 00:00:00'];
        DB::table('product_features')->insert($feature);$switch(true);DB::table('product_features')->insert($feature);
        $driftBefore=$product(1);$editDrift=$editExisting;$editDrift['_desktop_command']=(string)Str::uuid();$editDrift['product_name']='صنف روابط متعددة من النموذج';$commands[]=$editDrift['_desktop_command'];
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/restaurant-product-crud.log','a'],['file',$profile.'/restaurant-product-crud.log','a']],$pipes,$application,$localEnv);
        for($n=0;$n<100;$n++){if($get('/_desktop/health')[0]===200)break;usleep(50000);}
        verify($request('/admin/resturant_products/update',$editDrift)[0]===302,'the original no-image form generates complete relation facts for a real typed-ID drift acceptance');$drift=$saved($editDrift['_desktop_command']);
        fclose($pipes[0]);proc_terminate($web);proc_close($web);$web=null;$switch(false);
        $driftRaw=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$drift['payload']);
        $alias=(array)DB::table('users')->where('id',10)->first();$alias['id']=$driftAliases['users'];$alias['email']='crud-drift-owner@test.invalid';$alias['mobile']='crud-drift-owner';DB::table('users')->insert($alias);
        $alias=(array)DB::table('resturants')->where('id',100)->first();$alias['id']=$driftAliases['resturants'];$alias['user_id']=$driftAliases['users'];DB::table('resturants')->insert($alias);
        $alias=(array)DB::table('categories')->where('id',1)->first();$alias['id']=$driftAliases['categories'];DB::table('categories')->insert($alias);
        $alias=(array)DB::table('products')->where('id',1)->first();$alias['id']=$driftAliases['products'];$alias['category_id']=$driftAliases['categories'];DB::table('products')->insert($alias);
        $alias=$feature;$alias['id']=$driftAliases['product_features'];$alias['product_id']=$driftAliases['products'];DB::table('product_features')->insert($alias);
        $alias=$driftBefore;$alias['id']=$driftAliases['resturant_products'];$alias['resturant_id']=$driftAliases['resturants'];$alias['product_id']=$driftAliases['products'];$alias['category_id']=$driftAliases['categories'];DB::table('resturant_products')->insert($alias);$createdServer[]=$alias['id'];
        $mediaMap=[88710=>55703,88711=>55702];foreach($mediaMap as $old=>$new){$alias=(array)DB::table('media')->where('id',$old)->first();$alias['id']=$new;$alias['model_id']=$driftAliases['resturant_products'];DB::table('media')->insert($alias);$fixtureMediaIds[]=$new;
            $path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($new)->getPath();if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$imageA);$paths[$path]=$imageA;}
        $mapping=(string)Str::uuid();$mappingCommands[]=$mapping;$map=[
            'menu_restaurant_product'=>[1=>$driftAliases['resturant_products']],'menu_restaurant'=>[100=>$driftAliases['resturants']],
            'restaurant_flag_user'=>[10=>$driftAliases['users']],'catalog_category'=>[1=>$driftAliases['categories']],
            'catalog_product'=>[1=>$driftAliases['products']],'catalog_feature'=>[$featureId=>$driftAliases['product_features']],
            'menu_product_media.0'=>[88710=>55703],'menu_product_media.1'=>[88711=>55702]];
        $mappingReceipt=['device_id'=>$remoteDevice->id,'command_id'=>$mapping,'committed'=>true,'references'=>[]];
        foreach($map as $entity=>$parts)foreach($parts as $local=>$server)$mappingReceipt['references'][]=['entity'=>$entity,'local_id'=>$local,'server_id'=>$server];
        DB::table('desktop_dashboard_commands')->insert(['device_id'=>$remoteDevice->id,'command_id'=>$mapping,'actor_id'=>1,'route_name'=>'fixture.typed-crud-relations','request_hash'=>str_repeat('b',64),
            'command_cipher'=>Crypt::encryptString('{}'),'local_result_cipher'=>Crypt::encryptString('{}'),'server_result_cipher'=>Crypt::encryptString(json_encode($mappingReceipt,JSON_THROW_ON_ERROR)),
            'dependencies'=>'[]','status'=>'acknowledged','attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $typed=$drift;$typed['command_id']=(string)Str::uuid();$commands[]=$typed['command_id'];$driftRaw['values']['idempotency_key']=$typed['command_id'];
        $typed['payload']=app(DesktopDashboardRestaurantProductCrud::class)->inputs($driftRaw,function(string $entity,$id)use($map,$mapping){
            if($entity==='menu_product_media'){foreach(['menu_product_media.0','menu_product_media.1'] as $slot)if(isset($map[$slot][$id]))return ['$desktop_ref'=>['entity'=>$slot,'command_id'=>$mapping,'local_id'=>(int)$id]];}
            if(isset($map[$entity][$id]))return ['$desktop_ref'=>['entity'=>$entity,'command_id'=>$mapping,'local_id'=>(int)$id]];return $id;});$typed['dependencies']=[$mapping];
        $priorBranches=DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->value('branches');$branches=json_decode($priorBranches,true);$branches[]='f:'.$driftAliases['resturants'];DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode($branches)]);
        try{
            foreach([['values.category_id','menu_restaurant'],['values.product_id','catalog_category'],['facts.restaurant_product_crud_before.product.media.0.id','catalog_product'],['facts.restaurant_product_crud_before.product.features.0.id','menu_product_media']] as [$path,$wrong]){
                $bad=$typed;$ref=data_get($bad['payload'],$path);$ref['$desktop_ref']['entity']=$wrong;data_set($bad['payload'],$path,$ref);
                $reject(fn()=>app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$bad),422,'explicit CRUD typed-reference validation rejects a wrong entity at '.$path);}
            $unrelatedProduct=$product(1);$unrelatedBranch=(array)DB::table('resturants')->where('id',100)->first();$unrelatedMedia=$media(1);
            $providerEvents=app('events');$providerCallbacks=new ReflectionProperty(\Illuminate\Foundation\Application::class,'terminatingCallbacks');$providerCallbacks->setAccessible(true);
            $retainedCallbacks=$providerCallbacks->getValue(app());$providerCallbacks->setValue(app(),[]);
            $failedProvider=new class(app()) extends \Illuminate\Events\Dispatcher {public int $attempts=0;public function dispatch($event,$payload=[],$halt=false){
                if($event instanceof \App\Events\ResturantProductUpdated){$this->attempts++;throw new RuntimeException('fixture actual committed receipt provider unavailable');}return parent::dispatch($event,$payload,$halt);}};
            app()->instance('events',$failedProvider);
            try{$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$typed);$committedProduct=$product($driftAliases['resturant_products']);
                verify($receipt['committed']===true&&$failedProvider->attempts===0&&count($providerCallbacks->getValue(app()))===1,
                    'the actual original CRUD replay commits its encrypted business receipt before scheduling one provider callback');
                app()->terminate();app()->terminate();
                verify($failedProvider->attempts===1&&$product($driftAliases['resturant_products'])===$committedProduct
                    &&$receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$typed),
                    'a failed actual post-response provider cannot change the committed row or encrypted reconciliation receipt and executes only once');
            }finally{app()->instance('events',$providerEvents);$providerCallbacks->setValue(app(),$retainedCallbacks);}
            verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$typed)
                &&$product($driftAliases['resturant_products'])['product_name']===$editDrift['product_name']
                &&$product(1)===$unrelatedProduct&&(array)DB::table('resturants')->where('id',100)->first()===$unrelatedBranch&&$media(1)===$unrelatedMedia,
                'actual original CRUD replay resolves product, branch/owner, category/catalog, feature and media ID drift without touching colliding original integers');
            verify(collect($receipt['references'])->firstWhere('entity','menu_product_media.0')['server_id']===55703
                &&collect($receipt['references'])->firstWhere('entity','menu_product_media.1')['server_id']===55702
                &&$receipt['result']['http']['location']==='/admin/resturants/'.$driftAliases['resturants'].'?'.$returnQuery,
                'typed CRUD receipt slots keep original image identity order even when mapped server IDs reverse their numeric order and retain encoded return context');
        }finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$priorBranches]);}
        // Actual framework transaction callbacks, with only provider delivery replaced by a recording dispatcher.
        $events=app('events');$callbacks=new ReflectionProperty(\Illuminate\Foundation\Application::class,'terminatingCallbacks');$callbacks->setAccessible(true);$oldCallbacks=$callbacks->getValue(app());$callbacks->setValue(app(),[]);
        $spy=new class(app()) extends \Illuminate\Events\Dispatcher {public array $seen=[];public bool $fail=false;public function dispatch($event,$payload=[],$halt=false){if($event instanceof \App\Events\ResturantProductUpdated){$this->seen[]=$event;if($this->fail)throw new RuntimeException('fixture provider unavailable');return null;}return parent::dispatch($event,$payload,$halt);}};
        app()->instance('events',$spy);$effects=app(\App\Services\Dashboard\DesktopDashboardProductEffects::class);$sample=new \App\Models\ResturantProduct(['product_name'=>'effect snapshot','product_price'=>'12.50']);$sample->id=1;
        try{
            config(['desktop_dashboard.local'=>true]);$effects->updated($sample,1);$effects->guard(fn()=>$effects->updated($sample,1));app()->terminate();
            verify($spy->seen===[]&&$callbacks->getValue(app())===[],'trusted local product updates schedule no provider event or termination callback');
            config(['desktop_dashboard.local'=>false]);$effects->guard(function()use($effects,$sample){DB::beginTransaction();DB::beginTransaction();$effects->updated($sample,1);DB::commit();
                verify(DB::transactionLevel()===1,'nested prepared product events still wait for the original outer transaction');DB::commit();});
            verify($spy->seen===[]&&count($callbacks->getValue(app()))===1,'one successful outer commit schedules one provider event only after response termination');
            $sample->product_price='999.00';app()->terminate();app()->terminate();verify(count($spy->seen)===1&&$spy->seen[0]->resturant->product_price==='12.50','successful prepared provider delivery uses the committed snapshot and runs only once');
            $beforeCallbacks=count($callbacks->getValue(app()));$effects->guard(function()use($effects,$sample){DB::beginTransaction();$effects->updated($sample,1);DB::rollBack();});app()->terminate();
            verify(count($spy->seen)===1&&count($callbacks->getValue(app()))===$beforeCallbacks,'a rolled-back prepared product update leaves no after-commit provider callback');
            try{$effects->guard(fn()=>$effects->guard(fn()=>throw new RuntimeException('fixture nested guard')));}catch(RuntimeException $error){verify($error->getMessage()==='fixture nested guard','nested product effect guards propagate the original failure');}
            $effects->updated($sample,1);verify(count($spy->seen)===2,'nested guard failure restores the exact original ordinary-online broadcast behavior');
        }finally{app()->instance('events',$events);$callbacks->setValue(app(),$oldCallbacks);config(['desktop_dashboard.local'=>false]);}
        echo 'RESTAURANT_PRODUCT_CRUD_COMPLETE '.json_encode(['schemaInventory'=>106,'productColumns'=>13,'mediaColumns'=>18,'originalController'=>true,'imageBytesPreserved'=>true,
            'createUpdateReplaceDelete'=>true,'typedProductMediaDrift'=>true,'effectsAfterCommit'=>true,'fullDashboard'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
    }finally{
        if(is_resource($web)){fclose($pipes[0]);proc_terminate($web);proc_close($web);}
        foreach([false,true] as $local){$switch($local);$ids=$local?$createdLocal:$createdServer;
            if($ids){DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->whereIn('model_id',$ids)->delete();DB::table('resturant_products')->whereIn('id',$ids)->delete();}
            DB::table('media')->whereIn('id',$fixtureMediaIds)->delete();$snapshot=$local?$localSaved:$serverSaved;$restore('resturant_products',$snapshot['product']);
            $restore('categories',$snapshot['category']);$restore('products',$snapshot['catalog']);DB::table('product_features')->where('product_id',1)->delete();if($snapshot['features'])DB::table('product_features')->insert($snapshot['features']);
            if($commands)DB::table('desktop_dashboard_commands')->whereIn('command_id',$commands)->delete();if($mappingCommands)DB::table('desktop_dashboard_commands')->whereIn('command_id',$mappingCommands)->delete();}
        foreach([false,true] as $local){$switch($local);DB::table('product_features')->where('id',55710)->delete();}
        $switch(false);if(isset($driftAliases)){DB::table('product_features')->where('id',$driftAliases['product_features'])->delete();DB::table('products')->where('id',$driftAliases['products'])->delete();
            DB::table('categories')->where('id',$driftAliases['categories'])->delete();DB::table('resturants')->where('id',$driftAliases['resturants'])->delete();DB::table('users')->where('id',$driftAliases['users'])->delete();}
        $switch(true);DB::table('desktop_dashboard_entities')->where('device_id',$ownerDevice)->delete();if($entityBaseline)DB::table('desktop_dashboard_entities')->insert($entityBaseline);
        foreach($paths as $path=>$bytes)if(is_file($path))unlink($path);$switch(false);
    }
})();
