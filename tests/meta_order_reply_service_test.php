<?php
namespace {
    $autoload=getenv('WA_INBOX_TEST_VENDOR');if(is_dir($autoload))$autoload.='/autoload.php';require $autoload;
    spl_autoload_register(function($class){if(str_starts_with($class,'App\\')){$file=__DIR__.'/../app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require_once$file;}});
    function app($name=null){$c=\Illuminate\Container\Container::getInstance();return$name===null?$c:$c->make($name);}
    function config($key=null,$default=null){return$key===null?app('config'):app('config')->get($key,$default);}
    function now($tz=null){return \Carbon\Carbon::now($tz);}
    function abort_unless($ok,$status,$message='BLOCKED'){if(!$ok)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function abort_if($ok,$status,$message='BLOCKED'){abort_unless(!$ok,$status,$message);}
    set_error_handler(function($s,$m,$f,$l){if($s&E_ALL)throw new \ErrorException($m,0,$s,$f,$l);return false;});
}
namespace App\Models {class User{public $id=1;public $active=true;public $account_type='admin';public $owner_resturant_id=null;}}
namespace App\Services\Dashboard {class TakeawayAccess{public function actor($a){\abort_unless($a&&$a->active,403);return$a;}}}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Facade;
    use App\Services\Dashboard\MetaReplyService as Replies;
    use App\Services\Dashboard\MetaOrderFollowupService as Followup;
    use App\Services\Dashboard\MetaInboxConsumer as Consumer;
    $c=new Container;Container::setInstance($c);$db=new Manager($c);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $db->setEventDispatcher(new Dispatcher($c));$db->setAsGlobal();$db->bootEloquent();$key=random_bytes(32);
    $accounts=[['channel'=>'messenger','account_id'=>'101','access_token'=>str_repeat('a',24)],
        ['channel'=>'instagram','account_id'=>'202','access_token'=>str_repeat('b',24),'login'=>'instagram'],
        ['channel'=>'instagram','account_id'=>'303','access_token'=>str_repeat('c',24),'login'=>'facebook']];
    $c->instance('config',new Repository(['app'=>['key'=>'base64:'.base64_encode($key)],'meta_messaging'=>['accounts'=>$accounts,'api_version'=>'v25.0'],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]] ]));
    $c->instance('db',$db->getDatabaseManager());$c->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter',new Encrypter($key,'AES-256-CBC'));Facade::setFacadeApplication($c);
    foreach(['2026_10_11_160000_create_meta_webhook_events_table','2026_10_11_160001_create_meta_inbox_tables',
        '2026_10_11_160003_create_meta_reply_requests']as$file)require __DIR__.'/../database/migrations/'.$file.'.php';
    foreach(['CreateMetaWebhookEventsTable','CreateMetaInboxTables','CreateMetaReplyRequests']as$klass)(new$klass)->up();
    $checks=0;$serial=0;$actor=new \App\Models\User;$other=new \App\Models\User;$other->id=2;
    function check($ok,$why){global$checks;$checks++;if(!$ok)throw new \RuntimeException($why);}
    function rejects($fn,$why){try{$fn();check(false,$why);}catch(\Throwable$e){check($e->getMessage()!==$why,$why);}}
    function capture($channel,$account,$peer,$mid,$text,$outbound=false,$stamp=null){
        $p=['object'=>$channel==='messenger'?'page':'instagram','entry'=>[['id'=>$account,'messaging'=>[[
            'sender'=>['id'=>$outbound?$account:$peer],'recipient'=>['id'=>$outbound?$peer:$account],
            'timestamp'=>($stamp??time()-20)*1000,'message'=>['mid'=>$mid,'text'=>$text]+($outbound?['is_echo'=>true]:[])]]]]];
        $body=json_encode($p,JSON_THROW_ON_ERROR);DB::table('meta_webhook_events')->insert(['payload_hash'=>hash('sha256',$body),
            'payload'=>Crypt::encryptString($body),'received_at'=>now('UTC')]);return(new Consumer)->consume();
    }
    function thread($channel='messenger',$account='101'){
        global$serial;$serial++;$peer=(string)(900000+$serial);capture($channel,$account,$peer,'in-'.$serial,'ممكن أطلب؟');
        $row=DB::table('meta_inbox_conversations')->where('waba_id',$channel)->where('phone_number_id',$account)->orderByDesc('id')->first();
        return[$row,$peer,(int)DB::table('meta_inbox_messages')->where('conversation_id',$row->id)->where('direction','inbound')->value('id')];
    }
    function followupInput($thread,$inbound,$compose,$fields=['ITEMS']){
        $native=DB::table('meta_inbox_messages')->where('id',$inbound)->first();
        $marker=['version'=>'meta-order-followup-v1','kind'=>$fields===['CONFIRMATION']?'confirmation':'question','fields'=>$fields,
            'latest_customer_id'=>$inbound,'latest_customer_key'=>$native->message_key,'episode_customer_key'=>$native->message_key,
            'proposal_hash'=>$fields===['CONFIRMATION']?str_repeat('a',64):null];
        $uuid=Followup::requestUuid($marker+['conversation_id'=>(int)$thread->id],Crypt::getKey());
        check(Followup::validMarker($marker,(int)$thread->id,$uuid,$inbound),'production scoped marker valid');
        return['client_request_id'=>$uuid,'expected_inbound_id'=>$inbound,'text'=>'جاري إعداد الرد','_followup'=>$marker,'_compose'=>$compose];
    }
    function accepted($mid){return['status'=>200,'body'=>json_encode(['message_id'=>$mid])];}
    function receipt($cid){return DB::table('meta_reply_requests')->where('conversation_id',$cid)->orderByDesc('id')->first();}
    function projected($cid){return DB::table('meta_inbox_messages')->where('conversation_id',$cid)->where('source','dashboard_api')->first();}
    // Both native transports reserve first, compose outside locks, and persist uncertainty before Graph.
    foreach([['messenger','101','graph.facebook.com/v25.0/101/messages'],['instagram','202','graph.instagram.com/v25.0/202/messages'],
        ['instagram','303','graph.facebook.com/v25.0/me/messages']]as[$channel,$account,$endpoint]){
        [$t,$peer,$inbound]=thread($channel,$account);$ai=0;$graph=0;$sender=null;$input=null;
        $sender=new Replies(function($url,$body,$token)use(&$graph,$endpoint,$t,$peer){
            $graph++;check(str_contains($url,$endpoint),'channel-native Graph endpoint');check(DB::connection()->transactionLevel()===0,'Graph outside transaction');
            $r=receipt($t->id);check($r->state==='UNKNOWN'&&$r->send_started_at!==null,'uncertainty durable before Graph');
            check($body['recipient']['id']===$peer&&$body['message']['text']==='تحب تطلب إيه؟','actual composed text and scoped recipient');
            return accepted('out-'.$t->id);
        });
        $input=followupInput($t,$inbound,function()use(&$ai,&$sender,&$input,$t,$other){
            $ai++;check(DB::connection()->transactionLevel()===0,'AI outside transaction');check(receipt($t->id)->state==='CLAIMED','claim durable before AI');
            $copy=$input;$copy['_compose']=function(){throw new \RuntimeException('DUPLICATE_AI');};
            check($sender->sendOrderFollowup($other,(int)$t->id,$copy,fn()=>true)['replayed'],'other actor sees durable input claim during composition');
            return'تحب تطلب إيه؟';
        });
        $sent=$sender->sendOrderFollowup($actor,(int)$t->id,$input,fn()=>true);
        check($sent['state']==='ACCEPTED'&&$ai===1&&$graph===1,'one AI and one accepted native send');
        check($sender->sendOrderFollowup($other,(int)$t->id,$input,fn()=>true)['replayed']&&$ai===1&&$graph===1,'cross-actor accepted replay spends/sends nothing');
        $r=receipt($t->id);$row=projected($t->id);check($row!==null,'accepted automatic text projects without echo');
        $e=$sender->unwrapAcceptedEvidence($t,$row);check($e['native_id']==='out-'.$t->id&&$e['text']==='تحب تطلب إيه؟'&&$e['peer_user_id']===$peer,'projection authenticates accepted facts');
        check($e['order_followup']===$input['_followup']&&$e['client_request_id']===$input['client_request_id'],'ownership carries exact scoped marker');
        check(!$sender->hasManualReplyAfter((int)$t->id,DB::table('meta_inbox_messages')->where('id',$inbound)->value('sent_at')),'owned automatic receipt is not human takeover');
        check(!str_contains($row->content,'تحب')&&!str_contains($r->audit,'تحب'),'projection and audit encrypted');
        $before=clone$row;$m=capture($channel,$account,$peer,'out-'.$t->id,'تحب تطلب إيه؟',true,time()-3);
        check($m['messages_replayed']===1&&$m['quarantined_events']===0,'later genuine echo with differing time merges');
        $row=projected($t->id);check($row->content===$before->content&&$row->sent_at===$before->sent_at,'echo preserves immutable acceptance provenance and time');
        $m=capture($channel,$account,$peer,'out-'.$t->id,'تغيير غير مصرح',true,time()-2);
        check($m['quarantined_events']===1&&projected($t->id)->content===$before->content,'same MID different text quarantines without overwrite');
        $bad=clone$row;$bad->direction='inbound';rejects(fn()=>$sender->unwrapAcceptedEvidence($t,$bad),'inbound cannot impersonate accepted merchant');
        $bad=clone$row;$bad->message_key=str_repeat('f',64);rejects(fn()=>$sender->unwrapAcceptedEvidence($t,$bad),'wrong native key rejected');
        $bad=clone$row;$bad->sent_at=gmdate('Y-m-d H:i:s',time()-999);rejects(fn()=>$sender->unwrapAcceptedEvidence($t,$bad),'changed projection time rejected');
        $original=$r->audit;$audit=json_decode(Crypt::decryptString($original),true);$audit['text']='تغيير';
        DB::table('meta_reply_requests')->where('id',$r->id)->update(['audit'=>Crypt::encryptString(json_encode($audit))]);
        rejects(fn()=>$sender->unwrapAcceptedEvidence($t,$row),'changed audit text rejected');
        DB::table('meta_inbox_messages')->where('id',$row->id)->delete();
        check(!$sender->projectPending(100,(int)$t->id)['complete'],'malformed owned pending projection reported explicitly');
        DB::table('meta_reply_requests')->where('id',$r->id)->update(['audit'=>$original]);
        DB::table('meta_inbox_messages')->insert((array)$row);
        check($sender->projectPending(100,(int)$t->id)['complete'],'materialized history consumes no pending budget');
    }
    // An echo can arrive inside the provider call before durable ACCEPTED is saved.
    [$t,$peer,$inbound]=thread();$sender=new Replies(function($u,$p,$token)use($t,$peer){
        $m=capture('messenger','101',$peer,'echo-first-'.$t->id,'تحب تطلب إيه؟',true,time()-3);
        check($m['messages_inserted']===1,'native echo arrives before acceptance');return accepted('echo-first-'.$t->id);
    });
    $input=followupInput($t,$inbound,fn()=>'تحب تطلب إيه؟');check($sender->sendOrderFollowup($actor,(int)$t->id,$input,fn()=>true)['state']==='ACCEPTED','echo-first acceptance');
    check(projected($t->id)===null&&DB::table('meta_inbox_messages')->where('conversation_id',$t->id)->count()===2,'echo-first accepted reply does not duplicate native row');
    $native=DB::table('meta_inbox_messages')->where('conversation_id',$t->id)->where('direction','outbound')->first();
    $proof=$sender->acceptedReceiptForNative($t,$native);check($proof['reply_id']===(int)receipt($t->id)->id&&$proof['sent_at']===$native->sent_at,'native row has authenticated owned receipt proof');
    [$foreign]=thread();check($sender->acceptedReceiptForNative($foreign,$native)===null,'foreign thread cannot borrow native receipt');
    // Every failed or uncertain automation attempt remains a cross-actor tombstone.
    foreach(['guard','exception','oversized','new_inbound','revoked','token_change','timeout','rejected']as$mode){
        [$t,$peer,$inbound]=thread();$ai=0;$graph=0;$ready=true;$sender=new Replies(function()use(&$graph,$mode,$t){
            $graph++;if($mode==='timeout')return['status'=>0,'body'=>''];if($mode==='rejected')return['status'=>400,'body'=>'{"error":{"code":10}}'];return accepted('case-'.$t->id);
        });
        $input=followupInput($t,$inbound,function()use(&$ai,&$ready,$mode,$t,$peer,$actor){
            $ai++;if($mode==='guard')$ready=false;if($mode==='exception')throw new \RuntimeException('fixture composition failure');
            if($mode==='oversized')return str_repeat('x',1001);
            if($mode==='new_inbound')capture('messenger','101',$peer,'new-'.$t->id,'أضيف حاجة',false,time()-1);
            if($mode==='revoked')$actor->active=false;
            if($mode==='token_change'){$accounts=config('meta_messaging.accounts');$accounts[0]['access_token']=str_repeat('z',24);config()->set('meta_messaging.accounts',$accounts);}
            return'تحب تطلب إيه؟';
        });
        $r=$sender->sendOrderFollowup($actor,(int)$t->id,$input,function()use(&$ready){return$ready;});$actor->active=true;config()->set('meta_messaging.accounts',$accounts);
        $expected=$mode==='timeout'?'UNKNOWN':'FAILED';check($r['state']===$expected,'guard/compose/access/transport result '.$mode);
        $again=$sender->sendOrderFollowup($other,(int)$t->id,$input,fn()=>true);
        check($again['replayed']&&$ai===1&&$graph===(in_array($mode,['timeout','rejected'],true)?1:0),'tombstone prevents AI and Graph replay '.$mode);
        check(projected($t->id)===null,'failed or unknown receipt creates no merchant evidence '.$mode);
    }
    // Provider acceptance survives a projection crash and the bounded local pass recovers it.
    [$t,$peer,$inbound]=thread();$graph=0;
    $sender=new class(function()use(&$graph,$t){$graph++;return accepted('recover-'.$t->id);})extends Replies{
        public function projectAcceptedReply(object$r):?int{throw new \RuntimeException('fixture projection crash');}
    };
    $input=followupInput($t,$inbound,fn()=>'تحب تطلب إيه؟');check($sender->sendOrderFollowup($actor,(int)$t->id,$input,fn()=>true)['state']==='ACCEPTED','receipt stays ACCEPTED despite independent projection crash');
    check(projected($t->id)===null,'crashed projection absent');$base=new Replies;
    $recovered=$base->projectPending(100,(int)$t->id);check($recovered['complete']&&$recovered['projected']===1&&$graph===1,'local retry recovers evidence without AI or Graph');
    // The next turn's live plan refresh runs under the sender claim transaction.
    capture('messenger','101',$peer,'second-turn-'.$t->id,'طبق رنجة',false,time()-1);
    $next=(int)DB::table('meta_inbox_messages')->where('conversation_id',$t->id)->where('direction','inbound')->max('id');
    $sender=new Replies(function()use(&$graph,$t){$graph++;return accepted('second-reply-'.$t->id);});
    $input=followupInput($t,$next,fn()=>'اسم حضرتك إيه؟',['NAME']);$guardsInTransaction=0;
    $guard=function()use($base,$t,&$guardsInTransaction){
        if(DB::connection()->transactionLevel()>0)$guardsInTransaction++;
        $before=(int)DB::table('meta_inbox_messages')->count();$proof=$base->projectPending(100,(int)$t->id);
        check((int)DB::table('meta_inbox_messages')->count()===$before,'refresh does not publish existing receipt under claim lock');return$proof['complete'];
    };
    check($sender->sendOrderFollowup($actor,(int)$t->id,$input,$guard)['state']==='ACCEPTED'&&$guardsInTransaction>0,'second inbound reserves and sends after pure in-transaction validation');
    check($graph===2&&DB::table('meta_inbox_messages')->where('conversation_id',$t->id)->where('source','dashboard_api')->count()===2,'second turn has one independent accepted reply');
    $saved=projected($t->id);DB::table('meta_inbox_messages')->where('id',$saved->id)->delete();
    DB::transaction(function()use($base,$t){$before=(int)DB::table('meta_inbox_messages')->count();$proof=$base->projectPending(100,(int)$t->id);
        check(!$proof['complete']&&$proof['invalid']===1,'missing committed projection blocks transaction refresh');
        check((int)DB::table('meta_inbox_messages')->count()===$before,'claim guard never inserts missing receipt evidence');});
    check($base->projectPending(100,(int)$t->id)['complete'],'missing projection recovers later outside claim transaction');
    // Manual acceptance keeps existing behavior but blocks the automatic takeover even without an echo.
    [$t,$peer,$inbound]=thread();$sender=new Replies(fn()=>accepted('manual-'.$t->id));
    $manual=['client_request_id'=>'12345678-1234-4234-8234-123456789abc','expected_inbound_id'=>$inbound,'text'=>'هرد عليك بنفسي'];
    check($sender->send($actor,(int)$t->id,$manual)['state']==='ACCEPTED'&&projected($t->id)===null,'manual accepted reply behavior retained');
    check($sender->hasManualReplyAfter((int)$t->id,DB::table('meta_inbox_messages')->where('id',$inbound)->value('sent_at')),'manual no-echo receipt pauses automatic replies');
    $input=followupInput($t,$inbound,function(){throw new \RuntimeException('HUMAN_TAKEOVER_AI');});
    rejects(fn()=>$sender->sendOrderFollowup($actor,(int)$t->id,$input,fn()=>!$sender->hasManualReplyAfter((int)$t->id,DB::table('meta_inbox_messages')->where('id',$inbound)->value('sent_at'))),'takeover guard blocks before claim and AI');
    check(DB::table('meta_reply_requests')->where('conversation_id',$t->id)->count()===1,'blocked takeover creates no automatic attempt');
    // More than a hundred valid historical acceptances and manual receipts do not stop new work.
    $oldGraph=0;$oldAi=0;
    for($index=0;$index<105;$index++){
        [$old,$peer,$oldInbound]=thread();$sender=new Replies(function()use(&$oldGraph,$old){$oldGraph++;return accepted('history-'.$old->id);});
        $request=followupInput($old,$oldInbound,function()use(&$oldAi){$oldAi++;return'تحب تطلب إيه؟';});
        check($sender->sendOrderFollowup($actor,(int)$old->id,$request,fn()=>true)['state']==='ACCEPTED','historical owned acceptance '.$index);
        $manual=['client_request_id'=>'12345678-1234-4234-8234-123456789abc','expected_inbound_id'=>$oldInbound,'text'=>'رد الموظف'];
        check($sender->send($other,(int)$old->id,$manual)['state']==='ACCEPTED','historical manual acceptance '.$index);
    }
    $pending=$base->projectPending(1);check($pending['complete']&&$pending['processed']===0,'over100 projected owned and manual receipts consume zero retry budget');
    check($oldGraph===210&&$oldAi===105,'history fixture uses real owned/manual send paths');
    [$fresh,$peer,$inbound]=thread();$freshGraph=0;$sender=new Replies(function()use(&$freshGraph,$fresh){$freshGraph++;return accepted('fresh-scale-'.$freshGraph.'-'.$fresh->id);});
    $freshGuard=fn()=>($base->projectPending(1)['complete']??false)===true;
    $request=followupInput($fresh,$inbound,fn()=>'تحب تطلب إيه؟');check($sender->sendOrderFollowup($actor,(int)$fresh->id,$request,$freshGuard)['state']==='ACCEPTED','fresh request survives global historical receipt volume');
    capture('messenger','101',$peer,'fresh-second-'.$fresh->id,'طبق رنجة',false,time()-1);
    $next=(int)DB::table('meta_inbox_messages')->where('conversation_id',$fresh->id)->where('direction','inbound')->max('id');
    $request=followupInput($fresh,$next,fn()=>'اسم حضرتك إيه؟',['NAME']);
    check($sender->sendOrderFollowup($actor,(int)$fresh->id,$request,$freshGuard)['state']==='ACCEPTED'&&$freshGraph===2,'fresh second turn reserves with historical volume and transactional refresh');
    // Provider acceptance with damaged owned metadata stays UNKNOWN, never unindexed ACCEPTED.
    foreach(['audit','marker','payload','kind']as$damage){
        [$bad,$peer,$badInbound]=thread();$sender=new Replies(function()use($damage,$bad){
            $r=receipt($bad->id);$changes=[];
            if($damage==='audit')$changes['audit']='invalid-private-cipher';
            if($damage==='marker'){$audit=json_decode(Crypt::decryptString($r->audit),true);unset($audit['order_followup']);$changes['audit']=Crypt::encryptString(json_encode($audit));}
            if($damage==='payload')$changes['payload_hash']=str_repeat('f',64);
            if($damage==='kind')$changes['kind']='image';
            DB::table('meta_reply_requests')->where('id',$r->id)->update($changes);return accepted('bad-owned-'.$bad->id);
        });
        $request=followupInput($bad,$badInbound,fn()=>'تحب تطلب إيه؟');
        check($sender->sendOrderFollowup($actor,(int)$bad->id,$request,fn()=>true)['state']==='UNKNOWN','damaged acceptance metadata remains uncertain '.$damage);
        check(receipt($bad->id)->state==='UNKNOWN'&&projected($bad->id)===null,'damaged owned receipt cannot become unindexed ACCEPTED '.$damage);
    }
    // Only genuinely pending owned receipts count toward a bounded backfill limit.
    $pendingIds=[];
    for($i=0;$i<2;$i++){
        [$pending,$peer,$pendingInbound]=thread();$sender=new class(fn()=>accepted('pending-'.$pending->id))extends Replies{
            public function projectAcceptedReply(object$r):?int{throw new \RuntimeException('fixture projection crash');}
        };
        $request=followupInput($pending,$pendingInbound,fn()=>'تحب تطلب إيه؟');
        check($sender->sendOrderFollowup($actor,(int)$pending->id,$request,fn()=>true)['state']==='ACCEPTED','pending receipt committed with native metadata');$pendingIds[]=(int)receipt($pending->id)->id;
    }
    check(!$base->projectPending(1)['complete'],'actual pending truncation reported explicitly');
    $damaged=DB::table('meta_reply_requests')->where('id',$pendingIds[0])->first();$audit=json_decode(Crypt::decryptString($damaged->audit),true);unset($audit['order_followup']);
    DB::table('meta_reply_requests')->where('id',$damaged->id)->update(['audit'=>Crypt::encryptString(json_encode($audit))]);
    $blocked=$base->projectPending(100);check(!$blocked['complete']&&$blocked['invalid']===1,'malformed indexed owned pending receipt fails closed');
    DB::table('meta_reply_requests')->where('id',$damaged->id)->update(['audit'=>$damaged->audit]);
    check($base->projectPending(100)['complete'],'valid pending receipts recover without history-volume blocking');
    echo "meta-order-reply-service: $checks checks passed\n";
}
