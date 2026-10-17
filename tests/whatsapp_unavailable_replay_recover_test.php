<?php
// The actual CLI runs in subprocesses with explicit host/DB seams. Core native
// replay semantics have their own real-parser/SQLite integration tests.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_unavailable_replay_recover.php');
$fixture=sys_get_temp_dir().'/wa-replay-cli-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
function replayExpect($ok,string$label):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException('UNAVAILABLE_REPLAY_CLI:'.$label);}
function replayRemove(string$path):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path))return;
    foreach(new DirectoryIterator($path)as$file)if(!$file->isDot())replayRemove($file->getPathname());rmdir($path);
}
register_shutdown_function(static fn()=>replayRemove($fixture));
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
replayExpect(count($pins)===12,'reviewed runtime roster including exact replay proof and consumer');
foreach($pins as[$all,$path,$pin]){
    $bytes=file_get_contents($root.'/'.$path);$actual=sha1('blob '.strlen($bytes)."\0".$bytes);
    replayExpect($actual===$pin,'runtime source exact '.$path);
    $dest=$fixture.'/app-root/'.$path;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);file_put_contents($dest,$bytes);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_REPLAY_CASE')==='owner'?1:0)]",$source,$count);replayExpect($count===1,'explicit owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture.'/app-root',true).')',$source,$count);replayExpect($count===1,'explicit root seam');
