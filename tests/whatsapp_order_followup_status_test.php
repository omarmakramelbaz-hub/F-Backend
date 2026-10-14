<?php
// Execute the actual read-only helper with private Laravel SQLite/Crypt fixtures.
// Only its fixed host owner/path and source-pin literals are fixture seams.
$vendor=getenv('WA_INBOX_TEST_VENDOR');
if (is_string($vendor)&&is_dir($vendor)) $vendor.='/autoload.php';
if (!is_string($vendor)||!is_file($vendor)) throw new RuntimeException('STATUS_TEST_VENDOR_REQUIRED');
$root=dirname(__DIR__);
$source=file_get_contents($root.'/deployment/whatsapp_order_followup_status.php');
$fixture=sys_get_temp_dir().'/wa-followup-status-'.bin2hex(random_bytes(8));
mkdir($fixture,0700);
register_shutdown_function(static function () use ($fixture): void {
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($fixture);
});
$checks=0;
function statusExpect($actual,$expected,string $label): void {
    $GLOBALS['checks']++; if ($actual!==$expected) throw new RuntimeException('FAILED: '.$label);
}
foreach (['app/Services/Dashboard','vendor','bootstrap'] as $directory) mkdir($fixture.'/'.$directory,0700,true);
$files=[];
foreach (['WhatsAppOrderAiProvider','WhatsAppReplyService','WhatsAppOrderFollowupService','WhatsAppOrderWorkflow','WhatsAppInboxAccess'] as $name) {
    $bytes=file_get_contents($root.'/app/Services/Dashboard/'.$name.'.php');
    $files[$name]=$bytes; file_put_contents($fixture.'/app/Services/Dashboard/'.$name.'.php',$bytes);
    $pin=sha1('blob '.strlen($bytes)."\0".$bytes);
    $source=preg_replace("/'".$name."'=>'[^']+'/", "'".$name."'=>'".$pin."'", $source,1,$count);
    statusExpect($count,1,'one unchanged source guard entry per staged class');
}
$source=str_replace("posix_getpwnam('fasakha')", "['uid'=>posix_geteuid()+(getenv('STATUS_FIXTURE_CASE')==='bad-owner'?1:0)]", $source,$count);
statusExpect($count,1,'only fixed host owner lookup is substituted');
$source=str_replace("chdir('/home/fasakha/public_html')", 'chdir('.var_export($fixture,true).')', $source,$count);
statusExpect($count,1,'only fixed deployment directory is substituted');
statusExpect((bool)preg_match('/(?:->|::)(?:request|extract|process|consume|projectPending|dispatch|send|quote|save|insert|update|delete)\s*\(/',$source),false,
    'actual helper contains no service actions or database mutations');
statusExpect((bool)preg_match('/new\s+(?:\\\\?App\\\\Services\\\\Dashboard\\\\|WhatsApp)/',$source),false,
    'actual helper constructs no domain services');
