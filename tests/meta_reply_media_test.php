<?php
namespace {
    $autoload=getenv('WA_INBOX_TEST_VENDOR'); if(is_dir($autoload))$autoload.='/autoload.php'; require $autoload;
    function app($name=null){$c=\Illuminate\Container\Container::getInstance();return $name===null?$c:$c->make($name);}
    function config($key=null,$default=null){return $key===null?app('config'):app('config')->get($key,$default);}
    function now($tz=null){return \Carbon\Carbon::now($tz);}
    function abort_unless($ok,$status,$message='BLOCKED'){if(!$ok)throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}
    function route($name,$params=[]){if($name!=='meta-replies.temporary-media')throw new \RuntimeException('unexpected route');return 'https://dashboard.example.test/api/meta/messaging/media/'.$params['ticket'];}
    set_error_handler(function($s,$m,$f,$l){if($s&E_ALL)throw new \ErrorException($m,0,$s,$f,$l);return false;});
}
namespace App\Models {class User {public $id=1;public $active=true;public $account_type='admin';public $owner_resturant_id=null;}}
namespace App\Services\Dashboard {class TakeawayAccess {public function actor($actor){\abort_unless($actor&&$actor->active,403);return $actor;}}}
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
    use App\Services\Dashboard\MetaUploadMedia as Media;
    use Symfony\Component\HttpFoundation\File\UploadedFile;
    $c=new Container; Container::setInstance($c); $db=new Manager($c);
    $db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
    $db->setEventDispatcher(new Dispatcher($c));$db->setAsGlobal();$db->bootEloquent();$key=random_bytes(32);
    $accounts=[['channel'=>'messenger','account_id'=>'101','access_token'=>str_repeat('a',24)],
        ['channel'=>'messenger','account_id'=>'102','access_token'=>str_repeat('d',24)],
        ['channel'=>'instagram','account_id'=>'202','access_token'=>str_repeat('b',24),'login'=>'instagram'],
        ['channel'=>'instagram','account_id'=>'303','access_token'=>str_repeat('c',24),'login'=>'facebook']];
    $c->instance('config',new Repository(['meta_messaging'=>['accounts'=>$accounts,'api_version'=>'v25.0'],
        'database'=>['default'=>'default','connections'=>['default'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]]]));
    $c->instance('db',$db->getDatabaseManager());$c->instance('db.schema',$db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter',new Encrypter($key,'AES-256-CBC'));Facade::setFacadeApplication($c);
    foreach(['Support/MetaMessagingProtocol','Services/Dashboard/WhatsAppInboxAccess','Services/Dashboard/MetaInboxAccess',
        'Services/Dashboard/MetaUploadMedia','Services/Dashboard/MetaMediaService','Services/Dashboard/MetaReplyService'] as $file)require __DIR__.'/../app/'.$file.'.php';
    foreach(['2026_10_11_160000_create_meta_webhook_events_table','2026_10_11_160001_create_meta_inbox_tables',
        '2026_10_11_160003_create_meta_reply_requests','2026_10_13_160000_create_meta_message_hides'] as $file)require __DIR__.'/../database/migrations/'.$file.'.php';
    foreach(['CreateMetaWebhookEventsTable','CreateMetaInboxTables','CreateMetaReplyRequests','CreateMetaMessageHides'] as $klass)(new $klass)->up();
    $checks=0;$serial=0;$threadSerial=0;$actor=new \App\Models\User;
    function check($ok,$label){global $checks;$checks++;if(!$ok)throw new \RuntimeException($label);}
    function thread($channel='messenger',$account='101',$peer=null){
        global $threadSerial;$peer=$peer??(string)(900+$threadSerial++);
        $scope=$channel.':'.$account.':';$id=DB::table('meta_inbox_conversations')->insertGetId([
            'waba_id'=>$channel,'phone_number_id'=>$account,'peer_hash'=>hash_hmac('sha256','whatsapp-peer-v1:'.$scope.'user:'.$peer,Crypt::getKey()),
            'customer'=>Crypt::encryptString(json_encode(['user_id'=>$peer,'name'=>'Fixture customer'])),
            'first_message_at'=>now('UTC'),'last_message_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        message($id,'inbound','سؤال العميل');return (int)$id;
    }
    function message($thread,$direction='inbound',$text='Fixture text',$attachments=[]){
        global $serial;$serial++;$t=DB::table('meta_inbox_conversations')->where('id',$thread)->first();
        $customer=json_decode(Crypt::decryptString($t->customer),true);$peer=$customer['user_id'];$scope=$t->waba_id.':'.$t->phone_number_id.':';
        $sent=gmdate('Y-m-d H:i:s',time()-2);$mid='fixture-mid-'.$serial;
        $kind=$text!==null?'text':($attachments[0]['type']??'other');if($kind==='file')$kind='document';
        $dto=['channel'=>$t->waba_id,'account_id'=>$t->phone_number_id,'peer_user_id'=>$peer,'peer_identity'=>'user:'.$peer,
            'direction'=>$direction,'sent_at'=>$sent,'message_id'=>$mid,'type'=>$kind,'text'=>$text,
            'content'=>['message'=>['meta'=>['attachments'=>$attachments]]]];
        return (int)DB::table('meta_inbox_messages')->insertGetId(['conversation_id'=>$thread,'message_key'=>hash('sha256','whatsapp-message-v1:'.$scope.$mid),
            'direction'=>$direction,'type'=>$kind,'sent_at'=>$sent,'source'=>$direction==='inbound'?'messages':'message_echoes','content'=>Crypt::encryptString(json_encode($dto)),
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    function input($thread,$extra=[]){global $serial;$serial++;return ['client_request_id'=>sprintf('%08x-1234-4234-8234-123456789abc',$serial),
        'expected_inbound_id'=>(int)DB::table('meta_inbox_messages')->where('conversation_id',$thread)->where('direction','inbound')->orderByDesc('id')->value('id')]+$extra;}
    function expectHttp($operation,$status,$label){try{$operation();check(false,$label);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===$status,$label);}}
    function expectMediaError($operation,$reason,$label){try{$operation();check(false,$label);}catch(\RuntimeException $e){check($e->getMessage()===$reason,$label);}}
    function accepted(){return ['status'=>200,'body'=>'{"message_id":"sent-fixture"}'];}
    $fixture=__DIR__.'/meta-media-fixture-'.bin2hex(random_bytes(8));mkdir($fixture,0700);
    $media=new Media(null,$fixture.'/private');$c->instance(Media::class,$media);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ioAAAAASUVORK5CYII=');
    file_put_contents($fixture.'/image.png',$png);$file=new UploadedFile($fixture.'/image.png','picture.png','image/png',null,true);
    try {
        $t=thread();$calls=[];$request=input($t);
        $reply=new Replies(function($endpoint,$payload,$token)use(&$calls,$t){
            $calls[]=$endpoint;check(DB::connection()->transactionLevel()===0,'network outside DB transaction');
            check(strpos($endpoint,$token)===false&&$token===str_repeat('a',24),'credential only in transport token');
            $r=DB::table('meta_reply_requests')->where('conversation_id',$t)->orderByDesc('id')->first();
            if(str_ends_with($endpoint,'/message_attachments')){
                check($r->state==='CLAIMED'&&$r->send_started_at===null,'durable claim before attachment upload');
                check($endpoint==='https://graph.facebook.com/v25.0/101/message_attachments','Messenger native upload edge');
                check($payload['message']['attachment']['type']==='image'&&is_file($payload['filedata']['path'])&&$payload['filedata']['mime']==='image/png','validated native multipart metadata');
                return ['status'=>200,'body'=>'{"attachment_id":"700"}'];
            }
            check($r->state==='UNKNOWN'&&$r->send_started_at!==null,'durable uncertainty before native send');
            check($payload['message']['attachment']['payload']['attachment_id']==='700'&&$payload['recipient']['id']==='900','send native uploaded attachment id');
            return accepted();
        },$media);
        $firstReply=$reply->attachment($actor,$t,$request,$file);
        check($firstReply['state']==='ACCEPTED','image attachment accepted: '.json_encode($firstReply).' calls: '.json_encode($calls));
        check($reply->attachment($actor,$t,$request,$file)['replayed']&&count($calls)===2,'image UUID never repeats upload or send');
        $state=$reply->state($actor,$t,$request['client_request_id']);
        check($state['request_state']==='ACCEPTED'&&$state['recent_replies'][0]['kind']==='image'&&$state['recent_replies'][0]['media']['name']==='image.png','media state reconciliation');
        check(strpos(json_encode($state),'private')===false&&strpos(json_encode($state),'input_sha256')===false,'no paths or private fingerprints in public state');
        file_put_contents($fixture.'/other.txt','changed input');$other=new UploadedFile($fixture.'/other.txt','other.txt','text/plain',null,true);
        expectHttp(fn()=>$reply->attachment($actor,$t,$request,$other),409,'UUID payload conflict blocks reuse');
        check(glob($fixture.'/private/*')===[],'native upload preparation cleaned');

        $t=thread();$uploadCalls=0;$failed=new Replies(function($endpoint)use(&$uploadCalls){$uploadCalls++;check(str_ends_with($endpoint,'/message_attachments'),'failed upload cannot send');return ['status'=>0,'body'=>''];},$media);
        $r=$failed->attachment($actor,$t,input($t),$file);
        check($r['state']==='FAILED'&&$r['reason']==='MEDIA_UPLOAD_FAILED'&&$uploadCalls===1,'upload timeout is failed because no customer send');
        check($failed->state($actor,$t)['can_reply'],'upload failure leaves new reply possible');

        $t=thread();$uncertainCalls=0;$unknown=new Replies(function($endpoint)use(&$uncertainCalls){$uncertainCalls++;return str_ends_with($endpoint,'/message_attachments')?['status'=>200,'body'=>'{"attachment_id":"701"}']:['status'=>0,'body'=>''];},$media);
        $request=input($t);check($unknown->attachment($actor,$t,$request,$file)['state']==='UNKNOWN','attachment send timeout uncertain');
        check(!$unknown->state($actor,$t)['can_reply'],'uncertain attachment blocks later sends');
        check($unknown->attachment($actor,$t,$request,$file)['replayed']&&$uncertainCalls===2,'uncertain UUID remains resolve-only');
        check($unknown->attachment($actor,$t,input($t),$file)['reason']==='UNKNOWN_PENDING'&&$uncertainCalls===2,'different UUID cannot bypass uncertainty');

        $t=thread();$staleCalls=0;$stale=new Replies(function($endpoint)use(&$staleCalls,$t){$staleCalls++;message($t,'inbound','A newer customer message');return ['status'=>200,'body'=>'{"attachment_id":"702"}'];},$media);
        $r=$stale->attachment($actor,$t,input($t),$file);check($r['reason']==='STALE_INBOUND'&&$staleCalls===1,'fresh customer inbound revalidated after upload');
        check(DB::table('meta_reply_requests')->where('id',$r['reply_id'])->value('send_started_at')===null,'stale upload never entered customer send');
        $t=thread();$revokedCalls=0;$revoked=new Replies(function()use(&$revokedCalls,$actor){$revokedCalls++;$actor->active=false;return ['status'=>200,'body'=>'{"attachment_id":"703"}'];},$media);
        check($revoked->attachment($actor,$t,input($t),$file)['reason']==='ACCESS_REVOKED'&&$revokedCalls===1,'actor revocation during upload blocks send');$actor->active=true;
        $t=thread();$rotated=new Replies(function(){config()->set('meta_messaging.accounts.0.access_token',str_repeat('z',24));return ['status'=>200,'body'=>'{"attachment_id":"704"}'];},$media);
        check($rotated->attachment($actor,$t,input($t),$file)['reason']==='REPLIES_UNAVAILABLE','account token change after upload blocks send');config()->set('meta_messaging.accounts',$accounts);
        $t=thread();$version=new Replies(function(){config()->set('meta_messaging.api_version','v26.0');return ['status'=>200,'body'=>'{"attachment_id":"705"}'];},$media);
        check($version->attachment($actor,$t,input($t),$file)['reason']==='REPLIES_UNAVAILABLE','Graph version change after upload blocks send');config()->set('meta_messaging.api_version','v25.0');
        $t=thread();$window=new Replies(function(){\Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::now('UTC')->addDays(2));return ['status'=>200,'body'=>'{"attachment_id":"706"}'];},$media);
        check($window->attachment($actor,$t,input($t),$file)['reason']==='WINDOW_CLOSED','response window checked after upload');\Carbon\CarbonImmutable::setTestNow();

        $t=thread('instagram','303');$igCalls=0;$ig=new Replies(function($endpoint,$payload,$token)use(&$igCalls){$igCalls++;
            if(str_ends_with($endpoint,'/message_attachments')){check($endpoint==='https://graph.facebook.com/v25.0/me/message_attachments'&&$payload['platform']==='instagram','Page-mediated Instagram upload platform');return ['status'=>200,'body'=>'{"attachment_id":"707"}'];}
            check($endpoint==='https://graph.facebook.com/v25.0/me/messages'&&!isset($payload['messaging_type']),'Page-mediated Instagram send');return accepted();
        },$media);
        check($ig->attachment($actor,$t,input($t),$file)['state']==='ACCEPTED'&&$igCalls===2,'Facebook-login Instagram attachment');
        $t=thread('instagram','202');$igUrl=null;$igLogin=new Replies(function($endpoint,$payload)use(&$igUrl,$media){
            check($endpoint==='https://graph.instagram.com/v25.0/202/messages','Instagram-login supported messages edge');
            $igUrl=$payload['message']['attachment']['payload']['url'];$ticket=basename($igUrl);$f=$media->temporary($ticket);
            check(is_file($f['path'])&&$f['mime']==='image/png','Instagram fetch capability serves validated media');return accepted();
        },$media);
        $request=input($t);check($igLogin->attachment($actor,$t,$request,$file)['state']==='ACCEPTED','Instagram-login signed-media attachment');
        $ticket=basename($igUrl);$f=$media->temporary($ticket);check(is_file($f['path']),'accepted capability retained for delayed Meta fetch');
        check(strpos(json_encode($igLogin->state($actor,$t)),$ticket)===false,'capability never enters public reply state');
        expectMediaError(fn()=>$media->temporary(substr($ticket,0,-1).($ticket[-1]==='a'?'b':'a')),'MEDIA_EXPIRED','tampered capability rejected');
        $parts=explode('.',$ticket);$expired=$parts[0].'.'.(time()-1);$expired.='.'.hash_hmac('sha256','meta-media-capability-v1:'.$expired,Crypt::getKey());
        expectMediaError(fn()=>$media->temporary($expired),'MEDIA_EXPIRED','expired signed capability rejected');
        expectMediaError(fn()=>$media->temporary('../'.$ticket),'MEDIA_EXPIRED','capability cannot supply arbitrary path');
        $mfile=dirname($f['path']).'/ticket.json';$manifest=json_decode(file_get_contents($mfile),true);$manifest['sha256']=str_repeat('0',64);file_put_contents($mfile,json_encode($manifest));
        expectMediaError(fn()=>$media->temporary($ticket),'MEDIA_EXPIRED','changed retained bytes/manifest cannot be fetched');

        $t=thread('instagram','202');$unknownUrl=null;$unknownIg=new Replies(function($endpoint,$payload)use(&$unknownUrl){$unknownUrl=$payload['message']['attachment']['payload']['url'];throw new \RuntimeException('timeout after Meta accepted');},$media);
        check($unknownIg->attachment($actor,$t,input($t),$file)['state']==='UNKNOWN','Instagram-login exception stays uncertain');
        check(is_file($media->temporary(basename($unknownUrl))['path']),'uncertain public media survives destructor ownership release');

        $t=thread('instagram','202');$docRequest=input($t);$docNoSend=0;$docIg=new Replies(function()use(&$docNoSend){$docNoSend++;return accepted();},$media);
        check($docIg->attachment($actor,$t,$docRequest,$other)['reason']==='MEDIA_UNSUPPORTED'&&$docNoSend===0,'Instagram native file unsupported without send');
        file_put_contents($fixture.'/fake.png','plain text is not an image');$fake=new UploadedFile($fixture.'/fake.png','fake.png','image/png',null,true);
        check($reply->attachment($actor,thread(),input($t),$fake)['reason']==='INVALID_MEDIA','client MIME cannot spoof image bytes');
        file_put_contents($fixture.'/unsafe.svg','<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');$svg=new UploadedFile($fixture.'/unsafe.svg','unsafe.svg','image/svg+xml',null,true);
        expectMediaError(fn()=>$media->prepare($svg),'INVALID_MEDIA','SVG active media rejected');
        symlink($fixture.'/image.png',$fixture.'/linked.png');$linked=new UploadedFile($fixture.'/linked.png','image.png','image/png',null,true);
        expectMediaError(fn()=>$media->prepare($linked),'INVALID_MEDIA','symlink input rejected');
        $officeZip=base64_decode('UEsDBBQAAAAIACWiTV30/mHEIgAAACAAAAARAAAAd29yZC9kb2N1bWVudC54bWyzsa/IzVEoSy0qzszPs1Uy1DNQsrezSclPLs1NzSvRtwMAUEsDBBQAAAAIACWiTV3uR1hmHwAAAB0AAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbLOxr8jNUShLLSrOzM+zVTLUM1Cyt7MJqSxILda3AwBQSwECFAMUAAAACAAlok1d9P5hxCIAAAAgAAAAEQAAAAAAAAAAAAAAgAEAAAAAd29yZC9kb2N1bWVudC54bWxQSwECFAMUAAAACAAlok1d7kdYZh8AAAAdAAAAEwAAAAAAAAAAAAAAgAFRAAAAW0NvbnRlbnRfVHlwZXNdLnhtbFBLBQYAAAAAAgACAIAAAAChAAAAAAA=');
        file_put_contents($fixture.'/office.docx',$officeZip);$office=new UploadedFile($fixture.'/office.docx','office.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',null,true);
        $prepared=$media->prepare($office);check($prepared['type']==='file'&&$prepared['filename']==='attachment.docx','OOXML ZIP container preserves approved Office extension');$media->cleanup($prepared['path']);
        file_put_contents($fixture.'/too-large.png',$png.str_repeat('x',Media::MAX_IMAGE_BYTES));$large=new UploadedFile($fixture.'/too-large.png','picture.png','image/png',null,true);
        expectMediaError(fn()=>$media->prepare($large),'INVALID_MEDIA','image byte quota applies to detected actual media');

        $source=thread();$target=thread();$sourceMessage=message($source,'inbound','Forward text');$forwardCalls=0;
        $forward=new Replies(function($endpoint,$payload)use(&$forwardCalls){$forwardCalls++;check($payload['message']['text']==='Forward text','forward text grounded in stored DTO');return accepted();},$media);
        $request=input($target,['source_message_id'=>$sourceMessage]);
        check($forward->forward($actor,$target,$request)['state']==='ACCEPTED','forward same-account text');
        check($forward->forward($actor,$target,$request)['replayed']&&$forwardCalls===1,'forward UUID idempotent');
        $audit=json_decode(Crypt::decryptString(DB::table('meta_reply_requests')->where('conversation_id',$target)->value('audit')),true);
        check($audit['source_message_id']===$sourceMessage&&is_string($audit['source_evidence_hash'])&&$audit['source_conversation_id']===$source,'forward source evidence encrypted');
        $foreign=thread('messenger','102');expectHttp(fn()=>$forward->forward($actor,$foreign,input($foreign,['source_message_id'=>$sourceMessage])),422,'forward cannot cross native accounts');
        $foreign=thread('instagram','202');expectHttp(fn()=>$forward->forward($actor,$foreign,input($foreign,['source_message_id'=>$sourceMessage])),422,'forward cannot cross channels');
        DB::table('meta_message_hides')->insert(['actor_id'=>1,'conversation_id'=>$source,'message_id'=>$sourceMessage,
            'message_key'=>DB::table('meta_inbox_messages')->where('id',$sourceMessage)->value('message_key'),'hidden_at'=>now('UTC')]);
        expectHttp(fn()=>$forward->forward($actor,$target,input($target,['source_message_id'=>$sourceMessage])),422,'hidden source cannot be forwarded');

        foreach(['image','audio','video','file'] as $kind){
            $source=thread();$target=thread();$id=message($source,'inbound',null,[['type'=>$kind,'payload'=>['url'=>'https://scontent.example.fbcdn.net/fixture-'.$kind]]]);
            $fwd=new Replies(function($endpoint,$payload)use($kind){check($payload['message']['attachment']['type']===$kind&&$payload['message']['attachment']['payload']['url']==='https://scontent.example.fbcdn.net/fixture-'.$kind,'native '.$kind.' forwarded from allowlisted source');return accepted();},$media);
            check($fwd->forward($actor,$target,input($target,['source_message_id'=>$id]))['state']==='ACCEPTED','forward '.$kind.' accepted');
        }
        $source=thread();$target=thread();$combined=message($source,'inbound','Caption to choose',[['type'=>'image','payload'=>['url'=>'https://scontent.example.fbcdn.net/image-1']],['type'=>'image','payload'=>['url'=>'https://scontent.example.fbcdn.net/image-2']]]);
        $never=0;$selected=new Replies(function($endpoint,$payload)use(&$never){$never++;if(isset($payload['message']['text']))check($payload['message']['text']==='Caption to choose','explicit combined text selected');else check(str_ends_with($payload['message']['attachment']['payload']['url'],'image-2'),'explicit combined second attachment selected');return accepted();},$media);
        check($selected->forward($actor,$target,input($target,['source_message_id'=>$combined]))['reason']==='FORWARD_SELECTION_REQUIRED'&&$never===0,'combined message cannot silently drop parts');
        check($selected->forward($actor,$target,input($target,['source_message_id'=>$combined,'source_kind'=>'text']))['state']==='ACCEPTED','explicit text part forwarded');
        check($selected->forward($actor,$target,input($target,['source_message_id'=>$combined,'source_kind'=>'media','source_media_index'=>1]))['state']==='ACCEPTED','explicit selected media forwarded');
        $badUrl=message($source,'inbound',null,[['type'=>'image','payload'=>['url'=>'http://127.0.0.1/internal']]]);
        expectHttp(fn()=>$selected->forward($actor,$target,input($target,['source_message_id'=>$badUrl])),404,'forward rejects untrusted arbitrary URLs');
        $badDto=message($source,'inbound','Bad source');DB::table('meta_inbox_messages')->where('id',$badDto)->update(['message_key'=>str_repeat('0',64)]);
        expectHttp(fn()=>$selected->forward($actor,$target,input($target,['source_message_id'=>$badDto])),404,'forward rejects corrupt source identity');

        if($media->audioAvailable()){
            $args=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error','-f','lavfi','-i','sine=frequency=440:duration=1','-c:a','libopus','-f','webm',$fixture.'/recording.webm'];
            $proc=proc_open($args,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);foreach($pipes as $p)fclose($p);check(proc_close($proc)===0,'real browser-codec fixture');
            $recording=new UploadedFile($fixture.'/recording.webm','voice.webm','audio/webm',null,true);$t=thread();$voiceCalls=0;
            $voice=new Replies(function($endpoint,$payload)use(&$voiceCalls){$voiceCalls++;
                if(str_ends_with($endpoint,'/message_attachments')){check($payload['message']['attachment']['type']==='audio'&&$payload['filedata']['mime']==='audio/mp4'&&$payload['filedata']['filename']==='voice.m4a','real recording converted to channel-compatible AAC');return ['status'=>200,'body'=>'{"attachment_id":"800"}'];}
                check($payload['message']['attachment']['type']==='audio','voice native audio send');return accepted();
            },$media);
            check($voice->voice($actor,$t,input($t),$recording)['state']==='ACCEPTED'&&$voiceCalls===2,'real voice recording accepted');
            $prepared=$media->prepare($recording);copy($prepared['path'],$fixture.'/audio.m4a');$media->cleanup($prepared['path']);
            $m4a=new UploadedFile($fixture.'/audio.m4a','audio.m4a','audio/mp4',null,true);$prepared=$media->prepare($m4a);
            check($prepared['type']==='audio'&&$prepared['mime']==='audio/mp4','generic M4A attachment classified from actual streams');$media->cleanup($prepared['path']);
            $longRunner=function($arguments){return ['code'=>0,'output'=>json_encode(['format'=>['format_name'=>'matroska,webm','duration'=>'61.0'],'streams'=>[['codec_type'=>'audio','codec_name'=>'opus']]])];};
            $longMedia=new Media($longRunner,$fixture.'/private');expectMediaError(fn()=>$longMedia->prepare($recording,true),'INVALID_MEDIA','overlong voice rejected before native upload');
            $noDurationRunner=function($arguments){if(str_ends_with($arguments[0],'ffprobe')){
                $output=str_ends_with(end($arguments),'/payload');return ['code'=>0,'output'=>json_encode(['format'=>['format_name'=>$output?'mov,mp4,m4a,3gp,3g2,mj2':'matroska,webm']+($output?['duration'=>'61.0']:[]),
                    'streams'=>[['codec_type'=>'audio','codec_name'=>$output?'aac':'opus','channels'=>1]]])];}
                file_put_contents(end($arguments),'encoded-fixture');return ['code'=>0,'output'=>''];};
            $bounded=new Media($noDurationRunner,$fixture.'/private');expectMediaError(fn()=>$bounded->prepare($recording,true),'INVALID_MEDIA','missing input duration still bounded by output duration');
        }
        echo "meta-reply-media: $checks checks passed\n";
    } finally {
        $remove=function($path)use(&$remove){if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path))return;foreach(scandir($path) as $name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);};
        $remove($fixture);\Carbon\CarbonImmutable::setTestNow();
    }
}
