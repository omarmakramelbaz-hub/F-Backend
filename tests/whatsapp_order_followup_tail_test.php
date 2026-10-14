<?php
// Execute the actual read-only tail observer against native encrypted DTOs and
// real Laravel 8 SQLite/Crypt. Only fixed host paths and owner are seams.
$root=getenv('WA_TAIL_TEST_ROOT')?:dirname(__DIR__);
$helper=getenv('WA_TAIL_TEST_HELPER')?:$root.'/deployment/whatsapp_order_followup_tail.php';
$vendor=getenv('WA_INBOX_TEST_VENDOR'); if (is_string($vendor)&&is_dir($vendor)) $vendor.='/autoload.php';
if (!is_string($vendor)||!is_file($vendor)) throw new RuntimeException('TAIL_TEST_VENDOR_REQUIRED');
$fixture=sys_get_temp_dir().'/wa-order-tail-test-'.bin2hex(random_bytes(8)); mkdir($fixture,0700);
register_shutdown_function(static function () use ($fixture): void {
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($fixture);
});
$checks=0;
function tailExpect($actual,$expected,string $label): void {
    $GLOBALS['checks']++; if ($actual!==$expected) throw new RuntimeException('TAIL_TEST_FAILED: '.$label);
}
function tailReplace(string $search,string $replacement,string &$source,string $label): void {
    $source=str_replace($search,$replacement,$source,$count); tailExpect($count,1,$label);
}
$source=file_get_contents($helper);
$classes=['App\\Services\\Dashboard\\WhatsAppOrderAiProvider','App\\Services\\Dashboard\\WhatsAppReplyService',
    'App\\Services\\Dashboard\\WhatsAppOrderWorkflow','App\\Services\\Dashboard\\WhatsAppOrderFollowupService',
    'App\\Services\\Dashboard\\WhatsAppInboxAccess','App\\Services\\Dashboard\\WhatsAppInboxMessage',
    'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence','App\\Support\\WhatsAppOrderExtraction'];
$files=[];
foreach ($classes as $class) {
    $rel=str_replace('\\','/',lcfirst($class)).'.php'; $path=$fixture.'/'.$rel;
    if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
    // This pre-install diagnostic must retain its deployed dependency pins while
    // the application implementation in the current checkout evolves.
    $sourcePath=$class==='App\\Services\\Dashboard\\WhatsAppOrderFollowupService'
        ?$root.'/tests/fixtures/whatsapp_order_pre_tail_handoff/'.$rel:$root.'/'.$rel;
    $files[$rel]=file_get_contents($sourcePath);
    tailExpect(is_string($files[$rel]),true,'exact deployed guarded fixture source is available');
    file_put_contents($path,$files[$rel]); chmod($path,0600);
    $literal=var_export($class,true);
    tailExpect(preg_match('/'.preg_quote($literal,'/')."=>'([a-f0-9]{40})'/",$source,$pin),1,'one actual helper source pin per staged class');
    tailExpect($pin[1],sha1('blob '.strlen($files[$rel])."\0".$files[$rel]),'observer pin exactly matches deployed fixture bytes without replacement');
}
tailReplace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('TAIL_FIXTURE_CASE')==='bad-owner'?1:0)]",$source,'only fixed host owner lookup is substituted');
tailReplace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,'only fixed deployment directory is substituted');
tailExpect((bool)preg_match('/(?:->|::)(?:request|extract|process|consume|projectPending|dispatch|send|sendOrderFollowup|quote|save|insert|update|delete|automaticFollowupPlan|missingInformationPlan|current)\s*\(/',$source),false,
    'observer invokes no actions, mutations or automatic planner');
file_put_contents($fixture.'/observer.php',$source);
mkdir($fixture.'/vendor',0700); mkdir($fixture.'/bootstrap',0700);
file_put_contents($fixture.'/vendor/autoload.php',"<?php require getenv('TAIL_FIXTURE_VENDOR');\n");
// Self-contained native SQLite/Crypt fixture. It does not depend on another
// test's mutable scaffold; every actual observer query is recorded below.
$bootstrap= <<<'WA_TAIL_NATIVE_FIXTURE'
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
$staged=['WhatsAppOrderAiProvider','WhatsAppReplyService','WhatsAppOrderWorkflow','WhatsAppOrderFollowupService','WhatsAppInboxAccess','WhatsAppInboxMessage','WhatsAppOrderConversationEvidence','WhatsAppOrderExtraction'];
$readDependencies=['App\\Support\\WhatsAppInboxProtocol','App\\Support\\WhatsAppOrderExtraction',
    'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence','App\\Services\\Dashboard\\WhatsAppOrderSourceSelection',
    'App\\Services\\Dashboard\\WhatsAppInboxMedia',
    'App\\Services\\Dashboard\\WhatsAppVoiceMedia','App\\Services\\Dashboard\\PosServiceTicket',
    'App\\Services\\Dashboard\\WhatsAppAcceptedReplyEvidence'];
