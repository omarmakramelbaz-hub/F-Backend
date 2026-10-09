<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Models\User;
use Spatie\Permission\Models\{Role,Permission};
use Spatie\Permission\PermissionRegistrar;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences,DesktopDashboardRoleFacts};

$roleSwitch=function(bool $local)use($ownerStage,$database){
    config(['database.connections.mysql.database'=>$local?$ownerStage:$database,'desktop_dashboard.local'=>$local]);DB::purge();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
};
$roleSwitch(true);
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$roleCommands=[];$roleIds=[];$selectedPermission=(string)Permission::findByName('product-list','admin')->id;
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/roles/create');preg_match('/name="_token" value="([^"]+)"/',$page,$roleCsrf);
    verify($status===200&&isset($roleCsrf[1])&&str_contains($page,'name="permission[]"')&&str_contains($page,'desktop-dashboard.js'),'the original role name, permission checkboxes and controller form remain available offline');
    $roleForm=['_token'=>$roleCsrf[1],'_desktop_command'=>(string)Str::uuid(),'name'=>'دور محلي أصلي','permission'=>[$selectedPermission],'permi'=>'product'];
    verify($http('/admin/roles',$roleForm)[0]===302,'the original role controller creates its local role and permission pivot with one command');$roleCommands[]=$roleForm['_desktop_command'];
    $firstRole=(int)DB::table('roles')->where('name',$roleForm['name'])->value('id');$roleIds[]=$firstRole;
    verify($firstRole>0&&Role::findOrFail($firstRole)->guard_name===\Spatie\Permission\Guard::getDefaultName(Role::class)
        &&Role::findOrFail($firstRole)->permissions()->pluck('name')->all()===['product-list'],'the original Role model chooses its actual guard and selected permission');
    verify($http('/admin/roles',$roleForm)[0]===302&&DB::table('roles')->where('name',$roleForm['name'])->count()===1,'a lost original role creation reply retains the UUID and creates no second role');
    $localAuthority=Role::findOrFail($role->id);$localAuthority->revokePermissionTo('role-create');app(PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/roles',$roleForm)[0]===403&&DB::table('roles')->where('name',$roleForm['name'])->count()===1,'a saved local role creation response cannot bypass its currently revoked original permission');
    $localAuthority->givePermissionTo('role-create');app(PermissionRegistrar::class)->forgetCachedPermissions();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$roleForm['_desktop_command'])->first();$rolePayload=json_decode(Crypt::decryptString($row->command_cipher),true);
    verify(array_keys($rolePayload['facts'])===['catalog_before','role_permissions']&&$rolePayload['facts']['role_permissions']['permissions']===[['input'=>$selectedPermission,'name'=>'product-list','guard_name'=>'admin']]
        &&$rolePayload['files']===[],'the role journal binds only its selected permission identity and no unrelated user, credential or assignment data');
    [$status,$page]=$http('/admin/roles/'.$firstRole.'/edit');
    verify($status===200&&str_contains($page,'name="permission[]"')&&str_contains($page,'value="دور محلي أصلي"'),'the original role edit form retains its name and permission controls');
    $roleForm['_method']='PUT';$roleForm['_desktop_command']=(string)Str::uuid();$roleForm['name']='دور محلي معدل';$roleForm['permission']=[(string)Permission::findByName('category-list','admin')->id];
    verify($http('/admin/roles/'.$firstRole,$roleForm)[0]===302&&Role::findOrFail($firstRole)->permissions()->pluck('name')->all()===['category-list'],'the original role update replaces permissions and captures its complete role before-state');$roleCommands[]=$roleForm['_desktop_command'];
    verify($http('/admin/roles/'.$firstRole,$roleForm)[0]===302,'a lost role update reply reuses its saved before-state and response');
    verify(app(DesktopDashboardRoleFacts::class)->beforeMembership($firstRole)===hash('sha256','[]'),'a typed locally created role uses only its actual empty assignment set');
    User::withoutGlobalScopes()->findOrFail(21)->assignRole(Role::findOrFail($firstRole));
    try{app(DesktopDashboardRoleFacts::class)->beforeMembership($firstRole);throw new RuntimeException('Unreviewed local assignment was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $failure){verify($failure->getStatusCode()===501,'an unexpected assignment to a new local role fails closed instead of inventing assignment coverage');}
    User::withoutGlobalScopes()->findOrFail(21)->removeRole(Role::findOrFail($firstRole));
    $roleState=DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->first();$roleCoverage=json_decode($roleState->coverage,true);
    verify(app(DesktopDashboardRoleFacts::class)->beforeMembership($role->id)===$ownerSnapshot['coverage']['role_memberships'][(string)$role->id],'an original role uses the membership proof from its enrolled imported snapshot');
    unset($roleCoverage['role_memberships'][(string)$role->id]);DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['coverage'=>json_encode($roleCoverage)]);
    try{app(DesktopDashboardRoleFacts::class)->beforeMembership($role->id);throw new RuntimeException('Missing membership proof was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $failure){verify($failure->getStatusCode()===501,'an older snapshot without a role membership proof cannot mutate that original role');}
    $roleCoverage['role_memberships'][(string)$role->id]='malformed';DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['coverage'=>json_encode($roleCoverage)]);
    try{app(DesktopDashboardRoleFacts::class)->beforeMembership($role->id);throw new RuntimeException('Malformed membership proof was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $failure){verify($failure->getStatusCode()===501,'a role membership proof must be an exact SHA256 digest');}
    DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['coverage'=>$roleState->coverage]);
    $before=DB::table('desktop_dashboard_commands')->count();$invalidRole=$roleForm;unset($invalidRole['_method']);$invalidRole['_desktop_command']=(string)Str::uuid();$invalidRole['name']='a';
    verify($http('/admin/roles',$invalidRole)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'the unchanged original role validation redirects without a success journal for an invalid name');
    $invalidRole['name']='حقول غير مقبولة';$invalidRole['permission']=[['unexpected'=>'value']];
    verify($http('/admin/roles',$invalidRole)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'the original role permission string validation still rejects nested arrays without creating a role');
    $invalidRole['permission']=[$selectedPermission];$invalidRole['model_has_roles']=[20];
    verify($http('/admin/roles',$invalidRole)[0]===422&&DB::table('desktop_dashboard_commands')->count()===$before,'unrelated user assignment fields cannot enter a reviewed role operation');
    unset($roleForm['_method']);$roleForm['_desktop_command']=(string)Str::uuid();$roleForm['name']='دور محلي للحذف';$roleForm['permission']=[$selectedPermission];
    verify($http('/admin/roles',$roleForm)[0]===302,'the original role form creates its disposable deletion role');$roleCommands[]=$roleForm['_desktop_command'];
    $secondRole=(int)DB::table('roles')->where('name',$roleForm['name'])->value('id');$roleIds[]=$secondRole;
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    verify(app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$secondRole),'the persisted permission cache is warmed with the role that the original controller will delete');
    $removeRole=['_token'=>$roleCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http('/admin/roles/'.$secondRole,$removeRole)[0]===302&&!DB::table('roles')->where('id',$secondRole)->exists()
        &&$http('/admin/roles/'.$secondRole,$removeRole)[0]===302,'the original role deletion retries its saved UUID after its scalar role ID is gone');$roleCommands[]=$removeRole['_desktop_command'];
    $localAuthority=Role::findOrFail($role->id);$localAuthority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
    verify($http('/admin/roles/'.$secondRole,$removeRole)[0]===403,'a saved local deletion reply after the role ID disappears still requires currently valid original authority');
    $localAuthority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->clearClassPermissions();
    verify(!app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$secondRole),'the original direct role deletion invalidates the warmed persisted cache so removed permission associations cannot linger');
    verify($http('/admin/rolesDeleteAll',['_token'=>$roleCsrf[1],'ids'=>(string)$firstRole],[],'DELETE')[0]===501&&DB::table('roles')->where('id',$firstRole)->exists(),'role bulk deletion remains explicitly unavailable without reviewed coverage');
    $webPermission=Permission::create(['name'=>'fixture-role-web-permission','guard_name'=>'web']);
    $webFacts=app(DesktopDashboardRoleFacts::class)->selected(['permission'=>[(string)$webPermission->id]],['row'=>['guard_name'=>'web']]);
    verify($webFacts['guard_name']==='web'&&$webFacts['permissions'][0]['guard_name']==='web','permission facts follow an existing role model guard instead of inventing an admin guard for every role');
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}

