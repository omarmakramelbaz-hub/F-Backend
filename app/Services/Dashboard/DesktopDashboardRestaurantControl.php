<?php
namespace App\Services\Dashboard;

use App\Models\{Resturant,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** The original global toggle is one atomic UUID, never one toggle per row or retry. */
class DesktopDashboardRestaurantControl
{
    public const ROUTE='resturantControl';
    private const COLUMNS=['id','added_by','user_id','name','status','created_at','updated_at','parent_id','address','avg_rate','lat','lng',
        'delivery_time','country_name','city_name','is_featured','resturant_phone','area_id','under_contract','open_at','close_at','min_order_price',
        'service_fees','km_price','sortby_is_featured','control','default_0_1','default_1_2','default_2_3'];
    private const IDENTITIES=['id'=>'menu_restaurant','added_by'=>'restaurant_flag_user','user_id'=>'restaurant_flag_user',
        'parent_id'=>'menu_restaurant','area_id'=>'catalog_area'];
    public static function handles(?string $route): bool {return $route===self::ROUTE;}
    private function original()
    {
        $route=app('router')->getRoutes()->getByName(self::ROUTE);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\HomeController@resturantControl'
            &&$route->uri()==='admin/resturantControl'&&$route->methods()===['GET','HEAD']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409);
        return $route;
    }
    private function id($value): int
    {
        abort_unless((is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)
            &&(int)$value>0&&(string)(int)$value===(string)$value,422);return (int)$value;
    }
    public function command(Request $request): string
    {
        abort_unless($request->isMethod('GET'),405);
        $header=$request->header('X-Fasakhansta-Command');$query=$request->input('_desktop_command');
        foreach([$header,$query] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$query!==null&&$header!==$query,409,'رقم العملية مختلف عن سجلها.');
        $command=$header??$query;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function transactional(): void
    {
        abort_unless(DB::connection()->getDriverName()==='mysql'&&DB::connection()->getTablePrefix()==='',409);
        foreach(['resturants','users','desktop_dashboard_devices','desktop_dashboard_commands','desktop_dashboard_entities'] as $table){
            $row=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
            abort_unless($row&&strcasecmp($row->engine,'InnoDB')===0,409,'تعديل التحكم يتطلب جداول تدعم التراجع الذري.');
        }
    }
    private function rows(array $rows): void
    {
        abort_unless(array_is_list($rows)&&count($rows)<=5000,409);$seen=[];
        foreach($rows as $row){
            abort_unless(is_array($row)&&count($row)===count(self::COLUMNS)&&array_diff(array_keys($row),self::COLUMNS)===[],409);
            foreach($row as $value)abort_unless($value===null||is_scalar($value),409);
            $id=$this->id($row['id']);abort_if($id===82||isset($seen[$id]),409);$seen[$id]=true;
            foreach(self::IDENTITIES as $field=>$kind)if($row[$field]!==null)$this->id($row[$field]);
            foreach(['created_at','updated_at'] as $field)abort_unless($row[$field]===null||(is_string($row[$field])
                &&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$row[$field])),409);
        }
    }
    private function selected(): array
    {
        // Exact original model query, including its original global scope. Authorization below
        // limits this increment to admin actors, whose global scope is intentionally unrestricted.
        $rows=Resturant::where('id','!=',82)->orderBy('id')->lockForUpdate()->get()->map(fn($row)=>$row->getAttributes())->all();
        $this->rows($rows);return $rows;
    }
    private function returnPath($value): string
    {
        abort_unless(is_string($value)&&strlen($value)<=4096,422);$parts=parse_url($value);
        abort_unless(is_array($parts)&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['fragment'])
            &&in_array($parts['path']??'', ['/admin/dashboard','/admin/resturants'],true),422,'رابط العودة خارج صفحة التحكم.');
        if(isset($parts['host'])){$origin=parse_url(url('/'));abort_unless(($parts['scheme']??null)===($origin['scheme']??null)
            &&strcasecmp($parts['host'],$origin['host']??'')===0&&($parts['port']??null)===($origin['port']??null),422);}
        $query=$parts['query']??'';abort_if($query!==''&&!preg_match('/^(?:page=[1-9][0-9]{0,6})$/D',$query),422);
        return $parts['path'].($query!==''?'?'.$query:'');
    }
    private function facts(array $payload): array
    {
        abort_unless(array_keys($payload)===['parameters','values','files','facts']&&$payload['parameters']===[]&&$payload['files']===[]
            &&is_array($payload['values'])&&array_keys($payload['values'])===['idempotency_key']&&is_array($payload['facts']),422);
        Validator::make($payload['values'],['idempotency_key'=>'required|uuid'])->validate();
        abort_unless(array_keys($payload['facts'])===['restaurant_control_before','restaurant_control_return']
            &&is_array($payload['facts']['restaurant_control_before']),409);
        $this->rows($payload['facts']['restaurant_control_before']);$this->returnPath($payload['facts']['restaurant_control_return']);
        return $payload['facts']['restaurant_control_before'];
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        abort_unless(self::handles($name),422);$this->transactional();$before=$this->facts($payload);$original=$this->original();
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        // The hidden global navbar action has no original resource permission. It may toggle the
        // entire selected fleet only on an admin device enrolled for EVERY selected branch.
        abort_unless($fresh&&$fresh->account_type==='admin'&&!in_array($fresh->status,['disabled','declined'],true)
            &&(int)$scope->actor_id===(int)$fresh->id,403);abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        $branches=json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR);abort_unless(is_array($branches),403);
        $guard=auth('admin');$previous=$guard->getUser();
        try{
            $guard->setUser($fresh);$current=$this->selected();
            foreach(array_merge($before,$current) as $row)abort_unless(in_array('f:'.$this->id($row['id']),$branches,true),403,'كل الفروع التي يغيّرها التحكم يجب أن تكون ضمن ربط الجهاز.');
            $old=array_column($before,'id');$actual=array_column($current,'id');sort($old);sort($actual);
            abort_unless($old===$actual,409,'قائمة فروع التحكم تغيّرت؛ العملية محفوظة للمراجعة.');
            $probe=Request::create(url('/admin/resturantControl'),'GET');if(request()->hasSession())$probe->setLaravelSession(request()->session());
            $route=clone $original;$route->flushController();$route->bind($probe);$probe->setRouteResolver(fn()=>$route);
            $probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            (new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
        return $fresh;
    }
    private function requestPayload(Request $request,string $command,?array $saved): array
    {
        abort_unless($request->isMethod('GET')&&$request->route()->parameters()===[],405);abort_if($request->allFiles(),501);
        $values=$request->except('_desktop_command');abort_unless($values===[],422);$values=['idempotency_key'=>$command];
        $payload=['parameters'=>[],'values'=>$values,'files'=>[],'facts'=>$saved??['restaurant_control_before'=>$this->selected(),
            'restaurant_control_return'=>$this->returnPath($request->header('Referer')??url('/admin/dashboard'))]];
        $this->facts($payload);return $payload;
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {
        $device=(string)config('desktop_dashboard.device_id');$scope=app(DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);
        $saved=app(DesktopDashboardJournal::class)->savedFacts($device,$command,(int)$actor->id,$name);
        $payload=$this->requestPayload($request,$command,$saved);$this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        $payload=$this->requestPayload($request,$command,$saved);$this->authorize($name,$payload,$actor,$device);return $payload;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($payload['facts']['restaurant_control_before']??[] as $i=>$row)foreach(self::IDENTITIES as $field=>$entity)
            if(isset($row[$field])&&!is_array($row[$field]))$payload['facts']['restaurant_control_before'][$i][$field]=$reference($entity,$row[$field]);
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach($payload['facts']['restaurant_control_before']??[] as $row)if(is_array($row))foreach(self::IDENTITIES as $field=>$entity){
            $value=$row[$field]??null;if(is_array($value))abort_unless(array_keys($value)===['$desktop_ref']&&is_array($value['$desktop_ref'])
                &&($value['$desktop_ref']['entity']??null)===$entity,422,'نوع مرجع فرع التحكم غير صحيح.');
        }
    }
    private function comparable(array $rows): array {foreach($rows as &$row)unset($row['updated_at']);unset($row);return $rows;}
    private function ordered(array $before,array $current): array
    {
        $byId=[];foreach($current as $row)$byId[$row['id']]=$row;$rows=[];
        abort_unless(count($before)===count($current),409);
        foreach($before as $row){$id=$this->id($row['id']);abort_unless(isset($byId[$id]),409);$rows[]=$byId[$id];}return $rows;
    }
    public function capture(string $name,array $payload,callable $work): array
    {
        $before=$this->facts($payload);$response=$work();$status=$response->getStatusCode();
        abort_unless(in_array($status,[302,303],true)&&strlen($response->getContent())<=1024*1024,409);
        $location=$this->returnPath($response->headers->get('Location')??'');
        abort_unless($location===$payload['facts']['restaurant_control_return'],409);
        return ['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'success'=>session('success'),'after'=>$this->ordered($before,$this->selected()),'references'=>[]];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);if($http['type'])$response->headers->set('Content-Type',$http['type']);
        $response->headers->set('Location',url($this->returnPath($http['location'])));if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    public function outputs(array $result): array {return [];}
    public function verifyAfter(array $server,array $local,array $resolved,array $original): void
    {
        abort_unless(is_array($server['after']??null)&&is_array($local['after']??null),409);$this->rows($server['after']);$this->rows($local['after']);
        $before=$this->facts($resolved);abort_unless(count($before)===count($local['after'])&&count($before)===count($server['after']),409);
        foreach($local['after'] as $i=>&$row){foreach(self::IDENTITIES as $field=>$entity){
            $old=$original['facts']['restaurant_control_before'][$i][$field]??null;$id=is_array($old)?($old['$desktop_ref']['local_id']??null):$old;
            abort_unless($row[$field]===$id,409,'نتيجة التحكم المحلية غيّرت هوية فرع أو رابطه.');$row[$field]=$before[$i][$field];
        }}unset($row);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($server['after'])),
            app(DesktopDashboardJournal::class)->fingerprint($this->comparable($local['after']))),409,'نتيجة التحكم تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($payload);$original=$this->original();$guard=auth('admin');$previous=$guard->getUser();
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$default=auth()->getDefaultDriver();
        $session=new Store('desktop-restaurant-control-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);
        $request=Request::create(url('/admin/resturantControl'),'GET');$request->headers->set('Referer',url($payload['facts']['restaurant_control_return']));$request->setLaravelSession($session);
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');
            $app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $current=$this->ordered($before,$this->selected());abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($before)),
                app(DesktopDashboardJournal::class)->fingerprint($this->comparable($current))),409,'فروع التحكم تغيّرت؛ العملية المحلية محفوظة للمراجعة.');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
