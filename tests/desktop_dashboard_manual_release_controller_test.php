<?php
/** Manual release HTTP boundaries without Laravel bootstrap or business data. */
namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException {
        public function __construct(private int $status,string $message=''){parent::__construct($message);}
        public function getStatusCode(){return $this->status;}
    }
}
namespace {
    function abort_unless($condition,$status,$message=''){
        if(!$condition)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);
    }
    function auth($guard){
        if($guard!=='admin')throw new \RuntimeException('wrong authentication guard');
        return new class {public function user(){return $GLOBALS['manual_controller_actor'];}};
    }
    function config($key){return $GLOBALS['manual_controller_config'][$key]??null;}
    function route($name,array $parameters=[]){
        if($name==='desktop-dashboard.download')return '/admin/desktop-dashboard/download';
        if($name==='desktop-dashboard.published.download'&&isset($parameters['sha'])
            &&preg_match('/^[a-f0-9]{64}$/D',$parameters['sha']))
            return '/admin/desktop-dashboard/published/'.$parameters['sha'];
        throw new \RuntimeException('unexpected route');
    }
    function response(){return new ManualControllerResponseFactory;}
    class ManualControllerResponseFactory {
        public function json($value,$status=200){return new ManualControllerResponse($value,$status);}
        public function stream($callback,$status,$headers){
            $response=new ManualControllerResponse([],$status);$response->callback=$callback;$response->headers=$headers;return $response;
        }
    }
    class ManualControllerResponse {
        public array $headers=[];
        public $callback=null;
        public function __construct(public array $data,public int $status){}
        public function header($key,$value){$this->headers[$key]=$value;return $this;}
    }
}
namespace Illuminate\Http {
    class Request {
        public array $rules=[];
        public function __construct(public array $value){}
        public function validate(array $rules){
            $this->rules=$rules;$nonce=$this->value['nonce']??null;
            \abort_unless(is_string($nonce)&&preg_match('/^[a-f0-9]{32}$/D',$nonce),422);
            return ['nonce'=>$nonce];
        }
    }
}
namespace App\Http\Controllers {class Controller {}}
namespace App\Services\Dashboard {
    class DesktopDashboardManualRelease {
        public array $calls=[];
        public bool $owner=true;
        public bool $idempotent=false;
        public array $value=['format'=>1,'kind'=>'go-erp-manual-release-status','state'=>'ready',
            'nonce'=>null,'updated_at'=>1792843200,'version'=>'0.4.4-preview.43','code'=>null];
        public function status($actor): array {
            $this->calls[]=['status',$actor];\abort_unless($this->owner,403);return $this->value;
        }
        public function request($actor,string $nonce): array {
            $this->calls[]=['request',$actor,$nonce];\abort_unless($this->owner,403);
            return ['request'=>['private'=>'not returned'],'idempotent'=>$this->idempotent,'status'=>$this->value];
        }
    }
    class DesktopDashboardUpdates {
        public int $calls=0;
        public int $publishedCalls=0;
        public array $installerCalls=[];
        public ?\Throwable $error=null;
        public ?\Throwable $publishedError=null;
        public array $value=['state'=>'unavailable','release'=>null];
        public array $published=['current'=>null,'previous'=>null];
        public array $files=[];
        public function status(): array {
            $this->calls++;if($this->error!==null)throw $this->error;return $this->value;
        }
        public function publishedDownloads(): array {
            $this->publishedCalls++;if($this->publishedError!==null)throw $this->publishedError;return $this->published;
        }
        public function publishedInstaller(string $sha): array {
            $this->installerCalls[]=$sha;\abort_unless(isset($this->files[$sha]),404);
            $file=$this->files[$sha];$stream=tmpfile();fwrite($stream,$file['bytes']);rewind($stream);
            return ['stream'=>$stream,'bytes'=>strlen($file['bytes']),'sha256'=>$sha,'version'=>$file['version']];
        }
    }
    class DesktopDashboardDevices {}
    class TakeawayAccess {
        public bool $allowed=true;
        public array $calls=[];
        public function canAccess($actor): bool {$this->calls[]=$actor;return $this->allowed;}
    }
}
namespace {
    require __DIR__.'/../app/Http/Controllers/Dashboard/DesktopDashboardController.php';
    use App\Http\Controllers\Dashboard\DesktopDashboardController;
    use App\Services\Dashboard\{DesktopDashboardManualRelease,DesktopDashboardUpdates,TakeawayAccess};
    use Illuminate\Http\Request;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    $checks=0;
    function check($condition,string $label): void {
        global $checks;$checks++;if(!$condition)throw new \RuntimeException($label);
    }
    function blocked(callable $callback,int $status,string $label): void {
        try{$callback();throw new \RuntimeException('Expected refusal: '.$label);}
        catch(HttpException $error){check($error->getStatusCode()===$status,$label);}
    }
    $controller=new DesktopDashboardController;
    $actor=(object)['id'=>1];$GLOBALS['manual_controller_actor']=$actor;
    $GLOBALS['manual_controller_config']=['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>false];
    $manual=new DesktopDashboardManualRelease;$updates=new DesktopDashboardUpdates;

    $response=$controller->releaseStatus($manual,$updates);
    check($manual->calls===[['status',$actor]],'status delegates owner identity to original service');
    check($response->status===200&&$response->data['status']===$manual->value,'root status returned without reinterpretation');
    check($response->headers===['Cache-Control'=>'private, no-store'],'status response stays private and uncached');
    check($response->data['download']===['ready'=>false,'version'=>null,'url'=>null],
        'root ready alone never grants a download');
    check($updates->calls===1,'real publication compatibility guard checked for every response');
    check($response->data['published_downloads']===['current'=>['ready'=>false,'version'=>null,'url'=>null],
        'previous'=>['ready'=>false,'version'=>null,'url'=>null]],'absent history cannot fabricate current or previous download');

    $updates->value=['state'=>'ready','release'=>['version'=>'0.4.4-preview.42','installer'=>['sha256'=>'private-not-returned']]];
    $response=$controller->releaseStatus($manual,$updates);
    check($response->data['download']===['ready'=>true,'version'=>'0.4.4-preview.42','url'=>'/admin/desktop-dashboard/download'],
        'only actual guarded release controls version and fixed download route');
    check(!str_contains(json_encode($response->data),'private-not-returned'),'internal release metadata is not exposed');
    $manual->value['state']='failed';$response=$controller->releaseStatus($manual,$updates);
    check($response->data['download']['ready']===true,'failed new request does not revoke a genuinely compatible published release');
    $manual->value['state']='ready';
    $updates->error=new HttpException(503,'private guard details');
    $response=$controller->releaseStatus($manual,$updates);
    check($response->data['download']===['ready'=>false,'version'=>null,'url'=>null],
        '503 compatibility check hides installer while retaining root status');
    check(!str_contains(json_encode($response->data),'private guard details'),'guard exception message remains private');
    $updates->error=new HttpException(409,'conflict');
    blocked(fn()=>$controller->releaseStatus($manual,$updates),409,'non503 guard failures are not hidden');
    $updates->error=null;

    $publishedCurrent=['version'=>'0.4.4-preview.42','installer'=>['sha256'=>str_repeat('a',64)]];
    $publishedPrevious=['version'=>'0.4.4-preview.41','installer'=>['sha256'=>str_repeat('b',64)]];
    $updates->published=['current'=>$publishedCurrent,'previous'=>$publishedPrevious];
    $updates->value=['state'=>'unavailable','release'=>null];$manual->value['state']='failed';
    $response=$controller->releaseStatus($manual,$updates);
    check($response->data['download']['ready']===false,'changed source still withholds original canonical download');
    check($response->data['published_downloads']===['current'=>['ready'=>true,'version'=>'0.4.4-preview.42',
        'url'=>'/admin/desktop-dashboard/published/'.str_repeat('a',64)],
        'previous'=>['ready'=>true,'version'=>'0.4.4-preview.41','url'=>'/admin/desktop-dashboard/published/'.str_repeat('b',64)]],
        'failed later build retains both root-approved SHA-pinned historical downloads');
    $updates->error=new HttpException(503,'private current-source guard details');
    $response=$controller->releaseStatus($manual,$updates);
    check($response->data['published_downloads']['current']['ready']===true
        &&$response->data['published_downloads']['previous']['ready']===true,
        '503 current-source transition does not remove approved historical installers');
    $updates->error=null;$updates->publishedError=new HttpException(503,'private history metadata failure');
    $response=$controller->releaseStatus($manual,$updates);
    check(!$response->data['published_downloads']['current']['ready']&&!$response->data['published_downloads']['previous']['ready'],
        'unverified history never grants either download');
    check(!str_contains(json_encode($response->data),'private history metadata failure'),'history diagnostics remain private');
    $updates->publishedError=new HttpException(409,'history conflict');
    blocked(fn()=>$controller->releaseStatus($manual,$updates),409,'non503 history failures propagate');
    $updates->publishedError=null;$manual->value['state']='ready';

    $manual->owner=false;$before=$updates->calls;$publishedBefore=$updates->publishedCalls;
    blocked(fn()=>$controller->releaseStatus($manual,$updates),403,'nonowner cannot read root release status');
    check($updates->calls===$before,'nonowner status denial precedes publication read');
    $nonce=str_repeat('d',32);$request=new Request(['nonce'=>$nonce]);
    blocked(fn()=>$controller->requestRelease($request,$manual,$updates),403,'nonowner cannot request release');
    check($updates->calls===$before,'nonowner request denial precedes publication read');
    check($updates->publishedCalls===$publishedBefore,'nonowner status and request denial precede history read');
    $manual->owner=true;$manual->calls=[];
    foreach([false,true] as $idempotent){
        $manual->idempotent=$idempotent;
        $request=new Request(['nonce'=>$nonce,'actor_id'=>999,'requested_at'=>1,'command'=>'systemctl start unsafe']);
        $response=$controller->requestRelease($request,$manual,$updates);
        check($response->status===202,'accepted or idempotent request returns202');
        check($response->data['accepted']===true&&$response->data['idempotent']===$idempotent
            &&$response->data['nonce']===$nonce,'request acknowledgement preserves nonce and idempotence');
        check($manual->calls[count($manual->calls)-1]===['request',$actor,$nonce],
            'only authenticated actor and validated nonce reach service');
        check($request->rules===['nonce'=>['required','string','regex:/^[a-f0-9]{32}$/D']],
            'HTTP boundary requires canonical nonce');
        check($response->headers===['Cache-Control'=>'private, no-store'],'request acknowledgement is private and uncached');
        check(!str_contains(json_encode($response->data),'systemctl')&&!array_key_exists('request',$response->data),
            'web command fields and internal request record never enter response');
    }
    $before=count($manual->calls);
    foreach([[],['nonce'=>'../unsafe'],['nonce'=>true],['nonce'=>str_repeat('D',32)]] as $invalid)
        blocked(fn()=>$controller->requestRelease(new Request($invalid),$manual,$updates),422,'invalid nonce refused before spool call');
    check(count($manual->calls)===$before,'invalid HTTP nonce never reaches queue service');
    $updates->value=['state'=>'unavailable','release'=>null];
    $response=$controller->requestRelease(new Request(['nonce'=>$nonce]),$manual,$updates);
    check($response->data['download']['ready']===false&&$response->status===202,
        'queue acceptance does not imply publication or download readiness');

    $access=new TakeawayAccess;$sha=$publishedCurrent['installer']['sha256'];
    $updates->files[$sha]=['bytes'=>"MZ\0approved current42",'version'=>'0.4.4-preview.42'];
    $access->allowed=false;$before=count($updates->installerCalls);
    blocked(fn()=>$controller->publishedDownload($sha,$access,$updates),403,'published binary requires existing program access');
    check(count($updates->installerCalls)===$before,'branch access denial precedes historical binary read');
    $access->allowed=true;$GLOBALS['manual_controller_config']['desktop_dashboard.local']=true;
    blocked(fn()=>$controller->publishedDownload($sha,$access,$updates),404,'local runtime cannot use central historical download endpoint');
    check(count($updates->installerCalls)===$before,'local refusal precedes historical binary read');
    $GLOBALS['manual_controller_config']['desktop_dashboard.local']=false;
    $GLOBALS['manual_controller_config']['desktop_dashboard.enabled']=false;
    blocked(fn()=>$controller->publishedDownload($sha,$access,$updates),404,'disabled server cannot serve historical installer');
    $GLOBALS['manual_controller_config']['desktop_dashboard.enabled']=true;
    $response=$controller->publishedDownload($sha,$access,$updates);
    check($access->calls[count($access->calls)-1]===$actor&&$updates->installerCalls[count($updates->installerCalls)-1]===$sha,
        'historical stream retains authenticated actor and selected SHA');
    check($response->status===200&&$response->headers['Content-Disposition']==='attachment; filename="GO-ERP-0.4.4-preview.42-Setup.exe"',
        'historical filename is bound to actual selected version42');
    check($response->headers['Content-Type']==='application/octet-stream'
        &&$response->headers['Content-Length']===(string)strlen($updates->files[$sha]['bytes'])
        &&$response->headers['Cache-Control']==='private, no-store'&&$response->headers['X-Content-Type-Options']==='nosniff',
        'historical stream uses exact size and private binary response headers');
    ob_start();($response->callback)();$bytes=ob_get_clean();
    check($bytes===$updates->files[$sha]['bytes'],'historical descriptor streams exact selected installer bytes');
    blocked(fn()=>$controller->publishedDownload(str_repeat('e',64),$access,$updates),404,'unknown SHA remains unavailable through authenticated endpoint');

    $routes=file_get_contents(__DIR__.'/../routes/desktop_dashboard.php');
    check(str_contains($routes,"Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/release/status'")
        &&str_contains($routes,"'releaseStatus'])->name('desktop-dashboard.release.status')"),
        'status is authenticated existing web GET route');
    check(str_contains($routes,"Route::middleware(['lang','IsAdmin'])->post('admin/desktop-dashboard/release/request'")
        &&str_contains($routes,"'requestRelease'])->name('desktop-dashboard.release.request')"),
        'request is authenticated existing web POST route');
    check(!str_contains($routes,'withoutMiddleware'),'new routes do not disable middleware');
    check(str_contains($routes,"Route::middleware(['lang','IsAdmin'])->get('admin/desktop-dashboard/published/{sha}'")
        &&str_contains($routes,"'publishedDownload'])->where('sha','[a-f0-9]{64}')->name('desktop-dashboard.published.download')"),
        'historical download is authenticated and SHA-constrained');
    $provider=file_get_contents(__DIR__.'/../app/Providers/RouteServiceProvider.php');
    check(str_contains($provider,"Route::middleware('web')->group(base_path('routes/desktop_dashboard.php'))"),
        'desktop release endpoints retain web and CSRF middleware');
    $csrf=file_get_contents(__DIR__.'/../app/Http/Middleware/VerifyCsrfToken.php');
    check(!str_contains($csrf,'desktop-dashboard')&&!str_contains($csrf,'release/request'),
        'manual request has no CSRF exemption');
    $kernel=file_get_contents(__DIR__.'/../app/Http/Kernel.php');
    check(str_contains($kernel,'VerifyCsrfToken::class'),'web middleware still installs CSRF protection');
    $source=file_get_contents(__DIR__.'/../app/Http/Controllers/Dashboard/DesktopDashboardController.php');
    $start=strpos($source,'public function releaseStatus(');$end=strpos($source,'public function download(',$start);
    $manualMethods=substr($source,$start,$end-$start);
    check(!preg_match('/\b(?:exec|shell_exec|system|passthru|proc_open|popen)\s*\(|systemctl|DB::|Artisan::/',$manualMethods),
        'new controller paths never run shell commands or business DB operations');
    echo "GO_ERP_MANUAL_RELEASE_CONTROLLER_TESTS_OK checks=$checks\n";
}
