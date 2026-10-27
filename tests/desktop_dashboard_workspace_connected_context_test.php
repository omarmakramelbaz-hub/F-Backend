<?php
// Actual context/Journal dispatch with synthetic authentication and HTTP adapters; no SQL/network.
namespace {require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';}
namespace App\Models {class User {public function __construct(public int $id){}}}
namespace App\Services\Dashboard {
    class DesktopDashboardWorkspaceAuthority implements DesktopDashboardWorkspaceIssuer {
        private object $cap;public bool $revoked=false;public int $checks=0;
        public function __construct(){$this->cap=new \stdClass;}
        public function authenticate($token):object{\abort_unless($token===str_repeat('b',64),401);return(object)['id'=>\ID,'custodian_actor_id'=>101];}
        public function rootFingerprint($root):string{return str_repeat('a',64);}
        public function scope($root,$actor,$grant,$revision):DesktopDashboardWorkspaceScope{
            \abort_unless($actor===202&&$grant===str_repeat('c',64)&&$revision===7&&!$this->revoked,403);
            return DesktopDashboardWorkspaceScope::issue($this,$this->cap,$root,new \App\Models\User($actor),
                ['actor_id'=>$actor,'branches'=>['f:1'],'permissions'=>['super_admin'=>false,'names'=>[],'roles'=>[]]],str_repeat('a',64),7);
        }
        public function assertIssuanceCapability(object $capability):void{\abort_unless($capability===$this->cap,403);}
        public function assertScope(DesktopDashboardWorkspaceScope $scope):void{$this->checks++;$scope->assertIssued();\abort_unless(!$this->revoked,403);}
        public function revalidateScope(DesktopDashboardWorkspaceScope $scope):DesktopDashboardWorkspaceScope{$this->assertScope($scope);return $scope;}
    }
    class DesktopDashboardWorkspaceRemoteAttempts {public function handle($request,$next){\check(DesktopDashboardWorkspaceExecutionContext::current()->actor_id===202,'Group dispatcher lost B context.');return 'group-remote';}public function authorizeDirect($scope,$request):void{\check(DesktopDashboardWorkspaceExecutionContext::current()->actor_id===$scope->actor_id,'Direct request lost typed scope.');}}
}
namespace {
    const ID='11111111-1111-4111-8111-111111111111';
    function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException('FIXED_'.$status);}
    function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
    function config($key){return $key==='desktop_dashboard.local'?$GLOBALS['local']:$GLOBALS['enabled'];}
    function app($class){if($class===\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::class)return $GLOBALS['authority'];return new $class;}
    function auth($guard){return $GLOBALS['guard'];}
    function check($condition,$message){$GLOBALS['checks']++;if(!$condition)throw new RuntimeException($message);}
    function refused(callable $work){try{$work();}catch(Throwable $error){check(\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::current()===null,'Refusal leaked a context.');return;}throw new RuntimeException('Invalid group HTTP context accepted.');}
    class Bag {private array $values=[];function has($key){return array_key_exists($key,$this->values);}function get($key){return $this->values[$key]??null;}function set($key,$value){$this->values[$key]=$value;}function remove($key){unset($this->values[$key]);}}
    class Guard {public ?object $actor=null;public int $logouts=0;function user(){return $this->actor;}function id(){return $this->actor->id??null;}function logout(){$this->logouts++;$this->actor=null;}}
    class Session {public bool $invalid=false,$regenerated=false;function invalidate(){$this->invalid=true;}function regenerateToken(){$this->regenerated=true;}}
    class Request {public Bag $attributes;public Session $storage;public bool $login=false;public array $headers;
        function __construct(array $headers){$this->headers=$headers;$this->attributes=new Bag;$this->storage=new Session;}
        function hasHeader($key){return array_key_exists($key,$this->headers);}function header($key){return $this->headers[$key]??null;}
        function isMethod($method){return $this->login&&$method==='POST';}function route(){return new class($this->login){function __construct(private bool $login){}function getName(){return $this->login?'admin.login':'dashboard.index';}function getActionName(){return $this->login?'App\\Http\\Controllers\\Dashboard\\HomeController@signin':'Controller@index';}};}
        function session(){return $this->storage;}
    }
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceScope.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceExecutionContext.php';
    require __DIR__.'/../app/Http/Middleware/DesktopDashboardWorkspaceConnectedContext.php';
    require __DIR__.'/../app/Http/Middleware/DesktopDashboardJournal.php';
    use App\Http\Middleware\DesktopDashboardWorkspaceConnectedContext as Connected;
    use App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext as Context;
    function environment():array{
        $GLOBALS['local']=false;$GLOBALS['enabled']=true;$GLOBALS['guard']=new Guard;$GLOBALS['guard']->actor=(object)['id'=>202];$GLOBALS['authority']=new \App\Services\Dashboard\DesktopDashboardWorkspaceAuthority;
        $headers=array_combine(Connected::HEADERS,[ID,str_repeat('b',64),'202','7',str_repeat('c',64),str_repeat('a',64)]);
        return [new Connected,new Request($headers),new \App\Http\Middleware\DesktopDashboardJournal];
    }
    $GLOBALS['checks']=0;
    [$middleware,$request,$journal]=environment();$value=$middleware->handle($request,fn($r)=>$journal->handle($r,function(){check(Context::current()->actor_id===202&&Context::current()->root()->custodian_actor_id===101,'Actual B/root custody distinction lost.');return 'connected';}));
    check($value==='connected'&&Context::current()===null,'Ordinary group connected context did not restore.');
    [$middleware,$request,$journal]=environment();$request->headers[Connected::ATTEMPT_HEADERS[0]]='22222222-2222-4222-8222-222222222222';$request->headers[Connected::ATTEMPT_HEADERS[1]]=str_repeat('d',64);
    check($middleware->handle($request,fn($r)=>$journal->handle($r,fn()=>'wrong'))==='group-remote','Group direct attempt reached v1 dispatch.');
    foreach(Connected::HEADERS as $header){[$middleware,$request]=environment();unset($request->headers[$header]);refused(fn()=>$middleware->handle($request,fn()=>'bad'));}
    foreach(['X-Fasakhansta-Connected-Device','X-Fasakhansta-Connected-Token','X-Fasakhansta-Remote-Attempt','X-Fasakhansta-Remote-Capability'] as $header){[$middleware,$request]=environment();$request->headers[$header]='legacy';refused(fn()=>$middleware->handle($request,fn()=>'bad'));}
    foreach(['+202','0202','202.0','9007199254740992','PRIVATE_ACTOR'] as $actor){[$middleware,$request]=environment();$request->headers[Connected::HEADERS[2]]=$actor;refused(fn()=>$middleware->handle($request,fn()=>'bad'));}
    foreach([0,1,2,3,4,5] as $index){[$middleware,$request]=environment();$request->headers[Connected::HEADERS[$index]]=$index===2?'101':($index===3?'8':str_repeat('e',64));refused(fn()=>$middleware->handle($request,fn()=>'bad'));}
    [$middleware,$request]=environment();$GLOBALS['guard']->actor=(object)['id'=>101];refused(fn()=>$middleware->handle($request,fn()=>'bad'));
    [$middleware,$request]=environment();$request->headers[Connected::ATTEMPT_HEADERS[0]]='22222222-2222-4222-8222-222222222222';refused(fn()=>$middleware->handle($request,fn()=>'bad'));
    [$middleware,$request]=environment();$GLOBALS['local']=true;refused(fn()=>$middleware->handle($request,fn()=>'bad'));
    [$middleware,$request]=environment();$GLOBALS['enabled']=false;refused(fn()=>$middleware->handle($request,fn()=>'bad'));
    [$middleware,$request,$journal]=environment();$request->login=true;$GLOBALS['guard']->actor=null;
    $value=$middleware->handle($request,fn($r)=>$journal->handle($r,function()use($request){check(Context::current()===null,'Password controller received actor authority before password verification.');check($request->attributes->get(Connected::SIGNIN_ATTRIBUTE) instanceof \App\Services\Dashboard\DesktopDashboardWorkspaceScope,'Login permit is not issued typed scope.');$GLOBALS['guard']->actor=(object)['id'=>202];return 'password';}));
    check($value==='password'&&!$request->attributes->has(Connected::SIGNIN_ATTRIBUTE)&&Context::current()===null,'Successful B login leaked its permit.');
    [$middleware,$request,$journal]=environment();$request->login=true;$GLOBALS['guard']->actor=null;
    check($middleware->handle($request,fn($r)=>$journal->handle($r,fn()=>'wrong-password'))==='wrong-password','Original password failure was rewritten.');
    [$middleware,$request,$journal]=environment();$request->login=true;$GLOBALS['guard']->actor=null;
    refused(fn()=>$middleware->handle($request,fn($r)=>$journal->handle($r,function(){$GLOBALS['guard']->actor=(object)['id'=>101];return 'wrong-account';})));
    check($GLOBALS['guard']->actor===null&&$request->storage->invalid&&$request->storage->regenerated,'Mismatched actual password session survived refusal.');
    [$middleware,$request]=environment();refused(fn()=>$middleware->handle($request,function(){throw new RuntimeException('Controller failed.');}));
    [$middleware,$request,$journal]=environment();$request->headers=[];
    check($middleware->handle($request,fn($r)=>$journal->handle($r,fn()=>'legacy'))==='legacy','Header-free v1 pipeline changed.');
    echo 'PASS '.$GLOBALS['checks'].' typed workspace connected/session/dispatch checks'.PHP_EOL;
}
