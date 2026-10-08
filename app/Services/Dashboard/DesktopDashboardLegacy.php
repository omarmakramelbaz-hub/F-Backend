<?php
namespace App\Services\Dashboard;

use App\Models\{Category,Product,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Replay reviewed original controllers, including their FormRequests and permission middleware. */
class DesktopDashboardLegacy
{
    public const ROUTES=[
        'categorys.store'=>['model'=>Category::class,'entity'=>'catalog_category','table'=>'categories','method'=>'POST','action'=>'store','parameter'=>'category'],
        'categorys.update'=>['model'=>Category::class,'entity'=>'catalog_category','table'=>'categories','method'=>'PUT','action'=>'update','parameter'=>'category'],
        'categorys.destroy'=>['model'=>Category::class,'entity'=>'catalog_category','table'=>'categories','method'=>'DELETE','action'=>'destroy','parameter'=>'category'],
        'categorys.destroy-all'=>['model'=>Category::class,'entity'=>'catalog_category','table'=>'categories','method'=>'DELETE','action'=>'deleteAll','parameter'=>null],
        'products.store'=>['model'=>Product::class,'entity'=>'catalog_product','table'=>'products','method'=>'POST','action'=>'store','parameter'=>'product'],
        'products.update'=>['model'=>Product::class,'entity'=>'catalog_product','table'=>'products','method'=>'PUT','action'=>'update','parameter'=>'product'],
        'products.destroy'=>['model'=>Product::class,'entity'=>'catalog_product','table'=>'products','method'=>'DELETE','action'=>'destroy','parameter'=>'product'],
        'products.destroy-all'=>['model'=>Product::class,'entity'=>'catalog_product','table'=>'products','method'=>'DELETE','action'=>'deleteAll','parameter'=>null],
    ];
    private static bool $listening=false;
    private static ?array $capture=null;

    public static function handles(?string $route): bool {return isset(self::ROUTES[$route??'']);}
    public function authorize(User $actor): void
    {
        // These are global catalog actions, rather than a branch employee's POS actions.
        abort_unless($actor->account_type==='admin'&&empty($actor->owner_resturant_id),403);
    }
    public function payload(Request $request,string $command): array
    {
        $name=$request->route()->getName();$definition=self::ROUTES[$name];
        abort_if(count($request->allFiles()),501,'نقل مرفقات هذا القسم لم يُجهّز بعد.');
        $values=$request->except('_token','_method','_desktop_command');
        if($definition['action']==='deleteAll'){
            abort_unless(is_string($values['ids']??null)&&preg_match('/^[1-9][0-9]{0,18}(?:,[1-9][0-9]{0,18}){0,199}$/D',$values['ids']),422);
            $values['ids']=array_map('intval',explode(',',$values['ids']));sort($values['ids']);
        }
        $this->validateValues($definition,$values);
        $parameters=[];
        foreach($request->route()->parameters() as $key=>$value){
            $id=$value instanceof \Illuminate\Database\Eloquent\Model?$value->getKey():$value;
            $parameters[$key]=is_scalar($id)&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$id)?(int)$id:$id;
        }
        $savedFacts=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)auth('admin')->id(),$name);
        $facts=$savedFacts??($definition['action']==='deleteAll'?
            ['catalog_rows'=>array_map(fn($id)=>['id'=>$id,'state'=>$this->state($definition,$id)],$values['ids'])]:
            ['catalog_before'=>$definition['action']!=='store'?$this->state($definition,(int)($parameters[$definition['parameter']]??0)):null]);
        return ['values'=>array_merge($values,['idempotency_key'=>$command]),'parameters'=>$parameters,'files'=>[],'facts'=>$facts];
    }
    private function state(array $definition,int $id): array
    {
        $row=DB::table($definition['table'])->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
        $row=(array)$row;unset($row['id'],$row['created_at'],$row['updated_at']);
        return ['row'=>$row,'features'=>$definition['table']==='products'?DB::table('product_features')->where('product_id',$id)->orderBy('id')->pluck('name')->all():[]];
    }
    private function validateValues(array $definition,array $values): void
    {
        if($definition['action']==='deleteAll'){
            abort_if(array_diff(array_keys($values),['ids']),422,'حقول عملية الحذف غير مقبولة.');
            Validator::make($values,['ids'=>'required|array|min:1|max:200','ids.*'=>'required|integer|min:1|distinct'])->validate();return;
        }
        if($definition['action']==='destroy'){
            abort_if(array_diff(array_keys($values),$definition['table']==='categories'?['parent']:[]),422,'حقول عملية الحذف غير مقبولة.');return;
        }
        $allowed=$definition['table']==='categories'?['added_by','parent_id','parent','name_ar','name_en','status','order']:
            ['added_by','category_id','subcategory_id','product_id','name_ar','name_en','status','has_clean','product_features','old_service'];
        abort_if(array_diff(array_keys($values),$allowed),422,'حقول عملية الكتالوج غير مقبولة.');
    }
    public function capture(string $name,callable $work): array
    {
        $definition=self::ROUTES[$name];
        if(!self::$listening){
            foreach([Category::class,Product::class,\App\Models\ProductFeature::class] as $model)app('events')->listen('eloquent.created: '.$model,function($row){
                if(self::$capture!==null)self::$capture[get_class($row)][]=(int)$row->getKey();
            });
            self::$listening=true;
        }
        abort_unless(self::$capture===null,409);self::$capture=[];
        try{
            $response=$work();$status=$response->getStatusCode();
            if($status>=400)throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            // Original form validation redirects back. Such a redirect is not a committed operation.
            if(request()->hasSession()&&in_array('errors',request()->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            abort_unless(in_array($status,[200,302,303],true),409);
            $location=$response->headers->get('Location');
            if($location){$parts=parse_url($location);$location=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');abort_unless(str_starts_with($location,'/admin/'),409);}
            $created=self::$capture[$definition['model']]??[];
            $id=$definition['action']==='deleteAll'?null:($definition['action']==='store'?($created[0]??0):(int)request()->route($definition['parameter'])->getKey());
            abort_unless($definition['action']==='deleteAll'||($id>0&&($definition['action']!=='store'||count($created)===1)),409,'نتيجة حفظ الكتالوج غير مكتملة.');
            $references=in_array($definition['action'],['destroy','deleteAll'],true)?[]:[$definition['entity']=>$id];
            foreach(self::$capture[\App\Models\ProductFeature::class]??[] as $index=>$feature)$references['catalog_feature.'.$index]=$feature;
            abort_if(strlen($response->getContent())>1024*1024,413);
            return ['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],'references'=>$references];
        }finally{self::$capture=null;}
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);
        if($http['type'])$response->headers->set('Content-Type',$http['type']);
        if($http['location'])$response->headers->set('Location',url($http['location']));
        return $response;
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $this->authorize($actor);$definition=self::ROUTES[$name];$app=app();$router=$app['router'];
        $original=$router->getRoutes()->getByName($name);abort_unless($original,409);
        $expected='App\\Http\\Controllers\\Dashboard\\'.($definition['table']==='categories'?'CategoryController':'ProductController').'@'.$definition['action'];
        abort_unless($original->getActionName()===$expected,409,'مسار الكتالوج الأصلي تغيّر.');
        abort_if(!empty($payload['files']),501);
        $values=$payload['values'];unset($values['idempotency_key'],$values['_token'],$values['_method'],$values['_desktop_command']);
        $this->validateValues($definition,$values);
        if($definition['action']==='deleteAll'){
            $rows=$payload['facts']['catalog_rows']??[];$ids=$values['ids'];
            abort_unless(is_array($rows)&&count($rows)===count($ids),409);
            foreach($rows as $index=>$row)abort_unless(is_array($row)&&is_array($row['state']??null)&&($row['id']??null)===($ids[$index]??null)
                &&app(DesktopDashboardJournal::class)->fingerprint($row['state'])===app(DesktopDashboardJournal::class)->fingerprint($this->state($definition,$ids[$index])),409,'أحد الأصناف أو الأقسام تغيّر على السيرفر؛ الحذف المحلي محفوظ للمراجعة.');
        }elseif($definition['action']!=='store'){
            $before=$payload['facts']['catalog_before']??null;
            abort_unless(is_array($before)&&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),app(DesktopDashboardJournal::class)->fingerprint($this->state($definition,(int)($payload['parameters'][$definition['parameter']]??0)))),409,'الصنف أو القسم تغيّر على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
        }
        $uri='/'.$original->uri();
        foreach($payload['parameters']??[] as $key=>$value){abort_unless(is_scalar($value)&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value),422);$uri=str_replace('{'.$key.'}',(string)$value,$uri);}
        abort_if(str_contains($uri,'{'),422);
        if($definition['action']==='deleteAll')$values['ids']=implode(',',$values['ids']);
        $request=Request::create(url($uri),$definition['method'],$values);$request->headers->set('Accept','text/html');
        $session=new Store('desktop-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$oldUser=$guard->getUser();$oldDefault=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);
            Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');$request->setUserResolver(fn($name=null)=>auth($name??'admin')->user());
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);
            $router->substituteBindings($route);$router->substituteImplicitBindings($route);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),$route->controllerMiddleware());
            // The independent device token has already authenticated the current account. The
            // original controller permissions and validation still run on the fresh server data.
            return $this->capture($name,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);
            Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($oldUser)$guard->setUser($oldUser);
            // Laravel 8 has no forgetUser(). Clear only the temporary in-memory principal;
            // logging out here would mutate the outer API request's session and remember token.
            else (function(){$this->user=null;})->call($guard);
            auth()->shouldUse($oldDefault);
        }
    }
    public function inputs(string $name,array $payload,callable $reference): array
    {
        $map=['category_id'=>'catalog_category','subcategory_id'=>'catalog_category','parent_id'=>'catalog_category','product_id'=>'catalog_product'];
        foreach(['values','facts.catalog_before.row'] as $path){$row=data_get($payload,$path);if(!is_array($row))continue;
            foreach($map as $field=>$entity)if(isset($row[$field])&&!is_array($row[$field]))$row[$field]=$reference($entity,$row[$field]);
            data_set($payload,$path,$row);
        }
        $definition=self::ROUTES[$name];$parameter=$definition['parameter'];
        if($definition['action']==='deleteAll'){
            foreach($payload['values']['ids']??[] as $index=>$id)if(!is_array($id))$payload['values']['ids'][$index]=$reference($definition['entity'],$id);
            foreach($payload['facts']['catalog_rows']??[] as $index=>$row){
                if(!is_array($row['id']))$payload['facts']['catalog_rows'][$index]['id']=$reference($definition['entity'],$row['id']);
                foreach($map as $field=>$entity)if(isset($row['state']['row'][$field])&&!is_array($row['state']['row'][$field]))
                    $payload['facts']['catalog_rows'][$index]['state']['row'][$field]=$reference($entity,$row['state']['row'][$field]);
            }
        }
        if(isset($payload['parameters'][$parameter])&&!is_array($payload['parameters'][$parameter]))$payload['parameters'][$parameter]=$reference($definition['entity'],$payload['parameters'][$parameter]);
        return $payload;
    }
}
