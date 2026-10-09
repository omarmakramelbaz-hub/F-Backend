<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\{Role,Permission};
use Spatie\Permission\PermissionRegistrar;

[$status,$rolePage]=$http('/admin/roles/create');preg_match('/name="_token" value="([^"]+)"/',$rolePage,$roleRemoteCsrf);
verify($status===200&&isset($roleRemoteCsrf[1]),'the original server role form prepares actual reserved outcome tests');
$roleRemoteForm=['_token'=>$roleRemoteCsrf[1],'_desktop_command'=>(string)Str::uuid(),'name'=>'دور نتيجة السيرفر','permission'=>[(string)Permission::findByName('product-list','admin')->id]];
$roleRemoteAttempt=$attempt('/admin/roles');[, $roleRemoteProof]=$decide($roleRemoteAttempt);
verify($http('/admin/roles',$roleRemoteForm,$proof($roleRemoteProof))[0]===302&&DB::table('roles')->where('name',$roleRemoteForm['name'])->count()===1
    &&$decide($roleRemoteAttempt,'settle')[1]['status']==='committed','the original role creation commits its role, permission pivot and encrypted reserved outcome together');
verify($http('/admin/roles',$roleRemoteForm,$proof($roleRemoteProof))[0]===302&&DB::table('roles')->where('name',$roleRemoteForm['name'])->count()===1,'a lost server role creation reply reuses the committed outcome without another role');
$authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-create');app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($http('/admin/roles',$roleRemoteForm,$proof($roleRemoteProof))[0]===403,'a stored original server role creation reply requires its current original permission');
$authority->givePermissionTo('role-create');app(PermissionRegistrar::class)->forgetCachedPermissions();
$roleRemoteRetry=$attempt('/admin/roles');[, $roleRemoteRetryProof]=$decide($roleRemoteRetry);
verify($http('/admin/roles',$roleRemoteForm,$proof($roleRemoteRetryProof))[0]===302&&DB::table('roles')->where('name',$roleRemoteForm['name'])->count()===1,'a later role transmission UUID acknowledges the same immutable logical operation');
$remoteRole=(int)DB::table('roles')->where('name',$roleRemoteForm['name'])->value('id');
$roleRemoteForm['_method']='PUT';$roleRemoteForm['_desktop_command']=(string)Str::uuid();$roleRemoteForm['name']='دور نتيجة السيرفر معدل';$roleRemoteForm['permission']=[(string)Permission::findByName('category-list','admin')->id];
$roleRemoteUpdate=$attempt('/admin/roles/'.$remoteRole);[, $roleRemoteUpdateProof]=$decide($roleRemoteUpdate);
verify($http($roleRemoteUpdate['path'],$roleRemoteForm,$proof($roleRemoteUpdateProof))[0]===302&&Role::findOrFail($remoteRole)->permissions()->pluck('name')->all()===['category-list']
    &&$http($roleRemoteUpdate['path'],$roleRemoteForm,$proof($roleRemoteUpdateProof))[0]===302,'the original server role permission replacement and repeated reply retain one reserved outcome');
$authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-edit');app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($http($roleRemoteUpdate['path'],$roleRemoteForm,$proof($roleRemoteUpdateProof))[0]===403,'a stored role update outcome cannot bypass currently revoked original edit authority');
$authority->givePermissionTo('role-edit');app(PermissionRegistrar::class)->forgetCachedPermissions();
$roleRemoteRemove=['_token'=>$roleRemoteCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
$roleRemoteDelete=$attempt('/admin/roles/'.$remoteRole);[, $roleRemoteDeleteProof]=$decide($roleRemoteDelete);
verify($http($roleRemoteDelete['path'],$roleRemoteRemove,$proof($roleRemoteDeleteProof))[0]===302&&!DB::table('roles')->where('id',$remoteRole)->exists()
    &&$http($roleRemoteDelete['path'],$roleRemoteRemove,$proof($roleRemoteDeleteProof))[0]===302,'a lost original server role deletion reply is replayed after its scalar role ID and pivots are gone');
$authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($http($roleRemoteDelete['path'],$roleRemoteRemove,$proof($roleRemoteDeleteProof))[0]===403,'a stored role deletion response still requires current original delete authority');
$authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($decide(['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/rolesDeleteAll'])[0]===422,'unreviewed original role bulk deletion cannot receive a native outcome reservation');
verify($decide(['id'=>(string)Str::uuid(),'method'=>'PUT','path'=>'/admin/roles'])[0]===422,'the role listing cannot reserve a non-existent single-role update route');

$failedRoleForm=$roleRemoteForm;unset($failedRoleForm['_method']);$failedRoleForm['_desktop_command']=(string)Str::uuid();$failedRoleForm['name']='دور تراجع نتيجة السيرفر';
$failedRoleAttempt=$attempt('/admin/roles');[, $failedRoleProof]=$decide($failedRoleAttempt);$pivotCount=DB::table('role_has_permissions')->count();
DB::unprepared("CREATE TRIGGER fixture_reject_role_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$failedRoleForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture role outcome rollback'; END IF; END");
try{
    verify($http('/admin/roles',$failedRoleForm,$proof($failedRoleProof))[0]===500&&!DB::table('roles')->where('name',$failedRoleForm['name'])->exists()
        &&DB::table('role_has_permissions')->count()===$pivotCount,'a failed reserved role outcome rolls back the original role and every permission pivot');
}finally{DB::unprepared('DROP TRIGGER fixture_reject_role_outcome');}
verify($decide($failedRoleAttempt,'settle')[1]['status']==='cancelled','a rejected role outcome can be terminally cancelled without claiming a committed role');
verify($http('/admin/roles',$failedRoleForm,$proof($failedRoleProof))[0]===409&&!DB::table('roles')->where('name',$failedRoleForm['name'])->exists(),'a late original role request cannot write after its reservation is cancelled');
