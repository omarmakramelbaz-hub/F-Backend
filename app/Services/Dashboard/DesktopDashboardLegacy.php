<?php
namespace App\Services\Dashboard;

use App\Models\{Area,Category,Contact,Contract,Feature,Product,QuestionAnswer,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Replay reviewed original controllers, including their FormRequests and permission middleware. */
class DesktopDashboardLegacy
{
    public const ROUTES=[
        'categorys.reorder'=>['model'=>Category::class,'entity'=>'catalog_category','table'=>'categories','method'=>'POST','action'=>'updateColumns','parameter'=>null],
        'contracts.store'=>['model'=>Contract::class,'entity'=>'catalog_contract','table'=>'contracts','method'=>'POST','action'=>'store','parameter'=>'contract'],
        'contracts.update'=>['model'=>Contract::class,'entity'=>'catalog_contract','table'=>'contracts','method'=>'PUT','action'=>'update','parameter'=>'contract'],
        'contracts.destroy'=>['model'=>Contract::class,'entity'=>'catalog_contract','table'=>'contracts','method'=>'DELETE','action'=>'destroy','parameter'=>'contract'],
        'features.store'=>['model'=>Feature::class,'entity'=>'site_feature','table'=>'features','method'=>'POST','action'=>'store','parameter'=>'feature'],
        'features.update'=>['model'=>Feature::class,'entity'=>'site_feature','table'=>'features','method'=>'PUT','action'=>'update','parameter'=>'feature'],
        'features.destroy'=>['model'=>Feature::class,'entity'=>'site_feature','table'=>'features','method'=>'DELETE','action'=>'destroy','parameter'=>'feature'],
        'features.destroy-all'=>['model'=>Feature::class,'entity'=>'site_feature','table'=>'features','method'=>'DELETE','action'=>'deleteAll','parameter'=>null],
        'contacts.destroy'=>['model'=>Contact::class,'entity'=>'admin_contact','table'=>'contacts','method'=>'DELETE','action'=>'destroy','parameter'=>'contact'],
        'contacts.destroy-all'=>['model'=>Contact::class,'entity'=>'admin_contact','table'=>'contacts','method'=>'DELETE','action'=>'deleteAll','parameter'=>null],
        'question_answers.store'=>['model'=>QuestionAnswer::class,'entity'=>'catalog_faq','table'=>'question_answers','method'=>'POST','action'=>'store','parameter'=>'question_answer'],
        'question_answers.update'=>['model'=>QuestionAnswer::class,'entity'=>'catalog_faq','table'=>'question_answers','method'=>'PUT','action'=>'update','parameter'=>'question_answer'],
        'question_answers.destroy'=>['model'=>QuestionAnswer::class,'entity'=>'catalog_faq','table'=>'question_answers','method'=>'DELETE','action'=>'destroy','parameter'=>'question_answer'],
        'question_answers.destroy-all'=>['model'=>QuestionAnswer::class,'entity'=>'catalog_faq','table'=>'question_answers','method'=>'DELETE','action'=>'deleteAll','parameter'=>null],
        'areas.store'=>['model'=>Area::class,'entity'=>'catalog_area','table'=>'areas','method'=>'POST','action'=>'store','parameter'=>'area'],
        'areas.update'=>['model'=>Area::class,'entity'=>'catalog_area','table'=>'areas','method'=>'PUT','action'=>'update','parameter'=>'area'],
        'areas.destroy'=>['model'=>Area::class,'entity'=>'catalog_area','table'=>'areas','method'=>'DELETE','action'=>'destroy','parameter'=>'area'],
        'areas.destroy-all'=>['model'=>Area::class,'entity'=>'catalog_area','table'=>'areas','method'=>'DELETE','action'=>'delete_all','parameter'=>null],
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
    private function isBulk(array $definition): bool {return in_array($definition['action'],['deleteAll','delete_all'],true);}
    private function isSelection(array $definition): bool {return $this->isBulk($definition)||$definition['action']==='updateColumns';}
    private function selected(array $definition,array $values): array {return $this->isBulk($definition)?$values['ids']:array_column($values['order'],'id');}
    public function authorize(User $actor): void
    {
        // These original administration actions change shared reference data.
        abort_unless($actor->account_type==='admin'&&empty($actor->owner_resturant_id),403);
    }
    public function payload(Request $request,string $command): array
    {
        $name=$request->route()->getName();$definition=self::ROUTES[$name];
        abort_if(count($request->allFiles()),501,'نقل مرفقات هذا القسم لم يُجهّز بعد.');
        $values=$request->except('_token','_method','_desktop_command');
        if($definition['action']==='updateColumns')$values=app(CategoryOrdering::class)->values($values);
        if($this->isBulk($definition)){
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
        $facts=$savedFacts??($this->isSelection($definition)?
            ['catalog_rows'=>array_map(fn($id)=>['id'=>$id,'state'=>$this->state($definition,$id)],$this->selected($definition,$values))]:
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
        if($definition['action']==='updateColumns'){app(CategoryOrdering::class)->values($values);return;}
        if($this->isBulk($definition)){
            abort_if(array_diff(array_keys($values),['ids']),422,'حقول عملية الحذف غير مقبولة.');
            Validator::make($values,['ids'=>'required|array|min:1|max:200','ids.*'=>'required|integer|min:1|distinct'])->validate();return;
        }
        if($definition['action']==='destroy'){
            abort_if(array_diff(array_keys($values),$definition['table']==='categories'?['parent']:[]),422,'حقول عملية الحذف غير مقبولة.');return;
        }
        $allowed=match($definition['table']){
            'contracts'=>['added_by','template','type'],
            'features'=>['added_by','title_ar','title_en','text_ar','text_en','status'],
            'areas'=>['added_by','parent_id','title_ar','title_en'],
            'question_answers'=>['added_by','question_ar','question_en','answer_ar','answer_en'],
            'categories'=>['added_by','parent_id','parent','name_ar','name_en','status','order'],
            'products'=>['added_by','category_id','subcategory_id','product_id','name_ar','name_en','status','has_clean','product_features','old_service'],
        };
        abort_if(array_diff(array_keys($values),$allowed),422,'حقول عملية الكتالوج غير مقبولة.');
    }
    public function capture(string $name,callable $work): array
    {
        $definition=self::ROUTES[$name];
        if(!self::$listening){
            foreach([Area::class,Category::class,Contract::class,Feature::class,Product::class,QuestionAnswer::class,\App\Models\ProductFeature::class] as $model)app('events')->listen('eloquent.created: '.$model,function($row){
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
            $parameter=$definition['parameter']?request()->route($definition['parameter']):null;
            $id=$this->isSelection($definition)?null:($definition['action']==='store'?($created[0]??0):($parameter instanceof \Illuminate\Database\Eloquent\Model?(int)$parameter->getKey():(int)$parameter));
            abort_unless($this->isSelection($definition)||($id>0&&($definition['action']!=='store'||count($created)===1)),409,'نتيجة حفظ الكتالوج غير مكتملة.');
            $references=$definition['action']==='destroy'||$this->isSelection($definition)?[]:[$definition['entity']=>$id];
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
        $controller=match($definition['table']){'contracts'=>'ContractController','features'=>'FeatureController','areas'=>'AreaController','categories'=>'CategoryController','contacts'=>'ContactController','products'=>'ProductController','question_answers'=>'QuestionAnswerController'};
        $expected='App\\Http\\Controllers\\Dashboard\\'.$controller.'@'.$definition['action'];
        abort_unless($original->getActionName()===$expected,409,'مسار الكتالوج الأصلي تغيّر.');
        abort_if(!empty($payload['files']),501);
        $values=$payload['values'];unset($values['idempotency_key'],$values['_token'],$values['_method'],$values['_desktop_command']);
        $this->validateValues($definition,$values);
        if($this->isSelection($definition)){
            $rows=$payload['facts']['catalog_rows']??[];$ids=$this->selected($definition,$values);
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
        if($this->isBulk($definition))$values['ids']=implode(',',$values['ids']);
        $request=Request::create(url($uri),$definition['method'],$values);$request->headers->set('Accept','text/html');
        // Area deletion uses the original redirect()->back(). A server replay has no
        // browser history, so return to its original, mapped parent listing.
        if($definition['table']==='areas'){
            $parent=$payload['facts']['catalog_before']['row']['parent_id']??null;
            $request->headers->set('Referer',url('/admin/areas').($parent?'?parent='.(int)$parent:''));
        }
        if($definition['table']==='features'&&$definition['action']==='update')$request->headers->set('Referer',url($uri.'/edit'));
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
        $definition=self::ROUTES[$name];
        $map=match($definition['table']){
            'areas'=>['parent_id'=>'catalog_area'],'question_answers','contacts','features','contracts'=>[],
            default=>['category_id'=>'catalog_category','subcategory_id'=>'catalog_category','parent_id'=>'catalog_category','product_id'=>'catalog_product'],
        };
        foreach(['values','facts.catalog_before.row'] as $path){$row=data_get($payload,$path);if(!is_array($row))continue;
            foreach($map as $field=>$entity)if(isset($row[$field])&&!is_array($row[$field]))$row[$field]=$reference($entity,$row[$field]);
            data_set($payload,$path,$row);
        }
        $parameter=$definition['parameter'];
        if($this->isSelection($definition)){
            foreach($payload['values']['ids']??[] as $index=>$id)if(!is_array($id))$payload['values']['ids'][$index]=$reference($definition['entity'],$id);
            foreach($payload['values']['order']??[] as $index=>$row)if(!is_array($row['id']))$payload['values']['order'][$index]['id']=$reference($definition['entity'],$row['id']);
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
