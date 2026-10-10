<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Replay only the original scoped menu availability controller, with complete before-facts. */
class DesktopDashboardMenuAvailability
{
    public const ROUTE='order-board.menu.availability';
    public static function handles(?string $route): bool {return $route===self::ROUTE;}
    public function generation(User $actor): string
    {
        if(!config('desktop_dashboard.local'))return 'server';
        $state=DB::table('desktop_dashboard_local_state')->where('device_id',(string)config('desktop_dashboard.device_id'))->first();
        return $state&&(int)$state->actor_id===(int)$actor->id?(string)$state->snapshot_id:'';
    }
    private function parameters(array $parameters): array
    {
        abort_if(array_diff(array_keys($parameters),['kind','branchId','product']),422);
        $v=Validator::make($parameters,['kind'=>'required|in:f,gs','branchId'=>'required|integer|min:1','product'=>'required|integer|min:1'])->validate();
        return ['kind'=>$v['kind'],'branchId'=>(int)$v['branchId'],'product'=>(int)$v['product']];
    }
    public function authorize(array $parameters,User $actor): array
    {
        $p=$this->parameters($parameters);$original=app('router')->getRoutes()->getByName(self::ROUTE);
        abort_unless($original&&$original->getActionName()==='App\\Http\\Controllers\\Dashboard\\OrderBoardMenuController@availability',409);
        // Locking reads remain current even inside an older repeatable-read transaction.
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&!in_array($fresh->status,['disabled','declined'],true),403);
        $fresh->setRelation('permissions',$fresh->permissions()->lockForUpdate()->get());
        $roles=$fresh->roles()->lockForUpdate()->get();
        $fresh->setRelation('roles',$roles);
        // Spatie's Permission->roles may be cached from before a grant was revoked.
        // Keep the original menu authority, and additionally prove both central
        // admin grants from current locked pivots before any deduplicated reply.
        if($fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)&&(int)$fresh->id!==1){
            foreach(['order-list','resturant-edit'] as $name){
                $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name',$name)->lockForUpdate()->first();
                abort_unless($permission,403);
                $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
                $grant=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles->pluck('id'))->where('permission_id',$permission->id)->lockForUpdate()->first();
                abort_unless($direct||$grant,403);
            }
        }
        return app(OrderBoardMenu::class)->authorizeAvailability($p['kind'],$p['branchId'],$p['product'],$fresh)+['actor'=>$fresh];
    }
    private function state(array $parameters,User $actor): array
    {
        $p=$this->parameters($parameters);$authorized=$this->authorize($p,$actor);$row=$authorized['row'];
        unset($row['id'],$row['created_at'],$row['updated_at']);
        // Ownership is part of identity; do not include user secrets in command facts.
        $branch=$p['kind']==='f'
            ?(array)DB::table('resturants')->where('id',$p['branchId'])->lockForUpdate()->first(['user_id','parent_id'])
            :(array)DB::table('users')->where('id',$p['branchId'])->lockForUpdate()->first(['account_type','app_scope','pending_vendor_id']);
        return ['kind'=>$p['kind'],'row'=>$row,'branch'=>$branch];
    }
    private function values(array $values): array
    {
        abort_if(array_diff(array_keys($values),['available','expected_available','expected_revision','idempotency_key']),422);
        return $values;
    }
    public function enrolledBranch(object $device,array $parameters): void
    {
        $p=$this->parameters($parameters);
        abort_unless(in_array($p['kind'].':'.$p['branchId'],json_decode($device->branches,true,512,JSON_THROW_ON_ERROR),true),403,'الفرع خارج نطاق ربط الجهاز.');
    }
    public function payload(Request $request,string $command,User $actor): array
    {
        abort_if($request->allFiles(),501);$p=$this->parameters($request->route()->parameters());
        $values=$this->values($request->except('_token','_desktop_command'));$values['idempotency_key']=$command;
        $this->authorize($p,$actor);
        $state=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));
        abort_unless($state&&(int)$state->actor_id===(int)$actor->id,403);$this->enrolledBranch($state,$p);
        $facts=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,self::ROUTE)
            ??['menu_before'=>$this->state($p,$actor)];
        return ['parameters'=>$p,'values'=>$values,'files'=>[],'facts'=>$facts];
    }
    public function inputs(array $payload,callable $reference): array
    {
        $kind=$payload['parameters']['kind']??null;abort_unless(in_array($kind,['f','gs'],true),422);
        $branch=$kind==='f'?'menu_restaurant':'menu_store_owner';$product=$kind==='f'?'menu_restaurant_product':'menu_store_product';
        foreach(['branchId'=>$branch,'product'=>$product] as $key=>$entity)
            if(isset($payload['parameters'][$key])&&!is_array($payload['parameters'][$key]))$payload['parameters'][$key]=$reference($entity,$payload['parameters'][$key]);
        $row=&$payload['facts']['menu_before']['row'];$owner=$kind==='f'?'resturant_id':'user_id';
        if(isset($row[$owner])&&!is_array($row[$owner]))$row[$owner]=$reference($branch,$row[$owner]);
        if($kind==='f'&&isset($row['product_id'])&&!is_array($row['product_id']))$row['product_id']=$reference('catalog_product',$row['product_id']);
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        $kind=$payload['parameters']['kind']??null;abort_unless(in_array($kind,['f','gs'],true),422);
        $branch=$kind==='f'?'menu_restaurant':'menu_store_owner';$product=$kind==='f'?'menu_restaurant_product':'menu_store_product';
        foreach([[$payload['parameters']['branchId']??null,$branch],[$payload['parameters']['product']??null,$product],
            [$payload['facts']['menu_before']['row'][$kind==='f'?'resturant_id':'user_id']??null,$branch],
            [$payload['facts']['menu_before']['row']['product_id']??null,'catalog_product']] as [$value,$entity])
            if(is_array($value))abort_unless(($value['$desktop_ref']['entity']??null)===$entity,422,'نوع مرجع الصنف أو الفرع لا يطابق القائمة.');
    }
    public function outputs(array $result,array $parameters): array
    {
        foreach($parameters as $key=>$value)if(is_array($value)&&isset($value['$desktop_ref']['local_id']))$parameters[$key]=(int)$value['$desktop_ref']['local_id'];
        $p=$this->parameters($parameters);abort_unless(($result['success']??false)===true&&($result['item']['id']??null)===$p['product'],409);
        return $p['kind']==='f'?['menu_restaurant'=>$p['branchId'],'menu_restaurant_product'=>$p['product']]
            :['menu_store_owner'=>$p['branchId'],'menu_store_product'=>$p['product']];
    }
    public function execute(array $payload,User $actor): array
    {
        abort_unless(empty($payload['files']),422);$p=$this->parameters($payload['parameters']??[]);$v=$this->values($payload['values']??[]);
        $before=$payload['facts']['menu_before']??null;
        abort_unless(is_array($before)&&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),
            app(DesktopDashboardJournal::class)->fingerprint($this->state($p,$actor))),409,'الصنف أو ملكية الفرع تغيّرا على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
        unset($v['idempotency_key']);$request=Request::create(url('/admin/order-board/menu/'.$p['kind'].'/'.$p['branchId'].'/products/'.$p['product'].'/availability'),'POST',$v);
        $guard=auth('admin');$previous=$guard->getUser();
        try{
            $guard->setUser($actor);
            $response=app(\App\Http\Controllers\Dashboard\OrderBoardMenuController::class)->availability($request,$p['kind'],$p['branchId'],$p['product'],app(OrderBoardMenu::class));
            abort_unless($response->getStatusCode()===200,409);
            return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
    }
}
