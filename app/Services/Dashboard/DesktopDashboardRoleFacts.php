<?php
namespace App\Services\Dashboard;

use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Illuminate\Support\Facades\DB;

/** Bind the original role form's permission IDs to their actual names and guards. */
class DesktopDashboardRoleFacts
{
    public const ROUTES=['roles.store','roles.update','roles.destroy','roles.destroy-all'];
    public static function handles(?string $route): bool {return in_array($route,self::ROUTES,true);}
    private function validSelection(array $values): bool
    {
        return isset($values['permission'])&&is_array($values['permission'])
            &&array_reduce($values['permission'],fn($valid,$value)=>$valid&&is_string($value)&&$value!=='',true);
    }
    private function guard(?array $before): string
    {
        return $before['row']['guard_name']??Guard::getDefaultName(Role::class);
    }
    public function selected(array $values,?array $before): array
    {
        // Invalid original fields still reach the unchanged controller validation.
        if(!$this->validSelection($values))return [];
        $guard=$this->guard($before);$class=config('permission.models.permission');$permissions=[];
        foreach($values['permission'] as $value){
            try{$permission=is_numeric($value)?$class::findById($value,$guard):$class::findByName($value,$guard);}
            catch(PermissionDoesNotExist $error){abort(422,'إحدى صلاحيات الدور لم تعد موجودة.');}
            $permissions[]=['input'=>$value,'name'=>$permission->name,'guard_name'=>$permission->guard_name];
        }
        return ['guard_name'=>$guard,'permissions'=>$permissions];
    }
    public function resolve(array $values,array $facts,?array $before,int $id=0): array
    {
        if(!$this->validSelection($values))return $values;
        $guard=$this->guard($before);$rows=$facts['permissions']??null;
        abort_unless(($facts['guard_name']??null)===$guard&&is_array($rows)&&count($rows)===count($values['permission']),409,'حقائق صلاحيات الدور غير مكتملة.');
        $class=config('permission.models.permission');$table=(new $class)->getTable();$mapped=[];
        if(is_string($values['name']??null)){
            $roles=(new Role)->getTable();
            $collision=DB::table($roles)->where('guard_name',$guard)->where('name',$values['name'])->where('id','!=',$id)->lockForUpdate()->exists();
            abort_if($collision,409,'اسم الدور موجود الآن على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
        }
        foreach(array_values($values['permission']) as $index=>$value){
            $row=$rows[$index]??null;
            abort_unless(is_array($row)&&array_keys($row)===['input','name','guard_name']&&($row['input']??null)===$value
                &&is_string($row['name']??null)&&$row['name']!==''&&($row['guard_name']??null)===$guard,409,'مرجع صلاحية الدور لا يطابق النموذج المحلي.');
            $permission=DB::table($table)->where('name',$row['name'])->where('guard_name',$guard)->lockForUpdate()->first();
            abort_unless($permission,409,'إحدى صلاحيات الدور تغيرت على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
            $mapped[]=(string)$permission->id;
        }
        $values['permission']=$mapped;return $values;
    }
    public function state(int $id): array
    {
        $class=config('permission.models.permission');$permissionTable=(new $class)->getTable();
        return DB::table($permissionTable)->join(config('permission.table_names.role_has_permissions'),'permission_id','=',$permissionTable.'.id')
            ->where('role_id',$id)->orderBy('guard_name')->orderBy('name')->lockForUpdate()
            ->get([$permissionTable.'.name',$permissionTable.'.guard_name'])->map(fn($row)=>(array)$row)->all();
    }
    public function membershipHash(int $id,bool $current=true): string
    {
        $query=DB::table(config('permission.table_names.model_has_roles'))->where('role_id',$id)->orderBy('model_type')->orderBy('model_id');
        // Replay already holds the original role's parent row lock. Use a current
        // membership read, including assignments committed while that lock waited.
        if($current)$query->lockForUpdate();
        $rows=$query->get(['model_type','model_id'])->map(fn($row)=>['model_type'=>(string)$row->model_type,'model_id'=>(string)$row->model_id])->all();
        return hash('sha256',DesktopDashboardBootstrap::json($rows));
    }
    public function manifest(array $roles): array
    {
        // Export stays in the bootstrap's one repeatable-read dataset snapshot.
        $facts=[];foreach($roles as $role)$facts[(string)$role['id']]=$this->membershipHash((int)$role['id'],false);
        return $facts;
    }
    public function beforeMembership(int $id): string
    {
        if(!config('desktop_dashboard.local'))return $this->membershipHash($id);
        $device=(string)config('desktop_dashboard.device_id');
        // A new typed role could not have pre-existing server assignments. Original
        // user/role assignment writes remain outside the reviewed local route registry.
        if(DB::table('desktop_dashboard_entities')->where('device_id',$device)->where('entity','catalog_role')->where('local_id',$id)->exists()){
            $hash=$this->membershipHash($id);abort_unless($hash===hash('sha256','[]'),501,'إسناد مستخدمين لهذا الدور المحلي لم تُراجع مزامنته.');return $hash;
        }
        $state=DB::table('desktop_dashboard_local_state')->where('device_id',$device)->first();
        $coverage=$state?json_decode($state->coverage,true,512,JSON_THROW_ON_ERROR):[];$proof=$coverage['role_memberships'][(string)$id]??null;
        abort_unless(is_string($proof)&&preg_match('/^[a-f0-9]{64}$/D',$proof),501,'عضوية هذا الدور لم تُراجع في نسخة الجهاز؛ أعد تجهيز البيانات.');
        return $proof;
    }
}
