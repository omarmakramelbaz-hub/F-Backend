<?php
// Original server POST with native reservation, lost reply and durable terminal outcome.
foreach([90001,90002] as $id)DB::table('categories')->insert(['id'=>$id,'added_by'=>1,'name_ar'=>'قسم نتيجة الترتيب '.$id,'name_en'=>'Order outcome '.$id,'status'=>'show','order'=>0]);
$orderAttempt=$attempt('/admin/post-sortable');[, $orderProof]=$decide($orderAttempt);
$orderForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'order'=>[['id'=>90002,'position'=>1],['id'=>90001,'position'=>2]]];
$currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('category-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
verify($http($orderAttempt['path'],$orderForm,$proof($orderProof))[0]===403&&(int)DB::table('categories')->whereIn('id',[90001,90002])->sum('order')===0,
    'the original server drag endpoint requires category-edit before persisting its reserved result');
$currentRole->givePermissionTo('category-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
DB::statement("CREATE TRIGGER reject_category_order_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.status='committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated category order outcome failure'; END IF; END");
try{verify($http($orderAttempt['path'],$orderForm,$proof($orderProof))[0]===500&&(int)DB::table('categories')->whereIn('id',[90001,90002])->sum('order')===0
    &&DB::table('desktop_dashboard_remote_attempts')->where('id',$orderAttempt['id'])->value('status')==='ready',
    'a failed order outcome write rolls back every original server position and keeps its reservation');}
finally{DB::statement('DROP TRIGGER reject_category_order_outcome');}
[$status,$orderBody]=$http($orderAttempt['path'],$orderForm,$proof($orderProof));
verify($status===200&&(json_decode($orderBody,true)['status']??null)==='success'&&DB::table('categories')->where('id',90001)->value('order')===2
    &&DB::table('categories')->where('id',90002)->value('order')===1&&$decide($orderAttempt,'settle')[1]['status']==='committed',
    'the original server drag controller and exact success response commit with one terminal outcome');
$normal=['_token'=>$serverCsrf[1],'order'=>[['id'=>90001,'position'=>7],['id'=>90002,'position'=>8]]];
verify($http('/admin/post-sortable',$normal)[0]===200&&DB::table('categories')->where('id',90001)->value('order')===7,
    'an ordinary original server drag works without desktop metadata and returns parseable JSON');
$orderForm['order']=array_reverse($orderForm['order']);
verify($http($orderAttempt['path'],$orderForm,$proof($orderProof))[1]===$orderBody&&DB::table('categories')->where('id',90001)->value('order')===7,
    'a lost order response returns its saved result without overwriting a later server order');
$orderRetry=$attempt('/admin/post-sortable');[, $orderRetryProof]=$decide($orderRetry);
verify($http($orderRetry['path'],$orderForm,$proof($orderRetryProof))[1]===$orderBody&&DB::table('categories')->where('id',90002)->value('order')===8,
    'a fresh native transmission of the same order UUID retains the original result without applying it again');
$changed=$orderForm;$changed['order'][0]['position']=4;
verify($http($orderRetry['path'],$changed,$proof($orderRetryProof))[0]===409&&DB::table('categories')->where('id',90001)->value('order')===7,
    'one reserved order UUID cannot be reused for another desired order');
$currentRole->revokePermissionTo('category-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
verify($http($orderRetry['path'],$orderForm,$proof($orderRetryProof))[0]===403,'stored category-order replies still require current original editing authority');
$currentRole->givePermissionTo('category-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$cancelOrder=$attempt('/admin/post-sortable');[, $cancelOrderProof]=$decide($cancelOrder);$decide($cancelOrder,'settle');
$orderForm['_desktop_command']=(string)Str::uuid();
verify($http($cancelOrder['path'],$orderForm,$proof($cancelOrderProof))[0]===409&&DB::table('categories')->where('id',90001)->value('order')===7,
    'a delayed drag POST cannot change positions after its native reservation was cancelled');
foreach([[],[['id'=>90001,'position'=>1],['id'=>999999999,'position'=>2]],[['id'=>90001,'position'=>1],['id'=>90001,'position'=>2]]] as $invalidOrder){
    $normal['order']=$invalidOrder;
    verify($http('/admin/post-sortable',$normal,['Accept: application/json'])[0]===422&&DB::table('categories')->where('id',90001)->value('order')===7,
        'normal server ordering rejects invalid/missing selections without a partial change');
}
