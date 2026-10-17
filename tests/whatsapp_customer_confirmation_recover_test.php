<?php
// Exercise the actual recovery CLI through explicit host and application seams.
// These fixtures never connect to a database, network, provider, sender or ERP.
$root=dirname(__DIR__);
$helper=$root.'/deployment/whatsapp_customer_confirmation_recover.php';
$fixture=sys_get_temp_dir().'/wa-confirmation-recover-'.bin2hex(random_bytes(8));
mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){
    if(!is_dir($fixture))return;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($fixture);
});
function recoverExpect($condition,string$label):void{
    $GLOBALS['checks']++;if(!$condition)throw new RuntimeException('CONFIRMATION_RECOVER_TEST_FAILED:'.$label);
}
recoverExpect(is_file($helper),'real recovery helper exists');
$source=file_get_contents($helper);
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);
preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
recoverExpect(count($pins)===10,'narrow reviewed source guard roster');
foreach($pins as[$match,$path,$pin]){
    $content=file_get_contents($root.'/'.$path);$actual=sha1('blob '.strlen($content)."\0".$content);
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);
    file_put_contents($destination,$content);
    $source=str_replace("'".$path."'=>'".$pin."'","'".$path."'=>'".$actual."'",$source,$count);
    recoverExpect($count===1,'one explicit reviewed source seam '.$path);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_RECOVER_CASE')==='bad-owner'?1:0)]",$source,$count);
