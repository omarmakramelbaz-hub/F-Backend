<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Original profile form/controller; the trusted repository context only permits a public name edit. */
class DesktopDashboardUserProfiles
{
    private static ?array $context=null;
    private const USER_CONTROLS=['account_type','mobile','email','owner_resturant_id','pending_vendor_id','delegate_fees','min_wallet','area_id','gender'];
    private const PENDING_CONTROLS=['national_id','commercial_registration_no','driving_license_no','tax_no','owner_name','branches_no','location','vodafone_cash_mobile','another_mobile'];
    private const PRIVATE_COLUMN='/password|remember_token|(?:^|_)(?:token|secret|api_key|private_key|credential|partner_auth_email|fcm_id|verification_code|activation_code|mobile_code|email_code|otp_first_no)(?:$|_)/i';
    public static function handles(?string $name): bool {return $name==='users.update';}
    private function original()
    {
        $route=app('router')->getRoutes()->getByName('users.update');
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\UserController@update'&&$route->uri()==='admin/users/{user}'
            &&$route->methods()===['PUT','PATCH']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار تعديل الحساب الأصلي تغير.');return $route;
    }
    private function id($value): int
    {
        abort_unless(is_scalar($value)&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(string)(int)$value===(string)$value,422);return (int)$value;
    }
    public function command(Request $request): string {return app(DesktopDashboardUserAccounts::class)->command($request);}
    private function state(int $id): array
    {
        $account=app(DesktopDashboardUserAccounts::class)->staffState($id);$pending=null;
        if(!empty($account['user']['pending_vendor_id'])){
            $row=DB::table('pending_vendors')->where('id',$account['user']['pending_vendor_id'])->lockForUpdate()->first();abort_unless($row,409);
            $pending=[];foreach((array)$row as $key=>$value)if(!preg_match(self::PRIVATE_COLUMN,$key))$pending[$key]=$value;
        }
        return ['account'=>$account,'pending'=>$pending];
    }
    private function scalar($value): string {abort_unless($value===null||is_scalar($value),422);return $value===null?'':(string)$value;}
    private function number($value): string
    {
        $value=$this->scalar($value);if($value==='')return '';abort_unless(preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D',$value,$m),422);
        $integer=ltrim($m[2],'0')?:'0';$fraction=rtrim($m[3]??'','0');return ($m[1]&&($integer!=='0'||$fraction!=='')?'-':'').$integer.($fraction!==''?'.'.$fraction:'');
    }
    private function controls(array $values,array $before,int $actor): void
    {
        abort_if(array_diff(array_keys($values),['name','idempotency_key','password','roles_name','added_by',...self::USER_CONTROLS,...self::PENDING_CONTROLS]),422,'حقول تعديل الحساب غير معتمدة.');
        Validator::make($values,['name'=>'required|string|min:2|max:130','account_type'=>'required|string','idempotency_key'=>'required|uuid'])->validate();
        abort_unless(!isset($values['password'])||$values['password']==='',422,'تغيير كلمة المرور يحتاج الداشبورد المتصل.');
        $user=$before['account']['user'];
        foreach(self::USER_CONTROLS as $key)if(array_key_exists($key,$values)){
            $normalize=in_array($key,['delegate_fees','min_wallet'],true)?'number':'scalar';
            abort_unless($this->$normalize($values[$key])===$this->$normalize($user[$key]??null),409,'يمكن تعديل الاسم فقط؛ هوية الحساب وحقوله المالية لا تتغير محليًا.');
        }
        if(array_key_exists('added_by',$values))abort_unless($this->scalar($values['added_by'])===(string)$actor,409);
        foreach(self::PENDING_CONTROLS as $key)if(array_key_exists($key,$values))abort_unless($this->scalar($values[$key])===$this->scalar($before['pending'][$key]??null),409,'بيانات طلب الانضمام تحتاج تعديلًا متصلًا.');
        if(array_key_exists('roles_name',$values)){
            abort_unless(is_array($values['roles_name'])&&count($values['roles_name'])<=100,422);$requested=array_values($values['roles_name']);foreach($requested as $role)abort_unless(is_string($role)&&$role!=='',422);sort($requested);
            $ids=array_column($before['account']['memberships']['roles'],'role_id');$actual=DB::table(config('permission.table_names.roles'))->whereIn('id',$ids)->where('guard_name','admin')->orderBy('name')->lockForUpdate()->pluck('name')->all();
            abort_unless($requested===$actual,409,'تغيير الأدوار يحتاج الداشبورد المتصل.');
        }
    }
    private function facts(array $payload): array
    {
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);abort_unless(empty($payload['files'])&&array_keys($payload['parameters']??[])===['user'],422);
        $id=$this->id($payload['parameters']['user']);$before=$payload['facts']['user_profile_before']??null;
        abort_unless(array_keys($payload['facts']??[])===['user_profile_before']&&is_array($before)&&array_keys($before)===['account','pending']&&is_array($before['account'])
            &&array_keys($before['account'])===['user','memberships','address']&&$before['account']['address']===null&&is_array($before['account']['user'])&&is_array($before['account']['memberships'])
            &&($before['pending']===null||is_array($before['pending']))&&$this->id($before['account']['user']['id']??null)===$id,409);
        foreach([$before['account']['user'],$before['pending']??[]] as $row)foreach(array_keys($row) as $key)abort_if(preg_match(self::PRIVATE_COLUMN,$key),422);
        abort_unless(empty($before['account']['user']['pending_vendor_id'])?$before['pending']===null:$this->id($before['pending']['id']??null)===(int)$before['account']['user']['pending_vendor_id'],409);return $before;
    }
    public function authorize(array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($payload);$this->original();$fresh=app(DesktopDashboardUserAccounts::class)->authorizeAccount('users.update',$before['account'],$actor,$scope);
        $this->controls($payload['values']??[],$before,(int)$fresh->id);
        if((int)$fresh->id===1){$permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name',$before['account']['user']['account_type'].'-edit')->lockForUpdate()->first();abort_unless($permission,403);
            $roles=DB::table(config('permission.table_names.model_has_roles'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->lockForUpdate()->pluck('role_id');$roles=DB::table(config('permission.table_names.roles'))->whereIn('id',$roles)->where('guard_name','admin')->lockForUpdate()->pluck('id');
            $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
            abort_unless($direct||DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles)->where('permission_id',$permission->id)->lockForUpdate()->first(),403);}
        return $fresh;
    }
    public function payload(Request $request,string $command,User $actor,object $scope,?array $saved=null): array
    {
        abort_unless(in_array($request->method(),['PUT','PATCH'],true),422);abort_if($request->allFiles(),501,'رفع ملفات الحساب يحتاج الداشبورد المتصل.');$this->original();
        abort_unless(array_keys($request->route()->parameters())===['user'],422);$bound=$request->route('user');$id=$this->id($bound instanceof User?$bound->id:$bound);
        $values=$request->except('_token','_method','_desktop_command');abort_if(array_key_exists('idempotency_key',$values),422);$values['idempotency_key']=$command;
        $saved=$saved??app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,'users.update');
        $payload=['parameters'=>['user'=>$id],'values'=>$values,'files'=>[],'facts'=>$saved??['user_profile_before'=>$this->state($id)]];$this->authorize($payload,$actor,$scope);
        $request->route()->setParameter('user',User::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail());return $payload;
    }
    /** Only PHP call context creates this capability; no request field activates it. */
    public function within(array $payload,callable $work)
    {
        $before=$this->facts($payload);$previous=self::$context;self::$context=['id'=>(int)$before['account']['user']['id'],'name'=>$payload['values']['name']];
        try{return $work();}finally{self::$context=$previous;}
    }
    public static function repositoryUpdate(int $id,array $details): ?User
    {
        if(self::$context===null)return null;
        abort_unless($id===self::$context['id']&&($details['name']??null)===self::$context['name'],409,'سياق تعديل الملف لا يخص هذا الحساب.');
        $user=User::whereId($id)->firstOrFail();$user->update(['name'=>self::$context['name']]);return $user;
    }
    public function capture(array $payload,callable $work): array
    {
        $before=$this->facts($payload);abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),app(DesktopDashboardJournal::class)->fingerprint($this->state((int)$before['account']['user']['id']))),409,'بيانات الحساب تغيرت؛ التعديل محفوظ للمراجعة.');
        $response=$this->within($payload,$work);abort_unless(in_array($response->getStatusCode(),[302,303],true),409);$parts=parse_url($response->headers->get('Location')??'');$location=($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'');abort_unless(str_starts_with($location,'/admin/'),409);
        $after=$this->state((int)$before['account']['user']['id']);$expected=$before;$expected['account']['user']['name']=$payload['values']['name'];unset($expected['account']['user']['updated_at']);$actual=$after;unset($actual['account']['user']['updated_at']);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($expected),app(DesktopDashboardJournal::class)->fingerprint($actual)),409,'تعديل الاسم غيّر بيانات غير متوقعة.');
        return ['http'=>['status'=>$response->getStatusCode(),'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'name'=>$after['account']['user']['name'],'references'=>['dashboard_user'=>(int)$after['account']['user']['id'],'wallet_account'=>(int)$after['account']['user']['id']]];
    }
    public function response(array $result){return app(DesktopDashboardUserAccounts::class)->response($result);}
    private function paths(array $payload): array
    {
        $p=['parameters.user'=>'dashboard_user','facts.user_profile_before.account.user.id'=>'dashboard_user','facts.user_profile_before.account.user.added_by'=>'dashboard_user','facts.user_profile_before.account.user.area_id'=>'catalog_area',
            'facts.user_profile_before.account.user.owner_resturant_id'=>'menu_restaurant','facts.user_profile_before.account.user.pending_vendor_id'=>'pending_vendor','values.pending_vendor_id'=>'pending_vendor','values.owner_resturant_id'=>'menu_restaurant','values.area_id'=>'catalog_area'];
        if($payload['facts']['user_profile_before']['pending']??null)$p+=['facts.user_profile_before.pending.id'=>'pending_vendor','facts.user_profile_before.pending.added_by'=>'dashboard_user'];
        foreach($payload['facts']['user_profile_before']['account']['memberships']['roles']??[] as $i=>$row)$p+=['facts.user_profile_before.account.memberships.roles.'.$i.'.model_id'=>'dashboard_user','facts.user_profile_before.account.memberships.roles.'.$i.'.role_id'=>'catalog_role'];
        foreach($payload['facts']['user_profile_before']['account']['memberships']['direct']??[] as $i=>$row)$p['facts.user_profile_before.account.memberships.direct.'.$i.'.model_id']='dashboard_user';return $p;
    }
    public function inputs(array $payload,callable $reference): array {foreach($this->paths($payload) as $path=>$kind){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($kind,$value));}return $payload;}
    public function validateReferences(array $payload): void {foreach($this->paths($payload) as $path=>$kind){$value=data_get($payload,$path);if(is_array($value))abort_unless(count($value)===1&&is_array($value['$desktop_ref']??null)&&preg_match('/^'.preg_quote($kind,'/').'(?:\.[0-9]+)?$/D',(string)($value['$desktop_ref']['entity']??'')),422);}}
    public function verifyLocal(array $payload,array $result,array $references): void
    {
        $id=$payload['facts']['user_profile_before']['account']['user']['id']??null;if(is_array($id))$id=$id['$desktop_ref']['local_id']??null;$id=$this->id($id);$expected=['dashboard_user'=>$id,'wallet_account'=>$id];
        abort_unless($references===$expected&&($result['references']??null)===$expected&&($result['name']??null)===($payload['values']['name']??null),409);
    }
    public function execute(array $payload,User $actor): array
    {
        $before=$this->facts($payload);$original=$this->original();$id=$this->id($payload['parameters']['user']);$values=$payload['values'];unset($values['idempotency_key']);
        $request=Request::create(url('admin/users/'.$id),'PUT',$values);$request->headers->set('Referer',url('/admin/users/'.$id.'/edit?account_type='.$before['account']['user']['account_type']));
        $session=new Store('desktop-profile-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($payload,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
