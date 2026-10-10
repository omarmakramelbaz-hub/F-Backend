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
foreach(['POST','PUT','PATCH'] as $wrongMethod)verify($decide(['id'=>(string)Str::uuid(),'method'=>$wrongMethod,'path'=>'/admin/rolesDeleteAll'])[0]===422,'the exact original role bulk endpoint cannot reserve an unsupported method');
verify($decide(['id'=>(string)Str::uuid(),'method'=>'POST','path'=>'/admin/permissions'])[0]===422,'nonexistent original permission administration cannot receive a native outcome reservation');
verify($decide(['id'=>(string)Str::uuid(),'method'=>'PUT','path'=>'/admin/roles'])[0]===422,'the role listing cannot reserve a non-existent single-role update route');

$bulkRemoteIds=[];$normalRole=['_token'=>$roleRemoteCsrf[1],'name'=>'','permission'=>[(string)Permission::findByName('product-list','admin')->id]];
foreach([1,2] as $index){
    $normalRole['name']='دور نتيجة حذف جماعي '.$index;
    verify($http('/admin/roles',$normalRole)[0]===302,'the normal original server role form prepares a bulk deletion without a native reservation');
    $bulkRemoteIds[]=(int)DB::table('roles')->where('name',$normalRole['name'])->value('id');
}
\App\Models\User::withoutGlobalScopes()->findOrFail(21)->assignRole(Role::findOrFail($bulkRemoteIds[0]));
$bulkRoleAttempt=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>'/admin/rolesDeleteAll'];[$status,$bulkRoleProof]=$decide($bulkRoleAttempt);
verify($status===200&&$bulkRoleProof['status']==='ready','the exact native role bulk DELETE reserves a real transactional server outcome');
$bulkRoleForm=['_token'=>$roleRemoteCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',array_reverse($bulkRemoteIds))];
$authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($http($bulkRoleAttempt['path'],$bulkRoleForm,$proof($bulkRoleProof),'DELETE')[0]===403&&DB::table('roles')->whereIn('id',$bulkRemoteIds)->count()===2
    &&$http($bulkRoleAttempt['path'],['_token'=>$roleRemoteCsrf[1],'ids'=>$bulkRoleForm['ids']],[],'DELETE')[0]===403,'both ordinary original requests and reserved bulk outcomes require role-delete before changing any role');
$authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
[$status,$bulkRoleBody]=$http($bulkRoleAttempt['path'],$bulkRoleForm,$proof($bulkRoleProof),'DELETE');
verify($status===200&&json_decode($bulkRoleBody,true)===['success'=>trans('messages.RecordsDeleteSuccessfully')]
    &&DB::table('roles')->whereIn('id',$bulkRemoteIds)->count()===0&&DB::table('role_has_permissions')->whereIn('role_id',$bulkRemoteIds)->count()===0
    &&DB::table('model_has_roles')->whereIn('role_id',$bulkRemoteIds)->count()===0&&$decide($bulkRoleAttempt,'settle')[1]['status']==='committed','the exact original bulk server response, roles and every permission/member cascade commit with one saved outcome');
$bulkRoleForm['ids']=implode(',',$bulkRemoteIds);
verify($http($bulkRoleAttempt['path'],$bulkRoleForm,$proof($bulkRoleProof),'DELETE')[1]===$bulkRoleBody,'a reordered lost bulk role server response returns its exact saved JSON after all IDs disappear');
$bulkRetry=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>$bulkRoleAttempt['path']];[, $bulkRetryProof]=$decide($bulkRetry);
verify($http($bulkRetry['path'],$bulkRoleForm,$proof($bulkRetryProof),'DELETE')[1]===$bulkRoleBody,'a new bulk transmission UUID acknowledges the same immutable role deletion operation');
$authority=Role::findOrFail($role->id);$authority->revokePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
verify($http($bulkRoleAttempt['path'],$bulkRoleForm,$proof($bulkRoleProof),'DELETE')[0]===403&&$http($bulkRetry['path'],$bulkRoleForm,$proof($bulkRetryProof),'DELETE')[0]===403,'every saved bulk role outcome still checks current original deletion authority');
$authority->givePermissionTo('role-delete');app(PermissionRegistrar::class)->forgetCachedPermissions();
$changedBulk=$bulkRoleForm;$changedBulk['ids']=(string)$bulkRemoteIds[0];
verify($http($bulkRoleAttempt['path'],$changedBulk,$proof($bulkRoleProof),'DELETE')[0]===409,'changing a selected role set cannot reuse a committed bulk operation UUID');

