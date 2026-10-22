<?php
// Real PHP child-process memory regressions, without a live database or user data.
namespace {
    class BootstrapMemoryFailure extends \RuntimeException {public function __construct(public int $status,string $message=''){parent::__construct($message);}}
    function abort_unless($value,$status,$message=''){if(!$value)throw new BootstrapMemoryFailure($status,$message);}
    function abort_if($value,$status,$message=''){abort_unless(!$value,$status,$message);}
    function abort($status,$message=''){throw new BootstrapMemoryFailure($status,$message);}
    function app($class){return $GLOBALS['services'][$class];}
    function now($zone){return new class {public function toIso8601String(){return '2026-10-22T00:00:00+00:00';}};}
    function response($body,$status,$headers){return (object)compact('body','status','headers');}
    function memoryCheck($value,$label){if(!$value)throw new \RuntimeException($label);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
    class MemoryRows {
        public ?array $columns=null;public array $wheres=[];
        public function __construct(public string $table,private int $count=0,private ?array $records=null){}
        public function cursor(): \Generator {
            $GLOBALS['cursor_calls']=($GLOBALS['cursor_calls']??0)+1;
            if($this->records!==null){foreach($this->records as $row){$GLOBALS['yielded']=($GLOBALS['yielded']??0)+1;yield (object)$row;}return;}
            $blob=($GLOBALS['case']??'')==='bytes'?str_repeat('x',140*1024*1024):null;
            for($id=1;$id<=$this->count;$id++){
                $GLOBALS['yielded']=($GLOBALS['yielded']??0)+1;
                if(($GLOBALS['case']??'')==='rows'){yield (object)['id'=>$id];continue;}
                yield (object)['id'=>$id,'name'=>'صنف / '.$id,'note'=>$blob??str_repeat('x',1024).$id,'price'=>1.0,
                    'payload'=>'{"nested":{"api_key":"fixture-json-secret"},"list":[1.0,"صنف/"],"safe":true}',
                    'api_key'=>'fixture-private-'.$id];
            }
        }
        public function get(){
            $GLOBALS['get_calls']=($GLOBALS['get_calls']??0)+1;
            if(empty($GLOBALS['baseline']))throw new \RuntimeException('unbounded get() used instead of row iteration');
            return new class(iterator_to_array($this->cursor(),false)) {
                public function __construct(private array $rows){}public function all(){return $this->rows;}
            };
        }
    }
}
namespace App\Models {class User {public function __construct(public int $id=2){}}}
namespace Illuminate\Support {
    class Str {public static function uuid(){return '10000000-0000-4000-8000-000000000001';}public static function random($length){return str_repeat('r',$length);}}
}
namespace Illuminate\Support\Facades {
    class DB {
        private static int $level=0;
        public static function transactionLevel(){return self::$level;}
        public static function statement($sql){}
        public static function transaction($callback){self::$level++;try{return $callback();}finally{self::$level--;}}
        public static function connection(){return new class {public function getDatabaseName(){return 'private_synthetic_fixture';}};}
        public static function selectOne($sql,$bindings=[]){
            if(str_contains($sql,'ENGINE AS engine'))return (object)['engine'=>'InnoDB'];
            if(preg_match('/SHOW CREATE TABLE `([a-z_]+)`/',$sql,$match))return (object)['Table'=>$match[1],'Create Table'=>'CREATE TABLE `'.$match[1].'` (`id` bigint NOT NULL) ENGINE=InnoDB'];
            throw new \RuntimeException('Unexpected fixture metadata query');
        }
        public static function select($sql,$bindings=[]){
            if(str_contains($sql,'KEY_COLUMN_USAGE'))return [];
            if(str_contains($sql,'COLUMN_NAME AS name')){
                $rows=[];foreach(['id','name','note','price','payload','api_key','password','remember_token','guard_name'] as $name)
                    $rows[]=(object)['name'=>$name,'value'=>null,'nullable'=>'YES','type'=>$name==='id'?'bigint':'varchar'];
                return $rows;
            }
            throw new \RuntimeException('Unexpected fixture metadata query');
        }
    }
}
namespace Illuminate\Http {class Request {public function __construct(private string $token){}public function bearerToken(){return $this->token;}}}
namespace App\Http\Controllers {class Controller {}}
namespace App\Services\Dashboard {
    // Intercept refusal/no-op only; all successful budget cases use real PHP ini functions.
    function ini_get($name){return $GLOBALS['invalid_limit']??\ini_get($name);}
    function ini_set($name,$value){
        $GLOBALS['budget_sets']=($GLOBALS['budget_sets']??0)+1;
        if(!empty($GLOBALS['deny_budget']))return false;
        if(!empty($GLOBALS['noop_budget']))return \ini_get($name);
        return \ini_set($name,$value);
    }
    class DesktopDashboardDevices {
        public function ready(){}
        public function device($token){\abort_unless($token==='fixture-device-token',401);return (object)['id'=>'10000000-0000-4000-8000-000000000002','branches'=>'["f:1"]','enrollment_hash'=>str_repeat('a',64),'enrolled_at'=>'2026-10-22 00:00:00'];}
        public function lockAuthenticated($device){\abort_unless(empty($GLOBALS['revoked']),401);return $device;}
        public function actor($device){return new \App\Models\User;}
        public function branch($device,$branch,$actor){\abort_unless($branch==='f:1',403);}
    }
    class DesktopDashboardCreatedScopes {public function available(){return false;}}
    class DesktopDashboardSource {
        public function fingerprint(){return ['format'=>1,'files'=>1,'sha256'=>str_repeat('b',64),'framework'=>'8.83.29'];}
        public function matches($source){return empty($GLOBALS['source_changed']);}
    }
    class DesktopDashboardMedia {
        public function manifest($dataset,$device,$actor,$snapshot){
            \memoryCheck(($dataset['users'][1]['password']??null)==='fixture-own-password','media manifest still receives original authorized data');
            return ['files'=>[],'issues'=>[],'complete'=>true];
        }
    }
    class DesktopDashboardRoleFacts {public function manifest($roles){\memoryCheck(($roles[0]['name']??null)==='Fixture role','role facts retain original role rows');return ['fixture-role'=>true];}}
    class DesktopDashboardRoutes {public const WRITES=['fixture.write'];}
    class DesktopDashboardLegacy {public const ROUTES=['fixture.legacy'=>[]];}
}
namespace {
    $baseline=in_array('--baseline',$argv,true);$GLOBALS['baseline']=$baseline;
    $dataPath=$baseline?getenv('DESKTOP_MEMORY_BASELINE_DATA'):__DIR__.'/../app/Services/Dashboard/DesktopDashboardData.php';
    $bootstrapPath=$baseline?getenv('DESKTOP_MEMORY_BASELINE_BOOTSTRAP'):__DIR__.'/../app/Services/Dashboard/DesktopDashboardBootstrap.php';
    memoryCheck(is_string($dataPath)&&is_file($dataPath)&&is_string($bootstrapPath)&&is_file($bootstrapPath),'test source paths exist');
    require $dataPath;require $bootstrapPath;
    require __DIR__.'/../app/Http/Controllers/Api/DesktopDashboardController.php';
    class MemoryDataset extends \App\Services\Dashboard\DesktopDashboardData {
        public function queries(object $device,\App\Models\User $actor,array $branches): array {
            $GLOBALS['dataset_queries']=($GLOBALS['dataset_queries']??0)+1;
            memoryCheck($actor->id===2&&$branches===['f:1'],'authenticated branch scope unchanged');
            return ['roles'=>new MemoryRows('roles',0,[['id'=>1,'name'=>'Fixture role','guard_name'=>'admin']]),
                'users'=>new MemoryRows('users',0,[['id'=>1,'password'=>'fixture-other-password','remember_token'=>'fixture-token'],['id'=>2,'password'=>'fixture-own-password','remember_token'=>'fixture-token']]),
                'products'=>new MemoryRows('products',($GLOBALS['case']??'')==='large'?100000:(($GLOBALS['case']??'')==='bytes'?2:3)),
                'empty_table'=>new MemoryRows('empty_table')];
        }
    }
    function fixtureController(string $token='fixture-device-token'){
        $devices=new \App\Services\Dashboard\DesktopDashboardDevices;
        $GLOBALS['services']=[
            \App\Services\Dashboard\DesktopDashboardCreatedScopes::class=>new \App\Services\Dashboard\DesktopDashboardCreatedScopes,
            \App\Services\Dashboard\DesktopDashboardSource::class=>new \App\Services\Dashboard\DesktopDashboardSource,
            \App\Services\Dashboard\DesktopDashboardMedia::class=>new \App\Services\Dashboard\DesktopDashboardMedia,
            \App\Services\Dashboard\DesktopDashboardRoleFacts::class=>new \App\Services\Dashboard\DesktopDashboardRoleFacts];
        return (new \App\Http\Controllers\Api\DesktopDashboardController)->bootstrap(new \Illuminate\Http\Request($token),$devices,
            new \App\Services\Dashboard\DesktopDashboardBootstrap($devices,new MemoryDataset));
    }
    function expectedFailure($callback,int $status){try{$callback();throw new \RuntimeException('Expected controlled refusal');}catch(BootstrapMemoryFailure $error){memoryCheck($error->status===$status,'controlled HTTP refusal status');return $error;}}
    function childCase(string $case): void {
        $GLOBALS['case']=$case;$startLimit=ini_get('memory_limit');
        $initial=['lower'=>'256M','plain'=>'134217728','lowercase'=>'128m','equal'=>'512M','higher'=>'1G','higherplain'=>'1073741824','unlimited'=>'-1'][$case]??null;
        if($initial!==null)memoryCheck(\ini_set('memory_limit',$initial)!==false,'native test limit accepted');
        if(in_array($case,['unauthorized','revoked','refused','noop','invalid'],true)){
            if($case==='revoked')$GLOBALS['revoked']=true;
            if($case==='refused')$GLOBALS['deny_budget']=true;
            if($case==='noop')$GLOBALS['noop_budget']=true;
            if($case==='invalid')$GLOBALS['invalid_limit']='unknown-limit';
            $error=expectedFailure(fn()=>fixtureController($case==='unauthorized'?'wrong-token':'fixture-device-token'),in_array($case,['unauthorized','revoked'],true)?401:503);
            memoryCheck(($GLOBALS['dataset_queries']??0)===0,'refusal occurs before reading dataset');
            memoryCheck(\ini_get('memory_limit')===$startLimit,'refusal preserves configured memory limit');
            if(in_array($case,['unauthorized','revoked','invalid'],true))memoryCheck(($GLOBALS['budget_sets']??0)===0,'untrusted or unknown state cannot change budget');
            if($error->status===503)memoryCheck(str_contains($error->getMessage(),'512'),'controlled failure explains required request budget');
        }elseif($case==='rows'){
            \ini_set('memory_limit','512M');$query=new MemoryRows('products',500001);
            expectedFailure(fn()=>(new \App\Services\Dashboard\DesktopDashboardData)->rows(['products'=>$query]),413);
            memoryCheck(($GLOBALS['yielded']??0)===500001&&($GLOBALS['get_calls']??0)===0,'row cap stops at first excess cursor row');
        }elseif($case==='bytes'){
            expectedFailure(fn()=>fixtureController(),413);
            memoryCheck(($GLOBALS['get_calls']??0)===0,'encoded-byte guard keeps bounded row iteration');
        }elseif($case==='source'){
            $GLOBALS['source_changed']=true;expectedFailure(fn()=>fixtureController(),409);
        }else{
            $reply=fixtureController();
            memoryCheck($reply->status===200&&$reply->headers['Cache-Control']==='private, no-store','same private JSON response contract');
            memoryCheck(($GLOBALS['get_calls']??0)===0||!empty($GLOBALS['baseline']),'no duplicate unbounded row collection');
            $expectedLimit=in_array($case,['higher','higherplain','unlimited'],true)?$initial:'512M';
            memoryCheck(\ini_get('memory_limit')===$expectedLimit,'budget covers complete controller response serialization and preserves higher/unlimited values');
            if($case==='large'){
                memoryCheck($startLimit==='128M','large fixture starts with actual128MiB limit');
                memoryCheck(memory_get_peak_usage(true)>128*1024*1024,'large fixture genuinely exceeds former128MiB budget');
                memoryCheck(strlen($reply->body)>100*1024*1024,'complete large HTTP JSON response was encoded after export');
            }else{
                $snapshot=json_decode($reply->body,true,512,JSON_THROW_ON_ERROR);
                memoryCheck($snapshot['format']===1&&$snapshot['kind']==='initial-dashboard-data'&&$snapshot['branches']===['f:1'],'snapshot identity and scope unchanged');
                foreach($snapshot['tables'] as $part)memoryCheck($part['sha256']===hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($part['rows'])),'incremental hash exactly matches original array JSON bytes');
                memoryCheck($snapshot['tables']['empty_table']['sha256']===hash('sha256','[]'),'empty-table hash preserved');
                $users=$snapshot['tables']['users']['rows'];memoryCheck($users[1]['password']==='fixture-own-password'&&str_starts_with($users[0]['password'],'!desktop-disabled-')&&$users[0]['remember_token']===null,'only actor login hash retained');
                $row=$snapshot['tables']['products']['rows'][0];$payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                memoryCheck($row['api_key']===null&&$payload['nested']['api_key']===null&&!str_contains($reply->body,'fixture-private-')&&!str_contains($reply->body,'fixture-json-secret'),'direct and nested credentials remain redacted');
                memoryCheck($row['price']===1.0&&$row['name']==='صنف / 1'&&$payload['list']===[1.0,'صنف/'],'float unicode slash JSON semantics preserved');
                memoryCheck($snapshot['coverage']['full_dashboard']===false&&$snapshot['coverage']['role_memberships']===['fixture-role'=>true],'coverage is neither widened nor lost');
            }
        }
        echo json_encode(['case'=>$case,'ok'=>true,'checks'=>$GLOBALS['checks'],'start_limit'=>$startLimit,'end_limit'=>\ini_get('memory_limit'),'peak_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR).PHP_EOL;
    }
    if(($argv[1]??'')==='--case'){childCase($argv[2]??'');exit;}
    $cases=['unauthorized','revoked','refused','noop','invalid','small','lower','plain','lowercase','equal','higher','higherplain','unlimited','rows','bytes','source','large'];
    $run=static function(string $case,bool $baseline=false): array {
        $command=[PHP_BINARY,'-n','-d','memory_limit=128M','-d','display_errors=stderr',__FILE__,'--case',$case];if($baseline)$command[]='--baseline';
        $process=proc_open($command,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,__DIR__.'/..');memoryCheck(is_resource($process),'native child started');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        return compact('code','out','error');
    };
    foreach($cases as $case){$result=$run($case);memoryCheck($result['code']===0,'native '.$case.' failed: '.substr($result['error'].$result['out'],0,2000));$receipt=json_decode(trim($result['out']),true,512,JSON_THROW_ON_ERROR);memoryCheck(($receipt['ok']??false)===true,'native result accepted');echo json_encode($receipt,JSON_THROW_ON_ERROR).PHP_EOL;}
    $baselineChecked=false;
    if(getenv('DESKTOP_MEMORY_BASELINE_BOOTSTRAP')&&getenv('DESKTOP_MEMORY_BASELINE_DATA')){
        $result=$run('large',true);memoryCheck($result['code']!==0&&str_contains($result['error'].$result['out'],'Allowed memory size of 134217728 bytes exhausted'),'same large fixture reproduces baseline128MiB fatal');$baselineChecked=true;
    }
    echo 'GO_ERP_BOOTSTRAP_MEMORY_TESTS_OK '.json_encode(['cases'=>count($cases),'baseline_oom_reproduced'=>$baselineChecked,'checks'=>$GLOBALS['checks']],JSON_THROW_ON_ERROR).PHP_EOL;
}
