<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{DB,Validator};

/** Original creator authority, independently rechecked before a catalog creation grant is usable. */
class DesktopDashboardCreatedScopeAuthority
{
    public function authorize(string $route,User $actor): User
    {
        abort_unless($route==='go-stores.store'&&DB::transactionLevel()>0,409);
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->account_type==='admin'&&!in_array($fresh->status,['disabled','declined'],true),403);
        $roles=$fresh->roles()->lockForUpdate()->get();$fresh->setRelation('roles',$roles);$fresh->setRelation('permissions',$fresh->permissions()->lockForUpdate()->get());
        if((int)$fresh->id!==1)foreach(['resturant-list','resturant-create'] as $name){
            $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name',$name)->lockForUpdate()->first();abort_unless($permission,403);
            $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
            $role=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles->where('guard_name','admin')->pluck('id'))->where('permission_id',$permission->id)->lockForUpdate()->first();abort_unless($direct||$role,403);
        }
        return $fresh;
    }
    public function validateCreated(string $route,array $result,array $payload,User $actor): void
    {
        $fresh=$this->authorize($route,$actor);$scope=$result['created_scope']??null;$owner=$result['after']['owner']??null;$profile=$result['after']['profile']??null;
        abort_unless(is_array($scope)&&array_keys($scope)===['kind','entity','id','command_id']&&$scope['kind']==='gs'&&$scope['entity']==='menu_store_owner'&&is_int($scope['id'])&&$scope['id']>0
            &&is_array($owner)&&is_array($profile)&&($owner['id']??null)===$scope['id']&&($profile['user_id']??null)===$scope['id']
            &&($owner['added_by']??null)===(int)$fresh->id&&($owner['account_type']??null)==='vendor'&&($owner['app_scope']??null)==='go_partner'&&is_string($owner['created_at']??null),409);
        Validator::make(['command'=>$scope['command_id']],['command'=>'required|uuid'])->validate();abort_unless($scope['command_id']===($payload['values']['idempotency_key']??null),409);
        foreach(['menu_store_owner','wallet_account','dashboard_user'] as $entity)abort_unless(($result['references'][$entity]??null)===$scope['id'],409);
        $row=DB::table('users')->where('id',$scope['id'])->lockForUpdate()->first(['id','added_by','created_at','account_type','app_scope']);
        abort_unless($row&&$row->created_at===$owner['created_at']&&(int)$row->added_by===(int)$fresh->id&&$row->account_type==='vendor'&&$row->app_scope==='go_partner',403,'هوية حساب المتجر المنشأ تغيّرت.');
        abort_unless(DB::table('go_stores')->where('user_id',$scope['id'])->lockForUpdate()->first(['user_id']),403);
    }
}
