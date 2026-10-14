<?php
// Run the real CLI helper. Fixed host identity/path and pinned source files are fixture seams.
$vendor=getenv('WA_INBOX_TEST_VENDOR');if(is_string($vendor)&&is_dir($vendor))$vendor.='/autoload.php';
if(!is_string($vendor)||!is_file($vendor))throw new RuntimeException('STATUS_TEST_VENDOR_REQUIRED');
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/meta_ai_control_status.php');
$fixture=sys_get_temp_dir().'/meta-ai-status-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;$staged=[];
register_shutdown_function(function()use($fixture){if(!is_dir($fixture))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);});
function expect($actual,$wanted,$label){$GLOBALS['checks']++;if($actual!==$wanted)throw new RuntimeException('FAILED: '.$label);}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$match);expect(isset($match[1]),true,'source guard map present');
preg_match_all("/'([^']+)'=>'([^']+)'/",$match[1],$entries,PREG_SET_ORDER);expect(count($entries),30,'complete deployed source profile guarded before bootstrap');
$guardPins=[];foreach($entries as$entry)$guardPins[$entry[1]]=$entry[2];
preg_match('/SOURCE_PINS = \{(.*?)\n\}/s',file_get_contents($root.'/deployment/apply_meta_ai_control.py'),$publication);
preg_match_all("/'([^']+)': \\((?:None|'[^']*'), '([^']+)'\\)/",$publication[1]??'',$targetPins,PREG_SET_ORDER);
expect(count($targetPins),24,'publisher runtime target roster complete');
foreach($targetPins as$target)expect($guardPins[$target[1]]??null,$target[2],'status agrees with published target '.$target[1]);
foreach($entries as$entry){[$all,$path,$old]=$entry;$bytes=file_get_contents($root.'/'.$path);$staged[$path]=$bytes;
    expect(sha1('blob '.strlen($bytes)."\0".$bytes),$old,'source guard matches release bytes '.$path);
    if(!is_dir(dirname($fixture.'/'.$path)))mkdir(dirname($fixture.'/'.$path),0700,true);file_put_contents($fixture.'/'.$path,$bytes);
    $source=str_replace("'".$path."'=>'".$old."'","'".$path."'=>'".sha1('blob '.strlen($bytes)."\0".$bytes)."'",$source,$count);expect($count,1,'one source guard entry '.$path);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('META_STATUS_CASE')==='bad-owner'?1:0)]",$source,$count);expect($count,1,'fixed server owner seam only');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);expect($count,1,'fixed deployment root seam only');
