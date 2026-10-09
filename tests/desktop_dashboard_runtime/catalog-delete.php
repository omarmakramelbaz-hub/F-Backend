<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

// Original form deletion must retry before implicit model binding tries to load the deleted row.
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$deleteCommands=[];
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/products/create');preg_match('/name="_token" value="([^"]+)"/',$page,$deleteCsrf);
    $createCategory=['_token'=>$deleteCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'name_ar'=>'قسم للحذف المحلي','name_en'=>'Delete fixture','status'=>'show'];
    verify($http('/admin/categorys',$createCategory)[0]===302,'an original category can be created for a later offline deletion');$deleteCommands[]=$createCategory['_desktop_command'];
    $deleteCategoryId=(int)DB::table('categories')->where('name_ar',$createCategory['name_ar'])->value('id');
    $createProduct=['_token'=>$deleteCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'category_id'=>$deleteCategoryId,'name_ar'=>'صنف للحذف المحلي','status'=>'show'];
    verify($http('/admin/products',$createProduct)[0]===302,'an original product retains its created category dependency before deletion');$deleteCommands[]=$createProduct['_desktop_command'];
    $deleteProductId=(int)DB::table('products')->where('name_ar',$createProduct['name_ar'])->value('id');
    foreach([['products',$deleteProductId,'products'],['categorys',$deleteCategoryId,'categories']] as [$uri,$id,$table]){
        $remove=['_token'=>$deleteCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];$path='/admin/'.$uri.'/'.$id;
        verify($http($path,$remove)[0]===302&&!DB::table($table)->where('id',$id)->exists(),'original local deletion commits with its encrypted command: '.$uri);
        [$retryStatus,$retryBody]=$http($path,$remove);
        verify($retryStatus===302,'a lost deletion reply retries its UUID before looking up the removed model: '.$uri);
        $changed=$remove;$changed['parent']='different';
        verify($http($path,$changed)[0]!==302,'a changed delete retry cannot reuse its old operation UUID: '.$uri);
        $deleteCommands[]=$remove['_desktop_command'];
    }
    $bulkCategories=[];$bulkProducts=[];
    foreach([1,2] as $index){
        $createCategory['_desktop_command']=(string)Str::uuid();$createCategory['name_ar']='قسم دفعة حذف '.$index;
        verify($http('/admin/categorys',$createCategory)[0]===302,'the original form creates a category for multi-row deletion');$deleteCommands[]=$createCategory['_desktop_command'];
        $categoryId=(int)DB::table('categories')->where('name_ar',$createCategory['name_ar'])->value('id');$bulkCategories[]=$categoryId;
        $createProduct['_desktop_command']=(string)Str::uuid();$createProduct['name_ar']='صنف دفعة حذف '.$index;$createProduct['category_id']=$categoryId;
        verify($http('/admin/products',$createProduct)[0]===302,'the original form creates a product with a typed bulk-delete dependency');$deleteCommands[]=$createProduct['_desktop_command'];
        $bulkProducts[]=(int)DB::table('products')->where('name_ar',$createProduct['name_ar'])->value('id');
    }
    foreach([['products',$bulkProducts,'products'],['categorys',$bulkCategories,'categories']] as [$uri,$ids,$table]){
        $remove=['_token'=>$deleteCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($ids))];
        $path='/admin/'.$uri.'DeleteAll';[$status,$body]=$http($path,$remove,['Accept: application/json']);
        verify($status===200&&!empty(json_decode($body,true)['success'])&&DB::table($table)->whereIn('id',$ids)->count()===0,'original bulk deletion journals all selected rows atomically: '.$uri);
        $remove['ids']=implode(',',$ids);
        verify($http($path,$remove,['Accept: application/json'])[0]===200,'the same bulk-delete UUID returns its receipt after all rows disappear: '.$uri);
        $deleteCommands[]=$remove['_desktop_command'];
    }
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
foreach($deleteCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if(str_ends_with($row->route_name,'.destroy-all')){
        $table=$row->route_name==='products.destroy-all'?'products':'categories';$selectedIds=$resolved['values']['ids'];
        $firstName=DB::table($table)->where('id',$selectedIds[0])->value('name_ar');
        DB::table($table)->where('id',$selectedIds[0])->update(['name_ar'=>$firstName.' تغير على السيرفر']);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed bulk deletion was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table($table)->whereIn('id',$selectedIds)->count()===count($selectedIds),
            'one changed server row rejects the entire bulk deletion without removing other selections: '.$table);}
        DB::table($table)->where('id',$selectedIds[0])->update(['name_ar'=>$firstName]);
    }
    if(in_array($row->route_name,['products.destroy','products.destroy-all'],true)){
        $protectedIds=$row->route_name==='products.destroy-all'?$resolved['values']['ids']:[$resolved['parameters']['product']];
        $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('product-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked deletion was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('products')->whereIn('id',$protectedIds)->count()===count($protectedIds),'revoked original deletion permission preserves every selected server product');}
        $currentRole->givePermissionTo('product-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'original catalog create/delete reconciles once despite removed model bindings: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('products')->where('name_ar','like','%حذف%')->count()===0&&DB::table('categories')->where('name_ar','like','%حذف%')->count()===0,
    'mapped original delete controllers remove only their own newly created catalog entities');
verify(DB::table('products')->where('id',$deleteProductId)->exists()&&DB::table('categories')->where('id',$deleteCategoryId)->exists(),'unrelated server catalog rows with colliding local IDs survive reconciliation');
