<?php
namespace App\Http\Controllers { class Controller {} }
namespace {
    function auth($guard=null) { return new class { public function user() { return $GLOBALS['actionActor']; } }; }
    function response($body=null,$status=200) {
        if(func_num_args())return new \Illuminate\Http\Response($body,$status);
        return new class { public function json($value,$status=200) { return new \Illuminate\Http\JsonResponse($value,$status); } };
    }
    // Reuse the real native capture, marker, encrypted ledger and Graph transport harness.
    require __DIR__.'/meta_order_reply_service_test.php';

    use App\Services\Dashboard\MetaMediaService;
    use App\Services\Dashboard\MetaReplyService;
    use App\Http\Controllers\Dashboard\MetaMessageActionsController;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    require_once __DIR__.'/../database/migrations/2026_10_13_160000_create_meta_message_hides.php';
    (new \CreateMetaMessageHides())->up();
    $actionChecks=0;
    function actionExpect($ok,string $label):void { $GLOBALS['actionChecks']++;if(!$ok)throw new \RuntimeException($label); }
    function actionDenied(callable $callback,int $status,string $label):void {
        try{$callback();actionExpect(false,$label);}catch(HttpException$error){actionExpect($error->getStatusCode()===$status,$label);}
    }
    function actionUuid(int $id,int $operation):string {return sprintf('a0000000-0000-4000-8000-%012d',$id*100+$operation);}
    $actor->active=true;$other->active=true;$GLOBALS['actionActor']=$actor;
    $media=new MetaMediaService(static function(){throw new \RuntimeException('RECEIPT_TEXT_MUST_NOT_FETCH_MEDIA');});
    app()->instance(MetaMediaService::class,$media);$actions=new MetaMessageActionsController();

    foreach([['messenger','101'],['instagram','202']]as[$channel,$account]){
        [$source,$peer,$inbound]=thread($channel,$account);$graph=0;$text='تحب تختار أي صنف من المينيو؟';
        $sender=new MetaReplyService(function($url,$payload,$token)use(&$graph,$text,$source){
            $graph++;actionExpect(($payload['message']['text']??null)===$text,'Graph receives authenticated capsule text only');
            actionExpect(!isset($payload['message']['schema'],$payload['message']['meta']),'forward payload contains no receipt or forged native envelope');
            return accepted('action-owned-'.$source->id.'-'.$graph);
        });
        app()->instance(MetaReplyService::class,$sender);
        $input=followupInput($source,$inbound,fn()=>$text);
        actionExpect($sender->sendOrderFollowup($actor,(int)$source->id,$input,fn()=>true)['state']==='ACCEPTED','actual '.$channel.' automatic reply accepted');
        $row=projected($source->id);$original=$row->content;$receipt=receipt($source->id);
        $snapshot=$media->message((int)$source->id,(int)$row->id,$actor);
        actionExpect($snapshot['dto']['text']===$text&&$snapshot['dto']['schema']==='meta-accepted-reply-evidence-v1','message exposes authenticated receipt capsule text');
        actionExpect(!isset($snapshot['dto']['content']['message']['meta'],$snapshot['dto']['channel']),'receipt provenance remains explicit instead of native fabrication');
        actionExpect($media->descriptors($source,$row,$snapshot['dto'])===[],'accepted text has no media descriptors');
        actionDenied(fn()=>$media->source((int)$source->id,(int)$row->id,0,$actor),404,'accepted text cannot provide a media source');
        $forged=$snapshot['dto'];$forged['content']['message']['meta']['attachments']=[['type'=>'image','payload'=>['url'=>'https://scontent.fbcdn.net/fake']]];
        actionExpect($media->descriptors($source,$row,$forged)===[],'caller cannot graft native attachments into receipt descriptor');

        [$target,$targetPeer,$targetInbound]=thread($channel,$account);
        $forward=['client_request_id'=>actionUuid((int)$source->id,1),'expected_inbound_id'=>$targetInbound,
            'source_message_id'=>(int)$row->id,'source_kind'=>'text'];
        actionExpect($sender->forward($actor,(int)$target->id,$forward)['state']==='ACCEPTED'&&$graph===2,'actual projected '.$channel.' reply forwards through existing text send');
        actionExpect($sender->forward($actor,(int)$target->id,$forward)['replayed']&&$graph===2,'forward replay sends no second Graph message');
        $audit=json_decode(Crypt::decryptString(receipt($target->id)->audit),true,32,JSON_THROW_ON_ERROR);
        actionExpect($audit['source_message_id']===(int)$row->id&&$audit['source_conversation_id']===(int)$source->id
            &&$audit['text']===$text&&is_string($audit['source_evidence_hash']),'forward retains encrypted receipt source proof');
        actionDenied(fn()=>$media->message((int)$target->id,(int)$row->id,$actor),404,'receipt message cannot cross conversation');

        $result=$actions->hide((string)$source->id,(string)$row->id,$media);
        actionExpect($result->getStatusCode()===200&&$result->getData(true)['hidden'],'existing hide controller accepts authenticated receipt text');
        $hide=DB::table('meta_message_hides')->where('message_id',$row->id)->first();
        actionExpect($actions->hide((string)$source->id,(string)$row->id,$media)->getStatusCode()===200
            &&DB::table('meta_message_hides')->where('message_id',$row->id)->first()==$hide,'receipt hide is immutable and idempotent');
        actionExpect($media->message((int)$source->id,(int)$row->id,$actor)['hidden']
            &&!$media->message((int)$source->id,(int)$row->id,$other)['hidden'],'receipt hide remains per actor');
        $forward['client_request_id']=actionUuid((int)$source->id,2);
        actionDenied(fn()=>$sender->forward($actor,(int)$target->id,$forward),422,'hidden receipt cannot be forwarded by hiding actor');
        actionExpect($graph===2&&DB::table('meta_inbox_messages')->where('id',$row->id)->value('content')===$original
            &&receipt($source->id)==$receipt,'hide preserves native evidence and accepted ledger bytes');

        $damaged=$snapshot['dto'];$damaged['text']='نص مختلف';
        DB::table('meta_inbox_messages')->where('id',$row->id)->update(['content'=>Crypt::encryptString(json_encode($damaged,JSON_THROW_ON_ERROR))]);
        actionDenied(fn()=>$media->message((int)$source->id,(int)$row->id,$other),404,'modified receipt capsule cannot be read as an action source');
        $GLOBALS['actionActor']=$other;
        actionExpect($actions->hide((string)$source->id,(string)$row->id,$media)->getStatusCode()===404,'modified receipt cannot be hidden as authenticated evidence');
        $forward['client_request_id']=actionUuid((int)$source->id,3);
        actionDenied(fn()=>$sender->forward($other,(int)$target->id,$forward),404,'modified receipt cannot be forwarded');
        actionExpect($graph===2,'invalid capsule never reaches Graph');
        DB::table('meta_inbox_messages')->where('id',$row->id)->update(['content'=>$original]);$GLOBALS['actionActor']=$actor;
        actionExpect($media->message((int)$source->id,(int)$row->id,$other)['dto']===$snapshot['dto'],'restored exact receipt authenticates with original provenance');
    }
    echo 'META_ORDER_PROJECTED_MESSAGE_ACTIONS_TESTS='.$actionChecks." PASS\n";
}
