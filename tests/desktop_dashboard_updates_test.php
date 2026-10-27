<?php
// Standalone release-feed contract and filesystem tests; no application DB/bootstrap.
namespace {
    class UpdateHttpFailure extends \RuntimeException {public function __construct(public int $status,string $message=''){parent::__construct($message);}}
    function abort_unless($value,$status,$message=''){if(!$value)throw new UpdateHttpFailure($status,$message);}
    function abort($status,$message=''){throw new UpdateHttpFailure($status,$message);}
    function config($key){return $GLOBALS['update_config'][$key]??null;}
    function storage_path($path){return $GLOBALS['update_storage'].'/'.$path;}
    function base_path($path=''){return $GLOBALS['update_storage'].($path!==''?'/'.$path:'');}
    function response(){return new UpdateResponse;}
    function app($class){if($class===\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::class)return $GLOBALS['update_workspace_authority'];throw new \RuntimeException('Unexpected fixture service.');}
    class UpdateResponse {
        public $data;public array $headers=[];public $callback;
        public function json($data){$this->data=$data;return $this;}
        public function header($key,$value){$this->headers[$key]=$value;return $this;}
        public function stream($callback,$status,$headers){$this->callback=$callback;$this->headers=$headers;return $this;}
    }
}
namespace Illuminate\Http {class Request {
    private array $headers=[];
    public function __construct(private string $token,array $headers=[]){foreach($headers as$key=>$value)$this->headers[strtolower($key)]=$value;}
    public function bearerToken(){return $this->token;}
    public function hasHeader($name){return array_key_exists(strtolower($name),$this->headers);}
    public function header($name,$default=null){return $this->headers[strtolower($name)]??$default;}
}}
namespace App\Http\Controllers {class Controller {}}
namespace App\Services\Dashboard {
    class DesktopDashboardDevices {public int $calls=0;public function device(string $token): object{$this->calls++;\abort_unless($token==='fixture-token',401);return (object)['id'=>'device'];}}
    class DesktopDashboardWorkspaceAuthority {public int $calls=0;public function authenticate(string $token): object{$this->calls++;\abort_unless($token==='workspace-fixture-token',401);return(object)['id'=>'workspace'];}}
}
namespace {
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardSource.php';
    require __DIR__.'/../app/Services/Dashboard/DesktopDashboardUpdates.php';
    require __DIR__.'/../app/Http/Controllers/Dashboard/DesktopDashboardUpdateController.php';
    use App\Services\Dashboard\{DesktopDashboardSource,DesktopDashboardUpdates,DesktopDashboardDevices};
    use App\Http\Controllers\Dashboard\DesktopDashboardUpdateController;
    class UpdateSource extends DesktopDashboardSource {
        public array $value;public $onRead=null;public int $reads=0;
        public function fingerprint(): array{$this->reads++;if($this->onRead)($this->onRead)($this->reads);return $this->value;}
    }
    $root=sys_get_temp_dir().'/go-erp-updates-test-'.bin2hex(random_bytes(8));
    $GLOBALS['update_storage']=$root;$GLOBALS['update_config']=['desktop_dashboard.enabled'=>true,'desktop_dashboard.local'=>false];
    mkdir($root,0700);mkdir($root.'/app',0700);
    $folder=$root.'/app/desktop-updates';$fileBytes="MZ\0fixture installer bytes\xff";$sha=hash('sha256',$fileBytes);
    $source=new UpdateSource;$source->value=['format'=>1,'files'=>1324,'sha256'=>str_repeat('a',64),'framework'=>'8.83.29'];
    $release=['version'=>'0.4.5-preview.1','sequence'=>5,'source_revision'=>str_repeat('b',40),'source'=>$source->value,
        'installer'=>['sha256'=>$sha,'bytes'=>strlen($fileBytes),'path'=>'/api/desktop-dashboard/update/installer/'.$sha],'gui'=>[]];
    $updates=new DesktopDashboardUpdates($source);$checks=0;
    function check($condition,$label){global $checks;$checks++;if(!$condition)throw new \RuntimeException($label);}
    function blocked($call,$status,$label){try{$call();throw new \RuntimeException('Expected refusal: '.$label);}catch(UpdateHttpFailure $error){check($error->status===$status,$label);}}
    function publicRelease($value){unset($value['gui']);return $value;}
    function publishFixture($value=null){global $folder,$release,$sha,$fileBytes;if(!is_dir($folder))mkdir($folder,0755);file_put_contents($folder.'/'.$sha.'.exe',$fileBytes);chmod($folder.'/'.$sha.'.exe',0644);$value=$value??$release;if(isset($value['gui'])&&is_array($value['gui']))$value['gui']=(object)$value['gui'];file_put_contents($folder.'/current.json',json_encode($value));chmod($folder.'/current.json',0644);}
    function removeFixture($path){if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path))return;foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')removeFixture($path.'/'.$name);rmdir($path);}
    try{
        check(function_exists('posix_geteuid')?posix_geteuid()===0:fileowner($root)===0,'root-owned publication fixtures required');
        $empty=$updates->status();check($empty===['format'=>1,'poll_seconds'=>60,'state'=>'unavailable','server_source'=>$source->value,'release'=>null],'absent first publication unavailable');
        publishFixture();unlink($folder.'/current.json');check($updates->status()['state']==='unavailable','absent manifest unavailable');
        publishFixture();$ready=$updates->status();check($ready['release']===publicRelease($release)&&$ready['state']==='ready'&&$ready['poll_seconds']===60,'exact ready release and minute polling');
        $source->value['sha256']=str_repeat('c',64);$status=$updates->status();check($status['state']==='unavailable'&&$status['release']===null,'new server source hides stale installer');
        blocked(fn()=>$updates->installer($sha),409,'stale source cannot download');$source->value=$release['source'];
        $download=$updates->installer($sha);check(stream_get_contents($download['stream'])===$fileBytes&&$download['bytes']===strlen($fileBytes),'descriptor verified and rewound');fclose($download['stream']);
        blocked(fn()=>$updates->installer('../current.json'),404,'path traversal rejected');
        blocked(fn()=>$updates->installer(str_repeat('d',64)),404,'unpublished hash rejected');
        $GLOBALS['update_config']['desktop_dashboard.enabled']=false;blocked(fn()=>$updates->status(),503,'disabled server refuses feed');
        $GLOBALS['update_config']['desktop_dashboard.enabled']=true;$GLOBALS['update_config']['desktop_dashboard.local']=true;blocked(fn()=>$updates->installer($sha),503,'local runtime cannot serve update');$GLOBALS['update_config']['desktop_dashboard.local']=false;
        $badValues=[];
        $bad=$release;$bad['extra']='no';$badValues['extra release field']=$bad;
        $bad=$release;unset($bad['sequence']);$badValues['missing sequence']=$bad;
        foreach([0,-1,'5',9007199254740992] as $index=>$value){$bad=$release;$bad['sequence']=$value;$badValues['bad sequence'.$index]=$bad;}
        foreach(['https://elsewhere.test/setup.exe','/api/desktop-dashboard/update/installer/'.$sha.'?token=secret','/api/desktop-dashboard/update/installer/../current.json'] as $index=>$value){$bad=$release;$bad['installer']['path']=$value;$badValues['bad installer path'.$index]=$bad;}
        foreach([0,-1,1073741825,'12'] as $index=>$value){$bad=$release;$bad['installer']['bytes']=$value;$badValues['bad installer bytes'.$index]=$bad;}
        $bad=$release;$bad['installer']['sha256']=strtoupper($sha);$badValues['noncanonical digest']=$bad;
        $bad=$release;$bad['installer']['url']='https://elsewhere.test';$badValues['installer fields exact']=$bad;
        $bad=$release;$bad['source']['sha256']='bad';$badValues['source digest invalid']=$bad;
        $bad=$release;$bad['source']['format']='1';$badValues['source protocol exact']=$bad;
        $bad=$release;$bad['source']['files']=20001;$badValues['source count bound']=$bad;
        $bad=$release;$bad['source']['secret']='hidden';$badValues['source fields exact']=$bad;
        $bad=$release;unset($bad['gui']);$badValues['internal GUI pins required']=$bad;
        foreach(['../public/js/dashboard-a.js','public/js/nested/dashboard-a.js','public/css/dashboard-a.js','public/js/other.js','public/js/dashboard-bad name.js'] as $index=>$path){$bad=$release;$bad['gui']=[$path=>str_repeat('a',64)];$badValues['invalid GUI path'.$index]=$bad;}
        $bad=$release;$bad['gui']=['public/js/dashboard-a.js'=>'bad'];$badValues['invalid GUI digest']=$bad;
        $bad=$release;$bad['gui']=['public/js/dashboard-A.js'=>str_repeat('a',64),'public/js/dashboard-a.js'=>str_repeat('a',64)];$badValues['GUI case collision']=$bad;
        foreach(['v0.4.5','0.4','01.4.5','0.4.5;rm','0.4.5\n','0.4.5-preview.01','9007199254740992.0.0','0.4.5-preview.9007199254740992'] as $index=>$value){$bad=$release;$bad['version']=$value;$badValues['bad version'.$index]=$bad;}
        $bad=$release;$bad['source_revision']=str_repeat('g',40);$badValues['source revision hex']=$bad;
        foreach($badValues as $label=>$value){publishFixture($value);blocked(fn()=>$updates->status(),503,$label);}
        foreach(['1.2.3-alpha-beta.0+build.001','9007199254740991.0.0','0.4.5-preview.9007199254740991'] as $version){$valid=$release;$valid['version']=$version;publishFixture($valid);check($updates->status()['release']['version']===$version,'valid SemVer supported by native client');}
        publishFixture();$arrayGui=$release;$arrayGui['gui']=[];file_put_contents($folder.'/current.json',json_encode($arrayGui));blocked(fn()=>$updates->status(),503,'GUI map must be JSON object');
        mkdir($root.'/public',0755);mkdir($root.'/public/js',0755);mkdir($root.'/public/css',0755);
        $js=$root.'/public/js/dashboard-example.js';$css=$root.'/public/css/dashboard-example.css';
        file_put_contents($js,"fixture();\r\n");file_put_contents($css,".fixture{}\n");
        $withGui=$release;$withGui['gui']=['public/js/dashboard-example.js'=>hash('sha256',"fixture();\n"),'public/css/dashboard-example.css'=>hash('sha256',".fixture{}\n")];
        publishFixture($withGui);check($updates->status()['release']===publicRelease($withGui),'complete GUI roster matches with normalized LF and private pins omitted');
        symlink($root.'/private-not-read',$root.'/public/js/.env');check($updates->status()['state']==='ready','private name excluded before opening or following it');unlink($root.'/public/js/.env');
        file_put_contents($root.'/public/js/dashboard-.js','');$zeroSuffix=$withGui;$zeroSuffix['gui']['public/js/dashboard-.js']=hash('sha256','');publishFixture($zeroSuffix);check($updates->status()['state']==='ready','dashboard wildcard agrees with publisher scanner for empty suffix');unlink($root.'/public/js/dashboard-.js');publishFixture($withGui);
        $source->reads=0;$source->onRead=function($read)use($js){if($read===1)file_put_contents($js,"newFixture();\n");};check($updates->status()['state']==='unavailable','GUI change during status source scan suppresses release');$source->onRead=null;file_put_contents($js,"fixture();\n");
        file_put_contents($js,"newFixture();\n");check($updates->status()['state']==='unavailable','GUI-only content change suppresses release');blocked(fn()=>$updates->installer($sha),409,'GUI-only drift refuses installer');
        file_put_contents($js,"fixture();\n");file_put_contents($root.'/public/js/dashboard-new.js','new');check($updates->status()['state']==='unavailable','new GUI file suppresses release');blocked(fn()=>$updates->installer($sha),409,'new GUI file refuses installer');unlink($root.'/public/js/dashboard-new.js');
        unlink($css);check($updates->status()['state']==='unavailable','deleted GUI file suppresses release');blocked(fn()=>$updates->installer($sha),409,'deleted GUI file refuses installer');file_put_contents($css,".fixture{}\n");
        $source->reads=0;$source->onRead=function($read)use($js){if($read===2)file_put_contents($js,"newFixture();\n");};blocked(fn()=>$updates->installer($sha),409,'GUI changed while installer verified');$source->onRead=null;file_put_contents($js,"fixture();\n");
        file_put_contents($root.'/public/js/not-dashboard.js','not selected');check($updates->status()['state']==='ready','unrelated public asset is outside explicit GUI scope');unlink($root.'/public/js/not-dashboard.js');
        unlink($js);symlink($root.'/outside.js',$js);file_put_contents($root.'/outside.js',"fixture();\n");blocked(fn()=>$updates->status(),503,'GUI symlink refused');unlink($js);file_put_contents($js,"fixture();\n");
        link($js,$root.'/gui-hardlink');blocked(fn()=>$updates->status(),503,'GUI hardlink refused');unlink($root.'/gui-hardlink');
        rename($root.'/public/js',$root.'/public/real-js');symlink($root.'/public/real-js',$root.'/public/js');blocked(fn()=>$updates->status(),503,'GUI directory symlink refused');unlink($root.'/public/js');rename($root.'/public/real-js',$root.'/public/js');
        $oversize=fopen($js,'wb');ftruncate($oversize,8388609);fclose($oversize);blocked(fn()=>$updates->status(),503,'GUI file bound checked before reading');
        removeFixture($root.'/public');publishFixture();
        $largeGui=$release;for($index=0;$index<2000;$index++)$largeGui['gui']['public/js/dashboard-fixture'.$index.'.js']=str_repeat('a',64);
        publishFixture($largeGui);check(filesize($folder.'/current.json')>16384&&$updates->status()['state']==='unavailable','bounded two thousand GUI pins supported beyond wire JSON limit');
        $largeGui['gui']['public/js/dashboard-fixture2000.js']=str_repeat('a',64);publishFixture($largeGui);blocked(fn()=>$updates->status(),503,'GUI map count bounded');
        publishFixture();file_put_contents($folder.'/current.json','{');blocked(fn()=>$updates->status(),503,'broken JSON rejected');
        file_put_contents($folder.'/current.json',str_repeat(' ',524289));blocked(fn()=>$updates->status(),503,'internal publication JSON bounded before read');
        publishFixture();chmod($folder.'/current.json',0664);blocked(fn()=>$updates->status(),503,'group writable manifest refused');
        publishFixture();chmod($folder,0775);blocked(fn()=>$updates->status(),503,'group writable directory refused');chmod($folder,0755);
        publishFixture();chmod($folder.'/'.$sha.'.exe',0666);blocked(fn()=>$updates->status(),503,'writable installer refused');
        publishFixture();file_put_contents($folder.'/'.$sha.'.exe','short');blocked(fn()=>$updates->status(),503,'installer byte length bound');
        publishFixture();file_put_contents($folder.'/'.$sha.'.exe',str_repeat('x',strlen($fileBytes)));blocked(fn()=>$updates->installer($sha),503,'same length corruption detected before streaming');
        publishFixture();$source->reads=0;$source->onRead=function($read)use($folder){if($read===2)unlink($folder.'/current.json');};
        blocked(fn()=>$updates->installer($sha),409,'publication revoked while verifying installer');$source->onRead=null;
        publishFixture();$source->reads=0;$source->onRead=function($read)use($source){if($read===2)$source->value['sha256']=str_repeat('c',64);};
        blocked(fn()=>$updates->installer($sha),409,'server source changed while verifying installer');$source->onRead=null;$source->value=$release['source'];
        publishFixture();unlink($folder.'/current.json');symlink($root.'/outside.json',$folder.'/current.json');file_put_contents($root.'/outside.json',json_encode($release));blocked(fn()=>$updates->status(),503,'manifest symlink refused');unlink($folder.'/current.json');
        publishFixture();unlink($folder.'/'.$sha.'.exe');symlink($root.'/outside.exe',$folder.'/'.$sha.'.exe');file_put_contents($root.'/outside.exe',$fileBytes);blocked(fn()=>$updates->status(),503,'installer symlink refused');unlink($folder.'/'.$sha.'.exe');
        publishFixture();link($folder.'/current.json',$root.'/manifest-hardlink');blocked(fn()=>$updates->status(),503,'hardlinked manifest refused');unlink($root.'/manifest-hardlink');
        publishFixture();link($folder.'/'.$sha.'.exe',$root.'/installer-hardlink');blocked(fn()=>$updates->status(),503,'hardlinked installer refused');unlink($root.'/installer-hardlink');
        publishFixture();$devices=new DesktopDashboardDevices;$controller=new DesktopDashboardUpdateController;
        blocked(fn()=>$controller->status(new \Illuminate\Http\Request('wrong'),$devices,$updates),401,'feed requires device authentication');
        blocked(fn()=>$controller->installer(new \Illuminate\Http\Request('wrong'),$sha,$devices,$updates),401,'installer requires device authentication');
        $response=$controller->status(new \Illuminate\Http\Request('fixture-token'),$devices,$updates);check($response->data['state']==='ready'&&$response->headers['Cache-Control']==='private, no-store','feed response private and uncached');
        $response=$controller->installer(new \Illuminate\Http\Request('fixture-token'),$sha,$devices,$updates);ob_start();($response->callback)();$bytes=ob_get_clean();check($bytes===$fileBytes&&$response->headers['Content-Length']===(string)strlen($fileBytes),'authenticated descriptor streamed exactly');
        check(!array_key_exists('Authorization',$response->headers),'no token in response metadata');
        $GLOBALS['update_workspace_authority']=new \App\Services\Dashboard\DesktopDashboardWorkspaceAuthority;
        $legacyCalls=$devices->calls;$headers=['x-fasakhansta-update-protocol'=>'2'];
        $response=$controller->status(new \Illuminate\Http\Request('workspace-fixture-token',$headers),$devices,$updates);
        check($response->data['state']==='ready'&&$devices->calls===$legacyCalls,'workspace feed uses only its root credential');
        $response=$controller->installer(new \Illuminate\Http\Request('workspace-fixture-token',$headers),$sha,$devices,$updates);
        ob_start();($response->callback)();check(ob_get_clean()===$fileBytes,'workspace root downloads the unchanged published descriptor');
        blocked(fn()=>$controller->status(new \Illuminate\Http\Request('fixture-token',$headers),$devices,$updates),401,'legacy token cannot authenticate a workspace request');
        blocked(fn()=>$controller->status(new \Illuminate\Http\Request('workspace-fixture-token'),$devices,$updates),401,'workspace token cannot fall back to legacy enrollment');
        blocked(fn()=>$controller->status(new \Illuminate\Http\Request('workspace-fixture-token',['X-Fasakhansta-Update-Protocol'=>'02']),$devices,$updates),422,'noncanonical workspace protocol is rejected');
        $routes=file_get_contents(__DIR__.'/../routes/api.php');check(str_contains($routes,"->where('sha','[a-f0-9]{64}')"),'route constrains installer hash');
        echo "GO_ERP_UPDATE_FEED_TESTS_OK checks=$checks\n";
    }finally{removeFixture($root);}
}
