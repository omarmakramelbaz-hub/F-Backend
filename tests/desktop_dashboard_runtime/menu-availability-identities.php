<?php
use Spatie\Permission\Models\{Role,Permission};
use Spatie\Permission\PermissionRegistrar;
use App\Services\Dashboard\OrderBoardMenu;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences,DesktopDashboardMenuAvailability};

// The supported global product creator has genuinely different local/server IDs.
// Existing restaurant/store branch and menu-product IDs have no reviewed creator;
// they remain exact snapshot IDs, and their acknowledged availability receipts
// produce separately typed identities for subsequent operations.
$menuSwitch(true);$localProductLink=DB::table('resturant_products')->where('id',1)->value('product_id');
DB::table('resturant_products')->where('id',1)->update(['product_id'=>$productId]);
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$menuDependent=[];
try{
    for($n=0;$n<100;$n++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
    foreach($menuCommands as $kind=>[$id,$path,$form]){
        $form['idempotency_key']=(string)\Illuminate\Support\Str::uuid();$form['expected_available']=false;$form['expected_revision']=$kind==='gs'?2:null;
        verify($http($path,$form,['Accept: application/json'])[0]===200,'the original already-disabled behavior creates a subsequent typed availability operation: '.$kind);
        $menuDependent[$kind]=$form['idempotency_key'];
    }
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
$menuSwitch(false);$serverProductLink=DB::table('resturant_products')->where('id',1)->value('product_id');
DB::table('resturant_products')->where('id',1)->update(['product_id'=>$serverProduct->id]);
$menuRacePdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach($menuDependent as $kind=>$id){
    $command=$menuEnvelope($id);$menuSwitch(false);$entity=$kind==='f'?'menu_restaurant_product':'menu_store_product';
    verify(($command['payload']['parameters']['product']['$desktop_ref']['entity']??null)===$entity
        &&in_array($menuCommands[$kind][0],$command['dependencies'],true),'a dependent original operation preserves the restaurant/store identity type: '.$kind);
    $resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($kind==='f')verify($productId!==(int)$serverProduct->id&&(int)$resolved['facts']['menu_before']['row']['product_id']===(int)$serverProduct->id,
        'the restaurant product before-facts resolve their actual acknowledged global-product ID drift');
    $wrong=$command;$wrong['payload']['parameters']['product']['$desktop_ref']['entity']=$kind==='f'?'menu_store_product':'menu_restaurant_product';
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$wrong);throw new RuntimeException('Wrong product reference type was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===422&&!DB::table('desktop_dashboard_commands')->where('command_id',$id)->exists(),'a colliding numeric identity cannot cross the restaurant/store product types: '.$kind);}
    $table=$kind==='f'?'resturant_products':'go_store_products';$field=$kind==='f'?'status':'available';$old=DB::table($table)->where('id',1)->value($field);
    DB::beginTransaction();
    try{
        DB::table($table)->where('id',1)->first(); // Establish a snapshot before another connection commits.
        $menuRacePdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=1')->execute([$kind==='f'?'show':1]);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Concurrent availability change was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409,'the locked availability facts detect a real independent commit after an older repeatable-read snapshot: '.$kind);}
    }finally{DB::rollBack();$menuRacePdo->prepare('UPDATE '.$table.' SET '.$field.'=? WHERE id=1')->execute([$old]);}
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt['result']['item']['available']===false&&$receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),
        'the original already-at-target behavior and typed dependent replay remain idempotent: '.$kind);
    verify(auth('admin')->getUser()===null,'availability replay restores the outer authentication context: '.$kind);
    $menuSwitch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);$menuSwitch(false);
}
DB::table('resturant_products')->where('id',1)->update(['product_id'=>$serverProductLink]);$menuSwitch(true);
DB::table('resturant_products')->where('id',1)->update(['product_id'=>$localProductLink]);$menuSwitch(false);

