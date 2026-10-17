<?php
// Run the real CLI helper against explicit host/database/domain fixture seams.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_status.php');
$fixture=sys_get_temp_dir().'/wa-confirmation-status-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){
    if(!is_dir($fixture))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);
});
function statusExpect($condition,string$label):void{
    $GLOBALS['checks']++;if(!$condition)throw new RuntimeException('CONFIRMATION_STATUS_TEST_FAILED:'.$label);
}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);
preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
statusExpect(count($pins)===10,'narrow source guard roster');
foreach($pins as[$all,$path,$pin]){
    $content=file_get_contents($root.'/'.$path);$actual=sha1('blob '.strlen($content)."\0".$content);
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);
    file_put_contents($destination,$content);
    $source=str_replace("'".$path."'=>'".$pin."'","'".$path."'=>'".$actual."'",$source,$count);
    statusExpect($count===1,'one reviewed source seam '.$path);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_STATUS_CASE')==='bad-owner'?1:0)]",$source,$count);
statusExpect($count===1,'fixed owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);
statusExpect($count===1,'fixed root seam');
statusExpect(!preg_match('/(?:->|::)(?:analyze|process|extract|dispatch|send|quote|save|insert|update|delete|prepare|pauseWhatsApp|projectAcceptedReply)\s*\(/',$source),
    'no domain action or SQL mutation method');
foreach(['vendor','bootstrap']as$directory)mkdir($fixture.'/'.$directory,0700,true);
file_put_contents($fixture.'/status.php',$source);file_put_contents($fixture.'/vendor/autoload.php','<?php ');
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB {
        public static function connection(){return new \WaStatusConnection;}
        public static function table($name){return new \WaStatusQuery($name);}
    }
    class Crypt {public static function decryptString($value){return$value;}}
    class Schema {
        public static function hasTable($name){
            return$name!=='pos_branch_print_jobs'||getenv('WA_STATUS_CASE')==='erp-queue';
        }
        public static function hasColumns($name,$columns){return self::hasTable($name);}
    }
}
namespace App\Support {
    class WhatsAppCustomerOrderConfirmation {
        public static function inspect($transcript){
            file_put_contents(getcwd().'/inspected','1');
            if(getenv('WA_STATUS_CASE')==='approval-missing')return['ok'=>false,'reason'=>'CUSTOMER_APPROVAL_REQUIRED','data'=>null];
            return['ok'=>true,'reason'=>null,'data'=>[
                'type'=>'CUSTOMER_CONFIRMED_RECAP','customer'=>['name'=>'PRIVATE_CUSTOMER_NAME','phone'=>'201033333333',
                    'address'=>'PRIVATE_ADDRESS','area'=>'PRIVATE_AREA'],
                'items'=>[['name'=>'PRIVATE_ITEM']],
                'evidence_receipt'=>['version'=>1,'recap_id'=>'m2','approval_id'=>'m3',
                    'recap_sha256'=>str_repeat('a',64),'approval_sha256'=>str_repeat('b',64),'receipt_sha256'=>str_repeat('c',64)],
                'textual_location'=>['source_message_id'=>'m2','literal'=>'PRIVATE_COORDINATES',
                    'coordinate_order'=>'LAT_LONG','values'=>[31.123456,30.654321],'point'=>['lat'=>31.123456,'long'=>30.654321]]]];
        }
    }
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess {const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class MetaAiControl {
        private int$calls=0;
        public function state(){
            $this->calls++;return['available'=>true,'policy'=>['mode'=>'ANALYZE_ONLY','enabled'=>true],
                'revision'=>getenv('WA_STATUS_CASE')==='policy-drift'&&$this->calls>1?3:2];
        }
        public function analysisGate($channel){return['effective'=>false,'reason'=>'ANALYSIS_OUTSIDE_HOURS','revision'=>2,'secret'=>'PRIVATE_ACTOR'];}
        public function dispatchGate($channel){return['effective'=>true,'reason'=>null,'revision'=>2,'secret'=>'PRIVATE_ACTOR'];}
        public function gate($channel){return['effective'=>false,'reason'=>'ANALYZE_ONLY','revision'=>2,'secret'=>'PRIVATE_ACTOR'];}
    }
    class WhatsAppOrderWorkflow {
        private int$snapshots=0;
        private function activationBoundary($name,$conversation){return 1;}
        private function dispatchedCeiling($conversation){return 0;}
        private function ceiling($conversation){return getenv('WA_STATUS_CASE')==='inbound-drift'&&$this->snapshots>0?5:4;}
        private function customerReceiptFloor($conversation,$floor,$ceiling){return getenv('WA_STATUS_CASE')==='suffix'?2:$floor;}
        protected function snapshot($conversation,$floor,$ceiling){
            $this->snapshots++;return['complete'=>getenv('WA_STATUS_CASE')!=='incomplete',
                'hash'=>str_repeat(getenv('WA_STATUS_CASE')==='snapshot-drift'&&$this->snapshots>1?'f':'a',64),
                'transcript'=>[['id'=>'m1','speaker'=>'customer','text'=>'PRIVATE_TEXT','location'=>null],
                    ['id'=>'m2','speaker'=>'customer','text'=>'PRIVATE_RECAP','location'=>null],
                    ['id'=>'m3','speaker'=>'customer','text'=>'PRIVATE_APPROVAL','location'=>null]]];
        }
    }
}
namespace {
    class WaStatusConnection {
        public function getDriverName(){return getenv('WA_STATUS_CASE')==='bad-driver'?'pgsql':'mysql';}
        public function getPdo(){return new WaStatusPdo;}
    }
    class WaStatusPdo {
        public function exec($query){
            if($query!=='SET SESSION TRANSACTION READ ONLY')throw new \RuntimeException('UNEXPECTED_SQL');
            file_put_contents(getcwd().'/read-only','1');return 0;
        }
    }
    class WaStatusQuery {
        private string$table;private array$conditions=[];
        public function __construct($table){$this->table=$table;}
        public function where($key,$value){$this->conditions[$key]=$value;return$this;}
        public function orderByDesc($key){return$this;}
        public function select(...$columns){return$this;}
        public function selectRaw($sql){return$this;}
        public function exists(){return true;}
        public function first($columns=null){
            if($this->table==='whatsapp_inbox_conversations'){
                if(($this->conditions['id']??null)!==11||($this->conditions['waba_id']??null)!=='TEST_WABA'
                    ||($this->conditions['phone_number_id']??null)!=='TEST_PHONE')return null;
                return(object)['id'=>11];
            }
            if($this->table==='whatsapp_order_drafts'){
                if(getenv('WA_STATUS_CASE')==='no-draft'||isset($this->conditions['id'])&&$this->conditions['id']!==100)return null;
                return(object)['id'=>100,'status'=>str_starts_with(getenv('WA_STATUS_CASE'),'erp')?'DISPATCHED':'REVIEW',
                    'reason'=>'LOCATION_UNCONFIRMED','evidence_floor'=>1,'evidence_ceiling'=>4,'evidence_hash'=>str_repeat('a',64),
                    'ticket_id'=>str_starts_with(getenv('WA_STATUS_CASE'),'erp')?501:null,'assigned_branch'=>'f:363',
                    'extraction'=>json_encode(['type'=>'CUSTOMER_CONFIRMED_RECAP','customer'=>['phone'=>'PRIVATE_PHONE']])];
            }
            if($this->table==='pos_service_tickets')return(object)['id'=>501,'last_kitchen_id'=>601];
            throw new \RuntimeException('UNEXPECTED_TABLE');
        }
    }
    class WaStatusKernel {public function bootstrap(){}}
    class WaStatusApp {
        private array$objects=[];
        public function make($class){
            if($class==='Illuminate\\Contracts\\Console\\Kernel')return new WaStatusKernel;
            return$this->objects[$class]??=new $class;
        }
    }
    file_put_contents(getcwd().'/bootstrapped','1');return new WaStatusApp;
}
PHP);
function statusRun(string$case,array$args=['--conversation','11']):array{
    $fixture=$GLOBALS['fixture'];foreach(['inspected','read-only','bootstrapped']as$name)@unlink($fixture.'/'.$name);
    $guard=$fixture.'/app/Support/WhatsAppOrderExtraction.php';$original=file_get_contents($guard);
    if($case==='stale-source')file_put_contents($guard,$original.' changed');
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;
    $pipes=[];$process=proc_open([$php,$fixture.'/status.php',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_STATUS_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($process);file_put_contents($guard,$original);
    statusExpect($errors===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201033333333')
        &&!str_contains($output,'31.123456')&&!str_contains($output,'30.654321'),'no customer/coordinate/error leakage '.$case);
    return[$exit,json_decode($output,true,32,JSON_THROW_ON_ERROR),is_file($fixture.'/bootstrapped'),is_file($fixture.'/inspected'),is_file($fixture.'/read-only')];
}
[$exit,$value,$booted,$inspected,$readOnly]=statusRun('valid');
statusExpect($exit===0&&$value['status']==='READ_READY'&&$booted&&$inspected&&$readOnly,'real helper read path succeeds');
statusExpect($value['candidate']['valid']&&$value['candidate']['customer_recap_captured']&&$value['candidate']['customer_approval_captured'],
    'recap and independent approval observed');
statusExpect($value['candidate']['items_count']===1&&$value['candidate']['coordinates_valid']
    &&$value['candidate']['coordinate_order']==='LAT_LONG','item and location proof metadata only');
statusExpect($value['control']['analysis']['effective']===false&&$value['control']['dispatch']['effective']===true
    &&$value['control']['automatic_replies']['effective']===false,'independent gates remain distinct');
statusExpect($value['draft']['uses_customer_receipt']&&$value['draft']['matches_current_snapshot'],'stored protocol/snapshot match observed');
foreach(['writes','ai_calls','message_sends','erp_writes','graph_gets']as$key)statusExpect($value[$key]===0,'zero actions '.$key);
[$exit,$value]=statusRun('approval-missing');statusExpect($exit===0&&!$value['candidate']['valid']
    &&$value['candidate']['reason']==='CUSTOMER_APPROVAL_REQUIRED','copied recap without approval not treated as confirmed');
[$exit,$value]=statusRun('suffix');statusExpect($exit===0&&$value['base_floor']===1&&$value['floor']===2
    &&$value['self_contained_suffix_used']&&$value['snapshot_current_at_read'],
    'workflow-selected complete recap suffix is observed without changing saved activation');
foreach(['snapshot-drift','inbound-drift','policy-drift']as$case){
    [$exit,$value]=statusRun($case);statusExpect($exit===0&&$value['status']==='OBSERVATION_CHANGED','concurrent state change observed '.$case);
}
[$exit,$value]=statusRun('erp');statusExpect($exit===0&&$value['draft']['erp']['ticket_scope_verified']
    &&!$value['draft']['erp']['print_queue_observation_available']&&!isset($value['draft']['erp']['print_jobs_present']),
    'missing optional queue is unavailable evidence');
[$exit,$value]=statusRun('erp-queue');statusExpect($exit===0&&$value['draft']['erp']['print_queue_observation_available']
    &&$value['draft']['erp']['print_jobs_present'],'existing optional queue reported without claiming physical printing');
foreach([['bad-owner',['--conversation','11'],'OWNER'],['stale-source',['--conversation','11'],'SOURCE_GUARD'],
    ['bad-args',[],'ARGUMENTS'],['bad-args',['--conversation','0'],'ARGUMENTS'],
    ['bad-args',['--conversation','11','--force'],'ARGUMENTS']]as[$case,$args,$stage]){
    [$exit,$value,$booted,$inspected,$readOnly]=statusRun($case,$args);
    statusExpect($exit===1&&$value['stage']===$stage&&!$booted&&!$inspected&&!$readOnly,'guard before app or transcript '.$stage);
}
[$exit,$value,$booted,$inspected,$readOnly]=statusRun('incomplete');
statusExpect($exit===1&&$value['stage']==='SNAPSHOT'&&!$inspected&&$readOnly,'incomplete snapshot never parsed');
[$exit,$value,$booted,$inspected,$readOnly]=statusRun('bad-driver');
statusExpect($exit===1&&$value['stage']==='READ_ONLY_SESSION'&&!$inspected&&!$readOnly,'unsupported driver never parses');
echo 'whatsapp-customer-confirmation-status: '.$checks." checks passed\n";
