<?php
namespace {
    $autoload=getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload.='/autoload.php';
    require $autoload;
    function app($name=null) { $c=\Illuminate\Container\Container::getInstance();return $name===null?$c:$c->make($name); }
    function config($key=null,$default=null) { return $key===null?app('config'):app('config')->get($key,$default); }
    function now($tz=null) { return \Carbon\Carbon::now($tz); }
    function abort_unless($ok,$status,$message='BLOCKED') { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message); }
    function abort_if($ok,$status,$message='BLOCKED') { abort_unless(!$ok,$status,$message); }
    set_error_handler(function($s,$m,$f,$l){if($s&E_ALL)throw new \ErrorException($m,0,$s,$f,$l);return false;});
}
namespace App\Models { class User { public $id=1;public $account_type='admin';public $owner_resturant_id=null;public $active=true; } }
namespace App\Services\Dashboard { class TakeawayAccess { public function actor($actor) { \abort_unless($actor&&$actor->active,403);return $actor; } public function permissions($actor) { return ['can_checkout'=>true]; } } }
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Facade;
    use App\Support\MetaMessagingProtocol as Protocol;
    use App\Services\Dashboard\MetaInboxAccess as Access;
    use App\Services\Dashboard\MetaInboxConsumer as Consumer;
    use App\Services\Dashboard\MetaReplyService as Replies;
    $c=new Container;Container::setInstance($c);$db=new Manager($c);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $db->setEventDispatcher(new Dispatcher($c));$db->setAsGlobal();$db->bootEloquent();$key=random_bytes(32);
    $accounts=[['channel'=>'messenger','account_id'=>'101','access_token'=>str_repeat('a',24),'label'=>'Facebook'],
        ['channel'=>'instagram','account_id'=>'202','access_token'=>str_repeat('b',24),'login'=>'instagram','label'=>'Instagram'],
        ['channel'=>'messenger','account_id'=>'303','access_token'=>str_repeat('c',24)]];
    $c->instance('config',new Repository(['app'=>['key'=>'base64:'.base64_encode($key)],'meta_messaging'=>['accounts'=>$accounts,'api_version'=>'v25.0'],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]] ]));
    $c->instance('db',$db->getDatabaseManager());$c->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter',new Encrypter($key,'AES-256-CBC'));Facade::setFacadeApplication($c);
    foreach (['Support/WhatsAppWebhookProtocol','Support/MetaMessagingProtocol','Services/Dashboard/WhatsAppInboxAccess','Services/Dashboard/MetaInboxAccess',
        'Services/Dashboard/MetaInvalidEvent','Services/Dashboard/MetaInboxConsumer','Services/Dashboard/MetaInboxReadState','Services/Dashboard/MetaReplyService',
        'Services/Dashboard/WhatsAppOrderWorkflow','Services/Dashboard/MetaOrderWorkflow'] as $file) require __DIR__.'/../app/'.$file.'.php';
    foreach (glob(__DIR__.'/../database/migrations/2026_10_11_*create_meta*.php') as $file) require $file;
    foreach (['CreateMetaWebhookEventsTable','CreateMetaInboxTables','CreateMetaOrderDrafts','CreateMetaReplyRequests','CreateMetaInboxReads'] as $klass) (new $klass)->up();
    $checks=0;
    function check($ok,$label) { global $checks;$checks++;if(!$ok)throw new \RuntimeException($label); }
    function event($object,$account,$peer,$mid,$text,$out=false,$extra=[]) {
        return ['object'=>$object,'entry'=>[['id'=>$account,'messaging'=>[array_merge(['sender'=>['id'=>$out?$account:$peer],
            'recipient'=>['id'=>$out?$peer:$account],'timestamp'=>(time()-2)*1000,'message'=>['mid'=>$mid,'text'=>$text]+($out?['is_echo'=>true]:[])],$extra)]]]];
    }
    function capture($p) { $json=json_encode($p,JSON_THROW_ON_ERROR);DB::table('meta_webhook_events')->insert(['payload_hash'=>hash('sha256',$json),'payload'=>Crypt::encryptString($json),'received_at'=>now('UTC')]); }
    $one=event('page','101','900','same-mid','اسم العميل أحمد');$two=event('instagram','202','900','same-mid','اسمي أحمد');
    $three=event('page','303','900','same-mid','هاتف العميل');$echo=event('page','101','900','echo-mid','تم تأكيد الطلب',true);
    $body=json_encode($one);$secret=str_repeat('d',32);
    check(\App\Support\WhatsAppWebhookProtocol::authentic($body,'sha256='.hash_hmac('sha256',$body,$secret),$secret),'valid signature');
    check(!\App\Support\WhatsAppWebhookProtocol::authentic($body.' ','sha256='.hash_hmac('sha256',$body,$secret),$secret),'modified body denied');
    check(Protocol::scopedPayload(json_encode(event('page','999','900','x','x')),$accounts)===null,'unknown account dropped');
    foreach ([$one,$two,$three,$echo] as $p) capture($p);
    $consumer=new Consumer;$m=$consumer->consume();check($m['messages_inserted']===4&&$m['errors']===0,'projection');
    check(DB::table('meta_inbox_conversations')->count()===3,'peer isolated across accounts and channels');
    capture(['object'=>'page','entry'=>[['id'=>'101','standby'=>$one['entry'][0]['messaging']]]]);
    check($consumer->consume()['messages_replayed']===1,'standby replay idempotent');
    check(DB::table('meta_inbox_messages')->count()===4,'no duplicate message');
    check(strpos(DB::table('meta_inbox_messages')->first()->content,'أحمد')===false,'encrypted customer data');
    $actor=new \App\Models\User;$thread=DB::table('meta_inbox_conversations')->where('waba_id','messenger')->where('phone_number_id','101')->first();
    $reads=new \App\Services\Dashboard\MetaInboxReadState;
    check($reads->unread($actor)['total_unread']===3,'per-user unread');
    $inbound=DB::table('meta_inbox_messages')->where('conversation_id',$thread->id)->where('direction','inbound')->value('id');
    $reads->markRead((int)$thread->id,(int)$inbound,$actor);check($reads->unread($actor)['total_unread']===2,'read marker');
    $calls=0;
    $replies=new Replies(function($endpoint,$payload,$token) use (&$calls) { $calls++;check(strpos($endpoint,'graph.facebook.com/v25.0/101/messages')!==false,'Messenger endpoint');
        check($payload['recipient']['id']==='900'&&$payload['messaging_type']==='RESPONSE','native recipient');check($token===str_repeat('a',24),'account token');
        check(DB::connection()->transactionLevel()===0,'no network under DB lock');
        return ['status'=>200,'body'=>json_encode(['recipient_id'=>'900','message_id'=>'sent-mid'])]; });
    $state=$replies->state($actor,(int)$thread->id);check($state['can_reply']&&$state['latest_inbound_id']===$inbound,'reply window');
    $input=['client_request_id'=>'12345678-1234-4234-8234-123456789abc','expected_inbound_id'=>$inbound,'text'=>'أهلاً بك'];
    check($replies->send($actor,(int)$thread->id,$input)['state']==='ACCEPTED','send accepted');
    check($replies->send($actor,(int)$thread->id,$input)['replayed']&&$calls===1,'send replay never repeats network');
    $actor->active=false;try{$replies->state($actor,(int)$thread->id);check(false,'revocation');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===403,'revoked');}$actor->active=true;
    $ig=DB::table('meta_inbox_conversations')->where('waba_id','instagram')->first();
    $igReply=new Replies(function($endpoint,$payload,$token){check(strpos($endpoint,'graph.instagram.com/v25.0/202/messages')!==false,'Instagram endpoint');check(!isset($payload['messaging_type']),'Instagram shape');return ['status'=>0,'body'=>''];});
    $igState=$igReply->state($actor,(int)$ig->id);$input['expected_inbound_id']=$igState['latest_inbound_id'];
    check($igReply->send($actor,(int)$ig->id,$input)['state']==='UNKNOWN','timeout uncertain');check(!$igReply->state($actor,(int)$ig->id)['can_reply'],'uncertain send blocks subsequent sends');
    config()->set('meta_messaging.accounts',[$accounts[0]]);
    check(Access::scope(DB::table('meta_inbox_conversations'))->count()===1,'revoked account hidden');
    try{$replies->state($actor,(int)$ig->id);check(false,'revoked account reply');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===404,'revoked account rejected');}
    config()->set('meta_messaging.accounts',$accounts);
    $workflow=new \App\Services\Dashboard\MetaOrderWorkflow;
    check($workflow->available(),'Meta workflow tables');
    $snapshot=new \ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderWorkflow::class,'snapshot');$snapshot->setAccessible(true);
    $result=$snapshot->invoke($workflow,(int)$thread->id,0,PHP_INT_MAX,false);
    check(count($result['transcript'])===2&&$result['transcript'][0]['speaker']==='customer'&&$result['transcript'][1]['speaker']==='business','same grounded order transcript');
    check($workflow->state((int)$thread->id,$actor)['drafts']===[],'Meta drafts separated');
    check($workflow->process()['disabled']===1,'auto requires independent activation');
    $bad=event('page','101','900','bad-routing','x');$bad['entry'][0]['messaging'][0]['recipient']['id']='303';capture($bad);
    check($consumer->consume()['quarantined_events']===1,'foreign routing quarantined');
    $voice=event('instagram','202','900','voice-mid',null);$voice['entry'][0]['messaging'][0]['message']['attachments']=[['type'=>'audio','payload'=>['url'=>'https://example.com/voice']]];
    check(Protocol::report($voice,$accounts)['messages'][0]['type']==='audio','incoming voice supported');
    $location=$voice;$location['entry'][0]['messaging'][0]['message']['attachments']=[['type'=>'location','payload'=>['coordinates'=>['lat'=>31.0,'long'=>31.4]]]];
    $dto=Protocol::report($location,$accounts)['messages'][0];check($dto['content']['message']['location']['latitude']===31.0,'native location normalized');
    echo "meta-messaging: $checks checks passed\n";
}
