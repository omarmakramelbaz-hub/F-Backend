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
statusExpect(count($pins)===11,'narrow source guard roster');
foreach($pins as[$all,$path,$pin]){
    $content=file_get_contents($root.'/'.$path);$actual=sha1('blob '.strlen($content)."\0".$content);
    if($path==='app/Services/Dashboard/WhatsAppOrderWorkflow.php')statusExpect($pin===$actual,'status guards the exact reviewed preparation workflow');
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);
    file_put_contents($destination,$content);
    $source=str_replace("'".$path."'=>'".$pin."'","'".$path."'=>'".$actual."'",$source,$count);
    statusExpect($count===1,'one reviewed source seam '.$path);
}
$protocolPath=$fixture.'/app/Support/WhatsAppInboxProtocol.php';$protocolOriginal=file_get_contents($protocolPath);
$protocolBefore=<<<'PHP'
$report = ['messages' => [], 'quarantined_count' => 0, 'ignored_count' => 0, 'status_count' => 0];
PHP;
$protocolAfter=<<<'PHP'
$report = ['messages' => [], 'statuses' => [], 'quarantined_count' => 0, 'ignored_count' => 0, 'status_count' => 0];
PHP;
$protocolAnchor="                        \$report['status_count']++;\n";
$protocolExtension=<<<'PHP'
                        // Receipts keep their own identity; never turn one into an inbox
                        // message or let an unscoped account update outbound records.
                        $timestamp = $status['timestamp'] ?? null;
                        if ((is_string($timestamp) || is_int($timestamp))
                            && preg_match('/\A[0-9]{1,10}\z/', (string) $timestamp)
                            && (int) $timestamp > 0 && (int) $timestamp <= min(2147483647, time() + 86400)) {
                            $report['statuses'][] = ['message_id' => $status['id'], 'status' => $status['status'],
                                'sent_at' => gmdate('Y-m-d H:i:s', (int) $timestamp),
                                'recipient_phone' => self::phone($status['recipient_id'] ?? null)];
                        }
PHP;
statusExpect(sha1('blob '.strlen($protocolOriginal)."\0".$protocolOriginal)==='c64f8cf89437bb7822fc767202f7cf5112bfd7fd'
    &&substr_count($protocolOriginal,$protocolBefore)===1&&substr_count($protocolOriginal,$protocolAnchor)===1,'reviewed original protocol fixture');
$protocolAlternate=str_replace($protocolBefore,$protocolAfter,$protocolOriginal);
$protocolAlternate=str_replace($protocolAnchor,$protocolAnchor.$protocolExtension."\n",$protocolAlternate);
statusExpect(sha1('blob '.strlen($protocolAlternate)."\0".$protocolAlternate)==='f2eec9099f9a27915cda50b7c8e267efb1f0b030',
    'reviewed additive CRM protocol fixture');