file_put_contents($fixture.'/status.php',$source);
file_put_contents($fixture.'/vendor/autoload.php',"<?php require getenv('STATUS_FIXTURE_VENDOR');\n");
file_put_contents($fixture.'/bootstrap/app.php', <<<'PHP'
<?php
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use App\Services\Dashboard\WhatsAppInboxAccess;
use App\Services\Dashboard\WhatsAppOrderFollowupService;
$container=new Container(); Container::setInstance($container);
$capsule=new Capsule($container);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$capsule->setEventDispatcher(new Dispatcher($container)); $capsule->setAsGlobal(); $capsule->bootEloquent();
$configuration=new Repository(['database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
    'whatsapp_orders'=>['enabled'=>true,'mode'=>'auto'],
    'whatsapp_replies'=>['enabled'=>true,'access_token'=>'PRIVATE_ACCESS_TOKEN']]);
$container->instance('config',$configuration); $container->instance('db',$capsule->getDatabaseManager());
$container->instance('db.schema',$capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter',new Encrypter(str_repeat('s',32),'AES-256-CBC'));
Facade::setFacadeApplication($container);
if (!function_exists('config')) { function config($name,$default=null) { return Container::getInstance()->make('config')->get($name,$default); } }
require getcwd().'/app/Services/Dashboard/WhatsAppInboxAccess.php';
require getcwd().'/app/Services/Dashboard/WhatsAppOrderFollowupService.php';
spl_autoload_register(static function ($class): void {
    if (str_starts_with($class,'App\\')) throw new RuntimeException('UNEXPECTED_DOMAIN_SERVICE_LOAD');
});
Schema::create('whatsapp_inbox_conversations',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->string('waba_id'); $t->string('phone_number_id'); $t->string('customer');
});
Schema::create('whatsapp_order_drafts',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('conversation_id'); $t->string('status'); $t->string('reason')->nullable();
    $t->integer('ticket_id')->nullable(); $t->string('assigned_branch')->nullable(); $t->string('updated_at'); $t->string('customer');
});
Schema::create('whatsapp_reply_requests',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('conversation_id'); $t->string('waba_id'); $t->string('phone_number_id');
    $t->string('kind'); $t->string('state'); $t->integer('inbound_message_id'); $t->string('reason')->nullable();
    $t->string('send_started_at')->nullable(); $t->string('updated_at'); $t->text('audit');
});
Schema::create('whatsapp_webhook_events',static function (Blueprint $t): void { $t->integer('id')->primary(); $t->string('processed_at')->nullable(); });
Schema::create('whatsapp_inbox_ingestion_failures',static function (Blueprint $t): void { $t->integer('event_id'); });
$case=getenv('STATUS_FIXTURE_CASE');
$waba=WhatsAppInboxAccess::WABA_ID; $phone=WhatsAppInboxAccess::PHONE_ID;
DB::table('whatsapp_inbox_conversations')->insert(['id'=>7,'waba_id'=>$case==='bad-scope'?'foreign':$waba,
    'phone_number_id'=>$phone,'customer'=>'PRIVATE_CUSTOMER_NAME 01000000001 PRIVATE_ADDRESS']);
for ($id=101;$id<=105;$id++) DB::table('whatsapp_order_drafts')->insert(['id'=>$id,'conversation_id'=>7,
    'status'=>$id===105?'DISPATCHED':($id===104?'READY':'private status'),
    'reason'=>$id===103?'PRIVATE_ADDRESS details':'REVIEW_REQUIRED','ticket_id'=>$id===105?501:null,
    'assigned_branch'=>$id===105?'f:363':($id===104?'v:9':'PRIVATE_ADDRESS'),'updated_at'=>'2026-10-14 12:00:00',
    'customer'=>'PRIVATE_CUSTOMER_NAME 01000000001 PRIVATE_ADDRESS']);
DB::table('whatsapp_order_drafts')->insert(['id'=>999,'conversation_id'=>8,'status'=>'READY','reason'=>null,
    'ticket_id'=>999,'assigned_branch'=>'f:94','updated_at'=>'2026-10-14 12:00:00','customer'=>'foreign']);