recoverExpect($count===1,'fixed account owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);
recoverExpect($count===1,'fixed application root seam');
foreach(['vendor','bootstrap']as$directory)mkdir($fixture.'/'.$directory,0700,true);
file_put_contents($fixture.'/recover.php',$source);
file_put_contents($fixture.'/vendor/autoload.php','<?php ');
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB {
        public static function connection(){return new \WaRecoverConnection;}
        public static function table($name){return new \WaRecoverQuery($name);}
    }
    class Schema {
        public static function hasTable($name){return getenv('WA_RECOVER_CASE')!=='missing-schema';}
        public static function hasColumns($name,$columns){return self::hasTable($name);}
    }
}
namespace App\Support {
    class WhatsAppCustomerOrderConfirmation {
        public static function inspect($transcript){
            file_put_contents(getcwd().'/inspected','1');
            if(getenv('WA_RECOVER_CASE')==='source-drift')file_put_contents(getcwd().'/app/Support/WhatsAppOrderExtraction.php',
                file_get_contents(getcwd().'/app/Support/WhatsAppOrderExtraction.php').' changed');
            if(getenv('WA_RECOVER_CASE')==='proof-missing')return['ok'=>false,'reason'=>'CUSTOMER_APPROVAL_REQUIRED','data'=>null];
            return['ok'=>true,'reason'=>null,'data'=>[
                'type'=>'CUSTOMER_CONFIRMED_RECAP','customer'=>['name'=>'PRIVATE_CUSTOMER_NAME','phone'=>'201033333333',
                    'address'=>'PRIVATE_ADDRESS','area'=>'PRIVATE_AREA'],
                'items'=>[['name'=>'PRIVATE_ITEM']],
                'evidence_receipt'=>['version'=>1,'recap_id'=>'m2','approval_id'=>'m3',
                    'recap_sha256'=>str_repeat('a',64),'approval_sha256'=>str_repeat('b',64),'receipt_sha256'=>str_repeat('c',64)],
                'textual_location'=>['source_message_id'=>'m2','literal'=>'PRIVATE_COORDINATES',
                    'coordinate_order'=>'LAT_LONG','values'=>[31.123456,30.654321],
                    'point'=>getenv('WA_RECOVER_CASE')==='point-missing'?null:['lat'=>31.123456,'long'=>30.654321]]]];
        }
    }
    class WhatsAppOrderExtraction {public static function hasProbe($transcript){return getenv('WA_RECOVER_CASE')==='probe';}}
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess {const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class PosServiceTicket {public static function fingerprint($data){return hash('sha256',json_encode($data));}}
    class MetaAiControl {
        private int$calls=0;
        public function state(){
            $this->calls++;return['available'=>true,'policy'=>['mode'=>getenv('WA_RECOVER_CASE')==='reply-mode'?'REPLY_AND_ANALYZE':'ANALYZE_ONLY',
                'enabled'=>getenv('WA_RECOVER_CASE')!=='disabled'],
                'revision'=>getenv('WA_RECOVER_CASE')==='wrong-revision'?3:2];
        }
        public function analysisGate($channel){return['effective'=>getenv('WA_RECOVER_CASE')!=='outside-hours','reason'=>null,'revision'=>2];}
        public function dispatchGate($channel){return['effective'=>false,'reason'=>'DISPATCH_OUTSIDE_HOURS','revision'=>2];}
        public function gate($channel){return['effective'=>false,'reason'=>'ANALYZE_ONLY','revision'=>2];}
    }
    class WhatsAppOrderWorkflow {
        private int$snapshots=0;
        private function settledBefore(){return gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-10);}
        private function pendingCapture($lock=false){return getenv('WA_RECOVER_CASE')==='capture-pending';}
        private function activationBoundary($name,$conversation){return getenv('WA_RECOVER_CASE')==='no-activation'?null:1;}
        private function dispatchedCeiling($conversation){return 0;}
        private function ceiling($conversation,$lock=false){return getenv('WA_RECOVER_CASE')==='wrong-ceiling'?5:4;}
        private function automaticFloor($conversation,$scan,$lock=false){return 1;}
        private function customerReceiptFloor($conversation,$floor,$ceiling){
            return in_array(getenv('WA_RECOVER_CASE'),['wrong-floor','already-recorded'],true)?3:2;
        }
        private function customerReceiptRecoveryScanHash($scan){return getenv('WA_RECOVER_CASE')==='bad-scan-hash'?'not-a-hash':str_repeat('b',64);}
        protected function snapshot($conversation,$floor,$ceiling,$lock=false){
            $this->snapshots++;return['complete'=>getenv('WA_RECOVER_CASE')!=='incomplete',
                'hash'=>str_repeat('a',64),'transcript'=>[
                    ['id'=>'m2','speaker'=>'customer','text'=>'PRIVATE_RECAP','location'=>null],
                    ['id'=>'m3','speaker'=>'customer','text'=>'PRIVATE_APPROVAL','location'=>null]]];
        }
        public function recoverCustomerReceipt(int$conversation,array$expected):array{
            $path=getcwd().'/recovery-calls';
            $calls=is_file($path)?json_decode(file_get_contents($path),true):[];
            $calls[]=['conversation'=>$conversation,'expected'=>$expected];
            file_put_contents($path,json_encode($calls));
            if($conversation!==11||array_keys($expected)!==['control_revision','floor','ceiling','snapshot_hash','scan_hash']
                ||$expected!==['control_revision'=>2,'floor'=>2,'ceiling'=>4,'snapshot_hash'=>str_repeat('a',64),'scan_hash'=>str_repeat('b',64)])
                throw new \RuntimeException('PRIVATE_INVALID_RECOVERY_EXPECTATION');
            $sql=is_file(getcwd().'/sql')?file(getcwd().'/sql',FILE_IGNORE_NEW_LINES):[];
            if($sql!==['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE'])
                throw new \RuntimeException('PRIVATE_SQL_AUTHORITY_ORDER');
            if(getenv('WA_RECOVER_CASE')==='recovery-throws')throw new \RuntimeException('PRIVATE_COMMIT_OUTCOME_UNCERTAIN');
            if(getenv('WA_RECOVER_CASE')==='outside-hours')throw new \RuntimeException('ANALYSIS_OUTSIDE_HOURS');
            if(getenv('WA_RECOVER_CASE')==='auto-not-configured')throw new \RuntimeException('AUTO_NOT_CONFIGURED');
            $already=getenv('WA_RECOVER_CASE')==='already-recorded';
            $result=['success'=>true,'status'=>$already?'ALREADY_RECORDED':'RECORDED','draft_id'=>100,
                'writes'=>$already?0:2,'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,
                'PRIVATE_UNREVIEWED_RESULT'=>'PRIVATE_CUSTOMER_NAME'];
            foreach(['ai_calls','message_sends','erp_writes']as$key)
                if(getenv('WA_RECOVER_CASE')==='bad-result-'.$key)$result[$key]=1;
            if(getenv('WA_RECOVER_CASE')==='bad-result-writes')$result['writes']=0;
            if(getenv('WA_RECOVER_CASE')==='bad-result-status')$result['status']='PRIVATE_ARBITRARY_STATUS';
            if(getenv('WA_RECOVER_CASE')==='bad-result-id')$result['draft_id']='100';
            if(getenv('WA_RECOVER_CASE')==='bad-result-success')$result['success']=false;
            return$result;
        }
    }
}
namespace {
    class WaRecoverConnection {
        private int$levels=0;
        public function getDriverName(){return getenv('WA_RECOVER_CASE')==='bad-driver'?'pgsql':'mysql';}
        public function getPdo(){return new WaRecoverPdo;}
        public function transactionLevel(){
            $this->levels++;return getenv('WA_RECOVER_CASE')==='initial-transaction'
                ||getenv('WA_RECOVER_CASE')==='boundary-transaction'&&$this->levels>1?1:0;
        }
    }
    class WaRecoverPdo {
        public function exec($query){
            if(!in_array($query,['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE'],true))
                throw new \RuntimeException('PRIVATE_UNEXPECTED_SQL');
            file_put_contents(getcwd().'/sql',$query."\n",FILE_APPEND);
            if(getenv('WA_RECOVER_CASE')==='read-only-failure'&&$query==='SET SESSION TRANSACTION READ ONLY')
                throw new \RuntimeException('PRIVATE_READ_ONLY_FAILURE');
            if(getenv('WA_RECOVER_CASE')==='read-write-failure'&&$query==='SET SESSION TRANSACTION READ WRITE')
                throw new \RuntimeException('PRIVATE_READ_WRITE_FAILURE');
            return 0;
        }
    }
    class WaRecoverQuery {
        private string$table;private array$conditions=[];
        public function __construct($table){$this->table=$table;}
        public function where($key,$operator,$value=null){$this->conditions[$key]=func_num_args()===2?$operator:[$operator,$value];return$this;}
        public function count(){
            if($this->table!=='whatsapp_order_drafts'||$this->conditions!==['conversation_id'=>11,'evidence_floor'=>2,'evidence_ceiling'=>4])
                throw new \RuntimeException('PRIVATE_UNSCOPED_DRAFT_COUNT');
            return getenv('WA_RECOVER_CASE')==='already-recorded'?1:0;
        }
        public function first($columns=null){
            if($this->table==='whatsapp_inbox_conversations'){
                if(($this->conditions['id']??null)!==11||($this->conditions['waba_id']??null)!=='TEST_WABA'
                    ||($this->conditions['phone_number_id']??null)!=='TEST_PHONE')throw new \RuntimeException('PRIVATE_UNSCOPED_CONVERSATION');
                return getenv('WA_RECOVER_CASE')==='wrong-scope'?null:(object)['id'=>11];
            }
            if($this->table==='whatsapp_order_scans'){
                if(($this->conditions['conversation_id']??null)!==11)throw new \RuntimeException('PRIVATE_UNSCOPED_SCAN');
                if(getenv('WA_RECOVER_CASE')==='no-scan')return null;
                return(object)['conversation_id'=>11,'auto_floor'=>1,'analyzed_ceiling'=>1,'claimed_ceiling'=>4,
                    'attempts'=>3,'next_attempt_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-60),
                    'claim_nonce'=>null,'lease_until'=>null,'updated_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-90)];
            }
            throw new \RuntimeException('PRIVATE_UNEXPECTED_TABLE');
        }
    }
    class WaRecoverKernel {public function bootstrap(){}}
    class WaRecoverApp {
        private array$objects=[];
        public function make($class){
            if($class==='Illuminate\\Contracts\\Console\\Kernel')return new WaRecoverKernel;
            return$this->objects[$class]??=new$class;
        }
    }
    $GLOBALS['fixtureNow']=time();file_put_contents(getcwd().'/bootstrapped','1');return new WaRecoverApp;
}
PHP);
function recoverRun(string$case,array$args=['--conversation','11','--expected-floor','2','--expected-ceiling','4','--expected-revision','2']):array{
    $fixture=$GLOBALS['fixture'];foreach(['bootstrapped','inspected','sql','recovery-calls']as$name)@unlink($fixture.'/'.$name);
    $guard=$fixture.'/app/Support/WhatsAppOrderExtraction.php';$original=is_file($guard)?file_get_contents($guard):null;
    if($case==='stale-source'&&$original!==null)file_put_contents($guard,$original.' changed');
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;
    $process=proc_open([$php,$fixture.'/recover.php',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_RECOVER_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($process);if($original!==null)file_put_contents($guard,$original);
    recoverExpect($errors===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201033333333')
        &&!str_contains($output,'31.123456')&&!str_contains($output,'30.654321'),'no customer, coordinates or exception text '.$case);
    return['exit'=>$exit,'result'=>json_decode($output,true,32,JSON_THROW_ON_ERROR),
        'bootstrapped'=>is_file($fixture.'/bootstrapped'),'inspected'=>is_file($fixture.'/inspected'),
        'sql'=>is_file($fixture.'/sql')?file($fixture.'/sql',FILE_IGNORE_NEW_LINES):[],
        'calls'=>is_file($fixture.'/recovery-calls')?json_decode(file_get_contents($fixture.'/recovery-calls'),true):[]];
}
$validArgs=['--conversation','11','--expected-floor','2','--expected-ceiling','4','--expected-revision','2'];
foreach([
    [],['--conversation','11'],
    ['--conversation','11','--expected-floor','2','--expected-ceiling','4'],
    ['--conversation','11','--expected-floor','2','--expected-revision','2'],
    ['--conversation','11','--expected-ceiling','4','--expected-revision','2'],
    [...$validArgs,'--force'],[...$validArgs,'--conversation','11'],
    [...$validArgs,'--expected-floor','2'],[...$validArgs,'--expected-ceiling','4'],
    [...$validArgs,'--expected-revision','2'],
    ['--conversation','0','--expected-floor','2','--expected-ceiling','4','--expected-revision','2'],
    ['--conversation','11','--expected-floor','-1','--expected-ceiling','4','--expected-revision','2'],
    ['--conversation','11','--expected-floor','4','--expected-ceiling','4','--expected-revision','2'],
    ['--conversation','11','--expected-floor','2','--expected-ceiling','4','--expected-revision','0'],
    ['--conversation','11','--expected-floor','2','--expected-ceiling','4','--expected-revision','99999999999999999999'],
    ['--conversation=11','--expected-floor','2','--expected-ceiling','4','--expected-revision','2'],
]as$index=>$args){
    $run=recoverRun('arguments',$args);
    recoverExpect($run['exit']===1&&($run['result']['stage']??null)==='ARGUMENTS'
        &&($run['result']['writes']??null)===0&&!$run['bootstrapped']&&$run['calls']===[]&&$run['sql']===[],
        'invalid or ambiguous CLI arguments stop before app bootstrap '.$index);
}
foreach(['bad-owner'=>'OWNER','stale-source'=>'SOURCE_GUARD','bad-driver'=>'READ_ONLY_SESSION']as$case=>$stage){
    $run=recoverRun($case);
    recoverExpect($run['exit']===1&&($run['result']['stage']??null)===$stage
        &&($run['result']['writes']??null)===0&&$run['calls']===[]&&$run['sql']===[],
        'host/source/driver guard cannot reach write authority '.$case);
    if($case!=='bad-driver')recoverExpect(!$run['bootstrapped'],'host/source rejection happens before bootstrap '.$case);
}
foreach(['wrong-scope','wrong-floor','wrong-ceiling','wrong-revision','no-scan','incomplete','proof-missing',
    'reply-mode','disabled','no-activation','point-missing','bad-scan-hash','source-drift','boundary-transaction']as$case){
    $run=recoverRun($case);
    recoverExpect($run['exit']===1&&($run['result']['status']??null)==='RECOVERY_BLOCKED'
        &&($run['result']['writes']??null)===0&&$run['calls']===[]
        &&$run['sql']===['SET SESSION TRANSACTION READ ONLY'],
        'preflight mismatch stays read-only and never calls recovery '.$case);
}
$run=recoverRun('initial-transaction');
recoverExpect($run['exit']===1&&$run['result']['stage']==='READ_ONLY_SESSION'&&$run['result']['writes']===0
    &&$run['calls']===[]&&$run['sql']===[],'existing connection transaction cannot cross session guard');
$run=recoverRun('read-only-failure');
recoverExpect($run['exit']===1&&$run['result']['stage']==='READ_ONLY_SESSION'&&$run['result']['writes']===0
    &&$run['calls']===[]&&$run['sql']===['SET SESSION TRANSACTION READ ONLY'],'read-only mode must succeed before any app read');
$run=recoverRun('read-write-failure');
recoverExpect($run['exit']===1&&$run['result']['stage']==='SCOPED_RECOVERY'&&$run['result']['writes']===0
    &&$run['calls']===[]&&$run['sql']===['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE'],
    'failed read-write transition never invokes recovery');
foreach(['valid'=>'RECORDED','already-recorded'=>'ALREADY_RECORDED']as$case=>$status){
    $run=recoverRun($case);
    recoverExpect($run['exit']===0&&$run['result']['status']===$status&&$run['result']['draft_id']===100
        &&$run['result']['writes']===($status==='RECORDED'?2:0)&&count($run['calls'])===1,
        'valid scoped recovery or exact prior receipt succeeds '.$case);
    recoverExpect($run['sql']===['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE']
        &&$run['calls'][0]===['conversation'=>11,'expected'=>['control_revision'=>2,'floor'=>2,'ceiling'=>4,
            'snapshot_hash'=>str_repeat('a',64),'scan_hash'=>str_repeat('b',64)]],
        'single core call binds exact conversation, revision, native bounds, snapshot and scan '.$case);
    foreach(['ai_calls','message_sends','erp_writes','graph_gets']as$key)
        recoverExpect($run['result'][$key]===0,'scoped recovery reports no external effect '.$case.' '.$key);
    recoverExpect(!isset($run['result']['snapshot_hash'],$run['result']['scan_hash'])
        &&!array_key_exists('PRIVATE_UNREVIEWED_RESULT',$run['result']),
        'safe projection excludes private proof and unreviewed domain result extras '.$case);
}
foreach(['recovery-throws','bad-result-ai_calls','bad-result-message_sends','bad-result-erp_writes',
    'bad-result-writes','bad-result-status','bad-result-id','bad-result-success']as$case){
    $run=recoverRun($case);
    recoverExpect($run['exit']===1&&$run['result']['status']==='RECOVERY_UNVERIFIED'
        &&array_key_exists('writes',$run['result'])&&$run['result']['writes']===null&&count($run['calls'])===1,
        'unknown post-call outcome never claims zero database writes '.$case);
    recoverExpect($run['result']['reason']===($case==='recovery-throws'?'UNVERIFIED':'RESULT_UNVERIFIED'),
        'post-call error output preserves only reviewed reason codes '.$case);
}
foreach(['outside-hours'=>'ANALYSIS_OUTSIDE_HOURS','auto-not-configured'=>'AUTO_NOT_CONFIGURED']as$case=>$reason){
    $run=recoverRun($case);
    recoverExpect($run['exit']===1&&$run['result']['status']==='RECOVERY_UNVERIFIED'
        &&$run['result']['writes']===null&&count($run['calls'])===1&&$run['result']['reason']===$reason,
        'scoped recovery retains reviewed control/hours failure without false zero writes '.$case);
}
echo 'whatsapp-customer-confirmation-recover: '.$checks." checks passed\n";
