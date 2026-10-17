<?php
// Execute the diagnostic itself against explicit host/source/domain fixture seams.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_authority.php');
$fixture=sys_get_temp_dir().'/wa-authority-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){
    if(!is_dir($fixture))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);
});
function authorityExpect($condition,string$label):void{
    $GLOBALS['checks']++;if(!$condition)throw new RuntimeException('AUTHORITY_DIAGNOSTIC_TEST_FAILED:'.$label);
}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
authorityExpect(count($pins)===10,'exact source guard roster');
foreach($pins as[$all,$path,$pin]){
    $content=file_get_contents($root.'/'.$path);$actual=sha1('blob '.strlen($content)."\0".$content);
    authorityExpect($actual===$pin,'source pin matches reviewed local file '.$path);
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);
    file_put_contents($destination,$content);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_AUTH_CASE')==='owner'?1:0)]",$source,$count);
authorityExpect($count===1,'explicit owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);
authorityExpect($count===1,'explicit root seam');
authorityExpect(!preg_match('/(?:->|::)(?:analyze|process|extract|dispatch|send|quote|save|insert|update|delete|prepare|pauseWhatsApp|projectAcceptedReply|transaction|beginTransaction|lockForUpdate)\s*\(/',$source),
    'no domain mutation worker sender projection transaction or row lock');
authorityExpect(substr_count($source,'SET SESSION TRANSACTION READ ONLY')===1&&!str_contains($source,'READ WRITE'),
    'session stays read only');
foreach(['vendor','bootstrap']as$directory)mkdir($fixture.'/'.$directory,0700,true);
file_put_contents($fixture.'/authority.php',$source);file_put_contents($fixture.'/vendor/autoload.php','<?php ');
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB {
        public static function connection(){return new \AuthorityConnection;}
        public static function table($name){return new \AuthorityQuery($name);}
    }
    class Schema {
        public static function hasTable($name){return!(getenv('WA_AUTH_CASE')==='missing-schema'&&$name==='whatsapp_inbox_ingestion_failures');}
        public static function hasColumn($table,$column){return getenv('WA_AUTH_CASE')!=='historical-no-time';}
    }
}
namespace App\Support {
    class WhatsAppOrderExtraction {public static function hasProbe($transcript){return getenv('WA_AUTH_CASE')==='probe';}}
    class WhatsAppCustomerOrderConfirmation {
        public static function inspect($transcript){return['ok'=>true,'data'=>['customer'=>['phone'=>'PRIVATE_PHONE'],
            'evidence_receipt'=>['approval_sha256'=>'PRIVATE_APPROVAL_HASH']]];}
    }
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess {const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class MetaAiControl {
        private int$calls=0;
        public function state(){return['available'=>true,'policy'=>['enabled'=>true,'mode'=>'ANALYZE_ONLY'],
            'revision'=>getenv('WA_AUTH_CASE')==='control-drift'&&++$this->calls>1?14:13];}
    }
    class WhatsAppOrderWorkflow {
        private int$snapshots=0;private int$activations=0;
        private function ceiling($conversation){return 10046;}
        private function activationBoundary($name,$conversation){return getenv('WA_AUTH_CASE')==='activation-drift'&&++$this->activations>1?6560:6559;}
        private function dispatchedCeiling($conversation){return 0;}
        private function customerReceiptFloor($conversation,$floor,$ceiling){return$floor;}
        private function snapshot($conversation,$floor,$ceiling){
            $this->snapshots++;return['complete'=>getenv('WA_AUTH_CASE')!=='incomplete',
                'hash'=>str_repeat(getenv('WA_AUTH_CASE')==='snapshot-drift'&&$this->snapshots>1?'f':'a',64),
                'transcript'=>[['id'=>'m1','speaker'=>'customer','text'=>'PRIVATE_RECAP'],
                    ['id'=>'m2','speaker'=>'customer','text'=>'PRIVATE_APPROVAL']]];
        }
        protected function primaryInbound($conversation,$snapshot){
            if(getenv('WA_AUTH_CASE')==='native-error')throw new \RuntimeException('PRIVATE_EXCEPTION_PHONE_201011111111');
            if(getenv('WA_AUTH_CASE')==='native')return null;
            $offset=match(getenv('WA_AUTH_CASE')){'expired'=>-86401,'future'=>60,default=>-60};
            return['id'=>10046,'message_key'=>'PRIVATE_NATIVE_KEY','sent_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['authorityNow']+$offset),
                'label'=>'m2','text'=>'PRIVATE_APPROVAL','kind'=>'text'];
        }
        private function analysisOnly($conversation){return getenv('WA_AUTH_CASE')!=='mode';}
        private function analysisRepliesSettled($conversation){return getenv('WA_AUTH_CASE')!=='replies';}
        private function primaryCancelled($text){return getenv('WA_AUTH_CASE')==='cancelled';}
        private function primaryCaptureClear($conversation,$latest){
            if(getenv('WA_AUTH_CASE')==='capture-error'){
                $e=new \PDOException('PRIVATE_QUERY_WITH_PHONE_201011111111');$e->errorInfo=['42S22',1054,'PRIVATE_SQL'];throw$e;
            }
            return!in_array(getenv('WA_AUTH_CASE'),['capture','historical','historical-no-time'],true);
        }
        private function controlGate($conversation,$latest=null){
            $original=gmdate('Y-m-d H:i:s',$GLOBALS['authorityNow']+(str_starts_with(getenv('WA_AUTH_CASE'),'historical')?60:-120));
            return['effective'=>!($latest!==null&&getenv('WA_AUTH_CASE')==='inbound-control'),
                'reason'=>$latest!==null&&getenv('WA_AUTH_CASE')==='inbound-control'?'BEFORE_ACTIVATION':null,
                'revision'=>13,'actor_id'=>777,'actor'=>(object)['name'=>'PRIVATE_ACTOR'],'mode'=>'ANALYZE_ONLY',
                'floor'=>['message_floor'=>6559,'event_floor'=>10,'started_at_utc'=>gmdate('Y-m-d H:i:s',$GLOBALS['authorityNow']-3600)],
                'backlog_version'=>1,'original_activation_started_at_utc'=>$original];
        }
        private function controlToken($conversation,$latest=null){
            if($latest!==null&&getenv('WA_AUTH_CASE')==='inbound-control')return null;
            return['actor_id'=>777,'revision'=>$latest!==null&&getenv('WA_AUTH_CASE')==='token'?14:13,'private'=>'PRIVATE_TOKEN'];
        }
        private function primaryAuthority($conversation,$snapshot){
            if(in_array(getenv('WA_AUTH_CASE'),['probe','native','expired','future','mode','replies','cancelled','capture','historical','historical-no-time',
                'capture-error','native-error','inbound-control','authority'],true))return null;
            return$this->controlToken($conversation,[]);
        }
    }
}
namespace {
    class AuthorityConnection {
        public function getDriverName(){return getenv('WA_AUTH_CASE')==='driver'?'sqlite':'mysql';}
        public function transactionLevel(){return getenv('WA_AUTH_CASE')==='transaction'?1:0;}
        public function getPdo(){return new AuthorityPdo;}
    }
    class AuthorityPdo {
        public function exec($sql){if($sql!=='SET SESSION TRANSACTION READ ONLY')throw new \RuntimeException('PRIVATE_SQL');
            file_put_contents(getcwd().'/readonly','yes');return 0;}
    }
    class AuthorityQuery {
        private string$table;private array$conditions=[];
        public function __construct($table){$this->table=$table;}
        public function where($key,$operator=null,$value=null){
            if(is_callable($key)){$key($this);return$this;}
            $this->conditions[$key]=func_num_args()===2?$operator:[$operator,$value];return$this;
        }
        public function whereNull($column){return$this;}
        public function orWhereNull($column){return$this;}
        public function orWhere($callback){$callback($this);return$this;}
        public function leftJoin(...$args){return$this;}
        public function orderBy($column){return$this;}
        public function limit($limit){return$this;}
        public function exists(){
            if($this->table!=='whatsapp_inbox_conversations'||$this->conditions!==['id'=>11,'waba_id'=>'TEST_WABA','phone_number_id'=>'TEST_PHONE'])
                throw new \RuntimeException('UNSCOPED_QUERY');return true;
        }
        public function first(){
            if($this->table!=='whatsapp_order_scans'||($this->conditions['conversation_id']??null)!==11)throw new \RuntimeException('UNSCOPED_SCAN');
            $GLOBALS['authorityScanReads']=($GLOBALS['authorityScanReads']??0)+1;
            return(object)['conversation_id'=>11,'attempts'=>getenv('WA_AUTH_CASE')==='scan-drift'&&$GLOBALS['authorityScanReads']>1?2:3,
                'claim_nonce'=>'PRIVATE_CLAIM_NONCE'];
        }
        public function count(){
            if($this->table==='whatsapp_inbox_ingestion_failures'){
                if(!isset($this->conditions['event_id'])&&getenv('WA_AUTH_CASE')!=='historical-no-time')throw new \RuntimeException('UNBOUNDED_COUNT');
                if(isset($this->conditions['event_id'])&&$this->conditions['event_id']!==['>',10])throw new \RuntimeException('WRONG_EVENT_FLOOR');
                return getenv('WA_AUTH_CASE')==='capture'?3:(getenv('WA_AUTH_CASE')==='historical-no-time'?2:0);
            }
            if($this->table!=='whatsapp_inbox_ingestion_failures as ai_failure'||($this->conditions['ai_failure.event_id']??null)!==['<=',10])
                throw new \RuntimeException('WRONG_HISTORICAL_PREDICATE');
            return 2;
        }
        public function min($column){return$this->count()?11:null;}
        public function max($column){return$this->count()?13:null;}
        public function get($columns){
            if($this->table!=='whatsapp_inbox_ingestion_failures as f')throw new \RuntimeException('UNEXPECTED_METADATA');
            $GLOBALS['authorityFailureReads']=($GLOBALS['authorityFailureReads']??0)+1;
            return new AuthorityRows([(object)['event_id'=>11,'reason'=>'PRIVATE_REASON','attempts'=>getenv('WA_AUTH_CASE')==='failure-drift'&&$GLOBALS['authorityFailureReads']>1?2:1,
                'last_attempted_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['authorityNow']),'captured_event_id'=>11]]);
        }
    }
    class AuthorityRows {public function __construct(private array$rows){}public function all(){return$this->rows;}}
    class AuthorityKernel {public function bootstrap(){}}
    class AuthorityApp {
        private array$objects=[];
        public function make($class){return$class==='Illuminate\\Contracts\\Console\\Kernel'?new AuthorityKernel:($this->objects[$class]??=new$class);}
    }
    $GLOBALS['authorityNow']=time();return new AuthorityApp;
}
PHP);
function authorityRun(string$case,array$args=[]):array{
    $fixture=$GLOBALS['fixture'];@unlink($fixture.'/readonly');
    if($args===[])$args=['--conversation','11','--expected-floor','6559','--expected-ceiling','10046','--expected-revision','13'];
    $path=$fixture.'/app/Services/Dashboard/WhatsAppInboxMessage.php';$saved=file_get_contents($path);
    if($case==='source')file_put_contents($path,$saved.' changed');
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;
    $process=proc_open([$php,$fixture.'/authority.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
        array_merge(getenv(),['WA_AUTH_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($process);file_put_contents($path,$saved);
    authorityExpect($stderr===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201011111111')&&!str_contains($output,'777'),
        'no PII keys actor identity exception or SQL prose '.$case);
    $value=json_decode($output,true,32,JSON_THROW_ON_ERROR);
    authorityExpect($value['writes']===0&&$value['ai_calls']===0&&$value['message_sends']===0&&$value['erp_writes']===0&&$value['worker_actions']===0,
        'zero action counters '.$case);
    return[$exit,$value,is_file($fixture.'/readonly')];
}
[$exit,$value,$readonly]=authorityRun('valid');
authorityExpect($exit===0&&$value['status']==='READ_READY'&&$value['reason']==='OBSERVATIONAL_CLEAR'&&$readonly,'unchanged authority is observed read only');
authorityExpect($value['latest_inbound']['id']===10046&&$value['base_control_gate']['activation']['event_floor']===10
    &&$value['inbound_control_gate']['activation']['message_floor']===6559,'safe native and activation metadata');
authorityExpect($value['snapshot_unchanged_during_read']&&$value['scan_unchanged_during_read']&&$value['control_unchanged_during_read']
    &&$value['activation_floor_unchanged_during_read']&&$value['failure_metadata_unchanged_during_read']&&$value['source_unchanged_during_read'],'all metadata currentness checks');
foreach(['probe'=>'PROBE_PRESENT','native'=>'NATIVE_INBOUND_UNAUTHENTICATED','expired'=>'INBOUND_EXPIRED','future'=>'INBOUND_FUTURE',
    'mode'=>'MODE_NOT_ANALYZE_ONLY','replies'=>'REPLIES_UNSETTLED','cancelled'=>'CUSTOMER_CANCELLED','capture'=>'CAPTURE_BLOCKED',
    'historical'=>'CAPTURE_BLOCKED','historical-no-time'=>'CAPTURE_BLOCKED','inbound-control'=>'INBOUND_CONTROL_BLOCKED',
    'token'=>'AUTHORITY_TOKEN_CHANGED','authority'=>'PRIMARY_AUTHORITY_UNAVAILABLE','native-error'=>'GUARD_OBSERVATION_ERROR',
    'capture-error'=>'GUARD_OBSERVATION_ERROR']as$case=>$reason){
    [$exit,$value,$readonly]=authorityRun($case);
    authorityExpect($value['reason']===$reason&&$readonly,'specific existing guard decomposition '.$case);
    if($case==='capture')authorityExpect($value['capture_failures']['forward']===['count'=>3,'min_event_id'=>11,'max_event_id'=>13],
        'forward failures use exact greater-than activation event floor');
    if(str_starts_with($case,'historical'))authorityExpect($value['capture_failures']['historical_guard_applies']
        &&$value['capture_failures']['historical']['count']===2,'historical predicates only when latest precedes original activation '.$case);
    if($case==='capture-error')authorityExpect($value['guards']['capture_clear']['error']===['category'=>'DATABASE','sqlstate'=>'42S22','driver_errno'=>1054],
        'database error category and numeric metadata only');
}
foreach(['snapshot-drift','activation-drift','scan-drift','control-drift','failure-drift','missing-schema']as$case){
    [$exit,$value]=authorityRun($case);authorityExpect($exit===1&&$value['status']==='OBSERVATION_CHANGED','changed or unavailable observations never reported current '.$case);
}
foreach(['owner'=>'OWNER_INVALID','source'=>'SOURCE_CHANGED','driver'=>'READ_ONLY_SESSION_REQUIRED','transaction'=>'READ_ONLY_SESSION_REQUIRED',
    'incomplete'=>'SNAPSHOT_UNAVAILABLE']as$case=>$reason){
    [$exit,$value]=authorityRun($case);authorityExpect($exit===1&&$value['status']==='READ_BLOCKED'&&$value['reason']===$reason,'preconditions block '.$case);
}
[$exit,$value]=authorityRun('arguments',['--conversation','11']);authorityExpect($exit===1&&$value['reason']==='ARGUMENTS_INVALID','all explicit expected bounds required');
echo 'whatsapp_customer_confirmation_authority: '.$checks." checks passed\n";