$source=str_replace("'/home/fasakha/whatsapp-release-backups'",var_export($fixture.'/backups',true),$source,$count);replayExpect($count===1,'private audit parent seam');
$source=str_replace('fsync(','waFixtureFsync(',$source,$count);replayExpect($count===2,'explicit file and directory durability fault seams');
foreach(['vendor','bootstrap']as$dir)mkdir($fixture.'/app-root/'.$dir,0700,true);
file_put_contents($fixture.'/app-root/recover.php',$source);file_put_contents($fixture.'/app-root/vendor/autoload.php','<?php ');
file_put_contents($fixture.'/app-root/bootstrap/app.php',<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB {
        public static function connection(){return new \ReplayConnection;}
        public static function table($name){return new \ReplayQuery($name);}
        public static function transaction($call,$attempts){$GLOBALS['replayTransaction']=true;try{return$call();}finally{$GLOBALS['replayTransaction']=false;}}
    }
    class Crypt {public static function decryptString($value){if(getenv('WA_REPLAY_CASE')==='decrypt')throw new \RuntimeException('PRIVATE_DECRYPT');return$value;}}
}
namespace App\Support {
    class WhatsAppCustomerOrderConfirmation {public static function inspect($rows){return['ok'=>getenv('WA_REPLAY_CASE')!=='recap-invalid'];}}
    class WhatsAppInboxProtocol {public static function report($payload,$waba,$phone){
        if($waba!=='TEST_WABA'||$phone!=='TEST_PHONE')throw new \RuntimeException('PRIVATE_SCOPE');
        return['messages'=>[['message_id'=>$payload['native']]],'quarantined_count'=>0,'ignored_count'=>0,'status_count'=>0];
    }}
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess {const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class MetaAiControl {
        private int$reads=0;
        public function state(){
            $this->reads++;$case=getenv('WA_REPLAY_CASE');$revision=$case==='revision'||$case==='control-drift'&&$this->reads>=2
                ||$case==='boundary-control-drift'&&($GLOBALS['replayTransaction']??false)?14:13;
            return['available'=>true,'policy'=>['enabled'=>$case!=='disabled','mode'=>$case==='reply-mode'?'REPLY_AND_ANALYZE':'ANALYZE_ONLY'],
                'revision'=>$revision,'updated_at_utc'=>'2026-10-17 16:00:00'];
        }
    }
    class WhatsAppOrderWorkflow {
        private function controlToken($conversation){return['revision'=>13,'event_floor'=>getenv('WA_REPLAY_CASE')==='event-floor'?13204:13203,'PRIVATE_ACTOR'=>1];}
        private function activationBoundary($key,$conversation){return 6559;}
        private function ceiling($conversation,$lock=false){return getenv('WA_REPLAY_CASE')==='ceiling'?10047:10046;}
        private function dispatchedCeiling($conversation,$lock=false){return 0;}
        private function customerReceiptFloor($conversation,$floor,$ceiling){return getenv('WA_REPLAY_CASE')==='floor'?6560:$floor;}
        private function snapshot($conversation,$floor,$ceiling,$lock=false){return['complete'=>getenv('WA_REPLAY_CASE')!=='incomplete','hash'=>str_repeat('a',64),'transcript'=>[['text'=>'PRIVATE_CUSTOMER_RECAP']]];}
    }
    class WhatsAppNativeUnavailableReplay {
        public static function inspect($thread,$existing,$raw){
            if(getenv('WA_REPLAY_CASE')==='proof'||getenv('WA_REPLAY_CASE')==='new-difference')return null;
            if($thread->id!==$existing->conversation_id||$raw->id!==$existing->event_id)throw new \RuntimeException('PRIVATE_WRONG_BINDING');
            return['proof_version'=>'whatsapp-native-unavailable-replay-v1','event_id'=>$raw->id,'message_id'=>$existing->id,
                'conversation_id'=>$thread->id,'payload_hash'=>$raw->payload_hash,'ciphertext_sha256'=>hash('sha256',$existing->content),
                'original_sent_at'=>'2026-10-17 17:00:00','incoming_sent_at'=>'2026-10-17 17:01:00'];
        }
    }
    class WhatsAppInboxConsumer {
        public function recoverUnavailableReplays($ids,$proofs){
            if(!($GLOBALS['replayTransaction']??false)||$ids!==[14983,16811,17608]||array_keys($proofs)!==$ids)throw new \RuntimeException('PRIVATE_UNBOUND_CORE');
            $parent=dirname(getcwd()).'/backups';$dirs=glob($parent.'/whatsapp-unavailable-replay-*');
            $allResolved=count(array_filter(\replayState()))===3;
            if(count($dirs)!==1||!is_file($dirs[0].'/PREPARED.json')||(!$allResolved&&($GLOBALS['replayFileSyncs']??0)<1)||($GLOBALS['replayDirectorySyncs']??0)<2)
                throw new \RuntimeException('PRIVATE_AUDIT_NOT_DURABLE');
            $audit=json_decode(file_get_contents($dirs[0].'/PREPARED.json'),true);
            if($audit['proofs']!==$proofs)throw new \RuntimeException('PRIVATE_PROOF_MISMATCH');
            foreach($proofs as$id=>$proof)if(array_keys($proof)!==['proof_version','event_id','message_id','conversation_id','payload_hash','ciphertext_sha256','original_sent_at','incoming_sent_at','failure']
                ||$proof['event_id']!==$id||$proof['failure']!==['reason'=>'CONFLICTING_MESSAGE','attempts'=>1,'last_attempted_at'=>'2026-10-17 17:02:00'])throw new \RuntimeException('PRIVATE_EXPECTATION_SHAPE');
            file_put_contents(getcwd().'/called','1');if(getenv('WA_REPLAY_CASE')==='core-throws')throw new \RuntimeException('PRIVATE_UNCERTAIN_COMMIT');
            $state=\replayState();$count=0;$receipts=[];
            foreach($ids as$id){$already=$state[$id]??false;if(!$already)$count++;$state[$id]=true;
                $receipts[]=array_merge($proofs[$id],['state'=>$already?'ALREADY_PROCESSED':'PROCESSED','processed_at'=>'2026-10-17 18:00:00']);
            }
            file_put_contents(getcwd().'/state.json',json_encode($state));
            return['success'=>true,'writes'=>$count*2,'events_processed'=>$count,'events_skipped'=>3-$count,'messages_replayed'=>3,
                'receipts'=>$receipts,'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,'PRIVATE_EXTRA'=>'PRIVATE_RAW_BODY'];
        }
    }
}
namespace {
    function replayState(){return is_file(getcwd().'/state.json')?json_decode(file_get_contents(getcwd().'/state.json'),true):[];}
    function waFixtureFsync($fd){
        $s=fstat($fd);$directory=($s['mode']&0170000)===0040000;$case=getenv('WA_REPLAY_CASE');
        if($directory)$GLOBALS['replayDirectorySyncs']=($GLOBALS['replayDirectorySyncs']??0)+1;else$GLOBALS['replayFileSyncs']=($GLOBALS['replayFileSyncs']??0)+1;
        if($case==='file-sync'&&!$directory||$case==='directory-sync'&&$directory
            ||$case==='committed-sync'&&!$directory&&($GLOBALS['replayFileSyncs']??0)>1)return false;
        return fsync($fd);
    }
    class ReplayConnection {
        public function getDriverName(){return getenv('WA_REPLAY_CASE')==='driver'?'sqlite':'mysql';}
        public function transactionLevel(){return getenv('WA_REPLAY_CASE')==='active-transaction'?1:(($GLOBALS['replayTransaction']??false)?1:0);}
        public function getPdo(){return new ReplayPdo;}
    }
    class ReplayPdo {public function exec($sql){
        if(!in_array($sql,['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE'],true))throw new \RuntimeException('PRIVATE_SQL');
        file_put_contents(getcwd().'/sql',$sql."\n",FILE_APPEND);
        if(getenv('WA_REPLAY_CASE')==='read-only'&&str_ends_with($sql,'READ ONLY')||getenv('WA_REPLAY_CASE')==='read-write'&&str_ends_with($sql,'READ WRITE'))throw new \RuntimeException('PRIVATE_SQL_FAILED');return 0;
    }}
    class ReplayQuery {
        private array$conditions=[];
        public function __construct(private string$table){}
        public function where($key,$operator,$value=null){$this->conditions[$key]=func_num_args()===2?$operator:[$operator,$value];return$this;}
        public function select(...$columns){return$this;}
        public function selectRaw($sql){if(!str_contains($sql,'CASE WHEN LENGTH('))throw new \RuntimeException('PRIVATE_UNBOUNDED_CONTENT');return$this;}
        public function lockForUpdate(){if(!($GLOBALS['replayTransaction']??false))throw new \RuntimeException('PRIVATE_LOCK_OUTSIDE_TRANSACTION');return$this;}
        public function first($columns=null){
            $ids=[14983,16811,17608];
            if($this->table==='whatsapp_webhook_events'){
                $id=$this->conditions['id']??null;if(!in_array($id,$ids,true))throw new \RuntimeException('PRIVATE_EVENT_SCOPE');
                $plain=json_encode(['native'=>'wamid.PRIVATE_'.$id,'text'=>'PRIVATE_RAW_BODY']);
                return(object)['id'=>$id,'payload_hash'=>getenv('WA_REPLAY_CASE')==='hash'?str_repeat('0',64):hash('sha256',$plain),
                    'payload'=>$plain,'received_at'=>'2026-10-17 17:02:00','processed_at'=>(replayState()[$id]??false)?'2026-10-17 18:00:00':null];
            }
            if($this->table==='whatsapp_inbox_messages'){
                foreach($ids as$id)if(($this->conditions['message_key']??null)===hash('sha256','whatsapp-message-v1:TEST_WABA:TEST_PHONE:wamid.PRIVATE_'.$id))
                    return(object)['id'=>$id+100,'event_id'=>$id,'conversation_id'=>$id+200,'content'=>'PRIVATE_CIPHERTEXT','source'=>'messages','type'=>'unsupported','direction'=>'inbound'];
                throw new \RuntimeException('PRIVATE_NATIVE_KEY_SCOPE');
            }
            if($this->table==='whatsapp_inbox_conversations'){
                $id=$this->conditions['id']??null;if($id===11){if($this->conditions!==['id'=>11,'waba_id'=>'TEST_WABA','phone_number_id'=>'TEST_PHONE'])throw new \RuntimeException('PRIVATE_TARGET_SCOPE');return(object)['id'=>11];}
                if(!in_array($id,array_map(static fn($id)=>$id+200,$ids),true))throw new \RuntimeException('PRIVATE_THREAD_SCOPE');return(object)['id'=>$id];
            }
            if($this->table==='whatsapp_inbox_ingestion_failures'){
                $id=$this->conditions['event_id']??null;if(!in_array($id,$ids,true))throw new \RuntimeException('PRIVATE_FAILURE_SCOPE');
                if(replayState()[$id]??false)return null;
                return(object)['reason'=>getenv('WA_REPLAY_CASE')==='failure'?'INVALID_EVENT':'CONFLICTING_MESSAGE','attempts'=>1,'last_attempted_at'=>'2026-10-17 17:02:00'];
            }
            throw new \RuntimeException('PRIVATE_UNEXPECTED_QUERY');
        }
    }
    class ReplayKernel {public function bootstrap(){}}
    class ReplayApp {private array$objects=[];public function make($name){return$name==='Illuminate\\Contracts\\Console\\Kernel'?new ReplayKernel:($this->objects[$name]??=new$name);}}
    file_put_contents(getcwd().'/bootstrapped','1');return new ReplayApp;
}
PHP);
function replayRun(string$case,bool$reset=true,?array$args=null):array{
    $f=$GLOBALS['fixture'];if($reset){replayRemove($f.'/backups');mkdir($f.'/backups',0700);@unlink($f.'/app-root/state.json');}
    foreach(['called','sql','bootstrapped']as$name)@unlink($f.'/app-root/'.$name);
    if($case==='permissions')chmod($f.'/backups',0755);
    if($case==='prior-resolved')file_put_contents($f.'/app-root/state.json',json_encode([14983=>true,16811=>true,17608=>true]));
    $guard=$f.'/app-root/app/Services/Dashboard/WhatsAppInboxMessage.php';$bytes=file_get_contents($guard);
    if($case==='source')file_put_contents($guard,$bytes.' changed');
    $args=$args??['--conversation','11','--expected-floor','6559','--expected-ceiling','10046','--expected-revision','13','--expected-event-floor','13203','--events','14983,16811,17608'];
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;$process=proc_open([$php,$f.'/app-root/recover.php',...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_REPLAY_CASE'=>$case]));
    fclose($pipes[0]);$text=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);file_put_contents($guard,$bytes);
    replayExpect($stderr===''&&!str_contains($text,'PRIVATE_')&&!str_contains($text,'wamid.'),'safe output '.$case);
    $result=json_decode($text,true,64,JSON_THROW_ON_ERROR);
    foreach(['ai_calls','message_sends','erp_writes','graph_gets','worker_actions']as$key)replayExpect(($result[$key]??null)===0,'no external action '.$case.' '.$key);
    return['exit'=>$exit,'result'=>$result,'called'=>is_file($f.'/app-root/called'),
        'sql'=>is_file($f.'/app-root/sql')?file($f.'/app-root/sql',FILE_IGNORE_NEW_LINES):[],
        'bootstrapped'=>is_file($f.'/app-root/bootstrapped')];
}
foreach([[],['--events','14983,16811'],['--force'],['--conversation','31','--expected-floor','6559','--expected-ceiling','10046','--expected-revision','13','--expected-event-floor','13203','--events','14983,16811,17608']]as$i=>$args){
    $r=replayRun('arguments',true,$args);replayExpect($r['exit']===1&&$r['result']['stage']==='ARGUMENTS'&&!$r['bootstrapped']&&!$r['called']&&$r['sql']===[],'exact fixed argument scope '.$i);
}
foreach(['owner','source','driver','active-transaction','read-only','disabled','revision','reply-mode','event-floor','ceiling','floor','incomplete',
    'recap-invalid','hash','decrypt','proof','new-difference','failure','permissions','file-sync','directory-sync','control-drift','prior-resolved','read-write']as$case){
    $r=replayRun($case);replayExpect($r['exit']===1&&$r['result']['status']==='RECOVERY_BLOCKED'&&$r['result']['writes']===0&&!$r['called'],'blocked before mutation '.$case);
    if(!in_array($case,['owner','source','driver','active-transaction','read-only','read-write'],true))replayExpect($r['sql']===['SET SESSION TRANSACTION READ ONLY'],'readonly until all proofs and durable audit '.$case);
}
$r=replayRun('boundary-control-drift');replayExpect($r['exit']===1&&$r['result']['status']==='RECOVERY_UNVERIFIED'&&$r['result']['writes']===null&&!$r['called'],'locked control drift prevents core call');
$r=replayRun('core-throws');replayExpect($r['exit']===1&&$r['result']['writes']===null&&$r['called'],'uncertain core result never claims zero writes');
$r=replayRun('valid');replayExpect($r['exit']===0&&$r['result']['status']==='RESOLVED'&&$r['result']['writes']===6&&$r['called'],'three exact events resolve with durable audit');
$directory=$r['result']['audit_directory'];replayExpect(is_file($directory.'/PREPARED.json')&&is_file($directory.'/COMMITTED.json'),'separate immutable prepared and committed receipts');
$prepared=file_get_contents($directory.'/PREPARED.json');$committed=file_get_contents($directory.'/COMMITTED.json');
replayExpect(!str_contains($prepared,'PRIVATE_')&&!str_contains($committed,'PRIVATE_'),'audit contains hashes and metadata only');
$again=replayRun('valid',false);replayExpect($again['exit']===0&&$again['result']['status']==='ALREADY_RESOLVED'&&$again['result']['writes']===0
    &&$again['result']['messages_replayed']===3&&$again['called'],'idempotent rerun re-proves all events through core');
replayExpect(file_get_contents($directory.'/PREPARED.json')===$prepared&&file_get_contents($directory.'/COMMITTED.json')===$committed,'rerun rewrites no audit history');
chmod($directory.'/PREPARED.json',0644);$bad=replayRun('valid',false);replayExpect($bad['exit']===1&&$bad['result']['reason']==='AUDIT_FILE_UNSAFE'&&!$bad['called'],'unsafe existing audit permissions cannot authorize rerun');
$r=replayRun('valid');$directory=$r['result']['audit_directory'];$path=$directory.'/PREPARED.json';$outside=$fixture.'/audit-link-target';copy($path,$outside);unlink($path);symlink($outside,$path);
$bad=replayRun('valid',false);replayExpect($bad['exit']===1&&$bad['result']['reason']==='AUDIT_FILE_UNSAFE'&&!$bad['called'],'audit symlink is rejected');unlink($outside);
$r=replayRun('valid');$directory=$r['result']['audit_directory'];$path=$directory.'/PREPARED.json';$outside=$fixture.'/audit-hardlink';link($path,$outside);
$bad=replayRun('valid',false);replayExpect($bad['exit']===1&&$bad['result']['reason']==='AUDIT_FILE_UNSAFE'&&!$bad['called'],'audit hardlink is rejected');unlink($outside);
$r=replayRun('committed-sync');replayExpect($r['exit']===1&&$r['result']['status']==='RECOVERY_UNVERIFIED'&&$r['result']['writes']===null&&$r['called'],'postcommit audit sync failure is reported uncertain');
echo 'whatsapp-unavailable-replay-recover: '.$checks." checks passed\n";
