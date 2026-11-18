<?php
namespace App\Services\Dashboard;

use App\Models\{Wishlist,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Invoke the original wishlist query-delete; its model/media events remain untouched. */
class DesktopDashboardWishlistDeletion
{
    public const ROUTE='userwishlists.destroy';
    private const REFERENCES=[
        'parameters.id'=>'user_wishlist',
        'facts.wishlist_before.row.id'=>'user_wishlist',
        'facts.wishlist_before.row.resturant_id'=>'wishlist_resturant',
        'facts.wishlist_before.row.user_id'=>'wishlist_user',
        'facts.wishlist_before.resturant.id'=>'wishlist_resturant',
        'facts.wishlist_before.resturant.user_id'=>'wishlist_user',
        'facts.wishlist_before.resturant.parent_id'=>'wishlist_resturant',
        'facts.wishlist_before.user.id'=>'wishlist_user',
        'facts.wishlist_before.branch.id'=>'wishlist_resturant',
    ];
    public static function handles(?string $route): bool {return $route===self::ROUTE;}
    private function original()
    {
        $route=app('router')->getRoutes()->getByName(self::ROUTE);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\UserController@userWishlistsDelete'
            &&$route->methods()===['DELETE']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار حذف المفضلة الأصلي تغيّر.');
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
        foreach([$header,$form] as $value)if($value!==null&&$value!=='')Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$header!==''&&$form!==null&&$form!==''&&$header!==$form,409,'رقم طلب العملية مختلف عن سجلها.');
        $command=$header?:$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return (string)$command;
    }
    private function state(int $id): array
    {
        $wishlist=DB::table('wishlists')->where('id',$id)->lockForUpdate()->first();
        abort_unless($wishlist,409,'عنصر المفضلة المحفوظ لم يعد موجودًا؛ العملية محفوظة للمراجعة.');
        $wishlist=(array)$wishlist;
        $restaurant=DB::table('resturants')->where('id',$wishlist['resturant_id'])->lockForUpdate()->first(['id','user_id','parent_id']);
        $user=DB::table('users')->where('id',$wishlist['user_id'])->lockForUpdate()->first(['id','account_type','app_scope']);
        abort_unless($restaurant&&$user,409,'روابط المفضلة المحفوظة غير مكتملة.');
        return ['row'=>$wishlist,'resturant'=>(array)$restaurant,'user'=>(array)$user,
            'branch'=>['kind'=>'f','id'=>(int)$wishlist['resturant_id']]];
    }
    private function facts(array $payload): array
    {
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);
        abort_unless(empty($payload['files'])&&array_keys($payload['parameters']??[])===['id'],422);
        abort_if(array_diff(array_keys($payload['values']??[]),['idempotency_key']),422);
        abort_unless(array_keys($payload['facts']??[])===['wishlist_before']&&is_array($payload['facts']['wishlist_before']),409);
        $before=$payload['facts']['wishlist_before'];$row=$before['row']??null;
        abort_unless(array_diff(array_keys($before),['row','resturant','user','branch'])===[]&&count($before)===4,409);
        foreach(['resturant'=>['id','user_id','parent_id'],'user'=>['id','account_type','app_scope'],'branch'=>['kind','id']] as $relation=>$keys){
            $actual=$before[$relation]??null;
            abort_unless(is_array($actual)&&count($actual)===count($keys)&&array_diff(array_keys($actual),$keys)===[],409);
        }
        abort_unless(is_array($row)&&array_diff(['id','resturant_id','user_id','created_at','updated_at'],array_keys($row))===[]
            &&array_diff(array_keys($row),['id','resturant_id','user_id','created_at','updated_at'])===[],409);
        abort_unless($this->id($payload['parameters']['id'])===$this->id($row['id'])
            &&($before['branch']['kind']??null)==='f'&&$this->id($before['branch']['id']??null)===$this->id($row['resturant_id'])
            &&$this->id($before['resturant']['id']??null)===$this->id($row['resturant_id'])
            &&$this->id($before['user']['id']??null)===$this->id($row['user_id']),409,'هوية المفضلة أو علاقاتها غير متطابقة.');
        return $before;
    }
    public function authorize(array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($payload);$original=$this->original();
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        // These are the existing device enrollment/current-account conditions, not a wishlist permission.
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)
            &&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        $branches=json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR);
        foreach([$before['branch']['id']] as $id){
            $id=$this->id($id);abort_unless(in_array('f:'.$id,$branches,true),403,'الفرع خارج نطاق ربط الجهاز.');
            $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($row,409,'فرع المفضلة لم يعد موجودًا.');
            // The same F-branch relationship predicates used by bootstrap, without its POS grants.
            $allowed=match($fresh->account_type){
                'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,
                'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$row->parent_id===(int)$fresh->owner_resturant_id),
                'vendor'=>(int)$row->user_id===(int)$fresh->id,
                default=>false,
            };
            abort_unless($allowed,403,'حساب الجهاز لم يعد يملك نطاق فرع المفضلة.');
        }
        $guard=auth('admin');$previous=$guard->getUser();$request=request();$router=app('router');
        try{
            $guard->setUser($fresh);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$original->controllerMiddleware()));
            (new Pipeline(app()))->send($request)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
        return $fresh;
    }
    public function payload(Request $request,string $command,User $actor): array
    {
        abort_if($request->allFiles(),501);abort_if(array_diff(array_keys($request->except('_token','_method','_desktop_command')),[]),422);
        $parameters=$request->route()->parameters();abort_unless(array_keys($parameters)===['id'],422);
        $value=$parameters['id'];$id=$this->id($value instanceof Wishlist?$value->getKey():$value);
        $before=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,self::ROUTE)
            ??['wishlist_before'=>$this->state($id)];
        $payload=['parameters'=>['id'=>$id],'values'=>['idempotency_key'=>$command],'files'=>[],'facts'=>$before];
        $scope=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $this->authorize($payload,$actor,$scope);return $payload;
    }
    public function remotePayload(Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        abort_if($request->allFiles(),501);abort_if($request->except('_token','_method','_desktop_command'),422);
        $value=$request->route('id');$id=$this->id($value instanceof Wishlist?$value->getKey():$value);
        $payload=['parameters'=>['id'=>$id],'values'=>['idempotency_key'=>$command],'files'=>[],
            'facts'=>$saved??['wishlist_before'=>$this->state($id)]];
        $this->authorize($payload,$actor,$device);return $payload;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach(self::REFERENCES as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach(self::REFERENCES as $path=>$entity){$value=data_get($payload,$path);if(is_array($value))
            abort_unless(count($value)===1&&is_array($value['$desktop_ref']??null)&&($value['$desktop_ref']['entity']??null)===$entity,422,'نوع مرجع المفضلة أو علاقتها غير صحيح.');}
    }
    public function capture(callable $work): array
    {
        $response=$work();$status=$response->getStatusCode();abort_unless(in_array($status,[302,303],true),409);
        $parts=parse_url($response->headers->get('Location')??'');$location=($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'');
        abort_unless(str_starts_with($location,'/admin/')&&strlen($response->getContent())<=1024*1024,409);
        return ['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'success'=>session('success'),'references'=>[]];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);
        if($http['type'])$response->headers->set('Content-Type',$http['type']);
        if($http['location'])$response->headers->set('Location',url($http['location']));
        if(isset($result['success']))session()->flash('success',$result['success']);
        return $response;
    }
    public function execute(array $payload,User $actor): array
    {
        $before=$this->facts($payload);$id=$this->id($payload['parameters']['id']);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),app(DesktopDashboardJournal::class)->fingerprint($this->state($id))),409,'المفضلة أو روابطها تغيّرت على السيرفر؛ الحذف المحلي محفوظ للمراجعة.');
        $original=$this->original();$request=Request::create(url('/admin/userWishlistsDelete/'.$id),'DELETE');
        $request->headers->set('Referer',url('/admin/users/'.$this->id($before['user']['id']).'?account_type=user'));
        $session=new Store('desktop-wishlist-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);
            Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($name=null)=>auth($name??'admin')->user());
            $router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture(fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
