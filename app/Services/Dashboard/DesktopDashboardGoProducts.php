<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};
use Illuminate\Support\Str;

/** Original GO store product/options forms, bound to one current enrolled store and immutable media. */
class DesktopDashboardGoProducts
{
    public const ROUTES=['go-stores.products.store','go-stores.products.update'];
    private const ROW=['id','user_id','request_key','name','description','unit','price_cents','image_path','available','options','revision','created_at','updated_at'];
    public static function handles(?string $route): bool {return in_array($route,self::ROUTES,true);}
    private function id($value): int
    {abort_unless((is_string($value)||is_int($value))&&preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)&&(int)$value>0&&(string)(int)$value===(string)$value,422);return (int)$value;}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$route=app('router')->getRoutes()->getByName($name);
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\GoStores\\StoreController@saveProduct'
            &&$route->uri()==='admin/go-stores/{owner}/products'.($name===self::ROUTES[1]?'/'.'{product}':'')
            &&$route->methods()===['POST']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409);return $route;
    }
    public function command(Request $request): string
    {
        abort_unless($request->isMethod('POST'),405);$header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409);$command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(array $values): array
    {
        abort_if(array_diff(array_keys($values),['idempotency_key','name','description','unit','price','available','options','revision','request_key']),422);
        Validator::make($values,['idempotency_key'=>'required|uuid'])->validate();
        foreach($values as $key=>$value)if($key!=='options')abort_unless($value===null||is_scalar($value),422);
        abort_unless(!isset($values['options'])||is_array($values['options'])||is_string($values['options']),422);
        // Content validation remains in the original Catalog::saveProduct controller path.
        return $values;
    }
    private function transactional(): void
    {
        abort_unless(DB::connection()->getDriverName()==='mysql'&&DB::connection()->getTablePrefix()==='',409);
        $tables=['go_store_products','go_stores','pending_vendors','users','desktop_dashboard_commands','desktop_dashboard_devices','desktop_dashboard_entities'];
        foreach(config('permission.table_names',[]) as $table)$tables[]=$table;
        foreach(array_unique($tables) as $table){abort_unless(is_string($table)&&preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$table),409);
            $row=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);abort_unless($row&&strcasecmp($row->engine,'InnoDB')===0,409);}
    }
    private function validateProduct(?array $row,int $owner): void
    {
        if($row===null)return;abort_unless(count($row)===count(self::ROW)&&array_diff(array_keys($row),self::ROW)===[]&&$this->id($row['user_id'])===$owner,409);
        foreach($row as $value)abort_unless($value===null||is_scalar($value),409);$this->id($row['id']);
        Validator::make($row,['request_key'=>'required|uuid','revision'=>'required|integer|min:1'])->validate();
        $options=json_decode($row['options'],true);abort_unless(is_array($options)&&array_is_list($options)&&count($options)<=20,409);$ids=[];
        foreach($options as $option){abort_unless(is_array($option)&&array_keys($option)===['id','label','price_cents'],409);
            Validator::make($option,['id'=>'required|uuid','label'=>'required|string','price_cents'=>'required|integer|min:1'])->validate();abort_if(isset($ids[$option['id']]),409);$ids[$option['id']]=true;}
        foreach(['created_at','updated_at'] as $field)abort_unless($row[$field]===null||(is_string($row[$field])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$row[$field])),409);
    }
    private function state(string $name,array $parameters,array $values): array
    {
        $owner=$this->id($parameters['owner']);$profile=app(DesktopDashboardGoStoreProfile::class)->state($owner);$identity=$this->ownerIdentity($owner);
        $query=DB::table('go_store_products')->where('user_id',$owner);
        if($name===self::ROUTES[1])$row=$query->where('id',$this->id($parameters['product']))->lockForUpdate()->first();
        else{$key=$values['request_key']??null;Validator::make(['request_key'=>$key],['request_key'=>'required|uuid'])->validate();$matches=$query->where('request_key',$key)->lockForUpdate()->get();abort_unless(count($matches)<=1,409);$row=$matches->first();}
        $row=$row?(array)$row:null;$this->validateProduct($row,$owner);if($name===self::ROUTES[1])abort_unless($row,404);
        return ['profile'=>$profile,'owner_identity'=>$identity,'product'=>$row,'image'=>$row?app(DesktopDashboardGoProductImages::class)->proof($row['image_path'],$owner):null];
    }
    private function ownerIdentity(int $owner): array
    {
        $row=DB::table('users')->where('id',$owner)->lockForUpdate()->first(['id','created_at']);
        abort_unless($row&&is_string($row->created_at)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$row->created_at),409,'وقت إنشاء صاحب المتجر غير موثّق؛ يلزم مراجعة متصلة.');
        return ['id'=>(int)$row->id,'created_at'=>$row->created_at];
    }
    private function facts(string $name,array $payload): array
    {
        abort_unless(array_keys($payload)===['parameters','values','files','facts']&&is_array($payload['parameters'])&&is_array($payload['values'])&&is_array($payload['files'])&&is_array($payload['facts']),422);
        abort_unless(array_keys($payload['parameters'])===($name===self::ROUTES[1]?['owner','product']:['owner']),422);$owner=$this->id($payload['parameters']['owner']);
        if($name===self::ROUTES[1])$this->id($payload['parameters']['product']);$this->values($payload['values']);app(DesktopDashboardGoProductImages::class)->decoded($payload['files']);
        abort_unless(array_keys($payload['facts'])===['go_product_before']&&is_array($payload['facts']['go_product_before']),409);$before=$payload['facts']['go_product_before'];
        abort_unless(array_keys($before)===['profile','owner_identity','product','image']&&is_array($before['profile'])&&array_keys($before['profile'])===['row','owner','pending']
            &&is_array($before['profile']['row'])&&$this->id($before['profile']['row']['user_id']??null)===$owner,409);
        abort_unless(is_array($before['owner_identity'])&&array_keys($before['owner_identity'])===['id','created_at']&&$this->id($before['owner_identity']['id'])===$owner
            &&is_string($before['owner_identity']['created_at'])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$before['owner_identity']['created_at']),409);
        foreach(['row'=>['user_id','name','kind','address','revision'],'owner'=>['account_type','app_scope','pending_vendor_id','delegate_fees'],'pending'=>['id','profession_key']] as $key=>$columns){
            $part=$before['profile'][$key];if($key==='pending'&&$part===null)continue;
            abort_unless(is_array($part)&&count($part)===count($columns)&&array_diff(array_keys($part),$columns)===[],409);
            foreach($part as $value)abort_unless($value===null||is_scalar($value),409);
        }
        abort_unless($before['product']===null||is_array($before['product']),409);$this->validateProduct($before['product'],$owner);
        if($name===self::ROUTES[1])abort_unless($before['product']&&$this->id($before['product']['id'])===$this->id($payload['parameters']['product']),409);
        elseif($before['product'])abort_unless($before['product']['request_key']===($payload['values']['request_key']??null),409);
        abort_unless($before['product']?is_array($before['image'])&&array_keys($before['image'])===['path','mime','sha256','bytes']&&$before['image']['path']===$before['product']['image_path']:$before['image']===null,409);
        return $before;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $this->transactional();$this->original($name);$before=$this->facts($name,$payload);$owner=$this->id($payload['parameters']['owner']);
        $fresh=app(DesktopDashboardGoStoreProfile::class)->authorizeActor($actor);abort_unless((int)$scope->actor_id===(int)$fresh->id,403);abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        app(DesktopDashboardGoStoreProfile::class)->enrolledBranch($scope,['owner'=>$owner]);app(DesktopDashboardGoStoreProfile::class)->state($owner);
        abort_unless($this->ownerIdentity($owner)===$before['owner_identity'],403,'هوية صاحب المتجر تغيّرت؛ النتيجة القديمة محفوظة للمراجعة.');
        if($before['product']){$current=DB::table('go_store_products')->where('id',$before['product']['id'])->lockForUpdate()->first(['user_id','request_key']);
            abort_if($current&&((int)$current->user_id!==$owner||$current->request_key!==$before['product']['request_key']),403,'هوية المنتج لم تعد مرتبطة بصاحب المتجر المحفوظ.');}
        return $fresh;
    }
    private function requestPayload(string $name,Request $request,string $command,?array $saved): array
    {
        $parameters=$request->route()->parameters();foreach($parameters as &$id)$id=$this->id($id);unset($id);
        $values=$request->except('_token','_desktop_command','image');$values['idempotency_key']=$command;$values=$this->values($values);
        $payload=['parameters'=>$parameters,'values'=>$values,'files'=>app(DesktopDashboardGoProductImages::class)->files($request),
            'facts'=>$saved??['go_product_before'=>$this->state($name,$parameters,$values)]];$this->facts($name,$payload);return $payload;
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {
        $device=(string)config('desktop_dashboard.device_id');$saved=app(DesktopDashboardJournal::class)->savedFacts($device,$command,(int)$actor->id,$name);
        $payload=$this->requestPayload($name,$request,$command,$saved);$scope=app(DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $scope,?array $saved=null): array
    {$payload=$this->requestPayload($name,$request,$command,$saved);$this->authorize($name,$payload,$actor,$scope);return $payload;}
    public function inputs(array $payload,callable $reference): array
    {
        foreach(['parameters.owner'=>'menu_store_owner','facts.go_product_before.owner_identity.id'=>'menu_store_owner','parameters.product'=>'menu_store_product','facts.go_product_before.profile.row.user_id'=>'menu_store_owner',
            'facts.go_product_before.product.id'=>'menu_store_product','facts.go_product_before.product.user_id'=>'menu_store_owner','facts.go_product_before.profile.pending.id'=>'go_product_pending','facts.go_product_before.profile.owner.pending_vendor_id'=>'go_product_pending'] as $path=>$kind){
            $value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($kind,$value));}
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach(['parameters.owner'=>'menu_store_owner','facts.go_product_before.owner_identity.id'=>'menu_store_owner','parameters.product'=>'menu_store_product','facts.go_product_before.profile.row.user_id'=>'menu_store_owner',
            'facts.go_product_before.product.id'=>'menu_store_product','facts.go_product_before.product.user_id'=>'menu_store_owner','facts.go_product_before.profile.pending.id'=>'go_product_pending','facts.go_product_before.profile.owner.pending_vendor_id'=>'go_product_pending'] as $path=>$kind){
            $value=data_get($payload,$path);if(is_array($value))abort_unless(array_keys($value)===['$desktop_ref']&&is_array($value['$desktop_ref'])&&($value['$desktop_ref']['entity']??null)===$kind,422);}
    }
    private function rebase(array $state,int $old,int $new): array
    {
        if($state['product']){$path=$state['product']['image_path'];abort_unless(is_string($path)&&str_starts_with($path,'go-stores/'.$old.'/'),409);$path='go-stores/'.$new.'/'.substr($path,strlen('go-stores/'.$old.'/'));
            $state['product']['user_id']=$new;$state['product']['image_path']=$path;$state['image']['path']=$path;}
        $state['profile']['row']['user_id']=$new;$state['owner_identity']['id']=$new;return $state;
    }
    public function capture(string $name,array $payload,Request $request,callable $work): array
    {
        $this->facts($name,$payload);$response=app(DesktopDashboardGoProductImages::class)->bind($request,$payload['parameters']['owner'],$payload['values']['idempotency_key'],$payload['files'],$work);
        if($response->getStatusCode()>=400||($request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true)))
            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        abort_unless(in_array($response->getStatusCode(),[302,303],true)&&strlen($response->getContent())<=1024*1024,409);
        $path='/admin/go-stores/'.$payload['parameters']['owner'];$parts=parse_url($response->headers->get('Location')??'');
        abort_unless(($parts['path']??null)===$path&&!isset($parts['query'])&&!isset($parts['fragment']),409);
        $after=$this->state($name,$payload['parameters'],$payload['values']);abort_unless($after['product'],409);
        return ['http'=>['status'=>$response->getStatusCode(),'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$path],
            'success'=>session('success'),'after'=>$after,'references'=>['menu_store_product'=>(int)$after['product']['id']]];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);if($http['type'])$response->headers->set('Content-Type',$http['type']);
        $response->headers->set('Location',url($http['location']));if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    public function verifyResult(array $result): void
    {
        if(isset($result['result'])&&($result['committed']??null)===true)$result=$result['result'];
        $after=$result['after']??null;abort_unless(is_array($after)&&array_keys($after)===['profile','owner_identity','product','image']&&is_array($after['product']),409);
        $owner=$this->id($after['product']['user_id']??null);$this->validateProduct($after['product'],$owner);
        abort_unless(is_array($after['image'])&&app(DesktopDashboardGoProductImages::class)->proof($after['product']['image_path'],$owner)===$after['image'],409,'صورة نتيجة المنتج المحفوظة غير متاحة أو تغيّرت.');
    }
    public function outputs(array $result): array {return isset($result['http'])?($result['references']??[]):[];}
    public function verifyAfter(array $server,array $local,array $resolved,array $original): void
    {
        foreach([$server,$local] as $result)abort_unless(is_array($result['after']??null)&&array_keys($result['after'])===['profile','owner_identity','product','image']&&is_array($result['after']['product']),409);
        $oldOwner=$original['parameters']['owner'];if(is_array($oldOwner))$oldOwner=$oldOwner['$desktop_ref']['local_id']??null;$oldOwner=$this->id($oldOwner);$owner=$this->id($resolved['parameters']['owner']);
        $this->validateProduct($local['after']['product'],$oldOwner);
        $identity=$original['parameters']['product']??($original['facts']['go_product_before']['product']['id']??null);
        if(is_array($identity))$identity=$identity['$desktop_ref']['local_id']??null;
        if($identity!==null)abort_unless($this->id($local['after']['product']['id'])===$this->id($identity),409);
        abort_unless(($local['references']['menu_store_product']??null)===$local['after']['product']['id']
            &&($server['references']['menu_store_product']??null)===$server['after']['product']['id'],409);
        $this->validateProduct($server['after']['product'],$owner);
        $localProfile=$original['facts']['go_product_before']['profile'];
        $localize=function($value)use(&$localize){if(!is_array($value))return $value;if(isset($value['$desktop_ref']))return $value['$desktop_ref']['local_id'];return array_map($localize,$value);};
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($localize($localProfile)),app(DesktopDashboardJournal::class)->fingerprint($local['after']['profile']))
            &&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($resolved['facts']['go_product_before']['profile']),app(DesktopDashboardJournal::class)->fingerprint($server['after']['profile'])),409,'نتيجة المنتج غيّرت ملف صاحب المتجر.');
        $local['after']['profile']=$resolved['facts']['go_product_before']['profile'];
        abort_unless($local['after']['owner_identity']===$localize($original['facts']['go_product_before']['owner_identity'])
            &&$server['after']['owner_identity']===$resolved['facts']['go_product_before']['owner_identity'],409,'نتيجة المنتج غيّرت هوية صاحب المتجر.');
        $local['after']['owner_identity']=$resolved['facts']['go_product_before']['owner_identity'];
        $local['after']=$this->rebase($local['after'],$oldOwner,$owner);$local['after']['product']['id']=$server['after']['product']['id'];
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($server['after']),app(DesktopDashboardJournal::class)->fingerprint($local['after'])),409,'نتيجة المنتج وخياراته وصورته تختلف عن العملية المحلية.');
    }
    /** New option UUIDs belong to the accepted form, not to the server's replay timing. */
    public function replayContext(string $name,array $local,array $payload,Carbon $when,callable $work)
    {
        $before=$payload['facts']['go_product_before']['product']??null;$old=$before?array_column(json_decode($before['options'],true,512,JSON_THROW_ON_ERROR),'id'):[];
        $after=$local['after']['product']??null;abort_unless(is_array($after)&&is_string($after['options']??null),409);$options=json_decode($after['options'],true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($options)&&array_is_list($options)&&count($options)<=20,409);$queue=[];
        foreach($options as $option){Validator::make($option,['id'=>'required|uuid'])->validate();if(!in_array($option['id'],$old,true))$queue[]=$option['id'];}
        $factory=new \ReflectionProperty(Str::class,'uuidFactory');$factory->setAccessible(true);$previous=$factory->getValue();$index=0;
        Str::createUuidsUsing(function()use($queue,&$index){abort_unless(isset($queue[$index]),409,'خيارات المنتج تتطلب هوية إضافية غير محفوظة.');return \Ramsey\Uuid\Uuid::fromString($queue[$index++]);});
        try{$result=$work();abort_unless($index===count($queue),409,'خيارات المنتج المحفوظة لا تطابق الإنشاء الأصلي.');return $result;}
        finally{Str::createUuidsUsing($previous);}
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);$owner=$this->id($payload['parameters']['owner']);
        // The resolver changes typed owner IDs, while immutable image paths retain their original textual owner segment.
        if($before['product']&&preg_match('#^go-stores/([1-9][0-9]*)/#D',$before['product']['image_path'],$match))$before=$this->rebase($before,(int)$match[1],$owner);
        $current=$this->state($name,$payload['parameters'],$payload['values']);abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),app(DesktopDashboardJournal::class)->fingerprint($current)),409,'المنتج أو صاحب المتجر أو صورته تغيّر؛ العملية محفوظة للمراجعة.');
        $original=$this->original($name);$values=$payload['values'];unset($values['idempotency_key']);$path='/admin/go-stores/'.$owner.'/products'.($name===self::ROUTES[1]?'/'.$payload['parameters']['product']:'');
        $request=Request::create(url($path),'POST',$values);$session=new Store('desktop-go-products-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');
            $middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$payload,$request,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
