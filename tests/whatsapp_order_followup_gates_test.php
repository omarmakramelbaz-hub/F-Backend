<?php
// Execute the actual LOCATION gate observer against native encrypted DTOs and real
// Laravel 8 SQLite/Crypt. Only its fixed host owner/path and source pins are seams.
$vendor=getenv('WA_INBOX_TEST_VENDOR');
if (is_string($vendor)&&is_dir($vendor)) $vendor.='/autoload.php';
if (!is_string($vendor)||!is_file($vendor)) throw new RuntimeException('GATES_TEST_VENDOR_REQUIRED');
$root=dirname(__DIR__); $source=file_get_contents($root.'/deployment/whatsapp_order_followup_gates.php');
$fixture=sys_get_temp_dir().'/wa-followup-gates-'.bin2hex(random_bytes(8)); mkdir($fixture,0700);
register_shutdown_function(static function () use ($fixture): void {
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($fixture);
});
$checks=0;
function gatesExpect($actual,$expected,string $label): void {
    $GLOBALS['checks']++; if ($actual!==$expected) throw new RuntimeException('FAILED: '.$label);
}
foreach (['app/Services/Dashboard','vendor','bootstrap'] as $directory) mkdir($fixture.'/'.$directory,0700,true);
$files=[];
foreach (['WhatsAppOrderAiProvider','WhatsAppReplyService','WhatsAppOrderWorkflow','WhatsAppOrderFollowupService','WhatsAppInboxAccess','WhatsAppInboxMessage'] as $name) {
    $bytes=file_get_contents($root.'/app/Services/Dashboard/'.$name.'.php'); $files[$name]=$bytes;
    file_put_contents($fixture.'/app/Services/Dashboard/'.$name.'.php',$bytes); chmod($fixture.'/app/Services/Dashboard/'.$name.'.php',0600);
    $source=preg_replace("/'".$name."'=>'[^']+'/","'".$name."'=>'".sha1('blob '.strlen($bytes)."\0".$bytes)."'",$source,1,$count);
    gatesExpect($count,1,'one unchanged source-guard entry per staged class');
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('GATES_FIXTURE_CASE')==='bad-owner'?1:0)]",$source,$count);
gatesExpect($count,1,'only fixed host owner lookup is substituted');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);
gatesExpect($count,1,'only fixed deployment directory is substituted');
gatesExpect((bool)preg_match('/(?:->|::)(?:request|extract|process|consume|projectPending|dispatch|send|sendOrderFollowup|quote|save|insert|update|delete|automaticFollowupPlan)\s*\(/',$source),false,
    'actual helper contains no actions, mutations or automatic planner invocation');
