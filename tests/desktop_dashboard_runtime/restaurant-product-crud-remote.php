<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

// Included in the actual original server HTTP fixture, with native reserve/settle capabilities.
(function()use($decide,$proof,$remoteDevice,$origin,&$cookies,$serverCsrf,$profile,$report){
    $query='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';$commands=[];$created=[];$mediaIds=[];$paths=[];
    $product=fn(int $id)=>(array)DB::table('resturant_products')->where('id',$id)->first();
    $media=fn(int $id)=>DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->where('model_id',$id)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $file=function(array $row){$path=\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($row['id'])->getPath();return ['path'=>$path,'bytes'=>is_file($path)?file_get_contents($path):null];};
    $request=function(string $path,array $form,array $files=[],array $headers=[])use($origin,$query,&$cookies){$boundary='native-restaurant-product-'.bin2hex(random_bytes(12));$body='';
        foreach($form as $name=>$value)foreach(is_array($value)?$value:[$value] as $part)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name.(is_array($value)?'[]':'')."\"\r\n\r\n".$part."\r\n";
        foreach($files as $name=>$upload)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name.'"; filename="'.$upload['name']."\"\r\nContent-Type: ".$upload['mime']."\r\n\r\n".$upload['bytes']."\r\n";
        $body.='--'.$boundary."--\r\n";$headers=['Content-Type: multipart/form-data; boundary='.$boundary,'Referer: '.$origin.'/admin/resturants/100?'.$query,...$headers];
        if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
        $reply=@file_get_contents($origin.$path,false,stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]));
        $headers=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$headers[0]??'',$status);
        foreach($headers as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];return [(int)($status[1]??0),$reply,$headers];};
    $reserve=fn(string $path,string $method='POST')=>['id'=>(string)Str::uuid(),'method'=>$method,'path'=>$path];
    $outcome=function(array $attempt){$row=DB::table('desktop_dashboard_remote_attempts')->where('id',$attempt['id'])->first();
        return json_decode(Crypt::decryptString($row->response_cipher),true,512,JSON_THROW_ON_ERROR);};
    $fingerprint=function(array $allowedProducts=[],array $allowedMedia=[])use($report){$inventory=[];foreach(array_keys($report['tables']) as $table){$parts=[];
        foreach(DB::table($table)->get() as $raw){$row=(array)$raw;if($table==='resturant_products'&&in_array((int)$row['id'],$allowedProducts,true)||$table==='media'&&in_array((int)$row['id'],$allowedMedia,true))continue;
            ksort($row);$parts[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}sort($parts);$inventory[$table]=['rows'=>count($parts),'sha256'=>hash('sha256',implode("\n",$parts))];}ksort($inventory);return $inventory;};
    $pngA=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=',true);
    $pngB=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);
    $imageA=['name'=>'رنجة أصلية عبر السيرفر.png','mime'=>'image/png','bytes'=>$pngA];$imageB=['name'=>'بديل عبر السيرفر.png','mime'=>'image/png','bytes'=>$pngB];
    $base=['_token'=>$serverCsrf[1],'added_by'=>'1','resturant_id'=>'100','category_id'=>'1','product_id'=>'1','product_name'=>'صنف نتيجة الصور الأصلية','product_description'=>'وصف صورة نتيجة السيرفر','product_price'=>'145.00',
        'extra_combo'=>'1','extra_large'=>'2','extra_medium'=>'3','extra_clean'=>'4','extra_clear'=>'5','extra_vacuim'=>'6'];
    $beforeBusiness=$fingerprint();$deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();
    try{
        $form=$base;$form['_desktop_command']=(string)Str::uuid();$commands[]=$form['_desktop_command'];$create=$reserve('/admin/resturant_products');[$status,$ready]=$decide($create);
        verify($status===200&&$ready['status']==='ready','native devices reserve the exact original multipart product create action');
        [$status,$body,$headers]=$request($create['path'],$form,['product_image'=>$imageA],$proof($ready));$row=(array)DB::table('resturant_products')->where('product_name',$form['product_name'])->first();$created[]=(int)($row['id']??0);
        verify($status===302&&count($row)===13&&in_array('Location: '.$origin.'/admin/resturants/100?'.$query,$headers,true)&&$decide($create,'settle')[1]['status']==='committed',
            'the native original create commits all13 product columns and settles its actual retained redirect');
        $images=$media($row['id']);$mediaIds=array_merge($mediaIds,array_column($images,'id'));verify(count($images)===1&&count($images[0])===18&&$file($images[0])['bytes']===$pngA,
            'the native original media library creates its full18-column media row and exact Arabic-named PNG bytes');$paths[$file($images[0])['path']]=$pngA;
        $saved=$outcome($create);verify($saved['restaurant_product_crud_route']==='resturant_products.store'
            &&$saved['restaurant_product_crud_after']['product']['row']===$row&&$saved['restaurant_product_crud_result']['after']['media']===$images
            &&$saved['restaurant_product_crud_success']===trans('messages.AddSuccessfully'),
            'the encrypted native CRUD outcome retains complete original facts/result/success for terminal recovery');
        verify($request($create['path'],$form,['product_image'=>$imageA],$proof($ready))[1]===$body&&$product($row['id'])===$row&&$media($row['id'])===$images,
            'a lost native create redirect replays one exact original row and image without another write');
        $retry=$reserve($create['path']);[, $retryReady]=$decide($retry);verify($request($retry['path'],$form,['product_image'=>$imageA],$proof($retryReady))[1]===$body&&$decide($retry,'settle')[1]['status']==='committed',
            'another native transmission preserves the same logical create UUID and immutable complete image');
        foreach([['field'=>'product_name','value'=>'محتوى مختلف'],['field'=>'product_price','value'=>'199.00']] as $change){$bad=$form;$bad[$change['field']]=$change['value'];$attempt=$reserve($create['path']);[, $cap]=$decide($attempt);
            verify($request($attempt['path'],$bad,['product_image'=>$imageA],[...$proof($cap),'Accept: application/json'])[0]===409&&$decide($attempt,'settle')[1]['status']==='cancelled',
                'changed native create content cannot obtain its original committed receipt: '.$change['field']);}
        verify($request($create['path'],$form,['product_image'=>$imageB],[...$proof($ready),'Accept: application/json'])[0]===409,'a committed native product UUID rejects changed actual PNG bytes and basename');
        $path=$file($images[0])['path'];unlink($path);try{verify($request($create['path'],$form,['product_image'=>$imageA],[...$proof($ready),'Accept: application/json'])[0]===503
            &&$decide($create,'settle')[0]===503,'native cached HTTP and terminal recovery cannot report a committed PNG unavailable as complete success');}
        finally{file_put_contents($path,$pngA);}
        foreach(['status'=>'disabled','owner_resturant_id'=>101] as $field=>$value){$old=DB::table('users')->where('id',1)->value($field);DB::table('users')->where('id',1)->update([$field=>$value]);
            try{verify($request($create['path'],$form,['product_image'=>$imageA],[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($create,'settle')[0]===403,
                'native saved/recovered image CRUD receipts require current actor '.$field);}finally{DB::table('users')->where('id',1)->update([$field=>$old]);}}
        DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:101'])]);
        try{verify($request($create['path'],$form,['product_image'=>$imageA],[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($create,'settle')[0]===403,'revoking the native enrolled branch rejects cached HTTP and settle image outcomes');}
        finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
        $update=$base;$update['id']=(string)$row['id'];$update['_method']='put';$update['_desktop_command']=(string)Str::uuid();$update['product_name']='صنف نتيجة تعديل دون صورة';$commands[]=$update['_desktop_command'];
        $edit=$reserve('/admin/resturant_products/update');[, $editReady]=$decide($edit);[$status,$editBody]=$request($edit['path'],$update,[],$proof($editReady));$edited=$product($row['id']);
        verify($status===302&&$edited['product_name']===$update['product_name']&&$media($row['id'])===$images&&$file($images[0])['bytes']===$pngA
            &&$decide($edit,'settle')[1]['status']==='committed'&&$outcome($edit)['restaurant_product_crud_success']===trans('messages.AddSuccessfully'),
            'native original multipart method-spoofed update preserves a pre-existing PNG and its original update success semantics');
        verify($request($edit['path'],$update,[],$proof($editReady))[1]===$editBody&&$product($row['id'])===$edited,'native no-image update retry changes neither original row timestamp nor media identity');
        $replace=$update;$replace['_desktop_command']=(string)Str::uuid();$replace['product_name']='صنف نتيجة استبدال صورة';$commands[]=$replace['_desktop_command'];$replacementAttempt=$reserve($edit['path']);[, $replacementReady]=$decide($replacementAttempt);
        DB::unprepared("CREATE TRIGGER reject_native_restaurant_crud BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$replace['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture native CRUD outcome rollback'; END IF; END");
        try{verify($request($edit['path'],$replace,['product_image'=>$imageB],[...$proof($replacementReady),'Accept: application/json'])[0]===500
            &&$product($row['id'])===$edited&&$media($row['id'])===$images&&is_file($path)&&file_get_contents($path)===$pngA
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$replacementAttempt['id'])->value('status')==='ready',
            'failed native outer outcome persistence rolls back original replacement row/media while retaining old complete PNG bytes');}
        finally{DB::unprepared('DROP TRIGGER reject_native_restaurant_crud');}
        [$status,$replaceBody]=$request($edit['path'],$replace,['product_image'=>$imageB],$proof($replacementReady));$newImages=$media($row['id']);$mediaIds=array_merge($mediaIds,array_column($newImages,'id'));
        verify($status===302&&count($newImages)===1&&$newImages[0]['id']!==$images[0]['id']&&$file($newImages[0])['bytes']===$pngB&&is_file($path)&&file_get_contents($path)===$pngA
            &&$decide($replacementAttempt,'settle')[1]['status']==='committed','the same native attempt retries after rollback and replaces the collection exactly once while retaining durable prior bytes');$paths[$file($newImages[0])['path']]=$pngB;
        verify($request($edit['path'],$replace,['product_image'=>$imageB],$proof($replacementReady))[1]===$replaceBody&&$media($row['id'])===$newImages,'a lost native replacement reply returns the same complete image receipt without an extra allocation');
        $delete=['_token'=>$serverCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];$commands[]=$delete['_desktop_command'];$destroy=$reserve('/admin/resturant_products/'.$row['id']);[, $destroyReady]=$decide($destroy);
        [$status,$destroyBody]=$request($destroy['path'],$delete,[],$proof($destroyReady));verify($status===302&&!DB::table('resturant_products')->where('id',$row['id'])->exists()
            &&$media($row['id'])===$newImages&&$file($newImages[0])['bytes']===$pngB&&$decide($destroy,'settle')[1]['status']==='committed',
            'native exact original query deletion preserves orphan product image rows/bytes and settles its deleted-row outcome');
        verify($request($destroy['path'],$delete,[],$proof($destroyReady))[1]===$destroyBody,'a lost native delete result replays before rebinding the absent product');
        $late=$reserve('/admin/resturant_products');[, $lateReady]=$decide($late);verify($decide($late,'settle')[1]['status']==='cancelled','recovery terminally cancels a multipart product create that never reached the original controller');
        $lateForm=$base;$lateForm['_desktop_command']=(string)Str::uuid();$lateForm['product_name']='رفع صورة وصل بعد الإلغاء';verify($request($late['path'],$lateForm,['product_image'=>$imageA],[...$proof($lateReady),'Accept: application/json'])[0]===409
            &&!DB::table('resturant_products')->where('product_name',$lateForm['product_name'])->exists(),'a delayed genuine multipart image request cannot write after native cancellation');
        foreach([['HEAD','/admin/resturant_products'],['DELETE','/admin/resturant_products/update'],['PUT','/admin/resturant_products/0'],['POST','/admin/resturant/copy/items/']] as [$method,$path])
            verify($decide($reserve($path,$method))[0]===422,'native image CRUD coverage rejects an unreviewed path or method: '.$method.' '.$path);
        verify($fingerprint($created,$mediaIds)===$beforeBusiness,'all106 original business tables outside the native product/image effect scope are unchanged, including orders, wallets and catalog images');
        echo 'RESTAURANT_PRODUCT_CRUD_REMOTE_COMPLETE '.json_encode(['schemaInventory'=>106,'productColumns'=>13,'mediaColumns'=>18,'originalMultipart'=>true,'originalController'=>true,
            'completePngRetry'=>true,'nativeCancellation'=>true,'rollbackPreservesOldPng'=>true,'fullDashboard'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
    }finally{DB::table('media')->whereIn('id',$mediaIds)->delete();if($created)DB::table('resturant_products')->whereIn('id',$created)->delete();foreach($paths as $path=>$bytes)if(is_file($path))unlink($path);
        DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);}
})();
