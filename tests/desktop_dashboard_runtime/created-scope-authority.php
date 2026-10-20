<?php
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Dashboard\DesktopDashboardCreatedScopeAuthority;

(function(){
    $authority=app(DesktopDashboardCreatedScopeAuthority::class);$owner=User::withoutGlobalScopes()->findOrFail(1);
    $reject=function(callable $work,int $expected,string $message){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$expected,$message);return;}throw new RuntimeException('Creator authority unexpectedly allowed '.$message);};
    DB::transaction(fn()=>verify((int)$authority->authorize('go-stores.store',$owner)->id===1,'current primary-admin GO creation retains the exact original ID1 exception'));
    $actor=User::withoutGlobalScopes()->create(['id'=>67,'added_by'=>1,'name'=>'Creator authority fixture','mobile'=>'1200000067','email'=>'creator-authority@test.invalid','password'=>'Fixture123','account_type'=>'admin','app_scope'=>'fasakhansta','status'=>'accepted']);
    $role=\Spatie\Permission\Models\Role::create(['name'=>'GO Creator authority fixture','guard_name'=>'admin']);$actor->assignRole($role);$actor->setRelation('roles',collect());
    foreach(['resturant-list','resturant-create'] as $name)\Spatie\Permission\Models\Permission::firstOrCreate(['name'=>$name,'guard_name'=>'admin']);
    $role->givePermissionTo('resturant-list');DB::transaction(fn()=>$reject(fn()=>$authority->authorize('go-stores.store',$actor),403,'GO creation requires both original list/create grants for another administrator'));
    $role->givePermissionTo('resturant-create');DB::transaction(fn()=>verify((int)$authority->authorize('go-stores.store',$actor)->id===67,'fresh current SQL grants authorize the original GO creator despite stale in-memory roles'));
    $role->revokePermissionTo('resturant-create');DB::transaction(fn()=>$reject(fn()=>$authority->authorize('go-stores.store',$actor),403,'revoking the current creation grant denies a previously authorized GO creator'));
    DB::table('users')->where('id',67)->update(['status'=>'disabled']);DB::transaction(fn()=>$reject(fn()=>$authority->authorize('go-stores.store',$actor),403,'a disabled original GO creator cannot revalidate a catalog grant'));
    DB::transaction(fn()=>$reject(fn()=>$authority->authorize('resturants.store',$owner),409,'unimplemented restaurant creation cannot mint a GO catalog authority grant'));
    DB::transaction(function()use($authority,$owner,$reject){
        $created=app(\App\Services\GoStores\AccountCreator::class)->create(['owner_name'=>'مالك اختبار السلطة','mobile'=>'1012345690','email'=>'created-authority@test.invalid','password'=>'Fixture123','name'=>'متجر اختبار السلطة','kind'=>'supermarket','address'=>'عنوان اختبار السلطة','commission_rate'=>'12.50'],1);
        $id=(int)$created->id;$safe=(array)DB::table('users')->where('id',$id)->first(['id','added_by','created_at','account_type','app_scope']);$profile=(array)DB::table('go_stores')->where('user_id',$id)->first();$command=(string)\Illuminate\Support\Str::uuid();
        $result=['after'=>['owner'=>$safe,'profile'=>$profile],'references'=>['menu_store_owner'=>$id,'wallet_account'=>$id,'dashboard_user'=>$id],'created_scope'=>['kind'=>'gs','entity'=>'menu_store_owner','id'=>$id,'command_id'=>$command]];$payload=['values'=>['idempotency_key'=>$command]];
        $authority->validateCreated('go-stores.store',$result,$payload,$owner);verify($created->app_scope==='go_partner'&&$created->account_type==='vendor','the authority validates the actual original AccountCreator output without exposing its password hash');
        $bad=$result;$bad['references']['wallet_account']=1;$reject(fn()=>$authority->validateCreated('go-stores.store',$bad,$payload,$owner),409,'a created GO scope requires all three exact generated user aliases');
        $bad=$result;$bad['created_scope']['command_id']=(string)\Illuminate\Support\Str::uuid();$reject(fn()=>$authority->validateCreated('go-stores.store',$bad,$payload,$owner),409,'a created GO scope is bound to the original immutable command UUID');
        $row=(array)DB::table('users')->where('id',$id)->first();DB::table('users')->where('id',$id)->delete();$recreated=$row;$recreated['created_at']='2021-01-01 00:00:00';DB::table('users')->insert($recreated);
        $reject(fn()=>$authority->validateCreated('go-stores.store',$result,$payload,$owner),403,'actual same-ID created-owner deletion and recreation cannot inherit the original catalog grant');
        DB::table('go_stores')->where('user_id',$id)->delete();DB::table('users')->where('id',$id)->delete();
    });
    $role->users()->detach();$role->delete();DB::table('users')->where('id',67)->delete();app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
})();
