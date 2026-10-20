<?php
namespace App\Services\Dashboard;

use App\Models\User;
use App\Http\Requests\Dashboard\GoStores\CreateStoreRequest;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Hash,Validator};

/** Original admin AccountCreator, with absence facts, generated identity aliases and no implicit POS enrollment. */
class DesktopDashboardGoStoreCreation
{
    public const ROUTE='go-stores.store';
    private const OWNER=['id','added_by','name','mobile','email','account_type','app_scope','status','balance','delegate_fees','created_at','updated_at','email_verified_at','pending_vendor_id','owner_resturant_id'];
    private const PROFILE=['user_id','name','kind','address','revision','created_at','updated_at'];
    public static function handles(?string $name): bool {return $name===self::ROUTE;}
    private function original()
    {
        $route=app('router')->getRoutes()->getByName(self::ROUTE);abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\GoStores\\StoreController@store'
            &&$route->uri()==='admin/go-stores'&&$route->methods()===['POST']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409);return $route;
    }
    public function command(Request $request): string
    {
        abort_unless($request->isMethod('POST'),405);$header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409);$command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return $command;
    }
    private function values(array $values): array
    {
        abort_if(array_diff(array_keys($values),['owner_name','mobile','email','password','password_confirmation','name','kind','address','commission_rate','after_save','idempotency_key']),422);
        foreach($values as $value)abort_unless($value===null||is_scalar($value),422);Validator::make($values,['idempotency_key'=>'required|uuid'])->validate();return $values;
    }
    private function validated(Request $request): array
    {
        $form=CreateStoreRequest::createFrom($request,new CreateStoreRequest);$form->setContainer(app())->setRedirector(app('redirect'));$form->validateResolved();return $form->validated();
    }
    private function creatorIdentity(User $actor): array
    {
        $created=$actor->getRawOriginal('created_at');abort_unless(is_string($created)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$created),409,'هوية منشئ المتجر تحتاج مراجعة متصلة.');return ['id'=>(int)$actor->id,'created_at'=>$created];
    }
    private function absence(array $identity): array
    {
        $mobile=$identity['mobile'];$aliases=[$mobile,'0'.$mobile,'20'.$mobile,'+20'.$mobile,'0020'.$mobile];
        $owners=DB::table('users')->where('app_scope','go_partner')->where(function($q)use($aliases,$identity){$q->whereIn('mobile',$aliases);if($identity['email']!==null)$q->orWhere('email',$identity['email']);})->orderBy('id')->lockForUpdate()->get(['id']);
        $pending=DB::table('pending_vendors')->where('application_kind','partner')->whereIn('mobile',$aliases)->whereIn('status',['pending','accepted'])->orderBy('id')->lockForUpdate()->get(['id']);
        return ['identity'=>$identity,'owners'=>$owners->map(fn($r)=>(int)$r->id)->all(),'applications'=>$pending->map(fn($r)=>(int)$r->id)->all()];
    }
    private function facts(array $payload): array
    {
        abort_unless(array_keys($payload)===['parameters','values','files','facts']&&$payload['parameters']===[]&&$payload['files']===[]&&is_array($payload['values'])&&is_array($payload['facts']),422);$this->values($payload['values']);
        abort_unless(array_keys($payload['facts'])===['go_store_create_before']&&is_array($payload['facts']['go_store_create_before']),409);$before=$payload['facts']['go_store_create_before'];
        abort_unless(array_keys($before)===['creator','absence']&&is_array($before['creator'])&&array_keys($before['creator'])===['id','created_at']&&is_int($before['creator']['id'])&&$before['creator']['id']>0&&is_string($before['creator']['created_at'])
            &&is_array($before['absence'])&&array_keys($before['absence'])===['identity','owners','applications']&&$before['absence']['owners']===[]&&$before['absence']['applications']===[],409);
        $identity=$before['absence']['identity'];abort_unless(is_array($identity)&&array_keys($identity)===['mobile','email']&&is_string($identity['mobile'])&&preg_match('/^1[0125][0-9]{8}$/D',$identity['mobile'])&&($identity['email']===null||is_string($identity['email'])),409);return $before;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        abort_unless(self::handles($name),422);$this->original();$before=$this->facts($payload);$fresh=app(DesktopDashboardCreatedScopeAuthority::class)->authorize($name,$actor);
        abort_unless((int)$scope->actor_id===(int)$fresh->id&&$before['creator']===$this->creatorIdentity($fresh),403);abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        foreach(array_merge(['users','go_stores','pending_vendors','desktop_dashboard_devices','desktop_dashboard_commands','desktop_dashboard_entities'],array_values(config('permission.table_names',[]))) as $table){$row=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);abort_unless($row&&strcasecmp($row->engine,'InnoDB')===0,409);}return $fresh;
    }
    private function requestPayload(Request $request,string $command,User $actor,?array $saved): array
    {
        abort_unless($request->allFiles()===[],422);$values=$request->except('_token','_desktop_command');$values['idempotency_key']=$command;$values=$this->values($values);
        if($saved===null){$fresh=app(DesktopDashboardCreatedScopeAuthority::class)->authorize(self::ROUTE,$actor);$guard=auth('admin');$previous=$guard->getUser();try{$guard->setUser($fresh);$data=$this->validated($request);}finally{if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);}
            $absence=$this->absence(['mobile'=>$data['mobile'],'email'=>$data['email']??null]);abort_unless($absence['owners']===[]&&$absence['applications']===[],409,'هوية حساب المتجر مسجلة؛ افتح الحساب أو طلب الانضمام الموجود.');$saved=['go_store_create_before'=>['creator'=>$this->creatorIdentity($fresh),'absence'=>$absence]];}
        $payload=['parameters'=>[],'values'=>$values,'files'=>[],'facts'=>$saved];$this->facts($payload);return $payload;
    }
    public function payload(string $name,Request $request,string $command,User $actor): array
    {$device=(string)config('desktop_dashboard.device_id');$saved=app(DesktopDashboardJournal::class)->savedFacts($device,$command,(int)$actor->id,$name);$payload=$this->requestPayload($request,$command,$actor,$saved);$scope=app(DesktopDashboardRefresh::class)->lock($device);abort_unless($scope,403);$this->authorize($name,$payload,$actor,$scope);return $payload;}
    public function remotePayload(string $name,Request $request,string $command,User $actor,object $scope,?array $saved=null): array {$payload=$this->requestPayload($request,$command,$actor,$saved);$this->authorize($name,$payload,$actor,$scope);return $payload;}
    public function inputs(array $payload,callable $reference): array {return $payload;}
    public function validateReferences(array $payload): void {abort_if(is_array($payload['facts']['go_store_create_before']['creator']['id']??null),422);}
    private function after(int $id): array
    {
        $owner=DB::table('users')->where('id',$id)->lockForUpdate()->first(self::OWNER);$profile=DB::table('go_stores')->where('user_id',$id)->lockForUpdate()->first(self::PROFILE);abort_unless($owner&&$profile,409);return ['owner'=>(array)$owner,'profile'=>(array)$profile];
    }
    public function capture(string $name,array $payload,Request $request,callable $work): array
    {
        $before=$this->facts($payload);$old=$request->request->all();try{$values=$payload['values'];unset($values['idempotency_key']);if(isset($old['_token']))$values['_token']=$old['_token'];$request->request->replace($values);$data=$this->validated($request);
            abort_unless($this->absence(['mobile'=>$data['mobile'],'email'=>$data['email']??null])===$before['absence'],409,'هوية صاحب المتجر تغيّرت أو سبقتها عملية إنشاء.');
            $response=$work();if($response->getStatusCode()>=400||$request->hasSession()&&in_array('errors',$request->session()->get('_flash.new',[]),true))throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            $matches=DB::table('users')->where('app_scope','go_partner')->where('mobile',$data['mobile'])->lockForUpdate()->get(['id']);abort_unless(count($matches)===1,409);$id=(int)$matches[0]->id;$after=$this->after($id);
            $owner=User::withoutGlobalScopes()->findOrFail($id);abort_unless(Hash::check($data['password'],$owner->getRawOriginal('password')),409);
            $parts=parse_url($response->headers->get('Location')??'');$path=$parts['path']??null;abort_unless(in_array($response->getStatusCode(),[302,303],true)&&in_array($path,['/admin/go-stores/'.$id,'/admin/go-stores/'.$id.'/products/create'],true)&&!isset($parts['query'])&&!isset($parts['fragment']),409);
            $result=['http'=>['status'=>$response->getStatusCode(),'content'=>$response->getContent(),'type'=>$response->headers->get('Content-Type'),'location'=>$path],'success'=>session('success'),'after'=>$after,
                'references'=>['menu_store_owner'=>$id,'wallet_account'=>$id,'dashboard_user'=>$id],'created_scope'=>['kind'=>'gs','entity'=>'menu_store_owner','id'=>$id,'command_id'=>$payload['values']['idempotency_key']]];
            app(DesktopDashboardCreatedScopeAuthority::class)->validateCreated($name,$result,$payload,auth('admin')->user());$this->verifyResult($result);return $result;
        }finally{$request->request->replace($old);}
    }
    public function verifyResult(array $result): void
    {
        if(isset($result['result'])&&($result['committed']??null)===true)$result=$result['result'];$after=$result['after']??null;abort_unless(is_array($after)&&array_keys($after)===['owner','profile']&&is_array($after['owner'])&&is_array($after['profile'])
            &&array_keys($after['owner'])===self::OWNER&&array_keys($after['profile'])===self::PROFILE,409);
        $owner=$after['owner'];$id=$owner['id'];abort_unless(is_int($id)&&$id>0&&$owner['account_type']==='vendor'&&$owner['app_scope']==='go_partner'&&is_string($owner['created_at']),409);
        $current=DB::table('users')->where('id',$id)->lockForUpdate()->first(['created_at','added_by','app_scope','account_type']);abort_unless($current&&$current->created_at===$owner['created_at']&&(int)$current->added_by===$owner['added_by']&&$current->app_scope==='go_partner'&&$current->account_type==='vendor'
            &&DB::table('go_stores')->where('user_id',$id)->lockForUpdate()->first(['user_id']),403,'هوية حساب المتجر المنشأ تغيّرت.');
    }
    public function outputs(array $result): array {return isset($result['http'])?($result['references']??[]):[];}
    public function verifyAfter(array $server,array $local,array $resolved,array $original): void
    {
        $this->verifyResult($server);$a=$server['after'];$b=$local['after']??null;abort_unless(is_array($b)&&is_int($b['owner']['id']??null)&&$b['owner']['id']>0&&($b['profile']['user_id']??null)===$b['owner']['id'],409);$old=$b['owner']['id'];$new=$a['owner']['id'];
        foreach(['menu_store_owner','wallet_account','dashboard_user'] as $entity)abort_unless(($local['references'][$entity]??null)===$old&&($server['references'][$entity]??null)===$new,409);
        abort_unless(($local['created_scope']??null)===['kind'=>'gs','entity'=>'menu_store_owner','id'=>$old,'command_id'=>$original['values']['idempotency_key']],409);$b['owner']['id']=$new;$b['profile']['user_id']=$new;
        abort_unless(app(DesktopDashboardJournal::class)->fingerprint($a)===app(DesktopDashboardJournal::class)->fingerprint($b),409,'نتيجة إنشاء المتجر وصاحبه تختلف عن العملية المحلية.');
        $expected=($local['http']['location']??null)==='/admin/go-stores/'.$old.'/products/create'?'/admin/go-stores/'.$new.'/products/create':'/admin/go-stores/'.$new;abort_unless(($server['http']['location']??null)===$expected,409);
    }
    public function response(array $result) {$h=$result['http'];$r=response($h['content'],$h['status']);if($h['type'])$r->headers->set('Content-Type',$h['type']);$r->headers->set('Location',url($h['location']));if(isset($result['success']))session()->flash('success',$result['success']);return $r;}
    public function execute(string $name,array $payload,User $actor): array
    {
        $this->facts($payload);$original=$this->original();$values=$payload['values'];unset($values['idempotency_key']);$request=Request::create(url('/admin/go-stores'),'POST',$values);$session=new Store('desktop-go-store-creation-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{$app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');$route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($driver=null)=>auth($driver??'admin')->user());$router=app('router');
            $middleware=array_map(fn($m)=>MiddlewareNameResolver::resolve($m,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));return $this->capture($name,$payload,$request,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{$app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);}
    }
}