file_put_contents($fixture.'/reviewed-alternate-protocol',$protocolAlternate);
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
            if(getenv('WA_STATUS_CASE')==='metadata-missing'&&in_array($name,
                ['whatsapp_order_scans','whatsapp_reply_requests','whatsapp_inbox_messages'],true))return false;
            return$name!=='pos_branch_print_jobs'||getenv('WA_STATUS_CASE')==='erp-queue';
        }
        public static function hasColumns($name,$columns){return self::hasTable($name);}
    }
}
namespace App\Support {
    class WhatsAppCustomerOrderConfirmation {
        public static function inspect($transcript){
            file_put_contents(getcwd().'/inspected','1');
            if(getenv('WA_STATUS_CASE')==='protocol-drift')file_put_contents(getcwd().'/app/Support/WhatsAppInboxProtocol.php',
                file_get_contents(getcwd().'/reviewed-alternate-protocol'));
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
        private function settledBefore(){return gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-10);}
        private function debounceSeconds(){return 10;}
        private function pendingCapture($lock=false){
            if($lock)throw new \RuntimeException('UNEXPECTED_LOCK');
            if(getenv('WA_STATUS_CASE')==='metadata-missing')throw new \RuntimeException('UNAVAILABLE');
            return getenv('WA_STATUS_CASE')==='capture-pending';
        }
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
        public function where($key,$operator,$value=null){$this->conditions[$key]=func_num_args()===2?$operator:[$operator,$value];return$this;}
        public function orderByDesc($key){return$this;}
        public function select(...$columns){return$this;}
        public function selectRaw($sql){return$this;}
        public function exists(){return true;}
        public function max($column){
            if($this->table!=='whatsapp_inbox_messages'||$column!=='created_at'
                ||($this->conditions['conversation_id']??null)!==11)throw new \RuntimeException('UNEXPECTED_MAX');
            $GLOBALS['observedReads']=($GLOBALS['observedReads']??0)+1;
            $offset=getenv('WA_STATUS_CASE')==='settling'?-1:-30;
            if(getenv('WA_STATUS_CASE')==='inbound-metadata-drift'&&$GLOBALS['observedReads']>1)$offset++;
            return gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']+$offset);
        }
        public function count(){
            if($this->table!=='whatsapp_reply_requests'||($this->conditions['conversation_id']??null)!==11
                ||($this->conditions['waba_id']??null)!=='TEST_WABA'||($this->conditions['phone_number_id']??null)!=='TEST_PHONE'
                ||($this->conditions['kind']??null)!==['!=','reaction']
                ||!in_array($this->conditions['state']??null,['CLAIMED','UNKNOWN'],true))throw new \RuntimeException('UNSCOPED_REPLY_COUNT');
            if(getenv('WA_STATUS_CASE')==='unknown-reply'&&$this->conditions['state']==='UNKNOWN')return 1;
            if(getenv('WA_STATUS_CASE')==='claimed-reply'&&$this->conditions['state']==='CLAIMED')return 1;
            return 0;
        }
        public function first($columns=null){
            if($this->table==='whatsapp_order_scans'){
                if(($this->conditions['conversation_id']??null)!==11)throw new \RuntimeException('UNSCOPED_SCAN');
                if(getenv('WA_STATUS_CASE')==='no-scan')return null;
                $GLOBALS['scanReads']=($GLOBALS['scanReads']??0)+1;
                $nonce=in_array(getenv('WA_STATUS_CASE'),['live-lease','expired-lease'],true)?'PRIVATE_CLAIM_NONCE':null;
                return(object)['auto_floor'=>1,'analyzed_ceiling'=>3,'claimed_ceiling'=>4,
                    'attempts'=>getenv('WA_STATUS_CASE')==='scan-drift'&&$GLOBALS['scanReads']>1?2:1,
                    'next_attempt_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']+(getenv('WA_STATUS_CASE')==='backoff-future'?60:-60)),
                    'claim_nonce'=>$nonce,'lease_until'=>$nonce===null?null:gmdate('Y-m-d H:i:s',
                        $GLOBALS['fixtureNow']+(getenv('WA_STATUS_CASE')==='live-lease'?120:-120)),
                    'updated_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-90)];
            }
            if($this->table==='whatsapp_inbox_messages'){
                if(($this->conditions['conversation_id']??null)!==11||($this->conditions['direction']??null)!=='inbound')
                    throw new \RuntimeException('UNSCOPED_INBOUND');
                return(object)['id'=>4,'source'=>'messages','type'=>'text','sent_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-40),
                    'created_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-30)];
            }
            if($this->table==='whatsapp_inbox_conversations'){
                if(($this->conditions['id']??null)!==11||($this->conditions['waba_id']??null)!=='TEST_WABA'
                    ||($this->conditions['phone_number_id']??null)!=='TEST_PHONE')return null;
                return(object)['id'=>11];
            }
            if($this->table==='whatsapp_order_drafts'){
                if(getenv('WA_STATUS_CASE')==='no-draft'||isset($this->conditions['id'])&&$this->conditions['id']!==100)return null;
                return(object)['id'=>100,'status'=>str_starts_with(getenv('WA_STATUS_CASE'),'erp')?'DISPATCHED':'REVIEW',
                    'reason'=>'LOCATION_UNCONFIRMED','evidence_floor'=>1,'evidence_ceiling'=>4,'evidence_hash'=>str_repeat('a',64),
                    'revision'=>3,'updated_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['fixtureNow']-120),
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
    $GLOBALS['fixtureNow']=time();file_put_contents(getcwd().'/bootstrapped','1');return new WaStatusApp;
}
PHP);
function statusRun(string$case,array$args=['--conversation','11']):array{
    $fixture=$GLOBALS['fixture'];foreach(['inspected','read-only','bootstrapped']as$name)@unlink($fixture.'/'.$name);
    $guard=$fixture.'/app/Support/WhatsAppOrderExtraction.php';$original=file_get_contents($guard);
    if($case==='stale-source')file_put_contents($guard,$original.' changed');
    $protocol=$fixture.'/app/Support/WhatsAppInboxProtocol.php';$protocolBefore=file_get_contents($protocol);
    if($case==='protocol-alternate')file_put_contents($protocol,$GLOBALS['protocolAlternate']);
    if($case==='protocol-unknown')file_put_contents($protocol,$GLOBALS['protocolAlternate']."\n// unreviewed change\n");
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;
    $pipes=[];$process=proc_open([$php,$fixture.'/status.php',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_STATUS_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($process);file_put_contents($guard,$original);file_put_contents($protocol,$protocolBefore);
    statusExpect($errors===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201033333333')
        &&!str_contains($output,'31.123456')&&!str_contains($output,'30.654321'),'no customer/coordinate/error leakage '.$case);
    return[$exit,json_decode($output,true,32,JSON_THROW_ON_ERROR),is_file($fixture.'/bootstrapped'),is_file($fixture.'/inspected'),is_file($fixture.'/read-only')];
}
[$exit,$value,$booted,$inspected,$readOnly]=statusRun('valid');
statusExpect($exit===0&&$value['status']==='READ_READY'&&$booted&&$inspected&&$readOnly,'real helper read path succeeds');
statusExpect($value['candidate']['valid']&&$value['candidate']['customer_recap_captured']&&$value['candidate']['customer_approval_captured'],
    'recap and independent approval observed');
statusExpect($value['candidate']['approval_basis']==='SEPARATE_CUSTOMER_APPROVAL'&&!$value['candidate']['recap_is_approval'],
    'legacy separate approval is distinguished from self-confirmed compact authority');
statusExpect($value['candidate']['items_count']===1&&$value['candidate']['coordinates_valid']
    &&$value['candidate']['coordinate_order']==='LAT_LONG','item and location proof metadata only');
statusExpect($value['control']['analysis']['effective']===false&&$value['control']['dispatch']['effective']===true
    &&$value['control']['automatic_replies']['effective']===false,'independent gates remain distinct');
statusExpect($value['draft']['uses_customer_receipt']&&$value['draft']['matches_current_snapshot'],'stored protocol/snapshot match observed');
statusExpect($value['draft']['revision']===3&&$value['draft']['evidence_floor']===1&&$value['draft']['evidence_ceiling']===4
    &&is_string($value['draft']['updated_at_utc']),'draft native bounds and revision are explicit');
statusExpect($value['scan']['present']&&$value['scan']['auto_floor']===1&&$value['scan']['analyzed_ceiling']===3
    &&$value['scan']['claimed_ceiling']===4&&$value['scan']['attempts']===1&&$value['scan']['backoff_due']
    &&!$value['scan']['live_lease']&&$value['scan_unchanged_during_read'],'retry/lease metadata is observed without processing');
statusExpect($value['latest_inbound']['id']===4&&$value['latest_inbound']['native_type']==='text'
    &&$value['settling']['settled']&&$value['debounce_seconds']===10&&!$value['capture']['pending'],
    'bounded inbound timestamps and conversation settling guard are visible');
[$compatExit,$compatValue]=statusRun('protocol-alternate');statusExpect($compatExit===0&&$compatValue['status']==='READ_READY'
    &&$compatValue['candidate']['valid'],'exact reviewed CRM protocol also passes initial and final guards');
[$compatExit,$compatValue,$compatBooted]=statusRun('protocol-unknown');statusExpect($compatExit===1
    &&$compatValue['stage']==='SOURCE_GUARD'&&!$compatBooted,'unknown protocol version fails before bootstrap');
[$compatExit,$compatValue]=statusRun('protocol-drift');statusExpect($compatExit===1&&$compatValue['stage']==='FINAL_GUARD',
    'swapping between two compatible protocols during observation still fails final exact source guard');
foreach(['writes','ai_calls','message_sends','erp_writes','graph_gets']as$key)statusExpect($value[$key]===0,'zero actions '.$key);
[$exit,$value]=statusRun('approval-missing');statusExpect($exit===0&&!$value['candidate']['valid']
    &&$value['candidate']['reason']==='CUSTOMER_APPROVAL_REQUIRED','copied recap without approval not treated as confirmed');
[$exit,$value]=statusRun('suffix');statusExpect($exit===0&&$value['base_floor']===1&&$value['floor']===2
    &&$value['self_contained_suffix_used']&&$value['snapshot_current_at_read'],
    'workflow-selected complete recap suffix is observed without changing saved activation');
foreach(['snapshot-drift','inbound-drift','policy-drift']as$case){
    [$exit,$value]=statusRun($case);statusExpect($exit===0&&$value['status']==='OBSERVATION_CHANGED','concurrent state change observed '.$case);
}
foreach(['scan-drift','inbound-metadata-drift']as$case){
    [$exit,$value]=statusRun($case);statusExpect($exit===0&&$value['status']==='OBSERVATION_CHANGED'
        &&!$value['worker_metadata_unchanged_during_read'],'worker metadata drift cannot claim a stable observation '.$case);
    if($case==='scan-drift')statusExpect(!$value['scan_unchanged_during_read'],'scan change specifically identified');
}
[$exit,$value]=statusRun('no-scan');statusExpect($exit===0&&$value['scan']['available']&&!$value['scan']['present'],
    'absent scan differs from unavailable schema');
[$exit,$value]=statusRun('live-lease');statusExpect($exit===0&&$value['scan']['claim_nonce_present']&&$value['scan']['live_lease'],
    'live claimed lease visible without leaking nonce');
[$exit,$value]=statusRun('expired-lease');statusExpect($exit===0&&$value['scan']['claim_nonce_present']&&!$value['scan']['live_lease'],
    'expired lease is not reported as live');
[$exit,$value]=statusRun('backoff-future');statusExpect($exit===0&&!$value['scan']['backoff_due'],
    'future backoff visible without resetting attempts');
foreach(['unknown-reply'=>'unknown','claimed-reply'=>'claimed']as$case=>$kind){
    [$exit,$value]=statusRun($case);statusExpect($exit===0&&$value['unresolved_replies']['available']
        &&$value['unresolved_replies']['count']===1&&$value['unresolved_replies'][$kind]===1,
        'unresolved nonreaction reply is scoped and counted '.$kind);
}
[$exit,$value]=statusRun('capture-pending');statusExpect($exit===0&&$value['capture']['available']&&$value['capture']['pending'],
    'global raw capture guard is reported without consumption');
[$exit,$value]=statusRun('settling');statusExpect($exit===0&&!$value['settling']['settled'],
    'newly persisted message reports settling without claiming processing eligibility');
[$exit,$value]=statusRun('metadata-missing');statusExpect($exit===0&&!$value['scan']['available']
    &&!$value['latest_inbound']['available']&&!$value['unresolved_replies']['available']&&!$value['capture']['available'],
    'optional missing schema/observer metadata does not block main receipt observation');
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
