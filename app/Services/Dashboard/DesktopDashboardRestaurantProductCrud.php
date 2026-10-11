<?php
namespace App\Services\Dashboard;

use App\Models\{ResturantProduct,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Bounded journal adapters around the original multipart restaurant-product forms. */
class DesktopDashboardRestaurantProductCrud
{
    public const ROUTES=[
        'resturant_products.store'=>['action'=>'resturantProductsCreate','method'=>'POST','uri'=>'admin/resturant_products'],
        'resturant_products.update'=>['action'=>'resturantProductsUpdate','method'=>'PUT','uri'=>'admin/resturant_products/update'],
        'resturant_products.destroy'=>['action'=>'resturantProductsDelete','method'=>'DELETE','uri'=>'admin/resturant_products/{resturant}'],
    ];
    private const COLUMNS=[
        'row'=>['id','added_by','resturant_id','product_id','price','product_name','product_description','product_price','created_at','updated_at','category_id','status','highest_rated'],
        'branch'=>['id','user_id','parent_id','added_by'],'added_by'=>['id'],'owner'=>['id'],'parent'=>['id','user_id','parent_id'],
        'category'=>['id','added_by','parent_id','name_ar','name_en','status','created_at','updated_at','order'],
        'catalog'=>['id','added_by','category_id','subcategory_id','name_ar','status','created_at','updated_at','has_clean','name_en'],
        'feature'=>['id','added_by','product_id','name','created_at','updated_at'],
        'media'=>['id','model_type','model_id','uuid','collection_name','name','file_name','mime_type','disk','conversions_disk','size','manipulations','custom_properties','generated_conversions','responsive_images','order_column','created_at','updated_at'],
    ];
    private const RELATIONS=[
        'branch.id'=>'menu_restaurant','branch.user_id'=>'restaurant_flag_user','branch.parent_id'=>'menu_restaurant','branch.added_by'=>'restaurant_flag_user',
        'added_by.id'=>'restaurant_flag_user','owner.id'=>'restaurant_flag_user','parent.id'=>'menu_restaurant','parent.user_id'=>'restaurant_flag_user','parent.parent_id'=>'menu_restaurant',
        'category.id'=>'catalog_category','category.added_by'=>'restaurant_flag_user','category.parent_id'=>'catalog_category',
        'catalog.id'=>'catalog_product','catalog.added_by'=>'restaurant_flag_user','catalog.category_id'=>'catalog_category','catalog.subcategory_id'=>'catalog_category',
    ];
    private const EXTRAS=['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'];
    private static bool $listening=false;
    private static ?array $created=null;

    public static function handles(?string $name): bool {return isset(self::ROUTES[$name??'']);}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$d=self::ROUTES[$name];$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\ResturantController@'.$d['action']
            &&$route->uri()===$d['uri']&&$route->methods()===[$d['method']]&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار نموذج صنف المطعم الأصلي تغيّر.');
        return $route;
    }
    private function id($value): int
    {
        abort_unless((is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)
            &&(int)$value>0&&(string)(int)$value===(string)$value,422);return (int)$value;
    }
    public function command(Request $request): string
    {
        abort_unless(in_array($request->getMethod(),['POST','PUT','DELETE'],true),405);
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409,'رقم طلب العملية مختلف عن سجلها.');
        $command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(string $name,array $values): array
    {
        abort_unless(self::handles($name),422);$allowed=['idempotency_key'];
        if($name!=='resturant_products.destroy')$allowed=array_merge($allowed,['added_by','resturant_id','category_id','product_id','product_name','product_price','product_description','subcategory_id'],self::EXTRAS);
        if($name==='resturant_products.update')$allowed=array_merge($allowed,['id','subcatid']);
        abort_if(array_diff(array_keys($values),$allowed),422,'حقول نموذج الصنف غير مدعومة للمزامنة.');
        foreach($values as $value)abort_unless($value===null||is_string($value)||is_int($value)||is_float($value),422);
        Validator::make($values,['idempotency_key'=>'required|uuid'])->validate();
        if($name!=='resturant_products.destroy'){
            foreach(['added_by','resturant_id','category_id','product_id'] as $key)$this->id($values[$key]??null);
            if($name==='resturant_products.update')$this->id($values['id']??null);
            // The actual FormRequest retains authority over content, nullable fields and optional uploads.
        }
        return $values;
    }
    private function shape(array $row,string $kind): void
    {
        $columns=self::COLUMNS[$kind];abort_unless(count($row)===count($columns)&&array_diff(array_keys($row),$columns)===[],409,'أعمدة الصنف أو علاقاته تحتاج مراجعة.');
        foreach($row as $value)abort_unless($value===null||is_scalar($value),409);
        foreach(['created_at','updated_at'] as $field)if(array_key_exists($field,$row))
            abort_unless($row[$field]===null||(is_string($row[$field])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$row[$field])),409);
    }
    private function target(array $values): array
    {
        $branch=DB::table('resturants')->where('id',$values['resturant_id'])->lockForUpdate()->first(self::COLUMNS['branch']);abort_unless($branch,409);$branch=(array)$branch;
        $state=['branch'=>$branch,'added_by'=>(array)DB::table('users')->where('id',$values['added_by'])->lockForUpdate()->first(['id']),
            'owner'=>(array)DB::table('users')->where('id',$branch['user_id'])->lockForUpdate()->first(['id']),
            'parent'=>isset($branch['parent_id'])?(array)DB::table('resturants')->where('id',$branch['parent_id'])->lockForUpdate()->first(self::COLUMNS['parent']):null,
            'category'=>isset($values['category_id'])?(array)DB::table('categories')->where('id',$values['category_id'])->lockForUpdate()->first():null,
            'catalog'=>isset($values['product_id'])?(array)DB::table('products')->where('id',$values['product_id'])->lockForUpdate()->first():null,
            'features'=>isset($values['product_id'])?DB::table('product_features')->where('product_id',$values['product_id'])->orderBy('id')->lockForUpdate()->get()->map(fn($item)=>(array)$item)->all():[]];
        $this->validateTarget($state);return $state;
    }
    private function validateTarget(array $state): void
    {
        abort_unless(count($state)===7&&array_diff(array_keys($state),['branch','added_by','owner','parent','category','catalog','features'])===[],409);
        foreach(['branch','added_by','owner','parent','category','catalog'] as $kind){
            if($state[$kind]===null&&in_array($kind,['parent','category','catalog'],true))continue;
            abort_unless(is_array($state[$kind]),409);$this->shape($state[$kind],$kind);
        }
        $branch=$state['branch'];$this->id($branch['id']);$this->id($state['added_by']['id']);
        abort_unless($this->id($branch['user_id'])===$this->id($state['owner']['id']),409);
        abort_unless(isset($branch['parent_id'])?is_array($state['parent'])&&$this->id($branch['parent_id'])===$this->id($state['parent']['id']):$state['parent']===null,409);
        abort_unless(is_array($state['features'])&&array_is_list($state['features'])&&count($state['features'])<=1000,409);$seen=[];
        foreach($state['features'] as $feature){abort_unless(is_array($feature),409);$this->shape($feature,'feature');$id=$this->id($feature['id']);abort_if(isset($seen[$id]),409);$seen[$id]=true;
            abort_unless($state['catalog']!==null&&$this->id($feature['product_id'])===$this->id($state['catalog']['id']),409);}
    }
    private function media(int $id): array
    {
        $rows=DB::table('media')->where('model_type',ResturantProduct::class)->where('model_id',$id)->orderBy('id')->lockForUpdate()->get()->map(fn($row)=>(array)$row)->all();
        $this->validateMedia($rows,$id);return $rows;
    }
    private function validateMedia(array $rows,int $product): void
    {
        abort_unless(array_is_list($rows)&&count($rows)<=50,409);$seen=[];
        foreach($rows as $row){abort_unless(is_array($row),409);$this->shape($row,'media');$id=$this->id($row['id']);abort_if(isset($seen[$id]),409);$seen[$id]=true;
            abort_unless($row['model_type']===ResturantProduct::class&&$this->id($row['model_id'])===$product,409);}
    }
    private function state(int $id,bool $replay=false): array
    {
        $row=DB::table('resturant_products')->where('id',$id)->lockForUpdate()->first();abort_unless($row,$replay?409:404);$row=(array)$row;$this->shape($row,'row');
        $relations=$this->target(['resturant_id'=>$row['resturant_id'],'added_by'=>$row['added_by'],'category_id'=>$row['category_id'],'product_id'=>$row['product_id']]);
        $state=array_merge(['row'=>$row],$relations,['media'=>$this->media($id)]);$this->validateState($state);return $state;
    }
    private function validateState(array $state): void
    {
        abort_unless(count($state)===9&&array_diff(array_keys($state),['row','branch','added_by','owner','parent','category','catalog','features','media'])===[],409);
        abort_unless(is_array($state['row']),409);$this->shape($state['row'],'row');$target=$state;unset($target['row'],$target['media']);$this->validateTarget($target);
        $row=$state['row'];abort_unless($this->id($row['resturant_id'])===$this->id($state['branch']['id'])&&$this->id($row['added_by'])===$this->id($state['added_by']['id']),409);
        foreach(['category_id'=>'category','product_id'=>'catalog'] as $field=>$kind)abort_unless(isset($row[$field])?is_array($state[$kind])&&$this->id($row[$field])===$this->id($state[$kind]['id']):$state[$kind]===null,409);
        $this->validateMedia($state['media'],$this->id($row['id']));
    }
    private function deleteRules(): array
    {
        $rows=DB::select('SELECT k.TABLE_NAME AS table_name,k.COLUMN_NAME AS column_name,r.DELETE_RULE AS delete_rule,r.UPDATE_RULE AS update_rule FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME=? ORDER BY k.TABLE_NAME,k.COLUMN_NAME',['resturant_products']);
        $rules=array_map(fn($row)=>['table'=>$row->table_name,'column'=>$row->column_name,'delete_rule'=>$row->delete_rule,'update_rule'=>$row->update_rule],$rows);$this->validateRules($rules);return $rules;
    }
    private function validateRules(array $rules): void
    {
        abort_unless(array_is_list($rules)&&count($rules)<=1,409,'علاقات حذف الصنف تحتاج مراجعة قبل المزامنة.');
        foreach($rules as $rule)abort_unless(is_array($rule)&&array_keys($rule)===['table','column','delete_rule','update_rule']&&$rule['table']==='carts'&&$rule['column']==='resturant_product_id'
            &&in_array($rule['delete_rule'],['RESTRICT','NO ACTION'],true)&&in_array($rule['update_rule'],['RESTRICT','NO ACTION','CASCADE'],true),409,'الحذف المتسلسل أو تغيير روابط الصنف غير مدعوم للمزامنة.');
    }
    private function facts(string $name,array $payload): array
    {
        abort_unless(array_keys($payload)===['parameters','values','files','facts']&&is_array($payload['parameters'])&&is_array($payload['values'])&&is_array($payload['files'])&&is_array($payload['facts']),422);
        $values=$this->values($name,$payload['values']);app(DesktopDashboardProductImages::class)->decoded($payload['files']);
        abort_unless(array_keys($payload['facts'])===['restaurant_product_crud_before','restaurant_product_crud_return'],409);
        $before=$payload['facts']['restaurant_product_crud_before'];abort_unless(is_array($before)&&array_keys($before)===['product','target','images','delete_rules'],409);
        if($name==='resturant_products.store')abort_unless($before['product']===null&&$payload['parameters']===[],409);
        else {abort_unless(is_array($before['product']),409);$this->validateState($before['product']);
            if($name==='resturant_products.destroy')abort_unless(array_keys($payload['parameters'])===['resturant']&&$this->id($payload['parameters']['resturant'])===$this->id($before['product']['row']['id']),409);
            else abort_unless($payload['parameters']===[]&&$this->id($values['id'])===$this->id($before['product']['row']['id']),409);}
        if($name==='resturant_products.destroy')abort_unless($before['target']===null&&$payload['files']===[],409);
        else {abort_unless(is_array($before['target']),409);$this->validateTarget($before['target']);
            foreach(['resturant_id'=>'branch','added_by'=>'added_by','category_id'=>'category','product_id'=>'catalog'] as $field=>$kind)
                abort_unless($this->id($values[$field])===$this->id($before['target'][$kind]['id']),409);}
        abort_unless(is_array($before['images'])&&is_array($before['delete_rules']),409);$before['images']=$this->rebaseImages($before['images']);app(DesktopDashboardProductImages::class)->validateFacts($before['images']);
        abort_unless(array_column($before['images'],'row')===($before['product']['media']??[]),409,'حقائق الصور تختلف عن صفوف الميديا المحفوظة.');
        if($name==='resturant_products.destroy')$this->validateRules($before['delete_rules']);else abort_unless($before['delete_rules']===[],409);
        $this->returnQuery($payload['facts']['restaurant_product_crud_return']);return $before;
    }
    private function transactionalTables(): void
    {
        abort_unless(DB::connection()->getDriverName()==='mysql'&&DB::connection()->getTablePrefix()==='',409,'مزامنة أصناف المطعم تتطلب قاعدة MySQL بمعاملات InnoDB.');
        $required=['resturant_products','media','resturants','users','products','categories','product_features','carts',
            'desktop_dashboard_commands','desktop_dashboard_devices','desktop_dashboard_entities'];
        $permission=config('permission.table_names',[]);abort_unless(is_array($permission),409);
        foreach(['roles','permissions','model_has_roles','model_has_permissions','role_has_permissions'] as $key){
            $table=$permission[$key]??null;abort_unless(is_string($table)&&preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$table),409);$required[]=$table;
        }
        $optional=['desktop_dashboard_local_state','desktop_dashboard_refreshes','desktop_dashboard_remote_attempts','desktop_dashboard_archived_commands',
            'desktop_dashboard_source_manifest','desktop_dashboard_media_manifest'];
        $tables=array_values(array_unique(array_merge($required,$optional)));
        $rows=DB::select('SELECT TABLE_NAME AS table_name,ENGINE AS engine FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')',$tables);
        $found=[];foreach($rows as $row){$found[$row->table_name]=true;abort_unless(strcasecmp((string)$row->engine,'InnoDB')===0,409,'جدول الصنف أو سجله لا يدعم التراجع الذري.');}
        abort_unless(array_diff($required,array_keys($found))===[],409,'جداول صلاحيات الصنف أو سجله غير مكتملة.');
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $this->transactionalTables();$before=$this->facts($name,$payload);$original=$this->original($name);$fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner','delegate'],true)&&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);$branches=[];
        foreach([$before['product']['branch']??null,$before['target']['branch']??null] as $branch)if($branch)$branches[$this->id($branch['id'])]=$branch;
        foreach($branches as $id=>$old){
            abort_unless(in_array('f:'.$id,json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR),true),403,'الفرع خارج نطاق ربط الجهاز.');
            $branch=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($branch,409);
            $allowed=match($fresh->account_type){'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,
                'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$branch->parent_id===(int)$fresh->owner_resturant_id),
                'vendor'=>(int)$branch->user_id===(int)$fresh->id,default=>false};abort_unless($allowed,403,'حساب الجهاز لم يعد يملك نطاق فرع الصنف.');
        }
        if($before['product']){
            $current=DB::table('resturant_products')->where('id',$before['product']['row']['id'])->lockForUpdate()->first(['resturant_id']);
            // A receipt can outlive deletion, but cannot transfer authority to an unrelated branch.
            abort_if($current&&!isset($branches[(int)$current->resturant_id]),403,'الصنف انتقل إلى فرع خارج نطاق العملية.');
        }
        $guard=auth('admin');$previous=$guard->getUser();$router=app('router');
        try{
            $guard->setUser($fresh);$probe=Request::create(url($this->uri($name,$payload)),self::ROUTES[$name]['method']);
            if(request()->hasSession())$probe->setLaravelSession(request()->session());$route=clone $original;$route->flushController();$route->bind($probe);$probe->setRouteResolver(fn()=>$route);
            $probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            (new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
        return $fresh;
    }
    private function uri(string $name,array $payload): string
    {
        return str_replace('{resturant}',(string)($payload['parameters']['resturant']??''),self::ROUTES[$name]['uri']);
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
        $this->original($name);abort_unless($request->getMethod()===self::ROUTES[$name]['method'],405);
        $parameters=$request->route()->parameters();$id=null;
        if($name==='resturant_products.destroy'){
            abort_unless(array_keys($parameters)===['resturant'],422);$parameter=$parameters['resturant'];$id=$this->id($parameter instanceof ResturantProduct?$parameter->getKey():$parameter);$parameters=['resturant'=>$id];
        }else abort_unless($parameters===[],422);
        // Establish the effective method before dropping Laravel's transport override. The original
        // controller mass-assigns except('_token'), so no desktop/transport control may reach it.
        $values=$request->except('_token','_method','_desktop_command','product_image');$values['idempotency_key']=$command;$values=$this->values($name,$values);
        $files=app(DesktopDashboardProductImages::class)->files($request);if($name==='resturant_products.destroy')abort_unless($files===[],422);
        if($name==='resturant_products.update')$id=$this->id($values['id']);
        if($saved===null){
            $product=$id?$this->state($id):null;$target=$name==='resturant_products.destroy'?null:$this->target($values);
            $branch=$this->id(($product??$target)['branch']['id']);
            $saved=['restaurant_product_crud_before'=>['product'=>$product,'target'=>$target,'images'=>$id?app(DesktopDashboardProductImages::class)->facts($id):[],
                'delete_rules'=>$name==='resturant_products.destroy'?$this->deleteRules():[]],
                'restaurant_product_crud_return'=>$this->returnContext($request,$branch)];
        }
        return ['parameters'=>$parameters,'values'=>$values,'files'=>$files,'facts'=>$saved];
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {
        $saved=app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name);
        $payload=$this->requestPayload($name,$request,$command,$saved);$scope=app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $device,?array $saved=null): array
    {
        $payload=$this->requestPayload($name,$request,$command,$saved);$this->authorize($name,$payload,$actor,$device);return $payload;
    }
    private function referencePaths(array $payload): array
    {
        $paths=['parameters.resturant'=>'menu_restaurant_product','values.id'=>'menu_restaurant_product','values.resturant_id'=>'menu_restaurant',
            'values.added_by'=>'restaurant_flag_user','values.category_id'=>'catalog_category','values.product_id'=>'catalog_product',
            'facts.restaurant_product_crud_return.filter_restaurant_id'=>'menu_restaurant'];
        $base='facts.restaurant_product_crud_before.';
        foreach(['product','target'] as $kind){
            if(!is_array($payload['facts']['restaurant_product_crud_before'][$kind]??null))continue;
            foreach(self::RELATIONS as $suffix=>$entity)$paths[$base.$kind.'.'.$suffix]=$entity;
            if($kind==='product')foreach(['id'=>'menu_restaurant_product','resturant_id'=>'menu_restaurant','added_by'=>'restaurant_flag_user','product_id'=>'catalog_product','category_id'=>'catalog_category'] as $field=>$entity)$paths[$base.'product.row.'.$field]=$entity;
            foreach($payload['facts']['restaurant_product_crud_before'][$kind]['features']??[] as $i=>$feature)
                foreach(['id'=>'catalog_feature','product_id'=>'catalog_product','added_by'=>'restaurant_flag_user'] as $field=>$entity)$paths[$base.$kind.'.features.'.$i.'.'.$field]=$entity;
        }
        foreach($payload['facts']['restaurant_product_crud_before']['product']['media']??[] as $i=>$media)
            foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$paths[$base.'product.media.'.$i.'.'.$field]=$entity;
        foreach($payload['facts']['restaurant_product_crud_before']['images']??[] as $i=>$image)
            foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$paths[$base.'images.'.$i.'.row.'.$field]=$entity;
        return $paths;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($this->referencePaths($payload) as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value)&&$value!=='')data_set($payload,$path,$reference($entity,$value));}return $payload;
    }
    public function validateReferences(array $payload): void
    {
        abort_unless(is_array($payload['facts']['restaurant_product_crud_before']??null),422);$before=$payload['facts']['restaurant_product_crud_before'];
        foreach(['product','target'] as $kind)if(($before[$kind]??null)!==null){abort_unless(is_array($before[$kind]),422);
            foreach(['features','media'] as $key)if(isset($before[$kind][$key]))abort_unless(is_array($before[$kind][$key])&&array_is_list($before[$kind][$key])&&count($before[$kind][$key])<=1000,422);}
        abort_unless(is_array($before['images']??null)&&array_is_list($before['images'])&&count($before['images'])<=50,422);
        foreach($before['images'] as $image){
            abort_unless(is_array($image)&&is_array($image['row']??null)&&is_array($image['files']??null),422);$local=$this->localId($image['row']['id']??null);
            foreach($image['files'] as $file)abort_unless(is_array($file)&&is_string($file['relative']??null)
                &&str_starts_with($file['relative'],$local.'/')&&($file['path']??null)==='products/'.$file['relative']&&DesktopDashboardMedia::safePath($file['path']),422);
        }
        $paths=$this->referencePaths($payload);$walk=function($value,string $path='')use(&$walk,$paths){
            if(!is_array($value))return;
            if(array_key_exists('$desktop_ref',$value)){
                $ref=$value['$desktop_ref'];$expected=$paths[$path]??null;
                abort_unless($expected&&count($value)===1&&is_array($ref)&&count($ref)===3&&array_diff(array_keys($ref),['entity','command_id','local_id'])===[]
                    &&is_string($ref['entity']??null)&&($ref['entity']===$expected||preg_match('/^'.preg_quote($expected,'/').'\.[0-9]+$/D',$ref['entity'])),422,'نوع مرجع الصنف غير صحيح.');
                $this->id($ref['local_id']);Validator::make($ref,['command_id'=>'required|uuid'])->validate();return;
            }
            foreach($value as $key=>$part)$walk($part,$path===''?(string)$key:$path.'.'.$key);
        };$walk($payload);
    }
    private function fingerprint($value): string {return app(DesktopDashboardJournal::class)->fingerprint($value);}
    private function comparable($value)
    {
        if(!is_array($value))return $value;
        foreach($value as $key=>$part)$value[$key]=$this->comparable($part);
        if(array_is_list($value)&&$value&&is_array($value[0])){
            if(isset($value[0]['id']))usort($value,fn($a,$b)=>$a['id']<=>$b['id']);
            elseif(isset($value[0]['row']['id']))usort($value,fn($a,$b)=>$a['row']['id']<=>$b['row']['id']);
            elseif(isset($value[0]['relative']))usort($value,fn($a,$b)=>strcmp($a['relative'],$b['relative']));
        }return $value;
    }
    private function equal($a,$b): bool {return hash_equals($this->fingerprint($this->comparable($a)),$this->fingerprint($this->comparable($b)));}
    private function returnBranch(array $before): int {return $this->id(($before['product']??$before['target'])['branch']['id']);}
    private function expectedRow(array $values,?array $old): array
    {
        $row=$old??[];
        foreach(['added_by','resturant_id','category_id','product_id','product_name','product_price','product_description'] as $field)
            if(array_key_exists($field,$values))$row[$field]=$values[$field];
        foreach(['added_by','resturant_id','category_id','product_id'] as $field)$row[$field]=$this->id($row[$field]);
        // MySQL converts the original numeric form value to the deployed DECIMAL(8,2).
        $row['product_price']=number_format((float)$row['product_price'],2,'.','');
        $row['price']=json_encode(array_combine(self::EXTRAS,array_map(fn($key)=>$values[$key]??null,self::EXTRAS)));
        return $row;
    }
    private function rebaseImages(array $images): array
    {
        foreach($images as &$image){
            abort_unless(is_array($image)&&is_array($image['row']??null)&&is_array($image['files']??null),422);$id=$this->id($image['row']['id']);
            foreach($image['files'] as &$file){
                abort_unless(is_array($file)&&is_string($file['relative']??null)&&preg_match('#^([1-9][0-9]{0,18})/([^\x00]+)$#D',$file['relative'],$parts)
                    &&($file['path']??null)==='products/'.$file['relative']&&DesktopDashboardMedia::safePath($file['path']),422);
                $file['relative']=$id.'/'.$parts[2];$file['path']='products/'.$file['relative'];
            }unset($file);
        }unset($image);return $images;
    }
    private function assertBefore(string $name,array $payload,array $before): void
    {
        if($before['product'])abort_unless($this->equal($before['product'],$this->state($this->id($before['product']['row']['id']),true)),409,'الصنف أو علاقاته تغيّرت قبل تنفيذ العملية.');
        if($before['target'])abort_unless($this->equal($before['target'],$this->target($payload['values'])),409,'الكتالوج أو نطاق الفرع تغيّر قبل تنفيذ العملية.');
        app(DesktopDashboardProductImages::class)->verifyFacts($before['images']);
        if($name==='resturant_products.destroy')abort_unless($this->equal($before['delete_rules'],$this->deleteRules()),409,'علاقات حذف الصنف تغيّرت.');
    }
    public function capture(string $name,array $payload,callable $work): array
    {
        $before=$this->facts($name,$payload);$this->assertBefore($name,$payload,$before);$images=app(DesktopDashboardProductImages::class);
        $branch=$this->id(($before['target']??$before['product'])['branch']['id']);$id=$before['product']?$this->id($before['product']['row']['id']):null;
        $images->allowProduct($branch,$id);
        if($before['product']&&$this->id($before['product']['branch']['id'])!==$branch)$images->allowProduct($this->id($before['product']['branch']['id']),$id);
        $images->protectOld($before['images']);
        if(!self::$listening){
            app('events')->listen('eloquent.created: '.ResturantProduct::class,function($model){if(self::$created!==null)self::$created[]=(int)$model->getKey();});
            foreach(['creating','updating'] as $event)app('events')->listen('eloquent.'.$event.': '.ResturantProduct::class,function($model)use($event){
                if(self::$created===null||self::$replayProductTime===null)return;
                foreach(self::$replayProductTime as $field=>$time)if($field==='updated_at'||$event==='creating')$model->setAttribute($field,$time);
            });self::$listening=true;
        }
        abort_unless(self::$created===null,409);self::$created=[];
        $request=request();$oldBody=$request->request->all();$oldQuery=$request->query->all();$oldFiles=$request->files->all();
        try{
            if($payload['files'])$images->configureUpload($payload['values']['idempotency_key'],self::$replayMediaTime);
            $values=$payload['values'];unset($values['idempotency_key']);if(isset($oldBody['_token']))$values['_token']=$oldBody['_token'];
            $request->request->replace($values);$request->query->replace([]);
            $response=$images->withUploads($payload['files'],function(array $files)use($request,$work){$request->files->replace($files);return $work();});
            $status=$response->getStatusCode();
            if($status>=400||$request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            abort_unless(in_array($status,[302,303],true)&&strlen($response->getContent())<=1024*1024,409);
            $parts=parse_url($response->headers->get('Location')??'');abort_unless(is_array($parts),409);$this->sameOrigin($parts);$query=$this->returnQuery($payload['facts']['restaurant_product_crud_return']);
            $location='/admin/resturants/'.$this->returnBranch($before);abort_unless(($parts['path']??'')===$location&&($parts['query']??'')===$query,409,'رابط الرجوع خارج صفحة فرع الصنف.');if($query!=='')$location.='?'.$query;
            if($name==='resturant_products.store'){abort_unless(count(self::$created)===1,409);$id=$this->id(self::$created[0]);}
            else abort_unless(self::$created===[],409,'العملية أنشأت صنفًا إضافيًا.');
            $after=['product'=>$name==='resturant_products.destroy'?null:$this->state($id),
                'target'=>$name==='resturant_products.destroy'?array_diff_key($before['product'],array_flip(['row','media'])):$this->target($payload['values']),
                'media'=>$this->orderedMedia($this->media($id),$before['product']['media']??[]),'images'=>$images->facts($id),'delete_rules'=>$name==='resturant_products.destroy'?$this->deleteRules():[]];
            $after['images']=$this->orderedImages($after['images'],$after['media']);if($after['product'])$after['product']['media']=$after['media'];
            if($name==='resturant_products.destroy'){
                abort_if(DB::table('resturant_products')->where('id',$id)->exists(),409);
                abort_unless($this->equal($after['media'],$before['product']['media'])&&$this->equal($after['images'],$before['images'])&&$this->equal($after['delete_rules'],$before['delete_rules']),409,'الحذف غيّر صور الصنف أو علاقاته.');
            }else {
                abort_unless($this->equal($before['target'],$after['target']),409,'الحفظ غيّر بيانات الكتالوج أو ملكية الفرع.');
                $expected=$this->expectedRow($payload['values'],$before['product']['row']??null);$actual=$after['product']['row'];
                if($name==='resturant_products.store'){
                    foreach(['id','created_at','updated_at'] as $field)unset($actual[$field]);
                    $expected+=['price'=>null,'product_description'=>null,'status'=>null,'highest_rated'=>null];
                    // Source INSERT/database defaults determine the two nullable enum defaults. Their
                    // actual values remain complete receipt facts and must agree on replay.
                    unset($actual['status'],$actual['highest_rated'],$expected['status'],$expected['highest_rated']);
                }else unset($actual['updated_at'],$expected['updated_at']);
                abort_unless($this->equal($expected,$actual),409,'نتيجة الحفظ عدّلت حقولًا خارج نموذج الصنف.');
                $kept=$before['product']['media']??[];
                if($payload['files'])$kept=array_values(array_filter($kept,fn($row)=>$row['collection_name']!=='product_image'));
                $new=array_values(array_filter($after['media'],fn($row)=>!in_array($row['id'],array_column($kept,'id'),true)));
                $actualKept=array_values(array_filter($after['media'],fn($row)=>in_array($row['id'],array_column($kept,'id'),true)));
                abort_unless($this->equal($kept,$actualKept)&&count($new)===($payload['files']?1:0),409,'الحفظ غيّر مجموعة صور أخرى.');
                if($new)abort_unless($new[0]['collection_name']==='product_image',409);
            }
            $result=['http'=>['status'=>$status,'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],
                'success'=>session('success'),'after'=>$after,'references'=>$this->resultReferences($after)];
            $images->verifyResult($result,$payload['files']);return $result;
        }finally{$request->request->replace($oldBody);$request->query->replace($oldQuery);$request->files->replace($oldFiles);self::$created=null;}
    }
    private function resultReferences(array $after): array
    {
        $branch=$after['target']['branch']['id']??null;
        $refs=[];if($branch!==null)$refs['menu_restaurant']=$this->id($branch);
        if($after['product'])$refs['menu_restaurant_product']=$this->id($after['product']['row']['id']);
        foreach($after['media'] as $index=>$row)$refs['menu_product_media.'.$index]=$this->id($row['id']);return $refs;
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);if($http['type'])$response->headers->set('Content-Type',$http['type']);
        if($http['location'])$response->headers->set('Location',url($http['location']));if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    public function outputs(array $result): array
    {
        if(!isset($result['http']))return [];$after=$result['after']??null;abort_unless(is_array($after)&&array_keys($after)===['product','target','media','images','delete_rules'],409);
        if($after['product']!==null){abort_unless(is_array($after['product'])&&is_array($after['target']),409);$this->validateState($after['product']);$this->validateTarget($after['target']);
            abort_unless($this->equal($after['product']['media'],$after['media']),409);
            $target=$after['product'];unset($target['row'],$target['media']);abort_unless($this->equal($target,$after['target']),409);
        }else {abort_unless(is_array($after['target']),409);$this->validateTarget($after['target']);if($after['media'])$this->validateMedia($after['media'],$this->id($after['media'][0]['model_id']));}
        app(DesktopDashboardProductImages::class)->validateFacts($after['images']);abort_unless(array_column($after['images'],'row')===$after['media'],409);
        $this->validateRules($after['delete_rules']);$refs=$this->resultReferences($after);abort_unless(($result['references']??null)===$refs,409,'مراجع نتيجة الصنف غير متطابقة.');return $refs;
    }
    private function orderedMedia(array $rows,array $before): array
    {
        $byId=[];foreach($rows as $row)$byId[$this->id($row['id'])]=$row;$ordered=[];
        foreach($before as $row){$id=$this->id($row['id']);if(isset($byId[$id])){$ordered[]=$byId[$id];unset($byId[$id]);}}
        foreach($byId as $row)$ordered[]=$row;return $ordered;
    }
    private function orderedImages(array $images,array $media): array
    {
        $byId=[];foreach($images as $image)$byId[$this->id($image['row']['id'])]=$image;$ordered=[];
        foreach($media as $row){$id=$this->id($row['id']);abort_unless(isset($byId[$id]),409);$ordered[]=$byId[$id];unset($byId[$id]);}abort_unless($byId===[],409);return $ordered;
    }
    private static ?array $replayMediaTime=null;
    private static ?array $replayProductTime=null;
    private static bool $replaying=false;
    /** Use only source-generated times close to this immutable command occurrence. */
    public function replayContext(string $name,array $local,array $originalPayload,\Carbon\Carbon $occurredAt,callable $work)
    {
        abort_unless(self::handles($name)&&!self::$replaying,409);$this->outputs($local);$product=$local['after']['product'];$productTimes=null;$mediaTimes=null;
        $zone=(string)config('app.timezone');abort_unless(in_array($zone,timezone_identifiers_list(),true),409);
        $time=function($value)use($occurredAt,$zone){abort_unless(is_string($value),409);$parsed=\Carbon\Carbon::parse($value,$zone);
            abort_unless(abs($parsed->getTimestamp()-$occurredAt->getTimestamp())<=5,409,'وقت إنشاء الصنف أو صورته بعيد عن وقت العملية.');return $value;};
        if($product){$productTimes=['updated_at'=>$time($product['row']['updated_at'])];if($name==='resturant_products.store')$productTimes['created_at']=$time($product['row']['created_at']);}
        if($originalPayload['files']??[]){
            $oldIds=array_map(fn($row)=>$this->localId($row['id']),$originalPayload['facts']['restaurant_product_crud_before']['product']['media']??[]);
            $new=array_values(array_filter($local['after']['media'],fn($row)=>!in_array($this->id($row['id']),$oldIds,true)));
            abort_unless(count($new)===1&&$new[0]['collection_name']==='product_image',409);Validator::make($new[0],['uuid'=>'required|uuid'])->validate();
            $order=$new[0]['order_column'];abort_unless(is_int($order)&&$order>0,409);
            $maximum=0;foreach($originalPayload['facts']['restaurant_product_crud_before']['product']['media']??[] as $old)if($old['collection_name']!=='product_image')$maximum=max($maximum,(int)($old['order_column']??0));
            abort_unless($order>$maximum,409,'ترتيب صورة الصنف الجديدة لا يلي الصور المحفوظة.');
            $mediaTimes=['created_at'=>$time($new[0]['created_at']),'updated_at'=>$time($new[0]['updated_at']),'order_column'=>$order];
        }
        $clock=\Carbon\Carbon::getTestNow();self::$replaying=true;self::$replayProductTime=$productTimes;self::$replayMediaTime=$mediaTimes;
        try{
            if($productTimes)\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($productTimes['created_at']??$productTimes['updated_at'],$zone));
            return $work();
        }finally{self::$replayProductTime=null;self::$replayMediaTime=null;self::$replaying=false;\Carbon\Carbon::setTestNow($clock);}
    }
    private function localId($value): int
    {
        return $this->id(is_array($value)?($value['$desktop_ref']['local_id']??null):$value);
    }
    private function localPayload($value)
    {
        if(!is_array($value))return $value;if(isset($value['$desktop_ref']))return $this->localId($value);
        foreach($value as $key=>$part)$value[$key]=$this->localPayload($part);return $value;
    }
    private function mapState(array $state,array $mapping): array
    {
        $map=function($value,string $entity)use($mapping){return $value===null?$value:($mapping[$entity][$this->id($value)]??$value);};
        foreach(self::RELATIONS as $path=>$entity){$value=data_get($state,$path);if($value!==null)data_set($state,$path,$map($value,$entity));}
        if(isset($state['row']))foreach(['id'=>'menu_restaurant_product','resturant_id'=>'menu_restaurant','added_by'=>'restaurant_flag_user','category_id'=>'catalog_category','product_id'=>'catalog_product'] as $field=>$entity)
            $state['row'][$field]=$map($state['row'][$field],$entity);
        foreach($state['features']??[] as $i=>$row)foreach(['id'=>'catalog_feature','added_by'=>'restaurant_flag_user','product_id'=>'catalog_product'] as $field=>$entity)$state['features'][$i][$field]=$map($row[$field],$entity);
        foreach($state['media']??[] as $i=>$row)foreach(['id'=>'menu_product_media','model_id'=>'menu_restaurant_product'] as $field=>$entity)$state['media'][$i][$field]=$map($row[$field],$entity);
        return $state;
    }
    public function verifyAfter(array $server,array $local,array $resolvedPayload,array $originalPayload): void
    {
        $serverRefs=$this->outputs($server);$localRefs=$this->outputs($local);$raw=$this->localPayload($originalPayload);
        $before=$raw['facts']['restaurant_product_crud_before'];$name=$before['product']===null?'resturant_products.store':($before['target']===null?'resturant_products.destroy':'resturant_products.update');
        $resolved=$this->facts($name,$resolvedPayload);$target=$before['target']??array_diff_key($before['product'],array_flip(['row','media']));
        abort_unless($this->equal($local['after']['target'],$target),409,'النتيجة المحلية غيّرت نطاق الفرع أو الكتالوج.');
        $mapping=[];
        foreach($this->referencePaths($originalPayload) as $path=>$entity){$old=data_get($raw,$path);$new=data_get($resolvedPayload,$path);if($old===null||$old==='')continue;
            $old=$this->id($old);$new=$this->id($new);abort_if(isset($mapping[$entity][$old])&&$mapping[$entity][$old]!==$new,409);$mapping[$entity][$old]=$new;}
        if($name!=='resturant_products.destroy'){
            $localProduct=$this->id($local['after']['product']['row']['id']);$serverProduct=$this->id($server['after']['product']['row']['id']);
            if($name!=='resturant_products.store')abort_unless($localProduct===$this->id($before['product']['row']['id'])&&$serverProduct===$this->id($resolved['product']['row']['id']),409);
            $mapping['menu_restaurant_product'][$localProduct]=$serverProduct;
        }
        abort_unless(array_keys($serverRefs)===array_keys($localRefs)&&count($server['after']['media'])===count($local['after']['media']),409);
        foreach($local['after']['media'] as $i=>$row){
            $old=$this->id($row['id']);$new=$this->id($server['after']['media'][$i]['id']);
            abort_if(isset($mapping['menu_product_media'][$old])&&$mapping['menu_product_media'][$old]!==$new,409,'ترتيب هوية صور الصنف مختلف.');$mapping['menu_product_media'][$old]=$new;
        }
        $translated=$local['after'];$translated['target']=$this->mapState($translated['target'],$mapping);
        if($translated['product'])$translated['product']=$this->mapState($translated['product'],$mapping);
        foreach($translated['media'] as $i=>$row){$translated['media'][$i]['id']=$mapping['menu_product_media'][$this->id($row['id'])];
            $translated['media'][$i]['model_id']=$mapping['menu_restaurant_product'][$this->id($row['model_id'])]??$row['model_id'];}
        foreach($translated['images'] as $i=>$image){$id=$this->id($image['row']['id']);$new=$mapping['menu_product_media'][$id];
            $translated['images'][$i]['row']['id']=$new;$translated['images'][$i]['row']['model_id']=$mapping['menu_restaurant_product'][$this->id($image['row']['model_id'])]??$image['row']['model_id'];}
        $translated['images']=$this->rebaseImages($translated['images']);
        // Existing and newly generated UUIDs, all creation times, nullable enums, six JSON slots,
        // and every named relationship remain exact after the explicit identity translation.
        abort_unless($this->equal($server['after'],$translated),409,'نتيجة حفظ الصنف أو صوره تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);$original=$this->original($name);$values=$this->values($name,$payload['values']);unset($values['idempotency_key']);
        $request=Request::create(url($this->uri($name,$payload)),self::ROUTES[$name]['method'],$values);
        $query=$this->returnQuery($payload['facts']['restaurant_product_crud_return']);$request->headers->set('Referer',url('/admin/resturants/'.$this->returnBranch($before)).($query!==''?'?'.$query:''));$request->headers->set('Accept','text/html');
        $session=new Store('desktop-restaurant-product-crud-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);
            $guard->setUser($actor);auth()->shouldUse('admin');$route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            if($name==='resturant_products.destroy')abort_unless($route->parameter('resturant') instanceof ResturantProduct&&(int)$route->parameter('resturant')->getKey()===$this->id($payload['parameters']['resturant']),409);
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
