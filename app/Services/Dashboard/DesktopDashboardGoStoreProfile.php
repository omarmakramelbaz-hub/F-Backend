<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};

/** Journal only an enrolled existing GO profile through its original controller. */
class DesktopDashboardGoStoreProfile
{
    public const ROUTE='go-stores.update';
    public static function handles(?string $route): bool {return $route===self::ROUTE;}
    private function parameters(array $parameters): array
    {
        abort_if(array_diff(array_keys($parameters),['owner']),422);
        $v=Validator::make($parameters,['owner'=>'required|integer|min:1'])->validate();
        return ['owner'=>(int)$v['owner']];
    }
    public function command(Request $request): string
    {
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409,'رقم العملية في النموذج يختلف عن ترويسة الطلب.');
        $command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();
        return $command;
    }
    public function authorizeActor(User $actor): User
    {
        $original=app('router')->getRoutes()->getByName(self::ROUTE);
        abort_unless($original&&$original->getActionName()==='App\\Http\\Controllers\\Dashboard\\GoStores\\StoreController@update',409);
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->account_type==='admin'&&!in_array($fresh->status,['disabled','declined'],true),403);
        $roles=$fresh->roles()->lockForUpdate()->get();$fresh->setRelation('roles',$roles);
        $fresh->setRelation('permissions',$fresh->permissions()->lockForUpdate()->get());
        // Preserve the original id 1 exception, without a role-name or POS-owner exception.
        if((int)$fresh->id!==1)foreach(['resturant-list','resturant-edit'] as $name){
            $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name',$name)->lockForUpdate()->first();
            abort_unless($permission,403);
            $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
            $grant=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles->where('guard_name','admin')->pluck('id'))->where('permission_id',$permission->id)->lockForUpdate()->first();
            abort_unless($direct||$grant,403);
        }
        return $fresh;
    }
    public function authorize(array $parameters,User $actor): User
    {
        $p=$this->parameters($parameters);$fresh=$this->authorizeActor($actor);$this->state($p['owner']);return $fresh;
    }
    public function enrolledBranch(object $device,array $parameters): void
    {
        $p=$this->parameters($parameters);
        abort_unless(in_array('gs:'.$p['owner'],json_decode($device->branches,true,512,JSON_THROW_ON_ERROR),true),403,'المتجر خارج نطاق ربط الجهاز.');
    }
    public function state(int $owner): array
    {
        $account=DB::table('users')->where('id',$owner)->lockForUpdate()->first(['account_type','app_scope','pending_vendor_id','delegate_fees']);
        $row=DB::table('go_stores')->where('user_id',$owner)->lockForUpdate()->first();abort_unless($account&&$row,404);
        $pending=$account->account_type==='delegate'&&$account->pending_vendor_id
            ?DB::table('pending_vendors')->where('id',$account->pending_vendor_id)->lockForUpdate()->first(['id','profession_key']):null;
        abort_unless($account->app_scope==='go_partner'&&($account->account_type==='vendor'
            ||($account->account_type==='delegate'&&$pending&&$pending->profession_key==='store_owner')),403);
        $row=(array)$row;unset($row['created_at'],$row['updated_at']);
        $account=(array)$account;$account['delegate_fees']=$account['delegate_fees']===null?null:number_format((float)$account['delegate_fees'],2,'.','');
        return ['row'=>$row,'owner'=>$account,'pending'=>$pending?(array)$pending:null];
    }
    public function validateReferences(array $payload): void
    {
        foreach([$payload['parameters']['owner']??null,$payload['facts']['catalog_before']['row']['user_id']??null] as $value)
            if(is_array($value))abort_unless(($value['$desktop_ref']['entity']??null)==='menu_store_owner',422,'نوع مرجع صاحب المتجر غير صالح.');
    }
}
