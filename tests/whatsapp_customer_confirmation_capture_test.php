<?php
// Execute the real helper and real pinned protocol with explicit host/database seams.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_capture.php');
$fixture=sys_get_temp_dir().'/wa-capture-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){
    if(!is_dir($fixture))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);
});
function captureExpect($condition,string$label):void{$GLOBALS['checks']++;if(!$condition)throw new RuntimeException('CAPTURE_DIAGNOSTIC_TEST_FAILED:'.$label);}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
captureExpect(count($pins)===10,'narrow source guard roster');
foreach($pins as[$all,$path,$pin]){
    $bytes=file_get_contents($root.'/'.$path);captureExpect(sha1('blob '.strlen($bytes)."\0".$bytes)===$pin,'exact reviewed pin '.$path);
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);file_put_contents($destination,$bytes);
}
foreach(['TakeawayAccess','TakeawayCatalog','WhatsAppInboxConsumer','PhoneDelivery','PosServiceTicket','BranchCustomers','PosServicePhone']as$name)
    file_put_contents($fixture.'/app/Services/Dashboard/'.$name.'.php',is_file($root.'/app/Services/Dashboard/'.$name.'.php')?file_get_contents($root.'/app/Services/Dashboard/'.$name.'.php'):"<?php // explicit public source observation fixture\n");
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_CAPTURE_CASE')==='owner'?1:0)]",$source,$count);captureExpect($count===1,'owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);captureExpect($count===1,'root seam');
captureExpect(!preg_match('/(?:->|::)(?:consume|retry|analyze|process|extract|dispatch|send|quote|save|insert|update|delete|prepare|project|transaction|beginTransaction|lockForUpdate)\s*\(/',$source),
    'no consumer retries mutations worker projection network transaction or row locks');
