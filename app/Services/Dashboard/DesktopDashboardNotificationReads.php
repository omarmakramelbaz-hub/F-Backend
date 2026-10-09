<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Facade,Schema,Validator};

/** Original history forms replay only their immutable account-scoped notification snapshot. */
class DesktopDashboardNotificationReads
{
    public const ROUTES=['read_notify'=>'read','mark_all_as_read'=>'mark_all_as_read'];
    public static function handles(?string $route): bool {return isset(self::ROUTES[$route??'']);}

    /** Keep retries in this snapshot; a refreshed dataset must not reuse an archived UUID. */
    public function generation(User $actor): string
    {
        if(!config('desktop_dashboard.local'))return 'server';
        if(!Schema::hasTable('desktop_dashboard_local_state'))return '';
        $state=DB::table('desktop_dashboard_local_state')->where('device_id',(string)config('desktop_dashboard.device_id'))->first();
        return $state&&(int)$state->actor_id===(int)$actor->id?(string)$state->snapshot_id:'';
    }

    public function payload(Request $request,string $command,User $actor): array
    {
        abort_if($request->allFiles(),501);
        abort_if(array_diff(array_keys($request->all()),['_token','_method','_desktop_command','desktop_notification_ids']),422);
        $encoded=$request->input('desktop_notification_ids');
        abort_unless(is_string($encoded)&&strlen($encoded)<=2000000,422,'قائمة الإشعارات غير مكتملة.');
        try{$ids=json_decode($encoded,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $error){abort(422,'قائمة الإشعارات غير صالحة.');}
        $ids=$this->ids($ids);
        $parameters=$request->route()->parameters();
        if($request->route()->getName()==='read_notify'){
            Validator::make($parameters,['id'=>'required|uuid'])->validate();
            abort_unless($ids===[$parameters['id']],422,'الإشعار لا يطابق العملية.');
        }else abort_if($parameters,422);
        // Reject foreign/missing IDs before the original controller or journal can commit.
        $this->owned($actor,$ids);
        $request->merge(['desktop_notification_ids'=>json_encode($ids,JSON_THROW_ON_ERROR)]);
        $request->attributes->set('_desktop_notification_ids',$ids);
        $request->headers->set('Referer',url('/admin/notifications'));
        return ['values'=>['idempotency_key'=>$command,'desktop_notification_ids'=>json_encode($ids,JSON_THROW_ON_ERROR)],
            'parameters'=>$parameters,'files'=>[]];
    }

    private function ids($ids): array
    {
        abort_unless(is_array($ids)&&array_is_list($ids),422);
        Validator::make(['ids'=>$ids],['ids'=>'present|array|max:50000','ids.*'=>'required|uuid|distinct'])->validate();
        sort($ids,SORT_STRING);return $ids;
    }
    private function owned(User $actor,array $ids): void
    {
        abort_unless($actor->notifications()->whereIn('id',$ids)->count()===count($ids),404);
    }
    public function capture(callable $work): array
    {
        $response=$work();
        if($response->getStatusCode()>=400)throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        abort_unless($response->getStatusCode()===302,409);
        $location=$response->headers->get('Location');
        abort_unless($location===url('/admin/notifications'),409);
        return ['http'=>['status'=>302,'content'=>'','location'=>'/admin/notifications'],'references'=>[]];
    }
    public function response(array $result)
    {
        abort_unless(($result['http']['status']??null)===302&&($result['http']['location']??null)==='/admin/notifications',503);
        return redirect('/admin/notifications')->header('Cache-Control','private, no-store');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        abort_unless(self::handles($name)&&empty($payload['files']),422);
        $values=$payload['values']??[];$parameters=$payload['parameters']??[];
        abort_if(array_diff(array_keys($values),['idempotency_key','desktop_notification_ids']),422);
        $method=$name==='read_notify'?'PUT':'POST';
        $uri=$name==='read_notify'?'/admin/read/'.($parameters['id']??''):'/admin/read/all/notification';
        $request=Request::create(url($uri),$method,['desktop_notification_ids'=>$values['desktop_notification_ids']??null]);
        $router=app('router');$original=$router->getRoutes()->getByName($name);
        abort_unless($original&&$original->getActionName()==='App\\Http\\Controllers\\Dashboard\\HomeController@'.self::ROUTES[$name],409);
        $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);
        $this->payload($request,(string)($values['idempotency_key']??''),$actor);
        $session=new \Illuminate\Session\Store('desktop-notifications',new \Illuminate\Session\ArraySessionHandler(60));
        $session->start();$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$guard=auth('admin');$oldUser=$guard->getUser();$oldDefault=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);Facade::clearResolvedInstance('request');$app['url']->setRequest($request);
            $guard->setUser($actor);auth()->shouldUse('admin');$request->setUserResolver(fn($name=null)=>auth($name??'admin')->user());
            $middleware=array_map(fn($item)=>\Illuminate\Routing\MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),$route->controllerMiddleware());
            return $this->capture(fn()=>(new \Illuminate\Pipeline\Pipeline($app))->send($request)->through($middleware)
                ->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);Facade::clearResolvedInstance('request');$app['url']->setRequest($oldRequest);
            if($oldUser)$guard->setUser($oldUser);else (function(){$this->user=null;})->call($guard);
            auth()->shouldUse($oldDefault);
        }
    }
}
