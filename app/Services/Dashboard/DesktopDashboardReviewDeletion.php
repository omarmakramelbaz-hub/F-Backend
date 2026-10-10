<?php
namespace App\Services\Dashboard;

use App\Models\{Review,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Delete only the original Review row, using its original guard and immutable parent facts. */
class DesktopDashboardReviewDeletion
{
    public const ROUTE='resturant_reviews.destroy';
    private const REFERENCES=[
        'parameters.review'=>'resturant_review',
        'facts.review_before.row.id'=>'resturant_review',
        'facts.review_before.row.resturant_id'=>'review_resturant',
        'facts.review_before.row.order_id'=>'review_order',
        'facts.review_before.row.user_id'=>'review_user',
        'facts.review_before.resturant.id'=>'review_resturant',
        'facts.review_before.resturant.user_id'=>'review_user',
        'facts.review_before.resturant.parent_id'=>'review_resturant',
        'facts.review_before.order.id'=>'review_order',
        'facts.review_before.order.resturant_id'=>'review_resturant',
        'facts.review_before.order.user_id'=>'review_user',
        'facts.review_before.user.id'=>'review_user',
        'facts.review_before.branch.id'=>'review_resturant',
    ];
    public static function handles(?string $route): bool {return $route===self::ROUTE;}
    private function original()
    {
        $route=app('router')->getRoutes()->getByName(self::ROUTE);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\ResturantController@resturantReviewsDelete'
            &&$route->methods()===['DELETE']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار حذف التقييم الأصلي تغيّر.');
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
    private function state(int $id,bool $replay=false): array
    {
        $review=DB::table('reviews')->where('id',$id)->lockForUpdate()->first();
        abort_unless($review,$replay?409:404,'التقييم المحفوظ لم يعد موجودًا؛ العملية المحلية محفوظة للمراجعة.');
        $review=(array)$review;
        $restaurant=DB::table('resturants')->where('id',$review['resturant_id'])->lockForUpdate()->first(['id','user_id','parent_id']);
        $order=isset($review['order_id'])?DB::table('orders')->where('id',$review['order_id'])->lockForUpdate()->first(['id','resturant_id','user_id']):null;
        $user=isset($review['user_id'])?DB::table('users')->where('id',$review['user_id'])->lockForUpdate()->first(['id']):null;
        abort_unless($restaurant&&(!isset($review['order_id'])||$order)&&(!isset($review['user_id'])||$user),409,'روابط التقييم المحفوظة غير مكتملة.');
        return ['row'=>$review,'resturant'=>(array)$restaurant,'order'=>$order?(array)$order:null,'user'=>$user?(array)$user:null,
            'branch'=>['kind'=>'f','id'=>(int)$review['resturant_id']]];
    }
    private function facts(array $payload): array
    {
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);
        abort_unless(empty($payload['files'])&&array_keys($payload['parameters']??[])===['review'],422);
        abort_if(array_diff(array_keys($payload['values']??[]),['idempotency_key']),422);
        abort_unless(array_keys($payload['facts']??[])===['review_before']&&is_array($payload['facts']['review_before']),409);
        $before=$payload['facts']['review_before'];$row=$before['row']??null;
        abort_unless(array_diff(array_keys($before),['row','resturant','order','user','branch'])===[]&&count($before)===5,409);
        foreach(['resturant'=>['id','user_id','parent_id'],'order'=>['id','resturant_id','user_id'],'user'=>['id'],'branch'=>['kind','id']] as $relation=>$keys){
            $actual=$before[$relation]??null;
            if($actual===null&&in_array($relation,['order','user'],true))continue;
            abort_unless(is_array($actual)&&count($actual)===count($keys)&&array_diff(array_keys($actual),$keys)===[],409);
        }
        abort_unless(is_array($row)&&array_diff(['id','resturant_id','order_id','user_id','rate','created_at','updated_at'],array_keys($row))===[]
            &&array_diff(array_keys($row),['id','resturant_id','order_id','user_id','rate','created_at','updated_at'])===[],409);
        abort_unless($this->id($payload['parameters']['review'])===$this->id($row['id'])
            &&($before['branch']['kind']??null)==='f'&&$this->id($before['branch']['id']??null)===$this->id($row['resturant_id'])
            &&$this->id($before['resturant']['id']??null)===$this->id($row['resturant_id']),409,'هوية التقييم أو فرعه غير متطابقة.');
        foreach(['order_id'=>'order','user_id'=>'user'] as $key=>$relation)
            abort_unless(isset($row[$key])?is_array($before[$relation]??null)&&$this->id($before[$relation]['id']??null)===$this->id($row[$key]):($before[$relation]??null)===null,409);
        return $before;
    }
    public function authorize(array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($payload);$original=$this->original();
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        // These are the existing device enrollment/current-account conditions, not a review permission.
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)
            &&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        $branches=json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR);
        foreach([$before['branch']['id']] as $id){
            $id=$this->id($id);abort_unless(in_array('f:'.$id,$branches,true),403,'الفرع خارج نطاق ربط الجهاز.');
            $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($row,409,'فرع التقييم لم يعد موجودًا.');
            // The same F-branch relationship predicates used by bootstrap, without its POS grants.
            $allowed=match($fresh->account_type){
                'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,
                'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$row->parent_id===(int)$fresh->owner_resturant_id),
                'vendor'=>(int)$row->user_id===(int)$fresh->id,
                default=>false,
            };
            abort_unless($allowed,403,'حساب الجهاز لم يعد يملك نطاق فرع التقييم.');
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
        $parameters=$request->route()->parameters();abort_unless(array_keys($parameters)===['review'],422);
        $value=$parameters['review'];$id=$this->id($value instanceof Review?$value->getKey():$value);
        $before=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,self::ROUTE)
            ??['review_before'=>$this->state($id)];
        $payload=['parameters'=>['review'=>$id],'values'=>['idempotency_key'=>$command],'files'=>[],'facts'=>$before];
        $scope=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $this->authorize($payload,$actor,$scope);return $payload;
    }
    public function remotePayload(Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        abort_if($request->allFiles(),501);abort_if($request->except('_token','_method','_desktop_command'),422);
        $value=$request->route('review');$id=$this->id($value instanceof Review?$value->getKey():$value);
        $payload=['parameters'=>['review'=>$id],'values'=>['idempotency_key'=>$command],'files'=>[],
            'facts'=>$saved??['review_before'=>$this->state($id)]];
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
            abort_unless(count($value)===1&&is_array($value['$desktop_ref']??null)&&($value['$desktop_ref']['entity']??null)===$entity,422,'نوع مرجع التقييم أو علاقته غير صحيح.');}
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
        $before=$this->facts($payload);$id=$this->id($payload['parameters']['review']);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),app(DesktopDashboardJournal::class)->fingerprint($this->state($id,true))),409,'التقييم أو روابطه تغيّرت على السيرفر؛ الحذف المحلي محفوظ للمراجعة.');
        $original=$this->original();$request=Request::create(url('/admin/resturant_reviews/'.$id),'DELETE');
        $request->headers->set('Referer',url('/admin/resturants/'.$this->id($before['branch']['id'])));
        $session=new Store('desktop-review-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
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