captureExpect(substr_count($source,'SET SESSION TRANSACTION READ ONLY')===1&&!str_contains($source,'READ WRITE'),'read-only session never reopened');
foreach(['vendor','bootstrap']as$directory)mkdir($fixture.'/'.$directory,0700,true);
file_put_contents($fixture.'/capture.php',$source);
file_put_contents($fixture.'/vendor/autoload.php',"<?php require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';");
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB {public static function connection(){return new \CaptureConnection;}public static function table($name){return new \CaptureQuery($name);}}
    class Crypt {
        public static function decryptString($value){if(getenv('WA_CAPTURE_CASE')==='decrypt')throw new \RuntimeException('PRIVATE_DECRYPT');return$value;}
        public static function getKey(){return str_repeat('k',32);}
    }
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess {const WABA_ID='468336579702269';const PHONE_ID='515388018324075';}
    class MetaAiControl {public function state(){return['available'=>true,'policy'=>['enabled'=>true,'mode'=>'ANALYZE_ONLY'],'revision'=>13];}}
    class WhatsAppOrderWorkflow {
        private int$snapshots=0;
        private function controlGate($conversation){return['floor'=>['event_floor'=>getenv('WA_CAPTURE_CASE')==='event-floor'?13204:13203]];}
        private function controlToken($conversation){return['actor'=>'PRIVATE_ACTOR','revision'=>13];}
        private function activationBoundary($name,$conversation){return 6559;}
        private function ceiling($conversation){return 10046;}
        private function dispatchedCeiling($conversation){return 0;}
        private function customerReceiptFloor($conversation,$floor,$ceiling){return$floor;}
        private function snapshot($conversation,$floor,$ceiling){$this->snapshots++;return['complete'=>true,'hash'=>str_repeat(getenv('WA_CAPTURE_CASE')==='snapshot-drift'&&$this->snapshots>1?'b':'a',64)];}
    }
}
namespace {
    function capturePayload():array{
        $message=['id'=>'wamid.PRIVATE_NATIVE','timestamp'=>(string)($GLOBALS['captureNow']-300),'type'=>'text',
            'from'=>'201011111111','from_user_id'=>'PRIVATE_USER','text'=>['body'=>'PRIVATE_CUSTOMER_TEXT']];
        $value=['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075','display_phone_number'=>'201285545554'],'messages'=>[$message]];
        $field='messages';$case=getenv('WA_CAPTURE_CASE');
        if($case==='contacts-null')$value['contacts']=null;
        if($case==='unknown-kind'){$value['messages'][0]['type']='future_kind';unset($value['messages'][0]['text']);}
        if($case==='invalid-contact')$value['contacts']=[['wa_id'=>'PRIVATE_BAD_PHONE']];
        if($case==='unsupported'){$value['messages'][0]['type']='unsupported';unset($value['messages'][0]['text']);$value['messages'][0]['errors']=[['code'=>131060,'message'=>'PRIVATE_ERROR_PROSE']];}
        if($case==='invalid-status')$value['statuses']=[['id'=>'wamid.PRIVATE_STATUS','status'=>['PRIVATE_STATUS_VALUE']]];
        if($case==='played'){$value['statuses']=[['id'=>'wamid.PRIVATE_STATUS','status'=>'played']];unset($value['messages']);}
        if($case==='played-mixed')$value['statuses']=[['id'=>'wamid.PRIVATE_STATUS','status'=>'played']];
        if($case==='wrapped'){
            $field='standby';unset($value['messages']);$value['standby']=['message_echoes'=>[['id'=>'wamid.PRIVATE_ECHO','timestamp'=>(string)$GLOBALS['captureNow'],
                'message'=>['messaging_product'=>'whatsapp','to'=>'201011111111','type'=>'text','text'=>['body'=>'PRIVATE_ECHO_TEXT']]]]];
        }
        if($case==='phone-scope')$value['metadata']['phone_number_id']='999';
        return['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>$field,'value'=>$value]]]]];
    }
    class CaptureConnection {
        public function getDriverName(){return getenv('WA_CAPTURE_CASE')==='driver'?'sqlite':'mysql';}
        public function transactionLevel(){return getenv('WA_CAPTURE_CASE')==='transaction'?1:0;}
        public function getPdo(){return new CapturePdo;}
    }
    class CapturePdo {public function exec($sql){if($sql!=='SET SESSION TRANSACTION READ ONLY')throw new \RuntimeException('PRIVATE_SQL');file_put_contents(getcwd().'/readonly','1');return 0;}}
    class CaptureRows {public function __construct(private array$rows){}public function all(){return$this->rows;}}
    class CaptureQuery {
        private string$table;private array$conditions=[];
        public function __construct($table){$this->table=$table;}
        public function where($key,$operator,$value=null){$this->conditions[$key]=func_num_args()===2?$operator:[$operator,$value];return$this;}
        public function leftJoin(...$args){return$this;}
        public function orderBy($column){return$this;}
        public function limit($limit){if($limit!==11)throw new \RuntimeException('UNBOUNDED_FAILURE_QUERY');return$this;}
        public function select(...$columns){return$this;}
        public function selectRaw($query){if(!str_contains($query,'CASE WHEN LENGTH('))throw new \RuntimeException('UNBOUNDED_CONTENT');return$this;}
        public function exists(){return$this->table==='whatsapp_inbox_conversations'&&$this->conditions===['id'=>11,'waba_id'=>'468336579702269','phone_number_id'=>'515388018324075'];}
        public function count(){
            if($this->table!=='whatsapp_inbox_ingestion_failures as f'||($this->conditions['f.event_id']??null)!==['>',13203])throw new \RuntimeException('WRONG_FAILURE_RANGE');
            return getenv('WA_CAPTURE_CASE')==='limit'?11:3;
        }
        public function get(){
            $this->count();$GLOBALS['captureFailureReads']=($GLOBALS['captureFailureReads']??0)+1;$rows=[];
            foreach([14983,16000,17608]as$id){
                $plain=json_encode(capturePayload(),JSON_THROW_ON_ERROR);$hash=hash('sha256',$plain);
                if(getenv('WA_CAPTURE_CASE')==='bad-hash')$hash=str_repeat('0',64);
                $rows[]=(object)['event_id'=>$id,'reason'=>'CONFLICTING_MESSAGE','attempts'=>getenv('WA_CAPTURE_CASE')==='failure-drift'&&$GLOBALS['captureFailureReads']>1?2:1,
                    'last_attempted_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['captureNow']-200),'captured_event_id'=>$id,
                    'received_at'=>gmdate('Y-m-d H:i:s',$GLOBALS['captureNow']-250),'processed_at'=>null,'payload_hash'=>$hash,'payload'=>$plain];
            }
            return new CaptureRows($rows);
        }
        public function first($columns=null){
            if($this->table==='whatsapp_order_scans'){
                if(($this->conditions['conversation_id']??null)!==11)throw new \RuntimeException('UNSCOPED_SCAN');return(object)['attempts'=>3,'nonce'=>'PRIVATE_NONCE'];
            }
            if($this->table==='whatsapp_inbox_messages'){
                $expected=hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:wamid.PRIVATE_NATIVE');
                if(($this->conditions['message_key']??null)!==$expected&&getenv('WA_CAPTURE_CASE')!=='wrapped')throw new \RuntimeException('NON_NATIVE_LOOKUP');
                if(!in_array(getenv('WA_CAPTURE_CASE'),['dto-conflict','dashboard'],true))return null;
                $message=capturePayload()['entry'][0]['changes'][0]['value']['messages'][0];
                $saved=['message_id'=>$message['id'],'peer_identity'=>'user:PRIVATE_USER','direction'=>'inbound','type'=>'text',
                    'text'=>'PRIVATE_DIFFERENT_TEXT','sent_at'=>gmdate('Y-m-d H:i:s',(int)$message['timestamp']),'content'=>['message'=>['text'=>['body'=>'PRIVATE_DIFFERENT_TEXT']]]];
                return(object)['id'=>999,'conversation_id'=>22,'source'=>getenv('WA_CAPTURE_CASE')==='dashboard'?'dashboard_api':'messages',
                    'direction'=>'inbound','type'=>'text','content'=>json_encode($saved)];
            }
            if($this->table==='whatsapp_inbox_conversations'){
                if(isset($this->conditions['id']))return(object)['id'=>22,'waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
                    'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:468336579702269:515388018324075:user:PRIVATE_USER',str_repeat('k',32))];
                if(!isset($this->conditions['peer_hash'])||($this->conditions['waba_id']??null)!=='468336579702269'||($this->conditions['phone_number_id']??null)!=='515388018324075')
                    throw new \RuntimeException('UNSCOPED_IDENTITY_LOOKUP');return null;
            }
            throw new \RuntimeException('UNEXPECTED_LOOKUP');
        }
    }
    class CaptureKernel {public function bootstrap(){}}
    class CaptureApp {private array$objects=[];public function make($class){return$class==='Illuminate\\Contracts\\Console\\Kernel'?new CaptureKernel:($this->objects[$class]??=new$class);}}
    $GLOBALS['captureNow']=time();return new CaptureApp;
}
PHP);
function captureRun(string$case,array$args=[]):array{
    $fixture=$GLOBALS['fixture'];@unlink($fixture.'/readonly');$guard=$fixture.'/app/Services/Dashboard/WhatsAppInboxMessage.php';$saved=file_get_contents($guard);
    if($case==='source')file_put_contents($guard,$saved.' changed');
    if($args===[])$args=['--conversation','11','--expected-floor','6559','--expected-ceiling','10046','--expected-revision','13','--expected-event-floor','13203'];
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;
    $process=proc_open([$php,$fixture.'/capture.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_CAPTURE_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);file_put_contents($guard,$saved);
    captureExpect($errors===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201011111111')&&!str_contains($output,'wamid.'),'no raw payload identity body key or error prose '.$case);
    $value=json_decode($output,true,32,JSON_THROW_ON_ERROR);
    captureExpect($value['writes']===0&&$value['ai_calls']===0&&$value['message_sends']===0&&$value['erp_writes']===0&&$value['worker_actions']===0,'no actions '.$case);
    return[$exit,$value,is_file($fixture.'/readonly')];
}
[$exit,$value,$readonly]=captureRun('valid');
captureExpect($exit===0&&$readonly&&$value['status']==='READ_READY'&&$value['forward_failures_count']===3,'exact complete forward failure set read only');
captureExpect(array_column($value['events'],'event_id')===[14983,16000,17608],'only exact failure ids after pinned event floor');
captureExpect(count($value['observed_public_sources'])===7&&$value['observed_public_sources_unchanged_during_read'],'seven public compatibility fingerprints');
captureExpect($value['events'][0]['payload_hash_valid']&&$value['events'][0]['classification']['normalizer']['messages']===1,'stored raw hash and actual pinned pure protocol');
foreach(['contacts-null'=>'CONTACTS_LIST_INVALID','invalid-contact'=>'CONTACT_IDENTITY_INVALID','unknown-kind'=>'MESSAGE_CONTENT_INVALID',
    'invalid-status'=>'STATUS_RECORD_INVALID','played-mixed'=>'STATUS_RECORD_INVALID']as$case=>$category){
    [$exit,$value]=captureRun($case);captureExpect(isset($value['events'][0]['classification']['rejections'][$category]),'pinned parser shape observation '.$case);
}
[$exit,$value]=captureRun('played');captureExpect($value['events'][0]['classification']['normalizer']['status_count']===1
    &&$value['events'][0]['classification']['status_values']===['played'=>1]&&$value['events'][0]['classification']['status_value_types']===['string'=>1]
    &&$value['events'][0]['classification']['status_id_valid']===['VALID'=>1],'played receipt status-only shape and fixed-enum metadata');
[$exit,$value]=captureRun('invalid-status');captureExpect($value['events'][0]['classification']['status_values']===['OTHER'=>1]
    &&$value['events'][0]['classification']['status_value_types']===['array'=>1],'invalid status never echoes supplied value');
[$exit,$value]=captureRun('unsupported');captureExpect($value['events'][0]['classification']['message_kinds']===['unsupported'=>1]
    &&$value['events'][0]['classification']['error_codes']===[131060=>1],'native unsupported kind and numeric error code only');
[$exit,$value]=captureRun('wrapped');captureExpect($exit===0&&$value['events'][0]['classification']['normalizer']['messages']===1,'documented pure standby wrapper retained');
[$exit,$value]=captureRun('phone-scope');captureExpect($value['events'][0]['classification']['normalizer']['ignored_count']===1,'different phone scope is observed without identities');
[$exit,$value]=captureRun('dto-conflict');$comparison=$value['events'][0]['native_comparisons']['messages'][0];
captureExpect($comparison['existing_message']&&$comparison['peer_hash_matches']&&$comparison['scope_matches']
    &&$comparison['dto_mismatch_fields']===['TEXT']&&$comparison['content_mismatch_fields']===['TEXT'],'native-id-only duplicate content equality flags');
[$exit,$value]=captureRun('dashboard');captureExpect($value['events'][0]['native_comparisons']['messages'][0]['dashboard_receipt_proof_required']
    &&!isset($value['events'][0]['native_comparisons']['messages'][0]['stored_readable']),'dashboard receipt requires separate proof without projector');
foreach(['bad-hash','decrypt']as$case){[$exit,$value]=captureRun($case);captureExpect($exit===1&&$value['status']==='PARTIAL'&&$value['events'][0]['status']==='UNREADABLE','integrity or decode failure cannot be normalized '.$case);}
foreach(['snapshot-drift','failure-drift']as$case){[$exit,$value]=captureRun($case);captureExpect($exit===1&&$value['status']==='OBSERVATION_CHANGED','unstable observation detected '.$case);}
foreach(['owner'=>'OWNER_INVALID','source'=>'SOURCE_CHANGED','driver'=>'READ_ONLY_SESSION_REQUIRED','transaction'=>'READ_ONLY_SESSION_REQUIRED',
    'event-floor'=>'CONTROL_EXPECTATION_CHANGED','limit'=>'FORWARD_FAILURE_LIMIT']as$case=>$reason){
    [$exit,$value]=captureRun($case);captureExpect($exit===1&&$value['status']==='READ_BLOCKED'&&$value['reason']===$reason,'guarded precondition '.$case);
}
[$exit,$value]=captureRun('arguments',['--conversation','11']);captureExpect($exit===1&&$value['reason']==='ARGUMENTS_INVALID','all five expectations required');
echo 'whatsapp_customer_confirmation_capture: '.$checks." checks passed\n";