for ($id=1;$id<=21;$id++) {
    $fields=$id%3===0?['NAME']:($id%3===1?['LOCATION']:['CONFIRMATION']);
    $marker=['version'=>'whatsapp-order-followup-v1','kind'=>$fields===['CONFIRMATION']?'confirmation':'question',
        'fields'=>$fields,'latest_customer_id'=>1000+$id,'latest_customer_key'=>hash('sha256','customer'.$id),
        'episode_customer_key'=>hash('sha256','episode'),'proposal_hash'=>$fields===['CONFIRMATION']?str_repeat('a',64):null];
    $uuid=WhatsAppOrderFollowupService::requestUuid($marker+['conversation_id'=>7],Crypt::getKey());
    if ($id===20&&$case==='bad-marker') $marker['fields']=['LOCATION','LOCATION'];
    $audit=['client_request_id'=>$uuid,'order_followup'=>$marker,'name'=>'PRIVATE_CUSTOMER_NAME','phone'=>'01000000001',
        'address'=>'PRIVATE_ADDRESS','text'=>'PRIVATE_REQUEST_TEXT','token'=>'PRIVATE_ACCESS_TOKEN'];
    if ($id===21) unset($audit['order_followup']);
    if ($id===20&&$case==='oversized-audit') $audit['padding']=str_repeat('PRIVATE_RAW_',7000);
    $encrypted=Crypt::encryptString(json_encode($audit,JSON_THROW_ON_ERROR));
    if ($id===20&&$case==='decrypt-failure') $encrypted='PRIVATE_BROKEN_CIPHERTEXT';
    DB::table('whatsapp_reply_requests')->insert(['id'=>$id,'conversation_id'=>7,'waba_id'=>$waba,'phone_number_id'=>$phone,
        'kind'=>'text','state'=>['CLAIMED','UNKNOWN','ACCEPTED','FAILED'][($id-1)%4],'inbound_message_id'=>1000+$id,
        'reason'=>$id===19?'PRIVATE_REQUEST_TEXT details':'FIXED_REASON','send_started_at'=>$id%2?'2026-10-14 12:00:00':null,
        'updated_at'=>'2026-10-14 12:00:00','audit'=>$encrypted]);
}
// Corrupt private records outside each required filter must never be decrypted.
foreach ([22=>[7,'foreign',$phone,'text'],23=>[8,$waba,$phone,'text'],24=>[7,$waba,'foreign','text'],25=>[7,$waba,$phone,'image']] as $id=>$scope)
    DB::table('whatsapp_reply_requests')->insert(['id'=>$id,'conversation_id'=>$scope[0],'waba_id'=>$scope[1],'phone_number_id'=>$scope[2],
        'kind'=>$scope[3],'state'=>'ACCEPTED','inbound_message_id'=>1000+$id,'reason'=>null,'send_started_at'=>null,
        'updated_at'=>'2026-10-14 12:00:00','audit'=>'PRIVATE_BAD_SCOPE_AUDIT']);
DB::table('whatsapp_webhook_events')->insert([['id'=>1,'processed_at'=>null],['id'=>2,'processed_at'=>null],
    ['id'=>3,'processed_at'=>null],['id'=>4,'processed_at'=>'2026-10-14 12:00:00']]);
DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>1]);
DB::listen(static function ($query): void {
    file_put_contents(getcwd().'/queries.jsonl',json_encode($query->sql,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
});
return new class {
    public function make($class) {
        if ($class!==Illuminate\Contracts\Console\Kernel::class) throw new RuntimeException('UNEXPECTED_SERVICE_CONSTRUCTION');
        return new class { public function bootstrap(): void {} };
    }
};
PHP
);
function statusRun(string $case,array $arguments=['--conversation','7']): array {
    global $fixture,$vendor;
    $queryFile=$fixture.'/queries.jsonl'; if (is_file($queryFile)) unlink($queryFile);
    $env=array_merge(getenv(),['STATUS_FIXTURE_VENDOR'=>$vendor,'STATUS_FIXTURE_CASE'=>$case]);
    $command=[PHP_BINARY];
    if (is_string(php_ini_loaded_file())&&php_ini_scanned_files()===false) {
        $command[]='-n'; $command[]='-c'; $command[]=php_ini_loaded_file();
    }
    $child=proc_open(array_merge($command,[$fixture.'/status.php'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture,$env);
    if (!is_resource($child)) throw new RuntimeException('STATUS_CHILD_UNAVAILABLE');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($child);
    statusExpect($stderr,'','helper never leaks exceptions to stderr');
    $data=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
    statusExpect(strlen($stdout)<12000,true,'diagnostic output remains bounded');
    foreach (['PRIVATE_','01000000001','client_request_id','order_followup','episode_customer_key','latest_customer_key','proposal_hash','audit'] as $secret)
        statusExpect(str_contains($stdout,$secret),false,'diagnostic excludes customer data, ciphertext, credentials and marker internals');
    $queries=is_file($queryFile)?file($queryFile,FILE_IGNORE_NEW_LINES):[];
    foreach ($queries as $query) statusExpect((bool)preg_match('/\ASELECT\b/i',json_decode($query,true,8,JSON_THROW_ON_ERROR)),true,'every diagnostic query is SELECT only');
    return [$data,$exit,count($queries)];
}
[$ready,$exit,$queries]=statusRun('ready');
statusExpect([$ready['status'],$exit,$queries],['READ_READY',0,4],'actual helper performs its four read queries '.json_encode($ready));
statusExpect([$ready['writes'],$ready['ai_calls'],$ready['conversation_id'],$ready['enabled'],$ready['mode'],$ready['replies_enabled'],$ready['reply_token_present']],
    [0,0,7,true,'auto',true,true],'only operational configuration flags are disclosed');
statusExpect(array_column($ready['drafts'],'id'),[105,104,103],'only three latest drafts in this conversation');
statusExpect([$ready['drafts'][0]['ticket_id'],$ready['drafts'][0]['branch'],$ready['drafts'][0]['status']],[501,'f:363','DISPATCHED'],'ticket and branch are accurately read');
statusExpect([$ready['drafts'][2]['status'],$ready['drafts'][2]['reason'],$ready['drafts'][2]['branch']],['UNKNOWN',null,null],'arbitrary draft values are bounded or suppressed');
statusExpect(array_column($ready['automatic_replies'],'id'),range(20,2),'twenty-row window skips manual and foreign requests without exposing them');
statusExpect(array_values(array_unique(array_column($ready['automatic_replies'],'state'))),['FAILED','ACCEPTED','UNKNOWN','CLAIMED'],'all four receipt states remain accurate');
statusExpect([$ready['automatic_replies'][0]['kind'],$ready['automatic_replies'][0]['fields'],$ready['automatic_replies'][0]['inbound_message_id'],$ready['automatic_replies'][0]['send_started']],
    ['confirmation',['CONFIRMATION'],1020,false],'confirmation metadata and send-start state are accurate');
statusExpect([$ready['automatic_replies'][1]['kind'],$ready['automatic_replies'][1]['fields'],$ready['automatic_replies'][1]['reason'],$ready['automatic_replies'][1]['send_started']],
    ['question',['LOCATION'],null,true],'location request discloses only fixed metadata');
statusExpect($ready['pending_raw_events'],2,'pending count excludes processed and quarantined events');
foreach (['bad-marker'=>'REPLIES','decrypt-failure'=>'REPLIES','oversized-audit'=>'REPLIES','bad-scope'=>'CONVERSATION','bad-owner'=>'OWNER'] as $case=>$stage) {
    [$failed,$exit,$queries]=statusRun($case);
    statusExpect([$failed['status'],$failed['stage'],$exit],['READ_FAILED',$stage,1],$case.' fails closed with a fixed stage');
    if ($case==='bad-owner') statusExpect($queries,0,'wrong owner cannot bootstrap or query');
}
foreach ([[],['--conversation','0'],['--conversation','7','extra'],['--wrong','7'],['--conversation','10000000000']] as $arguments) {
    [$failed,$exit,$queries]=statusRun('ready',$arguments);
    statusExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','ARGUMENTS',1,0],'bad arguments are rejected before reads');
}
$providerPath=$fixture.'/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
file_put_contents($providerPath,$files['WhatsAppOrderAiProvider']."\n");
[$failed,$exit,$queries]=statusRun('ready');
statusExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',1,0],'changed source bytes cannot bootstrap or query');
echo 'WHATSAPP_ORDER_FOLLOWUP_STATUS_TESTS='.$checks." PASS\n";