$roleSwitch(false);
$roleCacheEnv=$env;$roleCacheEnv['DB_DATABASE']=$database;$roleCacheEnv['DESKTOP_TEST_APPLICATION']=$application;$roleCacheEnv['DESKTOP_DASHBOARD_ENABLED']='true';
$roleCacheWeb=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,__DIR__.'/server-router.php'],[['pipe','r'],['file',$profile.'/roles-cache.log','a'],['file',$profile.'/roles-cache.log','a']],$roleCachePipes,$application,$roleCacheEnv);
for($n=0;$n<100;$n++){[$status]=$http('/admin/roles');if($status===200)break;usleep(50000);}
verify($status===200,'a second original server process can read committed roles for the real shared-cache race fixture');
$roleCacheRaceArmed=false;$roleCacheRepopulated=false;
DB::listen(function($query)use(&$roleCacheRaceArmed,&$roleCacheRepopulated,$http){
    if(!$roleCacheRaceArmed||!str_starts_with(strtolower($query->sql),'insert into `desktop_dashboard_commands`'))return;
    $roleCacheRaceArmed=false;
    verify($http('/admin/roles')[0]===200,'another actual server request repopulates the previous committed permissions cache before the role transaction commits');
    $roleCacheRepopulated=true;
});
try{
foreach($roleIds as $id)DB::table('roles')->insert(['id'=>$id,'name'=>'دور سيرفر مستقل '.$id,'guard_name'=>'admin','created_at'=>now(),'updated_at'=>now()]);
// The same numeric permission now names an unrelated server permission; its intended
// name moved to another ID without changing existing users' authority.
$oldPermission=Permission::findByName('product-list','admin');$oldPermissionId=(int)$oldPermission->id;
$movedPermission=Permission::create(['name'=>'fixture-role-permission-new-id','guard_name'=>'admin']);$movedPermissionId=(int)$movedPermission->id;
DB::table('role_has_permissions')->where('permission_id',$oldPermissionId)->update(['permission_id'=>$movedPermissionId]);
DB::table('model_has_permissions')->where('permission_id',$oldPermissionId)->update(['permission_id'=>$movedPermissionId]);
DB::table('permissions')->where('id',$oldPermissionId)->update(['name'=>'fixture-unrelated-permission-at-old-id']);
DB::table('permissions')->where('id',$movedPermissionId)->update(['name'=>'product-list']);app(PermissionRegistrar::class)->forgetCachedPermissions();
foreach($roleCommands as $id){
    $roleSwitch(true);$row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    $roleSwitch(false);$resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    $permission=match($row->route_name){'roles.store'=>'role-create','roles.update'=>'role-edit','roles.destroy'=>'role-delete'};
    $authority=Role::findOrFail($role->id);$authority->revokePermissionTo($permission);app(PermissionRegistrar::class)->forgetCachedPermissions();
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked role authority was accepted.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'current original role authority is required before replay: '.$row->route_name);}
    $authority->givePermissionTo($permission);app(PermissionRegistrar::class)->forgetCachedPermissions();
    if($row->route_name!=='roles.destroy'){
        $collisionName=$resolved['values']['name'];$collision=Role::create(['name'=>$collisionName,'guard_name'=>$resolved['facts']['catalog_before']['row']['guard_name']??$resolved['facts']['role_permissions']['guard_name']]);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('A later server role name collision was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $failure){verify($failure->getStatusCode()===409&&DB::table('roles')->where('id',$collision->id)->exists()
            &&!DB::table('desktop_dashboard_commands')->where('command_id',$id)->exists(),'a later server role name collision becomes a retained conflict without a partial role, pivots or receipt: '.$row->route_name);}
        $collision->delete();app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    if($row->route_name==='roles.store'){
        DB::table('permissions')->where('id',$movedPermissionId)->update(['name'=>'fixture-renamed-permission']);app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Renamed permission was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&!DB::table('roles')->where('name',$resolved['values']['name'])->exists(),'a removed permission name retains the local role operation and never grants the unrelated colliding permission ID');}
        DB::table('permissions')->where('id',$movedPermissionId)->update(['name'=>'product-list']);app(PermissionRegistrar::class)->forgetCachedPermissions();
    }else{
        $mappedRole=(int)$resolved['parameters']['role'];verify(!in_array($mappedRole,$roleIds,true),'the typed original role reference maps around unrelated colliding server role IDs');
        $originalName=DB::table('roles')->where('id',$mappedRole)->value('name');DB::table('roles')->where('id',$mappedRole)->update(['name'=>'تغيير مستقل على السيرفر']);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed server role was overwritten.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('roles')->where('id',$mappedRole)->value('name')==='تغيير مستقل على السيرفر','a changed server role before-state conflicts without overwriting its independent revision');}
        DB::table('roles')->where('id',$mappedRole)->update(['name'=>$originalName]);
        $changedRole=Role::findOrFail($mappedRole);$changedRole->givePermissionTo('category-list');app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed server permission pivot was overwritten.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&Role::findOrFail($mappedRole)->hasPermissionTo('category-list'),'changed role permission pivots are part of the reviewed before-state, independently of the role name');}
        $changedRole->revokePermissionTo('category-list');app(PermissionRegistrar::class)->forgetCachedPermissions();
        if($row->route_name==='roles.update'){
            $beforeMembership=app(DesktopDashboardRoleFacts::class)->membershipHash($mappedRole);DB::beginTransaction();
            try{
                DB::table('model_has_roles')->count(); // Establish an older repeatable-read view.
                $racePdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                $insertMember=$racePdo->prepare('INSERT INTO model_has_roles (role_id,model_type,model_id) VALUES (?,?,?)');$insertMember->execute([$mappedRole,User::class,21]);
                verify(app(DesktopDashboardRoleFacts::class)->membershipHash($mappedRole)!==$beforeMembership,'the locked membership proof sees a committed foreign assignment even after an older transaction snapshot was established');
            }finally{
                DB::rollBack();$racePdo->prepare('DELETE FROM model_has_roles WHERE role_id=? AND model_type=? AND model_id=?')->execute([$mappedRole,User::class,21]);
            }
        }
        User::withoutGlobalScopes()->findOrFail(21)->assignRole(Role::findOrFail($mappedRole));app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('A newly assigned server role was mutated unnoticed.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('model_has_roles')->where('role_id',$mappedRole)->where('model_id',21)->exists(),'a newly assigned server member conflicts before an offline role update or deletion affects that account');}
        User::withoutGlobalScopes()->findOrFail(21)->removeRole(Role::findOrFail($mappedRole));app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $roleCacheRaceArmed=$row->route_name==='roles.update';$receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    if($row->route_name==='roles.update'){
        app(PermissionRegistrar::class)->clearClassPermissions();
        verify($roleCacheRepopulated&&!app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$mappedRole),'the after-commit original role cache flush removes the stale grant repopulated by a concurrent real request');
    }
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'the original role controller reconciles exactly once including repeats after deletion: '.$row->route_name);
    if($row->route_name==='roles.store'){
        $newRole=(int)$receipt['references'][0]['server_id'];
        verify(Role::findOrFail($newRole)->permissions()->pluck('id')->all()===[$movedPermissionId],'the replayed original role form grants the named permission at its new server ID and never the unrelated old ID');
    }
    $authority=Role::findOrFail($role->id);$authority->revokePermissionTo($permission);app(PermissionRegistrar::class)->forgetCachedPermissions();
    try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('A stored role receipt bypassed current permission.');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'even a stored reconciled role receipt requires its current original permission: '.$row->route_name);}
    $authority->givePermissionTo($permission);app(PermissionRegistrar::class)->forgetCachedPermissions();
    $roleSwitch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
$roleSwitch(false);
verify(DB::table('roles')->whereIn('id',$roleIds)->count()===count($roleIds)&&DB::table('roles')->where('name','دور محلي معدل')->count()===1,'role reconciliation preserves every unrelated server role and one exact final local role');
DB::table('permissions')->where('id',$movedPermissionId)->update(['name'=>'fixture-role-permission-new-id']);
DB::table('permissions')->where('id',$oldPermissionId)->update(['name'=>'product-list']);
DB::table('role_has_permissions')->where('permission_id',$movedPermissionId)->update(['permission_id'=>$oldPermissionId]);
DB::table('model_has_permissions')->where('permission_id',$movedPermissionId)->update(['permission_id'=>$oldPermissionId]);
DB::table('permissions')->where('id',$movedPermissionId)->delete();app(PermissionRegistrar::class)->forgetCachedPermissions();
}finally{fclose($roleCachePipes[0]);proc_terminate($roleCacheWeb);proc_close($roleCacheWeb);}
