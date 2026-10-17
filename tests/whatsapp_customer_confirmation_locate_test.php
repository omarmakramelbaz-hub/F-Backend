<?php
// Real locator CLI and pinned native projector, with explicit host/DB seams only.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_locate.php');
$fixture=sys_get_temp_dir().'/wa-locate-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){
    if(!is_dir($fixture))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);
});
function locateExpect($value,string$label):void{$GLOBALS['checks']++;if(!$value)throw new RuntimeException('LOCATOR_TEST_FAILED:'.$label);}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
locateExpect(count($pins)===2,'only native access and projector source guards');
foreach($pins as[$all,$path,$pin]){
    $bytes=file_get_contents($root.'/'.$path);locateExpect(sha1('blob '.strlen($bytes)."\0".$bytes)===$pin,'actual pinned source '.$path);
    if(!is_dir(dirname($fixture.'/'.$path)))mkdir(dirname($fixture.'/'.$path),0700,true);file_put_contents($fixture.'/'.$path,$bytes);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_LOCATE_CASE')==='bad-owner'?1:0)]",$source,$count);locateExpect($count===1,'explicit owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);locateExpect($count===1,'explicit root seam');
locateExpect(!preg_match('/(?:->|::)(?:resolve|indexReply|backfillProjectionIndex|consume|analyze|process|quote|dispatch|send|insert|update|delete|save|lockForUpdate)\s*\(/',$source),'no domain actions or writes');
mkdir($fixture.'/vendor',0700);mkdir($fixture.'/bootstrap',0700);file_put_contents($fixture.'/locate.php',$source);
file_put_contents($fixture.'/vendor/autoload.php',<<<'PHP'
<?php
require getcwd().'/app/Services/Dashboard/WhatsAppInboxAccess.php';
require getcwd().'/app/Services/Dashboard/WhatsAppInboxMessage.php';
PHP);
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace Symfony\Component\HttpKernel\Exception { class HttpException extends \RuntimeException {} }
namespace Illuminate\Support\Facades {
    class DB { public static function connection(){return new \LocateConnection;}public static function table($table){if(empty($GLOBALS['readonly']))throw new \RuntimeException('PRIVATE_SQL_BEFORE_READONLY');return new \LocateQuery($table);} }
    class Crypt {public static function decryptString($value){
        if($value==='PRIVATE_BAD_CIPHERTEXT')throw new \RuntimeException('PRIVATE_DECRYPT_ERROR');
        if(getenv('WA_LOCATE_CASE')==='source-drift'&&str_starts_with($value,'{"message_id"'))file_put_contents(getcwd().'/app/Services/Dashboard/WhatsAppInboxMessage.php',"\n// drift",FILE_APPEND);
        return$value;
    }}
}
namespace {
    function config($key,$default=null){if($key!=='app.key')throw new \RuntimeException('PRIVATE_CONFIG');return getenv('WA_LOCATE_CASE')==='bad-key'?'base64:bad!':'base64:'.base64_encode('PRIVATE_LOCATOR_KEY_0123456789abcdef');}
    function locatePhone(){return'201011122233';}
    function locateThread($id,$phone=null,$user=null){
        $phone=$phone??locatePhone();$scope=\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID.':'.\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID.':';
        $customer=json_encode(['name'=>'PRIVATE_NAME','phone'=>$phone,'user_id'=>$user,'address'=>'PRIVATE_ADDRESS']);
        return(object)['id'=>$id,'waba_id'=>\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID,'phone_number_id'=>\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID,
            'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:'.$scope.($user!==null?'user:'.$user:'phone:'.$phone),'PRIVATE_LOCATOR_KEY_0123456789abcdef'),
            'updated_at'=>'2026-10-17 20:00:00','customer'=>$customer,'customer_digest'=>hash('sha256',$customer)];
    }
    function locateNative($cid){
        $case=getenv('WA_LOCATE_CASE');$user=in_array($case,['user','native-no-phone','native-other-phone','two-identities'],true)&&$cid===2?'Opaque_USER_A':null;
        $phone=$case==='native-no-phone'?null:($case==='native-other-phone'?'201099988877':locatePhone());
        $native='wamid.PRIVATE_NATIVE_'.$cid;$scope=\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID.':'.\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID.':';
        $raw=['id'=>$native,'timestamp'=>'1792267200','type'=>'text','text'=>['body'=>'PRIVATE_MESSAGE_BODY']];
        if($phone!==null)$raw['from']=$phone;if($user!==null)$raw['from_user_id']=$user;
        $dto=['message_id'=>$native,'peer_identity'=>$user!==null?'user:'.$user:'phone:'.$phone,'peer_phone'=>$phone,'peer_user_id'=>$user,
            'direction'=>'inbound','source'=>'messages','type'=>'text','sent_at'=>'2026-10-17 20:00:00','content'=>['message'=>$raw]];
        if($case==='invalid-projection')$dto['content']['message']['text']['body']=['bad'=>'PRIVATE_INVALID_TEXT'];
        $content=json_encode($dto);return(object)['id'=>100+$cid,'conversation_id'=>$cid,'message_key'=>hash('sha256','whatsapp-message-v1:'.$scope.$native),
            'direction'=>'inbound','source'=>'messages','type'=>'text','sent_at'=>'2026-10-17 20:00:00','created_at'=>'2026-10-17 20:00:01','updated_at'=>'2026-10-17 20:00:01',
            'content_digest'=>hash('sha256',$content),'content'=>$content];
    }
    class LocateConnection {
        public function getDriverName(){return getenv('WA_LOCATE_CASE')==='bad-driver'?'pgsql':'mysql';}
        public function transactionLevel(){return getenv('WA_LOCATE_CASE')==='transaction'?1:0;}
        public function getPdo(){return new LocatePdo;}
    }
    class LocatePdo {public function exec($sql){if($sql!=='SET SESSION TRANSACTION READ ONLY'||getenv('WA_LOCATE_CASE')==='readonly-fail')throw new \RuntimeException('PRIVATE_SQL');$GLOBALS['readonly']=true;return 0;}}
    class LocateRows {public function __construct(private array$rows){}public function all(){return$this->rows;}}
    class LocateQuery {
        private array$conditions=[],$orders=[],$raw=[],$columns=[];private int$limit=0;
        public function __construct(private string$table){}
        public function where($key,$operator,$value=null){$this->conditions[$key]=func_num_args()===2?$operator:[$operator,$value];return$this;}
        public function whereIn($key,$value){$this->conditions[$key]=$value;return$this;}
        public function join(...$args){if($args!==['whatsapp_inbox_conversations as c','c.id','=','m.conversation_id'])throw new \RuntimeException('PRIVATE_JOIN');return$this;}
        public function orderByDesc($key){$this->orders[]=$key;return$this;}
        public function limit($limit){if($limit<1||$limit>50)throw new \RuntimeException('PRIVATE_LIMIT');$this->limit=$limit;return$this;}
        public function select(...$columns){$this->columns=$columns;return$this;}
        public function selectRaw($sql){$allowed=['SHA2(customer,256) AS customer_digest','CASE WHEN LENGTH(customer)<=65536 THEN customer ELSE NULL END AS customer','SHA2(content,256) AS content_digest','CASE WHEN LENGTH(content)<=1048576 THEN content ELSE NULL END AS content'];if(!in_array($sql,$allowed,true))throw new \RuntimeException('PRIVATE_SQL_RAW');$this->raw[]=$sql;return$this;}
        private function scoped($prefix=''){return($this->conditions[$prefix.'waba_id']??null)===\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID&&($this->conditions[$prefix.'phone_number_id']??null)===\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID;}
        public function get(){
            if($this->table!=='whatsapp_inbox_conversations'||!$this->scoped()||$this->orders!==['id']||!$this->limit)throw new \RuntimeException('PRIVATE_THREAD_QUERY');
            $hasCustomer=in_array('CASE WHEN LENGTH(customer)<=65536 THEN customer ELSE NULL END AS customer',$this->raw,true);$case=getenv('WA_LOCATE_CASE');
            $count=$case==='truncated'?2001:($case==='many-candidates'?11:($case==='two-identities'?2:1));$rows=[];
            for($i=$count;$i>=1;$i--){$id=$case==='two-identities'?$i:($count===1?2:$i);if(isset($this->conditions['id'])&&$id>=$this->conditions['id'][1])continue;
                $user=in_array($case,['user','native-no-phone','native-other-phone','two-identities'],true)&&$id===2?'Opaque_USER_A':null;
                $phone=in_array($case,['no-match','truncated'],true)?'201077766655':locatePhone();$row=locateThread($id,$phone,$user);
                if($case==='wrong-hash')$row->peer_hash=str_repeat('f',64);
                if($case==='oversize-cipher')$row->customer=null;
                if($case==='decrypt-fail')$row->customer='PRIVATE_BAD_CIPHERTEXT';
                if($case==='bad-json')$row->customer='PRIVATE_BAD_JSON';
                if($case==='oversize-plain')$row->customer=str_repeat('X',32769);
                if(!$hasCustomer){unset($row->customer);if($case==='thread-drift')$row->customer_digest=str_repeat('a',64);}
                $rows[]=$row;if(count($rows)===$this->limit)break;
            }return new LocateRows($rows);
        }
        public function first($columns=null){
            $case=getenv('WA_LOCATE_CASE');
            if($this->table==='whatsapp_inbox_messages'){
                $cid=$this->conditions['conversation_id']??null;
                if(!is_int($cid)||($this->conditions['direction']??null)!=='inbound'||($this->conditions['source']??null)!==['messages','standby']||$this->orders!==['sent_at','id'])throw new \RuntimeException('PRIVATE_NATIVE_QUERY');
                if($case==='no-native')return null;$row=locateNative($cid);$content=in_array('CASE WHEN LENGTH(content)<=1048576 THEN content ELSE NULL END AS content',$this->raw,true);
                if(!$content){unset($row->content);if($case==='native-drift')$row->content_digest=str_repeat('b',64);}return$row;
            }
            if($this->table==='whatsapp_order_drafts'){
                if(!is_int($this->conditions['conversation_id']??null)||$this->orders!==['id']||$columns!==['id','status','reason','evidence_ceiling','ticket_id','assigned_branch','updated_at'])throw new \RuntimeException('PRIVATE_DRAFT_QUERY');
                if($case==='no-draft')return null;$GLOBALS['draftReads']=($GLOBALS['draftReads']??0)+1;
                return(object)['id'=>2171,'status'=>'REVIEW','reason'=>'CATALOG_UNRESOLVED','evidence_ceiling'=>10046,'ticket_id'=>null,'assigned_branch'=>'f:363','updated_at'=>$case==='draft-drift'&&$GLOBALS['draftReads']>1?'2026-10-17 20:00:02':'2026-10-17 20:00:01'];
            }
            if($this->table==='whatsapp_inbox_messages as m'){
                if(!$this->scoped('c.')||$this->orders!==['m.sent_at','m.id']||$columns!==['m.id','m.sent_at','m.created_at'])throw new \RuntimeException('PRIVATE_LATEST_QUERY');
                $GLOBALS['latestReads']=($GLOBALS['latestReads']??0)+1;return(object)['id'=>$case==='capture-drift'&&$GLOBALS['latestReads']>1?999:998,'sent_at'=>'2026-10-17 20:05:00','created_at'=>'2026-10-17 20:05:01'];
            }throw new \RuntimeException('PRIVATE_UNKNOWN_QUERY');
        }
    }
    return new class{public function make($name){return new class{public function bootstrap(){}};}};
}
PHP);
function locateRun(string$case,array$args=['--peer-phone','201011122233']):array{
    $fixture=$GLOBALS['fixture'];$path='app/Services/Dashboard/WhatsAppInboxMessage.php';file_put_contents($fixture.'/'.$path,file_get_contents($GLOBALS['root'].'/'.$path));
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;$spec=[1=>['pipe','w'],2=>['pipe','w']];$env=getenv();$env['WA_LOCATE_CASE']=$case;
    $process=proc_open(array_merge([$php,$fixture.'/locate.php'],$args),$spec,$pipes,$fixture,$env);$raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    locateExpect($error==='','no diagnostic stderr '.$case);$result=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    foreach(['201011122233','201099988877','201077766655','PRIVATE_','Opaque_USER_A','wamid.','peer_hash','customer_digest','content_digest','message_key','ciphertext']as$secret)locateExpect(!str_contains($raw,$secret),'no identity/content leak '.$case.' '.$secret);
    locateExpect(strlen($raw)<20000,'bounded JSON '.$case);return[$result,$exit];
}
[$base,$exit]=locateRun('phone');locateExpect($exit===0&&$base['status']==='READ_READY'&&$base['complete'],'complete stable exact PHONE observation');
locateExpect($base['read_only_session']&&$base['conversation_metadata_unchanged_during_read']&&$base['candidate_metadata_unchanged_during_read']&&$base['capture_metadata_unchanged_during_read']&&$base['source_unchanged_during_read'],'all stability guards');
$candidate=$base['candidates'][0];locateExpect($candidate['conversation_id']===2&&$candidate['peer_identity_namespace']==='PHONE'&&$candidate['native_projection_valid']&&$candidate['native_phone_match'],'actual native projector authenticates exact peer');
locateExpect($candidate['latest_draft']['branch']==='f:363'&&$candidate['latest_draft']['ceiling']===10046,'actual assigned_branch schema');
foreach(['user','native-no-phone','native-other-phone']as$case){[$row]=locateRun($case);$c=$row['candidates'][0];locateExpect($row['status']==='READ_READY'&&$c['peer_identity_namespace']==='USER'&&$c['native_projection_valid'],'opaque USER binds through actual projector '.$case);locateExpect($c['native_phone_match']===($case==='native-no-phone'?null:$case!=='native-other-phone'),'native phone proof separate '.$case);}
[$row]=locateRun('two-identities');locateExpect(count($row['candidates'])===2&&array_column($row['candidates'],'conversation_id')===[2,1]&&array_column($row['candidates'],'peer_identity_namespace')===['USER','PHONE'],'distinct exact PHONE/USER peers never merged');
[$row]=locateRun('wrong-hash');locateExpect($row['status']==='PARTIAL'&&$row['invalid_customer_identities']===1&&$row['exact_phone_matches']===0&&!$row['complete'],'unbound stored phone is not an identity match');
[$row]=locateRun('no-match');locateExpect($row['status']==='READ_READY'&&$row['complete']&&$row['candidates']===[],'bounded complete negative uses exact phone');
foreach(['oversize-cipher','decrypt-fail','bad-json','oversize-plain']as$case){[$row]=locateRun($case);locateExpect($row['status']==='PARTIAL'&&!$row['complete']&&$row['unreadable_customers']===1,'unreadable cannot produce exhaustive negative '.$case);}
[$row]=locateRun('invalid-projection');locateExpect($row['status']==='PARTIAL'&&!$row['complete']&&$row['invalid_native_projections']===1&&$row['candidates'][0]['native_projection_valid']===false,'actual projector rejects malformed native content');
[$row]=locateRun('truncated');locateExpect($row['status']==='PARTIAL'&&!$row['complete']&&$row['conversation_scan_truncated']&&$row['conversations_examined']===2000,'2001 sentinel bounds scan and blocks negative completeness');
[$row]=locateRun('many-candidates');locateExpect($row['status']==='PARTIAL'&&$row['exact_phone_matches']===11&&count($row['candidates'])===10&&$row['candidate_output_truncated'],'candidate cap retains exact total and truncation');
foreach(['no-native','no-draft']as$case){[$row]=locateRun($case);locateExpect($row['status']==='READ_READY'&&($case==='no-native'?$row['candidates'][0]['native_projection_valid']===null:$row['candidates'][0]['latest_draft']===null),'absent metadata explicit '.$case);}
foreach(['thread-drift','native-drift','draft-drift','capture-drift']as$case){[$row]=locateRun($case);locateExpect($row['status']==='OBSERVATION_CHANGED','repeat metadata rejects drift '.$case);}
foreach(['bad-owner','bad-driver','transaction','readonly-fail','bad-key','source-drift']as$case){[$row,$exit]=locateRun($case);locateExpect($exit===1&&$row['status']==='READ_BLOCKED','fail closed before authority or on drift '.$case);}
foreach([[],['--peer-phone','+201011122233'],['--peer-phone','20101112223x'],['--peer-phone','201011122233','extra']]as$args){[$row,$exit]=locateRun('arguments',$args);locateExpect($exit===1&&$row['stage']==='ARGUMENTS'&&$row['reason']==='ARGUMENTS_INVALID','strict generic phone argument');}
echo json_encode(['status'=>'WHATSAPP_CUSTOMER_CONFIRMATION_LOCATE_TEST_OK','checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