expect((bool)preg_match('/(?:->|::)(?:request|extract|process|consume|projectPending|projectAcceptedReply|dispatch|send|quote|save|insert|update|delete|prepare|pauseWhatsApp)\s*\(/',$source),false,'helper contains no domain or SQL actions');
expect(substr_count($source,'->inspectWhatsApp('),1,'one optional GET-only inspector');
expect(strpos($source,"if(\$options['agent'])")<strpos($source,'->inspectWhatsApp('),true,'GET inspector behind explicit flag');
foreach(['vendor','bootstrap']as$dir)if(!is_dir($fixture.'/'.$dir))mkdir($fixture.'/'.$dir,0700,true);
file_put_contents($fixture.'/status.php',$source);file_put_contents($fixture.'/vendor/autoload.php',"<?php require getenv('META_STATUS_VENDOR');\n");
file_put_contents($fixture.'/bootstrap/app.php',<<<'PHP'
<?php
namespace App\Services\Dashboard {
    class WhatsAppInboxAccess{const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class MetaInboxAccess{
        public static function accounts(){return [['channel'=>'messenger','account_id'=>'101','access_token'=>'PRIVATE_META_TOKEN_123456789'],['channel'=>'instagram','account_id'=>'202','access_token'=>'PRIVATE_META_TOKEN_123456789']];}
        public static function scope($q,$prefix=''){return$q->where(function($q)use($prefix){$q->where('waba_id','messenger')->where('phone_number_id','101')->orWhere(function($q)use($prefix){$q->where('waba_id','instagram')->where('phone_number_id','202');});});}
        public static function account($channel,$id){foreach(self::accounts()as$a)if($a['channel']===$channel&&$a['account_id']===$id)return$a;return null;}
    }
    class MetaAiControl{
        private $calls=0;
        public function state(){
            $this->calls++;if(getenv('META_STATUS_CASE')==='unavailable')return['available'=>false,'effective'=>false,'status'=>'DISABLED','reason'=>'UNAVAILABLE'];
            $policy=['enabled'=>getenv('META_STATUS_CASE')==='active','channels'=>['whatsapp','messenger','instagram'],
                'schedule'=>['enabled'=>false,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'23:00','all_day'=>true]];
            return['available'=>true,'effective'=>$policy['enabled'],'status'=>$policy['enabled']?'ACTIVE':'DISABLED','policy'=>$policy,
                'revision'=>getenv('META_STATUS_CASE')==='drift'&&$this->calls>1?8:7,'local_time'=>'2026-10-15T01:10:00+03:00',
                'handoff_method'=>'OWNER_ATTESTED','handoff_required'=>false,'updated_at_utc'=>'2026-10-14 22:10:00',
                'api_key'=>'PRIVATE_API_KEY','actor'=>['name'=>'PRIVATE_CUSTOMER_NAME'],'unexpected'=>'PRIVATE_ADDRESS'];
        }
        public function gate($channel){return['effective'=>getenv('META_STATUS_CASE')==='active',
            'reason'=>getenv('META_STATUS_CASE')==='active'?null:'DISABLED','revision'=>getenv('META_STATUS_CASE')==='unavailable'?null:7,
            'actor'=>['phone'=>'PRIVATE_PHONE'],'floor'=>['scope'=>'PRIVATE_SCOPE_HASH']];}
    }
    class MetaBusinessAgent{
        public function inspectWhatsApp(){if(getenv('META_STATUS_CASE')!=='agent')throw new \RuntimeException('UNEXPECTED_GRAPH');return['readable'=>true,'enabled'=>false,'status'=>'PAUSED','http_status'=>200,'token'=>'PRIVATE_GRAPH_TOKEN'];}
    }
    class WhatsAppReplyService{
        protected $prefix='whatsapp';
        private function snapshot($thread){return['inbound_id'=>111,'reason'=>getenv('META_STATUS_CASE')==='closed'?'WINDOW_CLOSED':null,
            'expires'=>'2026-10-16T01:10:00Z','recipient'=>'PRIVATE_RECIPIENT'];}
        private function configured(){return true;}
        private function unresolved($cid){return getenv('META_STATUS_CASE')==='unknown';}
    }
    class MetaReplyService{
        private function snapshot($thread){return['inbound_id'=>222,'reason'=>null,'expires'=>'2026-10-16T01:10:00+00:00','recipient'=>'PRIVATE_RECIPIENT'];}
        private function configured($account){return true;}
        private function unresolved($cid){return false;}
    }
    class WhatsAppOrderFollowupService{public static function validMarker($marker,$cid,$uuid,$inbound){return is_array($marker)&&($marker['version']??null)==='fixture-owned'&&($marker['latest_customer_id']??null)===$inbound;}}
    class MetaOrderFollowupService extends WhatsAppOrderFollowupService{}
}
namespace {
    use Illuminate\Config\Repository;use Illuminate\Container\Container;use Illuminate\Database\Capsule\Manager;
    use Illuminate\Events\Dispatcher;use Illuminate\Encryption\Encrypter;use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;
    $c=new Container;Container::setInstance($c);$db=new Manager($c);$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
    $db->setEventDispatcher(new Dispatcher($c));$db->setAsGlobal();$db->bootEloquent();
    $c->instance('config',new Repository(['database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
        'whatsapp_orders'=>['api_key'=>'PRIVATE_API_KEY_123456789','model'=>'PRIVATE_MODEL_NAME'],
        'meta_orders'=>['api_key'=>'PRIVATE_API_KEY_123456789','model'=>'PRIVATE_MODEL_NAME'],
        'whatsapp_replies'=>['enabled'=>true,'access_token'=>'PRIVATE_WHATSAPP_TOKEN_123456789']]));
    $c->instance('db',$db->getDatabaseManager());$c->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter',new Encrypter(str_repeat('s',32),'AES-256-CBC'));Facade::setFacadeApplication($c);
    if(!function_exists('app')){function app($name=null){$c=Container::getInstance();return$name===null?$c:$c->make($name);}}
    if(!function_exists('config')){function config($key=null,$default=null){return$key===null?app('config'):app('config')->get($key,$default);}}
    require getcwd().'/app/Support/MetaAiSchedule.php';
    foreach(['whatsapp','meta']as$prefix){
        Schema::create($prefix.'_inbox_conversations',function($t){$t->integer('id')->primary();$t->string('waba_id');$t->string('phone_number_id');$t->string('peer_hash');$t->string('last_message_at');$t->string('customer');});
        Schema::create($prefix.'_order_drafts',function($t){$t->integer('id')->primary();$t->integer('conversation_id');$t->string('status');$t->string('reason')->nullable();$t->integer('ticket_id')->nullable();$t->string('assigned_branch')->nullable();$t->string('updated_at');$t->string('extraction');});
        Schema::create($prefix.'_reply_requests',function($t){$t->integer('id')->primary();$t->integer('conversation_id');$t->string('waba_id');$t->string('phone_number_id');$t->string('state');$t->string('kind');$t->integer('inbound_message_id');$t->string('reason')->nullable();$t->string('send_started_at')->nullable();$t->string('completed_at')->nullable();$t->string('updated_at');$t->text('audit');});
        Schema::create($prefix.'_order_scans',function($t){$t->integer('conversation_id');$t->integer('analyzed_ceiling');$t->integer('claimed_ceiling');$t->integer('attempts');$t->string('next_attempt_at')->nullable();$t->string('lease_until')->nullable();$t->string('updated_at');});
        Schema::create($prefix.'_webhook_events',function($t){$t->integer('id');$t->string('processed_at')->nullable();$t->string('payload');});
        Schema::create($prefix.'_inbox_ingestion_failures',function($t){$t->integer('event_id');$t->string('reason');});
        DB::table($prefix.'_webhook_events')->insert([['id'=>1,'processed_at'=>null,'payload'=>'PRIVATE_RAW_PAYLOAD'],['id'=>2,'processed_at'=>null,'payload'=>'PRIVATE_RAW_PAYLOAD'],['id'=>3,'processed_at'=>'2026-10-14 22:00:00','payload'=>'PRIVATE_RAW_PAYLOAD']]);
        DB::table($prefix.'_inbox_ingestion_failures')->insert(['event_id'=>1,'reason'=>'PRIVATE_FAILURE_REASON']);
    }
    Schema::create('pos_service_tickets',function($t){$t->integer('id');$t->string('branch');$t->string('channel');$t->string('status');$t->string('payment_status');$t->integer('paid_order_id')->nullable();$t->string('bill_issued_at')->nullable();$t->integer('last_kitchen_id')->nullable();$t->string('customer_phone');});
    Schema::create('pos_service_kitchen_tickets',function($t){$t->integer('id');$t->string('branch');$t->integer('ticket_id');$t->string('snapshot');});
    Schema::create('pos_branch_print_jobs',function($t){$t->integer('id');$t->string('branch');$t->integer('ticket_id');$t->integer('kitchen_id');$t->string('status');$t->string('claim_token');});
    DB::table('pos_service_tickets')->insert(['id'=>501,'branch'=>getenv('META_STATUS_CASE')==='bad-erp'?'f:94':'f:363','channel'=>'phone','status'=>'new','payment_status'=>'unpaid','paid_order_id'=>null,'bill_issued_at'=>null,'last_kitchen_id'=>601,'customer_phone'=>'PRIVATE_PHONE']);
    DB::table('pos_service_kitchen_tickets')->insert(['id'=>601,'branch'=>'f:363','ticket_id'=>501,'snapshot'=>'PRIVATE_KITCHEN_SNAPSHOT']);
    for($id=1;$id<=4;$id++)DB::table('pos_branch_print_jobs')->insert(['id'=>$id,'branch'=>'f:363','ticket_id'=>501,'kitchen_id'=>$id===4?601:600,'status'=>['pending','claimed','failed','invoked'][$id-1],'claim_token'=>'PRIVATE_PRINT_TOKEN']);
    foreach([['whatsapp','TEST_WABA','TEST_PHONE',7],['messenger','messenger','101',17],['instagram','instagram','202',27]]as[$channel,$waba,$phone,$cid]){
        $prefix=$channel==='whatsapp'?'whatsapp':'meta';
        for($j=0;$j<3;$j++)DB::table($prefix.'_inbox_conversations')->insert(['id'=>$cid+$j,'waba_id'=>$waba,'phone_number_id'=>$phone,'peer_hash'=>'PRIVATE_PEER_HASH','last_message_at'=>'2026-10-14 22:10:0'.(2-$j),'customer'=>'PRIVATE_CUSTOMER_NAME PRIVATE_PHONE PRIVATE_ADDRESS']);
        for($j=0;$j<3;$j++)DB::table($prefix.'_order_drafts')->insert(['id'=>$cid*10+$j,'conversation_id'=>$cid,'status'=>$j===2?'DISPATCHED':'REVIEW','reason'=>$j===2?null:'PRIVATE_ADDRESS','ticket_id'=>$j===2?501:null,'assigned_branch'=>$j===2?'f:363':null,'updated_at'=>'2026-10-14 22:10:00','extraction'=>'PRIVATE_EXTRACTION']);
        for($j=1;$j<=7;$j++){
            $audit=['client_request_id'=>'fixture-uuid','order_followup'=>['version'=>'fixture-owned','latest_customer_id'=>1000+$j,'fields'=>['LOCATION']],
                'text'=>'PRIVATE_REPLY_TEXT','recipient'=>'PRIVATE_PHONE','token'=>'PRIVATE_API_KEY'];
            if($j===7)unset($audit['order_followup']);if(getenv('META_STATUS_CASE')==='bad-marker'&&$j===6)$audit['order_followup']['version']='PRIVATE_BAD_MARKER';
            if(getenv('META_STATUS_CASE')==='huge-audit'&&$j===6)$audit['padding']=str_repeat('PRIVATE_RAW_',10000);
            $cipher=\Illuminate\Support\Facades\Crypt::encryptString(json_encode($audit));if(getenv('META_STATUS_CASE')==='bad-cipher'&&$j===6)$cipher='PRIVATE_BAD_CIPHER';
            DB::table($prefix.'_reply_requests')->insert(['id'=>$cid*100+$j,'conversation_id'=>$cid,'waba_id'=>$waba,'phone_number_id'=>$phone,'state'=>['ACCEPTED','UNKNOWN','FAILED','CLAIMED'][($j-1)%4],'kind'=>'text','inbound_message_id'=>1000+$j,'reason'=>'PRIVATE_ADDRESS','send_started_at'=>'2026-10-14 22:10:00','completed_at'=>'2026-10-14 22:10:00','updated_at'=>'2026-10-14 22:10:00','audit'=>$cipher]);
        }
        DB::table($prefix.'_reply_requests')->insert(['id'=>$cid*100+99,'conversation_id'=>$cid,'waba_id'=>'FOREIGN','phone_number_id'=>$phone,'state'=>'ACCEPTED','kind'=>'text','inbound_message_id'=>1999,'reason'=>null,'send_started_at'=>null,'completed_at'=>null,'updated_at'=>'2026-10-14 22:10:00','audit'=>'PRIVATE_BAD_FOREIGN_CIPHER']);
        DB::table($prefix.'_order_scans')->insert(['conversation_id'=>$cid,'analyzed_ceiling'=>111,'claimed_ceiling'=>111,'attempts'=>1,'next_attempt_at'=>'2026-10-14 22:11:00','lease_until'=>null,'updated_at'=>'2026-10-14 22:10:00']);
    }
    DB::table('meta_inbox_conversations')->insert(['id'=>999,'waba_id'=>'messenger','phone_number_id'=>'FOREIGN','peer_hash'=>'PRIVATE_FOREIGN_HASH','last_message_at'=>'2099-01-01 00:00:00','customer'=>'PRIVATE_FOREIGN_CUSTOMER']);
    DB::listen(function($q){file_put_contents(getcwd().'/queries.jsonl',json_encode($q->sql)."\n",FILE_APPEND);});
    return new class{public function make($class){if($class!==Illuminate\Contracts\Console\Kernel::class)throw new RuntimeException('UNEXPECTED_KERNEL');return new class{public function bootstrap(){}};}};
}
PHP);
function runStatus($case,$arguments=[]){global$fixture,$vendor,$staged;
    $query=$fixture.'/queries.jsonl';if(is_file($query))unlink($query);
    $path='app/Services/Dashboard/MetaInboxAccess.php';if($case==='stale-source')file_put_contents($fixture.'/'.$path,$staged[$path]."\n// drift\n");
    $php=[PHP_BINARY];if(is_string(php_ini_loaded_file()))$php=array_merge($php,['-n','-c',php_ini_loaded_file()]);
    $pipes=[];$p=proc_open(array_merge($php,[$fixture.'/status.php'],$arguments),[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['META_STATUS_CASE'=>$case,'META_STATUS_VENDOR'=>$vendor]));
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    if($case==='stale-source')file_put_contents($fixture.'/'.$path,$staged[$path]);
    expect($stderr,'','no private errors '.$case);expect(strlen($stdout)<120000,true,'bounded result '.$case);
    foreach(['PRIVATE_','TEST_WABA','TEST_PHONE','fixture-uuid','api.facebook.com','graph.facebook.com','peer_hash','payload_hash','receipt_hash','client_request_id']as$private)
        expect(str_contains($stdout,$private),false,'redacted '.$private.' '.$case);
    $json=json_decode($stdout,true,64,JSON_THROW_ON_ERROR);
    if(is_file($query))foreach(file($query,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)as$line){$sql=json_decode($line,true);expect((bool)preg_match('/\A\s*(?:select|pragma)\b/i',$sql),true,'read-only SQL '.$case);}
    return[$exit,$json,is_file($query)];
}
[$exit,$value]=runStatus('off');expect($exit,0,'default probe successful '.($value['stage']??''));expect($value['status'],'READ_READY','off readiness');
foreach(['writes','ai_calls','message_sends','graph_gets']as$count)expect($value[$count],0,'default zero action counter '.$count);
expect($value['control']['policy']['enabled'],false,'default OFF exposed');expect($value['control']['revision'],7,'revision exposed');
expect($value['control']['local_time'],'2026-10-15T01:10:00+03:00','Cairo local offset retained');
expect(count($value['channels']),3,'all channel gates');expect(count($value['conversations']),6,'latest two threads per scoped channel');
expect($value['capture']['whatsapp']['pending_raw_events'],1,'quarantined capture excluded');expect($value['capture']['meta']['quarantined_events'],1,'quarantine counted separately');
expect($value['control_unchanged_during_read'],true,'revision stable');expect($value['worker']['runtime_observed'],false,'scan observation never claims process ran');
$one=$value['conversations'][0];expect(count($one['drafts']),2,'draft list bounded');expect(count($one['replies']),5,'receipt list bounded');
expect($one['drafts'][0]['erp']['payment_status'],'unpaid','unpaid contract observed');expect($one['drafts'][0]['erp']['bill_issued'],false,'billing remains unissued');
expect($one['drafts'][0]['erp']['kitchen_ticket_present'],true,'kitchen record matches ticket and branch');
expect(count($one['drafts'][0]['erp']['print_jobs']),3,'print list bounded');expect($one['drafts'][0]['erp']['print_jobs_truncated'],true,'print truncation explicit');
expect($one['drafts'][0]['erp']['print_jobs'][0]['state'],'invoked','print invocation classified accurately');
expect($one['replies'][0]['ownership'],'MANUAL','manual ownership separate');expect($one['replies'][1]['ownership'],'AUTOMATIC','verified marker ownership');
[$exit,$value]=runStatus('active',['--channel','whatsapp','--conversation','7']);expect($exit,0,'selected thread probe successful');expect($value['selection']['matched'],1,'explicit scope selection');expect($value['control']['effective'],true,'enabled state exposed');
foreach(['bad-marker','bad-cipher','huge-audit']as$case){[$exit,$value]=runStatus($case,['--channel','whatsapp','--conversation','7']);expect($exit,0,'unsafe receipt does not leak or abort '.$case);expect($value['conversations'][0]['replies'][1]['ownership'],'UNREADABLE','bad receipt explicit '.$case);}
[$exit,$value]=runStatus('unknown',['--channel','whatsapp','--conversation','7']);expect($value['conversations'][0]['sender']['reason'],'UNKNOWN_PENDING','uncertainty window reason');
[$exit,$value]=runStatus('closed',['--channel','whatsapp','--conversation','7']);expect($value['conversations'][0]['sender']['reason'],'WINDOW_CLOSED','closed window explicit');
[$exit,$value]=runStatus('bad-erp',['--channel','whatsapp','--conversation','7']);expect($value['conversations'][0]['drafts'][0]['erp']['status'],'TICKET_SCOPE_MISMATCH','ERP scope mismatch cannot prove dispatch');
[$exit,$value]=runStatus('drift');expect($value['control_unchanged_during_read'],false,'concurrent policy revision observable');
[$exit,$value]=runStatus('unavailable');expect($exit,0,'missing control policy observable');expect($value['control']['available'],false,'unavailable control explicit');expect($value['control']['policy'],null,'unavailable policy not invented');
[$exit,$value]=runStatus('agent',['--agent-status']);expect($exit,0,'explicit inspector probe successful');expect($value['graph_gets'],1,'one optional GET attempt');expect($value['external_agent']['status'],'PAUSED','external GET result sanitized');expect($value['writes'],0,'optional inspector performs no writes');expect($value['message_sends'],0,'optional inspector sends no messages');
foreach([['bad-owner',[],'OWNER'],['stale-source',[],'SOURCE_GUARD'],['bad-arguments',['--conversation','7'],'ARGUMENTS'],
    ['bad-arguments',['--channel','all'],'ARGUMENTS'],['bad-arguments',['--agent-status','--agent-status'],'ARGUMENTS'],
    ['bad-arguments',['--conversation','0','--channel','whatsapp'],'ARGUMENTS'],['bad-arguments',['--force'],'ARGUMENTS']]as[$case,$args,$stage]){
    [$exit,$value,$bootstrapped]=runStatus($case,$args);expect($exit,1,'guard fails closed '.$stage);expect($value['stage'],$stage,'specific guard stage');expect($bootstrapped,false,'guard before bootstrap');expect($value['graph_gets'],0,'guard before optional Graph');
}
echo "meta-ai-control-status: $checks checks passed\n";
