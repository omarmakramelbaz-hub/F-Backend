<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Models\User;
use Spatie\Permission\Models\{Role,Permission};
use Spatie\Permission\PermissionRegistrar;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

// The existing checkbox button posts CSV IDs to the unchanged original controller.
$roleSwitch(true);$bulkRoleCommands=[];$bulkCreatedRoleIds=[];
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/roles');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$bulkRoleCsrf);
    verify($status===200&&isset($bulkRoleCsrf[1])&&str_contains($page,'admin/rolesDeleteAll')&&str_contains($page,'delete_all'),'the original role selection button and exact bulk DELETE endpoint remain available offline');
    $ownerCookies=$cookies;$cookies=[];[$status,$loginPage]=$http('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$loginPage,$branchBulkCsrf);
    $http('/admin/signin',['_token'=>$branchBulkCsrf[1],'email'=>'branch@test.invalid','password'=>'Fixture123']);
    verify($http('/admin/rolesDeleteAll',['_token'=>$branchBulkCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',$snapshotBulkRoleIds)],['Accept: application/json'],'DELETE')[0]===403
        &&DB::table('roles')->whereIn('id',$snapshotBulkRoleIds)->count()===2,'an ordinary branch login cannot acquire global role bulk administration');$cookies=$ownerCookies;
    foreach([1,2] as $index){
        $create=['_token'=>$bulkRoleCsrf[1],'_desktop_command'=>(string)Str::uuid(),'name'=>'دور دفعة محلية '.$index,'permission'=>[(string)Permission::findByName('product-list','admin')->id]];
        verify($http('/admin/roles',$create)[0]===302,'the original role form creates a typed dependency for bulk deletion');
        $bulkCreatedRoleIds[]=(int)DB::table('roles')->where('name',$create['name'])->value('id');$bulkRoleCommands[]=$create['_desktop_command'];
    }
    foreach([$snapshotBulkRoleIds,$bulkCreatedRoleIds] as $selectionIndex=>$ids){
        $remove=['_token'=>$bulkRoleCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($ids))];
        $beforeCommands=DB::table('desktop_dashboard_commands')->count();
        foreach(['','0',implode(',',[$ids[0],$ids[0]]),implode(',',array_fill(0,201,$ids[0]))] as $invalidIds){
            $invalid=$remove;$invalid['ids']=$invalidIds;
            verify($http('/admin/rolesDeleteAll',$invalid,['Accept: application/json'],'DELETE')[0]===422&&DB::table('roles')->whereIn('id',$ids)->count()===2
                &&DB::table('desktop_dashboard_commands')->count()===$beforeCommands,'malformed or repeated role IDs cannot queue or partially delete a selection');
        }
        $invalid=$remove;$invalid['model_has_roles']=[21];
        verify($http('/admin/rolesDeleteAll',$invalid,['Accept: application/json'],'DELETE')[0]===422&&DB::table('roles')->whereIn('id',$ids)->count()===2,'unreviewed membership fields cannot enter a role bulk deletion');
        $invalid=$remove;$invalid['ids']=$ids[0].',99999999';
        verify($http('/admin/rolesDeleteAll',$invalid,['Accept: application/json'],'DELETE')[0]===404&&DB::table('roles')->whereIn('id',$ids)->count()===2
            &&DB::table('desktop_dashboard_commands')->count()===$beforeCommands,'an unknown role in a new local selection retains its original 404 without deleting or queuing any role');
        $authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        verify($http('/admin/rolesDeleteAll',$remove,['Accept: application/json'],'DELETE')[0]===403&&DB::table('roles')->whereIn('id',$ids)->count()===2,'current original role-delete authority protects every local selected role before the first write');
        $authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        if($selectionIndex===0){
            $state=DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->first();$coverage=json_decode($state->coverage,true);
            unset($coverage['role_memberships'][(string)$ids[1]]);DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['coverage'=>json_encode($coverage)]);
            verify($http('/admin/rolesDeleteAll',$remove,['Accept: application/json'],'DELETE')[0]===501&&DB::table('roles')->whereIn('id',$ids)->count()===2
                &&DB::table('desktop_dashboard_commands')->count()===$beforeCommands,'one missing imported membership proof keeps the complete role bulk selection intact');
            DB::table('desktop_dashboard_local_state')->where('device_id',$ownerDevice)->update(['coverage'=>$state->coverage]);
        }
        if($selectionIndex===1){
            DB::unprepared("CREATE TRIGGER fixture_reject_bulk_role_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.command_id='".$remove['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture role bulk journal rollback'; END IF; END");
            try{verify($http('/admin/rolesDeleteAll',$remove,['Accept: application/json'],'DELETE')[0]===500&&DB::table('roles')->whereIn('id',$ids)->count()===2
                &&DB::table('role_has_permissions')->whereIn('role_id',$ids)->count()===2&&DB::table('desktop_dashboard_commands')->count()===$beforeCommands,'a failed local bulk journal insert restores every original role and permission pivot');}
            finally{DB::unprepared('DROP TRIGGER fixture_reject_bulk_role_journal');}
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        verify(app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$ids[0]),'the shared permission cache is warmed before the original role mass deletion');
        [$status,$body]=$http('/admin/rolesDeleteAll',$remove,['Accept: application/json'],'DELETE');
        verify($status===200&&json_decode($body,true)===['success'=>trans('messages.RecordsDeleteSuccessfully')]&&DB::table('roles')->whereIn('id',$ids)->count()===0
            &&DB::table('role_has_permissions')->whereIn('role_id',$ids)->count()===0,'the original role bulk JSON response and all permission cascades commit in one local command');
        $remove['ids']=implode(',',$ids);[$retryStatus,$retryBody]=$http('/admin/rolesDeleteAll',$remove,['Accept: application/json'],'DELETE');
        verify($retryStatus===200&&$retryBody===$body&&DB::table('desktop_dashboard_commands')->count()===$beforeCommands+1,'a reordered lost bulk role reply reuses the exact saved response after all selected role IDs disappear');
        $authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        verify($http('/admin/rolesDeleteAll',$remove,['Accept: application/json'],'DELETE')[0]===403,'even a saved local bulk role reply requires the currently valid original deletion permission');
        $authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->clearClassPermissions();
        verify(!app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$ids[0]),'original mass role deletion invalidates the real persisted permission cache');
        $row=DB::table('desktop_dashboard_commands')->where('command_id',$remove['_desktop_command'])->first();$payload=json_decode(Crypt::decryptString($row->command_cipher),true);
        verify(array_keys($payload['facts'])===['catalog_rows']&&count($payload['facts']['catalog_rows'])===2
            &&array_keys($payload['facts']['catalog_rows'][0]['state'])===['row','features','permissions','membership_sha256']
            &&!isset($payload['facts']['role_permissions']),'bulk role before-state retains each guard, named permission set and membership digest without role-form grant facts');
        if($selectionIndex===0)verify($payload['facts']['catalog_rows'][0]['state']['membership_sha256']===$ownerSnapshot['coverage']['role_memberships'][(string)$ids[0]],'imported role deletion binds its foreign assignment set to the original bootstrap snapshot');
        $bulkRoleCommands[]=$remove['_desktop_command'];
    }
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}

$roleSwitch(false);$bulkCollisions=[];
foreach($bulkCreatedRoleIds as $id){
    if(!DB::table('roles')->where('id',$id)->exists())DB::table('roles')->insert(['id'=>$id,'name'=>'دور سيرفر مستقل '.$id,'guard_name'=>'admin']);
    $bulkCollisions[$id]=DB::table('roles')->where('id',$id)->value('name');
}
$cacheEnv=$env;$cacheEnv['DB_DATABASE']=$database;$cacheEnv['DESKTOP_TEST_APPLICATION']=$application;$cacheEnv['DESKTOP_DASHBOARD_ENABLED']='true';
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,__DIR__.'/server-router.php'],[['pipe','r'],['file',$profile.'/bulk-role-cache.log','a'],['file',$profile.'/bulk-role-cache.log','a']],$pipes,$application,$cacheEnv);
for($n=0;$n<100;$n++){[$status]=$http('/admin/roles');if($status===200)break;usleep(50000);}
verify($status===200,'a second actual server process is ready for the bulk role shared-cache commit race');
$bulkCacheArmed=false;$bulkCacheRepopulated=false;$bulkCacheRole=0;
DB::listen(function($query)use(&$bulkCacheArmed,&$bulkCacheRepopulated,&$bulkCacheRole,$http){
    if(!$bulkCacheArmed||!str_starts_with(strtolower($query->sql),'insert into `desktop_dashboard_commands`'))return;
    $bulkCacheArmed=false;verify($http('/admin/roles')[0]===200,'a concurrent actual server request repopulates old bulk role grants between journal insertion and commit');
    app(PermissionRegistrar::class)->clearClassPermissions();
    $bulkCacheRepopulated=app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$bulkCacheRole);
    verify($bulkCacheRepopulated,'the bulk race fixture confirms that the old committed role really re-entered the shared cache before commit');
});
try{foreach($bulkRoleCommands as $id){
    $roleSwitch(true);$row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    $roleSwitch(false);$resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($row->route_name==='roles.destroy-all'){
        $ids=$resolved['values']['ids'];$original=DB::table('roles')->where('id',$ids[1])->first();
        $missingRole=(array)DB::table('roles')->where('id',$ids[0])->first();
        $missingPermissions=DB::table('role_has_permissions')->where('role_id',$ids[0])->get()->map(fn($pivot)=>(array)$pivot)->all();
        $missingMembers=DB::table('model_has_roles')->where('role_id',$ids[0])->get()->map(fn($pivot)=>(array)$pivot)->all();
        $survivorPermissions=DB::table('role_has_permissions')->where('role_id',$ids[1])->get()->map(fn($pivot)=>(array)$pivot)->all();
        DB::table('roles')->where('id',$ids[0])->delete();app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('A missing selected server role was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&str_contains($error->getMessage(),'الأدوار')
            &&DB::table('roles')->whereIn('id',$ids)->count()===1&&(array)DB::table('roles')->where('id',$ids[1])->first()===(array)$original
            &&DB::table('role_has_permissions')->where('role_id',$ids[1])->get()->map(fn($pivot)=>(array)$pivot)->all()===$survivorPermissions
            &&!DB::table('desktop_dashboard_commands')->where('command_id',$id)->exists(),'one independently deleted server role reports a reviewable 409 and preserves every surviving selected role and pivot without a success receipt');}
        DB::table('roles')->insert($missingRole);if($missingPermissions)DB::table('role_has_permissions')->insert($missingPermissions);if($missingMembers)DB::table('model_has_roles')->insert($missingMembers);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        verify((array)DB::table('roles')->where('id',$ids[0])->first()===$missingRole
            &&DB::table('role_has_permissions')->where('role_id',$ids[0])->get()->map(fn($pivot)=>(array)$pivot)->all()===$missingPermissions
            &&DB::table('model_has_roles')->where('role_id',$ids[0])->get()->map(fn($pivot)=>(array)$pivot)->all()===$missingMembers,'the missing role is restored with its complete row, permission and member before-state before retrying the retained UUID');
        foreach(['name'=>'مراجعة مستقلة لاسم الدور','guard_name'=>'web'] as $field=>$changed){
            DB::table('roles')->where('id',$ids[1])->update([$field=>$changed]);
            try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed role batch was accepted.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('roles')->whereIn('id',$ids)->count()===2,'one changed role '.$field.' preserves the entire server deletion selection');}
            DB::table('roles')->where('id',$ids[1])->update([$field=>$original->$field]);
        }
        $extraPermission=$resolved['facts']['catalog_rows'][1]['state']['permissions'][0]['name']==='category-list'?'product-list':'category-list';
        Role::findOrFail($ids[1])->givePermissionTo($extraPermission);app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed bulk permissions were accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('roles')->whereIn('id',$ids)->count()===2,'one changed role permission set prevents deletion of every selected server role');}
        Role::findOrFail($ids[1])->revokePermissionTo($extraPermission);User::withoutGlobalScopes()->findOrFail(20)->assignRole(Role::findOrFail($ids[1]));app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed bulk members were accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('roles')->whereIn('id',$ids)->count()===2
            &&DB::table('model_has_roles')->where('role_id',$ids[1])->where('model_id',20)->exists(),'one new server assignment preserves the whole role batch and its independently assigned member');}
        User::withoutGlobalScopes()->findOrFail(20)->removeRole(Role::findOrFail($ids[1]));app(PermissionRegistrar::class)->forgetCachedPermissions();
        $authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked role bulk authority was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('roles')->whereIn('id',$ids)->count()===2,'current server role-delete authority is checked before any bulk replay writes');}
        $authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        $pivotCount=DB::table('role_has_permissions')->whereIn('role_id',$ids)->count();$memberCount=DB::table('model_has_roles')->whereIn('role_id',$ids)->count();
        DB::unprepared("CREATE TRIGGER fixture_reject_bulk_role_replay BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.command_id='".$id."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture role bulk replay rollback'; END IF; END");
        try{
            try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Bulk journal rollback fixture was ignored.');}
            catch(\Illuminate\Database\QueryException $failure){verify(DB::table('roles')->whereIn('id',$ids)->count()===2&&DB::table('role_has_permissions')->whereIn('role_id',$ids)->count()===$pivotCount
                &&DB::table('model_has_roles')->whereIn('role_id',$ids)->count()===$memberCount&&!DB::table('desktop_dashboard_commands')->where('command_id',$id)->exists(),'a failed server bulk journal restores the complete role, permission and membership selection');}
        }finally{DB::unprepared('DROP TRIGGER fixture_reject_bulk_role_replay');}
        $bulkCacheRole=$ids[0];$bulkCacheRepopulated=false;$bulkCacheArmed=true;
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'original typed role creation and bulk deletion reconcile exactly once: '.$row->route_name);
    if($row->route_name==='roles.destroy-all'){
        app(PermissionRegistrar::class)->clearClassPermissions();
        verify($bulkCacheRepopulated&&!app(PermissionRegistrar::class)->getPermissions(['name'=>'product-list','guard_name'=>'admin'])->first()->roles->contains('id',$ids[0]),'the original bulk after-commit invalidation removes grants repopulated by another real PHP process');
        verify(DB::table('roles')->whereIn('id',$ids)->count()===0&&DB::table('role_has_permissions')->whereIn('role_id',$ids)->count()===0
            &&DB::table('model_has_roles')->whereIn('role_id',$ids)->count()===0&&$receipt['references']===[],'original role bulk replay cascades every permission and assignment while producing no new entity references');
        $authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Stored bulk role receipt bypassed current authority.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403,'a saved reconciled bulk role receipt still checks current authority after all roles disappeared');}
        $authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $roleSwitch(true);app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
$roleSwitch(false);foreach($bulkCollisions as $id=>$name)verify(DB::table('roles')->where('id',$id)->value('name')===$name,'typed role bulk references preserve an unrelated server role at the same local numeric ID');
}finally{$bulkCacheArmed=false;fclose($pipes[0]);proc_terminate($web);proc_close($web);}
