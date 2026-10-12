<?php
namespace App\Services\Dashboard;

use App\Models\{Resturant,ResturantProduct,User};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** The original parent-menu checkbox form, with one immutable transaction for its ordered batch. */
class DesktopDashboardRestaurantMenuCopy
{
    public const ROUTE='copy_menu';
    public const MAX_SELECTED=100;
    public const MAX_TARGET=500;
    private const RESTAURANT_COLUMNS=['id','added_by','user_id','name','status','created_at','updated_at','parent_id','address','avg_rate','lat','lng',
        'delivery_time','country_name','city_name','is_featured','resturant_phone','area_id','under_contract','open_at','close_at','min_order_price',
        'service_fees','km_price','sortby_is_featured','control','default_0_1','default_1_2','default_2_3'];
    private const RELATIONS=[
        'row.id'=>'menu_restaurant_product','row.resturant_id'=>'menu_restaurant','row.added_by'=>'restaurant_flag_user','row.product_id'=>'catalog_product','row.category_id'=>'catalog_category',
        'branch.id'=>'menu_restaurant','branch.user_id'=>'restaurant_flag_user','branch.parent_id'=>'menu_restaurant','branch.added_by'=>'restaurant_flag_user',
        'added_by.id'=>'restaurant_flag_user','owner.id'=>'restaurant_flag_user','parent.id'=>'menu_restaurant','parent.user_id'=>'restaurant_flag_user','parent.parent_id'=>'menu_restaurant',
        'category.id'=>'catalog_category','category.added_by'=>'restaurant_flag_user','category.parent_id'=>'catalog_category',
        'catalog.id'=>'catalog_product','catalog.added_by'=>'restaurant_flag_user','catalog.category_id'=>'catalog_category','catalog.subcategory_id'=>'catalog_category',
    ];
    private const TARGET_REFS=['row.id'=>'menu_restaurant','row.added_by'=>'restaurant_flag_user','row.user_id'=>'restaurant_flag_user','row.parent_id'=>'menu_restaurant','row.area_id'=>'catalog_area',
        'added_by.id'=>'restaurant_flag_user','owner.id'=>'restaurant_flag_user','parent.id'=>'menu_restaurant','parent.user_id'=>'restaurant_flag_user','parent.parent_id'=>'menu_restaurant'];
    private static bool $listening=false;
    private static ?array $created=null;
    private static ?array $replay=null;
    private static array $createTimes=[];
    private static array $updateTimes=[];
    private static array $timestampModels=[];

