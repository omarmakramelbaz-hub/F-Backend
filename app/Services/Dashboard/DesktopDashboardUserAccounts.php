<?php
namespace App\Services\Dashboard;

use App\Models\{User,UserAddress};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Schema,Validator};

/** Bounded original account status and unreferenced-address removal, without credential writes. */
class DesktopDashboardUserAccounts
{
    private const ROUTES=[
        'users.change-status'=>['changeStatus','admin/change-status/{user}','GET','user'],
        'useraddresses.destroy'=>['userAddressDelete','admin/userAddressDelete/{id}','DELETE','id'],
    ];
    private const PRIVATE_COLUMN='/password|remember_token|(?:^|_)(?:token|secret|api_key|private_key|credential|partner_auth_email|fcm_id|verification_code|activation_code|mobile_code|email_code|otp_first_no)(?:$|_)/i';
    private const IDENTITY=['id','account_type','app_scope','owner_resturant_id','pending_vendor_id','mobile','email','created_at'];
    public static function handles(?string $name): bool {return isset(self::ROUTES[$name??'']);}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);[$action,$uri,$method]=self::ROUTES[$name];$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\UserController@'.$action&&$route->uri()===$uri
            &&$route->methods()===($method==='GET'?['GET','HEAD']:[$method])&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار حساب المستخدم الأصلي تغيّر.');return $route;
    }
    private function ready(): void
    {
        foreach(['users','user_address','orders','roles','permissions','role_has_permissions','model_has_roles','model_has_permissions','desktop_dashboard_devices','desktop_dashboard_commands','desktop_dashboard_entities'] as $table){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[DB::connection()->getDatabaseName(),$table]);
            abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,503,'عمليات الحسابات المحلية تحتاج جداول تدعم المعاملات.');
        }
    }
    private function id($value): int
    {
        abort_unless(is_scalar($value)&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(int)$value>0&&(string)(int)$value===(string)$value,422);return (int)$value;
    }
    public function command(Request $request): string
    {
        $header=$request->header('X-Fasakhansta-Command');$field=$request->input('_desktop_command');
        foreach([$header,$field] as $part)if($part!==null)Validator::make(['command'=>$part],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$field!==null&&$header!==$field,409);$command=$header??$field;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function user(int $id): array
    {
        $row=DB::table('users')->where('id',$id)->lockForUpdate()->first();abort_unless($row,409,'الحساب المحفوظ لم يعد موجودًا.');
        $user=[];foreach((array)$row as $key=>$value)if(!preg_match(self::PRIVATE_COLUMN,$key))$user[$key]=$value;
        return $user;
    }
    private function roles(int $id): array
    {
        $roles=DB::table(config('permission.table_names.model_has_roles'))->where('model_type',User::class)->where('model_id',$id)->orderBy('role_id')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();
        $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',User::class)->where('model_id',$id)->orderBy('permission_id')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();
        return ['roles'=>$roles,'direct'=>$direct];
    }
    private function unreferencedAddress(int $id): void
    {
        $hasOrderReference=false;
        foreach(DB::select('SELECT TABLE_NAME AS source_table,COLUMN_NAME AS source_column,REFERENCED_COLUMN_NAME AS target_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=?',[DB::connection()->getDatabaseName(),'user_address']) as $reference){
            foreach([$reference->source_table,$reference->source_column,$reference->target_column] as $part)abort_unless(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D',$part),503);
            if($reference->source_table==='orders'&&$reference->source_column==='user_address_id'&&$reference->target_column==='id')$hasOrderReference=true;
            abort_unless($reference->target_column==='id',501,'علاقة العنوان تحتاج مراجعة متصلة.');
            abort_if(DB::table($reference->source_table)->where($reference->source_column,$id)->lockForUpdate()->exists(),409,'العنوان مرتبط ببيانات محفوظة؛ الحذف يحتاج مراجعة متصلة.');
        }
        // Legacy deployments may omit the physical FK while retaining this relationship.
        if(Schema::hasTable('orders')&&Schema::hasColumn('orders','user_address_id')){
            abort_unless($hasOrderReference,501,'علاقة الطلب بالعنوان تحتاج قيدًا مثبتًا قبل الحذف المحلي.');
            abort_if(DB::table('orders')->where('user_address_id',$id)->lockForUpdate()->exists(),409,'العنوان مستخدم في طلب محفوظ؛ لا يُحذف محليًا.');}
    }
    private function state(string $name,int $id): array
    {
        $address=null;$userId=$id;
        if($name==='useraddresses.destroy'){
            $row=DB::table('user_address')->where('id',$id)->lockForUpdate()->first();abort_unless($row,409,'العنوان المحفوظ لم يعد موجودًا.');
            $this->unreferencedAddress($id);$address=(array)$row;$userId=$this->id($row->user_id);
        }
        $user=$this->user($userId);
        if($name==='users.change-status')abort_unless(in_array($user['account_type'],['admin','vendor','delegate','resturant_owner'],true),501,
            'تغيير حالة عميل التطبيق يحتاج مراجعة روابط دليل العملاء أولًا.');
        return ['user'=>$user,'memberships'=>$this->roles($userId),'address'=>$address];
    }
    private function facts(string $name,array $payload): array
    {
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);abort_unless(empty($payload['files']),422);
        $parameter=self::ROUTES[$name][3];abort_unless(array_keys($payload['parameters']??[])===[$parameter],422);$id=$this->id($payload['parameters'][$parameter]);
        $allowed=$name==='users.change-status'?['status','idempotency_key']:['idempotency_key'];abort_if(array_diff(array_keys($payload['values']??[]),$allowed),422);
        Validator::make($payload['values']??[],['idempotency_key'=>'required|uuid']+($name==='users.change-status'?['status'=>'required|in:accepted,declined']:[]))->validate();
        $before=$payload['facts']['user_account_before']??null;
        abort_unless(array_keys($payload['facts']??[])===['user_account_before']&&is_array($before)&&array_keys($before)===['user','memberships','address']
            &&is_array($before['user'])&&is_array($before['memberships'])&&($name==='users.change-status'?$before['address']===null:is_array($before['address'])),409,'حقائق الحساب أو العنوان غير مكتملة.');
        foreach(array_keys($before['user']) as $key)abort_if(preg_match(self::PRIVATE_COLUMN,$key),422,'بيانات الاعتماد لا تدخل سجل الحساب.');
        abort_unless($name==='users.change-status'?$this->id($before['user']['id']??null)===$id:$this->id($before['address']['id']??null)===$id
            &&$this->id($before['address']['user_id']??null)===$this->id($before['user']['id']??null),409);return $before;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($name,$payload);$this->original($name);return $this->authorizeAccount($name,$before,$actor,$scope);
    }
    public function staffState(int $id): array {return $this->state('users.change-status',$id);}
    /** Shared current administration authority, independent of the original controller's read cache. */
    public function authorizeAccount(string $name,array $before,User $actor,object $scope): User
    {
        abort_unless(self::handles($name)||$name==='users.update',422);$this->ready();
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)&&!in_array($fresh->status,['disabled','declined'],true)
            &&(int)$scope->actor_id===(int)$fresh->id,403);abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        if(config('desktop_dashboard.local')&&(string)config('desktop_dashboard.device_id')!==''){
            $enrolled=DB::table('desktop_dashboard_devices')->where('id',(string)config('desktop_dashboard.device_id'))->lockForUpdate()->first();
            abort_unless($enrolled&&$enrolled->enabled&&(int)$enrolled->actor_id===(int)$fresh->id,403,'ربط حساب الجهاز المحلي غير مفعّل.');
            if(app(DesktopDashboardCreatedScopes::class)->active())app(DesktopDashboardCreatedScopes::class)->assertStructural($scope,$fresh);
            else abort_unless(app(DesktopDashboardDevices::class)->baseBranches($enrolled)===json_decode($scope->branches,true),409);
        }
        // Only this original full-administration cohort imports complete account/role rows.
        // Lock current memberships, bypassing cached Spatie permissions and older RR reads.
        if((int)$fresh->id!==1){
            $ids=DB::table(config('permission.table_names.model_has_roles'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->lockForUpdate()->pluck('role_id');
            $ids=DB::table(config('permission.table_names.roles'))->whereIn('id',$ids)->where('guard_name','admin')->lockForUpdate()->pluck('id');
            abort_unless(DB::table(config('permission.table_names.roles'))->whereIn('id',$ids)->where('name','Super Admin')->lockForUpdate()->first(),403,'تعديل الحسابات محليًا يحتاج نسخة الإدارة الشاملة.');
        }
        $branches=app(DesktopDashboardDevices::class)->baseBranches($scope,DB::transactionLevel()>0);abort_unless(is_array($branches)&&count($branches)>0,403);
        foreach($branches as $branch){
            abort_unless(is_string($branch)&&preg_match('/^(f|gs):([1-9][0-9]{0,18})$/D',$branch,$parts),403);
            // Administration retains a declined GO account's structural scope, so it can
            // recover its immutable decline reply and explicitly reactivate that account.
            if($parts[1]==='gs')app(DesktopDashboardGoStoreProfile::class)->state((int)$parts[2]);
            else app(TakeawayAccess::class)->branch($branch,$fresh,true);
        }
        if((int)$fresh->id!==1){
            $permissionName=$before['user']['account_type'].($name==='useraddresses.destroy'?'-delete':'-edit');
            $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name',$permissionName)->lockForUpdate()->first();abort_unless($permission,403);
            $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
            $grant=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$ids)->where('permission_id',$permission->id)->lockForUpdate()->first();abort_unless($direct||$grant,403,'صلاحية تعديل هذا النوع من الحسابات لم تعد متاحة.');
        }
        $target=$this->id($before['user']['id']);
        abort_unless(!in_array($target,[1,560],true)&&($name==='useraddresses.destroy'||$target!==(int)$fresh->id),403,'هذا الحساب المحمي يحتاج مراجعة متصلة.');
        $current=$this->user($target);$identity=array_intersect_key($before['user'],array_flip(self::IDENTITY));
        abort_unless(count($identity)===count(self::IDENTITY)&&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($identity),
            app(DesktopDashboardJournal::class)->fingerprint(array_intersect_key($current,array_flip(self::IDENTITY))))
            &&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before['memberships']),app(DesktopDashboardJournal::class)->fingerprint($this->roles($target))),409,
            'هوية الحساب أو أدواره تغيرت؛ العملية محفوظة للمراجعة.');
        if($name!=='useraddresses.destroy')abort_unless(in_array($current['account_type'],['admin','vendor','delegate','resturant_owner'],true),501);
        return $fresh;
    }
    public function payload(string $name,Request $request,string $command,User $actor,?object $scope=null,?array $saved=null): array
    {
        abort_if($request->isMethod('HEAD')||$request->allFiles(),422);$this->original($name);$parameter=self::ROUTES[$name][3];
        abort_unless(array_keys($request->route()->parameters())===[$parameter],422);$value=$request->route($parameter);$id=$this->id($value instanceof \Illuminate\Database\Eloquent\Model?$value->getKey():$value);
        $values=$request->except('_token','_method','_desktop_command');abort_if(array_diff(array_keys($values),$name==='users.change-status'?['status']:[]),422);$values['idempotency_key']=$command;
        $scope=$scope??app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $saved=$saved??app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name);
        $payload=['parameters'=>[$parameter=>$id],'values'=>$values,'files'=>[],'facts'=>$saved??['user_account_before'=>$this->state($name,$id)]];
        $this->authorize($name,$payload,$actor,$scope);
        if($name==='users.change-status')$request->route()->setParameter('user',User::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail());
        return $payload;
    }
    private function paths(string $name,array $payload): array
    {
        $before=$payload['facts']['user_account_before']??[];$parameter=self::ROUTES[$name][3];
        $paths=['parameters.'.$parameter=>$name==='users.change-status'?'dashboard_user':'user_address',
            'facts.user_account_before.user.id'=>'dashboard_user','facts.user_account_before.user.added_by'=>'dashboard_user',
            'facts.user_account_before.user.area_id'=>'catalog_area','facts.user_account_before.user.owner_resturant_id'=>'menu_restaurant','facts.user_account_before.user.pending_vendor_id'=>'pending_vendor'];
        if($name==='useraddresses.destroy')$paths+=['facts.user_account_before.address.id'=>'user_address','facts.user_account_before.address.user_id'=>'dashboard_user','facts.user_account_before.address.area_id'=>'catalog_area'];
        foreach($before['memberships']['roles']??[] as $i=>$row)$paths+=['facts.user_account_before.memberships.roles.'.$i.'.model_id'=>'dashboard_user','facts.user_account_before.memberships.roles.'.$i.'.role_id'=>'catalog_role'];
        foreach($before['memberships']['direct']??[] as $i=>$row)$paths['facts.user_account_before.memberships.direct.'.$i.'.model_id']='dashboard_user';return $paths;
    }
    public function inputs(string $name,array $payload,callable $reference): array
    {
        foreach($this->paths($name,$payload) as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}return $payload;
    }
    public function validateReferences(string $name,array $payload): void
    {
        foreach($this->paths($name,$payload) as $path=>$entity){$value=data_get($payload,$path);if(is_array($value))abort_unless(count($value)===1&&is_array($value['$desktop_ref']??null)&&preg_match('/^'.preg_quote($entity,'/').'(?:\.[0-9]+)?$/D',(string)($value['$desktop_ref']['entity']??'')),422);}
    }
    public function verifyLocal(array $payload,array $result,array $references): void
    {
        $id=data_get($payload,'facts.user_account_before.user.id');if(is_array($id))$id=$id['$desktop_ref']['local_id']??null;
        $id=$this->id($id);$expected=['dashboard_user'=>$id,'wallet_account'=>$id];
        abort_unless($references===$expected&&($result['references']??null)===$expected,409,'نتيجة الحساب المحلي لا تخص هويته المثبتة.');
    }
    public function capture(string $name,array $payload,callable $work): array
    {
        $before=$this->facts($name,$payload);$response=$work();$http=$response->getStatusCode();abort_unless(in_array($http,[302,303],true),409);
        $parts=parse_url($response->headers->get('Location')??'');$location=($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'');abort_unless(str_starts_with($location,'/admin/'),409);
        $user=$this->user($this->id($before['user']['id']));
        if($name==='users.change-status'){
            $expected=$before['user'];$expected['status']=$payload['values']['status'];if($expected['status']==='accepted')$expected['expiration_date']=null;
            unset($expected['updated_at']);$actual=$user;unset($actual['updated_at']);
            abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($expected),app(DesktopDashboardJournal::class)->fingerprint($actual)),409,'تغيير الحالة عدّل بيانات غير متوقعة.');
        }else{abort_if(DB::table('user_address')->where('id',$this->id($before['address']['id']))->exists(),409);
            abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before['user']),app(DesktopDashboardJournal::class)->fingerprint($user)),409);}
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before['memberships']),app(DesktopDashboardJournal::class)->fingerprint($this->roles((int)$user['id']))),409);
        return ['http'=>['status'=>$http,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'references'=>['dashboard_user'=>(int)$user['id'],'wallet_account'=>(int)$user['id']]];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);if($http['type'])$response->headers->set('Content-Type',$http['type']);if($http['location'])$response->headers->set('Location',url($http['location']));return $response;
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);$parameter=self::ROUTES[$name][3];$id=$this->id($payload['parameters'][$parameter]);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),app(DesktopDashboardJournal::class)->fingerprint($this->state($name,$id))),409,'الحساب أو العنوان تغير على السيرفر؛ العملية محفوظة للمراجعة.');
        $original=$this->original($name);$values=$payload['values'];unset($values['idempotency_key']);$uri='/'.str_replace('{'.$parameter.'}',(string)$id,$original->uri());
        $request=Request::create(url($uri),self::ROUTES[$name][2],$values);$request->headers->set('Referer',url('/admin/users?account_type='.$before['user']['account_type']));
        $session=new Store('desktop-user-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
