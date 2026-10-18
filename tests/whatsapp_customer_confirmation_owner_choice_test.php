<?php
// Real one-order CLI with isolated host, session and workflow seams only.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_owner_choice.php');
$fixture=sys_get_temp_dir().'/wa-owner-choice-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){if(!is_dir($fixture))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);
});
function choiceExpect($ok,string$label):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException('OWNER_CHOICE_CLI_FAILED:'.$label);}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);
choiceExpect(count($pins)===12,'exact reviewed source roster');
foreach($pins as[$match,$path,$pin]){
    $original=$root.'/'.$path;if(!is_file($original))$original=$root.'/tests/fixtures/whatsapp_confirmation_runtime/'.basename($path);
    $bytes=file_get_contents($original);$actual=sha1('blob '.strlen($bytes)."\0".$bytes);
    if($path==='app/Services/Dashboard/TakeawayCatalog.php'){
        choiceExpect($pin==='e96fce33037a06330f95d6a91553950cdf32d763','live preparation catalog remains pinned');
        $source=str_replace("'".$path."'=>'".$pin."'","'".$path."'=>'".$actual."'",$source,$count);
        choiceExpect($count===1,'explicit catalog host seam');
    }else choiceExpect($actual===$pin,'reviewed production source '.$path);
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);file_put_contents($destination,$bytes);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_CHOICE_CASE')==='owner'?1:0)]",$source,$count);
choiceExpect($count===1,'owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);choiceExpect($count===1,'root seam');
choiceExpect(substr_count($source,'->resolveCustomerReceiptChoice(')===1&&!str_contains($source,'ReflectionMethod'),
    'wrapper calls only the reviewed public entry rather than duplicating private workflow');
choiceExpect(!preg_match('/(?:->|::)(?:analyze|process|extract|dispatch|send|quote|save|insert|update|delete|prepare|project|transaction|beginTransaction|lockForUpdate)\s*\(/',$source),
    'wrapper has no direct order, quote, writer, sender, provider or locking methods');
foreach(['vendor','bootstrap']as$directory)mkdir($fixture.'/'.$directory,0700,true);
file_put_contents($fixture.'/owner-choice.php',$source);file_put_contents($fixture.'/vendor/autoload.php','<?php ');
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB {public static function connection(){return$GLOBALS['choiceConnection']??=new \ChoiceConnection;}
        public static function table($name){return new \ChoiceQuery($name);}}
}
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess {const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class WhatsAppOrderWorkflow {
        public function resolveCustomerReceiptChoice(int$draft,array$expected,bool$execute=false):array{
            file_put_contents(getcwd().'/calls',json_encode(['draft'=>$draft,'expected'=>$expected,'execute'=>$execute]));
            if($draft!==2218||$expected!==['conversation'=>31,'control_revision'=>15,'draft_revision'=>2,
                'floor'=>6559,'ceiling'=>10303,'snapshot_hash'=>str_repeat('a',64)])throw new \RuntimeException('PRIVATE_SCOPE');
            $sql=file(getcwd().'/sql',FILE_IGNORE_NEW_LINES);
            if($sql!==($execute?['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE']:['SET SESSION TRANSACTION READ ONLY']))
                throw new \RuntimeException('PRIVATE_SESSION');
            $case=getenv('WA_CHOICE_CASE');
            if($case==='throws')throw new \RuntimeException('PRIVATE_UNCERTAIN_OUTCOME');
            if($case==='guard')throw new \RuntimeException('STALE_TRANSCRIPT');
            $already=$case==='already';$status=$already?'ALREADY_DISPATCHED':($execute?'DISPATCHED':'READY_TO_DISPATCH');
            $items=[['product_id'=>2551,'option_id'=>'','quantity'=>'1','quantity_mode'=>'piece'],
                ['product_id'=>2578,'option_id'=>'','quantity'=>'1','quantity_mode'=>'weight'],
                ['product_id'=>2576,'option_id'=>'','quantity'=>'0.5','quantity_mode'=>'weight'],
                ['product_id'=>2566,'option_id'=>'','quantity'=>'1','quantity_mode'=>'piece'],
                ['product_id'=>2557,'option_id'=>'','quantity'=>'2','quantity_mode'=>'piece']];
            foreach($items as&$item)$item['PRIVATE_DATA']='PRIVATE_CUSTOMER_PHONE_201033333333';unset($item);
            $result=['success'=>true,'status'=>$status,'draft_id'=>2218,'conversation_id'=>31,'branch'=>'f:363',
                'items'=>$items,'ticket_id'=>$execute||$already?42:null,'writes'=>$execute&&!$already?null:0,
                'erp_writes'=>$execute&&!$already?null:0,'ai_calls'=>0,'message_sends'=>0,'PRIVATE_DATA'=>'PRIVATE_CUSTOMER_ADDRESS'];
            if($case==='bad-status')$result['status']='PRIVATE_STATUS';
            if($case==='bad-success')$result['success']=false;
            if($case==='bad-branch')$result['branch']='f:307';
            if($case==='bad-draft')$result['draft_id']=2219;
            if($case==='bad-conversation')$result['conversation_id']=32;
            if($case==='bad-ai')$result['ai_calls']=1;
            if($case==='bad-send')$result['message_sends']=1;
            if($case==='bad-writes')$result['writes']=99;
            if($case==='bad-erp')$result['erp_writes']=99;
            if($case==='bad-ticket')$result['ticket_id']='42';
            if($case==='bad-items')$result['items']=[];
            if($case==='bad-item-id')$result['items'][4]['product_id']=2556;
            if($case==='bad-item-mode')$result['items'][4]['quantity_mode']='weight';
            if($case==='bad-item-option')$result['items'][4]['option_id']='f:28:base';
            if($case==='bad-item-quantity')$result['items'][4]['quantity']='1';
            if($case==='bad-zero')$result['items'][2]['quantity']='0';
            if($case==='bad-float')$result['items'][2]['quantity']=0.5;
            return$result;
        }
    }
}
namespace {
    class ChoiceConnection {
        private int$calls=0;
        public function getDriverName(){return getenv('WA_CHOICE_CASE')==='driver'?'sqlite':'mysql';}
        public function transactionLevel(){$this->calls++;return getenv('WA_CHOICE_CASE')==='transaction'
            ||getenv('WA_CHOICE_CASE')==='boundary-transaction'&&$this->calls>1?1:0;}
        public function getPdo(){return new ChoicePdo;}
    }
    class ChoicePdo {
        public function exec($sql){
            if(!in_array($sql,['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE'],true))throw new \RuntimeException('PRIVATE_SQL');
            file_put_contents(getcwd().'/sql',$sql."\n",FILE_APPEND);
            if(getenv('WA_CHOICE_CASE')==='readonly-failure'&&$sql==='SET SESSION TRANSACTION READ ONLY'
                ||getenv('WA_CHOICE_CASE')==='readwrite-failure'&&$sql==='SET SESSION TRANSACTION READ WRITE')throw new \RuntimeException('PRIVATE_SQL_FAILURE');
            return 0;
        }
    }
    class ChoiceQuery {
        private string$table;private array$where=[];
        public function __construct($table){$this->table=$table;}
        public function where($field,$value){$this->where[$field]=$value;return$this;}
        public function exists(){
            if($this->table!=='whatsapp_inbox_conversations'||$this->where!==['id'=>31,'waba_id'=>'TEST_WABA','phone_number_id'=>'TEST_PHONE'])throw new \RuntimeException('PRIVATE_SCOPE');
            return getenv('WA_CHOICE_CASE')!=='scope';
        }
        public function first($columns){
            if($this->table!=='whatsapp_order_drafts'||$this->where!==['id'=>2218,'conversation_id'=>31]
                ||$columns!==['id','conversation_id','evidence_floor','evidence_ceiling','evidence_hash'])throw new \RuntimeException('PRIVATE_DRAFT_SCOPE');
            $case=getenv('WA_CHOICE_CASE');if($case==='missing')return null;
            if($case==='source-drift')file_put_contents(getcwd().'/app/Support/WhatsAppOrderExtraction.php',
                file_get_contents(getcwd().'/app/Support/WhatsAppOrderExtraction.php').' changed');
            return(object)['id'=>2218,'conversation_id'=>31,'evidence_floor'=>$case==='floor'?6558:6559,
                'evidence_ceiling'=>$case==='ceiling'?10304:10303,'evidence_hash'=>$case==='hash'?'PRIVATE_BAD_HASH':str_repeat('a',64)];
        }
    }
    class ChoiceKernel {public function bootstrap(){}}
    class ChoiceApp {public function make($class){return$class==='Illuminate\\Contracts\\Console\\Kernel'?new ChoiceKernel:new$class;}}
    file_put_contents(getcwd().'/bootstrapped','1');return new ChoiceApp;
}
PHP);
$args=['--conversation','31','--draft','2218','--expected-floor','6559','--expected-ceiling','10303','--expected-revision','15','--expected-draft-revision','2'];
function choiceRun(string$case,?array$args=null):array{
    $fixture=$GLOBALS['fixture'];$args??=$GLOBALS['args'];foreach(['calls','sql','bootstrapped']as$name)@unlink($fixture.'/'.$name);
    $guard=$fixture.'/app/Support/WhatsAppOrderExtraction.php';$original=file_get_contents($guard);
    if($case==='source')file_put_contents($guard,$original.' changed');
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;
    $process=proc_open([$php,$fixture.'/owner-choice.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_CHOICE_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
    file_put_contents($guard,$original);choiceExpect($errors===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201033333333')&&!str_contains($output,str_repeat('a',64)),
        'no private proof, customer identity or exception prose '.$case);
    return['exit'=>$exit,'out'=>json_decode($output,true,32,JSON_THROW_ON_ERROR),'sql'=>is_file($fixture.'/sql')?file($fixture.'/sql',FILE_IGNORE_NEW_LINES):[],
        'calls'=>is_file($fixture.'/calls')?json_decode(file_get_contents($fixture.'/calls'),true):null,'bootstrapped'=>is_file($fixture.'/bootstrapped')];
}
foreach([[],['--check'],[...$args,'--force'],[...$args,'--check','--execute'],[...$args,'--check','--check'],[...$args,'--execute','--execute'],
    [...$args,'--draft','2218'],[...$args,'--draft','0'],[...$args,'--expected-snapshot-hash',str_repeat('a',64)]]as$invalid){
    $r=choiceRun('arguments',$invalid);choiceExpect($r['exit']===1&&$r['out']['stage']==='ARGUMENTS'&&$r['out']['writes']===0&&!$r['bootstrapped']&&$r['calls']===null,'invalid arguments stop before application');
}
foreach(['conversation','draft','expected-floor','expected-ceiling','expected-revision','expected-draft-revision']as$flag){
    $changed=$args;$at=array_search('--'.$flag,$changed,true);$changed[$at+1]=(string)((int)$changed[$at+1]+1);
    $r=choiceRun('scope-argument',$changed);choiceExpect($r['exit']===1&&$r['out']['reason']==='OWNER_CHOICE_SCOPE_NOT_APPROVED'&&!$r['bootstrapped'],
        'one-order approval cannot be reused for changed '.$flag);
}
foreach(['owner','source','driver','transaction','readonly-failure','scope','missing','floor','ceiling','hash','source-drift','boundary-transaction']as$case){
    $r=choiceRun($case);choiceExpect($r['exit']===1&&$r['out']['writes']===0&&$r['out']['erp_writes']===0&&$r['calls']===null,
        'host source metadata and connection guards never reach workflow '.$case);
    choiceExpect(!in_array('SET SESSION TRANSACTION READ WRITE',$r['sql'],true),'preflight cannot enable writing '.$case);
}
foreach([[],['--check']]as$flags){
    $r=choiceRun('valid',[...$args,...$flags]);choiceExpect($r['exit']===0&&$r['out']['status']==='READY_TO_DISPATCH'
        &&$r['out']['writes']===0&&$r['out']['erp_writes']===0&&$r['out']['read_only_session']===true
        &&$r['sql']===['SET SESSION TRANSACTION READ ONLY']&&$r['calls']['execute']===false,
        'default and explicit check remain read-only and do not request execution');
    choiceExpect($r['out']['items'][2]['quantity']==='0.5'&&array_keys($r['out']['items'][2])===['product_id','option_id','quantity','quantity_mode'],
        'valid fractional weight is preserved while extra result data is excluded');
}
$r=choiceRun('valid',[...$args,'--execute']);choiceExpect($r['exit']===0&&$r['out']['status']==='DISPATCHED'&&$r['out']['ticket_id']===42
    &&$r['out']['writes']===null&&$r['out']['erp_writes']===null&&!$r['out']['read_only_session']&&$r['calls']['execute']===true
    &&$r['sql']===['SET SESSION TRANSACTION READ ONLY','SET SESSION TRANSACTION READ WRITE'],'only explicit execute crosses the single public workflow boundary with unknown row counts');
foreach([[],['--execute']]as$flags){$r=choiceRun('already',[...$args,...$flags]);choiceExpect($r['exit']===0&&$r['out']['status']==='ALREADY_DISPATCHED'
    &&$r['out']['ticket_id']===42&&$r['out']['writes']===0&&$r['out']['erp_writes']===0,'verified prior ticket is projected idempotently in check and execute');}
$r=choiceRun('readwrite-failure',[...$args,'--execute']);choiceExpect($r['exit']===1&&$r['calls']===null&&$r['out']['writes']===0&&$r['out']['erp_writes']===0,
    'failed write transition does not call workflow or claim writes');
foreach(['throws','guard','bad-status','bad-success','bad-branch','bad-draft','bad-conversation','bad-ai','bad-send','bad-writes','bad-erp','bad-ticket',
    'bad-items','bad-item-id','bad-item-mode','bad-item-option','bad-item-quantity','bad-zero','bad-float']as$case){
    $r=choiceRun($case,[...$args,'--execute']);choiceExpect($r['exit']===1&&$r['out']['status']==='OWNER_CHOICE_UNVERIFIED'
        &&$r['out']['writes']===null&&$r['out']['erp_writes']===null&&$r['calls']!==null,'post-execute exception or invalid result never claims zero writes '.$case);
}
$r=choiceRun('guard');choiceExpect($r['exit']===1&&$r['out']['status']==='OWNER_CHOICE_BLOCKED'&&$r['out']['reason']==='STALE_TRANSCRIPT'
    &&$r['out']['writes']===0&&$r['out']['erp_writes']===0&&$r['sql']===['SET SESSION TRANSACTION READ ONLY'],
    'read-only core guard rejection remains zero-write without exposing private details');
echo 'whatsapp_customer_confirmation_owner_choice: '.$checks." checks passed\n";
