<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;

// Included after the real original server HTTP fixture prepares native reserve/settle helpers.
(function()use($decide,$proof,$remoteDevice,$origin,&$cookies,$serverCsrf,$profile,$report){
    echo 'RESTAURANT_MENU_COPY_REMOTE_BEGIN'.PHP_EOL;$startCount=$GLOBALS['count'];
    $path='/admin/resturant/copy/items';$query='q=%D8%B1%D9%86%D8%AC%D8%A9+%26+fish&resturant_id=101&page=2';
    $sourceIds=[992101,992102,992103,992104,992105,992106];$catalogIds=[992111,992112,992113,992114,992115,992116];$targetId=992100;
    $fixtureMediaIds=range(993100,993106);$attemptIds=[];$commands=[];$created=[];$newMedia=[];$sourceFiles=[];$completed=false;
    $rows=fn(string $table)=>DB::table($table)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $product=fn(int $id)=>(array)DB::table('resturant_products')->where('id',$id)->first();
    $media=fn(int $id)=>DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->where('model_id',$id)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $targetRows=fn()=>DB::table('resturant_products')->where('resturant_id',100)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $targetMedia=fn()=>DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->whereIn('model_id',array_column($targetRows(),'id'))->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $file=fn(array $row)=>\Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail($row['id'])->getPath();
    $request=function(array $form,array $headers=[])use($origin,$path,$query,$profile,&$cookies){
        $boundary='native-original-menu-copy-'.bin2hex(random_bytes(12));$body='';
        foreach($form as $name=>$value){
            if(!is_array($value))$parts=[[$name,$value]];
            else {$parts=[];foreach($value as $key=>$part)$parts[]=[$name.(array_is_list($value)?'[]':'['.$key.']'),$part];}
            foreach($parts as [$field,$part])$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$field."\"\r\n\r\n".$part."\r\n";
        }
        $body.='--'.$boundary."--\r\n";$headers=['Content-Type: multipart/form-data; boundary='.$boundary,'Referer: '.$origin.'/admin/resturants/100?'.$query,...$headers];
        if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
        $reply=@file_get_contents($origin.$path,false,stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]));
        $headers=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$headers[0]??'',$status);
        foreach($headers as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];
        if(empty($status[1]))foreach(['remote-attempts.log','logs/laravel.log'] as $log)if(is_file($profile.'/'.$log))fwrite(STDERR,substr(file_get_contents($profile.'/'.$log),-8000).PHP_EOL);
        return [(int)($status[1]??0),$reply,$headers];
    };
    $reserve=function(string $method='POST',?string $uri=null)use($path,&$attemptIds){$attempt=['id'=>(string)Str::uuid(),'method'=>$method,'path'=>$uri??$path];$attemptIds[]=$attempt['id'];return $attempt;};
    $uuid=function()use(&$commands){$command=(string)Str::uuid();$commands[]=$command;return $command;};
    $outcome=function(array $attempt){$row=DB::table('desktop_dashboard_remote_attempts')->where('id',$attempt['id'])->first();return json_decode(Crypt::decryptString($row->response_cipher),true,512,JSON_THROW_ON_ERROR);};
    $fingerprint=function(array $allowedProducts=[],array $allowedMedia=[],array $priceEffects=[])use($report){$inventory=[];
        foreach(array_keys($report['tables']) as $table){$parts=[];foreach(DB::table($table)->get() as $raw){$row=(array)$raw;
            if($table==='resturant_products'&&in_array((int)$row['id'],$allowedProducts,true)||$table==='media'&&in_array((int)$row['id'],$allowedMedia,true))continue;
            if($table==='resturant_products'&&in_array((int)$row['id'],$priceEffects,true))unset($row['product_price'],$row['updated_at']);
            ksort($row);$parts[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
            sort($parts);$inventory[$table]=['rows'=>count($parts),'sha256'=>hash('sha256',implode("\n",$parts))];}ksort($inventory);return $inventory;
    };
    $files=function(string $root){$found=[];if(is_dir($root))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $entry)
        if($entry->isFile())$found[$entry->getPathname()]=hash_file('sha256',$entry->getPathname());ksort($found);return $found;};
    $originalBusiness=$fingerprint();$originalTargetProducts=array_column($targetRows(),'id');
    if(DB::table('products')->whereIn('id',$catalogIds)->exists()||DB::table('resturant_products')->whereIn('id',[$targetId,...$sourceIds])->exists()
        ||DB::table('media')->whereIn('id',$fixtureMediaIds)->exists())throw new RuntimeException('Native menu-copy fixture IDs are already occupied.');
    $actorBefore=(array)DB::table('users')->where('id',1)->first();$targetBefore=(array)DB::table('resturants')->where('id',100)->first();
    $deviceBefore=(array)DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->first();
    $root=(string)config('filesystems.disks.products.root');$filesBefore=$files($root);
    $pngA=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=',true);
    $pngB=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZFXsAAAAASUVORK5CYII=',true);
    $sourcesIntact=function()use(&$sourceFiles){foreach($sourceFiles as $filename=>$bytes)if(!is_file($filename)||file_get_contents($filename)!==$bytes)return false;return true;};
    try{
        DB::table('users')->where('id',1)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>101]);
        DB::table('resturants')->where('id',100)->update(['parent_id'=>101]);DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:100','f:101'])]);
        $stamp='2020-01-01 00:00:00';$addons=['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
        foreach($catalogIds as $index=>$id){$catalog=(array)DB::table('products')->where('id',1)->first();$catalog['id']=$id;$catalog['name_ar']='كتالوج نتيجة النسخ '.$index;$catalog['name_en']='Native copy catalog '.$index;$catalog['created_at']=$stamp;$catalog['updated_at']=$stamp;DB::table('products')->insert($catalog);
            DB::table('resturant_products')->insert(['id'=>$sourceIds[$index],'added_by'=>11,'resturant_id'=>101,'product_id'=>$id,'category_id'=>1,
                'product_name'=>'صنف مصدر نتيجة النسخ '.$index,'product_description'=>'وصف المصدر الأصلي '.$index,'product_price'=>(string)(111*($index+1)),
                'price'=>json_encode(array_combine($addons,['1','2','3','4','5','6'])),'status'=>'show','highest_rated'=>'no','created_at'=>$stamp,'updated_at'=>$stamp]);}
        $existing=$product($sourceIds[0]);$existing['id']=$targetId;$existing['resturant_id']=100;$existing['added_by']=1;$existing['product_name']='صنف الفرع الموجود قبل النسخ';
        $existing['product_price']='44.00';$existing['price']=json_encode(array_fill_keys($addons,'9'));DB::table('resturant_products')->insert($existing);$existing=$product($targetId);
        foreach([[993100,$targetId,$pngB],[993101,$sourceIds[0],$pngA],[993102,$sourceIds[1],$pngA],[993103,$sourceIds[1],$pngB],[993104,$sourceIds[2],$pngB],[993105,$sourceIds[4],$pngA],[993106,$sourceIds[5],$pngB]] as $index=>[$id,$productId,$bytes]){
            DB::table('media')->insert(['id'=>$id,'model_type'=>\App\Models\ResturantProduct::class,'model_id'=>$productId,'uuid'=>null,'collection_name'=>'product_image',
                'name'=>'اسم صورة المصدر الأصلية '.$index,'file_name'=>'صورة-نتيجة-النسخ-'.$index.'.png','mime_type'=>'image/png','disk'=>'products','conversions_disk'=>'products','size'=>strlen($bytes),
                'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>'{}','responsive_images'=>'{}','order_column'=>$index+1,'created_at'=>$stamp,'updated_at'=>$stamp]);
            $filename=$file((array)DB::table('media')->where('id',$id)->first());
            if(file_exists($filename)||is_link($filename)||is_link(dirname($filename))||is_link($root))throw new RuntimeException('Native menu-copy fixture image path is already occupied or linked.');
            if(!is_dir(dirname($filename)))mkdir(dirname($filename),0700,true);
            if(file_put_contents($filename,$bytes)!==strlen($bytes))throw new RuntimeException('Native menu-copy fixture image could not be written completely.');$sourceFiles[$filename]=$bytes;
        }
        $sourceRows=DB::table('resturant_products')->whereIn('id',$sourceIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $sourceMedia=DB::table('media')->whereIn('id',$fixtureMediaIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        $copyRoute=app('router')->getRoutes()->getByName('copy_menu');
        verify(count($report['tables'])===106&&count($existing)===13&&array_unique(array_map('count',$sourceRows))===[13]&&array_unique(array_map('count',$sourceMedia))===[18]
            &&DB::table('users')->where('id',1)->value('account_type')==='resturant_owner'&&$copyRoute->uri()==='admin/resturant/copy/items'&&$copyRoute->methods()===['POST']
            &&$copyRoute->getActionName()==='App\\Http\\Controllers\\Api\\V1\\Vendor\\ResturantProductController@copy_menu'
            &&realpath((new ReflectionClass(\App\Http\Controllers\Api\V1\Vendor\ResturantProductController::class))->getFileName())===realpath(base_path('app/Http/Controllers/Api/V1/Vendor/ResturantProductController.php')),
            'native copy uses the original106 table inventory, full13/18-column rows and authentic parent restaurant owner');
        $beforeBusiness=$fingerprint([],[],[$targetId]);$beforeRows=$targetRows();$beforeMedia=$targetMedia();
        $failureForm=['_token'=>$serverCsrf[1],'resturant_id'=>'100','restraunt_product'=>array_map('strval',[$sourceIds[1],$sourceIds[2]]),'_desktop_command'=>$uuid()];
        $secondImage=$reserve();[$status,$secondReady]=$decide($secondImage);verify($status===200&&$secondReady['status']==='ready','native reservation accepts only the original parent-menu POST');
        $secondName=$sourceMedia[4]['file_name'];$quoted=DB::connection()->getPdo()->quote($secondName);
        DB::unprepared("CREATE TRIGGER reject_native_menu_second_image BEFORE INSERT ON media FOR EACH ROW BEGIN IF NEW.file_name=".$quoted." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture native second copied image failure'; END IF; END");
        try{verify($request($failureForm,$proof($secondReady))[0]===500&&$targetRows()===$beforeRows&&$targetMedia()===$beforeMedia&&$sourcesIntact()
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$secondImage['id'])->value('status')==='ready',
            'failure on the second original copied image rolls back every clone/media row and preserves all source image bytes');}
        finally{DB::unprepared('DROP TRIGGER reject_native_menu_second_image');}
        verify($decide($secondImage,'settle')[1]['status']==='cancelled','a fully rolled-back copied-image batch can be terminally cancelled');
        $outer=$reserve();[, $outerReady]=$decide($outer);$failureForm['_desktop_command']=$uuid();
        DB::unprepared("CREATE TRIGGER reject_native_menu_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$failureForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture native menu outcome failure'; END IF; END");
        try{verify($request($failureForm,$proof($outerReady))[0]===500&&$targetRows()===$beforeRows&&$targetMedia()===$beforeMedia&&$sourcesIntact()
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$outer['id'])->value('status')==='ready'
            &&DB::table('desktop_dashboard_remote_attempts')->where('id',$outer['id'])->value('response_cipher')===null,
            'failure saving the outer native outcome rolls back the complete original copy batch without damaging its source files');}
        finally{DB::unprepared('DROP TRIGGER reject_native_menu_outcome');}
        verify($decide($outer,'settle')[1]['status']==='cancelled','native recovery cancels an original copy whose outer outcome never committed');
        $late=$reserve();[, $lateReady]=$decide($late);verify($decide($late,'settle')[1]['status']==='cancelled','native recovery terminally cancels a copy form that never arrived');
        $failureForm['_desktop_command']=$uuid();verify($request($failureForm,[...$proof($lateReady),'Accept: application/json'])[0]===409&&$targetRows()===$beforeRows&&$targetMedia()===$beforeMedia,
            'a delayed genuine original multipart copy cannot write after terminal cancellation');
        $selection=[$sourceIds[0],$sourceIds[1],$sourceIds[2],$sourceIds[1],$sourceIds[3]];
        $form=['_token'=>$serverCsrf[1],'resturant_id'=>'100','restraunt_product'=>array_map('strval',$selection),'_desktop_command'=>$uuid()];$copy=$reserve();[, $ready]=$decide($copy);
        [$status,$body,$headers]=$request($form,$proof($ready));$afterRows=$targetRows();$afterMedia=$targetMedia();
        $created=array_values(array_diff(array_column($afterRows,'id'),array_column($beforeRows,'id')));$newMedia=array_values(array_diff(array_column($afterMedia,'id'),array_column($beforeMedia,'id')));
        verify($status===302&&in_array('Location: '.$origin.'/admin/resturants/100?'.$query,$headers,true)&&count($created)===4&&count($newMedia)===3
            &&$decide($copy,'settle')[1]['status']==='committed','the native original checkbox batch commits four clones and three complete images with its exact original redirect');
        $saved=$outcome($copy);$result=$saved['restaurant_menu_copy_result'];$after=$result['after'];$payload=$saved['restaurant_menu_copy_payload'];
        verify($saved['restaurant_menu_copy_route']==='copy_menu'&&$saved['restaurant_menu_copy_facts']===$payload['facts']&&$saved['restaurant_menu_copy_after']===$after
            &&$saved['restaurant_menu_copy_success']===$result['success']&&$result['success']===__('main.menue copied successfully')
            &&$saved['status']===302&&$saved['content']===$body&&$result['http']['content']===$body&&$result['http']['location']==='/admin/resturants/100?'.$query
            &&$payload['parameters']===[]&&$payload['files']===[]&&array_map('intval',$payload['values']['restraunt_product'])===$selection
            &&$payload['values']['idempotency_key']===$form['_desktop_command'],
            'encrypted native terminal outcome retains complete menu facts/after/success/route/payload/result and the exact ordered duplicate selection');
        verify(array_column($after['steps'],'source_product')===$selection&&array_column($after['steps'],'mode')===['price','create','create','create','create']
            &&$after['steps'][1]['target_product']!==$after['steps'][3]['target_product']&&$after['steps'][1]['source_media']===993102&&$after['steps'][3]['source_media']===993102,
            'ordered duplicate checkboxes preserve the original cached initial target membership and copy only each source first image');
        $expected=$existing;$actual=$product($targetId);$expected['product_price']='111.00';unset($expected['updated_at'],$actual['updated_at']);
        verify($actual===$expected&&$media($targetId)===[$sourceMedia[0]],'an existing catalog match changes only its original price/timestamp and retains addon prices and media');
        foreach([1,2,3,4] as $index){$step=$after['steps'][$index];$source=$product($step['source_product']);$clone=$product($step['target_product']);$expected=$source;
            $expected['id']=$step['target_product'];$expected['resturant_id']=100;$expected['added_by']=1;unset($expected['created_at'],$expected['updated_at'],$clone['created_at'],$clone['updated_at']);
            verify(count($product($step['target_product']))===13&&$clone===$expected,'original native replicate preserves every source product field except destination actor/id/times: '.$index);}
        foreach([1,2,3] as $index){$step=$after['steps'][$index];$images=$media($step['target_product']);$source=$media($step['source_product'])[0];$filename=$file($images[0]);
            verify(count($images)===1&&count($images[0])===18&&$images[0]['name']===$source['name']&&$images[0]['file_name']===$source['file_name']&&$images[0]['mime_type']===$source['mime_type']
                &&$images[0]['disk']==='products'&&$images[0]['collection_name']==='product_image'&&is_file($filename)&&file_get_contents($filename)===file_get_contents($file($source)),
                'original native FileAdder copies the full first PNG with its source name, filename and complete18-column metadata: '.$index);}
        verify($media($after['steps'][4]['target_product'])===[],'an original source without an image creates no media row');
        verify($request($form,$proof($ready))[1]===$body&&$targetRows()===$afterRows&&$targetMedia()===$afterMedia,
            'a lost native original copy redirect replays the exact reply without extra clone/image allocations or timestamp changes');
        $retry=$reserve();[, $retryReady]=$decide($retry);verify($request($form,$proof($retryReady))[1]===$body&&$targetRows()===$afterRows&&$targetMedia()===$afterMedia
            &&$decide($retry,'settle')[1]['status']==='committed','a new native transmission keeps the same logical UUID and original copy reply without another write');
        $changed=$form;$changed['restraunt_product']=array_reverse($changed['restraunt_product']);$changedAttempt=$reserve();[, $changedReady]=$decide($changedAttempt);
        verify($request($changed,[...$proof($changedReady),'Accept: application/json'])[0]===409&&$targetRows()===$afterRows&&$targetMedia()===$afterMedia
            &&$decide($changedAttempt,'settle')[1]['status']==='cancelled','changed order/content cannot reuse a committed original menu-copy UUID');
        $copied=$media($after['steps'][1]['target_product'])[0];$copiedPath=$file($copied);$copiedBytes=file_get_contents($copiedPath);
        foreach(['missing','corrupt'] as $fault){if($fault==='missing')unlink($copiedPath);else file_put_contents($copiedPath,$copiedBytes.'corrupt');
            try{verify($request($form,[...$proof($ready),'Accept: application/json'])[0]===503&&$decide($copy,'settle')[0]===503,
                'native cached HTTP and terminal settle reject a '.$fault.' copied full-image receipt');}finally{file_put_contents($copiedPath,$copiedBytes);}}
        foreach([['f:100'],['f:101']] as $branches){DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode($branches)]);
            try{verify($request($form,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($copy,'settle')[0]===403,
                'both cached original copy and settle require CURRENT source and destination enrollment: '.implode(',',$branches));}
            finally{DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>json_encode(['f:100','f:101'])]);}}
        DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);
        try{verify($request($form,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($copy,'settle')[0]===403,'revoking CURRENT parent ownership blocks saved native copy HTTP and settle even while the target is owned');}
        finally{DB::table('users')->where('id',1)->update(['owner_resturant_id'=>101]);}
        DB::table('resturants')->where('id',100)->update(['parent_id'=>null]);
        try{verify($request($form,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($copy,'settle')[0]===403,'revoking CURRENT child ownership blocks saved native copy HTTP and settle while the original parent remains owned');}
        finally{DB::table('resturants')->where('id',100)->update(['parent_id'=>101]);}
        DB::table('resturant_products')->where('id',$sourceIds[1])->update(['resturant_id'=>100]);
        try{verify($request($form,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($copy,'settle')[0]===403,'moving a CURRENT source product out of its original parent blocks cached native copy and settle');}
        finally{DB::table('resturant_products')->where('id',$sourceIds[1])->update(['resturant_id'=>101]);}
        $destination=$after['steps'][1]['target_product'];DB::table('resturant_products')->where('id',$destination)->update(['resturant_id'=>101]);
        try{verify($request($form,[...$proof($ready),'Accept: application/json'])[0]===403&&$decide($copy,'settle')[0]===403,'moving a CURRENT copied product out of its saved destination blocks native copy receipts');}
        finally{DB::table('resturant_products')->where('id',$destination)->update(['resturant_id'=>100]);}
        $ordinary=['_token'=>$serverCsrf[1],'resturant_id'=>'100','restraunt_product'=>['later-key'=>(string)$sourceIds[5],'earlier-key'=>(string)$sourceIds[4]]];
        $ordinaryBefore=$targetRows();[$status,,$headers]=$request($ordinary);$ordinaryCreated=array_values(array_diff(array_column($targetRows(),'id'),array_column($ordinaryBefore,'id')));sort($ordinaryCreated);
        $created=array_merge($created,$ordinaryCreated);$ordinaryMedia=[];foreach($ordinaryCreated as $id)$ordinaryMedia=array_merge($ordinaryMedia,$media($id));$newMedia=array_merge($newMedia,array_column($ordinaryMedia,'id'));
        verify($status===302&&in_array('Location: '.$origin.'/admin/resturants/100?'.$query,$headers,true)&&count($ordinaryCreated)===2
            &&array_column(array_map($product,$ordinaryCreated),'product_id')===[$catalogIds[5],$catalogIds[4]],
            'ordinary online original copy accepts associative selection keys and retains their iteration order without native metadata');
        $ordinaryComplete=true;foreach($ordinaryCreated as $index=>$id){$source=$media($sourceIds[$index===0?5:4])[0];$copied=$media($id);
            $ordinaryComplete=$ordinaryComplete&&count($copied)===1&&$copied[0]['name']===$source['name']&&$copied[0]['file_name']===$source['file_name']
                &&file_get_contents($file($copied[0]))===file_get_contents($file($source))&&!is_dir($profile.'/app/public/'.$source['id']);}
        verify($ordinaryComplete&&$sourcesIntact(),'ordinary online original FileAdder preserves canonical first-image names/bytes and needs no temporary public alias');
        verify(DB::table('resturant_products')->whereIn('id',$sourceIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$sourceRows
            &&DB::table('media')->whereIn('id',$fixtureMediaIds)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()===$sourceMedia&&$sourcesIntact(),
            'all complete original parent product/media rows and every source image byte remain unchanged across native retries and failures');
        verify($fingerprint($created,$newMedia,[$targetId])===$beforeBusiness,'all106 original business tables outside the exact copy price/clone/image effect scope remain unchanged');
        foreach(['GET','HEAD','PUT','PATCH','DELETE'] as $method)verify($decide($reserve($method))[0]===422,'native menu-copy reservations reject the unreviewed '.$method.' method');
        verify($decide($reserve('POST','/admin/resturant/copy/items/'))[0]===422,'native menu-copy reservation requires the exact original path');$completed=true;
    }finally{
        foreach(['reject_native_menu_second_image','reject_native_menu_outcome'] as $trigger)DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        $addedProducts=array_values(array_diff(array_column($targetRows(),'id'),$originalTargetProducts));$addedProducts=array_values(array_unique([...$addedProducts,...$sourceIds]));
        DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->whereIn('model_id',$addedProducts)->delete();
        DB::table('resturant_products')->whereIn('id',$addedProducts)->delete();
        DB::table('products')->whereIn('id',$catalogIds)->delete();DB::table('desktop_dashboard_remote_attempts')->whereIn('id',$attemptIds)->delete();
        DB::table('desktop_dashboard_commands')->whereIn('command_id',$commands)->delete();
        DB::table('users')->where('id',1)->update(['account_type'=>$actorBefore['account_type'],'owner_resturant_id'=>$actorBefore['owner_resturant_id']]);
        DB::table('resturants')->where('id',100)->update(['parent_id'=>$targetBefore['parent_id']]);DB::table('desktop_dashboard_devices')->where('id',$remoteDevice->id)->update(['branches'=>$deviceBefore['branches']]);
        foreach(array_diff(array_keys($files($root)),array_keys($filesBefore)) as $filename)if(is_file($filename))unlink($filename);
    }
    if($completed){verify($fingerprint()===$originalBusiness&&$files($root)===$filesBefore,'native copy fixture restores every original106-table business row and pre-existing product image file');
        echo 'RESTAURANT_MENU_COPY_REMOTE_COMPLETE '.json_encode(['checks'=>$GLOBALS['count']-$startCount,'schemaInventory'=>106,'productColumns'=>13,'mediaColumns'=>18,
            'originalMultipart'=>true,'originalController'=>true,'fullImagesCopied'=>true,'nativeCancellation'=>true,'sourceImagesPreserved'=>true,'sourceAndTargetRevocation'=>true,'fullDashboard'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;}
})();