spl_autoload_register(static function ($class) use ($staged,$readDependencies): void {
    if (!str_starts_with($class,'App\\')) return;
    $name=substr($class,strrpos($class,'\\')+1);
    if (in_array($name,$staged,true)&&in_array($class,array_merge(array_map(static fn($n)=>'App\\Services\\Dashboard\\'.$n,$staged),['App\\Support\\WhatsAppOrderExtraction']),true)) {
        if (getenv('TAIL_FIXTURE_CASE')==='wrong-loaded-source'&&$name==='WhatsAppOrderAiProvider') {
            require getenv('TAIL_FIXTURE_ROOT').'/app/Services/Dashboard/'.$name.'.php'; return;
        }
        require getcwd().'/'.str_replace('\\','/',lcfirst($class)).'.php'; return;
    }
    if (!in_array($class,$readDependencies,true)) throw new \RuntimeException('UNEXPECTED_DOMAIN_SERVICE_LOAD');
    require getenv('TAIL_FIXTURE_ROOT').'/'.str_replace('\\','/',lcfirst($class)).'.php';
});
Schema::create('users',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->boolean('active'); $t->string('account_type'); $t->integer('owner_resturant_id')->nullable(); $t->boolean('can_checkout');
});
Schema::create('whatsapp_inbox_conversations',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->string('waba_id'); $t->string('phone_number_id'); $t->string('peer_hash'); $t->text('private_customer'); $t->text('customer')->nullable();
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
    $t->string('payload_hash')->nullable(); $t->string('remote_message_key')->nullable(); $t->text('response_details')->nullable();
    $t->string('claimed_at')->nullable(); $t->string('send_started_at')->nullable();
});
Schema::create('whatsapp_reply_projection_keys',static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('reply_id'); $t->integer('conversation_id'); $t->string('waba_id'); $t->string('phone_number_id');
    $t->string('kind'); $t->boolean('valid'); $t->string('message_key'); $t->string('receipt_hash');
});
$case=getenv('TAIL_FIXTURE_CASE'); $waba=Access::WABA_ID; $phoneId=Access::PHONE_ID;
$scope=$waba.':'.$phoneId.':'; $peerPhone='201000000001'; $peerUser='US.PRIVATE_FIXTURE'; $peerIdentity='user:'.$peerUser;
DB::table('users')->insert(['id'=>9,'active'=>$case!=='actor-unavailable','account_type'=>'admin','owner_resturant_id'=>null,'can_checkout'=>true]);
DB::table('whatsapp_inbox_conversations')->insert(['id'=>7,'waba_id'=>$case==='bad-scope'?'foreign':$waba,'phone_number_id'=>$phoneId,
    'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$peerIdentity,Crypt::getKey()),'private_customer'=>'PRIVATE_ADDRESS PRIVATE_CUSTOMER',
    'customer'=>Crypt::encryptString(json_encode(['phone'=>$peerPhone,'user_id'=>$peerUser],JSON_THROW_ON_ERROR))]);
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
if ($case==='ordinary-mixed-media'||$case==='ordinary-mixed-then-positive') {
    $rows[2]['text']='تمام يا باشا';
    foreach ([4=>'audio',5=>'video',6=>'document'] as $id=>$kind) $rows[]=['id'=>$id,'direction'=>'inbound','text'=>null,
        $kind=>['id'=>'PRIVATE_MEDIA_REFERENCE_'.$id,'mime_type'=>'application/octet-stream']];
    if ($case==='ordinary-mixed-then-positive') $rows[]=['id'=>7,'direction'=>'inbound','text'=>'تمام موافق'];
}
if ($case==='ordinary-text') $rows[2]['text']='العنوان شارع المثال';
if (str_starts_with($case,'own-recap-')&&$case!=='own-recap-positive') {
    if (str_contains($case,'media')) {
        if (str_contains($case,'before')) {
            $rows[2]=['id'=>3,'direction'=>'inbound','text'=>null,'image'=>['id'=>'PRIVATE_OWN_RECAP_MEDIA','mime_type'=>'image/jpeg']];
            $rows[]=['id'=>4,'direction'=>'inbound','text'=>'تمام موافق'];
        } else $rows[]=['id'=>4,'direction'=>'inbound','text'=>null,'image'=>['id'=>'PRIVATE_OWN_RECAP_MEDIA','mime_type'=>'image/jpeg']];
    } elseif (str_contains($case,'before')) {
        $rows[2]['text']='العنوان شارع المثال'; $rows[]=['id'=>4,'direction'=>'inbound','text'=>'تمام موافق'];
    } else $rows[]=['id'=>4,'direction'=>'inbound','text'=>'العنوان شارع المثال'];
}
if (str_starts_with($case,'media-')) {
    if ($case==='media-direct-image') unset($rows[2]);
    $rows[]=['id'=>$case==='media-direct-image'?3:4,'direction'=>'inbound','text'=>null,
        'image'=>['id'=>'PRIVATE_IMAGE_REFERENCE','mime_type'=>'image/jpeg']];
    $last=array_key_last($rows);
    if ($case==='media-image-caption') $rows[$last]['image']['caption']='PRIVATE_CAPTION تغيّر العنوان';
    if ($case==='media-image-safe-caption') $rows[$last]['image']['caption']='PRIVATE_CAPTION صورة توضيحية';
    if ($case==='media-image-whitespace-caption') $rows[$last]['image']['caption']='   ';
    if ($case==='media-image-refusal-caption') $rows[$last]['image']['caption']='لا';
    if ($case==='media-image-probe-caption') $rows[$last]['image']['caption']='WA-2026';
    if ($case==='media-image-empty-caption') $rows[$last]['image']['caption']='';
    if ($case==='media-image-null-caption') $rows[$last]['image']['caption']=null;
    if ($case==='media-image-invalid-caption') $rows[$last]['image']['caption']=12345;
    if ($case==='media-image-oversized-caption') $rows[$last]['image']['caption']=str_repeat('ن',17000);
    if ($case==='media-image-bad-reference') $rows[$last]['image']['id']='PRIVATE IMAGE REFERENCE';
    if ($case==='media-image-bad-peer') $rows[$last]['image_bad_peer']=true;
    if ($case==='media-image-bad-timestamp') $rows[$last]['image_bad_timestamp']=true;
    if ($case==='media-image-type-mismatch') $rows[$last]['image_type_mismatch']=true;
    if ($case==='media-refusal-before-approval') {
        $rows[$last]['image']['caption']='لا'; $rows[]=['id'=>5,'direction'=>'inbound','text'=>'تمام موافق'];
    }
    if ($case==='media-second-image') $rows[]=['id'=>5,'direction'=>'inbound','text'=>null,
        'image'=>['id'=>'PRIVATE_SECOND_IMAGE_REFERENCE','mime_type'=>'image/jpeg']];
}
if ($case==='incomplete-snapshot') for ($id=4;$id<=61;$id++) $rows[]=['id'=>$id,'direction'=>'inbound','text'=>'بيانات إضافية'];
if ($case!=='incomplete-snapshot') {
    $rows[2]['text']='اسم عميل تجريبي ورقم 201000000001 والعنوان شارع المثال في المنصورة و فسيخ';
    $rows[]=['id'=>4,'direction'=>'inbound','text'=>null,'image'=>['id'=>'PRIVATE_MEDIA_ONE','mime_type'=>'image/jpeg']];
    $rows[]=['id'=>5,'direction'=>'inbound','text'=>null,'image'=>['id'=>'PRIVATE_MEDIA_TWO','mime_type'=>'image/jpeg']];
    $rows[]=['id'=>6,'direction'=>'inbound','text'=>null,'image'=>['id'=>'PRIVATE_MEDIA_THREE','mime_type'=>'image/jpeg']];
    if ($case==='tail-mixed-media') foreach ([3=>'audio',4=>'video',5=>'document'] as $index=>$kind) {
        $rows[$index][$kind]=$rows[$index]['image']; unset($rows[$index]['image']);
    }
}
foreach ($rows as $row) {
    $sent=$case==='delayed-data'&&$row['id']<3?'2026-10-14 10:59:0'.$row['id']:'2026-10-14 12:00:'.str_pad((string)min($row['id'],9),2,'0',STR_PAD_LEFT);
    if ($case==='tail-same-timestamp'&&$row['id']>=3) $sent='2026-10-14 12:00:03';
    $type='text'; foreach (['location','image','audio','video','document'] as $kind) if (isset($row[$kind])) { $type=$kind; break; }
    $native='wamid.PRIVATE_NATIVE_'.$row['id'];
    $raw=['id'=>$native,$row['direction']==='inbound'?'from':'to'=>$peerPhone,
        $row['direction']==='inbound'?'from_user_id':'to_user_id'=>$peerUser,'timestamp'=>(string)strtotime($sent.' UTC'),'type'=>$type];
    if ($type==='location') $raw['location']=$row['location'];
    elseif (in_array($type,['image','audio','video','document'],true)) $raw[$type]=$row[$type];
    else $raw['text']=['body'=>$row['text']];
    if (($row['image_bad_peer']??false)===true) $raw['from_user_id']='US.PRIVATE_FOREIGN_IMAGE_PEER';
    if (($row['image_bad_timestamp']??false)===true) $raw['timestamp']='0';
    if (($row['image_type_mismatch']??false)===true) $raw['type']='audio';
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
if ($case==='ordinary-mixed-then-positive') $data['evidence']['customer_acceptance_ids']=['m3','m7'];
if ($case==='media-refusal-before-approval') $data['evidence']['customer_acceptance_ids']=['m3','m5'];
if (str_starts_with($case,'own-recap-')&&str_contains($case,'before')) $data['evidence']['customer_acceptance_ids']=['m4'];
$ceiling=count($rows);
$snapshotMethod=new \ReflectionMethod(Workflow::class,'snapshot'); $snapshotMethod->setAccessible(true);
// Hash the actual native snapshot before the read-observation listener begins.
// A foreign scope fixture cannot call its scoped snapshot, so a synthetic hash is sufficient there.
$snapshot=$case==='bad-scope'?['hash'=>str_repeat('a',64)]:$snapshotMethod->invoke(new Workflow(),7,0,$ceiling,false);
if (str_starts_with($case,'own-recap-')) {
    $proposalMethod=new \ReflectionMethod(Followup::class,'proposalHash'); $proposalMethod->setAccessible(true);
    $marker=['version'=>'whatsapp-order-followup-v1','kind'=>'confirmation','fields'=>['CONFIRMATION'],
        'latest_customer_id'=>1,'latest_customer_key'=>hash('sha256','whatsapp-message-v1:'.$scope.'wamid.PRIVATE_NATIVE_1'),
        'episode_customer_key'=>hash('sha256','whatsapp-message-v1:'.$scope.'wamid.PRIVATE_NATIVE_1'),
        'proposal_hash'=>$proposalMethod->invoke(null,$data,$snapshot['transcript'])];
    $uuid=Followup::requestUuid($marker+['conversation_id'=>7],Crypt::getKey()); $text=$rows[1]['text'];
    $nativeId='wamid.PRIVATE_NATIVE_2';
    $response=Crypt::encryptString(json_encode(['message_id'=>$nativeId],JSON_THROW_ON_ERROR));
    $remote=hash('sha256','whatsapp-reply-message-v1:'.$scope.$nativeId);
    DB::table('whatsapp_reply_requests')->insert(['id'=>30,'actor_id'=>9,'conversation_id'=>7,'waba_id'=>$waba,'phone_number_id'=>$phoneId,
        'kind'=>'text','state'=>'ACCEPTED','inbound_message_id'=>1,'reason'=>null,
        'request_hash'=>hash_hmac('sha256','whatsapp-reply-request-v1:9:7:'.$uuid,Crypt::getKey()),
        'payload_hash'=>hash_hmac('sha256','whatsapp-reply-payload-v1:'.json_encode(['text',1,$text,null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey()),
        'remote_message_key'=>$remote,'response_details'=>$response,'created_at'=>'2026-10-14 12:00:01','claimed_at'=>'2026-10-14 12:00:01',
        'send_started_at'=>'2026-10-14 12:00:02','completed_at'=>'2026-10-14 12:00:02',
        'audit'=>Crypt::encryptString(json_encode(['client_request_id'=>$uuid,'order_followup'=>$marker,'recipient'=>$peerPhone,'text'=>$text],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
    DB::table('whatsapp_reply_projection_keys')->insert(['id'=>30,'reply_id'=>30,'conversation_id'=>7,'waba_id'=>$waba,'phone_number_id'=>$phoneId,
        'kind'=>'text','valid'=>true,'message_key'=>hash('sha256','whatsapp-message-v1:'.$scope.$nativeId),
        'receipt_hash'=>hash('sha256',$response.':'.$remote.':7')]);
}
if ($case==='approval-missing-index') Schema::drop('whatsapp_reply_projection_keys');
$cipher=Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
if ($case==='decrypt-failure') $cipher='PRIVATE_BROKEN_CIPHERTEXT';
if ($case==='oversized-ciphertext') $cipher=Crypt::encryptString(json_encode($data+['padding'=>str_repeat('PRIVATE_',20000)]));
if ($case==='oversized-plaintext') $cipher=Crypt::encryptString(json_encode($data+['padding'=>str_repeat('A',135000)]));
DB::table('whatsapp_order_drafts')->insert(['id'=>41,'conversation_id'=>$case==='draft-other-conversation'?8:7,
    'status'=>$case==='unsafe-status'?'PRIVATE_STATUS':'REVIEW','reason'=>$case==='unsafe-status'?'PRIVATE_REASON':'LOCATION_UNCONFIRMED',
    'ticket_id'=>$case==='has-ticket'?99:null,'evidence_floor'=>0,'evidence_ceiling'=>$ceiling,
    'evidence_hash'=>$case==='stale-hash'?str_repeat('b',64):$snapshot['hash'],'extraction'=>$cipher]);
if ($case==='newer-message') DB::table('whatsapp_inbox_messages')->insert(['id'=>7,'conversation_id'=>7,
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
Schema::create('whatsapp_webhook_events',static function (Blueprint $t): void { $t->integer('id')->primary(); $t->string('processed_at')->nullable(); });
Schema::create('whatsapp_inbox_ingestion_failures',static function (Blueprint $t): void { $t->integer('event_id')->primary(); });
if ($case==='tail-pending-capture') {
    DB::table('whatsapp_webhook_events')->insert([['id'=>1,'processed_at'=>null],['id'=>2,'processed_at'=>null],
        ['id'=>3,'processed_at'=>'2026-10-14 12:00:01'],['id'=>4,'processed_at'=>null]]);
    DB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>4]);
}
if ($case==='tail-wrong-key') DB::table('whatsapp_inbox_messages')->where('id',4)->update(['message_key'=>str_repeat('b',64)]);
if (in_array($case,['tail-caption-mismatch','tail-caption-empty','tail-caption-invalid','tail-raw-peer','tail-raw-date','tail-long-caption'],true)
    ||str_starts_with($case,'tail-decline-')) {
    $row=DB::table('whatsapp_inbox_messages')->where('id',4)->first();
    $dto=json_decode(Crypt::decryptString($row->content),true,64,JSON_THROW_ON_ERROR);
    if ($case==='tail-caption-mismatch') $dto['content']['message']['image']['caption']='PRIVATE_CAPTION شارع المثال 201000000001';
    if ($case==='tail-caption-empty') $dto['content']['message']['image']['caption']='';
    if ($case==='tail-caption-invalid') $dto['content']['message']['image']['caption']=1234;
    if ($case==='tail-long-caption') $dto['content']['message']['image']['caption']=str_repeat('ن',19999);
    $declines=['tail-decline-la'=>'لا','tail-decline-lah'=>'لأ','tail-decline-kalla'=>'كلا','tail-decline-no'=>'no',
        'tail-decline-nope'=>'nope','tail-decline-stop'=>'stop','tail-decline-quoted'=>'"لا"',
        'tail-decline-detail'=>'لا مشكلة','tail-decline-question'=>'لا؟'];
    if (isset($declines[$case])) $dto['content']['message']['image']['caption']=$declines[$case];
    if ($case==='tail-raw-peer') $dto['content']['message']['from_user_id']='US.PRIVATE_FOREIGN_PEER';
    if ($case==='tail-raw-date') $dto['content']['message']['timestamp']='0';
    DB::table('whatsapp_inbox_messages')->where('id',4)->update(['content'=>Crypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
}
DB::listen(static function ($query): void {
    static $fixtureMutating=false; static $fixtureDrifted=false;
    if ($fixtureMutating) return;
    file_put_contents(getcwd().'/queries.jsonl',json_encode($query->sql,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
    // Simulate an independent concurrent writer AFTER the observer has read its
    // first bounded native body. These harness queries are separate from observer SQL.
    if (getenv('TAIL_FIXTURE_CASE')==='tail-raw-caption-drift'&&!$fixtureDrifted
        &&str_contains($query->sql,'CASE WHEN LENGTH(content)')&&$query->bindings===[7,4]) {
        $fixtureMutating=true; $fixtureDrifted=true;
        $row=DB::table('whatsapp_inbox_messages')->where('id',4)->first();
        $dto=json_decode(Crypt::decryptString($row->content),true,64,JSON_THROW_ON_ERROR);
        $dto['content']['message']['image']['caption']='PRIVATE_CONCURRENT_CAPTION';
        DB::table('whatsapp_inbox_messages')->where('id',4)
            ->update(['content'=>Crypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
        $fixtureMutating=false;
    }
});
return new class {
    public function make($name) {
        if ($name!==\Illuminate\Contracts\Console\Kernel::class) throw new \RuntimeException('UNEXPECTED_KERNEL_SERVICE');
        return new class { public function bootstrap(): void {} };
    }
};
}
WA_TAIL_NATIVE_FIXTURE
;
file_put_contents($fixture.'/bootstrap/app.php',$bootstrap);
function tailRun(string $case,array $arguments=['--conversation','7','--draft','41']): array {
    global $fixture,$root,$vendor;
    $queryFile=$fixture.'/queries.jsonl'; if (is_file($queryFile)) unlink($queryFile);
    $command=[PHP_BINARY];
    if (is_string(php_ini_loaded_file())&&php_ini_scanned_files()===false) {
        $command[]='-n'; $command[]='-c'; $command[]=php_ini_loaded_file();
    }
    $env=array_merge(getenv(),['TAIL_FIXTURE_VENDOR'=>$vendor,'TAIL_FIXTURE_CASE'=>$case,'TAIL_FIXTURE_ROOT'=>$root]);
    $child=proc_open(array_merge($command,[$fixture.'/observer.php'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture,$env);
    if (!is_resource($child)) throw new RuntimeException('TAIL_CHILD_UNAVAILABLE');
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($child);
    tailExpect($stderr,'','observer never leaks exception details to stderr');
    $data=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
    tailExpect(strlen($stdout)<12000,true,'observer output is bounded for focused native tail');
    foreach (['PRIVATE_','201000000001','عميل تجريبي','شارع المثال','US.','wamid.','client_request_id',
        'message_key','proposal_hash','evidence_hash','audit','extraction"'] as $secret)
        tailExpect(str_contains($stdout,$secret),false,'observer suppresses customer data, credentials, ciphertext and stable identifiers');
    tailExpect((bool)preg_match('/\b[a-f0-9]{64}\b|\b[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\b/i',$stdout),false,'observer never discloses raw hashes or UUIDs');
    tailExpect([$data['writes'],$data['ai_calls'],$data['planner_executed'],$data['hint_scope']],
        [0,0,false,'DIAGNOSTIC_ONLY_NOT_APPROVAL_OR_COVERAGE'],'observer metadata never claims approval or coverage authority');
    $queries=is_file($queryFile)?file($queryFile,FILE_IGNORE_NEW_LINES):[];
    foreach ($queries as $query) tailExpect((bool)preg_match('/\ASELECT\b/i',json_decode($query,true,8,JSON_THROW_ON_ERROR)),true,'every actual observer database query is SELECT only');
    return [$data,$exit,count($queries)];
}
function tailReady(string $case): array {
    [$data,$exit,$queries]=tailRun($case); tailExpect([$data['status'],$exit],['READ_READY',0],$case.' observes native tail '.json_encode($data));
    tailExpect($queries>0,true,$case.' performs actual database reads'); return $data;
}
$ready=tailReady('tail-ready');
tailExpect([$ready['snapshot_complete'],$ready['snapshot_current_at_read'],$ready['extraction_valid'],$ready['raw_decision'],$ready['checked_decision'],
    $ready['snapshot_current_at_end'],$ready['tail_complete'],$ready['all_tail_authenticated'],$ready['ledger_rows_matched'],$ready['post_final_customer_count']],
    [true,true,true,'CONFIRMED','CONFIRMED',true,true,true,6,4],'complete actual native ledger and four-row post-final tail');
tailExpect($ready['native_ledger_current_at_end'],true,'private native content versions remain current without exposing ciphertext or digests');
tailExpect($ready['final_cited_business_ordinal'],2,'final cited merchant ordinal comes from exact ledger');
$text=$ready['customer_tail'][0];
tailExpect([$text['ordinal'],$text['relative_to_final'],$text['native_kind'],$text['source'],$text['authentic_projected'],
    $text['native_text_matches_snapshot'],$text['caption_status'],$text['cited_request'],$text['cited_acceptance'],$text['snapshot_whole_positive'],$text['native_whole_positive']],
    [3,1,'text','messages',true,true,'ABSENT',false,true,false,false],'later cited detail text remains nonpositive metadata');
tailExpect($text['matches_customer_fields'],['name'=>true,'phone'=>true,'address'=>true,'area'=>true],'pure literal matches observe all actual customer facts');
tailExpect([$text['item_name_match_ordinals'],$text['item_option_match_ordinals']],[ [1], [] ],'pure literal matching exposes only bounded item ordinals');
foreach (array_slice($ready['customer_tail'],1) as $index=>$media) {
    tailExpect([$media['ordinal'],$media['relative_to_final'],$media['native_kind'],$media['authentic_projected'],$media['native_text_matches_snapshot'],
        $media['caption_status'],$media['caption_length'],$media['text_present'],$media['native_whole_positive']],
        [$index+4,$index+2,'image',true,true,'ABSENT',null,false,false],'actual native captionless image '.($index+1).' yields metadata only');
    tailExpect($media['matches_customer_fields'],['name'=>false,'phone'=>false,'address'=>false,'area'=>false],'native media does not invent customer literal facts');
}
tailExpect([$ready['pending_raw_events'],$ready['pending_scope']],[0,'GLOBAL_UNQUARANTINED_CAPTURE_QUEUE'],'empty real capture queue is accurately labeled global');
$mixed=tailReady('tail-mixed-media');
tailExpect(array_column(array_slice($mixed['customer_tail'],1),'native_kind'),['audio','video','document'],
    'actual authenticated mixed native media kinds are emitted without guessing image semantics');
tailExpect([$mixed['all_tail_authenticated'],$mixed['snapshot_current_at_end'],$mixed['post_final_customer_count']],
    [true,true,4],'mixed native tail remains a complete current authenticated observation');
foreach (array_slice($mixed['customer_tail'],1) as $row)
    tailExpect([$row['authentic_projected'],$row['native_text_matches_snapshot'],$row['caption_status'],$row['text_present'],$row['native_whole_positive']],
        [true,true,'ABSENT',false,false],'mixed native media supply no invented text or approval');
$sameTime=tailReady('tail-same-timestamp');
tailExpect([array_column($sameTime['customer_tail'],'ordinal'),array_column($sameTime['customer_tail'],'relative_to_final'),
    $sameTime['all_tail_authenticated'],$sameTime['ledger_rows_matched'],$sameTime['snapshot_current_at_end']],
    [[3,4,5,6],[1,2,3,4],true,6,true],'same-sent_at native rows preserve the exact ID-ordered ledger bijection');
$pending=tailReady('tail-pending-capture'); tailExpect($pending['pending_raw_events'],2,'processed and quarantined events are excluded from pending capture count');
$caption=tailReady('tail-caption-mismatch'); $row=$caption['customer_tail'][1];
tailExpect([$caption['all_tail_authenticated'],$row['authentic_projected'],$row['native_text_matches_snapshot'],$row['caption_status'],
    $row['text_present'],$row['native_whole_positive'],$row['matches_customer_fields']['phone'],$row['matches_customer_fields']['address']],
    [true,true,false,'TEXT',true,false,true,true],'raw image caption invisible to DTO snapshot is observed without adoption or disclosure');
$empty=tailReady('tail-caption-empty'); $row=$empty['customer_tail'][1];
tailExpect([$row['authentic_projected'],$row['native_text_matches_snapshot'],$row['caption_status'],$row['caption_length'],$row['text_present']],
    [true,false,'EMPTY',0,false],'native empty caption differs from DTO null without becoming data');
$long=tailReady('tail-long-caption'); $row=$long['customer_tail'][1];
tailExpect([$row['caption_status'],$row['caption_length'],$row['text_length'],$row['text_word_count']],['TEXT',19999,19999,1],'valid long native caption stays bounded');
foreach (['tail-decline-la'=>true,'tail-decline-lah'=>true,'tail-decline-kalla'=>true,'tail-decline-no'=>true,
    'tail-decline-nope'=>true,'tail-decline-stop'=>true,'tail-decline-quoted'=>false,'tail-decline-detail'=>false,
    'tail-decline-question'=>false] as $case=>$expected) {
    $decline=tailReady($case); $row=$decline['customer_tail'][1];
    tailExpect([$row['authentic_projected'],$row['native_text_matches_snapshot'],$row['caption_status'],$row['whole_decline_or_stop']],
        [true,false,'TEXT',$expected],'whole native decline hint accurately observes '.$case);
    tailExpect([$decline['hint_scope'],$row['native_whole_positive']],
        ['DIAGNOSTIC_ONLY_NOT_APPROVAL_OR_COVERAGE',false],'decline flags remain observations and never authorization');
}
foreach (['tail-caption-invalid','tail-raw-peer','tail-raw-date'] as $case) {
    $invalid=tailReady($case); $row=$invalid['customer_tail'][1];
    tailExpect([$invalid['all_tail_authenticated'],$row['authentic_projected'],$row['native_text_matches_snapshot'],$row['caption_status'],$row['caption_length']],
        [false,false,null,'UNAUTHENTICATED',null],'unreadable authentic native row fails closed for '.$case);
    foreach (['text_present','text_length','text_word_count','approval_keyword_present','cancel_keyword_present','change_keyword_present',
        'new_order_keyword_present','probe_keyword_present','whole_decline_or_stop','native_whole_positive','matches_customer_fields','item_name_match_ordinals','item_option_match_ordinals'] as $field)
        tailExpect(array_key_exists($field,$row),false,'unreadable native row suppresses '.$field);
    tailExpect($invalid['post_final_customer_count'],4,'unreadable row remains in complete tail metadata instead of silently disappearing');
}
foreach (['stale-hash','newer-message','incomplete-snapshot','invalid-extraction'] as $case) {
    [$blocked,$exit,$queries]=tailRun($case);
    tailExpect([$blocked['status'],$blocked['stage'],$blocked['customer_tail'],$exit],['OBSERVATION_BLOCKED','SNAPSHOT_GUARD',[],1],
        'unsafe snapshot cannot expose interpreted tail for '.$case);
}
[$drift,$exit,$queries]=tailRun('tail-raw-caption-drift');
tailExpect([$drift['status'],$drift['stage'],$drift['customer_tail'],$exit,$drift['snapshot_current_at_end'],$drift['native_ledger_current_at_end']],
    ['OBSERVATION_BLOCKED','FINAL_CURRENT',[],1,true,false],
    'raw caption race clears tail even when the semantic DTO snapshot remains unchanged');
foreach (['bad-scope'=>'DRAFT','draft-other-conversation'=>'DRAFT','tail-wrong-key'=>'SNAPSHOT','bad-native-message'=>'SNAPSHOT',
    'bad-owner'=>'OWNER','decrypt-failure'=>'DRAFT','oversized-ciphertext'=>'DRAFT','oversized-plaintext'=>'DRAFT','wrong-loaded-source'=>'BOOTSTRAP'] as $case=>$stage) {
    [$failed,$exit,$queries]=tailRun($case);
    tailExpect([$failed['status'],$failed['stage'],$failed['customer_tail'],$exit],['READ_FAILED',$stage,[],1],
        'fixed-stage failure suppresses complete tail for '.$case);
}
foreach ([[],['--conversation','7'],['--conversation','0','--draft','41'],['--draft','41','--conversation','7'],
    ['--conversation','7','--draft','041'],['--conversation','7','--draft','10000000000'],['--conversation','7','--draft','41','extra']] as $arguments) {
    [$failed,$exit,$queries]=tailRun('tail-ready',$arguments);
    tailExpect([$failed['status'],$failed['stage'],$failed['customer_tail'],$exit,$queries],['READ_FAILED','ARGUMENTS',[],1,0],'bad arguments refuse before bootstrap');
}
foreach ($files as $rel=>$bytes) {
    $path=$fixture.'/'.$rel; file_put_contents($path,$bytes."\n");
    [$failed,$exit,$queries]=tailRun('tail-ready');
    tailExpect([$failed['status'],$failed['stage'],$failed['customer_tail'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',[],1,0],'drifted source refuses before bootstrap');
    file_put_contents($path,$bytes);
}
$provider='app/Services/Dashboard/WhatsAppOrderAiProvider.php'; $path=$fixture.'/'.$provider;
chmod($path,0666); [$failed,$exit,$queries]=tailRun('tail-ready');
tailExpect([$failed['status'],$failed['stage'],$failed['customer_tail'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',[],1,0],
    'group/world-writable guarded source refuses before bootstrap'); chmod($path,0600);
$linked=$fixture.'/linked-provider.php'; file_put_contents($linked,$files[$provider]); chmod($linked,0600);
unlink($path); symlink($linked,$path); [$failed,$exit,$queries]=tailRun('tail-ready');
tailExpect([$failed['status'],$failed['stage'],$failed['customer_tail'],$exit,$queries],['READ_FAILED','SOURCE_GUARD',[],1,0],
    'symlinked guarded source refuses before bootstrap');
unlink($path); file_put_contents($path,$files[$provider]); chmod($path,0600);
echo 'WHATSAPP_ORDER_CUSTOMER_TAIL_TESTS='.$checks." PASS\n";
