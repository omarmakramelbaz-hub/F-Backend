<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Session\{Store,ArraySessionHandler};
use Illuminate\Support\Facades\{DB,Facade,Validator};

/** Preserve original wallet forms and their cash ledger, without opening a gateway checkout. */
class DesktopDashboardWalletCash
{
    private const ROUTES=[
        'wallets.transfer'=>['store','admin/wallets/transfer','admin/wallets'],
        'wallets.withdraw.store'=>['store_withdraw','admin/wallets/withdraw/store','admin/wallet/withdraw'],
    ];
    private const USER=['id','account_type','app_scope','owner_resturant_id','pending_vendor_id','mobile','email','created_at','balance','min_wallet','status'];
    private const IDENTITY=['id','account_type','app_scope','owner_resturant_id','pending_vendor_id','mobile','email','created_at'];
    public static function handles(?string $route): bool {return isset(self::ROUTES[$route??'']);}
    private function original(string $name)
    {
        abort_unless(self::handles($name),422);$route=app('router')->getRoutes()->getByName($name);[$action,$uri]=self::ROUTES[$name];
        abort_unless($route&&$route->getActionName()==='App\\Http\\Controllers\\Dashboard\\WalletController@'.$action
            &&$route->uri()===$uri&&$route->methods()===['POST']&&in_array('IsAdmin',$route->gatherMiddleware(),true),409,'مسار المحفظة الأصلي تغيّر.');
        return $route;
    }
    public function command(Request $request): string
    {
        $header=$request->header('X-Fasakhansta-Command');$form=$request->input('_desktop_command');
        foreach([$header,$form] as $value)if($value!==null)Validator::make(['command'=>$value],['command'=>'required|uuid'])->validate();
        abort_if($header!==null&&$form!==null&&$header!==$form,409,'رقم طلب العملية مختلف عن سجلها.');
        $command=$header??$form;Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return (string)$command;
    }
    private function target(string $name,array $values): int
    {
        $v=app(WalletCashOperations::class)->values($name,$values);
        return (int)$v[$name==='wallets.transfer'?'to_user':'from_user'];
    }
    private function state(int $id): array
    {
        $user=DB::table('users')->where('id',$id)->lockForUpdate()->first(self::USER);
        abort_unless($user&&$user->account_type!=='admin',409,'هوية المحفظة لم تعد صالحة للمراجعة.');
        $ledger=DB::table('wallets')->where(fn($q)=>$q->where('from_user',$id)->orWhere('to_user',$id))->orderBy('id')->lockForUpdate()->get();
        return ['user'=>(array)$user,'ledger'=>$ledger->map(fn($row)=>(array)$row)->all()];
    }
    private function facts(string $name,array $payload): array
    {
        abort_if(array_diff(array_keys($payload),['parameters','values','files','facts']),422);
        abort_unless(($payload['parameters']??[])===[]&&empty($payload['files']),422);
        $id=$this->target($name,$payload['values']??[]);$before=$payload['facts']['wallet_cash_before']??null;
        abort_unless(array_keys($payload['facts']??[])===['wallet_cash_before']&&is_array($before)
            &&array_keys($before)===['user','ledger']&&is_array($before['user'])&&is_array($before['ledger'])
            &&count($before['user'])===count(self::USER)&&array_diff(array_keys($before['user']),self::USER)===[]
            &&(int)($before['user']['id']??0)===$id,409,'بيانات المحفظة المحلية غير مكتملة.');
        return $before;
    }
    public function authorize(string $name,array $payload,User $actor,object $scope): User
    {
        $before=$this->facts($name,$payload);$this->original($name);
        $fresh=app(WalletCashOperations::class)->authorize($actor);
        if((int)$fresh->id!==1){
            app(WalletCashOperations::class)->requirePermission($fresh,'order-list');
            // Only all-administration snapshots contain the complete wallet ledger.
            // A wallet-create grant alone cannot make an omitted ledger complete.
            $roles=DB::table(config('permission.table_names.model_has_roles'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->lockForUpdate()->pluck('role_id');
            $super=DB::table(config('permission.table_names.roles'))->whereIn('id',$roles)->where('guard_name','admin')->where('name','Super Admin')->lockForUpdate()->first();
            abort_unless($super,403,'بيانات المحفظة الكاملة متاحة في نسخة الإدارة الشاملة فقط.');
        }
        abort_unless((int)$scope->actor_id===(int)$fresh->id,403);
        abort_if(isset($scope->enabled)&&!$scope->enabled,401);
        // The original cash form is a central-admin action. An enrolled branch
        // administrator cannot use a formerly central saved reply after reassignment.
        $branches=json_decode($scope->branches,true,512,JSON_THROW_ON_ERROR);abort_unless(is_array($branches)&&count($branches)>0,403);
        foreach($branches as $branch)app(DesktopDashboardDevices::class)->branch($scope,$branch,$fresh);
        $identity=DB::table('users')->where('id',$this->target($name,$payload['values']))->lockForUpdate()->first(self::IDENTITY);
        $expected=array_intersect_key($before['user'],array_flip(self::IDENTITY));
        abort_unless($identity&&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($expected),
            app(DesktopDashboardJournal::class)->fingerprint((array)$identity)),409,'حساب المحفظة تغيّر؛ العملية محفوظة للمراجعة.');
        return $fresh;
    }
    public function payload(string $name,Request $request,string $command,User $actor,?object $scope=null,?array $saved=null): array
    {
        abort_if($request->allFiles(),501);abort_unless($request->route()->parameters()===[],422);
        $values=$request->except('_token','_desktop_command');
        abort_if(isset($values['idempotency_key'])&&$values['idempotency_key']!==$command,409,'رقم طلب المحفظة مختلف عن سجلها.');
        $values['idempotency_key']=$command;
        $values=app(WalletCashOperations::class)->values($name,$values);
        $scope=$scope??app(DesktopDashboardRefresh::class)->lock((string)config('desktop_dashboard.device_id'));abort_unless($scope,403);
        $saved=$saved??app(DesktopDashboardJournal::class)->savedFacts((string)config('desktop_dashboard.device_id'),$command,(int)$actor->id,$name);
        $payload=['parameters'=>[],'values'=>$values,'files'=>[],'facts'=>$saved??['wallet_cash_before'=>$this->state($this->target($name,$values))]];
        $this->authorize($name,$payload,$actor,$scope);return $payload;
    }
    private function referencePaths(array $payload): array
    {
        $paths=['values.from_user'=>'wallet_account','values.to_user'=>'wallet_account','facts.wallet_cash_before.user.id'=>'wallet_account',
            'facts.wallet_cash_before.user.owner_resturant_id'=>'menu_restaurant','facts.wallet_cash_before.user.pending_vendor_id'=>'pending_vendor'];
        foreach($payload['facts']['wallet_cash_before']['ledger']??[] as $i=>$row){
            foreach(['id'=>'wallet_cash_movement','from_user'=>'wallet_account','to_user'=>'wallet_account','order_id'=>'legacy_order'] as $key=>$entity)
                $paths['facts.wallet_cash_before.ledger.'.$i.'.'.$key]=$entity;
        }
        return $paths;
    }
    public function inputs(array $payload,callable $reference): array
    {
        foreach($this->referencePaths($payload) as $path=>$entity){$value=data_get($payload,$path);if($value!==null&&!is_array($value))data_set($payload,$path,$reference($entity,$value));}
        return $payload;
    }
    public function validateReferences(array $payload): void
    {
        foreach($this->referencePaths($payload) as $path=>$entity){$value=data_get($payload,$path);if(is_array($value))
            abort_unless(count($value)===1&&is_array($value['$desktop_ref']??null)
                &&preg_match('/^'.preg_quote($entity,'/').'(?:\.[0-9]{1,3})?$/D',$value['$desktop_ref']['entity']??''),422,'نوع مرجع المحفظة غير متطابق.');}
    }
    public function outputs(array $result): array
    {
        $row=$result['wallet']??[];abort_unless(isset($row['wallet_id'],$row['user_id'])&&(int)$row['wallet_id']>0&&(int)$row['user_id']>0,409);
        return ['wallet_cash_movement'=>(int)$row['wallet_id'],'wallet_account'=>(int)$row['user_id']];
    }
    public function capture(string $name,Request $request,callable $work): array
    {
        // Original forms have no business idempotency input. Bind their native
        // command header/form UUID to the exact ledger reference before dispatch.
        $command=$request->input('idempotency_key')??$this->command($request);
        Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();
        $request->request->set('idempotency_key',$command);
        $response=$work();abort_unless(in_array($response->getStatusCode(),[302,303],true),409);
        $wallet=$request->attributes->get('desktop_wallet_result');abort_unless(is_array($wallet),409);
        $location='/'.self::ROUTES[$name][2];
        return ['http'=>['status'=>$response->getStatusCode(),'content'=>$response->getContent(),
            'type'=>$response->headers->get('Content-Type'),'location'=>$location],
            'success'=>session('success'),'wallet'=>$wallet];
    }
    public function response(array $result)
    {
        $http=$result['http'];$response=response($http['content'],$http['status']);
        if($http['type'])$response->headers->set('Content-Type',$http['type']);$response->headers->set('Location',url($http['location']));
        if(isset($result['success']))session()->flash('success',$result['success']);return $response;
    }
    public function verifyAfter(array $result,array $local): void
    {
        $fields=['kind','amount','balance_before','balance_after','status_after','transfer_reference'];
        $facts=fn($value)=>array_intersect_key($value['wallet']??[],array_flip($fields));
        abort_unless(count($facts($result))===count($fields)&&hash_equals(app(DesktopDashboardJournal::class)->fingerprint($facts($result)),
            app(DesktopDashboardJournal::class)->fingerprint($facts($local))),409,'نتيجة المحفظة مختلفة عن العملية المحلية؛ احتفظ بالعملية للمراجعة.');
    }
    public function execute(string $name,array $payload,User $actor): array
    {
        $before=$this->facts($name,$payload);
        abort_unless(hash_equals(app(DesktopDashboardJournal::class)->fingerprint($before),
            app(DesktopDashboardJournal::class)->fingerprint($this->state($this->target($name,$payload['values'])))),409,'الرصيد أو سجل المحفظة تغيّرا؛ العملية المحلية محفوظة للمراجعة.');
        $original=$this->original($name);$request=Request::create(url(self::ROUTES[$name][1]),'POST',$payload['values']);
        $request->headers->set('Referer',url(self::ROUTES[$name][2]));
        $session=new Store('desktop-wallet-replay',new ArraySessionHandler(60));$session->start();$session->put(['id_user'=>(int)$actor->id,'guard'=>'admin','lang_code'=>app()->getLocale()]);$request->setLaravelSession($session);
        $app=app();$oldRequest=$app['request'];$oldSession=$app['session'];$oldStore=$app['session.store'];$guard=auth('admin');$previous=$guard->getUser();$default=auth()->getDefaultDriver();
        try{
            $app->instance('request',$request);$app->instance('session',$session);$app->instance('session.store',$session);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($request);$guard->setUser($actor);auth()->shouldUse('admin');
            $route=clone $original;$route->flushController();$route->bind($request);$request->setRouteResolver(fn()=>$route);$request->setUserResolver(fn($name=null)=>auth($name??'admin')->user());
            $router=app('router');$middleware=array_map(fn($item)=>MiddlewareNameResolver::resolve($item,$router->getMiddleware(),$router->getMiddlewareGroups()),array_merge(['IsAdmin'],$route->controllerMiddleware()));
            return $this->capture($name,$request,fn()=>(new Pipeline($app))->send($request)->through($middleware)->then(fn($request)=>$router->prepareResponse($request,$route->run())));
        }finally{
            $app->instance('request',$oldRequest);$app->instance('session',$oldSession);$app->instance('session.store',$oldStore);Facade::clearResolvedInstance('request');Facade::clearResolvedInstance('session');$app['url']->setRequest($oldRequest);
            if($previous)$guard->setUser($previous);else (function(){$this->user=null;})->call($guard);auth()->shouldUse($default);
        }
    }
}
