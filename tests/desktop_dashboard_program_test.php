<?php
// Authenticated modern program page/controller contract, without account data or a live DB.
namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException {
        public function __construct(private int $status,string $message=''){parent::__construct($message);}
        public function getStatusCode(){return $this->status;}
    }
}
namespace {
    function abort_unless($value,$status,$message=''){if(!$value)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function config($key){if(str_starts_with($key,'desktop_pos.'))throw new \RuntimeException('Legacy installer must never be read');return $GLOBALS['program_config'][$key]??null;}
    function auth($guard){if($guard!=='admin')throw new \RuntimeException('Wrong auth guard');return new class {function user(){return (object)['id'=>42];}};}
    function response(){return new ProgramResponse;}
    class ProgramResponse {
        public string $view;public array $data=[];public array $headers=[];public $callback;
        public function view($name,$data){$this->view=$name;$this->data=$data;return $this;}
        public function header($name,$value){$this->headers[$name]=$value;return $this;}
        public function stream($callback,$status,$headers){if($status!==200)throw new \RuntimeException('Wrong stream status');$this->callback=$callback;$this->headers=$headers;return $this;}
    }
}
namespace App\Http\Controllers {class Controller {}}
namespace Illuminate\Http {class Request {}}
namespace App\Services\Dashboard {
    class TakeawayAccess {public bool $allowed=true;public int $calls=0;function canAccess($actor){$this->calls++;return $this->allowed&&$actor->id===42;}}
    class DesktopDashboardDevices {}
    class DesktopDashboardManualRelease {
        public bool $owner=false;public int $calls=0;
        function canRequest($actor){$this->calls++;return $this->owner&&$actor->id===42;}
    }
    class DesktopDashboardUpdates {
        public const ERROR='Fixed publication error';public int $statusCalls=0;public int $installerCalls=0;public bool $failing=false;
        public array $status=['state'=>'ready','release'=>['version'=>'0.4.4-preview.99','installer'=>['sha256'=>'fixed-sha']]];
        public $stream;
        function status(){ $this->statusCalls++;if($this->failing)throw new \Symfony\Component\HttpKernel\Exception\HttpException(503,'PRIVATE unavailable diagnostics');return $this->status;}
        function publishedDownloads(){return ['current'=>null,'previous'=>null];}
        function installer($sha){$this->installerCalls++;if($sha!=='fixed-sha')throw new \RuntimeException('Wrong installer pin');$this->stream=fopen('php://memory','w+b');fwrite($this->stream,"MZ\0verified bytes");rewind($this->stream);return ['bytes'=>17,'stream'=>$this->stream];}
    }
}
namespace {
    require __DIR__.'/../app/Http/Controllers/Dashboard/DesktopDashboardController.php';
    $GLOBALS['program_config']=['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>false];
    $controller=new \App\Http\Controllers\Dashboard\DesktopDashboardController;
    $access=new \App\Services\Dashboard\TakeawayAccess;$updates=new \App\Services\Dashboard\DesktopDashboardUpdates;
    $manual=new \App\Services\Dashboard\DesktopDashboardManualRelease;$checks=0;
    $indexParameters=(new \ReflectionMethod($controller,'index'))->getNumberOfRequiredParameters();
    if(!in_array($indexParameters,[2,3],true))throw new \RuntimeException('Unreviewed program page dependency contract');
    $manualPage=$indexParameters===3;
    function check($value,$label){global $checks;if(!$value)throw new \RuntimeException($label);$checks++;}
    function blocked($call,$status,$label){try{$call();throw new \RuntimeException('Expected refusal '.$label);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){check($error->getStatusCode()===$status,$label);}}
    $page=$controller->index($access,$updates,$manual);
    check($page->view==='admin.desktop-dashboard'&&$page->data['release']['version']==='0.4.4-preview.99','Modern page advertises compatible service release');
    check($page->headers['Cache-Control']==='private, no-store','Page is private and uncached');
    check($manualPage?$page->data['manualOwner']===false:!array_key_exists('manualOwner',$page->data)&&$manual->calls===0,
        'Regular program users do not receive owner release controls');
    $manual->owner=true;$ownerPage=$controller->index($access,$updates,$manual);
    check($manualPage?$ownerPage->data['manualOwner']===true:!array_key_exists('manualOwner',$ownerPage->data)&&$manual->calls===0,
        'Manual release controls follow the reviewed controller owner contract');
    $response=$controller->download($access,$updates);ob_start();($response->callback)();$bytes=ob_get_clean();
    check($bytes==="MZ\0verified bytes"&&!is_resource($updates->stream),'Download streams verified descriptor exactly and closes it');
    check($response->headers['Content-Length']==='17'&&$response->headers['Content-Disposition']==='attachment; filename="GO-ERP-Setup.exe"','Modern installer name and bounded size');
    check($response->headers['Cache-Control']==='private, no-store'&&$response->headers['X-Content-Type-Options']==='nosniff','Download cannot be cached or MIME sniffed');
    $updates->status=['state'=>'unavailable','release'=>null];
    check($controller->index($access,$updates,$manual)->data['release']===null,'Unpublished or incompatible release never advertises installer');
    $before=$updates->installerCalls;blocked(fn()=>$controller->download($access,$updates),503,'Unavailable release refuses download');check($updates->installerCalls===$before,'Unavailable release does not open bytes');
    $updates->failing=true;
    check($controller->index($access,$updates,$manual)->data['release']===null,'Changing or invalid publication safely hides download without raw error');
    blocked(fn()=>$controller->download($access,$updates),503,'Invalid publication refuses download');
    $GLOBALS['program_config']['desktop_dashboard.local']=true;$GLOBALS['program_config']['desktop_dashboard.enabled']=false;$before=$updates->statusCalls;
    $page=$controller->index($access,$updates,$manual);check($page->data===($manualPage?['local'=>true,'release'=>null,'manualOwner'=>false]:['local'=>true,'release'=>null])&&$updates->statusCalls===$before,'Local page needs no server publication or filesystem');
    blocked(fn()=>$controller->download($access,$updates),404,'Local page cannot invoke installer download');check($updates->statusCalls===$before,'Local download refusal does not consult publication');
    $access->allowed=false;blocked(fn()=>$controller->index($access,$updates,$manual),403,'Account without branch access cannot view device setup');
    blocked(fn()=>$controller->download($access,$updates),403,'Account without branch access cannot download installer');
    $GLOBALS['program_config']['desktop_dashboard.local']=false;blocked(fn()=>$controller->index($access,$updates,$manual),404,'Disabled feature is not exposed');
    echo 'PASS '.$checks.' modern program controller checks'.PHP_EOL;
}