file_put_contents($fixture.'/gates.php',$source);
file_put_contents($fixture.'/vendor/autoload.php',"<?php require getenv('GATES_FIXTURE_VENDOR');\n");
file_put_contents($fixture.'/bootstrap/app.php', <<<'PHP'
<?php
namespace App\Models {
    // Minimal persisted Eloquent identity, with the actual InboxAccess return type.
    class User extends \Illuminate\Database\Eloquent\Model {
        protected $table='users'; public $timestamps=false; protected $guarded=[];
    }
}
namespace App\Services\Dashboard {
    // Only the authorization boundary is synthetic. It reloads the persisted user
    // with SELECTs and cannot provide any application-write or transport operation.
    class TakeawayAccess {
        public function actor($actor): \App\Models\User {
            $fresh=$actor?\App\Models\User::withoutGlobalScopes()->find($actor->id):null;
            \abort_unless($fresh&&$fresh->active&&$fresh->account_type==='admin',403); return $fresh;
        }
        public function permissions($actor): array { return ['can_checkout'=>(bool)$this->actor($actor)->can_checkout]; }
    }
}
namespace {
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
use App\Services\Dashboard\WhatsAppInboxAccess as Access;
use App\Services\Dashboard\WhatsAppOrderWorkflow as Workflow;
use App\Services\Dashboard\WhatsAppOrderFollowupService as Followup;
if (!function_exists('app')) { function app($name=null) { $c=Container::getInstance(); return $name===null?$c:$c->make($name); } }
if (!function_exists('config')) { function config($name=null,$default=null) { return $name===null?app('config'):app('config')->get($name,$default); } }
if (!function_exists('now')) { function now($zone=null) { return \Carbon\Carbon::now($zone); } }
if (!function_exists('abort_unless')) { function abort_unless($condition,$status,$message='PRIVATE_EXCEPTION') { if (!$condition) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message); } }
$container=new Container(); Container::setInstance($container);
$capsule=new Capsule($container); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$capsule->setEventDispatcher(new Dispatcher($container)); $capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('config',new Repository(['app'=>['key'=>str_repeat('g',32)],
    'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]],
    'whatsapp_orders'=>['enabled'=>true,'mode'=>'auto','automation_actor_id'=>9,'model'=>'PRIVATE_MODEL','api_key'=>'PRIVATE_API_KEY','activation_message_id'=>0],
    'whatsapp_cart'=>['activated_at'=>'2026-10-14 11:00:00'],
    'whatsapp_replies'=>['enabled'=>true,'access_token'=>'PRIVATE_ACCESS_TOKEN_VALID']]));
$container->instance('db',$capsule->getDatabaseManager()); $container->instance('db.schema',$capsule->getConnection()->getSchemaBuilder());
$container->instance('encrypter',new Encrypter(str_repeat('g',32),'AES-256-CBC')); Facade::setFacadeApplication($container);
\Carbon\Carbon::setTestNow('2026-10-14 12:00:10'); \Carbon\CarbonImmutable::setTestNow('2026-10-14 12:00:10');
$staged=['WhatsAppOrderAiProvider','WhatsAppReplyService','WhatsAppOrderWorkflow','WhatsAppOrderFollowupService','WhatsAppInboxAccess','WhatsAppInboxMessage'];
$readDependencies=['App\\Support\\WhatsAppInboxProtocol','App\\Support\\WhatsAppOrderExtraction',
    'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence','App\\Services\\Dashboard\\WhatsAppOrderSourceSelection',
    'App\\Services\\Dashboard\\WhatsAppInboxMedia',
    'App\\Services\\Dashboard\\WhatsAppVoiceMedia','App\\Services\\Dashboard\\PosServiceTicket',
    'App\\Services\\Dashboard\\WhatsAppAcceptedReplyEvidence'];
spl_autoload_register(static function ($class) use ($staged,$readDependencies): void {
    if (!str_starts_with($class,'App\\')) return;
    $name=substr($class,strrpos($class,'\\')+1);
    if (in_array($name,$staged,true)&&$class==='App\\Services\\Dashboard\\'.$name) {
        if (getenv('GATES_FIXTURE_CASE')==='wrong-loaded-source'&&$name==='WhatsAppOrderAiProvider') {
            require getenv('GATES_FIXTURE_ROOT').'/app/Services/Dashboard/'.$name.'.php'; return;
        }
        require getcwd().'/app/Services/Dashboard/'.$name.'.php'; return;
    }
    if (!in_array($class,$readDependencies,true)) throw new \RuntimeException('UNEXPECTED_DOMAIN_SERVICE_LOAD');
    require getenv('GATES_FIXTURE_ROOT').'/'.str_replace('\\','/',lcfirst($class)).'.php';
});
Schema::create('users',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->boolean('active'); $t->string('account_type'); $t->integer('owner_resturant_id')->nullable(); $t->boolean('can_checkout');
});
Schema::create('whatsapp_inbox_conversations',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->string('waba_id'); $t->string('phone_number_id'); $t->string('peer_hash'); $t->text('private_customer');
});
Schema::create('whatsapp_inbox_messages',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('conversation_id'); $t->string('message_key'); $t->string('source');
    $t->string('direction'); $t->string('type'); $t->string('sent_at'); $t->text('content');
});
Schema::create('whatsapp_order_drafts',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('conversation_id'); $t->string('status'); $t->string('reason'); $t->integer('ticket_id')->nullable();
    $t->integer('evidence_floor'); $t->integer('evidence_ceiling'); $t->string('evidence_hash'); $t->text('extraction');
});
Schema::create('whatsapp_order_scans',static function (Blueprint $t): void {
    $t->integer('conversation_id')->primary(); $t->integer('attempts'); $t->string('next_attempt_at')->nullable(); $t->string('lease_until')->nullable();
    $t->integer('claimed_ceiling'); $t->integer('analyzed_ceiling'); $t->integer('auto_floor'); $t->string('claim_nonce')->nullable();
});
Schema::create('whatsapp_reply_requests',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('actor_id'); $t->integer('conversation_id'); $t->string('waba_id'); $t->string('phone_number_id');
    $t->string('kind'); $t->string('state'); $t->integer('inbound_message_id'); $t->string('reason')->nullable(); $t->string('request_hash');
    $t->string('created_at'); $t->string('completed_at')->nullable(); $t->text('audit');
});
$case=getenv('GATES_FIXTURE_CASE'); $waba=Access::WABA_ID; $phoneId=Access::PHONE_ID;
$scope=$waba.':'.$phoneId.':'; $peerPhone='201000000001'; $peerUser='US.PRIVATE_FIXTURE'; $peerIdentity='user:'.$peerUser;
DB::table('users')->insert(['id'=>9,'active'=>$case!=='actor-unavailable','account_type'=>'admin','owner_resturant_id'=>null,'can_checkout'=>true]);
DB::table('whatsapp_inbox_conversations')->insert(['id'=>7,'waba_id'=>$case==='bad-scope'?'foreign':$waba,'phone_number_id'=>$phoneId,
    'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$peerIdentity,Crypt::getKey()),'private_customer'=>'PRIVATE_ADDRESS PRIVATE_CUSTOMER']);
DB::table('whatsapp_inbox_conversations')->insert(['id'=>8,'waba_id'=>$waba,'phone_number_id'=>$phoneId,
    'peer_hash'=>str_repeat('a',64),'private_customer'=>'PRIVATE_FOREIGN_CUSTOMER']);
$rows=[
    ['id'=>1,'direction'=>'inbound','text'=>'اسمي عميل تجريبي ورقمي 201000000001 وعنواني شارع المثال في المنصورة. عايز 0.5 كيلو فسيخ.'],
    ['id'=>2,'direction'=>'outbound','text'=>'تأكيد طلب عميل تجريبي: 0.5 كيلو فسيخ من المنصورة إلى شارع المثال.'],
    ['id'=>3,'direction'=>'inbound','text'=>'تمام موافق'],
];
if ($case==='location-text') $rows[2]['text']='ابعت اللوكيشن';
if ($case==='delayed-data') $rows[2]['text']='اسمي عميل تجريبي ورقمي 201000000001 وعنواني شارع المثال في المنصورة';
foreach (['hint-quoted-approval'=>'"موافق"','hint-negative-approval'=>'مش موافق',
    'hint-detail-ok'=>'العنوان شارع المثال ok','hint-courtesy-approval'=>'تمام يا باشا'] as $hintCase=>$latestText)
    if ($case===$hintCase) $rows[2]['text']=$latestText;
if ($case==='hint-bounded-counts') $rows[2]['text']=str_repeat('ن ',8100);
if ($case==='normalized-none') $rows[2]['text']='WA-2026 تمام موافق';
if ($case==='branch-mode') $rows[0]['text'].=' الطلب في الصالة.';
if ($case==='already-asked') $rows[]=['id'=>4,'direction'=>'outbound','text'=>'من فضلك ابعت اللوكيشن'];
if ($case==='native-location'||$case==='native-location-cited') $rows[]=['id'=>4,'direction'=>'inbound','text'=>null,'location'=>['latitude'=>31.0,'longitude'=>31.4]];
if (str_starts_with($case,'media-')) {
    if ($case==='media-direct-image') unset($rows[2]);
    $rows[]=['id'=>$case==='media-direct-image'?3:4,'direction'=>'inbound','text'=>null,
        'image'=>['id'=>'PRIVATE_IMAGE_REFERENCE','mime_type'=>'image/jpeg']];
    $last=array_key_last($rows);
    if ($case==='media-image-caption') $rows[$last]['image']['caption']='PRIVATE_CAPTION تغيّر العنوان';
    if ($case==='media-image-empty-caption') $rows[$last]['image']['caption']='';
    if ($case==='media-image-null-caption') $rows[$last]['image']['caption']=null;
    if ($case==='media-image-invalid-caption') $rows[$last]['image']['caption']=12345;
    if ($case==='media-image-oversized-caption') $rows[$last]['image']['caption']=str_repeat('ن',17000);
    if ($case==='media-image-bad-reference') $rows[$last]['image']['id']='PRIVATE IMAGE REFERENCE';
    if ($case==='media-image-bad-peer') $rows[$last]['image_bad_peer']=true;
    if ($case==='media-second-image') $rows[]=['id'=>5,'direction'=>'inbound','text'=>null,
        'image'=>['id'=>'PRIVATE_SECOND_IMAGE_REFERENCE','mime_type'=>'image/jpeg']];
}
if ($case==='incomplete-snapshot') for ($id=4;$id<=61;$id++) $rows[]=['id'=>$id,'direction'=>'inbound','text'=>'بيانات إضافية'];
foreach ($rows as $row) {
    $sent=$case==='delayed-data'&&$row['id']<3?'2026-10-14 10:59:0'.$row['id']:'2026-10-14 12:00:'.str_pad((string)min($row['id'],9),2,'0',STR_PAD_LEFT);
    $type=isset($row['location'])?'location':(isset($row['image'])?'image':'text'); $native='wamid.PRIVATE_NATIVE_'.$row['id'];
    $raw=['id'=>$native,'from'=>$peerPhone,'from_user_id'=>$peerUser,'timestamp'=>(string)strtotime($sent.' UTC'),'type'=>$type];
    if ($type==='location') $raw['location']=$row['location'];
    elseif ($type==='image') $raw['image']=$row['image'];
    else $raw['text']=['body'=>$row['text']];
    if (($row['image_bad_peer']??false)===true) $raw['from_user_id']='US.PRIVATE_FOREIGN_IMAGE_PEER';
    $dto=['message_id'=>$native,'peer_identity'=>$peerIdentity,'peer_phone'=>$peerPhone,'peer_user_id'=>$peerUser,
        'source'=>$row['direction']==='inbound'?'messages':'smb_message_echoes','direction'=>$row['direction'],'type'=>$type,
        'sent_at'=>$sent,'text'=>$row['text'],'content'=>['message'=>$raw]];
    DB::table('whatsapp_inbox_messages')->insert(['id'=>$row['id'],'conversation_id'=>7,
        'message_key'=>hash('sha256','whatsapp-message-v1:'.$scope.$native),'source'=>$dto['source'],'direction'=>$row['direction'],
        'type'=>$type,'sent_at'=>$sent,'content'=>Crypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
}
$data=['decision'=>'CONFIRMED','fulfillment'=>'UNKNOWN',
    'customer'=>['name'=>'عميل تجريبي','phone'=>$peerPhone,'address'=>'شارع المثال','area'=>'المنصورة','notes'=>null],
    'branch_hint'=>'المنصورة','items'=>[['name'=>'فسيخ','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>null]],
    'approximate_total'=>null,'evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m2'],'customer_acceptance_ids'=>['m3'],'location_id'=>null],
    'issues'=>[]];
if ($case==='invalid-extraction') $data['customer']['address']='عنوان مختلق خاص';
if ($case==='native-location-cited') $data['evidence']['location_id']='m4';
if ($case==='media-direct-image') $data['evidence']['customer_acceptance_ids']=[];
$ceiling=count($rows);
$snapshotMethod=new \ReflectionMethod(Workflow::class,'snapshot'); $snapshotMethod->setAccessible(true);
// Hash the actual native snapshot before the read-observation listener begins.
// A foreign scope fixture cannot call its scoped snapshot, so a synthetic hash is sufficient there.
$snapshot=$case==='bad-scope'?['hash'=>str_repeat('a',64)]:$snapshotMethod->invoke(new Workflow(),7,0,$ceiling,false);
$cipher=Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
if ($case==='decrypt-failure') $cipher='PRIVATE_BROKEN_CIPHERTEXT';
if ($case==='oversized-ciphertext') $cipher=Crypt::encryptString(json_encode($data+['padding'=>str_repeat('PRIVATE_',20000)]));
if ($case==='oversized-plaintext') $cipher=Crypt::encryptString(json_encode($data+['padding'=>str_repeat('A',135000)]));
DB::table('whatsapp_order_drafts')->insert(['id'=>41,'conversation_id'=>$case==='draft-other-conversation'?8:7,
    'status'=>$case==='unsafe-status'?'PRIVATE_STATUS':'REVIEW','reason'=>$case==='unsafe-status'?'PRIVATE_REASON':'LOCATION_UNCONFIRMED',
    'ticket_id'=>$case==='has-ticket'?99:null,'evidence_floor'=>0,'evidence_ceiling'=>$ceiling,
    'evidence_hash'=>$case==='stale-hash'?str_repeat('b',64):$snapshot['hash'],'extraction'=>$cipher]);
if ($case==='newer-message') DB::table('whatsapp_inbox_messages')->insert(['id'=>4,'conversation_id'=>7,
    'message_key'=>str_repeat('f',64),'source'=>'messages','direction'=>'inbound','type'=>'text','sent_at'=>'2026-10-14 12:00:04','content'=>'PRIVATE_NEWER_CIPHERTEXT']);
if ($case==='bad-native-message') DB::table('whatsapp_inbox_messages')->where('id',3)->update(['content'=>'PRIVATE_BROKEN_NATIVE_CIPHERTEXT']);
DB::table('whatsapp_order_scans')->insert(['conversation_id'=>7,'attempts'=>3,'next_attempt_at'=>'2026-10-14 12:00:20','lease_until'=>null,
    'claimed_ceiling'=>$ceiling,'analyzed_ceiling'=>$ceiling,'auto_floor'=>0,'claim_nonce'=>null]);
if (str_starts_with($case,'retry-')) {
    $values=['attempts'=>2,'next_attempt_at'=>null];
    if ($case==='retry-exhausted') $values['attempts']=3;
    if ($case==='retry-backoff') $values['next_attempt_at']='2026-10-14 12:00:20';
    if ($case==='retry-live-lease'||$case==='retry-expired-lease') {
        $values['claim_nonce']=str_repeat('c',64); $values['lease_until']=$case==='retry-live-lease'?'2026-10-14 12:30:00':'2026-10-14 11:59:00';
    }
    if ($case==='retry-new-ceiling') { $values['attempts']=3; $values['claimed_ceiling']=2; }
    if ($case==='retry-rebaseline') { $values['attempts']=3; config()->set('whatsapp_orders.activation_message_id',1); }
    if ($case==='retry-rebaseline-backoff') {
        $values['attempts']=3; $values['next_attempt_at']='2026-10-14 12:00:20'; config()->set('whatsapp_orders.activation_message_id',1);
    }
    if ($case==='retry-invalid-attempts') $values['attempts']=-1;
    if ($case==='retry-invalid-ceiling') $values['claimed_ceiling']='PRIVATE_CEILING';
    if ($case==='retry-invalid-date') $values['next_attempt_at']='PRIVATE_BACKOFF';
    if ($case==='retry-invalid-lease') { $values['claim_nonce']=str_repeat('c',64); $values['lease_until']='PRIVATE_LEASE_TIME'; }
    if ($case==='retry-zero-nonce') { $values['claim_nonce']='0'; $values['lease_until']='2026-10-14 12:30:00'; }
    DB::table('whatsapp_order_scans')->where('conversation_id',7)->update($values);
}
if ($case==='missing-scan') DB::table('whatsapp_order_scans')->delete();
if ($case==='bad-activation') config()->set('whatsapp_cart.activated_at','2026-02-30 12:00:00');
if ($case==='reply-disabled') config()->set('whatsapp_replies.enabled',false);
if ($case==='window-closed') \Carbon\CarbonImmutable::setTestNow('2026-10-15 12:00:10');
if (in_array($case,['tombstone-accepted','tombstone-failed','tombstone-unknown','bad-marker','bad-audit','foreign-tombstone'],true)) {
    $latestKey=hash('sha256','whatsapp-message-v1:'.$scope.'wamid.PRIVATE_NATIVE_3');
    $episodeKey=hash('sha256','whatsapp-message-v1:'.$scope.'wamid.PRIVATE_NATIVE_1');
    $marker=['version'=>'whatsapp-order-followup-v1','kind'=>'question','fields'=>['LOCATION'],
        'latest_customer_id'=>3,'latest_customer_key'=>$latestKey,'episode_customer_key'=>$episodeKey,'proposal_hash'=>null];
    $uuid=Followup::requestUuid($marker+['conversation_id'=>7],Crypt::getKey());
    if ($case==='bad-marker') $marker['fields']=['LOCATION','LOCATION'];
    $audit=Crypt::encryptString(json_encode(['client_request_id'=>$uuid,'order_followup'=>$marker,
        'text'=>'PRIVATE_REQUEST_TEXT','address'=>'PRIVATE_ADDRESS','token'=>'PRIVATE_ACCESS_TOKEN_VALID']));
    if ($case==='bad-audit') $audit='PRIVATE_BROKEN_AUDIT';
    DB::table('whatsapp_reply_requests')->insert(['id'=>12,'actor_id'=>9,'conversation_id'=>7,
        'waba_id'=>$case==='foreign-tombstone'?'foreign':$waba,'phone_number_id'=>$phoneId,'kind'=>'text',
        'state'=>$case==='tombstone-unknown'?'UNKNOWN':($case==='tombstone-failed'?'FAILED':'ACCEPTED'),
        'inbound_message_id'=>3,'reason'=>$case==='tombstone-failed'?'TRANSPORT_ERROR':null,
        'request_hash'=>hash_hmac('sha256','whatsapp-reply-request-v1:9:7:'.$uuid,Crypt::getKey()),
        'created_at'=>'2026-10-14 12:00:05','completed_at'=>'2026-10-14 12:00:05','audit'=>$audit]);
}
foreach (['workflow'=>Workflow::class,'followup_service'=>Followup::class,'reply_service'=>\App\Services\Dashboard\WhatsAppReplyService::class] as $key=>$class) {
    if ($case==='wrong-binding-'.$key) $container->instance($class,new \stdClass());
    if ($case==='throwing-constructor-'.$key) $container->bind($class,static function () { throw new \RuntimeException('PRIVATE_CONSTRUCTOR_DETAILS'); });
}
DB::listen(static function ($query): void {
    file_put_contents(getcwd().'/queries.jsonl',json_encode($query->sql,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
});
return new class {
    public function make($name) {
        if ($name!==\Illuminate\Contracts\Console\Kernel::class) throw new \RuntimeException('UNEXPECTED_KERNEL_SERVICE');
        return new class { public function bootstrap(): void {} };
    }
};
}
PHP
);
function gatesRun(string $case,array $arguments=['--conversation','7','--draft','41']): array {
    global $fixture,$vendor,$root;
    $queryFile=$fixture.'/queries.jsonl'; if (is_file($queryFile)) unlink($queryFile);
    $command=[PHP_BINARY];
    if (is_string(php_ini_loaded_file())&&php_ini_scanned_files()===false) {
        $command[]='-n'; $command[]='-c'; $command[]=php_ini_loaded_file();
    }
    $env=array_merge(getenv(),['GATES_FIXTURE_VENDOR'=>$vendor,'GATES_FIXTURE_CASE'=>$case,'GATES_FIXTURE_ROOT'=>$root]);
    $child=proc_open(array_merge($command,[$fixture.'/gates.php'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture,$env);
    if (!is_resource($child)) throw new RuntimeException('GATES_CHILD_UNAVAILABLE');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($child);
    gatesExpect($stderr,'','helper never leaks exceptions to stderr');
    $data=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
    gatesExpect(strlen($stdout)<6500,true,'diagnostic output remains bounded');
    foreach (['PRIVATE_','201000000001','عميل تجريبي','شارع المثال','US.','wamid.','client_request_id','episode_customer_key','latest_customer_key','proposal_hash','evidence_hash','audit','extraction'] as $secret)
        gatesExpect(str_contains($stdout,$secret),false,'diagnostic suppresses customer data, credentials, ciphertext and raw stable identifiers');
    gatesExpect((bool)preg_match('/\b[a-f0-9]{64}\b|\b[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\b/i',$stdout),false,
        'diagnostic never discloses a raw hash or UUID');
    $queries=is_file($queryFile)?file($queryFile,FILE_IGNORE_NEW_LINES):[];
    foreach ($queries as $query) gatesExpect((bool)preg_match('/\ASELECT\b/i',json_decode($query,true,8,JSON_THROW_ON_ERROR)),true,'every actual-helper database query is SELECT only');
    return [$data,$exit,count($queries)];
}
function gatesReady(string $case): array {
    [$data,$exit,$queries]=gatesRun($case);
    gatesExpect([$data['status'],$exit],['READ_READY',0],$case.' completes the actual helper '.json_encode($data));
    gatesExpect($queries>0,true,$case.' observes real database reads');
    gatesExpect([$data['writes'],$data['ai_calls'],$data['planner_executed'],$data['candidate_kind'],$data['eligibility']],
        [0,0,false,'LOCATION_READ_ONLY_CANDIDATE','NOT_EVALUATED_BRANCH_LOCATION_CATALOG_DEPENDENCIES'],'read-only candidate cannot claim planner eligibility');
    return $data;
}
$ready=gatesReady('approval');
gatesExpect([$ready['snapshot_complete'],$ready['snapshot_current_at_read'],$ready['valid'],$ready['raw_decision'],$ready['normalized_checked_decision']],
    [true,true,true,'CONFIRMED','CONFIRMED'],'actual grounded native snapshot is complete, current and confirmed');
gatesExpect([$ready['fresh_intent'],$ready['fresh_automatic_evidence'],$ready['fulfillment'],$ready['latest_customer_id'],$ready['latest_native_type']],
    [true,true,'DELIVERY',3,'text'],'actual fresh intent/evidence and address-phone delivery policy');
gatesExpect([$ready['latest_customer_is_cited_request'],$ready['latest_customer_is_cited_acceptance'],$ready['latest_positive_approval'],$ready['latest_after_final_cited_business']],
    [false,true,true,true],'approval is accurately identified as the actual latest cited acceptance');
gatesExpect([$ready['latest_text_length'],$ready['latest_text_word_count'],$ready['approval_keyword_present'],$ready['question_or_quote_present'],
    $ready['change_or_cancel_keyword_present'],$ready['turn_hint_scope']],
    [10,2,true,false,false,'DIAGNOSTIC_ONLY_NOT_HANDOFF_AUTHORITY'],'positive-turn hints are bounded metadata and declare their diagnostic-only scope');
gatesExpect([$ready['candidate_plan_available'],$ready['valid_plan'],$ready['confirmed_customer_handoff'],$ready['handoff_allowed'],
    $ready['unasked_fields'],$ready['render_ready'],$ready['valid_marker']],
    [true,true,true,true,['LOCATION'],true,true],'actual candidate gates accept the proved customer approval');
gatesExpect([$ready['media_question_handoff'],$ready['latest_image_authenticated'],$ready['latest_image_caption_status'],
    $ready['latest_image_caption_length'],$ready['latest_image_reference_valid']],
    [false,'NOT_APPLICABLE','NOT_APPLICABLE',null,'NOT_APPLICABLE'],'text approval cannot become a media handoff or native image');
gatesExpect($ready['post_final_customer_counts'],['total'=>1,'null_text'=>0,'whole_positive_cited'=>1],
    'actual episode counts only the later cited whole positive customer approval');
gatesExpect([$ready['automatic_actor_available'],$ready['sender_state']['can_reply'],$ready['sender_state']['reason'],
    $ready['sender_state']['latest_inbound_id'],$ready['tombstone_state'],$ready['tombstone_count']],
    [true,true,null,3,'NONE',0],'actual actor and sender window are available with no prior receipt');
foreach (['workflow','followup_service','reply_service'] as $key)
    gatesExpect($ready['runtime_bindings'][$key],['constructor_ready'=>true,'runtime_class_matches'=>true],'real container resolves the guarded '.$key.' class');
gatesExpect([$ready['scan']['present'],$ready['scan']['attempts'],$ready['scan']['claimed_ceiling'],$ready['scan']['analyzed_ceiling'],
    $ready['scan']['actual_message_ceiling'],$ready['scan']['claim_nonce_present'],$ready['scan']['live_lease'],$ready['scan']['next_attempt_at'],
    $ready['scan']['lease_until'],$ready['scan']['retry_limit_allows'],$ready['scan']['backoff_due'],$ready['scan']['retry_allowed'],$ready['scan']['retry_scope']],
    [true,3,3,3,3,false,false,'2026-10-14 12:00:20',null,false,false,false,'COUNT_CEILING_BACKOFF_LEASE_ONLY'],
    'actual scan is exhausted and in backoff without being awakened');
$location=gatesReady('location-text');
gatesExpect([$location['snapshot_complete'],$location['snapshot_current_at_read'],$location['valid'],$location['raw_decision'],$location['normalized_checked_decision']],
    [true,true,true,'CONFIRMED','CONFIRMED'],'location text preserves the existing grounded confirmed snapshot');
gatesExpect([$location['latest_positive_approval'],$location['candidate_plan_available'],$location['valid_plan'],$location['confirmed_customer_handoff'],$location['handoff_allowed']],
    [false,true,true,false,false],'mere location text is not a positive handoff approval');
foreach (['hint-quoted-approval'=>[true,true,false], 'hint-negative-approval'=>[true,false,true],
    'hint-detail-ok'=>[true,false,false], 'hint-courtesy-approval'=>[true,false,false]] as $case=>$hints) {
    $turn=gatesReady($case);
    gatesExpect([$turn['approval_keyword_present'],$turn['question_or_quote_present'],$turn['change_or_cancel_keyword_present']],
        $hints,'diagnostic-only turn flags accurately reflect '.$case);
    gatesExpect($turn['latest_positive_approval'],false,'keyword occurrence does not widen the actual anchored positiveApproval for '.$case);
    gatesExpect($turn['confirmed_customer_handoff']===true,false,'diagnostic hints never authorize confirmedCustomerHandoff for '.$case);
    gatesExpect($turn['turn_hint_scope'],'DIAGNOSTIC_ONLY_NOT_HANDOFF_AUTHORITY','turn hint scope remains explicit for '.$case);
}
$boundedTurn=gatesReady('hint-bounded-counts');
gatesExpect([$boundedTurn['latest_text_length'],$boundedTurn['latest_text_word_count'],$boundedTurn['snapshot_complete'],$boundedTurn['candidate_plan_available']],
    [16000,8000,false,false],'oversized turn metadata stays bounded and cannot form a candidate');
$late=gatesReady('delayed-data');
gatesExpect([$late['snapshot_complete'],$late['snapshot_current_at_read'],$late['valid'],$late['raw_decision'],$late['normalized_checked_decision']],
    [true,true,true,'CONFIRMED','CONFIRMED'],'delayed contact details preserve a valid historical confirmed snapshot '.json_encode($late));
gatesExpect([$late['fresh_intent'],$late['fresh_automatic_evidence'],$late['candidate_plan_available']],[false,false,false],
    'post-activation contact data cannot make an old commitment fresh');
foreach (['valid_plan','confirmed_customer_handoff','media_question_handoff','handoff_allowed','unasked_fields','render_ready','valid_marker','tombstone_state'] as $field)
    gatesExpect($late[$field],'NOT_APPLICABLE','intent-null leaves downstream '.$field.' explicitly unevaluated');
gatesExpect($late['tombstone_count'],null,'intent-null does not invent a tombstone query result');
gatesExpect($late['latest_customer_fresh'],true,'a fresh latest contact row alone is accurately distinguished from fresh purchase intent');
$normalized=gatesReady('normalized-none');
gatesExpect([$normalized['raw_decision'],$normalized['normalized_checked_decision']],['CONFIRMED','NONE'],
    'raw extraction decision remains distinct from the actual validator-normalized decision');
gatesExpect([$normalized['fresh_intent'],$normalized['fresh_automatic_evidence'],$normalized['candidate_plan_available']],[false,false,false],
    'raw confirmed data cannot bypass failed actual commitment normalization');
$asked=gatesReady('already-asked');
gatesExpect([$asked['candidate_plan_available'],$asked['handoff_allowed'],$asked['unasked_fields']],[true,true,[]],
    'actual unaskedFields suppresses the external already-asked LOCATION question');
$pin=gatesReady('native-location');
gatesExpect([$pin['native_pins_count'],$pin['latest_customer_id'],$pin['latest_native_type'],$pin['latest_text_present'],$pin['latest_positive_approval']],
    [1,4,'location',false,false],'native WhatsApp location metadata is accurately observed without exposing coordinates');
gatesExpect($pin['native_pin_cited'],false,'actual native pin and cached model citation remain distinct');
$citedPin=gatesReady('native-location-cited'); gatesExpect([$citedPin['native_pins_count'],$citedPin['native_pin_cited']],[1,true],'actual native location citation is accurately read');
foreach (['media-image','media-direct-image','media-image-empty-caption'] as $case) {
    $media=gatesReady($case);
    gatesExpect([$media['snapshot_complete'],$media['snapshot_current_at_read'],$media['valid'],$media['latest_native_type'],
        $media['latest_text_present'],$media['latest_positive_approval'],$media['confirmed_customer_handoff'],$media['media_question_handoff'],$media['handoff_allowed']],
        [true,true,true,'image',false,false,false,true,true],'actual native captionless image permits only missing-data handoff for '.$case);
    gatesExpect([$media['latest_image_authenticated'],$media['latest_image_caption_status'],$media['latest_image_caption_length'],$media['latest_image_reference_valid']],
        [true,$case==='media-image-empty-caption'?'EMPTY':'ABSENT',$case==='media-image-empty-caption'?0:null,true],
        'actual raw image authenticity and caption metadata are accurate for '.$case);
    gatesExpect([$media['native_pins_count'],$media['native_pin_cited'],$media['ticket_present'],$media['unasked_fields']],
        [0,false,false,['LOCATION']],'native image supplies no pin, customer approval or ERP dispatch');
    gatesExpect($media['post_final_customer_counts'],$case==='media-direct-image'
        ?['total'=>1,'null_text'=>1,'whole_positive_cited'=>0]:['total'=>2,'null_text'=>1,'whole_positive_cited'=>1],
        'bounded post-final counts distinguish direct media from separately cited prior approval');
}
foreach (['media-image-caption'=>['TEXT',29], 'media-image-null-caption'=>['NULL',null],
    'media-image-oversized-caption'=>['TEXT',16000], 'media-second-image'=>['ABSENT',null]] as $case=>$caption) {
    $media=gatesReady($case);
    gatesExpect([$media['latest_image_authenticated'],$media['latest_image_caption_status'],$media['latest_image_caption_length'],$media['latest_image_reference_valid']],
        [true,$caption[0],$caption[1],true],'bounded raw authenticated image caption diagnostics observe '.$case);
    gatesExpect([$media['latest_text_present'],$media['confirmed_customer_handoff'],$media['media_question_handoff'],$media['handoff_allowed']],
        [false,false,false,false],'raw caption or additional image cannot authorize missing-data handoff for '.$case);
    gatesExpect($media['post_final_customer_counts'],$case==='media-second-image'
        ?['total'=>3,'null_text'=>2,'whole_positive_cited'=>1]:['total'=>2,'null_text'=>1,'whole_positive_cited'=>1],
        'diagnostic counts show extra media without fabricating text or customer approval');
}
$badReference=gatesReady('media-image-bad-reference');
gatesExpect([$badReference['latest_image_authenticated'],$badReference['latest_image_reference_valid'],$badReference['media_question_handoff'],$badReference['handoff_allowed']],
    [true,false,false,false],'authenticated native envelope still requires a valid bounded image media reference');
foreach (['media-image-bad-peer','media-image-invalid-caption'] as $case) {
    $badImage=gatesReady($case);
    gatesExpect([$badImage['latest_image_authenticated'],$badImage['latest_image_caption_status'],$badImage['latest_image_caption_length'],
        $badImage['latest_image_reference_valid'],$badImage['media_question_handoff'],$badImage['handoff_allowed']],
        [false,'UNAUTHENTICATED',null,false,false,false],'unauthenticated native raw image cannot be handoff evidence or disclose caption metadata for '.$case);
}
$branch=gatesReady('branch-mode'); gatesExpect($branch['fulfillment'],'PICKUP','explicit in-branch choice overrides address and phone');
foreach (['tombstone-accepted'=>'ACCEPTED','tombstone-failed'=>'FAILED','tombstone-unknown'=>'UNKNOWN'] as $case=>$state) {
    $receipt=gatesReady($case); gatesExpect([$receipt['tombstone_state'],$receipt['tombstone_count']],[$state,1],'actual authenticated '.$state.' tombstone is accurate');
    if ($state==='UNKNOWN') gatesExpect([$receipt['sender_state']['can_reply'],$receipt['sender_state']['reason']],[false,'UNKNOWN_PENDING'],'unresolved prior send blocks sender');
}
$foreign=gatesReady('foreign-tombstone'); gatesExpect([$foreign['tombstone_state'],$foreign['tombstone_count']],['NONE',0],'foreign WABA receipt is excluded by actual scoped reads');
$disabled=gatesReady('reply-disabled'); gatesExpect([$disabled['sender_state']['can_reply'],$disabled['sender_state']['reason']],[false,'REPLIES_UNAVAILABLE'],'disabled replies are reported without enabling them');
$closed=gatesReady('window-closed'); gatesExpect([$closed['sender_state']['can_reply'],$closed['sender_state']['reason']],[false,'WINDOW_CLOSED'],'actual 24-hour sender window remains enforced');
$actor=gatesReady('actor-unavailable'); gatesExpect([$actor['automatic_actor_available'],$actor['sender_state']['reason'],$actor['tombstone_state']],[false,'NOT_APPLICABLE','NOT_APPLICABLE'],'revoked automatic actor leaves sender and receipt unevaluated');
$activation=gatesReady('bad-activation'); gatesExpect([$activation['activation_valid'],$activation['fresh_intent'],$activation['candidate_plan_available']],[false,false,false],'impossible activation timestamp cannot create a candidate');
foreach (['stale-hash','newer-message'] as $case) {
    $stale=gatesReady($case); gatesExpect($stale['snapshot_current_at_read'],false,'source snapshot drift is accurately reported for '.$case);
    gatesExpect($stale['eligibility'],'NOT_EVALUATED_BRANCH_LOCATION_CATALOG_DEPENDENCIES','stale diagnostic does not claim dispatch eligibility');
}
$incomplete=gatesReady('incomplete-snapshot'); gatesExpect([$incomplete['snapshot_complete'],$incomplete['candidate_plan_available']],[false,false],'over-limit native transcript cannot form a candidate');
$unsafe=gatesReady('unsafe-status'); gatesExpect([$unsafe['draft_status'],$unsafe['draft_reason']],['UNKNOWN','UNKNOWN'],'arbitrary private draft status/reason are suppressed');
foreach (['retry-ready'=>[true,true,false,true], 'retry-exhausted'=>[false,true,false,false],
    'retry-backoff'=>[true,false,false,false], 'retry-live-lease'=>[true,true,true,false],
    'retry-expired-lease'=>[true,true,false,true], 'retry-new-ceiling'=>[true,true,false,true],
    'retry-rebaseline'=>[true,true,false,true], 'retry-rebaseline-backoff'=>[true,false,false,true]] as $case=>$expected) {
    $retry=gatesReady($case);
    gatesExpect([$retry['scan']['retry_limit_allows'],$retry['scan']['backoff_due'],$retry['scan']['live_lease'],$retry['scan']['retry_allowed']],
        $expected,'bounded read-only retry metadata reflects '.$case);
    if ($case==='retry-new-ceiling') gatesExpect($retry['scan']['ceiling_changed'],true,'new actual inbox ceiling permits an ordinary retry');
    if ($case==='retry-rebaseline'||$case==='retry-rebaseline-backoff')
        gatesExpect($retry['scan']['activation_rebaseline'],true,'activation floor rebaseline is visible without applying it');
}
foreach (['retry-invalid-attempts','retry-invalid-ceiling'] as $case) {
    $invalidScan=gatesReady($case);
    gatesExpect([$invalidScan['scan']['scan_values_valid'],$invalidScan['scan']['retry_limit_allows'],$invalidScan['scan']['retry_allowed']],
        [false,false,false],'invalid private scan values cannot claim retry eligibility');
}
$invalidDate=gatesReady('retry-invalid-date'); gatesExpect([$invalidDate['scan']['next_attempt_at'],$invalidDate['scan']['scan_values_valid'],
    $invalidDate['scan']['backoff_due'],$invalidDate['scan']['retry_allowed']],
    [null,false,false,false],'invalid private backoff timestamp is suppressed and blocks retry');
$invalidLease=gatesReady('retry-invalid-lease'); gatesExpect([$invalidLease['scan']['lease_until'],$invalidLease['scan']['scan_values_valid'],
    $invalidLease['scan']['live_lease'],$invalidLease['scan']['retry_allowed']],
    [null,false,false,false],'invalid private lease timestamp cannot be treated as an expired holder');
$zeroNonce=gatesReady('retry-zero-nonce'); gatesExpect([$zeroNonce['scan']['claim_nonce_present'],$zeroNonce['scan']['live_lease'],$zeroNonce['scan']['retry_allowed']],
    [false,false,true],'zero nonce follows the actual ordinary-worker empty-holder semantics');
$missingScan=gatesReady('missing-scan'); gatesExpect([$missingScan['scan']['present'],$missingScan['scan']['activation_rebaseline'],$missingScan['scan']['retry_allowed']],
    [false,true,true],'absent scan is visible as an ordinary new baseline candidate');
$invalid=gatesReady('invalid-extraction');
gatesExpect([$invalid['valid'],$invalid['raw_decision'],$invalid['normalized_checked_decision'],$invalid['candidate_plan_available']],
    [false,'CONFIRMED','UNKNOWN',false],'ungrounded literal fails actual extraction validation without disclosing it');
foreach (['workflow','followup_service','reply_service'] as $key) foreach (['wrong-binding','throwing-constructor'] as $failure) {
    [$failed,$exit,$queries]=gatesRun($failure.'-'.$key);
    // The actual container resolves ReplyService as a typed FollowupService
    // constructor dependency, so a bad reply binding surfaces at that stage.
    $failedKey=$key==='reply_service'?'followup_service':$key;
    gatesExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','RUNTIME_'.strtoupper($failedKey),1,0],
        $failure.' '.$key.' fails before database reads');
    gatesExpect($failed['runtime_bindings'][$failedKey],['constructor_ready'=>$failure==='wrong-binding'&&$key!=='reply_service','runtime_class_matches'=>false],
        'runtime flags distinguish a constructor failure from an unexpected container binding');
}
foreach (['bad-owner'=>'OWNER','bad-scope'=>'DRAFT','draft-other-conversation'=>'DRAFT','decrypt-failure'=>'DRAFT_DECODE','bad-native-message'=>'SNAPSHOT',
    'wrong-loaded-source'=>'BOOTSTRAP',
    'oversized-ciphertext'=>'DRAFT','oversized-plaintext'=>'DRAFT_DECODE','bad-marker'=>'TOMBSTONE','bad-audit'=>'TOMBSTONE'] as $case=>$stage) {
    [$failed,$exit,$queries]=gatesRun($case);
    gatesExpect([$failed['status'],$failed['stage'],$exit],['READ_FAILED',$stage,1],$case.' fails closed with only a fixed stage '.json_encode($failed));
    if ($case==='bad-owner') gatesExpect($queries,0,'wrong owner cannot bootstrap or query');
}
foreach ([[],['--conversation','7'],['--conversation','0','--draft','41'],['--draft','41','--conversation','7'],
    ['--conversation','7','--draft','041'],['--conversation','7','--draft','10000000000'],['--conversation','7','--draft','41','extra']] as $arguments) {
    [$failed,$exit,$queries]=gatesRun('approval',$arguments);
    gatesExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','ARGUMENTS',1,0],'bad CLI arguments fail before bootstrap or reads');
}
foreach (['WhatsAppOrderAiProvider','WhatsAppReplyService','WhatsAppOrderWorkflow','WhatsAppOrderFollowupService','WhatsAppInboxAccess','WhatsAppInboxMessage'] as $name) {
    $path=$fixture.'/app/Services/Dashboard/'.$name.'.php'; file_put_contents($path,$files[$name]."\n");
    [$failed,$exit,$queries]=gatesRun('approval');
    gatesExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',1,0],'drift in '.$name.' refuses before bootstrap');
    file_put_contents($path,$files[$name]);
}
$path=$fixture.'/app/Services/Dashboard/WhatsAppOrderAiProvider.php'; chmod($path,0666);
[$failed,$exit,$queries]=gatesRun('approval');
gatesExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',1,0],'writable guarded source refuses before bootstrap'); chmod($path,0600);
$linkedSource=$fixture.'/linked-provider.php'; file_put_contents($linkedSource,$files['WhatsAppOrderAiProvider']); chmod($linkedSource,0600);
unlink($path); symlink($linkedSource,$path);
[$failed,$exit,$queries]=gatesRun('approval');
gatesExpect([$failed['status'],$failed['stage'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',1,0],'symlinked guarded source refuses before bootstrap');
unlink($path); file_put_contents($path,$files['WhatsAppOrderAiProvider']); chmod($path,0600);
echo 'WHATSAPP_ORDER_FOLLOWUP_GATES_TESTS='.$checks." PASS\n";