// Warm Spatie's real permission cache, then revoke a role grant outside the
// transaction. The original can() can retain a stale positive role membership.
DB::table('users')->insert(['id'=>62,'added_by'=>1,'name'=>'مدير صلاحيات القائمة','email'=>'menu-admin@test.invalid','mobile'=>'1200000062',
    'password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'admin','status'=>'accepted','app_scope'=>'fasakhansta']);
$menuRole=Role::create(['name'=>'Fixture Menu Operator','guard_name'=>'admin']);
foreach(['order-list','resturant-edit'] as $name)$menuRole->givePermissionTo(Permission::findOrCreate($name,'admin'));
$menuAdmin=User::withoutGlobalScopes()->findOrFail(62);$menuAdmin->assignRole($menuRole);app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($menuAdmin->can('order-list')&&$menuAdmin->can('resturant-edit'),'the menu authority fixture warms the actual cached role permissions');
foreach(['order-list','resturant-edit'] as $name){
    $permission=Permission::findByName($name,'admin');DB::beginTransaction();
    try{
        DB::table('role_has_permissions')->count();
        $menuRacePdo->prepare('DELETE FROM role_has_permissions WHERE role_id=? AND permission_id=?')->execute([$menuRole->id,$permission->id]);
        foreach(['f'=>100,'gs'=>60] as $kind=>$branch){
            try{app(DesktopDashboardMenuAvailability::class)->authorize(['kind'=>$kind,'branchId'=>$branch,'product'=>1],$menuAdmin);throw new RuntimeException('Stale cached menu grant was accepted.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'current locked menu grants reject a warmed cached revocation inside older repeatable-read: '.$name.' '.$kind);}
        }
    }finally{DB::rollBack();$menuRacePdo->prepare('INSERT INTO role_has_permissions (role_id,permission_id) VALUES (?,?)')->execute([$menuRole->id,$permission->id]);}
}
app(PermissionRegistrar::class)->forgetCachedPermissions();
$menuEdit=Permission::findByName('resturant-edit','admin');
DB::table('role_has_permissions')->where('role_id',$menuRole->id)->where('permission_id',$menuEdit->id)->delete();
$menuAdmin=User::withoutGlobalScopes()->findOrFail(62);$menuAdmin->givePermissionTo($menuEdit);app(PermissionRegistrar::class)->forgetCachedPermissions();
verify(User::withoutGlobalScopes()->findOrFail(62)->can('resturant-edit'),'the menu fixture also warms a direct original toggle grant');
DB::beginTransaction();
try{
    DB::table('model_has_permissions')->count();$menuRacePdo->prepare('DELETE FROM model_has_permissions WHERE model_type=? AND model_id=62 AND permission_id=?')->execute([User::class,$menuEdit->id]);
    foreach(['f'=>100,'gs'=>60] as $kind=>$branch){
        try{app(DesktopDashboardMenuAvailability::class)->authorize(['kind'=>$kind,'branchId'=>$branch,'product'=>1],$menuAdmin);throw new RuntimeException('Stale direct menu grant was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'current locked direct grants reject a foreign revocation after an older snapshot: '.$kind);}
    }
}finally{DB::rollBack();DB::table('role_has_permissions')->insert(['role_id'=>$menuRole->id,'permission_id'=>$menuEdit->id]);app(PermissionRegistrar::class)->forgetCachedPermissions();}
// Execute the original server controller for a non-primary enrolled admin, then
// revoke its warmed role grants before requesting that already committed receipt.
$menuAdminLink=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->enroll(['device_id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>'menu permission receipt fixture','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(62));
$menuAdminDevice=app(\App\Services\Dashboard\DesktopDashboardDevices::class)->device($menuAdminLink['token']);
foreach($menuCommands as $kind=>[$id]){
    $command=$menuEnvelope($id);$menuSwitch(false);$command['actor_id']=62;$command['command_id']=(string)\Illuminate\Support\Str::uuid();$command['payload']['values']['idempotency_key']=$command['command_id'];$command['occurred_at']=now('UTC')->toIso8601String();
    $table=$kind==='f'?'resturant_products':'go_store_products';$old=(array)DB::table($table)->where('id',1)->first();
    DB::table($table)->where('id',1)->update($kind==='f'?['status'=>'show']:['available'=>true,'revision'=>1]);
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($menuAdminDevice,$command);
    verify($receipt['committed']&&$receipt['result']['item']['available']===false,'the current non-primary menu administrator commits an original server receipt: '.$kind);
    foreach(['order-list','resturant-edit'] as $name){
        $permission=Permission::findByName($name,'admin');verify(User::withoutGlobalScopes()->findOrFail(62)->can($name),'the committed menu receipt fixture warms its original authority: '.$name.' '.$kind);DB::beginTransaction();
        try{
            DB::table('role_has_permissions')->count();$menuRacePdo->prepare('DELETE FROM role_has_permissions WHERE role_id=? AND permission_id=?')->execute([$menuRole->id,$permission->id]);
            try{app(DesktopDashboardReconciliation::class)->ingest($menuAdminDevice,$command);throw new RuntimeException('A cached menu receipt bypassed a revoked warm role grant.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'a committed menu receipt rechecks the real current grant before dedup inside older repeatable-read: '.$name.' '.$kind);}
        }finally{DB::rollBack();$menuRacePdo->prepare('INSERT INTO role_has_permissions (role_id,permission_id) VALUES (?,?)')->execute([$menuRole->id,$permission->id]);}
    }
    unset($old['id']);DB::table($table)->where('id',1)->update($old);
}
app(PermissionRegistrar::class)->forgetCachedPermissions();
DB::table('users')->where('id',10)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>null]);
try{DB::transaction(fn()=>app(OrderBoardMenu::class)->authorizeAvailability('f',100,1,User::withoutGlobalScopes()->findOrFail(10)));
    verify(true,'the original restaurant-owner fallback with no parent still authorizes its own restaurant user_id');}
finally{DB::table('users')->where('id',10)->update(['account_type'=>'vendor']);}
DB::beginTransaction();
try{
    DB::table('resturants')->where('id',100)->first();$menuRacePdo->exec('UPDATE resturants SET user_id=11 WHERE id=100');
    try{app(DesktopDashboardMenuAvailability::class)->authorize(['kind'=>'f','branchId'=>100,'product'=>1],User::withoutGlobalScopes()->findOrFail(10));throw new RuntimeException('Old restaurant ownership was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===404,'a current locked restaurant row rejects ownership changed after the transaction snapshot');}
}finally{DB::rollBack();$menuRacePdo->exec('UPDATE resturants SET user_id=10 WHERE id=100');}

$command=$menuEnvelope($menuCommands['gs'][0]);$menuSwitch(false);DB::beginTransaction();
try{
    DB::table('users')->where('id',60)->first();$menuRacePdo->exec("UPDATE users SET status='declined' WHERE id=60");
    verify(app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command)['committed'],
        'an owner status change preserves the original central-admin menu authority rather than applying POS scope policy');
}finally{DB::rollBack();$menuRacePdo->exec("UPDATE users SET status='accepted' WHERE id=60");}
$pendingId=DB::table('pending_vendors')->insertGetId(['full_name'=>'متجر مندوب الاختبار','mobile'=>'1200000060','type'=>'delegate','status'=>'accepted','source_app'=>'go_partner','profession_key'=>'store_owner']);
DB::table('users')->where('id',60)->update(['account_type'=>'delegate','pending_vendor_id'=>$pendingId]);DB::beginTransaction();
try{
    DB::table('pending_vendors')->where('id',$pendingId)->first();$menuRacePdo->prepare("UPDATE pending_vendors SET profession_key='courier' WHERE id=?")->execute([$pendingId]);
    try{app(DesktopDashboardMenuAvailability::class)->authorize(['kind'=>'gs','branchId'=>60,'product'=>1],User::withoutGlobalScopes()->findOrFail(1));throw new RuntimeException('Old store-owner profession was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===404,'a current locked delegate profession rejects a changed store-owner relationship after an older snapshot');}
}finally{DB::rollBack();DB::table('users')->where('id',60)->update(['account_type'=>'vendor','pending_vendor_id'=>null]);DB::table('pending_vendors')->where('id',$pendingId)->delete();}
