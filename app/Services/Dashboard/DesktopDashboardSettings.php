<?php
namespace App\Services\Dashboard;

use App\Models\{GeneralSettings,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Schema,Validator};
use Spatie\LaravelSettings\Events\SavingSettings;

/** Original nonsecret settings forms, with complete public facts and a protected central balance. */
class DesktopDashboardSettings
{
    public const ROUTES=['updateSetting'=>['action'=>'update','path'=>'/admin/settings/update','return'=>'/admin/settings'],
        'updateAdvertising'=>['action'=>'update_advertising','path'=>'/admin/advertising/update','return'=>'/admin/advertising']];
    private const FIELDS=['site_name','email','phone','policy_en','policy_ar','about_en','about_ar','service_fees','terms_en','terms_ar',
        'contact_text_en','contact_text_ar','address','km_price','tax','vendor_tax','googleplay_link','applestore_link','vendor_googleplay_link',
        'vendor_applestore_link','delegate_googleplay_link','delegate_applestore_link','slider_title_en','slider_title_ar','slider_text_en',
        'slider_text_ar','shipping_km_price','shipping_min_price','shipping_cancelled_block_no','default_0_1','default_1_2','default_2_3',
        'app_banner_background_color','min_order_price','twitter_link','facebook_link','google_link','instagram_link'];
    private static bool $listening=false;
    private static ?string $protectedBalance=null;
    private bool $guarded=false;
    public static function handles(?string $name): bool {return isset(self::ROUTES[$name??'']);}
    /** Includes the ROOT transaction's outcome/journal writes, never only controller work. */
    public function guard(callable $work)
    {
        if($this->guarded)return $work();abort_unless(DB::transactionLevel()===0,409);
        $app=app();$original=$app['events'];$events=new DesktopDashboardSettingsEvents($original);
        $cache=app(\Spatie\LaravelSettings\Support\SettingsCacheFactory::class)->build(GeneralSettings::repository());
        if($cache->isEnabled())$cache->clear();$app->forgetInstance(GeneralSettings::class);
        $previous=$app->bound(self::class)?$app->make(self::class):null;$app->instance(self::class,$this);
        $app->instance('events',$events);Facade::clearResolvedInstance('events');$this->guarded=true;
        try{
            $result=$work();abort_unless(DB::transactionLevel()===0,409);
            foreach($events->saved as $event)$original->dispatch($event);
            return $result;
        }finally{
            $app->instance('events',$original);Facade::clearResolvedInstance('events');$this->guarded=false;
            $app->forgetInstance(self::class);if($previous)$app->instance(self::class,$previous);
            $app->forgetInstance(GeneralSettings::class);if($cache->isEnabled())$cache->clear();
        }
    }
    public function command(Request $request): string
    {
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409,'رقم عملية الإعدادات مختلف عن سجلها.');
        $id=$header??$form;Validator::make(['command'=>$id],['command'=>'required|uuid'])->validate();return $id;
    }
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\SettingsController@'.self::ROUTES[$name]['action']
            &&'/'.$route->uri()===self::ROUTES[$name]['path']&&$route->methods()===['PUT'],409);return $route;
    }
    private function state(): array
    {
        $names=array_map(fn($p)=>$p->getName(),(new \ReflectionClass(GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC));
        $rows=DB::table('settings')->where('group','general')->whereIn('name',$names)->orderBy('name')->lockForUpdate()->get();$state=[];
        abort_unless(count($rows)===count($names)&&count(array_unique($rows->pluck('name')->all()))===count($names),409,'صفوف الإعدادات ناقصة أو مكررة؛ العملية لم تُنفّذ.');
        foreach($rows as $record){$row=(array)$record;abort_unless(count($row)===7&&array_diff(array_keys($row),['id','group','name','locked','payload','created_at','updated_at'])===[]&&!isset($state[$row['name']]),409);
            if($row['name']==='app_balance')continue;
            $row['value']=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);unset($row['payload']);
            abort_unless(is_scalar($row['value'])||$row['value']===null,409);
            if($row['name']==='advertise_resturant_id'&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$row['value']))$row['value']=(int)$row['value'];
            $state[$row['name']]=$row;
        }
        return $state;
    }
    private function balance(): string
    {
        $rows=DB::table('settings')->where('group','general')->where('name','app_balance')->lockForUpdate()->get();abort_unless(count($rows)===1,409);
        $value=json_decode($rows[0]->payload,true,16,JSON_THROW_ON_ERROR);abort_unless(is_string($value)&&preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$value),409);
        return $value;
    }
    private function values(string $name,array $values): array
    {
        $allowed=$name==='updateSetting'?self::FIELDS:['advertise_resturant_id'];
        abort_if(array_diff(array_keys($values),[...$allowed,'idempotency_key']),422,'حقول الإعدادات غير مقبولة.');
        Validator::make($values,['idempotency_key'=>'required|uuid'])->validate();
        foreach($values as $value)abort_unless($value===null||is_scalar($value),422);
        if($name==='updateAdvertising'){Validator::make($values,['advertise_resturant_id'=>'required|integer|min:1'])->validate();$values['advertise_resturant_id']=(int)$values['advertise_resturant_id'];}
        return $values;
    }
    private function validateState(array $state): void
    {
        $names=array_map(fn($p)=>$p->getName(),(new \ReflectionClass(GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC));
        $names=array_values(array_diff($names,['app_balance']));sort($names);$actual=array_keys($state);sort($actual);abort_unless($actual===$names,409);
        $ids=[];
        foreach($state as $key=>$row){
            abort_unless(is_array($row)&&count($row)===7&&($row['name']??null)===$key&&($row['group']??null)==='general'
                &&array_diff(array_keys($row),['id','group','name','locked','value','created_at','updated_at'])===[]
                &&is_int($row['id'])&&$row['id']>0&&!isset($ids[$row['id']])
                &&(is_scalar($row['value'])||$row['value']===null)&&in_array($row['locked'],[0,1,false,true],true),409);
            foreach(['created_at','updated_at'] as $time)abort_unless($row[$time]===null||(is_string($row[$time])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$row[$time])),409);
            $ids[$row['id']]=true;
        }
    }
    private function facts(string $name,array $payload): array
    {
        abort_unless(array_diff(array_keys($payload),['parameters','values','files','facts'])===[]&&($payload['parameters']??[])===[]&&($payload['files']??[])===[],422);
        $this->values($name,$payload['values']??[]);$facts=$payload['facts']??[];
        abort_unless(array_keys($facts)===['settings_before']&&is_array($facts['settings_before'])&&!isset($facts['settings_before']['app_balance']),409);
        $this->validateState($facts['settings_before']);
        return $facts['settings_before'];
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $this->facts($name,$payload);$original=$this->original($name);
        foreach(['settings','users','resturants','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions','desktop_dashboard_devices','desktop_dashboard_commands','desktop_dashboard_entities'] as $table){
            $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);abort_unless($engine&&strcasecmp($engine->engine,'InnoDB')===0,409);}
        $device=DB::table('desktop_dashboard_devices')->where('id',$scope->device_id??$scope->id??'')->lockForUpdate()->first();
        abort_unless($device&&$device->enabled&&(int)$device->actor_id===(int)$scope->actor_id&&(int)$scope->actor_id===(int)$actor->id,403);
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)&&!in_array($fresh->status,['disabled','declined'],true),403);
        // These are current locking reads, including a caller with an earlier RR snapshot.
        $roles=$fresh->roles()->lockForUpdate()->get();$fresh->setRelation('roles',$roles);$fresh->setRelation('permissions',$fresh->permissions()->lockForUpdate()->get());
        $permissionRows=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->orderBy('id')->lockForUpdate()->get();
        $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->orderBy('permission_id')->lockForUpdate()->get();
        $roleGrants=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles->where('guard_name','admin')->pluck('id'))->orderBy('permission_id')->lockForUpdate()->get();
        $has=function(string $permission)use($permissionRows,$direct,$roleGrants){$id=$permissionRows->firstWhere('name',$permission)->id??null;return $id!==null&&($direct->contains('permission_id',$id)||$roleGrants->contains('permission_id',$id));};
        abort_unless((int)$fresh->id===1||$roles->contains(fn($role)=>$role->guard_name==='admin'&&$role->name==='Super Admin')||$has('order-list'),403);
        if($name==='updateSetting')abort_unless($has('setting-list'),403);
        $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);abort_unless(is_array($branches)&&count($branches)>0,403);
        $required=[];
        foreach(DB::table('resturants')->orderBy('id')->lockForUpdate()->get() as $branch)$required[]='f:'.$branch->id;
        // Enrollment is structural: temporarily declined GO owners still own administrative rows.
        if(Schema::hasTable('go_stores')&&Schema::hasColumn('go_stores','user_id')&&Schema::hasColumn('users','app_scope')){
            foreach(DB::table('go_stores')->orderBy('user_id')->lockForUpdate()->get() as $store){
                $owner=DB::table('users')->where('id',$store->user_id)->lockForUpdate()->first();
                if(!$owner||($owner->app_scope??null)!=='go_partner')continue;
                $eligible=$owner->account_type==='vendor';
                if($owner->account_type==='delegate'&&!empty($owner->pending_vendor_id)&&Schema::hasTable('pending_vendors')&&Schema::hasColumn('pending_vendors','profession_key'))
                    $eligible=DB::table('pending_vendors')->where('id',$owner->pending_vendor_id)->lockForUpdate()->value('profession_key')==='store_owner';
                if($eligible)$required[]='gs:'.$store->user_id;
            }
        }
        foreach($required as $branch)abort_unless(in_array($branch,$branches,true),403,'الإعدادات العامة تحتاج ربط الإدارة بكل الفروع الحالية.');
        if($name==='updateAdvertising')abort_unless(in_array('f:'.$payload['values']['advertise_resturant_id'],$required,true)&&in_array('f:'.$payload['values']['advertise_resturant_id'],$branches,true),403);
        $guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{$guard->setUser($fresh);auth()->shouldUse('admin');$probe=Request::create(url(self::ROUTES[$name]['path']),'PUT');
            if(request()->hasSession())$probe->setLaravelSession(request()->session());$probe->setRouteResolver(fn()=>$original);$probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router=app('router');$middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$original->controllerMiddleware()));
            (new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else(function(){$this->user=null;})->call($guard);auth()->shouldUse($default);}
        return $fresh;
    }
    public function payload(string $name,Request $request,string $command,User $actor,object $scope,?array $saved=null): array
    {
        abort_if($request->allFiles(),501,'مزامنة صور الإعدادات لم تُجهّز بعد؛ لم تُنفّذ العملية.');abort_unless($request->route()->parameters()===[],422);
        abort_if($request->has('idempotency_key')&&$request->input('idempotency_key')!==$command,409,'رقم عملية الإعدادات مختلف عن سجلها.');
        // The original read-only financial display is not an editable setting command.
        $values=$request->except('_token','_method','_desktop_command','app_balance');$values['idempotency_key']=$command;$values=$this->values($name,$values);
        $saved=$saved??app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name);
        $payload=['parameters'=>[],'values'=>$values,'files'=>[],'facts'=>$saved??['settings_before'=>$this->state()]];
        $this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach(['values.advertise_resturant_id','facts.settings_before.advertise_resturant_id.value'] as $path){$value=data_get($payload,$path);if(!is_array($value)&&is_numeric($value)&&(int)$value>0)data_set($payload,$path,$reference('menu_restaurant',(int)$value));}return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach(['values.advertise_resturant_id','facts.settings_before.advertise_resturant_id.value'] as $path){$value=data_get($payload,$path);if(is_array($value))abort_unless(array_keys($value)===['$desktop_ref']&&($value['$desktop_ref']['entity']??null)==='menu_restaurant',422);}
    }
    public function capture(string $name,array $payload,Request $request,callable $work): array
    {
        abort_unless($this->guarded&&DB::transactionLevel()>0&&self::$protectedBalance===null,409);$balance=$this->balance();
        if(!self::$listening){app('events')->listen(SavingSettings::class,function(SavingSettings $event){
            if(self::$protectedBalance!==null&&get_class($event->settings)===GeneralSettings::class){
                $event->properties->put('app_balance',self::$protectedBalance);$event->settings->app_balance=self::$protectedBalance;
            }});self::$listening=true;}
        self::$protectedBalance=$balance;$request->merge(['app_balance'=>$balance]);
        try{
            app(GeneralSettings::class)->refresh();$response=$work();$status=$response->getStatusCode();
            if($status>=400||($request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true)))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            abort_unless(in_array($status,[302,303],true)&&$this->balance()===$balance,409,'رصيد التطبيق لا يدخل في تعديل الإعدادات المحلية.');
            $parts=parse_url($response->headers->get('Location')??'');abort_unless(($parts['path']??'')===self::ROUTES[$name]['return']&&!isset($parts['query']),409);
            return ['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>self::ROUTES[$name]['return']],
                'success'=>session('success'),'after'=>$this->state(),'references'=>[]];
        }finally{self::$protectedBalance=null;}
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);if($http['type'])$response->headers->set('Content-Type',$http['type']);
        if($http['location'])$response->headers->set('Location',url($http['location']));if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    private function comparable(array $rows): array {foreach($rows as &$row)unset($row['updated_at']);unset($row);return $rows;}
    public function verifyAfter(array $server,array $local,array $resolved,array $original): void
    {
        abort_unless(is_array($server['after']??null)&&is_array($local['after']??null),409);$this->validateState($server['after']);$this->validateState($local['after']);
        $path='after.advertise_resturant_id.value';$old=data_get($original,'facts.settings_before.advertise_resturant_id.value');
        $id=is_array($old)?($old['$desktop_ref']['local_id']??null):$old;
        if(isset($local['after']['advertise_resturant_id'])){
            $expected=$original['values']['advertise_resturant_id']??$id;if(is_array($expected))$expected=$expected['$desktop_ref']['local_id']??null;
            abort_unless(data_get($local,$path)===$expected,409);data_set($local,$path,$resolved['values']['advertise_resturant_id']??data_get($resolved,'facts.settings_before.advertise_resturant_id.value'));
        }
        abort_unless(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($server['after']))===app(DesktopDashboardJournal::class)->fingerprint($this->comparable($local['after'])),409,'نتيجة الإعدادات تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);abort_unless(app(DesktopDashboardJournal::class)->fingerprint($before)===app(DesktopDashboardJournal::class)->fingerprint($this->state()),409,'الإعدادات العامة تغيّرت؛ العملية المحلية محفوظة للمراجعة.');
        $original=$this->original($name);$values=$payload['values'];unset($values['idempotency_key']);
        $request=Request::create(url(self::ROUTES[$name]['path']),'PUT',$values);$request->headers->set('Referer',url(self::ROUTES[$name]['return']));
        $session=new Store('desktop-settings-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{$app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone$original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,$request,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{$app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);if($previous)$guard->setUser($previous);else(function(){$this->user=null;})->call($guard);auth()->shouldUse($default);}
    }
}
