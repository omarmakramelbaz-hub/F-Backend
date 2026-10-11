<?php
namespace App\Services\Dashboard;

use App\Models\{Resturant,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Journal only the two original restaurant checkbox updates, using their original scoped controller. */
class DesktopDashboardRestaurantFlags
{
    public const ROUTES=[
        'resturants.changeStatus'=>['action'=>'changeStatus','field'=>'is_featured','uri'=>'admin/resturantchangeStatus/{resturant}'],
        'resturants.changeUnderContract'=>['action'=>'changeUnderContract','field'=>'under_contract','uri'=>'admin/resturantchangeUnderContract/{resturant}'],
    ];
    private const COLUMNS=[
        'id','added_by','user_id','name','status','created_at','updated_at','parent_id','address','avg_rate','lat','lng',
        'delivery_time','country_name','city_name','is_featured','resturant_phone','area_id','under_contract','open_at',
        'close_at','min_order_price','service_fees','km_price','sortby_is_featured','control','default_0_1','default_1_2','default_2_3',
    ];
    private const REFERENCES=[
        'parameters.resturant'=>'menu_restaurant',
        'facts.restaurant_flag_before.row.id'=>'menu_restaurant',
        'facts.restaurant_flag_before.row.parent_id'=>'menu_restaurant',
        'facts.restaurant_flag_before.row.added_by'=>'restaurant_flag_user',
        'facts.restaurant_flag_before.row.user_id'=>'restaurant_flag_user',
        'facts.restaurant_flag_before.row.area_id'=>'catalog_area',
        'facts.restaurant_flag_before.parent.id'=>'menu_restaurant',
        'facts.restaurant_flag_before.parent.parent_id'=>'menu_restaurant',
        'facts.restaurant_flag_before.parent.user_id'=>'restaurant_flag_user',
        'facts.restaurant_flag_before.added_by.id'=>'restaurant_flag_user',
        'facts.restaurant_flag_before.owner.id'=>'restaurant_flag_user',
        'facts.restaurant_flag_before.branch.id'=>'menu_restaurant',
    ];
    public static function handles(?string $route): bool {return isset(self::ROUTES[$route??'']);}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);
        $definition=self::ROUTES[$name];$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\ResturantController@'.$definition['action']
            &&$route->uri()===$definition['uri']&&$route->methods()===['POST']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار تعديل علامة المطعم الأصلي تغيّر.');
        return $route;
    }
    private function id($value): int
    {
        abort_unless(is_scalar($value)&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(int)$value>0&&(string)(int)$value===(string)$value,422);
        return (int)$value;
    }
    public function command(Request $request): string
    {
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409,'رقم طلب العملية مختلف عن سجلها.');
        $command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(string $name,array $values): array
    {
        abort_unless(self::handles($name),422);
        abort_if(array_diff(array_keys($values),['idempotency_key',self::ROUTES[$name]['field'],'page']),422);
        Validator::make(['command'=>$values['idempotency_key']??null],['command'=>'required|uuid'])->validate();
        // Preserve request('field') == 'on' in the original controller. An absent checkbox stays absent.
        return $values;
    }
    private function state(int $id,bool $replay=false): array
    {
        $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();
        abort_unless($row,$replay?409:404,'المطعم المحفوظ لم يعد موجودًا؛ العملية المحلية محفوظة للمراجعة.');$row=(array)$row;
        abort_unless(count($row)===count(self::COLUMNS)&&array_diff(array_keys($row),self::COLUMNS)===[],409,'أعمدة المطعم تحتاج مراجعة قبل المزامنة.');
        $added=DB::table('users')->where('id',$row['added_by'])->lockForUpdate()->first(['id']);
        $owner=DB::table('users')->where('id',$row['user_id'])->lockForUpdate()->first(['id']);
        $parent=isset($row['parent_id'])?DB::table('resturants')->where('id',$row['parent_id'])->lockForUpdate()->first(['id','user_id','parent_id']):null;
        abort_unless($added&&$owner&&(!isset($row['parent_id'])||$parent),409,'روابط المطعم المحفوظة غير مكتملة.');
        return ['row'=>$row,'added_by'=>(array)$added,'owner'=>(array)$owner,'parent'=>$parent?(array)$parent:null,'branch'=>['kind'=>'f','id'=>$id]];
    }
    private function facts(string $name,array $payload): array
    {
        abort_unless(is_array($payload['values']??null)&&is_array($payload['parameters']??null)&&is_array($payload['facts']??null),422);
        $this->values($name,$payload['values']??[]);
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);
        abort_unless(empty($payload['files'])&&array_keys($payload['parameters']??[])===['resturant'],422);
        abort_unless(array_keys($payload['facts']??[])===['restaurant_flag_before']&&is_array($payload['facts']['restaurant_flag_before']),409);
        $before=$payload['facts']['restaurant_flag_before'];$row=$before['row']??null;
        abort_unless(count($before)===5&&array_diff(array_keys($before),['row','added_by','owner','parent','branch'])===[],409);
        abort_unless(is_array($row)&&count($row)===count(self::COLUMNS)&&array_diff(array_keys($row),self::COLUMNS)===[],409);
        foreach(['added_by'=>['id'],'owner'=>['id'],'parent'=>['id','user_id','parent_id'],'branch'=>['kind','id']] as $relation=>$keys){
            $actual=$before[$relation]??null;if($relation==='parent'&&$actual===null)continue;
            abort_unless(is_array($actual)&&count($actual)===count($keys)&&array_diff(array_keys($actual),$keys)===[],409);
        }
        $id=$this->id($payload['parameters']['resturant']);
        abort_unless($id===$this->id($row['id'])&&($before['branch']['kind']??null)==='f'&&$id===$this->id($before['branch']['id'])
            &&$this->id($row['added_by'])===$this->id($before['added_by']['id'])&&$this->id($row['user_id'])===$this->id($before['owner']['id']),409,'هوية المطعم أو روابطه غير متطابقة.');
        abort_unless(isset($row['parent_id'])?is_array($before['parent'])&&$this->id($row['parent_id'])===$this->id($before['parent']['id']):$before['parent']===null,409);
        return $before;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($name,$payload);$original=$this->original($name);$id=$this->id($before['branch']['id']);
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        // These are the existing enrollment/current-account boundaries, not a new restaurant permission.
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)
            &&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        abort_unless(in_array('f:'.$id,json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR),true),403,'الفرع خارج نطاق ربط الجهاز.');
        $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($row,409,'فرع المطعم لم يعد موجودًا.');
        $allowed=match($fresh->account_type){
            'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,
            'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$row->parent_id===(int)$fresh->owner_resturant_id),
            'vendor'=>(int)$row->user_id===(int)$fresh->id,
            default=>false,
        };
        abort_unless($allowed,403,'حساب الجهاز لم يعد يملك نطاق فرع المطعم.');
        $guard=auth('admin');$previous=$guard->getUser();$request=request();$router=app('router');
        try{
            $guard->setUser($fresh);
            $probe=Request::create(url(str_replace('{resturant}',(string)$id,self::ROUTES[$name]['uri'])),'POST');
            if($request->hasSession())$probe->setLaravelSession($request->session());
            $route=clone $original;$route->flushController();$route->bind($probe);$probe->setRouteResolver(fn()=>$route);
            $probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router->substituteBindings($route);$router->substituteImplicitBindings($route);
            abort_unless($route->parameter('resturant') instanceof Resturant&&(int)$route->parameter('resturant')->getKey()===$id,403);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            (new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
        return $fresh;
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {
        abort_if($request->allFiles(),501);$parameters=$request->route()->parameters();abort_unless(array_keys($parameters)===['resturant'],422);
        $value=$parameters['resturant'];$id=$this->id($value instanceof Resturant?$value->getKey():$value);
        $values=$request->except('_token','_method','_desktop_command');$values['idempotency_key']=$command;$values=$this->values($name,$values);
        $before=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name)
            ??['restaurant_flag_before'=>$this->state($id)];
        $payload=['parameters'=>['resturant'=>$id],'values'=>$values,'files'=>[],'facts'=>$before];
        $scope=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        abort_if($request->allFiles(),501);$value=$request->route('resturant');$id=$this->id($value instanceof Resturant?$value->getKey():$value);
        $values=$request->except('_token','_method','_desktop_command');$values['idempotency_key']=$command;$values=$this->values($name,$values);
        $payload=['parameters'=>['resturant'=>$id],'values'=>$values,'files'=>[],'facts'=>$saved??['restaurant_flag_before'=>$this->state($id)]];
        $this->authorize($name,$payload,$actor,$device);return $payload;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach(self::REFERENCES as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach(self::REFERENCES as $path=>$entity){$value=data_get($payload,$path);if(is_array($value))
            abort_unless(count($value)===1&&is_array($value['$desktop_ref']??null)&&($value['$desktop_ref']['entity']??null)===$entity,422,'نوع مرجع المطعم أو علاقته غير صحيح.');}
    }
    public function capture(string $name,array $payload,callable $work): array
    {
        $response=$work();$status=$response->getStatusCode();abort_unless(in_array($status,[302,303],true),409);
        $parts=parse_url($response->headers->get('Location')??'');$location=($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'');
        abort_unless(($parts['path']??'')==='/admin/resturants'&&strlen($response->getContent())<=1024*1024,409);
        if($name==='resturants.changeUnderContract')abort_if(isset($parts['query']),409);
        $id=$this->id($payload['parameters']['resturant']);
        return ['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'success'=>session('success'),'after'=>$this->state($id),'references'=>['menu_restaurant'=>$id]];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);
        if($http['type'])$response->headers->set('Content-Type',$http['type']);
        if($http['location'])$response->headers->set('Location',url($http['location']));
        if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    public function outputs(array $result): array {return isset($result['http'])?($result['references']??[]):[];}
    private function comparable(array $state): array
    {
        // The local controller timestamps its write before the journal timestamps its accepted command.
        // Replay uses that recorded command occurrence, which can cross a second boundary. Retain the
        // complete original state in the receipt, but do not turn that timing difference into a conflict.
        // Creation identity and all other 28 original restaurant columns remain exact.
        unset($state['row']['updated_at']);return $state;
    }
    public function verifyAfter(array $server,array $local,array $resolvedPayload,array $originalPayload): void
    {
        abort_unless(isset($server['after'],$local['after'])&&is_array($server['after'])&&is_array($local['after']),409,'نتيجة تعديل علامة المطعم غير مكتملة.');
        foreach([$server['after'],$local['after']] as $state){
            $row=$state['row']??null;
            abort_unless(is_array($row)&&count($row)===count(self::COLUMNS)&&array_diff(array_keys($row),self::COLUMNS)===[],409,'أعمدة نتيجة علامة المطعم غير مكتملة.');
            abort_unless($row['updated_at']===null||(is_string($row['updated_at'])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$row['updated_at'])),409,'وقت نتيجة علامة المطعم غير صحيح.');
        }
        // A checkbox cannot change an identity. Verify the local result's identities first, then
        // translate only the named references already resolved from this operation's before-facts.
        foreach(self::REFERENCES as $path=>$entity){
            if(!str_starts_with($path,'facts.restaurant_flag_before.'))continue;
            $afterPath='after.'.substr($path,strlen('facts.restaurant_flag_before.'));
            $old=data_get($originalPayload,$path);$actual=data_get($local,$afterPath);
            if(is_array($old)&&isset($old['$desktop_ref']))$old=$this->id($old['$desktop_ref']['local_id']??null);
            abort_unless($actual===$old,409,'نتيجة المطعم المحلية تغيّر هوية غير معدّلة.');
            if($old!==null)data_set($local,$afterPath,data_get($resolvedPayload,$path));
        }
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($server['after'])),
            app(DesktopDashboardJournal::class)->fingerprint($this->comparable($local['after']))),409,'نتيجة تعديل علامة المطعم تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);$id=$this->id($payload['parameters']['resturant']);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($before)),app(DesktopDashboardJournal::class)->fingerprint($this->comparable($this->state($id,true)))),409,'المطعم أو روابطه تغيّرت على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
        $original=$this->original($name);$values=$this->values($name,$payload['values']);unset($values['idempotency_key']);
        $request=Request::create(url(str_replace('{resturant}',(string)$id,self::ROUTES[$name]['uri'])),'POST',$values);
        $session=new Store('desktop-restaurant-flag-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);
            Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            abort_unless($route->parameter('resturant') instanceof Resturant&&(int)$route->parameter('resturant')->getKey()===$id,409);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