    public static function handles(?string $name): bool {return $name===self::ROUTE;}
    private function support(): DesktopDashboardRestaurantProductCrud {return app(DesktopDashboardRestaurantProductCrud::class);}
    private function images(): DesktopDashboardProductImages {return app(DesktopDashboardProductImages::class);}
    private function id($value): int
    {
        abort_unless((is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(int)$value>0&&(string)(int)$value===(string)$value,422);return (int)$value;
    }
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->uri()==='admin/resturant/copy/items'&&$route->methods()===['POST']
            &&$route->getActionName()==='App\\Http\\Controllers\\Api\\V1\\Vendor\\ResturantProductController@copy_menu'
            &&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار نسخ المنيو الأصلي تغيّر.');return $route;
    }
    public function command(Request $request): string
    {
        abort_unless($request->isMethod('POST'),405);abort_if($request->exists('_method'),422);
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409,'رقم طلب العملية مختلف عن سجلها.');$command=$header??$form;
        Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(array $values): array
    {
        abort_unless(array_diff(array_keys($values),['idempotency_key','resturant_id','restraunt_product'])===[],422);
        Validator::make($values,['idempotency_key'=>'required|uuid','restraunt_product'=>'required|array|min:1|max:100'])->validate();
        abort_unless(array_is_list($values['restraunt_product']),422);$this->id($values['resturant_id']??null);
        foreach($values['restraunt_product'] as $id)$this->id($id);return $values;
    }
    private function target(int $id): array
    {
        $row=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($row,409);$row=(array)$row;
        $target=['row'=>$row,'added_by'=>(array)DB::table('users')->where('id',$row['added_by'])->lockForUpdate()->first(['id']),
            'owner'=>(array)DB::table('users')->where('id',$row['user_id'])->lockForUpdate()->first(['id']),
            'parent'=>isset($row['parent_id'])?(array)DB::table('resturants')->where('id',$row['parent_id'])->lockForUpdate()->first(['id','user_id','parent_id']):null];
        $this->validateTarget($target);return $target;
    }
    private function validateTarget(array $target): void
    {
        abort_unless(array_keys($target)===['row','added_by','owner','parent']&&is_array($target['row'])&&count($target['row'])===count(self::RESTAURANT_COLUMNS)
            &&array_diff(array_keys($target['row']),self::RESTAURANT_COLUMNS)===[],409,'حقائق فرع المنيو غير مكتملة.');
        foreach($target['row'] as $value)abort_unless($value===null||is_scalar($value),409);
        foreach(['added_by','owner'] as $key)abort_unless(is_array($target[$key])&&array_keys($target[$key])===['id'],409);
        abort_unless($this->id($target['row']['added_by'])===$this->id($target['added_by']['id'])&&$this->id($target['row']['user_id'])===$this->id($target['owner']['id']),409);
        abort_unless(is_array($target['parent'])&&array_keys($target['parent'])===['id','user_id','parent_id']
            &&$this->id($target['row']['parent_id'])===$this->id($target['parent']['id'])&&$this->id($target['row']['id'])!==$this->id($target['parent']['id']),409,'النموذج المحلي يدعم نسخ منيو المطعم الرئيسي إلى فرعه.');
    }
    private function membership(int $target): array
    {
        // The controller caches this original relation once. Preserve its observed category order.
        $ids=DB::table('resturant_products')->where('resturant_id',$target)->orderBy('category_id','ASC')->lockForUpdate()->pluck('id')->all();
        abort_if(count($ids)>self::MAX_TARGET,413,'منيو الفرع يتجاوز حد المعاينة.');return $ids;
    }
    private function buildFacts(Request $request,array $values,User $actor): array
    {
        $target=$this->target($this->id($values['resturant_id']));$products=[];$targetImages=[];$sources=[];$sourceImages=[];
        foreach($this->membership($this->id($target['row']['id'])) as $id){$products[]=$this->support()->menuCopyState($this->id($id));$targetImages[]=$this->images()->facts($this->id($id));}
        foreach($values['restraunt_product'] as $id){$sources[]=$this->support()->menuCopyState($this->id($id));$sourceImages[]=$this->images()->facts($this->id($id));}
        return ['restaurant_menu_copy_before'=>['target'=>$target,'actor'=>['id'=>(int)$actor->id],'products'=>$products,'sources'=>$sources,'source_images'=>$sourceImages,'target_images'=>$targetImages],
            'restaurant_menu_copy_return'=>$this->support()->menuCopyReturnContext($request,$this->id($target['row']['id']))];
    }
    private function rebaseImages(array $images): array
    {
        foreach($images as &$image){abort_unless(is_array($image)&&is_array($image['row']??null)&&is_array($image['files']??null),422);$id=$this->id($image['row']['id']);
            foreach($image['files'] as &$file){abort_unless(is_array($file)&&is_string($file['relative']??null)&&preg_match('#^([1-9][0-9]{0,18})/([^\x00]+)$#D',$file['relative'],$parts)
                &&($file['path']??null)==='products/'.$file['relative']&&DesktopDashboardMedia::safePath($file['path']),422);$file['relative']=$id.'/'.$parts[2];$file['path']='products/'.$file['relative'];}unset($file);
        }unset($image);return $images;
    }
    private function facts(string $name,array $payload): array
    {
        $this->original($name);abort_unless(array_keys($payload)===['parameters','values','files','facts']&&$payload['parameters']===[]&&$payload['files']===[]&&is_array($payload['values'])&&is_array($payload['facts']),422);
        $values=$this->values($payload['values']);abort_unless(array_keys($payload['facts'])===['restaurant_menu_copy_before','restaurant_menu_copy_return'],409);
        $before=$payload['facts']['restaurant_menu_copy_before'];abort_unless(is_array($before)&&array_keys($before)===['target','actor','products','sources','source_images','target_images'],409);
        $this->validateTarget($before['target']);abort_unless(is_array($before['actor'])&&array_keys($before['actor'])===['id'],409);$this->id($before['actor']['id']);
        $target=$this->id($before['target']['row']['id']);$parent=$this->id($before['target']['parent']['id']);abort_unless($target===$this->id($values['resturant_id']),409);
        foreach(['products'=>self::MAX_TARGET,'sources'=>self::MAX_SELECTED,'source_images'=>self::MAX_SELECTED,'target_images'=>self::MAX_TARGET] as $key=>$max)
            abort_unless(is_array($before[$key])&&array_is_list($before[$key])&&count($before[$key])<=$max,409);
        abort_unless(count($before['sources'])===count($values['restraunt_product'])&&count($before['source_images'])===count($before['sources'])&&count($before['target_images'])===count($before['products']),409);
        $seen=[];$catalogs=[];$proofs=[];$features=0;
        foreach(['products'=>'target_images','sources'=>'source_images'] as $key=>$imagesKey)foreach($before[$key] as $i=>$state){
            abort_unless(is_array($state),409);$this->support()->menuCopyValidateState($state);$id=$this->id($state['row']['id']);$branch=$this->id($state['row']['resturant_id']);
            abort_unless($branch===($key==='products'?$target:$parent),409,'نسخ المنيو المحلي يقبل أصناف المطعم الرئيسي فقط.');
            if($key==='products'){
                abort_if(isset($seen[$id]),409);$seen[$id]=true;$catalog=$state['row']['product_id'];
                if($catalog!==null){$catalog=$this->id($catalog);abort_if(isset($catalogs[$catalog]),409,'الفرع يحتوي أكثر من صنف لنفس المنتج؛ يحتاج مراجعة قبل النسخ.');$catalogs[$catalog]=$id;}
            }else abort_unless($id===$this->id($values['restraunt_product'][$i])&&$state['catalog']!==null&&$state['category']!==null,409);
            $features+=count($state['features']);abort_if($features>10000,413);
            $before[$imagesKey][$i]=$this->rebaseImages($before[$imagesKey][$i]);$images=$before[$imagesKey][$i];$this->images()->validateFacts($images);
            abort_unless(array_column($images,'row')===$state['media'],409,'حقائق صور المنيو تختلف عن صفوفها.');
            foreach($images as $image)foreach($image['files'] as $file){$path=$file['path'];abort_if(isset($proofs[$path])&&$proofs[$path]!==$file,409);$proofs[$path]=$file;}
        }
        abort_if(count($proofs)>2000||array_sum(array_column($proofs,'bytes'))>256*1024*1024,413,'صور المنيو تتجاوز حد المعاينة؛ انسخ مجموعة أصغر.');
        $this->support()->menuCopyReturnQuery($payload['facts']['restaurant_menu_copy_return']);return $before;
    }
    private function requestPayload(string $name,Request $request,string $command,User $actor,?array $saved=null): array
    {
        $this->original($name);abort_unless($request->isMethod('POST'),405);abort_if($request->allFiles()||$request->exists('_method'),422);
        abort_unless($request->route()->parameters()===[],422);$values=$request->except('_token','_desktop_command');$values['idempotency_key']=$command;$values=$this->values($values);
        return ['parameters'=>[],'values'=>$values,'files'=>[],'facts'=>$saved??$this->buildFacts($request,$values,$actor)];
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {
        $saved=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name);
        $payload=$this->requestPayload($name,$request,$command,$actor,$saved);$scope=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        $payload=$this->requestPayload($name,$request,$command,$actor,$saved);$this->authorize($name,$payload,$actor,$device);return $payload;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $this->support()->menuCopyTransactionalTables();$before=$this->facts($name,$payload);$fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)&&!in_array($fresh->status,['disabled','declined'],true)
            &&(int)$scope->actor_id===(int)$fresh->id&&$this->id($before['actor']['id'])===(int)$fresh->id,403);abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        $ids=[$this->id($before['target']['row']['id']),$this->id($before['target']['parent']['id'])];
        foreach($ids as $id){
            abort_unless(in_array('f:'.$id,json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR),true),403,'المطعم الرئيسي أو الفرع خارج نطاق ربط الجهاز.');
            $branch=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($branch,409);
            $allowed=match($fresh->account_type){'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,
                'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$branch->parent_id===(int)$fresh->owner_resturant_id),
                'vendor'=>(int)$branch->user_id===(int)$fresh->id,default=>false};abort_unless($allowed,403,'الحساب لم يعد يملك نطاق المطعم الرئيسي أو الفرع.');
        }
        $currentTarget=DB::table('resturants')->where('id',$ids[0])->lockForUpdate()->first(['parent_id']);abort_unless($currentTarget&&(int)$currentTarget->parent_id===$ids[1],403,'علاقة الفرع بالمطعم الرئيسي تغيّرت.');
        foreach(['products'=>$ids[0],'sources'=>$ids[1]] as $key=>$branch)foreach($before[$key] as $state){
            $current=DB::table('resturant_products')->where('id',$state['row']['id'])->lockForUpdate()->first(['resturant_id']);
            abort_if($current&&(int)$current->resturant_id!==$branch,403,'صنف المنيو انتقل خارج نطاق العملية المحفوظة.');
        }
        $guard=auth('admin');$previous=$guard->getUser();$route=$this->original($name);$router=app('router');
        try{
            $guard->setUser($fresh);$probe=Request::create(url('/admin/resturant/copy/items'),'POST');if(request()->hasSession())$probe->setLaravelSession(request()->session());
            $route=clone $route;$route->flushController();$route->bind($probe);$probe->setRouteResolver(fn()=>$route);$probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            abort_unless(($visible=Resturant::find($ids[0]))&&(int)$visible->getKey()===$ids[0],403,'نطاق المطعم الأصلي لا يسمح بعرض الفرع.');
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            (new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
        return $fresh;
    }
    private function referencePaths(array $payload): array
    {
        $base='facts.restaurant_menu_copy_before.';$paths=['values.resturant_id'=>'menu_restaurant',$base.'actor.id'=>'restaurant_flag_user',
            'facts.restaurant_menu_copy_return.filter_restaurant_id'=>'menu_restaurant'];
        foreach($payload['values']['restraunt_product']??[] as $i=>$id)$paths['values.restraunt_product.'.$i]='menu_restaurant_product';
        foreach(self::TARGET_REFS as $path=>$entity)$paths[$base.'target.'.$path]=$entity;
        foreach(['products','sources'] as $key)foreach($payload['facts']['restaurant_menu_copy_before'][$key]??[] as $i=>$state){
            foreach(self::RELATIONS as $path=>$entity)$paths[$base.$key.'.'.$i.'.'.$path]=$entity;
            foreach($state['features']??[] as $j=>$row)foreach(['id'=>'catalog_feature','product_id'=>'catalog_product','added_by'=>'restaurant_flag_user'] as $field=>$entity)$paths[$base.$key.'.'.$i.'.features.'.$j.'.'.$field]=$entity;
            foreach($state['media']??[] as $j=>$row)foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$paths[$base.$key.'.'.$i.'.media.'.$j.'.'.$field]=$entity;
        }
        foreach(['source_images','target_images'] as $key)foreach($payload['facts']['restaurant_menu_copy_before'][$key]??[] as $i=>$images)
            foreach($images as $j=>$image)foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$paths[$base.$key.'.'.$i.'.'.$j.'.row.'.$field]=$entity;
        return $paths;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($this->referencePaths($payload) as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}return $payload;
    }
    private function localId($value): int {return $this->id(is_array($value)?($value['$desktop_ref']['local_id']??null):$value);}
    public function validateReferences(array $payload): void
    {
        $before=$payload['facts']['restaurant_menu_copy_before']??null;abort_unless(is_array($before),422);
        foreach(['products'=>self::MAX_TARGET,'sources'=>self::MAX_SELECTED,'source_images'=>self::MAX_SELECTED,'target_images'=>self::MAX_TARGET] as $key=>$max)
            abort_unless(is_array($before[$key]??null)&&array_is_list($before[$key])&&count($before[$key])<=$max,422);
        foreach(['products','sources'] as $key)foreach($before[$key] as $state){abort_unless(is_array($state),422);
            foreach(['features'=>1000,'media'=>50] as $part=>$max)abort_unless(is_array($state[$part]??null)&&array_is_list($state[$part])&&count($state[$part])<=$max,422);}
        foreach(['source_images','target_images'] as $key)foreach($before[$key] as $images){abort_unless(is_array($images)&&array_is_list($images)&&count($images)<=50,422);
            foreach($images as $image){abort_unless(is_array($image)&&is_array($image['row']??null)&&is_array($image['files']??null),422);$id=$this->localId($image['row']['id']??null);
                foreach($image['files'] as $file)abort_unless(is_array($file)&&is_string($file['relative']??null)&&str_starts_with($file['relative'],$id.'/')
                    &&($file['path']??null)==='products/'.$file['relative']&&DesktopDashboardMedia::safePath($file['path']),422);}}
        $paths=$this->referencePaths($payload);$walk=function($value,string $path='')use(&$walk,$paths){
            if(!is_array($value))return;if(array_key_exists('$desktop_ref',$value)){
                $ref=$value['$desktop_ref'];$expected=$paths[$path]??null;abort_unless($expected&&count($value)===1&&is_array($ref)&&count($ref)===3&&array_diff(array_keys($ref),['entity','command_id','local_id'])===[]
                    &&is_string($ref['entity']??null)&&($ref['entity']===$expected||preg_match('/^'.preg_quote($expected,'/').'\.[0-9]+$/D',$ref['entity'])),422,'نوع مرجع المنيو أو علاقته غير صحيح.');
                $this->id($ref['local_id']);Validator::make($ref,['command_id'=>'required|uuid'])->validate();return;
            }foreach($value as $key=>$part)$walk($part,$path===''?(string)$key:$path.'.'.$key);
        };$walk($payload);
    }
    private function comparable($value)
    {
        if(!is_array($value))return $value;foreach($value as $key=>$part)$value[$key]=$this->comparable($part);
        if(array_is_list($value)&&$value&&is_array($value[0])){
            if(isset($value[0]['id']))usort($value,fn($a,$b)=>$a['id']<=>$b['id']);
            elseif(isset($value[0]['row']['id']))usort($value,fn($a,$b)=>$a['row']['id']<=>$b['row']['id']);
            elseif(isset($value[0]['relative']))usort($value,fn($a,$b)=>strcmp($a['relative'],$b['relative']));
        }return $value;
    }
    private function same($a,$b): bool
    {
        return hash_equals(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($a)),app(DesktopDashboardJournal::class)->fingerprint($this->comparable($b)));
    }
    private function assertBefore(array $before): void
    {
        $target=$this->id($before['target']['row']['id']);abort_unless($this->same($before['target'],$this->target($target)),409,'بيانات فرع المنيو تغيّرت.');
        $actual=$this->membership($target);$expected=array_map(fn($state)=>$this->id($state['row']['id']),$before['products']);sort($actual);sort($expected);
        abort_unless($actual===$expected,409,'عضوية منيو الفرع تغيّرت قبل النسخ.');
        foreach(['products'=>'target_images','sources'=>'source_images'] as $key=>$images)foreach($before[$key] as $i=>$state){
            abort_unless($this->same($state,$this->support()->menuCopyState($this->id($state['row']['id']),true)),409,'صنف المنيو أو الكتالوج أو صوره تغيّرت.');$this->images()->verifyFacts($before[$images][$i]);
        }
    }
    private function firstImage(array $state,array $images): ?array
    {
        $rows=array_values(array_filter($state['media'],fn($row)=>$row['collection_name']==='product_image'));if(!$rows)return null;
        usort($rows,fn($a,$b)=>($a['order_column']??0)<=>($b['order_column']??0));
        abort_if(count($rows)>1&&($rows[0]['order_column']??0)===($rows[1]['order_column']??0),409,'ترتيب أول صورة للصنف ملتبس؛ يحتاج مراجعة.');
        foreach($images as $image)if($this->id($image['row']['id'])===$this->id($rows[0]['id']))return $image;abort(409);
    }
    private function plan(array $before): array
    {
        $initial=[];foreach($before['products'] as $state)if($state['row']['product_id']!==null)$initial[$this->id($state['row']['product_id'])]=$this->id($state['row']['id']);
        $plan=[];foreach($before['sources'] as $i=>$source){$existing=$initial[$this->id($source['row']['product_id'])]??null;
            $plan[]=['selection'=>$i,'source_product'=>$this->id($source['row']['id']),'existing'=>$existing,
                'image'=>$existing===null?$this->firstImage($source,$before['source_images'][$i]):null];}return $plan;
    }
    private function utc($value): Carbon
    {
        abort_unless(is_string($value)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00$/D',$value),409);
        try{$time=Carbon::parse($value,'UTC');}catch(\Throwable $error){abort(409);}abort_unless($time->toIso8601String()===$value,409);return $time;
    }
    private function sourceTime($value,Carbon $start,Carbon $end): string
    {
        $zone=(string)config('app.timezone');abort_unless(in_array($zone,timezone_identifiers_list(),true)&&is_string($value)
            &&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$value),409);
        try{$time=Carbon::parse($value,$zone);}catch(\Throwable $error){abort(409);}
        abort_unless($time->gte($start->copy()->subSeconds(5))&&$time->lte($end->copy()->addSeconds(5)),409,'وقت صنف المنيو أو صورته خارج فترة العملية المحفوظة.');return $value;
    }
    private function byProduct(array $products): array
    {
        $map=[];foreach($products as $state){$id=$this->id($state['row']['id']);abort_if(isset($map[$id]),409);$map[$id]=$state;}return $map;
    }
    private function registerCapture(): void
    {
        if(self::$listening)return;
        app('events')->listen('eloquent.created: '.ResturantProduct::class,function($model){if(self::$created!==null)self::$created[]=(int)$model->getKey();});
        app('events')->listen('eloquent.creating: '.ResturantProduct::class,function($model){
            if(self::$created===null||self::$replay===null)return;$times=self::$createTimes[count(self::$created)]??null;abort_unless(is_array($times),409);
            foreach($times as $field=>$time)$model->setAttribute($field,$time);
        });
        app('events')->listen('eloquent.updating: '.ResturantProduct::class,function($model){
            if(self::$created===null||self::$replay===null)return;$time=self::$updateTimes[(int)$model->getKey()]??null;
            if($time!==null){
                $key=spl_object_id($model);if(!isset(self::$timestampModels[$key]))self::$timestampModels[$key]=[$model,$model->timestamps];
                // Repeated price updates may already carry this final timestamp. Suppress only this
                // model's automatic clock so updateTimestamps cannot replace an unchanged value.
                $model->timestamps=false;$model->setAttribute('updated_at',$time);
            }
        });
        app('events')->listen('eloquent.saved: '.ResturantProduct::class,function($model){
            $key=spl_object_id($model);if(isset(self::$timestampModels[$key])){[$saved,$timestamps]=self::$timestampModels[$key];$saved->timestamps=$timestamps;unset(self::$timestampModels[$key]);}
        });self::$listening=true;
    }
    private function restoreTimestampModels(): void
    {
        foreach(self::$timestampModels as [$model,$timestamps])$model->timestamps=$timestamps;self::$timestampModels=[];
    }
    private function prepareReplay(array $before,array $plan): array
    {
        if(self::$replay===null)return [];$local=self::$replay['result'];$old=self::$replay['payload']['facts']['restaurant_menu_copy_before'];$localProducts=$this->byProduct($local['after']['products']);
        self::$createTimes=[];self::$updateTimes=[];$media=[];
        foreach($plan as $i=>$entry){$step=$local['after']['steps'][$i]??null;abort_unless(is_array($step)&&$step['selection']===$i&&$step['mode']===($entry['existing']===null?'create':'price'),409);
            $state=$localProducts[$this->id($step['target_product'])]??null;abort_unless(is_array($state),409);
            if($entry['existing']===null)self::$createTimes[]=['created_at'=>$state['row']['created_at'],'updated_at'=>$state['row']['updated_at']];
            else {
                $slot=null;foreach($before['products'] as $j=>$target)if($this->id($target['row']['id'])===$entry['existing'])$slot=$j;abort_unless($slot!==null,409);
                $localTarget=$this->localId($old['products'][$slot]['row']['id']);
                abort_unless($this->id($step['target_product'])===$localTarget,409);
                self::$updateTimes[$entry['existing']]=$state['row']['updated_at'];
            }
            if($entry['image']!==null){$id=$this->id($step['new_media']);$row=null;foreach($state['media'] as $part)if($this->id($part['id'])===$id)$row=$part;abort_unless(is_array($row),409);
                $media[$i]=['created_at'=>$row['created_at'],'updated_at'=>$row['updated_at'],'order_column'=>$row['order_column']];}
            else abort_unless($step['new_media']===null,409);
        }return $media;
    }
    private function assertAfter(array $before,array $plan,array $after): void
    {
        abort_unless($this->same($before['target'],$after['target']),409,'النسخ غيّر بيانات فرع المنيو.');$states=$this->byProduct($after['products']);$expected=[];
        foreach($before['products'] as $state)$expected[$this->id($state['row']['id'])]=$state;
        $start=$this->utc($after['started_at']);$end=$this->utc($after['completed_at']);
        foreach($plan as $i=>$entry){$step=$after['steps'][$i];$source=$before['sources'][$i];$id=$this->id($step['target_product']);$actual=$states[$id]??null;abort_unless(is_array($actual),409);
            if($entry['existing']!==null){abort_unless($id===$entry['existing']&&$step['new_media']===null&&$step['source_media']===null,409);
                $expected[$id]['row']['product_price']=$source['row']['product_price'];
                if($actual['row']['updated_at']!==$expected[$id]['row']['updated_at'])$this->sourceTime($actual['row']['updated_at'],$start,$end);
                $expected[$id]['row']['updated_at']=$actual['row']['updated_at'];
            }else {
                abort_if(isset($expected[$id]),409);$row=$source['row'];$row['id']=$id;$row['resturant_id']=$before['target']['row']['id'];$row['added_by']=$before['actor']['id'];
                $row['created_at']=$this->sourceTime($actual['row']['created_at'],$start,$end);$row['updated_at']=$this->sourceTime($actual['row']['updated_at'],$start,$end);
                abort_unless($this->same($row,$actual['row']),409,'الصنف المنسوخ غيّر بيانات المصدر.');
                $branch=$before['target']['row'];$expectedState=['row'=>$row,'branch'=>array_intersect_key($branch,array_flip(['id','user_id','parent_id','added_by'])),
                    'added_by'=>$before['actor'],'owner'=>$before['target']['owner'],'parent'=>$before['target']['parent'],
                    'category'=>$source['category'],'catalog'=>$source['catalog'],'features'=>$source['features'],'media'=>$actual['media']];
                abort_unless($this->same($expectedState,$actual)&&count($actual['media'])===($entry['image']?1:0),409,'الصنف المنسوخ غيّر علاقاته أو صوره.');
                if($entry['image']){
                    $media=$actual['media'][0];$original=$entry['image']['row'];abort_unless($step['new_media']===$media['id']&&$step['source_media']===$original['id']
                        &&$media['collection_name']==='product_image'&&$media['disk']==='products'&&$media['name']===$original['name']&&$media['file_name']===$original['file_name']
                        &&$media['mime_type']===$original['mime_type']&&(int)$media['size']===(int)$original['size'],409,'الصورة المنسوخة تختلف عن أول صورة أصلية.');
                    Validator::make($media,['uuid'=>'required|uuid'])->validate();$this->sourceTime($media['created_at'],$start,$end);$this->sourceTime($media['updated_at'],$start,$end);
                    foreach(['manipulations','custom_properties','generated_conversions','responsive_images'] as $field){$value=json_decode((string)$media[$field],true);abort_unless(json_last_error()===JSON_ERROR_NONE&&is_array($value)&&$value===[],409,'الصورة الجديدة تحمل بيانات خارج النسخ الأصلي.');}
                    $image=null;foreach($after['images'] as $part)if($part['row']['id']===$media['id'])$image=$part;abort_unless(is_array($image)&&count($image['files'])===1,409);
                    $primary=array_values(array_filter($entry['image']['files'],fn($file)=>$file['relative']===$original['id'].'/'.$original['file_name']))[0];$file=$image['files'][0];
                    abort_unless($file['bytes']===$primary['bytes']&&$file['sha256']===$primary['sha256']&&$file['mime']===$primary['mime'],409,'بايتات الصورة المنسوخة مختلفة.');
                }else abort_unless($step['new_media']===null&&$step['source_media']===null,409);
                $expected[$id]=$actual;
            }
        }
        abort_unless(count($expected)===count($states),409,'النسخ أنشأ صنفًا خارج الاختيارات.');
        foreach($expected as $id=>$state)abort_unless(isset($states[$id])&&$this->same($state,$states[$id]),409,'النسخ غيّر بيانات خارج السعر أو الصنف المطلوب.');
        $old=[];foreach($before['target_images'] as $images)foreach($images as $image)$old[$this->id($image['row']['id'])]=$image;
        foreach($after['images'] as $image){$id=$this->id($image['row']['id']);if(isset($old[$id])){abort_unless($this->same($old[$id],$image),409,'النسخ غيّر صورة قديمة للفرع.');unset($old[$id]);}}
        abort_unless($old===[],409,'صورة قديمة للفرع اختفت أثناء النسخ.');
    }
    public function capture(string $name,array $payload,callable $work): array
    {
        $started=self::$replay['result']['after']['started_at']??now('UTC')->toIso8601String();$before=$this->facts($name,$payload);$this->assertBefore($before);$plan=$this->plan($before);
        abort_if(request()->wantsJson(),501,'مزامنة نسخ المنيو تدعم نموذج الداشبورد الأصلي.');
        $this->images()->allowProduct($this->id($before['target']['row']['id']),null);
        foreach($before['sources'] as $source)$this->images()->allowProduct($this->id($source['branch']['id']),$this->id($source['row']['id']));
        $replay=$this->prepareReplay($before,$plan);$copyPlan=[];
        foreach($plan as $entry)if($entry['image'])$copyPlan[]=['selection'=>$entry['selection'],'source_product'=>$entry['source_product'],'image'=>$entry['image'],'replay'=>$replay[$entry['selection']]??null];
        $this->images()->configureCopies($payload['values']['idempotency_key'],$copyPlan);$this->registerCapture();abort_unless(self::$created===null,409);self::$created=[];
        $request=request();$body=$request->request->all();$query=$request->query->all();$files=$request->files->all();
        try{
            $values=$payload['values'];unset($values['idempotency_key']);if(isset($body['_token']))$values['_token']=$body['_token'];$request->request->replace($values);$request->query->replace([]);$request->files->replace([]);
            $response=$work();$this->images()->assertCopiesComplete();$status=$response->getStatusCode();
            if($status>=400||$request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            abort_unless(in_array($status,[302,303],true)&&strlen($response->getContent())<=1024*1024,409);$parts=parse_url($response->headers->get('Location')??'');abort_unless(is_array($parts),409);$this->support()->menuCopySameOrigin($parts);
            $return=$this->support()->menuCopyReturnQuery($payload['facts']['restaurant_menu_copy_return']);$location='/admin/resturants/'.$this->id($before['target']['row']['id']);
            abort_unless(($parts['path']??'')===$location&&($parts['query']??'')===$return,409,'رابط العودة خارج صفحة فرع المنيو.');if($return!=='')$location.='?'.$return;
            $products=[];$images=[];$steps=[];$new=0;
            foreach($before['products'] as $state)$products[]=$this->support()->menuCopyState($this->id($state['row']['id']));
            foreach($plan as $entry){$id=$entry['existing'];if($id===null){$id=$this->id(self::$created[$new]??null);$new++;$products[]=$this->support()->menuCopyState($id);}
                $media=$entry['image']?$this->support()->menuCopyState($id)['media']:[];abort_unless(!$entry['image']||count($media)===1,409);
                $steps[]=['selection'=>$entry['selection'],'source_product'=>$entry['source_product'],'target_product'=>$id,'mode'=>$entry['existing']===null?'create':'price',
                    'source_media'=>$entry['image']?$this->id($entry['image']['row']['id']):null,'new_media'=>$entry['image']?$this->id($media[0]['id']):null];
            }
            abort_unless(count(self::$created)===$new,409,'عدد الأصناف المنسوخة مختلف عن الاختيارات.');
            foreach($products as $state)foreach($this->images()->facts($this->id($state['row']['id'])) as $image)$images[]=$image;
            $actualMembership=$this->membership($this->id($before['target']['row']['id']));$acceptedMembership=array_map(fn($state)=>$this->id($state['row']['id']),$products);sort($actualMembership);sort($acceptedMembership);
            abort_unless($actualMembership===$acceptedMembership,409,'عضوية المنيو تغيّرت خارج الاختيارات.');
            foreach($before['sources'] as $i=>$source){abort_unless($this->same($source,$this->support()->menuCopyState($this->id($source['row']['id']))),409,'النسخ غيّر صنفًا من المطعم الرئيسي.');$this->images()->verifyFacts($before['source_images'][$i]);}
            $completed=self::$replay['result']['after']['completed_at']??now('UTC')->toIso8601String();$start=$this->utc($started);$end=$this->utc($completed);
            abort_unless($end->gte($start)&&$end->diffInSeconds($start)<=600,409,'نسخ المنيو تجاوز فترة المعاينة؛ انسخ مجموعة أصغر.');
            $after=['target'=>$this->target($this->id($before['target']['row']['id'])),'products'=>$products,'images'=>$images,'steps'=>$steps,'started_at'=>$started,'completed_at'=>$completed];
            $this->assertAfter($before,$plan,$after);$result=['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
                'success'=>session('success'),'after'=>$after,'references'=>$this->resultReferences($after)];$this->verifyResult($result);return $result;
        }finally{$request->request->replace($body);$request->query->replace($query);$request->files->replace($files);$this->restoreTimestampModels();self::$created=null;self::$createTimes=[];self::$updateTimes=[];}
    }
    private function resultReferences(array $after): array
    {
        $references=['menu_restaurant'=>$this->id($after['target']['row']['id'])];
        foreach($after['steps'] as $step){$i=$step['selection'];$references['menu_restaurant_product.'.$i]=$this->id($step['target_product']);
            if($step['new_media']!==null)$references['menu_product_media.'.$i]=$this->id($step['new_media']);}return $references;
    }
    private function imageGroups(array $images): array
    {
        abort_unless(array_is_list($images)&&count($images)<=2000,409);$groups=[];$seen=[];$files=0;$bytes=0;
        foreach($images as $image){abort_unless(is_array($image)&&is_array($image['row']??null)&&is_array($image['files']??null),409);$id=$this->id($image['row']['id']);abort_if(isset($seen[$id]),409);$seen[$id]=true;
            $groups[$this->id($image['row']['model_id'])][]=$image;$files+=count($image['files']);foreach($image['files'] as $file)$bytes+=(int)($file['bytes']??0);}
        abort_if($files>2000||$bytes>306*1024*1024,413);return $groups;
    }
    public function outputs(array $result): array
    {
        if(!isset($result['http']))return [];$after=$result['after']??null;abort_unless(is_array($after)&&array_keys($after)===['target','products','images','steps','started_at','completed_at'],409);$this->validateTarget($after['target']);
        abort_unless(is_array($after['products'])&&array_is_list($after['products'])&&count($after['products'])<=self::MAX_TARGET+self::MAX_SELECTED
            &&is_array($after['steps'])&&array_is_list($after['steps'])&&count($after['steps'])>0&&count($after['steps'])<=self::MAX_SELECTED&&is_array($after['images']),409);
        $products=$this->byProduct($after['products']);$media=[];
        foreach($products as $state){$this->support()->menuCopyValidateState($state);abort_unless($this->id($state['branch']['id'])===$this->id($after['target']['row']['id']),409);
            foreach($state['media'] as $row){$id=$this->id($row['id']);abort_if(isset($media[$id]),409);$media[$id]=$row;}}
        $proof=[];foreach($this->imageGroups($after['images']) as $group){$this->images()->validateFacts($group);foreach($group as $image)$proof[$this->id($image['row']['id'])]=$image['row'];}
        ksort($proof);ksort($media);abort_unless($proof===$media,409,'صور نتيجة المنيو لا تطابق صفوفها الكاملة.');$created=[];$copied=[];
        foreach($after['steps'] as $i=>$step){abort_unless(is_array($step)&&array_keys($step)===['selection','source_product','target_product','mode','source_media','new_media']&&$step['selection']===$i
                &&in_array($step['mode'],['create','price'],true)&&isset($products[$this->id($step['target_product'])]),409);$this->id($step['source_product']);
            if($step['mode']==='create'){abort_if(isset($created[$step['target_product']]),409);$created[$step['target_product']]=true;}
            if($step['new_media']===null)abort_unless($step['source_media']===null,409);
            else {$this->id($step['source_media']);$id=$this->id($step['new_media']);abort_unless($step['mode']==='create'&&!isset($copied[$id])&&isset($media[$id])
                    &&$this->id($media[$id]['model_id'])===$this->id($step['target_product'])&&$media[$id]['collection_name']==='product_image',409);$copied[$id]=true;}
        }
        $start=$this->utc($after['started_at']);$end=$this->utc($after['completed_at']);abort_unless($end->gte($start)&&$end->diffInSeconds($start)<=600,409);
        $expected=$this->resultReferences($after);abort_unless(($result['references']??null)===$expected,409,'مراجع نتيجة نسخ المنيو غير متطابقة.');return $expected;
    }
    public function verifyResult(array $result): void
    {
        if(isset($result['result'])&&!isset($result['http']))$result=$result['result'];$this->outputs($result);abort_unless(isset($result['after']['images']),503);
        foreach($this->imageGroups($result['after']['images']) as $group)$this->images()->verifyFacts($group,503);
        $branch=$this->id($result['after']['target']['row']['id']);
        foreach($result['after']['products'] as $state){$current=DB::table('resturant_products')->where('id',$state['row']['id'])->first(['resturant_id']);
            abort_if($current&&(int)$current->resturant_id!==$branch,403,'صنف نتيجة المنيو انتقل خارج فرعه المحفوظ.');}
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);if($http['type'])$response->headers->set('Content-Type',$http['type']);
        if($http['location'])$response->headers->set('Location',url($http['location']));if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    private function localPayload($value)
    {
        if(!is_array($value))return $value;if(isset($value['$desktop_ref']))return $this->localId($value);
        foreach($value as $key=>$part)$value[$key]=$this->localPayload($part);return $value;
    }
    /** Each timestamp belongs to the complete recorded batch window; price-only no-ops retain old times. */
    public function replayContext(string $name,array $local,array $originalPayload,Carbon $occurredAt,callable $work)
    {
        abort_unless(self::handles($name)&&self::$replay===null,409);$this->outputs($local);$raw=$this->localPayload($originalPayload);$before=$this->facts($name,$raw);$plan=$this->plan($before);
        $start=$this->utc($local['after']['started_at']);$end=$this->utc($local['after']['completed_at']);abort_unless(abs($start->getTimestamp()-$occurredAt->getTimestamp())<=5,409,'بداية نسخ المنيو تختلف عن وقت العملية.');
        $this->assertAfter($before,$plan,$local['after']);$states=$this->byProduct($local['after']['products']);$maximum=0;
        foreach(['products','sources'] as $key)foreach($before[$key] as $state)foreach($state['media'] as $row)$maximum=max($maximum,(int)($row['order_column']??0));
        foreach($plan as $i=>$entry){$step=$local['after']['steps'][$i];abort_unless($step['selection']===$i&&$this->id($step['source_product'])===$entry['source_product']&&$step['mode']===($entry['existing']===null?'create':'price'),409);
            if($entry['existing']!==null)abort_unless($this->id($step['target_product'])===$entry['existing'],409);
            $state=$states[$this->id($step['target_product'])];
            if($entry['existing']===null){$this->sourceTime($state['row']['created_at'],$start,$end);$this->sourceTime($state['row']['updated_at'],$start,$end);}
            if($entry['image']){
                abort_unless($step['source_media']===$entry['image']['row']['id']&&count($state['media'])===1,409);$row=$state['media'][0];$order=$row['order_column'];
                abort_unless(is_int($order)&&$order>$maximum&&$row['created_at']===$row['updated_at'],409,'ترتيب صورة المنيو الجديدة غير مطابق لترتيب العملية.');$maximum=$order;
                Validator::make($row,['uuid'=>'required|uuid'])->validate();$this->sourceTime($row['created_at'],$start,$end);$this->sourceTime($row['updated_at'],$start,$end);
            }
        }
        $clock=Carbon::getTestNow();self::$replay=['result'=>$local,'payload'=>$originalPayload];
        try{Carbon::setTestNow($start);return $work();}finally{$this->restoreTimestampModels();self::$replay=null;self::$createTimes=[];self::$updateTimes=[];Carbon::setTestNow($clock);}
    }
    private function mapState(array $state,array $mapping): array
    {
        $map=function($value,string $entity)use($mapping){return $value===null?$value:($mapping[$entity][$this->id($value)]??$value);};
        foreach(self::RELATIONS as $path=>$entity){$value=data_get($state,$path);if($value!==null)data_set($state,$path,$map($value,$entity));}
        foreach($state['features'] as $i=>$row)foreach(['id'=>'catalog_feature','product_id'=>'catalog_product','added_by'=>'restaurant_flag_user'] as $field=>$entity)$state['features'][$i][$field]=$map($row[$field],$entity);
        foreach($state['media'] as $i=>$row)foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$state['media'][$i][$field]=$map($row[$field],$entity);return $state;
    }
    public function verifyAfter(array $server,array $local,array $resolvedPayload,array $originalPayload): void
    {
        $serverRefs=$this->outputs($server);$localRefs=$this->outputs($local);abort_unless(array_keys($serverRefs)===array_keys($localRefs),409);
        $raw=$this->localPayload($originalPayload);$this->assertAfter($this->facts(self::ROUTE,$raw),$this->plan($raw['facts']['restaurant_menu_copy_before']),$local['after']);
        $mapping=[];
        foreach($this->referencePaths($originalPayload) as $path=>$entity){$old=data_get($raw,$path);$new=data_get($resolvedPayload,$path);if($old===null)continue;$old=$this->id($old);$new=$this->id($new);
            abort_if(isset($mapping[$entity][$old])&&$mapping[$entity][$old]!==$new,409);$mapping[$entity][$old]=$new;}
        foreach($localRefs as $entity=>$old){$base=explode('.',$entity)[0];$new=$this->id($serverRefs[$entity]);$old=$this->id($old);
            abort_if(isset($mapping[$base][$old])&&$mapping[$base][$old]!==$new,409,'هوية نتيجة نسخ المنيو تختلف عن مرجعها.');$mapping[$base][$old]=$new;}
        $translated=$local['after'];
        foreach(self::TARGET_REFS as $path=>$entity){$old=data_get($translated['target'],$path);if($old!==null)data_set($translated['target'],$path,$mapping[$entity][$this->id($old)]??$old);}
        foreach($translated['products'] as $i=>$state)$translated['products'][$i]=$this->mapState($state,$mapping);
        foreach($translated['images'] as $i=>$image){$translated['images'][$i]['row']['id']=$mapping['menu_product_media'][$this->id($image['row']['id'])]??$image['row']['id'];
            $translated['images'][$i]['row']['model_id']=$mapping['menu_restaurant_product'][$this->id($image['row']['model_id'])]??$image['row']['model_id'];}$translated['images']=$this->rebaseImages($translated['images']);
        foreach($translated['steps'] as $i=>$step){
            foreach(['source_product'=>'menu_restaurant_product','target_product'=>'menu_restaurant_product','source_media'=>'menu_product_media','new_media'=>'menu_product_media'] as $field=>$entity)
                if($step[$field]!==null)$translated['steps'][$i][$field]=$mapping[$entity][$this->id($step[$field])]??$step[$field];
        }
        abort_unless($this->same($server['after'],$translated),409,'نتيجة نسخ المنيو أو الصور تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);$original=$this->original($name);$values=$this->values($payload['values']);unset($values['idempotency_key']);$request=Request::create(url('/admin/resturant/copy/items'),'POST',$values);
        $query=$this->support()->menuCopyReturnQuery($payload['facts']['restaurant_menu_copy_return']);$request->headers->set('Referer',url('/admin/resturants/'.$this->id($before['target']['row']['id'])).($query!==''?'?'.$query:''));$request->headers->set('Accept','text/html');
        $session=new Store('desktop-menu-copy-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
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
