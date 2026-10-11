<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** The two original product links write one nullable enum, without media or provider side effects. */
class DesktopDashboardRestaurantProducts
{
    public const ROUTES=[
        'resturant_products.update.status'=>['action'=>'resturantProductsUpdateStatus','field'=>'status','choices'=>'show,hide','uri'=>'admin/resturant_products/{id}/update/status'],
        'resturant_products.update.highest_rated'=>['action'=>'resturantProductsUpdateHighestRated','field'=>'highest_rated','choices'=>'yes,no','uri'=>'admin/resturant_products/{id}/update/highest_rated'],
    ];
    private const COLUMNS=[
        'row'=>['id','added_by','resturant_id','product_id','price','product_name','product_description','product_price','created_at','updated_at','category_id','status','highest_rated'],
        'branch'=>['id','user_id','parent_id','added_by'],
        'added_by'=>['id'],'owner'=>['id'],'parent'=>['id','user_id','parent_id'],
        'category'=>['id','added_by','parent_id','name_ar','name_en','status','created_at','updated_at','order'],
        'catalog'=>['id','added_by','category_id','subcategory_id','name_ar','status','created_at','updated_at','has_clean','name_en'],
        'feature'=>['id','added_by','product_id','name','created_at','updated_at'],
        'media'=>['id','model_type','model_id','uuid','collection_name','name','file_name','mime_type','disk','conversions_disk','size','manipulations','custom_properties','generated_conversions','responsive_images','order_column','created_at','updated_at'],
    ];
    private const REFERENCES=[
        'parameters.id'=>'menu_restaurant_product',
        'row.id'=>'menu_restaurant_product','row.resturant_id'=>'menu_restaurant',
        'row.added_by'=>'restaurant_flag_user','row.product_id'=>'catalog_product','row.category_id'=>'catalog_category',
        'branch.id'=>'menu_restaurant','branch.user_id'=>'restaurant_flag_user','branch.parent_id'=>'menu_restaurant','branch.added_by'=>'restaurant_flag_user',
        'added_by.id'=>'restaurant_flag_user','owner.id'=>'restaurant_flag_user',
        'parent.id'=>'menu_restaurant','parent.user_id'=>'restaurant_flag_user','parent.parent_id'=>'menu_restaurant',
        'category.id'=>'catalog_category','category.added_by'=>'restaurant_flag_user','category.parent_id'=>'catalog_category',
        'catalog.id'=>'catalog_product','catalog.added_by'=>'restaurant_flag_user','catalog.category_id'=>'catalog_category','catalog.subcategory_id'=>'catalog_category',
    ];
    public static function handles(?string $route): bool {return isset(self::ROUTES[$route??'']);}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$definition=self::ROUTES[$name];$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\ResturantController@'.$definition['action']
            &&$route->uri()===$definition['uri']&&$route->methods()===['GET','HEAD']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار علامة صنف المطعم الأصلي تغيّر.');
        return $route;
    }
    private function id($value): int
    {
        abort_unless((is_string($value)||is_int($value))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(int)$value>0&&(string)(int)$value===(string)$value,422);
        return (int)$value;
    }
    public function command(Request $request): string
    {
        // HEAD is an automatic browser/proxy probe, never a second product operation.
        abort_unless($request->isMethod('GET'),405);
        $header=$request->header('X-Fasakhansta-Command');$query=$request->input('_desktop_command');
        foreach([$header,$query] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$query!==null&&$header!==$query,409,'رقم طلب العملية مختلف عن سجلها.');
        $command=$header??$query;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(string $name,array $values): array
    {
        abort_unless(self::handles($name),422);$definition=self::ROUTES[$name];
        abort_if(array_diff(array_keys($values),['idempotency_key',$definition['field']]),422);
        Validator::make($values,['idempotency_key'=>'required|uuid',$definition['field']=>'nullable|in:'.$definition['choices']])->validate();
        // An absent value remains absent: request()->field in the original controller then writes NULL.
        return $values;
    }
    private function shape(array $row,string $kind): void
    {
        $columns=self::COLUMNS[$kind];
        abort_unless(count($row)===count($columns)&&array_diff(array_keys($row),$columns)===[],409,'أعمدة الصنف أو روابطه تحتاج مراجعة قبل المزامنة.');
        foreach($row as $value)abort_unless($value===null||is_scalar($value),409,'بيانات الصنف أو روابطه غير مكتملة.');
        foreach(['created_at','updated_at'] as $field)if(array_key_exists($field,$row))
            abort_unless($row[$field]===null||(is_string($row[$field])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$row[$field])),409,'وقت الصنف أو روابطه غير صحيح.');
    }
    private function state(int $id,bool $replay=false): array
    {
        $row=DB::table('resturant_products')->where('id',$id)->lockForUpdate()->first();
        abort_unless($row,$replay?409:404,'الصنف المحفوظ لم يعد موجودًا؛ العملية المحلية محفوظة للمراجعة.');$row=(array)$row;
        $branch=DB::table('resturants')->where('id',$row['resturant_id'])->lockForUpdate()->first(self::COLUMNS['branch']);
        abort_unless($branch,409,'فرع الصنف المحفوظ لم يعد موجودًا.');$branch=(array)$branch;
        $state=['row'=>$row,'branch'=>$branch,
            'added_by'=>(array)DB::table('users')->where('id',$row['added_by'])->lockForUpdate()->first(['id']),
            'owner'=>(array)DB::table('users')->where('id',$branch['user_id'])->lockForUpdate()->first(['id']),
            'parent'=>isset($branch['parent_id'])?(array)DB::table('resturants')->where('id',$branch['parent_id'])->lockForUpdate()->first(self::COLUMNS['parent']):null,
            'category'=>isset($row['category_id'])?(array)DB::table('categories')->where('id',$row['category_id'])->lockForUpdate()->first():null,
            'catalog'=>isset($row['product_id'])?(array)DB::table('products')->where('id',$row['product_id'])->lockForUpdate()->first():null,
            'features'=>isset($row['product_id'])?DB::table('product_features')->where('product_id',$row['product_id'])->orderBy('id')->lockForUpdate()->get()->map(fn($item)=>(array)$item)->all():[],
            'media'=>DB::table('media')->where('model_type',\App\Models\ResturantProduct::class)->where('model_id',$id)->orderBy('id')->lockForUpdate()->get()->map(fn($item)=>(array)$item)->all(),
        ];
        $this->validateState($state);return $state;
    }
    private function validateState(array $state): void
    {
        abort_unless(count($state)===9&&array_diff(array_keys($state),['row','branch','added_by','owner','parent','category','catalog','features','media'])===[],409);
        foreach(['row','branch','added_by','owner','parent','category','catalog'] as $kind){
            if(in_array($kind,['parent','category','catalog'],true)&&$state[$kind]===null)continue;
            abort_unless(is_array($state[$kind]),409);$this->shape($state[$kind],$kind);
        }
        $row=$state['row'];$branch=$state['branch'];$product=$this->id($row['id']);
        abort_unless($this->id($row['resturant_id'])===$this->id($branch['id'])
            &&$this->id($row['added_by'])===$this->id($state['added_by']['id'])&&$this->id($branch['user_id'])===$this->id($state['owner']['id']),409,'هوية الصنف أو ملكية فرعه غير متطابقة.');
        foreach(['parent'=>[$branch,'parent_id'],'category'=>[$row,'category_id'],'catalog'=>[$row,'product_id']] as $kind=>[$source,$field])
            abort_unless(isset($source[$field])?is_array($state[$kind])&&$this->id($source[$field])===$this->id($state[$kind]['id']):$state[$kind]===null,409);
        foreach(['features'=>'feature','media'=>'media'] as $key=>$kind){
            abort_unless(is_array($state[$key])&&array_is_list($state[$key])&&count($state[$key])<=1000,409);$seen=[];
            foreach($state[$key] as $part){abort_unless(is_array($part),409);$this->shape($part,$kind);$id=$this->id($part['id']);abort_if(isset($seen[$id]),409);$seen[$id]=true;
                if($key==='features')abort_unless(isset($row['product_id'])&&$this->id($part['product_id'])===$this->id($row['product_id']),409);
                else abort_unless($part['model_type']===\App\Models\ResturantProduct::class&&$this->id($part['model_id'])===$product,409);
            }
        }
    }
    private function facts(string $name,array $payload): array
    {
        abort_unless(is_array($payload['values']??null)&&is_array($payload['parameters']??null)&&is_array($payload['facts']??null),422);
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);$this->values($name,$payload['values']);
        abort_unless(empty($payload['files'])&&array_keys($payload['parameters'])===['id'],422);
        abort_unless(array_keys($payload['facts'])===['restaurant_product_before','restaurant_product_return']&&is_array($payload['facts']['restaurant_product_before'])
            &&is_array($payload['facts']['restaurant_product_return']),409);
        $this->returnQuery($payload['facts']['restaurant_product_return']);
        $before=$payload['facts']['restaurant_product_before'];$this->validateState($before);
        abort_unless($this->id($payload['parameters']['id'])===$this->id($before['row']['id']),409);return $before;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($name,$payload);$original=$this->original($name);$id=$this->id($before['branch']['id']);
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)
            &&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        abort_unless(in_array('f:'.$id,json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR),true),403,'الفرع خارج نطاق ربط الجهاز.');
        $branch=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($branch,409,'فرع الصنف لم يعد موجودًا.');
        $current=DB::table('resturant_products')->where('id',$this->id($before['row']['id']))->lockForUpdate()->first(['resturant_id']);
        // A saved receipt may outlive deletion, but never follows a product moved to another branch.
        abort_if($current&&(int)$current->resturant_id!==$id,403,'الصنف لم يعد مرتبطًا بفرعه المحفوظ.');
        $allowed=match($fresh->account_type){
            'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,
            'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$branch->parent_id===(int)$fresh->owner_resturant_id),
            'vendor'=>(int)$branch->user_id===(int)$fresh->id,
            default=>false,
        };
        abort_unless($allowed,403,'حساب الجهاز لم يعد يملك نطاق فرع الصنف.');
        $guard=auth('admin');$previous=$guard->getUser();$router=app('router');
        try{
            $guard->setUser($fresh);$probe=Request::create(url(str_replace('{id}',(string)$before['row']['id'],self::ROUTES[$name]['uri'])),'GET');
            if(request()->hasSession())$probe->setLaravelSession(request()->session());
            $route=clone $original;$route->flushController();$route->bind($probe);$probe->setRouteResolver(fn()=>$route);
            $probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            (new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
        return $fresh;
    }
    private function queryValues(string $query): array
    {
        abort_unless(strlen($query)<=4096,422);$seen=[];
        foreach(explode('&',$query) as $part){if($part==='')continue;$key=urldecode(explode('=',$part,2)[0]);
            abort_unless(in_array($key,['q','resturant_id','page'],true)&&!isset($seen[$key]),422,'حقول رابط العودة غير مقبولة أو مكررة.');$seen[$key]=true;}
        $values=[];parse_str($query,$values);
        abort_if(array_diff(array_keys($values),['q','resturant_id','page']),422,'رابط العودة يحتوي حقولًا غير مقبولة.');
        foreach($values as $value)abort_unless(is_string($value),422,'حقول رابط العودة يجب أن تكون مفردة.');
        if(isset($values['resturant_id'])&&$values['resturant_id']!=='')$this->id($values['resturant_id']);
        if(isset($values['page']))abort_unless(preg_match('/^[1-9][0-9]{0,8}$/D',$values['page']),422);
        return $values;
    }
    private function returnContext(Request $request,int $branch): array
    {
        $parts=parse_url($request->header('Referer')??'');
        abort_unless(is_array($parts)&&($parts['path']??'')==='/admin/resturants/'.$branch,422,'رابط العودة خارج صفحة فرع الصنف.');
        $this->sameOrigin($parts);$query=$parts['query']??'';$values=$this->queryValues($query);
        return ['query'=>$query,'filter_restaurant_id'=>isset($values['resturant_id'])&&$values['resturant_id']!==''?$this->id($values['resturant_id']):null];
    }
    private function sameOrigin(array $parts): void
    {
        abort_if(isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']),422);
        if(!isset($parts['host']))return;$origin=parse_url(url('/'));
        abort_unless(($parts['scheme']??null)===($origin['scheme']??null)&&strcasecmp($parts['host'],$origin['host']??'')===0
            &&($parts['port']??null)===($origin['port']??null),422,'رابط العودة خارج الداشبورد.');
    }
    private function returnQuery(array $context): string
    {
        abort_unless(array_keys($context)===['query','filter_restaurant_id']&&is_string($context['query']),422);
        $values=$this->queryValues($context['query']);$id=$context['filter_restaurant_id'];
        $present=isset($values['resturant_id'])&&$values['resturant_id']!=='';
        abort_unless($present?($id!==null):$id===null,422);if(!$present)return $context['query'];$id=$this->id($id);
        if($id===$this->id($values['resturant_id']))return $context['query'];
        // Preserve every original encoded search/page value; translate only this named numeric filter.
        $parts=explode('&',$context['query']);foreach($parts as &$part){$pair=explode('=',$part,2);if(urldecode($pair[0])==='resturant_id')$part=$pair[0].'='.$id;}unset($part);
        return implode('&',$parts);
    }
    private function requestPayload(string $name,Request $request,string $command,?array $saved=null): array
    {
        abort_unless($request->isMethod('GET'),405);abort_if($request->allFiles(),501);
        $parameters=$request->route()->parameters();abort_unless(array_keys($parameters)===['id'],422);$id=$this->id($parameters['id']);
        $values=$request->except('_token','_method','_desktop_command');$values['idempotency_key']=$command;$values=$this->values($name,$values);
        if($saved===null){$before=$this->state($id);$saved=['restaurant_product_before'=>$before,'restaurant_product_return'=>$this->returnContext($request,$this->id($before['branch']['id']))];}
        return ['parameters'=>['id'=>$id],'values'=>$values,'files'=>[],'facts'=>$saved];
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {
        $saved=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name);
        $payload=$this->requestPayload($name,$request,$command,$saved);
        $scope=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        $payload=$this->requestPayload($name,$request,$command,$saved);$this->authorize($name,$payload,$actor,$device);return $payload;
    }
    private function referencePaths(array $payload): array
    {
        $paths=[];foreach(self::REFERENCES as $path=>$entity)$paths[str_starts_with($path,'parameters.')?$path:'facts.restaurant_product_before.'.$path]=$entity;
        $paths['facts.restaurant_product_return.filter_restaurant_id']='menu_restaurant';
        foreach($payload['facts']['restaurant_product_before']['features']??[] as $index=>$row)
            foreach(['id'=>'catalog_feature','product_id'=>'catalog_product','added_by'=>'restaurant_flag_user'] as $field=>$entity)$paths['facts.restaurant_product_before.features.'.$index.'.'.$field]=$entity;
        foreach($payload['facts']['restaurant_product_before']['media']??[] as $index=>$row)
            foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$paths['facts.restaurant_product_before.media.'.$index.'.'.$field]=$entity;
        return $paths;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($this->referencePaths($payload) as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}return $payload;
    }
    public function validateReferences(array $payload): void
    {
        abort_unless(is_array($payload['facts']??null)&&is_array($payload['facts']['restaurant_product_before']??null),422);
        foreach(['features','media'] as $key)abort_unless(is_array($payload['facts']['restaurant_product_before'][$key]??null)
            &&array_is_list($payload['facts']['restaurant_product_before'][$key])&&count($payload['facts']['restaurant_product_before'][$key])<=1000,422);
        $paths=$this->referencePaths($payload);
        $walk=function($value,string $path='')use(&$walk,$paths){
            if(!is_array($value))return;
            if(array_key_exists('$desktop_ref',$value)){
                $ref=$value['$desktop_ref'];$expected=$paths[$path]??null;
                abort_unless($expected&&count($value)===1&&is_array($ref)&&count($ref)===3&&array_diff(array_keys($ref),['entity','command_id','local_id'])===[]
                    &&is_string($ref['entity']??null)&&($ref['entity']===$expected||preg_match('/^'.preg_quote($expected,'/').'\.[0-9]+$/D',$ref['entity'])),422,'نوع مرجع الصنف أو علاقته غير صحيح.');
                $this->id($ref['local_id']);Validator::make($ref,['command_id'=>'required|uuid'])->validate();return;
            }
            foreach($value as $key=>$part)$walk($part,$path===''?(string)$key:$path.'.'.$key);
        };$walk($payload);
    }
    public function capture(string $name,array $payload,callable $work): array
    {
        $before=$this->facts($name,$payload);$response=$work();$status=$response->getStatusCode();
        if($status>=400||request()->hasSession()&&in_array('errors',request()->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        abort_unless(in_array($status,[302,303],true)&&strlen($response->getContent())<=1024*1024,409);
        $parts=parse_url($response->headers->get('Location')??'');abort_unless(is_array($parts),409);$this->sameOrigin($parts);$location=$parts['path']??'';
        $query=$this->returnQuery($payload['facts']['restaurant_product_return']);
        abort_unless($location==='/admin/resturants/'.$this->id($before['branch']['id'])&&($parts['query']??'')===$query,409,'رابط الرجوع للصنف خارج صفحة فرعه.');
        if($query!=='')$location.='?'.$query;
        $after=$this->state($this->id($payload['parameters']['id']));$field=self::ROUTES[$name]['field'];
        $expected=$before;$expected['row'][$field]=$payload['values'][$field]??null;
        abort_unless($this->same($expected,$after),409,'النتيجة عدّلت بيانات خارج علامة الصنف المطلوبة.');
        return ['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'success'=>session('success'),'after'=>$after,'references'=>['menu_restaurant'=>$this->id($after['branch']['id']),'menu_restaurant_product'=>$this->id($after['row']['id'])]];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);
        if($http['type'])$response->headers->set('Content-Type',$http['type']);if($http['location'])$response->headers->set('Location',url($http['location']));
        if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    public function outputs(array $result): array
    {
        if(!isset($result['http']))return [];
        abort_unless(is_array($result['after']??null),409,'مراجع نتيجة الصنف غير مكتملة.');$this->validateState($result['after']);
        $expected=['menu_restaurant'=>$this->id($result['after']['branch']['id']),'menu_restaurant_product'=>$this->id($result['after']['row']['id'])];
        abort_unless(($result['references']??null)===$expected,409,'مراجع نتيجة الصنف تختلف عن هويته المحفوظة.');return $expected;
    }
    private function comparable(array $state): array
    {
        // Product updated_at is the controller's write time; journal occurrence may cross a second boundary.
        unset($state['row']['updated_at']);
        foreach(['features','media'] as $key)usort($state[$key],fn($a,$b)=>$a['id']<=>$b['id']);return $state;
    }
    private function same(array $a,array $b): bool
    {
        return hash_equals(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($a)),app(DesktopDashboardJournal::class)->fingerprint($this->comparable($b)));
    }
    public function verifyAfter(array $server,array $local,array $resolvedPayload,array $originalPayload): void
    {
        abort_unless(is_array($server['after']??null)&&is_array($local['after']??null),409,'نتيجة علامة الصنف غير مكتملة.');
        $this->outputs($server);$this->outputs($local);
        $original=$originalPayload['facts']['restaurant_product_before']??null;abort_unless(is_array($original),409);
        foreach(['features','media'] as $key)abort_unless(count($original[$key]??[])===count($local['after'][$key]),409);
        foreach($this->referencePaths($originalPayload) as $path=>$entity){
            if(!str_starts_with($path,'facts.restaurant_product_before.'))continue;$suffix=substr($path,strlen('facts.restaurant_product_before.'));
            $old=data_get($originalPayload,$path);if(is_array($old)&&isset($old['$desktop_ref']))$old=$this->id($old['$desktop_ref']['local_id']??null);
            abort_unless(data_get($local['after'],$suffix)===$old,409,'هوية الصنف المحلية أو أحد روابطه تغيّرت.');
            if($old!==null)data_set($local['after'],$suffix,data_get($resolvedPayload,$path));
        }
        abort_unless($this->same($server['after'],$local['after']),409,'نتيجة علامة الصنف تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);$id=$this->id($payload['parameters']['id']);
        abort_unless($this->same($before,$this->state($id,true)),409,'الصنف أو روابطه أو صوره تغيّرت على السيرفر؛ العملية المحلية محفوظة للمراجعة.');
        $original=$this->original($name);$values=$this->values($name,$payload['values']);unset($values['idempotency_key']);
        $request=Request::create(url(str_replace('{id}',(string)$id,self::ROUTES[$name]['uri'])),'GET',$values);
        $query=$this->returnQuery($payload['facts']['restaurant_product_return']);
        $request->headers->set('Referer',url('/admin/resturants/'.$this->id($before['branch']['id'])).($query!==''?'?'.$query:''));$request->headers->set('Accept','text/html');
        $session=new Store('desktop-restaurant-product-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);
            $guard->setUser($actor);auth()->shouldUse('admin');$route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router=app('router');$middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