$rollbackBulkIds=[];
foreach([1,2] as $index){$normalRole['name']='دور تراجع حذف جماعي '.$index;verify($http('/admin/roles',$normalRole)[0]===302,'the original role controller prepares an atomic bulk outcome rollback fixture');$rollbackBulkIds[]=(int)DB::table('roles')->where('name',$normalRole['name'])->value('id');}
\App\Models\User::withoutGlobalScopes()->findOrFail(21)->assignRole(Role::findOrFail($rollbackBulkIds[0]));
$rollbackBulk=['id'=>(string)Str::uuid(),'method'=>'DELETE','path'=>$bulkRoleAttempt['path']];[, $rollbackBulkProof]=$decide($rollbackBulk);
$rollbackBulkForm=['_token'=>$roleRemoteCsrf[1],'_desktop_command'=>(string)Str::uuid(),'ids'=>implode(',',$rollbackBulkIds)];
DB::unprepared("CREATE TRIGGER fixture_reject_bulk_role_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$rollbackBulkForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture bulk role outcome rollback'; END IF; END");
try{verify($http($rollbackBulk['path'],$rollbackBulkForm,$proof($rollbackBulkProof),'DELETE')[0]===500&&DB::table('roles')->whereIn('id',$rollbackBulkIds)->count()===2
    &&DB::table('role_has_permissions')->whereIn('role_id',$rollbackBulkIds)->count()===2&&DB::table('model_has_roles')->whereIn('role_id',$rollbackBulkIds)->count()===1,'a failed bulk reserved outcome restores every original role, permission and member pivot');}
finally{DB::unprepared('DROP TRIGGER fixture_reject_bulk_role_outcome');}
verify($decide($rollbackBulk,'settle')[1]['status']==='cancelled'&&$http($rollbackBulk['path'],$rollbackBulkForm,$proof($rollbackBulkProof),'DELETE')[0]===409
    &&DB::table('roles')->whereIn('id',$rollbackBulkIds)->count()===2,'a cancelled bulk outcome prevents a late original DELETE from removing the retained roles');

$failedRoleForm=$roleRemoteForm;unset($failedRoleForm['_method']);$failedRoleForm['_desktop_command']=(string)Str::uuid();$failedRoleForm['name']='دور تراجع نتيجة السيرفر';
$failedRoleAttempt=$attempt('/admin/roles');[, $failedRoleProof]=$decide($failedRoleAttempt);$pivotCount=DB::table('role_has_permissions')->count();
DB::unprepared("CREATE TRIGGER fixture_reject_role_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.operation_id='".$failedRoleForm['_desktop_command']."' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture role outcome rollback'; END IF; END");
try{
    verify($http('/admin/roles',$failedRoleForm,$proof($failedRoleProof))[0]===500&&!DB::table('roles')->where('name',$failedRoleForm['name'])->exists()
        &&DB::table('role_has_permissions')->count()===$pivotCount,'a failed reserved role outcome rolls back the original role and every permission pivot');
}finally{DB::unprepared('DROP TRIGGER fixture_reject_role_outcome');}
verify($decide($failedRoleAttempt,'settle')[1]['status']==='cancelled','a rejected role outcome can be terminally cancelled without claiming a committed role');
verify($http('/admin/roles',$failedRoleForm,$proof($failedRoleProof))[0]===409&&!DB::table('roles')->where('name',$failedRoleForm['name'])->exists(),'a late original role request cannot write after its reservation is cancelled');
