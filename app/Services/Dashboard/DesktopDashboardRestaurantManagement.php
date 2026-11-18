<?php
namespace App\Services\Dashboard;

use App\Models\{Resturant,User};
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Existing original restaurant profile/status forms; branch identity and all other state stay frozen. */
class DesktopDashboardRestaurantManagement
{
    public const ROUTES=['resturants.update','resturants.updateStatus'];
    private const FIELDS=['added_by','user_id','parent_id','name','delivery_time','resturant_phone','open_at','close_at','min_order_price','km_price','default_0_1','default_1_2','default_2_3','service_fees','status','lat','lng'];
    private const ARRAYS=['area_id','expected_delivery','area_lat','area_lng'];
    private const AREA=['id','added_by','resturant_id','expected_delivery','created_at','updated_at','area_id','type','lng','lat'];
    private const GEOGRAPHY=['id','added_by','title_ar','title_en','parent_id','created_at','updated_at','cairo_id','geo_boundary'];
    private static ?array $replayMedia=null;
    public static function handles(?string $name): bool {return in_array($name,self::ROUTES,true);}
    private function id($value): int {abort_unless((is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(int)$value>0&&(string)(int)$value===(string)$value,422);return (int)$value;}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$route=app('router')->getRoutes()->getByName($name);$status=$name===self::ROUTES[1];
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\ResturantController@'.($status?'updateStatus':'update')
            &&$route->uri()===($status?'admin/updateStatus/{resturant}':'admin/resturants/{resturant}')&&$route->methods()===($status?['POST']:['PUT','PATCH'])&&in_array('IsAdmin',$route->gatherMiddleware(),true),409);return $route;
    }
    public function command(Request $request): string
    {
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409);$command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(string $name,array $values): array
    {
        $allowed=$name===self::ROUTES[1]?['status']:array_merge(self::FIELDS,self::ARRAYS);abort_if(array_diff(array_keys($values),array_merge(['idempotency_key'],$allowed)),422);
        Validator::make($values,['idempotency_key'=>'required|uuid'])->validate();foreach($values as $key=>$value){if(in_array($key,self::ARRAYS,true)){abort_unless(is_array($value)&&array_is_list($value)&&count($value)<=100,422);foreach($value as $part)abort_unless($part===null||is_scalar($part),422);}
            else abort_unless($value===null||is_scalar($value),422);}
        if($name===self::ROUTES[1])Validator::make($values,['status'=>'required|in:opened,disabled,busy,closed,hide'])->validate();return $values;
    }
    private function shape(array $row,array $columns): void
    {abort_unless(count($row)===count($columns)&&array_diff(array_keys($row),$columns)===[],409);foreach($row as $value)abort_unless($value===null||is_scalar($value),409);}
    private function identity(int $owner): array
    {
        $row=DB::table('users')->where('id',$owner)->lockForUpdate()->first(['id','created_at']);abort_unless($row&&is_string($row->created_at)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$row->created_at),409,'هوية صاحب الفرع تحتاج مراجعة متصلة.');return (array)$row;
    }
    private function selected(array $values,array $state): array
    {
        $ids=[];if(isset($state['restaurant']['row']['area_id']))$ids[]=$state['restaurant']['row']['area_id'];foreach($state['areas'] as $row)if(isset($row['area_id']))$ids[]=$row['area_id'];
        foreach($values['area_id']??[] as $id)if($id!==null&&$id!=='')$ids[]=$this->id($id);$rows=[];
        foreach(array_values(array_unique($ids)) as $id){$row=DB::table('areas')->where('id',$this->id($id))->lockForUpdate()->first();abort_unless($row,409);$row=(array)$row;$this->shape($row,self::GEOGRAPHY);$rows[]=$row;}return $rows;
    }
    private function state(int $id,?array $selected=null): array
    {
        $restaurant=app(DesktopDashboardRestaurantFlags::class)->managementState($id);abort_unless(is_string($restaurant['row']['created_at']),409,'هوية الفرع تحتاج مراجعة متصلة.');
        $areas=DB::table('resturant_areas')->where('resturant_id',$id)->orderBy('id')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();foreach($areas as $row)$this->shape($row,self::AREA);
        $state=['restaurant'=>$restaurant,'owner_identity'=>$this->identity($this->id($restaurant['row']['user_id'])),'areas'=>$areas,'catalog_areas'=>[],'images'=>app(DesktopDashboardRestaurantImages::class)->facts($id)];
        if($selected!==null)foreach($selected as $old){$row=DB::table('areas')->where('id',$this->id($old['id']))->lockForUpdate()->first();abort_unless($row,409);$row=(array)$row;$this->shape($row,self::GEOGRAPHY);$state['catalog_areas'][]=$row;}
        return $state;
    }
    private function query(array $context): string
    {
        abort_unless(array_keys($context)===['page','query','restaurant_filter','user_filter']&&in_array($context['page'],['edit','show','index','home'],true)&&is_string($context['query'])&&strlen($context['query'])<=4096,422);
        $seen=[];foreach(explode('&',$context['query']) as $part){if($part==='')continue;[$key,$value]=array_pad(explode('=',$part,2),2,'');$key=urldecode($key);abort_unless(in_array($key,['search','from_date','to_date','user_id','resturant_id','page'],true)&&!isset($seen[$key]),422);$seen[$key]=urldecode($value);}
        $parts=explode('&',$context['query']);foreach(['resturant_id'=>'restaurant_filter','user_id'=>'user_filter'] as $key=>$field){$present=isset($seen[$key])&&$seen[$key]!=='';$id=$context[$field];abort_unless($present?$id!==null:$id===null,422);if(!$present)continue;$id=$this->id($id);
            foreach($parts as &$part)if(urldecode(explode('=',$part,2)[0])===$key){if(urldecode(explode('=',$part,2)[1]??'')!==(string)$id)$part=explode('=',$part,2)[0].'='.$id;}unset($part);}
        return implode('&',$parts);
    }
    private function path(array $context,int $id): string
    {$path=match($context['page']){'edit'=>'/admin/resturants/'.$id.'/edit','show'=>'/admin/resturants/'.$id,'index'=>'/admin/resturants','home'=>'/admin/dashboard'};$query=$this->query($context);return $path.($query!==''?'?'.$query:'');}
    private function returnContext(Request $request,int $id): array
    {
        $parts=parse_url($request->header('Referer')??'');$origin=parse_url(url('/'));abort_unless(is_array($parts)&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['fragment'])
            &&(!isset($parts['host'])||(($parts['scheme']??null)===($origin['scheme']??null)&&strcasecmp($parts['host'],$origin['host']??'')===0&&($parts['port']??null)===($origin['port']??null))),422);
        $page=match($parts['path']??''){'/admin/resturants/'.$id.'/edit'=>'edit','/admin/resturants/'.$id=>'show','/admin/resturants'=>'index','/admin/dashboard'=>'home',default=>null};abort_unless($page,422);
        parse_str($parts['query']??'',$values);$context=['page'=>$page,'query'=>$parts['query']??'','restaurant_filter'=>isset($values['resturant_id'])&&$values['resturant_id']!==''?$this->id($values['resturant_id']):null,'user_filter'=>isset($values['user_id'])&&$values['user_id']!==''?$this->id($values['user_id']):null];$this->query($context);return $context;
    }
    private function facts(string $name,array $payload): array
    {
        abort_unless(array_keys($payload)===['parameters','values','files','facts']&&is_array($payload['parameters'])&&array_keys($payload['parameters'])===['resturant']&&is_array($payload['values'])&&is_array($payload['files'])&&is_array($payload['facts']),422);
        $id=$this->id($payload['parameters']['resturant']);$this->values($name,$payload['values']);app(DesktopDashboardRestaurantImages::class)->decoded($payload['files']);if($name===self::ROUTES[1])abort_unless($payload['files']===[],422);
        abort_unless(array_keys($payload['facts'])===['restaurant_management_before','restaurant_management_return']&&is_array($payload['facts']['restaurant_management_before']),409);$before=$payload['facts']['restaurant_management_before'];
        abort_unless(array_keys($before)===['restaurant','owner_identity','areas','catalog_areas','images']&&is_array($before['restaurant'])&&is_array($before['restaurant']['row']??null)
            &&$this->id($before['restaurant']['row']['id'])===$id&&is_array($before['owner_identity'])&&array_keys($before['owner_identity'])===['id','created_at']
            &&$this->id($before['owner_identity']['id'])===$this->id($before['restaurant']['row']['user_id'])&&is_string($before['owner_identity']['created_at']),409);
        foreach(['areas'=>self::AREA,'catalog_areas'=>self::GEOGRAPHY] as $key=>$columns){abort_unless(is_array($before[$key])&&array_is_list($before[$key])&&count($before[$key])<=200,409);foreach($before[$key] as $row){abort_unless(is_array($row),409);$this->shape($row,$columns);}}
        app(DesktopDashboardRestaurantImages::class)->validateFacts($this->rebaseImages($before['images']));$this->query($payload['facts']['restaurant_management_return']);app(DesktopDashboardRestaurantFlags::class)->managementValidate($before['restaurant']);return $before;
    }
    private function transactional(): void
    {
        abort_unless(DB::connection()->getDriverName()==='mysql'&&DB::connection()->getTablePrefix()==='',409);$tables=['resturants','resturant_areas','areas','users','media','desktop_dashboard_devices','desktop_dashboard_commands','desktop_dashboard_entities'];foreach(config('permission.table_names',[]) as $table)$tables[]=$table;
        foreach(array_unique($tables) as $table){$r=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);abort_unless($r&&strcasecmp($r->engine,'InnoDB')===0,409);}
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $this->transactional();$original=$this->original($name);$before=$this->facts($name,$payload);$id=$this->id($payload['parameters']['resturant']);$fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&in_array($fresh->account_type,['admin','vendor','resturant_owner'],true)&&!in_array($fresh->status,['disabled','declined'],true)&&(int)$scope->actor_id===(int)$fresh->id,403);abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        abort_unless(in_array('f:'.$id,json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR),true),403);$branch=DB::table('resturants')->where('id',$id)->lockForUpdate()->first();abort_unless($branch,403);
        abort_unless($branch->created_at===$before['restaurant']['row']['created_at']&&$this->identity($this->id($branch->user_id))===$before['owner_identity'],403,'هوية الفرع أو صاحبه تغيّرت.');
        $allowed=match($fresh->account_type){'admin'=>empty($fresh->owner_resturant_id)||(int)$fresh->owner_resturant_id===$id,'resturant_owner'=>(int)$fresh->owner_resturant_id>0&&($id===(int)$fresh->owner_resturant_id||(int)$branch->parent_id===(int)$fresh->owner_resturant_id),'vendor'=>(int)$branch->user_id===(int)$fresh->id,default=>false};abort_unless($allowed,403);
        if($name===self::ROUTES[0]){
            $roles=$fresh->roles()->lockForUpdate()->get();$fresh->setRelation('roles',$roles);$fresh->setRelation('permissions',$fresh->permissions()->lockForUpdate()->get());
            $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name','resturant-edit')->lockForUpdate()->first();abort_unless($permission,403);
            $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
            $role=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles->where('guard_name','admin')->pluck('id'))->where('permission_id',$permission->id)->lockForUpdate()->first();abort_unless($direct||$role,403);
            $v=$payload['values'];abort_unless($this->id($v['added_by']??null)===(int)$fresh->id&&$this->id($v['user_id']??null)===$this->id($before['restaurant']['row']['user_id']),403);
            if(array_key_exists('parent_id',$v))abort_unless(($v['parent_id']===null&&$before['restaurant']['row']['parent_id']===null)||$this->id($v['parent_id'])===$this->id($before['restaurant']['row']['parent_id']),403,'نقل هوية الفرع أو أصله يتطلب مراجعة متصلة.');
        }
        $guard=auth('admin');$previous=$guard->getUser();try{$guard->setUser($fresh);$probe=Request::create(url($this->uri($name,$id)),$name===self::ROUTES[1]?'POST':'PUT');if(request()->hasSession())$probe->setLaravelSession(request()->session());$route=clone $original;$route->flushController();$route->bind($probe);$probe->setRouteResolver(fn()=>$route);$probe->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());
            $router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);abort_unless($route->parameter('resturant') instanceof Resturant&&(int)$route->parameter('resturant')->id===$id,403);
            $middleware=array_map(fn($m)=>MiddlewareNameResolver::resolve($m,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));(new Pipeline(app()))->send($probe)->through($middleware)->then(fn()=>true);
        }finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}return $fresh;
    }
    private function uri(string $name,int $id): string {return $name===self::ROUTES[1]?'/admin/updateStatus/'.$id:'/admin/resturants/'.$id;}
    private function requestPayload(string $name,Request $request,string $command,?array $saved): array
    {
        $target=$request->route('resturant');$id=$this->id($target instanceof Resturant?$target->id:$target);$values=$request->except('_token','_method','_desktop_command','logo','bg_image');$values['idempotency_key']=$command;$values=$this->values($name,$values);
        if($saved===null){$state=$this->state($id);$state['catalog_areas']=$this->selected($values,$state);$saved=['restaurant_management_before'=>$state,'restaurant_management_return'=>$this->returnContext($request,$id)];}
        $payload=['parameters'=>['resturant'=>$id],'values'=>$values,'files'=>app(DesktopDashboardRestaurantImages::class)->files($request),'facts'=>$saved];$this->facts($name,$payload);return $payload;
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {$device=(string)config('desktop_dashboard.device_id');$saved=app(DesktopDashboardJournal::class)->savedFacts($device,$command,(int)$actor->id,$name);$payload=$this->requestPayload($name,$request,$command,$saved);$scope=app(DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$this->authorize($name,$payload,$actor,$scope);return $payload;}
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $scope,?array $saved=null): array {$p=$this->requestPayload($name,$request,$command,$saved);$this->authorize($name,$p,$actor,$scope);return $p;}
    private function references(array $payload): array
    {
        $paths=['parameters.resturant'=>'menu_restaurant','values.user_id'=>'restaurant_flag_user','values.added_by'=>'restaurant_flag_user','values.parent_id'=>'menu_restaurant','facts.restaurant_management_return.restaurant_filter'=>'menu_restaurant','facts.restaurant_management_return.user_filter'=>'restaurant_flag_user'];
        $base='facts.restaurant_management_before.';foreach(['restaurant.row.id'=>'menu_restaurant','restaurant.row.added_by'=>'restaurant_flag_user','restaurant.row.user_id'=>'restaurant_flag_user','restaurant.row.parent_id'=>'menu_restaurant','restaurant.row.area_id'=>'catalog_area',
            'restaurant.added_by.id'=>'restaurant_flag_user','restaurant.owner.id'=>'restaurant_flag_user','restaurant.parent.id'=>'menu_restaurant','restaurant.parent.user_id'=>'restaurant_flag_user','restaurant.parent.parent_id'=>'menu_restaurant','restaurant.branch.id'=>'menu_restaurant','owner_identity.id'=>'restaurant_flag_user'] as $path=>$kind)$paths[$base.$path]=$kind;
        foreach($payload['values']['area_id']??[] as $i=>$id)if($id!==''&&$id!==null)$paths['values.area_id.'.$i]='catalog_area';
        foreach($payload['facts']['restaurant_management_before']['areas']??[] as $i=>$row)foreach(['id'=>'restaurant_area','added_by'=>'restaurant_flag_user','resturant_id'=>'menu_restaurant','area_id'=>'catalog_area'] as $field=>$kind)$paths[$base.'areas.'.$i.'.'.$field]=$kind;
        foreach($payload['facts']['restaurant_management_before']['catalog_areas']??[] as $i=>$row)foreach(['id'=>'catalog_area','added_by'=>'restaurant_flag_user','parent_id'=>'catalog_area'] as $field=>$kind)$paths[$base.'catalog_areas.'.$i.'.'.$field]=$kind;
        foreach($payload['facts']['restaurant_management_before']['images']??[] as $i=>$image)foreach(['id'=>'restaurant_media','model_id'=>'menu_restaurant'] as $field=>$kind)$paths[$base.'images.'.$i.'.row.'.$field]=$kind;return $paths;
    }
    public function inputs(array $payload,callable $reference): array {foreach($this->references($payload) as $path=>$kind){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($kind,$value));}return $payload;}
    public function validateReferences(array $payload): void {foreach($this->references($payload) as $path=>$kind){$value=data_get($payload,$path);if(is_array($value))abort_unless(array_keys($value)===['$desktop_ref']&&is_string($value['$desktop_ref']['entity']??null)&&(($value['$desktop_ref']['entity']===$kind)||(in_array($kind,['restaurant_area','restaurant_media'],true)&&preg_match('/^'.preg_quote($kind,'/').'\.[0-9]{1,3}$/D',$value['$desktop_ref']['entity']))),422);}}
    private function rebaseImages(array $images): array
    {foreach($images as &$image){$id=$this->id($image['row']['id']);foreach($image['files'] as &$file){$parts=explode('/',$file['relative'],2);abort_unless(count($parts)===2,409);$file['relative']=$id.'/'.$parts[1];$file['path']='resturants/'.$file['relative'];}unset($file);}unset($image);return $images;}
    private function comparable(array $state): array {unset($state['restaurant']['row']['updated_at']);return $state;}
    public function capture(string $name,array $payload,Request $request,callable $work): array
    {
        $before=$this->facts($name,$payload);$before['images']=$this->rebaseImages($before['images']);$id=$this->id($payload['parameters']['resturant']);$current=$this->state($id,$before['catalog_areas']);
        abort_unless(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($before))===app(DesktopDashboardJournal::class)->fingerprint($this->comparable($current)),409,'بيانات الفرع أو صوره أو مناطقه تغيّرت.');
        $images=app(DesktopDashboardRestaurantImages::class);$images->allowRestaurant($id,$payload['values']['idempotency_key'],self::$replayMedia);$images->protectOld($before['images']);
        $oldBody=$request->request->all();$oldFiles=$request->files->all();$converted=(function(){return $this->convertedFiles;})->call($request);
        try{$values=$payload['values'];unset($values['idempotency_key']);if(isset($oldBody['_token']))$values['_token']=$oldBody['_token'];$request->request->replace($values);
            $response=$images->withUploads($payload['files'],function(array $files)use($request,$work){$request->files->replace($files);(function(){$this->convertedFiles=null;})->call($request);$response=$work();if($response->getStatusCode()>=400||$request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);return $response;});
            if($response->getStatusCode()>=400||$request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            $location=$this->path($payload['facts']['restaurant_management_return'],$id);$parts=parse_url($response->headers->get('Location')??'');$actual=($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'');abort_unless(in_array($response->getStatusCode(),[302,303],true)&&$actual===$location,409);
            $after=$this->state($id,$before['catalog_areas']);$result=['http'=>['status'=>$response->getStatusCode(),'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$location],'success'=>session('success'),'after'=>$after,'references'=>$this->resultReferences($after)];$images->verifyResult($result,$payload['files']);$this->assertEffects($after,$before,$payload['values'],$payload['files']);return $result;
        }finally{$request->request->replace($oldBody);$request->files->replace($oldFiles);(function($value){$this->convertedFiles=$value;})->call($request,$converted);}
    }
    private function resultReferences(array $after): array {$refs=['menu_restaurant'=>$this->id($after['restaurant']['row']['id'])];foreach($after['areas'] as $i=>$row)$refs['restaurant_area.'.$i]=$this->id($row['id']);foreach($after['images'] as $i=>$image)$refs['restaurant_media.'.$i]=$this->id($image['row']['id']);return $refs;}
    public function verifyResult(array $result,array $files=[]): void {app(DesktopDashboardRestaurantImages::class)->verifyResult($result,$files);}
    public function outputs(array $result): array {return isset($result['http'])?($result['references']??[]):[];}
    public function response(array $result) {$h=$result['http'];$r=response($h['content'],$h['status']);if($h['type'])$r->headers->set('Content-Type',$h['type']);$r->headers->set('Location',url($h['location']));if(isset($result['success']))session()->flash('success',$result['success']);return $r;}
    public function replayContext(string $name,array $local,array $payload,\Carbon\Carbon $when,callable $work)
    {
        abort_unless(self::$replayMedia===null,409);$times=[];$raw=$this->localize($payload);$before=$raw['facts']['restaurant_management_before'];$this->assertEffects($local['after'],$before,$raw['values'],$raw['files']);
        $old=array_column(array_column($before['images'],'row'),'id');$zone=(string)config('app.timezone');
        foreach($local['after']['images'] as $image){$row=$image['row'];if(in_array($row['id'],$old,true))continue;$collection=$row['collection_name'];
            foreach(['created_at','updated_at'] as $field){abort_unless(is_string($row[$field])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$row[$field]),409);abort_unless(abs(\Carbon\Carbon::parse($row[$field],$zone)->getTimestamp()-$when->getTimestamp())<=5,409);}
            $times[$collection]=['created_at'=>$row['created_at'],'updated_at'=>$row['updated_at'],'order_column'=>$row['order_column']];}
        self::$replayMedia=$times;try{return $work();}finally{self::$replayMedia=null;}
    }
    private function assertEffects(array $after,array $before,array $values,array $files): void
    {
        abort_unless(array_keys($after)===array_keys($before),409);app(DesktopDashboardRestaurantFlags::class)->managementValidate($after['restaurant']);
        foreach(['owner_identity','catalog_areas'] as $part)abort_unless($after[$part]===$before[$part],409);
        foreach(['owner','parent','branch'] as $part)abort_unless($after['restaurant'][$part]===$before['restaurant'][$part],409);
        $mutable=array_merge(array_keys($values),['updated_at']);if(!empty($values['area_id']))$mutable[]='area_id';
        foreach($before['restaurant']['row'] as $field=>$value)if(!in_array($field,$mutable,true))abort_unless($after['restaurant']['row'][$field]===$value,409);
        $added=$values['added_by']??$before['restaurant']['row']['added_by'];abort_unless((string)$after['restaurant']['row']['added_by']===(string)$added&&$after['restaurant']['added_by']===['id'=>$after['restaurant']['row']['added_by']],409);
        if(empty($values['area_id']))abort_unless($after['areas']===$before['areas'],409);
        else{abort_unless(count($after['areas'])===count($values['area_id']),409);$old=array_column($before['areas'],'id');foreach($after['areas'] as $i=>$row){$this->shape($row,self::AREA);abort_unless(!in_array($row['id'],$old,true)
            &&(string)$row['resturant_id']===(string)$before['restaurant']['row']['id']&&(string)$row['added_by']===(string)$added
            &&(empty($values['area_id'][$i])?$row['area_id']===null:(string)$row['area_id']===(string)$values['area_id'][$i]),409);}}
        $images=app(DesktopDashboardRestaurantImages::class);$images->validateFacts($after['images']);$images->verifyUploads($after['images'],$files);$kept=[];$new=[];$old=array_column(array_column($before['images'],'row'),'id');
        foreach($after['images'] as $image){$row=$image['row'];abort_unless($row['model_id']===$before['restaurant']['row']['id'],409);$collection=$row['collection_name'];
            if(isset($files[$collection])){abort_unless(!isset($new[$collection])&&!in_array($row['id'],$old,true)&&$row['uuid']===(string)\Ramsey\Uuid\Uuid::uuid5($values['idempotency_key'],'restaurant:'.$collection),409);$new[$collection]=$row;}
            else $kept[]=$image;}
        $orders=[];foreach($before['images'] as $oldImage)$orders[]=['collection'=>$oldImage['row']['collection_name'],'order'=>(int)$oldImage['row']['order_column']];foreach(['logo','bg_image'] as $slot)if(isset($files[$slot])){$orders=array_values(array_filter($orders,fn($o)=>$o['collection']!==$slot));$maximum=0;foreach($orders as $o)$maximum=max($maximum,$o['order']);abort_unless(is_int($new[$slot]['order_column'])&&$new[$slot]['order_column']>$maximum,409);$orders[]=['collection'=>$slot,'order'=>$new[$slot]['order_column']];}
        abort_unless($kept===array_values(array_filter($before['images'],fn($i)=>!isset($files[$i['row']['collection_name']])))&&count($new)===count($files),409);
    }
    private function localize($value) {if(!is_array($value))return $value;if(isset($value['$desktop_ref']))return $value['$desktop_ref']['local_id'];return array_map(fn($v)=>$this->localize($v),$value);}
    public function verifyAfter(array $server,array $local,array $resolved,array $original): void
    {
        abort_unless(is_array($server['after']??null)&&is_array($local['after']??null)&&$this->outputs($server)===$this->resultReferences($server['after'])&&$this->outputs($local)===$this->resultReferences($local['after']),409);
        $raw=$this->localize($original);$localState=$local['after'];$before=$raw['facts']['restaurant_management_before'];$mapped=$resolved['facts']['restaurant_management_before'];$mapped['images']=$this->rebaseImages($mapped['images']);$this->assertEffects($localState,$before,$raw['values'],$raw['files']);$this->assertEffects($server['after'],$mapped,$resolved['values'],$resolved['files']);
        foreach(['id','user_id','parent_id'] as $field){abort_unless($localState['restaurant']['row'][$field]===$before['restaurant']['row'][$field],409);$localState['restaurant']['row'][$field]=$mapped['restaurant']['row'][$field];}
        $localState['restaurant']['owner']=$mapped['restaurant']['owner'];$localState['restaurant']['parent']=$mapped['restaurant']['parent'];$localState['restaurant']['branch']=$mapped['restaurant']['branch'];$localState['owner_identity']=$mapped['owner_identity'];
        $localState['restaurant']['row']['added_by']=$this->id($resolved['values']['added_by']??$mapped['restaurant']['row']['added_by']);$localState['restaurant']['added_by']=['id'=>$localState['restaurant']['row']['added_by']];
        $localState['catalog_areas']=$mapped['catalog_areas'];
        foreach($localState['areas'] as $i=>&$row){abort_unless(isset($server['after']['areas'][$i]),409);$row['id']=$server['after']['areas'][$i]['id'];$row['resturant_id']=$resolved['parameters']['resturant'];$row['added_by']=!empty($resolved['values']['area_id'])?$this->id($resolved['values']['added_by']):$mapped['areas'][$i]['added_by'];
            if($row['area_id']!==null){$found=false;foreach($before['catalog_areas'] as $j=>$area)if($area['id']===$row['area_id']){$row['area_id']=$mapped['catalog_areas'][$j]['id'];$found=true;break;}abort_unless($found,409);}}
        unset($row);if($localState['restaurant']['row']['area_id']!==null){$found=false;foreach($before['catalog_areas'] as $i=>$area)if($area['id']===$localState['restaurant']['row']['area_id']){$localState['restaurant']['row']['area_id']=$mapped['catalog_areas'][$i]['id'];$found=true;break;}abort_unless($found,409);}
        foreach($localState['images'] as $i=>&$image){abort_unless(isset($server['after']['images'][$i]),409);$image['row']['id']=$server['after']['images'][$i]['row']['id'];$image['row']['model_id']=$resolved['parameters']['resturant'];}unset($image);$localState['images']=$this->rebaseImages($localState['images']);
        abort_unless(app(DesktopDashboardJournal::class)->fingerprint($this->comparable($localState))===app(DesktopDashboardJournal::class)->fingerprint($this->comparable($server['after'])),409,'نتيجة بيانات الفرع ومناطقه وصوره تختلف عن العملية المحلية.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $this->facts($name,$payload);$id=$this->id($payload['parameters']['resturant']);$original=$this->original($name);$method=$name===self::ROUTES[1]?'POST':'PUT';$v=$payload['values'];unset($v['idempotency_key']);$request=Request::create(url($this->uri($name,$id)),$method,$v);$session=new Store('desktop-restaurant-management-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);$request->headers->set('Referer',url($this->path($payload['facts']['restaurant_management_return'],$id)));
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{$app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');$route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            $middleware=array_map(fn($m)=>MiddlewareNameResolver::resolve($m,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));return $this->capture($name,$payload,$request,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{$app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);}
    }
}
