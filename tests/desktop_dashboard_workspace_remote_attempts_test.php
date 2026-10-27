<?php
// Actual group wrapper, issued Scope/Context and HTTP dispatch; original business engine/SQL are isolated observers.
namespace {require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';}
namespace App\Models {class User {public function __construct(public int $id){}}}
namespace Illuminate\Http {
    class Request {
        public \RemoteBag $attributes;
        public function __construct(public array $headers,public string $verb='POST',public string $url='/admin/products',public string $routeName='products.store'){$this->attributes=new \RemoteBag;}
        public function hasHeader($key){return array_key_exists($key,$this->headers);}
        public function header($key){return $this->headers[$key]??null;}
        public function isMethod($method){return $this->verb===$method;}
        public function server($key){return $key==='REQUEST_METHOD'?$this->verb:null;}
        public function path(){return ltrim($this->url,'/');}
        public function route(){return new class($this->routeName) {function __construct(private string $name){}function getName(){return $this->name;}function getActionName(){return 'Controller@store';}};}
    }
}
namespace Illuminate\Support\Facades {
    class DB {
        public static array $rows=[];public static int $level=0,$writes=0,$reads=0;
        public static function transaction($work){$saved=array_map(fn($row)=>clone $row,self::$rows);self::$level++;try{return $work();}catch(\Throwable $error){self::$rows=$saved;throw $error;}finally{self::$level--;}}
        public static function table($name){\checkRemote($name==='desktop_dashboard_remote_attempts','Group remote transport never resolves a legacy physical actor row');return new \RemoteQuery;}
    }
    class Crypt {public static function encryptString($value){return base64_encode($value);}public static function decryptString($value){$out=base64_decode($value,true);if($out===false)throw new \RuntimeException('Bad cipher');return $out;}}
    class Validator {
        public static function make($values,$rules){return new class($values){public function __construct(private array $v){}public function validate(){
            \abort_unless(is_string($this->v['id']??null)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$this->v['id'])
                &&in_array($this->v['method']??null,['GET','POST','PUT','PATCH','DELETE'],true)&&is_string($this->v['path']??null)&&strlen($this->v['path'])<=200,422);return $this->v;
        }};}
    }
}
namespace App\Services\Dashboard {
    class DesktopDashboardRoutes {public static function journaled($route){return $route==='products.store';}}
    class DesktopDashboardWorkspaceAuthority implements DesktopDashboardWorkspaceIssuer {
        private object $cap;public array $current=[101=>true,202=>true];public bool $enabled=true;public int $scopeChecks=0,$historicalChecks=0;
        public function __construct(){$this->cap=new \stdClass;}
        public function root(){return(object)['id'=>\REMOTE_ROOT,'custodian_actor_id'=>101,'fingerprint'=>str_repeat('a',64),'enabled'=>true];}
        public function rootFingerprint($root){\abort_unless(($root->fingerprint??null)===str_repeat('a',64),401);return $root->fingerprint;}
        public function authenticate($token){\abort_unless($token===str_repeat('b',64)&&$this->enabled,401);return $this->root();}
        public function lockAuthenticated($root){\abort_unless($this->enabled&&$root->id===\REMOTE_ROOT,401);$this->rootFingerprint($root);return clone $root;}
        public function scope($root,$actor,$grant,$revision):DesktopDashboardWorkspaceScope {
            $this->lockAuthenticated($root);\abort_unless(isset($this->current[$actor])&&$grant===str_repeat($actor===101?'1':'2',64)&&$revision===7,403);
            return DesktopDashboardWorkspaceScope::issue($this,$this->cap,$root,new \App\Models\User($actor),[
                'actor_id'=>$actor,'enabled'=>true,'role'=>'vendor','grant'=>$grant,'branches'=>['f:100'],
                'permissions'=>['super_admin'=>false,'names'=>[],'roles'=>[]]],str_repeat('a',64),7);
        }
        public function validateHistoricalMember($root,$actor,$grant,$revision){$this->historicalChecks++;$this->lockAuthenticated($root);
            \abort_unless(in_array($actor,[101,202],true)&&$revision===3&&$grant===str_repeat($actor===101?'3':'4',64),403);
            return ['actor_id'=>$actor,'grant'=>$grant];}
        public function assertIssuanceCapability(object $capability):void{\abort_unless($capability===$this->cap,403);}
        public function assertScope(DesktopDashboardWorkspaceScope $scope):void {$this->scopeChecks++;$scope->assertIssued();$this->lockAuthenticated($scope->root());
            \abort_unless(isset($this->current[$scope->actor_id])&&$scope->manifestRevision()===7&&$scope->rootFingerprint()===str_repeat('a',64),403);}
        public function revalidateScope(DesktopDashboardWorkspaceScope $scope):DesktopDashboardWorkspaceScope{$this->assertScope($scope);return $scope;}
        public static function canonical($value){return json_encode($value,JSON_THROW_ON_ERROR);}
    }
    class DesktopDashboardDevices {
        public int $actorCalls=0;
        public function lockAuthenticated($scope){\abort_unless($scope instanceof DesktopDashboardWorkspaceScope,403);$scope->assertIssued();return $scope;}
        public function actor($scope){$this->actorCalls++;return $scope->actor();}
        public function enrollmentFingerprint($scope){\abort_unless($scope instanceof DesktopDashboardWorkspaceScope,403);return $scope->rootFingerprint();}
    }
    class DesktopDashboardRemoteAttempts {
        public static int $decisions=0,$handlers=0,$business=0;public static ?object $lastScope=null;
        public function __construct(private DesktopDashboardDevices $devices){}
        public static function cap($row){return hash('sha256',$row->id.'|'.$row->device_id.'|'.$row->actor_id.'|'.$row->method.'|'.$row->path);}
        public function decide(object $device,array $values,bool $cancel):array {
            self::$decisions++;$v=\Illuminate\Support\Facades\Validator::make($values,[])->validate();\abort_unless($v['path']==='/admin/products',422);
            $this->devices->lockAuthenticated($device);$this->devices->actor($device);$row=\Illuminate\Support\Facades\DB::table('desktop_dashboard_remote_attempts')->where('id',$v['id'])->first();
            if(!$row){\Illuminate\Support\Facades\DB::table('desktop_dashboard_remote_attempts')->insert(['id'=>$v['id'],'device_id'=>$device->id,'actor_id'=>$device->actor_id,
                'method'=>$v['method'],'path'=>$v['path'],'status'=>$cancel?'cancelled':'ready','response_cipher'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode(['enrollment_binding'=>['format'=>1,'fingerprint'=>$this->devices->enrollmentFingerprint($device)]]))]);
                $row=\Illuminate\Support\Facades\DB::table('desktop_dashboard_remote_attempts')->where('id',$v['id'])->first();}
            \abort_unless($row->device_id===$device->id&&(int)$row->actor_id===$device->actor_id&&$row->method===$v['method']&&$row->path===$v['path'],409);
            if($cancel&&$row->status==='ready'){\Illuminate\Support\Facades\DB::table('desktop_dashboard_remote_attempts')->where('id',$row->id)->update(['status'=>'cancelled']);$row->status='cancelled';}
            return ['format'=>1,'id'=>$row->id,'device_id'=>$row->device_id,'actor_id'=>$row->actor_id,'method'=>$row->method,'path'=>$row->path,'status'=>$row->status,'capability'=>$cancel?null:self::cap($row)];
        }
        public function handle(\Illuminate\Http\Request $request,callable $next){self::$handlers++;[$id,$cap]=$this->attemptHeaders($request);
            $row=\Illuminate\Support\Facades\DB::table('desktop_dashboard_remote_attempts')->where('id',$id)->first();\abort_unless($row,409);$scope=$this->resolveDevice($row);self::$lastScope=$scope;
            \abort_unless($row->method===$request->verb&&$row->path===$request->url&&hash_equals(self::cap($row),$cap),403);
            \abort_unless($row->status!=='cancelled',409);if($row->status==='committed')return 'saved-original-result';
            \abort_unless($row->status==='ready',409);self::$business++;$response=$next($request);
            \Illuminate\Support\Facades\DB::table('desktop_dashboard_remote_attempts')->where('id',$id)->update(['status'=>'committed']);return $response;
        }
        protected function attemptHeaders(\Illuminate\Http\Request $request):array{return [$request->header('X-Fasakhansta-Remote-Attempt'),$request->header('X-Fasakhansta-Remote-Capability')];}
        protected function resolveDevice(object $row):object{throw new \LogicException('Legacy physical actor lookup used');}
    }
}
namespace {
    use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceRemoteAttempts as Attempts,
        DesktopDashboardWorkspaceExecutionContext as Context,DesktopDashboardRemoteAttempts as Engine};
    use App\Http\Middleware\DesktopDashboardWorkspaceConnectedContext as Connected;
    use Illuminate\Support\Facades\{DB,Crypt};
    const REMOTE_ROOT='11111111-1111-4111-8111-111111111111';
    const ATTEMPT_A='22222222-2222-4222-8222-222222222222';
    const ATTEMPT_B='33333333-3333-4333-8333-333333333333';
    const ATTEMPT_LOST='44444444-4444-4444-8444-444444444444';
    $checks=0;
    function checkRemote($ok,$label){global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
    function abort($status,$message=''){throw new RuntimeException('FIXED_'.$status,$status);}
    function abort_unless($ok,$status,$message=''){if(!$ok)abort($status,$message);}
    function abort_if($ok,$status,$message=''){abort_unless(!$ok,$status,$message);}
    function config($key){return match($key){'desktop_dashboard.local'=>false,'desktop_dashboard.workspace_enabled'=>true,'app.key'=>'synthetic-key',default=>null};}
    function now($zone){return '2026-10-27 12:00:00';}
    function auth($guard){return $GLOBALS['remote_guard'];}
    function app($class){return $GLOBALS['remote_services'][$class]??=new $class;}
    function refusal($work,int $status,$label){try{$work();}catch(RuntimeException $error){checkRemote($error->getCode()===$status,$label.' exact refusal');checkRemote(Context::current()===null,$label.' restores authority');return;}throw new RuntimeException($label.' was accepted');}
    class RemoteBag {private array $v=[];function has($key){return array_key_exists($key,$this->v);}function get($key){return $this->v[$key]??null;}function set($key,$value){$this->v[$key]=$value;}function remove($key){unset($this->v[$key]);}}
    class RemoteGuard {public ?object $actor=null;function id(){return $this->actor->id??null;}function user(){return $this->actor;}}
    class RemoteQuery {
        private array $where=[];
        public function where($key,$op,$value=null){$this->where[]=[$key,$value===null?'=':$op,$value===null?$op:$value];return $this;}
        public function lockForUpdate(){return $this;}
        private function matches($row){foreach($this->where as [$key,$op,$value]){if($op==='='&&($row->$key??null)!==$value)return false;if($op==='<>')if(($row->$key??null)===$value)return false;}return true;}
        public function first(){DB::$reads++;foreach(DB::$rows as $row)if($this->matches($row))return clone $row;return null;}
        public function exists(){return $this->first()!==null;}
        public function insert($row){DB::$writes++;abort_unless(!isset(DB::$rows[$row['id']]),409);DB::$rows[$row['id']]=(object)$row;}
        public function update($values){DB::$writes++;foreach(DB::$rows as $row)if($this->matches($row))foreach($values as $key=>$value)$row->$key=$value;}
    }
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceScope.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceExecutionContext.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceRemoteAttempts.php';
    require __DIR__.'/../app/Http/Middleware/DesktopDashboardWorkspaceConnectedContext.php';
    require __DIR__.'/../app/Http/Middleware/DesktopDashboardJournal.php';
    function setupRemote():array {
        DB::$rows=[];DB::$level=DB::$writes=DB::$reads=0;Engine::$decisions=Engine::$handlers=Engine::$business=0;Engine::$lastScope=null;
        $authority=new Authority;$devices=new \App\Services\Dashboard\DesktopDashboardDevices;$remote=new Attempts($devices,$authority);
        $GLOBALS['remote_services']=[Authority::class=>$authority,Attempts::class=>$remote];$GLOBALS['remote_guard']=new RemoteGuard;$GLOBALS['remote_guard']->actor=new \App\Models\User(202);
        return [$authority,$devices,$remote];
    }
    function valuesRemote($id=ATTEMPT_B){return ['id'=>$id,'method'=>'POST','path'=>'/admin/products'];}
    function requestRemote($result,$actor=202):\Illuminate\Http\Request {
        $headers=array_combine(Connected::HEADERS,[REMOTE_ROOT,str_repeat('b',64),(string)$actor,'7',str_repeat($actor===101?'1':'2',64),str_repeat('a',64)]);
        $headers['X-Fasakhansta-Workspace-Attempt']=$result['id'];$headers['X-Fasakhansta-Workspace-Capability']=$result['capability'];return new \Illuminate\Http\Request($headers);
    }
    function executeRemote($request,$next){return (new Connected)->handle($request,fn($r)=>(new \App\Http\Middleware\DesktopDashboardJournal)->handle($r,$next));}
    function pairfreeRemote($route='dashboard.index',$verb='GET',$path='/admin'): \Illuminate\Http\Request {
        $request=requestRemote(['id'=>ATTEMPT_B,'capability'=>str_repeat('f',64)]);
        unset($request->headers['X-Fasakhansta-Workspace-Attempt'],$request->headers['X-Fasakhansta-Workspace-Capability']);
        $request->routeName=$route;$request->verb=$verb;$request->url=$path;return $request;
    }
    [$authority,$devices,$remote]=setupRemote();$scopeA=$authority->scope($authority->root(),101,str_repeat('1',64),7);$scopeB=$authority->scope($authority->root(),202,str_repeat('2',64),7);
    $a=$remote->decideWorkspace($scopeA,valuesRemote(ATTEMPT_A),false);checkRemote($a['actor_id']===101&&$a['workspace']['workspace_id']===REMOTE_ROOT,'A reservation binds actual actor and one physical workspace');
    $before=Engine::$decisions;refusal(fn()=>$remote->decideWorkspace($scopeB,valuesRemote(),false),409,'Unresolved A globally fences new B reservation');
    checkRemote(Engine::$decisions===$before&&!isset(DB::$rows[ATTEMPT_B]),'Global fence refuses before original engine can reserve B');
    refusal(fn()=>executeRemote(pairfreeRemote(),fn()=>throw new RuntimeException('B page bypassed A fence')),409,'Pair-free B page also respects unresolved A');
    refusal(fn()=>executeRemote(pairfreeRemote('provider.send','POST','/admin/provider'),fn()=>throw new RuntimeException('Provider bypassed A fence')),409,'Pair-free provider traffic also respects physical fence');
    refusal(fn()=>executeRemote(pairfreeRemote('products.store','POST','/admin/products'),fn()=>throw new RuntimeException('B write lacked reservation')),422,'Known B mutator cannot omit the reservation pair');
    $proofA=['actor_id'=>101,'manifest_revision'=>3,'grant'=>str_repeat('3',64)];unset($authority->current[101]);
    $before=[Engine::$decisions,Engine::$handlers,Engine::$business,$devices->actorCalls];$settled=$remote->settleWorkspace($authority->root(),$proofA,valuesRemote(ATTEMPT_A));
    checkRemote($settled['status']==='cancelled'&&$settled['actor_id']===101&&$settled['capability']===null,'Historical retired A may only cancel its own ready reservation');
    checkRemote($before===[Engine::$decisions,Engine::$handlers,Engine::$business,$devices->actorCalls],'Historical terminal recovery performs no business/current-actor engine callback');
    checkRemote(executeRemote(pairfreeRemote(),fn()=>'harmless-original-page')==='harmless-original-page','Pair-free harmless original page works after all reservations are terminal');
    refusal(fn()=>executeRemote(pairfreeRemote('products.store','POST','/admin/products'),fn()=>throw new RuntimeException('Known B write bypassed pair')),422,'Known B mutator requires a reservation even without an unresolved sibling');
    $b=$remote->decideWorkspace($scopeB,valuesRemote(),false);checkRemote($b['format']===2&&$b['actor_id']===202&&is_string($b['capability']),'B reserves as B after physical fence is terminal');
    DB::$rows[ATTEMPT_A]->status='ready';$handlers=Engine::$handlers;
    refusal(fn()=>executeRemote(requestRemote($b),fn()=>throw new RuntimeException('B bypassed unresolved A')),409,'Unresolved A also globally fences existing B execution');
    checkRemote(Engine::$handlers===$handlers&&Engine::$business===0,'Execution fence runs before the original business engine');DB::$rows[ATTEMPT_A]->status='cancelled';
    $request=requestRemote($b);$answer=executeRemote($request,function(){checkRemote(Context::current()->actor_id===202&&Context::current()->root()->custodian_actor_id===101,'Business context is B and root custody remains A');return 'business-once';});
    checkRemote($answer==='business-once'&&Engine::$business===1&&Engine::$lastScope!==$scopeB,'Original engine executes with a newly issued B scope');
    checkRemote(Engine::$lastScope->actor_id===202&&Context::current()===null,'B context is restored after original engine');
    checkRemote(executeRemote(requestRemote($b),fn()=>throw new RuntimeException('Duplicate business callback'))==='saved-original-result'&&Engine::$business===1,'Lost reply reuses original committed result without reexecution');
    foreach(['actor','root','method','path','capability','legacy'] as $bad){$request=requestRemote($b);
        if($bad==='actor')$GLOBALS['remote_guard']->actor=new \App\Models\User(101);
        elseif($bad==='root')$request->headers[Connected::HEADERS[5]]=str_repeat('f',64);
        elseif($bad==='method')$request->verb='DELETE';elseif($bad==='path')$request->url='/admin/products/999';
        elseif($bad==='capability')$request->headers['X-Fasakhansta-Workspace-Capability']=str_repeat('f',64);
        else $request->headers['X-Fasakhansta-Remote-Attempt']='legacy';
        refusal(fn()=>executeRemote($request,fn()=>throw new RuntimeException('Forged request reached business')),in_array($bad,['root'],true)?401:($bad==='legacy'?422:403),'Forged B transport '.$bad);
        $GLOBALS['remote_guard']->actor=new \App\Models\User(202);
    }
    // Historical settlement ignores BOTH the removed current A and current B; it creates no execution capability.
    $GLOBALS['remote_guard']->actor=null;unset($authority->current[202]);$lost=$remote->settleWorkspace($authority->root(),$proofA,valuesRemote(ATTEMPT_LOST));
    checkRemote($lost['status']==='cancelled'&&$lost['capability']===null&&$lost['actor_id']===101,'Lost reserve reply creates historical A cancellation tombstone with no current session');
    $committed=(object)['id'=>ATTEMPT_A,'device_id'=>REMOTE_ROOT,'actor_id'=>101,'method'=>'POST','path'=>'/admin/products','status'=>'committed',
        'response_cipher'=>Crypt::encryptString(json_encode(['enrollment_binding'=>['format'=>1,'fingerprint'=>str_repeat('a',64)],'status'=>200,'operation'=>ATTEMPT_A,
            'content'=>'PRIVATE_SAVED_BUSINESS_RESPONSE'])),'request_hash'=>str_repeat('e',64)];
    DB::$rows[ATTEMPT_A]=$committed;$original=clone $committed;$writes=DB::$writes;
    $done=$remote->settleWorkspace($authority->root(),$proofA,valuesRemote(ATTEMPT_A));
    checkRemote($done['status']==='committed'&&$done['capability']===null&&DB::$rows[ATTEMPT_A]==$original&&DB::$writes===$writes,'Historical committed A is unchanged and receives no new execution grant');
    checkRemote(!str_contains(json_encode($done),'PRIVATE_SAVED_BUSINESS_RESPONSE'),'Terminal recovery exposes no stored business response');
    $originalCipher=DB::$rows[ATTEMPT_A]->response_cipher;
    DB::$rows[ATTEMPT_A]->response_cipher=Crypt::encryptString(json_encode(['enrollment_binding'=>['format'=>1,'fingerprint'=>str_repeat('f',64)]]));
    refusal(fn()=>$remote->settleWorkspace($authority->root(),$proofA,valuesRemote(ATTEMPT_A)),401,'Stored result from another physical root cannot be settled');
    DB::$rows[ATTEMPT_A]->response_cipher='not-encrypted';refusal(fn()=>$remote->settleWorkspace($authority->root(),$proofA,valuesRemote(ATTEMPT_A)),503,'Broken stored binding requires review');
    DB::$rows[ATTEMPT_A]->response_cipher=$originalCipher;
    foreach(['request_hash','missing_status','invalid_status','string_status','operation','missing_content','oversized_content'] as $bad){
        $saved=json_decode(Crypt::decryptString($originalCipher),true);$hash=DB::$rows[ATTEMPT_A]->request_hash;
        if($bad==='request_hash')DB::$rows[ATTEMPT_A]->request_hash='invalid';elseif($bad==='missing_status')unset($saved['status']);
        elseif($bad==='invalid_status')$saved['status']=500;elseif($bad==='string_status')$saved['status']='200';
        elseif($bad==='operation')$saved['operation']='unbound';elseif($bad==='missing_content')unset($saved['content']);else $saved['content']=str_repeat('x',1024*1024+1);
        DB::$rows[ATTEMPT_A]->response_cipher=Crypt::encryptString(json_encode($saved));
        refusal(fn()=>$remote->settleWorkspace($authority->root(),$proofA,valuesRemote(ATTEMPT_A)),503,'Incomplete/corrupt historical committed metadata '.$bad);
        DB::$rows[ATTEMPT_A]->response_cipher=$originalCipher;DB::$rows[ATTEMPT_A]->request_hash=$hash;
        checkRemote(Engine::$business===1,'Corrupt committed metadata never reaches a business callback');
    }
    foreach(['/other/products','/admin/products?x=1','/admin/products#fragment',"/admin/products\nPRIVATE"] as $path){$unsafe=valuesRemote(ATTEMPT_LOST);$unsafe['path']=$path;
        refusal(fn()=>$remote->settleWorkspace($authority->root(),$proofA,$unsafe),422,'Unsafe terminal-only path');}
    foreach(['actor','grant','revision','method','path','root','extra'] as $bad){$proof=$proofA;$values=valuesRemote(ATTEMPT_A);$root=$authority->root();
        if($bad==='actor'){$proof['actor_id']=202;$proof['grant']=str_repeat('4',64);}elseif($bad==='grant')$proof['grant']=str_repeat('f',64);
        elseif($bad==='revision')$proof['manifest_revision']=4;elseif($bad==='method')$values['method']='DELETE';elseif($bad==='path')$values['path']='/admin/products/999';
        elseif($bad==='root')$root->fingerprint=str_repeat('f',64);else $proof['actor_override']=202;
        refusal(fn()=>$remote->settleWorkspace($root,$proof,$values),$bad==='root'?401:($bad==='extra'?422:(in_array($bad,['grant','revision'],true)?403:409)),'Forged historical recovery '.$bad);
        checkRemote(Engine::$business===1,'Historical recovery never invokes business work');
    }
    $authority->current[101]=true;$GLOBALS['remote_guard']->actor=new \App\Models\User(101);$newScopeA=$authority->scope($authority->root(),101,str_repeat('1',64),7);
    $delayed=$remote->decideWorkspace($newScopeA,valuesRemote(ATTEMPT_LOST),false);checkRemote($delayed['status']==='cancelled','Delayed original reserve cannot reopen a historical cancellation tombstone');
    refusal(fn()=>executeRemote(requestRemote($delayed,101),fn()=>throw new RuntimeException('Cancelled delayed original executed')),409,'Cancelled tombstone blocks delayed business request');
    refusal(fn()=>$remote->decide((object)['id'=>REMOTE_ROOT,'actor_id'=>202],valuesRemote(),false),403,'Request-supplied legacy actor cannot become group scope');
    echo 'PASS '.$checks.' typed workspace remote-attempt, physical fence and historical terminal checks'.PHP_EOL;
}
