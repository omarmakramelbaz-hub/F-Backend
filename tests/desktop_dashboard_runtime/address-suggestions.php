<?php
// Real original Laravel/controller/read-only middleware against the disposable MariaDB fixture.
use Illuminate\Support\Facades\{DB,Http};
use App\Services\Dashboard\{PhoneMapProvider,DesktopDashboardRoutes};

$addressConfig=['desktop_dashboard.local'=>config('desktop_dashboard.local'),'services.maps.phone_open_enabled'=>config('services.maps.phone_open_enabled')];
$addressHttp=Http::getFacadeRoot();Http::swap(new \Illuminate\Http\Client\Factory);$addressHttpCalls=[];
Http::fake(function($request)use(&$addressHttpCalls){$addressHttpCalls[]=$request;return Http::response(['features'=>[
    ['geometry'=>['type'=>'Point','coordinates'=>[31.2,30.1]],'properties'=>['name'=>'عنوان Photon الأصلي','countrycode'=>'EG']]
]]);});
$addressGuard=auth('admin');$addressPreviousActor=$addressGuard->user();$addressIds=[];$addressPermissionMigration=null;
$addressBefore=['journal'=>DB::table('desktop_dashboard_commands')->count(),'operations'=>DB::table('branch_operation_commands')->count()];
$addressRows=[
    ['address'=>'شارع محفوظ 1','area'=>'المنطقة الأولى','latitude'=>30.01,'longitude'=>31.01],
    ['address'=>'  شارع  محفوظ 1  ','area'=>'المنطقة الأولى','latitude'=>30.01,'longitude'=>31.01],
];
for($n=2;$n<=8;$n++)$addressRows[]=['address'=>'شارع محفوظ '.$n,'latitude'=>30+$n/100,'longitude'=>31+$n/100];
$addressRows=array_merge($addressRows,[
    ['branch'=>'f:101','address'=>'عنوان الفرع الآخر','latitude'=>30.5,'longitude'=>31.5],
    ['address'=>'شارع محفوظ بلا دبوس','latitude'=>null,'longitude'=>null],
    ['address'=>'شارع محفوظ صفر','latitude'=>0,'longitude'=>0],
    ['address'=>'شارع محفوظ خارج النطاق','latitude'=>91,'longitude'=>31],
    ['address'=>'شارع محفوظ خط طول غير صالح','latitude'=>30,'longitude'=>181],
    ['address'=>'   ','area'=>'منطقة عنوان فارغ','latitude'=>30.6,'longitude'=>31.6],
    ['address'=>'عنوان %_ محفوظ','area'=>'منطقة خاصة','latitude'=>30.7,'longitude'=>31.7],
    ['address'=>'عنوان \\ محفوظ','latitude'=>30.8,'longitude'=>31.8],
    ['address'=>str_repeat('ط',440).' نهاية العنوان الأول','area'=>'منطقة العنوان الطويل','latitude'=>30.9,'longitude'=>31.9],
    ['address'=>str_repeat('ط',440).' نهاية العنوان الثاني','area'=>'منطقة العنوان الطويل','latitude'=>30.9,'longitude'=>31.9],
]);
try{
    foreach($addressRows as $index=>$row)$addressIds[]=DB::table('branch_customers')->insertGetId($row+[
        'branch'=>'f:100','name'=>'اسم عميل خاص لا يُبحث فيه','phone'=>'0199000'.str_pad((string)$index,4,'0',STR_PAD_LEFT),
        'phone_key'=>'0199000'.str_pad((string)$index,4,'0',STR_PAD_LEFT),'area'=>'','delivery_notes'=>'ملاحظات خاصة لا تُعرض',
        'actor_id'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
    ]);
    config(['desktop_dashboard.local'=>true,'services.maps.phone_open_enabled'=>false]);
    $maps=app(PhoneMapProvider::class);$values=['branch'=>'f:100','query'=>'شارع محفوظ'];
    $first=$maps->suggestions($values,$cashier);
    check($first['provider']==='saved'&&count($first['items'])===6,'local address suggestions skip invalid/duplicate pins before the six-result limit');
    check(array_column($first['items'],'label')===['شارع محفوظ 1، المنطقة الأولى','شارع محفوظ 2','شارع محفوظ 3','شارع محفوظ 4','شارع محفوظ 5','شارع محفوظ 6'],
        'saved suggestions retain stable ID order and normalized address/area labels');
    check($first===$maps->suggestions($values,$cashier),'repeated local reads return identical stable suggestion identities');
    foreach($first['items'] as $item)check(array_keys($item)===['id','label','latitude','longitude']&&preg_match('/^[a-f0-9]{64}$/D',$item['id'])
        &&is_finite($item['latitude'])&&is_finite($item['longitude']),'suggestion exposes only a stable address identity, label and finite pin');
    check(count($maps->suggestions(['branch'=>'f:100','query'=>'المنطقة الأولى'],$cashier)['items'])===1,'local query matches the saved area literally');
    $longAddress=$maps->suggestions(['branch'=>'f:100','query'=>'نهاية العنوان الأول'],$cashier)['items'];
    check(count($longAddress)===1&&mb_strlen($longAddress[0]['label'])===400,'literal matching searches the full saved address before shortening its display label');
    $longArea=$maps->suggestions(['branch'=>'f:100','query'=>'منطقة العنوان الطويل'],$cashier)['items'];
    check(count($longArea)===2&&$longArea[0]['label']===$longArea[1]['label']&&$longArea[0]['id']!==$longArea[1]['id'],
        'an area after a long address remains searchable and distinct full addresses retain distinct stable identities');
    foreach(['بلا دبوس','صفر','خارج النطاق','خط طول غير صالح','منطقة عنوان فارغ'] as $query)
        check($maps->suggestions(['branch'=>'f:100','query'=>$query],$cashier)['items']===[],
            'missing/out-of-range/zero pins and blank saved addresses cannot become suggestions: '.$query);
    check(count($maps->suggestions(['branch'=>'f:100','query'=>'%_'],$cashier)['items'])===1
        &&$maps->suggestions(['branch'=>'f:100','query'=>'%%'],$cashier)['items']===[]
        &&$maps->suggestions(['branch'=>'f:100','query'=>'_ا'],$cashier)['items']===[],
        'percent and underscore queries never become SQL wildcards or widen the result');
    check(count($maps->suggestions(['branch'=>'f:100','query'=>'\\ محفوظ'],$cashier)['items'])===1,'backslash remains literal query text');
    foreach(['اسم عميل خاص','0199000','ملاحظات خاصة','عنوان غير محفوظ'] as $query)
        check($maps->suggestions(['branch'=>'f:100','query'=>$query],$cashier)['items']===[],'identity, notes and absent addresses return honest empty saved results: '.$query);
    check($maps->suggestions(['branch'=>'f:100','query'=>'الفرع الآخر'],$actor)['items']===[],
        'even an owner with multiple branches receives only the selected branch saved addresses');
    denied(fn()=>$maps->suggestions($values,\App\Models\User::withoutGlobalScopes()->findOrFail(11)),404,'a different branch account cannot read the saved address scope');
    DB::table('users')->where('id',10)->update(['account_type'=>'user']);
    denied(fn()=>$maps->suggestions($values,$cashier),403,'a stale actor object cannot retain revoked address-read authority');
    DB::table('users')->where('id',10)->update(['account_type'=>'vendor']);
    DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
    denied(fn()=>$maps->suggestions($values,$cashier),404,'a transferred branch relationship is rechecked before saved address reads');
    DB::table('resturants')->where('id',100)->update(['user_id'=>10]);
    foreach(['x','   ',str_repeat('x',241)] as $query)denied(fn()=>$maps->suggestions(['branch'=>'f:100','query'=>$query],$cashier),422,'the original query length/required validation remains enforced');
    denied(fn()=>$maps->suggestions(['branch'=>'f:100','query'=>' x '],$cashier),422,'normalized local query must still contain two characters');
    config(['services.maps.phone_open_enabled'=>true]);
    check($first===$maps->suggestions($values,$cashier),'desktop reads avoid the provider even when online Photon is enabled');
    check(count($addressHttpCalls)===0,'all local valid, empty and rejected suggestion requests made no provider call');
    $addressGuard->setUser($cashier);$route=app('router')->getRoutes()->getByName('phone-orders.address-suggestions');
    $request=\Illuminate\Http\Request::create('/admin/phone-orders/address-suggestions','POST',$values);$request->setRouteResolver(fn()=>$route);
    check(in_array($route->getName(),DesktopDashboardRoutes::READ_POSTS,true)&&!DesktopDashboardRoutes::journaled($route->getName()),
        'the original address POST is classified only as a read, with no business journal registration');
    $response=app(\App\Http\Middleware\DesktopDashboardJournal::class)->handle($request,function($request)use($maps){
        check(DB::transactionLevel()===1,'the original address controller runs inside the actual local read-only transaction');
        return app(\App\Http\Controllers\Dashboard\PhoneOrdersController::class)->addressSuggestions($request,$maps);
    });
    check($response->getStatusCode()===200&&json_decode($response->getContent(),true)===$first
        &&str_contains($response->headers->get('Cache-Control'),'no-store'),'the original local controller returns saved address JSON with private no-store caching');
    require $application.'/database/migrations/2022_08_05_174522_create_permission_tables.php';$addressPermissionMigration=new \CreatePermissionTables;$addressPermissionMigration->up();
    DB::table('users')->insert(['id'=>12,'name'=>'مدير مركزي للعناوين','account_type'=>'admin']);
    $central=\App\Models\User::withoutGlobalScopes()->findOrFail(12);
    $orderPermission=\Spatie\Permission\Models\Permission::create(['name'=>'order-list','guard_name'=>'admin']);
    $orderRole=\Spatie\Permission\Models\Role::create(['name'=>'Address Reader','guard_name'=>'admin']);$orderRole->givePermissionTo($orderPermission);$central->assignRole($orderRole);
    check($central->can('order-list')&&$maps->suggestions($values,$central)===$first,'a current central admin role grant permits local saved address reads');
    DB::table('role_has_permissions')->where('role_id',$orderRole->id)->delete();
    check(\App\Models\User::withoutGlobalScopes()->findOrFail(12)->can('order-list'),
        'the pinned original permission cache really remains warm after an independent role grant revocation');
    $addressGuard->setUser($central);
    denied(fn()=>app(\App\Http\Middleware\DesktopDashboardJournal::class)->handle($request,
        fn($request)=>app(\App\Http\Controllers\Dashboard\PhoneOrdersController::class)->addressSuggestions($request,$maps)),403,
        'current pivot proof rejects a revoked warm-cache admin grant inside the original read-only controller transaction');
    denied(fn()=>$maps->suggestions(['branch'=>'f:100','query'=>'عنوان غير محفوظ'],$central),403,
        'the current grant is required before even an empty saved fallback');
    DB::table('model_has_permissions')->insert(['permission_id'=>$orderPermission->id,'model_type'=>$central->getMorphClass(),'model_id'=>$central->id]);
    check($maps->suggestions($values,$central)===$first,'a current direct admin permission remains sufficient without a role grant');
    DB::table('model_has_permissions')->where('model_id',12)->delete();
    denied(fn()=>$maps->suggestions($values,$central),403,'removing the current direct grant cannot fall back to a cached revoked role grant');
    $webSuper=\Spatie\Permission\Models\Role::create(['name'=>'Super Admin','guard_name'=>'web']);
    DB::table('model_has_roles')->insert(['role_id'=>$webSuper->id,'model_type'=>$central->getMorphClass(),'model_id'=>12]);
    denied(fn()=>$maps->suggestions($values,$central),403,'a foreign-guard Super Admin role cannot acquire local saved address access');
    $adminSuper=\Spatie\Permission\Models\Role::create(['name'=>'Super Admin','guard_name'=>'admin']);$central->assignRole($adminSuper);
    check($maps->suggestions($values,$central)===$first,'the original admin-guard Super Admin exception remains sufficient');
    DB::table('model_has_roles')->where('model_id',12)->delete();DB::table('users')->where('id',12)->update(['owner_resturant_id'=>100]);
    check($maps->suggestions($values,$central)===$first,'the original branch-scoped admin exception remains sufficient without central grants');
    denied(fn()=>$maps->suggestions(['branch'=>'f:101','query'=>'عنوان الفرع الآخر'],$central),404,
        'the branch-scoped admin exception never widens access to another branch');
    $addressPermissionMigration->down();$addressPermissionMigration=null;DB::table('users')->where('id',12)->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    check(DB::table('desktop_dashboard_commands')->count()===$addressBefore['journal']&&DB::table('branch_operation_commands')->count()===$addressBefore['operations'],
        'address reads, failures and empty fallback create zero journal or business operations');
    check(count($addressHttpCalls)===0,'the read-only local controller made no provider call');
    config(['desktop_dashboard.local'=>false,'services.maps.phone_open_enabled'=>false]);
    denied(fn()=>$maps->suggestions($values,$cashier),503,'online Photon still requires the original explicit activation');
    config(['services.maps.phone_open_enabled'=>true]);
    $online=$maps->suggestions(['branch'=>'f:100','query'=>'عنوان Photon الأصلي'],$cashier);
    check($online['provider']==='open'&&count($online['items'])===1&&$online['items'][0]['label']==='عنوان Photon الأصلي',
        'online suggestions retain the original Photon response path');
    check(count($addressHttpCalls)===1&&$addressHttpCalls[0]['q']==='عنوان Photon الأصلي'&&$addressHttpCalls[0]['countrycode']==='EG'
        &&$addressHttpCalls[0]['limit']===6&&!isset($addressHttpCalls[0]['customer_name'])&&!isset($addressHttpCalls[0]['customer_phone']),
        'online Photon retains original scoped query parameters without customer identity');
}finally{
    if($addressPermissionMigration)$addressPermissionMigration->down();DB::table('users')->where('id',12)->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    DB::table('users')->where('id',10)->update(['account_type'=>'vendor']);DB::table('resturants')->where('id',100)->update(['user_id'=>10]);
    DB::table('branch_customers')->whereIn('id',$addressIds)->delete();config($addressConfig);Http::swap($addressHttp);
    if($addressPreviousActor)$addressGuard->setUser($addressPreviousActor);else app('auth')->forgetGuards();
}
